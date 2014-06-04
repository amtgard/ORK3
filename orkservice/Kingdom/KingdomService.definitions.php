<?php

$server->wsdl->addComplexType(
		'CreateKingdomRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Abbreviation'=>array('name'=>'Abbreviation','type'=>'xsd:string'),
				'AveragePeriod'=>array('name'=>'AveragePeriod','type'=>'xsd:int'),
				'AttendancePeriodType'=>array('name'=>'AveragePeriod','type'=>'xsd:string'),
				'AttendanceMinimum'=>array('name'=>'AttendanceMinimum','type'=>'xsd:int'),
				'AttendanceCreditMinimum'=>array('name'=>'AttendanceCreditMinimum','type'=>'xsd:int'),
				'DuesPeriod'=>array('name'=>'DuesPeriod','type'=>'xsd:int'),
				'DuesPeriodType'=>array('name'=>'DuesPeriodType','type'=>'xsd:string'),
				'DuesAmount'=>array('name'=>'DuesAmount','type'=>'xsd:double'),
				'KingdomDuesTake'=>array('name'=>'KingdomDuesTake','type'=>'xsd:double')
			)
	);

$server->wsdl->addComplexType(
		'GetKingdomShortInfoRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'KingdomShortInfoType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'Abbreviation'=>array('name'=>'Abbreviation','type'=>'xsd:string'),
				'Active'=>array('name'=>'Active','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'GetKingdomShortInfoResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'KingdomInfo'=>array('name'=>'KingdomInfo','type'=>'tns:KingdomShortInfoType')
			)
	);

$server->wsdl->addComplexType(
		'GetKingdomDetailsRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);


$server->wsdl->addComplexType(
		'ParkTitleInfoType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ParkTitleId'=>array('name'=>'ParkTitleId','type'=>'xsd:int'),
				'Title'=>array('name'=>'Title','type'=>'xsd:string'),
				'Class'=>array('name'=>'Class','type'=>'xsd:int'),
				'MinimumAttendance'=>array('name'=>'MinimumAttendance','type'=>'xsd:int'),
				'MinimumCutoff'=>array('name'=>'MinimumCutoff','type'=>'xsd:int'),
				'Period'=>array('name'=>'Period','type'=>'xsd:string'),
				'Length'=>array('name'=>'Length','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'ParkTitleListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ParkTitleInfoType[]')
			),
		'tns:ParkTitleInfoType'
	);	
	
$server->wsdl->addComplexType(
		'GetKingdomDetailsResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'KingdomInfo'=>array('name'=>'KingdomInfo','type'=>'tns:KingdomShortInfoType'),
				'KingdomConfiguration'=>array('name'=>'KingdomInfo','type'=>'tns:ConfigurationListType'),
				'ParkTitles'=>array('name'=>'KingdomInfo','type'=>'tns:ParkTitleListType')
			)
	);

$server->wsdl->addComplexType(
		'GetKingdomAuthorizationsRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'KingdomAuthorizationInfoType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'AuthorizationId'=>array('name'=>'AuthorizationId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Role'=>array('name'=>'Role','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'KingdomAuthorizationListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:KingdomAuthorizationInfoType[]')
			),
		'tns:KingdomAuthorizationInfoType'
	);	
	
$server->wsdl->addComplexType(
		'GetKingdomAuthorizationsResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Authorizations'=>array('name'=>'Authorizations','type'=>'tns:KingdomAuthorizationListType')
			)
	);
	
$server->wsdl->addComplexType(
		'SetKingdomDetailsRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Abbreviation'=>array('name'=>'Abbreviation','type'=>'xsd:string'),		
				'KingdomConfiguration'=>array('name'=>'KingdomConfiguration','type'=>'tns:ConfigurationEditListType')
			)
	);

$server->wsdl->addComplexType(
		'WaffleKingdomRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);
	

?>