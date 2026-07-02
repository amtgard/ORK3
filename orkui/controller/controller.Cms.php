<?php

require_once __DIR__ . '/trait.CmsScope.php';

/**
 * Controller_Cms — CMS admin (page-rendering surfaces).
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
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        // The dashboard is visible to anyone holding ANY CMS capability (or super-admin).
        if (!$this->_hasAnyCmsCapability($uid, $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

        // Entry point: opening the dashboard in a non-global org scope lazily
        // provisions the org's site row (status='unbuilt'; seeding is Phase 5).
        // Idempotent — a second open returns the existing row and no-ops.
        if (!$this->_scopeIsGlobal($scope)) {
            $this->_loadSiteContext($uid, $scope, true);
        }

        $this->template = 'Cms_dashboard.tpl';
        $this->data['page_title'] = 'Content Management';

        // ---- Pages overview (list_pages is already ORDER BY updated_at DESC) ----
        $pages = $this->CmsPage->list_pages($this->_scopeFilters($scope));
        $pages = is_array($pages) ? $pages : array();

        $pageCount  = count($pages);
        $pageDrafts = 0;
        foreach ($pages as $p) {
            if ((string)($p['status'] ?? 'draft') !== 'published') {
                $pageDrafts++;
            }
        }

        // ---- Posts overview ----
        $postsRes = $this->CmsPost->list_posts(array('includeDrafts' => true) + $this->_scopeFilters($scope));
        $posts    = (is_array($postsRes) && isset($postsRes['rows']) && is_array($postsRes['rows'])) ? $postsRes['rows'] : array();

        $postCount  = count($posts);
        $postDrafts = 0;
        foreach ($posts as $p) {
            if ((string)($p['status'] ?? 'draft') !== 'published') {
                $postDrafts++;
            }
        }

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
     * Media library
     * ------------------------------------------------------------------ */

    public function media($action = null)
    {
        $uid = $this->_uid();
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        $caps = $this->_capFlags($uid, $scope);
        // Media management is its own capability (super-admins pass via _capFlags).
        if (empty($caps['media'])) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

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
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        // The list is visible to anyone holding ANY CMS capability (or super-admin).
        if (!$this->_hasAnyCmsCapability($uid, $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

        $this->template = 'Cms_index.tpl';
        $this->data['page_title'] = 'Content Management';

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
        // Attach the scope-correct PUBLIC live URL to each row so the list links
        // to the org site (Site/...) in a scoped context, not the global Page route.
        foreach ($pages as &$pRow) {
            $pRow['live_href'] = $this->_pageLiveHref(
                $scope,
                (int)($pRow['page_id'] ?? 0),
                (string)($pRow['slug'] ?? '')
            );
        }
        unset($pRow);
        $this->data['Pages']      = $pages;
        $this->data['Search']     = $search;
        $this->data['StatusFilter'] = $status;

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
        $scope  = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        $id     = (string)$id;
        $isNew  = ($id === 'new' || $id === '' || $id === '0');
        $needed = $isNew ? 'page.create' : 'page.edit';

        if (!$this->CmsAuth->cms_can($uid, $needed, $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

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
                $this->data['page_title'] = 'Content Management';
                $this->data['Pages']  = $this->CmsPage->list_pages($this->_scopeFilters($scope));
                $this->data['Search'] = '';
                $this->data['StatusFilter'] = '';
                $this->data['Caps'] = $this->_capFlags($uid, $scope);
                $this->data['Message'] = 'Page not found.';
                return;
            }
            // Editing an existing page returns ALL its blocks (incl. disabled) so
            // the editor can toggle them; the public renderer filters to enabled.
            $blocks = $this->CmsPage->get_blocks('page', (int)$page['page_id']);
            $this->data['page_title'] = 'Edit: ' . $page['title'];
        }

        $this->data['Page']         = $page;
        $catalog = $this->_blockCatalog();
        $this->data['Blocks']       = $blocks;
        $this->data['IsNew']        = $isNew;
        $this->data['BlockCatalog'] = $catalog;
        $this->data['PageTypes']    = $this->_pageTypes();
        $this->data['BlockAllow']   = $this->_blockAllow($catalog);
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
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

        $this->template = 'Cms_preview.tpl';
        $this->data['IsFrontDoor'] = false;
        $this->data['no_index']    = true;

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
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

        $this->template = 'Cms_preview.tpl';
        $this->data['IsFrontDoor'] = false;
        $this->data['no_index']    = true;

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
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        // Same gate as the page list: visible to anyone holding ANY CMS capability.
        if (!$this->_hasAnyCmsCapability($uid, $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

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
    }

    /* ------------------------------------------------------------------ *
     * Navigation management (the 'marketing' menu)
     * ------------------------------------------------------------------ */

    public function nav($action = null)
    {
        $uid = $this->_uid();
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        // Navigation management is an admin-only capability.
        if (!$this->CmsAuth->cms_can($uid, 'nav.manage', $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

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
        $scope = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        if (!$this->CmsAuth->cms_can($uid, 'theme.manage', $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);
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
        $scope  = $this->_scopeOrDeny($uid);
        if ($scope === false) {
            return;
        }
        $id     = (string)$id;
        $isNew  = ($id === 'new' || $id === '' || $id === '0');
        $needed = $isNew ? 'page.create' : 'page.edit';

        if (!$this->CmsAuth->cms_can($uid, $needed, $scope)) {
            return $this->_denyRedirect();
        }
        $this->_applyScopeData($scope);

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
            $blocks  = $this->CmsPost->get_post_blocks((int)$post['post_id']);
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
        $this->data['Caps']         = $this->_capFlags($uid, $scope);
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
     * @param array  $scope
     * @param int    $pageId
     * @param string $pageSlug
     * @return string
     */
    private function _pageLiveHref($scope, $pageId, $pageSlug)
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
        return $isHome
            ? UIR . 'Site/view/' . rawurlencode($siteSlug)
            : UIR . 'Site/page/' . rawurlencode($siteSlug) . '/' . rawurlencode($pageSlug);
    }

    /**
     * Not-permitted / not-logged-in → bounce to Login (page surfaces don't
     * emit JSON). We still let view() run, but the redirect header wins.
     */
    private function _denyRedirect()
    {
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
     * The block catalog the editor offers. Derived from the partials actually
     * present in frontdoor/blocks/ (authoritative `available` flag) UNION the
     * spec's named catalog (so future block types appear as "coming soon").
     *
     * Each entry: ['type','label','group','available','dynamic'].
     */
    private function _blockCatalog()
    {
        // Human labels + grouping + whether the block pulls dynamic data +
        // a Font Awesome icon + a one-line description. For DYNAMIC blocks the
        // description is also surfaced as the body of the editor's info card
        // (it states what the block shows live).
        // Tuple: [label, group, dynamic, icon, description, addable?].
        // `addable` (6th element, optional) defaults to true when omitted. A
        // false value keeps the entry so EXISTING placed blocks still resolve a
        // label, while the editor's Add-block chooser skips it (no new blocks).
        $known = array(
            // Shipped front-door blocks.
            'marketing_nav'   => array('Marketing Nav',      'Layout',   false, 'fa-bars',          'Top navigation bar with logo, menu links, and login / call-to-action buttons. Rendered automatically as site chrome — not added per page.', false),
            'member_bar'      => array('Member Bar',         'Layout',   true,  'fa-user-shield',   'Logged-in welcome strip with quick links to the viewer’s kingdom, Live Attendance, and Member Tools. Hidden from signed-out visitors.'),
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
            'gallery'         => array('Gallery',            'Media',    false, 'fa-photo-video',   'A multi-column grid of images.'),
            'video_embed'     => array('Video Embed',        'Media',    false, 'fa-play-circle',   'An embedded YouTube or Vimeo video.'),
            'file_download'   => array('File Download',      'Content',  false, 'fa-file-download', 'A list of downloadable files with titles and metadata.'),
            'columns'         => array('Columns',            'Layout',   false, 'fa-columns',       'Multiple side-by-side columns, each holding its own blocks.'),
            'raw_html'        => array('Raw HTML',           'Advanced', false, 'fa-code',          'Custom HTML, sanitized on save.'),
            'stat_ticker'     => array('Stat Ticker',        'Dynamic',  true,  'fa-chart-line',    'Live headline statistics across the org.'),
            'tournaments_feed' => array('Tournaments Feed',  'Dynamic',  true,  'fa-trophy',        'Live list of recent or upcoming tournaments.'),
            'recap_highlight' => array('Recap Highlight',    'Dynamic',  true,  'fa-newspaper',     'Live highlight from the latest event recap.'),
            'blog_feed'       => array('Blog Feed',          'Dynamic',  true,  'fa-rss',           'Shows the latest published blog posts live as linked cards. Optionally filtered to a single tag.'),
            // Phase 4 org-scoped dynamic blocks (kingdom sites): pull live ORK data for the page's owning kingdom.
            'kingdom_officers' => array('Officers (live)',   'Dynamic',  true,  'fa-user-shield',   'Live grid of the kingdom’s current officers from ORK data (office + persona). Pair with a Staff Roster for your Board of Directors.'),
            'kingdom_parks'   => array('Parks (live)',       'Dynamic',  true,  'fa-map-marked-alt', 'Live grid of the kingdom’s active parks (heraldry + name + city/state), sortable, each linking to its public park profile.'),
            'kingdom_parks_map' => array('Parks map (live)', 'Dynamic',  true,  'fa-map',           'Interactive map of the kingdom’s active parks with a click-to-open detail sidebar (heraldry, directions, description). Great placed above a Parks list.'),
            'kingdom_events'  => array('Events (live)',      'Dynamic',  true,  'fa-calendar-day',  'Live list of the kingdom’s soonest upcoming events, as date cards linking to each event.'),
        );

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
        return $catalog;
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
        return array(
            array(
                'type'   => 'composed',
                'label'  => $labels['composed'],
                'blocks' => array(
                    $this->_starter('hero_carousel'),
                    $this->_starter('rich_text'),
                    $this->_starter('cta_band'),
                ),
            ),
            array(
                'type'   => 'article',
                'label'  => $labels['article'],
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('rich_text'),
                ),
            ),
            array(
                'type'   => 'media',
                'label'  => $labels['media'],
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('gallery'),
                ),
            ),
            array(
                'type'   => 'about',
                'label'  => $labels['about'],
                'blocks' => array(
                    $this->_starter('rich_text'),
                    $this->_starter('staff_roster'),
                ),
            ),
            array(
                'type'   => 'resource',
                'label'  => $labels['resource'],
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('file_download'),
                ),
            ),
            array(
                'type'   => 'blog_index',
                'label'  => $labels['blog_index'],
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('blog_feed'),
                ),
            ),
            array(
                'type'   => 'dynamic',
                'label'  => $labels['dynamic'],
                'blocks' => array(
                    $this->_starter('kingdoms_teaser'),
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
            // Dynamic data: every live feed, plus framing blocks.
            'dynamic'    => array('events_feed', 'kingdoms_teaser', 'blog_feed', 'stat_ticker', 'tournaments_feed', 'recap_highlight', 'kingdom_officers', 'kingdom_parks', 'kingdom_parks_map', 'kingdom_events', 'member_bar', 'card_grid', 'cta_band'),
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
     * Build one starter block for a preset: a fully-formed block object with
     * sensible empty field defaults matching that block type's partial keys.
     *
     * @return array{type:string,enabled:int,source:string,fields:array}
     */
    private function _starter($type)
    {
        // Dynamic blocks (pull data at render time) are flagged source=dynamic.
        $dynamicTypes = array(
            'member_bar'      => true,
            'events_feed'     => true,
            'kingdoms_teaser' => true,
            'stat_ticker'     => true,
            'tournaments_feed' => true,
            'recap_highlight' => true,
            'blog_feed'       => true,
            'kingdom_officers' => true,
            'kingdom_parks'   => true,
            'kingdom_parks_map' => true,
            'kingdom_events'  => true,
        );

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
