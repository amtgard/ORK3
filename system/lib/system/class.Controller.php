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
        if ($_uid > 0) {
            $this->load_model('Player');
            $prefs = $this->Player->get_viewer_preferences($_uid);
            $this->data['ViewerBasicFonts']    = (int) ($prefs['BasicFonts'] ?? 0);
            $this->data['ViewerDyslexiaFonts'] = (int) ($prefs['DyslexiaFonts'] ?? 0);

            require_once(DIR_UI . 'whats_new_content.php');
            foreach ($WHATS_NEW_ITEMS as $_release) {
                if ($_release['date'] === WHATS_NEW_VERSION) {
                    $this->data['WhatsNewRelease'] = $_release;
                    break;
                }
            }
            if ($this->data['WhatsNewRelease'] !== null) {
                $this->data['ShowWhatsNew'] = !$this->Player->get_whats_new_seen($_uid, WHATS_NEW_VERSION);
            }
        }

        $this->data[ 'controller_title' ] = get_class($this);
        $this->data[ 'path' ] = [ get_class($this), $method ];

        $this->data[ 'menu' ] = [ ];
        $this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
        $this->load_model('Authorization');
        // The standing checks below cost dozens of queries, so they run only where a
        // nav is rendered (not for the JSON-only *Ajax controllers) and only for a
        // viewer with some administrative footprint -- one query that answers "no"
        // exactly for the ordinary player.
        $_navStanding = $_uid > 0
            && substr(get_class($this), -4) !== 'Ajax'
            && $this->Authorization->has_administrative_footprint($_uid);
        // The ORK Administration entry follows Admin::index()'s own front door, so a
        // holder of one delegated global.* capability can reach the tile for it.
        $this->data['NavIsOrkAdmin'] = $_navStanding && $this->has_ork_admin_standing($_uid);
        if ($this->data['NavIsOrkAdmin']) {
            $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin Panel', 'no-crumb' => 'no-crumb' ];
        }

        if (isset($this->session->kingdom_id)) {
            $this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
            // The nav entry must agree with the console's own front door, so an
            // officer holding only an RBAC role still sees it.
            if ($_navStanding && $this->has_kingdom_admin_standing($_uid, (int)$this->session->kingdom_id)) {
                $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
            }
        }

        if (isset($this->session->park_id)) {
            $this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/profile/' . $this->session->park_id, 'display' => $this->session->park_name ];
            if ($_navStanding && $this->has_park_admin_standing($_uid, (int)$this->session->park_id)) {
                $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
            }
        }
        // HasAuthority uses the auth ORM which shares the global DB connection.
        // Clear after all auth checks so subclass methods start with a clean DB state.
        $this->Authorization->clear_db_after_auth_checks();
    }

    /**
     * The installation-scope capabilities the ORK Administration dashboard can open:
     * each one has a tile there. Together with ORK_ADMIN_KEYS_WITHOUT_TILE this is the
     * whole 'global' block of PermissionRegistry (OrkAdminDashboardAccessTest pins the
     * two together); the legacy all-zero admin row satisfies every one.
     */
    protected const ORK_ADMIN_CAPABILITY_KEYS = [
        'global.admin.grant',
        'global.health.view',
        'global.kingdom.manage',
        'global.player.merge',
        'global.bonus_credit.manage',
    ];

    /**
     * Global keys the domain honours that have no dashboard tile yet: log purge and
     * table optimise, the award catalog, attendance classes and the ban switch. They
     * stay out of the front door so a holder is not shown an Admin Panel with nothing
     * on it; a key moves to the list above when its tile ships.
     */
    protected const ORK_ADMIN_KEYS_WITHOUT_TILE = [
        'global.maintenance.run',
        'global.award_catalog.manage',
        'global.attendance_class.manage',
        'global.player.ban',
    ];

    /**
     * Which global.* capabilities does $uid hold? key => bool, each answered by the same
     * gate the lib method behind it uses (RBAC global grant OR the all-zero admin row).
     *
     * A true admin holds them all without a query. Everyone else costs ONE query -- the
     * permissions their global-scope role rows carry -- and only keys found there are
     * confirmed through the full gate (ban and expiry rules included). This runs from
     * the constructor on every page for the nav entry, so asking the gate nine times
     * per request for users who hold nothing would be pure overhead.
     *
     * @return array<string, bool>
     */
    protected function global_capabilities(int $uid): array
    {
        $caps = array_fill_keys(self::ORK_ADMIN_CAPABILITY_KEYS, false);
        if ($uid <= 0) {
            return $caps;
        }
        $this->load_model('Authorization');
        if ($this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            return array_fill_keys(self::ORK_ADMIN_CAPABILITY_KEYS, true);
        }
        $this->load_model('RBACService');
        $held = $this->RBACService->GetEffectivePermissions($uid, 'global', 0);
        foreach (is_array($held) ? $held : [] as $key) {
            if (array_key_exists($key, $caps)) {
                $caps[$key] = $this->Authorization->has_permission_or_authority($uid, $key, 'global', 0, AUTH_ADMIN);
            }
        }
        return $caps;
    }

    /**
     * Front door of the ORK Administration dashboard (Admin::index) and its nav entry:
     * a true all-zero admin, or a holder of any one global.* capability.
     */
    protected function has_ork_admin_standing(int $uid): bool
    {
        return in_array(true, $this->global_capabilities($uid), true);
    }

    /**
     * Does $uid have ANY administrative standing in this kingdom -- i.e. would the
     * kingdom Admin console (Admin/kingdom) let them in?
     *
     * One definition for both the nav entry and the console's front door, so the two
     * cannot drift: the console exposes kingdom details, the park summary and the
     * Reports links, each card separately capability-gated. Besides officer standing,
     * every capability that has a console tile of its own counts: the qualification
     * tests (a non-officer test manager reaches the console for that section), the
     * Court Planner / Recommendations Manager (Court::can_manage) and Bonus Credits
     * (define or grant). Each is the model method the tile's destination checks.
     */
    protected function has_kingdom_admin_standing(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        if ($this->has_kingdom_officer_standing($uid, $kingdomId)) {
            return true;
        }
        $this->load_model('QualTest');
        if (in_array(true, $this->QualTest->capabilities($uid, $kingdomId), true)) {
            return true;
        }
        $this->load_model('Court');
        if ($this->Court->can_manage($uid, $kingdomId, 0)) {
            return true;
        }
        return $this->can_open_kingdom_bonus_credits($uid, $kingdomId);
    }

    /**
     * The officer half of has_kingdom_admin_standing(): ORK admin, or one of the kingdom
     * officer capabilities the console's own modals are built on. The park console's
     * front door accepts this (not the whole kingdom door, whose other arms have no
     * tile on a park console), and the kingdom console gates its officer tiles on it.
     */
    protected function has_kingdom_officer_standing(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        $this->load_model('Authorization');
        if ($this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            return true;
        }
        return $this->Authorization->has_permission_or_authority($uid, 'kingdom.details.edit', 'kingdom', $kingdomId, AUTH_EDIT)
            || $this->Authorization->has_permission_or_authority($uid, 'kingdom.officer.set', 'kingdom', $kingdomId, AUTH_CREATE)
            || $this->Authorization->has_permission_or_authority($uid, 'kingdom.officer.position.manage', 'kingdom', $kingdomId, AUTH_EDIT);
    }

    /**
     * May $uid open the Bonus Credits modal at kingdom scope? BonusCreditAjax::options()
     * accepts a definer or a granter, so this asks the same two model questions.
     */
    protected function can_open_kingdom_bonus_credits(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        $this->load_model('BonusCredit');
        return $this->BonusCredit->can_define($uid, 'kingdom', $kingdomId)
            || $this->BonusCredit->can_grant_in_kingdom($uid, $kingdomId);
    }

    /**
     * Park-specific standing for the park Admin console (Admin/park): park.details.edit
     * at the park (RBAC cascades it from kingdom-scope assignments), or one of the two
     * capabilities with a park-console tile of their own -- the park's Court Planner /
     * Recommendations Manager (Court::can_manage) and Bonus Credits (BonusCredit::can_grant).
     * The console also admits kingdom officer standing over the park's kingdom; the nav
     * sends those officers to the kingdom console instead, so that arm is not repeated.
     */
    protected function has_park_admin_standing(int $uid, int $parkId): bool
    {
        if ($uid <= 0 || !valid_id($parkId)) {
            return false;
        }
        $this->load_model('Authorization');
        if ($this->Authorization->has_permission_or_authority($uid, 'park.details.edit', 'park', $parkId, AUTH_EDIT)) {
            return true;
        }
        $this->load_model('BonusCredit');
        if ($this->BonusCredit->can_grant($uid, $parkId)) {
            return true;
        }
        $kingdomId = (int)$this->BonusCredit->park_kingdom($parkId);
        if (!valid_id($kingdomId)) {
            return false;
        }
        $this->load_model('Court');
        return $this->Court->can_manage($uid, $kingdomId, $parkId);
    }

    /**
     * The whole park-console front door (Admin/park): park standing above, or kingdom
     * OFFICER standing over the park's kingdom. The park profile's Admin link asks this
     * too, so the link appears exactly when the console will open.
     */
    protected function can_open_park_console(int $uid, int $parkId, int $kingdomId): bool
    {
        return $this->has_park_admin_standing($uid, $parkId)
            || (valid_id($kingdomId) && $this->has_kingdom_officer_standing($uid, $kingdomId));
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
