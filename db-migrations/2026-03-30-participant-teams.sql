-- Tournament team data model
-- 2026-03-30
--
-- ork_participant_teams: one row per team per tournament bracket.
--   team_id       — durable PK for the team in this tournament
--   participant_id — FK to ork_participant (the bracket entry; holds team name as alias)
--   tournament_id, bracket_id — denormalized for fast lookup
--   name          — team name (mirrors ork_participant.alias, owned here)
--
-- ork_participant_team_members: roster rows — one per team member.
--   team_id       — FK to ork_participant_teams
--   mundane_id    — FK to ork_mundane
--   tournament_id — denormalized for fast lookup
--   (unique constraint prevents double-rostering a player on the same team)

SET @t1 = (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'ork_participant_teams');
SET @sql = IF(@t1 = 0,
    'CREATE TABLE ork_participant_teams (
        team_id        INT NOT NULL AUTO_INCREMENT,
        tournament_id  INT NOT NULL,
        bracket_id     INT NOT NULL,
        participant_id INT NOT NULL,
        name           VARCHAR(100) NOT NULL DEFAULT \'\',
        PRIMARY KEY (team_id),
        KEY idx_pt_tournament (tournament_id),
        KEY idx_pt_bracket    (bracket_id),
        KEY idx_pt_participant (participant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t2 = (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'ork_participant_team_members');
SET @sql = IF(@t2 = 0,
    'CREATE TABLE ork_participant_team_members (
        id            INT NOT NULL AUTO_INCREMENT,
        team_id       INT NOT NULL,
        mundane_id    INT NOT NULL,
        tournament_id INT NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_team_mundane (team_id, mundane_id),
        KEY idx_ptm_team       (team_id),
        KEY idx_ptm_mundane    (mundane_id),
        KEY idx_ptm_tournament (tournament_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
