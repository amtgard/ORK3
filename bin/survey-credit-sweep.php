#!/usr/bin/env php
<?php

/**
 * Posts owed survey bonus credits
 * (docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §3.5).
 *
 * Credits are normally posted when a player submits, when an officer switches
 * a credit on, and when the Credits panel opens; this sweep repairs any grant
 * that failed in between, posts the ones a web request left over (bounded
 * backfills), and posts credits held while a player was suspended once the
 * suspension lifts. Reconcile is idempotent, so running it often is harmless.
 * Optional cron:
 *
 *     # /etc/cron.d/ork-survey-credit-sweep
 *     15 * * * * www-data /usr/bin/php /var/www/ORK3/bin/survey-credit-sweep.php >> /var/log/ork-survey-credit-sweep.log 2>&1
 *
 * CLI only: bin/ sits under the web docroot, and an anonymous GET must not be
 * able to run the credit engine.
 */

if ('cli' !== PHP_SAPI) {
    header('HTTP/1.1 404 Not Found');
    exit(1);
}

// The site config reads $_SERVER['HTTP_HOST'] for its URL constants; a CLI run
// has none. Nothing here builds a URL, so any well-formed value will do.
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = (string) (getenv('HTTP_HOST') ?: 'localhost');
}

require_once dirname(__DIR__) . '/startup.php';

$credit = new SurveyCredit();
foreach ($credit->surveysWithConfigs() as $surveyId) {
    $r = $credit->reconcile($surveyId);
    if ($r['Granted'] > 0 || $r['Pending'] > 0) {
        fprintf(
            STDOUT,
            "[%s] survey=%d granted=%d pending=%d skipped=%d\n",
            date('Y-m-d H:i:s'),
            $surveyId,
            $r['Granted'],
            $r['Pending'],
            $r['Skipped']
        );
    }
}
exit(0);
