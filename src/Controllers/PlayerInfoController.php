<?php
declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Models\Ship;
use BNT\Models\PlayerInfo;
use BNT\Core\Session;

class PlayerInfoController
{
    public function __construct(
        private Ship $shipModel,
        private PlayerInfo $playerInfoModel,
        private Session $session,
        private array $config
    ) {}

    /**
     * Display player information page
     */
    public function show(int $targetPlayerId): void
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

        // Get target player information
        $targetPlayer = $this->playerInfoModel->getPlayerInfo($targetPlayerId);
        if (!$targetPlayer) {
            $this->session->set('error', 'Player not found');
            header('Location: /ranking');
            exit;
        }

        // Get additional information
        $rank = $this->playerInfoModel->getPlayerRank($targetPlayerId);
        $planetCount = $this->playerInfoModel->getPlanetCount($targetPlayerId);
        $shipLevel = PlayerInfo::calculateShipLevel($targetPlayer);
        $formattedRating = PlayerInfo::formatRating((float)$targetPlayer['rating']);
        $activitySummary = $this->playerInfoModel->getActivitySummary($targetPlayerId);

        // Get team members if player is in a team
        $teamMembers = [];
        if ($targetPlayer['team_id']) {
            $teamMembers = $this->playerInfoModel->getTeamMembers(
                (int)$targetPlayer['team_id'],
                $targetPlayerId
            );
        }

        // Calculate efficiency
        $efficiency = 0;
        if ($targetPlayer['turns_used'] >= 150) {
            $efficiency = (int)round($targetPlayer['score'] / max(1, $targetPlayer['turns_used']));
        }

        // Determine if viewing own profile
        $isOwnProfile = ($targetPlayerId === $playerId);

        // Render view
        $this->render('player_info', [
            'ship' => $ship,
            'targetPlayer' => $targetPlayer,
            'rank' => $rank,
            'planetCount' => $planetCount,
            'shipLevel' => $shipLevel,
            'formattedRating' => $formattedRating,
            'efficiency' => $efficiency,
            'activitySummary' => $activitySummary,
            'teamMembers' => $teamMembers,
            'isOwnProfile' => $isOwnProfile,
            'canMessage' => $this->playerInfoModel->canMessage($targetPlayerId),
            'session' => $this->session,
            'title' => 'Player Info: ' . htmlspecialchars($targetPlayer['character_name']),
            'showHeader' => true
        ]);
    }

    /**
     * Search for players
     */
    public function search(): void
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

        // Get search query
        $searchQuery = $_GET['q'] ?? '';
        $results = [];

        if (strlen($searchQuery) >= 2) {
            $results = $this->playerInfoModel->searchPlayers($searchQuery, 20);
        }

        // Render view
        $this->render('player_search', [
            'ship' => $ship,
            'searchQuery' => $searchQuery,
            'results' => $results,
            'session' => $this->session,
            'title' => 'Search Players',
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
