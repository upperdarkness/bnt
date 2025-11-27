-- Add ship types to the game
-- Different ship classes with unique strengths and weaknesses

ALTER TABLE ships
ADD COLUMN IF NOT EXISTS ship_type VARCHAR(20) DEFAULT 'balanced' CHECK (ship_type IN ('scout', 'merchant', 'warship', 'balanced'));

-- Add comment
COMMENT ON COLUMN ships.ship_type IS 'Ship class: scout (fast/cheap turns), merchant (cargo), warship (combat), balanced (average)';

-- Update existing ships to balanced type
UPDATE ships SET ship_type = 'balanced' WHERE ship_type IS NULL;
