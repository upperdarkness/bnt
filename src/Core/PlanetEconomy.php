<?php

declare(strict_types=1);

namespace BNT\Core;

/** One deterministic economy cycle, shared by production and the player forecast. */
class PlanetEconomy
{
    public const DEFAULTS = [
        'production_rate' => 0.01,
        'growth_rate' => 0.0005,
        'food_per_colonist' => 0.001,
        'starvation_rate' => 0.01,
        'tax_per_colonist' => 0.001,
        'interest_rate' => 0.0005,
        'population_limit' => 100000000,
        'unbased_credit_limit' => 10000000,
        'based_credit_limit' => 100000000000,
    ];

    private array $settings;

    public function __construct(array $settings = [])
    {
        $this->settings = array_replace(self::DEFAULTS, $settings);
        foreach (self::DEFAULTS as $key => $default) {
            $value = $this->settings[$key];
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 1000000000000) {
                throw new \InvalidArgumentException("Invalid planet economy setting: $key");
            }
        }
        foreach (['production_rate', 'growth_rate', 'food_per_colonist', 'starvation_rate', 'tax_per_colonist', 'interest_rate'] as $key) {
            if ($this->settings[$key] > 1) {
                throw new \InvalidArgumentException("Planet economy rate must be between 0 and 1: $key");
            }
        }
    }

    public function cycle(array $planet): array
    {
        $next = $planet;
        $population = max(0, (int)$planet['colonists']);
        $limit = (int)$this->settings[!empty($planet['base']) ? 'based_credit_limit' : 'unbased_credit_limit'];
        $report = ['food_required' => 0, 'starving' => false, 'tax' => 0, 'interest' => 0, 'credit_limit' => $limit];
        if (empty($planet['owner']) || $population === 0) {
            return ['planet' => $next, 'report' => $report];
        }

        // Preserve existing production allocations and unit costs.
        $production = floor($population * $this->settings['production_rate']);
        foreach (['ore' => [1, 100000000], 'organics' => [1, 100000000],
                  'goods' => [1, 100000000], 'energy' => [1, 1000000000],
                  'fighters' => [10, 1000000], 'torps' => [20, 1000000]] as $resource => [$cost, $stockLimit]) {
            $allocation = $planet[$resource === 'torps' ? 'prod_torp' : 'prod_' . $resource];
            $amount = (int)floor($production * max(0, min(100, (float)$allocation)) / 100 / $cost);
            $next[$resource] = $this->addWithinLimit((int)$planet[$resource], $amount, $stockLimit);
        }

        // This cycle's harvest feeds the starting population before growth.
        $food = (int)ceil($population * $this->settings['food_per_colonist']);
        $report['food_required'] = $food;
        $report['starving'] = $next['organics'] < $food;
        $next['organics'] = max(0, $next['organics'] - $food);
        if ($report['starving']) {
            $loss = (int)ceil($population * $this->settings['starvation_rate']);
            $next['colonists'] = max(0, $population - $loss);
        } else {
            $growth = (int)floor($population * $this->settings['growth_rate']);
            $next['colonists'] = $this->addWithinLimit($population, $growth, (int)$this->settings['population_limit']);
        }

        $credits = (int)$planet['credits'];
        // Starving colonies earn no tax; existing savings still earn capped interest.
        $tax = $report['starving'] ? 0 : (int)floor($population * $this->settings['tax_per_colonist']);
        $afterTax = $this->addWithinLimit($credits, $tax, $limit);
        $interest = (int)floor(min(max(0, $credits), $limit) * $this->settings['interest_rate']);
        $next['credits'] = $this->addWithinLimit($afterTax, $interest, $limit);
        $report['tax'] = $afterTax - $credits;
        $report['interest'] = $next['credits'] - $afterTax;
        $report['credit_limit'] = $limit;
        return ['planet' => $next, 'report' => $report];
    }

    private function addWithinLimit(int $current, int $amount, int $limit): int
    {
        // Caps stop growth; they never confiscate stock deposited above a cap.
        return $current + min($amount, max(0, $limit - $current));
    }
}
