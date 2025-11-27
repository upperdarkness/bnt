<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

/**
 * Skill management for character progression
 *
 * Four skill types:
 * - Trading: Improves port prices and reduces fees
 * - Combat: Increases damage dealt in combat
 * - Engineering: Reduces ship upgrade costs
 * - Leadership: Provides team bonuses
 */
class Skill
{
    private const SKILLS = ['trading', 'combat', 'engineering', 'leadership'];
    private const MAX_SKILL_LEVEL = 100;
    private const SKILL_POINT_COST_BASE = 1; // Cost increases with level

    public function __construct(private Database $db) {}

    /**
     * Get all skills for a ship
     */
    public function getSkills(int $shipId): array
    {
        $result = $this->db->query(
            'SELECT skill_trading, skill_combat, skill_engineering, skill_leadership, skill_points
             FROM ships WHERE ship_id = :id',
            ['id' => $shipId]
        );

        if (empty($result)) {
            return [
                'trading' => 0,
                'combat' => 0,
                'engineering' => 0,
                'leadership' => 0,
                'points' => 0
            ];
        }

        return [
            'trading' => (int)$result[0]['skill_trading'],
            'combat' => (int)$result[0]['skill_combat'],
            'engineering' => (int)$result[0]['skill_engineering'],
            'leadership' => (int)$result[0]['skill_leadership'],
            'points' => (int)$result[0]['skill_points']
        ];
    }

    /**
     * Allocate skill points to a specific skill
     */
    public function allocateSkillPoints(int $shipId, string $skillType, int $points): array
    {
        if (!in_array($skillType, self::SKILLS)) {
            return ['success' => false, 'message' => 'Invalid skill type'];
        }

        if ($points <= 0) {
            return ['success' => false, 'message' => 'Must allocate at least 1 point'];
        }

        $skills = $this->getSkills($shipId);
        $currentLevel = $skills[$skillType];
        $availablePoints = $skills['points'];

        // Calculate cost for this upgrade
        $cost = $this->calculateSkillCost($currentLevel, $points);

        if ($availablePoints < $cost) {
            return [
                'success' => false,
                'message' => "Not enough skill points. Need $cost, have $availablePoints"
            ];
        }

        $newLevel = $currentLevel + $points;
        if ($newLevel > self::MAX_SKILL_LEVEL) {
            return [
                'success' => false,
                'message' => 'Cannot exceed maximum skill level of ' . self::MAX_SKILL_LEVEL
            ];
        }

        // Update skill and deduct points
        $column = 'skill_' . $skillType;
        $this->db->execute(
            "UPDATE ships
             SET $column = :level, skill_points = skill_points - :cost
             WHERE ship_id = :id",
            ['level' => $newLevel, 'cost' => $cost, 'id' => $shipId]
        );

        return [
            'success' => true,
            'message' => "Increased " . ucfirst($skillType) . " skill to level $newLevel",
            'new_level' => $newLevel,
            'cost' => $cost
        ];
    }

    /**
     * Award skill points to a player
     */
    public function awardSkillPoints(int $shipId, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        $this->db->execute(
            'UPDATE ships SET skill_points = skill_points + :points WHERE ship_id = :id',
            ['points' => $points, 'id' => $shipId]
        );
    }

    /**
     * Calculate cost to upgrade a skill by X points
     * Cost increases with current level (higher levels cost more)
     */
    private function calculateSkillCost(int $currentLevel, int $points): int
    {
        $totalCost = 0;
        for ($i = 0; $i < $points; $i++) {
            $level = $currentLevel + $i;
            // Cost formula: 1 point per level + 1 extra per 10 levels
            $totalCost += self::SKILL_POINT_COST_BASE + (int)floor($level / 10);
        }
        return $totalCost;
    }

    /**
     * Get trading bonus percentage (0-50%)
     * Reduces buy prices and increases sell prices
     */
    public function getTradingBonus(int $skillLevel): float
    {
        return min(50.0, $skillLevel * 0.5); // 0.5% per level, max 50%
    }

    /**
     * Get combat damage multiplier (1.0 to 2.0)
     */
    public function getCombatMultiplier(int $skillLevel): float
    {
        return 1.0 + min(1.0, $skillLevel * 0.01); // 1% per level, max 100% bonus
    }

    /**
     * Get engineering cost reduction percentage (0-40%)
     */
    public function getEngineeringDiscount(int $skillLevel): float
    {
        return min(40.0, $skillLevel * 0.4); // 0.4% per level, max 40%
    }

    /**
     * Get leadership team bonus percentage (0-25%)
     * Applied to team members in same sector
     */
    public function getLeadershipBonus(int $skillLevel): float
    {
        return min(25.0, $skillLevel * 0.25); // 0.25% per level, max 25%
    }

    /**
     * Get skill description and current bonus
     */
    public function getSkillInfo(string $skillType, int $level): array
    {
        $info = [
            'trading' => [
                'name' => 'Trading',
                'description' => 'Improves port trading prices and reduces transaction fees',
                'bonus' => $this->getTradingBonus($level),
                'bonus_text' => number_format($this->getTradingBonus($level), 1) . '% price improvement'
            ],
            'combat' => [
                'name' => 'Combat',
                'description' => 'Increases damage dealt to ships and planets',
                'bonus' => $this->getCombatMultiplier($level),
                'bonus_text' => number_format(($this->getCombatMultiplier($level) - 1.0) * 100, 0) . '% damage bonus'
            ],
            'engineering' => [
                'name' => 'Engineering',
                'description' => 'Reduces ship upgrade and repair costs',
                'bonus' => $this->getEngineeringDiscount($level),
                'bonus_text' => number_format($this->getEngineeringDiscount($level), 1) . '% cost reduction'
            ],
            'leadership' => [
                'name' => 'Leadership',
                'description' => 'Provides combat and trading bonuses to team members in same sector',
                'bonus' => $this->getLeadershipBonus($level),
                'bonus_text' => number_format($this->getLeadershipBonus($level), 1) . '% team bonus'
            ]
        ];

        return $info[$skillType] ?? [];
    }
}
