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
 *   - Crown occupancy (single-per-scope, crown-per-person) is enforced at the
 *     app layer because ork_officer is MyISAM (no transactions, no partial unique
 *     indexes); the crown-per-person SELECT-then-write is serialized per person
 *     with GET_LOCK('crown_assign_<mundane_id>', timeout) / RELEASE_LOCK in finally.
 ***/

class OfficerPosition extends Ork3
{
	const CROWN_LOCK_TIMEOUT = 5; // seconds for GET_LOCK on crown assignment

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
	public function GetPositions( $kingdom_id, $include_retired = false, $classification = null )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;

		$sql = "SELECT p.*,
				IF(p.kingdom_id = 0,
				   IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
				   IF(p.title_alias != '', p.title_alias, p.title)) AS DisplayTitle
			FROM " . DB_PREFIX . "officer_position p
			LEFT JOIN " . DB_PREFIX . "officer_position_alias a
			  ON a.kingdom_id = :kingdom_id AND a.canonical_key = p.canonical_key
			WHERE (p.kingdom_id = 0 OR p.kingdom_id = :kingdom_id2)";
		if ( !$include_retired ) {
			$sql .= " AND p.retired_at IS NULL";
		}
		if ( $classification !== null ) {
			$sql .= " AND p.classification = :classification";
		}
		$sql .= " ORDER BY p.classification, p.sort_order";

		$DB->Clear();
		$DB->kingdom_id = $kingdom_id;
		$DB->kingdom_id2 = $kingdom_id;
		if ( $classification !== null ) {
			$DB->classification = $classification;
		}
		$r = $DB->DataSet( $sql );

		$positions = [];
		if ( $r !== false && $r->size() > 0 ) {
			while ( $r->Next() ) {
				$positions[] = $this->RowToArray( $r );
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
	public function GetPosition( $position_id, $kingdom_id = 0 )
	{
		global $DB;
		$position_id = (int) $position_id;
		$kingdom_id = (int) $kingdom_id;
		if ( $position_id <= 0 ) {
			return false;
		}

		$DB->Clear();
		$DB->pid = $position_id;
		$DB->gp_kid = $kingdom_id;
		$r = $DB->DataSet(
			"SELECT p.*,
				IF(p.kingdom_id = 0,
				   IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
				   IF(p.title_alias != '', p.title_alias, p.title)) AS DisplayTitle
			FROM " . DB_PREFIX . "officer_position p
			LEFT JOIN " . DB_PREFIX . "officer_position_alias a
			  ON a.kingdom_id = :gp_kid AND a.canonical_key = p.canonical_key
			WHERE p.position_id = :pid LIMIT 1"
		);
		if ( $r === false || $r->size() == 0 || !$r->Next() ) {
			return false;
		}
		$row = $this->RowToArray( $r );

		// Permission summary for the bound role.
		$row['Permissions'] = [];
		$rbac_role_id = (int) $row['RbacRoleId'];
		if ( $rbac_role_id > 0 ) {
			$DB->Clear();
			$DB->rid = $rbac_role_id;
			$pr = $DB->DataSet(
				"SELECT pm.`key` AS perm_key
				 FROM " . DB_PREFIX . "role_permission rp
				 JOIN " . DB_PREFIX . "permission pm ON pm.permission_id = rp.permission_id
				 WHERE rp.role_id = :rid
				 ORDER BY pm.`key`"
			);
			if ( $pr !== false && $pr->size() > 0 ) {
				while ( $pr->Next() ) {
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
	private function RowToArray( $r )
	{
		return [
			'PositionId'    => (int) $r->position_id,
			'KingdomId'     => (int) $r->kingdom_id,
			'CanonicalKey'  => $r->canonical_key,
			'Title'         => $r->title,
			'TitleAlias'    => $r->title_alias,
			'DisplayTitle'  => $r->DisplayTitle,
			'Classification'=> $r->classification,
			'IsPinned'      => (int) $r->is_pinned,
			'IsSystem'      => (int) $r->is_system,
			'RbacRoleId'    => (int) $r->rbac_role_id,
			'HasAuthRole'   => (int) $r->has_auth_role,
			'SortOrder'     => (int) $r->sort_order,
			'ParentPositionId'   => ( $r->parent_position_id === null || $r->parent_position_id === '' ) ? null : (int) $r->parent_position_id,
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
	public function ResolvePositionId( $kingdom_id, $roleOrKey )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$key = $this->NormalizeToCanonicalKey( $roleOrKey );

		$DB->Clear();
		$DB->rp_key = $key;
		$DB->rp_kid = $kingdom_id;
		$r = $DB->DataSet(
			"SELECT position_id FROM " . DB_PREFIX . "officer_position
			 WHERE canonical_key = :rp_key AND (kingdom_id = 0 OR kingdom_id = :rp_kid)
			 ORDER BY kingdom_id DESC LIMIT 1"
		);
		if ( $r !== false && $r->size() > 0 && $r->Next() ) {
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
	public function ResolveCanonicalKey( $kingdom_id, $roleOrKey )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$key = $this->NormalizeToCanonicalKey( $roleOrKey );

		$DB->Clear();
		$DB->rc_key = $key;
		$DB->rc_kid = $kingdom_id;
		$r = $DB->DataSet(
			"SELECT canonical_key FROM " . DB_PREFIX . "officer_position
			 WHERE canonical_key = :rc_key AND (kingdom_id = 0 OR kingdom_id = :rc_kid)
			 ORDER BY kingdom_id DESC LIMIT 1"
		);
		if ( $r !== false && $r->size() > 0 && $r->Next() ) {
			return $r->canonical_key;
		}
		return $key;
	}

	/**
	 * Map a legacy display string to its canonical key; pass through anything
	 * that already looks like a canonical key (lowercase/underscore slug).
	 */
	private function NormalizeToCanonicalKey( $roleOrKey )
	{
		$roleOrKey = trim( (string) $roleOrKey );
		$map = [
			'Monarch'        => 'monarch',
			'Regent'         => 'regent',
			'Prime Minister' => 'prime_minister',
			'Champion'       => 'champion',
			'GMR'            => 'gmr',
		];
		if ( isset( $map[ $roleOrKey ] ) ) {
			return $map[ $roleOrKey ];
		}
		return $this->Slugify( $roleOrKey );
	}

	/**
	 * Slugify a title into a canonical key (lowercase, underscores, alnum).
	 */
	private function Slugify( $value )
	{
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		$value = trim( $value, '_' );
		return $value;
	}

	// ================================================================
	// REGISTRY WRITES
	// ================================================================

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
	public function CreatePosition( $kingdom_id, $canonical_key, $title, $classification, $rbac_choice, $creator_id = 0, $parent_position_id = null, $hide_when_vacant = 0 )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$title = trim( (string) $title );
		$creator_id = (int) $creator_id;

		if ( $kingdom_id <= 0 ) {
			return InvalidParameter( null, 'A valid kingdom is required to create a position.' );
		}
		if ( $title === '' ) {
			return InvalidParameter( null, 'A position title is required.' );
		}
		if ( $classification !== 'crown' && $classification !== 'supporting' ) {
			return InvalidParameter( null, 'Classification must be crown or supporting.' );
		}

		$slug = ( trim( (string) $canonical_key ) !== '' ) ? $this->Slugify( $canonical_key ) : $this->Slugify( $title );
		if ( $slug === '' ) {
			return InvalidParameter( null, 'Could not derive a canonical key for this position.' );
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
		if ( $exists !== false && $exists->size() > 0 ) {
			return InvalidParameter( null, 'A position with this key already exists for this kingdom.' );
		}

		// Resolve the RBAC role binding.
		$rbac_role_id = 0;
		$created_custom_role_id = 0; // C1: set when CreateRole runs in custom mode
		if ( is_array( $rbac_choice ) && isset( $rbac_choice['mode'] ) && $rbac_choice['mode'] === 'custom' ) {
			$permission_keys = isset( $rbac_choice['permission_keys'] ) ? $rbac_choice['permission_keys'] : [];
			if ( !isset( Ork3::$Lib->rbacservice ) ) {
				return ProcessingError( 'RBAC service unavailable; cannot create custom role.' );
			}
			$res = Ork3::$Lib->rbacservice->CreateRole(
				$creator_id, $kingdom_id, 'officer:' . $slug, $title, '', 'kingdom', $permission_keys
			);
			if ( !isset( $res['Status'] ) || $res['Status'] != 0 ) {
				return $res;
			}
			$rbac_role_id = (int) $res['Detail'];
			// C1: remember that THIS call created the role, so we can roll it back if
			// the position INSERT below fails (avoid orphaning a custom role).
			$created_custom_role_id = $rbac_role_id;
		} else if ( is_array( $rbac_choice ) && isset( $rbac_choice['mode'] ) && $rbac_choice['mode'] === 'existing' ) {
			$rbac_role_id = isset( $rbac_choice['role_id'] ) ? (int) $rbac_choice['role_id'] : 0;
		}
		// 'none' mode: rbac_role_id stays 0 (the holder gets no extra access). A 0
		// binding is valid only for explicit 'none'; otherwise a binding is required.
		$rbac_mode = ( is_array( $rbac_choice ) && isset( $rbac_choice['mode'] ) ) ? $rbac_choice['mode'] : '';
		if ( $rbac_mode !== 'none' && $rbac_role_id <= 0 ) {
			return InvalidParameter( null, 'A valid RBAC role binding is required.' );
		}

		// hide_when_vacant applies to NON-Crown only; force 0 for crown.
		$hide_when_vacant = ( $classification === 'crown' ) ? 0 : ( ( (int) $hide_when_vacant ) ? 1 : 0 );

		// parent_position_id ("Reports To"). 0/''/null = top-level (NULL stored).
		$parent_position_id = ( $parent_position_id === null || $parent_position_id === '' || (int) $parent_position_id === 0 )
			? null : (int) $parent_position_id;
		if ( $parent_position_id !== null ) {
			$perr = $this->ValidateParent( 0, $kingdom_id, $parent_position_id );
			if ( $perr !== true ) {
				return $perr;
			}
		}

		// sort_order = max in group + 10.
		$DB->Clear();
		$DB->so_kid = $kingdom_id;
		$DB->so_cls = $classification;
		$mx = $DB->DataSet(
			"SELECT MAX(sort_order) AS mx FROM " . DB_PREFIX . "officer_position
			 WHERE (kingdom_id = 0 OR kingdom_id = :so_kid) AND classification = :so_cls"
		);
		$sort_order = 100;
		if ( $mx !== false && $mx->size() > 0 && $mx->Next() ) {
			$sort_order = ( (int) $mx->mx ) + 10;
		}

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
		$parent_sql = ( $parent_position_id === null ) ? 'NULL' : (int) $parent_position_id;
		$DB->Execute(
			"INSERT INTO " . DB_PREFIX . "officer_position
			 (kingdom_id, canonical_key, title, title_alias, classification, is_pinned, is_system, rbac_role_id, has_auth_role, sort_order, parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
			 VALUES (:c_kid, :c_key, :c_title, '', :c_cls, 0, 0, :c_rid, 0, :c_so, " . $parent_sql . ", :c_hwv, NULL, :c_cb, NOW())"
		);

		// Prefer the driver's last-insert-id accessor over a SELECT-after-INSERT.
		$position_id = (int) $DB->GetLastInsertId();
		if ( $position_id <= 0 ) {
			// Fallback: UNIQUE(kingdom_id, canonical_key) makes this lookup safe.
			$position_id = $this->ResolvePositionId( $kingdom_id, $slug );
		}

		// C1: the INSERT failed (no usable position_id). If THIS call created a
		// custom role, roll it back so it is not orphaned, and never return Success(0).
		if ( $position_id <= 0 ) {
			if ( $created_custom_role_id > 0 ) {
				$DB->Clear();
				$DB->orphan_rid = $created_custom_role_id;
				$DB->orphan_kid = $kingdom_id;
				$DB->Execute(
					"DELETE FROM " . DB_PREFIX . "role
					 WHERE role_id = :orphan_rid AND kingdom_id = :orphan_kid AND is_system = 0"
				);
			}
			return ProcessingError( 'The position could not be created. Please try again.' );
		}

		return Success( $position_id );
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
	public function EditPosition( $position_id, $fields, $acting_kingdom_id = 0 )
	{
		global $DB;
		$position_id = (int) $position_id;
		$acting_kingdom_id = (int) $acting_kingdom_id;
		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		$is_pinned = (int) $position['IsPinned'];
		$is_system = (int) $position['IsSystem'];
		$pos_kingdom_id = (int) $position['KingdomId'];
		$canonical_key  = $position['CanonicalKey'];
		$changed_by = isset( $fields['changed_by'] ) ? (int) $fields['changed_by'] : 0;
		$editor_id  = isset( $fields['editor_id'] ) ? (int) $fields['editor_id'] : $changed_by;

		// S1: kingdom-ownership guard. Allow shared system rows (kingdom_id=0) and
		// the acting kingdom's own rows; reject a different kingdom's custom row.
		if ( $pos_kingdom_id !== 0 && $acting_kingdom_id > 0 && $pos_kingdom_id !== $acting_kingdom_id ) {
			return NoAuthorization( 'Position does not belong to this kingdom.' );
		}

		// Reject pinned/system classification + RBAC edits server-side. Title alias
		// (via the alias table for system rows) and sort_order remain allowed.
		if ( $is_pinned || $is_system ) {
			if ( isset( $fields['classification'] ) && $fields['classification'] !== $position['Classification'] ) {
				return NoAuthorization( 'Pinned/system positions cannot be reclassified.' );
			}
			if ( ( isset( $fields['rbac_role_id'] ) && (int) $fields['rbac_role_id'] !== (int) $position['RbacRoleId'] )
				|| isset( $fields['permission_keys'] ) ) {
				return NoAuthorization( 'Pinned/system positions cannot have their RBAC binding changed.' );
			}
		}

		// title_alias routing differs by row type:
		//   - SYSTEM row (kingdom_id=0): per-kingdom alias lives in the alias table,
		//     keyed on ($acting_kingdom_id, canonical_key). NEVER mutate the shared row.
		//   - CUSTOM row (kingdom_id>0): alias lives on the row's own title_alias column.
		if ( array_key_exists( 'title_alias', $fields ) && $pos_kingdom_id === 0 ) {
			if ( $acting_kingdom_id <= 0 ) {
				return InvalidParameter( null, 'A valid kingdom is required to alias a system position.' );
			}
			$alias = trim( (string) $fields['title_alias'] );
			if ( $alias !== '' ) {
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
				$DB->Clear();
				$DB->ad_kid = $acting_kingdom_id;
				$DB->ad_key = $canonical_key;
				$DB->Execute(
					"DELETE FROM " . DB_PREFIX . "officer_position_alias
					 WHERE kingdom_id = :ad_kid AND canonical_key = :ad_key"
				);
			}
		}

		// Resolve + validate parent BEFORE binding UPDATE params: ValidateParent
		// runs its own DataSets ($DB->Clear()), which would wipe the UPDATE bindings
		// set below if interleaved (stale-PDO-binding guard).
		$apply_parent = false;
		$new_parent = null;
		if ( array_key_exists( 'parent_position_id', $fields ) ) {
			$apply_parent = true;
			$raw = $fields['parent_position_id'];
			$new_parent = ( $raw === null || $raw === '' || (int) $raw === 0 ) ? null : (int) $raw;
			if ( $new_parent !== null ) {
				$perr = $this->ValidateParent( $position_id, $pos_kingdom_id, $new_parent );
				if ( $perr !== true ) {
					return $perr;
				}
			}
		}

		// S2: resolve + validate an existing-role rebind BEFORE binding the UPDATE
		// params. The role-lookup DataSet runs its own $DB->Clear(), which would wipe
		// the UPDATE bindings if interleaved (stale-PDO-binding guard). Only applies
		// to non-pinned positions changing to a different role.
		$rebind_to_role_id = 0;
		if ( !$is_pinned && isset( $fields['rbac_role_id'] ) && (int) $fields['rbac_role_id'] > 0
			&& (int) $fields['rbac_role_id'] !== (int) $position['RbacRoleId'] ) {
			$candidate = (int) $fields['rbac_role_id'];
			$DB->Clear();
			$DB->vr_rid = $candidate;
			$vr = $DB->DataSet(
				"SELECT kingdom_id FROM " . DB_PREFIX . "role WHERE role_id = :vr_rid LIMIT 1"
			);
			if ( $vr === false || $vr->size() == 0 || !$vr->Next() ) {
				return InvalidParameter( null, 'The selected role does not exist.' );
			}
			// The chosen role must be a system role (kingdom_id=0) or owned by the
			// acting kingdom — never a foreign kingdom's custom role (which would let
			// this position inherit that kingdom's permissions).
			$role_kingdom_id = (int) $vr->kingdom_id;
			if ( $role_kingdom_id !== 0 && $acting_kingdom_id > 0 && $role_kingdom_id !== $acting_kingdom_id ) {
				return NoAuthorization( 'Role does not belong to this kingdom.' );
			}
			$rebind_to_role_id = $candidate;
		}

		// Rebinding to None (rbac_role_id explicitly 0): the holder gets no extra
		// access. Only for non-pinned positions whose current binding is non-zero.
		// No DB lookup needed (0 is always valid), so no stale-binding concern.
		$rebind_to_none = ( !$is_pinned && isset( $fields['rbac_role_id'] ) && (int) $fields['rbac_role_id'] === 0
			&& (int) $position['RbacRoleId'] !== 0 );

		// title / title_alias(custom only) / sort_order — written on the row itself.
		$sets = [];
		$DB->Clear();
		$DB->ep_pid = $position_id;
		if ( array_key_exists( 'title', $fields ) ) {
			$DB->ep_title = trim( (string) $fields['title'] );
			$sets[] = "title = :ep_title";
		}
		if ( array_key_exists( 'title_alias', $fields ) && $pos_kingdom_id > 0 ) {
			// Custom-row alias on its own column. '' clears; never null (yapo/SQL semantics).
			$DB->ep_alias = (string) $fields['title_alias'];
			$sets[] = "title_alias = :ep_alias";
		}
		if ( array_key_exists( 'sort_order', $fields ) ) {
			$DB->ep_so = (int) $fields['sort_order'];
			$sets[] = "sort_order = :ep_so";
		}

		// parent_position_id was validated above (before the UPDATE bindings). It is
		// written as a SQL literal (sanitized int / NULL), so no binding is needed.
		if ( $apply_parent ) {
			$sets[] = ( $new_parent === null ) ? "parent_position_id = NULL" : "parent_position_id = " . (int) $new_parent;
		}

		// hide_when_vacant: applies to NON-Crown only. Force 0 for crown/pinned/system.
		if ( array_key_exists( 'hide_when_vacant', $fields ) ) {
			// Use the incoming classification if it is being changed in this same edit,
			// otherwise the current stored classification.
			$eff_cls = ( !$is_pinned && array_key_exists( 'classification', $fields )
				&& ( $fields['classification'] === 'crown' || $fields['classification'] === 'supporting' ) )
				? $fields['classification'] : $position['Classification'];
			$hide = ( $eff_cls === 'crown' || $is_pinned || $is_system ) ? 0 : ( ( (int) $fields['hide_when_vacant'] ) ? 1 : 0 );
			$DB->ep_hwv = $hide;
			$sets[] = "hide_when_vacant = :ep_hwv";
		}

		$old_rbac_role_id = (int) $position['RbacRoleId'];
		$new_rbac_role_id = $old_rbac_role_id;

		if ( !$is_pinned ) {
			if ( array_key_exists( 'classification', $fields )
				&& ( $fields['classification'] === 'crown' || $fields['classification'] === 'supporting' ) ) {
				$DB->ep_cls = $fields['classification'];
				$sets[] = "classification = :ep_cls";
			}

			// Custom-permission upsert on the bound role.
			if ( isset( $fields['permission_keys'] ) && is_array( $fields['permission_keys'] )
				&& isset( Ork3::$Lib->rbacservice ) && $old_rbac_role_id > 0 ) {
				Ork3::$Lib->rbacservice->EditRole( $editor_id, $old_rbac_role_id, $fields['permission_keys'] );
			}

			// Rebinding to a different existing role (validated up-front into
			// $rebind_to_role_id before the UPDATE bindings were set).
			if ( $rebind_to_role_id > 0 ) {
				$new_rbac_role_id = $rebind_to_role_id;
				$DB->ep_rid = $new_rbac_role_id;
				$sets[] = "rbac_role_id = :ep_rid";
			} else if ( $rebind_to_none ) {
				// None: store 0 so the holder gets no extra access. Reconciliation
				// below revokes the old role from all live occupants.
				$new_rbac_role_id = 0;
				$sets[] = "rbac_role_id = 0";
			}
		}

		if ( count( $sets ) > 0 ) {
			$DB->Execute(
				"UPDATE " . DB_PREFIX . "officer_position SET " . implode( ', ', $sets ) . " WHERE position_id = :ep_pid"
			);
		}

		// §4.4 reconciliation: if the binding changed, revoke old role / grant new
		// for every live occupant of this position.
		if ( $new_rbac_role_id !== $old_rbac_role_id ) {
			$this->ReconcileRoleBinding( $position_id, $old_rbac_role_id, $new_rbac_role_id, $changed_by );
		}

		return Success( $position_id );
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
	private function ValidateParent( $position_id, $pos_kingdom_id, $proposed_parent_id )
	{
		global $DB;
		$position_id = (int) $position_id;
		$pos_kingdom_id = (int) $pos_kingdom_id;
		$proposed_parent_id = (int) $proposed_parent_id;

		if ( $position_id > 0 && $proposed_parent_id === $position_id ) {
			return InvalidParameter( null, 'A position cannot report to itself.' );
		}

		$DB->Clear();
		$DB->vp_pid = $proposed_parent_id;
		$r = $DB->DataSet(
			"SELECT position_id, kingdom_id FROM " . DB_PREFIX . "officer_position
			 WHERE position_id = :vp_pid LIMIT 1"
		);
		if ( $r === false || $r->size() == 0 || !$r->Next() ) {
			return InvalidParameter( null, 'The selected parent position does not exist.' );
		}
		$parent_kingdom_id = (int) $r->kingdom_id;
		if ( $parent_kingdom_id !== 0 && $parent_kingdom_id !== $pos_kingdom_id ) {
			return InvalidParameter( null, 'A position can only report to a system position or one in the same kingdom.' );
		}

		if ( $position_id > 0 && $this->WouldCreateCycle( $position_id, $proposed_parent_id, $pos_kingdom_id ) ) {
			return InvalidParameter( null, 'A position cannot report to its own descendant.' );
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
	private function WouldCreateCycle( $position_id, $proposed_parent_id, $pos_kingdom_id = 0 )
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
		if ( $r !== false && $r->size() > 0 ) {
			while ( $r->Next() ) {
				$next = $r->parent_position_id;
				$parent_of[ (int) $r->position_id ] = ( $next === null || $next === '' ) ? 0 : (int) $next;
			}
		}

		$cursor = (int) $proposed_parent_id;
		$visited = [];
		while ( $cursor > 0 ) {
			if ( $cursor === $position_id ) {
				return true;
			}
			if ( isset( $visited[ $cursor ] ) ) {
				// Pre-existing cycle in the data, or an id outside the loaded scope:
				// stop walking. Not a NEW cycle through $position_id.
				return false;
			}
			$visited[ $cursor ] = true;
			if ( !isset( $parent_of[ $cursor ] ) ) {
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
	private function ReconcileRoleBinding( $position_id, $old_rbac_role_id, $new_rbac_role_id, $changed_by )
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
		if ( $occ === false || $occ->size() == 0 ) {
			return;
		}
		$rows = [];
		while ( $occ->Next() ) {
			$rows[] = [
				'mundane_id' => (int) $occ->mundane_id,
				'kingdom_id' => (int) $occ->kingdom_id,
				'park_id'    => (int) $occ->park_id,
			];
		}

		$old_rbac_role_id = (int) $old_rbac_role_id;
		$new_rbac_role_id = (int) $new_rbac_role_id;

		// P2: one batched DELETE for the old role across all occupants, scoped by the
		// occupants' (mundane_id, kingdom_id, park_id) tuples. Integer-cast every id.
		if ( $old_rbac_role_id > 0 ) {
			$tuples = [];
			foreach ( $rows as $row ) {
				$tuples[] = "(" . (int) $row['mundane_id'] . ", " . (int) $row['kingdom_id'] . ", " . (int) $row['park_id'] . ")";
			}
			$DB->Clear();
			$DB->Execute(
				"DELETE FROM " . DB_PREFIX . "user_role
				 WHERE role_id = " . $old_rbac_role_id . "
				   AND (mundane_id, kingdom_id, park_id) IN (" . implode( ', ', $tuples ) . ")"
			);
		}

		// P2: one batched multi-row INSERT IGNORE for the new role grant.
		if ( $new_rbac_role_id > 0 ) {
			$granted_by_sql = ( $changed_by > 0 ) ? $changed_by : 'NULL';
			$values = [];
			foreach ( $rows as $row ) {
				$values[] = "(" . (int) $row['mundane_id'] . ", " . $new_rbac_role_id . ", " . (int) $row['kingdom_id'] . ", " . (int) $row['park_id'] . ", 0, 0, " . $granted_by_sql . ", NULL)";
			}
			$DB->Clear();
			$DB->Execute(
				"INSERT IGNORE INTO " . DB_PREFIX . "user_role
				 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
				 VALUES " . implode( ', ', $values )
			);
		}

		// Per-occupant cache invalidation stays a loop (Memcache, not DB).
		if ( isset( Ork3::$Lib->rbacservice ) ) {
			foreach ( $rows as $row ) {
				Ork3::$Lib->rbacservice->InvalidateUserCache( (int) $row['mundane_id'] );
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
	public function RetirePosition( $position_id, $changed_by, $acting_kingdom_id = 0 )
	{
		global $DB;
		$position_id = (int) $position_id;
		$changed_by = (int) $changed_by;
		$acting_kingdom_id = (int) $acting_kingdom_id;

		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		// S1: kingdom-ownership guard (system kingdom_id=0 rows are shared; the
		// is_pinned/is_system check below blocks retiring shared system rows anyway).
		if ( (int) $position['KingdomId'] !== 0 && $acting_kingdom_id > 0 && (int) $position['KingdomId'] !== $acting_kingdom_id ) {
			return NoAuthorization( 'Position does not belong to this kingdom.' );
		}
		if ( (int) $position['IsPinned'] || (int) $position['IsSystem'] ) {
			return NoAuthorization( 'System/pinned positions cannot be retired.' );
		}

		// Collect live occupants for the warning/audit.
		$DB->Clear();
		$DB->rt_pid = $position_id;
		$occ = $DB->DataSet(
			"SELECT mundane_id, kingdom_id, park_id FROM " . DB_PREFIX . "officer
			 WHERE position_id = :rt_pid AND mundane_id > 0"
		);
		$vacated = [];
		if ( $occ !== false && $occ->size() > 0 ) {
			while ( $occ->Next() ) {
				$vacated[] = [
					'MundaneId' => (int) $occ->mundane_id,
					'KingdomId' => (int) $occ->kingdom_id,
					'ParkId'    => (int) $occ->park_id,
				];
			}
		}

		foreach ( $vacated as $v ) {
			$this->VacateOfficerByPosition( $v['KingdomId'], $v['ParkId'], $position_id, $changed_by );
		}

		$DB->Clear();
		$DB->rtu_pid = $position_id;
		$DB->Execute(
			"UPDATE " . DB_PREFIX . "officer_position SET retired_at = NOW() WHERE position_id = :rtu_pid"
		);

		return Success( $vacated );
	}

	/**
	 * Reinstate a retired position. Classification is the unchanged column value
	 * (retire never touched it), so no snapshot restore is needed.
	 *
	 * @param int $position_id
	 * @return array
	 */
	public function ReinstatePosition( $position_id, $acting_kingdom_id = 0 )
	{
		global $DB;
		$position_id = (int) $position_id;
		$acting_kingdom_id = (int) $acting_kingdom_id;
		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		// S1: kingdom-ownership guard (system kingdom_id=0 rows are shared).
		if ( (int) $position['KingdomId'] !== 0 && $acting_kingdom_id > 0 && (int) $position['KingdomId'] !== $acting_kingdom_id ) {
			return NoAuthorization( 'Position does not belong to this kingdom.' );
		}

		$DB->Clear();
		$DB->ri_pid = $position_id;
		$DB->Execute(
			"UPDATE " . DB_PREFIX . "officer_position SET retired_at = NULL WHERE position_id = :ri_pid"
		);
		return Success( $position_id );
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
	public function GetOfficersForDisplay( $kingdom_id, $park_id, $include_retired = false )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$park_id = (int) $park_id;

		$sql = "SELECT o.officer_id, o.mundane_id, o.position_id, o.role,
				p.canonical_key, p.classification, p.sort_order,
				p.parent_position_id, p.hide_when_vacant,
				IF(p.kingdom_id = 0,
				   IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
				   IF(p.title_alias != '', p.title_alias, p.title)) AS DisplayTitle,
				m.persona, m.given_name, m.surname, m.username
			FROM " . DB_PREFIX . "officer o
			JOIN " . DB_PREFIX . "officer_position p ON p.position_id = o.position_id
			LEFT JOIN " . DB_PREFIX . "officer_position_alias a
			  ON a.kingdom_id = :kid AND a.canonical_key = p.canonical_key
			LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = o.mundane_id
			WHERE o.kingdom_id = :kid2 AND o.park_id = :pid";
		if ( !$include_retired ) {
			$sql .= " AND p.retired_at IS NULL";
		}
		$sql .= " ORDER BY p.classification, p.sort_order";

		$DB->Clear();
		$DB->kid = $kingdom_id;
		$DB->kid2 = $kingdom_id;
		$DB->pid = $park_id;
		$r = $DB->DataSet( $sql );

		$out = [ 'crown' => [], 'supporting' => [] ];
		if ( $r !== false && $r->size() > 0 ) {
			while ( $r->Next() ) {
				$group = ( $r->classification === 'supporting' ) ? 'supporting' : 'crown';
				$out[ $group ][] = [
					'OfficerId'    => (int) $r->officer_id,
					'PositionId'   => (int) $r->position_id,
					'CanonicalKey' => $r->canonical_key,
					'DisplayTitle' => $r->DisplayTitle,
					'ParentPositionId' => ( $r->parent_position_id === null || $r->parent_position_id === '' ) ? null : (int) $r->parent_position_id,
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
	 * Set an officer occupant by position, enforcing §3.4 occupancy rules.
	 * Crown: GET_LOCK on the incoming person, crown-per-person global check,
	 * single-occupant-per-scope (vacate existing), then write. Supporting:
	 * unrestricted, multi-occupant.
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
	public function SetOfficerByPosition( $kingdom_id, $park_id, $position_id, $mundane_id, $term_start, $term_end, $note, $changed_by )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$park_id = (int) $park_id;
		$position_id = (int) $position_id;
		$mundane_id = (int) $mundane_id;
		$changed_by = (int) $changed_by;

		$position = $this->GetPosition( $position_id, $kingdom_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		// S1: kingdom-ownership guard (system kingdom_id=0 rows are shared; each
		// kingdom legitimately fills its own occupant). Reject a foreign kingdom row.
		if ( (int) $position['KingdomId'] !== 0 && $kingdom_id > 0 && (int) $position['KingdomId'] !== $kingdom_id ) {
			return NoAuthorization( 'Position does not belong to this kingdom.' );
		}
		if ( $position['RetiredAt'] !== null ) {
			return InvalidParameter( null, 'Cannot assign an occupant to a retired position.' );
		}
		if ( $mundane_id <= 0 ) {
			return InvalidParameter( null, 'A valid member is required.' );
		}
		$canonical_key = $position['CanonicalKey'];
		$classification = $position['Classification'];

		if ( $classification !== 'crown' ) {
			// Supporting: no lock, no global check, multiple rows allowed. Each
			// assignment is a fresh ork_officer row (set_officer is single-slot and
			// cannot represent multi-occupant supporting positions).
			$this->InsertOfficerRow( $kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $changed_by, $term_start, $term_end, $position['DisplayTitle'] );
			return Success();
		}

		// Crown: serialize per person across all scopes with an advisory lock.
		$lock_name = 'crown_assign_' . $mundane_id;
		$locked = false;
		try {
			$DB->Clear();
			$DB->lk = $lock_name;
			$DB->lt = self::CROWN_LOCK_TIMEOUT;
			$lr = $DB->DataSet( "SELECT GET_LOCK(:lk, :lt) AS got" );
			if ( $lr === false || $lr->size() == 0 || !$lr->Next() || (int) $lr->got !== 1 ) {
				return ProcessingError( 'Could not acquire the crown assignment lock; please retry.' );
			}
			$locked = true;

			// Crown-per-person global check across kingdom + park scopes.
			$DB->Clear();
			$DB->cp_mid = $mundane_id;
			$DB->cp_k = $kingdom_id;
			$DB->cp_p = $park_id;
			$DB->cp_pos = $position_id;
			$conflict = $DB->DataSet(
				"SELECT o.kingdom_id, o.park_id, o.position_id,
					IF(p.kingdom_id = 0,
					   IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
					   IF(p.title_alias != '', p.title_alias, p.title)) AS DisplayTitle,
					k.name AS kingdom_name, pk.name AS park_name
				 FROM " . DB_PREFIX . "officer o
				 JOIN " . DB_PREFIX . "officer_position p ON p.position_id = o.position_id
				 LEFT JOIN " . DB_PREFIX . "officer_position_alias a
				   ON a.kingdom_id = o.kingdom_id AND a.canonical_key = p.canonical_key
				 LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = o.kingdom_id
				 LEFT JOIN " . DB_PREFIX . "park pk ON pk.park_id = o.park_id
				 WHERE p.classification = 'crown' AND p.retired_at IS NULL
				   AND o.mundane_id = :cp_mid
				   AND NOT (o.kingdom_id = :cp_k AND o.park_id = :cp_p AND o.position_id = :cp_pos)
				 LIMIT 1"
			);
			if ( $conflict !== false && $conflict->size() > 0 && $conflict->Next() ) {
				$scope = ( (int) $conflict->park_id > 0 )
					? ( 'park ' . $conflict->park_name )
					: ( 'kingdom ' . $conflict->kingdom_name );
				return InvalidParameter( null,
					'This person already holds a Crown office: ' . $conflict->DisplayTitle . ' in ' . $scope
					. '. A person may hold only one Crown office.' );
			}

			// Single-occupant-per-scope: set_officer find() keyed on position_id
			// replaces the occupant of the single crown row in place. For custom
			// crown positions no seeded slot exists yet, so ensure one is present
			// (vacant placeholder) before delegating, so find() succeeds.
			$this->EnsureCrownSlot( $kingdom_id, $park_id, $position_id, $canonical_key );
			$c = new Common();
			$c->set_officer( $kingdom_id, $park_id, $mundane_id, $canonical_key, 0, $changed_by, $position_id, $position['DisplayTitle'] );

			return Success();
		} finally {
			if ( $locked ) {
				$DB->Clear();
				$DB->rk = $lock_name;
				$DB->Execute( "SELECT RELEASE_LOCK(:rk)" );
			}
		}
	}

	/**
	 * Vacate the occupant of a position+scope: closes the term and revokes the
	 * synced ork_user_role via Common::set_officer(mundane_id=0). Crown leaves a
	 * vacant placeholder row (mundane_id=0); supporting deletes the row.
	 *
	 * @param int $kingdom_id
	 * @param int $park_id
	 * @param int $position_id
	 * @param int $changed_by
	 * @return array
	 */
	public function VacateOfficerByPosition( $kingdom_id, $park_id, $position_id, $changed_by )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$park_id = (int) $park_id;
		$position_id = (int) $position_id;
		$changed_by = (int) $changed_by;

		$position = $this->GetPosition( $position_id, $kingdom_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		// S1: kingdom-ownership guard (system kingdom_id=0 rows are shared).
		if ( (int) $position['KingdomId'] !== 0 && $kingdom_id > 0 && (int) $position['KingdomId'] !== $kingdom_id ) {
			return NoAuthorization( 'Position does not belong to this kingdom.' );
		}
		$canonical_key = $position['CanonicalKey'];
		$classification = $position['Classification'];

		if ( $classification === 'supporting' ) {
			// Supporting positions can have multiple occupants; close history +
			// revoke each occupant's role, then delete the rows (no placeholder).
			$DB->Clear();
			$DB->vs_kid = $kingdom_id;
			$DB->vs_pid = $park_id;
			$DB->vs_pos = $position_id;
			$occ = $DB->DataSet(
				"SELECT mundane_id FROM " . DB_PREFIX . "officer
				 WHERE kingdom_id = :vs_kid AND park_id = :vs_pid AND position_id = :vs_pos AND mundane_id > 0"
			);
			$occupants = [];
			if ( $occ !== false && $occ->size() > 0 ) {
				while ( $occ->Next() ) { $occupants[] = (int) $occ->mundane_id; }
			}
			foreach ( $occupants as $mid ) {
				if ( isset( Ork3::$Lib->rbacservice ) ) {
					try {
						Ork3::$Lib->rbacservice->SyncOfficerRoleByPositionId( $mid, 0, $position_id, $kingdom_id, $park_id, $changed_by );
					} catch ( Exception $e ) {
						logtrace( 'RBAC vacate supporting failed', $e->getMessage() );
					}
				}
			}
			$DB->Clear();
			$DB->vd_kid = $kingdom_id;
			$DB->vd_pid = $park_id;
			$DB->vd_pos = $position_id;
			$DB->Execute(
				"DELETE FROM " . DB_PREFIX . "officer
				 WHERE kingdom_id = :vd_kid AND park_id = :vd_pid AND position_id = :vd_pos"
			);
			return Success();
		}

		// Crown: close the term + revoke role (mundane_id = 0 means vacate),
		// leaving a vacant placeholder row.
		$c = new Common();
		$c->set_officer( $kingdom_id, $park_id, 0, $canonical_key, 0, $changed_by, $position_id );
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
	private function EnsureCrownSlot( $kingdom_id, $park_id, $position_id, $canonical_key )
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
		if ( $ex !== false && $ex->size() > 0 ) {
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
	 * Insert a fresh ork_officer row for a multi-occupant (supporting) position,
	 * record history, and sync the RBAC role. Skips a duplicate active occupant.
	 */
	private function InsertOfficerRow( $kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $changed_by, $term_start = '', $term_end = '', $display_label = '' )
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
		if ( $dup !== false && $dup->size() > 0 ) {
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
		$start = ( trim( (string) $term_start ) !== '' ) ? (string) $term_start : date( 'Y-m-d' );
		$has_end = ( trim( (string) $term_end ) !== '' );
		$label = ( trim( (string) $display_label ) !== '' ) ? (string) $display_label : $canonical_key;
		$DB->Clear();
		$DB->ih_kid = $kingdom_id;
		$DB->ih_pid = $park_id;
		$DB->ih_mid = $mundane_id;
		$DB->ih_role = $canonical_key;
		$DB->ih_pos = $position_id;
		$DB->ih_label = $label;
		$DB->ih_start = $start;
		$DB->ih_cb = ( $changed_by > 0 ? $changed_by : null );
		// C2: end_date is written as a SQL literal so an open term truly stores NULL.
		// Binding a PHP null can be skipped by the DB layer (yapo/null-skip rule),
		// leaving a stale value; mirror the parent_position_id = NULL literal pattern.
		if ( $has_end ) {
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
		if ( isset( Ork3::$Lib->rbacservice ) ) {
			try {
				Ork3::$Lib->rbacservice->SyncOfficerRoleByPositionId( 0, $mundane_id, $position_id, $kingdom_id, $park_id, $changed_by );
			} catch ( Exception $e ) {
				logtrace( 'RBAC supporting grant failed', $e->getMessage() );
			}
		}
	}
}

?>
