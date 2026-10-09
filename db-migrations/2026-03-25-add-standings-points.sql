-- Add standings_points column to ork_tournament for configurable placement point values
ALTER TABLE ork_tournament
    ADD COLUMN IF NOT EXISTS standings_points VARCHAR(64) NOT NULL DEFAULT '[5,4,3,2,1,0,0,0]';
