<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;

class GameController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Planet $planetModel,
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
        $maxHolds = $this->calculateHolds($ship['hull']);
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

        // Check if player has turns
        if ($ship['turns'] < 1) {
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

        // Use a turn and move
        $this->shipModel->useTurns((int)$ship['ship_id'], 1);
        $this->shipModel->update((int)$ship['ship_id'], ['sector' => $destinationSector]);

        // Log movement
        $this->logMovement((int)$ship['ship_id'], $destinationSector);

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
        $maxHolds = $this->calculateHolds($ship['hull']);
        $maxEnergy = $this->calculateEnergy($ship['power']);
        $maxFighters = $this->calculateFighters($ship['computer']);
        $maxTorps = $this->calculateTorps($ship['torp_launchers']);

        $data = compact('ship', 'planets', 'maxHolds', 'maxEnergy', 'maxFighters', 'maxTorps', 'score');

        ob_start();
        include __DIR__ . '/../Views/status.php';
        echo ob_get_clean();
    }

    private function calculateHolds(int $level): int
    {
        return (int)round(pow(1.5, $level) * 100);
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
}
