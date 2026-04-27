-- Add name_font column to ork_mundane for player profile custom name font
ALTER TABLE ork_mundane ADD COLUMN IF NOT EXISTS name_font varchar(100) DEFAULT NULL;
