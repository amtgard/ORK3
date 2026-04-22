-- Expand ork_danger_audit JSON columns from VARCHAR(1000) to TEXT so large
-- player-state payloads are no longer silently truncated.
ALTER TABLE ork_danger_audit
  MODIFY COLUMN parameters  TEXT NOT NULL,
  MODIFY COLUMN prior_state TEXT NOT NULL,
  MODIFY COLUMN post_state  TEXT NOT NULL;

-- Drop useless indexes:
--   prior_state / post_state / parameters: FULLTEXT indexes on JSON columns —
--     no queries use MATCH...AGAINST on these, and they bloat every INSERT.
--   entity: BTREE with cardinality 1 (every row = 'Player') — never selective.
DROP INDEX prior_state ON ork_danger_audit;
DROP INDEX post_state  ON ork_danger_audit;
DROP INDEX parameters  ON ork_danger_audit;
DROP INDEX entity      ON ork_danger_audit;

-- Add an index on entity_id to support player-centric lookups in the viewer.
ALTER TABLE ork_danger_audit ADD INDEX idx_entity_id (entity_id);

-- Backfill entity_id for historical AddAward records where the Yapo ORM failed
-- to persist the value (it defaulted to 0). RecipientId is always in parameters.
-- Note: ~58 records have truncated JSON (legacy VARCHAR(1000) overflow) and will
-- produce "Unexpected end of JSON text" warnings — these rows stay at entity_id=0
-- and the warnings are harmless.
UPDATE ork_danger_audit
SET entity_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(parameters, '$.RecipientId')) AS UNSIGNED)
WHERE method_call IN ('Player::AddAward', 'Player::GiveAward')
  AND entity_id = 0
  AND JSON_EXTRACT(parameters, '$.RecipientId') IS NOT NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(parameters, '$.RecipientId')) AS UNSIGNED) > 0;

-- Backfill entity_id for RemoveAward / revoke / reactivate / update award records.
-- The recipient's mundane_id is in prior_state (captured before the award is modified).
UPDATE ork_danger_audit
SET entity_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(prior_state, '$.mundane_id')) AS UNSIGNED)
WHERE method_call IN ('Player::RemoveAward', 'Player::revoke_award',
                      'Player::ReactivateAward', 'Player::UpdateAward')
  AND entity_id = 0
  AND JSON_EXTRACT(prior_state, '$.mundane_id') IS NOT NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(prior_state, '$.mundane_id')) AS UNSIGNED) > 0;
