<?php

die();

$DONOTWEBSERVICE = true;

include_once('KingdomService.php');

$K = new Kingdom();
$request = array (
	'UserName' => 'admin',
	'Password' => 'p455w0rd'
);

$r = Authorize($request);

$request = array (
	'Token' => $r['Token'],
	'Name'=> 'test kingdom 3',
	'Abbreviation'=>'T3',
	'AveragePeriod'=>'6',
	'AttendancePeriodType'=>'month',
	'AttendanceMinimum'=>'1',
	'AttendanceCreditMinimum'=>'1',
	'DuesPeriod'=>'6',
	'DuesPeriodType'=>'month',
	'DuesAmount'=>'10',
	'KingdomDuesTake'=>'3'
);

//print_r($K->CreateKingdom($request));
$c = new Common();
$c->create_officers(2,0);
$c->create_officers(9,0);
$c->create_officers(11,0);
$c->create_officers(14,0);
$c->create_officers(1,1);
$c->create_officers(1,2);
$c->create_officers(1,5);
$c->create_officers(1,39);
$c->create_officers(2,3);
$c->create_officers(2,4);

?>