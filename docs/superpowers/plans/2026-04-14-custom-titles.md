# Custom Titles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Custom Title" award option that lets givers bestow a named title aliased to a core non-officer title (e.g., Brother-in-Battle → Page). Aliased entries receive full peerage/report equivalency; display keeps the custom name with an `aka <alias>` subtitle. Edit modal supports reconciling existing Custom Awards into Custom Titles.

**Architecture:**
- New `ork_awards.alias_award_id` column (nullable) + new `ork_award` sentinel row "Custom Title".
- Full substitution at query time via `LEFT JOIN ork_award alias ON alias.award_id = aw.alias_award_id` + `COALESCE(alias.col, a.col)` for peerage, is_title, is_ladder, officer_role, title_class, peerage_rank.
- Display name stays custom-first: `COALESCE(NULLIF(aw.custom_name,''), ka.name, a.name)`.
- UI is in `Playernew_index.tpl` (CRM profile) — add/edit modals gain a "Custom Title" entry and alias dropdown, and rows in the Awards table and Titles tab render the `[Custom Title]` chip + `aka …` subtitle.

**Tech Stack:** PHP 8 / MariaDB 10 / vanilla JS. No test framework — verification via SQL checks + browser interaction.

**Spec:** [`docs/superpowers/specs/2026-04-14-custom-titles-design.md`](../specs/2026-04-14-custom-titles-design.md)

**Conventions:**
- PHP file edits: use Python `.read_text() / .write_text(.replace())` for any multi-line edit. Tab-vs-space mismatches make the Edit tool unreliable on PHP. Single-line edits OK via Edit tool.
- Debugging output goes to browser console via `console.log` or `die(json_encode(...))`.
- Always `$DB->Clear()` before raw Execute/DataSet.
- DB migrations run via: `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`.
- Never commit `class.Authorization.php` if it contains the `true ||` bypass hack.
- After any DB schema change or query rewrite, flush memcache: `docker exec ork3-php8-memcached sh -c 'echo flush_all | nc -q1 localhost 11211'` (use whichever container name is running; check with `docker ps`).

---

## File Map

**Create:**
- `db-migrations/2026-04-14-custom-titles.sql` — schema + sentinel insert

**Modify:**
- `system/lib/ork3/class.Player.php` — `add_player_award()`, `update_player_award()`, `get_award()` (return alias fields); new helper `GetCustomTitleAwardId()`
- `system/lib/ork3/class.Report.php` — `BeltlineData()` query gains alias join
- `orkui/controller/controller.Award.php` — `addawards` passes `AliasAwardId`
- `orkui/controller/controller.Player.php` — `updateaward` case passes `AliasAwardId`; legacy Titles + MyAssociates queries get alias join
- `orkui/controller/controller.Playernew.php` — build `$CustomTitleAliasOptions`; ensure Titles/Awards fetches return alias info
- `orkui/template/revised-frontend/Playernew_index.tpl` — add-award modal, edit-award modal, awards table rendering, titles tab rendering

---

## Task 1: Database Migration

**Files:**
- Create: `db-migrations/2026-04-14-custom-titles.sql`

- [ ] **Step 1: Check ork_award's full schema to understand defaults**

Run:
```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW CREATE TABLE ork_award\G" | sed -n '1,80p'
```

Expected: columns `award_id`, `name`, `is_title`, `peerage`, `officer_role`, `is_ladder`, `title_class`, `peerage_rank` (or similar), plus any `reign_limit`/`month_limit`. Note the exact column list and NOT NULL constraints so the INSERT below can be completed accurately.

- [ ] **Step 2: Write the migration file**

Create `db-migrations/2026-04-14-custom-titles.sql` with:

```sql
-- Custom Titles feature: add alias column and sentinel ork_award row

ALTER TABLE ork_awards
  ADD COLUMN alias_award_id INT(11) NULL DEFAULT NULL AFTER custom_name,
  ADD KEY idx_alias_award_id (alias_award_id);

-- Sentinel row. Adjust column list to match the real ork_award schema from Step 1.
-- Flags: is_title=1 so unaliased Custom Titles still flow into the Titles tab;
-- peerage=None and officer_role=none so they don't polute peerage/officer queries.
INSERT INTO ork_award (name, is_title, peerage, officer_role, is_ladder)
VALUES ('Custom Title', 1, 'None', 'none', 0);

SELECT LAST_INSERT_ID() AS custom_title_award_id;
```

If `ork_award` has additional NOT NULL columns without defaults, add them to the INSERT with sensible neutral values (empty strings, 0, etc.). Cross-reference the existing "Custom Award" row (`award_id=94`) to match its flag pattern.

- [ ] **Step 3: Apply the migration**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-04-14-custom-titles.sql
```

Capture the `custom_title_award_id` returned by the final SELECT — you'll need it in the next step for cache busting and verification.

- [ ] **Step 4: Verify schema + sentinel**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "DESCRIBE ork_awards;" | grep alias_award_id
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT award_id, name, is_title, peerage, officer_role FROM ork_award WHERE name IN ('Custom Award','Custom Title');"
```

Expected:
- `alias_award_id` column present, `int(11)`, NULL allowed
- Both "Custom Award" (94) and "Custom Title" (new id, typically 245+) rows return

- [ ] **Step 5: Flush memcache**

```bash
docker ps --format '{{.Names}}' | grep -i memcache
# then, using whichever matches:
docker exec ork3-php8-memcached sh -c 'echo flush_all | nc -q1 localhost 11211' 2>/dev/null \
  || docker exec ork3-php8-memcache sh -c 'echo flush_all | nc -q1 localhost 11211'
```

- [ ] **Step 6: Commit**

```bash
git add db-migrations/2026-04-14-custom-titles.sql
git commit -m "Enhancement: Add alias_award_id column and Custom Title sentinel row"
```

---

## Task 2: Helper + add_player_award wiring in class.Player.php

**Files:**
- Modify: `system/lib/ork3/class.Player.php`

- [ ] **Step 1: Add GetCustomTitleAwardId() static cache helper**

Find a place near the top of the `Player` class (below existing private helpers or near the start of `public function` definitions) and add:

```php
    private static $_customTitleAwardId = null;
    public static function GetCustomTitleAwardId() {
        if (self::$_customTitleAwardId !== null) return self::$_customTitleAwardId;
        $db = Ork3::$DB;
        $db->Clear();
        $row = $db->DataSet("SELECT award_id FROM " . DB_PREFIX . "award WHERE name = 'Custom Title' AND officer_role='none' LIMIT 1");
        self::$_customTitleAwardId = (isset($row[0]['award_id']) && (int)$row[0]['award_id'] > 0) ? (int)$row[0]['award_id'] : 0;
        return self::$_customTitleAwardId;
    }
```

Use Python for this edit (tabs!):

```bash
python3 -c "
import pathlib
p = pathlib.Path('system/lib/ork3/class.Player.php')
t = p.read_text()
needle = 'class Player {'
helper = '''
\tprivate static \$_customTitleAwardId = null;
\tpublic static function GetCustomTitleAwardId() {
\t\tif (self::\$_customTitleAwardId !== null) return self::\$_customTitleAwardId;
\t\t\$db = Ork3::\$DB;
\t\t\$db->Clear();
\t\t\$row = \$db->DataSet(\"SELECT award_id FROM \" . DB_PREFIX . \"award WHERE name = 'Custom Title' AND officer_role='none' LIMIT 1\");
\t\tself::\$_customTitleAwardId = (isset(\$row[0]['award_id']) && (int)\$row[0]['award_id'] > 0) ? (int)\$row[0]['award_id'] : 0;
\t\treturn self::\$_customTitleAwardId;
\t}
'''
assert needle in t, 'class Player { not found'
p.write_text(t.replace(needle, needle + helper, 1))
print('added helper')
"
```

- [ ] **Step 2: Accept AliasAwardId in add_player_award**

Locate the `add_player_award` function (around line 1330-1390). Add alias persistence right after `$awards->custom_name = $request['CustomName'] ?? '';`:

```php
        $awards->alias_award_id = (!empty($request['AliasAwardId']) && (int)$request['AliasAwardId'] > 0)
                                  ? (int)$request['AliasAwardId'] : null;
```

Use Python:

```bash
python3 -c "
import pathlib
p = pathlib.Path('system/lib/ork3/class.Player.php')
t = p.read_text()
needle = \"\$awards->custom_name = \$request['CustomName'] ?? '';\"
add = \"\n\t\t\t\$awards->alias_award_id = (!empty(\$request['AliasAwardId']) && (int)\$request['AliasAwardId'] > 0) ? (int)\$request['AliasAwardId'] : null;\"
assert needle in t
p.write_text(t.replace(needle, needle + add, 1))
print('add_player_award patched')
"
```

- [ ] **Step 3: Validate alias target**

Just before `$awards->save();` in `add_player_award`, insert validation that rejects an alias pointing to the Custom Title sentinel itself or to an officer-role award:

```php
        if (!empty($awards->alias_award_id)) {
            $db = Ork3::$DB;
            $db->Clear();
            $chk = $db->DataSet("SELECT award_id, is_title, peerage, officer_role FROM " . DB_PREFIX . "award WHERE award_id = " . (int)$awards->alias_award_id . " LIMIT 1");
            $ctid = self::GetCustomTitleAwardId();
            if (empty($chk) || (int)$chk[0]['award_id'] === $ctid || $chk[0]['officer_role'] !== 'none'
                || ((int)$chk[0]['is_title'] !== 1 && in_array($chk[0]['peerage'], ['', 'None'], true))) {
                return InvalidParameter();
            }
        }
```

Use Python to insert before the first `$awards->save();` inside `add_player_award`. Verify with `grep` that you only patched the add function, not any of the other save paths.

- [ ] **Step 4: Verify compile**

```bash
docker exec ork3-php8-web php -l /var/www/html/system/lib/ork3/class.Player.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Player.php
git commit -m "Enhancement: Custom Title helper + add_player_award alias support"
```

---

## Task 3: update_player_award accepts AliasAwardId and supports Custom↔Title reclassification

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (~lines 1500-1600)
- Modify: `system/lib/ork3/class.Player.php` `get_award()` (return `AliasAwardId`)

- [ ] **Step 1: Read the current update_player_award + get_award**

Read lines ~1490–1600 of `class.Player.php` to see the `update_player_award` body and the `get_award` body.

- [ ] **Step 2: Add AliasAwardId handling to update_player_award**

In `update_player_award`, after the custom_name handling, add:

```php
        $new_alias_id = null;
        if (array_key_exists('AliasAwardId', $request)) {
            $new_alias_id = (!empty($request['AliasAwardId']) && (int)$request['AliasAwardId'] > 0) ? (int)$request['AliasAwardId'] : null;
            $awards->alias_award_id = $new_alias_id;
        }
```

If the function also supports AwardId override (reclassification between Custom Award and Custom Title sentinels), make sure:
- When `$request['AwardId']` is provided and points to either 94 or the Custom Title sentinel, it's written to `$awards->award_id`.
- Clearing to Custom Award: caller sends `AwardId=94, AliasAwardId=0` → alias column becomes NULL.
- Setting to Custom Title: caller sends `AwardId=<custom_title_id>, AliasAwardId=<page_id or 0>`.

Repeat the alias validation block from Task 2 Step 3 (non-officer, non-self, exists) so update is just as strict as add.

- [ ] **Step 3: Add AliasAwardId to get_award return payload**

Find `get_award` (~line 1578) and add to the returned array:

```php
            'AliasAwardId' => (int)($awards->alias_award_id ?? 0),
```

Next to the existing `'CustomName' => $awards->custom_name,` entry. Use Python for the edit.

- [ ] **Step 4: Lint check**

```bash
docker exec ork3-php8-web php -l /var/www/html/system/lib/ork3/class.Player.php
```

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Player.php
git commit -m "Enhancement: update_player_award accepts AliasAwardId; get_award returns it"
```

---

## Task 4: Effective-award cap/limit enforcement

**Files:**
- Modify: `system/lib/ork3/class.Player.php`

- [ ] **Step 1: Find existing cap checks**

```bash
grep -n 'reign_limit\|month_limit\|ExceededCap\|CheckCap' system/lib/ork3/class.Player.php
```

If cap-checking logic lives in `add_player_award` (or a helper it calls), identify the point where it compares `$request['AwardId']` against `ork_award.peerage` / `ork_kingdomaward.reign_limit`.

- [ ] **Step 2: Resolve effective_award_id for cap checks**

At the top of `add_player_award` (after basic param validation, before caps are checked), compute:

```php
        $effective_award_id = (!empty($request['AliasAwardId']) && (int)$request['AliasAwardId'] > 0)
                              ? (int)$request['AliasAwardId']
                              : (int)$request['AwardId'];
```

Pass `$effective_award_id` (not raw `AwardId`) to any downstream function that counts against peerage/reign limits. Grep every internal consumer and update.

If no cap-check logic exists (some forks don't enforce limits server-side), skip this task — just note in the commit message.

- [ ] **Step 3: Lint**

```bash
docker exec ork3-php8-web php -l /var/www/html/system/lib/ork3/class.Player.php
```

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Player.php
git commit -m "Enhancement: Custom Title alias target honors peerage cap checks"
```

---

## Task 5: BeltlineData() alias join

**Files:**
- Modify: `system/lib/ork3/class.Report.php` (~lines 2486-2584)

- [ ] **Step 1: Read the current query**

Read lines 2480-2600 of `system/lib/ork3/class.Report.php` so you have the full current SELECT to patch.

- [ ] **Step 2: Add the alias LEFT JOIN and COALESCE columns**

Modify the BeltlineData query:
1. Add `LEFT JOIN ork_award alias ON alias.award_id = aw.alias_award_id` next to the existing `ork_award a` join.
2. Replace `a.peerage` with `COALESCE(alias.peerage, a.peerage)` in the SELECT list and in any `WHERE`/`GROUP BY`/`HAVING` clauses.
3. Replace `a.is_title` / `a.officer_role` similarly if referenced.
4. Leave the `title_name` column as-is IF it already uses `COALESCE(NULLIF(aw.custom_name,''), ka.name, a.name)`. If it reads only from `a.name`, update to the custom-first form so Custom Titles show their custom name.

Use Python for the multi-line edit.

- [ ] **Step 3: Sanity-check with a sample query**

Give yourself an aliased Custom Title via raw SQL against a test player for verification:

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "
SELECT aw.awards_id, aw.mundane_id, aw.custom_name, aw.alias_award_id,
       a.name AS base_name, a.peerage AS base_peerage,
       alias.name AS alias_name, alias.peerage AS alias_peerage,
       COALESCE(alias.peerage, a.peerage) AS effective_peerage,
       COALESCE(NULLIF(aw.custom_name,''), a.name) AS display_name
FROM ork_awards aw
JOIN ork_award a ON a.award_id = aw.award_id
LEFT JOIN ork_award alias ON alias.award_id = aw.alias_award_id
WHERE aw.alias_award_id IS NOT NULL
LIMIT 5;
"
```

Expected (once Task 7 gives a Custom Title): rows with `effective_peerage = 'Page'` and `display_name = 'Brother-in-Battle'`. Until then, empty result is fine — this is just to confirm the SQL shape is right.

- [ ] **Step 4: Load Beltline Explorer in browser to smoke-test**

Using the claude-in-chrome tools (or manual), load `/orkui/Reports/beltlineexplorer/<kingdom_id>` and verify nothing regresses — existing data should render identically.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Report.php
git commit -m "Enhancement: BeltlineData resolves effective peerage via alias join"
```

---

## Task 6: Legacy controller.Player.php queries

**Files:**
- Modify: `orkui/controller/controller.Player.php`

- [ ] **Step 1: Patch the Titles tab query (~lines 590-620)**

Read the current Titles query. Add `LEFT JOIN ork_award alias ON alias.award_id = a.alias_award_id` (adjust the `a` alias to match the actual table alias — it's likely `aw` or similar for `ork_awards`). Rewrite the WHERE clause predicates to use `COALESCE(alias.col, base.col)` for `is_title`, `peerage`, `officer_role`.

In the row-building PHP that consumes the result, replace `(int)$row->is_title` with `(int)($row->effective_is_title ?? $row->is_title)` and similar for peerage fields.

- [ ] **Step 2: Patch the MyAssociates query (~lines 559-587)**

Same treatment. Crucially, the peerage filter `WHERE a.peerage IN ('Page','Squire',...)` becomes `WHERE COALESCE(alias.peerage, a.peerage) IN (...)`.

- [ ] **Step 3: updateaward controller case passes AliasAwardId**

Find the `case 'updateaward':` block (~line 146) and add `'AliasAwardId' => $this->request->Player_index->AliasAwardId ?? 0,` to the request array passed into `update_player_award`.

Similarly, if `controller.Player.php` has its own `addaward` case that calls `add_player_award` (it does at line 125), pipe `AliasAwardId` through — but this legacy-profile add flow isn't the one used by Playernew, so it's defensive only.

- [ ] **Step 4: Lint**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Player.php
```

- [ ] **Step 5: Smoke-test legacy profile**

Load `/orkui/Player/index/<id>` for a player with titles. Confirm the Titles section still renders without errors.

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.Player.php
git commit -m "Enhancement: legacy Player profile queries resolve Custom Title aliases"
```

---

## Task 7: controller.Award.php pass-through

**Files:**
- Modify: `orkui/controller/controller.Award.php`

- [ ] **Step 1: Read around line 100-120**

Read the `addawards` method (line ~103 per grep) and verify how `Award_addawards` form fields flow into `add_player_award`.

- [ ] **Step 2: Add AliasAwardId to the array**

Add:

```php
            'AliasAwardId' => $this->request->Award_addawards->AliasAwardId ?? 0,
```

Right after the existing `'CustomName' => $this->request->Award_addawards->AwardName,` line. Use the Edit tool (single line, low ambiguity) or Python.

- [ ] **Step 3: Find and patch the update path**

```bash
grep -n 'update_player_award\|updateawards\|updateaward' orkui/controller/controller.Award.php
```

If there's an edit/update method in this controller, add `AliasAwardId` there too. If not, the updateaward path is in `controller.Player.php` (handled in Task 6).

- [ ] **Step 4: Lint**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Award.php
```

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.Award.php
git commit -m "Enhancement: Award controller passes AliasAwardId through"
```

---

## Task 8: controller.Playernew.php — alias options + alias-aware Titles/Awards fetches

**Files:**
- Modify: `orkui/controller/controller.Playernew.php`

- [ ] **Step 1: Build $CustomTitleAliasOptions**

In the main `index()` method (or wherever profile data is assembled), after permission/auth setup but before `$this->data = ...` render, add:

```php
        // Custom Title alias dropdown — peerage ladder first, then other non-officer titles
        $db = Ork3::$DB;
        $db->Clear();
        $aliasRows = $db->DataSet("
            SELECT award_id, name, peerage, is_title
            FROM " . DB_PREFIX . "award
            WHERE officer_role = 'none'
              AND name <> 'Custom Title'
              AND name <> 'Custom Award'
              AND (peerage IN ('Page','Lords-Page','Squire','Man-At-Arms','Master','Knight') OR is_ladder = 1 IS NULL OR is_title = 1)
            ORDER BY
              FIELD(peerage,'Page','Lords-Page','Squire','Man-At-Arms','Master','Knight') ASC,
              is_title DESC,
              name ASC
        ");
        $peerageLadder = []; $otherTitles = [];
        foreach ((array)$aliasRows as $r) {
            if (in_array($r['peerage'], ['Page','Lords-Page','Squire','Man-At-Arms','Master','Knight'], true)) {
                $peerageLadder[] = $r;
            } elseif ((int)$r['is_title'] === 1) {
                $otherTitles[] = $r;
            }
        }
        $this->data['CustomTitleAliasOptions'] = [
            'Peerage' => $peerageLadder,
            'Titles'  => $otherTitles,
        ];
        $this->data['CustomTitleAwardId'] = \Player::GetCustomTitleAwardId();
        $this->data['CustomAwardId'] = 94;
```

- [ ] **Step 2: Update Awards fetch to include alias fields**

Locate the query that populates the awards list for the Playernew profile (likely calling `$this->Reports->player_report` or a direct SQL in the controller). If it's SQL, add the alias JOIN + COALESCE columns (`EffectivePeerage`, `EffectiveIsTitle`, `EffectiveOfficerRole`, `AliasAwardId`, `AliasName`). If it's a model call that returns a fixed shape, update the underlying model to return the extra fields.

If the Awards list is assembled by `class.Report.php::get_player_details` or similar, patch that function in the same style as Task 5.

- [ ] **Step 3: Titles tab filter**

Verify the Titles tab filtering in Playernew. If it uses `is_title = 1 OR peerage != 'None'`, rewrite to `EffectiveIsTitle = 1 OR EffectivePeerage != 'None'`.

- [ ] **Step 4: Lint**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Playernew.php
```

- [ ] **Step 5: Smoke-test**

Reload `/orkui/Playernew/index/<id>` for a known player. Confirm Awards and Titles tabs still render unchanged (no Custom Titles exist yet). Check browser console for JS errors and PHP notices.

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.Playernew.php
git commit -m "Enhancement: Playernew builds Custom Title alias options; queries resolve aliases"
```

---

## Task 9: Playernew add-award modal — "Custom Title" option + alias dropdown

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (~lines 2330-2430)

- [ ] **Step 1: Read the modal block**

Read lines 2330-2430 to see the current `<select>` for Award, the Custom Award name input, and surrounding JS.

- [ ] **Step 2: Add "Custom Title" <option> to the award select**

Next to where the existing "Custom Award" `<option>` is rendered (grep for `Custom Award` in the file), add a "Custom Title" option with `data-custom-title="1"` and `value="<?= (int)$CustomTitleAwardId ?>"`. The Custom Award option should also gain `data-custom-award="1"` for JS targeting.

Use Python for the edit to preserve tabs.

- [ ] **Step 3: Add the alias dropdown markup**

Just after the existing Custom Award Name field wrapper, add:

```php
<div class="pn-award-field" id="pn-award-alias-wrap" style="display:none">
    <label for="pn-award-alias">Alias of <span class="pn-form-hint">(optional)</span></label>
    <select id="pn-award-alias" name="Award_addawards[AliasAwardId]">
        <option value="0">— None —</option>
        <?php if (!empty($CustomTitleAliasOptions['Peerage'])): ?>
        <optgroup label="Peerage Ladder">
            <?php foreach ($CustomTitleAliasOptions['Peerage'] as $opt): ?>
                <option value="<?= (int)$opt['award_id'] ?>"><?= htmlspecialchars($opt['name']) ?> (<?= htmlspecialchars($opt['peerage']) ?>)</option>
            <?php endforeach ?>
        </optgroup>
        <?php endif ?>
        <?php if (!empty($CustomTitleAliasOptions['Titles'])): ?>
        <optgroup label="Other Titles">
            <?php foreach ($CustomTitleAliasOptions['Titles'] as $opt): ?>
                <option value="<?= (int)$opt['award_id'] ?>"><?= htmlspecialchars($opt['name']) ?></option>
            <?php endforeach ?>
        </optgroup>
        <?php endif ?>
    </select>
    <div class="pn-form-hint">Aliasing makes this title count as the selected core award for belt relationships and reports.</div>
</div>
```

- [ ] **Step 4: Lint the template**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/template/revised-frontend/Playernew_index.tpl
```

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Playernew add-award modal gains Custom Title option + alias dropdown"
```

---

## Task 10: Modal JS — show/hide alias field based on selection

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (in the modal JS block, likely a few hundred lines below the modal HTML)

- [ ] **Step 1: Find the existing Custom-Award show/hide logic**

```bash
grep -n 'pn-award-custom-name\|Custom Award\|custom-award' orkui/template/revised-frontend/Playernew_index.tpl
```

Locate the function / change-handler that toggles `#pn-award-custom-name` visibility when the award select changes.

- [ ] **Step 2: Extend it to also toggle the alias wrapper**

Modify the change handler to:

```javascript
function pnAwardSelectChanged() {
    var sel = document.getElementById('pn-award-select'); // use actual id
    var opt = sel.options[sel.selectedIndex];
    var isCustomAward = opt && opt.dataset.customAward === '1';
    var isCustomTitle = opt && opt.dataset.customTitle === '1';
    var needsCustomName = isCustomAward || isCustomTitle;
    document.getElementById('pn-award-custom-name-wrap').style.display = needsCustomName ? '' : 'none';
    document.getElementById('pn-award-alias-wrap').style.display = isCustomTitle ? '' : 'none';
    // Relabel the custom-name label
    var label = document.querySelector('label[for="pn-award-custom-name"]');
    if (label) label.textContent = isCustomTitle ? 'Custom Title Name' : 'Custom Award Name';
}
```

Attach the handler on select `change` and call it once on modal open to initialize state.

- [ ] **Step 3: Verify the POST includes AliasAwardId**

If the modal submits via a `<form>` with `name="Award_addawards"`, the `<select name="Award_addawards[AliasAwardId]">` element is auto-included — no JS change needed. If it submits via AJAX using a manual payload, add `AliasAwardId` explicitly to the payload:

```javascript
formData.append('Award_addawards[AliasAwardId]', document.getElementById('pn-award-alias').value || 0);
```

- [ ] **Step 4: Reload Playernew in browser, open the give-award modal**

Verify:
- Selecting "Custom Award" → name field visible, alias hidden.
- Selecting "Custom Title" → both name and alias fields visible.
- Selecting a regular award → both hidden.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Modal JS toggles alias dropdown for Custom Title selection"
```

---

## Task 11: Awards table + Titles tab render [Custom Title] chip with aka subtitle

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (awards-table rendering + titles-tab rendering)

- [ ] **Step 1: Find where [Custom Award] chip is rendered**

```bash
grep -n 'Custom Award\|\[Custom' orkui/template/revised-frontend/Playernew_index.tpl
```

Identify the PHP/HTML block that emits the `[Custom Award]` chip in the Awards table row.

- [ ] **Step 2: Extend chip logic**

Replace the chip emission with a small helper block that handles both sentinels and the aka subtitle:

```php
<?php
$_isCustomAward = ((int)$award['AwardId'] === (int)$CustomAwardId);
$_isCustomTitle = ((int)$award['AwardId'] === (int)$CustomTitleAwardId);
$_aliasName = '';
if ($_isCustomTitle && !empty($award['AliasAwardId'])) {
    $_aliasName = $award['AliasName'] ?? '';
}
?>
<?php if ($_isCustomAward): ?><span class="pn-award-chip pn-chip-custom">[Custom Award]</span><?php endif ?>
<?php if ($_isCustomTitle): ?><span class="pn-award-chip pn-chip-custom-title">[Custom Title]</span><?php endif ?>
<?php if ($_aliasName): ?><div class="pn-award-alias-sub">aka <?= htmlspecialchars($_aliasName) ?></div><?php endif ?>
```

- [ ] **Step 3: Add CSS for the new chip + subtitle**

In the `<style>` block at the top of the template, near `.pn-chip-custom`, add:

```css
.pn-chip-custom-title{background:#e6fffa;color:#2c7a7b;border:1px solid #b2f5ea;font-size:10px;padding:1px 6px;border-radius:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-left:6px}
.pn-award-alias-sub{font-size:11px;color:#a0aec0;margin-top:2px;font-style:italic}
```

- [ ] **Step 4: Apply the same chip to the Titles tab rows**

Find where the Titles tab rows are rendered in the same template and add the chip + subtitle there too.

- [ ] **Step 5: Ensure Awards query returns AliasName**

If the Awards data array doesn't already include `AliasName`, add a COALESCE join in `controller.Playernew.php` Step 2 of Task 8 (if not already done) so every row carries `AliasAwardId` and `AliasName`.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl orkui/controller/controller.Playernew.php
git commit -m "Enhancement: Render [Custom Title] chip + aka subtitle in Awards table and Titles tab"
```

---

## Task 12: Edit-award modal — Custom Award ↔ Custom Title reconciliation

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (edit-award modal + JS)

- [ ] **Step 1: Locate the edit-award modal**

```bash
grep -n 'edit.*award\|update.*award\|pn-edit-award' orkui/template/revised-frontend/Playernew_index.tpl
```

Find the modal HTML and the JS that populates it when the edit button is clicked.

- [ ] **Step 2: Add the radio toggle + alias dropdown to the edit modal**

Inside the edit-modal HTML, after the existing custom-name field (or wherever the award_id is hidden/shown), add:

```html
<div class="pn-award-field" id="pn-edit-type-wrap" style="display:none">
    <label>Type</label>
    <label class="pn-radio-inline"><input type="radio" name="Player_index[EditAwardType]" value="custom_award" id="pn-edit-type-award"> Custom Award</label>
    <label class="pn-radio-inline"><input type="radio" name="Player_index[EditAwardType]" value="custom_title" id="pn-edit-type-title"> Custom Title</label>
</div>
<div class="pn-award-field" id="pn-edit-alias-wrap" style="display:none">
    <label for="pn-edit-alias">Alias of <span class="pn-form-hint">(optional)</span></label>
    <select id="pn-edit-alias" name="Player_index[AliasAwardId]">
        <option value="0">— None —</option>
        <!-- Same optgroup structure as Task 9 -->
    </select>
</div>
```

Reuse the `$CustomTitleAliasOptions` PHP block to build the optgroups (factor into a small template include or inline-duplicate).

- [ ] **Step 3: Populate the modal on edit-click**

Find the JS function that opens the edit modal (grep for `openEditAward` or similar). Extend it:

```javascript
function pnOpenEditAwardModal(award) {
    // ... existing population ...
    var isCA = award.AwardId == PnConfig.customAwardId;
    var isCT = award.AwardId == PnConfig.customTitleAwardId;
    document.getElementById('pn-edit-type-wrap').style.display = (isCA || isCT) ? '' : 'none';
    document.getElementById('pn-edit-alias-wrap').style.display = isCT ? '' : 'none';
    document.getElementById('pn-edit-type-award').checked = isCA;
    document.getElementById('pn-edit-type-title').checked = isCT;
    document.getElementById('pn-edit-alias').value = award.AliasAwardId || 0;
}
document.querySelectorAll('input[name="Player_index[EditAwardType]"]').forEach(function(r){
    r.addEventListener('change', function(){
        var isCT = document.getElementById('pn-edit-type-title').checked;
        document.getElementById('pn-edit-alias-wrap').style.display = isCT ? '' : 'none';
    });
});
```

- [ ] **Step 4: Expose customTitleAwardId and customAwardId in PnConfig**

In the `PnConfig = { ... }` block near the bottom of the template, add:

```php
    customAwardId:      <?= (int)$CustomAwardId ?>,
    customTitleAwardId: <?= (int)$CustomTitleAwardId ?>,
```

- [ ] **Step 5: Submit AwardId override based on radio**

On edit submit, set the hidden `Player_index[AwardId]` field to `customAwardId` or `customTitleAwardId` based on the radio. If `custom_title` and alias empty, that's allowed (unaliased Custom Title). If `custom_award`, force alias to 0.

- [ ] **Step 6: Server-side accepts the reclass**

Task 3 already extended `update_player_award` to accept `AwardId` and `AliasAwardId`; verify by running:

```bash
grep -n "\$request\['AwardId'\]" system/lib/ork3/class.Player.php | head
```

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Edit-award modal reconciles Custom Awards into Custom Titles"
```

---

## Task 13: End-to-end verification

- [ ] **Step 1: Give a fresh Custom Title aliased to Page**

Via the Playernew UI, log in as an admin/monarch, open a test player's profile, click "Give Award", select "Custom Title", enter name "Brother-in-Battle", pick "Page" as alias, submit.

- [ ] **Step 2: Verify DB state**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "
SELECT awards_id, mundane_id, award_id, custom_name, alias_award_id
FROM ork_awards
WHERE custom_name = 'Brother-in-Battle'
ORDER BY awards_id DESC LIMIT 1;
"
```

Expected: `award_id` = Custom Title sentinel, `alias_award_id` = Page's award_id.

- [ ] **Step 3: Verify profile rendering**

Reload the recipient's Playernew profile. Confirm:
- Awards table shows "Brother-in-Battle [Custom Title]" with `aka Page` subtitle.
- Titles tab shows it grouped under Pages.
- No PHP warnings in browser console.

- [ ] **Step 4: Verify Beltline Explorer**

Load `/orkui/Reports/beltlineexplorer/<kingdom_id>` for the recipient's kingdom. Confirm the new relationship appears (giver → recipient, title_name = "Brother-in-Battle", peerage = Page). If the display is aggregated, search for the recipient.

- [ ] **Step 5: Verify My Associates (giver's view)**

Log in as the giver, load their own Playernew profile. Confirm the recipient appears in My Associates under Pages with title "Brother-in-Battle".

- [ ] **Step 6: Verify unaliased Custom Title**

Give another Custom Title "Lorekeeper" with no alias. Confirm:
- Awards table shows `[Custom Title]` chip, no subtitle.
- Titles tab shows it in the Other Titles section.
- Does NOT appear in Beltline Explorer or My Associates.

- [ ] **Step 7: Verify reconcile flow**

On an existing Custom Award row, click edit. Switch the radio to "Custom Title", pick "Page" as alias, save. Confirm:
- Row now renders as `[Custom Title] aka Page`.
- DB row has `award_id = Custom Title sentinel, alias_award_id = Page id`.
- Appears in Beltline / My Associates after page refresh.

- [ ] **Step 8: Verify legacy Custom Award regression-free**

Give a plain Custom Award (no conversion). Confirm it still renders as `[Custom Award]` with no alias subtitle and does NOT appear in Titles tab.

- [ ] **Step 9: Memcache flush + final smoke**

```bash
docker exec ork3-php8-memcached sh -c 'echo flush_all | nc -q1 localhost 11211' 2>/dev/null || docker exec ork3-php8-memcache sh -c 'echo flush_all | nc -q1 localhost 11211'
```

Reload one of each: Playernew profile, Beltline Explorer, legacy Player profile. No errors.

- [ ] **Step 10: Commit any final fixes + push**

```bash
git status
# stage explicit paths only, NEVER git add -A (Authorization.php hack)
git log --oneline upstream/master..HEAD
git push origin feature/player-profile-enhancements
```

---

## Notes for Implementer

- **Do not** commit `system/lib/ork3/class.Authorization.php` (contains local login bypass). Stage files explicitly.
- If an Edit tool call on a PHP multi-line block fails, switch immediately to Python. Don't retry the Edit tool.
- If the Beltline query becomes slow after the alias join, check that `idx_alias_award_id` was created in Task 1.
- The Custom Title sentinel id is environment-dependent — never hardcode. Always resolve via `Player::GetCustomTitleAwardId()` or the `$CustomTitleAwardId` template variable.
- Memcache is aggressive in this codebase. Flush it whenever a query shape changes.
