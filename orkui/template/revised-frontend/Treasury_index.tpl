<?php
/* -----------------------------------------------------------------
   Treasury — officer-only per-org financial ledger (Kingdom/Park)
   Plain-PHP template (extract()+include). All CSS/JS inlined, `tr-`
   prefixed. Dark-mode via global --ork-* vars + html[data-theme=dark].
   Variables (from controller.Treasury::render):
     $owner_type, $owner_id, $categories, $org_name, $has_opening,
     $summary, $ledger, $reconciliations, $series
   ----------------------------------------------------------------- */
$uir = UIR;

// Flatten the grouped category map (key => label) for the filter dropdown + JS.
$catFlat = [];
foreach ((array)$categories as $grp => $items) {
    foreach ((array)$items as $k => $lbl) {
        $catFlat[$k] = $lbl;
    }
}

$ajaxBase = $uir . 'TreasuryAjax/handle/' . $owner_type . '/' . $owner_id . '/';
$fmt      = fn($n) => '$' . number_format((float)$n, 2);

// Header scope chip — links back to the org this ledger belongs to.
$isPark          = ($owner_type === 'park');
$ownerLabel      = $isPark ? 'Park' : 'Kingdom';
$ownerLabelLower = $isPark ? 'park' : 'kingdom';
$scopeIcon       = $isPark ? 'fa-tree' : 'fa-chess-rook';
$scopeLink       = $uir . ($isPark ? 'Park/profile/' : 'Kingdom/profile/') . (int)$owner_id;
// Sibling officer tool for the same org — Treasury and Inventory are a pair.
$siblingLink     = $uir . 'Inventory/' . $owner_type . '/' . (int)$owner_id;
$org_name        = (string)($org_name ?? '');

// Defensive defaults (controller already supplies these, but render must never fatal).
$summary         = is_array($summary ?? null) ? $summary : ['CurrentBalance' => 0, 'TotalIn' => 0, 'TotalOut' => 0, 'ByCategory' => []];
$ledger          = is_array($ledger ?? null) ? $ledger : ['Rows' => [], 'Total' => 0];
$reconciliations = is_array($reconciliations ?? null) ? $reconciliations : [];
$series          = is_array($series ?? null) ? $series : [];
$summary += ['CurrentBalance' => 0, 'TotalIn' => 0, 'TotalOut' => 0, 'ByCategory' => []];
?>
<script src="https://code.highcharts.com/highcharts.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(__DIR__ . '/../default/style/reports.css') ?>">
<script src="<?= HTTP_TEMPLATE ?>default/script/rp-tooltip.js?v=<?= filemtime(__DIR__ . '/../default/script/rp-tooltip.js') ?>"></script>
<style>
/* =====================================================
   Treasury tool — .tr-* additions on top of the shared
   report chrome (.rp-*, reports.css). Only what the shared
   sheet doesn't already provide lives here: the ledger and
   reconciliation tables, and Treasury-only deltas on the shared
   modal and form controls. Light defaults; dark via --ork-* + overrides.
   ===================================================== */
/* Full-bleed: the ledger has nine columns and wants every pixel. */
.tr-root { width: 100%; margin: 0; padding: 16px 16px 48px; box-sizing: border-box; color: var(--ork-text); }

/* Credit/debit colour on the shared stat cards */
#tr-in-card  .rp-stat-number { color: #2f855a; }
#tr-out-card .rp-stat-number { color: #c53030; }
html[data-theme="dark"] #tr-in-card  .rp-stat-number { color: #9ae6b4; }
html[data-theme="dark"] #tr-out-card .rp-stat-number { color: #feb2b2; }

/* Charts sit two-up under the stat row (the shared row stacks by default) */
.rp-charts-row.tr-charts { flex-direction: row; flex-wrap: wrap; }
.tr-charts .tr-chart-full { flex: 1 1 100%; }

/* First-run banner */
.tr-firstrun {
    background: var(--ork-alert-info-bg); border: 1px solid var(--ork-alert-info-border);
    color: var(--ork-alert-info-text); border-radius: 10px; padding: 16px 18px; margin-bottom: 18px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
.tr-firstrun p { margin: 0; font-size: 0.92rem; }

/* Sidebar filter form — flatpickr swaps in an altInput sibling, which
   needs the same shell as the field it replaces. */
.tr-filter-form .rp-form-input.form-control { width: 100%; box-sizing: border-box; }

/* Buttons (shared .rp-btn in reports.css) — Treasury dims disabled buttons */
.rp-btn:disabled, .rp-btn[disabled] { opacity: .45; cursor: not-allowed; }

/* Ledger table — the .rp-table-area wrapper already supplies the card
   surface, so the table itself stays flat (no second border/background). */
.tr-ledger { width: 100%; border-collapse: collapse; }
.tr-ledger thead th {
    text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: .03em;
    color: var(--ork-text-muted); background: var(--ork-bg-secondary);
    padding: 9px 12px; border-bottom: 1px solid var(--ork-border);
}
.tr-ledger tbody td { padding: 9px 12px; font-size: 0.86rem; color: var(--ork-text); border-bottom: 1px solid var(--ork-border); }
.tr-ledger tbody tr:last-child td { border-bottom: none; }
.tr-ledger .tr-num { text-align: right; font-variant-numeric: tabular-nums; }
.tr-ledger .tr-actions { text-align: right; white-space: nowrap; }
.tr-ledger tbody td:last-child { text-align: right; }
.tr-ledger .tr-empty td { text-align: center; color: var(--ork-text-muted); padding: 22px 12px; font-style: italic; }

/* Segmented control (shared .rp-seg) — direction colours */
.rp-seg.tr-seg-credit button.rp-seg-on { background: #2f855a; }
.rp-seg.tr-seg-debit button.rp-seg-on { background: #c53030; }
html[data-theme="dark"] .rp-seg.tr-seg-credit button.rp-seg-on { background: #38a169; }
html[data-theme="dark"] .rp-seg.tr-seg-debit button.rp-seg-on { background: #e53e3e; }

/* Blue inline hint (e.g. negative-amount notice) */
.tr-hint { display: none; margin-top: 5px; font-size: 0.78rem; color: #2b6cb0; }
.tr-hint.tr-hint-show { display: block; }
html[data-theme="dark"] .tr-hint { color: #63b3ed; }

/* To/From player checkbox */
.tr-check { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 600; color: var(--ork-text-secondary); cursor: pointer; text-transform: none; letter-spacing: 0; margin: 0 0 5px; }
.tr-check input { margin: 0; cursor: pointer; }

/* Player-search autocomplete — canonical kn-ac-results dropdown. revised.css (which
   carries the shared kn-ac-* rules) is not loaded on this page, so they are defined
   here. Inside the scrolling modal it is position:fixed and placed by
   tnFixedAcPosition() so the modal's overflow never clips it. */
#tr-entry-overlay .kn-ac-results {
    position: fixed; z-index: 10001;
    margin-top: 0; max-height: 220px; overflow-y: auto; display: none;
    background: var(--ork-card-bg); border: 1px solid var(--ork-input-border);
    border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,.28);
}
#tr-entry-overlay .kn-ac-results.kn-ac-open { display: block; }
#tr-entry-overlay .kn-ac-item { padding: 8px 11px; font-size: 0.84rem; cursor: pointer; color: var(--ork-text); border-bottom: 1px solid var(--ork-border); }
#tr-entry-overlay .kn-ac-item:last-child { border-bottom: none; }
#tr-entry-overlay .kn-ac-item:hover, #tr-entry-overlay .kn-ac-item:focus, #tr-entry-overlay .kn-ac-item.kn-ac-focused { background: rgba(99,102,241,.16); outline: none; }
#tr-entry-overlay .kn-ac-item.tr-ac-empty { color: var(--ork-text-muted); cursor: default; }
#tr-entry-overlay .kn-ac-item .tr-ac-meta { color: var(--ork-text-muted); font-size: 0.72rem; }

/* Reconciliation — live compare panel inside the reconcile modal */
.tr-recon-compare {
    border: 1px solid var(--ork-border); border-radius: 8px; padding: 12px 14px;
    background: var(--ork-bg-secondary); margin-bottom: 13px; font-size: 0.86rem;
}
.tr-recon-row { display: flex; justify-content: space-between; align-items: center; padding: 3px 0; }
.tr-recon-row .tr-recon-lbl { color: var(--ork-text-muted); }
.tr-recon-row .tr-recon-val { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--ork-text); }
.tr-recon-status { margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--ork-border); font-weight: 700; display: flex; align-items: center; gap: 6px; }
.tr-recon-status.tr-recon-match    { color: #2f855a; }
.tr-recon-status.tr-recon-mismatch { color: #c53030; }
html[data-theme="dark"] .tr-recon-status.tr-recon-match    { color: #9ae6b4; }
html[data-theme="dark"] .tr-recon-status.tr-recon-mismatch { color: #feb2b2; }
.tr-recon-var { font-variant-numeric: tabular-nums; }

/* Reconciliation history — its own .rp-table-area card below the ledger */
.tr-recon-history { margin-top: 16px; }
.tr-recon-history h2 {
    background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
    margin: 0 0 10px; font-size: 0.95rem; font-weight: 700; color: var(--ork-text);
}
.tr-recon-table { width: 100%; border-collapse: collapse; }
.tr-recon-table thead th {
    text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: .03em;
    color: var(--ork-text-muted); background: var(--ork-bg-secondary);
    padding: 9px 12px; border-bottom: 1px solid var(--ork-border);
}
.tr-recon-table tbody td { padding: 9px 12px; font-size: 0.86rem; color: var(--ork-text); border-bottom: 1px solid var(--ork-border); }
.tr-recon-table tbody tr:last-child td { border-bottom: none; }
.tr-recon-table .tr-num { text-align: right; font-variant-numeric: tabular-nums; }
.tr-recon-table .tr-empty td { text-align: center; color: var(--ork-text-muted); padding: 22px 12px; font-style: italic; }
.tr-var-pos { color: #2f855a; } .tr-var-neg { color: #c53030; }
html[data-theme="dark"] .tr-var-pos { color: #9ae6b4; } html[data-theme="dark"] .tr-var-neg { color: #feb2b2; }
.tr-badge-opening { background: var(--rp-primary); color: #fff; margin-left: 6px; }   /* on .rp-badge */
.tr-req-flag { color: var(--rp-danger); }
</style>

<div class="rp-root tr-root" id="tr-app"
     data-ajax="<?= htmlspecialchars($ajaxBase) ?>"
     data-hasopening="<?= $has_opening ? '1' : '0' ?>">

    <!-- ── Header ─────────────────────────────────────────── -->
    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fas fa-coins rp-header-icon"></i>
                <h1 class="rp-header-title">Treasury</h1>
            </div>
            <div class="rp-header-scope">
                <span class="rp-scope-chip-label"><?= $ownerLabel ?> ledger</span>
                <?php if ($org_name !== ''): ?>
                <a class="rp-scope-chip" href="<?= htmlspecialchars($scopeLink) ?>">
                    <i class="fas <?= $scopeIcon ?>"></i>
                    <?= htmlspecialchars($org_name) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="rp-header-actions">
            <button class="rp-btn-ghost" id="tr-add" type="button"><i class="fas fa-plus"></i> Add Entry</button>
            <button class="rp-btn-ghost" id="tr-reconcile" type="button"><i class="fas fa-balance-scale"></i> Reconcile</button>
            <a class="rp-btn-ghost" id="tr-export" href="<?= htmlspecialchars($ajaxBase) ?>export"><i class="fas fa-download"></i> Export CSV</a>
            <span class="rp-header-sep" aria-hidden="true"></span>
            <a class="rp-btn-ghost" href="<?= htmlspecialchars($siblingLink) ?>"><i class="fas fa-boxes"></i> Go to Inventory <i class="fas fa-arrow-right rp-btn-arrow"></i></a>
        </div>
    </div>

    <!-- ── Context strip ──────────────────────────────────── -->
    <div class="rp-context">
        <i class="fas fa-info-circle rp-context-icon"></i>
        <span>Every credit and debit against the <?= $ownerLabelLower ?>&rsquo;s funds, running to a live balance.
              <strong>Reconcile</strong> periodically to compare the book against the real-world account and record any variance.
              Only officers with edit authority over this <?= $ownerLabelLower ?> can see or change this ledger.</span>
    </div>

    <?php if (!$has_opening): ?>
    <div class="tr-firstrun" id="tr-firstrun">
        <p>Set your starting balance to begin. Enter the current real-world balance and the date it&rsquo;s accurate as of.</p>
        <button class="rp-btn rp-btn-primary" id="tr-set-opening" type="button">Set Opening Balance</button>
    </div>
    <?php endif; ?>

    <!-- ── Stats row ──────────────────────────────────────── -->
    <div class="rp-stats-row">
        <div class="rp-stat-card">
            <div class="rp-stat-icon"><i class="fas fa-wallet"></i></div>
            <div class="rp-stat-number" id="tr-bal"><?= $fmt($summary['CurrentBalance']) ?></div>
            <div class="rp-stat-label">Current Balance</div>
        </div>
        <div class="rp-stat-card" id="tr-in-card">
            <div class="rp-stat-icon"><i class="fas fa-arrow-down"></i></div>
            <div class="rp-stat-number" id="tr-in"><?= $fmt($summary['TotalIn']) ?></div>
            <div class="rp-stat-label">Total In</div>
        </div>
        <div class="rp-stat-card" id="tr-out-card">
            <div class="rp-stat-icon"><i class="fas fa-arrow-up"></i></div>
            <div class="rp-stat-number" id="tr-out"><?= $fmt($summary['TotalOut']) ?></div>
            <div class="rp-stat-label">Total Out</div>
        </div>
        <div class="rp-stat-card">
            <div class="rp-stat-icon"><i class="fas fa-list-ol"></i></div>
            <div class="rp-stat-number" id="tr-count"><?= (int)$ledger['Total'] ?></div>
            <div class="rp-stat-label">Entries</div>
        </div>
    </div>

    <!-- ── Charts ─────────────────────────────────────────── -->
    <div class="rp-charts-row rp-charts-visible tr-charts">
        <div class="rp-chart-card tr-chart-full">
            <div class="rp-chart-card-title">Balance Over Time</div>
            <div id="tr-chart-balance" style="height:120px"></div>
        </div>
        <div class="rp-chart-card">
            <div class="rp-chart-card-title">Income by Category</div>
            <div id="tr-chart-income" style="height:240px"></div>
        </div>
        <div class="rp-chart-card">
            <div class="rp-chart-card-title">Expenses by Category</div>
            <div id="tr-chart-expense" style="height:240px"></div>
        </div>
    </div>

    <!-- ── Body: sidebar + main ───────────────────────────── -->
    <div class="rp-body">

        <!-- Sidebar -->
        <div class="rp-sidebar">

            <div class="rp-filter-card">
                <div class="rp-filter-card-header">
                    <i class="fas fa-sliders-h"></i> Filters
                </div>
                <div class="rp-filter-card-body">
                    <div class="rp-param-form tr-filter-form">
                        <div class="rp-form-group">
                            <label for="tr-f-from">From</label>
                            <input type="text" id="tr-f-from" class="rp-form-input" placeholder="Any date" autocomplete="off">
                        </div>
                        <div class="rp-form-group">
                            <label for="tr-f-to">To</label>
                            <input type="text" id="tr-f-to" class="rp-form-input" placeholder="Any date" autocomplete="off">
                        </div>
                        <div class="rp-form-group">
                            <label for="tr-f-cat">Category</label>
                            <select id="tr-f-cat" class="rp-form-input">
                                <option value="">All categories</option>
                                <?php foreach ($catFlat as $k => $lbl): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rp-form-group">
                            <label for="tr-f-dir">Direction</label>
                            <select id="tr-f-dir" class="rp-form-input">
                                <option value="">In &amp; Out</option>
                                <option value="credit">In only</option>
                                <option value="debit">Out only</option>
                            </select>
                        </div>
                        <button type="button" class="rp-filter-reset" id="tr-f-reset">Clear filters</button>
                    </div>
                </div>
            </div>

            <div class="rp-filter-card">
                <div class="rp-filter-card-header">
                    <i class="fas fa-book-open"></i> About This Tool
                </div>
                <div class="rp-filter-card-body">
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Balance</span>
                        <span class="rp-col-guide-desc">Runs from the opening balance forward, oldest entry first, so each row shows the funds on hand at that point.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Reconcile</span>
                        <span class="rp-col-guide-desc">Records what the account actually held on a date. A variance is kept, not corrected &mdash; explain it in the note.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Counterparty</span>
                        <span class="rp-col-guide-desc">Who the money came from or went to. Link a player for reimbursements and donations so it shows on their record.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Export CSV</span>
                        <span class="rp-col-guide-desc">Exports the ledger with the filters above applied &mdash; useful for a term-end report to the <?= $ownerLabelLower ?>.</span>
                    </div>
                </div>
            </div>

        </div><!-- /rp-sidebar -->

        <!-- Main column -->
        <div class="rp-main">

            <div class="rp-table-area">
                <table class="tr-ledger" id="tr-ledger">
                    <thead>
                        <tr>
                            <th>Date</th><th>Category</th><th>Method</th><th>Description</th><th>Counterparty</th>
                            <th class="tr-num">In</th><th class="tr-num">Out</th><th class="tr-num">Balance</th>
                            <th class="tr-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tr-ledger-body"><!-- rendered by JS --></tbody>
                </table>
                <div class="rp-pager" id="tr-pager"></div>
            </div>

            <div class="rp-table-area tr-recon-history" id="tr-recon-history">
                <h2>Reconciliation History</h2>
                <table class="tr-recon-table" id="tr-recon-table">
                    <thead>
                        <tr>
                            <th>As Of</th>
                            <th class="tr-num">Actual</th>
                            <th class="tr-num">Computed</th>
                            <th class="tr-num">Variance</th>
                            <th>Explanation</th>
                            <th>Recorded</th>
                        </tr>
                    </thead>
                    <tbody id="tr-recon-body"><!-- rendered by JS --></tbody>
                </table>
            </div>

        </div><!-- /rp-main -->
    </div><!-- /rp-body -->
</div>

<!-- Add / Edit entry modal (built/populated by JS) -->
<div class="rp-modal-overlay" id="tr-entry-overlay" aria-hidden="true">
    <div class="rp-modal" role="dialog" aria-modal="true" aria-labelledby="tr-entry-title">
        <div class="rp-modal-head">
            <h2 id="tr-entry-title">Add Entry</h2>
            <button class="rp-modal-close" type="button" data-tr-close aria-label="Close">&times;</button>
        </div>
        <form id="tr-entry-form" autocomplete="off">
            <div class="rp-modal-body">
                <input type="hidden" name="id" id="tr-e-id" value="">
                <input type="hidden" name="direction" id="tr-e-direction" value="credit">
                <div class="rp-field">
                    <label class="rp-label">Type</label>
                    <div class="rp-seg tr-seg-credit" id="tr-e-dir-seg">
                        <button type="button" data-dir="credit" class="rp-seg-on">Money In</button>
                        <button type="button" data-dir="debit">Money Out</button>
                    </div>
                </div>
                <div class="rp-field-row">
                    <div class="rp-field">
                        <label class="rp-label" for="tr-e-date">Date</label>
                        <input class="rp-input" type="text" id="tr-e-date" name="entry_date" placeholder="Select date">
                        <div class="rp-field-err" data-err="entry_date">A date is required.</div>
                    </div>
                    <div class="rp-field">
                        <label class="rp-label" for="tr-e-amount">Amount</label>
                        <input class="rp-input" type="text" inputmode="decimal" id="tr-e-amount" name="amount" placeholder="0.00" autocomplete="off">
                        <div class="rp-field-err" data-err="amount">Enter an amount greater than zero.</div>
                        <div class="tr-hint" id="tr-e-amount-hint">No need to enter it as a negative, Money Out takes care of that.</div>
                    </div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="tr-e-category">Category</label>
                    <select class="rp-select" id="tr-e-category" name="category"></select>
                    <div class="rp-field-err" data-err="category">Choose a category.</div>
                </div>
                <div class="rp-field">
                    <label class="rp-label">Payment Method</label>
                    <div class="rp-seg" id="tr-e-method-seg">
                        <button type="button" data-method="cash">Cash</button>
                        <button type="button" data-method="check">Check</button>
                        <button type="button" data-method="digital">Digital</button>
                    </div>
                    <input type="hidden" name="payment_method" id="tr-e-method" value="">
                    <div class="rp-field-err" data-err="payment_method">Select a payment method.</div>
                </div>
                <div class="rp-field">
                    <label class="rp-label" for="tr-e-description">Description</label>
                    <input class="rp-input" type="text" id="tr-e-description" name="description" maxlength="255" placeholder="What was this for?">
                </div>
                <div class="rp-field-row">
                    <div class="rp-field" id="tr-e-cp-field">
                        <label class="rp-label" id="tr-e-cp-label" for="tr-e-counterparty">Counterparty</label>
                        <label class="tr-check"><input type="checkbox" id="tr-e-toplayer"> To / From a player?</label>
                        <input class="rp-input" type="text" id="tr-e-counterparty" name="counterparty" maxlength="255" placeholder="Paid to / received from">
                        <input class="rp-input" type="text" id="tr-e-player-text" placeholder="Search players&hellip;" autocomplete="off" style="display:none">
                        <input type="hidden" id="tr-e-counterparty-player-id" name="counterparty_player_id" value="0">
                        <div class="kn-ac-results" id="tr-e-player-results"></div>
                        <div class="rp-field-err" data-err="counterparty_player_id">Select a player from the list.</div>
                    </div>
                    <div class="rp-field">
                        <label class="rp-label" for="tr-e-reference">Reference #</label>
                        <input class="rp-input" type="text" id="tr-e-reference" name="reference_no" maxlength="64" placeholder="Check / receipt #">
                    </div>
                </div>
                <div class="rp-field-err rp-field-err-form" data-err="_form"></div>
            </div>
            <div class="rp-modal-foot">
                <button class="rp-btn" type="button" data-tr-close>Cancel</button>
                <button class="rp-btn rp-btn-primary" type="submit" id="tr-e-save">Save Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- Reconcile / opening-balance modal (built/populated by JS) -->
<div class="rp-modal-overlay" id="tr-recon-overlay" aria-hidden="true">
    <div class="rp-modal" role="dialog" aria-modal="true" aria-labelledby="tr-recon-title">
        <div class="rp-modal-head">
            <h2 id="tr-recon-title">Reconcile</h2>
            <button class="rp-modal-close" type="button" data-tr-rclose aria-label="Close">&times;</button>
        </div>
        <form id="tr-recon-form" autocomplete="off">
            <div class="rp-modal-body">
                <p class="tr-recon-intro" id="tr-recon-intro" style="margin:0 0 14px;font-size:0.88rem;color:var(--ork-text-secondary);"></p>
                <div class="rp-field-row">
                    <div class="rp-field">
                        <label class="rp-label" for="tr-r-date">As Of Date</label>
                        <input class="rp-input" type="text" id="tr-r-date" name="as_of_date" placeholder="Select date">
                        <div class="rp-field-err" data-err="as_of_date">A date is required.</div>
                    </div>
                    <div class="rp-field">
                        <label class="rp-label" for="tr-r-actual" id="tr-r-actual-label">Actual Balance</label>
                        <input class="rp-input" type="number" step="0.01" id="tr-r-actual" name="actual_balance" placeholder="0.00">
                        <div class="rp-field-err" data-err="actual_balance">Enter the real-world balance.</div>
                    </div>
                </div>
                <div class="tr-recon-compare" id="tr-recon-compare">
                    <div class="tr-recon-row">
                        <span class="tr-recon-lbl">Computed balance</span>
                        <span class="tr-recon-val" id="tr-r-computed">&mdash;</span>
                    </div>
                    <div class="tr-recon-row">
                        <span class="tr-recon-lbl">Your actual balance</span>
                        <span class="tr-recon-val" id="tr-r-actual-echo">&mdash;</span>
                    </div>
                    <div class="tr-recon-status" id="tr-r-status">
                        <span id="tr-r-status-text">Enter an actual balance to compare.</span>
                        <span class="tr-recon-var" id="tr-r-variance"></span>
                    </div>
                </div>
                <div class="rp-field" id="tr-r-expl-field" style="display:none;">
                    <label class="rp-label" for="tr-r-explanation">Explanation <span class="tr-req-flag">(required &mdash; balances don&rsquo;t match)</span></label>
                    <textarea class="rp-textarea" id="tr-r-explanation" name="explanation" maxlength="500" placeholder="Why does the actual balance differ from the computed balance?"></textarea>
                    <div class="rp-field-err" data-err="explanation">An explanation is required when the balances don&rsquo;t match.</div>
                </div>
                <div class="rp-field-err rp-field-err-form" data-err="_form"></div>
            </div>
            <div class="rp-modal-foot">
                <button class="rp-btn" type="button" data-tr-rclose>Cancel</button>
                <button class="rp-btn rp-btn-primary" type="submit" id="tr-r-save">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
window.TrConfig = {
    ajax:           '<?= $ajaxBase ?>',
    uir:            '<?= UIR ?>',
    ownerType:      '<?= $owner_type === 'park' ? 'park' : 'kingdom' ?>',
    ownerId:        <?= (int)$owner_id ?>,
    kingdomId:      <?= (int)($kingdom_id ?? 0) ?>,
    categories:     <?= json_encode($catFlat) ?>,
    categoryGroups: <?= json_encode($categories) ?>,
    hasOpening:     <?= $has_opening ? 'true' : 'false' ?>,
    series:         <?= json_encode($series) ?>,
    byCategory:     <?= json_encode($summary['ByCategory']) ?>,
    initialLedger:  <?= json_encode($ledger) ?>,
    reconciliations: <?= json_encode(array_values($reconciliations)) ?>
};
</script>

<script>
/* =====================================================
   Treasury — ledger render, add/edit modal, delete
   Exposes window.TrApp so later sections (reconcile, charts)
   can trigger refreshes via TrApp / the 'tr:datachanged' event.
   ===================================================== */
(function () {
    'use strict';

    var app = document.getElementById('tr-app');
    if (!app) { return; }
    var cfg = window.TrConfig || {};

    var body   = document.getElementById('tr-ledger-body');
    var pager  = document.getElementById('tr-pager');
    var state  = { page: 1, per: 25, from: '', to: '', category: '', direction: '' };

    /* ---- helpers ---- */
    function money(n) {
        return '$' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function methodLabel(m) {
        return { cash: 'Cash', check: 'Check', digital: 'Digital' }[m] || (m || '');
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

    /* ---- in-product confirm (tnConfirm if Tournament helper is loaded; else local fallback) ---- */
    function trConfirm(opts) {
        if (typeof window.tnConfirm === 'function') { window.tnConfirm(opts); return; }
        // Self-contained fallback modal (no native confirm()).
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

    /* ---- ledger rendering ---- */
    function renderRows(d) {
        d = d || {};
        body.innerHTML = '';
        var rows = d.Rows || [];
        if (!rows.length) {
            var er = document.createElement('tr');
            er.className = 'tr-empty';
            er.innerHTML = '<td colspan="9">No entries yet.</td>';
            body.appendChild(er);
        } else {
            rows.forEach(function (r) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + escapeHtml(r.Date) + '</td>' +
                    '<td>' + escapeHtml(cfg.categories[r.Category] || r.Category) + '</td>' +
                    '<td>' + escapeHtml(methodLabel(r.PaymentMethod)) + '</td>' +
                    '<td>' + escapeHtml(r.Description || '') + '</td>' +
                    '<td>' + cpCell(r) + '</td>' +
                    '<td class="tr-num">' + (r.Direction === 'credit' ? money(r.Amount) : '') + '</td>' +
                    '<td class="tr-num">' + (r.Direction === 'debit' ? money(r.Amount) : '') + '</td>' +
                    '<td class="tr-num">' + money(r.RunningBalance) + '</td>' +
                    '<td><div class="rp-row-actions">' +
                    '<button class="rp-row-btn" type="button" data-edit="' + r.Id + '" ' +
                    'data-tip="Correct this entry — date, amount, category, description, or counterparty.">' +
                    '<i class="fas fa-pen"></i> Edit</button>' +
                    '<button class="rp-row-btn rp-row-btn-danger" type="button" data-del="' + r.Id + '" ' +
                    'data-tip="Take this entry off the ledger and out of the balance. The row and its audit trail are kept for the record.">' +
                    '<i class="fas fa-trash-alt"></i> Delete</button>' +
                    '</div></td>';
                body.appendChild(tr);
            });
        }
        if (typeof d.CurrentBalance !== 'undefined') {
            var balEl = document.getElementById('tr-bal');
            if (balEl) { balEl.textContent = money(d.CurrentBalance); }
        }
        // Entries stat card tracks the filtered row count (it was server-rendered
        // only, so it went stale after an add/delete or a filter change).
        var cntEl = document.getElementById('tr-count');
        if (cntEl && typeof d.Total !== 'undefined') { cntEl.textContent = d.Total; }
        renderPager(d);
    }

    function renderPager(d) {
        pager.innerHTML = '';
        var total = d.Total || 0, per = d.Per || state.per, page = d.Page || state.page;
        var pages = Math.max(1, Math.ceil(total / per));
        if (total === 0) { return; }
        var prev = document.createElement('button');
        prev.type = 'button'; prev.textContent = 'Prev'; prev.disabled = page <= 1;
        prev.addEventListener('click', function () { if (state.page > 1) { state.page--; loadLedger(); } });
        var info = document.createElement('span');
        info.textContent = 'Page ' + page + ' of ' + pages + ' · ' + total + ' entr' + (total === 1 ? 'y' : 'ies');
        var next = document.createElement('button');
        next.type = 'button'; next.textContent = 'Next'; next.disabled = page >= pages;
        next.addEventListener('click', function () { if (state.page < pages) { state.page++; loadLedger(); } });
        pager.appendChild(prev); pager.appendChild(info); pager.appendChild(next);
    }

    function filterQuery(extra) {
        var q = new URLSearchParams({
            page: state.page, per: state.per, from: state.from, to: state.to,
            category: state.category, direction: state.direction
        });
        if (extra) { Object.keys(extra).forEach(function (k) { q.set(k, extra[k]); }); }
        return q.toString();
    }

    function loadLedger() {
        // cfg.ajax already ends in '?Route=...'; append params with '&' (a 2nd '?' breaks routing).
        return fetch(cfg.ajax + 'ledger&' + filterQuery(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j.status === 0) { renderRows(j.detail); } return j; })
            .catch(function (e) { console.log('[Treasury] loadLedger failed', e); });
    }

    /* Refresh the summary cards (Current Balance, Total In/Out, Entries). */
    function refreshSummary() {
        return fetch(cfg.ajax + 'summary&from=' + encodeURIComponent(state.from) + '&to=' + encodeURIComponent(state.to), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 0) { return j; }
                var s = j.detail || {};
                var set = function (id, val) { var el = document.getElementById(id); if (el) { el.textContent = money(val); } };
                set('tr-bal', s.CurrentBalance);
                set('tr-in', s.TotalIn);
                set('tr-out', s.TotalOut);
                cfg.byCategory = s.ByCategory || {};
                return j;
            })
            .catch(function (e) { console.log('[Treasury] refreshSummary failed', e); });
    }

    /* Reload everything affected by a CRUD operation; charts listen for tr:datachanged. */
    function refreshAll() {
        return Promise.all([loadLedger(), refreshSummary()]).then(function () {
            app.dispatchEvent(new CustomEvent('tr:datachanged', { bubbles: true }));
            // Re-baseline the poll so this (our own) change doesn't trigger a second refetch.
            return syncRevision();
        });
    }

    /* ---- live auto-refresh: poll a cheap revision token; only refetch on a real change ---- */
    var TR_POLL_MS = 25000;
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
                    refreshAll();
                }
            })
            .catch(function () {});
    }
    function startPolling() {
        if (pollTimer) { return; }
        pollTimer = setInterval(checkRevision, TR_POLL_MS);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { checkRevision(); } });
        window.addEventListener('focus', checkRevision);
        syncRevision();   // establish the baseline from the server-rendered initial state
    }

    /* ---- filters ---- */
    function bindFilters() {
        var catSel = document.getElementById('tr-f-cat');
        var dirSel = document.getElementById('tr-f-dir');
        if (catSel) { catSel.addEventListener('change', function () { state.category = catSel.value; state.page = 1; loadLedger(); }); }
        if (dirSel) { dirSel.addEventListener('change', function () { state.direction = dirSel.value; state.page = 1; loadLedger(); }); }
        var fpOpts = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: false };
        var fpFrom = null, fpTo = null;
        if (window.flatpickr) {
            fpFrom = flatpickr('#tr-f-from', Object.assign({}, fpOpts, {
                onChange: function (sel, str) { state.from = str || ''; state.page = 1; loadLedger(); refreshSummary(); }
            }));
            fpTo = flatpickr('#tr-f-to', Object.assign({}, fpOpts, {
                onChange: function (sel, str) { state.to = str || ''; state.page = 1; loadLedger(); refreshSummary(); }
            }));
        }
        var reset = document.getElementById('tr-f-reset');
        if (reset) {
            reset.addEventListener('click', function () {
                // clear(false) — don't fire onChange, we reload once at the end.
                if (fpFrom) { fpFrom.clear(false); } else { var f = document.getElementById('tr-f-from'); if (f) { f.value = ''; } }
                if (fpTo)   { fpTo.clear(false); }  else { var t = document.getElementById('tr-f-to');   if (t) { t.value = ''; } }
                if (catSel) { catSel.value = ''; }
                if (dirSel) { dirSel.value = ''; }
                state.from = ''; state.to = ''; state.category = ''; state.direction = ''; state.page = 1;
                loadLedger();
                refreshSummary();
            });
        }
        // Keep the export link in sync with active filters.
        var exp = document.getElementById('tr-export');
        if (exp) {
            exp.addEventListener('click', function () {
                exp.href = cfg.ajax + 'export&' + filterQuery({ page: 1, per: 100000 });
            });
        }
    }

    /* =====================================================
       Add / Edit entry modal
       ===================================================== */
    var overlay   = document.getElementById('tr-entry-overlay');
    var form      = document.getElementById('tr-entry-form');
    var titleEl   = document.getElementById('tr-entry-title');
    var dirSeg    = document.getElementById('tr-e-dir-seg');
    var dirHidden = document.getElementById('tr-e-direction');
    var methodSeg = document.getElementById('tr-e-method-seg');
    var methodHid = document.getElementById('tr-e-method');
    var catSelect = document.getElementById('tr-e-category');
    var dateInput = document.getElementById('tr-e-date');
    var fpEntry   = null;

    /* Amount field — strip signs/parens and nudge on Money Out. */
    var amountInput = document.getElementById('tr-e-amount');
    var amountHint  = document.getElementById('tr-e-amount-hint');
    function hideAmountHint() { if (amountHint) { amountHint.classList.remove('tr-hint-show'); } }
    function handleAmountInput() {
        if (!amountInput) { return; }
        var raw = amountInput.value;
        var hadSign = /[-()]/.test(raw);
        var cleaned = raw.replace(/[^0-9.]/g, '');
        if (cleaned !== raw) {
            var drop = raw.length - cleaned.length;
            var pos  = (amountInput.selectionStart || 0) - drop;
            amountInput.value = cleaned;
            try { amountInput.setSelectionRange(Math.max(0, pos), Math.max(0, pos)); } catch (e) {}
        }
        if (hadSign && dirHidden.value === 'debit' && amountHint) { amountHint.classList.add('tr-hint-show'); }
    }

    /* Counterparty / player-picker (scoped player search inside the modal). */
    var cpLabel    = document.getElementById('tr-e-cp-label');
    var cpText     = document.getElementById('tr-e-counterparty');
    var toPlayerCb = document.getElementById('tr-e-toplayer');
    var playerText = document.getElementById('tr-e-player-text');
    var playerId   = document.getElementById('tr-e-counterparty-player-id');
    var playerRes  = document.getElementById('tr-e-player-results');
    var playerTimer = null;

    // Canonical fixed-position placement for autocomplete dropdowns inside modals.
    function tnFixedAcPosition(inputEl, dropdownEl) {
        var rect = inputEl.getBoundingClientRect();
        dropdownEl.style.top   = (rect.bottom + 2) + 'px';
        dropdownEl.style.left  = rect.left + 'px';
        dropdownEl.style.width = rect.width + 'px';
    }
    function openPlayerResults() { tnFixedAcPosition(playerText, playerRes); playerRes.classList.add('kn-ac-open'); }
    function closePlayerResults() { clearTimeout(playerTimer); if (playerRes) { playerRes.classList.remove('kn-ac-open'); playerRes.innerHTML = ''; } }
    function setPlayerMode(on) {
        if (!toPlayerCb) { return; }
        toPlayerCb.checked = !!on;
        if (on) {
            cpLabel.textContent = 'Player';
            cpText.style.display = 'none';
            playerText.style.display = '';
        } else {
            cpLabel.textContent = 'Counterparty';
            cpText.style.display = '';
            playerText.style.display = 'none';
            if (playerId) { playerId.value = '0'; }
            closePlayerResults();
        }
    }
    function runPlayerSearch() {
        clearTimeout(playerTimer);
        if (playerId) { playerId.value = '0'; }   // typing invalidates a prior pick
        var term = playerText.value.trim();
        if (term.length < 2) { closePlayerResults(); return; }
        playerTimer = setTimeout(function () {
            // UIR already ends in '?Route=', so query params use '&' (a 2nd '?' empties $_GET['q']).
            var url = cfg.uir + 'KingdomAjax/playersearch/' + (cfg.kingdomId || 0) +
                '&scope=own&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    // Drop a stale response: the input changed (or blurred) while it was in flight.
                    if (playerText.value.trim() !== term || document.activeElement !== playerText) { return; }
                    if (!Array.isArray(data) || !data.length) {
                        playerRes.innerHTML = '<div class="kn-ac-item tr-ac-empty">No players found</div>';
                        openPlayerResults();
                        return;
                    }
                    playerRes.innerHTML = data.map(function (p) {
                        var meta = (p.KAbbr || '') + (p.PAbbr ? ':' + p.PAbbr : '');
                        return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId +
                            '" data-name="' + encodeURIComponent(p.Persona || '') + '">' +
                            escapeHtml(p.Persona || '') + ' <span class="tr-ac-meta">(' + escapeHtml(meta) + ')</span>' +
                            (p.Active === 0 ? ' <span class="tr-ac-meta">&mdash; inactive</span>' : '') + '</div>';
                    }).join('');
                    openPlayerResults();
                })
                .catch(function () { closePlayerResults(); });
        }, 250);
    }
    function choosePlayer(item) {
        if (!item) { return; }
        playerText.value = decodeURIComponent(item.getAttribute('data-name') || '');
        if (playerId) { playerId.value = item.getAttribute('data-id') || '0'; }
        closePlayerResults();
    }
    function playerKeyNav(e) {
        if (!playerRes) { return; }
        if (e.key === 'Tab') { closePlayerResults(); return; }
        var items = playerRes.querySelectorAll('.kn-ac-item[data-id]');
        if (!items.length) { return; }
        var cur = playerRes.querySelector('.kn-ac-item.kn-ac-focused');
        var idx = -1;
        for (var i = 0; i < items.length; i++) { if (items[i] === cur) { idx = i; break; } }
        if (e.key === 'ArrowDown') { e.preventDefault(); if (cur) { cur.classList.remove('kn-ac-focused'); } idx = Math.min(idx + 1, items.length - 1); items[idx].classList.add('kn-ac-focused'); items[idx].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (cur) { cur.classList.remove('kn-ac-focused'); } idx = Math.max(idx - 1, 0); items[idx].classList.add('kn-ac-focused'); items[idx].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'Enter' && cur) { e.preventDefault(); choosePlayer(cur); }
        else if (e.key === 'Escape') { closePlayerResults(); }
    }

    /* Counterparty cell — link to the player profile when one is attached. */
    function cpCell(r) {
        var name = escapeHtml(r.Counterparty || '');
        if (name && r.CounterpartyPlayerId && r.CounterpartyPlayerId > 0) {
            return '<a href="' + cfg.uir + 'Playernew/index/' + r.CounterpartyPlayerId + '">' + name + '</a>';
        }
        return name;
    }

    /* Build the category <select> for the active direction (Money In => Income, Money Out => Expense). */
    function buildCategoryOptions(dir) {
        if (!catSelect) { return; }
        var prev = catSelect.value;
        dir = (dir === 'debit') ? 'debit' : (dirHidden ? dirHidden.value : 'credit');
        var wantGroup  = (dir === 'debit') ? 'expense' : 'income';
        var groups     = cfg.categoryGroups || {};
        var groupLabel = (wantGroup === 'expense') ? 'Expense' : 'Income';
        var items      = groups[wantGroup] || {};
        catSelect.innerHTML = '<option value="">Select…</option>';
        var og = document.createElement('optgroup');
        og.label = groupLabel;
        Object.keys(items).forEach(function (k) {
            var opt = document.createElement('option');
            opt.value = k; opt.textContent = items[k];
            opt.setAttribute('data-group', wantGroup);
            og.appendChild(opt);
        });
        catSelect.appendChild(og);
        // Keep the selection only if it belongs to this direction's category set.
        catSelect.value = (prev && items[prev]) ? prev : '';
    }

    function setDirection(dir) {
        dir = (dir === 'debit') ? 'debit' : 'credit';
        dirHidden.value = dir;
        dirSeg.className = 'rp-seg ' + (dir === 'debit' ? 'tr-seg-debit' : 'tr-seg-credit');
        Array.prototype.forEach.call(dirSeg.querySelectorAll('button'), function (b) {
            b.classList.toggle('rp-seg-on', b.getAttribute('data-dir') === dir);
        });
        buildCategoryOptions(dir);
        if (dir !== 'debit') { hideAmountHint(); }
    }
    function setMethod(method) {
        methodHid.value = method || '';
        Array.prototype.forEach.call(methodSeg.querySelectorAll('button'), function (b) {
            b.classList.toggle('rp-seg-on', b.getAttribute('data-method') === method);
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

    function openModal(isEdit) {
        clearErrors();
        titleEl.textContent = isEdit ? 'Edit Entry' : 'Add Entry';
        document.getElementById('tr-e-save').textContent = isEdit ? 'Save Changes' : 'Save Entry';
        overlay.classList.add('rp-open');
        overlay.setAttribute('aria-hidden', 'false');
        if (!fpEntry && window.flatpickr) {
            fpEntry = flatpickr(dateInput, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: false });
        }
    }
    function closeModal() {
        overlay.classList.remove('rp-open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function resetForm() {
        form.reset();
        document.getElementById('tr-e-id').value = '';
        setDirection('credit');
        setMethod('');
        if (playerText) { playerText.value = ''; }
        setPlayerMode(false);
        hideAmountHint();
        if (fpEntry) { fpEntry.clear(); }
        else { dateInput.value = ''; }
        clearErrors();
    }

    function fillForm(e) {
        document.getElementById('tr-e-id').value = e.id || '';
        setDirection(e.direction);
        document.getElementById('tr-e-amount').value = (e.amount != null) ? Number(e.amount).toFixed(2) : '';
        catSelect.value = e.category || '';
        setMethod(e.payment_method || '');
        document.getElementById('tr-e-description').value = e.description || '';
        var cpPid = parseInt(e.counterparty_player_id || 0, 10);
        if (cpPid > 0) {
            setPlayerMode(true);
            playerText.value = e.counterparty || '';
            cpText.value = '';
            if (playerId) { playerId.value = String(cpPid); }
        } else {
            setPlayerMode(false);
            cpText.value = e.counterparty || '';
        }
        document.getElementById('tr-e-reference').value = e.reference_no || '';
        var d = e.entry_date || '';
        if (fpEntry) { fpEntry.setDate(d, true); } else { dateInput.value = d; }
    }

    function openAdd() {
        resetForm();
        // Default the date to today for convenience.
        var today = new Date();
        var iso = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2) + '-' + ('0' + today.getDate()).slice(-2);
        openModal(false);
        if (fpEntry) { fpEntry.setDate(iso, true); } else { dateInput.value = iso; }
    }

    function openEdit(id) {
        resetForm();
        openModal(true);
        fetch(cfg.ajax + 'getentry&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 0 && j.detail) { fillForm(j.detail); }
                else { markError('_form', (j.error || 'Could not load this entry.')); }
            });
    }

    function submitEntry(ev) {
        ev.preventDefault();
        clearErrors();
        var id     = document.getElementById('tr-e-id').value;
        var isEdit = !!id;
        var usingPlayer = !!(toPlayerCb && toPlayerCb.checked);
        var data = {
            entry_date:     dateInput.value || (fpEntry && fpEntry.input ? fpEntry.input.value : ''),
            direction:      dirHidden.value,
            amount:         document.getElementById('tr-e-amount').value,
            category:       catSelect.value,
            payment_method: methodHid.value,
            description:    document.getElementById('tr-e-description').value,
            counterparty:   usingPlayer ? playerText.value : cpText.value,
            counterparty_player_id: usingPlayer ? (playerId.value || '0') : '0',
            reference_no:   document.getElementById('tr-e-reference').value
        };

        // Client-side guards mirroring the lib's validation (server re-validates regardless).
        var ok = true;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(data.entry_date)) { markError('entry_date'); ok = false; }
        if (!(parseFloat(data.amount) > 0))               { markError('amount'); ok = false; }
        if (!data.category)                               { markError('category'); ok = false; }
        if (['cash', 'check', 'digital'].indexOf(data.payment_method) === -1) { markError('payment_method'); ok = false; }
        if (usingPlayer && !(parseInt(data.counterparty_player_id, 10) > 0)) { markError('counterparty_player_id'); ok = false; }
        if (!ok) { return; }

        var saveBtn = document.getElementById('tr-e-save');
        saveBtn.disabled = true;
        var action = isEdit ? 'editentry' : 'addentry';
        if (isEdit) { data.id = id; }
        postForm(action, data).then(function (j) {
            saveBtn.disabled = false;
            if (j.status === 0) {
                closeModal();
                if (!isEdit) { state.page = 1; }
                refreshAll();
            } else {
                markError('_form', j.error || 'Could not save this entry.');
            }
        }).catch(function () {
            saveBtn.disabled = false;
            markError('_form', 'Network error — please try again.');
        });
    }

    function deleteEntry(id) {
        trConfirm({
            title: 'Delete entry?',
            body: 'This removes the entry from the ledger. The record and its audit trail are retained.',
            confirmLabel: 'Delete',
            danger: true,
            onConfirm: function () {
                postForm('deleteentry', { id: id }).then(function (j) {
                    if (j.status === 0) { refreshAll(); }
                    else { trConfirm({ title: 'Delete failed', body: (j.error || 'Could not delete this entry.'), confirmLabel: 'OK', cancelLabel: 'Close' }); }
                });
            }
        });
    }

    /* ---- wiring ---- */
    function bindModal() {
        buildCategoryOptions();
        var addBtn = document.getElementById('tr-add');
        if (addBtn) { addBtn.addEventListener('click', openAdd); }
        // Direction + method segmented controls.
        dirSeg.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-dir]'); if (b) { setDirection(b.getAttribute('data-dir')); }
        });
        methodSeg.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-method]'); if (b) { setMethod(b.getAttribute('data-method')); }
        });
        // Amount: strip signs/parens, hint on Money Out.
        if (amountInput) { amountInput.addEventListener('input', handleAmountInput); }
        // To/From player toggle + scoped player search.
        if (toPlayerCb) {
            toPlayerCb.addEventListener('change', function () { setPlayerMode(this.checked); if (this.checked) { playerText.focus(); } });
        }
        if (playerText) {
            playerText.addEventListener('input', runPlayerSearch);
            playerText.addEventListener('keydown', playerKeyNav);
            playerText.addEventListener('blur', closePlayerResults);
        }
        if (playerRes) {
            // Keep focus in the input while picking, so its blur-close doesn't eat the click.
            playerRes.addEventListener('mousedown', function (e) { e.preventDefault(); });
            playerRes.addEventListener('click', function (e) {
                var item = e.target.closest('.kn-ac-item[data-id]');
                if (item) { choosePlayer(item); }
            });
            // The fixed dropdown must follow its input when the modal overlay scrolls.
            overlay.addEventListener('scroll', function () {
                if (playerRes.classList.contains('kn-ac-open')) { tnFixedAcPosition(playerText, playerRes); }
            });
        }
        // Close the player dropdown on an outside click.
        document.addEventListener('click', function (e) {
            if (playerRes && !playerRes.contains(e.target) && e.target !== playerText) { closePlayerResults(); }
        });
        // Close handlers.
        Array.prototype.forEach.call(overlay.querySelectorAll('[data-tr-close]'), function (b) {
            b.addEventListener('click', closeModal);
        });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('rp-open')) { closeModal(); }
        });
        form.addEventListener('submit', submitEntry);
    }

    /* Delegated edit/delete buttons in the ledger body. */
    body.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]');
        if (ed) { openEdit(ed.getAttribute('data-edit')); return; }
        var dl = e.target.closest('[data-del]');
        if (dl) { deleteEntry(dl.getAttribute('data-del')); }
    });

    /* ---- public surface for later sections (reconcile/charts) ---- */
    window.TrApp = {
        loadLedger: loadLedger,
        refreshSummary: refreshSummary,
        refreshAll: refreshAll,
        money: money,
        confirm: trConfirm,
        state: state
    };

    /* ---- init ---- */
    bindFilters();
    bindModal();
    if (cfg.initialLedger && cfg.initialLedger.Rows) { renderRows(cfg.initialLedger); }
    else { loadLedger(); }
    startPolling();
})();
</script>

<script>
/* =====================================================
   Treasury — reconciliation panel + opening-balance flow
   "Reconcile" records a snapshot of the real-world balance and compares
   it against the computed balance as of the chosen date. The first
   reconciliation (no opening yet) seeds the opening baseline.
   Relies on window.TrApp for shared helpers/refresh.
   ===================================================== */
(function () {
    'use strict';

    var app = document.getElementById('tr-app');
    if (!app) { return; }
    var cfg = window.TrConfig || {};
    var TrApp = window.TrApp || {};
    var money = TrApp.money || function (n) {
        return '$' + (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    var overlay   = document.getElementById('tr-recon-overlay');
    var form      = document.getElementById('tr-recon-form');
    var titleEl   = document.getElementById('tr-recon-title');
    var introEl   = document.getElementById('tr-recon-intro');
    var dateInput = document.getElementById('tr-r-date');
    var actualInp = document.getElementById('tr-r-actual');
    var actualLbl = document.getElementById('tr-r-actual-label');
    var computedEl = document.getElementById('tr-r-computed');
    var actualEcho = document.getElementById('tr-r-actual-echo');
    var statusEl   = document.getElementById('tr-r-status');
    var statusText = document.getElementById('tr-r-status-text');
    var varianceEl = document.getElementById('tr-r-variance');
    var compareBox = document.getElementById('tr-recon-compare');
    var explField  = document.getElementById('tr-r-expl-field');
    var explInput  = document.getElementById('tr-r-explanation');
    var saveBtn    = document.getElementById('tr-r-save');
    var reconBody  = document.getElementById('tr-recon-body');

    var fpRecon   = null;
    var isOpening = false;          // current modal mode
    var computedAsOf = 0;           // computed balance for the chosen as-of date
    var computeToken = 0;           // guards against out-of-order async compute responses

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* The opening baseline = the actual balance of the is_opening reconciliation.
       It anchors ComputeBalanceAsOf: any date before the first entry resolves to
       this base (matches the server). Returns 0 when no opening exists yet. */
    function openingBase() {
        var rows = cfg.reconciliations || [];
        for (var i = 0; i < rows.length; i++) {
            if (Number(rows[i].IsOpening) === 1) { return Number(rows[i].ActualBalance) || 0; }
        }
        return 0;
    }

    /* ---- field error helpers (mirror the entry modal) ---- */
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

    /* ---- reconciliation history rendering ---- */
    function varianceCell(v) {
        v = Number(v) || 0;
        if (Math.abs(v) < 0.005) { return money(0); }
        var cls = v > 0 ? 'tr-var-pos' : 'tr-var-neg';
        var sign = v > 0 ? '+' : '−'; // minus sign
        return '<span class="' + cls + '">' + sign + money(Math.abs(v)) + '</span>';
    }
    function renderHistory(rows) {
        rows = rows || [];
        reconBody.innerHTML = '';
        if (!rows.length) {
            var er = document.createElement('tr');
            er.className = 'tr-empty';
            er.innerHTML = '<td colspan="6">No reconciliations recorded yet.</td>';
            reconBody.appendChild(er);
            return;
        }
        rows.forEach(function (r) {
            var tr = document.createElement('tr');
            var opening = Number(r.IsOpening) === 1;
            tr.innerHTML =
                '<td>' + escapeHtml(r.AsOfDate) + (opening ? '<span class="rp-badge tr-badge-opening">Opening</span>' : '') + '</td>' +
                '<td class="tr-num">' + money(r.ActualBalance) + '</td>' +
                '<td class="tr-num">' + money(r.ComputedBalance) + '</td>' +
                '<td class="tr-num">' + (opening ? money(0) : varianceCell(r.Variance)) + '</td>' +
                '<td>' + escapeHtml(r.Explanation || '') + '</td>' +
                '<td>' + escapeHtml((r.CreatedAt || '').replace('T', ' ').slice(0, 16)) + '</td>';
            reconBody.appendChild(tr);
        });
    }
    function refreshHistory() {
        return fetch(cfg.ajax + 'reconciliations', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 0 && j.detail && j.detail.Rows) {
                    cfg.reconciliations = j.detail.Rows;
                    renderHistory(j.detail.Rows);
                }
                return j;
            });
    }

    /* ---- live compare ---- */
    /* Fetch the computed balance as of the chosen date (anchored to opening).
       Uses the ledger endpoint filtered by `to`; the newest shown row's
       RunningBalance is the computed balance through that date. */
    function fetchComputedAsOf(asOf, cb) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(asOf)) { cb(null); return; }
        var token = ++computeToken;
        var q = new URLSearchParams({ to: asOf, per: 1, page: 1 });
        fetch(cfg.ajax + 'ledger&' + q.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (token !== computeToken) { return; }  // stale response
                if (j.status !== 0 || !j.detail) { cb(null); return; }
                var rows = j.detail.Rows || [];
                if (rows.length) {
                    // Rows are newest-first; the first row through `to` carries the as-of balance.
                    cb(Number(rows[0].RunningBalance) || 0);
                } else {
                    // No entries on/before the date → balance is the opening baseline (or 0).
                    cb(openingBase());
                }
            })
            .catch(function () { if (token === computeToken) { cb(null); } });
    }

    function updateCompare() {
        if (isOpening) { return; } // opening mode: no comparison
        var actualRaw = actualInp.value;
        var hasActual = actualRaw !== '' && !isNaN(parseFloat(actualRaw));
        computedEl.textContent = money(computedAsOf);
        actualEcho.textContent = hasActual ? money(parseFloat(actualRaw)) : '—';
        statusEl.classList.remove('tr-recon-match', 'tr-recon-mismatch');
        varianceEl.textContent = '';
        if (!hasActual) {
            statusText.textContent = 'Enter an actual balance to compare.';
            explField.style.display = 'none';
            return;
        }
        var variance = Math.round((parseFloat(actualRaw) - computedAsOf) * 100) / 100;
        if (Math.abs(variance) < 0.01) {
            statusEl.classList.add('tr-recon-match');
            statusText.textContent = '✓ Balances match';
            explField.style.display = 'none';
        } else {
            statusEl.classList.add('tr-recon-mismatch');
            statusText.textContent = '✗ Off by';
            varianceEl.textContent = (variance > 0 ? '+' : '−') + money(Math.abs(variance));
            explField.style.display = '';
        }
    }

    function recomputeForDate() {
        var asOf = dateInput.value;
        if (isOpening) { return; }
        if (!/^\d{4}-\d{2}-\d{2}$/.test(asOf)) {
            computedAsOf = 0;
            computedEl.textContent = '—';
            updateCompare();
            return;
        }
        computedEl.textContent = '…';
        fetchComputedAsOf(asOf, function (bal) {
            computedAsOf = (bal == null) ? 0 : bal;
            updateCompare();
        });
    }

    /* ---- modal open/close ---- */
    function openModal(opening) {
        isOpening = !!opening;
        clearErrors();
        form.reset();
        explField.style.display = 'none';
        statusEl.classList.remove('tr-recon-match', 'tr-recon-mismatch');
        varianceEl.textContent = '';

        if (isOpening) {
            titleEl.textContent = 'Set Opening Balance';
            actualLbl.textContent = 'Opening Balance';
            introEl.textContent = 'Enter the current real-world balance and the date it is accurate as of. This becomes the starting point for all future tracking.';
            saveBtn.textContent = 'Set Opening Balance';
            compareBox.style.display = 'none';
        } else {
            titleEl.textContent = 'Reconcile';
            actualLbl.textContent = 'Actual Balance';
            introEl.textContent = 'Record the real-world balance as of a date and compare it against the computed balance. Differences require an explanation.';
            saveBtn.textContent = 'Save Reconciliation';
            compareBox.style.display = '';
            computedEl.textContent = '—';
            actualEcho.textContent = '—';
            statusText.textContent = 'Enter an actual balance to compare.';
        }

        overlay.classList.add('rp-open');
        overlay.setAttribute('aria-hidden', 'false');

        if (!fpRecon && window.flatpickr) {
            fpRecon = flatpickr(dateInput, {
                dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: false,
                onChange: function () { recomputeForDate(); }
            });
        }
        // Default the as-of date to today.
        var today = new Date();
        var iso = today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2) + '-' + ('0' + today.getDate()).slice(-2);
        if (fpRecon) { fpRecon.setDate(iso, true); } else { dateInput.value = iso; }
        if (!isOpening) { recomputeForDate(); }
        setTimeout(function () { actualInp.focus(); }, 30);
    }
    function closeModal() {
        overlay.classList.remove('rp-open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    /* ---- submit ---- */
    function submit(ev) {
        ev.preventDefault();
        clearErrors();
        var asOf   = dateInput.value || (fpRecon && fpRecon.input ? fpRecon.input.value : '');
        var actual = actualInp.value;
        var expl   = explInput.value.trim();

        var ok = true;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(asOf)) { markError('as_of_date'); ok = false; }
        if (actual === '' || isNaN(parseFloat(actual))) { markError('actual_balance'); ok = false; }
        // Mirror the lib: explanation required on a mismatch (non-opening only).
        if (ok && !isOpening) {
            var variance = Math.round((parseFloat(actual) - computedAsOf) * 100) / 100;
            if (Math.abs(variance) >= 0.01 && expl === '') {
                explField.style.display = '';
                markError('explanation'); ok = false;
            }
        }
        if (!ok) { return; }

        saveBtn.disabled = true;
        var fd = new URLSearchParams();
        fd.append('as_of_date', asOf);
        fd.append('actual_balance', actual);
        fd.append('explanation', isOpening ? '' : expl);
        fetch(cfg.ajax + 'addreconciliation', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        }).then(function (r) { return r.json(); }).then(function (j) {
            saveBtn.disabled = false;
            if (j.status !== 0) {
                markError('_form', j.error || 'Could not save this reconciliation.');
                return;
            }
            var wasOpening = j.detail && j.detail.IsOpening;
            closeModal();
            if (wasOpening) {
                // Opening just set: drop the first-run banner and flip the flag.
                cfg.hasOpening = true;
                var fr = document.getElementById('tr-firstrun');
                if (fr && fr.parentNode) { fr.parentNode.removeChild(fr); }
            }
            // The opening anchor changes running balances; refresh ledger + summary + charts.
            if (TrApp.refreshAll) { TrApp.refreshAll(); }
            refreshHistory();
        }).catch(function () {
            saveBtn.disabled = false;
            markError('_form', 'Network error — please try again.');
        });
    }

    /* ---- wiring ---- */
    actualInp.addEventListener('input', updateCompare);
    explInput.addEventListener('input', function () {
        if (explInput.value.trim() !== '') {
            var wrap = explInput.closest('.rp-field');
            if (wrap) { wrap.classList.remove('rp-has-err'); }
        }
    });
    form.addEventListener('submit', submit);

    Array.prototype.forEach.call(overlay.querySelectorAll('[data-tr-rclose]'), function (b) {
        b.addEventListener('click', closeModal);
    });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeModal(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('rp-open')) { closeModal(); }
    });

    var reconcileBtn = document.getElementById('tr-reconcile');
    if (reconcileBtn) {
        reconcileBtn.addEventListener('click', function () {
            // If there is no opening yet, route "Reconcile" into the opening flow too.
            openModal(!cfg.hasOpening);
        });
    }
    var openingBtn = document.getElementById('tr-set-opening');
    if (openingBtn) {
        openingBtn.addEventListener('click', function () { openModal(true); });
    }

    /* When the ledger/summary refresh (e.g. after an entry edit) the history is
       unaffected, but keep it consistent if another section requests a full refresh. */
    app.addEventListener('tr:reconchanged', refreshHistory);

    /* ---- init ---- */
    renderHistory(cfg.reconciliations || []);
})();
</script>

<script>
/* =====================================================
   Treasury — charts
   • Balance Over Time: full-width column (bar) chart from cfg.series (Month → Balance).
   • Two donuts from cfg.byCategory (key → total), split by group: Income
     (green palette) and Expenses (red/orange palette), labelled via cfg.categories.
   All re-render after any CRUD by listening for the 'tr:datachanged'
   event (dispatched by TrApp.refreshAll) — on which they
   refetch the `series` + `summary` endpoints so the charts stay in
   sync with the ledger. Dark-mode-aware via the _isDark pattern.
   ===================================================== */
(function () {
    'use strict';

    var app = document.getElementById('tr-app');
    if (!app) { return; }
    if (typeof Highcharts === 'undefined') { return; } // CDN unavailable → skip silently
    var cfg = window.TrConfig || {};

    var balanceChart = null;
    var catCharts    = { income: null, expense: null };
    // Distinct slices within each donut (greens for income, reds/oranges for expense).
    var CAT_PALETTE = {
        income:  ['#2f855a', '#38a169', '#48bb78', '#68d391', '#276749', '#9ae6b4'],
        expense: ['#c53030', '#e53e3e', '#dd6b20', '#ed8936', '#9b2c2c', '#f6ad55']
    };

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

    /* Pretty month label: 'YYYY-MM' → 'Mon YYYY'. */
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function monthLabel(ym) {
        var m = /^(\d{4})-(\d{2})$/.exec(ym || '');
        if (!m) { return ym || ''; }
        return MONTHS[(parseInt(m[2], 10) - 1) % 12] + ' ' + m[1];
    }

    function renderBalanceChart(points) {
        points = points || [];
        var categories = points.map(function (p) { return monthLabel(p.Month); });
        var data       = points.map(function (p) { return Number(p.Balance) || 0; });
        var dark = isDark();
        var ax   = axisColors();
        var hostEl = document.getElementById('tr-chart-balance');
        if (!hostEl) { return; }

        if (!points.length) {
            if (balanceChart) { balanceChart.destroy(); balanceChart = null; }
            hostEl.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--ork-text-muted);font-size:0.85rem;">No balance history yet.</div>';
            return;
        }

        if (balanceChart) {
            balanceChart.update({ tooltip: tooltipOpts() }, false);
            balanceChart.xAxis[0].update({ categories: categories }, false);
            balanceChart.series[0].setData(data, false);
            balanceChart.redraw();
            return;
        }

        balanceChart = new Highcharts.Chart({
            chart: { renderTo: 'tr-chart-balance', type: 'column', backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
            title: { text: null },
            xAxis: {
                categories: categories,
                lineColor: ax.line, tickColor: ax.line,
                labels: { style: { color: ax.label, fontSize: '11px' } }
            },
            yAxis: {
                title: { text: null }, gridLineColor: ax.grid,
                labels: { style: { color: ax.label, fontSize: '11px' }, formatter: function () { return money(this.value); } }
            },
            plotOptions: { column: { borderRadius: 2, borderWidth: 0, maxPointWidth: 48, pointPadding: 0.05, groupPadding: 0.08 } },
            series: [{ name: 'Balance', data: data, color: '#4338ca' }],
            legend: { enabled: false },
            credits: { enabled: false },
            tooltip: Object.assign({
                headerFormat: '<b>{point.key}</b><br/>',
                pointFormatter: function () { return 'Balance: <b>' + money(this.y) + '</b>'; }
            }, tooltipOpts())
        });
    }

    /* Render one donut for a single group ('income' or 'expense') from byCategory. */
    function renderCatChart(group, hostId, byCategory) {
        byCategory = byCategory || {};
        var labels = cfg.categories || {};
        var groupKeys = (cfg.categoryGroups && cfg.categoryGroups[group]) || {};
        var palette = CAT_PALETTE[group] || ['#4338ca'];
        var data = [];
        Object.keys(byCategory).forEach(function (key) {
            if (!Object.prototype.hasOwnProperty.call(groupKeys, key)) { return; }
            var val = Math.abs(Number(byCategory[key]) || 0);
            if (val <= 0) { return; }
            data.push({ name: labels[key] || key, y: val, color: palette[data.length % palette.length] });
        });
        var hostEl = document.getElementById(hostId);
        if (!hostEl) { return; }

        if (!data.length) {
            if (catCharts[group]) { catCharts[group].destroy(); catCharts[group] = null; }
            hostEl.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--ork-text-muted);font-size:0.85rem;">No ' + group + ' yet.</div>';
            return;
        }

        var dark = isDark();
        if (catCharts[group]) {
            catCharts[group].update({ tooltip: tooltipOpts() }, false);
            catCharts[group].series[0].update({ dataLabels: { style: { color: dark ? '#e2e8f0' : '#333333' } } }, false);
            catCharts[group].series[0].setData(data, false);
            catCharts[group].redraw();
            return;
        }

        catCharts[group] = new Highcharts.Chart({
            chart: { renderTo: hostId, type: 'pie', backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
            title: { text: null },
            series: [{
                name: group === 'income' ? 'Income' : 'Expenses', data: data, innerSize: '55%',
                dataLabels: {
                    enabled: true,
                    formatter: function () { return this.point.name + ': ' + money(this.y); },
                    style: { color: dark ? '#e2e8f0' : '#333333', fontSize: '11px', textOutline: 'none' }
                }
            }],
            legend: { enabled: false },
            credits: { enabled: false },
            tooltip: Object.assign({
                headerFormat: '',
                pointFormatter: function () { return '<b>' + this.name + '</b>: ' + money(this.y); }
            }, tooltipOpts())
        });
    }

    function renderAll() {
        renderBalanceChart(cfg.series || []);
        renderCatChart('income', 'tr-chart-income', cfg.byCategory || {});
        renderCatChart('expense', 'tr-chart-expense', cfg.byCategory || {});
    }

    /* Refetch the data the charts depend on, then re-render. Called after any
       CRUD (entry add/edit/delete, reconciliation) via 'tr:datachanged'. */
    function refetchAndRender() {
        var seriesP = fetch(cfg.ajax + 'series', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 0 && j.detail && j.detail.Points) { cfg.series = j.detail.Points; }
            }).catch(function () {});
        // refreshSummary() already updates cfg.byCategory on a datachanged cycle,
        // but fetch it here too so the chart is correct even if charts load independently.
        var sumP = fetch(cfg.ajax + 'summary', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status === 0 && j.detail) { cfg.byCategory = j.detail.ByCategory || {}; }
            }).catch(function () {});
        Promise.all([seriesP, sumP]).then(renderAll);
    }

    /* Re-theme charts when dark mode is toggled (re-render picks up colours). */
    var themeObserver = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
            if (muts[i].attributeName === 'data-theme') { renderAll(); break; }
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    /* ---- wiring ---- */
    app.addEventListener('tr:datachanged', refetchAndRender);

    /* ---- init ---- */
    renderAll();
})();
</script>
