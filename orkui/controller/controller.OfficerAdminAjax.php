<?php

/***
 * Controller_OfficerAdminAjax
 *
 * JSON Ajax endpoint for the Officer Admin Expansion "Manage Officers" UI
 * (Phase 4 + P5 retire/reinstate). Orchestrates the P2 OfficerPosition service
 * (Ork3::$Lib->officerposition / Model_OfficerPosition) and RBAC helpers; all DB
 * logic lives in the lib layer.
 *
 * Route shape (mirrors KingdomAjax):
 *   index.php?Route=OfficerAdminAjax/officer/{kingdom_id}/{action}
 *
 * ParkId comes from POST 'ParkId' (default 0 = kingdom scope for the prototype).
 *
 * Response envelope: success => {status:0, ...}; failure => {status:N, error:'...'}.
 * Not logged in => {status:5}; invalid kingdom => {status:1}; unauthorized => {status:5}.
 ***/

class Controller_OfficerAdminAjax extends Controller {

	public function officer($p = null) {
		header('Content-Type: application/json');

		$parts      = explode('/', $p ?? '');
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action     = strtolower(trim($parts[1] ?? ''));

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($kingdom_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
			exit;
		}

		$uid     = (int)$this->session->user_id;
		$park_id = (int)($_POST['ParkId'] ?? 0);

		// Per-action permission gate (scope = kingdom, id = kingdom_id).
		$gate = [
			'list'           => 'kingdom.officer.set',
			'setoccupant'    => 'kingdom.officer.set',
			'vacate'         => 'kingdom.officer.set',
			'createposition' => 'kingdom.officer.position.manage',
			'editposition'   => 'kingdom.officer.position.manage',
			'reclassify'     => 'kingdom.officer.position.manage',
			'retire'         => 'kingdom.officer.position.manage',
			'reinstate'      => 'kingdom.officer.position.manage',
			'roles'          => 'kingdom.officer.position.manage',
			'permissions'    => 'kingdom.officer.position.manage',
		];

		if (!isset($gate[$action])) {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
			exit;
		}

		if (!Ork3::$Lib->authorization->HasPermissionOrAuthority($uid, $gate[$action], 'kingdom', $kingdom_id, AUTH_EDIT)) {
			echo json_encode(['status' => 5, 'error' => 'Unauthorized']);
			exit;
		}

		$this->load_model('OfficerPosition');

		switch ($action) {
			case 'list':         $this->actionList($kingdom_id, $park_id);              break;
			case 'setoccupant':  $this->actionSetOccupant($kingdom_id, $park_id, $uid); break;
			case 'vacate':       $this->actionVacate($kingdom_id, $park_id, $uid);      break;
			case 'createposition': $this->actionCreatePosition($kingdom_id, $uid);      break;
			case 'editposition': $this->actionEditPosition($kingdom_id, $uid);          break;
			case 'reclassify':   $this->actionReclassify($kingdom_id, $uid);            break;
			case 'retire':       $this->actionRetire($uid);                             break;
			case 'reinstate':    $this->actionReinstate();                              break;
			case 'roles':        $this->actionRoles($kingdom_id);                       break;
			case 'permissions':  $this->actionPermissions();                           break;
		}
		exit;
	}

	// ============================================================
	// HELPERS
	// ============================================================

	/**
	 * Map a service response array (['Status'=>0,'Error'=>..,'Detail'=>..]) into
	 * the JSON envelope. Status 0 = success. The user-facing rejection text is
	 * carried in 'Error' for these service methods (custom message is passed as
	 * the second InvalidParameter/NoAuthorization arg); fall back to 'Detail'.
	 */
	private function emitServiceResult($r, $extraOnSuccess = []) {
		if (is_array($r) && isset($r['Status']) && (int)$r['Status'] === 0) {
			echo json_encode(array_merge(['status' => 0], $extraOnSuccess));
			return;
		}
		$status = (is_array($r) && isset($r['Status'])) ? (int)$r['Status'] : 1;
		$msg    = '';
		if (is_array($r)) {
			if (isset($r['Error']) && is_string($r['Error']) && $r['Error'] !== '') {
				$msg = $r['Error'];
			} elseif (isset($r['Detail']) && is_string($r['Detail']) && $r['Detail'] !== '') {
				$msg = $r['Detail'];
			}
		}
		if ($msg === '') {
			$msg = 'The request could not be completed.';
		}
		echo json_encode(['status' => $status ?: 1, 'error' => $msg]);
	}

	/** Slugify a title into a canonical key (lowercase, underscores, alnum). */
	private function slugify($value) {
		$value = strtolower(trim((string)$value));
		$value = preg_replace('/[^a-z0-9]+/', '_', $value);
		return trim($value, '_');
	}

	/** Normalize a POSTed permission-keys value (array or comma string) into a clean array. */
	private function parsePermissionKeys($raw) {
		if (is_array($raw)) {
			$keys = $raw;
		} else {
			$keys = explode(',', (string)$raw);
		}
		$out = [];
		foreach ($keys as $k) {
			$k = trim((string)$k);
			if ($k !== '') {
				$out[] = $k;
			}
		}
		return array_values(array_unique($out));
	}

	/** Build the rbac_choice array from POST (mode + role_id / permission_keys). */
	private function buildRbacChoice() {
		$mode = strtolower(trim($_POST['RbacMode'] ?? ''));
		if ($mode === 'custom') {
			return ['mode' => 'custom', 'permission_keys' => $this->parsePermissionKeys($_POST['PermissionKeys'] ?? [])];
		}
		if ($mode === 'existing') {
			return ['mode' => 'existing', 'role_id' => (int)($_POST['RoleId'] ?? 0)];
		}
		return null;
	}

	/** Human-readable date or '' (never raw ISO with a T). */
	private function humanDate($value) {
		$value = trim((string)$value);
		if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
			return '';
		}
		$ts = strtotime($value);
		if ($ts === false) {
			return '';
		}
		return date('M j, Y', $ts);
	}

	/** Compose a persona/name display string from a GetOfficersForDisplay row. */
	private function personaLabel($row) {
		$persona = trim((string)($row['Persona'] ?? ''));
		if ($persona !== '') {
			return $persona;
		}
		$name = trim(trim((string)($row['GivenName'] ?? '')) . ' ' . trim((string)($row['Surname'] ?? '')));
		if ($name !== '') {
			return $name;
		}
		return trim((string)($row['UserName'] ?? ''));
	}

	// ============================================================
	// ACTIONS
	// ============================================================

	private function actionList($kingdom_id, $park_id) {
		// Crown/supporting from the occupancy-resolved display; one POS object per
		// registry position (crown single occupant, supporting array of occupants).
		$display = $this->OfficerPosition->GetOfficersForDisplay($kingdom_id, $park_id, false);
		$positions = $this->OfficerPosition->GetPositions($kingdom_id, true, null);

		// Index the registry rows so we can attach full POS metadata to each entry.
		$regByPos = [];
		foreach ($positions as $pos) {
			$regByPos[(int)$pos['PositionId']] = $pos;
		}

		// Index display rows by position_id, grouped by classification.
		$crownOcc = [];      // position_id => single occupant row
		$supportingOcc = []; // position_id => [occupant rows]
		foreach (($display['crown'] ?? []) as $row) {
			$pid = (int)$row['PositionId'];
			if ((int)$row['MundaneId'] > 0) {
				$crownOcc[$pid] = $row;
			} elseif (!isset($crownOcc[$pid])) {
				$crownOcc[$pid] = null; // vacant placeholder slot present
			}
		}
		foreach (($display['supporting'] ?? []) as $row) {
			$pid = (int)$row['PositionId'];
			if (!isset($supportingOcc[$pid])) {
				$supportingOcc[$pid] = [];
			}
			if ((int)$row['MundaneId'] > 0) {
				$supportingOcc[$pid][] = $row;
			}
		}

		$crown = [];
		$supporting = [];
		$retired = [];

		foreach ($positions as $pos) {
			$pid = (int)$pos['PositionId'];
			$base = $this->posBase($pos);

			if ($pos['retired_at'] !== null && $pos['retired_at'] !== '') {
				$retired[] = $base;
				continue;
			}

			if ($pos['classification'] === 'supporting') {
				$occ = [];
				foreach (($supportingOcc[$pid] ?? []) as $row) {
					$occ[] = $this->occupant($row);
				}
				$base['Occupants'] = $occ;
				$supporting[] = $base;
			} else {
				$row = $crownOcc[$pid] ?? null;
				$base['Occupant'] = ($row && (int)$row['MundaneId'] > 0) ? $this->occupant($row) : null;
				$crown[] = $base;
			}
		}

		echo json_encode(['status' => 0, 'data' => [
			'crown'      => $crown,
			'supporting' => $supporting,
			'retired'    => $retired,
		]]);
	}

	/** Contracted POS object (camelCase keys) from a registry row. */
	private function posBase($pos) {
		return [
			'PositionId'    => (int)$pos['PositionId'],
			'CanonicalKey'  => $pos['CanonicalKey'],
			'DisplayTitle'  => $pos['DisplayTitle'],
			'Title'         => $pos['Title'],
			'TitleAlias'    => (string)($pos['TitleAlias'] ?? ''),
			'Classification'=> $pos['Classification'],
			'IsPinned'      => (int)$pos['IsPinned'],
			'IsSystem'      => (int)$pos['IsSystem'],
			'RbacRoleId'    => (int)$pos['RbacRoleId'],
			'SortOrder'     => (int)$pos['SortOrder'],
			'ParentPositionId' => isset($pos['ParentPositionId']) && $pos['ParentPositionId'] !== null ? (int)$pos['ParentPositionId'] : null,
			'HideWhenVacant'   => (int)($pos['HideWhenVacant'] ?? 0),
			'RetiredAt'     => $this->humanDate($pos['RetiredAt'] ?? ''),
		];
	}

	/** Contracted occupant object from a GetOfficersForDisplay row. */
	private function occupant($row) {
		return [
			'MundaneId' => (int)$row['MundaneId'],
			'Persona'   => $this->personaLabel($row),
			'TermStart' => '', // term metadata not yet surfaced by the display layer
			'TermEnd'   => '',
		];
	}

	private function actionSetOccupant($kingdom_id, $park_id, $uid) {
		$position_id = (int)($_POST['PositionId'] ?? 0);
		$mundane_id  = (int)($_POST['MundaneId'] ?? 0);
		$term_start  = trim($_POST['TermStart'] ?? '');
		$term_end    = trim($_POST['TermEnd'] ?? '');
		$note        = trim($_POST['Note'] ?? '');

		if (!valid_id($position_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid position is required.']);
			return;
		}
		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid member is required.']);
			return;
		}

		$r = $this->OfficerPosition->SetOfficerByPosition(
			$kingdom_id, $park_id, $position_id, $mundane_id, $term_start, $term_end, $note, $uid
		);
		$this->emitServiceResult($r);
	}

	private function actionVacate($kingdom_id, $park_id, $uid) {
		$position_id = (int)($_POST['PositionId'] ?? 0);
		if (!valid_id($position_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid position is required.']);
			return;
		}

		// NOTE: the P2 service's VacateOfficerByPosition vacates a position+scope as
		// a whole (for supporting positions it removes ALL occupants of that
		// position in this scope). It does not target a single supporting holder by
		// MundaneId, so the optional MundaneId POST param is accepted but not used.
		$r = $this->OfficerPosition->VacateOfficerByPosition($kingdom_id, $park_id, $position_id, $uid);
		$this->emitServiceResult($r);
	}

	private function actionCreatePosition($kingdom_id, $uid) {
		$title          = trim($_POST['Title'] ?? '');
		$classification = strtolower(trim($_POST['Classification'] ?? ''));
		$canonical_key  = trim($_POST['CanonicalKey'] ?? '');

		if ($title === '') {
			echo json_encode(['status' => 1, 'error' => 'A position title is required.']);
			return;
		}
		if ($classification !== 'crown' && $classification !== 'supporting') {
			echo json_encode(['status' => 1, 'error' => 'Classification must be crown or supporting.']);
			return;
		}
		$rbac_choice = $this->buildRbacChoice();
		if ($rbac_choice === null) {
			echo json_encode(['status' => 1, 'error' => 'An RBAC role binding (existing role or custom permissions) is required.']);
			return;
		}
		if ($canonical_key === '') {
			$canonical_key = $this->slugify($title);
		}

		// "Reports To" nesting + hide-when-vacant. 0/'' = no parent (top-level).
		$parent_raw = $_POST['ParentPositionId'] ?? '';
		$parent_position_id = ($parent_raw === '' || (int)$parent_raw === 0) ? null : (int)$parent_raw;
		$hide_when_vacant = (int)($_POST['HideWhenVacant'] ?? 0) ? 1 : 0;

		$r = $this->OfficerPosition->CreatePosition($kingdom_id, $canonical_key, $title, $classification, $rbac_choice, $uid, $parent_position_id, $hide_when_vacant);
		if (is_array($r) && isset($r['Status']) && (int)$r['Status'] === 0) {
			$this->emitServiceResult($r, ['data' => ['PositionId' => (int)($r['Detail'] ?? 0)]]);
		} else {
			$this->emitServiceResult($r);
		}
	}

	private function actionEditPosition($kingdom_id, $uid) {
		$position_id = (int)($_POST['PositionId'] ?? 0);
		if (!valid_id($position_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid position is required.']);
			return;
		}

		$fields = ['changed_by' => $uid, 'editor_id' => $uid];

		if (isset($_POST['Title'])) {
			$fields['title'] = trim($_POST['Title']);
		}
		if (isset($_POST['TitleAlias'])) {
			// '' clears the alias; never null (yapo/SQL semantics).
			$fields['title_alias'] = trim($_POST['TitleAlias']);
		}
		if (isset($_POST['Classification'])) {
			$fields['classification'] = strtolower(trim($_POST['Classification']));
		}
		if (isset($_POST['SortOrder']) && $_POST['SortOrder'] !== '') {
			$fields['sort_order'] = (int)$_POST['SortOrder'];
		}
		// "Reports To" nesting + hide-when-vacant. Only applied when the POST key
		// is present, so partial edits don't clobber the stored value.
		if (isset($_POST['ParentPositionId'])) {
			$pp = trim((string)$_POST['ParentPositionId']);
			$fields['parent_position_id'] = ($pp === '' || (int)$pp === 0) ? null : (int)$pp;
		}
		if (isset($_POST['HideWhenVacant'])) {
			$fields['hide_when_vacant'] = (int)$_POST['HideWhenVacant'] ? 1 : 0;
		}
		// RBAC rebinding: existing role => rbac_role_id; custom => permission_keys.
		$mode = strtolower(trim($_POST['RbacMode'] ?? ''));
		if ($mode === 'existing' && isset($_POST['RoleId'])) {
			$fields['rbac_role_id'] = (int)$_POST['RoleId'];
		} elseif ($mode === 'custom' && isset($_POST['PermissionKeys'])) {
			$fields['permission_keys'] = $this->parsePermissionKeys($_POST['PermissionKeys']);
		}

		$r = $this->OfficerPosition->EditPosition($position_id, $fields, $kingdom_id);
		$this->emitServiceResult($r, ['data' => ['PositionId' => $position_id]]);
	}

	private function actionReclassify($kingdom_id, $uid) {
		$position_id    = (int)($_POST['PositionId'] ?? 0);
		$classification = strtolower(trim($_POST['Classification'] ?? ''));
		if (!valid_id($position_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid position is required.']);
			return;
		}
		if ($classification !== 'crown' && $classification !== 'supporting') {
			echo json_encode(['status' => 1, 'error' => 'Classification must be crown or supporting.']);
			return;
		}

		$r = $this->OfficerPosition->EditPosition($position_id, [
			'classification' => $classification,
			'changed_by'     => $uid,
			'editor_id'      => $uid,
		], $kingdom_id);
		$this->emitServiceResult($r, ['data' => ['PositionId' => $position_id]]);
	}

	private function actionRetire($uid) {
		$position_id = (int)($_POST['PositionId'] ?? 0);
		if (!valid_id($position_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid position is required.']);
			return;
		}

		$r = $this->OfficerPosition->RetirePosition($position_id, $uid);
		// On success the service returns the list of vacated occupants in Detail so
		// the UI can confirm what was cleared.
		if (is_array($r) && isset($r['Status']) && (int)$r['Status'] === 0) {
			$vacated = (isset($r['Detail']) && is_array($r['Detail'])) ? $r['Detail'] : [];
			$this->emitServiceResult($r, ['data' => ['Vacated' => $vacated]]);
		} else {
			$this->emitServiceResult($r);
		}
	}

	private function actionReinstate() {
		$position_id = (int)($_POST['PositionId'] ?? 0);
		if (!valid_id($position_id)) {
			echo json_encode(['status' => 1, 'error' => 'A valid position is required.']);
			return;
		}

		$r = $this->OfficerPosition->ReinstatePosition($position_id);
		$this->emitServiceResult($r, ['data' => ['PositionId' => $position_id]]);
	}

	private function actionRoles($kingdom_id) {
		// System roles (is_system=1, kingdom_id=0) + this kingdom's custom roles.
		// GetAvailableRoles already returns kingdom_id=0 OR kingdom_id=$kingdom_id.
		$roles = [];
		if (isset(Ork3::$Lib->rbacservice)) {
			$rows = Ork3::$Lib->rbacservice->GetAvailableRoles($kingdom_id);
			foreach ($rows as $row) {
				$roles[] = [
					'RoleId'      => (int)$row['RoleId'],
					'Name'        => $row['Name'],
					'DisplayName' => $row['DisplayName'],
					'Description' => (string)($row['Description'] ?? ''),
				];
			}
		}
		echo json_encode(['status' => 0, 'data' => $roles]);
	}

	private function actionPermissions() {
		// Kingdom-scope-applicable permissions for the custom-permission-set builder.
		$out = [];
		$all = PermissionRegistry::GetAll(); // key => [display_name, description, scope_type, category]
		foreach ($all as $key => $def) {
			if (($def[2] ?? '') !== 'kingdom') {
				continue;
			}
			$out[] = [
				'Key'         => $key,
				'DisplayName' => $def[0] ?? $key,
				'Category'    => $def[3] ?? '',
			];
		}
		echo json_encode(['status' => 0, 'data' => $out]);
	}
}

?>
