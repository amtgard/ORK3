<?php

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
 * to populate data, then calls the base render method view() (zero args) to emit
 * HTML. Our actions are index/post/rss so there's no collision with view(); if a
 * 'view' action were ever added it would need the func_num_args() dispatch pattern
 * Controller_Page uses.
 */
class Controller_Blog extends Controller
{
    /** Posts per page on the index feed. */
    public const PER_PAGE = 12;

    /** v2 CMS-auth scope: org-wide. */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['blog'] = array('url' => UIR . 'Blog', 'display' => 'News');
    }

    /**
     * Paginated list of published posts. Optional ?tag= slug filter, ?p= page (1-based).
     */
    public function index($action = null)
    {
        $this->template = 'Blog_index.tpl';
        $this->load_model('CmsPost');

        $page = isset($_GET['p']) ? (int) $_GET['p'] : 1;
        if ($page < 1) {
            $page = 1;
        }
        $tag = isset($_GET['tag']) ? trim((string) $_GET['tag']) : '';

        $perPage = self::PER_PAGE;
        $offset  = ($page - 1) * $perPage;

        $opts = array(
            'limit'      => $perPage,
            'offset'     => $offset,
            'scope_type' => 'global',
            'scope_id'   => 0,
        );
        if ($tag !== '') {
            $opts['tag'] = $tag;
        }

        $result = $this->CmsPost->list_posts($opts);
        $rows    = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
        $total   = isset($result['total']) ? (int) $result['total'] : 0;
        $pages   = ($perPage > 0) ? (int) ceil($total / $perPage) : 1;
        if ($pages < 1) {
            $pages = 1;
        }

        // Clamp an out-of-range page so the OFFSET can never exceed the result
        // set (an unbounded ?p= would otherwise scan the whole set for nothing).
        // Refetch the last valid page's rows only when the request was too high.
        if ($page > $pages) {
            $page          = $pages;
            $opts['offset'] = ($page - 1) * $perPage;
            $result = $this->CmsPost->list_posts($opts);
            $rows   = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();
            $total  = isset($result['total']) ? (int) $result['total'] : $total;
        }

        $this->data['posts']       = $rows;
        $this->data['total_posts'] = $total;
        $this->data['page']        = $page;
        $this->data['total_pages'] = $pages;
        $this->data['per_page']    = $perPage;
        $this->data['tag']         = $tag;
        $this->data['page_title']  = ($tag !== '') ? ('News — ' . $tag) : 'News';
        $this->_cmsFab(UIR . 'Cms/posts', 'Manage posts');
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
        $this->_cmsFab(UIR . 'Cms/editpost/' . (int) $post['post_id'], 'Edit this post');
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
    private function _cmsFab($editUrl, $editTip)
    {
        $uid = (int) ($this->session->user_id ?? 0);
        if ($uid <= 0) {
            return;
        }
        $this->load_model('CmsAuth');
        // Resolve capabilities once instead of two cms_can() round-trips: a
        // super-admin passes everything; otherwise union the granted caps and
        // test in memory (mirrors Controller_Cms::_capFlags()).
        $isSuper = (bool) $this->CmsAuth->is_super_admin($uid);
        $caps    = $isSuper ? array() : $this->CmsAuth->get_user_capabilities($uid, self::$SCOPE);
        if ($isSuper || in_array('page.edit', $caps, true)) {
            $this->data['cmsEditUrl'] = $editUrl;
            $this->data['cmsEditTip'] = $editTip;
        }
        if ($isSuper || in_array('page.create', $caps, true)) {
            $this->data['cmsNewPostUrl'] = UIR . 'Cms/editpost/new';
            $this->data['cmsNewPostTip'] = 'New post';
        }
    }
}
