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
     * E36: single source of truth for the block-field sanitize choke-point lists.
     * Block field keys whose values hold authored rich-text/HTML and MUST be run
     * through CmsSanitizer::Clean before storage. PUBLIC so the controller layer
     * (Controller_CmsAjax) references THESE constants instead of maintaining its
     * own duplicated copy — this class (the choke point every writer passes
     * through) owns the canonical membership; the controller copy is redundant
     * belt-and-suspenders keyed off the same constant.
     */
    public const HTML_FIELDS = array('body', 'html');

    /** E36: block field keys holding a URL → must pass URL-scheme validation on save. */
    public const URL_FIELDS = array('href', 'more_href', 'url', 'link', 'cta_href', 'button_href', 'src', 'thumb', 'poster');

    /** Max revision snapshots retained per owner (older ones pruned on write). */
    private static $MAX_REVISIONS = 25;

    /**
     * C17: page slugs that would be shadowed by the pretty-URL router. nginx
     * routes /k/{site}/blog and /k/{site}/post/{x} (and the /k, /p prefixes)
     * BEFORE the generic /k/{site}/{pageSlug} rewrite, so a PAGE slugged with any
     * of these can never be reached. Rejected at every write path (CreatePage +
     * the controller savepage guard). Compared case-insensitively; applies to the
     * FIRST path segment (a top-level page slug), which is what the router sees.
     *
     * 'rss' is the one actually rewritten today (/k|/p/{site}/rss → Site/rss), so it
     * is the value that matters. 'sitemap' + 'robots' are held in reserve only: the
     * documented future routes are /k|/p/{site}/sitemap.xml and robots.txt, and
     * _normalizeSlug() (CmsBase — the page-slug deriver; _slugify() is CmsPost-only)
     * turns those filenames into 'sitemap-xml'/'robots-txt', which these entries do
     * NOT cover. They prevent the bare-word collision only.
     */
    private static $RESERVED_PAGE_SLUGS = array('blog', 'post', 'p', 'k', 'rss', 'sitemap', 'robots');

    /**
     * Per-request memo of the ancestor chain, keyed by page_id (C13). PagePath()
     * + GetPageByPath() re-walk the parent chain per render; this collapses the
     * repeats. Mirrors the static-memo pattern used for table-existence probes.
     * Invalidated whenever a parent link changes (UpdatePage/DeletePage).
     */
    private static $_ancestorMemo = array();

    /**
     * C1/#9: GhettoCache "call" namespace + TTL for GetPageWithBlocks. The
     * front-door / site render path resolves a page + its enabled blocks on every
     * request; caching the (enabled) block set per (scope, page_id, updated_at)
     * collapses the block query. The updated_at component makes any page-meta edit
     * self-busting; #121 also bumps updated_at on a block-only edit (ReplaceBlocks),
     * which re-keys this cache — ReplaceBlocks still busts the OLD key explicitly
     * BEFORE the write via _bustPageWithBlocksCache(). Mirrors the RSS cache
     * pattern in CmsPost.
     */
    private const PWB_CACHE_CALL = 'CmsPage.page_with_blocks';
    private const PWB_CACHE_TTL  = 1800;

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

    /** @var CmsPost|null lazily-instantiated post delegate (revision meta-restore) */
    private $_postLib = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The CmsPost lib instance used when RestoreRevision re-applies snapshotted
     * meta to a POST owner (so the post's slug-uniqueness + verify path runs).
     * Mirror of CmsPost::_pages(): prefer the shared Ork3 lib registry, else
     * instantiate.
     *
     * @return CmsPost
     */
    private function _posts()
    {
        if ($this->_postLib instanceof CmsPost) {
            return $this->_postLib;
        }
        if (isset(Ork3::$Lib) && is_object(Ork3::$Lib) && isset(Ork3::$Lib->CmsPost) && Ork3::$Lib->CmsPost instanceof CmsPost) {
            $this->_postLib = Ork3::$Lib->CmsPost;
        } else {
            $this->_postLib = new CmsPost();
        }
        return $this->_postLib;
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
     * C2/#39: ALL blocks for an owner INCLUDING disabled ones, in the SAME row
     * shape as GetBlocks (id/type/order/enabled/source/fields). The public
     * GetBlocks stays enabled-only (the renderer skips disabled blocks); the
     * editor + the home-relink migration hydrate through THIS so a disabled block
     * survives an edit/save round-trip instead of being silently dropped.
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId   owner row id
     * @return array list of ['id','type','enabled','order','source','fields']
     */
    public function GetBlocksForEditor($ownerType, $ownerId)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';

        $sql = 'SELECT block_id, owner_type, owner_id, type, ordering, enabled, source, fields_json'
            . ' FROM ' . DB_PREFIX . 'cms_block'
            . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
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
                'enabled' => ((int)$row['enabled'] === 1),   // real value (not forced true)
                'order'   => (int)$row['ordering'],
                'source'  => $row['source'],
                'fields'  => $fields,
            );
        }
        return $blocks;
    }

    /**
     * C1/#9: resolve a page (by slug, or the system home when $slug is null/'')
     * within a scope, together with its ENABLED block set, as
     * ['page' => row|null, 'blocks' => [...]]. The block set is served from (and
     * stored into) the GhettoCache keyed by (scope, page_id, updated_at) so the
     * hot render path skips the block query. Busted on
     * UpdatePage/ReplaceBlocks/SetStatus/DeletePage/RestorePage. A miss (or a
     * page that isn't found/live) never caches.
     *
     * @param string      $scopeType         'global' | 'kingdom' | 'park'
     * @param int         $scopeId           scope owner id (0 for global)
     * @param string|null $slugOrNullForHome page slug, or null/'' for the home page
     * @param bool        $publishedOnly     public gate (default true)
     * @return array ['page' => array|null, 'blocks' => array]
     */
    public function GetPageWithBlocks($scopeType, $scopeId, $slugOrNullForHome, $publishedOnly = true)
    {
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        if ($slugOrNullForHome === null || $slugOrNullForHome === '') {
            // The system home page is global by definition (GetHomePage ignores
            // scope), so the cache key for home always lands on global/0.
            $page = $this->GetHomePage();
        } else {
            $page = $this->GetPageBySlug((string)$slugOrNullForHome, $scopeType, $scopeId, $publishedOnly);
        }
        if ($page === null) {
            return array('page' => null, 'blocks' => array());
        }

        $pageId    = (int)$page['page_id'];
        $updatedAt = isset($page['updated_at']) ? (string)$page['updated_at'] : '';

        $gc  = $this->_ghettoCache();
        $key = null;
        if ($gc !== null) {
            $key    = $gc->key(self::_pwbKeyArgs((string)$page['scope_type'], (int)$page['scope_id'], $pageId, $updatedAt));
            $cached = $gc->get(self::PWB_CACHE_CALL, $key, self::PWB_CACHE_TTL);
            if ($cached !== false) {
                $blocks = json_decode((string)$cached, true);
                if (is_array($blocks)) {
                    return array('page' => $page, 'blocks' => $blocks);
                }
            }
        }

        $blocks = $this->GetBlocks('page', $pageId);
        if ($gc !== null && $key !== null) {
            $gc->cache(
                self::PWB_CACHE_CALL,
                $key,
                json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        return array('page' => $page, 'blocks' => $blocks);
    }

    /**
     * The GhettoCache key ARGS for a page's cached (enabled) block set. Order
     * matters — Ghettocache::key() implodes the values — so the writer and the
     * invalidator build the key from this SAME shape.
     *
     * @return array
     */
    private static function _pwbKeyArgs($scopeType, $scopeId, $pageId, $updatedAt)
    {
        return array(
            'scope_type' => (string)$scopeType,
            'scope_id'   => (int)$scopeId,
            'page_id'    => (int)$pageId,
            'updated_at' => (string)$updatedAt,
        );
    }

    /**
     * C1/#9: bust a page's cached GetPageWithBlocks block set. Reads the page's
     * CURRENT (scope, updated_at) so the key matches what GetPageWithBlocks stored
     * — safe to call while updated_at is unchanged (DeletePage soft-delete /
     * RestorePage) and BEFORE a write bumps updated_at (UpdatePage, and #121's
     * ReplaceBlocks owner stamp). No-op when memcache isn't wired up or row gone.
     *
     * @param int $pageId
     * @return void
     */
    private function _bustPageWithBlocksCache($pageId)
    {
        global $DB;

        $gc = $this->_ghettoCache();
        if ($gc === null) {
            return;
        }
        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return;
        }

        $DB->Clear();
        $DB->page_id = $pageId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id, updated_at FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id LIMIT 1'
        ));
        if ($row === null) {
            return;
        }
        $updatedAt = isset($row['updated_at']) ? (string)$row['updated_at'] : '';
        $gc->bust(
            self::PWB_CACHE_CALL,
            $gc->key(self::_pwbKeyArgs((string)$row['scope_type'], (int)$row['scope_id'], $pageId, $updatedAt))
        );
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

        // C17/#59: refuse a router-shadowed slug (blog/post/k/p) ONLY for a
        // TOP-LEVEL page — the reserved slugs shadow the FIRST path segment, so a
        // child page (parent_id set) may legitimately be slugged 'blog'/'post'/etc.
        // Signalled as a collision (0) so the caller's "slug in use" path handles
        // it; the controller pre-checks IsReservedPageSlug for a specific message.
        if ($cols['parent_id'] === null && $this->IsReservedPageSlug($cols['slug'])) {
            return 0;
        }

        // #55: a supplied parent must be an existing, non-trashed page in the SAME
        // scope (no cross-scope nesting). A new page has no descendants yet, so no
        // cycle is possible here — the cycle guard is UpdatePage's concern.
        if ($cols['parent_id'] !== null
            && !$this->_parentLinkValid($cols['parent_id'], $cols['scope_type'], $cols['scope_id'], 0)
        ) {
            return 0;
        }

        // Shared dup-guarded insert (C29 + live-slug reuse). The dup pre-check,
        // INSERT IGNORE, ROW_COUNT() race arbitration and authoritative
        // read-back-by-live-tuple all live in CmsBase::_insertWithDupGuard so
        // CreatePage/CreatePost stay in lockstep.
        $pageId = $this->_insertWithDupGuard('cms_page', 'page_id', $cols);

        // #62: audit the content-creating write (not just delete/restore/publish).
        // Actor = whoever the create attributed the row to; scope from the new row.
        if ($pageId > 0) {
            $actorId = (int)($cols['updated_by'] ?? ($cols['created_by'] ?? 0));
            $this->_cmsAudit($actorId, 'update', 'page', $pageId, $cols['scope_type'], (int)$cols['scope_id']);
        }

        return $pageId;
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

        // C1/#9: bust the cached block set BEFORE the write. Reads the CURRENT
        // updated_at so it hits the exact key GetPageWithBlocks stored; the write
        // below then bumps updated_at, so subsequent reads key fresh regardless.
        $this->_bustPageWithBlocksCache($pageId);

        // #54: existence + not-trashed guard. GetPage() filters deleted_at IS NULL,
        // so a nonexistent OR trashed page short-circuits to false here (and gives
        // us the live row for the effective-parent / scope derivations below). Runs
        // its own Clear()/DataSet(), so it precedes the bind loop.
        $curRow = $this->GetPage($pageId);
        if ($curRow === null) {
            return false;
        }

        // #55/#59: derive the EFFECTIVE parent after this update (in-flight
        // parent_id change wins, else the current one). Drives the cycle/scope
        // parent guard (#55) and the top-level-only reserved-slug gate (#59).
        if (array_key_exists('parent_id', $data)) {
            $dp        = (int)$data['parent_id'];
            $effParent = ($dp > 0 && $dp !== $pageId) ? $dp : 0;
        } else {
            $effParent = (int)($curRow['parent_id'] ?? 0);
        }
        $isTopLevel = ($effParent <= 0);

        // #55: a genuine parent change must reference an existing, non-trashed page
        // in the SAME (effective) scope and must NOT be this page or one of its
        // descendants (which would form a cycle). Runs its own Clear()/DataSet().
        if (array_key_exists('parent_id', $data) && $effParent > 0) {
            $pScopeType = array_key_exists('scope_type', $data) ? $data['scope_type'] : (string)$curRow['scope_type'];
            $pScopeId   = array_key_exists('scope_id', $data) ? (int)$data['scope_id'] : (int)$curRow['scope_id'];
            if (!$this->_parentLinkValid($effParent, $pScopeType, $pScopeId, $pageId)) {
                return false;
            }
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

        // C17/#56: capture the pre-edit row + its current full PATH up front (before
        // any binds accumulate on $DB) whenever the slug OR the parent changes —
        // either shifts this page's path, so we record a 301 from the OLD path after
        // the write. PagePath()/GetPage() run their own Clear()/DataSet(), so they
        // MUST precede the bind loop below or they would wipe the placeholders.
        $preEditRow  = $curRow;   // live row already fetched above
        $preEditPath = '';
        $pathMayChange = array_key_exists('slug', $data) || array_key_exists('parent_id', $data);
        if ($pathMayChange) {
            $preEditPath = $this->PagePath($pageId);
        }
        $newSlug = null; // normalized target slug, computed up front when set
        if (array_key_exists('slug', $data)) {
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
            // block a rename. Empty slugs, and reserved slugs on a page that stays
            // top-level (#59), are handled below (existing slug kept), so skip them.
            if (
                $newSlug !== '' && !($isTopLevel && $this->IsReservedPageSlug($newSlug))
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
        $set         = array();
        $slugChanged = false; // true when the slug column actually changes
        $DB->Clear();

        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $DB->title = (string)$data['title'];
        }
        if (array_key_exists('slug', $data)) {
            // $newSlug was normalized (and dup-checked) up front.
            // C17/#59: never persist an empty slug, or a router-shadowed one
            // (blog/post/k/p) on a page that STAYS top-level — silently keep the
            // existing slug (the controller pre-validates + surfaces a friendly
            // message). A child page (not top-level) may keep a reserved slug.
            if ($newSlug !== '' && !($isTopLevel && $this->IsReservedPageSlug($newSlug))) {
                $set[] = 'slug = :slug';
                $DB->slug = $newSlug;
                if ($preEditRow !== null && $newSlug !== (string)$preEditRow['slug']) {
                    $slugChanged = true;
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
        // #54: the deleted_at IS NULL guard means a page trashed between the top
        // existence check and here matches zero rows → ROW_COUNT() < 1 → false.
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_page SET ' . implode(', ', $set)
            . ' WHERE page_id = :page_id AND deleted_at IS NULL'
        );

        // #54: confirm a live row was actually updated. updated_at is bumped on
        // every save, so a matching non-trashed row always reports >= 1 changed
        // row; a nonexistent/trashed target reports 0. Read immediately after the
        // Execute on the same connection (before any other query).
        $DB->Clear();
        $rcRow = $this->_firstRow($DB->DataSet('SELECT ROW_COUNT() AS rc'));
        if ($rcRow === null || (int)$rcRow['rc'] < 1) {
            return false;
        }

        // C13/#51: a slug OR parent-link change invalidates memoized ancestor
        // chains (a renamed ancestor changes every descendant's PagePath()).
        if (array_key_exists('parent_id', $data) || array_key_exists('slug', $data)) {
            self::$_ancestorMemo = array();
        }

        // C17: after a slug change, verify the new slug actually LANDED before
        // trusting the rename. Execute() is void under ERRMODE_WARNING, so a
        // silently-dropped UPDATE (e.g. a racing writer claimed the tuple between
        // the pre-check and the write) leaves the old slug in place — reporting
        // success or recording a 301 would both be wrong.
        if ($slugChanged) {
            $DB->Clear();
            $DB->page_id = $pageId;
            $verify = $this->_firstRow($DB->DataSet(
                'SELECT slug FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id LIMIT 1'
            ));
            if ($verify === null || (string)$verify['slug'] !== (string)$newSlug) {
                return false;   // rename didn't take — no bogus redirect, signal failure
            }
        }

        // C17/#56: after ANY path-affecting change (slug OR parent), 301 the OLD
        // path to this page so inbound links / bookmarks keep resolving. A single
        // redirect on the moved page covers its whole descendant subtree — the
        // descendants share the old path as a prefix, which LookupRedirect resolves
        // via its prefix fallback. Best-effort (never fails the save).
        if ($pathMayChange && $preEditPath !== '' && $preEditRow !== null) {
            $newPath = $this->PagePath($pageId);
            if ($newPath !== '' && $newPath !== $preEditPath) {
                $this->RecordRedirect(
                    (string)$preEditRow['scope_type'],
                    (int)$preEditRow['scope_id'],
                    $preEditPath,
                    $pageId,
                    null,
                    (isset($data['updated_by']) && (int)$data['updated_by'] > 0) ? (int)$data['updated_by'] : 0
                );
            }
        }

        // #62: audit the content-mutating write. Scope = the effective (post-edit)
        // scope — an in-flight scope move wins, else the row's current scope.
        $auditType = array_key_exists('scope_type', $data)
            ? $this->_normalizeScopeType($data['scope_type'])
            : (string)$curRow['scope_type'];
        $auditId = array_key_exists('scope_id', $data)
            ? (int)$data['scope_id']
            : (int)$curRow['scope_id'];
        $auditActor = (isset($data['updated_by']) && (int)$data['updated_by'] > 0) ? (int)$data['updated_by'] : 0;
        $this->_cmsAudit($auditActor, 'update', 'page', $pageId, $auditType, $auditId);

        return true;
    }

    /* ------------------------------------------------------------------ *
     * C13 — page hierarchy (nested slug paths + breadcrumbs)
     * ------------------------------------------------------------------ */

    /**
     * #55: validate a proposed parent link for a page. A parent must be an
     * existing, non-trashed page in the SAME scope, and — for an already-existing
     * child ($pageId > 0) — must be neither the page itself nor one of its
     * descendants (which would form a cycle). A new page ($pageId == 0) has no
     * descendants, so only the exists+same-scope rule applies. Returns true when
     * the link is legal (a zero/absent parent is always legal — a flat page).
     *
     * @param int    $parentId  proposed parent page id
     * @param string $scopeType the child's (effective) scope_type
     * @param int    $scopeId   the child's (effective) scope_id
     * @param int    $pageId    the child page id (0 for a not-yet-created page)
     * @return bool
     */
    private function _parentLinkValid($parentId, $scopeType, $scopeId, $pageId = 0)
    {
        global $DB;

        $parentId = (int)$parentId;
        $pageId   = (int)$pageId;
        if ($parentId <= 0) {
            return true;   // no parent → flat page, always valid
        }
        if ($pageId > 0 && $parentId === $pageId) {
            return false;  // self-parent
        }

        // Parent must exist, be live, and share the (effective) scope.
        $DB->Clear();
        $DB->page_id = $parentId;
        $p = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE page_id = :page_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($p === null) {
            return false;  // no such live page
        }
        if (
            (string)$p['scope_type'] !== $this->_normalizeScopeType($scopeType)
            || (int)$p['scope_id'] !== (int)$scopeId
        ) {
            return false;  // cross-scope nesting not allowed
        }

        // Cycle guard: walk UP from the proposed parent; if we reach $pageId, the
        // parent is a descendant of the page → the link would form a cycle.
        if ($pageId > 0) {
            $cursor = $parentId;
            $seen   = array();
            $guard  = 0;
            while ($cursor > 0 && $guard++ < 50) {
                if ($cursor === $pageId) {
                    return false;
                }
                if (isset($seen[$cursor])) {
                    break;   // pre-existing corrupt loop above us — stop
                }
                $seen[$cursor] = true;
                $DB->Clear();
                $DB->page_id = $cursor;
                $r = $this->_firstRow($DB->DataSet(
                    'SELECT parent_id FROM ' . DB_PREFIX . 'cms_page WHERE page_id = :page_id LIMIT 1'
                ));
                if ($r === null) {
                    break;
                }
                $cursor = (int)($r['parent_id'] ?? 0);
            }
        }

        return true;
    }

    /**
     * Walk the parent chain for a page and return its ancestors ordered
     * root → immediate-parent (the page itself is NOT included). Cycle-guarded
     * (a corrupt parent loop stops at a bounded depth). Each entry carries
     * page_id, slug, title, status, plus a 'restricted' flag.
     *
     * GetPageByPath publish-gates only the LEAF segment (#60), so a published page
     * can legitimately sit under a DRAFT/scheduled ancestor. Any PUBLIC consumer
     * (breadcrumbs, canonical/OG, sitemap) must therefore not echo that ancestor's
     * title or slug. With $publishedOnly (the default) an ancestor that is not
     * published-and-due comes back REDACTED: slug '', a generic title, and
     * restricted => true — the row keeps its position so callers can still see
     * where the chain is broken. Pass false only for trusted/authoring contexts
     * (path building, officer preview), which need the real chain.
     *
     * @param int  $pageId
     * @param bool $publishedOnly redact ancestors that are not published + due
     * @return array list of ancestor rows (root first)
     */
    public function GetPageAncestors($pageId, $publishedOnly = true)
    {
        global $DB;

        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return array();
        }

        // Per-request memo: PagePath() + GetPageByPath() re-walk this chain per
        // render. Invalidated on any parent-link change (UpdatePage/DeletePage).
        if (array_key_exists($pageId, self::$_ancestorMemo)) {
            return $publishedOnly
                ? $this->_redactUnpublishedAncestors(self::$_ancestorMemo[$pageId])
                : self::$_ancestorMemo[$pageId];
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
                'SELECT page_id, parent_id, slug, title, status, published_at'
                . ', (status = \'published\' AND (published_at IS NULL OR published_at <= NOW())) AS is_live'
                . ' FROM ' . DB_PREFIX . 'cms_page'
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
        return $publishedOnly ? $this->_redactUnpublishedAncestors($chain) : $chain;
    }

    /**
     * Redact every ancestor row that is not published-and-due: blank the slug,
     * swap in a generic label, and mark it restricted. Positions are preserved so
     * a caller can tell WHERE the chain stops being public. See GetPageAncestors.
     *
     * @param array $chain ancestor rows (root first)
     * @return array
     */
    private function _redactUnpublishedAncestors($chain)
    {
        $out = array();
        foreach ((is_array($chain) ? $chain : array()) as $row) {
            // Due-ness is decided by the DB (is_live, selected in GetPageAncestors)
            // exactly as the GetPageByPath leaf gate does it. Never re-derive it in
            // PHP: config sets America/Chicago while the DB compares in its own
            // timezone, so strtotime() on a naive DB datetime skews by hours —
            // redacting live ancestors, and (if the DB clock leads) leaking
            // future-dated ones.
            if ((int)($row['is_live'] ?? 0) === 1) {
                $row['restricted'] = false;
            } else {
                $row['slug']       = '';
                $row['title']      = 'Private';
                $row['restricted'] = true;
            }
            $out[] = $row;
        }
        return $out;
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
        // The REAL path (including any draft ancestor's slug) — this is the routing
        // truth used for redirects/admin links. Public consumers must gate on
        // GetPageAncestors()'s published-only default before echoing it.
        foreach ($this->GetPageAncestors($pageId, false) as $anc) {
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
        $lastIdx  = count($segments) - 1;
        foreach ($segments as $idx => $seg) {
            $seg = preg_replace('/[^a-z0-9\-]+/', '', strtolower((string)$seg));
            if ($seg === '') {
                return null;
            }
            $sql = 'SELECT * FROM ' . DB_PREFIX . 'cms_page'
                . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id'
                . ' AND deleted_at IS NULL'
                . ($parentId === null ? ' AND parent_id IS NULL' : ' AND parent_id = :parent_id');
            // #60: publish-gate ONLY the LEAF. Intermediate segments are resolved
            // by slug+scope+parent WITHOUT the status filter so a nested page under
            // an unpublished (draft/scheduled) ancestor stays reachable — otherwise
            // publishing a child would silently 404 behind its unpublished parent.
            if ($publishedOnly && $idx === $lastIdx) {
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
        $fromPath = trim((string)$fromPath, '/');
        if ($fromPath === '' || !$this->_redirectTableAvailable()) {
            return null;
        }

        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        // Exact match first (preserves the historical behavior + shape).
        $row = $this->_redirectRowFor($scopeType, $scopeId, $fromPath);
        if ($row !== null) {
            $resolved = $this->_resolveRedirect($row, $fromPath, '');
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // #56: prefix fallback for a moved SUBTREE. A page move records ONE
        // redirect on the moved page's old path; its descendants share that path as
        // a prefix. Find the LONGEST ancestor prefix that has a redirect and rewrite
        // it, carrying the remaining suffix onto the target's current path. This
        // keeps every descendant URL resolving without recording a row per node.
        $parts = explode('/', $fromPath);
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $prefix = implode('/', array_slice($parts, 0, $i));
            $suffix = implode('/', array_slice($parts, $i));
            $prow   = $this->_redirectRowFor($scopeType, $scopeId, $prefix);
            if ($prow === null) {
                continue;
            }
            $resolved = $this->_resolveRedirect($prow, $fromPath, $suffix);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Fetch the raw redirect row for an EXACT (scope, from_path), or null.
     *
     * @return array|null ['to_page_id','to_url','code'] or null
     */
    private function _redirectRowFor($scopeType, $scopeId, $fromPath)
    {
        global $DB;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = (int)$scopeId;
        $DB->from_path  = (string)$fromPath;
        return $this->_firstRow($DB->DataSet(
            'SELECT to_page_id, to_url, code FROM ' . DB_PREFIX . 'cms_redirect'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND from_path = :from_path LIMIT 1'
        ));
    }

    /**
     * Resolve a raw redirect row into a target ['url','code','to_page_id'], or
     * null when unusable (dead page target, empty to_url, or a self-resolving
     * path). $suffix (possibly '') is the descendant tail appended after the
     * target's current path/url for the #56 prefix fallback.
     *
     * @param array  $row      raw redirect row (to_page_id/to_url/code)
     * @param string $fromPath the requested path (for the self-resolve guard)
     * @param string $suffix   descendant tail to append ('' for an exact hit)
     * @return array|null
     */
    private function _resolveRedirect($row, $fromPath, $suffix)
    {
        $code   = ((int)$row['code'] === 302) ? 302 : 301;
        $suffix = trim((string)$suffix, '/');

        $toPageId = (int)($row['to_page_id'] ?? 0);
        if ($toPageId > 0) {
            $target = $this->GetPage($toPageId);
            if ($target === null) {
                return null; // dead target — skip (fall through to 404)
            }
            $path = $this->PagePath($toPageId);
            if ($path === '') {
                return null;
            }
            if ($suffix !== '') {
                $path .= '/' . $suffix;
            }
            if ($path === $fromPath) {
                return null; // resolves to itself — no redirect
            }
            return array('url' => $path, 'code' => $code, 'to_page_id' => $toPageId);
        }

        $toUrl = (string)($row['to_url'] ?? '');
        if ($toUrl === '') {
            return null;
        }
        if ($suffix !== '') {
            $toUrl = rtrim($toUrl, '/') . '/' . $suffix;
        }
        if ($toUrl === $fromPath) {
            return null; // resolves to itself
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
            // C1/#9: a soft-delete leaves page.updated_at untouched, so bust the
            // cached block set explicitly (public reads gate deleted_at anyway, but
            // this keeps the cache from holding a trashed page's blocks).
            $this->_bustPageWithBlocksCache($pageId);
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
        $ok = $this->_restore('cms_page', 'page_id', $pageId, $scopeType, $scopeId, $actorId, 'page');

        // C1/#9: restore leaves page.updated_at untouched — bust the cached block
        // set so the just-restored page serves fresh (a restored parent also frees
        // its flattened children's ancestor memo).
        if ($ok) {
            self::$_ancestorMemo = array();
            $this->_bustPageWithBlocksCache($pageId);
        }

        return $ok;
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
     * @param int    $actorId     acting mundane_id (#121: stamped as the owner's
     *                            updated_by + attributed to the revision; 0 = unknown,
     *                            leaves updated_by untouched). Optional so existing
     *                            callers are unaffected.
     * @return int number of blocks stored (-1 on partial-write rollback)
     */
    public function ReplaceBlocks($ownerType, $ownerId, $blocksArray, $actorId = 0)
    {
        global $DB;

        $ownerType = ($ownerType === 'post') ? 'post' : 'page';
        $ownerId = (int)$ownerId;
        $actorId = (int)$actorId;

        // C3: normalize + sanitize up front, outside the transaction.
        $normalized = $this->_normalizeBlocks($blocksArray);

        // C1/#9/#121: the owner's updated_at is now bumped inside the txn below
        // (#121), which RE-KEYS the GetPageWithBlocks cache. Bust the CURRENT
        // (old-updated_at) key BEFORE the write — mirroring UpdatePage — so the
        // pre-edit block set can't keep serving; the new updated_at then makes
        // subsequent reads self-fresh. Pages only (posts aren't PWB-cached).
        if ($ownerType === 'page') {
            $this->_bustPageWithBlocksCache($ownerId);
        }

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

        // #121: stamp the owning page/post row (updated_at = now, updated_by =
        // actor) so a block-only edit surfaces in ListPages/ListPosts ORDER BY
        // updated_at, and — crucially BEFORE the snapshot below — so the revision
        // authorship (_ownerMeta reads updated_by) is attributed to the actor.
        // Inside the txn so a rollback reverts it too.
        $this->_stampOwnerWrite($ownerType, $ownerId, $actorId);

        // C2: snapshot the state we just wrote (inside the txn so a rollback
        // would discard it too), then prune to the retention cap.
        $this->_snapshotRevision($ownerType, $ownerId, $normalized);

        $DB->Clear();
        $DB->Execute('COMMIT');

        // #62: audit the content-mutating block write (not just delete/restore/
        // publish). Best-effort, post-commit; scope resolved from the owner row.
        $ownerScope = $this->_ownerScope($ownerType, $ownerId);
        if ($ownerScope !== null) {
            $this->_cmsAudit($actorId, 'blocks_replace', $ownerType, $ownerId, $ownerScope['type'], $ownerScope['id']);
        }

        return $expected;
    }

    /**
     * #121: stamp an owner (page/post) row's updated_at (always) + updated_by
     * (only when a real actor is known — yapo drops null, and 0 would clobber a
     * legitimate prior author). Called INSIDE the ReplaceBlocks transaction.
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @param int    $actorId   acting mundane_id (0 = unknown → updated_by left as-is)
     * @return void
     */
    private function _stampOwnerWrite($ownerType, $ownerId, $actorId)
    {
        global $DB;

        $table = ($ownerType === 'post') ? 'cms_post' : 'cms_page';
        $pk    = ($ownerType === 'post') ? 'post_id' : 'page_id';

        $set = 'updated_at = :updated_at';
        $DB->Clear();
        $DB->updated_at = date('Y-m-d H:i:s');
        if ((int)$actorId > 0) {
            $set .= ', updated_by = :updated_by';
            $DB->updated_by = (int)$actorId;
        }
        $DB->owner_pk = (int)$ownerId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . $table . ' SET ' . $set . ' WHERE ' . $pk . ' = :owner_pk'
        );
    }

    /**
     * The (scope_type, scope_id) of a page/post owner, for the block-write audit
     * trail. Returns ['type'=>string,'id'=>int] or null when the owner is gone.
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @return array|null
     */
    private function _ownerScope($ownerType, $ownerId)
    {
        global $DB;

        $table = ($ownerType === 'post') ? 'cms_post' : 'cms_page';
        $pk    = ($ownerType === 'post') ? 'post_id' : 'page_id';

        $DB->Clear();
        $DB->owner_pk = (int)$ownerId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $pk . ' = :owner_pk LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }
        return array('type' => (string)$row['scope_type'], 'id' => (int)$row['scope_id']);
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
     * transaction. Returns ['kept'=>[id=>true], 'keptFields'=>[id=>['type',
     * 'ordering','enabled','source','fields_json']], 'inserts'=>[normalized,...]].
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
                // #41: carry the full intended row (not just fields_json) so the
                // post-write verify can also compare type/ordering/enabled/source.
                $keptFields[$n['id']] = array(
                    'type'        => $n['type'],
                    'ordering'    => (int)$n['ordering'],
                    'enabled'     => (int)$n['enabled'],
                    'source'      => $n['source'],
                    'fields_json' => $n['fields_json'],
                );
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
     * COUNT(*) equals kept+inserted, and (2) each kept block was stored as
     * intended — #41 compares type, ordering, enabled and source (not just the
     * fields), so a silently-dropped UPDATE that leaves the row (and thus the
     * count) unchanged under ERRMODE_WARNING is caught for ANY column, not only
     * fields_json. #43 compares the DECODED fields (json_decode(stored) ==
     * json_decode(intended)) rather than the raw strings: a native-JSON column
     * canonicalizes key order / whitespace on storage, so an exact string compare
     * could false-fail even on a correct write. Returns false → caller ROLLBACKs.
     * Called inside the ReplaceBlocks transaction.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param int    $expected   count(kept) + count(inserts)
     * @param array  $keptFields map of kept block_id => ['type','ordering',
     *                           'enabled','source','fields_json'] (intended)
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
            $stored = array();
            foreach ($this->_eachRow($DB->DataSet(
                'SELECT block_id, type, ordering, enabled, source, fields_json FROM ' . DB_PREFIX . 'cms_block'
                . ' WHERE owner_type = :owner_type AND owner_id = :owner_id'
                . ' AND block_id IN (' . $keptIdList . ')'
            )) as $vr) {
                $stored[(int)$vr['block_id']] = $vr;
            }
            foreach ($keptFields as $bid => $intended) {
                $bid = (int)$bid;
                if (!isset($stored[$bid])) {
                    return false;
                }
                $s = $stored[$bid];
                // #41: every persisted column must match intent.
                if ((string)$s['type'] !== (string)$intended['type']) {
                    return false;
                }
                if ((int)$s['ordering'] !== (int)$intended['ordering']) {
                    return false;
                }
                if ((int)$s['enabled'] !== (int)$intended['enabled']) {
                    return false;
                }
                if ((string)$s['source'] !== (string)$intended['source']) {
                    return false;
                }
                // #43: order-insensitive JSON compare (native-JSON canonicalizes).
                $storedJson   = json_decode(isset($s['fields_json']) ? (string)$s['fields_json'] : '', true);
                $intendedJson = json_decode((string)$intended['fields_json'], true);
                if ($storedJson != $intendedJson) {
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
            } elseif (is_string($val) && in_array($key, self::HTML_FIELDS, true)) {
                $fields[$key] = CmsSanitizer::Clean($val);
            } elseif (is_string($val) && in_array($key, self::URL_FIELDS, true)) {
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
     * turn snapshots the restored state, so history is never lost). #53: the
     * snapshotted META (title/slug/status/published_at, plus hero when captured)
     * is also re-applied — through UpdatePage/UpdatePost so slug-uniqueness and
     * the redirect trail are honored (best-effort: a slug now taken by a live row
     * leaves the meta as-is rather than failing the block restore).
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
            'SELECT blocks_json, meta_json FROM ' . DB_PREFIX . 'cms_revision'
            . ' WHERE revision_id = :revision_id AND owner_type = :owner_type AND owner_id = :owner_id LIMIT 1'
        ));
        if ($row === null) {
            return false;   // not found, or belongs to a different owner
        }

        // #52: a corrupt/unparseable blocks_json is a HARD failure — restoring to
        // an empty block set would silently wipe the owner's content. Bail out and
        // leave the current content intact.
        $blocks = json_decode(isset($row['blocks_json']) ? (string)$row['blocks_json'] : '', true);
        if (!is_array($blocks)) {
            return false;
        }

        $ok = ($this->ReplaceBlocks($ownerType, $ownerId, $blocks) >= 0);
        if (!$ok) {
            return false;
        }

        // #53: re-apply the snapshotted meta. Only keys the snapshot actually
        // captured are applied; the Update* path guards slug uniqueness + records
        // the redirect trail. Best-effort — a meta clash never undoes the block
        // restore we already committed.
        $meta = array();
        if (!empty($row['meta_json'])) {
            $decodedMeta = json_decode((string)$row['meta_json'], true);
            if (is_array($decodedMeta)) {
                $meta = $decodedMeta;
            }
        }
        $metaApply = array();
        foreach (array('title', 'slug', 'status', 'published_at', 'hero_media_id') as $k) {
            if (array_key_exists($k, $meta)) {
                $metaApply[$k] = $meta[$k];
            }
        }
        if (!empty($metaApply)) {
            if ($ownerType === 'post') {
                $this->_posts()->UpdatePost($ownerId, $metaApply);
            } else {
                $this->UpdatePage($ownerId, $metaApply);
            }
        }

        return true;
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

        // #13: include parent_id so the admin list can resolve nested live URLs
        // via an in-memory path map instead of a per-row PagePath() DB walk (N+1).
        $sql = 'SELECT page_id, parent_id, slug, type, title, status, updated_at'
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

    /* ------------------------------------------------------------------ *
     * E117/#117 — maintenance cleanup (hard-purge trashed + orphan sweep)
     * ------------------------------------------------------------------ */

    /**
     * E117/#117: HARD-delete pages that have been soft-deleted (deleted_at NOT
     * NULL) for longer than $olderThanDays, together with their blocks and
     * revisions, then run the orphan-block sweep. NON-DESTRUCTIVE to live content:
     * only rows already trashed past the cutoff (and blocks whose owner is truly
     * gone) are removed — a live or recently-trashed (still restorable) page is
     * never touched. Idempotent and safe to re-run.
     *
     * @param int $olderThanDays retention window in days (clamped to >= 0)
     * @return array counts ['pages','blocks','revisions','orphan_blocks']
     */
    public function PurgeTrashed($olderThanDays = 30)
    {
        global $DB;

        $days = max(0, (int)$olderThanDays);
        $out  = array('pages' => 0, 'blocks' => 0, 'revisions' => 0, 'orphan_blocks' => 0);

        // Cutoff is a code-controlled int → inline (INTERVAL n DAY can't be bound
        // portably through the emulated prepare layer). Collect the doomed page ids
        // first so the block/revision deletes target exactly this set.
        $DB->Clear();
        $ids = array();
        foreach ($this->_eachRow($DB->DataSet(
            'SELECT page_id FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL ' . $days . ' DAY)'
        )) as $row) {
            $ids[] = (int)$row['page_id'];
        }

        if (!empty($ids)) {
            $idList = implode(',', array_map('intval', $ids));

            // Blocks owned by the doomed pages.
            $DB->Clear();
            $DB->Execute(
                'DELETE FROM ' . DB_PREFIX . 'cms_block'
                . " WHERE owner_type = 'page' AND owner_id IN (" . $idList . ')'
            );
            $out['blocks'] = $this->_affectedRows();

            // Revisions owned by the doomed pages (table is migration-gated).
            if ($this->_tableExists(DB_PREFIX . 'cms_revision')) {
                $DB->Clear();
                $DB->Execute(
                    'DELETE FROM ' . DB_PREFIX . 'cms_revision'
                    . " WHERE owner_type = 'page' AND owner_id IN (" . $idList . ')'
                );
                $out['revisions'] = $this->_affectedRows();
            }

            // The pages themselves — re-assert the trashed+cutoff predicate so a
            // page restored between the SELECT and here is never hard-deleted.
            $DB->Clear();
            $DB->Execute(
                'DELETE FROM ' . DB_PREFIX . 'cms_page'
                . ' WHERE page_id IN (' . $idList . ')'
                . ' AND deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL ' . $days . ' DAY)'
            );
            $out['pages'] = $this->_affectedRows();
        }

        $out['orphan_blocks'] = $this->SweepOrphanBlocks();

        return $out;
    }

    /**
     * E117/#117: delete ork_cms_block rows whose owner page/post no longer exists
     * (the owner row is ABSENT). Blocks of a still-present-but-trashed owner are
     * DELIBERATELY retained so a soft-deleted page/post stays restorable with its
     * content — those are reclaimed only once PurgeTrashed hard-deletes the aged-out
     * owner. Idempotent and non-destructive to any live/restorable content.
     *
     * @return int number of orphaned block rows removed
     */
    public function SweepOrphanBlocks()
    {
        global $DB;

        $DB->Clear();
        $DB->Execute(
            'DELETE b FROM ' . DB_PREFIX . 'cms_block b'
            . ' LEFT JOIN ' . DB_PREFIX . "cms_page pg ON b.owner_type = 'page' AND pg.page_id = b.owner_id"
            . ' LEFT JOIN ' . DB_PREFIX . "cms_post po ON b.owner_type = 'post' AND po.post_id = b.owner_id"
            . " WHERE (b.owner_type = 'page' AND pg.page_id IS NULL)"
            . "    OR (b.owner_type = 'post' AND po.post_id IS NULL)"
        );
        return $this->_affectedRows();
    }

    /**
     * Affected-row count of the immediately-preceding write on the shared $DB
     * connection (Execute() is void under ERRMODE_WARNING). Read via ROW_COUNT()
     * before any other query intervenes.
     *
     * @return int
     */
    private function _affectedRows()
    {
        global $DB;

        $DB->Clear();
        $row = $this->_firstRow($DB->DataSet('SELECT ROW_COUNT() AS rc'));
        return ($row !== null && isset($row['rc'])) ? (int)$row['rc'] : 0;
    }

}
