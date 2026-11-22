-- ORK3 — event schedule board: request tokens for schedule batches and copies
-- =============================================================================
-- The schedule board sends its changes as one batch (EventAjax/schedule_batch)
-- and can copy a past occurrence's schedule in (EventAjax/schedule_copy_into).
-- When the reply to either is lost, the board sends the same request again.
-- Each request may carry a token; the first request with a token claims it here,
-- in the same transaction as its writes, and stores its results. A repeat of the
-- token gets those stored results back and changes nothing.
--
-- ork_event_schedule_batch: one row per (occurrence, token).
--   kind    'batch' or 'copy'
--   results JSON: the batch's Results list, or what a copy needs to rebuild its reply
--   created rows older than one day are deleted when a later token is claimed
-- Cascades with the occurrence. The token compares byte for byte (ascii_bin), so
-- two tokens that differ only in letter case are two tokens.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS; safe to re-run.

CREATE TABLE IF NOT EXISTS ork_event_schedule_batch (
  event_calendardetail_id INT NOT NULL,
  token                   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind                    VARCHAR(8) NOT NULL,
  results                 MEDIUMTEXT NOT NULL,
  created                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_calendardetail_id, token),
  KEY idx_schedule_batch_created (created),
  CONSTRAINT fk_schedule_batch_detail FOREIGN KEY (event_calendardetail_id)
    REFERENCES ork_event_calendardetail (event_calendardetail_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
