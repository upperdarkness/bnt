<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiMiddleware;
use BNT\Core\ApiResponse;
use BNT\Models\Ship;
use BNT\Services\AgentService;
use BNT\Services\MovementService;
use BNT\Services\ObservationBuilder;

/** agent/* endpoints: NPC accounts only. */
class ApiAgentController extends ApiBaseController
{
    public function __construct(
        private Ship $shipModel,
        private ObservationBuilder $observation,
        private MovementService $movement,
        private AgentService $agent,
        ApiMiddleware $middleware,
        private array $config
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
