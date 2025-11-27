<?php
declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Models\Ship;
use BNT\Models\AttackLog;
use BNT\Core\Session;

class AttackLogController
{
    public function __construct(
        private Ship $shipModel,
        private AttackLog $attackLogModel,
        private Session $session,
        private array $config
    ) {}

    /**
     * Display attack log overview
     */
    public function index(): void
    {
        // Require authentication
        $playerId = $this->session->get('player_id');
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship
        $ship = $this->shipModel->getShipById($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Get recent activity
        $recentActivity = $this->attackLogModel->getRecentActivity($playerId, 25);
        $statistics = $this->attackLogModel->getStatistics($playerId);

        // Render view
        $this->render('attack_logs', [
            'ship' => $ship,
            'recentActivity' => $recentActivity,
            'statistics' => $statistics,
            'session' => $this->session,
            'title' => 'Attack Logs',
            'showHeader' => true
        ]);
    }

    /**
     * Display attacks made by player
     */
    public function attacksMade(): void
    {
        // Require authentication
        $playerId = $this->session->get('player_id');
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship
        $ship = $this->shipModel->getShipById($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Get page number
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        // Get attacks made
        $attacks = $this->attackLogModel->getAttacksMadeBy($playerId, $perPage, $offset);
        $totalCount = $this->attackLogModel->getLogCount($playerId, 'made');
        $totalPages = max(1, (int)ceil($totalCount / $perPage));

        // Render view
        $this->render('attack_logs_made', [
            'ship' => $ship,
            'attacks' => $attacks,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'session' => $this->session,
            'title' => 'Attacks Made',
            'showHeader' => true
        ]);
    }

    /**
     * Display attacks received by player
     */
    public function attacksReceived(): void
    {
        // Require authentication
        $playerId = $this->session->get('player_id');
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship
        $ship = $this->shipModel->getShipById($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Get page number
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        // Get attacks received
        $attacks = $this->attackLogModel->getAttacksReceivedBy($playerId, $perPage, $offset);
        $totalCount = $this->attackLogModel->getLogCount($playerId, 'received');
        $totalPages = max(1, (int)ceil($totalCount / $perPage));

        // Render view
        $this->render('attack_logs_received', [
            'ship' => $ship,
            'attacks' => $attacks,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'session' => $this->session,
            'title' => 'Attacks Received',
            'showHeader' => true
        ]);
    }

    /**
     * Render a view
     */
    private function render(string $view, array $data = []): void
    {
        extract($data);
        ob_start();
        include __DIR__ . '/../Views/' . $view . '.php';
        $content = ob_get_clean();
        include __DIR__ . '/../Views/layout.php';
    }
}
