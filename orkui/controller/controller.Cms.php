<?php

require_once __DIR__ . '/trait.CmsScope.php';

/**
 * Controller_Cms — OGRE admin (page-rendering surfaces).
 * OGRE = Online Gallery and Resource Engine, the product name for the CMS; the
 * `Cms*` class / `ork_cms_*` table names are the internal identifiers for it.
 *
 * Routes:
 *   Cms/index            → page list (any CMS capability / super-admin)
 *   Cms/edit/{id|new}    → block editor for a page (page.edit, or page.create for new)
 *   Cms/preview/{id}     → render the page's CURRENT draft blocks with a preview banner (page.edit)
 *
 * Auth: every action gates on CmsAuth->cms_can($uid, <capability>, GLOBAL_SCOPE).
 * v2 is global scope only (the data model carries scope_type/scope_id for later).
 * Unauthorized / not-logged-in → redirect to Login (page surfaces never emit JSON).
 *
 * Conventions: thin controller (no raw $DB; all DB work via the CmsPage lib).
 * Templates are PLAIN PHP (extract()+include), set via $this->template.
 */
class Controller_Cms extends Controller
{
    use CmsScopeContext;

    /**
     * Default scope when no ?scope= selector is present — the global front door.
     * Phase 3 threads a per-request scope via _resolveScope(); this constant is
     * only the fallback shape and the byte-for-byte legacy behavior.
     */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    /**
     * Per-request capability cache: ['is_super' => bool, 'caps' => string[]].
     * Keyed by uid so a single request can't bleed between users.
     * @var array
     */
    private $_capCache = array();

    /** Memoized site row for the current non-global scope (false = not yet looked up). */
    private $_scopeSiteMemo = false;

    /**
     * #21: per-request memo of the block catalog. _blockCatalog() is otherwise
     * recomputed ~15x per Cms/edit — once per _starter() call inside _pageTypes()
     * (each starter re-derived the dynamic-type set from a fresh catalog build) —
     * each rebuild doing a file_exists() probe per block type. Cached here so the
     * whole editor load costs exactly one catalog build. (null = not yet built.)
     * @var array|null
     */
    private $_blockCatalogMemo = null;

    public function __construct($call = null, $action = null)
    {
        parent::__construct($call, $action);
        // CMS admin is an org-level surface — drop the kingdom/park crumbs.
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->load_model('CmsAuth');
        $this->load_model('CmsPage');
        $this->load_model('CmsPost');
        $this->load_model('CmsNav');
        // CSRF synchronizer token for the editor's state-changing requests.
        $this->data['CmsCsrf'] = $this->_csrfToken();
    }

    /* ------------------------------------------------------------------ *
     * Dashboard — CMS landing / overview
     * ------------------------------------------------------------------ */

    public function dashboard($action = null)
    {
        $uid = $this->_uid();
        // The dashboard is visible to anyone holding ANY CMS capability (or super-admin).
        $scope = $this->_scopeOrDenyWithCap($uid, function ($uid, $scope) {
            return $this->_hasAnyCmsCapability($uid, $scope);
        });
        if ($scope === false) {
            return;
        }

        // Entry point: opening the dashboard in a non-global org scope lazily
        // provisions the org's site row (status='unbuilt'; seeding is Phase 5).
        // Idempotent — a second open returns the existing row and no-ops.
        if (!$this->_scopeIsGlobal($scope)) {
            $this->_loadSiteContext($uid, $scope, true);
        }

        $this->template = 'Cms_dashboard.tpl';
        $this->data['page_title'] = 'OGRE Dashboard';

        $sf = $this->_scopeFilters($scope);

        // ---- Pages/posts overview: status-broken-down counts via a lightweight
        //      aggregate (GROUP BY status) instead of fetching every row just to
        //      tally. Each returns an assoc status=>count plus a 'total'; drafts =
        //      everything not published (matches the prior count semantics).
        $pageCounts = $this->CmsPage->CountPages($sf['scope_type'], $sf['scope_id']);
        $pageCounts = is_array($pageCounts) ? $pageCounts : array();
        $pageCount  = (int)($pageCounts['total'] ?? array_sum(array_map('intval', $pageCounts)));
        $pageDrafts = max(0, $pageCount - (int)($pageCounts['published'] ?? 0));

        $postCounts = $this->CmsPost->CountPosts($sf['scope_type'], $sf['scope_id']);
        $postCounts = is_array($postCounts) ? $postCounts : array();
        $postCount  = (int)($postCounts['total'] ?? array_sum(array_map('intval', $postCounts)));
        $postDrafts = max(0, $postCount - (int)($postCounts['published'] ?? 0));

        // ---- "Continue editing": newest pages + posts. Fetch only what the card
        //      renders (6 each, already ORDER BY updated_at DESC) — a bounded read,
        //      not the whole set. ----
        $pages = $this->CmsPage->list_pages($sf + array('limit' => 6));
        $pages = is_array($pages) ? $pages : array();

        $postsRes = $this->CmsPost->list_posts(array('includeDrafts' => true, 'limit' => 6) + $sf);
        $posts    = (is_array($postsRes) && isset($postsRes['rows']) && is_array($postsRes['rows'])) ? $postsRes['rows'] : array();

        // ---- "Continue editing": merge newest pages + posts by updated_at, take ~6 ----
        $recent = array();
        foreach (array_slice($pages, 0, 6) as $p) {
            $recent[] = array(
                'kind'       => 'page',
                'id'         => (int)($p['page_id'] ?? 0),
                'title'      => (string)($p['title'] ?? '(untitled)'),
                'status'     => (string)($p['status'] ?? 'draft'),
                'updated_at' => (string)($p['updated_at'] ?? ''),
                'edit_href'  => UIR . 'Cms/edit/' . (int)($p['page_id'] ?? 0) . $this->_scopeQuery($scope),
            );
        }
        foreach (array_slice($posts, 0, 6) as $p) {
            $recent[] = array(
                'kind'       => 'post',
                'id'         => (int)($p['post_id'] ?? 0),
                'title'      => (string)($p['title'] ?? '(untitled)'),
                'status'     => (string)($p['status'] ?? 'draft'),
                'updated_at' => (string)($p['updated_at'] ?? ''),
                'edit_href'  => UIR . 'Cms/editpost/' . (int)($p['post_id'] ?? 0) . $this->_scopeQuery($scope),
            );
        }
        // Newest-first across both kinds; keep the 6 most recently touched.
        usort($recent, function ($a, $b) {
            return strcmp((string)$b['updated_at'], (string)$a['updated_at']);
        });
        $recent = array_slice($recent, 0, 6);

        // ---- #09 usage analytics: scope rollup + most-viewed content. Reads are
        //      best-effort in the lib (pre-migration → zeros/empty), so this never
        //      breaks the dashboard. "Most viewed" links into the editor, mirroring
        //      the recent-items list, so an officer can act on the feedback. ----
        $this->load_model('CmsView');
        $viewSummary = $this->CmsView->get_scope_view_summary($sf['scope_type'], $sf['scope_id']);
        $viewSummary = is_array($viewSummary) ? $viewSummary : array();

        $topRows = $this->CmsView->get_view_stats($sf['scope_type'], $sf['scope_id'], 8);
        $topRows = is_array($topRows) ? $topRows : array();
        $topViewed = array();
        foreach ($topRows as $tv) {
            $kind = ((string)($tv['entity_type'] ?? 'page') === 'post') ? 'post' : 'page';
            $id   = (int)($tv['entity_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $topViewed[] = array(
                'kind'      => $kind,
                'id'        => $id,
                'title'     => (string)($tv['title'] ?? '(untitled)'),
                'total'     => (int)($tv['total'] ?? 0),
                'recent'    => (int)($tv['recent'] ?? 0),
                'edit_href' => UIR . ($kind === 'post' ? 'Cms/editpost/' : 'Cms/edit/') . $id . $this->_scopeQuery($scope),
            );
        }

        $this->data['ViewSummary'] = array(
            'total'       => (int)($viewSummary['total'] ?? 0),
            'recent'      => (int)($viewSummary['recent'] ?? 0),
            'recent_days' => (int)($viewSummary['recent_days'] ?? 30),
        );
        $this->data['TopViewed'] = $topViewed;

        // #128: "Top content (30 days)" panel — the most-viewed pages/posts over
        // the rolling window as {title,url,count}, url deep-linking into the editor
        // (mirrors TopViewed so an officer can act on it). Best-effort in the lib
        // (pre-migration → empty), so this never breaks the dashboard.
        $topContentRows = $this->CmsView->GetTopContent($sf['scope_type'], $sf['scope_id'], 30, 5);
        $topContent = array();
        foreach ((is_array($topContentRows) ? $topContentRows : array()) as $tc) {
            $kind = ((string)($tc['entity_type'] ?? 'page') === 'post') ? 'post' : 'page';
            $id   = (int)($tc['entity_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $topContent[] = array(
                'title' => (string)($tc['title'] ?? '(untitled)'),
                'url'   => UIR . ($kind === 'post' ? 'Cms/editpost/' : 'Cms/edit/') . $id . $this->_scopeQuery($scope),
                'count' => (int)($tc['count'] ?? 0),
            );
        }
        $this->data['topContent'] = $topContent;

        $this->data['Recent'] = $recent;
        $this->data['Stats']  = array(
            'pages'       => $pageCount,
            'posts'       => $postCount,
            'page_drafts' => $pageDrafts,
            'post_drafts' => $postDrafts,
            'drafts'      => $pageDrafts + $postDrafts,
        );
        $this->data['PageTypes']  = $this->_pageTypes();
        $this->data['TypeLabels'] = $this->_typeLabels();
        $this->data['Caps']       = $this->_capFlags($uid, $scope);
        $this->data['Greet']      = $this->_greeting();

        // Home-page chooser source for the site-settings form on the site card
        // (org scope only — the global front door has no /k/{slug} site row).
        // Only loaded for users who can actually see the form — otherwise this is
        // a 500-row read on every org dashboard render for nothing.
        if (!$this->_scopeIsGlobal($scope) && !empty($this->data['CanEditSite'])) {
            $sitePages = $this->CmsPage->list_pages($sf);
            $sitePages = is_array($sitePages) ? $sitePages : array();
            // list_pages() is capped (LIMIT 500, updated_at DESC), so a rarely
            // edited home page can fall off the list — the picker would then show
            // nothing selected, i.e. "no home page chosen", which is untrue. Pull
            // that one row by key and inject it so the select states ground truth.
            $homeId = (int)($this->data['CmsSite']['home_page_id'] ?? 0);
            if ($homeId > 0) {
                $found = false;
                foreach ($sitePages as $sp) {
                    if ((int)($sp['page_id'] ?? 0) === $homeId) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $homeRow = $this->CmsPage->get_page($homeId);
                    if (
                        is_array($homeRow)
                        && (string)($homeRow['scope_type'] ?? '') === (string)$sf['scope_type']
                        && (int)($homeRow['scope_id'] ?? 0) === (int)$sf['scope_id']
                    ) {
                        array_unshift($sitePages, $homeRow);
                    }
                }
            }
            $this->data['PickerPages'] = $sitePages;
        }
    }

    /**
     * Load the org site row + publish-gate flag for the dashboard's site card
     * (non-global scope only). Optionally EnsureSite first (dashboard entry).
     * Sets $this->data['CmsSite'] and ['CanPublishSite'].
     *
     * @param int   $uid
     * @param array $scope     resolved, authorized non-global scope
     * @param bool  $ensure    provision the row if missing (idempotent)
     * @return void
     */
    private function _loadSiteContext($uid, $scope, $ensure = false)
    {
        $this->load_model('CmsSite');
        $type = (string)$scope['type'];
        $id   = (int)$scope['id'];
        $site = $ensure
            ? $this->CmsSite->ensure_site($type, $id, $uid)
            : $this->CmsSite->get_site_for_scope($type, $id);
        $this->data['CmsSite'] = is_array($site) ? $site : array();
        // Site publish/unpublish is an AUTH_ADMIN-tier action (monarch/regent).
        // page.publish bridges to AUTH_ADMIN on the scope, so it is the correct
        // gate: an AUTH_EDIT-only officer sees the "must be published" state.
        $this->data['CanPublishSite'] = $this->CmsAuth->cms_can($uid, 'page.publish', $scope);
        // Site settings (name / home page) is an edit-tier action. Use the SAME
        // bridge-aware source as the publish gate above: GetUserCapabilities
        // (behind $Caps) reads grant rows only, so an officer whose CMS rights
        // come from OFFICE would see "Publish site" but no "Site settings".
        $this->data['CanEditSite'] = $this->CmsAuth->cms_can($uid, 'page.edit', $scope);
    }

    /**
     * Plain time-of-day greeting for the dashboard masthead. No archaic/flavor
     * copy — this is a straightforward internal CMS.
     */
    private function _greeting()
    {
        $hr = (int)date('G');
        if ($hr < 5) {
            return 'Good evening';
        }
        if ($hr < 12) {
            return 'Good morning';
        }
        if ($hr < 17) {
            return 'Good afternoon';
        }
        return 'Good evening';
    }

    /* ------------------------------------------------------------------ *
     * All sites — GLOBAL super-admin overview of every started org site
     * ------------------------------------------------------------------ */

    /**
     * Cms/sites — the cross-org "CMS Sites" overview. SUPER-ADMIN ONLY: a
     * non-super-admin is bounced to Login via _denyRedirect() with NO site data
     * ever assembled (the ListAllSites query is never reached), so there is no
     * cross-org data leak. This is a GLOBAL page — it deliberately does NOT use
     * _scopeOrDeny/_applyScopeData (those thread a single org scope); it lists
     * every scope at once and builds per-row scope selectors instead.
     */
    public function sites($action = null)
    {
        $uid = $this->_uid();
        // Hard gate: only an ORK super-admin (all-zero-scope AUTH_ADMIN row) may
        // see the cross-org overview. Bounce everyone else before any data load.
        if (!$this->CmsAuth->IsSuperAdmin($uid)) {
            return $this->_denyRedirect();
        }

        $this->load_model('CmsSite');

        $this->template = 'Cms_sites.tpl';
        $this->data['page_title'] = 'OGRE Sites';
        $this->data['cmsActive']  = 'sites';

        // ---- Pinned front-door summary (Amtgard International) -------------
        // The global front door is NOT an ork_cms_site row — its home lives in
        // ork_cms_page (scope_type='global'). It is always live; its admin is the
        // scopeless dashboard and its public URL is the site root.
        $globalCounts = $this->CmsSite->global_page_counts();
        $globalCounts = is_array($globalCounts) ? $globalCounts : array();
        $this->data['FrontDoor'] = array(
            'name'            => 'Amtgard International',
            'subtitle'       => 'Global front door',
            'pages_total'     => (int)($globalCounts['pages_total'] ?? 0),
            'pages_published' => (int)($globalCounts['pages_published'] ?? 0),
            'posts_total'     => (int)($globalCounts['posts_total'] ?? 0),
            // Scopeless dashboard = the global front-door CMS admin.
            'manage_url'      => UIR . 'Cms/dashboard',
            // Public front door is the site root, not a Site/view route.
            'visit_url'       => HTTP_UI_REMOTE,
        );

        // ---- Every started org site, split into Kingdoms / Parks ----------
        $all = $this->CmsSite->list_all_sites();
        $all = is_array($all) ? $all : array();

        $kingdoms = array();
        $parks    = array();
        $kingdomHasSite = array(); // scope_id => true, to flag the provision picker

        foreach ($all as $row) {
            $type    = (string)($row['scope_type'] ?? 'kingdom');
            $scopeId = (int)($row['scope_id'] ?? 0);
            $slug    = (string)($row['slug'] ?? '');
            $sel     = (($type === 'park') ? 'p:' : 'k:') . $scopeId;

            $view = array(
                'site_id'         => (int)($row['site_id'] ?? 0),
                'scope_type'      => $type,
                'scope_id'        => $scopeId,
                'scope_sel'       => $sel,
                'org_name'        => (string)($row['org_name'] ?? ''),
                'slug'            => $slug,
                'status'          => (string)($row['status'] ?? 'unbuilt'),
                'pages_total'     => (int)($row['pages_total'] ?? 0),
                'pages_published' => (int)($row['pages_published'] ?? 0),
                'posts_total'     => (int)($row['posts_total'] ?? 0),
                'updated_at'      => (string)($row['updated_at'] ?? ''),
                // Manage = the scoped CMS dashboard for this org.
                'manage_url'      => UIR . 'Cms/dashboard&scope=' . $sel,
                // Visit = the org's public site home (empty when no slug yet).
                'visit_url'       => ($slug !== '') ? UIR . 'Site/view/' . rawurlencode($slug) : '',
            );

            if ($type === 'park') {
                $parks[] = $view;
            } else {
                $kingdoms[] = $view;
                $kingdomHasSite[$scopeId] = true;
            }
        }

        $this->data['KingdomSites'] = $kingdoms;
        $this->data['ParkSites']    = $parks;

        // ---- Provisioning picker: every active kingdom, name-sorted, with a
        //      flag for those that already have a site (so the picker can nudge
        //      toward un-provisioned orgs). Opening a scoped dashboard auto-fires
        //      EnsureSite, so provisioning needs no dedicated endpoint. ----
        // #17: the picker only needs id + name (+ the has_site flag). GetKingdoms()
        // loads Common::get_configs() per kingdom (an AtlasColor lookup we never use
        // here) — an N-config fan-out. No light id+name lister exists on the Kingdom
        // lib, so read the minimal active set directly (a parameterless, static
        // query — no user input reaches it, so nothing to bind/escape). Ordered in
        // SQL, so no post-sort is needed.
        global $DB;
        $DB->Clear();
        $krows = $DB->DataSet(
            'SELECT kingdom_id, name FROM ' . DB_PREFIX . 'kingdom'
            . " WHERE active = 'Active' ORDER BY name ASC"
        );
        $pick = array();
        if ($krows !== false) {
            while ($krows->Next()) {
                $kid = (int)$krows->kingdom_id;
                if ($kid <= 0) {
                    continue;
                }
                $pick[] = array(
                    'id'       => $kid,
                    'name'     => (string)$krows->name,
                    'has_site' => !empty($kingdomHasSite[$kid]),
                );
            }
        }
        $DB->Clear();
        $this->data['ProvisionKingdoms'] = $pick;

        // Rail flag: this is a super-admin so the "All sites" entry is shown.
        $this->data['Caps'] = $this->_capFlags($uid);
        // #129: held-capability set for window.CMS_CAPS (super-admin → full set).
        $this->data['CmsCaps'] = $this->_capList($uid);
    }

    /* ------------------------------------------------------------------ *
     * Media library
     * ------------------------------------------------------------------ */

    public function media($action = null)
    {
        $uid = $this->_uid();
        // Media management is its own capability (super-admins pass via _capFlags).
        $scope = $this->_scopeOrDenyWithCap($uid, function ($uid, $scope) {
            return !empty($this->_capFlags($uid, $scope)['media']);
        });
        if ($scope === false) {
            return;
        }
        $caps = $this->_capFlags($uid, $scope); // cached; no extra DB round-trip

        $this->load_model('CmsMedia');

        $this->template = 'Cms_media.tpl';
        $this->data['page_title'] = 'Media Library';

        $search = trim((string)($_GET['q'] ?? ''));

        $media = $this->CmsMedia->list_media($scope, 200, ($search === '' ? null : $search));
        $this->data['Media']  = is_array($media) ? $media : array();
        $this->data['Search'] = $search;
        $this->data['Caps']   = $caps;
    }

    /* ------------------------------------------------------------------ *
     * Page list
     * ------------------------------------------------------------------ */

    public function index($action = null)
    {
        $uid = $this->_uid();
        // The list is visible to anyone holding ANY CMS capability (or super-admin).
        $scope = $this->_scopeOrDenyWithCap($uid, function ($uid, $scope) {
            return $this->_hasAnyCmsCapability($uid, $scope);
        });
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_index.tpl';
        $this->data['page_title'] = 'OGRE — Pages';

        $filters = $this->_scopeFilters($scope);
        $search = trim((string)($_GET['q'] ?? ''));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        $status = trim((string)($_GET['status'] ?? ''));
        if ($status === 'draft' || $status === 'published') {
            $filters['status'] = $status;
        }

        $pages = $this->CmsPage->list_pages($filters);
        $pages = is_array($pages) ? $pages : array();
        // #13: resolve every row's nested slug PATH from the fetched rows ONCE (an
        // in-memory parent_id walk) instead of a per-row PagePath() DB round-trip
        // (an N+1). Falls back to PagePath for any row whose ancestry can't be
        // resolved in memory (e.g. a filtered list missing an ancestor, or rows
        // that don't carry parent_id), so correctness never regresses.
        $pathMap = $this->_buildScopePathMap($pages);
        // Attach the scope-correct PUBLIC live URL to each row so the list links
        // to the org site (Site/...) in a scoped context, not the global Page route.
        foreach ($pages as &$pRow) {
            $pRow['live_href'] = $this->_pageLiveHref(
                $scope,
                (int)($pRow['page_id'] ?? 0),
                (string)($pRow['slug'] ?? ''),
                $pathMap
            );
        }
        unset($pRow);
        $this->data['Pages']      = $pages;
        $this->data['Search']     = $search;
        $this->data['StatusFilter'] = $status;

        // #128: per-row view counts for the list's "Views" column — one batched
        // read (page_id IN …) for the whole page, not an N+1. Best-effort in the
        // lib (pre-migration → empty map); the template falls back to ?? [].
        $this->load_model('CmsView');
        $pageIds = array();
        foreach ($pages as $pr) {
            $pid = (int)($pr['page_id'] ?? 0);
            if ($pid > 0) {
                $pageIds[] = $pid;
            }
        }
        $pageViewCounts = $this->CmsView->GetCountsForEntities($filters['scope_type'], $filters['scope_id'], 'page', $pageIds);
        $this->data['pageViewCounts'] = is_array($pageViewCounts) ? $pageViewCounts : array();

        // Full human label map for the Type column (covers types not present in
        // PageTypes, e.g. legacy/system page types). Unknown keys → ucwords.
        $this->data['TypeLabels'] = $this->_typeLabels();

        // Capability flags the list UI uses to show/hide actions.
        $this->data['Caps'] = $this->_capFlags($uid, $scope);
    }

    /* ------------------------------------------------------------------ *
     * Block editor
     * ------------------------------------------------------------------ */

    public function edit($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid    = $this->_uid();
        $id     = (string)$id;
        $isNew  = ($id === 'new' || $id === '' || $id === '0');
        $needed = $isNew ? 'page.create' : 'page.edit';

        $scope = $this->_scopeOrDenyWithCap($uid, $needed);
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_edit.tpl';

        if ($isNew) {
            $page   = array(
                'page_id'          => 0,
                'slug'             => '',
                'type'             => 'composed',
                'title'            => '',
                'status'           => 'draft',
                'published_at'     => null,
                'hero_media_id'    => null,
                'meta_description' => '',
                'is_system'        => 0,
                'scope_type'       => (string)$scope['type'],
                'scope_id'         => (int)$scope['id'],
            );
            $blocks = array();
            $this->data['page_title'] = 'New Page';
        } else {
            $page = $this->CmsPage->get_page((int)$id);
            // IDOR guard: a page from another scope is treated as not-found so a
            // scoped officer can neither view nor edit cross-org content.
            if (empty($page) || !$this->_rowInScope($page, $scope)) {
                // No such page — fall back to the list with a message.
                $this->template = 'Cms_index.tpl';
                $this->data['page_title'] = 'OGRE — Pages';
                $this->data['Pages']  = $this->CmsPage->list_pages($this->_scopeFilters($scope));
                $this->data['Search'] = '';
                $this->data['StatusFilter'] = '';
                $this->data['Caps'] = $this->_capFlags($uid, $scope);
                $this->data['Message'] = 'Page not found.';
                return;
            }
            // C2: editing an existing page returns ALL its blocks (incl. disabled)
            // via GetBlocksForEditor so the editor can toggle them; the public
            // get_blocks()/renderer path stays enabled-only.
            $blocks = $this->CmsPage->get_blocks_for_editor('page', (int)$page['page_id']);
            $this->data['page_title'] = 'Edit: ' . $page['title'];
        }

        $this->data['Page']         = $page;
        $catalog = $this->_blockCatalog();
        $this->data['Blocks']       = $blocks;
        $this->data['IsNew']        = $isNew;
        $this->data['BlockCatalog'] = $catalog;
        $this->data['PageTypes']    = $this->_pageTypes();
        $this->data['BlockAllow']   = $this->_blockAllow($catalog);
        // C22: the existing-tags library feeds the blog_feed block's validated tag
        // picker (a free-text tag silently rendered an empty feed on a typo).
        $this->data['AllTags']      = $this->_tagOptions();
        $this->data['Caps']         = $this->_capFlags($uid, $scope);
    }

    /* ------------------------------------------------------------------ *
     * Draft preview
     * ------------------------------------------------------------------ */

    public function preview($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid = $this->_uid();
        $scope = $this->_scopeOrDenyWithCap($uid, 'page.edit');
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_preview.tpl';
        $this->data['IsFrontDoor'] = false;
        $this->data['no_index']    = true;
        // C3: Cms_preview.tpl emits window.CMS_CSRF from $CmsCsrf so the preview's
        // inline editor actions carry the token. Set explicitly here (the
        // constructor also sets it) so the emit is guaranteed non-empty.
        $this->data['CmsCsrf']     = $this->_csrfToken();

        $page = $this->CmsPage->get_page((int)$id);
        // IDOR guard: never preview a page belonging to another scope.
        if (empty($page) || !$this->_rowInScope($page, $scope)) {
            $this->data['Message']    = 'Page not found.';
            $this->data['page_title'] = 'Preview — not found';
            $this->data['FrontDoor']  = array();
            $this->data['PreviewPage'] = null;
            return;
        }

        // Preview renders the CURRENT (draft) enabled blocks via the shared renderer.
        $this->data['FrontDoor']   = $this->CmsPage->get_page_blocks((int)$page['page_id']);
        $this->data['PreviewPage'] = $page;
        $this->data['PreviewKind'] = 'page';
        $this->data['CanPublish']  = $this->CmsAuth->cms_can($uid, 'page.publish', $scope);
        $this->data['page_title']  = 'Preview: ' . $page['title'];
    }

    /**
     * Draft preview for a POST. Mirrors preview() but renders a post's CURRENT
     * (draft) blocks via the shared Cms_preview.tpl renderer so unpublished
     * drafts can be reviewed without hitting the public route (which 404s on
     * drafts). PreviewPage carries source='postrow' so the renderer frames it
     * as a post row rather than a page.
     */
    public function previewpost($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid = $this->_uid();
        $scope = $this->_scopeOrDenyWithCap($uid, 'page.edit');
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_preview.tpl';
        $this->data['IsFrontDoor'] = false;
        $this->data['no_index']    = true;
        // C3: guarantee window.CMS_CSRF is non-empty in the post preview too.
        $this->data['CmsCsrf']     = $this->_csrfToken();

        $post = $this->CmsPost->get_post((int)$id);
        // IDOR guard: never preview a post belonging to another scope.
        if (empty($post) || !$this->_rowInScope($post, $scope)) {
            $this->data['Message']     = 'Post not found.';
            $this->data['page_title']  = 'Preview — not found';
            $this->data['FrontDoor']   = array();
            $this->data['PreviewPage'] = null;
            return;
        }

        // Preview renders the post's CURRENT (draft) blocks via the shared renderer.
        $this->data['FrontDoor']   = $this->CmsPost->get_post_blocks((int)$post['post_id']);
        $this->data['PreviewPage'] = $post;
        $this->data['PreviewKind'] = 'postrow';
        $this->data['CanPublish']  = $this->CmsAuth->cms_can($uid, 'page.publish', $scope);
        $this->data['page_title']  = 'Preview: ' . $post['title'];
    }

    /* ------------------------------------------------------------------ *
     * Blog posts — list
     * ------------------------------------------------------------------ */

    public function posts($action = null)
    {
        $uid = $this->_uid();
        // Same gate as the page list: visible to anyone holding ANY CMS capability.
        $scope = $this->_scopeOrDenyWithCap($uid, function ($uid, $scope) {
            return $this->_hasAnyCmsCapability($uid, $scope);
        });
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_posts.tpl';
        $this->data['page_title'] = 'Blog Posts';

        $opts = array('includeDrafts' => true) + $this->_scopeFilters($scope);
        $tag = trim((string)($_GET['tag'] ?? ''));
        if ($tag !== '') {
            $opts['tag'] = $tag;
        }

        $result = $this->CmsPost->list_posts($opts);
        $rows   = (is_array($result) && isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();

        $this->data['Posts']     = $rows;
        $this->data['TagFilter'] = $tag;
        $this->data['AllTags']   = $this->CmsPost->list_all_tags();
        $this->data['Caps']      = $this->_capFlags($uid, $scope);

        // #128: per-row view counts for the post list's "Views" column — one
        // batched read (post_id IN …), best-effort (template falls back to ?? []).
        $this->load_model('CmsView');
        $sf = $this->_scopeFilters($scope);
        $postIds = array();
        foreach ($rows as $pr) {
            $pid = (int)($pr['post_id'] ?? 0);
            if ($pid > 0) {
                $postIds[] = $pid;
            }
        }
        $postViewCounts = $this->CmsView->GetCountsForEntities($sf['scope_type'], $sf['scope_id'], 'post', $postIds);
        $this->data['postViewCounts'] = is_array($postViewCounts) ? $postViewCounts : array();
    }

    /* ------------------------------------------------------------------ *
     * Navigation management (the 'marketing' menu)
     * ------------------------------------------------------------------ */

    public function nav($action = null)
    {
        $uid = $this->_uid();
        // Navigation management is an admin-only capability.
        $scope = $this->_scopeOrDenyWithCap($uid, 'nav.manage');
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_nav.tpl';
        $this->data['page_title'] = 'Navigation';

        // The flat item list (incl. disabled) the admin tree is built from.
        $items = $this->CmsNav->list_items('marketing', (string)$scope['type'], (int)$scope['id']);
        $this->data['Menu']     = 'marketing';
        $this->data['NavItems'] = is_array($items) ? $items : array();

        // Link-picker source lists: published + draft pages, and posts (scope-filtered).
        $pages = $this->CmsPage->list_pages($this->_scopeFilters($scope));
        $this->data['PickerPages'] = is_array($pages) ? $pages : array();

        $postsRes = $this->CmsPost->list_posts(array('includeDrafts' => true) + $this->_scopeFilters($scope));
        $postRows = (is_array($postsRes) && isset($postsRes['rows']) && is_array($postsRes['rows'])) ? $postsRes['rows'] : array();
        $this->data['PickerPosts'] = $postRows;

        $this->data['Caps'] = $this->_capFlags($uid, $scope);
    }

    /* ------------------------------------------------------------------ *
     * Theme engine editor — global scope, v1
     * ------------------------------------------------------------------ */

    /** Theme engine editor (global scope, v1). */
    public function theme($action = null)
    {
        $uid = $this->_uid();
        $scope = $this->_scopeOrDenyWithCap($uid, 'theme.manage');
        if ($scope === false) {
            return;
        }
        $this->load_model('CmsTheme');

        $active       = $this->CmsTheme->get_active_theme((string)$scope['type'], (int)$scope['id']);
        $activeTokens = (is_array($active) && isset($active['tokens']) && is_array($active['tokens']))
            ? $active['tokens']
            : array();

        $this->template = 'Cms_theme.tpl';
        $this->data['page_title']    = 'Theme';
        $this->data['cmsActive']     = 'theme';
        $this->data['ThemeCatalog']  = $this->CmsTheme->catalog();
        $this->data['ThemeFonts']    = $this->CmsTheme->font_allowlist();
        $this->data['ThemeValues']   = array_merge($this->CmsTheme->base_values(), $activeTokens);
        $this->data['ThemeActiveId'] = (is_array($active) && isset($active['id'])) ? (int)$active['id'] : 0;
        $this->data['Caps']          = $this->_capFlags($uid, $scope);
    }

    /* ------------------------------------------------------------------ *
     * Blog posts — editor
     * ------------------------------------------------------------------ */

    public function editpost($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid    = $this->_uid();
        $id     = (string)$id;
        $isNew  = ($id === 'new' || $id === '' || $id === '0');
        $needed = $isNew ? 'page.create' : 'page.edit';

        $scope = $this->_scopeOrDenyWithCap($uid, $needed);
        if ($scope === false) {
            return;
        }

        $this->template = 'Cms_editpost.tpl';

        if ($isNew) {
            $post = array(
                'post_id'       => 0,
                'slug'          => '',
                'title'         => '',
                'excerpt'       => '',
                'status'        => 'draft',
                'published_at'  => null,
                'hero_media_id' => null,
                'author_id'     => $uid,
                'author_name'   => '',
                'scope_type'    => (string)$scope['type'],
                'scope_id'      => (int)$scope['id'],
                'tags'          => array(),
            );
            $blocks = array();
            $heroRef = null;
            $this->data['page_title'] = 'New Post';
        } else {
            $post = $this->CmsPost->get_post((int)$id);
            // IDOR guard: a post from another scope is treated as not-found.
            if (empty($post) || !$this->_rowInScope($post, $scope)) {
                // No such post — fall back to the post list with a message.
                $this->template = 'Cms_posts.tpl';
                $this->data['page_title'] = 'Blog Posts';
                $listed = $this->CmsPost->list_posts(array('includeDrafts' => true) + $this->_scopeFilters($scope));
                $this->data['Posts']     = (is_array($listed) && isset($listed['rows'])) ? $listed['rows'] : array();
                $this->data['TagFilter'] = '';
                $this->data['AllTags']   = $this->CmsPost->list_all_tags();
                $this->data['Caps']      = $this->_capFlags($uid, $scope);
                $this->data['Message']   = 'Post not found.';
                return;
            }
            // C2: the editor needs ALL body blocks (incl. disabled) so they can be
            // toggled; get_post_blocks() is the enabled-only public path.
            $blocks  = $this->CmsPage->get_blocks_for_editor('post', (int)$post['post_id']);
            $heroRef = $this->_heroRef($post);
            $this->data['page_title'] = 'Edit: ' . $post['title'];
        }

        $this->data['Post']         = $post;
        $this->data['Blocks']       = $blocks;
        $this->data['IsNew']        = $isNew;
        $this->data['HeroRef']      = $heroRef;
        $catalog = $this->_blockCatalog();
        $this->data['BlockCatalog'] = $catalog;
        $this->data['BlockAllow']   = $this->_blockAllow($catalog);
        // C22: existing-tags library for the blog_feed block's validated tag picker.
        $this->data['AllTags']      = $this->_tagOptions();
        $this->data['Caps']         = $this->_capFlags($uid, $scope);
    }

    /**
     * The existing-tags option list the block editor's blog_feed tag picker binds
     * to: each ['slug','name','post_count'] so the editor can offer real tags and
     * warn when a chosen filter currently matches no posts. Thin pass-through to
     * the CmsPost tag library (never raw $DB in the controller).
     *
     * @return array<int,array{slug:string,name:string,post_count:int}>
     */
    private function _tagOptions()
    {
        $tags = $this->CmsPost->list_all_tags();
        $out = array();
        foreach ((is_array($tags) ? $tags : array()) as $t) {
            $slug = (string)($t['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[] = array(
                'slug'       => $slug,
                'name'       => (string)($t['name'] ?? $slug),
                'post_count' => (int)($t['post_count'] ?? 0),
            );
        }
        return $out;
    }

    /**
     * Resolve a post's hero image (hero_media_id) to a media-ref the editor's
     * image picker understands, or null when none is set / cannot be resolved.
     */
    private function _heroRef($post)
    {
        $mediaId = isset($post['hero_media_id']) ? (int)$post['hero_media_id'] : 0;
        if ($mediaId <= 0) {
            return null;
        }
        $this->load_model('CmsMedia');
        $row = $this->CmsMedia->get_media($mediaId);
        if (empty($row)) {
            return null;
        }
        return $this->CmsMedia->to_media_ref($row);
    }

    /* ------------------------------------------------------------------ *
     * Internal helpers
     * ------------------------------------------------------------------ */

    private function _uid()
    {
        return isset($this->session->user_id) ? (int)$this->session->user_id : 0;
    }

    /**
     * True when the user holds at least one CMS capability in the given scope
     * (covers super-admin via _resolveCapabilities short-circuit). Scope defaults
     * to the global front door for legacy callers.
     */
    private function _hasAnyCmsCapability($uid, $scope = null)
    {
        if ($uid <= 0) {
            return false;
        }
        $resolved = $this->_resolveCapabilities($uid, $scope);
        if ($resolved['is_super']) {
            return true;
        }
        return !empty($resolved['caps']);
    }

    /**
     * Per-capability boolean map for templates (show/hide editor buttons), for
     * the given scope (defaults to the global front door).
     */
    private function _capFlags($uid, $scope = null)
    {
        $resolved = $this->_resolveCapabilities($uid, $scope);
        $isSuper  = $resolved['is_super'];
        $caps     = $resolved['caps'];
        return array(
            'create'  => $isSuper || in_array('page.create', $caps, true),
            'edit'    => $isSuper || in_array('page.edit', $caps, true),
            'publish' => $isSuper || in_array('page.publish', $caps, true),
            'delete'  => $isSuper || in_array('page.delete', $caps, true),
            'media'   => $isSuper || in_array('media.manage', $caps, true),
            'nav'     => $isSuper || in_array('nav.manage', $caps, true),
            'roles'   => $isSuper || in_array('roles.manage', $caps, true),
            'theme'   => $isSuper || in_array('theme.manage', $caps, true),
            // ORK super-admin only. Gates the global "All sites" rail entry (the
            // overview is a cross-org, super-admin-only surface). Never true for
            // an org-scoped officer, so the rail entry stays hidden for them.
            'super'   => $isSuper,
        );
    }

    /**
     * #129: the FLAT list of capability strings the user actually holds in a
     * scope, for the shell to emit as window.CMS_CAPS (admin-templates annotates /
     * disables actions the user lacks). A super-admin holds everything, so return
     * the full canonical set for them (nothing gets disabled). Never breaks an
     * empty case — an unauthenticated / capless caller yields [].
     *
     * @param int        $uid
     * @param array|null $scope
     * @return string[]
     */
    private function _capList($uid, $scope = null)
    {
        $resolved = $this->_resolveCapabilities($uid, $scope);
        if (!empty($resolved['is_super'])) {
            return self::_allCmsCapabilities();
        }
        return array_values(array_unique(array_map('strval', (array)$resolved['caps'])));
    }

    /**
     * #129: every CMS capability string the admin UI knows about — the set a
     * super-admin is treated as holding, and the vocabulary the shell annotates
     * against. Keep in sync with the _capFlags() mapping.
     *
     * @return string[]
     */
    private static function _allCmsCapabilities()
    {
        return array(
            'page.create',
            'page.edit',
            'page.edit_own',
            'page.publish',
            'page.delete',
            'media.manage',
            'nav.manage',
            'roles.manage',
            'theme.manage',
        );
    }

    /**
     * Resolve a user's CMS capabilities ONCE per request (cached by uid).
     *
     * Issues exactly 2 DB queries total (1 IsSuperAdmin + 1 GetUserGrants),
     * versus the prior O(N) loop that fired up to ~24 queries (8 caps ×
     * IsSuperAdmin + GetUserGrants each). All callers do in_array() in memory.
     *
     * Big-O: O(G × R) per request, G = grant rows, R = roles/caps (both tiny,
     * single-digit in practice); previously O(N) DB round-trips where N = caps.
     *
     * @param int        $uid   mundane_id
     * @param array|null $scope resolved request scope (null → global default)
     * @return array{is_super:bool, caps:string[]}
     */
    private function _resolveCapabilities($uid, $scope = null)
    {
        $uid = (int)$uid;
        if (!is_array($scope)) {
            $scope = self::$SCOPE;
        }
        // Cache key MUST include scope: caps differ per org within one request,
        // so a uid-only key would leak one scope's caps into another.
        $key = $uid . '|' . (string)($scope['type'] ?? 'global') . ':' . (int)($scope['id'] ?? 0);
        if (isset($this->_capCache[$key])) {
            return $this->_capCache[$key];
        }

        // One HasAuthority query (super-admin short-circuit).
        $isSuper = ($uid > 0) && (bool)$this->CmsAuth->IsSuperAdmin($uid);

        // One GetUserGrants query + in-memory role expansion, scoped to this org.
        // Skip for super-admins — they pass every cap already.
        $caps = ($uid > 0 && !$isSuper)
            ? $this->CmsAuth->get_user_capabilities($uid, $scope)
            : array();

        $resolved = array('is_super' => $isSuper, 'caps' => $caps);
        $this->_capCache[$key] = $resolved;
        return $resolved;
    }

    /**
     * Resolve the request scope for a page surface, or emit the deny redirect
     * when a present selector is malformed / unauthorized. Returns the scope
     * array on success, or false after arranging the deny (caller must return).
     *
     * @param int $uid
     * @return array{type:string,id:int}|false
     */
    private function _scopeOrDeny($uid)
    {
        $scope = $this->_resolveScope($uid);
        if ($scope === false) {
            $this->_denyRedirect();
            return false;
        }
        return $scope;
    }

    /**
     * The page-surface preamble every scoped action shares: resolve+authorize the
     * request scope, gate a capability, then publish the scope to the template
     * layer. Returns the resolved scope on success, or false after arranging the
     * deny (the caller must `return;` on false — identical to the old inline
     * three-liner + gate).
     *
     * $capability is either a capability string checked via CmsAuth->cms_can(),
     * or a callable(int $uid, array $scope):bool for the non-cms_can gates
     * (e.g. _hasAnyCmsCapability, or the media _capFlags check).
     *
     * @param int             $uid
     * @param string|callable $capability
     * @return array{type:string,id:int}|false
     */
    private function _scopeOrDenyWithCap($uid, $capability)
    {
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return false; // deny already arranged by _scopeOrDeny
        }
        $ok = is_string($capability)
            ? (bool)$this->CmsAuth->cms_can($uid, $capability, $scope)
            : (bool)$capability($uid, $scope);
        if (!$ok) {
            // #129: when the gate is a single named capability, pass it through so
            // the deny page can tell the user exactly what they're missing. A
            // callable gate (any-of / dashboard "any capability") has no single
            // name → null.
            $this->_denyRedirect(is_string($capability) ? $capability : null);
            return false;
        }
        $this->_applyScopeData($scope);
        return $scope;
    }

    /**
     * Publish the resolved scope's context to the template layer: the shell
     * reads these to thread scope onto rail links, emit window.CMS_SCOPE, and
     * render the "Editing: {Org} — public site" banner. No-ops to empty for
     * global so the legacy front-door chrome is unchanged.
     *
     * @param array $scope
     * @return void
     */
    private function _applyScopeData($scope)
    {
        $this->data['CmsScope']      = $scope;
        $this->data['CmsScopeQuery'] = $this->_scopeQuery($scope);
        $this->data['CmsScopeSel']   = $this->_scopeSelector($scope);
        $this->data['CmsScopeLabel'] = $this->_scopeIsGlobal($scope) ? '' : $this->_scopeOrgLabel($scope);
        // The "View live site" target for THIS scope: the org's own public home
        // (/k|/p route) for a scoped site, or the global front door otherwise.
        $this->data['SiteLiveUrl']   = $this->_scopeLiveHome($scope);
        // #129: the caller's held capability set, for the shell to emit as
        // window.CMS_CAPS so the admin UI can annotate/disable actions the user
        // lacks. Every scoped render path funnels through here.
        $this->data['CmsCaps']       = $this->_capList($this->_uid(), $scope);
    }

    /**
     * Memoized site row for the current (non-global) scope, or null. Used to
     * build scope-correct PUBLIC live URLs in the admin — a kingdom/park page's
     * public address is Site/... (its org site), never the global Page/Blog route.
     *
     * @param array $scope
     * @return array|null
     */
    private function _scopeSite($scope)
    {
        if ($this->_scopeSiteMemo !== false) {
            return $this->_scopeSiteMemo;
        }
        $this->_scopeSiteMemo = null;
        if (!$this->_scopeIsGlobal($scope)) {
            $this->load_model('CmsSite');
            $site = $this->CmsSite->get_site_for_scope((string)$scope['type'], (int)$scope['id']);
            $this->_scopeSiteMemo = is_array($site) ? $site : null;
        }
        return $this->_scopeSiteMemo;
    }

    /**
     * The "View live site" URL for the current scope: the org site's public home
     * (Site/view/{siteSlug}) when scoped, else the global front door (UIR).
     *
     * @param array $scope
     * @return string
     */
    private function _scopeLiveHome($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return UIR;
        }
        $site = $this->_scopeSite($scope);
        $slug = ($site && !empty($site['slug'])) ? (string)$site['slug'] : '';
        return $slug !== '' ? UIR . 'Site/view/' . rawurlencode($slug) : UIR;
    }

    /**
     * Scope-correct PUBLIC live URL for a page row. Global scope keeps the
     * legacy Page/view route; a scoped page maps to its org site — the home page
     * (matched by home_page_id, or the 'home' slug) to Site/view/{siteSlug}, any
     * other page to Site/page/{siteSlug}/{pageSlug}. Returns '' when a scoped
     * site has no resolvable slug yet (no public URL to link).
     *
     * @param array      $scope
     * @param int        $pageId
     * @param string     $pageSlug
     * @param array|null $pathMap  optional pageId => full-slug-path map (#13) to
     *                             resolve nested paths in memory; falls back to a
     *                             PagePath() DB walk for any id it doesn't cover.
     * @return string
     */
    private function _pageLiveHref($scope, $pageId, $pageSlug, $pathMap = null)
    {
        $pageSlug = (string)$pageSlug;
        if ($this->_scopeIsGlobal($scope)) {
            return ($pageSlug === 'home') ? UIR : UIR . 'Page/view/' . rawurlencode($pageSlug);
        }
        $site = $this->_scopeSite($scope);
        $siteSlug = ($site && !empty($site['slug'])) ? (string)$site['slug'] : '';
        if ($siteSlug === '') {
            return '';
        }
        $isHome = ($site && (int)($site['home_page_id'] ?? 0) === (int)$pageId) || $pageSlug === 'home';
        if ($isHome) {
            return UIR . 'Site/view/' . rawurlencode($siteSlug);
        }
        // Nested pages live at their FULL slug path (parent/child/…), not the bare
        // leaf slug — so the live link matches the public Site/page route (which
        // walks parent_id). #13: prefer the precomputed in-memory path map; only
        // fall back to a per-row PagePath() DB walk when the map can't resolve it.
        $path = (is_array($pathMap) && isset($pathMap[(int)$pageId]))
            ? (string)$pathMap[(int)$pageId]
            : (string)$this->CmsPage->PagePath((int)$pageId);
        if ($path === '') {
            $path = $pageSlug;
        }
        $encPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        return UIR . 'Site/page/' . rawurlencode($siteSlug) . '/' . $encPath;
    }

    /**
     * #13: build a pageId => full-slug-path map from the already-fetched admin
     * list rows, resolving nested paths with an in-memory parent_id walk — one
     * pass over the rows instead of a PagePath() DB walk per row. A row is only
     * mapped when its ENTIRE ancestor chain is present in the same result set and
     * every row carries the parent_id column; anything not fully resolvable is
     * simply omitted so _pageLiveHref falls back to a PagePath() lookup for it
     * (correctness preserved for filtered lists or pre-migration rows).
     *
     * @param array $pages ListPages rows (need page_id, slug, parent_id)
     * @return array<int,string> pageId => 'parent/child/leaf' slug path
     */
    private function _buildScopePathMap($pages)
    {
        $byId = array();
        foreach ((is_array($pages) ? $pages : array()) as $p) {
            $pid = (int)($p['page_id'] ?? 0);
            // Require the parent_id column to resolve ancestry in memory; without
            // it, leave the row unmapped (caller falls back to PagePath).
            if ($pid <= 0 || !array_key_exists('parent_id', $p)) {
                continue;
            }
            $par = ($p['parent_id'] !== null && (int)$p['parent_id'] > 0) ? (int)$p['parent_id'] : 0;
            $byId[$pid] = array('slug' => (string)($p['slug'] ?? ''), 'parent' => $par);
        }

        $map = array();
        foreach ($byId as $pid => $_) {
            $parts = array();
            $cur   = $pid;
            $guard = 0;
            $ok    = true;
            while ($cur > 0) {
                if (!isset($byId[$cur]) || ++$guard > 64) {
                    $ok = false; // ancestor missing from this list, or a cycle
                    break;
                }
                array_unshift($parts, $byId[$cur]['slug']);
                $cur = $byId[$cur]['parent'];
            }
            if ($ok) {
                $map[$pid] = implode('/', array_filter($parts, function ($s) {
                    return $s !== '';
                }));
            }
        }
        return $map;
    }

    /**
     * Auth failure on a page surface (these never emit JSON). Two distinct cases:
     *
     *   - GENUINELY UNAUTHENTICATED (no session) → bounce to Login, since there's
     *     nothing to show and re-authenticating is the actual next step.
     *   - AUTHENTICATED BUT LACKS CMS RIGHTS (or a malformed/unauthorized scope) →
     *     a login bounce is baffling ("why is it asking me to log in again?") and
     *     dead-ends the user. Instead render a plain-language permission page that
     *     tells them who can grant access, mirroring the dashboard site-card's tone.
     */
    private function _denyRedirect($missingCap = null)
    {
        if ($this->_uid() > 0) {
            return $this->_denyPermission($missingCap);
        }
        header('X-Robots-Tag: noindex, nofollow');
        header('Location: ' . UIR . 'Login');
        // Set a minimal template so view() has something harmless to render
        // if headers were already flushed (shouldn't happen in normal flow).
        $this->template = 'Cms_index.tpl';
        $this->data['Pages']  = array();
        $this->data['Search'] = '';
        $this->data['StatusFilter'] = '';
        $this->data['Caps'] = array();
        $this->data['Message'] = 'Not authorized.';
        return;
    }

    /**
     * Render a self-contained "you don't have permission" page for a logged-in
     * user who lacks CMS access, then stop. #109: the markup now lives in the
     * bare-chrome Cms_deny.tpl; the controller keeps only the 403 + X-Robots-Tag
     * headers and renders that template directly (the deny page deliberately
     * bypasses the themed View pipeline — a denied viewer holds no CMS scope to
     * build the shell chrome from).
     */
    private function _denyPermission($missingCap = null)
    {
        header('X-Robots-Tag: noindex, nofollow');
        if (function_exists('http_response_code')) {
            http_response_code(403);
        }
        $HomeUrl = (string)UIR;
        // #129: the specific capability the user lacked (when the gate was a single
        // named capability), for the deny page to name. '' when unknown (a scope
        // failure or an any-of gate) — the template falls back to generic copy.
        $MissingCapability = ($missingCap !== null) ? (string)$missingCap : '';
        $tpl = DIR_TEMPLATE . 'default/Cms_deny.tpl';
        if (is_file($tpl)) {
            include $tpl;
        }
        exit;
    }

    /**
     * The block catalog the editor offers. Derived from the partials actually
     * present in frontdoor/blocks/ (authoritative `available` flag) UNION the
     * spec's named catalog (so future block types appear as "coming soon").
     *
     * Each entry: ['type','label','group','available','dynamic'].
     */
    private function _blockCatalog()
    {
        // #21: return the per-request memo when present — the editor load builds
        // the catalog once instead of ~15 times (see $_blockCatalogMemo).
        if (is_array($this->_blockCatalogMemo)) {
            return $this->_blockCatalogMemo;
        }
        // #42: the canonical type => meta map is the SINGLE source of truth for
        // block types, shared with Controller_CmsAjax (which derives its save-time
        // allowlist from CanonicalBlockTypes()). Kept as pure data in one static
        // method so neither the catalog view nor the parser hand-duplicates it.
        $known = self::_blockCatalogMeta();

        $blockDir = DIR_TEMPLATE . 'default/frontdoor/blocks/';

        $catalog = array();
        foreach ($known as $type => $meta) {
            $partial   = $blockDir . preg_replace('/[^a-z_]/', '', $type) . '.tpl';
            $available = file_exists($partial);
            $catalog[] = array(
                'type'        => $type,
                'label'       => $meta[0],
                'group'       => $meta[1],
                'dynamic'     => (bool)$meta[2],
                'icon'        => $meta[3],
                'description' => $meta[4],
                'available'   => $available,
                // 6th tuple element is the addable flag; default true when absent.
                'addable'     => !isset($meta[5]) ? true : (bool)$meta[5],
            );
        }
        $this->_blockCatalogMemo = $catalog;
        return $catalog;
    }

    /**
     * #42: the canonical block-type => meta map — the SINGLE source of truth for
     * which block types exist. Pure data (no filesystem), so it is safe to call
     * statically from Controller_CmsAjax's save-time allowlist. Tuple:
     * [label, group, dynamic, icon, description, addable?].
     *
     * @return array<string,array>
     */
    private static function _blockCatalogMeta()
    {
        return array(
            // Shipped front-door blocks.
            'marketing_nav'   => array('Marketing Nav',      'Layout',   false, 'fa-bars',          'Top navigation bar with logo, menu links, and login / call-to-action buttons. Rendered automatically as site chrome — not added per page.', false),
            'member_bar'      => array('Member Bar',         'Dynamic',  true,  'fa-user-shield',   'Logged-in welcome strip with quick links to the viewer’s kingdom, Live Attendance, and Member Tools. Hidden from signed-out visitors.'),
            'hero_carousel'   => array('Hero Carousel',      'Hero',     false, 'fa-images',        'Full-width rotating hero with slides, logo, and call-to-action buttons.'),
            'richtext'        => array('Rich Text (legacy)', 'Content',  false, 'fa-align-left',    'Legacy rich-text block. Prefer the newer Rich Text block for new pages.', false),
            'card_grid'       => array('Card Grid',          'Content',  false, 'fa-th-large',      'Grid of cards, each with an image/icon, title, blurb, and link.'),
            'steps'           => array('Steps / How-To',     'Content',  false, 'fa-list-ol',       'Numbered steps in a row — great for “How to join” style guides.'),
            'events_feed'     => array('Events Feed',        'Dynamic',  true,  'fa-calendar-day',  'Shows the soonest upcoming events live across the org, as date cards linking to each event.'),
            'photo_mosaic'    => array('Photo Mosaic',       'Media',    false, 'fa-icons',         'Asymmetric photo collage (first image large) with a caption tile.'),
            'kingdoms_teaser' => array('Kingdoms Teaser',    'Dynamic',  true,  'fa-crown',         'Live grid of active parent kingdoms with heraldry, linking to each kingdom profile.'),
            'cta_band'        => array('Call-to-Action Band', 'Content', false, 'fa-bullhorn',      'Banner with a heading, subcopy, optional logo, and call-to-action buttons.'),
            'staff_roster'    => array('Staff Roster',       'Content',  false, 'fa-users',         'A roster of people — photo, name, role, and bio, each optionally linked to their Amtgard persona.'),
            // New CMS block types from the spec (Phase 4 partials).
            'rich_text'       => array('Rich Text',          'Content',  false, 'fa-paragraph',     'Heading + formatted body text with an optional call-to-action.'),
            'heading'         => array('Heading',            'Content',  false, 'fa-heading',       'A standalone section heading (H2–H4) with alignment.'),
            'divider'         => array('Divider',            'Layout',   false, 'fa-grip-lines',    'A thin horizontal rule to separate sections.'),
            'spacer'          => array('Spacer',             'Layout',   false, 'fa-arrows-alt-v',  'Vertical whitespace between blocks.'),
            'accordion'       => array('Accordion',          'Content',  false, 'fa-chevron-circle-down', 'Expandable question / answer (FAQ) items.'),
            'quote'           => array('Quote',              'Content',  false, 'fa-quote-right',   'A pull-quote with an optional attribution.'),
            'table'           => array('Table',              'Content',  false, 'fa-table',         'A simple data table with an optional caption and header row.'),
            'image'           => array('Image',              'Media',    false, 'fa-image',         'A single image with an optional caption and link.'),
            'gallery'         => array('Gallery',            'Media',    false, 'fa-images',        'A multi-column grid of images.'),
            'video_embed'     => array('Video Embed',        'Media',    false, 'fa-play-circle',   'An embedded YouTube or Vimeo video.'),
            'file_download'   => array('File Download',      'Content',  false, 'fa-file-download', 'A list of downloadable files with titles and metadata.'),
            'columns'         => array('Columns',            'Layout',   false, 'fa-columns',       'Multiple side-by-side columns, each holding its own blocks.'),
            'raw_html'        => array('Custom HTML (limited)', 'Advanced', false, 'fa-code',        'Custom HTML — script/style/iframe/form are stripped on save; use Video Embed for embeds.'),
            'blog_feed'       => array('Blog Feed',          'Dynamic',  true,  'fa-rss',           'Shows the latest published blog posts live as linked cards. Optionally filtered to a single tag.'),
            // Phase 4 org-scoped dynamic blocks (kingdom sites): pull live ORK data for the page's owning kingdom.
            'kingdom_officers' => array('Officers (live)',   'Dynamic',  true,  'fa-user-shield',   'Live grid of the kingdom’s current officers from ORK data (office + persona). Pair with a Staff Roster for your Board of Directors.'),
            'kingdom_parks'   => array('Parks (live)',       'Dynamic',  true,  'fa-map-marked-alt', 'Live grid of the kingdom’s active parks (heraldry + name + city/state), sortable, each linking to its public park profile.'),
            'kingdom_parks_map' => array('Parks map (live)', 'Dynamic',  true,  'fa-map',           'Interactive map of the kingdom’s active parks with a click-to-open detail sidebar (heraldry, directions, description). Great placed above a Parks list.'),
            'kingdom_events'  => array('Events (live)',      'Dynamic',  true,  'fa-calendar-day',  'Live list of the kingdom’s soonest upcoming events, as date cards linking to each event.'),
        );
    }

    /**
     * #42: the canonical list of valid block-type keys — the shared allowlist
     * Controller_CmsAjax::_parseBlocks() drops forged/unknown types against.
     * Derived from the ONE meta map above so the two can never drift.
     *
     * @return string[]
     */
    public static function CanonicalBlockTypes()
    {
        return array_keys(self::_blockCatalogMeta());
    }

    /**
     * Page-type presets (editor hint → starting block set). Mirrors the spec's
     * "Page types are editor presets" decision.
     *
     * Each entry: ['type','label','blocks'=>[<starter block objects>]], where a
     * starter block is a fully-formed block: ['type','enabled','source','fields'].
     * The editor seeds the block list from these when CREATING a new page of the
     * given type (and re-seeds when the type is switched on an empty new page).
     * `fields` carry sensible empty defaults matching each block's partial keys.
     */
    private function _pageTypes()
    {
        $labels = $this->_typeLabels();
        // #21: build the catalog ONCE and thread it into every _starter() call so
        // the ~15 starters below don't each rebuild it (memoized too, belt-and-
        // suspenders). The starter only needs the catalog's dynamic-type flags.
        $catalog = $this->_blockCatalog();
        return array(
            array(
                'type'   => 'composed',
                'label'  => $labels['composed'],
                'blocks' => array(
                    $this->_starter('hero_carousel', $catalog),
                    $this->_starter('rich_text', $catalog),
                    $this->_starter('cta_band', $catalog),
                ),
            ),
            array(
                'type'   => 'article',
                'label'  => $labels['article'],
                'blocks' => array(
                    $this->_starter('heading', $catalog),
                    $this->_starter('rich_text', $catalog),
                ),
            ),
            array(
                'type'   => 'media',
                'label'  => $labels['media'],
                'blocks' => array(
                    $this->_starter('heading', $catalog),
                    $this->_starter('gallery', $catalog),
                ),
            ),
            array(
                'type'   => 'about',
                'label'  => $labels['about'],
                'blocks' => array(
                    $this->_starter('rich_text', $catalog),
                    $this->_starter('staff_roster', $catalog),
                ),
            ),
            array(
                'type'   => 'resource',
                'label'  => $labels['resource'],
                'blocks' => array(
                    $this->_starter('heading', $catalog),
                    $this->_starter('file_download', $catalog),
                ),
            ),
            array(
                'type'   => 'blog_index',
                'label'  => $labels['blog_index'],
                'blocks' => array(
                    $this->_starter('heading', $catalog),
                    $this->_starter('blog_feed', $catalog),
                ),
            ),
            array(
                'type'   => 'dynamic',
                'label'  => $labels['dynamic'],
                'blocks' => array(
                    $this->_starter('kingdoms_teaser', $catalog),
                ),
            ),
        );
    }

    /**
     * Which block types are SENSIBLE to add on each page type (and on blog post
     * bodies, keyed 'post'). The editor surfaces these in the Add-block chooser
     * by default; everything else is reachable behind a "Show all blocks" toggle.
     * This only governs the chooser — blocks already placed on a page keep
     * rendering and stay editable regardless of this list.
     *
     * A handful of blocks are universal (sensible on any page); each type then
     * adds its own thematic extras. `composed` (the landing-page type) is the
     * kitchen sink — it gets every addable block, so it is computed from the
     * catalog rather than enumerated here.
     *
     * @return array<string,array<int,string>> page-type key => allowed block types
     */
    private function _blockAllow($catalog = null)
    {
        if (!is_array($catalog)) {
            $catalog = $this->_blockCatalog();
        }
        // Sensible on any page: structure + plain content + a single image.
        $universal = array('heading', 'rich_text', 'image', 'divider', 'spacer', 'quote', 'raw_html');

        $extra = array(
            // Text/article: long-form content + inline media + supporting layout.
            'article'    => array('accordion', 'table', 'file_download', 'video_embed', 'gallery', 'columns'),
            // Media/gallery: image-led blocks.
            'media'      => array('gallery', 'photo_mosaic', 'video_embed', 'card_grid'),
            // About / Team: a people roster plus supporting content blocks.
            'about'      => array('staff_roster', 'kingdom_officers', 'kingdom_parks', 'kingdom_parks_map', 'card_grid', 'cta_band', 'gallery'),
            // Resource/document: downloads + tabular/structured reference.
            'resource'   => array('file_download', 'table', 'accordion', 'columns'),
            // Blog index: the live post feed, with an optional call-to-action.
            'blog_index' => array('blog_feed', 'cta_band'),
            // Dynamic data: every live feed, plus framing blocks. #65: the global
            // events_feed (org-wide, all kingdoms) is dropped from the chooser in
            // favor of the scope-correct kingdom_events; existing events_feed blocks
            // still render and stay editable (blockAllow only governs the chooser).
            'dynamic'    => array('kingdoms_teaser', 'blog_feed', 'kingdom_officers', 'kingdom_parks', 'kingdom_parks_map', 'kingdom_events', 'member_bar', 'card_grid', 'cta_band'),
            // Blog post bodies behave like articles.
            'post'       => array('accordion', 'table', 'file_download', 'video_embed', 'gallery', 'columns'),
        );

        // composed = all addable block types (the full landing-page kit).
        $composed = array();
        foreach ($catalog as $c) {
            if (!empty($c['addable'])) {
                $composed[] = $c['type'];
            }
        }

        $allow = array('composed' => $composed);
        foreach ($extra as $type => $types) {
            $allow[$type] = array_values(array_unique(array_merge($universal, $types)));
        }
        return $allow;
    }

    /**
     * Human label map for page `type` keys, used by both the Type column and the
     * type chooser. Unknown keys should fall back to a de-underscored ucwords()
     * form at the call site (e.g. "blog_index" → "Blog Index").
     *
     * @return array<string,string>
     */
    private function _typeLabels()
    {
        return self::_pageTypeLabelMap();
    }

    /**
     * #110: the canonical page-type => label map — the SINGLE source of truth for
     * which page types exist. Pure static data so both the presets/labels path
     * (here) and Controller_CmsAjax::_normalizeType's save-time allowlist read the
     * SAME key set (via CanonicalPageTypes()). Previously the enum was declared
     * twice; a type added to the presets could be silently clamped back to
     * 'composed' by a stale allowlist. One list now makes that impossible.
     *
     * @return array<string,string>
     */
    private static function _pageTypeLabelMap()
    {
        return array(
            'composed'   => 'Composed / Landing',
            'article'    => 'Article / Text',
            'media'      => 'Media / Gallery',
            'about'      => 'About / Team',
            'resource'   => 'Resource / Document',
            'blog_index' => 'Blog Index',
            'dynamic'    => 'Dynamic Data',
        );
    }

    /**
     * #110: the canonical list of valid page-type keys — the shared write-side
     * allowlist Controller_CmsAjax::_normalizeType clamps unknown types against.
     * Derived from the ONE label map above so the presets and the save allowlist
     * can never drift.
     *
     * @return string[]
     */
    public static function CanonicalPageTypes()
    {
        return array_keys(self::_pageTypeLabelMap());
    }

    /**
     * Build one starter block for a preset: a fully-formed block object with
     * sensible empty field defaults matching that block type's partial keys.
     *
     * @return array{type:string,enabled:int,source:string,fields:array}
     */
    private function _starter($type, $catalog = null)
    {
        // Dynamic blocks (pull data at render time) are flagged source=dynamic.
        // Derive the set from the catalog's `dynamic` flag so it stays in sync
        // with a single source of truth rather than a duplicated hand-list.
        // #21: the caller (_pageTypes) passes the already-built catalog so this
        // isn't rebuilt per starter; fall back to the memoized build otherwise.
        if (!is_array($catalog)) {
            $catalog = $this->_blockCatalog();
        }
        $dynamicTypes = array();
        foreach ($catalog as $c) {
            if (!empty($c['dynamic'])) {
                $dynamicTypes[$c['type']] = true;
            }
        }

        // Empty field defaults keyed to each partial's consumed fields.
        $defaults = array(
            'hero_carousel'   => array('autoplay_ms' => '', 'logo' => array(), 'slides' => array(), 'ctas' => array()),
            'rich_text'       => array('kicker' => '', 'heading' => '', 'body' => '', 'align' => 'left', 'cta' => array('label' => '', 'href' => '')),
            'cta_band'        => array('heading' => '', 'subcopy' => '', 'logo' => array(), 'ctas' => array(), 'links' => ''),
            'card_grid'       => array('kicker' => '', 'heading' => '', 'subheading' => '', 'cards' => array()),
            'staff_roster'    => array('kicker' => '', 'heading' => 'Meet the Team', 'subheading' => '', 'presentation' => 'amtgard', 'people' => array()),
            'heading'         => array('text' => '', 'level' => 2, 'align' => 'left'),
            'gallery'         => array('images' => array(), 'columns' => 3, 'caption' => ''),
            'file_download'   => array('files' => array()),
            'video_embed'     => array('provider' => 'youtube', 'video_id' => '', 'url' => '', 'caption' => ''),
            'accordion'       => array('items' => array()),
            'quote'           => array('text' => '', 'cite' => ''),
            'image'           => array('image' => array(), 'caption' => '', 'href' => '', 'align' => 'center', 'max_width' => ''),
            // Newly friendly authored types (defaults match each partial's keys).
            'steps'           => array('kicker' => '', 'heading' => '', 'band' => 'light', 'cta' => array('label' => '', 'href' => ''), 'steps' => array()),
            'photo_mosaic'    => array('caption' => '', 'images' => array()),
            'divider'         => array('style' => 'line'),
            'spacer'          => array('size' => 'md'),
            'table'           => array('caption' => '', 'header_first_row' => 1, 'rows' => array()),
            'raw_html'        => array('html' => ''),
            'marketing_nav'   => array('logo' => array(), 'cta' => array('label' => '', 'href' => ''), 'login' => array('label' => '', 'href' => '')),
            'columns'         => array('columns' => array()),
            // Dynamic blocks (sourced live) — only their genuine knobs.
            'kingdoms_teaser' => array('kicker' => '', 'heading' => '', 'limit' => 12, 'more_href' => ''),
            'events_feed'     => array('kicker' => '', 'heading' => '', 'limit' => 3, 'more_href' => ''),
            'blog_feed'       => array('heading' => '', 'limit' => 3, 'tag' => ''),
            'kingdom_officers' => array('kicker' => '', 'heading' => '', 'limit' => 12),
            'kingdom_parks'   => array('kicker' => '', 'heading' => '', 'sort' => 'name', 'show_heraldry' => 0, 'limit' => 24, 'more_href' => ''),
            'kingdom_parks_map' => array('kicker' => '', 'heading' => 'Park Map'),
            'kingdom_events'  => array('kicker' => '', 'heading' => '', 'limit' => 3, 'more_href' => ''),
            'member_bar'      => array(),
        );

        return array(
            'type'    => $type,
            'enabled' => 1,
            'source'  => isset($dynamicTypes[$type]) ? 'dynamic' : 'authored',
            'fields'  => isset($defaults[$type]) ? $defaults[$type] : array(),
        );
    }
}
