<?php
$server->register(
		'Event.CreateEvent',
		array('CreateEventRequest'=>'tns:CreateEventRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Event.GetEvent',
		array('GetEventRequest'=>'tns:GetEventRequest'),
		array('return' => 'tns:GetEventResponse'),
		$namespace
	);
	
$server->register(
		'Event.GetEventDetail',
		array('GetEventDetailRequest'=>'tns:GetEventDetailRequest'),
		array('return' => 'tns:GetEventDetailResponse'),
		$namespace
	);
	
$server->register(
		'Event.GetEventDetails',
		array('GetEventDetailRequest'=>'tns:GetEventDetailRequest'),
		array('return' => 'tns:GetEventDetailResponse'),
		$namespace
	);
	
$server->register(
		'Event.CreateEventDetails',
		array('CreateEventDetailsRequest'=>'tns:CreateEventDetailsRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Event.SetCurrent',
		array('SetCurrentRequest'=>'tns:SetCurrentRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Event.DeleteEventDetail',
		array('DeleteEventDetailRequest'=>'tns:DeleteEventDetailRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Event.SetEventDetails',
		array('SetEventDetailsRequest'=>'tns:SetEventDetailsRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

$server->register(
		'Event.SetEvent',
		array('SetEventRequest'=>'tns:SetEventRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);

	
?>