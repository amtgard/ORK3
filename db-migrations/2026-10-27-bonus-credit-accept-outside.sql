-- ORK3 — bonus credits: a kingdom may accept credits from other kingdoms
-- =============================================================================
-- A bonus credit category belongs to the kingdom that defined it, and until now
-- could only ever be granted to that kingdom's own players (and its
-- principalities'). Leadership wants a kingdom to be able to say "if another
-- kingdom is offering a credit for doing X, our players may take it".
--
-- That is a yes/no per kingdom, not a picker: either the kingdom's players may
-- receive other kingdoms' bonus credits, or they may not. One row per kingdom
-- here; no row means no. A parent kingdom's yes covers its principalities, the
-- same way an Amtgard-wide credit's adoption does.
--
-- Who may grant across the line is decided in BonusCredit::grant(), and
-- follows attendance: whoever may sign players in on a park or kingdom page
-- inside the kingdom that DEFINED the category may give it from that page, to
-- a player whose kingdom (or a kingdom above it) has said yes here. The
-- receiving kingdom's own officers cannot hand out another kingdom's credit.
-- Amtgard-wide credits are untouched: adoption governs those.
-- (Since 2026-10-28 the default is yes: no row means yes. PR #548.)
--
-- Flipping the setting needs kingdom CREATE, or an ORK admin on the kingdom's
-- behalf; both are audited (BonusCredit::setAcceptsOutside).
--
-- Idempotent: CREATE TABLE IF NOT EXISTS; safe to re-run.
-- After applying: docker restart ork3-php8-app (APCu schema cache).

CREATE TABLE IF NOT EXISTS ork_bonus_credit_kingdom_setting (
  kingdom_id      INT NOT NULL,
  accepts_outside TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = other kingdoms' credits may reach this kingdom's players
  updated_by      INT NOT NULL,
  updated_at      DATETIME NOT NULL,
  PRIMARY KEY (kingdom_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
