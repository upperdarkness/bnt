-- Add starbase support to universe
-- Starbases are safe zones where combat is prohibited and ships can be upgraded

ALTER TABLE universe 
ADD COLUMN IF NOT EXISTS is_starbase BOOLEAN DEFAULT FALSE;

-- Mark Sector 1 as starbase (always)
UPDATE universe 
SET is_starbase = TRUE 
WHERE sector_id = 1;

-- Create index for faster starbase lookups
CREATE INDEX IF NOT EXISTS idx_universe_starbase ON universe(is_starbase) WHERE is_starbase = TRUE;

COMMENT ON COLUMN universe.is_starbase IS 'If true, this sector is a starbase where combat is prohibited and ships can be upgraded';
