-- Add basic_fonts viewer preference to ork_mundane
-- When set, suppresses custom/stylistic fonts on player nameplates for THIS viewer
ALTER TABLE ork_mundane ADD COLUMN IF NOT EXISTS basic_fonts tinyint(1) NOT NULL DEFAULT 0;
