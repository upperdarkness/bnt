-- Apply using psql -v ON_ERROR_STOP=1 --single-transaction.
-- Scheduler system tables
-- Tracks last run times and execution logs for automated tasks

-- Table to track when each task last ran
CREATE TABLE IF NOT EXISTS scheduler_tasks (
    task_name VARCHAR(50) PRIMARY KEY,
    last_run TIMESTAMP NOT NULL DEFAULT NOW(),
    interval_minutes INTEGER NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,
    description TEXT
);

-- Table to log scheduler executions
CREATE TABLE IF NOT EXISTS scheduler_log (
    log_id SERIAL PRIMARY KEY,
    task_name VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('success', 'error', 'skipped')),
    result TEXT,
    duration_ms FLOAT,
    run_time TIMESTAMP DEFAULT NOW()
);

-- Create index for faster log queries
CREATE INDEX IF NOT EXISTS idx_scheduler_log_task ON scheduler_log(task_name);
CREATE INDEX IF NOT EXISTS idx_scheduler_log_time ON scheduler_log(run_time DESC);

-- Insert default scheduled tasks
INSERT INTO scheduler_tasks (task_name, interval_minutes, description) VALUES
    ('turn_generation', 2, 'Generate turns for all active players'),
    ('port_production', 2, 'Produce commodities at all ports'),
    ('planet_production', 2, 'Produce resources on all colonized planets'),
    ('igb_interest', 2, 'Apply interest to IGB accounts'),
    ('ranking_update', 30, 'Update player and team rankings'),
    ('fighter_degradation', 6, 'Degrade deployed fighters over time'),
    ('cleanup', 60, 'Clean up old logs and expired data')
ON CONFLICT (task_name) DO NOTHING;

-- Add comments
COMMENT ON TABLE scheduler_tasks IS 'Tracks scheduled tasks and their execution intervals';
COMMENT ON TABLE scheduler_log IS 'Logs all scheduler task executions for monitoring';
COMMENT ON COLUMN scheduler_tasks.interval_minutes IS 'How often the task should run (in minutes)';
COMMENT ON COLUMN scheduler_tasks.enabled IS 'Whether the task is currently active';

-- Attack Logs Table
-- Tracks all combat events for player review

CREATE TABLE IF NOT EXISTS attack_logs (
    log_id SERIAL PRIMARY KEY,
    attacker_id INTEGER NOT NULL,
    attacker_name VARCHAR(50) NOT NULL,
    defender_id INTEGER,
    defender_name VARCHAR(50),
    attack_type VARCHAR(20) NOT NULL,
    result VARCHAR(20) NOT NULL,
    damage_dealt INTEGER DEFAULT 0,
    sector INTEGER DEFAULT 0,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_attack_type CHECK (attack_type IN ('ship', 'planet', 'defense')),
    CONSTRAINT chk_result CHECK (result IN ('success', 'failure', 'destroyed', 'escaped'))
);

CREATE INDEX IF NOT EXISTS idx_attack_logs_attacker ON attack_logs(attacker_id);
CREATE INDEX IF NOT EXISTS idx_attack_logs_defender ON attack_logs(defender_id);
CREATE INDEX IF NOT EXISTS idx_attack_logs_timestamp ON attack_logs(timestamp DESC);

-- Durable publication markers survive news retention and prevent duplicates.
ALTER TABLE attack_logs ADD COLUMN IF NOT EXISTS news_published BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE news ADD COLUMN IF NOT EXISTS source_attack_log_id INTEGER;
CREATE UNIQUE INDEX IF NOT EXISTS idx_news_source_attack ON news(source_attack_log_id);
CREATE INDEX IF NOT EXISTS idx_attack_logs_unpublished_news ON attack_logs(log_id)
    WHERE news_published = FALSE AND result = 'destroyed' AND attack_type IN ('ship', 'planet');

INSERT INTO scheduler_tasks (task_name, interval_minutes, description)
VALUES ('news_generation', 15, 'Publish ship destructions and planet captures')
ON CONFLICT (task_name) DO NOTHING;
