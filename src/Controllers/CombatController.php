<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Combat;
use BNT\Models\AttackLog;
use BNT\Models\Skill;
use BNT\Models\ShipType;
use BNT\Services\CombatService;

class CombatController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Planet $planetModel,
        private Combat $combatModel,
        private AttackLog $attackLogModel,
        private Skill $skillModel,
        private Session $session,
        private array $config,
        private ?CombatService $combatService = null,
        private ?\BNT\Services\AlignmentService $alignment = null
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

        // Get sector defenses (other players)
        $sql = "SELECT sd.*, s.character_name, s.team
                FROM sector_defence sd
                JOIN ships s ON sd.ship_id = s.ship_id
                WHERE sd.sector_id = :sector
                AND sd.ship_id != :ship_id";

        $defenses = $this->shipModel->getDb()->fetchAll($sql, [
            'sector' => $ship['sector'],
            'ship_id' => $ship['ship_id']
        ]);

        // Get player's own deployed defenses in this sector (for recall)
        $myDefenses = $this->shipModel->getDb()->fetchAll(
            "SELECT * FROM sector_defence 
             WHERE sector_id = :sector 
             AND ship_id = :ship_id",
            ['sector' => $ship['sector'], 'ship_id' => $ship['ship_id']]
        );
        
        $myFighters = [];
        $myMines = [];
        $totalMyFighters = 0;
        $totalMyMines = 0;
        
        foreach ($myDefenses as $defense) {
            if ($defense['defence_type'] === 'F') {
                $myFighters[] = $defense;
                $totalMyFighters += $defense['quantity'];
            } elseif ($defense['defence_type'] === 'M') {
                $myMines[] = $defense;
                $totalMyMines += $defense['quantity'];
            }
        }

        $session = $this->session;
        $title = 'Combat - BlackNova Traders';
        $showHeader = true;
        
        // Check if in starbase sector (no combat allowed)
        $isStarbaseSector = $this->universeModel->isStarbase((int)$ship['sector']);
        $alignmentService = $this->alignment;
        
        // Extract variables to make them available to the view
        extract(compact('ship', 'sector', 'shipsInSector', 'planets', 'defenses', 'myFighters', 'myMines', 'totalMyFighters', 'totalMyMines', 'isStarbaseSector', 'alignmentService', 'session', 'title', 'showHeader'));

        ob_start();
        include __DIR__ . '/../Views/combat.php';
        echo ob_get_clean();
    }

    /**
     * Attack another ship (rules live in CombatService, shared with the API and NPCs)
     */
    public function attackShip(int $targetId): void
    {
        $ship = $this->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /combat');
            exit;
        }

        $result = $this->combatService->attackShip($ship, $targetId, !empty($_POST['confirm_protection']));
        $this->session->set($result['flash'], $result['text']);
        header('Location: /combat');
        exit;
    }

    /**
     * Attack a planet
     */
    public function attackPlanet(int $planetId): void
    {
        $ship = $this->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /combat');
            exit;
        }

        $result = $this->combatService->attackPlanet($ship, $planetId, !empty($_POST['confirm_protection']));
        $this->session->set($result['flash'], $result['text']);
        header('Location: /combat');
        exit;
    }

    /**
     * Deploy sector defenses (fighters or mines)
     */
    public function deployDefense(): void
    {
        $ship = $this->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /combat');
            exit;
        }

        $result = $this->combatService->deployDefence(
            $ship,
            (string)($_POST['defense_type'] ?? ''),
            max(0, (int)($_POST['quantity'] ?? 0)),
            !empty($_POST['confirm_protection'])
        );
        $this->session->set($result['flash'], $result['text']);
        header('Location: /combat');
        exit;
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

        $defenses = $this->shipModel->getDb()->fetchAll($sql, ['ship_id' => $ship['ship_id']]);

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
        $defense = $this->shipModel->getDb()->fetchOne(
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
            $returnTo = $_POST['return_to'] ?? 'defenses';
            header('Location: /' . $returnTo);
            exit;
        }

        // Retrieve defenses back to ship
        $shipColumn = $defense['defence_type'] === 'F' ? 'ship_fighters' : 'torps';
        $this->shipModel->update((int)$ship['ship_id'], [
            $shipColumn => $ship[$shipColumn] + $defense['quantity']
        ]);

        // Remove defense
        $this->shipModel->getDb()->execute(
            'DELETE FROM sector_defence WHERE defence_id = :id',
            ['id' => $defenseId]
        );

        $typeName = $defense['defence_type'] === 'F' ? 'fighters' : 'mines';
        $this->session->set('message', "Retrieved {$defense['quantity']} $typeName");
        $returnTo = $_POST['return_to'] ?? 'defenses';
        header('Location: /' . $returnTo);
        exit;
    }
}
