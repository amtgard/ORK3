<?php
/**
 * Survey results (design spec §7 "Results").
 *
 * Plain PHP template — NOT Smarty.
 *
 * Data supplied by Controller_Survey::results():
 *   $SurveyId   int
 *   $Survey     envelope from Model_Survey::get() — ['Survey'=>row, 'Pages'=>[], 'Questions'=>[], ...]
 *   $Questions  list of question rows (DB columns + decoded settings + Options)
 *   $Kingdoms   kingdoms present in this survey's responses (Model_Survey::kingdoms_present):
 *               list of ['kingdom_id'=>int,'name'=>string,'count'=>int] (+ scope_type/scope_id aliases)
 *   $SurveyCsrf session token for SurveyAjax POSTs (X-CSRF-Token)
 *   $ScopeName  optional display name for the survey's scope
 *   $Error      optional error string
 *
 * Everything on the page beyond this shell is drawn by survey-results.js from
 * SurveyAjax/results and SurveyAjax/rows. This file only emits the frame, the
 * filter controls and the SvConfig bootstrap.
 */

$_svr_id       = isset($SurveyId) ? (int) $SurveyId : 0;
$_svr_envelope = (isset($Survey) && is_array($Survey)) ? $Survey : [];
$_svr_row      = (isset($_svr_envelope['Survey']) && is_array($_svr_envelope['Survey'])) ? $_svr_envelope['Survey'] : [];
$_svr_qs       = (isset($Questions) && is_array($Questions)) ? $Questions : [];
$_svr_kingdoms = (isset($Kingdoms) && is_array($Kingdoms)) ? $Kingdoms : [];
$_svr_error    = isset($Error) ? trim((string) $Error) : '';
/* Held by after-close sharing timing (sharing spec §2): a notice, not an error. */
$_svr_pending  = isset($ResultsPendingText) ? trim((string) $ResultsPendingText) : '';

$_svr_title  = isset($_svr_row['title']) && $_svr_row['title'] !== '' ? (string) $_svr_row['title'] : 'Survey';
$_svr_status = isset($_svr_row['status']) ? (string) $_svr_row['status'] : '';

/* Sharing lens (sharing-and-credits spec §2, §5). A shared viewer (one org
   level down, results_share allowing it) reads charts and stats only —
   no rows, no export, no per-response panel. */
$_svr_shared = ($ResultsAccess['level'] ?? 'manage') === 'shared';
$_svr_lens   = $ResultsAccess['label'] ?? '';

/* Scope chip. $Kingdoms lists the kingdoms present in the responses, so it names
   a kingdom-scoped survey's own kingdom whenever anyone from it answered; a
   controller-supplied $ScopeName wins when there is one. */
$_svr_scope_type = isset($_svr_row['scope_type']) ? (string) $_svr_row['scope_type'] : '';
$_svr_scope_id   = isset($_svr_row['scope_id']) ? (int) $_svr_row['scope_id'] : 0;
$_svr_scope_name = isset($ScopeName) ? trim((string) $ScopeName) : '';
$_svr_scope_icon = 'fa-globe';
if ($_svr_scope_type === 'kingdom') {
	$_svr_scope_icon = 'fa-crown';
	if ($_svr_scope_name === '') {
		foreach ($_svr_kingdoms as $_k) {
			if ((int) ($_k['kingdom_id'] ?? ($_k['scope_id'] ?? 0)) === $_svr_scope_id) {
				$_svr_scope_name = (string) ($_k['name'] ?? '');
				break;
			}
		}
	}
	if ($_svr_scope_name === '') {
		$_svr_scope_name = 'Kingdom';
	}
} elseif ($_svr_scope_type === 'park') {
	$_svr_scope_icon = 'fa-shield-alt';
	if ($_svr_scope_name === '') {
		$_svr_scope_name = 'Park';
	}
} elseif ($_svr_scope_type !== '' && $_svr_scope_name === '') {
	$_svr_scope_name = $_svr_scope_type === 'ork' ? 'ORK-wide' : ucfirst($_svr_scope_type);
}

/* Question list for the client: answerable questions only (section / image blocks
   collect no answers, so they get no chart and no row column). `num` is the
   Q1..Qn label shared by the chart cards, the row-table headers and the
   response panel. */
$_svr_js_qs    = [];
$_svr_crosstab = [];
$_svr_xt_targets = 0;
foreach ($_svr_qs as $_q) {
	$_t = (string) ($_q['type'] ?? '');
	if ($_t === '' || !in_array($_t, $AnswerableTypes ?? [], true)) {
		continue;
	}
	$_svr_js_qs[] = [
		'question_id' => (int) ($_q['question_id'] ?? 0),
		'type'        => $_t,
		'prompt'      => (string) ($_q['prompt'] ?? ''),
		'num'         => count($_svr_js_qs) + 1,
	];
	if (in_array($_t, $CrosstabTargets ?? [], true)) {
		$_svr_xt_targets++;
	}
	/* Cross-tab sources are single-answer choice questions: every response falls
	   in exactly one bucket. */
	if (in_array($_t, $CrosstabSources ?? [], true)) {
		$_svr_crosstab[] = [
			'question_id' => (int) ($_q['question_id'] ?? 0),
			'prompt'      => (string) ($_q['prompt'] ?? ''),
			'num'         => count($_svr_js_qs),
		];
	}
}

/* The kingdom filter only earns its space when the responses span more than
   one kingdom (#33). */
$_svr_show_kingdoms = count($_svr_kingdoms) > 1;

/* The report never splits the source question by itself, so the cross-tab
   earns its field only with a source plus one other question to split (#9).
   A source is itself a target, hence two targets. */
$_svr_show_crosstab = $_svr_crosstab && $_svr_xt_targets >= 2;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__ . '/style/reports.css')?>">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/survey.css?v=<?=filemtime(__DIR__ . '/style/survey.css')?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/survey-results.css?v=<?=filemtime(__DIR__ . '/style/survey-results.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<?php if ($_svr_error !== '' || $_svr_pending !== '') : ?>
<div class="rp-root">
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-chart-pie rp-header-icon"></i>
				<h1 class="rp-header-title">Survey Results</h1>
			</div>
		</div>
	</div>
	<div class="rp-context">
<?php if ($_svr_pending !== '') : ?>
		<i class="fas fa-clock rp-context-icon" aria-hidden="true"></i>
		<span><strong><?=htmlspecialchars((string) ($_svr_row['title'] ?? 'This survey'))?></strong> — <?=htmlspecialchars($_svr_pending)?></span>
<?php else : ?>
		<i class="fas fa-exclamation-triangle rp-context-icon"></i>
		<span><?=htmlspecialchars($_svr_error)?></span>
<?php endif; ?>
	</div>
</div>
<?php else : ?>

<div class="rp-root svr-root" id="svr-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-chart-pie rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($_svr_title)?></h1>
<?php if ($_svr_status !== '') : ?>
				<span class="svr-status-pill svr-status-<?=htmlspecialchars($_svr_status)?>"><?=htmlspecialchars(ucfirst($_svr_status))?></span>
<?php endif; ?>
			</div>
<?php if ($_svr_scope_name !== '') : ?>
			<div class="rp-header-scope">
				<span class="rp-scope-chip-label">Scope:</span>
				<span class="rp-scope-chip"><i class="fas <?=htmlspecialchars($_svr_scope_icon)?>"></i> <?=htmlspecialchars($_svr_scope_name)?></span>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
<?php if (!$_svr_shared) : ?>
			<a class="rp-btn-ghost" id="svr-export" href="<?=UIR?>Survey/export/<?=$_svr_id?>"><i class="fas fa-download"></i> Export CSV</a>
			<a class="rp-btn-ghost" id="svr-export-analysis" href="<?=UIR?>Survey/export/<?=$_svr_id?>&format=analysis" data-tip="Analysis CSV: one coded column per answer (Q{id}, _o multi 0/1, _r matrix rows, _rank_o, _wins_o), -99 = not shown, blank = skipped. Pair it with the codebook."><i class="fas fa-table"></i> Analysis CSV</a>
			<a class="rp-btn-ghost" id="svr-export-codebook" href="<?=UIR?>Survey/export/<?=$_svr_id?>&format=codebook" data-tip="Codebook for the Analysis CSV: every column code, its question, type, option codes and values, and show-if rules."><i class="fas fa-book"></i> Codebook</a>
			<a class="rp-btn-ghost" href="<?=UIR?>Survey/build/<?=$_svr_id?>"><i class="fas fa-pen-to-square"></i> Builder</a>
			<a class="rp-btn-ghost" href="<?=UIR?>Survey/take/<?=$_svr_id?>/preview"><i class="fas fa-eye"></i> Preview</a>
			<button type="button" class="rp-btn-ghost svr-btn-danger" id="svr-clear" data-tip="Permanently delete every response, test and real. Attendance credits already posted stay."><i class="fas fa-trash-can"></i> Clear Results</button>
<?php endif; ?>
			<button type="button" class="rp-btn-ghost" id="svr-summary-toggle" aria-pressed="false" data-tip="Charts only, with the filters and response count in a caption: no row-level data and no written comments. Safe to print for court."><i class="fas fa-file-lines"></i> Summary for sharing</button>
<?php if (!$_svr_shared) : ?>
			<button type="button" class="rp-btn-ghost" id="svr-print"><i class="fas fa-print"></i> Print</button>
<?php else : /* Spec §2: a shared viewer's Print is the summary's own — survey-results.css shows it only in Summary for sharing. */ ?>
			<button type="button" class="rp-btn-ghost svr-print-summary" id="svr-print" data-tip="Print the summary for sharing"><i class="fas fa-print"></i> Print summary</button>
<?php endif; ?>
		</div>
	</div>

<?php if ($_svr_shared) :
	$_org = htmlspecialchars((string) ($ResultsAccess['org_name'] ?? ''));
	/* An ORK survey's $OwnerName is the scope label "All of Amtgard", which
	   does not read as a sharer ("Shared by All of Amtgard"), so name the ORK. */
	$_owner = $_svr_scope_type === 'ork' ? 'the ORK' : htmlspecialchars((string) $OwnerName);
	$_lens_text = [
		'kingdom' => 'Showing responses from players of ' . $_org . ' who chose Any ORK Data or My Kingdom and How Long I’ve Been Playing. Anonymous responses can’t be attributed to a kingdom.',
		'park'    => 'Showing responses from ' . $_org . ' players who chose Any ORK Data. Other responses can’t be attributed to a park.',
		'all'     => 'Shared by ' . $_owner . ': all respondents.',
	][$_svr_lens] ?? '';
?>
	<div class="rp-context svr-lens" role="note">
		<i class="fas fa-share-nodes rp-context-icon" aria-hidden="true"></i>
		<span><?=$_lens_text?> Charts and stats only; individual responses stay with the survey’s owners.</span>
	</div>
<?php endif; ?>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
<?php if ($_svr_shared) : /* Shared viewers never see personal details (D2): no line about them. */ ?>
		<span>Aggregated answers for every response that matches the filters. Any group of fewer than 5 responses is hidden so no one can be singled out.</span>
<?php else : ?>
		<span>Aggregated answers for every response that matches the filters. Personal details are shown only for respondents who chose to share them &mdash; anonymous responses carry no kingdom, no persona and a date-only timestamp. Any group of fewer than 5 responses is hidden so no one can be singled out.</span>
<?php endif; ?>
	</div>

	<!-- Security-token / transport notice (#43). Kept outside .rp-body so it is
	     the first thing read after the header. -->
	<div class="sv-scope">
		<div class="sv-notice sv-notice-error svr-csrf" id="svr-csrf" role="alert" hidden></div>
	</div>

	<!-- Stats -->
	<div class="rp-stats-row" id="svr-stats">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-inbox"></i></div>
			<div class="rp-stat-number" id="svr-stat-responses">&mdash;</div>
			<div class="rp-stat-label" id="svr-stat-responses-label">Responses</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number" id="svr-stat-rate">&mdash;</div>
			<div class="rp-stat-label">Response rate</div>
			<div class="rp-stat-hint" id="svr-stat-rate-hint">of the current audience</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-circle-check"></i></div>
			<div class="rp-stat-number" id="svr-stat-completion">&mdash;</div>
			<div class="rp-stat-label">Completion</div>
			<div class="rp-stat-hint">submitted vs. started</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-stopwatch"></i></div>
			<div class="rp-stat-number" id="svr-stat-duration">&mdash;</div>
			<div class="rp-stat-label">Median time</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-shield"></i></div>
			<div class="svr-consent-split" id="svr-stat-consent">
				<span class="svr-consent-part"><b>&mdash;</b> full</span>
				<span class="svr-consent-part"><b>&mdash;</b> partial</span>
				<span class="svr-consent-part"><b>&mdash;</b> anon</span>
			</div>
			<div class="rp-stat-label">Consent split</div>
		</div>
	</div>

	<div class="rp-body sv-scope">

		<!-- Filters. Below 900px the card collapses behind the disclosure button
		     so the first chart is not pushed a screen and a half down (#33). -->
		<div class="rp-sidebar svr-sidebar" id="svr-sidebar">
			<button type="button" class="sv-btn svr-filters-toggle" id="svr-filters-toggle" aria-expanded="true" aria-controls="svr-filters">
				<span><i class="fas fa-filter"></i> <span id="svr-filters-toggle-label">Filters</span></span>
				<i class="fas fa-chevron-down svr-chev" aria-hidden="true"></i>
			</button>
			<form class="rp-filter-card" id="svr-filters" onsubmit="return false;">
				<div class="rp-filter-card-header"><i class="fas fa-filter"></i> Filters</div>
				<div class="rp-filter-card-body">

<?php if ($_svr_show_kingdoms && !($_svr_lens === 'kingdom' || $_svr_lens === 'park')) : ?>
					<fieldset class="svr-fieldset">
						<legend class="svr-field-label">Kingdom</legend>
						<div class="svr-checklist" aria-describedby="svr-kingdom-hint">
<?php if ($_svr_shared) : /* A shared viewer picks all kingdoms or ONE the server allows ($Kingdoms is SurveyReport::sharedKingdomChoices() for them, served counts); the server refuses anything else. */ ?>
							<label class="svr-check"><input type="radio" name="svr-kingdom" class="svr-kingdom" value="0" data-name="All kingdoms" checked> <span>All kingdoms</span></label>
<?php endif; ?>
<?php foreach ($_svr_kingdoms as $_k) : ?>
<?php 	$_kid = (int) ($_k['kingdom_id'] ?? ($_k['scope_id'] ?? 0)); $_kname = (string) ($_k['name'] ?? ''); ?>
<?php 	if ($_svr_shared && (int) ($_k['count'] ?? 0) < 5) { continue; } ?>
							<label class="svr-check"><input type="<?=$_svr_shared ? 'radio' : 'checkbox'?>"<?=$_svr_shared ? ' name="svr-kingdom"' : ''?> class="svr-kingdom" value="<?=$_kid?>" data-name="<?=htmlspecialchars($_kname)?>"> <span><?=htmlspecialchars($_kname)?> <span class="svr-count">(<?=(int) ($_k['count'] ?? 0)?>)</span></span></label>
<?php endforeach; ?>
						</div>
						<p class="svr-field-hint" id="svr-kingdom-hint">Anonymous responses have no kingdom and are excluded when this filter is set.</p>
					</fieldset>
<?php endif; ?>

<?php if (!$_svr_shared) : /* No consent pick for a shared viewer: 'any' minus 'full' would isolate a few partial/anonymous respondents (the server forces 'any'). */ ?>
					<div class="svr-field">
						<label class="svr-field-label" for="svr-consent">Consent level</label>
						<select class="sv-select" id="svr-consent">
							<option value="any">Any</option>
							<option value="full">Full &mdash; name shared</option>
							<option value="partial">Partial &mdash; kingdom and years played</option>
							<option value="anonymous">Anonymous</option>
						</select>
					</div>
<?php endif; ?>

<?php if (!$_svr_shared) : /* No date bounds for a shared viewer: home-park credits are public and dated the day taken, so two date windows would name a respondent's answers (the server drops them too). */ ?>
					<div class="svr-field">
						<div class="svr-label-row">
							<label class="svr-field-label" for="svr-date-from">Submitted from</label>
							<button type="button" class="svr-date-clear" data-clear="svr-date-from" hidden>Clear</button>
						</div>
						<input type="text" class="sv-input svr-date" id="svr-date-from" placeholder="Any date" autocomplete="off">
					</div>

					<div class="svr-field">
						<div class="svr-label-row">
							<label class="svr-field-label" for="svr-date-to">Submitted to</label>
							<button type="button" class="svr-date-clear" data-clear="svr-date-to" hidden>Clear</button>
						</div>
						<input type="text" class="sv-input svr-date" id="svr-date-to" placeholder="Any date" autocomplete="off">
					</div>
<?php endif; ?>

<?php if (!$_svr_shared && $_svr_show_crosstab) : /* No cross-tab for a shared viewer: group g across two views isolates the remainder respondents in g (the server drops it too). */ ?>
					<div class="svr-field">
						<label class="svr-field-label" for="svr-crosstab">Cross-tab by</label>
						<select class="sv-select" id="svr-crosstab">
							<option value="">&mdash; None &mdash;</option>
<?php foreach ($_svr_crosstab as $_c) : ?>
							<option value="<?=(int) $_c['question_id']?>">Q<?=(int) $_c['num']?> &middot; <?=htmlspecialchars($_c['prompt'] !== '' ? $_c['prompt'] : ('Question ' . $_c['question_id']))?></option>
<?php endforeach; ?>
						</select>
					</div>
<?php endif; ?>

<?php if (!$_svr_shared) : ?>
					<div class="svr-field">
						<label class="svr-check"><input type="checkbox" id="svr-include-test"> <span>Include test responses</span></label>
					</div>
<?php endif; ?>

					<div class="svr-filter-actions">
						<button type="button" class="sv-btn sv-btn-primary" id="svr-apply"><i class="fas fa-check"></i> Apply</button>
						<button type="button" class="sv-btn" id="svr-reset">Reset</button>
					</div>

				</div>
			</form>
		</div>

		<!-- Charts + rows -->
		<div class="svr-main">
			<div class="sv-notice sv-notice-warn" id="svr-notice" role="status" hidden></div>
<?php if (!$_svr_shared) : ?>
			<!-- One-shot status after Clear Results (set by survey-results.js). -->
			<div class="sv-notice svr-cleared-notice" id="svr-cleared" role="status" hidden></div>
<?php endif; ?>
			<!-- Page-level minimum-cell notice (#4). -->
			<div class="sv-notice svr-suppressed-notice" id="svr-suppressed" role="status" hidden></div>
			<p class="svr-field-hint svr-rule-note" id="svr-rule-note" hidden></p>
			<!-- Summary-for-sharing caption (#5): what the charts below describe. -->
			<div class="svr-caption" id="svr-caption" hidden></div>
			<!--
				The live region is this short status line, NOT the card grid:
				every filter apply replaces the whole grid, and a live region
				around it would read every chart card end to end.
			-->
			<p class="sv-visually-hidden" id="svr-live" role="status" aria-live="polite"></p>
			<div class="svr-cards" id="svr-cards" aria-busy="false"></div>

<?php if (!$_svr_shared) : ?>
			<div class="rp-table-area svr-rows-area">
				<h2 class="svr-section-title"><i class="fas fa-table"></i> Responses</h2>
				<p class="svr-field-hint" id="svr-rows-hint">Row-level data for the filtered responses. Select a row to read that whole response. Persona links appear only for respondents who shared their name; Q1, Q2&hellip; match the chart cards above.</p>
				<div class="svr-table-scroll">
					<table class="display" id="svr-rows" style="width:100%"></table>
				</div>
			</div>
<?php endif; ?>
		</div>

	</div><!-- /.rp-body -->

<?php if (!$_svr_shared) : ?>
	<!-- One response, read as prompt / answer pairs (#36). Non-modal side sheet:
	     the table stays usable behind it; Escape or Close returns focus to the row. -->
	<aside class="svr-panel sv-scope" id="svr-panel" role="dialog" aria-modal="false" aria-labelledby="svr-panel-title" hidden>
		<div class="svr-panel-head">
			<h2 class="svr-panel-title" id="svr-panel-title" tabindex="-1">Response</h2>
			<div class="svr-panel-nav">
				<button type="button" class="sv-btn" id="svr-panel-prev" aria-label="Previous response"><i class="fas fa-chevron-left" aria-hidden="true"></i> Prev</button>
				<button type="button" class="sv-btn" id="svr-panel-next" aria-label="Next response">Next <i class="fas fa-chevron-right" aria-hidden="true"></i></button>
				<button type="button" class="sv-btn" id="svr-panel-close" aria-label="Close response"><i class="fas fa-xmark" aria-hidden="true"></i></button>
			</div>
		</div>
		<div class="svr-panel-body" id="svr-panel-body"></div>
	</aside>

	<!-- Clear Results confirm (shared .sv-overlay shell; never a native confirm).
	     The confirm button stays disabled through a 5-second countdown. -->
	<div class="sv-overlay" id="svr-clear-overlay">
		<div class="sv-modal sv-scope" role="dialog" aria-modal="true" aria-labelledby="svr-clear-heading" aria-describedby="svr-clear-body">
			<h4 class="sv-modal-title" id="svr-clear-heading">Clear Results</h4>
			<div class="sv-modal-body" id="svr-clear-body">
				<p id="svr-clear-count">Counting responses&hellip;</p>
				<p>This cannot be undone. Answers, completion records and in-progress drafts are deleted, and everyone can take the survey again.</p>
				<p>Attendance credits already posted stay in place; retaking does not earn a second credit.</p>
				<div class="sv-modal-error" id="svr-clear-error" role="alert"></div>
			</div>
			<div class="sv-modal-footer">
				<button type="button" class="sv-modal-btn sv-modal-cancel" id="svr-clear-cancel">Cancel</button>
				<button type="button" class="sv-modal-btn sv-modal-ok sv-modal-danger" id="svr-clear-ok" disabled aria-disabled="true">Clear results (5)</button>
			</div>
		</div>
	</div>
<?php endif; ?>
</div><!-- /.rp-root -->

<script>
window.SvConfig = {
	uir      : <?=json_encode(UIR, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
	surveyId : <?=$_svr_id?>,
	csrf     : <?=json_encode($SurveyCsrf ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
	questions: <?=json_encode($_svr_js_qs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
	access   : <?=json_encode($_svr_shared ? 'shared' : 'manage', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
	context  : <?=json_encode((string) ($ResultsContext ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
	lens     : <?=json_encode($_svr_shared ? (string) $_svr_lens : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
	lensOrg  : <?=json_encode($_svr_shared ? (string) ($ResultsAccess['org_name'] ?? '') : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<!--
	orkui.js inlines Highcharts 3.0.7 and defines window.Highcharts before this
	point. Highcharts 11 refuses to install over an existing global: it calls
	win.Highcharts.error(16, true), which 3.0.7 does not implement, so the whole
	11.x module aborts with an uncaught TypeError and the charts silently fall
	back to the 12-year-old build. Hide the old global while 11.4.8 loads, hand
	the fresh copy to survey-results.js as window.SvHighcharts, then put the
	original back so nothing else on the page changes behaviour.
-->
<script>
window.__svPrevHighcharts = window.Highcharts;
try { delete window.Highcharts; } catch (e) { window.Highcharts = undefined; }
</script>
<script src="https://code.highcharts.com/11.4.8/highcharts.js"></script>
<!--
	The accessibility module must load while 11.4.8 still owns window.Highcharts
	(it installs onto that global). Without it every chart build logs a console
	warning; with it the charts get keyboard navigation and screen-reader text.
-->
<script src="https://code.highcharts.com/11.4.8/modules/accessibility.js"></script>
<script>
window.SvHighcharts = window.Highcharts;
if (window.__svPrevHighcharts) { window.Highcharts = window.__svPrevHighcharts; }
try { delete window.__svPrevHighcharts; } catch (e) { window.__svPrevHighcharts = undefined; }
</script>
<script src="<?=HTTP_TEMPLATE?>default/script/survey-tip.js?v=<?=filemtime(__DIR__ . '/script/survey-tip.js')?>"></script>
<script src="<?=HTTP_TEMPLATE?>default/script/survey-results.js?v=<?=filemtime(__DIR__ . '/script/survey-results.js')?>"></script>

<?php endif; ?>
