<?php

declare(strict_types=1);

namespace BNT\Tests;

class ProtectionTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(30);
    }

    private function prot(): \BNT\Services\ProtectionService
    {
        return $this->svc('protectionService');
    }

    /** A freshly registered ship exactly as the web/API registration creates it. */
    private function rookie(string $name, array $over = []): int
    {
        $id = $this->svc('shipModel')->register(strtolower(str_replace(' ', '', $name)) . '@x.test', 'password123', $name, self::$config['game']);
        $sets = ['sector = :sector'];
        $params = ['id' => $id, 'sector' => $over['sector'] ?? 6];
        unset($over['sector']);
        foreach ($over as $k => $v) {
            $sets[] = "$k = :$k";
            $params[$k] = $v;
        }
        $this->db()->execute('UPDATE ships SET ' . implode(', ', $sets) . ' WHERE ship_id = :id', $params);
        return $id;
    }

    private function veteran(string $name, array $over = []): int
    {
        return $this->makePlayer($name, $this->strong(['sector' => 6]) + $over);
    }

    public function testNewShipsStartProtectedExistingAndNpcShipsDoNot(): void
    {
        $new = $this->rookie('Rookie');
        $this->assertSame('protected', $this->ship($new)['protection_state']);
        $this->assertTrue($this->prot()->isProtected($this->ship($new)));
        $vet = $this->makePlayer('Vet');
        $this->assertSame('none', $this->ship($vet)['protection_state']);
        $npc = $this->svc('npcService')->spawn('guild', ['sector' => 6]);
        $this->assertSame('none', $this->ship($npc['ship_id'])['protection_state']);
        $this->assertFalse($this->prot()->isProtected($this->ship($npc['ship_id'])));
    }

    public function testNoCodePathLetsAVeteranOrAnNpcDamageAProtectedShip(): void
    {
        $rookie = $this->rookie('Rookie');
        $vet = $this->veteran('Veteran');
        $armorBefore = (int)$this->ship($rookie)['armor_pts'];
        $r = $this->svc('combatService')->attackShip($this->ship($vet), $rookie);
        $this->assertFalse($r['success']);
        $this->assertSame('PROTECTED_TARGET', $r['code']);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM attack_logs')['c']);
        $this->assertSame(0, $this->alignmentOf($vet), 'a blocked attempt costs nothing');
        $this->assertSame($armorBefore, (int)$this->ship($rookie)['armor_pts']);
        // Raider NPCs treat protected ships as invisible.
        $raider = $this->svc('npcService')->spawn('xenobe', ['sector' => 6]);
        $this->db()->execute('UPDATE ships SET sector = 7 WHERE ship_id = :id', ['id' => $vet]);
        $this->svc('npcBrain')->tick($this->svc('npcService')->profile($raider['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM attack_logs WHERE attacker_id = :a', ['a' => $raider['ship_id']])['c']);
        $this->assertFalse((bool)$this->ship($rookie)['ship_destroyed']);
        // Even a Wanted-hunting police ship cannot touch one.
        $police = $this->svc('npcService')->spawn('police', ['sector' => 6]);
        $this->assertSame('PROTECTED_TARGET', $this->svc('combatService')->attackShip($this->ship($police['ship_id']), $rookie)['code']);
    }

    public function testOnlyTheFirstThreePlanetsAreCovered(): void
    {
        $rookie = $this->rookie('Rookie', ['sector' => 6]);
        $vet = $this->veteran('Veteran');
        for ($i = 1; $i <= 4; $i++) {
            $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, fighters) VALUES (:n, 6, :o, 0)", ['n' => "P$i", 'o' => $rookie]);
        }
        $ids = array_map(fn($r) => (int)$r['planet_id'], $this->db()->fetchAll('SELECT planet_id FROM planets ORDER BY planet_id'));
        for ($i = 0; $i < 3; $i++) {
            $r = $this->svc('combatService')->attackPlanet($this->ship($vet), $ids[$i]);
            $this->assertSame('PROTECTED_TARGET', $r['code'], 'planet ' . ($i + 1) . ' is covered');
        }
        $r = $this->svc('combatService')->attackPlanet($this->ship($vet), $ids[3]);
        $this->assertTrue($r['success'], 'the fourth planet is fair game: ' . $r['text']);
        $this->assertTrue($r['data']['captured']);
    }

    public function testAggressiveActsEndProtectionAfterConfirmation(): void
    {
        $rookie = $this->rookie('Rookie', ['sector' => 8, 'beams' => 12, 'ship_fighters' => 500, 'torps' => 40]);
        $victim = $this->makePlayer('Victim', ['sector' => 8]);
        $svc = $this->svc('combatService');
        $r = $svc->attackShip($this->ship($rookie), $victim);
        $this->assertFalse($r['success']);
        $this->assertSame('PROTECTION_CONFIRM', $r['code'], 'a warning first');
        $this->assertTrue($this->prot()->isProtectedId($rookie), 'nothing happened yet');
        $r = $svc->deployDefence($this->ship($rookie), 'M', 5);
        $this->assertSame('PROTECTION_CONFIRM', $r['code']);
        $r = $svc->attackShip($this->ship($rookie), $victim, true);
        $this->assertTrue($r['success'], $r['text']);
        $this->assertFalse($this->prot()->isProtectedId($rookie), 'attacking ended protection immediately');
        $this->assertSame('none', $this->ship($rookie)['protection_state']);

        $second = $this->rookie('Second', ['sector' => 9, 'torps' => 40]);
        $this->assertTrue($svc->deployDefence($this->ship($second), 'M', 5, true)['success']);
        $this->assertSame('none', $this->ship($second)['protection_state'], 'deploying mines ends it too');
        $third = $this->rookie('Third', ['sector' => 10]);
        $this->assertTrue($this->prot()->optOut($third));
        $this->assertSame('none', $this->ship($third)['protection_state'], 'opting out is immediate and permanent');
        $this->assertFalse($this->prot()->optOut($third), 'nothing left to give up');
    }

    public function testAProtectedShipCannotScoutThroughMinefields(): void
    {
        $owner = $this->veteran('Owner', ['ship_fighters' => 500, 'torps' => 20]);
        $this->svc('combatService')->deployDefence($this->ship($owner), 'F', 100);
        $rookie = $this->rookie('Scout', ['sector' => 5, 'turns' => 50]);
        $r = $this->svc('movementService')->move($this->ship($rookie), 6);
        $this->assertFalse($r['success']);
        $this->assertSame('DEFENCES_BLOCK_PROTECTED', $r['code']);
        $this->assertSame(5, (int)$this->ship($rookie)['sector'], 'turned back');
        $this->assertSame(50, (int)$this->ship($rookie)['turns'], 'no turn spent');
        // Team-mates' defences do not block.
        $this->db()->execute("INSERT INTO teams (id, team_name, creator) VALUES (1, 'T', :c)", ['c' => $owner]);
        $this->db()->execute('UPDATE ships SET team = 1 WHERE ship_id IN (:a, :b)', ['a' => $owner, 'b' => $rookie]);
        $this->assertTrue($this->svc('movementService')->move($this->ship($rookie), 6)['success']);
        // And defences never damage a protected ship that is somehow inside.
        $this->db()->execute('UPDATE ships SET team = 0 WHERE ship_id = :b', ['b' => $rookie]);
        $combat = $this->svc('combatModel');
        $this->assertFalse($combat->checkSectorFighters($this->ship($rookie), 6)['attacked']);
        $this->assertFalse($combat->checkMines($rookie, 6, 12, 0)['hit']);
    }

    public function testNoDefenceWallForPlanetCaptureByAProtectedShip(): void
    {
        $owner = $this->veteran('Owner', ['ship_fighters' => 500]);
        $this->svc('combatService')->deployDefence($this->ship($owner), 'F', 50);
        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, fighters) VALUES ('Weak', 6, NULL, 0)");
        $rookie = $this->rookie('Rookie', ['sector' => 6, 'beams' => 12, 'ship_fighters' => 500]);
        $r = $this->svc('combatService')->attackPlanet($this->ship($rookie), 1, true);
        $this->assertSame('PROTECTED_NO_DEFENCE_WALL', $r['code']);
        $this->assertTrue($this->prot()->isProtectedId($rookie), 'and the refusal does not cost the shield');
    }

    public function testExitByScoreUsesAPercentageOfTheActiveMedianWithAFloor(): void
    {
        foreach ([200000, 220000, 240000, 260000, 280000] as $i => $score) {
            $this->makePlayer("Vet$i", ['score' => $score]);
        }
        $this->assertSame(60000, $this->prot()->threshold(), '25% of the median 240,000');
        $rookie = $this->rookie('Rookie', ['active_days' => 3]);
        $this->db()->execute('UPDATE ships SET score = 59000 WHERE ship_id = :id', ['id' => $rookie]);
        // tick recalculates the score from assets; give the ship real wealth below the line first.
        $this->db()->execute('UPDATE ships SET credits = 100 WHERE ship_id = :id', ['id' => $rookie]);
        $this->prot()->tick();
        $this->assertSame('protected', $this->ship($rookie)['protection_state']);
        $this->db()->execute('UPDATE ships SET credits = 5000000000 WHERE ship_id = :id', ['id' => $rookie]);   // score = sqrt(total) > 60,000
        $out = $this->prot()->tick();
        $this->assertSame(1, $out['ended']);
        $this->assertSame('grace', $this->ship($rookie)['protection_state']);
        // Floor on a tiny server.
        $this->db()->execute('DELETE FROM ships WHERE character_name LIKE \'Vet%\'');
        $this->assertSame(10000, $this->prot()->threshold());
    }

    public function testExitByActiveDaysThenGraceWarningNewsAndMessage(): void
    {
        $rookie = $this->rookie('Rookie', ['active_days' => 14]);
        $this->prot()->tick();
        $ship = $this->ship($rookie);
        $this->assertSame('grace', $ship['protection_state']);
        $this->assertTrue($ship['protection_ended_at'] !== null);
        $this->assertFalse($this->prot()->isProtected($ship), 'attackable during the warning period');
        $news = $this->db()->fetchOne("SELECT * FROM news WHERE news_type = 'protection'");
        $this->assertContains('Rookie', $news['newstext']);
        $msg = $this->db()->fetchOne('SELECT m.*, s.character_name FROM messages m JOIN ships s ON s.ship_id = m.from_id WHERE m.to_id = :t', ['t' => $rookie]);
        $this->assertContains('protection has ended', $msg['subject']);
        $this->assertContains('Talia Venn', $msg['character_name'], 'the Courier sends the warning');
        // After 12 hours the grace period is over for good.
        $this->prot()->tick();
        $this->assertSame('grace', $this->ship($rookie)['protection_state'], 'not yet');
        $this->db()->execute("UPDATE ships SET protection_ended_at = now() - interval '13 hours' WHERE ship_id = :id", ['id' => $rookie]);
        $this->assertSame(1, $this->prot()->tick()['graces_over']);
        $this->assertSame('none', $this->ship($rookie)['protection_state']);
        // And it can be attacked now.
        $vet = $this->veteran('Vet');
        $this->assertTrue($this->svc('combatService')->attackShip($this->ship($vet), $rookie)['success']);
    }

    public function testActiveDaysCountLoginDays(): void
    {
        $rookie = $this->rookie('Rookie');
        $ship = $this->ship($rookie);
        $this->prot()->markActive($ship);
        $this->prot()->markActive($this->ship($rookie));
        $this->assertSame(1, (int)$this->ship($rookie)['active_days'], 'same day counts once');
        $this->db()->execute("UPDATE ships SET last_active_date = CURRENT_DATE - 9 WHERE ship_id = :id", ['id' => $rookie]);
        $this->prot()->markActive($this->ship($rookie));
        $this->assertSame(2, (int)$this->ship($rookie)['active_days'], 'days away do not count');
        // Authenticating counts too.
        $this->db()->execute("UPDATE ships SET last_active_date = CURRENT_DATE - 1 WHERE ship_id = :id", ['id' => $rookie]);
        $this->svc('shipModel')->authenticate($this->ship($rookie)['email'], 'password123');
        $this->assertSame(3, (int)$this->ship($rookie)['active_days']);
        // Veterans are not touched.
        $vet = $this->makePlayer('Vet');
        $this->prot()->markActive($this->ship($vet));
        $this->assertSame(0, (int)$this->ship($vet)['active_days']);
    }

    public function testRespawnShieldOncePerSevenDaysAndEndsOnAggression(): void
    {
        $vet = $this->makePlayer('Vet');
        $this->assertTrue($this->prot()->grantRespawnShield($vet));
        $ship = $this->ship($vet);
        $this->assertTrue($this->prot()->isProtected($ship), 'shielded after a respawn');
        $hours = (strtotime($ship['respawn_shield_until']) - time()) / 3600;
        $this->assertTrue($hours > 23.9 && $hours <= 24);
        $this->assertFalse($this->prot()->grantRespawnShield($vet), 'once per 7 days');
        $this->db()->execute("UPDATE ships SET last_respawn_shield_at = now() - interval '8 days' WHERE ship_id = :id", ['id' => $vet]);
        $this->assertTrue($this->prot()->grantRespawnShield($vet));
        // A shielded veteran who attacks loses the shield.
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 500, torps = 20, sector = 8 WHERE ship_id = :id', ['id' => $vet]);
        $victim = $this->makePlayer('Victim', ['sector' => 8]);
        $this->assertSame('PROTECTION_CONFIRM', $this->svc('combatService')->attackShip($this->ship($vet), $victim)['code']);
        $this->assertTrue($this->svc('combatService')->attackShip($this->ship($vet), $victim, true)['success']);
        $this->assertFalse($this->prot()->isProtected($this->ship($vet)));
        // NPCs never receive one.
        $npc = $this->svc('npcService')->spawn('free', ['sector' => 6]);
        $this->assertFalse($this->prot()->isProtected(['protection_state' => 'none', 'is_npc' => true, 'respawn_shield_until' => date('c', time() + 3600)]));
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM ships WHERE ship_id = :i AND respawn_shield_until IS NOT NULL', ['i' => $npc['ship_id']])['c']);
    }

    public function testRespawnFromAnEscapePodThroughLoginGrantsTheShield(): void
    {
        $vet = $this->makePlayer('Pod');
        $this->db()->execute('UPDATE ships SET ship_destroyed = TRUE, dev_escapepod = TRUE WHERE ship_id = :id', ['id' => $vet]);
        $this->svc('shipModel')->grantRespawnShield($vet, 24, 7);   // what the login controllers call after rebuilding the ship
        $this->assertTrue($this->prot()->isProtected($this->ship($vet)));
    }

    public function testCreditTransfersBetweenProtectedAndOthersAreCapped(): void
    {
        $rookie = $this->rookie('Rookie');
        $vet = $this->makePlayer('Vet');
        $rookie2 = $this->rookie('Rookie Two');
        foreach ([$rookie, $vet, $rookie2] as $id) {
            $this->db()->execute('UPDATE ibank_accounts SET balance = 1000000 WHERE ship_id = :id', ['id' => $id]);
        }
        $ibank = new \BNT\Models\IBank($this->db());
        $ibank->setProtection($this->prot());
        $this->assertTrue($ibank->transfer($vet, $rookie, 30000, 0.0)['success']);
        $r = $ibank->transfer($vet, $rookie, 30000, 0.0);
        $this->assertFalse($r['success']);
        $this->assertContains('capped at 50,000', $r['error']);
        $this->assertContains('20,000 left', $r['error']);
        $this->assertTrue($ibank->transfer($rookie, $vet, 20000, 0.0)['success'], 'the cap counts both directions together');
        $this->assertFalse($ibank->transfer($rookie, $vet, 1, 0.0)['success']);
        // Two protected accounts, or two veterans, are not restricted by this rule.
        $this->assertTrue($ibank->transfer($rookie, $rookie2, 90000, 0.0)['success']);
        $vet2 = $this->makePlayer('Vet2');
        $this->db()->execute('UPDATE ibank_accounts SET balance = 1000000 WHERE ship_id = :id', ['id' => $vet2]);
        $this->assertTrue($ibank->transfer($vet, $vet2, 500000, 0.0)['success']);
        // Yesterday's transfers do not count.
        $this->db()->execute("UPDATE igb_transfers SET transfer_time = now() - interval '2 days'");
        $this->assertTrue($ibank->transfer($vet, $rookie, 40000, 0.0)['success']);
    }

    public function testATeamHoldsAtMostTwoProtectedMembers(): void
    {
        $leader = $this->makePlayer('Leader');
        $this->db()->execute("INSERT INTO teams (id, team_name, creator) VALUES (1, 'Crew', :c)", ['c' => $leader]);
        $this->db()->execute('UPDATE ships SET team = 1 WHERE ship_id = :id', ['id' => $leader]);
        $team = new \BNT\Models\Team($this->db());
        $team->setProtection($this->prot());
        $a = $this->rookie('Rookie A');
        $b = $this->rookie('Rookie B');
        $c = $this->rookie('Rookie C');
        $this->assertTrue($team->addMember($a, 1));
        $this->assertTrue($team->addMember($b, 1));
        $this->assertFalse($team->addMember($c, 1), 'a third protected member is refused');
        $vet = $this->makePlayer('Vet Joiner');
        $this->assertTrue($team->addMember($vet, 1), 'veterans are not limited');
        // Once one protected member leaves or loses protection, there is room.
        $this->prot()->optOut($a);
        $this->assertTrue($team->addMember($c, 1));
    }

    public function testMismatchDoublesAlignmentPenaltiesAndIsReportedInTheNews(): void
    {
        $big = $this->veteran('Bigfoot', ['score' => 10000]);
        $small = $this->makePlayer('Minnow', ['sector' => 6, 'score' => 2000]);
        $r = $this->svc('combatService')->attackShip($this->ship($big), $small);
        $this->assertSame('destroyed', $r['data']['outcome']);
        $this->assertSame(-700, $this->alignmentOf($big), '(-100 and -250) doubled');
        $this->assertSame(1, $this->logCount($big, 'attacked_lawful_mismatch'));
        $this->assertSame(1, $this->logCount($big, 'destroyed_lawful_mismatch'));
        $this->assertSame(1, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM news WHERE news_type = 'mismatch'")['c']);
        // At 25% or more the ordinary penalties apply.
        $peer = $this->veteran('Peer', ['score' => 10000]);
        $near = $this->makePlayer('Nearly', ['sector' => 6, 'score' => 2500]);
        $this->svc('combatService')->attackShip($this->ship($peer), $near);
        $this->assertSame(-350, $this->alignmentOf($peer));
        // Hunting a much weaker outlaw is still rewarded: only penalties double.
        $hunter = $this->veteran('Hunter', ['score' => 10000]);
        $weakOutlaw = $this->makePlayer('WeakOutlaw', ['sector' => 6, 'score' => 100, 'alignment' => -500]);
        $this->svc('combatService')->attackShip($this->ship($hunter), $weakOutlaw);
        $this->assertSame(200, $this->alignmentOf($hunter));
    }

    public function testMultiAccountSignalsAreFlaggedWithoutAction(): void
    {
        $a = $this->rookie('Alt One');
        $b = $this->rookie('Alt Two');
        $c = $this->rookie('Stranger');
        $this->svc('shipModel')->recordSignup($a, '203.0.113.7', 'dev-1');
        $this->svc('shipModel')->recordSignup($b, '203.0.113.7', 'dev-2');
        $this->svc('shipModel')->recordSignup($c, '198.51.100.2', 'dev-1');
        $flags = $this->prot()->multiAccountFlags();
        $kinds = array_map(fn($f) => $f['kind'] . ':' . $f['value'], $flags);
        $this->assertTrue(in_array('ip:203.0.113.7', $kinds, true));
        $this->assertTrue(in_array('device:dev-1', $kinds, true));
        $this->assertFalse(in_array('ip:198.51.100.2', $kinds, true), 'a single account is not a signal');
        $this->assertSame('protected', $this->ship($a)['protection_state'], 'flagging takes no automatic action');
    }

    public function testApiFacingStateAndSwitch(): void
    {
        $rookie = $this->rookie('Rookie', ['active_days' => 6, 'score' => 4210]);
        $p = $this->prot()->progress($this->ship($rookie));
        $this->assertSame('protected', $p['state']);
        $this->assertSame(14, $p['active_days_needed']);
        $this->assertTrue($p['protected']);
        $view = $this->svc('alignmentService')->publicView($this->ship($rookie));
        $this->assertTrue($view['protected']);
        $this->assertTrue(!array_key_exists('protection_state', $view), 'internals stay private');
        // Master switch off: nobody is protected.
        $cfg = self::$config;
        $cfg['protection']['enabled'] = false;
        $off = new \BNT\Services\ProtectionRules($cfg);
        $this->assertFalse($off->isProtected($this->ship($rookie)));
    }
}
