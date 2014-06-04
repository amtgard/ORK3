<?php

/******************

	Inventory Service Definitions

******************/


$server->wsdl->addComplexType(
		'GetHeraldryRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Id'=>array('name'=>'Id','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'GetHeraldryResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
			)
	);
	
$server->wsdl->addComplexType(
		'GetHeraldryUrlRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Id'=>array('name'=>'Id','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'GetHeraldryUrlResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Url'=>array('name'=>'HeraldryUrl','type'=>'xsd:string'),
			)
	);
	
	
$server->wsdl->addComplexType(
		'SetHeraldryRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
				'HeraldryUrl'=>array('name'=>'HeraldryUrl','type'=>'xsd:string'),
				'HeraldryMimeType'=>array('name'=>'HeraldryMimeType','type'=>'xsd:string'),
				'Id'=>array('name'=>'Id','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'Id','type'=>'xsd:int')
			)
	);
?>