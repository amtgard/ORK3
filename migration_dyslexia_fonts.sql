-- Add dyslexia_fonts viewer preference to ork_mundane
-- When set, applies Lexend (a dyslexia-friendly Google Font) site-wide for THIS viewer
ALTER TABLE ork_mundane ADD COLUMN IF NOT EXISTS dyslexia_fonts tinyint(1) NOT NULL DEFAULT 0;
