-- Uniqueness backstops for the tournament registration / assignment races (#40, #99, #100).
--
-- Runs AFTER the tables + columns it constrains exist:
--   ork_point_score          (2026-05-28-add-points-bracket.sql)
--   ork_participant.participant_number (2026-03-18-participant-number.sql)
--   ork_participant_teams.team_number  (2026-06-01-team-registration.sql)
--
-- DATA MODEL: registration rows and per-bracket entrant rows share one table each,
-- distinguished by bracket_id (NULL = tournament-level registration, NOT NULL = bracket
-- entrant), and they intentionally SHARE the same participant_number / team_number across
-- brackets. A plain UNIQUE(tournament_id, participant_number) is therefore impossible on
-- the combined table. We enforce:
--   * registration-scoped uniqueness via a nullable generated column narrowed to the
--     bracket_id-IS-NULL rows (yapo never writes nullable/generated columns, so INSERT
--     paths are unaffected), and
--   * per-bracket entrant uniqueness via a composite index; the bracket_id-IS-NULL
--     registration rows carry a NULL in the key tuple and are excluded from enforcement.
-- ork_participant is MyISAM (its START TRANSACTION guard is a no-op), so these indexes are
-- the only real backstop for the number-allocation race. The lib layer is being updated
-- to catch the resulting duplicate-key errors and retry.
--
-- Idempotent: dedupe passes are no-ops once clean; all index/column adds use IF NOT EXISTS.

-- =====================================================================================
-- ork_point_score — enforce one score cell per (bracket, participant, round)  [#40]
-- (The CREATE TABLE already declares uq_cell; this repairs any table created without it.)
-- =====================================================================================
DELETE ps FROM ork_point_score ps
  JOIN (
    SELECT point_score_id FROM (
      SELECT point_score_id,
             ROW_NUMBER() OVER (PARTITION BY bracket_id, participant_id, round
                                ORDER BY point_score_id) AS rn
      FROM ork_point_score
    ) z WHERE z.rn > 1
  ) d ON d.point_score_id = ps.point_score_id;

ALTER TABLE ork_point_score
  ADD UNIQUE INDEX IF NOT EXISTS uq_cell (bracket_id, participant_id, round);

-- =====================================================================================
-- ork_participant — per-bracket entrant uniqueness  [#100]
--   UNIQUE(tournament_id, bracket_id, participant_number); NULL bracket_id (registration)
--   rows are excluded automatically.
-- =====================================================================================
-- SAFETY (#43): the dedup below hard-DELETEs duplicate participant rows. A duplicate may
-- already be referenced by ork_match (participant_1_id / participant_2_id), ork_seed, or
-- ork_point_score. Two protections:
--   1. KEEP the referenced duplicate (ORDER BY has-refs first), so its match/seed/score
--      linkage survives and only the unreferenced twin is removed.
--   2. PRE-FLIGHT orphan scan: if a single (tournament, bracket, number) partition has
--      TWO OR MORE referenced duplicates, collapsing it would necessarily orphan one set
--      of references. That cannot be auto-resolved, so ABORT with a clear message rather
--      than blind-deleting. Resolve those by hand (repoint refs / merge) then re-run.
-- The ork_participant and ork_participant_teams passes are coordinated: individual entrant
-- rows (participant_number > 0) are deduped here; team identity rows carry
-- participant_number = 0 and are uniqued via ork_participant_teams below, so the two passes
-- never delete the same identity.
SET @orphan := (
  SELECT COUNT(*) FROM (
    SELECT 1
    FROM ork_participant p
    JOIN (
      SELECT DISTINCT pid FROM (
        SELECT participant_1_id AS pid FROM ork_match
        UNION SELECT participant_2_id FROM ork_match
        UNION SELECT participant_id   FROM ork_seed
        UNION SELECT participant_id   FROM ork_point_score
      ) u
    ) r ON r.pid = p.participant_id
    WHERE p.participant_number > 0
    GROUP BY p.tournament_id, p.bracket_id, p.participant_number
    HAVING COUNT(*) > 1
  ) x
);
SET @sql := IF(@orphan > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''#43 ABORT: duplicate ork_participant rows share a (tournament, bracket, participant_number) partition and MORE THAN ONE is referenced by ork_match/ork_seed/ork_point_score. Deduping would orphan match/seed/score rows. Resolve manually (repoint or merge references) before applying.''',
  'SELECT ''participant dedup pre-flight OK — no orphaning'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Dedupe duplicate entrant rows (same number twice in one bracket) — race artifacts.
-- KEEP the referenced row (r.pid IS NOT NULL first), delete the unreferenced twin.
DELETE pm FROM ork_participant_mundane pm
  WHERE pm.participant_id IN (
    SELECT participant_id FROM (
      SELECT participant_id FROM (
        SELECT p.participant_id,
               ROW_NUMBER() OVER (PARTITION BY p.tournament_id, p.bracket_id, p.participant_number
                                  ORDER BY (r.pid IS NOT NULL) DESC, p.participant_id) AS rn
        FROM ork_participant p
        LEFT JOIN (
          SELECT DISTINCT pid FROM (
            SELECT participant_1_id AS pid FROM ork_match
            UNION SELECT participant_2_id FROM ork_match
            UNION SELECT participant_id   FROM ork_seed
            UNION SELECT participant_id   FROM ork_point_score
          ) u
        ) r ON r.pid = p.participant_id
        WHERE p.bracket_id IS NOT NULL AND p.participant_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

DELETE FROM ork_participant
  WHERE participant_id IN (
    SELECT participant_id FROM (
      SELECT participant_id FROM (
        SELECT p.participant_id,
               ROW_NUMBER() OVER (PARTITION BY p.tournament_id, p.bracket_id, p.participant_number
                                  ORDER BY (r.pid IS NOT NULL) DESC, p.participant_id) AS rn
        FROM ork_participant p
        LEFT JOIN (
          SELECT DISTINCT pid FROM (
            SELECT participant_1_id AS pid FROM ork_match
            UNION SELECT participant_2_id FROM ork_match
            UNION SELECT participant_id   FROM ork_seed
            UNION SELECT participant_id   FROM ork_point_score
          ) u
        ) r ON r.pid = p.participant_id
        WHERE p.bracket_id IS NOT NULL AND p.participant_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

-- NOTE: team brackets store one ork_participant identity row per team with
-- participant_number = 0 (teams are uniqued separately via ork_participant_teams.team_number),
-- so the entrant index must exclude number = 0 rows (as well as bracket_id-IS-NULL
-- registration rows) or multiple teams in one bracket would collide. A generated column
-- narrows enforcement to real individual entrants (bracket_id NOT NULL AND number > 0).
-- (The entrant_number_uk column + its unique index are added together with the
-- registration-scoped ones below, in a SINGLE ALTER, so this large table is rewritten once.)

-- ork_participant — registration-scoped number uniqueness  [#99]
-- Generated column is NULL except on registration rows (bracket_id IS NULL, number > 0),
-- so the UNIQUE index enforces one registration per number per tournament only.
-- Dedupe duplicate registration rows first. KEEP the referenced row (see #43 pre-flight above).
DELETE pm FROM ork_participant_mundane pm
  WHERE pm.participant_id IN (
    SELECT participant_id FROM (
      SELECT participant_id FROM (
        SELECT p.participant_id,
               ROW_NUMBER() OVER (PARTITION BY p.tournament_id, p.participant_number
                                  ORDER BY (r.pid IS NOT NULL) DESC, p.participant_id) AS rn
        FROM ork_participant p
        LEFT JOIN (
          SELECT DISTINCT pid FROM (
            SELECT participant_1_id AS pid FROM ork_match
            UNION SELECT participant_2_id FROM ork_match
            UNION SELECT participant_id   FROM ork_seed
            UNION SELECT participant_id   FROM ork_point_score
          ) u
        ) r ON r.pid = p.participant_id
        WHERE p.bracket_id IS NULL AND p.participant_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

DELETE FROM ork_participant
  WHERE participant_id IN (
    SELECT participant_id FROM (
      SELECT participant_id FROM (
        SELECT p.participant_id,
               ROW_NUMBER() OVER (PARTITION BY p.tournament_id, p.participant_number
                                  ORDER BY (r.pid IS NOT NULL) DESC, p.participant_id) AS rn
        FROM ork_participant p
        LEFT JOIN (
          SELECT DISTINCT pid FROM (
            SELECT participant_1_id AS pid FROM ork_match
            UNION SELECT participant_2_id FROM ork_match
            UNION SELECT participant_id   FROM ork_seed
            UNION SELECT participant_id   FROM ork_point_score
          ) u
        ) r ON r.pid = p.participant_id
        WHERE p.bracket_id IS NULL AND p.participant_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

-- Both generated columns and both unique indexes are added in a SINGLE ALTER so the large
-- ork_participant table is rewritten only ONCE: entrant-scoped [#100] + registration-scoped
-- [#99]. MariaDB applies the ADD COLUMNs before the ADD INDEXes within one statement, so the
-- indexes can reference the generated columns created in the same ALTER. All adds are guarded
-- with IF NOT EXISTS, so a re-run (columns/indexes already present) is a no-op.
ALTER TABLE ork_participant
  ADD COLUMN IF NOT EXISTS entrant_number_uk INT
    AS (IF(bracket_id IS NOT NULL AND participant_number > 0, participant_number, NULL)) PERSISTENT,
  ADD COLUMN IF NOT EXISTS reg_number_uk INT
    AS (IF(bracket_id IS NULL AND participant_number > 0, participant_number, NULL)) PERSISTENT,
  ADD UNIQUE INDEX IF NOT EXISTS uq_participant_bracket_number
    (tournament_id, bracket_id, entrant_number_uk),
  ADD UNIQUE INDEX IF NOT EXISTS uq_participant_reg_number (tournament_id, reg_number_uk);

-- =====================================================================================
-- ork_participant_teams — per-bracket entrant + registration-scoped uniqueness  [#99/#100]
-- =====================================================================================
-- SAFETY (#43): a team row carries its identity via its ork_participant_team_members
-- roster. KEEP the team that actually has members so team identity is not lost, and
-- PRE-FLIGHT abort if a single (tournament, bracket, team_number) partition has TWO OR
-- MORE teams that each carry members (ambiguous merge — resolve by hand, then re-run).
SET @team_orphan := (
  SELECT COUNT(*) FROM (
    SELECT 1
    FROM ork_participant_teams t
    JOIN (SELECT DISTINCT team_id FROM ork_participant_team_members) r ON r.team_id = t.team_id
    WHERE t.team_number > 0
    GROUP BY t.tournament_id, t.bracket_id, t.team_number
    HAVING COUNT(*) > 1
  ) x
);
SET @sql := IF(@team_orphan > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''#43 ABORT: duplicate ork_participant_teams rows share a (tournament, bracket, team_number) partition and MORE THAN ONE carries roster members. Deduping would lose team identity. Resolve manually before applying.''',
  'SELECT ''team dedup pre-flight OK — no lost identity'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Dedupe duplicate team entrant rows (same team_number twice in one bracket).
-- KEEP the team that carries roster members (r.team_id IS NOT NULL first).
DELETE ptm FROM ork_participant_team_members ptm
  WHERE ptm.team_id IN (
    SELECT team_id FROM (
      SELECT team_id FROM (
        SELECT t.team_id,
               ROW_NUMBER() OVER (PARTITION BY t.tournament_id, t.bracket_id, t.team_number
                                  ORDER BY (r.team_id IS NOT NULL) DESC, t.team_id) AS rn
        FROM ork_participant_teams t
        LEFT JOIN (SELECT DISTINCT team_id FROM ork_participant_team_members) r ON r.team_id = t.team_id
        WHERE t.bracket_id IS NOT NULL AND t.team_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

DELETE FROM ork_participant_teams
  WHERE team_id IN (
    SELECT team_id FROM (
      SELECT team_id FROM (
        SELECT t.team_id,
               ROW_NUMBER() OVER (PARTITION BY t.tournament_id, t.bracket_id, t.team_number
                                  ORDER BY (r.team_id IS NOT NULL) DESC, t.team_id) AS rn
        FROM ork_participant_teams t
        LEFT JOIN (SELECT DISTINCT team_id FROM ork_participant_team_members) r ON r.team_id = t.team_id
        WHERE t.bracket_id IS NOT NULL AND t.team_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

-- (The per-bracket unique index is added together with the registration-scoped column +
-- index below, in a SINGLE ALTER, so ork_participant_teams is rewritten once.)

-- Registration-scoped: one registration team per team_number per tournament.
-- KEEP the team that carries roster members (see #43 pre-flight above).
DELETE ptm FROM ork_participant_team_members ptm
  WHERE ptm.team_id IN (
    SELECT team_id FROM (
      SELECT team_id FROM (
        SELECT t.team_id,
               ROW_NUMBER() OVER (PARTITION BY t.tournament_id, t.team_number
                                  ORDER BY (r.team_id IS NOT NULL) DESC, t.team_id) AS rn
        FROM ork_participant_teams t
        LEFT JOIN (SELECT DISTINCT team_id FROM ork_participant_team_members) r ON r.team_id = t.team_id
        WHERE t.bracket_id IS NULL AND t.team_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

DELETE FROM ork_participant_teams
  WHERE team_id IN (
    SELECT team_id FROM (
      SELECT team_id FROM (
        SELECT t.team_id,
               ROW_NUMBER() OVER (PARTITION BY t.tournament_id, t.team_number
                                  ORDER BY (r.team_id IS NOT NULL) DESC, t.team_id) AS rn
        FROM ork_participant_teams t
        LEFT JOIN (SELECT DISTINCT team_id FROM ork_participant_team_members) r ON r.team_id = t.team_id
        WHERE t.bracket_id IS NULL AND t.team_number > 0
      ) z WHERE z.rn > 1
    ) d
  );

-- Per-bracket index [#100] + registration-scoped column and index [#99] are added in a
-- SINGLE ALTER so ork_participant_teams is rewritten only ONCE. IF NOT EXISTS on every add
-- keeps a re-run a no-op.
ALTER TABLE ork_participant_teams
  ADD COLUMN IF NOT EXISTS reg_team_number_uk INT
    AS (IF(bracket_id IS NULL AND team_number > 0, team_number, NULL)) PERSISTENT,
  ADD UNIQUE INDEX IF NOT EXISTS uq_pteams_bracket_number
    (tournament_id, bracket_id, team_number),
  ADD UNIQUE INDEX IF NOT EXISTS uq_pteams_reg_number (tournament_id, reg_team_number_uk);
