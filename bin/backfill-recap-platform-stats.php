#!/usr/bin/env php
<?php
/**
 * Refresh the Cloudflare block (PlatformStats) of ALREADY-STORED weekly recaps
 * so they carry the v2 fields the digest now reads (OriginRequests,
 * CfPageRequests, their Global twins, CacheHitsGlobal/CachedBytesGlobal,
 * TopOtherHost, StatsVersion). Nothing else in a week's payload is touched.
 *
 * This is deliberately NOT bin/compute-weekly-recap.php. That script rebuilds
 * the whole payload: for a week past Cloudflare's retention it would overwrite
 * a good PlatformStats block with null, and it re-derives HumanUsers, which was
 * hand-blanked for the 2025 bot-wave weeks. This one issues a single JSON_SET
 * per week on $.PlatformStats only, and leaves a week untouched when Cloudflare
 * cannot answer for it.
 *
 * The whole block is refetched rather than just the new keys: the digest's
 * buckets are remainders of one another, so they must come from one query
 * window. Grafting fresh origin counts onto a total that was stored by, say,
 * a mid-week manual run yields percentages over 100.
 *
 *     php bin/backfill-recap-platform-stats.php --weeks 4             # newest 4 stored weeks
 *     php bin/backfill-recap-platform-stats.php 2026-10-05 2026-10-12 # specific week_starts
 *     php bin/backfill-recap-platform-stats.php --weeks 6 --dry-run   # show, don't write
 *
 * On production, run it inside the live app container with the prod
 * environment selected (the container's default is DEV):
 *
 *     sudo docker exec -e ENVIRONMENT=PROD -e HTTP_HOST=ork.amtgard.com <app> \
 *         php /var/www/ork.amtgard.com/bin/backfill-recap-platform-stats.php --weeks 4
 *
 * Idempotent: re-running rewrites the block with fresh values. Weeks past
 * Cloudflare's retention are reported as skipped and keep whatever they had.
 */

ob_start();
require(__DIR__ . '/../startup.php');
ob_end_clean();

$report  = Ork3::$Lib->report;
$weeks   = array();
$count   = null;
$dry_run = false;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '--dry-run') {
        $dry_run = true;
    } elseif ($arg === '--weeks') {
        $count = (int)($args[++$i] ?? 0);
        if ($count <= 0) {
            fwrite(STDERR, "--weeks needs a positive number\n");
            exit(1);
        }
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
        $weeks[] = $arg;
    } else {
        fwrite(STDERR, "Invalid arg '$arg'. Expected YYYY-MM-DD, --weeks N, or --dry-run.\n");
        exit(1);
    }
}
if ($count !== null) {
    $weeks = array_merge($weeks, array_slice($report->ListRecapWeeks(52), 0, $count));
}
$weeks = array_values(array_unique($weeks));
if (empty($weeks)) {
    fwrite(STDERR, "Nothing to do: pass week_starts or --weeks N.\n");
    exit(1);
}

$written = 0;
$skipped = 0;
foreach ($weeks as $ws) {
    $existing = $report->ReadWeeklyRecap(array('WeekStart' => $ws));
    if (!is_array($existing)) {
        printf("[%s] %s  skip: no stored recap for this week\n", date('H:i:s'), $ws);
        $skipped++;
        continue;
    }
    $ps = $report->RecapPlatformStatsForWeek($ws);
    if (!is_array($ps)) {
        printf("[%s] %s  skip: Cloudflare could not answer (past retention, or credentials missing)\n", date('H:i:s'), $ws);
        $skipped++;
        continue;
    }
    $old = $existing['PlatformStats'] ?? null;
    $summary = sprintf(
        'requests=%s (was %s) origin=%s cfpages=%s | global requests=%s origin=%s cache=%s top_other=%s',
        number_format($ps['Requests']),
        is_array($old) ? number_format((int)($old['Requests'] ?? 0)) : 'null',
        number_format($ps['OriginRequests']),
        number_format((int)$ps['CfPageRequests']),
        $ps['RequestsGlobal'] === null ? 'n/a' : number_format($ps['RequestsGlobal']),
        $ps['OriginRequestsGlobal'] === null ? 'n/a' : number_format($ps['OriginRequestsGlobal']),
        $ps['CacheHitsGlobal'] === null ? 'n/a' : number_format($ps['CacheHitsGlobal']),
        is_array($ps['TopOtherHost']) ? $ps['TopOtherHost']['Host'] . '/' . number_format($ps['TopOtherHost']['Stopped']) : 'none'
    );
    if ($dry_run) {
        printf("[%s] %s  would write: %s\n", date('H:i:s'), $ws, $summary);
        continue;
    }
    if ($report->ReplaceWeeklyRecapPlatformStats($ws, $ps) === false) {
        printf("[%s] %s  FAILED to write\n", date('H:i:s'), $ws);
        $skipped++;
        continue;
    }
    printf("[%s] %s  written: %s\n", date('H:i:s'), $ws, $summary);
    $written++;
}
printf("done: %d written, %d skipped%s\n", $written, $skipped, $dry_run ? ' (dry run, nothing written)' : '');
exit(0);
