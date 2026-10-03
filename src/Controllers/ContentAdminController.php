<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\AdminAuth;
use BNT\Core\Database;
use BNT\Core\Session;
use BNT\Services\FeatureSettings;
use BNT\Services\NewsService;
use BNT\Services\ProtectionService;
use BNT\Services\RumourService;

/** Admin panel: news queue, rumour pool and inspector, protection controls. */
class ContentAdminController
{
    public function __construct(
        private Database $db,
        private NewsService $news,
        private RumourService $rumours,
        private ProtectionService $protection,
        private Session $session,
        private AdminAuth $adminAuth,
        private array $config
    ) {}

    // ------------------------------------------------------------------ news queue

    public function newsQueue(): void
    {
        $this->adminAuth->requireAuth();
        $pending = $this->news->pending();
        foreach ($pending as &$p) {
            $p['validator'] = $this->db->fetchOne(
                "SELECT result FROM npc_action_log WHERE tool = 'news_story' AND arguments ->> 'candidate_id' = :c ORDER BY id DESC LIMIT 1",
                ['c' => (string)($p['candidate_id'] ?? 0)]
            )['result'] ?? null;
        }
        unset($p);
        $this->render('admin_news', [
            'pending' => $pending,
            'recent' => $this->db->fetchAll("SELECT news_id, headline, date, status, news_type FROM news WHERE source = 'journalist' ORDER BY date DESC LIMIT 25"),
            'candidates' => $this->db->fetchAll("SELECT id, score, status, kind, fact_sheet, created_at FROM news_candidates ORDER BY created_at DESC LIMIT 15"),
            'switches' => $this->switches(['news.journalist_enabled', 'news.review_mode']),
            'storiesToday' => $this->news->storiesToday(),
            'title' => 'News queue - Admin',
        ]);
    }

    public function newsAction(int $newsId, string $action): void
    {
        $this->adminAuth->requireAuth();
        $this->csrf('/admin/news');
        $reason = trim((string)($_POST['reason'] ?? ''));
        $ok = match ($action) {
            'approve' => $this->news->approve($newsId),
            'edit' => $this->news->approve($newsId, (string)($_POST['headline'] ?? ''), (string)($_POST['body'] ?? '')),
            'reject' => $reason !== '' && $this->news->reject($newsId, $reason),
            'retract' => $reason !== '' && $this->news->retract($newsId, $reason),
            default => false,
        };
        if (!$ok && in_array($action, ['reject', 'retract'], true) && $reason === '') {
            $this->session->set('error', 'A reason is required (it is logged to tune the prompt)');
        } else {
            $this->session->set($ok ? 'message' : 'error', $ok ? ucfirst($action) . ' done' : 'Nothing to ' . $action);
        }
        header('Location: /admin/news');
        exit;
    }

    // ------------------------------------------------------------------ rumours

    public function rumours(): void
    {
        $this->adminAuth->requireAuth();
        $lines = $this->db->fetchAll("SELECT * FROM rumour_lines WHERE NOT retired ORDER BY type, approved, id");
        $seeds = $this->db->fetchAll(
            "SELECT s.*, (s.expires_at <= now()) AS expired,
                    (SELECT COUNT(*) FROM rumour_purchases p WHERE p.seed_id = s.id) AS buyers
             FROM rumour_seeds s ORDER BY s.created_at DESC LIMIT 40"
        );
        $buyers = [];
        foreach ($seeds as $s) {
            $buyers[$s['id']] = $this->db->fetchAll(
                'SELECT p.tier, p.port_sector, p.purchased_at, ships.character_name FROM rumour_purchases p JOIN ships ON ships.ship_id = p.ship_id WHERE p.seed_id = :s ORDER BY p.purchased_at',
                ['s' => (int)$s['id']]
            );
        }
        $split = $this->db->fetchAll("SELECT truth_state, COUNT(*) AS c FROM rumour_purchases p JOIN rumour_seeds s ON s.id = p.seed_id GROUP BY truth_state");
        $this->render('admin_rumours', [
            'lines' => $lines, 'seeds' => $seeds, 'buyers' => $buyers, 'split' => array_column($split, 'c', 'truth_state'),
            'pool' => $this->rumours->poolStatus(), 'switches' => $this->switches(['rumours.enabled', 'rumours.require_line_approval']),
            'title' => 'Rumours - Admin',
        ]);
    }

    public function lineAction(int $lineId, string $action): void
    {
        $this->adminAuth->requireAuth();
        $this->csrf('/admin/rumours');
        if ($action === 'approve') {
            $this->db->execute('UPDATE rumour_lines SET approved = TRUE WHERE id = :id', ['id' => $lineId]);
        } elseif ($action === 'retire') {
            $this->db->execute('UPDATE rumour_lines SET retired = TRUE, approved = FALSE WHERE id = :id', ['id' => $lineId]);
        }
        $this->session->set('message', 'Line ' . $action . 'd');
        header('Location: /admin/rumours');
        exit;
    }

    public function toggle(): void
    {
        $this->adminAuth->requireAuth();
        $this->csrf('/admin');
        $key = (string)($_POST['key'] ?? '');
        if (in_array($key, FeatureSettings::KEYS, true)) {
            FeatureSettings::set($this->db, $key, ($_POST['value'] ?? '') === '1');
            $this->session->set('message', "$key updated");
        }
        header('Location: ' . (str_starts_with($key, 'rumours') ? '/admin/rumours' : (str_starts_with($key, 'news') ? '/admin/news' : '/admin/protection')));
        exit;
    }

    // ------------------------------------------------------------------ protection

    public function protection(): void
    {
        $this->adminAuth->requireAuth();
        $threshold = $this->protection->threshold();
        $rows = $this->db->fetchAll(
            "SELECT ship_id, character_name, protection_state, protection_ended_at, respawn_shield_until, active_days, score, created_at, signup_ip
             FROM ships WHERE is_npc = FALSE AND (protection_state <> 'none' OR respawn_shield_until > now()) ORDER BY created_at DESC LIMIT 200"
        );
        $this->render('admin_protection', [
            'rows' => $rows, 'threshold' => $threshold, 'flags' => $this->protection->multiAccountFlags(),
            'rules' => $this->protection->rules(), 'switches' => $this->switches(['protection.enabled']), 'title' => 'Protection - Admin',
        ]);
    }

    public function protectionAction(int $shipId, string $action): void
    {
        $this->adminAuth->requireAuth();
        $this->csrf('/admin/protection');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') {
            $this->session->set('error', 'A reason is required');
        } else {
            $action === 'grant' ? $this->protection->grant($shipId) : $this->protection->endNow($shipId);
            $this->db->execute(
                'INSERT INTO alignment_log (ship_id, delta, new_value, reason) SELECT ship_id, 0, alignment, :r FROM ships WHERE ship_id = :id',
                ['r' => 'admin_protection_' . $action . ': ' . mb_substr($reason, 0, 180), 'id' => $shipId]
            );
            $this->session->set('message', 'Protection ' . ($action === 'grant' ? 'granted' : 'ended'));
        }
        header('Location: /admin/protection');
        exit;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,bool> */
    private function switches(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            [$section, $name] = explode('.', $k, 2);
            $out[$k] = (bool)($this->config[$section][$name] ?? false);
        }
        return $out;
    }

    private function csrf(string $back): void
    {
        if (!$this->session->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->session->set('error', 'Invalid request');
            header("Location: $back");
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
