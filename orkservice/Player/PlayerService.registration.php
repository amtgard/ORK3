<?php

$server->register(
		'Player.SetPlayerReconciledCredits',
		array('SetPlayerReconciledCreditsRequest'=>'tns:SetPlayerReconciledCreditsRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Player.GetPlayer',
		array('GetPlayerRequest'=>'tns:GetPlayerRequest'),
		array('return' => 'tns:GetPlayerResponse'),
		$namespace
	);


$server->register(
		'Player.AttendanceForPlayer',
		array('AttendanceForPlayerRequest'=>'tns:AttendanceForPlayerRequest'),
		array('return' => 'tns:AttendanceForPlayerResponse'),
		$namespace
	);
	
$server->register(
		'Player.AwardsForPlayer',
		array('AwardsForPlayerRequest'=>'tns:AwardsForPlayerRequest'),
		array('return' => 'tns:AwardsForPlayerResponse'),
		$namespace
	);
	
$server->register(
		'Player.GetPlayerClasses',
		array('GetPlayerClassesRequest'=>'tns:GetPlayerClassesRequest'),
		array('return' => 'tns:GetPlayerClassesResponse'),
		$namespace
	);
	
$server->register(
		'Player.CreatePlayer',
		array('CreatePlayerRequest'=>'tns:CreatePlayerRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.MergePlayer',
		array('MergePlayerRequest'=>'tns:MergePlayerRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.MovePlayer',
		array('MovePlayerRequest'=>'tns:MovePlayerRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.UpdatePlayer',
		array('UpdatePlayerRequest'=>'tns:UpdatePlayerRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.SetRestriction',
		array('SetRestrictionRequest'=>'tns:SetRestrictionRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.SetWaiver',
		array('SetWaiverRequest'=>'tns:SetWaiverRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.SetBan',
		array('SetBanRequest'=>'tns:SetBanRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.AddAward',
		array('AddAwardRequest'=>'tns:AddAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Player.UpdateAward',
		array('UpdateAwardRequest'=>'tns:UpdateAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
	
$server->register(
		'Player.RemoveAward',
		array('RemoveAwardRequest'=>'tns:RemoveAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Player.ReconcileAward',
		array('ReconcileAwardRequest'=>'tns:ReconcileAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Player.AutoAssignRanks',
		array('AutoAssignRanksRequest'=>'tns:AutoAssignRanksRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Player.ResetWaivers',
		array('ResetWaiversRequest'=>'tns:ResetWaiversRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

?>