<?php
/**
 * Survey_build.tpl — the survey builder (spec §7 "Builder").
 *
 * PLAIN PHP, never Smarty. Controller_Survey::build() sets:
 *   $SurveyId  int
 *   $Survey    the Survey::get() envelope: Status, Error, Survey, Pages,
 *              Questions (each with Options), Images (each with Url), Locked
 *   $Error     set instead when the survey is missing
 *
 * The envelope is normalised here into the same lowercase WIRE shape that
 * SurveyAjax/get returns, so survey-build.js sees one shape whether the data
 * came from this bootstrap or from a later re-fetch.
 *
 * The canvas IS the editor: there is no palette and no content inspector.
 * The page wears the standard Reports shell (spec §7 "Builder"):
 *
 *   .rp-root > .rp-header
 *            > .rp-body > .rp-sidebar  (survey settings, collapsible sections)
 *                       > .rp-main     (the canvas column of page cards)
 *                       > .svb-toc     (sticky outline; only above 1450px,
 *                                       where the canvas still gets its 860px)
 *
 * The sidebar's sections are painted by survey-build.js (renderSettings), so
 * every field autosaves through the same `update` path the drawer used to.
 */

if (!empty($Error)) {
	echo '<div class="rp-root"><div class="sv-notice sv-notice-error" style="margin:20px;">'
		. htmlspecialchars($Error) . '</div></div>';
	return;
}

$_svSurvey = $Survey['Survey'] ?? [];
$_svLocked = !empty($Survey['Locked']);
$_svStatus = (string) ($_svSurvey['status'] ?? 'draft');

$_svQuestions = [];
foreach ($Survey['Questions'] ?? [] as $_q) {
	$_q['options'] = $_q['Options'] ?? [];
	unset($_q['Options']);
	$_svQuestions[] = $_q;
}

$_svImages = [];
foreach ($Survey['Images'] ?? [] as $_img) {
	$_img['url'] = $_img['Url'] ?? '';
	unset($_img['Url']);
	$_svImages[] = $_img;
}

$_svBoot = [
	'survey'    => $_svSurvey,
	'pages'     => $Survey['Pages'] ?? [],
	'questions' => $_svQuestions,
	'images'    => $_svImages,
	'locked'    => $_svLocked,
];

$_svScopeType = (string) ($_svSurvey['scope_type'] ?? 'kingdom');
$_svScopeIcon = $_svScopeType === 'park' ? 'fa-campground' : ($_svScopeType === 'ork' ? 'fa-globe' : 'fa-crown');
$_svScopeWord = $_svScopeType === 'park' ? 'Park' : ($_svScopeType === 'ork' ? 'All of Amtgard' : 'Kingdom');
$_svShareLink = HTTP_UI_REMOTE . 'index.php?Route=Survey/s/' . rawurlencode((string) ($_svSurvey['slug'] ?? ''));
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(__DIR__ . '/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/survey.css?v=<?= filemtime(__DIR__ . '/style/survey.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/survey-build.css?v=<?= filemtime(__DIR__ . '/style/survey-build.css') ?>">
<!-- Flatpickr: the same build Survey_results.tpl uses. Every third-party file on
     this page comes from cdnjs at an exact version with an SRI hash, so the
     browser opens ONE extra connection and a swapped-out build cannot run. -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css"
	integrity="sha512-MQXduO8IQnJVq1qmySpN87QQkiR1bZHtorbJBD0tzy7/0U9+YIC93QWHeGTEoojMVHWWNkoCp8V6OzVSYrX0oQ=="
	crossorigin="anonymous" referrerpolicy="no-referrer">

<!-- data-svb-view: under 900px the Questions | Settings switch shows one of
     the canvas and the settings sidebar at a time (survey-build.js setView). -->
<div class="rp-root svb-root" id="svb-root" data-svb-view="questions">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-poll rp-header-icon" aria-hidden="true"></i>
				<h1 class="rp-header-title">
					<label class="sv-visually-hidden" for="svb-title">Survey title</label>
					<input type="text" id="svb-title" class="svb-title-input" maxlength="200" aria-required="true"
						placeholder="Untitled survey" value="<?= htmlspecialchars((string) ($_svSurvey['title'] ?? '')) ?>">
				</h1>
				<span class="svb-status-pill svb-status-<?= htmlspecialchars($_svStatus) ?>" id="svb-statuspill"><?= htmlspecialchars(ucfirst($_svStatus)) ?></span>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip-label">Scope:</span>
				<span class="rp-scope-chip">
					<i class="fas <?= $_svScopeIcon ?>" aria-hidden="true"></i>
					<span id="svb-scopename"><?= htmlspecialchars($_svScopeWord) ?></span>
				</span>
			</div>
		</div>
		<div class="rp-header-actions">
			<span class="svb-savestate svb-savestate-saved" id="svb-savestate" role="status" aria-live="polite">Saved</span>
			<a class="rp-btn-ghost" id="svb-preview" href="<?= UIR ?>Survey/take/<?= (int) $SurveyId ?>/preview" target="_blank" rel="noopener" data-tip="Take the survey without saving anything"><i class="fas fa-eye" aria-hidden="true"></i> Preview</a>
			<button type="button" class="rp-btn-ghost" id="svb-openclose" data-target="open"><i class="fas fa-paper-plane" aria-hidden="true"></i> Open survey</button>
			<a class="rp-btn-ghost svb-wide-only" href="<?= UIR ?>Survey/results/<?= (int) $SurveyId ?>"><i class="fas fa-chart-column" aria-hidden="true"></i> Results</a>
			<button type="button" class="rp-btn-ghost svb-wide-only" id="svb-copylink" data-link="<?= htmlspecialchars($_svShareLink) ?>" data-tip="Copy the share link for this survey"><i class="fas fa-link" aria-hidden="true"></i> Copy link</button>
			<button type="button" class="rp-btn-ghost svb-wide-only" id="svb-help"><i class="fas fa-circle-question" aria-hidden="true"></i> Help</button>
			<!-- Under 900px the three buttons above fold into this one menu. -->
			<div class="svb-overflow">
				<button type="button" class="rp-btn-ghost svb-overflow-btn" id="svb-overflow-btn" aria-expanded="false" aria-controls="svb-overflow-menu"><i class="fas fa-ellipsis" aria-hidden="true"></i> More</button>
				<div class="svb-overflow-menu" id="svb-overflow-menu" hidden>
					<a class="svb-overflow-item" href="<?= UIR ?>Survey/results/<?= (int) $SurveyId ?>"><i class="fas fa-chart-column" aria-hidden="true"></i> Results</a>
					<button type="button" class="svb-overflow-item" data-overflow="copy"><i class="fas fa-link" aria-hidden="true"></i> Copy link</button>
					<button type="button" class="svb-overflow-item" data-overflow="help"><i class="fas fa-circle-question" aria-hidden="true"></i> Help</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Under 900px: one of the canvas and the settings at a time. -->
	<div class="svb-viewseg" role="group" aria-label="Show">
		<button type="button" class="svb-viewseg-btn" data-view="questions" aria-pressed="true" aria-controls="svb-canvas"><i class="fas fa-list-check" aria-hidden="true"></i> Questions</button>
		<button type="button" class="svb-viewseg-btn" data-view="settings" aria-pressed="false" aria-controls="svb-settings"><i class="fas fa-sliders" aria-hidden="true"></i> Settings</button>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon" aria-hidden="true"></i>
		<span>Click any card to edit it right where it sits. Add Element starts a new question; its Type menu turns it into anything. Every change saves itself.</span>
	</div>

	<div class="svb-lockbar" id="svb-lockbar"<?= $_svLocked ? '' : ' hidden' ?>>
		<i class="fas fa-lock" aria-hidden="true"></i>
		<span>This survey has been opened, so its questions, options and pages are locked. Wording, help text, option labels and every survey setting stay editable.</span>
	</div>

	<!-- Sticky, so it stays in view however far down the canvas the author is. -->
	<div class="svb-confirm sv-scope" id="svb-confirm" role="alertdialog" aria-labelledby="svb-confirm-text" hidden>
		<span class="svb-confirm-text" id="svb-confirm-text"></span>
		<!-- Cancel first in the DOM: when the strip wraps on a phone the safe
		     choice leads, and paint order matches tab order. -->
		<button type="button" class="sv-btn svb-confirm-cancel" id="svb-confirm-no">Cancel</button>
		<button type="button" class="sv-btn sv-btn-primary" id="svb-confirm-yes">Confirm</button>
	</div>

	<div class="sv-notice svb-notice-slot" id="svb-notice" role="status" aria-live="polite" hidden></div>

	<div class="rp-body sv-scope">

		<!-- Survey settings. Sections are painted by survey-build.js. -->
		<aside class="rp-sidebar svb-settings" id="svb-settings" aria-label="Survey settings"></aside>

		<main class="rp-main svb-canvas" id="svb-canvas" aria-label="Survey canvas"></main>

		<!-- Outline: a sticky table of contents for the canvas, painted by
		     survey-build.js (renderToc). It only appears once the row is wide
		     enough that the canvas still gets its full 860px. -->
		<nav class="svb-toc" id="svb-toc" aria-label="Survey outline"></nav>

	</div>

	<input type="file" id="svb-file" accept="image/jpeg,image/png" hidden>

	<!-- Help -->
	<div class="svb-modal" id="svb-modal" role="dialog" aria-modal="true" aria-labelledby="svb-modal-title" hidden>
		<button type="button" class="svb-modal-backdrop" tabindex="-1" aria-label="Close"></button>
		<div class="svb-modal-panel sv-scope">
			<div class="svb-modal-head">
				<h2 class="svb-modal-title" id="svb-modal-title">Help</h2>
				<button type="button" class="svb-icon-btn svb-modal-close" aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button>
			</div>
			<div class="svb-modal-body"></div>
		</div>
	</div>

</div>

<script>
window.SvConfig = {
	uir:      <?= json_encode(UIR, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
	csrf:     <?= json_encode($SurveyCsrf ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
	surveyId: <?= (int) $SurveyId ?>,
	survey:   <?= json_encode($_svBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<!-- No `defer` on these four: survey-build.js is a plain script that renders the
     settings (flatpickr) and the markdown previews (marked + DOMPurify) during
     its own boot, so it would run before anything deferred. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"
	integrity="sha512-TelkP3PCMJv+viMWynjKcvLsQzx6dJHvIGhfqzFtZKgAjKM1YPqcwzzDEoTc/BHjf43PcPzTQOjuTr4YdE8lNQ=="
	crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"
	integrity="sha512-K/oyQtMXpxI4+K0W7H25UopjM8pzq0yrVdFdG21Fh5dBe91I40pDd9A4lzNlHPHBIP2cwZuoxaUSX0GJSObvGA=="
	crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.2/marked.min.js"
	integrity="sha512-xeUh+KxNyTufZOje++oQHstlMQ8/rpyzPuM+gjMFYK3z5ILJGE7l2NvYL+XfliKURMpBIKKp1XoPN/qswlSMFA=="
	crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js"
	integrity="sha512-jB0TkTBeQC9ZSkBqDhdmfTv1qdfbWpGE72yJ/01Srq6hEzZIz2xkz1e57p9ai7IeHMwEG7HpzG6NdptChif5Pg=="
	crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/survey-render.js?v=<?= filemtime(__DIR__ . '/script/survey-render.js') ?>"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/survey-tip.js?v=<?= filemtime(__DIR__ . '/script/survey-tip.js') ?>"></script>
<script>window.SvCreditConfig = { uir: <?=json_encode(UIR, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>, csrf: <?=json_encode((string)($SurveyCsrf ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?> };</script>
<?php include __DIR__ . '/_survey_credit_modal.tpl'; ?>
<script src="<?= HTTP_TEMPLATE ?>default/script/survey-build.js?v=<?= filemtime(__DIR__ . '/script/survey-build.js') ?>"></script>
