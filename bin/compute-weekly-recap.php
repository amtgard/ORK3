#!/usr/bin/env php
<?php
/**
 * Computes and persists the "Amtgard Week in Review" recap for the most recently
 * completed week (server-local Mon 00:00 → Sun 23:59). One row per week_start
 * in ork_weekly_recap; UPSERT so re-running is safe.
 *
 * Intended cron (Monday 6am, server local):
 *
 *     # /etc/cron.d/ork-weekly-recap
 *     0 6 * * 1 www-data /usr/bin/php /var/www/ORK3/bin/compute-weekly-recap.php >> /var/log/ork-weekly-recap.log 2>&1
 *
 * Manual rerun for a specific week (rare, e.g. backfill):
 *
 *     php bin/compute-weekly-recap.php 2026-05-25
 *
 * Reads via Report::GetWeeklyRecap(), writes via Report::StoreWeeklyRecap().
 *
 * For the Cloudflare "ORK This Week" section, the cron needs CF_API_TOKEN and
 * CF_ZONE_ID in its environment. Add them to the crontab line or PHP-FPM env.
 * If they're missing or CF errors out, PlatformStats is stored as null and the
 * template omits that section — recap still ships.
 */

require_once dirname(__DIR__) . '/startup.php';

$report = Ork3::$Lib->report;

$request = array();
if (!empty($argv[1])) {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
		fprintf(STDERR, "Invalid week_start '%s'. Expected YYYY-MM-DD.\n", $argv[1]);
		exit(1);
	}
	$request['WeekStart'] = $argv[1];
}

$start = microtime(true);
$recap = $report->GetWeeklyRecap($request);
$elapsed_ms = round((microtime(true) - $start) * 1000);

$result = $report->StoreWeeklyRecap($recap);
if ($result === false) {
	fprintf(STDERR, "[%s] StoreWeeklyRecap returned false for week %s\n",
		date('Y-m-d H:i:s'), $recap['WeekStart']);
	exit(1);
}

$cf_note = is_array($recap['PlatformStats'])
	? sprintf(' cf=%dM_reqs/%dGB', $recap['PlatformStats']['Requests'] / 1000000, $recap['PlatformStats']['Bytes'] / 1000000000)
	: ' cf=n/a';

fprintf(STDOUT,
	"[%s] week=%s computed in %dms — knights=%d masters=%d paragons=%d events=%d parks=%d new_players=%d returning=%d milestones=%d%s\n",
	date('Y-m-d H:i:s'),
	$recap['WeekStart'],
	$elapsed_ms,
	count($recap['Knightings']),
	count($recap['Masterhoods']),
	count($recap['Paragons']),
	count($recap['TopEvents']),
	count($recap['TopParks']),
	$recap['NewPlayers']['Count'],
	count($recap['ReturningPlayers']),
	count($recap['MilestoneEvents']),
	$cf_note
);
exit(0);
