# Recommendations Manager Server-Side Lazy Pagination — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Recommendations Manager page its data server-side in 500-row (cluster) batches via infinite scroll, never selecting the full set, with all search/filter/sort applied server-side and eligibility aligned to the recs-tab pills (Open Recs default, newest-first).

**Architecture:** A new page-selection SQL query chooses the ≤500 *clusters* (`mundane_id·kingdomaward_id·rank`) for a batch — applying the SQL-expressible filters + sort + `LIMIT/OFFSET`. Those clusters are then hydrated by REUSING the existing `PlayerAwardRecommendations` row-building (so the exact `AlreadyHas`/snoozed/seconds/Master-peerage logic is unchanged), grouped via a shared helper, and the exact eligibility is re-applied in PHP. A new `Recommendations/rows` AJAX endpoint renders batches as `<tr>` HTML via a shared row partial; the template's JS appends batches on scroll and re-queries from offset 0 on any filter/sort/search change.

**Tech Stack:** PHP (`system/lib/ork3/class.Report.php`, `orkui/controller/controller.Recommendations.php`), plain-PHP `.tpl` templates, vanilla JS (IntersectionObserver, fetch). No PHP test harness — verification is `php -l`, curl against the running app (port 19080), and browser checks.

**Spec:** `docs/superpowers/specs/2026-06-16-recs-manager-server-pagination-design.md`

---

## Conventions (project rules)
- `.tpl` is plain PHP. Tab-indented templates → use Python `replace` for multi-line edits, Edit for unique single lines.
- `$DB->Clear()` before raw Execute/DataSet. Read flag config with `(int)Value===1` where relevant.
- Dark mode via `html[data-theme="dark"]`; `data-tip` not native `title`; no native confirm/alert.
- Stage files explicitly; never `git add -A`; never stage `class.Authorization.php`. `git diff --cached` before commit.
- Local curl-auth: login via `Login/login` (any password — bypass), reuse ONE cookie jar, single block (single-device sessions). App container `ork3-php8-app`; 500s show in `docker logs ork3-php8-app`.

## Documented compromises (from spec; keep these in mind)
- **Master-peerage coverage:** the page-selection SQL approximates `AlreadyHas` via the `kacount`/`awcount` award subqueries; the *exact* `AlreadyHas` (which also counts a Master peerage covering a ladder rec) is computed during hydration and re-applied as a PHP post-filter. Net effect: a batch may occasionally return a few **fewer** than 500 rows (rare master-covered recs dropped from `open`/added to `ator`), and the `Total` for `open`/`ator` may be off by that handful. Acceptable — "Showing N of M" is a close indicator, and batches are "≤500", not exactly 500.
- **Support-count sort** uses an SQL distinct-supporter subquery (recommended_by + recommendation_seconds, minus the recipient); it matches the PHP support count closely (the PHP version additionally subtracts the single original recommender — see Task 2 Step 4 for the matching `-1` adjustment in SQL).

## File structure
- Modify: `system/lib/ork3/class.Report.php` — add `groupRecommendations()` helper (Task 1) + `PlayerAwardRecommendationsPage()` (Task 2); add an optional cluster-restriction + no-cache path to the existing hydration (Task 2).
- Create: `orkui/template/revised-frontend/_rm_row.tpl` — shared row markup (Task 3).
- Modify: `orkui/controller/controller.Recommendations.php` — use the grouping helper, add `rows()` endpoint, change `manage()` to first-batch (Tasks 1, 4, 5).
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` — render via partial, JS rewrite, eligibility options (Tasks 3, 6).

Tasks are sequential (each builds on the prior).

---

### Task 1: Extract the grouping logic into a shared helper

Today the cluster-grouping lives inline in `controller.Recommendations.php::manage()` (lines 73-127). The page method needs the identical grouping, so extract it once.

**Files:** Modify `system/lib/ork3/class.Report.php` (add method), `orkui/controller/controller.Recommendations.php` (call it).

- [ ] **Step 1: Add `groupRecommendations()` to `class.Report.php`**

Add this public method (place it just before `PlayerAwardRecommendations`):
```php
	/**
	 * Collapse parallel recommendations into one row per (recipient, kingdomaward, rank)
	 * cluster. Pure transform of the row array returned by PlayerAwardRecommendations.
	 * Returns array_values of the grouped rows (same shape the Manager template expects).
	 */
	public function groupRecommendations($recs) {
		$groups = [];
		foreach ((array)$recs as $rec) {
			$mid  = (int)($rec['MundaneId'] ?? 0);
			$kaid = (int)($rec['KingdomAwardId'] ?? 0);
			$rank = (int)($rec['Rank'] ?? 0);
			$key  = $mid . ':' . $kaid . ':' . $rank;
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'MundaneId'      => $mid,
					'KingdomAwardId' => $kaid,
					'Rank'           => $rank,
					'Persona'        => $rec['Persona'] ?? '',
					'AwardName'      => $rec['AwardName'] ?? '',
					'ParkId'         => (int)($rec['ParkId'] ?? 0),
					'AlreadyHas'     => !empty($rec['AlreadyHas']),
					'CurrentRank'    => isset($rec['CurrentRank']) ? (int)$rec['CurrentRank'] : null,
					'Members'        => [],
					'MemberRecIds'   => [],
					'OldestAgeDays'  => 0,
					'OldestDate'     => $rec['DateRecommended'] ?? '',
					'RepRecId'       => (int)($rec['RecommendationsId'] ?? 0),
					'_advocates'     => [],
					'_hasNamedRec'   => false,
					'_allSnoozed'    => true,
					'_allPassed'     => true,
				];
			}
			$g = &$groups[$key];
			$g['Members'][]      = $rec;
			$g['MemberRecIds'][] = (int)($rec['RecommendationsId'] ?? 0);
			$age = (int)($rec['AgeDays'] ?? 0);
			if ($age >= $g['OldestAgeDays']) {
				$g['OldestAgeDays'] = $age;
				$g['OldestDate']    = $rec['DateRecommended'] ?? '';
				$g['RepRecId']      = (int)($rec['RecommendationsId'] ?? 0);
			}
			if (!empty($rec['RecommendedById'])) { $g['_advocates'][(int)$rec['RecommendedById']] = true; $g['_hasNamedRec'] = true; }
			foreach (($rec['Seconds'] ?? []) as $s) {
				if (!empty($s['SupporterMundaneId'])) { $g['_advocates'][(int)$s['SupporterMundaneId']] = true; }
			}
			if (empty($rec['IsSnoozed'])) { $g['_allSnoozed'] = false; }
			if (empty($rec['PassedToLocal'])) { $g['_allPassed'] = false; }
			unset($g);
		}
		foreach ($groups as $k => $g) {
			unset($g['_advocates'][$g['MundaneId']]);
			$groups[$k]['SupportCount']  = max(0, count($g['_advocates']) - ($g['_hasNamedRec'] ? 1 : 0));
			$groups[$k]['IsSnoozed']     = $g['_allSnoozed'];
			$groups[$k]['PassedToLocal'] = $g['_allPassed'];
			unset($groups[$k]['_advocates'], $groups[$k]['_hasNamedRec'], $groups[$k]['_allSnoozed'], $groups[$k]['_allPassed']);
		}
		return array_values($groups);
	}
```

- [ ] **Step 2: Use it in `manage()`** — replace the inline grouping (controller lines 73-127, the `$groups = []; foreach … ` block through `$this->data['Groups'] = array_values($groups);`) with:
```php
		$this->data['Groups'] = Ork3::$Lib->report->groupRecommendations($recs);
```
(Confirm the model accessor is `Ork3::$Lib->report` — grep `class.Report.php` for how it's registered; if the controller already has `$this->load_model('Reports')`, you may instead call a model passthrough. Use whichever the codebase exposes for `Report` methods — check an existing `Ork3::$Lib->report->` call or `$this->Reports->`. If unsure, add a `group_recommendations($recs)` passthrough to `orkui/model/model.Reports.php` that calls `$this->Report->groupRecommendations($recs)` and call `$this->Reports->group_recommendations($recs)`.)

- [ ] **Step 3: Lint + verify no behavior change**
```bash
php -l system/lib/ork3/class.Report.php
php -l orkui/controller/controller.Recommendations.php
```
Both `No syntax errors detected`. Then load `Recommendations/manage/park/76` in the browser (or curl-auth) and confirm the grid still renders identically (same row count, grouping) — this task is a pure refactor.

- [ ] **Step 4: Commit**
```bash
git add system/lib/ork3/class.Report.php orkui/controller/controller.Recommendations.php orkui/model/model.Reports.php
git diff --cached   # confirm no Authorization.php
git commit -m "Refactor: extract rec cluster-grouping into Report::groupRecommendations()"
```

---

### Task 2: `Report::PlayerAwardRecommendationsPage()` — paged data layer

**Files:** Modify `system/lib/ork3/class.Report.php`.

This adds (a) a way to hydrate a SPECIFIC set of clusters reusing the existing row-building, and (b) the page method that picks clusters, hydrates, groups, post-filters, and counts.

- [ ] **Step 1: Make the existing hydration reusable for a cluster subset**

Refactor `PlayerAwardRecommendations($request)` so the SQL `WHERE` can be restricted to an explicit list of recommendation ids and caching can be bypassed. Minimal change: accept two optional request keys and thread them in.

At the top of the method, after `$viewer_id = …`, add:
```php
		$recIdList = isset($request['RecommendationsIdIn']) ? (array)$request['RecommendationsIdIn'] : null;
		$skipCache = !empty($request['SkipCache']) || $recIdList !== null;
```
Guard the cache read/write with `if (!$skipCache)` (wrap the existing `if (($cache = …) !== false) return …;` and the final `$cached = …->cache(…)` so they only run when `!$skipCache`; when `$skipCache`, set `$cached = $response;` before the final `return $this->applyViewerFlags($cached, $viewer_id);`).

In the SQL `WHERE`, after the `$location_clause`, add a recommendation-id restriction:
```php
		$idClause = '';
		if ($recIdList !== null) {
			$ids = array_filter(array_map('intval', $recIdList));
			$idClause = empty($ids) ? ' AND 1=0 ' : ' AND recs.recommendations_id IN (' . implode(',', $ids) . ') ';
		}
```
and append `$idClause` to the WHERE (right after `$location_clause`). Behavior is unchanged for existing callers (both new keys absent → `$recIdList=null`, `$skipCache=false`, `$idClause=''`).

- [ ] **Step 2: Add the page-selection query + method**

Add this public method to `class.Report.php`:
```php
	/**
	 * One 500-row (cluster) page of Manager recommendations, fully server-side
	 * filtered/sorted. Never selects the full set. Returns:
	 *   ['Groups' => [...≤Limit grouped rows...], 'Total' => int, 'HasMore' => bool]
	 *
	 * $request: KingdomId|ParkId (scope), RequestedBy, plus
	 *   Search, Eligibility(open|below|ator|nonladder|all|snoozed),
	 *   Court(all|none|any|court:<id>), Park('all'|<id>), PassLocal(bool),
	 *   SortKey(recip|award|date|supp), SortDir(asc|desc), Limit(=500), Offset.
	 */
	public function PlayerAwardRecommendationsPage($request) {
		$limit  = max(1, (int)($request['Limit']  ?? 500));
		$offset = max(0, (int)($request['Offset'] ?? 0));

		// ---- scope clause (mirror PlayerAwardRecommendations) ----
		$scope = '';
		if (valid_id($request['KingdomId'] ?? 0)) {
			$kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
			$scope = " AND m.kingdom_id IN ($kidList)";
		} elseif (valid_id($request['ParkId'] ?? 0)) {
			$scope = ' AND m.park_id = ' . (int)$request['ParkId'];
		}

		// ---- filter clauses (SQL-expressible) ----
		$where = [];
		$search = trim((string)($request['Search'] ?? ''));
		if ($search !== '') {
			$where[] = "m.persona LIKE '%" . $this->db->escape($search) . "%'";
		}
		if (($request['Park'] ?? 'all') !== 'all' && valid_id($request['Park'] ?? 0)) {
			$where[] = 'm.park_id = ' . (int)$request['Park'];
		}
		if (!empty($request['PassLocal'])) {
			$where[] = 'recs.passed_to_local = 1';
		}
		// court filter
		$court = (string)($request['Court'] ?? 'all');
		if ($court === 'none')      $where[] = '(SELECT COUNT(*) FROM ' . DB_PREFIX . "court_award ca WHERE ca.recommendations_id = recs.recommendations_id AND ca.status != 'cancelled') = 0";
		elseif ($court === 'any')   $where[] = '(SELECT COUNT(*) FROM ' . DB_PREFIX . "court_award ca WHERE ca.recommendations_id = recs.recommendations_id AND ca.status != 'cancelled') > 0";
		elseif (strpos($court, 'court:') === 0) {
			$cid = (int)substr($court, 6);
			$where[] = 'EXISTS (SELECT 1 FROM ' . DB_PREFIX . "court_award ca WHERE ca.recommendations_id = recs.recommendations_id AND ca.court_id = $cid AND ca.status != 'cancelled')";
		}
		// snoozed expression (matches PlayerAwardRecommendations: snoozed officer ids == current officer ids)
		$snoozedExpr = "(recs.snoozed_monarch_id IS NOT NULL"
			. " AND recs.snoozed_monarch_id = (SELECT COALESCE(MAX(CASE WHEN role='Monarch' THEN mundane_id END),0) FROM " . DB_PREFIX . "officer WHERE park_id = m.park_id)"
			. " AND recs.snoozed_regent_id  = (SELECT COALESCE(MAX(CASE WHEN role='Regent'  THEN mundane_id END),0) FROM " . DB_PREFIX . "officer WHERE park_id = m.park_id))";
		// already-has expression (SQL approximation: kacount/awcount; master-peerage refined in PHP at hydrate)
		$alreadyExpr = "((SELECT COUNT(*) FROM " . DB_PREFIX . "awards oa WHERE oa.mundane_id = recs.mundane_id AND oa.kingdomaward_id = ka.kingdomaward_id AND oa.rank >= COALESCE(recs.rank,0)) > 0"
			. " OR (SELECT COUNT(*) FROM " . DB_PREFIX . "awards oa2 WHERE oa2.mundane_id = recs.mundane_id AND oa2.award_id = recs.award_id AND oa2.rank >= COALESCE(recs.rank,0)) > 0)";
		// is-custom (held many times; never "already has"): base award not ladder and not title
		$customExpr = '(a.is_ladder = 0 AND a.is_title = 0)';

		$elig = (string)($request['Eligibility'] ?? 'open');
		if ($elig === 'snoozed') {
			$where[] = $snoozedExpr;
		} elseif ($elig === 'nonladder') {
			$where[] = 'COALESCE(recs.rank,0) = 0';
			$where[] = "NOT $snoozedExpr";
		} elseif ($elig === 'ator') {
			$where[] = "NOT $customExpr AND $alreadyExpr";
			$where[] = "NOT $snoozedExpr";
		} elseif ($elig === 'below') {
			$where[] = "(COALESCE(recs.rank,0) > 0) AND ($customExpr OR NOT $alreadyExpr)";
			$where[] = "NOT $snoozedExpr";
		} elseif ($elig === 'open') {
			// pending: hide already-has and snoozed (custom awards are always "open")
			$where[] = "($customExpr OR NOT $alreadyExpr)";
			$where[] = "NOT $snoozedExpr";
		} // 'all' => no eligibility restriction

		$whereSql = $where ? (' AND ' . implode(' AND ', $where)) : '';

		// ---- support-count subquery (distinct backers, minus recipient, minus 1 for the original named rec) ----
		$supportSub = "(SELECT COUNT(DISTINCT adv) FROM ("
			. "  SELECT recommended_by_id AS adv FROM " . DB_PREFIX . "recommendations r2 WHERE r2.mundane_id = recs.mundane_id AND r2.kingdomaward_id = recs.kingdomaward_id AND COALESCE(r2.rank,0)=COALESCE(recs.rank,0) AND r2.recommended_by_id IS NOT NULL AND (r2.deleted_by IS NULL OR r2.deleted_by=0)"
			. "  UNION SELECT s.supporter_mundane_id AS adv FROM " . DB_PREFIX . "recommendation_seconds s JOIN " . DB_PREFIX . "recommendations r3 ON r3.recommendations_id = s.recommendations_id WHERE r3.mundane_id = recs.mundane_id AND r3.kingdomaward_id = recs.kingdomaward_id AND COALESCE(r3.rank,0)=COALESCE(recs.rank,0) AND s.deleted_at IS NULL"
			. ") adv_t WHERE adv <> recs.mundane_id)";

		// ---- ORDER BY ----
		$dir = (strtolower((string)($request['SortDir'] ?? 'desc')) === 'asc') ? 'ASC' : 'DESC';
		switch ((string)($request['SortKey'] ?? 'date')) {
			case 'recip': $order = "m.persona $dir, award_name ASC, COALESCE(recs.rank,0) ASC"; break;
			case 'award': $order = "award_name $dir, COALESCE(recs.rank,0) $dir, m.persona ASC"; break;
			case 'supp':  $order = "support_count $dir, MIN(recs.date_recommended) DESC"; break;
			case 'date':
			default:      $order = "MIN(recs.date_recommended) $dir, m.persona ASC"; break;
		}

		$base = " FROM " . DB_PREFIX . "recommendations recs"
			. " LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = recs.kingdomaward_id"
			. " LEFT JOIN " . DB_PREFIX . "award a ON a.award_id = ka.award_id"
			. " LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = recs.mundane_id"
			. " WHERE (recs.deleted_by IS NULL OR recs.deleted_by = 0)"
			. " AND m.active = 1 AND (m.suspended IS NULL OR m.suspended = 0)"
			. $scope . $whereSql;

		// ---- Total (distinct clusters matching the filters) ----
		$this->db->Clear();
		$cnt = $this->db->query("SELECT COUNT(*) AS n FROM (SELECT recs.mundane_id $base GROUP BY recs.mundane_id, recs.kingdomaward_id, COALESCE(recs.rank,0)) t");
		$total = ($cnt !== false && $cnt->next()) ? (int)$cnt->n : 0;

		// ---- Page: the cluster keys for this batch ----
		$this->db->Clear();
		$pageSql = "SELECT recs.mundane_id, recs.kingdomaward_id, COALESCE(recs.rank,0) AS rk,"
			. " MIN(ifnull(ka.name, a.name)) AS award_name, MIN(recs.date_recommended) AS oldest,"
			. " $supportSub AS support_count,"
			. " MIN(recs.recommendations_id) AS any_rec_id"
			. " $base"
			. " GROUP BY recs.mundane_id, recs.kingdomaward_id, COALESCE(recs.rank,0)"
			. " ORDER BY $order"
			. " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
		$pr = $this->db->query($pageSql);
		$clusters = [];     // ordered list of [mundane_id, kingdomaward_id, rank]
		$recIds   = [];     // representative rec ids -> to fetch the full clusters' rec ids
		if ($pr !== false) {
			while ($pr->next()) {
				$clusters[] = [(int)$pr->mundane_id, (int)$pr->kingdomaward_id, (int)$pr->rk];
			}
		}
		if (empty($clusters)) {
			return ['Groups' => [], 'Total' => $total, 'HasMore' => false];
		}

		// ---- Collect ALL rec ids belonging to the page's clusters (so a cluster is hydrated whole) ----
		$orParts = [];
		foreach ($clusters as $c) {
			$orParts[] = '(recs.mundane_id = ' . $c[0] . ' AND recs.kingdomaward_id = ' . $c[1] . ' AND COALESCE(recs.rank,0) = ' . $c[2] . ')';
		}
		$this->db->Clear();
		$ir = $this->db->query("SELECT recs.recommendations_id FROM " . DB_PREFIX . "recommendations recs LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = recs.mundane_id WHERE (recs.deleted_by IS NULL OR recs.deleted_by = 0) AND (" . implode(' OR ', $orParts) . ")");
		$pageRecIds = [];
		if ($ir !== false) { while ($ir->next()) { $pageRecIds[] = (int)$ir->recommendations_id; } }

		// ---- Hydrate those recs with the EXISTING row-building (exact AlreadyHas/snoozed/seconds/master) ----
		$hyd = $this->PlayerAwardRecommendations([
			'KingdomId' => (int)($request['KingdomId'] ?? 0),
			'ParkId'    => (int)($request['ParkId'] ?? 0),
			'PlayerId'  => 0,
			'RequestedBy' => (int)($request['RequestedBy'] ?? 0),
			'RecommendationsIdIn' => $pageRecIds,
			'SkipCache' => true,
		]);
		$rows = is_array($hyd) && isset($hyd['AwardRecommendations']) ? $hyd['AwardRecommendations'] : [];

		// ---- Group (shared helper) ----
		$groups = $this->groupRecommendations($rows);

		// ---- Exact eligibility post-filter (covers master-peerage cases SQL approximated) ----
		$groups = array_values(array_filter($groups, function ($g) use ($elig) {
			$already  = !empty($g['AlreadyHas']);
			$snoozed  = !empty($g['IsSnoozed']);
			$rank     = (int)$g['Rank'];
			switch ($elig) {
				case 'all':       return true;
				case 'snoozed':   return $snoozed;
				case 'nonladder': return $rank === 0 && !$snoozed;
				case 'ator':      return $already && !$snoozed;
				case 'below':     return $rank > 0 && !$already && !$snoozed;
				case 'open':
				default:          return !$already && !$snoozed;
			}
		}));

		// ---- Re-order groups to match the page query's cluster order ----
		$pos = [];
		foreach ($clusters as $i => $c) { $pos[$c[0] . ':' . $c[1] . ':' . $c[2]] = $i; }
		usort($groups, function ($x, $y) use ($pos) {
			$kx = $x['MundaneId'] . ':' . $x['KingdomAwardId'] . ':' . $x['Rank'];
			$ky = $y['MundaneId'] . ':' . $y['KingdomAwardId'] . ':' . $y['Rank'];
			return ($pos[$kx] ?? 1e9) <=> ($pos[$ky] ?? 1e9);
		});

		$hasMore = ($offset + count($clusters)) < $total;
		return ['Groups' => $groups, 'Total' => $total, 'HasMore' => $hasMore];
	}
```

Notes:
- `$this->db->escape()` — confirm the DB wrapper's escape method name (grep `class.Report.php`/the DB lib for `->escape(` or `addslashes`; if no `escape`, use `addslashes()` on the search term, which the codebase uses elsewhere).
- The hydrate path calls `PlayerAwardRecommendations` with `RecommendationsIdIn` + `SkipCache` (added in Step 1), so it reuses ALL exact-flag logic for just the page's recs.

- [ ] **Step 3: Lint**
```bash
php -l system/lib/ork3/class.Report.php
```
Expected `No syntax errors detected`.

- [ ] **Step 4: Synthetic curl test of the data layer (via a temporary debug route or tinker)**

Because there's no PHP REPL wired, validate through the endpoint in Task 4 instead. For now, only lint. (Acceptance of Task 2 is proven end-to-end in Task 7.)

- [ ] **Step 5: Commit**
```bash
git add system/lib/ork3/class.Report.php
git diff --cached
git commit -m "Recs Manager: add Report::PlayerAwardRecommendationsPage() (server-side cluster paging)"
```

---

### Task 3: Shared row partial `_rm_row.tpl`

**Files:** Create `orkui/template/revised-frontend/_rm_row.tpl`; Modify `Recommendations_manage.tpl` (use it).

- [ ] **Step 1: Extract the row markup**

Read the current row block in `Recommendations_manage.tpl` — the `<?php foreach ($Groups as $group) { ?>` … `<?php } ?>` (starts ~line 559; the `<tr class="rm-row" …>` is ~line 600). Move the BODY of the loop (everything the loop emits for one `$group`, including the per-group PHP setup vars like `$gRank`, `$isLad`, `$elig`, `$snoozed`, `$support`, `$courtJson`, `$gpayload`, `$membersJson`, etc., and the `<tr class="rm-row">…</tr>` plus any detail row) into a new file `orkui/template/revised-frontend/_rm_row.tpl`. The partial expects `$group` (one group), `$CourtMap`, `$Parks`, `$Context`, `$ParkId`, `$KingdomId` in scope.

Replace the loop body in `Recommendations_manage.tpl` with:
```php
<?php foreach ($Groups as $group) { include __DIR__ . '/_rm_row.tpl'; } ?>
```

- [ ] **Step 2: Lint both**
```bash
php -l orkui/template/revised-frontend/Recommendations_manage.tpl
php -l orkui/template/revised-frontend/_rm_row.tpl
```
Both clean.

- [ ] **Step 3: Verify visually** — reload `Recommendations/manage/park/76`; the grid renders exactly as before (pure refactor).

- [ ] **Step 4: Commit**
```bash
git add orkui/template/revised-frontend/_rm_row.tpl orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Recs Manager: extract row markup into shared _rm_row.tpl partial"
```

---

### Task 4: `rows()` AJAX endpoint

**Files:** Modify `orkui/controller/controller.Recommendations.php`.

- [ ] **Step 1: Add the `rows()` action**

Add to `Controller_Recommendations` (mirror `manage()`'s context/auth parsing). It reads filter/sort/offset from `$_GET`, calls the page method, renders each group through the partial into an HTML string, and echoes JSON.
```php
	// Route: ?Route=Recommendations/rows/kingdom/{id} or /rows/park/{id}  (GET: filters/sort/offset)
	public function rows($context = null, $id = null) {
		if ($id === null && $context !== null && strpos($context, '/') !== false) {
			$parts = explode('/', $context, 2); $context = $parts[0]; $id = $parts[1] ?? '';
		}
		$id      = (int)preg_replace('/[^0-9]/', '', $id ?? '');
		$context = ($context === 'park') ? 'park' : 'kingdom';
		$uid     = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

		$kingdom_id = 0; $park_id = 0;
		global $DB;
		if ($context === 'park') {
			$park_id = $id; $DB->Clear();
			$pr = $DB->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1');
			if ($pr && $pr->Next()) $kingdom_id = (int)$pr->kingdom_id;
		} else { $kingdom_id = $id; }

		header('Content-Type: application/json');
		if (!valid_id($kingdom_id) || !Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
			http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
		}

		$req = [
			'RequestedBy' => $uid,
			'KingdomId'   => $park_id > 0 ? 0 : $kingdom_id,
			'ParkId'      => $park_id,
			'Search'      => (string)($_GET['search'] ?? ''),
			'Eligibility' => (string)($_GET['elig'] ?? 'open'),
			'Court'       => (string)($_GET['court'] ?? 'all'),
			'Park'        => (string)($_GET['park'] ?? 'all'),
			'PassLocal'   => !empty($_GET['passlocal']),
			'SortKey'     => (string)($_GET['sort'] ?? 'date'),
			'SortDir'     => (string)($_GET['dir'] ?? 'desc'),
			'Limit'       => 500,
			'Offset'      => max(0, (int)($_GET['offset'] ?? 0)),
		];
		$page = Ork3::$Lib->report->PlayerAwardRecommendationsPage($req);

		// Render rows via the shared partial.
		$CourtMap  = Ork3::$Lib->court->getRecommendationCourtMap($kingdom_id, $park_id);
		$Parks     = []; $DB->Clear();
		$prs = $DB->DataSet('SELECT park_id, name, abbreviation FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ' . (int)$kingdom_id . ' ORDER BY name ASC');
		if ($prs) { while ($prs->Next()) { $Parks[(int)$prs->park_id] = ['Name' => $prs->name, 'Abbrev' => $prs->abbreviation]; } }
		$Context = $context; $ParkId = $park_id; $KingdomId = $kingdom_id;

		$html = '';
		foreach ($page['Groups'] as $group) {
			ob_start();
			include dirname(__DIR__) . '/template/revised-frontend/_rm_row.tpl';
			$html .= ob_get_clean();
		}
		echo json_encode([
			'html'    => $html,
			'total'   => (int)$page['Total'],
			'hasMore' => (bool)$page['HasMore'],
			'offset'  => $req['Offset'] + count($page['Groups']),
		]);
		exit;
	}
```
Notes:
- Confirm the include path to `_rm_row.tpl` resolves from the controller (`dirname(__DIR__) . '/template/revised-frontend/_rm_row.tpl'` assumes controller is in `orkui/controller/`; adjust to the real template root — grep how other controllers locate templates, or reuse `DIR_TEMPLATE` if defined: `DIR_TEMPLATE . 'revised-frontend/_rm_row.tpl'`).
- Confirm `Ork3::$Lib->report->` is the correct accessor (same one used in Task 1); if the project uses `$this->Reports->` model passthrough, add a `recommended_awards_page($req)` passthrough in `model.Reports.php` and call that instead.

- [ ] **Step 2: Lint**
```bash
php -l orkui/controller/controller.Recommendations.php
```

- [ ] **Step 3: Curl-test the endpoint** (the first real end-to-end check of Tasks 1-4)
```bash
# login (any password — bypass) into a cookie jar, then hit the endpoint
J=/tmp/rmcj.txt
curl -s -c $J -b $J "http://localhost:19080/orkui/index.php?Route=Login/login" -d 'username=Ginevra_GWK&password=x' >/dev/null
echo "--- offset 0 (open, date desc): count + total + hasMore ---"
curl -s -b $J "http://localhost:19080/orkui/index.php?Route=Recommendations/rows/park/76&offset=0&elig=open&sort=date&dir=desc" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('rows', d['html'].count('class=\"rm-row\"'), 'total', d['total'], 'hasMore', d['hasMore'], 'nextOffset', d['offset'])"
echo "--- offset 500 (next batch) ---"
curl -s -b $J "http://localhost:19080/orkui/index.php?Route=Recommendations/rows/park/76&offset=500&elig=open&sort=date&dir=desc" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('rows', d['html'].count('class=\"rm-row\"'), 'hasMore', d['hasMore'])"
echo "--- elig=all total (should be >= open total) ---"
curl -s -b $J "http://localhost:19080/orkui/index.php?Route=Recommendations/rows/park/76&offset=0&elig=all" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('all total', d['total'], 'rows', d['html'].count('class=\"rm-row\"'))"
```
Expected: offset 0 returns ≤500 `rm-row`s, a sensible `total` (~hundreds/thousands), `hasMore=true` when total>500; offset 500 returns the next batch; `elig=all` total ≥ `elig=open` total. If a 500 error: `docker logs --tail=40 ork3-php8-app`.

- [ ] **Step 4: Commit**
```bash
git add orkui/controller/controller.Recommendations.php orkui/model/model.Reports.php
git diff --cached
git commit -m "Recs Manager: add Recommendations/rows JSON endpoint for paged batches"
```

---

### Task 5: `manage()` renders only the first batch

**Files:** Modify `orkui/controller/controller.Recommendations.php`, `Recommendations_manage.tpl`.

- [ ] **Step 1: Switch `manage()` to the page method (first batch)**

Replace the full-set fetch + grouping in `manage()` (the `$recs = $this->Reports->recommended_awards($req); … $this->data['Groups'] = …` block, post-Task-1) with a first-batch call using the defaults (eligibility `open`, sort `date` desc, offset 0):
```php
		$page = Ork3::$Lib->report->PlayerAwardRecommendationsPage([
			'RequestedBy' => $uid,
			'KingdomId'   => $park_id > 0 ? 0 : $kingdom_id,
			'ParkId'      => $park_id,
			'Eligibility' => 'open',
			'SortKey'     => 'date',
			'SortDir'     => 'desc',
			'Limit'       => 500,
			'Offset'      => 0,
		]);
		$this->data['Groups']   = $page['Groups'];
		$this->data['Total']    = (int)$page['Total'];
		$this->data['HasMore']  = (bool)$page['HasMore'];
		$this->data['NextOffset'] = count($page['Groups']);
```
Remove the now-unused `$this->data['Recommendations'] = $recs;` (or set it to `[]`). Keep `$CourtMap`, `$Courts`, `$Parks` as-is.

- [ ] **Step 2: Template — count display + config**

In `Recommendations_manage.tpl`: the context line that shows total pending and the footer `#rm-count` must reflect server totals. Change the footer (line ~658) to a "Showing N of M" form:
```php
  <div class="rm-foot">Showing <span id="rm-count"><?= count($Groups) ?></span> of <span id="rm-total"><?= (int)($Total ?? 0) ?></span> &middot; <span id="rm-selcount">0</span> selected</div>
```
Extend the inline `window.RmConfig = { … }` (line ~714) with the routing + initial paging state:
```php
	rowsUrl:   '<?= UIR ?>Recommendations/rows/<?= $Context ?>/<?= $Context === 'park' ? (int)$ParkId : (int)$KingdomId ?>',
	total:     <?= (int)($Total ?? 0) ?>,
	hasMore:   <?= !empty($HasMore) ? 'true' : 'false' ?>,
	nextOffset:<?= (int)($NextOffset ?? 0) ?>,
```

- [ ] **Step 3: Lint + browser** — `php -l` both; load the Manager; first 500 rows render; "Showing 500 of M".

- [ ] **Step 4: Commit**
```bash
git add orkui/controller/controller.Recommendations.php orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Recs Manager: render only the first 500-row batch on initial load"
```

---

### Task 6: Client JS — infinite scroll + server-side filter/sort + eligibility options

**Files:** Modify `orkui/template/revised-frontend/Recommendations_manage.tpl`.

- [ ] **Step 1: Eligibility dropdown options**

Replace the `<select id="rm-filter-elig">` options (line ~517) with the pill set (default `open`):
```php
    <select id="rm-filter-elig" class="rm-fsel">
      <option value="open" selected>Open Recs</option>
      <option value="below">Below Rec&rsquo;d</option>
      <option value="nonladder">Non-Ladder</option>
      <option value="ator">At or Above Rec&rsquo;d</option>
      <option value="all">All</option>
      <option value="snoozed">Snoozed</option>
    </select>
```

- [ ] **Step 2: Replace client-side filter/sort with server fetch**

The functions `rmApplyFilters()` (line ~772) and `rmSort()` (line ~841) currently mutate DOM visibility/order over the full set. Re-wire them to drive a server fetch. Add this state + fetch core (place after the `RM` helper, ~line 770), and repoint the existing input/sort listeners to it:
```javascript
var rmState = {
	search: '', elig: 'open', court: 'all', park: 'all', passlocal: false,
	sort: 'date', dir: 'desc',
	offset: RmConfig.nextOffset || 0, total: RmConfig.total || 0,
	hasMore: !!RmConfig.hasMore, loading: false,
	seen: {} // cluster key -> true (de-dupe appended batches)
};
function rmReadFilters() {
	rmState.search = (document.getElementById('rm-search').value || '').trim();
	rmState.elig   = document.getElementById('rm-filter-elig').value;
	rmState.court  = document.getElementById('rm-filter-court').value;
	var pk = document.getElementById('rm-filter-park'); rmState.park = pk ? pk.value : 'all';
	rmState.passlocal = document.getElementById('rm-filter-passlocal').checked;
}
function rmRowKey(tr) { return tr.getAttribute('data-rec-cluster') || tr.getAttribute('data-rec-id'); }
function rmIndexSeen() { rmState.seen = {}; RM.rows().forEach(function (tr) { rmState.seen[rmRowKey(tr)] = true; }); }
function rmFetch(reset) {
	if (rmState.loading) return;
	rmState.loading = true;
	if (reset) { rmState.offset = 0; }
	var q = new URLSearchParams({
		search: rmState.search, elig: rmState.elig, court: rmState.court,
		park: rmState.park, passlocal: rmState.passlocal ? '1' : '', sort: rmState.sort,
		dir: rmState.dir, offset: String(rmState.offset)
	});
	var tbody = document.getElementById('rm-tbody');
	document.getElementById('rm-loading').style.display = '';
	fetch(RmConfig.rowsUrl + (RmConfig.rowsUrl.indexOf('?') >= 0 ? '&' : '?') + q.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (reset) { tbody.innerHTML = ''; rmState.seen = {}; }
			var tmp = document.createElement('tbody'); tmp.innerHTML = d.html;
			Array.prototype.slice.call(tmp.children).forEach(function (tr) {
				if (!tr.classList || !tr.classList.contains('rm-row')) { tbody.appendChild(tr); return; }
				var k = rmRowKey(tr);
				if (k && rmState.seen[k]) return;       // de-dupe
				if (k) rmState.seen[k] = true;
				tbody.appendChild(tr);
			});
			rmState.offset  = d.offset;
			rmState.total   = d.total;
			rmState.hasMore = d.hasMore;
			document.getElementById('rm-count').textContent = RM.rows().length;
			document.getElementById('rm-total').textContent = d.total;
			rmUpdateSelCount();
		})
		.catch(function () { rmToast('Failed to load.', true); })
		.finally(function () {
			rmState.loading = false;
			document.getElementById('rm-loading').style.display = 'none';
		});
}
// search (debounced) + filter changes -> reset fetch
var rmDeb;
['rm-search', 'rm-filter-elig', 'rm-filter-court', 'rm-filter-park'].forEach(function (idv) {
	var el = document.getElementById(idv); if (!el) return;
	el.addEventListener('input', function () { rmReadFilters(); clearTimeout(rmDeb); rmDeb = setTimeout(function () { rmFetch(true); }, 250); });
	el.addEventListener('change', function () { rmReadFilters(); clearTimeout(rmDeb); rmFetch(true); });
});
document.getElementById('rm-filter-passlocal').addEventListener('change', function () { rmReadFilters(); rmFetch(true); });
// sort headers -> set sort + reset fetch
document.querySelectorAll('.rm-sortable').forEach(function (th) {
	th.addEventListener('click', function () {
		var key = th.getAttribute('data-sort');
		if (rmState.sort === key) rmState.dir = (rmState.dir === 'asc') ? 'desc' : 'asc';
		else { rmState.sort = key; rmState.dir = (key === 'date') ? 'desc' : 'asc'; }
		document.querySelectorAll('.rm-sortable').forEach(function (t) { t.classList.remove('rm-sort-asc', 'rm-sort-desc'); });
		th.classList.add(rmState.dir === 'asc' ? 'rm-sort-asc' : 'rm-sort-desc');
		rmFetch(true);
	});
});
```
Then DELETE the old `rmApplyFilters()` body and the old `rmSort()` (and the old listener-binding block at ~821 and the sort-binding at ~859) — but KEEP any small helpers other code still calls. Specifically: code paths that call `rmApplyFilters()` after a row action (e.g. snooze/dismiss removing a row) should instead just update the counts. Replace remaining `rmApplyFilters()` call sites with a light `rmAfterRowRemoved()`:
```javascript
function rmAfterRowRemoved() {
	document.getElementById('rm-count').textContent = RM.rows().length;
	if (rmState.total > 0) { rmState.total -= 1; document.getElementById('rm-total').textContent = rmState.total; }
	rmUpdateSelCount();
}
```
(Grep the file for `rmApplyFilters()` and repoint each remaining call to `rmAfterRowRemoved()` — these are the action handlers that remove a row; do NOT trigger a full refetch on every action.)

- [ ] **Step 3: Add the loading indicator + infinite-scroll sentinel**

After the `</table>` (before `#rm-foot`), add:
```html
  <div id="rm-loading" class="rm-loading" style="display:none">Loading&hellip;</div>
  <div id="rm-sentinel" style="height:1px"></div>
```
Add minimal CSS to the inline `<style>`:
```css
.rm-loading { text-align:center; padding:14px; color:var(--rm-muted); font-size:13px; }
```
And the observer (after the fetch code):
```javascript
if ('IntersectionObserver' in window) {
	var rmObs = new IntersectionObserver(function (entries) {
		if (entries[0].isIntersecting && rmState.hasMore && !rmState.loading) rmFetch(false);
	}, { rootMargin: '400px' });
	rmObs.observe(document.getElementById('rm-sentinel'));
}
```
On init, index the server-rendered first batch so de-dupe works: call `rmIndexSeen();` once after defining state.

- [ ] **Step 4: Give rows a cluster key for de-dupe**

In `_rm_row.tpl`, add `data-rec-cluster="<?= (int)$group['MundaneId'] ?>:<?= (int)$group['KingdomAwardId'] ?>:<?= (int)$group['Rank'] ?>"` to the `<tr class="rm-row" …>`.

- [ ] **Step 5: Remove the old default-sort init call** — the template calls `rmSort('date')` on init (~line 1290). Remove it (the server already returns the default order). Confirm nothing else references the deleted `rmSort`/`rmApplyFilters` (grep).

- [ ] **Step 6: Lint + grep**
```bash
php -l orkui/template/revised-frontend/Recommendations_manage.tpl
grep -n "rmApplyFilters\|rmSort(" orkui/template/revised-frontend/Recommendations_manage.tpl   # expect no stale calls
```

- [ ] **Step 7: Commit**
```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl orkui/template/revised-frontend/_rm_row.tpl
git commit -m "Recs Manager: infinite-scroll lazy batches + server-side filter/sort + pill eligibility"
```

---

### Task 7: End-to-end verification (curl + browser)

- [ ] **Step 1: Curl matrix** (reuse the cookie jar from Task 4). Verify each filter + sort + offset:
```bash
J=/tmp/rmcj.txt
curl -s -c $J -b $J "http://localhost:19080/orkui/index.php?Route=Login/login" -d 'username=Ginevra_GWK&password=x' >/dev/null
for q in \
  "offset=0&elig=open&sort=date&dir=desc" \
  "offset=0&elig=all&sort=recip&dir=asc" \
  "offset=0&elig=ator&sort=award&dir=asc" \
  "offset=0&elig=below" "offset=0&elig=nonladder" "offset=0&elig=snoozed" \
  "offset=0&search=a" "offset=0&court=none" "offset=0&sort=supp&dir=desc" ; do
  echo -n "$q => "
  curl -s -b $J "http://localhost:19080/orkui/index.php?Route=Recommendations/rows/park/76&$q" \
    | python3 -c "import sys,json; d=json.load(sys.stdin); print('rows', d['html'].count('class=\"rm-row\"'), 'total', d['total'], 'hasMore', d['hasMore'])"
done
```
Expected: each returns ≤500 rows, a plausible total, no PHP error. `open` total ≤ `all` total. `search=a` total < `all` total. No 500s (`docker logs ork3-php8-app`).

- [ ] **Step 2: Browser (Greenwood Keep / park 76, 1920 recs), light + dark:**
  1. Manager loads fast; first 500 rows; "Showing 500 of M" (M ≈ open-count).
  2. Scroll to bottom → next 500 auto-load (Loading… flashes), repeats to end; then no more loads and `hasMore=false`.
  3. Change eligibility (All / Below / Non-Ladder / At or Above / Snoozed) → list re-queries from top; default Open hides already-has + snoozed; newest-first.
  4. Type a recipient name present only deep in the set → it appears (search hit an unloaded recipient).
  5. Click each sortable header → re-queries; toggling a header flips asc/desc.
  6. Snooze / dismiss / pass-down / add-to-court on a row still works; the row drops and "Showing N" decrements; bulk select still works.
  7. Network tab: no request ever returns more than 500 rows; no full-set query.
  8. Dark mode: rows, rank pills, loading indicator all legible.

- [ ] **Step 3: Final summary** — report curl matrix results, both lint sweeps, and browser outcomes. Flag any deviation (esp. eligibility counts vs. the old client behavior on a spot-checked recipient).

---

## Self-review notes (author)
- **Spec coverage:** server-side filter/sort/paginate by cluster (T2), never-full-query (T2 page query LIMIT + hydrate-by-cluster), 500 batches + infinite scroll (T6), eligibility pill set + Open default + newest-first (T5/T6), "Showing N of M" (T5/T6), shared row partial (T3), endpoint (T4), de-dupe on append (T6), first-batch initial load (T5). Covered.
- **Documented compromises** (master-peerage refinement → occasional <500 batch & small Open/Ator total skew; support-count SQL approximation) are called out in the plan header and Task 2.
- **Naming/consistency:** `PlayerAwardRecommendationsPage` returns `{Groups,Total,HasMore}`; endpoint JSON `{html,total,hasMore,offset}`; client `rmState`/`rmFetch`/`rmAfterRowRemoved`; row carries `data-rec-cluster`. Consistent across tasks.
- **Verify-before-claim:** Tasks 1 & 3 are pure refactors (visual parity check); the data layer is first exercised for real at Task 4 Step 3 (curl) and fully at Task 7 — no task claims success without lint + curl/browser evidence.
- **Open confirmations for the implementer** (grep to resolve, don't guess): the `Report` accessor (`Ork3::$Lib->report->` vs a `model.Reports.php` passthrough); the DB escape method name (`->escape(` vs `addslashes`); the template-root path for including `_rm_row.tpl` from the controller (`DIR_TEMPLATE` vs `dirname(__DIR__)`).
