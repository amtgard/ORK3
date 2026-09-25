<?php
/**
 * Survey_take.tpl — the survey runner (spec §7 "Runner").
 *
 * Plain PHP, never Smarty. This page deliberately loads NO .rp-* report shell
 * and no chart library: it is the one survey surface a player opens on a phone,
 * so it links only survey.css plus the shared renderer, the shared tip engine
 * (script/survey-tip.js) and its own IIFE, and
 * two pinned cdnjs helpers: DOMPurify (builder-authored HTML is sanitised again
 * before innerHTML) and Flatpickr (date questions show a readable date; the same
 * build and SRI hash as Survey_build.tpl). Every script is `defer`: nothing here may block the first
 * paint of the frame. SortableJS (drag for ranking questions) is not loaded
 * here at all — survey-take.js pulls it in, same pinned version and SRI hash,
 * only when the definition actually contains a ranking question, and the page
 * works minus drag if it never arrives.
 *
 * Everything below #sv-stage is drawn by script/survey-take.js from the JSON
 * definition payload, embedded below as SvConfig.definition (the runner POSTs
 * SurveyAjax/definition only when it is absent); the server renders the frame.
 *
 * Controller: Controller_Survey::take() / ::s() -> SurveyId, IsPreview, CanManage,
 * ViewerId, Definition (definition payload or null), SurveyCsrf (sent as X-CSRF-Token on every SurveyAjax POST mutation).
 */

$_svStyle      = __DIR__ . '/style/survey.css';
$_svRenderJs   = __DIR__ . '/script/survey-render.js';
$_svTakeJs     = __DIR__ . '/script/survey-take.js';
$_svError      = isset($Error) ? trim((string) $Error) : '';
$_svSurveyId   = isset($SurveyId) ? (int) $SurveyId : 0;
$_svIsPreview  = !empty($IsPreview);
$_svCanManage  = !empty($CanManage);
$_svCsrf       = isset($SurveyCsrf) ? (string) $SurveyCsrf : '';
$_svViewerId   = isset($ViewerId) ? (int) $ViewerId : 0;
// The definition payload, embedded so the runner skips its first POST (null ->
// the runner fetches it). HEX flags keep it inert inside <script>; unescaped
// unicode/slashes keep it small (PHP still escapes U+2028/U+2029).
$_svDefinition = isset($Definition) && is_array($Definition)
	? json_encode($Definition, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
	: false;
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css"
	integrity="sha512-MQXduO8IQnJVq1qmySpN87QQkiR1bZHtorbJBD0tzy7/0U9+YIC93QWHeGTEoojMVHWWNkoCp8V6OzVSYrX0oQ=="
	crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/survey.css?v=<?= filemtime($_svStyle) ?>">

<?php if ($_svError !== '' || $_svSurveyId <= 0) : ?>
	<div class="sv-root sv-scope">
		<div class="sv-notice sv-notice-error">
			<?= htmlspecialchars($_svError !== '' ? $_svError : 'Survey not found.') ?>
		</div>
	</div>
<?php else : ?>
	<div class="sv-root sv-scope" id="sv-root">

		<?php if ($_svIsPreview) : ?>
			<div class="sv-preview-strip" role="note">
				<i class="fas fa-eye" aria-hidden="true"></i>
				<span class="sv-preview-text">Preview — nothing will be saved<span class="sv-preview-text-more"> unless you submit a test response</span>.</span>
				<a class="sv-preview-link" href="<?= UIR ?>Survey/build/<?= $_svSurveyId ?>" aria-label="Back to builder">
					<i class="fas fa-arrow-left" aria-hidden="true"></i>
					<span class="sv-preview-link-text">Back to builder</span>
					<span class="sv-preview-link-abbr" aria-hidden="true">Builder</span>
				</a>
			</div>
		<?php endif; ?>

		<header class="sv-header" id="sv-header">
			<h1 class="sv-title" id="sv-title">Survey</h1>
			<p class="sv-meta" id="sv-meta" hidden></p>
			<div class="sv-progress" id="sv-progress" hidden>
				<span class="sv-progress-bar" id="sv-progress-bar"></span>
			</div>
			<div class="sv-header-foot">
				<div class="sv-progress-text" id="sv-progress-text" hidden></div>
				<div class="sv-save-status" id="sv-save-status" role="status" aria-live="polite"></div>
			</div>
		</header>

		<main id="sv-stage" class="sv-stage">
			<div class="sv-notice" id="sv-loading">Loading the survey&hellip;</div>
		</main>

		<div id="sv-live" class="sv-visually-hidden" role="status" aria-live="assertive"></div>
		<div id="sv-live-polite" class="sv-visually-hidden" role="status" aria-live="polite"></div>
	</div>

	<script>
		window.SvConfig = {
			uir: <?= json_encode(UIR, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
			surveyId: <?= $_svSurveyId ?>,
			preview: <?= $_svIsPreview ? 'true' : 'false' ?>,
			canManage: <?= $_svCanManage ? 'true' : 'false' ?>,
			viewer: <?= $_svViewerId ?>,
			definition: <?= $_svDefinition !== false ? $_svDefinition : 'null' ?>,
			csrf: <?= json_encode($_svCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
		};
	</script>
	<script defer src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js"
		integrity="sha512-jB0TkTBeQC9ZSkBqDhdmfTv1qdfbWpGE72yJ/01Srq6hEzZIz2xkz1e57p9ai7IeHMwEG7HpzG6NdptChif5Pg=="
		crossorigin="anonymous" referrerpolicy="no-referrer"></script>
	<script defer src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"
		integrity="sha512-K/oyQtMXpxI4+K0W7H25UopjM8pzq0yrVdFdG21Fh5dBe91I40pDd9A4lzNlHPHBIP2cwZuoxaUSX0GJSObvGA=="
		crossorigin="anonymous" referrerpolicy="no-referrer"></script>
	<script defer src="<?= HTTP_TEMPLATE ?>default/script/survey-render.js?v=<?= filemtime($_svRenderJs) ?>"></script>
	<script defer src="<?= HTTP_TEMPLATE ?>default/script/survey-tip.js?v=<?= filemtime(__DIR__ . '/script/survey-tip.js') ?>"></script>
	<script defer src="<?= HTTP_TEMPLATE ?>default/script/survey-take.js?v=<?= filemtime($_svTakeJs) ?>"></script>
<?php endif; ?>
