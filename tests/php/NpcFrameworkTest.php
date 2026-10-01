<?php

declare(strict_types=1);

namespace BNT\Tests;

class NpcFrameworkTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(30);
    }

    private function npcs(): \BNT\Services\NpcService
    {
        return $this->svc('npcService');
    }

    public function testNpcAccountsCannotLogInAndTokensAreStoredHashed(): void
    {
        $n = $this->npcs()->spawn('guild', ['sector' => 5, 'name' => 'Hauler Pete']);
        $ship = $this->ship($n['ship_id']);
        $this->assertSame('[Guild] Hauler Pete', $ship['character_name']);
        $this->assertSame('guild-hauler-pete@npc.invalid', $ship['email']);
        $this->assertTrue((bool)$ship['is_npc']);
        $this->assertSame(null, $this->svc('shipModel')->authenticate($ship['email'], ''), 'empty password');
        $this->assertSame(null, $this->svc('shipModel')->authenticate($ship['email'], $ship['password_hash']), 'the hash itself is not a password');
        $this->assertSame(null, $this->svc('shipModel')->authenticate($ship['email'], 'password123'));
        $this->assertFalse(password_verify('anything', $ship['password_hash']));

        $token = $this->npcs()->issueToken($n['ship_id']);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM api_tokens WHERE token_hash = :t', ['t' => $token['token']])['c'], 'raw token is not stored');
        $this->assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM api_tokens WHERE token_hash = :t', ['t' => hash('sha256', $token['token'])])['c']);
        $days = (strtotime($token['expires_at']) - time()) / 86400;
        $this->assertTrue($days > 29 && $days < 31, 'NPC tokens rotate monthly');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue($this->svc('apiAuth')->validateToken($token['token']) !== null);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertSame(null, $this->svc('apiAuth')->validateToken($token['token']), 'accepted only from the worker\'s source IP');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        // Re-issuing revokes the previous token.
        $again = $this->npcs()->issueToken($n['ship_id']);
        $this->assertSame(null, $this->svc('apiAuth')->validateToken($token['token']));
        $this->assertTrue($this->svc('apiAuth')->validateToken($again['token']) !== null);
    }

    public function testFactionDefaultsAndPirateRaidersStartWanted(): void
    {
        $want = ['police' => 5000, 'guild' => 500, 'xenobe' => -3000, 'free' => 0];
        foreach ($want as $faction => $alignment) {
            $n = $this->npcs()->spawn($faction, ['sector' => 5]);
            $this->assertSame($alignment, $this->alignmentOf($n['ship_id']), $faction);
            $this->assertTrue($this->logCount($n['ship_id'], 'npc_spawn') >= ($alignment === 0 ? 0 : 1), 'initial alignment is logged');
        }
        $raider = $this->db()->fetchOne("SELECT ship_id FROM npc_profiles WHERE faction = 'xenobe'");
        $this->assertTrue($this->svc('alignmentService')->isWanted($this->ship((int)$raider['ship_id'])));
        $profile = $this->npcs()->profile((int)$raider['ship_id']);
        $this->assertSame('scripted', $profile['controller']);
        $this->assertSame('raider', $profile['archetype']);
        $this->assertSame('', $profile['notebook']);
    }

    public function testPopulationScalesWithUniverseSizeAndIsTopUpOnly(): void
    {
        $this->assertSame(4, $this->npcs()->targetCount('police', 1000));
        $this->assertSame(10, $this->npcs()->targetCount('guild', 1000));
        $this->assertSame(6, $this->npcs()->targetCount('xenobe', 1000));
        $this->assertSame(24, $this->npcs()->targetCount('police', 6000));
        $this->assertSame(1, $this->npcs()->targetCount('police', 30), 'always at least one per faction in a non-empty universe');
        $this->assertSame(0, $this->npcs()->targetCount('police', 0));

        $tasks = $this->svc('npcTasks');
        $tasks->population();
        $first = (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM npc_profiles')['c'];
        $this->assertSame(4, $first, 'one per faction in a 30-sector universe');
        $tasks->population();
        $this->assertSame($first, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM npc_profiles')['c'], 'idempotent');
    }

    public function testDeathCooldownThenRespawnKeepsPersonaAndNotebook(): void
    {
        $n = $this->npcs()->spawn('xenobe', ['sector' => 6]);
        $id = $n['ship_id'];
        $this->db()->execute("UPDATE npc_profiles SET notebook = 'Vex killed me. Remember.' WHERE ship_id = :id", ['id' => $id]);
        $persona = $this->npcs()->profile($id)['persona'];
        $this->svc('combatModel')->destroyShip($id);
        $this->db()->execute('UPDATE ships SET credits = 5 WHERE ship_id = :id', ['id' => $id]);
        $this->svc('npcTasks')->population();
        $profile = $this->npcs()->profile($id);
        $this->assertTrue($profile['respawn_at'] !== null, 'respawn cooldown started');
        $hours = (strtotime($profile['respawn_at']) - time()) / 3600;
        $this->assertTrue($hours > 5.9 && $hours <= 6.0, 'default cooldown is 6 hours');
        $this->assertTrue((bool)$this->ship($id)['ship_destroyed'], 'still dead during the cooldown');
        $this->db()->execute("UPDATE npc_profiles SET respawn_at = now() - interval '1 minute' WHERE ship_id = :id", ['id' => $id]);
        $this->svc('npcTasks')->population();
        $ship = $this->ship($id);
        $this->assertFalse((bool)$ship['ship_destroyed']);
        $this->assertSame(30000, (int)$ship['credits'], 'fresh loadout');
        $after = $this->npcs()->profile($id);
        $this->assertSame('Vex killed me. Remember.', $after['notebook']);
        $this->assertSame($persona, $after['persona']);
        $this->assertSame(null, $after['respawn_at']);
        $this->assertSame(-3000, (int)$ship['alignment']);
    }

    public function testScriptedTickIsCappedAt25NpcsAndTheTimeBudget(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->npcs()->spawn('guild', ['sector' => 3 + ($i % 20)]);
        }
        $out = $this->svc('npcTasks')->scriptedTick();
        preg_match('/Ran (\d+) NPCs/', $out, $m);
        $this->assertTrue((int)$m[1] <= 25, $out);
        $this->assertGreaterThan(0, (int)$m[1], $out);
        // Fairness: the next tick takes the NPCs that have not run yet.
        $ran = (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM npc_profiles WHERE state -> 'script' ->> 'last_tick' IS NOT NULL")['c'];
        $this->svc('npcTasks')->scriptedTick();
        $ran2 = (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM npc_profiles WHERE state -> 'script' ->> 'last_tick' IS NOT NULL")['c'];
        $this->assertGreaterThan($ran, $ran2);
        // A tiny time budget limits the work even further.
        $cfg = self::$config;
        $cfg['npc']['scripted_tick_max_ms'] = 1;
        $tasks = new \BNT\Core\NpcSchedulerTasks($this->db(), $cfg, $this->svc('npcSettings'), $this->npcs(), $this->svc('npcBrain'),
            $this->svc('npcControl'), $this->svc('npcMonitor'), $this->svc('policeService'), $this->svc('alignmentService'), $this->svc('sectorGraph'));
        $this->db()->execute("UPDATE npc_profiles SET state = state - 'script'");
        preg_match('/Ran (\d+) NPCs/', $tasks->scriptedTick(), $m2);
        $this->assertTrue((int)$m2[1] < 25, 'time cap bites');
    }

    public function testScriptedTraderTradesProfitablyWithPlayerRules(): void
    {
        $n = $this->npcs()->spawn('guild', ['sector' => 5]);
        $id = $n['ship_id'];
        $start = (int)$this->ship($id)['credits'];
        for ($i = 0; $i < 40; $i++) {
            $this->db()->execute('UPDATE ships SET turns = 500 WHERE ship_id = :id', ['id' => $id]);
            $this->svc('npcBrain')->tick($this->npcProfile($id), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        }
        $ship = $this->ship($id);
        $this->assertGreaterThan($start, (int)$ship['credits'] + (int)$ship['ship_goods'] * 21 + (int)$ship['ship_ore'] * 15 + (int)$ship['ship_organics'] * 6 + (int)$ship['ship_energy'] * 3, 'a Guild trader makes money');
        $this->assertTrue((int)$ship['turns_used'] > 0, 'moving cost turns like any player');
        $this->assertGreaterThan(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM movement_log WHERE ship_id = :id', ['id' => $id])['c']);
        $this->assertGreaterThan(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM ship_known_ports WHERE ship_id = :id', ['id' => $id])['c']);
    }

    private function npcProfile(int $id): array
    {
        $p = $this->npcs()->profile($id);
        $p['state'] = $p['state'] ?: [];
        return $p;
    }

    public function testRaiderOnlyAttacksTargetsUnder70PercentOfItsRating(): void
    {
        $raider = $this->npcs()->spawn('xenobe', ['sector' => 8]);
        $rid = $raider['ship_id'];
        $weak = $this->makePlayer('Weakling', ['sector' => 8]);
        $rating = $this->svc('combatRating');
        $mine = $rating->rate($this->ship($rid));
        $this->assertTrue($rating->rate($this->ship($weak)) < 0.7 * $mine, 'precondition: target is weak');
        $this->svc('npcBrain')->tick($this->npcProfile($rid), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertTrue((bool)$this->ship($weak)['ship_destroyed'] || (int)$this->ship($weak)['armor_pts'] < 100, 'the raider attacked a weak trader');

        // A similar-strength ship is left alone.
        $this->setUp();
        $raider = $this->npcs()->spawn('xenobe', ['sector' => 8]);
        $peer = $this->makePlayer('Peer', ['sector' => 8, 'beams' => 7, 'shields' => 6, 'armor' => 6, 'armor_pts' => 1139, 'ship_fighters' => 900, 'torps' => 50]);
        $this->assertTrue($rating->rate($this->ship($peer)) >= 0.7 * $rating->rate($this->ship($raider['ship_id'])), 'precondition: peer is not weak');
        $this->svc('npcBrain')->tick($this->npcProfile($raider['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM attack_logs WHERE attacker_id = :id', ['id' => $raider['ship_id']])['c']);
    }

    public function testRaiderNeverAttacksFellowRaidersAndRetreatsWhenHurt(): void
    {
        $a = $this->npcs()->spawn('xenobe', ['sector' => 8]);
        $b = $this->npcs()->spawn('xenobe', ['sector' => 8]);
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 900, armor = 12, armor_pts = 20000 WHERE ship_id = :id', ['id' => $a['ship_id']]);
        $this->db()->execute('UPDATE ships SET beams = 0, ship_fighters = 0, shields = 0, armor = 0, armor_pts = 10 WHERE ship_id = :id', ['id' => $b['ship_id']]);
        $this->svc('npcBrain')->tick($this->npcProfile($a['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM attack_logs WHERE attacker_id = :id', ['id' => $a['ship_id']])['c']);
        $this->assertFalse((bool)$this->ship($b['ship_id'])['ship_destroyed']);

        // Below 40% hull the raider heads for its home zone (zone 4 does not exist in this tiny map -> fallback is "resting").
        $this->db()->execute('UPDATE ships SET sector = 20, armor = 5, armor_pts = 100 WHERE ship_id = :id', ['id' => $b['ship_id']]);
        $this->db()->execute("UPDATE universe SET zone_id = 4 WHERE sector_id IN (24, 25)");
        $this->svc('sectorGraph')->refresh();
        $this->db()->execute('UPDATE ships SET armor_pts = 20 WHERE ship_id = :id', ['id' => $b['ship_id']]);   // 20 / 759 = 2.6% hull
        $before = (int)$this->ship($b['ship_id'])['sector'];
        $this->svc('npcBrain')->tick($this->npcProfile($b['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertTrue((int)$this->ship($b['ship_id'])['sector'] > $before, 'moved toward home zone 4');
    }

    public function testRaiderLaysMinesOnTheBusiestLane(): void
    {
        $r = $this->npcs()->spawn('xenobe', ['sector' => 8]);
        $this->db()->execute('UPDATE ships SET torps = 40 WHERE ship_id = :id', ['id' => $r['ship_id']]);
        $this->svc('npcBrain')->tick($this->npcProfile($r['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => [8]]);
        $mines = (int)$this->db()->fetchOne("SELECT COALESCE(SUM(quantity), 0) AS q FROM sector_defence WHERE ship_id = :id AND defence_type = 'M' AND sector_id = 8", ['id' => $r['ship_id']])['q'];
        $this->assertSame(5, $mines);
        // Never in FedSpace.
        $r2 = $this->npcs()->spawn('xenobe', ['sector' => 2]);
        $this->db()->execute('UPDATE ships SET torps = 40 WHERE ship_id = :id', ['id' => $r2['ship_id']]);
        $this->svc('npcBrain')->tick($this->npcProfile($r2['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => [2]]);
        $this->assertSame(0, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM sector_defence WHERE ship_id = :id AND sector_id = 2", ['id' => $r2['ship_id']])['c']);
    }

    public function testPoliceDispatchAssignsScalesAndRecalls(): void
    {
        $p1 = $this->npcs()->spawn('police', ['sector' => 2]);
        $p2 = $this->npcs()->spawn('police', ['sector' => 2]);
        $p3 = $this->npcs()->spawn('police', ['sector' => 2]);
        $offender = $this->makePlayer('Offender', ['sector' => 12]);
        $this->svc('alignmentService')->apply($offender, -1200, 'bad');   // 2 units: 1 per 500 points
        $r = $this->svc('policeService')->dispatch();
        $this->assertSame(2, $r['assigned'], 'one police unit per 500 points below zero');
        $assigned = $this->db()->fetchAll("SELECT ship_id FROM npc_profiles WHERE faction = 'police' AND state -> 'police' ->> 'target' = :t", ['t' => (string)$offender]);
        $this->assertCount(2, $assigned);
        // No double-assignment on the next run.
        $this->assertSame(0, $this->svc('policeService')->dispatch()['assigned']);
        // The offender's Wanted status lapses: both units are recalled and head home before any new tasking.
        $this->db()->execute('UPDATE ships SET alignment = -50, wanted_until = NULL WHERE ship_id = :id', ['id' => $offender]);
        $r = $this->svc('policeService')->dispatch();
        $this->assertSame(2, $r['recalled']);
        $this->assertSame(0, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM npc_profiles WHERE state -> 'police' ->> 'target' IS NOT NULL")['c']);
    }

    public function testPoliceSpawnTemporaryUnitsForHumanOffendersAndStandDown(): void
    {
        $offender = $this->makePlayer('Offender', ['sector' => 12]);
        $this->svc('alignmentService')->apply($offender, -3000, 'bad');   // 5 units (cap)
        $r = $this->svc('policeService')->dispatch();
        $this->assertSame(5, $r['spawned']);
        $units = $this->db()->fetchAll("SELECT p.ship_id FROM npc_profiles p WHERE p.faction = 'police'");
        $this->assertCount(5, $units);
        // Stand-down: offender cleared, units go home, temporary units retire in FedSpace.
        $this->db()->execute('UPDATE ships SET alignment = -50, wanted_until = NULL WHERE ship_id = :id', ['id' => $offender]);
        $this->svc('policeService')->dispatch();
        $this->svc('policeService')->dispatch();
        $alive = (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM ships s JOIN npc_profiles p ON p.ship_id = s.ship_id WHERE p.faction = 'police' AND NOT s.ship_destroyed")['c'];
        $this->assertSame(0, $alive, 'temporary units stand down once back in FedSpace');
    }

    public function testPoliceGiveUpAfter48HoursWithoutContact(): void
    {
        $p = $this->npcs()->spawn('police', ['sector' => 2]);
        $offender = $this->makePlayer('Offender', ['sector' => 25]);
        $this->svc('alignmentService')->apply($offender, -300, 'bad');
        $this->svc('alignmentService')->setWanted($offender, 'test');
        $this->svc('policeService')->dispatch();
        $state = $this->npcs()->profile($p['ship_id'])['state'];
        $this->assertSame($offender, $state['police']['target']);
        $old = date('c', time() - 49 * 3600);
        $this->npcs()->patchState($p['ship_id'], 'police', ['last_contact_at' => $old]);
        $r = $this->svc('policeService')->dispatch();
        $this->assertSame(1, $r['recalled']);
        $this->assertSame(null, $this->npcs()->profile($p['ship_id'])['state']['police']['target']);
    }

    public function testPoliceEngageOnlyWantedAndNeverPursueIntoAStarbase(): void
    {
        $p = $this->npcs()->spawn('police', ['sector' => 10]);
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 500, torps = 50 WHERE ship_id = :id', ['id' => $p['ship_id']]);
        $innocent = $this->makePlayer('Innocent', ['sector' => 10]);
        $this->svc('npcBrain')->tick($this->npcProfile($p['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM attack_logs')['c'], 'police leave innocents alone');
        $this->db()->execute('UPDATE ships SET sector = 10 WHERE ship_id = :id', ['id' => $p['ship_id']]);   // it wandered off

        $outlaw = $this->makePlayer('Outlaw', ['sector' => 10]);
        $this->svc('alignmentService')->apply($outlaw, -300, 'bad');
        $this->svc('alignmentService')->setWanted($outlaw, 'test');
        $this->svc('npcBrain')->tick($this->npcProfile($p['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertTrue((bool)$this->ship($outlaw)['ship_destroyed'], 'a Wanted ship in the same sector is engaged');
        $this->assertFalse((bool)$this->ship($innocent)['ship_destroyed']);

        // Pursuit never enters a starbase sector: the target's last known sector is a starbase.
        $this->db()->execute('UPDATE universe SET is_starbase = TRUE WHERE sector_id = 14');
        $this->svc('sectorRules')->forget();
        $this->svc('sectorGraph')->refresh();
        $fugitive = $this->makePlayer('Fugitive', ['sector' => 14]);
        $this->svc('alignmentService')->apply($fugitive, -300, 'bad');
        $this->svc('alignmentService')->setWanted($fugitive, 'test', 14);
        $this->db()->execute('UPDATE ships SET sector = 10 WHERE ship_id = :id', ['id' => $p['ship_id']]);
        $this->svc('policeService')->dispatch();
        for ($i = 0; $i < 6; $i++) {
            $this->svc('npcBrain')->tick($this->npcProfile($p['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
            $this->assertTrue((int)$this->ship($p['ship_id'])['sector'] !== 14, 'police never enter the starbase sector');
        }
    }

    public function testFreeCaptainAttacksOnlyALoneOutlawItWouldClearlyBeat(): void
    {
        $f = $this->npcs()->spawn('free', ['sector' => 9]);
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 500, torps = 50, armor = 10, armor_pts = 5000 WHERE ship_id = :id', ['id' => $f['ship_id']]);
        $lawful = $this->makePlayer('Lawful', ['sector' => 9]);
        $this->svc('npcBrain')->tick($this->npcProfile($f['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertFalse((bool)$this->ship($lawful)['ship_destroyed'], 'avoids combat with lawful ships');
        $this->db()->execute('UPDATE ships SET sector = 9 WHERE ship_id = :id', ['id' => $f['ship_id']]);   // it moved on
        $outlaw = $this->makePlayer('Outlaw', ['sector' => 9]);
        $this->db()->execute('UPDATE ships SET alignment = -400 WHERE ship_id = :id', ['id' => $outlaw]);
        $this->db()->execute('UPDATE ships SET sector = 18 WHERE ship_id = :id', ['id' => $lawful]);
        $this->svc('npcBrain')->tick($this->npcProfile($f['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
        $this->assertTrue((bool)$this->ship($outlaw)['ship_destroyed']);
    }

    public function testLlmControlFallsBackToScriptedInEveryDocumentedCase(): void
    {
        $n = $this->npcs()->spawn('free', ['sector' => 9, 'controller' => 'llm']);
        $id = $n['ship_id'];
        $control = $this->svc('npcControl');
        $profile = fn() => $this->npcs()->profile($id);
        $none = ['global' => 0.0, 'per_npc' => []];
        $this->assertSame('llm_disabled', $control->fallbackReason($profile(), $none));
        $this->svc('npcSettings')->set('llm_enabled', true);
        $this->assertSame('worker_silent', $control->fallbackReason($profile(), $none), 'no heartbeat yet');
        $this->db()->execute("INSERT INTO npc_worker_status (id, heartbeat_at) VALUES (1, now())");
        $this->assertSame(null, $control->fallbackReason($profile(), $none), 'all conditions met: LLM drives');
        $this->db()->execute("UPDATE npc_worker_status SET heartbeat_at = now() - interval '16 minutes'");
        $this->assertSame('worker_silent', $control->fallbackReason($profile(), $none), 'worker silent for 15+ minutes');
        $this->db()->execute("UPDATE npc_worker_status SET heartbeat_at = now() - interval '14 minutes'");
        $this->assertSame(null, $control->fallbackReason($profile(), $none));
        $this->npcs()->patchState($id, 'llm', ['failures' => 3]);
        $this->assertSame('openrouter_failures', $control->fallbackReason($profile(), $none), '3 failures in a row');
        $this->npcs()->patchState($id, 'llm', ['failures' => 2]);
        $this->assertSame('npc_budget_spent', $control->fallbackReason($profile(), ['global' => 1.0, 'per_npc' => [$id => 1.0]]));
        $this->assertSame('global_budget_spent', $control->fallbackReason($profile(), ['global' => 10.0, 'per_npc' => []]));
        $this->svc('npcSettings')->set('llm_enabled', false);
        $this->assertSame('llm_disabled', $control->fallbackReason($profile(), $none), 'kill switch');

        // And a fallen-back LLM NPC really is run by the scripted tick (while an active one is not).
        $this->db()->execute('UPDATE ships SET turns = 100 WHERE ship_id = :id', ['id' => $id]);
        $this->svc('npcTasks')->scriptedTick();
        $this->assertTrue($this->npcs()->profile($id)['state']['script']['last_tick'] !== null);
    }

    public function testTurnMultiplierAppliesToNpcsOnlyWhenConfigured(): void
    {
        $n = $this->npcs()->spawn('guild', ['sector' => 5]);
        $human = $this->makePlayer('Human');
        $this->db()->execute('UPDATE ships SET turns = 100, last_login = now()');
        $cfg = self::$config;
        $cfg['npc']['factions']['guild']['turn_multiplier'] = 2.0;
        $tasks = new \BNT\Core\SchedulerTasks($this->db(), $cfg);
        $tasks->generateTurns(1);
        $this->assertSame(102 + 2, (int)$this->ship($n['ship_id'])['turns'], 'x2 multiplier doubles the faction\'s turns');
        $this->assertSame(102, (int)$this->ship($human)['turns']);
    }
}
