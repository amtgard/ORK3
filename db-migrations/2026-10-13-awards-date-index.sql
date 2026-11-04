-- Award Explorer prerequisite: read ork_awards in date order without a filesort.
--
-- ork_awards has 16 indexes and none on `date`. The closest, idx_awards_mundane_ka_rank_date,
-- carries date as its FOURTH column, so it cannot serve a global chronological sort. Measured
-- on production 2026-10-13 (396,913 rows / 163 MB):
--
--   SELECT ... FROM ork_awards ORDER BY date DESC LIMIT 50
--     before: type=index, key=idx_awards_mundane_ka_rank_date, rows=388,102, Using filesort -- 377 ms
--     after : type=index, key=idx_awards_date,                 rows=50,     no filesort     --  65 ms
--
-- That is the floor: no filters, no joins. Every Award Explorer query sorts by date, so
-- without this each run reads the whole table.
--
-- InnoDB, so this is an ONLINE index build: ALGORITHM=INPLACE, LOCK=NONE takes no table lock
-- and concurrent reads and writes continue. Both are stated explicitly rather than left to
-- the server to choose, so the statement FAILS instead of silently falling back to a locking
-- COPY if a future schema change makes the online path unavailable.
--
-- Timed at 341 ms on a production-sized restore (390,380 rows / 202 MB). No quiet window needed.
-- Adds roughly 5 MB of index.
--
-- NOT re-runnable as written: MariaDB has no ADD INDEX IF NOT EXISTS for a named index in every
-- version in use here, so check first:
--   SELECT COUNT(*) FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ork_awards' AND INDEX_NAME = 'idx_awards_date';

ALTER TABLE ork_awards
    ADD INDEX idx_awards_date (date),
    ALGORITHM=INPLACE, LOCK=NONE;
