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
 * Pretty URLs (via nginx `location ^~ /k/` — see nginx.ork3.config):
 *   /k/{slug}                       → Site/view/{slug}
 *   /k/{slug}/blog                  → Site/blog/{slug}
 *   /k/{slug}/post/{postSlug}       → Site/post/{slug}/{postSlug}
 *   /k/{slug}/{pageSlug}            → Site/page/{slug}/{pageSlug}
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

        $blocks     = array();
        $homePageId = (int) ($site['home_page_id'] ?? 0);
        if ($homePageId > 0) {
            $this->load_model('CmsPage');
            $page = $this->CmsPage->get_page($homePageId);
            // The home pointer must resolve to a PUBLISHED page owned by THIS
            // site's scope — otherwise a public visitor could see an unpublished
            // or cross-scope page. Fall through to the empty state if not.
            if (!empty($page)
                && (string) $page['status'] === 'published'
                && (string) $page['scope_type'] === $scopeType
                && (int) $page['scope_id'] === $scopeId
            ) {
                $blocks = $this->CmsPage->get_page_blocks($homePageId);
            }
        }

        $this->data['SiteMode']   = 'home';
        $this->data['SiteBlocks'] = is_array($blocks) ? $blocks : array();
        if (empty($this->data['SiteBlocks'])) {
            $this->data['Message'] = 'This site is being built. Please check back soon.';
            // Don't let the placeholder interstitial get indexed as public content.
            $this->data['no_index'] = true;
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

        $pageSlug = trim((string) $pageSlug);
        $page     = null;
        if ($pageSlug !== '') {
            $this->load_model('CmsPage');
            $page = $this->CmsPage->get_page_by_slug($pageSlug, $scopeType, $scopeId, true);
        }

        if (empty($page)) {
            $this->_markNotFound('This page could not be found.');
            return;
        }

        $this->data['SiteMode']         = 'page';
        $this->data['SiteBlocks']       = $this->CmsPage->get_page_blocks((int) $page['page_id']);
        $this->data['page_title']       = (string) $page['title'];
        $this->data['meta_description'] = isset($page['meta_description']) ? (string) $page['meta_description'] : '';
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
        $result = $this->CmsPost->list_posts(array(
            'limit'      => $perPage,
            'offset'     => $offset,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
        ));
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
            $post = $this->CmsPost->get_post_by_slug($postSlug, $scopeType, $scopeId, true);
        }

        if (empty($post)) {
            $this->_markNotFound('This post could not be found.');
            return;
        }

        $this->data['SiteMode']         = 'post';
        $this->data['SitePost']         = $post;
        $this->data['SiteBlocks']       = $this->CmsPost->get_post_blocks((int) $post['post_id']);
        $this->data['page_title']       = (string) $post['title'];
        $this->data['meta_description'] = isset($post['excerpt']) ? (string) $post['excerpt'] : '';
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
        $status = (string) ($site['status'] ?? 'unbuilt');
        if ($status === 'unbuilt') {
            // No public identity yet — render a BARE not-found (pass null, not the
            // site row) so an in-progress site is indistinguishable from an
            // unknown slug: no name/logo/nav leak that a site exists in progress.
            $this->_renderNotFound(null);
            return true;
        }
        if ($status !== 'published') {
            // Draft: lightweight branded "coming soon"; NEVER render page bodies.
            $this->_renderComingSoon($site);
            return true;
        }
        return false;
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
        $this->data['no_index']  = false;

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
