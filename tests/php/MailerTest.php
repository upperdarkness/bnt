<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Services\Mailer;

class MailerTest extends TestCase
{
    private static $proc = null;
    private static int $port = 0;
    private static string $inbox = '';

    public static function setUpBeforeClass(): void
    {
        self::$inbox = sys_get_temp_dir() . '/bnt_smtp_' . bin2hex(random_bytes(4)) . '.txt';
        self::$port = random_int(40000, 49000);
        self::$proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/mock_smtp.php', (string)self::$port, self::$inbox, 'mailer', 's3cret'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', self::$port, $e, $s, 0.1); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        @unlink(self::$inbox);
    }

    private function smtp(array $over = []): Mailer
    {
        return new Mailer(['mail' => $over + ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => self::$port, 'encryption' => 'none',
            'username' => 'mailer', 'password' => 's3cret', 'from' => 'game@example.com', 'from_name' => 'BlackNova Traders', 'timeout' => 5]]);
    }

    private function lastMessage(): string
    {
        $all = explode("\n=====\n", trim((string)@file_get_contents(self::$inbox)));
        return (string)end($all);
    }

    /** Decode the base64 body of a recorded message. */
    private function bodyOf(string $message): string
    {
        [, $body] = explode("\n\n", $message, 2) + [1 => ''];
        return (string)base64_decode(str_replace("\n", '', $body));
    }

    public function testSendsOverSmtpWithAuthAndEncodedBody(): void
    {
        $this->assertTrue($this->smtp()->enabled());
        $ok = $this->smtp()->send('pilot@example.com', 'Hello pilot', "Line one\n.starts with a dot\nLink: https://x.test/reset?token=abc_-1");
        $this->assertTrue($ok);
        $m = $this->lastMessage();
        $this->assertContains('From: BlackNova Traders <game@example.com>', $m);
        $this->assertContains('To: pilot@example.com', $m);
        $this->assertContains('Subject: Hello pilot', $m);
        $this->assertContains('Content-Type: text/plain; charset=UTF-8', $m);
        $this->assertContains("Line one\n.starts with a dot\nLink: https://x.test/reset?token=abc_-1", $this->bodyOf($m));
    }

    public function testBadCredentialsAreRefusedAndNothingIsDelivered(): void
    {
        $this->assertFalse($this->smtp(['password' => 'wrong'])->send('pilot@example.com', 'x', 'y'), 'bad credentials fail');
        $before = substr_count((string)@file_get_contents(self::$inbox), '=====');
        $this->smtp(['password' => 'wrong'])->send('pilot@example.com', 'x', 'y');
        $this->assertSame($before, substr_count((string)@file_get_contents(self::$inbox), '====='), 'nothing is delivered after a failed login');
    }

    public function testHeaderInjectionIsStrippedAndBadAddressesRefused(): void
    {
        $this->assertTrue($this->smtp()->send("victim@example.com\r\nBcc: evil@example.com", "Hi\r\nBcc: evil@example.com", 'body') === false,
            'an address with line breaks is not an address');
        $this->assertTrue($this->smtp()->send('pilot@example.com', "Reset\r\nBcc: evil@example.com\r\nX-Evil: 1", 'body'));
        $m = $this->lastMessage();
        $this->assertNotContains("\nBcc:", $m, 'no injected header');
        $this->assertNotContains("\nX-Evil:", $m);
        $this->assertContains('Subject: Reset Bcc: evil@example.com X-Evil: 1', $m, 'the line breaks became spaces');
        $this->assertFalse($this->smtp()->send('not-an-address', 's', 'b'));
    }

    public function testNonAsciiSubjectsAreEncoded(): void
    {
        $this->assertTrue($this->smtp()->send('pilot@example.com', 'Pasvārds atiestatīts', 'ok'));
        $this->assertContains('Subject: =?UTF-8?B?', $this->lastMessage());
    }

    public function testConnectionFailureIsAFalseNotAnException(): void
    {
        $this->assertFalse($this->smtp(['port' => 1])->send('pilot@example.com', 's', 'b'));
    }

    public function testLogAndNoneDrivers(): void
    {
        $log = sys_get_temp_dir() . '/bnt_maillog_' . bin2hex(random_bytes(4)) . '.txt';
        $mailer = new Mailer(['mail' => ['driver' => 'log', 'log_path' => $log, 'from' => 'game@example.com']]);
        $this->assertTrue($mailer->enabled());
        $this->assertTrue($mailer->send('pilot@example.com', 'Logged', 'Reset here: https://x.test/r'));
        $this->assertContains('Reset here: https://x.test/r', (string)file_get_contents($log));
        unlink($log);
        $none = new Mailer(['mail' => ['driver' => 'none']]);
        $this->assertFalse($none->enabled());
        $this->assertFalse($none->send('pilot@example.com', 's', 'b'));
    }
}
