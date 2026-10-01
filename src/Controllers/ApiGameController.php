<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiAuth;
use BNT\Core\ApiMiddleware;
use BNT\Core\ApiResponse;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Combat;
use BNT\Models\ShipType;
use BNT\Services\AlignmentService;
use BNT\Services\MovementService;
use BNT\Services\TradeService;

class ApiGameController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Planet $planetModel,
        private Combat $combatModel,
        private ApiAuth $apiAuth,
        private ApiMiddleware $middleware,
        private array $config,
        private ?MovementService $movement = null,
        private ?AlignmentService $alignment = null,
        private ?TradeService $trade = null
    ) {}

    /** Other ships as other players may see them: tier not number, Wanted badge, NPC faction. */
    private function publicShips(array $ships): array
    {
        return $this->alignment ? array_map(fn($s) => $this->alignment->publicView($s), $ships) : $ships;
    }

    private function cleanShip(array $ship): array
    {
        unset($ship['password_hash'], $ship['trade_credit_accum']);
        return $ship;
    }
    
    private function requireAuth(): array
    {
        $ship = $this->middleware->requireAuth();
        if (!$ship) {
            exit; // Response already sent
        }
        return $ship;
    }
    
    /**
     * GET /api/v1/game/main
     * Get main game screen data
     */
    public function main(): void
    {
        $ship = $this->requireAuth();
        
        // If on planet, leave it automatically
        if ($ship['on_planet']) {
            $this->shipModel->getDb()->execute(
                'UPDATE ships SET on_planet = FALSE, planet_id = 0 WHERE ship_id = :id',
                ['id' => (int)$ship['ship_id']]
            );
            $ship = $this->shipModel->find((int)$ship['ship_id']);
        }
        
        $sector = $this->universeModel->getSector((int)$ship['sector']);
        $links = $this->universeModel->getLinkedSectors((int)$ship['sector']);
        $planets = $this->planetModel->getPlanetsInSector((int)$ship['sector']);
        $shipsInSector = $this->shipModel->getShipsInSector((int)$ship['sector'], (int)$ship['ship_id']);
        $this->trade?->recordKnownPort((int)$ship['ship_id'], (int)$ship['sector']);
        
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];
        
        $isStarbaseSector = $this->universeModel->isStarbase((int)$ship['sector']);
        
        ApiResponse::success([
            'ship' => $this->cleanShip($ship),
            'sector' => $sector,
            'links' => $links,
            'planets' => $planets,
            'ships_in_sector' => $this->publicShips($shipsInSector),
            'holds' => [
                'max' => $maxHolds,
                'used' => $usedHolds,
                'available' => $maxHolds - $usedHolds
            ],
            'is_starbase_sector' => $isStarbaseSector
        ]);
    }
    
    /**
     * POST /api/v1/game/move/:sector
     * Move to a new sector
     */
    public function move(int $destinationSector): void
    {
        $ship = $this->requireAuth();

        $result = $this->movement->move($ship, $destinationSector);
        if (!$result['success']) {
            ApiResponse::error($result['error'], $result['code'], 400);
        }
        if ($result['destroyed']) {
            $msg = $result['destroyed_by'] === 'mines' ? 'Your ship was destroyed by mines!' : 'Your ship was destroyed by sector fighters!';
            ApiResponse::error($msg, 'SHIP_DESTROYED', 400, ['sector' => $destinationSector]);
        }

        ApiResponse::success([
            'ship' => $this->cleanShip($result['ship']),
            'movement' => [
                'sector' => $destinationSector,
                'turns_used' => $result['turns_used'],
                'mine_result' => $result['mine'],
                'fighter_result' => $result['fighter'],
                'contraband' => $result['contraband'] ?? null,
            ],
        ]);
    }

    /**
     * GET /api/v1/game/scan
     * Get detailed sector scan
     */
    public function scan(): void
    {
        $ship = $this->requireAuth();
        
        $sector = $this->universeModel->getSector((int)$ship['sector']);
        $links = $this->universeModel->getLinkedSectors((int)$ship['sector']);
        $planets = $this->planetModel->getPlanetsInSector((int)$ship['sector']);
        $shipsInSector = $this->shipModel->getShipsInSector((int)$ship['sector'], (int)$ship['ship_id']);
        
        $this->trade?->recordKnownPort((int)$ship['ship_id'], (int)$ship['sector']);
        foreach ($links as $link) {
            if (($link['port_type'] ?? 'none') !== 'none') {
                $this->trade?->recordKnownPort((int)$ship['ship_id'], (int)$link['sector_id'], $link['port_type']);
            }
        }
        $this->alignment?->noteSightings($shipsInSector, (int)$ship['sector']);

        $sql = "SELECT sd.*, s.character_name
                FROM sector_defence sd
                JOIN ships s ON sd.ship_id = s.ship_id
                WHERE sd.sector_id = :sector_id";
        
        $defenses = $this->shipModel->getDb()->fetchAll($sql, ['sector_id' => $ship['sector']]);
        
        ApiResponse::success([
            'ship' => $this->cleanShip($ship),
            'sector' => $sector,
            'links' => $links,
            'planets' => $planets,
            'ships_in_sector' => $this->publicShips($shipsInSector),
            'defenses' => $defenses
        ]);
    }
    
    /**
     * GET /api/v1/game/status
     * Get ship status
     */
    public function status(): void
    {
        $ship = $this->requireAuth();
        
        $score = $this->shipModel->calculateScore((int)$ship['ship_id']);
        $ship = $this->shipModel->find((int)$ship['ship_id']);
        $planets = $this->planetModel->getPlayerPlanets((int)$ship['ship_id']);
        
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
        $maxEnergy = $this->calculateEnergy($ship['power']);
        $maxFighters = $this->calculateFighters($ship['computer']);
        $maxTorps = $this->calculateTorps($ship['torp_launchers']);
        
        ApiResponse::success([
            'ship' => $this->cleanShip($ship),
            'planets' => $planets,
            'capacities' => [
                'holds' => $maxHolds,
                'energy' => $maxEnergy,
                'fighters' => $maxFighters,
                'torps' => $maxTorps
            ],
            'score' => $score
        ]);
    }
    
    /**
     * GET /api/v1/game/planet/:id
     * Get planet information
     */
    public function planet(int $planetId): void
    {
        $ship = $this->requireAuth();
        
        $planet = $this->planetModel->find($planetId);
        
        if (!$planet) {
            ApiResponse::notFound('Planet not found');
        }
        
        if ($planet['sector_id'] != $ship['sector']) {
            ApiResponse::error('You must be in the same sector as the planet', 'WRONG_SECTOR', 400);
        }
        
        $ownerName = null;
        if ($planet['owner'] > 0) {
            $owner = $this->shipModel->find($planet['owner']);
            $ownerName = $owner ? $owner['character_name'] : 'Unknown';
        }
        
        $isOwner = $planet['owner'] == $ship['ship_id'];
        $isOnPlanet = $ship['on_planet'] && $ship['planet_id'] == $planetId;
        
        ApiResponse::success([
            'planet' => $planet,
            'owner_name' => $ownerName,
            'is_owner' => $isOwner,
            'is_on_planet' => $isOnPlanet
        ]);
    }
    
    /**
     * POST /api/v1/game/land/:id
     * Land on a planet
     */
    public function landOnPlanet(int $planetId): void
    {
        $ship = $this->requireAuth();
        
        $planet = $this->planetModel->find($planetId);
        
        if (!$planet || $planet['sector_id'] != $ship['sector']) {
            ApiResponse::error('Cannot land on this planet', 'INVALID_PLANET', 400);
        }
        
        if ($planet['owner'] != 0 && $planet['owner'] != $ship['ship_id']) {
            ApiResponse::error('This planet is owned by another player', 'PLANET_OWNED', 403);
        }
        
        $this->shipModel->getDb()->execute(
            'UPDATE ships SET on_planet = TRUE, planet_id = :planet_id WHERE ship_id = :id',
            ['planet_id' => $planetId, 'id' => (int)$ship['ship_id']]
        );
        
        $ship = $this->shipModel->find((int)$ship['ship_id']);
        
        ApiResponse::success([
            'ship' => $this->cleanShip($ship),
            'planet' => $planet
        ], 'Landed on planet successfully');
    }
    
    /**
     * POST /api/v1/game/leave
     * Leave current planet
     */
    public function leavePlanet(): void
    {
        $ship = $this->requireAuth();
        
        if (!$ship['on_planet']) {
            ApiResponse::error('You are not on a planet', 'NOT_ON_PLANET', 400);
        }
        
        $this->shipModel->getDb()->execute(
            'UPDATE ships SET on_planet = FALSE, planet_id = 0 WHERE ship_id = :id',
            ['id' => (int)$ship['ship_id']]
        );
        
        $ship = $this->shipModel->find((int)$ship['ship_id']);
        
        ApiResponse::success([
            'ship' => $this->cleanShip($ship)
        ], 'Left planet successfully');
    }
    
    // Helper methods
    private function calculateHolds(int $level, string $shipType): int
    {
        $baseCapacity = (int)round(pow(1.5, $level) * 100);
        return ShipType::getCargoCapacity($shipType, $baseCapacity);
    }
    
    private function calculateEnergy(int $level): int
    {
        return (int)round(pow(1.5, $level) * 500);
    }
    
    private function calculateFighters(int $level): int
    {
        return (int)round(pow(1.5, $level) * 100);
    }
    
    private function calculateTorps(int $level): int
    {
        return (int)round(pow(1.5, $level) * 100);
    }
}
