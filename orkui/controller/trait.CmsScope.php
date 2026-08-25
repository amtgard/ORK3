<?php

/**
 * trait.CmsScope.php — shared admin scope-context resolution for the CMS.
 *
 * Used by BOTH Controller_Cms (page surfaces) and Controller_CmsAjax (JSON
 * endpoints). Turns an optional per-request `scope` selector into a validated
 * (scope_type, scope_id) array and RE-VALIDATES it server-side on every request
 * against HasAuthority — the client value is only a selector, never authority.
 *
 * Selector wire format (query string `?scope=` or POST field `scope`):
 *   ''            → global front door (unchanged legacy behavior)
 *   'k:{id}'      → kingdom scope for kingdom_id={id}
 *   'p:{id}'      → park scope for park_id={id}
 *
 * BACKWARD COMPATIBILITY: when no selector is present, _resolveScope() returns
 * exactly array('type' => 'global', 'id' => 0) — byte-for-byte the old
 * hard-coded self::$SCOPE — so every existing global-scope path is unchanged.
 *
 * SECURITY: a present-but-unauthorized/malformed selector returns false (never
 * a silent downgrade to global and never an honored unauthorized scope). Each
 * consuming controller maps false to its own deny path (redirect vs JSON 403).
 *
 * Loaded via require_once from each controller file (the router include_once's
 * one controller per request; there is no class autoloader for arbitrary files).
 */
trait CmsScopeContext
{
    /** Memoized resolved scope for this request. @var array|false|null */
    private $_cmsScope = null;
    /** Whether _resolveScope() has run this request (false is a valid result). */
    private $_cmsScopeResolved = false;
    /** Request-scoped memo of _cmsCanScope() decisions, keyed "uid|cap|type|id". */
    private $_cmsCapMemo = array();

    /**
     * The acting mundane_id for this request, or 0 when signed out.
     *
     * Lives here because it is the INPUT to every other method on this trait —
     * _resolveScope, _cmsCanScope and _cmsFabData all take a $uid, and both
     * consuming admin controllers were reading it out of the session with their
     * own copy of this ternary. Use $this->session->user_id (never $this->__session,
     * which resolves to uid 0).
     *
     * @return int
     */
    private function _uid()
    {
        return isset($this->session->user_id) ? (int)$this->session->user_id : 0;
    }

    /**
     * Resolve + authorize the admin scope for this request.
     *
     * @param int $uid acting mundane_id (from $this->session->user_id)
     * @return array{type:string,id:int}|false
     *   ['type'=>'global','id'=>0]         when no selector (legacy front door),
     *   ['type'=>'kingdom'|'park','id'=>N] when authorized over the named org,
     *   false                              when the selector is malformed or the
     *                                      user lacks AUTH_EDIT over that org.
     */
    private function _resolveScope($uid)
    {
        if ($this->_cmsScopeResolved) {
            return $this->_cmsScope;
        }
        $this->_cmsScopeResolved = true;

        // Query string wins (rides on every scoped link + AJAX fetch URL); a
        // POST body field is accepted as a fallback for form-style callers.
        $raw = isset($_GET['scope']) ? (string)$_GET['scope']
            : (isset($_POST['scope']) ? (string)$_POST['scope'] : '');
        $raw = trim($raw);

        // No selector → global, exactly as the legacy hard-coded scope.
        if ($raw === '') {
            $this->_cmsScope = array('type' => 'global', 'id' => 0);
            return $this->_cmsScope;
        }

        // Parse 'k:{id}' / 'p:{id}'. Anything else is a malformed selector.
        if (!preg_match('/^([kp]):([0-9]{1,10})$/', $raw, $m)) {
            $this->_cmsScope = false;
            return false;
        }
        $scopeType = ($m[1] === 'p') ? 'park' : 'kingdom';
        $scopeId   = (int)$m[2];
        if ($scopeId <= 0) {
            $this->_cmsScope = false;
            return false;
        }

        // Re-validate server-side: the acting user MUST hold at least AUTH_EDIT
        // over the requested org (super-admins pass via HasAuthority's all-zero
        // short-circuit; publish-tier caps are gated separately via CmsCan).
        $uid      = (int)$uid;
        $authType = ($scopeType === 'park') ? AUTH_PARK : AUTH_KINGDOM;
        // Through the model layer (Model_Authorization::has_authority), never the
        // lib handle directly — that is how every other controller asks this, and
        // it keeps the auth gate swappable behind one seam.
        $this->load_model('Authorization');
        $ok = ($uid > 0)
            && isset($this->Authorization)
            && $this->Authorization->has_authority($uid, $authType, $scopeId, AUTH_EDIT);
        if (!$ok) {
            $this->_cmsScope = false;
            return false;
        }

        $this->_cmsScope = array('type' => $scopeType, 'id' => $scopeId);
        return $this->_cmsScope;
    }

    /** True when the resolved scope is the global front door. */
    private function _scopeIsGlobal($scope)
    {
        return !is_array($scope) || (string)($scope['type'] ?? 'global') === 'global';
    }

    /**
     * The `scope_type`/`scope_id` filter pair for the scope-aware list/read libs.
     * For global this is ('global', 0) — which the libs already treat as the
     * legacy default, keeping global reads scoped to global-only rows.
     *
     * @param array $scope
     * @return array{scope_type:string,scope_id:int}
     */
    private function _scopeFilters($scope)
    {
        return array(
            'scope_type' => is_array($scope) ? (string)($scope['type'] ?? 'global') : 'global',
            'scope_id'   => is_array($scope) ? (int)($scope['id'] ?? 0) : 0,
        );
    }

    /**
     * The URL query fragment ('&scope=k:5' or '') to append to intra-admin links
     * and AJAX fetch URLs so the active scope rides along. Empty for global so
     * legacy URLs stay clean. UIR already ends in '?Route=' → always join with &.
     *
     * @param array $scope
     * @return string
     */
    private function _scopeQuery($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        $prefix = CmsSite::UrlPrefixFor($scope['type']);
        return '&scope=' . $prefix . ':' . (int)$scope['id'];
    }

    /**
     * The bare selector string ('k:5' / 'p:3' / '') echoed to the client as
     * window.CMS_SCOPE so admin JS can append it to fetch URLs for re-validation.
     *
     * @param array $scope
     * @return string
     */
    private function _scopeSelector($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        $prefix = CmsSite::UrlPrefixFor($scope['type']);
        return $prefix . ':' . (int)$scope['id'];
    }

    /**
     * Human org label for the CMS context banner ('' for global). The org-name
     * read is pushed into the Kingdom/Park libs (via the model pass-through) so
     * this controller trait carries no raw $DB (architecture-layer rule).
     *
     * @param array $scope
     * @return string e.g. 'Kingdom of Foo' name, or '' for global
     */
    private function _scopeOrgLabel($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        $type = (string)$scope['type'];
        $id   = (int)$scope['id'];
        if ($id <= 0) {
            return '';
        }
        if ($type === 'park') {
            // Park has no single-column name getter; the existing short-info
            // read carries the name and already lives in the Park lib.
            $this->load_model('Park');
            $info = $this->Park->GetParkShortInfo(array('ParkId' => $id));
            return (is_array($info) && isset($info['ParkInfo']['ParkName']))
                ? (string)$info['ParkInfo']['ParkName']
                : '';
        }
        $this->load_model('Kingdom');
        return (string)$this->Kingdom->GetName($id);
    }

    /**
     * The org-unit NOUN for the active scope: 'Kingdom', 'Principality', 'Park',
     * or '' for global. Use this anywhere CMS copy would otherwise hard-code
     * "kingdom".
     *
     * Amtgard stores a principality as an ork_kingdom row with a parent kingdom,
     * so a principality's site is an ordinary kingdom-scoped site — nothing about
     * the scope model changes. Only the wording does, and getting that wording
     * wrong tells a principality's officers something untrue about their own org.
     *
     * @param array $scope
     * @return string
     */
    private function _scopeOrgNoun($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        $this->load_model('CmsSite');
        return (string)$this->CmsSite->org_unit_noun(
            (string)($scope['type'] ?? ''),
            (int)($scope['id'] ?? 0)
        );
    }

    /**
     * THE single "may this user edit-FAB / preview this scope" gate for the
     * public CMS surfaces (Page/Blog/Site). Resolves via CmsCan('page.edit', $scope)
     * so ALL of — an ORK super-admin, a global ork_cms_grant, a matching
     * kingdom/park ork_cms_grant, AND the HasAuthority(AUTH_EDIT) officer bridge —
     * consistently govern the edit FAB and the unpublished-site preview EVERYWHERE.
     *
     * This replaces three divergent per-controller implementations: Controller_Page
     * called CmsCan directly, Controller_Blog hand-rolled is_super_admin +
     * get_user_capabilities, and Controller_Site called HasAuthority(AUTH_EDIT)
     * raw (which silently ignored ork_cms_grant). Routing them all through one
     * helper means a kingdom/park content grant now governs the gate uniformly.
     *
     * @param int   $uid   acting mundane_id (from $this->session->user_id)
     * @param array $scope ['type'=>'global'|'kingdom'|'park','id'=>int]
     * @return bool
     */
    private function _cmsCanEditScope($uid, $scope)
    {
        return $this->_cmsCanScope($uid, 'page.edit', $scope);
    }

    /**
     * One capability probe against the resolved scope, memoized for the request.
     *
     * The public renderers ask the same question more than once per render (the
     * unpublished-site preview gate, then the edit FAB, then the new-post FAB),
     * and each miss is a grant + officer-authority round trip. The memo is
     * request-scoped and keyed by the full (uid, capability, scope) tuple, so it
     * can only ever return the answer cms_can would have returned a moment
     * earlier — no capability decision changes, only how often it is asked.
     *
     * Used by the PUBLIC surfaces (Page/Blog/Site) via _cmsCanEditScope and
     * _cmsFabData. The admin controllers do not route through here.
     *
     * @param int    $uid   acting mundane_id
     * @param string $cap   CMS capability name ('page.edit', 'page.create', …)
     * @param array  $scope ['type'=>'global'|'kingdom'|'park','id'=>int]
     * @return bool
     */
    private function _cmsCanScope($uid, $cap, $scope)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return false;
        }
        if (!is_array($scope)) {
            $scope = array('type' => 'global', 'id' => 0);
        }
        $key = $uid . '|' . (string) $cap . '|'
            . (string) ($scope['type'] ?? 'global') . '|' . (int) ($scope['id'] ?? 0);
        if (!array_key_exists($key, $this->_cmsCapMemo)) {
            $this->load_model('CmsAuth');
            $this->_cmsCapMemo[$key] = (bool) $this->CmsAuth->cms_can($uid, (string) $cap, $scope);
        }
        return $this->_cmsCapMemo[$key];
    }

    /**
     * Publish the floating CMS FAB flags (rendered by default.theme) for a public
     * surface: the pen "edit" FAB when the viewer may edit this scope, plus an
     * optional second "new post" FAB.
     *
     * THE NEW-POST CAPABILITY IS THE CALLER'S, and that is deliberate: the global
     * blog gates its new-post FAB on 'page.create' (a contributor may draft a post
     * without holding edit rights over the surface), while an org site gates it on
     * 'page.edit' — the same decision that governs its unpublished-site preview.
     * Collapsing the two would silently widen or narrow who sees the button.
     *
     * @param int        $uid     acting mundane_id (from $this->session->user_id)
     * @param array      $scope   ['type'=>'global'|'kingdom'|'park','id'=>int]
     * @param string     $editUrl pen-FAB target
     * @param string     $editTip pen-FAB tooltip
     * @param array|null $newPost null for no second FAB, else
     *                            ['url'=>…, 'tip'=>…, 'cap'=>'page.edit'|'page.create']
     * @return void
     */
    private function _cmsFabData($uid, $scope, $editUrl, $editTip, $newPost = null)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return;   // signed out — no FABs at all
        }
        if ($this->_cmsCanEditScope($uid, $scope)) {
            $this->data['cmsEditUrl'] = $editUrl;
            $this->data['cmsEditTip'] = $editTip;
        }
        if (!is_array($newPost) || empty($newPost['url'])) {
            return;
        }
        if ($this->_cmsCanScope($uid, (string) ($newPost['cap'] ?? 'page.create'), $scope)) {
            $this->data['cmsNewPostUrl'] = (string) $newPost['url'];
            $this->data['cmsNewPostTip'] = (string) ($newPost['tip'] ?? 'New post');
        }
    }

    /**
     * The shared paginated post-list read behind BOTH public blog indexes
     * (Controller_Blog::index at global scope and Controller_Site::blog at org
     * scope): fetch one page, then clamp a ?p= beyond the last page so the OFFSET
     * can never run past the result set — an unbounded page number would otherwise
     * make the DB scan the whole set to return nothing. Only an out-of-range
     * request costs the second query.
     *
     * Callers own everything surface-specific: their own PER_PAGE, their own extra
     * list_posts options ('tag' for the global index, 'includeDrafts' for an
     * officer previewing an unpublished org site), and their own view-var names.
     *
     * @param array $listArgs scope filters + extra list_posts opts; 'limit' and
     *                        'offset' are supplied here and must not be passed in
     * @param int   $perPage
     * @param int   $pageNo   requested 1-based page (raw user input)
     * @return array{rows:array,total:int,page:int,pages:int} page = the CLAMPED page
     */
    private function _cmsPagedPosts(array $listArgs, $perPage, $pageNo)
    {
        $perPage = (int) $perPage;
        $pageNo  = (int) $pageNo;
        if ($pageNo < 1) {
            $pageNo = 1;
        }
        // limit/offset lead so the opts hash CmsPost::ListPosts memoizes on is
        // built from the same key order both callers used before this extraction.
        $listArgs = array_merge(
            array('limit' => $perPage, 'offset' => ($pageNo - 1) * $perPage),
            $listArgs
        );

        $this->load_model('CmsPost');
        $result = $this->CmsPost->list_posts($listArgs);
        $rows   = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();
        $total  = isset($result['total']) ? (int) $result['total'] : 0;
        $pages  = ($perPage > 0) ? (int) ceil($total / $perPage) : 1;
        if ($pages < 1) {
            $pages = 1;
        }

        if ($pageNo > $pages) {
            $pageNo             = $pages;
            $listArgs['offset'] = ($pageNo - 1) * $perPage;
            $result = $this->CmsPost->list_posts($listArgs);
            $rows   = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();
            $total  = isset($result['total']) ? (int) $result['total'] : $total;
        }

        return array('rows' => $rows, 'total' => $total, 'page' => $pageNo, 'pages' => $pages);
    }

    /**
     * Memoized site row for the current non-global scope (false = not yet looked up).
     * Lives on the trait because BOTH consumers build public live URLs from it:
     * the page surfaces (Controller_Cms) and the JSON endpoints
     * (Controller_CmsAjax::pagelist, which hands the link chooser the same URL
     * shape the admin list links to).
     * @var array|false|null
     */
    private $_scopeSiteMemo = false;

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
     * @param array|null $pathMap  optional pageId => full-slug-path map to
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
        // walks parent_id). Prefer the precomputed in-memory path map; only
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
     * Build a pageId => full-slug-path map from the already-fetched admin
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
     * True when a page/post/media row belongs to the resolved scope — the IDOR
     * guard for every by-id mutation. A row with no scope columns is treated as
     * global ('global', 0), matching the libs' create defaults.
     *
     * @param array|null $row   the fetched target row (must carry scope_type/id)
     * @param array      $scope the resolved, authorized request scope
     * @return bool
     */
    private function _rowInScope($row, $scope)
    {
        if (!is_array($row) || !is_array($scope)) {
            return false;
        }
        $rt = isset($row['scope_type']) ? (string)$row['scope_type'] : 'global';
        $ri = isset($row['scope_id']) ? (int)$row['scope_id'] : 0;
        return $rt === (string)($scope['type'] ?? 'global')
            && $ri === (int)($scope['id'] ?? 0);
    }
}
