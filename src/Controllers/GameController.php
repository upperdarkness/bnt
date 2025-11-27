<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Combat;
use BNT\Models\ShipType;

class GameController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Planet $planetModel,
        private Combat $combatModel,
        private Session $session,
        private array $config
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

        // Check if on planet
        if ($ship['on_planet']) {
            header('Location: /planet/' . $ship['planet_id']);
            exit;
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

        $data = compact('ship', 'sector', 'links', 'planets', 'shipsInSector', 'maxHolds', 'usedHolds');

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

        // Calculate turn cost based on ship type
        $turnCost = ShipType::getTurnCost($ship['ship_type']);

        // Check if player has turns
        if ($ship['turns'] < $turnCost) {
            $this->session->set('error', 'Not enough turns');
            header('Location: /main');
            exit;
        }

        // Check if sectors are linked
        if (!$this->universeModel->isLinked((int)$ship['sector'], $destinationSector)) {
            $this->session->set('error', 'Sectors are not linked');
            header('Location: /main');
            exit;
        }

        // Use turns and move
        $this->shipModel->useTurns((int)$ship['ship_id'], $turnCost);
        $this->shipModel->update((int)$ship['ship_id'], ['sector' => $destinationSector]);

        // Log movement
        $this->logMovement((int)$ship['ship_id'], $destinationSector);

        // Check for mines in destination sector
        $mineResult = $this->combatModel->checkMines((int)$ship['ship_id'], $destinationSector, (int)$ship['hull']);
        if ($mineResult['hit']) {
            $this->session->set('error', $mineResult['message']);

            // Apply mine damage
            $this->combatModel->applyDamageToShip((int)$ship['ship_id'], $mineResult['damage']);

            // Remove destroyed mines
            if ($mineResult['mines_destroyed'] > 0) {
                $this->removeMines($destinationSector, $mineResult['mines_destroyed']);
            }

            // Check if ship was destroyed
            if ($mineResult['ship_destroyed']) {
                $this->session->set('error', 'Your ship was destroyed by mines!');
                header('Location: /');
                exit;
            }
        }

        // Check for sector fighter attacks
        $ship = $this->shipModel->find((int)$ship['ship_id']); // Reload ship data
        $fighterResult = $this->combatModel->checkSectorFighters($ship, $destinationSector);
        if ($fighterResult['attacked']) {
            $existingError = $this->session->get('error');
            $message = $existingError ? $existingError . ' | ' . $fighterResult['message'] : $fighterResult['message'];
            $this->session->set('error', $message);

            // Apply fighter damage
            if ($fighterResult['damage'] > 0) {
                $this->combatModel->applyDamageToShip((int)$ship['ship_id'], $fighterResult['damage']);
            }

            // Check if ship was destroyed
            if ($fighterResult['ship_destroyed']) {
                $this->session->set('error', 'Your ship was destroyed by sector fighters!');
                header('Location: /');
                exit;
            }
        }

        header('Location: /main');
        exit;
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

        $defenses = $this->shipModel->db->fetchAll($sql, ['sector_id' => $ship['sector']]);

        $data = compact('ship', 'sector', 'links', 'planets', 'shipsInSector', 'defenses');

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

        $data = compact('ship', 'planet');

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

        // Land on planet
        $this->shipModel->update((int)$ship['ship_id'], [
            'on_planet' => true,
            'planet_id' => $planetId
        ]);

        header('Location: /planet/' . $planetId);
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

    private function logMovement(int $shipId, int $sectorId): void
    {
        $sql = "INSERT INTO movement_log (ship_id, sector_id, time) VALUES (:ship_id, :sector_id, NOW())";
        $this->shipModel->db->execute($sql, ['ship_id' => $shipId, 'sector_id' => $sectorId]);
    }

    private function removeMines(int $sectorId, int $count): void
    {
        $sql = "SELECT * FROM sector_defence
                WHERE sector_id = :sector AND defence_type = 'M'
                ORDER BY quantity ASC";
        $mines = $this->shipModel->db->fetchAll($sql, ['sector' => $sectorId]);

        $remaining = $count;
        foreach ($mines as $mine) {
            if ($remaining <= 0) break;

            if ($mine['quantity'] <= $remaining) {
                $this->shipModel->db->execute(
                    'DELETE FROM sector_defence WHERE defence_id = :id',
                    ['id' => $mine['defence_id']]
                );
                $remaining -= $mine['quantity'];
            } else {
                $this->shipModel->db->execute(
                    'UPDATE sector_defence SET quantity = quantity - :count WHERE defence_id = :id',
                    ['count' => $remaining, 'id' => $mine['defence_id']]
                );
                $remaining = 0;
            }
        }
    }
}
