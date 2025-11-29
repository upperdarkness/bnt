# BlackNova Traders Scheduler

The scheduler system manages automated game tasks that run periodically to keep the game running smoothly.

## Overview

The scheduler is **integrated into the web application** and runs automatically on every page load. It checks which tasks are due and executes them, making it efficient and requiring no external cron setup.

### How It Works

1. **Automatic Execution**: The scheduler runs on every page load via `public/index.php`
2. **Smart Timing**: It tracks last run times in the database and only executes tasks when their interval has elapsed
3. **Non-Blocking**: Tasks run quickly and don't delay page rendering
4. **Error Resilient**: If one task fails, others continue to run

### Architecture

- **`Scheduler`** (`src/Core/Scheduler.php`): Manages task registration, timing, and execution
- **`SchedulerTasks`** (`src/Core/SchedulerTasks.php`): Contains the actual task implementations
- **Database Tables**: 
  - `scheduler_tasks`: Tracks last run times for each task
  - `scheduler_log`: Logs all task executions with status and duration

### Execution Flow

```
Page Load → Initialize Scheduler → Check Each Task:
  ├─ Time since last run >= interval? 
  │  ├─ YES → Execute task → Update last_run → Log result
  │  └─ NO  → Skip task (not due yet)
  └─ Continue with normal page rendering
```

## Scheduled Tasks

The scheduler handles the following tasks:

### Turn Generation
- **Interval**: Every 2 minutes (configurable in `config.php`)
- **Function**: Adds new turns to all active players
- **Details**: Players receive turns up to the maximum limit (2500 by default)
- **Method**: `SchedulerTasks::generateTurns()`

### Port Production
- **Interval**: Every 2 minutes
- **Function**: Ports generate commodities
- **Details**: Each port type produces its specific commodity (ore, organics, goods, energy)
- **Method**: `SchedulerTasks::portProduction()`

### Planet Production
- **Interval**: Every 2 minutes
- **Function**: Planets produce resources based on colonist allocation
- **Details**: Production rates scale with colonist count and production percentages
- **Method**: `SchedulerTasks::planetProduction()`

### IGB Interest
- **Interval**: Every 2 minutes
- **Function**: Applies interest to Interplanetary Galactic Bank accounts
- **Details**: 0.1% interest per cycle on positive balances
- **Method**: `SchedulerTasks::igbInterest()`

### Rankings Update
- **Interval**: Every 30 minutes
- **Function**: Updates the top 100 player rankings
- **Details**: Rankings based on player score
- **Method**: `SchedulerTasks::updateRankings()`

### News Generation
- **Interval**: Every 15 minutes
- **Function**: Creates news from recent combat events
- **Details**: Generates headlines from ship destructions and major battles
- **Method**: `SchedulerTasks::generateNews()`

### Fighter Degradation
- **Interval**: Every 6 minutes
- **Function**: Deployed fighters decay over time
- **Details**: 1% degradation per cycle to prevent indefinite fighter deployments
- **Method**: `SchedulerTasks::degradeFighters()`

### Cleanup
- **Interval**: Every 60 minutes
- **Function**: Maintains database hygiene
- **Details**: Removes old sessions, logs, and expired invitations
- **Method**: `SchedulerTasks::cleanup()`

## Configuration

Edit `config/config.php` to adjust scheduler intervals:

```php
'scheduler' => [
    'ticks' => 6,      // Base interval in minutes (not currently used)
    'turns' => 2,      // Turn generation interval
    'ports' => 2,      // Port production interval
    'planets' => 2,    // Planet production interval
    'igb' => 2,        // IGB interest interval
    'ranking' => 30,   // Rankings update interval
    'news' => 15,      // News generation interval
    'degrade' => 6,   // Fighter degradation interval
],
```

## Database Tables

The scheduler requires these tables (created by `database/migrations/add_scheduler.sql`):

### scheduler_tasks
Tracks last run times for each task:
```sql
CREATE TABLE scheduler_tasks (
    task_name VARCHAR(50) PRIMARY KEY,
    last_run TIMESTAMP NOT NULL DEFAULT NOW(),
    interval_minutes INTEGER NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,
    description TEXT
);
```

### scheduler_log
Logs all scheduler executions:
```sql
CREATE TABLE scheduler_log (
    log_id SERIAL PRIMARY KEY,
    task_name VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('success', 'error', 'skipped')),
    result TEXT,
    duration_ms FLOAT,
    run_time TIMESTAMP DEFAULT NOW()
);
```

## How Tasks Are Registered

Tasks are registered in `public/index.php`:

```php
// Initialize scheduler
$scheduler = new Scheduler($db, $config);
$schedulerTasks = new SchedulerTasks($db, $config);

// Register scheduled tasks
$scheduler->registerTask('turn_generation', [$schedulerTasks, 'generateTurns'], 2);
$scheduler->registerTask('port_production', [$schedulerTasks, 'portProduction'], 2);
$scheduler->registerTask('planet_production', [$schedulerTasks, 'planetProduction'], 2);
$scheduler->registerTask('igb_interest', [$schedulerTasks, 'igbInterest'], 2);
$scheduler->registerTask('ranking_update', [$schedulerTasks, 'updateRankings'], 30);
$scheduler->registerTask('news_generation', [$schedulerTasks, 'generateNews'], 15);
$scheduler->registerTask('fighter_degradation', [$schedulerTasks, 'degradeFighters'], 6);
$scheduler->registerTask('cleanup', [$schedulerTasks, 'cleanup'], 60);

// Run scheduler (executes only tasks that are due)
$scheduler->run();
```

## Monitoring

### View Recent Activity
```sql
SELECT * FROM scheduler_log ORDER BY run_time DESC LIMIT 20;
```

### Check Task History
```sql
SELECT task_name, status, result, duration_ms, run_time
FROM scheduler_log
WHERE task_name = 'turn_generation'
ORDER BY run_time DESC
LIMIT 10;
```

### Verify Tasks Are Running
```sql
SELECT task_name, last_run,
       EXTRACT(EPOCH FROM (NOW() - last_run)) / 60 AS minutes_since_last_run
FROM scheduler_tasks;
```

### Check Task Status
You can check which tasks are due by examining the database:
```sql
SELECT 
    task_name,
    last_run,
    EXTRACT(EPOCH FROM (NOW() - last_run)) / 60 AS minutes_ago,
    CASE 
        WHEN EXTRACT(EPOCH FROM (NOW() - last_run)) / 60 >= interval_minutes 
        THEN 'DUE'
        ELSE 'NOT DUE'
    END AS status
FROM scheduler_tasks;
```

## Troubleshooting

### Tasks Not Running
1. **Check if scheduler is being called**: Verify `$scheduler->run()` is in `public/index.php`
2. **Check database connectivity**: Ensure database connection is working
3. **Check scheduler_log table**: Look for error entries
4. **Verify task registration**: Ensure tasks are registered in `public/index.php`

### Task Errors
1. Check scheduler log table: `SELECT * FROM scheduler_log WHERE status = 'error'`
2. Check PHP error logs for exceptions
3. Verify database connectivity
4. Check individual task methods in `SchedulerTasks.php` for issues

### Performance Issues
- Tasks run on every page load, but only execute when due (very efficient)
- Monitor `duration_ms` in `scheduler_log` to identify slow tasks
- Adjust intervals to reduce frequency if needed
- Optimize individual task queries in `SchedulerTasks.php`

## Benefits of This Approach

1. **No Cron Required**: Runs automatically on page loads
2. **Self-Regulating**: Only executes tasks when due
3. **Efficient**: Minimal overhead when tasks aren't due
4. **Resilient**: Errors in one task don't stop others
5. **Observable**: Full logging of all executions
6. **Simple**: No external dependencies or setup required

## Development

### Adding New Tasks

1. **Add task method to `src/Core/SchedulerTasks.php`**:
```php
public function myNewTask(): string
{
    // Task logic here
    // Return status message
    return "Task completed successfully";
}
```

2. **Register task in `public/index.php`**:
```php
$scheduler->registerTask('my_task', [$schedulerTasks, 'myNewTask'], 10); // 10 minutes
```

3. **Add interval to config** (optional, for reference):
```php
'scheduler' => [
    // ...
    'my_task' => 10,
],
```

### Testing Tasks

Since tasks run automatically, you can test them by:
1. Clearing the last_run time in the database:
```sql
UPDATE scheduler_tasks SET last_run = NOW() - INTERVAL '1 hour' WHERE task_name = 'my_task';
```
2. Loading any page - the task will execute
3. Check the `scheduler_log` table for results

## Security

- Scheduler runs with database credentials from config
- Executes in the same context as the web application
- Logs may contain sensitive data - protect database access
- Task methods should validate inputs and handle errors gracefully

## Performance Optimization

- Intervals should match game activity levels
- Shorter intervals = more responsive but more frequent execution
- Longer intervals = less frequent execution but less responsive
- Monitor `duration_ms` in logs to identify slow tasks
- Use batch operations for large updates
- Consider database indexes on frequently queried tables

## Best Practices

1. **Monitor regularly**: Check `scheduler_log` for errors
2. **Keep intervals reasonable**: Balance responsiveness with server load
3. **Test changes**: Test new tasks in development before deploying
4. **Handle errors gracefully**: Tasks should catch exceptions and return error messages
5. **Log meaningful results**: Return descriptive status messages from tasks
