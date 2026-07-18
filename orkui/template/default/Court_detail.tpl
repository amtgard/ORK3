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

// Stage/finalize planner data (spec §6)
$giverOptions = $GiverOptions ?? ['default' => null, 'pills' => []];
$courtMode    = $CourtMode    ?? 'run';
$stateVersion = $StateVersion ?? '';
$stagedCount  = (int)($StagedCount ?? 0);
$prevSkipped  = $PrevSkipped  ?? [];

$statusLabel = ['draft' => 'Draft', 'published' => 'Published', 'complete' => 'Complete'];
$statusColor = ['draft' => '#718096', 'published' => '#2b6cb0', 'complete' => '#276749'];
$statusBg    = ['draft' => '#edf2f7', 'published' => '#ebf8ff', 'complete' => '#f0fff4'];

$awardStatusLabel = ['planned' => 'Planned', 'announced' => 'Announced', 'staged' => 'Staged', 'given' => 'Given', 'cancelled' => 'Skipped'];
$awardStatusColor = ['planned' => '#4a5568', 'announced' => '#2b6cb0', 'staged' => '#b7791f', 'given' => '#276749', 'cancelled' => '#c53030'];
$awardStatusBg    = ['planned' => '#edf2f7', 'announced' => '#ebf8ff', 'staged' => '#fffbeb', 'given' => '#f0fff4', 'cancelled' => '#fff5f5'];

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

// QW#8: state-aware label for the scroll/regalia tracking glyphs (mirrors the JS
// cpTrackLabel). Keeps data-tip + aria-label describing the CURRENT state, not color-alone.
if (!function_exists('cp_track_label')) {
    function cp_track_label($type, $status)
    {
        $noun = $type === 'scroll' ? 'Scroll' : 'Regalia';
        $s    = (int)$status;
        if ($s === 2) {
            return $noun . ': done';
        }
        if ($s === 1) {
            return $noun . ($type === 'scroll' ? ': in progress (needs printing)' : ': in progress (needs token)');
        }
        return $noun . ': not tracked';
    }
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/rank-pill.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/rank-pill.css') ?>">
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
/* Promoted to <h1> (QW#8) — reset the global orkui heading pill box (bg/border/padding/radius). */
.cp-hero-name { font-size: 26px; font-weight: 700; color: #fff; margin: 0 0 6px; line-height: 1.2; background: none; border: none; padding: 0; border-radius: 0; text-shadow: 0 1px 4px rgba(0,0,0,.4); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
/* QW#1a / S3: horizontal scroll so the Grant/Skip columns are reachable on narrow
   viewports (the page <html> is overflow-x:hidden). Below 640px the grid collapses to
   stacked cards (see the mobile @media block) so this scroll only bites at 641–800px. */
.cp-award-list { border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 20px; }
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
/* Highlight the main line of the row whose details panel is open, so it's clear
   which row the expanded panel belongs to. Uses an inset left accent (no layout
   shift) plus a subtle tint; the granted/skipped/staged states only alter row
   opacity, so this doesn't fight those tints. */
.cp-award-row-main:has(+ .cp-award-row-expand.open) { background: #ebf4ff; box-shadow: inset 3px 0 0 #4299e1; }
html[data-theme="dark"] .cp-award-row-main:has(+ .cp-award-row-expand.open) { background: rgba(66,153,225,.12); box-shadow: inset 3px 0 0 #4299e1; }
.cp-expand-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
.cp-expand-label { font-size: 11px; font-weight: 700; color: #718096; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 3px; }
.cp-expand-val { font-size: 13px; color: #2d3748; }
.cp-notes-area { width: 100%; border: 1px solid #cbd5e0; border-radius: 5px; padding: 7px 10px; font-size: 13px; resize: vertical; min-height: 60px; box-sizing: border-box; }
.cp-pc-label-row { display: flex; align-items: center; gap: 8px; }
.cp-rec-hint-btn { background: none; border: none; padding: 0; cursor: pointer; font-size: 12px; color: #3182ce; line-height: 1; }
.cp-rec-hint-btn:hover { text-decoration: underline; }
.cp-pubcomment-wrap { position: relative; }
.cp-rec-hint { position: absolute; top: 1px; left: 1px; right: 1px; padding: 7px 10px; font-size: 13px; line-height: 1.35; color: #718096; font-style: italic; white-space: normal; overflow: hidden; pointer-events: none; box-sizing: border-box; max-height: calc(100% - 2px); }
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
/* Rank pill picker (ad-hoc Add Award modal) — clickable .ladder-rank pills */
.cp-rank-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 2px; }
.cp-rank-pill { width: auto; padding: 3px 11px; font-size: 12px; cursor: pointer; opacity: .5; transition: opacity .12s ease, box-shadow .12s ease; }
.cp-rank-pill:hover { opacity: .85; }
.cp-rank-pill-selected { opacity: 1; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2b6cb0; }
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
.cp-flag-rec[data-tip] { position: relative; }
.cp-flag-rec[data-tip]:hover::after { content: attr(data-tip); position: absolute; top: 100%; right: 0; margin-top: 4px; width: max-content; max-width: 200px; white-space: normal; background: #2d3748; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 11px; line-height: 1.35; text-align: left; box-shadow: 0 2px 6px rgba(0,0,0,0.25); z-index: 50; pointer-events: none; }
html[data-theme="dark"] .cp-flag-rec[data-tip]:hover::after { background: #000; }
.cp-send-local-btn { position: relative; }
.cp-send-local-btn[data-tip]:hover::after { content: attr(data-tip); position: absolute; top: 100%; left: 0; margin-top: 4px; width: max-content; max-width: 240px; white-space: normal; background: #2d3748; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 11px; line-height: 1.35; text-align: left; box-shadow: 0 2px 6px rgba(0,0,0,0.25); z-index: 50; pointer-events: none; }
html[data-theme="dark"] .cp-send-local-btn[data-tip]:hover::after { background: #000; }
/* Generic data-tip tooltips (converted from native title=) — reuses the pattern above */
.cp-page [data-tip], #cp-note-popup [data-tip], .cp-overlay [data-tip] { position: relative; }
.cp-page [data-tip]:hover::after, #cp-note-popup [data-tip]:hover::after, .cp-overlay [data-tip]:hover::after { content: attr(data-tip); position: absolute; top: 100%; left: 0; margin-top: 4px; width: max-content; max-width: 240px; white-space: normal; background: #2d3748; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 11px; line-height: 1.35; text-align: left; box-shadow: 0 2px 6px rgba(0,0,0,0.25); z-index: 1001; pointer-events: none; }
html[data-theme="dark"] .cp-page [data-tip]:hover::after, html[data-theme="dark"] #cp-note-popup [data-tip]:hover::after, html[data-theme="dark"] .cp-overlay [data-tip]:hover::after { background: #000; }
/* Right-anchor tooltips in the tracking / flags columns so they don't overflow the row edge */
.cp-tracking-icon[data-tip]:hover::after, .cp-hdr-scroll[data-tip]:hover::after, .cp-hdr-regalia[data-tip]:hover::after, .cp-flag-local[data-tip]:hover::after,
.cp-rm-qualified[data-tip]:hover::after, .cp-rm-snooze-chip[data-tip]:hover::after, .cp-rm-onother[data-tip]:hover::after, .cp-rm-seconds[data-tip]:hover::after, .cp-rm-age-badge[data-tip]:hover::after, .cp-btn-undo[data-tip]:hover::after { left: auto; right: 0; }
/* Toast surface for network/AJAX failures */
.cp-toast { position: fixed; top: 20px; right: 20px; z-index: 9999; background: #c53030; color: #fff; padding: 12px 16px; border-radius: 6px; font-size: 13px; line-height: 1.4; max-width: 320px; box-shadow: 0 4px 14px rgba(0,0,0,0.25); }
html[data-theme="dark"] .cp-toast { background: #9b2c2c; }
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
.cp-ac-group { padding: 5px 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #718096; background: #f7fafc; border-bottom: 1px solid #edf2f7; cursor: default; position: sticky; top: 0; }

.cp-tracking-icon {
    display: inline-block;
    position: relative;
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
/* QW#8: state must not be color-alone — a corner glyph distinguishes the three states
   (− not tracked, … in progress, ✓ done). aria-label/data-tip are kept in sync in JS. */
.cp-tracking-icon::after {
    content: ''; position: absolute; right: -3px; bottom: -3px;
    min-width: 12px; height: 12px; padding: 0 1px; border-radius: 6px; box-sizing: border-box;
    font-size: 9px; font-weight: 700; line-height: 12px; text-align: center;
    background: #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.10);
}
.cp-tracking-icon[data-status="0"]::after { content: '\2212'; color: #718096; } /* minus */
.cp-tracking-icon[data-status="1"]::after { content: '\2026'; color: #c05621; } /* ellipsis */
.cp-tracking-icon[data-status="2"]::after { content: '\2713'; color: #276749; } /* check */
html[data-theme="dark"] .cp-tracking-icon::after { background: #161b22; box-shadow: 0 0 0 1px rgba(255,255,255,.14); }
html[data-theme="dark"] .cp-tracking-icon[data-status="0"]::after { color: #a0aec0; }
html[data-theme="dark"] .cp-tracking-icon[data-status="1"]::after { color: #fbd38d; }
html[data-theme="dark"] .cp-tracking-icon[data-status="2"]::after { color: #9ae6b4; }

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
html[data-theme="dark"] .cp-rank-pill-selected { box-shadow: 0 0 0 2px #1f2733, 0 0 0 4px #63b3ed; }
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
html[data-theme="dark"] .cp-rec-hint { color: #a0aec0; }
html[data-theme="dark"] .cp-rec-hint-btn { color: #63b3ed; }
html[data-theme="dark"] .cp-artisan-row { color: #cbd5e0; }
html[data-theme="dark"] .cp-maker-ac    { background: #1f2733 !important; border-color: #2d3748 !important; color: #e2e8f0 !important; }
html[data-theme="dark"] .cp-maker-ac::placeholder { color: #4a5568; }

/* Autocomplete dropdown (inline styles on some instances — need !important) */
html[data-theme="dark"] .cp-ac-dropdown { background: #1f2733 !important; border-color: #2d3748 !important; box-shadow: 0 4px 12px rgba(0,0,0,.4) !important; }
html[data-theme="dark"] .cp-ac-item { color: #cbd5e0; }
html[data-theme="dark"] .cp-ac-item:hover { background: #2d3748 !important; }
html[data-theme="dark"] .cp-ac-group { color: #a0aec0; background: #171e28 !important; border-bottom-color: #2d3748 !important; }

/* Error inline */
html[data-theme="dark"] .cp-error { color: #fc8181; }
html[data-theme="dark"] .cp-error-box { background: rgba(229,62,62,.12); border-color: rgba(229,62,62,.4); color: #fc8181; }
html[data-theme="dark"] .cp-h2-icon { color: #a0aec0; }
html[data-theme="dark"] .cp-count   { color: #718096; }
html[data-theme="dark"] .cp-btn-danger-inline { background: rgba(229,62,62,.12) !important; border-color: rgba(229,62,62,.4) !important; color: #fc8181 !important; }

/* ============================================================
   STAGE / FINALIZE — Court Planner v3 (spec §6)
   ============================================================ */

/* Mode badge in hero (Run at Court / Locked as Plan) */
.cp-mode-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; letter-spacing: .02em; }
.cp-mode-run  { background: rgba(255,255,255,.16); color: #fff; }
.cp-mode-plan { background: rgba(214,158,46,.9); color: #1a2744; }

/* Staged-not-finalized safeguard indicator (spec §5.3) */
.cp-staged-indicator { display: none; align-items: center; gap: 12px; background: #fffbeb; border: 1px solid #f6e05e; color: #744210; border-radius: 8px; padding: 11px 16px; margin-bottom: 14px; font-size: 13px; }
.cp-staged-indicator.show { display: flex; }
.cp-staged-indicator i.cp-si-icon { font-size: 18px; color: #b7791f; flex-shrink: 0; }
.cp-staged-indicator .cp-si-text { flex: 1; min-width: 0; }
.cp-staged-indicator .cp-si-text strong { color: #744210; }
.cp-staged-indicator .cp-si-btn { background: #b7791f; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
.cp-staged-indicator .cp-si-btn:hover { background: #975a16; }
html[data-theme="dark"] .cp-staged-indicator { background: rgba(214,158,46,.12); border-color: rgba(214,158,46,.4); color: #f6e05e; }
html[data-theme="dark"] .cp-staged-indicator .cp-si-text strong { color: #f6e05e; }
html[data-theme="dark"] .cp-staged-indicator i.cp-si-icon { color: #f6e05e; }

/* Prev-court skipped banner (spec §6.5) */
.cp-prev-banner { display: none; align-items: center; gap: 12px; background: #ebf8ff; border: 1px solid #90cdf4; color: #2c5282; border-radius: 8px; padding: 11px 16px; margin-bottom: 14px; font-size: 13px; }
.cp-prev-banner.show { display: flex; }
.cp-prev-banner i.cp-pb-icon { font-size: 18px; color: #2b6cb0; flex-shrink: 0; }
.cp-prev-banner .cp-pb-text { flex: 1; min-width: 0; line-height: 1.45; }
.cp-prev-banner .cp-pb-btn { background: #2c5282; color: #fff; border: none; padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
.cp-prev-banner .cp-pb-btn:hover { background: #2a4a7f; }
.cp-prev-banner .cp-pb-dismiss { background: none; border: none; color: #90cdf4; cursor: pointer; font-size: 16px; padding: 2px 4px; flex-shrink: 0; }
.cp-prev-banner .cp-pb-dismiss:hover { color: #2b6cb0; }
html[data-theme="dark"] .cp-prev-banner { background: rgba(99,179,237,.12); border-color: rgba(99,179,237,.4); color: #90cdf4; }
html[data-theme="dark"] .cp-prev-banner i.cp-pb-icon { color: #90cdf4; }
html[data-theme="dark"] .cp-prev-banner .cp-pb-dismiss { color: #4a5568; }
html[data-theme="dark"] .cp-prev-banner .cp-pb-dismiss:hover { color: #90cdf4; }

/* Undo control on staged rows (spec §6.3) */
.cp-btn-undo { background: #fffbeb; color: #b7791f; border: 1px solid #f6e05e; padding: 3px 10px; border-radius: 5px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: background .1s; }
.cp-btn-undo:hover { background: #fef5d7; }
html[data-theme="dark"] .cp-btn-undo { background: rgba(214,158,46,.14); border-color: rgba(214,158,46,.4); color: #f6e05e; }
html[data-theme="dark"] .cp-btn-undo:hover { background: rgba(214,158,46,.24); }

/* Staged rows keep full opacity (active), but tint the badge in dark mode */
html[data-theme="dark"] .cp-award-row.cp-staged .cp-aw-badge { background: rgba(214,158,46,.18) !important; color: #f6e05e !important; box-shadow: inset 0 0 0 1px rgba(214,158,46,.35); }

/* Bulk "Record grants" button (plan mode) */
.cp-btn-record { background: #b7791f; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.cp-btn-record:hover { background: #975a16; }

/* Drag handle for reorder (spec §6.2) */
.cp-award-drag { color: #cbd5e0; cursor: grab; font-size: 12px; display: flex; align-items: center; justify-content: center; touch-action: none; user-select: none; padding: 2px; }
.cp-award-drag:hover { color: #718096; }
.cp-award-drag:active { cursor: grabbing; }
.cp-list-published .cp-award-drag { display: none; }
.cp-cell-order { flex-direction: column; gap: 2px; }
.cp-award-row.cp-cp-dragging { opacity: .5; background: #ebf8ff !important; }
.cp-award-drop-line { height: 0; border-top: 2px solid #2c5282; margin: -1px 0; }
html[data-theme="dark"] .cp-award-drag { color: #4a5568; }
html[data-theme="dark"] .cp-award-drag:hover { color: #a0aec0; }
html[data-theme="dark"] .cp-award-row.cp-cp-dragging { background: #1f2733 !important; }
html[data-theme="dark"] .cp-award-drop-line { border-top-color: #63b3ed; }

/* Grant modal — giver pills + fields */
.cp-grant-ro { background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 9px 12px; font-size: 14px; color: #2d3748; }
.cp-grant-ro .cp-grant-ro-award { color: #4a5568; font-size: 13px; margin-top: 2px; }
.cp-giver-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.cp-giver-pill { background: #edf2f7; border: 1px solid #cbd5e0; color: #4a5568; padding: 5px 11px; border-radius: 16px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: background .1s, border-color .1s, color .1s; }
.cp-giver-pill:hover { background: #e2e8f0; }
.cp-giver-pill.active { background: #2c5282; border-color: #2c5282; color: #fff; }
.cp-giver-pill .cp-giver-role { font-size: 10px; opacity: .75; text-transform: uppercase; letter-spacing: .03em; }
html[data-theme="dark"] .cp-grant-ro { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-grant-ro .cp-grant-ro-award { color: #a0aec0; }
html[data-theme="dark"] .cp-giver-pill { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-giver-pill:hover { background: #2d3748; }
html[data-theme="dark"] .cp-giver-pill.active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }

/* Complete-court three-option modal (spec §6.6) */
.cp-complete-opts { display: flex; flex-direction: column; gap: 10px; }
.cp-complete-opt { text-align: left; background: #fff; border: 1px solid #cbd5e0; border-radius: 8px; padding: 13px 15px; cursor: pointer; display: flex; align-items: flex-start; gap: 12px; transition: border-color .12s, background .12s; }
.cp-complete-opt:hover { border-color: #2c5282; background: #f7fafc; }
.cp-complete-opt i { font-size: 18px; margin-top: 1px; flex-shrink: 0; }
.cp-complete-opt .cp-co-title { font-size: 14px; font-weight: 700; color: #2d3748; }
.cp-complete-opt .cp-co-desc { font-size: 12px; color: #718096; margin-top: 2px; line-height: 1.4; }
.cp-complete-opt.cp-co-primary i { color: #276749; }
.cp-complete-opt.cp-co-danger i { color: #c05621; }
.cp-complete-opt.cp-co-neutral i { color: #718096; }
.cp-complete-fail { display: none; margin-top: 12px; background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; border-radius: 6px; padding: 10px 12px; font-size: 12px; line-height: 1.5; }
html[data-theme="dark"] .cp-complete-opt { background: #161b22; border-color: #2d3748; }
html[data-theme="dark"] .cp-complete-opt:hover { border-color: #2b6cb0; background: #1f2733; }
html[data-theme="dark"] .cp-complete-opt .cp-co-title { color: #e2e8f0; }
html[data-theme="dark"] .cp-complete-opt .cp-co-desc { color: #a0aec0; }
html[data-theme="dark"] .cp-complete-fail { background: rgba(229,62,62,.12); border-color: rgba(229,62,62,.4); color: #fc8181; }

/* Publish choice (Run vs Plan) — reuses cp-complete-opt look */
.cp-complete-opt.cp-co-run i { color: #2b6cb0; }
.cp-complete-opt.cp-co-plan i { color: #b7791f; }

/* Shared modal lead paragraph */
.cp-modal-lead { font-size: 13px; color: #4a5568; margin: 0 0 14px; line-height: 1.5; }
html[data-theme="dark"] .cp-modal-lead { color: #a0aec0; }

/* ============================================================
   PHASE 3b — presentation: mobile, tap targets, a11y, un-skip
   ============================================================ */

/* QW#7: inline Un-skip on cancelled/skipped rows (mirrors the staged Undo) */
.cp-btn-unskip { background: #edf2f7; color: #4a5568; border: 1px solid #cbd5e0; padding: 3px 8px; border-radius: 5px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: background .1s; }
.cp-btn-unskip:hover { background: #e2e8f0; color: #2d3748; }
html[data-theme="dark"] .cp-btn-unskip { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-btn-unskip:hover { background: #2d3748; color: #e2e8f0; }

/* cp-toast-info (S5 stale-reload notice) — calm navy, never the red error look */
.cp-toast-info { background: #2c5282; }
html[data-theme="dark"] .cp-toast-info { background: #2b6cb0; }

/* Published-mode "+ Add award" toolbar (QW#6) — compact/inline */
#cp-published-add-tools { align-items: center; }
#cp-published-add-tools .cp-btn-sm { padding: 4px 10px; font-size: 12px; }

/* Presence + honest sync chip (S5) */
.cp-sb-presence { color: #4a5568; font-weight: 600; }
.cp-sb-sync { color: #718096; font-weight: 600; }
.cp-sb-sync[data-state="synced"]       { color: #276749; }
.cp-sb-sync[data-state="reconnecting"] { color: #c05621; }
html[data-theme="dark"] .cp-sb-presence { color: #cbd5e0; }
html[data-theme="dark"] .cp-sb-sync { color: #a0aec0; }
html[data-theme="dark"] .cp-sb-sync[data-state="synced"]       { color: #9ae6b4; }
html[data-theme="dark"] .cp-sb-sync[data-state="reconnecting"] { color: #fbd38d; }

/* QW#8 contrast — retire #a0aec0-on-white for load-bearing small text (row #, rank,
   dates) → ≥ #6b7280; darken muted park/body text → ≥ #5a6472. Dark equivalents kept legible. */
.cp-cell-num,
.cp-cell-award .cp-award-rank,
.cp-award-rank,
.cp-rm-date,
.cp-script-num { color: #6b7280; }
.cp-award-park,
.cp-cell-recipient .cp-award-park,
.cp-rm-park,
.cp-script-park { color: #5a6472; }
html[data-theme="dark"] .cp-cell-num,
html[data-theme="dark"] .cp-cell-award .cp-award-rank,
html[data-theme="dark"] .cp-award-rank,
html[data-theme="dark"] .cp-rm-date,
html[data-theme="dark"] .cp-script-num,
html[data-theme="dark"] .cp-award-park,
html[data-theme="dark"] .cp-cell-recipient .cp-award-park,
html[data-theme="dark"] .cp-rm-park,
html[data-theme="dark"] .cp-script-park { color: #97a3b4; }

/* QW#8 shared focus ring for the custom court controls (scoped) */
.cp-page :focus-visible,
.cp-hero :focus-visible,
.cp-overlay :focus-visible,
#cp-note-popup :focus-visible { outline: 2px solid #4299e1; outline-offset: 2px; border-radius: 3px; }

/* QW#8 reduced motion — scoped to the court page surfaces */
@media (prefers-reduced-motion: reduce) {
    .cp-page *, .cp-hero *, .cp-overlay *, #cp-note-popup *,
    .cp-staged-indicator, .cp-prev-banner { transition: none !important; animation: none !important; }
}

/* QW#7 tap targets — coarse pointers get ≥44px hit area (padding, not larger glyphs) */
@media (pointer: coarse) {
    .cp-reorder-btn { min-width: 34px; min-height: 30px; }
    .cp-row-grid .cp-reorder-btn { width: 34px; height: 30px; }
    .cp-tracking-icon { min-width: 40px; min-height: 40px; line-height: 40px; }
    .cp-btn-grant, .cp-btn-skip, .cp-btn-undo, .cp-btn-unskip { min-height: 40px; }
    .cp-list-published .cp-grant-actions .cp-btn-grant,
    .cp-list-published .cp-grant-actions .cp-btn-skip { padding: 8px 12px; }
    .cp-modal-close { min-width: 44px; min-height: 44px; }
    .cp-note-btn, .cp-btn-danger-sm, .cp-rm-trash, #cp-note-popup-close, .cp-pb-dismiss { min-width: 40px; min-height: 40px; }
}

/* QW#1 / S3 — mobile stacked-card award list (also the run-mode touch layout) */
@media (max-width: 640px) {
    /* Column header makes no sense stacked */
    #cp-list-header { display: none !important; }
    /* Collapse the fixed-px grid into a flowing card */
    .cp-award-row-main.cp-row-grid { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; padding: 12px 14px; }
    .cp-cell { min-width: 0; }
    .cp-cell-num       { order: 1; flex: 0 0 auto; }
    .cp-cell-order     { order: 2; flex: 0 0 auto; }
    .cp-cell-recipient { order: 3; flex: 1 1 auto; font-size: 15px; flex-direction: row; align-items: baseline; }
    .cp-cell-chevron   { order: 4; flex: 0 0 auto; margin-left: auto; }
    .cp-cell-award     { order: 5; flex: 1 1 100%; font-size: 14px; }
    .cp-cell-type      { order: 6; flex: 0 0 auto; }
    .cp-cell-type > *  { width: auto; }
    .cp-cell-flags     { order: 7; flex: 0 0 auto; }
    .cp-cell-scroll    { order: 8; flex: 0 0 auto; margin-left: auto; }
    .cp-cell-regalia   { order: 9; flex: 0 0 auto; }
    /* Status + actions span the full card width, stacked */
    .cp-cell-status    { order: 10; flex: 1 1 100%; flex-direction: column; align-items: stretch; gap: 8px; }
    .cp-cell-status .cp-aw-badge   { align-self: flex-start; }
    .cp-cell-status .cp-grant-static { align-self: flex-start; }
    .cp-cell-status .cp-grant-actions { display: flex; width: 100%; gap: 8px; justify-content: stretch; }
    .cp-cell-status .cp-grant-actions > button { flex: 1 1 auto; justify-content: center; min-height: 44px; font-size: 14px; padding: 10px 12px; }
    /* Reachable, roomy tracking + reorder on the phone */
    .cp-tracking-icon { width: 34px; height: 34px; line-height: 34px; }
    .cp-row-grid .cp-reorder-btn { width: 30px; height: 22px; font-size: 10px; }
    /* Compact the toolbar so it doesn't wrap awkwardly */
    .cp-list-toolbar { gap: 6px; }
}

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
            </div>
            <h1 class="cp-hero-name"><?= htmlspecialchars($court['Name']) ?></h1>
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
            <div style="display:flex;align-items:center;gap:8px">
                <span class="cp-badge" id="cp-status-badge"
                      style="background:<?= $statusBg[$courtSt] ?? '#edf2f7' ?>;color:<?= $statusColor[$courtSt] ?? '#718096' ?>">
                    <?= $statusLabel[$courtSt] ?? $courtSt ?>
                </span>
                <?php if (in_array($courtSt, ['published', 'complete'])): ?>
                <span class="cp-mode-badge <?= $courtMode === 'plan' ? 'cp-mode-plan' : 'cp-mode-run' ?>" id="cp-mode-badge">
                    <i class="fas fa-<?= $courtMode === 'plan' ? 'clipboard-list' : 'bullhorn' ?>"></i>
                    <?= $courtMode === 'plan' ? 'Plan' : 'Run at Court' ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($nextSt): ?>
            <button class="cp-btn-primary" onclick="cpAdvanceStatus('<?= $nextSt ?>')">
                <?= $nextLabel[$courtSt] ?? 'Advance' ?> <i class="fas fa-arrow-right"></i>
            </button>
            <?php endif; ?>
            <?php if ($courtSt === 'published' && $courtMode === 'plan'): ?>
            <button class="cp-btn-record" style="margin-top:5px" onclick="cpBulkRecord()">
                <i class="fas fa-clipboard-check"></i> Record All Grants
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

<?php if (!$error): ?>
<div class="cp-page" style="padding-top:0;padding-bottom:0;margin-bottom:0">
    <!-- Unfinalized-staged safeguard indicator (spec §5.3) -->
    <div class="cp-staged-indicator<?= ($stagedCount > 0 && $courtSt !== 'complete') ? ' show' : '' ?>" id="cp-staged-indicator" role="status" aria-live="polite">
        <i class="fas fa-hourglass-half cp-si-icon"></i>
        <span class="cp-si-text"><strong><span id="cp-staged-count-n"><?= $stagedCount ?></span> grant<span id="cp-staged-count-s"><?= $stagedCount === 1 ? '' : 's' ?></span> staged</strong>, not yet finalized — Finalize to record them in the player registry.</span>
        <button class="cp-si-btn" onclick="cpOpenCompleteModal()"><i class="fas fa-stamp"></i> Finalize &amp; Complete</button>
    </div>

    <?php if ($courtSt === 'draft' && !empty($prevSkipped)): ?>
    <!-- Prepopulate skipped-from-last-court banner (spec §6.5) -->
    <div class="cp-prev-banner show" id="cp-prev-banner">
        <i class="fas fa-history cp-pb-icon"></i>
        <span class="cp-pb-text"><strong><?= count($prevSkipped) ?> award<?= count($prevSkipped) === 1 ? '' : 's' ?></strong> <?= count($prevSkipped) === 1 ? 'was' : 'were' ?> skipped at the most recent previous court and <?= count($prevSkipped) === 1 ? 'has' : 'have' ?> not been granted yet. Prepopulate <?= count($prevSkipped) === 1 ? 'it' : 'those' ?> onto this court?</span>
        <button class="cp-pb-btn" id="cp-prev-btn" onclick="cpPrepopulate()"><i class="fas fa-plus"></i> Prepopulate</button>
        <button class="cp-pb-dismiss" onclick="cpDismissPrevBanner()" data-tip="Dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

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
    <?php if ($courtSt === 'published'): ?>
    <!-- S5 presence + honest sync state (populated by the heartbeat) -->
    <span class="cp-status-sep"> &middot; </span>
    <span class="cp-sb-presence" id="cp-presence-chip" data-tip="Officers currently viewing this court"><i class="fas fa-users" style="margin-right:4px"></i>1 viewing</span>
    <span class="cp-status-sep"> &middot; </span>
    <span class="cp-sb-sync" id="cp-sync-indicator" aria-live="polite"><i class="fas fa-circle-notch" style="margin-right:4px;opacity:.5"></i>Connecting…</span>
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
                    data-tip="Collapse sidebar" aria-label="Collapse sidebar">
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
            <button class="cp-btn-primary cp-btn-sm" onclick="cpOpenAdhocModal('award')">
                <i class="fas fa-plus"></i> Add Award
            </button>
            <button class="cp-btn-primary cp-btn-sm" onclick="cpOpenAdhocModal('title')">
                <i class="fas fa-plus"></i> Add Title
            </button>
        </div>
        <?php elseif ($courtSt === 'published'): ?>
        <!-- QW#6: walk-on adds while published — new rows insert as 'planned' at the end. -->
        <div style="display:flex;gap:8px" id="cp-published-add-tools">
            <?php if (!empty($pendingRecs)): ?>
            <button class="cp-btn-outline cp-btn-sm" onclick="cpOpenRecModal()" data-tip="Add a walk-on recipient from recommendations">
                <i class="fas fa-star"></i> Add from Rec
            </button>
            <?php endif; ?>
            <button class="cp-btn-primary cp-btn-sm" onclick="cpOpenAdhocModal('award')" data-tip="Add a walk-on award (inserts as Planned)">
                <i class="fas fa-plus"></i> Add Award
            </button>
            <button class="cp-btn-primary cp-btn-sm" onclick="cpOpenAdhocModal('title')" data-tip="Add a walk-on title (inserts as Planned)">
                <i class="fas fa-plus"></i> Add Title
            </button>
        </div>
        <?php endif; ?>
        <button class="cp-btn cp-btn-outline" id="cp-script-btn" onclick="cpOpenScript()">
            <i class="fas fa-scroll"></i> Court Script
        </button>
    </div>

    <!-- Spreadsheet toolbar -->
    <div class="cp-list-toolbar">
        <span class="cp-list-toolbar-label"><i class="fas fa-th-large" style="margin-right:5px"></i>View</span>
        <div class="cp-density-seg" id="cp-density-seg" role="group" aria-label="Row density">
            <button type="button" data-density="cozy"        onclick="cpSetDensity('cozy')"        data-tip="Cozy — extra spacing"><i class="fas fa-bars"></i> Cozy</button>
            <button type="button" data-density="comfortable" onclick="cpSetDensity('comfortable')" data-tip="Comfortable — balanced spacing"><i class="fas fa-grip-lines"></i> Comfortable</button>
            <button type="button" data-density="compact"     onclick="cpSetDensity('compact')"     data-tip="Compact — dense rows"><i class="fas fa-minus"></i> Compact</button>
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
            <div class="cp-hdr-scroll" data-tip="Scroll"><i class="fas fa-print"></i></div>
            <div class="cp-hdr-regalia" data-tip="Regalia"><i class="fas fa-medal"></i></div>
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
                $typeTip   = 'Title or peerage — a bestowed title/rank.';
            } elseif ($aw['IsLadder']) {
                $typeClass = 'cp-type-ladder'; $typeLabel = 'Ladder';
                $typeTip   = 'Ladder award — given in progressive ranks (Rank 1, 2, 3 …).';
            } else {
                $typeClass = 'cp-type-award';  $typeLabel = 'Award';
                $typeTip   = 'Standard award — a one-off honor, not ranked.';
            }
        ?>
        <div class="cp-award-row<?= $ast === 'given' ? ' cp-granted' : ($ast === 'cancelled' ? ' cp-skipped' : ($ast === 'staged' ? ' cp-staged' : '')) ?> cp-aw-type-<?= $aw['IsTitle'] ? 'title' : ($aw['IsLadder'] ? 'ladder' : 'award') ?>"
             id="cp-aw-<?= (int)$aw['CourtAwardId'] ?>"
             data-court-award-id="<?= (int)$aw['CourtAwardId'] ?>"
             data-rowversion="<?= (int)($aw['RowVersion'] ?? 0) ?>"
             data-sort="<?= (int)$aw['SortOrder'] ?>">
            <div class="cp-award-row-main cp-row-grid" onclick="cpToggleAward(<?= (int)$aw['CourtAwardId'] ?>)">
                <div class="cp-cell cp-cell-order">
                    <?php if ($courtSt === 'draft'): ?>
                    <span class="cp-award-drag" data-tip="Drag to reorder" aria-label="Drag to reorder" onclick="event.stopPropagation()"><i class="fas fa-grip-vertical"></i></span>
                    <?php endif; ?>
                    <div class="cp-reorder-btns">
                        <button class="cp-reorder-btn" data-tip="Move up" aria-label="Move award up" onclick="event.stopPropagation();cpMoveAward(<?= (int)$aw['CourtAwardId'] ?>,-1)">&#9650;</button>
                        <button class="cp-reorder-btn" data-tip="Move down" aria-label="Move award down" onclick="event.stopPropagation();cpMoveAward(<?= (int)$aw['CourtAwardId'] ?>,1)">&#9660;</button>
                    </div>
                </div>
                <div class="cp-cell cp-cell-num"><?= $_rowIndex ?></div>
                <div class="cp-cell cp-cell-recipient cp-award-name">
                    <span class="cp-recipient-name"><?= htmlspecialchars($aw['Persona']) ?></span>
                    <?php if (!empty($aw['ParkAbbrev'])): ?><span class="cp-award-park"><?= htmlspecialchars($aw['ParkAbbrev']) ?></span><?php endif; ?>
                    <?php if (!empty($aw['Notes'])): ?><button class="cp-note-btn" data-note="<?= htmlspecialchars($aw['Notes']) ?>" onclick="event.stopPropagation();cpShowNote(this)" data-tip="View note" aria-label="View internal note"><i class="fas fa-comment-alt"></i></button><?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-award">
                    <span class="cp-award-name-text"><?= htmlspecialchars($aw['AwardName']) ?></span>
                    <?php if ($aw['IsLadder'] && $aw['Rank'] > 0): ?><span class="ladder-rank cp-award-rank" data-lvl="<?= min((int)$aw['Rank'], 10) ?>">Rank <?= (int)$aw['Rank'] ?></span><?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-type">
                    <span class="<?= $typeClass ?>" data-tip="<?= htmlspecialchars($typeTip) ?>"><?= $typeLabel ?></span>
                </div>
                <div class="cp-cell cp-cell-flags cp-award-flags">
                    <?php if ($aw['PassToLocal']): ?><span class="cp-flag-local" data-tip="Pass to Local — this award will be handed down to the recipient's home park to grant at their court."><i class="fas fa-arrow-down"></i></span><?php endif; ?>
                    <?php if ($aw['RecommendationsId']): ?><span class="cp-flag-rec" data-tip="This award came from a submitted recommendation."><i class="fas fa-star"></i></span><?php endif; ?>
                </div>
                <div class="cp-cell cp-cell-scroll">
                    <span class="cp-tracking-icon" data-tip="<?= htmlspecialchars(cp_track_label('scroll', $aw['ScrollStatus'])) ?>" aria-label="<?= htmlspecialchars(cp_track_label('scroll', $aw['ScrollStatus'])) ?>" data-type="scroll" data-status="<?= (int)$aw['ScrollStatus'] ?>" onclick="cpUpdateTracking(event, <?= (int)$aw['CourtAwardId'] ?>, 'scroll', this)"><i class="fas fa-print"></i></span>
                </div>
                <div class="cp-cell cp-cell-regalia">
                    <span class="cp-tracking-icon" data-tip="<?= htmlspecialchars(cp_track_label('regalia', $aw['RegaliaStatus'])) ?>" aria-label="<?= htmlspecialchars(cp_track_label('regalia', $aw['RegaliaStatus'])) ?>" data-type="regalia" data-status="<?= (int)$aw['RegaliaStatus'] ?>" onclick="cpUpdateTracking(event, <?= (int)$aw['CourtAwardId'] ?>, 'regalia', this)"><i class="fas fa-medal"></i></span>
                </div>
                <div class="cp-cell cp-cell-status">
                    <span class="cp-aw-badge" style="background:<?= $abg ?>;color:<?= $aclr ?>"><?= $albl ?></span>
                    <?php if ($courtSt === 'published' && $ast === 'staged'): ?>
                    <div class="cp-grant-actions" onclick="event.stopPropagation()">
                        <button class="cp-btn-undo" onclick="cpUnstageAward(<?= (int)$aw['CourtAwardId'] ?>)" data-tip="Un-stage — return to planned (nothing is recorded until finalize)"><i class="fas fa-undo"></i> Undo</button>
                    </div>
                    <?php elseif ($courtSt === 'published' && !in_array($ast, ['given','cancelled'])): ?>
                    <div class="cp-grant-actions" onclick="event.stopPropagation()">
                        <button class="cp-btn-grant" onclick="cpGrantAward(<?= (int)$aw['CourtAwardId'] ?>)"><i class="fas fa-check"></i> Grant</button>
                        <button class="cp-btn-skip" onclick="cpSkipAward(<?= (int)$aw['CourtAwardId'] ?>)"><i class="fas fa-forward"></i> Skip</button>
                    </div>
                    <?php elseif ($courtSt === 'published' && $ast === 'cancelled'): ?>
                    <!-- QW#7: inline un-skip returns a skipped row to planned (guarded server-side). -->
                    <div class="cp-grant-actions" onclick="event.stopPropagation()">
                        <span class="cp-grant-static" style="font-size:12px;color:#718096;font-weight:700"><i class="fas fa-forward"></i> Skipped</span>
                        <button class="cp-btn-unskip" onclick="cpUnskipAward(<?= (int)$aw['CourtAwardId'] ?>)" data-tip="Un-skip — return this award to planned" aria-label="Un-skip award, return to planned"><i class="fas fa-undo"></i> Un-skip</button>
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
                                  aria-label="Internal notes (not public)"
                                  placeholder="Monarchy notes (not public)…"><?= htmlspecialchars($aw['Notes']) ?></textarea>
                        <?php
                        $pcRecReason = $aw['RecReason'] ?? '';
                        $pcSaved     = $aw['PublicComment'] ?? '';
                        $pcTriggered = $pcRecReason !== '' && $pcSaved === '';
                        $pcCaid      = (int)$aw['CourtAwardId'];
                        ?>
                        <div class="cp-pc-label-row" style="margin-top:10px">
                            <span class="cp-expand-label" style="margin-bottom:0">Public Comment</span>
                            <?php if ($pcTriggered): ?>
                            <button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(<?= $pcCaid ?>,'start')">(Start from Rec)</button>
                            <button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(<?= $pcCaid ?>,'clear')">(Clear)</button>
                            <?php endif; ?>
                        </div>
                        <div class="cp-pubcomment-wrap" id="cp-pcwrap-<?= $pcCaid ?>" data-rec-engaged="0">
                            <textarea class="cp-notes-area" id="cp-pubcomment-<?= $pcCaid ?>"
                                      aria-label="Public comment (shown on the Court Report)"
                                      placeholder="Shown on the public Court Report…"<?= $pcTriggered ? ' onfocus="cpRecHintFocus(' . $pcCaid . ')"' : '' ?>><?= htmlspecialchars($pcSaved) ?></textarea>
                            <?php if ($pcTriggered): ?>
                            <div class="cp-rec-hint" id="cp-rec-hint-<?= $pcCaid ?>"><?= htmlspecialchars($pcRecReason) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <?php if (($court['ParkId'] ?? 0) == 0): ?>
                            <?php if ($aw['RecommendationsId']): ?>
                            <div class="cp-expand-label">Pass to Local</div>
                            <button type="button" class="cp-btn-sm cp-btn-outline cp-send-local-btn" style="margin-top:4px"
                                    data-tip="Would you rather this award be given by their local park? Click here to remove from this Court and send to the local monarchy."
                                    onclick="cpSendToLocal(<?= (int)$aw['CourtAwardId'] ?>)"><i class="fas fa-arrow-down"></i> Send to Local Park</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="cp-expand-label">Pass to Local</div>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px">
                                <input type="checkbox" id="cp-ptl-<?= (int)$aw['CourtAwardId'] ?>"
                                       <?= $aw['PassToLocal'] ? 'checked' : '' ?>
                                       style="width:auto">
                                <span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Scroll / Regalia Makers -->
                <div class="cp-expand-grid" style="margin-top:8px">
                    <div>
                        <div class="cp-expand-label">Scroll Maker</div>
                        <div style="position:relative">
                            <input type="text" id="cp-scroll-maker-text-<?= (int)$aw['CourtAwardId'] ?>"
                                   class="cp-maker-ac" data-drop="cp-scroll-drop-<?= (int)$aw['CourtAwardId'] ?>" data-hidden="cp-scroll-maker-id-<?= (int)$aw['CourtAwardId'] ?>"
                                   aria-label="Scroll maker — search by persona"
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
                                   aria-label="Regalia maker — search by persona"
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
                            <button class="cp-btn-danger-sm" data-tip="Remove artisan" aria-label="Remove artisan"
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
    <div class="cp-modal" style="max-width:660px" role="dialog" aria-modal="true" aria-labelledby="cp-rec-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-rec-modal-title"><i class="fas fa-star" style="color:#d69e2e;margin-right:8px"></i>Add from Recommendations</h3>
            <button class="cp-modal-close" onclick="cpCloseRecModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body" style="padding-bottom:8px">
            <div class="cp-rm-search-wrap">
                <i class="fas fa-search"></i>
                <input class="cp-rm-search" id="cp-rm-filter" type="text" placeholder="Filter by name or award…" oninput="cpRmFilter()" autocomplete="off">
            </div>
            <div class="cp-rm-controls">
                <span class="cp-rm-sort-label">View:</span>
                <button class="cp-rm-view-btn active" data-view="open"     onclick="cpRmView('open')"     data-tip="Open recs ready to grant (hides already-qualified and snoozed)">Open</button>
                <button class="cp-rm-view-btn"        data-view="all"      onclick="cpRmView('all')"      data-tip="Every eligible rec for this kingdom/park">All</button>
                <button class="cp-rm-view-btn"        data-view="snoozed"  onclick="cpRmView('snoozed')"  data-tip="Recs the monarchy has set aside for this regnum">Snoozed</button>
                <button class="cp-rm-view-btn"        data-view="already"  onclick="cpRmView('already')"  data-tip="Player already has this award at or above the recommended rank">Already Has</button>
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
                            <span class="cp-rm-age-badge <?= $ageCls ?>" data-tip="Age of this recommendation"><?= $ageLbl ?></span>
                            <?php endif; ?>
                            <?php if (!empty($rec['SecondsCount'])): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-seconds" data-tip="<?= (int)$rec['SecondsCount'] ?> supporting <?= (int)$rec['SecondsCount'] === 1 ? 'second' : 'seconds' ?>"><i class="fas fa-thumbs-up"></i><?= (int)$rec['SecondsCount'] ?></span>
                            <?php endif; ?>
                            <?php if ($isOnOther): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-onother" data-tip="This recommendation is on another court plan"><i class="fas fa-scroll"></i> On another court</span>
                            <?php endif; ?>
                            <?php if ($alreadyHas): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-qualified" data-tip="<?= htmlspecialchars($qualifiedTip) ?>"><i class="fas fa-check-circle"></i> <?= $coveredMaster ? 'Covered by Master' : 'Already has' ?></span>
                            <?php endif; ?>
                            <?php if ($isSnoozed): ?>
                            <span class="cp-rm-sep">&middot;</span>
                            <span class="cp-rm-snooze-chip" data-tip="Snoozed for the current regnum"><i class="fas fa-bell-slash"></i> Snoozed</span>
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
    <div class="cp-modal cp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cp-adhoc-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-adhoc-modal-title"><i class="fas fa-award" style="margin-right:8px;color:#4a5568"></i>Add Award to Court</h3>
            <button class="cp-modal-close" onclick="cpCloseAdhocModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body">
            <div class="cp-field">
                <label for="cp-adhoc-persona">Recipient <span style="color:#e53e3e">*</span></label>
                <div class="cp-ac-wrap">
                    <input type="text" id="cp-adhoc-persona" placeholder="Search player name…" autocomplete="off" aria-required="true" oninput="cpAcSearch(this,'cp-adhoc-ac','cp-adhoc-mundane-id')">
                    <div class="cp-ac-dropdown" id="cp-adhoc-ac"></div>
                </div>
                <input type="hidden" id="cp-adhoc-mundane-id">
            </div>
            <div class="cp-field">
                <label for="cp-adhoc-award-search" id="cp-adhoc-award-label">Award <span style="color:#e53e3e">*</span></label>
                <div class="cp-ac-wrap">
                    <input type="text" id="cp-adhoc-award-search" placeholder="Search awards &amp; titles…" autocomplete="off" aria-required="true" data-ladder="0" oninput="cpAwardSearch()" onfocus="cpAwardSearch()">
                    <div class="cp-ac-dropdown" id="cp-adhoc-award-ac"></div>
                </div>
                <input type="hidden" id="cp-adhoc-award-id">
            </div>
            <div class="cp-field" id="cp-adhoc-rank-wrap" style="display:none">
                <label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px;text-transform:none;letter-spacing:0">— select the rank being awarded</span></label>
                <div class="cp-rank-pills" id="cp-adhoc-rank-pills"></div>
                <input type="hidden" id="cp-adhoc-rank-val" value="">
            </div>
            <div class="cp-field">
                <label for="cp-adhoc-notes">Internal Notes</label>
                <textarea id="cp-adhoc-notes" rows="3" placeholder="Monarchy notes (not public)…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
            <div class="cp-field">
                <label for="cp-adhoc-pubcomment">Public Comment</label>
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
                <i class="fas fa-plus"></i> Add to Plan
            </button>
        </div>
    </div>
</div>

<!-- Add Artisan Modal -->
<div class="cp-overlay" id="cp-artisan-modal">
    <div class="cp-modal cp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cp-artisan-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-artisan-modal-title"><i class="fas fa-paint-brush" style="color:#9f7aea;margin-right:8px"></i>Add Artisan</h3>
            <button class="cp-modal-close" onclick="cpCloseArtisanModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body">
            <div class="cp-field">
                <label for="cp-art-persona">Artisan <span style="color:#e53e3e">*</span></label>
                <div class="cp-ac-wrap">
                    <input type="text" id="cp-art-persona" placeholder="Search player name…" autocomplete="off" aria-required="true" oninput="cpAcSearch(this,'cp-art-ac','cp-art-mundane-id')">
                    <div class="cp-ac-dropdown" id="cp-art-ac"></div>
                </div>
                <input type="hidden" id="cp-art-mundane-id">
            </div>
            <div class="cp-field">
                <label for="cp-art-contribution">Contribution</label>
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

<!-- Grant Award Modal (stage on confirm — spec §6.1) -->
<div class="cp-overlay" id="cp-grant-modal">
    <div class="cp-modal cp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cp-grant-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-grant-modal-title"><i class="fas fa-check-circle" style="margin-right:8px;color:#276749"></i>Grant Award</h3>
            <button class="cp-modal-close" onclick="cpCloseGrantModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body">
            <input type="hidden" id="cp-grant-caid">
            <div class="cp-field">
                <label>Recipient</label>
                <div class="cp-grant-ro">
                    <div id="cp-grant-recipient"></div>
                    <div class="cp-grant-ro-award" id="cp-grant-award"></div>
                </div>
            </div>
            <div class="cp-row-2">
                <div class="cp-field" id="cp-grant-rank-wrap">
                    <label for="cp-grant-rank">Rank</label>
                    <input type="number" id="cp-grant-rank" min="1" max="99" value="1">
                </div>
                <div class="cp-field">
                    <label for="cp-grant-date">Date</label>
                    <input type="text" id="cp-grant-date" readonly>
                </div>
            </div>
            <div class="cp-field">
                <label for="cp-grant-giver-text">Given By <span style="color:#e53e3e">*</span></label>
                <div class="cp-giver-pills" id="cp-grant-giver-pills"></div>
                <div class="cp-ac-wrap">
                    <input type="text" id="cp-grant-giver-text" placeholder="Search for another giver…" autocomplete="off"
                           aria-required="true" oninput="cpGiverSearchInput(this)">
                    <div class="cp-ac-dropdown" id="cp-grant-giver-ac"></div>
                </div>
                <input type="hidden" id="cp-grant-giver-id">
            </div>
            <div class="cp-field">
                <label for="cp-grant-reason">Reason / Citation</label>
                <textarea id="cp-grant-reason" rows="3" placeholder="Citation shown on the public Court Report…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box"></textarea>
            </div>
            <div class="cp-error" id="cp-grant-error"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpCloseGrantModal()">Cancel</button>
            <button class="cp-btn-primary" id="cp-grant-confirm" onclick="cpGrantConfirm()" style="background:#276749">
                <i class="fas fa-check"></i> Stage Grant
            </button>
        </div>
    </div>
</div>

<!-- Publish choice: Run vs Plan (spec §5.2) -->
<div class="cp-overlay" id="cp-publish-modal">
    <div class="cp-modal cp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cp-publish-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-publish-modal-title"><i class="fas fa-bullhorn" style="margin-right:8px;color:#2b6cb0"></i>Publish Court</h3>
            <button class="cp-modal-close" onclick="cpClosePublishModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body">
            <p class="cp-modal-lead">How will this court be run? You can switch modes at any time after publishing.</p>
            <div class="cp-complete-opts">
                <div class="cp-complete-opt cp-co-run" onclick="cpDoPublish('run')">
                    <i class="fas fa-bullhorn"></i>
                    <div>
                        <div class="cp-co-title">Run at Court</div>
                        <div class="cp-co-desc">Live ceremony — grant or skip each award as the herald calls it. Multiple officers can run court together and stay in sync automatically.</div>
                    </div>
                </div>
                <div class="cp-complete-opt cp-co-plan" onclick="cpDoPublish('plan')">
                    <i class="fas fa-clipboard-list"></i>
                    <div>
                        <div class="cp-co-title">Lock as Plan</div>
                        <div class="cp-co-desc">Print the order of court now; a different officer records the grants later with one bulk action.</div>
                    </div>
                </div>
            </div>
            <div class="cp-error" id="cp-publish-error" style="margin-top:10px"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpClosePublishModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Complete-court modal (spec §6.6) -->
<div class="cp-overlay" id="cp-complete-modal">
    <div class="cp-modal cp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cp-complete-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-complete-modal-title"><i class="fas fa-stamp" style="margin-right:8px;color:#276749"></i>Complete Court</h3>
            <button class="cp-modal-close" onclick="cpCloseCompleteModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body">
            <p class="cp-modal-lead" id="cp-complete-lead"></p>
            <div class="cp-complete-opts" id="cp-complete-opts"></div>
            <div class="cp-complete-fail" id="cp-complete-fail"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpCloseCompleteModal()">Go Back</button>
        </div>
    </div>
</div>

<div id="cp-note-popup" style="position:fixed">
    <div id="cp-note-popup-header">
        <span id="cp-note-popup-title">Monarchy Note</span>
        <button id="cp-note-popup-close" onclick="cpDismissNote()" data-tip="Close" aria-label="Close note">&times;</button>
    </div>
    <span id="cp-note-popup-text"></span>
</div>

<script>
(function() {
    var uir      = '<?= UIR ?>';
    var courtId     = <?= (int)($court['CourtId'] ?? 0) ?>;
    var courtIsKingdom = <?= ($court['ParkId'] ?? 0) == 0 ? 'true' : 'false' ?>;
    var kidId       = <?= (int)($court['KingdomId'] ?? 0) ?>;
    var courtStatus = <?= json_encode($court['Status'] ?? 'draft') ?>;
    var courtAwards = window.courtAwards = <?= json_encode($courtAwards) ?>;
    var courtMeta   = window.courtMeta   = { name: <?= json_encode($court['Name'] ?? '') ?>, date: <?= json_encode($court['CourtDate'] ?? '') ?> };
    var currentArtisanCourtAwardId = 0;

    // Ad-hoc "Add Award to Court" picker options (typeable autocomplete). Emitted
    // once from Model_Award::fetch_award_option_groups() — the SAME grouping the
    // player Add Award modal uses. Flattened to {id,name,ladder,title,group},
    // preserving canonical group + within-group order; the search renders these
    // under .cp-ac-group headers per the modal's mode (award vs title).
    var cpAwardOptions = <?= json_encode((function ($groups) {
        $flat = [];
        foreach (($groups ?? []) as $g) {
            foreach (($g['options'] ?? []) as $o) {
                $flat[] = [
                    'id'     => (int)$o['KingdomAwardId'],
                    'name'   => $o['Name'],
                    'ladder' => (bool)$o['IsLadder'],
                    'title'  => (bool)$o['IsTitle'],
                    'group'  => $g['label'],
                ];
            }
        }
        return $flat;
    })($awardOpts)) ?>;
    // Group sets per modal mode, in canonical display order (skip-empty at render).
    var CP_AWARD_GROUPS = ['Ladder Awards', 'Other', 'Custom Award'];
    var CP_TITLE_GROUPS = ['Knighthoods', 'Masterhoods', 'Paragons', 'Noble Titles', 'Associate Titles', 'Custom Title'];

    // ---- Stage/finalize planner state (spec §6) ----
    var cpGiverOptions = window.cpGiverOptions = <?= json_encode($giverOptions) ?>;
    var cpMode         = window.cpMode         = <?= json_encode($courtMode) ?>;
    var cpStagedCount  = window.cpStagedCount  = <?= (int)$stagedCount ?>;
    var cpPrevSkipped  = window.cpPrevSkipped  = <?= json_encode($prevSkipped) ?>;
    var cpStateVersion = <?= json_encode($stateVersion) ?>;
    // Human-readable court date for the grant modal (F j, Y).
    var cpCourtDateHuman = <?= json_encode($court['CourtDate'] ? date('F j, Y', strtotime($court['CourtDate'])) : '') ?>;

    // Per-status badge appearance, mirrored from the PHP $awardStatus* maps.
    var CP_AW_BADGE = {
        planned:   { bg: '#edf2f7', color: '#4a5568', label: 'Planned' },
        announced: { bg: '#ebf8ff', color: '#2b6cb0', label: 'Announced' },
        staged:    { bg: '#fffbeb', color: '#b7791f', label: 'Staged' },
        given:     { bg: '#f0fff4', color: '#276749', label: 'Given' },
        cancelled: { bg: '#fff5f5', color: '#c53030', label: 'Skipped' }
    };

    // ---- Utilities ----
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    window.esc = esc;
    function gid(id) { return document.getElementById(id); }

    // Transient error toast — the shared failure surface for AJAX/network problems.
    function cpGlobalError(msg) {
        var t = document.createElement('div');
        t.className = 'cp-toast';
        // QW#8: errors are assertive so screen readers announce them immediately.
        t.setAttribute('role', 'alert');
        t.setAttribute('aria-live', 'assertive');
        t.textContent = msg || 'Something went wrong. Please try again.';
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 5000);
    }
    window.cpGlobalError = cpGlobalError;

    // Non-blocking message dialog (replaces native alert(), which freezes automation).
    function cpAlert(msg, title) {
        if (typeof tnConfirm === 'function') tnConfirm({ title: title || 'Court Planner', body: msg, confirmLabel: 'OK' });
        else cpGlobalError(msg);
    }
    window.cpAlert = cpAlert;

    // Confirmation dialog (replaces native confirm()). Mirrors the tnConfirm pattern
    // already used by cpSendToLocal / cpDismissRec, with a native fallback if unloaded.
    function cpConfirm(opts) {
        if (typeof tnConfirm === 'function') tnConfirm(opts);
        else cpFallbackConfirm(opts);
    }

    // QW#9: non-blocking confirm used when tnConfirm isn't loaded on this page. Native
    // confirm() freezes the in-app browser, so we build a lightweight overlay dialog that
    // reuses the .cp-overlay/.cp-modal styling. No window.confirm/alert/prompt anywhere.
    function cpFallbackConfirm(opts) {
        opts = opts || {};
        var ov = document.createElement('div');
        ov.className = 'cp-overlay';
        ov.style.display = 'flex';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.innerHTML =
            '<div class="cp-modal cp-modal-sm">' +
              '<div class="cp-modal-header"><h3>' + esc(opts.title || 'Confirm') + '</h3>' +
                '<button class="cp-modal-close" type="button" aria-label="Close" data-cp-cancel>&times;</button></div>' +
              '<div class="cp-modal-body"><p class="cp-modal-lead">' + esc(opts.body || '') + '</p></div>' +
              '<div class="cp-modal-footer">' +
                '<button class="cp-btn-outline" type="button" data-cp-cancel>' + esc(opts.cancelLabel || 'Cancel') + '</button>' +
                '<button class="cp-btn-primary" type="button" data-cp-ok' + (opts.danger ? ' style="background:#c53030"' : '') + '>' + esc(opts.confirmLabel || 'OK') + '</button>' +
              '</div>' +
            '</div>';
        function close() { ov.remove(); }
        ov.addEventListener('click', function(e) {
            if (e.target === ov || e.target.closest('[data-cp-cancel]')) { close(); return; }
            if (e.target.closest('[data-cp-ok]')) { close(); if (typeof opts.onConfirm === 'function') opts.onConfirm(); }
        });
        document.body.appendChild(ov);
        var okBtn = ov.querySelector('[data-cp-ok]');
        if (okBtn) setTimeout(function() { okBtn.focus(); }, 30);
    }

    // `silent` (S5): background polls (the heartbeat) pass true so a transient
    // network blip never raises the red error toast — only user-initiated actions do.
    function post(url, fd, silent) {
        return fetch(uir + url, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).catch(function(err) {
            // Surface fetch/JSON failures instead of silently no-op'ing, and resolve to a
            // sentinel so callers' .then handlers still run (and Promise.all doesn't hang).
            if (!silent) cpGlobalError('Could not reach the server. Please check your connection and try again.');
            return { status: -1, error: 'Request failed. Please try again.', _postFailed: true };
        });
    }

    // Neutral (non-error) toast — used by the optimistic-lock reload path so a stale
    // row never looks like a failure. Shares the .cp-toast base; .cp-toast-info is a
    // Phase-3b styling hook (falls back to the base toast look until then).
    function cpNotice(msg) {
        var t = document.createElement('div');
        t.className = 'cp-toast cp-toast-info';
        // QW#8: informational, so polite (never interrupts, but is announced).
        t.setAttribute('role', 'status');
        t.setAttribute('aria-live', 'polite');
        t.textContent = msg || '';
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 4000);
    }

    // ---- Optimistic-lock (S5) row_version helpers ----
    // Every mutating write bumps ork_court_award.row_version; the client threads its
    // last-known token on guarded POSTs so a stale edit is rejected (status 9) instead
    // of clobbering a newer change.
    function cpGetRowVersion(caid) {
        var row = gid('cp-aw-' + caid);
        if (row && row.dataset.rowversion !== undefined && row.dataset.rowversion !== '') {
            return parseInt(row.dataset.rowversion, 10) || 0;
        }
        var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
        return a && a.RowVersion != null ? (parseInt(a.RowVersion, 10) || 0) : 0;
    }
    function cpSetRowVersion(caid, v) {
        v = parseInt(v, 10) || 0;
        var row = gid('cp-aw-' + caid);
        if (row) row.dataset.rowversion = v;
        var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
        if (a) a.RowVersion = v;
    }
    // After a successful guarded write we hold the winning token, so the server row is
    // now exactly old+1. Bump locally to stay fresh (the heartbeat also reconciles it).
    function cpBumpRowVersion(caid) { cpSetRowVersion(caid, cpGetRowVersion(caid) + 1); }

    // status===9 handler: a guarded write was rejected because our token was stale.
    // Do NOT apply the optimistic change; tell the user softly and refetch the truth.
    function cpStale(caid) {
        cpNotice('This row changed — reloading…');
        cpHeartbeatPoll(true);
    }

    // ---- Court status ----
    window.cpAdvanceStatus = function(newStatus) {
        // Leaving draft → choose run/plan (spec §5.2); completing → finalize flow (spec §6.6).
        if (newStatus === 'published') { cpOpenPublishModal(); return; }
        if (newStatus === 'complete')  { cpOpenCompleteModal(); return; }
        cpConfirm({
            title: 'Update court status',
            body: 'Mark this court as "' + newStatus + '"?',
            confirmLabel: 'Confirm',
            onConfirm: function() {
                var fd = new FormData();
                fd.append('CourtId', courtId);
                fd.append('Status',  newStatus);
                post('CourtAjax/update_court_status', fd).then(function(d) {
                    if (d.status === 0) location.reload();
                    else if (!d._postFailed) cpAlert(d.error || 'Could not update status.');
                });
            }
        });
    };

    window.cpReturnToPlanning = function(newStatus) {
        cpConfirm({
            title: 'Return to planning',
            body: 'Return this court to "' + newStatus + '" status?',
            confirmLabel: 'Confirm',
            onConfirm: function() {
                var fd = new FormData();
                fd.append('CourtId', courtId);
                fd.append('Status',  newStatus);
                post('CourtAjax/update_court_status', fd).then(function(d) {
                    if (d.status === 0) location.reload();
                    else if (!d._postFailed) cpAlert(d.error || 'Could not update status.');
                });
            }
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
            var sbLabel = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            btn.dataset.tip = sbLabel;
            btn.setAttribute('aria-label', sbLabel);
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
                btn.dataset.tip = 'Expand sidebar';
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

    // QW#8: state-aware label for the tracking glyphs (mirrors PHP cp_track_label) so the
    // scroll/regalia state is conveyed by text/aria too, not color alone.
    function cpTrackLabel(type, status) {
        var noun = type === 'scroll' ? 'Scroll' : 'Regalia';
        var s = parseInt(status, 10) || 0;
        if (s === 2) return noun + ': done';
        if (s === 1) return noun + (type === 'scroll' ? ': in progress (needs printing)' : ': in progress (needs token)');
        return noun + ': not tracked';
    }

    window.cpUpdateTracking = function(event, caid, type, element) {
        event.stopPropagation();
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        fd.append('Type', type);

        post('CourtAjax/update_award_tracking_status', fd).then(function(d) {
            if (d.status === 0) {
                element.dataset.status = d.newStatus;
                // Keep the tooltip + screen-reader label describing the CURRENT state.
                var lbl = cpTrackLabel(type, d.newStatus);
                element.dataset.tip = lbl;
                element.setAttribute('aria-label', lbl);
                const award = courtAwards.find(a => a.CourtAwardId === caid);
                if (award) {
                    if (type === 'scroll') award.ScrollStatus = d.newStatus;
                    else award.RegaliaStatus = d.newStatus;
                }
                cpRefreshStatusBar();
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not update tracking status.');
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
        post('CourtAjax/reorder_awards', fd).then(function(d) {
            // Report server-side reorder failures; network failures already toast via post().
            if (d && d.status !== 0 && !d._postFailed) {
                cpAlert(d.error || 'Could not save the new order. Please refresh and try again.');
            }
        });
    }

    // ---- Save award (notes, pass_to_local, status) ----
    window.cpSaveAward = function(caid) {
        var notes          = gid('cp-notes-' + caid).value;
        var pubCommentEl    = gid('cp-pubcomment-' + caid);
        var publicComment   = pubCommentEl ? pubCommentEl.value : '';
        var ptlEl          = gid('cp-ptl-' + caid);
        var ptl            = ptlEl ? (ptlEl.checked ? 1 : 0) : 0;
        var scrollMakerEl  = gid('cp-scroll-maker-id-'  + caid);
        var regaliaMakerEl = gid('cp-regalia-maker-id-' + caid);
        var fd     = new FormData();
        fd.append('CourtAwardId',  caid);
        fd.append('Notes',         notes);
        fd.append('PublicComment', publicComment);
        fd.append('PassToLocal',   ptl);
        // QW#4: update_award writes FIELDS only — never status (the server ignores it
        // now). Lifecycle moves solely through grant/skip/stage/set-status, so a stale
        // field-save can no longer drag a row's status backward.
        fd.append('ScrollMakerId',  scrollMakerEl  ? (parseInt(scrollMakerEl.value,  10) || 0) : 0);
        fd.append('RegaliaMakerId', regaliaMakerEl ? (parseInt(regaliaMakerEl.value, 10) || 0) : 0);
        // S5 optimistic lock: thread our last-known row_version so a stale save is rejected.
        fd.append('RowVersion',    cpGetRowVersion(caid));
        post('CourtAjax/update_award', fd).then(function(d) {
            if (d && d.status === 9) { cpStale(caid); return; }
            if (d.status === 0) {
                // Guarded write won: the server row is now old+1. update_award does not
                // return the new token, so bump locally (the heartbeat also reconciles it).
                cpBumpRowVersion(caid);
                // Update Pass-to-Local badge in row header
                var flagsEl = document.querySelector('#cp-aw-' + caid + ' .cp-award-flags');
                if (flagsEl) {
                    var existing = flagsEl.querySelector('.cp-flag-local');
                    if (ptl && !existing) {
                        var span = document.createElement('span');
                        span.className = 'cp-flag-local';
                        span.dataset.tip = 'Pass to Local — this award will be handed down to the recipient\'s home park to grant at their court.';
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
                            nb.dataset.tip = 'View note';
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
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not save.');
            }
        });
    };

    // ---- Pass to Local control + Send-to-Local action (kingdom/principality courts) ----
    function cpPtlControlHtml(caid, recId, passToLocal) {
        if (courtIsKingdom) {
            if (!recId) return '';
            return '<div class="cp-expand-label">Pass to Local</div>' +
                '<button type="button" class="cp-btn-sm cp-btn-outline cp-send-local-btn" style="margin-top:4px" ' +
                'data-tip="Would you rather this award be given by their local park? Click here to remove from this Court and send to the local monarchy." ' +
                'onclick="cpSendToLocal(' + caid + ')"><i class="fas fa-arrow-down"></i> Send to Local Park</button>';
        }
        return '<div class="cp-expand-label">Pass to Local</div>' +
            '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:4px">' +
            '<input type="checkbox" id="cp-ptl-' + caid + '" style="width:auto"' + (passToLocal ? ' checked' : '') + '>' +
            '<span style="font-size:13px;color:#4a5568">Kingdom approves — Park to give</span></label>';
    }

    function cpRemoveAwardRow(caid) {
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
        if (typeof cpRenumberRows === 'function') cpRenumberRows();
    }

    window.cpSendToLocal = function(caid) {
        var body = 'Would you rather this award be given by their local park? This removes it from this Court and sends it to the local monarchy.';
        function doSend() {
            var fd = new FormData();
            fd.append('CourtAwardId', caid);
            post('CourtAjax/pass_award_to_local', fd).then(function(d) {
                if (d.status === 0) {
                    cpRemoveAwardRow(caid);
                } else if (!d._postFailed) {
                    // QW#9: no native alert — route through the app's non-blocking dialog.
                    cpAlert(d.error || 'Could not send to local.', 'Could not send to local');
                }
            });
        }
        // QW#9: cpConfirm prefers tnConfirm and falls back to a DOM dialog — never confirm().
        cpConfirm({ title: 'Send to local park?', body: body, confirmLabel: 'Send to Local', danger: true, onConfirm: doSend });
    };

    // ---- Public Comment: rec-reason helper text ----
    function cpPubCommentFieldHtml(caid, recReason, publicComment) {
        recReason     = recReason || '';
        publicComment = publicComment || '';
        var triggered = recReason !== '' && publicComment === '';
        var buttons = triggered
            ? '<button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(' + caid + ',\'start\')">(Start from Rec)</button>' +
              '<button type="button" class="cp-rec-hint-btn" onclick="cpRecHintAction(' + caid + ',\'clear\')">(Clear)</button>'
            : '';
        var taFocus = triggered ? ' onfocus="cpRecHintFocus(' + caid + ')"' : '';
        var hint = triggered
            ? '<div class="cp-rec-hint" id="cp-rec-hint-' + caid + '">' + esc(recReason) + '</div>'
            : '';
        return '<div class="cp-pc-label-row" style="margin-top:10px"><span class="cp-expand-label" style="margin-bottom:0">Public Comment</span>' + buttons + '</div>' +
            '<div class="cp-pubcomment-wrap" id="cp-pcwrap-' + caid + '" data-rec-engaged="0">' +
            '<textarea class="cp-notes-area" id="cp-pubcomment-' + caid + '" placeholder="Shown on the public Court Report…"' + taFocus + '>' + esc(publicComment) + '</textarea>' +
            hint + '</div>';
    }

    function cpRecHintEngage(caid) {
        var wrap = gid('cp-pcwrap-' + caid);
        var hint = gid('cp-rec-hint-' + caid);
        var ta   = gid('cp-pubcomment-' + caid);
        var labelRow = wrap ? wrap.previousElementSibling : null;
        if (labelRow && labelRow.classList.contains('cp-pc-label-row')) {
            labelRow.querySelectorAll('.cp-rec-hint-btn').forEach(function(b) { b.style.display = 'none'; });
        }
        if (ta) ta.removeAttribute('onfocus');
        if (wrap) wrap.dataset.recEngaged = '1';
        if (hint) hint.style.display = 'none';
    }

    window.cpRecHintFocus = function(caid) {
        var wrap = gid('cp-pcwrap-' + caid);
        if (!wrap || wrap.dataset.recEngaged === '1') return;
        var ta   = gid('cp-pubcomment-' + caid);
        var hint = gid('cp-rec-hint-' + caid);
        if (ta && hint && !ta.value) ta.value = hint.textContent;
        cpRecHintEngage(caid);
    };

    window.cpRecHintAction = function(caid, action) {
        var ta   = gid('cp-pubcomment-' + caid);
        var hint = gid('cp-rec-hint-' + caid);
        if (!ta) return;
        if (action === 'start') {
            if (hint) ta.value = hint.textContent;
            cpRecHintEngage(caid);
            ta.focus();
            ta.setSelectionRange(ta.value.length, ta.value.length);
        } else if (action === 'clear') {
            cpRecHintEngage(caid);
            ta.value = '';
            ta.focus();
        }
    };

    // ---- Shared row-status renderer (keeps DOM, badge, and model in sync) ----
    // Rebuilds the published-mode action controls for a row from its status.
    function cpStatusActionsHtml(caid, status) {
        if (status === 'staged') {
            return '<div class="cp-grant-actions" onclick="event.stopPropagation()">' +
                '<button class="cp-btn-undo" onclick="cpUnstageAward(' + caid + ')" data-tip="Un-stage — return to planned (nothing is recorded until finalize)"><i class="fas fa-undo"></i> Undo</button></div>';
        }
        if (status === 'given') {
            return '<span class="cp-grant-static" style="font-size:12px;color:#276749;font-weight:700"><i class="fas fa-check-circle"></i> Granted</span>';
        }
        if (status === 'cancelled') {
            // QW#7: inline Un-skip returns the row to planned (server-guarded vs given rows).
            return '<div class="cp-grant-actions" onclick="event.stopPropagation()">' +
                '<span class="cp-grant-static" style="font-size:12px;color:#718096;font-weight:700"><i class="fas fa-forward"></i> Skipped</span>' +
                '<button class="cp-btn-unskip" onclick="cpUnskipAward(' + caid + ')" data-tip="Un-skip — return this award to planned" aria-label="Un-skip award, return to planned"><i class="fas fa-undo"></i> Un-skip</button></div>';
        }
        // planned / announced
        return '<div class="cp-grant-actions" onclick="event.stopPropagation()">' +
            '<button class="cp-btn-grant" onclick="cpGrantAward(' + caid + ')"><i class="fas fa-check"></i> Grant</button>' +
            '<button class="cp-btn-skip" onclick="cpSkipAward(' + caid + ')"><i class="fas fa-forward"></i> Skip</button></div>';
    }

    window.cpSetRowStatus = function(caid, status) {
        var row = gid('cp-aw-' + caid);
        if (row) {
            row.classList.remove('cp-granted', 'cp-skipped', 'cp-staged');
            if (status === 'given')          row.classList.add('cp-granted');
            else if (status === 'cancelled') row.classList.add('cp-skipped');
            else if (status === 'staged')    row.classList.add('cp-staged');
            var badge = row.querySelector('.cp-aw-badge');
            var b = CP_AW_BADGE[status] || CP_AW_BADGE.planned;
            if (badge) { badge.style.background = b.bg; badge.style.color = b.color; badge.textContent = b.label; }
            var statusCell = row.querySelector('.cp-cell-status');
            if (statusCell) {
                statusCell.querySelectorAll('.cp-grant-actions, .cp-grant-static').forEach(function(e) { e.remove(); });
                if (courtStatus === 'published') statusCell.insertAdjacentHTML('beforeend', cpStatusActionsHtml(caid, status));
            }
            // QW#4: keep the expand-panel status <select> in sync with the lifecycle so a
            // later field-save can't resurrect a stale planned/announced value from the DOM.
            var statusSel = gid('cp-status-' + caid);
            if (statusSel && statusSel.value !== status) statusSel.value = status;
        }
        var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
        if (a) a.Status = status;
        cpRefreshProgress();
    };

    // ---- Staged-not-finalized safeguard indicator (spec §5.3) ----
    // S2: wording follows the mode. Run mode hides the "staged" vocabulary ("N to record
    // on Complete"); plan mode keeps the explicit finalize framing. Either way the button
    // opens the complete flow, which commits via finalize_court.
    window.cpUpdateStagedIndicator = function(count) {
        cpStagedCount = window.cpStagedCount = count;
        var ind = gid('cp-staged-indicator');
        if (!ind) return;
        var txt = ind.querySelector('.cp-si-text');
        var btn = ind.querySelector('.cp-si-btn');
        var plural = count === 1 ? '' : 's';
        if (txt) {
            if (cpMode === 'plan') {
                txt.innerHTML = '<strong>' + count + ' grant' + plural + ' staged</strong>, not yet finalized — ' +
                    'Finalize to record them in the player registry.';
            } else {
                txt.innerHTML = '<strong>' + count + ' to record on Complete</strong> — ' +
                    'these grants are written to the player registry when you complete this court.';
            }
        }
        if (btn) {
            btn.innerHTML = cpMode === 'plan'
                ? '<i class="fas fa-stamp"></i> Finalize &amp; Complete'
                : '<i class="fas fa-check"></i> Complete Court';
        }
        ind.classList.toggle('show', count > 0 && courtStatus !== 'complete');
    };

    // ---- Grant modal (spec §6.1) — stages on confirm, does NOT commit ----
    window.cpGrantAward = function(caid) { cpOpenGrantModal(caid); };

    function cpBuildGiverPills() {
        var wrap = gid('cp-grant-giver-pills');
        if (!wrap) return;
        wrap.innerHTML = '';
        var list = [];
        if (cpGiverOptions && cpGiverOptions.default) list.push(cpGiverOptions.default);
        if (cpGiverOptions && cpGiverOptions.pills) cpGiverOptions.pills.forEach(function(p) { list.push(p); });
        if (!list.length) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'flex';
        list.forEach(function(g) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cp-giver-pill';
            btn.dataset.mundaneId = g.mundane_id;
            btn.innerHTML = esc(g.persona) + ' <span class="cp-giver-role">' + esc(g.role || '') + '</span>';
            btn.onclick = function() { cpGrantPickGiver(g.mundane_id, g.persona); };
            wrap.appendChild(btn);
        });
    }

    function cpMarkActiveGiverPill(mundaneId) {
        var wrap = gid('cp-grant-giver-pills');
        if (!wrap) return;
        wrap.querySelectorAll('.cp-giver-pill').forEach(function(b) {
            b.classList.toggle('active', String(b.dataset.mundaneId) === String(mundaneId));
        });
    }

    window.cpGrantPickGiver = function(mundaneId, persona) {
        gid('cp-grant-giver-text').value = persona;
        gid('cp-grant-giver-id').value = mundaneId;
        cpMarkActiveGiverPill(mundaneId);
        var drop = gid('cp-grant-giver-ac');
        if (drop) { drop.style.display = 'none'; drop.innerHTML = ''; }
    };

    // Wrapper for the giver player-search input: de-highlights the pills when the
    // user types a custom giver, then defers to the shared scoped autocomplete.
    window.cpGiverSearchInput = function(input) {
        cpMarkActiveGiverPill(-1);
        cpAcSearch(input, 'cp-grant-giver-ac', 'cp-grant-giver-id');
    };

    function cpOpenGrantModal(caid) {
        var aw = courtAwards.find(function(a) { return String(a.CourtAwardId) === String(caid); });
        if (!aw) return;
        gid('cp-grant-caid').value = caid;
        gid('cp-grant-recipient').textContent = aw.Persona + (aw.ParkAbbrev ? ' (' + aw.ParkAbbrev + ')' : '');
        gid('cp-grant-award').textContent = aw.AwardName + (aw.IsLadder && aw.Rank ? ' — Rank ' + aw.Rank : '');
        var rankWrap = gid('cp-grant-rank-wrap');
        if (aw.IsLadder) { rankWrap.style.display = ''; gid('cp-grant-rank').value = aw.Rank || 1; }
        else            { rankWrap.style.display = 'none'; gid('cp-grant-rank').value = aw.Rank || 0; }
        gid('cp-grant-date').value = cpCourtDateHuman || 'Today';
        // Reason precedence: saved public comment → originating rec reason → blank.
        gid('cp-grant-reason').value = aw.PublicComment || aw.RecReason || '';
        cpBuildGiverPills();
        var def = cpGiverOptions && cpGiverOptions.default;
        if (def) {
            gid('cp-grant-giver-text').value = def.persona;
            gid('cp-grant-giver-id').value = def.mundane_id;
            cpMarkActiveGiverPill(def.mundane_id);
        } else {
            gid('cp-grant-giver-text').value = '';
            gid('cp-grant-giver-id').value = '';
        }
        gid('cp-grant-error').style.display = 'none';
        // S2: run mode says "Grant" (the stage happens under the hood + auto-finalizes on
        // Complete); plan mode keeps the explicit "Stage Grant" verb.
        var confirmBtn = gid('cp-grant-confirm');
        if (confirmBtn) {
            confirmBtn.innerHTML = cpMode === 'plan'
                ? '<i class="fas fa-check"></i> Stage Grant'
                : '<i class="fas fa-check"></i> Grant';
        }
        gid('cp-grant-modal').style.display = 'flex';
        // QW#8: move focus into the dialog on open (giver input, else the confirm button).
        setTimeout(function() {
            var gi = gid('cp-grant-giver-text') || gid('cp-grant-confirm');
            if (gi) gi.focus();
        }, 40);
    }

    window.cpCloseGrantModal = function() {
        var m = gid('cp-grant-modal');
        if (m) m.style.display = 'none';
        var drop = gid('cp-grant-giver-ac');
        if (drop) drop.style.display = 'none';
    };

    window.cpGrantConfirm = function() {
        var caid    = gid('cp-grant-caid').value;
        var giverId = parseInt(gid('cp-grant-giver-id').value, 10) || 0;
        var reason  = gid('cp-grant-reason').value.trim();
        var rankWrap = gid('cp-grant-rank-wrap');
        var rank    = rankWrap.style.display !== 'none' ? (parseInt(gid('cp-grant-rank').value, 10) || 0) : 0;
        var err     = gid('cp-grant-error');
        if (!giverId) { err.textContent = 'Please choose who is granting this award.'; err.style.display = 'block'; return; }
        err.style.display = 'none';
        var btn = gid('cp-grant-confirm');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        fd.append('GivenById',     giverId);
        fd.append('PublicComment', reason);
        fd.append('Rank',          rank);
        post('CourtAjax/grant_award', fd).then(function(d) {
            btn.disabled = false;
            if (d.status === 0) {
                var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
                if (a) { a.PublicComment = reason; a.Rank = rank; a.GivenByMundaneId = giverId; }
                cpBumpRowVersion(caid);
                cpSetRowStatus(caid, 'staged');
                if (typeof d.staged_count !== 'undefined') cpUpdateStagedIndicator(d.staged_count);
                cpCloseGrantModal();
            } else if (!d._postFailed) {
                err.textContent = d.error || 'Could not stage the grant.';
                err.style.display = 'block';
            }
        });
    };

    // ---- Undo a staged grant (spec §6.3) ----
    window.cpUnstageAward = function(caid) {
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        post('CourtAjax/unstage_award', fd).then(function(d) {
            if (d.status === 0) {
                cpBumpRowVersion(caid);
                cpSetRowStatus(caid, 'planned');
                if (typeof d.staged_count !== 'undefined') cpUpdateStagedIndicator(d.staged_count);
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not undo the grant.');
            }
        });
    };

    // ---- Skip (published mode) ----
    window.cpSkipAward = function(caid) {
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        // S5 optimistic lock: thread our last-known row_version.
        fd.append('RowVersion', cpGetRowVersion(caid));
        post('CourtAjax/skip_award', fd).then(function(d) {
            if (d && d.status === 9) { cpStale(caid); return; }
            if (d.status === 0) {
                cpBumpRowVersion(caid);
                cpSetRowStatus(caid, 'cancelled');
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not skip award.');
            }
        });
    };

    // ---- Un-skip (QW#7) — return a cancelled/skipped row to planned ----
    // set_award_status guards committed ('given') rows and honors the row_version token
    // (stale → status 9 → cpStale non-destructive reload).
    window.cpUnskipAward = function(caid) {
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        fd.append('Status', 'planned');
        fd.append('RowVersion', cpGetRowVersion(caid));
        post('CourtAjax/set_award_status', fd).then(function(d) {
            if (d && d.status === 9) { cpStale(caid); return; }
            if (d.status === 0) {
                cpBumpRowVersion(caid);
                cpSetRowStatus(caid, 'planned');
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not un-skip the award.');
            }
        });
    };

    // ---- Remove award ----
    window.cpRemoveAward = function(caid) {
        cpConfirm({
            title: 'Remove award',
            body: 'Remove this award from the court plan?',
            confirmLabel: 'Remove',
            danger: true,
            onConfirm: function() { cpDoRemoveAward(caid); }
        });
    };

    function cpDoRemoveAward(caid) {
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
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not remove.');
            }
        });
    }

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
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(d) {
                    if (d.status === 0) {
                        if (row) {
                            row.classList.add('dismissing');
                            setTimeout(function() { row.remove(); cpRmFilter(); }, 320);
                        }
                    } else {
                        cpAlert(d.error || 'Failed to dismiss recommendation.');
                    }
                })
                .catch(function() {
                    cpGlobalError('Could not dismiss the recommendation. Please try again.');
                });
        }
        // QW#9: in-product confirm only — cpConfirm falls back to a DOM dialog, never confirm().
        cpConfirm({ title: 'Dismiss recommendation?', body: 'Already given out previously? No plans to award this? You can dismiss this rec.', confirmLabel: 'Dismiss', danger: true, onConfirm: doDismiss });
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
        // Keep each rec id paired with its result so we only mark successful adds as "In Plan".
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
            return post('CourtAjax/add_award', fd).then(function(d) { return { rid: rid, d: d }; });
        });
        Promise.all(promises).then(function(results) {
            var succeeded = results.filter(function(x) { return x.d.status === 0; });
            var failed    = results.filter(function(x) { return x.d.status !== 0; });
            // Append rows and mark ONLY the successful recs as already planned.
            succeeded.forEach(function(x) {
                cpAppendAwardRow(x.d.award);
                var el = gid('cp-rec-' + x.rid);
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
            // Failed recs stay selectable so the user can retry them.
            selectedRecs = failed.map(function(x) { return x.rid; });
            cpRmUpdateCount();
            if (failed.length) {
                gid('cp-rec-error').textContent = failed[0].d.error || 'Some awards could not be added.';
                gid('cp-rec-error').style.display = 'block';
            } else {
                cpCloseRecModal();
            }
        });
    };

    // ---- Ad-hoc award modal ----
    var cpAdhocMode = 'award';  // 'award' | 'title' — set by the two toolbar buttons
    window.cpOpenAdhocModal = function(mode) {
        cpAdhocMode = (mode === 'title') ? 'title' : 'award';
        var isTitle = cpAdhocMode === 'title';
        // Retitle the shared modal shell + award field for the chosen type.
        gid('cp-adhoc-modal-title').innerHTML = '<i class="fas fa-award" style="margin-right:8px;color:#4a5568"></i>Add ' + (isTitle ? 'Title' : 'Award') + ' to Court';
        gid('cp-adhoc-award-label').firstChild.textContent = (isTitle ? 'Title ' : 'Award ');
        var awSearch = gid('cp-adhoc-award-search');
        awSearch.placeholder = isTitle ? 'Search titles…' : 'Search awards…';
        gid('cp-adhoc-persona').value = '';
        gid('cp-adhoc-mundane-id').value = '';
        awSearch.value = '';
        awSearch.dataset.ladder = '0';
        gid('cp-adhoc-award-id').value = '';
        var awDrop = gid('cp-adhoc-award-ac');
        awDrop.innerHTML = '';
        awDrop.style.display = 'none';
        gid('cp-adhoc-rank-val').value    = '0';
        gid('cp-adhoc-rank-pills').innerHTML = '';
        gid('cp-adhoc-notes').value  = '';
        gid('cp-adhoc-pubcomment').value = '';
        gid('cp-adhoc-ptl').checked  = false;
        gid('cp-adhoc-rank-wrap').style.display = 'none';
        gid('cp-adhoc-error').style.display     = 'none';
        gid('cp-adhoc-modal').style.display     = 'flex';
        setTimeout(function() { gid('cp-adhoc-persona').focus(); }, 50);
    };
    window.cpCloseAdhocModal = function() { gid('cp-adhoc-modal').style.display = 'none'; };

    // Typeable autocomplete for the ad-hoc modal. Scoped to the current mode —
    // 'award' shows the award-type groups, 'title' shows the title-type groups —
    // and rendered UNDER .cp-ac-group headers in canonical order (skipping empty
    // groups), exactly like the player Add Award modal. Options within a group are
    // filtered by case-insensitive substring; an empty query shows all, so it
    // doubles as a browsable, grouped dropdown. Group + within-group order from
    // cpAwardOptions is preserved.
    window.cpAwardSearch = function() {
        var input = gid('cp-adhoc-award-search');
        var drop  = gid('cp-adhoc-award-ac');
        var q     = (input.value || '').trim().toLowerCase();
        var wantTitle  = cpAdhocMode === 'title';
        var groupOrder = wantTitle ? CP_TITLE_GROUPS : CP_AWARD_GROUPS;

        drop.innerHTML = '';
        var any = false;
        for (var g = 0; g < groupOrder.length; g++) {
            var label = groupOrder[g];
            var items = [];
            for (var i = 0; i < cpAwardOptions.length; i++) {
                var o = cpAwardOptions[i];
                if (o.group !== label) continue;
                if (q && String(o.name).toLowerCase().indexOf(q) === -1) continue;
                items.push(o);
            }
            if (!items.length) continue;
            any = true;
            var hdr = document.createElement('div');
            hdr.className = 'cp-ac-group';
            hdr.textContent = label;
            drop.appendChild(hdr);
            for (var j = 0; j < items.length; j++) {
                (function(o) {
                    var div = document.createElement('div');
                    div.className = 'cp-ac-item';
                    div.textContent = o.name;
                    div.addEventListener('mousedown', function(e) { e.preventDefault(); });
                    div.addEventListener('click', function(e) {
                        e.stopPropagation();
                        cpSelectAdhocAward(o);
                    });
                    drop.appendChild(div);
                })(items[j]);
            }
        }
        if (!any) {
            drop.innerHTML = '<div class="cp-ac-item" style="color:#a0aec0;cursor:default">No ' + (wantTitle ? 'titles' : 'awards') + ' found</div>';
        }
        cpPositionAc(input, drop);
        drop.style.display = 'block';
    };

    // Commit an award/title selection from the autocomplete and drive the rank UI.
    window.cpSelectAdhocAward = function(o) {
        var input = gid('cp-adhoc-award-search');
        input.value = o.name;
        input.dataset.ladder = o.ladder ? '1' : '0';
        gid('cp-adhoc-award-id').value = o.id;
        var drop = gid('cp-adhoc-award-ac');
        drop.style.display = 'none';
        drop.innerHTML = '';
        cpAdhocAwardChange();
    };

    // Toggle the rank-pills field based on the currently selected award.
    window.cpAdhocAwardChange = function() {
        var input = gid('cp-adhoc-award-search');
        var wrap  = gid('cp-adhoc-rank-wrap');
        if (gid('cp-adhoc-award-id').value && input.dataset.ladder === '1') {
            wrap.style.display = '';
            cpBuildAdhocRankPills(input.value);
        } else {
            wrap.style.display = 'none';
            gid('cp-adhoc-rank-pills').innerHTML = '';
            gid('cp-adhoc-rank-val').value = '0';
        }
    };

    // Build clickable rank pills (1..maxRank) using the shared .ladder-rank
    // component for colour; maxRank follows the standard Add Award modal's
    // zodiac heuristic. Default-selects rank 1.
    window.cpBuildAdhocRankPills = function(awardName) {
        var maxRank = /zodiac/i.test(awardName || '') ? 12 : 10;
        var html = '';
        for (var i = 1; i <= maxRank; i++) {
            html += '<button type="button" class="ladder-rank cp-rank-pill" data-lvl="' + Math.min(i, 10) + '" data-rank="' + i + '" onclick="cpSelectAdhocRank(' + i + ')">' + i + '</button>';
        }
        gid('cp-adhoc-rank-pills').innerHTML = html;
        cpSelectAdhocRank(1);
    };

    window.cpSelectAdhocRank = function(rank) {
        gid('cp-adhoc-rank-val').value = rank;
        var pills = gid('cp-adhoc-rank-pills').querySelectorAll('.cp-rank-pill');
        for (var i = 0; i < pills.length; i++) {
            pills[i].classList.toggle('cp-rank-pill-selected', String(pills[i].dataset.rank) === String(rank));
        }
    };

    window.cpSubmitAdhoc = function() {
        var mundaneId = gid('cp-adhoc-mundane-id').value;
        var kaId      = gid('cp-adhoc-award-id').value;
        var rank      = gid('cp-adhoc-rank-wrap').style.display !== 'none' ? (parseInt(gid('cp-adhoc-rank-val').value, 10) || 0) : 0;
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
        // Register in the client model so grant/skip/reconcile can find the row (walk-on
        // adds must be grantable without a reload). Idempotent.
        if (!courtAwards.some(function(x) { return String(x.CourtAwardId) === String(aw.CourtAwardId); })) {
            courtAwards.push(aw);
        }
        var ptlBadge = aw.PassToLocal ? '<span class="cp-flag-local" data-tip="Pass to Local — this award will be handed down to the recipient\'s home park to grant at their court."><i class="fas fa-arrow-down"></i></span>' : '';
        var recBadge = aw.RecommendationsId ? '<span class="cp-flag-rec" data-tip="This award came from a submitted recommendation."><i class="fas fa-star"></i></span>' : '';
        var rankStr  = (aw.IsLadder && aw.Rank > 0) ? '<span class="ladder-rank cp-award-rank" data-lvl="' + Math.min(aw.Rank, 10) + '">Rank ' + aw.Rank + '</span>' : '';
        var noteBtn  = aw.Notes ? '<button class="cp-note-btn" data-note="' + esc(aw.Notes) + '" onclick="event.stopPropagation();cpShowNote(this)" data-tip="View note" aria-label="View internal note"><i class="fas fa-comment-alt"></i></button>' : '';
        var typeClass = aw.IsTitle ? 'cp-type-title' : (aw.IsLadder ? 'cp-type-ladder' : 'cp-type-award');
        var typeLabel = aw.IsTitle ? 'Title' : (aw.IsLadder ? 'Ladder' : 'Award');
        var typeTip   = aw.IsTitle ? 'Title or peerage — a bestowed title/rank.' : (aw.IsLadder ? 'Ladder award — given in progressive ranks (Rank 1, 2, 3 …).' : 'Standard award — a one-off honor, not ranked.');
        var typeRow   = aw.IsTitle ? 'title'  : (aw.IsLadder ? 'ladder' : 'award');
        var html = '<div class="cp-award-row cp-aw-type-' + typeRow + '" id="cp-aw-' + aw.CourtAwardId + '" data-court-award-id="' + aw.CourtAwardId + '" data-rowversion="' + (aw.RowVersion || 0) + '" data-sort="' + aw.SortOrder + '">' +
            '<div class="cp-award-row-main cp-row-grid" onclick="cpToggleAward(' + aw.CourtAwardId + ')">' +
            '<div class="cp-cell cp-cell-order"><div class="cp-reorder-btns">' +
            '<button class="cp-reorder-btn" data-tip="Move up" aria-label="Move award up" onclick="event.stopPropagation();cpMoveAward(' + aw.CourtAwardId + ',-1)">&#9650;</button>' +
            '<button class="cp-reorder-btn" data-tip="Move down" aria-label="Move award down" onclick="event.stopPropagation();cpMoveAward(' + aw.CourtAwardId + ',1)">&#9660;</button>' +
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
            '<div class="cp-cell cp-cell-type"><span class="' + typeClass + '" data-tip="' + esc(typeTip) + '">' + typeLabel + '</span></div>' +
            '<div class="cp-cell cp-cell-flags cp-award-flags">' + ptlBadge + recBadge + '</div>' +
            '<div class="cp-cell cp-cell-scroll"><span class="cp-tracking-icon" data-tip="' + esc(cpTrackLabel('scroll', aw.ScrollStatus)) + '" aria-label="' + esc(cpTrackLabel('scroll', aw.ScrollStatus)) + '" data-type="scroll" data-status="' + aw.ScrollStatus + '" onclick="cpUpdateTracking(event, ' + aw.CourtAwardId + ', \'scroll\', this)"><i class="fas fa-print"></i></span></div>' +
            '<div class="cp-cell cp-cell-regalia"><span class="cp-tracking-icon" data-tip="' + esc(cpTrackLabel('regalia', aw.RegaliaStatus)) + '" aria-label="' + esc(cpTrackLabel('regalia', aw.RegaliaStatus)) + '" data-type="regalia" data-status="' + aw.RegaliaStatus + '" onclick="cpUpdateTracking(event, ' + aw.CourtAwardId + ', \'regalia\', this)"><i class="fas fa-medal"></i></span></div>' +
            '<div class="cp-cell cp-cell-status"><span class="cp-aw-badge" style="background:#edf2f7;color:#4a5568">Planned</span></div>' +
            '<div class="cp-cell cp-cell-chevron"><i class="fas fa-chevron-down"></i></div>' +
            '</div>' +
            '<div class="cp-award-row-expand" id="cp-aw-expand-' + aw.CourtAwardId + '">' +
            '<div class="cp-expand-grid">' +
            '<div><div class="cp-expand-label">Internal Notes</div><textarea class="cp-notes-area" id="cp-notes-' + aw.CourtAwardId + '" placeholder="Monarchy notes…">' + esc(aw.Notes || '') + '</textarea>' + cpPubCommentFieldHtml(aw.CourtAwardId, aw.RecReason, aw.PublicComment) + '</div>' +
            '<div>' + cpPtlControlHtml(aw.CourtAwardId, aw.RecommendationsId, aw.PassToLocal) +
            '</div>' +
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
        // Published-mode walk-on: render the Grant/Skip lifecycle actions for the new row
        // (cpSetRowStatus builds them only when courtStatus === 'published').
        if (courtStatus === 'published') cpSetRowStatus(aw.CourtAwardId, aw.Status || 'planned');
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
        cpConfirm({
            title: 'Remove artisan',
            body: 'Remove this artisan?',
            confirmLabel: 'Remove',
            danger: true,
            onConfirm: function() { cpDoRemoveArtisan(artId); }
        });
    };

    function cpDoRemoveArtisan(artId) {
        var fd = new FormData();
        fd.append('CourtAwardArtisanId', artId);
        post('CourtAjax/remove_artisan', fd).then(function(d) {
            if (d.status === 0) { var el = gid('cp-art-' + artId); if (el) el.remove(); }
            else if (!d._postFailed) cpAlert(d.error || 'Could not remove.');
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

    // ---- Publish choice: Run vs Plan (spec §5.2) ----
    window.cpOpenPublishModal = function() {
        var e = gid('cp-publish-error'); if (e) e.style.display = 'none';
        gid('cp-publish-modal').style.display = 'flex';
    };
    window.cpClosePublishModal = function() {
        var m = gid('cp-publish-modal'); if (m) m.style.display = 'none';
    };
    window.cpDoPublish = function(mode) {
        var fd = new FormData();
        fd.append('CourtId', courtId);
        fd.append('Status', 'published');
        fd.append('Mode', mode === 'plan' ? 'plan' : 'run');
        post('CourtAjax/update_court_status', fd).then(function(d) {
            if (d.status === 0) { location.reload(); }
            else if (!d._postFailed) {
                var e = gid('cp-publish-error');
                e.textContent = d.error || 'Could not publish.';
                e.style.display = 'block';
            }
        });
    };

    // ---- Bulk "Record grants" (plan mode) ----
    window.cpBulkRecord = function() {
        cpConfirm({
            title: 'Record all grants',
            body: 'Stage every remaining planned award using the default giver? You can still undo individual grants before finalizing.',
            confirmLabel: 'Record All',
            onConfirm: function() {
                var fd = new FormData();
                fd.append('CourtId', courtId);
                post('CourtAjax/bulk_record_grants', fd).then(function(d) {
                    if (d.status === 0) { location.reload(); }
                    else if (!d._postFailed) cpAlert(d.error || 'Could not record grants.');
                });
            }
        });
    };

    // ---- Prepopulate skipped-from-last-court (spec §6.5) ----
    window.cpPrepopulate = function() {
        var btn = gid('cp-prev-btn');
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('CourtId', courtId);
        post('CourtAjax/prepopulate_from_last_court', fd).then(function(d) {
            if (d.status === 0) { location.reload(); }
            else { if (btn) btn.disabled = false; if (!d._postFailed) cpAlert(d.error || 'Could not prepopulate.'); }
        });
    };
    window.cpDismissPrevBanner = function() {
        var b = gid('cp-prev-banner');
        if (b) b.classList.remove('show');
        try { sessionStorage.setItem('cp.prevBannerDismissed.' + courtId, '1'); } catch (e) {}
    };

    // ---- Complete-court modal (spec §6.6) ----
    window.cpOpenCompleteModal = function() {
        var unresolved = 0, staged = 0;
        courtAwards.forEach(function(a) {
            if (a.Status === 'planned' || a.Status === 'announced') unresolved++;
            else if (a.Status === 'staged') staged++;
        });
        var lead = gid('cp-complete-lead');
        var opts = gid('cp-complete-opts');
        var fail = gid('cp-complete-fail');
        fail.style.display = 'none'; fail.innerHTML = '';
        opts.innerHTML = '';
        if (unresolved > 0) {
            lead.innerHTML = '<strong>' + unresolved + '</strong> award' + (unresolved === 1 ? ' is' : 's are') +
                ' still unresolved (not granted or skipped)' +
                (staged > 0 ? ', and <strong>' + staged + '</strong> grant' + (staged === 1 ? ' is' : 's are') + ' staged to finalize' : '') +
                '. How would you like to complete this court?';
            opts.innerHTML =
                '<div class="cp-complete-opt cp-co-danger" onclick="cpDoFinalize(1)"><i class="fas fa-forward"></i><div>' +
                    '<div class="cp-co-title">Skip Remaining Awards</div>' +
                    '<div class="cp-co-desc">Mark the ' + unresolved + ' unresolved award' + (unresolved === 1 ? '' : 's') + ' as skipped, then finalize the staged grants and complete.</div></div></div>' +
                '<div class="cp-complete-opt cp-co-neutral" onclick="cpDoFinalize(0)"><i class="fas fa-check"></i><div>' +
                    '<div class="cp-co-title">Leave As-Is and Close</div>' +
                    '<div class="cp-co-desc">Finalize the staged grants and complete. Unresolved awards are left untouched and resurface on the next court.</div></div></div>';
        } else if (staged > 0) {
            lead.innerHTML = 'Finalize <strong>' + staged + '</strong> staged grant' + (staged === 1 ? '' : 's') +
                ' and complete this court? This records ' + (staged === 1 ? 'it' : 'them') + ' in the player registry.';
            opts.innerHTML =
                '<div class="cp-complete-opt cp-co-primary" onclick="cpDoFinalize(0)"><i class="fas fa-stamp"></i><div>' +
                    '<div class="cp-co-title">Finalize &amp; Complete</div>' +
                    '<div class="cp-co-desc">Commit ' + staged + ' staged grant' + (staged === 1 ? '' : 's') + ' to the permanent record and mark the court complete.</div></div></div>';
        } else {
            lead.innerHTML = 'There are no staged grants or unresolved awards. Mark this court complete?';
            opts.innerHTML =
                '<div class="cp-complete-opt cp-co-primary" onclick="cpDoFinalize(0)"><i class="fas fa-check"></i><div>' +
                    '<div class="cp-co-title">Complete Court</div>' +
                    '<div class="cp-co-desc">Close out this court.</div></div></div>';
        }
        gid('cp-complete-modal').style.display = 'flex';
    };
    window.cpCloseCompleteModal = function() {
        var m = gid('cp-complete-modal'); if (m) m.style.display = 'none';
    };
    window.cpDoFinalize = function(skipRemaining) {
        var opts = gid('cp-complete-opts');
        function lockOpts(on) {
            opts.querySelectorAll('.cp-complete-opt').forEach(function(o) {
                o.style.pointerEvents = on ? 'none' : '';
                o.style.opacity = on ? '.6' : '';
            });
        }
        lockOpts(true);
        var fd = new FormData();
        fd.append('CourtId', courtId);
        fd.append('SkipRemaining', skipRemaining ? 1 : 0);
        post('CourtAjax/finalize_court', fd).then(function(d) {
            if (d._postFailed) { lockOpts(false); return; }
            if (d.status !== 0) {
                var f = gid('cp-complete-fail');
                f.textContent = d.error || 'Could not finalize.';
                f.style.display = 'block';
                lockOpts(false);
                return;
            }
            if (d.completed) { location.reload(); return; }
            // Partial failure: committed some, others stay staged. Surface which and stay put.
            var f = gid('cp-complete-fail');
            var msg = '<strong>' + (d.committed || 0) + ' grant' + (d.committed === 1 ? '' : 's') + ' recorded</strong>, but ' +
                (d.failed ? d.failed.length : 0) + ' could not be committed and remain staged:';
            msg += '<ul style="margin:6px 0 0;padding-left:18px">';
            (d.failed || []).forEach(function(fl) {
                var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(fl.court_award_id); });
                var who = a ? (a.Persona + ' — ' + a.AwardName) : ('Award #' + fl.court_award_id);
                msg += '<li>' + esc(who) + ': ' + esc(fl.error || 'error') + '</li>';
            });
            msg += '</ul>';
            f.innerHTML = msg;
            f.style.display = 'block';
            lockOpts(false);
            // Reflect the rows that DID commit (now 'given') and refresh the staged count.
            cpHeartbeatPoll(true);
        });
    };

    // ---- Live heartbeat (spec §6.4) ----
    function cpAnyModalOpen() {
        var ids = ['cp-rec-modal', 'cp-adhoc-modal', 'cp-artisan-modal', 'cp-grant-modal', 'cp-publish-modal', 'cp-complete-modal'];
        for (var i = 0; i < ids.length; i++) {
            var e = gid(ids[i]);
            if (e && e.style.display && e.style.display !== 'none') return true;
        }
        var so = gid('cp-script-overlay');
        if (so && !so.hidden) return true;
        if (document.querySelector('.cp-award-row-expand.open')) return true;
        return false;
    }
    function cpUpdateModeBadge(mode) {
        var b = gid('cp-mode-badge');
        if (!b) return;
        if (mode === 'plan') { b.className = 'cp-mode-badge cp-mode-plan'; b.innerHTML = '<i class="fas fa-clipboard-list"></i> Plan'; }
        else                 { b.className = 'cp-mode-badge cp-mode-run';  b.innerHTML = '<i class="fas fa-bullhorn"></i> Run at Court'; }
    }
    // Reorder DOM rows to match the server sort (full-payload PascalCase fields).
    function cpApplyServerOrderFull(full) {
        var list = gid('cp-award-list');
        if (!list) return;
        var ordered = full.slice().sort(function(a, b) {
            return (a.SortOrder - b.SortOrder) || (a.CourtAwardId - b.CourtAwardId);
        });
        ordered.forEach(function(sa) {
            var row = gid('cp-aw-' + sa.CourtAwardId);
            if (row) list.appendChild(row);
        });
        cpRenumberRows();
    }

    // Push a server row's editable fields into the DOM. Safe because the heartbeat is
    // paused whenever a row is expanded (cpAnyModalOpen checks .cp-award-row-expand.open),
    // so we never overwrite an in-progress edit.
    function cpApplyRowFields(caid, sa) {
        var row = gid('cp-aw-' + caid);
        if (!row) return;
        // Internal notes textarea + header note button.
        var notesEl = gid('cp-notes-' + caid);
        if (notesEl && notesEl.value !== (sa.Notes || '')) notesEl.value = sa.Notes || '';
        var nameEl = row.querySelector('.cp-award-name');
        if (nameEl) {
            var noteBtn = nameEl.querySelector('.cp-note-btn');
            if (sa.Notes) {
                if (!noteBtn) {
                    var nb = document.createElement('button');
                    nb.className = 'cp-note-btn';
                    nb.dataset.tip = 'View note';
                    nb.dataset.note = sa.Notes;
                    nb.innerHTML = '<i class="fas fa-comment-alt"></i>';
                    nb.addEventListener('click', function(e) { e.stopPropagation(); cpShowNote(this); });
                    nameEl.appendChild(nb);
                } else { noteBtn.dataset.note = sa.Notes; }
            } else if (noteBtn) { noteBtn.remove(); }
        }
        // Public comment textarea.
        var pcEl = gid('cp-pubcomment-' + caid);
        if (pcEl && pcEl.value !== (sa.PublicComment || '')) pcEl.value = sa.PublicComment || '';
        // Pass-to-local: park-court checkbox + header flag.
        var ptlEl = gid('cp-ptl-' + caid);
        if (ptlEl) ptlEl.checked = !!sa.PassToLocal;
        var flagsEl = row.querySelector('.cp-award-flags');
        if (flagsEl) {
            var localFlag = flagsEl.querySelector('.cp-flag-local');
            if (sa.PassToLocal && !localFlag) {
                var span = document.createElement('span');
                span.className = 'cp-flag-local';
                span.dataset.tip = 'Pass to Local — this award will be handed down to the recipient\'s home park to grant at their court.';
                span.innerHTML = '<i class="fas fa-arrow-down"></i>';
                flagsEl.insertBefore(span, flagsEl.firstChild);
            } else if (!sa.PassToLocal && localFlag) { localFlag.remove(); }
        }
        // Scroll / regalia makers (visible label + hidden id).
        var smText = gid('cp-scroll-maker-text-' + caid);
        var smId   = gid('cp-scroll-maker-id-' + caid);
        if (smText) smText.value = sa.ScrollMakerPersona || '';
        if (smId)   smId.value   = sa.ScrollMakerId || 0;
        var rmText = gid('cp-regalia-maker-text-' + caid);
        var rmId   = gid('cp-regalia-maker-id-' + caid);
        if (rmText) rmText.value = sa.RegaliaMakerPersona || '';
        if (rmId)   rmId.value   = sa.RegaliaMakerId || 0;
        // Scroll / regalia tracking status icons.
        var si = row.querySelector('.cp-tracking-icon[data-type="scroll"]');
        if (si && String(si.dataset.status) !== String(sa.ScrollStatus)) si.dataset.status = sa.ScrollStatus;
        var ri = row.querySelector('.cp-tracking-icon[data-type="regalia"]');
        if (ri && String(ri.dataset.status) !== String(sa.RegaliaStatus)) ri.dataset.status = sa.RegaliaStatus;
    }

    // Full-field reconcile (S5): drive the row set from awards_full — status, giver,
    // sort_order, row_version AND notes/public_comment/pass_to_local/makers — plus add
    // rows another officer created and remove rows that disappeared.
    function cpReconcileState(d) {
        var full = d.awards_full;
        if (!Array.isArray(full)) { cpReconcileLight(d); return; }
        // given_by lives only on the light payload; map it for the model.
        var givenByMap = {};
        (d.awards || []).forEach(function(la) { givenByMap[String(la.court_award_id)] = la.given_by_mundane_id; });

        var serverIds = {};
        var staged = 0;
        full.forEach(function(sa) {
            var caid = sa.CourtAwardId;
            serverIds[String(caid)] = true;
            if (sa.Status === 'staged') staged++;
            var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
            if (!a) {
                // Row added by another officer — insert it (cpAppendAwardRow registers it in the model).
                cpAppendAwardRow(sa);
                a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
            }
            if (a && a.Status !== sa.Status) cpSetRowStatus(caid, sa.Status);
            cpSetRowVersion(caid, sa.RowVersion);
            cpApplyRowFields(caid, sa);
            if (a) {
                a.Status = sa.Status;
                a.SortOrder = sa.SortOrder;
                a.Notes = sa.Notes || '';
                a.PublicComment = sa.PublicComment || '';
                a.PassToLocal = sa.PassToLocal;
                a.ScrollMakerId = sa.ScrollMakerId || null;
                a.ScrollMakerPersona = sa.ScrollMakerPersona || '';
                a.RegaliaMakerId = sa.RegaliaMakerId || null;
                a.RegaliaMakerPersona = sa.RegaliaMakerPersona || '';
                a.ScrollStatus = sa.ScrollStatus;
                a.RegaliaStatus = sa.RegaliaStatus;
                if (givenByMap[String(caid)] !== undefined) a.GivenByMundaneId = givenByMap[String(caid)];
            }
        });
        // Remove rows that disappeared (removed / passed-to-local by another officer).
        for (var i = courtAwards.length - 1; i >= 0; i--) {
            var id = courtAwards[i].CourtAwardId;
            if (!serverIds[String(id)]) {
                var gone = gid('cp-aw-' + id);
                if (gone) gone.remove();
                courtAwards.splice(i, 1);
            }
        }
        cpApplyServerOrderFull(full);
        if (d.mode && d.mode !== cpMode) { cpMode = window.cpMode = d.mode; cpUpdateModeBadge(d.mode); }
        cpUpdateStagedIndicator(staged);
        // Keep the header/toolbar/status-bar counts honest after add/remove.
        var n = courtAwards.length;
        var cnt = gid('cp-award-count');            if (cnt) cnt.textContent = '(' + n + ')';
        var tcnt = gid('cp-list-toolbar-count');    if (tcnt) tcnt.textContent = n + ' award' + (n !== 1 ? 's' : '');
        var sbt = gid('cp-sb-total');               if (sbt) sbt.textContent = n + ' award' + (n !== 1 ? 's' : '');
        cpRefreshStatusBar();
        cpRefreshProgress();
    }

    // Fallback for an older server that only sends the light `awards` array.
    function cpReconcileLight(d) {
        var list = gid('cp-award-list');
        var staged = 0;
        (d.awards || []).forEach(function(sa) {
            var caid = sa.court_award_id;
            var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
            if (sa.status === 'staged') staged++;
            if (a && a.Status !== sa.status) cpSetRowStatus(caid, sa.status);
            if (sa.row_version != null) cpSetRowVersion(caid, sa.row_version);
            if (a) { a.GivenByMundaneId = sa.given_by_mundane_id; a.SortOrder = sa.sort_order; }
        });
        if (list) {
            (d.awards || []).slice().sort(function(a, b) {
                return (a.sort_order - b.sort_order) || (a.court_award_id - b.court_award_id);
            }).forEach(function(sa) {
                var row = gid('cp-aw-' + sa.court_award_id);
                if (row) list.appendChild(row);
            });
            cpRenumberRows();
        }
        cpUpdateStagedIndicator(staged);
        if (d.mode && d.mode !== cpMode) { cpMode = window.cpMode = d.mode; cpUpdateModeBadge(d.mode); }
        cpRefreshProgress();
    }

    // ---- Presence + honest sync state (S5) ----
    function cpRenderPresence(list) {
        var chip = gid('cp-presence-chip');
        if (!chip) return;
        var n = (list && list.length) ? list.length : 1;
        chip.innerHTML = '<i class="fas fa-users" style="margin-right:4px"></i>' + n + ' viewing';
        if (list && list.length) {
            chip.dataset.tip = list.map(function(o) { return o.name || ('Officer #' + o.uid); }).join(', ');
        }
    }
    var cpLastSyncAt = 0;
    function cpSetSyncState(ok) {
        var el = gid('cp-sync-indicator');
        if (!el) return;
        if (ok) { cpLastSyncAt = Date.now(); el.dataset.state = 'synced'; cpRenderSync(); }
        else    { el.dataset.state = 'reconnecting'; el.innerHTML = '<i class="fas fa-exclamation-triangle" style="margin-right:4px;color:#dd6b20"></i>Reconnecting…'; }
    }
    function cpRenderSync() {
        var el = gid('cp-sync-indicator');
        if (!el || el.dataset.state !== 'synced' || !cpLastSyncAt) return;
        var secs = Math.max(0, Math.round((Date.now() - cpLastSyncAt) / 1000));
        var ago  = secs < 5 ? 'just now' : (secs + 's ago');
        el.innerHTML = '<i class="fas fa-check-circle" style="margin-right:4px;color:#38a169"></i>Synced ' + ago;
    }

    window.cpHeartbeatPoll = function(force) {
        // Pause reconcile while a modal/expand is open so we never yank the UI out from
        // under an edit — but a forced poll (stale reload / post-finalize) always runs.
        if (!force && cpAnyModalOpen()) return;
        var fd = new FormData();
        fd.append('CourtId', courtId);
        // Silent: a background poll failure must NOT raise the red error toast (only the
        // "Reconnecting…" chip). User-initiated actions keep their loud error path.
        post('CourtAjax/court_state', fd, true).then(function(d) {
            if (!d || d._postFailed || d.status !== 0) { cpSetSyncState(false); return; }
            cpSetSyncState(true);
            cpRenderPresence(d.presence);
            // Version-stamp short-circuit: skip the (heavier) reconcile when nothing changed.
            if (d.version === cpStateVersion) return;
            cpStateVersion = d.version;
            cpReconcileState(d);
        });
    };

    // ---- Drag-and-drop reorder (spec §6.2) — draft only, one POST on drop ----
    var cpDrag = null;
    function cpInitDrag() {
        if (courtStatus !== 'draft') return;
        var list = gid('cp-award-list');
        if (!list) return;
        list.addEventListener('pointerdown', function(e) {
            var handle = e.target.closest('.cp-award-drag');
            if (!handle) return;
            var row = handle.closest('.cp-award-row');
            if (!row) return;
            e.preventDefault();
            cpDrag = { row: row, list: list, pointerId: e.pointerId, handle: handle, moved: false };
            row.classList.add('cp-cp-dragging');
            try { handle.setPointerCapture(e.pointerId); } catch (err) {}
        });
        list.addEventListener('pointermove', function(e) {
            if (!cpDrag || e.pointerId !== cpDrag.pointerId) return;
            cpDrag.moved = true;
            var rows = Array.prototype.slice.call(list.querySelectorAll('.cp-award-row:not(.cp-cp-dragging)'));
            var after = null;
            for (var i = 0; i < rows.length; i++) {
                var rect = rows[i].getBoundingClientRect();
                if (e.clientY < rect.top + rect.height / 2) { after = rows[i]; break; }
            }
            if (after) list.insertBefore(cpDrag.row, after);
            else       list.appendChild(cpDrag.row);
        });
        function endDrag() {
            if (!cpDrag) return;
            var moved = cpDrag.moved;
            cpDrag.row.classList.remove('cp-cp-dragging');
            try { cpDrag.handle.releasePointerCapture(cpDrag.pointerId); } catch (err) {}
            cpDrag = null;
            if (moved) { cpSaveOrder(); cpRenumberRows(); }
        }
        list.addEventListener('pointerup', endDrag);
        list.addEventListener('pointercancel', endDrag);
    }

    // ---- Init: drag, heartbeat, prev-banner session-dismiss ----
    cpInitDrag();
    // S2: normalize the staged-safeguard banner to the current mode's wording on load
    // (the server render defaults to plan phrasing).
    cpUpdateStagedIndicator(cpStagedCount);
    if (courtStatus === 'published') {
        cpHeartbeatPoll(false);                                  // populate presence/sync immediately
        setInterval(function() { cpHeartbeatPoll(false); }, 15000);
        setInterval(cpRenderSync, 5000);                         // tick "Synced Ns ago"
    }
    try {
        if (sessionStorage.getItem('cp.prevBannerDismissed.' + courtId) === '1') {
            var _pb = gid('cp-prev-banner');
            if (_pb) _pb.classList.remove('show');
        }
    } catch (e) {}

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
    ['cp-rec-modal','cp-adhoc-modal','cp-artisan-modal','cp-grant-modal','cp-publish-modal','cp-complete-modal'].forEach(function(id) {
        var el = gid(id);
        if (el) el.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            cpDismissNote();
            ['cp-rec-modal','cp-adhoc-modal','cp-artisan-modal','cp-grant-modal','cp-publish-modal','cp-complete-modal'].forEach(function(id) {
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
                '<td class="cp-script-check">' + (a.Status === 'given' || a.Status === 'staged' ? '☑' : '☐') + '</td>' +
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
