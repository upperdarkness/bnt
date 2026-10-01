<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Combat;
use BNT\Models\ShipType;
use BNT\Services\AlignmentService;
use BNT\Services\MovementService;
use BNT\Services\TradeService;

class GameController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Planet $planetModel,
        private Combat $combatModel,
        private Session $session,
        private array $config,
        private ?MovementService $movement = null,
        private ?AlignmentService $alignment = null,
        private ?TradeService $trade = null
    ) {}

    private function requireAuth(): ?array
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $shipId = $this->session->getUserId();
        $ship = $this->shipModel->find($shipId);

        if (!$ship) {
            $this->session->logout();
            header('Location: /');
            exit;
        }

        return $ship;
    }

    public function main(): void
    {
        $ship = $this->requireAuth();

        // If on planet, leave it automatically when accessing main
        if ($ship['on_planet']) {
            // Use raw SQL with boolean literal to avoid PDO binding issues
            $this->shipModel->getDb()->execute(
                'UPDATE ships SET on_planet = FALSE, planet_id = 0 WHERE ship_id = :id',
                ['id' => (int)$ship['ship_id']]
            );
            // Reload ship data after update
            $ship = $this->shipModel->find((int)$ship['ship_id']);
        }

        // Get sector information
        $sector = $this->universeModel->getSector((int)$ship['sector']);
        $links = $this->universeModel->getLinkedSectors((int)$ship['sector']);
        $planets = $this->planetModel->getPlanetsInSector((int)$ship['sector']);
        $shipsInSector = $this->shipModel->getShipsInSector((int)$ship['sector'], (int)$ship['ship_id']);

        // Calculate ship capacity
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];

        // Check if in starbase sector
        $isStarbaseSector = $this->universeModel->isStarbase((int)$ship['sector']);
        $this->trade?->recordKnownPort((int)$ship['ship_id'], (int)$ship['sector']);
        $alignmentService = $this->alignment;
        
        $session = $this->session;
        $title = 'Main - BlackNova Traders';
        $showHeader = true;
        
        // Extract variables to make them available to the view
        extract(compact('ship', 'sector', 'links', 'planets', 'shipsInSector', 'maxHolds', 'usedHolds', 'isStarbaseSector', 'alignmentService', 'session', 'title', 'showHeader'));
        
        ob_start();
        include __DIR__ . '/../Views/main.php';
        echo ob_get_clean();
    }

    public function move(int $destinationSector): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF token
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /main');
            exit;
        }

        $result = $this->movement->move($ship, $destinationSector);
        if (!$result['success']) {
            $this->session->set('error', $result['error']);
            header('Location: /main');
            exit;
        }

        if ($result['destroyed']) {
            $this->session->set('error', $result['destroyed_by'] === 'mines'
                ? 'Your ship was destroyed by mines!'
                : 'Your ship was destroyed by sector fighters!');
            header('Location: /');
            exit;
        }

        $messages = array_filter([$result['mine']['message'] ?? null, $result['fighter']['message'] ?? null, $result['contraband']['message'] ?? null]);
        if ($messages) {
            $hit = !empty($result['mine']['hit']) || !empty($result['fighter']['attacked']) || !empty($result['contraband']);
            $this->session->set($hit ? 'error' : 'message', implode(' | ', $messages));
        }

        header('Location: /main');
        exit;
    }

    public function galaxy(): void
    {
        $ship = $this->requireAuth();
        $galaxy = $this->universeModel->getGalaxyMap();
        $currentSector = (int)$ship['sector'];
        $selectedId = max(1, (int)($_GET['sector'] ?? $currentSector));
        $sectorsById = array_column($galaxy['sectors'], null, 'id');
        $selected = $sectorsById[$selectedId] ?? $sectorsById[$currentSector] ?? null;
        $links = $this->universeModel->getLinkedSectors($currentSector);
        $linkedIds = array_map(static fn(array $link): int => (int)$link['sector_id'], $links);
        $session = $this->session;
        include __DIR__ . '/../Views/galaxy.php';
    }

    public function scan(): void
    {
        $ship = $this->requireAuth();

        $sector = $this->universeModel->getSector((int)$ship['sector']);
        $links = $this->universeModel->getLinkedSectors((int)$ship['sector']);
        $planets = $this->planetModel->getPlanetsInSector((int)$ship['sector']);
        $shipsInSector = $this->shipModel->getShipsInSector((int)$ship['sector'], (int)$ship['ship_id']);

        // Get detailed sector defense info
        $sql = "SELECT sd.*, s.character_name
                FROM sector_defence sd
                JOIN ships s ON sd.ship_id = s.ship_id
                WHERE sd.sector_id = :sector_id";

        $defenses = $this->shipModel->getDb()->fetchAll($sql, ['sector_id' => $ship['sector']]);

        $this->trade?->recordKnownPort((int)$ship['ship_id'], (int)$ship['sector']);
        foreach ($links as $link) {
            if (($link['port_type'] ?? 'none') !== 'none') {
                $this->trade?->recordKnownPort((int)$ship['ship_id'], (int)$link['sector_id'], $link['port_type']);
            }
        }
        $this->alignment?->noteSightings($shipsInSector, (int)$ship['sector']);
        $alignmentService = $this->alignment;

        $session = $this->session;
        $title = 'Scan - BlackNova Traders';
        $showHeader = true;
        
        // Extract variables to make them available to the view
        extract(compact('ship', 'sector', 'links', 'planets', 'shipsInSector', 'defenses', 'alignmentService', 'session', 'title', 'showHeader'));

        ob_start();
        include __DIR__ . '/../Views/scan.php';
        echo ob_get_clean();
    }

    public function planet(int $planetId): void
    {
        $ship = $this->requireAuth();

        $planet = $this->planetModel->find($planetId);

        if (!$planet) {
            $this->session->set('error', 'Planet not found');
            header('Location: /main');
            exit;
        }

        // Check if player is in the same sector
        if ($planet['sector_id'] != $ship['sector']) {
            $this->session->set('error', 'You must be in the same sector as the planet');
            header('Location: /main');
            exit;
        }

        // Get owner name if owned
        $ownerName = null;
        if ($planet['owner'] > 0) {
            $owner = $this->shipModel->find($planet['owner']);
            $ownerName = $owner ? $owner['character_name'] : 'Unknown';
        }

        $isOwner = $planet['owner'] == $ship['ship_id'];
        $isOnPlanet = $ship['on_planet'] && $ship['planet_id'] == $planetId;

        $session = $this->session;
        $title = 'Planet - BlackNova Traders';
        $showHeader = true;
        
        // Extract variables to make them available to the view
        extract(compact('ship', 'planet', 'ownerName', 'isOwner', 'isOnPlanet', 'session', 'title', 'showHeader'));

        ob_start();
        include __DIR__ . '/../Views/planet.php';
        echo ob_get_clean();
    }

    public function landOnPlanet(int $planetId): void
    {
        $ship = $this->requireAuth();

        $planet = $this->planetModel->find($planetId);

        if (!$planet || $planet['sector_id'] != $ship['sector']) {
            $this->session->set('error', 'Cannot land on this planet');
            header('Location: /main');
            exit;
        }

        // Check if player owns the planet or it's unowned
        if ($planet['owner'] != 0 && $planet['owner'] != $ship['ship_id']) {
            $this->session->set('error', 'This planet is owned by another player');
            header('Location: /main');
            exit;
        }

        // Land on planet - use raw SQL with boolean literal to avoid PDO binding issues
        $this->shipModel->getDb()->execute(
            'UPDATE ships SET on_planet = TRUE, planet_id = :planet_id WHERE ship_id = :id',
            ['planet_id' => $planetId, 'id' => (int)$ship['ship_id']]
        );

        header('Location: /planet/' . $planetId);
        exit;
    }

    public function leavePlanet(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF token
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /main');
            exit;
        }

        // Check if player is actually on a planet
        if (!$ship['on_planet']) {
            $this->session->set('error', 'You are not on a planet');
            header('Location: /main');
            exit;
        }

        // Leave planet - use raw SQL with boolean literal to avoid PDO binding issues
        $this->shipModel->getDb()->execute(
            'UPDATE ships SET on_planet = FALSE, planet_id = 0 WHERE ship_id = :id',
            ['id' => (int)$ship['ship_id']]
        );

        $this->session->set('message', 'You have left the planet');
        header('Location: /main');
        exit;
    }

    public function status(): void
    {
        $ship = $this->requireAuth();

        // Recalculate score
        $score = $this->shipModel->calculateScore((int)$ship['ship_id']);

        // Get updated ship data
        $ship = $this->shipModel->find((int)$ship['ship_id']);

        // Get planets
        $planets = $this->planetModel->getPlayerPlanets((int)$ship['ship_id']);

        // Calculate capacities
        $maxHolds = $this->calculateHolds($ship['hull'], $ship['ship_type']);
        $maxEnergy = $this->calculateEnergy($ship['power']);
        $maxFighters = $this->calculateFighters($ship['computer']);
        $maxTorps = $this->calculateTorps($ship['torp_launchers']);

        $data = compact('ship', 'planets', 'maxHolds', 'maxEnergy', 'maxFighters', 'maxTorps', 'score');

        ob_start();
        include __DIR__ . '/../Views/status.php';
        echo ob_get_clean();
    }

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
