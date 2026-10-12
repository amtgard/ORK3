<?php

require_once __DIR__ . '/trait.CmsScope.php';

/**
 * Controller_Blog — public-facing blog (CMS posts).
 *
 * Routes:
 *   Blog            / Blog/index        → index()  list of published posts (paginated; ?p=, ?tag=)
 *   Blog/post/{slug}                    → post($slug)  single published entry
 *   Blog/rss                            → rss()  RSS 2.0 feed (GLOBAL scope) of the latest published posts
 *
 * The feed XML + its per-scope 300s ghettocache live in the CmsPost lib
 * (CmsPost::RssFeedXml) so this GLOBAL feed and the per-org feeds in
 * Controller_Site::rss share ONE builder; rss() here only supplies channel meta.
 *
 * Posts come from the CmsPost lib (via Model_CmsPost). A post's BODY is stored as
 * blocks in the shared polymorphic block store (owner_type='post') and renders
 * through the SAME frontdoor/render_blocks.tpl partial pages use.
 *
 * NOTE on view(): the framework calls the controller's action (e.g. post($slug))
 * to populate data, then calls the base render method render() to emit HTML. Our
 * actions are index/post/rss, and the render step is no longer named view(), so a
 * 'view' action here would collide with nothing. (Where an action name genuinely
 * doubles as a render entry point, Controller_Cms::edit()/preview() show the
 * func_num_args() dispatch pattern that disambiguates the two calls.)
 */
class Controller_Blog extends Controller
{
    use CmsScopeContext;

    /** Posts per page on the index feed. */
    public const PER_PAGE = 12;

    /** v2 CMS-auth scope: org-wide. */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['blog'] = array('url' => UIR . 'Blog', 'display' => 'News');
        // The public blog is a CMS-styled surface (not the front-door home),
        // so the brand serif must load — default.theme gates it on this flag.
        $this->data['IsCmsPage'] = true;
        // The global blog belongs to the Amtgard-level site: "Amtgard - News".
        $this->data['SiteTitleOrg'] = self::PUBLIC_SITE_BRAND;
    }

    /**
     * Paginated list of published posts. Optional ?tag= slug filter, ?p= page (1-based).
     */
    public function index($action = null)
    {
        $this->template = 'Blog_index.tpl';

        $tag  = isset($_GET['tag']) ? trim((string) $_GET['tag']) : '';
        $opts = array(
            'scope_type' => 'global',
            'scope_id'   => 0,
        );
        if ($tag !== '') {
            $opts['tag'] = $tag;
        }

        // Shared paginated fetch + out-of-range clamp + refetch (see trait.CmsScope);
        // Controller_Site::blog uses the same helper at org scope.
        $paged = $this->_cmsPagedPosts($opts, self::PER_PAGE, isset($_GET['p']) ? (int) $_GET['p'] : 1);
        $page  = $paged['page'];

        $this->data['posts']       = $paged['rows'];
        $this->data['page']        = $page;
        $this->data['total_pages'] = $paged['pages'];
        $this->data['tag']         = $tag;
        // Leaf only — the org half ("Amtgard") is published in the constructor.
        $this->data['page_title']  = ($tag !== '') ? ('News: ' . $tag) : 'News';
        // Load the front-door theme tokens on the index too (post() already
        // does) so the blog list matches the CMS look.
        $this->_attachFrontDoorTheme();

        // Canonical + OG for the blog index (type=website). Page 1 canonicals
        // to /Blog; deeper pages self-canonical with ?p= to avoid duplicate content.
        $canon = UIR . 'Blog'
            . ($tag !== '' ? '/index&tag=' . rawurlencode($tag) : '')
            . ($page > 1 ? (($tag !== '' ? '&' : '/index&') . 'p=' . $page) : '');
        $this->data['PageMeta'] = CmsMeta::Build(array(
            'canonical'   => $canon,
            'og_type'     => 'website',
            'og_title'    => ($tag !== '' ? ('News — ' . $tag) : 'Amtgard News'),
            'og_desc'     => 'Latest news and announcements from the Amtgard Online Record Keeper.',
            'og_sitename' => self::APP_BRAND,
        ));
        $this->_blogFab(UIR . 'Cms/posts', 'Manage posts');
    }

    /**
     * Single published post by slug. Sets the post + its body blocks for the
     * entry template; on miss, sets a Message and no_index.
     */
    public function post($slug = null)
    {
        $this->template = 'Blog_post.tpl';
        $this->load_model('CmsPost');

        $slug = trim((string) $slug);
        $post = ($slug !== '')
            ? $this->CmsPost->get_post_by_slug($slug, 'global', 0, true)
            : null;

        if (empty($post)) {
            http_response_code(404);
            $this->data['Message']    = 'Post not found.';
            $this->data['page_title'] = 'Post not found';
            $this->data['post']       = null;
            $this->data['post_blocks'] = array();
            $this->data['no_index']   = true;
            return;
        }

        $blocks = $this->CmsPost->get_post_blocks((int) $post['post_id']);

        // Resolve hero image (if any) to a media ref for the template.
        $hero = null;
        if (!empty($post['hero_media_id'])) {
            $this->load_model('CmsMedia');
            $hero = $this->CmsMedia->get_media((int) $post['hero_media_id']);
        }

        $this->data['post']        = $post;
        $this->data['post_blocks'] = is_array($blocks) ? $blocks : array();
        $this->_attachFrontDoorTheme();
        $this->data['hero']        = $hero;
        $this->data['page_title']  = $post['title'];
        $this->data['meta_description'] = isset($post['excerpt']) ? (string) $post['excerpt'] : '';
        // Per-post canonical + OG (type=article; hero → og:image), mirroring
        // Controller_Site::_setPostMeta.
        $this->_setPostMeta($post, $hero);
        $this->_blogFab(UIR . 'Cms/editpost/' . (int) $post['post_id'], 'Edit this post');
    }

    /**
     * Publish a per-post $PageMeta (canonical + og:*) for a global blog post,
     * mirroring Controller_Site::_setPostMeta. Canonical is the post's public
     * Blog/post URL (from UIR); og:image comes from the hero media row when set.
     *
     * @param array      $post post row (title, slug, excerpt)
     * @param array|null $hero resolved hero media row (carries url) or null
     */
    private function _setPostMeta($post, $hero = null)
    {
        $canon = UIR . 'Blog/post/' . rawurlencode((string) ($post['slug'] ?? ''));

        $ogImage = (is_array($hero) && !empty($hero['url']))
            ? CmsMeta::Absolutize((string) $hero['url'], CmsMeta::Origin())
            : '';

        $title = trim((string) ($post['title'] ?? ''));
        $this->data['PageMeta'] = CmsMeta::Build(array(
            'canonical'   => $canon,
            'og_type'     => 'article',
            'og_title'    => ($title !== '' ? $title : 'Amtgard News'),
            'og_desc'     => trim((string) ($post['excerpt'] ?? '')),
            'og_image'    => $ogImage,
            'og_sitename' => self::APP_BRAND,
        ));
    }

    /**
     * RSS 2.0 feed of the latest published posts. Emits XML directly and exits.
     *
     * The feed is keyed on a static scope tuple and cached for 300 s so that
     * RSS aggregators polling every few minutes hit memcache instead of the DB.
     * Hot path: O(1) cache lookup → early-exit; miss → O(N) list_posts (N=20).
     */
    public function rss($action = null)
    {
        // The XML shape + per-scope ghettocache live in the CmsPost lib so the
        // GLOBAL feed here and the org feeds in Controller_Site::rss share ONE
        // builder. This controller only supplies the channel meta and emits.
        $this->load_model('CmsPost');
        $xml = $this->CmsPost->RssFeedXml('global', 0, array(
            'title'       => 'Amtgard News',
            'description' => 'Latest news and announcements from the Amtgard Online Record Keeper.',
            'index_link'  => UIR . 'Blog/index',
            'self_link'   => UIR . 'Blog/rss',
            'post_base'   => UIR . 'Blog/post',
        ));

        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $xml;
        exit;
    }

    /**
     * Expose CMS editor FAB flags (rendered by default.theme) when the viewer
     * may edit. $editUrl/$editTip drive the Edit FAB; CMS post-creators also get
     * a New Post FAB. No-op for signed-out or non-CMS users.
     */
    private function _blogFab($editUrl, $editTip)
    {
        // Both gates resolve through the shared CmsCan surface (super-admin, a
        // global or matching kingdom/park ork_cms_grant, or the officer AUTH_EDIT
        // bridge). The new-post FAB keeps its DISTINCT create capability — on the
        // global blog a contributor may draft a post without holding page.edit —
        // which is why _cmsFabData takes the capability from the caller.
        $this->_cmsFabData(
            $this->_uid(),
            self::$SCOPE,
            $editUrl,
            $editTip,
            array(
                'url' => UIR . 'Cms/editpost/new',
                'tip' => 'New post',
                'cap' => 'page.create',
            )
        );
    }
}
