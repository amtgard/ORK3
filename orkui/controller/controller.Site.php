<?php

/**
 * Controller_Site — public renderer for per-org CMS "sites" (CMS Multi-Site).
 *
 * A "site" (ork_cms_site) gives a kingdom (park later) its own addressable,
 * publishable website built from the existing scope-keyed ork_cms_* content.
 * This controller resolves a site by slug, then renders its published pages /
 * posts through the SAME shared front-door block renderer inside a STANDALONE
 * org chrome (default.theme with $IsOrgSite=true → no global ORK nav/footer).
 *
 * Scope is ALWAYS read from the resolved site row (scope_type/scope_id), never
 * from user input — the slug is the only thing the visitor controls, and it is
 * charset-normalized inside CmsSite::GetSiteBySlug before the lookup.
 *
 * Routes (raw — work with or without the /k/ pretty rewrite):
 *   Site/view/{slug}                → view($slug)   site home (home_page_id page)
 *   Site/page/{slug}/{pageSlug}     → page("{slug}/{pageSlug}")  scoped page
 *   Site/blog/{slug}                → blog($slug)   scoped blog index
 *   Site/post/{slug}/{postSlug}     → post("{slug}/{postSlug}")  scoped post
 *
 * Pretty URLs (via nginx `location ^~ /k/` and `^~ /p/` — see nginx.ork3.config).
 * /k/ is the KINGDOM namespace, /p/ the PARK namespace (C23); each rewrite adds
 * a &_pfx=k|p hint so _enforcePrefix() can 301 a site reached under the wrong
 * prefix to its canonical one. The page form is multi-segment (C13 nested pages):
 *   /k/{slug}                       → Site/view/{slug}
 *   /k/{slug}/blog                  → Site/blog/{slug}
 *   /k/{slug}/post/{postSlug}       → Site/post/{slug}/{postSlug}
 *   /k/{slug}/{a}/{b}/…             → Site/page/{slug}/{a}/{b}/…  (nested path)
 *   /p/{slug}/…                     → same, park scope
 *
 * NOTE on view(): the framework calls the controller twice — first as the action
 * handler ($C->view($slug), one arg) to populate data, then as the base render
 * step ($C->view(), zero args). Because the action name collides with the base
 * render method, view() dispatches on arg count (mirrors Controller_Page).
 *
 * Multi-segment routes ("Site/page/a/b") arrive as ONE joined string arg
 * ("a/b") because the dispatcher collapses segments 3+ with implode('/'); the
 * action splits it back into site-slug + page/post-slug via _splitPath().
 *
 * Error states never leak draft content or a stack trace:
 *   unknown slug / status='unbuilt' → clean branded 404
 *   status='draft' (any non-published) → lightweight branded "coming soon"
 *   published but missing page/post   → branded 404 inside the org chrome
 */
class Controller_Site extends Controller
{
    /** Posts per page on the scoped blog index. */
    public const PER_PAGE = 12;

    /** True when the viewer is an authorized officer previewing an unpublished site. */
    private $_isPreview = false;

    /** Memoized "viewer may edit this org" (AUTH_EDIT) — powers preview + the edit FAB. */
    private $_canEditMemo = null;

    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        // Standalone org site: drop the inherited global ORK breadcrumb items.
        // ($IsOrgSite also suppresses the global nav bar + footer in default.theme.)
        unset(
            $this->data['menu']['kingdom'],
            $this->data['menu']['park'],
            $this->data['menu']['home'],
            $this->data['menu']['admin']
        );
    }

    /**
     * Bare Site (no slug) has no public identity → clean 404.
     */
    public function index($action = null)
    {
        $this->_renderNotFound(null);
    }

    /**
     * Site home: the site's home_page_id page rendered as blocks.
     */
    public function view($slug = null)
    {
        // Zero-arg call = framework render step → delegate to base renderer.
        if (func_num_args() === 0) {
            return parent::view();
        }

        $site = $this->_resolveSite($slug);
        if ($this->_requirePublished($site)) {
            return;
        }

        $this->_bootShell($site);
        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];

        $blocks      = array();
        $homePageId  = (int) ($site['home_page_id'] ?? 0);
        $homePage    = null;
        $homeUsable  = false;   // home pointer resolves to a live, in-scope page
        if ($homePageId > 0) {
            $this->load_model('CmsPage');
            $homePage = $this->CmsPage->get_page($homePageId);
            // The home pointer must resolve to a PUBLISHED page owned by THIS
            // site's scope — otherwise a public visitor could see an unpublished
            // or cross-scope page. Fall through to the empty state if not.
            if (!empty($homePage)
                && ((string) $homePage['status'] === 'published' || $this->_isPreview)
                && (string) $homePage['scope_type'] === $scopeType
                && (int) $homePage['scope_id'] === $scopeId
            ) {
                $blocks     = $this->CmsPage->get_page_blocks($homePageId);
                $homeUsable = true;
            }
        }

        $this->data['SiteMode']   = 'home';
        $this->data['SiteBlocks'] = is_array($blocks) ? $blocks : array();
        if (empty($this->data['SiteBlocks'])) {
            $this->data['Message'] = 'This site is being built. Please check back soon.';
            // Don't let the placeholder interstitial get indexed as public content.
            $this->data['no_index'] = true;

            // C30: a PUBLISHED site whose home page is blank / unpublished / missing
            // would silently show the public "being built" interstitial. Surface an
            // actionable warning to an editing officer (preview only — never public)
            // so the misconfiguration is visible instead of looking like a dead site.
            if ((string) ($site['status'] ?? '') === 'published' && $this->_viewerCanPreview($site)) {
                if ($homePageId <= 0) {
                    $this->data['SiteHomeWarning'] =
                        'This site is published but has no home page set. Choose a home page in Site settings.';
                } elseif (!$homeUsable) {
                    $this->data['SiteHomeWarning'] = (!empty($homePage)
                        && (string) ($homePage['status'] ?? '') !== 'published')
                        ? 'This site is published but its home page is not published yet. Publish it, or pick another home page.'
                        : 'This site is published but its home page is missing. Pick a new home page in Site settings.';
                }
            }
        }
        if ($homePageId > 0) {
            // C6: canonical + OG for the site home (type=website).
            if (!empty($homePage) && $homeUsable) {
                $this->_setPageMeta($site, $homePage, 'website', true);
            }
            $this->_cmsFab($site, UIR . 'Cms/edit/' . $homePageId . $this->_scopeQ($site), 'Edit this page');
        }
    }

    /**
     * A published scoped page by slug: Site/page/{slug}/{pageSlug}.
     */
    public function page($path = null)
    {
        list($siteSlug, $pageSlug) = $this->_splitPath($path);

        $site = $this->_resolveSite($siteSlug);
        if ($this->_requirePublished($site)) {
            return;
        }

        $this->_bootShell($site);
        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];

        // C13: $pageSlug may be a NESTED path ("parent/child") — resolve it one
        // segment at a time by walking parent_id (a single segment is the flat
        // case, unchanged).
        $pageSlug = trim((string) $pageSlug, '/ ');
        $page     = null;
        if ($pageSlug !== '') {
            $this->load_model('CmsPage');
            $page = $this->CmsPage->GetPageByPath($pageSlug, $scopeType, $scopeId, !$this->_isPreview);
        }

        if (empty($page)) {
            // C17: before the branded 404, honor a 301 redirect for this path (set
            // when a page slug was renamed) so old links/bookmarks keep working.
            if ($this->_tryRedirect($site, $pageSlug)) {
                return;
            }
            $this->_markNotFound('This page could not be found.');
            return;
        }

        $pageId = (int) $page['page_id'];
        $this->data['SiteMode']         = 'page';
        $this->data['SiteBlocks']       = $this->CmsPage->get_page_blocks($pageId);
        $this->data['page_title']       = (string) $page['title'];
        $this->data['meta_description'] = isset($page['meta_description']) ? (string) $page['meta_description'] : '';

        // C13: breadcrumbs (root → parent → this page). Dropped before this change.
        $this->data['SiteBreadcrumbs'] = $this->_breadcrumbs($site, $page);

        // C6: per-page canonical + OG derived from the page (hero image → og:image).
        $this->_setPageMeta($site, $page, 'article');

        $this->_cmsFab($site, UIR . 'Cms/edit/' . $pageId . $this->_scopeQ($site), 'Edit this page');
    }

    /**
     * The scoped blog index: Site/blog/{slug} (?p= page).
     * Thin reuse of CmsPost::list_posts with the resolved scope.
     */
    public function blog($slug = null)
    {
        $site = $this->_resolveSite($slug);
        if ($this->_requirePublished($site)) {
            return;
        }

        $this->_bootShell($site);
        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];

        $pageNo = isset($_GET['p']) ? (int) $_GET['p'] : 1;
        if ($pageNo < 1) {
            $pageNo = 1;
        }
        $perPage = self::PER_PAGE;
        $offset  = ($pageNo - 1) * $perPage;

        $this->load_model('CmsPost');
        $listArgs = array(
            'limit'      => $perPage,
            'offset'     => $offset,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
        );
        if ($this->_isPreview) {
            $listArgs['includeDrafts'] = true; // officer preview shows draft posts too
        }
        $result = $this->CmsPost->list_posts($listArgs);
        $rows  = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();
        $total = isset($result['total']) ? (int) $result['total'] : 0;
        $pages = ($perPage > 0) ? (int) ceil($total / $perPage) : 1;
        if ($pages < 1) {
            $pages = 1;
        }

        $this->data['SiteMode']       = 'blog';
        $this->data['SitePosts']      = $rows;
        $this->data['SitePostsPage']  = $pageNo;
        $this->data['SitePostsPages'] = $pages;
        $this->data['page_title']     = ($this->data['SiteName'] !== '' ? $this->data['SiteName'] . ' — ' : '') . 'News';

        // C6: canonical + OG for the blog index (type=website). Page 1 canonicals
        // to /blog; deeper pages self-canonical with the ?p= arg to avoid dupes.
        $siteName = trim((string) ($this->data['SiteName'] ?? ''));
        $canon    = $this->_siteBaseUrl($site) . '/blog' . ($pageNo > 1 ? '?p=' . $pageNo : '');
        $ogImage  = !empty($this->data['SiteLogoUrl']) ? (string) $this->data['SiteLogoUrl'] : '';
        if ($ogImage !== '' && !preg_match('#^https?://#i', $ogImage)) {
            $ogImage = $this->_origin() . '/' . ltrim($ogImage, '/');
        }
        $this->data['PageMeta'] = array(
            'canonical'   => $canon,
            'og_type'     => 'website',
            'og_title'    => ($siteName !== '' ? $siteName . ' — News' : 'News'),
            'og_desc'     => '',
            'og_image'    => $ogImage,
            'og_sitename' => $siteName,
        );

        $this->_cmsFab($site, UIR . 'Cms/posts' . $this->_scopeQ($site), 'Manage posts', true);
    }

    /**
     * A published scoped post by slug: Site/post/{slug}/{postSlug}.
     * Thin reuse of CmsPost::get_post_by_slug + get_post_blocks with scope.
     */
    public function post($path = null)
    {
        list($siteSlug, $postSlug) = $this->_splitPath($path);

        $site = $this->_resolveSite($siteSlug);
        if ($this->_requirePublished($site)) {
            return;
        }

        $this->_bootShell($site);
        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];

        $postSlug = trim((string) $postSlug);
        $post     = null;
        if ($postSlug !== '') {
            $this->load_model('CmsPost');
            $post = $this->CmsPost->get_post_by_slug($postSlug, $scopeType, $scopeId, !$this->_isPreview);
        }

        if (empty($post)) {
            // C17: honor a redirect for the /post/{slug} path before the 404.
            if ($this->_tryRedirect($site, 'post/' . $postSlug)) {
                return;
            }
            $this->_markNotFound('This post could not be found.');
            return;
        }

        $this->data['SiteMode']         = 'post';
        $this->data['SitePost']         = $post;
        $this->data['SiteBlocks']       = $this->CmsPost->get_post_blocks((int) $post['post_id']);
        $this->data['page_title']       = (string) $post['title'];
        $this->data['meta_description'] = isset($post['excerpt']) ? (string) $post['excerpt'] : '';

        // C6: per-post canonical + OG (hero image → og:image; type=article).
        $this->_setPostMeta($site, $post);

        $this->_cmsFab($site, UIR . 'Cms/editpost/' . (int) $post['post_id'] . $this->_scopeQ($site), 'Edit this post', true);
    }

    /* ==================================================================
     * Internals
     * ================================================================ */

    /**
     * Resolve a slug to a site row (or null). The slug is normalized to the
     * [a-z0-9-] charset inside CmsSite::GetSiteBySlug, so nothing beyond the
     * lookup key ever reaches the DB from user input.
     */
    private function _resolveSite($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }
        $this->load_model('CmsSite');
        $site = $this->CmsSite->get_site_by_slug($slug);
        return (is_array($site) && !empty($site)) ? $site : null;
    }

    /**
     * Split a joined multi-segment route arg ("{slug}/{pageSlug}") into its
     * first segment (site slug) and the remainder (page/post slug).
     *
     * @return array{0:string,1:string}
     */
    private function _splitPath($path)
    {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return array('', '');
        }
        $parts = explode('/', $path, 2);
        return array($parts[0], isset($parts[1]) ? $parts[1] : '');
    }

    /**
     * Enforce the publish lifecycle before any content renders. Returns true
     * when a terminal state (404 / coming-soon) was rendered and the caller
     * should return immediately; false when the site is published.
     *
     * unknown / unbuilt → 404; draft (any non-published) → coming soon.
     */
    private function _requirePublished($site)
    {
        if ($site === null) {
            $this->_renderNotFound(null);
            return true;
        }

        // C23: enforce the /k (kingdom) vs /p (park) namespace. Slugs share one
        // global pool, so a park site is ALSO resolvable by slug under /k/ (and
        // vice-versa); the nginx rewrite passes a &_pfx=k|p hint identifying which
        // prefix the visitor actually used. When it disagrees with the resolved
        // site's real scope, 301 to the canonical prefix (preserving the full path
        // + query) so a park always lives at /p/ and a kingdom at /k/ — one URL per
        // site, no duplicate-content ambiguity. Raw Site/* routes (no hint) skip.
        if ($this->_enforcePrefix($site)) {
            return true;
        }
        $status = (string) ($site['status'] ?? 'unbuilt');
        if ($status !== 'published') {
            // Authorized officers PREVIEW their own unpublished site (see the
            // seeded / draft content before go-live) — the whole point of building
            // a site is to look at it before publishing. The PUBLIC still gets the
            // gated states below.
            if ($this->_viewerCanPreview($site)) {
                $this->_isPreview          = true;
                $this->data['SitePreview'] = true;
                return false;
            }
            if ($status === 'unbuilt') {
                // No public identity yet — render a BARE not-found (pass null, not
                // the site row) so an in-progress site is indistinguishable from an
                // unknown slug: no name/logo/nav leak that a site exists in progress.
                $this->_renderNotFound(null);
                return true;
            }
            // Draft: lightweight branded "coming soon"; NEVER render page bodies.
            $this->_renderComingSoon($site);
            return true;
        }
        return false;
    }

    /**
     * True when the current viewer is an officer authorized to EDIT this site's
     * org (kingdom/park) — they may preview it before it is published. Uses the
     * same HasAuthority(AUTH_EDIT) gate as the CMS admin (super-admins pass via
     * HasAuthority's all-zero short-circuit).
     *
     * @param array $site
     * @return bool
     */
    private function _viewerCanPreview($site)
    {
        if ($this->_canEditMemo !== null) {
            return $this->_canEditMemo;
        }
        $ok  = false;
        $uid = (int) ($this->session->user_id ?? 0);
        if ($uid > 0 && is_array($site) && is_object(Ork3::$Lib->authorization)) {
            $type     = (string) ($site['scope_type'] ?? '');
            $id       = (int) ($site['scope_id'] ?? 0);
            $authType = ($type === 'park') ? AUTH_PARK : AUTH_KINGDOM;
            $ok = $id > 0
                && Ork3::$Lib->authorization->HasAuthority($uid, $authType, $id, AUTH_EDIT);
        }
        $this->_canEditMemo = $ok;
        return $ok;
    }

    /**
     * C23: canonical-prefix guard. Returns true (and issues a 301) when the URL
     * prefix the visitor used (&_pfx=k|p) does not match the resolved site's real
     * scope_type — the caller should then return. No hint (raw route) → no-op.
     *
     * @param array $site
     * @return bool true when a redirect was issued
     */
    private function _enforcePrefix($site)
    {
        $hint = isset($_GET['_pfx']) ? strtolower((string) $_GET['_pfx']) : '';
        if ($hint !== 'k' && $hint !== 'p') {
            return false; // raw Site/* route — nothing to enforce
        }
        $wantType   = ($hint === 'p') ? 'park' : 'kingdom';
        $actualType = ((string) ($site['scope_type'] ?? '') === 'park') ? 'park' : 'kingdom';
        if ($wantType === $actualType) {
            return false;
        }

        // Mismatch → 301 to the correct prefix, preserving the rest of the path
        // and query string. Swap only the leading /k/ or /p/ segment.
        $correct = $this->_prefixFor($actualType);
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '');
        // Strip the &_pfx hint we injected (it isn't part of the pretty URL).
        $uri = preg_replace('/([?&])_pfx=[kp](&|$)/', '$1', $uri);
        $uri = rtrim($uri, '?&');
        if (preg_match('#^/[kp]/#', $uri)) {
            $target = preg_replace('#^/[kp]/#', '/' . $correct . '/', $uri);
        } else {
            // Reached via a raw route with a hint but no pretty path — fall back to
            // the canonical site home under the correct prefix.
            $target = '/' . $correct . '/' . rawurlencode((string) ($site['slug'] ?? ''));
        }
        http_response_code(301);
        header('Location: ' . $target, true, 301);
        exit;
    }

    /** The single-letter URL/scope prefix for a scope_type: park → 'p', everything else → 'k'. */
    private function _prefixFor($scopeType)
    {
        return ((string) $scopeType === 'park') ? 'p' : 'k';
    }

    /** The '&scope=k:17' / '&scope=p:3' fragment for linking into the scoped CMS admin. */
    private function _scopeQ($site)
    {
        $prefix = $this->_prefixFor($site['scope_type'] ?? 'kingdom');
        return '&scope=' . $prefix . ':' . (int) ($site['scope_id'] ?? 0);
    }

    /**
     * Expose the CMS edit / new-post FAB (rendered by default.theme) when the
     * viewer may edit this org — on published AND preview pages alike. Links
     * point into the SCOPED CMS admin so edits land in the org's own content,
     * never the global front door.
     *
     * @param array  $site
     * @param string $editUrl     already-built edit link (Cms/edit|editpost|posts + scope)
     * @param string $editTip     tooltip for the pen FAB
     * @param bool   $withNewPost also show the "new post" (feather) FAB
     * @return void
     */
    private function _cmsFab($site, $editUrl, $editTip, $withNewPost = false)
    {
        if (!$this->_viewerCanPreview($site)) {
            return;
        }
        $this->data['cmsEditUrl'] = $editUrl;
        $this->data['cmsEditTip'] = $editTip;
        if ($withNewPost) {
            $this->data['cmsNewPostUrl'] = UIR . 'Cms/editpost/new' . $this->_scopeQ($site);
            $this->data['cmsNewPostTip'] = 'New post';
        }
    }

    /**
     * Populate the standalone org-chrome shell for a resolved site: template,
     * $IsOrgSite flag, header identity (name/logo/home url), scoped nav scope,
     * and the per-org theme tokens (scoped GetActiveCss; unthemed → '' → the
     * frontdoor.css :root defaults, i.e. today's look).
     */
    private function _bootShell($site)
    {
        $this->template          = 'Site_shell.tpl';
        $this->data['IsOrgSite'] = true;
        // A preview render (unpublished site, officer viewer) must never be indexed.
        $this->data['no_index']  = $this->_isPreview;

        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];
        $slug      = (string) $site['slug'];
        $siteName  = trim((string) ($site['site_name'] ?? ''));

        $this->data['SiteName']         = $siteName;
        $this->data['SiteSlug']         = $slug;
        $this->data['SiteHomeUrl']      = UIR . 'Site/view/' . rawurlencode($slug);
        $this->data['SiteNavScopeType'] = $scopeType;
        $this->data['SiteNavScopeId']   = $scopeId;
        $this->data['SiteLogoUrl']      = $this->_logoUrl($site);
        $this->data['page_title']       = $siteName !== '' ? $siteName : 'Kingdom Site';

        // Per-org theme tokens (scoped to the site's own scope, not global).
        $this->load_model('CmsTheme');
        $css = (string) $this->CmsTheme->get_active_css($scopeType, $scopeId);
        if ($css !== '') {
            $this->data['fdThemeCss'] = $css;
        }
    }

    /* ==================================================================
     * C6 — per-page canonical + Open Graph meta
     * ================================================================== */

    /**
     * The scheme+host origin for absolute canonical/OG URLs, derived from the
     * live request (honors the CF-forwarded proto when present).
     */
    private function _origin()
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . $host;
    }

    /** The pretty-URL base for this site: {origin}/{k|p}/{slug} (no trailing slash). */
    private function _siteBaseUrl($site)
    {
        $prefix = $this->_prefixFor($site['scope_type'] ?? 'kingdom');
        $slug   = rawurlencode((string) ($site['slug'] ?? ''));
        return $this->_origin() . '/' . $prefix . '/' . $slug;
    }

    /**
     * Resolve a media id to an ABSOLUTE image URL for og:image (or '' when
     * unset/missing). Mirrors _logoUrl but returns an absolute URL.
     */
    private function _absMediaUrl($mediaId)
    {
        $mediaId = (int) $mediaId;
        if ($mediaId <= 0) {
            return '';
        }
        $this->load_model('CmsMedia');
        $media = $this->CmsMedia->get_media($mediaId);
        $url   = (is_array($media) && !empty($media['url'])) ? (string) $media['url'] : '';
        if ($url === '') {
            return '';
        }
        // Already absolute? leave it; otherwise prefix the origin.
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $this->_origin() . '/' . ltrim($url, '/');
    }

    /**
     * C6: publish a per-page $PageMeta block (canonical + og:*) so default.theme
     * emits page-specific tags instead of leaking the GLOBAL ORK branding onto
     * every org-site page. og:image falls back to the site logo, then the theme's
     * ORK default (handled in default.theme) when neither is set.
     *
     * @param array  $site
     * @param array  $page  page row (title, meta_description, hero_media_id, slug)
     * @param string $type  og:type ('website'|'article')
     * @param bool   $isHome true → canonical is the site base (no page path)
     */
    private function _setPageMeta($site, $page, $type = 'article', $isHome = false)
    {
        $base  = $this->_siteBaseUrl($site);
        $canon = $base;
        if (!$isHome) {
            $path = $this->CmsPage->PagePath((int) $page['page_id']);
            if ($path !== '') {
                $canon = $base . '/' . $path;
            }
        }

        $ogImage = $this->_absMediaUrl((int) ($page['hero_media_id'] ?? 0));
        if ($ogImage === '' && !empty($this->data['SiteLogoUrl'])) {
            $logo = (string) $this->data['SiteLogoUrl'];
            $ogImage = preg_match('#^https?://#i', $logo) ? $logo : ($this->_origin() . '/' . ltrim($logo, '/'));
        }

        $siteName = trim((string) ($this->data['SiteName'] ?? ''));
        $title    = trim((string) ($page['title'] ?? ''));
        $ogTitle  = $title . ($siteName !== '' && $title !== $siteName ? ' — ' . $siteName : '');

        $this->data['PageMeta'] = array(
            'canonical'   => $canon,
            'og_type'     => ($type === 'website') ? 'website' : 'article',
            'og_title'    => ($ogTitle !== '') ? $ogTitle : $siteName,
            'og_desc'     => trim((string) ($page['meta_description'] ?? '')),
            'og_image'    => $ogImage,
            'og_sitename' => $siteName,
        );
    }

    /** C6: canonical + OG for a scoped blog POST (/post/{slug}). */
    private function _setPostMeta($site, $post)
    {
        $base    = $this->_siteBaseUrl($site);
        $slug    = rawurlencode((string) ($post['slug'] ?? ''));
        $canon   = $base . '/post/' . $slug;
        $ogImage = $this->_absMediaUrl((int) ($post['hero_media_id'] ?? 0));
        if ($ogImage === '' && !empty($this->data['SiteLogoUrl'])) {
            $logo = (string) $this->data['SiteLogoUrl'];
            $ogImage = preg_match('#^https?://#i', $logo) ? $logo : ($this->_origin() . '/' . ltrim($logo, '/'));
        }
        $siteName = trim((string) ($this->data['SiteName'] ?? ''));
        $title    = trim((string) ($post['title'] ?? ''));

        $this->data['PageMeta'] = array(
            'canonical'   => $canon,
            'og_type'     => 'article',
            'og_title'    => ($title !== '' ? $title : $siteName),
            'og_desc'     => trim((string) ($post['excerpt'] ?? '')),
            'og_image'    => $ogImage,
            'og_sitename' => $siteName,
        );
    }

    /**
     * C13: build the breadcrumb trail (root → this page) for a nested page. Each
     * crumb is ['label','url']; the current page is the last crumb (no url). A
     * flat page yields a single home crumb + itself.
     */
    private function _breadcrumbs($site, $page)
    {
        $base   = $this->_siteBaseUrl($site);
        $crumbs = array(array('label' => 'Home', 'url' => $base));

        $ancestors = $this->CmsPage->GetPageAncestors((int) $page['page_id']);
        $prefix    = array();
        foreach ((is_array($ancestors) ? $ancestors : array()) as $anc) {
            $prefix[] = (string) $anc['slug'];
            $crumbs[] = array(
                'label' => (string) ($anc['title'] !== '' ? $anc['title'] : $anc['slug']),
                'url'   => $base . '/' . implode('/', $prefix),
            );
        }
        $crumbs[] = array('label' => (string) $page['title'], 'url' => '');
        return $crumbs;
    }

    /**
     * C17: issue a 301 for a renamed/aliased path within a site scope, if one is
     * recorded. Returns true when a redirect was sent (caller returns).
     *
     * @param array  $site
     * @param string $path path after the site slug (no leading slash)
     * @return bool
     */
    private function _tryRedirect($site, $path)
    {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return false;
        }
        $this->load_model('CmsPage');
        $hit = $this->CmsPage->LookupRedirect(
            (string) $site['scope_type'],
            (int) $site['scope_id'],
            $path
        );
        if (!is_array($hit) || empty($hit['url'])) {
            return false;
        }
        $target = (string) $hit['url'];
        // A relative target (a page path) resolves under this site's pretty base.
        if (!preg_match('#^https?://#i', $target) && $target[0] !== '/') {
            $target = $this->_siteBaseUrl($site) . '/' . ltrim($target, '/');
        }
        $code = ((int) ($hit['code'] ?? 301) === 302) ? 302 : 301;
        http_response_code($code);
        header('Location: ' . $target, true, $code);
        exit;
    }

    /**
     * Resolve the site's logo media id to a public URL, or '' when unset/missing.
     */
    private function _logoUrl($site)
    {
        $mediaId = (int) ($site['logo_media_id'] ?? 0);
        if ($mediaId <= 0) {
            return '';
        }
        $this->load_model('CmsMedia');
        $media = $this->CmsMedia->get_media($mediaId);
        return (is_array($media) && !empty($media['url'])) ? (string) $media['url'] : '';
    }

    /**
     * Mark the current (already-booted) org shell as a 404 without discarding
     * the org chrome — used when the SITE is valid/published but a requested
     * page/post is missing.
     */
    private function _markNotFound($message)
    {
        http_response_code(404);
        $this->data['SiteMode']   = 'notfound';
        $this->data['no_index']   = true;
        $this->data['Message']    = (string) $message;
        $this->data['page_title'] = 'Not found';
    }

    /**
     * Render a clean, branded 404. When $site is known we keep its chrome
     * (logo/name/nav); otherwise a bare shell with no org identity.
     */
    private function _renderNotFound($site)
    {
        http_response_code(404);
        if (is_array($site) && !empty($site)) {
            $this->_bootShell($site);
        } else {
            $this->template                 = 'Site_shell.tpl';
            $this->data['IsOrgSite']        = true;
            $this->data['SiteName']         = '';
            $this->data['SiteSlug']         = '';
            $this->data['SiteHomeUrl']      = '';
            $this->data['SiteLogoUrl']      = '';
            $this->data['SiteNavScopeType'] = 'kingdom';
            $this->data['SiteNavScopeId']   = 0;
        }
        $this->data['SiteMode']   = 'notfound';
        $this->data['no_index']   = true;
        $this->data['Message']    = 'This page could not be found.';
        $this->data['page_title'] = 'Not found';
    }

    /**
     * Render a lightweight branded "coming soon" for a draft/unpublished site.
     * Deliberately renders NO page bodies (no content leak).
     */
    private function _renderComingSoon($site)
    {
        $this->_bootShell($site);
        $this->data['SiteMode']   = 'comingsoon';
        $this->data['no_index']   = true;
        $this->data['page_title'] = ($this->data['SiteName'] !== '' ? $this->data['SiteName'] . ' — ' : '') . 'Coming soon';
    }
}
