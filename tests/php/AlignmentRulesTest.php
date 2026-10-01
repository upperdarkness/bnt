<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Services\AlignmentRules;

class AlignmentRulesTest extends TestCase
{
    private function rules(array $override = []): AlignmentRules
    {
        static $config = null; // config.php hashes a password on load; do it once
        $config ??= require dirname(__DIR__, 2) . '/config/config.php';
        return new AlignmentRules(array_replace_recursive($config, $override));
    }

    public function testTierBoundaries(): void
    {
        $r = $this->rules();
        $cases = [
            10000 => 'paragon', 1000 => 'paragon', 999 => 'lawful', 100 => 'lawful', 99 => 'neutral', 0 => 'neutral',
            -99 => 'neutral', -100 => 'outlaw', -999 => 'outlaw', -1000 => 'pirate', -10000 => 'pirate',
        ];
        foreach ($cases as $value => $tier) {
            $this->assertSame($tier, $r->tier($value), "tier($value)");
        }
    }

    public function testThresholdsComeFromConfigNotCode(): void
    {
        $r = $this->rules(['alignment' => ['tiers' => ['paragon' => 2000]]]);
        $this->assertSame('lawful', $r->tier(1500));
        $this->assertSame('paragon', $r->tier(2000));
    }

    public function testLawAbidingAndOutlawSplit(): void
    {
        $r = $this->rules();
        $this->assertTrue($r->isLawAbiding(-99));
        $this->assertFalse($r->isLawAbiding(-100));
        $this->assertTrue($r->isOutlaw(-100));
        $this->assertTrue($r->isPirate(-1000));
        $this->assertFalse($r->isPirate(-999));
    }

    public function testClamp(): void
    {
        $r = $this->rules();
        $this->assertSame(10000, $r->clamp(15000));
        $this->assertSame(-10000, $r->clamp(-10001));
        $this->assertSame(42, $r->clamp(42));
    }

    public function testEveryDeltaInTheSpecTable(): void
    {
        $r = $this->rules();
        $lawful = ['alignment' => 0, 'is_npc' => false, 'team' => 0];
        $outlaw = ['alignment' => -500, 'is_npc' => false, 'team' => 0];
        $me = ['team' => 0];

        // Attack a Neutral-or-better ship: -100 (win or lose); destroy adds -250.
        $this->assertSame([[-100, 'attacked_lawful']], $r->attackDeltas($me, $lawful, false, false));
        $this->assertSame([[-100, 'attacked_lawful'], [-250, 'destroyed_lawful']], $r->attackDeltas($me, $lawful, true, false));
        // Attack / destroy an Outlaw or Pirate: +50 / +150.
        $this->assertSame([[50, 'attacked_outlaw']], $r->attackDeltas($me, $outlaw, false, false));
        $this->assertSame([[50, 'attacked_outlaw'], [150, 'destroyed_outlaw']], $r->attackDeltas($me, $outlaw, true, false));
        // Boundary: exactly -99 is still "Neutral or better".
        $this->assertSame('attacked_lawful', $r->attackDeltas($me, ['alignment' => -99, 'is_npc' => false, 'team' => 0], false, false)[0][1]);
        $this->assertSame('attacked_outlaw', $r->attackDeltas($me, ['alignment' => -100, 'is_npc' => false, 'team' => 0], false, false)[0][1]);
        // NPC rows replace the generic destroy row.
        $pirateNpc = ['alignment' => -3000, 'is_npc' => true, 'faction' => 'xenobe', 'team' => 0];
        $this->assertSame([[50, 'attacked_outlaw'], [100, 'destroyed_pirate_npc']], $r->attackDeltas($me, $pirateNpc, true, false));
        foreach (['police', 'guild', 'free'] as $f) {
            $traderNpc = ['alignment' => 500, 'is_npc' => true, 'faction' => $f, 'team' => 0];
            $this->assertSame([[-100, 'attacked_lawful'], [-300, 'destroyed_trader_npc']], $r->attackDeltas($me, $traderNpc, true, false), $f);
        }
        // Planet captures: -150 from lawful, +50 from outlaw owners; unowned planets change nothing.
        $this->assertSame([-150, 'captured_lawful_planet'], $r->captureDelta($lawful, $me));
        $this->assertSame([50, 'captured_outlaw_planet'], $r->captureDelta($outlaw, $me));
        $this->assertSame(null, $r->captureDelta(null, $me));
        // FedSpace hostile action and fine values.
        $this->assertSame(-500, $r->delta('fedspace_hostile'));
    }

    public function testTeamMatesNeverChangeAlignment(): void
    {
        $r = $this->rules();
        $this->assertSame([], $r->attackDeltas(['team' => 7], ['alignment' => 800, 'is_npc' => false, 'team' => 7], true, false));
        $this->assertSame(null, $r->captureDelta(['alignment' => 800, 'team' => 7], ['team' => 7]));
        // Team 0 means "no team": two teamless ships are not team-mates.
        $this->assertTrue(count($r->attackDeltas(['team' => 0], ['alignment' => 0, 'is_npc' => false, 'team' => 0], false, false)) > 0);
    }

    public function testDriftRoundingAndMinimumStep(): void
    {
        $r = $this->rules();
        $this->assertSame(0, $r->drift(0));
        $this->assertSame(0, $r->drift(1), 'min step 1 reaches zero');
        $this->assertSame(0, $r->drift(-1));
        $this->assertSame(49, $r->drift(50), '1% of 50 rounds to 1 (min step 1)');
        $this->assertSame(-49, $r->drift(-50));
        $this->assertSame(990, $r->drift(1000), '1% of 1000 = 10');
        $this->assertSame(-2970, $r->drift(-3000));
        $this->assertSame(9900, $r->drift(10000));
        $this->assertSame(1, $r->drift(2), '1% of 2 = 0.02 -> min step 1');
        $this->assertSame(-1, $r->drift(-2));
        $this->assertSame(0, $r->drift(1), 'never overshoots zero');
        // 150 * 1% = 1.5 rounds half away from zero to 2, same as the SQL ROUND().
        $this->assertSame(148, $r->drift(150));
        $this->assertSame(-148, $r->drift(-150));
        $faster = $this->rules(['alignment' => ['drift_pct_per_day' => 10]]);
        $this->assertSame(900, $faster->drift(1000));
    }

    public function testFedSpaceCombatTable(): void
    {
        $r = $this->rules();
        $lawful = ['alignment' => 100, 'is_police' => false];
        $outlaw = ['alignment' => -300, 'is_police' => false];
        $pirate = ['alignment' => -2000, 'is_police' => false];
        $police = ['alignment' => 5000, 'is_police' => true];

        // Anyone vs Neutral or better in FedSpace: blocked, with the existing safe-zone message.
        foreach ([$lawful, $outlaw, $pirate] as $attacker) {
            $d = $r->combatDecision($attacker, ['alignment' => 0, 'wanted' => false], false, true);
            $this->assertFalse($d['allowed']);
            $this->assertSame('Combat is not allowed in starbase sectors', $d['message']);
        }
        // Neutral or better vs Outlaw/Pirate: allowed, no Wanted.
        $d = $r->combatDecision($lawful, ['alignment' => -300, 'wanted' => false], false, true);
        $this->assertTrue($d['allowed']);
        $this->assertFalse($d['fedspace_offence']);
        // Outlaw/Pirate vs Outlaw/Pirate: allowed with -500 and Wanted.
        $d = $r->combatDecision($outlaw, ['alignment' => -2000, 'wanted' => true], false, true);
        $this->assertTrue($d['allowed']);
        $this->assertTrue($d['fedspace_offence']);
        // Police vs a Wanted ship: always allowed, even when the target is still Neutral or better.
        $d = $r->combatDecision($police, ['alignment' => 50, 'wanted' => true], false, true);
        $this->assertTrue($d['allowed']);
        $this->assertFalse($d['fedspace_offence']);
        // Police vs a lawful ship that is not Wanted: blocked.
        $this->assertFalse($r->combatDecision($police, ['alignment' => 50, 'wanted' => false], false, true)['allowed']);
        // Outside FedSpace anything goes; unowned things in FedSpace are protected.
        $this->assertTrue($r->combatDecision($pirate, ['alignment' => 900, 'wanted' => false], false, false)['allowed']);
        $this->assertFalse($r->combatDecision($pirate, null, false, true)['allowed']);
    }

    public function testStarbaseBlocksEveryoneIncludingPolice(): void
    {
        $r = $this->rules();
        $police = ['alignment' => 5000, 'is_police' => true];
        $this->assertFalse($r->combatDecision($police, ['alignment' => -5000, 'wanted' => true], true, true)['allowed']);
        $this->assertFalse($r->combatDecision($police, ['alignment' => -5000, 'wanted' => true], true, false)['allowed']);
        $this->assertFalse($r->combatDecision(['alignment' => -5000, 'is_police' => false], ['alignment' => -5000, 'wanted' => true], true, false)['allowed']);
    }

    public function testDefencesInFedSpace(): void
    {
        $r = $this->rules();
        $this->assertTrue($r->mayDeployInFedSpace(-99));
        $this->assertFalse($r->mayDeployInFedSpace(-100), 'below Neutral cannot deploy');
        // Defences act in FedSpace only against outlaws and only for law-abiding owners.
        $this->assertTrue($r->defenceMayAct(true, 0, -500));
        $this->assertFalse($r->defenceMayAct(true, 0, 50), 'protected ship');
        $this->assertFalse($r->defenceMayAct(true, -500, -500), 'outlaw-owned defences are inert in FedSpace');
        $this->assertTrue($r->defenceMayAct(false, -500, 800), 'no restriction outside FedSpace');
    }

    public function testFineAndBountyAndPolicePricing(): void
    {
        $r = $this->rules();
        $this->assertSame(10000, $r->fineAmount(-50), 'minimum fine');
        $this->assertSame(50000, $r->fineAmount(-500));
        $this->assertSame(-99, $r->fineTarget(-800));
        $this->assertSame(-50, $r->fineTarget(-50), 'a player above the floor is not pushed down');
        // 1,000 credits per 100 points below 0.
        $this->assertSame(5000, $r->federationBounty(-500));
        $this->assertSame(30000, $r->federationBounty(-3000));
        $this->assertSame(1000, $r->federationBounty(-10), 'at least one unit');
        // One police unit per 500 points below 0: minimum 1, cap 5.
        $this->assertSame(1, $r->policeCount(-100));
        $this->assertSame(2, $r->policeCount(-1000));
        $this->assertSame(5, $r->policeCount(-9000));
        $this->assertSame(5, $r->policeCount(-10000));
    }

    public function testStarbasePricingAndRefusal(): void
    {
        $r = $this->rules();
        $this->assertSame(0.95, $r->starbasePriceFactor(1000));
        $this->assertSame(1.0, $r->starbasePriceFactor(999));
        $this->assertSame(1.0, $r->starbasePriceFactor(-99));
        $this->assertSame(1.15, $r->starbasePriceFactor(-100));
        $this->assertSame(1.0, $r->starbasePriceFactor(-1000), 'pirates are refused, not surcharged');
        $this->assertTrue($r->refusedAtStarbase(-1000));
        $this->assertFalse($r->refusedAtStarbase(-999));
        $this->assertSame(1.25, $r->bountyPayoutFactor(1000));
        $this->assertSame(1.0, $r->bountyPayoutFactor(999));
    }
}
