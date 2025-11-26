# BlackNova Traders Scheduler

The scheduler system manages automated game tasks that run periodically to keep the game running smoothly.

## Overview

The scheduler handles the following tasks:

### Turn Generation
- **Interval**: Every 2 minutes (configurable in `config.php`)
- **Function**: Adds new turns to all active players
- **Details**: Players receive turns up to the maximum limit (2500 by default)

### Port Production
- **Interval**: Every 2 minutes
- **Function**: Ports generate commodities
- **Details**: Each port type produces its specific commodity (ore, organics, goods, energy)

### Planet Production
- **Interval**: Every 2 minutes
- **Function**: Planets produce resources based on colonist allocation
- **Details**: Production rates scale with colonist count and production percentages

### IGB Interest
- **Interval**: Every 2 minutes
- **Function**: Applies interest to Interplanetary Galactic Bank accounts
- **Details**: 0.1% interest per cycle on positive balances

### Rankings Update
- **Interval**: Every 30 minutes
- **Function**: Updates the top 100 player rankings
- **Details**: Rankings based on player score

### News Generation
- **Interval**: Every 15 minutes
- **Function**: Creates news from recent combat events
- **Details**: Generates headlines from ship destructions and major battles

### Fighter Degradation
- **Interval**: Every 6 minutes
- **Function**: Deployed fighters decay over time
- **Details**: 1% degradation per cycle to prevent indefinite fighter deployments

### Cleanup
- **Interval**: Every 60 minutes
- **Function**: Maintains database hygiene
- **Details**: Removes old sessions, logs, and expired invitations

## Usage

### Run All Due Tasks
```bash
php bin/scheduler.php
```

### Check Task Status
```bash
php bin/scheduler.php --status
```

### Run Specific Task
```bash
php bin/scheduler.php --task=turns
```

### Force Run Task
```bash
php bin/scheduler.php --task=turns --force
```

### Show Help
```bash
php bin/scheduler.php --help
```

## Cron Setup

To run the scheduler automatically, add it to your crontab:

```bash
# Edit crontab
crontab -e

# Add this line to run every 6 minutes (matches the default tick interval)
*/6 * * * * cd /path/to/blacknova && php bin/scheduler.php >> logs/scheduler.log 2>&1
```

### Alternative: Run every minute (more responsive)
```bash
* * * * * cd /path/to/blacknova && php bin/scheduler.php >> logs/scheduler.log 2>&1
```

The scheduler will automatically skip tasks that aren't due yet, so running it more frequently than the shortest interval is safe.

## Configuration

Edit `config/config.php` to adjust scheduler intervals:

```php
'scheduler' => [
    'ticks' => 6,      // Base interval in minutes
    'turns' => 2,      // Turn generation interval
    'ports' => 2,      // Port production interval
    'planets' => 2,    // Planet production interval
    'igb' => 2,        // IGB interest interval
    'ranking' => 30,   // Rankings update interval
    'news' => 15,      // News generation interval
    'degrade' => 6,    // Fighter degradation interval
],
```

## Database Tables

The scheduler requires these tables:

### scheduler_tasks
Tracks last run times for each task:
```sql
CREATE TABLE scheduler_tasks (
    task_name VARCHAR(50) PRIMARY KEY,
    last_run TIMESTAMP NOT NULL
);
```

### scheduler_log
Logs all scheduler executions:
```sql
CREATE TABLE scheduler_log (
    log_id SERIAL PRIMARY KEY,
    task_name VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL,
    result TEXT,
    duration_ms FLOAT,
    run_time TIMESTAMP DEFAULT NOW()
);
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
WHERE task_name = 'turns'
ORDER BY run_time DESC
LIMIT 10;
```

### Verify Tasks Are Running
```sql
SELECT task_name, last_run,
       EXTRACT(EPOCH FROM (NOW() - last_run)) / 60 AS minutes_since_last_run
FROM scheduler_tasks;
```

## Troubleshooting

### Tasks Not Running
1. Check cron is configured: `crontab -l`
2. Verify scheduler is executable: `ls -l bin/scheduler.php`
3. Check scheduler logs: `tail -f logs/scheduler.log`
4. Test manually: `php bin/scheduler.php --status`

### Task Errors
1. Check scheduler log table: `SELECT * FROM scheduler_log WHERE status = 'error'`
2. Run task manually with verbose output: `php bin/scheduler.php --task=NAME --force`
3. Verify database connectivity
4. Check PHP error logs

### Performance Issues
- Adjust intervals to reduce frequency
- Monitor database performance
- Check scheduler_log for long durations
- Optimize individual task queries

## Manual Task Execution

You can run tasks manually from the admin panel or CLI:

### Via CLI
```bash
# Force generate turns right now
php bin/scheduler.php --task=turns --force

# Force update rankings
php bin/scheduler.php --task=ranking --force

# Run planet production
php bin/scheduler.php --task=planets --force
```

### Via Admin Panel
The admin panel includes a scheduler section where you can:
- View task status
- See last run times
- Force run specific tasks
- View scheduler logs

## Best Practices

1. **Run frequently**: Set cron to run every 1-6 minutes
2. **Monitor logs**: Check scheduler logs regularly for errors
3. **Backup before changes**: Back up database before modifying intervals
4. **Test changes**: Test new intervals in a development environment
5. **Log rotation**: Set up log rotation for scheduler.log

## Development

### Adding New Tasks

1. Add task method to `src/Core/SchedulerTasks.php`:
```php
public function myNewTask(): string
{
    // Task logic here
    return "Task completed successfully";
}
```

2. Register task in `bin/scheduler.php`:
```php
$scheduler->registerTask('mytask', [$tasks, 'myNewTask'], 10); // 10 minutes
```

3. Add interval to config:
```php
'scheduler' => [
    // ...
    'mytask' => 10,
],
```

### Testing Tasks

Test individual tasks before deploying:
```bash
# Test the task
php bin/scheduler.php --task=mytask --force

# Check output and logs
tail -f logs/scheduler.log
```

## Security

- Scheduler runs with database credentials from config
- Only accessible via CLI (not web accessible)
- Logs may contain sensitive data - protect log files
- Use appropriate file permissions on scheduler.php

## Performance Optimization

- Intervals should match game activity levels
- Shorter intervals = more responsive but higher load
- Longer intervals = less load but less responsive
- Monitor database query performance
- Use indexes on frequently queried tables
- Consider batch operations for large updates
