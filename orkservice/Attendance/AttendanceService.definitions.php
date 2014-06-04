<?php

/******************

	Inventory Service Definitions

$server->wsdl->addComplexType(
		'VendorComponentType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'VendorComponentId'=>array('name'=>'VendorComponentId','type'=>'xsd:int'),
				'VendorId'=>array('name'=>'VendorId','type'=>'xsd:int'),
				'ComponentId'=>array('name'=>'ComponentId','type'=>'xsd:int'),
				'VendorComponentName'=>array('name'=>'VendorComponentName','type'=>'xsd:string'),
				'ComponentName'=>array('name'=>'ComponentName','type'=>'xsd:string'),
				'Code'=>array('name'=>'Code','type'=>'xsd:string'),
				'QuantityPerPack'=>array('name'=>'QuantityPerPack','type'=>'xsd:int'),
				'Price'=>array('name'=>'Price','type'=>'xsd:float'),
				'LeadTime'=>array('name'=>'LeadTime','type'=>'xsd:int'),
				'Notes'=>array('name'=>'Notes','type'=>'xsd:string')
			)
	);

$server->wsdl->addComplexType(
		'VendorComponentList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:VendorComponentType[]')
			),
		'tns:VendorComponentType'
	);
	
******************/

/// GetClasses()

$server->wsdl->addComplexType(
		'GetClassesRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Active'=>array('name'=>'Active','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'ClassType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Active'=>array('name'=>'Active','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ClassesList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ClassType[]')
			),
		'tns:ClassType'
	);



$server->wsdl->addComplexType(
		'GetClassesResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Classes'=>array('name'=>'Classes','type'=>'tns:ClassesList')
			)
	);
	

/// CreateClass()
	
$server->wsdl->addComplexType(
		'CreateClassRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Active'=>array('name'=>'Active','type'=>'xsd:int'),
			)
	);

/// SetClass()

$server->wsdl->addComplexType(
		'SetClassRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'Active'=>array('name'=>'Active','type'=>'xsd:int'),
			)
	);

/// AddAttendance()
	
$server->wsdl->addComplexType(
		'AddAttendanceRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'Date'=>array('name'=>'ClassId','type'=>'xsd:date'),
				'Credits'=>array('name'=>'ClassId','type'=>'xsd:float'),
				'ParkId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'EventCalendarDetailId'=>array('name'=>'ClassId','type'=>'xsd:int'),
			)
	);

/// SetAttendance()
	
$server->wsdl->addComplexType(
		'SetAttendanceRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'AttendanceId'=>array('name'=>'AttendanceId','type'=>'xsd:int'),
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'Date'=>array('name'=>'ClassId','type'=>'xsd:date'),
				'Credits'=>array('name'=>'ClassId','type'=>'xsd:float')
			)
	);

/// RemoveAttendance()

$server->wsdl->addComplexType(
		'RemoveAttendanceRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'AttendanceId'=>array('name'=>'AttendanceId','type'=>'xsd:int')
			)
	);	
?>