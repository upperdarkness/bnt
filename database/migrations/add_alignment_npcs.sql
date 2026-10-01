-- Alignment, Federation police, NPC framework and LLM agent interface.
-- Apply with: psql -v ON_ERROR_STOP=1 --single-transaction -f database/migrations/add_alignment_npcs.sql
-- Safe to re-run: every statement is idempotent.

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
