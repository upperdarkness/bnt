<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Contraband ("Void Relics"): rare, very valuable, illegal.
 *  - Traded only at black-market sectors, never at ordinary ports.
 *  - Every buy or sell action costs alignment and never earns the trading bonus.
 *  - Carrying it into FedSpace sets Wanted; starbase inspectors confiscate it and fine the carrier.
 *  - Lost with the ship: the killer takes what fits in their hold.
 */
class ContrabandService
{
    public function __construct(
        private Database $db,
        private AlignmentService $alignment,
        private SectorRules $sectors,
        private array $config
    ) {}

    public function enabled(): bool
    {
        return (bool)($this->config['contraband']['enabled'] ?? false);
    }

    public function name(): string
    {
        return (string)($this->config['contraband']['name'] ?? 'Void Relics');
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config['contraband'][$key] ?? $default;
    }

    /** @return array{buy: int, sell: int, stock: int} credits per unit the ship pays / receives */
    public function prices(array $sector): array
    {
        $limit = max(1, (int)$this->cfg('stock_limit', 200));
        $stock = (int)($sector['port_contraband'] ?? 0);
        $mid = (float)$this->cfg('base_price', 1000) * (1 + (float)$this->cfg('price_swing', 0.5) * (1 - min(1, $stock / $limit)));
        $spread = (float)$this->cfg('spread', 0.10);
        return ['buy' => (int)ceil($mid * (1 + $spread)), 'sell' => (int)floor($mid * (1 - $spread)), 'stock' => $stock];
    }

    /** Value used for score calculation (mid price). */
    public function unitValue(): int
    {
        return (int)$this->cfg('base_price', 1000);
    }

    /** @return array{success: bool, error?: string, code?: string, message?: string, credits_delta?: int, amount?: int, unit_price?: int} */
    public function trade(int $shipId, string $action, int $amount): array
    {
        if (!$this->enabled()) {
            return $this->fail('Nobody here trades in that', 'CONTRABAND_DISABLED');
        }
        if (!in_array($action, ['buy', 'sell'], true) || $amount <= 0) {
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
            if (!$sector || !$sector['is_blackmarket']) {
                throw new TradeRefused('There is no black market in this sector', 'NOT_BLACKMARKET');
            }
            $prices = $this->prices($sector);
            $limit = (int)$this->cfg('stock_limit', 200);
            if ($action === 'buy') {
                $cost = $amount * $prices['buy'];
                if ($prices['stock'] < $amount) {
                    throw new TradeRefused('The dealer does not have that many', 'PORT_STOCK');
                }
                if ((int)$ship['ship_contraband'] + $amount > (int)$this->cfg('carry_cap', 50)) {
                    throw new TradeRefused('You cannot carry more than ' . (int)$this->cfg('carry_cap', 50) . ' ' . $this->name(), 'CONTRABAND_CAP');
                }
                if (TradeService::usedHolds($ship) + $amount > TradeService::holds((int)$ship['hull'], (string)$ship['ship_type'])) {
                    throw new TradeRefused('Not enough cargo space', 'NO_CARGO_SPACE');
                }
                if ((int)$ship['credits'] < $cost) {
                    throw new TradeRefused('Not enough credits', 'INSUFFICIENT_CREDITS');
                }
                $this->db->execute('UPDATE universe SET port_contraband = port_contraband - :a WHERE sector_id = :s', ['a' => $amount, 's' => (int)$sector['sector_id']]);
                $this->db->execute('UPDATE ships SET credits = credits - :c, ship_contraband = ship_contraband + :a WHERE ship_id = :id', ['c' => $cost, 'a' => $amount, 'id' => $shipId]);
                $delta = -$cost;
                $unit = $prices['buy'];
                $message = "Bought $amount {$this->name()} for $cost credits";
            } else {
                if ((int)$ship['ship_contraband'] < $amount) {
                    throw new TradeRefused("You do not have that much {$this->name()}", 'INSUFFICIENT_CARGO');
                }
                if ($prices['stock'] + $amount > $limit) {
                    throw new TradeRefused('The dealer cannot take that many right now', 'MARKET_FULL');
                }
                $earn = $amount * $prices['sell'];
                $this->db->execute('UPDATE universe SET port_contraband = port_contraband + :a WHERE sector_id = :s', ['a' => $amount, 's' => (int)$sector['sector_id']]);
                $this->db->execute('UPDATE ships SET credits = credits + :c, ship_contraband = ship_contraband - :a WHERE ship_id = :id', ['c' => $earn, 'a' => $amount, 'id' => $shipId]);
                $delta = $earn;
                $unit = $prices['sell'];
                $message = "Sold $amount {$this->name()} for $earn credits";
            }
            // The price of dealing in contraband: a large alignment hit per action. No trading bonus is recorded.
            $this->alignment->apply($shipId, $this->alignment->rules()->delta('contraband_trade'), $action === 'buy' ? 'contraband_buy' : 'contraband_sell');
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

    /**
     * Called when a ship arrives somewhere. Starbase: cargo confiscated and a fine taken.
     * FedSpace: alignment penalty and Wanted. Returns a description of what happened, or null.
     */
    public function inspectArrival(array $ship): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $carried = (int)$this->db->fetchOne('SELECT ship_contraband AS c FROM ships WHERE ship_id = :id', ['id' => (int)$ship['ship_id']])['c'];
        if ($carried <= 0) {
            return null;
        }
        $flags = $this->sectors->flags((int)$ship['sector']);
        if ($flags['starbase']) {
            $credits = (int)$this->db->fetchOne('SELECT credits FROM ships WHERE ship_id = :id', ['id' => (int)$ship['ship_id']])['credits'];
            $fine = min($credits, (int)$this->cfg('starbase_fine', 50000));
            $this->db->execute('UPDATE ships SET ship_contraband = 0, credits = credits - :f WHERE ship_id = :id', ['f' => $fine, 'id' => (int)$ship['ship_id']]);
            return ['type' => 'confiscated', 'units' => $carried, 'fine' => $fine,
                'message' => "Federation inspectors confiscated $carried {$this->name()} and fined you " . number_format($fine) . ' credits.'];
        }
        if ($flags['federation'] && $this->alignment->enabled()) {
            $this->alignment->apply((int)$ship['ship_id'], $this->alignment->rules()->delta('contraband_fedspace'), 'contraband_in_fedspace');
            $this->alignment->setWanted((int)$ship['ship_id'], 'carrying contraband in FedSpace', (int)$ship['sector']);
            return ['type' => 'wanted', 'units' => $carried,
                'message' => "Federation scanners detected $carried {$this->name()} in your hold. You are Wanted."];
        }
        return null;
    }

    /** A ship carrying contraband was destroyed: the killer takes what fits. @return int units taken */
    public function lootOnKill(int $killerId, int $victimId): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $victim = $this->db->fetchOne('SELECT ship_contraband FROM ships WHERE ship_id = :id', ['id' => $victimId]);
        $killer = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id', ['id' => $killerId]);
        $loot = (int)($victim['ship_contraband'] ?? 0);
        if ($loot <= 0 || !$killer) {
            return 0;
        }
        $room = min(
            (int)$this->cfg('carry_cap', 50) - (int)$killer['ship_contraband'],
            TradeService::holds((int)$killer['hull'], (string)$killer['ship_type']) - TradeService::usedHolds($killer)
        );
        $take = max(0, min($loot, $room));
        $this->db->execute('UPDATE ships SET ship_contraband = 0 WHERE ship_id = :id', ['id' => $victimId]);
        if ($take > 0) {
            $this->db->execute('UPDATE ships SET ship_contraband = ship_contraband + :t WHERE ship_id = :id', ['t' => $take, 'id' => $killerId]);
        }
        return $take;
    }

    public function regenerate(): int
    {
        $limit = (int)$this->cfg('stock_limit', 200);
        return $this->db->query(
            'UPDATE universe SET port_contraband = LEAST(:lim, port_contraband + CEIL((:lim2 - port_contraband) * CAST(:rate AS NUMERIC)))
             WHERE is_blackmarket AND port_contraband < :lim3',
            ['lim' => $limit, 'lim2' => $limit, 'lim3' => $limit, 'rate' => (float)$this->cfg('regeneration_rate', 0.01)]
        )->rowCount();
    }

    /** Target number of black markets for a universe of $sectorCount sectors. */
    public function targetMarkets(int $sectorCount): int
    {
        return $sectorCount <= 0 ? 0 : max(2, (int)round((float)$this->cfg('markets_per_1000', 7) * $sectorCount / 1000));
    }

    /** Mark black-market sectors (never FedSpace or starbases). Returns how many were added. */
    public function markMarkets(?int $count = null): int
    {
        $total = (int)$this->db->fetchOne('SELECT COUNT(*) AS c FROM universe')['c'];
        $have = (int)$this->db->fetchOne('SELECT COUNT(*) AS c FROM universe WHERE is_blackmarket')['c'];
        $need = ($count ?? $this->targetMarkets($total)) - $have;
        if ($need <= 0) {
            return 0;
        }
        return $this->db->query(
            "UPDATE universe SET is_blackmarket = TRUE, port_contraband = :stock,
                    port_type = CASE WHEN port_type = 'none' THEN 'special' ELSE port_type END
             WHERE sector_id IN (
                SELECT u.sector_id FROM universe u LEFT JOIN zones z ON z.zone_id = u.zone_id
                WHERE NOT u.is_blackmarket AND NOT u.is_starbase AND NOT COALESCE(z.is_federation, FALSE)
                ORDER BY RANDOM() LIMIT :n)",
            ['stock' => intdiv((int)$this->cfg('stock_limit', 200), 2), 'n' => $need]
        )->rowCount();
    }

    private function fail(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }
}
