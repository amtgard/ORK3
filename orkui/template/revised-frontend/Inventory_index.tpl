<?php
/* -----------------------------------------------------------------
   Inventory — officer-only per-org durable-goods register (Kingdom/Park).
   Plain-PHP template (extract()+include). All CSS/JS inlined, `inv-`
   prefixed. Dark-mode via global --ork-* vars + html[data-theme=dark].
   Mirrors Treasury_index.tpl. Variables (from controller.Inventory::render):
     $owner_type, $owner_id, $categories, $removal_reasons, $conditions,
     $org_name, $kingdom_id, $summary, $items
   ----------------------------------------------------------------- */
$uir      = UIR;
$ajaxBase = $uir . 'InventoryAjax/handle/' . $owner_type . '/' . $owner_id . '/';
$fmt      = fn($n) => '$' . number_format((float)$n, 2);
// Condition vocabulary is owned by the domain (Inventory::$CONDITIONS, passed in
// as $conditions); label them here rather than keeping a second hardcoded list.
$condLabels = [];
foreach ((array)($conditions ?? []) as $c) {
    $condLabels[$c] = ucwords(str_replace('_', ' ', (string)$c));
}

// Defensive defaults (controller supplies these, but render must never fatal).
$summary = is_array($summary ?? null) ? $summary : [];
$summary += ['TotalValue' => 0, 'TotalUnits' => 0, 'LineItems' => 0, 'NeedsRepair' => 0, 'ByCategory' => [], 'ByCondition' => []];
$items   = is_array($items ?? null) ? $items : ['Rows' => [], 'Total' => 0];
$categories      = (array)($categories ?? []);
$removal_reasons = (array)($removal_reasons ?? []);
// Treasury vocab for "record this sale as income" on Remove (sold).
$treasury_income_categories = (array)($treasury_income_categories ?? []);
if (!$treasury_income_categories) { $treasury_income_categories = ['income_other' => 'Other Income']; }
$treasury_methods = (array)($treasury_methods ?? []);
if (!$treasury_methods) { $treasury_methods = ['cash' => 'Cash', 'check' => 'Check', 'digital' => 'Digital']; }

// Header scope chip — links back to the org this register belongs to.
$isPark          = ($owner_type === 'park');
$ownerLabel      = $isPark ? 'Park' : 'Kingdom';
$ownerLabelLower = $isPark ? 'park' : 'kingdom';
$scopeIcon       = $isPark ? 'fa-tree' : 'fa-chess-rook';
$scopeLink       = $uir . ($isPark ? 'Park/profile/' : 'Kingdom/profile/') . (int)$owner_id;
// Sibling officer tool for the same org — Treasury and Inventory are a pair.
$siblingLink     = $uir . 'Treasury/' . $owner_type . '/' . (int)$owner_id;
$org_name        = (string)($org_name ?? '');
?>
<script src="https://code.highcharts.com/highcharts.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(__DIR__ . '/../default/style/reports.css') ?>">
<script src="<?= HTTP_TEMPLATE ?>default/script/rp-tooltip.js?v=<?= filemtime(__DIR__ . '/../default/script/rp-tooltip.js') ?>"></script>
<style>
/* =====================================================
   Inventory tool — .inv-* additions on top of the shared
   report chrome (.rp-*, reports.css). Only what the shared
   sheet doesn't provide lives here: the item table, the
   history/log views, and Inventory-only deltas on the shared
   modal and form controls.
   Light defaults; dark via --ork-* + overrides.
   ===================================================== */
/* Full-bleed: the register is a wide table and wants every pixel. */
.inv-root { width: 100%; margin: 0; padding: 16px 16px 48px; box-sizing: border-box; color: var(--ork-text); }

/* Needs-Repair reads as a warning on the shared stat card */
#inv-repair-card .rp-stat-number { color: var(--rp-warning-text); }

/* Charts sit two-up under the stat row (the shared row stacks by default) */
.rp-charts-row.inv-charts { flex-direction: row; flex-wrap: wrap; }
.inv-chart-empty { height: 100%; display: flex; align-items: center; justify-content: center; color: var(--ork-text-muted); font-size: 0.85rem; }

/* Item table — the .rp-table-area wrapper already supplies the card
   surface, so the table itself stays flat (no second border/background). */
.inv-table { width: 100%; border-collapse: collapse; }
.inv-table thead th {
    text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: .03em;
    color: var(--ork-text-muted); background: var(--ork-bg-secondary);
    padding: 9px 12px; border-bottom: 1px solid var(--ork-border); white-space: nowrap;
}
.inv-table thead th[data-sort] { cursor: pointer; user-select: none; }
.inv-table thead th[data-sort]:hover { color: var(--ork-text); }
.inv-table thead th .inv-sort-ind { font-size: 0.7rem; opacity: .7; }
.inv-table tbody td { padding: 9px 12px; font-size: 0.86rem; color: var(--ork-text); border-bottom: 1px solid var(--ork-border); vertical-align: top; }
.inv-table tbody tr:last-child td { border-bottom: none; }
.inv-table .inv-num { text-align: right; font-variant-numeric: tabular-nums; }
.inv-table .inv-actions { text-align: right; white-space: nowrap; }
.inv-table tbody td:last-child { text-align: right; }

/* Empty state */
.inv-empty { padding: 28px 16px; text-align: center; color: var(--ork-text-muted); font-style: italic; }

/* Condition badge ("Needs Repair") + removed-reason badge (base pill: .rp-badge) */
.inv-badge-repair { background: var(--ork-badge-orange-bg); color: var(--ork-badge-orange-text); }
.inv-badge-reason { background: var(--ork-badge-gray-bg); color: var(--ork-badge-gray-text); }
.inv-reason-note { display: block; color: var(--ork-text-muted); font-size: 0.76rem; margin-top: 3px; }

/* Shared modal/field/segmented rules live in reports.css; Inventory-only deltas: */
#inv-item-overlay .rp-modal { max-width: 560px; }
.rp-field-row > .rp-field.inv-field-grow { flex: 2 1 0; }
.inv-label-opt { font-weight: 400; text-transform: none; letter-spacing: 0; color: var(--ork-text-muted); }
.inv-modal-intro { margin: 0 0 14px; color: var(--ork-text-muted); font-size: 0.85rem; }
/* Condition segmented control wraps and runs a touch tighter than Treasury's */
.rp-seg { flex-wrap: wrap; }
.rp-seg button { padding: 7px 13px; font-size: 0.82rem; }

/* Held-by player-search — canonical kn-ac-results dropdown. revised.css (which
   carries the shared kn-ac-* rules) is not loaded on this page, so they are defined
   here. Inside the scrolling modal it is position:fixed and placed by
   tnFixedAcPosition() so the modal's overflow never clips it. */
#inv-item-overlay .kn-ac-results {
    position: fixed; z-index: 10001;
    margin-top: 0; max-height: 220px; overflow-y: auto; display: none;
    background: var(--ork-card-bg); border: 1px solid var(--ork-input-border);
    border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,.28);
}
#inv-item-overlay .kn-ac-results.kn-ac-open { display: block; }
#inv-item-overlay .kn-ac-item { padding: 8px 11px; font-size: 0.84rem; cursor: pointer; color: var(--ork-text); border-bottom: 1px solid var(--ork-border); }
#inv-item-overlay .kn-ac-item:last-child { border-bottom: none; }
#inv-item-overlay .kn-ac-item:hover, #inv-item-overlay .kn-ac-item:focus, #inv-item-overlay .kn-ac-item.kn-ac-focused { background: rgba(99,102,241,.16); outline: none; }
#inv-item-overlay .kn-ac-item.inv-ac-empty { color: var(--ork-text-muted); cursor: default; }
#inv-item-overlay .kn-ac-item .inv-ac-meta { color: var(--ork-text-muted); font-size: 0.72rem; }

/* Header actions wrap instead of overflowing once the row gets long */
.inv-root .rp-header-actions { flex-wrap: wrap; flex-shrink: 1; justify-content: flex-end; }

/* Field hint + first-run empty state */
.inv-hint { font-size: 0.76rem; color: var(--ork-text-muted); margin-top: 5px; }
.inv-empty.inv-empty-first { font-style: normal; }
.inv-empty-first p { margin: 0 0 12px; }

/* Row "Actions" menu — in-page popover, placed with position:fixed. Desktop shows the
   row's primary action inline beside it; mobile collapses everything into the menu. */
.inv-act-menu {
    position: fixed; z-index: 10002; min-width: 190px; padding: 4px;
    display: flex; flex-direction: column;
    background: var(--ork-card-bg); border: 1px solid var(--ork-border);
    border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.28);
}
.inv-act-menu button {
    display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 10px;
    background: none; border: none; border-radius: 5px; text-align: left;
    font-size: 0.86rem; color: var(--ork-text); cursor: pointer;
}
.inv-act-menu button:hover, .inv-act-menu button:focus { background: var(--ork-bg-secondary); outline: none; }
.inv-act-menu button.inv-act-danger { color: var(--rp-danger-text); }

/* History + change log */
.rp-modal.inv-modal-wide { max-width: 720px; }
.inv-hist-entry { padding: 10px 0; border-bottom: 1px solid var(--ork-border); }
.inv-hist-entry:last-child { border-bottom: none; }
.inv-hist-meta { font-size: 0.8rem; color: var(--ork-text-muted); }
.inv-hist-meta strong { color: var(--ork-text); }
.inv-hist-change { font-size: 0.84rem; margin-top: 4px; overflow-wrap: anywhere; }
.inv-hist-field { color: var(--ork-text-secondary); font-weight: 600; }
.inv-hist-old { text-decoration: line-through; color: var(--ork-text-muted); }
.inv-hist-note {
    margin-top: 6px; padding: 6px 9px; font-size: 0.82rem; overflow-wrap: anywhere;
    border-left: 3px solid var(--ork-border-dark); background: var(--ork-bg-secondary);
}
.inv-badge-act-create   { background: var(--ork-badge-green-bg);  color: var(--ork-badge-green-text); }
.inv-badge-act-edit     { background: var(--ork-badge-blue-bg);   color: var(--ork-badge-blue-text); }
.inv-badge-act-remove   { background: var(--ork-badge-orange-bg); color: var(--ork-badge-orange-text); }
.inv-badge-act-restore  { background: var(--ork-badge-blue-bg);   color: var(--ork-badge-blue-text); }
.inv-badge-act-delete   { background: var(--ork-badge-red-bg);    color: var(--ork-badge-red-text); }
.inv-badge-act-undelete { background: var(--ork-badge-purple-bg); color: var(--ork-badge-purple-text); }
.inv-badge-act-split    { background: var(--ork-badge-gray-bg);   color: var(--ork-badge-gray-text); }
.inv-badge-act-verify   { background: var(--ork-badge-green-bg);  color: var(--ork-badge-green-text); }
.inv-log-toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
.inv-log-toolbar .rp-select { width: auto; }
.inv-log-toolbar .rp-label { margin: 0; }
.inv-log-row {
    display: block; width: 100%; padding: 9px 4px; text-align: left; font: inherit; cursor: pointer;
    background: none; border: none; border-bottom: 1px solid var(--ork-border); color: var(--ork-text);
}
.inv-log-row:hover, .inv-log-row:focus { background: var(--ork-bg-secondary); outline: none; }
.inv-log-row .inv-log-item { font-weight: 600; font-size: 0.88rem; overflow-wrap: anywhere; }

/* Table toolbar: status toggle (#27) + bulk verify (#18); filter chips (#13); view banner (#27) */
.inv-table-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.inv-status-seg button .inv-seg-count { font-weight: 400; opacity: .8; }
.inv-status-seg button:focus-visible { outline: 2px solid var(--rp-focus); outline-offset: -2px; }
.inv-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.inv-chips:empty { display: none; }
.inv-chip {
    display: inline-flex; align-items: center; gap: 6px; max-width: 100%; box-sizing: border-box;
    padding: 3px 4px 3px 10px; border-radius: 999px; font-size: 0.8rem;
    background: var(--ork-bg-secondary); border: 1px solid var(--ork-border); color: var(--ork-text);
}
.inv-chip-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inv-chip-text b { color: var(--ork-text-muted); font-weight: 600; }
.inv-chip button {
    flex: 0 0 auto; width: 22px; height: 22px; border-radius: 50%; border: none; cursor: pointer;
    background: transparent; color: var(--ork-text-muted); font-size: 1rem; line-height: 1;
}
.inv-chip button:hover, .inv-chip button:focus-visible { background: var(--ork-bg-tertiary); color: var(--ork-text); outline: none; }
.inv-view-banner {
    display: flex; gap: 8px; align-items: flex-start; margin-bottom: 10px; padding: 8px 12px;
    font-size: 0.84rem; border-radius: 6px; color: var(--ork-text-secondary);
    background: var(--ork-bg-secondary); border-left: 3px solid var(--ork-border-dark);
}

/* Held By cell: click filters to the holder; a small icon links to the linked player (#13) */
.inv-holder-btn {
    background: none; border: none; padding: 0; font: inherit; color: var(--ork-link); cursor: pointer;
    text-align: left; overflow-wrap: break-word;
}
.inv-holder-btn:hover { text-decoration: underline; }
.inv-holder-profile { margin-left: 6px; font-size: 0.75rem; color: var(--ork-text-muted); }
.inv-holder-profile:hover { color: var(--ork-link); }

/* Verified sub-line under the item name (#18) */
.inv-verified { display: block; margin-top: 3px; font-size: 0.74rem; font-weight: 400; color: var(--ork-text-muted); }

/* Removed-view disposal lines (#12) */
.inv-td-actions-wrap .rp-row-actions { flex-wrap: wrap; }
.inv-reason-note a { color: var(--ork-link); }

/* Held-by linked-player chip in the item modal (#26) */
.inv-heldby-chip { margin-top: 6px; }
.inv-heldby-chip[hidden] { display: none; }
.inv-heldby-chip .inv-chip { padding-left: 9px; }
.inv-heldby-chip .fa-user { color: var(--ork-text-muted); font-size: 0.75rem; }

/* Remove modal: disposal + Treasury fields (#12) */
#inv-remove-overlay .rp-modal { max-width: 520px; }
.inv-check { display: flex; gap: 8px; align-items: flex-start; font-size: 0.86rem; color: var(--ork-text); cursor: pointer; }
.inv-check input { margin-top: 3px; }
.inv-treasury-box { padding: 10px 12px 1px; margin-bottom: 13px; border: 1px solid var(--ork-border); border-radius: 8px; background: var(--ork-bg-secondary); }
.inv-treasury-box .rp-field-row { margin-top: 10px; }

/* Toasts (#27): bottom-right stack, non-blocking */
.inv-toasts {
    position: fixed; right: 16px; bottom: 16px; z-index: 10003;
    display: flex; flex-direction: column; gap: 8px; align-items: flex-end;
    max-width: min(420px, calc(100vw - 32px)); pointer-events: none;
}
.inv-toast {
    pointer-events: auto; display: flex; gap: 10px; align-items: flex-start; box-sizing: border-box; max-width: 100%;
    padding: 10px 10px 10px 14px; border-radius: 8px; font-size: 0.86rem;
    background: var(--ork-card-bg); color: var(--ork-text);
    border: 1px solid var(--ork-border); border-left: 4px solid var(--rp-primary);
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
}
.inv-toast.inv-toast-warn { border-left-color: var(--rp-warning-text); }
.inv-toast-msg { flex: 1 1 auto; overflow-wrap: anywhere; }
.inv-toast-acts { display: inline; }
.inv-toast-act { background: none; border: none; padding: 0; margin-left: 8px; font: inherit; font-weight: 700; color: var(--ork-link); cursor: pointer; }
.inv-toast-act:hover { text-decoration: underline; }
.inv-toast-close { flex: 0 0 auto; background: none; border: none; cursor: pointer; font-size: 1.1rem; line-height: 1; color: var(--ork-text-muted); padding: 0 2px; }
.inv-toast-close:hover { color: var(--ork-text); }

@media (max-width: 820px) {
    .rp-charts-row.inv-charts .rp-chart-card { flex: 1 1 100%; }
}

/* Mobile: each register row becomes a card; row actions collapse into one menu. */
@media (max-width: 640px) {
    #inv-table thead { display: none; }
    #inv-table, #inv-table tbody, #inv-table tr, #inv-table td { display: block; width: 100%; box-sizing: border-box; }
    #inv-table tbody tr {
        border: 1px solid var(--ork-border); border-radius: 8px; margin-bottom: 10px;
        padding: 8px 12px; background: var(--ork-card-bg);
    }
    #inv-table tbody td {
        display: flex; justify-content: space-between; gap: 12px;
        padding: 4px 0; border-bottom: none; text-align: right; overflow-wrap: anywhere;
    }
    #inv-table tbody td::before {
        content: attr(data-label); flex: 0 0 auto; text-align: left;
        font-size: 0.72rem; font-weight: 600; text-transform: uppercase; color: var(--ork-text-muted);
    }
    #inv-table tbody td.inv-td-name { display: block; text-align: left; font-weight: 600; font-size: 0.95rem; }
    #inv-table tbody td.inv-td-name::before, #inv-table tbody td.inv-td-actions::before { content: none; }
    #inv-table tbody td.inv-td-hide-m { display: none; }
    #inv-table tbody td.inv-td-actions { justify-content: flex-end; }
    #inv-table .inv-act-inline { display: none; }
    /* Modal field pairs (disposal date/proceeds, Treasury category/method, item modal) stack. */
    .rp-modal-overlay .rp-field-row { flex-direction: column; gap: 0; }
    .rp-modal-overlay .rp-field-row > .rp-field { flex: 0 0 auto; }
    .inv-table-toolbar { flex-direction: column; align-items: stretch; }
    .inv-status-seg { display: flex; }
    .inv-status-seg button { flex: 1 1 0; padding: 7px 6px; }
    .inv-toasts { left: 16px; right: 16px; max-width: none; align-items: stretch; }
}
</style>

<div class="rp-root inv-root" id="inv-app"
     data-ajax="<?= htmlspecialchars($ajaxBase) ?>"
     data-kingdom="<?= (int)$kingdom_id ?>">

    <!-- ── Header ─────────────────────────────────────── -->
    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fas fa-boxes rp-header-icon"></i>
                <h1 class="rp-header-title">Inventory</h1>
            </div>
            <div class="rp-header-scope">
                <span class="rp-scope-chip-label"><?= $ownerLabel ?> register</span>
                <?php if ($org_name !== ''): ?>
                <a class="rp-scope-chip" href="<?= htmlspecialchars($scopeLink) ?>">
                    <i class="fas <?= $scopeIcon ?>"></i>
                    <?= htmlspecialchars($org_name) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="rp-header-actions">
            <button class="rp-btn-ghost" id="inv-add" type="button"><i class="fas fa-plus"></i> Add Item</button>
            <a class="rp-btn-ghost" id="inv-export" href="<?= htmlspecialchars($ajaxBase) ?>export"><i class="fas fa-download"></i> Export CSV</a>
            <a class="rp-btn-ghost" id="inv-export-full" href="<?= htmlspecialchars($ajaxBase) ?>export&amp;full=1"
               data-tip="The complete register &mdash; every active and removed item, ignoring the filters. Use this for an end-of-term handoff."><i class="fas fa-file-csv"></i> Export full register</a>
            <button class="rp-btn-ghost" id="inv-log-open" type="button"><i class="fas fa-history"></i> Change Log</button>
            <button class="rp-btn-ghost" id="inv-countsheet" type="button"
                    data-tip="A printable sheet of the active items matching the filters, sorted by location, with blank columns for a physical count."><i class="fas fa-clipboard-list"></i> Count Sheet</button>
            <span class="rp-header-sep" aria-hidden="true"></span>
            <a class="rp-btn-ghost" href="<?= htmlspecialchars($siblingLink) ?>"><i class="fas fa-coins"></i> Go to Treasury <i class="fas fa-arrow-right rp-btn-arrow"></i></a>
        </div>
    </div>

    <!-- ── Context strip ──────────────────────────────── -->
    <div class="rp-context">
        <i class="fas fa-info-circle rp-context-icon"></i>
        <span>The <?= $ownerLabelLower ?>&rsquo;s durable goods &mdash; loaner gear, regalia, pavilions, event equipment &mdash; with what each is worth,
              what condition it&rsquo;s in, and who is holding it. Removing an item keeps it on the books under
              <strong>Removed</strong> with a reason, so nothing silently disappears between officer terms.</span>
    </div>

    <!-- ── Stats row ─────────────────────────────────── -->
    <div class="rp-stats-row">
        <div class="rp-stat-card">
            <div class="rp-stat-icon"><i class="fas fa-coins"></i></div>
            <div class="rp-stat-number" id="inv-total-value"><?= $fmt($summary['TotalValue']) ?></div>
            <div class="rp-stat-label">Total Value</div>
        </div>
        <div class="rp-stat-card">
            <div class="rp-stat-icon"><i class="fas fa-cubes"></i></div>
            <div class="rp-stat-number" id="inv-total-units"><?= (int)$summary['TotalUnits'] ?></div>
            <div class="rp-stat-label">Total Units</div>
        </div>
        <div class="rp-stat-card">
            <div class="rp-stat-icon"><i class="fas fa-list-ul"></i></div>
            <div class="rp-stat-number" id="inv-line-items"><?= (int)$summary['LineItems'] ?></div>
            <div class="rp-stat-label">Line Items</div>
        </div>
        <div class="rp-stat-card" id="inv-repair-card">
            <div class="rp-stat-icon"><i class="fas fa-tools"></i></div>
            <div class="rp-stat-number" id="inv-needs-repair"><?= (int)$summary['NeedsRepair'] ?></div>
            <div class="rp-stat-label">Needs Repair</div>
        </div>
    </div>

    <!-- ── Charts ────────────────────────────────────── -->
    <div class="rp-charts-row rp-charts-visible inv-charts">
        <div class="rp-chart-card">
            <div class="rp-chart-card-title">Value by Category</div>
            <div id="inv-chart-category" style="height:240px"></div>
        </div>
        <div class="rp-chart-card">
            <div class="rp-chart-card-title">Units by Condition</div>
            <div id="inv-chart-condition" style="height:240px"></div>
        </div>
    </div>

    <!-- ── Body: sidebar + main ───────────────────────── -->
    <div class="rp-body">

        <!-- Sidebar -->
        <div class="rp-sidebar">

            <div class="rp-filter-card">
                <div class="rp-filter-card-header">
                    <i class="fas fa-sliders-h"></i> Filters
                </div>
                <div class="rp-filter-card-body">
                    <div class="rp-param-form">
                        <div class="rp-form-group">
                            <label for="inv-f-q">Search</label>
                            <input type="text" id="inv-f-q" class="rp-form-input" placeholder="Name, location, holder, notes&hellip;" autocomplete="off">
                        </div>
                        <div class="rp-form-group">
                            <label for="inv-f-cat">Category</label>
                            <select id="inv-f-cat" class="rp-form-input">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rp-form-group">
                            <label for="inv-f-cond">Condition</label>
                            <select id="inv-f-cond" class="rp-form-input">
                                <option value="">Any condition</option>
                                <?php foreach ($condLabels as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rp-form-group">
                            <label for="inv-f-loc">Location</label>
                            <select id="inv-f-loc" class="rp-form-input">
                                <option value="">Any location</option>
                            </select>
                        </div>
                        <div class="rp-form-group">
                            <label class="rp-form-check" for="inv-f-stale">
                                <input type="checkbox" id="inv-f-stale"> Not verified in 12 months
                            </label>
                        </div>
                        <button type="button" class="rp-filter-reset" id="inv-f-reset">Clear filters</button>
                    </div>
                </div>
            </div>

            <div class="rp-filter-card">
                <div class="rp-filter-card-header">
                    <i class="fas fa-book-open"></i> About This Tool
                </div>
                <div class="rp-filter-card-body">
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Total Value</span>
                        <span class="rp-col-guide-desc">Quantity &times; unit value across every active line item. Removed items are excluded.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Held By</span>
                        <span class="rp-col-guide-desc">Who physically has the item. Link a player so the gear can be tracked down when they step back.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Mark Removed <span class="rp-col-guide-desc">vs.</span> Delete Record</span>
                        <span class="rp-col-guide-desc"><strong>Mark Removed</strong> is about the item: the <?= $ownerLabelLower ?> had it and no longer does
                            (sold, lost, donated, worn out). It keeps the history &mdash; pick <strong>Removed</strong> in the Active / Removed / Deleted toggle above the table to see it, or restore it.
                            <strong>Delete Record</strong> is about the row: it was a typo or a duplicate added in the last 7 days and should never have existed.
                            It leaves the register but stays in the Change Log, and can be undeleted from <strong>Deleted</strong> in that same toggle.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Export CSV</span>
                        <span class="rp-col-guide-desc">Exports the current view with the filters above applied. <strong>Export full register</strong> exports every active and removed item &mdash; use that for a handoff at the end of a term.</span>
                    </div>
                </div>
            </div>

        </div><!-- /rp-sidebar -->

        <!-- Main column -->
        <div class="rp-table-area">
            <div class="inv-table-toolbar">
                <div class="rp-seg inv-status-seg" id="inv-status-seg" role="group" aria-label="Item status">
                    <button type="button" data-status="active" class="rp-seg-on" aria-pressed="true">Active <span class="inv-seg-count" data-count="Active"></span></button>
                    <button type="button" data-status="removed" aria-pressed="false">Removed <span class="inv-seg-count" data-count="Removed"></span></button>
                    <button type="button" data-status="deleted" aria-pressed="false">Deleted <span class="inv-seg-count" data-count="Deleted"></span></button>
                </div>
                <button type="button" class="rp-btn" id="inv-verify-all"
                        data-tip="Record that every item matching the current filters was physically checked today."><i class="fas fa-clipboard-check"></i> Mark all shown as verified</button>
            </div>
            <div class="inv-chips" id="inv-chips"></div>
            <div class="inv-view-banner" id="inv-view-banner" style="display:none"><i class="fas fa-info-circle"></i><span id="inv-view-banner-text"></span></div>
            <table class="inv-table" id="inv-table">
                <thead>
                    <tr>
                        <th data-sort="name">Name</th>
                        <th data-sort="category">Category</th>
                        <th class="inv-num" data-sort="quantity">Qty</th>
                        <th data-sort="condition">Condition</th>
                        <th class="inv-num" data-sort="unit_value">Unit Value</th>
                        <th class="inv-num" data-sort="total_value">Total Value</th>
                        <th data-sort="location">Location</th>
                        <th>Held By</th>
                        <th class="inv-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="inv-table-body"><!-- rendered by JS --></tbody>
            </table>
            <div class="inv-empty" id="inv-empty" style="display:none">No items yet &mdash; add your first item.</div>
            <div class="rp-pager" id="inv-pager"></div>
        </div>

    </div><!-- /rp-body -->
</div>

<!-- Add / Edit item modal (built/populated by JS) -->
<div class="rp-modal-overlay" id="inv-item-overlay" aria-hidden="true">
    <div class="rp-modal" role="dialog" aria-modal="true" aria-labelledby="inv-item-title">
        <div class="rp-modal-head">
            <h2 id="inv-item-title">Add Item</h2>
            <button class="rp-modal-close" type="button" data-inv-close aria-label="Close">&times;</button>
        </div>
        <form id="inv-item-form" autocomplete="off">
            <div class="rp-modal-body">
                <div class="rp-field-row">
                    <div class="rp-field inv-field-grow">
                        <label class="rp-label" for="inv-i-name">Name</label>
                        <input class="rp-input" type="text" id="inv-i-name" name="name" maxlength="255" placeholder="e.g. Boffer longsword">
                        <div class="rp-field-err" data-err="name">A name is required.</div>
                    </div>
                    <div class="rp-field">
                        <label class="rp-label" for="inv-i-quantity">Quantity</label>
                        <input class="rp-input" type="number" min="1" step="1" id="inv-i-quantity" name="quantity" value="1">
                        <div class="rp-field-err" data-err="quantity">Quantity must be at least 1.</div>
                    </div>
                </div>
                <div class="rp-field" id="inv-i-change-note-field" style="display:none">
                    <label class="rp-label" for="inv-i-change-note">Why did the quantity go down?</label>
                    <textarea class="rp-textarea" id="inv-i-change-note" name="change_note" maxlength="500" placeholder="e.g. miscounted at the last inventory"></textarea>
                    <div class="inv-hint">To record lost, sold, or donated units, use <strong>Remove from Inventory</strong> instead.</div>
                    <div class="rp-field-err" data-err="change_note">Add a note explaining why the quantity went down.</div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="inv-i-category">Category</label>
                    <select class="rp-select" id="inv-i-category" name="category"></select>
                    <div class="rp-field-err" data-err="category">Choose a category.</div>
                </div>
                <div class="rp-field">
                    <label class="rp-label">Condition</label>
                    <div class="rp-seg" id="inv-i-cond-seg"></div>
                    <input type="hidden" name="condition" id="inv-i-condition" value="good">
                </div>
                <div class="rp-field-row">
                    <div class="rp-field">
                        <label class="rp-label" for="inv-i-unit-value">Unit Value</label>
                        <input class="rp-input" type="number" step="0.01" min="0" id="inv-i-unit-value" name="unit_value" placeholder="0.00">
                    </div>
                    <div class="rp-field">
                        <label class="rp-label" for="inv-i-acquired">Acquired Date</label>
                        <input class="rp-input" type="text" id="inv-i-acquired" name="acquired_date" placeholder="Select date">
                    </div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="inv-i-location">Location</label>
                    <input class="rp-input" type="text" id="inv-i-location" name="location" maxlength="255" placeholder="e.g. Kingdom shed">
                </div>
                <div class="rp-field" id="inv-i-heldby-field">
                    <label class="rp-label" for="inv-i-heldby">Held By</label>
                    <input class="rp-input" type="text" id="inv-i-heldby" name="held_by" maxlength="255" placeholder="Holder name, or search players&hellip;" autocomplete="off">
                    <input type="hidden" id="inv-i-heldby-player-id" name="held_by_player_id" value="0">
                    <div class="inv-heldby-chip" id="inv-i-heldby-chip" hidden>
                        <span class="inv-chip"><i class="fas fa-user" aria-hidden="true"></i><span class="inv-chip-text" id="inv-i-heldby-chip-label"></span><button type="button" id="inv-i-heldby-unlink" aria-label="Unlink player" data-tip="Unlink this player (keeps the name as free text)">&times;</button></span>
                    </div>
                    <div class="kn-ac-results" id="inv-i-heldby-results"></div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="inv-i-notes">Notes</label>
                    <textarea class="rp-textarea" id="inv-i-notes" name="notes" maxlength="500" placeholder="Anything else worth recording?"></textarea>
                </div>
                <div class="rp-field-err rp-field-err-form" data-err="_form"></div>
            </div>
            <div class="rp-modal-foot">
                <button class="rp-btn" type="button" data-inv-close>Cancel</button>
                <button class="rp-btn rp-btn-primary" type="submit" id="inv-i-save">Save Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Remove-from-inventory modal (reason + optional note) -->
<div class="rp-modal-overlay" id="inv-remove-overlay" aria-hidden="true">
    <div class="rp-modal rp-confirm-box" role="dialog" aria-modal="true" aria-labelledby="inv-remove-title">
        <div class="rp-modal-head">
            <h2 id="inv-remove-title">Mark Removed from Inventory</h2>
            <button class="rp-modal-close" type="button" data-inv-remove-close aria-label="Close">&times;</button>
        </div>
        <form id="inv-remove-form" autocomplete="off">
            <div class="rp-modal-body">
                <input type="hidden" name="id" id="inv-r-id" value="">
                <p class="inv-modal-intro">
                    For an item the org genuinely no longer has. It moves to the <strong>Removed</strong>
                    list with the reason below, drops out of the active totals, and can be restored later
                    &mdash; the record and its history (see <strong>History</strong> and the <strong>Change Log</strong>) are kept either way.
                    If the row was simply entered by mistake in the last 7 days, cancel and use <strong>Delete Record</strong> instead.
                </p>
                <div class="rp-field">
                    <label class="rp-label" for="inv-r-reason">Reason</label>
                    <select class="rp-select" id="inv-r-reason" name="removal_reason">
                        <option value="">Select a reason&hellip;</option>
                        <?php foreach ($removal_reasons as $k => $lbl): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="rp-field-err" data-err="removal_reason">Choose a reason for removal.</div>
                </div>
                <div class="rp-field" id="inv-r-units-field">
                    <label class="rp-label" for="inv-r-units">Units to remove</label>
                    <input class="rp-input" type="number" min="1" step="1" id="inv-r-units" name="units" value="1">
                    <div class="inv-hint" id="inv-r-units-hint"></div>
                    <div class="rp-field-err" data-err="units">Enter a number of units within the quantity.</div>
                </div>
                <div class="rp-field-row">
                    <div class="rp-field">
                        <label class="rp-label" for="inv-r-date">Disposal date</label>
                        <input class="rp-input" type="text" id="inv-r-date" name="disposal_date" placeholder="Select date">
                        <div class="rp-field-err" data-err="disposal_date">Choose a date that isn&rsquo;t in the future.</div>
                    </div>
                    <div class="rp-field">
                        <label class="rp-label" for="inv-r-value">Proceeds / value received <span class="inv-label-opt" id="inv-r-value-opt">(optional)</span></label>
                        <input class="rp-input" type="number" step="0.01" min="0" id="inv-r-value" name="disposal_value" placeholder="0.00">
                        <div class="rp-field-err" data-err="disposal_value">Enter the sale proceeds.</div>
                    </div>
                </div>
                <div class="inv-hint" id="inv-r-book" style="margin:-6px 0 13px"></div>
                <div class="rp-field">
                    <label class="rp-label" for="inv-r-to">Disposed to <span class="inv-label-opt">(optional)</span></label>
                    <input class="rp-input" type="text" id="inv-r-to" name="disposed_to" maxlength="255" placeholder="Buyer, recipient, or receiving org">
                </div>
                <div class="inv-treasury-box" id="inv-r-treasury" style="display:none">
                    <label class="inv-check" for="inv-r-rec">
                        <input type="checkbox" id="inv-r-rec" name="record_treasury" value="1"> Also record this sale as income in Treasury
                    </label>
                    <div class="rp-field-row" id="inv-r-treasury-fields" style="display:none">
                        <div class="rp-field">
                            <label class="rp-label" for="inv-r-tcat">Treasury category</label>
                            <select class="rp-select" id="inv-r-tcat" name="treasury_category">
                                <?php foreach ($treasury_income_categories as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"<?= $k === 'income_other' ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rp-field">
                            <label class="rp-label" for="inv-r-tmethod">Payment method</label>
                            <select class="rp-select" id="inv-r-tmethod" name="treasury_method">
                                <option value="">Select&hellip;</option>
                                <?php foreach ($treasury_methods as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="rp-field-err" data-err="treasury_method">Choose a payment method.</div>
                        </div>
                    </div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="inv-r-note">Note <span class="inv-label-opt">(optional)</span></label>
                    <textarea class="rp-textarea" id="inv-r-note" name="removal_note" maxlength="500" placeholder="e.g. damaged at June war, disposed of on-site"></textarea>
                </div>
                <div class="rp-field-err rp-field-err-form" data-err="_form"></div>
            </div>
            <div class="rp-modal-foot">
                <button class="rp-btn" type="button" data-inv-remove-close>Cancel</button>
                <button class="rp-btn rp-btn-danger" type="submit" id="inv-r-save">Mark Removed</button>
            </div>
        </form>
    </div>
</div>

<!-- Split modal: move N units to a new line item -->
<div class="rp-modal-overlay" id="inv-split-overlay" aria-hidden="true">
    <div class="rp-modal rp-confirm-box" role="dialog" aria-modal="true" aria-labelledby="inv-split-title">
        <div class="rp-modal-head">
            <h2 id="inv-split-title">Split Item</h2>
            <button class="rp-modal-close" type="button" data-inv-split-close aria-label="Close">&times;</button>
        </div>
        <form id="inv-split-form" autocomplete="off">
            <div class="rp-modal-body">
                <p class="inv-modal-intro" id="inv-s-intro"></p>
                <div class="rp-field">
                    <label class="rp-label" for="inv-s-units">Move units to a new line item</label>
                    <input class="rp-input" type="number" min="1" step="1" id="inv-s-units" name="units" value="1">
                    <div class="inv-hint" id="inv-s-hint"></div>
                    <div class="rp-field-err" data-err="units">Enter a number of units within the range.</div>
                </div>
                <div class="rp-field-err rp-field-err-form" data-err="_form"></div>
            </div>
            <div class="rp-modal-foot">
                <button class="rp-btn" type="button" data-inv-split-close>Cancel</button>
                <button class="rp-btn rp-btn-primary" type="submit" id="inv-s-save">Split</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete-record modal (mis-entry correction; required note) -->
<div class="rp-modal-overlay" id="inv-delete-overlay" aria-hidden="true">
    <div class="rp-modal rp-confirm-box" role="dialog" aria-modal="true" aria-labelledby="inv-delete-title">
        <div class="rp-modal-head">
            <h2 id="inv-delete-title">Delete this record?</h2>
            <button class="rp-modal-close" type="button" data-inv-delete-close aria-label="Close">&times;</button>
        </div>
        <form id="inv-delete-form" autocomplete="off">
            <div class="rp-modal-body">
                <p class="inv-modal-intro">
                    Delete is only for a row <strong>added by mistake in the last 7 days</strong> &mdash; a typo or a duplicate.
                    It drops out of the register but stays in the <strong>Change Log</strong> with your note, and can be
                    undeleted from <strong>Deleted</strong> in the Active / Removed / Deleted toggle above the table. If the org actually owned this item, cancel and use
                    <strong>Mark Removed</strong> instead.
                </p>
                <div class="rp-field">
                    <label class="rp-label" for="inv-d-note">Why is this a mis-entry?</label>
                    <textarea class="rp-textarea" id="inv-d-note" name="delete_note" maxlength="500" placeholder="e.g. duplicate of the row added the same day"></textarea>
                    <div class="rp-field-err" data-err="delete_note">A note is required.</div>
                </div>
                <div class="rp-field-err rp-field-err-form" data-err="_form"></div>
            </div>
            <div class="rp-modal-foot">
                <button class="rp-btn" type="button" data-inv-delete-close>Cancel</button>
                <button class="rp-btn rp-btn-danger" type="submit" id="inv-d-save">Delete Record</button>
            </div>
        </form>
    </div>
</div>

<!-- Org-level change log -->
<div class="rp-modal-overlay" id="inv-log-overlay" aria-hidden="true">
    <div class="rp-modal inv-modal-wide" role="dialog" aria-modal="true" aria-labelledby="inv-log-title">
        <div class="rp-modal-head">
            <h2 id="inv-log-title">Change Log</h2>
            <button class="rp-modal-close" type="button" data-inv-log-close aria-label="Close">&times;</button>
        </div>
        <div class="rp-modal-body">
            <div class="inv-log-toolbar">
                <label class="rp-label" for="inv-log-action">Action</label>
                <select class="rp-select" id="inv-log-action">
                    <option value="">All actions</option>
                    <option value="create">Created</option>
                    <option value="edit">Edited</option>
                    <option value="split">Split</option>
                    <option value="remove">Removed</option>
                    <option value="restore">Restored</option>
                    <option value="delete">Deleted</option>
                    <option value="undelete">Undeleted</option>
                    <option value="verify">Verified</option>
                </select>
            </div>
            <div id="inv-log-list"></div>
            <div class="rp-pager" id="inv-log-pager"></div>
        </div>
    </div>
</div>

<!-- Per-item history (stacks above the change log) -->
<div class="rp-modal-overlay" id="inv-history-overlay" aria-hidden="true">
    <div class="rp-modal inv-modal-wide" role="dialog" aria-modal="true" aria-labelledby="inv-history-title">
        <div class="rp-modal-head">
            <h2 id="inv-history-title">History</h2>
            <button class="rp-modal-close" type="button" data-inv-history-close aria-label="Close">&times;</button>
        </div>
        <div class="rp-modal-body" id="inv-history-body"></div>
    </div>
</div>

<!-- Non-blocking toasts (undo / confirmations) -->
<div class="inv-toasts" id="inv-toasts" aria-live="polite" aria-atomic="false"></div>

<script>
window.InvConfig = {
    ajax:            '<?= $ajaxBase ?>',
    uir:             '<?= UIR ?>',
    ownerType:       '<?= $owner_type === 'park' ? 'park' : 'kingdom' ?>',
    ownerId:         <?= (int)$owner_id ?>,
    kingdomId:       <?= (int)($kingdom_id ?? 0) ?>,
    categories:      <?= json_encode($categories) ?>,
    removalReasons:  <?= json_encode($removal_reasons) ?>,
    treasuryIncome:  <?= json_encode($treasury_income_categories) ?>,
    treasuryMethods: <?= json_encode($treasury_methods) ?>,
    orgName:         <?= json_encode($org_name) ?>,
    conditionLabels: <?= json_encode($condLabels) ?>,
    summary:         <?= json_encode($summary) ?>,
    initialItems:    <?= json_encode($items) ?>
};
</script>

<script>
/* =====================================================
   Inventory — item render, add/edit modal, held-by playersearch.
   Exposes window.InvApp so later sections (remove/restore/delete, charts)
   can trigger refreshes via InvApp (loadSummary fires 'inv:summarychanged').
   ===================================================== */
(function () {
    'use strict';

    var app = document.getElementById('inv-app');
    if (!app) { return; }
    var cfg = window.InvConfig || {};

    var body  = document.getElementById('inv-table-body');
    var table = document.getElementById('inv-table');
    var empty = document.getElementById('inv-empty');
    var pager = document.getElementById('inv-pager');
    var state = { page: 1, per: 25, q: '', category: '', condition: '', status: 'active', sort: 'name', dir: 'asc',
        location: '', held_by_player_id: 0, held_by: '', holderLabel: '', stale: false, total: 0 };
    var treasuryUrl = cfg.uir + 'Treasury/' + cfg.ownerType + '/' + cfg.ownerId;

    /* The register filters (everything but status/sort/paging) — shared by items, summary,
       export, the count sheet and bulk verify so they always agree. */
    function filterParams() {
        var f = { q: state.q, category: state.category, condition: state.condition, location: state.location };
        if (state.held_by_player_id > 0) { f.held_by_player_id = state.held_by_player_id; }
        else if (state.held_by) { f.held_by = state.held_by; }
        if (state.stale) { f.stale = 1; }
        return f;
    }

    /* ---- helpers ---- */
    var money = function (n) { return '$' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function condLabel(c) { return (cfg.conditionLabels && cfg.conditionLabels[c]) || c || ''; }
    function catLabel(c) { return (cfg.categories && cfg.categories[c]) || c || ''; }
    // "2026-06-07 12:34:56" -> "Jun 7, 2026" (date-only, parsed as local so no TZ drift).
    function fmtDate(s) {
        if (!s) { return ''; }
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) { return String(s); }
        var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    // "2026-06-07 13:04:00" -> "Jun 7, 2026, 1:04 PM" (local, no TZ drift).
    function fmtDateTime(s) {
        var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) { return fmtDate(s); }
        var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
        return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    /* Modal focus: remember the opener, return focus to it on close. */
    function rememberOpener(ov) { ov._invOpener = document.activeElement; }
    function returnFocus(ov) {
        var el = ov._invOpener; ov._invOpener = null;
        if (el && el.focus && document.body.contains(el)) { el.focus(); }
    }
    function postForm(action, data) {
        var fd = new URLSearchParams();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k] == null ? '' : data[k]); });
        return fetch(cfg.ajax + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ---- toasts: non-blocking, stacked bottom-right, auto-dismiss (paused on hover/focus) ----
       opts: { actions: [{label, fn}], link: {label, href}, warn: bool, sticky: bool } */
    var toastRegion = document.getElementById('inv-toasts');
    function toast(msg, opts) {
        opts = opts || {};
        if (!toastRegion) { return; }
        var t = document.createElement('div');
        t.className = 'inv-toast' + (opts.warn ? ' inv-toast-warn' : '');
        t.setAttribute('role', opts.warn ? 'alert' : 'status');
        var m = document.createElement('div');
        m.className = 'inv-toast-msg';
        m.appendChild(document.createTextNode(msg));
        var acts = document.createElement('span');
        acts.className = 'inv-toast-acts';
        var timer = null;
        function dismiss() { clearTimeout(timer); if (t.parentNode) { t.parentNode.removeChild(t); } }
        (opts.actions || []).forEach(function (a) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'inv-toast-act'; b.textContent = a.label;
            b.addEventListener('click', function () { dismiss(); a.fn(); });
            acts.appendChild(document.createTextNode(' \u00b7'));
            acts.appendChild(b);
        });
        if (opts.link) {
            var l = document.createElement('a');
            l.className = 'inv-toast-act'; l.href = opts.link.href; l.textContent = opts.link.label;
            acts.appendChild(document.createTextNode(' \u00b7'));
            acts.appendChild(l);
        }
        m.appendChild(acts);
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'inv-toast-close'; x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '&times;';
        x.addEventListener('click', dismiss);
        t.appendChild(m); t.appendChild(x);
        toastRegion.appendChild(t);
        function arm() { if (!opts.sticky) { clearTimeout(timer); timer = setTimeout(dismiss, 8000); } }
        t.addEventListener('mouseenter', function () { clearTimeout(timer); });
        t.addEventListener('mouseleave', arm);
        t.addEventListener('focusin', function () { clearTimeout(timer); });
        t.addEventListener('focusout', arm);
        arm();
    }
    function quoted(name) { return '\u201c' + (name || 'Item') + '\u201d'; }
    /* Undo helper: POST, refresh the list + summary, surface failures as a toast.
       onOk(detail) runs after a successful undo (e.g. to warn about a Treasury entry). */
    function undoPost(action, data, failMsg, onOk) {
        postForm(action, data).then(function (j) {
            refreshAll();
            if (j.status !== 0) { toast(j.error || failMsg, { warn: true }); }
            else if (typeof onOk === 'function') { onOk(j.detail || {}); }
        }).catch(function () { toast(failMsg, { warn: true }); });
    }

    /* ---- in-product confirm (tnConfirm if Tournament helper is loaded; else local fallback) ---- */
    function invConfirm(opts) {
        if (typeof window.tnConfirm === 'function') { window.tnConfirm(opts); return; }
        var ov = document.createElement('div');
        ov.className = 'rp-modal-overlay rp-open';
        ov.innerHTML =
            '<div class="rp-modal rp-confirm-box" role="dialog" aria-modal="true">' +
            '<div class="rp-modal-head"><h2></h2>' +
            '<button class="rp-modal-close" type="button" data-c="x" aria-label="Close">&times;</button></div>' +
            '<div class="rp-modal-body"></div>' +
            '<div class="rp-modal-foot">' +
            '<button class="rp-btn" type="button" data-c="cancel"></button>' +
            '<button class="rp-btn" type="button" data-c="ok"></button></div></div>';
        ov.querySelector('h2').textContent = opts.title || 'Confirm';
        ov.querySelector('.rp-modal-body').textContent = opts.body || '';
        var okBtn = ov.querySelector('[data-c="ok"]');
        var cancelBtn = ov.querySelector('[data-c="cancel"]');
        okBtn.textContent = opts.confirmLabel || 'Confirm';
        cancelBtn.textContent = opts.cancelLabel || 'Cancel';
        okBtn.className = 'rp-btn ' + (opts.danger ? 'rp-btn-danger' : 'rp-btn-primary');
        function close() { if (ov.parentNode) { ov.parentNode.removeChild(ov); } }
        ov.addEventListener('click', function (e) {
            var c = e.target.getAttribute && e.target.getAttribute('data-c');
            if (e.target === ov || c === 'x' || c === 'cancel') { close(); }
            else if (c === 'ok') { close(); if (typeof opts.onConfirm === 'function') { opts.onConfirm(); } }
        });
        document.body.appendChild(ov);
        okBtn.focus();
    }

    /* ---- item rendering ---- */
    /* The holder name filters the register to that holder; a linked player also gets a
       small profile-link icon beside it. */
    function heldByCell(r) {
        var name = escapeHtml(r.HeldBy || '');
        if (!name) { return ''; }
        var pid = Number(r.HeldByPlayerId) || 0;
        var html = '<button type="button" class="inv-holder-btn" data-holder-filter="' + r.Id + '" data-tip="Show only items held by ' + name + '">' + name + '</button>';
        if (pid > 0) {
            html += '<a class="inv-holder-profile" href="' + cfg.uir + 'Playernew/index/' + pid + '" aria-label="Open ' + name +
                '\u2019s player profile" data-tip="Open player profile"><i class="fas fa-external-link-alt"></i></a>';
        }
        return html;
    }
    function conditionCell(r) {
        if (r.Condition === 'needs_repair') {
            return '<span class="rp-badge inv-badge-repair">Needs Repair</span>';
        }
        return escapeHtml(condLabel(r.Condition));
    }
    /* Two different destructive verbs, so the labels have to carry the difference:
       "Mark Removed" acts on the OBJECT (the org no longer has it — it stays on the
       books under the Removed view and can be restored); "Delete Record" acts on the
       ROW (it was never real — a typo or a duplicate — so it leaves the register, but stays
       in the Change Log and can be undeleted from the Deleted status). */
    var TIP_EDIT    = 'Correct this item\u2019s details \u2014 name, quantity, condition, value, location, or who is holding it.';
    var TIP_REMOVE  = 'The org no longer has this item \u2014 sold, lost, donated, or worn out. It stays on the books under Removed, with a reason, and can be restored.';
    var TIP_RESTORE = 'Put this item back into active inventory.';
    var TIP_DELETE  = 'Take this row out of the register \u2014 only for a row added by mistake in the last 7 days. It stays in the Change Log and can be undeleted. To log an item the org disposed of, use Mark Removed instead.';
    var TIP_SPLIT   = 'Move some of these units to their own line item \u2014 e.g. to record a different holder, location, or condition for part of the stock.';
    var TIP_HISTORY = 'Every change made to this item \u2014 who, when, and what changed.';
    var TIP_UNDELETE = 'Bring this record back into the register.';
    var TIP_VERIFY  = 'Record that this item was physically checked today.';

    /* Delete is only offered for rows created in the last 7 days (the server enforces the
       same window by the row's 'create' audit; an unparseable date fails open). */
    function deletable(r) {
        var m = String(r.CreatedAt || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
        if (!m) { return true; }
        var created = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), Number(m[6]));
        return (Date.now() - created.getTime()) <= 7 * 86400000;
    }

    /* The row's actions as data, so the inline buttons (desktop) and the
       Actions menu (mobile) are built from one list and share one handler. */
    function rowActions(r) {
        var id = r.Id;
        if (state.status === 'removed') {
            return [
                { attr: 'data-restore', id: id, icon: 'fa-undo', label: 'Restore', tip: TIP_RESTORE, cls: 'rp-row-btn-primary', inline: true },
                { attr: 'data-history', id: id, icon: 'fa-history', label: 'History', tip: TIP_HISTORY }
            ];
        }
        if (state.status === 'deleted') {
            return [
                { attr: 'data-history', id: id, icon: 'fa-history', label: 'History', tip: TIP_HISTORY },
                { attr: 'data-undelete', id: id, icon: 'fa-trash-restore', label: 'Undelete', tip: TIP_UNDELETE, cls: 'rp-row-btn-primary', inline: true }
            ];
        }
        var list = [{ attr: 'data-edit', id: id, icon: 'fa-pen', label: 'Edit', tip: TIP_EDIT, inline: true }];
        if ((Number(r.Quantity) || 0) >= 2) {
            list.push({ attr: 'data-split', id: id, icon: 'fa-code-branch', label: 'Split', tip: TIP_SPLIT });
        }
        list.push({ attr: 'data-remove', id: id, icon: 'fa-box-open', label: 'Mark Removed', tip: TIP_REMOVE });
        list.push({ attr: 'data-verify', id: id, icon: 'fa-clipboard-check', label: 'Mark verified', tip: TIP_VERIFY });
        list.push({ attr: 'data-history', id: id, icon: 'fa-history', label: 'History', tip: TIP_HISTORY });
        if (deletable(r)) {
            list.push({ attr: 'data-del', id: id, icon: 'fa-trash-alt', label: 'Delete Record', tip: TIP_DELETE, cls: 'rp-row-btn-danger', danger: true });
        }
        return list;
    }
    function actionCell(r) {
        var html = '<div class="rp-row-actions">' + rowActions(r).filter(function (a) { return a.inline; }).map(function (a) {
            return '<button class="rp-row-btn inv-act-inline' + (a.cls ? ' ' + a.cls : '') + '" type="button" ' + a.attr + '="' + a.id +
                '" data-tip="' + escapeHtml(a.tip) + '"><i class="fas ' + a.icon + '"></i> ' + escapeHtml(a.label) + '</button>';
        }).join('');
        return html + '<button class="rp-row-btn inv-act-toggle" type="button" data-actmenu="' + r.Id +
            '" aria-haspopup="menu" aria-expanded="false"><i class="fas fa-ellipsis-h"></i> Actions</button></div>';
    }

    var rowsById = {};
    function filtersActive() {
        return !!(state.q || state.category || state.condition || state.location ||
            state.held_by_player_id > 0 || state.held_by || state.stale);
    }
    function renderEmpty() {
        empty.classList.remove('inv-empty-first');
        if (filtersActive()) {
            empty.innerHTML = 'No items match these filters. <button type="button" class="rp-filter-reset" data-inv-clear>Clear filters</button>';
        } else if (state.status === 'removed') {
            empty.textContent = 'No removed items.';
        } else if (state.status === 'deleted') {
            empty.textContent = 'No deleted items.';
        } else {
            empty.classList.add('inv-empty-first');
            empty.innerHTML = '<p>Nothing on the register yet. Start with the gear the ' + escapeHtml(cfg.ownerType) +
                ' owns &mdash; loaner weapons, regalia, pavilions.</p>' +
                '<button type="button" class="rp-btn rp-btn-primary" data-inv-first-add><i class="fas fa-plus"></i> Add your first item</button>';
        }
    }

    function renderRows(d) {
        d = d || {};
        body.innerHTML = '';
        rowsById = {};
        var rows = d.Rows || [];
        state.total = Number(d.Total) || 0;
        syncVerifyAll();
        var removed = state.status === 'removed';
        var deleted = state.status === 'deleted';
        if (!rows.length) {
            // Empty state replaces the table entirely.
            table.style.display = 'none';
            empty.style.display = '';
            renderEmpty();
            pager.innerHTML = '';
            return;
        }
        table.style.display = '';
        empty.style.display = 'none';
        rows.forEach(function (r) {
            rowsById[r.Id] = r;
            var tr = document.createElement('tr');
            var nameCell = escapeHtml(r.Name || '');
            if (deleted && r.DeletedAt) {
                nameCell += '<span class="inv-reason-note">Deleted ' + escapeHtml(fmtDate(r.DeletedAt)) + '</span>';
            }
            if (removed && (r.RemovalReason || r.RemovedAt)) {
                if (r.RemovalReason) {
                    var rlabel = (cfg.removalReasons && cfg.removalReasons[r.RemovalReason]) || r.RemovalReason;
                    nameCell += ' <span class="rp-badge inv-badge-reason">' + escapeHtml(rlabel) + '</span>';
                }
                var outDate = r.DisposalDate || r.RemovedAt;
                if (outDate) { nameCell += '<span class="inv-reason-note">Removed ' + escapeHtml(fmtDate(outDate)) + '</span>'; }
                var proceeds = Number(r.DisposalValue) || 0;
                var book = (r.BookValue != null && r.BookValue !== '') ? Number(r.BookValue) : Number(r.TotalValue);
                if (proceeds > 0 || r.RemovalReason === 'sold') {
                    nameCell += '<span class="inv-reason-note">Proceeds ' + money(proceeds) + ' vs. book value ' + money(book) + '</span>';
                }
                if (r.DisposedTo) { nameCell += '<span class="inv-reason-note">To ' + escapeHtml(r.DisposedTo) + '</span>'; }
                if (Number(r.TreasuryEntryId) > 0) {
                    nameCell += '<span class="inv-reason-note"><a href="' + escapeHtml(treasuryUrl) + '">Treasury entry #' +
                        (Number(r.TreasuryEntryId) || 0) + '</a></span>';
                }
                if (r.RemovalNote) { nameCell += '<span class="inv-reason-note">' + escapeHtml(r.RemovalNote) + '</span>'; }
            }
            if (!removed && !deleted) {
                nameCell += '<span class="inv-verified">' + (r.LastVerifiedAt
                    ? 'Verified ' + escapeHtml(fmtDate(r.LastVerifiedAt)) + (r.LastVerifiedByName ? ' by ' + escapeHtml(r.LastVerifiedByName) : '')
                    : 'Never verified') + '</span>';
            }
            tr.innerHTML =
                '<td class="inv-td-name">' + nameCell + '</td>' +
                '<td data-label="Category">' + escapeHtml(catLabel(r.Category)) + '</td>' +
                '<td class="inv-num" data-label="Qty">' + (Number(r.Quantity) || 0) + '</td>' +
                '<td data-label="Condition">' + conditionCell(r) + '</td>' +
                '<td class="inv-num inv-td-hide-m" data-label="Unit Value">' + money(r.UnitValue) + '</td>' +
                '<td class="inv-num" data-label="Total Value">' + money(r.TotalValue) + '</td>' +
                '<td class="inv-td-hide-m" data-label="Location">' + escapeHtml(r.Location || '') + '</td>' +
                '<td data-label="Held By">' + heldByCell(r) + '</td>' +
                // Removed rows carry disposal detail lines, so let Restore/Actions stack to keep the table in bounds.
                (removed ? '<td class="inv-td-actions inv-td-actions-wrap">' : '<td class="inv-td-actions" style="white-space:nowrap;">') +
                actionCell(r) + '</td>';
            body.appendChild(tr);
        });
        renderPager(d);
        updateSortIndicators();
    }

    function renderPager(d) {
        pager.innerHTML = '';
        var total = d.Total || 0, per = d.Per || state.per, page = d.Page || state.page;
        var pages = Math.max(1, Math.ceil(total / per));
        if (total === 0) { return; }
        var prev = document.createElement('button');
        prev.type = 'button'; prev.textContent = 'Prev'; prev.disabled = page <= 1;
        prev.addEventListener('click', function () { if (state.page > 1) { state.page--; loadItems(); } });
        var info = document.createElement('span');
        info.textContent = 'Page ' + page + ' of ' + pages + ' · ' + total + ' item' + (total === 1 ? '' : 's');
        var next = document.createElement('button');
        next.type = 'button'; next.textContent = 'Next'; next.disabled = page >= pages;
        next.addEventListener('click', function () { if (state.page < pages) { state.page++; loadItems(); } });
        pager.appendChild(prev); pager.appendChild(info); pager.appendChild(next);
    }

    function updateSortIndicators() {
        Array.prototype.forEach.call(table.querySelectorAll('thead th[data-sort]'), function (th) {
            var ind = th.querySelector('.inv-sort-ind');
            if (ind) { ind.parentNode.removeChild(ind); }
            if (th.getAttribute('data-sort') === state.sort) {
                var span = document.createElement('span');
                span.className = 'inv-sort-ind';
                span.textContent = state.dir === 'asc' ? ' ▲' : ' ▼';
                th.appendChild(span);
            }
        });
    }

    function itemsQuery(extra) {
        var q = new URLSearchParams(filterParams());
        q.set('page', state.page); q.set('per', state.per); q.set('status', state.status);
        q.set('sort', state.sort); q.set('dir', state.dir);
        if (extra) { Object.keys(extra).forEach(function (k) { q.set(k, extra[k]); }); }
        return q.toString();
    }

    function loadItems() {
        // cfg.ajax already ends in '?Route=...'; append params with '&' (a 2nd '?' breaks routing).
        return fetch(cfg.ajax + 'items&' + itemsQuery(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 0 || !j.detail) { return j; }
                var d = j.detail;
                var total = Number(d.Total) || 0, per = Number(d.Per) || state.per;
                // Past the last page (e.g. the last row on it was removed): jump to the real last page.
                var lastPage = Math.max(1, Math.ceil(total / per));
                if (!(d.Rows || []).length && total > 0 && state.page > lastPage) {
                    state.page = lastPage;
                    return loadItems();
                }
                if (d.Page) { state.page = Number(d.Page) || 1; }   // the server clamps; stay in sync
                renderRows(d);
                return j;
            })
            .catch(function (e) { console.log('[Inventory] loadItems failed', e); });
    }

    /* Refresh the four summary cards (Total Value / Units / Line Items / Needs Repair). */
    function loadSummary() {
        return fetch(cfg.ajax + 'summary&' + new URLSearchParams(filterParams()).toString(),
                { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 0 || !j.detail) { return j; }
                var s = j.detail;
                var setTxt = function (id, val) { var el = document.getElementById(id); if (el) { el.textContent = val; } };
                setTxt('inv-total-value', money(s.TotalValue));
                setTxt('inv-total-units', Number(s.TotalUnits) || 0);
                setTxt('inv-line-items', Number(s.LineItems) || 0);
                setTxt('inv-needs-repair', Number(s.NeedsRepair) || 0);
                cfg.summary = s;
                renderCounts(s.Counts);
                // Charts re-render from cfg.summary, so they always match the stat cards' filters.
                app.dispatchEvent(new CustomEvent('inv:summarychanged'));
                return j;
            })
            .catch(function (e) { console.log('[Inventory] loadSummary failed', e); });
    }

    /* Reload everything affected by a CRUD operation; loadSummary fires inv:summarychanged for the charts. */
    function refreshAll() {
        return Promise.all([loadItems(), loadSummary()]);
    }

    /* ---- status toggle (Active | Removed | Deleted) + view banner + bulk-verify visibility ---- */
    var statusSeg  = document.getElementById('inv-status-seg');
    var viewBanner = document.getElementById('inv-view-banner');
    var verifyAllBtn = document.getElementById('inv-verify-all');
    function renderCounts(c) {
        if (!statusSeg || !c) { return; }
        Array.prototype.forEach.call(statusSeg.querySelectorAll('[data-count]'), function (el) {
            var k = el.getAttribute('data-count');
            el.textContent = (c[k] != null) ? '(' + (Number(c[k]) || 0) + ')' : '';
        });
    }
    function syncVerifyAll() {
        if (!verifyAllBtn) { return; }
        verifyAllBtn.style.display = state.status === 'active' ? '' : 'none';
        verifyAllBtn.disabled = !(state.total > 0);
    }
    function syncStatusView() {
        if (statusSeg) {
            Array.prototype.forEach.call(statusSeg.querySelectorAll('button[data-status]'), function (b) {
                var on = b.getAttribute('data-status') === state.status;
                b.classList.toggle('rp-seg-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        if (viewBanner) {
            var txt = state.status === 'removed' ? 'Showing removed items. The totals and charts above count active inventory only.'
                : state.status === 'deleted' ? 'Showing deleted records. The totals and charts above count active inventory only.' : '';
            document.getElementById('inv-view-banner-text').textContent = txt;
            viewBanner.style.display = txt ? '' : 'none';
        }
        syncVerifyAll();
    }
    function setStatus(st) {
        state.status = ['removed', 'deleted'].indexOf(st) !== -1 ? st : 'active';
        state.page = 1;
        syncStatusView();
        return loadItems();
    }

    /* ---- Location filter options (GET locations); refreshed after add/edit ---- */
    var locSel = document.getElementById('inv-f-loc');
    function loadLocations() {
        if (!locSel) { return; }
        fetch(cfg.ajax + 'locations', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 0 || !j.detail) { return; }
                var locs = (j.detail.Locations || []).map(String);
                if (state.location && locs.indexOf(state.location) === -1) { locs.unshift(state.location); }
                locSel.innerHTML = '<option value="">Any location</option>';
                locs.forEach(function (l) {
                    var o = document.createElement('option');
                    o.value = l; o.textContent = l;
                    locSel.appendChild(o);
                });
                locSel.value = state.location;
            })
            .catch(function (e) { console.log('[Inventory] loadLocations failed', e); });
    }

    /* ---- active holder / location filters as removable chips above the table ---- */
    var chipsEl = document.getElementById('inv-chips');
    function renderChips() {
        if (!chipsEl) { return; }
        var chips = [];
        if (state.held_by_player_id > 0 || state.held_by) {
            chips.push({ k: 'holder', label: 'Held by', val: state.holderLabel || state.held_by });
        }
        if (state.location) { chips.push({ k: 'location', label: 'Location', val: state.location }); }
        chipsEl.innerHTML = chips.map(function (c) {
            return '<span class="inv-chip"><span class="inv-chip-text"><b>' + escapeHtml(c.label) + ':</b> ' + escapeHtml(c.val) +
                '</span><button type="button" data-chip-clear="' + c.k + '" aria-label="Remove ' + escapeHtml(c.label) +
                ' filter">&times;</button></span>';
        }).join('');
    }
    function applyFilters() { state.page = 1; renderChips(); loadItems(); loadSummary(); }
    function setHolderFilter(r) {
        var pid = Number(r.HeldByPlayerId) || 0;
        state.held_by_player_id = pid > 0 ? pid : 0;
        state.held_by = pid > 0 ? '' : String(r.HeldBy || '');
        state.holderLabel = String(r.HeldBy || '');
        applyFilters();
    }

    /* ---- filters + sort ---- */
    function bindFilters() {
        var qIn   = document.getElementById('inv-f-q');
        var catSel = document.getElementById('inv-f-cat');
        var condSel = document.getElementById('inv-f-cond');
        var staleCb = document.getElementById('inv-f-stale');
        var qTimer = null;
        if (qIn) {
            qIn.addEventListener('input', function () {
                clearTimeout(qTimer);
                qTimer = setTimeout(function () { state.q = qIn.value.trim(); state.page = 1; loadItems(); loadSummary(); }, 250);
            });
        }
        if (catSel)  { catSel.addEventListener('change', function () { state.category = catSel.value; state.page = 1; loadItems(); loadSummary(); }); }
        if (condSel) { condSel.addEventListener('change', function () { state.condition = condSel.value; state.page = 1; loadItems(); loadSummary(); }); }
        if (locSel)  { locSel.addEventListener('change', function () { state.location = locSel.value; applyFilters(); }); }
        if (staleCb) { staleCb.addEventListener('change', function () { state.stale = staleCb.checked; applyFilters(); }); }
        if (statusSeg) {
            statusSeg.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-status]');
                if (b && b.getAttribute('data-status') !== state.status) { setStatus(b.getAttribute('data-status')); }
            });
        }
        if (chipsEl) {
            chipsEl.addEventListener('click', function (e) {
                var b = e.target.closest('[data-chip-clear]');
                if (!b) { return; }
                if (b.getAttribute('data-chip-clear') === 'holder') { state.held_by_player_id = 0; state.held_by = ''; state.holderLabel = ''; }
                else { state.location = ''; if (locSel) { locSel.value = ''; } }
                applyFilters();
                var next = chipsEl.querySelector('[data-chip-clear]');
                if (next) { next.focus(); }
            });
        }

        var reset = document.getElementById('inv-f-reset');
        if (reset) {
            reset.addEventListener('click', function () {
                clearTimeout(qTimer);
                if (qIn)     { qIn.value = ''; }
                if (catSel)  { catSel.value = ''; }
                if (condSel) { condSel.value = ''; }
                if (locSel)  { locSel.value = ''; }
                if (staleCb) { staleCb.checked = false; }
                state.q = ''; state.category = ''; state.condition = ''; state.status = 'active'; state.page = 1;
                state.location = ''; state.held_by_player_id = 0; state.held_by = ''; state.holderLabel = ''; state.stale = false;
                syncStatusView();
                renderChips();
                loadItems();
                loadSummary();
            });
        }

        // Sortable headers.
        Array.prototype.forEach.call(table.querySelectorAll('thead th[data-sort]'), function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-sort');
                if (state.sort === key) { state.dir = state.dir === 'asc' ? 'desc' : 'asc'; }
                else { state.sort = key; state.dir = 'asc'; }
                state.page = 1; loadItems();
            });
        });

        // Keep the export link in sync with active filters.
        var exp = document.getElementById('inv-export');
        if (exp) {
            exp.addEventListener('click', function () {
                exp.href = cfg.ajax + 'export&' + itemsQuery({ page: 1, per: 100000 });
            });
        }
    }

    /* =====================================================
       Add / Edit item modal
       ===================================================== */
    var overlay   = document.getElementById('inv-item-overlay');
    var form      = document.getElementById('inv-item-form');
    var titleEl   = document.getElementById('inv-item-title');
    var catSelect = document.getElementById('inv-i-category');
    var condSeg   = document.getElementById('inv-i-cond-seg');
    var condHidden = document.getElementById('inv-i-condition');
    var acqInput  = document.getElementById('inv-i-acquired');
    var fpAcq     = null;

    /* Held-by player-picker (scoped player search inside the modal). */
    var heldByInput = document.getElementById('inv-i-heldby');
    var heldById    = document.getElementById('inv-i-heldby-player-id');
    var heldByRes   = document.getElementById('inv-i-heldby-results');
    var heldByTimer = null;
    var heldByChip  = document.getElementById('inv-i-heldby-chip');
    var heldByChipLabel = document.getElementById('inv-i-heldby-chip-label');
    /* Linked-player chip: shown only while held_by_player_id > 0; free text shows none. */
    function showHeldByChip(label) {
        if (!heldByChip) { return; }
        heldByChipLabel.textContent = label || heldByInput.value;
        heldByChip.hidden = false;
    }
    function hideHeldByChip() { if (heldByChip) { heldByChip.hidden = true; heldByChipLabel.textContent = ''; } }
    function unlinkHeldBy() {
        if (heldById) { heldById.value = '0'; }
        hideHeldByChip();
        heldByInput.focus();
    }

    // Canonical fixed-position placement for autocomplete dropdowns inside modals.
    function tnFixedAcPosition(inputEl, dropdownEl) {
        var rect = inputEl.getBoundingClientRect();
        dropdownEl.style.top   = (rect.bottom + 2) + 'px';
        dropdownEl.style.left  = rect.left + 'px';
        dropdownEl.style.width = rect.width + 'px';
    }
    function openHeldByResults() { tnFixedAcPosition(heldByInput, heldByRes); heldByRes.classList.add('kn-ac-open'); }
    function closeHeldByResults() { clearTimeout(heldByTimer); if (heldByRes) { heldByRes.classList.remove('kn-ac-open'); heldByRes.innerHTML = ''; } }
    function runHeldBySearch() {
        clearTimeout(heldByTimer);
        if (heldById) { heldById.value = '0'; }   // typing free-text invalidates a prior pick
        hideHeldByChip();
        var term = heldByInput.value.trim();
        if (term.length < 2) { closeHeldByResults(); return; }
        heldByTimer = setTimeout(function () {
            // UIR already ends in '?Route='; query params use '&' (a 2nd '?' empties $_GET['q']).
            var url = cfg.uir + 'KingdomAjax/playersearch/' + (cfg.kingdomId || 0) +
                '&scope=own&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    // Drop a stale response: the input changed (or blurred) while it was in flight.
                    if (heldByInput.value.trim() !== term || document.activeElement !== heldByInput) { return; }
                    if (!Array.isArray(data) || !data.length) {
                        heldByRes.innerHTML = '<div class="kn-ac-item inv-ac-empty">No players found</div>';
                        openHeldByResults();
                        return;
                    }
                    heldByRes.innerHTML = data.map(function (p) {
                        var meta = (p.KAbbr || '') + (p.PAbbr ? ':' + p.PAbbr : '');
                        return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId +
                            '" data-name="' + encodeURIComponent(p.Persona || '') + '" data-meta="' + encodeURIComponent(meta) + '">' +
                            escapeHtml(p.Persona || '') + ' <span class="inv-ac-meta">(' + escapeHtml(meta) + ')</span>' +
                            (p.Active === 0 ? ' <span class="inv-ac-meta">&mdash; inactive</span>' : '') + '</div>';
                    }).join('');
                    openHeldByResults();
                })
                .catch(function () { closeHeldByResults(); });
        }, 250);
    }
    function chooseHeldBy(item) {
        if (!item) { return; }
        heldByInput.value = decodeURIComponent(item.getAttribute('data-name') || '');
        if (heldById) { heldById.value = item.getAttribute('data-id') || '0'; }
        var meta = decodeURIComponent(item.getAttribute('data-meta') || '');
        showHeldByChip(heldByInput.value + (meta ? ' (' + meta + ')' : ''));
        closeHeldByResults();
    }
    function heldByKeyNav(e) {
        if (!heldByRes) { return; }
        if (e.key === 'Tab') { closeHeldByResults(); return; }
        var items = heldByRes.querySelectorAll('.kn-ac-item[data-id]');
        if (!items.length) { return; }
        var cur = heldByRes.querySelector('.kn-ac-item.kn-ac-focused');
        var idx = -1;
        for (var i = 0; i < items.length; i++) { if (items[i] === cur) { idx = i; break; } }
        if (e.key === 'ArrowDown') { e.preventDefault(); if (cur) { cur.classList.remove('kn-ac-focused'); } idx = Math.min(idx + 1, items.length - 1); items[idx].classList.add('kn-ac-focused'); items[idx].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (cur) { cur.classList.remove('kn-ac-focused'); } idx = Math.max(idx - 1, 0); items[idx].classList.add('kn-ac-focused'); items[idx].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'Enter' && cur) { e.preventDefault(); chooseHeldBy(cur); }
        else if (e.key === 'Escape') { closeHeldByResults(); }
    }

    /* Build the category <select> (flat key=>label map). */
    function buildCategoryOptions() {
        if (!catSelect) { return; }
        var cats = cfg.categories || {};
        catSelect.innerHTML = '<option value="">Select…</option>';
        Object.keys(cats).forEach(function (k) {
            var opt = document.createElement('option');
            opt.value = k; opt.textContent = cats[k];
            catSelect.appendChild(opt);
        });
    }

    /* Build the condition segmented control. */
    function buildConditionSeg() {
        if (!condSeg) { return; }
        var labels = cfg.conditionLabels || {};
        condSeg.innerHTML = '';
        Object.keys(labels).forEach(function (k) {
            var b = document.createElement('button');
            b.type = 'button'; b.setAttribute('data-cond', k); b.textContent = labels[k];
            condSeg.appendChild(b);
        });
    }
    function setCondition(cond) {
        cond = cond || 'good';
        condHidden.value = cond;
        Array.prototype.forEach.call(condSeg.querySelectorAll('button'), function (b) {
            b.classList.toggle('rp-seg-on', b.getAttribute('data-cond') === cond);
        });
    }

    function clearErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('.rp-field.rp-has-err'), function (f) { f.classList.remove('rp-has-err'); });
        var fe = form.querySelector('[data-err="_form"]');
        if (fe) { fe.textContent = ''; fe.style.display = 'none'; }
    }
    function markError(field, msg) {
        if (field === '_form') {
            var fe = form.querySelector('[data-err="_form"]');
            if (fe) { fe.textContent = msg; fe.style.display = 'block'; }
            return;
        }
        var errEl = form.querySelector('[data-err="' + field + '"]');
        if (errEl) {
            if (msg) { errEl.textContent = msg; }
            var wrap = errEl.closest('.rp-field');
            if (wrap) { wrap.classList.add('rp-has-err'); }
        }
    }

    /* The id being edited ('' = add) and the quantity the edit form loaded. Held here,
       not read back from the hidden field, so a failed load can never become an additem. */
    var editId = '';
    var loadedQty = null;
    var changeNoteField = document.getElementById('inv-i-change-note-field');
    var changeNoteEl    = document.getElementById('inv-i-change-note');
    function qtyLowered() {
        return !!editId && loadedQty !== null && parseInt(document.getElementById('inv-i-quantity').value, 10) < loadedQty;
    }
    function syncChangeNote() {
        if (changeNoteField) { changeNoteField.style.display = qtyLowered() ? '' : 'none'; }
    }

    function openModal(isEdit) {
        clearErrors();
        rememberOpener(overlay);
        titleEl.textContent = isEdit ? 'Edit Item' : 'Add Item';
        document.getElementById('inv-i-save').textContent = isEdit ? 'Save Changes' : 'Save Item';
        overlay.classList.add('rp-open');
        overlay.setAttribute('aria-hidden', 'false');
        if (!fpAcq && window.flatpickr) {
            fpAcq = flatpickr(acqInput, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: false });
        }
    }
    function closeModal() {
        overlay.classList.remove('rp-open');
        overlay.setAttribute('aria-hidden', 'true');
        closeHeldByResults();
        editId = '';
        returnFocus(overlay);
    }

    function resetForm() {
        form.reset();
        document.getElementById('inv-i-quantity').value = '1';
        setCondition('good');
        if (heldByInput) { heldByInput.value = ''; }
        if (heldById) { heldById.value = '0'; }
        hideHeldByChip();
        if (fpAcq) { fpAcq.clear(); } else { acqInput.value = ''; }
        loadedQty = null;
        if (changeNoteEl) { changeNoteEl.value = ''; }
        syncChangeNote();
        closeHeldByResults();
        clearErrors();
    }

    function fillForm(e) {
        document.getElementById('inv-i-name').value = e.name || '';
        catSelect.value = e.category || '';
        document.getElementById('inv-i-quantity').value = (e.quantity != null) ? e.quantity : 1;
        setCondition(e.condition || 'good');
        document.getElementById('inv-i-unit-value').value = (e.unit_value != null && e.unit_value !== '') ? Number(e.unit_value).toFixed(2) : '';
        document.getElementById('inv-i-location').value = e.location || '';
        heldByInput.value = e.held_by || '';
        if (heldById) { heldById.value = (parseInt(e.held_by_player_id || 0, 10) > 0) ? String(parseInt(e.held_by_player_id, 10)) : '0'; }
        if (heldById && heldById.value !== '0') { showHeldByChip(e.held_by_player_label || e.held_by || ''); } else { hideHeldByChip(); }
        document.getElementById('inv-i-notes').value = e.notes || '';
        var d = e.acquired_date || '';
        // Normalize a 'YYYY-MM-DD HH:MM:SS' value down to the date part.
        if (d && d.length >= 10) { d = d.slice(0, 10); }
        if (fpAcq) { if (d) { fpAcq.setDate(d, true); } else { fpAcq.clear(); } }
        else { acqInput.value = d; }
    }

    function openAdd() {
        editId = '';
        resetForm();
        openModal(false);
        document.getElementById('inv-i-save').disabled = false;
        document.getElementById('inv-i-name').focus();
    }

    function openEdit(id) {
        id = String(id);
        resetForm();
        openModal(true);
        editId = id;
        var saveBtn = document.getElementById('inv-i-save');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Loading\u2026';
        fetch(cfg.ajax + 'getitem&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (editId !== id) { return; }   // modal closed or another item opened meanwhile
                saveBtn.textContent = 'Save Changes';
                if (j.status === 0 && j.detail) {
                    fillForm(j.detail);
                    loadedQty = parseInt(j.detail.quantity, 10);
                    if (isNaN(loadedQty)) { loadedQty = null; }
                    saveBtn.disabled = false;
                    document.getElementById('inv-i-name').focus();
                } else {
                    markError('_form', (j.error || 'Could not load this item.'));   // Save stays disabled
                }
            })
            .catch(function () {
                if (editId !== id) { return; }
                saveBtn.textContent = 'Save Changes';
                markError('_form', 'Could not load this item \u2014 close and try again.');
            });
    }

    function submitItem(ev) {
        ev.preventDefault();
        var saveBtnEl = document.getElementById('inv-i-save');
        if (saveBtnEl.disabled) { return; }   // still loading, or the load failed
        clearErrors();
        var id     = editId;
        var isEdit = !!id;
        var data = {
            name:              document.getElementById('inv-i-name').value.trim(),
            category:          catSelect.value,
            quantity:          document.getElementById('inv-i-quantity').value,
            condition:         condHidden.value,
            unit_value:        document.getElementById('inv-i-unit-value').value || '0',
            location:          document.getElementById('inv-i-location').value,
            held_by:           heldByInput.value,
            held_by_player_id: heldById ? (heldById.value || '0') : '0',
            acquired_date:     acqInput.value || (fpAcq && fpAcq.input ? fpAcq.input.value : ''),
            notes:             document.getElementById('inv-i-notes').value
        };

        // Client-side guards mirroring the lib's validation (server re-validates regardless).
        var ok = true;
        if (data.name === '')                          { markError('name'); ok = false; }
        if (!data.category)                            { markError('category'); ok = false; }
        if (!(parseInt(data.quantity, 10) >= 1))       { markError('quantity'); ok = false; }
        if (isEdit) {
            data.change_note = qtyLowered() && changeNoteEl ? changeNoteEl.value.trim() : '';
            if (loadedQty !== null) { data.expected_quantity = loadedQty; }
            if (qtyLowered() && data.change_note === '') { markError('change_note'); ok = false; }
        }
        if (!ok) { return; }

        var saveBtn = document.getElementById('inv-i-save');
        saveBtn.disabled = true;
        var action = isEdit ? 'edititem' : 'additem';
        if (isEdit) { data.id = id; }
        postForm(action, data).then(function (j) {
            saveBtn.disabled = false;
            if (j.status === 0) {
                closeModal();
                if (!isEdit) { state.page = 1; }
                refreshAll();
                loadLocations();
                toast((isEdit ? 'Saved ' : 'Added ') + quoted(data.name));
            } else {
                markError('_form', j.error || 'Could not save this item.');
            }
        }).catch(function () {
            saveBtn.disabled = false;
            markError('_form', 'Network error — please try again.');
        });
    }

    /* =====================================================
       Remove / restore / delete flows
       ----------------------------------------------------
       Remove  : opens a modal (required reason + optional note) → POST removeitem.
       Restore : tnConfirm → POST restoreitem (shown when the status filter = Removed).
       Delete  : modal with a required note → POST deleteitem (mis-entry correction).
       Split   : modal (units) → POST splititem. Undelete: tnConfirm → POST undeleteitem.
       ===================================================== */
    var removeOverlay = document.getElementById('inv-remove-overlay');
    var removeForm    = document.getElementById('inv-remove-form');
    var removeIdEl    = document.getElementById('inv-r-id');
    var removeReason  = document.getElementById('inv-r-reason');
    var removeNote    = document.getElementById('inv-r-note');
    var removeUnits   = document.getElementById('inv-r-units');
    var removeUnitsField = document.getElementById('inv-r-units-field');
    var removeQty     = 1;
    var removeRow     = {};
    var remDateEl     = document.getElementById('inv-r-date');
    var remValueEl    = document.getElementById('inv-r-value');
    var remToEl       = document.getElementById('inv-r-to');
    var remBookEl     = document.getElementById('inv-r-book');
    var remTreasury   = document.getElementById('inv-r-treasury');
    var remRecCb      = document.getElementById('inv-r-rec');
    var remTFields    = document.getElementById('inv-r-treasury-fields');
    var remTCat       = document.getElementById('inv-r-tcat');
    var remTMethod    = document.getElementById('inv-r-tmethod');
    var fpRem         = null;
    function todayYmd() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    function removeUnitsNow() {
        var u = removeQty >= 2 && removeUnits ? parseInt(removeUnits.value, 10) : removeQty;
        return (u >= 1 && u <= removeQty) ? u : removeQty;
    }
    /* Book value (units x unit value) beside the proceeds, for comparison. */
    function syncRemoveBook() {
        if (!remBookEl) { return; }
        var u = removeUnitsNow(), uv = Number(removeRow.UnitValue) || 0;
        var txt = 'Book value: ' + money(u * uv) + ' (' + u + ' \u00d7 ' + money(uv) + ').';
        if (removeQty >= 2 && u < removeQty) { txt += ' Proceeds are for the ' + u + ' unit' + (u === 1 ? '' : 's') + ' being removed only.'; }
        remBookEl.textContent = txt;
    }
    /* Sold: proceeds required + optional Treasury income entry (method required when checked). */
    function syncRemoveSold() {
        var sold = removeReason && removeReason.value === 'sold';
        var opt = document.getElementById('inv-r-value-opt');
        if (opt) { opt.textContent = sold ? '(required)' : '(optional)'; }
        if (remTreasury) { remTreasury.style.display = sold ? '' : 'none'; }
        if (remTFields) { remTFields.style.display = (sold && remRecCb && remRecCb.checked) ? '' : 'none'; }
    }

    function clearRemoveErrors() {
        if (!removeForm) { return; }
        Array.prototype.forEach.call(removeForm.querySelectorAll('.rp-field.rp-has-err'), function (f) { f.classList.remove('rp-has-err'); });
        var fe = removeForm.querySelector('[data-err="_form"]');
        if (fe) { fe.textContent = ''; fe.style.display = 'none'; }
    }
    function markRemoveError(field, msg) {
        if (!removeForm) { return; }
        if (field === '_form') {
            var fe = removeForm.querySelector('[data-err="_form"]');
            if (fe) { fe.textContent = msg; fe.style.display = 'block'; }
            return;
        }
        var errEl = removeForm.querySelector('[data-err="' + field + '"]');
        if (errEl) {
            if (msg) { errEl.textContent = msg; }
            var wrap = errEl.closest('.rp-field');
            if (wrap) { wrap.classList.add('rp-has-err'); }
        }
    }
    function closeRemoveModal() {
        if (!removeOverlay) { return; }
        removeOverlay.classList.remove('rp-open');
        removeOverlay.setAttribute('aria-hidden', 'true');
        returnFocus(removeOverlay);
    }
    function removeItem(id) {
        if (!removeOverlay) { return; }
        clearRemoveErrors();
        rememberOpener(removeOverlay);
        if (removeForm) { removeForm.reset(); }
        if (removeIdEl) { removeIdEl.value = id; }
        if (removeReason) { removeReason.value = ''; }
        if (removeNote) { removeNote.value = ''; }
        removeRow = rowsById[id] || {};
        if (remTCat && remTCat.querySelector('option[value="income_other"]')) { remTCat.value = 'income_other'; }
        if (!fpRem && window.flatpickr && remDateEl) {
            fpRem = flatpickr(remDateEl, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: false, maxDate: 'today' });
        }
        if (fpRem) { fpRem.setDate(todayYmd(), false); } else if (remDateEl) { remDateEl.value = todayYmd(); }
        // Units to remove: default = the whole quantity; hidden when there is only one.
        removeQty = Math.max(1, Number((rowsById[id] || {}).Quantity) || 1);
        if (removeUnits) {
            removeUnits.max = String(removeQty);
            removeUnits.value = String(removeQty);
            removeUnits.disabled = removeQty < 2;
            var hint = document.getElementById('inv-r-units-hint');
            if (hint) { hint.textContent = 'Of ' + removeQty + '. Removing fewer splits those units off into their own removed line; the rest stay active.'; }
        }
        if (removeUnitsField) { removeUnitsField.style.display = removeQty < 2 ? 'none' : ''; }
        syncRemoveBook();
        syncRemoveSold();
        removeOverlay.classList.add('rp-open');
        removeOverlay.setAttribute('aria-hidden', 'false');
        if (removeReason) { removeReason.focus(); }
    }
    function submitRemove(ev) {
        ev.preventDefault();
        clearRemoveErrors();
        var id     = removeIdEl ? removeIdEl.value : '';
        var reason = removeReason ? removeReason.value : '';
        var note   = removeNote ? removeNote.value : '';
        if (!reason) { markRemoveError('removal_reason'); return; }   // block submit with no reason
        var units = removeQty;
        if (removeUnits && removeQty >= 2) {
            units = parseInt(removeUnits.value, 10);
            if (!(units >= 1 && units <= removeQty)) { markRemoveError('units', 'Enter between 1 and ' + removeQty + ' units.'); return; }
        }
        var data = {
            id: id, removal_reason: reason, removal_note: note, units: units,
            disposal_date: (remDateEl && remDateEl.value) || todayYmd(),
            disposal_value: remValueEl ? remValueEl.value.trim() : '',
            disposed_to: remToEl ? remToEl.value.trim() : ''
        };
        if (data.disposal_date > todayYmd()) { markRemoveError('disposal_date'); return; }
        var proceeds = Number(data.disposal_value) || 0;
        if (data.disposal_value !== '' && !(proceeds >= 0)) { markRemoveError('disposal_value', 'Enter a valid amount.'); return; }
        if (reason === 'sold' && !(proceeds > 0)) { markRemoveError('disposal_value', 'Enter the sale proceeds.'); return; }
        if (reason === 'sold' && remRecCb && remRecCb.checked && proceeds > 0) {
            if (!remTMethod || !remTMethod.value) { markRemoveError('treasury_method'); return; }
            data.record_treasury = '1';
            data.treasury_category = (remTCat && remTCat.value) || 'income_other';
            data.treasury_method = remTMethod.value;
        }

        var name = removeRow.Name || '';
        var full = units >= removeQty;
        var saveBtn = document.getElementById('inv-r-save');
        if (saveBtn) { saveBtn.disabled = true; }
        postForm('removeitem', data).then(function (j) {
            if (saveBtn) { saveBtn.disabled = false; }
            if (j.status === 0) {
                closeRemoveModal(); refreshAll();
                if (full) {
                    toast('Moved ' + quoted(name) + ' to Removed', { actions: [
                        { label: 'Undo', fn: function () { undoPost('restoreitem', { id: id }, 'Could not undo the removal.', warnTreasuryOnRestore); } },
                        { label: 'View', fn: function () { setStatus('removed'); } }
                    ] });
                } else {
                    toast('Removed ' + units + ' unit' + (units === 1 ? '' : 's') + ' of ' + quoted(name), { actions: [
                        { label: 'View', fn: function () { setStatus('removed'); } }
                    ] });
                }
            }
            else { markRemoveError('_form', j.error || 'Could not remove this item.'); }
        }).catch(function () {
            if (saveBtn) { saveBtn.disabled = false; }
            markRemoveError('_form', 'Network error — please try again.');
        });
    }
    function bindRemoveModal() {
        if (!removeOverlay) { return; }
        Array.prototype.forEach.call(removeOverlay.querySelectorAll('[data-inv-remove-close]'), function (b) {
            b.addEventListener('click', closeRemoveModal);
        });
        removeOverlay.addEventListener('click', function (e) { if (e.target === removeOverlay) { closeRemoveModal(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && removeOverlay.classList.contains('rp-open')) { closeRemoveModal(); }
        });
        if (removeForm) { removeForm.addEventListener('submit', submitRemove); }
        if (removeUnits) { removeUnits.addEventListener('input', syncRemoveBook); }
        if (remRecCb) { remRecCb.addEventListener('change', syncRemoveSold); }
        // Clear the reason error as soon as a reason is chosen.
        if (removeReason) {
            removeReason.addEventListener('change', function () {
                syncRemoveSold();
                if (removeReason.value) {
                    var wrap = removeReason.closest('.rp-field');
                    if (wrap) { wrap.classList.remove('rp-has-err'); }
                }
            });
        }
    }
    /* A restored item whose sale was recorded in Treasury keeps that income entry: say so (sticky). */
    function warnTreasuryOnRestore(detail) {
        var te = Number((detail || {}).TreasuryEntryId) || 0;
        if (te > 0) {
            toast('This item\u2019s sale was recorded in Treasury (entry #' + te + '). Restoring it did not reverse that entry \u2014 adjust Treasury if needed.',
                { warn: true, sticky: true, link: { label: 'Open Treasury', href: treasuryUrl } });
        }
    }
    function restoreItem(id) {
        var r = rowsById[id] || {};
        invConfirm({
            title: 'Restore item',
            body: 'Return this item to active inventory?',
            confirmLabel: 'Restore',
            onConfirm: function () {
                postForm('restoreitem', { id: id }).then(function (j) {
                    if (j.status === 0) {
                        refreshAll();
                        // Undo re-removes with the prior disposal details; never re-records Treasury income.
                        var redo = {
                            id: id, removal_reason: r.RemovalReason || '', removal_note: r.RemovalNote || '',
                            units: Number(r.Quantity) || 1,
                            disposal_date: String(r.DisposalDate || r.RemovedAt || '').slice(0, 10),
                            disposal_value: (Number(r.DisposalValue) || 0) > 0 ? String(r.DisposalValue) : '',
                            disposed_to: r.DisposedTo || ''
                        };
                        toast('Restored ' + quoted(r.Name), { actions: [
                            { label: 'Undo', fn: function () { undoPost('removeitem', redo, 'Could not undo the restore.'); } }
                        ] });
                        warnTreasuryOnRestore(j.detail || {});
                    }
                    else { invConfirm({ title: 'Restore failed', body: (j.error || 'Could not restore this item.'), confirmLabel: 'OK', cancelLabel: 'Close' }); }
                }).catch(function () {
                    invConfirm({ title: 'Restore failed', body: 'Network error \u2014 please try again.', confirmLabel: 'OK', cancelLabel: 'Close' });
                });
            }
        });
    }
    function undeleteItem(id) {
        invConfirm({
            title: 'Undelete record',
            body: 'Bring this record back into the register?',
            confirmLabel: 'Undelete',
            onConfirm: function () {
                var uname = (rowsById[id] || {}).Name;
                postForm('undeleteitem', { id: id }).then(function (j) {
                    if (j.status === 0) { refreshAll(); toast('Undeleted ' + quoted(uname)); }
                    else { invConfirm({ title: 'Undelete failed', body: (j.error || 'Could not undelete this item.'), confirmLabel: 'OK', cancelLabel: 'Close' }); }
                }).catch(function () {
                    invConfirm({ title: 'Undelete failed', body: 'Network error \u2014 please try again.', confirmLabel: 'OK', cancelLabel: 'Close' });
                });
            }
        });
    }

    /* ---- Small form-modal helper for the Delete and Split dialogs ---- */
    function formModal(ovId, closeAttr) {
        var ov = document.getElementById(ovId);
        var fm = ov ? ov.querySelector('form') : null;
        var m = {
            ov: ov, form: fm,
            clear: function () {
                Array.prototype.forEach.call(fm.querySelectorAll('.rp-field.rp-has-err'), function (f) { f.classList.remove('rp-has-err'); });
                var fe = fm.querySelector('[data-err="_form"]');
                if (fe) { fe.textContent = ''; fe.style.display = 'none'; }
            },
            err: function (field, msg) {
                var el = fm.querySelector('[data-err="' + field + '"]');
                if (!el) { return; }
                if (field === '_form') { el.textContent = msg; el.style.display = 'block'; return; }
                if (msg) { el.textContent = msg; }
                var wrap = el.closest('.rp-field'); if (wrap) { wrap.classList.add('rp-has-err'); }
            },
            open: function () {
                m.clear(); rememberOpener(ov);
                ov.classList.add('rp-open'); ov.setAttribute('aria-hidden', 'false');
            },
            close: function () {
                ov.classList.remove('rp-open'); ov.setAttribute('aria-hidden', 'true');
                returnFocus(ov);
            }
        };
        if (ov) {
            Array.prototype.forEach.call(ov.querySelectorAll('[' + closeAttr + ']'), function (b) { b.addEventListener('click', m.close); });
            ov.addEventListener('click', function (e) { if (e.target === ov) { m.close(); } });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && ov.classList.contains('rp-open') && topOverlay() === ov) { m.close(); }
            });
        }
        return m;
    }
    function topOverlay() {
        var open = document.querySelectorAll('.rp-modal-overlay.rp-open');
        return open.length ? open[open.length - 1] : null;
    }

    /* Delete: required "why is this a mis-entry" note; server errors shown in the modal. */
    var delModal = formModal('inv-delete-overlay', 'data-inv-delete-close');
    var delId = '';
    var delNote = document.getElementById('inv-d-note');
    function deleteItem(id) {
        if (!delModal.ov) { return; }
        delId = String(id);
        delNote.value = '';
        delModal.open();
        delNote.focus();
    }
    if (delModal.form) {
        delModal.form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            delModal.clear();
            var note = delNote.value.trim();
            if (!note) { delModal.err('delete_note'); return; }
            var btn = document.getElementById('inv-d-save');
            btn.disabled = true;
            var did = delId, dname = (rowsById[delId] || {}).Name;
            postForm('deleteitem', { id: did, delete_note: note }).then(function (j) {
                btn.disabled = false;
                if (j.status === 0) {
                    delModal.close(); refreshAll();
                    toast('Deleted ' + quoted(dname), { actions: [
                        { label: 'Undo', fn: function () { undoPost('undeleteitem', { id: did }, 'Could not undo the delete.'); } }
                    ] });
                }
                else { delModal.err('_form', j.error || 'Could not delete this item.'); }
            }).catch(function () {
                btn.disabled = false;
                delModal.err('_form', 'Network error — please try again.');
            });
        });
    }

    /* Split: move 1..quantity-1 units into a new, otherwise identical line item. */
    var splitModal = formModal('inv-split-overlay', 'data-inv-split-close');
    var splitId = '', splitQty = 0;
    var splitUnits = document.getElementById('inv-s-units');
    function splitItem(id) {
        if (!splitModal.ov) { return; }
        var r = rowsById[id] || {};
        splitId = String(id);
        splitQty = Number(r.Quantity) || 0;
        if (splitQty < 2) { return; }
        document.getElementById('inv-s-intro').textContent = '“' + (r.Name || 'This item') + '” has ' + splitQty +
            ' units. Move some of them to a new line item — then edit that line to give it its own holder, location, or condition.';
        document.getElementById('inv-s-hint').textContent = 'Between 1 and ' + (splitQty - 1) + '.';
        splitUnits.min = '1';
        splitUnits.max = String(splitQty - 1);
        splitUnits.value = '1';
        splitModal.open();
        splitUnits.focus();
    }
    if (splitModal.form) {
        splitModal.form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            splitModal.clear();
            var units = parseInt(splitUnits.value, 10);
            if (!(units >= 1 && units <= splitQty - 1)) { splitModal.err('units', 'Enter between 1 and ' + (splitQty - 1) + ' units.'); return; }
            var btn = document.getElementById('inv-s-save');
            btn.disabled = true;
            var sname = (rowsById[splitId] || {}).Name;
            postForm('splititem', { id: splitId, units: units }).then(function (j) {
                btn.disabled = false;
                if (j.status === 0) {
                    splitModal.close(); refreshAll();
                    toast('Split ' + units + ' unit' + (units === 1 ? '' : 's') + ' of ' + quoted(sname) + ' into a new line item');
                }
                else { splitModal.err('_form', j.error || 'Could not split this item.'); }
            }).catch(function () {
                btn.disabled = false;
                splitModal.err('_form', 'Network error — please try again.');
            });
        });
    }

    /* =====================================================
       Verification: one row (Actions menu) or everything the current filters match.
       ===================================================== */
    function verifyItem(id) {
        var vid = parseInt(id, 10);
        if (!(vid > 0)) { return; }
        var name = (rowsById[id] || {}).Name;
        postForm('verifyitems', { ids: String(vid) }).then(function (j) {
            if (j.status === 0) { refreshAll(); toast('Marked ' + quoted(name) + ' verified'); }
            else { toast(j.error || 'Could not mark this item verified.', { warn: true }); }
        }).catch(function () { toast('Network error \u2014 could not mark this item verified.', { warn: true }); });
    }
    function verifyAllShown() {
        if (state.status !== 'active' || !(state.total > 0)) { return; }
        var n = state.total;
        invConfirm({
            title: 'Mark verified',
            body: 'Mark ' + n + ' item' + (n === 1 ? '' : 's') + ' matching the current filters as verified today?',
            confirmLabel: 'Mark verified',
            onConfirm: function () {
                var vf = filterParams();
                vf.by_filter = 1;   // the server requires an explicit opt-in for a filter-wide verify
                postForm('verifyitems', vf).then(function (j) {
                    if (j.status === 0) {
                        refreshAll();
                        var c = Number((j.detail || {}).Count);
                        if (isNaN(c)) { c = n; }
                        toast('Marked ' + c + ' item' + (c === 1 ? '' : 's') + ' verified');
                    } else { toast(j.error || 'Could not mark these items verified.', { warn: true }); }
                }).catch(function () { toast('Network error \u2014 could not mark these items verified.', { warn: true }); });
            }
        });
    }

    /* =====================================================
       Count Sheet: a print-friendly new window (same-origin about:blank, built via the
       DOM) of ACTIVE items matching the filters, by location, with blank count columns.
       ===================================================== */
    function filterSummaryText() {
        var parts = [];
        if (state.q) { parts.push('Search: ' + state.q); }
        if (state.category) { parts.push('Category: ' + catLabel(state.category)); }
        if (state.condition) { parts.push('Condition: ' + condLabel(state.condition)); }
        if (state.location) { parts.push('Location: ' + state.location); }
        if (state.held_by_player_id > 0 || state.held_by) { parts.push('Held by: ' + (state.holderLabel || state.held_by)); }
        if (state.stale) { parts.push('Not verified in 12 months'); }
        return parts.length ? parts.join(' \u00b7 ') : 'All active items';
    }
    function openCountSheet() {
        var w = window.open('', '_blank');
        if (!w) { toast('Your browser blocked the count sheet window \u2014 allow pop-ups for this site and try again.', { warn: true }); return; }
        var doc = w.document;
        doc.open();
        doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Count Sheet</title></head><body><p>Loading\u2026</p></body></html>');
        doc.close();
        var q = new URLSearchParams(filterParams());
        q.set('status', 'active'); q.set('page', 1); q.set('per', 500); q.set('sort', 'location'); q.set('dir', 'asc');
        var filtersTxt = filterSummaryText();
        fetch(cfg.ajax + 'items&' + q.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (w.closed) { return; }
                if (j.status !== 0 || !j.detail) { doc.body.textContent = j.error || 'Could not load the items.'; return; }
                var rows = j.detail.Rows || [], total = Number(j.detail.Total) || rows.length;
                var title = (cfg.orgName ? cfg.orgName + ' \u2014 ' : '') + 'Inventory Count Sheet';
                var today = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                var body = rows.map(function (r) {
                    return '<tr><td>' + escapeHtml(r.Location || '') + '</td><td>' + escapeHtml(r.Name || '') + '</td><td>' +
                        escapeHtml(catLabel(r.Category)) + '</td><td>' + escapeHtml(condLabel(r.Condition)) + '</td><td class="n">' +
                        (Number(r.Quantity) || 0) + '</td><td></td><td></td><td></td></tr>';
                }).join('');
                doc.open();
                doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) + '</title><style>' +
                    'body{font:13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111;background:#fff;margin:24px}' +
                    '.bar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:14px}' +
                    '.t{font-size:18px;font-weight:700;margin:0 0 4px}.m{color:#444;margin:0}' +
                    'button{font:inherit;padding:6px 14px;cursor:pointer}' +
                    'table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:6px 8px;text-align:left;vertical-align:top}' +
                    'th{background:#eee;font-size:11px;text-transform:uppercase}td.n{text-align:right}' +
                    'th.w{width:70px}th.nt{width:22%}tr{page-break-inside:avoid}' +
                    '@media print{.np{display:none}body{margin:0}}</style></head><body>' +
                    '<div class="bar"><div><p class="t">' + escapeHtml(title) + '</p>' +
                    '<p class="m">' + escapeHtml(today) + ' \u00b7 ' + escapeHtml(filtersTxt) + '</p>' +
                    '<p class="m">' + (total > rows.length ? escapeHtml('Showing the first ' + rows.length + ' of ' + total + ' items \u2014 narrow the filters to print the rest.')
                        : escapeHtml(rows.length + ' item' + (rows.length === 1 ? '' : 's'))) + '</p></div>' +
                    '<button type="button" class="np" id="cs-print">Print</button></div>' +
                    '<table><thead><tr><th>Location</th><th>Item</th><th>Category</th><th>Condition</th><th class="w">Expected Qty</th>' +
                    '<th class="w">Counted</th><th class="w">Initials</th><th class="nt">Notes</th></tr></thead><tbody>' +
                    (body || '<tr><td colspan="8">No active items match these filters.</td></tr>') + '</tbody></table></body></html>');
                doc.close();
                var pb = doc.getElementById('cs-print');
                if (pb) { pb.addEventListener('click', function () { w.print(); }); }
            })
            .catch(function () { if (!w.closed) { doc.body.textContent = 'Could not load the items \u2014 close this window and try again.'; } });
    }

    /* =====================================================
       History (per item) + Change Log (org-wide)
       ===================================================== */
    var ACTION_LABELS = { create: 'Created', edit: 'Edited', remove: 'Removed', restore: 'Restored',
        'delete': 'Deleted', undelete: 'Undeleted', split: 'Split', verify: 'Verified' };
    var HIST_FIELDS = [
        ['name', 'Name'], ['category', 'Category'], ['quantity', 'Quantity'], ['condition', 'Condition'],
        ['unit_value', 'Unit value'], ['location', 'Location'], ['held_by', 'Held by'], ['notes', 'Notes'],
        ['removal_reason', 'Removal reason'], ['removal_note', 'Removal note']
    ];
    function reasonLabel(k) { return (cfg.removalReasons && cfg.removalReasons[k]) || k || ''; }
    function histVal(field, v) {
        if (v == null || v === '') { return ''; }
        if (field === 'category') { return catLabel(v); }
        if (field === 'condition') { return condLabel(v); }
        if (field === 'unit_value') { return money(v); }
        if (field === 'removal_reason') { return reasonLabel(v); }
        return String(v);
    }
    function actionBadge(a) {
        return '<span class="rp-badge inv-badge-act-' + escapeHtml(a) + '">' + escapeHtml(ACTION_LABELS[a] || a) + '</span>';
    }
    function histLine(label, html) {
        return '<div class="inv-hist-change"><span class="inv-hist-field">' + escapeHtml(label) + ':</span> ' + html + '</div>';
    }
    function histNote(label, text) {
        return text ? '<div class="inv-hist-note"><span class="inv-hist-field">' + escapeHtml(label) + ':</span> ' + escapeHtml(text) + '</div>' : '';
    }
    function histEntryBody(e) {
        var b = e.Before || null, a = e.After || null, out = '';
        // Split / partial remove store a nested {source, new_row|removed_row, units}, not a flat snapshot.
        if (a && a.units != null && (a.new_row || a.removed_row)) {
            var n = Number(a.units) || 0;
            if (e.Action === 'split') {
                out += histLine('Split', escapeHtml(n + ' unit' + (n === 1 ? '' : 's') + ' split off into a new line item'));
            } else {
                var rr = a.removed_row || {};
                out += histLine('Removed', escapeHtml(n + ' unit' + (n === 1 ? '' : 's') + ' removed into a separate removed line'));
                if (rr.removal_reason) { out += histLine('Reason', escapeHtml(reasonLabel(rr.removal_reason))); }
                out += histNote('Note', rr.removal_note || '');
            }
            if (a.source && a.source.quantity != null) { out += histLine('Quantity left', escapeHtml(String(a.source.quantity))); }
            return out;
        }
        if (e.Action === 'verify') {
            var vAt = a && a.last_verified_at ? fmtDateTime(a.last_verified_at) : '';
            return histLine('Marked verified', escapeHtml(vAt || '\u2014'));
        }
        if (!b && a) {
            // Created: list the starting values.
            HIST_FIELDS.forEach(function (f) {
                var v = histVal(f[0], a[f[0]]);
                if (v !== '') { out += histLine(f[1], escapeHtml(v)); }
            });
        } else if (b && a) {
            HIST_FIELDS.forEach(function (f) {
                // Only fields the After snapshot carries (older audit rows can be partial).
                if (!Object.prototype.hasOwnProperty.call(a, f[0])) { return; }
                var ov = histVal(f[0], b[f[0]]), nv = histVal(f[0], a[f[0]]);
                if (ov === nv) { return; }
                out += histLine(f[1], (ov !== '' ? '<span class="inv-hist-old">' + escapeHtml(ov) + '</span> → ' : '') +
                    (nv !== '' ? escapeHtml(nv) : '<em>(cleared)</em>'));
            });
        }
        if (a) {
            out += histNote('Why', a._change_note || '');
            out += histNote('Why deleted', a.delete_note || '');
        }
        return out;
    }

    var histOverlay = document.getElementById('inv-history-overlay');
    var histBody    = document.getElementById('inv-history-body');
    var histToken   = 0;
    function closeHistory() {
        histToken++;
        histOverlay.classList.remove('rp-open');
        histOverlay.setAttribute('aria-hidden', 'true');
        returnFocus(histOverlay);
    }
    function openHistory(id, name) {
        if (!histOverlay) { return; }
        var r = rowsById[id];
        name = name || (r && r.Name) || '';
        document.getElementById('inv-history-title').textContent = name ? 'History — ' + name : 'History';
        histBody.innerHTML = '<div class="inv-empty">Loading…</div>';
        rememberOpener(histOverlay);
        histOverlay.classList.add('rp-open');
        histOverlay.setAttribute('aria-hidden', 'false');
        histOverlay.querySelector('.rp-modal-close').focus();
        var tok = ++histToken;
        fetch(cfg.ajax + 'history&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (tok !== histToken) { return; }
                if (j.status !== 0 || !j.detail) {
                    histBody.innerHTML = '<div class="inv-empty">' + escapeHtml(j.error || 'Could not load the history.') + '</div>';
                    return;
                }
                var rows = j.detail.Rows || [];
                var html = '';
                var hasCreate = rows.some(function (e) { return e.Action === 'create'; });
                if (!hasCreate) {
                    html += '<div class="inv-hist-note" style="margin:0 0 10px;">This line item was split off from another item ' +
                        '(by a Split or a partial Remove). Its earlier history lives on that source item.</div>';
                }
                if (!rows.length) { html += '<div class="inv-empty">No recorded changes yet.</div>'; }
                rows.forEach(function (e) {
                    html += '<div class="inv-hist-entry"><div class="inv-hist-meta">' + actionBadge(e.Action) + ' ' +
                        '<strong>' + escapeHtml(e.ChangedByName || 'Unknown officer') + '</strong> &middot; ' +
                        escapeHtml(fmtDateTime(e.ChangedAt)) + '</div>' + histEntryBody(e) + '</div>';
                });
                histBody.innerHTML = html;
            })
            .catch(function () {
                if (tok === histToken) { histBody.innerHTML = '<div class="inv-empty">Could not load the history.</div>'; }
            });
    }

    var logOverlay = document.getElementById('inv-log-overlay');
    var logList    = document.getElementById('inv-log-list');
    var logPager   = document.getElementById('inv-log-pager');
    var logAction  = document.getElementById('inv-log-action');
    var logState   = { page: 1, per: 50, action: '' };
    var logToken   = 0;
    function loadLog() {
        var tok = ++logToken;
        logList.innerHTML = '<div class="inv-empty">Loading…</div>';
        logPager.innerHTML = '';
        fetch(cfg.ajax + 'audit&action=' + encodeURIComponent(logState.action) + '&page=' + logState.page + '&per=' + logState.per,
            { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (tok !== logToken) { return; }
                if (j.status !== 0 || !j.detail) {
                    logList.innerHTML = '<div class="inv-empty">' + escapeHtml(j.error || 'Could not load the change log.') + '</div>';
                    return;
                }
                var d = j.detail, rows = d.Rows || [];
                var total = Number(d.Total) || 0, per = Number(d.Per) || logState.per;
                logState.page = Number(d.Page) || 1;
                if (!rows.length) {
                    logList.innerHTML = '<div class="inv-empty">No changes recorded' + (logState.action ? ' for this action.' : ' yet.') + '</div>';
                    return;
                }
                logList.innerHTML = rows.map(function (e) {
                    return '<button type="button" class="inv-log-row" data-log-item="' + (Number(e.ItemId) || 0) +
                        '" data-log-name="' + escapeHtml(e.ItemName || '') + '">' +
                        '<div class="inv-log-item">' + actionBadge(e.Action) + ' ' + escapeHtml(e.ItemName || '') + '</div>' +
                        '<div class="inv-hist-meta"><strong>' + escapeHtml(e.ChangedByName || 'Unknown officer') + '</strong> &middot; ' +
                        escapeHtml(fmtDateTime(e.ChangedAt)) + '</div></button>';
                }).join('');
                var pages = Math.max(1, Math.ceil(total / per));
                if (pages > 1) {
                    var prev = document.createElement('button');
                    prev.type = 'button'; prev.textContent = 'Prev'; prev.disabled = logState.page <= 1;
                    prev.addEventListener('click', function () { logState.page--; loadLog(); });
                    var info = document.createElement('span');
                    info.textContent = 'Page ' + logState.page + ' of ' + pages + ' · ' + total + ' change' + (total === 1 ? '' : 's');
                    var next = document.createElement('button');
                    next.type = 'button'; next.textContent = 'Next'; next.disabled = logState.page >= pages;
                    next.addEventListener('click', function () { logState.page++; loadLog(); });
                    logPager.appendChild(prev); logPager.appendChild(info); logPager.appendChild(next);
                }
            })
            .catch(function () {
                if (tok === logToken) { logList.innerHTML = '<div class="inv-empty">Could not load the change log.</div>'; }
            });
    }
    function openLog() {
        if (!logOverlay) { return; }
        rememberOpener(logOverlay);
        logState.page = 1;
        logOverlay.classList.add('rp-open');
        logOverlay.setAttribute('aria-hidden', 'false');
        logAction.focus();
        loadLog();
    }
    function closeLog() {
        logToken++;
        logOverlay.classList.remove('rp-open');
        logOverlay.setAttribute('aria-hidden', 'true');
        returnFocus(logOverlay);
    }
    function bindHistoryAndLog() {
        if (histOverlay) {
            Array.prototype.forEach.call(histOverlay.querySelectorAll('[data-inv-history-close]'), function (b) { b.addEventListener('click', closeHistory); });
            histOverlay.addEventListener('click', function (e) { if (e.target === histOverlay) { closeHistory(); } });
        }
        if (logOverlay) {
            Array.prototype.forEach.call(logOverlay.querySelectorAll('[data-inv-log-close]'), function (b) { b.addEventListener('click', closeLog); });
            logOverlay.addEventListener('click', function (e) { if (e.target === logOverlay) { closeLog(); } });
            logAction.addEventListener('change', function () { logState.action = logAction.value; logState.page = 1; loadLog(); });
            logList.addEventListener('click', function (e) {
                var row = e.target.closest('[data-log-item]');
                if (row) { openHistory(row.getAttribute('data-log-item'), row.getAttribute('data-log-name')); }
            });
            var openBtn = document.getElementById('inv-log-open');
            if (openBtn) { openBtn.addEventListener('click', openLog); }
        }
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            var top = topOverlay();
            if (top === histOverlay) { closeHistory(); }
            else if (top === logOverlay) { closeLog(); }
        });
    }

    /* =====================================================
       Row "Actions" menu: one in-page popover, keyboard accessible.
       ===================================================== */
    var actMenu = null, actToggle = null;
    function closeActMenu(refocus) {
        if (!actMenu) { return; }
        actMenu.parentNode.removeChild(actMenu);
        actMenu = null;
        if (actToggle) {
            actToggle.setAttribute('aria-expanded', 'false');
            if (refocus) { actToggle.focus(); }
        }
    }
    function openActMenu(toggle) {
        closeActMenu(false);
        var r = rowsById[toggle.getAttribute('data-actmenu')];
        if (!r) { return; }
        actToggle = toggle;
        actMenu = document.createElement('div');
        actMenu.className = 'inv-act-menu';
        actMenu.setAttribute('role', 'menu');
        // Skip actions already shown inline beside the toggle (desktop); on mobile those are hidden.
        var inlineShown = toggle.parentNode.querySelector('.inv-act-inline') &&
            toggle.parentNode.querySelector('.inv-act-inline').offsetParent !== null;
        actMenu.innerHTML = rowActions(r).filter(function (a) { return !(inlineShown && a.inline); }).map(function (a) {
            return '<button type="button" role="menuitem" ' + a.attr + '="' + a.id + '"' + (a.danger ? ' class="inv-act-danger"' : '') +
                '><i class="fas ' + a.icon + '"></i> ' + escapeHtml(a.label) + '</button>';
        }).join('');
        document.body.appendChild(actMenu);
        var rect = toggle.getBoundingClientRect();
        var w = actMenu.offsetWidth, h = actMenu.offsetHeight;
        var top = rect.bottom + 4;
        if (top + h > window.innerHeight - 8) { top = Math.max(8, rect.top - h - 4); }
        actMenu.style.top = top + 'px';
        actMenu.style.left = Math.max(8, Math.min(rect.right - w, window.innerWidth - w - 8)) + 'px';
        toggle.setAttribute('aria-expanded', 'true');
        actMenu.querySelector('button').focus();
        actMenu.addEventListener('keydown', function (e) {
            var items = Array.prototype.slice.call(actMenu.querySelectorAll('button'));
            var i = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
            else if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
            else if (e.key === 'End') { e.preventDefault(); items[items.length - 1].focus(); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeActMenu(true); }
            else if (e.key === 'Tab') { closeActMenu(false); }
        });
        actMenu.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn) { return; }
            closeActMenu(true);   // focus back on the toggle, so the modal returns focus there
            handleRowAction(btn);
        });
    }
    document.addEventListener('click', function (e) {
        if (actMenu && !actMenu.contains(e.target) && !e.target.closest('[data-actmenu]')) { closeActMenu(false); }
    });
    window.addEventListener('resize', function () { closeActMenu(false); });
    window.addEventListener('scroll', function () { closeActMenu(false); }, true);

    /* ---- wiring ---- */
    function bindModal() {
        buildCategoryOptions();
        buildConditionSeg();
        setCondition('good');

        var addBtn = document.getElementById('inv-add');
        if (addBtn) { addBtn.addEventListener('click', openAdd); }

        // Condition segmented control.
        condSeg.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-cond]'); if (b) { setCondition(b.getAttribute('data-cond')); }
        });

        // Held-by scoped player search.
        if (heldByInput) {
            heldByInput.addEventListener('input', runHeldBySearch);
            heldByInput.addEventListener('keydown', heldByKeyNav);
            heldByInput.addEventListener('blur', closeHeldByResults);
        }
        var unlinkBtn = document.getElementById('inv-i-heldby-unlink');
        if (unlinkBtn) { unlinkBtn.addEventListener('click', unlinkHeldBy); }
        if (heldByRes) {
            // Keep focus in the input while picking, so its blur-close doesn't eat the click.
            heldByRes.addEventListener('mousedown', function (e) { e.preventDefault(); });
            heldByRes.addEventListener('click', function (e) {
                var item = e.target.closest('.kn-ac-item[data-id]');
                if (item) { chooseHeldBy(item); }
            });
            // The fixed dropdown must follow its input when the modal overlay scrolls.
            overlay.addEventListener('scroll', function () {
                if (heldByRes.classList.contains('kn-ac-open')) { tnFixedAcPosition(heldByInput, heldByRes); }
            });
        }
        // Close the player dropdown on an outside click.
        document.addEventListener('click', function (e) {
            if (heldByRes && !heldByRes.contains(e.target) && e.target !== heldByInput) { closeHeldByResults(); }
        });

        // Close handlers.
        Array.prototype.forEach.call(overlay.querySelectorAll('[data-inv-close]'), function (b) {
            b.addEventListener('click', closeModal);
        });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('rp-open')) { closeModal(); }
        });
        form.addEventListener('submit', submitItem);
        document.getElementById('inv-i-quantity').addEventListener('input', syncChangeNote);
    }

    /* Row actions — shared by the inline buttons and the Actions menu. */
    function handleRowAction(el) {
        var map = [['data-edit', openEdit], ['data-split', splitItem], ['data-remove', removeItem],
            ['data-restore', restoreItem], ['data-history', openHistory], ['data-del', deleteItem],
            ['data-undelete', undeleteItem], ['data-verify', verifyItem]];
        for (var i = 0; i < map.length; i++) {
            if (el.hasAttribute(map[i][0])) { map[i][1](el.getAttribute(map[i][0])); return true; }
        }
        return false;
    }
    body.addEventListener('click', function (e) {
        var tg = e.target.closest('[data-actmenu]');
        if (tg) { if (actMenu && actToggle === tg) { closeActMenu(true); } else { openActMenu(tg); } return; }
        var hb = e.target.closest('[data-holder-filter]');
        if (hb) { var hr = rowsById[hb.getAttribute('data-holder-filter')]; if (hr) { setHolderFilter(hr); } return; }
        var btn = e.target.closest('.rp-row-actions button');
        if (btn) { handleRowAction(btn); }
    });
    /* Empty-state buttons: first-run "Add your first item" and "Clear filters". */
    empty.addEventListener('click', function (e) {
        if (e.target.closest('[data-inv-first-add]')) { openAdd(); return; }
        if (e.target.closest('[data-inv-clear]')) { var rs = document.getElementById('inv-f-reset'); if (rs) { rs.click(); } }
    });

    /* ---- public surface for later sections (charts/auto-refresh) ---- */
    window.InvApp = {
        loadItems: loadItems,
        loadSummary: loadSummary,
        refreshAll: refreshAll,
        money: money,
        confirm: invConfirm,
        postForm: postForm,
        state: state
    };

    /* ---- init ---- */
    bindFilters();
    bindModal();
    bindRemoveModal();
    bindHistoryAndLog();
    if (verifyAllBtn) { verifyAllBtn.addEventListener('click', verifyAllShown); }
    var csBtn = document.getElementById('inv-countsheet');
    if (csBtn) { csBtn.addEventListener('click', openCountSheet); }
    renderCounts((cfg.summary || {}).Counts);
    syncStatusView();
    renderChips();
    loadLocations();
    if (cfg.initialItems && cfg.initialItems.Rows) { renderRows(cfg.initialItems); }
    else { loadItems(); }
    updateSortIndicators();
})();
</script>

<script>
/* =====================================================
   Inventory — charts + live auto-refresh heartbeat
   • Value by Category : donut/pie from cfg.summary.ByCategory (key → value),
     labelled via cfg.categories.
   • Units by Condition: column chart from cfg.summary.ByCondition (cond → units),
     labelled via cfg.conditionLabels, in fixed order (new → needs_repair).
   Both re-render (destroy + rebuild — the global Highcharts 3.0.7 has no
   .update()) on 'inv:summarychanged', fired by InvApp.loadSummary after every
   filter change or CRUD refresh, so they match the stat cards' filters. A cheap `rev` poll (every ~25s + on focus) refetches
   items + summary when the server-side data changes — paused while a modal is
   open or the tab is hidden. Dark-mode-aware via the isDark() pattern.
   ===================================================== */
(function () {
    'use strict';

    var app = document.getElementById('inv-app');
    if (!app) { return; }
    var cfg = window.InvConfig || {};
    var InvApp = window.InvApp || {};

    /* Fixed condition order for the column chart (matches the lib's FIELD() sort). */
    var COND_ORDER = ['new', 'good', 'fair', 'poor', 'needs_repair'];
    /* Per-condition colours (green → amber → red as condition worsens). */
    var COND_COLORS = {
        'new': '#2f855a', 'good': '#38a169', 'fair': '#d69e2e',
        'poor': '#dd6b20', 'needs_repair': '#c53030'
    };
    /* Distinct slices for the category donut — one per category key, by the key's position in
       cfg.categories, so a category keeps its colour across filters (and new keys get one too). */
    var CAT_PALETTE = ['#4338ca', '#6366f1', '#0891b2', '#0d9488', '#7c3aed',
        '#db2777', '#ea580c', '#ca8a04', '#16a34a', '#475569',
        '#be123c', '#0369a1', '#65a30d', '#9333ea', '#b45309', '#0f766e'];
    var CAT_KEYS = Object.keys(cfg.categories || {});
    function catColor(key, fallbackIdx) {
        var i = CAT_KEYS.indexOf(key);
        return CAT_PALETTE[(i === -1 ? fallbackIdx : i) % CAT_PALETTE.length];
    }

    function isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }
    function tooltipOpts() {
        var dark = isDark();
        return {
            backgroundColor: dark ? '#1e293b' : undefined,
            borderColor:     dark ? '#334155' : undefined,
            style:           { color: dark ? '#e2e8f0' : '#333333' }
        };
    }
    function axisColors() {
        var dark = isDark();
        return {
            label: dark ? '#94a3b8' : '#666666',
            line:  dark ? '#334155' : '#e5e7eb',
            grid:  dark ? '#293548' : '#f0f0f0'
        };
    }
    function money(n) {
        return '$' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function catLabel(k) { return (cfg.categories && cfg.categories[k]) || k; }
    function condLabel(k) { return (cfg.conditionLabels && cfg.conditionLabels[k]) || k; }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* Same test as the register's empty state: any of the search/category/condition filters set. */
    function filtersActive() {
        var st = InvApp.state || {};
        return !!(st.q || st.category || st.condition || st.location || st.held_by_player_id > 0 || st.held_by || st.stale);
    }

    var catChart  = null;
    var condChart = null;

    /* ---- Value by Category (donut) ---- */
    function renderCategoryChart(byCategory) {
        if (typeof Highcharts === 'undefined') { return; }
        byCategory = byCategory || {};
        var data = [];
        Object.keys(byCategory).forEach(function (key) {
            var val = Math.abs(Number(byCategory[key]) || 0);
            if (val <= 0) { return; }
            data.push({ name: catLabel(key), y: val, color: catColor(key, data.length) });
        });
        var hostEl = document.getElementById('inv-chart-category');
        if (!hostEl) { return; }

        if (!data.length) {
            if (catChart) { catChart.destroy(); catChart = null; }
            hostEl.innerHTML = '<div class="inv-chart-empty">' + (filtersActive() ? 'No items match these filters.' : 'No item value yet.') + '</div>';
            return;
        }

        var dark = isDark();
        // Always destroy + rebuild: the global Highcharts (3.0.7) has no Chart/Series.update().
        if (catChart) { catChart.destroy(); catChart = null; }
        hostEl.innerHTML = '';

        catChart = new Highcharts.Chart({
            chart: { renderTo: 'inv-chart-category', type: 'pie', backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
            title: { text: null },
            series: [{
                name: 'Value', data: data, innerSize: '55%',
                dataLabels: {
                    enabled: true,
                    formatter: function () { return escapeHtml(this.point.name) + ': ' + money(this.y); },
                    style: { color: dark ? '#e2e8f0' : '#333333', fontSize: '11px', textOutline: 'none' }
                }
            }],
            legend: { enabled: false },
            credits: { enabled: false },
            tooltip: Object.assign({
                // formatter (not pointFormatter, which Highcharts 3 lacks); names are escaped.
                formatter: function () { return '<b>' + escapeHtml(this.point.name) + '</b>: ' + money(this.y); }
            }, tooltipOpts())
        });
    }

    /* ---- Items by Condition (column) ---- */
    function renderConditionChart(byCondition) {
        if (typeof Highcharts === 'undefined') { return; }
        byCondition = byCondition || {};
        var categories = COND_ORDER.map(condLabel);
        var data = COND_ORDER.map(function (k) {
            return { y: Number(byCondition[k]) || 0, color: COND_COLORS[k] || '#4338ca' };
        });
        var ax = axisColors();
        var hostEl = document.getElementById('inv-chart-condition');
        if (!hostEl) { return; }

        var anyData = data.some(function (p) { return p.y > 0; });
        if (!anyData) {
            if (condChart) { condChart.destroy(); condChart = null; }
            hostEl.innerHTML = '<div class="inv-chart-empty">' + (filtersActive() ? 'No items match these filters.' : 'No items yet.') + '</div>';
            return;
        }

        // Always destroy + rebuild: the global Highcharts (3.0.7) has no Chart/Axis.update().
        if (condChart) { condChart.destroy(); condChart = null; }
        hostEl.innerHTML = '';

        condChart = new Highcharts.Chart({
            chart: { renderTo: 'inv-chart-condition', type: 'column', backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
            title: { text: null },
            xAxis: {
                categories: categories,
                lineColor: ax.line, tickColor: ax.line,
                labels: { style: { color: ax.label, fontSize: '11px' } }
            },
            yAxis: {
                title: { text: null }, gridLineColor: ax.grid, allowDecimals: false, min: 0,
                labels: { style: { color: ax.label, fontSize: '11px' } }
            },
            plotOptions: { column: { borderRadius: 2, borderWidth: 0, maxPointWidth: 56, colorByPoint: true, pointPadding: 0.05, groupPadding: 0.08 } },
            series: [{ name: 'Units', data: data }],
            legend: { enabled: false },
            credits: { enabled: false },
            tooltip: Object.assign({
                formatter: function () { return '<b>' + escapeHtml(this.x) + '</b><br/>Units: <b>' + (Number(this.y) || 0) + '</b>'; }
            }, tooltipOpts())
        });
    }

    function renderAll() {
        var s = cfg.summary || {};
        renderCategoryChart(s.ByCategory || {});
        renderConditionChart(s.ByCondition || {});
    }

    /* Re-theme charts when dark mode is toggled (re-render picks up colours). */
    var themeObserver = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
            if (muts[i].attributeName === 'data-theme') { renderAll(); break; }
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    /* =====================================================
       Live auto-refresh: poll a cheap revision token; only refetch on a real change.
       ===================================================== */
    var INV_POLL_MS = 25000;
    var lastRev = null;
    var pollTimer = null;
    function anyModalOpen() { return !!document.querySelector('.rp-modal-overlay.rp-open'); }
    function syncRevision() {
        return fetch(cfg.ajax + 'rev', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.status === 0 && j.detail) { lastRev = j.detail.Rev; } })
            .catch(function () {});
    }
    function checkRevision() {
        if (document.hidden || anyModalOpen()) { return; }   // don't refetch under an open editor or hidden tab
        fetch(cfg.ajax + 'rev', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 0 || !j.detail) { return; }
                if (lastRev === null) { lastRev = j.detail.Rev; return; }
                if (j.detail.Rev !== lastRev && !anyModalOpen()) {
                    lastRev = j.detail.Rev;
                    if (InvApp.refreshAll) { InvApp.refreshAll(); }   // reloads items + summary (fires inv:summarychanged)
                }
            })
            .catch(function () {});
    }
    function startPolling() {
        if (pollTimer) { return; }
        pollTimer = setInterval(checkRevision, INV_POLL_MS);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { checkRevision(); } });
        window.addEventListener('focus', checkRevision);
        syncRevision();   // establish the baseline from the server-rendered initial state
    }

    /* ---- wiring ---- */
    // InvApp.loadSummary fires inv:summarychanged after every summary fetch — filter
    // changes and CRUD refreshes alike — so the charts always use the stat cards' filters.
    app.addEventListener('inv:summarychanged', renderAll);

    /* ---- init ---- */
    renderAll();
    startPolling();
})();
</script>
