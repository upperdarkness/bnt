<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Models\Ship;
use BNT\Models\ShipType;
use BNT\Models\Skill;
use BNT\Models\Universe;
use BNT\Models\Upgrade;

/**
 * Port trading, starbase equipment and upgrades. Shared by the web
 * controllers, the REST API and scripted NPCs so every rule lives in one place.
 */
class TradeService
{
    public const COMMODITIES = ['ore', 'organics', 'goods', 'energy'];

    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private Universe $universe,
        private Skill $skills,
        private AlignmentService $alignment,
        private SectorRules $sectors,
        private array $config
    ) {}

    // ------------------------------------------------------------ port maths

    public static function portSells(string $portType): array
    {
        return match ($portType) {
            'ore', 'organics', 'goods', 'energy' => [$portType],
            default => [],
        };
    }

    public static function portBuys(string $portType): array
    {
        return match ($portType) {
            'ore' => ['organics', 'goods'],
            'organics' => ['ore', 'goods'],
            'goods' => ['ore', 'organics'],
            'energy' => ['ore', 'organics', 'goods'],
            default => [],
        };
    }

    public static function holds(int $hullLevel, string $shipType): int
    {
        return ShipType::getCargoCapacity($shipType, (int)round(pow(1.5, $hullLevel) * 100));
    }

    public static function usedHolds(array $ship): int
    {
        return (int)$ship['ship_ore'] + (int)$ship['ship_organics'] + (int)$ship['ship_goods']
            + (int)$ship['ship_energy'] + (int)$ship['ship_colonists'];
    }

    /**
     * Port prices for a sector row. Prices are per unit; 'buy' is what the ship pays,
     * 'sell' is what the ship receives. $tradingBonus is a percentage from the trading skill.
     */
    public function prices(array $sector, float $tradingBonus = 0.0): array
    {
        $portType = $sector['port_type'];
        $canSell = self::portSells($portType);
        $canBuy = self::portBuys($portType);
        $prices = [];
        foreach (self::COMMODITIES as $commodity) {
            $cfg = $this->config['trading'][$commodity];
            $stock = (int)$sector["port_$commodity"];
            $demand = ($cfg['limit'] - $stock) / $cfg['limit'];
            $base = $cfg['price'] + $cfg['delta'] * $demand;

            $buy = 0;
            if (in_array($commodity, $canSell, true)) {
                $buy = (int)$base;
                if ($tradingBonus > 0) {
                    $buy = (int)($buy * (1.0 - $tradingBonus / 100));
                }
                $buy = max(1, $buy);
            }
            $sell = 0;
            if (in_array($commodity, $canBuy, true)) {
                $sell = (int)$base;
                if ($tradingBonus > 0) {
                    $sell = (int)($sell * (1.0 + $tradingBonus / 100));
                }
                $sell = max(1, $sell);
            }
            $prices[$commodity] = [
                'buy' => $buy, 'sell' => $sell, 'stock' => $stock,
                'canBuy' => in_array($commodity, $canBuy, true),
                'canSell' => in_array($commodity, $canSell, true),
            ];
        }
        return $prices;
    }

    /** Trading-skill percentage bonus for a ship row. */
    public function bonusFor(array $ship): float
    {
        return $this->skills->getTradingBonus((int)($ship['skill_trading'] ?? 0));
    }

    /** Price a ship pays at this location (starbase alignment modifiers apply only at starbases). */
    public function adjustPurchase(int $cost, array $ship): int
    {
        if (!$this->alignment->enabled() || !$this->sectors->isStarbase((int)$ship['sector'])) {
            return $cost;
        }
        return (int)ceil($cost * $this->alignment->rules()->starbasePriceFactor((int)$ship['alignment']));
    }

    /** Null if the ship may use starbase services here, otherwise the refusal message. */
    public function starbaseRefusal(array $ship): ?string
    {
        if ($this->alignment->enabled() && $this->sectors->isStarbase((int)$ship['sector'])
            && $this->alignment->rules()->refusedAtStarbase((int)$ship['alignment'])) {
            return 'The starbase refuses to serve Pirates.';
        }
        return null;
    }

    // ------------------------------------------------------------ trading

    /**
     * Buy or sell at the port in the ship's current sector.
     *
     * @return array{success: bool, error?: string, code?: string, message?: string, credits_delta?: int, amount?: int, unit_price?: int}
     */
    public function trade(int $shipId, string $commodity, string $action, int $amount): array
    {
        if (!in_array($action, ['buy', 'sell'], true) || !in_array($commodity, self::COMMODITIES, true) || $amount <= 0) {
            return $this->fail('Invalid trade parameters', 'INVALID_TRADE');
        }
        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $ship = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id FOR UPDATE', ['id' => $shipId]);
            if (!$ship) {
                throw new TradeRefused('Ship not found', 'NOT_FOUND');
            }
            $sector = $this->db->fetchOne('SELECT * FROM universe WHERE sector_id = :id FOR UPDATE', ['id' => (int)$ship['sector']]);
            $isStarbase = $sector && ($sector['is_starbase'] ?? false);
            if (!$sector || ($sector['port_type'] === 'none' && !$isStarbase)) {
                throw new TradeRefused('No port in this sector', 'NO_PORT');
            }
            if ($refusal = $this->starbaseRefusal($ship)) {
                throw new TradeRefused($refusal, 'SERVICE_REFUSED');
            }
            $portType = $sector['port_type'];
            if ($action === 'buy' && !in_array($commodity, self::portSells($portType), true)) {
                throw new TradeRefused(ucfirst($portType) . ' ports do not sell ' . $commodity, 'PORT_WONT_SELL');
            }
            if ($action === 'sell' && !in_array($commodity, self::portBuys($portType), true)) {
                throw new TradeRefused(ucfirst($portType) . ' ports do not buy ' . $commodity
                    . ' (they only buy ' . implode(' and ', self::portBuys($portType)) . ')', 'PORT_WONT_BUY');
            }
            $skills = $this->skills->getSkills($shipId);
            $prices = $this->prices($sector, $this->skills->getTradingBonus($skills['trading']));
            $portCol = "port_$commodity";
            $shipCol = "ship_$commodity";

            if ($action === 'buy') {
                // Alignment price modifiers apply to the whole bill so small unit prices don't round them away.
                $cost = $this->adjustPurchase($amount * $prices[$commodity]['buy'], $ship);
                $unit = round($cost / $amount, 2);
                if ((int)$sector[$portCol] < $amount) {
                    throw new TradeRefused("Port does not have enough $commodity", 'PORT_STOCK');
                }
                if ((int)$ship['credits'] < $cost) {
                    throw new TradeRefused('Not enough credits', 'INSUFFICIENT_CREDITS');
                }
                $max = self::holds((int)$ship['hull'], (string)$ship['ship_type']);
                if (self::usedHolds($ship) + $amount > $max) {
                    throw new TradeRefused('Not enough cargo space', 'NO_CARGO_SPACE');
                }
                $this->db->execute("UPDATE universe SET $portCol = $portCol - :a WHERE sector_id = :s", ['a' => $amount, 's' => (int)$sector['sector_id']]);
                $this->db->execute("UPDATE ships SET credits = credits - :c, $shipCol = $shipCol + :a WHERE ship_id = :id",
                    ['c' => $cost, 'a' => $amount, 'id' => $shipId]);
                $delta = -$cost;
                $message = "Bought $amount $commodity for $cost credits";
            } else {
                $unit = $prices[$commodity]['sell'];
                if ((int)$ship[$shipCol] < $amount) {
                    throw new TradeRefused("You do not have enough $commodity", 'INSUFFICIENT_CARGO');
                }
                $earn = $amount * $unit;
                $this->db->execute("UPDATE universe SET $portCol = $portCol + :a WHERE sector_id = :s", ['a' => $amount, 's' => (int)$sector['sector_id']]);
                $this->db->execute("UPDATE ships SET credits = credits + :c, $shipCol = $shipCol - :a WHERE ship_id = :id",
                    ['c' => $earn, 'a' => $amount, 'id' => $shipId]);
                $delta = $earn;
                $message = "Sold $amount $commodity for $earn credits";
            }
            $traded = abs($delta);
            $points = (int)floor($traded / 50000);
            if ($points > 0) {
                $this->skills->awardSkillPoints($shipId, $points);
            }
            $this->alignment->recordTrade($shipId, $traded);
            $this->recordKnownPort($shipId, (int)$sector['sector_id'], $portType);
            if ($own) {
                $pdo->commit();
            }
            return ['success' => true, 'message' => $message, 'credits_delta' => $delta, 'amount' => $amount, 'unit_price' => $unit];
        } catch (TradeRefused $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->fail($e->getMessage(), $e->errorCode);
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Remember that a ship has seen a port (used by find_trade). */
    public function recordKnownPort(int $shipId, int $sectorId, ?string $portType = null): void
    {
        if ($portType === null) {
            $row = $this->db->fetchOne('SELECT port_type FROM universe WHERE sector_id = :s', ['s' => $sectorId]);
            $portType = $row['port_type'] ?? 'none';
        }
        if ($portType === 'none' || $portType === 'special') {
            return;
        }
        $this->db->execute(
            'INSERT INTO ship_known_ports (ship_id, sector_id, seen_at) VALUES (:ship, :sec, now())
             ON CONFLICT (ship_id, sector_id) DO UPDATE SET seen_at = now()',
            ['ship' => $shipId, 'sec' => $sectorId]
        );
    }

    // ------------------------------------------------------------ starbase

    /**
     * Buy exactly one upgrade level. Starbase sector only (API path); web keeps its own page.
     */
    public function buyUpgrade(array $ship, string $component, bool $requireStarbase = true): array
    {
        if ($requireStarbase && !$this->sectors->isStarbase((int)$ship['sector'])) {
            return $this->fail('Upgrades can only be purchased at a starbase', 'NOT_STARBASE');
        }
        if ($refusal = $this->starbaseRefusal($ship)) {
            return $this->fail($refusal, 'SERVICE_REFUSED');
        }
        if (!Upgrade::isValidComponent($component)) {
            return $this->fail('Invalid component', 'INVALID_COMPONENT');
        }
        $skills = $this->skills->getSkills((int)$ship['ship_id']);
        $discount = $this->skills->getEngineeringDiscount($skills['engineering']);
        // Alignment price modifier is folded into the percentage discount (negative = surcharge).
        $factor = $requireStarbase || $this->sectors->isStarbase((int)$ship['sector'])
            ? ($this->alignment->enabled() ? $this->alignment->rules()->starbasePriceFactor((int)$ship['alignment']) : 1.0)
            : 1.0;
        $upgrade = new Upgrade($this->db);
        $result = $upgrade->upgradeComponent((int)$ship['ship_id'], $component, $this->config, $discount, $factor);
        if (!$result['success']) {
            return $this->fail($result['error'] ?? 'Upgrade failed', 'UPGRADE_FAILED') + ['details' => $result];
        }
        if (($result['new_level'] % 5) === 0) {
            $this->skills->awardSkillPoints((int)$ship['ship_id'], 1);
        }
        return ['success' => true, 'message' => sprintf('%s upgraded from level %d to %d for %s credits',
            $result['component'], $result['old_level'], $result['new_level'], number_format($result['cost'])), 'result' => $result];
    }

    private function fail(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }
}
