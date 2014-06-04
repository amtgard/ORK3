<?php

/******************

	Inventory Service Definitions

******************/


$server->wsdl->addComplexType(
		'VendorComponentType',
		'complextType',
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

?>