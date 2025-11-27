<?php
declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Models\Ranking;
use BNT\Models\Ship;
use BNT\Core\Session;

class RankingController
{
    public function __construct(
        private Ranking $rankingModel,
        private Ship $shipModel,
        private Session $session,
        private array $config
    ) {}

    /**
     * Display player rankings
     */
    public function index(): void
    {
        // Require authentication
        $playerId = $this->session->get('player_id');
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship data
        $ship = $this->shipModel->getShipById($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Get sort parameter from query string
        $sortBy = $_GET['sort'] ?? 'score';
        $validSorts = ['score', 'turns', 'login', 'good', 'bad', 'alliance', 'efficiency'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'score';
        }

        // Get max rank from config
        $maxRank = $this->config['max_rank'] ?? 100;

        // Fetch rankings
        $rankings = $this->rankingModel->getRankings($sortBy, $maxRank);
        $playerCount = $this->rankingModel->getPlayerCount();
        $currentPlayerRank = $this->rankingModel->getPlayerRank($playerId);

        // Add rank numbers to results
        foreach ($rankings as $index => &$player) {
            $player['rank'] = $index + 1;
            $player['formatted_rating'] = Ranking::formatRating((float)$player['rating']);
        }
        unset($player);

        // Render view
        $this->render('ranking', [
            'ship' => $ship,
            'rankings' => $rankings,
            'playerCount' => $playerCount,
            'currentPlayerRank' => $currentPlayerRank,
            'sortBy' => $sortBy,
            'maxRank' => $maxRank,
            'session' => $this->session,
            'title' => 'Player Rankings',
            'showHeader' => true
        ]);
    }

    /**
     * Display team rankings
     */
    public function teams(): void
    {
        // Require authentication
        $playerId = $this->session->get('player_id');
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship data
        $ship = $this->shipModel->getShipById($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Fetch team rankings
        $teamRankings = $this->rankingModel->getTeamRankings(20);

        // Add rank numbers
        foreach ($teamRankings as $index => &$team) {
            $team['rank'] = $index + 1;
        }
        unset($team);

        // Render view
        $this->render('ranking_teams', [
            'ship' => $ship,
            'teamRankings' => $teamRankings,
            'session' => $this->session,
            'title' => 'Team Rankings',
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
