-- Scrollery bulk selection and event picker: composite (scope, date) indexes.
--
-- WHAT IS SLOW. Both of Scrollery's own queries filter ork_awards on ONE scope
-- column and then on a date range:
--
--   bulk page/count   where awards.at_kingdom_id = K and awards.date between ...
--                     where awards.at_park_id    = P and awards.date between ...
--   events picker     where awards.at_event_id > 0 and awards.at_kingdom_id = K
--                     (grouped by at_event_id, ordered by max(awards.date))
--
-- ork_awards carries single-column indexes on at_kingdom_id and at_park_id and
-- nothing composite with `date`, so MariaDB reads every row for the scope and
-- discards most of them. Measured on the dev mirror, kingdom 4 for calendar
-- 2024: 21,530 rows examined for a range that contains a small fraction of them.
--
-- WHAT THIS DOES NOT FIX, stated plainly so nobody re-measures expecting it:
-- the `Using filesort` on the bulk page query does NOT go away and cannot. That
-- query sorts on coalesce(m.persona,''), coalesce(a.name, ka.name, ''),
-- awards.rank, awards.awards_id — two of the four terms come from JOINED tables,
-- so no index on ork_awards can satisfy the ORDER BY. Likewise the events
-- picker's `Using temporary` is the GROUP BY plus an ORDER BY on an aggregate.
-- What these indexes buy is a much smaller row set arriving at that sort, which
-- is the part that scales with the size of a kingdom's history.
--
-- Column order is (scope, date), not (date, scope): the scope is always an
-- equality and the date is always a range, and a range term ends an index's
-- usefulness for everything after it.
--
-- Idempotent: guarded by an information_schema probe, safe to re-run. ork_awards
-- is ~391k rows; both ALTERs are online in InnoDB and take seconds.

-- (at_kingdom_id, date)
SET @have := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'ork_awards'
     AND INDEX_NAME   = 'idx_awards_at_kingdom_date'
);
SET @ddl := IF(@have = 0,
  'ALTER TABLE `ork_awards` ADD INDEX `idx_awards_at_kingdom_date` (`at_kingdom_id`, `date`)',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- (at_park_id, date)
SET @have := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'ork_awards'
     AND INDEX_NAME   = 'idx_awards_at_park_date'
);
SET @ddl := IF(@have = 0,
  'ALTER TABLE `ork_awards` ADD INDEX `idx_awards_at_park_date` (`at_park_id`, `date`)',
  'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
