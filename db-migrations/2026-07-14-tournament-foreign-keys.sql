-- Referential integrity for the InnoDB tournament child tables (enhancement).
--
-- Only FKs whose PARENT is also InnoDB can be created. Current engines:
--   ork_participant_teams        InnoDB   (parent OK)
--   ork_participant_team_members InnoDB   (child)
--   ork_point_score              InnoDB
--   ork_tournament_event / _seq  InnoDB
--   ork_bracket / ork_participant / ork_tournament   MyISAM  (cannot be FK targets)
--
-- Therefore the following FKs are intentionally SKIPPED (MyISAM parent — a cross-engine
-- FK is rejected by InnoDB, and we do NOT convert the large shared MyISAM tables here):
--   ork_point_score.bracket_id      -> ork_bracket        (MyISAM parent)
--   ork_point_score.participant_id  -> ork_participant    (MyISAM parent)
--   ork_tournament_event.tournament_id -> ork_tournament  (MyISAM parent)
--   ork_tournament_seq.tournament_id   -> ork_tournament  (MyISAM parent)
--
-- Only team_members -> teams (InnoDB -> InnoDB) is created below.
-- Idempotent: orphan cleanup no-ops when clean; FK add is guarded by name.

-- Remove roster rows whose team no longer exists so the FK can be established.
DELETE ptm FROM ork_participant_team_members ptm
  LEFT JOIN ork_participant_teams pt ON pt.team_id = ptm.team_id
  WHERE pt.team_id IS NULL;

SET @fk := (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'ork_participant_team_members'
    AND constraint_name = 'fk_ptm_team'
    AND constraint_type = 'FOREIGN KEY'
);
SET @sql := IF(@fk = 0,
  'ALTER TABLE ork_participant_team_members
     ADD CONSTRAINT fk_ptm_team FOREIGN KEY (team_id)
     REFERENCES ork_participant_teams (team_id) ON DELETE CASCADE',
  'SELECT "fk_ptm_team already exists" AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
