<?php

declare(strict_types=1);

namespace BNT\Models;

/**
 * Ship Type definitions and bonuses
 *
 * Four ship classes with different strengths:
 * - Scout: Fast, cheap turns, but weak and small cargo
 * - Merchant: Large cargo, but slow and weak in combat
 * - Warship: Superior combat, but expensive and small cargo
 * - Balanced: Average in all aspects (default)
 */
class ShipType
{
    public const SCOUT = 'scout';
    public const MERCHANT = 'merchant';
    public const WARSHIP = 'warship';
    public const BALANCED = 'balanced';

    public const TYPES = [
        self::SCOUT,
        self::MERCHANT,
        self::WARSHIP,
        self::BALANCED
    ];

    /**
     * Get ship type information
     */
    public static function getInfo(string $type): array
    {
        $types = [
            self::SCOUT => [
                'name' => 'Scout',
                'description' => 'Fast and efficient. Excels at exploration and evasion.',
                'cargo_multiplier' => 0.7,      // 70% cargo capacity
                'turn_cost_multiplier' => 0.5,  // 50% turn costs (moves cost half as much)
                'combat_multiplier' => 0.8,     // 80% combat damage
                'defense_multiplier' => 0.7,    // 70% armor/shields
                'speed_bonus' => 1.5,           // 50% faster movement
                'icon' => '🚀',
                'color' => '#3498db'
            ],
            self::MERCHANT => [
                'name' => 'Merchant',
                'description' => 'Massive cargo holds. Built for trading and profit.',
                'cargo_multiplier' => 2.0,      // 200% cargo capacity
                'turn_cost_multiplier' => 1.2,  // 120% turn costs (moves cost more)
                'combat_multiplier' => 0.6,     // 60% combat damage
                'defense_multiplier' => 0.8,    // 80% armor/shields
                'speed_bonus' => 0.8,           // 20% slower movement
                'icon' => '🚢',
                'color' => '#2ecc71'
            ],
            self::WARSHIP => [
                'name' => 'Warship',
                'description' => 'Built for combat. Dominates in battle.',
                'cargo_multiplier' => 0.6,      // 60% cargo capacity
                'turn_cost_multiplier' => 1.5,  // 150% turn costs (expensive to move)
                'combat_multiplier' => 1.5,     // 150% combat damage
                'defense_multiplier' => 1.4,    // 140% armor/shields
                'speed_bonus' => 0.9,           // 10% slower movement
                'icon' => '⚔️',
                'color' => '#e74c3c'
            ],
            self::BALANCED => [
                'name' => 'Balanced',
                'description' => 'Well-rounded ship. Good at everything, master of nothing.',
                'cargo_multiplier' => 1.0,      // 100% cargo capacity
                'turn_cost_multiplier' => 1.0,  // 100% turn costs (normal)
                'combat_multiplier' => 1.0,     // 100% combat damage
                'defense_multiplier' => 1.0,    // 100% armor/shields
                'speed_bonus' => 1.0,           // Normal movement speed
                'icon' => '🛸',
                'color' => '#95a5a6'
            ]
        ];

        return $types[$type] ?? $types[self::BALANCED];
    }

    /**
     * Get all ship types with their info
     */
    public static function getAllTypes(): array
    {
        $result = [];
        foreach (self::TYPES as $type) {
            $result[$type] = self::getInfo($type);
        }
        return $result;
    }

    /**
     * Validate ship type
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::TYPES);
    }

    /**
     * Get cargo capacity for ship type
     */
    public static function getCargoCapacity(string $type, int $baseCapacity): int
    {
        $info = self::getInfo($type);
        return (int)($baseCapacity * $info['cargo_multiplier']);
    }

    /**
     * Get turn cost for movement (applies to sector movement)
     */
    public static function getTurnCost(string $type, int $baseCost = 1): int
    {
        $info = self::getInfo($type);
        return max(1, (int)($baseCost * $info['turn_cost_multiplier']));
    }

    /**
     * Get combat damage multiplier
     */
    public static function getCombatMultiplier(string $type): float
    {
        $info = self::getInfo($type);
        return $info['combat_multiplier'];
    }

    /**
     * Get defense multiplier (for armor/shields)
     */
    public static function getDefenseMultiplier(string $type): float
    {
        $info = self::getInfo($type);
        return $info['defense_multiplier'];
    }

    /**
     * Get speed bonus (affects escape chance, initiative, etc.)
     */
    public static function getSpeedBonus(string $type): float
    {
        $info = self::getInfo($type);
        return $info['speed_bonus'];
    }

    /**
     * Get starting bonuses for new ships
     */
    public static function getStartingBonuses(string $type): array
    {
        $bonuses = [
            self::SCOUT => [
                'credits' => 2000,      // Less starting money
                'turns' => 200,         // More starting turns
                'ship_ore' => 5,
                'ship_organics' => 5,
                'ship_goods' => 5,
                'ship_energy' => 50
            ],
            self::MERCHANT => [
                'credits' => 5000,      // More starting money
                'turns' => 100,         // Fewer starting turns
                'ship_ore' => 20,
                'ship_organics' => 20,
                'ship_goods' => 20,
                'ship_energy' => 100
            ],
            self::WARSHIP => [
                'credits' => 1000,      // Least starting money
                'turns' => 150,
                'ship_ore' => 0,
                'ship_organics' => 0,
                'ship_goods' => 0,
                'ship_energy' => 100,
                'torps' => 10,          // Start with torpedoes
                'ship_fighters' => 5    // Start with fighters
            ],
            self::BALANCED => [
                'credits' => 3000,
                'turns' => 150,
                'ship_ore' => 10,
                'ship_organics' => 10,
                'ship_goods' => 10,
                'ship_energy' => 75
            ]
        ];

        return $bonuses[$type] ?? $bonuses[self::BALANCED];
    }
}
