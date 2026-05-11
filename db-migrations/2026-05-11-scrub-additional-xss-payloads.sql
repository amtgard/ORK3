-- Second-pass security cleanup: scrub the remaining stored XSS payloads
-- discovered in the 2026-05-11 schema sweep. See the prior migration
-- (2026-05-11-scrub-viridian-probe-accounts.sql) for the original story
-- and what was already scrubbed (park 566 url, 227 inert probe accounts).
--
-- This migration handles:
--   1. Park 583 (Moonlit Meadows, Kingdom of the Golden Plains) — three
--      poisoned columns left by an unknown actor at an unknown time:
--        url:        Facebook URL with onclick='alert("XSS")' suffix
--        map_url:    javascript:alert(1)
--        directions: '>%00; (polyglot probe leftover)
--      The description field on this park appears legitimate, so it is
--      left alone.
--
--   2. Park 103 (Bifost) — hex-entity-encoded <script> XSS payload in
--      map_url, missed by the literal-string scan because every character
--      was &#x..; escaped.
--
--   3. Park 975 — probe leftover in map_url (">'>%00;&#x3E') and a bare
--      ">'> polyglot leftover in url — same probe style as Park 583's
--      directions field.
--
--   4. ork_recommendations rows 1868, 1871, 1872, 1880 — award
--      recommendations submitted on 2022-02-28 by "Captain Cuddles"
--      (mundane 71141) targeting "Saint Illcrest" (mundane 79514).
--      All four are already soft-deleted (deleted_at populated). Their
--      reason fields contain XSS probe payloads (location.href backticks,
--      String.fromCharCode escapes, and the polyglot from park 566).
--      We retain the rows for forensic visibility but blank the reason.
--
-- Deliberately NOT touched: parks 1128 (Bannerfall), 1130 (Mirrored
-- Cities), 1131 (Borealis Gardens) — their map_url fields contain
-- legitimate Google Maps <iframe src="..."> embed snippets that users
-- pasted in. They are not malicious; whether they should be rendered
-- raw is a separate template-side question.

-- 1. Park 583 — clear the three poisoned fields.
UPDATE ork_park
SET
    url        = '',
    map_url    = '',
    directions = ''
WHERE park_id = 583
  AND (   url        LIKE '%onclick=%'
       OR url        LIKE '%javascript:%'
       OR url        LIKE '%fromCharCode%'
       OR map_url    LIKE '%javascript:%'
       OR map_url    LIKE '%onclick=%'
       OR map_url    LIKE '%fromCharCode%'
       OR directions LIKE '%''>%'
       OR directions LIKE '%<script%'
       OR directions LIKE '%javascript:%' );

-- 2. Park 103 — hex-encoded <script> in map_url.
UPDATE ork_park
SET map_url = ''
WHERE park_id = 103
  AND map_url LIKE '%&#x73;&#x63;&#x72;&#x69;&#x70;&#x74;%';

-- 3. Park 975 — polyglot probe leftovers in map_url and url.
UPDATE ork_park
SET map_url = ''
WHERE park_id = 975
  AND map_url LIKE '%&#x3E%';

UPDATE ork_park
SET url = ''
WHERE park_id = 975
  AND url LIKE '%''>%';

-- 4. Soft-deleted recommendation reasons that contain XSS probe payloads.
UPDATE ork_recommendations
SET reason = '[scrubbed — security cleanup 2026-05-11]'
WHERE recommendations_id IN (1868, 1871, 1872, 1880)
  AND deleted_at IS NOT NULL
  AND (   reason LIKE '%<script%'
       OR reason LIKE '%javascript:%'
       OR reason LIKE '%onerror=%'
       OR reason LIKE '%onclick=%'
       OR reason LIKE '%fromCharCode%'
       OR reason LIKE '%<iframe%'
       OR reason LIKE '%<svg%' );

-- 5. Verification.
SELECT
    ( SELECT COUNT(*) FROM ork_park
        WHERE park_id = 583
          AND url = '' AND map_url = '' AND directions = '' )                AS park_583_cleared,
    ( SELECT COUNT(*) FROM ork_park
        WHERE park_id = 103 AND map_url = '' )                               AS park_103_cleared,
    ( SELECT COUNT(*) FROM ork_park
        WHERE park_id = 975 AND map_url = '' AND url = '' )                  AS park_975_cleared,
    ( SELECT COUNT(*) FROM ork_recommendations
        WHERE recommendations_id IN (1868, 1871, 1872, 1880)
          AND reason = '[scrubbed — security cleanup 2026-05-11]' )          AS recommendations_scrubbed;
