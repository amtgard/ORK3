# Crown Release — Court Planner

**Branch:** `feature/court-planner`
**Module:** Royal court planning + recommendation lifecycle improvements.

The Crown release adds a structured workflow for planning and running
royal courts: queue up awards, assign artisans/scroll-makers,
sequence the give-out, and grant in one click during court itself —
with the originating recommendation, reason, and contributors
preserved as context on the resulting award.

A second concern: improve the *upstream* of court — how
recommendations are filtered before reaching court — by adding
**snooze**, **anonymous submission**, and (locally on this branch)
a parallel **support** mechanism for +1s.

---

## Why this exists

Today, royal courts are planned in spreadsheets / chat / nothing,
and on the day of court the monarchy gives awards from memory or
notes. There is no structured record of *which recommendation* led
to *which award*, no way to credit scroll/regalia makers, no
ordered run-of-show, and no way to defer a recommendation that the
current monarchy doesn't intend to act on without flat-out deleting
it (which removes valuable record).

Crown solves the planning side end-to-end: list courts, build out
each court's award list (from pending recommendations or
free-form), assign artisans, reorder, and use the planner *as the
script* during court itself — clicking Grant in court produces a
real `ork_awards` row tagged back to the planning context.

---

## Data model

### `ork_court`
A planned court — kingdom court (`park_id = 0`) or park court.

| column | type | meaning |
|---|---|---|
| `court_id` | INT UNSIGNED PK | |
| `kingdom_id` | INT UNSIGNED | scope |
| `park_id` | INT UNSIGNED | `0` = kingdom court |
| `name` | VARCHAR(100) | display label |
| `court_date` | DATE NULL | optional date |
| `event_calendardetail_id` | INT UNSIGNED NULL | optional link to an event occurrence |
| `status` | ENUM(draft, published, complete) | lifecycle |
| `created_by` | INT UNSIGNED | mundane id |
| `modified` | TIMESTAMP | |

### `ork_court_award`
An award queued on a court — the heart of the planner.

| column | type | meaning |
|---|---|---|
| `court_award_id` | INT UNSIGNED PK | |
| `court_id` | INT UNSIGNED | parent court |
| `mundane_id` | INT UNSIGNED | recipient |
| `kingdomaward_id` | INT UNSIGNED | what to give |
| `rank` | INT | ladder rank or `0` |
| `recommendations_id` | INT UNSIGNED NULL | source rec, if any |
| `sort_order` | INT | sequencing during court |
| `pass_to_local` | TINYINT | kingdom approved → park to actually give |
| `notes` | TEXT | internal monarchy notes |
| `status` | ENUM(planned, announced, given, cancelled) | |
| `scroll_maker_id` | INT UNSIGNED NULL | (added 2026-03-27) artisan credit |
| `regalia_maker_id` | INT UNSIGNED NULL | (added 2026-03-27) artisan credit |
| `modified` | TIMESTAMP | |

### `ork_court_award_artisan`
N additional artisan contributors per court award.

| column | type | meaning |
|---|---|---|
| `court_award_artisan_id` | INT UNSIGNED PK | |
| `court_award_id` | INT UNSIGNED | parent |
| `mundane_id` | INT UNSIGNED | the artisan |
| `contribution` | VARCHAR(255) | free-text role ("scroll", "favor", etc.) |
| `modified` | TIMESTAMP | |

### `ork_recommendations` — new columns

Migration `2026-03-25-add-snooze-to-recommendations.sql`:

| column | meaning |
|---|---|
| `snoozed_by_id` | mundane_id of who snoozed (audit) |
| `snoozed_monarch_id` | monarch mundane_id at snooze time (`0` = no monarch) |
| `snoozed_regent_id`  | regent mundane_id at snooze time (`0` = no regent) |

**Snooze auto-expires when the monarchy changes.** Read-side check:
the rec is hidden as snoozed only while *both* stored values still
match the kingdom's *current* `monarch_id` and `regent_id` —
implemented in `Report::PlayerAwardRecommendations` around line 491.
Snooze is **not** a soft-delete; it's a "not now" signal scoped to
this regnum.

### `ork_awards` — new columns

Migration `2026-03-27-court-rec-enhancements.sql`:

| column | meaning |
|---|---|
| `court_award_id` | the planning row this award was granted from |
| `source_reason` | the originating recommendation's `reason`, snapshotted at grant time |

This is **context preservation**: the award row remembers why it was
given and which court it came from, so reports can attribute it
years later even if the court or rec gets cleaned up.

### `ork_recommendation_support`

Migration `2026-03-27-court-rec-enhancements.sql`:

| column | meaning |
|---|---|
| `support_id` | PK |
| `recommendations_id` | parent rec |
| `mundane_id` | supporter |
| `date_added` | DATE |

UNIQUE `(recommendations_id, mundane_id)` — one support per
supporter per rec.

> **⚠️ Parallel implementation.** This is the same conceptual
> feature as `ork_recommendation_seconds` on the Mask
> (`feature/player-profile-enhancements`) branch — both branches
> independently solved "+1 a recommendation". The Mask version is
> richer (notes, soft-delete, supporter masking, originator-reason
> edit) and is what shipped to that branch's release notes.
> One of these two implementations needs to win during merge; do
> not let both land. See `agent-instructions/award-recommendations.md`
> §8 for the Mask-side documentation.

### Anonymous award recommendations

This branch wires up the existing `ork_recommendations.mask_giver`
column end-to-end:

- **Submit**: "Submit Anonymously" checkbox on every Add
  Recommendation modal (Player / Park / Kingdom).
- **Display**: when `mask_giver = 1`, the giver is shown as
  *Anonymous* italicized — except to ORK Admins, who always see
  the real name.
- **Service** (`class.Player.php:1717`):
  `$awardRec->mask_giver = !empty($request['Anonymous']) ? 1 : 0`.

---

## Code map

### Service layer
**`system/lib/ork3/class.Court.php`** — single class. Public API:

- `canManage($uid, $kingdom_id, $park_id = 0)` — auth gate
  (kingdom-court manage requires monarch/regent of the kingdom;
  park-court manage allows park officers in addition).
- `getCourtList($kingdom_id, $park_id = 0)` — list courts in scope.
- `getCourtDetail($court_id)` / `getCourtAwards($court_id)` — full
  court load with awards + artisans.
- `getPendingRecommendations($kingdom_id, $park_id = 0)` — feeds
  the "Add from rec" picker; honors snooze.
- `getKingdomAwardOptions($kingdom_id)` — for the free-form add
  picker.
- `getUpcomingEvents($kingdom_id)` — for `event_calendardetail_id`
  linkage.
- `updateAwardTrackingStatus($courtAwardId, $type)` — toggles
  scroll/regalia tracking on the planning row.

`class.Player.php` is extended with:
- Snooze set/clear methods writing the 3 columns.
- Grant-from-court flow that snapshots `source_reason` and tags
  `court_award_id` on the new `ork_awards` row.
- `mask_giver` plumbing on `AddAwardRecommendation`.

`class.Report.php` (`PlayerAwardRecommendations`):
- SELECTs `mask_giver`, `snoozed_*`, joins to current monarchy.
- Computes `IsAnonymous` and `IsSnoozed` per rec.
- Snooze gate: `snoozed_monarch_id == current_monarch_id AND
  snoozed_regent_id == current_regent_id`. Both must match.

### Controllers
- **`controller.Court.php`** — page routes:
  - `list($context, $id)` — kingdom or park court list.
  - `detail($court_id)` — single court editor / runner.
- **`controller.CourtAjax.php`** — 14 endpoints:
  `create_court`, `update_court_status`, `add_award`,
  `remove_award`, `update_award`, `reorder_awards`, `add_artisan`,
  `remove_artisan`, `grant_award`, `skip_award`,
  `update_award_tracking_status`.

### Templates / frontend
- `Court_list.tpl`, `Court_detail.tpl` — legacy-default templates
  for the list and editor.
- `Kingdomnew_index.tpl` Reports tab — Court Planner tab + entry
  point.
- `Parknew_index.tpl` — park-court entry.
- `Playernew_index.tpl` — anonymous-rec submit checkbox + display
  treatment on the Recommendations tab.
- `revised.css` / `revised.js` — Court Planner row redesign,
  reorder UX, modal polish.

---

## Workflows

### Monarchy planning court
1. Kingdom profile → Reports tab → **Court Planner**.
2. Create a court (name, date, optional event link, status =
   draft / published / complete).
3. Open the court → add awards from pending recs (one click;
   recommendation context follows the row) or free-form (pick
   `kingdomaward_id` + recipient).
4. Reorder via drag, set rank, add notes, mark `pass_to_local`
   for park-given awards, assign scroll / regalia makers and
   additional artisans.
5. Move court status to **published** to share with park officers.

### During court
1. Open the court detail page on a phone/tablet.
2. Walk the planned list in `sort_order`.
3. **Grant**: writes `ork_awards` with `court_award_id` and
   `source_reason` populated; flips the planning row to
   `status = given`.
4. **Skip**: flips to `status = announced` or back to `planned`
   without granting (configurable per click).
5. After court: status → **complete**.

### Recommendation hygiene (snooze)
1. On Player / Park / Kingdom Recommendations tab, monarch or
   regent **Snooze** a rec they don't intend to act on this regnum.
2. Snoozed rec drops out of the visible list and out of the
   "Add from rec" Court picker.
3. When the next monarchy takes office, the stored
   `snoozed_monarch_id` / `snoozed_regent_id` no longer match
   current → the rec re-appears automatically.

### Anonymous recommendation
1. Add Recommendation modal → tick **Submit Anonymously**.
2. Stored as `mask_giver = 1`.
3. Display masks the giver everywhere except ORK Admin views.

---

## Things to know before changing this branch

- **Snooze is regnum-scoped, not time-scoped.** Don't add a
  `snoozed_until DATE` shortcut; the design intent is that snooze
  expires on monarchy turnover, not the calendar.
- **Snooze gate requires BOTH stored values to match current.**
  If a kingdom currently has no regent (`current_regent_id = 0`),
  the rec only stays snoozed if `snoozed_regent_id = 0`. Keep
  this symmetric — partial matches must un-snooze.
- **Court → Award context preservation is one-way.** Granting from
  the planner stamps `court_award_id` and `source_reason` on the
  new `ork_awards` row. Editing the planner row later does **not**
  retroactively update the award. The award is the historical
  record; the planner is the workspace.
- **Artisan credit is data-only.** It does not currently surface
  on the recipient's award row or on the artisan's profile —
  only on the court detail page. This is the obvious next surface
  to wire up.
- **`pass_to_local` flag** changes who is expected to give the
  award but does not enforce it; park officers can still see the
  planned row and grant from it.
- **`ork_recommendation_support` collides with the Mask branch's
  `ork_recommendation_seconds`** (see callout above). Pick one
  before merging either to master.
- **Anonymous reveal is ORK Admin only.** Don't broaden the reveal
  to kingdom monarchs without explicit product sign-off — the
  privacy contract is "anonymous to everyone except platform
  administrators."

## What's not done in this branch

Inferred from the surface — feature is functional but rough edges:

- **No artisan credit display** on the recipient's award row or
  on artisan profiles. The data is captured; surfacing it is the
  obvious next step.
- **No printable court program** export (PDF / printable HTML).
  Today the planner *is* the runtime UI, which works on a phone
  but not great for ceremonial use.
- **No bulk add from rec list** — must add recs to the court one
  at a time. A multi-select picker would help large kingdom
  courts.
- **No "previous court" copy** — every court starts empty.
- **`ork_recommendation_support` not wired into UI** beyond the
  schema; the support concept overlaps with the Mask branch's
  more complete `recommendation_seconds`. Resolve before merge
  rather than shipping both.
- **No notification** to recipients/artisans when they're added
  to a planned court (intentional — secrecy is a design constraint
  pre-court — but worth surfacing post-grant).
