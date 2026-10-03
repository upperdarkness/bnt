<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Pure newbie-protection logic (no database): who is shielded, thresholds, progress and the
 * score-mismatch test. Every enforcement point asks this class so the rules live in one place.
 */
class ProtectionRules
{
    public function __construct(private array $config) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config['protection'][$key] ?? $default;
    }

    public function enabled(): bool
    {
        return (bool)$this->cfg('enabled', true);
    }

    /**
     * Is this ship row currently protected from attack?
     * Needs: protection_state, respawn_shield_until, is_npc (all optional).
     */
    public function isProtected(array $ship, ?int $now = null): bool
    {
        if (!$this->enabled() || !empty($ship['is_npc'])) {
            return false;
        }
        $state = $ship['protection_state'] ?? 'none';
        if ($state === 'protected' || ($state === 'grace' && $this->cfg('grace_protects', false))) {
            return true;
        }
        $until = $ship['respawn_shield_until'] ?? null;
        return $until !== null && $until !== '' && strtotime((string)$until) > ($now ?? time());
    }

    /** Score a ship must reach to leave protection, given the reference score of active players. */
    public function scoreThreshold(?float $referenceScore): int
    {
        $floor = (int)$this->cfg('score_floor', 10000);
        if ($referenceScore === null) {
            return $floor;
        }
        return max($floor, (int)round($referenceScore * (float)$this->cfg('score_fraction', 0.25)));
    }

    /** Linear-interpolated percentile (50 = median) of a list of scores; null when empty. */
    public function percentile(array $scores, float $p): ?float
    {
        if (!$scores) {
            return null;
        }
        sort($scores);
        $rank = ($p / 100) * (count($scores) - 1);
        $lo = (int)floor($rank);
        $hi = (int)ceil($rank);
        return $scores[$lo] + ($scores[$hi] - $scores[$lo]) * ($rank - $lo);
    }

    /** @return 'days'|'score'|null the first exit condition that has been met */
    public function exitReason(array $ship, int $threshold): ?string
    {
        if ((int)($ship['active_days'] ?? 0) >= (int)$this->cfg('active_days', 14)) {
            return 'days';
        }
        if ((int)($ship['score'] ?? 0) >= $threshold) {
            return 'score';
        }
        return null;
    }

    /** Has a ship in the grace state run out its warning period? */
    public function graceOver(array $ship, ?int $now = null): bool
    {
        if (($ship['protection_state'] ?? '') !== 'grace' || empty($ship['protection_ended_at'])) {
            return false;
        }
        return strtotime((string)$ship['protection_ended_at']) + (int)$this->cfg('grace_hours', 12) * 3600 <= ($now ?? time());
    }

    /**
     * Is the target so much weaker than the attacker that alignment penalties double?
     */
    public function isMismatch(int $attackerScore, int $victimScore): bool
    {
        $ratio = (float)($this->config['alignment']['mismatch_ratio'] ?? 0.25);
        return $attackerScore > 0 && $victimScore < $ratio * $attackerScore;
    }

    /** Active-day counter: +1 when the last active date is not today. */
    public function nextActiveDays(int $current, ?string $lastActiveDate, string $today): int
    {
        return $lastActiveDate === $today ? $current : $current + 1;
    }

    /**
     * Progress toward each exit condition for the status page / API.
     * @return array{state: string, protected: bool, active_days: int, active_days_needed: int, score: int, score_needed: int, closest: string, text: string, grace_ends_at: ?string, respawn_shield_until: ?string}
     */
    public function progress(array $ship, int $threshold, ?int $now = null): array
    {
        $needDays = (int)$this->cfg('active_days', 14);
        $days = (int)($ship['active_days'] ?? 0);
        $score = (int)($ship['score'] ?? 0);
        $daysFrac = $needDays > 0 ? $days / $needDays : 1;
        $scoreFrac = $threshold > 0 ? $score / $threshold : 1;
        $state = (string)($ship['protection_state'] ?? 'none');
        $graceEnds = ($state === 'grace' && !empty($ship['protection_ended_at']))
            ? date('c', strtotime((string)$ship['protection_ended_at']) + (int)$this->cfg('grace_hours', 12) * 3600) : null;
        $shield = (!empty($ship['respawn_shield_until']) && strtotime((string)$ship['respawn_shield_until']) > ($now ?? time()))
            ? (string)$ship['respawn_shield_until'] : null;
        $protected = $this->isProtected($ship, $now);
        $text = match (true) {
            $state === 'protected' => sprintf('Protected: %d of %d active days, score %s of %s', $days, $needDays, number_format($score), number_format($threshold)),
            $state === 'grace' => 'Protection ended: you can now be attacked',
            $shield !== null => 'Respawn shield active until ' . date('M j, g:i A', strtotime($shield)),
            default => 'Not protected',
        };
        return [
            'state' => $state, 'protected' => $protected, 'active_days' => $days, 'active_days_needed' => $needDays,
            'score' => $score, 'score_needed' => $threshold, 'closest' => $daysFrac >= $scoreFrac ? 'days' : 'score',
            'text' => $text, 'grace_ends_at' => $graceEnds, 'respawn_shield_until' => $shield,
        ];
    }
}
