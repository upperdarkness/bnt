<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Cached sector graph (port types and link lists) with breadth-first search.
 * Scripted NPC decisions use this instead of per-hop queries. The cache is
 * rebuilt every npc.graph_cache_seconds (10 minutes by default).
 */
class SectorGraph
{
    private ?array $graph = null;

    public function __construct(private Database $db, private array $config) {}

    /** Build a graph directly (tests, simulation). */
    public static function fromArrays(array $links, array $ports = []): self
    {
        $g = new self(new Database([]), []);
        $g->graph = ['built' => time(), 'links' => $links, 'ports' => $ports, 'zones' => []];
        return $g;
    }

    public function data(): array
    {
        if ($this->graph !== null && (time() - $this->graph['built']) < $this->ttl()) {
            return $this->graph;
        }
        $path = $this->config['npc']['graph_cache_path'] ?? null;
        if ($path && is_readable($path)) {
            $cached = json_decode((string)file_get_contents($path), true);
            if (is_array($cached) && (time() - ($cached['built'] ?? 0)) < $this->ttl()) {
                return $this->graph = $this->normalise($cached);
            }
        }
        $this->graph = $this->build();
        if ($path) {
            @file_put_contents($path, json_encode($this->graph), LOCK_EX);
        }
        return $this->graph;
    }

    public function refresh(): void
    {
        $this->graph = null;
        $path = $this->config['npc']['graph_cache_path'] ?? null;
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    private function ttl(): int
    {
        return (int)($this->config['npc']['graph_cache_seconds'] ?? 600);
    }

    private function build(): array
    {
        $links = [];
        foreach ($this->db->fetchAll('SELECT link_start, link_dest FROM links ORDER BY link_start, link_dest') as $row) {
            $links[(int)$row['link_start']][] = (int)$row['link_dest'];
        }
        $ports = [];
        $zones = [];
        foreach ($this->db->fetchAll('SELECT sector_id, port_type, is_starbase, zone_id FROM universe') as $row) {
            $id = (int)$row['sector_id'];
            $zones[$id] = (int)$row['zone_id'];
            if ($row['port_type'] !== 'none' || $row['is_starbase']) {
                $ports[$id] = ['type' => $row['port_type'], 'starbase' => (bool)$row['is_starbase']];
            }
        }
        return ['built' => time(), 'links' => $links, 'ports' => $ports, 'zones' => $zones];
    }

    private function normalise(array $cached): array
    {
        // JSON turns int keys into strings; PHP arrays coerce them back on access.
        return $cached;
    }

    public function neighbours(int $sector): array
    {
        return $this->data()['links'][$sector] ?? [];
    }

    public function port(int $sector): ?array
    {
        return $this->data()['ports'][$sector] ?? null;
    }

    public function zoneOf(int $sector): ?int
    {
        return $this->data()['zones'][$sector] ?? null;
    }

    public function sectorsInZone(int $zoneId): array
    {
        return array_keys(array_filter($this->data()['zones'], static fn($z) => $z === $zoneId));
    }

    /**
     * Shortest path (list of sectors including start and goal) or null.
     * @param int[] $avoid sectors the path may not pass through (the goal is always allowed)
     */
    public function path(int $from, int $to, int $maxDepth = 20, array $avoid = []): ?array
    {
        if ($from === $to) {
            return [$from];
        }
        $links = $this->data()['links'];
        $avoidSet = array_flip($avoid);
        $prev = [$from => null];
        $frontier = [$from];
        for ($depth = 1; $depth <= $maxDepth && $frontier; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($links[$node] ?? [] as $n) {
                    if (array_key_exists($n, $prev)) {
                        continue;
                    }
                    if (isset($avoidSet[$n]) && $n !== $to) {
                        continue;
                    }
                    $prev[$n] = $node;
                    if ($n === $to) {
                        $path = [$to];
                        for ($c = $node; $c !== null; $c = $prev[$c]) {
                            array_unshift($path, $c);
                        }
                        return $path;
                    }
                    $next[] = $n;
                }
            }
            $frontier = $next;
        }
        return null;
    }

    /**
     * Distances (hops) from a sector to every sector within $maxDepth.
     * @return array<int,int>
     */
    public function distances(int $from, int $maxDepth): array
    {
        $links = $this->data()['links'];
        $dist = [$from => 0];
        $frontier = [$from];
        for ($d = 1; $d <= $maxDepth && $frontier; $d++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($links[$node] ?? [] as $n) {
                    if (!isset($dist[$n])) {
                        $dist[$n] = $d;
                        $next[] = $n;
                    }
                }
            }
            $frontier = $next;
        }
        return $dist;
    }

    /** Nearest sector satisfying $predicate (BFS order), or null. */
    public function nearest(int $from, callable $predicate, int $maxDepth = 20): ?int
    {
        foreach ($this->distances($from, $maxDepth) as $sector => $_) {
            if ($sector !== $from && $predicate($sector)) {
                return $sector;
            }
        }
        return null;
    }
}
