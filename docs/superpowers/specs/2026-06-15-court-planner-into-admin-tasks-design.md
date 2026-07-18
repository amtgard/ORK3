# Move Court Planner into Admin Tasks — Design

**Date:** 2026-06-15
**Surfaces:** Kingdom profile, Park profile (revised-frontend)
**Type:** Enhancement (UI reorganization — reduce top-level tab clutter)

## Problem

Kingdom and Park profile layouts have accumulated too many top-level tabs. "Court Planner"
is a top-level tab on both surfaces, sitting alongside Parks/Events/Map/Players/Reports/
Recommendations/Admin Tasks. Court planning is an admin activity and belongs with the other
admin work rather than competing for a top-level tab slot.

## Goal

Remove the standalone "Court Planner" tab from both the Kingdom and Park profiles and
relocate its full UI into a **collapsible subsection at the bottom of the existing Admin
Tasks tab**, below the current report/utility link grid.

No backend changes: `$CanManageCourt`, `$CourtList`, and `$CourtUpcomingEvents` keep flowing
from the controllers exactly as today. Court creation behavior is unchanged.

## Scope

Two files, mirrored changes:

- `orkui/template/revised-frontend/Kingdomnew_index.tpl`
- `orkui/template/revised-frontend/Parknew_index.tpl`

Out of scope: controllers, models, the `CourtAjax/create_court` endpoint, and any court
creation logic. JS function names, CSS class prefixes, and the modal markup are reused
verbatim — only their DOM location changes.

## Current structure (reference)

### Kingdom — `Kingdomnew_index.tpl`
- Court Planner tab nav `<li data-kntab="court">`: lines ~314–319 (gated `$CanManageCourt`,
  shows `(count)` badge).
- Court Planner panel `#kn-tab-court`: lines ~863–1034. Contains inline `<style>` (`.kn-cp-*`
  classes ~866–885), toolbar + "Plan a Court" button (~886–891), court cards / empty state
  (~897–929), New Court modal `#kn-cp-new-court-modal` (~931–969), inline `<script>` with
  `knCpOnEventChange` / `knCpOpenNewCourt` / `knCpCloseNewCourt` / `knCpSubmitNewCourt`
  (~971–1031).
- Admin Tasks panel `#kn-tab-admin`: lines ~825–847 (gated `$CanManageKingdom`). Contains
  `<div class="kn-report-cols">` with two `kn-report-group` columns (Players; Kingdom).

### Park — `Parknew_index.tpl`
- Court Planner tab nav `<li data-pktab="court">`: lines ~530–535 (gated `$CanManageCourt`,
  shows `(count)` badge).
- Court Planner panel `#pk-tab-court`: lines ~1341–1517. Contains inline `<style>` (`.pk-cp-*`
  classes ~1344–1367), toolbar (~1368–1373), court cards / empty state (~1379–1411), New
  Court modal `#pk-cp-new-court-modal` (~1413–1451), inline `<script>` with `pkCpOnEventChange`
  / `pkCpOpenNewCourt` / `pkCpCloseNewCourt` / `pkCpSubmitNewCourt` (~1453–1514).
- Admin Tasks panel `#pk-tab-admin`: lines ~1144–1165 (gated `$CanManagePark`). Contains
  `<div class="kn-report-cols">` with two `kn-report-group` columns (Players; Park).

### Tab wiring — `script/revised.js`
- `knActivateTab(tab)` (~2651–2672) and `pkActivateTab(tab)` (~6214–6225): generic show/hide
  of `#kn-tab-{tab}` / `#pk-tab-{tab}`; no per-tab special case for `court`. No change needed.
- Click handlers bind `.kn-tab-nav li` / `.pk-tab-nav li` by `data-kntab` / `data-pktab`.
- `?tab=...` URL param auto-activates a tab (~2880 / ~6332).

## Target structure

Per surface (Kingdom shown; Park is the `pk-` mirror):

1. **Remove the Court Planner tab nav `<li>`** entirely, including its `(count)` badge.

2. **Remove the standalone `#kn-tab-court` panel** and relocate its entire contents into the
   bottom of `#kn-tab-admin`. The relocated block stays gated on `$CanManageCourt`, nested
   inside the Admin Tasks `$CanManageKingdom` gate (so it only renders when the viewer can
   both manage the kingdom and manage court — same effective audience as today, since the
   court tab was already admin-only).

3. **Wrap the relocated block in a collapsible subsection**, placed below the existing
   `kn-report-cols` grid and separated from it by a divider/rule:
   - **Header** (clickable): a gavel icon + "Court Planner" label + court-count badge
     `(<?= count($CourtList) ?>)` (only when non-empty) + a chevron that rotates on toggle.
   - **Body**: the relocated toolbar + "Plan a Court" button, court cards / empty state, and
     the New Court modal.
   - **Smart default open state:** render expanded when `!empty($CourtList)`, collapsed when
     empty. PHP emits the initial collapsed/open class; a small inline JS toggle flips it and
     rotates the chevron on header click.

4. The inline `<style>` block (`.kn-cp-*`) and inline `<script>` (`knCp*` functions) move with
   the content, unchanged. Add minimal new CSS for the collapsible header/divider/chevron and
   one tiny toggle function (e.g. `knCpToggleSection()`).

5. **Drop dead tab wiring:** the `(count)` tab badge is removed with the nav `<li>`. Confirm
   nothing else references `data-kntab="court"` / `#kn-tab-court` / `?tab=court` (and the Park
   equivalents) after removal. `knActivateTab` / `pkActivateTab` are untouched.

## Conventions to honor (project rules)

- **Dark mode:** the new collapsible header, divider, chevron, and badge must be dark-mode
  compatible proactively (not a follow-up). Walk the subsection in dark mode before "done".
- **No native tooltips:** any tooltip uses the `data-tip` CSS pattern, never `title`.
- **Heading gray-box reset:** if the subsection header uses an `h*` tag, reset the global
  orkui.css `h1–h6` gray-box styling (`background:transparent;border:none;padding:0;
  border-radius:0;`) so it doesn't render as a gray pill. Prefer a non-heading element to
  avoid the issue entirely.
- **No native confirm/alert:** unchanged (court creation already avoids these).
- **.tpl is plain PHP:** use `<?php ?>` / `<?= ?>`, never Smarty syntax.

## Verification

- Lint both `.tpl` files (`php -l`) after edits.
- Grep both templates and `revised.js` for residual `court` tab references
  (`data-kntab="court"`, `data-pktab="court"`, `kn-tab-court`, `pk-tab-court`, `tab=court`).
- Browser check (after implementation): on a Kingdom and a Park where the viewer can manage
  court — confirm no Court Planner top-level tab; Admin Tasks tab shows the collapsible
  subsection; expands/collapses; defaults expanded when courts exist and collapsed when none;
  "Plan a Court" modal opens and creates a court; dark mode looks correct.

## Risks / notes

- Court-count badge previously lived on the tab; it now lives on the collapsible header. No
  other surface depends on that tab badge.
- Audience nuance: today the court tab is gated `$CanManageCourt` independently of Admin Tasks
  (`$CanManageKingdom`/`$CanManagePark`). Nesting court under Admin Tasks means a viewer who
  can manage court but NOT the org would lose court access. In practice court management is an
  org-admin capability, so these audiences coincide — but the implementer should verify the
  two flags can't legitimately diverge; if they can, render the Admin Tasks tab when
  `$CanManageKingdom || $CanManageCourt` and gate each subsection independently.
