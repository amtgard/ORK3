<?php

/******************

	Inventory Service Definitions

******************/


/// SetPlayerReconciledCredits()

$server->wsdl->addComplexType(
		'SetPlayerReconciledCreditsRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Reconcile'=>array('name'=>'Reconcile','type'=>'tns:ReconcileList')
			)
	);

$server->wsdl->addComplexType(
		'ReconcileElementType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'Quantity'=>array('name'=>'Quantity','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'ReconcileList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ReconcileElementType[]')
			),
		'tns:ReconcileElementType'
	);

/// GetPlayer()

$server->wsdl->addComplexType(
		'GetPlayerRequest',
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
		'PlayerType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'GivenName'=>array('name'=>'GivenName','type'=>'xsd:string'),
				'Surname'=>array('name'=>'Surname','type'=>'xsd:string'),
				'OtherName'=>array('name'=>'OtherName','type'=>'xsd:string'),
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Persona'=>array('name'=>'Persona','type'=>'xsd:string'),
				'Email'=>array('name'=>'Email','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'Restricted'=>array('name'=>'Restricted','type'=>'xsd:int'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:int'),
				'Waiver'=>array('name'=>'Waiver','type'=>'xsd:string'),
				'WaiverExt'=>array('name'=>'WaiverExt','type'=>'xsd:string'),
				'HasHeraldry'=>array('name'=>'HasHeraldry','type'=>'xsd:int'),
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
				'HasImage'=>array('name'=>'HasImage','type'=>'xsd:int'),
				'Image'=>array('name'=>'Image','type'=>'xsd:string'),
				'CompanyId'=>array('name'=>'CompanyId','type'=>'xsd:int'),
				'PenaltyBox'=>array('name'=>'PenaltyBox','type'=>'xsd:int'),
				'Active'=>array('name'=>'Active','type'=>'xsd:int'),
				'Company'=>array('name'=>'Company','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'GetPlayerResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Player'=>array('name'=>'Player','type'=>'tns:PlayerType')
			)
	);
	
/// AttendanceForPlayer()

$server->wsdl->addComplexType(
		'AttendanceForPlayerRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int')
			)
	);


$server->wsdl->addComplexType(
		'AttendanceElementType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'AttendanceId'=>array('name'=>'AttendanceId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'Date'=>array('name'=>'Date','type'=>'xsd:dateTime'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'EventCalendarDetailId'=>array('name'=>'EventCalendarDetailId','type'=>'xsd:int'),
				'EventParkId'=>array('name'=>'EventParkId','type'=>'xsd:int'),
				'EventKingdomId'=>array('name'=>'EventKingdomId','type'=>'xsd:int'),
				'EventParkName'=>array('name'=>'EventParkName','type'=>'xsd:string'),
				'EventKingdomName'=>array('name'=>'EventKingdomName','type'=>'xsd:string'),
				'Credits'=>array('name'=>'Credits','type'=>'xsd:int'),
				'ClassName'=>array('name'=>'ClassName','type'=>'xsd:string'),
				'ParkName'=>array('name'=>'ParkName','type'=>'xsd:string'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'EventName'=>array('name'=>'EventName','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'AttendanceList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:AttendanceElementType[]')
			),
		'tns:AttendanceElementType'
	);
	
$server->wsdl->addComplexType(
		'AttendanceForPlayerResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Attendance'=>array('name'=>'Player','type'=>'tns:AttendanceList')
			)
	);
	
	
/// AwardsForPlayer()

$server->wsdl->addComplexType(
		'AwardsForPlayerRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int')
			)
	);


$server->wsdl->addComplexType(
		'AwardElementType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'AwardsId'=>array('name'=>'AwardsId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'AwardId'=>array('name'=>'AwardId','type'=>'xsd:int'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Rank'=>array('name'=>'Rank','type'=>'xsd:int'),
				'Date'=>array('name'=>'Date','type'=>'xsd:dateTime'),
				'GivenById'=>array('name'=>'GivenById','type'=>'xsd:int'),
				'Note'=>array('name'=>'Note','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'Name'=>array('name'=>'Name','type'=>'xsd:string'),
				'KingdomAwardName'=>array('name'=>'KingdomAwardName','type'=>'xsd:string'),
				'CustomAwardName'=>array('name'=>'CustomName','type'=>'xsd:string'),
				'IsLadder'=>array('name'=>'IsLadder','type'=>'xsd:int'),
				'IsTitle'=>array('name'=>'IsTitle','type'=>'xsd:int'),
				'TitleClass'=>array('name'=>'TitleClass','type'=>'xsd:int'),
				'ParkName'=>array('name'=>'ParkName','type'=>'xsd:string'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'EventName'=>array('name'=>'EventName','type'=>'xsd:string'),
				'GivenBy'=>array('name'=>'GivenBy','type'=>'xsd:string')
			)
	);
	
$server->wsdl->addComplexType(
		'AwardList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:AwardElementType[]')
			),
		'tns:AwardElementType'
	);
	
$server->wsdl->addComplexType(
		'AwardsForPlayerResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Awards'=>array('name'=>'Player','type'=>'tns:AwardList')
			)
	);

/// GetPlayerClasses()

$server->wsdl->addComplexType(
		'GetPlayerClassesRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int')
			)
	);


$server->wsdl->addComplexType(
		'PlayerClassElementType',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'ClassReconciliationId'=>array('name'=>'ClassReconciliationId','type'=>'xsd:int'),
				'Reconciled'=>array('name'=>'Reconciled','type'=>'xsd:int'),
				'ClassId'=>array('name'=>'ClassId','type'=>'xsd:int'),
				'ClassName'=>array('name'=>'ClassName','type'=>'xsd:string'),
				'Weeks'=>array('name'=>'Weeks','type'=>'xsd:int'),
				'Attendances'=>array('name'=>'Attendances','type'=>'xsd:int'),
				'Credits'=>array('name'=>'Credits','type'=>'xsd:int')
			)
	);
	
$server->wsdl->addComplexType(
		'PlayerClassList',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:PlayerClassElementType[]')
			),
		'tns:PlayerClassElementType'
	);
	
$server->wsdl->addComplexType(
		'GetPlayerClassesResponse',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Classes'=>array('name'=>'Classes','type'=>'tns:PlayerClassList')
			)
	);
	
/// CreatePlayer()

$server->wsdl->addComplexType(
		'CreatePlayerRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'GivenName'=>array('name'=>'GivenName','type'=>'xsd:string'),
				'Surname'=>array('name'=>'Surname','type'=>'xsd:string'),
				'OtherName'=>array('name'=>'OtherName','type'=>'xsd:string'),
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Password'=>array('name'=>'Password','type'=>'xsd:string'),
				'Persona'=>array('name'=>'Persona','type'=>'xsd:string'),
				'Email'=>array('name'=>'Email','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'Restricted'=>array('name'=>'Restricted','type'=>'xsd:int'),
				'IsActive'=>array('name'=>'IsActive','type'=>'xsd:int'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:int'),
				'Waiver'=>array('name'=>'Waiver','type'=>'xsd:string'),
				'WaiverExt'=>array('name'=>'WaiverExt','type'=>'xsd:string'),
				'HasHeraldry'=>array('name'=>'HasHeraldry','type'=>'xsd:int'),
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
				'HasImage'=>array('name'=>'HasImage','type'=>'xsd:int'),
				'Image'=>array('name'=>'Image','type'=>'xsd:string')
			)
	);
	
/// MergePlayer()

$server->wsdl->addComplexType(
		'MergePlayerRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'FromMundaneId'=>array('name'=>'FromMundaneId','type'=>'xsd:int'),
				'ToMundaneId'=>array('name'=>'ToMundaneId','type'=>'xsd:int')
			)
	);
	
	
/// MergePlayer()

$server->wsdl->addComplexType(
		'MovePlayerRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:int')
			)
	);
	
/// UpdatePlayer()

$server->wsdl->addComplexType(
		'UpdatePlayerRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'GivenName'=>array('name'=>'GivenName','type'=>'xsd:string'),
				'Surname'=>array('name'=>'Surname','type'=>'xsd:string'),
				'OtherName'=>array('name'=>'OtherName','type'=>'xsd:string'),
				'UserName'=>array('name'=>'UserName','type'=>'xsd:string'),
				'Password'=>array('name'=>'Password','type'=>'xsd:string'),
				'Persona'=>array('name'=>'Persona','type'=>'xsd:string'),
				'Email'=>array('name'=>'Email','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'Restricted'=>array('name'=>'Restricted','type'=>'xsd:int'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:int'),
				'Waiver'=>array('name'=>'Waiver','type'=>'xsd:string'),
				'WaiverExt'=>array('name'=>'WaiverExt','type'=>'xsd:string'),
				'HasHeraldry'=>array('name'=>'HasHeraldry','type'=>'xsd:int'),
				'Heraldry'=>array('name'=>'Heraldry','type'=>'xsd:string'),
				'HasImage'=>array('name'=>'HasImage','type'=>'xsd:int'),
				'Image'=>array('name'=>'Image','type'=>'xsd:string')
			)
	);
	
	
/// SetRestriction()

$server->wsdl->addComplexType(
		'SetRestrictionRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Restricted'=>array('name'=>'Restricted','type'=>'xsd:int')
			)
	);

	
/// SetWaiver()

$server->wsdl->addComplexType(
		'SetWaiverRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:int'),
				'Waiver'=>array('name'=>'Waiver','type'=>'xsd:string')
			)
	);
	
	
/// SetBan()

$server->wsdl->addComplexType(
		'SetBanRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Banned'=>array('name'=>'Banned','type'=>'xsd:int')
			)
	);
	
	
/// AddAward()

$server->wsdl->addComplexType(
		'AddAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'RecipientId'=>array('name'=>'RecipientId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'CustomName'=>array('name'=>'CustomName','type'=>'xsd:string'),
				'Rank'=>array('name'=>'Rank','type'=>'xsd:int'),
				'Date'=>array('name'=>'Date','type'=>'xsd:dateTime'),
				'GivenById'=>array('name'=>'GivenById','type'=>'xsd:int'),
				'Note'=>array('name'=>'Note','type'=>'xsd:string')
			)
	);
	
/// UpdateAward()

$server->wsdl->addComplexType(
		'UpdateAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'AwardsId'=>array('name'=>'AwardsId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'Rank'=>array('name'=>'Rank','type'=>'xsd:int'),
				'Date'=>array('name'=>'Date','type'=>'xsd:dateTime'),
				'GivenById'=>array('name'=>'GivenById','type'=>'xsd:int'),
				'Note'=>array('name'=>'Note','type'=>'xsd:string')
			)
	);
	
	
/// RemoveAward()

$server->wsdl->addComplexType(
		'RemoveAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'AwardsId'=>array('name'=>'AwardsId','type'=>'xsd:int')
			)
	);

/// ReconcileAward()

$server->wsdl->addComplexType(
		'ReconcileAwardRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'AwardsId'=>array('name'=>'AwardsId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int'),
				'CustomName'=>array('name'=>'CustomName','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'EventId'=>array('name'=>'EventId','type'=>'xsd:int'),
				'Rank'=>array('name'=>'Rank','type'=>'xsd:int'),
				'Date'=>array('name'=>'Date','type'=>'xsd:dateTime'),
				'GivenById'=>array('name'=>'GivenById','type'=>'xsd:int'),
				'Note'=>array('name'=>'Note','type'=>'xsd:string')
			)
	);

/// AutoAssignRanks()

$server->wsdl->addComplexType(
		'AutoAssignRanksRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'KingdomAwardId'=>array('name'=>'KingdomAwardId','type'=>'xsd:int')
			)
	);

/// ResetWaivers()

$server->wsdl->addComplexType(
		'ResetWaiversRequest',
		'complexType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int')
			)
	);
?>