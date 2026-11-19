-- Convert ork_participant.status from free-text VARCHAR to a constrained ENUM.
--
-- WHY: the column is written from a fixed, small vocabulary and read against that same
-- vocabulary; an ENUM makes the contract explicit and rejects typos at the DB layer.
--
-- VALUE SET (confirmed read-only against the code that WRITES ork_participant.status):
--   ''            -- legacy empty-string sentinel; treated as "active" by every query
--                    (e.g. `status IN ('active','')` in class.Tournament.php standings/seeding).
--   'active'      -- WithdrawParticipant() / UpdateParticipantStatus() reactivate.
--   'withdrawn'   -- WithdrawParticipant() and UpdateParticipantStatus().
--   'disqualified'-- UpdateParticipantStatus() (class.Tournament.php ~4406; allowed set is
--                    ['active','withdrawn','disqualified'], and the Tournametnew UI offers a
--                    "Disqualified" action). Omitting it would break the disqualify feature,
--                    so it is INCLUDED here even though the change note only named active/
--                    withdrawn/''. Local dev data confirms live 'disqualified' rows exist.
-- Default is 'active' (matches the prior VARCHAR default and the '' sentinel semantics).
--
-- PRE-FLIGHT: abort (do NOT silently coerce) if any existing status value falls outside the
-- ENUM set — those rows must be reconciled by hand before the column can be narrowed.
--
-- IDEMPOTENT: the MODIFY is guarded on INFORMATION_SCHEMA — once the column is already an
-- ENUM (DATA_TYPE = 'enum'), a re-run is a no-op. This migration has NO tracking table and is
-- re-applied after a prod reload, so it must stay safely re-runnable.
--
-- ork_participant was converted to InnoDB by an earlier migration, so this ALTER runs InnoDB.

-- ---------------------------------------------------------------------------------------------
-- PRE-FLIGHT: SIGNAL-abort on any out-of-set value rather than losing/coercing it.
-- (After conversion the ENUM physically cannot hold an out-of-set value, so this is a no-op on
--  every re-run.)
-- ---------------------------------------------------------------------------------------------
SET @bad := (
  SELECT COUNT(*) FROM ork_participant
  WHERE status NOT IN ('', 'active', 'withdrawn', 'disqualified')
);
SET @sql := IF(@bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''ABORT: ork_participant.status contains value(s) outside the allowed set (empty, active, withdrawn, disqualified). Reconcile those rows by hand before narrowing the column to an ENUM.''',
  'SELECT ''participant status pre-flight OK — all values in ENUM set'' AS note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------------------------
-- GUARDED MODIFY: only convert while the column is still VARCHAR (DATA_TYPE <> 'enum').
-- ---------------------------------------------------------------------------------------------
SET @is_enum := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ork_participant'
    AND COLUMN_NAME  = 'status'
    AND DATA_TYPE    = 'enum'
);
SET @sql := IF(@is_enum > 0,
  'SELECT ''ork_participant.status already ENUM — no-op'' AS note',
  'ALTER TABLE ork_participant MODIFY COLUMN status ENUM('''',''active'',''withdrawn'',''disqualified'') NOT NULL DEFAULT ''active'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
