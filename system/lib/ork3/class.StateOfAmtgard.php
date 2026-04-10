<?php
class StateOfAmtgard {

	/**
	 * Returns sign-in counts per kingdom for the given date range.
	 *
	 * @param string $start       Start date (YYYY-MM-DD)
	 * @param string $end         End date (YYYY-MM-DD)
	 * @param array  $kingdom_ids Optional list of integer kingdom IDs to filter by.
	 *                             Pass an empty array to include all active kingdoms.
	 * @return array Array of kingdoms, each with keys:
	 *               kingdom_id, kingdom_name, sign_in_count, percentage, rank
	 */
	public function getKingdomSignIns(string $start, string $end, array $kingdom_ids): array
	{
		global $DB;

		// Sanitize inputs
		$start = preg_replace('/[^0-9\-]/', '', $start);
		$end   = preg_replace('/[^0-9\-]/', '', $end);
		$ids   = array_map('intval', $kingdom_ids);
		$ids   = array_filter($ids, fn($id) => $id > 0);

		// Build optional kingdom filter clause
		$kingdomClause = '';
		if (!empty($ids)) {
			$kingdomClause = ' AND a.kingdom_id IN (' . implode(',', $ids) . ')';
		}

		// Compute the prior period: same length, ending the day before $start
		// e.g. if range is 2024-01-01 → 2024-12-31 (365 days), prior = 2023-01-01 → 2023-12-31
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT DATE_SUB('$start', INTERVAL 1 DAY) AS prior_end," .
			" DATE_SUB('$start', INTERVAL DATEDIFF('$end','$start') DAY) AS prior_start"
		);
		$prior_start = '';
		$prior_end   = '';
		if ($rs && $rs->Next()) {
			$prior_start = preg_replace('/[^0-9\-]/', '', (string)$rs->prior_start);
			$prior_end   = preg_replace('/[^0-9\-]/', '', (string)$rs->prior_end);
		}

		// Prior-period sign-in counts keyed by kingdom_id
		$prior_counts = [];
		if ($prior_start !== '' && $prior_end !== '') {
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT a.kingdom_id, COUNT(a.attendance_id) AS prior_count" .
				" FROM " . DB_PREFIX . "attendance a" .
				" INNER JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = a.kingdom_id" .
				" WHERE k.active = 'Active'" .
				"   AND a.date BETWEEN '$prior_start' AND '$prior_end'" .
				$kingdomClause .
				" GROUP BY a.kingdom_id"
			);
			if ($rs) {
				while ($rs->Next()) {
					$prior_counts[(int)$rs->kingdom_id] = (int)$rs->prior_count;
				}
			}
		}

		$sql = "SELECT
			k.kingdom_id,
			k.name AS kingdom_name,
			COUNT(a.attendance_id) AS sign_in_count
		FROM " . DB_PREFIX . "attendance a
		INNER JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = a.kingdom_id
		WHERE k.active = 'Active'
		  AND a.date BETWEEN '$start' AND '$end'
		  $kingdomClause
		GROUP BY k.kingdom_id, k.name
		ORDER BY sign_in_count DESC";

		$DB->Clear();
		$rs = $DB->DataSet($sql);

		$rows  = [];
		$total = 0;

		if ($rs) {
			while ($rs->Next()) {
				$count   = (int)$rs->sign_in_count;
				$total  += $count;
				$rows[]  = [
					'kingdom_id'    => (int)$rs->kingdom_id,
					'kingdom_name'  => (string)$rs->kingdom_name,
					'sign_in_count' => $count,
				];
			}
		}

		// Compute percentage, rank, and YoY change now that we have the total
		$result = [];
		$rank   = 1;
		foreach ($rows as $row) {
			$kid          = $row['kingdom_id'];
			$prior        = $prior_counts[$kid] ?? 0;
			$yoy_change   = $row['sign_in_count'] - $prior;
			$yoy_pct      = $prior > 0
				? round(($yoy_change / $prior) * 100, 1)
				: null;

			$row['percentage']          = $total > 0
				? round(($row['sign_in_count'] / $total) * 100, 1)
				: 0.0;
			$row['rank']                = $rank++;
			$row['prior_period_count']  = $prior;
			$row['yoy_change']          = $yoy_change;
			$row['yoy_pct_change']      = $yoy_pct;
			$result[]                   = $row;
		}

		return $result;
	}

	/**
	 * Returns all active kingdoms for filter dropdowns.
	 *
	 * @return array Array of kingdoms, each with keys: kingdom_id, kingdom_name
	 */
	public function getActiveKingdoms(): array
	{
		global $DB;

		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT kingdom_id, name FROM " . DB_PREFIX . "kingdom WHERE active = 'Active' ORDER BY name"
		);

		$kingdoms = [];
		if ($rs) {
			while ($rs->Next()) {
				$kingdoms[] = [
					'kingdom_id'   => (int)$rs->kingdom_id,
					'kingdom_name' => (string)$rs->name,
				];
			}
		}

		return $kingdoms;
	}

    public function getClassSignIns(string $start, string $end, array $kingdom_ids): array
    {
        global $DB;

        // H-3: sanitize dates
        $start = preg_replace('/[^0-9\-]/', '', $start);
        $end   = preg_replace('/[^0-9\-]/', '', $end);

        // Build optional kingdom filter
        $kingdom_filter = '';
        if (!empty($kingdom_ids)) {
            $safe_ids = array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0);
            if (!empty($safe_ids)) {
                $kingdom_filter = 'AND a.kingdom_id IN (' . implode(',', $safe_ids) . ')';
            }
        }
        // H-2: when no kingdom filter specified, restrict to active kingdoms only
        if (empty($kingdom_filter)) {
            $kingdom_filter = "AND a.kingdom_id IN (SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE active = 'Active')";
        }

        // Fetch per-class sign-in counts
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT c.class_id, c.name AS class_name, COUNT(*) AS sign_in_count
             FROM ' . DB_PREFIX . 'attendance a
             JOIN ' . DB_PREFIX . 'class c ON c.class_id = a.class_id
             WHERE a.date BETWEEN \'' . $start . '\' AND \'' . $end . '\'
               AND c.active = 1
               ' . $kingdom_filter . '
             GROUP BY c.class_id, c.name
             ORDER BY sign_in_count DESC'
        );
        $rows  = [];
        $total = 0;
        if ($rs) {
            while ($rs->Next()) {
                $count = (int)$rs->sign_in_count;
                $total += $count;
                $rows[] = [
                    'class_id'      => (int)$rs->class_id,
                    'class_name'    => (string)$rs->class_name,
                    'sign_in_count' => $count,
                ];
            }
        }
        if (empty($rows)) return [];

        // Build result with percentage, rank, and inflation flag
        // class_ids whose counts may be inflated (Color=6, Warrior=16)
        $inflated_ids = [6, 16];
        $result = [];
        $rank   = 1;
        foreach ($rows as $row) {
            $result[] = [
                'class_id'      => $row['class_id'],
                'class_name'    => $row['class_name'],
                'sign_in_count' => $row['sign_in_count'],
                'percentage'    => $total > 0 ? round(($row['sign_in_count'] / $total) * 100, 1) : 0.0,
                'rank'          => $rank,
                'inflated_note' => in_array($row['class_id'], $inflated_ids, true),
            ];
            $rank++;
        }

        return $result;
    }

	private function spearmanCorrelation(array $values): float {
		$n = count($values);
		if ($n < 3) return 0.0;
		$sorted = $values;
		sort($sorted);
		$rankY = [];
		foreach ($values as $v) {
			$positions = array_keys($sorted, $v, true);
			$rankY[] = (array_sum($positions) / count($positions)) + 1;
		}
		$rankX = range(1, $n);
		$d2 = 0;
		for ($i = 0; $i < $n; $i++) $d2 += ($rankX[$i] - $rankY[$i]) ** 2;
		return 1 - (6 * $d2) / ($n * ($n**2 - 1));
	}

	public function getParksAnalysis(string $start, string $end, array $kingdom_ids): array {
		global $DB;

		// --- Build kingdom filter clauses ---
		$safe_kingdom_ids = array_map('intval', $kingdom_ids);
		$safe_kingdom_ids = array_filter($safe_kingdom_ids, fn($id) => $id > 0);

		if (!empty($safe_kingdom_ids)) {
			$id_list        = implode(',', $safe_kingdom_ids);
			$kingdom_clause = ' AND p.kingdom_id IN (' . $id_list . ')';
			$k_clause_bare  = ' AND k.kingdom_id IN (' . $id_list . ')';
			$a_clause_bare  = ' AND a.kingdom_id IN (' . $id_list . ')';
		} else {
			// All active kingdoms
			$kingdom_clause = " AND p.kingdom_id IN (SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE active = 'Active')";
			$k_clause_bare  = " AND k.active = 'Active'";
			$a_clause_bare  = " AND a.kingdom_id IN (SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE active = 'Active')";
		}

		$safe_start = preg_replace('/[^0-9\-]/', '', $start);
		$safe_end   = preg_replace('/[^0-9\-]/', '', $end);

		// -------------------------------------------------------
		// 1. Total active parks + avg per kingdom
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(DISTINCT p.park_id) AS total_active, COUNT(DISTINCT p.kingdom_id) AS kingdom_count" .
			" FROM " . DB_PREFIX . "park p" .
			" WHERE p.active = 'Active'" .
			$kingdom_clause
		);
		$total_active  = 0;
		$kingdom_count = 0;
		if ($rs && $rs->Next()) {
			$total_active  = (int)$rs->total_active;
			$kingdom_count = (int)$rs->kingdom_count;
		}
		$avg_per_kingdom = $kingdom_count > 0 ? round($total_active / $kingdom_count, 1) : 0;

		// -------------------------------------------------------
		// 2. New parks — first-ever attendance in range
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.park_id, p.name AS park_name, k.name AS kingdom_name" .
			" FROM " . DB_PREFIX . "attendance a" .
			" JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id" .
			" JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id" .
			" WHERE p.active = 'Active'" . $kingdom_clause .
			" GROUP BY a.park_id, p.name, k.name" .
			" HAVING MIN(a.date) BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			" ORDER BY k.name, p.name"
		);
		$new_parks          = [];
		$new_by_kingdom_map = [];
		if ($rs) while ($rs->Next()) {
			$new_parks[] = [
				'park_id'      => (int)$rs->park_id,
				'park_name'    => $rs->park_name,
				'kingdom_name' => $rs->kingdom_name,
			];
			$new_by_kingdom_map[$rs->kingdom_name] = ($new_by_kingdom_map[$rs->kingdom_name] ?? 0) + 1;
		}
		$new_by_kingdom = [];
		foreach ($new_by_kingdom_map as $kname => $cnt) {
			$new_by_kingdom[] = ['kingdom_name' => $kname, 'count' => $cnt];
		}
		usort($new_by_kingdom, fn($a, $b) => $b['count'] <=> $a['count']);
		$new_parks_count = count($new_parks);
		$new_parks = array_slice($new_parks, 0, 30);

		// -------------------------------------------------------
		// 3. Lost parks — Retired AND last attendance in range
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.park_id, p.name AS park_name, k.name AS kingdom_name" .
			" FROM " . DB_PREFIX . "attendance a" .
			" JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id" .
			" JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id" .
			" WHERE p.active = 'Retired'" .
			$kingdom_clause .
			" GROUP BY a.park_id, p.name, k.name" .
			" HAVING MAX(a.date) BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			" ORDER BY k.name, p.name"
		);
		$lost_parks          = [];
		$lost_by_kingdom_map = [];
		if ($rs) while ($rs->Next()) {
			$lost_parks[] = [
				'park_id'      => (int)$rs->park_id,
				'park_name'    => $rs->park_name,
				'kingdom_name' => $rs->kingdom_name,
			];
			$lost_by_kingdom_map[$rs->kingdom_name] = ($lost_by_kingdom_map[$rs->kingdom_name] ?? 0) + 1;
		}
		$lost_by_kingdom = [];
		foreach ($lost_by_kingdom_map as $kname => $cnt) {
			$lost_by_kingdom[] = ['kingdom_name' => $kname, 'count' => $cnt];
		}
		usort($lost_by_kingdom, fn($a, $b) => $b['count'] <=> $a['count']);
		$lost_parks_count = count($lost_parks);
		$lost_parks = array_slice($lost_parks, 0, 30);

		// -------------------------------------------------------
		// 4. Downward trend — Spearman r < -0.5, last year <= 20
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.park_id, p.name AS park_name, k.name AS kingdom_name," .
			" YEAR(a.date) AS yr, COUNT(*) AS cnt" .
			" FROM " . DB_PREFIX . "attendance a" .
			" JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id" .
			" JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id" .
			" WHERE a.date >= DATE_SUB('" . $safe_end . "', INTERVAL 5 YEAR)" .
			"   AND a.date <= '" . $safe_end . "'" .
			$kingdom_clause .
			" GROUP BY a.park_id, p.name, k.name, YEAR(a.date)" .
			" ORDER BY a.park_id, yr ASC"
		);

		// Group by park
		$park_years = [];
		if ($rs) while ($rs->Next()) {
			$pid = (int)$rs->park_id;
			if (!isset($park_years[$pid])) {
				$park_years[$pid] = [
					'park_name'    => $rs->park_name,
					'kingdom_name' => $rs->kingdom_name,
					'years'        => [],
				];
			}
			$park_years[$pid]['years'][(int)$rs->yr] = (int)$rs->cnt;
		}

		$downward_trend_parks = [];
		foreach ($park_years as $pid => $info) {
			$years = $info['years'];
			ksort($years);
			if (count($years) < 3) continue;

			$counts          = array_values($years);
			$last_year_count = end($counts);
			$r               = $this->spearmanCorrelation($counts);

			if ($r < -0.5 && $last_year_count <= 20) {
					$trend_pts = [];
					foreach (array_keys($years) as $ki => $yr) {
						$trend_pts[] = ['year' => (int)$yr, 'count' => (int)array_values($years)[$ki]];
					}
				$downward_trend_parks[] = [
					'park_name'       => $info['park_name'],
					'kingdom_name'    => $info['kingdom_name'],
					'last_year_count' => $last_year_count,
					'spearman_r'      => round($r, 4),
					'trend_years'     => $trend_pts,
				];
			}
		}
		usort($downward_trend_parks, fn($a, $b) => $a['spearman_r'] <=> $b['spearman_r']);
		$downward_trend_parks = array_slice($downward_trend_parks, 0, 15);

		// -------------------------------------------------------
		// 5. Parks by kingdom — active/retired counts + ratio
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT k.kingdom_id, k.name AS kingdom_name," .
			" SUM(CASE WHEN pk.active = 'Active'  THEN 1 ELSE 0 END) AS active_parks," .
			" SUM(CASE WHEN pk.active = 'Retired' THEN 1 ELSE 0 END) AS retired_parks" .
			" FROM " . DB_PREFIX . "kingdom k" .
			" LEFT JOIN " . DB_PREFIX . "park pk ON pk.kingdom_id = k.kingdom_id" .
			" WHERE k.active = 'Active'" .
			$k_clause_bare .
			" GROUP BY k.kingdom_id, k.name" .
			" ORDER BY k.name ASC"
		);
		$parks_by_kingdom = [];
		if ($rs) while ($rs->Next()) {
			$active  = (int)$rs->active_parks;
			$retired = (int)$rs->retired_parks;
			$ratio   = $retired > 0 ? number_format($active / $retired, 1) : 'Undefined';
			$parks_by_kingdom[] = [
				'kingdom_id'    => (int)$rs->kingdom_id,
				'kingdom_name'  => (string)$rs->kingdom_name,
				'active_parks'  => $active,
				'retired_parks' => $retired,
				'ratio'         => $ratio,
				'sign_ins_per_park' => null, // populated below
			];
		}

		// -------------------------------------------------------
		// 6. Sign-ins per active park by kingdom
		//    Requires a separate aggregation over ork_attendance so we
		//    get accurate sign-in totals without blowing up the earlier
		//    parks-summary query with a join to a 3.5M-row table.
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.kingdom_id, COUNT(a.attendance_id) AS si_count" .
			" FROM " . DB_PREFIX . "attendance a" .
			" WHERE a.date BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			$a_clause_bare .
			" GROUP BY a.kingdom_id"
		);
		$si_by_kingdom = [];
		if ($rs) {
			while ($rs->Next()) {
				$si_by_kingdom[(int)$rs->kingdom_id] = (int)$rs->si_count;
			}
		}
		foreach ($parks_by_kingdom as &$pkrow) {
			$kid        = $pkrow['kingdom_id'];
			$si         = $si_by_kingdom[$kid] ?? 0;
			$ap         = $pkrow['active_parks'];
			$pkrow['sign_ins_per_park'] = $ap > 0 ? round($si / $ap, 1) : null;
		}
		unset($pkrow);

		// -------------------------------------------------------
		// 7. Top 10 most active parks in the period
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT p.park_id, p.name AS park_name, k.name AS kingdom_name," .
			" COUNT(a.attendance_id) AS sign_in_count" .
			" FROM " . DB_PREFIX . "attendance a" .
			" INNER JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id" .
			" INNER JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id" .
			" WHERE a.date BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			$kingdom_clause .
			" GROUP BY p.park_id, p.name, k.name" .
			" ORDER BY sign_in_count DESC" .
			" LIMIT 10"
		);
		$top_parks = [];
		if ($rs) {
			while ($rs->Next()) {
				$top_parks[] = [
					'park_id'        => (int)$rs->park_id,
					'park_name'      => (string)$rs->park_name,
					'kingdom_name'   => (string)$rs->kingdom_name,
					'sign_in_count'  => (int)$rs->sign_in_count,
				];
			}
		}

		// -------------------------------------------------------
		// Assemble result
		// -------------------------------------------------------
		return [
			'total_active'         => $total_active,
			'avg_per_kingdom'      => $avg_per_kingdom,
			'new_parks'            => $new_parks,
			'new_parks_count'      => $new_parks_count,
			'new_by_kingdom'       => $new_by_kingdom,
			'lost_parks'           => $lost_parks,
			'lost_parks_count'     => $lost_parks_count,
			'lost_by_kingdom'      => $lost_by_kingdom,
			'downward_trend_parks' => $downward_trend_parks,
			'parks_by_kingdom'     => $parks_by_kingdom,
			'top_parks'            => $top_parks,
		];
	}


	public function getPlayerStats(string $start, string $end, array $kingdom_ids): array {
		global $DB;

		// Sanitize inputs (no $DB->Escape — use regex like getParksAnalysis)
		$start = preg_replace('/[^0-9\-]/', '', $start);
		$end   = preg_replace('/[^0-9\-]/', '', $end);

		$kingdom_filter = '';
		if (!empty($kingdom_ids)) {
			$safe_ids = array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0);
			if (!empty($safe_ids)) {
				$kingdom_filter = 'AND kingdom_id IN (' . implode(',', $safe_ids) . ')';
			}
		}

		// days_in_range for avg_credits_per_week
		$DB->Clear();
		$rs = $DB->DataSet("SELECT DATEDIFF('$end', '$start') + 1 AS days_in_range");
		$days_in_range = 365.0;
		if ($rs && $rs->Next()) { $days_in_range = (float)$rs->days_in_range; }
		if ($days_in_range <= 0) $days_in_range = 365.0;
		$weeks_in_range = $days_in_range / 7.0;

		// ----- ALL PLAYERS (selected range) -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt) AS total_si,
			       AVG(cnt) AS avg_si, STDDEV_SAMP(cnt) AS std_si,
			       MIN(cnt) AS min_si, MAX(cnt) AS max_si, AVG(cred) AS avg_cred
			FROM (
				SELECT mundane_id, COUNT(*) AS cnt, SUM(credits) AS cred
				FROM " . DB_PREFIX . "attendance
				WHERE date BETWEEN '$start' AND '$end' $kingdom_filter
				GROUP BY mundane_id
			) per_player
		");
		$total_sign_ins = $total_players = $min_sign_ins = $max_sign_ins = 0;
		$avg_sign_ins = $std_dev_sign_ins = $avg_credits = 0.0;
		if ($rs && $rs->Next()) {
			$total_players    = (int)$rs->player_count;
			$total_sign_ins   = (int)$rs->total_si;
			$avg_sign_ins     = (float)$rs->avg_si;
			$std_dev_sign_ins = (float)$rs->std_si;
			$min_sign_ins     = (int)$rs->min_si;
			$max_sign_ins     = (int)$rs->max_si;
			$avg_credits      = (float)$rs->avg_cred;
		}
		$avg_credits_per_week = $avg_credits / $weeks_in_range;

		// ----- NORMAL PLAYERS (>= 4 sign-ins AND >= 12 credits) -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt) AS total_si,
			       AVG(cnt) AS avg_si, STDDEV_SAMP(cnt) AS std_si,
			       MIN(cnt) AS min_si, MAX(cnt) AS max_si, AVG(cred) AS avg_cred
			FROM (
				SELECT mundane_id, COUNT(*) AS cnt, SUM(credits) AS cred
				FROM " . DB_PREFIX . "attendance
				WHERE date BETWEEN '$start' AND '$end' $kingdom_filter
				GROUP BY mundane_id
				HAVING cnt >= 4 AND cred >= 12
			) per_player
		");
		$norm_count = $norm_total_si = $norm_min_si = $norm_max_si = 0;
		$norm_avg_si = $norm_std_si = $norm_avg_cred = 0.0;
		if ($rs && $rs->Next()) {
			$norm_count    = (int)$rs->player_count;
			$norm_total_si = (int)$rs->total_si;
			$norm_avg_si   = (float)$rs->avg_si;
			$norm_std_si   = (float)$rs->std_si;
			$norm_min_si   = (int)$rs->min_si;
			$norm_max_si   = (int)$rs->max_si;
			$norm_avg_cred = (float)$rs->avg_cred;
		}
		$norm_avg_cred_per_week = $norm_avg_cred / $weeks_in_range;

		// ----- TREND BY YEAR (10 years ending at $end) -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT YEAR(date) AS yr, COUNT(*) AS cnt
			FROM " . DB_PREFIX . "attendance
			WHERE date >= DATE_FORMAT(DATE_SUB('$end', INTERVAL 9 YEAR), '%Y-01-01')
			  AND date <= '$end'
			  $kingdom_filter
			GROUP BY YEAR(date) ORDER BY yr
		");
		$trend_by_year = [];
		if ($rs) { while ($rs->Next()) { $trend_by_year[] = ['year' => (int)$rs->yr, 'sign_ins' => (int)$rs->cnt]; } }

		// ----- TREND BY YEAR — NORMAL PLAYERS (10 years ending at $end) -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT yr, COUNT(*) AS cnt
			FROM (
				SELECT YEAR(date) AS yr, mundane_id
				FROM " . DB_PREFIX . "attendance
				WHERE date >= DATE_FORMAT(DATE_SUB('$end', INTERVAL 9 YEAR), '%Y-01-01')
				  AND date <= '$end'
				  $kingdom_filter
				GROUP BY YEAR(date), mundane_id
				HAVING COUNT(*) >= 4 AND SUM(credits) >= 12
			) norm
			GROUP BY yr ORDER BY yr
		");
		$trend_normal_by_year = [];
		if ($rs) { while ($rs->Next()) { $trend_normal_by_year[] = ['year' => (int)$rs->yr, 'normal_players' => (int)$rs->cnt]; } }

		// ----- TEN-YEAR AGGREGATE -----
		$ten_year_start_sql = "DATE_FORMAT(DATE_SUB('$end', INTERVAL 9 YEAR), '%Y-01-01')";

		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt) AS total_si,
			       AVG(cnt) AS avg_si, STDDEV_SAMP(cnt) AS std_si,
			       MIN(cnt) AS min_si, MAX(cnt) AS max_si, AVG(cred) AS avg_cred
			FROM (
				SELECT mundane_id, COUNT(*) AS cnt, SUM(credits) AS cred
				FROM " . DB_PREFIX . "attendance
				WHERE date >= $ten_year_start_sql AND date <= '$end' $kingdom_filter
				GROUP BY mundane_id
			) per_player
		");
		$ten_total_si = $ten_players = $ten_min_si = $ten_max_si = 0;
		$ten_avg_si = $ten_std_si = $ten_avg_cred = 0.0;
		if ($rs && $rs->Next()) {
			$ten_players   = (int)$rs->player_count;
			$ten_total_si  = (int)$rs->total_si;
			$ten_avg_si    = (float)$rs->avg_si;
			$ten_std_si    = (float)$rs->std_si;
			$ten_min_si    = (int)$rs->min_si;
			$ten_max_si    = (int)$rs->max_si;
			$ten_avg_cred  = (float)$rs->avg_cred;
		}

		// avg_lifespan (all players, 10-year)
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT AVG(distinct_years) AS avg_lifespan FROM (
				SELECT mundane_id, COUNT(DISTINCT YEAR(date)) AS distinct_years
				FROM " . DB_PREFIX . "attendance
				WHERE date >= $ten_year_start_sql AND date <= '$end' $kingdom_filter
				GROUP BY mundane_id
			) per_player_years
		");
		$ten_avg_lifespan = 0.0;
		if ($rs && $rs->Next()) { $ten_avg_lifespan = (float)$rs->avg_lifespan; }

		// Normal players (10-year)
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt) AS total_si,
			       AVG(cnt) AS avg_si, STDDEV_SAMP(cnt) AS std_si,
			       MIN(cnt) AS min_si, MAX(cnt) AS max_si, AVG(cred) AS avg_cred
			FROM (
				SELECT mundane_id, COUNT(*) AS cnt, SUM(credits) AS cred
				FROM " . DB_PREFIX . "attendance
				WHERE date >= $ten_year_start_sql AND date <= '$end' $kingdom_filter
				GROUP BY mundane_id
				HAVING cnt >= 4 AND cred >= 12
			) per_player
		");
		$ten_norm_count = $ten_norm_total_si = $ten_norm_min_si = $ten_norm_max_si = 0;
		$ten_norm_avg_si = $ten_norm_std_si = $ten_norm_avg_cred = 0.0;
		if ($rs && $rs->Next()) {
			$ten_norm_count    = (int)$rs->player_count;
			$ten_norm_total_si = (int)$rs->total_si;
			$ten_norm_avg_si   = (float)$rs->avg_si;
			$ten_norm_std_si   = (float)$rs->std_si;
			$ten_norm_min_si   = (int)$rs->min_si;
			$ten_norm_max_si   = (int)$rs->max_si;
			$ten_norm_avg_cred = (float)$rs->avg_cred;
		}

		// avg_lifespan (normal players, 10-year)
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT AVG(distinct_years) AS avg_lifespan FROM (
				SELECT mundane_id, COUNT(DISTINCT YEAR(date)) AS distinct_years
				FROM " . DB_PREFIX . "attendance
				WHERE date >= $ten_year_start_sql AND date <= '$end' $kingdom_filter
				GROUP BY mundane_id
				HAVING COUNT(*) >= 4 AND SUM(credits) >= 12
			) per_player_years
		");
		$ten_norm_lifespan = 0.0;
		if ($rs && $rs->Next()) { $ten_norm_lifespan = (float)$rs->avg_lifespan; }

		$ten_avg_cred_per_week      = round($ten_avg_cred / $weeks_in_range, 1);
		$ten_norm_avg_cred_per_week = round($ten_norm_avg_cred / $weeks_in_range, 1);

		return [
			'total_sign_ins'       => $total_sign_ins,
			'total_players'        => $total_players,
			'avg_sign_ins'         => round($avg_sign_ins,    1),
			'std_dev_sign_ins'     => round($std_dev_sign_ins,1),
			'min_sign_ins'         => $min_sign_ins,
			'max_sign_ins'         => $max_sign_ins,
			'avg_credits'          => round($avg_credits,     1),
			'avg_credits_per_week' => round($avg_credits_per_week, 1),
			'normal_players' => [
				'count'              => $norm_count,
				'total_sign_ins'     => $norm_total_si,
				'avg_sign_ins'       => round($norm_avg_si,   1),
				'std_dev_sign_ins'   => round($norm_std_si,   1),
				'min_sign_ins'       => $norm_min_si,
				'max_sign_ins'       => $norm_max_si,
				'avg_credits'        => round($norm_avg_cred, 1),
				'avg_credits_per_week' => round($norm_avg_cred_per_week, 1),
			],
			'trend_by_year'        => $trend_by_year,
			'trend_normal_by_year' => $trend_normal_by_year,
			'ten_year' => [
				'total_sign_ins'    => $ten_total_si,
				'total_players'     => $ten_players,
				'avg_sign_ins'      => round($ten_avg_si,   1),
				'std_dev_sign_ins'  => round($ten_std_si,   1),
				'min_sign_ins'      => $ten_min_si,
				'max_sign_ins'      => $ten_max_si,
				'avg_credits'           => round($ten_avg_cred, 1),
				'avg_credits_per_week'  => $ten_avg_cred_per_week,
				'avg_lifespan_years'    => round($ten_avg_lifespan, 1),
				'normal_players' => [
					'count'          => $ten_norm_count,
					'total_sign_ins' => $ten_norm_total_si,
					'avg_sign_ins'   => round($ten_norm_avg_si,  1),
					'std_dev_sign_ins'=> round($ten_norm_std_si, 1),
					'min_sign_ins'   => $ten_norm_min_si,
					'max_sign_ins'   => $ten_norm_max_si,
					'avg_credits'           => round($ten_norm_avg_cred,1),
					'avg_credits_per_week'  => $ten_norm_avg_cred_per_week,
					'avg_lifespan_years'    => round($ten_norm_lifespan, 1),
				],
			],
		];
	}

	/**
	 * Returns player retention / cohort counts for the given date range.
	 *
	 * @param string $start       Start date (YYYY-MM-DD)
	 * @param string $end         End date (YYYY-MM-DD)
	 * @param array  $kingdom_ids Optional list of integer kingdom IDs to filter by.
	 * @return array {
	 *   new_players       int  — mundane_ids whose first-ever attendance is within [$start, $end]
	 *   returning_players int  — mundane_ids with attendance in [$start,$end] AND at least one
	 *                            attendance record before $start
	 *   churned_players   int  — mundane_ids with attendance in the prior period (same span
	 *                            ending at $start-1day) but NO attendance in [$start,$end]
	 *   one_time_visitors int  — mundane_ids with exactly 1 sign-in in [$start,$end]
	 * }
	 */
	public function getPlayerCohorts(string $start, string $end, array $kingdom_ids): array
	{
		global $DB;

		$start = preg_replace('/[^0-9\-]/', '', $start);
		$end   = preg_replace('/[^0-9\-]/', '', $end);

		$kingdom_filter = '';
		if (!empty($kingdom_ids)) {
			$safe_ids = array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0);
			if (!empty($safe_ids)) {
				$kingdom_filter = ' AND kingdom_id IN (' . implode(',', $safe_ids) . ')';
			}
		}

		// Compute prior-period boundaries (same length, ending the day before $start)
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT DATE_SUB('$start', INTERVAL 1 DAY) AS prior_end," .
			" DATE_SUB('$start', INTERVAL DATEDIFF('$end','$start') DAY) AS prior_start"
		);
		$prior_start = '';
		$prior_end   = '';
		if ($rs && $rs->Next()) {
			$prior_start = preg_replace('/[^0-9\-]/', '', (string)$rs->prior_start);
			$prior_end   = preg_replace('/[^0-9\-]/', '', (string)$rs->prior_end);
		}

		// ── new_players ──────────────────────────────────────────────────────────
		// Players whose very first attendance record ever falls inside [$start,$end].
		// Using a subquery on the full table (no date filter on inner MIN) so we
		// correctly exclude anyone who also attended before $start.
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(*) AS cnt FROM (" .
			"  SELECT mundane_id" .
			"  FROM " . DB_PREFIX . "attendance" .
			"  WHERE 1=1 $kingdom_filter" .
			"  GROUP BY mundane_id" .
			"  HAVING MIN(date) BETWEEN '$start' AND '$end'" .
			") new_cohort"
		);
		$new_players = 0;
		if ($rs && $rs->Next()) { $new_players = (int)$rs->cnt; }

		// ── returning_players ────────────────────────────────────────────────────
		// Players with at least one sign-in in [$start,$end] AND at least one before $start.
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(DISTINCT cur.mundane_id) AS cnt" .
			" FROM " . DB_PREFIX . "attendance cur" .
			" WHERE cur.date BETWEEN '$start' AND '$end'" .
			$kingdom_filter .
			"   AND EXISTS (" .
			"     SELECT 1 FROM " . DB_PREFIX . "attendance prev" .
			"     WHERE prev.mundane_id = cur.mundane_id" .
			"       AND prev.date < '$start'" .
			"   )"
		);
		$returning_players = 0;
		if ($rs && $rs->Next()) { $returning_players = (int)$rs->cnt; }

		// ── churned_players ──────────────────────────────────────────────────────
		// Players who appeared in the prior period but have NO record in [$start,$end].
		// Only meaningful if we have a valid prior period.
		$churned_players = 0;
		if ($prior_start !== '' && $prior_end !== '') {
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT COUNT(DISTINCT prior.mundane_id) AS cnt" .
				" FROM " . DB_PREFIX . "attendance prior" .
				" WHERE prior.date BETWEEN '$prior_start' AND '$prior_end'" .
				$kingdom_filter .
				"   AND NOT EXISTS (" .
				"     SELECT 1 FROM " . DB_PREFIX . "attendance cur" .
				"     WHERE cur.mundane_id = prior.mundane_id" .
				"       AND cur.date BETWEEN '$start' AND '$end'" .
				"   )"
			);
			if ($rs && $rs->Next()) { $churned_players = (int)$rs->cnt; }
		}

		// ── one_time_visitors ────────────────────────────────────────────────────
		// Players with exactly 1 sign-in row in [$start,$end].
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(*) AS cnt FROM (" .
			"  SELECT mundane_id" .
			"  FROM " . DB_PREFIX . "attendance" .
			"  WHERE date BETWEEN '$start' AND '$end'" .
			$kingdom_filter .
			"  GROUP BY mundane_id" .
			"  HAVING COUNT(*) = 1" .
			") one_timers"
		);
		$one_time_visitors = 0;
		if ($rs && $rs->Next()) { $one_time_visitors = (int)$rs->cnt; }

		return [
			'new_players'       => $new_players,
			'returning_players' => $returning_players,
			'churned_players'   => $churned_players,
			'one_time_visitors' => $one_time_visitors,
		];
	}

	/**
	 * Returns per-class sign-in counts per year for the 5 years ending at $end,
	 * formatted for a multi-series chart.
	 *
	 * @param string $start       Start date (used only to constrain kingdoms; actual year
	 *                             window is always 5 years ending at $end)
	 * @param string $end         End date (YYYY-MM-DD)
	 * @param array  $kingdom_ids Optional list of integer kingdom IDs to filter by.
	 * @return array {
	 *   years  int[]   — e.g. [2020, 2021, 2022, 2023, 2024]
	 *   series array[] — each element: { class_name: string, data: int[] }
	 *                    data is parallel to years; 0 for years with no sign-ins
	 * }
	 */
	public function getClassTrends(string $start, string $end, array $kingdom_ids): array
	{
		global $DB;

		$end = preg_replace('/[^0-9\-]/', '', $end);

		$kingdom_filter = '';
		if (!empty($kingdom_ids)) {
			$safe_ids = array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0);
			if (!empty($safe_ids)) {
				$kingdom_filter = ' AND a.kingdom_id IN (' . implode(',', $safe_ids) . ')';
			}
		}
		if (empty($kingdom_filter)) {
			$kingdom_filter = " AND a.kingdom_id IN (SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE active = 'Active')";
		}

		// Determine the 5 calendar years ending with the year of $end
		$end_year   = (int)date('Y', strtotime($end));
		$start_year = $end_year - 4;
		$years      = range($start_year, $end_year);
		$trend_start = $start_year . '-01-01';

		// Fetch counts: one row per (class, year)
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT c.class_id, c.name AS class_name," .
			" YEAR(a.date) AS yr, COUNT(*) AS sign_in_count" .
			" FROM " . DB_PREFIX . "attendance a" .
			" INNER JOIN " . DB_PREFIX . "class c ON c.class_id = a.class_id" .
			" WHERE c.active = 1" .
			"   AND a.date BETWEEN '$trend_start' AND '$end'" .
			$kingdom_filter .
			" GROUP BY c.class_id, c.name, YEAR(a.date)" .
			" ORDER BY c.name, yr"
		);

		// Build a map: class_name => [ year => count ]
		$class_map = [];
		if ($rs) {
			while ($rs->Next()) {
				$cname = (string)$rs->class_name;
				$yr    = (int)$rs->yr;
				if (!isset($class_map[$cname])) { $class_map[$cname] = []; }
				$class_map[$cname][$yr] = (int)$rs->sign_in_count;
			}
		}

		// Convert to parallel-array series format
		$series = [];
		foreach ($class_map as $cname => $yr_counts) {
			$data = [];
			foreach ($years as $yr) {
				$data[] = $yr_counts[$yr] ?? 0;
			}
			$series[] = [
				'class_name' => $cname,
				'data'       => $data,
			];
		}

		return [
			'years'  => $years,
			'series' => $series,
		];
	}

	/**
	 * Returns sign-in totals grouped by calendar month across the date range,
	 * ordered chronologically.
	 *
	 * @param string $start       Start date (YYYY-MM-DD)
	 * @param string $end         End date (YYYY-MM-DD)
	 * @param array  $kingdom_ids Optional list of integer kingdom IDs to filter by.
	 * @return array[] Each element: { year, month, sign_ins, players }
	 *   year     int — calendar year
	 *   month    int — calendar month (1–12)
	 *   sign_ins int — total attendance rows
	 *   players  int — distinct mundane_ids with at least one sign-in that month
	 */
	public function getMonthlyBreakdown(string $start, string $end, array $kingdom_ids): array
	{
		global $DB;

		$start = preg_replace('/[^0-9\-]/', '', $start);
		$end   = preg_replace('/[^0-9\-]/', '', $end);

		$kingdom_filter = '';
		if (!empty($kingdom_ids)) {
			$safe_ids = array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0);
			if (!empty($safe_ids)) {
				$kingdom_filter = ' AND kingdom_id IN (' . implode(',', $safe_ids) . ')';
			}
		}
		if (empty($kingdom_filter)) {
			$kingdom_filter = " AND kingdom_id IN (SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE active = 'Active')";
		}

		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT" .
			"  YEAR(date)              AS yr," .
			"  MONTH(date)             AS mo," .
			"  COUNT(*)                AS sign_ins," .
			"  COUNT(DISTINCT mundane_id) AS players" .
			" FROM " . DB_PREFIX . "attendance" .
			" WHERE date BETWEEN '$start' AND '$end'" .
			$kingdom_filter .
			" GROUP BY YEAR(date), MONTH(date)" .
			" ORDER BY yr ASC, mo ASC"
		);

		$result = [];
		if ($rs) {
			while ($rs->Next()) {
				$result[] = [
					'year'     => (int)$rs->yr,
					'month'    => (int)$rs->mo,
					'sign_ins' => (int)$rs->sign_ins,
					'players'  => (int)$rs->players,
				];
			}
		}

		return $result;
	}

	public function getPlayerLongevity(string $start, string $end, array $kingdom_ids): array {
		global $DB;

		$safe_start = preg_replace('/[^0-9\-]/', '', $start);
		$safe_end   = preg_replace('/[^0-9\-]/', '', $end);

		$safe_ids = array_values(array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0));
		if (!empty($safe_ids)) {
			$id_list        = implode(',', $safe_ids);
			$kingdom_filter = " AND a.kingdom_id IN ($id_list)";
		} else {
			$kingdom_filter = " AND a.kingdom_id IN (SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE active = 'Active')";
		}

		// Active players in period + their first-ever attendance date (global, not kingdom-filtered)
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT FLOOR(TIMESTAMPDIFF(YEAR, fc.first_date, '" . $safe_end . "') / 2) AS bucket," .
			" COUNT(*) AS cnt" .
			" FROM (" .
			"   SELECT DISTINCT a.mundane_id" .
			"   FROM " . DB_PREFIX . "attendance a" .
			"   WHERE a.date BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			$kingdom_filter .
			" ) active_p" .
			" JOIN (" .
			"   SELECT mundane_id, MIN(date) AS first_date" .
			"   FROM " . DB_PREFIX . "attendance" .
			"   WHERE date >= '1990-01-01'" .
			"   GROUP BY mundane_id" .
			" ) fc ON fc.mundane_id = active_p.mundane_id" .
			" WHERE fc.first_date IS NOT NULL" .
			"   AND TIMESTAMPDIFF(YEAR, fc.first_date, '" . $safe_end . "') BETWEEN 0 AND 40" .
			" GROUP BY bucket ORDER BY bucket ASC"
		);

		$raw = [];
		$max_bucket = 0;
		if ($rs) {
			while ($rs->Next()) {
				$b = max(0, (int)$rs->bucket);
				$raw[$b] = (int)$rs->cnt;
				if ($b > $max_bucket) $max_bucket = $b;
			}
		}

		// Build 2-year buckets; anything >= 10 yrs collapses into "10+ yrs"
		$CAP = 5; // bucket 5 = 10-12 yrs → collapse everything >= 5 into "10+ yrs"
		$result = [];
		for ($i = 0; $i < $CAP; $i++) {
			$result[] = ['label' => ($i * 2) . '-' . (($i + 1) * 2) . ' yrs', 'count' => $raw[$i] ?? 0];
		}
		$ten_plus = 0;
		for ($j = $CAP; $j <= $max_bucket; $j++) $ten_plus += ($raw[$j] ?? 0);
		$result[] = ['label' => '10+ yrs', 'count' => $ten_plus];

		return $result;
	}

}
