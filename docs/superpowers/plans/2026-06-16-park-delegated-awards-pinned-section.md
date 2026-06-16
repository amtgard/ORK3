# Park "Delegated by the Kingdom" Pinned Section — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an officer-only, draining "to-schedule" section pinned at the top of the Park profile Recommendations tab that surfaces delegated (passed-to-local) recommendations not yet on a court, plus a `Manage →` deep-link that pre-applies the Manager's "Passed to local" filter.

**Architecture:** Two independent template edits. (1) `Parknew_index.tpl`: a new `pk-delegated` block computed in-PHP by filtering the already-available `$AwardRecommendations` array (`PassedToLocal && !IsOnCourt`), gated on `$CanAdminPark`, with its own CSS appended to the existing recs `<style>` block. (2) `Recommendations_manage.tpl`: read `?passlocal=1` from the URL on init and pre-check the existing `#rm-filter-passlocal` checkbox before the first `rmApplyFilters()`. No backend, model, schema, or controller changes.

**Tech Stack:** PHP `.tpl` templates (plain PHP via `extract()`+`include`), vanilla JS, inline CSS. No template-render test harness — verification is `php -l`, `grep`, and a browser checkpoint.

**Spec:** `docs/superpowers/specs/2026-06-15-park-delegated-awards-pinned-section-design.md`

---

## Key conventions (project rules — do not violate)

- `.tpl` is **plain PHP**: `<?php ?>` / `<?= ?>`, never Smarty.
- **Editing method:** these templates are tab-indented. Prefer the Python `replace` fallback for multi-line inserts:
  ```bash
  python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); assert t.count(OLD)==1, 'anchor not unique/found'; p.write_text(t.replace(OLD, NEW, 1))"
  ```
  Read exact current text first; copy anchors verbatim (tabs included). Edit tool is fine for small unique single-line changes.
- **Dark mode required** (`html[data-theme="dark"] …`). Reuse the existing `pk-rec-passlocal` palette (`#2c5f8b` light / `#6fb0e6` dark).
- **No native `title` tooltips** — use `data-tip`. **No native confirm/alert.**
- **Heading gray-box:** use the project's existing `kn-bare-heading` class (already used elsewhere in this file, e.g. lines 412/457/1413) — it resets the global orkui.css `h1–h6` gray pill. Do NOT use a bare `<h4>`.
- **Stage files explicitly.** NEVER `git add -A`/`git add .`. Run `git diff --cached` before each commit; confirm `class.Authorization.php` is NOT staged.

## Data already in hand (no new plumbing)

`controller.Park.php:305–321` builds `$AwardRecommendations` via `Reports->recommended_awards(['ParkId'=>$park_id,...])` → `Report::PlayerAwardRecommendations`. Each `$rec` already includes:
- `PassedToLocal` (bool)
- `IsOnCourt` (bool) — `class.Report.php:643`, from `on_court_count` over any non-cancelled `ork_court_award` (`class.Report.php:486`). (v1 nuance: any court, not park-scoped — accepted per spec.)
- `Persona`, `MundaneId`, `AwardName`.

Officer gate `$CanAdminPark` (`controller.Park.php:289–290`, AUTH_CREATE on park) is the same flag behind the "Manage Recommendations" button (`Parknew_index.tpl:1391–1393`). It is independent of `AwardRecsPublic`/`$ShowRecsTab`.

## File structure

- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (Task 1 — CSS + pinned section)
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (Task 2 — URL-param filter)
- Verify: both files (Task 3)

Tasks 1 and 2 are independent and could run in parallel, but commit to the same branch — run sequentially to avoid index races.

---

### Task 1: Park profile — pinned "Delegated by the Kingdom" section

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl`
  - CSS: append after the `pk-rec-passlocal` rules (current line 1192)
  - Markup: insert just inside the recs panel open (current line 1382), before `kn-rec-header-row` (1383)

- [ ] **Step 1: Append the section CSS**

Anchor on the existing final `pk-rec-passlocal` rule (line 1192). Replace it with itself + the new rules.

OLD (unique — line 1192):
```php
			.pk-rec-passlocal[data-tip]:hover::after { content:attr(data-tip); position:absolute; left:0; top:calc(100% + 4px); white-space:normal; width:220px; background:#1a202c; color:#fff; font-size:11px; padding:6px 8px; border-radius:4px; z-index:50; }
```

NEW:
```php
			.pk-rec-passlocal[data-tip]:hover::after { content:attr(data-tip); position:absolute; left:0; top:calc(100% + 4px); white-space:normal; width:220px; background:#1a202c; color:#fff; font-size:11px; padding:6px 8px; border-radius:4px; z-index:50; }
			/* Delegated-by-the-Kingdom pinned section (officer-only, to-schedule list) */
			.pk-delegated { border:1px solid rgba(44,95,139,.4); background:rgba(44,95,139,.07); border-radius:8px; padding:12px 16px; margin-bottom:16px; }
			.pk-delegated-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
			.pk-delegated-title { margin:0; font-size:14px; font-weight:700; color:#2c5f8b; display:flex; align-items:center; gap:8px; }
			.pk-delegated-title i { color:#2c5f8b; }
			.pk-delegated-count { font-weight:600; color:#718096; }
			.pk-delegated-help { font-size:12px; color:#718096; margin:6px 0 10px; }
			.pk-delegated-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
			.pk-delegated-item { font-size:13px; color:#2d3748; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
			.pk-delegated-item a { font-weight:600; }
			.pk-delegated-sep { color:#cbd5e0; }
			.pk-delegated-award { color:#4a5568; }
			html[data-theme="dark"] .pk-delegated { border-color:rgba(111,176,230,.4); background:rgba(111,176,230,.08); }
			html[data-theme="dark"] .pk-delegated-title { color:#6fb0e6; }
			html[data-theme="dark"] .pk-delegated-title i { color:#6fb0e6; }
			html[data-theme="dark"] .pk-delegated-count { color:#a0aec0; }
			html[data-theme="dark"] .pk-delegated-help { color:#a0aec0; }
			html[data-theme="dark"] .pk-delegated-item { color:#e2e8f0; }
			html[data-theme="dark"] .pk-delegated-award { color:#cbd5e0; }
			html[data-theme="dark"] .pk-delegated-sep { color:#4a5568; }
```

- [ ] **Step 2: Insert the pinned section markup at the top of the recs panel**

Anchor on the recs panel open + the header-row open (lines 1382–1383). Replace with the same two lines plus the section between them.

OLD (unique — lines 1382–1383):
```php
			<div class="pk-tab-panel" id="pk-tab-recommendations" style="display:none">
				<div class="kn-rec-header-row">
```

NEW:
```php
			<div class="pk-tab-panel" id="pk-tab-recommendations" style="display:none">
				<?php if (!empty($CanAdminPark)):
					$pkDelegated = array_values(array_filter($AwardRecommendations ?? [], function ($r) {
						return !empty($r['PassedToLocal']) && empty($r['IsOnCourt']);
					}));
				?>
				<?php if (!empty($pkDelegated)): ?>
				<div class="pk-delegated">
					<div class="pk-delegated-head">
						<h4 class="kn-bare-heading pk-delegated-title"><i class="fas fa-arrow-down"></i> Delegated by the Kingdom &mdash; to schedule <span class="pk-delegated-count">(<?= count($pkDelegated) ?>)</span></h4>
						<a class="pk-btn pk-btn-secondary pk-delegated-manage" href="<?= UIR ?>Recommendations/manage/park/<?= (int)$park_id ?>?passlocal=1">Manage <i class="fas fa-arrow-right"></i></a>
					</div>
					<div class="pk-delegated-help">The kingdom granted your park authority to give these. Schedule them into a court.</div>
					<ul class="pk-delegated-list">
						<?php foreach ($pkDelegated as $dr): ?>
						<li class="pk-delegated-item">
							<a href="<?= UIR ?>Player/profile/<?= (int)$dr['MundaneId'] ?>"><?= htmlspecialchars($dr['Persona']) ?></a>
							<span class="pk-delegated-sep">&middot;</span>
							<span class="pk-delegated-award"><?= htmlspecialchars(preg_replace('/^Order of(?:\\s+the)?\\s+/i', '', $dr['AwardName'])) ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				<?php endif; ?>
				<div class="kn-rec-header-row">
```

Notes:
- `$AwardRecommendations` is always set by the controller (defaults to `[]`), but the `?? []` guard keeps the filter safe regardless.
- The section renders only when `$CanAdminPark` AND at least one qualifying item exists — no empty box otherwise.
- `kn-bare-heading` resets the global heading gray-box; `pk-delegated-title` layers on the blue color + flex layout.

- [ ] **Step 3: Lint**

Run: `php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Sanity-grep the new section**

Run: `grep -n 'pk-delegated\|pkDelegated' orkui/template/revised-frontend/Parknew_index.tpl`
Expected: the CSS rules, the PHP filter, and the markup all present; `?passlocal=1` href present.

- [ ] **Step 5: Commit**

```bash
git diff --cached   # after staging: only Parknew_index.tpl, no foreign hunks, no Authorization.php
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: pinned 'Delegated by the Kingdom' section on Park recs tab"
```

---

### Task 2: Recommendations Manager — pre-apply "Passed to local" from `?passlocal=1`

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl`
  - Init region around line 1292 (`rmSort('date');` then `rmApplyFilters();` at 1293)

Context (already present): the checkbox `#rm-filter-passlocal` (line 543); `rmApplyFilters()` (line 773) already honors its `.checked` state (line 797); on init, `rmSort('date')` then `rmApplyFilters()` run (lines 1292–1293).

- [ ] **Step 1: Insert URL-param pre-apply before the first `rmApplyFilters()`**

Anchor on the init lines 1292–1293. Replace with the same lines plus the pre-apply block between them.

OLD (unique — lines 1292–1293):
```javascript
rmSort('date');
rmApplyFilters();
```

NEW:
```javascript
rmSort('date');
// Pre-apply the "Passed to local" filter when arrived via ?passlocal=1
// (e.g. from the park profile "Delegated by the Kingdom" section's Manage link).
(function () {
    var params = new URLSearchParams(window.location.search);
    if (params.get('passlocal') === '1') {
        var pl = document.getElementById('rm-filter-passlocal');
        if (pl) pl.checked = true;
    }
})();
rmApplyFilters();
```

Notes:
- Setting `.checked` before `rmApplyFilters()` means the first render reflects the filter, and the chip logic (line 816) auto-adds the "Passed to local" chip. No other change needed.
- `#rm-filter-passlocal` only renders in some scopes; the `if (pl)` guard makes the block a no-op when absent.

- [ ] **Step 2: Lint**

Run: `php -l orkui/template/revised-frontend/Recommendations_manage.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Sanity-grep**

Run: `grep -n "passlocal') === '1'\|URLSearchParams" orkui/template/revised-frontend/Recommendations_manage.tpl`
Expected: the new pre-apply block present, immediately before the init `rmApplyFilters()`.

- [ ] **Step 4: Commit**

```bash
git diff --cached
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Enhancement: Recommendations Manager pre-applies passlocal filter from ?passlocal=1"
```

---

### Task 3: Verification

**Files:** both edited templates.

- [ ] **Step 1: Lint both**

```bash
php -l orkui/template/revised-frontend/Parknew_index.tpl
php -l orkui/template/revised-frontend/Recommendations_manage.tpl
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 2: Confirm officer-gate + filter wiring**

```bash
grep -n 'CanAdminPark\|pk-delegated\|passlocal=1' orkui/template/revised-frontend/Parknew_index.tpl
grep -n "passlocal') === '1'" orkui/template/revised-frontend/Recommendations_manage.tpl
```
Confirm: the `pk-delegated` block is inside a `!empty($CanAdminPark)` gate; the Manage href ends with `?passlocal=1`; the Manager reads `passlocal`.

- [ ] **Step 3: Browser checkpoint (manual — verification after implementation)**

Local routing: `index.php?Route=Park/profile/{id}` (see project memory for curl-auth login + bypass). As a park officer (`$CanAdminPark`) on a park with ≥1 delegated, not-on-court rec:
  1. Recommendations tab shows the pinned `⬇ Delegated by the Kingdom — to schedule (N)` section above the toolbar, with correct count and recipient · award rows.
  2. A delegated rec already on a court does NOT appear; a non-delegated rec does NOT appear.
  3. Zero qualifying items → section absent (no empty box).
  4. As a non-officer (and in the `AwardRecsPublic`-on public-viewer case) the section is absent even though the recs list shows.
  5. `Manage →` lands on `Recommendations/manage/park/{id}?passlocal=1` with the "Passed to local" filter pre-checked, the chip shown, and the grid filtered to delegated rows.
  6. Dark mode: section border/background/heading/help/rows all legible; tooltips are `data-tip`, not native.

- [ ] **Step 4: Final summary**

Report files changed, both lint results, grep results, and browser-check outcomes. Flag deviations rather than declaring done.

---

## Self-review notes (author)

- **Spec coverage:** officer gate `$CanAdminPark` independent of `AwardRecsPublic` (T1 Step 2 gate); pinned at top of recs tab (T1 Step 2 placement); filter `PassedToLocal && !IsOnCourt` (T1 Step 2 PHP); read-only rows + `Manage →` CTA (T1 Step 2 markup); Manager `?passlocal=1` pre-apply (T2); heading gray-box via `kn-bare-heading` (T1); dark mode + `data-tip` (T1 CSS); hide-when-empty (T1 `!empty($pkDelegated)`); no schema/endpoint change (whole plan). All covered.
- **Name consistency:** `pk-delegated*` classes, `$pkDelegated` var, `pk-delegated-manage` href with `?passlocal=1`, and the Manager `passlocal` param all match across tasks.
- **No placeholders:** every code step shows exact code anchored to verified current line numbers.
