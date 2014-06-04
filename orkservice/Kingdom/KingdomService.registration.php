<?php

$server->register(
		'GetKingdomShortInfo',
		array('GetKingdomShortInfo'=>'tns:GetKingdomShortInfoRequest'),
		array('return' => 'tns:GetKingdomShortInfoResponse'),
		$namespace
	);
	
$server->register(
		'GetKingdomDetails',
		array('GetKingdomDetails'=>'tns:GetKingdomDetailsRequest'),
		array('return' => 'tns:GetKingdomDetailsResponse'),
		$namespace
	);

$server->register(
		'GetKingdomAuthorizations',
		array('GetKingdomAuthorizations'=>'tns:GetKingdomAuthorizationsRequest'),
		array('return' => 'tns:GetKingdomAuthorizationsResponse'),
		$namespace
	);

$server->register(
		'CreateKingdom',
		array('CreateKingdom'=>'tns:CreateKingdomRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'SetKingdomDetails',
		array('SetKingdomDetails'=>'tns:SetKingdomDetailsRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'RetireKingdom',
		array('RetireKingdom'=>'tns:WaffleKingdomRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'RestoreKingdom',
		array('RetireKingdom'=>'tns:WaffleKingdomRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Kingdom.GetAwardList',
		array('GetAwardListRequest'=>'tns:GetAwardListRequest'),
		array('return' => 'tns:GetAwardListResponse'),
		$namespace
	);

$server->register(
		'Kingdom.CreateAward',
		array('CreateAwardRequest'=>'tns:CreateAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Kingdom.EditAward',
		array('EditAwardRequest'=>'tns:EditAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Kingdom.RemoveAward',
		array('RemoveAwardRequest'=>'tns:RemoveAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
?>