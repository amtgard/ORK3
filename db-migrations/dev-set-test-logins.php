<?php
/**
 * dev-set-test-logins.php  —  LOCAL DEV ONLY
 *
 * Sets a known, simple password on one representative account per permission tier
 * (ORK admin / kingdom officer / regular player) so developers can log in and
 * exercise every access level. Re-run after each fresh database import.
 *
 * SAFETY: refuses to run unless it detects the local dev database
 * (DB_HOSTNAME === 'ork3-php8-db'), so it can never touch production even if
 * committed. It only changes passwords; it does not alter roles/authority.
 *
 * Passwords are set through the app's own Authorization::SaltPassword(), so the
 * SHA-512 salted-crypt hash (stored in ork_credential) is always correct, and the
 * login string it hashes is  strtoupper(username) . password.
 *
 * Run (from the repo root, dev containers up):
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/dev-set-test-logins.php
 *
 * Options (env):
 *   DEV_PASSWORD=secret   password to set   (default: "password")
 *   DEV_KINGDOM=31        kingdom to source the officer/regular accounts from
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

define('DONOTWEBSERVICE', true);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:19080';
chdir('/var/www/ork.amtgard.com/orkui');
ob_start(); require('/var/www/ork.amtgard.com/startup.php'); ob_end_clean();

// ---- SAFETY GUARD: local dev database only --------------------------------
if (!defined('DB_HOSTNAME') || DB_HOSTNAME !== 'ork3-php8-db') {
    fwrite(STDERR, "REFUSING TO RUN: local-dev only.\n"
        . "  DB_HOSTNAME is '" . (defined('DB_HOSTNAME') ? DB_HOSTNAME : '(undefined)') . "', expected 'ork3-php8-db'.\n");
    exit(1);
}

$PASSWORD = getenv('DEV_PASSWORD') ?: 'password';
$KID      = (int)(getenv('DEV_KINGDOM') ?: 31);

global $DB;
function firstRow($sql) { global $DB; $r = $DB->DataSet($sql); return ($r && $r->Next()) ? $r : null; }

// One representative account per tier, chosen dynamically so it survives a fresh
// import. Each query requires a non-empty username (login needs it).
$targets = array(
    'ORK Admin' => firstRow(
        "SELECT m.mundane_id, m.persona, m.username, 'global' AS scope
         FROM ork_authorization a JOIN ork_mundane m ON m.mundane_id = a.mundane_id
         WHERE a.role='admin' AND a.park_id=0 AND a.kingdom_id=0 AND a.unit_id=0 AND a.event_id=0
           AND LENGTH(m.username) > 0 LIMIT 1"),
    'Kingdom Officer' => firstRow(
        "SELECT m.mundane_id, m.persona, m.username, o.role AS scope
         FROM ork_officer o JOIN ork_mundane m ON m.mundane_id = o.mundane_id
         WHERE o.kingdom_id=$KID AND o.park_id=0 AND o.role IN ('Monarch','Regent','Prime Minister')
           AND LENGTH(m.username) > 0 LIMIT 1"),
    'Regular Player' => firstRow(
        "SELECT m.mundane_id, m.persona, m.username, p.name AS scope
         FROM ork_mundane m LEFT JOIN ork_park p ON p.park_id = m.park_id
         WHERE m.kingdom_id=$KID AND m.active=1 AND LENGTH(m.username) > 0
           AND m.mundane_id NOT IN (SELECT mundane_id FROM ork_authorization)
           AND m.mundane_id NOT IN (SELECT mundane_id FROM ork_officer) LIMIT 1"),
);

function setPassword($mundane_id, $password) {
    global $DB;
    $m = new yapo($DB, DB_PREFIX . 'mundane');
    $m->clear();
    $m->mundane_id = (int)$mundane_id;
    if (!$m->find()) { return null; }
    $username = trim($m->username);
    // Fresh salt = old password (and any stale credentials) stop matching.
    $m->password_salt    = md5(mt_rand() . microtime());
    // Far-future expiry so the UI doesn't force a password change on login.
    $m->password_expires = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 365);
    $m->save();
    // Same hash + login-string the login path uses: strtoupper(username) . password.
    Authorization::SaltPassword($m->password_salt, strtoupper($username) . $password, $m->password_expires, 0);
    // Self-check: does the login verifier accept it now?
    $ok = Authorization::KeyExists($m->password_salt, strtoupper($username) . $password);
    return array('username' => $username, 'ok' => $ok);
}

printf("\nLOCAL DEV test logins  (password: \"%s\",  kingdom id: %d)\n", $PASSWORD, $KID);
printf("%-16s %-22s %-16s %-8s %s\n", 'Tier', 'Persona', 'Username', 'Login', 'Scope');
printf("%s\n", str_repeat('-', 78));
foreach ($targets as $tier => $row) {
    if ($row === null) {
        printf("%-16s %-22s (no matching account found)\n", $tier, '-');
        continue;
    }
    $res = setPassword($row->mundane_id, $PASSWORD);
    if ($res === null) {
        printf("%-16s %-22s FAILED to load mundane #%d\n", $tier, $row->persona, $row->mundane_id);
        continue;
    }
    printf("%-16s %-22s %-16s %-8s %s\n",
        $tier,
        mb_strimwidth((string)$row->persona, 0, 22),
        $res['username'],
        $res['ok'] ? 'OK' : 'FAIL',
        (string)$row->scope);
}
printf("\nLog in at http://localhost:19080/orkui/  with the Username above + the password.\n\n");
