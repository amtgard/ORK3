-- Index ork_qual_result by (kingdom_id, test_type, expires_at) to speed up the
-- kingdom test-result reports and the qualified/expiry-bounded lookups in
-- class.QualTest.php (getTestResults / getTestReportStats / getPlayerResults).
ALTER TABLE `ork_qual_result` ADD INDEX `idx_kingdom_type_expires` (`kingdom_id`, `test_type`, `expires_at`);
