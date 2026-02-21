<?php

class Controller
{

	var $data = [ ];
	var $kingdom = null;
	var $view = null;
	var $method = null;
	var $action = null;
	var $settings = null;
	var $session = null;
	var $template = null;

	public function __construct( $method = null, $action = null )
	{
		$this->method = is_null( $method ) ? 'index' : $method;
		$this->action = $action;

		global $Settings, $Session, $Request;
		$this->settings = $Settings;
		$this->session = $Session;
		$this->request = $Request;

		$this->load_model( $this->controller_class() );

		$this->Report = new APIModel( 'Report' );
		$this->Search = new JSONModel( 'Search' );
		$this->data[ 'no_index' ] = false;

		if (get_class( $this ) == "Controller") {
			$this->data[ 'page_title' ] = "Home";
		} else {
			$this->data[ 'page_title' ] = $this->method;
		}
		$this->data['LoggedIn'] = isset($this->session->user_id);

		$this->data[ 'controller_title' ] = get_class( $this );
		$this->data[ 'path' ] = [ get_class( $this ), $method ];

		$this->data[ 'menu' ] = [ ];
		$this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
		if ($this->data['LoggedIn']) {
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin Panel', 'no-crumb' => 'no-crumb' ];
		}

    if ( isset( $this->session->kingdom_id ) ) {
			$this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/index/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
			if ($this->data['LoggedIn']) {
				$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
			}
		}

		if ( isset( $this->session->park_id ) ) {
			$this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/index/' . $this->session->park_id, 'display' => $this->session->park_name ];
			if ($this->data['LoggedIn']) {
				$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
			}
		}
	}

	public function load_model( $name )
	{
		if ( file_exists( DIR_MODEL . 'model.' . $name . '.php' ) ) {
			require_once( DIR_MODEL . 'model.' . $name . '.php' );
			$model_name = 'Model_' . $name;
			$this->$name = new $model_name();
		}
	}

	public function __call( $method, $action )
	{
	}

	public function encode_image_file( $tmpname )
	{
		$imgbinary = fread( fopen( $tmpname, "r" ), filesize( $tmpname ) );
		return base64_encode( $imgbinary );
	}

	public function index( $action = null )
	{
		// Determine the logged-in user's home kingdom from their profile in the DB.
		// Fall back to the session-cached value only when not logged in.
		if ( $this->data['LoggedIn'] && isset( $this->session->user_id ) ) {
			global $DB;
			$uid = (int) $this->session->user_id;
			$hkRow = $DB->DataSet(
				"SELECT p.kingdom_id FROM ork_mundane m
				 INNER JOIN ork_park p ON p.park_id = m.park_id
				 WHERE m.mundane_id = {$uid} LIMIT 1"
			);
			$this->data['UserKingdomId'] = ($hkRow && $hkRow->Size() > 0 && $hkRow->Next())
				? (int) $hkRow->kingdom_id
				: 0;
		} else {
			$this->data['UserKingdomId'] = 0;
		}

		unset( $this->session->kingdom_id );
		unset( $this->session->park_id );
		unset( $this->session->kingdom_name );
		unset( $this->session->park_name );
		$this->data[ 'Tournaments' ] = $this->Report->TournamentReport( [ 'Limit' => 15 ] );
		$this->data[ 'ActiveKingdomSummary' ] = $this->Report->GetActiveKingdomsSummary();
		$this->data[ 'EventSummary' ] = $this->Search->Search_Event( null, null, 0, null, null, 15, null, true );
		$this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
		if ($this->data['LoggedIn']) {
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
		}
		unset( $this->data[ 'menu' ][ 'kingdom' ] );
		unset( $this->data[ 'menu' ][ 'park' ] );
	}

	public function view()
	{
		$V = null;
		if ( is_null( $this->view ) ) {
			logtrace( "Controller: view(): $this->template, " . $this->controller_class() . ", $this->method, $this->action", null );
			$V = new View( $this->template, $this->controller_class(), $this->method, $this->action );
			$V->__setttings = $this->settings;
		} else {
			logtrace( "Controller: view(): $this->template, " . $this->controller_class() . ", $this->method, $this->action", null );
			$V = new View( $this->template, $this->controller_class(), $this->method, $this->action );
			$V->__setttings = $this->settings;
		}
		logtrace( "Controller view(): data, {$this->kingdom}", $this->data );
		$CONTENT = $V->view( $this->data, $this->kingdom );

		return $CONTENT;
	}

	public function controller_class()
	{
		$parts = explode( '_', get_class( $this ) );
		return implode( '_', array_slice( $parts, 1 ) );
	}
}
