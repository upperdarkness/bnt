<?php
declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class Upgrade
{
    // Upgradeable ship components
    private const COMPONENTS = [
        'hull' => [
            'name' => 'Hull',
            'description' => 'Increases ship cargo capacity and hull strength',
            'icon' => '🛡️'
        ],
        'engines' => [
            'name' => 'Engines',
            'description' => 'Improves ship speed and maneuverability',
            'icon' => '⚡'
        ],
        'power' => [
            'name' => 'Power Plant',
            'description' => 'Increases energy generation capacity',
            'icon' => '🔋'
        ],
        'computer' => [
            'name' => 'Computer',
            'description' => 'Enhances targeting and navigation systems',
            'icon' => '💻'
        ],
        'sensors' => [
            'name' => 'Sensors',
            'description' => 'Improves long-range scanning capabilities',
            'icon' => '📡'
        ],
        'beams' => [
            'name' => 'Beam Weapons',
            'description' => 'Increases beam weapon effectiveness',
            'icon' => '⚔️'
        ],
        'torp_launchers' => [
            'name' => 'Torpedo Launchers',
            'description' => 'Enhances torpedo firing capabilities',
            'icon' => '🚀'
        ],
        'shields' => [
            'name' => 'Shields',
            'description' => 'Provides better protection from attacks',
            'icon' => '🛡️'
        ],
        'armor' => [
            'name' => 'Armor',
            'description' => 'Increases hull armor and damage resistance',
            'icon' => '🔰'
        ],
        'cloak' => [
            'name' => 'Cloaking Device',
            'description' => 'Improves stealth and detection avoidance',
            'icon' => '👻'
        ]
    ];

    public function __construct(private Database $db) {}

    /**
     * Get all available components for upgrading
     *
     * @return array List of component information
     */
    public static function getComponents(): array
    {
        return self::COMPONENTS;
    }

    /**
     * Get component info by key
     *
     * @param string $component Component key
     * @return array|null Component info or null if not found
     */
    public static function getComponentInfo(string $component): ?array
    {
        return self::COMPONENTS[$component] ?? null;
    }

    /**
     * Check if a component is valid
     *
     * @param string $component Component key
     * @return bool True if valid
     */
    public static function isValidComponent(string $component): bool
    {
        return isset(self::COMPONENTS[$component]);
    }

    /**
     * Calculate upgrade cost for a component
     * Formula: base_cost * (level_factor ^ current_level)
     *
     * @param int $currentLevel Current level of the component
     * @param array $config Game configuration
     * @return int Upgrade cost in credits
     */
    public static function calculateUpgradeCost(int $currentLevel, array $config): int
    {
        $baseCost = $config['upgrade_cost'] ?? 1000;
        $levelFactor = $config['level_factor'] ?? 1.5;

        // Cost increases exponentially with level
        $cost = (int)round($baseCost * pow($levelFactor, $currentLevel));

        return $cost;
    }

    /**
     * Calculate total ship level (sum of all component levels)
     *
     * @param array $ship Ship data
     * @return int Total ship level
     */
    public static function calculateShipLevel(array $ship): int
    {
        $level = 0;
        foreach (array_keys(self::COMPONENTS) as $component) {
            $level += (int)($ship[$component] ?? 0);
        }
        return $level;
    }

    /**
     * Get upgrade information for all components
     *
     * @param array $ship Ship data
     * @param array $config Game configuration
     * @return array Array of components with current level, cost, and info
     */
    public function getUpgradeInfo(array $ship, array $config): array
    {
        $upgradeInfo = [];

        foreach (self::COMPONENTS as $key => $info) {
            $currentLevel = (int)($ship[$key] ?? 0);
            $upgradeCost = self::calculateUpgradeCost($currentLevel, $config);

            $upgradeInfo[$key] = [
                'key' => $key,
                'name' => $info['name'],
                'description' => $info['description'],
                'icon' => $info['icon'],
                'current_level' => $currentLevel,
                'next_level' => $currentLevel + 1,
                'upgrade_cost' => $upgradeCost,
                'can_afford' => $ship['credits'] >= $upgradeCost
            ];
        }

        return $upgradeInfo;
    }

    /**
     * Upgrade a ship component
     *
     * @param int $shipId Ship ID
     * @param string $component Component to upgrade
     * @param array $config Game configuration
     * @return array Result with success/error message
     */
    public function upgradeComponent(int $shipId, string $component, array $config, float $discount = 0.0): array
    {
        // Validate component
        if (!self::isValidComponent($component)) {
            return ['success' => false, 'error' => 'Invalid component'];
        }

        // Get current ship state
        $ship = $this->db->fetch(
            "SELECT ship_id, credits, $component FROM ships WHERE ship_id = :ship_id AND ship_destroyed = FALSE",
            ['ship_id' => $shipId]
        );

        if (!$ship) {
            return ['success' => false, 'error' => 'Ship not found'];
        }

        $currentLevel = (int)$ship[$component];
        $upgradeCost = self::calculateUpgradeCost($currentLevel, $config);

        // Apply engineering skill discount
        if ($discount > 0) {
            $upgradeCost = (int)($upgradeCost * (1.0 - $discount / 100));
            $upgradeCost = max(1, $upgradeCost); // Minimum 1 credit
        }

        // Check if player can afford it
        if ($ship['credits'] < $upgradeCost) {
            return [
                'success' => false,
                'error' => 'Insufficient credits',
                'cost' => $upgradeCost,
                'credits' => $ship['credits']
            ];
        }

        // Perform upgrade
        $newLevel = $currentLevel + 1;
        $newCredits = $ship['credits'] - $upgradeCost;

        $this->db->execute(
            "UPDATE ships SET $component = :new_level, credits = :new_credits WHERE ship_id = :ship_id",
            [
                'new_level' => $newLevel,
                'new_credits' => $newCredits,
                'ship_id' => $shipId
            ]
        );

        $componentInfo = self::getComponentInfo($component);

        return [
            'success' => true,
            'component' => $componentInfo['name'],
            'old_level' => $currentLevel,
            'new_level' => $newLevel,
            'cost' => $upgradeCost,
            'remaining_credits' => $newCredits
        ];
    }

    /**
     * Downgrade a ship component (refund half the upgrade cost)
     *
     * @param int $shipId Ship ID
     * @param string $component Component to downgrade
     * @param array $config Game configuration
     * @return array Result with success/error message
     */
    public function downgradeComponent(int $shipId, string $component, array $config): array
    {
        // Validate component
        if (!self::isValidComponent($component)) {
            return ['success' => false, 'error' => 'Invalid component'];
        }

        // Get current ship state
        $ship = $this->db->fetch(
            "SELECT ship_id, credits, $component FROM ships WHERE ship_id = :ship_id AND ship_destroyed = FALSE",
            ['ship_id' => $shipId]
        );

        if (!$ship) {
            return ['success' => false, 'error' => 'Ship not found'];
        }

        $currentLevel = (int)$ship[$component];

        // Can't downgrade below level 0
        if ($currentLevel <= 0) {
            return ['success' => false, 'error' => 'Component already at minimum level'];
        }

        // Calculate refund (half of what it cost to upgrade to this level)
        $previousLevelCost = self::calculateUpgradeCost($currentLevel - 1, $config);
        $refund = (int)round($previousLevelCost / 2);

        // Perform downgrade
        $newLevel = $currentLevel - 1;
        $newCredits = $ship['credits'] + $refund;

        $this->db->execute(
            "UPDATE ships SET $component = :new_level, credits = :new_credits WHERE ship_id = :ship_id",
            [
                'new_level' => $newLevel,
                'new_credits' => $newCredits,
                'ship_id' => $shipId
            ]
        );

        $componentInfo = self::getComponentInfo($component);

        return [
            'success' => true,
            'component' => $componentInfo['name'],
            'old_level' => $currentLevel,
            'new_level' => $newLevel,
            'refund' => $refund,
            'remaining_credits' => $newCredits
        ];
    }
}
