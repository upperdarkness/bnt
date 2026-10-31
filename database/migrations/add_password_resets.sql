-- Forgotten-password reset tokens. Only a SHA-256 hash of each token is stored.
-- Apply with: psql -v ON_ERROR_STOP=1 --single-transaction -f database/migrations/add_password_resets.sql
-- Safe to re-run.
CREATE TABLE IF NOT EXISTS password_resets (
  id            BIGSERIAL PRIMARY KEY,
  ship_id       INTEGER NOT NULL REFERENCES ships(ship_id) ON DELETE CASCADE,
  token_hash    CHAR(64) NOT NULL UNIQUE,
  expires_at    TIMESTAMPTZ NOT NULL,
  used_at       TIMESTAMPTZ NULL,
  requested_ip  TEXT NULL,
  created_by    TEXT NOT NULL DEFAULT 'user' CHECK (created_by IN ('user', 'admin')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_password_resets_ship ON password_resets (ship_id, created_at DESC);
