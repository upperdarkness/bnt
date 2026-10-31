<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Core\Services;

class PasswordResetTest extends DbTestCase
{
    private string $mailLog = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->mailLog = sys_get_temp_dir() . '/bnt_reset_mail_' . bin2hex(random_bytes(4)) . '.txt';
        self::$config['mail'] = ['driver' => 'log', 'log_path' => $this->mailLog, 'from' => 'game@example.com', 'from_name' => 'BNT'];
        self::$config['app_url'] = 'https://game.example.com';
        self::$svc = Services::create(self::$config, self::$db);
        $this->makeUniverse(10);
    }

    public function tearDown(): void
    {
        @unlink($this->mailLog);
        self::$config['mail']['driver'] = 'none';
        self::$config['app_url'] = '';
        self::$svc = Services::create(self::$config, self::$db);
    }

    private function resets(): \BNT\Services\PasswordResetService
    {
        return $this->svc('passwordResetService');
    }

    private function tokenFrom(array $job): string
    {
        $this->assertTrue(preg_match('#^https://game\.example\.com/reset-password\?token=([A-Za-z0-9_-]{43})$#', $job['url'], $m) === 1, $job['url']);
        return $m[1];
    }

    private function password(string $email, string $password): bool
    {
        return $this->svc('shipModel')->authenticate($email, $password) !== null;
    }

    public function testRequestStoresOnlyAHashAndEmailsTheLink(): void
    {
        $id = $this->makePlayer('Forgetful', ['sector' => 1]);
        $email = $this->ship($id)['email'];
        $job = $this->resets()->issueForEmail($email, '203.0.113.9');
        $this->assertTrue($job !== null && empty($job['throttled']));
        $token = $this->tokenFrom($job);
        $row = $this->db()->fetchOne('SELECT * FROM password_resets WHERE ship_id = :i', ['i' => $id]);
        $this->assertSame(hash('sha256', $token), trim($row['token_hash']), 'the hash is stored');
        $this->assertNotContains($token, json_encode($row), 'the token itself is not stored');
        $this->assertSame('user', $row['created_by']);
        $this->assertSame('203.0.113.9', $row['requested_ip']);
        $this->assertTrue($this->resets()->deliver($job));
        $mail = (string)file_get_contents($this->mailLog);
        $this->assertContains("To: $email", $mail);
        $this->assertContains('https://game.example.com/reset-password?token=' . $token, $mail);
        $this->assertContains('Hello Forgetful', $mail);
    }

    public function testUnknownAddressesNpcsAndJunkLookTheSameAndSendNothing(): void
    {
        $npc = $this->svc('npcService')->spawn('free', ['sector' => 2]);
        $npcEmail = $this->ship($npc['ship_id'])['email'];
        foreach (['nobody@example.com', $npcEmail, 'not an email', ''] as $addr) {
            $this->assertNull($this->resets()->issueForEmail($addr, null), "no job for '$addr'");
        }
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM password_resets')['c']);
        $this->assertFalse(is_file($this->mailLog), 'nothing was emailed');
    }

    public function testTokenWorksOnceAndSetsTheNewPassword(): void
    {
        $id = $this->makePlayer('Forgetful', ['sector' => 1]);
        $email = $this->ship($id)['email'];
        $token = $this->tokenFrom($this->resets()->issueForEmail($email, null));
        $this->assertTrue($this->password($email, 'password123'), 'the old password works before the reset');
        $r = $this->resets()->reset($token, 'a-brand-new-pass');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($this->password($email, 'password123'), 'the old password stops working');
        $this->assertTrue($this->password($email, 'a-brand-new-pass'), 'the new one works');
        $again = $this->resets()->reset($token, 'another-new-pass-1');
        $this->assertFalse($again['ok']);
        $this->assertSame('INVALID_TOKEN', $again['code']);
        $this->assertFalse($this->password($email, 'another-new-pass-1'), 'a used link changes nothing');
        $this->assertNull($this->resets()->lookup($token));
        $this->assertContains('password for your BlackNova Traders account was just changed', (string)file_get_contents($this->mailLog), 'the owner is told');
    }

    public function testWeakPasswordsAreRejectedWithoutUsingUpTheLink(): void
    {
        $id = $this->makePlayer('Forgetful', ['sector' => 1]);
        $token = $this->tokenFrom($this->resets()->issueForEmail($this->ship($id)['email'], null));
        $r = $this->resets()->reset($token, 'short');
        $this->assertSame('WEAK_PASSWORD', $r['code']);
        $r = $this->resets()->reset($token, str_repeat('x', 80));
        $this->assertSame('WEAK_PASSWORD', $r['code'], 'longer than bcrypt reads');
        $this->assertNotNull($this->resets()->lookup($token), 'the link still works');
        $this->assertTrue($this->resets()->reset($token, 'long-enough-now')['ok']);
    }

    public function testExpiredForgedAndSupersededTokensAreRefused(): void
    {
        $id = $this->makePlayer('Forgetful', ['sector' => 1]);
        $email = $this->ship($id)['email'];
        $first = $this->tokenFrom($this->resets()->issueForEmail($email, null));
        $second = $this->tokenFrom($this->resets()->issueForEmail($email, null));
        $this->assertNull($this->resets()->lookup($first), 'asking again cancels the earlier link');
        $this->assertSame('INVALID_TOKEN', $this->resets()->reset($first, 'long-enough-now')['code']);
        $this->db()->execute("UPDATE password_resets SET expires_at = now() - interval '1 minute' WHERE ship_id = :i", ['i' => $id]);
        $this->assertSame('INVALID_TOKEN', $this->resets()->reset($second, 'long-enough-now')['code'], 'expired');
        foreach (['', 'abc', str_repeat('A', 43), str_repeat('A', 44), "' OR 1=1 --"] as $junk) {
            $this->assertSame('INVALID_TOKEN', $this->resets()->reset($junk, 'long-enough-now')['code'], "forged '$junk'");
        }
        $this->assertTrue($this->password($email, 'password123'), 'nothing above changed the password');
    }

    public function testResetSignsOutAppTokensAndOnlyAffectsThatAccount(): void
    {
        $a = $this->makePlayer('Alice', ['sector' => 1]);
        $b = $this->makePlayer('Bob', ['sector' => 1]);
        $this->svc('apiAuth')->generateToken($a, 'phone');
        $this->svc('apiAuth')->generateToken($b, 'phone');
        $token = $this->tokenFrom($this->resets()->issueForEmail($this->ship($a)['email'], null));
        $this->assertTrue($this->resets()->reset($token, 'long-enough-now')['ok']);
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM api_tokens WHERE ship_id = :i', ['i' => $a])['c'], 'Alice has to log in again in the app');
        $this->assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM api_tokens WHERE ship_id = :i', ['i' => $b])['c'], 'Bob is untouched');
        $this->assertTrue($this->password($this->ship($b)['email'], 'password123'));
    }

    public function testRequestsAreRateLimitedPerAccountAndPerAddress(): void
    {
        $id = $this->makePlayer('Target', ['sector' => 1]);
        $email = $this->ship($id)['email'];
        $jobs = 0;
        for ($i = 0; $i < 6; $i++) {
            if ($this->resets()->issueForEmail($email, null) !== null) {
                $jobs++;
            }
        }
        $this->assertSame(3, $jobs, 'at most three emails an hour to one account');
        $throttled = 0;
        for ($i = 0; $i < 9; $i++) {
            $r = $this->resets()->issueForEmail("nobody$i@example.com", '198.51.100.7');
            if (!empty($r['throttled'])) {
                $throttled++;
                $this->assertTrue($r['retry_after'] >= 1);
            }
        }
        $this->assertTrue($throttled >= 3, "a single address is slowed down ($throttled throttled)");
        $this->assertNull($this->resets()->issueForEmail('nobody@example.com', '198.51.100.8'), 'other addresses are unaffected');
    }

    public function testAdminLinkWorksWithoutEmailAndIsAudited(): void
    {
        $id = $this->makePlayer('Locked Out', ['sector' => 1]);
        $url = $this->resets()->issueForAdmin($id);
        $this->assertNotNull($url);
        $this->assertSame('admin', $this->db()->fetchOne('SELECT created_by FROM password_resets WHERE ship_id = :i', ['i' => $id])['created_by']);
        $this->assertFalse(is_file($this->mailLog), 'nothing was sent');
        preg_match('/token=([A-Za-z0-9_-]{43})$/', $url, $m);
        $this->assertTrue($this->resets()->reset($m[1], 'long-enough-now')['ok']);
        $npc = $this->svc('npcService')->spawn('free', ['sector' => 2]);
        $this->assertNull($this->resets()->issueForAdmin($npc['ship_id']), 'NPCs have no passwords to reset');
        $this->assertNull($this->resets()->issueForAdmin(999999));
    }

    public function testLinksUseAppUrlNotTheHostHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.example.net';
        $this->assertSame('https://game.example.com/reset-password?token=T', $this->resets()->linkFor('T'));
        self::$config['app_url'] = '';
        self::$svc = Services::create(self::$config, self::$db);
        $this->assertSame('http://evil.example.net/reset-password?token=T', $this->resets()->linkFor('T'), 'without APP_URL the request address is used');
        unset($_SERVER['HTTP_HOST']);
    }

    public function testCleanupForgetsOldTokensOnly(): void
    {
        $id = $this->makePlayer('Forgetful', ['sector' => 1]);
        $this->resets()->issueForEmail($this->ship($id)['email'], null);
        $this->db()->execute("INSERT INTO password_resets (ship_id, token_hash, expires_at) VALUES (:i, repeat('a', 64), now() - interval '8 days')", ['i' => $id]);
        $tasks = new \BNT\Core\SchedulerTasks(self::$db, self::$config);
        try {
            $tasks->cleanup();
        } catch (\Throwable $e) {
            // other housekeeping in cleanup() may reference tables an install does not have; ours runs first
        }
        $this->assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM password_resets')['c']);
    }
}
