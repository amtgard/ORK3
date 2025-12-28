<?php

class Controller_Park extends Controller
{
	public function __construct( $call = null, $id = null )
	{
		parent::__construct( $call, $id );
		$id = preg_replace('/[^0-9]/', '', $id);

		if ( $id != $this->session->park_id ) {
			unset( $this->session->kingdom_id );
			unset( $this->session->kingdom_name );
			unset( $this->session->park_name );
			unset( $this->session->park_id );
		}

		$this->session->park_id = $id;

		if ( !isset( $this->session->kingdom_id ) ) {
			// Direct link
			$park_info = $this->Park->get_park_info( $id );
			$this->session->park_name = $park_info[ 'ParkInfo' ][ 'ParkName' ];
			$this->session->kingdom_id = $park_info[ 'KingdomInfo' ][ 'KingdomId' ];
			$this->session->kingdom_name = $park_info[ 'KingdomInfo' ][ 'KingdomName' ];
		}
		$this->data[ 'kingdom_id' ] = $this->session->kingdom_id;
		$this->data[ 'park_id' ] = $this->session->park_id;
		$this->data[ 'kingdom_name' ] = $this->session->kingdom_id;

		if ( isset( $this->request->park_name ) ) {
			$this->session->park_name = $this->request->park_name;
		}
		$this->data[ 'park_name' ] = $this->session->park_name;
		$this->data[ 'page_title' ] = $this->session->park_name;

		if ($this->data['LoggedIn']) {
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
		}
		$this->data[ 'menulist' ][ 'admin' ] = [
			[ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Park' ],
			[ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Kingdom' ],
		];
		$this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/index/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
		$this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/index/' . $this->session->park_id, 'display' => $this->session->park_name ];
	}

	public function index( $park_id = null )
	{
		$park_id = preg_replace('/[^0-9]/', '', $park_id);
		$this->load_model( 'Reports' );
		$this->data[ 'event_summary' ] = $this->Park->get_park_events( $park_id );
		$this->data[ 'park_days' ] = $this->Park->get_park_parkdays( $park_id );
		$this->data[ 'park_info' ] = $this->Park->get_park_details( $park_id );
		$this->data[ 'park_officers' ] = $this->Park->GetOfficers(['ParkId' => $park_id, 'Token' => $this->session->token]);
		$this->data[ 'park_tournaments' ] = $this->Reports->get_tournaments( null, null, $park_id );
	}
}
