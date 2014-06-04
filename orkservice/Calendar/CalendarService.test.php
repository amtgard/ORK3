<?php

$DONOTWEBSERVICE = true;

include_once('CalendarService.php');

$request = array( 'Type' => 'Year', 'Date' => date("Y-m-d"));

$C = new APIModel("Calendar");
$r = $C->Next($request);

print_r($r);

die();

?>