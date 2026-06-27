<?php

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

        $sql = 'SELECT p.*, COALESCE(NULLIF(m.persona, \'\'), m.given_name) AS author_name'
            . ' FROM ' . DB_PREFIX . 'cms_post p'
            . ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = p.author_id'
            . ' WHERE p.slug = :slug AND p.scope_type = :scope_type AND p.scope_id = :scope_id';
        if ($publishedOnly) {
            $sql .= " AND p.status = 'published'";
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

        $scopeType = $this->_normalizeScopeType(isset($opts['scope_type']) ? $opts['scope_type'] : 'global');
        $scopeId = isset($opts['scope_id']) ? (int)$opts['scope_id'] : 0;
        $includeDrafts = !empty($opts['includeDrafts']);
        $tag = isset($opts['tag']) && $opts['tag'] !== '' ? (string)$opts['tag'] : '';

        $where = array('p.scope_type = :scope_type', 'p.scope_id = :scope_id');
        if (!$includeDrafts) {
            $where[] = "p.status = 'published'";
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

        $sql = 'SELECT p.*, COALESCE(NULLIF(m.persona, \'\'), m.given_name) AS author_name'
            . ' FROM ' . DB_PREFIX . 'cms_post p'
            . $join
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
        if (!empty($rows)) {
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
        }

        return array('rows' => $rows, 'total' => $total);
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
        global $DB;

        $now = date('Y-m-d H:i:s');

        $cols = array(
            'slug'          => preg_replace('/[^a-z0-9\-]+/', '', strtolower((string)(isset($data['slug']) ? $data['slug'] : ''))),
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

        $names = array_keys($cols);
        $placeholders = array();
        foreach ($names as $n) {
            $placeholders[] = ':' . $n;
        }
        $sql = 'INSERT INTO ' . DB_PREFIX . 'cms_post (`' . implode('`, `', $names) . '`)'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        // Pre-check for duplicate (scope_type, scope_id, slug) to avoid the
        // stale-lastInsertId hazard under PDO ERRMODE_WARNING.
        $DB->Clear();
        $DB->slug       = $cols['slug'];
        $DB->scope_type = $cols['scope_type'];
        $DB->scope_id   = (int)$cols['scope_id'];
        $dup = $this->_firstRow($DB->DataSet(
            'SELECT post_id FROM ' . DB_PREFIX . 'cms_post'
            . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
        ));
        if ($dup !== null) {
            return 0;
        }

        $DB->Clear();
        foreach ($cols as $field => $value) {
            $DB->$field = $value;
        }
        $DB->Execute($sql);

        // Authoritative read-back by the unique tuple (lastInsertId unreliable
        // under PDO ERRMODE_WARNING on duplicate key).
        $DB->Clear();
        $DB->slug       = $cols['slug'];
        $DB->scope_type = $cols['scope_type'];
        $DB->scope_id   = (int)$cols['scope_id'];
        $row = $this->_firstRow($DB->DataSet(
            'SELECT post_id FROM ' . DB_PREFIX . 'cms_post'
            . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
        ));
        return ($row !== null && isset($row['post_id'])) ? (int)$row['post_id'] : 0;
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
        $row = $this->_firstRow($DB->DataSet(
            'SELECT p.*, COALESCE(NULLIF(m.persona, \'\'), m.given_name) AS author_name'
            . ' FROM ' . DB_PREFIX . 'cms_post p'
            . ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = p.author_id'
            . ' WHERE p.post_id = :post_id LIMIT 1'
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
     * @param int   $postId
     * @param array $data subset: title, slug, excerpt, hero_media_id, author_id,
     *                    status, published_at, scope_type, scope_id, updated_by
     * @return bool
     */
    public function UpdatePost($postId, $data)
    {
        global $DB;

        $postId = (int)$postId;
        if ($postId <= 0 || !is_array($data)) {
            return false;
        }

        $set = array();
        $DB->Clear();

        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $DB->title = (string)$data['title'];
        }
        if (array_key_exists('slug', $data)) {
            $set[] = 'slug = :slug';
            $DB->slug = preg_replace('/[^a-z0-9\-]+/', '', strtolower((string)$data['slug']));
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
            $status = ((string)$data['status'] === 'published') ? 'published' : 'draft';
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
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_post SET ' . implode(', ', $set)
            . ' WHERE post_id = :post_id'
        );

        return true;
    }

    /**
     * Set a post's publish status, stamping published_at on publish (only when
     * currently empty; unpublishing leaves the historical stamp intact).
     *
     * @param int    $postId
     * @param string $status 'published' | 'draft'
     * @param int    $uid    actor mundane_id (0 to skip)
     * @return bool
     */
    public function SetStatus($postId, $status, $uid = 0)
    {
        $postId = (int)$postId;
        if ($postId <= 0) {
            return false;
        }
        $status = ((string)$status === 'published') ? 'published' : 'draft';

        $data = array('status' => $status);
        if ((int)$uid > 0) {
            $data['updated_by'] = (int)$uid;
        }

        if ($status === 'published') {
            // Targeted single-column read (GetPost would also JOIN the author +
            // run a second tags query, both discarded here).
            global $DB;
            $DB->Clear();
            $DB->post_id = $postId;
            $row = $this->_firstRow($DB->DataSet(
                'SELECT published_at FROM ' . DB_PREFIX . 'cms_post WHERE post_id = :post_id LIMIT 1'
            ));
            if ($row !== null && empty($row['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
        }

        return $this->UpdatePost($postId, $data);
    }

    /**
     * Delete a post, its body blocks (via ReplaceBlocks('post',id,[])), and its
     * tag links. Returns true when the post existed and was removed.
     *
     * @param int $postId
     * @return bool
     */
    public function DeletePost($postId)
    {
        global $DB;

        $postId = (int)$postId;
        if ($postId <= 0) {
            return false;
        }

        // Existence check only — a targeted single-column read (GetPost would
        // also JOIN the author + fetch tags, all unused on the delete path).
        $DB->Clear();
        $DB->post_id = $postId;
        $exists = $this->_firstRow($DB->DataSet(
            'SELECT post_id FROM ' . DB_PREFIX . 'cms_post WHERE post_id = :post_id LIMIT 1'
        ));
        if ($exists === null) {
            return false;
        }

        // Remove body blocks (direct DELETE, as DeletePage does for its blocks).
        $DB->Clear();
        $DB->owner_id = $postId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_block'
            . " WHERE owner_type = 'post' AND owner_id = :owner_id"
        );

        // Remove tag links.
        $DB->Clear();
        $DB->post_id = $postId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_post_tag WHERE post_id = :post_id'
        );

        // Remove the post row.
        $DB->Clear();
        $DB->post_id = $postId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_post WHERE post_id = :post_id'
        );

        return true;
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

        foreach ($tagIds as $tagId) {
            $DB->Clear();
            $DB->post_id = $postId;
            $DB->tag_id = (int)$tagId;
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_post_tag (post_id, tag_id)'
                . ' VALUES (:post_id, :tag_id)'
            );
        }

        $DB->Clear();
        $DB->Execute('COMMIT');

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
     * Slugify a string: lowercase, ASCII, non-alnum → single hyphen, trimmed.
     * Clamped to 80 chars (ork_cms_tag.slug width).
     */
    private function _slugify($text)
    {
        $text = (string)$text;
        // Best-effort transliteration to ASCII.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string)$text, '-');
        if (strlen($text) > 80) {
            $text = rtrim(substr($text, 0, 80), '-');
        }
        return $text;
    }

}
