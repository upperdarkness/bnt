<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;

class PlanetController
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

    /**
     * View planet details
     */
    public function view(int $planetId): void
    {
        $ship = $this->requireAuth();

        $planet = $this->planetModel->find($planetId);

        if (!$planet) {
            $this->session->set('error', 'Planet not found');
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

        $data = compact('ship', 'planet', 'ownerName', 'isOwner', 'isOnPlanet');

        ob_start();
        include __DIR__ . '/../Views/planet.php';
        echo ob_get_clean();
    }

    /**
     * Colonize an unowned planet
     */
    public function colonize(int $planetId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /planet/' . $planetId);
            exit;
        }

        $planet = $this->planetModel->find($planetId);

        if (!$planet) {
            $this->session->set('error', 'Planet not found');
            header('Location: /main');
            exit;
        }

        // Check if planet is unowned
        if ($planet['owner'] != 0) {
            $this->session->set('error', 'Planet is already owned');
            header('Location: /planet/' . $planetId);
            exit;
        }

        // Check if player is in same sector
        if ($planet['sector_id'] != $ship['sector']) {
            $this->session->set('error', 'You must be in the same sector');
            header('Location: /planet/' . $planetId);
            exit;
        }

        // Check if player has colonists
        if ($ship['ship_colonists'] < 100) {
            $this->session->set('error', 'You need at least 100 colonists to colonize a planet');
            header('Location: /planet/' . $planetId);
            exit;
        }

        // Transfer colonists to planet and claim it
        $colonistsToTransfer = min(100, $ship['ship_colonists']);

        $this->planetModel->update($planetId, [
            'owner' => $ship['ship_id'],
            'colonists' => $planet['colonists'] + $colonistsToTransfer
        ]);

        $this->shipModel->update((int)$ship['ship_id'], [
            'ship_colonists' => $ship['ship_colonists'] - $colonistsToTransfer
        ]);

        $this->session->set('message', "Planet colonized! Transferred $colonistsToTransfer colonists.");
        header('Location: /planet/' . $planetId);
        exit;
    }

    /**
     * Manage planet (transfer resources, set production, etc.)
     */
    public function manage(int $planetId): void
    {
        $ship = $this->requireAuth();

        $planet = $this->planetModel->find($planetId);

        if (!$planet || $planet['owner'] != $ship['ship_id']) {
            $this->session->set('error', 'You do not own this planet');
            header('Location: /main');
            exit;
        }

        $isOnPlanet = $ship['on_planet'] && $ship['planet_id'] == $planetId;

        // Calculate ship capacity
        $maxHolds = $this->calculateHolds($ship['hull']);
        $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                     $ship['ship_goods'] + $ship['ship_energy'] +
                     $ship['ship_colonists'];

        $data = compact('ship', 'planet', 'isOnPlanet', 'maxHolds', 'usedHolds');

        ob_start();
        include __DIR__ . '/../Views/planet_manage.php';
        echo ob_get_clean();
    }

    /**
     * Transfer resources between ship and planet
     */
    public function transfer(int $planetId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        $planet = $this->planetModel->find($planetId);

        if (!$planet || $planet['owner'] != $ship['ship_id']) {
            $this->session->set('error', 'You do not own this planet');
            header('Location: /main');
            exit;
        }

        // Must be on planet surface
        if (!$ship['on_planet'] || $ship['planet_id'] != $planetId) {
            $this->session->set('error', 'You must be on the planet surface to transfer resources');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        $direction = $_POST['direction'] ?? ''; // 'to_planet' or 'to_ship'
        $resourceType = $_POST['resource_type'] ?? '';
        $amount = max(0, (int)($_POST['amount'] ?? 0));

        $validResources = ['ore', 'organics', 'goods', 'energy', 'colonists', 'fighters', 'credits'];
        if (!in_array($resourceType, $validResources)) {
            $this->session->set('error', 'Invalid resource type');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        if ($direction === 'to_planet') {
            $this->transferToPlanet($ship, $planet, $resourceType, $amount, $planetId);
        } elseif ($direction === 'to_ship') {
            $this->transferToShip($ship, $planet, $resourceType, $amount, $planetId);
        } else {
            $this->session->set('error', 'Invalid transfer direction');
        }

        header('Location: /planet/manage/' . $planetId);
        exit;
    }

    private function transferToPlanet(array $ship, array $planet, string $resource, int $amount, int $planetId): void
    {
        $shipColumn = 'ship_' . $resource;
        if ($resource === 'credits' || $resource === 'fighters') {
            $shipColumn = $resource;
        }

        if ($ship[$shipColumn] < $amount) {
            $this->session->set('error', 'Not enough ' . $resource . ' on ship');
            return;
        }

        // Update planet
        $this->planetModel->update($planetId, [
            $resource => $planet[$resource] + $amount
        ]);

        // Update ship
        $this->shipModel->update((int)$ship['ship_id'], [
            $shipColumn => $ship[$shipColumn] - $amount
        ]);

        $this->session->set('message', "Transferred $amount $resource to planet");
    }

    private function transferToShip(array $ship, array $planet, string $resource, int $amount, int $planetId): void
    {
        if ($planet[$resource] < $amount) {
            $this->session->set('error', 'Not enough ' . $resource . ' on planet');
            return;
        }

        $shipColumn = 'ship_' . $resource;
        if ($resource === 'credits' || $resource === 'fighters') {
            $shipColumn = $resource;
        }

        // Check cargo space for non-credits/fighters
        if (!in_array($resource, ['credits', 'fighters'])) {
            $maxHolds = $this->calculateHolds($ship['hull']);
            $usedHolds = $ship['ship_ore'] + $ship['ship_organics'] +
                         $ship['ship_goods'] + $ship['ship_energy'] +
                         $ship['ship_colonists'];

            if ($usedHolds + $amount > $maxHolds) {
                $this->session->set('error', 'Not enough cargo space on ship');
                return;
            }
        }

        // Update planet
        $this->planetModel->update($planetId, [
            $resource => $planet[$resource] - $amount
        ]);

        // Update ship
        $this->shipModel->update((int)$ship['ship_id'], [
            $shipColumn => $ship[$shipColumn] + $amount
        ]);

        $this->session->set('message', "Transferred $amount $resource to ship");
    }

    /**
     * Update production allocation
     */
    public function updateProduction(int $planetId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        $planet = $this->planetModel->find($planetId);

        if (!$planet || $planet['owner'] != $ship['ship_id']) {
            $this->session->set('error', 'You do not own this planet');
            header('Location: /main');
            exit;
        }

        // Get production percentages
        $prodOre = max(0, min(100, (float)($_POST['prod_ore'] ?? 0)));
        $prodOrganics = max(0, min(100, (float)($_POST['prod_organics'] ?? 0)));
        $prodGoods = max(0, min(100, (float)($_POST['prod_goods'] ?? 0)));
        $prodEnergy = max(0, min(100, (float)($_POST['prod_energy'] ?? 0)));
        $prodFighters = max(0, min(100, (float)($_POST['prod_fighters'] ?? 0)));
        $prodTorp = max(0, min(100, (float)($_POST['prod_torp'] ?? 0)));

        // Check total is 100%
        $total = $prodOre + $prodOrganics + $prodGoods + $prodEnergy + $prodFighters + $prodTorp;
        if (abs($total - 100) > 0.1) {
            $this->session->set('error', 'Production allocation must total 100%');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        $this->planetModel->update($planetId, [
            'prod_ore' => $prodOre,
            'prod_organics' => $prodOrganics,
            'prod_goods' => $prodGoods,
            'prod_energy' => $prodEnergy,
            'prod_fighters' => $prodFighters,
            'prod_torp' => $prodTorp
        ]);

        $this->session->set('message', 'Production allocation updated');
        header('Location: /planet/manage/' . $planetId);
        exit;
    }

    /**
     * Build a base on the planet
     */
    public function buildBase(int $planetId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        $planet = $this->planetModel->find($planetId);

        if (!$planet || $planet['owner'] != $ship['ship_id']) {
            $this->session->set('error', 'You do not own this planet');
            header('Location: /main');
            exit;
        }

        if ($planet['base']) {
            $this->session->set('error', 'Planet already has a base');
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        // Base costs from config
        $baseCost = $this->config['game']['base_credits'] ?? 10000000;
        $baseOre = $this->config['game']['base_ore'] ?? 10000;
        $baseOrganics = $this->config['game']['base_organics'] ?? 10000;
        $baseGoods = $this->config['game']['base_goods'] ?? 10000;

        // Check if planet has enough resources
        if ($planet['credits'] < $baseCost ||
            $planet['ore'] < $baseOre ||
            $planet['organics'] < $baseOrganics ||
            $planet['goods'] < $baseGoods) {
            $this->session->set('error', "Not enough resources. Need: $baseCost credits, $baseOre ore, $baseOrganics organics, $baseGoods goods");
            header('Location: /planet/manage/' . $planetId);
            exit;
        }

        // Build base
        $this->planetModel->update($planetId, [
            'base' => true,
            'credits' => $planet['credits'] - $baseCost,
            'ore' => $planet['ore'] - $baseOre,
            'organics' => $planet['organics'] - $baseOrganics,
            'goods' => $planet['goods'] - $baseGoods
        ]);

        // Update sector ownership
        $this->updateSectorOwnership($planet['sector_id']);

        $this->session->set('message', 'Base construction complete! This sector may now be under your control.');
        header('Location: /planet/manage/' . $planetId);
        exit;
    }

    /**
     * Update sector ownership based on bases
     */
    private function updateSectorOwnership(int $sectorId): void
    {
        // Count bases by owner in this sector
        $sql = "SELECT owner, COUNT(*) as base_count
                FROM planets
                WHERE sector_id = :sector AND base = TRUE AND owner > 0
                GROUP BY owner
                ORDER BY base_count DESC";

        $bases = $this->planetModel->db->fetchAll($sql, ['sector' => $sectorId]);

        $minBases = $this->config['game']['min_bases_to_own'] ?? 3;

        if (empty($bases) || $bases[0]['base_count'] < $minBases) {
            // Not enough bases, set to neutral
            $this->universeModel->update($sectorId, ['zone_id' => 1]);
        } else {
            // Winner has enough bases
            // This is simplified - full implementation would check for conflicts
            // For now, just mark as player-controlled
        }
    }

    /**
     * View all player's planets
     */
    public function listPlanets(): void
    {
        $ship = $this->requireAuth();

        $planets = $this->planetModel->getPlayerPlanets((int)$ship['ship_id']);

        // Calculate totals
        $totals = [
            'colonists' => 0,
            'ore' => 0,
            'organics' => 0,
            'goods' => 0,
            'energy' => 0,
            'credits' => 0,
            'fighters' => 0,
            'bases' => 0
        ];

        foreach ($planets as $planet) {
            $totals['colonists'] += $planet['colonists'];
            $totals['ore'] += $planet['ore'];
            $totals['organics'] += $planet['organics'];
            $totals['goods'] += $planet['goods'];
            $totals['energy'] += $planet['energy'];
            $totals['credits'] += $planet['credits'];
            $totals['fighters'] += $planet['fighters'];
            if ($planet['base']) $totals['bases']++;
        }

        $data = compact('ship', 'planets', 'totals');

        ob_start();
        include __DIR__ . '/../Views/planets.php';
        echo ob_get_clean();
    }

    private function calculateHolds(int $level): int
    {
        return (int)round(pow(1.5, $level) * 100);
    }
}
