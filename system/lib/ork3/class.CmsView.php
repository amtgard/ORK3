<?php

/*************************************************************************
 * CmsView — built-in CMS usage analytics (#09).
 *
 * Backs a lightweight, per-day page/post view counter surfaced on the CMS
 * dashboard so officers get feedback that their content is actually being
 * read — no third-party analytics, no PII.
 *
 * Storage (ork_cms_view): one row per (scope_type, scope_id, entity_type,
 * entity_id, day) with a `views` tally. A public render fires a single
 * upsert (INSERT ... ON DUPLICATE KEY UPDATE views = views + 1) so the hot
 * path is one cheap indexed write. Per-day granularity lets the dashboard
 * show an all-time total AND a rolling "last 30 days" from the same table.
 *
 * BEST-EFFORT WRITE: RecordView never blocks or fails the render it is
 * called from — a missing table (pre-migration) is detected once per request
 * and skipped silently, and any write error is swallowed. The controller ALSO
 * gates on !preview + !bot + GET before calling; this lib is the storage layer.
 *
 * DB idiom (matches CmsNav/CmsPage): shared global $DB (YapoDb). Always
 * Clear() before a raw DataSet()/Execute(); bind via $DB->field = ...
 * (becomes :field) so nothing is concatenated unescaped. Rows are driven off
 * Next() via _firstRow/_eachRow (Size() is unreliable on PDO).
 *
 * LOAD-ORDER NOTE: 'CmsView' sorts AFTER 'CmsBase' in scandir()/alphabetical
 * order, so — like CmsMedia/CmsNav/CmsPage/CmsPost — it needs no explicit
 * require of CmsBase (only CmsAuth, which sorts before CmsBase, does).
 *************************************************************************/

class CmsView extends CmsBase
{
    /** Supported entity types (matches the ork_cms_view.entity_type ENUM). */
    private static $ENTITY_TYPES = array('page', 'post');

    /** Rolling window (days) for the "recent" tally surfaced as "last 30 days". */
    public const RECENT_DAYS = 30;

    public function __construct()
    {
        parent::__construct();
    }

    /* ====================================================================
     * WRITE
     * ================================================================== */

    /**
     * Record ONE view of a page/post in the given scope (today's counter).
     *
     * Best-effort and fire-and-forget: this is called from the public render
     * path and must never slow or break it. A pre-migration missing table is
     * skipped silently (one probe per request), and every error is swallowed.
     * The caller is responsible for the exclusion policy (preview / bot / non-GET)
     * — by the time we get here the view is assumed countable.
     *
     * @param string $scopeType  'global'|'kingdom'|'park'
     * @param int    $scopeId    scope owner id (0 for global)
     * @param string $entityType 'page'|'post'
     * @param int    $entityId   page_id / post_id
     * @return void
     */
    public function RecordView($scopeType, $scopeId, $entityType, $entityId)
    {
        global $DB;

        $entityType = $this->_normalizeEntityType($entityType);
        $entityId   = (int)$entityId;
        if ($entityId <= 0) {
            return;
        }

        try {
            // Silent no-op before the analytics migration has been applied.
            if (!$this->_tableExists(DB_PREFIX . 'cms_view')) {
                return;
            }

            $DB->Clear();
            $DB->scope_type  = $this->_normalizeScopeType($scopeType);
            $DB->scope_id    = (int)$scopeId;
            $DB->entity_type = $entityType;
            $DB->entity_id   = $entityId;
            // CURDATE() keys the day server-side; the UNIQUE (scope,entity,day)
            // turns a repeat view into a single +1 on the existing counter.
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_view'
                . ' (scope_type, scope_id, entity_type, entity_id, `day`, views)'
                . ' VALUES (:scope_type, :scope_id, :entity_type, :entity_id, CURDATE(), 1)'
                . ' ON DUPLICATE KEY UPDATE views = views + 1'
            );
        } catch (\Throwable $e) {
            // Best-effort only — an analytics-write failure must never surface to
            // (or slow) the public render that triggered it.
        }
    }

    /* ====================================================================
     * READ (dashboard)
     * ================================================================== */

    /**
     * Scope-wide rollup for the dashboard masthead ("X views this month").
     *
     * @param string $scopeType
     * @param int    $scopeId
     * @return array{total:int,recent:int,recent_days:int}
     *         total  = all-time views in scope; recent = last RECENT_DAYS days.
     */
    public function GetScopeViewSummary($scopeType, $scopeId)
    {
        global $DB;

        $out = array('total' => 0, 'recent' => 0, 'recent_days' => self::RECENT_DAYS);

        try {
            if (!$this->_tableExists(DB_PREFIX . 'cms_view')) {
                return $out;
            }

            $DB->Clear();
            $DB->scope_type  = $this->_normalizeScopeType($scopeType);
            $DB->scope_id    = (int)$scopeId;
            $DB->recent_days = self::RECENT_DAYS - 1;   // inclusive window (today + N-1 prior days)
            $row = $this->_firstRow($DB->DataSet(
                'SELECT COALESCE(SUM(views), 0) AS total,'
                . ' COALESCE(SUM(CASE WHEN `day` >= (CURDATE() - INTERVAL :recent_days DAY)'
                . ' THEN views ELSE 0 END), 0) AS recent'
                . ' FROM ' . DB_PREFIX . 'cms_view'
                . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
            ));
            if ($row !== null) {
                $out['total']  = (int)$row['total'];
                $out['recent'] = (int)$row['recent'];
            }
        } catch (\Throwable $e) {
            // Read failure → zeros; the dashboard degrades to "no data".
        }

        return $out;
    }

    /**
     * The most-viewed pages/posts in a scope, for the dashboard "Most viewed"
     * card. Joins to the live entity so a deleted/missing page never appears and
     * so each row carries its human title + slug. Ordered by all-time views DESC.
     *
     * Entity resolution is SCOPE-BOUND (join predicate pins scope_type/scope_id),
     * so a stray cross-scope entity_id can never leak another org's title/slug.
     * Soft-deleted entities (deleted_at) are excluded, and a row whose entity no
     * longer resolves is dropped via HAVING.
     *
     * @param string $scopeType
     * @param int    $scopeId
     * @param int    $limit  max rows (clamped 1..50)
     * @return array list of
     *   ['entity_type','entity_id','title','slug','total','recent'] (recent = last RECENT_DAYS days)
     */
    public function GetViewStats($scopeType, $scopeId, $limit = 8)
    {
        global $DB;

        $limit = (int)$limit;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $out = array();

        try {
            if (!$this->_tableExists(DB_PREFIX . 'cms_view')) {
                return $out;
            }

            $DB->Clear();
            $DB->scope_type  = $this->_normalizeScopeType($scopeType);
            $DB->scope_id    = (int)$scopeId;
            $DB->recent_days = self::RECENT_DAYS - 1;
            // LIMIT is an int literal we clamped above (never user-concatenated
            // as a string) — bound placeholders aren't reliable for LIMIT under
            // this PDO driver, so inline the sanitized int.
            $rows = $this->_eachRow($DB->DataSet(
                'SELECT v.entity_type,'
                . ' v.entity_id,'
                . ' COALESCE(SUM(v.views), 0) AS total,'
                . ' COALESCE(SUM(CASE WHEN v.`day` >= (CURDATE() - INTERVAL :recent_days DAY)'
                . ' THEN v.views ELSE 0 END), 0) AS recent,'
                . ' COALESCE(pg.title, po.title) AS title,'
                . ' COALESCE(pg.slug, po.slug) AS slug'
                . ' FROM ' . DB_PREFIX . 'cms_view v'
                . ' LEFT JOIN ' . DB_PREFIX . 'cms_page pg'
                . "   ON v.entity_type = 'page' AND pg.page_id = v.entity_id"
                . '   AND pg.scope_type = v.scope_type AND pg.scope_id = v.scope_id'
                . '   AND pg.deleted_at IS NULL'
                . ' LEFT JOIN ' . DB_PREFIX . 'cms_post po'
                . "   ON v.entity_type = 'post' AND po.post_id = v.entity_id"
                . '   AND po.scope_type = v.scope_type AND po.scope_id = v.scope_id'
                . '   AND po.deleted_at IS NULL'
                . ' WHERE v.scope_type = :scope_type AND v.scope_id = :scope_id'
                . ' GROUP BY v.entity_type, v.entity_id'
                // Drop entities that no longer resolve to a live page/post.
                . ' HAVING title IS NOT NULL'
                . ' ORDER BY total DESC, recent DESC'
                . ' LIMIT ' . $limit
            ));
            foreach ($rows as $row) {
                $out[] = array(
                    'entity_type' => $this->_normalizeEntityType($row['entity_type']),
                    'entity_id'   => (int)$row['entity_id'],
                    'title'       => (string)$row['title'],
                    'slug'        => (string)$row['slug'],
                    'total'       => (int)$row['total'],
                    'recent'      => (int)$row['recent'],
                );
            }
        } catch (\Throwable $e) {
            // Read failure → empty list; the card renders its empty state.
        }

        return $out;
    }

    /* ====================================================================
     * INTERNAL
     * ================================================================== */

    /** Clamp entity_type to the supported enum (default 'page'). */
    private function _normalizeEntityType($entityType)
    {
        $entityType = strtolower(trim((string)$entityType));
        return in_array($entityType, self::$ENTITY_TYPES, true) ? $entityType : 'page';
    }
}
