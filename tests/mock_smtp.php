<?php

declare(strict_types=1);

/**
 * Minimal SMTP server for tests: php tests/mock_smtp.php PORT OUTFILE [USER PASS]
 * Accepts connections one at a time, optionally requires AUTH (PLAIN or LOGIN), and appends each received
 * message to OUTFILE followed by a line "=====". (No STARTTLS: tests use encryption "none".)
 */
[$script, $port, $out] = [$argv[0], (int)$argv[1], $argv[2]];
$user = $argv[3] ?? '';
$pass = $argv[4] ?? '';
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
while ($conn = @stream_socket_accept($server, 3600)) {
    stream_set_timeout($conn, 5);
    $say = static fn(string $l) => fwrite($conn, $l . "\r\n");
    $say('220 mock ESMTP');
    $authed = $user === '';
    $message = null;
    while (($line = fgets($conn, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($message !== null) {
            if ($line === '.') {
                file_put_contents($out, implode("\n", $message) . "\n=====\n", FILE_APPEND);
                $message = null;
                $say('250 queued');
            } else {
                $message[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO') {
            fwrite($conn, "250-mock\r\n" . ($user !== '' ? "250-AUTH PLAIN LOGIN\r\n" : '') . "250 8BITMIME\r\n");
        } elseif ($cmd === 'AUTH') {
            if (stripos($line, 'AUTH PLAIN ') === 0) {
                $parts = explode("\0", (string)base64_decode(substr($line, 11)));
                $ok = ($parts[1] ?? '') === $user && ($parts[2] ?? '') === $pass;
            } else {   // AUTH LOGIN
                $say('334 VXNlcm5hbWU6');
                $u = base64_decode(rtrim((string)fgets($conn, 512)));
                $say('334 UGFzc3dvcmQ6');
                $p = base64_decode(rtrim((string)fgets($conn, 512)));
                $ok = $u === $user && $p === $pass;
            }
            $authed = $ok;
            $say($ok ? '235 ok' : '535 bad credentials');
        } elseif ($cmd === 'MAIL' || $cmd === 'RCPT') {
            $say($authed ? '250 ok' : '530 auth required');
        } elseif ($cmd === 'DATA') {
            $message = ["ENVELOPE: " . ($line)];
            $say('354 go');
        } elseif ($cmd === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('250 ok');
        }
    }
    fclose($conn);
}
