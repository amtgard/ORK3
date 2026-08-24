<?php

// The RSS builder escapes every emitted value through CmsSanitizer::XmlEscape,
// so the sanitizer must be loadable from the lib layer even when no controller
// has require'd it (same guard CmsPage carries). Idempotent.
require_once __DIR__ . '/class.CmsSanitizer.php';

/*************************************************************************
 * CmsPost — blog-post content store for the CMS.
 *
 * Reads/writes ork_cms_post + ork_cms_tag + ork_cms_post_tag. A post's
 * BODY is stored as blocks in ork_cms_block with owner_type='post', so
 * block read/write delegates to CmsPage::GetBlocks('post',id) /
 * ::ReplaceBlocks('post',id,blocks) — the SAME polymorphic store + the
 * SAME renderer shape (['id','type','enabled','order','source','fields'])
 * that pages use. No separate block table or renderer.
 *
 * DB idiom: shared global $DB (YapoDb). Always Clear() before a raw
 * DataSet()/Execute(); bind via $DB->field = ... (becomes :field) so
 * nothing is concatenated unescaped. Rows are driven off Next() via the
 * _firstRow/_eachRow helpers (Size() is unreliable on PDO unbuffered).
 *************************************************************************/

class CmsPost extends CmsBase
{
    /** @var CmsPage|null lazily-instantiated block delegate */
    private $_pageLib = null;

    /** @var array per-request memo of ListPosts results, keyed by opts hash */
    private static $_listCache = array();

    /** Max items in a scoped RSS feed. Single source of truth for feed + cache. */
    public const RSS_LIMIT = 20;

    /**
     * Ghettocache "call" namespace for the rendered RSS XML. Shared by the feed
     * writer (RssFeedXml) and the invalidators (_bustRssCache) so a publish /
     * unpublish / delete busts the EXACT key the feed cached under.
     */
    public const RSS_CACHE_CALL = 'CmsPost.rss_xml';

    /** RSS feed cache TTL (seconds). */
    private const RSS_CACHE_TTL = 300;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The CmsPage lib instance used for block read/write (owner_type='post').
     * Prefer the shared Ork3 lib registry when available, else instantiate.
     *
     * @return CmsPage
     */
    private function _pages()
    {
        if ($this->_pageLib instanceof CmsPage) {
            return $this->_pageLib;
        }
        // Reuse the shared lib instance from the Ork3 registry when present
        // (Ork3::$Lib is an Ork3LibContainer with magic __get); else instantiate.
        if (isset(Ork3::$Lib) && is_object(Ork3::$Lib) && isset(Ork3::$Lib->CmsPage) && Ork3::$Lib->CmsPage instanceof CmsPage) {
            $this->_pageLib = Ork3::$Lib->CmsPage;
        } else {
            $this->_pageLib = new CmsPage();
        }
        return $this->_pageLib;
    }

    /**
     * Fetch a single published (or any-status) post by slug within a scope,
     * decorated with author display name and its tag list.
     *
     * @param string $slug          post slug
     * @param string $scopeType     'global' | 'kingdom' | 'park'
     * @param int    $scopeId       scope owner id (0 for global)
     * @param bool   $publishedOnly when true, only status='published' matches
     * @return array|null post row + 'author_name' + 'tags' => [['name','slug'],...], or null
     */
    public function GetPostBySlug($slug, $scopeType = 'global', $scopeId = 0, $publishedOnly = true)
    {
        global $DB;

        $scopeType = $this->_normalizeScopeType($scopeType);

        // C7: flip any due scheduled rows to published before the read gate.
        if ($publishedOnly) {
            $this->_promoteScheduled();
        }

        // C21: never leak a real given_name into a public byline / RSS. When the
        // author has no persona (or the author row is gone — orphaned authorship
        // after a role revoke, see CmsAuth::RevokeRole), fall back to a neutral
        // org-scoped label ('Staff' globally, 'Kingdom' for an org site) rather
        // than the person's mundane given name.
        $sql = $this->_livePostSelectHead()
            . ' WHERE p.slug = :slug AND p.scope_type = :scope_type AND p.scope_id = :scope_id'
            . ' AND p.deleted_at IS NULL';   // C2: never serve a trashed post
        if ($publishedOnly) {
            // C7: live only once the (optional) schedule time has passed.
            $sql .= ' AND ' . $this->_publishedGateSql('p');
        }
        $sql .= ' LIMIT 1';

        $DB->Clear();
        $DB->slug = (string)$slug;
        $DB->scope_type = $scopeType;
        $DB->scope_id = (int)$scopeId;
        $row = $this->_firstRow($DB->DataSet($sql));

        if ($row === null) {
            return null;
        }

        $row['tags'] = $this->GetTags((int)$row['post_id']);
        return $row;
    }

    /**
     * Ordered, enabled-only body blocks for a post — reuses the polymorphic
     * block store via CmsPage::GetBlocks('post', $postId). Returns the SAME
     * renderer shape pages use.
     *
     * @param int $postId
     * @return array list of ['id','type','enabled','order','source','fields']
     */
    public function GetPostBlocks($postId)
    {
        return $this->_pages()->GetBlocks('post', (int)$postId);
    }

    /**
     * List posts newest-first by published_at. Public default = published only.
     *
     * @param array $opts limit, offset, tag (slug filter), includeDrafts (admin),
     *                     scope_type, scope_id
     * @return array ['rows' => [...], 'total' => int]  where each row carries
     *               author_name, excerpt, and 'tags' => [['name','slug'],...]
     */
    public function ListPosts($opts = array())
    {
        global $DB;

        // Per-request memo: blog_feed.tpl can call this multiple times per render
        // with identical opts. Keyed by a hash of the normalized opts; reset
        // naturally per FPM request and invalidated on any write below.
        $cacheKey = md5(json_encode(is_array($opts) ? $opts : array()));
        if (isset(self::$_listCache[$cacheKey])) {
            return self::$_listCache[$cacheKey];
        }

        $scopeType = $this->_normalizeScopeType(isset($opts['scope_type']) ? $opts['scope_type'] : 'global');
        $scopeId = isset($opts['scope_id']) ? (int)$opts['scope_id'] : 0;
        $includeDrafts = !empty($opts['includeDrafts']);
        $tag = isset($opts['tag']) && $opts['tag'] !== '' ? (string)$opts['tag'] : '';

        // C7: flip any due scheduled rows before the public read gate.
        if (!$includeDrafts) {
            $this->_promoteScheduled();
        }

        // C2: trashed posts never appear (admin or public).
        $where = array('p.scope_type = :scope_type', 'p.scope_id = :scope_id', 'p.deleted_at IS NULL');
        if (!$includeDrafts) {
            // C7: live only once the (optional) schedule time has passed. One
            // entry rather than two — the imploded ' AND ' string is identical.
            $where[] = $this->_publishedGateSql('p');
        }

        $join = ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = p.author_id';
        if ($tag !== '') {
            $join .= ' INNER JOIN ' . DB_PREFIX . 'cms_post_tag pt ON pt.post_id = p.post_id'
                . ' INNER JOIN ' . DB_PREFIX . 'cms_tag t ON t.tag_id = pt.tag_id AND t.slug = :tag';
        }

        // total count (same filters/join, no limit)
        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id = $scopeId;
        if ($tag !== '') {
            $DB->tag = $tag;
        }
        $countRow = $this->_firstRow($DB->DataSet(
            'SELECT COUNT(DISTINCT p.post_id) AS total FROM ' . DB_PREFIX . 'cms_post p'
            . $join . ' WHERE ' . implode(' AND ', $where)
        ));
        $total = ($countRow !== null && isset($countRow['total'])) ? (int)$countRow['total'] : 0;

        // LIMIT/OFFSET can't be bound; code-controlled integers only.
        $limitClause = '';
        if (isset($opts['limit']) && (int)$opts['limit'] > 0) {
            $limitClause = ' LIMIT ' . (int)$opts['limit'];
            if (isset($opts['offset']) && (int)$opts['offset'] > 0) {
                $limitClause .= ' OFFSET ' . (int)$opts['offset'];
            }
        }

        $sql = $this->_livePostSelectHead($join)
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY p.post_id'
            . ' ORDER BY p.published_at DESC, p.post_id DESC'
            . $limitClause;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id = $scopeId;
        if ($tag !== '') {
            $DB->tag = $tag;
        }
        $rows = array();
        foreach ($this->_eachRow($DB->DataSet($sql)) as $row) {
            $rows[] = $row;
        }

        // Bulk-fetch tags for all materialized posts in ONE query (avoids N+1).
        $rows = $this->_attachTags($rows);

        $result = array('rows' => $rows, 'total' => $total);
        self::$_listCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * List TRASHED posts (deleted_at IS NOT NULL) for a scope — the mirror of
     * ListPosts for the Trash view. Newest-trashed-first, decorated with
     * author_name + tags so the admin Trash surface can show Restore. Never
     * touched by public reads (those all gate deleted_at IS NULL).
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @return array list of post rows + author_name + 'tags' => [['name','slug'],...]
     */
    public function ListTrashed($scopeType = 'global', $scopeId = 0)
    {
        global $DB;

        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $sql = $this->_livePostSelectHead()
            . ' WHERE p.scope_type = :scope_type AND p.scope_id = :scope_id'
            . ' AND p.deleted_at IS NOT NULL'
            . ' ORDER BY p.deleted_at DESC, p.post_id DESC';

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $rows = array();
        foreach ($this->_eachRow($DB->DataSet($sql)) as $row) {
            $rows[] = $row;
        }

        // Bulk-fetch tags in ONE query (avoids N+1), mirroring ListPosts.
        $rows = $this->_attachTags($rows);

        return $rows;
    }

    /**
     * Bulk-fetch tags for a set of post rows in ONE query (avoids N+1) and
     * decorate each row with 'tags' => [['name','slug'],...]. Shared by
     * ListPosts and ListTrashed. Returns the rows unchanged when empty.
     *
     * @param array $rows post rows, each carrying 'post_id'
     * @return array the same rows, each with a 'tags' key
     */
    private function _attachTags(array $rows)
    {
        global $DB;

        if (empty($rows)) {
            return $rows;
        }

        $postIds = array();
        foreach ($rows as $r) {
            $postIds[] = (int)$r['post_id'];
        }
        $inList = implode(',', $postIds);
        $DB->Clear();
        $tagsByPost = array();
        foreach ($this->_eachRow($DB->DataSet(
            'SELECT pt.post_id, t.name, t.slug FROM ' . DB_PREFIX . 'cms_post_tag pt'
            . ' JOIN ' . DB_PREFIX . 'cms_tag t ON t.tag_id = pt.tag_id'
            . ' WHERE pt.post_id IN (' . $inList . ')'
            . ' ORDER BY t.name ASC'
        )) as $tr) {
            $pid = (int)$tr['post_id'];
            $tagsByPost[$pid][] = array('name' => $tr['name'], 'slug' => $tr['slug']);
        }
        foreach ($rows as &$row) {
            $pid = (int)$row['post_id'];
            $row['tags'] = isset($tagsByPost[$pid]) ? $tagsByPost[$pid] : array();
        }
        unset($row);

        return $rows;
    }

    /**
     * Drop the per-request ListPosts memo. Called on any post/tag write so a
     * subsequent ListPosts in the same request re-queries fresh data.
     */
    private function _invalidateListCache()
    {
        self::$_listCache = array();
    }

    /* ==================================================================
     * RSS feed — shared, scope-parameterized XML builder + cache.
     *
     * Both Controller_Blog::rss (global scope) and Controller_Site::rss (a
     * resolved org scope) call RssFeedXml() so the feed shape lives in ONE
     * place — the two controllers only supply channel meta + emit headers.
     * The rendered XML is ghettocached PER-SCOPE (RssCacheKeyArgs) so a bust
     * on one scope never nukes another's; publish/unpublish/delete/restore
     * mirror that key via _bustRssCache().
     * ================================================================== */

    /**
     * The Ghettocache key ARGS for a scope's RSS feed. The writer and the
     * invalidator build the cache key from this SAME array shape — order
     * matters (Ghettocache::key() implodes the values), so a bust always
     * lands on the key the feed stored under.
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @return array
     */
    public static function RssCacheKeyArgs($scopeType, $scopeId)
    {
        return array(
            'scope_type' => (string)$scopeType,
            'scope_id'   => (int)$scopeId,
            'limit'      => self::RSS_LIMIT,
        );
    }

    /**
     * Rendered RSS 2.0 XML for one scope's latest published posts, served from
     * (and stored into) the per-scope ghettocache with a 300s TTL. On a cache
     * miss it renders fresh via _renderRssXml() and caches the result. The
     * controller owns the HTTP concerns (Content-Type header + echo + exit);
     * this returns a string so no headers/exit leak into the lib layer.
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @param array  $channel   ['title','description','index_link','self_link']
     * @return string
     */
    public function RssFeedXml($scopeType, $scopeId, array $channel)
    {
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $gc  = $this->_ghettoCache();
        $key = null;
        if ($gc !== null) {
            $key    = $gc->key(self::RssCacheKeyArgs($scopeType, $scopeId));
            $cached = $gc->get(self::RSS_CACHE_CALL, $key, self::RSS_CACHE_TTL);
            if ($cached !== false) {
                return (string)$cached;
            }
        }

        $xml = $this->_renderRssXml($scopeType, $scopeId, $channel);

        if ($gc !== null && $key !== null) {
            $gc->cache(self::RSS_CACHE_CALL, $key, $xml);
        }
        return $xml;
    }

    /**
     * Build the RSS 2.0 document for a scope's latest published posts. Public
     * read gate only (ListPosts default = published, non-trashed, schedule-due).
     * The <description> uses the curated excerpt, falling back to a plain-text
     * snippet of the post body when the excerpt is empty (RssDescription).
     *
     * @param string $scopeType normalized scope type
     * @param int    $scopeId   scope owner id
     * @param array  $channel   ['title','description','index_link','self_link']
     * @return string
     */
    private function _renderRssXml($scopeType, $scopeId, array $channel)
    {
        $result = $this->ListPosts(array(
            'limit'      => self::RSS_LIMIT,
            'offset'     => 0,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
        ));
        $rows = (isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();

        $title     = (string)(isset($channel['title']) ? $channel['title'] : 'Amtgard News');
        $descr     = (string)(isset($channel['description']) ? $channel['description'] : '');
        $indexLink = (string)(isset($channel['index_link']) ? $channel['index_link'] : '');
        $selfLink  = (string)(isset($channel['self_link']) ? $channel['self_link'] : '');
        $postBase  = (string)(isset($channel['post_base']) ? $channel['post_base'] : $indexLink);
        $buildDate = date('r');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n";
        $xml .= "<channel>\n";
        $xml .= '<title>' . CmsSanitizer::XmlEscape($title) . "</title>\n";
        $xml .= '<link>' . CmsSanitizer::XmlEscape($indexLink) . "</link>\n";
        $xml .= '<description>' . CmsSanitizer::XmlEscape($descr) . "</description>\n";
        $xml .= '<language>en-us</language>' . "\n";
        $xml .= '<lastBuildDate>' . CmsSanitizer::XmlEscape($buildDate) . "</lastBuildDate>\n";
        if ($selfLink !== '') {
            $xml .= '<atom:link href="' . CmsSanitizer::XmlEscape($selfLink) . '" rel="self" type="application/rss+xml" />' . "\n";
        }

        foreach ($rows as $row) {
            $slug  = isset($row['slug']) ? (string)$row['slug'] : '';
            $ptitle = isset($row['title']) ? (string)$row['title'] : '';
            $link  = $this->_rssItemLink($postBase, $slug);
            $descText = $this->RssDescription($row);

            $pubDate = '';
            if (!empty($row['published_at'])) {
                $ts = strtotime((string)$row['published_at']);
                if ($ts !== false) {
                    $pubDate = date('r', $ts);   // RFC-822
                }
            }

            $xml .= "<item>\n";
            $xml .= '<title>' . CmsSanitizer::XmlEscape($ptitle) . "</title>\n";
            $xml .= '<link>' . CmsSanitizer::XmlEscape($link) . "</link>\n";
            $xml .= '<guid isPermaLink="true">' . CmsSanitizer::XmlEscape($link) . "</guid>\n";
            if ($pubDate !== '') {
                $xml .= '<pubDate>' . CmsSanitizer::XmlEscape($pubDate) . "</pubDate>\n";
            }
            if (isset($row['author_name']) && $row['author_name'] !== '') {
                $xml .= '<dc:creator>' . CmsSanitizer::XmlEscape((string)$row['author_name']) . "</dc:creator>\n";
            }
            $xml .= '<description><![CDATA[' . $this->_cdataSafe($descText) . "]]></description>\n";
            if (!empty($row['tags']) && is_array($row['tags'])) {
                foreach ($row['tags'] as $t) {
                    if (!empty($t['name'])) {
                        $xml .= '<category>' . CmsSanitizer::XmlEscape((string)$t['name']) . "</category>\n";
                    }
                }
            }
            $xml .= "</item>\n";
        }

        $xml .= "</channel>\n";
        $xml .= "</rss>\n";
        return $xml;
    }

    /** Join a post-base URL and a slug into an item permalink. */
    private function _rssItemLink($postBase, $slug)
    {
        $postBase = rtrim((string)$postBase, '/');
        return $postBase . '/' . rawurlencode((string)$slug);
    }

    /**
     * The RSS <description> for a post: the curated excerpt when present, else
     * a plain-text snippet rendered from the post body blocks (tags stripped,
     * whitespace collapsed, capped). '' when the post has neither.
     *
     * @param array $row post row (carries 'excerpt' and 'post_id')
     * @return string
     */
    public function RssDescription($row)
    {
        $excerpt = isset($row['excerpt']) ? $this->_plainText((string)$row['excerpt']) : '';
        if ($excerpt !== '') {
            return $excerpt;
        }
        $postId = isset($row['post_id']) ? (int)$row['post_id'] : 0;
        if ($postId <= 0) {
            return '';
        }
        return $this->_bodySnippet($this->GetPostBlocks($postId), 300);
    }

    /**
     * Derive a plain-text snippet from a post's body blocks: pull the prose
     * fields (in reading order) across the block library, strip markup, decode
     * entities, collapse whitespace, and cap at $maxLen on a word boundary.
     *
     * @param array $blocks GetPostBlocks() output ([...,'fields'=>[...]])
     * @param int   $maxLen character cap for the snippet
     * @return string
     */
    private function _bodySnippet($blocks, $maxLen = 300)
    {
        if (!is_array($blocks)) {
            return '';
        }
        // Prose-bearing field keys across the CMS block library, in a rough
        // reading order. Non-prose blocks (image/spacer/divider/…) add nothing.
        $proseKeys = array('kicker', 'heading', 'text', 'body', 'quote', 'cite', 'caption', 'subtitle', 'html');
        $parts = array();
        foreach ($blocks as $block) {
            $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();
            foreach ($proseKeys as $k) {
                if (isset($fields[$k]) && is_string($fields[$k]) && trim($fields[$k]) !== '') {
                    $parts[] = $fields[$k];
                }
            }
        }
        if (empty($parts)) {
            return '';
        }

        $text = $this->_plainText(implode(' ', $parts));
        if ($text === '') {
            return '';
        }

        // Character-aware clamp via the shared CmsBase helpers (one answer for the
        // whole Cms hierarchy) — these carry the byte fallback the hand-rolled
        // mb_* calls here lacked on a host without mbstring.
        if ($this->_strLen($text) <= $maxLen) {
            return $text;
        }
        $cut = $this->_subStr($text, 0, $maxLen);
        $sp  = $this->_strRPos($cut, ' ');
        if ($sp !== false && $sp > 0) {
            $cut = $this->_subStr($cut, 0, $sp);
        }
        return rtrim($cut) . '…';
    }

    /**
     * Reduce authored content to plain text for the RSS <description>: DECODE
     * entities first, THEN strip tags (the reverse order lets an author type
     * "&lt;script&gt;" as literal text and have it decoded back into a real tag
     * inside the CDATA block), then collapse whitespace. Shared by both
     * description paths — the curated excerpt (stored raw, never routed through
     * CmsSanitizer) and the auto-generated body snippet — so they can't drift.
     *
     * @param string $text
     * @return string
     */
    private function _plainText($text)
    {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    }

    /**
     * Make a string safe to nest inside CDATA (only "]]>" can break out).
     * Deliberately private and local: unlike XML escaping (CmsSanitizer::XmlEscape),
     * this has exactly one caller — the RSS <description> below.
     */
    private function _cdataSafe($text)
    {
        return str_replace(']]>', ']]&gt;', (string)$text);
    }

    /**
     * Bust the ghettocached RSS XML for the GLOBAL feed AND the post's own org
     * scope. ListPosts' per-request memo aside, RssFeedXml() 300s-caches the
     * rendered feed; without this an unpublished / trashed post would keep
     * serving (with a now-404 link) for up to five minutes. Called on
     * publish / unpublish / delete / restore. No-op when memcache isn't wired up.
     *
     * @param int $postId
     * @return void
     */
    private function _bustRssCache($postId)
    {
        $gc = $this->_ghettoCache();
        if ($gc === null) {
            return;
        }

        // Always bust global; add the post's own scope when it isn't global.
        $scopes = array(array('global', 0));

        $postId = (int)$postId;
        if ($postId > 0) {
            global $DB;
            $DB->Clear();
            $DB->post_id = $postId;
            $row = $this->_firstRow($DB->DataSet(
                'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_post WHERE post_id = :post_id LIMIT 1'
            ));
            if ($row !== null) {
                $sType = $this->_normalizeScopeType($row['scope_type']);
                $sId   = (int)$row['scope_id'];
                if ($sType !== 'global' || $sId !== 0) {
                    $scopes[] = array($sType, $sId);
                }
            }
        }

        foreach ($scopes as $s) {
            $gc->bust(self::RSS_CACHE_CALL, $gc->key(self::RssCacheKeyArgs($s[0], $s[1])));
        }
    }

    /**
     * Insert a post row.
     *
     * @param array $data slug, title, excerpt, hero_media_id, author_id, status,
     *                    published_at, scope_type, scope_id, created_by, ...
     * @return int new post_id (0 on failure)
     */
    public function CreatePost($data)
    {
        $now = date('Y-m-d H:i:s');

        // Parity with UpdatePage/UpdatePost (#59): the lib layer — not just the
        // controller — refuses an empty slug, which would publish the post at
        // 'post/' and collide with any other empty-slug post in the same scope.
        $slug = $this->_normalizeSlug((string)(isset($data['slug']) ? $data['slug'] : ''));
        if ($slug === '') {
            return 0;
        }

        $cols = array(
            'slug'          => $slug,
            'title'         => isset($data['title']) ? (string)$data['title'] : '',
            'excerpt'       => isset($data['excerpt']) ? $data['excerpt'] : null,
            'hero_media_id' => (isset($data['hero_media_id']) && $data['hero_media_id'] !== '') ? (int)$data['hero_media_id'] : null,
            'author_id'     => (isset($data['author_id']) && $data['author_id'] !== '') ? (int)$data['author_id'] : null,
            'status'        => (isset($data['status']) && (string)$data['status'] === 'published') ? 'published' : 'draft',
            'published_at'  => isset($data['published_at']) ? $data['published_at'] : null,
            'scope_type'    => $this->_normalizeScopeType(isset($data['scope_type']) ? $data['scope_type'] : 'global'),
            'scope_id'      => isset($data['scope_id']) ? (int)$data['scope_id'] : 0,
            'created_by'    => (isset($data['created_by']) && $data['created_by'] !== '') ? (int)$data['created_by'] : null,
            'created_at'    => isset($data['created_at']) ? $data['created_at'] : $now,
            'updated_by'    => (isset($data['updated_by']) && $data['updated_by'] !== '')
                ? (int)$data['updated_by']
                : ((isset($data['created_by']) && $data['created_by'] !== '') ? (int)$data['created_by'] : null),
            'updated_at'    => isset($data['updated_at']) ? $data['updated_at'] : $now,
        );

        // Publishing without an explicit timestamp stamps published_at.
        if ($cols['status'] === 'published' && empty($cols['published_at'])) {
            $cols['published_at'] = $now;
        }

        // Shared dup-guarded insert (C29 + live-slug reuse): the dup pre-check is
        // scoped to LIVE rows only, so a new post CAN reuse a TRASHED post's slug.
        // INSERT IGNORE + ROW_COUNT() race arbitration + authoritative
        // read-back-by-live-tuple all live in CmsBase::_insertWithDupGuard.
        $id = $this->_insertWithDupGuard('cms_post', 'post_id', $cols);
        $this->_invalidateListCache();

        // #62: audit the content-creating write (mirrors CmsPage::CreatePage) so
        // content mutations are trailed, not just delete/restore/publish. Actor =
        // whoever the create attributed the row to; scope from the new row.
        if ($id > 0) {
            $actorId = (int)($cols['updated_by'] ?? ($cols['created_by'] ?? 0));
            $this->_cmsAudit($actorId, 'update', 'post', $id, $cols['scope_type'], (int)$cols['scope_id']);
        }

        return $id;
    }

    /**
     * Fetch a single post row by primary key (admin/editor — any status/scope),
     * decorated with author_name + tags. Returns null when not found.
     *
     * @param int $postId
     * @return array|null
     */
    public function GetPost($postId)
    {
        global $DB;

        $postId = (int)$postId;
        if ($postId <= 0) {
            return null;
        }

        $DB->Clear();
        $DB->post_id = $postId;
        // C2: a trashed post is invisible to editor/publish/delete surfaces;
        // restore reads the trashed row directly (see RestorePost()).
        $row = $this->_firstRow($DB->DataSet(
            $this->_livePostSelectHead()
            . ' WHERE p.post_id = :post_id AND p.deleted_at IS NULL LIMIT 1'
        ));

        if ($row === null) {
            return null;
        }
        $row['tags'] = $this->GetTags($postId);
        return $row;
    }

    /**
     * Update editable post meta. Only provided keys are written; updated_at is
     * always stamped. Returns true when a valid id was supplied and UPDATE ran.
     *
     * @param int         $postId
     * @param array       $data      subset: title, slug, excerpt, hero_media_id,
     *                               author_id, status, published_at, scope_type,
     *                               scope_id, updated_by
     * @param string|null $scopeType IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId   IDOR guard: caller's intended scope_id
     * @return bool
     */
    public function UpdatePost($postId, $data, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $postId = (int)$postId;
        if ($postId <= 0 || !is_array($data)) {
            return false;
        }

        // #54: existence + not-trashed guard. A nonexistent OR trashed post
        // short-circuits to false here rather than running a no-op UPDATE that
        // "succeeds" against zero rows. Runs its own Clear()/DataSet(), so it
        // precedes the bind loop below.
        $DB->Clear();
        $DB->post_id = $postId;
        // Carry scope_type/scope_id too so the #62 audit below has the row's scope
        // without a second read.
        $existRow = $this->_firstRow($DB->DataSet(
            'SELECT post_id, scope_type, scope_id FROM ' . DB_PREFIX . 'cms_post'
            . ' WHERE post_id = :post_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($existRow === null) {
            return false;
        }

        // IDOR guard (opt-in, mirrors UpdatePage/DeletePost): refuse to touch a
        // post in a different org, and refuse to relocate it OUT of the guarded
        // scope. Shared with UpdatePage via CmsBase::_scopeGuardOk, which runs its
        // own Clear()/DataSet() — so it precedes the bind loop below.
        if (!$this->_scopeGuardOk('cms_post', 'post_id', $postId, $scopeType, $scopeId, $data)) {
            return false;
        }

        // Dup-slug pre-check when the slug is genuinely changing (mirrors
        // UpdatePage): refuse a rename onto a slug already held by ANOTHER LIVE
        // post in the target scope, so the UPDATE can't silently drop against
        // uq_post_scope_slug_live. Both this check and the normalize run their own
        // Clear()/DataSet(), so they precede the bind loop below.
        $newSlug     = null;   // normalized target slug, computed up front when set
        $slugChanged = false;
        if (array_key_exists('slug', $data)) {
            $newSlug = $this->_normalizeSlug((string)$data['slug']);

            $DB->Clear();
            $DB->post_id = $postId;
            $preRow = $this->_firstRow($DB->DataSet(
                'SELECT scope_type, scope_id, slug FROM ' . DB_PREFIX . 'cms_post WHERE post_id = :post_id LIMIT 1'
            ));
            $slugChanged = ($preRow !== null && $newSlug !== '' && $newSlug !== (string)$preRow['slug']);

            if ($newSlug !== '' && $slugChanged) {
                // Effective target scope: an in-flight scope change wins, else the
                // post's current scope.
                $chkType = array_key_exists('scope_type', $data)
                    ? $this->_normalizeScopeType($data['scope_type'])
                    : (string)$preRow['scope_type'];
                $chkId = array_key_exists('scope_id', $data)
                    ? (int)$data['scope_id']
                    : (int)$preRow['scope_id'];

                if ($this->_liveSlugOwner('cms_post', 'post_id', $newSlug, $chkType, $chkId, $postId) > 0) {
                    return false;   // slug already in use in this scope — collision
                }
            }
        }

        $set = array();
        $DB->Clear();

        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $DB->title = (string)$data['title'];
        }
        if (array_key_exists('slug', $data) && $newSlug !== '') {
            // $newSlug was normalized (and dup-checked) up front. Parity with
            // CmsPage::UpdatePage (#59): never persist an empty slug — silently
            // keep the existing one rather than writing slug = ''.
            $set[] = 'slug = :slug';
            $DB->slug = $newSlug;
        }
        if (array_key_exists('excerpt', $data)) {
            $set[] = 'excerpt = :excerpt';
            $DB->excerpt = ($data['excerpt'] === null) ? null : (string)$data['excerpt'];
        }
        if (array_key_exists('hero_media_id', $data)) {
            $set[] = 'hero_media_id = :hero_media_id';
            $DB->hero_media_id = ($data['hero_media_id'] === null || $data['hero_media_id'] === '')
                ? null : (int)$data['hero_media_id'];
        }
        if (array_key_exists('author_id', $data)) {
            $set[] = 'author_id = :author_id';
            $DB->author_id = ($data['author_id'] === null || $data['author_id'] === '')
                ? null : (int)$data['author_id'];
        }
        if (array_key_exists('status', $data)) {
            // C7: 'scheduled' is a first-class status (promoted to published on
            // read once published_at arrives); anything else clamps to draft.
            $status = (string)$data['status'];
            if ($status !== 'published' && $status !== 'scheduled') {
                $status = 'draft';
            }
            $set[] = 'status = :status';
            $DB->status = $status;
        }
        if (array_key_exists('published_at', $data)) {
            $set[] = 'published_at = :published_at';
            $DB->published_at = ($data['published_at'] === null || $data['published_at'] === '')
                ? null : (string)$data['published_at'];
        }
        if (array_key_exists('scope_type', $data)) {
            $set[] = 'scope_type = :scope_type';
            $DB->scope_type = $this->_normalizeScopeType($data['scope_type']);
        }
        if (array_key_exists('scope_id', $data)) {
            $set[] = 'scope_id = :scope_id';
            $DB->scope_id = (int)$data['scope_id'];
        }

        // No caller-supplied columns → nothing to update (checked before the
        // unconditional updated_at append so an empty $data is a true no-op).
        if (count($set) === 0) {
            return false;
        }

        $set[] = 'updated_at = :updated_at';
        $DB->updated_at = date('Y-m-d H:i:s');
        if (array_key_exists('updated_by', $data)) {
            $set[] = 'updated_by = :updated_by';
            $DB->updated_by = ($data['updated_by'] === null || $data['updated_by'] === '')
                ? null : (int)$data['updated_by'];
        }

        $DB->post_id = $postId;
        // #54: the deleted_at IS NULL guard means a post trashed between the top
        // existence check and here matches zero rows → ROW_COUNT() < 1 → false.
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_post SET ' . implode(', ', $set)
            . ' WHERE post_id = :post_id AND deleted_at IS NULL'
        );

        // #54: confirm a live row was actually updated. updated_at is bumped on
        // every save, so a matching non-trashed row always reports >= 1 changed
        // row; a nonexistent/trashed target reports 0. Read immediately after the
        // Execute on the same connection (before any other query).
        if ($this->_affectedRows() < 1) {
            return false;
        }

        // After a slug rename, verify the new slug actually LANDED before
        // reporting success. Execute() is void under ERRMODE_WARNING, so a
        // silently-dropped UPDATE (e.g. a racing writer claimed the tuple between
        // the pre-check and the write) would otherwise report a false success.
        if ($slugChanged) {
            $DB->Clear();
            $DB->post_id = $postId;
            $verify = $this->_firstRow($DB->DataSet(
                'SELECT slug FROM ' . DB_PREFIX . 'cms_post WHERE post_id = :post_id LIMIT 1'
            ));
            if ($verify === null || (string)$verify['slug'] !== (string)$newSlug) {
                return false;   // rename didn't take — signal failure
            }

            // #57: 301 the OLD post path to the new one so inbound links / bookmarks
            // keep resolving. A post target can't use to_page_id (that FK references
            // cms_page), so record the post's NEW path as a relative to_url — the
            // site router resolves it under the org's pretty base. Recorded under
            // the OLD scope (where the old URL lived); best-effort via the shared
            // redirect store on CmsPage. NOTE: a further rename supersedes the
            // middle path (this to_url is a snapshot, not a live-resolved page).
            $oldSlug = ($preRow !== null) ? (string)$preRow['slug'] : '';
            if ($oldSlug !== '' && $oldSlug !== (string)$newSlug) {
                $sType = ($preRow !== null) ? (string)$preRow['scope_type'] : 'global';
                $sId   = ($preRow !== null) ? (int)$preRow['scope_id'] : 0;
                $actor = (isset($data['updated_by']) && (int)$data['updated_by'] > 0) ? (int)$data['updated_by'] : 0;
                $this->_pages()->RecordRedirect(
                    $sType,
                    $sId,
                    'post/' . $oldSlug,
                    null,
                    'post/' . (string)$newSlug,
                    $actor
                );
            }
        }

        // Covers SetStatus too (it delegates here).
        $this->_invalidateListCache();
        // A rename changes the permalink and title/excerpt edits are feed-visible,
        // so the 300s-cached feed XML has to go too (SetStatus double-busting is
        // harmless; no-op when memcache isn't wired up).
        $this->_bustRssCache($postId);

        // #62: audit the content-mutating write. Scope = the effective (post-edit)
        // scope — an in-flight scope move wins, else the row's current scope.
        $auditType = array_key_exists('scope_type', $data)
            ? $this->_normalizeScopeType($data['scope_type'])
            : (string)$existRow['scope_type'];
        $auditId = array_key_exists('scope_id', $data)
            ? (int)$data['scope_id']
            : (int)$existRow['scope_id'];
        $auditActor = (isset($data['updated_by']) && (int)$data['updated_by'] > 0) ? (int)$data['updated_by'] : 0;

        // A title/slug/status/published_at edit changes what a nav item linking to
        // this post resolves to, so bump the scope's content version — the key of
        // the cross-request nav cache (CmsNav::GetMenu). SetStatus delegates here,
        // so publish/unpublish/schedule are covered. A scope MOVE changes the nav
        // of both scopes, so bump both.
        $this->_bumpContentVersion($auditType, $auditId);
        if ((string)$existRow['scope_type'] !== $auditType || (int)$existRow['scope_id'] !== $auditId) {
            $this->_bumpContentVersion((string)$existRow['scope_type'], (int)$existRow['scope_id']);
        }

        $this->_cmsAudit($auditActor, 'update', 'post', $postId, $auditType, $auditId);

        return true;
    }

    /**
     * Set a post's publish status, stamping published_at on publish (only when
     * currently empty; unpublishing leaves the historical stamp intact).
     *
     * When $status is 'scheduled' a future $publishedAt is required (the read
     * path promotes it to 'published' once that time passes — see C7).
     *
     * @param int         $postId
     * @param string      $status      'published' | 'draft' | 'scheduled'
     * @param int         $uid         actor mundane_id (0 to skip)
     * @param string|null $publishedAt explicit publish timestamp (scheduling)
     * @return bool
     */
    public function SetStatus($postId, $status, $uid = 0, $publishedAt = null)
    {
        $postId = (int)$postId;
        // Shared publish-lifecycle skeleton (status clamp, published_at stamping,
        // C14 audit) lives in CmsBase::_setStatus; the column write delegates back
        // to UpdatePost so its whitelist/verify/cache path still runs.
        $ok = $this->_setStatus(
            'cms_post',
            'post_id',
            'post',
            $postId,
            $status,
            $uid,
            $publishedAt,
            function ($data) use ($postId) {
                return $this->UpdatePost($postId, $data);
            }
        );
        if ($ok) {
            // Publish/unpublish changes what the public feed contains — bust the
            // 300s ghettocache so a just-unpublished post stops serving (and a
            // just-published one appears) immediately.
            $this->_bustRssCache($postId);
        }
        return $ok;
    }

    /**
     * Trash a post (C2 soft-delete): stamp deleted_at instead of physically
     * DELETEing, so the post, its body blocks, tag links and revisions survive
     * for restore. Within the same transaction it NULLs any ork_cms_nav_item.post_id
     * pointing here (C8; the ON DELETE SET NULL FK does not fire on a soft-delete).
     *
     * @param int         $postId
     * @param string|null $scopeType IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId   IDOR guard: caller's intended scope_id
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @return bool true when the post existed and was trashed
     */
    public function DeletePost($postId, $scopeType = null, $scopeId = null, $actorId = 0)
    {
        // Shared soft-delete skeleton (existence + IDOR guard, transactional
        // stamp, verify, C14 audit). The $refCleanup hook carries the post-only
        // inbound-nav detach (C8) — the ON DELETE SET NULL FK does not fire on a
        // soft-delete, so it runs explicitly inside the transaction before the
        // trash marker is stamped. Body blocks/tags/revisions are retained.
        $ok = $this->_softDelete(
            'cms_post',
            'post_id',
            $postId,
            $scopeType,
            $scopeId,
            $actorId,
            'post',
            function ($id) {
                global $DB;

                // A nav item pointing here resolves to '#'.
                $DB->Clear();
                $DB->post_id = $id;
                $DB->Execute(
                    'UPDATE ' . DB_PREFIX . 'cms_nav_item SET post_id = NULL WHERE post_id = :post_id'
                );
            }
        );

        if ($ok) {
            $this->_invalidateListCache();
            // A trashed post must vanish from the feed at once (its /post/ link
            // now 404s) — bust the cached XML rather than waiting out the TTL.
            $this->_bustRssCache($postId);
        }
        return $ok;
    }

    /**
     * Restore a trashed post (clear deleted_at). Optional IDOR scope guard.
     * Detached nav references are NOT re-linked (they were cleared on trash).
     *
     * @param int         $postId
     * @param string|null $scopeType IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId   IDOR guard: caller's intended scope_id
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @return bool
     */
    public function RestorePost($postId, $scopeType = null, $scopeId = null, $actorId = 0)
    {
        // Shared restore skeleton: existence/IDOR guard, live-slug collision guard
        // (a live post may have claimed this slug while we were trashed — see
        // CmsBase::_restore), verified un-trash, C14 audit.
        $ok = $this->_restore('cms_post', 'post_id', $postId, $scopeType, $scopeId, $actorId, 'post');
        if ($ok) {
            $this->_invalidateListCache();
            // A restored (published) post may re-enter the feed — bust so it
            // reappears without waiting out the TTL.
            $this->_bustRssCache($postId);
        }
        return $ok;
    }

    /**
     * Did RestorePost() fail specifically because a LIVE post now holds the
     * trashed post's slug? Callers use this only to choose an error message.
     *
     * @param int $postId
     * @return bool
     */
    public function RestoreSlugConflict($postId)
    {
        return $this->_slugConflictForTrashed('cms_post', 'post_id', $postId);
    }

    /**
     * Upsert the supplied tag names into ork_cms_tag (slugified), then REPLACE
     * the post's ork_cms_post_tag links with exactly that set.
     *
     * @param int   $postId
     * @param array $tagNames raw display names
     * @return bool
     */
    public function SetTags($postId, array $tagNames)
    {
        global $DB;

        $postId = (int)$postId;
        if ($postId <= 0) {
            return false;
        }

        // Resolve each name → tag_id (upsert), de-duped by slug.
        $tagIds = array();
        foreach ($tagNames as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $tagId = $this->_upsertTag($name);
            if ($tagId > 0) {
                $tagIds[$tagId] = $tagId;
            }
        }

        // Replace links atomically: clear, then insert the resolved set. The
        // transaction prevents a silent INSERT failure (ERRMODE_WARNING) from
        // leaving a partial tag set after the DELETE. Mirrors ReplaceBlocks.
        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        $DB->Clear();
        $DB->post_id = $postId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_post_tag WHERE post_id = :post_id'
        );

        // Single multi-row INSERT for all resolved links (O(1) round-trip vs
        // O(N) per-tag INSERTs). Distinct placeholders per row; post_id repeated
        // safely as code-controlled int. Stays inside the open transaction.
        if (!empty($tagIds)) {
            $rows = array();
            $i = 0;
            $DB->Clear();
            foreach ($tagIds as $tagId) {
                $rows[] = '(:post_id_' . $i . ', :tag_id_' . $i . ')';
                $DB->{'post_id_' . $i} = $postId;
                $DB->{'tag_id_' . $i} = (int)$tagId;
                $i++;
            }
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_post_tag (post_id, tag_id)'
                . ' VALUES ' . implode(', ', $rows)
            );
        }

        // Post-write verification before COMMIT (mirrors ReplaceBlocks): confirm
        // exactly the resolved link count landed. A silent partial INSERT under
        // ERRMODE_WARNING would otherwise leave a truncated tag set post-DELETE.
        $DB->Clear();
        $DB->post_id = $postId;
        $countRow = $this->_firstRow($DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_post_tag WHERE post_id = :post_id'
        ));
        $written = ($countRow !== null && isset($countRow['c'])) ? (int)$countRow['c'] : -1;
        if ($written !== count($tagIds)) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return false;
        }

        $DB->Clear();
        $DB->Execute('COMMIT');

        $this->_invalidateListCache();

        return true;
    }

    /**
     * Tags linked to a post, ordered by name.
     *
     * @param int $postId
     * @return array list of ['name','slug']
     */
    public function GetTags($postId)
    {
        global $DB;

        $postId = (int)$postId;
        if ($postId <= 0) {
            return array();
        }

        $DB->Clear();
        $DB->post_id = $postId;
        $r = $DB->DataSet(
            'SELECT t.name, t.slug FROM ' . DB_PREFIX . 'cms_tag t'
            . ' INNER JOIN ' . DB_PREFIX . 'cms_post_tag pt ON pt.tag_id = t.tag_id'
            . ' WHERE pt.post_id = :post_id'
            . ' ORDER BY t.name ASC'
        );

        $tags = array();
        foreach ($this->_eachRow($r) as $row) {
            $tags[] = array('name' => $row['name'], 'slug' => $row['slug']);
        }
        return $tags;
    }

    /**
     * Every tag in the library, with a usage count, ordered by name.
     *
     * @return array list of ['tag_id','name','slug','post_count']
     */
    public function ListAllTags()
    {
        global $DB;

        $DB->Clear();
        $r = $DB->DataSet(
            'SELECT t.tag_id, t.name, t.slug, COUNT(pt.post_id) AS post_count'
            . ' FROM ' . DB_PREFIX . 'cms_tag t'
            . ' LEFT JOIN ' . DB_PREFIX . 'cms_post_tag pt ON pt.tag_id = t.tag_id'
            . ' GROUP BY t.tag_id, t.name, t.slug'
            . ' ORDER BY t.name ASC'
        );

        $out = array();
        foreach ($this->_eachRow($r) as $row) {
            $out[] = array(
                'tag_id'     => (int)$row['tag_id'],
                'name'       => $row['name'],
                'slug'       => $row['slug'],
                'post_count' => (int)$row['post_count'],
            );
        }
        return $out;
    }

    /**
     * A single tag row by slug (for a per-tag landing header). Null when unknown.
     *
     * INTENTIONALLY UNCONSUMED — no caller today. The SHIPPED per-tag path is
     * Controller_Blog::index (controller.Blog.php:61-73), which passes the raw
     * ?tag= straight into ListPosts' tag filter and keeps the generic blog page
     * title. Wiring this in would change both the page title and the response to
     * an unknown tag, so it is a behavior change, not a cleanup. Kept as the seam
     * for a future dedicated tag landing.
     *
     * @param string $slug tag slug
     * @return array|null ['tag_id','name','slug'] or null
     */
    public function GetTagBySlug($slug)
    {
        global $DB;

        $slug = $this->_slugify($slug);
        if ($slug === '') {
            return null;
        }

        $DB->Clear();
        $DB->slug = $slug;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT tag_id, name, slug FROM ' . DB_PREFIX . 'cms_tag WHERE slug = :slug LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }
        return array(
            'tag_id' => (int)$row['tag_id'],
            'name'   => (string)$row['name'],
            'slug'   => (string)$row['slug'],
        );
    }

    /**
     * Browsable per-tag landing data: the tag header plus the published posts
     * carrying it, newest-first, scope-filtered. Reuses ListPosts (which enforces
     * the C2 trash + C7 schedule gates) so the landing can never surface a
     * trashed/unpublished post. The RENDER ROUTE lives in the other lane
     * (controller.Blog); this method only exposes the data + is the seam.
     *
     * INTENTIONALLY UNCONSUMED — same rationale as GetTagBySlug above: the
     * shipped tag path is Controller_Blog::index (controller.Blog.php:61-73).
     * Do not wire this up as a "cleanup"; it changes the page title and the
     * unknown-tag response.
     *
     * @param string $tagSlug   tag slug
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @param int    $limit     max posts (0 = ListPosts default paging)
     * @param int    $offset    paging offset
     * @return array ['tag'=>['name','slug']|null, 'posts'=>[...], 'total'=>int]
     */
    public function GetTagLanding($tagSlug, $scopeType = 'global', $scopeId = 0, $limit = 0, $offset = 0)
    {
        $tag = $this->GetTagBySlug($tagSlug);
        if ($tag === null) {
            // Unknown tag: an empty landing (never leak another tag's posts).
            return array('tag' => null, 'posts' => array(), 'total' => 0);
        }

        $opts = array(
            'tag'        => $tag['slug'],
            'scope_type' => $scopeType,
            'scope_id'   => (int)$scopeId,
        );
        if ((int)$limit > 0) {
            $opts['limit']  = (int)$limit;
            $opts['offset'] = (int)$offset;
        }

        $res = $this->ListPosts($opts);
        return array(
            'tag'   => array('name' => $tag['name'], 'slug' => $tag['slug']),
            'posts' => (isset($res['rows']) && is_array($res['rows'])) ? $res['rows'] : array(),
            'total' => isset($res['total']) ? (int)$res['total'] : 0,
        );
    }

    /**
     * SQL fragment for the neutral byline fallback used when a post has no
     * persona author (blank persona OR an orphaned/removed author row). Emits a
     * scope-aware label: 'Staff' on the global front door, 'Kingdom' on an org
     * site — never a member's real given name (C21 PII).
     *
     * @return string a CASE expression (references p.scope_type)
     */
    private function _neutralAuthorSql()
    {
        return "CASE WHEN p.scope_type = 'global' THEN 'Staff' ELSE 'Kingdom' END";
    }

    /**
     * The shared SELECT ... FROM ... JOIN head every full-row post read uses:
     * the whole post row aliased `p`, plus the C21-safe byline (persona, else the
     * neutral org-scoped label) as author_name, over the mundane LEFT JOIN the
     * byline needs. Callers append their own WHERE/GROUP/ORDER/LIMIT.
     *
     * @param string|null $join override the join clause (ListPosts appends its
     *                          optional tag INNER JOINs to the same mundane join)
     * @return string
     */
    private function _livePostSelectHead($join = null)
    {
        if ($join === null) {
            $join = ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = p.author_id';
        }
        return 'SELECT p.*, COALESCE(NULLIF(m.persona, \'\'), ' . $this->_neutralAuthorSql() . ') AS author_name'
            . ' FROM ' . DB_PREFIX . 'cms_post p'
            . (string)$join;
    }

    /**
     * Find-or-create a tag by display name (keyed on its slug). Returns tag_id.
     *
     * @param string $name
     * @return int tag_id (0 on failure)
     */
    private function _upsertTag($name)
    {
        global $DB;

        $name = trim((string)$name);
        if ($name === '') {
            return 0;
        }
        $slug = $this->_slugify($name);
        if ($slug === '') {
            return 0;
        }

        // Existing?
        $DB->Clear();
        $DB->slug = $slug;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT tag_id FROM ' . DB_PREFIX . 'cms_tag WHERE slug = :slug LIMIT 1'
        ));
        if ($existing !== null && isset($existing['tag_id'])) {
            return (int)$existing['tag_id'];
        }

        // INSERT IGNORE is a no-op on duplicate slug; we always resolve the id
        // via read-back so lastInsertId() stale-value from a prior successful
        // insert can never leak to a second tag in the same SetTags loop.
        $DB->Clear();
        $DB->name = $name;
        $DB->slug = $slug;
        $DB->Execute(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'cms_tag (name, slug)'
            . ' VALUES (:name, :slug)'
        );

        // Authoritative read-back by slug (same pattern as CmsAuth::GrantRole).
        $DB->Clear();
        $DB->slug = $slug;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT tag_id FROM ' . DB_PREFIX . 'cms_tag WHERE slug = :slug LIMIT 1'
        ));
        return ($row !== null && isset($row['tag_id'])) ? (int)$row['tag_id'] : 0;
    }

    /**
     * Slugify a tag name: lowercase, ASCII, non-alnum → single hyphen, trimmed.
     * Clamped to 80 chars (ork_cms_tag.slug width).
     *
     * Routed through CmsBase::_normalizeSlug() rather than carrying its own
     * transliteration. This used to call iconv('ASCII//TRANSLIT'), whose output is
     * libc- and locale-dependent, and a tag slug is BOTH a public URL segment
     * (Blog_post.tpl renders it into 'Blog/index&tag=') and the de-duplication key
     * in _upsertTag() — so the same tag name entered on two different hosts
     * produced two ork_cms_tag rows for one tag. The shared helper is
     * byte-identical everywhere and already matches what glibc iconv produced, so
     * slugs stored by the container keep resolving.
     */
    private function _slugify($text)
    {
        return $this->_normalizeSlug((string)$text, 80);
    }

    /**
     * Status-broken-down live post counts for a scope, via a single GROUP BY (no
     * full-row fetch). Only non-trashed rows are counted (deleted_at IS NULL).
     * Lets admin surfaces show "N drafts / M published" without materializing the
     * rows. Statuses with no rows are simply absent from the map.
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @return array ['total' => int, '<status>' => int, ...] e.g.
     *               ['total'=>5,'draft'=>1,'published'=>3,'scheduled'=>1]
     */
    public function CountPosts($scopeType, $scopeId)
    {
        return $this->_countByStatus('cms_post', $scopeType, $scopeId);
    }

    /**
     * E117/#117: prune tags with ZERO live post_tag usage from ork_cms_tag. A tag
     * is deleted only when no ork_cms_post_tag row references it — i.e. it labels
     * no post at all (an empty vocabulary entry left behind after retag/delete).
     * NON-DESTRUCTIVE: any tag still attached to any post is retained; nothing that
     * a live surface uses is touched. Idempotent and safe to re-run.
     *
     * @return int number of unused tag rows removed
     */
    public function PruneUnusedTags()
    {
        global $DB;

        // LEFT JOIN / IS NULL form (rather than NOT IN) so an empty link table is
        // handled cleanly and the DELETE target isn't referenced in a subquery.
        $DB->Clear();
        $DB->Execute(
            'DELETE t FROM ' . DB_PREFIX . 'cms_tag t'
            . ' LEFT JOIN ' . DB_PREFIX . 'cms_post_tag pt ON pt.tag_id = t.tag_id'
            . ' WHERE pt.post_id IS NULL'
        );

        // Affected-row count via ROW_COUNT() before any other query intervenes.
        $removed = $this->_affectedRows();

        if ($removed > 0) {
            $this->_invalidateListCache();
        }
        return $removed;
    }

}
