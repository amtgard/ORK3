<?php

class Controller
{
    /**
     * Org name used in the browser tab for the Amtgard-level public site: the
     * front door and the global CMS pages/blog. Kingdom and park sites supply
     * their own name instead (Controller_Site::_bootShell).
     *
     * Deliberately NOT "ORK 3" — that is the internal application brand, and it
     * still prefixes every in-app page. These are public marketing pages whose
     * tab should read "Amtgard - About", not "ORK 3: About".
     */
    public const PUBLIC_SITE_BRAND = 'Amtgard';

    /**
     * The full application brand used in og:site_name / og:title on the public
     * CMS surfaces (front door, global pages, blog, Kingdoms Directory).
     *
     * Distinct from PUBLIC_SITE_BRAND above, which is the SHORT org name shown in
     * the browser tab. default.theme carries its own copies of this string as the
     * last-resort fallback for a surface that publishes no $PageMeta at all —
     * those stay where they are on purpose.
     */
    public const APP_BRAND = 'ORK 3 - Amtgard Online Record Keeper';

    public $data = [ ];
    public $kingdom = null;
    public $view = null;
    public $method = null;
    public $action = null;
    public $settings = null;
    public $session = null;
    public $template = null;

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
            'Controller_CmsAjax',
        ]);
        if (!$_skipTokenCheck && isset($this->session->user_id) && isset($this->session->token)) {
            $_uid_check = (int)$this->session->user_id;
            $_tok_check = $this->session->token;
            // validate_session_token() returns false whenever the stored token is absent
            // or mismatched. Note it does NOT distinguish that from a failed query: PDO
            // runs in ERRMODE_WARNING (Yapo2\YapoMysql), so a transient DB error yields a
            // result set with no rows rather than an exception, and this check treats it
            // as an invalid token — i.e. a DB blip logs the session out.
            $this->load_model('SessionToken');
            if (!$this->SessionToken->validate_session_token($_uid_check, $_tok_check)) {
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

            require(DIR_UI . 'whats_new_content.php'); // require, not require_once — see the note in that file
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

        $this->load_model('Authorization');
        $this->data['NavIsOrkAdmin'] = $_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_ADMIN, 0, AUTH_ADMIN);

        // CMS admin access flag for the user drop-down ("Manage Site Pages").
        // True for any holder of a CMS capability at global scope (and super-admins).
        // Computed LAZILY: only the non-AJAX (nav-rendering) request path shows the
        // user drop-down, so skip the capability probe entirely for the *Ajax
        // controllers (reuse the $_skipTokenCheck detection) — they never render the
        // nav and so would pay a needless CmsAuth query on every XHR. ORK admins are
        // super-admins to CmsAuth, so NavIsOrkAdmin (already resolved above, same
        // HasAuthority(AUTH_ADMIN, 0, AUTH_ADMIN) probe cms_can would repeat)
        // short-circuits the capability probe entirely for them.
        $this->data['CanManageCms'] = false;
        if ($_uid > 0 && !$_skipTokenCheck) {
            if ($this->data['NavIsOrkAdmin']) {
                $this->data['CanManageCms'] = true;
            } else {
                $this->load_model('CmsAuth');
                if (isset($this->CmsAuth)) {
                    // One capability probe (not a loop): every CMS role from
                    // contributor up holds page.create — so a single check answers
                    // "can this user reach the CMS admin?" without N grant/auth
                    // queries on every request.
                    $this->data['CanManageCms'] = (bool) $this->CmsAuth->cms_can(
                        $_uid,
                        'page.create',
                        ['type' => 'global', 'id' => 0]
                    );
                }
            }
        }

        $this->data[ 'controller_title' ] = get_class($this);
        $this->data[ 'path' ] = [ get_class($this), $method ];

        $this->data[ 'menu' ] = [ ];
        $this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
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

    /**
     * Per-session CSRF synchronizer token. Minted once and persisted in the
     * session; the same value is emitted to authenticated pages (for JS to send
     * back on state-changing requests) and validated server-side. Cryptographically
     * random; constant-time compared at the validation site.
     *
     * @return string 64-hex-char token
     */
    protected function _csrfToken()
    {
        if (empty($this->session->csrf_token)) {
            $this->session->csrf_token = bin2hex(random_bytes(32));
        }
        return (string) $this->session->csrf_token;
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

    /**
     * The default landing action, inherited verbatim by every controller that
     * declares no index() of its own (17 of them) as well as by the site root.
     *
     * It is TWO separable halves, split out below so a surface can reuse one
     * without the other: the shared home data every inheritor expects, and the
     * front-door payload only the site root renders. index() itself must keep
     * calling both, in this order, so the front door and all the index()-less
     * controllers stay byte-identical.
     */
    public function index($action = null)
    {
        $this->_indexCommonData();
        $this->_indexFrontDoor();
    }

    /**
     * Half 1 of index(): the shared landing data — the viewer's home kingdom,
     * the tournament/kingdom/event/recap summaries, the viewer display name and
     * the top-level menu shape. No front-door / CMS content.
     *
     * Controller_Directory calls THIS ONLY: it renders the same summaries under
     * its own identity and must not pull in the front-door payload.
     */
    protected function _indexCommonData()
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
            $this->data['UserKingdomId']       = 0;
            $this->data['UserParentKingdomId'] = 0;
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

        // Display name for the member bar (logged-in only)
        $this->data[ 'ViewerName' ] = '';
        if ($this->data['LoggedIn'] && isset($this->session->user_id)) {
            $this->load_model('Player');
            $this->data[ 'ViewerName' ] = $this->Player->get_viewer_display_name((int) $this->session->user_id);
        }

        $this->data[ 'menu' ][ 'home' ] = [ 'url' => UIR, 'display' => 'Home <i class="fas fa-home"></i> ', 'no-crumb' => 'no-crumb' ];
        if ($this->data['LoggedIn']) {
            $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin', 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
        }
        unset($this->data[ 'menu' ][ 'kingdom' ]);
        unset($this->data[ 'menu' ][ 'park' ]);
    }

    /**
     * Half 2 of index(): the FRONT-DOOR payload — the CMS-backed home blocks (or
     * the hardcoded Model_FrontDoor defaults), the front-door theme tokens, the
     * "Amtgard - {Page}" tab identity, the home-page edit FAB, and the home
     * canonical/OG. Only the site root renders these.
     */
    protected function _indexFrontDoor()
    {
        // ---- Front door (now CMS-backed, with hardcoded defaults as fallback) ----
        // Prefer the CMS-managed `home` system page when it exists and has blocks;
        // otherwise fall back to the hardcoded Model_FrontDoor defaults so the front
        // door always renders even before the home page is seeded.
        $this->data[ 'IsFrontDoor' ] = true;
        // Browser-tab identity: public site pages read "{Org} - {Page}". The front
        // door is the Amtgard-level site, so "Amtgard - Home". See
        // Controller::PUBLIC_SITE_BRAND and the <title> block in default.theme.
        $this->data[ 'SiteTitleOrg' ] = self::PUBLIC_SITE_BRAND;
        $frontDoorBlocks = null;
        $home = null;
        $_ogImage = '';
        // Every CMS-library read on the front door sits inside ONE boundary: the
        // site root must still render from the hardcoded Model_FrontDoor defaults
        // if any of them throws, not just when the bundle comes back empty.
        try {
            $this->_attachFrontDoorTheme();
            $this->load_model('CmsPage');
            // C1: one GhettoCache-backed bundle (home page row + its enabled blocks)
            // instead of a separate GetHomePage() + GetBlocks() pair. A null slug means
            // "the scope's home page".
            $bundle = $this->CmsPage->get_page_with_blocks('global', 0, null);
            $home = (is_array($bundle) && !empty($bundle['page'])) ? $bundle['page'] : null;
            $homeBlocks = (is_array($bundle) && isset($bundle['blocks']) && is_array($bundle['blocks']))
                ? $bundle['blocks'] : array();
            if (!empty($home) && !empty($home['page_id'])) {
                if (!empty($homeBlocks)) {
                    $frontDoorBlocks = $homeBlocks;
                }
                // Floating editor FAB on the front-door home for CMS editors
                // (rendered by default.theme from cmsEditUrl). NOTE: $_uid from the
                // bootstrap method is out of scope here, so read the session directly.
                $fabUid = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
                if ($fabUid > 0) {
                    $this->load_model('CmsAuth');
                    if (isset($this->CmsAuth) && $this->CmsAuth->cms_can($fabUid, 'page.edit', ['type' => 'global', 'id' => 0])) {
                        $this->data['cmsEditUrl'] = UIR . 'Cms/edit/' . (int) $home['page_id'];
                        $this->data['cmsEditTip'] = 'Edit home page';
                    }
                }
            }
            // C6 (og:image half): the home page's hero, when one is set. Inside the
            // boundary because it is another CMS-library read; the theme's default
            // image covers the failure case.
            if (!empty($home) && !empty($home['hero_media_id'])) {
                $this->load_model('CmsMedia');
                if (isset($this->CmsMedia)) {
                    $_hm = $this->CmsMedia->get_media((int) $home['hero_media_id']);
                    if (is_array($_hm) && !empty($_hm['url'])) {
                        $_ogImage = CmsMeta::Absolutize((string) $_hm['url'], CmsMeta::Origin());
                    }
                }
            }
        } catch (\Throwable $e) {
            // Degrade to the hardcoded front door rather than fataling the site root.
            $frontDoorBlocks = null;
            $_ogImage = '';
            unset($this->data['cmsEditUrl'], $this->data['cmsEditTip']);
        }
        if ($frontDoorBlocks === null) {
            $this->load_model('FrontDoor');
            $frontDoorBlocks = $this->FrontDoor->GetContent([
                'logged_in'  => (bool) $this->data['LoggedIn'],
                'kingdom_id' => (int) ($this->data['UserKingdomId'] ?? 0),
            ]);
        }
        $this->data[ 'FrontDoor' ] = $frontDoorBlocks;

        // C6: per-page canonical + OG for the front-door HOME. The hardcoded ORK
        // branding in default.theme is now a FALLBACK, overridden here so the home
        // canonical is the site root and og:image can use the home page's hero
        // when one is set (else the theme falls back to the ORK default image).
        $_origin = CmsMeta::Origin();
        // No Host header (CLI/test render) → no canonical at all rather than a
        // bare '/' that would resolve against whatever host reads the page.
        $this->data['PageMeta'] = CmsMeta::Build(array(
            'canonical'   => ($_origin !== '') ? ($_origin . '/') : '',
            'og_type'     => 'website',
            'og_title'    => self::APP_BRAND,
            'og_desc'     => 'The Online Record Keeper for the Amtgard International LARP.',
            'og_image'    => $_ogImage,
            'og_sitename' => self::APP_BRAND,
        ));
    }

    /** Resolve the active front-door theme into $data['fdThemeCss'] (global scope, v1). */
    protected function _attachFrontDoorTheme()
    {
        $this->load_model('CmsTheme');
        $css = (string) $this->CmsTheme->get_active_css('global', 0);
        if ($css !== '') {
            $this->data['fdThemeCss'] = $css;
        }
    }

    /**
     * The framework RENDER step (index.php), deliberately named apart from the
     * action namespace: a controller action may legitimately be called "view"
     * (Page/view, Site/view), and when the render step was also view() a
     * zero-arg 2-segment route was indistinguishable from the render call.
     */
    public function render()
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

    /**
     * Backwards-compatible alias for render(). Kept so existing zero-arg
     * $this->view() / parent::view() callers keep rendering.
     */
    public function view()
    {
        return $this->render();
    }

    public function controller_class()
    {
        $parts = explode('_', get_class($this));
        return implode('_', array_slice($parts, 1));
    }
}
