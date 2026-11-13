-- Treasury: optional linked player for a counterparty (To/From Player)
-- 0 = no linked player (free-text counterparty); >0 = ork_mundane.mundane_id
-- 2026-06-07
ALTER TABLE `ork_treasury_entry`
  ADD COLUMN `counterparty_player_id` int(11) NOT NULL DEFAULT 0 AFTER `counterparty`;
