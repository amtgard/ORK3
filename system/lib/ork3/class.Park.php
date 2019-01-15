<?php

class Park extends Ork3
{

	public function __construct()
	{
		parent::__construct();
		$this->park = new yapo( $this->db, DB_PREFIX . 'park' );
		$this->parkday = new yapo( $this->db, DB_PREFIX . 'parkday' );
	}

	public function MergeParks( $request )
	{
		logtrace( "MergeParks", $request );
		if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_ADMIN, $request[ 'FromParkId' ], AUTH_CREATE )
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_ADMIN, $request[ 'ToParkId' ], AUTH_CREATE )
		) {
      
      $to_kingdom_id = $this->GetParkKingdomId( $request[ 'ToParkId' ] );
/*
			$sql = "delete from " . DB_PREFIX . "account where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "delete from " . DB_PREFIX . "configuration where id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "' and type = 'Park'";
			$this->db->query( $sql );
			$sql = "delete from " . DB_PREFIX . "event where id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "' and type = 'Park'";
			$this->db->query( $sql );
      */
      $sql = "delete from `" . DB_PREFIX . "authorization` where authorization_id in (select authorization_id from `" . DB_PREFIX . "officer` where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "')";
      $this->db->query( $sql );
			$sql = "delete from " . DB_PREFIX . "officer where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
      /*
			$sql = "delete from " . DB_PREFIX . "park where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "attendance set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "authorization set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "awards set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "awards set at_park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where at_park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "event_calendardetail set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "glicko2 set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
      */
			$sql = "update " . DB_PREFIX . "mundane set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "', kingdom_id = $to_kingdom_id where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
      /*
			$sql = "update " . DB_PREFIX . "parkday set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
			$sql = "update " . DB_PREFIX . "tournament set park_id = '" . mysql_real_escape_string( $request[ 'ToParkId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'FromParkId' ] ) . "'";
			$this->db->query( $sql );
      */
			logtrace( "Parks Merged", null );
			return Success();
		}
		logtrace( "Parks NOT Merged", null );
		return NoAuthorization();
	}

	public function TransferPark( $request )
	{
		if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_ADMIN, $request[ 'KingdomId' ], AUTH_CREATE )
		) {
			$this->park->clear();
			$this->park->park_id = $request[ 'ParkId' ];
			if ( $this->park->find() && $this->park->park_id == $request[ 'ParkId' ] ) {
				$this->park->kingdom_id = $request[ 'KingdomId' ];
				$this->park->save();
				$sql = "update " . DB_PREFIX . "mundane set kingdom_id = '" . mysql_real_escape_string( $request[ 'KingdomId' ] ) . "' where park_id = '" . mysql_real_escape_string( $request[ 'ParkId' ] ) . "'";
				$this->db->query( $sql );
				return Success();
			} else {
				return InvalidParameter( NULL, 'There was an issue accessing the park.' );
			}
		} else {
			return NoAuthorization();
		}
	}

	public function AddParkDay( $request )
	{
		if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_PARK, $request[ 'ParkId' ], AUTH_EDIT )
		) {
			$this->parkday->clear();
			$this->parkday->park_id = $request[ 'ParkId' ];
			$this->parkday->recurrence = $request[ 'Recurrence' ];
			$this->parkday->week_of_month = $request[ 'WeekOfMonth' ];
			$this->parkday->week_day = $request[ 'WeekDay' ];
			$this->parkday->month_day = $request[ 'MonthDay' ];
			$this->parkday->time = $request[ 'Time' ];
			$this->parkday->purpose = $request[ 'Purpose' ];
			$this->parkday->description = $request[ 'Description' ];
			$this->parkday->alternate_location = $request[ 'AlternateLocation' ];

			if ( $request[ 'AlternateLocation' ] > 0 ) {
     		logtrace( 'AddParkDay.AlternateLocation', null );
				$this->parkday->address = $request[ 'Address' ];
				$this->parkday->city = $request[ 'City' ];
				$this->parkday->province = $request[ 'Province' ];
				$this->parkday->postal_code = $request[ 'PostalCode' ];
				$this->parkday->map_url = $request[ 'MapUrl' ];
     		logtrace( 'AddParkDay', array( $this->parkday, $request ) );
        $this->park_geocode_h(null, $this->parkday);
			} else {
     		logtrace( 'AddParkDay.NormalLocation', null );
				$this->park->clear();
				$this->park->park_id = $request[ 'ParkId' ];
				$this->park->find();
				$this->parkday->address = $this->park->address;
				$this->parkday->city = $this->park->city;
				$this->parkday->province = $this->park->province;
				$this->parkday->postal_code = $this->park->postal_code;
				$this->parkday->latitude = $this->park->latitude;
				$this->parkday->longitude = $this->park->longitude;
				$this->parkday->google_geocode = $this->park->google_geocode;
				$this->parkday->location = $this->park->location;
				$this->parkday->map_url = $this->park->map_url;
			}
			$this->parkday->location_url = $request[ 'LocationUrl' ];

			$this->parkday->save();
		} else {
			return NoAuthorization();
		}
	}

	public function RemoveParkDay( $request )
	{
		$this->parkday->clear();
		$this->parkday->parkday_id = $request[ 'ParkDayId' ];
		if ( !valid_id( $request[ 'ParkDayId' ] && $this->parkday->find() ) ) {
			$park_id = $this->parkday->park_id;
		} else {
			return InvalidParameter();
		}
		if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_PARK, $park_id, AUTH_EDIT )
		) {
			$this->parkday->parkday_id = $request[ 'ParkDayId' ];
			$this->parkday->delete();
			return Success();
		}
		return NoAuthorization();
	}

	public function GetParks( $request )
	{
		$sql = "select *
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "parktitle pt on pt.parktitle_id = p.parktitle_id
					where p.park_id = '" . mysql_real_escape_string( $request[ 'ParkId' ] ) . "' and p.parent_park_id > 0
					order by pt.class desc, p.name asc";
		$r = $this->db->query( $sql );
		if ( $r !== false && $r->size() > 0 ) {
			$response = [ 'Status' => Success(), 'Parks' => [ ] ];
			do {
				$response[ 'Parks' ][] = [
					'ParkId'       => $r->park_id,
					'KingdomId'    => $r->kingdom_id,
					'ParentParkId' => $r->parent_park_id,
					'Name'         => $r->name,
					'Abbreviation' => $r->abbreviation,
					'Url'          => $r->url,
					'Directions'   => stripslashes( nl2br( $r->directions ) ),
					'Location'     => $r->location,
					'ParkTitleId'  => $r->parktitle_id,
					'Active'       => $r->active,
					'Title'        => $r->title,
					'Class'        => $r->class,
					'ParentOf'     => ( $r->is_principality == 1 && !array_search( $r->park_id, $request[ 'Stack' ] ) ) ? $this->GetParks( [ 'ParkId' => $r->park_id, 'Stack' => push_stack( $request[ 'Stack' ], $r->park_id ) ] ) : null,
				];
			} while ( $r->next() );
		} else {
			$response[ 'Status' ] = InvalidParameter();
		}
		return $response;
	}

	public function GetOfficers( $request )
	{
		$sql = "select a.*, p.name as park_name, k.name as kingdom_name, e.name as event_name, u.name as unit_name, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id
					from " . DB_PREFIX . "officer o
						left join " . DB_PREFIX . "mundane m on o.mundane_id = m.mundane_id
						left join " . DB_PREFIX . "authorization a on a.authorization_id = o.authorization_id
							left join " . DB_PREFIX . "park p on a.park_id = p.park_id
							left join " . DB_PREFIX . "kingdom k on a.kingdom_id = k.kingdom_id
							left join " . DB_PREFIX . "event e on a.event_id = e.event_id
							left join " . DB_PREFIX . "unit u on a.unit_id = u.unit_id
				where o.park_id = '" . mysql_real_escape_string( $request[ 'ParkId' ] ) . "' and o.kingdom_id > 0
			";
		$r = $this->db->query( $sql );
		$response = [ ];
		$response[ 'Officers' ] = [ ];
		if ( $r !== false && $r->size() > 0 ) {
			$response[ 'Status' ] = Success();
			do {
				$response[ 'Officers' ][] = [
					'AuthorizationId' => $r->authorization_id,
					'MundaneId'       => $r->mundane_id,
					'ParkId'          => $r->park_id,
					'KingdomId'       => $r->kingdom_id,
					'EventId'         => $r->event_id,
					'UnitId'          => $r->unit_id,
					'Role'            => $r->role,
					'ParkName'        => $r->park_name,
					'KingdomName'     => $r->kingdom_name,
					'EventName'       => $r->event_name,
					'UnitName'        => $r->unit_name,
					'Restricted'      => $r->restricted,
					'UserName'        => $r->username,
					'GivenName'       => $r->restricted == 0 ? $r->given_name : '',
					'Surname'         => $r->restricted == 0 ? $r->surname : '',
					'Persona'         => $r->persona,
					'OfficerId'       => $r->officer_id,
					'OfficerRole'     => $r->officer_role,
				];
			} while ( $r->next() );
			$response[ 'Status' ] = Success();
		} else {
			$response[ 'Status' ] = InvalidParameter();
		}
		return $response;
	}

	public function GetParkKingdomId( $pid )
	{
		$this->park->clear();
		$this->park->park_id = $pid;
		if ( $this->park->find() ) {
			return $this->park->kingdom_id;
		}

		return false;
	}
	public function GetParkShortInfo( $request )
	{
		$this->park->clear();
		$this->park->park_id = $request[ 'ParkId' ];
		$response = [ ];
		if ( $this->park->find() ) {
			$response[ 'Status' ] = Success();
			$response[ 'ParkInfo' ] = [ ];
			$response[ 'ParkInfo' ][ 'KingdomId' ] = $this->park->kingdom_id;
			$response[ 'ParkInfo' ][ 'ParkId' ] = $this->park->park_id;
			$response[ 'ParkInfo' ][ 'ParkName' ] = $this->park->name;
			$response[ 'ParkInfo' ][ 'Abbreviation' ] = $this->park->abbreviation;
			$response[ 'ParkInfo' ][ 'HasHeraldry' ] = $this->park->has_heraldry;
			$response[ 'ParkInfo' ][ 'Url' ] = $this->park->url;
			$response[ 'ParkInfo' ][ 'Location' ] = $this->park->location;
			$response[ 'ParkInfo' ][ 'Active' ] = $this->park->active;
			$k = Ork3::$Lib->kingdom->GetKingdomShortInfo( [ 'KingdomId' => $this->park->kingdom_id ] );
			if ( 0 == $k[ 'Status' ][ 'Status' ] ) {
				$response[ 'KingdomInfo' ] = $k[ 'KingdomInfo' ];
			}
		} else {
			$response[ 'Status' ] = InvalidParameter();
		}
		return $response;
	}

	public function GetParkDetails( $request )
	{
		$this->park->clear();
		$this->park->park_id = $request[ 'ParkId' ];
		$response = [ ];
		if ( $this->park->find() ) {
			$response[ 'Status' ] = Success();
			$response[ 'KingdomId' ] = $this->park->kingdom_id;
			$response[ 'ParkId' ] = $this->park->park_id;
			$response[ 'ParkName' ] = $this->park->name;
			$response[ 'Abbreviation' ] = $this->park->abbreviation;
			$response[ 'HasHeraldry' ] = $this->park->has_heraldry;
			$response[ 'ParkTitleId' ] = $this->park->parktitle_id;
			$parktitle = new yapo( $this->db, DB_PREFIX . 'parktitle' );
			$parktitle->parktitle_id = $this->park->parktitle_id;
			$parktitle->find();
			$response[ 'ParkTitle' ] = $parktitle->title;
			$response[ 'Active' ] = $this->park->active;
			$response[ 'Address' ] = $this->park->address;
			$response[ 'City' ] = $this->park->city;
			$response[ 'Province' ] = $this->park->province;
			$response[ 'PostalCode' ] = $this->park->postal_code;
			$response[ 'Url' ] = $this->park->url;
			$response[ 'MapUrl' ] = $this->park->map_url;
			$response[ 'Directions' ] = stripslashes( nl2br( $this->park->directions ) );
			$response[ 'Description' ] = stripslashes( nl2br( $this->park->description ) );
			$response[ 'GoogleGeocode' ] = $this->park->google_geocode;
			$response[ 'Location' ] = $this->park->location;
		} else {
			$response[ 'Status' ] = InvalidParameter();
		}
		return $response;
	}

	public function GetParkConfiguration( $request )
	{
		return Common::get_configs( $request[ 'ParkId' ], CFG_PARK );
	}

	public static function CalculateNextParkDay( $recurrence, $week_of_month, $month_day, $week_day, $from_date = null )
	{
		if ( is_null( $from_date ) )
			$from_date = strtotime( date( "Y-m-d" ) );
		switch ( $recurrence ) {
			case 'weekly':
				return date( "Y-m-d", strtotime( "next $week_day", $from_date ) );
			case 'week-of-month':
				switch ( $week_of_month ) {
					case 1:
						return date( "Y-m-d", strtotime( "first $week_day of " . date( "F Y", $from_date ), $from_date ) );
					case 2:
						return date( "Y-m-d", strtotime( "second $week_day of " . date( "F Y", $from_date ), $from_date ) );
					case 3:
						return date( "Y-m-d", strtotime( "third $week_day of " . date( "F Y", $from_date ), $from_date ) );
					case 4:
						return date( "Y-m-d", strtotime( "fourth $week_day of " . date( "F Y", $from_date ), $from_date ) );
					case 5:
						return date( "Y-m-d", strtotime( "fifth $week_day of " . date( "F Y", $from_date ), $from_date ) );
				}
			case 'monthly':
				return date( "Y-m-d", strtotime( date( "F $month_day, Y", $from_date ), $from_date ) );
		}
	}

	public function GetParkDays( $request )
	{
		$parkday = new yapo( $this->db, DB_PREFIX . 'parkday' );
		$parkday->clear();
		$parkday->park_id = $request[ 'ParkId' ];
		$response = [ 'Status' => Success(), 'ParkDays' => [ ] ];
		if ( valid_id( $request[ 'ParkId' ] ) && $parkday->find() ) {
			do {
				$response[ 'ParkDays' ][] = [
					'ParkDayId'         => $parkday->parkday_id,
					'ParkId'            => $parkday->park_id,
					'Recurrence'        => $parkday->recurrence,
					'WeekOfMonth'       => $parkday->week_of_month,
					'WeekDay'           => $parkday->week_day,
					'MonthDay'          => $parkday->month_day,
					'Time'              => $parkday->time,
					'Purpose'           => $parkday->purpose,
					'Description'       => $parkday->description,
					'AlternateLocation' => $parkday->alternate_location,
					'Address'           => $parkday->address,
					'City'              => $parkday->city,
					'Province'          => $parkday->province,
					'PostalCode'        => $parkday->postal_code,
					'GoogleGeocode'     => $parkday->google_geocode,
					'Location'          => $parkday->location,
					'MapUrl'            => $parkday->map_url,
					'LocationUrl'       => $parkday->location_url,
				];
			} while ( $parkday->next() );
		} else {
			$response[ 'Status' ] = InvalidParameter();
		}
		return $response;
	}

  public function PlayAmtgard($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (false && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false)
			return $cache;

    $latitude = $request['latitude'];
    $longitude = $request['longitude'];
    $start = isset($request['start']) ? date("Y-m-d", strtotime($request['start'])) : date("Y-m-d");
    $end = date("Y-m-d", strtotime($request['end']));
    $distance = isset($request['distance']) ? $request['distance'] : 25;
    $limit = isset($request['limit']) ? $request['limit'] : 12;
    
    $sql = "select * 
              from (
                SELECT 
                  d.*, p.kingdom_id, p.name park_name, k.name kingdom_name,
                  '$start' + interval mod(((c.day - weekday('$start')) + 7), 7) day next_day,
                  ( 3959 * acos( cos( radians($latitude) ) * cos( radians( d.latitude_d ) ) * cos( radians( d.longitude_d ) - radians($longitude) ) + sin( radians($latitude) ) * sin(radians(d.latitude_d)) ) ) AS distance 
                from 
                  ( select 
                      case sd.latitude when 0 then sp.latitude else sd.latitude end latitude_d,
                      case sd.longitude when 0 then sp.longitude else sd.longitude end longitude_d,
                      sd.*
                    from ork_parkday sd left join ork_park sp on sd.park_id = sp.park_id ) d
                  left join ork_day_convert c on d.week_day = c.dayname 
                  left join ork_park p on d.park_id = p.park_id
                    left join ork_kingdom k on p.kingdom_id = k.kingdom_id
                where
                  p.active = 'Active'
                having
                  next_day < '$end' and distance < $distance
                order by next_day asc, distance asc limit $limit) date_src";

    $r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['ParkDays'] = array();
			do {
				$response['ParkDays'][] = array(
						'ParkdayId' => $r->parkday_id,
						'KingdomId' => $r->kingdom_id,
						'ParkId' => $r->park_id,
						'ParkName' => $r->park_name,
						'KingdomName' => $r->kingdom_name,
						'Recurrence' => $r->recurrence,
						'WeekOfMonth' => $r->week_of_month,
						'WeekDay' => $r->week_day,
						'MonthDay' => $r->month_day,
						'Time' => $r->time,
						'Purpose' => $r->purpose,
						'Description' => $r->description,
						'AlternateLocation' => $r->alternate_location,
						'Address' => $r->address,
						'City' => $r->city,
						'Province' => $r->province,
						'PostalCode' => $r->postal_code,
						'GoogleGeocode' => $r->google_geocode,
						'Latitude' => $r->latitude_d,
						'Longitude' => $r->longitude_d,
						'Location' => $r->location,
						'MapUrl' => $r->map_url,
						'LocationUrl' => $r->location_url,
            'Distance' => $r->distance,
            'NextDay' => $r->next_day
					);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
  }
  
	public function GetParkAuthorizations( $request )
	{
		$sql = "select authorization_id, username, a.mundane_id, role from " . DB_PREFIX . "authorization a left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id where a.park_id = '" . mysql_real_escape_string( $request[ 'ParkId' ] ) . "' and system=0";
		$r = $this->db->query( $sql );
		$response = [ ];
		$response[ 'Authorizations' ] = [ ];
		if ( $r !== false && $r->size() > 0 ) {
			$response[ 'Status' ] = Success();
			do {
				$response[ 'Authorizations' ][] = [
					'AuthorizationId' => $r->authorization_id,
					'UserName'        => $r->username,
					'MundaneId'       => $r->mundane_id,
					'Role'            => $r->role,
				];
			} while ( $r->next() );
		} else {
			$response[ 'Status' ] = InvalidParameter( NULL, 'Problem processing request.' );
		}
		return $response;
	}

	public function park_geocode_h( $geocode = null, & $parkobject = null )
	{
    $parkobject = is_null($parkobject) ? $this->park : $parkobject;
 		logtrace( 'park_geocode_h', $parkobject );

		if ( trimlen( $geocode ) > 0 ) {
			$details = Common::Geocode( null, null, null, null, $geocode );
		} else {
			$details = Common::Geocode( $parkobject->address, $parkobject->city, $parkobject->province, $parkobject->postal_code );
		}
		if ( $details[ 'status' ] == 'OVER_QUERY_LIMIT' )
			return;
		$geocode = json_decode( $details[ 'Geocode' ] );
		$parkobject->latitude = $geocode->results[ 0 ]->geometry->location->lat;
		$parkobject->longitude = $geocode->results[ 0 ]->geometry->location->lng;
		$parkobject->google_geocode = $details[ 'Geocode' ];
		$parkobject->location = $details[ 'Location' ];
		$parkobject->address = $details[ 'Address' ];
		if ( isset( $details[ 'City' ] ) ) $parkobject->city = $details[ 'City' ];
		if ( isset( $details[ 'Province' ] ) ) $parkobject->province = $details[ 'Province' ];
		if ( isset( $details[ 'PostalCode' ] ) ) $parkobject->postal_code = $details[ 'PostalCode' ];
	}

	public function ParkGeocode( $park_id )
	{
    $parkobject = is_null($parkobject) ? $this->park : $parkobject;
    
		$parkobject->clear();
		$parkobject->park_id = $park_id;
		if ( $parkobject->find()) do {
			if ( $parkobject->park_id == $park_id && $this->park_geocode_h(null, $parkobject) ) {
				$parkobject->save();
			}
		} while ($parkobject->next());
	}

	public function unique_username( $name )
	{
		$srcname = $name;
		$found = false;
		do {
			$this->park->clear();
			$this->park->name = $name;
			if ( $this->park->find() ) {
				$name = $srcname . '-' . substr( md5( microtime() ), 0, 3 );
			} else {
				$found = true;
			}
		} while ( !$found );
		return $name;
	}

	public function CreatePark( $request )
	{
		if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_ADMIN, $request[ 'KingdomId' ], AUTH_CREATE )
		) {
			$this->log->Write( 'Park', $mundane_id, LOG_ADD, $request );
			$request[ 'Name' ] = $this->unique_username( trim( $request[ 'Name' ] ) );
			$this->park->clear();
			$this->park->kingdom_id = $request[ 'KingdomId' ];
			$this->park->name = $request[ 'Name' ];
			$this->park->abbreviation = $request[ 'Abbreviation' ];
			$this->park->active = 'Active';
			$this->park->modified = date( "Y-m-d H:i:s", time() );
			$this->park->parktitle_id = $request[ 'ParkTitleId' ];
			$this->park->save();
			$t = new Treasury();
			$t->create_accounts( $mundane_id, 'park', $this->park->park_id, $this->park->kingdom_id );
			$c = new Common();
			// Auths for a pricipality's officers travel with the mundane record, so we have to handle that @ the SetOfficer level
			$c->create_officers( $this->park->kingdom_id, $this->park->park_id, 0 );
			$c->create_events( $this->park->kingdom_id, $this->park->park_id );
			if ( strlen( $request[ 'Heraldry' ] ) ) {
				Ork3::$Lib->heraldry->SetParkHeraldry( $request );
			}
			$response = Success( $this->park->park_id );
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}

	public function SetParkDetails( $request )
	{
		logtrace( "SetParkDetails", $request );
		$this->park->clear();
		if ( trimlen( $request[ 'Name' ] ) > 0 ) {
			$this->park->name = trim( $request[ 'Name' ] );
			if ( $this->park->find() ) {
				if ( $this->park->park_id != $request[ 'ParkId' ] ) {
					return InvalidParameter( 'This park name already exists.' );
				}
			}
		}
		$this->park->clear();
		$this->park->park_id = $request[ 'ParkId' ];
		if ( $this->park->find() ) {
			if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
				&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_PARK, $request[ 'ParkId' ], AUTH_EDIT )
			) {
				$this->log->Write( 'Park', $mundane_id, LOG_EDIT, $request );
				$this->park->modified = date( "Y-m-d H:i:s", time() );

				if ( Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_KINGDOM, $this->park->kingdom_id, AUTH_EDIT ) ) {
					$this->park->name = trimlen( $request[ 'Name' ] ) == 0 ? $this->park->name : $request[ 'Name' ];
					$this->park->abbreviation = trimlen( $request[ 'Abbreviation' ] ) == 0 ? $this->park->abbreviation : $request[ 'Abbreviation' ];
					$parktitle = new yapo( $this->db, DB_PREFIX . 'parktitle' );
					$parktitle->clear();
					if ( isset( $request[ 'ParkTitleId' ] ) && $request[ 'ParkTitleId' ] != $this->park->parktitle_id ) {
						$parktitle->parktitle_id = $request[ 'ParkTitleId' ];
						if ( $parktitle->find() ) {
							$this->park->parktitle_id = $request[ 'ParkTitleId' ];
						}
					}
					$this->park->active = trimlen( $request[ 'Active' ] ) == 0 ? $this->park->active : $request[ 'Active' ];
				}

				$address_change = false;

				if ( isset( $request[ 'Address' ] ) && ( $this->park->address != $request[ 'Address' ] || trimlen( $this->park->location ) == 0 ) )
					$address_change = true;

				$this->park->url = isset( $request[ 'Url' ] ) ? ( $request[ 'Url' ] ) : $this->park->url;
				$this->park->address = isset( $request[ 'Address' ] ) ? ( $request[ 'Address' ] ) : $this->park->address;
				$this->park->city = isset( $request[ 'City' ] ) ? ( $request[ 'City' ] ) : $this->park->city;
				$this->park->province = isset( $request[ 'Province' ] ) ? ( $request[ 'Province' ] ) : $this->park->province;
				$this->park->postal_code = isset( $request[ 'PostalCode' ] ) ? ( $request[ 'PostalCode' ] ) : $this->park->postal_code;
				$this->park->directions = isset( $request[ 'Directions' ] ) ? ( $request[ 'Directions' ] ) : $this->park->directions;
				$this->park->description = isset( $request[ 'Description' ] ) ? ( $request[ 'Description' ] ) : $this->park->description;
				$this->park->map_url = isset( $request[ 'MapUrl' ] ) ? ( $request[ 'MapUrl' ] ) : $this->park->map_url;

				$this->park->save();
				$this->park->clear();
				$this->park->park_id = $request[ 'ParkId' ];
				if ( $this->park->find() ) {

					if ( $address_change )
						if ( isset( $request[ 'GeoCode' ] ) && trimlen( $request[ 'GeoCode' ] ) > 0 )
							$this->park_geocode_h( $request[ 'GeoCode' ] );
						else
							$this->park_geocode_h();

					if ( $request[ 'KingdomId' ] > 0 && $this->park->kingdom_id != $request[ 'KingdomId' ] ) {
						// Seriously? You couldn't work it out somehow?
						// AKA Blackspire Code, AKA Golden Plains Exception
						if ( Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_ADMIN, $request[ 'KingdomId' ], AUTH_ADMIN ) ) {
							$this->park->kingdom_id = $request[ 'KingdomId' ];
						} else {
							$response = Warning( 'You do not have permissions to move this Park [' . $this->park->park_id . ', ' . $this->park->kingdom_id . '] to another Kingdom [' . $request[ 'KingdomId' ] . '].' );
						}
					}

					if ( strlen( $request[ 'Heraldry' ] ) ) {
						Ork3::$Lib->heraldry->SetParkHeraldry( $request );
					}

					$this->park->save();
					$response = Success( $this->park->park_id );
				} else {
					$response = InvalidParameter( 'ParkId could not be found.' );
				}
			} else {
				$response = NoAuthorization( 'You do not have permissions to perform this action: ' . $mundane_id );
			}
		} else {
			$response = InvalidParameter( 'ParkId could not be found.' );
		}
		return $response;
	}

	public function SetOfficer( $request )
	{
		// Check for Principality Details, and create auths for Principality concurrently
		$response = [ ];
		if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
			&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_PARK, $request[ 'ParkId' ], AUTH_EDIT )
		) {
			$officer = new yapo( $this->db, DB_PREFIX . 'officer' );
			$c = new Common();
			$c->set_officer( $request[ 'KingdomId' ], $request[ 'ParkId' ], $request[ 'MundaneId' ], $request[ 'Role' ] );
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}

	public function RetirePark( $request )
	{
		return $this->WafflePark( $request, 'Retired' );
	}

	public function RestorePark( $request )
	{
		return $this->WafflePark( $request, 'Active' );
	}

	public function WafflePark( $request, $waffle )
	{
		$response = [ ];
		$this->park->clear();
		$this->park->park_id = $request[ 'ParkId' ];
		if ( $this->park->find() ) {
			if ( ( $mundane_id = Ork3::$Lib->authorization->IsAuthorized( $request[ 'Token' ] ) ) > 0
				&& Ork3::$Lib->authorization->HasAuthority( $mundane_id, AUTH_KINGDOM, $this->park->kingdom_id, AUTH_EDIT )
			) {
				$this->log->Write( 'Park', $mundane_id, 'Active' == $waffle ? LOG_RESTORE : LOG_RETIRE, $request );
				$this->park->active = $waffle;
				$this->park->save();
				$response = Success();
			} else {
				$response = NoAuthorization();
			}
		} else {
			$response = InvalidParameter( NULL, 'Problem processing request.' );
		}
		return $response;
	}
}
