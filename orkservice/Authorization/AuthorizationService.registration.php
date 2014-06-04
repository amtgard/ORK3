<?php
$server->register(
		'Authorization.Authorize',
		array('AuthorizeRequest'=>'tns:AuthorizeRequest'),
		array('return' => 'tns:AuthorizeResponse'),
		$namespace
	);
	
$server->register(
		'Authorization.XSiteAuthorize',
		array('XSiteAuthorizeRequest'=>'tns:XSiteAuthorizeRequest'),
		array('return' => 'tns:XSiteAuthorizeResponse'),
		$namespace
	);
	
$server->register(
		'Authorization.AddAuthorization',
		array('AddAuthorizationRequest'=>'tns:AddAuthorizationRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Authorization.RemoveAuthorization',
		array('RemoveAuthorizationRequest'=>'tns:RemoveAuthorizationRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Authorization.ResetPassword',
		array('ResetPasswordRequest'=>'tns:ResetPasswordRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
$server->register(
		'Authorization.GetAuthorizations',
		array('GetAuthorizationsRequest'=>'tns:GetAuthorizationsRequest'),
		array('return' => 'tns:GetAuthorizationsResponse'),
		$namespace
	);
	
$server->register(
		'Authorization.RequestAuthorization',
		array('GetAuthorizationsRequest'=>'tns:GetAuthorizationsRequest'),
		array('return' => 'tns:GetAuthorizationsResponse'),
		$namespace
	);
	
$server->register(
		'Authorization.GetApplicationRequests',
		array('GetApplicationRequestsRequest'=>'tns:GetApplicationRequestsRequest'),
		array('return' => 'tns:GetApplicationRequestsResponse'),
		$namespace
	);

$server->register(
		'Authorization.SetApplicationAuthorization',
		array('SetApplicationAuthorizationRequest'=>'tns:SetApplicationAuthorizationRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);	
	
$server->register(
		'Authorization.RegisterApplication',
		array('RegisterApplicationRequest'=>'tns:RegisterApplicationRequest'),
		array('return' => 'tns:StatusType'),
		$namespace
	);
	
?>