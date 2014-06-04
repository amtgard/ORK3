<?php

die();

$DONOTWEBSERVICE = true;

include_once('ParkService.php');

$P = new Park();
$request = array (
	'UserName' => 'kpmone',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

$request = array (
	'Token' => $r['Token'],
	'Name' => 'park again',
	'Abbreviation' => 'PA',
	'KingdomId' => 1
);

print_r($P->CreatePark($request));


?>