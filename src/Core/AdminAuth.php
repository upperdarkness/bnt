<?php

declare(strict_types=1);

namespace BNT\Core;

class AdminAuth
{
    public function __construct(
        private Session $session,
        private array $config
    ) {}

    /**
     * Check if admin is authenticated
     */
    public function isAuthenticated(): bool
    {
        if ($this->session->get('admin_authenticated') !== true) {
            return false;
        }

        // Check session timeout (default 1 hour)
        $loginTime = $this->session->get('admin_login_time');
        $sessionLifetime = $this->config['security']['session_lifetime'] ?? 3600;
        
        if ($loginTime && (time() - $loginTime) > $sessionLifetime) {
            // Session expired
            $this->logout();
            return false;
        }

        return true;
    }

    /**
     * Authenticate admin with password
     */
    public function authenticate(string $password): bool
    {
        if (password_verify($password, $this->config['security']['admin_password'])) {
            $this->session->set('admin_authenticated', true);
            $this->session->set('admin_login_time', time());
            return true;
        }

        return false;
    }

    /**
     * Logout admin
     */
    public function logout(): void
    {
        $this->session->remove('admin_authenticated');
        $this->session->remove('admin_login_time');
    }

    /**
     * Require admin authentication, redirect if not authenticated
     */
    public function requireAuth(): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /admin/login');
            exit;
        }
    }
}
