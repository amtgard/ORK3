-- Court Planner: consolidated schema.
--
-- Run THIS instead of the sixteen 2026-03-16 .. 2026-09-09 Court Planner
-- migrations when deploying to a database that has never seen Court Planner --
-- production. Those files are kept for boxes that are part-way through them;
-- everything here is IF NOT EXISTS, so running both is harmless, just slower.
--
-- The originals created ork_court and ork_court_award and then altered them a
-- dozen times over six months. On a fresh database that is a table build
-- followed by ten rebuilds of a table that was empty the whole time. This
-- creates them in final shape instead, and folds the four separate ALTERs on
-- ork_recommendations into one -- that table is MyISAM, so each statement is a
-- full table lock and copy.
--
-- Generated from a database that applied the incremental path in order, so the
-- column order matches what those migrations produced. Collation and
-- AUTO_INCREMENT are deliberately omitted: they are instance-specific, and the
-- uca1400 collation names only exist on MariaDB 11.4+ (production is 11.2).
--
-- Rehearsed 2026-10-09 against a restore of the 2026-10-08 production backup
-- (403k ork_awards rows / 201 MB, MyISAM ork_recommendations and
-- ork_kingdomaward), on MariaDB 12.3.2:
--
--   all 8 statements          0.66s total
--   ork_awards  +2 columns    0.09s   (ALGORITHM=INSTANT, metadata only)
--   ork_recommendations       0.15s   (8 columns + index, one lock)
--   ork_kingdomaward          0.08s
--
-- The resulting schema was identical to the incremental path's: 116 columns and
-- 67 index columns across all nine affected tables.
--
-- The 2026-08-18 ork_session migration is deliberately not included here: its
-- user_agent and ip columns are already in production.


CREATE TABLE IF NOT EXISTS `ork_court` (
  `court_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(10) unsigned NOT NULL DEFAULT 0,
  `park_id` int(10) unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `court_date` date DEFAULT NULL,
  `event_calendardetail_id` int(10) unsigned DEFAULT NULL,
  `status` enum('draft','published','complete') NOT NULL DEFAULT 'draft',
  `mode` enum('run','plan') NOT NULL DEFAULT 'run',
  `created_by` int(10) unsigned NOT NULL DEFAULT 0,
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `finalized_at` datetime DEFAULT NULL,
  `finalized_by` int(11) DEFAULT NULL,
  `recorder_mundane_id` int(11) DEFAULT NULL,
  `giver_snapshot` text DEFAULT NULL,
  `last_printed_at` datetime DEFAULT NULL,
  `last_printed_award_count` int(11) DEFAULT NULL,
  PRIMARY KEY (`court_id`),
  KEY `idx_kingdom` (`kingdom_id`),
  KEY `idx_park` (`park_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ork_court_award` (
  `court_award_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `court_id` int(10) unsigned NOT NULL,
  `mundane_id` int(10) unsigned NOT NULL DEFAULT 0,
  `given_by_mundane_id` int(11) DEFAULT NULL,
  `kingdomaward_id` int(10) unsigned NOT NULL DEFAULT 0,
  `rank` int(11) NOT NULL DEFAULT 0,
  `recommendations_id` int(10) unsigned DEFAULT NULL,
  `award_id` int(11) DEFAULT NULL,
  `last_finalize_error` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `pass_to_local` tinyint(4) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `public_comment` text DEFAULT NULL,
  `public_comment_cleared` tinyint(4) NOT NULL DEFAULT 0,
  `status` enum('planned','announced','staged','given','cancelled') NOT NULL DEFAULT 'planned',
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `row_version` int(11) NOT NULL DEFAULT 0,
  `scroll_maker_id` int(10) unsigned DEFAULT NULL,
  `scroll_status` tinyint(4) NOT NULL DEFAULT 0,
  `regalia_maker_id` int(10) unsigned DEFAULT NULL,
  `regalia_status` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`court_award_id`),
  UNIQUE KEY `uniq_court_rec` (`court_id`,`recommendations_id`),
  KEY `idx_court` (`court_id`),
  KEY `idx_mundane` (`mundane_id`),
  KEY `idx_rec` (`recommendations_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ork_court_award_artisan` (
  `court_award_artisan_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `court_award_id` int(10) unsigned NOT NULL,
  `mundane_id` int(10) unsigned NOT NULL DEFAULT 0,
  `contribution` varchar(255) NOT NULL DEFAULT '',
  `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`court_award_artisan_id`),
  KEY `idx_court_award` (`court_award_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ork_recommendation_support` (
  `support_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `recommendations_id` int(10) unsigned NOT NULL,
  `mundane_id` int(10) unsigned NOT NULL,
  `date_added` date NOT NULL,
  PRIMARY KEY (`support_id`),
  UNIQUE KEY `idx_unique_support` (`recommendations_id`,`mundane_id`),
  KEY `idx_rec` (`recommendations_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `ork_notification` (
  `notification_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mundane_id` int(10) unsigned NOT NULL,
  `type` varchar(40) NOT NULL,
  `message` varchar(400) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_active` (`mundane_id`,`dismissed_at`,`read_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


-- ---------------------------------------------------------------------------
-- Pre-existing tables. These are the only statements here that touch data that
-- already exists in production.
-- ---------------------------------------------------------------------------

-- InnoDB, ~391k rows / 163 MB. Both columns are appended at the end, which
-- MariaDB applies as an instant metadata change. ALGORITHM=INSTANT is stated
-- so that if it ever cannot, the statement fails instead of silently
-- rebuilding the table under live traffic.
ALTER TABLE ork_awards
    ADD COLUMN IF NOT EXISTS court_award_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS source_reason  VARCHAR(400) NULL DEFAULT NULL,
    ALGORITHM=INSTANT;

-- MyISAM: no online DDL, so this takes a full table lock and copies the table.
-- All eight columns and the index are done in ONE statement: the incremental
-- set spread them over four files, which meant four locks and four copies.
ALTER TABLE ork_recommendations
    ADD COLUMN IF NOT EXISTS snoozed_by_id      INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS snoozed_monarch_id INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS snoozed_regent_id  INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS snoozed_kingdom_id INT NULL DEFAULT NULL AFTER snoozed_regent_id,
    ADD COLUMN IF NOT EXISTS snoozed_park_id    INT NULL DEFAULT NULL AFTER snoozed_kingdom_id,
    ADD COLUMN IF NOT EXISTS passed_to_local    TINYINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS passed_to_local_by INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS passed_to_local_at TIMESTAMP NULL DEFAULT NULL,
    ADD INDEX IF NOT EXISTS idx_recs_mundane_ka_rank_deleted
        (mundane_id, kingdomaward_id, `rank`, deleted_by);

-- MyISAM, 4.9k rows / 1 MB. Sub-second, but this table is read on nearly every
-- award page, so the lock is briefly site-wide.
ALTER TABLE ork_kingdomaward
    ADD COLUMN IF NOT EXISTS is_ladder TINYINT(1) NOT NULL DEFAULT 0 AFTER award_id;

-- ork_session's user_agent and ip (2026-08-18) are deliberately absent: they
-- shipped with multi-device sessions and are already in production.
