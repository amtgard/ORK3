<?php

/******************

	Inventory Service Definitions

******************/


$server->wsdl->addComplexType(
		'NextRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Date'=>array('name'=>'Date','type'=>'xsd:dateTime')
			)
	);

	
$server->wsdl->addComplexType(
		'DateItemType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'DateStart'=>array('name'=>'DateStart','type'=>'xsd:dateTime'),
				'DateEnd'=>array('name'=>'DateEnd','type'=>'xsd:dateTime'),
				'Time'=>array('name'=>'Time','type'=>'xsd:time'),
				'Title'=>array('name'=>'Title','type'=>'xsd:string'),
				'Url'=>array('name'=>'Url','type'=>'xsd:string'),
				'Description'=>array('name'=>'Description','type'=>'xsd:string')
			)
	);

$server->wsdl->addComplexType(
		'DatesList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:DateItemType[]')
			),
		'tns:DateItemType'
	);	

$server->wsdl->addComplexType(
		'NextResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Dates'=>array('name'=>'Dates','type'=>'tns:DatesList')
			)
	);
?>