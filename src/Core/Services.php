<?php

declare(strict_types=1);

namespace BNT\Core;

use BNT\Models\AttackLog;
use BNT\Models\Combat;
use BNT\Models\Planet;
use BNT\Models\Ship;
use BNT\Models\Skill;
use BNT\Models\Universe;
use BNT\Services\AgentService;
use BNT\Services\AlignmentRules;
use BNT\Services\AlignmentService;
use BNT\Services\BountyService;
use BNT\Services\CombatRating;
use BNT\Services\ContentValidator;
use BNT\Services\ContrabandService;
use BNT\Services\FeatureSettings;
use BNT\Services\NewsService;
use BNT\Services\RumourService;
use BNT\Services\CombatService;
use BNT\Services\MessagingService;
use BNT\Services\MovementService;
use BNT\Services\NpcControl;
use BNT\Services\NpcEvents;
use BNT\Services\NpcMonitor;
use BNT\Services\NpcScriptedBrain;
use BNT\Services\NpcService;
use BNT\Services\NpcSettings;
use BNT\Services\ObservationBuilder;
use BNT\Services\PlanetTransferService;
use BNT\Services\PoliceService;
use BNT\Services\PressService;
use BNT\Services\ProtectionRules;
use BNT\Services\ProtectionService;
use BNT\Services\SectorGraph;
use BNT\Services\SectorRules;
use BNT\Services\TextFilter;
use BNT\Services\TradeService;

/**
 * Single place that wires the alignment/NPC services. The web front controller,
 * CLI scripts, the simulation harness and the tests all build their object
 * graph here so they cannot drift apart.
 */
class Services
{
    /** @return array<string,object> keyed by the variable names public/index.php uses */
    public static function create(array $config, Database $db): array
    {
        $config = FeatureSettings::apply($db, $config);   // admin overrides for protection / news / rumours switches
        $s = ['config' => $config];
        $s['shipModel'] = $ship = new Ship($db);
        $s['universeModel'] = $universe = new Universe($db);
        $s['planetModel'] = $planet = new Planet($db);
        $s['skillModel'] = $skill = new Skill($db);

        $s['alignmentRules'] = $rules = new AlignmentRules($config);
        $s['bountyService'] = $bounty = new BountyService($db, $rules);
        $s['alignmentService'] = $alignment = new AlignmentService($db, $rules, $bounty, $config);
        $s['sectorRules'] = $sectors = new SectorRules($db);
        $s['protectionRules'] = $protRules = new ProtectionRules($config);
        $s['combatModel'] = $combat = new Combat($db, $rules, $sectors, $bounty, $protRules);
        $s['attackLogModel'] = $attackLog = new AttackLog($db, $rules);
        $s['npcSettings'] = $settings = new NpcSettings($db, $config);
        $s['npcEvents'] = $events = new NpcEvents($db);
        $s['sectorGraph'] = $graph = new SectorGraph($db, $config);
        $s['apiAuth'] = $apiAuth = new ApiAuth($db, $ship);
        $apiAuth->setNpcWorkerIps($config['npc']['worker_ips']);
        $s['npcService'] = $npcs = new NpcService($db, $alignment, $apiAuth, $sectors, $config);
        $s['pressService'] = $press = new PressService($db, $npcs, $config);
        $s['protectionService'] = $protection = new ProtectionService($db, $protRules, $ship, $press, $config);
        $s['textFilter'] = $filter = new TextFilter($config);
        $s['contrabandService'] = $contraband = new ContrabandService($db, $alignment, $sectors, $config);
        $s['tradeService'] = $trade = new TradeService($db, $ship, $universe, $skill, $alignment, $sectors, $config, $contraband);
        $s['npcControl'] = $control = new NpcControl($db, $settings, $config);
        $s['npcMonitor'] = $monitor = new NpcMonitor($db, $settings, $control, $config);
        $s['combatService'] = $combatService = new CombatService($db, $ship, $universe, $planet, $combat, $attackLog,
            $skill, $alignment, $sectors, $events, $config, $monitor, $contraband, $protection);
        $s['movementService'] = $movement = new MovementService($db, $ship, $universe, $combat, $alignment,
            $sectors, $trade, $events, $graph, $config, $contraband, $protection);
        $s['policeService'] = $police = new PoliceService($db, $alignment, $npcs, $graph, $config);
        $s['combatRating'] = $rating = new CombatRating($combat);
        $s['npcBrain'] = $brain = new NpcScriptedBrain($db, $ship, $movement, $trade, $combatService,
            $alignment, $rating, $graph, $npcs, $police, $config, $contraband, $protRules);
        $s['npcTasks'] = new NpcSchedulerTasks($db, $config, $settings, $npcs, $brain, $control, $monitor, $police, $alignment, $graph);
        $s['messagingService'] = new MessagingService($db, $filter, $events, $config);
        $s['planetTransferService'] = new PlanetTransferService($db);
        $s['agentService'] = new AgentService($db, $trade, $graph, $config);
        $s['contentValidator'] = $validator = new ContentValidator($filter, $config);
        $s['rumourService'] = $rumours = new RumourService($db, $graph, $protection, $validator, $config);
        $s['newsService'] = $news = new NewsService($db, $validator, $filter, $press, $protection, $config);
        $s['contentTasks'] = new ContentSchedulerTasks($protection, $news, $rumours);
        $s['observationBuilder'] = new ObservationBuilder($db, $ship, $planet, $trade, $alignment, $rating, $events, $filter, $config, $contraband);
        return $s;
    }
}
