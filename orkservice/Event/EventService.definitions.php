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
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'UnitId' => array('name' => 'UnitId','type' => 'xsd:int'),
                'Name' => array('name' => 'Name','type' => 'xsd:string'),
                'Status' => array('name' => 'Status','type' => 'xsd:string'),
                'HeraldryUrl' => array('name' => 'HeraldryUrl','type' => 'xsd:string'),
                'Heraldry' => array('name' => 'Heraldry','type' => 'xsd:string'),
                'HeraldryMimeType' => array('name' => 'HeraldryMimeType','type' => 'xsd:string')
            )
);


$server->wsdl->addComplexType(
    'GetEventRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventId' => array('name' => 'EventId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetEventResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'UnitId' => array('name' => 'UnitId','type' => 'xsd:int'),
                'Name' => array('name' => 'Name','type' => 'xsd:string'),
                'HeraldryUrl' => array('name' => 'HeraldryUrl','type' => 'xsd:string'),
                'HasHeraldry' => array('name' => 'HasHeraldry','type' => 'xsd:int')
            )
);


$server->wsdl->addComplexType(
    'GetEventDetailRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetEventDetailItemType',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'Current' => array('name' => 'Current','type' => 'xsd:int'),
                'Price' => array('name' => 'Price','type' => 'xsd:double'),
                'EventStart' => array('name' => 'EventStart','type' => 'xsd:dateTime'),
                'EventEnd' => array('name' => 'EventEnd','type' => 'xsd:dateTime'),
                'Description' => array('name' => 'Description','type' => 'xsd:string'),
                'Url' => array('name' => 'Url','type' => 'xsd:string'),
                'UrlName' => array('name' => 'UrlName','type' => 'xsd:string'),
                'Address' => array('name' => 'Address','type' => 'xsd:string'),
                'Province' => array('name' => 'Province','type' => 'xsd:string'),
                'PostalCode' => array('name' => 'PostalCode','type' => 'xsd:string'),
                'City' => array('name' => 'City','type' => 'xsd:string'),
                'Country' => array('name' => 'Country','type' => 'xsd:string'),
                'MapURL' => array('name' => 'MapURL','type' => 'xsd:string'),
                'MapUrlName' => array('name' => 'MapUrlName','type' => 'xsd:string'),
                'Modified' => array('name' => 'Modified','type' => 'xsd:dateTime')
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
            array('ref' => 'SOAP-ENC:arrayType', 'wsdl:arrayType' => 'tns:GetEventDetailItemType[]')
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
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'CalendarEventDetails' => array('name' => 'CalendarEventDetails','type' => 'tns:EventCalendarDetailsList')
            )
);


$server->wsdl->addComplexType(
    'CreateEventDetailsRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'AtParkId' => array('name' => 'AtParkId','type' => 'xsd:int'),
                'Current' => array('name' => 'Current','type' => 'xsd:int'),
                'Price' => array('name' => 'Price','type' => 'xsd:double'),
                'EventStart' => array('name' => 'EventStart','type' => 'xsd:dateTime'),
                'EventEnd' => array('name' => 'EventEnd','type' => 'xsd:dateTime'),
                'Description' => array('name' => 'Description','type' => 'xsd:string'),
                'Url' => array('name' => 'Url','type' => 'xsd:string'),
                'UrlName' => array('name' => 'UrlName','type' => 'xsd:string'),
                'Address' => array('name' => 'Address','type' => 'xsd:string'),
                'Province' => array('name' => 'Province','type' => 'xsd:string'),
                'PostalCode' => array('name' => 'PostalCode','type' => 'xsd:string'),
                'City' => array('name' => 'City','type' => 'xsd:string'),
                'Country' => array('name' => 'Country','type' => 'xsd:string'),
                'MapURL' => array('name' => 'MapURL','type' => 'xsd:string'),
                'MapUrlName' => array('name' => 'MapUrlName','type' => 'xsd:string')
            )
);


$server->wsdl->addComplexType(
    'SetCurrentRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'Current' => array('name' => 'Current','type' => 'xsd:boolean')
            )
);

$server->wsdl->addComplexType(
    'DeleteEventDetailRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int')
            )
);


$server->wsdl->addComplexType(
    'SetEventDetailsRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'Current' => array('name' => 'Current','type' => 'xsd:int'),
                'Price' => array('name' => 'Price','type' => 'xsd:double'),
                'EventStart' => array('name' => 'EventStart','type' => 'xsd:dateTime'),
                'EventEnd' => array('name' => 'EventEnd','type' => 'xsd:dateTime'),
                'Description' => array('name' => 'Description','type' => 'xsd:string'),
                'Url' => array('name' => 'Url','type' => 'xsd:string'),
                'UrlName' => array('name' => 'UrlName','type' => 'xsd:string'),
                'Address' => array('name' => 'Address','type' => 'xsd:string'),
                'Province' => array('name' => 'Province','type' => 'xsd:string'),
                'PostalCode' => array('name' => 'PostalCode','type' => 'xsd:string'),
                'City' => array('name' => 'City','type' => 'xsd:string'),
                'Country' => array('name' => 'Country','type' => 'xsd:string'),
                'MapURL' => array('name' => 'MapURL','type' => 'xsd:string'),
                'MapUrlName' => array('name' => 'MapUrlName','type' => 'xsd:string')
            )
);


$server->wsdl->addComplexType(
    'DeleteEventRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token'   => array('name' => 'Token',   'type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId', 'type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'SetEventRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:string'),
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'UnitId' => array('name' => 'UnitId','type' => 'xsd:int'),
                'Name' => array('name' => 'Name','type' => 'xsd:string'),
                'HeraldryUrl' => array('name' => 'HeraldryUrl','type' => 'xsd:string'),
                'Heraldry' => array('name' => 'Heraldry','type' => 'xsd:string'),
                'HeraldryMimeType' => array('name' => 'HeraldryMimeType','type' => 'xsd:string')
            )
);

$server->wsdl->addComplexType(
    'GetRsvpStatusRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetRsvpStatusResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'RsvpStatus' => array('name' => 'RsvpStatus','type' => 'xsd:string')
            )
);

$server->wsdl->addComplexType(
    'SetRsvpRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'Status' => array('name' => 'Status','type' => 'xsd:string'),
                'AllowToggleOff' => array('name' => 'AllowToggleOff','type' => 'xsd:boolean'),
                'CoerceInvalidStatus' => array('name' => 'CoerceInvalidStatus','type' => 'xsd:boolean'),
                'EndDateGate' => array('name' => 'EndDateGate','type' => 'xsd:string')
            )
);

$server->wsdl->addComplexType(
    'SetRsvpResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'MyStatus' => array('name' => 'MyStatus','type' => 'xsd:string'),
                'ToggledOff' => array('name' => 'ToggledOff','type' => 'xsd:boolean'),
                'Going' => array('name' => 'Going','type' => 'xsd:int'),
                'Interested' => array('name' => 'Interested','type' => 'xsd:int'),
                'Total' => array('name' => 'Total','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'WithdrawRsvpRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'WithdrawRsvpResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'MyStatus' => array('name' => 'MyStatus','type' => 'xsd:string'),
                'Going' => array('name' => 'Going','type' => 'xsd:int'),
                'Interested' => array('name' => 'Interested','type' => 'xsd:int'),
                'Total' => array('name' => 'Total','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'RemoveRsvpRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'TargetMundaneId' => array('name' => 'TargetMundaneId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetRsvpCountsRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetRsvpCountsResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'Going' => array('name' => 'Going','type' => 'xsd:int'),
                'Interested' => array('name' => 'Interested','type' => 'xsd:int'),
                'Total' => array('name' => 'Total','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'IntList',
    'complexType',
    'array',
    '',
    'SOAP-ENC:Array',
    array(),
    array(
            array('ref' => 'SOAP-ENC:arrayType', 'wsdl:arrayType' => 'xsd:int[]')
            ),
    'xsd:int'
);

$server->wsdl->addComplexType(
    'GetRsvpBatchRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailIds' => array('name' => 'EventCalendarDetailIds','type' => 'tns:IntList'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'RsvpCountItemType',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'Going' => array('name' => 'Going','type' => 'xsd:int'),
                'Interested' => array('name' => 'Interested','type' => 'xsd:int'),
                'Total' => array('name' => 'Total','type' => 'xsd:int'),
                'RsvpStatus' => array('name' => 'RsvpStatus','type' => 'xsd:string')
            )
);

$server->wsdl->addComplexType(
    'RsvpCountItemList',
    'complexType',
    'array',
    '',
    'SOAP-ENC:Array',
    array(),
    array(
            array('ref' => 'SOAP-ENC:arrayType', 'wsdl:arrayType' => 'tns:RsvpCountItemType[]')
            ),
    'tns:RsvpCountItemType'
);

$server->wsdl->addComplexType(
    'GetRsvpBatchResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'Items' => array('name' => 'Items','type' => 'tns:RsvpCountItemList')
            )
);

$server->wsdl->addComplexType(
    'RsvpPlayerItemType',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'Persona' => array('name' => 'Persona','type' => 'xsd:string'),
                'Status' => array('name' => 'Status','type' => 'xsd:string'),
                'Waivered' => array('name' => 'Waivered','type' => 'xsd:boolean'),
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'KingdomAbbr' => array('name' => 'KingdomAbbr','type' => 'xsd:string'),
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
                'ParkAbbr' => array('name' => 'ParkAbbr','type' => 'xsd:string'),
                'LastClassId' => array('name' => 'LastClassId','type' => 'xsd:int'),
                'LastClassName' => array('name' => 'LastClassName','type' => 'xsd:string')
            )
);

$server->wsdl->addComplexType(
    'RsvpPlayerList',
    'complexType',
    'array',
    '',
    'SOAP-ENC:Array',
    array(),
    array(
            array('ref' => 'SOAP-ENC:arrayType', 'wsdl:arrayType' => 'tns:RsvpPlayerItemType[]')
            ),
    'tns:RsvpPlayerItemType'
);

$server->wsdl->addComplexType(
    'GetRsvpListResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'RsvpPlayers' => array('name' => 'RsvpPlayers','type' => 'tns:RsvpPlayerList')
            )
);

$server->wsdl->addComplexType(
    'UpcomingRsvpItemType',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventName' => array('name' => 'EventName','type' => 'xsd:string'),
                'EventStart' => array('name' => 'EventStart','type' => 'xsd:dateTime'),
                'EventEnd' => array('name' => 'EventEnd','type' => 'xsd:dateTime')
            )
);

$server->wsdl->addComplexType(
    'UpcomingRsvpList',
    'complexType',
    'array',
    '',
    'SOAP-ENC:Array',
    array(),
    array(
            array('ref' => 'SOAP-ENC:arrayType', 'wsdl:arrayType' => 'tns:UpcomingRsvpItemType[]')
            ),
    'tns:UpcomingRsvpItemType'
);

$server->wsdl->addComplexType(
    'GetUpcomingRsvpsRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetUpcomingRsvpsResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'UpcomingRsvps' => array('name' => 'UpcomingRsvps','type' => 'tns:UpcomingRsvpList')
            )
);

$server->wsdl->addComplexType(
    'KingdomEventItemType',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventName' => array('name' => 'EventName','type' => 'xsd:string'),
                'EventStart' => array('name' => 'EventStart','type' => 'xsd:dateTime'),
                'EventEnd' => array('name' => 'EventEnd','type' => 'xsd:dateTime'),
                'ParkAbbreviation' => array('name' => 'ParkAbbreviation','type' => 'xsd:string')
            )
);

$server->wsdl->addComplexType(
    'KingdomEventList',
    'complexType',
    'array',
    '',
    'SOAP-ENC:Array',
    array(),
    array(
            array('ref' => 'SOAP-ENC:arrayType', 'wsdl:arrayType' => 'tns:KingdomEventItemType[]')
            ),
    'tns:KingdomEventItemType'
);

$server->wsdl->addComplexType(
    'GetKingdomEventsWithoutRsvpRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'Limit' => array('name' => 'Limit','type' => 'xsd:int')
            )
);

$server->wsdl->addComplexType(
    'GetKingdomEventsWithoutRsvpResponse',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Status' => array('name' => 'Status','type' => 'tns:StatusType'),
                'KingdomEvents' => array('name' => 'KingdomEvents','type' => 'tns:KingdomEventList')
            )
);

$server->wsdl->addComplexType(
    'SetEventStatusRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'Status' => array('name' => 'Status','type' => 'xsd:string'),
            )
);

$server->wsdl->addComplexType(
    'GetEventPreviewRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'EventStaffRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'StaffId' => array('name' => 'StaffId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'RoleName' => array('name' => 'RoleName','type' => 'xsd:string'),
                'CanManage' => array('name' => 'CanManage','type' => 'xsd:int'),
                'CanAttendance' => array('name' => 'CanAttendance','type' => 'xsd:int'),
                'CanSchedule' => array('name' => 'CanSchedule','type' => 'xsd:int'),
                'CanFeast' => array('name' => 'CanFeast','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'EventScheduleRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'ScheduleId' => array('name' => 'ScheduleId','type' => 'xsd:int'),
                'Title' => array('name' => 'Title','type' => 'xsd:string'),
                'StartTime' => array('name' => 'StartTime','type' => 'xsd:string'),
                'EndTime' => array('name' => 'EndTime','type' => 'xsd:string'),
                'Location' => array('name' => 'Location','type' => 'xsd:string'),
                'Description' => array('name' => 'Description','type' => 'xsd:string'),
                'Category' => array('name' => 'Category','type' => 'xsd:string'),
                'SecondaryCategory' => array('name' => 'SecondaryCategory','type' => 'xsd:string'),
                'Menu' => array('name' => 'Menu','type' => 'xsd:string'),
                'Cost' => array('name' => 'Cost','type' => 'xsd:string'),
                'Dietary' => array('name' => 'Dietary','type' => 'xsd:string'),
                'Allergens' => array('name' => 'Allergens','type' => 'xsd:string'),
                'Leads' => array('name' => 'Leads','type' => 'xsd:string'),
            )
);

$server->wsdl->addComplexType(
    'ListCopySourceEventsRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
                'Query' => array('name' => 'Query','type' => 'xsd:string'),
                'ExcludeEventId' => array('name' => 'ExcludeEventId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'CreateEventWithCopyRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'Name' => array('name' => 'Name','type' => 'xsd:string'),
                'KingdomId' => array('name' => 'KingdomId','type' => 'xsd:int'),
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
                'SourceEventId' => array('name' => 'SourceEventId','type' => 'xsd:int'),
                'NewStart' => array('name' => 'NewStart','type' => 'xsd:string'),
                'NewEnd' => array('name' => 'NewEnd','type' => 'xsd:string'),
                'Modules' => array('name' => 'Modules','type' => 'xsd:string'),
                'Status' => array('name' => 'Status','type' => 'xsd:string'),
            )
);

$server->wsdl->addComplexType(
    'RemoveEventHeraldryRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'OccurrenceScopeRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'OccurrenceDetailRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'OccurrencePageDataRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'AtParkId' => array('name' => 'AtParkId','type' => 'xsd:int'),
                'FallbackParkId' => array('name' => 'FallbackParkId','type' => 'xsd:int'),
                'IncludeDietary' => array('name' => 'IncludeDietary','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'CalendarDetailFeesLinksRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'Fees' => array('name' => 'Fees','type' => 'xsd:string'),
                'Links' => array('name' => 'Links','type' => 'xsd:string'),
            )
);

$server->wsdl->addComplexType(
    'CalendarDetailEventTypeRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
                'EventType' => array('name' => 'EventType','type' => 'xsd:string'),
            )
);

$server->wsdl->addComplexType(
    'ReconcilePastAttendanceRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'Token' => array('name' => 'Token','type' => 'xsd:string'),
                'EventId' => array('name' => 'EventId','type' => 'xsd:int'),
                'EventCalendarDetailId' => array('name' => 'EventCalendarDetailId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'GetParkNameRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'ParkId' => array('name' => 'ParkId','type' => 'xsd:int'),
            )
);

$server->wsdl->addComplexType(
    'DraftBlockedRequest',
    'complexType',
    'struct',
    'all',
    '',
    array(
                'EventStatus' => array('name' => 'EventStatus','type' => 'xsd:string'),
                'CreatorId' => array('name' => 'CreatorId','type' => 'xsd:int'),
                'MundaneId' => array('name' => 'MundaneId','type' => 'xsd:int'),
                'CanManageEvent' => array('name' => 'CanManageEvent','type' => 'xsd:int'),
                'StaffCaps' => array('name' => 'StaffCaps','type' => 'xsd:string'),
            )
);
