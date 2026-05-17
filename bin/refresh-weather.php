#!/usr/bin/env php
<?php
/**
 * Refreshes the ork_park_weather cache for all active parks.
 * Designed to be invoked from cron every ~30 minutes:
 *
 *     # /etc/cron.d/ork-weather (or root's crontab)
 *     0,30 * * * * www-data /usr/bin/php /var/www/ORK3/bin/refresh-weather.php >> /var/log/ork-weather.log 2>&1
 *
 *   (Above uses '0,30' rather than the standard star-slash-30 because the
 *   shorthand closes the PHP doc-comment block.)
 *
 * Not strictly required — the on-read lazy fallback in Weather::for_park()
 * keeps data fresh enough for any single page render. The cron just keeps
 * the cache warm so users never pay the ~500ms Open-Meteo round trip.
 */

require_once dirname(__DIR__) . '/startup.php';

$start = microtime(true);
$count = Ork3::$Lib->weather->refresh_all_active_parks();
$dt    = round((microtime(true) - $start) * 1000);

fprintf(STDOUT, "[%s] refreshed %d parks in %dms\n", date('Y-m-d H:i:s'), $count, $dt);
exit($count > 0 ? 0 : 1);
