# Kingdom page: fold Principalities into the Parks tab

Date: 2026-06-03
Status: Approved (design), ready for implementation

## Goal
On the Kingdom profile page (Kingdomnew), remove the standalone **Principalities** tab and present principalities inside the **Parks** tab: below the kingdom's own parks, show each principality (heraldry + name + external-link icon linking to the Pr profile) with that principality's parks beneath it — in both the tile view and the list/table view. On the kingdom **Map**, draw principality parks with a distinct related pin color + a legend.

## Key files
- Controller: `orkui/controller/controller.Kingdom.php` — `profile()` (line 373); data loads at 388-416.
- Template: `orkui/template/revised-frontend/Kingdomnew_index.tpl`
- JS: `orkui/template/revised-frontend/script/revised.js` (+ inline `<script>` in the template, averages fill at ~2030-2086)
- CSS: `orkui/template/revised-frontend/style/revised.css`

## Existing mechanics reused
- `park_averages_json/{id}` (controller line 63) is scoped to a single kingdom id and works for **any** kingdom row — a principality is a kingdom row, so calling it per principality returns full Avg/Wk · Avg/Mo · Total Players · Total Members for that Pr's parks. **No new stats backend.**
- Kingdom park tiles/rows carry `data-park-id` + spinner placeholders (`.kn-avgwk-tile/.kn-avgmo-tile/.kn-avgwk-row/.kn-avgmo-row/.kn-tp-row/.kn-tm-row`); the inline averages fill (template ~2030-2086) populates them. Pr park tiles/rows use identical markup.
- Map locations are built server-data → template loop (`$knMapLocations`, template ~45-62) → `KnConfig.mapLocations` → `knInitMap` marker loop in revised.js (~2571-2616), current pin: bg `#8B1A1A`, border `#B8860B`, glyph `#FFD700`.
- `knSetParksView('tiles'|'list')` (revised.js ~2818) toggles `#kn-parks-tiles` vs `#kn-parks-list-view`.

## Integration contract (all surfaces build against this)

### Backend → template data (added in `profile()`)
- `$this->data['principality_parks']`: ordered array, one entry per principality that has ≥1 park OR always (render even with 0 parks is acceptable; skip principalities with 0 parks). Each entry:
  ```php
  [ 'KingdomId' => int, 'Name' => string,
    'parks' => array of get_park_summary(prId)['KingdomParkAveragesSummary'] rows
               (fields: ParkId, ParkName, Title, HasHeraldry, AttendanceCount) ]
  ```
- `$this->data['prinz_map_parks']`: array, one entry per principality:
  ```php
  [ 'KingdomId' => int, 'Name' => string,
    'parks' => GetParks(prId)['Parks'] filtered to Active=='Active'
               (same row shape as $map_parks: Name, Location, City, Province, HasHeraldry, Directions, Description, ParkId) ]
  ```
- Source: iterate `$this->data['principalities']['Principalities']` (fields `KingdomId`, `Name`). Only when `!$IsPrinz`. Cost scales with # principalities (usually 0).

### Template → JS DOM contract
- Tile view: container `#kn-prinz-tile-sections` placed immediately AFTER `#kn-parks-tiles`. Inside, per principality a `<section class="kn-prinz-section" data-prinz-id="{prId}">` with:
  - header `<a class="kn-prinz-head" href="{UIR}Kingdom/profile/{prId}"> <img class="kn-prinz-heraldry"> <span class="kn-prinz-name">{Name}</span> <i class="fas fa-external-link-alt kn-prinz-extlink"></i> </a>`
  - a `.kn-park-tiles` grid of `.kn-park-tile` anchors identical to kingdom park tiles (data-park-id, heraldry img, name, type, two `.kn-park-tile-stat` blocks with `.kn-avgwk-tile`/`.kn-avgmo-tile` spinners).
- List view: container `#kn-prinz-tables` placed immediately AFTER `#kn-parks-list-view`. Inside, per principality a `<div class="kn-prinz-table-wrap" data-prinz-id="{prId}">` with the same header anchor, then a `<table class="kn-table kn-sortable">` mirroring the kingdom table columns (Park, Type, Avg/Wk, Avg/Mo, Total Players, Total Members — NO edit column), rows with `data-park-id` + the `.kn-avgwk-row/.kn-avgmo-row/.kn-tp-row/.kn-tm-row` cells, and a `<tfoot>` total row with per-section ids: `#kn-prinz-{prId}-avgwk`, `-avgmo`, `-tp`, `-tm`.
- Both Pr containers render whenever `principality_parks` is non-empty, INDEPENDENT of the kingdom's own `parkList` count (handle the kingdom-has-no-parks case).
- Heraldry URL for a Pr (kingdom heraldry): `HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prId))` with `onerror` fallback to `HTTP_KINGDOM_HERALDRY.'0000.jpg'`.
- Park heraldry/type/link identical to kingdom park tiles (`Park/profile/{ParkId}`, `%05d` heraldry, `00000.jpg` fallback).
- Remove the Principalities tab `<li data-kntab="principalities">` (~266-271) and its panel `#kn-tab-principalities` (~592-607).

### Template map data
- Extend the `$knMapLocations` builder (~45-62): after the kingdom loop, loop `$prinz_map_parks`; for each park build the same location object PLUS `'prinz' => true`, `'prName' => {Name}`, `'prId' => {KingdomId}`.

### JS contract
- Refactor the inline averages fill into `function knFillAverages(data, opts)` where `opts = { scope: Element|null (default document), footer: {avgwk, avgmo, tp, tm} element-ids or null, statCards: bool }`. Kingdom call: `scope=null, footer={kn-total-*}, statCards=true` (unchanged behavior). Per-Pr call: fetch `park_averages_json/{prId}`, `scope = the section/table-wrap for that prId, footer = {kn-prinz-{prId}-*}, statCards=false`. Scope the tile/row `querySelector` lookups to `scope` so Pr fills never touch kingdom elements (park ids are globally unique, but scoping keeps totals correct).
- Drive per-Pr fetches from a server-emitted list `KnConfig.principalityIds = [prId, ...]` (add to the KnConfig block ~928).
- `knSetParksView`: when showing tiles, also `show(#kn-prinz-tile-sections)` + `hide(#kn-prinz-tables)`; when list, the inverse.
- `knInitMap` marker loop: if `loc.prinz`, PinElement bg `#2C5F8B` (steel blue), keep border `#B8860B` + glyph `#FFD700`. Add a small map legend (two swatches: Kingdom = red, Principality = steel blue) into the map sidebar/overlay. In `knRenderMapSidebar`, if `loc.prinz`, show a line like "Principality: {prName}".

### CSS contract (dark-mode aware — use existing theme tokens; verify in dark mode)
Add styles for: `.kn-prinz-section`, `.kn-prinz-head` (flex, heraldry 28-32px, name weight, hover), `.kn-prinz-heraldry`, `.kn-prinz-name`, `.kn-prinz-extlink` (muted, hints external nav), `.kn-prinz-table-wrap`, section spacing/divider, and a `— PRINCIPALITIES —` group label. Mirror existing `.kn-park-tile`/`.kn-table` look. No native tooltips; if a tip is wanted use the `data-tip` pattern.

## Edge cases
- Kingdom is itself a principality (`$IsPrinz`) → no Pr section/data (already gated).
- Kingdom with principalities but no own parks → Pr sections still render; kingdom "No parks found" handled.
- A principality with 0 active parks → skip it (don't render an empty section).
- `data-park-id` uniqueness across kingdom + Prs holds (park ids global) — scope fills anyway.

## Verification
Local kingdom 6 → principality 29 (has parks). Load `Kingdom/profile/6` in Chrome (after implementation):
1. No standalone Principalities tab; Parks tab shows kingdom parks then a Principalities area.
2. Tile view: grouped Pr section(s) with heraldry + name + ext-link icon, Pr park tiles, stats fill in.
3. List view: separate Pr table(s) with own totals footer; stats fill.
4. Toggle tiles/list switches both kingdom and Pr blocks.
5. Map: Pr parks render as steel-blue pins + legend; sidebar shows Pr name; link-out navigates to the Pr profile.
6. Dark-mode walkthrough of all new surfaces.
