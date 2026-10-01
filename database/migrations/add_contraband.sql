-- Contraband trade good ("Void Relics"): carried in a ship's hold, traded only at black-market sectors.
-- Apply with: psql -v ON_ERROR_STOP=1 --single-transaction -f database/migrations/add_contraband.sql
-- Idempotent.
ALTER TABLE ships    ADD COLUMN IF NOT EXISTS ship_contraband BIGINT NOT NULL DEFAULT 0 CHECK (ship_contraband >= 0);
ALTER TABLE universe ADD COLUMN IF NOT EXISTS is_blackmarket  BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE universe ADD COLUMN IF NOT EXISTS port_contraband BIGINT  NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_universe_blackmarket ON universe (sector_id) WHERE is_blackmarket;
