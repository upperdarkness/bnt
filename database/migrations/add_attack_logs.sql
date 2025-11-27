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

CREATE INDEX idx_attack_logs_attacker ON attack_logs(attacker_id);
CREATE INDEX idx_attack_logs_defender ON attack_logs(defender_id);
CREATE INDEX idx_attack_logs_timestamp ON attack_logs(timestamp DESC);
