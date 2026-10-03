<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiMiddleware;
use BNT\Core\ApiResponse;
use BNT\Core\Database;
use BNT\Models\Ship;
use BNT\Services\ProtectionService;
use BNT\Services\RumourService;

/** Player-facing endpoints for newbie protection, journalist news and rumours. */
class ApiContentController extends ApiBaseController
{
    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private ProtectionService $protection,
        private RumourService $rumours,
        ApiMiddleware $middleware,
        private array $config
    ) {
        $this->middleware = $middleware;
    }

    /** GET /game/protection */
    public function protection(): void
    {
        $ship = $this->requireAuth();
        $p = $this->protection->progress($ship);
        ApiResponse::success($p + ['can_opt_out' => $p['protected'], 'interview_opt_out' => (bool)$ship['interview_opt_out']]);
    }

    /** POST /game/protection/opt-out  (permanent) */
    public function optOut(): void
    {
        $ship = $this->requireAuth();
        if (!$this->protection->optOut((int)$ship['ship_id'])) {
            ApiResponse::error('You are not protected', 'NOT_PROTECTED', 400);
        }
        ApiResponse::success(null, 'Protection ended permanently');
    }

    /** GET /game/news?source=journalist|system&limit=n */
    public function news(): void
    {
        $this->requireAuth();
        $source = isset($_GET['source']) && in_array($_GET['source'], ['journalist', 'system'], true) ? $_GET['source'] : null;
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $sql = "SELECT n.news_id, n.headline, n.newstext, n.date, n.news_type, n.source, s.character_name AS byline
                FROM news n LEFT JOIN ships s ON s.ship_id = n.user_id AND n.source = 'journalist'
                WHERE n.status = 'published'" . ($source ? ' AND n.source = :src' : '') . ' ORDER BY n.date DESC, n.news_id DESC LIMIT ' . $limit;
        ApiResponse::success(['news' => $this->db->fetchAll($sql, $source ? ['src' => $source] : [])]);
    }

    /** GET /game/rumours  (tiers and prices at the current port) */
    public function rumourOffers(): void
    {
        $ship = $this->requireAuth();
        ApiResponse::success($this->rumours->offers($ship) + ['enabled' => $this->rumours->enabled()]);
    }

    /** POST /game/rumours/buy  {tier: tavern|informant} */
    public function rumourBuy(): void
    {
        $ship = $this->requireAuth();
        $b = $this->body();
        $result = $this->rumours->buy((int)$ship['ship_id'], (string)($b['tier'] ?? ''));
        $this->respond($result, null, $result['success'] ? ['rumour' => $result['rumour']] : []);
    }

    /** GET /game/rumours/log */
    public function rumourLog(): void
    {
        $ship = $this->requireAuth();
        ApiResponse::success(['rumours' => $this->rumours->log((int)$ship['ship_id'], (int)($_GET['limit'] ?? 50))]);
    }

    /** POST /game/settings/interviews  {opt_out: bool} */
    public function interviews(): void
    {
        $ship = $this->requireAuth();
        $optOut = $this->flag('opt_out');
        $this->db->getConnection()->prepare('UPDATE ships SET interview_opt_out = :v WHERE ship_id = :id')
            ->execute(['v' => $optOut ? 't' : 'f', 'id' => (int)$ship['ship_id']]);
        ApiResponse::success(['interview_opt_out' => $optOut]);
    }
}
