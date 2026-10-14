-- ORK3 — bonus credits: class credits that are NOT attendance
-- =============================================================================
-- A credit in the ORK has always been an attendance row, and ~150 queries
-- across reports, voting eligibility, park and kingdom tallies, the recap and
-- the explorers read that table. Leadership wants to hand out credits for
-- things that are not attendance -- "a credit for filling in the survey",
-- "a credit for being purple" -- that count toward a player's class levels
-- but never as attendance anywhere: not for voting, not in a park's or
-- kingdom's numbers.
--
-- Excluding such rows from every attendance query (and every future one) is
-- not a guarantee anyone can keep, so they live here instead. The ONLY reader
-- of ork_bonus_credit is Player::GetPlayerClasses(), which already adds the
-- class-reconciliation offset to attendance credits; the bonus total joins in
-- the same spot. Nothing that counts attendance ever sees these tables.
--
-- TWO TABLES. A credit is granted FOR a reason (ork_bonus_credit_reason): the
-- named "thing" an officer picks in the Add Bonus Credits modal. Reasons are
-- defined at KINGDOM level (a principality counts as a kingdom); parks do not
-- create them, they hand them out. scope_type leaves room for an Amtgard-wide
-- level later ('ork', scope_id 0) that nothing creates yet.
--
-- Authority is kingdom-gated at both ends: creating a reason needs EDIT on
-- its kingdom; granting one needs EDIT on the player's park (which kingdom
-- officers hold for every park in their kingdom) AND the player's home must
-- lie inside the reason's kingdom or one of its principalities. So a kingdom
-- can never credit another kingdom's players, and a park can only credit its
-- own. A reason is granted once per player unless marked repeatable. An
-- automated source (a survey) is a reason too, linked by source_type/id, so
-- "once per survey" is the same rule.
--
-- Revocation and archiving are stamps, not deletes: a credit's history
-- survives, and an archived reason stops being offered without touching the
-- credits already granted for it.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS; safe to re-run.
-- After applying: docker restart ork3-php8-app (APCu schema cache).

CREATE TABLE IF NOT EXISTS ork_bonus_credit_reason (
  reason_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type      ENUM('ork','kingdom') NOT NULL DEFAULT 'kingdom', -- who defines it; 'ork' reserved
  scope_id        INT NOT NULL,                        -- kingdom_id, or 0 for 'ork'
  name            VARCHAR(100) NOT NULL,
  description     VARCHAR(500) NOT NULL DEFAULT '',
  default_credits DECIMAL(4,2) NOT NULL DEFAULT 1.00,  -- what the modal proposes
  repeatable      TINYINT(1) NOT NULL DEFAULT 0,       -- 0 = a player gets it once
  source_type     VARCHAR(20) NOT NULL DEFAULT 'manual', -- 'manual', 'survey', ...
  source_id       INT NOT NULL DEFAULT 0,              -- e.g. the survey_id; 0 for manual
  created_by      INT NOT NULL,
  created_at      DATETIME NOT NULL,
  archived_by     INT NULL,
  archived_at     DATETIME NULL,
  PRIMARY KEY (reason_id),
  UNIQUE KEY uq_scope_name (scope_type, scope_id, name),
  KEY idx_scope (scope_type, scope_id, archived_at),
  KEY idx_source (source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ork_bonus_credit (
  bonus_credit_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mundane_id      INT NOT NULL,
  class_id        INT NOT NULL,
  credits         DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  credit_date     DATE NOT NULL,                       -- the day the credit is for (bookkeeping, not identity)
  reason_id       INT UNSIGNED NOT NULL,
  note            VARCHAR(200) NOT NULL DEFAULT '',
  granted_by      INT NOT NULL,
  granted_at      DATETIME NOT NULL,
  revoked_by      INT NULL,
  revoked_at      DATETIME NULL,
  revoke_note     VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (bonus_credit_id),
  KEY idx_player (mundane_id, revoked_at, class_id),
  KEY idx_reason (reason_id, revoked_at, mundane_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
