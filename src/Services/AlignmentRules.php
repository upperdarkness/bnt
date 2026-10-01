<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Pure alignment logic: tiers, deltas, drift and the FedSpace combat-rule table.
 * No database access, so every boundary can be unit tested.
 */
class AlignmentRules
{
    public const PARAGON = 'paragon';
    public const LAWFUL = 'lawful';
    public const NEUTRAL = 'neutral';
    public const OUTLAW = 'outlaw';
    public const PIRATE = 'pirate';

    public const TIER_LABELS = [
        self::PARAGON => 'Paragon',
        self::LAWFUL => 'Lawful',
        self::NEUTRAL => 'Neutral',
        self::OUTLAW => 'Outlaw',
        self::PIRATE => 'Pirate',
    ];

    /** Name colour per tier (gold / green / plain / orange / red). */
    public const TIER_COLORS = [
        self::PARAGON => '#f1c40f',
        self::LAWFUL => '#2ecc71',
        self::NEUTRAL => 'inherit',
        self::OUTLAW => '#e67e22',
        self::PIRATE => '#e74c3c',
    ];

    /** Shown when a hostile action is blocked; deliberately the same as the starbase block. */
    public const SAFE_ZONE_MESSAGE = 'Combat is not allowed in starbase sectors';

    private array $tiers;
    private array $deltas;
    private int $min;
    private int $max;

    public function __construct(private array $config)
    {
        $a = $config['alignment'] ?? [];
        $this->tiers = $a['tiers'] ?? ['paragon' => 1000, 'lawful' => 100, 'neutral' => -99, 'outlaw' => -999];
        $this->deltas = $a['deltas'] ?? [];
        $this->min = (int)($a['min'] ?? -10000);
        $this->max = (int)($a['max'] ?? 10000);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config['alignment'][$key] ?? $default;
    }

    public function clamp(int $value): int
    {
        return max($this->min, min($this->max, $value));
    }

    public function tier(int $alignment): string
    {
        return match (true) {
            $alignment >= $this->tiers['paragon'] => self::PARAGON,
            $alignment >= $this->tiers['lawful'] => self::LAWFUL,
            $alignment >= $this->tiers['neutral'] => self::NEUTRAL,
            $alignment >= $this->tiers['outlaw'] => self::OUTLAW,
            default => self::PIRATE,
        };
    }

    public function label(int $alignment): string
    {
        return self::TIER_LABELS[$this->tier($alignment)];
    }

    public function color(int $alignment): string
    {
        return self::TIER_COLORS[$this->tier($alignment)];
    }

    /** Neutral or better: protected in FedSpace, may claim bounties. */
    public function isLawAbiding(int $alignment): bool
    {
        return $alignment >= $this->tiers['neutral'];
    }

    /** Outlaw or Pirate: legitimate targets, no FedSpace protection. */
    public function isOutlaw(int $alignment): bool
    {
        return !$this->isLawAbiding($alignment);
    }

    public function isPirate(int $alignment): bool
    {
        return $this->tier($alignment) === self::PIRATE;
    }

    public function delta(string $key): int
    {
        return (int)($this->deltas[$key] ?? 0);
    }

    /**
     * Daily drift: 1% toward zero (config), minimum step 1, never overshooting zero.
     */
    public function drift(int $alignment): int
    {
        if ($alignment === 0) {
            return 0;
        }
        $pct = (float)$this->config('drift_pct_per_day', 1);
        $step = max(1, (int)round(abs($alignment) * $pct / 100));
        $step = min($step, abs($alignment));
        return $alignment > 0 ? $alignment - $step : $alignment + $step;
    }

    /**
     * Alignment changes for an attack on another ship.
     * Each element is [delta, reason]. Team-mates produce nothing.
     *
     * @param array $victim  ['alignment' => int, 'is_npc' => bool, 'faction' => ?string, 'team' => int]
     */
    public function attackDeltas(array $attacker, array $victim, bool $destroyed, bool $inFedSpace): array
    {
        if ((int)($attacker['team'] ?? 0) !== 0 && (int)($attacker['team'] ?? 0) === (int)($victim['team'] ?? 0)) {
            return [];
        }
        $out = [];
        $victimAlign = (int)$victim['alignment'];
        $faction = $victim['faction'] ?? null;
        $victimIsPirateNpc = !empty($victim['is_npc']) && $faction === 'xenobe';
        $victimIsTraderNpc = !empty($victim['is_npc']) && in_array($faction, ['police', 'guild', 'free'], true)
            && $this->isLawAbiding($victimAlign);

        if ($this->isLawAbiding($victimAlign)) {
            $out[] = [$this->delta('attack_lawful'), 'attacked_lawful'];
            if ($destroyed) {
                $out[] = $victimIsTraderNpc
                    ? [$this->delta('destroy_trader_npc'), 'destroyed_trader_npc']
                    : [$this->delta('destroy_lawful'), 'destroyed_lawful'];
            }
        } else {
            $out[] = [$this->delta('attack_outlaw'), 'attacked_outlaw'];
            if ($destroyed) {
                $out[] = $victimIsPirateNpc
                    ? [$this->delta('destroy_pirate_npc'), 'destroyed_pirate_npc']
                    : [$this->delta('destroy_outlaw'), 'destroyed_outlaw'];
            }
        }
        return array_values(array_filter($out, static fn(array $d): bool => $d[0] !== 0));
    }

    /**
     * Result of a planet capture. Planet owner null/0 gives no change.
     */
    public function captureDelta(?array $owner, array $captor): ?array
    {
        if ($owner === null) {
            return null;
        }
        if ((int)($captor['team'] ?? 0) !== 0 && (int)($captor['team'] ?? 0) === (int)($owner['team'] ?? 0)) {
            return null;
        }
        return $this->isLawAbiding((int)$owner['alignment'])
            ? [$this->delta('capture_lawful_planet'), 'captured_lawful_planet']
            : [$this->delta('capture_outlaw_planet'), 'captured_outlaw_planet'];
    }

    /**
     * FedSpace / starbase combat table.
     *
     * @param array $attacker ['alignment' => int, 'is_police' => bool]
     * @param array|null $target ['alignment' => int, 'wanted' => bool] or null for non-ship targets
     * @return array{allowed: bool, reason: string, fedspace_offence: bool, message: string}
     */
    public function combatDecision(array $attacker, ?array $target, bool $inStarbase, bool $inFedSpace): array
    {
        $deny = fn(string $reason) => ['allowed' => false, 'reason' => $reason, 'fedspace_offence' => false, 'message' => self::SAFE_ZONE_MESSAGE];
        $allow = fn(bool $offence = false) => ['allowed' => true, 'reason' => 'ok', 'fedspace_offence' => $offence, 'message' => ''];

        if ($inStarbase) {
            return $deny('starbase');
        }
        if (!$inFedSpace) {
            return $allow();
        }
        if ($target === null) {
            return $deny('fedspace_unowned');
        }
        if (!empty($attacker['is_police']) && !empty($target['wanted'])) {
            return $allow();
        }
        $attackerLawful = $this->isLawAbiding((int)$attacker['alignment']);
        $targetLawful = $this->isLawAbiding((int)$target['alignment']);
        if ($targetLawful) {
            return $deny('fedspace_protected');
        }
        // Target is an Outlaw or Pirate.
        return $attackerLawful ? $allow(false) : $allow(true);
    }

    /** Defences may only act in FedSpace against outlaws, and only if their owner is law-abiding. */
    public function defenceMayAct(bool $inFedSpace, int $ownerAlignment, int $victimAlignment): bool
    {
        if (!$inFedSpace) {
            return true;
        }
        return $this->isOutlaw($victimAlignment) && $this->isLawAbiding($ownerAlignment);
    }

    /** Sector defences cannot be deployed in FedSpace below Neutral. */
    public function mayDeployInFedSpace(int $alignment): bool
    {
        return $this->isLawAbiding($alignment);
    }

    /** Fine (credits) to restore a player to the configured floor. */
    public function fineAmount(int $alignment): int
    {
        $below = max(0, -$alignment);
        $fine = $below * (int)$this->config('fine_credits_per_point', 100);
        return max((int)$this->config('fine_minimum', 10000), $fine);
    }

    public function fineTarget(int $alignment): int
    {
        return max($alignment, (int)$this->config('fine_restore_to', -99));
    }

    /** Federation bounty: credits per 100 points below 0. */
    public function federationBounty(int $alignment): int
    {
        $below = max(0, -$alignment);
        return max(1, intdiv($below, 100)) * (int)$this->config('bounty_credits_per_100_points', 1000);
    }

    public function policeCount(int $alignment): int
    {
        $below = max(0, -$alignment);
        $n = intdiv($below, (int)$this->config('police_points_per_unit', 500));
        return max(1, min((int)$this->config('police_max_per_offender', 5), $n));
    }

    /** Multiplier applied to starbase prices (Paragon discount, Outlaw surcharge). */
    public function starbasePriceFactor(int $alignment): float
    {
        return match ($this->tier($alignment)) {
            self::PARAGON => 1.0 - (float)$this->config('starbase_paragon_discount_pct', 5) / 100,
            self::OUTLAW => 1.0 + (float)$this->config('starbase_outlaw_surcharge_pct', 15) / 100,
            default => 1.0,
        };
    }

    public function refusedAtStarbase(int $alignment): bool
    {
        return $this->isPirate($alignment);
    }

    public function bountyPayoutFactor(int $alignment): float
    {
        return $this->tier($alignment) === self::PARAGON ? (float)$this->config('paragon_bounty_multiplier', 1.25) : 1.0;
    }

    /** Escape a player-visible name with the right colour. */
    public function styledName(string $name, int $alignment, bool $wanted = false): string
    {
        $color = $this->color($alignment);
        $html = '<span style="color:' . $color . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
        if ($wanted) {
            $html .= ' <span class="wanted-badge" style="background:#c0392b;color:#fff;border-radius:3px;padding:0 4px;font-size:0.75em">WANTED</span>';
        }
        return $html;
    }
}
