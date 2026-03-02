<?php

class Controller_SearchAjax extends Controller {

	public function universal($p = null) {
		header('Content-Type: application/json');

		$q = trim($_GET['q'] ?? '');
		if (strlen($q) < 2) {
			echo json_encode(['players' => [], 'parks' => [], 'kingdoms' => [], 'units' => []]);
			exit;
		}

		$kid  = (int)($_GET['kid'] ?? 0);
		$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);

		global $DB;

		// Per-category budgets; unused slots from each category roll to players
		$playerBudget  = 4;
		$parkBudget    = 3;
		$kingdomBudget = 2;
		$unitBudget    = 3;

		// Parks — prioritize user's kingdom first
		$parkPri = valid_id($kid) ? "CASE WHEN p.kingdom_id = {$kid} THEN 0 ELSE 1 END" : "0";
		$rs = $DB->DataSet("
			SELECT p.park_id, p.name, k.abbreviation AS k_abbr
			FROM ork_park p
			LEFT JOIN ork_kingdom k ON k.kingdom_id = p.kingdom_id
			WHERE p.active = 'Active'
			  AND (p.name LIKE '%{$term}%' OR p.abbreviation LIKE '%{$term}%')
			ORDER BY {$parkPri}, p.name
			LIMIT {$parkBudget}");
		$parks = [];
		while ($rs->Next()) {
			$parks[] = ['type' => 'park', 'id' => (int)$rs->park_id, 'name' => $rs->name, 'abbr' => $rs->k_abbr ?? ''];
		}
		$playerBudget += $parkBudget - count($parks);

		// Kingdoms
		$rs = $DB->DataSet("
			SELECT k.kingdom_id, k.name, k.abbreviation
			FROM ork_kingdom k
			WHERE k.name LIKE '%{$term}%' OR k.abbreviation LIKE '%{$term}%'
			ORDER BY k.name
			LIMIT {$kingdomBudget}");
		$kingdoms = [];
		while ($rs->Next()) {
			$kingdoms[] = ['type' => 'kingdom', 'id' => (int)$rs->kingdom_id, 'name' => $rs->name, 'abbr' => $rs->abbreviation ?? ''];
		}
		$playerBudget += $kingdomBudget - count($kingdoms);

		// Units
		$rs = $DB->DataSet("
			SELECT unit_id, name, type
			FROM ork_unit
			WHERE name LIKE '%{$term}%'
			ORDER BY name
			LIMIT {$unitBudget}");
		$units = [];
		while ($rs->Next()) {
			$units[] = ['type' => 'unit', 'id' => (int)$rs->unit_id, 'name' => $rs->name, 'unitType' => $rs->type ?? ''];
		}
		$playerBudget += $unitBudget - count($units);

		// Players — prioritize user's kingdom, with expanded budget from unused slots above
		$playerPri = valid_id($kid) ? "CASE WHEN m.kingdom_id = {$kid} THEN 0 ELSE 1 END" : "0";
		$rs = $DB->DataSet("
			SELECT m.mundane_id, m.persona, k.abbreviation AS k_abbr, p.name AS park_name
			FROM ork_mundane m
			LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id
			LEFT JOIN ork_park p ON p.park_id = m.park_id
			WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
			  AND (m.persona LIKE '%{$term}%'
			    OR m.given_name LIKE '%{$term}%'
			    OR m.surname LIKE '%{$term}%'
			    OR m.username LIKE '%{$term}%')
			ORDER BY {$playerPri}, m.persona
			LIMIT {$playerBudget}");
		$players = [];
		while ($rs->Next()) {
			$players[] = [
				'type' => 'player',
				'id'   => (int)$rs->mundane_id,
				'name' => $rs->persona,
				'abbr' => $rs->k_abbr ?? '',
				'park' => $rs->park_name ?? '',
			];
		}

		echo json_encode(['players' => $players, 'parks' => $parks, 'kingdoms' => $kingdoms, 'units' => $units]);
		exit;
	}
}
