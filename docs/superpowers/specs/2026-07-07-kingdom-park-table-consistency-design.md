# Kingdom & Park Table Consistency (DataTables Standardization) — Design

**Date:** 2026-07-07
**Branch:** feature/07-07-misc-fixes (or a dedicated feature branch)
**Status:** Approved for planning

## 1. Goal

Bring the data tables on the Kingdom and Park pages toward a **consistent feature set and toolbar**:

1. Column sorting (click a header)
2. Show-X-rows (page-length selector)
3. Pagination
4. Live search box
5. Export CSV

The standard mechanism is **jQuery DataTables** (already loaded on both pages). We migrate the tables where DataTables cleanly does the job, and deliberately **defer** the two surfaces whose intricate custom behavior a naive migration would break.

## 2. Scope

### In scope — full DataTables treatment
| Table | File (anchor) | Today | Work |
|---|---|---|---|
| Kingdom **Parks** (list view) | `Kingdomnew_index.tpl:433` `#kn-parks-table` | custom `kn-sortable` + `knPaginate` | Migrate to DataTables via shared wrapper |
| Kingdom **Principality** tables | `Kingdomnew_index.tpl:541` (per-prinz) | custom `kn-sortable` | Migrate to DataTables (each) |
| Kingdom **Recs** | `Kingdomnew_recommendations_panel.tpl:58` `#kn-rec-table` | DataTables (lazy) + filter bar | Add Show-X + search + CSV |
| Park **Recs** | `Parknew_index.tpl:1354` `#pk-rec-table` | DataTables + filter bar | Add Show-X + search + CSV |
| Kingdom **Deleted-recs** | `Kingdomnew_recommendations_panel.tpl:144` `#kn-deleted-recs-tbody` | plain + bespoke search | Convert to DataTables |
| Park **Deleted-recs** | `Parknew_index.tpl:1433` `.pk-deleted-recs-table` | plain + bespoke search | Convert to DataTables |

### Minimal standalone fix (original motivating bug)
- **Kingdom Players list sortability** (`Kingdomnew_index.tpl:2735`, class `kn-year-table`): the per-year list tables carry `data-sorttype` header hints but lack the `kn-sortable` class and are built by JS *after* the load-time `.kn-sortable` binding runs, so headers never wire up. **Fix:** convert the Kingdom `.kn-sortable thead th` binding (`revised.js:3073`) to a single delegated handler and add the `kn-sortable` class to the built table markup. This is the *only* Players change in this pass — no DataTables/RowGroup migration.

### Deferred (untouched this pass — separate focused follow-up)
- **Events tables** (Kingdom & Park): list/calendar view toggle (localStorage + mobile-resize force), type-filter chips (`knToggleFilter`/`pkToggleFilter`), RSVP colspan action cells. Already click-sortable + paginated.
- **Players tables** (Kingdom & Park) full migration: cards/list view toggle, single search box driving both views, Park "active only" toggle, Kingdom async build + `content-visibility`, year `<details>` grouping (would need RowGroup). Park players already sort; Kingdom players sort is handled by the minimal fix above.

### Out of scope (as before)
- Admin-overlay tables (titles/awards/parks/sign-in links) and attendance sign-in tables — behind admin modals; revisit separately if desired.
- No server-side/controller changes. All work is client-side (templates + `revised.js` + one new CSS file).

## 3. Assets

DataTables core (`cdn.datatables.net/1.13.8`) is already loaded on both pages. **No new JS plugins are added** — not Buttons, not RowGroup.

- **CSV** uses a shared custom helper (`orkExportDataTableCsv`, §4) generalized from the existing, proven `recsExportCsv` (`revised.js:15567`). This gives one CSV codepath for every table and avoids the Buttons/JSZip dependency and its separate styling burden.
- **RowGroup** is not needed (Players deferred).

The only new asset is a stylesheet: `orkui/template/revised-frontend/css/ork-datatables.css` — dark-mode (`html[data-theme="dark"]`) + ORK brand theming for the DataTables toolbar chrome (search input, length select, Export CSV button, pagination, info row). Linked on both pages.

## 4. Shared helpers: `orkInitDataTable` + `orkExportDataTableCsv`

Two helpers in `revised.js` so every table shares identical config, toolbar layout, and CSV behavior. No DataTables plugins required.

```js
// Serialize a DataTables instance to CSV: data columns only (skip <th class="no-export">),
// current filtered + sorted view, ALL rows (search+order applied, no paging limit).
// Generalized from the existing recsExportCsv (revised.js:15567).
function orkExportDataTableCsv(dt, filename) {
    var keep = [];               // column indexes to export (exclude .no-export headers)
    var headers = [];
    dt.columns().every(function(i) {
        var $h = $(this.header());
        if ($h.hasClass('no-export')) return;
        keep.push(i);
        headers.push($h.text().trim());
    });
    var rows = [headers];
    dt.rows({ search: 'applied', order: 'applied' }).every(function() {
        var $tds = $(this.node()).find('td');
        rows.push(keep.map(function(ci) { return $tds.eq(ci).text().trim(); }));
    });
    var csv = rows.map(function(r) {
        return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\r\n');
    var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
}

// Init a table as a DataTable with the standard ORK toolbar + Export CSV button.
// $table: jQuery table. opts: { order, columnDefs, csvName, dt (extra config) }.
function orkInitDataTable($table, opts) {
    opts = opts || {};
    if ($.fn.dataTable.isDataTable($table)) { $table.DataTable().destroy(); }
    var dt = $table.DataTable($.extend(true, {
        dom: "<'ork-dt-top'lf>rt<'ork-dt-bot'ip>", // length, filter / table / info, pagination
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        pagingType: 'simple_numbers',
        autoWidth: false,
        scrollX: true,
        order: (opts.order || []),
        columnDefs: (opts.columnDefs || []),
        language: { searchPlaceholder: 'Search…', search: '', lengthMenu: 'Show _MENU_' },
    }, opts.dt || {}));
    // Inject the Export CSV button into the toolbar (no Buttons plugin).
    var $top = $(dt.table().container()).find('.ork-dt-top');
    var $btn = $('<button type="button" class="ork-dt-csv"><i class="fas fa-file-csv"></i> Export CSV</button>');
    $btn.on('click', function() { orkExportDataTableCsv(dt, (opts.csvName || 'export') + '.csv'); });
    $top.append($btn);
    return dt;
}
```

- **Action/non-data columns** (edit gear, RSVP, row-action buttons) get `<th class="no-export">` **and** `columnDefs` markers for `orderable:false, searchable:false`. The `no-export` header class excludes them from CSV; non-orderable/non-searchable keeps them out of sort + search.
- **CSV semantics:** `rows({search:'applied', order:'applied'})` with no paging modifier → the current filtered + sorted view, **all rows** (not just the visible page). Data columns only.
- **Recs tables** keep their existing `recsExportCsv`/`recsExportPrint` buttons (already conform) — they are *not* re-inited through `orkInitDataTable`; they only gain Show-X + a search box (see §5.2).
- **Date/number sort reliability:** date cells emit a `data-order` attribute (epoch ms) so DataTables sorts by real value regardless of display text. Numeric cells rely on DataTables auto-detection (or `data-order` when the display text is not a bare number). Existing `data-sortval` attributes are re-emitted as `data-order`.

### Hidden-tab initialization
DataTables mis-measures column widths when initialized inside a `display:none` tab. Every table calls `.columns.adjust()` on its tab's first show, reusing the pattern already at `revised.js:6543` (Park recs re-measure on tab show). The wrapper is init-once; a small registry maps tab id → DataTables instance(s) so the tab-activation handlers can adjust the right table(s).

## 5. Per-table detail

### 5.1 Kingdom Parks (`#kn-parks-table`) + principality tables
- Init each via `orkInitDataTable`. Default order: Park name asc (col 0).
- Edit-gear column (manager view): `no-export`, `orderable:false`, `searchable:false`.
- Numeric columns (Avg/Wk, Avg/Mo, Total Players, Total Members): ensure sortable as numbers (`data-order` where the cell shows non-numeric decoration).
- **Tile/list toggle preserved** — DataTables lives in the list container; toggling to list must `.columns.adjust()`.
- Remove `#kn-parks-table` from the bespoke `knSortAsc`/`knPaginate` default-sort calls (`revised.js:3162-3163`) to avoid double-management.
- CSV filename: `Kingdom Parks`.

### 5.2 Kingdom & Park Recs (`#kn-rec-table`, `#pk-rec-table`)
- Already DataTables, already have working CSV + Print buttons (`knRecCsv`/`pkRecCsv` → `recsExportCsv`, which exports data columns only + all `search:'applied'` rows) and a filter bar. **Do NOT re-init through `orkInitDataTable`** (that would add a second CSV button and risk the filter predicate/lazy-load).
- **Only add the two missing features** to their existing inline `.DataTable({...})` config: Show-X and search box — by adding `dom: "<'ork-dt-top'lf>rt<'ork-dt-bot'ip>"` and `lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'All']]` to the config. Keep `order`, `columnDefs`, `scrollX`, the `ext.search` predicate, the filter bar, and the CSV/Print buttons exactly as they are.
- `ork-datatables.css` styles the added search/length chrome to match the other tables.
- No filename change (existing `recs-<slug>.csv`).

### 5.3 Kingdom & Park Deleted-recs
- Both tables are structurally identical (`.pk-deleted-recs-table`, 9 columns: Player, Award, Rank, Notes, Date Rec., Recommended By, Deleted At, Deleted By, [actions]). Populated by the shared `wirePanel`/`loadDeleted`/`renderRows` IIFE (`revised.js:15886`–`16054`).
- **Init the DataTable *after* rows are injected** — inside `loadDeleted`'s success branch, right after `renderRows(tbody, recs)` and showing the wrap. Store the instance on the panel (e.g. `panel.__dt`).
- Column types via `columnDefs`: Rank (col 2) numeric; Date Rec. (col 4) + Deleted At (col 6) dates — the row-builder (`renderRows`) must add `data-order="<epoch ms>"` to those two cells so DT sorts by value. Actions column (col 8): `<th class="no-export">` + `orderable:false, searchable:false`.
- **Replace the bespoke search** (`.pk-deleted-recs-search` + its `input` handler) with the DataTables search box; remove the now-dead `.pk-deleted-recs-no-match` / `searchWrap` visibility code and the `data-search` attribute path. Keep the collapsible toggle.
- **Integration fixes required** because DataTables now owns the tbody DOM (paging removes off-page `<tr>`s):
  - **Restore removal** must use the API: replace `row.parentNode.removeChild(row)` with `panel.__dt.row(row).remove().draw(false)`.
  - **Count** must use the API: replace `tbody.querySelectorAll('tr').length` with `panel.__dt.rows().count()`.
  - When the last row is restored (count 0), destroy/empty the DT and show the empty state.
- CSV filenames: `Kingdom Deleted Recommendations`, `Park Deleted Recommendations`.

### 5.4 Kingdom Players sort (minimal, standalone)
- `revised.js:3073`: replace the direct `$('.kn-sortable').each(... thead th .on('click'))` binding with a single delegated handler:
  `$(document).on('click', '.kn-sortable thead th', function(){ ... })`, deriving `$table = $(this).closest('table')`. Logic otherwise identical (colIndex, `data-sorttype`, asc/desc toggle, sort, `knPaginate`).
- `Kingdomnew_index.tpl:2735`: add `kn-sortable` to the built table's class list (`kn-table kn-year-table kn-sortable`).
- This keeps `knPaginate` behavior for the (potentially large) player tables. No DataTables here.
- **Note:** after 5.1, Parks **and** principality tables both migrate to DataTables, so they no longer use the `kn-sortable` handler. The remaining consumers of the delegated `kn-sortable` handler are the **deferred Kingdom Events table** and the **Kingdom Players list** (this minimal fix). Both must continue to work under delegation — verified in the QA pass. (Park Events uses the separate `pk-table` handler, untouched.)

## 6. Dark mode & styling (`ork-datatables.css`)

Theme all DataTables chrome for both light and `html[data-theme="dark"]`:
- Search input + length `<select>`: background, border, text, placeholder color.
- `Export CSV` button (`.ork-dt-csv`) + `.dt-button`: ORK brand (navy/gold restrained), hover/focus, dark variant.
- Pagination buttons + current-page highlight; info row (`Showing X–Y of N`) text color.
- Recs tables already theme `.pk-recs-table` — do not regress; layer additively.
- No native `title` tooltips (use `data-tip` if any hint is needed). FontAwesome 5.8.2 icon names only.

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Hidden-tab column mis-measure | `.columns.adjust()` on tab first-show (existing pattern `revised.js:6543`). |
| Delegation change regresses existing kn-sortable tables | QA every kn-sortable table (parks→now DT, principality, and confirm no other consumers) in-browser. |
| Deleted-recs DT init before rows exist | Init after JS injection / add rows via API. |
| CSV leaks action columns | `no-export` class + `columns: ':not(.no-export)'`; verify exported header row. |
| Double sort/paginate management on Parks | Remove `#kn-parks-table` from `knSortAsc`/`knPaginate` default calls. |
| Dark-mode DT chrome unstyled | Dedicated `ork-datatables.css`; walk dark-mode checklist before done. |
| Deleted-recs restore/count desync after DT owns tbody | Use `dt.row(node).remove().draw(false)` for restore and `dt.rows().count()` for the count (§5.3). |
| Second CSV button on recs | Recs are NOT re-inited through the wrapper — they keep their existing CSV/Print and only gain Show-X + search (§5.2). |

## 8. Execution

Subagent-driven, in dependency order:
1. **Shared infra** — add CDN assets on both pages, `orkInitDataTable` wrapper, `ork-datatables.css`, tab-show adjust registry. (Serial; everything depends on it.)
2. **Kingdom surfaces** — Parks + principality (5.1), KN Recs (5.2), KN Deleted-recs (5.3), KN Players minimal sort (5.4). Independent per-surface → parallel agents.
3. **Park surfaces** — PK Recs (5.2), PK Deleted-recs (5.3). Parallel agents.
4. **QA / verification** — Chrome pass confirming all five features on every in-scope table, light + dark mode, hidden-tab init, CSV contents (data columns only, all filtered/sorted rows). Verify deferred Events/Players are unchanged and still function.

## 9. Acceptance criteria

For every **in-scope** table:
- [ ] Clicking any data-column header sorts asc/desc (dates/numbers sort by value).
- [ ] Show-X selector changes page length (10/25/50/100/All).
- [ ] Pagination controls present and correct; "Showing X–Y of N".
- [ ] Live search filters rows.
- [ ] Export CSV downloads **data columns only**, **all rows** of the **current filtered+sorted** view (not just the visible page).
- [ ] Correct in light and dark mode.
- [ ] Correct after being revealed from a hidden tab (no zero-width columns).
- [ ] Kingdom Players list headers sort (minimal fix).
- [ ] Deferred Events & Players tables behave exactly as before (no regressions).
```
