<?php

/*************************************************************************
 * CmsBase — shared helpers for the CMS library classes.
 *
 * CmsPage, CmsPost, CmsMedia, CmsNav, and CmsAuth all extend this class
 * instead of Ork3 directly. It de-duplicates the helper surface common to
 * the CMS libs, in a few categories:
 *
 *   - Result-set adapters (_firstRow / _eachRow) that drive YapoDb off
 *     Next() rather than the unreliable Size()/pre-fetch.
 *   - Scope normalization (_normalizeScopeType).
 *   - Migration-gated bookkeeping (_tableExists memo, _cmsAudit trail,
 *     _promoteScheduled lazy publish).
 *   - Shared page/post write skeletons that CmsPage/CmsPost drive as thin
 *     wrappers: _insertWithDupGuard (create), _softDelete / _restore
 *     (trash lifecycle), _setStatus (publish lifecycle). These keep the
 *     two entities in lockstep on the live-slug uniqueness + IDOR + write
 *     verification rules.
 *
 * LOAD-ORDER NOTE: class.CmsAuth.php sorts before class.CmsBase.php in
 * scandir() / alphabetical order, so CmsAuth adds an explicit
 * require_once at its top. The other four CMS classes (CmsMedia, CmsNav,
 * CmsPage, CmsPost) all sort after CmsBase and require nothing extra.
 *************************************************************************/

class CmsBase extends Ork3
{
    /**
     * Per-request memo of table-existence probes, keyed by full table name
     * (value: bool). Backs _tableExists() so each "does this table exist yet?"
     * check (audit, revision, redirect, …) issues at most one SHOW TABLES per
     * request — silent and cheap both before a migration has run and after. FPM
     * resets statics per request so this never leaks across requests.
     */
    private static $_tableExistsMemo = array();

    /**
     * Per-request guard so the lazy scheduled→published promotion (C7) runs at
     * most once per request. FPM resets statics between requests.
     */
    private static $_promotedThisRequest = false;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Lazy scheduling (C7): promote any scheduled page/post whose published_at
     * has arrived to 'published'. Runs at most once per request (both tables in
     * one pass) so no cron is needed — the next public read sees them live.
     * Best-effort: a failure here never blocks the read. Shared by CmsPage and
     * CmsPost so either read path triggers the flip.
     */
    protected function _promoteScheduled()
    {
        global $DB;

        if (self::$_promotedThisRequest) {
            return;
        }
        self::$_promotedThisRequest = true;

        try {
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'cms_page'
                . " SET status = 'published'"
                . " WHERE status = 'scheduled' AND published_at IS NOT NULL AND published_at <= NOW()"
            );
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'cms_post'
                . " SET status = 'published'"
                . " WHERE status = 'scheduled' AND published_at IS NOT NULL AND published_at <= NOW()"
            );
        } catch (\Throwable $e) {
            // Non-fatal — a scheduled row simply stays scheduled until the next read.
        }
    }

    /**
     * Append a fire-and-forget row to the CMS audit trail (ork_cms_audit).
     *
     * Never blocks or fails the calling mutation: a missing table (pre-migration)
     * is detected once per request and skipped silently, and any write error is
     * swallowed. Callers should invoke this AFTER their primary write succeeds.
     *
     * @param int    $actorId    acting mundane_id (0/unknown → stored NULL)
     * @param string $action     short verb, e.g. 'publish'|'unpublish'|'delete'|'grant'|'revoke'
     * @param string $entityType 'page'|'post'|'media'|'grant'|...
     * @param int    $entityId   primary key of the affected entity
     * @param string $scopeType  'global'|'kingdom'|'park'
     * @param int    $scopeId    scope owner id (0 for global)
     * @return void
     */
    protected function _cmsAudit($actorId, $action, $entityType, $entityId, $scopeType = 'global', $scopeId = 0)
    {
        global $DB;

        try {
            // Probe the table once per request so this stays silent (and cheap)
            // before the C14 migration has been applied.
            if (!$this->_tableExists(DB_PREFIX . 'cms_audit')) {
                return;
            }

            $actorId = (int)$actorId;

            $DB->Clear();
            $DB->actor_id    = $actorId > 0 ? $actorId : null;
            $DB->action      = substr((string)$action, 0, 40);
            $DB->entity_type = substr((string)$entityType, 0, 24);
            $DB->entity_id   = (int)$entityId;
            $DB->scope_type  = $this->_normalizeScopeType($scopeType);
            $DB->scope_id    = (int)$scopeId;
            $DB->at          = date('Y-m-d H:i:s');
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'cms_audit'
                . ' (actor_id, action, entity_type, entity_id, scope_type, scope_id, `at`)'
                . ' VALUES (:actor_id, :action, :entity_type, :entity_id, :scope_type, :scope_id, :at)'
            );
        } catch (\Throwable $e) {
            // Best-effort only — an audit-write failure must never surface to
            // (or roll back) the primary mutation.
        }
    }

    /**
     * Return the first row of a result set as an assoc array, or null.
     *
     * YapoDb::DataSet() pre-advances to the first row, but that pre-fetch is
     * unreliable on PDO's unbuffered MySQL cursor (and Size()/rowCount() lies
     * for SELECTs). So we drive everything off Next()'s boolean and the
     * captured field set.
     *
     * @param mixed $r YapoDb result or false/null
     * @return array|null
     */
    protected function _firstRow($r)
    {
        foreach ($this->_eachRow($r) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Collect and return each result row as an assoc array. Emits the pre-fetched first row
     * (if present) then advances with Next(); never trusts Size().
     *
     * @param mixed $r YapoDb result or false/null
     * @return array list of assoc rows (materialized; small result sets)
     */
    protected function _eachRow($r)
    {
        $rows = array();
        if ($r === false || $r === null) {
            return $rows;
        }
        $first = $r->CurrentFieldSet();
        if (!empty($first)) {
            $rows[] = $first;
        }
        while ($r->Next()) {
            $row = $r->CurrentFieldSet();
            if (!empty($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Canonical slug derivation, shared by every CMS entity so a fresh slug is
     * produced the SAME way wherever it is derived from a title/name. Lowercases,
     * collapses every run of non-alphanumerics to a single hyphen, and trims
     * leading/trailing hyphens: 'My Page' -> 'my-page', 'A  &  B!' -> 'a-b'.
     *
     * Hoisted here to end the pre-existing inconsistency where CmsPage stripped
     * non-alphanumerics to nothing ('My Page' -> 'mypage') while CmsSite
     * hyphenated ('My Kingdom' -> 'my-kingdom'). Both now route through this, so
     * derivation is identical everywhere. Length clamping (a column-width concern)
     * stays with the caller — see CmsSite::DeriveSlug.
     *
     * NOTE: this is for DERIVING a slug from human input, not for normalizing an
     * inbound URL segment for LOOKUP — the public resolvers deliberately keep
     * their strip-to-charset canonicalization so stored slugs still match.
     *
     * @param string $raw human-entered title/name/slug
     * @return string normalized slug ([a-z0-9] and single hyphens; may be '')
     */
    protected function _normalizeSlug($raw)
    {
        $slug = strtolower(trim((string)$raw));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Clamp an arbitrary scope-type string to the supported enum.
     *
     * @param string $scopeType
     * @return string 'global'|'kingdom'|'park'
     */
    protected function _normalizeScopeType($scopeType)
    {
        $scopeType = (string)$scopeType;
        if ($scopeType === 'kingdom' || $scopeType === 'park') {
            return $scopeType;
        }
        return 'global';
    }

    /**
     * Per-request "does this table exist yet?" probe, memoized by full table
     * name. Used to keep optional/migration-gated writes (audit, revision,
     * redirect) silent before their table exists — one SHOW TABLES per table per
     * request, never a warning. Fails closed (false) on any probe error.
     *
     * @param string $tableName full table name (incl. DB_PREFIX)
     * @return bool
     */
    protected function _tableExists($tableName)
    {
        global $DB;

        $tableName = (string)$tableName;
        if (array_key_exists($tableName, self::$_tableExistsMemo)) {
            return self::$_tableExistsMemo[$tableName];
        }

        $exists = false;
        try {
            $DB->Clear();
            $probe = $this->_firstRow($DB->DataSet(
                "SHOW TABLES LIKE '" . $tableName . "'"
            ));
            $exists = ($probe !== null);
        } catch (\Throwable $e) {
            $exists = false;
        }
        self::$_tableExistsMemo[$tableName] = $exists;
        return $exists;
    }

    /**
     * Insert a page/post row with a slug dup-guard, shared by CreatePage and
     * CreatePost. Behavior is identical across both entities:
     *
     *   - Dup pre-check against ONLY live rows (deleted_at IS NULL) so a new row
     *     CAN reuse the slug of a TRASHED one (the uq_*_scope_slug_live key on
     *     the generated slug_live column enforces the same live-only rule at the
     *     DB layer — see 2026-07-08-cms-slug-live-and-integrity.sql).
     *   - INSERT IGNORE so a concurrent racer that already claimed the live
     *     (scope_type, scope_id, slug) tuple makes OUR insert a silent no-op
     *     rather than an error (C29).
     *   - ROW_COUNT() on the same connection tells us whether WE created the row:
     *     the pre-check closes the common case, but two simultaneous "new" saves
     *     both pass it and only the winner's INSERT lands. If we did NOT create
     *     it, return 0 so the caller FAILS the save instead of adopting the
     *     winner's id and clobbering its blocks.
     *   - Read the id back by the live unique tuple (never GetLastInsertId(),
     *     which is unreliable on dup-key under PDO ERRMODE_WARNING). The
     *     deleted_at IS NULL filter guarantees we read OUR fresh row, not a
     *     coexisting trashed row sharing the slug.
     *
     * @param string $table full-suffix table ('cms_page'|'cms_post')
     * @param string $pk     primary-key column ('page_id'|'post_id')
     * @param array  $cols   column => value map (must include slug/scope_type/scope_id)
     * @return int new row id (0 on collision / lost race / failure)
     */
    protected function _insertWithDupGuard($table, $pk, array $cols)
    {
        global $DB;

        $slug      = isset($cols['slug']) ? (string)$cols['slug'] : '';
        $scopeType = isset($cols['scope_type']) ? (string)$cols['scope_type'] : 'global';
        $scopeId   = isset($cols['scope_id']) ? (int)$cols['scope_id'] : 0;

        // Dup pre-check — LIVE rows only (a trashed slug is reusable).
        $DB->Clear();
        $DB->slug       = $slug;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT ' . $pk . ' AS id FROM ' . DB_PREFIX . $table
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND slug = :slug'
            . ' AND deleted_at IS NULL LIMIT 1'
        ));
        if ($existing !== null) {
            return 0;   // slug already in use by a live row — signal collision
        }

        $names = array_keys($cols);
        $placeholders = array();
        foreach ($names as $n) {
            $placeholders[] = ':' . $n;
        }
        $sql = 'INSERT IGNORE INTO ' . DB_PREFIX . $table . ' (`' . implode('`, `', $names) . '`)'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $DB->Clear();
        foreach ($cols as $field => $value) {
            $DB->$field = $value;
        }
        $DB->Execute($sql);

        // Did WE insert (1) or did a concurrent winner already hold the tuple (0)?
        $DB->Clear();
        $rc = $this->_firstRow($DB->DataSet('SELECT ROW_COUNT() AS rc'));
        if ($rc === null || (int)$rc['rc'] < 1) {
            return 0;   // lost the race (or nothing inserted) — signal collision
        }

        // Authoritative read-back by the LIVE unique tuple.
        $DB->Clear();
        $DB->slug       = $slug;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT ' . $pk . ' AS id FROM ' . DB_PREFIX . $table
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND slug = :slug'
            . ' AND deleted_at IS NULL LIMIT 1'
        ));
        return ($row !== null && isset($row['id'])) ? (int)$row['id'] : 0;
    }

    /**
     * Soft-delete (C2 trash) a page/post row: stamp deleted_at instead of
     * physically DELETEing, atomically with any caller-supplied reference
     * cleanup. Shared skeleton for CmsPage::DeletePage / CmsPost::DeletePost —
     * the only per-entity divergence (system-page protection, which inbound
     * references to detach) is handled by the wrapper + the $refCleanup hook.
     *
     * @param string        $table       full-suffix table ('cms_page'|'cms_post')
     * @param string        $pk          primary-key column
     * @param int           $id          row id
     * @param string|null   $scopeType   IDOR guard: caller's intended scope_type
     * @param int|null      $scopeId     IDOR guard: caller's intended scope_id
     * @param int           $actorId     acting mundane_id (audit trail)
     * @param string        $entityType  'page'|'post' (audit)
     * @param callable|null $refCleanup  fn(int $id): void — inbound-reference NULLing,
     *                                   run inside the transaction before the stamp
     * @return bool true when the row existed (and matched scope) and was trashed
     */
    protected function _softDelete($table, $pk, $id, $scopeType, $scopeId, $actorId, $entityType, $refCleanup = null)
    {
        global $DB;

        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }

        // Existence + scope read (skip already-trashed rows so a double-delete
        // reports false).
        $DB->Clear();
        $DB->pk = $id;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $pk . ' = :pk AND deleted_at IS NULL LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }

        // IDOR guard (opt-in, mirrors CmsNav::DeleteItem): reject a cross-scope
        // delete when the caller supplies its intended scope.
        if ($scopeType !== null) {
            $wantType = $this->_normalizeScopeType($scopeType);
            if ((string)$row['scope_type'] !== $wantType || (int)$row['scope_id'] !== (int)$scopeId) {
                return false;
            }
        }

        // Soft-delete + reference cleanup atomically. If the deleted_at write is
        // silently dropped (ERRMODE_WARNING), ROLLBACK so we don't orphan the
        // reference NULLs against a still-live row.
        $now = date('Y-m-d H:i:s');

        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        if ($refCleanup !== null) {
            $refCleanup($id);
        }

        $DB->Clear();
        $DB->deleted_at = $now;
        $DB->pk = $id;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . $table . ' SET deleted_at = :deleted_at'
            . ' WHERE ' . $pk . ' = :pk AND deleted_at IS NULL'
        );

        // Confirm the marker landed before committing.
        $DB->Clear();
        $DB->pk = $id;
        $check = $this->_firstRow($DB->DataSet(
            'SELECT deleted_at FROM ' . DB_PREFIX . $table . ' WHERE ' . $pk . ' = :pk LIMIT 1'
        ));
        if ($check === null || empty($check['deleted_at'])) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return false;
        }

        $DB->Clear();
        $DB->Execute('COMMIT');

        // C14: audit the trash (fire-and-forget).
        $this->_cmsAudit((int)$actorId, 'delete', $entityType, $id, (string)$row['scope_type'], (int)$row['scope_id']);

        return true;
    }

    /**
     * Restore a trashed page/post (clear deleted_at). Shared by
     * CmsPage::RestorePage / CmsPost::RestorePost.
     *
     * Restore-collision (slug-live): while the row was trashed its slug freed up,
     * so a LIVE row may since have claimed the same (scope_type, scope_id, slug).
     * Restoring would re-populate slug_live and violate uq_*_scope_slug_live —
     * detect that up front and fail gracefully (return false) rather than let the
     * UPDATE throw / silently drop. A post-write verify catches any residual
     * silent drop.
     *
     * @param string      $table      full-suffix table ('cms_page'|'cms_post')
     * @param string      $pk         primary-key column
     * @param int         $id         row id
     * @param string|null $scopeType  IDOR guard: caller's intended scope_type
     * @param int|null    $scopeId    IDOR guard: caller's intended scope_id
     * @param int         $actorId    acting mundane_id (audit trail)
     * @param string      $entityType 'page'|'post' (audit)
     * @return bool
     */
    protected function _restore($table, $pk, $id, $scopeType, $scopeId, $actorId, $entityType)
    {
        global $DB;

        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }

        // Read the trashed row directly (the Get* accessors hide deleted rows).
        $DB->Clear();
        $DB->pk = $id;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id, slug, deleted_at FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $pk . ' = :pk LIMIT 1'
        ));
        if ($row === null || empty($row['deleted_at'])) {
            return false;   // not found or not trashed
        }

        if ($scopeType !== null) {
            $wantType = $this->_normalizeScopeType($scopeType);
            if ((string)$row['scope_type'] !== $wantType || (int)$row['scope_id'] !== (int)$scopeId) {
                return false;
            }
        }

        // Live-slug collision guard: a live row in the same scope already holds
        // this slug → restoring is impossible without a rename. Fail cleanly.
        $DB->Clear();
        $DB->scope_type = (string)$row['scope_type'];
        $DB->scope_id   = (int)$row['scope_id'];
        $DB->slug       = (string)$row['slug'];
        $DB->pk         = $id;
        $clash = $this->_firstRow($DB->DataSet(
            'SELECT ' . $pk . ' AS id FROM ' . DB_PREFIX . $table
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND slug = :slug'
            . ' AND deleted_at IS NULL AND ' . $pk . ' <> :pk LIMIT 1'
        ));
        if ($clash !== null) {
            return false;   // slug taken by a live row — cannot restore
        }

        $DB->Clear();
        $DB->pk = $id;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . $table . ' SET deleted_at = NULL WHERE ' . $pk . ' = :pk'
        );

        // Verify the restore actually landed (Execute() is void under
        // ERRMODE_WARNING; a residual unique violation would silently drop it).
        $DB->Clear();
        $DB->pk = $id;
        $verify = $this->_firstRow($DB->DataSet(
            'SELECT deleted_at FROM ' . DB_PREFIX . $table . ' WHERE ' . $pk . ' = :pk LIMIT 1'
        ));
        if ($verify === null || !empty($verify['deleted_at'])) {
            return false;   // restore did not take
        }

        $this->_cmsAudit((int)$actorId, 'restore', $entityType, $id, (string)$row['scope_type'], (int)$row['scope_id']);

        return true;
    }

    /**
     * Would restoring this trashed row fail because a LIVE row in the same scope
     * now holds its slug? Used only to pick a specific error message when
     * _restore() returned false — restore itself already enforces the guard.
     * Returns false for a not-found / not-trashed id (that is a different cause).
     *
     * @return bool true when a live slug clash blocks the restore
     */
    protected function _slugConflictForTrashed($table, $pk, $id)
    {
        global $DB;

        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }

        $DB->Clear();
        $DB->pk = $id;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id, slug, deleted_at FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $pk . ' = :pk LIMIT 1'
        ));
        if ($row === null || empty($row['deleted_at'])) {
            return false;   // not trashed → the failure was some other cause
        }

        $DB->Clear();
        $DB->scope_type = (string)$row['scope_type'];
        $DB->scope_id   = (int)$row['scope_id'];
        $DB->slug       = (string)$row['slug'];
        $DB->pk         = $id;
        $clash = $this->_firstRow($DB->DataSet(
            'SELECT ' . $pk . ' AS id FROM ' . DB_PREFIX . $table
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND slug = :slug'
            . ' AND deleted_at IS NULL AND ' . $pk . ' <> :pk LIMIT 1'
        ));

        return $clash !== null;
    }

    /**
     * Set a page/post publish status, stamping/clearing published_at, then audit
     * the lifecycle transition. Shared by CmsPage::SetStatus / CmsPost::SetStatus.
     * The actual column write is delegated back to the entity's Update* via the
     * $applyUpdate closure (each has its own whitelist / verify / cache logic).
     *
     *   - 'scheduled' requires a target time (falls back to now == publish now);
     *     the read path promotes it to 'published' once that time passes (C7).
     *   - 'published' stamps published_at only when currently empty (an explicit
     *     $publishedAt always wins); unpublishing leaves the historical stamp.
     *   - anything else clamps to 'draft'.
     *
     * @param string   $table       full-suffix table ('cms_page'|'cms_post')
     * @param string   $pk          primary-key column
     * @param string   $entityType  'page'|'post' (audit)
     * @param int      $id          row id
     * @param string   $status      'published'|'draft'|'scheduled'
     * @param int      $updatedBy   actor mundane_id (0 to skip)
     * @param string|null $publishedAt explicit publish timestamp (scheduling)
     * @param callable $applyUpdate fn(array $data): bool — the entity's Update*
     * @return bool
     */
    protected function _setStatus($table, $pk, $entityType, $id, $status, $updatedBy, $publishedAt, $applyUpdate)
    {
        global $DB;

        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        $status = (string)$status;
        if ($status !== 'published' && $status !== 'scheduled') {
            $status = 'draft';
        }

        $data = array('status' => $status);
        if ((int)$updatedBy > 0) {
            $data['updated_by'] = (int)$updatedBy;
        }

        $publishedAt = ($publishedAt === null || $publishedAt === '') ? null : (string)$publishedAt;

        if ($status === 'scheduled') {
            // Scheduling needs a target time; fall back to now (== publish now).
            $data['published_at'] = ($publishedAt !== null) ? $publishedAt : date('Y-m-d H:i:s');
        } elseif ($status === 'published') {
            if ($publishedAt !== null) {
                $data['published_at'] = $publishedAt;
            } else {
                // Stamp published_at only if not already set (targeted 1-col read).
                $DB->Clear();
                $DB->pk = $id;
                $row = $this->_firstRow($DB->DataSet(
                    'SELECT published_at FROM ' . DB_PREFIX . $table . ' WHERE ' . $pk . ' = :pk LIMIT 1'
                ));
                if ($row !== null && empty($row['published_at'])) {
                    $data['published_at'] = date('Y-m-d H:i:s');
                }
            }
        }

        $ok = $applyUpdate($data);

        // C14: audit the publish-lifecycle transition (fire-and-forget). Read the
        // scope off the row so the audit entry is scope-attributed.
        if ($ok) {
            $DB->Clear();
            $DB->pk = $id;
            $scopeRow = $this->_firstRow($DB->DataSet(
                'SELECT scope_type, scope_id FROM ' . DB_PREFIX . $table . ' WHERE ' . $pk . ' = :pk LIMIT 1'
            ));
            $action = ($status === 'draft') ? 'unpublish' : $status; // publish|unpublish|scheduled
            $this->_cmsAudit(
                (int)$updatedBy,
                $action,
                $entityType,
                $id,
                $scopeRow !== null ? (string)$scopeRow['scope_type'] : 'global',
                $scopeRow !== null ? (int)$scopeRow['scope_id'] : 0
            );
        }

        return $ok;
    }
}
