<?php

/***
 * PermissionRegistry
 *
 * Source of truth for all RBAC permissions. Defines the complete list of
 * atomic permissions used by the system. The database ork_permission table
 * is seeded from this registry via SyncToDatabase().
 *
 * Auto-loaded by startup.php as Ork3::$Lib->permissionregistry
 ***/

class PermissionRegistry extends Ork3
{

	/**
	 * Master list of all permissions.
	 * Format: 'key' => ['display_name', 'description', 'scope_type', 'category']
	 *
	 * Naming convention: {scope}.{resource}.{action}
	 */
	private static $permissions = [

		// ========================================
		// Kingdom-Scoped (15)
		// ========================================
		'kingdom.details.edit' => [
			'Edit Kingdom Details',
			'Edit kingdom name, description, and basic details',
			'kingdom', 'config'
		],
		'kingdom.config.edit' => [
			'Edit Kingdom Config',
			'Edit kingdom configuration settings',
			'kingdom', 'config'
		],
		'kingdom.parktitle.manage' => [
			'Manage Park Titles',
			'Create, edit, and remove park title definitions',
			'kingdom', 'config'
		],
		'kingdom.award.create' => [
			'Create Kingdom Award',
			'Create new kingdom award definitions',
			'kingdom', 'award'
		],
		'kingdom.award.edit' => [
			'Edit Kingdom Award',
			'Edit existing kingdom award definitions',
			'kingdom', 'award'
		],
		'kingdom.award.remove' => [
			'Remove Kingdom Award',
			'Remove kingdom award definitions',
			'kingdom', 'award'
		],
		'kingdom.officer.set' => [
			'Set Kingdom Officer',
			'Appoint kingdom-level officers',
			'kingdom', 'officer'
		],
		'kingdom.officer.vacate' => [
			'Vacate Kingdom Officer',
			'Remove kingdom-level officers from office',
			'kingdom', 'officer'
		],
		'kingdom.officer_history.manage' => [
			'Manage Officer History',
			'Create, edit, and delete officer history records',
			'kingdom', 'officer'
		],
		'kingdom.heraldry.manage' => [
			'Manage Kingdom Heraldry',
			'Upload and remove kingdom heraldry',
			'kingdom', 'heraldry'
		],
		'kingdom.auth.manage' => [
			'Manage Kingdom Authorizations',
			'Add and remove kingdom-level authorizations',
			'kingdom', 'auth'
		],
		'kingdom.park.create' => [
			'Create Parks',
			'Create new parks within the kingdom',
			'kingdom', 'config'
		],
		'kingdom.park.retire' => [
			'Retire/Restore Parks',
			'Retire or restore parks within the kingdom',
			'kingdom', 'config'
		],
		'kingdom.park.bulk_edit' => [
			'Bulk Edit Parks',
			'Bulk edit park settings across the kingdom',
			'kingdom', 'config'
		],
		'kingdom.park.claim' => [
			'Claim/Transfer Parks',
			'Claim or transfer parks between kingdoms',
			'kingdom', 'config'
		],

		// ========================================
		// Park-Scoped (12)
		// ========================================
		'park.details.edit' => [
			'Edit Park Details',
			'Edit park name, description, and basic details',
			'park', 'config'
		],
		'park.officer.set' => [
			'Set Park Officer',
			'Appoint park-level officers',
			'park', 'officer'
		],
		'park.officer.vacate' => [
			'Vacate Park Officer',
			'Remove park-level officers from office',
			'park', 'officer'
		],
		'park.officer_history.manage' => [
			'Manage Park Officer History',
			'Create, edit, and delete park officer history records',
			'park', 'officer'
		],
		'park.heraldry.manage' => [
			'Manage Park Heraldry',
			'Upload and remove park heraldry',
			'park', 'heraldry'
		],
		'park.auth.manage' => [
			'Manage Park Authorizations',
			'Add and remove park-level authorizations',
			'park', 'auth'
		],
		'park.parkday.manage' => [
			'Manage Park Days',
			'Create, edit, and delete park day schedules',
			'park', 'config'
		],
		'park.event.create' => [
			'Create Park Events',
			'Create events for the park',
			'park', 'event'
		],
		'park.attendance.manage' => [
			'Manage Attendance',
			'Record, edit, and delete attendance entries',
			'park', 'event'
		],
		'park.report.view' => [
			'View Park Reports',
			'Access park-level reports',
			'park', 'config'
		],
		'park.dues.manage' => [
			'Manage Dues',
			'Record and manage player dues',
			'park', 'financial'
		],
		'park.reconcile_credits' => [
			'Set Reconciled Credits',
			'Set reconciled credit amounts for players',
			'park', 'financial'
		],

		// ========================================
		// Player-Scoped at park level (12)
		// ========================================
		'player.create' => [
			'Create Player',
			'Create new player accounts',
			'park', 'player'
		],
		'player.edit' => [
			'Edit Other Player Details',
			'Edit other players profile details',
			'park', 'player'
		],
		'player.move' => [
			'Move Player Between Parks',
			'Transfer players between parks',
			'park', 'player'
		],
		'player.merge' => [
			'Merge Players',
			'Merge duplicate player records',
			'park', 'player'
		],
		'player.suspend' => [
			'Set Player Suspension',
			'Suspend or unsuspend player accounts',
			'park', 'player'
		],
		'player.waiver.manage' => [
			'Manage Waivers & Restrictions',
			'Manage player waivers and restrictions',
			'park', 'player'
		],
		'player.qualification.edit' => [
			'Edit Reeve/Corpora Qualifications',
			'Edit player reeve and corpora qualification status',
			'park', 'player'
		],
		'player.heraldry.manage' => [
			'Manage Other Player Heraldry/Image',
			'Upload and remove other players heraldry and images',
			'park', 'heraldry'
		],
		'player.note.manage' => [
			'Manage Other Player Notes',
			'Create, edit, and delete notes on other players',
			'park', 'player'
		],
		'player.award.manage' => [
			'Manage Player Awards',
			'Grant, edit, and remove player awards',
			'park', 'award'
		],
		'player.recommendation.manage' => [
			'Manage Award Recommendations',
			'Manage award recommendations for players',
			'park', 'award'
		],
		'player.active_status.set' => [
			'Set Player Active Status',
			'Set player active/inactive status',
			'park', 'player'
		],

		// ========================================
		// Event-Scoped (8)
		// ========================================
		'event.edit' => [
			'Edit Event',
			'Edit event name, dates, and basic details',
			'event', 'event'
		],
		'event.delete' => [
			'Delete Event',
			'Delete events',
			'event', 'event'
		],
		'event.detail.manage' => [
			'Manage Event Details',
			'Manage event locations, descriptions, and details',
			'event', 'event'
		],
		'event.heraldry.manage' => [
			'Manage Event Heraldry',
			'Upload and remove event heraldry',
			'event', 'heraldry'
		],
		'event.attendance.manage' => [
			'Manage Event Attendance',
			'Record, edit, and delete event attendance',
			'event', 'event'
		],
		'event.reconcile' => [
			'Reconcile Event Attendance',
			'Reconcile event attendance records',
			'event', 'event'
		],
		'event.auth.manage' => [
			'Manage Event Authorizations',
			'Add and remove event-level authorizations',
			'event', 'auth'
		],
		'event.rsvp.manage' => [
			'Manage RSVPs (admin)',
			'Manage event RSVPs on behalf of other players',
			'event', 'event'
		],

		// ========================================
		// Unit-Scoped (5)
		// ========================================
		'unit.edit' => [
			'Edit Unit Details',
			'Edit unit name, description, and details',
			'unit', 'config'
		],
		'unit.member.manage' => [
			'Manage Unit Members',
			'Add, remove, and manage unit members',
			'unit', 'player'
		],
		'unit.heraldry.manage' => [
			'Manage Unit Heraldry',
			'Upload and remove unit heraldry',
			'unit', 'heraldry'
		],
		'unit.convert' => [
			'Convert Unit Type',
			'Convert unit between company and household types',
			'unit', 'config'
		],
		'unit.auth.manage' => [
			'Manage Unit Authorizations',
			'Add and remove unit-level authorizations',
			'unit', 'auth'
		],

		// ========================================
		// Tournament (2)
		// ========================================
		'tournament.bracket.manage' => [
			'Manage Tournament Brackets',
			'Create, edit, and manage tournament brackets',
			'event', 'event'
		],
		'tournament.delete' => [
			'Delete Tournament',
			'Delete tournament records',
			'event', 'event'
		],
	];

	/**
	 * Map from officer role display name (as stored in ork_officer.role)
	 * to the RBAC system role name (as stored in ork_role.name).
	 */
	private static $officerRoleMap = [
		'Monarch'        => 'monarch',
		'Regent'         => 'regent',
		'Prime Minister' => 'prime_minister',
		'Champion'       => 'champion',
		'GMR'            => 'gmr',
	];

	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * Get the full permissions array.
	 *
	 * @return array  Keyed by permission key string
	 */
	public static function GetAll()
	{
		return self::$permissions;
	}

	/**
	 * Get a single permission definition by key.
	 *
	 * @param string $key  Permission key, e.g. 'kingdom.award.create'
	 * @return array|null  [display_name, description, scope_type, category] or null
	 */
	public static function Get( $key )
	{
		return isset( self::$permissions[$key] ) ? self::$permissions[$key] : null;
	}

	/**
	 * Check if a permission key exists in the registry.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function Exists( $key )
	{
		return isset( self::$permissions[$key] );
	}

	/**
	 * Get all permission keys for a given scope type.
	 *
	 * @param string $scope_type  One of: 'kingdom', 'park', 'event', 'unit'
	 * @return array  Array of permission key strings
	 */
	public static function GetByScope( $scope_type )
	{
		$result = [];
		foreach ( self::$permissions as $key => $def ) {
			if ( $def[2] === $scope_type ) {
				$result[] = $key;
			}
		}
		return $result;
	}

	/**
	 * Get all permission keys for a given category.
	 *
	 * @param string $category  One of: 'config', 'award', 'officer', 'heraldry', 'auth', 'event', 'player', 'financial'
	 * @return array  Array of permission key strings
	 */
	public static function GetByCategory( $category )
	{
		$result = [];
		foreach ( self::$permissions as $key => $def ) {
			if ( $def[3] === $category ) {
				$result[] = $key;
			}
		}
		return $result;
	}

	/**
	 * Map an officer role display name to the RBAC system role name.
	 *
	 * @param string $officer_role  E.g. 'Monarch', 'Prime Minister', 'Champion'
	 * @return string|null  RBAC role name (e.g. 'monarch', 'prime_minister') or null if not mapped
	 */
	public static function OfficerRoleToRbacRole( $officer_role )
	{
		return isset( self::$officerRoleMap[$officer_role] ) ? self::$officerRoleMap[$officer_role] : null;
	}

	/**
	 * Get the officer role map (officer display name => RBAC role name).
	 *
	 * @return array
	 */
	public static function GetOfficerRoleMap()
	{
		return self::$officerRoleMap;
	}

	/**
	 * Get total count of registered permissions.
	 *
	 * @return int
	 */
	public static function Count()
	{
		return count( self::$permissions );
	}

	/**
	 * Sync the in-code permission registry to the ork_permission database table.
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotent upserts.
	 *
	 * Call this on deploy or when permissions change.
	 *
	 * @return array  ['synced' => int count, 'errors' => array]
	 */
	public function SyncToDatabase()
	{
		global $DB;
		$synced = 0;
		$errors = [];

		foreach ( self::$permissions as $key => $def ) {
			list( $display_name, $description, $scope_type, $category ) = $def;

			$DB->Clear();
			$DB->perm_key = $key;
			$DB->display_name = $display_name;
			$DB->perm_desc = $description;
			$DB->scope_type = $scope_type;
			$DB->category = $category;
			$DB->upd_display_name = $display_name;
			$DB->upd_perm_desc = $description;
			$DB->upd_scope_type = $scope_type;
			$DB->upd_category = $category;
			$sql = "INSERT INTO " . DB_PREFIX . "permission (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`)
				VALUES (:perm_key, :display_name, :perm_desc, :scope_type, :category, 1)
				ON DUPLICATE KEY UPDATE
					`display_name` = :upd_display_name,
					`description` = :upd_perm_desc,
					`scope_type` = :upd_scope_type,
					`category` = :upd_category";

			$result = $DB->Execute( $sql );
			if ( $result === false ) {
				$errors[] = "Failed to sync permission: " . $key;
			} else {
				$synced++;
			}
		}

		return [ 'synced' => $synced, 'errors' => $errors ];
	}
}

?>
