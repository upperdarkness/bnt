<?php

declare(strict_types=1);

namespace BNT\Core;

use BNT\Core\Database;

class Scheduler
{
    private array $tasks = [];
    private array $lastRun = [];

    public function __construct(
        private Database $db,
        private array $config
    ) {
        $this->loadLastRunTimes();
    }

    /**
     * Register a scheduled task
     */
    public function registerTask(string $name, callable $handler, int $intervalMinutes): void
    {
        if ($intervalMinutes < 1) {
            throw new \InvalidArgumentException('Task interval must be positive');
        }
        $this->tasks[$name] = [
            'handler' => $handler,
            'interval' => $intervalMinutes * 60, // Convert to seconds
        ];
    }

    /**
     * Run all due tasks
     */
    public function run(): array
    {
        $results = [];
        foreach ($this->tasks as $name => $task) {
            $results[$name] = $this->runTask($name, false);
        }
        return $results;
    }

    public function forceRun(string $taskName): array
    {
        if (!isset($this->tasks[$taskName])) {
            return ['status' => 'error', 'error' => 'Task not found'];
        }
        return $this->runTask($taskName, true);
    }

    private function runTask(string $name, bool $force): array
    {
        $task = $this->tasks[$name];
        $pdo = $this->db->getConnection();
        try {
            $pdo->beginTransaction();
            // New tasks start now, rather than receiving decades of catch-up income.
            $this->db->execute(
                'INSERT INTO scheduler_tasks (task_name, last_run, interval_minutes)
                 VALUES (:name, NOW(), :minutes) ON CONFLICT (task_name) DO NOTHING',
                ['name' => $name, 'minutes' => (int)($task['interval'] / 60)]
            );
            $row = $this->db->fetchOne(
                "SELECT *, EXTRACT(EPOCH FROM last_run AT TIME ZONE current_setting('TimeZone')) AS last_epoch,
                 EXTRACT(EPOCH FROM clock_timestamp()) AS now_epoch
                 FROM scheduler_tasks WHERE task_name = :name FOR UPDATE SKIP LOCKED",
                ['name' => $name]
            );
            if (!$row) {
                $pdo->rollBack();
                return ['status' => 'skipped', 'reason' => 'Already running'];
            }
            $elapsed = max(0, (int)floor((float)$row['now_epoch'] - (float)$row['last_epoch']));
            $this->lastRun[$name] = (int)$row['last_epoch'];
            if (!$force && (!$row['enabled'] || $elapsed < $task['interval'])) {
                $pdo->commit();
                return ['status' => 'skipped', 'next_run_in' => round(max(0, $task['interval'] - $elapsed) / 60, 1) . ' minutes'];
            }
            $cycles = $force ? 1 : max(1, min((int)floor($elapsed / $task['interval']), 720));
            $start = microtime(true);
            $handler = $task['handler'];
            if (is_array($handler) && (new \ReflectionMethod($handler[0], $handler[1]))->getNumberOfParameters() > 0) {
                $result = call_user_func($handler, $cycles);
            } else {
                $result = call_user_func($handler);
            }
            $duration = round((microtime(true) - $start) * 1000, 2);
            // Keep fractional cycles so frequent visits don't lose elapsed time.
            $remainder = $force ? 0 : $elapsed % $task['interval'];
            $this->db->execute(
                'UPDATE scheduler_tasks SET last_run = to_timestamp(:epoch) - make_interval(secs => :remainder),
                 interval_minutes = :minutes WHERE task_name = :name',
                ['epoch' => $row['now_epoch'], 'remainder' => $remainder,
                 'minutes' => (int)($task['interval'] / 60), 'name' => $name]
            );
            $this->log($name, 'success', $result, $duration);
            $pdo->commit();
            $this->lastRun[$name] = (int)$row['now_epoch'] - $remainder;
            return ['status' => 'success', 'result' => $result, 'duration' => $duration . 'ms'];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->log($name, 'error', $error->getMessage(), 0);
            return ['status' => 'error', 'error' => $error->getMessage()];
        }
    }

    /**
     * Get status of all tasks
     */
    public function getStatus(): array
    {
        $status = [];
        $currentTime = time();

        foreach ($this->tasks as $name => $task) {
            $lastRunTime = $this->lastRun[$name] ?? 0;
            $timeSinceLastRun = $currentTime - $lastRunTime;
            $timeUntilNextRun = max(0, $task['interval'] - $timeSinceLastRun);

            $status[$name] = [
                'interval' => round($task['interval'] / 60, 1) . ' minutes',
                'last_run' => $lastRunTime > 0 ? date('Y-m-d H:i:s', $lastRunTime) : 'Never',
                'time_since_last_run' => round($timeSinceLastRun / 60, 1) . ' minutes',
                'next_run_in' => round($timeUntilNextRun / 60, 1) . ' minutes',
                'is_due' => $timeSinceLastRun >= $task['interval']
            ];
        }

        return $status;
    }

    /**
     * Load last run times from database
     */
    private function loadLastRunTimes(): void
    {
        $results = $this->db->fetchAll("SELECT task_name,
            EXTRACT(EPOCH FROM last_run AT TIME ZONE current_setting('TimeZone')) AS last_epoch
            FROM scheduler_tasks");

        foreach ($results as $row) {
            $this->lastRun[$row['task_name']] = (int)$row['last_epoch'];
        }
    }

    /**
     * Log scheduler activity
     */
    private function log(string $taskName, string $status, $result, float $duration): void
    {
        $resultStr = is_array($result) ? json_encode($result) : (string)$result;

        $this->db->execute(
            'INSERT INTO scheduler_log (task_name, status, result, duration_ms, run_time)
             VALUES (:name, :status, :result, :duration, NOW())',
            [
                'name' => $taskName,
                'status' => $status,
                'result' => substr($resultStr, 0, 1000),
                'duration' => $duration
            ]
        );

        // Keep only last 1000 log entries
        $this->db->execute(
            'DELETE FROM scheduler_log WHERE log_id NOT IN (
                SELECT log_id FROM (
                    SELECT log_id FROM scheduler_log ORDER BY run_time DESC LIMIT 1000
                ) tmp
            )'
        );
    }
}
