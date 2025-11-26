<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Combat;

class CombatController
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

    /**
     * Show combat options for current sector
     */
    public function show(): void
    {
        $ship = $this->requireAuth();

        $sector = $this->universeModel->getSector((int)$ship['sector']);
        $shipsInSector = $this->shipModel->getShipsInSector(
            (int)$ship['sector'],
            (int)$ship['ship_id']
        );
        $planets = $this->planetModel->getPlanetsInSector((int)$ship['sector']);

        // Get sector defenses
        $sql = "SELECT sd.*, s.character_name, s.team
                FROM sector_defence sd
                JOIN ships s ON sd.ship_id = s.ship_id
                WHERE sd.sector_id = :sector
                AND sd.ship_id != :ship_id";

        $defenses = $this->shipModel->db->fetchAll($sql, [
            'sector' => $ship['sector'],
            'ship_id' => $ship['ship_id']
        ]);

        $data = compact('ship', 'sector', 'shipsInSector', 'planets', 'defenses');

        ob_start();
        include __DIR__ . '/../Views/combat.php';
        echo ob_get_clean();
    }

    /**
     * Attack another ship
     */
    public function attackShip(int $targetId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /combat');
            exit;
        }

        // Get target
        $target = $this->shipModel->find($targetId);
        if (!$target) {
            $this->session->set('error', 'Target not found');
            header('Location: /combat');
            exit;
        }

        // Validate attack
        if ($target['sector'] != $ship['sector']) {
            $this->session->set('error', 'Target is not in your sector');
            header('Location: /combat');
            exit;
        }

        if ($target['ship_id'] == $ship['ship_id']) {
            $this->session->set('error', 'You cannot attack yourself');
            header('Location: /combat');
            exit;
        }

        if ($ship['team'] != 0 && $target['team'] == $ship['team']) {
            $this->session->set('error', 'You cannot attack your team members');
            header('Location: /combat');
            exit;
        }

        // Check if attacker has turns
        if ($ship['turns'] < 1) {
            $this->session->set('error', 'Not enough turns');
            header('Location: /combat');
            exit;
        }

        // Execute combat
        $result = $this->combatModel->shipVsShip($ship, $target);

        // Use turn
        $this->shipModel->useTurns((int)$ship['ship_id'], 1);

        // Apply results
        if ($result['escaped']) {
            $this->session->set('error', $result['message']);
            $this->logCombat($ship['ship_id'], $targetId, 'escape', $result);
        } else {
            // Update torpedo count
            if ($result['torpedos_used'] > 0) {
                $this->shipModel->update((int)$ship['ship_id'], [
                    'torps' => $ship['torps'] - $result['torpedos_used']
                ]);
            }

            // Update fighters
            $this->shipModel->update((int)$ship['ship_id'], [
                'ship_fighters' => $ship['ship_fighters'] - $result['fighters_lost_attacker']
            ]);
            $this->shipModel->update($targetId, [
                'ship_fighters' => max(0, $target['ship_fighters'] - $result['fighters_lost_defender'])
            ]);

            // Apply damage
            if ($result['attacker_damage'] > 0) {
                $this->combatModel->applyDamageToShip((int)$ship['ship_id'], $result['attacker_damage']);
            }

            if ($result['defender_destroyed']) {
                $this->combatModel->destroyShip($targetId);

                // Award kill credits
                $credits = $this->combatModel->awardKillCredits((int)$ship['ship_id'], $target);

                // Collect bounty
                $bounty = $this->combatModel->collectBounty((int)$ship['ship_id'], $targetId);

                $totalEarnings = $credits + $bounty;
                $message = "Target destroyed! You earned $credits credits";
                if ($bounty > 0) {
                    $message .= " + $bounty bounty";
                }
                $message .= " = $totalEarnings total!";

                $this->session->set('message', $message);
                $this->logCombat($ship['ship_id'], $targetId, 'kill', $result);
            } else {
                // Apply damage to target
                if ($result['defender_damage'] > 0) {
                    $this->combatModel->applyDamageToShip($targetId, $result['defender_damage']);
                }

                $this->session->set('message', $result['message'] . " Damage dealt: {$result['defender_damage']}");
                $this->logCombat($ship['ship_id'], $targetId, 'attack', $result);
            }
        }

        header('Location: /combat');
        exit;
    }

    /**
     * Attack a planet
     */
    public function attackPlanet(int $planetId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /combat');
            exit;
        }

        // Get planet
        $planet = $this->planetModel->find($planetId);
        if (!$planet) {
            $this->session->set('error', 'Planet not found');
            header('Location: /combat');
            exit;
        }

        // Validate attack
        if ($planet['sector_id'] != $ship['sector']) {
            $this->session->set('error', 'Planet is not in your sector');
            header('Location: /combat');
            exit;
        }

        if ($planet['owner'] == $ship['ship_id']) {
            $this->session->set('error', 'You cannot attack your own planet');
            header('Location: /combat');
            exit;
        }

        // Check if attacker has turns
        if ($ship['turns'] < 5) {
            $this->session->set('error', 'Need at least 5 turns to attack a planet');
            header('Location: /combat');
            exit;
        }

        // Execute combat
        $result = $this->combatModel->shipVsPlanet($ship, $planet);

        // Use turns
        $this->shipModel->useTurns((int)$ship['ship_id'], 5);

        // Update torpedoes
        if ($result['torpedos_used'] > 0) {
            $this->shipModel->update((int)$ship['ship_id'], [
                'torps' => $ship['torps'] - $result['torpedos_used']
            ]);
        }

        // Update fighters
        if ($result['fighters_lost_ship'] > 0) {
            $this->shipModel->update((int)$ship['ship_id'], [
                'ship_fighters' => $ship['ship_fighters'] - $result['fighters_lost_ship']
            ]);
        }

        if ($result['fighters_lost_planet'] > 0) {
            $this->planetModel->update($planetId, [
                'fighters' => max(0, $planet['fighters'] - $result['fighters_lost_planet'])
            ]);
        }

        // Apply ship damage
        if ($result['ship_damage'] > 0) {
            $this->combatModel->applyDamageToShip((int)$ship['ship_id'], $result['ship_damage']);
        }

        // Handle results
        if ($result['planet_captured']) {
            $this->planetModel->capture($planetId, (int)$ship['ship_id']);
            $this->session->set('message', 'Planet captured!');
        } elseif ($result['success']) {
            // Damage planet base or defenses
            if ($planet['base']) {
                $this->planetModel->update($planetId, ['base' => false]);
            }
            $this->session->set('message', $result['message']);
        } else {
            $this->session->set('error', $result['message']);
        }

        $this->logPlanetCombat($ship['ship_id'], $planetId, $result);

        header('Location: /combat');
        exit;
    }

    /**
     * Deploy sector defenses (fighters or mines)
     */
    public function deployDefense(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /combat');
            exit;
        }

        $defenseType = $_POST['defense_type'] ?? '';
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));

        if (!in_array($defenseType, ['F', 'M'])) {
            $this->session->set('error', 'Invalid defense type');
            header('Location: /combat');
            exit;
        }

        $defenseTypeName = $defenseType === 'F' ? 'fighters' : 'mines';

        // Check if player has enough
        $shipColumn = $defenseType === 'F' ? 'ship_fighters' : 'torps';
        if ($ship[$shipColumn] < $quantity) {
            $this->session->set('error', "Not enough $defenseTypeName");
            header('Location: /combat');
            exit;
        }

        // Check if defense already exists
        $sql = "SELECT * FROM sector_defence
                WHERE sector_id = :sector
                AND ship_id = :ship
                AND defence_type = :type";

        $existing = $this->shipModel->db->fetchOne($sql, [
            'sector' => $ship['sector'],
            'ship' => $ship['ship_id'],
            'type' => $defenseType
        ]);

        if ($existing) {
            // Update existing
            $this->shipModel->db->execute(
                'UPDATE sector_defence SET quantity = quantity + :qty WHERE defence_id = :id',
                ['qty' => $quantity, 'id' => $existing['defence_id']]
            );
        } else {
            // Create new
            $this->shipModel->db->execute(
                'INSERT INTO sector_defence (ship_id, sector_id, defence_type, quantity) VALUES (:ship, :sector, :type, :qty)',
                [
                    'ship' => $ship['ship_id'],
                    'sector' => $ship['sector'],
                    'type' => $defenseType,
                    'qty' => $quantity
                ]
            );
        }

        // Remove from ship
        $this->shipModel->update((int)$ship['ship_id'], [
            $shipColumn => $ship[$shipColumn] - $quantity
        ]);

        // Check for defense vs defense combat
        $dvdResult = $this->combatModel->defenseVsDefense((int)$ship['sector'], (int)$ship['ship_id']);
        if ($dvdResult['combat_occurred']) {
            $message = "Deployed $quantity $defenseTypeName. " . $dvdResult['message'];
            $this->session->set('message', $message);
        } else {
            $this->session->set('message', "Deployed $quantity $defenseTypeName in this sector");
        }

        header('Location: /combat');
        exit;
    }

    /**
     * Log combat event
     */
    private function logCombat(int $attackerId, int $defenderId, string $type, array $result): void
    {
        $data = json_encode($result);

        $this->shipModel->db->execute(
            'INSERT INTO logs (ship_id, log_type, log_data) VALUES (:id, :type, :data)',
            ['id' => $attackerId, 'type' => 3, 'data' => $data]
        );

        $this->shipModel->db->execute(
            'INSERT INTO logs (ship_id, log_type, log_data) VALUES (:id, :type, :data)',
            ['id' => $defenderId, 'type' => 7, 'data' => $data]
        );
    }

    /**
     * Log planet combat
     */
    private function logPlanetCombat(int $attackerId, int $planetId, array $result): void
    {
        $data = json_encode($result);

        $this->shipModel->db->execute(
            'INSERT INTO logs (ship_id, log_type, log_data) VALUES (:id, :type, :data)',
            ['id' => $attackerId, 'type' => 13, 'data' => $data]
        );
    }

    /**
     * View all player's defenses across sectors
     */
    public function viewDefenses(): void
    {
        $ship = $this->requireAuth();

        // Get all player's defenses
        $sql = "SELECT sd.*, u.sector_name,
                CASE WHEN sd.defence_type = 'F' THEN 'Fighters' ELSE 'Mines' END as type_name
                FROM sector_defence sd
                JOIN universe u ON sd.sector_id = u.sector_id
                WHERE sd.ship_id = :ship_id
                ORDER BY sd.sector_id, sd.defence_type";

        $defenses = $this->shipModel->db->fetchAll($sql, ['ship_id' => $ship['ship_id']]);

        // Calculate totals
        $totalFighters = 0;
        $totalMines = 0;
        foreach ($defenses as $defense) {
            if ($defense['defence_type'] === 'F') {
                $totalFighters += $defense['quantity'];
            } else {
                $totalMines += $defense['quantity'];
            }
        }

        $data = compact('ship', 'defenses', 'totalFighters', 'totalMines');

        ob_start();
        include __DIR__ . '/../Views/defenses.php';
        echo ob_get_clean();
    }

    /**
     * Retrieve defenses from a sector
     */
    public function retrieveDefense(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /defenses');
            exit;
        }

        $defenseId = (int)($_POST['defence_id'] ?? 0);

        // Get defense
        $defense = $this->shipModel->db->fetchOne(
            'SELECT * FROM sector_defence WHERE defence_id = :id AND ship_id = :ship_id',
            ['id' => $defenseId, 'ship_id' => $ship['ship_id']]
        );

        if (!$defense) {
            $this->session->set('error', 'Defense not found or not owned by you');
            header('Location: /defenses');
            exit;
        }

        // Check if player is in the same sector
        if ($defense['sector_id'] != $ship['sector']) {
            $this->session->set('error', 'You must be in the same sector to retrieve defenses');
            header('Location: /defenses');
            exit;
        }

        // Retrieve defenses back to ship
        $shipColumn = $defense['defence_type'] === 'F' ? 'ship_fighters' : 'torps';
        $this->shipModel->update((int)$ship['ship_id'], [
            $shipColumn => $ship[$shipColumn] + $defense['quantity']
        ]);

        // Remove defense
        $this->shipModel->db->execute(
            'DELETE FROM sector_defence WHERE defence_id = :id',
            ['id' => $defenseId]
        );

        $typeName = $defense['defence_type'] === 'F' ? 'fighters' : 'mines';
        $this->session->set('message', "Retrieved {$defense['quantity']} $typeName");
        header('Location: /defenses');
        exit;
    }
}
