<?php

/******************

	Inventory Service Definitions

******************/


$server->wsdl->addComplexType(
		'GetActiveKingdomsSummaryRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ReportFromDate'=>array('name'=>'ReportFromDate','type'=>'xsd:date'),
				'KingdomAverageWeeks'=>array('name'=>'KingdomAverageWeeks','type'=>'xsd:int'),
				'ParkAttendanceWithinWeeks'=>array('name'=>'ParkAttendanceWithin','type'=>'xsd:int'),
				'KingdomAverageMonths'=>array('name'=>'KingdomAverageWeeks','type'=>'xsd:int'),
				'ParkAttendanceWithinMonths'=>array('name'=>'ParkAttendanceWithin','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ActiveKingdomsSummaryItemType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'ParkCount'=>array('name'=>'ParkCount','type'=>'xsd:int'),
				'Attendance'=>array('name'=>'Attendance','type'=>'xsd:float'),
				'Participation'=>array('name'=>'Participation','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ActiveKingdomsSummaryListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ActiveKingdomsSummaryItemType[]')
			),
		'tns:ActiveKingdomsSummaryItemType'
	);

$server->wsdl->addComplexType(
		'GetActiveKingdomsSummaryResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'ActiveKingdomsSummaryList'=>array('name'=>'ActiveKingdomsSummaryList','type'=>'tns:ActiveKingdomsSummaryListType')
			)
	);
	
$server->wsdl->addComplexType(
		'GetActivePlayersRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ReportFromDate'=>array('name'=>'ReportFromDate','type'=>'xsd:date'),
				'MinimumWeeklyAttendance'=>array('name'=>'MinimumWeeklyAttendance','type'=>'xsd:int'),
				'MinimumDailyAttendance'=>array('name'=>'MinimumDailyAttendance','type'=>'xsd:int'),
				'MonthlyCreditMaximum'=>array('name'=>'MonthlyCreditMaximum','type'=>'xsd:int'),
				'MinimumCredits'=>array('name'=>'MinimumCredits','type'=>'xsd:int'),
				'PerWeeks'=>array('name'=>'PerWeeks','type'=>'xsd:int'),
				'PerMonths'=>array('name'=>'PerMonths','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'DuesPaid'=>array('name'=>'WeeksAttended','type'=>'xsd:boolean'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:boolean'),
				'UnWaivered'=>array('name'=>'UnWaivered','type'=>'xsd:boolean'),
				'ByLocalPark'=>array('name'=>'ByLocalPark','type'=>'xsd:boolean'),
				'ByKingdom'=>array('name'=>'ByKingdom','type'=>'xsd:boolean'),
				'Peerage'=>array('name'=>'Peerage','type'=>'xsd:boolean')
			)
	);

$server->wsdl->addComplexType(
		'GetActivePlayersItemType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'ParkName'=>array('name'=>'ParkName','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Persona'=>array('name'=>'Persona','type'=>'xsd:string'),
				'TotalCredits'=>array('name'=>'TotalCredits','type'=>'xsd:int'),
				'WeeksAttended'=>array('name'=>'WeeksAttended','type'=>'xsd:int'),
				'DuesPaid'=>array('name'=>'DuesPaid','type'=>'xsd:boolean'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:boolean')
			)
	);

$server->wsdl->addComplexType(
		'GetActivePlayersListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:GetActivePlayersItemType[]')
			),
		'tns:GetActivePlayersItemType'
	);

$server->wsdl->addComplexType(
		'GetActivePlayersResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'ActivePlayerSummary'=>array('name'=>'ActivePlayerSummary','type'=>'tns:GetActivePlayersListType')
			)
	);

$server->wsdl->addComplexType(
		'GetKingdomParkMonthlyAveragesRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ParkMonthlySummaryItemType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'MonthlyCount'=>array('name'=>'MonthlyCount','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ParkMonthlySummaryListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ParkMonthlySummaryItemType[]')
			),
		'tns:ParkMonthlySummaryItemType'
	);

$server->wsdl->addComplexType(
		'GetKingdomParkMonthlyAveragesResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'KingdomParkMonthlySummary'=>array('name'=>'KingdomParkMonthlySummary','type'=>'tns:ParkMonthlySummaryListType')
			)
	);

$server->wsdl->addComplexType(
		'GetKingdomParkAveragesRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ReportFromDate'=>array('name'=>'ReportFromDate','type'=>'xsd:date'),
				'AverageWeeks'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'AverageMonths'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ParkAverageSummaryItemType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'ParkName'=>array('name'=>'ParkName','type'=>'xsd:string'),
				'AttendanceCount'=>array('name'=>'AttendanceCount','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'ParkAverageSummaryListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:ParkAverageSummaryItemType[]')
			),
		'tns:ParkAverageSummaryItemType'
	);

$server->wsdl->addComplexType(
		'GetKingdomParkAveragesResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'KingdomParkAveragesSummary'=>array('name'=>'KingdomParkAveragesSummary','type'=>'tns:ParkAverageSummaryListType')
			)
	);
	
$server->wsdl->addComplexType(
		'GetTopParksByAttendanceRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'StartDate'=>array('name'=>'StartDate','type'=>'xsd:date'),
				'EndDate'=>array('name'=>'EndDate','type'=>'xsd:date'),
				'Limit'=>array('name'=>'Limit','type'=>'xsd:int'),
				'NativePopulace'=>array('name'=>'NativePopulace','type'=>'xsd:boolean')
			)
	);

$server->wsdl->addComplexType(
		'TopParksSummaryItemType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'ParkName'=>array('name'=>'ParkName','type'=>'xsd:string'),
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'AttendanceCount'=>array('name'=>'AttendanceCount','type'=>'xsd:int')
			)
	);

$server->wsdl->addComplexType(
		'TopParksSummaryListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:TopParksSummaryItemType[]')
			),
		'tns:TopParksSummaryItemType'
	);

$server->wsdl->addComplexType(
		'GetTopParksByAttendanceResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'TopParksSummary'=>array('name'=>'TopParksSummary','type'=>'tns:TopParksSummaryListType')
			)
	);

$server->wsdl->addComplexType(
		'GetPlayerRosterRequest',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Token'=>array('name'=>'Token','type'=>'xsd:string'),
				'Type'=>array('name'=>'Type','type'=>'xsd:string'),
				'Id'=>array('name'=>'Id','type'=>'xsd:int'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:boolean'),
				'UnWaivered'=>array('name'=>'UnWaivered','type'=>'xsd:boolean'),
				'Active'=>array('name'=>'Active','type'=>'xsd:boolean'),
				'InActive'=>array('name'=>'InActive','type'=>'xsd:boolean'),
				'Banned'=>array('name'=>'Banned','type'=>'xsd:boolean'),
				'DuesPaid'=>array('name'=>'DuesPaid','type'=>'xsd:boolean'),
				'IncludeRetiredUnitMembers'=>array('name'=>'IncludeRetiredUnitMembers','type'=>'xsd:boolean'),
			)
	);

$server->wsdl->addComplexType(
		'GetPlayerRosterItemType',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'KingdomId'=>array('name'=>'KingdomId','type'=>'xsd:int'),
				'KingdomName'=>array('name'=>'KingdomName','type'=>'xsd:string'),
				'ParkId'=>array('name'=>'ParkId','type'=>'xsd:int'),
				'ParkName'=>array('name'=>'ParkName','type'=>'xsd:string'),
				'MundaneId'=>array('name'=>'MundaneId','type'=>'xsd:int'),
				'Persona'=>array('name'=>'Persona','type'=>'xsd:string'),
				'GivenName'=>array('name'=>'GivenName','type'=>'xsd:string'),
				'Surname'=>array('name'=>'Surname','type'=>'xsd:string'),
				'OtherName'=>array('name'=>'OtherName','type'=>'xsd:string'),
				'Restricted'=>array('name'=>'Restricted','type'=>'xsd:string'),
				'Waivered'=>array('name'=>'Waivered','type'=>'xsd:string'),
				'DuesPaid'=>array('name'=>'DuesPaid','type'=>'xsd:boolean'),
				'PenaltyBox'=>array('name'=>'PenaltyBox','type'=>'xsd:boolean'),
				'LastSignIn'=>array('name'=>'LastSignIn','type'=>'xsd:string'),
				'Displayable'=>array('name'=>'Displayable','type'=>'xsd:boolean')
			)
	);

$server->wsdl->addComplexType(
		'GetPlayerRosterListType',
		'complexType',
		'array',
		'',
		'SOAP-ENC:Array',
		array(),
		array(
			array('ref'=>'SOAP-ENC:arrayType', 'wsdl:arrayType'=> 'tns:GetPlayerRosterItemType[]')
			),
		'tns:GetPlayerRosterItemType'
	);

$server->wsdl->addComplexType(
		'GetPlayerRosterResponse',
		'complextType',
		'struct',
		'all',
		'',
		array(
				'Status'=>array('name'=>'Status','type'=>'tns:StatusType'),
				'Roster'=>array('name'=>'ActivePlayerSummary','type'=>'tns:GetPlayerRosterListType')
			)
	);
	
?>