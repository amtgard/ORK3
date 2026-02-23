<?php

class Controller_Eventcreate extends Controller
{
	public function __construct( $call = null, $id = null )
	{
		parent::__construct( $call, $id );
		$this->load_model('Event');
		$this->load_model('Attendance');

		$parts    = explode('/', $id ?? '');
		$event_id = (int)$parts[0];

		$eventInfo = $this->Attendance->get_event_info($event_id);
		$info      = $eventInfo[0] ?? [];

		$this->data['EventInfo']  = $info;
		$this->data['event_id']   = $event_id;
		$this->data['page_title'] = 'New Occurrence: ' . ($info['Name'] ?? 'Event');

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
		$this->data['menu']['create'] = [
			'url'     => UIR . 'Eventcreate/index/' . $event_id,
			'display' => 'New Occurrence'
		];
	}

	public function index( $p = null, $at_park_id = null )
	{
		$parts       = explode('/', $p ?? '');
		$event_id    = (int)$parts[0];
		$at_park_id  = isset($parts[1]) ? (int)$parts[1] : 0;
		$uid         = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		$this->data['AtParkId']   = $at_park_id;
		$this->data['AtParkName'] = '';
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
				header('Location: ' . UIR . "Eventnew/index/{$event_id}/{$new_id}");
				return;
			} elseif ( $r['Status'] != 5 ) {
				$this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
			}
		}

		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
	}
}

?>
