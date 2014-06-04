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
		'GetPlayerRoster',
		array('GetPlayerRoster'=>'tns:GetPlayerRosterRequest'),
		array('return' => 'tns:GetPlayerRosterResponse'),
		$namespace
	);
	
?>