<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Models\Combat;
use BNT\Models\ShipType;

/**
 * Single-number strength estimate built from the same formulas the combat
 * system uses (beam/torpedo/fighter damage, shield/armour strength, ship-type
 * multipliers). Scripted and LLM NPCs both reason about fights through this.
 */
class CombatRating
{
    public function __construct(private Combat $combat) {}

    public function rate(array $ship): int
    {
        $type = (string)($ship['ship_type'] ?? 'balanced');
        $offence = $this->combat->calculateBeamDamage((int)$ship['beams'])
            + $this->combat->calculateTorpedoDamage(min((int)$ship['torps'], 10))
            + $this->combat->calculateFighterDamage(min((int)$ship['ship_fighters'], 100));
        $offence *= ShipType::getCombatMultiplier($type);

        $defence = $this->combat->calculateShieldStrength((int)$ship['shields'])
            + $this->combat->calculateArmorStrength((int)$ship['armor'], (int)$ship['armor_pts']);
        $defence *= ShipType::getDefenseMultiplier($type);

        return (int)round(sqrt(max(0.0, $offence * $defence)));
    }

    /** Coarse label used in LLM observations ("rating~low"). */
    public function bucket(int $rating, int $myRating): string
    {
        if ($myRating <= 0) {
            return 'unknown';
        }
        $ratio = $rating / $myRating;
        return match (true) {
            $ratio < 0.7 => 'low',
            $ratio <= 1.3 => 'similar',
            default => 'high',
        };
    }

    /** Hull as a percentage of the armour cap, 0-100. */
    public function hullPercent(array $ship): int
    {
        $max = (int)round(pow(1.5, (int)$ship['armor']) * 100);
        return $max > 0 ? (int)max(0, min(100, round(100 * (int)$ship['armor_pts'] / $max))) : 0;
    }
}
