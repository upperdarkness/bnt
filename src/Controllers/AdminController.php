<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Core\AdminAuth;
use BNT\Models\Ship;
use BNT\Models\Universe;
use BNT\Models\Planet;
use BNT\Models\Team;

class AdminController
{
    public function __construct(
        private Ship $shipModel,
        private Universe $universeModel,
        private Planet $planetModel,
        private Team $teamModel,
        private Session $session,
        private AdminAuth $adminAuth,
        private array $config
    ) {}

    /**
     * Show admin login form
     */
    public function showLogin(): void
    {
        if ($this->adminAuth->isAuthenticated()) {
            header('Location: /admin');
            exit;
        }

        $session = $this->session;
        $title = 'Admin Login - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_login.php';
        echo ob_get_clean();
    }

    /**
     * Process admin login
     */
    public function login(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/login');
            exit;
        }

        $password = $_POST['password'] ?? '';

        if ($this->adminAuth->authenticate($password)) {
            header('Location: /admin');
            exit;
        }

        $this->session->set('error', 'Invalid admin password');
        header('Location: /admin/login');
        exit;
    }

    /**
     * Admin logout
     */
    public function logout(): void
    {
        $this->adminAuth->logout();
        $this->session->set('message', 'Logged out successfully');
        header('Location: /admin/login');
        exit;
    }

    /**
     * Admin dashboard
     */
    public function dashboard(): void
    {
        $this->adminAuth->requireAuth();

        // Get statistics
        $stats = [
            'total_players' => $this->shipModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM ships')['count'],
            'active_players' => $this->shipModel->getDb()->fetchOne(
                "SELECT COUNT(*) as count FROM ships WHERE last_login > NOW() - INTERVAL '7 days'"
            )['count'],
            'total_teams' => $this->teamModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM teams')['count'],
            'total_planets' => $this->planetModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM planets')['count'],
            'claimed_planets' => $this->planetModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM planets WHERE owner != 0')['count'],
            'total_sectors' => $this->universeModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM universe')['count'],
        ];

        // Recent players
        $recentPlayers = $this->shipModel->getDb()->fetchAll(
            'SELECT ship_id, character_name, email, last_login, score, credits
             FROM ships
             ORDER BY last_login DESC
             LIMIT 10'
        );

        $session = $this->session;
        $config = $this->config;
        $title = 'Admin Dashboard - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_dashboard.php';
        echo ob_get_clean();
    }

    /**
     * Manage players
     */
    public function players(): void
    {
        $this->adminAuth->requireAuth();

        $search = $_GET['search'] ?? '';
        $sql = 'SELECT ship_id, character_name, email, last_login, score, credits, turns, team, ship_destroyed
                FROM ships';

        $params = [];
        if (!empty($search)) {
            $sql .= ' WHERE character_name ILIKE :search OR email ILIKE :search';
            $params['search'] = "%$search%";
        }

        $sql .= ' ORDER BY last_login DESC LIMIT 100';

        $players = $this->shipModel->getDb()->fetchAll($sql, $params);

        $session = $this->session;
        $config = $this->config;
        $title = 'Manage Players - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_players.php';
        echo ob_get_clean();
    }

    /**
     * Edit player
     */
    public function editPlayer(int $shipId): void
    {
        $this->adminAuth->requireAuth();

        $player = $this->shipModel->find($shipId);

        if (!$player) {
            $this->session->set('error', 'Player not found');
            header('Location: /admin/players');
            exit;
        }

        $session = $this->session;
        $config = $this->config;
        $title = 'Edit Player - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_player_edit.php';
        echo ob_get_clean();
    }

    /**
     * Update player
     */
    public function updatePlayer(int $shipId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/players');
            exit;
        }

        $updates = [
            'character_name' => trim($_POST['character_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'credits' => max(0, (int)($_POST['credits'] ?? 0)),
            'turns' => max(0, (int)($_POST['turns'] ?? 0)),
            'ship_fighters' => max(0, (int)($_POST['ship_fighters'] ?? 0)),
            'ship_energy' => max(0, (int)($_POST['ship_energy'] ?? 0)),
            'score' => (int)($_POST['score'] ?? 0),
        ];

        $this->shipModel->update($shipId, $updates);
        $this->session->set('message', 'Player updated successfully');

        header('Location: /admin/players');
        exit;
    }

    /**
     * Delete player
     */
    public function deletePlayer(int $shipId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/players');
            exit;
        }

        // Get player info
        $player = $this->shipModel->find($shipId);

        // Remove from team
        if ($player && $player['team'] != 0) {
            $this->teamModel->removeMember($shipId);
        }

        // Delete player
        $this->shipModel->delete($shipId);

        $this->session->set('message', "Player '{$player['character_name']}' deleted");
        header('Location: /admin/players');
        exit;
    }

    /**
     * Manage teams
     */
    public function teams(): void
    {
        $this->adminAuth->requireAuth();

        $teams = $this->teamModel->getAll();

        $session = $this->session;
        $config = $this->config;
        $title = 'Manage Teams - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_teams.php';
        echo ob_get_clean();
    }

    /**
     * Delete team
     */
    public function deleteTeam(int $teamId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/teams');
            exit;
        }

        $team = $this->teamModel->find($teamId);
        $this->teamModel->delete($teamId);

        $this->session->set('message', "Team '{$team['team_name']}' deleted");
        header('Location: /admin/teams');
        exit;
    }

    /**
     * Universe management - List all sectors
     */
    public function universe(): void
    {
        $this->adminAuth->requireAuth();

        $search = trim($_GET['search'] ?? '');
        
        // Handle search
        if (!empty($search)) {
            $sectors = $this->universeModel->getDb()->fetchAll(
                'SELECT u.*,
                        (SELECT COUNT(*) FROM planets WHERE sector_id = u.sector_id) as planet_count
                 FROM universe u
                 WHERE u.sector_id::text LIKE :search OR u.sector_name ILIKE :search
                 ORDER BY u.sector_id
                 LIMIT 100',
                ['search' => "%$search%"]
            );
            $sectorCount = count($sectors);
            $totalPages = 1;
            $page = 1;
        } else {
            // Get sector count
            $sectorCount = $this->universeModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM universe')['count'];

            // Pagination
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 50;
            $offset = ($page - 1) * $perPage;
            $totalPages = (int)ceil($sectorCount / $perPage);

            // Get sectors with planet counts
            $sectors = $this->universeModel->getDb()->fetchAll(
                'SELECT u.*,
                        (SELECT COUNT(*) FROM planets WHERE sector_id = u.sector_id) as planet_count
                 FROM universe u
                 ORDER BY u.sector_id
                 LIMIT :limit OFFSET :offset',
                ['limit' => $perPage, 'offset' => $offset]
            );
        }

        $session = $this->session;
        $config = $this->config;
        $title = 'Universe Management - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_universe.php';
        echo ob_get_clean();
    }

    /**
     * View/edit individual sector
     */
    public function viewSector(int $sectorId): void
    {
        $this->adminAuth->requireAuth();

        $sector = $this->universeModel->getSector($sectorId);
        if (!$sector) {
            $this->session->set('error', 'Sector not found');
            header('Location: /admin/universe');
            exit;
        }

        // Get planets in this sector
        $planets = $this->planetModel->getPlanetsInSector($sectorId);
        $planetCount = count($planets);

        // Get linked sectors
        $linkedSectors = $this->universeModel->getLinkedSectors($sectorId);

        $session = $this->session;
        $config = $this->config;
        $title = 'Edit Sector - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_sector_edit.php';
        echo ob_get_clean();
    }

    /**
     * Update sector
     */
    public function updateSector(int $sectorId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/universe');
            exit;
        }

        $sector = $this->universeModel->getSector($sectorId);
        if (!$sector) {
            $this->session->set('error', 'Sector not found');
            header('Location: /admin/universe');
            exit;
        }

        // Validate port type
        $portType = $_POST['port_type'] ?? 'none';
        $validPortTypes = ['none', 'ore', 'organics', 'goods', 'energy', 'special'];
        if (!in_array($portType, $validPortTypes)) {
            $portType = 'none';
        }

        // Handle boolean properly for PostgreSQL
        $isStarbase = false;
        if (isset($_POST['is_starbase'])) {
            $isStarbase = ($_POST['is_starbase'] === '1' || $_POST['is_starbase'] === true || $_POST['is_starbase'] === 'true');
        }

        $updates = [
            'sector_name' => trim($_POST['sector_name'] ?? ''),
            'port_type' => $portType,
            'beacon' => trim($_POST['beacon'] ?? ''),
            'is_starbase' => $isStarbase,
            'zone_id' => max(1, (int)($_POST['zone_id'] ?? 1)),
        ];

        // Only update port inventory if there's a port
        if ($portType !== 'none') {
            $updates['port_ore'] = max(0, (int)($_POST['port_ore'] ?? 0));
            $updates['port_organics'] = max(0, (int)($_POST['port_organics'] ?? 0));
            $updates['port_goods'] = max(0, (int)($_POST['port_goods'] ?? 0));
            $updates['port_energy'] = max(0, (int)($_POST['port_energy'] ?? 0));
            $updates['port_colonists'] = max(0, (int)($_POST['port_colonists'] ?? 0));
        } else {
            // Clear port inventory when port type is 'none'
            $updates['port_ore'] = 0;
            $updates['port_organics'] = 0;
            $updates['port_goods'] = 0;
            $updates['port_energy'] = 0;
            $updates['port_colonists'] = 0;
        }

        try {
            $this->universeModel->update($sectorId, $updates);
            $this->session->set('message', "Sector $sectorId updated successfully");
        } catch (\Exception $e) {
            $this->session->set('error', 'Failed to update sector: ' . $e->getMessage());
            error_log('Sector update error: ' . $e->getMessage());
        }

        header('Location: /admin/universe/sector/' . $sectorId);
        exit;
    }

    /**
     * Create navigation link between sectors
     */
    public function createLink(int $sectorId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        $sector = $this->universeModel->getSector($sectorId);
        if (!$sector) {
            $this->session->set('error', 'Source sector not found');
            header('Location: /admin/universe');
            exit;
        }

        $targetSectorId = max(1, (int)($_POST['target_sector'] ?? 0));
        
        if ($targetSectorId === $sectorId) {
            $this->session->set('error', 'Cannot link a sector to itself');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        $targetSector = $this->universeModel->getSector($targetSectorId);
        if (!$targetSector) {
            $this->session->set('error', "Target sector $targetSectorId not found");
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Check if link already exists
        if ($this->universeModel->isLinked($sectorId, $targetSectorId)) {
            $this->session->set('error', "Link already exists between Sector $sectorId and Sector $targetSectorId");
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Create bidirectional link
        $this->universeModel->createLink($sectorId, $targetSectorId);
        $this->session->set('message', "Link created between Sector $sectorId and Sector $targetSectorId");

        header('Location: /admin/universe/sector/' . $sectorId);
        exit;
    }

    /**
     * Delete navigation link between sectors
     */
    public function deleteLink(int $sectorId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        $sector = $this->universeModel->getSector($sectorId);
        if (!$sector) {
            $this->session->set('error', 'Source sector not found');
            header('Location: /admin/universe');
            exit;
        }

        $targetSectorId = max(1, (int)($_POST['target_sector'] ?? 0));
        
        if ($targetSectorId === $sectorId) {
            $this->session->set('error', 'Invalid target sector');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Check if link exists
        if (!$this->universeModel->isLinked($sectorId, $targetSectorId)) {
            $this->session->set('error', "Link does not exist between Sector $sectorId and Sector $targetSectorId");
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Delete bidirectional link
        $this->universeModel->deleteLink($sectorId, $targetSectorId);
        $this->session->set('message', "Link removed between Sector $sectorId and Sector $targetSectorId");

        header('Location: /admin/universe/sector/' . $sectorId);
        exit;
    }

    /**
     * Create planet in sector
     */
    public function createPlanet(int $sectorId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        $sector = $this->universeModel->getSector($sectorId);
        if (!$sector) {
            $this->session->set('error', 'Sector not found');
            header('Location: /admin/universe');
            exit;
        }

        $planetName = trim($_POST['planet_name'] ?? '');
        if (empty($planetName)) {
            $this->session->set('error', 'Planet name is required');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Create planet
        $planetId = $this->planetModel->createPlanet($planetName, $sectorId, 0);
        $this->session->set('message', "Planet '$planetName' created in Sector $sectorId");

        header('Location: /admin/universe/sector/' . $sectorId);
        exit;
    }

    /**
     * Delete planet (only if not owned by a player)
     */
    public function deletePlanet(int $sectorId, int $planetId): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        $planet = $this->planetModel->find($planetId);
        if (!$planet) {
            $this->session->set('error', 'Planet not found');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        if ($planet['sector_id'] != $sectorId) {
            $this->session->set('error', 'Planet does not belong to this sector');
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Check if planet is owned by a player
        if ($planet['owner'] != 0) {
            $owner = $this->shipModel->find($planet['owner']);
            $ownerName = $owner ? $owner['character_name'] : 'Unknown';
            $this->session->set('error', "Cannot delete planet owned by player: $ownerName");
            header('Location: /admin/universe/sector/' . $sectorId);
            exit;
        }

        // Delete planet
        $this->planetModel->delete($planetId);
        $this->session->set('message', "Planet '{$planet['planet_name']}' deleted");

        header('Location: /admin/universe/sector/' . $sectorId);
        exit;
    }

    /**
     * Regenerate universe
     */
    public function regenerateUniverse(): void
    {
        $this->adminAuth->requireAuth();

        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /admin/universe');
            exit;
        }

        // Clear existing universe
        $this->universeModel->getDb()->execute('DELETE FROM universe');
        $this->universeModel->getDb()->execute('DELETE FROM planets');

        // Generate new universe
        $this->universeModel->generate($this->config['game']['sector_max']);

        $this->session->set('message', 'Universe regenerated successfully');
        header('Location: /admin/universe');
        exit;
    }

    /**
     * Game settings
     */
    public function settings(): void
    {
        $this->adminAuth->requireAuth();

        $session = $this->session;
        $config = $this->config;
        $title = 'Settings - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_settings.php';
        echo ob_get_clean();
    }

    /**
     * System logs
     */
    public function logs(): void
    {
        $this->adminAuth->requireAuth();

        // Get recent combat logs
        $combatLogs = $this->shipModel->getDb()->fetchAll(
            'SELECT l.*, s.character_name
             FROM logs l
             JOIN ships s ON l.ship_id = s.ship_id
             WHERE l.log_type IN (3, 7, 13)
             ORDER BY l.log_id DESC
             LIMIT 50'
        );

        $session = $this->session;
        $config = $this->config;
        $title = 'Logs - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_logs.php';
        echo ob_get_clean();
    }

    /**
     * Database statistics
     */
    public function statistics(): void
    {
        $this->adminAuth->requireAuth();

        // Gather comprehensive statistics
        $stats = [
            'players' => [
                'total' => $this->shipModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM ships')['count'],
                'active_today' => $this->shipModel->getDb()->fetchOne(
                    "SELECT COUNT(*) as count FROM ships WHERE last_login > NOW() - INTERVAL '1 day'"
                )['count'],
                'active_week' => $this->shipModel->getDb()->fetchOne(
                    "SELECT COUNT(*) as count FROM ships WHERE last_login > NOW() - INTERVAL '7 days'"
                )['count'],
                'destroyed' => $this->shipModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM ships WHERE ship_destroyed = true')['count'],
            ],
            'economy' => [
                'total_credits' => (int)($this->shipModel->getDb()->fetchOne('SELECT SUM(credits) as total FROM ships')['total'] ?? 0),
                'avg_credits' => round((float)($this->shipModel->getDb()->fetchOne('SELECT AVG(credits) as avg FROM ships')['avg'] ?? 0)),
                'richest' => (int)($this->shipModel->getDb()->fetchOne('SELECT MAX(credits) as max FROM ships')['max'] ?? 0),
            ],
            'military' => [
                'total_fighters' => (int)($this->shipModel->getDb()->fetchOne('SELECT SUM(ship_fighters) as total FROM ships')['total'] ?? 0),
                'total_defenses' => (int)($this->shipModel->getDb()->fetchOne('SELECT SUM(quantity) as total FROM sector_defence')['total'] ?? 0),
            ],
            'planets' => [
                'total' => $this->planetModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM planets')['count'],
                'claimed' => $this->planetModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM planets WHERE owner != 0')['count'],
                'with_bases' => $this->planetModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM planets WHERE base = true')['count'],
            ],
            'teams' => [
                'total' => $this->teamModel->getDb()->fetchOne('SELECT COUNT(*) as count FROM teams')['count'],
                'avg_members' => round((float)($this->teamModel->getDb()->fetchOne(
                    'SELECT AVG(member_count) as avg FROM (SELECT COUNT(*) as member_count FROM ships WHERE team != 0 GROUP BY team) as t'
                )['avg'] ?? 0), 1),
            ],
        ];

        // Top players
        $topPlayers = $this->shipModel->getDb()->fetchAll(
            'SELECT character_name, score, credits, ship_fighters
             FROM ships
             WHERE ship_destroyed = false
             ORDER BY score DESC
             LIMIT 10'
        );

        $session = $this->session;
        $config = $this->config;
        $title = 'Statistics - Admin - BlackNova Traders';
        $showHeader = false;

        ob_start();
        include __DIR__ . '/../Views/admin_statistics.php';
        echo ob_get_clean();
    }
}
