# Tournament Playtest Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix four issues found in a tournament-module playtest: kingdom-locked participant search, hidden advanced options + dropdown format selector, a conflated weapon-style category, and painful mid-tournament withdrawals.

**Architecture:** Four independent parts (A–D). A is a DB migration + label changes; B is a small backend scope mode + one frontend call; C is frontend-only template/JS; D adds a withdrawal-resolution feature on top of the existing per-participant status flow (round-robin scope). PHP DB logic lives in `system/lib/ork3/class.Tournament.php`; the thin model `orkui/model/model.Tournament.php` and AJAX controller `orkui/controller/controller.TournamentAjax.php` pass through; all tournament UI/JS is inlined in `orkui/template/revised-frontend/Tournametnew_index.tpl`.

**Tech Stack:** PHP 8 (legacy custom MVC, `Yapo`/`$DB` data layer), MariaDB (Docker container `ork3-php8-db`), vanilla JS in `.tpl` templates (plain PHP via `extract()`+`include` — use `<?php ?>`/`<?= ?>`, never Smarty), Docker app container `ork3-php8-app`.

---

## Conventions for every task

**Editing PHP/JS/TPL multi-line blocks:** Do NOT use the Edit tool for multi-line changes — tab/space ambiguity makes byte-match fail. Use Python:
```bash
python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); print('found:', OLD in t); p.write_text(t.replace(OLD, NEW, 1))"
```
Always **read the current block first** (line numbers drift between tasks).

**Lint after every PHP edit:**
```bash
docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/<relative-path>
```
Expected: `No syntax errors detected`.

**Curl-auth session (for logged-in AJAX verification)** — login + all calls must share ONE cookie jar in ONE shell block (single-device sessions); login bypass accepts any password:
```bash
J=/tmp/tn_cookies.txt; B=http://localhost:19080/orkui/index.php?Route=
curl -s -c $J -b $J "${B}Login/login" --data 'username=<known-user>&password=x' >/dev/null
# then reuse -b $J -c $J for each test call in the same block
```
HTTP 500s surface in `docker logs ork3-php8-app`.

**Run a migration:**
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/<file>.sql
```

**Never** stage `system/lib/ork3/class.Authorization.php`, `CLAUDE.md`, or `.claude/`. Stage files explicitly (no `git add -A`/`.`); run `git diff --cached` before committing.

---

## Part A — Issue 4: Split "Other / Open" into "Open Weapons" + "Other"

**Files:**
- Create: `db-migrations/2026-06-01-bracket-style-open-weapons.sql`
- Modify: `ork.sql:210`, `ork.sql:427` (schema seed enum, both occurrences)
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (label map `:83`, add dropdown `:3056`, edit dropdown `:3182`, `STYLE_OPTS` `:5569`)
- Modify: `scripts/simulate_tournament.php` (`$all_styles`)

### Task A1: Migration — add `'Open Weapons'` enum member and migrate existing rows

- [ ] **Step 1: Create the migration file**

Create `db-migrations/2026-06-01-bracket-style-open-weapons.sql`:
```sql
-- Issue 4: split the conflated 'Other' weapon style (displayed "Other / Open")
-- into two distinct buckets: 'Open Weapons' and a genuine 'Other'.
-- Append-only enum change (safe), then migrate existing rows.
ALTER TABLE ork_bracket
  MODIFY `style` enum(
    'Single Sword','Florentine','Sword and Shield','Great Weapon','Missile',
    'Other','Jugging','Battlegame','Quest','Open Weapons'
  ) NOT NULL;

-- Today's 'Other' rows semantically meant "open weapons"; migrate them.
UPDATE ork_bracket SET `style` = 'Open Weapons' WHERE `style` = 'Other';
```

- [ ] **Step 2: Run the migration**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-01-bracket-style-open-weapons.sql`
Expected: no error output.

- [ ] **Step 3: Verify enum + data**

Run:
```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_bracket LIKE 'style'\G"
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT style, COUNT(*) FROM ork_bracket GROUP BY style;"
```
Expected: the `Type` includes `'Open Weapons'`; **zero** rows with `style='Other'` (all migrated to `'Open Weapons'`).

- [ ] **Step 4: Commit**
```bash
git add db-migrations/2026-06-01-bracket-style-open-weapons.sql
git commit -m "Enhancement: split tournament weapon style into Open Weapons + Other (migration)"
```

### Task A2: Update schema seed (`ork.sql`)

- [ ] **Step 1: Update both enum definitions**

For each of `ork.sql:210` and `ork.sql:427`, replace:
```
  `style` enum('Single Sword','Florentine','Sword and Shield','Great Weapon','Missile','Other','Jugging','Battlegame','Quest') NOT NULL,
```
with:
```
  `style` enum('Single Sword','Florentine','Sword and Shield','Great Weapon','Missile','Other','Jugging','Battlegame','Quest','Open Weapons') NOT NULL,
```
Use Python with `replace(OLD, NEW)` (no count limit — both occurrences are identical, intentionally replace both).

- [ ] **Step 2: Verify**

Run: `grep -c "Open Weapons" ork.sql`
Expected: `2`.

- [ ] **Step 3: Commit**
```bash
git add ork.sql
git commit -m "Enhancement: add Open Weapons to ork_bracket style enum in schema seed"
```

### Task A3: Update the four template UI touchpoints + simulate script

- [ ] **Step 1: Label map (`Tournametnew_index.tpl:83`)**

Replace:
```php
	'Other'           => 'Open',
```
with:
```php
	'Other'           => 'Other',
	'Open Weapons'    => 'Open Weapons',
```

- [ ] **Step 2: Add-bracket dropdown (`:3056`)**

Replace:
```html
						<option value="Other">Other / Open</option>
```
with:
```html
						<option value="Open Weapons">Open Weapons</option>
						<option value="Other">Other</option>
```

- [ ] **Step 3: Edit-bracket dropdown (`:3182`)**

Identical replacement to Step 2 (the second occurrence of `<option value="Other">Other / Open</option>`). Read the file region first; use `replace(OLD, NEW, 1)` after confirming only the second instance remains (Step 2 already consumed the first), or operate on the unique surrounding context.

- [ ] **Step 4: JS `STYLE_OPTS` (`:5569`)**

Replace:
```javascript
		['Quest','Quest'], ['Other','Other / Open']
```
with:
```javascript
		['Quest','Quest'], ['Open Weapons','Open Weapons'], ['Other','Other']
```

- [ ] **Step 5: Simulate script**

In `scripts/simulate_tournament.php`, find the `$all_styles` array containing `'Other'` and add `'Open Weapons'`:
```php
$all_styles  = ['Single Sword', 'Florentine', 'Sword and Shield', 'Great Weapon', 'Missile', 'Other', 'Open Weapons'];
```

- [ ] **Step 6: Verify no stale "Open" mapping remains**

Run: `grep -n "Other / Open\|'Other'.*=>.*'Open'" orkui/template/revised-frontend/Tournametnew_index.tpl`
Expected: no matches.

- [ ] **Step 7: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl scripts/simulate_tournament.php
git commit -m "Enhancement: show Open Weapons and Other as distinct weapon styles in tournament UI"
```

---

## Part B — Issue 1: Tiered (non-exclusionary) participant search

**Files:**
- Modify: `orkui/controller/controller.KingdomAjax.php` (`playersearch`, `:790`–`:855`)
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (TnConfig `:3893`, add-participant search `:6448`–`:6470`)

### Task B1: Add `scope=tiered` to the playersearch endpoint

**Behavior:** `scope=tiered` returns ALL kingdoms (no kingdom/park WHERE filter) but orders results park-tier (when a `ParkId` is supplied) → kingdom-tier → persona. Existing `own`/`exclude`/`all` behavior is unchanged.

- [ ] **Step 1: Read the current method**

Read `orkui/controller/controller.KingdomAjax.php:789`–`:856` to confirm current text.

- [ ] **Step 2: Add a tier-park param**

After the line (`:791`):
```php
		$park_id          = (int)($_GET['park_id']        ?? 0);
```
add:
```php
		$tier_park        = (int)($_GET['ParkId']         ?? 0); // tiered-scope park ranking
```

- [ ] **Step 3: Update the scope comment and add the `tiered` branch**

Change the comment on the `$scope` line (`:790`) from `// 'own' | 'exclude'` to `// 'own' | 'exclude' | 'all' | 'tiered'`.

In the scope `if/elseif` chain, after the `} elseif ($scope === 'all') {` block (`:830`–`:832`), add a new branch before the final `else`:
```php
		} elseif ($scope === 'tiered') {
			// Non-exclusionary: include every kingdom, but rank by proximity to the
			// event (same-park first when supplied, then same-kingdom, then everyone).
			$kingdom_clause = '';
			$park_clause    = '';
		}
```

- [ ] **Step 4: Add the park-tier ORDER fragment and inject it**

Immediately before the `$sql = "` assignment (`:838`), add:
```php
		$park_tier_order = ($scope === 'tiered' && $tier_park > 0)
			? "CASE WHEN m.park_id = {$tier_park} THEN 0 ELSE 1 END, "
			: '';
```
Then change the ORDER BY line (`:855`) from:
```php
			ORDER BY m.suspended ASC, m.active DESC, CASE WHEN m.kingdom_id = {$kid} THEN 0 ELSE 1 END, m.persona
```
to:
```php
			ORDER BY m.suspended ASC, m.active DESC, {$park_tier_order}CASE WHEN m.kingdom_id = {$kid} THEN 0 ELSE 1 END, m.persona
```

- [ ] **Step 5: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.KingdomAjax.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Verify endpoint returns cross-kingdom rows, kingdom-first**

In one curl-auth block (see Conventions), pick a kingdom id `KID` that has out-of-kingdom personas matching a common term, and a park id `PID` within it:
```bash
curl -s -b $J "${B}KingdomAjax/playersearch/KID&scope=tiered&ParkId=PID&q=ar" | python3 -m json.tool | head -40
```
Expected: rows from MORE than one `KingdomId` appear (proves the lock is gone), with same-park then same-kingdom personas ranked first. Compare against `&scope=own&q=ar` (kingdom-only) to confirm tiered returns a superset.

- [ ] **Step 7: Commit**
```bash
git add orkui/controller/controller.KingdomAjax.php
git commit -m "Enhancement: add tiered (park->kingdom->global) scope to KingdomAjax playersearch"
```

### Task B2: Point the tournament add-participant search at `scope=tiered`

- [ ] **Step 1: Expose parkId in TnConfig**

In `Tournametnew_index.tpl`, after the TnConfig `kingdomId:` line (`:3893`):
```php
	kingdomId:            <?= $tKingdomId ?>,
```
add:
```php
	parkId:               <?= $tParkId ?>,
```
(`$tParkId` is already defined at `:32` — verify with `grep -n "tParkId" Tournametnew_index.tpl`.)

- [ ] **Step 2: Update the add-participant search fetch**

Read `:6448`–`:6470`, then replace:
```javascript
				if (TnConfig.kingdomId > 0) {
					// Kingdom-scoped search (same endpoint as award modals)
					var url = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.kingdomId + '&q=' + encodeURIComponent(term);
					fetch(url)
						.then(function(r) { return r.json(); })
						.then(function(data) { tnAcRender(data); })
						.catch(function(err) {
							console.error('[AddParticipant] kingdom search failed:', err);
							tnAcClose();
						});
```
with:
```javascript
				if (TnConfig.kingdomId > 0) {
					// Tiered, non-exclusionary search: same-park -> same-kingdom -> everyone.
					var url = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.kingdomId
						+ '&scope=tiered'
						+ (TnConfig.parkId > 0 ? '&ParkId=' + TnConfig.parkId : '')
						+ '&q=' + encodeURIComponent(term);
					fetch(url)
						.then(function(r) { return r.json(); })
						.then(function(data) { tnAcRender(data); })
						.catch(function(err) {
							console.error('[AddParticipant] tiered search failed:', err);
							tnAcClose();
						});
```
(`tnFixedAcPosition` is already defined at `:5985` and the no-results/results branches in `tnAcRender` already call it — the in-modal dropdown positioning is handled. Do not change that.)

- [ ] **Step 3: Verify in the browser (Chrome) after implementation**

Per project rule, use Chrome only to verify. Open `http://localhost:19080/orkui/index.php?Route=Tournament/profile/173`, open the Add Participant modal, type a known out-of-kingdom persona; confirm it now appears in the dropdown and can be selected/added. Confirm same-kingdom results still rank first.

- [ ] **Step 4: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: tournament add-participant search uses tiered scope (finds out-of-kingdom players)"
```

---

## Part C — Issue 2: Always-visible advanced options + Individual/Team segmented toggle

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (markup `:3097`,`:3102`,`:3106`,`:3223`,`:3228`,`:3232`; JS `:5122`,`:5163`,`:5282`,`:5331`,`:5361`,`:5410`,`:12067`)

This part is frontend-only; the `Participants` POST contract (`individual`/`team`) and all PHP are unchanged. We reuse the existing `.tn-seg` segmented-control pattern (CSS `:431`, dark mode `:1218`) already used by `tn-editbracket-firstround`.

### Task C1: Add reusable seg helpers + participants click handlers

- [ ] **Step 1: Add helpers + click wiring**

Insert this IIFE near the other tournament JS helpers (place it AFTER the TnConfig block, e.g. right after `:3893`'s closing — find a stable spot at top-level script scope; do NOT guard with `getElementById` per project rule — guard with `if (!seg) return` inside the loop):
```javascript
// Issue 2: Individual/Team segmented-control helpers (mirrors tn-...-firstround).
function tnSegGet(id) {
	var el = document.getElementById(id); if (!el) return '';
	var a = el.querySelector('.tn-seg-btn.tn-seg-active');
	return a ? a.getAttribute('data-val') : '';
}
function tnSegSet(id, val) {
	var el = document.getElementById(id); if (!el) return;
	Array.prototype.forEach.call(el.querySelectorAll('.tn-seg-btn'), function(b) {
		b.classList.toggle('tn-seg-active', b.getAttribute('data-val') === val);
	});
}
(function() {
	['tn-addbracket-participants', 'tn-editbracket-participants'].forEach(function(id) {
		var seg = document.getElementById(id); if (!seg) return;
		seg.addEventListener('click', function(e) {
			var b = e.target.closest('.tn-seg-btn'); if (!b || b.disabled) return;
			Array.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(x) { x.classList.remove('tn-seg-active'); });
			b.classList.add('tn-seg-active');
		});
	});
})();
```

- [ ] **Step 2: Lint-free check (template renders)**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/revised-frontend/Tournametnew_index.tpl`
Expected: `No syntax errors detected` (the file is plain PHP).

- [ ] **Step 3: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: add Individual/Team segmented-control helpers"
```

### Task C2: Convert both Participants selects to `.tn-seg` and always-show advanced

- [ ] **Step 1: Add-bracket participants markup (`:3106`–`:3109`)**

Replace:
```html
						<select id="tn-addbracket-participants">
							<option value="individual">Individual</option>
							<option value="team">Team</option>
						</select>
```
with:
```html
						<div class="tn-seg" id="tn-addbracket-participants">
							<button type="button" class="tn-seg-btn tn-seg-active" data-val="individual">Individual</button>
							<button type="button" class="tn-seg-btn" data-val="team">Team</button>
						</div>
```

- [ ] **Step 2: Edit-bracket participants markup (`:3232`–`:3235`)**

Replace the identical `<select id="tn-editbracket-participants">…</select>` block with:
```html
						<div class="tn-seg" id="tn-editbracket-participants">
							<button type="button" class="tn-seg-btn tn-seg-active" data-val="individual">Individual</button>
							<button type="button" class="tn-seg-btn" data-val="team">Team</button>
						</div>
```

- [ ] **Step 3: Remove the advanced-options disclosure buttons (`:3097`, `:3223`)**

Delete both `<button type="button" class="tn-advanced-toggle" …>…</button>` elements (the full button including its inner `<i>`, label text, and `<span>` hint — read the block to get exact bounds). They span from `<button … class="tn-advanced-toggle" data-target="tn-addbracket-advanced"` through its closing `</button>`, and likewise for `tn-editbracket-advanced`.

- [ ] **Step 4: Always-show advanced containers (`:3102`, `:3228`)**

Replace `<div id="tn-addbracket-advanced" style="display:none">` with `<div id="tn-addbracket-advanced">`, and `<div id="tn-editbracket-advanced" style="display:none">` with `<div id="tn-editbracket-advanced">`.

- [ ] **Step 5: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: always show advanced bracket options; Individual/Team as segmented toggle"
```

### Task C3: Update the JS that reads/writes/gates the participants control

- [ ] **Step 1: Add-bracket Ironman gate (`:5122`–`:5126` region)**

Read `:5118`–`:5135`. Replace the select/option-based gate:
```javascript
		var pSel = document.getElementById('tn-addbracket-participants');
		var pTeamOpt = pSel ? pSel.querySelector('option[value="team"]') : null;
```
…and the body that does `pTeamOpt.disabled = isIronman; if (isIronman && pSel.value === 'team') pSel.value = 'individual';`
with a seg-based gate. The replacement function body should be:
```javascript
		function tnGateAddTeam(isIronman) {
			var seg = document.getElementById('tn-addbracket-participants'); if (!seg) return;
			var teamBtn = seg.querySelector('.tn-seg-btn[data-val="team"]'); if (!teamBtn) return;
			teamBtn.disabled = isIronman;
			teamBtn.style.opacity = isIronman ? '0.4' : '';
			teamBtn.style.cursor  = isIronman ? 'not-allowed' : '';
			if (isIronman && tnSegGet('tn-addbracket-participants') === 'team') tnSegSet('tn-addbracket-participants', 'individual');
		}
```
Keep the existing `mSel.addEventListener('change', …)` wiring that calls `tnGateAddTeam(isIronman)` (rename the call if the old inner function name differs). Read the block and preserve its structure; only swap the select/option mechanics for the seg mechanics above.

- [ ] **Step 2: Add-bracket submit (`:5163`)**

Replace:
```javascript
			fd.append('Participants', document.getElementById('tn-addbracket-participants').value);
```
with:
```javascript
			fd.append('Participants', tnSegGet('tn-addbracket-participants') || 'individual');
```

- [ ] **Step 3: Edit-bracket preselect (`:5282`)**

Replace:
```javascript
		document.getElementById('tn-editbracket-participants').value  = data.participants || 'individual';
```
with:
```javascript
		tnSegSet('tn-editbracket-participants', data.participants || 'individual');
```

- [ ] **Step 4: Remove the edit-bracket auto-expand block (`:5331`–`:5348` region)**

This block reads `document.getElementById('tn-editbracket-participants').value` and toggles `tn-editbracket-advanced` visibility — both obsolete now (advanced is always shown, and the element is no longer a `<select>` with `.value`). Read `:5325`–`:5352` and delete the entire IIFE/block that computes `nonDefault` and sets `adv.style.display`. (If it is a self-contained `(function(){ … })();`, remove it wholesale.)

- [ ] **Step 5: Edit-bracket Ironman gate (`:5361`–`:5365` region)**

Same transformation as Step 1, for the edit modal:
```javascript
		function tnGateEditTeam(isIronman) {
			var seg = document.getElementById('tn-editbracket-participants'); if (!seg) return;
			var teamBtn = seg.querySelector('.tn-seg-btn[data-val="team"]'); if (!teamBtn) return;
			teamBtn.disabled = isIronman;
			teamBtn.style.opacity = isIronman ? '0.4' : '';
			teamBtn.style.cursor  = isIronman ? 'not-allowed' : '';
			if (isIronman && tnSegGet('tn-editbracket-participants') === 'team') tnSegSet('tn-editbracket-participants', 'individual');
		}
```
Preserve the existing method-change listener wiring.

- [ ] **Step 6: Edit-bracket submit (`:5410`)**

Replace:
```javascript
			fd.append('Participants', document.getElementById('tn-editbracket-participants').value);
```
with:
```javascript
			fd.append('Participants', tnSegGet('tn-editbracket-participants') || 'individual');
```

- [ ] **Step 7: Remove the now-dead advanced-toggle handler (`:12067`)**

Read `:12060`–`:12090`. Delete the IIFE that does `document.querySelectorAll('.tn-advanced-toggle').forEach(...)` (the toggle buttons no longer exist). If it shares an IIFE with other live code, remove only the `.tn-advanced-toggle` querySelectorAll/forEach statement.

- [ ] **Step 8: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/revised-frontend/Tournametnew_index.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 9: Verify in Chrome**

Open `Tournament/profile/173`, open Add Bracket: advanced options are visible without clicking; Individual/Team is a left|right toggle that switches on click. Select method = Ironman → Team button becomes disabled/greyed and selection snaps to Individual. Create an individual and a team bracket; confirm each saves with the correct `participants` value:
```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT bracket_id, method, participants FROM ork_bracket ORDER BY bracket_id DESC LIMIT 4;"
```
Open Edit Bracket on a team bracket → the Team button is preselected. Walk both modals in dark mode (toggle theme) — `.tn-seg` dark styles apply; disabled Team button is legible.

- [ ] **Step 10: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: wire Individual/Team toggle into gating/submit/preselect; drop advanced disclosure JS"
```

---

## Part D — Issue 3: Withdrawal that lets a round-robin bracket finish (forfeit vs annul)

**Scope:** round-robin brackets (the reported case). Single/double-elim, Swiss, points, and Ironman keep current status behavior (no auto-resolution) — documented as follow-up. The forfeit-vs-annul modal only appears for round-robin brackets that already have ≥1 recorded result.

**Data model additions:**
- `ork_participant.withdraw_mode ENUM('forfeit','annul') NULL`
- `ork_match.voided TINYINT NOT NULL DEFAULT 0`
- `ork_match.auto_resolved TINYINT NOT NULL DEFAULT 0`

**Files:**
- Create: `db-migrations/2026-06-01-tournament-withdrawal-resolution.sql`
- Modify: `system/lib/ork3/class.Tournament.php` (`UpdateParticipantStatus` `:3237`; standings JOINs `:2192`,`:2247`; completion check `:1969`)
- Modify: `orkui/controller/controller.TournamentAjax.php` (`updateparticipantstatus` action `:656`)
- Modify: `orkui/model/model.Tournament.php` (`update_participant_status` `:164` — passes through, just confirm Mode flows)
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (`tnSetParticipantStatus` `:11696`)

### Task D1: Migration — add withdrawal columns

- [ ] **Step 1: Create migration**

Create `db-migrations/2026-06-01-tournament-withdrawal-resolution.sql`:
```sql
-- Issue 3: mid-tournament withdrawal resolution for round-robin brackets.
-- withdraw_mode: how a withdrawn participant's matches were resolved.
ALTER TABLE ork_participant
  ADD COLUMN `withdraw_mode` enum('forfeit','annul') NULL DEFAULT NULL;

-- voided: match excluded from standings + completion (annul). auto_resolved:
-- result was written by withdrawal forfeit (not a human) so undo can revert it.
ALTER TABLE ork_match
  ADD COLUMN `voided` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `auto_resolved` tinyint(1) NOT NULL DEFAULT 0;
```

- [ ] **Step 2: Run + verify**
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-01-tournament-withdrawal-resolution.sql
docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_participant LIKE 'withdraw_mode'; SHOW COLUMNS FROM ork_match LIKE 'voided'; SHOW COLUMNS FROM ork_match LIKE 'auto_resolved';"
```
Expected: all three columns exist with the right types/defaults.

- [ ] **Step 3: Update `ork.sql` schema seed**

Add the same columns to the `ork_participant` and `ork_match` CREATE TABLE blocks in `ork.sql` (find via `grep -n "CREATE TABLE.*ork_participant\b\|CREATE TABLE.*ork_match\b" ork.sql`; add the columns alongside existing ones like `status`/`bouts`).

- [ ] **Step 4: Commit**
```bash
git add db-migrations/2026-06-01-tournament-withdrawal-resolution.sql ork.sql
git commit -m "Enhancement: add withdrawal-resolution columns (withdraw_mode, match voided/auto_resolved)"
```

### Task D2: Standings + completion ignore voided matches

- [ ] **Step 1: Standings — team path JOIN (`:2192`)**

In `GetStandings`, the team-path match JOIN reads:
```php
				LEFT JOIN " . DB_PREFIX . "match m ON (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id) AND m.bracket_id = $bracket_id
```
Append `AND m.voided = 0` to its ON clause:
```php
				LEFT JOIN " . DB_PREFIX . "match m ON (m.participant_1_id = p.participant_id OR m.participant_2_id = p.participant_id) AND m.bracket_id = $bracket_id AND m.voided = 0
```

- [ ] **Step 2: Standings — individual path JOIN (`:2247`)**

Apply the identical `AND m.voided = 0` append to the individual-path match JOIN (same text, second occurrence). Read first; there are exactly two such JOIN lines — both get the suffix.

- [ ] **Step 3: Completion check (`:1969`)**

In `PostMatchResult`, the all-resolved check reads:
```php
			$unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0");
```
Add `AND voided = 0` so voided matches never block completion:
```php
			$unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
```

- [ ] **Step 4: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: exclude voided matches from standings and bracket-completion check"
```

### Task D3: Implement withdrawal resolution in `UpdateParticipantStatus`

Extend the existing method to accept an optional `Mode` and, for round-robin brackets, resolve matches so the bracket can complete. Add a private helper `resolveRoundRobinWithdrawals($bracket_id, $tournament_id)` that recomputes voided/forfeit state from the CURRENT set of withdrawn participants (idempotent — safe to run on every status change, handles multiple withdrawals and undo).

- [ ] **Step 1: Read the current method**

Read `system/lib/ork3/class.Tournament.php:3237`–`:3263`.

- [ ] **Step 2: Replace `UpdateParticipantStatus` body**

Replace the method (`:3237`–`:3263`) with:
```php
	public function UpdateParticipantStatus($request) {
		if (!$this->check_auth($request)) return NoAuthorization();

		$bracket_id     = (int)($request['BracketId'] ?? 0);
		$participant_id = (int)($request['ParticipantId'] ?? 0);
		$tournament_id  = (int)($request['TournamentId'] ?? 0);
		if (!valid_id($bracket_id))     return InvalidParameter('BracketId required');
		if (!valid_id($participant_id)) return InvalidParameter('ParticipantId required');

		$exists = $this->db->query(
			"SELECT participant_id FROM " . DB_PREFIX . "participant WHERE participant_id = :pid AND bracket_id = :bid",
			[':pid' => $participant_id, ':bid' => $bracket_id]
		);
		if (!$exists || !$exists->next()) return InvalidParameter('Participant not found in this bracket.');

		$status  = trim($request['Status'] ?? '');
		$allowed = ['active', 'withdrawn', 'disqualified'];
		if (!in_array($status, $allowed, true)) {
			return InvalidParameter('Invalid status. Allowed: ' . implode(', ', $allowed));
		}

		// Withdrawal resolution mode (round-robin only): 'forfeit' | 'annul'.
		$mode = trim($request['Mode'] ?? '');
		if (!in_array($mode, ['forfeit', 'annul'], true)) $mode = '';

		// Look up tournament + method for resolution dispatch.
		$brow = $this->db->query("SELECT tournament_id, method FROM " . DB_PREFIX . "bracket WHERE bracket_id = $bracket_id LIMIT 1");
		$brow && $brow->next();
		$b_tid  = $brow ? (int)$brow->tournament_id : $tournament_id;
		$method = $brow ? (string)$brow->method : '';

		$this->db->query('START TRANSACTION');
		try {
			// Set status. Clear withdraw_mode on re-activation; otherwise store the
			// chosen mode (defaulting annulled DQs/withdrawals with no mode to 'forfeit'
			// so elimination/other formats keep prior block behavior unaffected).
			if ($status === 'active') {
				$this->db->query(
					"UPDATE " . DB_PREFIX . "participant SET status = 'active', withdraw_mode = NULL WHERE participant_id = $participant_id AND bracket_id = $bracket_id"
				);
			} else {
				$mode_sql = ($method === 'round-robin' && $mode !== '') ? "'" . $mode . "'" : 'NULL';
				$this->db->query(
					"UPDATE " . DB_PREFIX . "participant SET status = '" . $status . "', withdraw_mode = $mode_sql WHERE participant_id = $participant_id AND bracket_id = $bracket_id"
				);
			}

			// Round-robin: recompute match voiding/forfeits from current state so the
			// bracket can complete without a manual regenerate.
			if ($method === 'round-robin') {
				$this->resolveRoundRobinWithdrawals($bracket_id, $b_tid);
			}

			$this->db->query('COMMIT');
		} catch (\Throwable $e) {
			$this->db->query('ROLLBACK');
			throw $e;
		}

		$this->bustTournamentReportCache();
		return Success(['ParticipantId' => $participant_id, 'Status' => $status, 'Mode' => $mode]);
	}

	/**
	 * Recompute round-robin match resolution from the current withdrawn set.
	 * Idempotent: derives every match's state from participant status/withdraw_mode,
	 * so it is safe to call on each status change (multiple withdrawals, re-activation).
	 *
	 *  - annul-withdrawn participant: ALL their matches voided=1 (excluded everywhere).
	 *  - forfeit-withdrawn participant: their UNPLAYED matches get the opponent-win
	 *    result written (auto_resolved=1); already-played matches are left intact.
	 *  - re-activated participant: matches auto_resolved by a prior forfeit are reset
	 *    to unplayed; voided flags fall away because they are recomputed from scratch.
	 */
	private function resolveRoundRobinWithdrawals(int $bracket_id, int $tournament_id) {
		// 1) Reset all derived state for this bracket to a clean slate.
		//    Clear voided everywhere; revert ONLY auto-written results (never human ones).
		$this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 0 WHERE bracket_id = $bracket_id");
		$this->db->query("UPDATE " . DB_PREFIX . "match SET result = NULL, score = NULL, auto_resolved = 0 WHERE bracket_id = $bracket_id AND auto_resolved = 1");

		// 2) Load current non-active participants and their mode.
		$pr = $this->db->query("SELECT participant_id, status, withdraw_mode FROM " . DB_PREFIX . "participant WHERE bracket_id = $bracket_id AND status NOT IN ('active','')");
		$annul = []; $forfeit = [];
		if ($pr) {
			while ($pr->next()) {
				$pid  = (int)$pr->participant_id;
				$wm   = (string)$pr->withdraw_mode;
				if ($wm === 'annul') $annul[] = $pid;
				else                 $forfeit[] = $pid; // default (incl. disqualified / no mode) = forfeit
			}
		}

		// 3) Annul: void every match touching an annulled participant.
		if (!empty($annul)) {
			$list = implode(',', array_map('intval', $annul));
			$this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 1
				WHERE bracket_id = $bracket_id AND (participant_1_id IN ($list) OR participant_2_id IN ($list))");
		}

		// 4) Forfeit: for each forfeited participant, write opponent-win results on their
		//    UNPLAYED, non-voided matches (where the opponent is a real, active participant).
		foreach ($forfeit as $pid) {
			// Opponent is p2 -> '1-wins' (p1 wins); withdrawn is p1 -> '2-wins'.
			$ms = $this->db->query("SELECT match_id, participant_1_id, participant_2_id FROM " . DB_PREFIX . "match
				WHERE bracket_id = $bracket_id AND voided = 0 AND (result IS NULL OR result = '')
				  AND (participant_1_id = $pid OR participant_2_id = $pid)
				  AND participant_1_id > 0 AND participant_2_id > 0");
			$rows = [];
			if ($ms) { while ($ms->next()) { $rows[] = [(int)$ms->match_id, (int)$ms->participant_1_id, (int)$ms->participant_2_id]; } }
			foreach ($rows as $row) {
				[$mid, $p1, $p2] = $row;
				$res = ($p1 === $pid) ? '2-wins' : '1-wins'; // opponent wins
				$this->db->query("UPDATE " . DB_PREFIX . "match SET result = '$res', auto_resolved = 1
					WHERE match_id = $mid AND (result IS NULL OR result = '')");
			}
		}

		// 5) If all non-voided matches are now resolved, mark the bracket complete.
		$unresolved = $this->db->query("SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "match
			WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '') AND participant_1_id > 0 AND participant_2_id > 0 AND voided = 0");
		if ($unresolved && $unresolved->next() && (int)$unresolved->cnt === 0) {
			$this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'complete' WHERE bracket_id = $bracket_id AND status NOT IN ('finalized','setup')");
		} else {
			// Re-activation re-opened matches -> bring a previously-complete bracket back to active.
			$this->db->query("UPDATE " . DB_PREFIX . "bracket SET status = 'active' WHERE bracket_id = $bracket_id AND status = 'complete'");
		}
	}
```

> Note on edge case: a match between two forfeit-withdrawn participants that was never played stays unresolved (both withdrew, no winner). It is excluded from completion only if voided; since neither is annulled it is NOT voided. Handle by also voiding any unplayed match where BOTH participants are non-active — add, inside step 4's loop guard, a final sweep after the loop:
```php
		// Unplayed matches between two non-active participants have no rightful winner: void them.
		$nonActive = array_merge($annul, $forfeit);
		if (!empty($nonActive)) {
			$na = implode(',', array_map('intval', $nonActive));
			$this->db->query("UPDATE " . DB_PREFIX . "match SET voided = 1
				WHERE bracket_id = $bracket_id AND (result IS NULL OR result = '')
				  AND participant_1_id IN ($na) AND participant_2_id IN ($na)");
		}
```
Insert that sweep BETWEEN step 4 (forfeit loop) and step 5 (completion check).

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/system/lib/ork3/class.Tournament.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**
```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: round-robin withdrawal resolution (forfeit/annul) in UpdateParticipantStatus"
```

### Task D4: Thread `Mode` through controller + model

- [ ] **Step 1: Controller (`:665`–`:671`)**

In the `updateparticipantstatus` action, add `Mode` to the params passed to `update_participant_status`:
```php
			$r = $this->Tournament->update_participant_status([
				'Token'         => $this->session->token,
				'TournamentId'  => $tid,
				'BracketId'     => $bracket_id,
				'ParticipantId' => $participant_id,
				'Status'        => trim($_POST['Status'] ?? ''),
				'Mode'          => trim($_POST['Mode'] ?? ''),
			]);
```

- [ ] **Step 2: Model — confirm pass-through (`:164`)**

`update_participant_status` (`orkui/model/model.Tournament.php:164`) is `return $this->Tournament->UpdateParticipantStatus($request);` — it forwards the whole `$request`, so `Mode` flows automatically. No change needed; verify by reading the line.

- [ ] **Step 3: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.TournamentAjax.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**
```bash
git add orkui/controller/controller.TournamentAjax.php
git commit -m "Enhancement: pass withdrawal Mode through tournament status AJAX action"
```

### Task D5: Frontend — forfeit/annul modal on withdraw (round-robin)

The status menu calls `tnSetParticipantStatus(pid, status, bid, menuItemEl)`. For round-robin brackets with recorded results, intercept `withdrawn`/`disqualified` to ask forfeit-vs-annul (default per FIDE 50% rule) before posting `Mode`. Reuse the in-product `tnConfirm({...})` modal pattern (no native dialogs).

- [ ] **Step 1: Read current function + locate bracket/match JS state**

Read `Tournametnew_index.tpl:11696`–`:11760`. Identify (grep) how the current bracket's `method` and its matches are available to JS on the page (e.g. a `TnConfig`/bracket data object or the rendered match dataset used near `:8111`). Determine `playedCount` / `totalCount` for a participant from that in-memory data. If match data is not readily available client-side, compute the suggested default as `forfeit` (safe majority case) — the organizer can still switch.

- [ ] **Step 2: Add a withdrawal-intent wrapper**

Add this function near `tnSetParticipantStatus` (top-level script scope):
```javascript
// Issue 3: for round-robin brackets with recorded results, choose how to resolve a
// withdrawal (forfeit vs annul) before applying status. Other formats apply directly.
window.tnWithdrawIntent = function(pid, status, bid, menuItemEl) {
	var isRR = (typeof tnBracketMethod === 'function') ? (tnBracketMethod(bid) === 'round-robin') : false;
	var played = (typeof tnParticipantPlayedFraction === 'function') ? tnParticipantPlayedFraction(pid, bid) : 1;
	if (!isRR) { window.tnSetParticipantStatus(pid, status, bid, menuItemEl, ''); return; }
	// FIDE-style default: <50% played -> annul, >=50% -> forfeit.
	var defMode = (played < 0.5) ? 'annul' : 'forfeit';
	tnConfirm({
		title: (status === 'disqualified' ? 'Disqualify' : 'Withdraw') + ' participant',
		body:
			'<p style="margin:0 0 10px">How should this round-robin participant’s matches be resolved?</p>' +
			'<label style="display:block;margin-bottom:8px;cursor:pointer"><input type="radio" name="tn-wd-mode" value="forfeit"' + (defMode === 'forfeit' ? ' checked' : '') + '> <strong>Forfeit</strong> — already-fought matches stand; remaining matches become wins for their opponents.</label>' +
			'<label style="display:block;cursor:pointer"><input type="radio" name="tn-wd-mode" value="annul"' + (defMode === 'annul' ? ' checked' : '') + '> <strong>Annul</strong> — all of their matches stop counting toward everyone’s standings.</label>',
		confirmLabel: (status === 'disqualified' ? 'Disqualify' : 'Withdraw'),
		danger: true,
		onConfirm: function() {
			var sel = document.querySelector('input[name="tn-wd-mode"]:checked');
			var mode = sel ? sel.value : defMode;
			window.tnSetParticipantStatus(pid, status, bid, menuItemEl, mode);
		}
	});
};
```
If `tnBracketMethod` / `tnParticipantPlayedFraction` helpers do not already exist, add minimal versions that read the page's bracket/match data identified in Step 1 (e.g. a lookup over the rendered bracket method attribute and a count of that participant's matches with a non-empty result). Keep them small and data-source-specific.

- [ ] **Step 3: Make `tnSetParticipantStatus` accept + post `Mode`**

In `tnSetParticipantStatus` (`:11696`), add a `mode` parameter and append it to the FormData:
```javascript
		window.tnSetParticipantStatus = function(pid, status, bid, menuItemEl, mode) {
	var fd = new FormData();
	fd.append('ParticipantId', pid);
	fd.append('Status', status);
	fd.append('TournamentId', TnConfig.tournamentId);
	if (mode) fd.append('Mode', mode);
```
(Leave the rest of the function — DOM updates, pills — unchanged. After a successful round-robin withdrawal, also refresh standings/matches if the page exposes a refresh function; otherwise the existing pill/strikethrough update is sufficient and a manual refresh shows the completed bracket.)

- [ ] **Step 4: Route the menu's withdraw/DQ clicks through the intent wrapper**

The two status-menu markup blocks (`:2573`, `:2594`) call `tnSetParticipantStatus(pid, 'withdrawn'|'disqualified', bid, this)` directly. Change the `withdrawn` and `disqualified` menu items to call `tnWithdrawIntent(...)` instead (leave `active` calling `tnSetParticipantStatus(..., 'active', ..., this)` directly — re-activation needs no mode). These are PHP-rendered `onclick` strings; update both occurrences. Example (per item):
```php
onclick="tnWithdrawIntent(<?= (int)$p['ParticipantId'] ?>, 'withdrawn', <?= $bid ?>, this)"
```
and for disqualified:
```php
onclick="tnWithdrawIntent(<?= (int)$p['ParticipantId'] ?>, 'disqualified', <?= $bid ?>, this)"
```
Keep `active` as `onclick="tnSetParticipantStatus(<?= (int)$p['ParticipantId'] ?>, 'active', <?= $bid ?>, this)"` — note the menu-driven delegation at `:11635` parses a 3-arg `tnSetParticipantStatus(d,'x',d` regex; since `active` still matches that and withdraw/DQ now go through `tnWithdrawIntent`, verify the delegation parser (`:11617`–`:11640`) still works or update its regex to also recognize `tnWithdrawIntent`.

- [ ] **Step 5: Lint**

Run: `docker exec ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/template/revised-frontend/Tournametnew_index.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**
```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: forfeit/annul withdrawal modal for round-robin participants"
```

### Task D6: End-to-end verification of withdrawal resolution

- [ ] **Step 1: Seed a round-robin bracket with partial results**

Use an existing round-robin bracket on tournament 173 (or create one and record a few match results via the UI). Capture the bracket id `BID` and a participant id `PID` who has played some but not all matches:
```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT bracket_id, method, status FROM ork_bracket WHERE method='round-robin' ORDER BY bracket_id DESC LIMIT 5;"
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT match_id, participant_1_id, participant_2_id, result, voided FROM ork_match WHERE bracket_id=BID ORDER BY round, \`match\`;"
```

- [ ] **Step 2: Test FORFEIT via curl**

In one curl-auth block, withdraw `PID` with `Mode=forfeit`:
```bash
curl -s -b $J "${B}TournamentAjax/bracket/BID/updateparticipantstatus" --data "TournamentId=173&ParticipantId=PID&Status=withdrawn&Mode=forfeit"
```
Then check matches + bracket:
```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT match_id, participant_1_id, participant_2_id, result, voided, auto_resolved FROM ork_match WHERE bracket_id=BID;"
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT status FROM ork_bracket WHERE bracket_id=BID;"
```
Expected: `PID`'s previously-played matches keep their original `result` (auto_resolved=0); their UNPLAYED matches now have a directional win for the opponent (`auto_resolved=1`); no match is voided; if that was the only gap, bracket `status='complete'`.

- [ ] **Step 3: Test ANNUL (re-activate then annul)**

```bash
curl -s -b $J "${B}TournamentAjax/bracket/BID/updateparticipantstatus" --data "TournamentId=173&ParticipantId=PID&Status=active"
curl -s -b $J "${B}TournamentAjax/bracket/BID/updateparticipantstatus" --data "TournamentId=173&ParticipantId=PID&Status=withdrawn&Mode=annul"
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT match_id, participant_1_id, participant_2_id, result, voided, auto_resolved FROM ork_match WHERE bracket_id=BID;"
```
Expected after re-activate: `PID`'s auto_resolved matches reset to `result=NULL, auto_resolved=0`, voided cleared, bracket back to `active`. After annul: every match touching `PID` has `voided=1`; the previously auto-written forfeit results are gone.

- [ ] **Step 4: Verify standings reflect annul**

```bash
curl -s -b $J "${B}TournamentAjax/bracket/BID/standings" 2>/dev/null || true
```
(Use the actual standings endpoint/route the page uses — find via grep for `standings` in the controller.) Expected: opponents' win/loss counts no longer include their game vs the annulled `PID`; `PID` shows 0–0.

- [ ] **Step 5: Two-team withdrawal (the reported scenario)**

Withdraw a SECOND participant `PID2` (annul). Confirm the bracket still completes and the match between `PID` and `PID2` is voided (not stuck unresolved). This reproduces the original playtest pain and proves no manual regenerate is needed.

- [ ] **Step 6: Browser sanity (Chrome)**

On `Tournament/profile/173`, open a round-robin bracket with results, use the participant status menu → Withdrawn → confirm the forfeit/annul modal appears with the FIDE-based default preselected, choose one, and confirm the bracket resolves (standings update on refresh). Walk the modal in dark mode.

- [ ] **Step 7: Final commit (if any verification fixes were needed)**

Stage only the touched files explicitly and commit with a descriptive `Enhancement:`/`Bugfix:` message.

---

## Self-Review (completed during planning)

- **Spec coverage:** Issue 1 → Part B. Issue 2 → Part C. Issue 3 → Part D (scoped to round-robin, per the reported case; other formats documented as follow-up). Issue 4 → Part A. ✔
- **Type/name consistency:** `tnSegGet`/`tnSegSet` defined in C1, used in C3. `resolveRoundRobinWithdrawals` defined + called in D3. `tnWithdrawIntent` defined in D5 and referenced from the menu markup edit in D5. `Mode` param flows controller (D4) → model (pass-through) → service (D3) → consumed; frontend posts it (D5). `voided`/`auto_resolved`/`withdraw_mode` created in D1, consumed in D2/D3. ✔
- **Known follow-ups (out of scope, intentionally):** withdrawal auto-resolution for single/double-elim, Swiss, points, and Ironman; re-pairing/re-seeding remaining RR matches after annul; visually flagging withdrawn rows in the standings table beyond the existing WD/DQ pills.
```
