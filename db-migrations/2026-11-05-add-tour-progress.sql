-- ORK3 — OrkTour progress: which guided tours a person has finished or turned down
-- =============================================================================
-- One row per person per tour. The site-wide "Take the ORK Tour" offer, and any
-- tour that starts on its own, read this so the offer follows the person across
-- devices instead of living in one browser's localStorage.
--
-- status: 'completed' = reached the last step; 'dismissed' = closed it early or
-- said "No thanks". version lets a tour be re-offered after a rewrite: the
-- frontend compares it with the tour's current version.
--
-- Written by Player::save_tour_progress() (an upsert on the unique key), read by
-- Player::get_tour_progress(). MergePlayer() carries rows to the surviving player.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS; safe to re-run.

CREATE TABLE IF NOT EXISTS ork_tour_progress (
  tour_progress_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mundane_id       INT NOT NULL,
  tour_id          VARCHAR(64) NOT NULL,
  version          INT NOT NULL DEFAULT 1,
  status           ENUM('completed','dismissed') NOT NULL,
  last_step        INT NOT NULL DEFAULT 0,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tour_progress_id),
  UNIQUE KEY uq_tour_progress_mundane_tour (mundane_id, tour_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
