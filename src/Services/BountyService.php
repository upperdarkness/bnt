<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Federation and player bounties. The only bounty payout path in the game
 * (Combat::collectBounty delegates here).
 */
class BountyService
{
    public function __construct(private Database $db, private AlignmentRules $rules) {}

    /** Create or refresh the single open Federation bounty on a Wanted ship. */
    public function setFederationBounty(int $targetId, int $alignment): int
    {
        $amount = $this->rules->federationBounty($alignment);
        $this->clearFederationBounty($targetId);
        $this->db->execute(
            'INSERT INTO bounties (target_id, placed_by, amount) VALUES (:t, NULL, :a)',
            ['t' => $targetId, 'a' => $amount]
        );
        return $amount;
    }

    public function clearFederationBounty(int $targetId): void
    {
        $this->db->execute(
            'DELETE FROM bounties WHERE target_id = :t AND placed_by IS NULL AND claimed_by IS NULL',
            ['t' => $targetId]
        );
    }

    /**
     * Fund a player bounty from the placer's IGB balance. Non-refundable.
     * @return array{success: bool, error?: string, amount?: int}
     */
    public function place(int $placerId, int $targetId, int $amount): array
    {
        $min = (int)$this->rules->config('player_bounty_minimum', 10000);
        if ($placerId === $targetId) {
            return ['success' => false, 'error' => 'You cannot place a bounty on yourself'];
        }
        if ($amount < $min) {
            return ['success' => false, 'error' => 'Minimum bounty is ' . number_format($min) . ' credits'];
        }
        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $placer = $this->db->fetchOne('SELECT is_npc FROM ships WHERE ship_id = :id', ['id' => $placerId]);
            if (!$placer || $placer['is_npc']) {
                throw new \DomainException('This account cannot place bounties');
            }
            $target = $this->db->fetchOne(
                'SELECT ship_id FROM ships WHERE ship_id = :id AND ship_destroyed = FALSE',
                ['id' => $targetId]
            );
            if (!$target) {
                throw new \DomainException('Target not found');
            }
            $account = $this->db->fetchOne(
                'SELECT balance FROM ibank_accounts WHERE ship_id = :id FOR UPDATE',
                ['id' => $placerId]
            );
            if (!$account || (int)$account['balance'] < $amount) {
                throw new \DomainException('Insufficient IGB balance');
            }
            $this->db->execute(
                'UPDATE ibank_accounts SET balance = balance - :a WHERE ship_id = :id',
                ['a' => $amount, 'id' => $placerId]
            );
            $this->db->execute(
                'INSERT INTO bounties (target_id, placed_by, amount) VALUES (:t, :p, :a)',
                ['t' => $targetId, 'p' => $placerId, 'a' => $amount]
            );
            if ($own) {
                $pdo->commit();
            }
            return ['success' => true, 'amount' => $amount];
        } catch (\DomainException $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function openTotal(int $targetId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM bounties WHERE target_id = :t AND claimed_by IS NULL',
            ['t' => $targetId]
        );
        return (int)$row['total'];
    }

    /**
     * Pay out all open bounties on a destroyed ship to its killer.
     * Only Neutral-or-better killers can claim, never on a team-mate.
     * Returns the credits paid (0 if ineligible; bounties stay open).
     */
    public function claim(int $killerId, int $targetId): int
    {
        $killer = $this->db->fetchOne('SELECT ship_id, alignment, team FROM ships WHERE ship_id = :id', ['id' => $killerId]);
        $target = $this->db->fetchOne('SELECT ship_id, team FROM ships WHERE ship_id = :id', ['id' => $targetId]);
        if (!$killer || !$target || $killerId === $targetId) {
            return 0;
        }
        if ((int)$killer['team'] !== 0 && (int)$killer['team'] === (int)$target['team']) {
            return 0;
        }
        if (!$this->rules->isLawAbiding((int)$killer['alignment'])) {
            return 0;
        }
        $total = $this->openTotal($targetId);
        if ($total <= 0) {
            return 0;
        }
        $paid = (int)floor($total * $this->rules->bountyPayoutFactor((int)$killer['alignment']));
        $this->db->execute(
            'UPDATE bounties SET claimed_by = :k, claimed_at = now() WHERE target_id = :t AND claimed_by IS NULL',
            ['k' => $killerId, 't' => $targetId]
        );
        $this->db->execute('UPDATE ships SET credits = credits + :a WHERE ship_id = :id', ['a' => $paid, 'id' => $killerId]);
        return $paid;
    }

    /** Open bounties for display. */
    public function openOn(int $targetId): array
    {
        return $this->db->fetchAll(
            'SELECT b.id, b.amount, b.placed_by, b.created_at, s.character_name AS placed_by_name
             FROM bounties b LEFT JOIN ships s ON s.ship_id = b.placed_by
             WHERE b.target_id = :t AND b.claimed_by IS NULL ORDER BY b.amount DESC',
            ['t' => $targetId]
        );
    }
}
