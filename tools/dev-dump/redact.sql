-- Redact a production copy so it can be handed to a new developer.
--
-- Run against a FRESHLY IMPORTED production dump in a local database.
-- Never run against production. Never run against a database you want to keep.
--
-- Scope agreed with Ken 2026-10-08: names, emails and credentials only, plus
-- dropping the audit log. Deliberately NOT redacted, because this dump is for
-- "getting their teeth wet" and anyone trusted further gets a real backup:
--   ork_mundane.username / other_name    (usernames are often real names)
--                                        -- EXCEPT where the username IS an
--                                        email address; see below
--   ork_mundane_note.note                (30k officer notes, prose, real names)
--   ork_recommendations.reason           (44k award write-ups about people)
--   ork_mundane_design.about_*           (user-written bios)
--   ork_rate_limit.ip_address
-- Revisit that list if the audience for this dump ever changes.

SET SESSION sql_mode = '';

-- Audit log: 694k rows carrying prior/post state JSON, including names.
-- Dropping it is also most of the size saving. Developers who need audit
-- behaviour can generate their own by using the app.
DELETE FROM ork_danger_audit;

-- Names and email. Blanked rather than replaced, per Ken: a developer who
-- needs realistic name rendering can ask for a real backup.
UPDATE ork_mundane        SET given_name = '', surname = '', email = '';
UPDATE ork_mundane_myisam SET given_name = '', surname = '', email = '';

-- Usernames that are themselves email addresses. Usernames generally stay (see
-- the header), but 15 people registered using their email as the login name, so
-- for those rows the address sits on the person record right beside the
-- given_name/surname/email just blanked above -- three identifiers removed and a
-- fourth left in place on the same row. Found 2026-10-10 by the export scan.
--
-- mundane_id keeps the replacement unique, which matters: ork_mundane.username
-- carries a UNIQUE index. This must run BEFORE the `logins` step, which hashes
-- strtoupper(username) . password -- the workflow order (redact, then logins)
-- already does that.
UPDATE ork_mundane
   SET username = CONCAT('redacted-', mundane_id)
 WHERE username REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}';
UPDATE ork_mundane_myisam
   SET username = CONCAT('redacted-', mundane_id)
 WHERE username REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}';

-- Stored credentials.
DELETE FROM ork_credential;
DELETE FROM ork_credential_myisam;

-- Live auth material. These are not a privacy nicety: a session token from a
-- production dump authenticates against PRODUCTION, because prod looks the same
-- string up in its own ork_session. Measured 2026-10-08: 4,715 of 6,426 session
-- rows were still valid, plus 72 live OAuth access/refresh tokens. Nobody loses
-- anything by clearing them -- developers log in normally with the accounts set
-- up by db-migrations/dev-set-test-logins.php (the `logins` step).
DELETE FROM ork_session;
DELETE FROM ork_idp_auth;

-- Same category, smaller: an attendance link records attendance at a REAL park
-- and a self-reg link creates a REAL player, both against production. 225 and
-- 147 rows as of 2026-10-08. Officers regenerate these from the park page.
--
-- NOT cleared, deliberately: ork_mundane.token and .xtoken. Checked 2026-10-08
-- -- xtoken is written in two places and read nowhere, and no code path
-- authenticates by ork_mundane.token (IsAuthorized resolves through
-- ValidateSessionByToken against ork_session). They are stale strings, not
-- working credentials, so they stay and the dump looks more like a real DB.
DELETE FROM ork_attendance_link;
DELETE FROM ork_selfreg_link;
