# Calendar Enhancements Round 2 — Design Spec

**Date:** 2026-04-27
**Branch:** `feat/calendar-items` (continuing past 882d84a Zodiac/Calendar Items)
**Excludes:** All work on `feature/event-planning-expansion`.

## Goals

Add six independent enhancements to the kingdom/park calendar surface:

1. Officer-only calendar items (lightweight visibility flag).
2. RSVP ▾ button on Kingdom + Park Events list rows.
3. Map view as a third toggle on the Events tab (List / Calendar / Map).
4. Weather forecast badge on event surfaces (Open-Meteo).
5. Sunrise/sunset tooltip on the event detail page.
6. Draft events with a publish flow.

All six ship as one bundled enhancement, mirroring how the Zodiac/Calendar Items work landed in 882d84a.

## Schema

Single migration: `db-migrations/2026-04-27-calendar-enhancements-r2.sql`.

```sql
ALTER TABLE ork_calendar_item
  ADD COLUMN is_officer_only TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE ork_event
  ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'published',
  ADD INDEX idx_event_status (status);

CREATE TABLE ork_weather_cache (
  cache_key     VARCHAR(64) NOT NULL PRIMARY KEY,
  lat           DOUBLE NOT NULL,
  lng           DOUBLE NOT NULL,
  forecast_date DATE NOT NULL,
  payload       MEDIUMTEXT NOT NULL,
  fetched_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_weather_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`cache_key = sha1(round(lat,3) || ':' || round(lng,3) || ':' || forecast_date)` — coordinate rounded to ~110m so nearby events share entries.

## Feature 1 — Officer-only calendar items

**Visibility rule (Q1=C):** when `is_officer_only=1`, item is shown only to:

- ORK Admins, OR
- Anyone present in `ork_officer` for that kingdom (when `park_id=0`) or park (when `park_id` set), in any of the four roles (Monarch / Regent / Prime Minister / Champion).

**Backend touchpoints:**

- New helper `CalendarItem::CanSee($mundane_id, $item)` in `system/lib/ork3/class.CalendarItem.php`. Returns true if `is_officer_only=0`, OR caller is ORK Admin, OR caller has a row in `ork_officer` matching the item's kingdom/park scope.
- `controller.Kingdom.php` calendar-item fetch path filters out items the requester can't see.
- `controller.Park.php` same.
- `controller.CalendarItemAjax.php::get` returns 403 when caller can't see.
- `CanEdit` flag in the item payload remains the existing `AUTH_CREATE` check; the visibility check is independent.

**Frontend:**

- Modal: add `<input type="checkbox" id="ci-officer-only">` labelled "Officer-only — hide from non-officers" inside the existing calendar-item create/edit modal in `revised.js`. Writable by anyone who can already create items (no extra gate on the input itself).
- List rendering: officer-only items render with a small shield icon `🛡` before the title and a tinted background (`background-color: var(--kn-officer-tint)` / `--pk-officer-tint`) so officers see at a glance which are restricted.

## Feature 2 — Draft events

**Schema:** `ork_event.status` (`'published'` | `'draft'`, default `'published'`). All existing rows backfilled to `'published'` by the default.

**Visibility rule (Q6=B):** drafts visible to:

- Event creator (`ork_event.mundane_id == $pid`), OR
- Anyone passing `HasAuthority($pid, AUTH_EVENT, $event_id, AUTH_EDIT)`.

Filter applied in:

- `class.Event.php` event-list methods consumed by `controller.Kingdom.php` + `controller.Park.php`.
- Event detail (`controller.Eventnew.php` / `controller.Event.php` whichever serves the detail page) returns 403 to non-editors when status is draft.

**Frontend (Q6=ii):**

- For viewers who can see them: row in Kingdom/Park list gets a muted style + `DRAFT` pill; calendar grid + map pins get the same treatment.
- Event detail page gets a banner above the hero: "This event is a draft and is hidden from members. Publish to make it visible." with a Publish button right-aligned.
- Edit form gains a Publish / Move-to-draft toggle near the existing Save button.

**Create flow (Q6=y):**

- Event create form gains a secondary submit "Save as draft" alongside the primary "Save" (which saves as published).
- Endpoint: same create endpoint accepts a `status` field (default `'published'`).

**Audit:** the existing UpdateEvent audit-log diff includes the `status` column automatically once it's a tracked field. Verify status transitions render cleanly in the audit log detail panel.

## Feature 3 — RSVP ▾ button on list rows

**Surfaces (Q2=A+ii):**

- Kingdom Events tab list (`Kingdomnew_index.tpl` ~lines 505–520).
- Park Events tab list (`Parknew_index.tpl`).
- Same widget reused in map popovers (Feature 5).

**Stateful button:**

- No RSVP → `RSVP ▾`
- Going → `✓ Going ▾` (green tint, dark-mode-compatible)
- Interested → `★ Interested ▾` (amber tint)

**Dropdown menu:** Going · Interested · Withdraw RSVP. Withdraw is disabled when no current RSVP.

**Backend:** new controller `orkui/controller/controller.EventRsvpAjax.php`:

- `POST set` body `{event_calendardetail_id, status}` where `status ∈ {going, interested}` — upserts `ork_event_rsvp` (the unique index on `(event_calendardetail_id, mundane_id)` makes this an `INSERT ... ON DUPLICATE KEY UPDATE`).
- `POST withdraw` body `{event_calendardetail_id}` — `DELETE` by `(event_calendardetail_id, mundane_id)`.
- Both check user is logged in; no other authorization required.
- Both return the new authoritative `going_count` + `interested_count` for that detail row.

**Server render:** events list fetch precomputes `MyRsvpStatus` per row in one batch query keyed on `mundane_id` joined to the result set's `event_calendardetail_id`s. Single round-trip; no N+1.

**Client behavior:** click is optimistic — increment/decrement counts client-side immediately, then reconcile against the server-returned authoritative counts.

**Gotcha:** RSVP joins on `event_calendardetail_id`, not `event_id`. The list rows must expose the correct detail id per row (the row's "displayed date" detail). Confirm during implementation.

## Feature 4 — Map view on Events tab

**Surfaces (Q3=B):** new "Map" button in the existing List / Calendar toggle on Kingdomnew Events tab and Parknew Events tab. Lazy-loads Google Maps JS on first click (mirrors the Kingdomnew Map tab's `knLoadMap()` pattern).

**Server-side data assembly:**

- Kingdom: `$knEventMapLocations` precomputed in `controller.Kingdom.php` — events in next 90 days, lat/lng resolved per event:
  1. Event has its own `location` JSON → use that.
  2. Else event has `at_park_id` set → use that park's `latitude`/`longitude`.
  3. Else drop from map (counted toward "no location" footer).
- Park: `$pkEventMapLocations` in `controller.Park.php`, same logic but scoped to the park's events.

**Pin payload schema:**

```json
{
  "event_id": 123,
  "event_calendardetail_id": 456,
  "name": "Spring War",
  "date": "2026-05-15",
  "lat": 33.4484,
  "lng": -112.0740,
  "my_rsvp_status": "going|interested|null",
  "going_count": 12,
  "interested_count": 5,
  "weather": { "code": 2, "high_f": 78, "low_f": 62 } | null
}
```

**Pin rendering:** star-icon pins distinct from park pins (kingdom Map tab keeps its existing park pins). Click → popover containing event name (linked to event detail), date, RSVP ▾ widget (same component as list rows), weather badge if available.

**Footer:** if any events in the 90-day window had no resolvable coords, show "N events in this window have no map location" below the map.

**Filter scope:** Round 2 map = events only. Calendar items not on the map (deferred). The existing Calendar-Items filter button on the kingdom Events tab does not apply when Map view is active (it's hidden in Map mode).

## Feature 5 — Weather forecast badge

**API:** Open-Meteo (`https://api.open-meteo.com/v1/forecast`) — free, no key, worldwide, 16-day forecast.

**Helper:** `system/lib/ork3/class.Weather.php::GetForecast($lat, $lng, $date)`:

1. Compute `cache_key`. Look up `ork_weather_cache`; if `fetched_at` is within 2h, decode payload and return.
2. Else issue HTTPS GET to Open-Meteo with params: `latitude`, `longitude`, `daily=temperature_2m_max,temperature_2m_min,weather_code`, `timezone=auto`, `start_date=$date`, `end_date=$date`, `temperature_unit=fahrenheit`. Timeout 4s.
3. On success: write/update `ork_weather_cache` row, return parsed `{ code, high_f, low_f }`.
4. On error or non-200: return `null` (callers hide the badge gracefully).

Server-side fetch only — never from the browser (preserves caching, avoids fingerprinting).

**Display rule:** badge shown only when `forecast_date - now <= 16 days` AND coords resolvable. Multi-day events use the start day.

**Surfaces (Q4=C):**

- Event detail header: `🌤 H 78° L 62°` next to date/time row.
- Kingdom + Park Events tab list rows: small inline pill on the right of the row.
- Map popovers: same pill, inside the popover.

**Weather-code mapping:** Open-Meteo WMO codes → emoji + short label, e.g.
- 0 → ☀ Clear
- 1–2 → 🌤 Partly cloudy
- 3 → ☁ Cloudy
- 45, 48 → 🌫 Foggy
- 51–67 → 🌧 Rain
- 71–77 → ❄ Snow
- 80–82 → 🌧 Showers
- 95–99 → ⛈ Thunderstorm

## Feature 6 — Sunrise / sunset tooltip

**Surface (Q5=B):** small ☀ icon next to the event date on the event detail page only. Uses the existing `data-tip` CSS-tooltip pattern (no native `title` — per hard rule).

**Tooltip content (multi-line):**

```
Sunrise 5:42 AM
Sunset 8:31 PM
Twilight 6:08 AM – 8:55 PM
```

**Computation:** new `system/lib/ork3/class.SolarTimes.php` with `SolarTimes::ForDate($lat, $lng, $date, $timezone)` returning `{sunrise, sunset, civil_twilight_start, civil_twilight_end}` as local-time strings. Uses NOAA solar formulas — no external API needed.

**Coords:** event coords first, then fallback to `at_park_id` park coords. Icon and tooltip hidden if neither.

## Cross-cutting

- **Dark mode:** every new pill / badge / dropdown / banner / tooltip / modal addition must use existing dark-mode tokens proactively. New CSS variables for officer-only tints + draft styling defined for both light and dark themes.
- **Mobile:** kingdom Events list is already tight on mobile. The stateful single-button RSVP, compact weather pill, and DRAFT pill are designed to fit. Map view on mobile uses the full Events-tab area.
- **Memcache flush:** invalidate cached kingdom/park event lists after:
  - Event status change (draft ↔ published).
  - RSVP set/change/withdraw.
  - Calendar-item officer-only toggle.
- **No `title` attrs:** all hover info uses `data-tip` (per hard rule).
- **`$DB->Clear()`** before any new raw Execute/DataSet (per hard rule).
- **PHP edits:** any multi-line PHP modification uses Python, not the Edit tool (per hard rule).
- **Audit log:** confirm status transitions on UpdateEvent emit a clean diff in the audit detail panel.

## File-level summary

**New files:**

- `db-migrations/2026-04-27-calendar-enhancements-r2.sql`
- `orkui/controller/controller.EventRsvpAjax.php`
- `system/lib/ork3/class.Weather.php`
- `system/lib/ork3/class.SolarTimes.php`

**Modified files:**

- `system/lib/ork3/class.CalendarItem.php` — `CanSee()` helper, `is_officer_only` field handling.
- `system/lib/ork3/class.Event.php` — draft visibility filter, batched MyRsvpStatus, weather-summary helper hook.
- `system/lib/ork3/class.Authorization.php` — only if missing a needed admin-check helper (likely fine as-is).
- `orkui/controller/controller.Kingdom.php` — events list MyRsvpStatus + draft filter, calendar-items officer-only filter, `$knEventMapLocations`.
- `orkui/controller/controller.Park.php` — same as Kingdom but scoped.
- `orkui/controller/controller.CalendarItemAjax.php` — handle new field on create/update; `get` 403 when not visible.
- `orkui/controller/controller.Event.php` (or `controller.Eventnew.php`) — accept `status` on create/update; gate detail by visibility; expose draft banner state to template.
- `orkui/template/revised-frontend/Kingdomnew_index.tpl` — Map toggle + map render block, RSVP ▾ on list rows, DRAFT pill, calendar-items officer-only checkbox + shield, weather pill on rows.
- `orkui/template/revised-frontend/Parknew_index.tpl` — same as Kingdomnew but scoped.
- `orkui/template/revised-frontend/Eventnew_index.tpl` — draft banner, status toggles in edit form, "Save as draft" on create, sunrise ☀ tooltip, weather badge on detail.
- `orkui/template/revised-frontend/script/revised.js` — calendar-item modal officer-only checkbox, RSVP dropdown component, map view code (lazy Google Maps load), weather/sunrise rendering helpers, draft create/edit toggles.
- `orkui/template/revised-frontend/style/revised.css` — RSVP button states, DRAFT pill, officer-only tint, weather pill, sunrise icon, map popover, mobile-tightening rules — all dark-mode-aware.

## Testing strategy

Manual walkthrough per feature:

1. **Officer-only:** as ORK admin, kingdom Monarch, park Monarch, regular member — verify items hide/show correctly. Toggle the flag from a non-officer-eyes session and confirm it disappears.
2. **Draft events:** as creator, editor, ORK admin, regular member — verify draft visibility, banner present on detail for editors, hidden entirely from members. Create-as-draft → edit → publish → confirm members can now see.
3. **RSVP:** from each surface (kingdom list, park list, map popover) set Going, change to Interested, withdraw. Confirm counts update and persist on reload.
4. **Map view:** load with mix of (a) events with own coords, (b) events with `at_park_id` only, (c) events with no coords. Verify pin/footer behavior. Confirm RSVP ▾ in popover works.
5. **Weather:** event 1 day out → badge shows; event 30 days out → no badge; API down (block in /etc/hosts) → no badge, cache fallback. No coords → no badge.
6. **Sunrise:** northern park (long summer days), equatorial park, no-coords park. Confirm tooltip uses `data-tip` not native.
7. **Dark mode:** walk every surface above in dark mode.

## Out of scope

- Calendar items on the Map view (deferred).
- Weather and sunrise on calendar items (events only).
- Re-styling existing event-detail RSVP UI to match the new dropdown (separate change).
- Event-changed notifications (deferred — separate feature on the prior brainstorm list).
