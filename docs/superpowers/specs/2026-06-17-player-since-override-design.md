# Player Since Override — Design

**Date:** 2026-06-17
**Status:** Approved (design), pending implementation plan

## Summary

The player profile shows a **"Player Since"** date. Today it is always *computed* as
`MIN(ork_attendance.date)` across all parks (`class.Player.php::get_earliest_attendance_date`).
This is fine for most players, but it can never predate a player's first *recorded* sign-in, so
players whose early attendance was imported incompletely look more recent than they actually are.

This feature adds an **admin-editable, nullable override**:

- A new nullable column `ork_mundane.player_since_override DATE NULL`.
- `NULL` (or `0000-00-00`) → fall back to the computed `MIN(attendance.date)`.
- A date → use that date as the authoritative "Player Since".

It mirrors the existing `ork_mundane.park_member_since` override pattern exactly.

## Decisions (from brainstorming)

1. **Override scope = all three surfaces.** When set, the override drives the profile
   "Player Since" row, the "Amtgard Tenure"/anniversary widget, **and** the
   "First sign-in at Amtgard" milestone in the timeline. All three already read the single
   `$Player['PlayerSinceDate']` value, so they update automatically.
2. **Empty-field UX = helper line.** When the override input is blank, a small helper line under
   it reads: `Current first sign-in: {computed date}` (or `N/A` if none).
3. **Permission = admin-only**, same gate as Park Member Since (`$canEditAdmin` in the modal;
   `AUTH_PARK`/`AUTH_EDIT` block in `UpdatePlayer`).

## Architecture — layer separation (CRITICAL)

This codebase enforces a 3-layer contract (see
`docs/superpowers/specs/2026-06-15-layer-separation-refactor-inventory.md`):

| Layer | Directory | Rule |
|---|---|---|
| Frontend (MVC) | `orkui/controller`, `orkui/model` | Orchestrate + render. Models are **thin** pass-throughs (`__call` → lib). **No raw SQL, no business rules.** |
| **Library** | `system/lib/ork3/class.*.php` | **The only layer that touches the DB.** All business logic lives here. |

**The single most important guardrail for this feature:** the "use override **or** computed"
coalesce is a **business rule** and reads a DB column, so it lives **entirely in the lib**
(`get_player_since_date`). The controller must **never** read `player_since_override` directly,
and must **never** implement the `if (override) … else (computed)` decision. The controller calls
one lib method and renders the result.

### Layer responsibilities

| Layer | File | Responsibility | Touches DB? |
|---|---|---|---|
| Library | `system/lib/ork3/class.Player.php` | **NEW** `get_player_since_date($mundane_id)` — the coalesce rule. Surface raw `player_since_override` in the player-details read. **MODIFY** `UpdatePlayer` to persist the column. | ✅ only here |
| Model | `orkui/model/model.Player.php` | Thin. `update_player` already forwards to lib + busts caches; the new field rides along in `$request`. Read methods auto-forward via `__call`. | ❌ |
| Controller | `orkui/controller/controller.Player.php` | Orchestrate: assign `PlayerSinceDate` / `PlayerSinceComputed` from lib calls. No DB, no coalesce. | ❌ |
| Controller | `orkui/controller/controller.Admin.php` | Add `PlayerSinceOverride` to the `update_player` request array — pure request pass-through. | ❌ |
| View | `orkui/template/revised-frontend/Playernew_index.tpl` | Render the input + helper line. Display only. | ❌ |

## Detailed design

### 1. Database (migration)

```sql
ALTER TABLE ork_mundane
  ADD COLUMN player_since_override DATE NULL DEFAULT NULL AFTER park_member_since;
```

- Run via: `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`
- Idempotency: guard so re-running is safe (check `information_schema.columns` before `ADD`, or
  document it as a one-shot like the existing `park_member_since` migration). Follow whatever the
  repo's migration convention is for the adjacent `park_member_since` column.

### 2. Library — `system/lib/ork3/class.Player.php`

**a. New read/coalesce method (the business rule):**

```php
/**
 * Resolve the player's "Player Since" date: the admin-set override if present,
 * otherwise the computed earliest attendance across all parks.
 * Returns 'Y-m-d' or null.
 */
public function get_player_since_date($mundane_id) {
    $override = $this->get_player_since_override($mundane_id); // raw column read
    if (!empty($override) && $override !== '0000-00-00') {
        return date('Y-m-d', strtotime($override));
    }
    return $this->get_earliest_attendance_date($mundane_id);
}
```

`get_player_since_override($mundane_id)` reads `player_since_override` from `ork_mundane`
(parameterized; `$DB->Clear()` first per the project rule before any raw Execute/DataSet). If the
existing player-details fetch (`GetPlayer` / `get_player_details` / the `$this->mundane` load) is
`SELECT *` or already maps the full mundane row, the column will surface automatically and a
dedicated reader may be unnecessary — verify and reuse rather than adding a redundant query.

**b. Surface the raw override for pre-filling the input.** Ensure the lib method that builds the
profile `$Player` array (the player-details read) includes `player_since_override` so the
controller/template can pre-fill the input without its own query. Add it to the select/mapping if
the fetch is not already `SELECT *`.

**c. Persist on save** — in `UpdatePlayer`, alongside `park_member_since` and **inside the same
`HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)` block** (~line 1354):

```php
$pso = $request['PlayerSinceOverride'] ?? null;
$this->mundane->player_since_override =
    is_null($pso)
        ? $this->mundane->player_since_override
        : (($pso === '' || $pso === '0000-00-00') ? null : $pso);
```

This matches the `park_member_since` semantics: `''`/`0000-00-00` → `NULL`, a date stored as-is,
absent key leaves the existing value untouched.

### 3. Model — `orkui/model/model.Player.php`

No new logic. `update_player($request)` already forwards to `Player->UpdatePlayer($request)` and
busts the player-details + roster caches; the new `PlayerSinceOverride` key simply rides along in
`$request`. The read methods (`get_player_since_date`, `get_earliest_attendance_date`) auto-forward
to the lib via `__call`, consistent with how `get_earliest_attendance_date` is already called today.

### 4. Controller — `orkui/controller/controller.Player.php`

At both existing call sites (~lines 216 and 372) where `PlayerSinceDate` is currently set:

```php
$this->data['Player']['PlayerSinceDate']     = $this->Player->get_player_since_date($id);
$this->data['Player']['PlayerSinceComputed'] = $this->Player->get_earliest_attendance_date($id);
```

- `PlayerSinceDate` is the resolved value → drives the row, tenure widget, and milestone.
- `PlayerSinceComputed` is the raw computed value → only for the modal helper line.
- `PlayerSinceOverride` is the raw stored column, already present on `$Player` from the lib fetch →
  used to pre-fill the input.
- **No coalesce logic and no DB access in the controller.**

The existing `park_member_since` fallback block (lines ~217-228) is unrelated and stays as-is.

### 5. Controller — `orkui/controller/controller.Admin.php`

In the `Update Details` branch (~line 1203), add to the `update_player(array(...))` payload:

```php
'PlayerSinceOverride' => $this->request->Admin_player->PlayerSinceOverride,
```

Pure request pass-through — no logic.

### 6. View — `orkui/template/revised-frontend/Playernew_index.tpl`

In the `$canEditAdmin` Administrative section, immediately after the "Park Member Since" field
(~line 2533):

```php
<div class="pn-acct-field">
  <label for="pn-acct-player-since">Player Since (override)</label>
  <input type="date" id="pn-acct-player-since" name="PlayerSinceOverride"
         value="<?= htmlspecialchars(($Player['PlayerSinceOverride'] ?? '') === '0000-00-00' ? '' : ($Player['PlayerSinceOverride'] ?? '')) ?>" />
  <small class="pn-acct-hint">Current first sign-in: <?= !empty($Player['PlayerSinceComputed']) ? htmlspecialchars($Player['PlayerSinceComputed']) : 'N/A' ?></small>
</div>
```

- **No JS change required.** The existing save handler in `revised.js` collects every named input in
  the modal and converts empty `type="date"` values → `0000-00-00`, which the lib maps to `NULL`.
- `.pn-acct-hint` gets a muted, **dark-mode-compatible** style (use existing muted-text variables;
  do not hardcode a light-only color). Verify in dark mode before declaring done.

## Edge cases

- **No attendance + no override** → `get_player_since_date` returns `null`; surfaces render `N/A`
  (row), no tenure, no milestone — unchanged from today.
- **Override set, then cleared** → input blank → `0000-00-00` → `NULL` → reverts to computed.
- **Override earlier than first attendance** → allowed; this is the whole point (legacy players).
- **Cache** → `update_player` already busts the player-details cache carrying the mundane row, so
  the new column refreshes on save. The computed fallback's own 300s ghettocache is independent and
  unaffected.

## Out of scope

- Reports (`class.Report.php` voting/membership) keep their existing
  `park_member_since` vs `first_attendance` toggle; they do **not** consume this override.
- No change to `get_earliest_attendance_date` itself (still pure computed; the override lives one
  level up in `get_player_since_date`).

## Verification

- Lint changed PHP.
- Migration applies cleanly + is safe to re-run.
- Curl-authed admin save of `PlayerSinceOverride` → confirm `ork_mundane.player_since_override`
  written; clear it → confirm `NULL` and profile reverts to computed.
- Profile renders override across all three surfaces (row, tenure, milestone) when set.
- Modal helper line shows the computed first-sign-in date.
- Dark-mode check on the new field + hint.
