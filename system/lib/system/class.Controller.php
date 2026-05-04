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

		// Proactive stale-session check: if the session token no longer matches the DB,
		// the user logged in on another device — clear session and redirect to login.
		$_skipTokenCheck = in_array(get_class($this), [
			'Controller_Login',
			'Controller_SearchAjax',
			'Controller_AttendanceAjax',
			'Controller_EventAjax',
			'Controller_KingdomAjax',
			'Controller_ParkAjax',
			'Controller_PlayerAjax',
			'Controller_AdminAjax',
			'Controller_WnAjax',
		]);
		if (!$_skipTokenCheck && isset($this->session->user_id) && isset($this->session->token)) {
			global $DB;
			$DB->Clear();
			$_uid_check = (int)$this->session->user_id;
			$_tok_check = $this->session->token;
			$_rs = $DB->DataSet("SELECT token FROM " . DB_PREFIX . "mundane WHERE mundane_id = {$_uid_check} LIMIT 1");
			if (!$_rs || !$_rs->Next() || $_rs->token !== $_tok_check) {
				$_returnRoute = trim($_GET['Route'] ?? '');
				unset($_SESSION['is_authorized_mundane_id']);
				session_unset();
				session_destroy();
				$_returnParam = (strlen($_returnRoute) > 0 && strncasecmp($_returnRoute, 'Login', 5) !== 0)
					? '&return=' . urlencode($_returnRoute)
					: '';
				header('Location: ' . UIR . 'Login/login&msg=session_replaced' . $_returnParam);
				exit;
			}
			$DB->Clear();
		}

		$_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		// Viewer accessibility-font preferences — read once and surface to every template
		$this->data['ViewerBasicFonts']    = 0;
		$this->data['ViewerDyslexiaFonts'] = 0;
		if ($_uid > 0) {
			global $DB;
			$DB->Clear();
			$_prefRs = $DB->DataSet("SELECT basic_fonts, dyslexia_fonts FROM " . DB_PREFIX . "mundane WHERE mundane_id = {$_uid} LIMIT 1");
			if ($_prefRs && $_prefRs->Next()) {
				$this->data['ViewerBasicFonts']    = (int)$_prefRs->basic_fonts;
				$this->data['ViewerDyslexiaFonts'] = (int)$_prefRs->dyslexia_fonts;
			}
			$DB->Clear();
		}

		$this->data[ 'controller_title' ] = get_class( $this );
		$this->data[ 'path' ] = [ get_class( $this ), $method ];

		$this->data[ 'menu' ] = [ ];
		$this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
		if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, null, null)) {
			$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin Panel', 'no-crumb' => 'no-crumb' ];
		}

		if ( isset( $this->session->kingdom_id ) ) {
			$this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
			if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, (int)$this->session->kingdom_id, AUTH_EDIT)) {
				$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
			}
		}

		if ( isset( $this->session->park_id ) ) {
			$this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/profile/' . $this->session->park_id, 'display' => $this->session->park_name ];
			if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$this->session->park_id, AUTH_EDIT)) {
				$this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
			}
		}
		// HasAuthority uses the auth ORM which shares the global DB connection.
		// Clear after all auth checks so subclass methods start with a clean DB state.
		global $DB; $DB->Clear();
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
				"SELECT p.kingdom_id, k.parent_kingdom_id FROM ork_mundane m
				 INNER JOIN ork_park p ON p.park_id = m.park_id
				 INNER JOIN ork_kingdom k ON k.kingdom_id = p.kingdom_id
				 WHERE m.mundane_id = {$uid} LIMIT 1"
			);
			if ($hkRow && $hkRow->Size() > 0 && $hkRow->Next()) {
				$this->data['UserKingdomId']       = (int) $hkRow->kingdom_id;
				$this->data['UserParentKingdomId'] = (int) $hkRow->parent_kingdom_id;
			} else {
				$this->data['UserKingdomId']       = 0;
				$this->data['UserParentKingdomId'] = 0;
			}
		} else {
			$this->data['UserKingdomId'] = 0;
		}

		unset( $this->session->kingdom_id );
		unset( $this->session->park_id );
		unset( $this->session->kingdom_name );
		unset( $this->session->park_name );
		$this->data[ 'Tournaments' ] = $this->Report->TournamentReport( [ 'Limit' => 15 ] );
		$this->data[ 'ActiveKingdomSummary' ] = $this->Report->GetActiveKingdomsSummary();
		$eventSummary = $this->Search->Search_Event( null, null, 0, null, null, 15, null, true );
		if (!empty($eventSummary)) {
			global $DB;
			$detailIds = array_filter(array_column($eventSummary, 'NextDetailId'));
			if (!empty($detailIds)) {
				$idList = implode(',', array_map('intval', $detailIds));
				$DB->Clear();
				$rsvpResult = $DB->DataSet("SELECT event_calendardetail_id, COUNT(*) AS cnt FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id IN ($idList) GROUP BY event_calendardetail_id");
				$rsvpCounts = [];
				if ($rsvpResult) {
					while ($rsvpResult->Next()) {
						$rsvpCounts[(int)$rsvpResult->event_calendardetail_id] = (int)$rsvpResult->cnt;
					}
				}
				foreach ($eventSummary as &$ev) {
					$ev['RsvpCount'] = $rsvpCounts[(int)($ev['NextDetailId'] ?? 0)] ?? 0;
				}
				unset($ev);
			}
		}
		$this->data[ 'EventSummary' ] = $eventSummary;
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
