<?php

declare(strict_types=1);

namespace BNT\Tests;

/** Starts PHP built-in servers for integration tests and stops them again. */
class HttpServers
{
    /** @var array<int,resource> */
    private static array $procs = [];

    /** @return int the port */
    public static function start(string $docRoot, string $router, array $env, string $cwd): int
    {
        $port = random_int(30000, 39000);
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $cwd,
            array_merge(getenv(), $env)
        );
        for ($i = 0; $i < 60; $i++) {
            if (@fsockopen('127.0.0.1', $port, $e, $s, 0.1)) {
                self::$procs[$port] = $proc;
                return $port;
            }
            usleep(100000);
        }
        proc_terminate($proc);
        throw new \RuntimeException('server did not start');
    }

    public static function stop(int $port): void
    {
        if (isset(self::$procs[$port])) {
            proc_terminate(self::$procs[$port]);
            proc_close(self::$procs[$port]);
            unset(self::$procs[$port]);
        }
    }
}
