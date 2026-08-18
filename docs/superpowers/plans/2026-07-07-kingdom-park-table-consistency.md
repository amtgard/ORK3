# Kingdom & Park Table Consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the clean data tables on the Kingdom & Park pages a consistent feature set — column sort, show-X-rows, pagination, live search, export CSV — by standardizing them on jQuery DataTables, and fix the Kingdom Players list sort bug.

**Architecture:** A shared `orkInitDataTable()` wrapper + `orkExportDataTableCsv()` helper in `revised.js` drive Parks/principality and Deleted-recs tables. The already-DataTables Recs tables gain only Show-X + a search box. A new `ork-datatables.css` themes the toolbar for dark mode. Events & Players full migration is deferred; the one exception is a minimal delegation fix so the Kingdom Players list becomes click-sortable.

**Tech Stack:** PHP plain-PHP `.tpl` templates (`extract()`+`include`, NOT Smarty), jQuery + DataTables 1.13.8 (CDN, already loaded), vanilla JS in `revised.js` (~19.8k lines, 4-space indent), CSS.

## Global Constraints

- **`.tpl` files are plain PHP** — use `<?php ?>`/`<?= ?>`, never Smarty `{$var}`/`{if}`.
- **No new JS plugins** — DataTables core only; CSV via the shared `orkExportDataTableCsv` helper (generalized from `recsExportCsv` at `revised.js:15567`).
- **Dark mode required** — selector `html[data-theme="dark"]`; walk every new surface in dark mode before "done".
- **FontAwesome 5.8.2 only** — e.g. `fa-file-csv` is valid FA5; do not use FA6-only names.
- **No native `title` tooltips / no native `confirm()`/`alert()`** — use existing in-product patterns.
- **CSV contract (every table):** data columns only (skip `<th class="no-export">`), current filtered + sorted view, **all rows** (not just the visible page).
- **Editing discipline:** `revised.js` is space-indented — the Edit tool is reliable. `.tpl` files are tab-indented — before a multi-line `.tpl` Edit, check `awk '/^\t/{c++}END{print c+0}' <file>`; if dirty, prefer a precise unique-anchor Edit or the Python `replace` fallback. **Never `git add -A`/`.`**; stage files explicitly. **Never stage `system/lib/ork3/class.Authorization.php`.**
- **Verification:** app runs via `docker-compose -f docker-compose.php8.yml up -d` at `http://localhost:19080/orkui/`. JS syntax gate: `node --check`. Template gate: `php -l`.

---

### Task 1: Shared infrastructure — CSS, CSV helper, init wrapper, tab-adjust

**Files:**
- Create: `orkui/template/revised-frontend/css/ork-datatables.css`
- Modify: `orkui/template/revised-frontend/script/revised.js` (add helpers near other globals, e.g. just before `recsExportCsv` at ~`:15566`)
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (add CSS `<link>` in the `<head>` styles block near `:113`)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (add CSS `<link>` near `:255`)

**Interfaces:**
- Produces: `window.orkInitDataTable($table, opts)` → DataTables API; `window.orkExportDataTableCsv(dt, filename)`; `window.orkAdjustDataTables($scope)`.
  - `opts`: `{ order: Array, columnDefs: Array, csvName: String, dt: Object (extra DataTables config) }`.

- [ ] **Step 1: Create `ork-datatables.css`**

```css
/* ork-datatables.css — shared toolbar theming for ORK DataTables (light + dark) */
.ork-dt-top { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:.5rem; }
.ork-dt-top .dataTables_length { margin:0; }
.ork-dt-top .dataTables_filter { margin:0 0 0 auto; }           /* search pushed right */
.ork-dt-top .dataTables_filter input { margin-left:.4rem; padding:.35rem .6rem;
    border:1px solid #cbd2dc; border-radius:6px; min-width:180px; }
.ork-dt-top .dataTables_length select { padding:.3rem 1.4rem .3rem .5rem; border:1px solid #cbd2dc; border-radius:6px; }
.ork-dt-bot { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; margin-top:.5rem; }
button.ork-dt-csv { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .75rem;
    background:#1f2d4d; color:#fff; border:1px solid #1f2d4d; border-radius:6px; cursor:pointer;
    font-size:.85rem; line-height:1; }
button.ork-dt-csv:hover { background:#2a3d68; }
button.ork-dt-csv:focus-visible { outline:2px solid #c9a23a; outline-offset:2px; }

/* Dark mode */
html[data-theme="dark"] .ork-dt-top .dataTables_filter input,
html[data-theme="dark"] .ork-dt-top .dataTables_length select {
    background:#1c2432; color:#e6e9ef; border-color:#3a4557; }
html[data-theme="dark"] .ork-dt-top .dataTables_filter input::placeholder { color:#8b95a7; }
html[data-theme="dark"] .dataTables_wrapper .dataTables_info,
html[data-theme="dark"] .dataTables_wrapper .dataTables_length,
html[data-theme="dark"] .dataTables_wrapper label { color:#c3cad6; }
html[data-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button { color:#c3cad6 !important; }
html[data-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button.current,
html[data-theme="dark"] .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    color:#fff !important; background:#2a3d68; border-color:#3a4557; }
html[data-theme="dark"] button.ork-dt-csv { background:#c9a23a; color:#1a1f29; border-color:#c9a23a; }
html[data-theme="dark"] button.ork-dt-csv:hover { background:#d8b24a; }
```

- [ ] **Step 2: Add the CSS `<link>` to both templates**

In `Kingdomnew_index.tpl` immediately AFTER the DataTables CSS at `:113`, and in `Parknew_index.tpl` immediately AFTER `:255`, add:
```html
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/css/ork-datatables.css?v=<?= filemtime(__DIR__ . '/css/ork-datatables.css') ?>">
```

- [ ] **Step 3: Add the shared JS helpers to `revised.js`**

Insert immediately before `window.recsExportCsv = function` (~`:15566`):
```js
// ---- Shared DataTables helpers (ORK standard toolbar + CSV) ----
// CSV: data columns only (skip <th class="no-export">), current filtered+sorted view, ALL rows.
window.orkExportDataTableCsv = function(dt, filename) {
    var keep = [], headers = [];
    dt.columns().every(function(i) {
        var $h = $(this.header());
        if ($h.hasClass('no-export')) return;
        keep.push(i);
        headers.push($h.text().trim());
    });
    var rows = [headers];
    dt.rows({ search: 'applied', order: 'applied' }).every(function() {
        var $tds = $(this.node()).find('td');
        rows.push(keep.map(function(ci) { return $tds.eq(ci).text().trim().replace(/\s+/g, ' '); }));
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
};

// Init a table as a DataTable with the ORK standard toolbar + an Export CSV button.
// opts: { order, columnDefs, csvName, dt (extra config merged last) }
window.orkInitDataTable = function($table, opts) {
    opts = opts || {};
    if (!$table || !$table.length) return null;
    if ($.fn.dataTable.isDataTable($table)) { $table.DataTable().destroy(); }
    var dt = $table.DataTable($.extend(true, {
        dom: "<'ork-dt-top'lf>rt<'ork-dt-bot'ip>",
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        pagingType: 'simple_numbers',
        autoWidth: false,
        scrollX: true,
        order: (opts.order || []),
        columnDefs: (opts.columnDefs || []),
        language: { searchPlaceholder: 'Search…', search: '', lengthMenu: 'Show _MENU_' }
    }, opts.dt || {}));
    var $top = $(dt.table().container()).find('.ork-dt-top');
    var $btn = $('<button type="button" class="ork-dt-csv"><i class="fas fa-file-csv"></i> Export CSV</button>');
    $btn.on('click', function() { window.orkExportDataTableCsv(dt, (opts.csvName || 'export') + '.csv'); });
    $top.append($btn);
    return dt;
};

// Re-measure columns for any DataTables inside a just-revealed container
// (fixes zero-width columns when a table was initialised while its tab was hidden).
window.orkAdjustDataTables = function($scope) {
    $($scope || document).find('table').each(function() {
        if ($.fn.dataTable.isDataTable(this)) {
            try { $(this).DataTable().columns.adjust(); } catch (e) {}
        }
    });
};
```

- [ ] **Step 4: Syntax gate**

Run: `node --check orkui/template/revised-frontend/script/revised.js`
Expected: no output (exit 0).

Run: `php -l orkui/template/revised-frontend/Kingdomnew_index.tpl && php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Browser smoke gate**

With Docker up, load `http://localhost:19080/orkui/` on a Kingdom page. In the console:
`typeof orkInitDataTable` → `"function"`; `typeof orkExportDataTableCsv` → `"function"`; `typeof orkAdjustDataTables` → `"function"`. No new console errors.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/css/ork-datatables.css \
        orkui/template/revised-frontend/script/revised.js \
        orkui/template/revised-frontend/Kingdomnew_index.tpl \
        orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: shared ORK DataTables toolbar + CSV helper (infra)"
```

---

### Task 2: Kingdom Parks + Principality tables → DataTables

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (parks table `:433`, principality tables `:541`)
- Modify: `orkui/template/revised-frontend/script/revised.js` (default-sort calls `:3162-3163`; parks/principality init; parks tab-show + list-toggle adjust)

**Interfaces:**
- Consumes: `orkInitDataTable`, `orkAdjustDataTables` (Task 1).

- [ ] **Step 1: Mark the edit-gear column non-data**

In `Kingdomnew_index.tpl` at the `#kn-parks-table` markup (`:433`), the manager-only edit-gear column: add `class="no-export"` to its `<th>`. (Column index is the last one, present only when manager — the init uses `targets:'_all'` guard below, so also give the gear `<td>`s no special data.) Add a `data-csvname` is not needed for the main table (set in init).

- [ ] **Step 2: Add a shared selection class + CSV name to principality tables**

In the principality `foreach` (`:541`), add class `kn-parks-dt` to each principality `<table>` and a `data-csvname="<?= htmlspecialchars($principality_name) ?> Parks"` attribute (use the loop's principality-name variable — confirm its exact name in the template). Also add `kn-parks-dt` to `#kn-parks-table`.

- [ ] **Step 3: Replace the bespoke default-sort with DataTables init**

In `revised.js`, replace the parks default-sort lines (`:3162-3163`):
```js
    knSortAsc($('#kn-parks-table'), 0, 'text');
    knPaginate($('#kn-parks-table'), 1);
```
with:
```js
    // Parks + principality tables → standard DataTables toolbar.
    $('#kn-parks-table').each(function() {
        var lastCol = this.tHead ? this.tHead.rows[0].cells.length - 1 : 0;
        var hasGear = $(this).find('thead th.no-export').length > 0;
        window.orkInitDataTable($(this), {
            order: [[0, 'asc']],
            csvName: 'Kingdom Parks',
            columnDefs: hasGear ? [{ targets: lastCol, orderable: false, searchable: false }] : []
        });
    });
    $('.kn-parks-dt').not('#kn-parks-table').each(function() {
        var hasGear = $(this).find('thead th.no-export').length > 0;
        var lastCol = this.tHead ? this.tHead.rows[0].cells.length - 1 : 0;
        window.orkInitDataTable($(this), {
            order: [[0, 'asc']],
            csvName: ($(this).data('csvname') || 'Parks'),
            columnDefs: hasGear ? [{ targets: lastCol, orderable: false, searchable: false }] : []
        });
    });
```
(If `#kn-parks-table` already carries `kn-parks-dt`, the `.not('#kn-parks-table')` filter prevents double-init.)

- [ ] **Step 4: Re-measure on Parks list-view reveal**

The Parks tab defaults to tile view; the list table is hidden until toggled. Find the parks tile/list toggle handler in `revised.js` (search `kn-parks` / list toggle) and, in the branch that shows the list table, add:
`window.orkAdjustDataTables($('#kn-tab-parks'));`
Also call it when the Parks tab itself is activated (add to the Kingdom tab-activation handler for `parks`).

- [ ] **Step 5: Syntax gate**

Run: `node --check orkui/template/revised-frontend/script/revised.js`
Run: `php -l orkui/template/revised-frontend/Kingdomnew_index.tpl`
Expected: clean.

- [ ] **Step 6: Browser verification (orchestrator drives Chrome)**

On a Kingdom page → Parks tab → switch to list view. Verify: header click sorts asc/desc; Show-X changes rows; pagination works; search filters; **Export CSV** downloads a file whose header row has the data columns and **no** gear column, and includes all filtered rows (test: search to ~2 rows, export, confirm only those rows). Repeat spot-check on one principality table. No zero-width columns.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/script/revised.js
git commit -m "Enhancement: Kingdom Parks + principality tables on standard DataTables toolbar"
```

---

### Task 3: Kingdom Recs — add Show-X + search box

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (`knInitRecsTab` config `:3162-3173`)

**Interfaces:**
- Consumes: nothing new. Preserves `knRecDT`, the `ext.search` predicate, filter bar, `knRecCsv`/`knRecPrint`.

- [ ] **Step 1: Add `dom` + `lengthMenu` to the existing config**

In the `window.knRecDT = $tbl.DataTable({ ... })` config, add these two keys (keep everything else — `order`, `columnDefs`, `pageLength`, `scrollX` — unchanged):
```js
            dom: "<'ork-dt-top'lf>rt<'ork-dt-bot'ip>",
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            language: { searchPlaceholder: 'Search…', search: '', lengthMenu: 'Show _MENU_' },
```

- [ ] **Step 2: Template gate**

Run: `php -l orkui/template/revised-frontend/Kingdomnew_index.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Browser verification**

Kingdom → Recommendations tab (triggers lazy load). Verify the existing filter bar + CSV/Print still work; the new **search box** filters and composes with the filter bar; **Show-X** changes page length; sort + pagination unchanged. Only ONE CSV control (the existing filter-bar one). No console errors on lazy load.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Enhancement: Kingdom Recs table gains show-X + search box"
```

---

### Task 4: Park Recs — add Show-X + search box

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (`pkRecDT` config `:3293-3303`)

- [ ] **Step 1: Add `dom` + `lengthMenu` to the existing config**

In the `window.pkRecDT = $('#pk-rec-table').DataTable({ ... })` config, add (keep `order`, `columnDefs`, `pageLength`, `scrollX`):
```js
                dom: "<'ork-dt-top'lf>rt<'ork-dt-bot'ip>",
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                language: { searchPlaceholder: 'Search…', search: '', lengthMenu: 'Show _MENU_' },
```

- [ ] **Step 2: Template gate**

Run: `php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: clean.

- [ ] **Step 3: Browser verification**

Park → Recommendations tab. Same checks as Task 3 Step 3 (park side). Confirm the existing `columns.adjust()` on tab-show (`revised.js:6543`) still fires so columns are correct.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: Park Recs table gains show-X + search box"
```

---

### Task 5: Kingdom & Park Deleted-recs → DataTables

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (deleted-recs IIFE `:15840`–`16054`: `renderRows`, `loadDeleted`, restore handler, search handler)
- Modify: `orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl` (`:144` table headers)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (`:1433` table headers)

**Interfaces:**
- Consumes: `orkInitDataTable`. Stores instance on `panel.__dt`.

- [ ] **Step 1: Mark headers for typing + export in both templates**

In both deleted-recs `<thead>` blocks (`Kingdomnew_recommendations_panel.tpl:146-156`, `Parknew_index.tpl:1435-1445`), replace the header row with typed headers and a non-exported actions column:
```html
<tr>
    <th>Player</th>
    <th>Award</th>
    <th data-dt-type="num">Rank</th>
    <th>Notes</th>
    <th data-dt-type="date">Date Rec.</th>
    <th>Recommended By</th>
    <th data-dt-type="date">Deleted At</th>
    <th>Deleted By</th>
    <th class="no-export"></th>
</tr>
```

- [ ] **Step 2: Emit `data-order` on date/rank cells in `renderRows`**

In `revised.js` `renderRows` (~`:15871-15881`), add `data-order` to the two date cells and rank cell so DataTables sorts by value. The date fields are `r.DateRecommended` (col 4) and `r.DeletedAt` (col 6, displayed via `fmtDt`). Change those three `<td>`s to:
```js
                + '<td data-order="' + (parseInt(r.Rank, 10) || 0) + '">' + rank + '</td>'   // col 2 Rank
                ...
                + '<td data-order="' + (Date.parse(r.DateRecommended) || 0) + '">' + escHtml(r.DateRecommended || '') + '</td>'  // col 4 Date Rec.
                ...
                + '<td data-order="' + (Date.parse(r.DeletedAt) || 0) + '">' + escHtml(fmtDt(r.DeletedAt)) + '</td>'            // col 6 Deleted At
```
(Keep the existing cell contents; only add the `data-order` attribute. Confirm exact current strings when editing.)

- [ ] **Step 3: Initialise the DataTable after rows render**

In `loadDeleted` success branch, immediately after `renderRows(tbody, recs);` and `if (wrap) wrap.style.display = '';`, add:
```js
                var $delTable = $(panel).find('.pk-deleted-recs-table');
                panel.__dt = window.orkInitDataTable($delTable, {
                    order: [[6, 'desc']],   // Deleted At, newest first
                    csvName: (panel.id === 'kn-deleted-recs' ? 'Kingdom' : 'Park') + ' Deleted Recommendations',
                    columnDefs: [{ targets: 8, orderable: false, searchable: false }]
                });
                window.orkAdjustDataTables($(panel));
```

- [ ] **Step 4: Remove the bespoke search (DataTables provides it)**

Delete the `searchWrap` reveal line in `loadDeleted` (`var searchWrap = ...; if (searchWrap) searchWrap.style.display = ...`) and the entire `searchInput` block in `wirePanel` (`:15951-15968`). Also remove the now-unused `data-search` attribute build in `renderRows` (the `searchKey` var + `data-search="..."`). The `.pk-deleted-recs-search-wrap` / `.pk-deleted-recs-no-match` markup can stay hidden (harmless) or be removed from templates — removing is cleaner but optional; if kept, ensure they remain `display:none`.

- [ ] **Step 5: Fix restore removal to use the DataTables API**

In the restore success handler (`:16002-16021`), replace the direct DOM removal + count logic. Change:
```js
                    var row = btn.closest('tr');
                    if (row) {
                        row.classList.add('pk-deleted-restored');
                        setTimeout(function () {
                            row.parentNode && row.parentNode.removeChild(row);
                            var countEl = panel.querySelector('.pk-deleted-recs-count');
                            var tbody   = panel.querySelector('tbody');
                            var remaining = tbody ? tbody.querySelectorAll('tr').length : 0;
                            ...
```
to (row may be off the current DataTables page, so resolve by rec id via the API):
```js
                    var recIdDone = btn.getAttribute('data-rec-id');
                    setTimeout(function () {
                        if (panel.__dt) {
                            panel.__dt.rows(function(i, data, node) {
                                return node.getAttribute('data-rec-id') === recIdDone;
                            }).remove().draw(false);
                        }
                        var remaining = panel.__dt ? panel.__dt.rows().count() : 0;
                        var countEl = panel.querySelector('.pk-deleted-recs-count');
                        if (countEl) {
                            countEl.textContent = remaining;
                            countEl.style.display = remaining > 0 ? '' : 'none';
                        }
                        if (remaining === 0) {
                            var wrap = panel.querySelector('.pk-deleted-recs-table-wrap');
                            var emptyEl = panel.querySelector('.pk-deleted-recs-empty');
                            if (wrap)    wrap.style.display = 'none';
                            if (emptyEl) emptyEl.style.display = '';
                        }
                    }, 500);
```
(The `btn.closest('tr')` visual `pk-deleted-restored` flash can be kept before the timeout if the row is on-page: `var row = btn.closest('tr'); if (row) row.classList.add('pk-deleted-restored');`.)

- [ ] **Step 6: Guard re-open re-init**

`loadDeleted` only runs once (`panel.dataset.loaded` guard) so DT inits once. Confirm the toggle's re-open path does not call `renderRows`/`loadDeleted` again (it checks `dataset.loaded !== '1'`). No change expected; just verify.

- [ ] **Step 7: Syntax + template gate**

Run: `node --check orkui/template/revised-frontend/script/revised.js`
Run: `php -l orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl && php -l orkui/template/revised-frontend/Parknew_index.tpl`
Expected: clean.

- [ ] **Step 8: Browser verification (needs a park/kingdom with deleted recs)**

Park → Recommendations → "Show Deleted Recommendations". Verify: table appears as a DataTable with search, Show-X, pagination, sort (Date columns sort chronologically, Rank numerically); **Export CSV** yields 8 data columns (no Restore column), all filtered rows. **Restore a row** → it disappears, the count decrements correctly, and when the last is restored the empty state shows. Repeat on Kingdom (lazy-loaded panel). Dark mode check.

- [ ] **Step 9: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js \
        orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl \
        orkui/template/revised-frontend/Parknew_index.tpl
git commit -m "Enhancement: Deleted-recs tables (Kingdom + Park) on standard DataTables toolbar"
```

---

### Task 6: Kingdom Players list — minimal click-sort fix

**Files:**
- Modify: `orkui/template/revised-frontend/script/revised.js` (kn-sortable binding `:3072-3095`)
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (built player table class `:2735`)

**Interfaces:**
- Consumes: existing `knPaginate`.

- [ ] **Step 1: Convert the load-time `.each` binding to delegation**

In `revised.js`, replace the block at `:3072-3095`:
```js
    // ---- Sortable tables ----
    $('.kn-sortable').each(function() {
        var $table = $(this);
        $table.find('thead th').on('click', function() {
            ... // existing body
        });
    });
```
with a single delegated handler (same sort logic, `$table` derived from the header):
```js
    // ---- Sortable tables (delegated so JS-injected tables like the Players
    //      list are covered too) ----
    $(document).on('click', '.kn-sortable thead th', function() {
        var $th = $(this);
        var $table = $th.closest('table');
        var colIndex = $th.index();
        var sortType = $th.data('sorttype') || 'text';
        var isAsc = !$th.hasClass('sort-asc');
        $table.find('thead th').removeClass('sort-asc sort-desc');
        $th.addClass(isAsc ? 'sort-asc' : 'sort-desc');
        var $tbody = $table.find('tbody');
        var rows = $tbody.find('tr').get();
        rows.sort(function(a, b) {
            var aVal = $(a).find('td').eq(colIndex).text().trim();
            var bVal = $(b).find('td').eq(colIndex).text().trim();
            var cmp = 0;
            if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
            else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
            else                          cmp = aVal.localeCompare(bVal);
            return isAsc ? cmp : -cmp;
        });
        $.each(rows, function(i, row) { $tbody.append(row); });
        knPaginate($table, 1);
    });
```

- [ ] **Step 2: Add `kn-sortable` to the built player table**

In `Kingdomnew_index.tpl:2735`, change the built table class:
```js
+ '<table class="kn-table kn-year-table"><thead><tr>'
```
to:
```js
+ '<table class="kn-table kn-year-table kn-sortable"><thead><tr>'
```

- [ ] **Step 3: Syntax + template gate**

Run: `node --check orkui/template/revised-frontend/script/revised.js`
Run: `php -l orkui/template/revised-frontend/Kingdomnew_index.tpl`
Expected: clean.

- [ ] **Step 4: Browser verification**

Kingdom → Players tab → list view. Click each header — rows sort asc/desc (6mo Sign-ins numerically, Last Visit chronologically). **Regression check:** Kingdom → Events tab (still `kn-sortable`, deferred) — headers still sort. (Parks now DataTables — covered by Task 2.)

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/script/revised.js orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Bugfix: Kingdom Players list headers now click-sortable (delegated kn-sortable)"
```

---

### Task 7: Full acceptance + dark-mode QA pass (orchestrator, Chrome)

**Files:** none (verification; fixes go back into the relevant task's files if issues found).

- [ ] **Step 1: Light-mode acceptance matrix**

For each in-scope table — Kingdom Parks, one principality table, Kingdom Recs, Park Recs, Kingdom Deleted-recs, Park Deleted-recs — confirm all five: sort (asc/desc, correct type), Show-X (10/25/50/100/All), pagination + "Showing X–Y of N", search filters, Export CSV (data columns only, all filtered+sorted rows). Confirm Kingdom Players list sorts (Task 6).

- [ ] **Step 2: Dark-mode pass**

Toggle dark mode (`html[data-theme="dark"]`). Walk every table's toolbar: search input, length select, Export CSV button, pagination, info row, group/zebra rows — all legible and on-brand. Fix `ork-datatables.css` as needed and re-commit under Task 1's file.

- [ ] **Step 3: Hidden-tab init**

Hard-reload directly onto each page; open each tab fresh; confirm no zero-width/misaligned columns on first reveal (parks list toggle, recs tabs, deleted-recs collapsible).

- [ ] **Step 4: Deferred-surface regression**

Confirm Events (Kingdom + Park) and Players (Park cards/list, active-only, search; Kingdom cards) behave exactly as before — no console errors, toggles/filters intact.

- [ ] **Step 5: Final commit (only if QA fixes were made)**

```bash
git add -p   # stage only the QA fix hunks
git commit -m "Polish: dark-mode + hidden-tab fixes for DataTables toolbar"
```

---

## Self-Review

**Spec coverage:**
- §2 in-scope Parks/principality → Task 2 ✓; Recs (KN/PK) → Tasks 3/4 ✓; Deleted-recs (KN/PK) → Task 5 ✓; Kingdom Players minimal sort → Task 6 ✓.
- §3 assets (ork-datatables.css, no plugins) → Task 1 ✓.
- §4 `orkInitDataTable` + `orkExportDataTableCsv` → Task 1 ✓.
- §5.1 gear no-export, tile/list adjust, remove default-sort → Task 2 ✓.
- §5.2 recs show-X + search, keep export/predicate → Tasks 3/4 ✓.
- §5.3 deleted-recs init-after-render, data-order, replace search, restore/count via API → Task 5 ✓.
- §5.4 delegation + class → Task 6 ✓.
- §6 dark mode / §7 risks (hidden-tab adjust, restore/count desync, no double CSV) → Tasks 1/2/5/7 ✓.
- §9 acceptance criteria → Task 7 ✓.

**Type/name consistency:** `orkInitDataTable`, `orkExportDataTableCsv`, `orkAdjustDataTables`, `panel.__dt`, `csvName`, `no-export`, `kn-parks-dt` — used identically across tasks.

**Placeholder scan:** no placeholders or unresolved TODOs; every code step contains final content.
