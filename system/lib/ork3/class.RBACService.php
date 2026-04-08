<?php

/***
 * RBACService
 *
 * Core RBAC engine for ORK3. Provides permission checking with scope cascade,
 * role management (CRUD), grant/revoke with escalation prevention, audit logging,
 * and GhettoCache integration with generation-counter invalidation.
 *
 * Auto-loaded by startup.php as Ork3::$Lib->rbacservice
 *
 * Key methods:
 *   HasPermission()  — Check if a user has a specific permission at a scope
 *   GrantRole()      — Assign a role to a user at a scope (with escalation guard)
 *   RevokeRole()     — Remove a role assignment from a user
 *   CreateRole()     — Create a custom kingdom-level role
 *   EditRole()       — Edit a custom role's permissions
 *   DeleteRole()     — Delete a custom role
 *   SyncOfficerRole() — Dual-write: create/update ork_user_role for an officer change
 ***/

class RBACService extends Ork3
{

	private $cache;
	private $cache_ttl = 120; // seconds

	public function __construct()
	{
		parent::__construct();
		$this->cache = new Ghettocache();
	}

	// ================================================================
	// PERMISSION CHECKING
	// ================================================================

	/**
	 * Check if a user has a specific permission at a given scope.
	 *
	 * Logic flow:
	 *   1. Admin bypass (check ork_authorization for role='admin')
	 *   2. Ban check (ork_mundane.penalty_box == 1 => false)
	 *   3. Cache check (GhettoCache with generation counter)
	 *   4. Direct query (ork_user_role JOIN ork_role_permission JOIN ork_permission)
	 *   5. Scope cascade (park -> kingdom, event -> park -> kingdom, unit -> kingdom)
	 *   6. Cache result
	 *
	 * @param int    $mundane_id     User ID
	 * @param string $permission_key Permission key, e.g. 'kingdom.award.create'
	 * @param string $scope_type     One of: 'kingdom', 'park', 'event', 'unit'
	 * @param int    $scope_id       The ID of the scoped entity
	 * @return bool
	 */
	public function HasPermission( $mundane_id, $permission_key, $scope_type, $scope_id )
	{
		global $DB;

		$mundane_id = (int) $mundane_id;
		$scope_id = (int) $scope_id;

		if ( $mundane_id <= 0 || $scope_id <= 0 ) {
			return false;
		}

		// Validate permission key exists in registry
		if ( !PermissionRegistry::Exists( $permission_key ) ) {
			logtrace( 'RBACService::HasPermission', 'Unknown permission key: ' . $permission_key );
			return false;
		}

		// 1. Admin bypass — check ork_authorization for role='admin'
		if ( $this->IsAdmin( $mundane_id ) ) {
			return true;
		}

		// 2. Ban check
		if ( $this->IsBanned( $mundane_id ) ) {
			return false;
		}

		// 3. Cache check
		$gen = $this->GetGenerationCounter( $mundane_id );
		$cache_key = $this->BuildCacheKey( $gen, $mundane_id, $permission_key, $scope_type, $scope_id );
		$cached = $this->cache->get( 'rbac', $cache_key, $this->cache_ttl );
		if ( $cached !== false ) {
			return (bool) $cached;
		}

		// 4. Direct query at the requested scope
		$has_perm = $this->CheckPermissionDirect( $mundane_id, $permission_key, $scope_type, $scope_id );

		// 5. Scope cascade if no direct match
		if ( !$has_perm ) {
			$has_perm = $this->CheckPermissionCascade( $mundane_id, $permission_key, $scope_type, $scope_id );
		}

		// 6. Cache result (store 1 or 0 since GhettoCache returns false for cache miss)
		$this->cache->cache( 'rbac', $cache_key, $has_perm ? 1 : 0 );

		return $has_perm;
	}

	/**
	 * Check for a permission directly at the given scope (no cascade).
	 */
	private function CheckPermissionDirect( $mundane_id, $permission_key, $scope_type, $scope_id )
	{
		global $DB;

		$scope_column = $this->ScopeTypeToColumn( $scope_type );
		if ( $scope_column === null ) {
			return false;
		}

		$DB->Clear();
		$DB->perm_key = $permission_key;
		$sql = "SELECT 1
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role_permission rp ON rp.role_id = ur.role_id
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE ur.mundane_id = " . (int) $mundane_id . "
			  AND ur." . $scope_column . " = " . (int) $scope_id . "
			  AND p.`key` = :perm_key
			  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
			LIMIT 1";

		$result = $DB->DataSet( $sql );
		return ( $result !== false && $result->size() > 0 );
	}

	/**
	 * Cascade permission check up the scope hierarchy:
	 *   park -> kingdom
	 *   event -> park -> kingdom
	 *   unit -> kingdom
	 */
	private function CheckPermissionCascade( $mundane_id, $permission_key, $scope_type, $scope_id )
	{
		global $DB;

		switch ( $scope_type ) {
			case 'park':
				// Park scope -> check kingdom scope
				$park = new yapo( $this->db, DB_PREFIX . 'park' );
				$park->clear();
				$park->park_id = $scope_id;
				if ( $park->find() && valid_id( $park->kingdom_id ) ) {
					return $this->CheckPermissionDirect( $mundane_id, $permission_key, 'kingdom', $park->kingdom_id );
				}
				break;

			case 'event':
				// Event scope -> check park scope, then kingdom scope
				$event = new yapo( $this->db, DB_PREFIX . 'event' );
				$event->clear();
				$event->event_id = $scope_id;
				if ( $event->find() ) {
					if ( valid_id( $event->park_id ) ) {
						if ( $this->CheckPermissionDirect( $mundane_id, $permission_key, 'park', $event->park_id ) ) {
							return true;
						}
					}
					if ( valid_id( $event->kingdom_id ) ) {
						return $this->CheckPermissionDirect( $mundane_id, $permission_key, 'kingdom', $event->kingdom_id );
					}
					logtrace( 'RBACService::CheckPermissionCascade', 'Event ' . $scope_id . ' has no valid park_id or kingdom_id for cascade' );
				}
				break;

			case 'unit':
				// Unit scope -> check kingdom scope via unit's park's kingdom
				$DB->Clear();
				$sql = "SELECT p.kingdom_id FROM " . DB_PREFIX . "unit u JOIN " . DB_PREFIX . "park p ON p.park_id = u.park_id WHERE u.unit_id = " . (int) $scope_id;
				$result = $DB->DataSet( $sql );
				if ( $result !== false && $result->size() > 0 && $result->Next() ) {
					$kid = $result->kingdom_id;
					if ( valid_id( $kid ) ) {
						return $this->CheckPermissionDirect( $mundane_id, $permission_key, 'kingdom', $kid );
					}
				}
				break;
		}

		return false;
	}

	/**
	 * Map scope type string to the ork_user_role column name.
	 */
	private function ScopeTypeToColumn( $scope_type )
	{
		switch ( $scope_type ) {
			case 'kingdom': return 'kingdom_id';
			case 'park':    return 'park_id';
			case 'event':   return 'event_id';
			case 'unit':    return 'unit_id';
			default:        return null;
		}
	}

	// ================================================================
	// ADMIN / BAN HELPERS
	// ================================================================

	/**
	 * Check if a user is an admin (has role='admin' in ork_authorization).
	 */
	private function IsAdmin( $mundane_id )
	{
		global $DB;
		$auth = new yapo( $DB, DB_PREFIX . 'authorization' );
		$auth->clear();
		$auth->mundane_id = (int) $mundane_id;
		$auth->role = AUTH_ADMIN;
		return ( $auth->find() && $auth->size() > 0 );
	}

	/**
	 * Check if a user is banned (penalty_box == 1).
	 */
	private function IsBanned( $mundane_id )
	{
		global $DB;
		$mundane = new yapo( $DB, DB_PREFIX . 'mundane' );
		$mundane->clear();
		$mundane->mundane_id = (int) $mundane_id;
		if ( $mundane->find() ) {
			return ( $mundane->penalty_box == 1 );
		}
		return true; // If user not found, treat as banned
	}

	// ================================================================
	// CACHE MANAGEMENT (Generation Counter Pattern)
	// ================================================================

	/**
	 * Get the RBAC generation counter for a user.
	 * Used as part of cache keys so incrementing invalidates all cached permissions.
	 */
	private function GetGenerationCounter( $mundane_id )
	{
		$gen = $this->cache->get( 'rbac_gen', (string) $mundane_id, 3600 );
		if ( $gen === false ) {
			$gen = 1;
			$this->cache->cache( 'rbac_gen', (string) $mundane_id, $gen );
		}
		return (int) $gen;
	}

	/**
	 * Increment the generation counter for a user, invalidating all cached permissions.
	 */
	private function IncrementGenerationCounter( $mundane_id )
	{
		$gen = $this->GetGenerationCounter( $mundane_id );
		$gen++;
		$this->cache->bust( 'rbac_gen', (string) $mundane_id );
		$this->cache->cache( 'rbac_gen', (string) $mundane_id, $gen );
	}

	/**
	 * Build a cache key for a permission check.
	 */
	private function BuildCacheKey( $gen, $mundane_id, $permission_key, $scope_type, $scope_id )
	{
		return $gen . '.' . $mundane_id . '.' . $permission_key . '.' . $scope_type . '.' . $scope_id;
	}

	// ================================================================
	// ROLE ASSIGNMENT (Grant / Revoke)
	// ================================================================

	/**
	 * Grant a role to a user at a specific scope.
	 *
	 * Includes escalation prevention: the granter must hold ALL permissions
	 * that are in the target role, at >= the target scope.
	 *
	 * @param int    $granter_id    Who is granting
	 * @param int    $target_id     Who receives the role
	 * @param int    $role_id       The role to grant
	 * @param string $scope_type    Scope type
	 * @param int    $scope_id      Scope entity ID
	 * @param string|null $expires_at  Optional expiration (MySQL datetime), or null for permanent
	 * @return array  Standard ORK response array
	 */
	public function GrantRole( $granter_id, $target_id, $role_id, $scope_type, $scope_id, $expires_at = null )
	{
		global $DB;

		$granter_id = (int) $granter_id;
		$target_id = (int) $target_id;
		$role_id = (int) $role_id;
		$scope_id = (int) $scope_id;

		if ( !valid_id( $granter_id ) || !valid_id( $target_id ) || !valid_id( $role_id ) ) {
			return InvalidParameter( null, 'Invalid IDs provided.' );
		}

		// Load the role
		$role = new yapo( $DB, DB_PREFIX . 'role' );
		$role->clear();
		$role->role_id = $role_id;
		if ( !$role->find() ) {
			return InvalidParameter( null, 'Role not found.' );
		}

		// Self-appointment guard for officer roles
		if ( $role->is_system && $granter_id == $target_id ) {
			$officer_roles = [ 'monarch', 'regent', 'prime_minister', 'champion', 'gmr' ];
			if ( in_array( $role->name, $officer_roles ) ) {
				return NoAuthorization( 'Cannot assign officer roles to yourself.' );
			}
		}

		// Escalation prevention: granter must hold ALL permissions in the target role
		if ( !$this->IsAdmin( $granter_id ) ) {
			$missing = $this->CheckEscalation( $granter_id, $role_id, $scope_type, $scope_id );
			if ( count( $missing ) > 0 ) {
				return NoAuthorization( 'Escalation prevented: you lack permissions: ' . implode( ', ', $missing ) );
			}
		}

		// Determine scope columns
		$scope_column = $this->ScopeTypeToColumn( $scope_type );
		if ( $scope_column === null ) {
			return InvalidParameter( null, 'Invalid scope type.' );
		}

		// Insert user role assignment
		$kingdom_id = ( $scope_type === 'kingdom' ) ? $scope_id : 0;
		$park_id = ( $scope_type === 'park' ) ? $scope_id : 0;
		$event_id = ( $scope_type === 'event' ) ? $scope_id : 0;
		$unit_id = ( $scope_type === 'unit' ) ? $scope_id : 0;

		// Validate expires_at is not in the past
		if ( $expires_at !== null ) {
			$exp_time = strtotime( $expires_at );
			if ( $exp_time === false || $exp_time < time() ) {
				return InvalidParameter( null, 'Expiration date must be in the future.' );
			}
		}

		$DB->Clear();
		if ( $expires_at !== null ) {
			$DB->expires_at = $expires_at;
			$expires_sql = ':expires_at';
		} else {
			$expires_sql = 'NULL';
		}
		$sql = "INSERT IGNORE INTO " . DB_PREFIX . "user_role
			(mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
			VALUES (" . $target_id . ", " . $role_id . ", " . $kingdom_id . ", " . $park_id . ", " . $event_id . ", " . $unit_id . ", " . $granter_id . ", " . $expires_sql . ")";
		$DB->Execute( $sql );

		// Invalidate target user's cache
		$this->IncrementGenerationCounter( $target_id );

		// Audit log
		$this->AuditLog( $granter_id, 'grant_role', $target_id, $role_id, null, $kingdom_id, $park_id, $event_id, $unit_id,
			'Granted role ' . $role->display_name . ' at ' . $scope_type . ':' . $scope_id );

		return Success();
	}

	/**
	 * Revoke a role from a user.
	 *
	 * @param int $revoker_id  Who is revoking
	 * @param int $user_role_id  The ork_user_role.user_role_id to remove
	 * @return array  Standard ORK response array
	 */
	public function RevokeRole( $revoker_id, $user_role_id )
	{
		global $DB;

		$revoker_id = (int) $revoker_id;
		$user_role_id = (int) $user_role_id;

		if ( !valid_id( $revoker_id ) || !valid_id( $user_role_id ) ) {
			return InvalidParameter( null, 'Invalid IDs provided.' );
		}

		// Load the user_role record
		$ur = new yapo( $DB, DB_PREFIX . 'user_role' );
		$ur->clear();
		$ur->user_role_id = $user_role_id;
		if ( !$ur->find() ) {
			return InvalidParameter( null, 'User role assignment not found.' );
		}

		$target_id = $ur->mundane_id;
		$role_id = $ur->role_id;

		// Delete the assignment
		$ur->delete();

		// Invalidate target user's cache
		$this->IncrementGenerationCounter( $target_id );

		// Audit log
		$this->AuditLog( $revoker_id, 'revoke_role', $target_id, $role_id, null,
			$ur->kingdom_id, $ur->park_id, $ur->event_id, $ur->unit_id,
			'Revoked role assignment #' . $user_role_id );

		return Success();
	}

	/**
	 * Check which permissions in a role the granter lacks (escalation check).
	 *
	 * @return array  List of permission keys the granter is missing
	 */
	private function CheckEscalation( $granter_id, $role_id, $scope_type, $scope_id )
	{
		global $DB;
		$missing = [];

		// Get all permissions in the target role
		$DB->Clear();
		$sql = "SELECT p.`key`
			FROM " . DB_PREFIX . "role_permission rp
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE rp.role_id = " . (int) $role_id;
		$result = $DB->DataSet( $sql );

		if ( $result !== false && $result->size() > 0 ) {
			while ( $result->Next() ) {
				$perm_key = $result->key;
				if ( !$this->HasPermission( $granter_id, $perm_key, $scope_type, $scope_id ) ) {
					$missing[] = $perm_key;
				}
			}
		}

		return $missing;
	}

	// ================================================================
	// ROLE MANAGEMENT (CRUD for Custom Roles)
	// ================================================================

	/**
	 * Create a custom role for a kingdom.
	 *
	 * @param int    $creator_id    Who is creating the role
	 * @param int    $kingdom_id    Kingdom that owns the role
	 * @param string $name          Role machine name (lowercase, underscores)
	 * @param string $display_name  Human-readable name
	 * @param string $description   Description
	 * @param string $scope_type    Scope type for the role
	 * @param array  $permission_keys  Array of permission key strings
	 * @return array  Standard ORK response array (Detail = new role_id on success)
	 */
	public function CreateRole( $creator_id, $kingdom_id, $name, $display_name, $description, $scope_type, $permission_keys = [] )
	{
		global $DB;

		$creator_id = (int) $creator_id;
		$kingdom_id = (int) $kingdom_id;

		if ( !valid_id( $creator_id ) || !valid_id( $kingdom_id ) ) {
			return InvalidParameter( null, 'Invalid creator or kingdom ID.' );
		}

		// Validate permission keys
		$invalid_keys = [];
		foreach ( $permission_keys as $key ) {
			if ( !PermissionRegistry::Exists( $key ) ) {
				$invalid_keys[] = $key;
			}
		}
		if ( count( $invalid_keys ) > 0 ) {
			return InvalidParameter( null, 'Invalid permission keys: ' . implode( ', ', $invalid_keys ) );
		}

		// Escalation check: creator must hold every permission they're adding
		if ( !$this->IsAdmin( $creator_id ) ) {
			$missing = [];
			foreach ( $permission_keys as $key ) {
				if ( !$this->HasPermission( $creator_id, $key, 'kingdom', $kingdom_id ) ) {
					$missing[] = $key;
				}
			}
			if ( count( $missing ) > 0 ) {
				return NoAuthorization( 'Cannot create role with permissions you lack: ' . implode( ', ', $missing ) );
			}
		}

		// Insert the role
		$DB->Clear();
		$DB->role_name = trim( $name );
		$DB->display_name = trim( $display_name );
		$DB->role_desc = trim( $description );
		$DB->scope_type = $scope_type;
		$sql = "INSERT INTO " . DB_PREFIX . "role (`name`, `display_name`, `description`, `scope_type`, `is_system`, `kingdom_id`, `created_by`)
			VALUES (:role_name, :display_name, :role_desc, :scope_type, 0, " . $kingdom_id . ", " . $creator_id . ")";
		$DB->Execute( $sql );

		// Get the new role_id
		$DB->Clear();
		$DB->role_name = trim( $name );
		$sql = "SELECT role_id FROM " . DB_PREFIX . "role WHERE `name` = :role_name AND kingdom_id = " . $kingdom_id;
		$result = $DB->DataSet( $sql );
		if ( $result === false || $result->size() == 0 || !$result->Next() ) {
			return ProcessingError( 'Failed to create role.' );
		}
		$new_role_id = $result->role_id;

		// Map permissions to the role
		foreach ( $permission_keys as $key ) {
			$DB->Clear();
			$DB->perm_key = $key;
			$sql = "INSERT IGNORE INTO " . DB_PREFIX . "role_permission (role_id, permission_id)
				SELECT " . (int) $new_role_id . ", permission_id
				FROM " . DB_PREFIX . "permission
				WHERE `key` = :perm_key";
			$DB->Execute( $sql );
		}

		// Audit log
		$this->AuditLog( $creator_id, 'create_role', null, $new_role_id, null,
			$kingdom_id, 0, 0, 0,
			'Created custom role: ' . $display_name . ' with ' . count( $permission_keys ) . ' permissions' );

		return Success( $new_role_id );
	}

	/**
	 * Edit a custom role's permissions.
	 * System roles (is_system=1) cannot be edited.
	 *
	 * @param int   $editor_id        Who is editing
	 * @param int   $role_id          Role to edit
	 * @param array $permission_keys  New complete set of permission key strings
	 * @param string|null $display_name  Optional new display name
	 * @param string|null $description   Optional new description
	 * @return array  Standard ORK response
	 */
	public function EditRole( $editor_id, $role_id, $permission_keys, $display_name = null, $description = null )
	{
		global $DB;

		$editor_id = (int) $editor_id;
		$role_id = (int) $role_id;

		// Load the role
		$role = new yapo( $DB, DB_PREFIX . 'role' );
		$role->clear();
		$role->role_id = $role_id;
		if ( !$role->find() ) {
			return InvalidParameter( null, 'Role not found.' );
		}

		// Cannot edit system roles
		if ( $role->is_system ) {
			return NoAuthorization( 'System roles cannot be edited.' );
		}

		// Validate permission keys
		foreach ( $permission_keys as $key ) {
			if ( !PermissionRegistry::Exists( $key ) ) {
				return InvalidParameter( null, 'Invalid permission key: ' . $key );
			}
		}

		// Escalation check
		if ( !$this->IsAdmin( $editor_id ) ) {
			$missing = [];
			foreach ( $permission_keys as $key ) {
				if ( !$this->HasPermission( $editor_id, $key, 'kingdom', $role->kingdom_id ) ) {
					$missing[] = $key;
				}
			}
			if ( count( $missing ) > 0 ) {
				return NoAuthorization( 'Cannot add permissions you lack: ' . implode( ', ', $missing ) );
			}
		}

		// Update display_name / description if provided
		if ( $display_name !== null ) {
			$role->display_name = $display_name;
		}
		if ( $description !== null ) {
			$role->description = $description;
		}
		$role->save();

		// Replace permissions: delete all current, then insert new
		$DB->Clear();
		$DB->Execute( "DELETE FROM " . DB_PREFIX . "role_permission WHERE role_id = " . $role_id );

		foreach ( $permission_keys as $key ) {
			$DB->Clear();
			$DB->perm_key = $key;
			$sql = "INSERT IGNORE INTO " . DB_PREFIX . "role_permission (role_id, permission_id)
				SELECT " . $role_id . ", permission_id
				FROM " . DB_PREFIX . "permission
				WHERE `key` = :perm_key";
			$DB->Execute( $sql );
		}

		// Invalidate cache for all users with this role
		$this->InvalidateCacheForRole( $role_id );

		// Audit log
		$this->AuditLog( $editor_id, 'edit_role', null, $role_id, null,
			$role->kingdom_id, 0, 0, 0,
			'Edited custom role: ' . $role->display_name . ' — now has ' . count( $permission_keys ) . ' permissions' );

		return Success();
	}

	/**
	 * Delete a custom role. System roles cannot be deleted.
	 *
	 * @param int $deleter_id  Who is deleting
	 * @param int $role_id     Role to delete
	 * @return array  Standard ORK response
	 */
	public function DeleteRole( $deleter_id, $role_id )
	{
		global $DB;

		$deleter_id = (int) $deleter_id;
		$role_id = (int) $role_id;

		$role = new yapo( $DB, DB_PREFIX . 'role' );
		$role->clear();
		$role->role_id = $role_id;
		if ( !$role->find() ) {
			return InvalidParameter( null, 'Role not found.' );
		}

		if ( $role->is_system ) {
			return NoAuthorization( 'System roles cannot be deleted.' );
		}

		// Invalidate cache for all users with this role before deleting
		$this->InvalidateCacheForRole( $role_id );

		$kingdom_id = $role->kingdom_id;
		$display_name = $role->display_name;

		// Delete role-permission mappings
		$DB->Clear();
		$DB->Execute( "DELETE FROM " . DB_PREFIX . "role_permission WHERE role_id = " . $role_id );

		// Delete user-role assignments
		$DB->Clear();
		$DB->Execute( "DELETE FROM " . DB_PREFIX . "user_role WHERE role_id = " . $role_id );

		// Delete the role itself
		$role->delete();

		// Audit log
		$this->AuditLog( $deleter_id, 'delete_role', null, $role_id, null,
			$kingdom_id, 0, 0, 0,
			'Deleted custom role: ' . $display_name );

		return Success();
	}

	// ================================================================
	// OFFICER DUAL-WRITE
	// ================================================================

	/**
	 * Sync an officer change to ork_user_role (dual-write).
	 * Called from Common::set_officer() after the legacy officer record is updated.
	 *
	 * When a new officer is set:
	 *   1. Remove old officer's RBAC role assignment for this position+scope
	 *   2. Create new officer's RBAC role assignment (if new_officer_id > 0)
	 *
	 * @param int    $kingdom_id      Kingdom ID
	 * @param int    $park_id         Park ID (0 for kingdom-level)
	 * @param int    $old_officer_id  Previous officer mundane_id (0 if none)
	 * @param int    $new_officer_id  New officer mundane_id (0 if vacating)
	 * @param string $role            Officer role display name ('Monarch', 'Regent', etc.)
	 * @param int    $changed_by      Who made the change (0 = system)
	 */
	public function SyncOfficerRole( $kingdom_id, $park_id, $old_officer_id, $new_officer_id, $role, $changed_by = 0 )
	{
		global $DB;

		$kingdom_id = (int) $kingdom_id;
		$park_id = (int) $park_id;
		$old_officer_id = (int) $old_officer_id;
		$new_officer_id = (int) $new_officer_id;
		$changed_by = (int) $changed_by;

		// Map officer role to RBAC role name
		$rbac_role_name = PermissionRegistry::OfficerRoleToRbacRole( $role );
		if ( $rbac_role_name === null ) {
			logtrace( 'RBACService::SyncOfficerRole', 'Unknown officer role: ' . $role );
			return;
		}

		// Look up the system role_id
		$DB->Clear();
		$DB->rbac_role_name = $rbac_role_name;
		$sql = "SELECT role_id FROM " . DB_PREFIX . "role
			WHERE `name` = :rbac_role_name
			  AND kingdom_id = 0 AND is_system = 1
			LIMIT 1";
		$result = $DB->DataSet( $sql );
		if ( $result === false || $result->size() == 0 || !$result->Next() ) {
			logtrace( 'RBACService::SyncOfficerRole', 'System role not found for: ' . $rbac_role_name );
			return;
		}
		$rbac_role_id = (int) $result->role_id;

		// Remove old officer's role assignment for this position+scope
		if ( $old_officer_id > 0 ) {
			$DB->Clear();
			$DB->Execute(
				"DELETE FROM " . DB_PREFIX . "user_role
				 WHERE mundane_id = " . $old_officer_id . "
				   AND role_id = " . $rbac_role_id . "
				   AND kingdom_id = " . $kingdom_id . "
				   AND park_id = " . $park_id
			);
			$this->IncrementGenerationCounter( $old_officer_id );
		}

		// Create new officer's role assignment (skip if vacating, i.e. new_officer_id = 0)
		if ( $new_officer_id > 0 ) {
			$granted_by_sql = ( $changed_by > 0 ) ? $changed_by : 'NULL';
			$DB->Clear();
			$DB->Execute(
				"INSERT IGNORE INTO " . DB_PREFIX . "user_role
				 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
				 VALUES (" . $new_officer_id . ", " . $rbac_role_id . ", " . $kingdom_id . ", " . $park_id . ", 0, 0, " . $granted_by_sql . ", NULL)"
			);
			$this->IncrementGenerationCounter( $new_officer_id );
		}
	}

	/**
	 * Create RBAC role assignments for newly created officer slots.
	 * Called from Common::create_officer() — since new officers start with mundane_id=0,
	 * this is a no-op but exists for completeness and future use.
	 *
	 * @param int    $kingdom_id  Kingdom ID
	 * @param int    $park_id     Park ID (0 for kingdom-level)
	 * @param string $role        Officer role display name
	 */
	public function SyncNewOfficerSlot( $kingdom_id, $park_id, $role )
	{
		// New officer slots are created with mundane_id = 0, so there's nothing
		// to write to ork_user_role. When an actual officer is appointed via
		// set_officer(), SyncOfficerRole() will handle the RBAC assignment.
		logtrace( 'RBACService::SyncNewOfficerSlot', 'Slot created for ' . $role . ' at kingdom:' . $kingdom_id . ' park:' . $park_id );
	}

	// ================================================================
	// ROLE QUERY HELPERS
	// ================================================================

	/**
	 * Get all roles assigned to a user (optionally filtered by scope).
	 *
	 * @param int         $mundane_id
	 * @param string|null $scope_type   Optional filter
	 * @param int|null    $scope_id     Optional filter
	 * @return array  Array of role assignment records
	 */
	public function GetUserRoles( $mundane_id, $scope_type = null, $scope_id = null )
	{
		global $DB;
		$mundane_id = (int) $mundane_id;
		$roles = [];

		$where = "ur.mundane_id = " . $mundane_id;
		$where .= " AND (ur.expires_at IS NULL OR ur.expires_at > NOW())";

		if ( $scope_type !== null && $scope_id !== null ) {
			$scope_column = $this->ScopeTypeToColumn( $scope_type );
			if ( $scope_column !== null ) {
				$where .= " AND ur." . $scope_column . " = " . (int) $scope_id;
			}
		}

		$DB->Clear();
		$sql = "SELECT ur.user_role_id, ur.role_id, r.name, r.display_name, r.is_system,
				ur.kingdom_id, ur.park_id, ur.event_id, ur.unit_id,
				ur.granted_by, ur.created_at, ur.expires_at
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role r ON r.role_id = ur.role_id
			WHERE " . $where . "
			ORDER BY r.display_name";

		$result = $DB->DataSet( $sql );
		if ( $result !== false && $result->size() > 0 ) {
			while ( $result->Next() ) {
				$roles[] = [
					'UserRoleId' => $result->user_role_id,
					'RoleId' => $result->role_id,
					'Name' => $result->name,
					'DisplayName' => $result->display_name,
					'IsSystem' => $result->is_system,
					'KingdomId' => $result->kingdom_id,
					'ParkId' => $result->park_id,
					'EventId' => $result->event_id,
					'UnitId' => $result->unit_id,
					'GrantedBy' => $result->granted_by,
					'CreatedAt' => $result->created_at,
					'ExpiresAt' => $result->expires_at,
				];
			}
		}

		return $roles;
	}

	/**
	 * Get all permissions in a role.
	 *
	 * @param int $role_id
	 * @return array  Array of permission records
	 */
	public function GetRolePermissions( $role_id )
	{
		global $DB;
		$role_id = (int) $role_id;
		$perms = [];

		$DB->Clear();
		$sql = "SELECT p.permission_id, p.`key`, p.display_name, p.description, p.scope_type, p.category
			FROM " . DB_PREFIX . "role_permission rp
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE rp.role_id = " . $role_id . "
			ORDER BY p.scope_type, p.category, p.`key`";

		$result = $DB->DataSet( $sql );
		if ( $result !== false && $result->size() > 0 ) {
			while ( $result->Next() ) {
				$perms[] = [
					'PermissionId' => $result->permission_id,
					'Key' => $result->key,
					'DisplayName' => $result->display_name,
					'Description' => $result->description,
					'ScopeType' => $result->scope_type,
					'Category' => $result->category,
				];
			}
		}

		return $perms;
	}

	/**
	 * Get the effective permissions for a user at a scope.
	 * Aggregates all permissions from all roles assigned at that scope.
	 *
	 * @param int    $mundane_id
	 * @param string $scope_type
	 * @param int    $scope_id
	 * @return array  Array of permission key strings
	 */
	public function GetEffectivePermissions( $mundane_id, $scope_type, $scope_id )
	{
		global $DB;
		$mundane_id = (int) $mundane_id;
		$scope_id = (int) $scope_id;
		$permissions = [];

		$scope_column = $this->ScopeTypeToColumn( $scope_type );
		if ( $scope_column === null ) {
			return $permissions;
		}

		$DB->Clear();
		$sql = "SELECT DISTINCT p.`key`
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role_permission rp ON rp.role_id = ur.role_id
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE ur.mundane_id = " . $mundane_id . "
			  AND ur." . $scope_column . " = " . $scope_id . "
			  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
			ORDER BY p.`key`";

		$result = $DB->DataSet( $sql );
		if ( $result !== false && $result->size() > 0 ) {
			while ( $result->Next() ) {
				$permissions[] = $result->key;
			}
		}

		return $permissions;
	}

	/**
	 * Get all available roles (system + custom for a kingdom).
	 *
	 * @param int $kingdom_id  Kingdom ID (0 for system roles only)
	 * @return array
	 */
	public function GetAvailableRoles( $kingdom_id = 0 )
	{
		global $DB;
		$kingdom_id = (int) $kingdom_id;
		$roles = [];

		$DB->Clear();
		$sql = "SELECT role_id, `name`, display_name, description, scope_type, is_system, kingdom_id
			FROM " . DB_PREFIX . "role
			WHERE kingdom_id = 0 OR kingdom_id = " . $kingdom_id . "
			ORDER BY is_system DESC, display_name";

		$result = $DB->DataSet( $sql );
		if ( $result !== false && $result->size() > 0 ) {
			while ( $result->Next() ) {
				$roles[] = [
					'RoleId' => $result->role_id,
					'Name' => $result->name,
					'DisplayName' => $result->display_name,
					'Description' => $result->description,
					'ScopeType' => $result->scope_type,
					'IsSystem' => $result->is_system,
					'KingdomId' => $result->kingdom_id,
				];
			}
		}

		return $roles;
	}

	// ================================================================
	// AUDIT LOGGING
	// ================================================================

	/**
	 * Write an audit record to ork_rbac_audit.
	 */
	private function AuditLog( $actor_id, $action, $target_id, $role_id, $permission_id, $kingdom_id, $park_id, $event_id, $unit_id, $detail )
	{
		global $DB;
		$DB->Clear();
		$DB->audit_action = $action;
		$DB->audit_detail = $detail;
		$sql = "INSERT INTO " . DB_PREFIX . "rbac_audit
			(actor_mundane_id, `action`, target_mundane_id, role_id, permission_id,
			 scope_kingdom_id, scope_park_id, scope_event_id, scope_unit_id, detail)
			VALUES (" .
			(int) $actor_id . ", :audit_action, " .
			( $target_id !== null ? (int) $target_id : 'NULL' ) . ", " .
			( $role_id !== null ? (int) $role_id : 'NULL' ) . ", " .
			( $permission_id !== null ? (int) $permission_id : 'NULL' ) . ", " .
			(int) $kingdom_id . ", " .
			(int) $park_id . ", " .
			(int) $event_id . ", " .
			(int) $unit_id . ", :audit_detail)";
		$DB->Execute( $sql );
	}

	// ================================================================
	// CACHE HELPERS
	// ================================================================

	/**
	 * Invalidate the cache for all users who have a given role.
	 * Used when a role's permissions are changed.
	 */
	private function InvalidateCacheForRole( $role_id )
	{
		global $DB;
		$DB->Clear();
		$sql = "SELECT DISTINCT mundane_id FROM " . DB_PREFIX . "user_role WHERE role_id = " . (int) $role_id;
		$result = $DB->DataSet( $sql );
		if ( $result !== false && $result->size() > 0 ) {
			while ( $result->Next() ) {
				$this->IncrementGenerationCounter( $result->mundane_id );
			}
		}
	}
}

?>
