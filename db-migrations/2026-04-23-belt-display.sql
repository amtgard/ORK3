-- Add belt_display preference to ork_mundane_design.
-- Values: 'white' (default, generic belt.svg), 'own' (earned knighthood belts),
-- 'none' (hide). Surfaced on the Playernew hero via the Design My Profile Icons tab.
ALTER TABLE ork_mundane_design
    ADD COLUMN belt_display varchar(10) NOT NULL DEFAULT 'white'
    AFTER show_beltline;
