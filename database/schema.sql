-- BlackNova Traders - PostgreSQL Schema
-- Modern version with proper constraints and indexes

-- Ships (Players)
CREATE TABLE IF NOT EXISTS ships (
    ship_id SERIAL PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    character_name VARCHAR(50) NOT NULL UNIQUE,
    ship_name VARCHAR(50),

    -- Ship stats
    hull INTEGER DEFAULT 0,
    engines INTEGER DEFAULT 0,
    power INTEGER DEFAULT 0,
    computer INTEGER DEFAULT 0,
    sensors INTEGER DEFAULT 0,
    beams INTEGER DEFAULT 0,
    torp_launchers INTEGER DEFAULT 0,
    shields INTEGER DEFAULT 0,
    armor INTEGER DEFAULT 0,
    cloak INTEGER DEFAULT 0,

    -- Cargo
    ship_ore BIGINT DEFAULT 0,
    ship_organics BIGINT DEFAULT 0,
    ship_goods BIGINT DEFAULT 0,
    ship_energy BIGINT DEFAULT 0,
    ship_colonists BIGINT DEFAULT 0,
    ship_fighters INTEGER DEFAULT 0,
    torps INTEGER DEFAULT 0,
    armor_pts INTEGER DEFAULT 100,

    -- Resources
    credits BIGINT DEFAULT 1000,
    turns INTEGER DEFAULT 1200,
    score INTEGER DEFAULT 0,

    -- Devices
    dev_warpedit INTEGER DEFAULT 0,
    dev_genesis INTEGER DEFAULT 0,
    dev_beacon INTEGER DEFAULT 0,
    dev_emerwarp INTEGER DEFAULT 0,
    dev_minedeflector INTEGER DEFAULT 0,
    dev_escapepod BOOLEAN DEFAULT FALSE,
    dev_fuelscoop BOOLEAN DEFAULT FALSE,
    dev_lssd BOOLEAN DEFAULT FALSE,

    -- Location and status
    sector INTEGER DEFAULT 1,
    planet_id INTEGER DEFAULT 0,
    on_planet BOOLEAN DEFAULT FALSE,
    ship_destroyed BOOLEAN DEFAULT FALSE,
    ship_damage REAL DEFAULT 0,

    -- Team and zone
    team INTEGER DEFAULT 0,
    cleared_defences VARCHAR(200) DEFAULT '',

    -- Character progression (also available through migrations/add_skills.sql)
    skill_trading INTEGER DEFAULT 0 CHECK (skill_trading >= 0 AND skill_trading <= 100),
    skill_combat INTEGER DEFAULT 0 CHECK (skill_combat >= 0 AND skill_combat <= 100),
    skill_engineering INTEGER DEFAULT 0 CHECK (skill_engineering >= 0 AND skill_engineering <= 100),
    skill_leadership INTEGER DEFAULT 0 CHECK (skill_leadership >= 0 AND skill_leadership <= 100),
    skill_points INTEGER DEFAULT 0 CHECK (skill_points >= 0),

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    turns_used INTEGER DEFAULT 0,

    CONSTRAINT chk_turns CHECK (turns >= 0 AND turns <= 2500)
);

CREATE INDEX idx_ships_email ON ships(email);
CREATE INDEX idx_ships_sector ON ships(sector);
CREATE INDEX idx_ships_team ON ships(team);

-- Universe (Sectors)
CREATE TABLE IF NOT EXISTS universe (
    sector_id SERIAL PRIMARY KEY,
    sector_name VARCHAR(100),
    zone_id INTEGER DEFAULT 1,
    port_type VARCHAR(20) DEFAULT 'none',
    port_colonists BIGINT DEFAULT 0,
    is_starbase BOOLEAN DEFAULT FALSE,
    port_organics BIGINT DEFAULT 0,
    port_ore BIGINT DEFAULT 0,
    port_goods BIGINT DEFAULT 0,
    port_energy BIGINT DEFAULT 0,
    beacon TEXT DEFAULT '',

    CONSTRAINT chk_port_type CHECK (port_type IN ('none', 'ore', 'organics', 'goods', 'energy', 'special'))
);

CREATE INDEX idx_universe_zone ON universe(zone_id);
CREATE INDEX idx_universe_port ON universe(port_type);

-- Links (Sector connections)
CREATE TABLE IF NOT EXISTS links (
    link_id SERIAL PRIMARY KEY,
    link_start INTEGER NOT NULL,
    link_dest INTEGER NOT NULL,

    CONSTRAINT fk_link_start FOREIGN KEY (link_start) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT fk_link_dest FOREIGN KEY (link_dest) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT chk_no_self_link CHECK (link_start != link_dest)
);

CREATE INDEX idx_links_start ON links(link_start);
CREATE INDEX idx_links_dest ON links(link_dest);
CREATE UNIQUE INDEX idx_links_unique ON links(link_start, link_dest);

-- Planets
CREATE TABLE IF NOT EXISTS planets (
    planet_id SERIAL PRIMARY KEY,
    planet_name VARCHAR(100) NOT NULL,
    sector_id INTEGER NOT NULL,
    owner INTEGER DEFAULT NULL,
    corp INTEGER DEFAULT 0,

    -- Resources
    organics BIGINT DEFAULT 0,
    ore BIGINT DEFAULT 0,
    goods BIGINT DEFAULT 0,
    energy BIGINT DEFAULT 0,
    credits BIGINT DEFAULT 0,
    colonists BIGINT DEFAULT 0,

    -- Production percentages
    prod_ore REAL DEFAULT 20.0,
    prod_organics REAL DEFAULT 20.0,
    prod_goods REAL DEFAULT 20.0,
    prod_energy REAL DEFAULT 20.0,
    prod_fighters REAL DEFAULT 10.0,
    prod_torp REAL DEFAULT 10.0,

    -- Defense
    fighters INTEGER DEFAULT 0,
    torps INTEGER DEFAULT 0,
    base BOOLEAN DEFAULT FALSE,
    defeated BOOLEAN DEFAULT FALSE,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_planet_sector FOREIGN KEY (sector_id) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT fk_planet_owner FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET NULL,
    CONSTRAINT chk_prod_total CHECK (prod_ore + prod_organics + prod_goods + prod_energy + prod_fighters + prod_torp = 100)
);

CREATE INDEX idx_planets_sector ON planets(sector_id);
CREATE INDEX idx_planets_owner ON planets(owner);
CREATE INDEX idx_planets_corp ON planets(corp);

-- Sector Defenses (Mines and Fighters)
CREATE TABLE IF NOT EXISTS sector_defence (
    defence_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    sector_id INTEGER NOT NULL,
    defence_type CHAR(1) NOT NULL,
    quantity INTEGER DEFAULT 0,

    CONSTRAINT fk_defence_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_defence_sector FOREIGN KEY (sector_id) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT chk_defence_type CHECK (defence_type IN ('F', 'M'))
);

CREATE INDEX idx_defence_ship ON sector_defence(ship_id);
CREATE INDEX idx_defence_sector ON sector_defence(sector_id);

-- Teams
CREATE TABLE IF NOT EXISTS teams (
    id SERIAL PRIMARY KEY,
    team_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    creator INTEGER NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_team_creator FOREIGN KEY (creator) REFERENCES ships(ship_id) ON DELETE CASCADE
);

-- Zones
CREATE TABLE IF NOT EXISTS zones (
    zone_id SERIAL PRIMARY KEY,
    zone_name VARCHAR(50) NOT NULL,
    owner INTEGER DEFAULT NULL,
    corp_zone BOOLEAN DEFAULT FALSE,

    CONSTRAINT fk_zone_owner FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET NULL
);

-- Insert default zones
INSERT INTO zones (zone_id, zone_name, owner, corp_zone) VALUES
(1, 'Neutral Zone', NULL, FALSE),
(2, 'Federation Space', NULL, TRUE),
(3, 'Free Trade Zone', NULL, FALSE),
(4, 'War Zone', NULL, FALSE)
ON CONFLICT (zone_id) DO NOTHING;

-- Explicit seed IDs must not collide with the next player-created zone.
SELECT setval(pg_get_serial_sequence('zones', 'zone_id'),
    GREATEST((SELECT MAX(zone_id) FROM zones),
             (SELECT last_value FROM zones_zone_id_seq)), true);

-- Messages/Mail
CREATE TABLE IF NOT EXISTS messages (
    message_id SERIAL PRIMARY KEY,
    from_id INTEGER NOT NULL,
    to_id INTEGER NOT NULL,
    subject VARCHAR(100),
    message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read BOOLEAN DEFAULT FALSE,

    CONSTRAINT fk_message_from FOREIGN KEY (from_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_to FOREIGN KEY (to_id) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_messages_to ON messages(to_id);
CREATE INDEX idx_messages_read ON messages(read);

-- Logs
CREATE TABLE IF NOT EXISTS logs (
    log_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    log_type INTEGER NOT NULL,
    log_data TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_log_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_logs_ship ON logs(ship_id);
CREATE INDEX idx_logs_time ON logs(logged_at);

-- News
CREATE TABLE IF NOT EXISTS news (
    news_id SERIAL PRIMARY KEY,
    headline VARCHAR(200) NOT NULL,
    newstext TEXT,
    user_id INTEGER DEFAULT 0,
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    news_type VARCHAR(50) DEFAULT 'general',

    CONSTRAINT fk_news_user FOREIGN KEY (user_id) REFERENCES ships(ship_id) ON DELETE SET DEFAULT
);

CREATE INDEX idx_news_date ON news(date DESC);
CREATE INDEX idx_news_type ON news(news_type);

-- IGB (Intergalactic Bank)
CREATE TABLE IF NOT EXISTS ibank_accounts (
    account_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL UNIQUE,
    balance BIGINT DEFAULT 0,
    loan BIGINT DEFAULT 0,
    loantime TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_ibank_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT chk_balance CHECK (balance >= 0)
);

CREATE INDEX idx_ibank_ship ON ibank_accounts(ship_id);

-- Trade Routes
CREATE TABLE IF NOT EXISTS traderoutes (
    traderoute_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    source_sector INTEGER NOT NULL,
    dest_sector INTEGER NOT NULL,
    source_type VARCHAR(20),
    dest_type VARCHAR(20),
    move_type VARCHAR(20),

    CONSTRAINT fk_traderoute_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_traderoute_source FOREIGN KEY (source_sector) REFERENCES universe(sector_id) ON DELETE CASCADE,
    CONSTRAINT fk_traderoute_dest FOREIGN KEY (dest_sector) REFERENCES universe(sector_id) ON DELETE CASCADE
);

CREATE INDEX idx_traderoutes_ship ON traderoutes(ship_id);

-- Bounty
CREATE TABLE IF NOT EXISTS bounty (
    bounty_id SERIAL PRIMARY KEY,
    bounty_on INTEGER NOT NULL,
    placed_by INTEGER NOT NULL,
    amount BIGINT NOT NULL,
    placed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_bounty_target FOREIGN KEY (bounty_on) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_bounty_placer FOREIGN KEY (placed_by) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT chk_bounty_amount CHECK (amount > 0)
);

CREATE INDEX idx_bounty_target ON bounty(bounty_on);

-- Scheduler
CREATE TABLE IF NOT EXISTS scheduler (
    scheduler_id SERIAL PRIMARY KEY,
    last_run TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    task_name VARCHAR(50) NOT NULL UNIQUE
);

-- Movement Log
CREATE TABLE IF NOT EXISTS movement_log (
    movement_id SERIAL PRIMARY KEY,
    ship_id INTEGER NOT NULL,
    sector_id INTEGER NOT NULL,
    time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_movement_ship FOREIGN KEY (ship_id) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_movement_ship ON movement_log(ship_id);
CREATE INDEX idx_movement_time ON movement_log(time);

-- IP Bans
CREATE TABLE IF NOT EXISTS ip_bans (
    ban_id SERIAL PRIMARY KEY,
    ip_address INET NOT NULL UNIQUE,
    reason TEXT,
    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    banned_by INTEGER,

    CONSTRAINT fk_ban_admin FOREIGN KEY (banned_by) REFERENCES ships(ship_id) ON DELETE SET NULL
);

CREATE INDEX idx_ipban_address ON ip_bans(ip_address);

-- IGB Transfers Log
CREATE TABLE IF NOT EXISTS igb_transfers (
    transfer_id SERIAL PRIMARY KEY,
    from_ship INTEGER NOT NULL,
    to_ship INTEGER NOT NULL,
    amount BIGINT NOT NULL,
    transfer_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transfer_from FOREIGN KEY (from_ship) REFERENCES ships(ship_id) ON DELETE CASCADE,
    CONSTRAINT fk_transfer_to FOREIGN KEY (to_ship) REFERENCES ships(ship_id) ON DELETE CASCADE
);

CREATE INDEX idx_transfers_from ON igb_transfers(from_ship);
CREATE INDEX idx_transfers_to ON igb_transfers(to_ship);
CREATE INDEX idx_transfers_time ON igb_transfers(transfer_time);

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

CREATE INDEX idx_attack_logs_attacker ON attack_logs(attacker_id);
CREATE INDEX idx_attack_logs_defender ON attack_logs(defender_id);
CREATE INDEX idx_attack_logs_timestamp ON attack_logs(timestamp DESC);

-- Durable publication markers survive news retention and prevent duplicates.
ALTER TABLE attack_logs ADD COLUMN IF NOT EXISTS news_published BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE news ADD COLUMN IF NOT EXISTS source_attack_log_id INTEGER;
CREATE UNIQUE INDEX IF NOT EXISTS idx_news_source_attack ON news(source_attack_log_id);
CREATE INDEX IF NOT EXISTS idx_attack_logs_unpublished_news ON attack_logs(log_id)
    WHERE news_published = FALSE AND result = 'destroyed' AND attack_type IN ('ship', 'planet');

INSERT INTO scheduler_tasks (task_name, interval_minutes, description)
VALUES ('news_generation', 15, 'Publish ship destructions and planet captures')
ON CONFLICT (task_name) DO NOTHING;

-- =============================================================================
-- Alignment, Federation police, NPC framework and LLM agent interface
-- (kept identical to database/migrations/add_alignment_npcs.sql so that fresh
-- installs get the same objects; every statement is idempotent)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Ships: alignment, Wanted status, NPC flag, last known position (police pursuit)
-- ---------------------------------------------------------------------------
ALTER TABLE ships
  ADD COLUMN IF NOT EXISTS alignment     INTEGER     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS wanted_until  TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS is_npc        BOOLEAN     NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS last_known_sector INTEGER NULL,
  ADD COLUMN IF NOT EXISTS last_known_at     TIMESTAMPTZ NULL;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_ships_alignment') THEN
    ALTER TABLE ships ADD CONSTRAINT chk_ships_alignment CHECK (alignment BETWEEN -10000 AND 10000);
  END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Zones: Federation flag. Zone 2 ("Federation Space") is the default Federation
-- zone; sector 1 and its neighbours are placed in it.
-- ---------------------------------------------------------------------------
ALTER TABLE zones ADD COLUMN IF NOT EXISTS is_federation BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE zones SET is_federation = TRUE WHERE zone_id = 2;

UPDATE universe SET zone_id = 2
 WHERE sector_id = 1
    OR sector_id IN (SELECT link_dest FROM links WHERE link_start = 1);

-- ---------------------------------------------------------------------------
-- Alignment log
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alignment_log (
  id         BIGSERIAL PRIMARY KEY,
  ship_id    INTEGER NOT NULL REFERENCES ships(ship_id) ON DELETE CASCADE,
  delta      INTEGER NOT NULL,
  new_value  INTEGER NOT NULL,
  reason     TEXT    NOT NULL,
  related_ship_id INTEGER NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Bounties. The legacy `bounty` table is folded into this one so there is a
-- single payout path (Combat::collectBounty -> BountyService::claim).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bounties (
  id          BIGSERIAL PRIMARY KEY,
  target_id   INTEGER NOT NULL REFERENCES ships(ship_id) ON DELETE CASCADE,
  placed_by   INTEGER NULL REFERENCES ships(ship_id),   -- NULL = Federation
  amount      BIGINT  NOT NULL CHECK (amount > 0),
  claimed_by  INTEGER NULL REFERENCES ships(ship_id),
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  claimed_at  TIMESTAMPTZ NULL
);

-- One-time copy of legacy rows (only while the new table is still empty).
INSERT INTO bounties (target_id, placed_by, amount, created_at)
SELECT b.bounty_on, b.placed_by, b.amount, COALESCE(b.placed_at, now())
  FROM bounty b
 WHERE NOT EXISTS (SELECT 1 FROM bounties);

-- ---------------------------------------------------------------------------
-- NPC framework
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS npc_profiles (
  ship_id      INTEGER PRIMARY KEY REFERENCES ships(ship_id) ON DELETE CASCADE,
  faction      TEXT    NOT NULL CHECK (faction IN ('police','guild','xenobe','free')),
  archetype    TEXT    NOT NULL,
  controller   TEXT    NOT NULL DEFAULT 'scripted' CHECK (controller IN ('scripted','llm')),
  persona      JSONB   NOT NULL DEFAULT '{}',
  model        TEXT    NULL,
  notebook     TEXT    NOT NULL DEFAULT '' CHECK (length(notebook) <= 2000),
  state        JSONB   NOT NULL DEFAULT '{}',
  home_zone    INTEGER NULL REFERENCES zones(zone_id),
  respawn_at   TIMESTAMPTZ NULL,
  last_wake_at TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS npc_events (
  id         BIGSERIAL PRIMARY KEY,
  ship_id    INTEGER NOT NULL REFERENCES npc_profiles(ship_id) ON DELETE CASCADE,
  kind       TEXT    NOT NULL,
  payload    JSONB   NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  consumed_at TIMESTAMPTZ NULL
);

-- No foreign key on ship_id: logs survive NPC deletion for investigation.
CREATE TABLE IF NOT EXISTS npc_action_log (
  id          BIGSERIAL PRIMARY KEY,
  ship_id     INTEGER NOT NULL,
  wake_id     UUID    NOT NULL,
  step        SMALLINT NOT NULL,
  model       TEXT    NULL,
  tool        TEXT    NULL,
  arguments   JSONB   NULL,
  result      JSONB   NULL,
  input_tokens  INTEGER NULL,
  output_tokens INTEGER NULL,
  cost_usd    NUMERIC(10,6) NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS ship_known_ports (
  ship_id   INTEGER NOT NULL REFERENCES ships(ship_id) ON DELETE CASCADE,
  sector_id INTEGER NOT NULL,
  seen_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (ship_id, sector_id)
);

CREATE TABLE IF NOT EXISTS npc_worker_status (
  id           SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  heartbeat_at TIMESTAMPTZ NOT NULL
);

-- Runtime overrides for npc.* config keys (kill switch, model, budgets).
CREATE TABLE IF NOT EXISTS npc_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Anomaly and monitoring alerts shown on the NPC admin page.
CREATE TABLE IF NOT EXISTS npc_alerts (
  id          BIGSERIAL PRIMARY KEY,
  kind        TEXT NOT NULL,
  ship_id     INTEGER NULL,
  detail      JSONB NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  emailed_at  TIMESTAMPTZ NULL,
  acknowledged_at TIMESTAMPTZ NULL
);

-- Token bucket state for API rate limiting (one row per API token).
CREATE TABLE IF NOT EXISTS api_rate_buckets (
  bucket_key TEXT PRIMARY KEY,
  tokens     DOUBLE PRECISION NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Indexes
-- ---------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_alignment_log_ship   ON alignment_log (ship_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_bounties_open        ON bounties (target_id) WHERE claimed_by IS NULL;
CREATE INDEX IF NOT EXISTS idx_npc_events_pending   ON npc_events (ship_id) WHERE consumed_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_npc_action_log_wake  ON npc_action_log (ship_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ships_wanted         ON ships (wanted_until) WHERE wanted_until IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_ships_npc            ON ships (is_npc) WHERE is_npc;
CREATE INDEX IF NOT EXISTS idx_npc_alerts_open      ON npc_alerts (created_at DESC) WHERE acknowledged_at IS NULL;

-- ---------------------------------------------------------------------------
-- Scheduler tasks
-- ---------------------------------------------------------------------------
INSERT INTO scheduler_tasks (task_name, interval_minutes, description) VALUES
    ('npc_population',    10,   'Spawn and respawn NPCs to faction target counts'),
    ('npc_scripted_tick', 2,    'Run scripted NPC behaviours (capped at 25 NPCs / 200 ms)'),
    ('police_dispatch',   2,    'Assign Federation police to Wanted ships and recall idle police'),
    ('alignment_drift',   1440, 'Apply daily alignment drift and expire Wanted status')
ON CONFLICT (task_name) DO NOTHING;

-- Fractional trade credits carried between trades (+1 alignment per 10,000 credits traded).
ALTER TABLE ships ADD COLUMN IF NOT EXISTS trade_credit_accum BIGINT NOT NULL DEFAULT 0;

-- Alignment tier of each party at the time of the fight (shown in combat logs; never the number).
ALTER TABLE attack_logs
  ADD COLUMN IF NOT EXISTS attacker_tier TEXT NULL,
  ADD COLUMN IF NOT EXISTS defender_tier TEXT NULL;

-- Contraband trade good (identical to database/migrations/add_contraband.sql)
ALTER TABLE ships    ADD COLUMN IF NOT EXISTS ship_contraband BIGINT NOT NULL DEFAULT 0 CHECK (ship_contraband >= 0);
ALTER TABLE universe ADD COLUMN IF NOT EXISTS is_blackmarket  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE universe ADD COLUMN IF NOT EXISTS port_contraband BIGINT  NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_universe_blackmarket ON universe (sector_id) WHERE is_blackmarket;

-- Newbie protection, NPC journalist, generated rumours (identical to database/migrations/add_protection_news_rumours.sql)

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'ships' AND column_name = 'protection_state') THEN
    ALTER TABLE ships ADD COLUMN protection_state TEXT NOT NULL DEFAULT 'protected'
        CHECK (protection_state IN ('protected','grace','none'));
    UPDATE ships SET protection_state = 'none';
  END IF;
END $$;

ALTER TABLE ships
  ADD COLUMN IF NOT EXISTS protection_ended_at     TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS active_days             INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_active_date        DATE NULL,
  ADD COLUMN IF NOT EXISTS respawn_shield_until    TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS last_respawn_shield_at  TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS interview_opt_out       BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS signup_ip               TEXT NULL,
  ADD COLUMN IF NOT EXISTS signup_device           TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_ships_protection ON ships (protection_state) WHERE protection_state <> 'none';
CREATE INDEX IF NOT EXISTS idx_ships_signup_ip  ON ships (signup_ip) WHERE signup_ip IS NOT NULL;

-- Galactic News: `headline` already exists on the original table, so only source and status are new.
ALTER TABLE news ADD COLUMN IF NOT EXISTS headline TEXT NULL;
ALTER TABLE news ADD COLUMN IF NOT EXISTS source TEXT NOT NULL DEFAULT 'system';
ALTER TABLE news ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'published';
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_news_status') THEN
    ALTER TABLE news ADD CONSTRAINT chk_news_status CHECK (status IN ('published','pending_review','rejected'));
  END IF;
END $$;
CREATE INDEX IF NOT EXISTS idx_news_source_status ON news (source, status, date DESC);

-- The reporter is an NPC of its own faction.
ALTER TABLE npc_profiles DROP CONSTRAINT IF EXISTS npc_profiles_faction_check;
ALTER TABLE npc_profiles ADD CONSTRAINT npc_profiles_faction_check CHECK (faction IN ('police','guild','xenobe','free','press'));

CREATE TABLE IF NOT EXISTS news_candidates (
  id          BIGSERIAL PRIMARY KEY,
  score       NUMERIC(8,2) NOT NULL,
  fact_sheet  JSONB NOT NULL,
  event_ids   BIGINT[] NOT NULL,
  status      TEXT NOT NULL DEFAULT 'open'
      CHECK (status IN ('open','interviewing','written','fallback','skipped')),
  news_id     INTEGER NULL,
  kind        TEXT NOT NULL DEFAULT 'event',      -- 'event' or 'digest'
  ready_at    TIMESTAMPTZ NULL,                   -- interviews: when the reply window closes
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS interview_requests (
  id           BIGSERIAL PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES news_candidates(id) ON DELETE CASCADE,
  ship_id      INTEGER NOT NULL REFERENCES ships(ship_id) ON DELETE CASCADE,
  question     TEXT NOT NULL,
  reply        TEXT NULL CHECK (length(reply) <= 300),
  asked_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  replied_at   TIMESTAMPTZ NULL
);

CREATE TABLE IF NOT EXISTS rumour_seeds (
  id          BIGSERIAL PRIMARY KEY,
  type        TEXT NOT NULL,
  shown_facts JSONB NOT NULL,   -- what the buyer is told (possibly perturbed)
  true_facts  JSONB NOT NULL,   -- what was actually true, revealed on expiry
  truth_state TEXT NOT NULL CHECK (truth_state IN ('true','stale','false')),
  zone_scope  INTEGER NULL,     -- NULL = sold everywhere
  planted_by  INTEGER NULL REFERENCES ships(ship_id),
  expires_at  TIMESTAMPTZ NOT NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS rumour_lines (
  id        BIGSERIAL PRIMARY KEY,
  type      TEXT NOT NULL,
  template  TEXT NOT NULL CHECK (length(template) <= 400),
  approved  BOOLEAN NOT NULL DEFAULT FALSE,
  retired   BOOLEAN NOT NULL DEFAULT FALSE,
  uses      INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS rumour_purchases (
  id          BIGSERIAL PRIMARY KEY,
  ship_id     INTEGER NOT NULL REFERENCES ships(ship_id) ON DELETE CASCADE,
  seed_id     BIGINT  NOT NULL REFERENCES rumour_seeds(id) ON DELETE CASCADE,
  line_id     BIGINT  NULL REFERENCES rumour_lines(id),
  tier        TEXT    NOT NULL CHECK (tier IN ('tavern','informant')),
  port_sector INTEGER NOT NULL,
  price       BIGINT  NOT NULL,
  text        TEXT    NOT NULL,
  purchased_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (ship_id, seed_id)
);

CREATE INDEX IF NOT EXISTS idx_candidates_open   ON news_candidates (score DESC) WHERE status = 'open';
CREATE INDEX IF NOT EXISTS idx_rumour_seeds_live ON rumour_seeds (type, expires_at);
CREATE INDEX IF NOT EXISTS idx_rumour_buys_daily ON rumour_purchases (ship_id, port_sector, purchased_at);

INSERT INTO scheduler_tasks (task_name, interval_minutes, description) VALUES
    ('protection_tick', 10,  'Advance newbie protection: exit conditions and grace periods'),
    ('news_candidates', 30,  'Score events and queue journalist story candidates and interviews'),
    ('rumour_seeds',    60,  'Generate rumour seeds with their truth state and expire old ones')
ON CONFLICT (task_name) DO NOTHING;
