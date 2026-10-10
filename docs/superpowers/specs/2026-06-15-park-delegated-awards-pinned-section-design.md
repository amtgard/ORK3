# Park "Delegated by the Kingdom" Pinned Section — Design

**Date:** 2026-06-15
**Surface:** Park profile → Recommendations tab (`Parknew_index.tpl`)
**Type:** Enhancement (discoverability)

## Problem

When a kingdom officer "passes a recommendation to local" (sets `ork_recommendations.passed_to_local = 1`
via the Recommendations Manager), it delegates authority for the recipient's home park to give an
award normally above the park's level. Today that signal is easy for a park officer to miss:

- On the **Park profile → Recommendations tab** it appears only as a small read-only `↓ passed to local`
  badge inline in the full recommendation list — passive and easily buried.
- It is only actionable in the **Recommendations Manager** (park scope), and only if the officer knows
  to open it and apply the "Passed to local" filter.

There is no dedicated, prominent "the kingdom handed these to you — go give them" surface on the park's
own page. Park officers don't reliably notice delegated awards.

## Goal

Make delegated awards **unmissable** for park officers by pinning them into a distinct, highlighted
section at the top of the Park profile Recommendations tab — a draining "to-schedule" list that points
the officer to where they can act.

Discoverability only. This is not the action-path project (no inline scheduling here) and not a
data-model change.

## Audience gate (firm requirement)

The pinned section is visible **only to park officers**, gated on **`$CanAdminPark`** — the exact flag
already behind the existing "Manage Recommendations" button (`Parknew_index.tpl:1391–1393`;
`$CanAdminPark` = `HasAuthority($uid, AUTH_PARK, $park_id, AUTH_CREATE)`, set in
`controller.Park.php:289–290`).

Critically, this gate is **independent of `AwardRecsPublic`/`$ShowRecsTab`**. When a kingdom makes
award recs public, the Recommendations tab (and the normal rec list) renders for everyone — but the
"Delegated by the Kingdom" section must NOT. A public viewer never sees the delegated-to-do callout.

## Surface & placement

A highlighted block pinned at the **top of the Recommendations tab panel**, above the normal
recommendation list/table. It renders only when:

1. `$CanAdminPark` is truthy, AND
2. at least one qualifying item exists (see filter below).

When zero qualifying items, the section is omitted entirely — no empty-state box.

## What's in it (item filter)

The tab already renders from `$AwardRecommendations` (built in `controller.Park.php:305–321` via
`$this->Reports->recommended_awards(['ParkId' => $park_id, 'KingdomId' => 0, ...])` →
`Report::PlayerAwardRecommendations`). Each `$rec` already carries:

- `PassedToLocal` (bool) — `class.Report.php:643` region.
- `IsOnCourt` (bool) — `class.Report.php:643`, derived from `on_court_count` (count of non-cancelled
  `ork_court_award` rows linked to the rec; `class.Report.php:486`).

The pinned section is the subset of `$AwardRecommendations` where:

```
$rec['PassedToLocal'] === true  &&  empty($rec['IsOnCourt'])
```

Granted/dismissed recs are already excluded from the active rec list upstream, so no extra status check
is needed. This subset is a "to-schedule" list that drains to empty as the officer schedules each item
into a court.

**No schema change, no new endpoint, no new controller query** — the data is already in hand; the
section is a filtered view of the existing array.

### Row content (read-only)

Each row shows, at minimum: **recipient persona** (`$rec['Persona']`) and **award name**
(`$rec['AwardName']`, with the same `Order of (the)` prefix-trim the tab already applies). Reuse the
existing `pk-rec-passlocal` blue treatment for visual identity. Rows are read-only, consistent with the
rest of the tab. Optionally include recommended-date/age (`$rec['DateRecommended']`/`$rec['AgeDays']`)
if it renders cleanly; not required.

De-duplication: mirror whatever the normal tab list does for parallel recs of the same recipient+award.
The tab currently renders one `<tr>` per `RecommendationsId`; the pinned section follows the same
row-per-rec rendering for consistency (no new grouping logic).

## Heading & CTA

- **Heading (non-`h*` element to avoid the global `h1–h6` gray-box, or reset it):**
  `⬇ Delegated by the Kingdom — to schedule (N)` where N is the count of qualifying items.
- **Helper line:** "The kingdom granted your park authority to give these. Schedule them into a court."
- **CTA button:** `Manage →` linking to the Recommendations Manager, park scope, pre-filtered to
  passed-to-local: `<?= UIR ?>Recommendations/manage/park/{parkId}?passlocal=1`.

### Manager URL-param support (small addition)

The Manager's "Passed to local" filter (`#rm-filter-passlocal`, `Recommendations_manage.tpl:543`) is
client-side only (`rmApplyFilters`, `revised.js:773–807`) with no URL-param pre-application today.

Add: on Manager init (near `revised.js:1291`, before the first `rmApplyFilters()` call), read
`new URLSearchParams(window.location.search)` and, if `passlocal=1`, set
`document.getElementById('rm-filter-passlocal').checked = true` before applying filters. Keep it minimal
and forward-compatible (a single param now; structure it so other filter params could be added later,
but only `passlocal` is in scope).

## Conventions to honor (project rules)

- **`.tpl` is plain PHP** — `<?php ?>`/`<?= ?>`, never Smarty.
- **Dark mode compatible proactively** — the section background, border/accent, heading text, helper
  text, and rows must be legible in dark mode (`html[data-theme="dark"] …`). Reuse the established
  `pk-rec-passlocal` palette (`#2c5f8b` light / `#6fb0e6` dark, already defined in `Parknew_index.tpl`).
- **No native `title` tooltips** — use `data-tip`.
- **Heading gray-box reset** — do not let the global orkui.css `h1–h6` style turn the heading into a
  gray pill; use a styled non-heading element or explicitly reset.
- **Mobile-friendly** — rows stack/wrap; the `Manage →` CTA stays reachable.

## Nuance (accepted for v1)

`IsOnCourt` reflects placement on **any** non-cancelled court (kingdom or park), because `on_court_count`
does not scope by court owner. So a delegated rec already placed on a *kingdom* court drops off the
park's to-schedule list even if the park hasn't personally given it. This is acceptable: delegation
implies the park gives it on a park court, and the common case is that delegated recs are not on any
court yet. We deliberately do NOT add park-court-only court-map filtering in v1.

## Out of scope (possible later "secondary touches")

- A delegated-count chip on the Recommendations tab nav `<li>`.
- Inline "Add to Court" action on each pinned row (the action-path project).
- Any profile-level/hero callout visible across tabs, or cross-surface notifications.
- Kingdom-side or Player-profile equivalents.

## Verification

- `php -l` on edited templates; lint clean.
- Officer (`$CanAdminPark`) on a park with ≥1 delegated, not-on-court rec sees the pinned section with
  the correct count; non-officer (incl. the `AwardRecsPublic`-on public-viewer case) does NOT see it.
- A delegated rec that gets added to a court drops off the section (count decrements).
- Zero qualifying items → section absent (no empty box).
- `Manage →` lands on `Recommendations/manage/park/{id}` with the "Passed to local" filter pre-checked
  and applied.
- Dark mode: section legible; tooltips are `data-tip`, not native.
