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
        $currentTime = time();

        foreach ($this->tasks as $name => $task) {
            $lastRunTime = $this->lastRun[$name] ?? 0;
            $timeSinceLastRun = $currentTime - $lastRunTime;

            if ($timeSinceLastRun >= $task['interval']) {
                try {
                    $startTime = microtime(true);
                    
                    // Calculate how many cycles have been missed
                    $missedCycles = (int)floor($timeSinceLastRun / $task['interval']);
                    // Cap at 720 cycles (24 hours) to prevent excessive processing
                    // Individual tasks can apply their own limits
                    $missedCycles = max(1, min($missedCycles, 720));
                    
                    // Pass cycle information to handler if it accepts parameters
                    $handler = $task['handler'];
                    if (is_array($handler) && method_exists($handler[0], $handler[1])) {
                        $reflection = new \ReflectionMethod($handler[0], $handler[1]);
                        if ($reflection->getNumberOfParameters() > 0) {
                            $result = call_user_func($handler, $missedCycles);
                        } else {
                            $result = call_user_func($handler);
                        }
                    } else {
                        $result = call_user_func($handler);
                    }
                    
                    $duration = round((microtime(true) - $startTime) * 1000, 2);

                    $this->updateLastRunTime($name, $currentTime);
                    $this->lastRun[$name] = $currentTime;

                    $results[$name] = [
                        'status' => 'success',
                        'result' => $result,
                        'duration' => $duration . 'ms'
                    ];

                    $this->log($name, 'success', $result, $duration);
                } catch (\Exception $e) {
                    $results[$name] = [
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];

                    $this->log($name, 'error', $e->getMessage(), 0);
                }
            } else {
                $nextRun = $task['interval'] - $timeSinceLastRun;
                $results[$name] = [
                    'status' => 'skipped',
                    'next_run_in' => round($nextRun / 60, 1) . ' minutes'
                ];
            }
        }

        return $results;
    }

    /**
     * Force run a specific task regardless of schedule
     */
    public function forceRun(string $taskName): array
    {
        if (!isset($this->tasks[$taskName])) {
            return ['status' => 'error', 'error' => 'Task not found'];
        }

        try {
            $startTime = microtime(true);
            $result = call_user_func($this->tasks[$taskName]['handler']);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->updateLastRunTime($taskName, time());
            $this->lastRun[$taskName] = time();

            $this->log($taskName, 'success (forced)', $result, $duration);

            return [
                'status' => 'success',
                'result' => $result,
                'duration' => $duration . 'ms'
            ];
        } catch (\Exception $e) {
            $this->log($taskName, 'error (forced)', $e->getMessage(), 0);
            return ['status' => 'error', 'error' => $e->getMessage()];
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
        $results = $this->db->fetchAll('SELECT task_name, last_run FROM scheduler_tasks');

        foreach ($results as $row) {
            $this->lastRun[$row['task_name']] = strtotime($row['last_run']);
        }
    }

    /**
     * Update last run time in database
     */
    private function updateLastRunTime(string $taskName, int $timestamp): void
    {
        $exists = $this->db->fetchOne(
            'SELECT task_name FROM scheduler_tasks WHERE task_name = :name',
            ['name' => $taskName]
        );

        if ($exists) {
            $this->db->execute(
                'UPDATE scheduler_tasks SET last_run = :time WHERE task_name = :name',
                ['time' => date('Y-m-d H:i:s', $timestamp), 'name' => $taskName]
            );
        } else {
            $this->db->execute(
                'INSERT INTO scheduler_tasks (task_name, last_run) VALUES (:name, :time)',
                ['name' => $taskName, 'time' => date('Y-m-d H:i:s', $timestamp)]
            );
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
