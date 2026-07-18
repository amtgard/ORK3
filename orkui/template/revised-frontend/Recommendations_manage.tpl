<?php if (!empty($Error)) { ?>
  <div class="rm-wrap"><div class="rm-error"><?= htmlspecialchars($Error) ?></div></div>
<?php return; }
  $backUrl = $ParkId > 0
    ? UIR . 'Park/index/' . (int)$ParkId
    : UIR . 'Kingdom/index/' . (int)$KingdomId;
?>
<style>
.rm-wrap {
    --rm-line: #d8d8d8;
    --rm-bg: #fff;
    --rm-bg2: #f6f6f6;
    --rm-fg: #222;
    --rm-muted: #777;
    --rm-accent: #2c5f8b;
    --rm-danger: #b03030;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 12px 80px;
    box-sizing: border-box;
    color: var(--rm-fg);
}
html[data-theme="dark"] .rm-wrap {
    --rm-line: #3a3f47;
    --rm-bg: #1e2127;
    --rm-bg2: #23262d;
    --rm-fg: #e6e6e6;
    --rm-muted: #9aa0a8;
    --rm-accent: #6fb0e6;
    --rm-danger: #e07070;
}

/* Hero */
.rm-hero { padding: 14px 4px 10px; }
.rm-back {
    display: inline-block;
    font-size: 13px;
    color: var(--rm-accent);
    text-decoration: none;
    margin-bottom: 6px;
}
.rm-back:hover { text-decoration: underline; }
.rm-title {
    background: transparent;
    border: none;
    padding: 0;
    border-radius: 0;
    text-shadow: none;
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: var(--rm-fg);
}
.rm-sub { font-size: 13px; color: var(--rm-muted); margin-top: 2px; }

/* Filter bar */
.rm-filterbar {
    position: sticky;
    top: 48px; /* below the app's 48px fixed top nav */
    z-index: 5;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    padding: 8px 4px;
    background: var(--rm-bg);
    border-bottom: 1px solid var(--rm-line);
}
.rm-search, .rm-fsel {
    font-size: 13px;
    padding: 5px 8px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-search { min-width: 200px; }
.rm-search::placeholder { color: var(--rm-muted); }
.rm-fcheck { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: var(--rm-fg); cursor: pointer; }
.rm-fcheck input { margin: 0; }
/* Export button — right-aligned in the filter bar. */
.rm-fbtn {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-size: 13px;
    padding: 5px 10px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
    white-space: nowrap;
}
.rm-fbtn:hover { border-color: var(--rm-accent); color: var(--rm-accent); }
.rm-fbtn[disabled] { opacity: .6; cursor: default; }
.rm-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.rm-chip {
    cursor: pointer;
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 12px;
    background: var(--rm-bg2);
    border: 1px solid var(--rm-line);
    color: var(--rm-fg);
}
.rm-chip:hover { border-color: var(--rm-accent); }

/* Grid */
/* NOTE: no overflow here — an overflow context would scope the sticky thead to
   this wrapper (which never scrolls vertically) and break the frozen header on
   window scroll. The table is full-width; very narrow viewports scroll the page. */
.rm-gridwrap { }
.rm-grid {
    border-collapse: collapse;
    width: 100%;
    font-size: 13px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-grid th, .rm-grid td {
    border: 1px solid var(--rm-line);
    padding: 4px 8px;
    text-align: left;
    vertical-align: top;
}
.rm-grid thead th {
    position: sticky;
    top: 94px; /* 48px fixed nav + 46px sticky filter bar */
    z-index: 4;
    background: var(--rm-bg2);
    font-weight: 700;
    white-space: nowrap;
}
.rm-row:nth-child(even) { background: var(--rm-bg2); }
.rm-row:hover { background: rgba(127, 127, 127, 0.10); }

/* Sticky recipient column */
.rm-col-recip { position: sticky; left: 0; z-index: 1; background: var(--rm-bg); }
.rm-row:nth-child(even) .rm-col-recip { background: var(--rm-bg2); }
thead .rm-col-recip { z-index: 6; background: var(--rm-bg2); }

/* tabular numbers */
.rm-date, .rm-age, .rm-rank, .rm-supp-chip { font-variant-numeric: tabular-nums; }

.rm-col-sel { width: 28px; text-align: center; }
thead .rm-col-sel { text-align: center; }
.rm-col-act { white-space: nowrap; }

.rm-sortable { cursor: pointer; user-select: none; }
.rm-sort-asc::after { content: " \25B2"; font-size: 9px; }
.rm-sort-desc::after { content: " \25BC"; font-size: 9px; }

/* Recipient cell */
.rm-col-recip a { color: var(--rm-accent); text-decoration: none; font-weight: 600; }
.rm-col-recip a:hover { text-decoration: underline; }
.rm-park {
    display: inline-block;
    margin-left: 4px;
    font-size: 11px;
    color: var(--rm-muted);
    border: 1px solid var(--rm-line);
    border-radius: 3px;
    padding: 0 4px;
}
a.rm-park { text-decoration: none; cursor: pointer; }
a.rm-park:hover { color: var(--rm-accent); border-color: var(--rm-accent); }
/* Park column (split out of the Recipient cell) */
.rm-col-park { white-space: nowrap; }
.rm-col-park .rm-park { margin-left: 0; }

/* Award cell */
.rm-rank {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    color: var(--rm-muted);
}
.rm-rank.rm-nonladder { font-style: italic; }

/* Rank column (split out of the Award cell) */
.rm-col-rank { white-space: nowrap; }
.rm-col-rank .rm-rank { margin-left: 0; }
.rm-badge {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    padding: 0 5px;
    border-radius: 3px;
    border: 1px solid var(--rm-line);
}
.rm-badge-has { color: #8a6d00; background: rgba(240, 200, 0, 0.14); border-color: rgba(240, 200, 0, 0.4); }
.rm-badge-below { color: var(--rm-danger); background: rgba(176, 48, 48, 0.10); border-color: rgba(176, 48, 48, 0.35); }
html[data-theme="dark"] .rm-badge-has { color: #e0c860; }
.rm-badge-passlocal { color: #2c5f8b; background: rgba(44, 95, 139, 0.12); border-color: rgba(44, 95, 139, 0.4); }
html[data-theme="dark"] .rm-badge-passlocal { color: #6fb0e6; }
.rm-act-passlocal.rm-act-active { background: var(--rm-accent); color: #fff; border-color: var(--rm-accent); }
.rm-row[data-passlocal="1"] { box-shadow: inset 3px 0 0 var(--rm-accent); }
/* Pass-down tooltip: rich (bold title line + body) — data-tip can't bold/format,
   so this button uses a child tooltip span shown on hover. Right-anchored so it
   never clips off the right edge. */
.rm-act-passlocal { position: relative; }
/* Shared rich-tooltip body — Pass-down (.rm-passlocal-tip) and Snooze
   (.rm-snooze-tip) render byte-identical tooltips; only the :hover trigger
   selectors differ. (The spans carry these class names from _rm_row.tpl.) */
.rm-passlocal-tip, .rm-snooze-tip {
    display: none;
    position: absolute;
    right: 0;
    bottom: calc(100% + 4px);
    width: max-content;
    max-width: 240px;
    background: #222;
    color: #fff;
    font-size: 11px;
    font-weight: 400;
    line-height: 1.35;
    text-align: left;
    padding: 5px 8px;
    border-radius: 4px;
    white-space: normal;
    z-index: 50;
    pointer-events: none;
}
.rm-act-passlocal:hover .rm-passlocal-tip { display: block; }
.rm-passlocal-tip strong, .rm-snooze-tip strong { display: block; font-weight: 700; margin-bottom: 3px; }
html[data-theme="dark"] .rm-passlocal-tip, html[data-theme="dark"] .rm-snooze-tip { background: #000; }

/* Recommended cell */
.rm-by { display: block; }
.rm-date { display: inline-block; color: var(--rm-muted); }
.rm-age { display: inline-block; margin-left: 6px; color: var(--rm-muted); font-size: 11px; }

/* Reason + support */
.rm-empty { color: var(--rm-muted); }
.rm-reason-trunc {
    display: inline-block;
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
}
.rm-expand-reason, .rm-supp-chip, .rm-expand-members {
    cursor: pointer;
    font-size: 12px;
    padding: 1px 6px;
    margin-left: 4px;
    border: 1px solid var(--rm-line);
    border-radius: 3px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-expand-reason:hover, .rm-supp-chip:hover, .rm-expand-members:hover { border-color: var(--rm-accent); }

/* Court badge */
.rm-courtbadge {
    display: inline-block;
    font-size: 12px;
    /* !important + fixed steel-blue bg: the app's global `a` link color
       otherwise wins over this class, rendering light-blue-on-light-blue. */
    color: #fff !important;
    background: #2c5f8b;
    border-radius: 3px;
    padding: 1px 6px;
    text-decoration: none;
}
.rm-courtbadge:hover { opacity: 0.9; }
.rm-courtmore { font-weight: 700; }

/* Action buttons */
.rm-act {
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    padding: 3px 5px;
    margin: 0 1px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-act:hover { border-color: var(--rm-accent); background: var(--rm-bg2); }
.rm-act-dismiss:hover { border-color: var(--rm-danger); }

.rm-loading { text-align:center; padding:14px; color:var(--rm-muted); font-size:13px; }

/* Footer */
.rm-foot {
    padding: 8px 4px;
    font-size: 12px;
    color: var(--rm-muted);
    border-top: 1px solid var(--rm-line);
}

/* Detail rows (Task 5) */
.rm-detailrow td { background: var(--rm-bg2); }
.rm-detailrow .rm-col-recip { background: var(--rm-bg2); }
.rm-seclist {
    margin: 0;
    padding: 4px 0 4px 8px;
    list-style: none;
    font-size: 13px;
}
.rm-seclist li { padding: 1px 0; }
.rm-reason-full {
    white-space: pre-wrap;
    font-size: 13px;
    padding: 2px 0;
}

/* data-tip tooltips (no native title) */
[data-tip] { position: relative; }
[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 4px);
    transform: translateX(-50%);
    /* wrap long tooltips instead of running off-screen: compact for short text
       (max-content), wraps once it would exceed max-width. */
    white-space: normal;
    width: max-content;
    max-width: 240px;
    line-height: 1.3;
    text-align: left;
    background: #222;
    color: #fff;
    font-size: 11px;
    padding: 4px 7px;
    border-radius: 4px;
    z-index: 50;
    pointer-events: none;
}
/* Actions-column buttons sit at the right edge — anchor their tooltip to the
   button's right so a wrapped tooltip extends leftward and never clips off-screen. */
.rm-col-act [data-tip]:hover::after {
    left: auto;
    right: 0;
    transform: none;
}
html[data-theme="dark"] [data-tip]:hover::after { background: #000; }
/* Snooze button uses a rich tooltip (bold title + description) rather than the
   plain data-tip; mirrors .rm-passlocal-tip and right-anchors so it never clips. */
.rm-act-snooze { position: relative; }
/* Body/strong/dark styles are shared with .rm-passlocal-tip above; only the
   distinct :hover trigger lives here. */
.rm-act-snooze:hover .rm-snooze-tip { display: block; }

/* Bulk action bar (Task 7) */
.rm-bulkbar {
    position: sticky;
    bottom: 0;
    z-index: 6;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    padding: 8px 10px;
    margin-top: 6px;
    /* Brand header blue so the bar clearly stands out once rows are selected. */
    background: #2c5f8b;
    border: 1px solid #234d73;
    border-radius: 6px;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.22);
}
html[data-theme="dark"] .rm-bulkbar { background: #23557f; border-color: #16344d; }
.rm-bulkbar[hidden] { display: none; }
#rm-bulklabel { font-size: 13px; font-weight: 700; color: #fff; margin-right: 4px; }
.rm-bulk {
    cursor: pointer;
    font-size: 13px;
    padding: 5px 10px;
    border: 1px solid rgba(255, 255, 255, 0.55);
    border-radius: 4px;
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
}
.rm-bulk:hover { border-color: #fff; background: rgba(255, 255, 255, 0.24); }
.rm-bulk-dismiss:hover { border-color: #ffd4d4; background: rgba(176, 48, 48, 0.55); color: #fff; }

/* Toast (Task 8) */
.rm-toast {
    position: fixed;
    bottom: 18px;
    right: 18px;
    z-index: 9999;
    max-width: 320px;
    padding: 10px 14px;
    font-size: 13px;
    color: #fff;
    background: #2c3a4a;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-left: 4px solid var(--rm-accent, #2c5f8b);
    border-radius: 5px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
    opacity: 1;
    transition: opacity 0.4s ease;
}
.rm-toast-err {
    background: #4a2c2c;
    border-left-color: var(--rm-danger, #b03030);
}
.rm-toast-out { opacity: 0; }
html[data-theme="dark"] .rm-toast { background: #2a2f37; }
html[data-theme="dark"] .rm-toast-err { background: #3a2424; }

.rm-error {
    padding: 16px;
    margin: 24px auto;
    max-width: 480px;
    border: 1px solid var(--rm-danger, #b03030);
    border-radius: 6px;
    color: #b03030;
    background: rgba(176, 48, 48, 0.08);
    text-align: center;
}

/* Add-to-Court modal (Task 10) */
.rm-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9998;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, 0.45);
}
.rm-modal-overlay[hidden] { display: none; }
.rm-modal {
    --rm-line: #d8d8d8;
    --rm-bg: #fff;
    --rm-bg2: #f6f6f6;
    --rm-fg: #222;
    --rm-muted: #777;
    --rm-accent: #2c5f8b;
    width: 100%;
    max-width: 440px;
    background: var(--rm-bg);
    color: var(--rm-fg);
    border: 1px solid var(--rm-line);
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.35);
    padding: 18px 20px 16px;
    box-sizing: border-box;
}
html[data-theme="dark"] .rm-modal {
    --rm-line: #3a3f47;
    --rm-bg: #23262d;
    --rm-bg2: #1e2127;
    --rm-fg: #e6e6e6;
    --rm-muted: #9aa0a8;
    --rm-accent: #6fb0e6;
}
.rm-modal-title {
    background: transparent;
    border: none;
    padding: 0;
    border-radius: 0;
    text-shadow: none;
    margin: 0 0 4px;
    font-size: 18px;
    font-weight: 700;
    color: var(--rm-fg);
}
.rm-modal-sub { font-size: 13px; color: var(--rm-muted); margin-bottom: 12px; }
.rm-modal-modes {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
    font-size: 13px;
    color: var(--rm-fg);
}
.rm-modal-modes label { cursor: pointer; }
#rm-court-existing, #rm-court-new { margin-bottom: 12px; }
.rm-modal .rm-fsel, .rm-modal .rm-input {
    width: 100%;
    box-sizing: border-box;
    font-size: 13px;
    padding: 7px 9px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-modal .rm-input { margin-bottom: 8px; }
.rm-modal .rm-input:last-child { margin-bottom: 0; }
.rm-modal .rm-input::placeholder { color: var(--rm-muted); }
.rm-modal .rm-empty { font-size: 13px; }
.rm-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 4px;
}
.rm-modal-actions-stack {
    flex-direction: column;
    align-items: stretch;
}
.rm-modal-actions-stack .rm-btn { text-align: center; }
.rm-btn {
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    padding: 7px 16px;
    border-radius: 4px;
    border: 1px solid var(--rm-line);
}
.rm-btn-ghost {
    background: transparent;
    color: var(--rm-fg);
}
.rm-btn-ghost:hover { background: var(--rm-bg2); border-color: var(--rm-accent); }
.rm-btn-primary {
    background: var(--rm-accent);
    border-color: var(--rm-accent);
    color: #fff;
}
.rm-btn-primary:hover { opacity: 0.92; }
.rm-btn-primary:disabled { opacity: 0.55; cursor: default; }
/* Grant Award modal fields */
.rm-field { margin-bottom: 12px; }
.rm-field > label { display: block; font-size: 12px; font-weight: 600; color: var(--rm-fg); margin-bottom: 4px; }
.rm-field .rm-input, .rm-field textarea.rm-input { margin-bottom: 0; }
.rm-field textarea.rm-input { resize: vertical; min-height: 56px; font-family: inherit; }
.rm-field-hint { font-size: 11px; color: var(--rm-muted); margin-top: 4px; line-height: 1.4; }
.rm-form-error { background: rgba(176, 48, 48, 0.12); border: 1px solid var(--rm-danger); color: var(--rm-danger); font-size: 13px; padding: 7px 10px; border-radius: 5px; margin-bottom: 12px; }
.rm-form-error[hidden] { display: none; }
.rm-radio-row { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 400; color: var(--rm-fg); margin: 4px 0; cursor: pointer; }
.rm-radio-row input { margin: 0; }
/* Given-By autocomplete dropdown */
.rm-ac-wrap { position: relative; }
.rm-ac-results { position: absolute; left: 0; right: 0; top: calc(100% + 2px); z-index: 20; background: var(--rm-bg); border: 1px solid var(--rm-line); border-radius: 5px; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25); max-height: 220px; overflow-y: auto; display: none; }
.rm-ac-results.rm-ac-open { display: block; }
.rm-ac-item { padding: 7px 10px; font-size: 13px; color: var(--rm-fg); cursor: pointer; }
.rm-ac-item:hover, .rm-ac-item.rm-ac-active { background: var(--rm-bg2); }
.rm-ac-none { padding: 7px 10px; font-size: 12px; color: var(--rm-muted); }
/* Officer quick-pick chips (Given By) */
.rm-officer-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.rm-officer-chip { font-size: 12px; padding: 4px 9px; border: 1px solid var(--rm-line); border-radius: 14px; background: var(--rm-bg2); color: var(--rm-fg); cursor: pointer; }
.rm-officer-chip span { color: var(--rm-muted); }
.rm-officer-chip:hover { border-color: var(--rm-accent); }
.rm-officer-chip.rm-selected { background: var(--rm-accent); border-color: var(--rm-accent); color: #fff; }
.rm-officer-chip.rm-selected span { color: rgba(255, 255, 255, 0.85); }
/* Inline field hints */
.rm-field-hint-inline { color: var(--rm-muted); font-weight: 400; font-size: 11px; }
/* Rank pills (select the rank being granted; green = already held) */
.rm-rank-pills { display: flex; flex-wrap: wrap; gap: 5px; }
.rm-rank-pill { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; border: 1px solid var(--rm-line); border-radius: 6px; background: var(--rm-bg); color: var(--rm-fg); cursor: pointer; user-select: none; }
.rm-rank-pill:hover { border-color: var(--rm-accent); }
.rm-rank-pill.rm-rank-held { background: #2f855a; border-color: #2f855a; color: #fff; }
.rm-rank-pill.rm-rank-forward { background: rgba(44, 95, 139, 0.14); border-color: var(--rm-accent); }
.rm-rank-pill.rm-rank-selected { outline: 2px solid var(--rm-accent); outline-offset: 1px; }
html[data-theme="dark"] .rm-rank-pill.rm-rank-held { background: #38a169; border-color: #38a169; }
</style>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/rank-pill.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/rank-pill.css') ?>">

<div class="rp-root">
<div class="rm-wrap">
  <div class="rp-header">
    <div class="rp-header-left">
      <div class="rp-header-icon-title">
        <i class="fas fa-star rp-header-icon"></i>
        <h1 class="rp-header-title">Recommendations Manager</h1>
      </div>
      <div class="rp-header-scope">
        <a class="rp-scope-chip" href="<?= htmlspecialchars($backUrl) ?>">
          <i class="fas fa-<?= $ParkId > 0 ? 'map-marker-alt' : 'crown' ?>"></i>
          <span class="rp-scope-chip-label"><?= $ParkId > 0 ? 'Park' : 'Kingdom' ?>:</span> <?= htmlspecialchars($LocationName) ?>
        </a>
      </div>
    </div>
    <div class="rp-header-actions">
      <a class="rp-btn-ghost" href="<?= htmlspecialchars($backUrl) ?>"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
  </div>
  <div class="rp-context">
    <i class="fas fa-info-circle rp-context-icon"></i>
    <span>Review, grant, dismiss, snooze, pass-down, and schedule award recommendations for <strong><?= htmlspecialchars($LocationName) ?></strong> &mdash; <?= (int)($Total ?? 0) ?> pending.</span>
  </div>

  <div class="rm-filterbar" id="rm-filterbar">
    <input type="search" id="rm-search" class="rm-search" placeholder="Search recipient&hellip;" autocomplete="off">
    <select id="rm-filter-elig" class="rm-fsel">
      <option value="open" selected>Open Recs</option>
      <option value="below">Below Rec&rsquo;d</option>
      <option value="nonladder">Non-Ladder</option>
      <option value="ator">At or Above Rec&rsquo;d</option>
      <option value="all">All</option>
      <option value="snoozed">Snoozed</option>
    </select>
    <select id="rm-filter-court" class="rm-fsel">
      <option value="all">Any court status</option>
      <option value="none">Not on a court</option>
      <option value="any">On any court</option>
      <?php foreach ($Courts as $c) { ?>
        <option value="court:<?= (int)$c['CourtId'] ?>">On: <?= htmlspecialchars($c['Name']) ?></option>
      <?php } ?>
    </select>
    <?php if ($ParkId === 0 && count($Parks)) { ?>
    <select id="rm-filter-park" class="rm-fsel">
      <option value="all">All parks</option>
      <?php foreach ($Parks as $pid => $p) { ?>
        <option value="<?= (int)$pid ?>"><?= htmlspecialchars($p['Name']) ?></option>
      <?php } ?>
    </select>
    <?php } ?>
    <label class="rm-fcheck"><input type="checkbox" id="rm-filter-passlocal"> Passed to local</label>
    <div id="rm-chips" class="rm-chips"></div>
    <button type="button" id="rm-export" class="rm-fbtn" data-tip="Download the full current filtered list as a CSV file"><i class="fas fa-download"></i> Export CSV</button>
  </div>

  <div class="rm-gridwrap">
  <table class="rm-grid" id="rm-grid">
    <thead>
      <tr>
        <th class="rm-col-sel"><input type="checkbox" id="rm-selall"></th>
        <th class="rm-col-recip rm-sortable" data-sort="recip">Recipient</th>
        <th class="rm-col-park">Park</th>
        <th class="rm-col-award rm-sortable" data-sort="award">Award</th>
        <th class="rm-col-rank rm-sortable" data-sort="rank">Rank</th>
        <th class="rm-col-rec rm-sortable" data-sort="date">Recommended</th>
        <th class="rm-col-reason">Reason</th>
        <th class="rm-col-supp rm-sortable" data-sort="supp">Support</th>
        <th class="rm-col-court">Court</th>
        <th class="rm-col-act">Actions</th>
      </tr>
    </thead>
    <tbody id="rm-tbody">
    <?php foreach ($Groups as $group) { include __DIR__ . '/_rm_row.tpl'; } ?>
    </tbody>
  </table>
  </div>
  <div id="rm-loading" class="rm-loading" style="display:none">Loading&hellip;</div>
  <div id="rm-sentinel" style="height:1px"></div>
  <div class="rm-foot">Showing <span id="rm-count"><?= count($Groups) ?></span> of <span id="rm-total"><?= (int)($Total ?? 0) ?></span> &middot; <span id="rm-selcount">0</span> selected</div>

  <div class="rm-bulkbar" id="rm-bulkbar" hidden>
    <span id="rm-bulklabel">0 selected</span>
    <button type="button" class="rm-bulk rm-bulk-court">Add to Court</button>
    <button type="button" class="rm-bulk rm-bulk-snooze">Snooze</button>
    <?php if (($Context ?? '') === 'kingdom') { ?><button type="button" class="rm-bulk rm-bulk-passlocal" data-tip="For recommendations at a higher level than the park can provide, you are granting authority for that park to award at this level.">Pass down</button><?php } ?>
    <button type="button" class="rm-bulk rm-bulk-dismiss" data-tip="Already given out previously? No plans to award this? You can dismiss this rec.">Dismiss</button>
    <button type="button" class="rm-bulk rm-bulk-clear">Clear</button>
  </div>

  <!-- Task 10: Add-to-Court modal -->
  <div class="rm-modal-overlay" id="rm-court-overlay" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title">Add to Court</h2>
      <div class="rm-modal-sub" id="rm-court-sub"></div>
      <div class="rm-modal-modes">
        <label><input type="radio" name="rm-court-mode" value="existing" checked> Existing court</label>
        <label><input type="radio" name="rm-court-mode" value="new"> Create new court</label>
      </div>
      <div id="rm-court-existing">
        <select id="rm-court-select" class="rm-fsel">
          <?php foreach ($Courts as $c) { ?>
            <option value="<?= (int)$c['CourtId'] ?>"><?= htmlspecialchars($c['Name']) ?><?= !empty($c['CourtDate']) ? ' &mdash; ' . htmlspecialchars($c['CourtDate']) : '' ?> (<?= htmlspecialchars($c['Status']) ?>)</option>
          <?php } ?>
        </select>
        <?php if (!count($Courts)) { ?><div class="rm-empty">No courts yet &mdash; create one.</div><?php } ?>
      </div>
      <div id="rm-court-new" hidden>
        <input type="text" id="rm-court-name" class="rm-input" placeholder="Court name" maxlength="100">
        <input type="text" id="rm-court-date" class="rm-input" placeholder="Court date (optional)">
      </div>
      <div class="rm-modal-actions">
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-court-cancel">Cancel</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-court-submit">Add</button>
      </div>
    </div>
  </div>

  <!-- Grant Award modal: pre-filled from the rec. The ⚡ button always opens this —
       we never insta-grant. When the rec is on a court plan, the officer also picks
       what happens to the planned court award. -->
  <div class="rm-modal-overlay" id="rm-grant-overlay" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title">Grant Award</h2>
      <div class="rm-modal-sub" id="rm-grant-sub"></div>
      <div class="rm-form-error" id="rm-grant-error" hidden></div>
      <div class="rm-field" id="rm-grant-rank-wrap" hidden>
        <label>Rank <span class="rm-field-hint-inline">&mdash; green ranks are already held; grant a higher rank if earned</span></label>
        <div class="rm-rank-pills" id="rm-grant-rank-pills"></div>
        <input type="hidden" id="rm-grant-rank-val">
      </div>
      <div class="rm-field">
        <label for="rm-grant-date">Date</label>
        <input type="date" id="rm-grant-date" class="rm-input">
      </div>
      <div class="rm-field">
        <label for="rm-grant-givenby">Given by</label>
<?php if (!empty($PreloadOfficers)): ?>
        <div class="rm-officer-chips" id="rm-grant-officer-chips">
<?php foreach ($PreloadOfficers as $officer): ?>
          <button type="button" class="rm-officer-chip" data-id="<?= (int)$officer['MundaneId'] ?>" data-name="<?= htmlspecialchars($officer['Persona'], ENT_QUOTES) ?>"><?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span></button>
<?php endforeach; ?>
        </div>
<?php endif; ?>
        <div class="rm-ac-wrap">
          <input type="text" id="rm-grant-givenby" class="rm-input" autocomplete="off" placeholder="Search a player&hellip;">
          <input type="hidden" id="rm-grant-givenby-id">
          <div class="rm-ac-results" id="rm-grant-givenby-results"></div>
        </div>
        <div class="rm-field-hint">Defaults to you. For an association (e.g. a Knight taking a Squire), set the granter here.</div>
      </div>
      <div class="rm-field">
        <label for="rm-grant-givenat">Given at <span class="rm-field-hint-inline">(optional)</span></label>
        <div class="rm-ac-wrap">
          <input type="text" id="rm-grant-givenat" class="rm-input" autocomplete="off" placeholder="Search park, kingdom, or event&hellip;">
          <div class="rm-ac-results" id="rm-grant-givenat-results"></div>
        </div>
        <input type="hidden" id="rm-grant-park-id">
        <input type="hidden" id="rm-grant-kingdom-id">
        <input type="hidden" id="rm-grant-event-id" value="0">
      </div>
      <div class="rm-field">
        <label for="rm-grant-note">Note</label>
        <textarea id="rm-grant-note" class="rm-input" rows="3"></textarea>
      </div>
      <div class="rm-field" id="rm-grant-court-wrap" hidden>
        <label id="rm-grant-court-label">This recommendation is on a court plan</label>
        <label class="rm-radio-row"><input type="radio" name="rm-grant-court" value="remove" checked> Grant &amp; remove from court</label>
        <label class="rm-radio-row"><input type="radio" name="rm-grant-court" value="leave"> Grant &amp; leave on court</label>
      </div>
      <div class="rm-modal-actions">
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-grant-cancel">Cancel</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-grant-submit">Grant Award</button>
      </div>
    </div>
  </div>
</div>
</div>

<script>
window.RmConfig = {
  uir: '<?= UIR ?>',
  kingdomId: <?= (int)$KingdomId ?>,
  parkId: <?= (int)$ParkId ?>,
  context: '<?= $Context === 'park' ? 'park' : 'kingdom' ?>',
  userId: <?= (int)$Uid ?>,
  userName: <?= json_encode((string)($UserName ?? '')) ?>,
  httpService: <?= json_encode((string)(defined('HTTP_SERVICE') ? HTTP_SERVICE : '')) ?>,
  locationName: <?= json_encode((string)($LocationName ?? '')) ?>,
  rowsUrl:    '<?= UIR ?>Recommendations/rows/<?= $Context ?>/<?= $Context === 'park' ? (int)$ParkId : (int)$KingdomId ?>',
  exportUrl:  '<?= UIR ?>Recommendations/export/<?= $Context ?>/<?= $Context === 'park' ? (int)$ParkId : (int)$KingdomId ?>',
  total:      <?= (int)($Total ?? 0) ?>,
  hasMore:    <?= !empty($HasMore) ? 'true' : 'false' ?>,
  nextOffset: <?= (int)($NextOffset ?? 0) ?>
};
</script>

<script>
// HTML-escape helper for JS-built markup.
function rmEsc(s) {
    var d = document.createElement('div');
    d.textContent = (s == null) ? '' : String(s);
    return d.innerHTML;
}

// Insert (or toggle off) an inline detail row directly after `tr`.
function rmInsertDetail(tr, html, cls) {
    var next = tr.nextElementSibling;
    if (next && next.classList.contains(cls)) { next.remove(); return; } // toggle off
    // If a different detail row is open, replace it.
    if (next && next.classList.contains('rm-detailrow')) { next.remove(); }
    var dr = document.createElement('tr');
    dr.className = 'rm-detailrow ' + cls;
    dr.innerHTML = '<td></td><td colspan="9">' + html + '</td>';
    tr.parentNode.insertBefore(dr, tr.nextSibling);
}

// Member expand: list every recommendation in the cluster (recommender + reason
// + that member's seconds), built from data-membersfull. Reuses rmInsertDetail's
// toggle-on/off behavior.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var btn = e.target.closest('.rm-expand-members'); if (!btn) return;
    var tr = btn.closest('tr');
    var members = []; try { members = JSON.parse(tr.getAttribute('data-membersfull') || '[]'); } catch (x) {}
    var html = '<ul class="rm-seclist">';
    members.forEach(function (m) {
        var who = m.By ? rmEsc(m.By) : '(unknown)';
        var when = m.Date ? ' <span class="rm-age">' + rmEsc(m.Date) + '</span>' : '';
        html += '<li><strong>' + who + '</strong>' + when +
                (m.Reason ? '<div class="rm-reason-full">' + rmEsc(m.Reason) + '</div>' : '');
        if (m.Seconds && m.Seconds.length) {
            html += '<ul class="rm-seclist">';
            m.Seconds.forEach(function (s) {
                html += '<li>↳ ' + rmEsc(s.Name || '') + (s.Notes ? ' — ' + rmEsc(s.Notes) : ' <em class="rm-empty">(no note)</em>') + '</li>';
            });
            html += '</ul>';
        }
        html += '</li>';
    });
    html += '</ul>';
    rmInsertDetail(tr, html, 'rm-detail-members');
});

/* ---------- Server-side filter/sort + infinite-scroll lazy batches ---------- */
var RM = { rows: function () { return Array.from(document.querySelectorAll('#rm-tbody .rm-row')); } };

var rmState = {
	search: '', elig: 'open', court: 'all', park: 'all', passlocal: false,
	sort: 'date', dir: 'desc',
	offset: RmConfig.nextOffset || 0, total: RmConfig.total || 0,
	hasMore: !!RmConfig.hasMore, loading: false, seen: {}
};
function rmReadFilters() {
	rmState.search = (document.getElementById('rm-search').value || '').trim();
	rmState.elig   = document.getElementById('rm-filter-elig').value;
	rmState.court  = document.getElementById('rm-filter-court').value;
	var pk = document.getElementById('rm-filter-park'); rmState.park = pk ? pk.value : 'all';
	rmState.passlocal = document.getElementById('rm-filter-passlocal').checked;
}
function rmRowKey(tr) { return tr.getAttribute('data-rec-cluster') || tr.getAttribute('data-rec-id'); }
function rmIndexSeen() { rmState.seen = {}; RM.rows().forEach(function (tr) { rmState.seen[rmRowKey(tr)] = true; }); }
function rmFetch(reset) {
	if (rmState.loading) return;
	rmState.loading = true;
	if (reset) { rmState.offset = 0; }
	var q = new URLSearchParams({
		search: rmState.search, elig: rmState.elig, court: rmState.court,
		park: rmState.park, passlocal: rmState.passlocal ? '1' : '', sort: rmState.sort,
		dir: rmState.dir, offset: String(rmState.offset)
	});
	var tbody = document.getElementById('rm-tbody');
	document.getElementById('rm-loading').style.display = '';
	fetch(RmConfig.rowsUrl + (RmConfig.rowsUrl.indexOf('?') >= 0 ? '&' : '?') + q.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
		.then(function (r) { if (!r.ok) throw new Error('rows http ' + r.status); return r.json(); })
		.then(function (d) {
			// A 200 with an error field (or missing paging data) is not a valid page — surface it.
			if (!d || d.error || typeof d.total === 'undefined') { throw new Error(d && d.error ? d.error : 'bad page'); }
			if (reset) { tbody.innerHTML = ''; rmState.seen = {}; }
			var tmp = document.createElement('tbody'); tmp.innerHTML = d.html;
			Array.prototype.slice.call(tmp.children).forEach(function (tr) {
				if (!tr.classList || !tr.classList.contains('rm-row')) { tbody.appendChild(tr); return; }
				var k = rmRowKey(tr);
				if (k && rmState.seen[k]) return;
				if (k) rmState.seen[k] = true;
				tbody.appendChild(tr);
			});
			rmState.offset  = d.offset;
			rmState.total   = d.total;
			rmState.hasMore = d.hasMore;
			document.getElementById('rm-count').textContent = RM.rows().length;
			document.getElementById('rm-total').textContent = d.total;
			if (typeof rmUpdateSelCount === 'function') rmUpdateSelCount();
			if (typeof rmSyncReasonExpanders === 'function') rmSyncReasonExpanders();
		})
		.catch(function () { if (typeof rmToast === 'function') rmToast('Failed to load.', true); })
		.finally(function () {
			rmState.loading = false;
			document.getElementById('rm-loading').style.display = 'none';
		});
}
function rmAfterRowRemoved() {
	document.getElementById('rm-count').textContent = RM.rows().length;
	if (rmState.total > 0) { rmState.total -= 1; document.getElementById('rm-total').textContent = rmState.total; }
	if (typeof rmUpdateSelCount === 'function') rmUpdateSelCount();
}
rmIndexSeen();
// filter inputs (debounced search) -> reset fetch
var rmDeb;
['rm-search', 'rm-filter-elig', 'rm-filter-court', 'rm-filter-park'].forEach(function (idv) {
	var el = document.getElementById(idv); if (!el) return;
	el.addEventListener('input', function () { rmReadFilters(); clearTimeout(rmDeb); rmDeb = setTimeout(function () { rmFetch(true); }, 250); });
	el.addEventListener('change', function () { rmReadFilters(); clearTimeout(rmDeb); rmFetch(true); });
});
var rmPl = document.getElementById('rm-filter-passlocal'); if (rmPl) rmPl.addEventListener('change', function () { rmReadFilters(); rmFetch(true); });
// sort headers -> set sort + reset fetch
document.querySelectorAll('.rm-sortable').forEach(function (th) {
	th.addEventListener('click', function () {
		var key = th.getAttribute('data-sort');
		if (rmState.sort === key) rmState.dir = (rmState.dir === 'asc') ? 'desc' : 'asc';
		else { rmState.sort = key; rmState.dir = (key === 'date') ? 'desc' : 'asc'; }
		document.querySelectorAll('.rm-sortable').forEach(function (t) { t.classList.remove('rm-sort-asc', 'rm-sort-desc'); });
		th.classList.add(rmState.dir === 'asc' ? 'rm-sort-asc' : 'rm-sort-desc');
		rmFetch(true);
	});
});
// export current filtered set as CSV (server streams the FULL set, not just loaded batches)
var rmExportBtn = document.getElementById('rm-export');
if (rmExportBtn) rmExportBtn.addEventListener('click', function () {
	rmReadFilters();
	var q = new URLSearchParams({
		search: rmState.search, elig: rmState.elig, court: rmState.court,
		park: rmState.park, passlocal: rmState.passlocal ? '1' : '',
		sort: rmState.sort, dir: rmState.dir
	});
	// brief disabled state — a large scope can take a moment to assemble server-side
	var label = rmExportBtn.innerHTML;
	rmExportBtn.disabled = true;
	rmExportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting…';
	setTimeout(function () { rmExportBtn.disabled = false; rmExportBtn.innerHTML = label; }, 4000);
	window.location = RmConfig.exportUrl + (RmConfig.exportUrl.indexOf('?') >= 0 ? '&' : '?') + q.toString();
});
// infinite scroll
if ('IntersectionObserver' in window) {
	var rmObs = new IntersectionObserver(function (entries) {
		if (entries[0].isIntersecting && rmState.hasMore && !rmState.loading) rmFetch(false);
	}, { rootMargin: '400px' });
	var rmSent = document.getElementById('rm-sentinel'); if (rmSent) rmObs.observe(rmSent);
}

/* ---------- Task 7: selection + bulk bar ---------- */
var rmLastIdx = null;
// All rows currently loaded in the DOM. (Filtering is server-side now, so there is
// no client-side hidden state to skip — every loaded row is a visible row.)
function rmLoadedRows() { return RM.rows(); }
function rmSelected() { return RM.rows().filter(function (tr) { return tr.querySelector('.rm-rowsel').checked; }); }
function rmUpdateSelCount() {
    var n = rmSelected().length;
    document.getElementById('rm-selcount').textContent = n;
    var bar = document.getElementById('rm-bulkbar');
    bar.hidden = n === 0;
    document.getElementById('rm-bulklabel').textContent = n + ' selected';
}
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var cb = e.target.closest('.rm-rowsel'); if (!cb) return;
    var vis = rmLoadedRows();
    var idx = vis.indexOf(cb.closest('tr'));
    if (e.shiftKey && rmLastIdx !== null) {
        var lo = Math.min(idx, rmLastIdx), hi = Math.max(idx, rmLastIdx);
        for (var i = lo; i <= hi; i++) vis[i].querySelector('.rm-rowsel').checked = cb.checked;
    }
    rmLastIdx = idx;
    rmUpdateSelCount();
});
document.getElementById('rm-selall').addEventListener('change', function () {
    rmLoadedRows().forEach(function (tr) { tr.querySelector('.rm-rowsel').checked = this.checked; }, this);
    rmUpdateSelCount();
});
document.querySelector('.rm-bulk-clear').addEventListener('click', function () {
    RM.rows().forEach(function (tr) { tr.querySelector('.rm-rowsel').checked = false; });
    document.getElementById('rm-selall').checked = false;
    rmUpdateSelCount();
});

/* ---------- Task 8: snooze/dismiss (row + bulk), toast, config ---------- */
function rmToast(msg, isErr) {
    var t = document.createElement('div');
    t.className = 'rm-toast' + (isErr ? ' rm-toast-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('rm-toast-out'); setTimeout(function () { t.remove(); }, 400); }, 2600);
}
// Build the snooze/dismiss endpoint base for the current scope.
function rmRecAjaxBase(action) {
    if (RmConfig.context === 'park')
        return RmConfig.uir + 'ParkAjax/park/' + RmConfig.parkId + '/' + action;
    return RmConfig.uir + 'KingdomAjax/kingdom/' + RmConfig.kingdomId + '/' + action;
}
function rmPost(url, fd) {
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
}
// These rec AJAX endpoints all echo {status:0} on success, {status:N,error} on failure.
function rmJsonOk(j) { return !!j && j.status === 0; }
function rmAllOk(results) { return Array.isArray(results) && results.every(rmJsonOk); }
// Does the active eligibility filter hide a row given its snoozed state?
// ('snoozed' shows only snoozed; 'all' shows both; every other bucket excludes snoozed.)
function rmEligHides(elig, isSnoozed) {
    if (elig === 'all') return false;
    if (elig === 'snoozed') return !isSnoozed;
    return !!isSnoozed;
}
// Add or remove the Award-cell "passed to local" badge (idempotent).
function rmSetPasslocalBadge(tr, passed) {
    var awardCell = tr.querySelector('.rm-col-award');
    if (!awardCell) return;
    var existing = awardCell.querySelector('.rm-badge-passlocal');
    if (passed && !existing) {
        var b = document.createElement('span');
        b.className = 'rm-badge rm-badge-passlocal';
        b.setAttribute('data-tip', 'Passed to the local park to award.');
        b.innerHTML = '<i class="fas fa-arrow-down"></i> passed to local';
        awardCell.appendChild(b);
    } else if (!passed && existing) {
        existing.remove();
    }
}

// Remove a row (and any open detail row) then re-sync filters/counts.
function rmRemoveRow(tr) {
    var dr = tr.nextElementSibling;
    if (dr && dr.classList.contains('rm-detailrow')) dr.remove();
    tr.remove();
    rmAfterRowRemoved();
}

// Read the member rec ids for a group row.
function rmMemberIds(tr) {
    var ids = []; try { ids = JSON.parse(tr.getAttribute('data-members') || '[]'); } catch (x) {}
    return ids;
}

// Per-row Snooze + Dismiss (third tbody click handler; early-returns on non-match).
// Both loop every member rec id in the cluster.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var sn = e.target.closest('.rm-act-snooze');
    if (sn) {
        var tr = sn.closest('tr');
        var snoozed = tr.getAttribute('data-snoozed') === '1';
        var action = snoozed ? 'unsnoozerecommendation' : 'snoozerecommendation';
        var ids = rmMemberIds(tr);
        if (!ids.length) { rmToast('No recommendations found.', true); return; }
        Promise.all(ids.map(function (id) {
            var fd = new FormData(); fd.append('RecommendationsId', id);
            return rmPost(rmRecAjaxBase(action), fd);
        })).then(function (results) {
            if (!rmAllOk(results)) { rmToast('Failed.', true); return; }
            var nowSnoozed = !snoozed;
            tr.setAttribute('data-snoozed', nowSnoozed ? '1' : '0');
            var sIco = sn.querySelector('.rm-snooze-ico') || sn;
            sIco.textContent = nowSnoozed ? '🔔' : '💤';
            // Refetch only if the toggle moves the row out of the current bucket;
            // otherwise leave it in place (cheap — no 500-row re-render).
            if (rmEligHides(rmState.elig, nowSnoozed)) rmFetch(true);
            rmToast(nowSnoozed ? 'Snoozed.' : 'Unsnoozed.');
        }).catch(function () { rmToast('Failed.', true); });
        return;
    }
    var ds = e.target.closest('.rm-act-dismiss');
    if (ds) {
        var tr2 = ds.closest('tr');
        tnConfirm({ title: 'Dismiss recommendation?', body: 'This removes the recommendation(s) from the pending list.', confirmLabel: 'Dismiss', danger: true, onConfirm: function () {
            var ids = rmMemberIds(tr2);
            if (!ids.length) { rmToast('No recommendations found.', true); return; }
            Promise.all(ids.map(function (id) {
                var fd = new FormData(); fd.append('RecommendationsId', id);
                return rmPost(rmRecAjaxBase('dismissrecommendation'), fd);
            })).then(function (results) {
                if (!rmAllOk(results)) { rmToast('Failed.', true); return; }
                rmRemoveRow(tr2); rmToast('Dismissed.');
            }).catch(function () { rmToast('Failed.', true); });
        } });
    }
});

// Per-row Pass-to-local toggle (kingdom scope only; button only rendered there).
// Loops every member rec id in the cluster, mirroring the snooze handler.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var pl = e.target.closest('.rm-act-passlocal'); if (!pl) return;
    var tr = pl.closest('tr');
    var passed = tr.getAttribute('data-passlocal') === '1';
    var ids = rmMemberIds(tr);
    if (!ids.length) { rmToast('No recommendations found.', true); return; }
    Promise.all(ids.map(function (id) {
        var fd = new FormData(); fd.append('RecommendationsId', id); fd.append('Passed', passed ? '0' : '1');
        return rmPost(rmRecAjaxBase('passtolocalrecommendation'), fd);
    })).then(function (results) {
        if (!rmAllOk(results)) { rmToast('Update failed.', true); return; }
        var nowPassed = !passed;
        tr.setAttribute('data-passlocal', nowPassed ? '1' : '0');
        pl.classList.toggle('rm-act-active', nowPassed);
        rmSetPasslocalBadge(tr, nowPassed);
        // Only refetch if the "Passed to local" filter is active and this row just
        // dropped out of it; otherwise the in-place badge/state update is enough.
        if (rmState.passlocal && !nowPassed) rmFetch(true);
        rmToast(nowPassed ? 'Passed to local.' : 'Pass-to-local removed.');
    }).catch(function () { rmToast('Update failed.', true); });
});

// Bulk: run fn over rows with a bounded concurrency pool (up to 6 in flight),
// tally results, toast + refetch once at the end. `fn(tr)` resolves true/false.
function rmBulkSequential(rows, fn, doneMsg) {
    var ok = 0, fail = 0, i = 0, active = 0, POOL = 6;
    function done() { rmToast(doneMsg(ok, fail), fail > 0); rmFetch(true); }
    function pump() {
        if (i >= rows.length && active === 0) { done(); return; }
        while (active < POOL && i < rows.length) {
            active++;
            fn(rows[i++])
                .then(function (good) { good ? ok++ : fail++; })
                .catch(function () { fail++; })
                .then(function () { active--; pump(); });
        }
    }
    if (!rows.length) { done(); return; }
    pump();
}
document.querySelector('.rm-bulk-snooze').addEventListener('click', function () {
    var rows = rmSelected().filter(function (tr) { return tr.getAttribute('data-snoozed') !== '1'; });
    rmBulkSequential(rows, function (tr) {
        var ids = rmMemberIds(tr);
        return Promise.all(ids.map(function (id) {
            var fd = new FormData(); fd.append('RecommendationsId', id);
            return rmPost(rmRecAjaxBase('snoozerecommendation'), fd);
        })).then(function (results) {
            if (!rmAllOk(results)) return false;
            tr.setAttribute('data-snoozed', '1');
            var b = tr.querySelector('.rm-act-snooze');
            if (b) { b.textContent = '🔔'; b.setAttribute('data-tip', 'Unsnooze'); }
            tr.querySelector('.rm-rowsel').checked = false;
            return true;
        }).catch(function () { return false; });
    }, function (ok, fail) { return 'Snoozed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; });
    rmUpdateSelCount();
});
// Bulk Pass down (kingdom scope only; button absent in park scope).
(function () {
    var btn = document.querySelector('.rm-bulk-passlocal'); if (!btn) return;
    btn.addEventListener('click', function () {
        var rows = rmSelected().filter(function (tr) { return tr.getAttribute('data-passlocal') !== '1'; });
        rmBulkSequential(rows, function (tr) {
            var ids = rmMemberIds(tr);
            return Promise.all(ids.map(function (id) {
                var fd = new FormData(); fd.append('RecommendationsId', id); fd.append('Passed', '1');
                return rmPost(rmRecAjaxBase('passtolocalrecommendation'), fd);
            })).then(function (results) {
                if (!rmAllOk(results)) return false;
                tr.setAttribute('data-passlocal', '1');
                var pl = tr.querySelector('.rm-act-passlocal');
                if (pl) pl.classList.add('rm-act-active');
                rmSetPasslocalBadge(tr, true);
                tr.querySelector('.rm-rowsel').checked = false;
                return true;
            }).catch(function () { return false; });
        }, function (ok, fail) { return 'Passed ' + ok + (fail ? ', ' + fail + ' failed' : '') + ' to local.'; });
        rmUpdateSelCount();
    });
})();
document.querySelector('.rm-bulk-dismiss').addEventListener('click', function () {
    var rows = rmSelected();
    tnConfirm({ title: 'Dismiss ' + rows.length + ' recommendation(s)?', body: 'They will be removed from the pending list.', confirmLabel: 'Dismiss all', danger: true, onConfirm: function () {
        rmBulkSequential(rows, function (tr) {
            var ids = rmMemberIds(tr);
            return Promise.all(ids.map(function (id) {
                var fd = new FormData(); fd.append('RecommendationsId', id);
                return rmPost(rmRecAjaxBase('dismissrecommendation'), fd);
            })).then(function (results) {
                if (!rmAllOk(results)) return false;
                rmRemoveRow(tr); return true;
            }).catch(function () { return false; });
        }, function (ok, fail) { return 'Dismissed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; });
    } });
});

/* ---------- Task 9: Grant Award (modal — never insta-grants) ---------- */
// Today's date as YYYY-MM-DD (the format add_player_award expects).
function rmTodayYMD() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

// Core grant: write the award via the JSON grantaward endpoint, optionally reconcile
// the linked court award(s), then resolve the whole cluster + drop the row.
// opts = { date, givenById, note, courtStep }. Resolves on full success; rejects with
// an Error carrying `.granted` (did the award itself land?) so the caller can tell a
// real grant failure from a post-grant cleanup failure.
function rmDoGrant(rec, tr, opts) {
    opts = opts || {};
    var granted = false;
    var fd = new FormData();
    fd.append('KingdomAwardId', rec.KingdomAwardId);
    fd.append('GivenById', opts.givenById || RmConfig.userId);
    fd.append('Date', opts.date || rmTodayYMD());
    fd.append('ParkId', opts.parkId != null ? opts.parkId : (RmConfig.parkId || '0'));
    fd.append('KingdomId', opts.kingdomId != null ? opts.kingdomId : (RmConfig.kingdomId || '0'));
    fd.append('EventId', opts.eventId != null ? opts.eventId : '0');
    fd.append('Note', opts.note != null ? opts.note : (rec.Reason || ''));
    fd.append('Rank', opts.rank != null ? opts.rank : (rec.Rank || 0));
    // Thread the granted recommendation id so the server can reconcile the matching
    // court line (mark it given) and a later court finalize can't double-grant it.
    var recId = (opts.recommendationsId != null) ? opts.recommendationsId : rec.RepRecId;
    if (recId) { fd.append('RecommendationsId', recId); }
    return rmPost(RmConfig.uir + 'PlayerAjax/player/' + rec.MundaneId + '/grantaward', fd)
        .then(function (j) {
            if (!rmJsonOk(j)) { var e = new Error(j && j.error ? j.error : 'Could not grant the award.'); e.granted = false; throw e; }
            granted = true;
            return opts.courtStep ? opts.courtStep() : null;
        }).then(function (courtRes) {
            if (courtRes && Array.isArray(courtRes) && !rmAllOk(courtRes)) { var e = new Error('court cleanup failed'); e.granted = true; throw e; }
            var fd2 = new FormData();
            fd2.append('MundaneId', rec.MundaneId);
            fd2.append('KingdomAwardId', rec.KingdomAwardId);
            fd2.append('Rank', rec.Rank || 0);
            return rmPost(rmRecAjaxBase('resolverecommendationcluster'), fd2);
        }).then(function (j) {
            if (!rmJsonOk(j)) { var e = new Error('resolve cluster failed'); e.granted = true; throw e; }
            rmRemoveRow(tr);
            rmToast('Granted.');
        }).catch(function (err) {
            err.granted = (typeof err.granted === 'boolean') ? err.granted : granted;
            throw err;
        });
}

/* ----- Grant Award modal ----- */
var rmGrantCtx = null; // { rec, tr, courts }
function rmGid(id) { return document.getElementById(id); }
function rmGrantErr(msg) {
    var box = rmGid('rm-grant-error');
    if (!msg) { box.hidden = true; box.textContent = ''; return; }
    box.textContent = msg; box.hidden = false;
}
function rmOpenGrantModal(rec, tr, courts) {
    rmGrantCtx = { rec: rec, tr: tr, courts: (courts && courts.length) ? courts : [] };
    var rankTxt = rec.Rank ? (' — Rank ' + rec.Rank) : '';
    rmGid('rm-grant-sub').textContent = 'Grant ' + (rec.AwardName || 'this award') + rankTxt + ' to “' + (rec.Persona || '') + '”.';
    rmGrantErr('');
    rmGid('rm-grant-date').value = rmTodayYMD();
    rmGid('rm-grant-givenby').value = RmConfig.userName || '';
    rmGid('rm-grant-givenby-id').value = RmConfig.userId || '';
    rmGid('rm-grant-note').value = rec.Reason || '';
    rmBuildRankPills(rec);
    var chipsEl = rmGid('rm-grant-officer-chips');
    if (chipsEl) chipsEl.querySelectorAll('.rm-officer-chip').forEach(function (c) { c.classList.remove('rm-selected'); });
    rmGid('rm-grant-givenat').value = RmConfig.locationName || '';
    rmGid('rm-grant-park-id').value = RmConfig.parkId || '0';
    rmGid('rm-grant-kingdom-id').value = RmConfig.kingdomId || '0';
    rmGid('rm-grant-event-id').value = '0';
    var gaRes = rmGid('rm-grant-givenat-results'); if (gaRes) { gaRes.classList.remove('rm-ac-open'); gaRes.innerHTML = ''; }
    var courtWrap = rmGid('rm-grant-court-wrap');
    if (rmGrantCtx.courts.length) {
        var names = rmGrantCtx.courts.map(function (c) { return c.Name + (c.CourtDate ? ' (' + c.CourtDate + ')' : ''); }).join(', ');
        rmGid('rm-grant-court-label').textContent = 'Already on ' + (rmGrantCtx.courts.length === 1 ? 'court: ' : rmGrantCtx.courts.length + ' courts: ') + names;
        var rm = document.querySelector('input[name="rm-grant-court"][value="remove"]'); if (rm) rm.checked = true;
        courtWrap.hidden = false;
    } else {
        courtWrap.hidden = true;
    }
    var results = rmGid('rm-grant-givenby-results'); if (results) { results.classList.remove('rm-ac-open'); results.innerHTML = ''; }
    rmGid('rm-grant-submit').disabled = false;
    rmGid('rm-grant-overlay').hidden = false;
    setTimeout(function () { rmGid('rm-grant-date').focus(); }, 30);
}
function rmCloseGrantModal() {
    rmGid('rm-grant-overlay').hidden = true;
    var results = rmGid('rm-grant-givenby-results'); if (results) results.classList.remove('rm-ac-open');
    rmGrantCtx = null;
}
// The row's lightning-bolt always opens the modal (pre-filled). Never insta-grant.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var g = e.target.closest('.rm-act-grant'); if (!g) return;
    var tr = g.closest('tr');
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
    var courts = []; try { courts = JSON.parse(tr.getAttribute('data-courts') || '[]'); } catch (x) {}
    rmOpenGrantModal(rec, tr, courts);
});
rmGid('rm-grant-cancel').addEventListener('click', rmCloseGrantModal);
rmGid('rm-grant-overlay').addEventListener('click', function (e) { if (e.target === this) rmCloseGrantModal(); });
rmGid('rm-grant-submit').addEventListener('click', function () {
    if (!rmGrantCtx) return;
    var ctx = rmGrantCtx;
    var date = rmGid('rm-grant-date').value;
    var givenById = rmGid('rm-grant-givenby-id').value;
    var note = rmGid('rm-grant-note').value;
    if (!date) { rmGrantErr('Please choose a date.'); return; }
    if (!givenById) { rmGrantErr('Please choose who granted this award (pick from the search).'); return; }
    rmGrantErr('');
    var submitBtn = rmGid('rm-grant-submit');
    submitBtn.disabled = true;
    var courtStep = null;
    if (ctx.courts.length) {
        var choice = (document.querySelector('input[name="rm-grant-court"]:checked') || {}).value;
        courtStep = function () {
            return Promise.all(ctx.courts.map(function (c) {
                var fd = new FormData();
                fd.append('CourtAwardId', c.CourtAwardId);
                if (choice === 'leave') { fd.append('Status', 'given'); return rmPost(RmConfig.uir + 'CourtAjax/set_award_status', fd); }
                return rmPost(RmConfig.uir + 'CourtAjax/remove_award', fd);
            }));
        };
    }
    var rankVal = rmGid('rm-grant-rank-val').value;
    rmDoGrant(ctx.rec, ctx.tr, {
        date: date, givenById: givenById, note: note, courtStep: courtStep,
        rank: (rankVal !== '' ? rankVal : (ctx.rec.Rank || 0)),
        parkId: rmGid('rm-grant-park-id').value || '0',
        kingdomId: rmGid('rm-grant-kingdom-id').value || '0',
        eventId: rmGid('rm-grant-event-id').value || '0'
    })
        .then(function () { rmCloseGrantModal(); })
        .catch(function (err) {
            if (err && err.granted) {
                // Award landed but cleanup failed — closing + refresh guidance avoids a retry double-grant.
                rmCloseGrantModal();
                rmToast('Granted, but the court/rec cleanup failed — refresh before retrying.', true);
            } else {
                submitBtn.disabled = false;
                rmGrantErr((err && err.message) ? err.message : 'Grant failed.');
            }
        });
});
/* Rank pills — replicate the award modal's rank selector (green = already held). */
function rmRankPaint(wrap, held, selected) {
    held = parseInt(held, 10) || 0; selected = parseInt(selected, 10) || 0;
    wrap.querySelectorAll('.rm-rank-pill').forEach(function (pill) {
        var r = parseInt(pill.dataset.rank, 10);
        pill.classList.remove('rm-rank-held', 'rm-rank-forward', 'rm-rank-selected');
        if (r <= held) pill.classList.add('rm-rank-held');
        else if (r <= selected) pill.classList.add('rm-rank-forward');
        if (r === selected) pill.classList.add('rm-rank-selected');
    });
}
function rmBuildRankPills(rec) {
    var wrap = rmGid('rm-grant-rank-pills'), row = rmGid('rm-grant-rank-wrap'), input = rmGid('rm-grant-rank-val');
    wrap.innerHTML = ''; input.value = '';
    var recRank = parseInt(rec.Rank, 10) || 0;
    if (recRank <= 0) { row.hidden = true; return; } // non-ladder award → no rank selector
    row.hidden = false;
    var maxRank = /zodiac/i.test(rec.AwardName || '') ? 12 : 10;
    var held = parseInt(rec.HeldRank, 10) || 0;
    var selected = Math.min(Math.max(recRank, 1), maxRank);
    wrap.dataset.held = held;
    for (var r = 1; r <= maxRank; r++) {
        var pill = document.createElement('div');
        pill.className = 'rm-rank-pill'; pill.dataset.rank = r;
        pill.innerHTML = '<span class="rm-rank-num">' + r + '</span>';
        wrap.appendChild(pill);
    }
    rmRankPaint(wrap, held, selected);
    input.value = selected;
}
rmGid('rm-grant-rank-pills').addEventListener('click', function (e) {
    var pill = e.target.closest ? e.target.closest('.rm-rank-pill') : null;
    if (!pill) return;
    rmGid('rm-grant-rank-val').value = pill.dataset.rank;
    rmRankPaint(this, this.dataset.held, pill.dataset.rank);
});
// Officer quick-pick chips → fill Given By.
(function () {
    var chipsEl = rmGid('rm-grant-officer-chips');
    if (!chipsEl) return;
    chipsEl.addEventListener('click', function (e) {
        var chip = e.target.closest ? e.target.closest('.rm-officer-chip') : null;
        if (!chip) return;
        chipsEl.querySelectorAll('.rm-officer-chip').forEach(function (c) { c.classList.remove('rm-selected'); });
        chip.classList.add('rm-selected');
        rmGid('rm-grant-givenby').value = chip.getAttribute('data-name');
        rmGid('rm-grant-givenby-id').value = chip.getAttribute('data-id');
        var res = rmGid('rm-grant-givenby-results'); if (res) res.classList.remove('rm-ac-open');
    });
})();
// Given At: location search (park / kingdom / event) → sets ParkId/KingdomId/EventId.
(function () {
    var input = rmGid('rm-grant-givenat'), results = rmGid('rm-grant-givenat-results');
    if (!input || !results || !RmConfig.httpService) return;
    var timer = null;
    input.addEventListener('input', function () {
        rmGid('rm-grant-park-id').value = '0'; rmGid('rm-grant-kingdom-id').value = '0'; rmGid('rm-grant-event-id').value = '0';
        var term = input.value.trim(); clearTimeout(timer);
        if (term.length < 2) { results.classList.remove('rm-ac-open'); results.innerHTML = ''; return; }
        timer = setTimeout(function () {
            var today = new Date().toISOString().slice(0, 10);
            var url = RmConfig.httpService + 'Search/SearchService.php?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=8';
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.length) { results.innerHTML = '<div class="rm-ac-none">No locations found</div>'; results.classList.add('rm-ac-open'); return; }
                results.innerHTML = data.map(function (loc) {
                    return '<div class="rm-ac-item" data-park="' + (parseInt(loc.ParkId) || 0) + '" data-kingdom="' + (parseInt(loc.KingdomId) || 0) + '" data-event="' + (parseInt(loc.EventId) || 0) + '" data-name="' + encodeURIComponent(loc.ShortName || loc.LocationName || '') + '">' + rmEsc(loc.LocationName || '') + '</div>';
                }).join('');
                results.classList.add('rm-ac-open');
            }).catch(function () { results.classList.remove('rm-ac-open'); });
        }, 220);
    });
    results.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.rm-ac-item') : null;
        if (!item) return;
        input.value = decodeURIComponent(item.getAttribute('data-name'));
        rmGid('rm-grant-park-id').value = item.getAttribute('data-park') || '0';
        rmGid('rm-grant-kingdom-id').value = item.getAttribute('data-kingdom') || '0';
        rmGid('rm-grant-event-id').value = item.getAttribute('data-event') || '0';
        results.classList.remove('rm-ac-open');
    });
    document.addEventListener('click', function (e) { if (!input.contains(e.target) && !results.contains(e.target)) results.classList.remove('rm-ac-open'); });
})();
// Given-By player search: global giver scope (intentional), custom dropdown (not jQuery UI).
(function () {
    var input = rmGid('rm-grant-givenby');
    var hidden = rmGid('rm-grant-givenby-id');
    var results = rmGid('rm-grant-givenby-results');
    if (!input || !results) return;
    var timer = null;
    input.addEventListener('input', function () {
        hidden.value = ''; // typing invalidates the previous pick until one is reselected
        var _chips = rmGid('rm-grant-officer-chips'); if (_chips) _chips.querySelectorAll('.rm-officer-chip').forEach(function (c) { c.classList.remove('rm-selected'); });
        var term = input.value.trim();
        clearTimeout(timer);
        if (term.length < 2) { results.classList.remove('rm-ac-open'); results.innerHTML = ''; return; }
        timer = setTimeout(function () {
            var url = RmConfig.uir + 'KingdomAjax/playersearch/' + (RmConfig.kingdomId || 0) + '&scope=all&include_inactive=1&include_suspended=1&q=' + encodeURIComponent(term);
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.length) { results.innerHTML = '<div class="rm-ac-none">No players found</div>'; results.classList.add('rm-ac-open'); return; }
                results.innerHTML = data.map(function (pl) {
                    return '<div class="rm-ac-item" tabindex="-1" data-id="' + pl.MundaneId + '" data-name="' + encodeURIComponent(pl.Persona || '') + '">' +
                        rmEsc(pl.Persona || '') +
                        ' <span style="color:var(--rm-muted);font-size:11px">(' + rmEsc(pl.KAbbr || '') + ':' + rmEsc(pl.PAbbr || '') + ')</span>' +
                        (pl.Suspended ? ' <span style="color:var(--rm-danger);font-size:10px;font-weight:600">(Banned)</span>' : '') +
                        '</div>';
                }).join('');
                results.classList.add('rm-ac-open');
            }).catch(function () { results.classList.remove('rm-ac-open'); });
        }, 220);
    });
    results.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.rm-ac-item') : null;
        if (!item) return;
        input.value = decodeURIComponent(item.getAttribute('data-name'));
        hidden.value = item.getAttribute('data-id');
        results.classList.remove('rm-ac-open');
    });
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) results.classList.remove('rm-ac-open');
    });
    if (typeof acKeyNav === 'function') acKeyNav(input, results, 'rm-ac-open', '.rm-ac-item');
})();

/* ---------- Task 10: Add to Court modal (single + bulk) ---------- */
var rmCourtTargets = []; // array of rec payloads (each with ._tr) to add

function rmOpenCourtModal(targets) {
    rmCourtTargets = targets;
    document.getElementById('rm-court-sub').textContent = targets.length === 1
        ? 'Adding 1 recommendation.' : 'Adding ' + targets.length + ' recommendations.';
    document.getElementById('rm-court-overlay').hidden = false;
}
function rmCloseCourtModal() { document.getElementById('rm-court-overlay').hidden = true; }
document.getElementById('rm-court-cancel').addEventListener('click', rmCloseCourtModal);
document.getElementById('rm-court-overlay').addEventListener('click', function (e) {
    if (e.target === this) rmCloseCourtModal(); // click backdrop closes
});
document.querySelectorAll('input[name="rm-court-mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var isNew = document.querySelector('input[name="rm-court-mode"]:checked').value === 'new';
        document.getElementById('rm-court-new').hidden = !isNew;
        document.getElementById('rm-court-existing').hidden = isNew;
    });
});

// flatpickr is not loaded on this page; if it ever is, give the date a human-readable display.
if (typeof flatpickr !== 'undefined') {
    flatpickr('#rm-court-date', { altInput: true, altFormat: 'F j, Y', dateFormat: 'Y-m-d' });
}

// Per-row opener. One court award per group, keyed on the group's representative rec.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var c = e.target.closest('.rm-act-court'); if (!c) return;
    var tr = c.closest('tr');
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
    rec.RecommendationsId = rec.RepRecId;
    rec._tr = tr;
    rmOpenCourtModal([rec]);
});
// Bulk opener.
document.querySelector('.rm-bulk-court').addEventListener('click', function () {
    var targets = rmSelected().map(function (tr) {
        var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
        rec.RecommendationsId = rec.RepRecId;
        rec._tr = tr; return rec;
    });
    if (targets.length) rmOpenCourtModal(targets);
});

// Refresh the Court cell badge link after a rec is added to a court.
function rmUpdateCourtBadge(tr, courts) {
    var td = tr.querySelector('.rm-col-court');
    if (!courts.length) { td.innerHTML = '<span class="rm-empty">&mdash;</span>'; return; }
    var more = courts.length > 1 ? ' <span class="rm-courtmore">+' + (courts.length - 1) + '</span>' : '';
    td.innerHTML = '<a class="rm-courtbadge" href="' + RmConfig.uir + 'Court/detail/' + courts[0].CourtId + '">' + rmEsc(courts[0].Name) + more + '</a>';
}

// Submit: resolve a court id (create new, or use selected existing), then add_award per target.
document.getElementById('rm-court-submit').addEventListener('click', function () {
    var mode = document.querySelector('input[name="rm-court-mode"]:checked').value;
    var btn = this; btn.disabled = true;
    function withCourtId(cb) {
        if (mode === 'new') {
            var name = (document.getElementById('rm-court-name').value || '').trim();
            if (!name) { rmToast('Enter a court name.', true); btn.disabled = false; return; }
            var fd = new FormData();
            fd.append('KingdomId', RmConfig.kingdomId);
            fd.append('ParkId', RmConfig.parkId || '0');
            fd.append('Name', name);
            fd.append('CourtDate', (document.getElementById('rm-court-date').value || '').trim());
            fd.append('EventCalendarDetailId', '0');
            rmPost(RmConfig.uir + 'CourtAjax/create_court', fd).then(function (j) {
                if (j.status === 0 && j.court_id) cb(j.court_id, j.name);
                else { rmToast(j.error || 'Could not create court.', true); btn.disabled = false; }
            }).catch(function () { rmToast('Could not create court.', true); btn.disabled = false; });
        } else {
            var sel = document.getElementById('rm-court-select');
            if (!sel || !sel.value) { rmToast('Pick a court.', true); btn.disabled = false; return; }
            cb(parseInt(sel.value, 10), sel.selectedOptions[0].text);
        }
    }
    withCourtId(function (courtId, courtName) {
        var ok = 0, skip = 0, fail = 0, i = 0;
        (function next() {
            if (i >= rmCourtTargets.length) {
                rmToast('Added ' + ok + (skip ? ', ' + skip + ' already on court' : '') + (fail ? ', ' + fail + ' failed' : '') + '.', fail > 0);
                btn.disabled = false; rmCloseCourtModal(); rmFetch(true); return;
            }
            var rec = rmCourtTargets[i++];
            // Skip if this rec is already on the chosen court.
            var existing = []; try { existing = JSON.parse(rec._tr.getAttribute('data-courts') || '[]'); } catch (x) {}
            if (existing.some(function (cc) { return cc.CourtId === courtId; })) { skip++; next(); return; }
            var fd = new FormData();
            fd.append('CourtId', courtId);
            fd.append('MundaneId', rec.MundaneId);
            fd.append('KingdomAwardId', rec.KingdomAwardId);
            fd.append('Rank', rec.Rank || 0);
            fd.append('RecommendationsId', rec.RecommendationsId);
            rmPost(RmConfig.uir + 'CourtAjax/add_award', fd).then(function (j) {
                if (j.status === 0) {
                    ok++;
                    existing.push({ CourtId: courtId, CourtAwardId: (j.award && j.award.CourtAwardId) || 0, Name: courtName, CourtDate: '', Status: 'draft' });
                    rec._tr.setAttribute('data-courts', JSON.stringify(existing));
                    rmUpdateCourtBadge(rec._tr, existing);
                    var box = rec._tr.querySelector('.rm-rowsel'); if (box) box.checked = false;
                } else fail++;
                next();
            }).catch(function () { fail++; next(); });
        })();
    });
});

// Show the reason-cell expander when the reason is truncated OR the cluster has
// more than one member (so "show all recommendations" is always reachable).
function rmSyncReasonExpanders() {
    document.querySelectorAll('#rm-tbody .rm-reason-trunc').forEach(function (el) {
        var btn = el.parentNode.querySelector('.rm-expand-members');
        if (!btn) return;
        var tr = el.closest('tr');
        var members = []; try { members = JSON.parse(tr.getAttribute('data-membersfull') || '[]'); } catch (x) {}
        var truncated = (el.scrollWidth - el.clientWidth > 1);
        btn.style.display = (truncated || members.length > 1) ? '' : 'none';
    });
}

// Initial: the server already rendered the first batch sorted by date desc — just
// reflect that in the sort-header indicator and seed the filter state from the inputs.
(function () {
    var th = document.querySelector('.rm-sortable[data-sort="date"]');
    if (th) th.classList.add('rm-sort-desc');
})();
// Pre-apply the "Passed to local" filter when arrived via ?passlocal=1
// (e.g. from the park profile "Delegated by the Kingdom" section's Manage link).
(function () {
    var params = new URLSearchParams(window.location.search);
    var pl = document.getElementById('rm-filter-passlocal');
    rmReadFilters();
    if (params.get('passlocal') === '1' && pl) {
        pl.checked = true;
        rmReadFilters();
        rmFetch(true); // re-query server-side with the pass-to-local filter applied
    }
})();
rmSyncReasonExpanders();
var rmReasonResizeT;
window.addEventListener('resize', function () {
    clearTimeout(rmReasonResizeT);
    rmReasonResizeT = setTimeout(rmSyncReasonExpanders, 150);
});
</script>
