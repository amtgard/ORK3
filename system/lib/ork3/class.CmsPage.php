<?php

/*************************************************************************
 * CmsPage — content store for the CMS.
 *
 * Reads/writes ork_cms_page + ork_cms_block (polymorphic: owner_type
 * 'page'|'post'). Block rows are decoded into the SAME shape the
 * front-door renderer consumes (Model_FrontDoor::GetContent):
 *   ['id','type','enabled','order','source','fields'].
 *
 * DB idiom: uses the shared global $DB (YapoDb). Always Clear() before a
 * raw DataSet()/Execute(); bind values via $DB->field = ... (becomes
 * a :field placeholder) so nothing is concatenated unescaped.
 *************************************************************************/

class CmsPage extends CmsBase
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Fetch a single page row by slug within a scope.
     *
     * @param string $slug          page slug
     * @param string $scopeType     'global' | 'kingdom' | 'park'
     * @param int    $scopeId       scope owner id (0 for global)
     * @param bool   $publishedOnly when true, only status='published' matches
     * @return array|null associative page row, or null when not found
     */
    public function GetPageBySlug($slug, $scopeType = 'global', $scopeId = 0, $publishedOnly = true)
    {
        global $DB;

        $scopeType = $this->_normalizeScopeType($scopeType);

        $sql = 'SELECT * FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id';
        if ($publishedOnly) {
            $sql .= " AND status = 'published'";
        }
        $sql .= ' LIMIT 1';

        $DB->Clear();
        $DB->slug = (string)$slug;
        $DB->scope_type = $scopeType;
        $DB->scope_id = (int)$scopeId;
        $r = $DB->DataSet($sql);

        return $this->_firstRow($r);
    }

    /**
     * The system home page: is_system=1, slug='home', global scope, published.
     *
     * @return array|null associative page row, or null when not seeded/published
     */
    public function GetHomePage()
    {
        global $DB;

        $sql = 'SELECT * FROM ' . DB_PREFIX . 'cms_page'
            . " WHERE is_system = 1 AND slug = 'home'"
            . " AND scope_type = 'global' AND scope_id = 0"
            . " AND status = 'published' LIMIT 1";

        $DB->Clear();
        $r = $DB->DataSet($sql);

        return $this->_firstRow($r);
    }

    /**
     * Ordered, ENABLED-only blocks for an owner, shaped like the front-door
     * renderer expects. Disabled blocks are skipped.
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId   owner row id
     * @return array list of ['id','type','enabled','order','source','fields']
     */
    public function GetBlocks($ownerType, $ownerId)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';

        $sql = 'SELECT block_id, owner_type, owner_id, type, ordering, enabled, source, fields_json'
            . ' FROM ' . DB_PREFIX . 'cms_block'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id AND enabled = 1'
            . ' ORDER BY ordering ASC, block_id ASC';

        $DB->Clear();
        $DB->owner_type = $ownerType;
        $DB->owner_id = (int)$ownerId;
        $r = $DB->DataSet($sql);

        $blocks = array();
        foreach ($this->_eachRow($r) as $row) {
            $fields = array();
            if (isset($row['fields_json']) && $row['fields_json'] !== null && $row['fields_json'] !== '') {
                $decoded = json_decode($row['fields_json'], true);
                if (is_array($decoded)) {
                    $fields = $decoded;
                }
            }
            $blocks[] = array(
                'id'      => (int)$row['block_id'],
                'type'    => $row['type'],
                'enabled' => true,
                'order'   => (int)$row['ordering'],
                'source'  => $row['source'],
                'fields'  => $fields,
            );
        }
        return $blocks;
    }

    /**
     * Convenience: GetBlocks('page', $pageId).
     *
     * @param int $pageId
     * @return array
     */
    public function GetPageBlocks($pageId)
    {
        return $this->GetBlocks('page', $pageId);
    }

    /**
     * Insert a page row.
     *
     * @param array $data keyed subset of page columns (slug, type, title,
     *                    status, published_at, hero_media_id, meta_description,
     *                    is_system, scope_type, scope_id, created_by, ...)
     * @return int new page_id (0 on failure)
     */
    public function CreatePage($data)
    {
        global $DB;

        $now = date('Y-m-d H:i:s');

        $cols = array(
            'slug'             => isset($data['slug']) ? (string)$data['slug'] : '',
            'type'             => isset($data['type']) ? (string)$data['type'] : 'composed',
            'title'            => isset($data['title']) ? (string)$data['title'] : '',
            'status'           => isset($data['status']) ? (string)$data['status'] : 'draft',
            'published_at'     => isset($data['published_at']) ? $data['published_at'] : null,
            'hero_media_id'    => isset($data['hero_media_id']) ? $data['hero_media_id'] : null,
            'meta_description' => isset($data['meta_description']) ? $data['meta_description'] : null,
            'is_system'        => isset($data['is_system']) ? (int)$data['is_system'] : 0,
            'scope_type'       => $this->_normalizeScopeType(isset($data['scope_type']) ? $data['scope_type'] : 'global'),
            'scope_id'         => isset($data['scope_id']) ? (int)$data['scope_id'] : 0,
            'created_by'       => isset($data['created_by']) ? $data['created_by'] : null,
            'created_at'       => isset($data['created_at']) ? $data['created_at'] : $now,
            'updated_by'       => isset($data['updated_by']) ? $data['updated_by'] : (isset($data['created_by']) ? $data['created_by'] : null),
            'updated_at'       => isset($data['updated_at']) ? $data['updated_at'] : $now,
        );

        // If publishing without an explicit timestamp, stamp published_at.
        if ($cols['status'] === 'published' && empty($cols['published_at'])) {
            $cols['published_at'] = $now;
        }

        // Guard against duplicate (scope_type, scope_id, slug) BEFORE inserting.
        // lastInsertId() is unreliable on dup-key under ERRMODE_WARNING — a stale
        // id would let ReplaceBlocks silently overwrite a different page's blocks.
        $DB->Clear();
        $DB->slug       = $cols['slug'];
        $DB->scope_type = $cols['scope_type'];
        $DB->scope_id   = $cols['scope_id'];
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT page_id FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND slug = :slug LIMIT 1'
        ));
        if ($existing !== null) {
            return 0;   // slug already in use — signal collision to caller
        }

        $names = array_keys($cols);
        $placeholders = array();
        foreach ($names as $n) {
            $placeholders[] = ':' . $n;
        }
        $sql = 'INSERT INTO ' . DB_PREFIX . 'cms_page (`' . implode('`, `', $names) . '`)'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $DB->Clear();
        foreach ($cols as $field => $value) {
            $DB->$field = $value;
        }
        $DB->Execute($sql);

        // Read back by the unique tuple instead of trusting GetLastInsertId().
        $DB->Clear();
        $DB->slug       = $cols['slug'];
        $DB->scope_type = $cols['scope_type'];
        $DB->scope_id   = $cols['scope_id'];
        $row = $this->_firstRow($DB->DataSet(
            'SELECT page_id FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND slug = :slug LIMIT 1'
        ));

        return $row ? (int)$row['page_id'] : 0;
    }

    /**
     * Fetch a single page row by primary key (admin/editor surfaces — any
     * status, any scope). Returns the raw column map, or null when not found.
     *
     * @param int $pageId
     * @return array|null associative page row, or null
     */
    public function GetPage($pageId)
    {
        global $DB;

        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return null;
        }

        $DB->Clear();
        $DB->page_id = $pageId;
        return $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id LIMIT 1'
        ));
    }

    /**
     * Update an existing page's editable meta. Only the provided keys are
     * written (title, slug, type, meta_description, hero_media_id, status,
     * published_at, scope_type, scope_id, updated_by). updated_at is always
     * stamped. Returns true when a valid id was supplied and the UPDATE ran.
     *
     * @param int   $pageId
     * @param array $data subset of editable columns
     * @return bool
     */
    public function UpdatePage($pageId, $data)
    {
        global $DB;

        $pageId = (int)$pageId;
        if ($pageId <= 0 || !is_array($data)) {
            return false;
        }

        // Whitelist of editable columns + their normalizers.
        $set    = array();
        $DB->Clear();

        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $DB->title = (string)$data['title'];
        }
        if (array_key_exists('slug', $data)) {
            $set[] = 'slug = :slug';
            $DB->slug = (string)$data['slug'];
        }
        if (array_key_exists('type', $data)) {
            $set[] = 'type = :type';
            $DB->type = (string)$data['type'];
        }
        if (array_key_exists('meta_description', $data)) {
            $set[] = 'meta_description = :meta_description';
            $DB->meta_description = ($data['meta_description'] === null) ? null : (string)$data['meta_description'];
        }
        if (array_key_exists('hero_media_id', $data)) {
            $set[] = 'hero_media_id = :hero_media_id';
            $DB->hero_media_id = ($data['hero_media_id'] === null || $data['hero_media_id'] === '')
                ? null : (int)$data['hero_media_id'];
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

        // Always bump the updater + timestamp.
        $set[] = 'updated_at = :updated_at';
        $DB->updated_at = date('Y-m-d H:i:s');
        if (array_key_exists('updated_by', $data)) {
            $set[] = 'updated_by = :updated_by';
            $DB->updated_by = ($data['updated_by'] === null || $data['updated_by'] === '')
                ? null : (int)$data['updated_by'];
        }

        if (count($set) === 0) {
            return false;
        }

        $DB->page_id = $pageId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_page SET ' . implode(', ', $set)
            . ' WHERE page_id = :page_id'
        );

        return true;
    }

    /**
     * Set a page's publish status, stamping/clearing published_at. Publishing
     * stamps published_at (now) only when it is currently empty; unpublishing
     * leaves the historical stamp intact (so re-publish can preserve it).
     *
     * @param int    $pageId
     * @param string $status    'published' | 'draft'
     * @param int    $updatedBy mundane_id of the actor (0 to skip)
     * @return bool
     */
    public function SetStatus($pageId, $status, $updatedBy = 0)
    {
        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return false;
        }
        $status = ((string)$status === 'published') ? 'published' : 'draft';

        $data = array('status' => $status);
        if ((int)$updatedBy > 0) {
            $data['updated_by'] = (int)$updatedBy;
        }

        if ($status === 'published') {
            // Stamp published_at only if not already set.
            $row = $this->GetPage($pageId);
            if ($row !== null && empty($row['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
        }

        return $this->UpdatePage($pageId, $data);
    }

    /**
     * Delete a page and ALL of its blocks. Refuses to delete a system page
     * (is_system=1). Returns true when the page existed and was removed.
     *
     * @param int $pageId
     * @return bool
     */
    public function DeletePage($pageId)
    {
        global $DB;

        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return false;
        }

        $row = $this->GetPage($pageId);
        if ($row === null) {
            return false;
        }
        if (!empty($row['is_system'])) {
            // System pages (e.g. the home page) are protected.
            return false;
        }

        // Remove the page's blocks first, then the page row.
        $DB->Clear();
        $DB->owner_id = $pageId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_block'
            . " WHERE owner_type = 'page' AND owner_id = :owner_id"
        );

        $DB->Clear();
        $DB->page_id = $pageId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id'
        );

        return true;
    }

    /**
     * Replace ALL blocks for an owner: delete the existing set, then insert the
     * supplied ordered block array. Each block accepts the renderer shape
     * (type, enabled, order/ordering, source, fields) — fields is json_encoded.
     * Used by seeding/import.
     *
     * @param string $ownerType   'page' | 'post'
     * @param int    $ownerId     owner row id
     * @param array  $blocksArray ordered list of block definitions
     * @return int number of blocks inserted
     */
    public function ReplaceBlocks($ownerType, $ownerId, $blocksArray)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';
        $ownerId = (int)$ownerId;

        // Wrap DELETE + INSERT loop in a transaction so a mid-loop INSERT failure
        // cannot leave a partial block set (deleted old blocks, only some new ones
        // written). On failure ROLLBACK and return -1 to signal the partial write.
        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        // Wipe existing blocks for this owner.
        $DB->Clear();
        $DB->owner_type = $ownerType;
        $DB->owner_id = $ownerId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_block'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
        );

        if (!is_array($blocksArray) || count($blocksArray) === 0) {
            $DB->Clear();
            $DB->Execute('COMMIT');
            return 0;
        }

        $inserted = 0;
        $i = 0;
        foreach ($blocksArray as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = isset($block['type']) ? (string)$block['type'] : '';
            if ($type === '') {
                continue;
            }

            // Accept either 'order' (renderer shape) or 'ordering' (column name);
            // fall back to positional index so callers needn't number them.
            if (isset($block['ordering'])) {
                $ordering = (int)$block['ordering'];
            } elseif (isset($block['order'])) {
                $ordering = (int)$block['order'];
            } else {
                $ordering = $i * 10;
            }

            $enabled = isset($block['enabled']) ? (int)(bool)$block['enabled'] : 1;
            $source = isset($block['source']) ? (string)$block['source'] : 'authored';
            $source = ($source === 'dynamic') ? 'dynamic' : 'authored';

            $fields = isset($block['fields']) && is_array($block['fields']) ? $block['fields'] : array();
            $fieldsJson = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $DB->Clear();
            $DB->owner_type = $ownerType;
            $DB->owner_id = $ownerId;
            $DB->type = $type;
            $DB->ordering = $ordering;
            $DB->enabled = $enabled;
            $DB->source = $source;
            $DB->fields_json = $fieldsJson;
            $ok = $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_block'
                . ' (owner_type, owner_id, type, ordering, enabled, source, fields_json)'
                . ' VALUES (:owner_type, :owner_id, :type, :ordering, :enabled, :source, :fields_json)'
            );

            if ($ok === false) {
                // INSERT failed — undo everything and signal the caller.
                $DB->Clear();
                $DB->Execute('ROLLBACK');
                return -1;
            }

            $inserted++;
            $i++;
        }

        $DB->Clear();
        $DB->Execute('COMMIT');

        return $inserted;
    }

    /**
     * Lightweight page list for admin surfaces.
     *
     * @param array $filters optional: status, type, scope_type, scope_id, slug,
     *                       search (matches title/slug), limit
     * @return array list of ['page_id','slug','type','title','status','updated_at']
     */
    public function ListPages($filters = array())
    {
        global $DB;

        $where = array('1 = 1');

        $DB->Clear();

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $DB->status = (string)$filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'type = :type';
            $DB->type = (string)$filters['type'];
        }
        if (isset($filters['scope_type']) && $filters['scope_type'] !== '') {
            $where[] = 'scope_type = :scope_type';
            $DB->scope_type = $this->_normalizeScopeType($filters['scope_type']);
        }
        if (isset($filters['scope_id']) && $filters['scope_id'] !== '') {
            $where[] = 'scope_id = :scope_id';
            $DB->scope_id = (int)$filters['scope_id'];
        }
        if (!empty($filters['slug'])) {
            $where[] = 'slug = :slug';
            $DB->slug = (string)$filters['slug'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(title LIKE :search OR slug LIKE :search)';
            $DB->search = '%' . $filters['search'] . '%';
        }

        $limit = '';
        if (!empty($filters['limit'])) {
            // Code-controlled integer only; inlined since LIMIT can't be bound.
            $limit = ' LIMIT ' . (int)$filters['limit'];
        }

        $sql = 'SELECT page_id, slug, type, title, status, updated_at'
            . ' FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY updated_at DESC, page_id DESC'
            . $limit;

        $r = $DB->DataSet($sql);

        $out = array();
        foreach ($this->_eachRow($r) as $row) {
            $out[] = $row;
        }
        return $out;
    }

}
