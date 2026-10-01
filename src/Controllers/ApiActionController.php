<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiMiddleware;
use BNT\Core\ApiResponse;
use BNT\Core\Database;
use BNT\Models\Ship;
use BNT\Services\AlignmentService;
use BNT\Services\BountyService;
use BNT\Services\CombatService;
use BNT\Services\MessagingService;
use BNT\Services\PlanetTransferService;
use BNT\Services\TradeService;

/**
 * Trading, combat, defences, planets, upgrades, messaging and alignment over REST.
 * Human API clients and NPCs use exactly the same endpoints and rule checks.
 */
class ApiActionController extends ApiBaseController
{
    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private TradeService $trade,
        private CombatService $combat,
        private PlanetTransferService $planets,
        private MessagingService $messages,
        private AlignmentService $alignment,
        private BountyService $bounties,
        ApiMiddleware $middleware,
        private array $config
    ) {
        $this->middleware = $middleware;
    }

    /** POST /game/port/trade  {commodity, action, amount} */
    public function trade(): void
    {
        $ship = $this->requireAuth();
        $b = $this->body();
        $result = $this->trade->trade((int)$ship['ship_id'], (string)($b['commodity'] ?? ''), (string)($b['action'] ?? ''), $this->intParam($b, 'amount'));
        if ($result['success']) {
            $result['data'] = [
                'amount' => $result['amount'], 'unit_price' => $result['unit_price'], 'credits_delta' => $result['credits_delta'],
                'ship' => $this->cleanShip($this->shipModel->find((int)$ship['ship_id'])),
            ];
        }
        $this->respond($result);
    }

    /** POST /game/attack/ship/:id */
    public function attackShip(int $targetId): void
    {
        $ship = $this->requireAuth();
        $result = $this->combat->attackShip($ship, $targetId);
        $this->respond($result, null, $result['success'] ? ['ship' => $this->cleanShip($this->shipModel->find((int)$ship['ship_id']))] : []);
    }

    /** POST /game/attack/planet/:id */
    public function attackPlanet(int $planetId): void
    {
        $ship = $this->requireAuth();
        $result = $this->combat->attackPlanet($ship, $planetId);
        $this->respond($result, null, $result['success'] ? ['ship' => $this->cleanShip($this->shipModel->find((int)$ship['ship_id']))] : []);
    }

    /** POST /game/defences  {fighters?: n, mines?: n}  (or {type: F|M, quantity: n}) */
    public function defences(): void
    {
        $ship = $this->requireAuth();
        $b = $this->body();
        $jobs = [];
        if (isset($b['type'])) {
            $jobs[] = [substr(strtoupper((string)$b['type']), 0, 1), $this->intParam($b, 'quantity')];
        }
        if (!empty($b['fighters'])) {
            $jobs[] = ['F', (int)$b['fighters']];
        }
        if (!empty($b['mines'])) {
            $jobs[] = ['M', (int)$b['mines']];
        }
        if (!$jobs) {
            ApiResponse::validationError(['fighters' => 'Provide fighters and/or mines to deploy']);
        }
        $texts = [];
        $last = ['success' => true];
        foreach ($jobs as [$type, $qty]) {
            $ship = $this->shipModel->find((int)$ship['ship_id']);
            $last = $this->combat->deployDefence($ship, $type, $qty);
            if (!$last['success']) {
                $this->respond($last);
            }
            $texts[] = $last['text'];
        }
        ApiResponse::success(['ship' => $this->cleanShip($this->shipModel->find((int)$ship['ship_id']))], implode(' ', $texts));
    }

    /** POST /game/planet/:id/transfer  {commodity, amount, direction: to_planet|to_ship} */
    public function planetTransfer(int $planetId): void
    {
        $ship = $this->requireAuth();
        $b = $this->body();
        $result = $this->planets->transfer($ship, $planetId, (string)($b['commodity'] ?? $b['resource'] ?? ''),
            $this->intParam($b, 'amount'), (string)($b['direction'] ?? ''));
        $this->respond($result);
    }

    /** POST /game/upgrade/:component */
    public function upgrade(string $component): void
    {
        $ship = $this->requireAuth();
        $result = $this->trade->buyUpgrade($ship, $component, true);
        if ($result['success']) {
            $result['data'] = ['upgrade' => $result['result'], 'ship' => $this->cleanShip($this->shipModel->find((int)$ship['ship_id']))];
        }
        $this->respond($result);
    }

    /** GET /game/messages */
    public function messagesIndex(): void
    {
        $ship = $this->requireAuth();
        $limit = (int)($_GET['limit'] ?? 20);
        ApiResponse::success(['messages' => $this->messages->inbox((int)$ship['ship_id'], $limit)]);
    }

    /** POST /game/messages  {ship_id, text, subject?} */
    public function messagesSend(): void
    {
        $ship = $this->requireAuth();
        $b = $this->body();
        $result = $this->messages->send($ship, $this->intParam($b, 'ship_id'), (string)($b['text'] ?? $b['message'] ?? ''), $b['subject'] ?? null);
        $this->respond($result, 'Message sent', ['message_id' => $result['message_id'] ?? null]);
    }

    /** GET /game/alignment */
    public function alignment(): void
    {
        $ship = $this->requireAuth();
        $rules = $this->alignment->rules();
        $id = (int)$ship['ship_id'];
        $recent = $this->db->fetchAll(
            'SELECT delta, new_value, reason, related_ship_id, created_at FROM alignment_log
             WHERE ship_id = :id ORDER BY created_at DESC, id DESC LIMIT 20',
            ['id' => $id]
        );
        ApiResponse::success([
            'alignment' => (int)$ship['alignment'],
            'tier' => $rules->label((int)$ship['alignment']),
            'wanted' => $this->alignment->isWanted($ship),
            'wanted_until' => $ship['wanted_until'],
            'open_bounty_on_you' => $this->bounties->openTotal($id),
            'fine' => $this->alignment->quoteFine($ship),
            'recent_changes' => $recent,
        ]);
    }

    /** POST /game/bounty  {target_id, amount}  (paid from the IGB balance, non-refundable) */
    public function placeBounty(): void
    {
        $ship = $this->requireAuth();
        $b = $this->body();
        $result = $this->bounties->place((int)$ship['ship_id'], $this->intParam($b, 'target_id'), $this->intParam($b, 'amount'));
        $this->respond($result, 'Bounty placed', ['amount' => $result['amount'] ?? null]);
    }

    /** POST /game/fine  (pay the Federation fine at a starbase) */
    public function payFine(): void
    {
        $ship = $this->requireAuth();
        $result = $this->alignment->payFine((int)$ship['ship_id']);
        $this->respond($result, 'Fine paid', ['fine' => $result['fine'] ?? null, 'alignment' => $result['alignment'] ?? null]);
    }
}
