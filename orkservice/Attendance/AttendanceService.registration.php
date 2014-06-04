<?php
$server->register(
		'Attendance.GetClasses',
		array('GetClassesRequest'=>'tns:GetClassesRequest'),
		array('return' => 'tns:GetClassesResponse'),
		$namespace
	);

$server->register(
		'Attendance.CreateClass',
		array('CreateClassRequest'=>'tns:CreateClassRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	

$server->register(
		'Attendance.SetClass',
		array('SetClassRequest'=>'tns:SetClassRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	

$server->register(
		'Attendance.AddAttendance',
		array('AddAttendanceRequest'=>'tns:AddAttendanceRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	

$server->register(
		'Attendance.RemoveAttendance',
		array('RemoveAttendanceRequest'=>'tns:RemoveAttendanceRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
?>