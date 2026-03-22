<?php

class Controller_Event extends Controller {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);

		$this->load_model('Park');
		$this->load_model('Kingdom');

		$params = explode('/',$id);
		$event_id = $params[0];

		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
		if ($this->data['EventDetails']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['EventDetails']['Status']['Error'];
		}
		$this->data[ 'page_title' ] = $this->data['EventDetails']['Name'];

		if (valid_id($this->data['EventDetails']['KingdomId']))
			$this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->data['EventDetails']['KingdomId'], 'display' => $this->data['EventDetails']['EventInfo'][0]['KingdomName'] );
		if (valid_id($this->data['EventDetails']['ParkId']))
			$this->data['menu']['park'] = array( 'url' => UIR.'Park/profile/'.$this->data['EventDetails']['ParkId'], 'display' => $this->data['EventDetails']['EventInfo'][0]['ParkName'] );
			$this->data['menu']['event'] = array( 'url' => UIR.'Event/index/'.$id, 'display' => $this->data['EventDetails']['Name'] );
			$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
			if ($_uid > 0 && valid_id($id) && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_EVENT, (int)$id, AUTH_EDIT)) {
				$this->data['menu']['admin'] = array( 'url' => UIR.'Admin/event/'.$id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
				$this->data['menulist']['admin'] = array(
					array( 'url' => UIR.'Admin/event/'.$id, 'display' => 'Event' )
				);
			}
	}

	public function index($event_id = null) {
		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
		if ($this->data['EventDetails']['Status']['Status'] != 0) {
			$this->data['Error'] = $this->data['EventDetails']['Status']['Error'];
		}
		if ($this->request->exists('Admin_event')) {
			$this->data['Admin_event'] = $this->request->Admin_event->Request;
		}

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		if ($uid > 0 && isset($this->request->rsvp_detail_id)) {
			$detail_id = (int)$this->request->rsvp_detail_id;
			$this->Event->toggle_rsvp($detail_id, $uid);
			header('Location: ' . UIR . 'Event/index/' . $event_id);
			return;
		}

		$can_manage = $uid > 0 && valid_id($event_id)
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE);
		$this->data['CanManageEvent'] = $can_manage;

		$rsvp_data = [];
		foreach ($this->data['EventDetails']['CalendarEventDetails'] ?? [] as $detail) {
			$detail_id = $detail['EventCalendarDetailId'];
			$rsvp_data[$detail_id] = [
				'Count'         => $this->Event->get_rsvp_count($detail_id),
				'UserAttending' => $uid > 0 ? $this->Event->get_rsvp($detail_id, $uid) : false,
				'List'          => $can_manage ? $this->Event->get_rsvp_list($detail_id) : [],
			];
		}
		$this->data['RsvpData'] = $rsvp_data;
	}

	public function template( $event_id = null ) {
		$this->template = '../revised-frontend/Eventtemplatenew_index.tpl';
		$event_id = (int)preg_replace( '/[^0-9]/', '', $event_id );
		$details  = $this->data['EventDetails'];
		$info     = $details['EventInfo'][0] ?? [];

		if ( !empty($info['KingdomId']) )
			$this->data['menu']['kingdom'] = [
				'url'     => UIR . 'Kingdom/profile/' . $info['KingdomId'],
				'display' => $info['KingdomName'],
			];
		if ( !empty($info['ParkId']) )
			$this->data['menu']['park'] = [
				'url'     => UIR . 'Park/profile/' . $info['ParkId'],
				'display' => $info['ParkName'],
			];
		$this->data['menu']['event'] = [
			'url'     => '',
			'display' => $details['Name'],
		];
		if ( $can_manage ) {
			$this->data['menu']['admin'] = [
				'url'      => UIR . 'Admin/event/' . $event_id,
				'display'  => 'Admin Panel <i class="fas fa-cog"></i>',
				'no-crumb' => 'no-crumb',
			];
			$this->data['menulist']['admin'] = [
				[ 'url' => UIR . 'Admin/event/' . $event_id, 'display' => 'Event' ],
			];
		}

		$now      = time();
		$upcoming = [];
		$past     = [];

		foreach ( $details['CalendarEventDetails'] ?? [] as $cd ) {
			$cd['_LocationDisplay'] = '';
			$cd['_MapLink']         = '';
			if ( !empty($cd['Location']) ) {
				$loc = json_decode( stripslashes($cd['Location']) );
				if ( $loc ) {
					$pt = isset($loc->location) ? $loc->location : ($loc->bounds->northeast ?? null);
					if ( $pt ) {
						$cd['_MapLink'] = 'https://maps.google.com/maps?q=@' . $pt->lat . ',' . $pt->lng;
					}
				}
			}
			$parts = array_filter( [
				$cd['City']     ?? '',
				$cd['Province'] ?? '',
				$cd['Country']  ?? '',
			] );
			$cd['_LocationDisplay'] = implode( ', ', $parts );

			if ( strtotime($cd['EventStart']) > $now ) {
				$upcoming[] = $cd;
			} else {
				$past[] = $cd;
			}
		}

		usort( $upcoming, fn($a, $b) => strtotime($a['EventStart']) - strtotime($b['EventStart']) );
		usort( $past,     fn($a, $b) => strtotime($b['EventStart']) - strtotime($a['EventStart']) );

		// Attach RSVP counts to all occurrences in a single batch query
		$allCds = array_merge($upcoming, $past);
		if (!empty($allCds)) {
			$detailIds = array_map(fn($cd) => (int)$cd['EventCalendarDetailId'], $allCds);
			$idList    = implode(',', $detailIds);
			global $DB;
			$DB->Clear();
			$rsvpResult = $DB->DataSet("SELECT event_calendardetail_id, COUNT(*) AS cnt FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id IN ($idList) GROUP BY event_calendardetail_id");
			$rsvpCounts = [];
			if ($rsvpResult) {
				while ($rsvpResult->Next()) {
					$rsvpCounts[(int)$rsvpResult->event_calendardetail_id] = (int)$rsvpResult->cnt;
				}
			}
			foreach ($upcoming as &$cd) {
				$cd['_RsvpCount'] = $rsvpCounts[(int)$cd['EventCalendarDetailId']] ?? 0;
			}
			unset($cd);
			foreach ($past as &$cd) {
				$cd['_RsvpCount'] = $rsvpCounts[(int)$cd['EventCalendarDetailId']] ?? 0;
			}
			unset($cd);
		}

		$this->data['Upcoming']   = $upcoming;
		$this->data['Past']       = $past;
		$this->data['TotalDates'] = count( $details['CalendarEventDetails'] ?? [] );
		$this->data['NextDate']   = count($upcoming) > 0 ? $upcoming[0]['EventStart'] : null;
		$this->data['EventInfo']  = $info;

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$this->data['CanManageEvent'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority( $uid, AUTH_EVENT, $event_id, AUTH_CREATE );
	}

	public function detail( $p = null ) {
		$this->template = '../revised-frontend/Eventnew_index.tpl';
		$this->load_model('Attendance');
		$this->load_model('Reports');

		$params    = explode( '/', $p ?? '' );
		$event_id  = (int)preg_replace( '/[^0-9]/', '', $params[0] ?? '' );
		$detail_id = (int)preg_replace( '/[^0-9]/', '', $params[1] ?? '' );
		$action    = $params[2] ?? '';
		$del_id    = (int)preg_replace( '/[^0-9]/', '', $params[3] ?? '' );

		$eventInfo = $this->Attendance->get_event_info( $event_id );
		$info      = $eventInfo[0] ?? [];

		$this->data['EventInfo']  = $info;
		$this->data['event_id']   = $event_id;
		$this->data['detail_id']  = $detail_id;
		$this->data['page_title'] = $info['Name'] ?? $this->data['page_title'];

		if ( !empty($info['KingdomId']) )
			$this->data['menu']['kingdom'] = [
				'url'     => UIR . 'Kingdom/profile/' . $info['KingdomId'],
				'display' => $info['KingdomName'],
			];
		if ( !empty($info['ParkId']) )
			$this->data['menu']['park'] = [
				'url'     => UIR . 'Park/profile/' . $info['ParkId'],
				'display' => $info['ParkName'],
			];
		$this->data['menu']['event'] = [
			'url'     => '',
			'display' => $info['Name'] ?? 'Event',
		];
		if ( $this->data['CanManageEvent'] ) {
			$this->data['menu']['admin'] = [
				'url'      => UIR . 'Admin/event/' . $event_id,
				'display'  => 'Admin Panel <i class="fas fa-cog"></i>',
				'no-crumb' => 'no-crumb',
			];
			$this->data['menulist']['admin'] = [
				[ 'url' => UIR . 'Admin/event/' . $event_id, 'display' => 'Event' ],
			];
		}

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		$this->data['DefaultAttendanceCredits'] = 1;
		$this->data['DefaultParkName']    = $this->session->park_name    ?? '';
		$this->data['DefaultParkId']      = $this->session->park_id      ?? 0;
		$this->data['DefaultKingdomName'] = $this->session->kingdom_name ?? '';
		$this->data['DefaultKingdomId']   = $this->session->kingdom_id   ?? 0;

		if ( $action === 'deletedetail' && $uid > 0 ) {
			if ( Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE) ) {
				global $DB;
				$checkAtt  = $DB->DataSet("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "attendance WHERE event_calendardetail_id = " . $detail_id . " LIMIT 1");
				$attCnt    = ($checkAtt && $checkAtt->Size() > 0 && $checkAtt->Next()) ? (int)$checkAtt->cnt : 0;
				$checkRsvp = $DB->DataSet("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = " . $detail_id . " LIMIT 1");
				$rsvpCnt   = ($checkRsvp && $checkRsvp->Size() > 0 && $checkRsvp->Next()) ? (int)$checkRsvp->cnt : 0;
				if ( $attCnt === 0 && $rsvpCnt === 0 ) {
					$this->Event->delete_calendar_detail($this->session->token, $detail_id);
				}
			}
			$evRow = $DB->DataSet("SELECT kingdom_id, park_id FROM " . DB_PREFIX . "event WHERE event_id = " . $event_id . " LIMIT 1");
			$evKid = 0; $evPid = 0;
			if ($evRow && $evRow->Size() > 0 && $evRow->Next()) {
				$evKid = (int)$evRow->kingdom_id;
				$evPid = (int)$evRow->park_id;
			}
			if ($evPid > 0) {
				$redirect = UIR . 'Park/profile/' . $evPid . '&tab=events';
			} elseif ($evKid > 0) {
				$redirect = UIR . 'Kingdom/profile/' . $evKid . '&tab=events';
			} else {
				$redirect = UIR;
			}
			header('Location: ' . $redirect);
			exit;
		}

		if ( $action === 'rsvp' && $uid > 0 ) {
			$this->Event->toggle_rsvp($detail_id, $uid);
			header('Location: ' . UIR . 'Event/detail/' . $event_id . '/' . $detail_id);
			return;
		}

		if ( strlen($action) > 0 && $uid > 0 ) {

			if ( $action === 'edit' ) {
				if ( Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE) ) {
					$this->request->save('Eventnew_edit', true);
					$newName = trim($this->request->Eventnew_edit->EventName ?? '');
					if ( $newName ) {
						$this->Event->update_event($this->session->token, $event_id, null, null, null, null, $newName, '', '');
						$bustKey = Ork3::$Lib->ghettocache->key(['', null, null, null, null, null, $event_id]);
						Ork3::$Lib->ghettocache->bust('SearchService.Event', $bustKey);
					}
					$r = $this->Event->update_event_detail([
						'Token'                 => $this->session->token,
						'EventCalendarDetailId' => $detail_id,
						'EventId'               => $event_id,
						'Current'               => $this->request->Eventnew_edit->Current ? 1 : 0,
						'Price'                 => $this->request->Eventnew_edit->Price,
						'EventStart'            => $this->request->Eventnew_edit->StartDate,
						'EventEnd'              => $this->request->Eventnew_edit->EndDate,
						'Description'           => $this->request->Eventnew_edit->Description,
						'Url'                   => $this->request->Eventnew_edit->Url,
						'UrlName'               => $this->request->Eventnew_edit->UrlName,
						'Address'               => $this->request->Eventnew_edit->Address,
						'Province'              => $this->request->Eventnew_edit->Province,
						'PostalCode'            => $this->request->Eventnew_edit->PostalCode,
						'City'                  => $this->request->Eventnew_edit->City,
						'Country'               => $this->request->Eventnew_edit->Country,
						'MapUrl'                => $this->request->Eventnew_edit->MapUrl,
						'MapUrlName'            => $this->request->Eventnew_edit->MapUrlName,
					]);
					if ( $r['Status'] == 0 ) {
						$this->request->clear('Eventnew_edit');
						header('Location: ' . UIR . 'Event/detail/' . $event_id . '/' . $detail_id);
						exit;
					} elseif ( $r['Status'] != 5 ) {
						$this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
					}
				}

			} else {
				// Attendance actions
				$this->request->save('Attendance_event', true);
				$r = ['Status' => 0];
				switch ( $action ) {
					case 'new':
						$detail = $this->Attendance->get_eventdetail_info($detail_id);
						$r = $this->Attendance->add_attendance(
							$this->session->token,
							$this->request->Attendance_event->AttendanceDate,
							valid_id($detail['AtParkId']) ? $detail['AtParkId'] : null,
							$detail_id,
							$this->request->Attendance_event->MundaneId,
							$this->request->Attendance_event->ClassId,
							$this->request->Attendance_event->Credits
						);
						break;
					case 'delete':
						$r = $this->Attendance->delete_attendance($this->session->token, $del_id);
						break;
				}
				if ( $r['Status'] == 0 ) {
					$this->data['DefaultParkName']          = $this->request->Attendance_event->ParkName    ?? $this->data['DefaultParkName'];
					$this->data['DefaultParkId']            = $this->request->Attendance_event->ParkId      ?? $this->data['DefaultParkId'];
					$this->data['DefaultKingdomName']       = $this->request->Attendance_event->KingdomName ?? $this->data['DefaultKingdomName'];
					$this->data['DefaultKingdomId']         = $this->request->Attendance_event->KingdomId   ?? $this->data['DefaultKingdomId'];
					$this->data['DefaultAttendanceCredits'] = $this->request->Attendance_event->Credits     ?? 1;
					$this->request->clear('Attendance_event');
				} elseif ( $r['Status'] != 5 ) {
					$this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
				}
			}
		}

		$this->data['EventDetail'] = $this->Attendance->get_eventdetail_info($detail_id);

		$atParkId = (int)($this->data['EventDetail']['AtParkId'] ?? 0);
		$_parkLookupId = $atParkId ?: (int)($this->data['EventInfo']['ParkId'] ?? 0);
		if ( $_parkLookupId > 0 ) {
			global $DB;
			$row = $DB->DataSet("SELECT name, address, city, province, postal_code, location FROM " . DB_PREFIX . "park WHERE park_id = " . $_parkLookupId . " LIMIT 1");
			if ($row && $row->Size() > 0 && $row->Next()) {
				$this->data['AtParkName']       = $row->name;
				$this->data['AtParkAddress']    = $row->address;
				$this->data['AtParkCity']       = $row->city;
				$this->data['AtParkProvince']   = $row->province;
				$this->data['AtParkPostalCode'] = $row->postal_code;
				$this->data['AtParkLocation']   = $row->location;
			} else {
				$this->data['AtParkName'] = $this->data['AtParkAddress'] = $this->data['AtParkCity'] = $this->data['AtParkProvince'] = $this->data['AtParkPostalCode'] = $this->data['AtParkLocation'] = '';
			}
		} else {
			$this->data['AtParkName'] = $this->data['AtParkAddress'] = $this->data['AtParkCity'] = $this->data['AtParkProvince'] = $this->data['AtParkPostalCode'] = $this->data['AtParkLocation'] = '';
		}
		$this->data['AttendanceReport'] = $this->Attendance->get_attendance_for_event($event_id, $detail_id);
		$classes                        = $this->Attendance->get_classes();
		$this->data['Classes']          = $classes['Classes'];
		// [TOURNAMENTS HIDDEN] $this->data['Tournaments'] = [];

		if ( $this->request->exists('Attendance_event') ) {
			$this->data['Attendance_event'] = $this->request->Attendance_event->Request;
		}

		$cd      = $this->data['EventDetail'];
		$mapLink = '';
		if ( !empty($cd['Location']) ) {
			$loc = json_decode( stripslashes($cd['Location']) );
			if ( $loc ) {
				$pt = isset($loc->location) ? $loc->location : ($loc->bounds->northeast ?? null);
				if ( $pt ) $mapLink = 'https://maps.google.com/maps?q=@' . $pt->lat . ',' . $pt->lng;
			}
		}
		$this->data['MapLink'] = $mapLink;

		$now = time();
		$this->data['IsUpcoming']      = strtotime($cd['EventStart'] ?? '') > $now;
		$this->data['AttendanceCount'] = count($this->data['AttendanceReport']['Attendance'] ?? []);

		$this->data['CanManageEvent'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE);
		$this->data['CanManageAttendance'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT);

		$this->data['RsvpCount']     = $this->Event->get_rsvp_count($detail_id);
		$this->data['UserAttending'] = $uid > 0 ? $this->Event->get_rsvp($detail_id, $uid) : false;
		$this->data['RsvpList']      = $this->data['CanManageAttendance'] ? $this->Event->get_rsvp_list($detail_id) : [];

		global $DB;
		$cdCountRow = $DB->DataSet('SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $event_id . ' LIMIT 1');
		$this->data['CalendarDetailCount'] = ($cdCountRow && $cdCountRow->Size() > 0 && $cdCountRow->Next()) ? (int)$cdCountRow->cnt : 1;
	}

	public function create( $p = null ) {
		$this->template = '../revised-frontend/Eventcreate_index.tpl';
		$this->load_model('Attendance');

		$parts      = explode( '/', $p ?? '' );
		$event_id   = (int)$parts[0];
		$at_park_id = isset($parts[1]) ? (int)$parts[1] : 0;
		$uid        = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		$eventInfo = $this->Attendance->get_event_info($event_id);
		$info      = $eventInfo[0] ?? [];

		$this->data['EventInfo']  = $info;
		$this->data['event_id']   = $event_id;
		$this->data['page_title'] = 'New Occurrence: ' . ($info['Name'] ?? 'Event');

		if ( !empty($info['KingdomId']) )
			$this->data['menu']['kingdom'] = [
				'url'     => UIR . 'Kingdom/profile/' . $info['KingdomId'],
				'display' => $info['KingdomName'],
			];
		if ( !empty($info['ParkId']) )
			$this->data['menu']['park'] = [
				'url'     => UIR . 'Park/profile/' . $info['ParkId'],
				'display' => $info['ParkName'],
			];
		$this->data['menu']['event'] = [
			'url'     => '',
			'display' => $info['Name'] ?? 'Event',
		];
		$this->data['menu']['create'] = [
			'url'     => UIR . 'Event/create/' . $event_id,
			'display' => 'New Occurrence',
		];

		$this->data['AtParkId']   = $at_park_id;
		$this->data['AtParkName'] = '';
		$rawDate = trim($_GET['date'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
			$this->data['PresetDate']    = $rawDate . 'T12:00';
			$this->data['PresetEndDate'] = $rawDate . 'T18:00';
		} else {
			$this->data['PresetDate']    = '';
			$this->data['PresetEndDate'] = '';
		}
		if ( $at_park_id > 0 ) {
			global $DB;
			$row = $DB->DataSet("SELECT name FROM " . DB_PREFIX . "park WHERE park_id = " . $at_park_id . " LIMIT 1");
			$this->data['AtParkName'] = ($row && $row->Size() > 0 && $row->Next()) ? $row->name : '';
		}

		if ( !$uid || !Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE) ) {
			header('Location: ' . UIR . 'Login');
			return;
		}

		if ( !empty($_POST) ) {
			$this->request->save('Eventcreate', true);
			$r = $this->Event->add_event_detail([
				'Token'       => $this->session->token,
				'EventId'     => $event_id,
				'AtParkId'    => (int)($this->request->Eventcreate->AtParkId ?? 0) ?: null,
				'Current'     => 1,
				'Price'       => $this->request->Eventcreate->Price,
				'EventStart'  => $this->request->Eventcreate->StartDate,
				'EventEnd'    => $this->request->Eventcreate->EndDate,
				'Description' => $this->request->Eventcreate->Description,
				'Url'         => $this->request->Eventcreate->Url,
				'UrlName'     => $this->request->Eventcreate->UrlName,
				'Address'     => $this->request->Eventcreate->Address,
				'Province'    => $this->request->Eventcreate->Province,
				'PostalCode'  => $this->request->Eventcreate->PostalCode,
				'City'        => $this->request->Eventcreate->City,
				'Country'     => $this->request->Eventcreate->Country,
				'MapUrl'      => $this->request->Eventcreate->MapUrl,
				'MapUrlName'  => $this->request->Eventcreate->MapUrlName,
			]);
			if ( $r['Status'] == 0 ) {
				// Some SOAP implementations return new ID in Detail; fallback to newest detail
				$new_id = (int)($r['Detail'] ?? 0);
				if ( !$new_id ) {
					$details = $this->Event->get_event_details($event_id);
					$all     = $details['CalendarEventDetails'] ?? [];
					if ( $all ) $new_id = max(array_map('intval', array_column($all, 'EventCalendarDetailId')));
				}
				$bustKey = Ork3::$Lib->ghettocache->key(['', null, null, null, null, null, $event_id]);
				Ork3::$Lib->ghettocache->bust('SearchService.Event', $bustKey);
				header('Location: ' . UIR . "Event/detail/{$event_id}/{$new_id}");
				return;
			} elseif ( $r['Status'] != 5 ) {
				$this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
			}
		}

		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
	}

}

?>
