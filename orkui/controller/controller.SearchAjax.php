<?php

class Controller_SearchAjax extends Controller {

	public function universal($p = null) {
		header('Content-Type: application/json');

		$q = trim($_GET['q'] ?? '');
		if (strlen($q) < 2) {
			echo json_encode(['players' => [], 'parks' => [], 'kingdoms' => [], 'units' => []]);
			exit;
		}

		$kid            = (int)($_GET['kid'] ?? 0);
		$pid            = (int)($_GET['pid'] ?? 0);
		$includeInactive = !empty($_GET['inactive']);

		global $DB;

		// Parse optional "KD:PK search term" prefix (same pattern as SearchService::magic_search)
		// to scope results to a specific kingdom and/or park by abbreviation.
		// Examples: "KD: Aragon"  →  players/parks in kingdom KD named Aragon
		//           "KD:PK Aragon" →  players in park PK of kingdom KD
		$filterKid = 0;
		$filterPid = 0;
		$searchQ   = $q;
		if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\*)?\s+(.+)$/i', $q, $m)) {
			$kAbbr = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $m[1]);
			$rs = $DB->DataSet("SELECT kingdom_id FROM ork_kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
			if ($rs->Next()) {
				$filterKid = (int)$rs->kingdom_id;
			}
			if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
				$pAbbr = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $m[2]);
				$rs = $DB->DataSet("SELECT park_id FROM ork_park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
				if ($rs->Next()) {
					$filterPid = (int)$rs->park_id;
				}
			}
			$searchQ = trim($m[3]);
		}

		$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $searchQ);

		// Per-category budgets; unused slots from each category roll to players
		// Optional ?focus=type gives that type 10 results and zeros the rest (for single-type admin searches)
		$focus = trim($_GET['focus'] ?? '');
		$playerBudget  = $focus === 'player'  ? 10 : ($focus ? 0 : 4);
		$parkBudget    = $focus === 'park'    ? 10 : ($focus ? 0 : 3);
		$kingdomBudget = $focus === 'kingdom' ? 10 : ($focus ? 0 : 2);
		$unitBudget    = $focus === 'unit'    ? 10 : ($focus ? 0 : 3);

		// Parks — prioritize user's kingdom first; narrow by abbreviation prefix if provided
		$parkWhere = "p.active = 'Active' AND (p.name LIKE '%{$term}%' OR p.abbreviation LIKE '%{$term}%')";
		if ($filterPid > 0)           { $parkWhere .= " AND p.park_id = {$filterPid}"; }
		elseif ($filterKid > 0)       { $parkWhere .= " AND p.kingdom_id = {$filterKid}"; }
		$parkOrder = valid_id($pid)
			? "CASE WHEN p.park_id = {$pid} THEN 0 WHEN p.kingdom_id = {$kid} THEN 1 ELSE 2 END, p.name"
			: (valid_id($kid) ? "CASE WHEN p.kingdom_id = {$kid} THEN 0 ELSE 1 END, p.name" : "p.name");
		$rs = $DB->DataSet("
			SELECT p.park_id, p.name, k.abbreviation AS k_abbr, k.name AS k_name, k.kingdom_id
			FROM ork_park p
			LEFT JOIN ork_kingdom k ON k.kingdom_id = p.kingdom_id
			WHERE {$parkWhere}
			ORDER BY {$parkOrder}
			LIMIT {$parkBudget}");
		$parks = [];
		while ($rs->Next()) {
			$parks[] = ['type' => 'park', 'id' => (int)$rs->park_id, 'name' => $rs->name, 'abbr' => $rs->k_abbr ?? '', 'kingdom' => $rs->k_name ?? '', 'kingdom_id' => (int)$rs->kingdom_id];
		}
		$playerBudget += $parkBudget - count($parks);

		// Kingdoms — skip if scoped to a specific kingdom/park by abbreviation prefix
		$kingdoms = [];
		if ($filterKid === 0) {
			$rs = $DB->DataSet("
				SELECT k.kingdom_id, k.name, k.abbreviation
				FROM ork_kingdom k
				WHERE k.name LIKE '%{$term}%' OR k.abbreviation LIKE '%{$term}%'
				ORDER BY k.name
				LIMIT {$kingdomBudget}");
			while ($rs->Next()) {
				$kingdoms[] = ['type' => 'kingdom', 'id' => (int)$rs->kingdom_id, 'name' => $rs->name, 'abbr' => $rs->abbreviation ?? ''];
			}
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

		// Players — prioritize user's kingdom, with expanded budget from unused slots above;
		// narrow by kingdom/park if abbreviation prefix was parsed
		$activeClause    = $includeInactive ? '1' : 'm.active = 1';
		$suspendedClause = $includeInactive ? '1' : 'm.suspended = 0';
		$playerWhere = "{$suspendedClause} AND {$activeClause} AND LENGTH(m.persona) > 0
			  AND (m.persona LIKE '%{$term}%'
			    OR m.username LIKE '%{$term}%'
			    OR (m.restricted = 0 AND (m.given_name LIKE '%{$term}%' OR m.surname LIKE '%{$term}%')))";
		if ($filterPid > 0)           { $playerWhere .= " AND m.park_id = {$filterPid}"; }
		elseif ($filterKid > 0)       { $playerWhere .= " AND m.kingdom_id = {$filterKid}"; }
		$playerOrder = valid_id($pid)
			? "m.active DESC, CASE WHEN m.park_id = {$pid} THEN 0 WHEN m.kingdom_id = {$kid} THEN 1 ELSE 2 END, m.persona"
			: (valid_id($kid) ? "m.active DESC, CASE WHEN m.kingdom_id = {$kid} THEN 0 ELSE 1 END, m.persona" : "m.active DESC, m.persona");
		$rs = $DB->DataSet("
			SELECT m.mundane_id, m.persona, m.active, k.abbreviation AS k_abbr, p.name AS park_name
			FROM ork_mundane m
			LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id
			LEFT JOIN ork_park p ON p.park_id = m.park_id
			WHERE {$playerWhere}
			ORDER BY {$playerOrder}
			LIMIT {$playerBudget}");
		$players = [];
		while ($rs->Next()) {
			$players[] = [
				'type'   => 'player',
				'id'     => (int)$rs->mundane_id,
				'name'   => $rs->persona,
				'abbr'   => $rs->k_abbr ?? '',
				'park'   => $rs->park_name ?? '',
				'active' => (int)$rs->active,
			];
		}

		echo json_encode(['players' => $players, 'parks' => $parks, 'kingdoms' => $kingdoms, 'units' => $units]);
		exit;
	}
}
