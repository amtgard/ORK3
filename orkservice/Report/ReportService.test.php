<?php

$DONOTWEBSERVICE = true;

include_once('ReportService.php');

$R = new Report();

print_r($R->AttendanceForDate(array(
		'Date' => "2012-06-21"
	)));

?>