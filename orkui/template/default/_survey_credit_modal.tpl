<?php
/**
 * Attendance credit modal (sharing-and-credits spec §3.6), shared by the
 * survey list and the builder. Include once per page after survey.css, and
 * emit window.SvCreditConfig = {uir, csrf} before it. Open it with
 * SvCredit.open({surveyId, grantor, title, onChange}).
 */
?>
<div class="sv-overlay" id="sv-credit-overlay">
	<div class="sv-modal sv-scope sv-credit-modal" role="dialog" aria-modal="true" aria-labelledby="sv-credit-heading">
		<h4 class="sv-modal-title" id="sv-credit-heading">Attendance credit</h4>
		<div class="sv-modal-body" id="sv-credit-body" aria-live="polite">Loading&hellip;</div>
		<div class="sv-modal-footer">
			<button type="button" class="sv-modal-btn sv-modal-cancel" id="sv-credit-close">Close</button>
			<button type="button" class="sv-modal-btn sv-modal-ok" id="sv-credit-enable" hidden disabled>Turn on credits</button>
		</div>
	</div>
</div>
<script src="<?= HTTP_TEMPLATE ?>default/script/survey-credit.js?v=<?= filemtime(__DIR__ . '/script/survey-credit.js') ?>"></script>
