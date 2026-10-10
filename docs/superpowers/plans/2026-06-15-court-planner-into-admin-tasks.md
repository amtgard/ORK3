# Move Court Planner into Admin Tasks — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the standalone "Court Planner" top-level tab from the Kingdom and Park profiles and relocate its full UI into a collapsible subsection at the bottom of the existing Admin Tasks tab.

**Architecture:** Pure template edits in two `.tpl` files (plain PHP, not Smarty). On each surface: delete the Court Planner nav `<li>`, delete the standalone `#…-tab-court` panel, and re-home its exact contents (inline `<style>`, toolbar, court cards, New Court modal, inline `<script>`) inside the Admin Tasks panel, wrapped in a new collapsible subsection. No controller/model/AJAX changes — `$CanManageCourt`, `$CourtList`, `$CourtUpcomingEvents` keep flowing as today.

**Tech Stack:** PHP `.tpl` templates (`extract()`+`include`, plain PHP), vanilla JS, inline CSS. No test framework for template rendering — verification is `php -l`, `grep`, and a browser checkpoint.

**Spec:** `docs/superpowers/specs/2026-06-15-court-planner-into-admin-tasks-design.md`

---

## Key conventions (project rules — do not violate)

- `.tpl` files are **plain PHP**: use `<?php ?>` / `<?= ?>`, never `{$var}`/`{if}`.
- **Editing method:** these templates are tab-indented. For the large block moves, use the Python `replace` fallback (most reliable for tabbed multi-line blocks):
  ```bash
  python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); assert t.count(OLD)==1, 'anchor not unique/found'; p.write_text(t.replace(OLD, NEW, 1))"
  ```
  Read the exact current text from the file first; copy anchors verbatim.
- **Dark mode required:** all new chrome (collapsible header, divider, chevron) AND the relocated court cards must be dark-mode compatible. Dark selector in these files is `html[data-theme="dark"] …`.
- **No native `title` tooltips**, **no native confirm/alert**.
- **Heading gray-box:** the new collapsible header uses a `<button>`, NOT an `h*` tag, to sidestep the global orkui.css `h1–h6` gray-box styling. Do not change this to a heading.
- **Never stage `class.Authorization.php`**; stage files explicitly (never `git add -A`). Run `git diff --cached` before each commit.

## Auth model (applies to both surfaces)

Today Court (`$CanManageCourt`) and Admin Tasks gate independently, and on Park the nav/panel even use different flags (`$CanManagePark` vs `$CanAdminPark`). To preserve every viewer's current access with zero gain/loss, nest with **independent inner gates** and an **OR'd outer gate**:

- Admin Tasks **nav `<li>`**: show when `adminNavFlag || $CanManageCourt`.
- Admin Tasks **panel**: render when `adminPanelFlag || $CanManageCourt`.
- Inside the panel: wrap the existing report-cols grid in `if (adminPanelFlag)` and the Court subsection in `if ($CanManageCourt)`.

Flags per surface:
- **Kingdom:** `adminNavFlag = adminPanelFlag = ($CanManageKingdom ?? false)`.
- **Park:** `adminNavFlag = !empty($CanManagePark)`, `adminPanelFlag = !empty($CanAdminPark)`.

Result: report-cols render exactly when they did before; court renders exactly when it did before; the Admin Tasks tab simply appears for the union.

## File structure

- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (Task 1)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (Task 2)
- Verify: both files + `orkui/template/revised-frontend/script/revised.js` (Task 3 — no edit expected, grep only)

Tasks 1 and 2 are independent and may be executed in parallel (one subagent per surface).

---

### Task 1: Kingdom — relocate Court Planner into Admin Tasks

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl`
  - Nav `<li data-kntab="court">`: lines 314–319 (delete) and `<li data-kntab="admin">`: lines 309–313 (re-gate)
  - Admin panel `#kn-tab-admin`: lines 825–847 (extend)
  - Standalone court panel `#kn-tab-court`: lines 863–1034 (delete after moving body)

- [ ] **Step 1: Re-gate + remove the two nav `<li>` entries**

Replace the Admin nav `<li>` so it shows for admins OR court managers, and delete the Court nav `<li>` entirely.

OLD (lines 309–319):
```php
				<?php if ($CanManageKingdom ?? false): ?>
				<li data-kntab="admin">
					<i class="fas fa-cog"></i><span class="kn-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
				<?php if ($CanManageCourt ?? false): ?>
				<li data-kntab="court">
					<i class="fas fa-gavel"></i><span class="kn-tab-label"> Court Planner</span>
					<?php if (!empty($CourtList)): ?><span class="kn-tab-count">(<?= count($CourtList) ?>)</span><?php endif; ?>
				</li>
				<?php endif; ?>
```

NEW:
```php
				<?php if (($CanManageKingdom ?? false) || ($CanManageCourt ?? false)): ?>
				<li data-kntab="admin">
					<i class="fas fa-cog"></i><span class="kn-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
```

- [ ] **Step 2: Capture the court panel body to move**

The standalone panel `#kn-tab-court` spans lines 863–1034. The reusable **body** is everything between (and including) the `<style>` block (line 866) and the closing `</script>` (line 1032) — i.e. the inline `<style>`, the `.kn-cp-toolbar`, the `$_cpStatus*` PHP, the court cards / empty state, the `#kn-cp-new-court-modal`, and the inline `<script>`. Read lines 863–1034 from the file and copy that body verbatim; you will paste it unchanged inside the new subsection in Step 4.

- [ ] **Step 3: Delete the standalone court panel**

Delete the entire block (lines 863–1034 inclusive), including its leading comment and the `<?php if ($CanManageCourt ?? false): ?>` / closing `<?php endif; ?>` wrapper. Anchor the Python replace on the unique opening comment+gate and the closing. After deletion, the region between the recommendations panel (ends line 860) and the next section should have no court panel.

Verify nothing named `kn-tab-court` remains:
```bash
grep -n 'kn-tab-court\|data-kntab="court"' orkui/template/revised-frontend/Kingdomnew_index.tpl
```
Expected: no output.

- [ ] **Step 4: Re-home court into the Admin panel as a collapsible subsection**

Replace the Admin panel block (lines 825–847) with the version below. The outer gate becomes OR'd; the report-cols grid stays gated on `$CanManageKingdom`; the court subsection is gated on `$CanManageCourt`. Paste the **exact court body captured in Step 2** where marked, unchanged.

OLD (lines 825–847):
```php
		<!-- Admin Tab -->
		<?php if ($CanManageKingdom ?? false): ?>
		<div class="kn-tab-panel" id="kn-tab-admin" style="display:none">
			<div class="kn-report-cols">
				<div class="kn-report-group">
					<h5><i class="fas fa-users-cog"></i> Players</h5>
					<ul>
						<li><a href="#" onclick="knOpenAddPlayerModal();return false;">Create Player</a></li>
						<li><a href="#" onclick="knOpenMovePlayerModal();return false;">Move Player</a></li>
						<li><a href="#" onclick="knOpenMergePlayerModal();return false;">Merge Players</a></li>
						<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspensions</a></li>
					</ul>
				</div>
				<div class="kn-report-group">
					<h5><i class="fas fa-cog"></i> Kingdom</h5>
					<ul>
						<li><a href="<?= UIR ?>Admin/permissions/Kingdom/<?= $kingdom_id ?>">Roles &amp; Permissions</a></li>
						<li><a href="#" onclick="knOpenClaimParkModal();return false;">Claim Park</a></li>
					</ul>
				</div>
			</div>
		</div>
		<?php endif; ?>
```

NEW:
```php
		<!-- Admin Tab (now also hosts Court Planner as a collapsible subsection) -->
		<?php if (($CanManageKingdom ?? false) || ($CanManageCourt ?? false)): ?>
		<div class="kn-tab-panel" id="kn-tab-admin" style="display:none">
			<?php if ($CanManageKingdom ?? false): ?>
			<div class="kn-report-cols">
				<div class="kn-report-group">
					<h5><i class="fas fa-users-cog"></i> Players</h5>
					<ul>
						<li><a href="#" onclick="knOpenAddPlayerModal();return false;">Create Player</a></li>
						<li><a href="#" onclick="knOpenMovePlayerModal();return false;">Move Player</a></li>
						<li><a href="#" onclick="knOpenMergePlayerModal();return false;">Merge Players</a></li>
						<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspensions</a></li>
					</ul>
				</div>
				<div class="kn-report-group">
					<h5><i class="fas fa-cog"></i> Kingdom</h5>
					<ul>
						<li><a href="<?= UIR ?>Admin/permissions/Kingdom/<?= $kingdom_id ?>">Roles &amp; Permissions</a></li>
						<li><a href="#" onclick="knOpenClaimParkModal();return false;">Claim Park</a></li>
					</ul>
				</div>
			</div>
			<?php endif; ?>

			<!-- Court Planner subsection (relocated from former top-level tab) -->
			<?php if ($CanManageCourt ?? false): ?>
			<?php $_cpOpen = !empty($CourtList); ?>
			<div class="kn-cp-section<?= $_cpOpen ? ' kn-cp-open' : '' ?>" id="kn-cp-section">
				<button type="button" class="kn-cp-header" onclick="knCpToggleSection()" aria-expanded="<?= $_cpOpen ? 'true' : 'false' ?>">
					<span class="kn-cp-header-title"><i class="fas fa-gavel"></i> Court Planner<?php if (!empty($CourtList)): ?> <span class="kn-cp-header-count">(<?= count($CourtList) ?>)</span><?php endif; ?></span>
					<i class="fas fa-chevron-down kn-cp-chevron"></i>
				</button>
				<div class="kn-cp-body" id="kn-cp-body"<?= $_cpOpen ? '' : ' style="display:none"' ?>>
<!-- ===== BEGIN court body: paste verbatim from Step 2 (the <style>…</script> block) ===== -->
<!-- ===== END court body ===== -->
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
```

Notes for the paste:
- The pasted body keeps its own `<?php if ($CanManageCourt ?? false): ?>` wrapper? NO — the captured body from Step 2 is the *inner* content (`<style>` through `</script>`), which had no inner gate of its own; the gate was the panel wrapper you deleted. The new `<?php if ($CanManageCourt ?? false): ?>` above is its replacement gate. Do not double-wrap.
- Indentation mismatch between the pasted body and the new surroundings is fine (template renders identically); the pre-commit formatter does not touch `.tpl`.

- [ ] **Step 5: Add collapsible-chrome CSS + the toggle function**

Inside the pasted body, the inline `<style>` block (the `.kn-cp-*` rules) is already present. Append the following rules to that same `<style>` block (just before its closing `</style>`):
```css
				/* Collapsible subsection chrome (relocated court) */
				.kn-cp-section { border-top:1px solid #e2e8f0; margin-top:24px; padding-top:4px; }
				.kn-cp-header { display:flex; align-items:center; justify-content:space-between; width:100%; background:none; border:none; cursor:pointer; padding:10px 4px; font-size:15px; font-weight:700; color:#2d3748; text-align:left; }
				.kn-cp-header:hover { color:#1a202c; }
				.kn-cp-header-title i.fa-gavel { margin-right:8px; color:#4a5568; }
				.kn-cp-header-count { font-weight:600; color:#718096; font-size:13px; }
				.kn-cp-chevron { transition:transform .15s; color:#a0aec0; }
				.kn-cp-section.kn-cp-open .kn-cp-chevron { transform:rotate(180deg); }
				.kn-cp-body { padding-top:8px; }
				html[data-theme="dark"] .kn-cp-section { border-top-color:#2d3748; }
				html[data-theme="dark"] .kn-cp-header { color:#e2e8f0; }
				html[data-theme="dark"] .kn-cp-header:hover { color:#fff; }
				html[data-theme="dark"] .kn-cp-header-title i.fa-gavel { color:#a0aec0; }
				html[data-theme="dark"] .kn-cp-court-card { background:#1a202c; border-color:#2d3748; }
				html[data-theme="dark"] .kn-cp-court-name { color:#e2e8f0; }
				html[data-theme="dark"] .kn-cp-court-meta { color:#a0aec0; }
				html[data-theme="dark"] .kn-cp-badge-count { background:#2d3748; color:#cbd5e0; }
				html[data-theme="dark"] .kn-cp-btn-link { border-color:#4a5568; color:#cbd5e0; }
				html[data-theme="dark"] .kn-cp-btn-link:hover { background:#2d3748; color:#fff; }
				html[data-theme="dark"] .kn-cp-empty { border-color:#2d3748; color:#a0aec0; }
```

Inside the pasted body's inline `<script>` IIFE (the one that already defines `window.knCpOpenNewCourt` etc.), add the toggle function next to the other `window.knCp*` assignments:
```javascript
					window.knCpToggleSection = function() {
						var sec = document.getElementById('kn-cp-section');
						var body = document.getElementById('kn-cp-body');
						if (!sec || !body) return;
						var open = sec.classList.toggle('kn-cp-open');
						body.style.display = open ? '' : 'none';
						var hdr = sec.querySelector('.kn-cp-header');
						if (hdr) hdr.setAttribute('aria-expanded', open ? 'true' : 'false');
					};
```
(The IIFE's existing `if (!<?= … ?>) return;` court-permission guard at the top still correctly gates this — the subsection only renders when `$CanManageCourt`, so the guard passes.)

- [ ] **Step 6: Lint**

Run:
```bash
php -l orkui/template/revised-frontend/Kingdomnew_index.tpl
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: Residual-reference check**

Run:
```bash
grep -n 'kn-tab-court\|data-kntab="court"' orkui/template/revised-frontend/Kingdomnew_index.tpl
```
Expected: no output. (The court nav `<li>` and standalone panel are gone; the subsection uses `kn-cp-section`, not a `court` tab.)

- [ ] **Step 8: Commit**

```bash
git diff --cached   # after staging, confirm only Kingdomnew_index.tpl, no foreign hunks
git add orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Enhancement: move Kingdom Court Planner into Admin Tasks subsection"
```

---

### Task 2: Park — relocate Court Planner into Admin Tasks

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl`
  - Nav `<li data-pktab="court">`: lines 530–535 (delete) and `<li data-pktab="admin">`: lines 536–540 (re-gate)
  - Admin panel `#pk-tab-admin`: lines 1144–1165 (extend)
  - Standalone court panel `#pk-tab-court`: lines 1341–1517 (delete after moving body)

Mirror of Task 1 with `kn`→`pk` prefixes and Park's distinct flags (`$CanManagePark` for nav, `$CanAdminPark` for panel).

- [ ] **Step 1: Re-gate + remove the two nav `<li>` entries**

Note the Park source order is Court **then** Admin (reverse of Kingdom). Delete the Court nav `<li>` and re-gate the Admin nav `<li>`.

OLD (lines 530–540):
```php
				<?php if (!empty($CanManageCourt)): ?>
				<li data-pktab="court">
					<i class="fas fa-gavel"></i><span class="pk-tab-label"> Court Planner</span>
					<?php if (!empty($CourtList)): ?><span class="pk-tab-count">(<?= count($CourtList) ?>)</span><?php endif; ?>
				</li>
				<?php endif; ?>
				<?php if (!empty($CanManagePark)): ?>
				<li data-pktab="admin">
					<i class="fas fa-cog"></i><span class="pk-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
```

NEW:
```php
				<?php if (!empty($CanManagePark) || !empty($CanManageCourt)): ?>
				<li data-pktab="admin">
					<i class="fas fa-cog"></i><span class="pk-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
```

- [ ] **Step 2: Capture the court panel body to move**

Standalone panel `#pk-tab-court` spans lines 1341–1517. The reusable **body** is the `<style>` block (line 1344) through the closing `</script>` (line 1515) — inline `<style>`, `.pk-cp-toolbar`, `$_cpStatus*` PHP, court cards / empty state, `#pk-cp-new-court-modal`, and the inline `<script>`. Read lines 1341–1517 and copy that body verbatim for Step 4.

- [ ] **Step 3: Delete the standalone court panel**

Delete the entire block (lines 1341–1517 inclusive), including the leading comment and the `<?php if (!empty($CanManageCourt)): ?>` / closing `<?php endif; ?>`. Do NOT delete the following `</div><!-- /pk-tabs -->` (line 1519) — that closes the tabs container and must remain.

Verify:
```bash
grep -n 'pk-tab-court\|data-pktab="court"' orkui/template/revised-frontend/Parknew_index.tpl
```
Expected: no output.

- [ ] **Step 4: Re-home court into the Admin panel as a collapsible subsection**

Replace the Admin panel block (lines 1144–1165). Outer gate OR's `$CanAdminPark` with `$CanManageCourt`; report-cols stay gated on `$CanAdminPark`; court subsection gated on `$CanManageCourt`. Paste the **exact court body from Step 2** where marked.

OLD (lines 1144–1165):
```php
			<!-- Admin Tab -->
			<?php if (!empty($CanAdminPark)): ?>
			<div class="pk-tab-panel" id="pk-tab-admin" style="display:none">
				<div class="kn-report-cols">
					<div class="kn-report-group">
						<h5><i class="fas fa-users-cog"></i> Players</h5>
						<ul>
							<li><a href="#" onclick="pkOpenAddPlayerModal();return false;">Create Player</a></li>
							<li><a href="#" onclick="pkOpenMovePlayerModal();return false;">Move Player</a></li>
							<?php if (!empty($CanMergePlayers)): ?><li><a href="#" onclick="pkOpenMergePlayerModal();return false;">Merge Players</a></li><?php endif; ?>
							<li><a href="<?= UIR ?>Reports/suspended/Park&id=<?= $park_id ?>">Suspensions</a></li>
						</ul>
					</div>
					<div class="kn-report-group">
						<h5><i class="fas fa-cog"></i> Park</h5>
						<ul>
							<li><a href="<?= UIR ?>Admin/permissions/Park/<?= $park_id ?>">Roles &amp; Permissions</a></li>
						</ul>
					</div>
				</div>
			</div>
			<?php endif; ?>
```

NEW:
```php
			<!-- Admin Tab (now also hosts Court Planner as a collapsible subsection) -->
			<?php if (!empty($CanAdminPark) || !empty($CanManageCourt)): ?>
			<div class="pk-tab-panel" id="pk-tab-admin" style="display:none">
				<?php if (!empty($CanAdminPark)): ?>
				<div class="kn-report-cols">
					<div class="kn-report-group">
						<h5><i class="fas fa-users-cog"></i> Players</h5>
						<ul>
							<li><a href="#" onclick="pkOpenAddPlayerModal();return false;">Create Player</a></li>
							<li><a href="#" onclick="pkOpenMovePlayerModal();return false;">Move Player</a></li>
							<?php if (!empty($CanMergePlayers)): ?><li><a href="#" onclick="pkOpenMergePlayerModal();return false;">Merge Players</a></li><?php endif; ?>
							<li><a href="<?= UIR ?>Reports/suspended/Park&id=<?= $park_id ?>">Suspensions</a></li>
						</ul>
					</div>
					<div class="kn-report-group">
						<h5><i class="fas fa-cog"></i> Park</h5>
						<ul>
							<li><a href="<?= UIR ?>Admin/permissions/Park/<?= $park_id ?>">Roles &amp; Permissions</a></li>
						</ul>
					</div>
				</div>
				<?php endif; ?>

				<!-- Court Planner subsection (relocated from former top-level tab) -->
				<?php if (!empty($CanManageCourt)): ?>
				<?php $_cpOpen = !empty($CourtList); ?>
				<div class="pk-cp-section<?= $_cpOpen ? ' pk-cp-open' : '' ?>" id="pk-cp-section">
					<button type="button" class="pk-cp-header" onclick="pkCpToggleSection()" aria-expanded="<?= $_cpOpen ? 'true' : 'false' ?>">
						<span class="pk-cp-header-title"><i class="fas fa-gavel"></i> Court Planner<?php if (!empty($CourtList)): ?> <span class="pk-cp-header-count">(<?= count($CourtList) ?>)</span><?php endif; ?></span>
						<i class="fas fa-chevron-down pk-cp-chevron"></i>
					</button>
					<div class="pk-cp-body" id="pk-cp-body"<?= $_cpOpen ? '' : ' style="display:none"' ?>>
<!-- ===== BEGIN court body: paste verbatim from Step 2 (the <style>…</script> block) ===== -->
<!-- ===== END court body ===== -->
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
```

Same paste notes as Task 1 Step 4: the captured body is the inner `<style>…</script>` with no gate of its own; the new `<?php if (!empty($CanManageCourt)): ?>` is its gate — do not double-wrap.

- [ ] **Step 5: Add collapsible-chrome CSS + the toggle function**

Append to the pasted body's inline `<style>` (before `</style>`):
```css
				/* Collapsible subsection chrome (relocated court) */
				.pk-cp-section { border-top:1px solid #e2e8f0; margin-top:24px; padding-top:4px; }
				.pk-cp-header { display:flex; align-items:center; justify-content:space-between; width:100%; background:none; border:none; cursor:pointer; padding:10px 4px; font-size:15px; font-weight:700; color:#2d3748; text-align:left; }
				.pk-cp-header:hover { color:#1a202c; }
				.pk-cp-header-title i.fa-gavel { margin-right:8px; color:#4a5568; }
				.pk-cp-header-count { font-weight:600; color:#718096; font-size:13px; }
				.pk-cp-chevron { transition:transform .15s; color:#a0aec0; }
				.pk-cp-section.pk-cp-open .pk-cp-chevron { transform:rotate(180deg); }
				.pk-cp-body { padding-top:8px; }
				html[data-theme="dark"] .pk-cp-section { border-top-color:#2d3748; }
				html[data-theme="dark"] .pk-cp-header { color:#e2e8f0; }
				html[data-theme="dark"] .pk-cp-header:hover { color:#fff; }
				html[data-theme="dark"] .pk-cp-header-title i.fa-gavel { color:#a0aec0; }
				html[data-theme="dark"] .pk-cp-court-card { background:#1a202c; border-color:#2d3748; }
				html[data-theme="dark"] .pk-cp-court-name { color:#e2e8f0; }
				html[data-theme="dark"] .pk-cp-court-meta { color:#a0aec0; }
				html[data-theme="dark"] .pk-cp-badge-count { background:#2d3748; color:#cbd5e0; }
				html[data-theme="dark"] .pk-cp-btn-link { border-color:#4a5568; color:#cbd5e0; }
				html[data-theme="dark"] .pk-cp-btn-link:hover { background:#2d3748; color:#fff; }
				html[data-theme="dark"] .pk-cp-empty { border-color:#2d3748; color:#a0aec0; }
```

Add to the pasted body's `<script>` IIFE (next to `window.pkCpOpenNewCourt` etc.):
```javascript
					window.pkCpToggleSection = function() {
						var sec = document.getElementById('pk-cp-section');
						var body = document.getElementById('pk-cp-body');
						if (!sec || !body) return;
						var open = sec.classList.toggle('pk-cp-open');
						body.style.display = open ? '' : 'none';
						var hdr = sec.querySelector('.pk-cp-header');
						if (hdr) hdr.setAttribute('aria-expanded', open ? 'true' : 'false');
					};
```

- [ ] **Step 6: Lint**

```bash
php -l orkui/template/revised-frontend/Parknew_index.tpl
```
Expected: `No syntax errors detected`.

- [ ] **Step 7: Residual-reference check**

```bash
grep -n 'pk-tab-court\|data-pktab="court"' orkui/template/revised-frontend/Parknew_index.tpl
```
Expected: no output.

- [ ] **Step 8: Commit**

```bash
git diff --cached
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: move Park Court Planner into Admin Tasks subsection"
```

---

### Task 3: Cross-cutting verification

**Files:** both templates + `orkui/template/revised-frontend/script/revised.js` (grep only — no edit expected).

- [ ] **Step 1: Confirm no orphaned court-tab references anywhere**

```bash
grep -rn 'tab=court\|data-kntab="court"\|data-pktab="court"\|kn-tab-court\|pk-tab-court' orkui/template/revised-frontend/
```
Expected: no output. If `revised.js` has a `?tab=court`/`court` special case (it should not — `knActivateTab`/`pkActivateTab` are generic), report it; do not silently edit JS without flagging.

- [ ] **Step 2: Confirm the activation functions need no change**

```bash
grep -n "ActivateTab" orkui/template/revised-frontend/script/revised.js
```
Confirm `knActivateTab`/`pkActivateTab` show/hide `#…-tab-{tab}` generically with no `court` branch. No edit expected.

- [ ] **Step 3: Browser checkpoint (manual, per project Chrome-usage rule — verification after implementation)**

Local routing: `index.php?Route=Kingdom/index/{id}` and `index.php?Route=Park/profile/{id}` (see project memory for curl-auth login + bypass). On a Kingdom and a Park where the logged-in user can manage court:
  1. No "Court Planner" top-level tab in the nav.
  2. "Admin Tasks" tab present; opening it shows the report-link grid, a divider, then the "⚖ Court Planner (N)" collapsible header.
  3. Header click expands/collapses; chevron rotates; `aria-expanded` flips.
  4. Default state: **expanded** when at least one court exists, **collapsed** when none.
  5. "Plan a Court" opens the modal; creating a court redirects to `Court/detail/{id}`.
  6. Toggle to dark mode: header, divider, chevron, court cards, badges, and empty state all legible (no white card on dark bg).

- [ ] **Step 4: Final summary**

Report: files changed, both lint results, all grep results (expect empty), and browser-check outcomes. Flag any deviation rather than declaring done.

---

## Self-review notes (author)

- **Spec coverage:** tab removal (T1/T2 Step 1+3), relocation into Admin Tasks (T1/T2 Step 4), collapsible + smart-default-open (Step 4 `$_cpOpen`), count badge moved to header (Step 4), dark mode + heading-reset-via-button + no-native-tooltip (Step 5 + button choice), dead-wiring check (T3). Auth nuance from spec Risks resolved with independent inner gates + OR'd outer gate. All covered.
- **Type/name consistency:** `kn-cp-section`/`kn-cp-body`/`knCpToggleSection` (Kingdom) and `pk-cp-section`/`pk-cp-body`/`pkCpToggleSection` (Park) used consistently across markup, CSS, and JS within each task.
- **No placeholders:** the only intentional markers are the `BEGIN/END court body` paste anchors, which reference exact captured lines from each file's Step 2 (a verbatim move, not undefined new code).
