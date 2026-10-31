<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\AdminAuth;
use BNT\Core\Session;
use BNT\Services\PasswordResetService;

/** Web side of forgotten-password reset, plus the admin "issue a reset link" action. */
class PasswordResetController
{
    public function __construct(
        private PasswordResetService $resets,
        private Session $session,
        private AdminAuth $adminAuth
    ) {}

    private function page(string $view, array $vars = []): string
    {
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        $session = $this->session;
        $resets = $this->resets;
        extract($vars);
        ob_start();
        include __DIR__ . '/../Views/' . $view . '.php';
        return ob_get_clean();
    }

    private function csrfOk(): bool
    {
        return $this->session->validateCsrfToken((string)($_POST['csrf_token'] ?? ''));
    }

    public function showForgot(): string
    {
        return $this->page('forgot_password');
    }

    public function sendForgot(): void
    {
        if (!$this->csrfOk()) {
            $this->session->set('error', 'Invalid request. Please try again.');
            header('Location: /forgot-password');
            exit;
        }
        $job = $this->resets->issueForEmail((string)($_POST['email'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? null);
        if (isset($job['throttled'])) {
            $this->session->set('error', 'Too many requests. Please wait a few minutes and try again.');
            header('Location: /forgot-password');
            exit;
        }
        if ($job !== null) {
            $resets = $this->resets;
            // Send after the page has been delivered, so the response time does not reveal whether the address exists.
            register_shutdown_function(static function () use ($resets, $job): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $resets->deliver($job);
            });
        }
        $this->session->set('message', 'If that email address has an account, we have sent a link to reset the password. It is valid for '
            . $this->resets->ttlMinutes() . ' minutes.');
        header('Location: /');
        exit;
    }

    public function showReset(): string
    {
        $token = (string)($_GET['token'] ?? '');
        $valid = $this->resets->lookup($token) !== null;
        return $this->page('reset_password', ['token' => $token, 'valid' => $valid]);
    }

    public function doReset(): void
    {
        $token = (string)($_POST['token'] ?? '');
        $back = '/reset-password?token=' . rawurlencode($token);
        if (!$this->csrfOk()) {
            $this->session->set('error', 'Invalid request. Please try again.');
            header("Location: $back");
            exit;
        }
        $password = (string)($_POST['password'] ?? '');
        if ($password !== (string)($_POST['password_confirm'] ?? '')) {
            $this->session->set('error', 'The two passwords do not match.');
            header("Location: $back");
            exit;
        }
        $r = $this->resets->reset($token, $password);
        if (!$r['ok']) {
            $this->session->set('error', $r['error'] ?? 'Could not reset the password.');
            header('Location: ' . ($r['code'] === 'WEAK_PASSWORD' ? $back : '/forgot-password'));
            exit;
        }
        $this->session->set('message', 'Your password has been changed. You can log in now.');
        header('Location: /');
        exit;
    }

    // ------------------------------------------------------------------ admin

    public function adminIssue(int $shipId): void
    {
        $this->adminAuth->requireAuth();
        if (!$this->csrfOk()) {
            $this->session->set('error', 'Invalid request');
            header("Location: /admin/players/$shipId/edit");
            exit;
        }
        $url = $this->resets->issueForAdmin($shipId);
        if ($url === null) {
            $this->session->set('error', 'No such player.');
        } else {
            $this->session->set('reset_link', ['ship_id' => $shipId, 'url' => $url, 'minutes' => $this->resets->ttlMinutes()]);
        }
        header("Location: /admin/players/$shipId/edit");
        exit;
    }
}
