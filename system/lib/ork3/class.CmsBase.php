<?php

/*************************************************************************
 * CmsBase — shared helpers for the CMS library classes.
 *
 * CmsPage, CmsPost, CmsMedia, CmsNav, and CmsAuth all extend this class
 * instead of Ork3 directly. It exists solely to de-duplicate the three
 * private helpers that were byte-for-byte identical across every CMS lib.
 *
 * LOAD-ORDER NOTE: class.CmsAuth.php sorts before class.CmsBase.php in
 * scandir() / alphabetical order, so CmsAuth adds an explicit
 * require_once at its top. The other four CMS classes (CmsMedia, CmsNav,
 * CmsPage, CmsPost) all sort after CmsBase and require nothing extra.
 *************************************************************************/

class CmsBase extends Ork3
{
    /**
     * Per-request memo of whether ork_cms_audit exists. null = not yet probed.
     * Keeps _cmsAudit() silent (no repeated SHOW TABLES, no warnings) both
     * before the C14 migration has run and after. FPM resets statics per
     * request so this never leaks across requests.
     */
    private static $_auditTableExists = null;

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
            // before the migration has been applied.
            if (self::$_auditTableExists === null) {
                $DB->Clear();
                $probe = $this->_firstRow($DB->DataSet(
                    "SHOW TABLES LIKE '" . DB_PREFIX . "cms_audit'"
                ));
                self::$_auditTableExists = ($probe !== null);
            }
            if (self::$_auditTableExists !== true) {
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
}
