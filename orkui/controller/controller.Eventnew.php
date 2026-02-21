<?php

class Controller_Eventnew extends Controller
{
	public function __construct( $call = null, $id = null )
	{
		parent::__construct( $call, $id );
		$this->load_model('Attendance');
		$this->load_model('Reports');
		$this->load_model('Event');

		$params    = explode('/', $id ?? '');
		$event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');

		$eventInfo = $this->Attendance->get_event_info($event_id);
		$info      = $eventInfo[0] ?? [];

		$this->data['EventInfo']  = $info;
		$this->data['event_id']   = $event_id;
		$this->data['detail_id']  = $detail_id;
		$this->data['page_title'] = $info['Name'] ?? 'Event';

		if ( !empty($info['KingdomId']) )
			$this->data['menu']['kingdom'] = [
				'url'     => UIR . 'Kingdomnew/index/' . $info['KingdomId'],
				'display' => $info['KingdomName']
			];
		if ( !empty($info['ParkId']) )
			$this->data['menu']['park'] = [
				'url'     => UIR . 'Parknew/index/' . $info['ParkId'],
				'display' => $info['ParkName']
			];
		$this->data['menu']['event'] = [
			'url'     => UIR . 'Eventtemplatenew/index/' . $event_id,
			'display' => $info['Name'] ?? 'Event'
		];
		if ( $this->data['LoggedIn'] ) {
			$this->data['menu']['admin'] = [
				'url'      => UIR . 'Admin/event/' . $event_id,
				'display'  => 'Admin Panel <i class="fas fa-cog"></i>',
				'no-crumb' => 'no-crumb'
			];
		}
		$this->data['menulist']['admin'] = [
			[ 'url' => UIR . 'Admin/event/' . $event_id, 'display' => 'Event' ]
		];
	}

	public function index( $p = null )
	{
		$params    = explode('/', $p ?? '');
		$event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
		$action    = $params[2] ?? '';
		$del_id    = (int)preg_replace('/[^0-9]/', '', $params[3] ?? '');

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		$this->data['DefaultAttendanceCredits'] = 1;
		$this->data['DefaultParkName']    = $this->session->park_name    ?? '';
		$this->data['DefaultParkId']      = $this->session->park_id      ?? 0;
		$this->data['DefaultKingdomName'] = $this->session->kingdom_name ?? '';
		$this->data['DefaultKingdomId']   = $this->session->kingdom_id   ?? 0;

		if ( strlen($action) > 0 && $uid > 0 ) {

			if ( $action === 'edit' ) {
				// Only users with event edit authority may update details
				if ( Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT) ) {
					$this->request->save('Eventnew_edit', true);
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

		$this->data['EventDetail']      = $this->Attendance->get_eventdetail_info($detail_id);
		$this->data['AttendanceReport'] = $this->Attendance->get_attendance_for_event($event_id, $detail_id);
		$classes                        = $this->Attendance->get_classes();
		$this->data['Classes']          = $classes['Classes'];
		$this->data['Tournaments']      = $this->Reports->get_tournaments(null, null, null, $event_id, $detail_id);

		if ( $this->request->exists('Attendance_event') ) {
			$this->data['Attendance_event'] = $this->request->Attendance_event->Request;
		}

		$cd      = $this->data['EventDetail'];
		$mapLink = '';
		if ( !empty($cd['Location']) ) {
			$loc = json_decode(stripslashes($cd['Location']));
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
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT);
	}
}

?>
