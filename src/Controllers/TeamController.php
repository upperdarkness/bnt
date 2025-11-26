<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Team;

class TeamController
{
    public function __construct(
        private Ship $shipModel,
        private Team $teamModel,
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
     * List all teams
     */
    public function index(): void
    {
        $ship = $this->requireAuth();
        $teams = $this->teamModel->getAll();
        $invitations = $this->teamModel->getInvitationsForPlayer((int)$ship['ship_id']);

        $data = compact('ship', 'teams', 'invitations', 'session');

        ob_start();
        include __DIR__ . '/../Views/teams.php';
        echo ob_get_clean();
    }

    /**
     * Show team creation form
     */
    public function create(): void
    {
        $ship = $this->requireAuth();

        // Check if already in a team
        if ($ship['team'] != 0) {
            $this->session->set('error', 'You must leave your current team before creating a new one');
            header('Location: /teams');
            exit;
        }

        $data = compact('ship', 'session');

        ob_start();
        include __DIR__ . '/../Views/team_create.php';
        echo ob_get_clean();
    }

    /**
     * Create a new team
     */
    public function store(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams/create');
            exit;
        }

        // Check if already in a team
        if ($ship['team'] != 0) {
            $this->session->set('error', 'You must leave your current team before creating a new one');
            header('Location: /teams');
            exit;
        }

        $name = trim($_POST['team_name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Validate
        if (empty($name)) {
            $this->session->set('error', 'Team name is required');
            header('Location: /teams/create');
            exit;
        }

        if (strlen($name) < 3 || strlen($name) > 50) {
            $this->session->set('error', 'Team name must be between 3 and 50 characters');
            header('Location: /teams/create');
            exit;
        }

        // Create team
        $teamId = $this->teamModel->create((int)$ship['ship_id'], $name, $description);

        if (!$teamId) {
            $this->session->set('error', 'Team name already exists');
            header('Location: /teams/create');
            exit;
        }

        $this->session->set('message', "Team '$name' created successfully!");
        header("Location: /teams/$teamId");
        exit;
    }

    /**
     * View team details
     */
    public function show(int $teamId): void
    {
        $ship = $this->requireAuth();
        $team = $this->teamModel->find($teamId);

        if (!$team) {
            $this->session->set('error', 'Team not found');
            header('Location: /teams');
            exit;
        }

        $members = $this->teamModel->getMembers($teamId);
        $statistics = $this->teamModel->getStatistics($teamId);
        $messages = $this->teamModel->getMessages($teamId, 20);
        $isFounder = $this->teamModel->isFounder($teamId, (int)$ship['ship_id']);
        $isMember = $ship['team'] == $teamId;
        $pendingInvitations = $isFounder ? $this->teamModel->getInvitationsForTeam($teamId) : [];

        $data = compact('ship', 'team', 'members', 'statistics', 'messages', 'isFounder', 'isMember', 'pendingInvitations', 'session');

        ob_start();
        include __DIR__ . '/../Views/team_view.php';
        echo ob_get_clean();
    }

    /**
     * Leave team
     */
    public function leave(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams');
            exit;
        }

        if ($ship['team'] == 0) {
            $this->session->set('error', 'You are not in a team');
            header('Location: /teams');
            exit;
        }

        $team = $this->teamModel->find((int)$ship['team']);

        // Check if founder - cannot leave if there are other members
        if ($team && $this->teamModel->isFounder((int)$team['team_id'], (int)$ship['ship_id'])) {
            $members = $this->teamModel->getMembers((int)$team['team_id']);
            if (count($members) > 1) {
                $this->session->set('error', 'As founder, you must disband the team or transfer leadership before leaving');
                header('Location: /teams/' . $team['team_id']);
                exit;
            }
            // Founder is the only member, delete the team
            $this->teamModel->delete((int)$team['team_id']);
            $this->session->set('message', 'Team disbanded');
        } else {
            $this->teamModel->removeMember((int)$ship['ship_id']);
            $this->session->set('message', 'You have left the team');
        }

        header('Location: /teams');
        exit;
    }

    /**
     * Kick member from team
     */
    public function kick(int $memberId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams');
            exit;
        }

        if ($ship['team'] == 0) {
            $this->session->set('error', 'You are not in a team');
            header('Location: /teams');
            exit;
        }

        // Check if founder
        if (!$this->teamModel->isFounder((int)$ship['team'], (int)$ship['ship_id'])) {
            $this->session->set('error', 'Only the founder can kick members');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        // Get member
        $member = $this->shipModel->find($memberId);
        if (!$member || $member['team'] != $ship['team']) {
            $this->session->set('error', 'Member not found in your team');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        // Cannot kick yourself
        if ($memberId == $ship['ship_id']) {
            $this->session->set('error', 'You cannot kick yourself. Use leave instead.');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        $this->teamModel->removeMember($memberId);
        $this->session->set('message', "Kicked {$member['character_name']} from the team");
        header('Location: /teams/' . $ship['team']);
        exit;
    }

    /**
     * Invite player to team
     */
    public function invite(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams');
            exit;
        }

        if ($ship['team'] == 0) {
            $this->session->set('error', 'You are not in a team');
            header('Location: /teams');
            exit;
        }

        $playerName = trim($_POST['player_name'] ?? '');

        if (empty($playerName)) {
            $this->session->set('error', 'Player name is required');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        // Find player by name
        $invitee = $this->shipModel->findByName($playerName);

        if (!$invitee) {
            $this->session->set('error', 'Player not found');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        // Check if already in a team
        if ($invitee['team'] != 0) {
            $this->session->set('error', 'Player is already in a team');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        // Cannot invite yourself
        if ($invitee['ship_id'] == $ship['ship_id']) {
            $this->session->set('error', 'You cannot invite yourself');
            header('Location: /teams/' . $ship['team']);
            exit;
        }

        // Create invitation
        $result = $this->teamModel->createInvitation(
            (int)$ship['team'],
            (int)$ship['ship_id'],
            (int)$invitee['ship_id']
        );

        if (!$result) {
            $this->session->set('error', 'Invitation already sent to this player');
        } else {
            $this->session->set('message', "Invitation sent to {$invitee['character_name']}");
        }

        header('Location: /teams/' . $ship['team']);
        exit;
    }

    /**
     * Accept team invitation
     */
    public function acceptInvitation(int $invitationId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams');
            exit;
        }

        if ($ship['team'] != 0) {
            $this->session->set('error', 'You must leave your current team first');
            header('Location: /teams');
            exit;
        }

        $result = $this->teamModel->acceptInvitation($invitationId, (int)$ship['ship_id']);

        if ($result) {
            $this->session->set('message', 'You have joined the team!');
        } else {
            $this->session->set('error', 'Invitation not found or expired');
        }

        header('Location: /teams');
        exit;
    }

    /**
     * Decline team invitation
     */
    public function declineInvitation(int $invitationId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams');
            exit;
        }

        $this->teamModel->declineInvitation($invitationId, (int)$ship['ship_id']);
        $this->session->set('message', 'Invitation declined');

        header('Location: /teams');
        exit;
    }

    /**
     * Post message to team
     */
    public function postMessage(int $teamId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams/' . $teamId);
            exit;
        }

        // Check if member
        if ($ship['team'] != $teamId) {
            $this->session->set('error', 'You are not a member of this team');
            header('Location: /teams');
            exit;
        }

        $message = trim($_POST['message'] ?? '');

        if (empty($message)) {
            $this->session->set('error', 'Message cannot be empty');
            header('Location: /teams/' . $teamId);
            exit;
        }

        if (strlen($message) > 1000) {
            $this->session->set('error', 'Message is too long (max 1000 characters)');
            header('Location: /teams/' . $teamId);
            exit;
        }

        $this->teamModel->postMessage($teamId, (int)$ship['ship_id'], $message);
        $this->session->set('message', 'Message posted');

        header('Location: /teams/' . $teamId);
        exit;
    }

    /**
     * Update team settings
     */
    public function update(int $teamId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams/' . $teamId);
            exit;
        }

        // Check if founder
        if (!$this->teamModel->isFounder($teamId, (int)$ship['ship_id'])) {
            $this->session->set('error', 'Only the founder can update team settings');
            header('Location: /teams/' . $teamId);
            exit;
        }

        $updates = [
            'description' => trim($_POST['description'] ?? ''),
            'team_desc' => trim($_POST['team_desc'] ?? ''),
        ];

        $this->teamModel->update($teamId, $updates);
        $this->session->set('message', 'Team settings updated');

        header('Location: /teams/' . $teamId);
        exit;
    }

    /**
     * Disband team (founder only)
     */
    public function disband(int $teamId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /teams/' . $teamId);
            exit;
        }

        // Check if founder
        if (!$this->teamModel->isFounder($teamId, (int)$ship['ship_id'])) {
            $this->session->set('error', 'Only the founder can disband the team');
            header('Location: /teams/' . $teamId);
            exit;
        }

        $team = $this->teamModel->find($teamId);
        $this->teamModel->delete($teamId);

        $this->session->set('message', "Team '{$team['team_name']}' has been disbanded");
        header('Location: /teams');
        exit;
    }
}
