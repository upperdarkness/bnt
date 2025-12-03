-- Add port_colonists column to universe table
ALTER TABLE universe ADD COLUMN IF NOT EXISTS port_colonists BIGINT DEFAULT 0;

COMMENT ON COLUMN universe.port_colonists IS 'Number of colonists available at this port';
