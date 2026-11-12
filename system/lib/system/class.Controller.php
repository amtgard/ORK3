<?php

class Controller
{
    public $data = [ ];
    public $kingdom = null;
    public $view = null;
    public $method = null;
    public $action = null;
    public $settings = null;
    public $session = null;
    public $template = null;

    // Status 5 is NoAuthorization -- "you are not allowed to do that". It is NOT
    // "your session expired". Controllers uniformly mapped it to a redirect to
    // Login/login/..., so a still-logged-in officer who hit a permission boundary
    // was dropped on a bare login page with no message and read it as having been
    // logged out. It paired especially badly with pages that render every control
    // and only reject the click server-side.
    //
    // Only genuinely unauthenticated visitors are sent to log in; everyone else
    // gets told what actually happened.
    public function no_authorization($login_route, $detail = null)
    {
        if (!isset($this->session->user_id) || (int) $this->session->user_id <= 0) {
            header('Location: ' . UIR . 'Login/login/' . ltrim($login_route, '/'));
            exit;
        }
        $message = (is_string($detail) && trim($detail) !== '')
            ? trim($detail)
            : 'You do not have permission to perform that action.';
        $this->data[ 'Error' ] = $message;
        return $message;
    }

    // The single source of a release's Release Notes anchor id ("release-<slug>"),
    // shared by the Release Notes cards and the What's New modal's link to them.
    protected static function release_anchor($version)
    {
        return 'release-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($version));
    }

    // The single source of "is the ORKPLE II holiday event live": the ORKPLE_ENABLED kill
    // switch, then the orkple_preview=1 cookie, then today's server date inside the 'orkple'
    // release's [event_start, event_end]. The kill switch beats the cookie.
    public static function orkple_live(array $releases)
    {
        if (!defined('ORKPLE_ENABLED') || !ORKPLE_ENABLED) {
            return false;
        }
        if (($_COOKIE['orkple_preview'] ?? '') === '1') {
            return true;
        }
        $today = date('Y-m-d');
        foreach ($releases as $release) {
            if (!empty($release['orkple']) && !empty($release['event_start']) && !empty($release['event_end'])) {
                return $today >= $release['event_start'] && $today <= $release['event_end'];
            }
        }
        return false;
    }

    public function __construct($method = null, $action = null)
    {
        $this->method = is_null($method) ? 'index' : $method;
        $this->action = $action;

        global $Settings, $Session, $Request;
        $this->settings = $Settings;
        $this->session = $Session;
        $this->request = $Request;

        $this->load_model($this->controller_class());

        $this->Report = new APIModel('Report');
        $this->Search = new JSONModel('Search');
        $this->data[ 'no_index' ] = false;
        // Per-page OpenGraph overrides for link-preview cards (Discord etc.);
        // rendered by ork_og_meta_tags() in the theme head.
        $this->data[ 'og' ] = array();
        // Per-page schema.org JSON-LD (search-engine structured data).
        $this->data[ 'jsonld' ] = null;

        if (get_class($this) == "Controller") {
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
            'Controller_EventEmbed',
            'Controller_KingdomAjax',
            'Controller_ParkAjax',
            'Controller_PlayerAjax',
            'Controller_AdminAjax',
            'Controller_WnAjax',
        ]);
        if (!$_skipTokenCheck && isset($this->session->user_id) && isset($this->session->token)) {
            $_uid_check = (int)$this->session->user_id;
            $_tok_check = $this->session->token;
            // Multi-device: per-request validation runs against ork_session (up to
            // Authorization::MAX_SESSIONS_PER_USER concurrent device sessions), not
            // the single vestigial ork_mundane.token
            // slot. ValidateSessionByToken also enforces expiry and slides last_seen.
            $_owner = Ork3::$Lib->authorization->ValidateSessionByToken($_tok_check);
            if ($_owner === 0 || $_owner !== $_uid_check) {
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
        }

        $_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        // Viewer accessibility-font preferences — read once and surface to every template
        $this->data['ViewerBasicFonts']    = 0;
        $this->data['ViewerDyslexiaFonts'] = 0;
        $this->data['ShowWhatsNew']        = false;
        $this->data['WhatsNewRelease']     = null;

        // `require`, not `require_once`: the file is safe to read twice, and a subclass or a
        // second controller in the same request still needs $WHATS_NEW_ITEMS in its scope.
        require(DIR_UI . 'whats_new_content.php');
        $this->data['OrkpleEnabled'] = (bool) ORKPLE_ENABLED;
        $this->data['OrkpleLive']    = self::orkple_live($WHATS_NEW_ITEMS);

        if ($_uid > 0) {
            $this->load_model('Player');
            $prefs = $this->Player->get_viewer_preferences($_uid);
            $this->data['ViewerBasicFonts']    = (int) ($prefs['BasicFonts'] ?? 0);
            $this->data['ViewerDyslexiaFonts'] = (int) ($prefs['DyslexiaFonts'] ?? 0);

            foreach ($WHATS_NEW_ITEMS as $_release) {
                if ($_release['date'] === WHATS_NEW_VERSION) {
                    $_release['anchor'] = self::release_anchor($_release['version']);
                    $this->data['WhatsNewRelease'] = $_release;
                    break;
                }
            }
            // An ORKPLE release outside its event window (or with the kill switch off) shows no
            // modal and marks nothing seen, so the CRT reveal still appears when the window opens.
            // &wn=0 (the modal's own links to Release Notes) skips the modal for that one
            // request, so it never covers the card it sent the viewer to. Nothing is marked seen.
            if ($this->data['WhatsNewRelease'] !== null
                && (string) ($_GET['wn'] ?? '') !== '0'
                && !(!empty($this->data['WhatsNewRelease']['orkple']) && !$this->data['OrkpleLive'])) {
                $this->data['ShowWhatsNew'] = !$this->Player->get_whats_new_seen($_uid, WHATS_NEW_VERSION);
            }
        }

        $this->data[ 'controller_title' ] = get_class($this);
        $this->data[ 'path' ] = [ get_class($this), $method ];

        $this->data[ 'menu' ] = [ ];
        $this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
        $this->load_model('Authorization');
        $this->data['NavIsOrkAdmin'] = $_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_ADMIN, 0, AUTH_ADMIN);
        if ($_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_ADMIN, null, null)) {
            $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin Panel', 'no-crumb' => 'no-crumb' ];
        }

        if (isset($this->session->kingdom_id)) {
            $this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
            if ($_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_KINGDOM, (int)$this->session->kingdom_id, AUTH_EDIT)) {
                $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
            }
        }

        if (isset($this->session->park_id)) {
            $this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/profile/' . $this->session->park_id, 'display' => $this->session->park_name ];
            if ($_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_PARK, (int)$this->session->park_id, AUTH_EDIT)) {
                $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
            }
        }
        // HasAuthority uses the auth ORM which shares the global DB connection.
        // Clear after all auth checks so subclass methods start with a clean DB state.
        $this->Authorization->clear_db_after_auth_checks();
    }

    public function load_model($name)
    {
        if (file_exists(DIR_MODEL . 'model.' . $name . '.php')) {
            require_once(DIR_MODEL . 'model.' . $name . '.php');
            $model_name = 'Model_' . $name;
            $this->$name = new $model_name();
        }
    }

    public function __call($method, $action)
    {
    }

    public function encode_image_file($tmpname)
    {
        $imgbinary = fread(fopen($tmpname, "r"), filesize($tmpname));
        return base64_encode($imgbinary);
    }

    public function index($action = null)
    {
        // Determine the logged-in user's home kingdom from their profile in the DB.
        // Fall back to the session-cached value only when not logged in.
        if ($this->data['LoggedIn'] && isset($this->session->user_id)) {
            $uid = (int) $this->session->user_id;
            $this->load_model('Player');
            $home = $this->Player->get_home_kingdom($uid);
            $this->data['UserKingdomId']       = (int) ($home['KingdomId'] ?? 0);
            $this->data['UserParentKingdomId'] = (int) ($home['ParentKingdomId'] ?? 0);
        } else {
            $this->data['UserKingdomId'] = 0;
        }

        unset($this->session->kingdom_id);
        unset($this->session->park_id);
        unset($this->session->kingdom_name);
        unset($this->session->park_name);
        $this->data[ 'Tournaments' ] = $this->Report->TournamentReport([ 'Limit' => 15 ]);
        $this->data[ 'ActiveKingdomSummary' ] = $this->Report->GetActiveKingdomsSummary();
        $this->load_model('Recap');
        $this->data[ 'week_recap' ] = $this->Recap->get();
        $eventSummary = $this->Search->Search_Event(null, null, 0, null, null, 15, null, true);
        if (!empty($eventSummary)) {
            $detailIds = array_filter(array_column($eventSummary, 'NextDetailId'));
            if (!empty($detailIds)) {
                $this->load_model('Event');
                $rsvpCounts = $this->Event->get_rsvp_total_counts_batch($detailIds);
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
        unset($this->data[ 'menu' ][ 'kingdom' ]);
        unset($this->data[ 'menu' ][ 'park' ]);
    }

    public function view()
    {
        $V = null;
        if (is_null($this->view)) {
            logtrace("Controller: view(): $this->template, " . $this->controller_class() . ", $this->method, $this->action", null);
            $V = new View($this->template, $this->controller_class(), $this->method, $this->action);
            $V->__setttings = $this->settings;
        } else {
            logtrace("Controller: view(): $this->template, " . $this->controller_class() . ", $this->method, $this->action", null);
            $V = new View($this->template, $this->controller_class(), $this->method, $this->action);
            $V->__setttings = $this->settings;
        }
        logtrace("Controller view(): data, {$this->kingdom}", $this->data);
        $CONTENT = $V->view($this->data, $this->kingdom);

        return $CONTENT;
    }

    public function controller_class()
    {
        $parts = explode('_', get_class($this));
        return implode('_', array_slice($parts, 1));
    }
}
