# Recommendation Dismiss Clarity & Pass-to-Local Delegation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** (A) Standardize the recommendation-dismiss control to "Dismiss" + an explanatory tooltip everywhere; (B) let a kingdom/principality officer "pass a rec down" to the recipient's home park — a delegation flag distinguished in the recs list + Manager and surfaced to the park.

**Architecture:** A new `passed_to_local` flag on `ork_recommendations` flows through `PlayerAwardRecommendations` (cache-busted on write) so every surface renders it. A kingdom-authority-gated `class.Player::SetRecommendationPassedToLocal` toggles it via a new `KingdomAjax` endpoint; the Manager exposes it as a Thread-C group action (kingdom scope only) with a badge + filter, and the inline recs panels render the badge read-only. Part A is presentational (labels + `data-tip`).

**Tech Stack:** PHP (ork3 lib `$this->db->query(...)->next()`; model passthrough `model.Player::x → class.Player::X`), MariaDB, plain-PHP `.tpl` with inline JS, `rm-` Manager prefix.

**Verification model:** No PHP unit framework. Verify via `php -l` lint + curl-auth + DB read-back against the local DB (recommendations, parks, officers exist; Docker is up). Court pending-recs modal label change verifiable once a court is seeded (as in prior QA).

**Conventions:** `$DB->Clear()` before raw execute; `.tpl` plain PHP (no Smarty); `data-tip` not native `title`; dark-mode; stage files explicitly (never `git add -A`; never stage `class.Authorization.php`).

---

## File Structure
- **Create** `db-migrations/2026-06-11-add-recommendation-passed-to-local.sql`
- **Modify** `system/lib/ork3/class.Report.php` — `PlayerAwardRecommendations` returns `PassedToLocal` (+ by/at)
- **Modify** `system/lib/ork3/class.Player.php` — `SetRecommendationPassedToLocal`
- **Modify** `orkui/model/model.Player.php` — passthrough
- **Modify** `orkui/controller/controller.KingdomAjax.php` — `passtolocalrecommendation` endpoint
- **Modify** `orkui/controller/controller.Recommendations.php` — group `PassedToLocal`
- **Modify** `orkui/template/revised-frontend/Recommendations_manage.tpl` — Part A (dismiss tooltip) + Part B (pass-down button, badge, bulk, filter)
- **Modify** `orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl` — read-only badge
- **Modify** `orkui/template/default/Court_detail.tpl` — Part A (pending-recs dismiss label/tooltip)

---

### Task 1: Migration — `passed_to_local` columns

**Files:** Create `db-migrations/2026-06-11-add-recommendation-passed-to-local.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Pass-to-local delegation: a kingdom/principality officer delegates a recommendation
-- to the recipient's home park to award. Intent/communication signal (not enforced).
ALTER TABLE ork_recommendations
  ADD COLUMN passed_to_local    TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN passed_to_local_by INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN passed_to_local_at TIMESTAMP NULL DEFAULT NULL;
```

- [ ] **Step 2: Apply locally**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-11-add-recommendation-passed-to-local.sql`
Verify: `docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_recommendations LIKE 'passed_to_local%';"` → 3 columns.

- [ ] **Step 3: Commit**

```bash
git add db-migrations/2026-06-11-add-recommendation-passed-to-local.sql
git commit -m "Pass-to-local: add passed_to_local columns to ork_recommendations"
```

---

### Task 2: Report returns `PassedToLocal`

**Files:** Modify `system/lib/ork3/class.Report.php` (`PlayerAwardRecommendations`, ~lines 460-642)

- [ ] **Step 1: Add the columns to the SELECT**

Find (in the recommendations `$sql`, ~line 480):
```php
			recs.snoozed_monarch_id,
			recs.snoozed_regent_id,
```
Replace with:
```php
			recs.snoozed_monarch_id,
			recs.snoozed_regent_id,
			recs.passed_to_local,
			recs.passed_to_local_by,
			recs.passed_to_local_at,
```

- [ ] **Step 2: Add to the per-rec output array**

Find (~line 640):
```php
						'IsOnCourt' => $row->on_court_count > 0,
						'AgeDays'   => $ageDays,
					);
```
Replace with:
```php
						'IsOnCourt' => $row->on_court_count > 0,
						'AgeDays'   => $ageDays,
						'PassedToLocal'   => (int)$row->passed_to_local === 1,
						'PassedToLocalBy' => $row->passed_to_local_by ? (int)$row->passed_to_local_by : null,
						'PassedToLocalAt' => $row->passed_to_local_at,
					);
```

- [ ] **Step 3: Lint** — `php -l system/lib/ork3/class.Report.php` → `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Report.php
git commit -m "Pass-to-local: PlayerAwardRecommendations returns PassedToLocal"
```

---

### Task 3: `SetRecommendationPassedToLocal` + model passthrough

**Files:** Modify `system/lib/ork3/class.Player.php` (near `SnoozeAwardRecommendation`); `orkui/model/model.Player.php`

- [ ] **Step 1: Add the class method** (TAB-indented file — match tabs)

```php
	// Pass-to-local toggle: a kingdom/principality officer delegates this recommendation to
	// the recipient's home park to award (intent signal). Authority: AUTH_KINGDOM over the
	// recipient's kingdom (the principality-auth traversal also lets a parent-kingdom officer
	// through). Raw UPDATE (not yapo) so we can null the by/at columns on un-pass.
	public function SetRecommendationPassedToLocal($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

		$rec_id = (int)($request['RecommendationsId'] ?? 0);
		if (!$rec_id) return InvalidParameter();
		$passed = !empty($request['Passed']) ? 1 : 0;

		$awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
		$awardRec->clear();
		$awardRec->recommendations_id = $rec_id;
		if (!$awardRec->find()) return InvalidParameter('Recommendation not found.');

		$recipientInfo = $this->player_info($awardRec->mundane_id);
		$kingdom_id = (int)($recipientInfo['KingdomId'] ?? 0);
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE))
			return NoAuthorization();

		$this->db->Clear();
		if ($passed) {
			$this->db->query("UPDATE " . DB_PREFIX . "recommendations
				SET passed_to_local = 1, passed_to_local_by = " . (int)$mundane_id . ", passed_to_local_at = NOW()
				WHERE recommendations_id = " . $rec_id);
		} else {
			$this->db->query("UPDATE " . DB_PREFIX . "recommendations
				SET passed_to_local = 0, passed_to_local_by = NULL, passed_to_local_at = NULL
				WHERE recommendations_id = " . $rec_id);
		}

		$this->bust_player_award_recs_cache((int)$awardRec->mundane_id);
		if (isset(Ork3::$Lib->dangeraudit)) {
			Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', (int)$awardRec->mundane_id, ['passed_to_local' => $passed]);
		}
		return Success('Recommendation pass-to-local updated.');
	}
```

- [ ] **Step 2: Add the model passthrough** (`orkui/model/model.Player.php`, TAB-indented, near the other recommendation passthroughs)

```php
	function set_recommendation_passed_to_local($request) {
		return $this->Player->SetRecommendationPassedToLocal($request);
	}
```

- [ ] **Step 3: Lint** — `php -l system/lib/ork3/class.Player.php && php -l orkui/model/model.Player.php`.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Player.php orkui/model/model.Player.php
git commit -m "Pass-to-local: SetRecommendationPassedToLocal (kingdom-auth toggle)"
```

---

### Task 4: `passtolocalrecommendation` endpoint

**Files:** Modify `orkui/controller/controller.KingdomAjax.php` (alongside `dismissrecommendation`, ~line 433; TAB-indented)

- [ ] **Step 1: Add the action branch** (after the `dismissrecommendation` branch closes)

```php
		} elseif ($action === 'passtolocalrecommendation') {
			$this->load_model('Player');
			$rec_id = (int)($_POST['RecommendationsId'] ?? 0);
			if (!valid_id($rec_id)) { echo json_encode(['status' => 1, 'error' => 'Invalid recommendation.']); exit; }
			$r = $this->Player->set_recommendation_passed_to_local([
				'Token'             => $this->session->token,
				'RecommendationsId' => $rec_id,
				'Passed'            => !empty($_POST['Passed']) ? 1 : 0,
				'RequestedBy'       => $this->session->user_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
```

Note: `SetRecommendationPassedToLocal` enforces `AUTH_KINGDOM` itself, so no extra controller gate is needed (the Manager only shows the button in kingdom scope; the server still enforces).

- [ ] **Step 2: Lint** — `php -l orkui/controller/controller.KingdomAjax.php`.

- [ ] **Step 3: Local test (cluster exists locally)**

Curl-auth as a kingdom Monarch (e.g. `Neiva`, kingdom 1), POST `KingdomAjax/kingdom/1/passtolocalrecommendation` with `RecommendationsId={a real kingdom-1 rec id}&Passed=1`. Expect `{"status":0}`; DB read-back: `passed_to_local=1`, `passed_to_local_by`/`_at` set. Then `Passed=0` clears them. (Non-destructive — restore by toggling off.)

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.KingdomAjax.php
git commit -m "Pass-to-local: passtolocalrecommendation endpoint"
```

---

### Task 5: Group `PassedToLocal` in the Manager controller

**Files:** Modify `orkui/controller/controller.Recommendations.php` (the `$Groups` build added in Thread C, ~line 71; SPACE-indented)

- [ ] **Step 1: Track pass-to-local per group**

In the group-init block, add `'_allPassed' => true,` next to `'_allSnoozed' => true,`. In the per-member accumulation loop, after the `if (empty($rec['IsSnoozed'])) { $g['_allSnoozed'] = false; }` line, add:
```php
            if (empty($rec['PassedToLocal'])) { $g['_allPassed'] = false; }
```
In the finalize loop, after `$groups[$k]['IsSnoozed'] = $g['_allSnoozed'];`, add:
```php
            $groups[$k]['PassedToLocal'] = $g['_allPassed'];
```
and add `'_allPassed'` to the `unset($groups[$k]['_advocates'], $groups[$k]['_allSnoozed']);` line so it becomes:
```php
            unset($groups[$k]['_advocates'], $groups[$k]['_allSnoozed'], $groups[$k]['_allPassed']);
```

- [ ] **Step 2: Lint** — `php -l orkui/controller/controller.Recommendations.php`.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Recommendations.php
git commit -m "Pass-to-local: group-level PassedToLocal in the Manager controller"
```

---

### Task 6: Manager grid — Part A tooltip + Part B button/badge/bulk/filter

**Files:** Modify `orkui/template/revised-frontend/Recommendations_manage.tpl` (SPACE-indented). Read the current row loop, actions cell, bulk bar, filter JS, and snooze handler before editing.

- [ ] **Step 1: Part A — dismiss tooltip (per-row + bulk)**

Replace the per-row dismiss button:
```php
          <button type="button" class="rm-act rm-act-dismiss" data-tip="Dismiss">&#10005;</button>
```
with:
```php
          <button type="button" class="rm-act rm-act-dismiss" data-tip="Already given out previously? No plans to award this? You can dismiss this rec.">&#10005;</button>
```
And give the bulk Dismiss button the same tooltip — replace:
```php
    <button type="button" class="rm-bulk rm-bulk-dismiss">Dismiss</button>
```
with:
```php
    <button type="button" class="rm-bulk rm-bulk-dismiss" data-tip="Already given out previously? No plans to award this? You can dismiss this rec.">Dismiss</button>
```

- [ ] **Step 2: Part B — row data attribute + badge**

In the group row `<tr class="rm-row" ...>`, add `data-passlocal="<?= !empty($group['PassedToLocal']) ? 1 : 0 ?>"` alongside the other `data-*` attributes (e.g. right after `data-snoozed`). In the Award cell, after the "already has" badge line (`<?php if (!empty($group['AlreadyHas'])) { ?>...`), add:
```php
          <?php if (!empty($group['PassedToLocal'])) { ?><span class="rm-badge rm-badge-passlocal" data-tip="Passed to the local park to award."><i class="fas fa-arrow-down"></i> passed to local</span><?php } ?>
```

- [ ] **Step 3: Part B — pass-down button (kingdom scope only)**

In the actions cell, after the snooze button and before the dismiss button, add (only rendered in kingdom scope):
```php
          <?php if (($Context ?? '') === 'kingdom') { ?><button type="button" class="rm-act rm-act-passlocal<?= !empty($group['PassedToLocal']) ? ' rm-act-active' : '' ?>" data-tip="For recommendations at a higher level than the park can provide, you are granting authority for that park to award at this level."><i class="fas fa-arrow-down"></i></button><?php } ?>
```

- [ ] **Step 4: Part B — CSS for the badge + active button**

In the `<style>` block, after the `.rm-badge-has` rules (~line 178), add:
```css
.rm-badge-passlocal { color: #2c5f8b; background: rgba(44, 95, 139, 0.12); border-color: rgba(44, 95, 139, 0.4); }
html[data-theme="dark"] .rm-badge-passlocal { color: #6fb0e6; }
.rm-act-passlocal.rm-act-active { background: var(--rm-accent); color: #fff; border-color: var(--rm-accent); }
.rm-row[data-passlocal="1"] { box-shadow: inset 3px 0 0 var(--rm-accent); }
```

- [ ] **Step 5: Part B — pass-down toggle handler (loops members, like snooze)**

Add a tbody click handler (near the snooze handler):
```javascript
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var pl = e.target.closest('.rm-act-passlocal'); if (!pl) return;
    var tr = pl.closest('tr');
    var passed = tr.getAttribute('data-passlocal') === '1';
    var ids = rmMemberIds(tr);
    if (!ids.length) { rmToast('No recommendations found.', true); return; }
    Promise.all(ids.map(function (id) {
        var fd = new FormData(); fd.append('RecommendationsId', id); fd.append('Passed', passed ? '0' : '1');
        return rmPost(rmRecAjaxBase('passtolocalrecommendation'), fd);
    })).then(function () {
        tr.setAttribute('data-passlocal', passed ? '0' : '1');
        pl.classList.toggle('rm-act-active', !passed);
        // toggle the Award-cell badge
        var awardCell = tr.querySelector('.rm-col-award');
        var existing = awardCell ? awardCell.querySelector('.rm-badge-passlocal') : null;
        if (!passed && awardCell && !existing) {
            var b = document.createElement('span');
            b.className = 'rm-badge rm-badge-passlocal';
            b.setAttribute('data-tip', 'Passed to the local park to award.');
            b.innerHTML = '<i class="fas fa-arrow-down"></i> passed to local';
            awardCell.appendChild(b);
        } else if (passed && existing) { existing.remove(); }
        rmApplyFilters();
        rmToast(passed ? 'Pass-to-local removed.' : 'Passed to local.');
    }).catch(function () { rmToast('Update failed.', true); });
});
```
(`rmMemberIds`, `rmPost`, `rmRecAjaxBase`, `rmApplyFilters`, `rmToast` exist from Thread C.)

- [ ] **Step 6: Part B — "Passed to local" filter**

Add a filter control in the filter bar (near the eligibility/court/park selects):
```php
      <label class="rm-fcheck"><input type="checkbox" id="rm-filter-passlocal"> Passed to local</label>
```
And wire it into the client filter function (the one that reads `data-elig` etc.) — add, alongside the other per-row checks, a guard that hides rows failing the pass-local checkbox:
```javascript
    if (document.getElementById('rm-filter-passlocal').checked && tr.getAttribute('data-passlocal') !== '1') ok = false;
```
Bind it: `document.getElementById('rm-filter-passlocal').addEventListener('change', rmApplyFilters);`
(If the filter function uses a single `ok` accumulator, fold the check in consistent with the existing structure; read the function first.)

- [ ] **Step 7: Part B — bulk "Pass down" (kingdom scope)**

Add a bulk button to the bulk bar (kingdom scope only):
```php
    <?php if (($Context ?? '') === 'kingdom') { ?><button type="button" class="rm-bulk rm-bulk-passlocal" data-tip="For recommendations at a higher level than the park can provide, you are granting authority for that park to award at this level.">Pass down</button><?php } ?>
```
Wire it to pass down every member of each selected group (Passed=1), mirroring the bulk-snooze handler's structure (sequential over selected rows, looping `rmMemberIds(tr)`), then `rmApplyFilters()` + a result toast.

- [ ] **Step 8: Lint** — `php -l orkui/template/revised-frontend/Recommendations_manage.tpl`.

- [ ] **Step 9: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Pass-to-local: Manager button/badge/bulk/filter + dismiss tooltip"
```

---

### Task 7: Inline recs panel — read-only badge

**Files:** Modify `orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl` (the shared inline recs list used by Kingdom/Park/Player profile recs tabs)

- [ ] **Step 1: Render the badge where the per-rec award/badges are shown**

Read the panel's per-rec row (it already renders an "already has" info badge near line 77). Where the award + badges render, add a read-only pass-to-local badge:
```php
<?php if (!empty($rec['PassedToLocal'])) { ?><span class="rm-inline-passlocal" data-tip="The kingdom delegated this to the local park to award."><i class="fas fa-arrow-down"></i> passed to local</span><?php } ?>
```
Add a minimal style in the panel's existing `<style>` (or inline) so it's legible in light + dark:
```css
.rm-inline-passlocal { display:inline-block; margin-left:6px; font-size:11px; color:#2c5f8b; border:1px solid rgba(44,95,139,.4); border-radius:3px; padding:0 5px; }
html[data-theme="dark"] .rm-inline-passlocal { color:#6fb0e6; }
```
(If the panel iterates a `$rec` variable under a different name, match it. `PassedToLocal` is now returned by `PlayerAwardRecommendations`, which feeds this panel.)

- [ ] **Step 2: Lint** — `php -l orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl
git commit -m "Pass-to-local: read-only badge in the inline recs panel"
```

---

### Task 8: Court planner pending-recs dismiss label/tooltip (Part A)

**Files:** Modify `orkui/template/default/Court_detail.tpl` (the pending-recommendations modal)

- [ ] **Step 1: Standardize the pending-rec dismiss control**

Read the pending-recs modal markup (search for the dismiss/remove control on a pending recommendation — it dismisses a rec via `dismissrecommendation`). Ensure its label/tooltip reads **"Dismiss"** and add the same `data-tip` text: `Already given out previously? No plans to award this? You can dismiss this rec.` If the control already uses a native `title`, convert it to `data-tip` (project convention). If no such per-rec dismiss control exists in the modal, record that and skip (Part A is then fully covered by the Manager).

- [ ] **Step 2: Lint** — `php -l orkui/template/default/Court_detail.tpl`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Court_detail.tpl
git commit -m "Dismiss clarity: standardize court pending-recs dismiss label/tooltip"
```

---

### Task 9: Verification

- [ ] **Step 1: Lint sweep** — `php -l` all 8 changed PHP/tpl files → all `No syntax errors detected`.

- [ ] **Step 2: Pass-down write (endpoint)** — curl-auth as a kingdom Monarch, toggle a rec via `passtolocalrecommendation` Passed=1 then Passed=0; DB read-back confirms `passed_to_local`/`_by`/`_at` set then cleared. A non-`AUTH_KINGDOM` user is rejected.

- [ ] **Step 3: Manager (kingdom scope)** — load `Recommendations/manage/kingdom/{kid}`; the Pass-down button shows; clicking toggles the badge + active state; the cluster toggles all members (DB read-back); the "Passed to local" filter isolates passed rows; bulk Pass down works.

- [ ] **Step 4: Park scope** — load `Recommendations/manage/park/{pid}`; passed-down recs show the badge + are filterable; the Pass-down button is **absent** (kingdom-scope only).

- [ ] **Step 5: Inline panel** — a passed-down rec shows the read-only badge on the Kingdom/Park profile recs tab.

- [ ] **Step 6: Part A** — every dismiss control reads "Dismiss" + carries the tooltip; At-or-Above filter + bulk Dismiss clears already-has recs.

- [ ] **Step 7: Dark mode** — badge, active button, filter, row highlight all legible.

- [ ] **Step 8: Final commit** (only if verification required fixes).

---

## Self-Review

**Spec coverage:**
- Part A dismiss label + tooltip (Manager + court) → Tasks 6 Step 1, 8. ✓
- Part A cleanup via At-or-Above + bulk dismiss (existing) → Task 9 Step 6 (no code). ✓
- `passed_to_local` migration → Task 1. ✓
- Report returns PassedToLocal (+by/at) + cache bust on write → Tasks 2, 3. ✓
- `SetRecommendationPassedToLocal` AUTH_KINGDOM + audit + cache bust → Task 3. ✓
- Endpoint → Task 4. ✓
- Group PassedToLocal (all members) → Task 5. ✓
- Manager button (kingdom scope), badge, bulk, filter, group-loop toggle → Task 6. ✓
- Inline recs badge (read-only) → Task 7. ✓
- Park surfacing (badge + filter; no button) → Tasks 6 (Context gate) + 9 Step 4. ✓
- No court carry-through, no enforcement → not implemented (correct). ✓

**Placeholder scan:** No TBD/TODO. Tasks 6 Step 6, 7, 8 instruct reading an existing handler/markup then making a specified concrete change (the change is fully specified; the read is to locate the integration point). ✓

**Type/name consistency:** `passed_to_local`/`passed_to_local_by`/`passed_to_local_at` (DB) ↔ `PassedToLocal`/`PassedToLocalBy`/`PassedToLocalAt` (report) ↔ group `PassedToLocal` (controller) ↔ `data-passlocal` (template). `SetRecommendationPassedToLocal` / `set_recommendation_passed_to_local` / `passtolocalrecommendation` consistent across Tasks 3-6. `Passed` POST flag consistent (endpoint Task 4 ↔ JS Task 6). `rmMemberIds`/`rmApplyFilters`/`rmPost`/`rmRecAjaxBase`/`rmToast` reused from Thread C. ✓
