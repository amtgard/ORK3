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
	public function GetPosition( $position_id )
	{
		global $DB;
		$position_id = (int) $position_id;
		if ( $position_id <= 0 ) {
			return false;
		}

		$DB->Clear();
		$DB->pid = $position_id;
		$r = $DB->DataSet(
			"SELECT p.*,
				IF(p.kingdom_id = 0,
				   IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
				   IF(p.title_alias != '', p.title_alias, p.title)) AS DisplayTitle
			FROM " . DB_PREFIX . "officer_position p
			LEFT JOIN " . DB_PREFIX . "officer_position_alias a
			  ON a.kingdom_id = p.kingdom_id AND a.canonical_key = p.canonical_key
			WHERE p.position_id = :pid LIMIT 1"
		);
		if ( $r === false || $r->size() == 0 || !$r->Next() ) {
			return false;
		}
		$row = $this->RowToArray( $r );

		// Permission summary for the bound role.
		$row['Permissions'] = [];
		$rbac_role_id = (int) $row['rbac_role_id'];
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
	 * Normalize a DataSet registry row into an associative array, exposing
	 * the canonical key and resolved DisplayTitle in the contracted shape.
	 */
	private function RowToArray( $r )
	{
		return [
			'PositionId'    => (int) $r->position_id,
			'position_id'   => (int) $r->position_id,
			'KingdomId'     => (int) $r->kingdom_id,
			'kingdom_id'    => (int) $r->kingdom_id,
			'CanonicalKey'  => $r->canonical_key,
			'canonical_key' => $r->canonical_key,
			'Title'         => $r->title,
			'title'         => $r->title,
			'TitleAlias'    => $r->title_alias,
			'title_alias'   => $r->title_alias,
			'DisplayTitle'  => $r->DisplayTitle,
			'Classification'=> $r->classification,
			'classification'=> $r->classification,
			'IsPinned'      => (int) $r->is_pinned,
			'is_pinned'     => (int) $r->is_pinned,
			'IsSystem'      => (int) $r->is_system,
			'is_system'     => (int) $r->is_system,
			'RbacRoleId'    => (int) $r->rbac_role_id,
			'rbac_role_id'  => (int) $r->rbac_role_id,
			'HasAuthRole'   => (int) $r->has_auth_role,
			'has_auth_role' => (int) $r->has_auth_role,
			'SortOrder'     => (int) $r->sort_order,
			'sort_order'    => (int) $r->sort_order,
			'RetiredAt'     => $r->retired_at,
			'retired_at'    => $r->retired_at,
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
	public function CreatePosition( $kingdom_id, $canonical_key, $title, $classification, $rbac_choice, $creator_id = 0 )
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
		} else if ( is_array( $rbac_choice ) && isset( $rbac_choice['mode'] ) && $rbac_choice['mode'] === 'existing' ) {
			$rbac_role_id = isset( $rbac_choice['role_id'] ) ? (int) $rbac_choice['role_id'] : 0;
		}
		if ( $rbac_role_id <= 0 ) {
			return InvalidParameter( null, 'A valid RBAC role binding is required.' );
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
		$DB->Execute(
			"INSERT INTO " . DB_PREFIX . "officer_position
			 (kingdom_id, canonical_key, title, title_alias, classification, is_pinned, is_system, rbac_role_id, has_auth_role, sort_order, retired_at, created_by, created_at)
			 VALUES (:c_kid, :c_key, :c_title, '', :c_cls, 0, 0, :c_rid, 0, :c_so, NULL, :c_cb, NOW())"
		);

		$position_id = $this->ResolvePositionId( $kingdom_id, $slug );
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
	public function EditPosition( $position_id, $fields )
	{
		global $DB;
		$position_id = (int) $position_id;
		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		$is_pinned = (int) $position['is_pinned'];
		$changed_by = isset( $fields['changed_by'] ) ? (int) $fields['changed_by'] : 0;
		$editor_id  = isset( $fields['editor_id'] ) ? (int) $fields['editor_id'] : $changed_by;

		// Reject pinned classification/RBAC edits server-side.
		if ( $is_pinned ) {
			if ( isset( $fields['classification'] ) && $fields['classification'] !== $position['classification'] ) {
				return NoAuthorization( 'Pinned positions cannot be reclassified.' );
			}
			if ( ( isset( $fields['rbac_role_id'] ) && (int) $fields['rbac_role_id'] !== (int) $position['rbac_role_id'] )
				|| isset( $fields['permission_keys'] ) ) {
				return NoAuthorization( 'Pinned positions cannot have their RBAC binding changed.' );
			}
		}

		// title / title_alias / sort_order — always.
		$sets = [];
		$DB->Clear();
		$DB->ep_pid = $position_id;
		if ( array_key_exists( 'title', $fields ) ) {
			$DB->ep_title = trim( (string) $fields['title'] );
			$sets[] = "title = :ep_title";
		}
		if ( array_key_exists( 'title_alias', $fields ) ) {
			// '' clears the alias; never null (yapo/SQL semantics).
			$DB->ep_alias = (string) $fields['title_alias'];
			$sets[] = "title_alias = :ep_alias";
		}
		if ( array_key_exists( 'sort_order', $fields ) ) {
			$DB->ep_so = (int) $fields['sort_order'];
			$sets[] = "sort_order = :ep_so";
		}

		$old_rbac_role_id = (int) $position['rbac_role_id'];
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

			// Rebinding to a different existing role.
			if ( isset( $fields['rbac_role_id'] ) && (int) $fields['rbac_role_id'] > 0
				&& (int) $fields['rbac_role_id'] !== $old_rbac_role_id ) {
				$new_rbac_role_id = (int) $fields['rbac_role_id'];
				$DB->ep_rid = $new_rbac_role_id;
				$sets[] = "rbac_role_id = :ep_rid";
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
		foreach ( $rows as $row ) {
			if ( $old_rbac_role_id > 0 ) {
				$DB->Clear();
				$DB->Execute(
					"DELETE FROM " . DB_PREFIX . "user_role
					 WHERE mundane_id = " . $row['mundane_id'] . "
					   AND role_id = " . $old_rbac_role_id . "
					   AND kingdom_id = " . $row['kingdom_id'] . "
					   AND park_id = " . $row['park_id']
				);
			}
			if ( $new_rbac_role_id > 0 ) {
				$granted_by_sql = ( $changed_by > 0 ) ? $changed_by : 'NULL';
				$DB->Clear();
				$DB->Execute(
					"INSERT IGNORE INTO " . DB_PREFIX . "user_role
					 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
					 VALUES (" . $row['mundane_id'] . ", " . $new_rbac_role_id . ", " . $row['kingdom_id'] . ", " . $row['park_id'] . ", 0, 0, " . $granted_by_sql . ", NULL)"
				);
			}
			if ( isset( Ork3::$Lib->rbacservice ) ) {
				Ork3::$Lib->rbacservice->InvalidateUserCache( $row['mundane_id'] );
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
	public function RetirePosition( $position_id, $changed_by )
	{
		global $DB;
		$position_id = (int) $position_id;
		$changed_by = (int) $changed_by;

		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		if ( (int) $position['is_pinned'] || (int) $position['is_system'] ) {
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
	public function ReinstatePosition( $position_id )
	{
		global $DB;
		$position_id = (int) $position_id;
		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
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

		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		if ( $position['retired_at'] !== null ) {
			return InvalidParameter( null, 'Cannot assign an occupant to a retired position.' );
		}
		if ( $mundane_id <= 0 ) {
			return InvalidParameter( null, 'A valid member is required.' );
		}
		$canonical_key = $position['canonical_key'];
		$classification = $position['classification'];

		if ( $classification !== 'crown' ) {
			// Supporting: no lock, no global check, multiple rows allowed.
			$c = new Common();
			$c->set_officer( $kingdom_id, $park_id, $mundane_id, $canonical_key, 0, $changed_by, $position_id );
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

			// Single-occupant-per-scope: vacate any existing live occupant of this
			// exact position+scope (set_officer find() keyed on position_id replaces
			// the occupant in place, but if a DIFFERENT person currently holds it we
			// let set_officer overwrite the single crown row).
			$c = new Common();
			$c->set_officer( $kingdom_id, $park_id, $mundane_id, $canonical_key, 0, $changed_by, $position_id );

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

		$position = $this->GetPosition( $position_id );
		if ( $position === false ) {
			return InvalidParameter( null, 'Position not found.' );
		}
		$canonical_key = $position['canonical_key'];
		$classification = $position['classification'];

		// Close the term + revoke role (mundane_id = 0 means vacate).
		$c = new Common();
		$c->set_officer( $kingdom_id, $park_id, 0, $canonical_key, 0, $changed_by, $position_id );

		if ( $classification === 'supporting' ) {
			// Supporting positions delete the now-vacant row rather than keeping
			// a placeholder.
			$DB->Clear();
			$DB->v_kid = $kingdom_id;
			$DB->v_pid = $park_id;
			$DB->v_pos = $position_id;
			$DB->Execute(
				"DELETE FROM " . DB_PREFIX . "officer
				 WHERE kingdom_id = :v_kid AND park_id = :v_pid AND position_id = :v_pos AND mundane_id = 0"
			);
		}

		return Success();
	}
}

?>
