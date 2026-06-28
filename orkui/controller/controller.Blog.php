<?php

/**
 * Controller_Blog — public-facing blog (CMS posts).
 *
 * Routes:
 *   Blog            / Blog/index        → index()  list of published posts (paginated; ?p=, ?tag=)
 *   Blog/post/{slug}                    → post($slug)  single published entry
 *   Blog/rss                            → rss()  RSS 2.0 feed of the latest published posts
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

    /** Max items in the RSS feed. */
    public const RSS_LIMIT = 20;

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
        $gc       = Ork3::$Lib->ghettocache;
        $cacheKey = $gc->key(['scope_type' => 'global', 'scope_id' => 0, 'limit' => self::RSS_LIMIT]);
        $cached   = $gc->get(__CLASS__ . '.rss_xml', $cacheKey, 300);
        if ($cached !== false) {
            header('Content-Type: application/rss+xml; charset=utf-8');
            echo $cached;
            exit;
        }

        $this->load_model('CmsPost');

        $result = $this->CmsPost->list_posts(array(
            'limit'      => self::RSS_LIMIT,
            'offset'     => 0,
            'scope_type' => 'global',
            'scope_id'   => 0,
        ));
        $rows = isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : array();

        $indexLink  = UIR . 'Blog/index';
        $selfLink   = UIR . 'Blog/rss';
        $buildDate  = date('r');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '<title>' . $this->_xml('Amtgard News') . "</title>\n";
        $xml .= '<link>' . $this->_xml($indexLink) . "</link>\n";
        $xml .= '<description>' . $this->_xml('Latest news and announcements from the Amtgard Online Record Keeper.') . "</description>\n";
        $xml .= '<language>en-us</language>' . "\n";
        $xml .= '<lastBuildDate>' . $this->_xml($buildDate) . "</lastBuildDate>\n";
        $xml .= '<atom:link href="' . $this->_xml($selfLink) . '" rel="self" type="application/rss+xml" />' . "\n";

        foreach ($rows as $row) {
            $slug    = isset($row['slug']) ? (string) $row['slug'] : '';
            $title   = isset($row['title']) ? (string) $row['title'] : '';
            $excerpt = isset($row['excerpt']) ? (string) $row['excerpt'] : '';
            $link    = UIR . 'Blog/post/' . rawurlencode($slug);

            $pubDate = '';
            if (!empty($row['published_at'])) {
                $ts = strtotime((string) $row['published_at']);
                if ($ts !== false) {
                    $pubDate = date('r', $ts);
                }
            }

            $xml .= "<item>\n";
            $xml .= '<title>' . $this->_xml($title) . "</title>\n";
            $xml .= '<link>' . $this->_xml($link) . "</link>\n";
            $xml .= '<guid isPermaLink="true">' . $this->_xml($link) . "</guid>\n";
            if ($pubDate !== '') {
                $xml .= '<pubDate>' . $this->_xml($pubDate) . "</pubDate>\n";
            }
            if (isset($row['author_name']) && $row['author_name'] !== '') {
                $xml .= '<dc:creator>' . $this->_xml((string) $row['author_name']) . "</dc:creator>\n";
            }
            $xml .= '<description><![CDATA[' . $this->_cdata($excerpt) . "]]></description>\n";
            if (!empty($row['tags']) && is_array($row['tags'])) {
                foreach ($row['tags'] as $t) {
                    if (!empty($t['name'])) {
                        $xml .= '<category>' . $this->_xml((string) $t['name']) . "</category>\n";
                    }
                }
            }
            $xml .= "</item>\n";
        }

        $xml .= "</channel>\n";
        $xml .= "</rss>\n";

        $gc->cache(__CLASS__ . '.rss_xml', $cacheKey, $xml);

        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $xml;
        exit;
    }

    /**
     * Escape a string for safe inclusion in an XML text node / attribute.
     */
    private function _xml($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Make a string safe to nest inside a CDATA section (the only sequence that
     * can break out of CDATA is "]]>").
     */
    private function _cdata($text)
    {
        return str_replace(']]>', ']]&gt;', (string) $text);
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
