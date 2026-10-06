#!/usr/bin/env php
<?php

/**
 * Posts owed survey attendance credits
 * (docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3.5).
 *
 * Credits are normally posted when a player submits, when an officer turns a
 * credit on, and when the Credits panel opens; this sweep repairs any grant
 * that failed in between. Reconcile is idempotent, so running it often is
 * harmless. Optional cron:
 *
 *     # /etc/cron.d/ork-survey-credit-sweep
 *     15 * * * * www-data HTTP_HOST=ork.amtgard.com /usr/bin/php /var/www/ORK3/bin/survey-credit-sweep.php >> /var/log/ork-survey-credit-sweep.log 2>&1
 *
 * The site's public host is required (HTTP_HOST in the environment, or
 * --host=ork.amtgard.com). The config builds every URL from
 * $_SERVER['HTTP_HOST'], which a CLI run does not have, and a credit event the
 * sweep creates links to its survey through it; without a host that link would
 * be dropped. Add HTTPS=on too when the site is served over TLS and its config
 * is scheme-aware. Exits 2 when the host is missing or malformed.
 *
 * CLI only. bin/ sits under the web docroot, and under FPM getenv('HTTP_HOST')
 * is the request's own Host header, so without this guard any anonymous GET
 * would run the whole credit engine and could plant a spoofed host in the
 * link of an event it creates.
 */

if ('cli' !== PHP_SAPI) {
    header('HTTP/1.1 404 Not Found');
    exit(1);
}

$host = '';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (strncmp($arg, '--host=', 7) === 0) {
        $host = substr($arg, 7);
    }
}
if ($host === '') {
    $host = (string) (getenv('HTTP_HOST') ?: '');
}
$host = strtolower(trim($host));
if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?$/', $host)) {
    fwrite(STDERR, "survey-credit-sweep: set the site's public host with HTTP_HOST=ork.amtgard.com or --host=ork.amtgard.com"
        . " (credit events link to their survey through it).\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = $host;

require_once dirname(__DIR__) . '/startup.php';

$credit = Ork3::$Lib->surveycredit;
foreach ($credit->surveysWithConfigs() as $surveyId) {
    $r = $credit->reconcile($surveyId);
    if ($r['Granted'] > 0 || $r['Pending'] > 0) {
        fprintf(
            STDOUT,
            "[%s] survey=%d granted=%d pending=%d skipped_no_park=%d\n",
            date('Y-m-d H:i:s'),
            $surveyId,
            $r['Granted'],
            $r['Pending'],
            $r['SkippedNoPark']
        );
    }
}
exit(0);
