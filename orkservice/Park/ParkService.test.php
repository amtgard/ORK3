<?php

/**
 * @deprecated Manual dev script — superseded by PHPUnit (see docs/megiddo/refactor/06-test-framework.md).
 * Kept for reference; die() prevents accidental execution against a live database.
 */
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