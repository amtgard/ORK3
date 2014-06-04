<?php

/******************

	Inventory Service Definitions

******************/


$server->wsdl->addComplexType(
		'CreateEventRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'UnitId'=>array('name'=>'UnitId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'HeraldryUrl'=>array('name'=>'HeraldryUrl','type'=>'xsd:string'),
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
				'HeraldryMimeType'=>array('name'=>'HeraldryMimeType','type'=>'xsd:string')
			)
	);


$server->wsdl->addComplexType(
		'GetEventRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'GetEventResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'UnitId'=>array('name'=>'UnitId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'HeraldryUrl'=>array('name'=>'HeraldryUrl','type'=>'xsd:string'),
				'HasHeraldry'=>array('name'=>'HasHeraldry','type'=>'xsd:int')
			)
	);


$server->wsdl->addComplexType(
		'GetEventDetailRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'EventCalendarDetailId'=>array('name'=>'EventCalendarDetailId','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'GetEventDetailItemType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'EventCalendarDetailId'=>array('name'=>'EventCalendarDetailId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'Current'=>array('name'=>'Current','type'=>'xsd:int'),
				'Price'=>array('name'=>'Price','type'=>'xsd:double'),
				'EventStart'=>array('name'=>'EventStart','type'=>'xsd:dateTime'),
				'EventEnd'=>array('name'=>'EventEnd','type'=>'xsd:dateTime'),
				'Description'=>array('name'=>'Description','type'=>'xsd:string'),
				'Url'=>array('name'=>'Url','type'=>'xsd:string'),
				'UrlName'=>array('name'=>'UrlName','type'=>'xsd:string'),
				'Address'=>array('name'=>'Address','type'=>'xsd:string'),
				'Province'=>array('name'=>'Province','type'=>'xsd:string'),
				'PostalCode'=>array('name'=>'PostalCode','type'=>'xsd:string'),
				'City'=>array('name'=>'City','type'=>'xsd:string'),
				'Country'=>array('name'=>'Country','type'=>'xsd:string'),
				'MapURL'=>array('name'=>'MapURL','type'=>'xsd:string'),
				'MapUrlName'=>array('name'=>'MapUrlName','type'=>'xsd:string'),
				'Modified'=>array('name'=>'Modified','type'=>'xsd:dateTime')
			)
	);	
	

$server->wsdl->addComplexType(
		'EventCalendarDetailsList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:GetEventDetailItemType[]')
			),
		'tns:GetEventDetailItemType'
	);
	
$server->wsdl->addComplexType(
		'GetEventDetailResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'CalendarEventDetails'=>array('name'=>'CalendarEventDetails','type'=>'tns:EventCalendarDetailsList')
			)
	);
	

$server->wsdl->addComplexType(
		'CreateEventDetailsRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'Current'=>array('name'=>'Current','type'=>'xsd:int'),
				'Price'=>array('name'=>'Price','type'=>'xsd:double'),
				'EventStart'=>array('name'=>'EventStart','type'=>'xsd:dateTime'),
				'EventEnd'=>array('name'=>'EventEnd','type'=>'xsd:dateTime'),
				'Description'=>array('name'=>'Description','type'=>'xsd:string'),
				'Url'=>array('name'=>'Url','type'=>'xsd:string'),
				'UrlName'=>array('name'=>'UrlName','type'=>'xsd:string'),
				'Address'=>array('name'=>'Address','type'=>'xsd:string'),
				'Province'=>array('name'=>'Province','type'=>'xsd:string'),
				'PostalCode'=>array('name'=>'PostalCode','type'=>'xsd:string'),
				'City'=>array('name'=>'City','type'=>'xsd:string'),
				'Country'=>array('name'=>'Country','type'=>'xsd:string'),
				'MapURL'=>array('name'=>'MapURL','type'=>'xsd:string'),
				'MapUrlName'=>array('name'=>'MapUrlName','type'=>'xsd:string')
			)
	);
	

$server->wsdl->addComplexType(
		'SetCurrentRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'EventCalendarDetailId'=>array('name'=>'EventCalendarDetailId','type'=>'xsd:int'),
				'Current'=>array('name'=>'Current','type'=>'xsd:boolean')
			)
	);

$server->wsdl->addComplexType(
		'DeleteEventDetailRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'EventCalendarDetailId'=>array('name'=>'EventCalendarDetailId','type'=>'xsd:int')
			)
	);
	

$server->wsdl->addComplexType(
		'SetEventDetailsRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'EventCalendarDetailId'=>array('name'=>'EventCalendarDetailId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'Current'=>array('name'=>'Current','type'=>'xsd:int'),
				'Price'=>array('name'=>'Price','type'=>'xsd:double'),
				'EventStart'=>array('name'=>'EventStart','type'=>'xsd:dateTime'),
				'EventEnd'=>array('name'=>'EventEnd','type'=>'xsd:dateTime'),
				'Description'=>array('name'=>'Description','type'=>'xsd:string'),
				'Url'=>array('name'=>'Url','type'=>'xsd:string'),
				'UrlName'=>array('name'=>'UrlName','type'=>'xsd:string'),
				'Address'=>array('name'=>'Address','type'=>'xsd:string'),
				'Province'=>array('name'=>'Province','type'=>'xsd:string'),
				'PostalCode'=>array('name'=>'PostalCode','type'=>'xsd:string'),
				'City'=>array('name'=>'City','type'=>'xsd:string'),
				'Country'=>array('name'=>'Country','type'=>'xsd:string'),
				'MapURL'=>array('name'=>'MapURL','type'=>'xsd:string'),
				'MapUrlName'=>array('name'=>'MapUrlName','type'=>'xsd:string')
			)
	);
	

$server->wsdl->addComplexType(
		'SetEventRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'UnitId'=>array('name'=>'UnitId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'HeraldryUrl'=>array('name'=>'HeraldryUrl','type'=>'xsd:string'),
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
				'HeraldryMimeType'=>array('name'=>'HeraldryMimeType','type'=>'xsd:string')
			)
	);
?>