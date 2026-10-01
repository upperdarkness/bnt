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
        private array $config,
        private ?\BNT\Services\AlignmentService $alignment = null
    ) {}

    /**
     * Display player rankings
     */
    public function index(): void
    {
        // Require authentication
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $playerId = $this->session->getUserId();
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship data
        $ship = $this->shipModel->find($playerId);
        if (!$ship) {
            $this->session->set('error', 'Ship not found');
            header('Location: /');
            exit;
        }

        // Get sort parameter from query string
        $sortBy = $_GET['sort'] ?? 'score';
        $validSorts = ['score', 'turns', 'login', 'good', 'bad', 'alignment', 'alliance', 'efficiency'];

        if (!in_array($sortBy, $validSorts)) {
            $sortBy = 'score';
        }

        // Get max rank from config
        $maxRank = $this->config['max_rank'] ?? 100;

        // Fetch rankings
        $includeNpcs = (bool)($this->config['npc']['show_in_rankings'] ?? false);
        $rankings = $this->rankingModel->getRankings($sortBy, $maxRank, $includeNpcs);
        $playerCount = $this->rankingModel->getPlayerCount($includeNpcs);
        $currentPlayerRank = $this->rankingModel->getPlayerRank($playerId, $includeNpcs);

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
            'alignmentService' => $this->alignment,
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
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $playerId = $this->session->getUserId();
        if (!$playerId) {
            header('Location: /');
            exit;
        }

        // Get current player's ship data
        $ship = $this->shipModel->find($playerId);
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
     * NPC faction leaderboard
     */
    public function factions(): void
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }
        $ship = $this->shipModel->find($this->session->getUserId());
        if (!$ship) {
            header('Location: /');
            exit;
        }
        $this->render('ranking_factions', [
            'ship' => $ship,
            'factions' => $this->rankingModel->getFactionLeaderboard(),
            'factionLabels' => array_map(static fn($f) => $f['label'], $this->config['npc']['factions'] ?? []),
            'session' => $this->session,
            'title' => 'Faction Rankings',
            'showHeader' => true,
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
