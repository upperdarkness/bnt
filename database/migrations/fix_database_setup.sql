-- Repair databases initialized before the issue #4 fix. Run with psql
-- -v ON_ERROR_STOP=1 --single-transaction so repairs are atomic.
ALTER TABLE zones ALTER COLUMN owner DROP DEFAULT;
ALTER TABLE zones DROP CONSTRAINT fk_zone_owner;
UPDATE zones SET owner = NULL WHERE owner = 0;
ALTER TABLE zones ADD CONSTRAINT fk_zone_owner
    FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET NULL;

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

ALTER TABLE planets ALTER COLUMN owner DROP DEFAULT;
ALTER TABLE planets DROP CONSTRAINT fk_planet_owner;
UPDATE planets SET owner = NULL WHERE owner = 0;
ALTER TABLE planets ADD CONSTRAINT fk_planet_owner
    FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET NULL;

ALTER TABLE universe ADD COLUMN IF NOT EXISTS port_colonists BIGINT DEFAULT 0;
ALTER TABLE universe ADD COLUMN IF NOT EXISTS is_starbase BOOLEAN DEFAULT FALSE;
