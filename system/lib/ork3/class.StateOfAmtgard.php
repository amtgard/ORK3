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

		// 30-min cache: deterministic on (start, end, kingdom_ids)
		sort($kingdom_ids);
		$cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getKingdomSignIns', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

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
		// PHP DateTime arithmetic — no DB round-trip needed.
		$prior_start = '';
		$prior_end   = '';
		try {
			$startDt = new DateTime($start);
			$endDt   = new DateTime($end);
			$days    = $endDt->diff($startDt)->days + 1;
			$prior_end_dt   = (clone $startDt)->modify('-1 day');
			$prior_start_dt = (clone $prior_end_dt)->modify('-' . ($days - 1) . ' days');
			$prior_start = $prior_start_dt->format('Y-m-d');
			$prior_end   = $prior_end_dt->format('Y-m-d');
		} catch (Exception $e) {
			// leave prior_* empty on malformed input — caller handles
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
		  AND a.mundane_id > 0
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

		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getKingdomSignIns', $cacheKey, $result);
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

		// 60-min cache: list of active kingdoms rarely changes.
		// Suffix bumps when the sort/shape changes so old entries are bypassed
		// without needing a memcache flush.
		$cacheKey = 'all_v2';
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getActiveKingdoms', $cacheKey, 3600);
		if ($cached !== false && $cached !== null) return $cached;

		$DB->Clear();
		// Order is finalized in PHP (see usort below) — the SQL ORDER BY is
		// just a deterministic tiebreaker if the sort keys collide.
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

		// Sort by the kingdom's "core" name — strip leading "The " and
		// title prefixes ("Kingdom of [the]", "Empire of [the]",
		// "Freeholds of [the]", "Principality of [the]") so e.g.
		// "The Kingdom of Blackspire" sorts under B, not T.
		usort($kingdoms, function ($a, $b) {
			return strcasecmp(
				self::kingdomSortKey($a['kingdom_name']),
				self::kingdomSortKey($b['kingdom_name'])
			);
		});

		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getActiveKingdoms', $cacheKey, $kingdoms);
		return $kingdoms;
	}

	/**
	 * Return the "core" portion of a kingdom name for display-sort purposes.
	 * Matches the tokenized skip-list approach used by the homepage kingdom
	 * list in orkui/template/default/default.tpl — every word in the skip
	 * list is dropped wherever it appears (not just at the start), so
	 * "The Kingdom of Blackspire" → "blackspire" and "Kingdom of the
	 * Desert Winds" → "desert winds". Keeping the same algorithm app-wide
	 * means the filter dropdown order matches what users already see on
	 * the homepage.
	 */
	private static function kingdomSortKey(string $name): string
	{
		$words = preg_split('/\s+/', trim($name));
		$skip  = ['the', 'kingdom', 'empire', 'of'];
		$filtered = array_filter($words, function ($w) use ($skip) {
			return !in_array(strtolower($w), $skip, true);
		});
		return strtolower(implode(' ', array_values($filtered)));
	}

    public function getClassSignIns(string $start, string $end, array $kingdom_ids): array
    {
        global $DB;

        // 30-min cache
        sort($kingdom_ids);
        $cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
        $cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getClassSignIns', $cacheKey, 1800);
        if ($cached !== false && $cached !== null) return $cached;

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
               AND a.mundane_id > 0
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

        Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getClassSignIns', $cacheKey, $result);
        return $result;
    }

	private function spearmanCorrelation(array $values): float {
		// Tie-corrected Spearman: compute Pearson correlation on the ranks.
		// The simplified `1 - 6*d^2/(n*(n^2-1))` formula is only valid with NO
		// tied ranks; park sign-in counts tie frequently (e.g. 15, 15, 12).
		// X is the time index (1..n) so X never ties; Y may tie — assign each
		// tied group the average of the positions they would occupy.
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
		// Pearson on the ranks
		$meanX = array_sum($rankX) / $n;
		$meanY = array_sum($rankY) / $n;
		$num = 0.0; $sx = 0.0; $sy = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$dx = $rankX[$i] - $meanX;
			$dy = $rankY[$i] - $meanY;
			$num += $dx * $dy;
			$sx  += $dx * $dx;
			$sy  += $dy * $dy;
		}
		$den = sqrt($sx * $sy);
		if ($den == 0.0) return 0.0;
		$r = $num / $den;
		// Clamp to [-1, 1] to guard against floating point drift
		if ($r >  1.0) $r =  1.0;
		if ($r < -1.0) $r = -1.0;
		return $r;
	}

	public function getParksAnalysis(string $start, string $end, array $kingdom_ids): array {
		global $DB;

		// 30-min cache
		sort($kingdom_ids);
		$cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getParksAnalysis', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

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
		// NOTE: MIN(a.date) is computed kingdom-agnostically (the park filter is on
		// p.kingdom_id, not a.kingdom_id), so this means "park's first-ever sign-in"
		// across all kingdoms, not "first sign-in within the filtered kingdom set".
		// This is the defensible interpretation: a park is "new" if it just started
		// reporting attendance — its historical kingdom assignment is irrelevant.
		// IMPORTANT: count a founding regardless of the park's CURRENT active/retired
		// status. A park founded *and* retired inside the window is still a birth;
		// only counting still-Active parks here (while "lost" counts Retired ones)
		// made net = new - lost wildly negative over long windows (every dead park
		// landed in "lost" with no matching "new"). new = births, lost = deaths.
		// -------------------------------------------------------
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.park_id, p.name AS park_name, k.name AS kingdom_name" .
			" FROM " . DB_PREFIX . "attendance a" .
			" JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id" .
			" JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id" .
			" WHERE a.mundane_id > 0" . $kingdom_clause .
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
			" WHERE a.mundane_id > 0 AND p.active = 'Retired'" .
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
			"   AND a.mundane_id > 0" .
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
			"   AND a.mundane_id > 0" .
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
			"   AND a.mundane_id > 0" .
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
		$result = [
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
		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getParksAnalysis', $cacheKey, $result);
		return $result;
	}


	public function getPlayerStats(string $start, string $end, array $kingdom_ids): array {
		global $DB;

		// 30-min cache
		sort($kingdom_ids);
		$cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getPlayerStats', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

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

		// ----- CONSOLIDATED MATERIALIZATION (FIX 6) -----
		// Two temp tables feed all 8 downstream aggregations, eliminating redundant
		// full scans of ork_attendance. Parity verified against the original 8-query
		// implementation across multiple scenarios; identical numeric output.
		//   _sa_ps_range : per-mundane summary for the exact [$start,$end] window
		//                  (feeds the "selected range" all-players and normal-players blocks)
		//   _sa_ps_ten   : per-(mundane, year) summary for the 10-year window
		//                  (feeds all 6 ten-year aggregations including trend, lifespan,
		//                   and the normal-player HAVING subset)
		$ten_year_start_sql = "DATE_FORMAT(DATE_SUB('$end', INTERVAL 9 YEAR), '%Y-01-01')";

		$DB->Clear();
		$DB->Execute("DROP TEMPORARY TABLE IF EXISTS _sa_ps_range");
		$DB->Clear();
		$DB->Execute("DROP TEMPORARY TABLE IF EXISTS _sa_ps_ten");

		$DB->Clear();
		$DB->Execute("
			CREATE TEMPORARY TABLE _sa_ps_range (
				mundane_id INT NOT NULL,
				cnt INT NOT NULL,
				cred DECIMAL(10,2) NOT NULL,
				PRIMARY KEY (mundane_id)
			)
			SELECT mundane_id, COUNT(*) AS cnt, SUM(credits) AS cred
			FROM " . DB_PREFIX . "attendance
			WHERE mundane_id > 0 AND date BETWEEN '$start' AND '$end' $kingdom_filter
			GROUP BY mundane_id
		");

		$DB->Clear();
		$DB->Execute("
			CREATE TEMPORARY TABLE _sa_ps_ten (
				mundane_id INT NOT NULL,
				yr SMALLINT NOT NULL,
				cnt INT NOT NULL,
				cred DECIMAL(10,2) NOT NULL,
				PRIMARY KEY (mundane_id, yr),
				KEY ix_yr (yr)
			)
			SELECT mundane_id, YEAR(date) AS yr, COUNT(*) AS cnt, SUM(credits) AS cred
			FROM " . DB_PREFIX . "attendance
			WHERE mundane_id > 0 AND date >= $ten_year_start_sql AND date <= '$end' $kingdom_filter
			GROUP BY mundane_id, YEAR(date)
		");

		// Trend window (Figure 3b): honor the selected date range, but never show
		// fewer than the rolling 10 years (so a short selection still shows context).
		// When the selection reaches further back than the 10-year window, build a
		// wider per-(mundane, year) table JUST for the trend, leaving _sa_ps_ten — and
		// the other 5 explicitly-"10-year" aggregates below — unchanged.
		$trend_table    = '_sa_ps_ten';
		$end_year       = (int)substr($end, 0, 4);
		$start_year     = (int)substr($start, 0, 4);
		$ten_floor_year = $end_year - 9;
		if ($start_year > 0 && $start_year < $ten_floor_year) {
			$trend_start_sql = "'" . (int)$start_year . "-01-01'";
			$DB->Clear();
			$DB->Execute("DROP TEMPORARY TABLE IF EXISTS _sa_ps_trend");
			$DB->Execute("
				CREATE TEMPORARY TABLE _sa_ps_trend (
					mundane_id INT NOT NULL,
					yr SMALLINT NOT NULL,
					cnt INT NOT NULL,
					cred DECIMAL(10,2) NOT NULL,
					PRIMARY KEY (mundane_id, yr),
					KEY ix_yr (yr)
				)
				SELECT mundane_id, YEAR(date) AS yr, COUNT(*) AS cnt, SUM(credits) AS cred
				FROM " . DB_PREFIX . "attendance
				WHERE mundane_id > 0 AND date >= $trend_start_sql AND date <= '$end' $kingdom_filter
				GROUP BY mundane_id, YEAR(date)
			");
			$trend_table = '_sa_ps_trend';
		}

		// ----- ALL PLAYERS (selected range) -- from _sa_ps_range -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt) AS total_si,
			       AVG(cnt) AS avg_si, STDDEV_SAMP(cnt) AS std_si,
			       MIN(cnt) AS min_si, MAX(cnt) AS max_si, AVG(cred) AS avg_cred
			FROM _sa_ps_range
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

		// ----- NORMAL PLAYERS (range, >= 4 sign-ins AND >= 12 credits) -- from _sa_ps_range -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt) AS total_si,
			       AVG(cnt) AS avg_si, STDDEV_SAMP(cnt) AS std_si,
			       MIN(cnt) AS min_si, MAX(cnt) AS max_si, AVG(cred) AS avg_cred
			FROM _sa_ps_range
			WHERE cnt >= 4 AND cred >= 12
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

		// ----- TREND BY YEAR (trend window, all players) -- from $trend_table -----
		// SUM(cnt) per year reproduces COUNT(*) of raw attendance rows because
		// cnt is COUNT(*) grouped by (mundane_id, YEAR(date)).
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT yr, SUM(cnt) AS cnt
			FROM {$trend_table}
			GROUP BY yr ORDER BY yr
		");
		$trend_by_year = [];
		if ($rs) { while ($rs->Next()) { $trend_by_year[] = ['year' => (int)$rs->yr, 'sign_ins' => (int)$rs->cnt]; } }

		// ----- TREND BY YEAR — NORMAL PLAYERS (trend window) -- from $trend_table -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT yr, COUNT(*) AS cnt
			FROM {$trend_table}
			WHERE cnt >= 4 AND cred >= 12
			GROUP BY yr ORDER BY yr
		");
		$trend_normal_by_year = [];
		if ($rs) { while ($rs->Next()) { $trend_normal_by_year[] = ['year' => (int)$rs->yr, 'normal_players' => (int)$rs->cnt]; } }

		// ----- TEN-YEAR AGGREGATE (all players) -- from _sa_ps_ten re-rolled per mundane -----
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt2) AS total_si,
			       AVG(cnt2) AS avg_si, STDDEV_SAMP(cnt2) AS std_si,
			       MIN(cnt2) AS min_si, MAX(cnt2) AS max_si, AVG(cred2) AS avg_cred
			FROM (
				SELECT mundane_id, SUM(cnt) AS cnt2, SUM(cred) AS cred2
				FROM _sa_ps_ten
				GROUP BY mundane_id
			) p
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

		// avg_lifespan (all players, 10-year) -- COUNT(*) per mundane in _sa_ps_ten
		// == COUNT(DISTINCT YEAR(date)) per mundane, since rows are unique on (mundane_id, yr).
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT AVG(yrs) AS avg_lifespan FROM (
				SELECT mundane_id, COUNT(*) AS yrs FROM _sa_ps_ten GROUP BY mundane_id
			) p
		");
		$ten_avg_lifespan = 0.0;
		if ($rs && $rs->Next()) { $ten_avg_lifespan = (float)$rs->avg_lifespan; }

		// Normal players (10-year) -- from _sa_ps_ten with HAVING on summed cnt/cred
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT COUNT(*) AS player_count, SUM(cnt2) AS total_si,
			       AVG(cnt2) AS avg_si, STDDEV_SAMP(cnt2) AS std_si,
			       MIN(cnt2) AS min_si, MAX(cnt2) AS max_si, AVG(cred2) AS avg_cred
			FROM (
				SELECT mundane_id, SUM(cnt) AS cnt2, SUM(cred) AS cred2
				FROM _sa_ps_ten
				GROUP BY mundane_id
				HAVING cnt2 >= 4 AND cred2 >= 12
			) p
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

		// avg_lifespan (normal players, 10-year) -- from _sa_ps_ten
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT AVG(yrs) AS avg_lifespan FROM (
				SELECT mundane_id, COUNT(*) AS yrs
				FROM _sa_ps_ten
				GROUP BY mundane_id
				HAVING SUM(cnt) >= 4 AND SUM(cred) >= 12
			) p
		");
		$ten_norm_lifespan = 0.0;
		if ($rs && $rs->Next()) { $ten_norm_lifespan = (float)$rs->avg_lifespan; }

		// Explicit cleanup (PHP-FPM workers may pool connections; auto-drop on
		// connection close isn't sufficient).
		$DB->Clear();
		$DB->Execute("DROP TEMPORARY TABLE IF EXISTS _sa_ps_range");
		$DB->Clear();
		$DB->Execute("DROP TEMPORARY TABLE IF EXISTS _sa_ps_ten");
		$DB->Clear();
		$DB->Execute("DROP TEMPORARY TABLE IF EXISTS _sa_ps_trend");

		$ten_avg_cred_per_week      = round($ten_avg_cred / $weeks_in_range, 1);
		$ten_norm_avg_cred_per_week = round($ten_norm_avg_cred / $weeks_in_range, 1);

		$result = [
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
		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getPlayerStats', $cacheKey, $result);
		return $result;
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
	 *
	 * Kingdom-filter asymmetry (intentional, preserved from original SQL):
	 *   - returning_players: $kingdom_filter is applied to the current-period membership
	 *     (cur), but NOT to the historical-existence sub-select (hist). Interpretation:
	 *     "new-to-the-kingdom rate among returning attendees" — anyone who showed up at
	 *     this kingdom in [$start,$end] AND had ever played Amtgard anywhere prior.
	 *   - churned_players: $kingdom_filter is applied to the prior-period membership
	 *     (prior), but NOT to the current-period absence check (cur). Interpretation:
	 *     "left this kingdom" — was active in this kingdom in the prior period and is
	 *     not signed in anywhere in [$start,$end] (could have moved kingdoms or quit
	 *     Amtgard entirely; both count as churn from this kingdom's perspective).
	 * Row-count parity with the original pre-rewrite implementation has been verified.
	 * Do not symmetrize the filters without an explicit product decision.
	 */
	public function getPlayerCohorts(string $start, string $end, array $kingdom_ids): array
	{
		global $DB;

		// 30-min cache
		sort($kingdom_ids);
		$cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getPlayerCohorts', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

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
		// PHP DateTime arithmetic — no DB round-trip needed.
		$prior_start = '';
		$prior_end   = '';
		try {
			$startDt = new DateTime($start);
			$endDt   = new DateTime($end);
			$days    = $endDt->diff($startDt)->days + 1;
			$prior_end_dt   = (clone $startDt)->modify('-1 day');
			$prior_start_dt = (clone $prior_end_dt)->modify('-' . ($days - 1) . ' days');
			$prior_start = $prior_start_dt->format('Y-m-d');
			$prior_end   = $prior_end_dt->format('Y-m-d');
		} catch (Exception $e) {
			// leave prior_* empty on malformed input
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
			"  WHERE mundane_id > 0 $kingdom_filter" .
			"  GROUP BY mundane_id" .
			"  HAVING MIN(date) BETWEEN '$start' AND '$end'" .
			") new_cohort"
		);
		$new_players = 0;
		if ($rs && $rs->Next()) { $new_players = (int)$rs->cnt; }

		// ── returning_players ────────────────────────────────────────────────────
		// Players with at least one sign-in in [$start,$end] AND at least one before $start.
		// Rewritten as a set-based INNER JOIN of two distinct-mundane-id derived sets
		// instead of a correlated EXISTS subquery (per-row probe).
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(*) AS cnt FROM (" .
			"  SELECT DISTINCT cur.mundane_id" .
			"  FROM " . DB_PREFIX . "attendance cur" .
			"  WHERE cur.mundane_id > 0 AND cur.date BETWEEN '$start' AND '$end'" .
			   $kingdom_filter .
			") cur" .
			" INNER JOIN (" .
			"  SELECT DISTINCT mundane_id" .
			"  FROM " . DB_PREFIX . "attendance" .
			"  WHERE mundane_id > 0 AND date < '$start'" .
			") hist ON hist.mundane_id = cur.mundane_id"
		);
		$returning_players = 0;
		if ($rs && $rs->Next()) { $returning_players = (int)$rs->cnt; }

		// ── churned_players ──────────────────────────────────────────────────────
		// Players who appeared in the prior period but have NO record in [$start,$end].
		// Only meaningful if we have a valid prior period.
		// Rewritten as a set-based LEFT JOIN ... IS NULL anti-join instead of a
		// correlated NOT EXISTS subquery (per-row probe).
		$churned_players = 0;
		if ($prior_start !== '' && $prior_end !== '') {
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT COUNT(*) AS cnt FROM (" .
				"  SELECT DISTINCT prior.mundane_id" .
				"  FROM " . DB_PREFIX . "attendance prior" .
				"  WHERE prior.mundane_id > 0 AND prior.date BETWEEN '$prior_start' AND '$prior_end'" .
				   $kingdom_filter .
				") prior" .
				" LEFT JOIN (" .
				"  SELECT DISTINCT cur.mundane_id" .
				"  FROM " . DB_PREFIX . "attendance cur" .
				"  WHERE cur.date BETWEEN '$start' AND '$end'" .
				") cur ON cur.mundane_id = prior.mundane_id" .
				" WHERE cur.mundane_id IS NULL"
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
			"  WHERE mundane_id > 0 AND date BETWEEN '$start' AND '$end'" .
			$kingdom_filter .
			"  GROUP BY mundane_id" .
			"  HAVING COUNT(*) = 1" .
			") one_timers"
		);
		$one_time_visitors = 0;
		if ($rs && $rs->Next()) { $one_time_visitors = (int)$rs->cnt; }

		$result = [
			'new_players'       => $new_players,
			'returning_players' => $returning_players,
			'churned_players'   => $churned_players,
			'one_time_visitors' => $one_time_visitors,
		];
		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getPlayerCohorts', $cacheKey, $result);
		return $result;
	}

	public function getPlayerLongevity(string $start, string $end, array $kingdom_ids): array {
		global $DB;

		// 30-min cache
		sort($kingdom_ids);
		$cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getPlayerLongevity', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

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
			"   WHERE a.mundane_id > 0" .
			"     AND a.date BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
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

		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getPlayerLongevity', $cacheKey, $result);
		return $result;
	}


	public function getAwardGrants(string $start, string $end, array $kingdom_ids): array
	{
		global $DB;

		// 30-min cache: deterministic on (start, end, kingdom_ids)
		sort($kingdom_ids);
		$cacheKey = md5($start . '|' . $end . '|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getAwardGrants', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

		// Sanitize inputs
		$safe_start = preg_replace('/[^0-9\-]/', '', $start);
		$safe_end   = preg_replace('/[^0-9\-]/', '', $end);

		// Build optional kingdom filter on the recipient kingdom (aw.kingdom_id).
		// Note: aw.kingdom_id is NULLABLE; rows with NULL kingdom_id are excluded
		// whenever any kingdom filter is active (including the implicit "active kingdoms"
		// fallback below). This matches the convention used in getClassSignIns.
		$kingdom_filter = '';
		if (!empty($kingdom_ids)) {
			$safe_ids = array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0);
			if (!empty($safe_ids)) {
				$kingdom_filter = ' AND aw.kingdom_id IN (' . implode(',', $safe_ids) . ')';
			}
		}

		// Definitive ordered award lists for zero-padding the pie-chart buckets.
		// Pulled fresh from ork_award so we don't hardcode the names (only the IDs).
		$peerage_award_ids = [
			'Knight'  => [17, 18, 19, 20, 245],
			'Master'  => [1, 2, 3, 4, 5, 6, 12, 240, 244],
			'Paragon' => [37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 241, 242],
		];
		$all_ids = array_merge(
			$peerage_award_ids['Knight'],
			$peerage_award_ids['Master'],
			$peerage_award_ids['Paragon']
		);

		// ── 1. Name lookup + zero-bucket scaffold ───────────────────────────────
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT award_id, name, peerage" .
			" FROM " . DB_PREFIX . "award" .
			" WHERE award_id IN (" . implode(',', $all_ids) . ")"
		);
		$award_meta = [];
		if ($rs) {
			while ($rs->Next()) {
				$award_meta[(int)$rs->award_id] = [
					'name'    => (string)$rs->name,
					'peerage' => (string)$rs->peerage,
				];
			}
		}

		// ── 2. Counts per award in window ───────────────────────────────────────
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT a.award_id, a.name, a.peerage, COUNT(*) AS cnt" .
			" FROM " . DB_PREFIX . "awards aw" .
			" JOIN " . DB_PREFIX . "award a ON a.award_id = aw.award_id" .
			" WHERE a.peerage IN ('Knight','Master','Paragon')" .
			"   AND aw.revoked = 0" .
			"   AND aw.date BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			$kingdom_filter .
			" GROUP BY a.award_id, a.name, a.peerage"
		);
		$counts_by_id = [];
		if ($rs) {
			while ($rs->Next()) {
				$counts_by_id[(int)$rs->award_id] = (int)$rs->cnt;
			}
		}

		// Build zero-padded buckets in award_id ASC order
		$buckets = ['knights' => [], 'masters' => [], 'paragons' => []];
		$totals  = ['knights' => 0, 'masters' => 0, 'paragons' => 0];
		$bucket_for = ['Knight' => 'knights', 'Master' => 'masters', 'Paragon' => 'paragons'];
		foreach ($peerage_award_ids as $peerage => $ids) {
			$key = $bucket_for[$peerage];
			foreach ($ids as $aid) {
				$cnt = $counts_by_id[$aid] ?? 0;
				$name = $award_meta[$aid]['name'] ?? ('Award ' . $aid);
				$buckets[$key][] = [
					'award_id' => $aid,
					'name'     => $name,
					'count'    => $cnt,
				];
				$totals[$key] += $cnt;
			}
		}

		// ── 3. Kingdoms by total peerage grants (all kingdoms in scope, ordered) ─
		// aw.kingdom_id is NULLABLE → grants with no recipient-kingdom are excluded
		// from this breakdown (we have no kingdom to attribute them to).
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT aw.kingdom_id, k.name AS kingdom_name," .
			"  SUM(CASE WHEN a.peerage = 'Knight'  THEN 1 ELSE 0 END) AS knights," .
			"  SUM(CASE WHEN a.peerage = 'Master'  THEN 1 ELSE 0 END) AS masters," .
			"  SUM(CASE WHEN a.peerage = 'Paragon' THEN 1 ELSE 0 END) AS paragons," .
			"  COUNT(*) AS total" .
			" FROM " . DB_PREFIX . "awards aw" .
			" JOIN " . DB_PREFIX . "award a ON a.award_id = aw.award_id" .
			" JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = aw.kingdom_id" .
			" WHERE a.peerage IN ('Knight','Master','Paragon')" .
			"   AND aw.revoked = 0" .
			"   AND aw.kingdom_id IS NOT NULL" .
			"   AND aw.date BETWEEN '" . $safe_start . "' AND '" . $safe_end . "'" .
			$kingdom_filter .
			" GROUP BY aw.kingdom_id, k.name" .
			" ORDER BY total DESC"
		);
		$top_kingdoms = [];
		if ($rs) {
			while ($rs->Next()) {
				$top_kingdoms[] = [
					'kingdom_id'   => (int)$rs->kingdom_id,
					'kingdom_name' => (string)$rs->kingdom_name,
					'knights'      => (int)$rs->knights,
					'masters'      => (int)$rs->masters,
					'paragons'     => (int)$rs->paragons,
					'total'        => (int)$rs->total,
				];
			}
		}

		// ── 5. Ten-year trend of peerage grants per calendar year ──────────────
		// NOTE: This trend is FIXED to a rolling 10-year window ending at $end's
		// calendar year and intentionally IGNORES $start. The report uses the
		// $start..$end window for "this period" stats above, but the trend chart
		// is meant to always show a full 10-year context regardless of the
		// selected period length. $kingdom_filter still applies so the trend
		// scopes to the same kingdoms as the rest of the report.
		$end_dt          = new \DateTime($safe_end);
		$end_year        = (int)$end_dt->format('Y');
		$start_year      = $end_year - 9;
		$ten_year_start  = $start_year . '-01-01';
		$ten_year_end    = $safe_end;

		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT YEAR(aw.date) AS yr, a.peerage, COUNT(*) AS cnt" .
			" FROM " . DB_PREFIX . "awards aw" .
			" JOIN " . DB_PREFIX . "award a ON a.award_id = aw.award_id" .
			" WHERE a.peerage IN ('Knight','Master','Paragon')" .
			"   AND aw.revoked = 0" .
			"   AND aw.date BETWEEN '" . $ten_year_start . "' AND '" . $ten_year_end . "'" .
			$kingdom_filter .
			" GROUP BY YEAR(aw.date), a.peerage" .
			" ORDER BY yr, a.peerage"
		);
		$trend_pivot = [];
		if ($rs) {
			while ($rs->Next()) {
				$yr  = (int)$rs->yr;
				$pee = (string)$rs->peerage;
				$trend_pivot[$yr][$pee] = (int)$rs->cnt;
			}
		}
		// Zero-pad every (year, peerage) so the line chart has continuous data
		$ten_year_trend = [];
		for ($y = $start_year; $y <= $end_year; $y++) {
			$ten_year_trend[] = [
				'year'     => $y,
				'knights'  => (int)($trend_pivot[$y]['Knight']  ?? 0),
				'masters'  => (int)($trend_pivot[$y]['Master']  ?? 0),
				'paragons' => (int)($trend_pivot[$y]['Paragon'] ?? 0),
			];
		}

		$result = [
			'knights'        => $buckets['knights'],
			'masters'        => $buckets['masters'],
			'paragons'       => $buckets['paragons'],
			'totals'         => $totals,
			'top_kingdoms'   => $top_kingdoms,
			'ten_year_trend' => $ten_year_trend,
		];

		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getAwardGrants', $cacheKey, $result);
		return $result;
	}

	/**
	 * New-player conversion & retention (fixed mature-cohort analysis).
	 *
	 * Unlike the period-scoped sections, this intentionally IGNORES the report's
	 * date range: it always studies "mature" cohorts — players whose first-ever
	 * sign-in was at least 24 months before the latest attendance in the data —
	 * so retention is never right-censored by people who just joined. The kingdom
	 * filter still applies, scoped to the kingdom of each player's FIRST sign-in.
	 *
	 * Visit counting uses distinct sign-in DAYS (one per date). A "real joiner"
	 * is anyone with >= 4 distinct sign-in days — matching the report's definition
	 * of 1-3 sign-ins as visitors/trial attendees. (This is the conversion line;
	 * it does NOT also require the 12-credit "normal player" bar used elsewhere.)
	 * "Active at N" means they actually attended within a 3-month window of the
	 * N-month anniversary (not merely that their first..last span reached N).
	 */
	public function getNewPlayerRetention(array $kingdom_ids): array {
		global $DB;

		sort($kingdom_ids);
		$cacheKey = md5('retention|' . implode(',', $kingdom_ids));
		$cached = Ork3::$Lib->ghettocache->get('StateOfAmtgard.getNewPlayerRetention', $cacheKey, 1800);
		if ($cached !== false && $cached !== null) return $cached;

		// Reference "now" = latest real attendance date; mature cutoff = 24 months prior.
		$DB->Clear();
		$rs = $DB->DataSet("SELECT MAX(date) AS d FROM " . DB_PREFIX . "attendance WHERE date BETWEEN '1990-01-01' AND CURDATE()");
		$ref_end = ($rs && $rs->Next() && $rs->d) ? (string)$rs->d : date('Y-m-d');
		$cutoff  = date('Y-m-d', strtotime($ref_end . ' -24 months'));

		$safe_ids = array_values(array_filter(array_map('intval', $kingdom_ids), fn($id) => $id > 0 && $id < 100000));
		$kfilter  = !empty($safe_ids) ? (' WHERE first_kingdom IN (' . implode(',', $safe_ids) . ')') : '';
		$rjfilter = $kfilter === '' ? ' WHERE days >= 4' : ($kfilter . ' AND days >= 4');

		$num = fn($v) => $v === null ? null : (float)$v;

		// One-pass per-player cohort facts into a (request-local) temp table.
		// first_kingdom = kingdom of the earliest sign-in; a12/a24 = attended within
		// 3 months of the 1yr / 2yr anniversary; active_months = distinct months attended.
		$DB->query("DROP TEMPORARY TABLE IF EXISTS sor_ret");
		$DB->query(
			"CREATE TEMPORARY TABLE sor_ret AS " .
			"SELECT life.mundane_id, life.first_date, life.last_date, life.days, " .
			"  COUNT(DISTINCT EXTRACT(YEAR_MONTH FROM a.date)) AS active_months, " .
			"  MAX(TIMESTAMPDIFF(MONTH, life.first_date, a.date) BETWEEN 12 AND 14) AS a12, " .
			"  MAX(TIMESTAMPDIFF(MONTH, life.first_date, a.date) BETWEEN 24 AND 26) AS a24, " .
			"  MIN(CASE WHEN a.date = life.first_date THEN a.kingdom_id END) AS first_kingdom " .
			"FROM (SELECT mundane_id, MIN(date) AS first_date, MAX(date) AS last_date, COUNT(DISTINCT date) AS days " .
			"      FROM " . DB_PREFIX . "attendance WHERE mundane_id > 0 AND date >= '1990-01-01' " .
			"      GROUP BY mundane_id HAVING first_date <= '" . $cutoff . "') life " .
			"JOIN " . DB_PREFIX . "attendance a ON a.mundane_id = life.mundane_id AND a.date >= '1990-01-01' " .
			"GROUP BY life.mundane_id"
		);

		// Funnel + headline (whole cohort)
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(*) AS cohort, " .
			" ROUND(AVG(days), 1) AS mean_signins, " .
			" ROUND(100*AVG(days = 1), 1) AS pct_one_and_done, " .
			" ROUND(100*AVG(days >= 2), 1) AS pct_ge2_visits, " .
			" ROUND(100*AVG(days >= 4), 1) AS pct_realjoin, " .
			" ROUND(100*AVG(days >= 6), 1) AS pct_ge6_visits " .
			"FROM sor_ret" . $kfilter
		);
		$headline = ['cohort' => 0];
		if ($rs && $rs->Next()) {
			$headline = [
				'cohort'           => (int)$rs->cohort,
				'mean_signins'     => $num($rs->mean_signins),
				'pct_one_and_done' => $num($rs->pct_one_and_done),
				'pct_ge2_visits'   => $num($rs->pct_ge2_visits),
				'pct_realjoin'     => $num($rs->pct_realjoin),
				'pct_ge6_visits'   => $num($rs->pct_ge6_visits),
			];
		}

		// Median sign-ins (whole cohort)
		$DB->Clear();
		$rs = $DB->DataSet("SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY days) OVER () AS med FROM sor_ret" . $kfilter . " LIMIT 1");
		$headline['median_signins'] = ($rs && $rs->Next()) ? $num($rs->med) : null;

		// Real-joiner retention (>= 3 distinct sign-in days)
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT COUNT(*) AS rj, " .
			" ROUND(100*AVG(a12), 1) AS active_1yr, " .
			" ROUND(100*AVG(a24), 1) AS active_2yr, " .
			" ROUND(AVG(active_months), 1) AS mean_active_months, " .
			" ROUND(AVG(DATEDIFF(last_date, first_date)/30.44), 1) AS mean_tenure_months " .
			"FROM sor_ret" . $rjfilter
		);
		$realjoiners = ['n' => 0];
		if ($rs && $rs->Next()) {
			$realjoiners = [
				'n'                  => (int)$rs->rj,
				'active_1yr'         => $num($rs->active_1yr),
				'active_2yr'         => $num($rs->active_2yr),
				'mean_active_months' => $num($rs->mean_active_months),
				'mean_tenure_months' => $num($rs->mean_tenure_months),
			];
		}

		// Real-joiner medians
		$DB->Clear();
		$rs = $DB->DataSet(
			"SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY DATEDIFF(last_date, first_date)/30.44) OVER () AS med_tenure, " .
			" PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY active_months) OVER () AS med_active " .
			"FROM sor_ret" . $rjfilter . " LIMIT 1"
		);
		if ($rs && $rs->Next()) {
			$realjoiners['median_tenure_months'] = $num($rs->med_tenure);
			$realjoiners['median_active_months'] = $num($rs->med_active);
		}

		$DB->query("DROP TEMPORARY TABLE IF EXISTS sor_ret");

		$result = [
			'cutoff'      => $cutoff,
			'ref_end'     => $ref_end,
			'headline'    => $headline,
			'realjoiners' => $realjoiners,
		];

		Ork3::$Lib->ghettocache->cache('StateOfAmtgard.getNewPlayerRetention', $cacheKey, $result);
		return $result;
	}

}
