<?php

declare(strict_types=1);

namespace BNT\Services;

/**
 * Plain-text email without dependencies. Drivers: smtp (STARTTLS, implicit TLS or none, AUTH PLAIN/LOGIN),
 * mail (PHP mail()), log (appends to a file, for development and tests) and none (disabled).
 */
class Mailer
{
    public function __construct(private array $config) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config['mail'][$key] ?? $default;
    }

    public function driver(): string
    {
        return (string)$this->cfg('driver', 'none');
    }

    /** Can this installation send email at all? */
    public function enabled(): bool
    {
        return $this->driver() !== 'none';
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $to = self::clean($to);
        $subject = self::clean($subject);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            return match ($this->driver()) {
                'smtp' => $this->viaSmtp($to, $subject, $body),
                'mail' => $this->viaMail($to, $subject, $body),
                'log' => $this->viaLog($to, $subject, $body),
                default => false,
            };
        } catch (\Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            return false;
        }
    }

    /** Strip anything that could add headers. */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/[\r\n\0]+/', ' ', $value) ?? '');
    }

    private function from(): string
    {
        return self::clean((string)$this->cfg('from', 'noreply@localhost'));
    }

    private function fromHeader(): string
    {
        $name = self::clean((string)$this->cfg('from_name', ''));
        return $name === '' ? $this->from() : sprintf('%s <%s>', self::encodeWord($name), $this->from());
    }

    private static function encodeWord(string $text): string
    {
        return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
    }

    /** @return string[] */
    private function headers(string $to, string $subject): array
    {
        $domain = substr(strrchr($this->from(), '@') ?: '@localhost', 1);
        return [
            'Date: ' . date('r'),
            'From: ' . $this->fromHeader(),
            'To: ' . $to,
            'Subject: ' . self::encodeWord($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
    }

    private function encodedBody(string $body): string
    {
        return chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
    }

    private function viaLog(string $to, string $subject, string $body): bool
    {
        $entry = implode("\n", $this->headers($to, $subject)) . "\n\n" . $body . "\n=====\n";
        return file_put_contents((string)$this->cfg('log_path'), $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    private function viaMail(string $to, string $subject, string $body): bool
    {
        $headers = array_values(array_filter($this->headers($to, $subject), fn($h) => !str_starts_with($h, 'To: ') && !str_starts_with($h, 'Subject: ')));
        return @mail($to, self::encodeWord($subject), $this->encodedBody($body), implode("\r\n", $headers));
    }

    private function viaSmtp(string $to, string $subject, string $body): bool
    {
        $host = (string)$this->cfg('host', 'localhost');
        $port = (int)$this->cfg('port', 587);
        $enc = (string)$this->cfg('encryption', 'tls');
        $timeout = (int)$this->cfg('timeout', 10);
        $target = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($target, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect to $target failed: $errstr");
        }
        stream_set_timeout($fp, $timeout);
        try {
            $this->expect($fp, [220]);
            $ehlo = 'bnt.' . (preg_replace('/[^a-z0-9.-]/i', '', (string)gethostname()) ?: 'localhost');
            $caps = $this->command($fp, "EHLO $ehlo", [250]);
            if ($enc === 'tls') {
                $this->command($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS handshake failed');
                }
                $caps = $this->command($fp, "EHLO $ehlo", [250]);
            }
            $user = (string)$this->cfg('username', '');
            if ($user !== '') {
                $pass = (string)$this->cfg('password', '');
                if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') === false && stripos($caps, 'LOGIN') !== false) {
                    $this->command($fp, 'AUTH LOGIN', [334]);
                    $this->command($fp, base64_encode($user), [334]);
                    $this->command($fp, base64_encode($pass), [235]);
                } else {
                    $this->command($fp, 'AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
                }
            }
            $this->command($fp, 'MAIL FROM:<' . $this->from() . '>', [250]);
            $this->command($fp, "RCPT TO:<$to>", [250, 251]);
            $this->command($fp, 'DATA', [354]);
            $message = implode("\r\n", $this->headers($to, $subject)) . "\r\n\r\n" . $this->encodedBody($body);
            // Dot-stuffing; the base64 body cannot start a line with '.', but headers are checked as well.
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($fp, $message . "\r\n.\r\n");
            $this->expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
            return true;
        } finally {
            fclose($fp);
        }
    }

    private function command($fp, string $line, array $ok): string
    {
        fwrite($fp, $line . "\r\n");
        return $this->expect($fp, $ok);
    }

    /** Read a (possibly multi-line) reply and require one of the expected codes. */
    private function expect($fp, array $ok): string
    {
        $reply = '';
        do {
            $line = fgets($fp, 1024);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed or timed out');
            }
            $reply .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int)substr($reply, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($reply));
        }
        return $reply;
    }
}
