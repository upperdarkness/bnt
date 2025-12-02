-- Add colonists to ports
-- Ports can have colonists that can be loaded onto ships

ALTER TABLE universe 
ADD COLUMN IF NOT EXISTS port_colonists BIGINT DEFAULT 0;

-- Initialize ports with some colonists (using random value between 5000 and 50000)
UPDATE universe 
SET port_colonists = CASE 
    WHEN port_type IN ('ore', 'organics', 'goods', 'energy') THEN 
        FLOOR(RANDOM() * (50000 - 5000 + 1) + 5000)::BIGINT
    ELSE 0
END
WHERE port_type != 'none' AND port_type != 'special' AND (port_colonists IS NULL OR port_colonists = 0);

COMMENT ON COLUMN universe.port_colonists IS 'Number of colonists available at this port for loading onto ships';
