<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Models\Combat;
use BNT\Models\Ship;
use BNT\Models\ShipType;
use BNT\Models\Universe;

/**
 * Sector movement: turn cost, mines, sector fighters, discovery, police sightings.
 * Used by the web UI, the REST API and NPCs.
 */
class MovementService
{
    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private Universe $universe,
        private Combat $combat,
        private AlignmentService $alignment,
        private SectorRules $sectors,
        private TradeService $trade,
        private NpcEvents $events,
        private SectorGraph $graph,
        private array $config,
        private ?ContrabandService $contraband = null,
        private ?ProtectionService $protection = null
    ) {}

    /**
     * Move one hop.
     *
     * @return array{success: bool, error?: string, code?: string, turns_used?: int, destroyed?: bool,
     *   destroyed_by?: ?string, mine?: ?array, fighter?: ?array, ship?: array, hazard?: bool}
     */
    public function move(array $ship, int $destination): array
    {
        $shipId = (int)$ship['ship_id'];
        $turnCost = ShipType::getTurnCost($ship['ship_type']);
        if ((int)$ship['turns'] < $turnCost) {
            return ['success' => false, 'error' => 'Not enough turns', 'code' => 'INSUFFICIENT_TURNS'];
        }
        if (!$this->universe->isLinked((int)$ship['sector'], $destination)) {
            return ['success' => false, 'error' => 'Sectors are not linked', 'code' => 'SECTORS_NOT_LINKED'];
        }

        // A protected ship is turned back from sectors holding hostile defences (no scouting through minefields).
        if ($this->protection && $this->protection->isProtected($ship) && $this->protection->hostileDefencesIn($destination, $ship)) {
            return ['success' => false, 'code' => 'DEFENCES_BLOCK_PROTECTED',
                'error' => 'Hostile defences in that sector block your protected ship. You turn back.'];
        }

        $this->shipModel->useTurns($shipId, $turnCost);
        $this->db->execute(
            'UPDATE ships SET sector = :s, on_planet = FALSE, planet_id = 0 WHERE ship_id = :id',
            ['s' => $destination, 'id' => $shipId]
        );
        $this->db->execute('INSERT INTO movement_log (ship_id, sector_id, time) VALUES (:id, :s, NOW())', ['id' => $shipId, 's' => $destination]);
        $this->sectors->forget();

        $out = ['success' => true, 'turns_used' => $turnCost, 'destroyed' => false, 'destroyed_by' => null,
            'mine' => null, 'fighter' => null, 'hazard' => false];

        // Profile before any damage so a defence kill is judged on the victim's prior standing.
        $before = $this->alignment->profile($shipId);

        $mine = $this->combat->checkMines($shipId, $destination, (int)$ship['hull'], (int)($ship['dev_minedeflector'] ?? 0));
        if ($mine['deflector_used']) {
            $out['mine'] = ['deflector_used' => true, 'message' => $mine['message']];
            $out['hazard'] = true;
        } elseif ($mine['hit']) {
            $this->combat->applyDamageToShip($shipId, $mine['damage']);
            if ($mine['mines_destroyed'] > 0) {
                $this->removeMines($destination, $mine['mines_destroyed']);
            }
            $out['mine'] = ['hit' => true, 'damage' => $mine['damage'], 'message' => $mine['message']];
            $out['hazard'] = true;
            $this->noteDefenceHit($shipId, $destination, 'mines', $mine['owner_id'] ?? null);
            if ($this->isDestroyed($shipId)) {
                $this->alignment->recordDefenceKill((int)($mine['owner_id'] ?? 0), $before ?? ['ship_id' => $shipId, 'alignment' => 0, 'team' => 0]);
                return $this->finish($out, $shipId, true, 'mines');
            }
        }

        $fresh = $this->shipModel->find($shipId);
        $fighters = $this->combat->checkSectorFighters($fresh, $destination);
        if ($fighters['attacked']) {
            if ($fighters['damage'] > 0) {
                $this->combat->applyDamageToShip($shipId, $fighters['damage']);
            }
            $out['fighter'] = ['attacked' => true, 'damage' => $fighters['damage'], 'message' => $fighters['message']];
            $out['hazard'] = true;
            $this->noteDefenceHit($shipId, $destination, 'fighters', $fighters['owner_id'] ?? null);
            if ($this->isDestroyed($shipId)) {
                $this->alignment->recordDefenceKill((int)($fighters['owner_id'] ?? 0), $before ?? ['ship_id' => $shipId, 'alignment' => 0, 'team' => 0]);
                return $this->finish($out, $shipId, true, 'fighters');
            }
        }

        $this->trade->recordKnownPort($shipId, $destination);
        $this->noteContacts($shipId, $destination);
        $out['contraband'] = $this->contraband?->inspectArrival($this->shipModel->find($shipId));
        return $this->finish($out, $shipId, false, null);
    }

    /**
     * Multi-hop travel along the shortest path, stopping early on mines,
     * fighters, a threatening ship, destruction, or when turns run out.
     *
     * @return array{success: bool, error?: string, code?: string, path?: int[], steps?: array, stopped_because?: string, sector?: int, turns_used?: int, ship?: array}
     */
    public function goTo(array $ship, int $target, int $maxHops = 20): array
    {
        $from = (int)$ship['sector'];
        $path = $this->graph->path($from, $target, min($maxHops, (int)($this->config['npc']['max_path_depth'] ?? 20)));
        if ($path === null) {
            return ['success' => false, 'error' => 'No route to that sector within range', 'code' => 'NO_ROUTE'];
        }
        if (count($path) === 1) {
            return ['success' => true, 'path' => $path, 'steps' => [], 'stopped_because' => 'already_there', 'sector' => $from, 'turns_used' => 0, 'ship' => $ship];
        }
        $steps = [];
        $turnsUsed = 0;
        $stopped = 'arrived';
        $current = $ship;
        $rules = $this->alignment->rules();
        foreach (array_slice($path, 1) as $hop) {
            $step = $this->move($current, $hop);
            if (!$step['success']) {
                $stopped = strtolower($step['code'] ?? 'error');
                $steps[] = ['sector' => $hop, 'error' => $step['error']];
                break;
            }
            $turnsUsed += $step['turns_used'];
            $current = $step['ship'];
            $steps[] = ['sector' => $hop, 'mine' => $step['mine'], 'fighter' => $step['fighter']];
            if ($step['destroyed']) {
                $stopped = 'destroyed';
                break;
            }
            if ($step['hazard']) {
                $stopped = 'defences';
                break;
            }
            if ($hop !== $target) {
                $here = $this->shipModel->getShipsInSector($hop, (int)$current['ship_id']);
                $me = $current + ['faction' => null];
                foreach ($here as $other) {
                    $other['wanted'] = $this->alignment->isWanted($other);
                    if (FactionRelations::threatens($me, $other, $rules)) {
                        $stopped = 'hostile_ship';
                        break 2;
                    }
                }
            }
        }
        return ['success' => true, 'path' => $path, 'steps' => $steps, 'stopped_because' => $stopped,
            'sector' => (int)$current['sector'], 'turns_used' => $turnsUsed, 'ship' => $current];
    }

    private function finish(array $out, int $shipId, bool $destroyed, ?string $by): array
    {
        $out['destroyed'] = $destroyed;
        $out['destroyed_by'] = $by;
        $out['ship'] = $this->shipModel->find($shipId);
        return $out;
    }

    private function isDestroyed(int $shipId): bool
    {
        $row = $this->db->fetchOne('SELECT ship_destroyed FROM ships WHERE ship_id = :id', ['id' => $shipId]);
        return (bool)($row['ship_destroyed'] ?? false);
    }

    private function noteDefenceHit(int $victimId, int $sector, string $kind, ?int $ownerId): void
    {
        $this->events->queue($victimId, 'hit_defences', ['sector' => $sector, 'defence' => $kind, 'owner' => $ownerId]);
    }

    /** Police sightings: Wanted ship meets police in a sector, or police meets a Wanted ship. */
    private function noteContacts(int $shipId, int $sector): void
    {
        if (!$this->alignment->enabled()) {
            return;
        }
        $me = $this->alignment->profile($shipId);
        if (!$me) {
            return;
        }
        $others = $this->shipModel->getShipsInSector($sector, $shipId);
        $meWanted = $this->alignment->isWanted($me);
        $mePolice = ($me['faction'] ?? null) === 'police' && $me['is_npc'];
        foreach ($others as $o) {
            $oPolice = !empty($o['is_npc']) && ($o['faction'] ?? null) === 'police';
            if ($meWanted && $oPolice) {
                $this->alignment->noteSighting($shipId, $sector);
            }
            if ($mePolice && $this->alignment->isWanted($o)) {
                $this->alignment->noteSighting((int)$o['ship_id'], $sector);
            }
        }
    }

    private function removeMines(int $sectorId, int $count): void
    {
        $mines = $this->db->fetchAll(
            "SELECT * FROM sector_defence WHERE sector_id = :sector AND defence_type = 'M' ORDER BY quantity ASC",
            ['sector' => $sectorId]
        );
        $remaining = $count;
        foreach ($mines as $mine) {
            if ($remaining <= 0) {
                break;
            }
            if ($mine['quantity'] <= $remaining) {
                $this->db->execute('DELETE FROM sector_defence WHERE defence_id = :id', ['id' => $mine['defence_id']]);
                $remaining -= $mine['quantity'];
            } else {
                $this->db->execute('UPDATE sector_defence SET quantity = quantity - :count WHERE defence_id = :id',
                    ['count' => $remaining, 'id' => $mine['defence_id']]);
                $remaining = 0;
            }
        }
    }
}
