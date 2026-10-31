<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\ApiResponse;
use BNT\Services\PasswordResetService;

/** POST /api/v1/auth/forgot-password and /api/v1/auth/reset-password (public). */
class ApiPasswordController
{
    public function __construct(private PasswordResetService $resets) {}

    private function body(): array
    {
        $data = json_decode((string)file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    public function forgot(): void
    {
        $email = (string)($this->body()['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ApiResponse::validationError(['email' => 'Valid email is required']);
        }
        $job = $this->resets->issueForEmail($email, $_SERVER['REMOTE_ADDR'] ?? null);
        if (isset($job['throttled'])) {
            header('Retry-After: ' . (int)$job['retry_after']);
            ApiResponse::error('Too many requests', 'RATE_LIMITED', 429, ['retry_after' => (int)$job['retry_after']]);
        }
        if ($job !== null) {
            $resets = $this->resets;
            register_shutdown_function(static function () use ($resets, $job): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $resets->deliver($job);
            });
        }
        // Identical whether or not the address has an account.
        ApiResponse::success(null, 'If that email address has an account, a reset link has been sent.');
    }

    public function reset(): void
    {
        $data = $this->body();
        $r = $this->resets->reset((string)($data['token'] ?? ''), (string)($data['password'] ?? ''));
        if (!$r['ok']) {
            if ($r['code'] === 'WEAK_PASSWORD') {
                ApiResponse::validationError(['password' => $r['error']]);
            }
            ApiResponse::error($r['error'] ?? 'Invalid or expired reset token', 'INVALID_TOKEN', 400);
        }
        ApiResponse::success(null, 'Password changed. Please log in again.');
    }
}
