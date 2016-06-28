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

		$this->data[ 'page_title' ] = $this->method;
		$this->data[ 'controller_title' ] = get_class( $this );
		$this->data[ 'path' ] = [ get_class( $this ), $method ];

		$this->data[ 'menu' ] = [ ];
		$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin' ];
		$this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home' ];

        if ( isset( $this->session->kingdom_id ) ) {
			$this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/index/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Admin' ];
		}

		if ( isset( $this->session->park_id ) ) {
			$this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/index/' . $this->session->park_id, 'display' => $this->session->park_name ];
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin' ];
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
		unset( $this->session->kingdom_id );
		unset( $this->session->park_id );
		unset( $this->session->kingdom_name );
		unset( $this->session->park_name );
		$this->data[ 'Tournaments' ] = $this->Report->TournamentReport( [ 'Limit' => 15 ] );
		$this->data[ 'ActiveKingdomSummary' ] = $this->Report->GetActiveKingdomsSummary();
		$this->data[ 'EventSummary' ] = $this->Search->Search_Event( null, null, 0, null, null, 15, null, true );
		$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin' ];
		$this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home' ];
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
