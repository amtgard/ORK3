<?php

$server->register(
		'GetParkShortInfo',
		array('GetParkShortInfo'=>'tns:GetParkShortInfoRequest'),
		array('return' => 'tns:GetParkShortInfoResponse'),
		$namespace
	);

$server->register(
		'GetParkAuthorizations',
		array('GetParkAuthorizations'=>'tns:GetParkAuthorizationsRequest'),
		array('return' => 'tns:GetParkAuthorizationsResponse'),
		$namespace
	);

$server->register(
		'CreatePark',
		array('CreatePark'=>'tns:CreateParkRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'SetParkDetails',
		array('SetParkDetails'=>'tns:SetParkDetailsRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'RetirePark',
		array('RetirePark'=>'tns:WaffleParkRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'RestorePark',
		array('RetirePark'=>'tns:WaffleParkRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

?>