# Rose Release — Event Planning Expansion

**Branch:** `feature/event-planning-expansion`
**Module:** Event detail page becomes a real planning workspace.

The Rose release turns the historical "create an event, set a date,
done" model into a multi-occurrence planning surface: per-occurrence
schedule items with categories and leads, an event-staff system with
granular capabilities, admission/fee tiers, food/feast tracking
(initially separate, now unified into the schedule), and frictionless
on-site sign-in via QR codes and shareable links.

---

## Why this exists

Pre-Rose, the only event-occurrence data we stored was the date.
Everything else — schedule, staff list, food, fees, who's running
what — lived outside the ORK in chat, spreadsheets, or printed
programs. Rose pulls all of that into the event detail page so:

- A single event row owns its own schedule, staff, fees, and food.
- Park officers can hand off planning capabilities (schedule, feast,
  attendance) to a Don of Schedule or Don of Feast without giving
  them broader park-officer rights.
- Day-of attendance is a 30-second loop: monarch hands a phone with
  the QR code → players scan, sign in, hand it back.
- New players can self-register on the spot rather than being told
  to "go home and create an account."

---

## Data model

All new tables are InnoDB / utf8mb4. FK constraints with
`ON DELETE CASCADE` are used on the new tables (a departure from
the older MyISAM convention).

### `ork_event_schedule`
Per-occurrence schedule items.

| column | type | meaning |
|---|---|---|
| `event_schedule_id` | INT PK | |
| `event_calendardetail_id` | INT FK | parent occurrence |
| `title` | VARCHAR(255) | |
| `start_time` / `end_time` | DATETIME | |
| `location` | VARCHAR(255) | free text |
| `description` | TEXT | |
| `category` | VARCHAR(50) | primary category, default `Other` |
| `secondary_category` | VARCHAR(50) | optional second tag |
| `menu` / `cost` / `dietary` / `allergens` | (added by unify-feast migration) — present when `category = 'Feast and Food'` |
| `modified` | TIMESTAMP | |

### `ork_event_schedule_lead`
Many-to-many: schedule item ↔ mundane (the people running/teaching
each item). UNIQUE `(event_schedule_id, mundane_id)`. Cascade-deletes
with the parent schedule row.

### `ork_event_staff`
Per-occurrence staff with capability flags.

| column | type | meaning |
|---|---|---|
| `event_staff_id` | INT PK | |
| `event_calendardetail_id` | INT FK | parent occurrence |
| `mundane_id` | INT FK | the staffer |
| `role_name` | VARCHAR(100) | display label ("Don of Reeves", "Site Tuck", etc.) |
| `can_manage` | TINYINT | full event admin |
| `can_attendance` | TINYINT | take attendance / use sign-in QR |
| `can_schedule` | TINYINT | edit schedule items |
| `can_feast` | TINYINT | edit feast/food items |
| `modified` | TIMESTAMP | |

`can_*` flags are additive — staffers get exactly what they're
granted, not a fixed role tier.

### `ork_event_fees`
Admission/fee tiers per occurrence (Adult / Member / Day Trip / etc.).

| column | type | meaning |
|---|---|---|
| `event_fees_id` | INT PK | |
| `event_calendardetail_id` | INT FK | parent occurrence |
| `admission_type` | VARCHAR(100) | label |
| `cost` | DECIMAL(8,2) | |
| `sort_order` | INT | display order |

### `ork_event_meal` (deprecated, dropped by unify migration)
Originally a parallel meal table. The
`2026-04-12-unify-feast-schedule.sql` migration:
1. Adds nullable `menu` / `cost` / `dietary` / `allergens` columns to
   `ork_event_schedule`.
2. Copies every `ork_event_meal` row into `ork_event_schedule` with
   `category = 'Feast and Food'`, `start_time = event_start`,
   `end_time = event_start + 1 hour`.
3. **Drops `ork_event_meal`.**

After unification, all food/feast data lives in `ork_event_schedule`
filtered by category. The original meal-specific UI becomes a
specialized view of the schedule.

### `ork_attendance_link`
Token-based shareable sign-in link.

| column | type | meaning |
|---|---|---|
| `link_id` | PK | |
| `token` | VARCHAR(64) UNIQUE | URL-safe token |
| `park_id` | INT | scope |
| `kingdom_id` | INT | scope |
| `event_id` | INT | (added 2026-04-12) link an attendance link to an event |
| `event_calendardetail_id` | INT | (added 2026-04-12) and a specific occurrence |
| `by_whom_id` | INT | mundane who created the link |
| `credits` | DOUBLE(4,2) | attendance credits granted per scan, default 1.00 |
| `expires_at` / `created_at` | DATETIME | |

Indexed on `expires_at` for cleanup.

### `ork_selfreg_link`
Token-based self-registration link (different lifecycle: single-use).

| column | type | meaning |
|---|---|---|
| `selfreg_id` | PK | |
| `token` | CHAR(48) UNIQUE | |
| `park_id` | INT UNSIGNED | scope |
| `created_by` | INT UNSIGNED | mundane |
| `created_at` / `expires_at` | DATETIME | |
| `used_by` | INT UNSIGNED NULL | mundane_id of the player who consumed it (NULL = unused) |
| `used_at` | DATETIME NULL | |

Composite index `(park_id, used_by, expires_at)` powers "active
unused links for this park" queries.

### `ork_event_calendardetail` — new column

`event_type VARCHAR(50) NULL` — typed occurrences (Coronation,
Midreign, Warmaster, etc.). Drives icons, royal-progress crowns on
Kingdom events tab, and report filtering.

---

## Code map

### Services / system
- **`class.Event.php`** — extended for schedule, staff, fees, meal
  CRUD; staff-capability resolution helpers.
- **`class.Attendance.php`** — sign-in link consume path, credit
  attribution, dedupe per player per day.
- **`class.Authorization.php`** — event-staff capability gates layer
  on top of standard park/kingdom auth.

### New controllers
- **`controller.QR.php`** — `link($token)` renders the QR landing
  page that resolves the token type (sign-in vs self-reg) and
  hands off.
- **`controller.SignIn.php`** — `index($p)` posts attendance from
  a sign-in link scan. Validates token, dedupes, awards credits.
- **`controller.SelfReg.php`** — `form($token)` shows the
  self-registration form to an unauthenticated visitor; on submit,
  creates the player, marks the token `used_by` / `used_at`, and
  signs the new player into the park's current event if any.

### Modified controllers
- **`controller.Event.php`** + **`controller.EventAjax.php`** —
  schedule CRUD, staff CRUD, fee CRUD, attendance link CRUD.
  EventAjax adds `add_staff`, `remove_staff`, `add_schedule`,
  `remove_schedule`, `update_schedule` (and feast/fee analogues).
- **`controller.AttendanceAjax.php`** — credit configurable per
  link, link-based attendance entry path.
- **`controller.Park.php`** + **`controller.ParkAjax.php`** —
  Self-Registration QR Code generation and Active Sign-In Links
  list with revoke.
- **`controller.Kingdom.php`** — royal progress / crowns surfaced on
  Kingdom events tab via `event_type`.

### Templates
- **`Eventnew_index.tpl`** — the bulk of the work: Schedule tab
  (list + Grid view), Staff tab, Fees tab, Feast tab (now backed by
  schedule rows filtered by category). Schedule item leads with
  Event Staff quick-add. Save modes (save & continue / save &
  close), 5-minute time picker, dark-mode pass.
- **`Eventcreate_index.tpl`** — event creation UX polish, parse
  error fixes, image link.
- **`SignIn_index.tpl`** — token-resolved sign-in confirmation page
  (default theme).
- **`SelfReg_form.tpl`** — self-registration form (revised-frontend).
- **`Search_event.tpl`** — Event discovery improvements.

### Frontend
- `revised.js` / `revised.css` — schedule editor, grid view, fee
  editor, staff cap toggles, QR generation triggers, royal progress
  crowns, copy-link button, RSVP date gating, mobile responsiveness
  for sign-in / self-reg / attendance flows.

---

## Workflows

### Park officer planning an event
1. Open event detail → **Schedule** tab.
2. Add schedule items with category/secondary category, location,
   description, leads (search players → quick-add to Event Staff
   if not already on the staff list).
3. **Staff** tab: add Event Staff; toggle `can_manage` /
   `can_attendance` / `can_schedule` / `can_feast` per person.
4. **Fees** tab: list admission tiers in `sort_order`.
5. **Feast** tab (special view of schedule rows where
   `category='Feast and Food'`): per-meal cost, menu, dietary
   restrictions, allergens.

### Don of Schedule (capability-only staffer)
- Staffer with only `can_schedule = 1` can see and edit schedule
  items but cannot edit other event details, take attendance, or
  modify staff. Authorization layered on top of base park auth.

### Day-of: sign-in via QR
1. Park officer opens the event → **Sign-In Link** action →
   generates a token (URL + QR code).
2. Players scan → land on `controller.SignIn.index` → token
   validated against `ork_attendance_link` (not expired, scope
   matches) → attendance row written with the link's `credits`.
3. Token can be revoked via the Active Sign-In Links list.

### Day-of: brand-new player
1. Park officer generates a Self-Registration QR code from the park
   page (or the kingdom-level Self-Reg link).
2. Visitor scans → `controller.SelfReg.form` shows the
   create-account form (no login required).
3. On submit: account created, `ork_selfreg_link` row marked
   `used_by` / `used_at`, attendance auto-recorded if the link is
   tied to a current event.

### Royal progress display
- `event_type` set on an occurrence → Kingdom events tab shows a
  crown icon (one per royal type) so monarchy presence/progress is
  visible at a glance.

---

## Things to know before changing this branch

- **Feast/meal data lives in `ork_event_schedule`** post-2026-04-12.
  The `ork_event_meal` table is gone. Filter by
  `category = 'Feast and Food'` to find meal rows.
- **Event Staff capability flags are additive, not hierarchical.**
  Don't introduce a "tier" enum — the design is intentionally
  per-capability so kingdoms can mix and match.
- **`can_manage` is the superset.** It implies all the others; a
  staffer with `can_manage = 1` should pass every capability check.
- **Self-reg tokens are single-use** (`used_by` is set on consume,
  composite index expects it for "active links" queries).
  Sign-in links are **multi-use until expiry**. Don't conflate.
- **Sign-in link credits default to 1.00** but are configurable per
  link; some kingdoms use 0.5 for partial-day events. Don't hardcode.
- **Both link types live in the public route space** (no auth
  required to consume) — token validation IS the auth. Do not add
  auth gates that would break the unauthenticated scan flow.
- **`event_type` is free-text VARCHAR(50)**, not an enum, to allow
  per-kingdom additions. The crown-icon display logic enumerates
  known types in a frontend constant; new types render without an
  icon.
- **Schedule item `start_time` / `end_time`** are stored as DATETIME,
  not relative offsets. Editing the parent event date does not
  shift them.

### Overlap with other branches

This branch contains shared infrastructure that also exists on
`feature/player-profile-enhancements` (Mask):

- Audit log viewer (`Admin_auditlog.tpl`,
  `2026-04-21-danger-audit-schema-and-backfill.sql`,
  `class.DangerAudit.php` extensions).
- Inactive Kingdoms / Inactive Parks reports
  (`Admin_inactivekingdoms.tpl`, `Admin_inactiveparks.tpl`).
- Performance indexes (`2026-04-11-performance-indexes.sql`).
- Login / restricted-name / mundane-search QoL fixes.

These overlap intentionally (both branches build on the same admin
foundation work) and should de-duplicate cleanly via shared
ancestor commits — but verify before merging both.

## What's not done in this branch

- **No paid-attendance integration** — fees are display-only; the
  ORK does not collect money or track paid status against
  attendance.
- **No iCal / Google Calendar export** of the schedule.
- **No printable schedule** — same gap as the Crown court program.
- **No staff-capability bulk template** ("Don of Reeves" defaults
  the right boxes); each new staffer is configured manually.
- **No retroactive attendance for sign-in links** — credit is
  attributed at scan time only.
- **`ork_selfreg_link` cleanup** is index-supported but no cron job
  prunes expired rows; table will grow unboundedly without one.
- **Schedule item leads** can only be added via search (no roster
  import), and lead-only views (e.g. "what am I leading at this
  event") are not surfaced on the player profile.
