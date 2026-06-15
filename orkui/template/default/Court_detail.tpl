<?php
$court       = $Court        ?? [];
$courtAwards = $CourtAwards  ?? [];
$pendingRecs = $PendingRecs  ?? [];
$awardOpts   = $AwardOptions ?? [];
$statusFlow  = $StatusFlow   ?? [];
$canManage   = $CanManage    ?? false;
$error       = $Error        ?? '';
$heraldryUrl = $HeraldryUrl  ?? '';
$hasHeraldry = $HasHeraldry  ?? false;

$statusLabel = ['draft' => 'Draft', 'published' => 'Published', 'complete' => 'Complete'];
$statusColor = ['draft' => '#718096', 'published' => '#2b6cb0', 'complete' => '#276749'];
$statusBg    = ['draft' => '#edf2f7', 'published' => '#ebf8ff', 'complete' => '#f0fff4'];

$awardStatusLabel = ['planned' => 'Planned', 'announced' => 'Announced', 'given' => 'Given', 'cancelled' => 'Cancelled'];
$awardStatusColor = ['planned' => '#4a5568', 'announced' => '#2b6cb0', 'given' => '#276749', 'cancelled' => '#c53030'];
$awardStatusBg    = ['planned' => '#edf2f7', 'announced' => '#ebf8ff', 'given' => '#f0fff4', 'cancelled' => '#fff5f5'];

$courtSt   = $court['Status'] ?? 'draft';
$nextSt    = $statusFlow[$courtSt] ?? null;
$nextLabel = ['draft' => 'Publish', 'published' => 'Mark Complete'];

// Context back-link
// Court Planner now lives as a subsection inside the Admin Tasks tab (?tab=admin).
$backUrl = ($court['ParkId'] ?? 0) > 0
    ? UIR . 'Park/profile/' . $court['ParkId'] . '?tab=admin'
    : UIR . 'Kingdom/profile/' . $court['KingdomId'] . '?tab=admin';
$backLabel = ($court['ParkId'] ?? 0) > 0
    ? ($court['ParkName'] ?? 'Park') . ' Courts'
    : ($court['KingdomName'] ?? 'Kingdom') . ' Courts';
?>
<style>
.cp-page { padding: 0 16px 24px; font-family: inherit; }
.cp-back { color: rgba(255,255,255,.8); font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
.cp-back:hover { color: #fff; }

/* Hero */
.cp-hero { position: relative; background: #1a2744; min-height: 160px; display: flex; align-items: center; margin-top: 3px; margin-bottom: 20px; overflow: hidden; border-radius: 10px; }
.cp-hero-bg { position: absolute; top: -10px; left: -10px; right: -10px; bottom: -10px; background-size: cover; background-position: center; opacity: 0.14; filter: blur(6px); }
.cp-hero-content { position: relative; z-index: 1; width: 100%; padding: 24px 30px; display: flex; align-items: center; gap: 24px; box-sizing: border-box; }
.cp-heraldry-wrap { position: relative; flex-shrink: 0; }
.cp-heraldry-frame { width: 110px; height: 110px; border-radius: 8px; border: 3px solid rgba(255,255,255,0.8); background: rgba(0,0,0,0.15); display: flex; align-items: center; justify-content: center; overflow: hidden; }
.cp-heraldry-frame img { width: 100%; height: 100%; object-fit: contain; margin: 0; padding: 0; border: none; border-radius: 0; }
.cp-hero-heraldry-placeholder { width: 110px; height: 110px; border-radius: 8px; border: 3px solid rgba(255,255,255,0.4); background: rgba(0,0,0,0.15); flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.4); font-size: 36px; }
.cp-hero-info { flex: 1; min-width: 0; }
.cp-hero-supertitle { font-size: 12px; color: rgba(255,255,255,.7); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 4px; }
.cp-hero-supertitle a { color: rgba(255,255,255,.7); text-decoration: none; }
.cp-hero-supertitle a:hover { color: #fff; }
.cp-hero-name { font-size: 26px; font-weight: 700; color: #fff; margin: 0 0 6px; text-shadow: 0 1px 4px rgba(0,0,0,.4); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-hero-meta { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; color: rgba(255,255,255,.75); }
.cp-hero-meta span { display: flex; align-items: center; gap: 5px; }
.cp-hero-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-direction: column; align-items: flex-end; }
.cp-hero-back-row { padding: 10px 24px 0; }
.cp-badge { display: inline-block; padding: 4px 11px; border-radius: 12px; font-size: 12px; font-weight: 700; }

/* Published mode: Grant / Skip */
.cp-grant-actions { display: flex; gap: 6px; justify-content: flex-end; }
.cp-btn-grant { background: #276749; color: #fff; border: none; padding: 5px 12px; border-radius: 5px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: background .1s; }
.cp-btn-grant:hover { background: #22543d; }
.cp-btn-skip  { background: #edf2f7; color: #718096; border: 1px solid #cbd5e0; padding: 5px 10px; border-radius: 5px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: background .1s; }
.cp-btn-skip:hover  { background: #e2e8f0; color: #4a5568; }
.cp-award-row.cp-granted { opacity: .6; }
.cp-award-row.cp-skipped  { opacity: .45; }
.cp-award-row.cp-granted .cp-award-row-main,
.cp-award-row.cp-skipped  .cp-award-row-main { padding-top: 5px; padding-bottom: 5px; }
.cp-award-row.cp-granted .cp-reorder-btns,
.cp-award-row.cp-skipped  .cp-reorder-btns { visibility: hidden; }

/* Award status badge (small) */
.cp-aw-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }

/* Section */
.cp-section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.cp-section-header h2 { font-size: 16px; font-weight: 700; color: #2d3748; margin: 0; background: none; border: none; padding: 0; text-shadow: none; border-radius: 0; }
.cp-btn-primary { background: #2c5282; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.cp-btn-primary:hover { background: #2a4a7f; }
.cp-btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 5px; border: none; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
.cp-btn-outline { background: #fff; border: 1px solid #cbd5e0; color: #4a5568; padding: 7px 14px; border-radius: 5px; font-size: 13px; cursor: pointer; }
.cp-btn-outline:hover { background: #f7fafc; }
.cp-btn-danger-sm { background: none; border: none; color: #e53e3e; cursor: pointer; font-size: 14px; padding: 2px 4px; }

/* Award rows */
.cp-award-list { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }
.cp-award-row { background: #fff; border-bottom: 1px solid #edf2f7; }
.cp-award-row:last-child { border-bottom: none; }
.cp-award-row-main { display: flex; align-items: center; gap: 10px; padding: 10px 16px; cursor: pointer; }
.cp-award-row-main:hover { background: #f7fafc; }
.cp-award-drag { color: #cbd5e0; cursor: grab; font-size: 14px; }
.cp-award-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.cp-award-line1 { display: flex; align-items: center; gap: 6px; font-weight: 700; color: #2d3748; font-size: 14px; min-width: 0; }
.cp-award-park { font-weight: 400; color: #718096; font-size: 11px; letter-spacing: .2px; flex-shrink: 0; }
.cp-award-line2 { display: flex; align-items: center; gap: 5px; color: #4a5568; font-size: 13px; min-width: 0; flex-wrap: wrap; }
.cp-award-name-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex-shrink: 1; }
.cp-note-btn { background: none; border: none; cursor: pointer; color: #a0aec0; font-size: 12px; padding: 0 2px; flex-shrink: 0; line-height: 1; transition: color .15s; }
.cp-note-btn:hover { color: #4a5568; }
#cp-note-popup { position: fixed; background: #2d3748; color: #e2e8f0; font-size: 12px; line-height: 1.55; border-radius: 6px; padding: 10px 12px; max-width: 280px; z-index: 1200; box-shadow: 0 4px 16px rgba(0,0,0,.3); display: none; }
#cp-note-popup-text { white-space: pre-wrap; word-break: break-word; display: block; }
#cp-note-popup-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
#cp-note-popup-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #a0aec0; }
#cp-note-popup-close { background: none; border: none; color: #718096; cursor: pointer; font-size: 14px; line-height: 1; padding: 0; margin-left: 12px; flex-shrink: 0; }
#cp-note-popup-close:hover { color: #fff; }
.cp-award-rank { color: #a0aec0; font-size: 12px; flex-shrink: 0; }
.cp-award-flags { display: inline-flex; gap: 5px; align-items: center; flex-shrink: 0; }
.cp-award-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.cp-flag-local { background: #fff3cd; color: #856404; border: 1px solid #ffc107; width: 20px; height: 20px; border-radius: 50%; font-size: 10px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; cursor: default; }
.cp-flag-rec   { background: #e8f4fd; color: #1a6e9a; border: 1px solid #bee3f8; width: 20px; height: 20px; border-radius: 50%; font-size: 10px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; cursor: default; }
.cp-type-ladder { background: #faf5ff; color: #6b46c1; border: 1px solid #d6bcfa; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.cp-type-title  { background: #fffff0; color: #975a16; border: 1px solid #f6e05e; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.cp-type-award  { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.cp-award-row-expand { display: none; padding: 12px 16px 16px 52px; border-top: 1px solid #edf2f7; background: #f7fafc; }
.cp-award-row-expand.open { display: block; }
.cp-expand-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.cp-expand-label { font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
.cp-expand-val { font-size: 13px; color: #2d3748; }
.cp-notes-area { width: 100%; border: 1px solid #cbd5e0; border-radius: 5px; padding: 7px 10px; font-size: 13px; resize: vertical; min-height: 60px; box-sizing: border-box; }
.cp-artisan-row { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 4px; }
.cp-expand-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; align-items: center; }

/* Reorder arrows */
.cp-reorder-btns { display: flex; flex-direction: column; gap: 1px; }
.cp-reorder-btn { background: none; border: 1px solid #e2e8f0; color: #a0aec0; width: 18px; height: 14px; font-size: 9px; cursor: pointer; border-radius: 2px; display: flex; align-items: center; justify-content: center; padding: 0; }
.cp-reorder-btn:hover { background: #edf2f7; color: #4a5568; }

/* Empty */
.cp-award-empty { text-align: center; padding: 36px 24px; color: #a0aec0; font-size: 14px; }

/* Modals */
.cp-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; }
.cp-modal { background: #fff; border-radius: 10px; width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
.cp-modal-sm { max-width: 420px; }
.cp-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
.cp-modal-header h3 { margin: 0; font-size: 16px; font-weight: 700; color: #2d3748; background: none; border: none; padding: 0; text-shadow: none; border-radius: 0; }
.cp-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #718096; }
.cp-modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
.cp-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #e2e8f0; flex-shrink: 0; }
.cp-field { margin-bottom: 14px; }
.cp-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px; }
.cp-field input, .cp-field select, .cp-field textarea { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 14px; box-sizing: border-box; }
.cp-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cp-error { color: #c53030; font-size: 13px; margin-top: 8px; display: none; }

/* Rec list */
/* Rec modal redesign */
.cp-rm-search-wrap { position: relative; margin-bottom: 10px; }
.cp-rm-search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #a0aec0; font-size: 13px; pointer-events: none; }
.cp-rm-search { width: 100%; padding: 8px 10px 8px 32px; border: 1px solid #cbd5e0; border-radius: 6px; font-size: 13px; box-sizing: border-box; outline: none; }
.cp-rm-search:focus { border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,.15); }
.cp-rm-meta { font-size: 12px; color: #718096; margin-bottom: 10px; }
.cp-rm-meta strong { color: #2b6cb0; }
.cp-rm-list { max-height: 460px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
.cp-rm-row { display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-bottom: 1px solid #edf2f7; cursor: pointer; transition: background .1s; position: relative; }
.cp-rm-row:last-child { border-bottom: none; }
.cp-rm-row:hover:not(.already) { background: #f7fafc; }
.cp-rm-row.selected { background: #ebf8ff; }
.cp-rm-row.selected::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #2c5282; border-radius: 8px 0 0 8px; }
.cp-rm-row.already { cursor: default; background: #fafafa; }
.cp-rm-avatar { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }
.cp-rm-main { flex: 1; min-width: 0; }
/* Header line: persona · award · rank · date — all on one row, bullet-separated */
.cp-rm-head { display: flex; align-items: baseline; gap: 6px; flex-wrap: nowrap; overflow: hidden; line-height: 1.25; }
.cp-rm-persona { font-weight: 700; font-size: 13px; color: #1a202c; flex-shrink: 0; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-rm-park    { font-size: 11px; font-weight: 400; color: #a0aec0; letter-spacing: .2px; flex-shrink: 0; }
.cp-rm-award   { font-size: 12px; color: #4a5568; flex-shrink: 1; min-width: 60px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-rm-rank    { display: inline-block; background: #edf2f7; color: #4a5568; border-radius: 4px; font-size: 10px; font-weight: 700; padding: 1px 6px; flex-shrink: 0; }
.cp-rm-date    { font-size: 11px; color: #a0aec0; white-space: nowrap; flex-shrink: 0; }
.cp-rm-sep     { color: #cbd5e0; font-size: 11px; flex-shrink: 0; user-select: none; }
.cp-rm-reason  { font-size: 11px; color: #718096; line-height: 1.35; margin-top: 2px; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; font-style: italic; }
.cp-rm-right { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; flex-shrink: 0; }
.cp-rm-in-plan { background: #fefcbf; color: #744210; border: 1px solid #f6e05e; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.cp-rm-check { width: 20px; height: 20px; border-radius: 50%; background: #2c5282; color: #fff; display: none; align-items: center; justify-content: center; font-size: 11px; }
.cp-rm-row.selected .cp-rm-check { display: flex; }
.cp-rm-empty { text-align: center; padding: 28px 16px; color: #a0aec0; font-size: 13px; }
.cp-rm-trash { position: absolute; top: 6px; right: 8px; background: none; border: none; color: #fed7d7; cursor: pointer; font-size: 13px; padding: 3px 5px; border-radius: 4px; opacity: 0; transition: opacity .15s, color .15s; }
.cp-rm-row:hover .cp-rm-trash { opacity: 1; }
.cp-rm-trash:hover { color: #e53e3e; background: #fff5f5; }
.cp-rm-trash[data-tip] { position: absolute; }
.cp-rm-trash[data-tip]:hover::after { content: attr(data-tip); position: absolute; top: 100%; right: 0; margin-top: 4px; width: 200px; white-space: normal; background: #2d3748; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 11px; line-height: 1.35; text-align: left; box-shadow: 0 2px 6px rgba(0,0,0,0.25); z-index: 50; pointer-events: none; }
html[data-theme="dark"] .cp-rm-trash[data-tip]:hover::after { background: #000; }
.cp-rm-row.dismissing { opacity: 0; transition: opacity .3s; }
.cp-rm-add-count { font-size: 12px; color: #718096; align-self: center; margin-right: 4px; }
.cp-rm-controls { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; flex-wrap: wrap; }
.cp-rm-sort-label { font-size: 11px; font-weight: 600; color: #718096; text-transform: uppercase; letter-spacing: .4px; margin-right: 2px; }
.cp-rm-sort-btn { background: #edf2f7; border: 1px solid #e2e8f0; color: #4a5568; padding: 4px 10px; border-radius: 20px; font-size: 12px; cursor: pointer; font-weight: 600; transition: background .1s, border-color .1s; white-space: nowrap; }
.cp-rm-sort-btn:hover { background: #e2e8f0; }
.cp-rm-sort-btn.active { background: #2c5282; border-color: #2c5282; color: #fff; }

/* View filter (Open / All / Snoozed / Already Qualified) */
.cp-rm-view-btn { background: #edf2f7; border: 1px solid #e2e8f0; color: #4a5568; padding: 4px 12px; border-radius: 20px; font-size: 12px; cursor: pointer; font-weight: 600; transition: background .1s, border-color .1s; white-space: nowrap; }
.cp-rm-view-btn:hover { background: #e2e8f0; }
.cp-rm-view-btn.active { background: #2c5282; border-color: #2c5282; color: #fff; }

/* Inline metadata chips inside the rec head */
.cp-rm-age-badge { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; letter-spacing: .03em; flex-shrink: 0; }
.cp-age-green   { background: #f0fff4; color: #276749; }
.cp-age-yellow  { background: #fffff0; color: #975a16; }
.cp-age-orange  { background: #fffaf0; color: #c05621; }
.cp-age-red     { background: #fff5f5; color: #c53030; }

.cp-rm-seconds  { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: #2f855a; font-weight: 600; flex-shrink: 0; }
.cp-rm-seconds i { font-size: 9px; }

.cp-rm-onother  { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: #6b46c1; font-weight: 600; flex-shrink: 0; background: #faf5ff; border: 1px solid #d6bcfa; padding: 0 5px; border-radius: 4px; }
.cp-rm-onother i { font-size: 9px; }

.cp-rm-qualified { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: #276749; font-weight: 600; flex-shrink: 0; background: #f0fff4; border: 1px solid #9ae6b4; padding: 0 5px; border-radius: 4px; }
.cp-rm-qualified i { font-size: 9px; }

.cp-rm-snooze-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: #4a5568; font-weight: 600; flex-shrink: 0; background: #edf2f7; border: 1px solid #cbd5e0; padding: 0 5px; border-radius: 4px; }
.cp-rm-snooze-chip i { font-size: 9px; }

/* Snoozed rows render with a slight muting */
.cp-rm-row.cp-rm-snoozed:not(.already) { opacity: .8; }

/* Autocomplete */
.cp-ac-wrap { position: relative; }
.cp-ac-dropdown { position: fixed; top: 0; left: 0; width: 0; background: #fff; border: 1px solid #cbd5e0; border-radius: 5px; box-shadow: 0 4px 12px rgba(0,0,0,.1); z-index: 1100; max-height: 200px; overflow-y: auto; display: none; }
.cp-ac-item { padding: 8px 12px; cursor: pointer; font-size: 13px; }
.cp-ac-item:hover { background: #ebf8ff; }

.cp-tracking-icon {
    display: inline-block;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    text-align: center;
    line-height: 24px;
    cursor: pointer;
    font-size: 14px;
    margin-left: 4px;
}
.cp-tracking-icon[data-status="0"] { background-color: #ccc; color: #fff; } /* Gray */
.cp-tracking-icon[data-status="1"] { background-color: #e53e3e; color: #fff; } /* Red */
.cp-tracking-icon[data-status="2"] { background-color: #38a169; color: #fff; } /* Green */

/* Sidebar layout */
.cp-body { display: flex; gap: 20px; align-items: flex-start; }
.cp-sidebar { width: 210px; flex-shrink: 0; }
.cp-main-content { flex: 1; min-width: 0; }
.cp-sidebar-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.cp-sidebar-card-header { padding: 9px 14px; background: #f7fafc; border-bottom: 1px solid #e2e8f0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #718096; display: flex; align-items: center; gap: 6px; }
.cp-sidebar-card-body { padding: 12px 14px; }
.cp-sb-sort-btn { width: 100%; text-align: left; background: #edf2f7; border: 1px solid #e2e8f0; color: #4a5568; padding: 7px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; font-weight: 600; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; transition: background .1s; }
.cp-sb-sort-btn:hover { background: #e2e8f0; }
.cp-sb-sort-btn:last-child { margin-bottom: 0; }
.cp-sb-toggle-btn { width: 100%; text-align: left; background: #edf2f7; border: 1px solid #e2e8f0; color: #4a5568; padding: 7px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; font-weight: 600; margin-bottom: 0; display: flex; align-items: center; gap: 6px; transition: background .1s, color .1s, border-color .1s; }
.cp-sb-toggle-btn:hover { background: #e2e8f0; }
.cp-sb-toggle-btn.active { background: #2c5282; border-color: #2c5282; color: #fff; }
.cp-sb-toggle-btn.active:hover { background: #2a4a7f; }
@media (max-width: 800px) { .cp-body { flex-direction: column; } .cp-sidebar { width: 100%; } }
/* ---- Court Script preview modal ---- */
.cp-script-overlay { position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,.5); display: flex; align-items: flex-start; justify-content: center; padding: 30px 16px; overflow: auto; }
.cp-script-overlay[hidden] { display: none; }
.cp-script-modal { background: #fff; color: #1a202c; width: 100%; max-width: 760px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.3); overflow: hidden; }
.cp-script-chrome { padding: 18px 22px; border-bottom: 1px solid #e2e8f0; }
.cp-script-titlebar { text-align: center; margin-bottom: 12px; }
.cp-script-h1 { font-size: 22px; font-weight: 700; margin: 0; background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; color: #1a202c; }
.cp-script-date { color: #718096; margin: 4px 0 0; font-size: 13px; }
.cp-script-controls { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.cp-script-density { display: inline-flex; border: 1px solid #cbd5e0; border-radius: 6px; overflow: hidden; }
.cp-script-density button { border: none; background: #fff; color: #4a5568; font-size: 13px; font-weight: 600; padding: 6px 14px; cursor: pointer; }
.cp-script-density button + button { border-left: 1px solid #cbd5e0; }
.cp-script-density button.active { background: #2c5282; color: #fff; }
.cp-script-actions { display: flex; gap: 8px; }
.cp-script-body { padding: 20px 24px; font-family: Georgia, serif; max-height: 60vh; overflow: auto; }
.cp-script-empty { color: #718096; text-align: center; padding: 20px; }
/* compact density */
.cp-script-compact { width: 100%; border-collapse: collapse; font-size: 13px; }
.cp-script-compact td { padding: 5px 8px 5px 0; vertical-align: top; border-bottom: 1px solid #eee; line-height: 1.35; }
.cp-script-num { color: #a0aec0; font-size: 11px; width: 26px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.cp-script-check { width: 22px; font-size: 16px; line-height: 1; }
.cp-script-recip { font-weight: 700; white-space: nowrap; width: 38%; }
.cp-script-award { color: #2d3748; }
.cp-script-park { color: #718096; font-weight: 400; font-size: 11px; margin-left: 4px; }
.cp-script-ptl { color: #b7791f; font-size: 11px; font-style: italic; }
/* citation density */
.cp-script-cite { padding: 10px 0; border-bottom: 1px solid #eee; }
.cp-script-cite-head { font-size: 14px; line-height: 1.4; }
.cp-script-cite-num { color: #a0aec0; font-size: 12px; }
.cp-script-cite-recip { font-weight: 700; }
.cp-script-cite-award { color: #2d3748; }
.cp-script-cite-text { margin-top: 4px; color: #2d3748; line-height: 1.5; }
.cp-script-cite-artisans { margin-top: 4px; color: #4a5568; font-size: 13px; }
.cp-script-cite-artisans strong { color: #2d3748; }
/* dark mode (on-screen preview only) */
html[data-theme="dark"] .cp-script-modal { background: #161b22; color: #e2e8f0; }
html[data-theme="dark"] .cp-script-chrome { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-h1 { color: #e2e8f0; }
html[data-theme="dark"] .cp-script-density { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-density button { background: #1f2733; color: #cbd5e0; }
html[data-theme="dark"] .cp-script-density button + button { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-density button.active { background: #2b6cb0; color: #fff; }
html[data-theme="dark"] .cp-script-compact td,
html[data-theme="dark"] .cp-script-cite { border-color: #2d3748; }
html[data-theme="dark"] .cp-script-recip,
html[data-theme="dark"] .cp-script-award,
html[data-theme="dark"] .cp-script-cite-recip,
html[data-theme="dark"] .cp-script-cite-award,
html[data-theme="dark"] .cp-script-cite-text { color: #e2e8f0; }
html[data-theme="dark"] .cp-script-cite-artisans { color: #a0aec0; }
/* print: show only the rendered script body, force light, avoid page breaks mid-entry */
@media print {
    html { color-scheme: light; }
    body.cp-script-open { background: #fff !important; color-scheme: light; }
    body.cp-script-open > *:not(#cp-script-overlay) { display: none !important; }
    body.cp-script-open #cp-script-overlay { position: static; display: block !important; background: #fff; padding: 0; overflow: visible; }
    body.cp-script-open #cp-script-overlay .cp-script-modal { max-width: none; width: auto; margin: 0; box-shadow: none; border-radius: 0; background: #fff; color: #000; }
    body.cp-script-open .cp-script-controls { display: none !important; }
    body.cp-script-open .cp-script-chrome { border-bottom: 2px solid #333; padding: 0 0 10px; }
    body.cp-script-open .cp-script-body { max-height: none; overflow: visible; padding: 14px 0 0; color: #000; }
    body.cp-script-open .cp-script-cite,
    body.cp-script-open .cp-script-compact tr { break-inside: avoid; }
    body.cp-script-open .cp-script-h1,
    body.cp-script-open .cp-script-recip,
    body.cp-script-open .cp-script-award,
    body.cp-script-open .cp-script-cite-recip,
    body.cp-script-open .cp-script-cite-award,
    body.cp-script-open .cp-script-cite-text { color: #000; }
    @page { margin: 0.6in; }
}

.cp-status-bar { display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:13px; color:#4a5568; background:#f7fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 14px; margin-bottom:14px; }
.cp-status-sep { color:#cbd5e0; }
.cp-stat-ready { color:#276749; font-weight:600; }
.cp-stat-wip   { color:#c05621; font-weight:600; }
.cp-stat-none  { color:#718096; }

/* Award type accent border */
.cp-aw-type-title  { border-left: 4px solid #d69e2e !important; }
.cp-aw-type-ladder { border-left: 4px solid #9f7aea !important; }
.cp-aw-type-award  { border-left: 4px solid #38a169 !important; }

/* Published mode: hide drag column */
.cp-list-published .cp-reorder-btns { visibility: hidden; pointer-events: none; }

/* ============================================================
   SPREADSHEET REDESIGN — Court Planner v2
   ============================================================ */

/* Density tokens — applied at .cp-award-list level */
.cp-density-cozy        { --cp-row-py: 11px; --cp-row-px: 14px; --cp-row-font: 14px; --cp-row-num: 13px; --cp-track-size: 24px; --cp-track-font: 13px; }
.cp-density-comfortable { --cp-row-py: 6px;  --cp-row-px: 12px; --cp-row-font: 13px; --cp-row-num: 12px; --cp-track-size: 22px; --cp-track-font: 12px; }
.cp-density-compact     { --cp-row-py: 2px;  --cp-row-px: 10px; --cp-row-font: 12px; --cp-row-num: 11px; --cp-track-size: 18px; --cp-track-font: 10px; }

/* Toolbar above the spreadsheet */
.cp-list-toolbar {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 12px;
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    font-size: 12px;
    color: #4a5568;
    flex-wrap: wrap;
}
.cp-list-toolbar-label {
    font-size: 10px; font-weight: 700; color: #a0aec0;
    text-transform: uppercase; letter-spacing: .08em;
}
.cp-list-toolbar-spacer { flex: 1 1 auto; min-width: 4px; }

/* Density segmented control */
.cp-density-seg {
    display: inline-flex;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 2px;
    gap: 1px;
}
.cp-density-seg button {
    background: none; border: none;
    padding: 4px 9px; font-size: 11px; color: #4a5568;
    cursor: pointer; border-radius: 4px;
    font-weight: 600; display: inline-flex; align-items: center; gap: 5px;
    transition: background .12s, color .12s;
    line-height: 1;
}
.cp-density-seg button:hover { background: #f7fafc; }
.cp-density-seg button.active { background: #2c5282; color: #fff; }
.cp-density-seg button.active:hover { background: #2c5282; }
.cp-density-seg button i { font-size: 10px; }

/* When the toolbar is present, the list should square its top corners */
.cp-list-toolbar + .cp-award-list { border-radius: 0 0 8px 8px; border-top: none; }

/* ----- Grid template for header + rows (spreadsheet alignment) -----
   Columns: drag/order | num | recipient | award | type | flags | scroll | regalia | status | chevron
*/
.cp-row-grid {
    display: grid;
    grid-template-columns: 28px 32px minmax(110px, 1.3fr) minmax(160px, 1.6fr) 78px 50px 30px 30px 96px 22px;
    align-items: center;
    column-gap: 8px;
}
.cp-list-published .cp-row-grid {
    /* Wider status column to fit Grant / Skip buttons */
    grid-template-columns: 28px 32px minmax(110px, 1.3fr) minmax(160px, 1.6fr) 78px 50px 30px 30px 178px 22px;
}

/* Header row — sticky, label-style */
.cp-list-header {
    background: #f7fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 7px var(--cp-row-px, 12px);
    font-size: 10px; font-weight: 700;
    color: #718096;
    text-transform: uppercase; letter-spacing: .07em;
    position: sticky; top: 0; z-index: 4;
}
.cp-list-header > div {
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
}
.cp-list-header .cp-hdr-num     { text-align: right; padding-right: 2px; }
.cp-list-header .cp-hdr-scroll,
.cp-list-header .cp-hdr-regalia { text-align: center; }
.cp-list-header .cp-hdr-status  { text-align: left; }

/* The list itself becomes the spreadsheet container */
.cp-award-list { background: #fff; }
/* Override the old per-row border-bottom — single 1px on row instead */
.cp-award-row { border-bottom: 1px solid #edf2f7; background: #fff; }
.cp-award-row:last-child { border-bottom: none; }

/* Zebra striping (subtle) — light mode */
.cp-award-row:nth-child(even) { background: #fafbfc; }
.cp-density-compact .cp-award-row:nth-child(even) { background: #fbfcfd; }

/* The row's main interactive area becomes a grid */
.cp-award-row-main {
    padding: var(--cp-row-py, 6px) var(--cp-row-px, 12px);
    font-size: var(--cp-row-font, 13px);
    cursor: pointer;
    column-gap: 8px;
    /* Re-declare grid template since this overrides the old flex display */
    display: grid;
}
.cp-award-row-main:hover { background: #edf2f7; }

/* Cells */
.cp-cell { min-width: 0; }
.cp-cell-order  { display: flex; align-items: center; justify-content: flex-start; }
.cp-cell-num    { color: #a0aec0; font-variant-numeric: tabular-nums; font-size: var(--cp-row-num, 12px); text-align: right; padding-right: 2px; font-weight: 600; }
.cp-cell-recipient { display: flex; align-items: baseline; gap: 6px; min-width: 0; font-weight: 700; color: #2d3748; overflow: hidden; }
.cp-cell-recipient .cp-recipient-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.cp-cell-recipient .cp-award-park    { font-weight: 400; color: #718096; font-size: 11px; letter-spacing: .2px; flex-shrink: 0; }
.cp-cell-recipient .cp-note-btn      { background: none; border: none; cursor: pointer; color: #a0aec0; font-size: 11px; padding: 0; flex-shrink: 0; line-height: 1; transition: color .15s; }
.cp-cell-recipient .cp-note-btn:hover { color: #4a5568; }
.cp-cell-award   { color: #4a5568; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: flex; align-items: baseline; gap: 4px; min-width: 0; }
.cp-cell-award .cp-award-name-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.cp-cell-award .cp-award-rank      { color: #a0aec0; font-size: 11px; flex-shrink: 0; font-weight: 600; }
.cp-cell-type    { display: flex; align-items: center; }
.cp-cell-type > * { width: 100%; text-align: center; }
.cp-cell-flags   { display: flex; gap: 4px; align-items: center; flex-wrap: nowrap; overflow: hidden; }
.cp-cell-scroll, .cp-cell-regalia { display: flex; align-items: center; justify-content: center; }
.cp-cell-status  { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.cp-cell-chevron { color: #cbd5e0; font-size: 11px; text-align: center; display: flex; align-items: center; justify-content: center; }

/* Tracking icons — scale by density */
.cp-density-cozy        .cp-tracking-icon,
.cp-density-comfortable .cp-tracking-icon,
.cp-density-compact     .cp-tracking-icon {
    width: var(--cp-track-size, 22px); height: var(--cp-track-size, 22px);
    line-height: var(--cp-track-size, 22px);
    font-size: var(--cp-track-font, 12px);
    margin-left: 0;
}

/* Reorder arrows compact in spreadsheet */
.cp-row-grid .cp-reorder-btns { gap: 0; }
.cp-row-grid .cp-reorder-btn { width: 18px; height: 13px; font-size: 8px; }
.cp-density-compact .cp-row-grid .cp-reorder-btn { height: 11px; }
.cp-density-cozy    .cp-row-grid .cp-reorder-btn { height: 15px; }

/* Type badge — pill in cozy/comfortable, square chip in compact */
.cp-cell-type .cp-type-title,
.cp-cell-type .cp-type-ladder,
.cp-cell-type .cp-type-award {
    width: 100%; box-sizing: border-box;
    text-align: center; padding: 1px 6px; font-size: 10px;
    letter-spacing: .04em;
}
.cp-density-compact .cp-cell-type .cp-type-title,
.cp-density-compact .cp-cell-type .cp-type-ladder,
.cp-density-compact .cp-cell-type .cp-type-award { font-size: 9px; padding: 0 4px; border-radius: 3px; }

/* Status badge sizing by density */
.cp-density-compact .cp-aw-badge { padding: 1px 6px; font-size: 10px; }

/* Cozy mode: allow recipient to wrap park abbrev underneath */
.cp-density-cozy .cp-cell-recipient { flex-direction: column; align-items: flex-start; gap: 1px; }
.cp-density-cozy .cp-cell-recipient .cp-award-park { font-size: 10px; margin-left: 0; }
.cp-density-cozy .cp-cell-award { flex-direction: column; align-items: flex-start; gap: 1px; line-height: 1.3; }

/* Compact mode: shrink padding aggressively */
.cp-density-compact .cp-cell-recipient { font-size: 12px; }
.cp-density-compact .cp-cell-recipient .cp-award-park { font-size: 10px; }
.cp-density-compact .cp-cell-award      { font-size: 12px; }
.cp-density-compact .cp-flag-local,
.cp-density-compact .cp-flag-rec        { width: 16px; height: 16px; font-size: 8px; }
.cp-density-comfortable .cp-flag-local,
.cp-density-comfortable .cp-flag-rec    { width: 18px; height: 18px; font-size: 9px; }

/* Granted / Skipped rows */
.cp-award-row.cp-granted .cp-award-row-main,
.cp-award-row.cp-skipped .cp-award-row-main { padding-top: 4px; padding-bottom: 4px; }
.cp-density-compact .cp-award-row.cp-granted .cp-award-row-main,
.cp-density-compact .cp-award-row.cp-skipped .cp-award-row-main { padding-top: 2px; padding-bottom: 2px; }

/* Hide the colored left border on rows when in spreadsheet view (type column shows it instead).
   Keep a 3px colored marker on the order cell. */
.cp-award-row.cp-aw-type-title,
.cp-award-row.cp-aw-type-ladder,
.cp-award-row.cp-aw-type-award { border-left: none !important; }
.cp-cell-order { position: relative; }
.cp-award-row.cp-aw-type-title  .cp-cell-order::before,
.cp-award-row.cp-aw-type-ladder .cp-cell-order::before,
.cp-award-row.cp-aw-type-award  .cp-cell-order::before {
    content: ''; position: absolute; left: -12px; top: 0; bottom: 0; width: 3px;
}
.cp-award-row.cp-aw-type-title  .cp-cell-order::before { background: #d69e2e; }
.cp-award-row.cp-aw-type-ladder .cp-cell-order::before { background: #9f7aea; }
.cp-award-row.cp-aw-type-award  .cp-cell-order::before { background: #38a169; }

/* Grant / Skip in published mode — sized to fit status column */
.cp-list-published .cp-grant-actions .cp-btn-grant,
.cp-list-published .cp-grant-actions .cp-btn-skip {
    padding: 3px 8px; font-size: 11px; gap: 4px;
}
.cp-density-compact .cp-list-published .cp-grant-actions .cp-btn-grant,
.cp-density-compact .cp-list-published .cp-grant-actions .cp-btn-skip {
    padding: 2px 6px; font-size: 10px;
}

/* Expand area — match new alignment */
.cp-award-row-expand { padding: 14px 18px 16px; border-top: 1px solid #edf2f7; background: #fafbfc; }
.cp-density-compact .cp-award-row-expand { padding: 10px 14px 12px; }

/* ----- Sidebar collapse ----- */
.cp-sidebar { width: 220px; flex-shrink: 0; transition: width .22s ease; position: relative; }
.cp-sidebar-rail {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 8px; padding: 0 2px;
}
.cp-sidebar-rail-label {
    font-size: 10px; font-weight: 700; color: #a0aec0;
    text-transform: uppercase; letter-spacing: .08em;
}
.cp-sidebar-collapse-btn {
    background: #fff; border: 1px solid #e2e8f0;
    width: 26px; height: 26px; border-radius: 6px;
    cursor: pointer; color: #718096;
    display: flex; align-items: center; justify-content: center;
    transition: color .12s, border-color .12s, background .12s;
}
.cp-sidebar-collapse-btn:hover { color: #2d3748; border-color: #cbd5e0; background: #f7fafc; }
.cp-sidebar-collapse-btn i { font-size: 11px; }

/* Collapsed state */
.cp-body.cp-sidebar-collapsed .cp-sidebar { width: 30px; }
.cp-body.cp-sidebar-collapsed .cp-sidebar-card { display: none; }
.cp-body.cp-sidebar-collapsed .cp-sidebar-rail { justify-content: center; }
.cp-body.cp-sidebar-collapsed .cp-sidebar-rail-label { display: none; }
.cp-body.cp-sidebar-collapsed .cp-sidebar-collapse-btn { width: 30px; height: 30px; }

/* Chevron direction by state */
.cp-sidebar-collapse-btn .cp-side-arrow-collapse { display: inline-block; }
.cp-sidebar-collapse-btn .cp-side-arrow-expand   { display: none; }
.cp-body.cp-sidebar-collapsed .cp-sidebar-collapse-btn .cp-side-arrow-collapse { display: none; }
.cp-body.cp-sidebar-collapsed .cp-sidebar-collapse-btn .cp-side-arrow-expand   { display: inline-block; }

/* ----- Error box + section-header reusable bits ----- */
.cp-error-box { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 14px 18px; border-radius: 6px; }
.cp-h2-icon   { color: #4a5568; margin-right: 6px; }
.cp-count     { font-size: 13px; color: #718096; font-weight: 400; }
.cp-btn-danger-inline { background: #fff5f5 !important; border: 1px solid #fc8181 !important; color: #c53030 !important; }

/* ----- About-card pills & legend (light mode defaults) ----- */
.cp-about-body { font-size: 12px; line-height: 1.55; color: #4a5568; }
.cp-about-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #a0aec0; margin-bottom: 6px; }
.cp-about-flow { display: flex; align-items: center; gap: 5px; margin-bottom: 12px; flex-wrap: wrap; }
.cp-about-arrow { color: #cbd5e0; font-size: 10px; }
.cp-about-list { margin: 0 0 12px; padding-left: 14px; }
.cp-about-list li { margin-bottom: 4px; }
.cp-about-p { margin: 0 0 12px; }
.cp-about-track-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 6px; }
.cp-about-track-row .cp-tracking-demo { flex-shrink: 0; pointer-events: none; margin-top: 1px; }
.cp-about-legend { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; font-size: 11px; color: #718096; margin-top: 4px; }
.cp-legend-gray   { font-weight: 700; color: #a0aec0; }
.cp-legend-red    { font-weight: 700; color: #e53e3e; }
.cp-legend-green  { font-weight: 700; color: #38a169; }

/* Status pills used in About section (workflow chain + grant/skip examples) */
.cp-pill { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 700; }
.cp-pill-draft     { background: #edf2f7; color: #718096; }
.cp-pill-published { background: #ebf8ff; color: #2b6cb0; }
.cp-pill-complete  { background: #f0fff4; color: #276749; }
.cp-pill-grant     { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; padding: 1px 6px; }
.cp-pill-skip      { background: #edf2f7; color: #718096; border: 1px solid #cbd5e0; padding: 1px 6px; }

/* ----- Dark-mode pre-emptive overrides ----- */
/* New spreadsheet surfaces */
html[data-theme="dark"] .cp-list-toolbar { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-list-toolbar-label,
html[data-theme="dark"] .cp-sidebar-rail-label { color: #718096; }
html[data-theme="dark"] .cp-density-seg { background: #161b22; border-color: #2d3748; }
html[data-theme="dark"] .cp-density-seg button { color: #cbd5e0; }
html[data-theme="dark"] .cp-density-seg button:hover { background: #1f2733; }
html[data-theme="dark"] .cp-list-header { background: #1f2733; color: #a0aec0; border-color: #2d3748; }
html[data-theme="dark"] .cp-award-list { background: #161b22; border-color: #2d3748; }
html[data-theme="dark"] .cp-award-row { background: #161b22; border-color: #1f2733; }
html[data-theme="dark"] .cp-award-row:nth-child(even) { background: #1a2030; }
html[data-theme="dark"] .cp-award-row-main:hover { background: #1f2733; }
html[data-theme="dark"] .cp-cell-recipient { color: #e2e8f0; }
html[data-theme="dark"] .cp-cell-award     { color: #a0aec0; }
html[data-theme="dark"] .cp-cell-num,
html[data-theme="dark"] .cp-cell-chevron   { color: #4a5568; }
html[data-theme="dark"] .cp-award-row-expand { background: #1a2030; border-color: #2d3748; }
html[data-theme="dark"] .cp-sidebar-collapse-btn { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-sidebar-collapse-btn:hover { background: #2d3748; }

/* Status bar + section heading */
html[data-theme="dark"] .cp-status-bar { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-status-sep { color: #4a5568; }
html[data-theme="dark"] .cp-stat-none  { color: #718096; }
html[data-theme="dark"] .cp-section-header h2 { color: #e2e8f0; }
html[data-theme="dark"] .cp-section-header h2 i { color: #a0aec0 !important; }
html[data-theme="dark"] #cp-award-count { color: #718096 !important; }

/* Sidebar cards */
html[data-theme="dark"] .cp-sidebar-card { background: #161b22; border-color: #2d3748; box-shadow: none; }
html[data-theme="dark"] .cp-sidebar-card-header { background: #1f2733; border-color: #2d3748; color: #a0aec0; }
html[data-theme="dark"] .cp-sb-sort-btn,
html[data-theme="dark"] .cp-sb-toggle-btn { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-sb-sort-btn:hover,
html[data-theme="dark"] .cp-sb-toggle-btn:hover { background: #2d3748; }
html[data-theme="dark"] .cp-sb-toggle-btn.active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
html[data-theme="dark"] .cp-sidebar-card hr { border-top-color: #2d3748 !important; }

/* About card content */
html[data-theme="dark"] .cp-about-body { color: #cbd5e0; }
html[data-theme="dark"] .cp-about-section { color: #718096; }
html[data-theme="dark"] .cp-about-arrow { color: #4a5568; }
html[data-theme="dark"] .cp-about-legend { background: #1f2733; border-color: #2d3748; color: #a0aec0; }

/* Workflow / pill chips — soften the light pastel backgrounds */
html[data-theme="dark"] .cp-pill-draft     { background: rgba(160,174,192,.15);  color: #cbd5e0; }
html[data-theme="dark"] .cp-pill-published { background: rgba(99,179,237,.18);   color: #90cdf4; }
html[data-theme="dark"] .cp-pill-complete  { background: rgba(72,187,120,.18);   color: #9ae6b4; }
html[data-theme="dark"] .cp-pill-grant     { background: rgba(72,187,120,.18);   color: #9ae6b4; border-color: rgba(72,187,120,.4); }
html[data-theme="dark"] .cp-pill-skip      { background: rgba(160,174,192,.15);  color: #cbd5e0; border-color: rgba(160,174,192,.3); }

/* Type chips in spreadsheet rows — same softening */
html[data-theme="dark"] .cp-type-title  { background: rgba(214,158,46,.18);  color: #f6e05e; border-color: rgba(214,158,46,.4); }
html[data-theme="dark"] .cp-type-ladder { background: rgba(159,122,234,.20); color: #d6bcfa; border-color: rgba(159,122,234,.4); }
html[data-theme="dark"] .cp-type-award  { background: rgba(72,187,120,.18);  color: #9ae6b4; border-color: rgba(72,187,120,.4); }

/* Status badge backgrounds use inline styles — apply darker, more readable variants by status class */
html[data-theme="dark"] .cp-aw-badge { background: #1f2733 !important; color: #cbd5e0 !important; box-shadow: inset 0 0 0 1px #2d3748; }
html[data-theme="dark"] .cp-award-row[data-status-tone="given"]      .cp-aw-badge,
html[data-theme="dark"] .cp-award-row.cp-granted .cp-aw-badge { background: rgba(72,187,120,.18) !important; color: #9ae6b4 !important; box-shadow: inset 0 0 0 1px rgba(72,187,120,.35); }
html[data-theme="dark"] .cp-award-row.cp-skipped .cp-aw-badge { background: rgba(229,62,62,.16) !important; color: #fc8181 !important; box-shadow: inset 0 0 0 1px rgba(229,62,62,.35); }

/* Flag badges (PtL, From-Rec) */
html[data-theme="dark"] .cp-flag-local { background: rgba(214,158,46,.20); color: #f6e05e; border-color: rgba(214,158,46,.4); }
html[data-theme="dark"] .cp-flag-rec   { background: rgba(99,179,237,.18); color: #90cdf4; border-color: rgba(99,179,237,.4); }

/* Reorder arrows */
html[data-theme="dark"] .cp-reorder-btn { background: #1f2733; border-color: #2d3748; color: #718096; }
html[data-theme="dark"] .cp-reorder-btn:hover { background: #2d3748; color: #cbd5e0; }

/* Note popup is already dark, but ensure consistency on the trigger button */
html[data-theme="dark"] .cp-note-btn { color: #718096; }
html[data-theme="dark"] .cp-note-btn:hover { color: #cbd5e0; }

/* Award park abbreviation */
html[data-theme="dark"] .cp-award-park { color: #718096; }

/* Buttons */
html[data-theme="dark"] .cp-btn-outline { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-btn-outline:hover { background: #2d3748; }
html[data-theme="dark"] .cp-btn-skip   { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-btn-skip:hover { background: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-btn-grant  { background: #276749; color: #fff; }
html[data-theme="dark"] .cp-btn-grant:hover { background: #22543d; }
html[data-theme="dark"] .cp-btn-danger-sm { color: #fc8181; }

/* Tracking icons — status 0 (gray) needs to recede on dark bg */
html[data-theme="dark"] .cp-tracking-icon[data-status="0"] { background-color: #2d3748; color: #718096; }
html[data-theme="dark"] .cp-tracking-icon[data-status="1"] { background-color: #c53030; color: #fff; }
html[data-theme="dark"] .cp-tracking-icon[data-status="2"] { background-color: #276749; color: #fff; }

/* Empty state */
html[data-theme="dark"] .cp-award-empty { color: #4a5568; }

/* Modal */
html[data-theme="dark"] .cp-overlay { background: rgba(0,0,0,.65); }
html[data-theme="dark"] .cp-modal { background: #161b22; box-shadow: 0 8px 32px rgba(0,0,0,.6); }
html[data-theme="dark"] .cp-modal-header { border-bottom-color: #2d3748; }
html[data-theme="dark"] .cp-modal-header h3 { color: #e2e8f0; }
html[data-theme="dark"] .cp-modal-close { color: #718096; }
html[data-theme="dark"] .cp-modal-close:hover { color: #cbd5e0; }
html[data-theme="dark"] .cp-modal-footer { border-top-color: #2d3748; }

/* Form fields inside modals */
html[data-theme="dark"] .cp-field label { color: #a0aec0; }
html[data-theme="dark"] .cp-field input,
html[data-theme="dark"] .cp-field select,
html[data-theme="dark"] .cp-field textarea { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-field input::placeholder,
html[data-theme="dark"] .cp-field textarea::placeholder { color: #4a5568; }
html[data-theme="dark"] .cp-field input:focus,
html[data-theme="dark"] .cp-field select:focus,
html[data-theme="dark"] .cp-field textarea:focus { border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,.2); outline: none; }

/* Recommendation modal */
html[data-theme="dark"] .cp-rm-search { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-rm-search::placeholder { color: #4a5568; }
html[data-theme="dark"] .cp-rm-search-wrap i { color: #4a5568; }
html[data-theme="dark"] .cp-rm-meta { color: #a0aec0; }
html[data-theme="dark"] .cp-rm-meta strong { color: #90cdf4; }
html[data-theme="dark"] .cp-rm-list { background: #161b22; border-color: #2d3748; }
html[data-theme="dark"] .cp-rm-row { border-bottom-color: #1f2733; }
html[data-theme="dark"] .cp-rm-row:hover:not(.already) { background: #1f2733; }
html[data-theme="dark"] .cp-rm-row.selected { background: rgba(66,153,225,.15); }
html[data-theme="dark"] .cp-rm-row.selected::before { background: #4299e1; }
html[data-theme="dark"] .cp-rm-row.already { background: #161b22; opacity: .55; }
html[data-theme="dark"] .cp-rm-persona { color: #e2e8f0; }
html[data-theme="dark"] .cp-rm-park    { color: #718096; }
html[data-theme="dark"] .cp-rm-award   { color: #cbd5e0; }
html[data-theme="dark"] .cp-rm-reason  { color: #a0aec0; }
html[data-theme="dark"] .cp-rm-date    { color: #718096; }
html[data-theme="dark"] .cp-rm-sep     { color: #4a5568; }
html[data-theme="dark"] .cp-rm-rank    { background: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-rm-in-plan { background: rgba(214,158,46,.18); color: #f6e05e; border-color: rgba(214,158,46,.4); }
html[data-theme="dark"] .cp-rm-empty   { color: #4a5568; }
html[data-theme="dark"] .cp-rm-sort-label { color: #a0aec0; }
html[data-theme="dark"] .cp-rm-sort-btn { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-rm-sort-btn:hover { background: #2d3748; }
html[data-theme="dark"] .cp-rm-sort-btn.active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
html[data-theme="dark"] .cp-rm-view-btn { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-rm-view-btn:hover { background: #2d3748; }
html[data-theme="dark"] .cp-rm-view-btn.active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
html[data-theme="dark"] .cp-age-green   { background: rgba(72,187,120,.18); color: #9ae6b4; }
html[data-theme="dark"] .cp-age-yellow  { background: rgba(214,158,46,.18); color: #f6e05e; }
html[data-theme="dark"] .cp-age-orange  { background: rgba(237,137,54,.18); color: #fbd38d; }
html[data-theme="dark"] .cp-age-red     { background: rgba(229,62,62,.16);  color: #fc8181; }
html[data-theme="dark"] .cp-rm-seconds  { color: #9ae6b4; }
html[data-theme="dark"] .cp-rm-onother  { background: rgba(159,122,234,.16); border-color: rgba(159,122,234,.4); color: #d6bcfa; }
html[data-theme="dark"] .cp-rm-qualified { background: rgba(72,187,120,.16); border-color: rgba(72,187,120,.4); color: #9ae6b4; }
html[data-theme="dark"] .cp-rm-snooze-chip { background: rgba(160,174,192,.12); border-color: rgba(160,174,192,.3); color: #cbd5e0; }
html[data-theme="dark"] .cp-rm-add-count { color: #a0aec0; }

/* Expand area inner controls */
html[data-theme="dark"] .cp-expand-label { color: #a0aec0; }
html[data-theme="dark"] .cp-expand-val   { color: #e2e8f0; }
html[data-theme="dark"] .cp-notes-area   { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-notes-area::placeholder { color: #4a5568; }
html[data-theme="dark"] .cp-artisan-row { color: #cbd5e0; }
html[data-theme="dark"] .cp-maker-ac    { background: #1f2733 !important; border-color: #2d3748 !important; color: #e2e8f0 !important; }
html[data-theme="dark"] .cp-maker-ac::placeholder { color: #4a5568; }
html[data-theme="dark"] select[id^="cp-status-"] { background: #1f2733 !important; border-color: #2d3748 !important; color: #e2e8f0 !important; }

/* Autocomplete dropdown (inline styles on some instances — need !important) */
html[data-theme="dark"] .cp-ac-dropdown { background: #1f2733 !important; border-color: #2d3748 !important; box-shadow: 0 4px 12px rgba(0,0,0,.4) !important; }
html[data-theme="dark"] .cp-ac-item { color: #cbd5e0; }
html[data-theme="dark"] .cp-ac-item:hover { background: #2d3748 !important; }

/* Error inline */
html[data-theme="dark"] .cp-error { color: #fc8181; }
html[data-theme="dark"] .cp-error-box { background: rgba(229,62,62,.12); border-color: rgba(229,62,62,.4); color: #fc8181; }
html[data-theme="dark"] .cp-h2-icon { color: #a0aec0; }
html[data-theme="dark"] .cp-count   { color: #718096; }
html[data-theme="dark"] .cp-btn-danger-inline { background: rgba(229,62,62,.12) !important; border-color: rgba(229,62,62,.4) !important; color: #fc8181 !important; }

</style>

<?php if ($error): ?>
<div style="padding:24px">
    <div class="cp-error-box">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
</div>
<?php else: ?>

<!-- Hero -->
<div class="cp-hero" id="cp-hero">
    <?php if ($heraldryUrl): ?>
    <div class="cp-hero-bg" style="background-image:url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
    <?php endif; ?>
    <div class="cp-hero-content">
        <!-- Heraldry -->
        <?php if ($hasHeraldry): ?>
        <div class="cp-heraldry-wrap">
            <div class="cp-heraldry-frame">
                <img src="<?= htmlspecialchars($heraldryUrl) ?>"
                     alt="heraldry" crossorigin="anonymous"
                     onload="cpApplyHeroColor(this)">
            </div>
        </div>
        <?php else: ?>
        <div class="cp-hero-heraldry-placeholder"><i class="fas fa-gavel"></i></div>
        <?php endif; ?>

        <!-- Info -->
        <div class="cp-hero-info">
            <div class="cp-hero-supertitle">
                <?php if ($court['ParkId'] > 0 && $court['ParkName']): ?>
                <a href="<?= UIR ?>Park/profile/<?= (int)$court['ParkId'] ?>">
                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($court['ParkName']) ?>
                </a>
                &nbsp;&bull;&nbsp;
                <?php endif; ?>
                <a href="<?= UIR ?>Kingdom/profile/<?= (int)$court['KingdomId'] ?>">
                    <i class="fas fa-crown"></i> <?= htmlspecialchars($court['KingdomName']) ?>
                </a>
                &nbsp;&bull;&nbsp;
                <a href="<?= htmlspecialchars($backUrl) ?>" class="cp-back">
                    <i class="fas fa-arrow-left"></i> <?= htmlspecialchars($backLabel) ?>
                </a>
            </div>
            <div class="cp-hero-name"><?= htmlspecialchars($court['Name']) ?></div>
            <div class="cp-hero-meta">
                <?php if ($court['CourtDate']): ?>
                <span><i class="fas fa-calendar"></i><?= date('l, F j, Y', strtotime($court['CourtDate'])) ?></span>
                <?php endif; ?>
                <?php if ($court['EventName']): ?>
                <span><i class="fas fa-flag"></i><?= htmlspecialchars($court['EventName']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status + actions -->
        <div class="cp-hero-actions">
            <span class="cp-badge" id="cp-status-badge"
                  style="background:<?= $statusBg[$courtSt] ?? '#edf2f7' ?>;color:<?= $statusColor[$courtSt] ?? '#718096' ?>">
                <?= $statusLabel[$courtSt] ?? $courtSt ?>
            </span>
            <?php if ($nextSt): ?>
            <button class="cp-btn-primary" onclick="cpAdvanceStatus('<?= $nextSt ?>')">
                <?= $nextLabel[$courtSt] ?? 'Advance' ?> <i class="fas fa-arrow-right"></i>
            </button>
            <?php endif; ?>
            <?php if ($courtSt === 'published'): ?>
            <button class="cp-btn-outline" style="margin-top: 5px;" onclick="cpReturnToPlanning('draft')">
                <i class="fas fa-arrow-left"></i> Return to Planning
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$_scroll_counts  = [0=>0, 1=>0, 2=>0];
$_regalia_counts = [0=>0, 1=>0, 2=>0];
foreach ($courtAwards ?? [] as $_ca) {
    $_scroll_counts[min(2, max(0, (int)($_ca['ScrollStatus'] ?? 0)))]++;
    $_regalia_counts[min(2, max(0, (int)($_ca['RegaliaStatus'] ?? 0)))]++;
}
$_total_awards = count($courtAwards ?? []);
?>

<?php if (!$error): ?>
<div class="cp-page" style="padding-top:0;padding-bottom:0;margin-bottom:0">
<div class="cp-status-bar">
    <span id="cp-sb-total"><?= $_total_awards ?> award<?= $_total_awards !== 1 ? 's' : '' ?></span>
    <span class="cp-status-sep"> &middot; </span>
    <span class="cp-sb-scroll">Scrolls: <span class="cp-stat-ready"><?= $_scroll_counts[2] ?> ready</span>, <span class="cp-stat-wip"><?= $_scroll_counts[1] ?> in progress</span>, <span class="cp-stat-none"><?= $_scroll_counts[0] ?> not started</span></span>
    <span class="cp-status-sep"> &middot; </span>
    <span class="cp-sb-regalia">Regalia: <span class="cp-stat-ready"><?= $_regalia_counts[2] ?> ready</span>, <span class="cp-stat-wip"><?= $_regalia_counts[1] ?> in progress</span>, <span class="cp-stat-none"><?= $_regalia_counts[0] ?> not started</span></span>
    <?php if (in_array($courtSt, ['published','complete'])): ?>
    <span class="cp-status-sep"> &middot; </span>
    <span class="cp-sb-progress" id="cp-sb-progress"></span>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>

<div class="cp-page"><div class="cp-body" id="cp-body">

    <!-- Sidebar -->
    <div class="cp-sidebar">
        <div class="cp-sidebar-rail">
            <span class="cp-sidebar-rail-label">Tools</span>
            <button class="cp-sidebar-collapse-btn" id="cp-sidebar-collapse-btn"
                    onclick="cpToggleSidebar()" type="button"
                    title="Collapse sidebar" aria-label="Collapse sidebar">
                <i class="fas fa-chevron-left cp-side-arrow-collapse"></i>
                <i class="fas fa-chevron-right cp-side-arrow-expand"></i>
            </button>
        </div>
        <?php if ($courtSt === 'draft'): ?>
        <div class="cp-sidebar-card">
            <div class="cp-sidebar-card-header"><i class="fas fa-sort"></i> Sort Order</div>
            <div class="cp-sidebar-card-body">
                <button class="cp-sb-sort-btn" onclick="cpSortByOrders()">
                    <i class="fas fa-sort-numeric-up"></i> Orders Low &rarr; High
                </button>
                <button class="cp-sb-sort-btn" onclick="cpSortTitlesLast()">
                    <i class="fas fa-crown"></i> Titles Last
                </button>
                <hr style="border:none;border-top:1px solid #e2e8f0;margin:8px 0">
                <button class="cp-sb-toggle-btn" id="cp-printing-list-btn" onclick="cpTogglePrintingList()">
                    <i class="fas fa-print"></i> Printing List
                </button>
            </div>
        </div>
        <?php endif; ?>
        <div class="cp-sidebar-card">
            <details open>
                <summary class="cp-sidebar-card-header" style="cursor:pointer;list-style:none;display:flex"><i class="fas fa-info-circle"></i> About This Tool</summary>
                <div class="cp-sidebar-card-body cp-about-body">

                    <div class="cp-about-section">Workflow</div>
                    <div class="cp-about-flow">
                        <span class="cp-pill cp-pill-draft">Draft</span>
                        <i class="fas fa-arrow-right cp-about-arrow"></i>
                        <span class="cp-pill cp-pill-published">Published</span>
                        <i class="fas fa-arrow-right cp-about-arrow"></i>
                        <span class="cp-pill cp-pill-complete">Complete</span>
                    </div>

                    <div class="cp-about-section">Building Your List</div>
                    <ul class="cp-about-list">
                        <li>Add awards from pending <strong>Recommendations</strong> or create an <strong>Ad-hoc</strong> entry for any recipient.</li>
                        <li>Use the <strong>Sort Order</strong> buttons above to quickly arrange awards, then fine-tune with the <i class="fas fa-arrow-up cp-about-arrow"></i><i class="fas fa-arrow-down cp-about-arrow"></i> arrows on each row.</li>
                        <li>Click any row to expand it and add <strong>notes</strong>, set <strong>Pass to Local</strong>, or credit <strong>artisans</strong> who made scrolls or tokens.</li>
                    </ul>

                    <div class="cp-about-section">Running Court</div>
                    <p class="cp-about-p">Click <strong>Publish</strong> when the list is final. During court, use the <span class="cp-pill cp-pill-grant"><i class="fas fa-check"></i> Grant</span> and <span class="cp-pill cp-pill-skip"><i class="fas fa-forward"></i> Skip</span> buttons on each award to track progress in real time.</p>

                    <div class="cp-about-section">Scroll &amp; Regalia Tracking</div>
                    <p class="cp-about-p" style="margin-bottom:8px">Each row has two icons you can click to cycle status:</p>
                    <div class="cp-about-track-row">
                        <span class="cp-tracking-icon cp-tracking-demo" data-status="1"><i class="fas fa-print"></i></span>
                        <span><strong>Scroll</strong> &mdash; needs to be printed.</span>
                    </div>
                    <div class="cp-about-track-row">
                        <span class="cp-tracking-icon cp-tracking-demo" data-status="1"><i class="fas fa-medal"></i></span>
                        <span><strong>Regalia</strong> &mdash; needs a physical token.</span>
                    </div>
                    <div class="cp-about-legend">
                        <span class="cp-legend-gray">● gray</span> not tracked &nbsp;&rarr;&nbsp;
                        <span class="cp-legend-red">● red</span> needs doing &nbsp;&rarr;&nbsp;
                        <span class="cp-legend-green">● green</span> done
                    </div>

                </div>
            </details>
        </div>
    </div>

    <!-- Main content -->
    <div class="cp-main-content">

    <!-- Award list -->
    <div class="cp-section-header">
        <h2><i class="fas fa-award cp-h2-icon"></i>
            Order of Court <span id="cp-award-count" class="cp-count">(<?= count($courtAwards) ?>)</span>
        </h2>
        <?php if ($courtSt === 'draft'): ?>
        <div style="display:flex;gap:8px">
            <?php if (!empty($pendingRecs)): ?>
            <button class="cp-btn-outline cp-btn-sm" onclick="cpOpenRecModal()">
                <i class="fas fa-star"></i> Add from Recommendations
            </button>
            <?php endif; ?>
            <button class="cp-btn-primary cp-btn-sm" onclick="cpOpenAdhocModal()">
                <i class="fas fa-plus"></i> Add Ad-hoc Award
            </button>
        </div>
        <?php endif; ?>
        <button class="cp-btn cp-btn-outline" id="cp-script-btn" onclick="cpOpenScript()">
            <i class="fas fa-scroll"></i> Court Script
        </button>
    </div>

    <!-- Spreadsheet toolbar -->
    <div class="cp-list-toolbar">
        <span class="cp-list-toolbar-label"><i class="fas fa-table-cells" style="margin-right:5px"></i>View</span>
        <div class="cp-density-seg" id="cp-density-seg" role="group" aria-label="Row density">
            <button type="button" data-density="cozy"        onclick="cpSetDensity('cozy')"        title="Cozy — extra spacing"><i class="fas fa-bars"></i> Cozy</button>
            <button type="button" data-density="comfortable" onclick="cpSetDensity('comfortable')" title="Comfortable — balanced spacing"><i class="fas fa-grip-lines"></i> Comfortable</button>
            <button type="button" data-density="compact"     onclick="cpSetDensity('compact')"     title="Compact — dense rows"><i class="fas fa-minus"></i> Compact</button>
        </div>
        <span class="cp-list-toolbar-spacer"></span>
        <span class="cp-list-toolbar-label" id="cp-list-toolbar-count"><?= count($courtAwards) ?> award<?= count($courtAwards) !== 1 ? 's' : '' ?></span>
    </div>

    <div class="cp-award-list<?= in_array($courtSt, ['published','complete']) ? ' cp-list-published' : '' ?>" id="cp-award-list">
        <!-- Column header -->
        <div class="cp-list-header cp-row-grid" id="cp-list-header">
            <div class="cp-hdr-order"></div>
            <div class="cp-hdr-num">#</div>
            <div class="cp-hdr-recipient">Recipient</div>
            <div class="cp-hdr-award">Award</div>
            <div class="cp-hdr-type">Type</div>
            <div class="cp-hdr-flags">Flags</div>
            <div class="cp-hdr-scroll" title="Scroll"><i class="fas fa-print"></i></div>
            <div class="cp-hdr-regalia" title="Regalia"><i class="fas fa-medal"></i></div>
            <div class="cp-hdr-status">Status</div>
            <div class="cp-hdr-chev"></div>
        </div>

        <?php if (empty($courtAwards)): ?>
        <div class="cp-award-empty" id="cp-award-empty">
            <i class="fas fa-award" style="font-size:28px;opacity:.3;margin-bottom:10px;display:block"></i>
            No awards planned yet. Add from recommendations or create an ad-hoc entry.
        </div>
        <?php else: ?>
        <?php $_rowIndex = 0; foreach ($courtAwards as $aw): $_rowIndex++; ?>
        <?php
            $ast  = $aw['Status'];
            $albl = $awardStatusLabel[$ast] ?? $ast;
            $aclr = $awardStatusColor[$ast] ?? '#4a5568';
            $abg  = $awardStatusBg[$ast]    ?? '#edf2f7';
            // Type badge
            if ($aw['IsTitle']) {
                $typeClass = 'cp-type-title';  $typeLabel = 'Title';
            } elseif ($aw['IsLadder']) {
                $typeClass = 'cp-type-ladder'; $typeLabel = 'Ladder';
            } else {
                $typeClass = 'cp-type-award';  $typeLabel = 'Award';
            }
        ?>
        <div class="cp-award-row<?= $ast === 'given' ? ' cp-granted' : ($ast === 'cancelled' ? ' cp-skipped' : '') ?> cp-aw-type-<?= $aw['IsTitle'] ? 'title' : ($aw['IsLadder'] ? 'ladder' : 'award') ?>"
             id="cp-aw-<?= (int)$aw['CourtAwardId'] ?>"
             data-court-award-id="<?= (int)$aw['CourtAwardId'] ?>"
             data-sort="<?= (int)$aw['SortOrder'] ?>">
            <div class="cp-award-row-main cp-row-grid" onclick="cpToggleAward(<?= (int)$aw['CourtAwardId'] ?>)">
                <div class="cp-cell cp-cell-order">
                    <div class="cp-reorder-btns">
                        <button class="cp-reorder-btn" title="Move up" onclick="event.stopPropagation();cpMoveAward(<?= (int)$aw['CourtAwardId'] ?>,-1)">&#9650;</button>
                        <button class="cp-reorder-btn" title="Move down" onclick="event.stopPropagation();cpMoveAward(<?= (int)$aw['CourtAwardId'] ?>,1)">&#9660;</button>
                    </div>
                </div>
                <div class="cp-cell cp-cell-num"><?= $_rowIndex ?></div>
                <div class="cp-cell cp-cell-recipient cp-award-name">
                    <span class="cp-recipient-name"><?= htmlspecialchars($aw['Persona']) ?></span>
                    <?php if (!empty($aw['ParkAbbrev'])): ?><span class="cp-award-park"><?= htmlspecialchars($aw['ParkAbbrev']) ?></span><?php endif; ?>
                    <?php if (!empty($aw['Notes'])): ?><button class="cp-note-btn" data-note="<?= htmlspecialchars($aw['Notes']) ?>" onclick="event.stopPropagation();cpShowNote(this)" title="View note"><i class="fas fa-comment-alt"></i></button><?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-award">
                    <span class="cp-award-name-text"><?= htmlspecialchars($aw['AwardName']) ?></span>
                    <?php if ($aw['IsLadder'] && $aw['Rank'] > 0): ?><span class="cp-award-rank">Rank <?= (int)$aw['Rank'] ?></span><?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-type">
                    <span class="<?= $typeClass ?>"><?= $typeLabel ?></span>
                </div>
                <div class="cp-cell cp-cell-flags cp-award-flags">
                    <?php if ($aw['PassToLocal']): ?><span class="cp-flag-local" title="Pass to Local"><i class="fas fa-arrow-down"></i></span><?php endif; ?>
                    <?php if ($aw['RecommendationsId']): ?><span class="cp-flag-rec" title="From Recommendation"><i class="fas fa-star"></i></span><?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-scroll">
                    <span class="cp-tracking-icon" title="Needs Scroll" data-type="scroll" data-status="<?= $aw['ScrollStatus'] ?>" onclick="cpUpdateTracking(event, <?= (int)$aw['CourtAwardId'] ?>, 'scroll', this)"><i class="fas fa-print"></i></span>
                </div>
                <div class="cp-cell cp-cell-regalia">
                    <span class="cp-tracking-icon" title="Needs Regalia" data-type="regalia" data-status="<?= $aw['RegaliaStatus'] ?>" onclick="cpUpdateTracking(event, <?= (int)$aw['CourtAwardId'] ?>, 'regalia', this)"><i class="fas fa-medal"></i></span>
                </div>
                <div class="cp-cell cp-cell-status">
                    <span class="cp-aw-badge" style="background:<?= $abg ?>;color:<?= $aclr ?>"><?= $albl ?></span>
                    <?php if ($courtSt === 'published' && !in_array($ast, ['given','cancelled'])): ?>
                    <div class="cp-grant-actions" onclick="event.stopPropagation()">
                        <button class="cp-btn-grant" onclick="cpGrantAward(<?= (int)$aw['CourtAwardId'] ?>)"><i class="fas fa-check"></i> Grant</button>
                        <button class="cp-btn-skip" onclick="cpSkipAward(<?= (int)$aw['CourtAwardId'] ?>)"><i class="fas fa-forward"></i> Skip</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-chevron"><i class="fas fa-chevron-down"></i></div>
            </div>
            <div class="cp-award-row-expand" id="cp-aw-expand-<?= (int)$aw['CourtAwardId'] ?>">
                <div class="cp-expand-grid">
                    <div>
                        <div class="cp-expand-label">Internal Notes</div>
                        <textarea class="cp-notes-area" id="cp-notes-<?= (int)$aw['CourtAwardId'] ?>"
                                  placeholder="Monarchy notes (not public)…"><?= htmlspecialchars($aw['Notes']) ?></textarea>
                        <div class="cp-expand-label" style="margin-top:10px">Public Comment</div>
                        <textarea class="cp-notes-area" id="cp-pubcomment-<?= (int)$aw['CourtAwardId'] ?>"
                                  placeholder="Shown on the public Court Report…"><?= htmlspecialchars($aw['PublicComment'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <div class="cp-expand-label">Pass to Local</div>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px">
                            <input type="checkbox" id="cp-ptl-<?= (int)$aw['CourtAwardId'] ?>"
                                   <?= $aw['PassToLocal'] ? 'checked' : '' ?>
                                   style="width:auto">
                            <span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span>
                        </label>
                        <div style="margin-top:14px">
                            <div class="cp-expand-label">Status</div>
                            <select id="cp-status-<?= (int)$aw['CourtAwardId'] ?>" style="width:auto;padding:5px 8px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px">
                                <?php foreach ($awardStatusLabel as $sv => $sl): ?>
                                <option value="<?= $sv ?>" <?= $ast === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Scroll / Regalia Makers -->
                <div class="cp-expand-grid" style="margin-top:8px">
                    <div>
                        <div class="cp-expand-label">Scroll Maker</div>
                        <div style="position:relative">
                            <input type="text" id="cp-scroll-maker-text-<?= (int)$aw['CourtAwardId'] ?>"
                                   class="cp-maker-ac" data-drop="cp-scroll-drop-<?= (int)$aw['CourtAwardId'] ?>" data-hidden="cp-scroll-maker-id-<?= (int)$aw['CourtAwardId'] ?>"
                                   placeholder="Search by persona…"
                                   value="<?= htmlspecialchars($aw['ScrollMakerPersona'] ?? '') ?>"
                                   autocomplete="off"
                                   style="width:100%;padding:5px 8px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px">
                            <input type="hidden" id="cp-scroll-maker-id-<?= (int)$aw['CourtAwardId'] ?>" value="<?= (int)($aw['ScrollMakerId'] ?? 0) ?>">
                            <div id="cp-scroll-drop-<?= (int)$aw['CourtAwardId'] ?>" class="cp-ac-dropdown" style="display:none;position:fixed;z-index:1000;background:#fff;border:1px solid #e2e8f0;border-radius:5px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:200px;overflow-y:auto"></div>
                        </div>
                    </div>
                    <div>
                        <div class="cp-expand-label">Regalia Maker</div>
                        <div style="position:relative">
                            <input type="text" id="cp-regalia-maker-text-<?= (int)$aw['CourtAwardId'] ?>"
                                   class="cp-maker-ac" data-drop="cp-regalia-drop-<?= (int)$aw['CourtAwardId'] ?>" data-hidden="cp-regalia-maker-id-<?= (int)$aw['CourtAwardId'] ?>"
                                   placeholder="Search by persona…"
                                   value="<?= htmlspecialchars($aw['RegaliaMakerPersona'] ?? '') ?>"
                                   autocomplete="off"
                                   style="width:100%;padding:5px 8px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px">
                            <input type="hidden" id="cp-regalia-maker-id-<?= (int)$aw['CourtAwardId'] ?>" value="<?= (int)($aw['RegaliaMakerId'] ?? 0) ?>">
                            <div id="cp-regalia-drop-<?= (int)$aw['CourtAwardId'] ?>" class="cp-ac-dropdown" style="display:none;position:fixed;z-index:1000;background:#fff;border:1px solid #e2e8f0;border-radius:5px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:200px;overflow-y:auto"></div>
                        </div>
                    </div>
                </div>

                <!-- Artisans -->
                <div style="margin-bottom:10px">
                    <div class="cp-expand-label" style="margin-bottom:6px">Contributing Artisans</div>
                    <div id="cp-artisans-<?= (int)$aw['CourtAwardId'] ?>">
                        <?php foreach ($aw['Artisans'] as $art): ?>
                        <div class="cp-artisan-row" id="cp-art-<?= (int)$art['CourtAwardArtisanId'] ?>">
                            <i class="fas fa-paint-brush" style="color:#9f7aea"></i>
                            <strong><?= htmlspecialchars($art['Persona']) ?></strong>
                            <?php if ($art['Contribution']): ?>
                            <span style="color:#718096">— <?= htmlspecialchars($art['Contribution']) ?></span>
                            <?php endif; ?>
                            <button class="cp-btn-danger-sm" title="Remove artisan"
                                    onclick="cpRemoveArtisan(<?= (int)$art['CourtAwardArtisanId'] ?>)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="cp-btn-sm cp-btn-outline" style="margin-top:6px"
                            onclick="cpOpenArtisanModal(<?= (int)$aw['CourtAwardId'] ?>)">
                        <i class="fas fa-plus"></i> Add Artisan
                    </button>
                </div>

                <div class="cp-expand-actions">
                    <button class="cp-btn-primary cp-btn-sm" onclick="cpSaveAward(<?= (int)$aw['CourtAwardId'] ?>)">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button class="cp-btn-sm cp-btn-danger-inline"
                            onclick="cpRemoveAward(<?= (int)$aw['CourtAwardId'] ?>)">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    </div><!-- /cp-main-content -->
</div><!-- /cp-body -->
</div><!-- /cp-page -->
<?php endif; // end error check ?>

<!-- Add from Recommendations Modal -->
<div class="cp-overlay" id="cp-rec-modal">
    <div class="cp-modal" style="max-width:660px">
        <div class="cp-modal-header">
            <h3><i class="fas fa-star" style="color:#d69e2e;margin-right:8px"></i>Add from Recommendations</h3>
            <button class="cp-modal-close" onclick="cpCloseRecModal()">&times;</button>
        </div>
        <div class="cp-modal-body" style="padding-bottom:8px">
            <div class="cp-rm-search-wrap">
                <i class="fas fa-search"></i>
                <input class="cp-rm-search" id="cp-rm-filter" type="text" placeholder="Filter by name or award…" oninput="cpRmFilter()" autocomplete="off">
            </div>
            <div class="cp-rm-controls">
                <span class="cp-rm-sort-label">View:</span>
                <button class="cp-rm-view-btn active" data-view="open"     onclick="cpRmView('open')"     title="Open recs ready to grant (hides already-qualified and snoozed)">Open</button>
                <button class="cp-rm-view-btn"        data-view="all"      onclick="cpRmView('all')"      title="Every eligible rec for this kingdom/park">All</button>
                <button class="cp-rm-view-btn"        data-view="snoozed"  onclick="cpRmView('snoozed')"  title="Recs the monarchy has set aside for this regnum">Snoozed</button>
                <button class="cp-rm-view-btn"        data-view="already"  onclick="cpRmView('already')"  title="Player already has this award at or above the recommended rank">Already Has</button>
            </div>
            <div class="cp-rm-controls">
                <span class="cp-rm-sort-label">Sort:</span>
                <button class="cp-rm-sort-btn active" id="cp-rm-s-az"   onclick="cpRmSort('az')"  >A → Z</button>
                <button class="cp-rm-sort-btn"        id="cp-rm-s-za"   onclick="cpRmSort('za')"  >Z → A</button>
                <button class="cp-rm-sort-btn"        id="cp-rm-s-old"  onclick="cpRmSort('old')" >Oldest First</button>
                <button class="cp-rm-sort-btn"        id="cp-rm-s-new"  onclick="cpRmSort('new')" >Newest First</button>
            </div>
            <div class="cp-rm-meta" id="cp-rm-meta"><?php
                $total      = count($pendingRecs);
                $alreadyQ   = count(array_filter($pendingRecs, fn($r) => !empty($r['AlreadyHas'])));
                $snoozedCt  = count(array_filter($pendingRecs, fn($r) => !empty($r['IsSnoozed'])));
                $open       = $total - $alreadyQ - $snoozedCt;
                echo '<strong>' . $open . '</strong> open'
                   . ' &nbsp;·&nbsp; ' . $alreadyQ . ' already has'
                   . ' &nbsp;·&nbsp; ' . $snoozedCt . ' snoozed';
            ?></div>
            <div class="cp-rm-list" id="cp-rec-list">
                <?php
                $avatarColors = ['#3182ce','#2f855a','#c05621','#6b46c1','#b7791f','#2c7a7b','#c53030','#276749','#553c9a','#2b6cb0'];
                foreach ($pendingRecs as $rec):
                    $alreadyHas    = !empty($rec['AlreadyHas']);
                    $isSnoozed     = !empty($rec['IsSnoozed']);
                    $isOnOther     = !empty($rec['IsOnOtherCourt']);
                    $coveredMaster = !empty($rec['CoveredByMaster']);
                    // Disable selection only when player already has the award (snoozed/other-court are still selectable).
                    $disabled      = $alreadyHas;
                    // Default-hidden if NOT in the "open" bucket (already-qualified or snoozed).
                    $defaultBucket = $alreadyHas ? 'already' : ($isSnoozed ? 'snoozed' : 'open');
                    $initial = mb_strtoupper(mb_substr($rec['Persona'], 0, 1));
                    $colorIdx = abs(crc32($rec['Persona'])) % count($avatarColors);
                    $avatarBg = $disabled ? '#a0aec0' : $avatarColors[$colorIdx];
                    $reason = $rec['Reason'] ? mb_substr($rec['Reason'], 0, 120) . (mb_strlen($rec['Reason']) > 120 ? '…' : '') : '';

                    // Age badge bucket (matches Kingdom Recs colors)
                    $d = (int)($rec['AgeDays'] ?? 0);
                    if      ($d < 1)   { $ageLbl = 'today'; $ageCls = 'cp-age-green'; }
                    elseif  ($d < 30)  { $ageLbl = $d . 'd';                            $ageCls = 'cp-age-green'; }
                    elseif  ($d < 90)  { $ageLbl = round($d/30) . 'mo';                 $ageCls = 'cp-age-yellow'; }
                    elseif  ($d < 180) { $ageLbl = round($d/30) . 'mo';                 $ageCls = 'cp-age-orange'; }
                    else               { $ageLbl = round($d/365) . 'y+';                $ageCls = 'cp-age-red'; }

                    // Already-qualified inline reason
                    if ($alreadyHas) {
                        if ($coveredMaster) {
                            $qualifiedTip = 'Covered by a Master peerage';
                        } elseif ((int)($rec['CurrentRank'] ?? 0) > 0) {
                            $qualifiedTip = 'Held at Rank ' . (int)$rec['CurrentRank']
                                . (!empty($rec['CurrentRankDate']) ? ' since ' . htmlspecialchars($rec['CurrentRankDate']) : '');
                        } else {
                            $qualifiedTip = 'Already granted';
                        }
                    }
                ?>
                <div class="cp-rm-row<?= $disabled ? ' already' : '' ?><?= $isSnoozed ? ' cp-rm-snoozed' : '' ?>"
                     id="cp-rec-<?= (int)$rec['RecommendationsId'] ?>"
                     data-rec-id="<?= (int)$rec['RecommendationsId'] ?>"
                     data-mundane-id="<?= (int)$rec['MundaneId'] ?>"
                     data-ka-id="<?= (int)$rec['KingdomAwardId'] ?>"
                     data-rank="<?= (int)$rec['Rank'] ?>"
                     data-persona="<?= htmlspecialchars($rec['Persona'], ENT_QUOTES) ?>"
                     data-award="<?= htmlspecialchars($rec['AwardName'], ENT_QUOTES) ?>"
                     data-date="<?= $rec['DateRecommended'] ? date('Y-m-d', strtotime($rec['DateRecommended'])) : '' ?>"
                     data-search="<?= htmlspecialchars(strtolower($rec['Persona'] . ' ' . $rec['AwardName']), ENT_QUOTES) ?>"
                     data-bucket="<?= $defaultBucket ?>"
                     data-already-has="<?= $alreadyHas ? '1' : '0' ?>"
                     data-snoozed="<?= $isSnoozed ? '1' : '0' ?>"
                     data-on-other-court="<?= $isOnOther ? '1' : '0' ?>"
                     onclick="<?= $disabled ? '' : 'cpToggleRec(this)' ?>">
                    <?php if (!$disabled): ?>
                    <button class="cp-rm-trash" data-tip="Already given out previously? No plans to award this? You can dismiss this rec." aria-label="Dismiss" onclick="event.stopPropagation();cpDismissRec(this,<?= (int)$rec['RecommendationsId'] ?>)"><i class="fas fa-trash-alt"></i></button>
                    <?php endif; ?>
                    <div class="cp-rm-avatar" style="background:<?= $avatarBg ?>"><?= htmlspecialchars($initial) ?></div>
                    <div class="cp-rm-main">
                        <div class="cp-rm-head">
                            <span class="cp-rm-persona"><?= htmlspecialchars($rec['Persona']) ?></span>
                            <?php if (!empty($rec['ParkAbbrev'])): ?><span class="cp-rm-park"><?= htmlspecialchars($rec['ParkAbbrev']) ?></span><?php endif; ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-award"><?= htmlspecialchars($rec['AwardName']) ?></span>
                            <?php if ($rec['IsLadder'] && $rec['Rank'] > 0): ?><span class="cp-rm-rank">Rank <?= (int)$rec['Rank'] ?></span><?php endif; ?>
                            <?php if ($rec['DateRecommended']): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-date"><?= date('M j, Y', strtotime($rec['DateRecommended'])) ?></span>
                            <span class="cp-rm-age-badge <?= $ageCls ?>" title="Age of this recommendation"><?= $ageLbl ?></span>
                            <?php endif; ?>
                            <?php if (!empty($rec['SecondsCount'])): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-seconds" title="<?= (int)$rec['SecondsCount'] ?> supporting <?= (int)$rec['SecondsCount'] === 1 ? 'second' : 'seconds' ?>"><i class="fas fa-thumbs-up"></i><?= (int)$rec['SecondsCount'] ?></span>
                            <?php endif; ?>
                            <?php if ($isOnOther): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-onother" title="This recommendation is on another court plan"><i class="fas fa-scroll"></i> On another court</span>
                            <?php endif; ?>
                            <?php if ($alreadyHas): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-qualified" title="<?= htmlspecialchars($qualifiedTip) ?>"><i class="fas fa-check-circle"></i> <?= $coveredMaster ? 'Covered by Master' : 'Already has' ?></span>
                            <?php endif; ?>
                            <?php if ($isSnoozed): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-snooze-chip" title="Snoozed for the current regnum"><i class="fas fa-bell-slash"></i> Snoozed</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($reason): ?>
                        <div class="cp-rm-reason"><?= htmlspecialchars($reason) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="cp-rm-right">
                        <?php if ($alreadyHas): ?>
                        <span class="cp-rm-in-plan"><i class="fas fa-check" style="margin-right:3px"></i>Already Has</span>
                        <?php else: ?>
                        <div class="cp-rm-check"><i class="fas fa-check"></i></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="cp-rm-empty" id="cp-rm-empty" style="display:none">No recommendations match your filter.</div>
            </div>
            <div class="cp-error" id="cp-rec-error" style="margin-top:10px"></div>
        </div>
        <div class="cp-modal-footer" style="align-items:center">
            <button class="cp-btn-outline" onclick="cpCloseRecModal()">Cancel</button>
            <span class="cp-rm-add-count" id="cp-rm-count" style="display:none"></span>
            <button class="cp-btn-primary" id="cp-rm-add-btn" onclick="cpSubmitRecs()">
                <i class="fas fa-plus"></i> <span id="cp-rm-add-label">Add Selected</span>
            </button>
        </div>
    </div>
</div>

<!-- Add Ad-hoc Award Modal -->
<div class="cp-overlay" id="cp-adhoc-modal">
    <div class="cp-modal cp-modal-sm">
        <div class="cp-modal-header">
            <h3><i class="fas fa-award" style="margin-right:8px;color:#4a5568"></i>Add Award</h3>
            <button class="cp-modal-close" onclick="cpCloseAdhocModal()">&times;</button>
        </div>
        <div class="cp-modal-body">
            <div class="cp-field">
                <label>Recipient <span style="color:#e53e3e">*</span></label>
                <div class="cp-ac-wrap">
                    <input type="text" id="cp-adhoc-persona" placeholder="Search player name…" autocomplete="off" oninput="cpAcSearch(this,'cp-adhoc-ac','cp-adhoc-mundane-id')">
                    <div class="cp-ac-dropdown" id="cp-adhoc-ac"></div>
                </div>
                <input type="hidden" id="cp-adhoc-mundane-id">
            </div>
            <div class="cp-field">
                <label>Award <span style="color:#e53e3e">*</span></label>
                <select id="cp-adhoc-award" onchange="cpAdhocAwardChange()">
                    <option value="">— Select award —</option>
                    <?php foreach ($awardOpts as $ao): ?>
                    <option value="<?= (int)$ao['KingdomAwardId'] ?>"
                            data-ladder="<?= $ao['IsLadder'] ? '1' : '0' ?>">
                        <?= htmlspecialchars($ao['AwardName']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cp-field" id="cp-adhoc-rank-wrap" style="display:none">
                <label>Rank</label>
                <input type="number" id="cp-adhoc-rank" min="1" max="99" value="1">
            </div>
            <div class="cp-field">
                <label>Internal Notes</label>
                <textarea id="cp-adhoc-notes" rows="3" placeholder="Monarchy notes (not public)…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
            <div class="cp-field">
                <label>Public Comment</label>
                <textarea id="cp-adhoc-pubcomment" rows="3" placeholder="Shown on the public Court Report…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#4a5568;margin-bottom:12px">
                <input type="checkbox" id="cp-adhoc-ptl" style="width:auto">
                Pass to Local (Kingdom approves, Park to give)
            </label>
            <div class="cp-error" id="cp-adhoc-error"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpCloseAdhocModal()">Cancel</button>
            <button class="cp-btn-primary" id="cp-adhoc-save" onclick="cpSubmitAdhoc()">
                <i class="fas fa-plus"></i> Add Award
            </button>
        </div>
    </div>
</div>

<!-- Add Artisan Modal -->
<div class="cp-overlay" id="cp-artisan-modal">
    <div class="cp-modal cp-modal-sm">
        <div class="cp-modal-header">
            <h3><i class="fas fa-paint-brush" style="color:#9f7aea;margin-right:8px"></i>Add Artisan</h3>
            <button class="cp-modal-close" onclick="cpCloseArtisanModal()">&times;</button>
        </div>
        <div class="cp-modal-body">
            <div class="cp-field">
                <label>Artisan <span style="color:#e53e3e">*</span></label>
                <div class="cp-ac-wrap">
                    <input type="text" id="cp-art-persona" placeholder="Search player name…" autocomplete="off" oninput="cpAcSearch(this,'cp-art-ac','cp-art-mundane-id')">
                    <div class="cp-ac-dropdown" id="cp-art-ac"></div>
                </div>
                <input type="hidden" id="cp-art-mundane-id">
            </div>
            <div class="cp-field">
                <label>Contribution</label>
                <input type="text" id="cp-art-contribution" placeholder="What they made or contributed…" autocomplete="off">
            </div>
            <div class="cp-error" id="cp-art-error"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpCloseArtisanModal()">Cancel</button>
            <button class="cp-btn-primary" onclick="cpSubmitArtisan()">
                <i class="fas fa-plus"></i> Add
            </button>
        </div>
    </div>
</div>

<div id="cp-note-popup" style="position:fixed">
    <div id="cp-note-popup-header">
        <span id="cp-note-popup-title">Monarchy Note</span>
        <button id="cp-note-popup-close" onclick="cpDismissNote()" title="Close">&times;</button>
    </div>
    <span id="cp-note-popup-text"></span>
</div>

<script>
(function() {
    var uir      = '<?= UIR ?>';
    var courtId     = <?= (int)($court['CourtId'] ?? 0) ?>;
    var kidId       = <?= (int)($court['KingdomId'] ?? 0) ?>;
    var courtStatus = <?= json_encode($court['Status'] ?? 'draft') ?>;
    var courtAwards = window.courtAwards = <?= json_encode($courtAwards) ?>;
    var courtMeta   = window.courtMeta   = { name: <?= json_encode($court['Name'] ?? '') ?>, date: <?= json_encode($court['CourtDate'] ?? '') ?> };
    var currentArtisanCourtAwardId = 0;

    // ---- Utilities ----
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    window.esc = esc;
    function gid(id) { return document.getElementById(id); }
    function post(url, fd) {
        return fetch(uir + url, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) { return r.json(); });
    }

    // ---- Court status ----
    window.cpAdvanceStatus = function(newStatus) {
        if (!confirm('Mark this court as "' + newStatus + '"?')) return;
        var fd = new FormData();
        fd.append('CourtId', courtId);
        fd.append('Status',  newStatus);
        post('CourtAjax/update_court_status', fd).then(function(d) {
            if (d.status === 0) location.reload();
            else alert(d.error || 'Could not update status.');
        });
    };

    window.cpReturnToPlanning = function(newStatus) {
        if (!confirm('Return this court to "' + newStatus + '" status?')) return;
        var fd = new FormData();
        fd.append('CourtId', courtId);
        fd.append('Status',  newStatus);
        post('CourtAjax/update_court_status', fd).then(function(d) {
            if (d.status === 0) location.reload();
            else alert(d.error || 'Could not update status.');
        });
    };

    // ---- Toggle award expand ----
    window.cpToggleAward = function(caid) {
        var el = gid('cp-aw-expand-' + caid);
        if (!el) return;
        el.classList.toggle('open');
        var chevron = el.previousElementSibling.querySelector('.fa-chevron-down,.fa-chevron-up');
        if (chevron) {
            chevron.classList.toggle('fa-chevron-down');
            chevron.classList.toggle('fa-chevron-up');
        }
    };

    // ---- Density toggle (Cozy / Comfortable / Compact) ----
    var CP_DENSITY_KEY     = 'cp.density';
    var CP_SIDEBAR_KEY     = 'cp.sidebarCollapsed';
    var CP_VALID_DENSITIES = ['cozy', 'comfortable', 'compact'];
    function cpReadDensity() {
        try {
            var v = localStorage.getItem(CP_DENSITY_KEY);
            return CP_VALID_DENSITIES.indexOf(v) >= 0 ? v : 'comfortable';
        } catch (e) { return 'comfortable'; }
    }
    window.cpSetDensity = function(d) {
        if (CP_VALID_DENSITIES.indexOf(d) < 0) d = 'comfortable';
        var list = gid('cp-award-list');
        if (!list) return;
        CP_VALID_DENSITIES.forEach(function(name) { list.classList.remove('cp-density-' + name); });
        list.classList.add('cp-density-' + d);
        // Toolbar segmented control state
        var seg = gid('cp-density-seg');
        if (seg) {
            seg.querySelectorAll('button').forEach(function(b) {
                b.classList.toggle('active', b.dataset.density === d);
            });
        }
        try { localStorage.setItem(CP_DENSITY_KEY, d); } catch (e) {}
    };

    // ---- Sidebar collapse ----
    window.cpToggleSidebar = function() {
        var body = gid('cp-body');
        if (!body) return;
        var collapsed = body.classList.toggle('cp-sidebar-collapsed');
        var btn = gid('cp-sidebar-collapse-btn');
        if (btn) {
            btn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            btn.setAttribute('aria-label', btn.title);
        }
        try { localStorage.setItem(CP_SIDEBAR_KEY, collapsed ? '1' : '0'); } catch (e) {}
    };
    function cpInitSidebar() {
        var collapsed = false;
        try { collapsed = localStorage.getItem(CP_SIDEBAR_KEY) === '1'; } catch (e) {}
        if (collapsed) {
            var body = gid('cp-body');
            if (body) body.classList.add('cp-sidebar-collapsed');
            var btn = gid('cp-sidebar-collapse-btn');
            if (btn) {
                btn.title = 'Expand sidebar';
                btn.setAttribute('aria-label', 'Expand sidebar');
            }
        }
    }

    // Renumber the # column after reorder / sort / add / remove
    window.cpRenumberRows = function() {
        var list = gid('cp-award-list');
        if (!list) return;
        var n = 0;
        list.querySelectorAll('.cp-award-row').forEach(function(row) {
            n++;
            var cell = row.querySelector('.cp-cell-num');
            if (cell) cell.textContent = n;
        });
        var ttCount = gid('cp-list-toolbar-count');
        if (ttCount) ttCount.textContent = n + ' award' + (n === 1 ? '' : 's');
    };

    // Apply density immediately so the page paints with the saved choice
    cpSetDensity(cpReadDensity());
    cpInitSidebar();

    // ---- Reorder ----
    window.cpMoveAward = function(caid, dir) {
        var list  = gid('cp-award-list');
        var rows  = Array.from(list.querySelectorAll('.cp-award-row'));
        var idx   = rows.findIndex(function(r) { return parseInt(r.dataset.courtAwardId, 10) === caid; });
        var swap  = idx + dir;
        if (swap < 0 || swap >= rows.length) return;
        if (dir === -1) rows[swap].before(rows[idx]);
        else            rows[idx].before(rows[swap]);
        cpSaveOrder();
        cpRenumberRows();
    };

    window.cpSortByOrders = function() {
        const awardsByMundane = {};
        courtAwards.forEach(aw => {
            if (!awardsByMundane[aw.MundaneId]) {
                awardsByMundane[aw.MundaneId] = { awards: [], maxRank: 0 };
            }
            awardsByMundane[aw.MundaneId].awards.push(aw);
            if (aw.Rank > awardsByMundane[aw.MundaneId].maxRank) {
                awardsByMundane[aw.MundaneId].maxRank = aw.Rank;
            }
        });

        const sortedMundanes = Object.values(awardsByMundane).sort((a, b) => a.maxRank - b.maxRank);

        const sortedAwardIds = sortedMundanes.flatMap(mundane => {
            const sortedAwards = mundane.awards.sort((a, b) => a.Rank - b.Rank);
            return sortedAwards.map(aw => aw.CourtAwardId);
        });

        const list = gid('cp-award-list');
        sortedAwardIds.forEach(caid => {
            const row = gid('cp-aw-' + caid);
            if (row) {
                list.appendChild(row);
            }
        });
        cpSaveOrder();
        cpRenumberRows();
    }

    window.cpSortTitlesLast = function() {
        const awardsByMundane = {};
        courtAwards.forEach(aw => {
            if (!awardsByMundane[aw.MundaneId]) {
                awardsByMundane[aw.MundaneId] = { awards: [], isGettingTitle: false };
            }
            awardsByMundane[aw.MundaneId].awards.push(aw);
            if (aw.IsTitle) {
                awardsByMundane[aw.MundaneId].isGettingTitle = true;
            }
        });

        const withTitles = [];
        const withoutTitles = [];
        Object.values(awardsByMundane).forEach(mundane => {
            if (mundane.isGettingTitle) {
                withTitles.push(mundane);
            } else {
                withoutTitles.push(mundane);
            }
        });

        const sortedAwardIds = [
            ...withoutTitles.flatMap(m => m.awards.map(aw => aw.CourtAwardId)),
            ...withTitles.flatMap(m => m.awards.map(aw => aw.CourtAwardId))
        ];

        const list = gid('cp-award-list');
        sortedAwardIds.forEach(caid => {
            const row = gid('cp-aw-' + caid);
            if (row) {
                list.appendChild(row);
            }
        });
        cpSaveOrder();
        cpRenumberRows();
    }

    // ---- Printing List toggle ----
    var cpPrintingListActive = false;
    var cpPrintingListSavedOrder = null;

    window.cpTogglePrintingList = function() {
        var list = gid('cp-award-list');
        var btn  = gid('cp-printing-list-btn');
        cpPrintingListActive = !cpPrintingListActive;
        if (btn) btn.classList.toggle('active', cpPrintingListActive);

        if (cpPrintingListActive) {
            // Snapshot current DOM order so we can restore it
            cpPrintingListSavedOrder = Array.from(list.querySelectorAll('.cp-award-row'))
                .map(function(r) { return parseInt(r.dataset.courtAwardId, 10); });

            // Get all rows with their scroll status
            var rows = Array.from(list.querySelectorAll('.cp-award-row'));
            var withScroll = [], noScroll = [];
            rows.forEach(function(row) {
                var icon = row.querySelector('.cp-tracking-icon[data-type="scroll"]');
                var st = icon ? parseInt(icon.dataset.status, 10) : 0;
                if (st === 1) withScroll.unshift(row);       // red — needs printing → top
                else if (st === 2) withScroll.push(row);     // green — done → after red
                else noScroll.push(row);                     // gray — not tracked
            });

            // Show only scroll-tracked rows, sorted red then green
            rows.forEach(function(r) { r.style.display = 'none'; });
            withScroll.forEach(function(r) { r.style.display = ''; list.appendChild(r); });

            // If nothing to show, show a hint inside the list
            if (withScroll.length === 0) {
                var hint = document.createElement('div');
                hint.className = 'cp-award-empty';
                hint.id = 'cp-print-empty';
                hint.innerHTML = '<i class="fas fa-print" style="font-size:24px;opacity:.3;margin-bottom:8px;display:block"></i>No awards have scroll tracking set. Click the <i class="fas fa-print"></i> icon on any row to mark it.';
                list.appendChild(hint);
            }
        } else {
            // Remove hint if present
            var hint = gid('cp-print-empty');
            if (hint) hint.remove();

            // Restore all rows visible, in saved order
            var rows = Array.from(list.querySelectorAll('.cp-award-row'));
            rows.forEach(function(r) { r.style.display = ''; });
            if (cpPrintingListSavedOrder) {
                cpPrintingListSavedOrder.forEach(function(caid) {
                    var row = gid('cp-aw-' + caid);
                    if (row) list.appendChild(row);
                });
            }
            cpPrintingListSavedOrder = null;
        }
    };

    window.cpUpdateTracking = function(event, caid, type, element) {
        event.stopPropagation();
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        fd.append('Type', type);
        
        post('CourtAjax/update_award_tracking_status', fd).then(function(d) {
            if (d.status === 0) {
                element.dataset.status = d.newStatus;
                const award = courtAwards.find(a => a.CourtAwardId === caid);
                if (award) {
                    if (type === 'scroll') award.ScrollStatus = d.newStatus;
                    else award.RegaliaStatus = d.newStatus;
                }
                cpRefreshStatusBar();
            } else {
                alert(d.error || 'Could not update tracking status.');
            }
        });
    };

    // Recompute and update the status bar counts from current DOM state
    function cpRefreshStatusBar() {
        var scroll  = {0:0, 1:0, 2:0};
        var regalia = {0:0, 1:0, 2:0};
        document.querySelectorAll('#cp-award-list .cp-award-row').forEach(function(row) {
            var si = row.querySelector('.cp-tracking-icon[data-type="scroll"]');
            var ri = row.querySelector('.cp-tracking-icon[data-type="regalia"]');
            if (si) { var s = parseInt(si.dataset.status,10)||0; scroll[Math.min(2,Math.max(0,s))]++; }
            if (ri) { var r = parseInt(ri.dataset.status,10)||0; regalia[Math.min(2,Math.max(0,r))]++; }
        });
        var bar = document.querySelector('.cp-status-bar');
        if (!bar) return;
        // Update scroll text (second span[class] child)
        var scrollSpan = bar.querySelector('.cp-sb-scroll');
        if (scrollSpan) {
            scrollSpan.innerHTML = 'Scrolls: <span class="cp-stat-ready">'+scroll[2]+' ready</span>, <span class="cp-stat-wip">'+scroll[1]+' in progress</span>, <span class="cp-stat-none">'+scroll[0]+' not started</span>';
        }
        var regaliaSpan = bar.querySelector('.cp-sb-regalia');
        if (regaliaSpan) {
            regaliaSpan.innerHTML = 'Regalia: <span class="cp-stat-ready">'+regalia[2]+' ready</span>, <span class="cp-stat-wip">'+regalia[1]+' in progress</span>, <span class="cp-stat-none">'+regalia[0]+' not started</span>';
        }
    }

    function cpRefreshProgress() {
        var prog = document.getElementById('cp-sb-progress');
        if (!prog) return;
        var rows = document.querySelectorAll('#cp-award-list .cp-award-row');
        var granted = 0, skipped = 0, total = 0;
        rows.forEach(function(row) {
            total++;
            if (row.classList.contains('cp-granted')) granted++;
            else if (row.classList.contains('cp-skipped')) skipped++;
        });
        var remaining = total - granted - skipped;
        prog.innerHTML =
            '<span style="color:#276749;font-weight:600"><i class="fas fa-check-circle"></i> ' + granted + ' granted</span>' +
            (skipped > 0 ? ' &nbsp;<span style="color:#718096">' + skipped + ' skipped</span>' : '') +
            ' &nbsp;<span style="color:#4a5568">' + remaining + ' remaining</span>';
    }
    // Initialise progress counter on page load
    if (document.querySelector('#cp-sb-progress')) cpRefreshProgress();

    function cpSaveOrder() {
        var rows  = Array.from(document.querySelectorAll('#cp-award-list .cp-award-row'));
        var order = rows.map(function(r) { return parseInt(r.dataset.courtAwardId, 10); });
        var fd    = new FormData();
        fd.append('CourtId', courtId);
        fd.append('Order',   JSON.stringify(order));
        post('CourtAjax/reorder_awards', fd);
    }

    // ---- Save award (notes, pass_to_local, status) ----
    window.cpSaveAward = function(caid) {
        var notes          = gid('cp-notes-' + caid).value;
        var pubCommentEl    = gid('cp-pubcomment-' + caid);
        var publicComment   = pubCommentEl ? pubCommentEl.value : '';
        var ptl            = gid('cp-ptl-' + caid).checked ? 1 : 0;
        var status         = gid('cp-status-' + caid).value;
        var scrollMakerEl  = gid('cp-scroll-maker-id-'  + caid);
        var regaliaMakerEl = gid('cp-regalia-maker-id-' + caid);
        var fd     = new FormData();
        fd.append('CourtAwardId',  caid);
        fd.append('Notes',         notes);
        fd.append('PublicComment', publicComment);
        fd.append('PassToLocal',   ptl);
        fd.append('Status',        status);
        fd.append('ScrollMakerId',  scrollMakerEl  ? (parseInt(scrollMakerEl.value,  10) || 0) : 0);
        fd.append('RegaliaMakerId', regaliaMakerEl ? (parseInt(regaliaMakerEl.value, 10) || 0) : 0);
        post('CourtAjax/update_award', fd).then(function(d) {
            if (d.status === 0) {
                // Update Pass-to-Local badge in row header
                var flagsEl = document.querySelector('#cp-aw-' + caid + ' .cp-award-flags');
                if (flagsEl) {
                    var existing = flagsEl.querySelector('.cp-flag-local');
                    if (ptl && !existing) {
                        var span = document.createElement('span');
                        span.className = 'cp-flag-local';
                        span.title = 'Pass to Local';
                        span.innerHTML = '<i class="fas fa-arrow-down"></i>';
                        flagsEl.insertBefore(span, flagsEl.firstChild);
                    } else if (!ptl && existing) {
                        existing.remove();
                    }
                }
                // Update note bubble
                var nameEl = document.querySelector('#cp-aw-' + caid + ' .cp-award-name');
                if (nameEl) {
                    var existingNoteBtn = nameEl.querySelector('.cp-note-btn');
                    if (notes) {
                        if (!existingNoteBtn) {
                            var nb = document.createElement('button');
                            nb.className = 'cp-note-btn';
                            nb.title = 'View note';
                            nb.innerHTML = '<i class="fas fa-comment-alt"></i>';
                            nb.dataset.note = notes;
                            nb.addEventListener('click', function(e) { e.stopPropagation(); cpShowNote(this); });
                            nameEl.appendChild(nb);
                        } else {
                            existingNoteBtn.dataset.note = notes;
                        }
                    } else if (existingNoteBtn) {
                        existingNoteBtn.remove();
                    }
                }
                // Flash save confirmation
                var btn = document.querySelector('#cp-aw-expand-' + caid + ' .cp-expand-actions .cp-btn-primary');
                if (btn) { var orig = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i> Saved'; setTimeout(function() { btn.innerHTML = orig; }, 1500); }
            } else {
                alert(d.error || 'Could not save.');
            }
        });
    };

    // ---- Grant / Skip (published mode) ----
    window.cpGrantAward = function(caid) {
        var row = gid('cp-aw-' + caid);
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        post('CourtAjax/grant_award', fd).then(function(d) {
            if (d.status === 0) {
                if (row) {
                    row.classList.add('cp-granted');
                    // Update status badge and swap buttons to "Given" indicator
                    var badgeEl = row.querySelector('.cp-aw-badge');
                    if (badgeEl) { badgeEl.style.background = '#f0fff4'; badgeEl.style.color = '#276749'; badgeEl.textContent = 'Given'; }
                    var actionsEl = row.querySelector('.cp-grant-actions');
                    if (actionsEl) actionsEl.innerHTML = '<span style="font-size:12px;color:#276749;font-weight:700"><i class="fas fa-check-circle"></i> Granted</span>';
                }
                var ga = courtAwards.find(function(a){ return String(a.CourtAwardId) === String(caid); });
                if (ga) ga.Status = 'given';
                cpRefreshProgress();
            } else {
                alert(d.error || 'Could not grant award.');
            }
        });
    };

    window.cpSkipAward = function(caid) {
        var row = gid('cp-aw-' + caid);
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        post('CourtAjax/skip_award', fd).then(function(d) {
            if (d.status === 0) {
                if (row) {
                    row.classList.add('cp-skipped');
                    var badgeEl = row.querySelector('.cp-aw-badge');
                    if (badgeEl) { badgeEl.style.background = '#fff5f5'; badgeEl.style.color = '#c53030'; badgeEl.textContent = 'Skipped'; }
                    var actionsEl = row.querySelector('.cp-grant-actions');
                    if (actionsEl) actionsEl.innerHTML = '<span style="font-size:12px;color:#a0aec0;font-weight:700"><i class="fas fa-forward"></i> Skipped</span>';
                }
                var sa = courtAwards.find(function(a){ return String(a.CourtAwardId) === String(caid); });
                if (sa) sa.Status = 'cancelled';
                cpRefreshProgress();
            } else {
                alert(d.error || 'Could not skip award.');
            }
        });
    };

    // ---- Remove award ----
    window.cpRemoveAward = function(caid) {
        if (!confirm('Remove this award from the court plan?')) return;
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        post('CourtAjax/remove_award', fd).then(function(d) {
            if (d.status === 0) {
                var row = gid('cp-aw-' + caid);
                if (row) row.remove();
                var remaining = document.querySelectorAll('#cp-award-list .cp-award-row').length;
                if (remaining === 0 && !gid('cp-award-empty')) {
                    var list = gid('cp-award-list');
                    var empty = document.createElement('div');
                    empty.className = 'cp-award-empty';
                    empty.id = 'cp-award-empty';
                    empty.innerHTML = '<i class="fas fa-award" style="font-size:28px;opacity:.3;margin-bottom:10px;display:block"></i>No awards planned yet.';
                    list.appendChild(empty);
                }
                var cnt = gid('cp-award-count');
                if (cnt) cnt.textContent = '(' + remaining + ')';
                cpRenumberRows();
            } else {
                alert(d.error || 'Could not remove.');
            }
        });
    };

    // ---- Rec modal ----
    var selectedRecs = [];

    function cpRmUpdateCount() {
        var n = selectedRecs.length;
        var countEl = gid('cp-rm-count');
        var labelEl = gid('cp-rm-add-label');
        if (n > 0) {
            if (countEl) { countEl.textContent = n + ' selected'; countEl.style.display = ''; }
            if (labelEl) labelEl.textContent = 'Add ' + n;
        } else {
            if (countEl) countEl.style.display = 'none';
            if (labelEl) labelEl.textContent = 'Add Selected';
        }
    }

    var cpRmCurrentSort = 'az';

    window.cpRmSort = function(mode) {
        cpRmCurrentSort = mode;
        // Update button states
        ['az','za','old','new'].forEach(function(m) {
            var btn = gid('cp-rm-s-' + m);
            if (btn) btn.classList.toggle('active', m === mode);
        });
        var list = gid('cp-rec-list');
        if (!list) return;
        var rows = Array.from(list.querySelectorAll('.cp-rm-row'));
        rows.sort(function(a, b) {
            if (mode === 'az' || mode === 'za') {
                var pa = (a.dataset.persona || '').toLowerCase();
                var pb = (b.dataset.persona || '').toLowerCase();
                return mode === 'az' ? pa.localeCompare(pb) : pb.localeCompare(pa);
            } else {
                var da = a.dataset.date || '';
                var db = b.dataset.date || '';
                if (!da && !db) return 0;
                if (!da) return 1;
                if (!db) return -1;
                return mode === 'old' ? da.localeCompare(db) : db.localeCompare(da);
            }
        });
        var empty = gid('cp-rm-empty');
        rows.forEach(function(row) { list.insertBefore(row, empty); });
    };

    var cpRmCurrentView = 'open';

    function cpRmViewMatch(row, view) {
        var already = row.dataset.alreadyHas === '1';
        var snoozed = row.dataset.snoozed     === '1';
        switch (view) {
            case 'open':    return !already && !snoozed;
            case 'snoozed': return snoozed;
            case 'already': return already;
            case 'all':     return true;
        }
        return true;
    }

    window.cpRmView = function(view) {
        cpRmCurrentView = view;
        document.querySelectorAll('.cp-rm-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === view);
        });
        cpRmFilter();
    };

    window.cpRmFilter = function() {
        var q = (gid('cp-rm-filter').value || '').toLowerCase().trim();
        var rows = document.querySelectorAll('#cp-rec-list .cp-rm-row');
        var visible = 0;
        rows.forEach(function(row) {
            var inView = cpRmViewMatch(row, cpRmCurrentView);
            var match  = inView && (!q || (row.dataset.search || '').indexOf(q) !== -1);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        var empty = gid('cp-rm-empty');
        if (empty) {
            if (visible === 0) {
                empty.textContent = q ? 'No recommendations match your filter.'
                                     : (cpRmCurrentView === 'open'    ? 'No open recommendations. Try a different view.'
                                     :  cpRmCurrentView === 'snoozed' ? 'Nothing snoozed.'
                                     :  cpRmCurrentView === 'already' ? 'No one already has this award.'
                                     :  'No recommendations.');
                empty.style.display = '';
            } else {
                empty.style.display = 'none';
            }
        }
    };

    window.cpOpenRecModal = function() {
        selectedRecs = [];
        document.querySelectorAll('.cp-rm-row:not(.already)').forEach(function(el) { el.classList.remove('selected'); });
        var fi = gid('cp-rm-filter'); if (fi) { fi.value = ''; }
        cpRmCurrentView = 'open';
        document.querySelectorAll('.cp-rm-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === 'open');
        });
        cpRmSort(cpRmCurrentSort);
        cpRmFilter();
        cpRmUpdateCount();
        gid('cp-rec-error').style.display = 'none';
        gid('cp-rec-modal').style.display = 'flex';
        setTimeout(function() { var fi = gid('cp-rm-filter'); if (fi) fi.focus(); }, 50);
    };
    window.cpCloseRecModal = function() { gid('cp-rec-modal').style.display = 'none'; };

    window.cpDismissRec = function(btn, recId) {
        var row = document.getElementById('cp-rec-' + recId);
        function doDismiss() {
            var fd = new FormData();
            fd.append('RecommendationsId', recId);
            fetch(uir + 'KingdomAjax/kingdom/' + kidId + '/dismissrecommendation', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.status === 0) {
                        if (row) {
                            row.classList.add('dismissing');
                            setTimeout(function() { row.remove(); cpRmFilter(); }, 320);
                        }
                    } else {
                        alert(d.error || 'Failed to dismiss recommendation.');
                    }
                });
        }
        // Prefer the in-product confirm modal (project convention); fall back to native
        // confirm only if tnConfirm isn't loaded on this page.
        if (typeof tnConfirm === 'function') {
            tnConfirm({ title: 'Dismiss recommendation?', body: 'Already given out previously? No plans to award this? You can dismiss this rec.', confirmLabel: 'Dismiss', danger: true, onConfirm: doDismiss });
        } else if (confirm('Dismiss this recommendation? This cannot be undone.')) {
            doDismiss();
        }
    };

    window.cpToggleRec = function(el) {
        var rid = parseInt(el.dataset.recId, 10);
        var idx = selectedRecs.indexOf(rid);
        if (idx === -1) { selectedRecs.push(rid); el.classList.add('selected'); }
        else { selectedRecs.splice(idx, 1); el.classList.remove('selected'); }
        cpRmUpdateCount();
    };

    window.cpSubmitRecs = function() {
        if (selectedRecs.length === 0) {
            gid('cp-rec-error').textContent = 'Select at least one recommendation.';
            gid('cp-rec-error').style.display = 'block';
            return;
        }
        gid('cp-rec-error').style.display = 'none';
        var promises = selectedRecs.map(function(rid) {
            var el = gid('cp-rec-' + rid);
            var fd = new FormData();
            fd.append('CourtId',           courtId);
            fd.append('MundaneId',         el.dataset.mundaneId);
            fd.append('KingdomAwardId',    el.dataset.kaId);
            fd.append('Rank',              el.dataset.rank);
            fd.append('RecommendationsId', rid);
            fd.append('PassToLocal',       0);
            fd.append('Notes',             '');
            return post('CourtAjax/add_award', fd);
        });
        Promise.all(promises).then(function(results) {
            var added = results.filter(function(d) { return d.status === 0; });
            if (added.length > 0) {
                added.forEach(function(d) { cpAppendAwardRow(d.award); });
                cpCloseRecModal();
                // Mark as already planned in modal
                selectedRecs.forEach(function(rid) {
                    var el = gid('cp-rec-' + rid);
                    if (el) {
                        el.classList.add('already');
                        el.classList.remove('selected');
                        el.setAttribute('onclick','');
                        var checkEl = el.querySelector('.cp-rm-check');
                        if (checkEl) checkEl.style.display = 'none';
                        var rightEl = el.querySelector('.cp-rm-right');
                        if (rightEl) rightEl.innerHTML = '<span class="cp-rm-in-plan"><i class="fas fa-check" style="margin-right:3px"></i>In Plan</span>';
                    }
                });
                selectedRecs = [];
                cpRmUpdateCount();
            }
            var failed = results.filter(function(d) { return d.status !== 0; });
            if (failed.length) {
                gid('cp-rec-error').textContent = failed[0].error || 'Some awards could not be added.';
                gid('cp-rec-error').style.display = 'block';
            }
        });
    };

    // ---- Ad-hoc award modal ----
    window.cpOpenAdhocModal = function() {
        gid('cp-adhoc-persona').value = '';
        gid('cp-adhoc-mundane-id').value = '';
        gid('cp-adhoc-award').value  = '';
        gid('cp-adhoc-rank').value   = '1';
        gid('cp-adhoc-notes').value  = '';
        gid('cp-adhoc-pubcomment').value = '';
        gid('cp-adhoc-ptl').checked  = false;
        gid('cp-adhoc-rank-wrap').style.display = 'none';
        gid('cp-adhoc-error').style.display     = 'none';
        gid('cp-adhoc-modal').style.display     = 'flex';
        setTimeout(function() { gid('cp-adhoc-persona').focus(); }, 50);
    };
    window.cpCloseAdhocModal = function() { gid('cp-adhoc-modal').style.display = 'none'; };

    window.cpAdhocAwardChange = function() {
        var sel  = gid('cp-adhoc-award');
        var opt  = sel.options[sel.selectedIndex];
        var wrap = gid('cp-adhoc-rank-wrap');
        if (opt && opt.dataset.ladder === '1') wrap.style.display = '';
        else { wrap.style.display = 'none'; gid('cp-adhoc-rank').value = '0'; }
    };

    window.cpSubmitAdhoc = function() {
        var mundaneId = gid('cp-adhoc-mundane-id').value;
        var kaId      = gid('cp-adhoc-award').value;
        var rank      = gid('cp-adhoc-rank-wrap').style.display !== 'none' ? parseInt(gid('cp-adhoc-rank').value, 10) : 0;
        var notes     = gid('cp-adhoc-notes').value.trim();
        var pubComment = gid('cp-adhoc-pubcomment').value.trim();
        var ptl       = gid('cp-adhoc-ptl').checked ? 1 : 0;
        var errEl     = gid('cp-adhoc-error');

        if (!mundaneId) { errEl.textContent = 'Please select a recipient.'; errEl.style.display = 'block'; return; }
        if (!kaId)      { errEl.textContent = 'Please select an award.';    errEl.style.display = 'block'; return; }
        errEl.style.display = 'none';

        var fd = new FormData();
        fd.append('CourtId',        courtId);
        fd.append('MundaneId',      mundaneId);
        fd.append('KingdomAwardId', kaId);
        fd.append('Rank',           rank);
        fd.append('PassToLocal',    ptl);
        fd.append('Notes',          notes);
        fd.append('PublicComment',  pubComment);

        var btn = gid('cp-adhoc-save');
        btn.disabled = true;
        post('CourtAjax/add_award', fd).then(function(d) {
            btn.disabled = false;
            if (d.status === 0) {
                cpAppendAwardRow(d.award);
                cpCloseAdhocModal();
            } else {
                errEl.textContent = d.error || 'Could not add award.';
                errEl.style.display = 'block';
            }
        }).catch(function(e) { btn.disabled = false; errEl.textContent = 'Request failed.'; errEl.style.display = 'block'; });
    };

    // ---- Append a new award row to the list ----
    function cpAppendAwardRow(aw) {
        var empty = gid('cp-award-empty');
        if (empty) empty.remove();
        var ptlBadge = aw.PassToLocal ? '<span class="cp-flag-local" title="Pass to Local"><i class="fas fa-arrow-down"></i></span>' : '';
        var recBadge = aw.RecommendationsId ? '<span class="cp-flag-rec" title="From Recommendation"><i class="fas fa-star"></i></span>' : '';
        var rankStr  = (aw.IsLadder && aw.Rank > 0) ? '<span class="cp-award-rank">Rank ' + aw.Rank + '</span>' : '';
        var noteBtn  = aw.Notes ? '<button class="cp-note-btn" data-note="' + esc(aw.Notes) + '" onclick="event.stopPropagation();cpShowNote(this)" title="View note"><i class="fas fa-comment-alt"></i></button>' : '';
        var typeClass = aw.IsTitle ? 'cp-type-title' : (aw.IsLadder ? 'cp-type-ladder' : 'cp-type-award');
        var typeLabel = aw.IsTitle ? 'Title' : (aw.IsLadder ? 'Ladder' : 'Award');
        var typeRow   = aw.IsTitle ? 'title'  : (aw.IsLadder ? 'ladder' : 'award');
        var html = '<div class="cp-award-row cp-aw-type-' + typeRow + '" id="cp-aw-' + aw.CourtAwardId + '" data-court-award-id="' + aw.CourtAwardId + '" data-sort="' + aw.SortOrder + '">' +
            '<div class="cp-award-row-main cp-row-grid" onclick="cpToggleAward(' + aw.CourtAwardId + ')">' +
            '<div class="cp-cell cp-cell-order"><div class="cp-reorder-btns">' +
            '<button class="cp-reorder-btn" onclick="event.stopPropagation();cpMoveAward(' + aw.CourtAwardId + ',-1)">&#9650;</button>' +
            '<button class="cp-reorder-btn" onclick="event.stopPropagation();cpMoveAward(' + aw.CourtAwardId + ',1)">&#9660;</button>' +
            '</div></div>' +
            '<div class="cp-cell cp-cell-num"></div>' +
            '<div class="cp-cell cp-cell-recipient cp-award-name">' +
                '<span class="cp-recipient-name">' + esc(aw.Persona) + '</span>' +
                (aw.ParkAbbrev ? '<span class="cp-award-park">' + esc(aw.ParkAbbrev) + '</span>' : '') +
                noteBtn +
            '</div>' +
            '<div class="cp-cell cp-cell-award">' +
                '<span class="cp-award-name-text">' + esc(aw.AwardName) + '</span>' + rankStr +
            '</div>' +
            '<div class="cp-cell cp-cell-type"><span class="' + typeClass + '">' + typeLabel + '</span></div>' +
            '<div class="cp-cell cp-cell-flags cp-award-flags">' + ptlBadge + recBadge + '</div>' +
            '<div class="cp-cell cp-cell-scroll"><span class="cp-tracking-icon" title="Needs Scroll" data-type="scroll" data-status="' + aw.ScrollStatus + '" onclick="cpUpdateTracking(event, ' + aw.CourtAwardId + ', \'scroll\', this)"><i class="fas fa-print"></i></span></div>' +
            '<div class="cp-cell cp-cell-regalia"><span class="cp-tracking-icon" title="Needs Regalia" data-type="regalia" data-status="' + aw.RegaliaStatus + '" onclick="cpUpdateTracking(event, ' + aw.CourtAwardId + ', \'regalia\', this)"><i class="fas fa-medal"></i></span></div>' +
            '<div class="cp-cell cp-cell-status"><span class="cp-aw-badge" style="background:#edf2f7;color:#4a5568">Planned</span></div>' +
            '<div class="cp-cell cp-cell-chevron"><i class="fas fa-chevron-down"></i></div>' +
            '</div>' +
            '<div class="cp-award-row-expand" id="cp-aw-expand-' + aw.CourtAwardId + '">' +
            '<div class="cp-expand-grid">' +
            '<div><div class="cp-expand-label">Internal Notes</div><textarea class="cp-notes-area" id="cp-notes-' + aw.CourtAwardId + '" placeholder="Monarchy notes…">' + esc(aw.Notes || '') + '</textarea><div class="cp-expand-label" style="margin-top:10px">Public Comment</div><textarea class="cp-notes-area" id="cp-pubcomment-' + aw.CourtAwardId + '" placeholder="Shown on the public Court Report…">' + esc(aw.PublicComment || '') + '</textarea></div>' +
            '<div><div class="cp-expand-label">Pass to Local</div><label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px"><input type="checkbox" id="cp-ptl-' + aw.CourtAwardId + '" style="width:auto"' + (aw.PassToLocal ? ' checked' : '') + '><span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span></label>' +
            '<div style="margin-top:14px"><div class="cp-expand-label">Status</div><select id="cp-status-' + aw.CourtAwardId + '" style="width:auto;padding:5px 8px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px"><option value="planned" selected>Planned</option><option value="announced">Announced</option><option value="given">Given</option><option value="cancelled">Cancelled</option></select></div></div>' +
            '</div>' +
            '<div class="cp-expand-grid" style="margin-top:8px">' +
            '<div><div class="cp-expand-label">Scroll Maker</div><div style="position:relative"><input type="text" id="cp-scroll-maker-text-' + aw.CourtAwardId + '" class="cp-maker-ac" data-drop="cp-scroll-drop-' + aw.CourtAwardId + '" data-hidden="cp-scroll-maker-id-' + aw.CourtAwardId + '" placeholder="Search by persona…" autocomplete="off" style="width:100%;padding:5px 8px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px"><input type="hidden" id="cp-scroll-maker-id-' + aw.CourtAwardId + '" value="0"><div id="cp-scroll-drop-' + aw.CourtAwardId + '" class="cp-ac-dropdown" style="display:none;position:fixed;z-index:1000;background:#fff;border:1px solid #e2e8f0;border-radius:5px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:200px;overflow-y:auto"></div></div></div>' +
            '<div><div class="cp-expand-label">Regalia Maker</div><div style="position:relative"><input type="text" id="cp-regalia-maker-text-' + aw.CourtAwardId + '" class="cp-maker-ac" data-drop="cp-regalia-drop-' + aw.CourtAwardId + '" data-hidden="cp-regalia-maker-id-' + aw.CourtAwardId + '" placeholder="Search by persona…" autocomplete="off" style="width:100%;padding:5px 8px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px"><input type="hidden" id="cp-regalia-maker-id-' + aw.CourtAwardId + '" value="0"><div id="cp-regalia-drop-' + aw.CourtAwardId + '" class="cp-ac-dropdown" style="display:none;position:fixed;z-index:1000;background:#fff;border:1px solid #e2e8f0;border-radius:5px;box-shadow:0 4px 12px rgba(0,0,0,.12);max-height:200px;overflow-y:auto"></div></div></div>' +
            '</div>' +
            '<div style="margin-bottom:10px"><div class="cp-expand-label" style="margin-bottom:6px">Contributing Artisans</div><div id="cp-artisans-' + aw.CourtAwardId + '"></div>' +
            '<button class="cp-btn-sm cp-btn-outline" style="margin-top:6px" onclick="cpOpenArtisanModal(' + aw.CourtAwardId + ')"><i class="fas fa-plus"></i> Add Artisan</button></div>' +
            '<div class="cp-expand-actions">' +
            '<button class="cp-btn-primary cp-btn-sm" onclick="cpSaveAward(' + aw.CourtAwardId + ')"><i class="fas fa-save"></i> Save</button>' +
            '<button class="cp-btn-sm cp-btn-danger-inline" onclick="cpRemoveAward(' + aw.CourtAwardId + ')"><i class="fas fa-trash"></i> Remove</button>' +
            '</div></div></div>';
        gid('cp-award-list').insertAdjacentHTML('beforeend', html);
        var cnt = gid('cp-award-count');
        if (cnt) cnt.textContent = '(' + document.querySelectorAll('#cp-award-list .cp-award-row').length + ')';
        cpRenumberRows();
    }

    // ---- Artisan modal ----
    window.cpOpenArtisanModal = function(caid) {
        currentArtisanCourtAwardId = caid;
        gid('cp-art-persona').value       = '';
        gid('cp-art-mundane-id').value    = '';
        gid('cp-art-contribution').value  = '';
        gid('cp-art-error').style.display = 'none';
        gid('cp-artisan-modal').style.display = 'flex';
        setTimeout(function() { gid('cp-art-persona').focus(); }, 50);
    };
    window.cpCloseArtisanModal = function() { gid('cp-artisan-modal').style.display = 'none'; };

    window.cpSubmitArtisan = function() {
        var mundaneId    = gid('cp-art-mundane-id').value;
        var contribution = gid('cp-art-contribution').value.trim();
        var errEl        = gid('cp-art-error');

        if (!mundaneId) { errEl.textContent = 'Please select an artisan.'; errEl.style.display = 'block'; return; }
        errEl.style.display = 'none';

        var fd = new FormData();
        fd.append('CourtAwardId',  currentArtisanCourtAwardId);
        fd.append('MundaneId',     mundaneId);
        fd.append('Contribution',  contribution);

        post('CourtAjax/add_artisan', fd).then(function(d) {
            if (d.status === 0) {
                var a   = d.artisan;
                var row = '<div class="cp-artisan-row" id="cp-art-' + a.CourtAwardArtisanId + '">' +
                    '<i class="fas fa-paint-brush" style="color:#9f7aea"></i>' +
                    '<strong>' + esc(a.Persona) + '</strong>' +
                    (a.Contribution ? '<span style="color:#718096">— ' + esc(a.Contribution) + '</span>' : '') +
                    '<button class="cp-btn-danger-sm" onclick="cpRemoveArtisan(' + a.CourtAwardArtisanId + ')"><i class="fas fa-times"></i></button>' +
                    '</div>';
                gid('cp-artisans-' + currentArtisanCourtAwardId).insertAdjacentHTML('beforeend', row);
                cpCloseArtisanModal();
            } else {
                errEl.textContent = d.error || 'Could not add artisan.';
                errEl.style.display = 'block';
            }
        });
    };

    window.cpRemoveArtisan = function(artId) {
        if (!confirm('Remove this artisan?')) return;
        var fd = new FormData();
        fd.append('CourtAwardArtisanId', artId);
        post('CourtAjax/remove_artisan', fd).then(function(d) {
            if (d.status === 0) { var el = gid('cp-art-' + artId); if (el) el.remove(); }
            else alert(d.error || 'Could not remove.');
        });
    };

    // ---- Maker autocomplete (delegated) ----
    document.addEventListener('input', function(e) {
        var el = e.target;
        if (!el.classList.contains('cp-maker-ac')) return;
        cpAcSearch(el, el.dataset.drop, el.dataset.hidden);
    });

    // ---- Autocomplete ----
    // Position a fixed dropdown under its input — safe inside modals with overflow-y: auto
    function cpPositionAc(input, drop) {
        var r = input.getBoundingClientRect();
        drop.style.top   = (r.bottom + 2) + 'px';
        drop.style.left  = r.left + 'px';
        drop.style.width = r.width + 'px';
    }

    var cpAcTimer = null;
    window.cpAcSearch = function(input, dropdownId, hiddenId) {
        var q = input.value.trim();
        var drop = gid(dropdownId);
        gid(hiddenId).value = '';
        if (q.length < 2) { drop.style.display = 'none'; drop.innerHTML = ''; return; }
        clearTimeout(cpAcTimer);
        cpAcTimer = setTimeout(function() {
            fetch(uir + 'KingdomAjax/playersearch/' + kidId + '&q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                drop.innerHTML = '';
                if (!data || !data.length) {
                    drop.innerHTML = '<div class="cp-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                    cpPositionAc(input, drop);
                    drop.style.display = 'block';
                    return;
                }
                data.slice(0, 12).forEach(function(p) {
                    var div = document.createElement('div');
                    div.className = 'cp-ac-item';
                    div.innerHTML = esc(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + esc(p.KAbbr || '') + ':' + esc(p.PAbbr || '') + ')</span>';
                    div.addEventListener('click', function() {
                        input.value = p.Persona;
                        gid(hiddenId).value = p.MundaneId;
                        drop.style.display = 'none';
                    });
                    drop.appendChild(div);
                });
                cpPositionAc(input, drop);
                drop.style.display = 'block';
            })
            .catch(function() { drop.style.display = 'none'; });
        }, 200);
    };

    // ---- Note popup ----
    window.cpShowNote = function(btn) {
        var popup = gid('cp-note-popup');
        gid('cp-note-popup-text').textContent = btn.dataset.note || '';
        popup.style.display = 'block';
        var r  = btn.getBoundingClientRect();
        var pw = popup.offsetWidth;
        var ph = popup.offsetHeight;
        var top = r.bottom + 6;
        if (top + ph > window.innerHeight - 10) top = r.top - ph - 6;
        var left = r.left;
        if (left + pw > window.innerWidth - 10) left = window.innerWidth - pw - 10;
        popup.style.top  = Math.max(10, top)  + 'px';
        popup.style.left = Math.max(10, left) + 'px';
    };
    window.cpDismissNote = function() {
        var popup = gid('cp-note-popup');
        if (popup) popup.style.display = 'none';
    };

    // Close dropdowns and note popup on outside click
    document.addEventListener('click', function(e) {
        document.querySelectorAll('.cp-ac-dropdown').forEach(function(d) {
            if (!d.parentElement.contains(e.target)) d.style.display = 'none';
        });
        var popup = gid('cp-note-popup');
        if (popup && popup.style.display !== 'none' && !popup.contains(e.target) && !e.target.closest('.cp-note-btn')) {
            popup.style.display = 'none';
        }
    });

    // Close modals on backdrop / Escape
    ['cp-rec-modal','cp-adhoc-modal','cp-artisan-modal'].forEach(function(id) {
        var el = gid(id);
        if (el) el.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            cpDismissNote();
            ['cp-rec-modal','cp-adhoc-modal','cp-artisan-modal'].forEach(function(id) {
                var el = gid(id); if (el) el.style.display = 'none';
            });
            var cpso = gid('cp-script-overlay'); if (cpso && !cpso.hidden) cpCloseScript();
        }
    });
})();

// Hero dominant-color extraction (mirrors Park/Kingdom pattern)
window.cpApplyHeroColor = function(img) {
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 60; canvas.height = 60;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, 60, 60);
            var data = ctx.getImageData(0, 0, 60, 60).data;
            var r = 0, g = 0, b = 0, count = 0;
            for (var i = 0; i < data.length; i += 16) {
                if (data[i+3] < 64) continue;
                r += data[i]; g += data[i+1]; b += data[i+2]; count++;
            }
            if (!count) return;
            r = Math.round(r/count); g = Math.round(g/count); b = Math.round(b/count);
            // Convert to HSL, clamp lightness to 18% for dark hero
            var rn=r/255, gn=g/255, bn=b/255;
            var max=Math.max(rn,gn,bn), min=Math.min(rn,gn,bn), h=0, s=0, l=(max+min)/2;
            if (max!==min) {
                var d=max-min; s=l>.5?d/(2-max-min):d/(max+min);
                if (max===rn) h=((gn-bn)/d+(gn<bn?6:0))/6;
                else if (max===gn) h=((bn-rn)/d+2)/6;
                else h=((rn-gn)/d+4)/6;
            }
            l = 0.18;
            var hero = document.getElementById('cp-hero');
            if (hero) hero.style.backgroundColor = 'hsl('+(h*360).toFixed(0)+','+(s*100).toFixed(0)+'%,'+(l*100).toFixed(0)+'%)';
        } catch(e) {}
};


    // ---- Court Script ----
    var cpScriptDensity = 'compact';

    function cpScriptActiveAwards() {
        return (window.courtAwards || []).filter(function (a) { return a.Status !== 'cancelled'; });
    }
    function cpScriptAwardLabel(a) {
        var s = esc(a.AwardName || '');
        if (a.IsLadder && a.Rank) s += ' (Rank ' + a.Rank + ')';
        return s;
    }
    function cpScriptRecipient(a) {
        var s = esc(a.Persona || '');
        if (a.ParkAbbrev) s += ' <span class="cp-script-park">' + esc(a.ParkAbbrev) + '</span>';
        return s;
    }
    function cpScriptPtlMark(a) {
        return a.PassToLocal ? ' <span class="cp-script-ptl">(pass to local)</span>' : '';
    }
    function cpScriptArtisans(a) {
        var parts = [];
        if (a.ScrollMakerPersona)  parts.push(esc(a.ScrollMakerPersona) + ' (scroll)');
        if (a.RegaliaMakerPersona) parts.push(esc(a.RegaliaMakerPersona) + ' (regalia)');
        (a.Artisans || []).forEach(function (art) {
            var p = esc(art.Persona || '');
            if (art.Contribution) p += ' (' + esc(art.Contribution) + ')';
            if (p) parts.push(p);
        });
        return parts.join(', ');
    }
    function cpScriptCompact(awards) {
        if (!awards.length) return '<p class="cp-script-empty">No awards to present.</p>';
        var rows = awards.map(function (a, i) {
            return '<tr>' +
                '<td class="cp-script-num">' + (i + 1) + '</td>' +
                '<td class="cp-script-check">' + (a.Status === 'given' ? '☑' : '☐') + '</td>' +
                '<td class="cp-script-recip">' + cpScriptRecipient(a) + '</td>' +
                '<td class="cp-script-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) + '</td>' +
                '</tr>';
        }).join('');
        return '<table class="cp-script-compact"><tbody>' + rows + '</tbody></table>';
    }
    function cpScriptCitation(awards) {
        if (!awards.length) return '<p class="cp-script-empty">No awards to present.</p>';
        return awards.map(function (a, i) {
            var html = '<div class="cp-script-cite">' +
                '<div class="cp-script-cite-head">' +
                    '<span class="cp-script-cite-num">' + (i + 1) + '.</span> ' +
                    '<span class="cp-script-cite-recip">' + cpScriptRecipient(a) + '</span> ' +
                    '<span class="cp-script-cite-award">' + cpScriptAwardLabel(a) + cpScriptPtlMark(a) + '</span>' +
                '</div>';
            if (a.PublicComment) html += '<div class="cp-script-cite-text">' + esc(a.PublicComment) + '</div>';
            var art = cpScriptArtisans(a);
            if (art) html += '<div class="cp-script-cite-artisans"><strong>Artisans to thank:</strong> ' + art + '</div>';
            html += '</div>';
            return html;
        }).join('');
    }
    function cpRenderScript(density) {
        var body = document.getElementById('cp-script-body');
        if (!body) return;
        var awards = cpScriptActiveAwards();
        body.innerHTML = (density === 'citation') ? cpScriptCitation(awards) : cpScriptCompact(awards);
    }
    function cpSetScriptDensity(d) {
        cpScriptDensity = (d === 'citation') ? 'citation' : 'compact';
        document.querySelectorAll('.cp-script-density button').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-density') === cpScriptDensity);
        });
        cpRenderScript(cpScriptDensity);
    }
    function cpOpenScript() {
        var overlay = document.getElementById('cp-script-overlay');
        if (!overlay) return;
        var titleEl = document.getElementById('cp-script-title');
        var dateEl  = document.getElementById('cp-script-date');
        if (titleEl) titleEl.textContent = courtMeta.name || 'Court';
        if (dateEl) {
            if (courtMeta.date) {
                var d = new Date(courtMeta.date + 'T00:00:00');
                dateEl.textContent = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            } else {
                dateEl.textContent = '';
            }
        }
        cpRenderScript(cpScriptDensity);
        // Move overlay to be a direct child of <body> so the print selector
        // (body.cp-script-open > *:not(#cp-script-overlay)) hides everything else.
        document.body.appendChild(overlay);
        document.body.classList.add('cp-script-open');
        overlay.hidden = false;
    }
    function cpCloseScript() {
        var overlay = document.getElementById('cp-script-overlay');
        if (overlay) overlay.hidden = true;
        document.body.classList.remove('cp-script-open');
    }
    function cpPrintScript() {
        window.print();
    }
</script>

<div id="cp-script-overlay" class="cp-script-overlay" hidden onclick="if(event.target===this)cpCloseScript()">
    <div class="cp-script-modal">
        <div class="cp-script-chrome">
            <div class="cp-script-titlebar">
                <h1 id="cp-script-title" class="cp-script-h1"></h1>
                <p id="cp-script-date" class="cp-script-date"></p>
            </div>
            <div class="cp-script-controls">
                <div class="cp-script-density" role="group" aria-label="Script density">
                    <button type="button" data-density="compact" class="active" onclick="cpSetScriptDensity('compact')">Compact</button>
                    <button type="button" data-density="citation" onclick="cpSetScriptDensity('citation')">Citation</button>
                </div>
                <div class="cp-script-actions">
                    <button type="button" class="cp-btn cp-btn-outline cp-btn-sm" onclick="cpCloseScript()">Close</button>
                    <button type="button" class="cp-btn cp-btn-primary cp-btn-sm" onclick="cpPrintScript()">Print</button>
                </div>
            </div>
        </div>
        <div id="cp-script-body" class="cp-script-body"></div>
    </div>
</div>
