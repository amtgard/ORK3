<?php

/***
 * OfficerPosition
 *
 * DB layer for the officer position registry (ork_officer_position) and
 * occupancy-enforced officer writes (ork_officer). Replaces the five hardcoded
 * ENUM officer roles with a kingdom-extensible, alias-able, RBAC-bound registry.
 *
 * Auto-loaded by startup.php as Ork3::$Lib->officerposition.
 *
 * Project rules honored:
 *   - $DB->Clear() before every raw Execute/DataSet (stale PDO binding guard).
 *   - DisplayTitle resolution uses IF(alias != '', alias, title), NEVER COALESCE
 *     (a cleared yapo alias is '' not NULL; COALESCE('',...) returns '').
 *   - Crown occupancy is per-seat, not per-person: the ORK imposes no limit on
 *     how many offices a person holds. Single-occupant-per-scope is enforced at
 *     the app layer because ork_officer is MyISAM (no transactions, no partial
 *     unique indexes); the SELECT-then-write is serialized per office with
 *     GET_LOCK('officer_assign_<kingdom_id>_<park_id>_<position_id>', timeout) /
 *     RELEASE_LOCK in finally.
 ***/

class OfficerPosition extends Ork3
{
    public const CROWN_LOCK_TIMEOUT = 5; // seconds for GET_LOCK on crown assignment

    /**
     * The DisplayTitle resolution rule, as a SQL expression.
     *
     * A position's shown title is: the kingdom's alias if the row is a shared
     * system position (kingdom_id = 0), else the row's own title_alias, else its
     * title. This was written out at seven call sites across four files, in two
     * different column-alias conventions -- so the "NEVER COALESCE" rule in this
     * file's header had to hold in seven places at once, and adding a tier (a
     * park-level alias, say) meant finding SQL fragments no grep relates.
     *
     * @param string $pos    Table alias for officer_position
     * @param string $alias  Table alias for officer_position_alias
     * @return string  SQL expression; caller supplies its own `AS <name>`
     */
    public static function DisplayTitleSql($pos = 'p', $alias = 'a')
    {
        return 'IF(' . $pos . '.kingdom_id = 0,'
            . ' IF(' . $alias . '.title_alias IS NOT NULL AND ' . $alias . ".title_alias != '', "
            . $alias . '.title_alias, ' . $pos . '.title),'
            . ' IF(' . $pos . ".title_alias != '', " . $pos . '.title_alias, ' . $pos . '.title))';
    }

    /**
     * The permission key for an officer action in a given scope.
     *
     * The key is scoped as well as the scope argument. checkPermissionOrAuthority()
     * maps 'park' to AUTH_PARK, so passing scope='park' with a kingdom.* key looks
     * up a kingdom permission against a park id and simply fails. PermissionRegistry
     * has defined a full park mirror since this branch began; nothing used it.
     *
     * @param string $action one of set|vacate|position|history
     * @param int    $park_id 0 for kingdom scope, otherwise park scope
     */
    public static function PermissionKeyFor($action, $park_id)
    {
        $prefix = ((int) $park_id > 0) ? 'park' : 'kingdom';
        $map = [
            'set'      => '.officer.set',
            'vacate'   => '.officer.vacate',
            'position' => '.officer.position.manage',
            'history'  => '.officer_history.manage',
        ];
        if (!isset($map[$action])) {
            throw new InvalidArgumentException('Unknown officer action: ' . $action);
        }
        return $prefix . $map[$action];
    }

    /**
     * The effective sort_order resolution rule, as a SQL expression.
     *
     * Sibling to DisplayTitleSql() and written for the same reason. The Core Five
     * are SHARED rows (kingdom_id = 0) that every kingdom reads: on the dev mirror,
     * 37 kingdoms and exactly one of them owns a non-system position at all. So a
     * kingdom reordering "its" crown officers is, for 36 of 37 kingdoms, reordering
     * rows the whole game shares. Writing officer_position.sort_order there would be
     * globally destructive; refusing to write it would make the feature a no-op for
     * almost everyone.
     *
     * A kingdom's own row always uses its own value. A shared row prefers this
     * kingdom's override in officer_position_alias, falling back to the shared value
     * when the kingdom has expressed no opinion (sort_order IS NULL).
     *
     * IFNULL, not IF(... != ''), is correct here where DisplayTitleSql needs the
     * opposite: sort_order is a nullable INT whose NULL genuinely means "unset",
     * while title_alias is NOT NULL DEFAULT '' where '' means "unset".
     *
     * @param string $pos    Table alias for officer_position
     * @param string $alias  Table alias for officer_position_alias
     * @return string  SQL expression; caller supplies its own `AS <name>`
     */
    public static function SortOrderSql($pos = 'p', $alias = 'a')
    {
        return 'IF(' . $pos . '.kingdom_id = 0,'
            . ' IFNULL(' . $alias . '.sort_order, ' . $pos . '.sort_order),'
            . ' ' . $pos . '.sort_order)';
    }

    // ================================================================
    // REGISTRY READS
    // ================================================================

    /**
     * Return the position registry visible to a kingdom: the shared system
     * Core-Five (kingdom_id=0) plus this kingdom's own custom rows, with
     * DisplayTitle resolved (alias table for system rows; own title_alias for
     * custom rows). Ordered by classification, sort_order.
     *
     * @param int         $kingdom_id
     * @param bool        $include_retired
     * @param string|null $classification  'crown'|'supporting'|null (all)
     * @return array  Rows with DisplayTitle + CanonicalKey
     */
    public function GetPositions($kingdom_id, $include_retired = false, $classification = null)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;

        $sql = "SELECT p.*,
    			" . self::DisplayTitleSql('p', 'a') . " AS DisplayTitle,
    			" . self::SortOrderSql('p', 'a') . " AS EffectiveSortOrder
    		FROM " . DB_PREFIX . "officer_position p
    		LEFT JOIN " . DB_PREFIX . "officer_position_alias a
    		  ON a.kingdom_id = :kingdom_id AND a.canonical_key = p.canonical_key
    		WHERE (p.kingdom_id = 0 OR p.kingdom_id = :kingdom_id2)";
        if (!$include_retired) {
            $sql .= " AND p.retired_at IS NULL";
        }
        if ($classification !== null) {
            $sql .= " AND p.classification = :classification";
        }
        $sql .= " ORDER BY p.classification, " . self::SortOrderSql('p', 'a');

        $DB->Clear();
        $DB->kingdom_id = $kingdom_id;
        $DB->kingdom_id2 = $kingdom_id;
        if ($classification !== null) {
            $DB->classification = $classification;
        }
        $r = $DB->DataSet($sql);

        $positions = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->Next()) {
                $positions[] = $this->RowToArray($r);
            }
        }
        return $positions;
    }

    /**
     * Single registry row + resolved DisplayTitle + rbac_role_id + permission summary.
     *
     * @param int $position_id
     * @return array|false
     */
    public function GetPosition($position_id, $kingdom_id = 0)
    {
        global $DB;
        $position_id = (int) $position_id;
        $kingdom_id = (int) $kingdom_id;
        if ($position_id <= 0) {
            return false;
        }

        $DB->Clear();
        $DB->pid = $position_id;
        $DB->gp_kid = $kingdom_id;
        $r = $DB->DataSet(
            "SELECT p.*,
    			" . self::DisplayTitleSql('p', 'a') . " AS DisplayTitle,
    			" . self::SortOrderSql('p', 'a') . " AS EffectiveSortOrder
    		FROM " . DB_PREFIX . "officer_position p
    		LEFT JOIN " . DB_PREFIX . "officer_position_alias a
    		  ON a.kingdom_id = :gp_kid AND a.canonical_key = p.canonical_key
    		WHERE p.position_id = :pid LIMIT 1"
        );
        if ($r === false || $r->size() == 0 || !$r->Next()) {
            return false;
        }
        $row = $this->RowToArray($r);

        // Permission summary for the bound role.
        $row['Permissions'] = [];
        $rbac_role_id = (int) $row['RbacRoleId'];
        if ($rbac_role_id > 0) {
            $DB->Clear();
            $DB->rid = $rbac_role_id;
            $pr = $DB->DataSet(
                "SELECT pm.`key` AS perm_key
    			 FROM " . DB_PREFIX . "role_permission rp
    			 JOIN " . DB_PREFIX . "permission pm ON pm.permission_id = rp.permission_id
    			 WHERE rp.role_id = :rid
    			 ORDER BY pm.`key`"
            );
            if ($pr !== false && $pr->size() > 0) {
                while ($pr->Next()) {
                    $row['Permissions'][] = $pr->perm_key;
                }
            }
        }
        return $row;
    }

    /**
     * Normalize a DataSet registry row into an associative array in the
     * contracted PascalCase shape (PositionId, CanonicalKey, DisplayTitle, ...).
     *
     * PascalCase-only: every consumer of GetPositions/GetPosition/GetOfficersForDisplay
     * output (controller.OfficerAdminAjax, _manage_officers.tpl via the JSON envelope,
     * and the write methods in this class) reads the PascalCase keys. Kingdom/Park
     * only call ResolvePositionId/ResolveCanonicalKey (scalar returns, not RowToArray),
     * so no external snake_case consumer exists.
     */
    private function RowToArray($r)
    {
        return [
            'PositionId'    => (int) $r->position_id,
            'KingdomId'     => (int) $r->kingdom_id,
            'CanonicalKey'  => $r->canonical_key,
            'Title'         => $r->title,
            'TitleAlias'    => $r->title_alias,
            'DisplayTitle'  => $r->DisplayTitle,
            'Classification' => $r->classification,
            'IsPinned'      => (int) $r->is_pinned,
            'IsSystem'      => (int) $r->is_system,
            'RbacRoleId'    => (int) $r->rbac_role_id,
            'HasAuthRole'   => (int) $r->has_auth_role,
            // EFFECTIVE order: the acting kingdom's override for a shared row, else the
            // row's own value. Both queries feeding RowToArray select it; the fallback
            // covers a future caller that forgets the column rather than emitting null.
            'SortOrder'     => isset($r->EffectiveSortOrder) ? (int) $r->EffectiveSortOrder : (int) $r->sort_order,
            'ParentPositionId'   => ($r->parent_position_id === null || $r->parent_position_id === '') ? null : (int) $r->parent_position_id,
            'HideWhenVacant'     => (int) $r->hide_when_vacant,
            'RetiredAt'     => $r->retired_at,
        ];
    }

    // ================================================================
    // RESOLUTION HELPERS (used by Kingdom::SetOfficer / Park::SetOfficer)
    // ================================================================

    /**
     * Resolve a position_id for a kingdom from either a canonical key or a
     * legacy display string (e.g. 'Prime Minister'). System Core-Five rows are
     * shared (kingdom_id=0); kingdom-custom positions are matched by canonical
     * key within the kingdom. Returns 0 when no match.
     *
     * @param int    $kingdom_id
     * @param string $roleOrKey
     * @return int
     */
    public function ResolvePositionId($kingdom_id, $roleOrKey)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $key = $this->NormalizeToCanonicalKey($roleOrKey);

        $DB->Clear();
        $DB->rp_key = $key;
        $DB->rp_kid = $kingdom_id;
        $r = $DB->DataSet(
            "SELECT position_id FROM " . DB_PREFIX . "officer_position
    		 WHERE canonical_key = :rp_key AND (kingdom_id = 0 OR kingdom_id = :rp_kid)
    		 ORDER BY kingdom_id DESC LIMIT 1"
        );
        if ($r !== false && $r->size() > 0 && $r->Next()) {
            return (int) $r->position_id;
        }
        return 0;
    }

    /**
     * Resolve the canonical key for a kingdom from a canonical key or display
     * string. Falls back to the normalized input if no registry row matches.
     *
     * @param int    $kingdom_id
     * @param string $roleOrKey
     * @return string
     */
    public function ResolveCanonicalKey($kingdom_id, $roleOrKey)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $key = $this->NormalizeToCanonicalKey($roleOrKey);

        $DB->Clear();
        $DB->rc_key = $key;
        $DB->rc_kid = $kingdom_id;
        $r = $DB->DataSet(
            "SELECT canonical_key FROM " . DB_PREFIX . "officer_position
    		 WHERE canonical_key = :rc_key AND (kingdom_id = 0 OR kingdom_id = :rc_kid)
    		 ORDER BY kingdom_id DESC LIMIT 1"
        );
        if ($r !== false && $r->size() > 0 && $r->Next()) {
            return $r->canonical_key;
        }
        return $key;
    }

    /**
     * Map a legacy display string to its canonical key; pass through anything
     * that already looks like a canonical key (lowercase/underscore slug).
     */
    private function NormalizeToCanonicalKey($roleOrKey)
    {
        // PermissionRegistry owns the Core Five display-name -> key map and returns
        // null for anything outside it, which is exactly the kingdom-custom case:
        // fall through to the slug. Keeping a second copy of that map here meant
        // adding a sixth core office required editing two files that no grep relates.
        return PermissionRegistry::CanonicalOfficerRole($roleOrKey) ?? $this->Slugify($roleOrKey);
    }

    /**
     * Slugify a title into a canonical key (lowercase, underscores, alnum).
     */
    private function Slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = trim($value, '_');
        return $value;
    }

    // ================================================================
    // REGISTRY WRITES
    // ================================================================

    /**
     * The sort_order that puts a row at the END of a sibling group, as $kingdom_id
     * sees that group: MAX(effective sort_order) + 10.
     *
     * Shared by the two ways a row ARRIVES in a group -- CreatePosition() (a brand
     * new position) and ReinstatePosition() (a retired one coming back). Both mean
     * "this row has no place in the group yet, put it at the end", and both have to
     * measure the group the same way or the two paths disagree about where the end
     * is.
     *
     * MEASURED AGAINST THE EFFECTIVE ORDER, not the raw column. The Core Five are
     * SHARED rows (kingdom_id = 0) whose per-kingdom order lives in
     * officer_position_alias, so a kingdom that had dragged them down would get its
     * arrival wedged into the middle of the list it actually sees.
     *
     * SCOPED TO THE SIBLING GROUP, not to classification. sort_order orders
     * siblings, and Manage Officers renders crown and supporting as ONE list, so a
     * classification-scoped MAX draws from the wrong sequence: a kingdom whose only
     * supporting offices are nested children (10/20/30 within their parent) would
     * hand its first TOP-LEVEL supporting office 40 -- colliding with a crown office
     * already at 40, the tie then broken arbitrarily by role name.
     *
     * An EMPTY group yields 10 (MAX over no rows is NULL, and (int) NULL + 10 = 10),
     * which is the same base ReorderSiblings() renumbers from. The literal 100 is
     * reached only when the query itself fails.
     *
     * @param int      $kingdom_id           Kingdom whose effective order is measured.
     * @param int|null $parent_position_id   0/''/null = the top-level group.
     * @param int      $exclude_position_id  A row to leave out of the measurement --
     *                                       the row being placed, when it is already
     *                                       in the group. Without it a row retired at
     *                                       the group's own maximum would measure
     *                                       against itself and drift further out on
     *                                       every retire/reinstate cycle.
     * @return int
     */
    private function NextSortOrderInGroup($kingdom_id, $parent_position_id, $exclude_position_id = 0)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $exclude_position_id = (int) $exclude_position_id;

        // parent_position_id is interpolated, not bound: it is NULL-or-int and yapo
        // drops nulls from bindings. The non-null branch is (int)-cast, as is the
        // exclusion -- mysql_real_escape_string() is a no-op shim in this codebase.
        $parentScope = ($parent_position_id === null || $parent_position_id === '' || (int) $parent_position_id === 0)
            ? 'p.parent_position_id IS NULL'
            : 'p.parent_position_id = ' . (int) $parent_position_id;
        $excludeScope = $exclude_position_id > 0
            ? ' AND p.position_id != ' . $exclude_position_id
            : '';

        $DB->Clear();
        $DB->so_kid = $kingdom_id;
        $DB->so_kid2 = $kingdom_id;
        $mx = $DB->DataSet(
            "SELECT MAX(" . self::SortOrderSql('p', 'a') . ") AS mx
    		 FROM " . DB_PREFIX . "officer_position p
    		 LEFT JOIN " . DB_PREFIX . "officer_position_alias a
    		   ON a.kingdom_id = :so_kid AND a.canonical_key = p.canonical_key
    		 WHERE (p.kingdom_id = 0 OR p.kingdom_id = :so_kid2) AND " . $parentScope . $excludeScope
        );
        $sort_order = 100;
        if ($mx !== false && $mx->size() > 0 && $mx->Next()) {
            $sort_order = ((int) $mx->mx) + 10;
        }
        return $sort_order;
    }

    /**
     * Create a new kingdom-custom position.
     *
     * @param int    $kingdom_id
     * @param string $canonical_key  (slugified/validated; '' = derive from title)
     * @param string $title
     * @param string $classification 'crown'|'supporting'
     * @param array  $rbac_choice    ['mode'=>'existing','role_id'=>N]
     *                               | ['mode'=>'custom','permission_keys'=>[...]]
     * @param int    $creator_id
     * @return array  Success(position_id) | error
     */
    public function CreatePosition($kingdom_id, $canonical_key, $title, $classification, $rbac_choice, $creator_id = 0, $parent_position_id = null, $hide_when_vacant = 0)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $title = trim((string) $title);
        $creator_id = (int) $creator_id;

        if ($kingdom_id <= 0) {
            return InvalidParameter(null, 'A valid kingdom is required to create a position.');
        }
        if ($title === '') {
            return InvalidParameter(null, 'A position title is required.');
        }
        if ($classification !== 'crown' && $classification !== 'supporting') {
            return InvalidParameter(null, 'Classification must be crown or supporting.');
        }

        $slug = (trim((string) $canonical_key) !== '') ? $this->Slugify($canonical_key) : $this->Slugify($title);
        if ($slug === '') {
            return InvalidParameter(null, 'Could not derive a canonical key for this position.');
        }

        // Uniqueness within (kingdom_id, canonical_key). The shared system rows
        // live at kingdom_id=0, so reject any collision with them too.
        $DB->Clear();
        $DB->u_kid = $kingdom_id;
        $DB->u_key = $slug;
        $exists = $DB->DataSet(
            "SELECT position_id FROM " . DB_PREFIX . "officer_position
    		 WHERE canonical_key = :u_key AND (kingdom_id = 0 OR kingdom_id = :u_kid) LIMIT 1"
        );
        if ($exists !== false && $exists->size() > 0) {
            return InvalidParameter(null, 'A position with this key already exists for this kingdom.');
        }

        // Resolve the RBAC role binding.
        $rbac_role_id = 0;
        $created_custom_role_id = 0; // C1: set when CreateRole runs in custom mode
        if (is_array($rbac_choice) && isset($rbac_choice['mode']) && $rbac_choice['mode'] === 'custom') {
            $permission_keys = isset($rbac_choice['permission_keys']) ? $rbac_choice['permission_keys'] : [];
            if (!isset(Ork3::$Lib->rbacservice)) {
                return ProcessingError('RBAC service unavailable; cannot create custom role.');
            }
            $res = Ork3::$Lib->rbacservice->CreateRole(
                $creator_id,
                $kingdom_id,
                'officer:' . $slug,
                $title,
                '',
                'kingdom',
                $permission_keys
            );
            if (!isset($res['Status']) || $res['Status'] != 0) {
                return $res;
            }
            $rbac_role_id = (int) $res['Detail'];
            // C1: remember that THIS call created the role, so we can roll it back if
            // the position INSERT below fails (avoid orphaning a custom role).
            $created_custom_role_id = $rbac_role_id;
        } elseif (is_array($rbac_choice) && isset($rbac_choice['mode']) && $rbac_choice['mode'] === 'existing') {
            $rbac_role_id = isset($rbac_choice['role_id']) ? (int) $rbac_choice['role_id'] : 0;
        }
        // 'none' mode: rbac_role_id stays 0 (the holder gets no extra access). A 0
        // binding is valid only for explicit 'none'; otherwise a binding is required.
        $rbac_mode = (is_array($rbac_choice) && isset($rbac_choice['mode'])) ? $rbac_choice['mode'] : '';
        if ($rbac_mode !== 'none' && $rbac_role_id <= 0) {
            return InvalidParameter(null, 'A valid RBAC role binding is required.');
        }

        // hide_when_vacant applies to NON-Crown only; force 0 for crown.
        $hide_when_vacant = ($classification === 'crown') ? 0 : (((int) $hide_when_vacant) ? 1 : 0);

        // parent_position_id ("Reports To"). 0/''/null = top-level (NULL stored).
        $parent_position_id = ($parent_position_id === null || $parent_position_id === '' || (int) $parent_position_id === 0)
            ? null : (int) $parent_position_id;
        if ($parent_position_id !== null) {
            $perr = $this->ValidateParent(0, $kingdom_id, $parent_position_id);
            if ($perr !== true) {
                return $perr;
            }
        }

        // sort_order = end of the sibling group. See NextSortOrderInGroup().
        $sort_order = $this->NextSortOrderInGroup($kingdom_id, $parent_position_id);

        $DB->Clear();
        $DB->c_kid = $kingdom_id;
        $DB->c_key = $slug;
        $DB->c_title = $title;
        $DB->c_cls = $classification;
        $DB->c_rid = $rbac_role_id;
        $DB->c_so = $sort_order;
        $DB->c_cb = $creator_id;
        $DB->c_hwv = $hide_when_vacant;
        // parent_position_id is NULL-or-int; bind as a literal so NULL is stored as NULL.
        $parent_sql = ($parent_position_id === null) ? 'NULL' : (int) $parent_position_id;
        $DB->Execute(
            "INSERT INTO " . DB_PREFIX . "officer_position
    		 (kingdom_id, canonical_key, title, title_alias, classification, is_pinned, is_system, rbac_role_id, has_auth_role, sort_order, parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
    		 VALUES (:c_kid, :c_key, :c_title, '', :c_cls, 0, 0, :c_rid, 0, :c_so, " . $parent_sql . ", :c_hwv, NULL, :c_cb, NOW())"
        );

        // Prefer the driver's last-insert-id accessor over a SELECT-after-INSERT.
        $position_id = (int) $DB->GetLastInsertId();
        if ($position_id <= 0) {
            // Fallback: UNIQUE(kingdom_id, canonical_key) makes this lookup safe.
            $position_id = $this->ResolvePositionId($kingdom_id, $slug);
        }

        // C1: the INSERT failed (no usable position_id). If THIS call created a
        // custom role, roll it back so it is not orphaned, and never return Success(0).
        if ($position_id <= 0) {
            if ($created_custom_role_id > 0) {
                $DB->Clear();
                $DB->orphan_rid = $created_custom_role_id;
                $DB->orphan_kid = $kingdom_id;
                $DB->Execute(
                    "DELETE FROM " . DB_PREFIX . "role
    				 WHERE role_id = :orphan_rid AND kingdom_id = :orphan_kid AND is_system = 0"
                );
            }
            return ProcessingError('The position could not be created. Please try again.');
        }

        return Success($position_id);
    }

    /**
     * Edit a position. title / title_alias / sort_order always editable.
     * For non-pinned positions, classification + rbac binding are also editable;
     * a binding change triggers §4.4 reconciliation for all live occupants.
     *
     * @param int   $position_id
     * @param array $fields  title, title_alias, sort_order, classification,
     *                       rbac_role_id, permission_keys, changed_by, editor_id
     * @return array
     */
    public function EditPosition($position_id, $fields, $acting_kingdom_id = 0)
    {
        global $DB;
        $position_id = (int) $position_id;
        $acting_kingdom_id = (int) $acting_kingdom_id;
        $position = $this->GetPosition($position_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        $is_pinned = (int) $position['IsPinned'];
        $is_system = (int) $position['IsSystem'];
        $pos_kingdom_id = (int) $position['KingdomId'];
        $canonical_key  = $position['CanonicalKey'];
        $changed_by = isset($fields['changed_by']) ? (int) $fields['changed_by'] : 0;
        $editor_id  = isset($fields['editor_id']) ? (int) $fields['editor_id'] : $changed_by;

        // S1: kingdom-ownership guard. Allow shared system rows (kingdom_id=0) and
        // the acting kingdom's own rows; reject a different kingdom's custom row.
        if ($pos_kingdom_id !== 0 && $acting_kingdom_id > 0 && $pos_kingdom_id !== $acting_kingdom_id) {
            return NoAuthorization('Position does not belong to this kingdom.');
        }

        // Reject pinned/system classification + RBAC edits server-side. Title alias
        // (via the alias table for system rows) and sort_order remain allowed.
        if ($is_pinned || $is_system) {
            if (isset($fields['classification']) && $fields['classification'] !== $position['Classification']) {
                return NoAuthorization('Pinned/system positions cannot be reclassified.');
            }
            if ((isset($fields['rbac_role_id']) && (int) $fields['rbac_role_id'] !== (int) $position['RbacRoleId'])
                || isset($fields['permission_keys'])) {
                return NoAuthorization('Pinned/system positions cannot have their RBAC binding changed.');
            }
            // S1b: the Core-Five system rows are SHARED (kingdom_id=0) across every
            // kingdom, so a reparent by one kingdom's admin would corrupt the
            // Reports-To hierarchy (and its hide-when-vacant cascade) for all of them.
            if (array_key_exists('parent_position_id', $fields)) {
                $raw_parent = $fields['parent_position_id'];
                $req_parent = ($raw_parent === null || $raw_parent === '' || (int) $raw_parent === 0) ? null : (int) $raw_parent;
                $cur_parent = ($position['ParentPositionId'] === null) ? null : (int) $position['ParentPositionId'];
                if ($req_parent !== $cur_parent) {
                    return NoAuthorization('Pinned/system positions cannot be reparented.');
                }
            }
        }

        // title_alias routing differs by row type:
        //   - SYSTEM row (kingdom_id=0): per-kingdom alias lives in the alias table,
        //     keyed on ($acting_kingdom_id, canonical_key). NEVER mutate the shared row.
        //   - CUSTOM row (kingdom_id>0): alias lives on the row's own title_alias column.
        if (array_key_exists('title_alias', $fields) && $pos_kingdom_id === 0) {
            if ($acting_kingdom_id <= 0) {
                return InvalidParameter(null, 'A valid kingdom is required to alias a system position.');
            }
            $alias = trim((string) $fields['title_alias']);
            if ($alias !== '') {
                $DB->Clear();
                $DB->al_kid = $acting_kingdom_id;
                $DB->al_key = $canonical_key;
                $DB->al_alias = $alias;
                $DB->Execute(
                    "INSERT INTO " . DB_PREFIX . "officer_position_alias (kingdom_id, canonical_key, title_alias)
    				 VALUES (:al_kid, :al_key, :al_alias)
    				 ON DUPLICATE KEY UPDATE title_alias = VALUES(title_alias)"
                );
            } else {
                // uq_kingdom_canonical means the title alias and the per-kingdom sort
                // override share ONE row. The old unconditional DELETE here would have
                // silently reset a kingdom's officer order every time it cleared a
                // custom title. Blank the title, then drop the row only if it now
                // carries nothing at all.
                $DB->Clear();
                $DB->ad_kid = $acting_kingdom_id;
                $DB->ad_key = $canonical_key;
                $DB->Execute(
                    "UPDATE " . DB_PREFIX . "officer_position_alias
    				 SET title_alias = ''
    				 WHERE kingdom_id = :ad_kid AND canonical_key = :ad_key"
                );
                $DB->Clear();
                $DB->adx_kid = $acting_kingdom_id;
                $DB->adx_key = $canonical_key;
                $DB->Execute(
                    "DELETE FROM " . DB_PREFIX . "officer_position_alias
    				 WHERE kingdom_id = :adx_kid AND canonical_key = :adx_key
    				   AND title_alias = '' AND sort_order IS NULL"
                );
            }
        }

        // sort_order routing mirrors title_alias routing above, and for the same
        // reason: a SHARED system row (kingdom_id = 0) is read by every kingdom, so
        // one kingdom's ordering opinion belongs in ITS alias row, never on the shared
        // row. A kingdom-owned row keeps its order on the row itself. Done HERE, before
        // the UPDATE bindings are set, because this Execute runs its own $DB->Clear().
        $apply_sort_on_row = array_key_exists('sort_order', $fields);
        if ($apply_sort_on_row && $pos_kingdom_id === 0) {
            if ($acting_kingdom_id <= 0) {
                return InvalidParameter(null, 'A valid kingdom is required to reorder a system position.');
            }
            $DB->Clear();
            $DB->eps_kid = $acting_kingdom_id;
            $DB->eps_key = $canonical_key;
            $DB->eps_val = (int) $fields['sort_order'];
            $DB->Execute(
                "INSERT INTO " . DB_PREFIX . "officer_position_alias (kingdom_id, canonical_key, sort_order)
    			 VALUES (:eps_kid, :eps_key, :eps_val)
    			 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
            );
            $apply_sort_on_row = false;
        }

        // Resolve + validate parent BEFORE binding UPDATE params: ValidateParent
        // runs its own DataSets ($DB->Clear()), which would wipe the UPDATE bindings
        // set below if interleaved (stale-PDO-binding guard).
        $apply_parent = false;
        $new_parent = null;
        if (array_key_exists('parent_position_id', $fields)) {
            $apply_parent = true;
            $raw = $fields['parent_position_id'];
            $new_parent = ($raw === null || $raw === '' || (int) $raw === 0) ? null : (int) $raw;
            if ($new_parent !== null) {
                $perr = $this->ValidateParent($position_id, $pos_kingdom_id, $new_parent);
                if ($perr !== true) {
                    return $perr;
                }
            }
        }

        // S2: resolve + validate an existing-role rebind BEFORE binding the UPDATE
        // params. The role-lookup DataSet runs its own $DB->Clear(), which would wipe
        // the UPDATE bindings if interleaved (stale-PDO-binding guard). Only applies
        // to non-pinned positions changing to a different role.
        $rebind_to_role_id = 0;
        if (!$is_pinned && isset($fields['rbac_role_id']) && (int) $fields['rbac_role_id'] > 0
            && (int) $fields['rbac_role_id'] !== (int) $position['RbacRoleId']) {
            $candidate = (int) $fields['rbac_role_id'];
            $DB->Clear();
            $DB->vr_rid = $candidate;
            $vr = $DB->DataSet(
                "SELECT kingdom_id FROM " . DB_PREFIX . "role WHERE role_id = :vr_rid LIMIT 1"
            );
            if ($vr === false || $vr->size() == 0 || !$vr->Next()) {
                return InvalidParameter(null, 'The selected role does not exist.');
            }
            // The chosen role must be a system role (kingdom_id=0) or owned by the
            // acting kingdom — never a foreign kingdom's custom role (which would let
            // this position inherit that kingdom's permissions).
            $role_kingdom_id = (int) $vr->kingdom_id;
            if ($role_kingdom_id !== 0 && $acting_kingdom_id > 0 && $role_kingdom_id !== $acting_kingdom_id) {
                return NoAuthorization('Role does not belong to this kingdom.');
            }
            $rebind_to_role_id = $candidate;
        }

        // Rebinding to None (rbac_role_id explicitly 0): the holder gets no extra
        // access. Only for non-pinned positions whose current binding is non-zero.
        // No DB lookup needed (0 is always valid), so no stale-binding concern.
        $rebind_to_none = (!$is_pinned && isset($fields['rbac_role_id']) && (int) $fields['rbac_role_id'] === 0
            && (int) $position['RbacRoleId'] !== 0);

        // title / title_alias(custom only) / sort_order — written on the row itself.
        $sets = [];
        $DB->Clear();
        $DB->ep_pid = $position_id;
        if (array_key_exists('title', $fields)) {
            $DB->ep_title = trim((string) $fields['title']);
            $sets[] = "title = :ep_title";
        }
        if (array_key_exists('title_alias', $fields) && $pos_kingdom_id > 0) {
            // Custom-row alias on its own column. '' clears; never null (yapo/SQL semantics).
            $DB->ep_alias = (string) $fields['title_alias'];
            $sets[] = "title_alias = :ep_alias";
        }
        if ($apply_sort_on_row) {
            $DB->ep_so = (int) $fields['sort_order'];
            $sets[] = "sort_order = :ep_so";
        }

        // parent_position_id was validated above (before the UPDATE bindings). It is
        // written as a SQL literal (sanitized int / NULL), so no binding is needed.
        if ($apply_parent) {
            $sets[] = ($new_parent === null) ? "parent_position_id = NULL" : "parent_position_id = " . (int) $new_parent;
        }

        // hide_when_vacant: applies to NON-Crown only. Force 0 for crown/pinned/system.
        if (array_key_exists('hide_when_vacant', $fields)) {
            // Use the incoming classification if it is being changed in this same edit,
            // otherwise the current stored classification.
            $eff_cls = (!$is_pinned && array_key_exists('classification', $fields)
                && ($fields['classification'] === 'crown' || $fields['classification'] === 'supporting'))
                ? $fields['classification'] : $position['Classification'];
            $hide = ($eff_cls === 'crown' || $is_pinned || $is_system) ? 0 : (((int) $fields['hide_when_vacant']) ? 1 : 0);
            $DB->ep_hwv = $hide;
            $sets[] = "hide_when_vacant = :ep_hwv";
        }

        $old_rbac_role_id = (int) $position['RbacRoleId'];
        $new_rbac_role_id = $old_rbac_role_id;

        if (!$is_pinned) {
            if (array_key_exists('classification', $fields)
                && ($fields['classification'] === 'crown' || $fields['classification'] === 'supporting')) {
                $DB->ep_cls = $fields['classification'];
                $sets[] = "classification = :ep_cls";
            }

            // Custom-permission upsert on the bound role.
            if (isset($fields['permission_keys']) && is_array($fields['permission_keys'])
                && isset(Ork3::$Lib->rbacservice) && $old_rbac_role_id > 0) {
                Ork3::$Lib->rbacservice->EditRole($editor_id, $old_rbac_role_id, $fields['permission_keys']);
            }

            // Rebinding to a different existing role (validated up-front into
            // $rebind_to_role_id before the UPDATE bindings were set).
            if ($rebind_to_role_id > 0) {
                $new_rbac_role_id = $rebind_to_role_id;
                $DB->ep_rid = $new_rbac_role_id;
                $sets[] = "rbac_role_id = :ep_rid";
            } elseif ($rebind_to_none) {
                // None: store 0 so the holder gets no extra access. Reconciliation
                // below revokes the old role from all live occupants.
                $new_rbac_role_id = 0;
                $sets[] = "rbac_role_id = 0";
            }
        }

        if (count($sets) > 0) {
            $DB->Execute(
                "UPDATE " . DB_PREFIX . "officer_position SET " . implode(', ', $sets) . " WHERE position_id = :ep_pid"
            );
        }

        // §4.4 reconciliation: if the binding changed, revoke old role / grant new
        // for every live occupant of this position.
        if ($new_rbac_role_id !== $old_rbac_role_id) {
            $this->ReconcileRoleBinding($position_id, $old_rbac_role_id, $new_rbac_role_id, $changed_by);
        }

        return Success($position_id);
    }

    /** Hard cap on one reorder batch; a sibling group this large is a malformed request. */
    public const REORDER_MAX_BATCH = 500;

    /**
     * Renumber one sibling group's sort_order in a single atomic call.
     *
     * Reordering used to mean one EditPosition() per row, each writing a single
     * sort_order. N sequential writes can fail halfway, and there is no way to tell
     * from the outside how far the list got -- the group is simply left scrambled.
     * This renumbers the whole group 10, 20, 30, ... in one statement inside one
     * transaction.
     *
     * Renumbering (rather than swapping two values) is deliberate: rows created
     * before the sort_order convention existed, or seeded by a migration, routinely
     * share an identical sort_order, and a swap of two equal values is a no-op that
     * looks like a successful reorder.
     *
     * WHERE THE NEW ORDER IS STORED depends on who owns the row. A kingdom-owned row
     * keeps its order in officer_position.sort_order. A SHARED system row
     * (kingdom_id = 0) is read by all 37 kingdoms, so the acting kingdom's order goes
     * into its own officer_position_alias row instead -- the same table that already
     * holds its custom TITLE for that shared position. Reads resolve the two through
     * SortOrderSql(), so a group mixing both kinds renumbers coherently.
     *
     * VALIDATION RUNS TO COMPLETION BEFORE ANY WRITE. The group is identified by
     * ($kingdom_id, $parent_position_id), so an id that is not really in that group
     * must be refused: otherwise a caller could reparent a position, or write to
     * another kingdom's row, just by listing it under the wrong group. Visibility
     * matches GetPositions() -- shared system rows (kingdom_id = 0) plus the
     * kingdom's own rows -- so this endpoint is neither looser nor tighter than the
     * per-row EditPosition() path it replaces.
     *
     * Ids that belong to the group but are NOT listed keep their existing
     * sort_order; only the listed rows are renumbered. Retired rows are eligible
     * (they still occupy a slot in the tree the UI draws).
     *
     * @param int      $kingdom_id           Acting kingdom; required (> 0).
     * @param int|null $parent_position_id   0/''/null = the top-level group (parent IS NULL).
     * @param array    $ordered_position_ids Position ids, first = topmost.
     * @param int      $acting_uid           Actor, for future auditing; not written today.
     * @return array  Success(list of ids in applied order) | InvalidParameter | NoAuthorization | ProcessingError
     */
    public function ReorderSiblings($kingdom_id, $parent_position_id, array $ordered_position_ids, $acting_uid = 0)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;

        if ($kingdom_id <= 0) {
            return InvalidParameter(null, 'A valid kingdom is required to reorder positions.');
        }

        // 0 / '' / null all mean "the top-level group" (parent_position_id IS NULL).
        $parent_position_id = ($parent_position_id === null || $parent_position_id === ''
            || (int) $parent_position_id === 0) ? null : (int) $parent_position_id;

        // mysql_real_escape_string() is a no-op shim in this codebase, so every id
        // that reaches SQL below is (int)-cast here and never re-read from input.
        $ids = [];
        foreach ($ordered_position_ids as $raw) {
            $pid = (int) $raw;
            if ($pid <= 0) {
                continue;
            }
            if (in_array($pid, $ids, true)) {
                // A repeated id would give one row two positions in the order; the
                // CASE below would silently pick one. Refuse instead.
                return InvalidParameter(null, 'The same position was listed more than once.');
            }
            $ids[] = $pid;
        }
        if (count($ids) === 0) {
            return InvalidParameter(null, 'No positions were supplied to reorder.');
        }
        if (count($ids) > self::REORDER_MAX_BATCH) {
            return InvalidParameter(null, 'Too many positions were supplied to reorder at once.');
        }

        $DB->BeginTrans();

        // Load every listed row once, inside the transaction, so validation reads the
        // same snapshot the UPDATE writes.
        $DB->Clear();
        $r = $DB->DataSet(
            "SELECT position_id, kingdom_id, parent_position_id, canonical_key
    		 FROM " . DB_PREFIX . "officer_position
    		 WHERE position_id IN (" . implode(', ', $ids) . ")"
        );
        $found = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->Next()) {
                $next = $r->parent_position_id;
                $found[ (int) $r->position_id ] = [
                    'kingdom_id'    => (int) $r->kingdom_id,
                    'canonical_key' => (string) $r->canonical_key,
                    'parent'        => ($next === null || $next === '') ? null : (int) $next,
                ];
            }
        }

        $failure = null;
        foreach ($ids as $pid) {
            if (!isset($found[ $pid ])) {
                $failure = InvalidParameter(null, 'One of the positions to reorder no longer exists.');
                break;
            }
            // Visibility, mirroring GetPositions(): shared system rows (kingdom_id 0)
            // plus this kingdom's own rows. Anything else is another kingdom's row.
            if ($found[ $pid ]['kingdom_id'] !== 0 && $found[ $pid ]['kingdom_id'] !== $kingdom_id) {
                $failure = NoAuthorization('One of the positions does not belong to this kingdom.');
                break;
            }
            // Group membership. Without this the endpoint is a reparent primitive:
            // listing a top-level position under a parent id would move it there.
            if ($found[ $pid ]['parent'] !== $parent_position_id) {
                $failure = InvalidParameter(null, 'One of the positions is not in the group being reordered.');
                break;
            }
        }
        if ($failure !== null) {
            $DB->RollbackTrans();
            return $failure;
        }

        // The write splits by ownership. A kingdom-owned row's order lives on the row.
        // A SHARED row (kingdom_id = 0) is read by every kingdom, so this kingdom's
        // order goes into ITS officer_position_alias row instead -- otherwise one
        // kingdom's drag would reorder the officer list for the entire game. A group
        // can legitimately hold both kinds, so the two writes are siblings inside the
        // one transaction and the renumbering runs across the whole group first.
        $owned_cases = [];
        $owned_ids   = [];
        $shared      = [];
        $sort_order  = 0;
        foreach ($ids as $pid) {
            $sort_order += 10;
            if ($found[ $pid ]['kingdom_id'] === 0) {
                $shared[] = ['key' => $found[ $pid ]['canonical_key'], 'so' => $sort_order];
            } else {
                $owned_ids[]   = (int) $pid;
                $owned_cases[] = "WHEN " . (int) $pid . " THEN " . (int) $sort_order;
            }
        }

        // Kingdom-owned rows: one CASE statement, all-integer literals.
        if (count($owned_ids) > 0) {
            $DB->Clear();
            if (!$DB->ExecuteChecked(
                "UPDATE " . DB_PREFIX . "officer_position
    			 SET sort_order = CASE position_id " . implode(' ', $owned_cases) . " ELSE sort_order END
    			 WHERE position_id IN (" . implode(', ', $owned_ids) . ")"
            )) {
                $DB->RollbackTrans();
                return ProcessingError('The positions could not be reordered. Please try again.');
            }
        }

        // Shared rows: one multi-row upsert against uq_kingdom_canonical. Only
        // sort_order is in the UPDATE clause, so a kingdom's custom title_alias on the
        // same row survives a drag untouched (and a brand-new row takes the column's
        // NOT NULL DEFAULT ''). canonical_key is bound, never interpolated --
        // mysql_real_escape_string() is a no-op shim in this codebase.
        if (count($shared) > 0) {
            $DB->Clear();
            $tuples = [];
            $n = 0;
            foreach ($shared as $row) {
                $param = 'rs_key' . $n;
                $DB->$param = $row['key'];
                $tuples[] = "(" . $kingdom_id . ", :" . $param . ", " . (int) $row['so'] . ")";
                $n++;
            }
            if (!$DB->ExecuteChecked(
                "INSERT INTO " . DB_PREFIX . "officer_position_alias (kingdom_id, canonical_key, sort_order)
    			 VALUES " . implode(', ', $tuples) . "
    			 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
            )) {
                $DB->RollbackTrans();
                return ProcessingError('The positions could not be reordered. Please try again.');
            }
        }

        if (!$DB->CommitTrans()) {
            return ProcessingError('The positions could not be reordered. Please try again.');
        }

        return Success($ids);
    }

    /**
     * Validate a proposed parent ("Reports To") for a position.
     *   - Parent must exist.
     *   - Parent's kingdom_id must be 0 (system) OR == the position's kingdom.
     *   - Parent must not equal the position itself (self-parent).
     *   - Parent chain must not loop back through this position (cycle).
     *
     * $position_id is 0 on CreatePosition (no row yet) — only the scope check and
     * cycle walk (which is a no-op for an id that does not yet exist) apply.
     *
     * @return true on success, or an error response array.
     */
    private function ValidateParent($position_id, $pos_kingdom_id, $proposed_parent_id)
    {
        global $DB;
        $position_id = (int) $position_id;
        $pos_kingdom_id = (int) $pos_kingdom_id;
        $proposed_parent_id = (int) $proposed_parent_id;

        if ($position_id > 0 && $proposed_parent_id === $position_id) {
            return InvalidParameter(null, 'A position cannot report to itself.');
        }

        $DB->Clear();
        $DB->vp_pid = $proposed_parent_id;
        $r = $DB->DataSet(
            "SELECT position_id, kingdom_id FROM " . DB_PREFIX . "officer_position
    		 WHERE position_id = :vp_pid LIMIT 1"
        );
        if ($r === false || $r->size() == 0 || !$r->Next()) {
            return InvalidParameter(null, 'The selected parent position does not exist.');
        }
        $parent_kingdom_id = (int) $r->kingdom_id;
        if ($parent_kingdom_id !== 0 && $parent_kingdom_id !== $pos_kingdom_id) {
            return InvalidParameter(null, 'A position can only report to a system position or one in the same kingdom.');
        }

        if ($position_id > 0 && $this->WouldCreateCycle($position_id, $proposed_parent_id, $pos_kingdom_id)) {
            return InvalidParameter(null, 'A position cannot report to its own descendant.');
        }

        return true;
    }

    /**
     * Walk the parent chain upward from $proposed_parent_id. If we ever reach
     * $position_id, assigning that parent would form a cycle.
     *
     * P1: load the kingdom's parent map (shared system rows + this kingdom's rows)
     * in ONE query, then walk the chain in PHP with a visited-set guard, instead of
     * issuing one query per ancestor hop.
     */
    private function WouldCreateCycle($position_id, $proposed_parent_id, $pos_kingdom_id = 0)
    {
        global $DB;
        $position_id = (int) $position_id;
        $pos_kingdom_id = (int) $pos_kingdom_id;

        // Build position_id => parent_position_id for the visible scope in one query.
        $DB->Clear();
        $DB->wc_kid = $pos_kingdom_id;
        $r = $DB->DataSet(
            "SELECT position_id, parent_position_id FROM " . DB_PREFIX . "officer_position
    		 WHERE kingdom_id = 0 OR kingdom_id = :wc_kid"
        );
        $parent_of = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->Next()) {
                $next = $r->parent_position_id;
                $parent_of[ (int) $r->position_id ] = ($next === null || $next === '') ? 0 : (int) $next;
            }
        }

        $cursor = (int) $proposed_parent_id;
        $visited = [];
        while ($cursor > 0) {
            if ($cursor === $position_id) {
                return true;
            }
            if (isset($visited[ $cursor ])) {
                // Pre-existing cycle in the data, or an id outside the loaded scope:
                // stop walking. Not a NEW cycle through $position_id.
                return false;
            }
            $visited[ $cursor ] = true;
            if (!isset($parent_of[ $cursor ])) {
                return false;
            }
            $cursor = $parent_of[ $cursor ];
        }
        return false;
    }

    /**
     * §4.4 helper: rebind every live occupant's ork_user_role from the old role
     * to the new role for this position's scopes.
     */
    private function ReconcileRoleBinding($position_id, $old_rbac_role_id, $new_rbac_role_id, $changed_by)
    {
        global $DB;
        $position_id = (int) $position_id;
        $changed_by = (int) $changed_by;

        $DB->Clear();
        $DB->rcb_pid = $position_id;
        $occ = $DB->DataSet(
            "SELECT mundane_id, kingdom_id, park_id FROM " . DB_PREFIX . "officer
    		 WHERE position_id = :rcb_pid AND mundane_id > 0"
        );
        if ($occ === false || $occ->size() == 0) {
            return;
        }
        $rows = [];
        while ($occ->Next()) {
            $rows[] = [
                'mundane_id' => (int) $occ->mundane_id,
                'kingdom_id' => (int) $occ->kingdom_id,
                'park_id'    => (int) $occ->park_id,
            ];
        }

        $old_rbac_role_id = (int) $old_rbac_role_id;
        $new_rbac_role_id = (int) $new_rbac_role_id;

        // The revoke and the grant are one atomic unit: a failure between them would
        // strand occupants with NEITHER the old nor the new role. Same
        // BeginTrans/ExecuteChecked/CommitTrans/RollbackTrans pattern as
        // Unit::MergeUnits and Park::MergeParks (transactions live on YapoMysql).
        $DB->BeginTrans();
        try {
            // P2: one batched DELETE for the old role across all occupants, scoped by the
            // occupants' (mundane_id, kingdom_id, park_id) tuples. Integer-cast every id.
            if ($old_rbac_role_id > 0) {
                $tuples = [];
                foreach ($rows as $row) {
                    $tuples[] = "(" . (int) $row['mundane_id'] . ", " . (int) $row['kingdom_id'] . ", " . (int) $row['park_id'] . ")";
                }
                $DB->Clear();
                if (!$DB->ExecuteChecked(
                    "DELETE FROM " . DB_PREFIX . "user_role
    				 WHERE role_id = " . $old_rbac_role_id . "
    				   AND (mundane_id, kingdom_id, park_id) IN (" . implode(', ', $tuples) . ")"
                )) {
                    throw new Exception('officer role revoke failed');
                }
            }

            // P2: one batched multi-row INSERT IGNORE for the new role grant.
            if ($new_rbac_role_id > 0) {
                $granted_by_sql = ($changed_by > 0) ? $changed_by : 'NULL';
                $values = [];
                foreach ($rows as $row) {
                    $values[] = "(" . (int) $row['mundane_id'] . ", " . $new_rbac_role_id . ", " . (int) $row['kingdom_id'] . ", " . (int) $row['park_id'] . ", 0, 0, " . $granted_by_sql . ", NULL)";
                }
                $DB->Clear();
                if (!$DB->ExecuteChecked(
                    "INSERT IGNORE INTO " . DB_PREFIX . "user_role
    				 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
    				 VALUES " . implode(', ', $values)
                )) {
                    throw new Exception('officer role grant failed');
                }
            }
            $DB->CommitTrans();
        } catch (Exception $e) {
            $DB->RollbackTrans();
            return;
        }

        // Per-occupant cache invalidation stays a loop (Memcache, not DB).
        if (isset(Ork3::$Lib->rbacservice)) {
            foreach ($rows as $row) {
                Ork3::$Lib->rbacservice->InvalidateUserCache((int) $row['mundane_id']);
            }
        }
    }

    /**
     * Retire a position: reject pinned/system; auto-vacate all live occupants
     * (closing terms + revoking ork_user_role); set retired_at=NOW(). Returns
     * Success() with the list of vacated occupants in Detail.
     *
     * @param int $position_id
     * @param int $changed_by
     * @return array
     */
    public function RetirePosition($position_id, $changed_by, $acting_kingdom_id = 0)
    {
        global $DB;
        $position_id = (int) $position_id;
        $changed_by = (int) $changed_by;
        $acting_kingdom_id = (int) $acting_kingdom_id;

        $position = $this->GetPosition($position_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        // S1: kingdom-ownership guard (system kingdom_id=0 rows are shared; the
        // is_pinned/is_system check below blocks retiring shared system rows anyway).
        if ((int) $position['KingdomId'] !== 0 && $acting_kingdom_id > 0 && (int) $position['KingdomId'] !== $acting_kingdom_id) {
            return NoAuthorization('Position does not belong to this kingdom.');
        }
        if ((int) $position['IsPinned'] || (int) $position['IsSystem']) {
            return NoAuthorization('System/pinned positions cannot be retired.');
        }

        // Collect live occupants for the warning/audit.
        $DB->Clear();
        $DB->rt_pid = $position_id;
        $occ = $DB->DataSet(
            "SELECT mundane_id, kingdom_id, park_id FROM " . DB_PREFIX . "officer
    		 WHERE position_id = :rt_pid AND mundane_id > 0"
        );
        $vacated = [];
        if ($occ !== false && $occ->size() > 0) {
            while ($occ->Next()) {
                $vacated[] = [
                    'MundaneId' => (int) $occ->mundane_id,
                    'KingdomId' => (int) $occ->kingdom_id,
                    'ParkId'    => (int) $occ->park_id,
                ];
            }
        }

        foreach ($vacated as $v) {
            $this->VacateOfficerByPosition($v['KingdomId'], $v['ParkId'], $position_id, $changed_by);
        }

        $DB->Clear();
        $DB->rtu_pid = $position_id;
        $DB->Execute(
            "UPDATE " . DB_PREFIX . "officer_position SET retired_at = NOW() WHERE position_id = :rtu_pid"
        );

        return Success($vacated);
    }

    /**
     * Reinstate a retired position. Classification is the unchanged column value
     * (retire never touched it), so no snapshot restore is needed.
     *
     * PLACEMENT. Retire sets only retired_at and never touches sort_order, and the
     * UI never lists retired siblings -- so ReorderSiblings() renumbers the group
     * WITHOUT the retired row, and its stale value drifts to mean nothing. A row
     * retired at 15 out of 10/15/20/30 comes back into a group renumbered to
     * 10/20/30 still holding 15, and reappears wedged between the first and second
     * live siblings at a slot nobody chose. Reinstate therefore assigns a fresh
     * sort_order at the END of the row's CURRENT sibling group -- positionally, a
     * reinstated position is a new arrival -- using the same measurement
     * CreatePosition makes (NextSortOrderInGroup()), so the two arrival paths agree
     * on where the end is.
     *
     * WHERE that placement is written depends on who owns the row, exactly as in
     * ReorderSiblings(). A kingdom-owned row keeps its order on the row. A SHARED
     * row (kingdom_id = 0) is read by every kingdom in the game, so writing
     * officer_position.sort_order would re-order the officer list for all of them
     * on one kingdom's reinstate; the acting kingdom's placement goes into its own
     * officer_position_alias row instead. Only sort_order is in that upsert's UPDATE
     * clause, so a kingdom's custom title_alias on the same row survives untouched.
     *
     * With NO acting kingdom (0) and a shared row there is no list to place it into
     * and the only reachable column is the shared one, so the order is deliberately
     * left alone and reinstate does nothing but clear retired_at. Guessing a
     * placement there would mean writing the globally-shared column.
     *
     * @param int $position_id
     * @param int $acting_kingdom_id
     * @return array
     */
    public function ReinstatePosition($position_id, $acting_kingdom_id = 0)
    {
        global $DB;
        $position_id = (int) $position_id;
        $acting_kingdom_id = (int) $acting_kingdom_id;
        $position = $this->GetPosition($position_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        // S1: kingdom-ownership guard (system kingdom_id=0 rows are shared).
        if ((int) $position['KingdomId'] !== 0 && $acting_kingdom_id > 0 && (int) $position['KingdomId'] !== $acting_kingdom_id) {
            return NoAuthorization('Position does not belong to this kingdom.');
        }

        $owner_kingdom_id = (int) $position['KingdomId'];
        // Whose order is being measured. An owned row has exactly one candidate --
        // its own kingdom (the guard above already proved acting == owner whenever
        // acting is known). A shared row has no order of its own worth measuring,
        // since every kingdom sees a different effective one, so it is the ACTING
        // kingdom's list the row is being placed back into.
        $measure_kingdom_id = $owner_kingdom_id > 0 ? $owner_kingdom_id : $acting_kingdom_id;

        // Two writes in the shared case (alias upsert + the row's retired_at), so
        // they commit or roll back together rather than leaving a placement behind
        // for a position that is still retired.
        $DB->BeginTrans();

        $set_sort = '';
        if ($measure_kingdom_id > 0) {
            $sort_order = $this->NextSortOrderInGroup(
                $measure_kingdom_id,
                $position['ParentPositionId'],
                $position_id
            );
            if ($owner_kingdom_id > 0) {
                // Integer from NextSortOrderInGroup(); (int)-cast at the interpolation
                // site rather than bound, so it sits alongside :ri_pid below.
                $set_sort = 'sort_order = ' . (int) $sort_order . ', ';
            } else {
                $DB->Clear();
                $DB->ri_key = $position['CanonicalKey'];
                if (!$DB->ExecuteChecked(
                    "INSERT INTO " . DB_PREFIX . "officer_position_alias (kingdom_id, canonical_key, sort_order)
    				 VALUES (" . (int) $measure_kingdom_id . ", :ri_key, " . (int) $sort_order . ")
    				 ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
                )) {
                    $DB->RollbackTrans();
                    return ProcessingError('The position could not be reinstated. Please try again.');
                }
            }
        }

        $DB->Clear();
        $DB->ri_pid = $position_id;
        if (!$DB->ExecuteChecked(
            "UPDATE " . DB_PREFIX . "officer_position
    		 SET " . $set_sort . "retired_at = NULL
    		 WHERE position_id = :ri_pid"
        )) {
            $DB->RollbackTrans();
            return ProcessingError('The position could not be reinstated. Please try again.');
        }

        if (!$DB->CommitTrans()) {
            return ProcessingError('The position could not be reinstated. Please try again.');
        }
        return Success($position_id);
    }

    // ================================================================
    // OCCUPANCY-ENFORCED OFFICER WRITES
    // ================================================================

    /**
     * Grouped officer display for a scope: ['crown'=>[...], 'supporting'=>[...]].
     * Each entry carries CanonicalKey, DisplayTitle, occupant info, and term line.
     * Retired positions filtered out unless requested.
     *
     * @param int  $kingdom_id
     * @param int  $park_id
     * @param bool $include_retired
     * @return array
     */
    public function GetOfficersForDisplay($kingdom_id, $park_id, $include_retired = false)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $park_id = (int) $park_id;

        $sql = "SELECT o.officer_id, o.mundane_id, o.position_id, o.role,
    			p.canonical_key, p.classification, p.sort_order,
    			p.parent_position_id, p.hide_when_vacant,
    			" . self::DisplayTitleSql('p', 'a') . " AS DisplayTitle,
    			m.persona, m.given_name, m.surname, m.username
    		FROM " . DB_PREFIX . "officer o
    		JOIN " . DB_PREFIX . "officer_position p ON p.position_id = o.position_id
    		LEFT JOIN " . DB_PREFIX . "officer_position_alias a
    		  ON a.kingdom_id = :kid AND a.canonical_key = p.canonical_key
    		LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = o.mundane_id
    		WHERE o.kingdom_id = :kid2 AND o.park_id = :pid";
        if (!$include_retired) {
            $sql .= " AND p.retired_at IS NULL";
        }
        $sql .= " ORDER BY p.classification, " . self::SortOrderSql('p', 'a');

        $DB->Clear();
        $DB->kid = $kingdom_id;
        $DB->kid2 = $kingdom_id;
        $DB->pid = $park_id;
        $r = $DB->DataSet($sql);

        $out = [ 'crown' => [], 'supporting' => [] ];
        if ($r !== false && $r->size() > 0) {
            while ($r->Next()) {
                $group = ($r->classification === 'supporting') ? 'supporting' : 'crown';
                $out[ $group ][] = [
                    'OfficerId'    => (int) $r->officer_id,
                    'PositionId'   => (int) $r->position_id,
                    'CanonicalKey' => $r->canonical_key,
                    'DisplayTitle' => $r->DisplayTitle,
                    'ParentPositionId' => ($r->parent_position_id === null || $r->parent_position_id === '') ? null : (int) $r->parent_position_id,
                    'HideWhenVacant'   => (int) $r->hide_when_vacant,
                    'MundaneId'    => (int) $r->mundane_id,
                    'Persona'      => $r->persona,
                    'GivenName'    => $r->given_name,
                    'Surname'      => $r->surname,
                    'UserName'     => $r->username,
                ];
            }
        }
        return $out;
    }

    /**
     * Move an office from one holder to another as a single recorded transition.
     *
     * This is the API shape every other write in the application uses
     * (compare Player::AddAward): a $request array carrying a Token, the actor
     * resolved from that token rather than asserted by the caller, the permission
     * gate inside the domain, and a dangeraudit row. It is the only officer write
     * that can express a backdated end date or a note, because Common::set_officer's
     * record_officer_history stamps today and has nowhere to put one.
     *
     * A park-scoped call's KingdomId is never trusted: it is derived from the
     * park (matching Park::SetOfficer / GetParkKingdomId) so a park-scoped actor
     * cannot smuggle a foreign kingdom into the ownership guard, the membership
     * check, or the kingdom_id column written to ork_officer/ork_officer_history.
     *
     * The read-modify-write below -- read the current holder, close their term,
     * move the seat, open the new term -- is serialized under the same
     * 'officer_assign_<kingdom>_<park>_<position>' GET_LOCK SetOfficerByPosition
     * takes, and for the same reason: two admins transitioning one office
     * concurrently must not both read the same outgoing holder and both open a
     * term, which would leave two open (end_date IS NULL) rows on one seat. MySQL
     * GET_LOCK is re-entrant per session and Common::set_officer does not take
     * this lock itself, so holding it across the whole span is safe.
     *
     * ork_officer is MyISAM -- no transactions -- so the order is fixed: close
     * the outgoing term, then move the seat, then open the incoming term, and
     * each step's outcome is checked before the next runs. A failure part-way
     * leaves a closed term and either a visibly vacant office or an unchanged
     * one -- self-consistent and re-runnable, never a seated officer with no
     * term or a silently-unmoved seat reported as Success().
     *
     * @param array $request Token, KingdomId, ParkId, PositionId, MundaneId (incoming),
     *                        OutgoingEndDate?, OutgoingStartDate?, TermStart?, Note?
     * @return array
     */
    public function TransitionOfficer($request)
    {
        global $DB;

        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $kingdom_id  = (int) ($request['KingdomId'] ?? 0);
        $park_id     = (int) ($request['ParkId'] ?? 0);
        $position_id = (int) ($request['PositionId'] ?? 0);
        $incoming_id = (int) ($request['MundaneId'] ?? 0);

        $scope     = ($park_id > 0) ? 'park' : 'kingdom';
        $scope_id  = ($park_id > 0) ? $park_id : $kingdom_id;
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id,
            self::PermissionKeyFor('set', $park_id),
            $scope,
            $scope_id,
            AUTH_EDIT
        )) {
            return NoAuthorization();
        }

        // The gate above authorized this actor against the PARK (scope_id =
        // $park_id); $kingdom_id itself is still caller-supplied and unverified.
        // Derive it from the park rather than trust the request -- the legacy
        // path does the same (class.Park.php, GetParkKingdomId) -- so a
        // park-scoped caller cannot name a foreign kingdom, and a caller that
        // simply omits KingdomId on a park-scoped request does not fail the
        // membership check below for every valid player.
        if ($park_id > 0) {
            $park_kingdom_id = Ork3::$Lib->park->GetParkKingdomId($park_id);
            if ($park_kingdom_id === false) {
                return InvalidParameter(null, 'Park not found.');
            }
            $kingdom_id = (int) $park_kingdom_id;
        }

        if (!valid_id($position_id)) {
            return InvalidParameter(null, 'A valid position is required.');
        }
        if (!valid_id($incoming_id)) {
            return InvalidParameter(null, 'A valid member is required.');
        }

        $position = $this->GetPosition($position_id, $kingdom_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        if ((int) $position['KingdomId'] !== 0 && $kingdom_id > 0
            && (int) $position['KingdomId'] !== $kingdom_id) {
            return NoAuthorization(null, 'Position does not belong to this kingdom.');
        }
        if ($position['RetiredAt'] !== null) {
            return InvalidParameter(null, 'Cannot assign an occupant to a retired position.');
        }

        $today   = date('Y-m-d');
        $end     = $this->normalizeDate($request['OutgoingEndDate'] ?? '', $today);
        $start   = $this->normalizeDate($request['TermStart'] ?? '', $end);
        $backfill_start = $this->normalizeDate($request['OutgoingStartDate'] ?? '', '');
        // mb_substr, not substr: Note is free text and a byte-based cut can slice
        // a multi-byte character in half, leaving invalid UTF-8 in the column.
        $note    = mb_substr(trim((string) ($request['Note'] ?? '')), 0, 500);

        if ($end === false || $start === false || $backfill_start === false) {
            return InvalidParameter(null, 'Dates must be in YYYY-MM-DD form.');
        }
        if ($end > $today) {
            return InvalidParameter(null, 'A term cannot end in the future.');
        }
        if ($start < $end) {
            return InvalidParameter(null, 'The incoming term cannot start before the outgoing term ends.');
        }

        // The incoming officer must belong to the org, matching the rule the legacy
        // path has always applied (Kingdom::SetOfficer, class.Kingdom.php:1348).
        $incoming = Ork3::$Lib->player->player_info($incoming_id);
        if (!is_array($incoming) || (int) ($incoming['KingdomId'] ?? 0) !== $kingdom_id) {
            return InvalidParameter(null, 'The new officer must be a member of this kingdom.');
        }

        $canonical_key = $position['CanonicalKey'];

        // Single-occupant-per-office, unconditionally, same as
        // SetOfficerByPosition's crown branch and regardless of Classification.
        // Common::set_officer -- which this method delegates the seat move to --
        // is itself single-slot no matter what the position is classified as, so
        // forking on Classification here would not change how the seat is
        // written, only whether a term-closing bookkeeping step runs against a
        // holder that set_officer will overwrite either way. currentHolder()'s
        // ORDER BY (below) still pins which holder is treated as outgoing if a
        // position ever does carry more than one occupant row.
        $lock_name = 'officer_assign_' . $kingdom_id . '_' . $park_id . '_' . $position_id;
        $locked = false;
        try {
            $DB->Clear();
            $DB->lk = $lock_name;
            $DB->lt = self::CROWN_LOCK_TIMEOUT;
            $lr = $DB->DataSet('SELECT GET_LOCK(:lk, :lt) AS got');
            if ($lr === false || $lr->size() == 0 || !$lr->Next() || (int) $lr->got !== 1) {
                return ProcessingError('Could not acquire the officer assignment lock; please retry.');
            }
            $locked = true;

            $outgoing = $this->currentHolder($kingdom_id, $park_id, $position_id);
            if ($outgoing > 0) {
                $open_start = $this->openTermStart($kingdom_id, $park_id, $position_id, $canonical_key, $outgoing);
                if ($open_start !== null && $open_start !== '' && $end < $open_start) {
                    return InvalidParameter(null, 'A term cannot end before it began.');
                }
                if (!$this->closeTermAt($kingdom_id, $park_id, $position_id, $canonical_key, $outgoing, $end, $backfill_start)) {
                    return ProcessingError(null, 'Could not close the outgoing term.');
                }
            }

            // Move the seat, suppressing set_officer's own history write -- this method
            // owns the term, with dates and a note that record_officer_history cannot carry.
            $c = new Common();
            $this->EnsureCrownSlot($kingdom_id, $park_id, $position_id, $canonical_key);
            $c->set_officer(
                $kingdom_id,
                $park_id,
                $incoming_id,
                $canonical_key,
                0,
                $actor_id,
                $position_id,
                $position['DisplayTitle'],
                true
            );

            // set_officer() returns void and silently no-ops when a has_auth_role
            // position's ork_authorization row is missing -- exactly the shape
            // EnsureCrownSlot's vacant placeholder leaves (authorization_id = 0),
            // and exactly the has_auth_role=1 shape of the Core Five this feature
            // exists for. Re-read the seat rather than trust the call above did
            // anything, so a no-op reassignment is reported as a failure instead
            // of a Success() that closed the outgoing term but left them seated.
            if ($this->currentHolder($kingdom_id, $park_id, $position_id) !== $incoming_id) {
                return ProcessingError(null, 'The office could not be reassigned.');
            }

            if (!$this->openTerm(
                $kingdom_id,
                $park_id,
                $position_id,
                $canonical_key,
                $incoming_id,
                $actor_id,
                $start,
                $note,
                $position['DisplayTitle']
            )) {
                return ProcessingError(null, 'The office was reassigned, but the new term could not be recorded.');
            }
        } finally {
            if ($locked) {
                $DB->Clear();
                $DB->rk = $lock_name;
                $DB->Execute('SELECT RELEASE_LOCK(:rk)');
            }
        }

        $safe = $request;
        unset($safe['Token']);
        Ork3::$Lib->dangeraudit->audit(
            __CLASS__ . '::' . __FUNCTION__,
            $safe,
            ($park_id > 0) ? 'Park' : 'Kingdom',
            $scope_id,
            ['MundaneId' => $outgoing, 'PositionId' => $position_id, 'EndDate' => $end],
            ['MundaneId' => $incoming_id, 'PositionId' => $position_id, 'StartDate' => $start]
        );

        return Success();
    }

    /** '' -> $default; a valid Y-m-d -> itself; anything else -> false. */
    private function normalizeDate($value, $default)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return false;
        }
        return $value;
    }

    /**
     * The seat occupant of record for an office. ORDER BY officer_id makes the
     * choice deterministic if a position ever carries more than one ork_officer
     * row (it should not, for a seat this method treats as single-occupant, but
     * an unordered LIMIT 1 would otherwise let a concurrent write flip which row
     * "currentHolder" means between the read here and the term-closing read
     * that follows it).
     */
    private function currentHolder($kingdom_id, $park_id, $position_id)
    {
        global $DB;
        $DB->Clear();
        $DB->ch_kid = (int) $kingdom_id;
        $DB->ch_pid = (int) $park_id;
        $DB->ch_pos = (int) $position_id;
        $r = $DB->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'officer
             WHERE kingdom_id = :ch_kid AND park_id = :ch_pid
               AND position_id = :ch_pos AND mundane_id > 0
             ORDER BY officer_id LIMIT 1'
        );
        return ($r !== false && $r->size() > 0 && $r->Next()) ? (int) $r->mundane_id : 0;
    }

    /**
     * position_id OR (position_id = 0 AND role match): the same legacy-row
     * pattern CloseOfficerHistoryTerm uses. Migration 2026-08-25-04 only
     * backfilled position_id for the kingdom_id=0 Core Five, so a custom
     * position's pre-existing history rows still carry position_id = 0 and are
     * identified by canonical key alone.
     */
    private function openTermStart($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id)
    {
        global $DB;
        $DB->Clear();
        $DB->os_kid = (int) $kingdom_id;
        $DB->os_pid = (int) $park_id;
        $DB->os_pos = (int) $position_id;
        $DB->os_role = $canonical_key;
        $DB->os_mid = (int) $mundane_id;
        $r = $DB->DataSet(
            'SELECT start_date FROM ' . DB_PREFIX . 'officer_history
             WHERE kingdom_id = :os_kid AND park_id = :os_pid
               AND ( position_id = :os_pos OR ( position_id = 0 AND role = :os_role ) )
               AND mundane_id = :os_mid AND end_date IS NULL
             ORDER BY officer_history_id DESC LIMIT 1'
        );
        return ($r !== false && $r->size() > 0 && $r->Next()) ? $r->start_date : null;
    }

    /**
     * Close the open term at $end. $backfill_start fills a NULL start_date only --
     * a start date already on the row is never overwritten by a transition.
     * Matches openTermStart()'s legacy position_id=0 fallback. Returns false on
     * a genuine statement failure (ExecuteChecked) so the caller can abort
     * before opening a second term against a close that never happened.
     */
    private function closeTermAt($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $end, $backfill_start)
    {
        global $DB;
        $DB->Clear();
        $DB->ct_end = $end;
        $DB->ct_kid = (int) $kingdom_id;
        $DB->ct_pid = (int) $park_id;
        $DB->ct_pos = (int) $position_id;
        $DB->ct_role = $canonical_key;
        $DB->ct_mid = (int) $mundane_id;
        $start_sql = '';
        if ($backfill_start !== '') {
            $DB->ct_start = $backfill_start;
            $start_sql = ', start_date = IF(start_date IS NULL, :ct_start, start_date)';
        }
        return $DB->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . 'officer_history
             SET end_date = :ct_end' . $start_sql . '
             WHERE kingdom_id = :ct_kid AND park_id = :ct_pid
               AND ( position_id = :ct_pos OR ( position_id = 0 AND role = :ct_role ) )
               AND mundane_id = :ct_mid
               AND end_date IS NULL'
        );
    }

    /** Returns false on a genuine statement failure (ExecuteChecked). */
    private function openTerm($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $actor_id, $start, $note, $display_label)
    {
        global $DB;
        $DB->Clear();
        $DB->ot_kid = (int) $kingdom_id;
        $DB->ot_pid = (int) $park_id;
        $DB->ot_mid = (int) $mundane_id;
        $DB->ot_role = $canonical_key;
        $DB->ot_pos = (int) $position_id;
        $DB->ot_label = ($display_label !== '') ? $display_label : $canonical_key;
        $DB->ot_start = $start;
        $DB->ot_cb = ($actor_id > 0 ? $actor_id : null);
        $DB->ot_notes = $note;
        // end_date is a SQL literal NULL, not a bound param -- kept intentionally
        // even though a review of YapoMysql::Execute()/ExecuteChecked() confirmed
        // a bound PHP null on this raw path IS honoured (the null-drop is a
        // YapoSave/ORM behaviour, not this path's); the literal reads clearer
        // regardless of which path drops it.
        return $DB->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'officer_history
             (kingdom_id, park_id, mundane_id, role, position_id, display_label,
              start_date, end_date, changed_by, notes, created_at)
             VALUES (:ot_kid, :ot_pid, :ot_mid, :ot_role, :ot_pos, :ot_label,
                     :ot_start, NULL, :ot_cb, :ot_notes, NOW())'
        );
    }

    /**
     * Set an officer occupant by position, enforcing §3.4 occupancy rules.
     * Occupancy is per-seat, not per-person: the ORK imposes no limit on how
     * many offices a person holds, so Crown here means single-occupant-per-scope
     * (vacate existing, then write) under a GET_LOCK on the seat being written.
     * Supporting: unrestricted, multi-occupant.
     *
     * @param int    $kingdom_id
     * @param int    $park_id
     * @param int    $position_id
     * @param int    $mundane_id
     * @param string $term_start  (unused placeholder for term metadata)
     * @param string $term_end    (unused placeholder for term metadata)
     * @param string $note        (unused placeholder for note metadata)
     * @param int    $changed_by
     * @return array
     */
    public function SetOfficerByPosition($kingdom_id, $park_id, $position_id, $mundane_id, $term_start, $term_end, $note, $changed_by)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $park_id = (int) $park_id;
        $position_id = (int) $position_id;
        $mundane_id = (int) $mundane_id;
        $changed_by = (int) $changed_by;

        $position = $this->GetPosition($position_id, $kingdom_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        // S1: kingdom-ownership guard (system kingdom_id=0 rows are shared; each
        // kingdom legitimately fills its own occupant). Reject a foreign kingdom row.
        if ((int) $position['KingdomId'] !== 0 && $kingdom_id > 0 && (int) $position['KingdomId'] !== $kingdom_id) {
            return NoAuthorization('Position does not belong to this kingdom.');
        }
        if ($position['RetiredAt'] !== null) {
            return InvalidParameter(null, 'Cannot assign an occupant to a retired position.');
        }
        if ($mundane_id <= 0) {
            return InvalidParameter(null, 'A valid member is required.');
        }
        $canonical_key = $position['CanonicalKey'];
        $classification = $position['Classification'];

        if ($classification !== 'crown') {
            // Supporting: no lock, no global check, multiple rows allowed. Each
            // assignment is a fresh ork_officer row (set_officer is single-slot and
            // cannot represent multi-occupant supporting positions).
            $this->InsertOfficerRow($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $changed_by, $term_start, $term_end, $position['DisplayTitle']);
            return Success();
        }

        // Serialize on the OFFICE being written, not the person. The old key
        // ('crown_assign_' . $mundane_id) existed only to serialize a cross-scope
        // uniqueness query that no longer exists; it never guarded the race a
        // transition actually has, which is two admins writing the same seat.
        $lock_name = 'officer_assign_' . $kingdom_id . '_' . $park_id . '_' . $position_id;
        $locked = false;
        try {
            $DB->Clear();
            $DB->lk = $lock_name;
            $DB->lt = self::CROWN_LOCK_TIMEOUT;
            $lr = $DB->DataSet("SELECT GET_LOCK(:lk, :lt) AS got");
            if ($lr === false || $lr->size() == 0 || !$lr->Next() || (int) $lr->got !== 1) {
                return ProcessingError('Could not acquire the crown assignment lock; please retry.');
            }
            $locked = true;

            // Single-occupant-per-scope: set_officer find() keyed on position_id
            // replaces the occupant of the single crown row in place. For custom
            // crown positions no seeded slot exists yet, so ensure one is present
            // (vacant placeholder) before delegating, so find() succeeds.
            $this->EnsureCrownSlot($kingdom_id, $park_id, $position_id, $canonical_key);
            $c = new Common();
            $c->set_officer($kingdom_id, $park_id, $mundane_id, $canonical_key, 0, $changed_by, $position_id, $position['DisplayTitle']);

            return Success();
        } finally {
            if ($locked) {
                $DB->Clear();
                $DB->rk = $lock_name;
                $DB->Execute("SELECT RELEASE_LOCK(:rk)");
            }
        }
    }

    /**
     * Vacate a holder of a position+scope. Vacating ENDS THE TERM -- the officer's
     * ork_officer_history record is stamped with today's end_date so the kingdom's
     * officer history keeps who served and when; history is never deleted. The
     * synced ork_user_role is revoked for whoever is vacated.
     *
     * $mundane_id targets a SINGLE holder (the only sane behaviour for a supporting
     * position with several deputies). Omitting it -- or passing 0 -- is the
     * explicit "Vacate All" case and affects every holder of the position in this
     * scope. Callers that mean one person MUST pass the id.
     *
     * Occupancy representation stays consistent with the crown convention that the
     * rest of the system reads: an office with nobody in it keeps exactly one
     * ork_officer row with mundane_id = 0. Crown reaches that via
     * Common::set_officer(0). Supporting is multi-occupant, so a surplus holder's
     * row is removed, but the LAST holder's row is blanked to mundane_id = 0 rather
     * than deleted -- that keeps the office visible as vacant (hide_when_vacant
     * still applies) and, more importantly, keeps officer_id/authorization_id
     * instead of re-creating the row with authorization_id = 0.
     *
     * ork_officer is MyISAM -- no transactions -- so writes are ordered
     * history-close -> officer row -> RBAC revoke. A failure part-way can leave a
     * closed term with a role still granted (visible and re-runnable), never a
     * revoked role with an open term claiming the person is still serving.
     *
     * @param int $kingdom_id
     * @param int $park_id
     * @param int $position_id
     * @param int $changed_by
     * @param int $mundane_id  Optional single holder to vacate; 0/omitted = all holders.
     * @return array
     */
    public function VacateOfficerByPosition($kingdom_id, $park_id, $position_id, $changed_by, $mundane_id = 0)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $park_id = (int) $park_id;
        $position_id = (int) $position_id;
        $changed_by = (int) $changed_by;
        $mundane_id = (int) $mundane_id;
        if ($mundane_id < 0) {
            $mundane_id = 0;
        }

        $position = $this->GetPosition($position_id, $kingdom_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        // S1: kingdom-ownership guard (system kingdom_id=0 rows are shared).
        if ((int) $position['KingdomId'] !== 0 && $kingdom_id > 0 && (int) $position['KingdomId'] !== $kingdom_id) {
            return NoAuthorization('Position does not belong to this kingdom.');
        }
        $canonical_key = $position['CanonicalKey'];
        $classification = $position['Classification'];
        $display_label = isset($position['DisplayTitle']) ? (string) $position['DisplayTitle'] : '';

        if ($classification === 'supporting') {
            // Supporting positions can have multiple occupants. Read every occupied
            // row for the scope up front (never re-query this handle inside a
            // ->Next() walk) so we know both who is targeted and how many holders
            // remain -- the last one is blanked rather than deleted.
            $DB->Clear();
            $DB->vs_kid = $kingdom_id;
            $DB->vs_pid = $park_id;
            $DB->vs_pos = $position_id;
            $rows = $DB->DataSet(
                "SELECT officer_id, mundane_id, modified FROM " . DB_PREFIX . "officer
    			 WHERE kingdom_id = :vs_kid AND park_id = :vs_pid AND position_id = :vs_pos
    			   AND mundane_id > 0
    			 ORDER BY officer_id"
            );
            $occupied = [];
            if ($rows !== false && $rows->size() > 0) {
                while ($rows->Next()) {
                    $occupied[] = [
                        'officer_id' => (int) $rows->officer_id,
                        'mundane_id' => (int) $rows->mundane_id,
                        'modified'   => (string) $rows->modified,
                    ];
                }
            }
            $targets = [];
            foreach ($occupied as $row) {
                if ($mundane_id === 0 || $row['mundane_id'] === $mundane_id) {
                    $targets[] = $row;
                }
            }
            if (count($targets) === 0) {
                if ($mundane_id > 0) {
                    return InvalidParameter(null, 'That member does not currently hold this position.');
                }
                return Success(); // Vacate All on an already-empty office: no-op.
            }

            $remaining = count($occupied);
            $handled = [];
            foreach ($targets as $row) {
                $mid = $row['mundane_id'];

                // 1. End the term FIRST so a later failure can never leave a revoked
                //    role sitting behind an open (still-serving) history record.
                //    Guarded per person: a duplicate occupancy row must not backfill
                //    a second closed history record.
                if (!isset($handled[$mid])) {
                    $this->CloseOfficerHistoryTerm(
                        $kingdom_id,
                        $park_id,
                        $position_id,
                        $canonical_key,
                        $mid,
                        $changed_by,
                        $display_label,
                        $row['modified']
                    );
                }

                // 2. Free the occupancy row. Targeting officer_id is what keeps a
                //    six-deputy office from being emptied by one click. The final
                //    holder's row is blanked to mundane_id = 0 (the crown "vacant"
                //    convention) instead of deleted, preserving officer_id and any
                //    authorization_id binding.
                $DB->Clear();
                $DB->vd_oid = $row['officer_id'];
                if ($remaining > 1) {
                    $DB->Execute(
                        "DELETE FROM " . DB_PREFIX . "officer WHERE officer_id = :vd_oid"
                    );
                } else {
                    $DB->Execute(
                        "UPDATE " . DB_PREFIX . "officer
    					 SET mundane_id = 0, modified = NOW()
    					 WHERE officer_id = :vd_oid"
                    );
                }
                $remaining--;

                // 3. Revoke the synced RBAC role for this holder.
                if (!isset($handled[$mid]) && isset(Ork3::$Lib->rbacservice)) {
                    try {
                        Ork3::$Lib->rbacservice->SyncOfficerRoleByPositionId($mid, 0, $position_id, $kingdom_id, $park_id, $changed_by);
                    } catch (Exception $e) {
                        logtrace('RBAC vacate supporting failed', $e->getMessage());
                    }
                }
                $handled[$mid] = true;
            }
            return Success();
        }

        // Crown is single-slot: reject a single-holder request aimed at somebody who
        // is not the sitting officer rather than silently vacating whoever is.
        if ($mundane_id > 0) {
            $DB->Clear();
            $DB->vc_kid = $kingdom_id;
            $DB->vc_pid = $park_id;
            $DB->vc_pos = $position_id;
            $DB->vc_mid = $mundane_id;
            $cur = $DB->DataSet(
                "SELECT officer_id FROM " . DB_PREFIX . "officer
    			 WHERE kingdom_id = :vc_kid AND park_id = :vc_pid
    			   AND position_id = :vc_pos AND mundane_id = :vc_mid LIMIT 1"
            );
            if ($cur === false || $cur->size() == 0) {
                return InvalidParameter(null, 'That member does not currently hold this position.');
            }
        }

        // Crown: close the term + revoke role (mundane_id = 0 means vacate),
        // leaving a vacant placeholder row. set_officer() closes the open history
        // term via record_officer_history before the RBAC sync runs.
        $c = new Common();
        $c->set_officer($kingdom_id, $park_id, 0, $canonical_key, 0, $changed_by, $position_id, $display_label);
        return Success();
    }

    // ================================================================
    // LOW-LEVEL OFFICER ROW WRITES (occupancy support)
    // ================================================================

    /**
     * Ensure a single ork_officer slot exists for a crown position+scope so the
     * single-slot Common::set_officer find() succeeds. Inserts a vacant
     * (mundane_id=0) placeholder only when no row exists for this position+scope.
     */
    private function EnsureCrownSlot($kingdom_id, $park_id, $position_id, $canonical_key)
    {
        global $DB;
        $DB->Clear();
        $DB->ec_kid = (int) $kingdom_id;
        $DB->ec_pid = (int) $park_id;
        $DB->ec_pos = (int) $position_id;
        $ex = $DB->DataSet(
            "SELECT officer_id FROM " . DB_PREFIX . "officer
    		 WHERE kingdom_id = :ec_kid AND park_id = :ec_pid AND position_id = :ec_pos LIMIT 1"
        );
        if ($ex !== false && $ex->size() > 0) {
            return;
        }
        $DB->Clear();
        $DB->ic_kid = (int) $kingdom_id;
        $DB->ic_pid = (int) $park_id;
        $DB->ic_pos = (int) $position_id;
        $DB->ic_role = $canonical_key;
        $DB->Execute(
            "INSERT INTO " . DB_PREFIX . "officer
    		 (kingdom_id, park_id, mundane_id, role, system, authorization_id, position_id, modified)
    		 VALUES (:ic_kid, :ic_pid, 0, :ic_role, 0, 0, :ic_pos, NOW())"
        );
    }

    /**
     * End one holder's ork_officer_history term for a position+scope.
     *
     * Common::record_officer_history() closes EVERY open term for the role/position
     * regardless of who holds it, which is wrong for a multi-occupant supporting
     * position, so the close is done here with a mundane_id filter.
     *
     * When the holder has no open term -- appointed before officer history existed,
     * or by a legacy path that never opened one -- a completed record is written
     * instead, so vacating always leaves history showing that the person served and
     * when the service ended rather than erasing them.
     *
     * @param int    $kingdom_id
     * @param int    $park_id
     * @param int    $position_id
     * @param string $canonical_key
     * @param int    $mundane_id
     * @param int    $changed_by
     * @param string $display_label Snapshot title for a backfilled record.
     * @param string $held_since    ork_officer.modified, the best available start
     *                              date when a record has to be backfilled.
     * @return void
     */
    private function CloseOfficerHistoryTerm($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $changed_by, $display_label = '', $held_since = '')
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $park_id = (int) $park_id;
        $position_id = (int) $position_id;
        $mundane_id = (int) $mundane_id;
        $changed_by = (int) $changed_by;
        if ($mundane_id <= 0) {
            return;
        }
        $today = date('Y-m-d');

        // Legacy rows written before position_id existed carry position_id = 0 and
        // identify the office by canonical key alone; match those too.
        $match = "kingdom_id = :ch_kid AND park_id = :ch_pid AND mundane_id = :ch_mid
    		   AND ( position_id = :ch_pos OR ( position_id = 0 AND role = :ch_role ) )
    		   AND end_date IS NULL";

        $DB->Clear();
        $DB->ch_kid = $kingdom_id;
        $DB->ch_pid = $park_id;
        $DB->ch_mid = $mundane_id;
        $DB->ch_pos = $position_id;
        $DB->ch_role = $canonical_key;
        $open = $DB->DataSet(
            "SELECT officer_history_id FROM " . DB_PREFIX . "officer_history
    		 WHERE " . $match . " LIMIT 1"
        );

        if ($open !== false && $open->size() > 0) {
            // Execute() reports no row count, so the SELECT above is what tells us an
            // open term exists; close it with today's date.
            $DB->Clear();
            $DB->ch_end = $today;
            $DB->ch_kid = $kingdom_id;
            $DB->ch_pid = $park_id;
            $DB->ch_mid = $mundane_id;
            $DB->ch_pos = $position_id;
            $DB->ch_role = $canonical_key;
            $DB->Execute(
                "UPDATE " . DB_PREFIX . "officer_history
    			 SET end_date = :ch_end
    			 WHERE " . $match
            );
            return;
        }

        // No open term: backfill a completed one so the service is still recorded.
        $start = substr(trim((string) $held_since), 0, 10);
        if ($start === '' || $start === '0000-00-00' || $start > $today) {
            $start = $today;
        }
        $label = (trim((string) $display_label) !== '') ? (string) $display_label : $canonical_key;
        $DB->Clear();
        $DB->cb_kid = $kingdom_id;
        $DB->cb_pid = $park_id;
        $DB->cb_mid = $mundane_id;
        $DB->cb_role = $canonical_key;
        $DB->cb_pos = $position_id;
        $DB->cb_label = $label;
        $DB->cb_start = $start;
        $DB->cb_end = $today;
        $DB->cb_cb = ($changed_by > 0 ? $changed_by : null);
        $DB->Execute(
            "INSERT INTO " . DB_PREFIX . "officer_history
    		 (kingdom_id, park_id, mundane_id, role, position_id, display_label, start_date, end_date, changed_by, created_at)
    		 VALUES (:cb_kid, :cb_pid, :cb_mid, :cb_role, :cb_pos, :cb_label, :cb_start, :cb_end, :cb_cb, NOW())"
        );
    }

    /**
     * Insert a fresh ork_officer row for a multi-occupant (supporting) position,
     * record history, and sync the RBAC role. Skips a duplicate active occupant.
     */
    private function InsertOfficerRow($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $changed_by, $term_start = '', $term_end = '', $display_label = '')
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $park_id = (int) $park_id;
        $position_id = (int) $position_id;
        $mundane_id = (int) $mundane_id;
        $changed_by = (int) $changed_by;

        // Idempotency: do not add the same person twice to the same supporting slot.
        $DB->Clear();
        $DB->io_kid = $kingdom_id;
        $DB->io_pid = $park_id;
        $DB->io_pos = $position_id;
        $DB->io_mid = $mundane_id;
        $dup = $DB->DataSet(
            "SELECT officer_id FROM " . DB_PREFIX . "officer
    		 WHERE kingdom_id = :io_kid AND park_id = :io_pid AND position_id = :io_pos AND mundane_id = :io_mid LIMIT 1"
        );
        if ($dup !== false && $dup->size() > 0) {
            return;
        }

        $DB->Clear();
        $DB->ins_kid = $kingdom_id;
        $DB->ins_pid = $park_id;
        $DB->ins_mid = $mundane_id;
        $DB->ins_role = $canonical_key;
        $DB->ins_pos = $position_id;
        $DB->Execute(
            "INSERT INTO " . DB_PREFIX . "officer
    		 (kingdom_id, park_id, mundane_id, role, system, authorization_id, position_id, modified)
    		 VALUES (:ins_kid, :ins_pid, :ins_mid, :ins_role, 0, 0, :ins_pos, NOW())"
        );

        // Open an ork_officer_history term for this supporting appointment so the
        // grant is audit-visible (matches Common::record_officer_history columns;
        // record_officer_history is private, so write the open term directly here).
        $start = (trim((string) $term_start) !== '') ? (string) $term_start : date('Y-m-d');
        $has_end = (trim((string) $term_end) !== '');
        $label = (trim((string) $display_label) !== '') ? (string) $display_label : $canonical_key;
        $DB->Clear();
        $DB->ih_kid = $kingdom_id;
        $DB->ih_pid = $park_id;
        $DB->ih_mid = $mundane_id;
        $DB->ih_role = $canonical_key;
        $DB->ih_pos = $position_id;
        $DB->ih_label = $label;
        $DB->ih_start = $start;
        $DB->ih_cb = ($changed_by > 0 ? $changed_by : null);
        // C2: end_date is written as a SQL literal so an open term truly stores NULL.
        // Binding a PHP null can be skipped by the DB layer (yapo/null-skip rule),
        // leaving a stale value; mirror the parent_position_id = NULL literal pattern.
        if ($has_end) {
            $DB->ih_end = (string) $term_end;
            $end_sql = ':ih_end';
        } else {
            $end_sql = 'NULL';
        }
        $DB->Execute(
            "INSERT INTO " . DB_PREFIX . "officer_history
    		 (kingdom_id, park_id, mundane_id, role, position_id, display_label, start_date, end_date, changed_by, created_at)
    		 VALUES (:ih_kid, :ih_pid, :ih_mid, :ih_role, :ih_pos, :ih_label, :ih_start, " . $end_sql . ", :ih_cb, NOW())"
        );

        // RBAC grant via the shared service.
        if (isset(Ork3::$Lib->rbacservice)) {
            try {
                Ork3::$Lib->rbacservice->SyncOfficerRoleByPositionId(0, $mundane_id, $position_id, $kingdom_id, $park_id, $changed_by);
            } catch (Exception $e) {
                logtrace('RBAC supporting grant failed', $e->getMessage());
            }
        }
    }
}
