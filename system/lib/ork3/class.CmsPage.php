<?php

// ReplaceBlocks is the authoritative sanitize choke point (see C3), so the
// sanitizer must be loadable from the lib layer even when no controller has
// require'd it. Idempotent.
require_once __DIR__ . '/class.CmsSanitizer.php';

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
    /**
     * Block field keys whose values hold authored rich-text/HTML and MUST be
     * run through CmsSanitizer::Clean before storage. Kept in lockstep with
     * Controller_CmsAjax::$HTML_FIELDS — sanitizing HERE (the choke point every
     * writer passes through) is the authoritative defense; the controller copy
     * is redundant belt-and-suspenders.
     */
    private static $HTML_FIELDS = array('body', 'html');

    /** Block field keys holding a URL → must pass URL-scheme validation on save. */
    private static $URL_FIELDS = array('href', 'more_href', 'url', 'link', 'cta_href', 'button_href', 'src');

    /** Max revision snapshots retained per owner (older ones pruned on write). */
    private static $MAX_REVISIONS = 25;

    /**
     * C17: page slugs that would be shadowed by the pretty-URL router. nginx
     * routes /k/{site}/blog and /k/{site}/post/{x} (and the /k, /p prefixes)
     * BEFORE the generic /k/{site}/{pageSlug} rewrite, so a PAGE slugged with any
     * of these can never be reached. Rejected at every write path (CreatePage +
     * the controller savepage guard). Compared case-insensitively; applies to the
     * FIRST path segment (a top-level page slug), which is what the router sees.
     */
    private static $RESERVED_PAGE_SLUGS = array('blog', 'post', 'p', 'k');

    /**
     * Per-request memo of the ancestor chain, keyed by page_id (C13). PagePath()
     * + GetPageByPath() re-walk the parent chain per render; this collapses the
     * repeats. Mirrors the static-memo pattern used for table-existence probes.
     * Invalidated whenever a parent link changes (UpdatePage/DeletePage).
     */
    private static $_ancestorMemo = array();

    /**
     * C17: is $slug a reserved top-level page slug (would be unreachable behind
     * the pretty-URL router)? Public so the controller savepage path can surface
     * a friendly inline error before attempting the write.
     *
     * @param string $slug
     * @return bool
     */
    public function IsReservedPageSlug($slug)
    {
        $slug = strtolower(trim((string)$slug));
        return in_array($slug, self::$RESERVED_PAGE_SLUGS, true);
    }

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

        // C7: flip any due scheduled rows to published before the read gate.
        if ($publishedOnly) {
            $this->_promoteScheduled();
        }

        $sql = 'SELECT * FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id'
            . ' AND deleted_at IS NULL';   // C2: never serve a trashed page
        if ($publishedOnly) {
            // C7: a published row is only live once its (optional) schedule time
            // has passed; a NULL published_at means "live immediately".
            $sql .= " AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())";
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

        // C7: flip any due scheduled rows to published before the read gate.
        $this->_promoteScheduled();

        $sql = 'SELECT * FROM ' . DB_PREFIX . 'cms_page'
            . " WHERE is_system = 1 AND slug = 'home'"
            . " AND scope_type = 'global' AND scope_id = 0"
            . ' AND deleted_at IS NULL'   // C2: never serve a trashed home page
            . " AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())"
            . ' LIMIT 1';

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
                'enabled' => true, // always true: query filters enabled = 1
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
        $now = date('Y-m-d H:i:s');

        $cols = array(
            // Shared canonical derivation (CmsBase::_normalizeSlug): 'My Page' ->
            // 'my-page'. Previously stripped non-alphanumerics to nothing
            // ('mypage'); now hyphenated to match CmsSite and produce readable
            // slugs. Only affects slugs DERIVED here for new pages — stored slugs
            // are untouched, and the reserved-slug guard below still applies.
            'slug'             => $this->_normalizeSlug(isset($data['slug']) ? $data['slug'] : ''),
            // NOTE: 'type' is AUTHOR-FACING editor metadata, not a render input.
            // It records which editor preset a page was created from (see
            // Controller_Cms::_pageTypes — 'composed'|'article'|'media'|'about'|
            // 'resource'|'blog_index') and labels the admin list's Type column. The
            // PUBLIC renderer (frontdoor/render_blocks.tpl) is driven entirely by
            // per-BLOCK type + the site meta og_type literal, and never reads this
            // column. Kept for editor/admin ergonomics; intentionally inert at
            // render time. If a future need arises to drive layout/meta from it,
            // do it in the Site render path, not here.
            'type'             => isset($data['type']) ? (string)$data['type'] : 'composed',
            'title'            => isset($data['title']) ? (string)$data['title'] : '',
            'status'           => isset($data['status']) ? (string)$data['status'] : 'draft',
            'published_at'     => isset($data['published_at']) ? $data['published_at'] : null,
            'hero_media_id'    => isset($data['hero_media_id']) ? $data['hero_media_id'] : null,
            'meta_description' => isset($data['meta_description']) ? $data['meta_description'] : null,
            'is_system'        => isset($data['is_system']) ? (int)$data['is_system'] : 0,
            'scope_type'       => $this->_normalizeScopeType(isset($data['scope_type']) ? $data['scope_type'] : 'global'),
            'scope_id'         => isset($data['scope_id']) ? (int)$data['scope_id'] : 0,
            // C13: optional page hierarchy parent (nullable → flat/top-level).
            'parent_id'        => (isset($data['parent_id']) && (int)$data['parent_id'] > 0) ? (int)$data['parent_id'] : null,
            'created_by'       => isset($data['created_by']) ? $data['created_by'] : null,
            'created_at'       => isset($data['created_at']) ? $data['created_at'] : $now,
            'updated_by'       => isset($data['updated_by']) ? $data['updated_by'] : (isset($data['created_by']) ? $data['created_by'] : null),
            'updated_at'       => isset($data['updated_at']) ? $data['updated_at'] : $now,
        );

        // If publishing without an explicit timestamp, stamp published_at.
        if ($cols['status'] === 'published' && empty($cols['published_at'])) {
            $cols['published_at'] = $now;
        }

        // C17: refuse a router-shadowed slug (blog/post/k/p) — such a page would
        // be permanently unreachable behind the pretty-URL rewrites. Signalled as
        // a collision (0) so the caller's "slug in use" path handles it; the
        // controller pre-checks IsReservedPageSlug for a specific message.
        if ($this->IsReservedPageSlug($cols['slug'])) {
            return 0;
        }

        // Shared dup-guarded insert (C29 + live-slug reuse). The dup pre-check,
        // INSERT IGNORE, ROW_COUNT() race arbitration and authoritative
        // read-back-by-live-tuple all live in CmsBase::_insertWithDupGuard so
        // CreatePage/CreatePost stay in lockstep.
        return $this->_insertWithDupGuard('cms_page', 'page_id', $cols);
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
        // C2: a trashed page is invisible to editor/publish/delete surfaces.
        // Restore reads the trashed row directly (see RestorePage()).
        return $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id AND deleted_at IS NULL LIMIT 1'
        ));
    }

    /**
     * Update an existing page's editable meta. Only the provided keys are
     * written (title, slug, type, meta_description, hero_media_id, status,
     * published_at, scope_type, scope_id, updated_by). updated_at is always
     * stamped. Returns true when a valid id was supplied and the UPDATE ran.
     *
     * @param int         $pageId
     * @param array       $data      subset of editable columns
     * @param string|null $scopeType IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId   IDOR guard: caller's intended scope_id
     * @return bool
     */
    public function UpdatePage($pageId, $data, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $pageId = (int)$pageId;
        if ($pageId <= 0 || !is_array($data)) {
            return false;
        }

        // IDOR guard (opt-in, mirrors DeletePage/RestorePage): when the caller
        // supplies its intended scope, refuse to touch a page in a different org
        // AND refuse to relocate this page OUT of the guarded scope. Runs its own
        // Clear()/DataSet(), so it must precede the bind loop below.
        if ($scopeType !== null) {
            $wantType = $this->_normalizeScopeType($scopeType);
            $DB->Clear();
            $DB->page_id = $pageId;
            $cur = $this->_firstRow($DB->DataSet(
                'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id LIMIT 1'
            ));
            if (
                $cur === null
                || (string)$cur['scope_type'] !== $wantType
                || (int)$cur['scope_id'] !== (int)$scopeId
            ) {
                return false;
            }
            if (array_key_exists('scope_type', $data) && $this->_normalizeScopeType($data['scope_type']) !== $wantType) {
                return false;
            }
            if (array_key_exists('scope_id', $data) && (int)$data['scope_id'] !== (int)$scopeId) {
                return false;
            }
        }

        // C17: if the slug is changing, read the pre-edit row AND its current full
        // path up front (before any binds accumulate on $DB) so we can record a
        // 301 from the OLD path after the write. Both PagePath() and GetPage() run
        // their own Clear()/DataSet(), so they MUST precede the bind loop below or
        // they would wipe the accumulated placeholders.
        $preEditRow  = null;
        $preEditPath = '';
        $newSlug     = null; // normalized target slug, computed up front when set
        if (array_key_exists('slug', $data)) {
            $preEditRow  = $this->GetPage($pageId);
            $preEditPath = $this->PagePath($pageId);

            // Normalize the incoming slug here (before any binds accumulate on
            // $DB) so we can dup-check it up front — the check runs its own
            // Clear()/DataSet() and must precede the bind loop below. Uses the
            // shared canonical derivation (hyphenated, matching CreatePage/CmsSite);
            // _normalizeSlug is idempotent on an already-valid slug, so re-saving a
            // page without changing its slug is a no-op (no spurious rename/redirect).
            $newSlug = $this->_normalizeSlug($data['slug']);

            // Dup pre-check (mirrors CreatePage): a genuine rename to a slug
            // already claimed by ANOTHER LIVE page in the same target scope is a
            // collision — refuse rather than let the UPDATE silently drop against
            // the uq_page_scope_slug_live key. Trashed rows (deleted_at NOT NULL)
            // have slug_live=NULL and so free the slug for reuse — they must NOT
            // block a rename. Empty/reserved slugs are handled below (existing
            // slug kept), so they skip the check.
            if (
                $newSlug !== '' && !$this->IsReservedPageSlug($newSlug)
                && $preEditRow !== null && $newSlug !== (string)$preEditRow['slug']
            ) {
                // Effective target scope: an in-flight scope change wins, else the
                // page's current scope.
                $chkType = array_key_exists('scope_type', $data)
                    ? $this->_normalizeScopeType($data['scope_type'])
                    : (string)$preEditRow['scope_type'];
                $chkId = array_key_exists('scope_id', $data)
                    ? (int)$data['scope_id']
                    : (int)$preEditRow['scope_id'];

                $DB->Clear();
                $DB->slug       = $newSlug;
                $DB->scope_type = $chkType;
                $DB->scope_id   = $chkId;
                $DB->page_id    = $pageId;
                $dup = $this->_firstRow($DB->DataSet(
                    'SELECT page_id FROM ' . DB_PREFIX . 'cms_page'
                    . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
                    . ' AND slug = :slug AND page_id <> :page_id'
                    . ' AND deleted_at IS NULL LIMIT 1'
                ));
                if ($dup !== null) {
                    return false;   // slug already in use in this scope — collision
                }
            }
        }

        // Whitelist of editable columns + their normalizers.
        $set        = array();
        $slugChange = null; // [oldPath, newSlug] when the slug actually changes
        $DB->Clear();

        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $DB->title = (string)$data['title'];
        }
        if (array_key_exists('slug', $data)) {
            // $newSlug was normalized (and dup-checked) up front.
            // C17: never persist a router-shadowed slug (blog/post/k/p) or an
            // empty one — silently keep the existing slug (the controller
            // pre-validates and surfaces a friendly message). When it genuinely
            // changes, stage a redirect record for after the write.
            if ($newSlug !== '' && !$this->IsReservedPageSlug($newSlug)) {
                $set[] = 'slug = :slug';
                $DB->slug = $newSlug;
                if ($preEditRow !== null && $newSlug !== (string)$preEditRow['slug']) {
                    $slugChange = array(
                        'old_path'   => $preEditPath,
                        'new_slug'   => $newSlug,
                        'scope_type' => (string)$preEditRow['scope_type'],
                        'scope_id'   => (int)$preEditRow['scope_id'],
                        'actor'      => (isset($data['updated_by']) && (int)$data['updated_by'] > 0) ? (int)$data['updated_by'] : 0,
                    );
                }
            }
        }
        if (array_key_exists('parent_id', $data)) {
            // C13: nullable self-reference. A 0/''/self value clears it (flat page).
            $set[] = 'parent_id = :parent_id';
            $pid = (int)$data['parent_id'];
            $DB->parent_id = ($pid > 0 && $pid !== $pageId) ? $pid : null;
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
            // C7: 'scheduled' is a first-class status now (promoted to published
            // on read once published_at arrives); anything else clamps to draft.
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

        // Always bump the updater + timestamp.
        $set[] = 'updated_at = :updated_at';
        $DB->updated_at = date('Y-m-d H:i:s');
        if (array_key_exists('updated_by', $data)) {
            $set[] = 'updated_by = :updated_by';
            $DB->updated_by = ($data['updated_by'] === null || $data['updated_by'] === '')
                ? null : (int)$data['updated_by'];
        }

        $DB->page_id = $pageId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_page SET ' . implode(', ', $set)
            . ' WHERE page_id = :page_id'
        );

        // C13: a parent-link change invalidates any memoized ancestor chains.
        if (array_key_exists('parent_id', $data)) {
            self::$_ancestorMemo = array();
        }

        // C17: after a slug change, verify the new slug actually LANDED before
        // trusting the rename. Execute() is void under ERRMODE_WARNING, so a
        // silently-dropped UPDATE (e.g. a racing writer claimed the tuple between
        // the pre-check and the write) leaves the old slug in place — recording a
        // 301 from the old path or reporting success would both be wrong. Read the
        // slug back and only then record the redirect / report success.
        if ($slugChange !== null) {
            $DB->Clear();
            $DB->page_id = $pageId;
            $verify = $this->_firstRow($DB->DataSet(
                'SELECT slug FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id LIMIT 1'
            ));
            if ($verify === null || (string)$verify['slug'] !== (string)$slugChange['new_slug']) {
                return false;   // rename didn't take — no bogus redirect, signal failure
            }
            // 301 the OLD path to this page so inbound links / bookmarks keep
            // resolving. Best-effort (never fails the save).
            if ($slugChange['old_path'] !== '') {
                $this->RecordRedirect(
                    $slugChange['scope_type'],
                    $slugChange['scope_id'],
                    $slugChange['old_path'],
                    $pageId,
                    null,
                    $slugChange['actor']
                );
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     * C13 — page hierarchy (nested slug paths + breadcrumbs)
     * ------------------------------------------------------------------ */

    /**
     * Walk the parent chain for a page and return its ancestors ordered
     * root → immediate-parent (the page itself is NOT included). Cycle-guarded
     * (a corrupt parent loop stops at a bounded depth). Each entry carries
     * page_id, slug, title, status.
     *
     * @param int $pageId
     * @return array list of ancestor rows (root first)
     */
    public function GetPageAncestors($pageId)
    {
        global $DB;

        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return array();
        }

        // Per-request memo: PagePath() + GetPageByPath() re-walk this chain per
        // render. Invalidated on any parent-link change (UpdatePage/DeletePage).
        if (array_key_exists($pageId, self::$_ancestorMemo)) {
            return self::$_ancestorMemo[$pageId];
        }

        $chain   = array();
        $seen    = array($pageId => true);
        $cursor  = $pageId;
        $guard   = 0;
        while ($guard++ < 25) {
            // One fetch per level: read the cursor node, then advance to its
            // parent. The node fetched this iteration becomes the ancestor added
            // next iteration — no separate parent round trip.
            $DB->Clear();
            $DB->page_id = (int)$cursor;
            $row = $this->_firstRow($DB->DataSet(
                'SELECT page_id, parent_id, slug, title, status FROM ' . DB_PREFIX . 'cms_page'
                . ' WHERE page_id = :page_id AND deleted_at IS NULL LIMIT 1'
            ));
            if ($row === null) {
                break;
            }
            // The first node is the page itself (not an ancestor); every later
            // node is a real ancestor, prepended so the chain stays root-first.
            if ((int)$row['page_id'] !== $pageId) {
                array_unshift($chain, $row);
            }
            $parentId = (int)($row['parent_id'] ?? 0);
            if ($parentId <= 0 || isset($seen[$parentId])) {
                break; // reached a root, or a cycle — stop
            }
            $seen[$parentId] = true;
            $cursor = $parentId;
        }
        self::$_ancestorMemo[$pageId] = $chain;
        return $chain;
    }

    /**
     * The full slug PATH for a page ('grandparent/parent/page'), assembled from
     * its ancestor chain. A flat page returns just its own slug. '' when the page
     * is gone.
     *
     * @param int $pageId
     * @return string
     */
    public function PagePath($pageId)
    {
        $row = $this->GetPage($pageId);
        if ($row === null) {
            return '';
        }
        $parts = array();
        foreach ($this->GetPageAncestors($pageId) as $anc) {
            $parts[] = (string)$anc['slug'];
        }
        $parts[] = (string)$row['slug'];
        return implode('/', array_filter($parts, function ($s) {
            return $s !== '';
        }));
    }

    /**
     * Resolve a nested slug PATH ('a/b/c') to a page row within a scope by
     * walking parent_id one segment at a time: the first segment must be a
     * top-level page (parent_id NULL) and each following segment a child of the
     * previous. A single-segment path is the flat-page case (unchanged). Returns
     * null on any miss (never falls back to a same-slug page in a different
     * branch, so the URL is unambiguous).
     *
     * @param string $path          slug path, '/'-separated, no leading slash
     * @param string $scopeType     'global'|'kingdom'|'park'
     * @param int    $scopeId
     * @param bool   $publishedOnly  only published (+ due) rows match
     * @return array|null the LEAF page row, or null
     */
    public function GetPageByPath($path, $scopeType = 'global', $scopeId = 0, $publishedOnly = true)
    {
        global $DB;

        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $segments = array_values(array_filter(
            explode('/', trim((string)$path, '/')),
            function ($s) {
                return $s !== '';
            }
        ));
        if (empty($segments)) {
            return null;
        }
        // Single segment → identical to the flat lookup (keeps existing behavior).
        if (count($segments) === 1) {
            return $this->GetPageBySlug($segments[0], $scopeType, $scopeId, $publishedOnly);
        }

        if ($publishedOnly) {
            $this->_promoteScheduled();
        }

        $parentId = null; // first segment is a root page
        $row      = null;
        foreach ($segments as $seg) {
            $seg = preg_replace('/[^a-z0-9\-]+/', '', strtolower((string)$seg));
            if ($seg === '') {
                return null;
            }
            $sql = 'SELECT * FROM ' . DB_PREFIX . 'cms_page'
                . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id'
                . ' AND deleted_at IS NULL'
                . ($parentId === null ? ' AND parent_id IS NULL' : ' AND parent_id = :parent_id');
            if ($publishedOnly) {
                $sql .= " AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())";
            }
            $sql .= ' LIMIT 1';

            $DB->Clear();
            $DB->slug       = $seg;
            $DB->scope_type = $scopeType;
            $DB->scope_id   = $scopeId;
            if ($parentId !== null) {
                $DB->parent_id = (int)$parentId;
            }
            $row = $this->_firstRow($DB->DataSet($sql));
            if ($row === null) {
                return null;
            }
            $parentId = (int)$row['page_id'];
        }
        return $row;
    }

    /* ------------------------------------------------------------------ *
     * C17 — 301 redirects (slug-change trail + vanity redirects)
     * ------------------------------------------------------------------ */

    /**
     * Upsert a redirect row (best-effort — silent before the C17 migration).
     * Exactly one of $toPageId / $toUrl should be set. A repeated from_path in
     * the same scope overwrites (the newest rename wins). Self-referential rows
     * (from_path already equals the target's current path) are pointless but
     * harmless — the lookup skips a redirect that resolves to the same path.
     *
     * @param string      $scopeType 'global'|'kingdom'|'park'
     * @param int         $scopeId
     * @param string      $fromPath  path after the site slug (no leading slash)
     * @param int|null    $toPageId  target page id (preferred), or null
     * @param string|null $toUrl     target URL (when not a page), or null
     * @param int         $actorId
     * @param int         $code      301 (default) or 302
     * @return bool
     */
    public function RecordRedirect($scopeType, $scopeId, $fromPath, $toPageId = null, $toUrl = null, $actorId = 0, $code = 301)
    {
        global $DB;

        $fromPath = trim((string)$fromPath, '/');
        if ($fromPath === '') {
            return false;
        }
        if (!$this->_redirectTableAvailable()) {
            return false;
        }

        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;
        $toPageId  = ((int)$toPageId > 0) ? (int)$toPageId : null;
        $toUrl     = ($toUrl === null || $toUrl === '') ? null : (string)$toUrl;
        $code      = ((int)$code === 302) ? 302 : 301;

        // Open-redirect guard: a to_url is an admin-supplied target that the
        // public router 30x-redirects to. Reject any unsafe scheme (javascript:,
        // data:, etc.) rather than persist it — the same allowlist used at the
        // block-field choke point. A rejected to_url with no to_page_id makes the
        // whole row targetless (LookupRedirect skips it → 404), which is correct.
        if ($toUrl !== null && !CmsSanitizer::IsSafeUrl($toUrl)) {
            $toUrl = null;
        }

        try {
            // Upsert on the UNIQUE(scope_type, scope_id, from_path) key.
            $DB->Clear();
            $DB->scope_type = $scopeType;
            $DB->scope_id   = $scopeId;
            $DB->from_path  = $fromPath;
            $DB->to_page_id = $toPageId;
            $DB->to_url     = $toUrl;
            $DB->code       = $code;
            $DB->created_by = ($actorId > 0) ? (int)$actorId : null;
            $DB->created_at = date('Y-m-d H:i:s');
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_redirect'
                . ' (scope_type, scope_id, from_path, to_page_id, to_url, code, created_by, created_at)'
                . ' VALUES (:scope_type, :scope_id, :from_path, :to_page_id, :to_url, :code, :created_by, :created_at)'
                . ' ON DUPLICATE KEY UPDATE'
                . ' to_page_id = VALUES(to_page_id), to_url = VALUES(to_url),'
                . ' code = VALUES(code), created_by = VALUES(created_by), created_at = VALUES(created_at)'
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Look up a redirect for a scope + path. Returns a resolved target:
     *   ['url' => <absolute-or-relative URL>, 'code' => 301|302]
     * or null when there is no (usable) redirect. A to_page_id row is resolved to
     * the page's CURRENT path (so a chain of renames still lands correctly); a
     * to_url row is returned verbatim. Rows whose target is gone are skipped.
     *
     * @param string $scopeType
     * @param int    $scopeId
     * @param string $fromPath path after the site slug (no leading slash)
     * @return array|null ['url','code','to_page_id'] or null
     */
    public function LookupRedirect($scopeType, $scopeId, $fromPath)
    {
        global $DB;

        $fromPath = trim((string)$fromPath, '/');
        if ($fromPath === '' || !$this->_redirectTableAvailable()) {
            return null;
        }

        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->from_path  = $fromPath;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT to_page_id, to_url, code FROM ' . DB_PREFIX . 'cms_redirect'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND from_path = :from_path LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }

        $code = ((int)$row['code'] === 302) ? 302 : 301;

        $toPageId = (int)($row['to_page_id'] ?? 0);
        if ($toPageId > 0) {
            $target = $this->GetPage($toPageId);
            if ($target === null) {
                return null; // dead target — skip (fall through to 404)
            }
            $path = $this->PagePath($toPageId);
            if ($path === '' || $path === $fromPath) {
                return null; // resolves to itself — no redirect
            }
            return array('url' => $path, 'code' => $code, 'to_page_id' => $toPageId);
        }

        $toUrl = (string)($row['to_url'] ?? '');
        if ($toUrl === '') {
            return null;
        }
        return array('url' => $toUrl, 'code' => $code, 'to_page_id' => 0);
    }

    /** Per-request probe: does ork_cms_redirect exist yet? (C17 migration gate.) */
    private function _redirectTableAvailable()
    {
        return $this->_tableExists(DB_PREFIX . 'cms_redirect');
    }

    /**
     * Set a page's publish status, stamping/clearing published_at. Publishing
     * stamps published_at (now) only when it is currently empty; unpublishing
     * leaves the historical stamp intact (so re-publish can preserve it).
     *
     * When $status is 'scheduled' a future $publishedAt is required (the read
     * path promotes it to 'published' once that time passes — see C7).
     *
     * @param int         $pageId
     * @param string      $status      'published' | 'draft' | 'scheduled'
     * @param int         $updatedBy   mundane_id of the actor (0 to skip)
     * @param string|null $publishedAt explicit publish timestamp (used for
     *                                 scheduling; ignored when unpublishing)
     * @return bool
     */
    public function SetStatus($pageId, $status, $updatedBy = 0, $publishedAt = null)
    {
        $pageId = (int)$pageId;
        // Shared publish-lifecycle skeleton (status clamp, published_at stamping,
        // C14 audit) lives in CmsBase::_setStatus; the column write delegates back
        // to UpdatePage so its whitelist/verify path still runs.
        return $this->_setStatus(
            'cms_page',
            'page_id',
            'page',
            $pageId,
            $status,
            $updatedBy,
            $publishedAt,
            function ($data) use ($pageId) {
                return $this->UpdatePage($pageId, $data);
            }
        );
    }

    /**
     * Trash a page (C2 soft-delete): stamp deleted_at instead of physically
     * DELETEing, so the page and its blocks/revisions survive for restore.
     * Refuses to trash a system page (is_system=1). Within the same transaction
     * it clears inbound references so the live site never dangles (C8): NULLs
     * any ork_cms_site.home_page_id and ork_cms_nav_item.page_id pointing here.
     *
     * @param int         $pageId
     * @param string|null $scopeType IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId   IDOR guard: caller's intended scope_id
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @return bool true when the page existed and was trashed
     */
    public function DeletePage($pageId, $scopeType = null, $scopeId = null, $actorId = 0)
    {
        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return false;
        }

        // System pages (e.g. the home page) are protected — checked here rather
        // than in the shared skeleton since is_system is page-only.
        $row = $this->GetPage($pageId);   // already filters trashed rows
        if ($row === null) {
            return false;
        }
        if (!empty($row['is_system'])) {
            return false;
        }

        // Shared soft-delete skeleton (existence + IDOR guard, transactional
        // stamp, verify, C14 audit). The $refCleanup hook carries the page-only
        // inbound-reference detach (C8) + child flatten (C13) — the ON DELETE SET
        // NULL FKs do NOT fire on a soft-delete, so they run explicitly inside the
        // transaction before the trash marker is stamped.
        $ok = $this->_softDelete(
            'cms_page',
            'page_id',
            $pageId,
            $scopeType,
            $scopeId,
            $actorId,
            'page',
            function ($id) {
                global $DB;

                // A site whose home page is trashed reverts to no home page.
                $DB->Clear();
                $DB->home_page_id = $id;
                $DB->Execute(
                    'UPDATE ' . DB_PREFIX . 'cms_site SET home_page_id = NULL WHERE home_page_id = :home_page_id'
                );

                // Nav items pointing here resolve to '#'.
                $DB->Clear();
                $DB->page_id = $id;
                $DB->Execute(
                    'UPDATE ' . DB_PREFIX . 'cms_nav_item SET page_id = NULL WHERE page_id = :page_id'
                );

                // C13: flatten child pages so a trashed parent leaves them as
                // top-level pages rather than pointing at a hidden parent.
                $DB->Clear();
                $DB->parent_id = $id;
                $DB->Execute(
                    'UPDATE ' . DB_PREFIX . 'cms_page SET parent_id = NULL WHERE parent_id = :parent_id'
                );
            }
        );

        // A parent-link change (child flatten) invalidates memoized ancestor chains.
        if ($ok) {
            self::$_ancestorMemo = array();
        }

        return $ok;
    }

    /**
     * Restore a trashed page (clear deleted_at). Optional IDOR scope guard.
     * Detached nav/home-page references are NOT re-linked (they were cleared on
     * trash); relink them manually if needed.
     *
     * @param int         $pageId
     * @param string|null $scopeType IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId   IDOR guard: caller's intended scope_id
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @return bool
     */
    public function RestorePage($pageId, $scopeType = null, $scopeId = null, $actorId = 0)
    {
        // Shared restore skeleton: existence/IDOR guard, live-slug collision guard
        // (a live page may have claimed this slug while we were trashed — see
        // CmsBase::_restore), verified un-trash, C14 audit.
        return $this->_restore('cms_page', 'page_id', $pageId, $scopeType, $scopeId, $actorId, 'page');
    }

    /**
     * Did RestorePage() fail specifically because a LIVE page now holds the
     * trashed page's slug? Callers use this only to choose an error message.
     *
     * @param int $pageId
     * @return bool
     */
    public function RestoreSlugConflict($pageId)
    {
        return $this->_slugConflictForTrashed('cms_page', 'page_id', $pageId);
    }

    /**
     * Persist the ordered block set for an owner. This is the AUTHORITATIVE
     * choke point every writer passes through (editor saves, seeding, imports),
     * so it does three things no caller can bypass:
     *
     *   C3  — sanitizes every rich-text/HTML field through CmsSanitizer::Clean
     *         and neutralizes unsafe URL fields BEFORE storage, so persisted
     *         content is always clean regardless of entry path.
     *   C15 — upserts by a STABLE block id: a block carrying an existing id is
     *         UPDATEd in place (id preserved across edits), genuinely new blocks
     *         are batch-INSERTed as one multi-VALUES statement, and only blocks
     *         the editor actually removed are DELETEd. No more delete-all/reinsert.
     *   C2  — snapshots the resulting block set as a revision (capped history) so
     *         a bad save is recoverable.
     *
     * Each block accepts the renderer shape (id?, type, enabled, order/ordering,
     * source, fields). Wrapped in a transaction with a post-write count check;
     * returns the number of blocks now stored, or -1 on a verified partial write.
     *
     * @param string $ownerType   'page' | 'post'
     * @param int    $ownerId     owner row id
     * @param array  $blocksArray ordered list of block definitions
     * @return int number of blocks stored (-1 on partial-write rollback)
     */
    public function ReplaceBlocks($ownerType, $ownerId, $blocksArray)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';
        $ownerId = (int)$ownerId;

        // C3: normalize + sanitize up front, outside the transaction.
        $normalized = $this->_normalizeBlocks($blocksArray);

        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        // C15: existing block ids for this owner (the upsert candidate set).
        $existingIds = $this->_existingBlockIds($ownerType, $ownerId);

        // C15: UPDATE knowns in place, DELETE only the removed, batch-INSERT new.
        $upsert = $this->_upsertKnownBlocks($ownerType, $ownerId, $normalized, $existingIds);
        $this->_deleteRemovedBlocks($ownerType, $ownerId, $existingIds, $upsert['kept']);
        $this->_insertNewBlocks($ownerType, $ownerId, $upsert['inserts']);

        // Verify the write landed exactly as intended (total count + per-block
        // fields). Execute() is void under ERRMODE_WARNING, so a silently-dropped
        // write is only visible via a read-back inside the transaction → ROLLBACK.
        $expected = count($upsert['kept']) + count($upsert['inserts']);
        if (!$this->_verifyBlockCount($ownerType, $ownerId, $expected, $upsert['keptFields'])) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return -1;
        }

        // C2: snapshot the state we just wrote (inside the txn so a rollback
        // would discard it too), then prune to the retention cap.
        $this->_snapshotRevision($ownerType, $ownerId, $normalized);

        $DB->Clear();
        $DB->Execute('COMMIT');

        return $expected;
    }

    /**
     * C3/C15: normalize + sanitize the incoming block list into the internal
     * shape ReplaceBlocks persists. Skips non-array / typeless entries; accepts
     * 'order' (renderer shape) or 'ordering' (column); sanitizes every fields
     * array at the authoritative choke point. Pure (no DB) — runs outside the txn.
     *
     * @param mixed $blocksArray ordered list of block definitions (or non-array)
     * @return array list of normalized block rows
     */
    private function _normalizeBlocks($blocksArray)
    {
        $normalized = array();
        $i = 0;
        foreach ((is_array($blocksArray) ? $blocksArray : array()) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['type']) ? (string)$block['type'] : '';
            if ($type === '') {
                continue;
            }

            // Accept 'order' (renderer shape) or 'ordering' (column); else index.
            if (isset($block['ordering'])) {
                $ordering = (int)$block['ordering'];
            } elseif (isset($block['order'])) {
                $ordering = (int)$block['order'];
            } else {
                $ordering = $i * 10;
            }

            $enabled = isset($block['enabled']) ? (int)(bool)$block['enabled'] : 1;
            $source = (isset($block['source']) && $block['source'] === 'dynamic') ? 'dynamic' : 'authored';

            $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();
            $fields = $this->_sanitizeBlockFields($fields);   // C3 authoritative clean

            $normalized[] = array(
                // C15: a positive client-supplied id means "this is an existing
                // block — keep its row"; 0/absent means a brand-new block.
                'id'          => (isset($block['id']) && (int)$block['id'] > 0) ? (int)$block['id'] : 0,
                'type'        => $type,
                'ordering'    => $ordering,
                'enabled'     => $enabled,
                'source'      => $source,
                'fields'      => $fields,
                'fields_json' => json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
            $i++;
        }
        return $normalized;
    }

    /**
     * The set of existing block ids for an owner (upsert candidate set), as a
     * map [block_id => true]. Called inside the ReplaceBlocks transaction.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @return array map of existing block_id => true
     */
    private function _existingBlockIds($ownerType, $ownerId)
    {
        global $DB;

        $DB->Clear();
        $DB->owner_type = $ownerType;
        $DB->owner_id = $ownerId;
        $existingIds = array();
        foreach ($this->_eachRow($DB->DataSet(
            'SELECT block_id FROM ' . DB_PREFIX . 'cms_block'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
        )) as $er) {
            $existingIds[(int)$er['block_id']] = true;
        }
        return $existingIds;
    }

    /**
     * C15: UPDATE each block carrying a known existing id in place, and collect
     * brand-new blocks for the batch insert. Called inside the ReplaceBlocks
     * transaction. Returns ['kept'=>[id=>true], 'keptFields'=>[id=>fields_json],
     * 'inserts'=>[normalized,...]].
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param array  $normalized
     * @param array  $existingIds map of existing block_id => true
     * @return array
     */
    private function _upsertKnownBlocks($ownerType, $ownerId, $normalized, $existingIds)
    {
        global $DB;

        $kept = array();
        $keptFields = array();
        $inserts = array();
        foreach ($normalized as $n) {
            if ($n['id'] > 0 && isset($existingIds[$n['id']])) {
                $DB->Clear();
                $DB->type = $n['type'];
                $DB->ordering = $n['ordering'];
                $DB->enabled = $n['enabled'];
                $DB->source = $n['source'];
                $DB->fields_json = $n['fields_json'];
                $DB->block_id = $n['id'];
                $DB->owner_type = $ownerType;
                $DB->owner_id = $ownerId;
                $DB->Execute(
                    'UPDATE ' . DB_PREFIX . 'cms_block'
                    . ' SET type = :type, ordering = :ordering, enabled = :enabled,'
                    . ' source = :source, fields_json = :fields_json'
                    . ' WHERE block_id = :block_id AND owner_type = :owner_type AND owner_id = :owner_id'
                );
                $kept[$n['id']] = true;
                $keptFields[$n['id']] = $n['fields_json'];
            } else {
                $inserts[] = $n;
            }
        }
        return array('kept' => $kept, 'keptFields' => $keptFields, 'inserts' => $inserts);
    }

    /**
     * Delete only the blocks the editor actually removed (existing ids no longer
     * present in the kept set). Called inside the ReplaceBlocks transaction.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param array  $existingIds map of existing block_id => true
     * @param array  $kept        map of kept block_id => true
     * @return void
     */
    private function _deleteRemovedBlocks($ownerType, $ownerId, $existingIds, $kept)
    {
        global $DB;

        $toDelete = array();
        foreach ($existingIds as $eid => $_unused) {
            if (!isset($kept[$eid])) {
                $toDelete[] = (int)$eid;
            }
        }
        if (empty($toDelete)) {
            return;
        }
        $DB->Clear();
        $DB->owner_type = $ownerType;
        $DB->owner_id = $ownerId;
        // Code-controlled ints only; IN() can't be a bound list.
        $idList = implode(',', array_map('intval', $toDelete));
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_block'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
            . ' AND block_id IN (' . $idList . ')'
        );
    }

    /**
     * C15: batch-insert the brand-new blocks as ONE multi-VALUES statement.
     * Called inside the ReplaceBlocks transaction.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param array  $inserts list of normalized new-block rows
     * @return void
     */
    private function _insertNewBlocks($ownerType, $ownerId, $inserts)
    {
        global $DB;

        if (empty($inserts)) {
            return;
        }
        $rows = array();
        $j = 0;
        $DB->Clear();
        foreach ($inserts as $n) {
            // Distinct placeholders per row (emulated prepares forbid reusing
            // a name), so every value is bound — nothing is concatenated raw.
            $rows[] = '(:ot_' . $j . ', :oid_' . $j . ', :type_' . $j . ', :ord_' . $j
                . ', :en_' . $j . ', :src_' . $j . ', :fj_' . $j . ')';
            $DB->{'ot_' . $j}   = $ownerType;
            $DB->{'oid_' . $j}  = $ownerId;
            $DB->{'type_' . $j} = $n['type'];
            $DB->{'ord_' . $j}  = $n['ordering'];
            $DB->{'en_' . $j}   = $n['enabled'];
            $DB->{'src_' . $j}  = $n['source'];
            $DB->{'fj_' . $j}   = $n['fields_json'];
            $j++;
        }
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'cms_block'
            . ' (owner_type, owner_id, type, ordering, enabled, source, fields_json)'
            . ' VALUES ' . implode(', ', $rows)
        );
    }

    /**
     * Verify the persisted block set matches intent before COMMIT: (1) the total
     * COUNT(*) equals kept+inserted, and (2) each kept block's fields_json was
     * stored verbatim (catches a silently-dropped UPDATE that leaves the row —
     * and thus the count — unchanged under ERRMODE_WARNING). fields_json is
     * LONGTEXT (stored verbatim, no engine normalization), so an exact string
     * compare is reliable. Returns false to signal the caller must ROLLBACK.
     * Called inside the ReplaceBlocks transaction.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param int    $expected   count(kept) + count(inserts)
     * @param array  $keptFields map of kept block_id => intended fields_json
     * @return bool true when the write verified; false → caller ROLLBACKs
     */
    private function _verifyBlockCount($ownerType, $ownerId, $expected, $keptFields)
    {
        global $DB;

        $DB->Clear();
        $DB->owner_type = $ownerType;
        $DB->owner_id = $ownerId;
        $countRow = $this->_firstRow($DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_block'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
        ));
        $actual = $countRow ? (int)$countRow['c'] : 0;
        if ($actual !== $expected) {
            return false;
        }

        if (!empty($keptFields)) {
            $keptIdList = implode(',', array_map('intval', array_keys($keptFields)));
            $DB->Clear();
            $DB->owner_type = $ownerType;
            $DB->owner_id = $ownerId;
            $storedFields = array();
            foreach ($this->_eachRow($DB->DataSet(
                'SELECT block_id, fields_json FROM ' . DB_PREFIX . 'cms_block'
                . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
                . ' AND block_id IN (' . $keptIdList . ')'
            )) as $vr) {
                $storedFields[(int)$vr['block_id']] = isset($vr['fields_json']) ? $vr['fields_json'] : null;
            }
            foreach ($keptFields as $bid => $intended) {
                if (!array_key_exists((int)$bid, $storedFields) || $storedFields[(int)$bid] !== $intended) {
                    return false;
                }
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     * Sanitization (C3) — authoritative HTML/URL cleaning at the choke point
     * ------------------------------------------------------------------ */

    /**
     * Recursively clean a block-fields array: rich-text/HTML fields through
     * CmsSanitizer::Clean, URL fields through the scheme allowlist. Descends
     * into nested arrays (accordion items, columns, etc.). Mirrors — and is the
     * authoritative counterpart of — Controller_CmsAjax::_sanitizeFields.
     *
     * @param array $fields raw fields (may be nested)
     * @return array the same structure with HTML/URL fields cleaned
     */
    private function _sanitizeBlockFields(array $fields)
    {
        foreach ($fields as $key => $val) {
            if (is_array($val)) {
                $fields[$key] = $this->_sanitizeBlockFields($val);
            } elseif (is_string($val) && in_array($key, self::$HTML_FIELDS, true)) {
                $fields[$key] = CmsSanitizer::Clean($val);
            } elseif (is_string($val) && in_array($key, self::$URL_FIELDS, true)) {
                $fields[$key] = CmsSanitizer::IsSafeUrl($val) ? $val : '#';
            }
        }
        return $fields;
    }

    /* ------------------------------------------------------------------ *
     * Revisions (C2) — capped block-set history + restore
     * ------------------------------------------------------------------ */

    /**
     * Snapshot the just-written block set as a revision row, then prune the
     * owner's history to $MAX_REVISIONS. Best-effort: never aborts the save.
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @param array  $normalized normalized block list (from ReplaceBlocks)
     * @return void
     */
    private function _snapshotRevision($ownerType, $ownerId, $normalized)
    {
        global $DB;

        try {
            if (!$this->_tableExists(DB_PREFIX . 'cms_revision')) {
                return;
            }

            // Build the renderer-shape block list for the snapshot (preserve ids
            // so a restore re-applies by stable id).
            $snapshot = array();
            foreach ($normalized as $n) {
                $snapshot[] = array(
                    'id'      => (int)$n['id'],
                    'type'    => $n['type'],
                    'order'   => (int)$n['ordering'],
                    'enabled' => (int)$n['enabled'],
                    'source'  => $n['source'],
                    'fields'  => $n['fields'],
                );
            }

            $meta = $this->_ownerMeta($ownerType, $ownerId);
            $authorId = ($meta !== null && !empty($meta['updated_by'])) ? (int)$meta['updated_by'] : null;

            $DB->Clear();
            $DB->owner_type  = $ownerType;
            $DB->owner_id    = (int)$ownerId;
            $DB->blocks_json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $DB->meta_json   = json_encode($meta !== null ? $meta : array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $DB->author_id   = $authorId;
            $DB->created_at  = date('Y-m-d H:i:s');
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_revision'
                . ' (owner_type, owner_id, blocks_json, meta_json, author_id, created_at)'
                . ' VALUES (:owner_type, :owner_id, :blocks_json, :meta_json, :author_id, :created_at)'
            );

            // Prune older-than-cap revisions for this owner. Delete every row
            // whose id is NOT among the newest $MAX_REVISIONS. The nested derived
            // table dodges MySQL's "can't LIMIT a subquery used with IN" limit,
            // and its "can't reference the DELETE target in a subquery" rule.
            // owner_type (exact 'page'|'post' literal) and owner_id (int) are
            // inlined rather than bound because the tuple appears twice in one
            // statement, and named placeholders must not be reused per-statement.
            $keep = (int)self::$MAX_REVISIONS;
            $ownerLit = ($ownerType === 'post') ? 'post' : 'page';
            $ownerIdInt = (int)$ownerId;
            $DB->Clear();
            $DB->Execute(
                'DELETE FROM ' . DB_PREFIX . 'cms_revision'
                . " WHERE owner_type = '" . $ownerLit . "' AND owner_id = " . $ownerIdInt
                . ' AND revision_id NOT IN ('
                . '   SELECT revision_id FROM ('
                . '     SELECT revision_id FROM ' . DB_PREFIX . 'cms_revision'
                . "     WHERE owner_type = '" . $ownerLit . "' AND owner_id = " . $ownerIdInt
                . '     ORDER BY revision_id DESC LIMIT ' . $keep
                . '   ) keep_set'
                . ' )'
            );
        } catch (\Throwable $e) {
            // Best-effort history — a snapshot failure never fails the save.
        }
    }

    /**
     * Read a lightweight meta snapshot for the owner (title/slug/status/etc.),
     * used to label revision rows. Returns null when the owner is gone.
     */
    private function _ownerMeta($ownerType, $ownerId)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';
        $ownerId = (int)$ownerId;
        $table = ($ownerType === 'post') ? 'cms_post' : 'cms_page';
        $pk    = ($ownerType === 'post') ? 'post_id' : 'page_id';

        $DB->Clear();
        $DB->owner_pk = $ownerId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT title, slug, status, published_at, updated_by'
            . ' FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $pk . ' = :owner_pk LIMIT 1'
        ));
        return $row;
    }

    /**
     * List an owner's revisions, newest-first (metadata only — blocks_json is
     * omitted from the list for weight; fetch it via RestoreRevision).
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @param int    $limit
     * @return array list of ['revision_id','author_id','created_at','meta']
     */
    public function ListRevisions($ownerType, $ownerId, $limit = 25)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';
        $ownerId = (int)$ownerId;
        $limit = (int)$limit;
        if ($limit <= 0 || $limit > 100) {
            $limit = 25;
        }

        $DB->Clear();
        $DB->owner_type = $ownerType;
        $DB->owner_id = $ownerId;
        $out = array();
        foreach ($this->_eachRow($DB->DataSet(
            'SELECT revision_id, author_id, created_at, meta_json'
            . ' FROM ' . DB_PREFIX . 'cms_revision'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
            . ' ORDER BY revision_id DESC LIMIT ' . $limit
        )) as $row) {
            $meta = array();
            if (!empty($row['meta_json'])) {
                $decoded = json_decode($row['meta_json'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $out[] = array(
                'revision_id' => (int)$row['revision_id'],
                'author_id'   => ($row['author_id'] === null) ? null : (int)$row['author_id'],
                'created_at'  => $row['created_at'],
                'meta'        => $meta,
            );
        }
        return $out;
    }

    /**
     * Restore an owner's blocks from a revision. Validates the revision belongs
     * to the owner, then re-applies its block set via ReplaceBlocks (which in
     * turn snapshots the restored state, so history is never lost).
     *
     * @param int    $revisionId
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @return bool
     */
    public function RestoreRevision($revisionId, $ownerType, $ownerId)
    {
        global $DB;

        $revisionId = (int)$revisionId;
        $ownerType = ($ownerType === 'post') ? 'post' : 'page';
        $ownerId = (int)$ownerId;
        if ($revisionId <= 0 || $ownerId <= 0) {
            return false;
        }

        $DB->Clear();
        $DB->revision_id = $revisionId;
        $DB->owner_type = $ownerType;
        $DB->owner_id = $ownerId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT blocks_json FROM ' . DB_PREFIX . 'cms_revision'
            . ' WHERE revision_id = :revision_id AND owner_type = :owner_type AND owner_id = :owner_id LIMIT 1'
        ));
        if ($row === null) {
            return false;   // not found, or belongs to a different owner
        }

        $blocks = json_decode(isset($row['blocks_json']) ? (string)$row['blocks_json'] : '', true);
        if (!is_array($blocks)) {
            $blocks = array();
        }

        return $this->ReplaceBlocks($ownerType, $ownerId, $blocks) >= 0;
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

        // C7: keep the admin list honest — flip any due scheduled rows first.
        $this->_promoteScheduled();

        // C2: never list trashed pages.
        $where = array('deleted_at IS NULL');

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
            // Distinct placeholders: native prepared statements forbid reusing
            // one named param twice in a single statement.
            $where[] = '(title LIKE :search_t OR slug LIKE :search_s)';
            $DB->search_t = '%' . $filters['search'] . '%';
            $DB->search_s = '%' . $filters['search'] . '%';
        }

        // Hard default cap when no caller limit is supplied — never return an
        // unbounded result set. Explicit $filters['limit'] still wins.
        if (!empty($filters['limit'])) {
            // Code-controlled integer only; inlined since LIMIT can't be bound.
            $limit = ' LIMIT ' . (int)$filters['limit'];
        } else {
            $limit = ' LIMIT 500';
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

    /**
     * Status-broken-down live page counts for a scope, via a single GROUP BY
     * (no full-row fetch). Only non-trashed rows are counted (deleted_at IS NULL).
     * Lets admin surfaces show "N drafts / M published" without materializing the
     * rows. Statuses with no rows are simply absent from the map.
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @return array ['total' => int, '<status>' => int, ...] e.g.
     *               ['total'=>7,'draft'=>2,'published'=>4,'scheduled'=>1]
     */
    public function CountPages($scopeType, $scopeId)
    {
        global $DB;

        $scopeType = $this->_normalizeScopeType($scopeType);

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = (int)$scopeId;
        $out = array('total' => 0);
        foreach ($this->_eachRow($DB->DataSet(
            'SELECT status, COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND deleted_at IS NULL'
            . ' GROUP BY status'
        )) as $row) {
            $c = (int)$row['c'];
            $out[(string)$row['status']] = $c;
            $out['total'] += $c;
        }
        return $out;
    }

}
