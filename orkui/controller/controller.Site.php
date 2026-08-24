<?php

require_once __DIR__ . '/trait.CmsScope.php';

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
 *   Site/rss/{slug}                 → rss($slug)    scoped RSS 2.0 feed
 *
 * Pretty URLs (via nginx `location ^~ /k/` and `^~ /p/` — see nginx.ork3.config).
 * /k/ is the KINGDOM namespace, /p/ the PARK namespace (C23); each rewrite adds
 * a &_pfx=k|p hint so _enforcePrefix() can 301 a site reached under the wrong
 * prefix to its canonical one. The page form is multi-segment (C13 nested pages):
 *   /k/{slug}                       → Site/view/{slug}
 *   /k/{slug}/blog                  → Site/blog/{slug}
 *   /k/{slug}/rss                   → Site/rss/{slug}
 *   /k/{slug}/post/{postSlug}       → Site/post/{slug}/{postSlug}
 *   /k/{slug}/{a}/{b}/…             → Site/page/{slug}/{a}/{b}/…  (nested path)
 *   /p/{slug}/…                     → same, park scope
 *
 * NOTE on view(): it is a plain action. The framework's render step is
 * $C->render() (base Controller), so an action named view() does not collide
 * with it (mirrors Controller_Page).
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
    use CmsScopeContext;

    /** Posts per page on the scoped blog index. */
    public const PER_PAGE = 12;

    /** Hard cap on rows enumerated into the XML sitemap (per entity type). */
    private const SITEMAP_MAX = 5000;

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
                // #09: tally a public view of the home page (best-effort; the
                // home is a real published in-scope page here).
                $this->_recordCmsView($site, 'page', $homePageId);
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

        // #09: tally a public view of this page (best-effort; gated internally).
        $this->_recordCmsView($site, 'page', $pageId);

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

        $listArgs = array(
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
        );
        if ($this->_isPreview) {
            $listArgs['includeDrafts'] = true; // officer preview shows draft posts too
        }
        // Shared paginated fetch: clamps an out-of-range ?p= so the OFFSET can
        // never exceed the result set, refetching only when it was too high
        // (identical contract to Controller_Blog::index — see trait.CmsScope).
        $paged  = $this->_cmsPagedPosts($listArgs, self::PER_PAGE, isset($_GET['p']) ? (int) $_GET['p'] : 1);
        $pageNo = $paged['page'];

        $this->data['SiteMode']       = 'blog';
        $this->data['SitePosts']      = $paged['rows'];
        $this->data['SitePostsPage']  = $pageNo;
        $this->data['SitePostsPages'] = $paged['pages'];
        // Leaf only — _bootShell published the org half as SiteTitleOrg.
        $this->data['page_title']     = 'News';

        // C6: canonical + OG for the blog index (type=website). Page 1 canonicals
        // to /blog; deeper pages self-canonical with the ?p= arg to avoid dupes.
        $siteName = trim((string) ($this->data['SiteName'] ?? ''));
        $canon    = $this->_siteUrl($site, 'blog') . ($pageNo > 1 ? '?p=' . $pageNo : '');
        $this->data['PageMeta'] = CmsMeta::Build(array(
            'canonical'   => $canon,
            'og_type'     => 'website',
            'og_title'    => ($siteName !== '' ? $siteName . ' — News' : 'News'),
            'og_image'    => $this->_ogImage(),
            'og_sitename' => $siteName,
        ));

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

        // #09: tally a public view of this post (best-effort; gated internally).
        $this->_recordCmsView($site, 'post', (int) $post['post_id']);

        $this->_cmsFab($site, UIR . 'Cms/editpost/' . (int) $post['post_id'] . $this->_scopeQ($site), 'Edit this post', true);
    }

    /**
     * The scoped RSS 2.0 feed: Site/rss/{slug} (→ /k|/p/{slug}/rss).
     * Reuses the shared CmsPost feed builder with the site's RESOLVED scope, so
     * aggregators hitting an org site get its own posts instead of a dead end.
     *
     * Only a PUBLISHED public site is served: an unknown / unbuilt / draft slug
     * gets a clean 404, and a preview (an officer viewing an unpublished site)
     * is deliberately NOT given a feed — a feed is inherently public/indexable,
     * so it must never expose pre-launch content (mirrors the noindex/preview
     * discipline of the HTML routes).
     */
    public function rss($slug = null)
    {
        $site      = $this->_resolvePublicSiteOrExit($slug);
        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];
        $siteName  = trim((string) ($site['site_name'] ?? ''));

        $this->load_model('CmsPost');
        $xml = $this->CmsPost->RssFeedXml($scopeType, $scopeId, array(
            'title'       => ($siteName !== '' ? $siteName . ' — News' : 'News'),
            'description' => ($siteName !== '' ? 'Latest news from ' . $siteName . '.' : 'Latest news.'),
            'index_link'  => $this->_siteUrl($site, 'blog'),
            'self_link'   => $this->_siteUrl($site, 'rss'),
            'post_base'   => $this->_siteUrl($site, 'post'),
        ));

        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $xml;
        exit;
    }

    /**
     * #126: XML sitemap for a site: Site/sitemap/{slug} (→ /k|/p/{slug}/sitemap.xml).
     *
     * Emits every PUBLISHED, live, in-scope page (full nested paths via PagePath)
     * and every PUBLISHED post, using the SAME published+due filter the public
     * page/post routes use (ListPages status='published' after _promoteScheduled;
     * ListPosts without includeDrafts). Only a published public site has a sitemap;
     * an unknown / unbuilt / draft slug (and any preview) gets a bare 404 — a
     * sitemap is inherently public/indexable, so it never exposes pre-launch content
     * (mirrors rss()).
     */
    public function sitemap($slug = null)
    {
        $site = $this->_resolvePublicSiteOrExit($slug);
        $this->_emitSitemapXml($this->_sitemapUrls($site, $this->_siteUrl($site)));
    }

    /**
     * Enumerate the sitemap entries for a resolved, published site: the home,
     * every published live in-scope page (full nested path), the blog index and
     * every published post. See sitemap() for the publish-filter rationale.
     *
     * @param array  $site resolved site row (authoritative scope)
     * @param string $base the site's pretty absolute base URL (no trailing slash)
     * @return array<int,array{loc:string,lastmod:string}>
     */
    private function _sitemapUrls($site, $base)
    {
        $scopeType = (string) $site['scope_type'];
        $scopeId   = (int) $site['scope_id'];
        $homeId    = (int) ($site['home_page_id'] ?? 0);

        $this->load_model('CmsPage');
        $this->load_model('CmsPost');

        // 1) Site home (the home_page_id page renders at the bare base URL).
        $urls   = array();
        $urls[] = array('loc' => $base, 'lastmod' => (string) ($site['updated_at'] ?? ''));

        // 2) Published, live, in-scope pages. ListPages() promotes any due
        //    scheduled rows first, so status='published' is exactly the live set.
        //    Skip the home page — it is already canonicalized at the base above.
        $pages = $this->CmsPage->list_pages(array(
            'status'     => 'published',
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'limit'      => self::SITEMAP_MAX,
        ));
        $pages = is_array($pages) ? $pages : array();
        // Resolve every path from ONE in-memory parent_id walk over the rows we
        // already fetched, instead of a PagePath() + _hasRestrictedAncestor() DB
        // walk per row (an N+1 on a public, uncached endpoint). The row set IS the
        // published, live, in-scope set, so a page whose chain the map cannot
        // resolve has an ancestor outside it (draft/scheduled) — precisely what
        // _hasRestrictedAncestor() rejected — and is skipped the same way.
        $pathMap = $this->_buildScopePathMap($pages);
        foreach ($pages as $pg) {
            $pid = (int) ($pg['page_id'] ?? 0);
            if ($pid <= 0 || $pid === $homeId) {
                continue;
            }
            $path = isset($pathMap[$pid]) ? trim((string) $pathMap[$pid], '/') : '';
            if ($path === '') {
                continue;   // pathless page (or a restricted/unresolvable chain) — skip
            }
            $urls[] = array('loc' => $base . '/' . $path, 'lastmod' => (string) ($pg['updated_at'] ?? ''));
        }

        // 3) The blog index + every published post (same public filter as blog()).
        $postResult = $this->CmsPost->list_posts(array(
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'limit'      => self::SITEMAP_MAX,
        ));
        $postRows = (is_array($postResult) && isset($postResult['rows']) && is_array($postResult['rows']))
            ? $postResult['rows'] : array();
        if (!empty($postRows)) {
            $urls[] = array('loc' => $base . '/blog', 'lastmod' => (string) ($postRows[0]['updated_at'] ?? ''));
        }
        foreach ($postRows as $po) {
            $pslug = trim((string) ($po['slug'] ?? ''));
            if ($pslug === '') {
                continue;
            }
            $urls[] = array(
                'loc'     => $base . '/post/' . rawurlencode($pslug),
                'lastmod' => (string) ($po['updated_at'] ?? ($po['published_at'] ?? '')),
            );
        }

        return $urls;
    }

    /**
     * Serialize sitemap entries to XML and emit them (terminates the request).
     * Entries with an empty loc are skipped; an unparseable lastmod is omitted
     * rather than emitted empty (a bare <lastmod></lastmod> is invalid).
     *
     * @param array $urls from _sitemapUrls()
     * @return void
     */
    private function _emitSitemapXml(array $urls)
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $loc = (string) $u['loc'];
            if ($loc === '') {
                continue;
            }
            $xml .= '  <url><loc>' . CmsSanitizer::XmlEscape($loc) . '</loc>';
            $mod = $this->_w3cDate((string) ($u['lastmod'] ?? ''));
            if ($mod !== '') {
                $xml .= '<lastmod>' . $mod . '</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>' . "\n";

        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
        exit;
    }

    /**
     * #126: per-site robots.txt: Site/robots/{slug} (→ /k|/p/{slug}/robots.txt).
     * Advertises the site's sitemap so crawlers can discover it. Only a published
     * public site is served (mirrors sitemap()/rss()); anything else gets a bare
     * 404 so an in-progress site stays indistinguishable from an unknown slug.
     */
    public function robots($slug = null)
    {
        $site = $this->_resolvePublicSiteOrExit($slug);
        $body = "User-agent: *\n"
            . "Allow: /\n"
            . 'Sitemap: ' . $this->_siteUrl($site, 'sitemap.xml') . "\n";

        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    /* ==================================================================
     * Internals
     * ================================================================ */

    /**
     * Normalize a DB datetime ('Y-m-d H:i:s') to a W3C/ISO-8601 sitemap
     * <lastmod> value, or '' when unparseable/empty.
     */
    private function _w3cDate($dt)
    {
        $dt = trim((string) $dt);
        if ($dt === '' || $dt === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($dt);
        return ($ts !== false && $ts > 0) ? date('c', $ts) : '';
    }

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
     * The shared gate for the three MACHINE-READABLE routes (rss/sitemap/robots):
     * resolve the slug, require a PUBLISHED public site, and honor the /k vs /p
     * canonical-prefix 301. Returns the site row, or terminates the request.
     *
     * A feed/sitemap/robots response is inherently public and indexable, so —
     * unlike the HTML routes — there is deliberately NO officer-preview escape
     * hatch here: an unknown, unbuilt or draft slug gets the same bare 404 even
     * for a viewer who is authorized to preview the site in HTML. Pre-launch
     * content must never reach an aggregator or a crawler.
     *
     * NOTE for callers: this never returns on failure (_renderRssNotFound and
     * _enforcePrefix both emit and exit), so the result is always a live row.
     *
     * @param string $slug
     * @return array the resolved, published site row
     */
    private function _resolvePublicSiteOrExit($slug)
    {
        $site = $this->_resolveSite($slug);
        if ($site === null || (string) ($site['status'] ?? 'unbuilt') !== 'published') {
            $this->_renderRssNotFound();   // emits a bare 404 and exits
            exit;                          // unreachable — never fall through to content
        }
        // No-op on a raw Site/* route (no &_pfx hint); 301s and exits on a mismatch.
        $this->_enforcePrefix($site);
        return $site;
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
        //
        // The 301 is issued ONLY for a site the viewer may already see (published,
        // or preview-authorized): redirecting first would tell an anonymous visitor
        // that an unbuilt/draft slug is taken (and which org type owns it), while an
        // unknown slug still 404s — defeating the indistinguishability the unbuilt
        // branch below depends on.
        $status = (string) ($site['status'] ?? 'unbuilt');
        if (($status === 'published' || $this->_viewerCanPreview($site)) && $this->_enforcePrefix($site)) {
            return true;
        }
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
     * True when the current viewer may EDIT this site's org (kingdom/park) — they
     * may preview it before it is published. Resolves through the single shared
     * _cmsCanEditScope() gate (#29), so a super-admin, a global/matching
     * kingdom/park ork_cms_grant, OR the HasAuthority(AUTH_EDIT) officer bridge
     * all qualify — identically to the edit FAB everywhere else.
     *
     * @param array $site
     * @return bool
     */
    private function _viewerCanPreview($site)
    {
        if ($this->_canEditMemo !== null) {
            return $this->_canEditMemo;
        }
        // #29: resolve through the single shared CmsCan-backed edit-scope gate so a
        // kingdom/park (or global) ork_cms_grant — not only a raw HasAuthority
        // officer role — lets its holder preview the unpublished site, matching the
        // edit FAB gate used everywhere else.
        $uid   = (int) ($this->session->user_id ?? 0);
        $scope = is_array($site)
            ? array('type' => (string) ($site['scope_type'] ?? ''), 'id' => (int) ($site['scope_id'] ?? 0))
            : array('type' => '', 'id' => 0);
        $this->_canEditMemo = $this->_cmsCanEditScope($uid, $scope);
        return $this->_canEditMemo;
    }

    /**
     * #09 usage analytics: record ONE public view of a page/post for this site's
     * scope. Best-effort and non-blocking — it must never slow or break the
     * render, so it fires exactly once per request on a successful in-scope
     * PUBLISHED fetch and swallows every error.
     *
     * WHAT COUNTS is not decided here: the exclusion policy (preview / non-GET /
     * bot) lives in CmsView::IsCountableView() so every entry point that renders
     * CMS content counts the same way. This method only reports the raw request
     * facts alongside the view.
     *
     * The lib (CmsView::RecordView) is ALSO best-effort (missing-table probe +
     * swallowed errors).
     *
     * @param array  $site       resolved site row (authoritative scope)
     * @param string $entityType 'page'|'post'
     * @param int    $entityId
     * @return void
     */
    private function _recordCmsView($site, $entityType, $entityId)
    {
        $entityId = (int) $entityId;
        if ($entityId <= 0 || !is_array($site)) {
            return;
        }

        try {
            $this->load_model('CmsView');
            $this->CmsView->record_view(
                (string) ($site['scope_type'] ?? 'global'),
                (int) ($site['scope_id'] ?? 0),
                (string) $entityType,
                $entityId,
                array(
                    'is_preview' => (bool) $this->_isPreview,
                    'method'     => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                )
            );
        } catch (\Throwable $e) {
            // Best-effort only — analytics must never surface to the visitor.
        }
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

    /**
     * The single-letter URL/scope prefix for a scope_type: park → 'p', else 'k'.
     * Delegates to CmsSite so the CMS dashboard (which links out to these public
     * URLs from a different controller) reads the rule from the same place.
     */
    private function _prefixFor($scopeType)
    {
        return CmsSite::UrlPrefixFor($scopeType);
    }

    /**
     * The '&scope=k:17' / '&scope=p:3' fragment for linking into the scoped CMS
     * admin. Thin adapter over the trait's shared _scopeQuery() so the fragment is
     * built in exactly one place (a site row is never global scope).
     */
    private function _scopeQ($site)
    {
        return $this->_scopeQuery(array(
            'type' => (string) ($site['scope_type'] ?? 'kingdom'),
            'id'   => (int) ($site['scope_id'] ?? 0),
        ));
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
        // An ORG site gates BOTH FABs on the same page.edit decision that governs
        // the unpublished-site preview (_viewerCanPreview) — deliberately NOT the
        // page.create capability Controller_Blog uses for its new-post FAB. The
        // shared helper takes the capability per caller for exactly that reason.
        $this->_cmsFabData(
            (int) ($this->session->user_id ?? 0),
            array('type' => (string) ($site['scope_type'] ?? ''), 'id' => (int) ($site['scope_id'] ?? 0)),
            $editUrl,
            $editTip,
            $withNewPost
                ? array(
                    'url' => UIR . 'Cms/editpost/new' . $this->_scopeQ($site),
                    'tip' => 'New post',
                    'cap' => 'page.edit',
                )
                : null
        );
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
        // Org-unit noun for public copy ('Kingdom' / 'Principality' / 'Park'). A
        // principality is stored as an ork_kingdom row with a parent kingdom, so
        // its site is scope_type='kingdom' — the shell must not therefore call it
        // a kingdom in front of visitors.
        $this->load_model('CmsSite');
        $this->data['SiteOrgNoun']      = (string) $this->CmsSite->org_unit_noun($scopeType, $scopeId);
        // Browser-tab identity for an org site: "{Kingdom or Park name} - {Page}".
        // The org half is published once here; each action below sets only the leaf
        // (the page/post title, 'News', 'Coming soon'), and default.theme joins
        // them. Actions must NOT prefix the site name themselves or it doubles up.
        // Falls back to the org's real ORK name: sites created before site_name was
        // seeded on the create path still have an empty one, and their tab would
        // otherwise read a bare "Home" with no clue whose site it is.
        $this->data['SiteTitleOrg']     = $siteName !== ''
            ? $siteName
            : (string) $this->CmsSite->org_display_name($scopeType, $scopeId);
        // Default leaf for the site root; Site::view() overrides it with the real
        // page title as soon as a page resolves (the seeded home page is 'Home').
        $this->data['page_title']       = 'Home';

        // Per-org theme tokens (scoped to the site's own scope, not global).
        // GetActiveCss caches the resolved CSS in GhettoCache keyed by
        // (scope, theme updated_at) so this no longer recompiles on every hit.
        $this->load_model('CmsTheme');
        $css = (string) $this->CmsTheme->get_active_css($scopeType, $scopeId);
        if ($css !== '') {
            $this->data['fdThemeCss'] = $css;
        }
        // ...and the same tokens again at :root. <html>/<body> are ANCESTORS of
        // .fd-page and custom properties inherit downward only, so cms-base.css's
        // `body { background: var(--fd-bg) }` cannot see the scoped block — without
        // this a dark-themed org site paints a white body behind the page. Org
        // sites only; default.theme gates the emit on $IsOrgSite.
        $rootCss = (string) $this->CmsTheme->get_active_root_css($scopeType, $scopeId);
        if ($rootCss !== '') {
            $this->data['fdThemeCssRoot'] = $rootCss;
        }

        // RSS auto-discovery: every org-site page advertises the org's scoped
        // feed (Site/rss/{slug} → /k|/p/{slug}/rss) so readers/aggregators can
        // find it. default.theme emits the <link rel="alternate"> when set.
        $this->data['rss_feed_url']   = $this->_siteUrl($site, 'rss');
        $this->data['rss_feed_title'] = ($siteName !== '' ? $siteName . ' — News' : 'News');
    }

    /* ==================================================================
     * C6 — per-page canonical + Open Graph meta
     * ================================================================== */

    /**
     * The scheme+host origin for absolute canonical/OG URLs, derived from the
     * live request (honors the CF-forwarded proto when present). Shared with
     * the other public renderers via CmsMeta so the http/https detection cannot
     * drift between surfaces.
     */
    private function _origin()
    {
        return CmsMeta::Origin();
    }

    /**
     * THE builder for every public URL on this site: the pretty-URL base
     * {origin}/{k|p}/{slug} (no trailing slash), plus an optional path below it
     * ('blog', 'rss', 'post/my-slug', a nested page path…).
     *
     * Every canonical, og:url, feed link, breadcrumb and sitemap entry goes
     * through here, so the /k-vs-/p namespace rule and the slug encoding are
     * decided in exactly one place.
     *
     * NOTE: this is NOT what $SiteHomeUrl in the org chrome emits — that stays
     * the raw Site/view/{slug} route form, which works with or without the
     * nginx /k/ rewrite. Changing it would change a shipped public href.
     *
     * @param array  $site resolved site row (scope_type + slug)
     * @param string $path optional path below the base, with or without a leading '/'
     * @return string
     */
    private function _siteUrl($site, $path = '')
    {
        $prefix = $this->_prefixFor($site['scope_type'] ?? 'kingdom');
        $slug   = rawurlencode((string) ($site['slug'] ?? ''));
        $base   = $this->_origin() . '/' . $prefix . '/' . $slug;
        $path   = ltrim((string) $path, '/');
        return ($path === '') ? $base : $base . '/' . $path;
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
        return CmsMeta::Absolutize($url, $this->_origin());
    }

    /**
     * The og:image for a site surface: the entity's own hero when it has one,
     * otherwise the site logo (absolutized the same way). '' when neither is set,
     * which lets default.theme fall back to the ORK default image.
     *
     * @param int $heroMediaId 0 for surfaces with no hero of their own (blog index)
     * @return string
     */
    private function _ogImage($heroMediaId = 0)
    {
        $url = $this->_absMediaUrl((int) $heroMediaId);
        if ($url === '' && !empty($this->data['SiteLogoUrl'])) {
            $url = CmsMeta::Absolutize((string) $this->data['SiteLogoUrl'], $this->_origin());
        }
        return $url;
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
        // PagePath() lives on the CmsPage lib — load it here rather than relying
        // on a caller having happened to load it inside a conditional branch
        // (Controller::__call is an empty no-op, so an unset $this->CmsPage would
        // be a fatal, not a silent miss).
        $this->load_model('CmsPage');
        $canon = $this->_siteUrl($site);
        if (!$isHome) {
            $path = $this->CmsPage->PagePath((int) $page['page_id']);
            if ($path !== '') {
                $canon = $this->_siteUrl($site, $path);
            }
            // PagePath() is the ROUTING truth and includes a draft ancestor's slug.
            // For a public viewer that slug must not ship in rel=canonical/og:url
            // (nor be handed to a crawler): emit no canonical and mark the page
            // no-index instead. Officer preview keeps the real URL.
            if (!$this->_isPreview && $this->_hasRestrictedAncestor((int) $page['page_id'])) {
                $canon                  = '';
                $this->data['no_index'] = true;
            }
        }

        $siteName = trim((string) ($this->data['SiteName'] ?? ''));
        $title    = trim((string) ($page['title'] ?? ''));
        $ogTitle  = $title . ($siteName !== '' && $title !== $siteName ? ' — ' . $siteName : '');

        // NOTE: an org-site page falls back to the SITE name, not the ORK brand
        // (Controller_Page does the opposite for a global CMS page). Keep the
        // fallback here at the call site — CmsMeta must not homogenize the two.
        $this->data['PageMeta'] = CmsMeta::Build(array(
            'canonical'   => $canon,
            'og_type'     => ($type === 'website') ? 'website' : 'article',
            'og_title'    => ($ogTitle !== '') ? $ogTitle : $siteName,
            'og_desc'     => trim((string) ($page['meta_description'] ?? '')),
            'og_image'    => $this->_ogImage((int) ($page['hero_media_id'] ?? 0)),
            'og_sitename' => $siteName,
        ));
    }

    /** C6: canonical + OG for a scoped blog POST (/post/{slug}). */
    private function _setPostMeta($site, $post)
    {
        $canon    = $this->_siteUrl($site, 'post/' . rawurlencode((string) ($post['slug'] ?? '')));
        $siteName = trim((string) ($this->data['SiteName'] ?? ''));
        $title    = trim((string) ($post['title'] ?? ''));

        $this->data['PageMeta'] = CmsMeta::Build(array(
            'canonical'   => $canon,
            'og_type'     => 'article',
            'og_title'    => ($title !== '' ? $title : $siteName),
            'og_desc'     => trim((string) ($post['excerpt'] ?? '')),
            'og_image'    => $this->_ogImage((int) ($post['hero_media_id'] ?? 0)),
            'og_sitename' => $siteName,
        ));
    }

    /**
     * True when any ancestor of this page is not published+due — i.e. the page's
     * own public path embeds a slug that was never authorized for publication.
     * Such a page must not advertise that URL publicly (canonical/og:url, sitemap).
     *
     * @param int $pageId
     * @return bool
     */
    private function _hasRestrictedAncestor($pageId)
    {
        $this->load_model('CmsPage');
        $ancestors = $this->CmsPage->GetPageAncestors((int) $pageId, true);
        foreach ((is_array($ancestors) ? $ancestors : array()) as $anc) {
            if (!empty($anc['restricted'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * C13: build the breadcrumb trail (root → this page) for a nested page. Each
     * crumb is ['label','url']; the current page is the last crumb (no url). A
     * flat page yields a single home crumb + itself.
     *
     * A published page may sit under a DRAFT/scheduled ancestor (GetPageByPath
     * publish-gates only the leaf), so for a PUBLIC viewer the ancestors come back
     * redacted (generic label, no slug) from GetPageAncestors' published-only
     * default. Such a crumb renders as plain text, and so does everything below it
     * — a deeper crumb's URL would have to embed the withheld slug. Officer
     * preview passes false and still sees the real draft chain.
     */
    private function _breadcrumbs($site, $page)
    {
        // GetPageAncestors() lives on the CmsPage lib — load it here rather than
        // relying on a caller's conditional load (see _setPageMeta).
        $this->load_model('CmsPage');
        $base   = $this->_siteUrl($site);
        $crumbs = array(array('label' => 'Home', 'url' => $base));

        $ancestors = $this->CmsPage->GetPageAncestors((int) $page['page_id'], !$this->_isPreview);
        $prefix    = array();
        $blocked   = false;
        foreach ((is_array($ancestors) ? $ancestors : array()) as $anc) {
            if (!empty($anc['restricted'])) {
                $blocked  = true;   // slug withheld → no linkable path from here down
                $crumbs[] = array('label' => (string) $anc['title'], 'url' => '');
                continue;
            }
            $prefix[] = (string) $anc['slug'];
            $crumbs[] = array(
                'label' => (string) ($anc['title'] !== '' ? $anc['title'] : $anc['slug']),
                'url'   => $blocked ? '' : $base . '/' . implode('/', $prefix),
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
            $target = $this->_siteUrl($site, $target);
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
        // AFTER the shell boot: _bootShell resets page_title to 'Home'.
        $this->_markNotFound('This page could not be found.');
    }

    /**
     * A bare, non-HTML 404 for the RSS endpoint: an aggregator wants a feed, not
     * the branded HTML shell. Keeps an in-progress / unknown site indistinguish-
     * able (no name/logo leak) and emits nothing indexable.
     */
    private function _renderRssNotFound()
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
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
        // Leaf only — _bootShell (called above) published the org half.
        $this->data['page_title'] = 'Coming soon';
    }
}
