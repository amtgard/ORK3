<?php
$server->register(
		'Award.GetAwardList',
		array('GetAwardListRequest'=>'tns:GetAwardListRequest'),
		array('return' => 'tns:GetAwardListResponse'),
		$namespace
	);

$server->register(
		'Award.CreateAward',
		array('CreateAwardRequest'=>'tns:CreateAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Award.EditAward',
		array('EditAwardRequest'=>'tns:EditAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Award.RemoveAward',
		array('RemoveAwardRequest'=>'tns:RemoveAwardRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
?>