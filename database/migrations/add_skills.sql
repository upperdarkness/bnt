-- Add skill system to ships table
-- Run this migration to add character skill progression

ALTER TABLE ships
ADD COLUMN IF NOT EXISTS skill_trading INTEGER DEFAULT 0 CHECK (skill_trading >= 0 AND skill_trading <= 100),
ADD COLUMN IF NOT EXISTS skill_combat INTEGER DEFAULT 0 CHECK (skill_combat >= 0 AND skill_combat <= 100),
ADD COLUMN IF NOT EXISTS skill_engineering INTEGER DEFAULT 0 CHECK (skill_engineering >= 0 AND skill_engineering <= 100),
ADD COLUMN IF NOT EXISTS skill_leadership INTEGER DEFAULT 0 CHECK (skill_leadership >= 0 AND skill_leadership <= 100),
ADD COLUMN IF NOT EXISTS skill_points INTEGER DEFAULT 0 CHECK (skill_points >= 0);

-- Add comments for documentation
COMMENT ON COLUMN ships.skill_trading IS 'Trading skill level (0-100): Improves port prices and reduces fees';
COMMENT ON COLUMN ships.skill_combat IS 'Combat skill level (0-100): Increases damage dealt in combat';
COMMENT ON COLUMN ships.skill_engineering IS 'Engineering skill level (0-100): Reduces ship upgrade costs';
COMMENT ON COLUMN ships.skill_leadership IS 'Leadership skill level (0-100): Provides team bonuses';
COMMENT ON COLUMN ships.skill_points IS 'Unallocated skill points earned through gameplay';
