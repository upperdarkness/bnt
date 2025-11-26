<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class Team
{
    public function __construct(private Database $db) {}

    /**
     * Find team by ID
     */
    public function find(int $id): ?array
    {
        $result = $this->db->fetchOne(
            'SELECT * FROM teams WHERE team_id = :id',
            ['id' => $id]
        );

        return $result ?: null;
    }

    /**
     * Get all teams
     */
    public function getAll(): array
    {
        return $this->db->fetchAll(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM ships WHERE team = t.team_id) as member_count,
                    (SELECT character_name FROM ships WHERE ship_id = t.founder_id) as founder_name
             FROM teams t
             ORDER BY t.team_name'
        );
    }

    /**
     * Get team by name
     */
    public function findByName(string $name): ?array
    {
        $result = $this->db->fetchOne(
            'SELECT * FROM teams WHERE team_name = :name',
            ['name' => $name]
        );

        return $result ?: null;
    }

    /**
     * Create a new team
     */
    public function create(int $founderId, string $name, string $description = ''): ?int
    {
        // Check if name already exists
        if ($this->findByName($name)) {
            return null;
        }

        $this->db->execute(
            'INSERT INTO teams (team_name, description, founder_id, created_date)
             VALUES (:name, :desc, :founder, NOW())',
            [
                'name' => $name,
                'desc' => $description,
                'founder' => $founderId
            ]
        );

        $teamId = (int)$this->db->lastInsertId();

        // Add founder to team
        $this->db->execute(
            'UPDATE ships SET team = :team WHERE ship_id = :ship',
            ['team' => $teamId, 'ship' => $founderId]
        );

        return $teamId;
    }

    /**
     * Update team information
     */
    public function update(int $teamId, array $data): bool
    {
        $fields = [];
        $params = ['id' => $teamId];

        if (isset($data['team_name'])) {
            $fields[] = 'team_name = :name';
            $params['name'] = $data['team_name'];
        }

        if (isset($data['description'])) {
            $fields[] = 'description = :desc';
            $params['desc'] = $data['description'];
        }

        if (isset($data['team_desc'])) {
            $fields[] = 'team_desc = :team_desc';
            $params['team_desc'] = $data['team_desc'];
        }

        if (isset($data['team_planet_transfers'])) {
            $fields[] = 'team_planet_transfers = :transfers';
            $params['transfers'] = (bool)$data['team_planet_transfers'];
        }

        if (isset($data['team_credits'])) {
            $fields[] = 'team_credits = :credits';
            $params['credits'] = (int)$data['team_credits'];
        }

        if (empty($fields)) {
            return false;
        }

        $sql = 'UPDATE teams SET ' . implode(', ', $fields) . ' WHERE team_id = :id';
        return $this->db->execute($sql, $params);
    }

    /**
     * Delete a team
     */
    public function delete(int $teamId): bool
    {
        // Remove all members from team
        $this->db->execute(
            'UPDATE ships SET team = 0 WHERE team = :team',
            ['team' => $teamId]
        );

        // Delete team invitations
        $this->db->execute(
            'DELETE FROM team_invitations WHERE team_id = :team',
            ['team' => $teamId]
        );

        // Delete team messages
        $this->db->execute(
            'DELETE FROM team_messages WHERE team_id = :team',
            ['team' => $teamId]
        );

        // Delete team
        return $this->db->execute(
            'DELETE FROM teams WHERE team_id = :id',
            ['id' => $teamId]
        );
    }

    /**
     * Get team members
     */
    public function getMembers(int $teamId): array
    {
        return $this->db->fetchAll(
            'SELECT ship_id, character_name, credits, ship_fighters,
                    turns, sector, last_login, email
             FROM ships
             WHERE team = :team
             ORDER BY character_name',
            ['team' => $teamId]
        );
    }

    /**
     * Add member to team
     */
    public function addMember(int $shipId, int $teamId): bool
    {
        return $this->db->execute(
            'UPDATE ships SET team = :team WHERE ship_id = :ship',
            ['team' => $teamId, 'ship' => $shipId]
        );
    }

    /**
     * Remove member from team
     */
    public function removeMember(int $shipId): bool
    {
        return $this->db->execute(
            'UPDATE ships SET team = 0 WHERE ship_id = :ship',
            ['ship' => $shipId]
        );
    }

    /**
     * Check if player is team founder
     */
    public function isFounder(int $teamId, int $shipId): bool
    {
        $team = $this->find($teamId);
        return $team && $team['founder_id'] == $shipId;
    }

    /**
     * Transfer team credits
     */
    public function addCredits(int $teamId, int $amount): bool
    {
        return $this->db->execute(
            'UPDATE teams SET team_credits = team_credits + :amount WHERE team_id = :id',
            ['amount' => $amount, 'id' => $teamId]
        );
    }

    /**
     * Withdraw team credits
     */
    public function withdrawCredits(int $teamId, int $amount): bool
    {
        return $this->db->execute(
            'UPDATE teams SET team_credits = team_credits - :amount
             WHERE team_id = :id AND team_credits >= :amount',
            ['amount' => $amount, 'id' => $teamId]
        );
    }

    /**
     * Create team invitation
     */
    public function createInvitation(int $teamId, int $inviterId, int $inviteeId): bool
    {
        // Check if invitation already exists
        $existing = $this->db->fetchOne(
            'SELECT * FROM team_invitations WHERE team_id = :team AND invitee_id = :invitee',
            ['team' => $teamId, 'invitee' => $inviteeId]
        );

        if ($existing) {
            return false;
        }

        return $this->db->execute(
            'INSERT INTO team_invitations (team_id, inviter_id, invitee_id, created_date)
             VALUES (:team, :inviter, :invitee, NOW())',
            ['team' => $teamId, 'inviter' => $inviterId, 'invitee' => $inviteeId]
        );
    }

    /**
     * Get invitations for a player
     */
    public function getInvitationsForPlayer(int $shipId): array
    {
        return $this->db->fetchAll(
            'SELECT ti.*, t.team_name, s.character_name as inviter_name
             FROM team_invitations ti
             JOIN teams t ON ti.team_id = t.team_id
             JOIN ships s ON ti.inviter_id = s.ship_id
             WHERE ti.invitee_id = :ship
             ORDER BY ti.created_date DESC',
            ['ship' => $shipId]
        );
    }

    /**
     * Get pending invitations for a team
     */
    public function getInvitationsForTeam(int $teamId): array
    {
        return $this->db->fetchAll(
            'SELECT ti.*, s.character_name as invitee_name
             FROM team_invitations ti
             JOIN ships s ON ti.invitee_id = s.ship_id
             WHERE ti.team_id = :team
             ORDER BY ti.created_date DESC',
            ['team' => $teamId]
        );
    }

    /**
     * Accept team invitation
     */
    public function acceptInvitation(int $invitationId, int $shipId): bool
    {
        // Get invitation
        $invitation = $this->db->fetchOne(
            'SELECT * FROM team_invitations WHERE invitation_id = :id AND invitee_id = :ship',
            ['id' => $invitationId, 'ship' => $shipId]
        );

        if (!$invitation) {
            return false;
        }

        // Add to team
        $this->addMember($shipId, (int)$invitation['team_id']);

        // Delete invitation
        $this->db->execute(
            'DELETE FROM team_invitations WHERE invitation_id = :id',
            ['id' => $invitationId]
        );

        return true;
    }

    /**
     * Decline team invitation
     */
    public function declineInvitation(int $invitationId, int $shipId): bool
    {
        return $this->db->execute(
            'DELETE FROM team_invitations WHERE invitation_id = :id AND invitee_id = :ship',
            ['id' => $invitationId, 'ship' => $shipId]
        );
    }

    /**
     * Post message to team
     */
    public function postMessage(int $teamId, int $shipId, string $message): bool
    {
        return $this->db->execute(
            'INSERT INTO team_messages (team_id, ship_id, message, created_date)
             VALUES (:team, :ship, :message, NOW())',
            ['team' => $teamId, 'ship' => $shipId, 'message' => $message]
        );
    }

    /**
     * Get team messages
     */
    public function getMessages(int $teamId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT tm.*, s.character_name
             FROM team_messages tm
             JOIN ships s ON tm.ship_id = s.ship_id
             WHERE tm.team_id = :team
             ORDER BY tm.created_date DESC
             LIMIT :limit',
            ['team' => $teamId, 'limit' => $limit]
        );
    }

    /**
     * Get team statistics
     */
    public function getStatistics(int $teamId): array
    {
        $stats = $this->db->fetchOne(
            'SELECT
                COUNT(*) as member_count,
                SUM(credits) as total_credits,
                SUM(ship_fighters) as total_fighters,
                SUM(turns) as total_turns,
                AVG(turns) as avg_turns
             FROM ships
             WHERE team = :team',
            ['team' => $teamId]
        );

        // Get planet count
        $planetCount = $this->db->fetchOne(
            'SELECT COUNT(*) as planet_count
             FROM planets p
             JOIN ships s ON p.owner = s.ship_id
             WHERE s.team = :team',
            ['team' => $teamId]
        );

        $stats['planet_count'] = $planetCount['planet_count'] ?? 0;

        return $stats;
    }
}
