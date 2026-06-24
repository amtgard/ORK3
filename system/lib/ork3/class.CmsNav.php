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
        $rows = $this->_fetchItems($menu, $scopeType, $scopeId, true);

        // Split into top-level + children-by-parent, preserving SQL ordering.
        $top = array();
        $childrenByParent = array();
        foreach ($rows as $row) {
            if ($row['parent_id'] === null || (int)$row['parent_id'] === 0) {
                $top[] = $this->_resolveItem($row);
            } else {
                $pid = (int)$row['parent_id'];
                if (!isset($childrenByParent[$pid])) {
                    $childrenByParent[$pid] = array();
                }
                $childrenByParent[$pid][] = $this->_resolveItem($row);
            }
        }

        // Attach children to their parents (orphans whose parent is disabled
        // or missing are simply dropped — they never appear at top level).
        foreach ($top as &$item) {
            $nid = (int)$item['nav_id'];
            $item['children'] = isset($childrenByParent[$nid]) ? $childrenByParent[$nid] : array();
        }
        unset($item);

        return $top;
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

        return (int)$DB->GetLastInsertId();
    }

    /**
     * Update an existing nav item. Only provided keys are written. Validates
     * link_type and clamps lengths. Returns true when a valid id was supplied
     * and an UPDATE ran.
     *
     * @param int   $navId
     * @param array $data subset of CreateItem keys
     * @return bool
     */
    public function UpdateItem($navId, $data)
    {
        global $DB;

        $navId = (int)$navId;
        if ($navId <= 0 || !is_array($data)) {
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
        if (array_key_exists('link_type', $data)) {
            $set[] = 'link_type = :link_type';
            $DB->link_type = $this->_normalizeLinkType($data['link_type']);
        }
        if (array_key_exists('page_id', $data)) {
            $set[] = 'page_id = :page_id';
            $DB->page_id = ($data['page_id'] === null || $data['page_id'] === '') ? null : (int)$data['page_id'];
        }
        if (array_key_exists('post_id', $data)) {
            $set[] = 'post_id = :post_id';
            $DB->post_id = ($data['post_id'] === null || $data['post_id'] === '') ? null : (int)$data['post_id'];
        }
        if (array_key_exists('url', $data)) {
            $set[] = 'url = :url';
            $DB->url = ($data['url'] === null || $data['url'] === '') ? null : $this->_clampUrl($data['url']);
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

        return true;
    }

    /**
     * Delete a nav item AND its direct children (a single dropdown level —
     * deleting a top-level item removes its whole dropdown). Returns true
     * when the item existed and was removed.
     *
     * @param int $navId
     * @return bool
     */
    public function DeleteItem($navId)
    {
        global $DB;

        $navId = (int)$navId;
        if ($navId <= 0) {
            return false;
        }

        // Confirm existence first (so a no-op delete reports false).
        $DB->Clear();
        $DB->nav_id = $navId;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT nav_id FROM ' . DB_PREFIX . 'cms_nav_item WHERE nav_id = :nav_id LIMIT 1'
        ));
        if ($existing === null) {
            return false;
        }

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
        $rows = $this->_fetchItems($menu, $scopeType, $scopeId, false);
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

        return true;
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
     * @return array list of raw rows (+ page_slug, post_slug, page_title, post_title)
     */
    private function _fetchItems($menu, $scopeType, $scopeId, $enabledOnly)
    {
        global $DB;

        $menu = $this->_clampMenu($menu);
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId = (int)$scopeId;

        $sql = 'SELECT n.*, pg.slug AS page_slug, pg.title AS page_title,'
            . ' po.slug AS post_slug, po.title AS post_title'
            . ' FROM ' . DB_PREFIX . 'cms_nav_item n'
            . ' LEFT JOIN ' . DB_PREFIX . 'cms_page pg ON pg.page_id = n.page_id'
            . ' LEFT JOIN ' . DB_PREFIX . 'cms_post po ON po.post_id = n.post_id'
            . ' WHERE n.menu = :menu AND n.scope_type = :scope_type AND n.scope_id = :scope_id';
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
