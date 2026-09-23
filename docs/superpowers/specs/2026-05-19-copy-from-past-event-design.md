# Copy From Past Event — Design

**Date:** 2026-05-19
**Author:** averykrouse (with Claude)
**Branch:** `feature/event-planning-expansion`
**Status:** Approved (brainstorming complete)

## Summary

Add a "Copy from past event" affordance to the New Event modal on both the Kingdomnew and Parknew profiles. When the user picks a prior event in the same scope, the modal grows date/time pickers (end pre-filled from the source delta) and a checkbox list of modules to copy: Event Details, Schedule, Staff, Feast, and Banner (Banner off by default). On submit, one backend endpoint creates the new `ork_event`, its first `ork_event_calendardetail`, and all copied side-data in a single shot — then redirects to the new event's detail page.

## Goals

- One-click "do it like we did last year" for organizers — no manual re-entry of staff, schedule, etc.
- Strict scope isolation: kingdom-level past events only appear in the Kingdom modal; park-level past events only appear in the Park modal. No cross-scope leakage even for cross-hosted events.
- Graceful handling of stale references: banned/deactivated staff and leads are silently skipped; their containing schedule items are still copied.
- Atomic from the user's POV: a single POST creates everything; on fatal failure the new event is rolled back.
- Zero new tables; reuse existing schema.

## Non-goals

- Copying RSVPs or Attendance (per user request — those are per-occurrence data the new event will collect fresh).
- Copying Heraldry (per user — visual identity often differs year to year; banner is included only as an off-by-default option).
- Multi-occurrence copy (each event is its own standalone thing per the 3.5 ethos; the new event gets one initial occurrence).
- A "second dropdown of occurrences to copy from" — we always copy from the source event's most recent occurrence by `event_start DESC`.

## Background

### Current "New Event" modal flow

`Kingdomnew_index.tpl` (line 1219) and `Parknew_index.tpl` (line 1700) each host a `kn-emod-overlay` / `pk-emod-overlay` modal with a radio toggle for "Event" vs "Calendar Item". In Event mode the modal collects just Name (+ optional Host Park on Kn) and POSTs `EventAjax::create` (`orkui/controller/controller.EventAjax.php:5`), which creates a stub `ork_event` row and returns its id. The JS (`revised.js:3697 knCreateEvent`, with a mirrored `pkCreateEvent`) then redirects to `Event/create/{id}` (`controller.Event.php:736`) where the user fills the first occurrence's dates/details/fees/links.

### Data model touched

| Table | Role |
|---|---|
| `ork_event` | Parent — `event_id`, `name`, `kingdom_id`, `park_id`, `has_heraldry`, `has_banner`, `banner_show_logo`, `banner_vignette`, `banner_offset_x`, `banner_offset_y`, `status` |
| `ork_event_calendardetail` | Occurrence — `event_id`, `event_start`, `event_end`, `at_park_id`, `price`, `description`, `url`, `url_name`, `address`, `province`, `postal_code`, `city`, `country`, `map_url`, `map_url_name`, `event_type` |
| `ork_event_fees` | `event_calendardetail_id`, `admission_type`, `cost`, `sort_order` |
| `ork_event_links` | `event_calendardetail_id`, `title`, `url`, `icon`, `sort_order` |
| `ork_event_staff` | `event_calendardetail_id`, `mundane_id`, `role_name`, `can_manage`, `can_attendance`, `can_schedule`, `can_feast` |
| `ork_event_schedule` | `event_calendardetail_id`, `title`, `start_time`, `end_time`, `location`, `description`, `category`, `secondary_category`, `menu`, `cost`, `dietary`, `allergens` |
| `ork_event_schedule_lead` | `event_schedule_id`, `mundane_id` |
| `ork_mundane` | `active`, `suspended`, `suspended_until` — used to filter "banned/deactivated" |

Banner image files live on disk at `DIR_EVENT_BANNER . sprintf('%05d', $event_id) . '.{jpg|png}'`.

## Architecture

### Single endpoint, atomic flow

New AJAX action: `Controller_EventAjax::create_with_copy($p)` at the bottom of `orkui/controller/controller.EventAjax.php`.

```
POST /EventAjax/create_with_copy
Body:
  Name             string (required)
  KingdomId        int    (required if no ParkId; mirrors create())
  ParkId           int    (optional / required if no KingdomId)
  SourceEventId    int    (required — what we're copying from)
  NewStart         string ('Y-m-d H:i' or ISO; will be normalized)
  NewEnd           string (same)
  Modules          JSON   { details: bool, schedule: bool, staff: bool, feast: bool, banner: bool }
  Status           string ('published' | 'draft', default 'published')
Returns:
  { status: 0, eventId: <int>, detailId: <int>, url: '/Event/detail/<eventId>/<detailId>' }
```

Pipeline inside the endpoint (every DB write preceded by `$DB->Clear()`):

1. **Auth.** Require logged-in user. Resolve target scope (`kingdom_id` or `park_id`). Use `Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, 0, AUTH_CREATE)` against the scope, mirroring the existing `create()`.
2. **Scope validation on source.** Re-query the source event and confirm:
   - If `KingdomId` was given and `ParkId` is 0 → source must have `kingdom_id = KingdomId AND (park_id IS NULL OR park_id = 0)`.
   - If `ParkId` was given → source must have `park_id = ParkId`.
   - Otherwise reject with `status:3 "Source event is not in scope."`. This is defense-in-depth so the dropdown is not the only enforcer.
3. **Source occurrence.** `SELECT * FROM ork_event_calendardetail WHERE event_id = :src ORDER BY event_start DESC LIMIT 1`. Reject if none.
4. **Delta.** `$delta_seconds = strtotime($NewStart) - strtotime($src.event_start)`. Used to shift schedule rows in step 8.
5. **Create new event row.** Use `$this->Event->create_event(...)` (same path as `create()` today). Capture `$new_event_id`.
6. **Create new occurrence.** Insert into `ork_event_calendardetail` with `event_id = $new_event_id`, `event_start = $NewStart`, `event_end = $NewEnd`, `current = 1`, `at_park_id = $ParkId ?: NULL`. If `Modules.details`, copy `price, description, url, url_name, address, province, postal_code, city, country, map_url, map_url_name, event_type` from source; otherwise leave them empty. Capture `$new_detail_id`.
7. **Details extras** (if `Modules.details`):
   - Copy `ork_event_fees` rows (admission_type, cost, sort_order).
   - Copy `ork_event_links` rows (title, url, icon, sort_order). Re-validate URL scheme (`http`/`https`/`mailto`) and icon allow-list on insert, matching the protection in `controller.Event.php:846-861`, so we don't propagate any pre-validation rot.
8. **Schedule + Feast** (separate checkboxes, same table):
   - One pass over `ork_event_schedule` for the source detail.
   - Determine row's category bucket. Feast bucket = `category = 'Feast and Food' OR secondary_category = 'Feast and Food'`. Non-feast = everything else.
   - Skip rows whose bucket is not selected.
   - For each kept row: insert a new row with `event_calendardetail_id = $new_detail_id`, all text fields copied, `start_time = source.start_time + $delta_seconds`, `end_time = source.end_time + $delta_seconds`. Capture `$new_schedule_id`.
   - Copy `ork_event_schedule_lead` rows, filtering by `_isMundaneEligible($mundane_id)` (see below).
9. **Staff** (if `Modules.staff`):
   - For each `ork_event_staff` row in source: if mundane is eligible, insert with `event_calendardetail_id = $new_detail_id`, same `role_name`, same can_* flags. Otherwise skip silently.
10. **Banner** (if `Modules.banner`):
    - Read `has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y` from source `ork_event`.
    - If `has_banner = 1`: locate the source file at `DIR_EVENT_BANNER . sprintf('%05d', $src_event_id) . '.jpg'` (then `.png` fallback). `copy()` to the new event's path. Update new event's banner columns to match source. If file copy fails, leave `has_banner = 0` on the new event but continue — banner is best-effort, not a hard failure.
11. **Cache bust.** `_bustEventSearchCache($new_event_id)` (private helper at line 1026 — already in the file).
12. **Return.** JSON `{status:0, eventId, detailId, url}`. JS redirects to `url`.

**Rollback.** Wrap steps 5–11 in a try/catch. If anything fatal throws, run a cleanup pass that deletes any inserted rows by id (`event_schedule_lead` → `event_schedule` → `event_staff` → `event_links` → `event_fees` → `event_calendardetail` → `event`) and the banner file. PHP's MySQL layer here doesn't expose transactions cleanly (raw `$DB->Execute`), so explicit cleanup is more reliable than relying on transaction wrapping.

### Helper: mundane eligibility

```php
private function _isMundaneEligible($mundane_id) {
    global $DB;
    $DB->Clear();
    $row = $DB->DataSet(
        'SELECT active, suspended, suspended_until FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int)$mundane_id . ' LIMIT 1'
    );
    if (!$row || !$row->Next()) return false;
    if ((int)$row->active !== 1) return false;
    if ((int)$row->suspended === 1) {
        $until = $row->suspended_until;
        if (!$until || strtotime($until) === false || strtotime($until) >= strtotime(date('Y-m-d'))) {
            return false;
        }
    }
    return true;
}
```

Cache the result per request (in-memory `static $cache = []`) so a person referenced by 12 schedule items isn't queried 12 times.

### Source-list endpoint

New AJAX action: `Controller_EventAjax::copy_source_list($p)`.

```
GET /EventAjax/copy_source_list?KingdomId=123&ParkId=0&Query=summer
Returns:
  { status: 0, results: [
    { eventId, name, lastStart: 'Y-m-d', lastEnd: 'Y-m-d', occurrenceCount }
  ] }
```

Query (Kingdom scope shown; Park swaps the WHERE):

```sql
SELECT e.event_id, e.name,
       MAX(cd.event_start) AS last_start,
       MAX(cd.event_end)   AS last_end,
       COUNT(cd.event_calendardetail_id) AS occ_count
FROM ork_event e
JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
WHERE e.kingdom_id = :kid
  AND (e.park_id IS NULL OR e.park_id = 0)
  AND e.status = 'published'
  AND e.name LIKE :name_like
GROUP BY e.event_id
HAVING last_start IS NOT NULL
ORDER BY last_start DESC
LIMIT 25
```

Park scope: `WHERE e.park_id = :pid AND ...`. We include both past and future occurrences — a host might want to clone next month's planned event into another month before it happens. The label shows the last occurrence date so the user can tell them apart.

`Query` is optional; empty means "give me the 25 most recent". Typeahead JS sends `Query` after the user types ≥1 character.

Auth: same as `create()` — must be logged in, must have `AUTH_EVENT/AUTH_CREATE` on the target scope.

## UI

### Modal layout

In both `Kingdomnew_index.tpl` and `Parknew_index.tpl`, after the existing Name input (and Host Park input on Kn), add a collapsible section visible only when Event mode is selected:

```
┌─ Copy from past event (optional) ───────────── [▾]
│ When expanded:
│   [ search input — kn-ac-results typeahead, scope label "kingdom" or "park" ]
│   ← when a source is chosen, the row collapses to a chip:
│     [ Summer Coronation  ·  Apr 2025  (✕ clear) ]
│   ──────────────────────────────────────────────
│   Start:  [datetime picker — flatpickr]
│   End:    [datetime picker — flatpickr, prefilled from delta]
│   ──────────────────────────────────────────────
│   What to copy:
│     ☑ Select all
│     ☑ Event Details (description, address, fees, links)
│     ☑ Schedule
│     ☑ Staff
│     ☑ Feast
│     ☐ Banner
└──────────────────────────────────────────────────
```

Expander is closed by default. Opening it does not change submit behavior until a source is picked. Selecting a source rewires the Create button: instead of POSTing to `EventAjax::create` and redirecting to `Event/create/...`, it POSTs to `EventAjax::create_with_copy` with everything and redirects to the returned `url` (event detail page).

When a source is chosen, the Name field is pre-filled with `{source.name} {current_year}` (e.g. `Summer Coronation 2026`), only if the user hasn't typed anything yet. If the user types first, we never overwrite.

Clearing the source chip (the ✕ button) hides the date pickers and module checkboxes again, rewires the Create button back to the plain `EventAjax::create` path, and leaves the Name field exactly as the user last left it (we never undo a name fill).

### Typeahead

Per the [feedback_playersearch_pattern.md](feedback_playersearch_pattern) memory, use the project's `kn-ac-results` dropdown pattern — **not** jQuery UI autocomplete. Per [feedback_autocomplete_in_modal.md](feedback_autocomplete_in_modal) the dropdown must use `position: fixed` via `tnFixedAcPosition(inputEl, dropdownEl)` before every `.classList.add('kn-ac-open')`, in both the "no results" and "results" branches.

Debounce 200ms; min query length 1 (because the list is already capped at 25 the result set is small enough). Render rows as:

```
Summer Coronation
Apr 5, 2025 · 4 prior occurrences
```

### Date pickers

flatpickr with `altInput: true` and `altFormat: 'F j, Y  h:i K'` (per [feedback_datetime_display_format.md](feedback_datetime_display_format)). The hidden real input carries `Y-m-d H:i`. When the source is picked, compute the delta from `source.lastStart` to `source.lastEnd` and pre-fill End = NewStart + delta when the user changes NewStart. The user can override End freely.

### Select all

A simple master checkbox above the module list. Wired both ways (master toggles all; if any child unchecks, master goes to indeterminate; if all check, master becomes checked).

### Dark mode

All new CSS must include dark-mode variants per [feedback_dark_mode_compat.md](feedback_dark_mode_compat) and the [feedback_dark_mode_checklist.md](feedback_dark_mode_checklist). Walk the modal in dark mode before declaring done. Specifically watch for: the expander header background (orkui.css h-tag pill leak — explicit reset required), checkbox label color, chip background for the selected source, segmented control if used for Select-all.

### Tooltips

If we add hover hints (e.g. on the module checkboxes explaining what gets copied), use the project's `data-tip` pattern, never native `title=` (per [feedback_no_browser_tooltips.md](feedback_no_browser_tooltips)).

## Frontend wiring

In `revised.js`:

- New IIFE-guarded block for "copy-from-event" wiring. Guard with `if (typeof KnConfig === 'undefined' || !KnConfig.canEditAdmin) return;` — **not** `getElementById` per [`revised.js` IIFE memory rule](../MEMORY.md).
- Functions: `knCfeOpenExpander`, `knCfeSearch`, `knCfePick(eventId)`, `knCfeClear`, `knCfeUpdateEndFromDelta`, `knCfeSubmit`. Mirror for `pkCfe*` in the Park modal.
- `knCfeSubmit` is wired to the existing Create button: when a source is selected, it intercepts before `knCreateEvent` and calls the new endpoint instead.

JS endpoint constants added near the existing `CREATE_URL`:

```js
var COPY_SRC_URL  = UIR + 'EventAjax/copy_source_list';
var COPY_GO_URL   = UIR + 'EventAjax/create_with_copy';
```

## Error handling

| Failure | Response |
|---|---|
| Not logged in | `status:5, error:'Not logged in'` (matches existing handlers) |
| Missing name / kingdom+park / source / dates | `status:1, error:<specific>` shown in `knEvFeedback` |
| Source not in scope | `status:3, error:'Source event is not available in this scope.'` |
| Source has no occurrence | `status:1, error:'Selected event has no past data to copy.'` |
| Auth failure on target scope | `status:3, error:'Not authorized to create events here.'` |
| Mid-pipeline DB failure | Cleanup (see Rollback above), return `status:1, error:'Could not complete copy. No changes were saved.'` |
| Banner file copy fails | Non-fatal: continue, leave `has_banner=0` on new event, include `warning:'Banner could not be copied.'` in success response |

## Testing

Manual test plan in the implementation phase will hit:

1. Kn modal, no host park, copy a kingdom event with all 5 modules → verify new event detail page shows everything, schedule times shifted correctly.
2. Pk modal copy with banner checked → verify banner image present on new event.
3. Copy a source whose schedule has a banned lead and a deactivated staff member → verify schedule item kept (lead gone), staff row dropped.
4. Try to POST to `create_with_copy` with a SourceEventId from a different kingdom → expect `status:3`.
5. Copy with only Feast checked (no Schedule) → verify only feast-category rows landed.
6. Copy with no modules checked → should still create the new event + occurrence with new dates and copied Name, nothing else.
7. Override the pre-filled End time → verify the user value wins.
8. Override the pre-filled Name → verify user value wins.
9. Dark mode walkthrough of the modal in both Kn and Pk surfaces.
10. With permission revoked mid-flight (rare), verify clean rollback — no orphan event row.

## Files touched

| File | Change |
|---|---|
| `orkui/controller/controller.EventAjax.php` | New `create_with_copy`, `copy_source_list`, `_isMundaneEligible` |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | Modal markup additions (collapsible section, source typeahead, date pickers, module checkboxes) + scoped CSS |
| `orkui/template/revised-frontend/Parknew_index.tpl` | Same additions, `pk-` prefixed |
| `orkui/template/revised-frontend/script/revised.js` | New IIFE block(s) for `knCfe*` and `pkCfe*` |

No new migrations. No changes outside these four files (other than the existing untracked `class.Authorization.php` bypass — which per memory is **never** staged).

## Risks

- **Source-pool size.** Old kingdoms might have hundreds of events. The `LIMIT 25` plus typeahead handles this.
- **Time-shift edge cases.** Source schedule with `start_time` past midnight + huge delta could create absurd dates. We don't validate that shifted times fall inside `[NewStart, NewEnd]` — by design, per the Q1 answer. Documented in the user-facing checkbox hover.
- **Banner file race.** If the source banner is mid-upload during the copy, the file might not exist yet. Handled by the non-fatal banner branch.
- **Cache.** The new event's search/calendar cache is busted via existing `_bustEventSearchCache`, but the *source* event's cache is untouched (we only read from it).
