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

		// Typographic punctuation/whitespace variants the DB collation does NOT treat as equal
		// to their ASCII forms, so a normal-keyboard search misses names that store them (e.g.
		// "Wolf’s Run" with U+2019). Accented letters are already matched by utf8mb4_unicode_ci.
		$punctFolds = [
			"\u{2019}" => "'", "\u{2018}" => "'",   // ’ ‘ → '
			"\u{201C}" => '"', "\u{201D}" => '"',   // “ ” → "
			"\u{2014}" => '-', "\u{2013}" => '-',   // — – → -
			"\u{00A0}" => ' ',                       // no-break space → space
			"\u{02DC}" => '~',                       // ˜ → ~
		];
		// Normalize the user's typed/pasted query, then provide a SQL expression that folds a
		// column the same way so both sides of every LIKE compare in normalized ASCII form.
		$searchQ  = strtr($searchQ, $punctFolds);
		$sqlLit   = fn($s) => "'" . str_replace("'", "''", $s) . "'";
		$foldText = function ($col) use ($punctFolds, $sqlLit) {
			foreach ($punctFolds as $from => $to) {
				$col = "REPLACE({$col}, " . $sqlLit($from) . ", " . $sqlLit($to) . ")";
			}
			return $col;
		};

		$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $searchQ);

		// Per-category budgets; unused slots from each category roll to players
		// Optional ?focus=type gives that type 10 results and zeros the rest (for single-type admin searches)
		$focus = trim($_GET['focus'] ?? '');
		$playerBudget  = $focus === 'player'  ? 10 : ($focus ? 0 : 4);
		$parkBudget    = $focus === 'park'    ? 10 : ($focus ? 0 : 3);
		$kingdomBudget = $focus === 'kingdom' ? 10 : ($focus ? 0 : 2);
		$unitBudget    = $focus === 'unit'    ? 10 : ($focus ? 0 : 3);

		// Parks — prioritize user's kingdom first; narrow by abbreviation prefix if provided
		$parkWhere = "p.active = 'Active' AND (" . $foldText('p.name') . " LIKE '%{$term}%' OR p.abbreviation LIKE '%{$term}%')";
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
			$kingdomWhere = $foldText('k.name') . " LIKE '%{$term}%' OR k.abbreviation LIKE '%{$term}%'";
			$rs = $DB->DataSet("
				SELECT k.kingdom_id, k.name, k.abbreviation
				FROM ork_kingdom k
				WHERE {$kingdomWhere}
				ORDER BY k.name
				LIMIT {$kingdomBudget}");
			while ($rs->Next()) {
				$kingdoms[] = ['type' => 'kingdom', 'id' => (int)$rs->kingdom_id, 'name' => $rs->name, 'abbr' => $rs->abbreviation ?? ''];
			}
		}
		$playerBudget += $kingdomBudget - count($kingdoms);

		// Units
		$unitWhere = $foldText('name') . " LIKE '%{$term}%'";
		$rs = $DB->DataSet("
			SELECT unit_id, name, type
			FROM ork_unit
			WHERE {$unitWhere}
			ORDER BY name
			LIMIT {$unitBudget}");
		$units = [];
		while ($rs->Next()) {
			$units[] = ['type' => 'unit', 'id' => (int)$rs->unit_id, 'name' => $rs->name, 'unitType' => $rs->type ?? ''];
		}
		$playerBudget += $unitBudget - count($units);

		// Players — delegate to SearchService::RankedPlayers for consistent concentric-ring ranking
		// q is passed as-is; RankedPlayers::resolveAbbrevPrefix handles "KD:PK term" internally
		$_svc  = new SearchService();
		$_rows = $_svc->RankedPlayers(
			$q,
			($pid > 0 ? $pid : null),
			($kid > 0 ? $kid : null),
			null,
			$includeInactive ?: null,
			null,
			$playerBudget,
			$this->session->token ?? null
		);
		$players = [];
		foreach ($_rows as $_r) {
			$players[] = [
				'type'   => 'player',
				'id'     => $_r['MundaneId'],
				'name'   => $_r['Persona'],
				'abbr'   => $_r['KAbbr'] ?? '',
				'park'   => $_r['ParkName'] ?? '',
				'active' => $_r['Active'],
			];
		}

		echo json_encode(['players' => $players, 'parks' => $parks, 'kingdoms' => $kingdoms, 'units' => $units]);
		exit;
	}

	public function players($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) { echo json_encode([]); exit; }
		$svc = new SearchService();
		$rows = $svc->RankedPlayers(
			$_GET['q']                       ?? '',
			(int)($_GET['parkId']            ?? 0) ?: null,
			(int)($_GET['kingdomId']         ?? 0) ?: null,
			$_GET['restrictTo']              ?? '',
			!empty($_GET['include_inactive'])  ?: null,
			!empty($_GET['include_suspended']) ?: null,
			(int)($_GET['limit']             ?? 15) ?: null,
			$this->session->token            ?? null
		);
		echo json_encode($rows);
		exit;
	}
}
