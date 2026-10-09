# Bracket "Play-In" First-Round Option — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a tournament organizer display a bye-dominated single/double-elimination first round as a labeled "Play-In" round (showing only contested matches) instead of a long column of byes.

**Architecture:** A display-only per-bracket flag `first_round_mode` (`byes` | `play-in`). Match generation and advancement are untouched — play-in and byes are two presentations of the identical bracket. The Edit-Bracket modal offers the choice only when the bracket's participant count would make byes dominate round 1; the bracket visualization relabels round 1 "Play-In" and hides bye boxes, self-guarding on the actual presence of byes.

**Tech Stack:** PHP 8 (yapo ORM), MariaDB, vanilla JS in a `.tpl` template, Docker.

**Spec:** `docs/superpowers/specs/2026-05-24-bracket-play-in-round-design.md`

**Project conventions (read before editing):**
- Multi-line PHP/JS/TPL edits: use Python `str.replace`, never the Edit tool (tab-vs-space mismatches). Single-line unambiguous edits may use Edit.
- Stage files explicitly (`git add <path>`); never `git add -A`/`.`. Never stage `class.Authorization.php`, `CLAUDE.md`, or `agent-instructions/claude.md`.
- All new front-end surfaces must be dark-mode compatible.
- Migrations run via: `docker exec -i ork3-php8-db mariadb -u root -proot ork < <file>`.
- No automated PHP/JS test harness exists; "tests" here are concrete DB queries, JS console assertions, and browser checks. Run each verification and confirm its stated expected output before checking the box.

---

## Task 1: Add the `first_round_mode` column

**Files:**
- Create: `db-migrations/2026-05-24-bracket-first-round-mode.sql`
- Modify: `ork.sql` (the `CREATE TABLE ork_bracket` block, ~line 207-217)

- [ ] **Step 1: Write the migration file**

Create `db-migrations/2026-05-24-bracket-first-round-mode.sql`:

```sql
-- Per-bracket display flag: how to present a bye-dominated first round.
-- 'byes'    = legacy layout (every X-vs-Bye match shown in round 1)
-- 'play-in' = round 1 shows only contested matches, labeled "Play-In"
-- Display-only: does not affect match generation or advancement.
ALTER TABLE ork_bracket
  ADD COLUMN first_round_mode ENUM('byes','play-in') NOT NULL DEFAULT 'byes' AFTER best_of;
```

- [ ] **Step 2: Apply the migration**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-24-bracket-first-round-mode.sql
```
Expected: no output (success).

- [ ] **Step 3: Verify the column exists with the right default**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_bracket LIKE 'first_round_mode';"
```
Expected: one row, `Type` = `enum('byes','play-in')`, `Default` = `byes`, `Null` = `NO`.

- [ ] **Step 4: Verify existing brackets are unchanged**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT bracket_id, method, first_round_mode FROM ork_bracket LIMIT 10;"
```
Expected: every existing row shows `first_round_mode` = `byes`.

- [ ] **Step 5: Mirror the column in `ork.sql` for fresh installs**

In `ork.sql`, the `CREATE TABLE IF NOT EXISTS \`ork_bracket\`` block currently ends with the `seeding` column then `PRIMARY KEY`. Note that `best_of`, `status`, `duration_minutes` were added by later migrations and may or may not be present in `ork.sql`; insert the new column on its own line just before `PRIMARY KEY (\`bracket_id\`)`. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('ork.sql'); t = p.read_text()
needle = \"  PRIMARY KEY (\`bracket_id\`)\n) ENGINE=MyISAM\"
# Only patch the bracket table's PK line (first occurrence after the bracket CREATE)
i = t.index('CREATE TABLE IF NOT EXISTS \`ork_bracket\`')
j = t.index(needle, i)
print('found bracket PK at', j)
assert 'first_round_mode' not in t[i:j], 'already present'
t = t[:j] + \"  \`first_round_mode\` enum('byes','play-in') NOT NULL DEFAULT 'byes',\n\" + t[j:]
p.write_text(t)
print('patched ork.sql')
"
```
Expected: prints `found bracket PK at <n>` then `patched ork.sql`.

- [ ] **Step 6: Commit**

```bash
git add db-migrations/2026-05-24-bracket-first-round-mode.sql ork.sql
git commit -m "Enhancement: add ork_bracket.first_round_mode column (byes|play-in)"
```

---

## Task 2: Surface and persist the flag in the backend

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` (GetBrackets ~331-344; UpdateBracket ~300-314)
- Modify: `orkui/controller/controller.TournamentAjax.php` (updatebracket action ~151-163)

- [ ] **Step 1: Add `FirstRoundMode` to the GetBrackets mapping**

In `class.Tournament.php`, the `GetBrackets` result array currently ends each row with `'TiebreakerDeclined' => (int)$r->tiebreaker_declined,`. Add the new field. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php'); t = p.read_text()
old = \"\t\t\t\t\t'TiebreakerDeclined' => (int)\$r->tiebreaker_declined,\n\"
new = old + \"\t\t\t\t\t'FirstRoundMode' => \$r->first_round_mode,\n\"
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 2: Persist `first_round_mode` in UpdateBracket (always-editable group)**

In `UpdateBracket`, the cosmetic/always-editable block ends with the `BestOf` line:
`if (isset($request['BestOf']))          $this->Bracket->best_of          = self::normalize_best_of($request['BestOf']);`
Add the new field immediately after it (display-only ⇒ NOT inside the `$is_setup` gate). Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('system/lib/ork3/class.Tournament.php'); t = p.read_text()
old = \"\t\tif (isset(\$request['BestOf']))          \$this->Bracket->best_of          = self::normalize_best_of(\$request['BestOf']);\n\"
new = old + \"\t\tif (isset(\$request['FirstRoundMode'])) \$this->Bracket->first_round_mode = (in_array(\$request['FirstRoundMode'], ['byes','play-in'], true) ? \$request['FirstRoundMode'] : 'byes');\n\"
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 3: Forward `FirstRoundMode` from the updatebracket AJAX action**

In `controller.TournamentAjax.php`, the `update_bracket([...])` request array ends with `'BestOf' => (int)($_POST['BestOf'] ?? 1),`. Add the new key after it. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/controller/controller.TournamentAjax.php'); t = p.read_text()
old = \"\t\t\t\t'BestOf'          => (int)(\$_POST['BestOf'] ?? 1),\n\"
new = old + \"\t\t\t\t'FirstRoundMode'  => trim(\$_POST['FirstRoundMode'] ?? 'byes'),\n\"
print('found:', t.count(old), 'occurrence(s)')
# updatebracket and addbracket both have a BestOf line; patch only the updatebracket one.
i = t.index(\"\$action === 'updatebracket'\")
j = t.index(old, i)
t = t[:j] + new + t[j+len(old):]
p.write_text(t)
print('patched updatebracket')
"
```
Expected: prints a count ≥ 1, then `patched updatebracket`.

- [ ] **Step 4: Lint the PHP files**

Run:
```bash
docker exec ork3-php8 php -l /var/www/html/system/lib/ork3/class.Tournament.php
docker exec ork3-php8 php -l /var/www/html/orkui/controller/controller.TournamentAjax.php
```
Expected: `No syntax errors detected` for both. (If the container name differs, run `docker ps --format '{{.Names}}'` and use the PHP app container.)

- [ ] **Step 5: Verify the round-trip with a direct DB write + read-back**

Pick an existing single/double-elim bracket id from Task 1 Step 4 (call it `<BID>`), then:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_bracket SET first_round_mode='play-in' WHERE bracket_id=<BID>; SELECT bracket_id, first_round_mode FROM ork_bracket WHERE bracket_id=<BID>;"
```
Expected: the row shows `first_round_mode` = `play-in`. (Leave it set to `play-in` — Task 5 uses it for visual verification.)

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Tournament.php orkui/controller/controller.TournamentAjax.php
git commit -m "Enhancement: persist/expose bracket first_round_mode via update + GetBrackets"
```

---

## Task 3: JS trigger helper `tnShouldOfferPlayIn`

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (add helper near the other bracket-viz helpers, just above `function renderSection` at ~line 7002)

The helper is a pure function: should the play-in option be *offered* for a bracket with this method and participant count?

- [ ] **Step 1: Add the helper function**

Insert immediately before `function renderSection(wrap, matches, pMap, side) {`. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
anchor = '\tfunction renderSection(wrap, matches, pMap, side) {'
helper = '''\t// Should the 'Play-In' first-round option be offered for this bracket?
\t// True only for single/double elim where the field is not a power of two AND
\t// contested round-1 matches (N - P/2) are fewer than half the round-1 slots
\t// (P/4) -- i.e. byes outnumber real matches at least 2:1. See spec table.
\t// Attached to window so the edit-bracket modal code (a separate IIFE) can call it.
\twindow.tnShouldOfferPlayIn = function(method, n) {
\t\tn = parseInt(n) || 0;
\t\tif (method !== 'single' && method !== 'double') return false;
\t\tif (n < 3) return false;
\t\tvar P = 1; while (P < n) P *= 2;          // next power of two >= n
\t\tif (P === n) return false;                 // power of two -> no byes
\t\tvar contested = n - P / 2;
\t\treturn contested < P / 4;
\t};

'''
print('found:', anchor in t)
p.write_text(t.replace(anchor, helper + anchor, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 2: Verify the trigger logic against the spec table (browser console)**

Load any tournament page that includes this template (e.g. `http://localhost:19080/orkui/Tournament/index/162`), open the browser console, and paste:

```javascript
[[5,'single',true],[6,'single',false],[7,'single',false],[8,'single',false],
 [9,'single',true],[11,'single',true],[12,'single',false],[16,'single',false],
 [9,'double',true],[11,'double',true],[9,'swiss',false],[9,'round-robin',false]]
.forEach(function(c){
  var got = tnShouldOfferPlayIn(c[1], c[0]);
  console.log((got===c[2]?'PASS':'FAIL'), 'n='+c[0], c[1], '->', got, 'expected', c[2]);
});
```
Expected: every line prints `PASS`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: add tnShouldOfferPlayIn trigger helper for bracket play-in"
```

---

## Task 4: Edit-Bracket modal field + wiring

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (edit-bracket modal HTML ~2858-2867; `tnOpenEditBracketModal` ~4502-4548; edit submit handler ~4582-4598)

- [ ] **Step 1: Add the field group to the edit-bracket modal HTML**

Insert a new field group inside the advanced section, immediately after the Style Note field group (which ends just before the `</div>` that closes `#tn-editbracket-advanced`). The Style Note group is:
```html
				<div class="tn-field">
					<label for="tn-editbracket-stylenote">Style Note <span style="color:#a0aec0;font-size:11px;font-weight:400">(optional)</span></label>
					<input type="text" id="tn-editbracket-stylenote" placeholder="e.g. No shields allowed, florentine only…" maxlength="255">
				</div>
```
Use Python to add the new group right after it:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
old = '''\t\t\t\t<div class=\"tn-field\">
\t\t\t\t\t<label for=\"tn-editbracket-stylenote\">Style Note <span style=\"color:#a0aec0;font-size:11px;font-weight:400\">(optional)</span></label>
\t\t\t\t\t<input type=\"text\" id=\"tn-editbracket-stylenote\" placeholder=\"e.g. No shields allowed, florentine only…\" maxlength=\"255\">
\t\t\t\t</div>
'''
new = old + '''\t\t\t\t<div class=\"tn-field\" id=\"tn-editbracket-firstround-field\" style=\"display:none\">
\t\t\t\t\t<label>How to handle the first round?</label>
\t\t\t\t\t<div class=\"tn-seg\" id=\"tn-editbracket-firstround\" role=\"radiogroup\">
\t\t\t\t\t\t<button type=\"button\" class=\"tn-seg-btn\" data-val=\"play-in\">Play-In for First Round Position</button>
\t\t\t\t\t\t<button type=\"button\" class=\"tn-seg-btn\" data-val=\"byes\">Assign Byes for First Round</button>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"tn-field-hint\" id=\"tn-editbracket-firstround-hint\"></div>
\t\t\t\t</div>
'''
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 2: Add styles for the segmented control (dark-mode safe)**

The template uses `.tn-field-row`/`.tn-field` form styling already. Add a small block for the segmented control near the other bracket-config CSS (insert right before the `/* Bracket visualization */` comment at ~line 323). Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
anchor = '/* Bracket visualization */'
css = '''.tn-seg { display:flex; gap:6px; flex-wrap:wrap; }
.tn-seg-btn { flex:1 1 0; min-width:120px; padding:8px 10px; font-size:12px; font-weight:600; line-height:1.2; text-align:center; border:1px solid var(--tn-border,#e2e8f0); border-radius:6px; background:var(--tn-surface,#fff); color:var(--tn-text,#2d3748); cursor:pointer; transition:border-color .15s,background .15s,color .15s; }
.tn-seg-btn:hover { border-color:#276749; }
.tn-seg-btn.tn-seg-active { background:#276749; border-color:#276749; color:#fff; }
.tn-field-hint { font-size:11px; color:var(--tn-text-muted,#718096); margin-top:6px; }
'''
print('found:', anchor in t)
p.write_text(t.replace(anchor, css + anchor, 1))
"
```
Expected: prints `found: True`.

NOTE on dark mode: confirm the CSS variables `--tn-border`/`--tn-surface`/`--tn-text`/`--tn-text-muted` are the ones this template actually uses. If the template does not define them, replace the `var(...)` fallbacks with the same color tokens the neighboring `.tn-field`/`select`/`.tn-btn-ghost` rules use, so the control matches existing dark-mode styling. Verify visually in Task 5 Step 6.

- [ ] **Step 3: Add segmented-control click wiring + populate in `tnOpenEditBracketModal`**

In `tnOpenEditBracketModal`, after the line that sets the duration field
(`if (_edFld) _edFld.style.display = (data.method === 'ironman') ? '' : 'none';`),
add code to (a) compute the bracket's participant count, (b) show/hide the field,
(c) set the active segment. Also add a one-time click handler for the segment
buttons. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
old = '\t\tif (_edFld) _edFld.style.display = (data.method === \\'ironman\\') ? \\'\\' : \\'none\\';\n'
new = old + '''\t\t// First-round mode (play-in vs byes): offer only when byes would dominate.
\t\t(function(){
\t\t\tvar fld = document.getElementById('tn-editbracket-firstround-field');
\t\t\tvar seg = document.getElementById('tn-editbracket-firstround');
\t\t\tvar hint = document.getElementById('tn-editbracket-firstround-hint');
\t\t\tif (!fld || !seg) return;
\t\t\tvar bd = (TnConfig.bracketData || {})[bracketId];
\t\t\tvar n = (bd && bd.Participants) ? bd.Participants.length : 0;
\t\t\tif (!tnShouldOfferPlayIn(data.method, n)) { fld.style.display = 'none'; return; }
\t\t\tfld.style.display = '';
\t\t\tvar P = 1; while (P < n) P *= 2;
\t\t\tvar byes = P - n;
\t\t\tif (hint) hint.textContent = 'This bracket would otherwise show ' + byes + ' byes in round 1.';
\t\t\t// Default to play-in when offered, unless a saved value exists.
\t\t\tvar saved = (bd && bd.Bracket && bd.Bracket.FirstRoundMode) ? bd.Bracket.FirstRoundMode : 'play-in';
\t\t\tArray.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(b){
\t\t\t\tb.classList.toggle('tn-seg-active', b.getAttribute('data-val') === saved);
\t\t\t});
\t\t})();
'''
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 4: Add the segment button click handler (once, near the other edit-modal listeners)**

Add a click handler so clicking a segment button toggles the active state. Insert just before the edit submit-button block (`var submitBtn = document.getElementById('tn-editbracket-submit');` at ~line 4580). Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
anchor = '\tvar submitBtn = document.getElementById(\\'tn-editbracket-submit\\');'
handler = '''\t(function(){
\t\tvar seg = document.getElementById('tn-editbracket-firstround');
\t\tif (!seg) return;
\t\tseg.addEventListener('click', function(e){
\t\t\tvar b = e.target.closest('.tn-seg-btn'); if (!b) return;
\t\t\tArray.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(x){ x.classList.remove('tn-seg-active'); });
\t\t\tb.classList.add('tn-seg-active');
\t\t});
\t})();
'''
print('found:', anchor in t)
p.write_text(t.replace(anchor, handler + anchor, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 5: Append `FirstRoundMode` to the edit submit FormData**

In the edit submit handler, the FormData block ends with
`fd.append('BestOf', document.getElementById('tn-editbracket-bestof').value || 1);`.
Add the new field after it, reading the active segment (defaulting `byes` when the
field is hidden / nothing active). Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
old = \"\t\t\t\tfd.append('BestOf',       document.getElementById('tn-editbracket-bestof').value || 1);\n\"
new = old + '''\t\t\t\tvar _fr = document.querySelector('#tn-editbracket-firstround .tn-seg-btn.tn-seg-active');
\t\t\t\tfd.append('FirstRoundMode', _fr ? _fr.getAttribute('data-val') : 'byes');
'''
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 6: Verify the modal end-to-end in the browser**

Open a tournament whose bracket triggers the option (e.g. tournament 162's bracket, or any single/double-elim bracket with N=9/11). Open the bracket's Edit modal (expand Advanced if needed):
- Confirm the **"How to handle the first round?"** field is visible with two buttons and the hint "This bracket would otherwise show N byes in round 1."
- Confirm one button is pre-active (the saved value, or Play-In by default).
- Click the other button → active state moves. Click Save.
- After reload, run:
  ```bash
  docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT bracket_id, first_round_mode FROM ork_bracket WHERE bracket_id=<BID>;"
  ```
  Expected: `first_round_mode` matches the button you saved.
- Open the Edit modal of a bracket that does NOT trigger (e.g. N=12, or a round-robin bracket): confirm the field is hidden.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Edit-Bracket modal 'How to handle the first round?' option"
```

---

## Task 5: Render the Play-In round in the bracket viz

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` (`renderSection` ~7002-7048)

When the active bracket is single/double elim, the section is the winners side
(`side === null` for single, `side === 'winners'` for double), the bracket's
`FirstRoundMode === 'play-in'`, AND round 1 actually contains a bye match: relabel
round 1 "Play-In" and replace each bye box with an invisible same-size spacer (no
`data-matchid`, so the connector code's `if (!box1) continue` skips it, keeping the
contested boxes in their normal aligned positions).

- [ ] **Step 1: Compute the play-in flag at the top of `renderSection`**

Insert right after the `var maxRound = 0;` / forEach block that builds `rounds`,
i.e. immediately before `var tree = document.createElement('div');`. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
anchor = '\t\tvar tree = document.createElement(\\'div\\');\n\t\ttree.className = \\'tn-bv-tree\\';'
calc = '''\t\t// Play-in presentation: only for single/double-elim winners side, when the
\t\t// bracket's FirstRoundMode is 'play-in' AND round 1 actually has bye matches.
\t\tvar _playIn = false;
\t\t(function(){
\t\t\tif (side !== null && side !== 'winners') return;
\t\t\tvar bid = matches.length ? matches[0].BracketId : null;
\t\t\tvar bd = (TnConfig.bracketData || {})[bid];
\t\t\tif (!bd || !bd.Bracket) return;
\t\t\tvar method = bd.Bracket.Method;
\t\t\tif (method !== 'single' && method !== 'double') return;
\t\t\tif (bd.Bracket.FirstRoundMode !== 'play-in') return;
\t\t\tvar r1 = (rounds[1] || []);
\t\t\tvar hasBye = r1.some(function(m){
\t\t\t\tvar a = parseInt(m.Participant1Id) || 0, b = parseInt(m.Participant2Id) || 0;
\t\t\t\treturn (!a && b) || (a && !b);
\t\t\t});
\t\t\t_playIn = hasBye;
\t\t})();

'''
print('found:', anchor in t)
p.write_text(t.replace(anchor, calc + anchor, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 2: Relabel round 1 as "Play-In"**

In the label `if/else` chain, the first branch is `if (side === 'grand-final')`.
Add a play-in branch ahead of it so round 1 gets "Play-In" while other rounds keep
their normal labels. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
old = '\t\t\tif (side === \\'grand-final\\') {\n\t\t\t\tlbl.textContent = \\'Grand Final\\';'
new = '\t\t\tif (_playIn && r === 1) {\n\t\t\t\tlbl.textContent = \\'Play-In\\';\n\t\t\t} else if (side === \\'grand-final\\') {\n\t\t\t\tlbl.textContent = \\'Grand Final\\';'
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 3: Replace round-1 bye boxes with invisible spacers**

The round body builder is:
```javascript
				rMatches.forEach(function(m) {
					body.appendChild(buildMatchBox(m, pMap, matches));
				});
```
Replace it so that, in play-in mode for round 1, bye matches render as a hidden
same-size spacer (built from `buildMatchBox` then stripped of its id and hidden) —
preserving layout and connector alignment. Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Tournametnew_index.tpl'); t = p.read_text()
old = '''\t\t\t\trMatches.forEach(function(m) {
\t\t\t\t\tbody.appendChild(buildMatchBox(m, pMap, matches));
\t\t\t\t});'''
new = '''\t\t\t\trMatches.forEach(function(m) {
\t\t\t\t\tif (_playIn && r === 1) {
\t\t\t\t\t\tvar a = parseInt(m.Participant1Id) || 0, b = parseInt(m.Participant2Id) || 0;
\t\t\t\t\t\tif ((!a && b) || (a && !b)) {
\t\t\t\t\t\t\t// Bye match: render an invisible spacer that preserves the slot
\t\t\t\t\t\t\t// position (and thus connector alignment) without drawing a box
\t\t\t\t\t\t\t// or a connector line (no data-matchid -> connector skips it).
\t\t\t\t\t\t\tvar sp = buildMatchBox(m, pMap, matches);
\t\t\t\t\t\t\tsp.removeAttribute('data-matchid');
\t\t\t\t\t\t\tsp.style.visibility = 'hidden';
\t\t\t\t\t\t\tbody.appendChild(sp);
\t\t\t\t\t\t\treturn;
\t\t\t\t\t\t}
\t\t\t\t\t}
\t\t\t\t\tbody.appendChild(buildMatchBox(m, pMap, matches));
\t\t\t\t});'''
print('found:', old in t)
p.write_text(t.replace(old, new, 1))
"
```
Expected: prints `found: True`.

- [ ] **Step 4: Verify the play-in render in the browser**

Using the bracket left at `first_round_mode='play-in'` (Task 2 Step 5 / Task 4
Step 6), reload its bracket view. Confirm:
- Round 1 column header reads **"Play-In"**.
- Only the contested match(es) show as visible boxes; the bye boxes are gone (their
  space is reserved, so the contested box stays aligned with its round-2 parent).
- Connector lines run cleanly from each play-in box into its round-2 match, with no
  stray lines where byes used to be.
- Round 2 still shows the bye/top-seed players exactly as before.
- The far-right labels (Final/Semifinal/Quarterfinal) are unchanged.

- [ ] **Step 5: Verify the byes mode is unchanged**

Set the same bracket back to byes and reload:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "UPDATE ork_bracket SET first_round_mode='byes' WHERE bracket_id=<BID>;"
```
Expected in the browser: round 1 renders the original full layout (every X-vs-Bye box visible, labeled "Round 1"). Set it back to `play-in` if you want to keep iterating.

- [ ] **Step 6: Dark-mode + double-elim check**

- Toggle the app into dark mode. Re-open the Edit modal and the play-in bracket
  view: confirm the segmented control, hint text, "Play-In" label, and match boxes
  all read clearly (no gray-box heading leak, no muted-on-muted text). Fix any
  contrast issues in the CSS from Task 4 Step 2.
- For a **double-elimination** bracket that triggers (set its `first_round_mode` to
  `play-in`): confirm the winners-bracket round 1 collapses to "Play-In" while the
  Losers Bracket and Grand Final sections are visually unaffected.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: render bye-dominated first round as a Play-In round"
```

---

## Task 6: Final verification sweep

- [ ] **Step 1: Re-confirm no behavioral regression for non-triggering brackets**

Open a normal power-of-two bracket and a bracket with N=12 (does not trigger):
confirm no "Play-In" label appears and round 1 renders normally, regardless of any
`first_round_mode` value (the viz self-guards on actual bye presence).

- [ ] **Step 2: Confirm the staged diff is limited to intended files**

Run:
```bash
git log --oneline -6
git diff --stat master...HEAD
```
Expected: changes only in `db-migrations/2026-05-24-bracket-first-round-mode.sql`,
`ork.sql`, `system/lib/ork3/class.Tournament.php`,
`orkui/controller/controller.TournamentAjax.php`,
`orkui/template/revised-frontend/Tournametnew_index.tpl`, and the spec/plan docs.
No `CLAUDE.md`, `agent-instructions/claude.md`, or `class.Authorization.php`.

- [ ] **Step 3: Request code review**

Use the superpowers:requesting-code-review skill (or the project's review flow) on
the branch diff before merge.
