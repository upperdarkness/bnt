<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiMiddleware;
use BNT\Core\ApiResponse;

/** Shared plumbing for the REST API controllers added with alignment/NPCs. */
abstract class ApiBaseController
{
    private const STATUS = [
        'NOT_FOUND' => 404, 'TARGET_NOT_FOUND' => 404, 'PLANET_NOT_FOUND' => 404, 'RECIPIENT_NOT_FOUND' => 404,
        'FEDSPACE_PROTECTED' => 403, 'STARBASE_NO_COMBAT' => 403, 'STARBASE_NO_DEFENCES' => 403, 'FEDSPACE_NO_DEFENCES' => 403,
        'SERVICE_REFUSED' => 403, 'NOT_OWNER' => 403, 'NOT_STARBASE' => 403, 'FORBIDDEN' => 403,
        'MESSAGE_RATE_LIMITED' => 429, 'FACTION_LOYALTY' => 403,
    ];

    protected ApiMiddleware $middleware;

    protected function requireAuth(): array
    {
        $ship = $this->middleware->requireAuth();
        if (!$ship) {
            exit; // response already sent
        }
        return $ship;
    }

    /** JSON body, falling back to form fields. */
    protected function body(): array
    {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : $_POST;
    }

    /** Drop fields that should never leave the server. */
    protected function cleanShip(array $ship): array
    {
        unset($ship['password_hash'], $ship['trade_credit_accum']);
        return $ship;
    }

    /** Send a service result as an API response. */
    protected function respond(array $result, ?string $message = null, array $data = []): void
    {
        if (!($result['success'] ?? false)) {
            $code = (string)($result['code'] ?? 'ACTION_FAILED');
            ApiResponse::error((string)($result['error'] ?? $result['text'] ?? 'Action failed'), $code, self::STATUS[$code] ?? 400);
        }
        ApiResponse::success($data + ($result['data'] ?? []), $message ?? ($result['message'] ?? $result['text'] ?? null));
    }

    protected function intParam(array $body, string $key): int
    {
        return isset($body[$key]) ? (int)$body[$key] : 0;
    }
}
