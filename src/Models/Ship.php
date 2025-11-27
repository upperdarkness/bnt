<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class Ship extends Model
{
    protected string $table = 'ships';
    protected string $primaryKey = 'ship_id';

    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    public function findByName(string $name): ?array
    {
        return $this->findBy('character_name', $name);
    }

    public function authenticate(string $email, string $password): ?array
    {
        $ship = $this->findByEmail($email);

        if (!$ship || !password_verify($password, $ship['password_hash'])) {
            return null;
        }

        // Update last login
        $this->update((int)$ship['ship_id'], [
            'last_login' => date('Y-m-d H:i:s')
        ]);

        return $ship;
    }

    public function register(string $email, string $password, string $characterName, array $config, string $shipType = 'balanced'): int
    {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Get ship type specific starting bonuses
        $startingBonuses = ShipType::getStartingBonuses($shipType);

        $data = [
            'email' => $email,
            'password_hash' => $passwordHash,
            'character_name' => $characterName,
            'ship_type' => $shipType,
            'credits' => $startingBonuses['credits'] ?? $config['start_credits'],
            'turns' => $startingBonuses['turns'] ?? $config['start_turns'],
            'ship_energy' => $startingBonuses['ship_energy'] ?? $config['start_energy'],
            'ship_fighters' => $startingBonuses['ship_fighters'] ?? $config['start_fighters'],
            'ship_ore' => $startingBonuses['ship_ore'] ?? 0,
            'ship_organics' => $startingBonuses['ship_organics'] ?? 0,
            'ship_goods' => $startingBonuses['ship_goods'] ?? 0,
            'torps' => $startingBonuses['torps'] ?? 0,
            'armor_pts' => $config['start_armor'],
            'sector' => 1,
        ];

        $shipId = $this->create($data);

        // Create IGB account
        $this->db->execute(
            'INSERT INTO ibank_accounts (ship_id, balance, loan) VALUES (:ship_id, 0, 0)',
            ['ship_id' => $shipId]
        );

        return $shipId;
    }

    public function isDestroyed(int $shipId): bool
    {
        $ship = $this->find($shipId);
        return $ship && $ship['ship_destroyed'];
    }

    public function getShipsInSector(int $sectorId, ?int $excludeShipId = null): array
    {
        $sql = "SELECT ship_id, character_name, score, team
                FROM {$this->table}
                WHERE sector = :sector
                AND ship_destroyed = FALSE
                AND on_planet = FALSE";

        $params = ['sector' => $sectorId];

        if ($excludeShipId !== null) {
            $sql .= " AND ship_id != :exclude";
            $params['exclude'] = $excludeShipId;
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function addTurns(int $shipId, int $turns): bool
    {
        $sql = "UPDATE {$this->table}
                SET turns = LEAST(turns + :turns, 2500)
                WHERE ship_id = :ship_id";

        return $this->db->execute($sql, [
            'turns' => $turns,
            'ship_id' => $shipId
        ]);
    }

    public function useTurns(int $shipId, int $turns): bool
    {
        $sql = "UPDATE {$this->table}
                SET turns = GREATEST(turns - :turns, 0),
                    turns_used = turns_used + :turns
                WHERE ship_id = :ship_id
                AND turns >= :turns";

        return $this->db->execute($sql, [
            'turns' => $turns,
            'ship_id' => $shipId
        ]);
    }

    public function calculateScore(int $shipId): int
    {
        // Get ship value
        $ship = $this->find($shipId);
        if (!$ship) {
            return 0;
        }

        $score = 0;

        // Ship upgrades value
        $upgradeCost = 1000;
        $upgradeFactor = 2;
        $levels = $ship['hull'] + $ship['engines'] + $ship['power'] +
                  $ship['computer'] + $ship['sensors'] + $ship['beams'] +
                  $ship['torp_launchers'] + $ship['shields'] +
                  $ship['armor'] + $ship['cloak'];
        $score += pow($upgradeFactor, $levels) * $upgradeCost;

        // Cargo value
        $score += $ship['ship_ore'] * 11;
        $score += $ship['ship_organics'] * 5;
        $score += $ship['ship_goods'] * 15;
        $score += $ship['ship_energy'] * 3;
        $score += $ship['ship_colonists'] * 5;
        $score += $ship['ship_fighters'] * 50;
        $score += $ship['torps'] * 25;
        $score += $ship['armor_pts'] * 5;

        // Credits
        $score += $ship['credits'];

        // Devices
        $score += $ship['dev_genesis'] * 1000000;
        $score += $ship['dev_emerwarp'] * 1000000;
        $score += $ship['dev_warpedit'] * 100000;
        $score += ($ship['dev_escapepod'] ? 100000 : 0);
        $score += ($ship['dev_fuelscoop'] ? 100000 : 0);
        $score += ($ship['dev_lssd'] ? 10000000 : 0);

        // Planet value
        $sql = "SELECT
                SUM(organics * 5 + ore * 11 + goods * 15 + energy * 3) as goods_value,
                SUM(colonists * 5) as colonist_value,
                SUM(fighters * 50 + torps * 25) as defense_value,
                SUM(credits) as planet_credits
                FROM planets
                WHERE owner = :ship_id";

        $planets = $this->db->fetchOne($sql, ['ship_id' => $shipId]);
        if ($planets) {
            $score += $planets['goods_value'] ?? 0;
            $score += $planets['colonist_value'] ?? 0;
            $score += $planets['defense_value'] ?? 0;
            $score += $planets['planet_credits'] ?? 0;
        }

        // IGB balance
        $igb = $this->db->fetchOne(
            'SELECT balance - loan as net FROM ibank_accounts WHERE ship_id = :ship_id',
            ['ship_id' => $shipId]
        );
        if ($igb) {
            $score += $igb['net'] ?? 0;
        }

        // Final score is square root of total value
        $finalScore = (int)round(sqrt(max($score, 0)));

        // Update score in database
        $this->update($shipId, ['score' => $finalScore]);

        return $finalScore;
    }
}
