<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Core\RateLimiter;
use BNT\Models\Ship;

/**
 * Forgotten-password reset. A request never reveals whether an address has an account: the caller always gets the
 * same answer, and the email (if any) is sent after the response has gone out. Tokens are random, single use,
 * expire after an hour, and only their SHA-256 hash is stored. Admins can also issue a link by hand, which works
 * when the server has no outgoing email.
 */
class PasswordResetService
{
    public function __construct(
        private Database $db,
        private Ship $ships,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private array $config
    ) {}

    private function cfg(string $key, mixed $default): mixed
    {
        return $this->config['password_reset'][$key] ?? $default;
    }

    public function ttlMinutes(): int
    {
        return (int)$this->cfg('ttl_minutes', 60);
    }

    public function mailEnabled(): bool
    {
        return $this->mailer->enabled();
    }

    /** Public base URL for emailed links: APP_URL when set, otherwise the address of the current request. */
    public function baseUrl(): string
    {
        $configured = (string)($this->config['app_url'] ?? '');
        if ($configured !== '') {
            return $configured;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        return ($https ? 'https://' : 'http://') . $host;
    }

    public function linkFor(string $token): string
    {
        return $this->baseUrl() . '/reset-password?token=' . $token;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function looksLikeToken(string $token): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    /** Create a token for an account, cancelling any earlier unused ones. Returns the raw token (never stored). */
    private function create(int $shipId, string $by, ?string $ip): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->execute('UPDATE password_resets SET expires_at = LEAST(expires_at, now()) WHERE ship_id = :id AND used_at IS NULL', ['id' => $shipId]);
        $this->db->execute(
            'INSERT INTO password_resets (ship_id, token_hash, expires_at, requested_ip, created_by)
             VALUES (:id, :h, now() + make_interval(mins => :m), :ip, :by)',
            ['id' => $shipId, 'h' => self::hash($token), 'm' => $this->ttlMinutes(), 'ip' => $ip, 'by' => $by]
        );
        return $token;
    }

    /**
     * Database half of "I forgot my password". Returns:
     *  - ['throttled' => true, 'retry_after' => n] when this address is asking too often (independent of the account),
     *  - a mail job for deliver() when the address belongs to a player and is under the hourly limit,
     *  - null otherwise. The caller treats the last two identically.
     *
     * @return array{throttled?: bool, retry_after?: int, to?: string, name?: string, url?: string, ip?: ?string}|null
     */
    public function issueForEmail(string $email, ?string $ip): ?array
    {
        if ($ip !== null && $ip !== '') {
            $slot = $this->limiter->consume('pwreset:ip:' . $ip, (int)$this->cfg('ip_per_minute', 5));
            if (!$slot['allowed']) {
                return ['throttled' => true, 'retry_after' => $slot['retry_after']];
            }
        }
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $ship = $this->ships->findByEmail($email);
        if (!$ship || !empty($ship['is_npc'])) {
            return null;
        }
        $recent = (int)$this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM password_resets WHERE ship_id = :id AND created_by = 'user' AND created_at > now() - interval '1 hour'",
            ['id' => (int)$ship['ship_id']]
        )['c'];
        if ($recent >= (int)$this->cfg('per_account_per_hour', 3)) {
            return null;
        }
        $token = $this->create((int)$ship['ship_id'], 'user', $ip);
        return ['to' => (string)$ship['email'], 'name' => (string)$ship['character_name'], 'url' => $this->linkFor($token), 'ip' => $ip];
    }

    /** Send the email for a job from issueForEmail(). Best effort; failures are logged, never shown. */
    public function deliver(array $job): bool
    {
        $minutes = $this->ttlMinutes();
        $body = "Hello {$job['name']},\n\n"
            . "Someone asked to reset the password for your BlackNova Traders account"
            . (!empty($job['ip']) ? " (request from {$job['ip']})" : '') . ".\n\n"
            . "To choose a new password, open this link within $minutes minutes:\n{$job['url']}\n\n"
            . "If you did not ask for this, ignore this email. Your password has not been changed and the link stops working by itself.\n";
        $ok = $this->mailer->send($job['to'], 'Reset your BlackNova Traders password', $body);
        if (!$ok) {
            error_log('PasswordResetService: could not send reset email (mail driver: ' . $this->mailer->driver() . ')');
        }
        return $ok;
    }

    /** Admin helper: a fresh link for a player, without sending anything. Null for unknown accounts and NPCs. */
    public function issueForAdmin(int $shipId): ?string
    {
        $ship = $this->ships->find($shipId);
        if (!$ship || !empty($ship['is_npc'])) {
            return null;
        }
        return $this->linkFor($this->create($shipId, 'admin', null));
    }

    /** The account a usable token belongs to, or null if it is unknown, used or expired. */
    public function lookup(string $token): ?array
    {
        if (!self::looksLikeToken($token)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT r.id, r.ship_id, s.character_name, s.email FROM password_resets r JOIN ships s ON s.ship_id = r.ship_id
             WHERE r.token_hash = :h AND r.used_at IS NULL AND r.expires_at > now() AND s.is_npc = FALSE',
            ['h' => self::hash($token)]
        );
        return $row ?: null;
    }

    /** @return string|null an error message when the password is not acceptable */
    public function passwordProblem(string $password): ?string
    {
        $min = (int)($this->config['security']['password_min_length'] ?? 8);
        $max = (int)($this->config['security']['password_max_length'] ?? 72);
        if (strlen($password) < $min) {
            return "Password must be at least $min characters";
        }
        if (strlen($password) > $max) {
            return "Password must be at most $max characters";
        }
        return null;
    }

    /**
     * Set a new password with a reset token.
     *
     * @return array{ok: bool, code: string, error?: string, ship_id?: int}
     */
    public function reset(string $token, string $password): array
    {
        $problem = $this->passwordProblem($password);
        if ($problem !== null) {
            return ['ok' => false, 'code' => 'WEAK_PASSWORD', 'error' => $problem];   // the token is not used up
        }
        if (!self::looksLikeToken($token)) {
            return ['ok' => false, 'code' => 'INVALID_TOKEN', 'error' => 'This reset link is invalid or has expired.'];
        }
        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->db->fetchOne(
                'SELECT r.id, r.ship_id, s.email, s.character_name FROM password_resets r JOIN ships s ON s.ship_id = r.ship_id
                 WHERE r.token_hash = :h AND r.used_at IS NULL AND r.expires_at > now() AND s.is_npc = FALSE FOR UPDATE OF r',
                ['h' => self::hash($token)]
            );
            if (!$row) {
                if ($own) {
                    $pdo->rollBack();
                }
                return ['ok' => false, 'code' => 'INVALID_TOKEN', 'error' => 'This reset link is invalid or has expired.'];
            }
            $shipId = (int)$row['ship_id'];
            $this->db->execute('UPDATE ships SET password_hash = :p WHERE ship_id = :id', ['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $shipId]);
            $this->db->execute('UPDATE password_resets SET used_at = now() WHERE id = :id', ['id' => (int)$row['id']]);
            $this->db->execute('UPDATE password_resets SET expires_at = LEAST(expires_at, now()) WHERE ship_id = :id AND used_at IS NULL', ['id' => $shipId]);
            // Anyone holding an app token from before the reset has to log in again.
            $this->db->execute('DELETE FROM api_tokens WHERE ship_id = :id', ['id' => $shipId]);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->mailer->send((string)$row['email'], 'Your BlackNova Traders password was changed',
            "Hello {$row['character_name']},\n\nThe password for your BlackNova Traders account was just changed using a reset link.\n"
            . "If that was not you, request another reset right away and contact the game administrator.\n");
        return ['ok' => true, 'code' => 'OK', 'ship_id' => $shipId];
    }

    /** Housekeeping: forget tokens a week after they stopped being useful. */
    public function cleanup(): int
    {
        $this->db->execute("DELETE FROM password_resets WHERE expires_at < now() - interval '7 days'");
        return 0;
    }
}
