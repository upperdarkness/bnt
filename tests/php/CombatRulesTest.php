<?php

declare(strict_types=1);

namespace BNT\Tests;

/** Combat, defences, bounties and planet capture through CombatService / MovementService. */
class CombatRulesTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(12);
    }

    private function attack(int $attackerId, int $targetId): array
    {
        return $this->svc('combatService')->attackShip($this->ship($attackerId), $targetId);
    }

    private function setAlign(int $id, int $v): void
    {
        $this->db()->execute('UPDATE ships SET alignment = :v WHERE ship_id = :id', ['v' => $v, 'id' => $id]);
    }

    // ------------------------------------------------------------ alignment deltas

    public function testAttackAndDestroyLawfulShipCostsAlignmentWithLogRows(): void
    {
        $a = $this->makePlayer('Attacker', $this->strong(['sector' => 6]));
        $b = $this->makePlayer('Victim', ['sector' => 6]);
        $r = $this->attack($a, $b);
        $this->assertTrue($r['success'], $r['text']);
        $this->assertSame('destroyed', $r['data']['outcome']);
        $this->assertSame(-350, $this->alignmentOf($a), '-100 attack, -250 destroy');
        $this->assertSame(1, $this->logCount($a, 'attacked_lawful'));
        $this->assertSame(1, $this->logCount($a, 'destroyed_lawful'));
        $log = $this->db()->fetchOne("SELECT related_ship_id FROM alignment_log WHERE reason = 'attacked_lawful'");
        $this->assertSame($b, (int)$log['related_ship_id']);
    }

    public function testAttackingAnOutlawIsRewarded(): void
    {
        $a = $this->makePlayer('Hunter', $this->strong(['sector' => 6]));
        $b = $this->makePlayer('Outlaw', ['sector' => 6]);
        $this->setAlign($b, -500);
        $this->attack($a, $b);
        $this->assertSame(200, $this->alignmentOf($a), '+50 attack, +150 destroy');
        $this->assertSame(1, $this->logCount($a, 'attacked_outlaw'));
        $this->assertSame(1, $this->logCount($a, 'destroyed_outlaw'));
    }

    public function testTeamMatesCannotBeAttacked(): void
    {
        $a = $this->makePlayer('A', $this->strong(['sector' => 6]));
        $this->db()->execute("INSERT INTO teams (id, team_name, creator) VALUES (1, 'Crew', :c)", ['c' => $a]);
        $b = $this->makePlayer('B', ['sector' => 6]);
        $this->db()->execute('UPDATE ships SET team = 1 WHERE ship_id IN (:a, :b)', ['a' => $a, 'b' => $b]);
        $r = $this->attack($a, $b);
        $this->assertFalse($r['success']);
        $this->assertSame('TEAM_MEMBER', $r['code']);
        $this->assertSame(0, $this->alignmentOf($a));
        $this->assertSame(0, $this->logCount($a));
    }

    public function testPlanetCaptureAlignment(): void
    {
        $captor = $this->makePlayer('Captor', $this->strong(['sector' => 6]));
        $lawfulOwner = $this->makePlayer('Lawful', ['sector' => 9]);
        $outlawOwner = $this->makePlayer('Outlaw', ['sector' => 9]);
        $this->setAlign($outlawOwner, -400);
        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, fighters) VALUES ('P1', 6, :o, 0)", ['o' => $lawfulOwner]);
        $p1 = (int)$this->db()->fetchOne('SELECT planet_id FROM planets WHERE planet_name = :n', ['n' => 'P1'])['planet_id'];
        $r = $this->svc('combatService')->attackPlanet($this->ship($captor), $p1);
        $this->assertTrue($r['data']['captured'], $r['text']);
        $this->assertSame(-150, $this->alignmentOf($captor));
        $this->assertSame(1, $this->logCount($captor, 'captured_lawful_planet'));

        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, fighters) VALUES ('P2', 6, :o, 0)", ['o' => $outlawOwner]);
        $p2 = (int)$this->db()->fetchOne('SELECT planet_id FROM planets WHERE planet_name = :n', ['n' => 'P2'])['planet_id'];
        $this->svc('combatService')->attackPlanet($this->ship($captor), $p2);
        $this->assertSame(-100, $this->alignmentOf($captor), '+50 for taking a planet from an outlaw');
        $this->assertSame(1, $this->logCount($captor, 'captured_outlaw_planet'));
        // The previous owner hears about it (NPC events only queue for NPCs, so this is a no-op for humans).
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM npc_events')['c']);
    }

    // ------------------------------------------------------------ starbase + FedSpace

    public function testNoCombatPathReachesAnyShipInAStarbaseSector(): void
    {
        $this->db()->execute('UPDATE universe SET is_starbase = TRUE WHERE sector_id = 5');
        $this->svc('sectorRules')->forget();
        $police = $this->svc('npcService')->spawn('police', ['sector' => 5]);
        $victims = [
            'neutral' => $this->makePlayer('Neutral', ['sector' => 5]),
            'pirate' => $this->makePlayer('Pirate', ['sector' => 5]),
        ];
        $this->setAlign($victims['pirate'], -5000);
        $this->svc('alignmentService')->setWanted($victims['pirate']);
        $attackers = [
            'pirate' => $this->makePlayer('PirateA', $this->strong(['sector' => 5])),
            'lawful' => $this->makePlayer('LawfulA', $this->strong(['sector' => 5])),
            'police' => $police['ship_id'],
        ];
        $this->setAlign($attackers['pirate'], -5000);
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 500 WHERE ship_id = :id', ['id' => $police['ship_id']]);
        foreach ($attackers as $an => $aid) {
            foreach ($victims as $vn => $vid) {
                $r = $this->attack($aid, $vid);
                $this->assertFalse($r['success'], "$an -> $vn must be blocked in a starbase");
                $this->assertSame('STARBASE_NO_COMBAT', $r['code']);
                $this->assertSame('Combat is not allowed in starbase sectors', $r['text']);
            }
            $this->assertFalse($this->svc('combatService')->attackPlanet($this->ship($aid), 1)['success']);
            $this->assertFalse($this->svc('combatService')->deployDefence($this->ship($aid), 'F', 1)['success']);
        }
        foreach ($victims as $vid) {
            $this->assertFalse((bool)$this->ship($vid)['ship_destroyed']);
        }
        $this->assertSame(0, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM attack_logs")['c']);
    }

    public function testFedSpaceProtectsNeutralOrBetterFromEveryone(): void
    {
        $victim = $this->makePlayer('Citizen', ['sector' => 2]);
        foreach ([0 => 'neutral', 500 => 'lawful', -300 => 'outlaw', -3000 => 'pirate'] as $align => $label) {
            $a = $this->makePlayer("A$label", $this->strong(['sector' => 2]));
            $this->setAlign($a, $align);
            $r = $this->attack($a, $victim);
            $this->assertFalse($r['success'], "$label attacker must not reach a protected ship");
            $this->assertSame('FEDSPACE_PROTECTED', $r['code']);
            $this->assertSame($align, $this->alignmentOf($a), 'no penalty for a blocked attempt');
        }
        $this->assertFalse((bool)$this->ship($victim)['ship_destroyed']);
        // The boundary: -99 is still protected, -100 is not.
        $this->setAlign($victim, -99);
        $this->assertFalse($this->attack($this->makePlayer('B1', $this->strong(['sector' => 2])), $victim)['success']);
        $this->setAlign($victim, -100);
        $this->assertTrue($this->attack($this->makePlayer('B2', $this->strong(['sector' => 2])), $victim)['success']);
    }

    public function testLawfulCanHuntOutlawsInFedSpaceWithoutBecomingWanted(): void
    {
        $hunter = $this->makePlayer('Hunter', $this->strong(['sector' => 2]));
        $outlaw = $this->makePlayer('Outlaw', ['sector' => 2]);
        $this->setAlign($outlaw, -600);
        $r = $this->attack($hunter, $outlaw);
        $this->assertTrue($r['success'], $r['text']);
        $this->assertSame(200, $this->alignmentOf($hunter));
        $this->assertFalse($this->svc('alignmentService')->isWanted($this->ship($hunter)));
    }

    public function testOutlawVsOutlawInFedSpaceCostsFiveHundredAndSetsWanted(): void
    {
        $a = $this->makePlayer('Raider', $this->strong(['sector' => 2]));
        $b = $this->makePlayer('Smuggler', ['sector' => 2]);
        $this->setAlign($a, -200);
        $this->setAlign($b, -300);
        $r = $this->attack($a, $b);
        $this->assertTrue($r['success'], $r['text']);
        // +50 attack +150 destroy (target is an outlaw) and -500 for the FedSpace offence.
        $this->assertSame(-200 + 50 + 150 - 500, $this->alignmentOf($a));
        $this->assertSame(1, $this->logCount($a, 'fedspace_hostile_action'));
        $ship = $this->ship($a);
        $this->assertTrue($this->svc('alignmentService')->isWanted($ship));
        $this->assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM bounties WHERE target_id = :id AND placed_by IS NULL', ['id' => $a])['c']);
        $this->assertSame(1, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM news WHERE news_type = 'wanted'")['c']);
        $this->assertSame(2, (int)$ship['last_known_sector']);
    }

    public function testPoliceMayAttackWantedShipsAnywhereButNotUnwantedOnes(): void
    {
        $police = $this->svc('npcService')->spawn('police', ['sector' => 2]);
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 500, torps = 50 WHERE ship_id = :id', ['id' => $police['ship_id']]);
        $innocent = $this->makePlayer('Innocent', ['sector' => 2]);
        $r = $this->svc('combatService')->attackShip($this->ship($police['ship_id']), $innocent);
        $this->assertFalse($r['success'], 'police only engage Wanted ships');

        $this->svc('alignmentService')->setWanted($innocent, 'test');
        $r = $this->svc('combatService')->attackShip($this->ship($police['ship_id']), $innocent);
        $this->assertTrue($r['success'], $r['text']);
        $this->assertSame('destroyed', $r['data']['outcome']);
    }

    public function testNpcsCannotAttackTheirOwnFactionAndAnAlertIsRaised(): void
    {
        $a = $this->svc('npcService')->spawn('xenobe', ['sector' => 6]);
        $b = $this->svc('npcService')->spawn('xenobe', ['sector' => 6]);
        $this->db()->execute('UPDATE ships SET beams = 12, ship_fighters = 500 WHERE ship_id = :id', ['id' => $a['ship_id']]);
        $r = $this->svc('combatService')->attackShip($this->ship($a['ship_id']), $b['ship_id']);
        $this->assertFalse($r['success']);
        $this->assertSame('FACTION_LOYALTY', $r['code']);
        $alert = $this->db()->fetchOne("SELECT * FROM npc_alerts WHERE kind = 'faction_friendly_fire'");
        $this->assertSame($a['ship_id'], (int)$alert['ship_id']);
    }

    // ------------------------------------------------------------ defences

    public function testDefenceDeploymentRules(): void
    {
        $svc = $this->svc('combatService');
        $neutral = $this->makePlayer('Neutral', ['sector' => 2, 'ship_fighters' => 50, 'torps' => 50]);
        $outlaw = $this->makePlayer('Outlaw', ['sector' => 2, 'ship_fighters' => 50, 'torps' => 50]);
        $this->setAlign($outlaw, -100);
        $this->assertTrue($svc->deployDefence($this->ship($neutral), 'M', 5)['success'], 'Neutral may deploy in FedSpace');
        $r = $svc->deployDefence($this->ship($outlaw), 'M', 5);
        $this->assertFalse($r['success']);
        $this->assertSame('FEDSPACE_NO_DEFENCES', $r['code']);
        $this->assertSame(50, (int)$this->ship($outlaw)['torps'], 'nothing was deducted');
        $this->assertSame(0, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM sector_defence WHERE ship_id = :id", ['id' => $outlaw])['c']);
        // Outside FedSpace an outlaw can deploy.
        $this->db()->execute('UPDATE ships SET sector = 7 WHERE ship_id = :id', ['id' => $outlaw]);
        $this->assertTrue($svc->deployDefence($this->ship($outlaw), 'F', 10)['success']);
        // Invalid input.
        $this->assertFalse($svc->deployDefence($this->ship($outlaw), 'X', 1)['success']);
        $this->assertFalse($svc->deployDefence($this->ship($outlaw), 'F', 0)['success']);
        $this->assertFalse($svc->deployDefence($this->ship($outlaw), 'F', 9999)['success']);
    }

    public function testDefenceThatDestroysALawfulShipPenalisesItsOwnerOnly(): void
    {
        $owner = $this->makePlayer('Owner', ['sector' => 7, 'ship_fighters' => 2000]);
        $this->svc('combatService')->deployDefence($this->ship($owner), 'F', 1500);
        $lawfulVictim = $this->makePlayer('Lawful', ['sector' => 6, 'turns' => 100]);
        $r = $this->svc('movementService')->move($this->ship($lawfulVictim), 7);
        $this->assertTrue($r['success']);
        $this->assertTrue($r['destroyed'], 'sector fighters destroyed the ship');
        $this->assertSame(-350, $this->alignmentOf($owner), '-100 and -250 applied to the defences\' owner');
        $this->assertSame(1, $this->logCount($owner, 'defence_attacked_lawful'));
        $this->assertSame(1, $this->logCount($owner, 'defence_destroyed_lawful'));
        // An outlaw victim costs the owner nothing.
        $outlaw = $this->makePlayer('Outlaw', ['sector' => 6, 'turns' => 100]);
        $this->setAlign($outlaw, -400);
        $this->assertTrue($this->svc('movementService')->move($this->ship($outlaw), 7)['destroyed']);
        $this->assertSame(-350, $this->alignmentOf($owner));
    }

    public function testDefencesNeverHarmProtectedShipsInFedSpace(): void
    {
        $owner = $this->makePlayer('Owner', ['sector' => 2, 'ship_fighters' => 2000]);
        $this->svc('combatService')->deployDefence($this->ship($owner), 'F', 1500);
        $citizen = $this->makePlayer('Citizen', ['sector' => 3, 'turns' => 100]);
        $r = $this->svc('movementService')->move($this->ship($citizen), 2);
        $this->assertFalse($r['destroyed']);
        $this->assertSame(null, $r['fighter'], 'FedSpace defences ignore Neutral-or-better ships');
        // ...but they do engage an outlaw, and that is a lawful owner's right.
        $outlaw = $this->makePlayer('Outlaw', ['sector' => 3, 'turns' => 100]);
        $this->setAlign($outlaw, -500);
        $r = $this->svc('movementService')->move($this->ship($outlaw), 2);
        $this->assertTrue($r['destroyed']);
        $this->assertSame(0, $this->alignmentOf($owner), 'no penalty for destroying an outlaw');
        // Outlaw-owned leftovers are inert in FedSpace even against other outlaws.
        $this->db()->execute('UPDATE sector_defence SET quantity = 0');
        $rogue = $this->makePlayer('Rogue', ['sector' => 2]);
        $this->setAlign($rogue, -500);
        $this->db()->execute("INSERT INTO sector_defence (ship_id, sector_id, defence_type, quantity) VALUES (:id, 2, 'F', 2000)", ['id' => $rogue]);
        $o2 = $this->makePlayer('Outlaw2', ['sector' => 3, 'turns' => 100]);
        $this->setAlign($o2, -500);
        $this->assertFalse($this->svc('movementService')->move($this->ship($o2), 2)['destroyed']);
    }

    // ------------------------------------------------------------ bounties

    public function testBountyPayoutRules(): void
    {
        $bounty = $this->svc('bountyService');
        $target = $this->makePlayer('Target');
        $this->svc('alignmentService')->apply($target, -2000, 'bad');   // Federation bounty 20 x 1,000 = 20,000
        $this->db()->execute('UPDATE ships SET credits = 1000000 WHERE ship_id = :id', ['id' => $target]);
        $this->db()->execute('UPDATE ibank_accounts SET balance = 100000 WHERE ship_id = :id', ['id' => $target]);
        $placer = $this->makePlayer('Placer');
        $this->db()->execute('UPDATE ibank_accounts SET balance = 50000 WHERE ship_id = :id', ['id' => $placer]);
        $this->assertFalse($bounty->place($placer, $target, 9999)['success'], 'minimum 10,000');
        $this->assertTrue($bounty->place($placer, $target, 10000)['success']);
        $this->assertSame(40000, (int)$this->db()->fetchOne('SELECT balance FROM ibank_accounts WHERE ship_id = :id', ['id' => $placer])['balance'], 'paid from the IGB');
        $this->assertFalse($bounty->place($placer, $placer, 10000)['success'], 'no self-bounty');
        $this->assertFalse($bounty->place($placer, $target, 999999)['success'], 'cannot overspend');
        $this->assertSame(30000, $bounty->openTotal($target));

        // An outlaw killer cannot claim.
        $outlawKiller = $this->makePlayer('OutlawKiller');
        $this->setAlign($outlawKiller, -300);
        $this->assertSame(0, $bounty->claim($outlawKiller, $target));
        $this->assertSame(30000, $bounty->openTotal($target), 'bounty stays open');
        // A team-mate cannot claim.
        $this->db()->execute("INSERT INTO teams (id, team_name, creator) VALUES (5, 'T', :c)", ['c' => $placer]);
        $mate = $this->makePlayer('Mate', ['team' => 5]);
        $this->db()->execute('UPDATE ships SET team = 5 WHERE ship_id = :id', ['id' => $target]);
        $this->assertSame(0, $bounty->claim($mate, $target));
        $this->db()->execute('UPDATE ships SET team = 0 WHERE ship_id = :id', ['id' => $target]);
        // A Neutral killer gets 100%, a Paragon 125%.
        $neutral = $this->makePlayer('Neutral', ['credits' => 0]);
        $this->assertSame(30000, $bounty->claim($neutral, $target));
        $this->assertSame(30000, (int)$this->ship($neutral)['credits']);
        $this->assertSame(0, $bounty->openTotal($target));
        $this->assertSame(0, $bounty->claim($neutral, $target), 'cannot be claimed twice');
        $this->db()->execute("INSERT INTO bounties (target_id, placed_by, amount) VALUES (:t, NULL, 8000)", ['t' => $target]);
        $paragon = $this->makePlayer('Paragon', ['credits' => 0]);
        $this->setAlign($paragon, 1500);
        $this->assertSame(10000, $bounty->claim($paragon, $target));
        // NPC accounts cannot place bounties.
        $npc = $this->svc('npcService')->spawn('free', ['sector' => 5]);
        $this->db()->execute('UPDATE ibank_accounts SET balance = 100000 WHERE ship_id = :id', ['id' => $npc['ship_id']]);
        $this->assertFalse($bounty->place($npc['ship_id'], $target, 20000)['success']);
    }

    public function testKillingAWantedShipPaysTheFederationBountyThroughTheSingleCombatPath(): void
    {
        $target = $this->makePlayer('Wanted', ['sector' => 6]);
        $this->svc('alignmentService')->apply($target, -1500, 'bad');
        $hunter = $this->makePlayer('Hunter', $this->strong(['sector' => 6, 'credits' => 0]));
        $r = $this->attack($hunter, $target);
        $this->assertSame('destroyed', $r['data']['outcome']);
        $this->assertSame(15000, $r['data']['bounty']);
        $this->assertGreaterThan(14999, (int)$this->ship($hunter)['credits']);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM bounties WHERE claimed_by IS NULL AND target_id = :t', ['t' => $target])['c']);
    }

    // ------------------------------------------------------------ starbase services

    public function testStarbasePricingByTierAndPirateRefusal(): void
    {
        $this->db()->execute("UPDATE universe SET port_type = 'ore' WHERE sector_id = 1");
        $this->svc('sectorRules')->forget();
        $trade = $this->svc('tradeService');
        $price = [];
        foreach (['paragon' => 1500, 'neutral' => 0, 'outlaw' => -500] as $label => $align) {
            $id = $this->makePlayer(ucfirst($label), ['sector' => 1, 'credits' => 1_000_000, 'hull' => 5]);
            $this->setAlign($id, $align);
            $r = $trade->trade($id, 'ore', 'buy', 500);
            $this->assertTrue($r['success'], $label . ': ' . json_encode($r));
            $price[$label] = -$r['credits_delta'];
        }
        $this->assertSame((int)ceil($price['neutral'] * 0.95), $price['paragon'], '5% Paragon discount');
        $this->assertSame((int)ceil($price['neutral'] * 1.15), $price['outlaw'], '15% Outlaw surcharge');
        $pirate = $this->makePlayer('Pirate', ['sector' => 1, 'credits' => 1_000_000, 'hull' => 5]);
        $this->setAlign($pirate, -1000);
        $r = $trade->trade($pirate, 'ore', 'buy', 10);
        $this->assertFalse($r['success']);
        $this->assertSame('SERVICE_REFUSED', $r['code']);
        $this->assertSame(1_000_000, (int)$this->ship($pirate)['credits']);
        // Upgrades follow the same rules, and are starbase-only on the API path.
        $this->assertFalse($trade->buyUpgrade($this->ship($pirate), 'hull')['success']);
        $away = $this->makePlayer('Away', ['sector' => 5, 'credits' => 100000]);
        $this->assertSame('NOT_STARBASE', $trade->buyUpgrade($this->ship($away), 'hull')['code']);
        $base = $this->makePlayer('Base', ['sector' => 1, 'credits' => 100000]);
        $up = $trade->buyUpgrade($this->ship($base), 'hull');
        $this->assertTrue($up['success'], json_encode($up));
        $this->assertSame(1000, $up['result']['cost']);
        $para = $this->makePlayer('Para', ['sector' => 1, 'credits' => 100000]);
        $this->setAlign($para, 2000);
        $this->assertSame(950, $trade->buyUpgrade($this->ship($para), 'hull')['result']['cost']);
    }
}
