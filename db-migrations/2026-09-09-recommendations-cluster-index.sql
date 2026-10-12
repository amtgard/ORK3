-- Index the (recipient, kingdomaward, rank) cluster the Recommendations Manager
-- groups on. PlayerAwardRecommendationsPage GROUPs BY
-- (mundane_id, kingdomaward_id, COALESCE(rank,0)) and correlates two support-count
-- subqueries on exactly that tuple, for the count query, every 500-row page, every
-- infinite-scroll batch and the CSV export -- while ork_recommendations carried only
-- single-column keys and deleted_by was never indexed at all.
--
-- Column order is the group key first (mundane_id, kingdomaward_id, rank), which is
-- what the correlated subqueries actually seek on. NOTE: only mundane_id and
-- kingdomaward_id are sargable here -- EXPLAIN reports key_len=8 (two INTs) with
-- ref=func,func, because the queries compare COALESCE(rank,0) and test deleted_by
-- through an OR-clause. Those two trailing columns therefore act as index-condition
-- filters and as covering columns, not as seek keys; the measured win comes from the
-- narrower index scan, not from a deeper ref lookup.
CREATE INDEX IF NOT EXISTS idx_recs_mundane_ka_rank_deleted
  ON ork_recommendations (mundane_id, kingdomaward_id, `rank`, deleted_by);

-- NOTE: the seconds half of the support count (COUNT(DISTINCT s.supporter_mundane_id))
-- needs no new index. ork_recommendation_seconds already carries idx_supporter on
-- supporter_mundane_id, and EXPLAIN shows the subquery actually resolves via
-- uniq_rec_supporter (recommendations_id, supporter_mundane_id). An earlier revision of
-- this migration added idx_recsec_supporter here; CREATE INDEX IF NOT EXISTS matches on
-- index NAME, not column set, so that was NOT a no-op -- it created a second identical
-- single-column index. It has been removed. Any database that ran the earlier revision
-- should drop the duplicate:
--   DROP INDEX idx_recsec_supporter ON ork_recommendation_seconds;
