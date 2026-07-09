<?php

/*************************************************************************
 * CmsNav — editable-navigation store for the CMS.
 *
 * Reads/writes ork_cms_nav_item: ordered, optionally-nested (one level of
 * dropdown via parent_id) menu items keyed by a menu name (e.g.
 * 'marketing'). Each item links to a CMS page, a blog post, an external
 * URL, or a "dynamic" internal route key. Resolution to a renderable href
 * happens here so templates stay dumb (marketing_nav.tpl just reads
 * $item['href']/$item['label']/$item['children']).
 *
 * href resolution per link_type:
 *   page    -> UIR . 'Page/view/' . <page slug>   (joined from ork_cms_page)
 *   post    -> UIR . 'Blog/post/' . <post slug>    (joined from ork_cms_post)
 *   url     -> the stored url verbatim (external; target=_blank if off-site)
 *   dynamic -> the stored url field treated as an internal route key:
 *              an absolute/protocol url passes through; otherwise it is
 *              prefixed with UIR (e.g. 'Directory/index' -> UIR.'Directory/index').
 * A missing/broken link target resolves to '#'.
 *
 * DB idiom (matches CmsPage/CmsPost): shared global $DB (YapoDb). Always
 * Clear() before a raw DataSet()/Execute(); bind via $DB->field = ...
 * (becomes :field) so nothing is concatenated unescaped. Rows are driven
 * off Next() via _firstRow/_eachRow (Size() is unreliable on PDO).
 *************************************************************************/

class CmsNav extends CmsBase
{
    /** Column widths from ork_cms_nav_item (clamp authored input). */
    public const MENU_MAXLEN  = 40;
    public const LABEL_MAXLEN = 160;
    public const URL_MAXLEN   = 512;

    /** Allowed link_type enum values. */
    private static $LINK_TYPES = array('page', 'post', 'url', 'dynamic');

    /**
     * Per-request memo for GetMenu() results, keyed by "menu|scope_type|scope_id".
     * GetMenu is called on every front-door page render (marketing_nav.tpl), so
     * repeated calls in one request hit memory instead of re-querying. Cleared on
     * any same-request write so reads can't go stale. Per-request only (a static
     * resets naturally when the FPM worker finishes the request); no cross-request
     * cache.
     */
    private static $menuCache = array();

    public function __construct()
    {
        parent::__construct();
    }

    /* ====================================================================
     * READ
     * ================================================================== */

    /**
     * The ENABLED nav tree for a menu, resolved for rendering.
     *
     * Top-level items (parent_id IS NULL) ordered by ordering, each with a
     * 'children' => [ ...ordered enabled child items... ] list. Every item
     * carries a computed 'href' (see class docblock), plus label, link_type,
     * target ('_blank' for off-site url links, else ''), and nav_id.
     *
     * @param string $menu      menu name (e.g. 'marketing')
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @return array nested list of resolved top-level items
     */
    public function GetMenu($menu, $scopeType = 'global', $scopeId = 0)
    {
        // Per-request memo: this is called on every front-door page render, often
        // more than once per request. Key on the normalized scope so callers that
        // pass equivalent-but-unnormalized scope still share an entry.
        $menuName  = $this->_clampMenu($menu);
        $normScope = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;
        $cacheKey  = $menuName . '|' . $normScope . '|' . $scopeId;
        if (array_key_exists($cacheKey, self::$menuCache)) {
            return self::$menuCache[$cacheKey];
        }

        // C-perf: cross-request GhettoCache for the RESOLVED tree. The menu changes
        // only on an officer edit, yet was rebuilt (LEFT JOIN to page/post + PHP
        // tree assembly) on every anonymous public pageview of an org site. Cache
        // the built tree keyed by (menu, scope) + a content SIGNATURE of the scope's
        // nav rows. ork_cms_nav_item has no updated_at column, so the signature
        // (row count + MAX(nav_id) + SUM(CRC32(row fields))) IS the version signal:
        // any insert / delete / reorder / label / link-retarget / enable-toggle
        // changes it, so the key self-busts with no explicit invalidation. The
        // signature probe is a single indexed aggregate (one row, no joins), far
        // cheaper than the full fetch + resolve + tree build it lets us skip on hit.
        //
        // KNOWN EDGE: the signature covers nav-row fields only, NOT the JOINed
        // page/post SLUGS. A page/post slug RENAME (a separate CMS entity edit) can
        // leave a stale resolved href in this cache for up to the 1800s TTL. That is
        // a bounded, documented trade-off; a slug-edit-driven bust would live in the
        // page/post write path (a different owner) — see report.
        $ckey  = null;
        $cache = $this->_cache();
        if ($cache !== null) {
            $sig = $this->_navSignature($menuName, $normScope, $scopeId);
            // Skip caching entirely when the signature probe failed (null) so a
            // transient DB error can't pin an empty tree under a bogus key.
            if ($sig !== null) {
                $ckey   = $menuName . '.' . $normScope . '.' . $scopeId . '.' . $sig;
                $cached = $cache->get(__CLASS__ . '.GetMenu', $ckey, 1800);
                // Memcached returns the stored value (an array — possibly []) on a
                // hit, or false on a miss; is_array() distinguishes a genuinely
                // empty cached menu from a miss.
                if (is_array($cached)) {
                    self::$menuCache[$cacheKey] = $cached;
                    return $cached;
                }
            }
        }

        $rows = $this->_fetchItems($menu, $scopeType, $scopeId, true);

        // C22: build a TWO-level dropdown tree (top → child → grandchild), up from
        // the previous one-level-only split. Resolve every enabled row once, index
        // its children by parent id (0 = top level), then attach recursively with a
        // hard depth cap of 2 levels of nesting so a stray deep chain (or a parent
        // cycle) can never runaway-recurse. Orphans whose parent is disabled/missing
        // are dropped — they are simply never reached from the root.
        $resolved = array();          // nav_id => resolved item (with empty children)
        $childrenByParent = array();  // parent nav_id (0=top) => [child nav_id, ...]
        foreach ($rows as $row) {
            $navId = (int)$row['nav_id'];
            $item = $this->_resolveItem($row);
            $item['children'] = array();
            $resolved[$navId] = $item;
            $pid = ($row['parent_id'] === null) ? 0 : (int)$row['parent_id'];
            $childrenByParent[$pid][] = $navId;
        }

        // $depth 0 = top level; recurse into children while depth < 2 so a
        // grandchild (depth 2) is the deepest node that keeps its own children
        // pruned. SQL ordering is preserved because childrenByParent is filled in
        // row order.
        $attach = function ($parentId, $depth) use (&$attach, $resolved, $childrenByParent) {
            $out = array();
            if (empty($childrenByParent[$parentId])) {
                return $out;
            }
            foreach ($childrenByParent[$parentId] as $childId) {
                if (!isset($resolved[$childId])) {
                    continue;
                }
                $node = $resolved[$childId];
                $node['children'] = ($depth < 2) ? $attach($childId, $depth + 1) : array();
                $out[] = $node;
            }
            return $out;
        };
        $top = $attach(0, 0);

        // Store into the cross-request cache under the signature key computed above
        // (only when the probe succeeded → $ckey is set).
        if ($cache !== null && $ckey !== null) {
            $cache->cache(__CLASS__ . '.GetMenu', $ckey, $top);
        }

        self::$menuCache[$cacheKey] = $top;
        return $top;
    }

    /**
     * A content signature of a menu/scope's nav rows, used as the cross-request
     * cache version key (see GetMenu). Single indexed aggregate over
     * ork_cms_nav_item — no joins — returning "cnt-maxid-crcsum". Covers ALL rows
     * (enabled and disabled) so an enable/disable toggle also busts. Returns null
     * on a failed probe so the caller skips caching rather than key on garbage.
     *
     * @param string $menu      already-clamped menu name
     * @param string $scopeType already-normalized scope type
     * @param int    $scopeId
     * @return string|null
     */
    private function _navSignature($menu, $scopeType, $scopeId)
    {
        global $DB;

        $DB->Clear();
        $DB->menu       = $menu;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = (int)$scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT COUNT(*) AS cnt, COALESCE(MAX(nav_id), 0) AS mx,'
            . " COALESCE(SUM(CRC32(CONCAT_WS('|', nav_id, label, link_type,"
            . " IFNULL(page_id, 0), IFNULL(post_id, 0), IFNULL(url, ''),"
            . ' IFNULL(parent_id, 0), ordering, enabled))), 0) AS sig'
            . ' FROM ' . DB_PREFIX . 'cms_nav_item'
            . ' WHERE menu = :menu AND scope_type = :scope_type AND scope_id = :scope_id'
        ));
        if ($row === null) {
            return null;
        }
        return (string)(int)$row['cnt'] . '-'
            . (string)(int)$row['mx'] . '-'
            . (string)$row['sig'];
    }

    /** GhettoCache handle, or null when the memcache layer isn't wired up. */
    private function _cache()
    {
        if (isset(Ork3::$Lib) && is_object(Ork3::$Lib) && isset(Ork3::$Lib->ghettocache)
            && is_object(Ork3::$Lib->ghettocache)
        ) {
            return Ork3::$Lib->ghettocache;
        }
        return null;
    }

    /**
     * Flat list of items for a menu INCLUDING disabled rows (admin view),
     * ordered by parent grouping then ordering. Each row is decorated with a
     * resolved 'href', 'target', and a human 'target_label' describing the
     * link destination (page/post title or the raw url / route key).
     *
     * @param string $menu
     * @param string $scopeType
     * @param int    $scopeId
     * @return array flat list of resolved rows (incl. 'enabled', 'parent_id')
     */
    public function ListItems($menu, $scopeType = 'global', $scopeId = 0)
    {
        $rows = $this->_fetchItems($menu, $scopeType, $scopeId, false);

        $out = array();
        foreach ($rows as $row) {
            $item = $this->_resolveItem($row);
            $item['enabled']      = (int)$row['enabled'] === 1;
            $item['parent_id']    = ($row['parent_id'] === null) ? null : (int)$row['parent_id'];
            $item['ordering']     = (int)$row['ordering'];
            $item['page_id']      = ($row['page_id'] === null) ? null : (int)$row['page_id'];
            $item['post_id']      = ($row['post_id'] === null) ? null : (int)$row['post_id'];
            $item['url']          = ($row['url'] === null) ? '' : (string)$row['url'];
            $item['target_label'] = $this->_targetLabel($row);
            $out[] = $item;
        }
        return $out;
    }

    /* ====================================================================
     * WRITE
     * ================================================================== */

    /**
     * Insert a nav item. Validates link_type and clamps menu/label/url lengths.
     *
     * @param array $data menu, label, link_type, page_id, post_id, url,
     *                    parent_id, ordering, enabled, scope_type, scope_id
     * @return int new nav_id (0 on failure)
     */
    public function CreateItem($data)
    {
        global $DB;

        if (!is_array($data)) {
            return 0;
        }

        $cols = array(
            'menu'       => $this->_clampMenu(isset($data['menu']) ? $data['menu'] : 'marketing'),
            'label'      => $this->_clampLabel(isset($data['label']) ? $data['label'] : ''),
            'link_type'  => $this->_normalizeLinkType(isset($data['link_type']) ? $data['link_type'] : 'page'),
            'page_id'    => (isset($data['page_id']) && $data['page_id'] !== '' && $data['page_id'] !== null) ? (int)$data['page_id'] : null,
            'post_id'    => (isset($data['post_id']) && $data['post_id'] !== '' && $data['post_id'] !== null) ? (int)$data['post_id'] : null,
            'url'        => (isset($data['url']) && $data['url'] !== '' && $data['url'] !== null) ? $this->_clampUrl($data['url']) : null,
            'parent_id'  => (isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null && (int)$data['parent_id'] > 0) ? (int)$data['parent_id'] : null,
            'ordering'   => isset($data['ordering']) ? (int)$data['ordering'] : 0,
            'enabled'    => (isset($data['enabled']) && ((int)$data['enabled'] === 0 || $data['enabled'] === false)) ? 0 : 1,
            'scope_type' => $this->_normalizeScopeType(isset($data['scope_type']) ? $data['scope_type'] : 'global'),
            'scope_id'   => isset($data['scope_id']) ? (int)$data['scope_id'] : 0,
        );

        $names = array_keys($cols);
        $placeholders = array();
        foreach ($names as $n) {
            $placeholders[] = ':' . $n;
        }
        $sql = 'INSERT INTO ' . DB_PREFIX . 'cms_nav_item (`' . implode('`, `', $names) . '`)'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $DB->Clear();
        foreach ($cols as $field => $value) {
            $DB->$field = $value;
        }
        $DB->Execute($sql);

        // A same-request read must not serve a pre-write tree.
        self::$menuCache = array();

        // Verify the INSERT actually landed by reading the row back on the exact
        // tuple we just wrote — NOT GetLastInsertId(), which returns a stale prior
        // id on a silently-dropped/dup INSERT under ERRMODE_WARNING (lastInsertId
        // is not a reliable success/dup signal). cms_nav_item has no UNIQUE key on
        // the insert tuple, so match every column we wrote and take the newest
        // nav_id (AUTO_INCREMENT ⇒ our just-inserted row, or a concurrent identical
        // one — still a valid freshly-created id). Mirrors the read-back verify used
        // by DeleteItem/Reorder here and _insertRow in class.CmsMedia.php. Returns
        // 0 when nothing matched → the INSERT failed.
        $where = array();
        $DB->Clear();
        foreach ($cols as $field => $value) {
            if ($value === null) {
                $where[] = '`' . $field . '` IS NULL';
            } else {
                $where[] = '`' . $field . '` = :' . $field;
                $DB->$field = $value;
            }
        }
        $check = $this->_firstRow($DB->DataSet(
            'SELECT nav_id FROM ' . DB_PREFIX . 'cms_nav_item'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY nav_id DESC LIMIT 1'
        ));
        return ($check !== null && isset($check['nav_id'])) ? (int)$check['nav_id'] : 0;
    }

    /**
     * Update an existing nav item. Only provided keys are written. Validates
     * link_type and clamps lengths. Returns true when a valid id was supplied
     * and an UPDATE ran.
     *
     * IDOR guard: before mutating, the target row's stored scope_type/scope_id
     * must match the caller's intended scope. The intended scope is taken from
     * the explicit $scopeType/$scopeId params when given, else falls back to the
     * scope_type/scope_id carried in $data (how the CMS controller calls this).
     * When no intended scope can be determined (e.g. the data-migration callers
     * that pass neither) the check is skipped for backward compatibility.
     *
     * @param int        $navId
     * @param array      $data      subset of CreateItem keys
     * @param string|null $scopeType caller's intended scope_type (ownership guard)
     * @param int|null    $scopeId   caller's intended scope_id (ownership guard)
     * @return bool
     */
    public function UpdateItem($navId, $data, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $navId = (int)$navId;
        if ($navId <= 0 || !is_array($data)) {
            return false;
        }

        // Resolve the caller's intended scope (explicit params win, else $data).
        if ($scopeType === null && isset($data['scope_type'])) {
            $scopeType = $data['scope_type'];
        }
        if ($scopeId === null && isset($data['scope_id'])) {
            $scopeId = $data['scope_id'];
        }
        // When an intended scope is known, confirm the existing row belongs to it
        // before mutating — reject cross-scope writes (IDOR).
        if ($scopeType !== null && !$this->_ownsItem($navId, $scopeType, $scopeId)) {
            return false;
        }

        $set = array();
        $DB->Clear();

        if (array_key_exists('menu', $data)) {
            $set[] = 'menu = :menu';
            $DB->menu = $this->_clampMenu($data['menu']);
        }
        if (array_key_exists('label', $data)) {
            $set[] = 'label = :label';
            $DB->label = $this->_clampLabel($data['label']);
        }
        // When the link TYPE is (re)set, the target columns the OTHER types use
        // become dead. This method is AUTHORITATIVE about clearing them: yapo drops
        // null from an UPDATE, so the controller cannot null them by assignment —
        // instead we append raw `col = NULL` clauses (constant literal, no bind, no
        // injection) for every link column the new type does not use, in the SAME
        // UPDATE. A stale value in an unused column is overwritten, and a caller no
        // longer has to clear them manually (Agent C: savenavitem can stop hacking
        // nulls). Columns CLEARED per link_type (the used column is kept):
        //   page    -> clears post_id, url      (uses page_id)
        //   post    -> clears page_id, url      (uses post_id)
        //   url     -> clears page_id, post_id  (uses url)
        //   dynamic -> clears page_id, post_id  (uses url as the route key)
        // Clearing only fires when link_type is in $data; a partial update that
        // doesn't touch the type leaves all target columns alone.
        $clearCols = array();
        if (array_key_exists('link_type', $data)) {
            $newLinkType = $this->_normalizeLinkType($data['link_type']);
            $set[] = 'link_type = :link_type';
            $DB->link_type = $newLinkType;
            switch ($newLinkType) {
                case 'page':
                    $clearCols = array('post_id', 'url');
                    break;
                case 'post':
                    $clearCols = array('page_id', 'url');
                    break;
                case 'url':
                case 'dynamic':
                    $clearCols = array('page_id', 'post_id');
                    break;
            }
        }
        // Skip a $data-driven SET for any column we are about to force to NULL —
        // the clear (below) is authoritative and must win over a stale posted value.
        if (array_key_exists('page_id', $data) && !in_array('page_id', $clearCols, true)) {
            $set[] = 'page_id = :page_id';
            $DB->page_id = ($data['page_id'] === null || $data['page_id'] === '') ? null : (int)$data['page_id'];
        }
        if (array_key_exists('post_id', $data) && !in_array('post_id', $clearCols, true)) {
            $set[] = 'post_id = :post_id';
            $DB->post_id = ($data['post_id'] === null || $data['post_id'] === '') ? null : (int)$data['post_id'];
        }
        if (array_key_exists('url', $data) && !in_array('url', $clearCols, true)) {
            $set[] = 'url = :url';
            $DB->url = ($data['url'] === null || $data['url'] === '') ? null : $this->_clampUrl($data['url']);
        }
        // Force the now-unused link columns to NULL (yapo can't; raw literal does).
        foreach ($clearCols as $clearCol) {
            $set[] = '`' . $clearCol . '` = NULL';
        }
        if (array_key_exists('parent_id', $data)) {
            $set[] = 'parent_id = :parent_id';
            $pid = ($data['parent_id'] === null || $data['parent_id'] === '' || (int)$data['parent_id'] <= 0)
                ? null : (int)$data['parent_id'];
            // Guard against self-parenting (would orphan the item from GetMenu).
            if ($pid === $navId) {
                $pid = null;
            }
            $DB->parent_id = $pid;
        }
        if (array_key_exists('ordering', $data)) {
            $set[] = 'ordering = :ordering';
            $DB->ordering = (int)$data['ordering'];
        }
        if (array_key_exists('enabled', $data)) {
            $set[] = 'enabled = :enabled';
            $DB->enabled = ((int)$data['enabled'] === 0 || $data['enabled'] === false) ? 0 : 1;
        }
        if (array_key_exists('scope_type', $data)) {
            $set[] = 'scope_type = :scope_type';
            $DB->scope_type = $this->_normalizeScopeType($data['scope_type']);
        }
        if (array_key_exists('scope_id', $data)) {
            $set[] = 'scope_id = :scope_id';
            $DB->scope_id = (int)$data['scope_id'];
        }

        if (count($set) === 0) {
            return false;
        }

        $DB->nav_id = $navId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_nav_item SET ' . implode(', ', $set)
            . ' WHERE nav_id = :nav_id'
        );

        // A same-request read must not serve a pre-write tree.
        self::$menuCache = array();

        return true;
    }

    /**
     * Delete a nav item AND its direct children (a single dropdown level —
     * deleting a top-level item removes its whole dropdown). Returns true
     * when the item existed and was removed.
     *
     * IDOR guard: when an intended scope is supplied via $scopeType/$scopeId the
     * target row's stored scope must match it, else the delete is rejected. When
     * no scope is supplied the check is skipped (backward compatibility).
     *
     * @param int         $navId
     * @param string|null $scopeType caller's intended scope_type (ownership guard)
     * @param int|null    $scopeId   caller's intended scope_id (ownership guard)
     * @return bool
     */
    public function DeleteItem($navId, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $navId = (int)$navId;
        if ($navId <= 0) {
            return false;
        }

        // Confirm existence first (so a no-op delete reports false). Read the
        // scope too so we can enforce the ownership guard in the same lookup.
        $DB->Clear();
        $DB->nav_id = $navId;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT nav_id, scope_type, scope_id FROM ' . DB_PREFIX . 'cms_nav_item'
            . ' WHERE nav_id = :nav_id LIMIT 1'
        ));
        if ($existing === null) {
            return false;
        }

        // Reject cross-scope deletes (IDOR) when an intended scope is supplied.
        if ($scopeType !== null) {
            $wantType = $this->_normalizeScopeType($scopeType);
            $wantId   = (int)$scopeId;
            if ((string)$existing['scope_type'] !== $wantType || (int)$existing['scope_id'] !== $wantId) {
                return false;
            }
        }

        // Wrap the two DELETEs in one transaction so a failure between them can't
        // leave a parent removed with orphaned children (or vice versa). Mirrors
        // the ReplaceBlocks transaction pattern in class.CmsPage.php.
        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        // Delete children first (one dropdown level).
        $DB->Clear();
        $DB->parent_id = $navId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_nav_item WHERE parent_id = :parent_id'
        );

        // Then the item itself.
        $DB->Clear();
        $DB->nav_id = $navId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_nav_item WHERE nav_id = :nav_id'
        );

        // Verify the row is gone within the transaction before committing; a
        // surviving row means the DELETE was silently dropped → ROLLBACK.
        $DB->Clear();
        $DB->nav_id = $navId;
        $stillThere = $this->_firstRow($DB->DataSet(
            'SELECT nav_id FROM ' . DB_PREFIX . 'cms_nav_item WHERE nav_id = :nav_id LIMIT 1'
        ));

        $DB->Clear();
        if ($stillThere !== null) {
            $DB->Execute('ROLLBACK');
            return false;
        }
        $DB->Execute('COMMIT');

        // A same-request read must not serve a pre-delete tree.
        self::$menuCache = array();

        return true;
    }

    /**
     * Apply a new ordering + parent layout for a menu from an ordered list.
     * Each entry is ['nav_id'=>int, 'parent_id'=>int|null, 'ordering'=>int]
     * (ordering may be omitted — list index is used as a fallback). Only rows
     * whose nav_id actually belongs to the given menu/scope are touched.
     *
     * @param string $menu
     * @param array  $orderedItems  list of {nav_id, parent_id, ordering}
     * @param string $scopeType
     * @param int    $scopeId
     * @return bool
     */
    public function Reorder($menu, array $orderedItems, $scopeType = 'global', $scopeId = 0)
    {
        global $DB;

        $menu = $this->_clampMenu($menu);
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId = (int)$scopeId;

        // Build the set of nav_ids that legitimately belong to this menu/scope.
        $valid = array();
        // Only nav_id is consumed here, so skip the page/post LEFT JOINs.
        $rows = $this->_fetchItems($menu, $scopeType, $scopeId, false, true);
        foreach ($rows as $row) {
            $valid[(int)$row['nav_id']] = true;
        }
        if (empty($valid)) {
            return false;
        }

        // Wrap the per-item UPDATEs in one transaction. These are N separate
        // round-trips (one Clear+prepare+execute per item), NOT a single pipelined
        // statement — the transaction only makes them atomic. Execute() returns
        // void and the PDO driver runs in ERRMODE_WARNING (internal retry, never
        // throws/aborts the transaction), so failure can't be seen from a return
        // value. After the loop we read the rows back inside the transaction and
        // verify each landed exactly as intended; COMMIT only on a full match.
        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        $idx = 0;
        $intended = array();
        foreach ($orderedItems as $entry) {
            if (!is_array($entry) || !isset($entry['nav_id'])) {
                $idx++;
                continue;
            }
            $navId = (int)$entry['nav_id'];
            if ($navId <= 0 || !isset($valid[$navId])) {
                $idx++;
                continue;
            }

            $ordering = isset($entry['ordering']) ? (int)$entry['ordering'] : $idx;
            $parentId = (isset($entry['parent_id']) && $entry['parent_id'] !== '' && $entry['parent_id'] !== null && (int)$entry['parent_id'] > 0)
                ? (int)$entry['parent_id'] : null;
            // Never let an item parent itself, and only allow parents that are
            // themselves valid members of this menu/scope.
            if ($parentId !== null && ($parentId === $navId || !isset($valid[$parentId]))) {
                $parentId = null;
            }

            $DB->Clear();
            $DB->ordering = $ordering;
            $DB->parent_id = $parentId;
            $DB->nav_id = $navId;
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'cms_nav_item'
                . ' SET ordering = :ordering, parent_id = :parent_id'
                . ' WHERE nav_id = :nav_id'
            );

            // Record what we meant to write so we can verify it post-write.
            $intended[$navId] = array('ordering' => $ordering, 'parent_id' => $parentId);

            $idx++;
        }

        // Verify the writes inside the transaction (this connection sees its own
        // uncommitted changes). Read every intended row back in one IN() query and
        // confirm ordering + parent_id match. A missing or mismatched row means an
        // UPDATE was silently dropped → ROLLBACK instead of committing corruption.
        $ok = true;
        if (!empty($intended)) {
            $navIds = array_keys($intended);
            $DB->Clear();
            $idList = implode(',', array_map('intval', $navIds));
            $rows = $this->_eachRow($DB->DataSet(
                'SELECT nav_id, ordering, parent_id FROM ' . DB_PREFIX . 'cms_nav_item'
                . ' WHERE nav_id IN (' . $idList . ')'
            ));

            $seen = array();
            foreach ($rows as $row) {
                $rid = (int)$row['nav_id'];
                $seen[$rid] = true;
                $want = $intended[$rid];
                // parent_id is a nullable column; intended value may be PHP null.
                // Normalize both sides to "null OR int" before comparing so a NULL
                // column never spuriously equals an int (and 0 is treated as null,
                // matching how the intended value is derived above).
                $actualParent = ($row['parent_id'] === null || $row['parent_id'] === '') ? null : (int)$row['parent_id'];
                $wantParent = ($want['parent_id'] === null) ? null : (int)$want['parent_id'];
                if ((int)$row['ordering'] !== (int)$want['ordering'] || $actualParent !== $wantParent) {
                    $ok = false;
                    break;
                }
            }
            // Any intended row that didn't come back is a dropped write.
            if ($ok && count($seen) !== count($intended)) {
                $ok = false;
            }
        }

        $DB->Clear();
        if ($ok) {
            $DB->Execute('COMMIT');
        } else {
            $DB->Execute('ROLLBACK');
            return false;
        }

        // A same-request read must not serve the pre-reorder tree.
        self::$menuCache = array();

        return true;
    }

    /**
     * True when nav row $navId exists AND its stored scope_type/scope_id match
     * the supplied intended scope. Used as the IDOR ownership guard before a
     * mutating write. Follows the _firstRow read pattern used elsewhere here.
     *
     * @param int    $navId
     * @param string $scopeType intended scope_type (normalized internally)
     * @param int    $scopeId   intended scope_id
     * @return bool
     */
    private function _ownsItem($navId, $scopeType, $scopeId)
    {
        global $DB;

        $navId = (int)$navId;
        if ($navId <= 0) {
            return false;
        }
        $wantType = $this->_normalizeScopeType($scopeType);
        $wantId   = (int)$scopeId;

        $DB->Clear();
        $DB->nav_id = $navId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_nav_item'
            . ' WHERE nav_id = :nav_id LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }

        return (string)$row['scope_type'] === $wantType && (int)$row['scope_id'] === $wantId;
    }

    /* ====================================================================
     * INTERNAL — fetch + resolve
     * ================================================================== */

    /**
     * Fetch raw nav rows for a menu/scope joined to page/post slugs+titles,
     * ordered for tree assembly (top-level grouped first via parent_id IS NULL,
     * then by ordering, then nav_id for stability).
     *
     * @param string $menu
     * @param string $scopeType
     * @param int    $scopeId
     * @param bool   $enabledOnly when true, only enabled=1 rows
     * @param bool   $idsOnly     when true, SELECT only n.nav_id and skip the
     *                            page/post joins (callers that just need the
     *                            id set, e.g. Reorder()'s validity check)
     * @return array list of raw rows (+ page_slug, post_slug, page_title, post_title)
     */
    private function _fetchItems($menu, $scopeType, $scopeId, $enabledOnly, $idsOnly = false)
    {
        global $DB;

        $menu = $this->_clampMenu($menu);
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId = (int)$scopeId;

        if ($idsOnly) {
            $sql = 'SELECT n.nav_id'
                . ' FROM ' . DB_PREFIX . 'cms_nav_item n'
                . ' WHERE n.menu = :menu AND n.scope_type = :scope_type AND n.scope_id = :scope_id';
        } else {
            $sql = 'SELECT n.*, pg.slug AS page_slug, pg.title AS page_title,'
                . ' po.slug AS post_slug, po.title AS post_title'
                . ' FROM ' . DB_PREFIX . 'cms_nav_item n'
                // Scope-bound joins: a nav item can only resolve a page/post in
                // its OWN scope, so a cross-scope target id (should one ever be
                // stored) can never leak another org's title/slug into this list.
                . ' LEFT JOIN ' . DB_PREFIX . 'cms_page pg ON pg.page_id = n.page_id'
                . ' AND pg.scope_type = n.scope_type AND pg.scope_id = n.scope_id'
                . ' LEFT JOIN ' . DB_PREFIX . 'cms_post po ON po.post_id = n.post_id'
                . ' AND po.scope_type = n.scope_type AND po.scope_id = n.scope_id'
                . ' WHERE n.menu = :menu AND n.scope_type = :scope_type AND n.scope_id = :scope_id';
        }
        if ($enabledOnly) {
            $sql .= ' AND n.enabled = 1';
        }
        $sql .= ' ORDER BY (n.parent_id IS NOT NULL), n.parent_id, n.ordering, n.nav_id';

        $DB->Clear();
        $DB->menu = $menu;
        $DB->scope_type = $scopeType;
        $DB->scope_id = $scopeId;

        return $this->_eachRow($DB->DataSet($sql));
    }

    /**
     * Resolve a raw row into the renderer shape: nav_id, label, link_type,
     * href, target.
     *
     * @param array $row raw nav row (+ joined slugs)
     * @return array
     */
    private function _resolveItem($row)
    {
        $linkType = $this->_normalizeLinkType(isset($row['link_type']) ? $row['link_type'] : 'page');
        $href = $this->_resolveHref($linkType, $row);
        $target = '';
        if ($linkType === 'url' && $this->_isExternal(isset($row['url']) ? $row['url'] : '')) {
            $target = '_blank';
        }

        return array(
            'nav_id'    => (int)$row['nav_id'],
            'label'     => (string)$row['label'],
            'link_type' => $linkType,
            'href'      => $href,
            'target'    => $target,
        );
    }

    /**
     * Compute the renderable href for a row given its (normalized) link_type.
     * See class docblock for the per-type rules. Broken/missing targets => '#'.
     *
     * @param string $linkType
     * @param array  $row
     * @return string
     */
    private function _resolveHref($linkType, $row)
    {
        $uir = $this->_uir();

        switch ($linkType) {
            case 'page':
                $slug = isset($row['page_slug']) ? (string)$row['page_slug'] : '';
                return ($slug !== '') ? $uir . 'Page/view/' . rawurlencode($slug) : '#';

            case 'post':
                $slug = isset($row['post_slug']) ? (string)$row['post_slug'] : '';
                return ($slug !== '') ? $uir . 'Blog/post/' . rawurlencode($slug) : '#';

            case 'url':
                $url = isset($row['url']) ? trim((string)$row['url']) : '';
                // Reject javascript:/data:/protocol-relative etc. at render
                // time so a stored hostile URL never reaches a visitor.
                if ($url === '' || !CmsSanitizer::IsSafeUrl($url)) {
                    return '#';
                }
                return $url;

            case 'dynamic':
                // Stored url field is an internal route key (e.g. 'Directory/index').
                // An absolute/protocol url passes through; otherwise prefix UIR.
                $route = isset($row['url']) ? trim((string)$row['url']) : '';
                if ($route === '') {
                    return '#';
                }
                if ($this->_isExternal($route) || strpos($route, 'index.php?Route=') !== false) {
                    // Apply the same trust-boundary check the 'url' case uses so
                    // a stored hostile scheme (javascript:/data:/...) can't reach
                    // a visitor through the 'dynamic' type.
                    if (!CmsSanitizer::IsSafeUrl($route)) {
                        return '#';
                    }
                    return $route;
                }
                return $uir . ltrim($route, '/');
        }

        return '#';
    }

    /**
     * A human-readable description of an item's link target (admin list).
     *
     * @param array $row raw row
     * @return string
     */
    private function _targetLabel($row)
    {
        $linkType = $this->_normalizeLinkType(isset($row['link_type']) ? $row['link_type'] : 'page');
        switch ($linkType) {
            case 'page':
                $t = isset($row['page_title']) ? trim((string)$row['page_title']) : '';
                if ($t !== '') {
                    return $t;
                }
                $s = isset($row['page_slug']) ? (string)$row['page_slug'] : '';
                return ($s !== '') ? $s : '(missing page)';
            case 'post':
                $t = isset($row['post_title']) ? trim((string)$row['post_title']) : '';
                if ($t !== '') {
                    return $t;
                }
                $s = isset($row['post_slug']) ? (string)$row['post_slug'] : '';
                return ($s !== '') ? $s : '(missing post)';
            case 'url':
            case 'dynamic':
                $u = isset($row['url']) ? trim((string)$row['url']) : '';
                return ($u !== '') ? $u : '(no link)';
        }
        return '';
    }

    /* ====================================================================
     * INTERNAL — validation / helpers
     * ================================================================== */

    /**
     * The UI route base ('.../index.php?Route='). Uses the UIR constant when
     * defined (every orkui request defines it); otherwise falls back to a
     * relative base so non-UI callers (e.g. SOAP) still get a usable value.
     */
    private function _uir()
    {
        if (defined('UIR')) {
            return UIR;
        }
        if (defined('HTTP_UI_REMOTE')) {
            return HTTP_UI_REMOTE . 'index.php?Route=';
        }
        return 'index.php?Route=';
    }

    /**
     * True when a url is off-site (has a scheme / is protocol-relative).
     */
    private function _isExternal($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return false;
        }
        if (substr($url, 0, 2) === '//') {
            return true;
        }
        return (bool)preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)
            || stripos($url, 'mailto:') === 0
            || stripos($url, 'tel:') === 0;
    }

    /** Clamp link_type to the supported enum (default 'page'). */
    private function _normalizeLinkType($linkType)
    {
        $linkType = strtolower(trim((string)$linkType));
        return in_array($linkType, self::$LINK_TYPES, true) ? $linkType : 'page';
    }

    /** Clamp the menu name to its column width (default 'marketing'). */
    private function _clampMenu($menu)
    {
        $menu = trim((string)$menu);
        if ($menu === '') {
            $menu = 'marketing';
        }
        if (strlen($menu) > self::MENU_MAXLEN) {
            $menu = substr($menu, 0, self::MENU_MAXLEN);
        }
        return $menu;
    }

    /** Clamp a label to its column width. */
    private function _clampLabel($label)
    {
        $label = (string)$label;
        if (strlen($label) > self::LABEL_MAXLEN) {
            $label = substr($label, 0, self::LABEL_MAXLEN);
        }
        return $label;
    }

    /** Clamp a url/route to its column width. */
    private function _clampUrl($url)
    {
        $url = (string)$url;
        if (strlen($url) > self::URL_MAXLEN) {
            $url = substr($url, 0, self::URL_MAXLEN);
        }
        return $url;
    }

}
