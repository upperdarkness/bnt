<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiMiddleware;
use BNT\Core\ApiResponse;
use BNT\Models\Ship;
use BNT\Services\AgentService;
use BNT\Services\MovementService;
use BNT\Core\Database;
use BNT\Services\NewsService;
use BNT\Services\ObservationBuilder;
use BNT\Services\RumourService;

/** agent/* endpoints: NPC accounts only. */
class ApiAgentController extends ApiBaseController
{
    public function __construct(
        private Ship $shipModel,
        private ObservationBuilder $observation,
        private MovementService $movement,
        private AgentService $agent,
        ApiMiddleware $middleware,
        private array $config,
        private ?Database $db = null,
        private ?NewsService $news = null,
        private ?RumourService $rumours = null
    ) {
        $this->middleware = $middleware;
    }

    private function requireNpc(): array
    {
        $ship = $this->requireAuth();
        if (empty($ship['is_npc'])) {
            ApiResponse::forbidden('This endpoint is for NPC accounts only');
        }
        return $ship;
    }

    /** Press endpoints accept only the press NPC's token. */
    private function requirePress(): array
    {
        $ship = $this->requireNpc();
        $row = $this->db?->fetchOne("SELECT faction FROM npc_profiles WHERE ship_id = :id", ['id' => (int)$ship['ship_id']]);
        if (($row['faction'] ?? null) !== 'press') {
            ApiResponse::forbidden('This endpoint is for the press NPC only');
        }
        return $ship;
    }

    /** GET /agent/news/candidates */
    public function newsCandidates(): void
    {
        $this->requirePress();
        ApiResponse::success([
            'candidates' => $this->news->readyCandidates(),
            'style_guide_version' => (string)($this->config['news']['prompt_version'] ?? 'v1'),
            'limits' => ['headline_chars' => 80, 'story_words' => 120],
        ]);
    }

    /** POST /agent/news/stories  {candidate_id, headline, body} or {candidate_id, fallback: true} */
    public function newsStory(): void
    {
        $this->requirePress();
        $b = $this->body();
        $result = $this->news->submitStory((int)($b['candidate_id'] ?? 0), (string)($b['headline'] ?? ''), (string)($b['body'] ?? ''), !empty($b['fallback']));
        if (!$result['accepted']) {
            ApiResponse::error('Story rejected: ' . implode('; ', $result['errors'] ?? []), $result['code'] ?? 'STORY_REJECTED', ($result['code'] ?? '') === 'NOT_FOUND' ? 404 : 422, ['errors' => $result['errors'] ?? []]);
        }
        ApiResponse::success($result);
    }

    /** GET /agent/rumours/pool-status */
    public function rumourPoolStatus(): void
    {
        $this->requirePress();
        ApiResponse::success(['pool' => $this->rumours->poolStatus(), 'batch_size' => (int)($this->config['rumours']['lines_batch_size'] ?? 20),
            'max_words' => (int)($this->config['rumours']['line_max_words'] ?? 40)]);
    }

    /** POST /agent/rumours/lines  {lines: [{type, text}]} */
    public function rumourLines(): void
    {
        $this->requirePress();
        $b = $this->body();
        $lines = is_array($b['lines'] ?? null) ? $b['lines'] : [];
        ApiResponse::success($this->rumours->submitLines($lines));
    }

    /** GET /agent/observation */
    public function observation(): void
    {
        $ship = $this->requireNpc();
        $obs = $this->observation->build($ship);
        ApiResponse::success([
            'observation' => $obs['text'],
            'events' => $obs['events'],
            'last_event_id' => $obs['last_event_id'],
        ]);
    }

    /** POST /agent/go_to/:sector */
    public function goTo(int $sector): void
    {
        $ship = $this->requireNpc();
        $result = $this->movement->goTo($ship, $sector);
        if (!$result['success']) {
            $this->respond($result);
        }
        unset($result['ship']);
        ApiResponse::success($result);
    }

    /** GET /agent/trades?max_hops=n */
    public function trades(): void
    {
        $ship = $this->requireNpc();
        $hops = (int)($_GET['max_hops'] ?? 5);
        if ($hops < 1 || $hops > 10) {
            ApiResponse::validationError(['max_hops' => 'max_hops must be between 1 and 10']);
        }
        ApiResponse::success($this->agent->findTrades($ship, $hops));
    }

    /** POST /agent/notebook  {text} */
    public function notebook(): void
    {
        $ship = $this->requireNpc();
        $b = $this->body();
        $result = $this->agent->saveNotebook((int)$ship['ship_id'], (string)($b['text'] ?? ''));
        if (!$result['success']) {
            ApiResponse::validationError(['text' => $result['error']]);
        }
        ApiResponse::success(null, 'Notebook saved');
    }
}
