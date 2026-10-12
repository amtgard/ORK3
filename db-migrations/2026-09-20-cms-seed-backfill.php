<?php

/**
 * 2026-09-20-cms-seed-backfill.php
 *
 * THE PRODUCTION ENTRY POINT for CmsSite::BackfillSeedContent().
 *
 * Starter content is versioned (ork_cms_site.seed_version, CmsStarterContent),
 * and the runner that carries a version bump to already-seeded sites has always
 * existed — but nothing in the application ever called it. No controller, no
 * render path, no CLI. A copy fix or a template redesign therefore landed for
 * sites seeded AFTER it and for nobody else. This is the missing caller: it runs
 * the runner over every site whose seed_version is behind
 * CmsSite::CURRENT_SEED_VERSION.
 *
 * WHAT IT CAN CHANGE, and the one rule that never bends: the runner rewrites a
 * stored field, restructures a page, adds a starter page or appends a nav row
 * ONLY where the content is still byte-for-byte what that site's recorded seed
 * version wrote. Anything an officer has edited — one word, one character, one
 * re-ordered block, one rearranged menu — no longer matches and is left exactly
 * as they left it. See the safety property spelled out in
 * CmsSite::BackfillSeedContent()'s docblock; this script adds no writes of its
 * own and no rules of its own.
 *
 * ORDER MATTERS FOR KINGDOMS SEEDED BEFORE THIS BRANCH. Those sites are stamped
 * seed_version = 0 while holding the PRE-branch copy, which is not what v0
 * re-derives, so they simply do not match and are skipped (safely, silently).
 * Run 2026-09-20-cms-kingdom-seed-copy-repair.php FIRST to bring their stored
 * copy in line; this script then has something to match against.
 *
 * RE-RUNNABLE. A site already at CURRENT_SEED_VERSION reports 'current' and is
 * not read any further. A site that failed a page write mid-run is deliberately
 * left behind its version so the next run retries it; every write is idempotent,
 * so a retry can only finish the job.
 *
 * Reports PER SITE what it did, then a summary.
 *
 * Run: php db-migrations/2026-09-20-cms-seed-backfill.php
 *      php db-migrations/2026-09-20-cms-seed-backfill.php --dry-run
 */

require_once __DIR__ . '/_cms_cli_bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);

$site    = new CmsSite();
$current = CmsSite::CURRENT_SEED_VERSION;

// Read through the library, not raw SQL: ListAllSites() already returns the
// whole ork_cms_site row (seed_version included) plus the org's display name,
// which is what makes the per-site report readable. Buffered in full before any
// write, because the shared $DB handle is single-cursor.
$sites = $site->ListAllSites();

echo "CMS starter-content backfill — target version {$current}"
    . ($dryRun ? "  [DRY RUN — nothing will be written]" : '') . "\n\n";

if (!is_array($sites) || count($sites) === 0) {
    echo "No CMS sites exist. Nothing to do.\n";
    return;
}
if (!array_key_exists('seed_version', $sites[0])) {
    // Same fail-open convention the runner itself uses: without the column
    // there is no recorded version to re-derive against, and guessing one is
    // exactly what the byte-match gate exists to avoid.
    echo "ork_cms_site.seed_version does not exist yet — run\n"
        . "  db-migrations/2026-09-20-cms-site-seed-version.sql\n"
        . "first. Nothing was read or written.\n";
    return;
}

$behind = array();
foreach ($sites as $row) {
    if ((int) $row['seed_version'] < $current) {
        $behind[] = $row;
    }
}

echo 'Sites: ' . count($sites) . ' total, ' . count($behind) . " behind version {$current}.\n\n";

$nUpdated = 0;
$nSkipped = 0;
$nFields  = 0;
$nBlocks  = 0;
$nPages   = 0;
$nRestruc = 0;
$nDeclined = 0;
$nNav     = 0;

foreach ($behind as $row) {
    $siteId = (int) $row['site_id'];
    $label  = (string) $row['scope_type'] . ' ' . trim((string) $row['org_name'])
        . ' (/' . CmsSite::UrlPrefixFor((string) $row['scope_type']) . '/' . (string) $row['slug'] . ')';

    if ($dryRun) {
        echo "  WOULD RUN  {$label} — seed_version " . (int) $row['seed_version'] . " -> {$current}\n";
        continue;
    }

    // uid 0 = "the system", the same actor the seed itself writes under on a
    // path with no logged-in officer. It rides on the revision snapshot and the
    // ork_cms_audit row, so the change is attributable to this migration rather
    // than to whichever officer happened to be in the session.
    $r = $site->BackfillSeedContent($siteId, 0);

    if ($r['status'] === 'updated') {
        $nUpdated++;
        $nFields  += (int) $r['fields'];
        $nBlocks  += (int) $r['blocks'];
        $nPages   += (int) $r['pages_created'];
        $nRestruc += (int) $r['pages_restructured'];
        $nNav     += (int) $r['nav_added'];
        $nDeclined += (int) $r['pages_declined'];
        echo "  UPDATED    {$label}: v{$r['from']} -> v{$r['to']}"
            . '  fields=' . (int) $r['fields']
            . ' blocks=' . (int) $r['blocks']
            . ' pages_rebuilt=' . (int) $r['pages_restructured']
            . ' pages_added=' . (int) $r['pages_created']
            . ' nav_added=' . (int) $r['nav_added']
            . (($r['nav_reason'] !== '' && (int) $r['nav_added'] === 0)
                ? ' (nav: ' . $r['nav_reason'] . ')' : '')
            // A page the new version wanted to restructure and the safety gate
            // refused. The version is stamped regardless, so this line is the
            // ONLY record that this site declined — name the pages here or an
            // operator can never find them again.
            . ((int) $r['pages_declined'] > 0
                ? ' DECLINED=' . (int) $r['pages_declined']
                  . ' (' . implode(', ', (array) $r['declined_slugs']) . ' — edited, left alone)'
                : '')
            . "\n";
    } elseif ($r['status'] === 'current') {
        echo "  CURRENT    {$label}: already at v{$r['to']}\n";
    } else {
        $nSkipped++;
        echo "  SKIPPED    {$label}: {$r['reason']} (still v{$r['from']})\n";
    }
}

if ($dryRun) {
    echo "\nDry run complete. Nothing was written.\n";
    return;
}

echo "\nBackfill complete.\n";
echo "  sites upgraded            : {$nUpdated}\n";
echo "  sites skipped             : {$nSkipped}\n";
echo "  fields re-worded          : {$nFields}\n";
echo "  blocks written            : {$nBlocks}\n";
echo "  pages rebuilt (untouched) : {$nRestruc}\n";
echo "  starter pages added       : {$nPages}\n";
echo "  pages declined (edited)   : {$nDeclined}\n";
echo "  nav rows appended         : {$nNav}\n";
echo "\nNote: only content still byte-identical to what that site's recorded seed\n"
    . "version wrote was touched. Every page, block, menu and word an officer had\n"
    . "edited was left exactly as they wrote it. A SKIPPED site is safe: it stays\n"
    . "on its old version and a later re-run retries it.\n";
