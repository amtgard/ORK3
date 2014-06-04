<?php

/******************

	Inventory Service Definitions

******************/

/// Authorize()


$server->wsdl->addComplexType(
		'AuthorizeRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Password'=>array('name'=>'Password','type'=>'xsd:string'),
				'Token'=>array('name'=>'Token','type'=>'xsd:string')
			)
	);

$server->wsdl->addComplexType(
		'AuthorizeResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'UserId'=>array('name'=>'PlayerId','type'=>'xsd:int'),
				'Timeout'=>array('name'=>'Timeout','type'=>'xsd:dateTime')
			)
	);

/// ResetPassword()
	
$server->wsdl->addComplexType(
		'ResetPasswordRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Email'=>array('name'=>'Email','type'=>'xsd:string')
			)
	);

/// XSiteAuthorize()
	
$server->wsdl->addComplexType(
		'XSiteAuthorizeRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Password'=>array('name'=>'Password','type'=>'xsd:string')
			)
	);

$server->wsdl->addComplexType(
		'XSiteAuthorizeResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Token'=>array('name'=>'Token','type'=>'xsd:string')
			)
	);

/// AddAuthorization()
	
$server->wsdl->addComplexType(
		'AddAuthorizationRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Role'=>array('name'=>'Role','type'=>'xsd:string'),
				'Id'=>array('name'=>'Id','type'=>'xsd:int')
			)
	);

/// RemoveAuthorization()
	
$server->wsdl->addComplexType(
		'RemoveAuthorizationRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'AuthorizationId'=>array('name'=>'AuthorizationId','type'=>'xsd:int')
			)
	);


/// GetAuthorizations()
	
$server->wsdl->addComplexType(
		'GetAuthorizationsRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'AuthorizationsItemType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'AuthorizationId'=>array('name'=>'AuthorizationId','type'=>'xsd:int'),
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Id'=>array('name'=>'Id','type'=>'xsd:int'),
				'Role'=>array('name'=>'Role','type'=>'xsd:string'),
				'Detail'=>array('name'=>'Detail','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'AuthorizationsList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:AuthorizationsItemType[]')
			),
		'tns:AuthorizationsItemType'
	);

$server->wsdl->addComplexType(
		'GetAuthorizationsResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Authorizations'=>array('name'=>'Authorizations','type'=>'tns:AuthorizationsList')
			)
	);
	
/// RequestAuthorization()
	
$server->wsdl->addComplexType(
		'RequestAuthorizationRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'AppId'=>array('name'=>'AppId','type'=>'xsd:string'),
				'AppSecret'=>array('name'=>'AppSecret','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int')
			)
	);
	
/// GetApplicationRequests
	
$server->wsdl->addComplexType(
		'GetApplicationRequestElementType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'ApplicationAuthorizationId'=>array('name'=>'ApplicationAuthorizationId','type'=>'xsd:int'),
				'ApplicationId'=>array('name'=>'ApplicationId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Approved'=>array('name'=>'Approved','type'=>'xsd:string'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Url'=>array('name'=>'Url','type'=>'xsd:string'),
				'Persona'=>array('name'=>'Persona','type'=>'xsd:string'),
				'GivenName'=>array('name'=>'GivenName','type'=>'xsd:string'),
				'Surname'=>array('name'=>'Surname','type'=>'xsd:string'),
				'Email'=>array('name'=>'Email','type'=>'xsd:string'),
			)
	);
		
$server->wsdl->addComplexType(
		'GetApplicationRequestList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:GetApplicationRequestElementType[]')
			),
		'tns:GetApplicationRequestElementType'
	);


$server->wsdl->addComplexType(
		'GetApplicationRequestsResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'ApplicationRequests'=>array('name'=>'ApplicationRequests','type'=>'tns:GetApplicationRequestList')
			)
	);
	
$server->wsdl->addComplexType(
		'GetApplicationRequestsRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string')
			)
	);
		
/// RegisterApplication() 

$server->wsdl->addComplexType(
		'RegisterApplicationRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'ApplicationRequests'=>array('name'=>'ApplicationRequests','type'=>'tns:GetApplicationRequestList')
			)
	);

$server->wsdl->addComplexType(
		'RegisterApplicationRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Status','type'=>'xsd:string'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Description'=>array('name'=>'Description','type'=>'xsd:string'),
				'Url'=>array('name'=>'Url','type'=>'xsd:string'),
				'AppSecret'=>array('name'=>'AppSecret','type'=>'xsd:string')
			)
	);

/// SetApplicationAuthorization()
	
$server->wsdl->addComplexType(
		'SetApplicationAuthorizationRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Status','type'=>'xsd:string'),
				'ApplicationAuthorizationId'=>array('name'=>'ApplicationAuthorizationId','type'=>'xsd:int'),
				'Approved'=>array('name'=>'Approved','type'=>'xsd:string')
			)
	);

?>