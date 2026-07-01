<?php

/**
 * trait.CmsScope.php — shared admin scope-context resolution for the CMS.
 *
 * Used by BOTH Controller_Cms (page surfaces) and Controller_CmsAjax (JSON
 * endpoints). Turns an optional per-request `scope` selector into a validated
 * (scope_type, scope_id) array and RE-VALIDATES it server-side on every request
 * against HasAuthority — the client value is only a selector, never authority.
 *
 * Selector wire format (query string `?scope=` or POST field `scope`):
 *   ''            → global front door (unchanged legacy behavior)
 *   'k:{id}'      → kingdom scope for kingdom_id={id}
 *   'p:{id}'      → park scope for park_id={id}
 *
 * BACKWARD COMPATIBILITY: when no selector is present, _resolveScope() returns
 * exactly array('type' => 'global', 'id' => 0) — byte-for-byte the old
 * hard-coded self::$SCOPE — so every existing global-scope path is unchanged.
 *
 * SECURITY: a present-but-unauthorized/malformed selector returns false (never
 * a silent downgrade to global and never an honored unauthorized scope). Each
 * consuming controller maps false to its own deny path (redirect vs JSON 403).
 *
 * Loaded via require_once from each controller file (the router include_once's
 * one controller per request; there is no class autoloader for arbitrary files).
 */
trait CmsScopeContext
{
    /** Memoized resolved scope for this request. @var array|false|null */
    private $_cmsScope = null;
    /** Whether _resolveScope() has run this request (false is a valid result). */
    private $_cmsScopeResolved = false;

    /**
     * Resolve + authorize the admin scope for this request.
     *
     * @param int $uid acting mundane_id (from $this->session->user_id)
     * @return array{type:string,id:int}|false
     *   ['type'=>'global','id'=>0]         when no selector (legacy front door),
     *   ['type'=>'kingdom'|'park','id'=>N] when authorized over the named org,
     *   false                              when the selector is malformed or the
     *                                      user lacks AUTH_EDIT over that org.
     */
    private function _resolveScope($uid)
    {
        if ($this->_cmsScopeResolved) {
            return $this->_cmsScope;
        }
        $this->_cmsScopeResolved = true;

        // Query string wins (rides on every scoped link + AJAX fetch URL); a
        // POST body field is accepted as a fallback for form-style callers.
        $raw = isset($_GET['scope']) ? (string)$_GET['scope']
            : (isset($_POST['scope']) ? (string)$_POST['scope'] : '');
        $raw = trim($raw);

        // No selector → global, exactly as the legacy hard-coded scope.
        if ($raw === '') {
            $this->_cmsScope = array('type' => 'global', 'id' => 0);
            return $this->_cmsScope;
        }

        // Parse 'k:{id}' / 'p:{id}'. Anything else is a malformed selector.
        if (!preg_match('/^([kp]):([0-9]{1,10})$/', $raw, $m)) {
            $this->_cmsScope = false;
            return false;
        }
        $scopeType = ($m[1] === 'p') ? 'park' : 'kingdom';
        $scopeId   = (int)$m[2];
        if ($scopeId <= 0) {
            $this->_cmsScope = false;
            return false;
        }

        // Re-validate server-side: the acting user MUST hold at least AUTH_EDIT
        // over the requested org (super-admins pass via HasAuthority's all-zero
        // short-circuit; publish-tier caps are gated separately via CmsCan).
        $uid      = (int)$uid;
        $authType = ($scopeType === 'park') ? AUTH_PARK : AUTH_KINGDOM;
        $ok = ($uid > 0)
            && is_object(Ork3::$Lib->authorization)
            && Ork3::$Lib->authorization->HasAuthority($uid, $authType, $scopeId, AUTH_EDIT);
        if (!$ok) {
            $this->_cmsScope = false;
            return false;
        }

        $this->_cmsScope = array('type' => $scopeType, 'id' => $scopeId);
        return $this->_cmsScope;
    }

    /** True when the resolved scope is the global front door. */
    private function _scopeIsGlobal($scope)
    {
        return !is_array($scope) || (string)($scope['type'] ?? 'global') === 'global';
    }

    /**
     * The `scope_type`/`scope_id` filter pair for the scope-aware list/read libs.
     * For global this is ('global', 0) — which the libs already treat as the
     * legacy default, keeping global reads scoped to global-only rows.
     *
     * @param array $scope
     * @return array{scope_type:string,scope_id:int}
     */
    private function _scopeFilters($scope)
    {
        return array(
            'scope_type' => is_array($scope) ? (string)($scope['type'] ?? 'global') : 'global',
            'scope_id'   => is_array($scope) ? (int)($scope['id'] ?? 0) : 0,
        );
    }

    /**
     * The URL query fragment ('&scope=k:5' or '') to append to intra-admin links
     * and AJAX fetch URLs so the active scope rides along. Empty for global so
     * legacy URLs stay clean. UIR already ends in '?Route=' → always join with &.
     *
     * @param array $scope
     * @return string
     */
    private function _scopeQuery($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        $prefix = ((string)$scope['type'] === 'park') ? 'p' : 'k';
        return '&scope=' . $prefix . ':' . (int)$scope['id'];
    }

    /**
     * The bare selector string ('k:5' / 'p:3' / '') echoed to the client as
     * window.CMS_SCOPE so admin JS can append it to fetch URLs for re-validation.
     *
     * @param array $scope
     * @return string
     */
    private function _scopeSelector($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        $prefix = ((string)$scope['type'] === 'park') ? 'p' : 'k';
        return $prefix . ':' . (int)$scope['id'];
    }

    /**
     * Human org label for the CMS context banner ('' for global). Looks up the
     * org name directly (thin read; no model needed for a single name column).
     *
     * @param array $scope
     * @return string e.g. 'Kingdom of Foo' name, or '' for global
     */
    private function _scopeOrgLabel($scope)
    {
        if ($this->_scopeIsGlobal($scope)) {
            return '';
        }
        global $DB;
        $type = (string)$scope['type'];
        $id   = (int)$scope['id'];
        $table = ($type === 'park') ? 'park' : 'kingdom';
        $idCol = ($type === 'park') ? 'park_id' : 'kingdom_id';
        $DB->Clear();
        $DB->oid = $id;
        $r = $DB->DataSet(
            'SELECT name FROM ' . DB_PREFIX . $table . ' WHERE ' . $idCol . ' = :oid LIMIT 1'
        );
        if ($r && $r->Next()) {
            return (string)$r->name;
        }
        return '';
    }

    /**
     * True when a page/post/media row belongs to the resolved scope — the IDOR
     * guard for every by-id mutation. A row with no scope columns is treated as
     * global ('global', 0), matching the libs' create defaults.
     *
     * @param array|null $row   the fetched target row (must carry scope_type/id)
     * @param array      $scope the resolved, authorized request scope
     * @return bool
     */
    private function _rowInScope($row, $scope)
    {
        if (!is_array($row) || !is_array($scope)) {
            return false;
        }
        $rt = isset($row['scope_type']) ? (string)$row['scope_type'] : 'global';
        $ri = isset($row['scope_id']) ? (int)$row['scope_id'] : 0;
        return $rt === (string)($scope['type'] ?? 'global')
            && $ri === (int)($scope['id'] ?? 0);
    }
}
