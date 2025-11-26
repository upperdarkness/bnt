<?php

declare(strict_types=1);

namespace BNT\Core;

class Session
{
    private bool $started = false;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            $this->started = true;
        }
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function destroy(): void
    {
        if ($this->started) {
            session_destroy();
            $this->started = false;
        }
    }

    public function regenerate(): bool
    {
        return session_regenerate_id(true);
    }

    public function getUserId(): ?int
    {
        return $this->get('user_id');
    }

    public function setUserId(int $userId): void
    {
        $this->set('user_id', $userId);
        $this->regenerate();
    }

    public function isLoggedIn(): bool
    {
        return $this->has('user_id');
    }

    public function logout(): void
    {
        $this->remove('user_id');
        $this->destroy();
    }

    public function getCsrfToken(): string
    {
        if (!$this->has('csrf_token')) {
            $this->set('csrf_token', bin2hex(random_bytes(32)));
        }
        return $this->get('csrf_token');
    }

    public function validateCsrfToken(string $token): bool
    {
        return hash_equals($this->getCsrfToken(), $token);
    }
}
