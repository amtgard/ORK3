-- Collapse scoped role='admin' grants in ork_authorization to role='create'.
--
-- Background: HasAuthority had a short-circuit that returned true whenever
-- the caller held *any* row with role='admin', regardless of the row's scope
-- (park_id / kingdom_id / unit_id / event_id). This silently granted system-
-- wide Ork Admin powers to anyone with a scoped admin grant — affecting 63
-- accounts holding 71 such rows. The "Cluster" investigation (see commit
-- log around 2026-05-11) traced multiple cross-kingdom account-takeover
-- patterns back to this leak.
--
-- The pending HasAuthority code fix tightens the short-circuit to require an
-- unscoped (all-zero foreign-key) row. This migration is the data-side
-- counterpart: it eliminates the rows that the bug was leaking through,
-- which closes the active threat *immediately* — even before the code fix
-- deploys — because the buggy short-circuit will find no matching row to
-- elevate.
--
-- Why role='create' is the right replacement:
-- For scoped grants (park/kingdom/unit/event), HasAuthority treats
-- role='admin' and role='create' identically — both return true for any
-- requested capability (AUTH_EDIT / AUTH_CREATE / AUTH_ADMIN) on the row's
-- scope. Converting admin → create therefore preserves every legitimate
-- power the grantee had at their actual scope, and only removes the
-- accidental global elevation.
--
-- True Ork Admin grants (all foreign keys = 0) are intentionally excluded.

-- 1. Convert the 71 scoped admin rows to create.
UPDATE ork_authorization
SET role = 'create'
WHERE role = 'admin'
  AND (park_id > 0 OR kingdom_id > 0 OR unit_id > 0 OR event_id > 0);

-- 2. Verification: should report 0 scoped admin rows remaining and the
--    original 23 unscoped (true Ork Admin) rows untouched.
SELECT 'scoped_admin_remaining'  AS metric,
       COUNT(*) AS n
FROM ork_authorization
WHERE role = 'admin'
  AND (park_id > 0 OR kingdom_id > 0 OR unit_id > 0 OR event_id > 0);

SELECT 'unscoped_admin_remaining' AS metric,
       COUNT(*) AS n,
       COUNT(DISTINCT mundane_id) AS distinct_ork_admins
FROM ork_authorization
WHERE role = 'admin'
  AND park_id = 0 AND kingdom_id = 0 AND unit_id = 0 AND event_id = 0;
