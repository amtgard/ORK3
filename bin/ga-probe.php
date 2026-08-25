#!/usr/bin/env php
<?php

/**
 * Quick check of the GA4 Data API wiring, and the answer to "how many humans
 * visit the ORK?" without opening the Analytics UI.
 *
 *     php bin/ga-probe.php            # last 30 days + last full calendar month
 *     php bin/ga-probe.php 90         # last 90 days instead
 *
 * Needs GA4_PROPERTY_ID and GA4_SA_KEY_PATH in config.php (see
 * Report::GaActiveUsers). Prints "unavailable" if config is missing or the
 * API call fails — the same null-safe behavior the weekly recap has.
 */

require_once dirname(__DIR__) . '/startup.php';

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : 30;
$report = Ork3::$Lib->report;

$fmt = function ($n) {
    return $n === null ? 'unavailable (missing config or API error)' : number_format($n);
};

$since = date('Y-m-d', strtotime("-{$days} days"));
$today = date('Y-m-d');
printf(
    "GA4 unique human visitors (activeUsers), property %s\n",
    defined('GA4_PROPERTY_ID') ? GA4_PROPERTY_ID : (getenv('GA4_PROPERTY_ID') ?: '(unset)')
);
printf("  last %d days (%s .. %s): %s\n", $days, $since, $today, $fmt($report->GaActiveUsers($since, $today)));

$month_start = date('Y-m-01', strtotime('first day of last month'));
$month_end   = date('Y-m-t', strtotime('first day of last month'));
printf("  last calendar month (%s .. %s): %s\n", $month_start, $month_end, $fmt($report->GaActiveUsers($month_start, $month_end)));
