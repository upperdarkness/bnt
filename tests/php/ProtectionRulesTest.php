<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Services\ProtectionRules;

class ProtectionRulesTest extends TestCase
{
    private function rules(array $override = []): ProtectionRules
    {
        static $config = null;
        $config ??= require dirname(__DIR__, 2) . '/config/config.php';
        return new ProtectionRules(array_replace_recursive($config, $override));
    }

    public function testWhoIsProtected(): void
    {
        $r = $this->rules();
        $now = 1_000_000;
        $this->assertTrue($r->isProtected(['protection_state' => 'protected'], $now));
        $this->assertFalse($r->isProtected(['protection_state' => 'none'], $now));
        $this->assertFalse($r->isProtected(['protection_state' => 'grace'], $now), 'attackable during the warning period by default');
        $this->assertFalse($r->isProtected(['protection_state' => 'protected', 'is_npc' => true], $now), 'NPCs never get protection');
        $this->assertTrue($r->isProtected(['protection_state' => 'none', 'respawn_shield_until' => date('c', $now + 60)], $now), 'respawn shield');
        $this->assertFalse($r->isProtected(['protection_state' => 'none', 'respawn_shield_until' => date('c', $now - 60)], $now), 'expired shield');
        $this->assertTrue($this->rules(['protection' => ['grace_protects' => true]])->isProtected(['protection_state' => 'grace'], $now));
        $this->assertFalse($this->rules(['protection' => ['enabled' => false]])->isProtected(['protection_state' => 'protected'], $now), 'master switch');
    }

    public function testScoreThresholdScalesWithTheEconomyWithAFloor(): void
    {
        $r = $this->rules();
        $this->assertSame(10000, $r->scoreThreshold(null), 'no data: the floor');
        $this->assertSame(10000, $r->scoreThreshold(20000), '25% of 20,000 is below the floor');
        $this->assertSame(10000, $r->scoreThreshold(40000));
        $this->assertSame(25000, $r->scoreThreshold(100000));
        $this->assertSame(50000, $this->rules(['protection' => ['score_fraction' => 0.5]])->scoreThreshold(100000));
    }

    public function testPercentile(): void
    {
        $r = $this->rules();
        $this->assertSame(null, $r->percentile([], 50));
        $this->assertSame(5.0, $r->percentile([5], 50));
        $this->assertSame(30.0, $r->percentile([10, 50, 30], 50), 'median of three');
        $this->assertSame(20.0, $r->percentile([10, 30], 50), 'median interpolates between two');
        $this->assertSame(10.0, $r->percentile([10, 20, 30, 40, 50], 0));
        $this->assertSame(50.0, $r->percentile([10, 20, 30, 40, 50], 100));
        // A dominant player skews the mean, not the median.
        $this->assertSame(1100.0, $r->percentile([900, 1000, 1100, 1200, 900000], 50));
    }

    public function testExitConditionsAndWhichComesFirst(): void
    {
        $r = $this->rules();
        $this->assertSame(null, $r->exitReason(['active_days' => 13, 'score' => 9999], 10000));
        $this->assertSame('days', $r->exitReason(['active_days' => 14, 'score' => 0], 10000));
        $this->assertSame('score', $r->exitReason(['active_days' => 2, 'score' => 10000], 10000));
        $this->assertSame('days', $r->exitReason(['active_days' => 20, 'score' => 99999], 10000), 'days checked first');
    }

    public function testGracePeriodTiming(): void
    {
        $r = $this->rules();
        $ended = '2026-10-01 00:00:00+00';
        $t = strtotime($ended);
        $ship = ['protection_state' => 'grace', 'protection_ended_at' => $ended];
        $this->assertFalse($r->graceOver($ship, $t + 12 * 3600 - 1));
        $this->assertTrue($r->graceOver($ship, $t + 12 * 3600));
        $this->assertFalse($r->graceOver(['protection_state' => 'protected', 'protection_ended_at' => $ended], $t + 99999));
    }

    public function testActiveDayCounterCountsLoginDaysNotCalendarDays(): void
    {
        $r = $this->rules();
        $this->assertSame(1, $r->nextActiveDays(0, null, '2026-10-01'));
        $this->assertSame(1, $r->nextActiveDays(1, '2026-10-01', '2026-10-01'), 'a second login the same day adds nothing');
        $this->assertSame(2, $r->nextActiveDays(1, '2026-10-01', '2026-10-02'));
        $this->assertSame(3, $r->nextActiveDays(2, '2026-10-02', '2026-10-09'), 'days away do not count, and do not reset');
    }

    public function testMismatchBoundary(): void
    {
        $r = $this->rules();
        $this->assertTrue($r->isMismatch(10000, 2499));
        $this->assertFalse($r->isMismatch(10000, 2500), 'exactly 25% is not a mismatch');
        $this->assertFalse($r->isMismatch(10000, 9000));
        $this->assertFalse($r->isMismatch(0, 0), 'no score, no mismatch');
        $this->assertTrue($this->rules(['alignment' => ['mismatch_ratio' => 0.5]])->isMismatch(10000, 4000));
    }

    public function testProgressTextMatchesTheSpecExample(): void
    {
        $r = $this->rules();
        $p = $r->progress(['protection_state' => 'protected', 'active_days' => 6, 'score' => 4210], 10000);
        $this->assertSame('Protected: 6 of 14 active days, score 4,210 of 10,000', $p['text']);
        $this->assertSame('days', $p['closest'], '43% of the days vs 42% of the score: days is slightly ahead');
    }

    public function testProgressClosestCondition(): void
    {
        $r = $this->rules();
        $this->assertSame('days', $r->progress(['protection_state' => 'protected', 'active_days' => 12, 'score' => 100], 10000)['closest']);
        $this->assertSame('score', $r->progress(['protection_state' => 'protected', 'active_days' => 1, 'score' => 9000], 10000)['closest']);
        $g = $r->progress(['protection_state' => 'grace', 'protection_ended_at' => '2026-10-01 00:00:00+00'], 10000);
        $this->assertContains('can now be attacked', $g['text']);
        $this->assertTrue($g['grace_ends_at'] !== null);
    }
}
