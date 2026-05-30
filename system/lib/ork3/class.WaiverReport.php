<?php

/*************************************************************************

The WaiverReport class.

Read-only aggregate + roster reporting over digital waiver signatures.
All SQL for the Reports/waivers compliance report lives here. Auth is
enforced by the controller before any of these methods are called.

*************************************************************************/

class WaiverReport extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->signature = new yapo($this->db, DB_PREFIX . 'waiver_signature');
		$this->template  = new yapo($this->db, DB_PREFIX . 'waiver_template');
		$this->mundane   = new yapo($this->db, DB_PREFIX . 'mundane');
		$this->park      = new yapo($this->db, DB_PREFIX . 'park');
		$this->kingdom   = new yapo($this->db, DB_PREFIX . 'kingdom');
	}

/* Resolve the kingdom_id for a given scope entity (used to look up the
	 * active template, which is always keyed on kingdom_id). */
	private function _resolve_kingdom_id($type, $entity_id) {
		$entity_id = (int)$entity_id;
		if ($type === 'Park') {
			$this->db->Clear();
			$this->db->park_id = $entity_id;
			$r = $this->db->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = :park_id LIMIT 1");
			if ($r && $r->Size() > 0 && $r->Next()) return (int)$r->kingdom_id;
			return 0;
		}
		return $entity_id;
	}

	/**
	 * Aggregate counts + compliance inputs for the stat cards.
	 * Returns a flat associative array.
	 */
	public function GetStats($type, $entity_id) {
		$entity_id  = (int)$entity_id;
		$kingdom_id = $this->_resolve_kingdom_id($type, $entity_id);
		$scope_name = ($type === 'Park') ? 'park' : 'kingdom';
		$snap_col   = ($type === 'Park') ? 'park_id_snapshot' : 'kingdom_id_snapshot';

		$out = array(
			'pending_active'       => 0,
			'stale'                => 0,
			'verified'             => 0,
			'rejected'             => 0,
			'superseded'           => 0,
			'total'                => 0,
			'avg_days_pending'     => 0,
			'minor_guardian_count' => 0,
			'unsigned'             => 0,
		);

		// ── Aggregate over signatures in scope ─────────────────────
		$this->db->Clear();
		$this->db->scope_id = $entity_id;
		$this->db->scope    = $scope_name;
		$r = $this->db->DataSet(
			"SELECT
				SUM(CASE WHEN s.verification_status='pending'   AND t.is_active=1 THEN 1 ELSE 0 END) AS pending_active,
				SUM(CASE WHEN s.verification_status='pending'   AND t.is_active=0 THEN 1 ELSE 0 END) AS stale,
				SUM(CASE WHEN s.verification_status='verified'   THEN 1 ELSE 0 END) AS verified,
				SUM(CASE WHEN s.verification_status='rejected'   THEN 1 ELSE 0 END) AS rejected,
				SUM(CASE WHEN s.verification_status='superseded' THEN 1 ELSE 0 END) AS superseded,
				COUNT(*) AS total,
				AVG(CASE WHEN s.verification_status='pending' THEN DATEDIFF(NOW(), s.signed_at) ELSE NULL END) AS avg_days_pending,
				SUM(CASE WHEN s.is_minor=1 THEN 1 ELSE 0 END) AS minor_guardian_count
			 FROM " . DB_PREFIX . "waiver_signature s
			 JOIN " . DB_PREFIX . "waiver_template t ON t.waiver_template_id = s.waiver_template_id
			 WHERE s." . $snap_col . " = :scope_id
			   AND t.scope = :scope"
		);
		if ($r && $r->Size() > 0 && $r->Next()) {
			$out['pending_active']       = (int)$r->pending_active;
			$out['stale']                = (int)$r->stale;
			$out['verified']             = (int)$r->verified;
			$out['rejected']             = (int)$r->rejected;
			$out['superseded']           = (int)$r->superseded;
			$out['total']                = (int)$r->total;
			$out['avg_days_pending']     = $r->avg_days_pending !== null ? round((float)$r->avg_days_pending, 1) : 0;
			$out['minor_guardian_count'] = (int)$r->minor_guardian_count;
		}

		// ── Unsigned: active members in scope with no pending/verified
		//    signature against the active template ──────────────────
		if ($type === 'Park') {
			$member_clause = "m.park_id = :scope_id";
		} else {
			$member_clause = "m.park_id IN (SELECT park_id FROM " . DB_PREFIX . "park WHERE kingdom_id = :scope_id)";
		}

		$this->db->Clear();
		$this->db->scope_id = $entity_id;
		$this->db->kid      = $kingdom_id;
		$this->db->scope    = $scope_name;
		$r2 = $this->db->DataSet(
			"SELECT COUNT(*) AS unsigned_ct
			 FROM " . DB_PREFIX . "mundane m
			 WHERE m.active = 1
			   AND " . $member_clause . "
			   AND m.mundane_id NOT IN (
				   SELECT s2.mundane_id
				   FROM " . DB_PREFIX . "waiver_signature s2
				   JOIN " . DB_PREFIX . "waiver_template t2 ON t2.waiver_template_id = s2.waiver_template_id
				   WHERE t2.is_active = 1
				     AND t2.kingdom_id = :kid
				     AND t2.scope = :scope
				     AND s2.verification_status IN ('pending','verified')
			   )"
		);
		if ($r2 && $r2->Size() > 0 && $r2->Next()) {
			$out['unsigned'] = (int)$r2->unsigned_ct;
		}

		return $out;
	}

	/**
	 * Per-player waiver status. One row per active member in scope; the
	 * latest signature against the active template is LEFT-joined so
	 * members with no signature surface as Unsigned (status null).
	 */
	public function GetPlayerStatusList($type, $entity_id) {
		$entity_id  = (int)$entity_id;
		$kingdom_id = $this->_resolve_kingdom_id($type, $entity_id);
		$scope_name = ($type === 'Park') ? 'park' : 'kingdom';

		if ($type === 'Park') {
			$member_clause = "m.park_id = :scope_id";
		} else {
			$member_clause = "m.park_id IN (SELECT park_id FROM " . DB_PREFIX . "park WHERE kingdom_id = :scope_id)";
		}

		$this->db->Clear();
		$this->db->scope_id = $entity_id;
		$this->db->kid      = $kingdom_id;
		$this->db->scope    = $scope_name;
		$r = $this->db->DataSet(
			"SELECT
				m.mundane_id, m.persona, m.given_name, m.surname,
				p.name AS park_name, p.park_id,
				s.verification_status, s.signed_at, s.verified_at, s.waiver_signature_id,
				s.is_minor,
				t.version AS template_version, t.scope AS template_scope, t.is_active AS template_active,
				CASE WHEN s.verification_status='pending' THEN DATEDIFF(NOW(), s.signed_at) ELSE NULL END AS days_waiting
			 FROM " . DB_PREFIX . "mundane m
			 JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
			 LEFT JOIN " . DB_PREFIX . "waiver_signature s
				ON s.mundane_id = m.mundane_id
				AND s.waiver_signature_id = (
					SELECT s3.waiver_signature_id
					FROM " . DB_PREFIX . "waiver_signature s3
					JOIN " . DB_PREFIX . "waiver_template t3 ON t3.waiver_template_id = s3.waiver_template_id
					WHERE s3.mundane_id = m.mundane_id
					  AND t3.is_active = 1
					  AND t3.kingdom_id = :kid
					  AND t3.scope = :scope
					ORDER BY s3.signed_at DESC
					LIMIT 1
				)
			 LEFT JOIN " . DB_PREFIX . "waiver_template t ON t.waiver_template_id = s.waiver_template_id
			 WHERE m.active = 1
			   AND " . $member_clause . "
			 ORDER BY s.signed_at DESC, m.persona ASC"
		);

		$rows = array();
		if ($r && $r->Size() > 0) {
			while ($r->Next()) {
				$rows[] = array(
					'MundaneId'       => (int)$r->mundane_id,
					'Persona'         => $r->persona,
					'GivenName'       => $r->given_name,
					'Surname'         => $r->surname,
					'ParkName'        => $r->park_name,
					'ParkId'          => (int)$r->park_id,
					'Status'          => $r->verification_status, // null = unsigned
					'SignedAt'        => $r->signed_at,
					'VerifiedAt'      => $r->verified_at,
					'SignatureId'     => $r->waiver_signature_id !== null ? (int)$r->waiver_signature_id : null,
					'IsMinor'         => (int)$r->is_minor,
					'TemplateVersion' => $r->template_version !== null ? (int)$r->template_version : null,
					'TemplateScope'   => $r->template_scope,
					'TemplateActive'  => $r->template_active !== null ? (int)$r->template_active : null,
					'DaysWaiting'     => $r->days_waiting !== null ? (int)$r->days_waiting : null,
				);
			}
		}
		return $rows;
	}

	/**
	 * Monthly signature counts for the trend chart (oldest → newest).
	 * Returns array of array('month'=>'YYYY-MM','count'=>int).
	 */
	public function GetMonthlySeries($type, $entity_id, $months = 12) {
		$entity_id = (int)$entity_id;
		$months    = max(1, min(60, (int)$months));
		$snap_col  = ($type === 'Park') ? 'park_id_snapshot' : 'kingdom_id_snapshot';

		$this->db->Clear();
		$this->db->scope_id = $entity_id;
		$this->db->months   = $months;
		$r = $this->db->DataSet(
			"SELECT DATE_FORMAT(signed_at, '%Y-%m') AS month, COUNT(*) AS count
			 FROM " . DB_PREFIX . "waiver_signature
			 WHERE " . $snap_col . " = :scope_id
			   AND signed_at >= DATE_SUB(NOW(), INTERVAL :months MONTH)
			 GROUP BY DATE_FORMAT(signed_at, '%Y-%m')
			 ORDER BY month ASC"
		);

		$rows = array();
		if ($r && $r->Size() > 0) {
			while ($r->Next()) {
				$rows[] = array('month' => $r->month, 'count' => (int)$r->count);
			}
		}
		return $rows;
	}

	/**
	 * Version history for a (kingdom_id, scope) template chain. Newest first.
	 * Parks share their kingdom's park-scope chain; templates are always
	 * keyed on kingdom_id.
	 */
	public function GetVersionHistory($kingdom_id, $scope) {
		$kingdom_id = (int)$kingdom_id;
		$scope      = in_array($scope, array('kingdom', 'park')) ? $scope : 'kingdom';

		$this->db->Clear();
		$this->db->kingdom_id = $kingdom_id;
		$this->db->scope      = $scope;
		$r = $this->db->DataSet(
			"SELECT
				t.waiver_template_id, t.version, t.version_name, t.change_reason,
				t.is_active, t.is_enabled, t.created_at, t.created_by_mundane_id,
				m.persona, m.given_name, m.surname
			 FROM " . DB_PREFIX . "waiver_template t
			 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = t.created_by_mundane_id
			 WHERE t.kingdom_id = :kingdom_id
			   AND t.scope = :scope
			 ORDER BY t.version DESC"
		);

		$rows = array();
		if ($r && $r->Size() > 0) {
			while ($r->Next()) {
				$created_by_id = (int)$r->created_by_mundane_id;
				if ($r->persona !== null && trim($r->persona) !== '') {
					$created_by_name = $r->persona;
				} else {
					$created_by_name = trim($r->given_name . ' ' . $r->surname);
					if ($created_by_name === '') {
						$created_by_name = '#' . $created_by_id;
					}
				}
				$rows[] = array(
					'TemplateId'         => (int)$r->waiver_template_id,
					'Version'            => (int)$r->version,
					'VersionName'        => $r->version_name,
					'ChangeReason'       => $r->change_reason !== null ? $r->change_reason : '',
					'IsActive'           => (int)$r->is_active,
					'IsEnabled'          => (int)$r->is_enabled,
					'CreatedAt'          => $r->created_at,
					'CreatedByMundaneId' => $created_by_id,
					'CreatedByName'      => $created_by_name,
				);
			}
		}
		return $rows;
	}

}
