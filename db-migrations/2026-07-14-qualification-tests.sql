-- ============================================================================
-- Qualification Tests (Reeve's Test + Corpora Test) — complete schema.
--
-- This ONE file replaces the 16 incremental migrations the feature was developed
-- against (2026-03-17 .. 2026-07-13). Those were never applied to production —
-- the feature has not shipped — so there is no upgrade path to preserve and no
-- data to backfill. A fresh install gets the final shape directly.
--
-- Consolidating also fixes a trap in the old set: applying the files in filename
-- order was wrong. `2026-03-17-add-qual-config-valid-until.sql` (which ALTERs
-- ork_qual_config) sorts BEFORE `2026-03-17-add-qualification-tests.sql` (which
-- CREATEs it), so a by-name run against a clean database failed on the first file.
--
-- Idempotent: safe to re-run. CREATE TABLE IF NOT EXISTS, and the config seed
-- skips kingdoms that already have the row.
--
-- Requires: ork_kingdom, ork_configuration (both pre-existing in production).
-- ============================================================================

/*M!999999\- enable the sandbox mode */ 
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_config` (
  `qual_config_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(10) unsigned NOT NULL,
  `test_type` enum('reeve','corpora') NOT NULL,
  `question_count` int(11) NOT NULL DEFAULT 10,
  `pass_percent` int(11) NOT NULL DEFAULT 70,
  `valid_days` int(11) NOT NULL DEFAULT 365,
  `valid_until` date DEFAULT NULL,
  `max_retakes` int(11) NOT NULL DEFAULT 0,
  `share_questions` tinyint(1) NOT NULL DEFAULT 0,
  `instructions` text DEFAULT NULL,
  `rules_version` varchar(100) DEFAULT NULL,
  `show_correct_on_incorrect` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`qual_config_id`),
  UNIQUE KEY `uq_kingdom_type` (`kingdom_id`,`test_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_question` (
  `qual_question_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(10) unsigned NOT NULL,
  `test_type` enum('reeve','corpora') NOT NULL,
  `question_text` text NOT NULL,
  `answer_mode` enum('single','multi') NOT NULL DEFAULT 'single',
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned NOT NULL DEFAULT 0,
  `source_question_id` int(10) unsigned DEFAULT NULL COMMENT 'qual_question_id this was imported from (Global Question Library); NULL if written locally',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`qual_question_id`),
  KEY `idx_kingdom_type_status` (`kingdom_id`,`test_type`,`status`),
  KEY `idx_source_question` (`source_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_answer` (
  `qual_answer_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `qual_question_id` int(10) unsigned NOT NULL,
  `answer_text` text NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`qual_answer_id`),
  KEY `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_result` (
  `qual_result_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` int(10) unsigned NOT NULL,
  `kingdom_id` int(10) unsigned NOT NULL,
  `test_type` enum('reeve','corpora') NOT NULL,
  `score_percent` int(11) NOT NULL DEFAULT 0,
  `rules_version` varchar(100) NOT NULL DEFAULT '',
  `qual_question_set_id` int(10) unsigned DEFAULT NULL,
  `set_name` varchar(100) NOT NULL DEFAULT '',
  `passed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`qual_result_id`),
  UNIQUE KEY `uq_player_kingdom_type` (`player_id`,`kingdom_id`,`test_type`),
  KEY `idx_player` (`player_id`),
  KEY `idx_kingdom_type_expires` (`kingdom_id`,`test_type`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_retake` (
  `qual_retake_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` int(10) unsigned NOT NULL,
  `kingdom_id` int(10) unsigned NOT NULL,
  `test_type` enum('reeve','corpora') NOT NULL,
  `retake_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`qual_retake_id`),
  UNIQUE KEY `uq_player_kingdom_type` (`player_id`,`kingdom_id`,`test_type`),
  KEY `idx_kingdom_type` (`kingdom_id`,`test_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_manager` (
  `qual_manager_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(10) unsigned NOT NULL,
  `mundane_id` int(10) unsigned NOT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`qual_manager_id`),
  UNIQUE KEY `uq_kingdom_mundane` (`kingdom_id`,`mundane_id`),
  KEY `idx_kingdom` (`kingdom_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_report` (
  `qual_report_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `qual_question_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL DEFAULT 0,
  `reason` enum('wording','correct','outdated','other') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`qual_report_id`),
  KEY `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_question_stat` (
  `qual_question_id` int(11) NOT NULL,
  `times_answered` int(11) NOT NULL DEFAULT 0,
  `times_correct` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_attempt` (
  `qual_attempt_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` int(10) unsigned NOT NULL,
  `kingdom_id` int(10) unsigned NOT NULL,
  `test_type` enum('reeve','corpora') NOT NULL,
  `score_percent` int(11) NOT NULL,
  `pass_percent` int(11) NOT NULL,
  `rules_version` varchar(100) NOT NULL DEFAULT '',
  `qual_question_set_id` int(10) unsigned DEFAULT NULL,
  `set_name` varchar(100) NOT NULL DEFAULT '',
  `passed` tinyint(1) NOT NULL,
  `taken_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`qual_attempt_id`),
  KEY `idx_player_type_taken` (`player_id`,`test_type`,`taken_at`),
  KEY `idx_kingdom_type_taken` (`kingdom_id`,`test_type`,`taken_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_attempt_answer` (
  `qual_attempt_answer_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `qual_attempt_id` int(10) unsigned NOT NULL,
  `qual_question_id` int(10) unsigned DEFAULT NULL,
  `question_text` text NOT NULL,
  `answer_mode` enum('single','multi') NOT NULL,
  `question_order` int(11) NOT NULL DEFAULT 0,
  `qual_answer_id` int(10) unsigned DEFAULT NULL,
  `answer_text` text NOT NULL,
  `is_correct` tinyint(1) NOT NULL,
  `was_selected` tinyint(1) NOT NULL,
  PRIMARY KEY (`qual_attempt_answer_id`),
  KEY `idx_attempt` (`qual_attempt_id`),
  KEY `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_question_set` (
  `qual_question_set_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(10) unsigned NOT NULL,
  `test_type` enum('reeve','corpora') NOT NULL,
  `name` varchar(100) NOT NULL,
  `rules_version` varchar(100) NOT NULL DEFAULT '',
  `status` enum('draft','published','retired') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  `published_slot` tinyint(3) unsigned GENERATED ALWAYS AS (if(`status` = 'published',1,NULL)) STORED,
  `draft_slot` tinyint(3) unsigned GENERATED ALWAYS AS (if(`status` = 'draft',1,NULL)) STORED,
  PRIMARY KEY (`qual_question_set_id`),
  UNIQUE KEY `uq_one_published` (`kingdom_id`,`test_type`,`published_slot`),
  UNIQUE KEY `uq_one_draft` (`kingdom_id`,`test_type`,`draft_slot`),
  KEY `idx_kingdom_type_status` (`kingdom_id`,`test_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ork_qual_set_question` (
  `qual_question_set_id` int(10) unsigned NOT NULL,
  `qual_question_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`qual_question_set_id`,`qual_question_id`),
  KEY `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- ----------------------------------------------------------------------------
-- Kingdom on/off switches. Both default to '0': the tests are opt-in per kingdom
-- and must not appear for anyone until a kingdom's monarchy turns them on.
-- Skips kingdoms that already have the row, so this is safe to re-run.
-- (New kingdoms get these rows from Kingdom::add_config() in application code.)
-- ----------------------------------------------------------------------------
INSERT INTO `ork_configuration` (`type`, `var_type`, `id`, `key`, `value`, `user_setting`, `allowed_values`, `modified`)
SELECT 'Kingdom', 'fixed', k.kingdom_id, 'QualTestReeveEnabled', '"0"', 1, 'null', NOW()
  FROM `ork_kingdom` k
 WHERE k.active = 'Active'
   AND NOT EXISTS (
       SELECT 1 FROM `ork_configuration` c
        WHERE c.type = 'Kingdom' AND c.id = k.kingdom_id AND c.`key` = 'QualTestReeveEnabled'
   );

INSERT INTO `ork_configuration` (`type`, `var_type`, `id`, `key`, `value`, `user_setting`, `allowed_values`, `modified`)
SELECT 'Kingdom', 'fixed', k.kingdom_id, 'QualTestCorporaEnabled', '"0"', 1, 'null', NOW()
  FROM `ork_kingdom` k
 WHERE k.active = 'Active'
   AND NOT EXISTS (
       SELECT 1 FROM `ork_configuration` c
        WHERE c.type = 'Kingdom' AND c.id = k.kingdom_id AND c.`key` = 'QualTestCorporaEnabled'
   );
