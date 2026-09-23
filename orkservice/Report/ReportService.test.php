<?php

/**
 * @deprecated Manual dev script — superseded by PHPUnit (see docs/megiddo/refactor/06-test-framework.md).
 * Kept for reference; the Report domain still lacks PHPUnit coverage.
 */
$DONOTWEBSERVICE = true;

include_once('ReportService.php');

$R = new Report();

print_r($R->AttendanceForDate(array(
		'Date' => "2012-06-21"
	)));

?>