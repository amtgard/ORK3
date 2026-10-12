<?php
/**
 * Survey_index.tpl — manage list of surveys for a scope (spec §7 "Survey list").
 *
 * Vars from Controller_Survey::index(): $Surveys, $Scopes, $ScopeType, $ScopeId,
 * $ScopeName, $IsOrkAdmin; $SurveyCsrf from the controller constructor.
 */
if (!empty($Error)) {
	echo '<div class="rp-root"><div class="sv-notice sv-notice-error" style="margin:20px;">'
		. htmlspecialchars($Error) . '</div></div>';
	return;
}

$_status_labels = ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed', 'archived' => 'Archived'];
// Default list order: live surveys first, then drafts, closed, archived.
$_status_rank_map = ['open' => 0, 'draft' => 1, 'closed' => 2, 'archived' => 3];

$_total    = count($Surveys);
$_open     = 0;
$_drafts   = 0;
$_responses = 0;
foreach ($Surveys as $_s) {
	if ($_s['status'] === 'open') {
		$_open++;
	}
	if ($_s['status'] === 'draft') {
		$_drafts++;
	}
	// Responses to surveys this viewer manages only; an inherited (shared)
	// survey's responses belong to its owner (sharing spec §1).
	if (($_s['Access'] ?? 'manage') === 'manage') {
		$_responses += (int) $_s['ResponseCount'];
	}
}

$_scope_icon = 'fa-globe';
if ($ScopeType === 'kingdom') {
	$_scope_icon = 'fa-chess-rook';
} elseif ($ScopeType === 'park') {
	$_scope_icon = 'fa-tree';
}
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/survey.css?v=<?=filemtime(__DIR__.'/style/survey.css')?>">
<style>
/* Local additions for the survey list page. Prefixed sv- per module convention;
   the shared .rp-* shell (header/context/stats/sidebar/table) comes from
   reports.css, and the module vocabulary (.sv-scope tokens + heading/paragraph
   resets, .sv-md for the rendered guide) comes from survey.css. */
/* Survey-only contrast bump for the active status filter pill: the shared
   dark-mode #4f86c6 is 3.78:1 under white, #3a6ea8 keeps the hue at 5.28:1.
   Scoped to this page's pills so the shared .rp-filter-pill rule (used by
   seven other report pages) is left untouched. */
html[data-theme="dark"] .rp-filter-pill[data-sv-filter].active {
	background: #3a6ea8;
	border-color: #3a6ea8;
}
.sv-status-pill {
	display: inline-block; padding: 3px 10px; border-radius: 20px;
	font-size: 11px; font-weight: 700; white-space: nowrap;
}
.sv-status-pill-draft    { background: #edf2f7; color: #4a5568; }
.sv-status-pill-open     { background: #c6f6d5; color: #276749; }
/* Foregrounds are chosen for >= 4.5:1 on their own fill: the pill is 11px/700,
   which is not WCAG "large text", so the 3:1 allowance does not apply.
   (#b7791f on #fef3c7 was 3.27:1; #718096 on #e2e8f0 was 3.26:1.) */
.sv-status-pill-closed   { background: #fef3c7; color: #8a5a12; }
.sv-status-pill-archived { background: #e2e8f0; color: #4a5568; }
html[data-theme="dark"] .sv-status-pill-draft    { background: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .sv-status-pill-open     { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .sv-status-pill-closed   { background: #744210; color: #fbd38d; }
html[data-theme="dark"] .sv-status-pill-archived { background: #2d3748; color: #a0aec0; }

/* Both the <a> and the <button> variants land on the same box so the row reads
   as one control strip. 28px on a desktop (spec §7 Density); the touch block
   at the bottom restores the 44px tap-target floor. Colours come from the
   theme-aware --rp- and --ork- tokens; the only dark rule is the anchor
   re-assertion below, which beats default.theme's dark link blue. */
.sv-row-btn {
	display: inline-flex; align-items: center; justify-content: center; gap: 5px;
	padding: 4px 9px; min-height: 28px; box-sizing: border-box; border-radius: 5px;
	border: 1px solid var(--rp-border-mid); background: var(--ork-card-bg); color: var(--rp-text-body);
	font-size: var(--ork-font-size-sm); font-weight: 600; line-height: 1.2; cursor: pointer; white-space: nowrap; text-decoration: none;
}
/* :where() keeps this at (0,2,0), so the later .sv-row-btn-on state still wins under the pointer. */
.sv-row-btn:hover:where(:not([disabled]))  { background: var(--rp-bg-light); border-color: var(--rp-border-strong); color: var(--rp-text); }
.sv-row-btn[disabled], .sv-row-btn.sv-is-busy { opacity: 0.55; cursor: not-allowed; }
.sv-row-btn i      { font-size: 11px; }
.sv-row-actions    { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }

/* The status pills are real <button>s so the filter is keyboard-operable; the
   shared .rp-filter-pill rule assumes a <span>, so the UA button defaults
   (font, line-height, text-align) are normalised here. */
button.rp-filter-pill { font: inherit; font-size: 11px; font-weight: 600; line-height: 1.4; text-align: center; }

.sv-empty-state { padding: 32px 16px; text-align: center; color: var(--rp-text-muted); font-size: var(--ork-font-size-base); }
.sv-empty-state i { font-size: 24px; display: block; margin-bottom: 12px; opacity: 0.4; }

/* The list is a DataTable: header, row, toolbar, paging and dark-mode styling
   all come from reports.css's .rp-table-area table.dataTable rules. Only the
   table-scroll wrapper and the in-cell links are local.
   The full six-column table needs a 740px wrapper (dom 'sv-dt-scroll') before
   the title column is crushed. The wrapper only gets that from a 1121px
   viewport: from 901px up the 220px sidebar sits beside the table (1120px
   leaves ~757px, 1121px leaves ~766px), and at 641-900px, with the sidebar
   stacked below, the wrapper is at most ~792px. So at 1120px and below every
   row is a stacked card (see the last blocks) and no row action sits past a
   sideways scroll. On a coarse pointer the cards run to 1400px, so tablets
   in landscape (1180, 1194, 1366px) get the card's labelled 44px buttons
   instead of an icon strip they cannot hover for a tip. The floor is 740px,
   not 760px, so a 17px classic scrollbar at 1121px (wrapper ~750px) still
   fits. The wrapper keeps overflow-x as a safety net only. */
.sv-dt-scroll { clear: both; overflow-x: auto; -webkit-overflow-scrolling: touch; }
#theme_container .sv-survey-table { min-width: 740px; }
#theme_container .sv-survey-table tr[hidden] { display: none; }

/* A date never breaks inside itself ("Oct 1, / 2026"); the Opened / Closes
   cell breaks at the arrow instead. Both dates and the arrow share one outer
   span so the card layout's flex cell sees a single value, not three items
   spread across the row by justify-content: space-between. */
.sv-nowrap { white-space: nowrap; }

/* reports.css paints every tbody <a> in the accent colour; the title and the
   row buttons carry their own look, so re-assert it at a matching specificity. */
.rp-table-area table.dataTable tbody .sv-survey-title a { color: var(--rp-text); font-weight: 700; text-decoration: none; }
.rp-table-area table.dataTable tbody .sv-survey-title a:hover { color: var(--rp-accent); text-decoration: underline; }
.rp-table-area table.dataTable tbody a.sv-row-btn,
.rp-table-area table.dataTable tbody a.sv-row-btn:hover { color: var(--rp-text-body); text-decoration: none; }
.rp-table-area table.dataTable tbody a.sv-row-btn:hover { color: var(--rp-text); }
/* default.theme's `html[data-theme="dark"] #theme_container a` (1,1,2) outranks
   the class-only rules above, so in dark mode the <a> row buttons (Build,
   Results, Preview) turned link-blue beside the <button> ones. The ID
   qualifier outranks it; the colours are the same tokens the buttons use. */
html[data-theme="dark"] #theme_container a.sv-row-btn { color: var(--rp-text-body); }
html[data-theme="dark"] #theme_container a.sv-row-btn:hover { color: var(--rp-text); }
.sv-survey-meta { font-size: 11px; color: var(--ork-text-secondary); margin-top: 2px; white-space: nowrap; }
html[data-theme="dark"] .sv-survey-meta { color: var(--ork-text-muted); }
/* #theme_container-qualified for the same reason as a.sv-row-btn above: without
   it these lost to default.theme's dark link blue. */
html[data-theme="dark"] #theme_container .rp-table-area table.dataTable tbody .sv-survey-title a { color: #e2e8f0; }
html[data-theme="dark"] #theme_container .rp-table-area table.dataTable tbody .sv-survey-title a:hover { color: var(--rp-accent); }

/* ---- New Survey modal fields ----
   The modal shell itself (.sv-overlay / .sv-modal*) is shared with the
   builder's Attendance credit panel and lives in survey.css. */
.sv-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
.sv-field label { font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--sv-text-body); }

/* Named .sv-toast, not .sv-notice: survey.css owns .sv-notice as an in-flow
   alert block, and a single-class collision would be settled by load order. */
.sv-toast {
	position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
	background: #2d3748; color: #fff; padding: 9px 18px; border-radius: 20px;
	font-size: var(--ork-font-size-sm); font-weight: 600; z-index: var(--z-modal-top, 10200); opacity: 0; pointer-events: none;
	transition: opacity 0.2s;
}
.sv-toast.sv-toast-show { opacity: 1; }
html[data-theme="dark"] .sv-toast { background: #1a202c; border: 1px solid #4a5568; }

/* Header actions: the .rp-btn-ghost sizing every survey .rp-* page needs is
   declared once in survey.css, which this page loads above. */

/* The compact sizes above are the desktop scale. On a coarse pointer or at
   phone width, every control goes back to a 44px tap target: the header's
   New Survey button, DataTables' "Show N" select and search box, the row
   buttons (and the sidebar's "Read the guide"), the status filter pills,
   DataTables' paging buttons and the modal controls. reports.css only does
   the select, search box and header button under 600px, so a tablet got
   ~30px ones; survey.css holds the header button on every coarse pointer,
   and #sv-new-btn here covers a 601-640px fine-pointer window. The text
   fields also go to 16px so iOS does not zoom the page on focus. The
   DataTables selectors are class + #theme_container scoped to outrank
   reports.css's .rp-table-area rules. Up to three tables now share one
   DataTables_wrapper class (sv-table-{key}_wrapper has no single id to
   hook), so the wrapper is targeted by that shared class instead. */
@media (pointer: coarse), (max-width: 640px) {
	#sv-new-btn { min-height: 44px; box-sizing: border-box; }
	#theme_container .dataTables_wrapper .dataTables_length select,
	#theme_container .dataTables_wrapper .dataTables_filter input {
		min-height: 44px; box-sizing: border-box; font-size: 16px; padding: 8px 10px;
	}
	.sv-row-btn { min-height: 44px; padding: 8px 12px; }
	.sv-field .sv-input, .sv-field .sv-select { min-height: 44px; font-size: 16px; padding: 9px 10px; }
	.sv-field .sv-select { padding-right: 36px; }
	button.rp-filter-pill[data-sv-filter] {
		display: inline-flex; align-items: center; justify-content: center;
		min-height: 44px; min-width: 44px; box-sizing: border-box; padding: 6px 14px; border-radius: 22px;
	}
	#theme_container .dataTables_wrapper .dataTables_paginate .paginate_button {
		display: inline-flex; align-items: center; justify-content: center;
		min-height: 44px; min-width: 44px; box-sizing: border-box; padding: 6px 12px;
		margin: 2px;
	}
	#theme_container .dataTables_wrapper .dataTables_paginate .ellipsis {
		display: inline-flex; align-items: center; min-height: 44px; vertical-align: top;
	}
}

/* Up to 1120px (phones, portrait tablets, and the sidebar layout before the
   table fits; see the .sv-dt-scroll note above), and up to 1400px on a
   coarse pointer (landscape tablets), each row becomes a stacked card, so the
   actions sit in the card instead of past a sideways scroll. The markup, and
   DataTables' search, sort, paging and status filter, are unchanged; the
   explicit ARIA table roles on the markup keep it a table for screen readers
   while its parts are display:block. The header row becomes a strip of sort
   chips (DataTables' own <th> click handlers and arrows). Each data cell
   shows its column name from data-label. The buttons are laid out on a grid.
   Sizes here are the compact desktop scale; the touch block above and the
   sort-chip block below restore the 44px floor on touch and at phone width.
   The class + #theme_container selectors outrank reports.css's
   .rp-table-area table.dataTable rules, including their dark-mode variants.
   Colours are the theme-aware --rp-/--ork- tokens, so no dark override is
   needed. */
@media (max-width: 1120px), (max-width: 1400px) and (pointer: coarse) {
	#theme_container .sv-survey-table { min-width: 0; border-bottom: 0; }
	/* A card list, not a table: no table surface behind the sort strip or in the
	   gaps between cards (dark mode painted a lighter band there). ID + class
	   outrank the theme's dark table rules. */
	#theme_container .sv-survey-table, #theme_container .sv-survey-table thead, #theme_container .sv-survey-table thead tr,
	html[data-theme="dark"] #theme_container .sv-survey-table,
	html[data-theme="dark"] #theme_container .sv-survey-table thead,
	html[data-theme="dark"] #theme_container .sv-survey-table thead tr { background: transparent; }
	#theme_container .sv-survey-table, #theme_container .sv-survey-table thead, #theme_container .sv-survey-table tbody,
	#theme_container .sv-survey-table tbody tr, #theme_container .sv-survey-table tbody td {
		display: block; width: 100%; box-sizing: border-box;
	}
	#theme_container .sv-survey-table tr[hidden] { display: none; }

	#theme_container .sv-survey-table thead tr { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 0 0 10px; }
	#theme_container .sv-survey-table thead tr::before {
		content: "Sort by"; font-size: 11px; font-weight: 700; letter-spacing: 0.04em;
		text-transform: uppercase; color: var(--ork-text-secondary); margin-right: 2px;
	}
	#theme_container .sv-survey-table thead th {
		position: relative; display: inline-flex; align-items: center; box-sizing: border-box;
		min-height: 30px; padding: 4px 26px 4px 12px; border: 1px solid var(--rp-border-mid);
		border-radius: 15px; white-space: nowrap;
	}
	#theme_container .sv-survey-table thead th.sorting_disabled,
	#theme_container .sv-survey-table thead th:last-child { display: none; }

	#theme_container .sv-survey-table tbody tr {
		margin: 0 0 10px; border: 1px solid var(--rp-border-mid); border-radius: 8px;
		overflow: hidden; background: var(--rp-bg-table, #fff);
	}
	#theme_container .sv-survey-table tbody td {
		display: flex; justify-content: space-between; align-items: center; gap: 12px;
		padding: 5px 12px; border-bottom: 0; text-align: right;
	}
	#theme_container .sv-survey-table tbody td[data-label]::before {
		content: attr(data-label); flex: 0 0 auto; text-align: left;
		font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
		color: var(--ork-text-secondary);
	}
	#theme_container .sv-survey-table tbody td.sv-survey-title { display: block; text-align: left; padding-top: 12px; font-size: 14px; }
	#theme_container .sv-survey-table tbody td.sv-row-actions-cell { display: block; padding: 10px 12px 12px; }
	#theme_container .sv-survey-table tbody td.dataTables_empty { display: block; text-align: center; padding: 16px 12px; }

	.sv-row-actions {
		display: grid; grid-template-columns: repeat(auto-fit, minmax(96px, 1fr)); gap: 6px;
	}
	/* Horizontal padding only: the height comes from .sv-row-btn, which is
	   28px on a fine pointer and 44px in the touch block above. */
	.sv-row-actions .sv-row-btn { min-width: 0; padding-left: 8px; padding-right: 8px; }
}

/* Once .rp-body stacks (reports.css, <=900px) it aligns its items to
   flex-start, so the table area shrink-wraps its content. The fixed-width
   table used to hold it open; the card list does not, so stretch it across
   the column. */
@media (max-width: 900px) {
	.rp-body.sv-scope > .rp-table-area { align-self: stretch; }
}

/* Cards on touch (and every card at phone width, fine pointer or not): the
   sort chips go back to a 44px tap target. The row buttons already get theirs
   from the touch block above. */
@media (max-width: 640px), (max-width: 1400px) and (pointer: coarse) {
	#theme_container .sv-survey-table thead th { min-height: 44px; padding-top: 6px; padding-bottom: 6px; border-radius: 22px; }
}
/* The full table on a touch screen wider than 1400px: its sortable headers are
   tap targets too. */
@media (min-width: 1401px) and (pointer: coarse) {
	#theme_container .sv-survey-table thead th { height: 44px; box-sizing: border-box; }
}

/* The full table on a fine pointer: the six row buttons go icon-only (28px
   squares on one line) so the Actions column stays ~190px and the title,
   scope and dates keep their width. Each label moves to .sv-visually-hidden's
   visually-hidden box, so it stays the button's accessible name, and the
   script's data-tip tooltip names the action on hover and keyboard focus.
   A coarse pointer never gets here: it has cards to 1400px and, past that,
   the labelled 44px buttons (a tip cannot be hovered on touch). */
@media (min-width: 1121px) and (pointer: fine) {
	#theme_container .sv-survey-table .sv-row-actions { flex-wrap: nowrap; gap: 4px; }
	#theme_container .sv-survey-table .sv-row-btn { width: 28px; padding: 0; }
	#theme_container .sv-survey-table .sv-row-btn i { font-size: 12px; }
	#theme_container .sv-survey-table .sv-row-btn-label {
		position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
		overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
	}
}

/* ---- Org sections (Amtgard / Kingdom / Park) ----
   The h2 must reset orkui.css's global heading pill box (background, border,
   padding, radius, box-shadow) in both themes: html[data-theme="dark"]
   h1..h6 carries more type selectors than a plain class and wins the tie. */
.sv-list-section { margin: 0 0 22px; }
#theme_container .sv-list-section-title,
html[data-theme="dark"] #theme_container .sv-list-section-title {
	display: flex; align-items: center; gap: 8px; margin: 0 0 10px; padding: 0;
	background: none; border: 0; border-radius: 0; box-shadow: none;
	font-size: 14px; font-weight: 700; color: var(--rp-text-body, var(--ork-text));
}
.sv-list-section-count {
	font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 10px;
	/* The rp chip surface: #e2e8f0 on the white table area, #374151 on the dark
	   one (reports.css swaps it), so the pill reads in both themes. */
	background: var(--rp-bg-tertiary); color: var(--ork-text-secondary);
}
.sv-list-section-empty { margin: 0; padding: 12px 14px; font-size: 13px; color: var(--ork-text-secondary);
	border: 1px dashed var(--rp-border-mid); border-radius: 8px; }
/* Credits on: the survey accent (--sv-accent = --ork-blue-primary, which
   swaps per theme: 8.4:1 on the light button, 5.3:1 on the dark one) plus a
   check icon and ", on" in the accessible name, so the state never rests on
   colour alone. */
.sv-row-btn.sv-row-btn-on { color: var(--sv-accent); border-color: var(--sv-accent); }
/* Results not open yet (after-close sharing): muted, dashed, no hover lift —
   it explains itself on hover/focus through its tip and does nothing on click. */
.sv-row-btn.sv-row-btn-wait,
.sv-row-btn.sv-row-btn-wait:hover {
	color: var(--ork-text-muted); border: 1px dashed var(--rp-border-strong);
	background: transparent; cursor: default;
}
/* When held results open. The tip says it where labels are icon-only (wide,
   fine pointer); in the card layout tips never show, so a line under the
   buttons says it instead. The button's aria-describedby points here at
   every width, so screen readers hear it once. */
.sv-row-wait-note { display: none; }
@media (max-width: 1120px), (max-width: 1400px) and (pointer: coarse) {
	.sv-row-wait-note {
		display: flex; gap: 6px; align-items: baseline; margin: 8px 0 0;
		font-size: var(--ork-font-size-sm); line-height: 1.45; text-align: left;
		color: var(--ork-text-secondary);
	}
}
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- .qt-page: the sidebar here is filters + a prose card, so on a phone the
     table comes first (reports.css opt-in, see its 900px block). -->
<div class="rp-root qt-page">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-poll rp-header-icon"></i>
				<h1 class="rp-header-title">Surveys</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip-label">Scope:</span>
				<span class="rp-scope-chip"><i class="fas <?=$_scope_icon?>"></i> <?=htmlspecialchars($ScopeName)?></span>
			</div>
		</div>
		<div class="rp-header-actions">
			<button type="button" class="rp-btn-ghost" id="sv-new-btn"><i class="fas fa-plus"></i> New Survey</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Build a survey, share it with your players, and see the results roll in. Surveys are locked to their structure once opened, so drafts stay editable until you're ready.</span>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-poll"></i></div>
			<div class="rp-stat-number" data-sv-stat="total"><?=number_format($_total)?></div>
			<div class="rp-stat-label">Total Surveys</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-door-open"></i></div>
			<div class="rp-stat-number" data-sv-stat="open"><?=number_format($_open)?></div>
			<div class="rp-stat-label">Open</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-pencil-alt"></i></div>
			<div class="rp-stat-number" data-sv-stat="draft"><?=number_format($_drafts)?></div>
			<div class="rp-stat-label">Drafts</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-reply-all"></i></div>
			<div class="rp-stat-number"><?=number_format($_responses)?></div>
			<div class="rp-stat-label">Responses</div>
		</div>
	</div>

	<!-- Body -->
	<div class="rp-body sv-scope">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-filter"></i> Status</div>
				<div class="rp-filter-card-body">
					<div class="rp-filter-pills">
						<button type="button" class="rp-filter-pill active" data-sv-filter="all" aria-pressed="true">All</button>
						<button type="button" class="rp-filter-pill" data-sv-filter="draft" aria-pressed="false">Draft</button>
						<button type="button" class="rp-filter-pill" data-sv-filter="open" aria-pressed="false">Open</button>
						<button type="button" class="rp-filter-pill" data-sv-filter="closed" aria-pressed="false">Closed</button>
						<button type="button" class="rp-filter-pill" data-sv-filter="archived" aria-pressed="false">Archived</button>
					</div>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-question-circle"></i> About Surveys</div>
				<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;color:var(--rp-text-body);">
					<p style="margin:0 0 8px;">Create a survey, add questions in the builder, then open it to your audience. Once opened its questions and pages are locked — clone it if you need to make structural changes.</p>
					<p style="margin:0 0 10px;">Results update live as responses come in, with charts, a row-level export, and a consent-aware privacy model.</p>
					<button type="button" class="sv-row-btn" id="sv-help-btn" style="width:100%;justify-content:center;"><i class="fas fa-book"></i> Read the guide</button>
				</div>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Table -->
		<div class="rp-table-area">
			<!-- Expired-token notice (#43): colours and the link style come from
			     survey.css's .sv-notice-error / .sv-notice-link in both themes. -->
			<div class="sv-notice sv-notice-error" id="sv-csrf-notice" role="alert" hidden>
				Your security token expired. <a href="" class="sv-notice-link" id="sv-csrf-reload">Reload the page</a> and try again.
			</div>
<?php if ($ScopeType === null && $_total === 0): ?>
			<div class="sv-empty-state">
				<i class="fas fa-poll"></i>
				No surveys yet for this scope.<br>
				<button type="button" class="sv-row-btn" id="sv-empty-new-btn" style="margin-top:14px;"><i class="fas fa-plus"></i> Create your first survey</button>
			</div>
<?php else:
	$_sec_icon  = ['ork' => 'fa-globe', 'kingdom' => 'fa-crown', 'park' => 'fa-campground'];
	$_sec_empty = [
		'ork'     => 'No Amtgard-wide surveys right now.',
		'kingdom' => 'No kingdom surveys right now.',
		'park'    => 'No park surveys yet.',
	];
	foreach (['ork', 'kingdom', 'park'] as $_key):
		$_rows = $Buckets['Rows'][$_key] ?? [];
		if ($ScopeType === null && !$_rows) { continue; }   // unscoped: only sections with rows
?>
			<section class="sv-list-section" data-sv-section="<?=$_key?>" aria-labelledby="sv-sec-<?=$_key?>">
				<h2 class="sv-list-section-title" id="sv-sec-<?=$_key?>">
					<i class="fas <?=$_sec_icon[$_key]?>" aria-hidden="true"></i>
					<?=htmlspecialchars($Buckets['Labels'][$_key])?>
					<span class="sv-list-section-count"><?=count($_rows)?></span>
				</h2>
<?php if (!$_rows): ?>
				<p class="sv-list-section-empty"><?=$_sec_empty[$_key]?></p>
<?php else: ?>
				<!-- Explicit table roles: up to 1120px (1400px on touch) every row is a
				     display:block card, and some browsers (WebKit) drop a table's
				     semantics once its parts stop being display:table-*. The roles keep
				     it a table with headers for screen readers at every width. -->
				<table class="sv-survey-table dataTable" id="sv-table-<?=$_key?>" role="table" style="width:100%">
					<thead role="rowgroup">
						<tr role="row">
							<th role="columnheader">Title</th>
							<th role="columnheader">Scope</th>
							<th role="columnheader">Status</th>
							<th role="columnheader" class="dt-right">Responses</th>
							<th role="columnheader">Opened / Closes</th>
							<th role="columnheader"><span class="sv-visually-hidden">Actions</span></th>
						</tr>
					</thead>
					<tbody role="rowgroup">
<?php foreach ($_rows as $_row):
	$_sid    = (int) $_row['survey_id'];
	$_status = (string) $_row['status'];
	$_label  = $_status_labels[$_status] ?? ucfirst($_status);
	// Past its scheduled close_at it takes no responses, whatever the status says.
	if (!empty($_row['ClosedScheduled'])) { $_label = 'Closed (scheduled)'; }
	$_opened = !empty($_row['opened_at']) ? date('M j, Y', strtotime((string) $_row['opened_at'])) : 'Not opened';
	// Still 'open' in status, but its open_at is ahead: respondents are told it
	// is not open yet, so the list says Scheduled and when it opens.
	if (!empty($_row['OpensScheduled'])) {
		$_label  = 'Scheduled';
		$_opened = 'Opens ' . date('M j, Y', strtotime((string) $_row['open_at']));
	}
	$_closes = !empty($_row['close_at']) ? date('M j, Y', strtotime((string) $_row['close_at'])) : '—';
	// Sort keys (DataTables reads data-order / data-search off the cell): status
	// sorts live-first, then by close date soonest-first with no close date last.
	$_status_rank = $_status_rank_map[$_status] ?? 9;
	$_close_key   = !empty($_row['close_at']) ? date('Y-m-d H:i:s', strtotime((string) $_row['close_at'])) : '9999-12-31 23:59:59';
	// Inherited rows (sharing spec §1) get none of the manager's actions, and
	// their count arrives as null unless results_share = 'all' (Survey::listForScope).
	$_shared = ($_row['Access'] ?? 'manage') === 'shared';
?>
						<tr role="row" data-sv-status="<?=htmlspecialchars($_status)?>" data-sv-slug="<?=htmlspecialchars((string)$_row['slug'])?>">
							<td role="cell" class="sv-survey-title" data-order="<?=htmlspecialchars(mb_strtolower((string)$_row['title']))?>">
<?php if ($_shared): ?>
								<?=htmlspecialchars((string)$_row['title'])?>
<?php else: ?>
								<a href="<?=UIR?>Survey/build/<?=$_sid?>"><?=htmlspecialchars((string)$_row['title'])?></a>
<?php endif; ?>
								<div class="sv-survey-meta">Created <?=date('M j, Y', strtotime((string)$_row['created_at']))?></div>
							</td>
							<td role="cell" data-label="Scope"><?=htmlspecialchars($_row['scope_type'] === 'ork' ? 'All of Amtgard' : (string)$_row['ScopeName'])?></td>
							<td role="cell" data-label="Status" data-order="<?=$_status_rank?>" data-search="<?=htmlspecialchars($_status)?>"><span class="sv-status-pill sv-status-pill-<?=htmlspecialchars($_status)?>"><?=$_label?></span></td>
<?php if ($_row['ResponseCount'] === null): /* the domain withholds a shared row's count unless results are shared with everyone */ ?>
							<td role="cell" class="dt-right" data-label="Responses" data-order="-1">&mdash;</td>
<?php else: ?>
							<td role="cell" class="dt-right" data-label="Responses" data-order="<?=(int)$_row['ResponseCount']?>"><?=number_format((int)$_row['ResponseCount'])?></td>
<?php endif; ?>
							<td role="cell" data-label="Opened / Closes" data-order="<?=$_close_key?>"><span><span class="sv-nowrap"><?=$_opened?></span> &rarr; <span class="sv-nowrap"><?=$_closes?></span></span></td>
<?php /* Each label is in its own span: the full table shows the buttons
	icon-only (the span is visually hidden, so it stays the accessible name)
	and data-tip names the action on hover and keyboard focus. */ ?>
							<td role="cell" class="sv-row-actions-cell">
								<div class="sv-row-actions">
<?php if ($_row['Access'] === 'manage'): ?>
									<a class="sv-row-btn" href="<?=UIR?>Survey/build/<?=$_sid?>" data-tip="Build: edit the questions and pages"><i class="fas fa-hammer" aria-hidden="true"></i> <span class="sv-row-btn-label">Build</span></a>
									<a class="sv-row-btn" href="<?=UIR?>Survey/results/<?=$_sid?>" data-tip="Results: charts, responses and export"><i class="fas fa-chart-bar" aria-hidden="true"></i> <span class="sv-row-btn-label">Results</span></a>
									<a class="sv-row-btn" href="<?=UIR?>Survey/take/<?=$_sid?>/preview" target="_blank" rel="noopener" data-tip="Preview: take the survey without saving (opens a new tab)"><i class="fas fa-eye" aria-hidden="true"></i> <span class="sv-row-btn-label">Preview</span></a>
									<button type="button" class="sv-row-btn sv-clone-btn" data-sid="<?=$_sid?>" data-tip="Clone: copy it into a new draft"><i class="fas fa-clone" aria-hidden="true"></i> <span class="sv-row-btn-label">Clone</span></button>
									<button type="button" class="sv-row-btn sv-copylink-btn" data-link="<?=htmlspecialchars(HTTP_UI_REMOTE . 'index.php?Route=Survey/s/' . rawurlencode((string)$_row['slug']))?>" data-tip="Copy link: copy the share link to your clipboard"><i class="fas fa-link" aria-hidden="true"></i> <span class="sv-row-btn-label">Copy link</span></button>
<?php if ($_status !== 'archived'): ?>
									<button type="button" class="sv-row-btn sv-archive-btn" data-sid="<?=$_sid?>" data-title="<?=htmlspecialchars((string)$_row['title'])?>" data-tip="Archive: stop collecting responses and hide it"><i class="fas fa-box-archive" aria-hidden="true"></i> <span class="sv-row-btn-label">Archive</span></button>
<?php endif; ?>
<?php if ($_status === 'draft' && (int)$_row['ResponseCount'] === 0): /* Survey::delete refuses anything else */ ?>
									<button type="button" class="sv-row-btn sv-delete-btn" data-sid="<?=$_sid?>" data-title="<?=htmlspecialchars((string)$_row['title'])?>" data-tip="Delete: remove this draft for good"><i class="fas fa-trash" aria-hidden="true"></i> <span class="sv-row-btn-label">Delete</span></button>
<?php endif; ?>
<?php endif; /* Access === manage */ ?>
<?php if ($_row['Access'] === 'shared'): ?>
<?php if ($_status === 'open'): ?>
									<a class="sv-row-btn" href="<?=UIR?>Survey/s/<?=htmlspecialchars((string)$_row['slug'])?>" data-tip="Take this survey"><i class="fas fa-pen-to-square" aria-hidden="true"></i> <span class="sv-row-btn-label">Take</span></a>
<?php endif; ?>
<?php if (!empty($_row['CanResults'])): ?>
<?php /* The visible label stays "Results" so it fits a ~100px card cell at
	phone width; the lens rides in the tip and the accessible name. */
	$_lens_note = $_row['ResultsLabel'] === 'all' ? 'shared: all respondents' : 'your ' . htmlspecialchars($_row['ResultsLabel']); ?>
									<a class="sv-row-btn" href="<?=UIR?>Survey/results/<?=$_sid?>/<?=htmlspecialchars((string)$_row['ResultsContext'])?>"
									   data-tip="Results (<?=$_lens_note?>)"><i class="fas fa-chart-bar" aria-hidden="true"></i> <span class="sv-row-btn-label">Results</span><span class="sv-visually-hidden"> (<?=$_lens_note?>)</span></a>
<?php elseif (!empty($_row['ResultsPending'])): ?>
<?php /* Held by after-close sharing timing: a focusable, inert control whose
	tip and accessible name say when results open (the label stays short for
	the phone card cell). */ ?>
									<button type="button" class="sv-row-btn sv-row-btn-wait" aria-disabled="true" aria-describedby="sv-wait-<?=$_key?>-<?=$_sid?>"
									        data-tip="<?=htmlspecialchars((string)$_row['ResultsPendingText'])?>"><i class="fas fa-clock" aria-hidden="true"></i> <span class="sv-row-btn-label">Results</span></button>
<?php endif; ?>
<?php endif; /* Access === shared */ ?>
<?php if (!empty($_row['CreditGrantor']) && $_status !== 'archived'):
	/* CreditShownOn (domain): this org's own config, or an earlier one (its
	   kingdom's, the owner's event) already covers its players (spec §1
	   CreditChip). The tip says which. */
	$_cov      = (string)($_row['CreditCoveredBy'] ?? '');
	$_cred_on  = !empty($_row['CreditShownOn']);
	$_cred_tip = !empty($_row['CreditOn']) ? 'Attendance credit is on'
		: ($_cov !== '' ? 'Attendance credit is on: your players are covered by ' . $_cov . '’s credit' : 'Attendance credit');
?>
									<button type="button" class="sv-row-btn<?=$_cred_on ? ' sv-row-btn-on' : ''?>" data-sv-credit="<?=$_sid?>"
									        data-sv-grantor="<?=htmlspecialchars((string)$_row['CreditGrantor'])?>" data-sv-title="<?=htmlspecialchars((string)$_row['title'])?>"
									        data-tip="<?=htmlspecialchars($_cred_tip)?>" aria-label="<?=$_cred_on ? 'Credits (on)' : 'Credits (off)'?>"><i class="fas <?=$_cred_on ? 'fa-circle-check' : 'fa-award'?>" aria-hidden="true"></i> <span class="sv-row-btn-label">Credits</span></button>
<?php endif; ?>
								</div>
<?php if (!empty($_row['ResultsPending'])): ?>
								<p class="sv-row-wait-note" id="sv-wait-<?=$_key?>-<?=$_sid?>"><i class="fas fa-clock" aria-hidden="true"></i> <?=htmlspecialchars((string)$_row['ResultsPendingText'])?></p>
<?php endif; ?>
							</td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
<?php endif; ?>
			</section>
<?php endforeach; ?>
<?php endif; ?>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<!-- New Survey modal -->
<div class="sv-overlay" id="sv-new-overlay">
	<div class="sv-modal sv-scope" role="dialog" aria-modal="true" aria-labelledby="sv-new-heading">
		<h4 class="sv-modal-title" id="sv-new-heading">New Survey</h4>
		<div class="sv-modal-body">
			<div class="sv-field">
				<label for="sv-new-title">Title</label>
				<input type="text" id="sv-new-title" class="sv-input" maxlength="200" placeholder="e.g. Fall 2026 Feedback">
			</div>
			<div class="sv-field">
				<label for="sv-new-scope">Scope</label>
				<select id="sv-new-scope" class="sv-select">
<?php foreach ($Scopes as $_sc): ?>
					<option value="<?=htmlspecialchars($_sc['scope_type'])?>:<?=(int)$_sc['scope_id']?>"<?php if ($_sc['scope_type'] === $ScopeType && (int)$_sc['scope_id'] === (int)$ScopeId) { echo ' selected'; } ?>><?=htmlspecialchars($_sc['name'])?></option>
<?php endforeach; ?>
				</select>
			</div>
			<div class="sv-modal-error" id="sv-new-error"></div>
		</div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-new-cancel">Cancel</button>
			<button type="button" class="sv-modal-btn sv-modal-ok" id="sv-new-ok">Create</button>
		</div>
	</div>
</div>

<!-- Archive confirm modal -->
<div class="sv-overlay" id="sv-archive-overlay">
	<div class="sv-modal sv-scope" role="dialog" aria-modal="true" aria-labelledby="sv-archive-heading">
		<h4 class="sv-modal-title" id="sv-archive-heading">Archive Survey</h4>
		<div class="sv-modal-body" id="sv-archive-body"></div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-archive-cancel">Cancel</button>
			<button type="button" class="sv-modal-btn sv-modal-ok sv-modal-danger" id="sv-archive-ok">Archive</button>
		</div>
	</div>
</div>

<!-- Delete confirm modal (drafts with no responses only) -->
<div class="sv-overlay" id="sv-delete-overlay">
	<div class="sv-modal sv-scope" role="dialog" aria-modal="true" aria-labelledby="sv-delete-heading">
		<h4 class="sv-modal-title" id="sv-delete-heading">Delete Survey</h4>
		<div class="sv-modal-body" id="sv-delete-body"></div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-delete-cancel">Cancel</button>
			<button type="button" class="sv-modal-btn sv-modal-ok sv-modal-danger" id="sv-delete-ok">Delete</button>
		</div>
	</div>
</div>

<!-- Help modal -->
<div class="sv-overlay" id="sv-help-overlay">
	<div class="sv-modal sv-scope" role="dialog" aria-modal="true" aria-labelledby="sv-help-heading" style="max-width:640px;">
		<h4 class="sv-modal-title" id="sv-help-heading">Survey Guide</h4>
		<div class="sv-modal-body sv-md" id="sv-help-body">Loading&hellip;</div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-help-close">Close</button>
		</div>
	</div>
</div>

<!-- Share link fallback: when the clipboard API is unavailable or refused, the
     URL needs a persistent, selectable home — a 2.2s toast is unreadable and
     untargetable with a screen reader or a slow hand. -->
<div class="sv-overlay" id="sv-link-overlay">
	<div class="sv-modal sv-scope" role="dialog" aria-modal="true" aria-labelledby="sv-link-heading">
		<h4 class="sv-modal-title" id="sv-link-heading">Share Link</h4>
		<div class="sv-modal-body">
			<div class="sv-field">
				<label for="sv-link-input">Copy this link</label>
				<input type="text" id="sv-link-input" class="sv-input" readonly>
			</div>
		</div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-link-close">Close</button>
		</div>
	</div>
</div>

<div class="sv-toast" id="sv-toast" role="status" aria-live="polite"></div>

<script>window.SvCreditConfig = { uir: <?=json_encode(UIR)?>, csrf: <?=json_encode((string)($SurveyCsrf ?? '')) ?> };</script>
<?php include __DIR__ . '/_survey_credit_modal.tpl'; ?>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="<?=HTTP_TEMPLATE?>default/script/survey-tip.js?v=<?=filemtime(__DIR__.'/script/survey-tip.js')?>"></script>
<script>
(function() {
	'use strict';
	var UIR_BASE = '<?= UIR ?>';
	// Every SurveyAjax POST mutation must carry this in X-CSRF-Token (#43).
	var SvConfig = { csrf: <?= json_encode((string)($SurveyCsrf ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> };

	// POST to SurveyAjax/<action> with the session token; resolves to the JSON
	// reply. A csrf:true reply also raises the page's inline Reload notice.
	function post(action, fields) {
		var fd = new FormData();
		Object.keys(fields || {}).forEach(function(k) { fd.append(k, fields[k]); });
		return fetch(UIR_BASE + 'SurveyAjax/' + action, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: { 'X-CSRF-Token': SvConfig.csrf }
		})
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j && j.csrf) { showCsrfNotice(); }
				return j || {};
			});
	}

	function showCsrfNotice() {
		var n = document.getElementById('sv-csrf-notice');
		if (!n) { return; }
		n.hidden = false;
		if (n.scrollIntoView) { n.scrollIntoView({ block: 'nearest' }); }
	}
	var csrfReload = document.getElementById('sv-csrf-reload');
	if (csrfReload) {
		csrfReload.addEventListener('click', function(e) { e.preventDefault(); window.location.reload(); });
	}

	// #sv-toast is role="status" aria-live="polite", so every message below is
	// announced as well as shown.
	function notice(msg) {
		var el = document.getElementById('sv-toast');
		el.textContent = msg;
		el.classList.add('sv-toast-show');
		clearTimeout(el._t);
		el._t = setTimeout(function() { el.classList.remove('sv-toast-show'); }, 2200);
	}

	// ----- Dialog plumbing: focus in, focus trapped, Escape out, focus back -----
	var openOv = null, lastFocus = null;
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function focusables(ov) {
		return Array.prototype.filter.call(ov.querySelectorAll(FOCUSABLE), function(el) {
			return el.offsetParent !== null;
		});
	}

	function openOverlay(id, focusId) {
		var ov = document.getElementById(id);
		lastFocus = document.activeElement;
		ov.classList.add('sv-open');
		openOv = ov;
		var first = focusId ? document.getElementById(focusId) : null;
		if (!first) { first = focusables(ov)[0]; }
		if (first) { first.focus(); }
	}

	function closeOverlay(id) {
		var ov = document.getElementById(id);
		ov.classList.remove('sv-open');
		if (openOv === ov) { openOv = null; }
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
		lastFocus = null;
	}

	document.querySelectorAll('.sv-overlay').forEach(function(ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) { closeOverlay(ov.id); } });
	});

	document.addEventListener('keydown', function(e) {
		if (!openOv) { return; }
		if (e.key === 'Escape' || e.key === 'Esc') {
			e.preventDefault();
			closeOverlay(openOv.id);
			return;
		}
		if (e.key !== 'Tab') { return; }
		var f = focusables(openOv);
		if (!f.length) { return; }
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && (document.activeElement === first || !openOv.contains(document.activeElement))) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && (document.activeElement === last || !openOv.contains(document.activeElement))) {
			e.preventDefault();
			first.focus();
		}
	});

	// ----- Survey list DataTables -----
	// Up to three tables now, one per org section (Amtgard / Kingdom / Park).
	// Columns: 0 Title, 1 Scope, 2 Status, 3 Responses, 4 Opened / Closes,
	// 5 row actions. Status and close date sort on the cells' data-order keys
	// (live first, soonest close first); the status pills search column 2's
	// data-search value on every table at once.
	var STATUS_COL = 2;
	var dts = [];
	if (window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable) {
		document.querySelectorAll('.sv-survey-table').forEach(function(tableEl) {
			dts.push(window.jQuery(tableEl).DataTable({
				dom        : 'lfr<"sv-dt-scroll"t>ip',
				pageLength : 25,
				lengthMenu : [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
				order      : [[STATUS_COL, 'asc'], [4, 'asc']],
				autoWidth  : false,
				columnDefs : [
					{ targets: [0], type: 'html' },
					{ targets: [3], type: 'num', className: 'dt-right' },
					{ targets: [5], orderable: false, searchable: false }
				],
				language   : {
					search           : 'Search:',
					searchPlaceholder: 'Search surveys',
					lengthMenu       : 'Show _MENU_ surveys',
					info             : 'Showing _START_ to _END_ of _TOTAL_ surveys',
					infoEmpty        : 'No surveys to show',
					infoFiltered     : '(filtered from _MAX_)',
					zeroRecords      : 'No surveys match this filter.',
					emptyTable       : 'No surveys yet for this scope.'
				},
				// DataTables' own "no match" row has no ARIA roles; in the card
				// layout (display:block) it would drop out of the table for
				// WebKit screen readers like an unmarked server row would.
				drawCallback: function() {
					tableEl.querySelectorAll('tbody tr:not([role])').forEach(function(tr) { tr.setAttribute('role', 'row'); });
					tableEl.querySelectorAll('tbody td:not([role])').forEach(function(td) { td.setAttribute('role', 'cell'); });
				}
			}));
		});
	}

	// data-tip tooltips are shown by the shared script/survey-tip.js.

	// ----- Status filter pills -----
	var pills = document.querySelectorAll('[data-sv-filter]');
	pills.forEach(function(pill) {
		pill.addEventListener('click', function() {
			pills.forEach(function(p) { p.classList.remove('active'); p.setAttribute('aria-pressed', 'false'); });
			pill.classList.add('active');
			pill.setAttribute('aria-pressed', 'true');
			var f = pill.getAttribute('data-sv-filter');
			if (dts.length) {
				dts.forEach(function(dt) { dt.column(STATUS_COL).search(f === 'all' ? '' : '^' + f + '$', true, false).draw(); });
				return;
			}
			// No DataTables (CDN blocked): fall back to hiding rows in place.
			document.querySelectorAll('.sv-survey-table tbody tr').forEach(function(row) {
				row.hidden = (f !== 'all' && row.getAttribute('data-sv-status') !== f);
			});
		});
	});

	// Row actions live on DataTables rows that may not be drawn yet (another
	// page, filtered out), so they are delegated from the document, not bound
	// per button at load.
	function delegate(selector, handler) {
		document.addEventListener('click', function(e) {
			var btn = e.target && e.target.closest ? e.target.closest(selector) : null;
			if (btn) { handler(btn, e); }
		});
	}

	// ----- New Survey modal -----
	function openNewModal() {
		document.getElementById('sv-new-title').value = '';
		document.getElementById('sv-new-error').style.display = 'none';
		openOverlay('sv-new-overlay', 'sv-new-title');
	}
	var newBtn = document.getElementById('sv-new-btn');
	if (newBtn) { newBtn.addEventListener('click', openNewModal); }
	var emptyNewBtn = document.getElementById('sv-empty-new-btn');
	if (emptyNewBtn) { emptyNewBtn.addEventListener('click', openNewModal); }
	document.getElementById('sv-new-cancel').addEventListener('click', function() { closeOverlay('sv-new-overlay'); });

	// An expired token inside a modal is reported in the modal itself (the
	// page notice sits behind the overlay), with the same Reload link.
	function csrfInto(el) {
		el.textContent = 'Your security token expired. ';
		var a = document.createElement('a');
		a.href = '';
		a.textContent = 'Reload the page';
		a.className = 'sv-notice-link';
		a.addEventListener('click', function(e) { e.preventDefault(); window.location.reload(); });
		el.appendChild(a);
		el.appendChild(document.createTextNode(' and try again.'));
		el.style.display = 'block';
	}

	document.getElementById('sv-new-ok').addEventListener('click', function() {
		var okBtn = this;
		var title = document.getElementById('sv-new-title').value.trim();
		var scope = document.getElementById('sv-new-scope').value.split(':');
		var errEl = document.getElementById('sv-new-error');
		if (!title) {
			errEl.textContent = 'Give the survey a title.';
			errEl.style.display = 'block';
			return;
		}
		okBtn.disabled = true;
		post('create', { ScopeType: scope[0], ScopeId: scope[1] || '0', Title: title })
			.then(function(j) {
				if (j.status === 0 && j.survey_id) {
					window.location.href = UIR_BASE + 'Survey/build/' + j.survey_id;
					return;
				}
				okBtn.disabled = false;
				if (j.csrf) {
					csrfInto(errEl);
				} else {
					errEl.textContent = j.error || 'Could not create the survey.';
					errEl.style.display = 'block';
				}
			})
			.catch(function() {
				okBtn.disabled = false;
				errEl.textContent = 'Network error creating the survey.';
				errEl.style.display = 'block';
			});
	});

	// ----- Clone -----
	delegate('.sv-clone-btn', function(btn) {
		if (btn.disabled) { return; }
		btn.disabled = true;
		post('clone', { SurveyId: btn.getAttribute('data-sid') })
			.then(function(j) {
				if (j.status === 0 && j.survey_id) {
					window.location.href = UIR_BASE + 'Survey/build/' + j.survey_id;
					return;
				}
				btn.disabled = false;
				if (!j.csrf) { notice(j.error || 'Could not clone the survey.'); }
			})
			.catch(function() { btn.disabled = false; notice('Network error cloning the survey.'); });
	});

	// ----- Copy link -----
	delegate('.sv-copylink-btn', function(btn) {
		var url = btn.getAttribute('data-link');
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(function() {
				notice('Share link copied.');
			}, function() {
				showLink(url);
			});
		} else {
			showLink(url);
		}
	});

	// ----- Attendance credit -----
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-sv-credit]');
		if (!b || !window.SvCredit) { return; }
		window.SvCredit.open({
			surveyId: parseInt(b.getAttribute('data-sv-credit'), 10),
			grantor: b.getAttribute('data-sv-grantor') || '',
			title: b.getAttribute('data-sv-title') || '',
			// Called only after this org's credit is turned on (survey-credit.js).
			onChange: function () {
				b.classList.add('sv-row-btn-on');
				b.setAttribute('data-tip', 'Attendance credit is on');
				var ic = b.querySelector('i');
				if (ic) { ic.className = 'fas fa-circle-check'; }
				b.setAttribute('aria-label', 'Credits (on)');
			}
		});
	});

	// ----- Archive -----
	var archiveSid = null;
	delegate('.sv-archive-btn', function(btn) {
		archiveSid = btn.getAttribute('data-sid');
		document.getElementById('sv-archive-body').textContent =
			'Archive "' + btn.getAttribute('data-title') + '"? It will stop collecting responses and be hidden from Available Surveys.';
		openOverlay('sv-archive-overlay', 'sv-archive-cancel');
	});
	document.getElementById('sv-archive-cancel').addEventListener('click', function() { closeOverlay('sv-archive-overlay'); });
	document.getElementById('sv-archive-ok').addEventListener('click', function() {
		if (!archiveSid) { return; }
		var okBtn = this;
		okBtn.disabled = true;
		post('set_status', { SurveyId: archiveSid, Status: 'archived' })
			.then(function(j) {
				okBtn.disabled = false;
				if (j.status === 0) {
					window.location.reload();
					return;
				}
				// The page's inline notice (raised by post()) is behind the
				// overlay, so close it for the token case too.
				closeOverlay('sv-archive-overlay');
				if (!j.csrf) { notice(j.error || 'Could not archive the survey.'); }
			})
			.catch(function() { okBtn.disabled = false; closeOverlay('sv-archive-overlay'); notice('Network error archiving the survey.'); });
	});

	// ----- Delete (never-answered drafts) -----
	var deleteBtn = null;
	delegate('.sv-delete-btn', function(btn) {
		deleteBtn = btn;
		document.getElementById('sv-delete-body').textContent =
			'Delete "' + btn.getAttribute('data-title') + '"? Its questions, pages and images are removed for good. This cannot be undone.';
		openOverlay('sv-delete-overlay', 'sv-delete-cancel');
	});
	document.getElementById('sv-delete-cancel').addEventListener('click', function() { closeOverlay('sv-delete-overlay'); });
	document.getElementById('sv-delete-ok').addEventListener('click', function() {
		if (!deleteBtn) { return; }
		var okBtn = this, btn = deleteBtn;
		okBtn.disabled = true;
		post('delete', { SurveyId: btn.getAttribute('data-sid') })
			.then(function(j) {
				okBtn.disabled = false;
				closeOverlay('sv-delete-overlay');
				if (j.status !== 0) {
					if (!j.csrf) { notice(j.error || 'Could not delete the survey.'); }
					return;
				}
				deleteBtn = null;
				var tr = btn.closest('tr');
				// Keep the stat tiles in step: Total, plus the tile for this row's status.
				var rowStatus = tr ? tr.getAttribute('data-sv-status') : null;
				['total', rowStatus].forEach(function(key) {
					var tile = key ? document.querySelector('[data-sv-stat="' + key + '"]') : null;
					if (!tile) { return; }
					var n = parseInt(tile.textContent.replace(/[^0-9]/g, ''), 10) || 0;
					tile.textContent = Math.max(0, n - 1).toLocaleString('en-US');
				});
				var section = btn.closest('.sv-list-section');
				var tableEl = btn.closest('table');
				var dt = null;
				if (tableEl && window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable
					&& window.jQuery.fn.DataTable.isDataTable(tableEl)) {
					dt = window.jQuery(tableEl).DataTable();
				}
				if (dt) { dt.row(tr).remove().draw(false); } else if (tr) { tr.remove(); }
				var count = section ? section.querySelector('.sv-list-section-count') : null;
				if (count) { count.textContent = String(Math.max(0, (parseInt(count.textContent, 10) || 1) - 1)); }
				notice('Survey deleted.');
			})
			.catch(function() { okBtn.disabled = false; closeOverlay('sv-delete-overlay'); notice('Network error deleting the survey.'); });
	});

	// ----- Share link fallback modal -----
	function showLink(url) {
		var input = document.getElementById('sv-link-input');
		input.value = url;
		openOverlay('sv-link-overlay', 'sv-link-input');
		input.select();
	}
	document.getElementById('sv-link-close').addEventListener('click', function() { closeOverlay('sv-link-overlay'); });

	// ----- Help modal -----
	// help is a read (CSRF-exempt), but it goes through post() like every other
	// call so the header is always present.
	function openHelp() {
		openOverlay('sv-help-overlay', 'sv-help-close');
		var body = document.getElementById('sv-help-body');
		body.innerHTML = 'Loading&hellip;';
		post('help', { Doc: 'surveys' })
			.then(function(j) {
				body.innerHTML = (j.status === 0 && j.html) ? j.html : 'Could not load the guide right now.';
			})
			.catch(function() { body.innerHTML = 'Could not load the guide right now.'; });
	}
	document.getElementById('sv-help-btn').addEventListener('click', openHelp);
	document.getElementById('sv-help-close').addEventListener('click', function() { closeOverlay('sv-help-overlay'); });
})();
</script>
