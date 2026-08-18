<?php

/**
 * 2026-08-11-park-theme-backfill.php
 *
 * Gives every EXISTING org site the theme row that new sites now get at seed time.
 *
 * Without a row, GetActiveCss() returns '' and the site falls through to the raw
 * CSS defaults — which is how every org site ended up rendering in MedievalSharp.
 * This is therefore a visual bug fix, not a nicety.
 *
 * CONSERVATIVE: skips any scope that already has a theme row, so an officer who has
 * already chosen a palette is never overwritten. Re-run safe.
 *
 * Run:
 *   docker exec -w /var/www/ork.amtgard.com ork3-php8-app php \
 *     db-migrations/2026-08-11-park-theme-backfill.php
 */

// This file lives under the docroot and reflectively invokes a private seeder
// across EVERY org site, so a stray HTTP request to its path would run a
// site-wide write with no authentication in front of it. Same guard 11 of the 13
// sibling migrations already carry.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// startup.php derives UIR/HTTP_TEMPLATE from HTTP_HOST, which CLI has no reason
// to set. Same CLI-time default the sibling migrations use.
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}

require_once __DIR__ . '/../startup.php';

global $DB;

$DB->Clear();
$sites = array();
$rs = $DB->DataSet(
    'SELECT s.scope_type, s.scope_id FROM ' . DB_PREFIX . 'cms_site s'
    . ' LEFT JOIN ' . DB_PREFIX . 'cms_theme t'
    . '   ON t.scope_type = s.scope_type AND t.scope_id = s.scope_id'
    . ' WHERE t.id IS NULL'
);
while ($rs && $rs->Next()) {
    $sites[] = array('type' => (string) $rs->scope_type, 'id' => (int) $rs->scope_id);
}

if (empty($sites)) {
    echo "Every org site already has a theme row — nothing to do.\n";
    return;
}

$site  = new CmsSite();
$seed  = new ReflectionMethod('CmsSite', '_seedOrgTheme');
$n = 0;
foreach ($sites as $s) {
    $primary = $seed->invoke($site, $s['type'], $s['id'], 0);
    if ($primary !== '') {
        $n++;
        echo "  {$s['type']} {$s['id']}: primary {$primary}\n";
    } else {
        echo "  {$s['type']} {$s['id']}: SKIPPED (no device, no parent, no name)\n";
    }
}

echo "\nBackfilled {$n} of " . count($sites) . " org site(s).\n";
