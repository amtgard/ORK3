-- One-shot security cleanup: scrub the 227 inert probe accounts created
-- 2024-09-28 in Viridian Outlands / park 566 (Sacred Wind), and the stored
-- XSS polyglot the same actor left in that park's URL field.
--
-- Background:
--   On 2024-09-28 21:34–21:38 an authenticated park officer (mundane 47936,
--   "mimic"/Matt Panattoni at park 566) called Player::CreatePlayer ~227
--   times via the JSON-RPC endpoint. Each call submitted path-traversal /
--   config-file fuzz payloads in given_name/surname/persona, all sharing
--   email "wwww@bomb.com". The accounts never logged in, never attended,
--   never received an award. The same actor also stored a polyglot XSS
--   payload in ork_park.url for park 566.
--
--   No code path currently renders these fields unescaped, but leaving the
--   payloads in place keeps them indexable, searchable, and a foot-gun for
--   any future caller that bypasses output escaping. We retain the rows for
--   forensic visibility (deletion would also break the audit-log entity_id
--   references) and mark them inactive — but deliberately NOT suspended, so
--   they don't clutter the kingdom's suspended-player reports. The marker
--   that identifies these as scrubbed rows is the synthetic persona
--   '[scrubbed]' plus the 'scrubbed-{id}' username pattern.

-- 1. Scrub the 227 inert mundane rows.
UPDATE ork_mundane
SET
    given_name      = '',
    surname         = '',
    other_name      = '',
    persona         = '[scrubbed]',
    email           = '',
    pronoun_custom  = NULL,
    username        = CONCAT('scrubbed-', mundane_id),
    password_salt   = '',
    token           = '',
    xtoken          = '',
    active          = 0
WHERE mundane_id BETWEEN 172377 AND 172608
  AND ( persona = 'qqqqq' OR email LIKE '%@bomb.com' );

-- 2. Scrub the stored-XSS polyglot from park 566's URL field.
UPDATE ork_park
SET url = ''
WHERE park_id = 566
  AND url LIKE '%alert(String.fromCharCode%';

-- 3. Verification snapshot.
SELECT
    ( SELECT COUNT(*) FROM ork_mundane
        WHERE persona = '[scrubbed]'
          AND username LIKE 'scrubbed-%'
          AND active = 0 )                                            AS scrubbed_mundane_rows,
    ( SELECT COUNT(*) FROM ork_park
        WHERE park_id = 566 AND url = '' )                            AS park_566_url_cleared;
