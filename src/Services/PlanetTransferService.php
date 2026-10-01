<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/** Move cargo between a ship and a planet it owns and is standing on. */
class PlanetTransferService
{
    private const RESOURCES = ['ore', 'organics', 'goods', 'energy', 'colonists', 'fighters', 'credits'];

    public function __construct(private Database $db) {}

    /** @return array{success: bool, error?: string, code?: string, message?: string} */
    public function transfer(array $ship, int $planetId, string $resource, int $amount, string $direction): array
    {
        if (!in_array($resource, self::RESOURCES, true)) {
            return $this->fail('Invalid resource type', 'INVALID_RESOURCE');
        }
        if (!in_array($direction, ['to_planet', 'to_ship'], true)) {
            return $this->fail('Invalid transfer direction', 'INVALID_DIRECTION');
        }
        if ($amount <= 0) {
            return $this->fail('Amount must be positive', 'INVALID_AMOUNT');
        }
        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $planet = $this->db->fetchOne('SELECT * FROM planets WHERE planet_id = :id FOR UPDATE', ['id' => $planetId]);
            $fresh = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id FOR UPDATE', ['id' => (int)$ship['ship_id']]);
            if (!$planet || (int)$planet['owner'] !== (int)$ship['ship_id']) {
                throw new TradeRefused('You do not own this planet', 'NOT_OWNER');
            }
            if (!$fresh['on_planet'] || (int)$fresh['planet_id'] !== $planetId) {
                throw new TradeRefused('You must land on the planet to transfer resources', 'NOT_ON_PLANET');
            }
            $shipCol = in_array($resource, ['credits', 'fighters'], true) ? $resource : "ship_$resource";
            if ($direction === 'to_planet') {
                if ((int)$fresh[$shipCol] < $amount) {
                    throw new TradeRefused("Not enough $resource on ship", 'INSUFFICIENT_SHIP');
                }
                $this->db->execute("UPDATE planets SET $resource = $resource + :a WHERE planet_id = :id", ['a' => $amount, 'id' => $planetId]);
                $this->db->execute("UPDATE ships SET $shipCol = $shipCol - :a WHERE ship_id = :id", ['a' => $amount, 'id' => (int)$ship['ship_id']]);
            } else {
                if ((int)$planet[$resource] < $amount) {
                    throw new TradeRefused("Not enough $resource on planet", 'INSUFFICIENT_PLANET');
                }
                if (!in_array($resource, ['credits', 'fighters'], true)
                    && TradeService::usedHolds($fresh) + $amount > TradeService::holds((int)$fresh['hull'], (string)$fresh['ship_type'])) {
                    throw new TradeRefused('Not enough cargo space on ship', 'NO_CARGO_SPACE');
                }
                $this->db->execute("UPDATE planets SET $resource = $resource - :a WHERE planet_id = :id", ['a' => $amount, 'id' => $planetId]);
                $this->db->execute("UPDATE ships SET $shipCol = $shipCol + :a WHERE ship_id = :id", ['a' => $amount, 'id' => (int)$ship['ship_id']]);
            }
            if ($own) {
                $pdo->commit();
            }
            return ['success' => true, 'message' => "Transferred $amount $resource " . ($direction === 'to_planet' ? 'to planet' : 'to ship')];
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

    private function fail(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }
}
