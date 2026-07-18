<?php
	if (strlen($Error ?? '') > 0) {
		echo '<div class="error-message">' . htmlspecialchars($Error) . '</div>';
		return;
	}
	$typeLabel  = ($TestType === 'corpora') ? 'Corpora Test' : "Reeve's Test";
	$activeQs   = array_values(array_filter($Questions, fn($q) => $q['Status'] === 'active'));
	$archivedQs = array_values(array_filter($Questions, fn($q) => $q['Status'] === 'archived'));
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
.qt-nav-link { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 5px; font-size: 13px; font-weight: 600; color: #2b6cb0; text-decoration: none; transition: background 0.15s; }
.qt-nav-link:hover { background: #ebf4ff; border-color: #bee3f8; color: #2c5282; }
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<style>
.qt-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
.qt-badge-green { background: #c6f6d5; color: #276749; }
.qt-badge-gray  { background: #e2e8f0; color: #4a5568; }
.qt-badge-red   { background: #fed7d7; color: #9b2c2c; }
.rp-table-area .qt-action-btn {
	display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.8rem;
	font-weight: 600; cursor: pointer; border: none; text-decoration: none;
}
.qt-action-btn-edit   { background: #e2e8f0; color: #2d3748; }
.qt-action-btn-edit:hover { background: #cbd5e0; }
.qt-action-btn-archive { background: #fed7d7; color: #9b2c2c; border: none; }
.qt-action-btn-archive:hover { background: #feb2b2; }
.qt-action-btn-restore { background: #c6f6d5; color: #276749; border: none; }
.qt-action-btn-restore:hover { background: #9ae6b4; }
.qt-actions-cell { white-space: nowrap; display: flex; gap: 6px; align-items: center; }
.qt-action-btn-reset { background: #e9d8fd; color: #553c9a; border: none; }
.qt-action-btn-reset:hover { background: #d6bcfa; }
.qt-action-btn-dup { background: #bee3f8; color: #2c5282; border: none; }
.qt-action-btn-dup:hover { background: #90cdf4; }
.qt-correct-answer { font-size: 0.78rem; color: #276749; margin-top: 3px; font-style: italic; }
.qt-success-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
.qt-success-green  { background: #c6f6d5; color: #276749; }
.qt-success-yellow { background: #fefcbf; color: #744210; }
.qt-success-red    { background: #fed7d7; color: #9b2c2c; }
.qt-success-none   { background: #e2e8f0; color: #718096; }
.qt-flag-btn { background: none; border: none; cursor: pointer; color: #e53e3e; font-size: 1rem; padding: 0 2px; line-height: 1; }
.qt-flag-btn:hover { color: #9b2c2c; }
[data-tip] { position: relative; }
[data-tip]::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #2d3748; color: #fff; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; white-space: normal; width: max-content; max-width: 240px; pointer-events: none; opacity: 0; transition: opacity 0.1s; z-index: 100; }
[data-tip]:hover::after { opacity: 1; }
/* Right-anchor tips in the Actions column so they don't clip off-screen */
.qt-actions-cell [data-tip]::after { left: auto; right: 0; transform: none; }
/* ── Versions (question sets) ───────────────────────────── */
.qt-versions { margin:10px 0 0; padding:12px 14px; background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; }
.qt-ver-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.qt-ver-row + .qt-ver-row { margin-top:10px; padding-top:10px; border-top:1px dashed #e2e8f0; }
.qt-ver-chip { display:inline-flex; align-items:center; gap:5px; padding:2px 9px; border-radius:999px; font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.qt-ver-live  { background:#c6f6d5; color:#22543d; }
.qt-ver-off   { background:#e2e8f0; color:#4a5568; }
.qt-ver-warn  { background:#fef3c7; color:#78350f; }
.qt-ver-draft { background:#e9d8fd; color:#44337a; }
/* Published, but holding fewer questions than the test draws — so it cannot be taken at all. */
.qt-ver-short { display:inline-flex; align-items:center; gap:5px; font-size:0.8rem; font-weight:600; color:#b45309; }
/* Publish stays disabled until the draft can actually pass publishSet()'s guards. */
.qt-ver-btn:disabled { opacity:.5; cursor:not-allowed; }
/* Rename a version in place. Subtle until hovered — it is metadata, not a primary action. */
.qt-ver-rename, .qt-ver-editlabel { background:none; border:none; cursor:pointer; color:#a0aec0; font-size:0.72rem; padding:2px 4px; }
.qt-ver-rename:hover, .qt-ver-editlabel:hover { color:#2b6cb0; }
/* The live version's rules/corpora label, edited in place like the name. */
.qt-ver-label { font-size:0.8rem; color:#718096; }
/* Previous versions — retired sets, readable but not editable. Collapsed: the list only grows. */
.qt-ver-past-wrap { margin-top:10px; padding-top:10px; border-top:1px dashed #e2e8f0; }
.qt-ver-pasthdr { display:flex; align-items:center; gap:6px; font-size:0.72rem; font-weight:700;
	text-transform:uppercase; letter-spacing:.04em; color:#718096; cursor:pointer; list-style:none; user-select:none; }
.qt-ver-pasthdr::-webkit-details-marker { display:none; }   /* we draw our own caret */
.qt-ver-pasthdr:hover { color:#2b6cb0; }
.qt-ver-caret { transition:transform .15s ease; font-size:0.65rem; }
.qt-ver-past-wrap[open] .qt-ver-caret { transform:rotate(90deg); }
/* Cap the height once there are many versions — scroll rather than shove the page down. */
.qt-ver-pastlist { display:flex; flex-direction:column; gap:6px; margin-top:10px; max-height:220px; overflow-y:auto; }
.qt-ver-past { display:flex; align-items:center; gap:10px; padding:6px 10px; background:#fff; text-align:left;
	border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; font-size:0.82rem; color:#2d3748; }
.qt-ver-past:hover { border-color:#90cdf4; background:#ebf8ff; }
.qt-ver-past .qt-ver-view { margin-left:auto; }   /* View pinned right, so the rows line up */
.qt-ver-view { display:inline-flex; align-items:center; gap:4px; font-size:0.75rem; font-weight:600; color:#2b6cb0; }
.qt-ver-nolabel { font-style:italic; }
/* A retired version's question, shown read-only in the modal. */
.qt-vq { padding:10px 12px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:8px; background:#fff; }
.qt-vq-text { font-weight:600; color:#2d3748; margin-bottom:6px; }
.qt-vq-ans { font-size:0.85rem; color:#4a5568; padding:2px 0 2px 18px; position:relative; }
.qt-vq-ans.qt-vq-correct { color:#22543d; font-weight:600; }
.qt-vq-ans.qt-vq-correct::before { content:"\f00c"; font-family:"Font Awesome 5 Free"; font-weight:900;
	position:absolute; left:0; color:#38a169; }
.qt-vq-archived { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:999px; font-size:0.68rem;
	font-weight:700; text-transform:uppercase; background:#fed7d7; color:#742a2a; }
html[data-theme="dark"] .qt-ver-warn  { background:#3b2f14; color:#fde68a; }
html[data-theme="dark"] .qt-ver-short { color:#fbbf24; }
/* The kingdom hasn't switched the test on — a published version still reaches nobody. */
.qt-notlive-warning { display:flex; align-items:flex-start; gap:10px; margin:0 0 12px; padding:11px 14px;
	background:#fffbeb; border:1px solid #fcd34d; border-left:4px solid #f59e0b; border-radius:6px;
	font-size:0.85rem; color:#78350f; line-height:1.55; }
.qt-notlive-warning i { color:#f59e0b; margin-top:3px; flex-shrink:0; }
html[data-theme="dark"] .qt-notlive-warning { background:#3b2f14; border-color:#a16207; color:#fde68a; }
.qt-ver-meta  { font-size:0.8rem; color:#718096; }
.qt-ver-input { padding:5px 9px; border:1px solid #cbd5e0; border-radius:5px; font-size:0.82rem; min-width:230px; }
.qt-ver-btn { margin-left:auto; padding:5px 13px; border:1px solid #cbd5e0; background:#fff; border-radius:6px; font-size:0.82rem; font-weight:600; color:#2d3748; cursor:pointer; }
.qt-ver-btn:hover { background:#edf2f7; }
.qt-ver-publish { background:#2b6cb0; border-color:#2b6cb0; color:#fff; margin-left:auto; }
.qt-ver-publish:hover { background:#2c5282; }
.qt-ver-discard { margin-left:0; color:#9b2c2c; border-color:#feb2b2; }
.qt-ver-discard:hover { background:#fff5f5; }
.qt-ver-note { margin-top:10px; font-size:0.8rem; color:#553c9a; background:#faf5ff; border:1px solid #e9d8fd; border-radius:6px; padding:8px 11px; line-height:1.5; }
/* membership chips on question rows */
.qt-mem { display:inline-flex; align-items:center; gap:4px; font-size:0.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:1px 6px; border-radius:4px; margin-left:5px; white-space:nowrap; }
.qt-mem-live   { background:#c6f6d5; color:#22543d; }
.qt-mem-draft  { background:#e9d8fd; color:#44337a; }
.qt-mem-unused { background:#edf2f7; color:#718096; }
/* Imported from another kingdom via the Global Question Library. Deliberately NOT uppercased —
   it carries a kingdom's name, and shouting it looks wrong. */
.qt-mem-lib    { background:#bee3f8; color:#2a4365; text-transform:none; letter-spacing:0; font-weight:600; }
.qt-mem-btn { padding:3px 9px; font-size:0.72rem; font-weight:600; border-radius:5px; border:1px solid #cbd5e0; background:#fff; cursor:pointer; white-space:nowrap; }
.qt-mem-btn:hover { background:#edf2f7; }
.qt-mem-btn.qt-mem-in { background:#e9d8fd; border-color:#d6bcfa; color:#44337a; }
/* ── Dark theme: the status colours ──────────────────────────────────────────
   The light-mode chips are pale tints (#e9d8fd lavender, #c6f6d5 mint) meant to sit on
   white. Rendered unchanged on a dark page they are the BRIGHTEST thing on screen — the
   draft purple in particular glared. Invert the pairing instead: deep background, light
   text, so a chip still reads as purple-means-draft without shouting. */
html[data-theme="dark"] .qt-ver-draft,
html[data-theme="dark"] .qt-mem-draft,
html[data-theme="dark"] .qt-preview-setchip-draft { background:#3c366b; color:#d6bcfa; }
html[data-theme="dark"] .qt-ver-live,
html[data-theme="dark"] .qt-mem-live,
html[data-theme="dark"] .qt-preview-setchip-live  { background:#22543d; color:#9ae6b4; }
html[data-theme="dark"] .qt-mem-unused { background:#374151; color:#a0aec0; }
html[data-theme="dark"] .qt-mem-lib    { background:#2a4365; color:#bee3f8; }
html[data-theme="dark"] .qt-preview-btn-draft { background:#3c366b; color:#d6bcfa; }
html[data-theme="dark"] .qt-preview-btn-draft:hover { background:#4c4285; }
html[data-theme="dark"] .qt-mem-btn { background:#374151; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .qt-mem-btn:hover { background:#4a5568; }
html[data-theme="dark"] .qt-mem-btn.qt-mem-in { background:#3c366b; border-color:#553c9a; color:#d6bcfa; }
html[data-theme="dark"] .qt-ver-label { color:#a0aec0; }
html[data-theme="dark"] .qt-ver-off { background:#374151; color:#cbd5e0; }
html[data-theme="dark"] .qt-lib-ver-none { background:#374151; color:#718096; }
/* The "multi" / "select all that apply" badge — was inline-styled, now a class so dark works. */
.qt-multi-badge { background:#e6fffa; color:#234e52; font-size:0.7rem; padding:1px 6px; border-radius:3px; font-weight:600; margin-left:6px; }
html[data-theme="dark"] .qt-multi-badge { background:#234e52; color:#9decdb; }

html[data-theme="dark"] .qt-versions { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .qt-ver-note { background:#322659; border-color:#553c9a; color:#e9d8fd; }
html[data-theme="dark"] .qt-ver-btn { background:#374151; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .qt-ver-input { background:#374151; border-color:#4a5568; color:#e2e8f0; }

/* Unsaved-config warning (test is running on invisible defaults) */
.qt-unsaved-warning {
	display:flex; align-items:flex-start; gap:10px;
	margin:10px 0 0; padding:11px 14px;
	background:#fffbeb; border:1px solid #fcd34d; border-left:4px solid #f59e0b;
	border-radius:6px; font-size:0.85rem; color:#78350f; line-height:1.5;
}
.qt-unsaved-warning i { color:#f59e0b; margin-top:2px; flex-shrink:0; }
.qt-unsaved-warning a { color:#92400e; font-weight:700; text-decoration:underline; }
html[data-theme="dark"] .qt-unsaved-warning { background:#3b2f14; border-color:#a16207; color:#fde68a; }
html[data-theme="dark"] .qt-unsaved-warning a { color:#fde68a; }
.qt-lib-question { border:1px solid #e2e8f0; border-radius:6px; padding:12px 14px; margin-bottom:10px; }
.qt-lib-question-hdr { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.qt-lib-question-text { font-size:0.88rem; font-weight:600; color:#2d3748; flex:1; }
.qt-lib-kingdom { font-size:0.75rem; color:#718096; margin-top:2px; }
.qt-lib-answers { margin-top:8px; padding-left:14px; }
.qt-lib-answer { font-size:0.8rem; color:#4a5568; line-height:1.6; }
.qt-lib-answer.qt-lib-correct { color:#276749; font-weight:600; }
.qt-lib-add-btn { white-space:nowrap; padding:4px 12px; background:#2b6cb0; color:#fff; border:none; border-radius:4px; font-size:0.8rem; font-weight:600; cursor:pointer; }
.qt-lib-add-btn:hover { background:#2c5282; }
.qt-lib-add-btn:disabled { background:#a0aec0; cursor:default; }
.qt-lib-flag { display:inline-flex; align-items:center; gap:3px; margin-left:8px; font-size:0.72rem; font-weight:700; color:#e53e3e; vertical-align:middle; }
/* Which rules edition the sharing kingdom's live test is built on. Neutral when it matches the
   version you are building; amber when it does not — that is the signal worth acting on. */
.qt-lib-ver { display:inline-block; margin-left:8px; padding:1px 7px; border-radius:999px; font-size:0.7rem;
	font-weight:600; background:#edf2f7; color:#4a5568; vertical-align:middle; }
.qt-lib-ver-diff { background:#fef3c7; color:#78350f; }
.qt-lib-ver-none { background:#edf2f7; color:#a0aec0; font-style:italic; font-weight:400; }
html[data-theme="dark"] .qt-lib-ver { background:#2d3748; color:#cbd5e0; }
html[data-theme="dark"] .qt-lib-ver-diff { background:#3b2f14; color:#fde68a; }
#qt-library-search:focus { border-color:#2b6cb0; box-shadow:0 0 0 3px rgba(43,108,176,0.15); }
.qt-report-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9000; align-items:center; justify-content:center; }
.qt-report-overlay.qt-open { display:flex; }
.qt-report-modal { background:#fff; border-radius:8px; padding:24px 26px; min-width:320px; max-width:440px; width:100%; box-shadow:0 4px 24px rgba(0,0,0,0.18); }
.qt-report-modal h4 { margin:0 0 14px; font-size:1rem; color:#2d3748; }
.qt-report-modal h4 i { color:#e53e3e; margin-right:6px; }
/* orkui.css styles EVERY h1..h6 as a grey "chip" — background, border, white text-shadow —
   meant for report section headers. On a modal TITLE that reads as a pale box around the
   text, and in dark mode it was a glaring white box (the global dark override for headings
   is less specific than this, so the light chip won). Strip the chrome from modal titles in
   both themes; the dark-context selectors are specific enough to beat the global heading rule. */
.qt-preview-modal h4, .qt-bulk-import-modal h4, .qt-report-modal h4, .qt-confirm-title,
html[data-theme="dark"] .qt-preview-modal h4,
html[data-theme="dark"] .qt-bulk-import-modal h4,
html[data-theme="dark"] .qt-report-modal h4,
html[data-theme="dark"] .qt-confirm-title {
	background:none; border:none; box-shadow:none; text-shadow:none; padding:0; border-radius:0;
}
.qt-report-reason-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #f0f4f8; font-size:0.88rem; color:#4a5568; }
.qt-report-reason-row:last-of-type { border-bottom:none; }
.qt-report-count { font-weight:700; color:#e53e3e; min-width:24px; text-align:right; }
.qt-report-reporters-hdr { margin:14px 0 6px; font-size:0.74rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#a0aec0; }
.qt-report-reporter-row { display:flex; justify-content:space-between; align-items:baseline; gap:12px; padding:5px 0; border-bottom:1px solid #f0f4f8; font-size:0.86rem; }
.qt-report-reporter-row:last-child { border-bottom:none; }
.qt-report-reporter-name a { color:#2b6cb0; text-decoration:none; font-weight:600; }
.qt-report-reporter-name a:hover { text-decoration:underline; }
.qt-report-reporter-meta { color:#718096; font-size:0.8rem; white-space:nowrap; }
.qt-report-footer { display:flex; gap:8px; margin-top:16px; flex-wrap:wrap; }
/* Bulk checkbox + bar */
.qt-bulk-cb { accent-color:#2b6cb0; width:15px; height:15px; cursor:pointer; }
.qt-bulk-cb-th { width:36px; text-align:center; }
.qt-bulk-bar { display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#2d3748; color:#fff; padding:10px 20px; border-radius:8px; z-index:9000; gap:14px; align-items:center; box-shadow:0 4px 16px rgba(0,0,0,0.25); font-size:0.88rem; }
.qt-bulk-bar.qt-bulk-bar-visible { display:flex; }
.qt-bulk-bar-count { font-weight:700; color:#90cdf4; }
.qt-bulk-bar-action { padding:5px 14px; border-radius:5px; border:none; font-size:0.82rem; font-weight:700; cursor:pointer; }
.qt-bulk-bar-archive { background:#fed7d7; color:#9b2c2c; }
.qt-bulk-bar-archive:hover { background:#feb2b2; }
.qt-bulk-bar-restore { background:#c6f6d5; color:#276749; }
.qt-bulk-bar-restore:hover { background:#9ae6b4; }
.qt-bulk-bar-deselect { color:#a0aec0; text-decoration:underline; cursor:pointer; background:none; border:none; font-size:0.82rem; }
/* Bulk import modal */
.qt-bulk-import-modal { background:#fff; border-radius:8px; padding:24px 26px; min-width:340px; max-width:680px; width:95%; max-height:90vh; box-sizing:border-box; box-shadow:0 4px 24px rgba(0,0,0,0.18); display:flex; flex-direction:column; }
.qt-bulk-import-modal h4 { margin:0 0 14px; font-size:1rem; color:#2d3748; }
/* The header (above) and the button row (below) are flex-shrink:0 and stay
   pinned; only this middle region scrolls, so the buttons are never pushed off
   screen no matter how short the window. min-height:0 lets it actually shrink. */
.qt-bulk-import-body { flex:1 1 auto; min-height:0; overflow-y:auto; display:flex; flex-direction:column; }
.qt-bulk-import-instructions { background:#f7fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; font-size:0.78rem; color:#4a5568; font-family:monospace; white-space:pre-line; margin-bottom:12px; line-height:1.6; flex-shrink:0; }
.qt-bulk-import-preview { flex:0 0 auto; margin:12px 0; }
.qt-bulk-import-preview-q { border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:8px; }
.qt-bulk-import-preview-q-text { font-weight:600; font-size:0.88rem; color:#2d3748; margin-bottom:6px; }
.qt-bulk-import-preview-a { font-size:0.8rem; color:#4a5568; line-height:1.5; padding-left:12px; }
.qt-bulk-import-preview-a.qt-correct { color:#276749; font-weight:600; }
.qt-bulk-import-error { background:#fed7d7; border:1px solid #fc8181; color:#9b2c2c; padding:6px 10px; border-radius:4px; font-size:0.82rem; margin-bottom:6px; }
.qt-bulk-import-success { color:#276749; font-weight:600; font-size:0.88rem; margin-bottom:8px; }
/* Test preview modal */
.qt-preview-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9100; align-items:center; justify-content:center; }
.qt-preview-overlay.qt-open { display:flex; }
.qt-preview-modal { background:#fff; border-radius:8px; padding:24px 26px; max-width:720px; width:95%; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 4px 24px rgba(0,0,0,0.18); }
.qt-preview-info { background:#ebf8ff; border:1px solid #bee3f8; border-radius:6px; padding:8px 14px; font-size:0.85rem; color:#2b6cb0; font-weight:600; margin-bottom:14px; flex-shrink:0; }
/* Which version the preview drew from. A GMR mid-draft must never mistake the running test for
   the one they are building, or vice versa. */
.qt-preview-setchip { display:inline-block; margin-right:8px; padding:1px 9px; border-radius:999px; font-size:0.7rem;
	font-weight:700; text-transform:uppercase; letter-spacing:.04em; vertical-align:middle; }
.qt-preview-setchip-draft { background:#e9d8fd; color:#44337a; }
.qt-preview-setchip-live  { background:#c6f6d5; color:#22543d; }
.qt-preview-setmeta { font-weight:400; color:#4a5568; margin-left:6px; }
.qt-preview-setnote { margin-top:6px; font-weight:400; font-size:0.8rem; color:#553c9a; }
html[data-theme="dark"] .qt-preview-setmeta { color:#a0aec0; }
html[data-theme="dark"] .qt-preview-setnote { color:#d6bcfa; }
.qt-preview-body { overflow-y:auto; flex:1; }
.qt-preview-q { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px; }
.qt-preview-q-text { font-weight:700; font-size:0.92rem; color:#2d3748; margin-bottom:10px; }
.qt-preview-answer { padding:5px 10px; border-radius:4px; font-size:0.85rem; color:#4a5568; margin-bottom:4px; }
.qt-preview-correct { background:#c6f6d5; color:#276749; font-weight:600; }
.qt-preview-btn { padding:6px 14px; border-radius:5px; font-size:0.85rem; font-weight:600; cursor:pointer; border:none; }
.qt-preview-btn-secondary { background:#e2e8f0; color:#2d3748; }
.qt-preview-btn-secondary:hover { background:#cbd5e0; }
/* Preview Draft is tinted like the Draft chip, so the two buttons are never confused at a glance. */
.qt-preview-btn-draft { background:#e9d8fd; color:#44337a; }
.qt-preview-btn-draft:hover { background:#d6bcfa; }
.qt-preview-btn-sub { display:block; font-weight:400; font-size:0.72rem; opacity:.75; margin-top:1px; margin-left:18px; }
.qt-preview-btn-draw { background:#2b6cb0; color:#fff; }
.qt-preview-btn-draw:hover { background:#2c5282; }

/* ── In-product confirm/alert modal (replaces native confirm/alert) ── */
.qt-confirm-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9500; align-items:center; justify-content:center; }
.qt-confirm-overlay.qt-open { display:flex; }
.qt-confirm-modal { background:#fff; border-radius:8px; padding:22px 24px; min-width:300px; max-width:420px; width:100%; box-shadow:0 4px 24px rgba(0,0,0,0.18); }
.qt-confirm-title { margin:0 0 10px; font-size:1rem; font-weight:700; color:#2d3748; }
.qt-confirm-body { font-size:0.9rem; color:#4a5568; line-height:1.5; margin-bottom:18px; }
.qt-confirm-footer { display:flex; gap:10px; justify-content:flex-end; }
.qt-confirm-btn { padding:7px 16px; border-radius:5px; font-size:0.85rem; font-weight:600; cursor:pointer; border:none; }
.qt-confirm-cancel { background:#e2e8f0; color:#2d3748; }
.qt-confirm-cancel:hover { background:#cbd5e0; }
.qt-confirm-ok { background:#2b6cb0; color:#fff; }
.qt-confirm-ok:hover { background:#2c5282; }
.qt-confirm-ok.qt-confirm-danger { background:#e53e3e; }
.qt-confirm-ok.qt-confirm-danger:hover { background:#c53030; }

/* ── Dark mode ────────────────────────────────────────── */
html[data-theme="dark"] .qt-nav-link {
	background: var(--ork-bg-secondary, #2d3748);
	border-color: var(--ork-border, #4a5568);
	color: #63b3ed;
}
html[data-theme="dark"] .qt-nav-link:hover { background: #4a5568; border-color: #718096; color: #90cdf4; }
/* Status badges */
html[data-theme="dark"] .qt-badge-green { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-badge-gray  { background: #4a5568; color: #cbd5e0; }
html[data-theme="dark"] .qt-badge-red   { background: #742a2a; color: #feb2b2; }
/* Action buttons */
html[data-theme="dark"] .qt-action-btn-edit    { background: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .qt-action-btn-edit:hover { background: #718096; }
html[data-theme="dark"] .qt-action-btn-archive { background: #742a2a; color: #feb2b2; }
html[data-theme="dark"] .qt-action-btn-archive:hover { background: #9b2c2c; }
html[data-theme="dark"] .qt-action-btn-restore { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-action-btn-restore:hover { background: #2f855a; }
html[data-theme="dark"] .qt-action-btn-reset   { background: #44337a; color: #d6bcfa; }
html[data-theme="dark"] .qt-action-btn-reset:hover { background: #553c9a; }
html[data-theme="dark"] .qt-action-btn-dup     { background: #2a4365; color: #90cdf4; }
html[data-theme="dark"] .qt-action-btn-dup:hover { background: #2c5282; }
html[data-theme="dark"] .qt-correct-answer     { color: #9ae6b4; }
/* Success-rate cells */
html[data-theme="dark"] .qt-success-green  { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-success-yellow { background: #744210; color: #fefcbf; }
html[data-theme="dark"] .qt-success-red    { background: #742a2a; color: #feb2b2; }
html[data-theme="dark"] .qt-success-none   { background: #4a5568; color: #cbd5e0; }
html[data-theme="dark"] .qt-flag-btn       { color: #fc8181; }
html[data-theme="dark"] .qt-flag-btn:hover { color: #feb2b2; }
/* Library panel */
html[data-theme="dark"] .qt-lib-question-text { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-lib-kingdom { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-lib-answer  { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-lib-answer.qt-lib-correct { color: #9ae6b4; }
html[data-theme="dark"] .qt-lib-flag { color: #fc8181; }
html[data-theme="dark"] .qt-lib-add-btn:disabled { background: #4a5568; color: #a0aec0; }
html[data-theme="dark"] #qt-library-search:focus { border-color: #63b3ed; box-shadow: 0 0 0 3px rgba(99,179,237,0.2); }
/* "N shared questions already in your bank are hidden" note — color moved off the
   inline style so dark mode can lift it off the too-dim #718096. */
.qt-lib-note { color: #718096; }
html[data-theme="dark"] .qt-lib-note { color: #a0aec0; }
/* Report-question modal */
html[data-theme="dark"] .qt-report-modal {
	background: var(--ork-card-bg, #2d3748);
	color: var(--ork-text, #e2e8f0);
}
html[data-theme="dark"] .qt-report-modal h4 { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-report-reason-row {
	border-bottom-color: var(--ork-border, #4a5568);
	color: var(--ork-text-secondary, #cbd5e0);
}
html[data-theme="dark"] .qt-report-count { color: #fc8181; }
html[data-theme="dark"] .qt-report-reporter-row { border-bottom-color: var(--ork-border, #4a5568); }
html[data-theme="dark"] .qt-report-reporter-name a { color: #63b3ed; }
html[data-theme="dark"] .qt-report-reporter-meta { color: var(--ork-text-muted, #a0aec0); }
/* Bulk bar (already dark-friendly bg) — preserve high-contrast inner buttons */
html[data-theme="dark"] .qt-bulk-bar-archive { background: #742a2a; color: #feb2b2; }
html[data-theme="dark"] .qt-bulk-bar-archive:hover { background: #9b2c2c; }
html[data-theme="dark"] .qt-bulk-bar-restore { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-bulk-bar-restore:hover { background: #2f855a; }
/* Bulk import modal */
html[data-theme="dark"] .qt-bulk-import-modal {
	background: var(--ork-card-bg, #2d3748);
	color: var(--ork-text, #e2e8f0);
}
html[data-theme="dark"] .qt-bulk-import-modal h4 { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-bulk-import-instructions {
	background: var(--ork-bg-tertiary, #374151);
	border-color: var(--ork-border, #4a5568);
	color: var(--ork-text-secondary, #cbd5e0);
}
html[data-theme="dark"] .qt-bulk-import-preview-q {
	background: var(--ork-bg-secondary, #2d3748);
	border-color: var(--ork-border, #4a5568);
}
html[data-theme="dark"] .qt-bulk-import-preview-q-text { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-bulk-import-preview-a { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-bulk-import-preview-a.qt-correct { color: #9ae6b4; }
html[data-theme="dark"] .qt-bulk-import-error { background: #742a2a; border-color: #fc8181; color: #feb2b2; }
html[data-theme="dark"] .qt-bulk-import-success { color: #9ae6b4; }
/* Test preview modal */
html[data-theme="dark"] .qt-preview-modal {
	background: var(--ork-card-bg, #2d3748);
	color: var(--ork-text, #e2e8f0);
}
html[data-theme="dark"] .qt-preview-info {
	background: #2a4365;
	border-color: #4299e1;
	color: #90cdf4;
}
html[data-theme="dark"] .qt-preview-q {
	background: var(--ork-bg-secondary, #2d3748);
	border-color: var(--ork-border, #4a5568);
}
html[data-theme="dark"] .qt-preview-q-text { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-preview-answer { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-preview-correct { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-preview-btn-secondary { background: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .qt-preview-btn-secondary:hover { background: #718096; }
html[data-theme="dark"] .qt-confirm-modal { background: var(--ork-bg-secondary, #2d3748); }
html[data-theme="dark"] .qt-confirm-title { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-confirm-body { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-confirm-cancel { background: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .qt-confirm-cancel:hover { background: #718096; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-scroll rp-header-icon"></i>
				<h1 class="rp-header-title"><?= $typeLabel ?> Questions</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-chess-rook rp-scope-chip-label"></i>
					<?= htmlspecialchars($KingdomName) ?>
				</span>
			</div>
		</div>
		<div class="rp-header-actions">
			<a class="rp-btn-ghost" href="<?= UIR ?>QualTest/question/create/<?= $KingdomId ?>/<?= $TestType ?>">
				<i class="fas fa-plus"></i> Add Question
			</a>
			<button class="rp-btn-ghost" id="qt-bulkimport-btn">
				<i class="fas fa-file-import"></i> Bulk Import
			</button>
			<?php if ($TestType === 'reeve' && !empty($Config['ShareQuestions'])): ?>
			<button class="rp-btn-ghost" id="qt-library-btn" style="background:#ebf8ff;color:#2b6cb0;border-color:#bee3f8;">
				<i class="fas fa-globe"></i> Add from Library
			</button>
			<?php endif; ?>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>
			<?= count($activeQs) ?> active question<?= count($activeQs) !== 1 ? 's' : '' ?> &mdash;
			test draws <?= (int)$Config['QuestionCount'] ?> at random,
			requires <?= (int)$Config['PassPercent'] ?>% to pass,
			<?php if (!empty($Config['ValidUntil'])): ?>valid until <?= htmlspecialchars(date('F j, Y', strtotime($Config['ValidUntil']))) ?>.<?php else: ?>valid for <?= (int)$Config['ValidDays'] ?> days.<?php endif; ?>
		</span>
	</div>

	<?php // The values above come from getConfig(), which falls back to DEFAULTS when
	      // no qual_config row was ever saved. Without this warning the settings look
	      // deliberate when nothing was actually chosen (and sharing stays opted out). ?>
	<?php if (empty($Config['Configured'])): ?>
	<div class="qt-unsaved-warning">
		<i class="fas fa-exclamation-triangle"></i>
		<span>
			<strong>This test has no saved configuration.</strong>
			The numbers above are <em>defaults</em>, not settings anyone chose &mdash; nothing is stored for this
			kingdom's <?= htmlspecialchars($typeLabel) ?>. The test still runs on them, but they can change
			under you<?php if ($TestType === 'reeve'): ?>, and the kingdom is <strong>not</strong> opted in to the
			Global Question Library<?php endif; ?>.
			<a href="<?= UIR ?>QualTest/manage/<?= (int)$KingdomId ?>">Open test settings and Save</a> to make them explicit.
		</span>
	</div>
	<?php endif; ?>

	<?php
		// ── Versions ────────────────────────────────────────────────────────────
		// The live test draws ONLY from the published set, so a draft can be built
		// freely without touching the running test. New questions land in the draft
		// while one exists; otherwise they go straight live.
		$_live  = $PublishedSet ?? null;
		$_draft = $DraftSet ?? null;
		$_target = (int)($TargetSetId ?? 0);
	?>
	<div class="qt-versions">
		<?php // Publishing a version does NOT make the test takeable — the kingdom must also
		      // have the test switched on. That toggle lives in Kingdom Configuration and needs
		      // kingdom authority, which a GMR does NOT have. Say so plainly, or "Published"
		      // reads as "players can take it" when nobody can. ?>
		<?php if (empty($TestEnabled)): ?>
		<div class="qt-notlive-warning">
			<i class="fas fa-power-off"></i>
			<span>
				<strong>The <?= htmlspecialchars($typeLabel) ?> is switched off for this kingdom.</strong>
				Nothing below is available to players &mdash; not even a published version &mdash; until it is turned on.
				The switch is on the <strong>Kingdom page</strong>, under the <strong>Admin</strong> button (the cog at the top)
				&rarr; <strong>Configuration</strong>. Only the monarchy (Monarch, Regent, or Prime Minister) can open that panel
				&mdash; as GMR or a Test Manager you won't see the Admin button at all, so you'll need to ask them to turn it on.
				You can still write, version and publish questions here; they simply won't be asked of anyone yet.
			</span>
		</div>
		<?php endif; ?>
		<?php
		  // "Live" must mean exactly one thing: a player can be asked these questions RIGHT NOW.
		  // Three things have to hold, and the chip reports all three honestly.
		  //   1. a published version exists,
		  //   2. it holds at least QuestionCount active questions — the draw is a LIMIT n that
		  //      returns NULL when it cannot fill the test, and the start endpoint then refuses
		  //      outright ("Not enough active questions"), so a short version is not merely a
		  //      shorter test: it is NO test,
		  //   3. the kingdom has switched the test on (Kingdom Configuration; monarchy only).
		  // MemberCount counts ACTIVE members, so it is precisely what the draw can reach.
		  $_liveCount = (int)($_live['MemberCount'] ?? 0);
		  $_need      = (int)($Config['QuestionCount'] ?? 0);
		  $_ready     = ($_live && $_need > 0 && $_liveCount >= $_need);
		  $_isLive    = ($_ready && !empty($TestEnabled));
		?>
		<div class="qt-ver-row">
			<?php if ($_isLive): ?>
				<span class="qt-ver-chip qt-ver-live"><i class="fas fa-check-circle"></i> Live</span>
			<?php elseif ($_ready): ?>
				<span class="qt-ver-chip qt-ver-warn"><i class="fas fa-power-off"></i> Published (test off)</span>
			<?php else: ?>
				<span class="qt-ver-chip qt-ver-off"><i class="fas fa-minus-circle"></i> Not live</span>
			<?php endif; ?>

			<?php if ($_live): ?>
				<strong class="qt-ver-name" data-set="<?= (int)$_live['SetId'] ?>"><?= htmlspecialchars($_live['Name']) ?></strong>
				<button type="button" class="qt-ver-rename" data-set="<?= (int)$_live['SetId'] ?>" title="Rename this version"><i class="fas fa-pen"></i></button>
				<?php // The rules/corpora label is editable on the LIVE version too, not just on a draft.
				      // Publishing REQUIRES it, so a typo used to be unfixable — the only remedy was
				      // publishing a whole new version to correct a string. Safe to change in place:
				      // past attempts keep the label they were STAMPED with, so history stays truthful;
				      // only the test footer and future attempts move. ?>
				<span class="qt-ver-label<?= ($_live['RulesVersion'] ?? '') === '' ? ' qt-ver-nolabel' : '' ?>"
				      data-set="<?= (int)$_live['SetId'] ?>"><?= ($_live['RulesVersion'] ?? '') !== '' ? htmlspecialchars($_live['RulesVersion']) : 'no version label' ?></span>
				<button type="button" class="qt-ver-editlabel" data-set="<?= (int)$_live['SetId'] ?>"
				        title="Edit the rules/corpora version. Players see it as a footer on every test card. Past attempts keep the version they were sat under."><i class="fas fa-pen"></i></button>
				<span class="qt-ver-meta"><?= $_liveCount ?> question<?= $_liveCount !== 1 ? 's' : '' ?></span>
				<?php if (!$_ready): ?>
					<span class="qt-ver-short"><i class="fas fa-exclamation-triangle"></i>
						the test draws <?= $_need ?> &mdash; nobody can take it until this version has <?= $_need ?></span>
				<?php endif; ?>
			<?php elseif ($_draft): ?>
				<span class="qt-ver-meta">Nothing is live yet. Publish the draft below when it is ready.</span>
			<?php else: ?>
				<span class="qt-ver-meta">Nothing is live yet &mdash; add your first question to start building a version.</span>
			<?php endif; ?>

			<?php // Nothing to succeed until a version is actually live, so offering "next version"
			      // would build v2 of a v1 that never shipped. Hidden until there is one. ?>
			<?php if (!$_draft && $_live): ?>
				<button type="button" class="qt-ver-btn" id="qt-newdraft-btn"><i class="fas fa-code-branch"></i> Start next version</button>
			<?php endif; ?>
		</div>

		<?php if ($_draft): ?>
		<?php
		  // The FIRST version is a draft like any other, so this row serves two situations:
		  // building v1 (nothing live behind it) and building the successor to a running test.
		  // The reassurance "the live test is unaffected" is meaningless in the first case.
		  $_draftCount = (int)($_draft['MemberCount'] ?? 0);
		  $_draftVer   = trim((string)($_draft['RulesVersion'] ?? ''));
		  $_firstVer   = ($_live === null);
		  // publishSet() rejects a draft with no version label or too few questions. Rather than
		  // let the GMR click Publish and collect an error, disable it and say what is missing.
		  $_pubBlock   = [];
		  if ($_draftVer === '')                        { $_pubBlock[] = 'a rules/corpora version'; }
		  if ($_need > 0 && $_draftCount < $_need)      { $_pubBlock[] = ($_need - $_draftCount) . ' more question' . (($_need - $_draftCount) === 1 ? '' : 's'); }
		?>
		<div class="qt-ver-row qt-ver-draftrow">
			<span class="qt-ver-chip qt-ver-draft"><i class="fas fa-pen"></i> Draft</span>
			<strong class="qt-ver-name" data-set="<?= (int)$_draft['SetId'] ?>"><?= htmlspecialchars($_draft['Name']) ?></strong>
			<button type="button" class="qt-ver-rename" data-set="<?= (int)$_draft['SetId'] ?>" title="Rename this version"><i class="fas fa-pen"></i></button>
			<input type="text" id="qt-draft-version" class="qt-ver-input" placeholder="Rules / Corpora version (required)"
			       value="<?= htmlspecialchars($_draft['RulesVersion']) ?>" data-set="<?= (int)$_draft['SetId'] ?>">
			<span class="qt-ver-meta"><?= $_draftCount ?> question<?= $_draftCount !== 1 ? 's' : '' ?><?= $_need > 0 ? ' of ' . $_need : '' ?></span>
			<button type="button" class="qt-ver-btn qt-ver-publish" id="qt-publish-btn"
			        data-set="<?= (int)$_draft['SetId'] ?>"
			        data-need="<?= $_need ?>" data-have="<?= $_draftCount ?>"
			        <?= $_pubBlock ? 'disabled' : '' ?>
			        title="<?= $_pubBlock ? htmlspecialchars('Needs ' . implode(' and ', $_pubBlock) . ' before publishing.') : 'Make this the live test' ?>">
				<i class="fas fa-upload"></i> Publish
			</button>
			<button type="button" class="qt-ver-btn qt-ver-discard" id="qt-discard-btn" data-set="<?= (int)$_draft['SetId'] ?>"><i class="fas fa-trash"></i> Discard</button>
		</div>
		<div class="qt-ver-note">
			<i class="fas fa-info-circle"></i>
			<?php if ($_firstVer): ?>
				You are building the <strong>first version</strong> of this test. <strong>Nothing is live until you publish it</strong> &mdash;
				players cannot take the test yet, even if the kingdom has switched it on. Add questions at your own pace,
				set the rules/corpora version, then publish when you are ready.
			<?php else: ?>
				You are building the next version. <strong>The live test is unaffected until you publish.</strong>
				New questions (add, bulk import, library) go into the draft. Removing a question from the draft
				does <em>not</em> archive it &mdash; it stays live in the current version.
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php
		  // Retired versions. Publishing never deletes anything — publishSet() only flips the
		  // outgoing version to 'retired', membership intact — so every version this kingdom has
		  // ever run can still be read back in full. Without this list that history was reachable
		  // only from the database.
		  $_retired = [];
		  foreach (($Sets ?? []) as $_s) {
			  if (($_s['Status'] ?? '') === 'retired') { $_retired[] = $_s; }
		  }
		?>
		<?php if ($_retired): ?>
		<?php // Collapsed by default, and it has to be: this list only ever grows — two versions a
		      // reign, forever. Laid out inline it would eventually push the questions off the page.
		      // Old versions are reference material, not something you need on every visit.
		      // <details> gives the disclosure behaviour natively, so there is no JS to get wrong. ?>
		<details class="qt-ver-past-wrap">
			<summary class="qt-ver-pasthdr">
				<i class="fas fa-chevron-right qt-ver-caret"></i>
				<i class="fas fa-history"></i>
				Previous versions <span class="qt-ver-meta">(<?= count($_retired) ?>)</span>
			</summary>
			<div class="qt-ver-pastlist">
				<?php foreach ($_retired as $_s): ?>
				<button type="button" class="qt-ver-past qt-verview-btn" data-set="<?= (int)$_s['SetId'] ?>">
					<strong><?= htmlspecialchars($_s['Name']) ?></strong>
					<?php if (($_s['RulesVersion'] ?? '') !== ''): ?>
						<span class="qt-ver-meta"><?= htmlspecialchars($_s['RulesVersion']) ?></span>
					<?php else: ?>
						<span class="qt-ver-meta qt-ver-nolabel" data-tip="Published before a version label was required">no version label</span>
					<?php endif; ?>
					<?php // TotalCount, not MemberCount: a version CONTAINED what it contained. Counting
					      // only still-active questions would shrink history each time one is archived. ?>
					<span class="qt-ver-meta"><?= (int)$_s['TotalCount'] ?> question<?= ((int)$_s['TotalCount']) !== 1 ? 's' : '' ?></span>
					<?php if (!empty($_s['PublishedAt'])): ?>
						<span class="qt-ver-meta">ran from <?= date('M j, Y', strtotime($_s['PublishedAt'])) ?></span>
					<?php endif; ?>
					<span class="qt-ver-view"><i class="fas fa-eye"></i> View</span>
				</button>
				<?php endforeach; ?>
			</div>
		</details>
		<?php endif; ?>
	</div>

	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sitemap"></i> Navigation</div>
				<div class="rp-filter-card-body" style="display:flex;flex-direction:column;gap:8px;">
					<a class="qt-nav-link" href="<?= UIR ?>QualTest/manage/<?= $KingdomId ?>">
						<i class="fas fa-arrow-left"></i> Configure Tests
					</a>
					<a class="qt-nav-link" href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/<?= $TestType === 'reeve' ? 'corpora' : 'reeve' ?>">
						<i class="fas fa-exchange-alt"></i> Switch to <?= $TestType === 'reeve' ? 'Corpora Test' : "Reeve's Test" ?>
					</a>
					<a class="qt-nav-link" href="<?= UIR ?>Kingdom/profile/<?= $KingdomId ?>">
						<i class="fas fa-chess-rook"></i> Kingdom Profile
					</a>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-cog"></i> Test Configuration</div>
				<div class="rp-filter-card-body" style="font-size:13px;line-height:1.7;color:var(--rp-text-body);">
					<div><strong><?= (int)$Config['QuestionCount'] ?></strong> questions per test</div>
					<div><strong><?= (int)$Config['PassPercent'] ?>%</strong> required to pass</div>
					<?php if (!empty($Config['ValidUntil'])): ?><div><strong><?= htmlspecialchars(date('F j, Y', strtotime($Config['ValidUntil']))) ?></strong> expiry date</div><?php else: ?><div><strong><?= (int)$Config['ValidDays'] ?></strong> days validity</div><?php endif; ?>
					<div style="margin-top:8px;">
						<a href="<?= UIR ?>QualTest/manage/<?= $KingdomId ?>" style="font-size:12px;color:#2b6cb0;">
							<i class="fas fa-edit"></i> Edit settings
						</a>
					</div>
					<?php // One button per version that exists, rather than one button that silently picks
					      // for you. Two when a draft is open, one when only the live test exists, none
					      // when there is nothing to preview — you always know what you are about to see
					      // BEFORE you click, not from a chip afterwards. ?>
					<?php if ($_live || $_draft): ?>
					<div style="margin-top:8px;display:flex;flex-direction:column;gap:6px;">
						<?php if ($_draft): ?>
						<button class="qt-preview-btn qt-preview-btn-draft qt-preview-open"
						        data-set="<?= (int)$_draft['SetId'] ?>"
						        style="font-size:12px;width:100%;text-align:left;">
							<i class="fas fa-eye"></i> Preview Draft
							<span class="qt-preview-btn-sub"><?= htmlspecialchars($_draft['Name']) ?></span>
						</button>
						<?php endif; ?>
						<?php if ($_live): ?>
						<button class="qt-preview-btn qt-preview-btn-secondary qt-preview-open"
						        data-set="<?= (int)$_live['SetId'] ?>"
						        style="font-size:12px;width:100%;text-align:left;">
							<i class="fas fa-eye"></i> Preview Live Test
							<span class="qt-preview-btn-sub"><?= htmlspecialchars($_live['Name']) ?></span>
						</button>
						<?php endif; ?>

						<?php // Downloads in the SAME format Bulk Import reads, so a bank round-trips:
						      // export it, edit it in any text editor, paste it back. Version-scoped for
						      // the same reason Preview is — "export the test" means nothing while a
						      // draft and a live version both exist. ?>
						<?php // Purple = draft, everywhere: the Draft chip, Preview Draft, and this. The two
					      // draft actions were different colours, which made the tint look decorative
					      // rather than meaningful. ?>
					<?php $_exp = $_draft ?: $_live; ?>
						<a class="qt-preview-btn <?= $_draft ? 'qt-preview-btn-draft' : 'qt-preview-btn-secondary' ?>"
						   href="<?= UIR ?>QualTest/export/<?= (int)$_exp['SetId'] ?>"
						   style="font-size:12px;width:100%;text-align:left;text-decoration:none;display:block;box-sizing:border-box;">
							<i class="fas fa-download"></i> Export <?= $_draft ? 'Draft' : 'Live Test' ?>
							<span class="qt-preview-btn-sub"><?= htmlspecialchars($_exp['Name']) ?></span>
						</a>
						<?php if ($_draft && $_live): ?>
						<a class="qt-preview-btn qt-preview-btn-secondary"
						   href="<?= UIR ?>QualTest/export/<?= (int)$_live['SetId'] ?>"
						   style="font-size:12px;width:100%;text-align:left;text-decoration:none;display:block;box-sizing:border-box;">
							<i class="fas fa-download"></i> Export Live Test
							<span class="qt-preview-btn-sub"><?= htmlspecialchars($_live['Name']) ?></span>
						</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">

			<!-- Active questions -->
			<div class="rp-table-section-title">
				Active Questions
				<span style="font-weight:400;font-size:13px;color:var(--rp-text-muted);margin-left:8px;">(<?= count($activeQs) ?>)</span>
			</div>

			<?php if (count($activeQs) > 0): ?>
			<table id="qt-active-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb" id="qt-active-select-all" data-tip="Select all on this page"></th>
						<th>Question</th>
						<th>Answers</th>
						<th>% Success</th>
						<th>Added</th>
						<th style="width:140px">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($activeQs as $q): ?>
					<tr id="qrow-<?= $q['QualQuestionId'] ?>">
						<td class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb qt-active-cb" data-id="<?= $q['QualQuestionId'] ?>"></td>
						<td>
							<?= htmlspecialchars($q['QuestionText']) ?>
							<?php if (($q['AnswerMode'] ?? 'single') === 'multi'): ?>
							<span class="qt-multi-badge">multi</span>
							<?php endif; ?>
							<?php // Which version(s) is this question part of? "Unused" = written but in
							      // no version — still fine, still reusable, just not being asked. ?>
							<?php if (!empty($q['InLive'])): ?><span class="qt-mem qt-mem-live" data-tip="In the live test">Live</span><?php endif; ?>
							<?php if (!empty($q['InDraft'])): ?><span class="qt-mem qt-mem-draft" data-tip="In the draft version">Draft</span><?php endif; ?>
							<?php if (!empty($q['Unused'])): ?><span class="qt-mem qt-mem-unused" data-tip="Not in any version — not being asked">Unused</span><?php endif; ?>
						<?php // Imported from the Global Question Library. Tells a GMR at a glance which
						      // questions their kingdom wrote and which it inherited — and it survives
						      // rewording, because the origin is a stored id, not a text match. The
						      // source kingdom's name may be missing (kingdom removed), so fall back. ?>
						<?php if (!empty($q['SourceQuestionId'])): ?>
							<span class="qt-mem qt-mem-lib" data-tip="Imported from the Global Question Library<?= $q['SourceKingdom'] !== '' ? ' — originally from ' . htmlspecialchars($q['SourceKingdom']) : '' ?>. Your copy is independent: edits here do not affect them.">
								<i class="fas fa-globe"></i>
								<?= $q['SourceKingdom'] !== '' ? htmlspecialchars($q['SourceKingdom']) : 'Library' ?>
							</span>
						<?php endif; ?>
							<?php if ($_draft): ?>
							<button type="button" class="qt-mem-btn qt-draft-toggle <?= !empty($q['InDraft']) ? 'qt-mem-in' : '' ?>"
							        data-id="<?= (int)$q['QualQuestionId'] ?>" data-set="<?= (int)$_draft['SetId'] ?>"
							        data-in="<?= !empty($q['InDraft']) ? '1' : '0' ?>">
								<?= !empty($q['InDraft']) ? '&minus; Remove from draft' : '+ Add to draft' ?>
							</button>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($q['CorrectCount'] > 0): ?>
								<span class="qt-badge qt-badge-green"><?= $q['AnswerCount'] ?> answers</span>
								<div class="qt-correct-answer"><i class="fas fa-check" style="color:#276749"></i> <?= htmlspecialchars($q['CorrectText']) ?></div>
							<?php else: ?>
								<span class="qt-badge qt-badge-red"><?= $q['AnswerCount'] ?> &mdash; no correct!</span>
							<?php endif; ?>
						</td>
						<td><?php
							$ta = $q['TimesAnswered'];
							if ($ta > 0) {
								$pct = round($q['TimesCorrect'] / $ta * 100);
								$cls = $pct >= 81 ? 'qt-success-green' : ($pct >= 61 ? 'qt-success-yellow' : 'qt-success-red');
								echo '<span class="qt-success-badge ' . $cls . '">' . $pct . '%</span>';
								echo '<div style="font-size:0.72rem;color:var(--rp-text-muted);margin-top:2px;">' . $q['TimesCorrect'] . '/' . $ta . '</div>';
							} else {
								echo '<span class="qt-success-badge qt-success-none">—</span>';
							}
						?></td>
						<td><?= date('Y-m-d', strtotime($q['CreatedAt'])) ?></td>
						<td>
							<div class="qt-actions-cell">
								<a class="qt-action-btn qt-action-btn-edit"
								   href="<?= UIR ?>QualTest/question/edit/<?= $q['QualQuestionId'] ?>">
									<i class="fas fa-edit"></i> Edit
								</a>
								<button class="qt-action-btn qt-action-btn-archive qt-status-btn" data-tip="Archive"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>"
								        data-status="archived">
									<i class="fas fa-archive"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-reset qt-reset-btn" data-tip="Reset Stats"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-sync-alt"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-dup qt-dup-btn" data-tip="Duplicate"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-copy"></i>
								</button>
								<?php if ($q['ReportCount'] > 0): ?>
								<button class="qt-flag-btn qt-report-flag-btn" data-tip="<?= $q['ReportCount'] ?> report<?= $q['ReportCount'] !== 1 ? 's' : '' ?>"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-flag"></i>
								</button>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else: ?>
			<div class="rp-empty-state">
				<i class="fas fa-scroll"></i>
				No active questions yet.
				<a href="<?= UIR ?>QualTest/question/create/<?= $KingdomId ?>/<?= $TestType ?>">Add the first one.</a>
			</div>
			<?php endif; ?>

			<?php if (count($archivedQs) > 0): ?>
			<!-- Archived questions (collapsible) -->
			<div class="rp-table-section-title" style="margin-top:32px;cursor:pointer;user-select:none;" id="qt-archived-toggle">
				<i class="fas fa-chevron-right" id="qt-archived-chevron" style="font-size:0.75em;margin-right:4px;transition:transform 0.2s;"></i>
				Archived Questions
				<span style="font-weight:400;font-size:13px;color:var(--rp-text-muted);margin-left:8px;">(<?= count($archivedQs) ?>)</span>
			</div>
			<div id="qt-archived-panel" style="display:none;">
			<table id="qt-archived-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb" id="qt-archived-select-all" data-tip="Select all on this page"></th>
						<th>Question</th>
						<th>Answers</th>
						<th>% Success</th>
						<th>Added</th>
						<th style="width:140px">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($archivedQs as $q): ?>
					<tr id="qrow-<?= $q['QualQuestionId'] ?>">
						<td class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb qt-archived-cb" data-id="<?= $q['QualQuestionId'] ?>"></td>
						<td style="color:var(--rp-text-muted)"><?= htmlspecialchars($q['QuestionText']) ?></td>
						<td>
							<span class="qt-badge qt-badge-gray"><?= $q['AnswerCount'] ?> answers</span>
							<?php if (!empty($q['CorrectText'])): ?>
							<div class="qt-correct-answer" style="color:var(--rp-text-muted)"><?= htmlspecialchars($q['CorrectText']) ?></div>
							<?php endif; ?>
						</td>
						<td><?php
							$ta = $q['TimesAnswered'];
							if ($ta > 0) {
								$pct = round($q['TimesCorrect'] / $ta * 100);
								$cls = $pct >= 81 ? 'qt-success-green' : ($pct >= 61 ? 'qt-success-yellow' : 'qt-success-red');
								echo '<span class="qt-success-badge ' . $cls . '">' . $pct . '%</span>';
								echo '<div style="font-size:0.72rem;color:var(--rp-text-muted);margin-top:2px;">' . $q['TimesCorrect'] . '/' . $ta . '</div>';
							} else {
								echo '<span class="qt-success-badge qt-success-none">—</span>';
							}
						?></td>
						<td><?= date('Y-m-d', strtotime($q['CreatedAt'])) ?></td>
						<td>
							<div class="qt-actions-cell">
								<a class="qt-action-btn qt-action-btn-edit"
								   href="<?= UIR ?>QualTest/question/edit/<?= $q['QualQuestionId'] ?>">
									<i class="fas fa-edit"></i> Edit
								</a>
								<button class="qt-action-btn qt-action-btn-restore qt-status-btn" data-tip="Restore"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>"
								        data-status="active">
									<i class="fas fa-check-circle"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-reset qt-reset-btn" data-tip="Reset Stats"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-sync-alt"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-dup qt-dup-btn" data-tip="Duplicate"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-copy"></i>
								</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div><!-- /#qt-archived-panel -->
			<?php endif; ?>

		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<!-- Bulk action bar -->
<div class="qt-bulk-bar" id="qt-bulk-bar">
	<span class="qt-bulk-bar-count" id="qt-bulk-count">0 selected</span>
	<button class="qt-bulk-bar-action qt-bulk-bar-archive" id="qt-bulk-archive" style="display:none;">
		<i class="fas fa-archive"></i> Archive Selected
	</button>
	<button class="qt-bulk-bar-action qt-bulk-bar-restore" id="qt-bulk-restore" style="display:none;">
		<i class="fas fa-check-circle"></i> Restore Selected
	</button>
	<button class="qt-bulk-bar-deselect" id="qt-bulk-deselect">Deselect All</button>
</div>

<!-- Version contents modal (read-only; used for previous versions) -->
<div class="qt-preview-overlay" id="qt-verview-overlay">
	<div class="qt-preview-modal">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-history" style="color:#2b6cb0;margin-right:6px;"></i> <span id="qt-verview-title">Version</span></h4>
			<button id="qt-verview-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div class="qt-preview-info" id="qt-verview-info"></div>
		<div class="qt-preview-body" id="qt-verview-body">
			<div style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading&hellip;</div>
		</div>
		<div style="display:flex;gap:8px;margin-top:14px;flex-shrink:0;">
			<?php // Retired versions are exportable too — this is the one place an old bank can be
			      // pulled back out as text, e.g. to revive a question set a previous GMR wrote. ?>
			<a class="qt-preview-btn qt-preview-btn-secondary" id="qt-verview-export"
			   href="#" style="text-decoration:none;"><i class="fas fa-download"></i> Export as text</a>
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-verview-close-footer">Close</button>
		</div>
	</div>
</div>

<!-- Test Preview modal -->
<div class="qt-preview-overlay" id="qt-preview-overlay">
	<div class="qt-preview-modal">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-eye" style="color:#2b6cb0;margin-right:6px;"></i> Test Preview &mdash; <?= $typeLabel ?></h4>
			<button id="qt-preview-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div class="qt-preview-info" id="qt-preview-info"></div>
		<div class="qt-preview-body" id="qt-preview-body">
			<div style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading preview&hellip;</div>
		</div>
		<div style="display:flex;gap:8px;margin-top:14px;flex-shrink:0;">
			<button class="qt-preview-btn qt-preview-btn-draw" id="qt-preview-draw" style="display:none;"><i class="fas fa-random"></i> Draw Again</button>
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-preview-close-footer">Close</button>
		</div>
	</div>
</div>

<!-- Bulk Import modal -->
<div class="qt-report-overlay" id="qt-bulkimport-overlay">
	<div class="qt-bulk-import-modal">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-file-import" style="color:#2b6cb0;margin-right:6px;"></i> Bulk Import Questions</h4>
			<button id="qt-bulkimport-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div class="qt-bulk-import-body">
		<div class="qt-bulk-import-instructions">Paste questions separated by a blank line.
First line = question text.
Subsequent lines = answers (prefix with * for correct).
Two or more * answers = "select all that apply".
For a select-all question with only ONE correct answer, put [multi] on its own line above the question.
Letter prefixes like A) B) are optional and stripped.

Example:
What color is the sky?
A) Green
*B) Blue
C) Red

[multi]
Which of these is a primary color?
*A) Blue
B) Green
C) Orange</div>
		<textarea id="qt-bulkimport-text" aria-label="Paste questions here" rows="6" placeholder="Paste your questions here..." style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;font-size:0.88rem;font-family:inherit;resize:vertical;flex:0 0 auto;min-height:96px;"></textarea>
		<div class="qt-bulk-import-preview" id="qt-bulkimport-preview"></div>
		</div><!-- /.qt-bulk-import-body -->
		<div style="display:flex;gap:8px;margin-top:10px;flex-shrink:0;">
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-bulkimport-parse"><i class="fas fa-search"></i> Parse &amp; Preview</button>
			<button class="qt-preview-btn qt-preview-btn-draw" id="qt-bulkimport-submit" disabled><i class="fas fa-file-import"></i> Import Questions</button>
			<?php // Shown only AFTER a successful import, in place of the import button. Clears the
			      // box so a second batch can be pasted — importing the same text twice would just
			      // create duplicates, which is what the old dead "Done" button left you one click
			      // away from doing. ?>
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-bulkimport-more" style="display:none;"><i class="fas fa-plus"></i> Import Another Batch</button>
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-bulkimport-cancel">Close</button>
		</div>
	</div>
</div>

<!-- Global Question Library modal -->
<?php if ($TestType === 'reeve' && !empty($Config['ShareQuestions'])): ?>
<div class="qt-report-overlay" id="qt-library-overlay">
	<?php // Fixed height (not max-height) so the modal does NOT resize as the filter
	      // narrows results — the list area stays constant and just scrolls. ?>
	<div class="qt-report-modal" style="max-width:720px;width:95%;height:80vh;max-height:80vh;box-sizing:border-box;display:flex;flex-direction:column;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-globe" style="color:#2b6cb0;margin-right:6px;"></i> Global Question Library</h4>
			<button id="qt-library-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div id="qt-library-search-wrap" style="display:none;flex-shrink:0;margin-bottom:12px;">
			<input type="text" id="qt-library-search" placeholder="Filter by question text or kingdom&hellip;" autocomplete="off"
			       style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #cbd5e0;border-radius:6px;font-size:0.88rem;outline:none;">
		</div>
		<div id="qt-library-loading" style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading library&hellip;</div>
		<div id="qt-library-empty" style="display:none;text-align:center;padding:32px;color:#718096;">No questions available from other kingdoms yet.</div>
		<?php // The modal now has a fixed height, so the list fills the remaining space
		      // and scrolls. min-height:0 lets it shrink below its content (without it,
		      // a flex item won't shrink past its content and would push the modal). ?>
		<div id="qt-library-body" style="display:none;overflow-y:auto;flex:1 1 auto;min-height:0;">
			<div id="qt-library-list"></div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Report popup -->
<div class="qt-report-overlay" id="qt-report-overlay">
	<div class="qt-report-modal">
		<h4><i class="fas fa-flag"></i> Question Reports</h4>
		<div id="qt-report-loading" style="font-size:0.88rem;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading&hellip;</div>
		<div id="qt-report-body" style="display:none;">
			<div class="qt-report-reason-row"><span>Question is worded poorly</span><span class="qt-report-count" id="qr-wording">0</span></div>
			<div class="qt-report-reason-row"><span>My answer was correct</span><span class="qt-report-count" id="qr-correct">0</span></div>
			<div class="qt-report-reason-row"><span>Not updated for recent changes</span><span class="qt-report-count" id="qr-outdated">0</span></div>
			<div class="qt-report-reason-row"><span>Other</span><span class="qt-report-count" id="qr-other">0</span></div>
			<div id="qt-report-reporters"></div>
		</div>
		<div class="qt-report-footer">
			<button class="qt-action-btn" id="qt-report-close" style="background:#e2e8f0;color:#2d3748;">Close</button>
			<button class="qt-action-btn" id="qt-report-clear" style="display:none;background:#fff5f5;color:#c53030;border:1px solid #feb2b2;"><i class="fas fa-times"></i> Clear Flags</button>
			<button class="qt-action-btn qt-action-btn-archive" id="qt-report-archive" style="display:none;"><i class="fas fa-archive"></i> Archive Question</button>
			<a class="qt-action-btn qt-action-btn-edit" id="qt-report-edit" href="#" style="display:none;"><i class="fas fa-edit"></i> Edit Question</a>
		</div>
	</div>
</div>

<!-- In-product confirm/alert modal -->
<div class="qt-confirm-overlay" id="qt-confirm-overlay">
	<div class="qt-confirm-modal">
		<h4 class="qt-confirm-title" id="qt-confirm-title"></h4>
		<div class="qt-confirm-body" id="qt-confirm-body"></div>
		<div class="qt-confirm-footer">
			<button type="button" class="qt-confirm-btn qt-confirm-cancel" id="qt-confirm-cancel">Cancel</button>
			<button type="button" class="qt-confirm-btn qt-confirm-ok" id="qt-confirm-ok">OK</button>
		</div>
	</div>
</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
// In-product replacement for native confirm()/alert().
// qtConfirm({title, body, confirmLabel, danger, okOnly, onConfirm})
var qtConfirm = (function() {
	var overlay  = document.getElementById('qt-confirm-overlay');
	var titleEl  = document.getElementById('qt-confirm-title');
	var bodyEl   = document.getElementById('qt-confirm-body');
	var cancelEl = document.getElementById('qt-confirm-cancel');
	var okEl     = document.getElementById('qt-confirm-ok');
	var pending  = null;
	function close() { overlay.classList.remove('qt-open'); pending = null; }
	okEl.addEventListener('click', function() { var cb = pending; close(); if (cb) cb(); });
	cancelEl.addEventListener('click', close);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
	return function(opts) {
		opts = opts || {};
		titleEl.textContent = opts.title || 'Please Confirm';
		bodyEl.textContent  = opts.body || '';
		okEl.textContent    = opts.confirmLabel || (opts.okOnly ? 'OK' : 'Confirm');
		okEl.classList.toggle('qt-confirm-danger', !!opts.danger);
		cancelEl.style.display = opts.okOnly ? 'none' : '';
		pending = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
		overlay.classList.add('qt-open');
	};
})();
// Convenience inline error (replaces native alert in fetch error paths)
function qtAlert(msg) { qtConfirm({ title: 'Error', body: msg, okOnly: true }); }

$(function() {
	var dtOpts = { pageLength: 25, order: [[4, 'desc']], columnDefs: [{ orderable: false, targets: [0, 5] }] };
	var activeTable = null, archivedTable = null;

	if ($('#qt-active-table tbody tr').length > 0) {
		activeTable = $('#qt-active-table').DataTable(dtOpts);
	}
	// Archived table init deferred until panel is first shown (DataTables + display:none = broken widths)
	var archivedInited = false;

	// ----- Archived panel toggle -----
	var archivedToggle  = document.getElementById('qt-archived-toggle');
	var archivedPanel   = document.getElementById('qt-archived-panel');
	var archivedChevron = document.getElementById('qt-archived-chevron');
	if (archivedToggle) {
		archivedToggle.addEventListener('click', function() {
			var open = archivedPanel.style.display === 'none';
			archivedPanel.style.display = open ? '' : 'none';
			archivedChevron.style.transform = open ? 'rotate(90deg)' : '';
			if (open && !archivedInited && $('#qt-archived-table tbody tr').length > 0) {
				archivedTable = $('#qt-archived-table').DataTable(dtOpts);
				archivedInited = true;
				// Bind checkbox events for the newly-inited table
				archivedTable.on('draw.dt', function() {
					var t = document.getElementById('qt-archived-table');
					rebindCheckboxes(t, archivedSelected, 'qt-archived-cb');
					rebindSelectAll('qt-archived-select-all', t, archivedSelected, 'qt-archived-cb');
				});
				archivedTable.draw();
			}
		});
	}

	// ----- Bulk checkbox selection -----
	var activeSelected   = new Set();
	var archivedSelected = new Set();
	var bulkBar      = document.getElementById('qt-bulk-bar');
	var bulkCount    = document.getElementById('qt-bulk-count');
	var bulkArchive  = document.getElementById('qt-bulk-archive');
	var bulkRestore  = document.getElementById('qt-bulk-restore');
	var bulkDeselect = document.getElementById('qt-bulk-deselect');

	function updateBulkBar() {
		var ac = activeSelected.size, ar = archivedSelected.size, total = ac + ar;
		if (total === 0) {
			bulkBar.classList.remove('qt-bulk-bar-visible');
			return;
		}
		bulkBar.classList.add('qt-bulk-bar-visible');
		bulkCount.textContent = total + ' selected';
		bulkArchive.style.display = ac > 0 ? '' : 'none';
		bulkRestore.style.display = ar > 0 ? '' : 'none';
	}

	function rebindCheckboxes(tableEl, selectedSet, cbClass) {
		if (!tableEl) return;
		tableEl.querySelectorAll('.' + cbClass).forEach(function(cb) {
			var id = parseInt(cb.dataset.id, 10);
			cb.checked = selectedSet.has(id);
			cb.onchange = function() {
				if (cb.checked) selectedSet.add(id); else selectedSet.delete(id);
				updateBulkBar();
			};
		});
	}

	function rebindSelectAll(headerId, tableEl, selectedSet, cbClass) {
		var sa = document.getElementById(headerId);
		if (!sa) return;
		sa.onchange = function() {
			var cbs = tableEl.querySelectorAll('.' + cbClass);
			cbs.forEach(function(cb) {
				var id = parseInt(cb.dataset.id, 10);
				cb.checked = sa.checked;
				if (sa.checked) selectedSet.add(id); else selectedSet.delete(id);
			});
			updateBulkBar();
		};
	}

	if (activeTable) {
		activeTable.on('draw.dt', function() {
			var t = document.getElementById('qt-active-table');
			rebindCheckboxes(t, activeSelected, 'qt-active-cb');
			rebindSelectAll('qt-active-select-all', t, activeSelected, 'qt-active-cb');
		});
		activeTable.draw();
	}
	// Archived table draw.dt binding is deferred — see toggle handler above

	bulkDeselect.addEventListener('click', function() {
		activeSelected.clear(); archivedSelected.clear();
		document.querySelectorAll('.qt-bulk-cb').forEach(function(cb) { cb.checked = false; });
		updateBulkBar();
	});

	function doBulkStatus(ids, status) {
		if (!ids.length) return;
		var label = status === 'archived' ? 'archive' : 'restore';
		qtConfirm({
			title: label.charAt(0).toUpperCase() + label.slice(1) + ' Questions',
			body: label.charAt(0).toUpperCase() + label.slice(1) + ' ' + ids.length + ' question(s)?',
			confirmLabel: label.charAt(0).toUpperCase() + label.slice(1),
			danger: status === 'archived',
			onConfirm: function() {
				var fd = new FormData();
				fd.append('KingdomId', '<?= (int)$KingdomId ?>');
				fd.append('QuestionIds', JSON.stringify(ids));
				fd.append('Status', status);
				fetch('<?= UIR ?>QualTestAjax/bulkstatus', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j.status === 0) window.location.reload();
						else qtAlert(j.error || 'Error updating status.');
					});
			}
		});
	}

	bulkArchive.addEventListener('click', function() { doBulkStatus([...activeSelected], 'archived'); });
	bulkRestore.addEventListener('click', function() { doBulkStatus([...archivedSelected], 'active'); });

	// ----- Single status toggle -----
	document.querySelectorAll('.qt-status-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var newStatus = btn.dataset.status;
			var label = newStatus === 'archived' ? 'archive' : 'restore';
			qtConfirm({
				title: label.charAt(0).toUpperCase() + label.slice(1) + ' Question',
				body: 'Are you sure you want to ' + label + ' this question?',
				confirmLabel: label.charAt(0).toUpperCase() + label.slice(1),
				danger: newStatus === 'archived',
				onConfirm: function() {
					var fd = new FormData();
					fd.append('KingdomId',  btn.dataset.kingdom);
					fd.append('QuestionId', btn.dataset.id);
					fd.append('Status',     newStatus);
					fetch('<?= UIR ?>QualTestAjax/setstatus', { method: 'POST', body: fd })
						.then(function(r) { return r.json(); })
						.then(function(j) {
							if (j.status === 0) { window.location.reload(); }
							else { qtAlert(j.error || 'Error updating status.'); }
						});
				}
			});
		});
	});

	// ----- Reset stats -----
	document.querySelectorAll('.qt-reset-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			qtConfirm({
				title: 'Reset Success Rate',
				body: 'Reset Success Rate for this Question?',
				confirmLabel: 'Reset',
				danger: true,
				onConfirm: function() {
					var row = btn.closest('tr');
					var fd = new FormData();
					fd.append('KingdomId',  btn.dataset.kingdom);
					fd.append('QuestionId', btn.dataset.id);
					fetch('<?= UIR ?>QualTestAjax/resetstats', { method: 'POST', body: fd })
						.then(function(r) { return r.json(); })
						.then(function(j) {
							if (j.status !== 0) { qtAlert(j.error || 'Error resetting stats.'); return; }
							var cells = row.querySelectorAll('td');
							if (cells[3]) cells[3].innerHTML = '<span class="qt-success-badge qt-success-none">\u2014</span>';
						});
				}
			});
		});
	});

	// ----- Duplicate -----
	document.querySelectorAll('.qt-dup-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			qtConfirm({
				title: 'Duplicate Question',
				body: 'Duplicate this question?',
				confirmLabel: 'Duplicate',
				onConfirm: function() {
					btn.disabled = true;
					var fd = new FormData();
					fd.append('KingdomId',  btn.dataset.kingdom);
					fd.append('QuestionId', btn.dataset.id);
					fetch('<?= UIR ?>QualTestAjax/duplicatequestion', { method: 'POST', body: fd })
						.then(function(r) { return r.json(); })
						.then(function(j) {
							if (j.status === 0) window.location = '<?= UIR ?>QualTest/question/edit/' + j.new_question_id;
							else { qtAlert(j.error || 'Error duplicating question.'); btn.disabled = false; }
						});
				}
			});
		});
	});
});

// ----- Test Preview -----
(function() {
	// Guard on the OVERLAY, not on a button: there is no longer a single preview button — there
	// are zero, one or two (Draft / Live), depending on which versions exist.
	var overlay       = document.getElementById('qt-preview-overlay');
	if (!overlay) return;
	var infoEl        = document.getElementById('qt-preview-info');
	var bodyEl        = document.getElementById('qt-preview-body');
	var drawBtn       = document.getElementById('qt-preview-draw');
	var closeBtn      = document.getElementById('qt-preview-close');
	var closeFooter   = document.getElementById('qt-preview-close-footer');

	function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	var letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	// Which version the open preview is showing. "Draw Again" must re-draw from the SAME one,
	// not silently hop to the other.
	var previewSet = 0;

	function fetchPreview() {
		bodyEl.innerHTML = '<div style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading preview&hellip;</div>';
		drawBtn.style.display = 'none';
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fd.append('TestType',  '<?= $TestType ?>');
		if (previewSet) fd.append('SetId', previewSet);
		fetch('<?= UIR ?>QualTestAjax/previewtest', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.status !== 0) { bodyEl.innerHTML = '<div style="color:#e53e3e;padding:16px;">' + escH(data.error || 'Error') + '</div>'; return; }

				// WHICH version this is a preview of. Never leave it ambiguous: while a draft is
				// open this shows the draft (what you are about to publish), not the running test.
				var isDraft = (data.set_status === 'draft');
				var chip = '<span class="qt-preview-setchip ' + (isDraft ? 'qt-preview-setchip-draft' : 'qt-preview-setchip-live') + '">'
					+ (isDraft ? 'Draft' : 'Live') + '</span>';
				var ver = (data.rules_version || '').trim();
				infoEl.innerHTML = chip
					+ '<strong>' + escH(data.set_name || '') + '</strong>'
					+ (ver ? ' <span class="qt-preview-setmeta">' + escH(ver) + '</span>' : '')
					+ ' <span class="qt-preview-setmeta">' + data.question_count
					+ ' questions drawn \u2014 requires ' + data.pass_percent + '% to pass</span>'
					+ (isDraft
						? '<div class="qt-preview-setnote">This is the version you are building. Players are still being asked the current live test until you publish.</div>'
						: '');
				var html = '';
				data.questions.forEach(function(q, qi) {
					html += '<div class="qt-preview-q"><div class="qt-preview-q-text">' + (qi+1) + '. ' + escH(q.QuestionText) + '</div>';
					q.Answers.forEach(function(a, ai) {
						var cls = a.IsCorrect ? ' qt-preview-correct' : '';
						html += '<div class="qt-preview-answer' + cls + '">' + letters[ai] + ') ' + escH(a.AnswerText);
						if (a.IsCorrect) html += ' <i class="fas fa-check"></i>';
						html += '</div>';
					});
					html += '</div>';
				});
				bodyEl.innerHTML = html;
				drawBtn.style.display = '';
			});
	}

	document.querySelectorAll('.qt-preview-open').forEach(function(b) {
		b.addEventListener('click', function() {
			previewSet = parseInt(b.dataset.set || '0', 10);
			overlay.classList.add('qt-open');
			fetchPreview();
		});
	});
	drawBtn.addEventListener('click', fetchPreview);   // re-draws from previewSet
	function closePreview() { overlay.classList.remove('qt-open'); }
	closeBtn.addEventListener('click', closePreview);
	closeFooter.addEventListener('click', closePreview);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closePreview(); });
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay.classList.contains('qt-open')) closePreview();
	});
})();

// ----- Bulk Import -----
(function() {
	var openBtn   = document.getElementById('qt-bulkimport-btn');
	if (!openBtn) return;
	var overlay   = document.getElementById('qt-bulkimport-overlay');
	var closeBtn  = document.getElementById('qt-bulkimport-close');
	var cancelBtn = document.getElementById('qt-bulkimport-cancel');
	var parseBtn  = document.getElementById('qt-bulkimport-parse');
	var submitBtn = document.getElementById('qt-bulkimport-submit');
	var moreBtn   = document.getElementById('qt-bulkimport-more');
	var textarea  = document.getElementById('qt-bulkimport-text');
	var previewEl = document.getElementById('qt-bulkimport-preview');
	var parsedQuestions = [];
	var importedCount = 0;

	// HTML-escape helper — the Test Preview IIFE has its own copy scoped to
	// that closure, so we need our own here. Without it, any parsed input
	// throws ReferenceError inside the click handler and the preview stays
	// silently blank.
	function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	// Strip the numbering a pasted quiz brings with it — "1.", "12)", "Q3.", "A)".
	// Real banks are written in a document first, so most GMRs paste something already
	// numbered; without this the numbering became part of the question text ("1. What
	// colour...") and had to be hand-edited out of every single question afterwards.
	//
	// A bare letter followed by a PERIOD is deliberately NOT treated as numbering: that
	// would eat the start of a sentence like "E. coli is a bacterium?". A letter only
	// counts as an enumerator when it uses ")", which nothing else does. Digits are safe
	// with either, because the trailing space rules out decimals ("3.14" is untouched).
	function stripEnumerator(s) {
		return s.replace(/^(?:Q?\d+)[.)]\s+/i, '').replace(/^[A-Za-z]\)\s*/, '');
	}

	function parseQuestions(raw) {
		var blocks = raw.split(/\n\s*\n/).map(function(b) { return b.trim(); }).filter(Boolean);
		var questions = [], errors = [];
		blocks.forEach(function(block, bi) {
			var lines = block.split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
			// Optional leading mode directive ([multi] / [single]). This is what lets a
			// multi-select question with a SINGLE correct answer round-trip: the star
			// count alone would read one star as single. Absent, mode is still inferred
			// from the number of starred answers below.
			var forcedMode = null;
			if (lines.length && /^\[(multi|single)\]$/i.test(lines[0])) {
				forcedMode = /multi/i.test(lines[0]) ? 'multi' : 'single';
				lines = lines.slice(1);
			}
			if (lines.length < 2) { errors.push('Block ' + (bi+1) + ': needs question + at least 2 answers.'); return; }
			var qText = stripEnumerator(lines[0].replace(/^\*/, '')); // leading * then any numbering
			var answers = [], correctCount = 0;
			for (var i = 1; i < lines.length; i++) {
				var line = lines[i];
				var isCorrect = line.charAt(0) === '*';
				if (isCorrect) { line = line.substring(1).trim(); correctCount++; }
				// Same rule as the question line. This previously stripped "A)" but not "1)",
				// so a numbered answer list imported as "1) Blue", "2) Green".
				line = stripEnumerator(line);
				if (line) answers.push({ AnswerText: line, IsCorrect: isCorrect ? 1 : 0 });
			}
			if (answers.length < 2) { errors.push('Block ' + (bi+1) + ': at least 2 answers required.'); return; }
			if (correctCount < 1)   { errors.push('Block ' + (bi+1) + ': at least 1 correct answer required.'); return; }
			// An explicit [multi]/[single] directive wins; otherwise multi-correct is
			// auto-detected (2+ starred answers → "select all that apply"). Admins can
			// still flip the toggle in the editor.
			var mode = forcedMode || (correctCount > 1 ? 'multi' : 'single');
			questions.push({ QuestionText: qText, AnswerMode: mode, Answers: answers });
		});
		return { questions: questions, errors: errors };
	}

	parseBtn.addEventListener('click', function() {
		var result = parseQuestions(textarea.value);
		parsedQuestions = result.questions;
		var html = '';
		result.errors.forEach(function(e) { html += '<div class="qt-bulk-import-error">' + escH(e) + '</div>'; });
		if (parsedQuestions.length > 0) {
			if (parsedQuestions.length > 200) {
				html += '<div class="qt-bulk-import-error">Maximum 200 questions per batch. You have ' + parsedQuestions.length + '.</div>';
				parsedQuestions = parsedQuestions.slice(0, 200);
			}
			html += '<div class="qt-bulk-import-success">' + parsedQuestions.length + ' question(s) ready to import</div>';
			parsedQuestions.forEach(function(q, qi) {
				var badge = q.AnswerMode === 'multi'
					? ' <span class="qt-multi-badge">select all that apply</span>'
					: '';
				html += '<div class="qt-bulk-import-preview-q"><div class="qt-bulk-import-preview-q-text">' + (qi+1) + '. ' + escH(q.QuestionText) + badge + '</div>';
				q.Answers.forEach(function(a) {
					html += '<div class="qt-bulk-import-preview-a' + (a.IsCorrect ? ' qt-correct' : '') + '">';
					html += (a.IsCorrect ? '<i class="fas fa-check" style="margin-right:4px;color:#276749;"></i>' : '&bull; ') + escH(a.AnswerText) + '</div>';
				});
				html += '</div>';
			});
			submitBtn.disabled = false;
			submitBtn.textContent = 'Import ' + parsedQuestions.length + ' Questions';
		} else {
			submitBtn.disabled = true;
			submitBtn.textContent = 'Import Questions';
		}
		previewEl.innerHTML = html;
	});

	submitBtn.addEventListener('click', function() {
		if (!parsedQuestions.length) return;
		submitBtn.disabled = true;
		submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fd.append('TestType',  '<?= $TestType ?>');
		fd.append('Questions', JSON.stringify(parsedQuestions));
		fd.append('SetId', QT_TARGET_SET);   // imported questions join the draft, not the live test
		fetch('<?= UIR ?>QualTestAjax/bulkimport', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				var html = '';
				if (j.status !== 0) {
					html = '<div class="qt-bulk-import-error">' + escH(j.error || 'Import failed.') + '</div>';
				} else {
					importedCount += j.imported;
					html = '<div class="qt-bulk-import-success">' + j.imported + ' question(s) imported successfully!</div>';
					if (j.errors && j.errors.length) {
						j.errors.forEach(function(e) {
							html += '<div class="qt-bulk-import-error">Question ' + escH(String(e.index + 1)) + ': ' + escH(e.error) + '</div>';
						});
					}
				}
				previewEl.innerHTML = html;

				if (j.status === 0) {
					// Closed mid-flight (Escape / backdrop / X while the request was still running):
					// closeModal() saw importedCount === 0 and skipped its reload, so the page behind
					// is now stale — it still says "0 active questions" while the import succeeded.
					// Reload from here instead.
					if (j.imported > 0 && !overlay.classList.contains('qt-open')) {
						window.location.reload();
						return;
					}
					// The batch is CONSUMED. Clearing it is the point: the textarea still holds the
					// questions you just imported, so re-parsing and importing again would duplicate
					// every one of them. The import button used to be relabelled "Done" and left
					// disabled here — a dead control that did nothing, one click away from that trap.
					parsedQuestions = [];
					submitBtn.style.display = 'none';
					moreBtn.style.display   = '';
					// Keep Parse & Preview ONLY if something failed, so a partial batch can be
					// corrected in place and retried. On a clean import there is nothing to re-parse.
					parseBtn.style.display  = (j.errors && j.errors.length) ? '' : 'none';
					// Closing now reloads the page, so make that the obvious next step.
					cancelBtn.className = 'qt-preview-btn qt-preview-btn-draw';
					cancelBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Close &amp; Reload';
				} else {
					submitBtn.disabled  = false;   // failed outright — let them try again
					submitBtn.innerHTML = '<i class="fas fa-file-import"></i> Import Questions';
				}
			});
	});

	// Paste a second batch: clear the box and put the buttons back to their starting state.
	moreBtn.addEventListener('click', function() {
		resetModal();
		submitBtn.style.display = '';
		parseBtn.style.display  = '';
		moreBtn.style.display   = 'none';
		textarea.focus();
	});

	function resetModal() {
		textarea.value = '';
		previewEl.innerHTML = '';
		parsedQuestions = [];
		submitBtn.disabled = true;
		submitBtn.textContent = 'Import Questions';
	}

	openBtn.addEventListener('click', function() {
		resetModal();
		importedCount = 0;
		// Full reset: the modal is reused, so a previous import's finished-state buttons would
		// otherwise still be showing the next time it is opened.
		submitBtn.style.display = '';
		parseBtn.style.display  = '';
		moreBtn.style.display   = 'none';
		cancelBtn.className = 'qt-preview-btn qt-preview-btn-secondary';
		cancelBtn.innerHTML = 'Close';
		overlay.classList.add('qt-open');
	});

	function closeModal() {
		overlay.classList.remove('qt-open');
		if (importedCount > 0) window.location.reload();
	}
	closeBtn.addEventListener('click', closeModal);
	cancelBtn.addEventListener('click', closeModal);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay.classList.contains('qt-open')) closeModal();
	});
})();

// ----- Global Question Library -----
(function() {
	var libBtn     = document.getElementById('qt-library-btn');
	if (!libBtn) return; // not opted in

	var overlay      = document.getElementById('qt-library-overlay');
	var closeBtn     = document.getElementById('qt-library-close');
	var loadingEl    = document.getElementById('qt-library-loading');
	var emptyEl      = document.getElementById('qt-library-empty');
	var bodyEl       = document.getElementById('qt-library-body');
	var listEl       = document.getElementById('qt-library-list');
	var searchEl     = document.getElementById('qt-library-search');
	var searchWrapEl = document.getElementById('qt-library-search-wrap');
	var loaded     = false;
	var allQuestions = [];
	var addedCount = 0;
	// The rules/corpora version of the set THIS kingdom is building — the yardstick every
	// library row is measured against.
	var myVersion  = '';

	// escH is defined inside the preview + bulk-import IIFEs, which are separate
	// scopes — it was NOT visible here, so renderList() threw "escH is not defined"
	// and (being inside a .then()) failed silently: the search box appeared but the
	// list never rendered. Define it locally.
	function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	function renderList(questions) {
		if (!questions.length) { listEl.innerHTML = '<div style="text-align:center;padding:16px;color:#718096;">No matches.</div>'; return; }
		listEl.innerHTML = questions.map(function(q) {
			// The shared library never exposes which answer is correct — list texts only.
			var answers = q.Answers.map(function(a) {
				return '<div class="qt-lib-answer">&bull; ' + escH(a.AnswerText) + '</div>';
			}).join('');
			// Flag the "offending" questions (2+ player reports) so admins can steer
			// clear; these are also sorted to the bottom of the list server-side.
			var flag = (q.ReportCount >= 2)
				? '<span class="qt-lib-flag" data-tip="' + q.ReportCount + ' player reports"><i class="fas fa-flag"></i> ' + q.ReportCount + '</span>'
				: '';

			// Which edition of the rules this Kingdom's LIVE test is built on. Everyone plays the
			// same rulebook, but Kingdoms rewrite their tests at different speeds — so a question
			// can be current for THEM and still predate the ruleset you are writing against. Mark
			// it when it differs from the version you are building; that is the whole point of
			// showing this. Free text, so an exact match is all we can honestly claim.
			var ver = (q.RulesVersion || '').trim();
			var verChip;
			if (!ver) {
				verChip = '<span class="qt-lib-ver qt-lib-ver-none" data-tip="This kingdom published before a version label was required">no version</span>';
			} else if (myVersion && ver !== myVersion) {
				verChip = '<span class="qt-lib-ver qt-lib-ver-diff" data-tip="Their test is built on ' + escH(ver)
					+ '; you are building ' + escH(myVersion) + '">' + escH(ver) + '</span>';
			} else {
				verChip = '<span class="qt-lib-ver">' + escH(ver) + '</span>';
			}

			return '<div class="qt-lib-question" data-qid="' + q.QualQuestionId + '">'
				+ '<div class="qt-lib-question-hdr">'
					+ '<div><div class="qt-lib-question-text">' + escH(q.QuestionText) + '</div>'
					+ '<div class="qt-lib-kingdom"><i class="fas fa-chess-rook" style="margin-right:3px;"></i>' + escH(q.KingdomName)
					+ verChip + flag + '</div></div>'
					+ '<button class="qt-lib-add-btn" data-qid="' + q.QualQuestionId + '"><i class="fas fa-plus"></i> Add</button>'
				+ '</div>'
				+ '<div class="qt-lib-answers">' + answers + '</div>'
			+ '</div>';
		}).join('');
		listEl.querySelectorAll('.qt-lib-add-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				btn.disabled = true;
				btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
				var fd = new FormData();
				fd.append('KingdomId',  '<?= (int)$KingdomId ?>');
				fd.append('QuestionId', btn.dataset.qid);
				fd.append('SetId', QT_TARGET_SET);
				fetch('<?= UIR ?>QualTestAjax/copyfromlibrary', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j.status !== 0) { qtAlert(j.error || 'Error adding question.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Add'; return; }
						btn.innerHTML = '<i class="fas fa-check"></i> Added';
						btn.style.background = '#276749';
						addedCount++;
						// Closed mid-flight: closeLibrary() saw addedCount === 0 and skipped its
						// reload, so the bank behind is stale and the question looks like it never
						// arrived. Same guard as the bulk-import path.
						if (!overlay.classList.contains('qt-open')) { window.location.reload(); }
					});
			});
		});
	}

	libBtn.addEventListener('click', function() {
		overlay.classList.add('qt-open');
		if (loaded) return;
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fetch('<?= UIR ?>QualTestAjax/getlibrary', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				loaded = true;
				loadingEl.style.display = 'none';
				if (j.status !== 0) { emptyEl.textContent = j.error || 'Error loading library.'; emptyEl.style.display = 'block'; return; }
				allQuestions = j.questions || [];
				if (!allQuestions.length) {
					// An empty library has two quite different meanings, and saying "none shared
					// yet" when a kingdom has in fact imported every shared question reads as if
					// the feature is broken. The server tells us which case this is.
					var st = j.stats || {};
					if (st.Shared > 0 && st.AlreadyHave >= st.Shared) {
						emptyEl.innerHTML = '<div style="font-weight:600;color:#22543d;">'
							+ '<i class="fas fa-check-circle"></i> You already have every shared question.</div>'
							+ '<div style="margin-top:6px;">All ' + st.Shared + ' question'
							+ (st.Shared === 1 ? '' : 's') + ' other kingdoms are sharing '
							+ (st.Shared === 1 ? 'is' : 'are') + ' already in your bank. '
							+ 'Check back when another kingdom publishes new ones.</div>';
					} else {
						emptyEl.textContent = 'No questions available from other kingdoms yet. '
							+ 'Questions appear here once another kingdom opts in to sharing and publishes them.';
					}
					emptyEl.style.display = 'block';
					return;
				}
				searchWrapEl.style.display = 'block';
				bodyEl.style.display = 'block';
				// Questions already in your bank are filtered out (matched on text). Without a word
				// of explanation the list looks arbitrarily short next to what a kingdom advertises.
				var st2 = j.stats || {};
				if (st2.AlreadyHave > 0 && listEl.parentNode) {
					var note = document.createElement('div');
					note.className = 'qt-lib-note';
					note.style.cssText = 'font-size:0.8rem;padding:0 0 8px;';
					note.innerHTML = '<i class="fas fa-info-circle"></i> ' + st2.AlreadyHave
						+ ' shared question' + (st2.AlreadyHave === 1 ? '' : 's')
						+ ' already in your bank ' + (st2.AlreadyHave === 1 ? 'is' : 'are') + ' hidden.';
					listEl.parentNode.insertBefore(note, listEl);
				}
				myVersion = (j.my_version || '').trim();
				renderList(allQuestions);
				searchEl.focus();
			});
	});

	searchEl.addEventListener('input', function() {
		var q = searchEl.value.trim().toLowerCase();
		// Version is searchable too: "show me everything built on 8.7" is the obvious question
		// once the label is on screen.
		var filtered = !q ? allQuestions : allQuestions.filter(function(item) {
			return item.QuestionText.toLowerCase().indexOf(q) !== -1
				|| item.KingdomName.toLowerCase().indexOf(q) !== -1
				|| (item.RulesVersion || '').toLowerCase().indexOf(q) !== -1;
		});
		renderList(filtered);
	});

	function closeLibrary() {
		overlay.classList.remove('qt-open');
		if (addedCount > 0) { window.location.reload(); }
	}
	closeBtn.addEventListener('click', closeLibrary);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closeLibrary(); });
	// Escape closes it too — every other modal on this page does, and the search box swallows
	// the key otherwise, so there was no way out but the mouse.
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay.classList.contains('qt-open')) closeLibrary();
	});
})();

// ----- Report flag popup -----
(function() {
	var overlay    = document.getElementById('qt-report-overlay');
	var loadingEl  = document.getElementById('qt-report-loading');
	var bodyEl     = document.getElementById('qt-report-body');
	var closeBtn   = document.getElementById('qt-report-close');
	var archiveBtn = document.getElementById('qt-report-archive');
	var editLink   = document.getElementById('qt-report-edit');
	var clearBtn   = document.getElementById('qt-report-clear');
	var currentQid = 0;
	var currentKid = 0;

	function repEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	// Show WHO reported (persona linked to their profile so the writer can reach
	// out), with each report's reason and date. Reporter identity is only exposed
	// here in the admin-only reports modal.
	function renderReporters(reporters) {
		var el = document.getElementById('qt-report-reporters');
		if (!el) return;
		if (!reporters.length) { el.innerHTML = ''; return; }
		var reasonLabels = { wording:'Worded poorly', correct:'Answer was correct', outdated:'Outdated', other:'Other' };
		var rows = reporters.map(function(rp) {
			var name = rp.Persona ? repEsc(rp.Persona) : 'Unknown player';
			var nameHtml = rp.PlayerId
				? '<a href="<?= UIR ?>Player/profile/' + rp.PlayerId + '" target="_blank" rel="noopener">' + name + '</a>'
				: name;
			var when = '';
			if (rp.CreatedAt) {
				var d = new Date(String(rp.CreatedAt).replace(' ', 'T'));
				when = isNaN(d.getTime()) ? repEsc(rp.CreatedAt)
					: d.toLocaleDateString([], { year:'numeric', month:'short', day:'numeric' });
			}
			var meta = repEsc(reasonLabels[rp.Reason] || rp.Reason) + (when ? ' · ' + when : '');
			return '<div class="qt-report-reporter-row"><span class="qt-report-reporter-name">' + nameHtml
				+ '</span><span class="qt-report-reporter-meta">' + meta + '</span></div>';
		}).join('');
		el.innerHTML = '<div class="qt-report-reporters-hdr">Who reported (' + reporters.length + ')</div>' + rows;
	}

	function openReportPopup(qid, kid) {
		currentQid = qid;
		currentKid = kid;
		loadingEl.style.display = 'block';
		bodyEl.style.display    = 'none';
		archiveBtn.style.display = 'inline-block';
		archiveBtn.dataset.id     = qid;
		archiveBtn.dataset.kingdom = kid;
		editLink.style.display   = 'inline-block';
		clearBtn.style.display   = 'inline-block';
		editLink.href = '<?= UIR ?>QualTest/question/edit/' + qid;
		overlay.classList.add('qt-open');

		var fd = new FormData();
		fd.append('KingdomId',  kid);
		fd.append('QuestionId', qid);
		fetch('<?= UIR ?>QualTestAjax/getreports', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				loadingEl.style.display = 'none';
				if (j.status !== 0) { bodyEl.innerHTML = '<span style="color:#e53e3e">Error loading reports.</span>'; bodyEl.style.display = 'block'; return; }
				document.getElementById('qr-wording').textContent  = j.counts.wording;
				document.getElementById('qr-correct').textContent  = j.counts.correct;
				document.getElementById('qr-outdated').textContent = j.counts.outdated;
				document.getElementById('qr-other').textContent    = j.counts.other;
				renderReporters(j.reporters || []);
				bodyEl.style.display = 'block';
			});
	}

	document.querySelectorAll('.qt-report-flag-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			openReportPopup(parseInt(btn.dataset.id, 10), parseInt(btn.dataset.kingdom, 10));
		});
	});

	closeBtn.addEventListener('click', function() { overlay.classList.remove('qt-open'); });
	overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.classList.remove('qt-open'); });
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay.classList.contains('qt-open')) overlay.classList.remove('qt-open');
	});

	clearBtn.addEventListener('click', function() {
		qtConfirm({
			title: 'Clear Flags',
			body: 'Clear all flags for this question?',
			confirmLabel: 'Clear Flags',
			danger: true,
			onConfirm: function() {
				clearBtn.disabled = true;
				var fd = new FormData();
				fd.append('KingdomId',  currentKid);
				fd.append('QuestionId', currentQid);
				fetch('<?= UIR ?>QualTestAjax/clearreports', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j.status !== 0) { qtAlert(j.error || 'Error clearing flags.'); clearBtn.disabled = false; return; }
						window.location.reload();
					});
			}
		});
	});

	archiveBtn.addEventListener('click', function() {
		qtConfirm({
			title: 'Archive Question',
			body: 'Archive this question and clear its reports?',
			confirmLabel: 'Archive',
			danger: true,
			onConfirm: function() {
				var fd = new FormData();
				fd.append('KingdomId',  currentKid);
				fd.append('QuestionId', currentQid);
				fd.append('Status', 'archived');
				fetch('<?= UIR ?>QualTestAjax/setstatus', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
						.then(function(j) {
							if (j.status !== 0) { qtAlert(j.error || 'Error archiving.'); return; }
							var fd2 = new FormData();
							fd2.append('KingdomId',  currentKid);
							fd2.append('QuestionId', currentQid);
							fetch('<?= UIR ?>QualTestAjax/clearreports', { method: 'POST', body: fd2 })
								.finally(function() { window.location.reload(); });
						});
				}
			});
	});
})();

// ── Question-set versioning ────────────────────────────────────────────────
// New questions (add / bulk import / library) go into whichever set the admin is
// working in: the draft when one exists, otherwise the live set.
var QT_TARGET_SET = <?= (int)($TargetSetId ?? 0) ?>;

(function() {
	var KID  = '<?= (int)$KingdomId ?>';
	var TYPE = '<?= htmlspecialchars($TestType, ENT_QUOTES) ?>';
	var BASE = '<?= UIR ?>QualTestAjax/';

	function post(endpoint, params, cb) {
		var fd = new FormData();
		Object.keys(params).forEach(function(k) { fd.append(k, params[k]); });
		fetch(BASE + endpoint, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(cb)
			.catch(function() { cb({ status: 1, error: 'Network error.' }); });
	}

	// Start the next version: clones the live set's membership, so carried-over
	// questions are NOT duplicated.
	var newBtn = document.getElementById('qt-newdraft-btn');
	if (newBtn) newBtn.addEventListener('click', function() {
		qtConfirm({
			title: 'Start the next version',
			body: 'This copies the current live questions into a new draft. The live test keeps running unchanged until you publish.',
			confirmLabel: 'Create draft',
			danger: false,
			onConfirm: function() {
				// No name: the model numbers it ("Version 3"). It used to hardcode "Next version",
				// which stopped being true the moment it was published — leaving the LIVE test
				// called "Next version" forever. Rename it with the pencil once it exists.
				post('createdraft', { KingdomId: KID, TestType: TYPE, Name: '', RulesVersion: '' }, function(j) {
					if (j.status !== 0) { qtAlert(j.error || 'Could not create the draft.'); return; }
					window.location.reload();
				});
			}
		});
	});

	// Version label on the draft (required before publishing).
	var verInput = document.getElementById('qt-draft-version');

	// publishSet() refuses a draft with no version label or too few questions. The button is
	// rendered disabled in that state; keep it in step as the GMR types, so the version they
	// just entered enables Publish immediately instead of after a reload. The question count
	// only changes on reload, so it is read from the server-rendered data-* attributes.
	function qtSyncPublishBtn() {
		var b = document.getElementById('qt-publish-btn');
		if (!b) return;
		var need    = parseInt(b.dataset.need || '0', 10);
		var have    = parseInt(b.dataset.have || '0', 10);
		var missing = [];
		if (!verInput || verInput.value.trim() === '') { missing.push('a rules/corpora version'); }
		if (need > 0 && have < need) { missing.push((need - have) + ' more question' + ((need - have) === 1 ? '' : 's')); }
		b.disabled = missing.length > 0;
		b.title    = missing.length ? 'Needs ' + missing.join(' and ') + ' before publishing.' : 'Make this the live test';
	}

	if (verInput) {
		var _t;
		verInput.addEventListener('input', function() {
			qtSyncPublishBtn();          // reflect the requirement the moment it is met
			clearTimeout(_t);
			_t = setTimeout(function() {
				post('updateset', { SetId: verInput.dataset.set, RulesVersion: verInput.value }, function() {});
			}, 500);
		});
	}
	qtSyncPublishBtn();

	// ── Edit a version's name, or its rules/corpora label, in place ──────────
	// Both outlive the reign that made them: they are stamped onto every attempt and shown in
	// players' test history for good. Swap the text for an input; Enter or blur saves, Escape
	// reverts. Nothing here can rewrite history — an attempt keeps whatever it was STAMPED
	// with, so editing a live version moves only the test footer and future attempts.
	//
	// One editor, two fields: `field` is the POST key ('Name' or 'RulesVersion'). The endpoint
	// falls back to the CURRENT value for whichever field is not sent, so sending one alone
	// cannot blank the other.
	function qtWireInlineEdit(btnSelector, labelSelector, field, opts) {
		opts = opts || {};
		document.querySelectorAll(btnSelector).forEach(function(btn) {
			btn.addEventListener('click', function() {
				var label = document.querySelector(labelSelector + '[data-set="' + btn.dataset.set + '"]');
				if (!label || label.dataset.editing === '1') return;
				// A placeholder ("no version label") is prompt text, not a value to edit.
				var isPlaceholder = label.classList.contains('qt-ver-nolabel');
				var original = isPlaceholder ? '' : label.textContent.trim();
				label.dataset.editing = '1';

				var inp = document.createElement('input');
				inp.type        = 'text';
				inp.className   = 'qt-ver-input';
				inp.value       = original;
				inp.placeholder = opts.placeholder || '';
				inp.style.minWidth = '180px';
				label.style.display = 'none';
				btn.style.display   = 'none';
				label.parentNode.insertBefore(inp, label.nextSibling);
				inp.focus();
				inp.select();

				var done = false;
				function finish(save) {
					if (done) return;          // blur fires again when we remove the input
					done = true;
					var val = inp.value.trim();
					inp.remove();
					label.style.display = '';
					btn.style.display   = '';
					delete label.dataset.editing;
					if (!save || val === original) return;
					if (val === '' && !opts.allowEmpty) return;   // a name cannot be blanked

					var body = { SetId: btn.dataset.set };
					body[field] = val;
					label.textContent = val;                       // optimistic
					label.classList.remove('qt-ver-nolabel');
					post('updateset', body, function(j) {
						if (!j || j.status !== 0) {
							label.textContent = isPlaceholder ? (opts.placeholder || '') : original;
							if (isPlaceholder) label.classList.add('qt-ver-nolabel');
							qtAlert((j && j.error) || 'Could not save that change.');
						}
					});
				}
				inp.addEventListener('keydown', function(e) {
					if (e.key === 'Enter')  { e.preventDefault(); finish(true); }
					if (e.key === 'Escape') { e.preventDefault(); finish(false); }
				});
				inp.addEventListener('blur', function() { finish(true); });
			});
		});
	}

	qtWireInlineEdit('.qt-ver-rename',    '.qt-ver-name',  'Name');
	// The live version's rules/corpora label. Publishing REQUIRES one, so before this a typo in
	// it could only be corrected by publishing an entirely new version.
	qtWireInlineEdit('.qt-ver-editlabel', '.qt-ver-label', 'RulesVersion',
		{ placeholder: 'no version label' });

	// ── Previous versions: read one back, read-only ──────────────────────────
	// Defined LOCALLY on purpose. An earlier bug in this file had a render function reach for
	// an escH() that lived in a different IIFE; it threw inside a .then() with no .catch() and
	// the list just silently came up empty. Keep the helper in the scope that uses it.
	function qtVerEsc(s) {
		return String(s === null || s === undefined ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	var verviewOverlay = document.getElementById('qt-verview-overlay');
	function qtVerviewClose() { if (verviewOverlay) verviewOverlay.classList.remove('qt-open'); }

	function qtVerviewOpen(setId) {
		if (!verviewOverlay) return;
		var titleEl = document.getElementById('qt-verview-title');
		var infoEl  = document.getElementById('qt-verview-info');
		var bodyEl  = document.getElementById('qt-verview-body');
		// Point the export link at the version being viewed.
		var expEl   = document.getElementById('qt-verview-export');
		if (expEl) expEl.href = '<?= UIR ?>QualTest/export/' + encodeURIComponent(setId);
		titleEl.textContent = 'Version';
		infoEl.textContent  = '';
		bodyEl.innerHTML    = '<div style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading&hellip;</div>';
		verviewOverlay.classList.add('qt-open');

		post('setquestions', { SetId: setId }, function(j) {
			if (!j || j.status !== 0) {
				bodyEl.innerHTML = '<div style="padding:24px;color:#c53030;">' +
					qtVerEsc((j && j.error) || 'Could not load this version.') + '</div>';
				return;
			}
			var s  = j.set || {};
			var qs = j.questions || [];
			titleEl.textContent = s.Name || 'Version';

			var bits = [];
			if (s.RulesVersion) { bits.push(qtVerEsc(s.RulesVersion)); }
			else { bits.push('<em>no version label</em>'); }
			bits.push(qs.length + ' question' + (qs.length === 1 ? '' : 's'));
			// Questions are shared by reference between versions, so what is shown is the text as
			// it reads TODAY. Only a player's attempt keeps the wording they actually saw. Say so
			// rather than let this list be mistaken for a snapshot.
			infoEl.innerHTML = bits.join(' &middot; ') +
				'<div style="margin-top:6px;font-size:0.8rem;color:#718096;">' +
				'This version is retired and cannot be edited. Questions are shown as they read now &mdash; ' +
				'if one was edited since, the change shows here. What a player was actually asked is kept ' +
				'on their attempt.</div>';

			if (!qs.length) {
				bodyEl.innerHTML = '<div style="padding:24px;color:#718096;">This version has no questions.</div>';
				return;
			}
			var html = '';
			qs.forEach(function(q, i) {
				html += '<div class="qt-vq">';
				html += '<div class="qt-vq-text">' + (i + 1) + '. ' + qtVerEsc(q.QuestionText);
				if (q.Archived) { html += '<span class="qt-vq-archived">Archived</span>'; }
				html += '</div>';
				(q.Answers || []).forEach(function(a) {
					html += '<div class="qt-vq-ans' + (a.IsCorrect ? ' qt-vq-correct' : '') + '">' +
						qtVerEsc(a.AnswerText) + '</div>';
				});
				html += '</div>';
			});
			bodyEl.innerHTML = html;
		});
	}

	document.querySelectorAll('.qt-verview-btn').forEach(function(b) {
		b.addEventListener('click', function() { qtVerviewOpen(b.dataset.set); });
	});
	var vvClose  = document.getElementById('qt-verview-close');
	var vvCloseF = document.getElementById('qt-verview-close-footer');
	if (vvClose)  vvClose.addEventListener('click', qtVerviewClose);
	if (vvCloseF) vvCloseF.addEventListener('click', qtVerviewClose);
	if (verviewOverlay) {
		verviewOverlay.addEventListener('click', function(e) {
			if (e.target === verviewOverlay) qtVerviewClose();   // click the backdrop
		});
	}
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && verviewOverlay && verviewOverlay.classList.contains('qt-open')) {
			qtVerviewClose();
		}
	});

	// Publishing makes this the current version — but the kingdom must ALSO have the
	// test switched on for anyone to actually sit it. Don't let a GMR publish believing
	// players can now take it when the monarchy hasn't turned the test on.
	var QT_TEST_ENABLED = <?= !empty($TestEnabled) ? 'true' : 'false' ?>;
	// Publishing the FIRST version has no predecessor to retire, so don't promise one.
	var QT_FIRST_VERSION = <?= empty($PublishedSet) ? 'true' : 'false' ?>;

	var pubBtn = document.getElementById('qt-publish-btn');
	if (pubBtn) pubBtn.addEventListener('click', function() {
		qtConfirm({
			title: 'Publish this version',
			body: !QT_TEST_ENABLED
				? 'This becomes the current version — but the test is still switched OFF for this kingdom, so nobody can take it yet. The monarchy must turn it on from the Kingdom page: Admin (the cog at the top) → Configuration.'
				: QT_FIRST_VERSION
					? 'This makes the test live. Players can start taking it right away.'
					: 'This immediately becomes the live test. The current version is kept as a previous version. Players start being asked these questions right away.',
			confirmLabel: 'Publish',
			danger: false,
			onConfirm: function() {
				post('publishset', { SetId: pubBtn.dataset.set }, function(j) {
					if (j.status !== 0) { qtAlert(j.error || 'Could not publish.'); return; }
					window.location.reload();
				});
			}
		});
	});

	var disBtn = document.getElementById('qt-discard-btn');
	if (disBtn) disBtn.addEventListener('click', function() {
		qtConfirm({
			title: 'Discard this draft',
			body: 'The draft version is thrown away. The questions themselves are kept — any that were only in this draft simply become unused.',
			confirmLabel: 'Discard draft',
			danger: true,
			onConfirm: function() {
				post('discarddraft', { SetId: disBtn.dataset.set }, function(j) {
					if (j.status !== 0) { qtAlert(j.error || 'Could not discard.'); return; }
					window.location.reload();
				});
			}
		});
	});

	// Add/remove a question from the draft. Removing does NOT archive it — it stays
	// live in the published version until the draft is published.
	document.querySelectorAll('.qt-draft-toggle').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var want = btn.dataset.in === '1' ? 0 : 1;
			btn.disabled = true;
			post('setmembership', { SetId: btn.dataset.set, QuestionId: btn.dataset.id, In: want }, function(j) {
				btn.disabled = false;
				if (j.status !== 0) { qtAlert(j.error || 'Could not update the draft.'); return; }
				window.location.reload();
			});
		});
	});
})();
</script>