# Player Since Override Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-editable, nullable "Player Since" override on the player profile that falls back to the computed `MIN(attendance.date)` when unset.

**Architecture:** New nullable `ork_mundane.player_since_override` column. The override-vs-computed coalesce is a **business rule living only in the lib** (`class.Player.php::get_player_since_date`). Controllers orchestrate and render; they never read the column or make the coalesce decision. Mirrors the existing `park_member_since` override pattern.

**Tech Stack:** PHP 8 (yapo ORM), MariaDB, plain-PHP `.tpl` templates, vanilla JS (`revised.js`). No PHP unit-test harness exists — verification is **lint + DB inspection + curl-authed round-trip + browser render** (per project convention).

**Spec:** `docs/superpowers/specs/2026-06-17-player-since-override-design.md`

---

## Layer guardrail (read before every task)

- **DB access only in `system/lib/ork3/class.Player.php`.** No SQL, no column reads, no coalesce logic in any controller or model.
- The controller calls **one** lib method (`get_player_since_date`) and renders the result. It must NOT replicate the `if (override) … else (computed)` decision. (The adjacent `park_member_since` controller-side fallback at `controller.Player.php:217-228` is a known leak — do **not** copy its shape for this field.)
- `model.Player.php` stays a thin pass-through; the new field rides the existing `$request` array.

## PHP edit workflow (normalize-first)

All three PHP files are tab-indented (dirty), which breaks the Edit tool's whitespace matching. **Before editing any PHP file in this plan:**

```bash
php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php <file>
```

It will be reformatted by the pre-commit hook anyway. After normalizing, use the Edit tool. Fallback if the fixer is unavailable: the Python `read_text()/replace()/write_text()` pattern.

## File structure

| File | Change | Responsibility |
|---|---|---|
| `db-migrations/2026-06-17-add-player-since-override.sql` | Create | Add nullable column |
| `system/lib/ork3/class.Player.php` | Modify | `get_player_since_date` (new), surface `PlayerSinceOverride` in player-details array, persist in `UpdatePlayer` |
| `orkui/controller/controller.Player.php` | Modify | Wire `PlayerSinceDate` (resolved) + `PlayerSinceComputed` (hint) at two call sites |
| `orkui/controller/controller.Admin.php` | Modify | Pass `PlayerSinceOverride` into `update_player` |
| `orkui/template/revised-frontend/Playernew_index.tpl` | Modify | Admin modal input + hint line |

---

## Task 1: Database migration

**Files:**
- Create: `db-migrations/2026-06-17-add-player-since-override.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Add Player Since override to ork_mundane — 2026-06-17
--
-- player_since_override is an admin-set authoritative "Player Since" date.
-- NULL means "use the computed value" (MIN(attendance.date) across all parks,
-- via class.Player::get_earliest_attendance_date). A date overrides it.
-- Mirrors the existing park_member_since override.
--
-- NOTE: this column MUST exist before UpdatePlayer writes it. Under PDO
-- ERRMODE_WARNING an unknown column silently rolls back the ENTIRE mundane
-- UPDATE (taking park_member_since, active, etc. with it). Run this first.

ALTER TABLE `ork_mundane`
    ADD COLUMN `player_since_override` DATE NULL DEFAULT NULL AFTER `park_member_since`;
```

- [ ] **Step 2: Apply the migration locally**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-17-add-player-since-override.sql
```
Expected: no error (empty output).

- [ ] **Step 3: Verify the column exists**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_mundane LIKE 'player_since_override';"
```
Expected: one row — `player_since_override | date | YES | | NULL |`.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-06-17-add-player-since-override.sql
git commit -m "Enhancement: add ork_mundane.player_since_override column"
```

---

## Task 2: Lib — read/resolve method + surface raw override

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (add method after `get_earliest_attendance_date`, ~line 2901; add array key near `:336`)

- [ ] **Step 1: Normalize the file**

Run: `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php system/lib/ork3/class.Player.php`

- [ ] **Step 2: Add the `get_player_since_date` resolver**

Insert immediately after the closing brace of `get_earliest_attendance_date` (currently ends at line ~2901), before `get_earliest_park_attendance_date`:

```php
// Resolve the "Player Since" date: the admin-set override if present,
// otherwise the computed earliest attendance across all parks.
// The override-vs-computed coalesce lives HERE (lib), never in a controller.
// Returns 'Y-m-d' or null.
public function get_player_since_date($mundane_id) {
    $mundane_id = (int)$mundane_id;
    if (!$mundane_id) {
        return null;
    }
    $sql = "select player_since_override from " . DB_PREFIX . "mundane
            where mundane_id = " . $mundane_id . " limit 1";
    $r = $this->db->query($sql);
    if ($r !== false && $r->size() > 0) {
        $r->next();
        $override = $r->player_since_override;
        if (!empty($override) && $override !== '0000-00-00') {
            return date('Y-m-d', strtotime($override));
        }
    }
    return $this->get_earliest_attendance_date($mundane_id);
}
```

- [ ] **Step 3: Surface the raw override in the player-details array**

In the player-details array literal, find (line ~336):

```php
					'ParkMemberSince' => $this->mundane->park_member_since,
```

Add immediately after it:

```php
					'PlayerSinceOverride' => $this->mundane->player_since_override,
```

- [ ] **Step 4: Lint**

Run: `php -l system/lib/ork3/class.Player.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verify the resolver via a curl-authed profile fetch**

The profile controller doesn't call this yet (Task 4), so verify the method directly with a synthetic check: pick a player id with attendance and confirm the computed branch still returns a date, and (after setting an override in SQL) the override branch wins.

Run (computed branch — replace 12345 with a real mundane_id that has attendance):
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT MIN(date) FROM ork_attendance WHERE mundane_id=12345 AND date>='1988-01-01';"
```
Expected: a date — this is what `get_player_since_date` must return when the override is NULL. (Full end-to-end is exercised in Task 6.)

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Player.php
git commit -m "Enhancement: lib get_player_since_date resolver + surface override"
```

---

## Task 3: Lib — persist the override in UpdatePlayer

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (the `AUTH_PARK`/`AUTH_EDIT` block, ~line 1353-1356)

- [ ] **Step 1: Add the persist line**

Find (line ~1353-1356):

```php
				if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
					$pms = $request['ParkMemberSince'];
					$this->mundane->park_member_since = is_null($pms) ? $this->mundane->park_member_since : (($pms === '' || $pms === '0000-00-00') ? null : $pms);
				}
```

Replace with (add the override handling inside the same auth block):

```php
				if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
					$pms = $request['ParkMemberSince'];
					$this->mundane->park_member_since = is_null($pms) ? $this->mundane->park_member_since : (($pms === '' || $pms === '0000-00-00') ? null : $pms);
					$pso = $request['PlayerSinceOverride'] ?? null;
					$this->mundane->player_since_override = is_null($pso) ? $this->mundane->player_since_override : (($pso === '' || $pso === '0000-00-00') ? null : $pso);
				}
```

- [ ] **Step 2: Lint**

Run: `php -l system/lib/ork3/class.Player.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Player.php
git commit -m "Enhancement: persist player_since_override in UpdatePlayer"
```

---

## Task 4: Controllers — wire resolved value + hint + request pass-through

**Files:**
- Modify: `orkui/controller/controller.Player.php` (lines ~216 and ~372)
- Modify: `orkui/controller/controller.Admin.php` (line ~1203)

- [ ] **Step 1: Normalize both files**

Run:
```bash
php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php orkui/controller/controller.Player.php
php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php orkui/controller/controller.Admin.php
```

- [ ] **Step 2: Wire the primary profile call site (`controller.Player.php` ~line 216)**

Find:
```php
        $this->data['Player']['PlayerSinceDate']  = $this->Player->get_earliest_attendance_date($id);
```

Replace with:
```php
        $this->data['Player']['PlayerSinceDate']     = $this->Player->get_player_since_date($id);
        $this->data['Player']['PlayerSinceComputed'] = $this->Player->get_earliest_attendance_date($id);
```

- [ ] **Step 3: Wire the second call site (`controller.Player.php` ~line 372)**

Find:
```php
        $this->data['Player']['PlayerSinceDate'] = $this->Player->get_earliest_attendance_date($id);
```

Replace with:
```php
        $this->data['Player']['PlayerSinceDate']     = $this->Player->get_player_since_date($id);
        $this->data['Player']['PlayerSinceComputed'] = $this->Player->get_earliest_attendance_date($id);
```

> Note: `$Player['PlayerSinceOverride']` is already populated by the lib player-details fetch (Task 2 Step 3); the controller does not set it and must not query for it.

- [ ] **Step 4: Pass the field into the save payload (`controller.Admin.php` ~line 1203)**

Find:
```php
									'ParkMemberSince' => $this->request->Admin_player->ParkMemberSince,
```

Add immediately after:
```php
									'PlayerSinceOverride' => $this->request->Admin_player->PlayerSinceOverride,
```

- [ ] **Step 5: Lint both files**

Run:
```bash
php -l orkui/controller/controller.Player.php
php -l orkui/controller/controller.Admin.php
```
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add orkui/controller/controller.Player.php orkui/controller/controller.Admin.php
git commit -m "Enhancement: wire Player Since override through controllers"
```

---

## Task 5: Template — admin modal input + hint

**Files:**
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (after the Park Member Since field, ~line 2535)

> `.tpl` files are PLAIN PHP (`<?php ?>`/`<?= ?>`), not Smarty. `.pn-acct-hint` already exists in `revised.css` and already has a dark-mode rule — no CSS change. The modal save JS already collects every named input and converts empty `type="date"` → `0000-00-00` — no JS change.

- [ ] **Step 1: Add the input + hint**

Find (line ~2534-2535, inside the `<?php if ($canEditAdmin): ?>` Administrative section):
```php
				<label for="pn-acct-member-since">Park Member Since</label>
				<input type="date" id="pn-acct-member-since" name="ParkMemberSince" value="<?= htmlspecialchars(($Player['ParkMemberSince'] ?? '') === '0000-00-00' ? '' : ($Player['ParkMemberSince'] ?? '')) ?>" />
```

This sits inside a `<div class="pn-acct-field">…</div>`. Immediately **after that closing `</div>`**, add a new field block:

```php
			<div class="pn-acct-field">
				<label for="pn-acct-player-since">Player Since (override)</label>
				<input type="date" id="pn-acct-player-since" name="PlayerSinceOverride" value="<?= htmlspecialchars(($Player['PlayerSinceOverride'] ?? '') === '0000-00-00' ? '' : ($Player['PlayerSinceOverride'] ?? '')) ?>" />
				<div class="pn-acct-hint">Current first sign-in: <?= !empty($Player['PlayerSinceComputed']) ? htmlspecialchars($Player['PlayerSinceComputed']) : 'N/A' ?></div>
			</div>
```

> Match the actual indentation/closing-`</div>` placement of the existing Park Member Since `.pn-acct-field` block when inserting — read the surrounding 6-8 lines first to place it correctly.

- [ ] **Step 2: Lint the template**

Run: `php -l orkui/template/revised-frontend/Playernew_index.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Playernew_index.tpl
git commit -m "Enhancement: Player Since override input in admin modal"
```

---

## Task 6: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Confirm migration is applied** (from Task 1 Step 3). If testing on a fresh DB, re-apply.

- [ ] **Step 2: Curl-authed round-trip — set an override**

Use one cookie jar; login + save in one block (single-device sessions). Replace `PID` with a real mundane_id you have admin authority over, and use a date earlier than its computed first sign-in:

```bash
cd /Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias
J=/tmp/ork_psjar.txt; B=http://localhost:19080
curl -s -c $J -b $J "$B/index.php?Route=Login/login" \
  --data-urlencode "username=YOUR_TEST_USER" --data-urlencode "password=x" -o /dev/null
curl -s -c $J -b $J "$B/index.php?Route=Admin/player/PID/update" \
  -F "Update=Update Details" \
  -F "PlayerSinceOverride=1995-01-01" \
  -F "ParkMemberSince=" -o /dev/null
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "SELECT player_since_override FROM ork_mundane WHERE mundane_id=PID;"
```
Expected: `1995-01-01`. (Fields are posted FLAT — `name="PlayerSinceOverride"` — and the framework namespaces them into `$this->request->Admin_player->…` on read, exactly like the existing `ParkMemberSince`. Other modal fields may be required for the save to pass validation; if the save no-ops, capture the response body instead of `-o /dev/null` and inspect.)

> If the column write appears to silently fail, re-check Task 1 ran — an unknown column rolls back the whole mundane UPDATE under PDO ERRMODE_WARNING.

- [ ] **Step 3: Verify the profile renders the override across all three surfaces**

Open `http://localhost:19080/index.php?Route=Player/profile/PID` and confirm:
- "Player Since" detail row shows `1995-01-01`.
- "Amtgard Tenure" widget computes tenure from `1995-01-01`.
- Timeline "First sign-in at Amtgard" milestone shows `1995-01-01`.

(If `Player/profile` is cached stale, the save already busts the player-details cache; hard-reload.)

- [ ] **Step 4: Clear the override → reverts to computed**

```bash
curl -s -c $J -b $J "$B/index.php?Route=Admin/player/PID/update" \
  -F "Update=Update Details" \
  -F "PlayerSinceOverride=0000-00-00" \
  -F "ParkMemberSince=" -o /dev/null
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "SELECT player_since_override FROM ork_mundane WHERE mundane_id=PID;"
```
Expected: `NULL`. Reload the profile — "Player Since" reverts to the computed `MIN(date)` value.

- [ ] **Step 5: Modal + dark-mode check**

Open the Update Account modal as an admin. Confirm:
- The empty input shows the helper line `Current first sign-in: {computed date}`.
- Toggle dark mode — the input and `.pn-acct-hint` text are legible (muted, not invisible).

- [ ] **Step 6: Final lint sweep**

Run:
```bash
php -l system/lib/ork3/class.Player.php
php -l orkui/controller/controller.Player.php
php -l orkui/controller/controller.Admin.php
php -l orkui/template/revised-frontend/Playernew_index.tpl
```
Expected: `No syntax errors detected` for all.

---

## Done criteria

- Migration applied; column present and nullable.
- Override settable + clearable via the admin modal; round-trips to `ork_mundane.player_since_override`.
- When set, the override drives the Player Since row, Tenure widget, and First-sign-in milestone.
- When cleared/NULL, all surfaces revert to the computed `MIN(attendance.date)`.
- No DB access or coalesce logic in any controller/model — only in `class.Player.php`.
- Helper line shows the computed first-sign-in date; dark mode legible.
