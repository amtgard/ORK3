<?php

/******************

	Inventory Service Definitions

******************/

$server->wsdl->addComplexType(
		'GetAwardListRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'IsLadder'=>array('name'=>'IsLadder','type'=>'xsd:string'),
				'IsTitle'=>array('name'=>'IsTitle','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'AwardsListItemType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'KingdomAwardName'=>array('name'=>'KingdomAwardName','type'=>'xsd:string'),
				'ReignLimit'=>array('name'=>'ReignLimit','type'=>'xsd:int'),
				'MonthLimit'=>array('name'=>'MonthLimit','type'=>'xsd:int'),
				'AwardName'=>array('name'=>'AwardName','type'=>'xsd:string'),
				'AwardId'=>array('name'=>'AwardId','type'=>'xsd:int'),
				'IsLadder'=>array('name'=>'IsLadder','type'=>'xsd:int'),
				'IsTitle'=>array('name'=>'IsTitle','type'=>'xsd:int'),
				'TitleClass'=>array('name'=>'TitleClass','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'AwardsList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:AwardsListItemType[]')
			),
		'tns:AwardsListItemType'
	);

$server->wsdl->addComplexType(
		'GetAwardListResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Awards'=>array('name'=>'Awards','type'=>'tns:AwardsList')
			)
	);

	

$server->wsdl->addComplexType(
		'CreateAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'AwardId'=>array('name'=>'EquivalenceId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'ReignLimit'=>array('name'=>'ReignLimit','type'=>'xsd:int'),
				'MonthLimit'=>array('name'=>'MonthLimit','type'=>'xsd:int'),
				'IsLadder'=>array('name'=>'IsLadder','type'=>'xsd:string'),
				'IsTitle'=>array('name'=>'IsTitle','type'=>'xsd:string'),
				'TitleClass'=>array('name'=>'TitleClass','type'=>'xsd:int'),
				'Peerage'=>array('name'=>'Peerage','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'EditAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'AwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'ReignLimit'=>array('name'=>'ReignLimit','type'=>'xsd:int'),
				'MonthLimit'=>array('name'=>'MonthLimit','type'=>'xsd:int'),
				'IsLadder'=>array('name'=>'IsLadder','type'=>'xsd:string'),
				'IsTitle'=>array('name'=>'IsTitle','type'=>'xsd:string'),
				'TitleClass'=>array('name'=>'TitleClass','type'=>'xsd:int'),
				'Peerage'=>array('name'=>'Peerage','type'=>'xsd:string')
			)
	);

	
$server->wsdl->addComplexType(
		'RemoveAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'AwardId'=>array('name'=>'AwardId','type'=>'xsd:int')
			)
	);
?>