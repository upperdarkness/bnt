-- Newbie protection, NPC journalist and generated rumours.
-- Apply with: psql -v ON_ERROR_STOP=1 --single-transaction -f database/migrations/add_protection_news_rumours.sql
-- Idempotent. Existing ships are veterans: protection is switched off for them only when the column is first added,
-- so re-running the migration never strips protection from ships created since.

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
