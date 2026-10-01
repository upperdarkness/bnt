<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\AdminAuth;
use BNT\Core\Database;
use BNT\Core\Session;
use BNT\Services\AlignmentService;
use BNT\Services\NpcControl;
use BNT\Services\NpcEvents;
use BNT\Services\NpcMonitor;
use BNT\Services\NpcService;
use BNT\Services\NpcSettings;

/** Admin panel: NPC list, per-NPC pages, global controls and alignment tools. */
class NpcAdminController
{
    public function __construct(
        private Database $db,
        private NpcService $npcs,
        private NpcSettings $settings,
        private NpcControl $control,
        private NpcMonitor $monitor,
        private NpcEvents $events,
        private AlignmentService $alignment,
        private Session $session,
        private AdminAuth $adminAuth,
        private array $config
    ) {}

    // ------------------------------------------------------------ list

    public function index(): void
    {
        $this->adminAuth->requireAuth();
        $spend = $this->control->spendToday();
        $rows = $this->db->fetchAll(
            "SELECT p.ship_id, p.faction, p.archetype, p.controller, p.model, p.respawn_at, p.last_wake_at, p.state,
                    s.character_name, s.sector, s.alignment, s.turns, s.ship_destroyed
             FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             ORDER BY p.faction, s.character_name"
        );
        foreach ($rows as &$r) {
            $r['state'] = json_decode((string)$r['state'], true) ?: [];
            $r['effective'] = $this->control->fallbackReason($r, $spend) === null ? 'llm' : 'scripted';
            $r['fallback_reason'] = $r['controller'] === 'llm' ? $this->control->fallbackReason($r, $spend) : null;
            $r['spend_today'] = $spend['per_npc'][(int)$r['ship_id']] ?? 0.0;
            $r['script_state'] = $r['state']['script']['last_actions'][0] ?? '';
        }
        unset($r);
        $this->render('admin_npcs', [
            'npcs' => $rows,
            'rules' => $this->alignment->rules(),
            'factions' => $this->config['npc']['factions'],
            'llmEnabled' => $this->settings->llmEnabled(),
            'title' => 'NPCs - Admin',
        ]);
    }

    // ------------------------------------------------------------ detail

    public function show(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        $profile = $this->npcs->profile($shipId);
        if (!$profile) {
            $this->session->set('error', 'NPC not found');
            header('Location: /admin/npcs');
            exit;
        }
        $spend = $this->control->spendToday();
        $wakes = $this->db->fetchAll(
            "SELECT wake_id, MIN(created_at) AS started, MAX(model) AS model,
                    COALESCE(SUM(cost_usd), 0) AS cost, COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COUNT(*) FILTER (WHERE tool IS NOT NULL AND tool NOT LIKE '\\_%') AS tool_calls,
                    MAX(result ->> 'summary') FILTER (WHERE tool = 'end_turn') AS summary
             FROM npc_action_log WHERE ship_id = :id
             GROUP BY wake_id ORDER BY MIN(created_at) DESC LIMIT 20",
            ['id' => $shipId]
        );
        $this->render('admin_npc_detail', [
            'npc' => $profile,
            'wakes' => $wakes,
            'fallbackReason' => $this->control->fallbackReason($profile, $spend),
            'spendToday' => $spend['per_npc'][$shipId] ?? 0.0,
            'budget' => (float)$this->settings->get('daily_budget_usd', 1.0),
            'faction' => $this->config['npc']['factions'][$profile['faction']],
            'defaultModel' => (string)$this->settings->get('default_model', ''),
            'pending' => $this->events->pending($shipId, 10),
            'title' => 'NPC ' . $profile['character_name'] . ' - Admin',
        ]);
    }

    public function replay(int $shipId, string $wakeId): void
    {
        $this->adminAuth->requireAuth();
        if (!preg_match('/^[0-9a-f-]{36}$/i', $wakeId)) {
            header('Location: /admin/npcs/' . $shipId);
            exit;
        }
        $steps = $this->db->fetchAll(
            'SELECT step, model, tool, arguments, result, input_tokens, output_tokens, cost_usd, created_at
             FROM npc_action_log WHERE ship_id = :id AND wake_id = CAST(:w AS UUID) ORDER BY id',
            ['id' => $shipId, 'w' => $wakeId]
        );
        $this->render('admin_npc_replay', [
            'npc' => $this->npcs->profile($shipId),
            'steps' => $steps,
            'wakeId' => $wakeId,
            'title' => 'Wake replay - Admin',
        ]);
    }

    public function update(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        $this->checkCsrf('/admin/npcs/' . $shipId);
        $profile = $this->npcs->profile($shipId);
        if (!$profile) {
            header('Location: /admin/npcs');
            exit;
        }
        $persona = $profile['persona'];
        $persona['temperament'] = mb_substr(trim((string)($_POST['temperament'] ?? '')), 0, 200);
        $persona['speech_style'] = mb_substr(trim((string)($_POST['speech_style'] ?? '')), 0, 200);
        $goals = preg_split('/\R/', (string)($_POST['goals'] ?? '')) ?: [];
        $persona['goals'] = array_slice(array_values(array_filter(array_map(fn($g) => mb_substr(trim($g), 0, 300), $goals))), 0, 6);
        $controller = in_array($_POST['controller'] ?? '', ['scripted', 'llm'], true) ? $_POST['controller'] : $profile['controller'];
        $model = trim((string)($_POST['model'] ?? ''));
        if ($model !== '' && !preg_match('#^[A-Za-z0-9._:/-]{1,100}$#', $model)) {
            $this->session->set('error', 'Model id contains invalid characters');
            header('Location: /admin/npcs/' . $shipId);
            exit;
        }
        $fallbacks = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['fallback_models'] ?? '')))));
        foreach ($fallbacks as $f) {
            if (!preg_match('#^[A-Za-z0-9._:/-]{1,100}$#', $f)) {
                $this->session->set('error', 'Fallback model ids contain invalid characters');
                header('Location: /admin/npcs/' . $shipId);
                exit;
            }
        }
        $persona['fallback_models'] = array_slice($fallbacks, 0, 3);
        $this->db->execute(
            'UPDATE npc_profiles SET persona = CAST(:p AS JSONB), controller = :c, model = :m WHERE ship_id = :id',
            ['p' => json_encode($persona), 'c' => $controller, 'm' => $model !== '' ? $model : null, 'id' => $shipId]
        );
        if ($controller === 'llm') {
            $this->npcs->patchState($shipId, 'llm', ['failures' => 0]);
        }
        $this->session->set('message', 'NPC updated');
        header('Location: /admin/npcs/' . $shipId);
        exit;
    }

    public function wake(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        $this->checkCsrf('/admin/npcs/' . $shipId);
        $this->events->queue($shipId, 'admin_wake', ['by' => 'admin']);
        $this->session->set('message', 'Wake queued. The worker will pick it up on its next loop.');
        header('Location: /admin/npcs/' . $shipId);
        exit;
    }

    // ------------------------------------------------------------ global

    public function global(): void
    {
        $this->adminAuth->requireAuth();
        $spend = $this->control->spendToday();
        $stats = $this->db->fetchOne(
            "SELECT COUNT(*) FILTER (WHERE tool = '_error') AS errors,
                    COUNT(*) FILTER (WHERE tool IS NULL OR tool = '_error') AS calls
             FROM npc_action_log WHERE created_at > now() - interval '1 hour'"
        );
        $this->render('admin_npc_global', [
            'llmEnabled' => $this->settings->llmEnabled(),
            'npcEnabled' => $this->settings->npcEnabled(),
            'settings' => [
                'default_model' => (string)$this->settings->get('default_model', ''),
                'daily_budget_usd' => (float)$this->settings->get('daily_budget_usd', 1.0),
                'global_daily_budget_usd' => (float)$this->settings->get('global_daily_budget_usd', 10.0),
                'wake_interval_min' => (int)$this->settings->get('wake_interval_min', 10),
            ],
            'spend' => $spend,
            'heartbeatAge' => $this->control->heartbeatAgeMinutes(),
            'workerAlive' => $this->control->workerAlive(),
            'errorRate' => ((int)$stats['calls']) > 0 ? (int)$stats['errors'] / (int)$stats['calls'] : null,
            'calls' => (int)$stats['calls'],
            'alerts' => $this->monitor->open(),
            'title' => 'NPC Controls - Admin',
        ]);
    }

    public function updateGlobal(): void
    {
        $this->adminAuth->requireAuth();
        $this->checkCsrf('/admin/npcs/global');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'kill_switch') {
            $this->settings->set('llm_enabled', false);
            $this->session->set('message', 'LLM control disabled. All NPCs are on scripted behaviour.');
        } elseif ($action === 'enable_llm') {
            $this->settings->set('llm_enabled', true);
            $this->session->set('message', 'LLM control enabled.');
        } elseif ($action === 'toggle_npcs') {
            $this->settings->set('enabled', !$this->settings->npcEnabled());
            $this->session->set('message', 'NPC framework ' . ($this->settings->npcEnabled() ? 'enabled' : 'disabled') . '.');
        } elseif ($action === 'save') {
            $model = trim((string)($_POST['default_model'] ?? ''));
            if ($model !== '' && !preg_match('#^[A-Za-z0-9._:/-]{1,100}$#', $model)) {
                $this->session->set('error', 'Model id contains invalid characters');
            } else {
                $this->settings->set('default_model', $model);
                $this->settings->set('daily_budget_usd', max(0, (float)($_POST['daily_budget_usd'] ?? 1)));
                $this->settings->set('global_daily_budget_usd', max(0, (float)($_POST['global_daily_budget_usd'] ?? 10)));
                $this->settings->set('wake_interval_min', max(1, (int)($_POST['wake_interval_min'] ?? 10)));
                $this->session->set('message', 'Settings saved');
            }
        } elseif ($action === 'ack') {
            $this->monitor->acknowledge((int)($_POST['alert_id'] ?? 0));
        }
        header('Location: /admin/npcs/global');
        exit;
    }

    // ------------------------------------------------------------ alignment tools

    public function alignmentPage(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        $ship = $this->db->fetchOne('SELECT ship_id, character_name, alignment, wanted_until, is_npc FROM ships WHERE ship_id = :id', ['id' => $shipId]);
        if (!$ship) {
            $this->session->set('error', 'Player not found');
            header('Location: /admin/players');
            exit;
        }
        $log = $this->db->fetchAll(
            'SELECT a.*, s.character_name AS related_name FROM alignment_log a
             LEFT JOIN ships s ON s.ship_id = a.related_ship_id
             WHERE a.ship_id = :id ORDER BY a.created_at DESC, a.id DESC LIMIT 100',
            ['id' => $shipId]
        );
        $this->render('admin_alignment', [
            'target' => $ship,
            'log' => $log,
            'rules' => $this->alignment->rules(),
            'wanted' => $this->alignment->isWanted($ship),
            'bounties' => $this->db->fetchAll(
                'SELECT b.*, s.character_name AS placed_by_name FROM bounties b LEFT JOIN ships s ON s.ship_id = b.placed_by
                 WHERE b.target_id = :id AND b.claimed_by IS NULL', ['id' => $shipId]),
            'title' => 'Alignment - Admin',
        ]);
    }

    public function adjustAlignment(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        $this->checkCsrf('/admin/players/' . $shipId . '/alignment');
        $delta = (int)($_POST['delta'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($delta === 0 || $reason === '') {
            $this->session->set('error', 'A non-zero change and a reason are both required');
        } else {
            $this->alignment->apply($shipId, $delta, 'admin_adjustment: ' . mb_substr($reason, 0, 200));
            $this->session->set('message', 'Alignment adjusted');
        }
        header('Location: /admin/players/' . $shipId . '/alignment');
        exit;
    }

    public function clearWanted(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        $this->checkCsrf('/admin/players/' . $shipId . '/alignment');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') {
            $this->session->set('error', 'A reason is required');
        } else {
            $this->db->execute('UPDATE ships SET wanted_until = NULL WHERE ship_id = :id', ['id' => $shipId]);
            $this->db->execute('DELETE FROM bounties WHERE target_id = :id AND placed_by IS NULL AND claimed_by IS NULL', ['id' => $shipId]);
            $this->db->execute(
                'INSERT INTO alignment_log (ship_id, delta, new_value, reason)
                 SELECT ship_id, 0, alignment, :r FROM ships WHERE ship_id = :id',
                ['r' => 'admin_cleared_wanted: ' . mb_substr($reason, 0, 200), 'id' => $shipId]
            );
            $this->session->set('message', 'Wanted status cleared' . ($this->alignment->rules()->isPirate((int)$this->db->fetchOne('SELECT alignment FROM ships WHERE ship_id = :id', ['id' => $shipId])['alignment']) ? ' (note: Pirates are re-flagged at the next daily drift)' : ''));
        }
        header('Location: /admin/players/' . $shipId . '/alignment');
        exit;
    }

    // ------------------------------------------------------------ helpers

    private function checkCsrf(string $redirect): void
    {
        if (!$this->session->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->session->set('error', 'Invalid request');
            header('Location: ' . $redirect);
            exit;
        }
    }

    private function render(string $view, array $data): void
    {
        $session = $this->session;
        $config = $this->config;
        $showHeader = false;
        extract($data);
        ob_start();
        include __DIR__ . '/../Views/' . $view . '.php';
        echo ob_get_clean();
    }
}
