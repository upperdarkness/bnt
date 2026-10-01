<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Services\SectorGraph;

class SectorGraphTest extends TestCase
{
    private function ring(int $n): SectorGraph
    {
        $links = [];
        for ($i = 1; $i <= $n; $i++) {
            $links[$i] = [$i === 1 ? $n : $i - 1, $i === $n ? 1 : $i + 1];
        }
        return SectorGraph::fromArrays($links);
    }

    public function testShortestPathOnRing(): void
    {
        $g = $this->ring(10);
        $this->assertSame([1, 2, 3, 4], $g->path(1, 4));
        $this->assertSame([1, 10, 9], $g->path(1, 9), 'goes the short way round');
        $this->assertSame([5], $g->path(5, 5));
    }

    public function testMaxDepthIsHonoured(): void
    {
        $g = $this->ring(60);
        $this->assertSame(null, $g->path(1, 30, 20), '29 hops away exceeds depth 20');
        $this->assertTrue($g->path(1, 21, 20) !== null, '20 hops is allowed');
        $this->assertSame(null, $g->path(1, 22, 20), '21 hops is not');
    }

    public function testAvoidList(): void
    {
        $g = $this->ring(6);
        $this->assertSame([1, 6, 5, 4], $g->path(1, 4, 20, [2, 3]));
        $this->assertSame(null, $g->path(1, 4, 20, [2, 6]), 'both routes blocked');
        $this->assertSame([1, 2], $g->path(1, 2, 20, [2]), 'the goal itself is always allowed');
    }

    public function testDistancesAndNearest(): void
    {
        $g = SectorGraph::fromArrays([1 => [2], 2 => [1, 3], 3 => [2, 4], 4 => [3]]);
        $this->assertSame([1 => 0, 2 => 1, 3 => 2], $g->distances(1, 2));
        $this->assertSame(3, $g->nearest(1, fn($s) => $s >= 3));
        $this->assertSame(null, $g->nearest(1, fn($s) => $s > 99));
    }

    public function testDisconnectedSectorsHaveNoPath(): void
    {
        $g = SectorGraph::fromArrays([1 => [2], 2 => [1], 3 => [4], 4 => [3]]);
        $this->assertSame(null, $g->path(1, 4));
    }
}
