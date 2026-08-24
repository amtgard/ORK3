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
 * and skipped silently, and any write error is swallowed. The COUNTING POLICY
 * (!preview + GET + !bot) lives here too, in IsCountableView(), which
 * RecordView applies itself — every entry point that renders CMS content
 * counts identically, whatever the caller (Site, Page, Blog, a future app).
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

    /**
     * Guard so fastcgi_finish_request() is invoked at most once per request even
     * when several views are recorded (each defers its own shutdown writer).
     * @var bool
     */
    private static $_clientFlushed = false;

    public function __construct()
    {
        parent::__construct();
    }

    /* ====================================================================
     * WRITE
     * ================================================================== */

    /**
     * THE counting policy: does this request count as one public content view?
     *
     * Every entry point that renders CMS content hands us the same raw request
     * facts and gets the same answer, so the dashboard's numbers mean exactly
     * one thing no matter who rendered the page.
     *
     * A view counts only when ALL hold:
     *   - NOT a preview render — an officer previewing their own unpublished
     *     content is not a public visitor;
     *   - a GET request — POST/HEAD/etc. are never a content view;
     *   - NOT an obvious bot/crawler/link-unfurler (user-agent heuristic).
     *
     * @param array $ctx ['is_preview'=>bool, 'method'=>string, 'user_agent'=>string]
     *                   A missing 'method'/'user_agent' falls back to $_SERVER, so a
     *                   caller that reports nothing still gets the real request facts;
     *                   'is_preview' is the one thing only the caller can know (missing
     *                   → not a preview).
     * @return bool
     */
    public static function IsCountableView($ctx)
    {
        $ctx = is_array($ctx) ? $ctx : array();

        if (!empty($ctx['is_preview'])) {
            return false;   // officer preview — not a public view
        }
        $method = strtoupper(trim((string)($ctx['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET')));
        if ($method !== 'GET') {
            return false;   // only a GET render counts as a view
        }
        if (self::_isProbablyBot((string)($ctx['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''))) {
            return false;   // crawlers / unfurlers / monitors don't count
        }

        return true;
    }

    /**
     * Record ONE view of a page/post in the given scope (today's counter).
     *
     * Best-effort and fire-and-forget: this is called from the public render
     * path and must never slow or break it. A pre-migration missing table is
     * skipped silently (one probe per request), and every error is swallowed.
     * The exclusion policy is applied HERE via IsCountableView($ctx) — the
     * caller only reports the raw request facts and never decides what counts.
     *
     * @param string $scopeType  'global'|'kingdom'|'park'
     * @param int    $scopeId    scope owner id (0 for global)
     * @param string $entityType 'page'|'post'
     * @param int    $entityId   page_id / post_id
     * @param array  $ctx        request facts for the policy gate — see
     *                           IsCountableView(). The gate always runs; an
     *                           omitted/null ctx just means method + user-agent
     *                           come from $_SERVER and the render is not a preview.
     * @return void
     */
    public function RecordView($scopeType, $scopeId, $entityType, $entityId, $ctx = null)
    {
        if (!self::IsCountableView($ctx)) {
            return;
        }
        $entityType = $this->_normalizeEntityType($entityType);
        $entityId   = (int)$entityId;
        if ($entityId <= 0) {
            return;
        }
        // Normalize eagerly so the deferred closure captures clean scalars only.
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        // #-perf: move the counter write OUT of the render critical path. A
        // trending page previously serialized concurrent requests on the
        // INSERT ... ON DUPLICATE KEY row lock BEFORE the template rendered.
        // Instead, register the +1 to run at shutdown — after the response body
        // has been produced — and, when the SAPI supports it, flush + close the
        // client connection first (fastcgi_finish_request) so the visitor never
        // waits on the analytics write at all. The write stays best-effort: any
        // failure (incl. a pre-migration missing table) is swallowed downstream.
        // A closure declared here is bound to the class scope, so it can reach
        // the private writer despite running from global shutdown context.
        register_shutdown_function(function () use ($scopeType, $scopeId, $entityType, $entityId) {
            if (!self::$_clientFlushed && function_exists('fastcgi_finish_request')) {
                self::$_clientFlushed = true;
                @fastcgi_finish_request();
            }
            $this->_recordViewNow($scopeType, $scopeId, $entityType, $entityId);
        });
    }

    /**
     * The actual counter upsert, run AFTER the response is flushed (see
     * RecordView). Best-effort and fire-and-forget: a pre-migration missing
     * table is skipped silently and every error is swallowed — nothing here may
     * ever surface, because by shutdown the response is already on the wire.
     *
     * @param string $scopeType  normalized scope type
     * @param int    $scopeId
     * @param string $entityType normalized entity type
     * @param int    $entityId
     * @return void
     */
    private function _recordViewNow($scopeType, $scopeId, $entityType, $entityId)
    {
        global $DB;

        try {
            // Silent no-op before the analytics migration has been applied.
            if (!$this->_tableExists(DB_PREFIX . 'cms_view')) {
                return;
            }

            $DB->Clear();
            $DB->scope_type  = $scopeType;
            $DB->scope_id    = (int)$scopeId;
            $DB->entity_type = $entityType;
            $DB->entity_id   = (int)$entityId;
            // CURDATE() keys the day server-side; the UNIQUE (scope,entity,day)
            // turns a repeat view into a single +1 on the existing counter.
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_view'
                . ' (scope_type, scope_id, entity_type, entity_id, `day`, views)'
                . ' VALUES (:scope_type, :scope_id, :entity_type, :entity_id, CURDATE(), 1)'
                . ' ON DUPLICATE KEY UPDATE views = views + 1'
            );
        } catch (\Throwable $e) {
            // Best-effort only — an analytics-write failure must never surface.
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
            // #115: bind the rollup to LIVE entities the same way GetViewStats does
            // (scope-bound join + deleted_at IS NULL, keeping only rows whose
            // page/post still resolves). Without this, the masthead total counted
            // views of deleted pages while the "most viewed" card — which joins to
            // live entities — did not, so the two figures disagreed. Scope-bound
            // joins also stop a stray cross-scope entity_id from inflating the tally.
            $row = $this->_firstRow($DB->DataSet(
                'SELECT COALESCE(SUM(v.views), 0) AS total,'
                . ' COALESCE(SUM(CASE WHEN v.`day` >= (CURDATE() - INTERVAL :recent_days DAY)'
                . ' THEN v.views ELSE 0 END), 0) AS recent'
                . ' FROM ' . DB_PREFIX . 'cms_view v'
                . $this->_liveEntityJoinSql()
                . ' WHERE v.scope_type = :scope_type AND v.scope_id = :scope_id'
                // Keep only view rows whose entity still resolves to a live page/post.
                . ' AND COALESCE(pg.page_id, po.post_id) IS NOT NULL'
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

        $limit = $this->_clampInt($limit, 1, 50);

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
                . $this->_liveEntityJoinSql()
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

    /**
     * #128: per-entity all-time view totals for a set of ids, so the page/post
     * ADMIN LISTS can show a "N views" column without an N+1. Scope-bound (a
     * stray cross-scope entity_id can't leak a count) and best-effort (missing
     * table / read error → empty map).
     *
     * @param string $scopeType
     * @param int    $scopeId
     * @param string $entityType 'page'|'post'
     * @param int[]  $ids
     * @return array<int,int> entityId => total views (ids with no rows omitted)
     */
    public function GetCountsForEntities($scopeType, $scopeId, $entityType, $ids)
    {
        global $DB;

        $out = array();
        $entityType = $this->_normalizeEntityType($entityType);

        // Dedup + int-cast the id set; anything non-positive is dropped.
        $clean = array();
        foreach ((is_array($ids) ? $ids : array()) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        if (empty($clean)) {
            return $out;
        }

        try {
            if (!$this->_tableExists(DB_PREFIX . 'cms_view')) {
                return $out;
            }

            // Ids are sanitized ints (never user-concatenated strings), so the IN
            // list is inlined — the same idiom this lib uses for LIMIT, and the
            // reason a single batched read replaces one query per row.
            $inList = implode(',', array_map('intval', array_keys($clean)));

            $DB->Clear();
            $DB->scope_type  = $this->_normalizeScopeType($scopeType);
            $DB->scope_id    = (int)$scopeId;
            $DB->entity_type = $entityType;
            $rows = $this->_eachRow($DB->DataSet(
                'SELECT entity_id, COALESCE(SUM(views), 0) AS total'
                . ' FROM ' . DB_PREFIX . 'cms_view'
                . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
                . ' AND entity_type = :entity_type'
                . ' AND entity_id IN (' . $inList . ')'
                . ' GROUP BY entity_id'
            ));
            foreach ($rows as $row) {
                $out[(int)$row['entity_id']] = (int)$row['total'];
            }
        } catch (\Throwable $e) {
            // Read failure → empty map; the list renders without counts.
        }

        return $out;
    }

    /**
     * #128: the top pages/posts by views over the last $days, for the dashboard
     * "Top content (30 days)" panel. Scope-bound joins to the LIVE entity so a
     * deleted/foreign entity never appears and each row carries its title + slug.
     *
     * @param string $scopeType
     * @param int    $scopeId
     * @param int    $days   rolling window (clamped 1..3650)
     * @param int    $limit  max rows (clamped 1..50)
     * @return array list of ['entity_type','entity_id','title','slug','count']
     */
    public function GetTopContent($scopeType, $scopeId, $days = self::RECENT_DAYS, $limit = 5)
    {
        global $DB;

        // NOT _clampInt: a sub-1 window falls back to RECENT_DAYS, not to 1.
        $days = (int)$days;
        if ($days < 1) {
            $days = self::RECENT_DAYS;
        }
        if ($days > 3650) {
            $days = 3650;
        }
        $limit = $this->_clampInt($limit, 1, 50);

        $out = array();

        try {
            if (!$this->_tableExists(DB_PREFIX . 'cms_view')) {
                return $out;
            }

            $DB->Clear();
            $DB->scope_type  = $this->_normalizeScopeType($scopeType);
            $DB->scope_id    = (int)$scopeId;
            $DB->win_days    = $days - 1;   // inclusive window (today + N-1 prior days)
            // $limit is a clamped int literal (see GetViewStats for the rationale).
            $rows = $this->_eachRow($DB->DataSet(
                'SELECT v.entity_type,'
                . ' v.entity_id,'
                . ' COALESCE(SUM(v.views), 0) AS cnt,'
                . ' COALESCE(pg.title, po.title) AS title,'
                . ' COALESCE(pg.slug, po.slug) AS slug'
                . ' FROM ' . DB_PREFIX . 'cms_view v'
                . $this->_liveEntityJoinSql()
                . ' WHERE v.scope_type = :scope_type AND v.scope_id = :scope_id'
                . ' AND v.`day` >= (CURDATE() - INTERVAL :win_days DAY)'
                . ' GROUP BY v.entity_type, v.entity_id'
                . ' HAVING title IS NOT NULL'
                . ' ORDER BY cnt DESC, v.entity_id ASC'
                . ' LIMIT ' . $limit
            ));
            foreach ($rows as $row) {
                $out[] = array(
                    'entity_type' => $this->_normalizeEntityType($row['entity_type']),
                    'entity_id'   => (int)$row['entity_id'],
                    'title'       => (string)$row['title'],
                    'slug'        => (string)$row['slug'],
                    'count'       => (int)$row['cnt'],
                );
            }
        } catch (\Throwable $e) {
            // Read failure → empty list; the panel renders its empty state.
        }

        return $out;
    }

    /* ====================================================================
     * INTERNAL
     * ================================================================== */

    /**
     * Lightweight user-agent bot heuristic behind IsCountableView(). Deliberately
     * simple (no third-party lists): matches common crawler / link-unfurler /
     * uptime-monitor / scripting tokens, and treats a MISSING user-agent as
     * non-human (bots and scripts routinely omit it). False negatives are
     * acceptable — the goal is directional feedback, not audited analytics.
     *
     * @param string $ua
     * @return bool
     */
    private static function _isProbablyBot($ua)
    {
        $ua = (string)$ua;
        if (trim($ua) === '') {
            return true;
        }
        return (bool)preg_match(
            '~(bot|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|'
            . 'quora link preview|pinterest|slackbot|twitterbot|telegrambot|whatsapp|'
            . 'discordbot|linkedinbot|skypeuripreview|preview|monitor|uptime|pingdom|'
            . 'statuscake|curl|wget|python-requests|python-urllib|go-http-client|'
            . 'java/|okhttp|headless|phantomjs|lighthouse|scrapy|apache-httpclient)~i',
            $ua
        );
    }

    /** Clamp entity_type to the supported enum (default 'page'). */
    private function _normalizeEntityType($entityType)
    {
        $entityType = strtolower(trim((string)$entityType));
        return in_array($entityType, self::$ENTITY_TYPES, true) ? $entityType : 'page';
    }

    /**
     * The shared polymorphic LEFT JOIN from ork_cms_view (aliased `v`) to the
     * live owning page (`pg`) / post (`po`).
     *
     * Every read in this class resolves a view row's entity the SAME way, and
     * they must stay in lockstep or the dashboard's figures contradict each
     * other (#115): the join is SCOPE-BOUND (pinned to v.scope_type/v.scope_id,
     * so a stray cross-scope entity_id can never leak another org's title/slug)
     * and LIVE-ONLY (deleted_at IS NULL, so views of deleted content drop out).
     *
     * Emits only the JOIN clauses — each caller supplies its own WHERE/HAVING
     * test for "the entity still resolves" (COALESCE(...) IS NOT NULL vs
     * HAVING title IS NOT NULL), because those differ by aggregation shape.
     *
     * @return string SQL fragment, leading space included
     */
    private function _liveEntityJoinSql()
    {
        return ' LEFT JOIN ' . DB_PREFIX . 'cms_page pg'
            . "   ON v.entity_type = 'page' AND pg.page_id = v.entity_id"
            . '   AND pg.scope_type = v.scope_type AND pg.scope_id = v.scope_id'
            . '   AND pg.deleted_at IS NULL'
            . ' LEFT JOIN ' . DB_PREFIX . 'cms_post po'
            . "   ON v.entity_type = 'post' AND po.post_id = v.entity_id"
            . '   AND po.scope_type = v.scope_type AND po.scope_id = v.scope_id'
            . '   AND po.deleted_at IS NULL';
    }

    /**
     * Clamp an int into [$min, $max]. Used for the row-count ceilings that are
     * inlined into LIMIT (bound placeholders aren't reliable for LIMIT under
     * this PDO driver), so the value MUST be a sanitized int.
     *
     * NOTE: this is the plain clamp — out-of-range snaps to the nearest bound.
     * GetTopContent's $days clamp deliberately does NOT use it: a sub-1 $days
     * falls back to RECENT_DAYS, not to 1.
     *
     * @param mixed $n
     * @param int   $min
     * @param int   $max
     * @return int
     */
    private function _clampInt($n, $min, $max)
    {
        $n = (int)$n;
        if ($n < $min) {
            $n = $min;
        }
        if ($n > $max) {
            $n = $max;
        }
        return $n;
    }
}
