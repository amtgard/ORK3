<?php
$server->register(
		'GetActiveKingdomsSummary',
		array('GetActiveKingdomsSummary'=>'tns:GetActiveKingdomsSummaryRequest'),
		array('return' => 'tns:GetActiveKingdomsSummaryResponse'),
		$namespace
	);

$server->register(
		'GetActivePlayers',
		array('GetActivePlayers'=>'tns:GetActivePlayersRequest'),
		array('return' => 'tns:GetActivePlayersResponse'),
		$namespace
	);
	
$server->register(
		'GetKingdomParkAverages',
		array('GetKingdomParkAverages'=>'tns:GetKingdomParkAveragesRequest'),
		array('return' => 'tns:GetKingdomParkAveragesResponse'),
		$namespace
	);

$server->register(
		'GetKingdomParkMonthlyAverages',
		array('GetKingdomParkMonthlyAverages'=>'tns:GetKingdomParkMonthlyAveragesRequest'),
		array('return' => 'tns:GetKingdomParkMonthlyAveragesResponse'),
		$namespace
	);

$server->register(
		'GetTopParksByAttendance',
		array('GetTopParksByAttendance'=>'tns:GetTopParksByAttendanceRequest'),
		array('return' => 'tns:GetTopParksByAttendanceResponse'),
		$namespace
	);

$server->register(
		'GetPlayerRoster',
		array('GetPlayerRoster'=>'tns:GetPlayerRosterRequest'),
		array('return' => 'tns:GetPlayerRosterResponse'),
		$namespace
	);
	
?>