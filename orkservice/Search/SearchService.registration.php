<?php


$server->Register(
		array(
				'Search/Unit',
				array('SearchService', 'Unit'),
				array(
						array( 'name','request',false,'string',true ),
						array( 'limit','request',true,'int',true )
					)
			)
	);
	
$server->Register(
		array(
				'Search/PlayerAward',
				array('SearchService', 'PlayerAward'),
				array(
						array( 'awards_id','request',false,'int',true )
					)
			)
	);
	
$server->Register(
		array(
				'Search/Location',
				array('SearchService', 'Location'),
				array(
						array( 'name','request',false,'string',true ),
						array( 'date','request',false,'string',true )
					)
			)
	);
	
$server->Register(
		array(
				'Search/Event',
				array('SearchService', 'Event'),
				array(
						array( 'name','request',true,'string',true ),
						array( 'kingdom_id','request',true,'int',true ),
						array( 'park_id','request',true,'int',true ),
						array( 'mundane_id','request',true,'int',true ),
						array( 'unit_id','request',true,'int',true ),
						array( 'limit','request',true,'int',true ),
						array( 'event','request',true,'int',true ),
						array( 'date_order','request',true,'int',true ),
						array( 'date_start','request',true,'string',true )
					)
			)
	);
	
$server->Register(
		array(
				'Search/CalendarDetail',
				array('SearchService', 'CalendarDetail'),
				array(
						array( 'event_calendardetail_id','request',false,'int',true )
					)
			)
	);
	

$server->Register(
		array(
				'Search/Unit',
				array('SearchService', 'Unit'),
				array(
						array( 'name','request',false,'string',true ),
						array( 'limit','request',true,'int',true )
					)
			)
	);
	
$server->Register(
		array(
				'Search/Player',
				array('SearchService', 'Player'),
				array(
						array( 'type','request',false,'string',true ),
						array( 'search','request',false,'string',true ),
						array( 'limit','request',true,'int',true ),
						array( 'kingdom_id','request',true,'int',true ),
						array( 'park_id','request',true,'int',true ),
						array( 'waivered','request',true,'int',true )
					)
			)
	);

$server->Register(
		array(
				'Search/Park',
				array('SearchService', 'Park'),
				array(
						array( 'name','request',false,'string',true ),
						array( 'kingdom_id','request',true,'int',true ),
						array( 'limit','request',true,'int',true ),
					)
			)
	);
	
$server->Register(
		array(
				'Search/Kingdom',
				array('SearchService', 'Kingdom'),
				array(
						array( 'name','request',false,'string',true ),
						array( 'limit','request',true,'int',true ),
					)
			)
	);
	
?>