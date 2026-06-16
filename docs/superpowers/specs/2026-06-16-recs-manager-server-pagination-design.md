# Recommendations Manager — Server-Side Lazy Pagination — Design

**Date:** 2026-06-16
**Surface:** Recommendations Manager (`Recommendations/manage/{kingdom|park}/{id}`)
**Type:** Enhancement (performance / scalability)

## Problem

The Recommendations Manager fetches the **entire** recommendation set for a scope in one query
(`Report::PlayerAwardRecommendations`, no LIMIT), groups it in PHP, renders **every** group row,
and does all search / filter / sort **client-side over the DOM**. On a large kingdom this is
1900+ recs → ~1200-1400 DOM rows rendered up front, blocking DOMContentLoaded and bloating the
page. The query also selects the full set every time (300s cached).

## Goal

Never select more than **500 displayed rows (groups)** from the DB at a time. Load the first
500, then **lazy-load further 500-row batches via infinite scroll**. Because the page must never
hold the full set, search / filter / sort move **server-side** (each is a fresh capped query from
offset 0). Also bring the eligibility filter in line with the recs-tab pills (Open Recs default).

Decisions (confirmed with user):
- **Batch size:** 500 displayed rows (groups), never query the full set.
- **Load trigger:** infinite scroll (auto-load next 500 near bottom; loading + end-of-list states).
- **Eligibility set:** Open Recs (default) · Below Rec'd · Non-Ladder · At or Above Rec'd · All ·
  Snoozed. Default **Open Recs** hides already-has AND snoozed.
- **Count display:** "Showing N of M" — M via a cheap `COUNT(DISTINCT cluster)`, not a full fetch.
- **Default sort:** **newest first (date DESC)** (changed from today's oldest-first).

## Current state (reference)

- Controller `manage()` (`controller.Recommendations.php:11-152`): calls
  `Reports->recommended_awards($req)` → `Report::PlayerAwardRecommendations` (full set), groups by
  composite key **`mundane_id : kingdomaward_id : rank`** into `$Groups` (one DOM row per group;
  parallel supporters collapsed into `Members[]`), loads `$CourtMap`, `$Courts`, `$Parks`, passes
  all to the template. No limit/offset.
- Query `PlayerAwardRecommendations` (`class.Report.php:431-671`): one row per recommendation (no
  GROUP_CONCAT; seconds fetched separately via `GetSecondsForRecommendations`), `ORDER BY persona,
  award, rank`, no LIMIT. 300s cache keyed on scope; bust via `ghettocache->bust(...)`.
- Template (`Recommendations_manage.tpl`): `foreach ($Groups)` renders `<tr class="rm-row" …>` with
  data-attributes; client `rmApplyFilters()` (search on `data-recip`, eligibility on `data-elig`/
  snoozed, court on `data-courts`, park on `data-park`, passlocal) and `rmSort()` (recip/award+rank/
  date/supp) operate on the DOM. All row/bulk action handlers are **delegated on `#rm-tbody`** (so
  lazy-appended rows need no re-binding). Footer `#rm-count` shows visible count.

## Target architecture

Move from "load-all + client filter/sort" to "server-side filter/sort/paginate; client appends
500-row batches via infinite scroll."

### 1. Data layer — `Report::PlayerAwardRecommendationsPage($request)` (new)

Inputs: `KingdomId`/`ParkId` (scope, same as today incl. principality roll-up), `RequestedBy`,
plus:
- `Filters`: `Search` (recipient substring), `Eligibility` (`open|below|ator|nonladder|all|
  snoozed`), `Court` (`all|none|any|court:<id>`), `Park` (park id or `all`, kingdom scope only),
  `PassLocal` (bool).
- `Sort`: `Key` (`recip|award|date|supp`), `Dir` (`asc|desc`).
- `Limit` (default 500), `Offset`.

Returns: `{ Groups: [...≤Limit grouped rows…], Total: int, HasMore: bool }`.

**Pagination unit = the cluster** (`mundane_id, kingdomaward_id, rank`), so a cluster is never
split across batches and each batch is exactly ≤Limit *displayed* rows. Two-step:

1. **Page query** — selects the page's cluster keys: `GROUP BY mundane_id, kingdomaward_id, rank`
   over the scoped, non-deleted, active-recipient base, with **all filters in WHERE/HAVING** and
   the requested **ORDER BY**, `LIMIT :limit OFFSET :offset`. Per-cluster derived values needed
   for filter/sort are computed here (oldest date = `MIN(date_recommended)`; support count; an
   `AlreadyHas`/`snoozed`/`on_court` flag via the existing rank/officer/court subqueries). Sort
   keys: `recip` → persona; `award` → award_name, rank; `date` → `MIN(date_recommended)`; `supp`
   → support count.
2. **Hydrate** — fetch full rec fields for those clusters (the existing detailed SELECT, restricted
   to the page's clusters) + `GetSecondsForRecommendations`, then **reuse the existing PHP grouping
   + viewer-flag logic** to build the ≤Limit group rows. (Grouping logic is extracted from the
   controller into a shared helper so both `manage()` and this method use one implementation.)

`Total` = `COUNT(*)` over the distinct-cluster page query (filters applied, no limit) — cheap, no
hydration. `HasMore` = `Offset + count(Groups) < Total`.

**Eligibility → SQL:** `open` = not already-has AND not snoozed; `below` = below recommended rank
(`player_ka_rank < rank` / not already-has on a ladder); `ator` = already-has; `nonladder` =
`rank = 0` (non-ladder); `snoozed` = snoozed (snoozed-officer ids == current officer ids); `all`
= no eligibility restriction. These reuse the subquery expressions already in
`PlayerAwardRecommendations`.

**Caching:** replace the 300s full-set cache with a short per-(scope + filters + sort + offset)
cache for pages and a short cache for `Total`; existing rec-mutation cache-bust must also clear
these (bust by scope prefix). If clean per-key busting is impractical, use a short TTL (e.g. 30-60s)
and accept eventual consistency — actions already update the DOM optimistically.

### 2. Endpoint — `controller.Recommendations.php::rows()` (new action)

Route `Recommendations/rows/{kingdom|park}/{id}` (mirrors `manage`'s routing + `Court::canManage`
auth). Reads filter/sort/offset from the request, calls `PlayerAwardRecommendationsPage`, and
returns the batch as **rendered `<tr>` HTML** (via the shared row partial) plus `Total` and
`HasMore` (JSON envelope: `{ html, total, hasMore, offset }`). Returns `403` JSON if unauthorized.

### 3. Initial load — `manage()` change

Render the page shell (header, filter bar, empty `#rm-tbody`) + the **first batch** (offset 0,
default `Eligibility=open`, `Sort=date desc`) via the same `PlayerAwardRecommendationsPage` +
shared row partial. Pass initial `Total` / `HasMore` to the template. No full-set fetch.

### 4. Shared row partial

Extract the current `<tr class="rm-row">…</tr>` (+ its detail row) markup into
`template/revised-frontend/_rm_row.tpl` (or inline-rendered helper) consumed by both the initial
`manage()` render and the `rows()` endpoint, so row markup (including the `.ladder-rank` pill,
data-attributes, action buttons) stays DRY and identical.

### 5. Client rewrite — `Recommendations_manage.tpl` JS

- State object `{ search, eligibility, court, park, passlocal, sortKey, sortDir, offset, loading,
  hasMore, total }`, seeded from the initial server render.
- `rmFetch(reset)`: builds query params from state; on `reset` sets `offset=0` and clears
  `#rm-tbody`; GETs `Recommendations/rows/…`; appends returned `html`; updates `offset`, `hasMore`,
  `total`, the "Showing N of M" footer; toggles loading/end states. **De-dupes by cluster key**
  (`data-rec-id`/cluster) so an offset shift after a row removal can't duplicate/skip.
- **Filter/sort/search change → `rmFetch(reset=true)`** (search debounced ~250ms). Sort headers set
  `sortKey/sortDir` then reset-fetch.
- **Infinite scroll:** `IntersectionObserver` on a sentinel after the tbody → if `hasMore &&
  !loading` then `rmFetch(reset=false)` (append next 500).
- Existing delegated `#rm-tbody` action handlers unchanged (appended rows just work). Row-removing
  actions remove the row from the DOM as today and decrement the shown/total counters.
- Eligibility `<select>` options updated to the pill set; default value `open`.

## Out of scope

- Changing the recs-tab (profile) rendering — only the standalone Manager.
- Server-side rendering of the bulk-action bar logic (unchanged; operates on selected DOM rows).
- Virtualizing already-loaded rows (we cap fetch, not DOM; 500-row batches are acceptable DOM-wise).

## Risks / notes

- **Filter parity is the main risk:** the eligibility (already-has / snoozed) and court-status logic
  currently computed in PHP must be expressed correctly in SQL WHERE/HAVING so pagination counts are
  exact. This is where testing concentrates (compare server-filtered counts against the old
  client-filtered behavior on a known dataset).
- **Cluster integrity:** always paginate by distinct cluster; never `LIMIT` raw recs.
- **Offset drift after actions:** mitigated by client de-dupe on append (track loaded cluster keys).
- **Cache correctness:** ensure rec mutations invalidate the new page/count caches for the scope (or
  short TTL).

## Verification

- **Backend (curl/synthetic):** authenticated requests to `Recommendations/rows/park/76`:
  offset 0 → ≤500 groups + correct `Total`/`HasMore`; offset 500 → next batch (no overlap/gap);
  each eligibility value, court filter, park filter, passlocal, search, and each sort key+dir
  honored; `Total` matches a manual count.
- **Browser (Greenwood Keep, 1920 recs):** first 500 render fast; scrolling auto-loads the next
  500 with a loading indicator then an end-of-list state; changing eligibility/sort/search
  re-queries from the top; search finds a recipient that wasn't in the first batch; default view
  hides already-has + snoozed and is newest-first; snooze/dismiss/grant/add-to-court still work on
  appended rows; "Showing N of M" accurate; light + dark mode.
