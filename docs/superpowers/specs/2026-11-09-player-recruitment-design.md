# "How did you find us?" + Player Recruitment Report — design

Date: 2026-11-09 · Branch: `feature/how-did-you-find-us`

## Why

Kingdom and park officers want to know where new players come from so they can
put recruiting effort where it pays off ("flyer recruits come back 60% of the
time, Facebook recruits 15%"). We capture a recruitment source on every new
player, let it be backfilled later, and report on it alongside the other
new-player numbers officers care about: new player counts, the return funnel,
and whether an early award correlates with retention.

## Part 1 — Capture

### Data
- `ork_mundane.recruitment_source VARCHAR(32) NOT NULL DEFAULT ''`
  (migration `db-migrations/2026-11-09-mundane-recruitment-source.sql`, already
  applied to `ork` and `ork_test`, already classified).
- `''` = not answered. Codes → labels, in this display order:

| code | label |
|---|---|
| `friend_family` | Friend or family member invited me |
| `cross_larp` | Cross-gaming from a different LARP |
| `search_website` | Google search / official website |
| `social_media` | Social media (Facebook, Instagram, Reddit, etc.) |
| `walk_up` | Walk-up at a local gathering |
| `convention_demo` | Demo or event at a convention, Renn fair, etc. |
| `flyer` | Saw a flyer, brochure, poster, etc. |
| `other` | Other / prefer not to say |

- Single source of truth: new `system/lib/ork3/class.RecruitmentSource.php`
  with `CODES` (code ⇒ label, ordered) and a static `normalize($v)` returning a
  valid code or `''`. Not a JSON-whitelisted class. Templates get the list from
  the controller via the model layer (no direct lib calls from templates).

### Where it is asked
Dropdown label **"How did you find us?"**, first option "— Select —" (value
`''`). **Optional everywhere** (Claude's call: never block registration).
1. Officer create-player modals posting to `PlayerAjax/park/{id}/create`:
   Kingdom profile (`revised.js` ~12605), Park profile (`revised.js` ~12730),
   ORK Admin (`Admin_index.tpl` ~1035). Officer asks the player and picks.
2. Self-registration form `SelfReg_form.tpl` → `controller.SelfReg.php` →
   `Player::SelfRegister`. Worded to the player ("How did you find us?").
3. Backfill: the Account modal on the player profile (`Playernew_index.tpl`
   ~2733, near Pronouns), saved through `Admin` "Update Details" →
   `Player::UpdatePlayer`. Anyone who can open that modal (self, park officer
   with edit, admin) can set or change it. Submitting "— Select —" clears it to
   `''`; the field being absent from the POST leaves it unchanged (so other
   callers of UpdatePlayer never wipe it).
4. Legacy `default/` create/edit forms: not touched (CRM only). Lib layer
   accepts the field from any caller.

### Visibility (Claude's call)
An individual's answer is shown only in the Account modal (to those who can
edit the player) and in the report's per-player detail table (officers). It is
**not** added to `GetPlayer`'s public output — the profile controller reads it
through a dedicated gated path. Any new public lib method that skips token
checks must have `_` in its name (JSON service exposure rule).

## Part 2 — Player Recruitment Report

Route `Reports/player_recruitment` with `&KingdomId=` or `&ParkId=` (defaults
to session park, then kingdom; `&Pick=1` skips that default and shows the
kingdom picker, which is what the ORK Admin link uses). Template in
`revised-frontend/`, using the shared `.rp-*` shell (`default/style/reports.css`,
which also holds the shared light/dark `.rp-form-*` controls and the opt-in
`.rp-root.rp-tips [data-tip]` tooltip). Charts: **Apache ECharts** (Apache-2.0)
pinned from cdnjs (Highcharts is non-MIT and only in the legacy theme).

### Access
ORK admin; or kingdom officer (AUTH_KINGDOM, EDIT) for a kingdom scope; or
park officer (AUTH_PARK, EDIT) / kingdom officer of its kingdom for a park
scope. Everyone else: polite no-access state. Kingdom scope includes
principalities via `Kingdom::GetStatsKingdomIds` (same as New Player
Attendance).

### Definitions
Every rate uses an eligible-only denominator (players whose window is over) and
is shown with its n. Rates from fewer than 10 eligible players (`MIN_N`) are
greyed out as "too few" (table cells muted with n; bars hatched grey with
"n=X, too few").
- **New player** = their first-ever sign-in (`ork_attendance`, `mundane_id>0`,
  `park_id>0`) falls in the date range, at a park in scope. Same definition as
  the existing New Player Attendance report, so the numbers agree.
- **Day N** counted from the first sign-in date; "within N days" = day offset ≤ N.
- **Came back** = a second distinct sign-in date within 30 days of the first.
  Eligible once the first sign-in is ≥ 30 days old; younger players are
  "not back yet".
- **Still playing at day N** = at least one sign-in (anywhere) dated in
  [first + N, first + N + 30). Not monotonic: a single late visit doesn't count
  as playing at every milestone before it.
- **Too recent** = the day-N window isn't over yet (first + N + 30 > today).
  Such players are excluded from the denominator for that milestone and shown
  as a separate "too recent" sink in the Sankey, never counted as drop-offs.
- Milestones: day 30, 60, 90, 180, 365.
- **Return funnel** = reached the Nth distinct sign-in date (2, 3, 5, 10, 25)
  within 180 days of the first; eligible once ≥ 180 days old.
- **Early award** = a non-revoked `ork_awards` row for the player dated within
  X days of first sign-in (X filter, default 30; options 14/30/60/90).
  Landmark comparison: both groups are restricted to players whose X days are
  over and who signed in on/after day X; only milestones N > X are compared
  (N ≤ X shown greyed as "in window").
- **Dues paid** = a non-revoked `ork_dues` row dated (created_on, else
  dues_from) within 90 days of the first sign-in; eligible once ≥ 90 days old.
  The player detail table still shows "any dues since the first sign-in".
- **Answered at registration vs later**: `ork_mundane.recruitment_source_via`
  (`registration` | `backfill` | `''`). Backfilled answers come mostly from
  players who stayed, which inflates a source's retention (survivorship), so the
  report has an Answers filter (All / At registration only; in the latter, any
  answer not given at registration counts as Not answered) and shows the split
  next to Answered %.

### Filters (sidebar)
Date range of first sign-in (default: last 12 months; at most 5 years, enforced
in `RecruitmentReport::Run` and in the Flatpickr min/max), park (kingdom scope
only), recruitment source (all / one / not answered), Answers (all / at
registration only), early-award window X. Run button; results load in one
request.

### Sections
1. **Stat cards:** New players · Source answered % (with the registration /
   added-later split) · Came back % · Still playing at day 90 % (of eligible).
2. **Where they came from:** horizontal bar of counts by source incl.
   "Not answered" (short axis labels, `RecruitmentSource::SHORT`; full labels in
   tooltips and the table), plus a DataTables table (no paging/search/info,
   sortable, numeric `data-order`, empty values last): source, new players,
   % of total, came back %, day 30 / 90 / 180 retention %, dues paid %, each
   with n. This is the headline table, captioned with the backfill bias.
3. **New players by month:** stacked columns by source.
4. **Retention flow (Sankey):** New players → Came back / Not back within 30
   days / Not back yet → Day 30 playing / Lapsed / Too recent → Day 60 → Day 90
   → Day 180. Stages chain: only players still playing at one stage flow to the
   next. Uses the same per-player facts as the stat cards, so "Came back"
   matches the card. Below 600px it scrolls sideways with a fade and hint. A source
   selector above it re-renders client-side (data for every source shipped in
   the one response).
5. **Return funnel table:** reached 2nd, 3rd, 5th, 10th, 25th sign-in within
   180 days (counts and % of eligible).
6. **Early awards and retention:** grouped bars + table comparing players with
   vs without an award in their first X days, retention at day 30/60/90/180
   (eligible-only denominators, with n shown; landmark rules above). Caption
   explains the in-window exclusion and that it's correlation, not proof of
   cause.
7. **Player detail (DataTables):** persona (link), park, first sign-in, source,
   total sign-ins, last sign-in, first award date, dues paid. Default sort first
   sign-in desc.

Empty and loading states, dark mode, ≤900px layout follow the rp shell rules.

### Entry points
- Kingdom and Park profile **Reports** tab, Players group, next to Population
  Explorer: "Player Recruitment" link. The report's Back button returns there
  (`&tab=reports`).
- ORK Admin (`Admin_index.tpl`) report list next to New Player Attendance,
  linking with `&Pick=1` so admins get the kingdom picker.
- Account modal source field help text: none needed.

## Non-goals
- No free-text "other" detail, no multi-select.
- No change to the New Player Attendance report (it stays; the new report links
  to nothing it replaces).
- No prompting existing players to fill it in (could be a follow-up).
- No Amtgard-wide (all kingdoms) view in v1.

## Layering
SQL only in `system/lib/ork3/` (new report method on `Report` or a new
non-whitelisted `class.RecruitmentReport.php` — builder's call; if on `Report`,
it must take a Token and check authority, or have `_` in its name).
`orkui/model` is the only membrane. Integer-cast all ids;
`mysql_real_escape_string` is a no-op, so regex-validate dates. The cohort's
numeric per-player facts (first date, credited park, sign-in day offsets, first
award, first dues) are cached with ghettocache (300s) per scope + range;
persona, recruitment_source and recruitment_source_via are never cached and are
re-read every run (chunked, int-cast `IN` list), so an Account-modal backfill
shows up on the next run.
