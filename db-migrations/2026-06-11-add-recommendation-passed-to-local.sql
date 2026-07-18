-- Pass-to-local delegation: a kingdom/principality officer delegates a recommendation
-- to the recipient's home park to award. Intent/communication signal (not enforced).
ALTER TABLE ork_recommendations
  ADD COLUMN passed_to_local    TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN passed_to_local_by INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN passed_to_local_at TIMESTAMP NULL DEFAULT NULL;
