<?php

class Controller_Eventtemplatenew extends Controller
{
	public function __construct( $call = null, $id = null )
	{
		parent::__construct( $call, $id );
		$this->load_model('Event');

		$params   = explode('/', $id);
		$event_id = (int)preg_replace('/[^0-9]/', '', $params[0]);

		$this->data['EventDetails'] = $this->Event->get_event_details($event_id);
		$details = $this->data['EventDetails'];
		$info    = $details['EventInfo'][0] ?? [];

		$this->data['page_title'] = $details['Name'] ?? 'Event';

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
			'display' => $details['Name']
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

	public function index( $event_id = null )
	{
		$event_id = (int)preg_replace('/[^0-9]/', '', $event_id);
		$details  = $this->data['EventDetails'];
		$info     = $details['EventInfo'][0] ?? [];
		$now      = time();

		$upcoming = [];
		$past     = [];

		foreach ( $details['CalendarEventDetails'] ?? [] as $cd ) {
			// Pre-parse geocoded location for template use
			$cd['_LocationDisplay'] = '';
			$cd['_MapLink']         = '';
			if ( !empty($cd['Location']) ) {
				$loc = json_decode(stripslashes($cd['Location']));
				if ( $loc ) {
					$pt = isset($loc->location) ? $loc->location : ($loc->bounds->northeast ?? null);
					if ( $pt ) {
						$cd['_MapLink'] = 'https://maps.google.com/maps?q=@' . $pt->lat . ',' . $pt->lng;
					}
				}
			}
			$parts = array_filter([
				$cd['City']     ?? '',
				$cd['Province'] ?? '',
				$cd['Country']  ?? '',
			]);
			$cd['_LocationDisplay'] = implode(', ', $parts);

			if ( strtotime($cd['EventStart']) > $now ) {
				$upcoming[] = $cd;
			} else {
				$past[] = $cd;
			}
		}

		usort($upcoming, fn($a, $b) => strtotime($a['EventStart']) - strtotime($b['EventStart']));
		usort($past,     fn($a, $b) => strtotime($b['EventStart']) - strtotime($a['EventStart']));

		$this->data['Upcoming']   = $upcoming;
		$this->data['Past']       = $past;
		$this->data['TotalDates'] = count($details['CalendarEventDetails'] ?? []);
		$this->data['NextDate']   = count($upcoming) > 0 ? $upcoming[0]['EventStart'] : null;
		$this->data['EventInfo']  = $info;

		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$this->data['CanManageEvent'] = $uid > 0
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT);
	}
}

?>
