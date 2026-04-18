<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $this->data['_wv'];
$tpl = $wv['template'];
$prefill = $wv['prefill'];
$token = htmlspecialchars($wv['token']);
$md = function($t) { return $t ? (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($t) : ''; };
require_once(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.inc.php');
?>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.css.inc') ?>
.wv-sign { max-width: 900px; margin: 20px auto; padding: 0 16px; background: #fff; }
.wv-sign h1, .wv-sign h2, .wv-sign h3, .wv-sign h4, .wv-sign h5, .wv-sign h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-sign h1 { font-size: 26px; margin-bottom: 4px; }
.wv-sign .wv-section { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin: 14px 0; background: #fff; }
.wv-sign .wv-header-md, .wv-sign .wv-body-md, .wv-sign .wv-footer-md, .wv-sign .wv-minor-md { line-height: 1.5; }
.wv-sign .wv-header-md h1, .wv-sign .wv-body-md h1, .wv-sign .wv-footer-md h1, .wv-sign .wv-minor-md h1,
.wv-sign .wv-header-md h2, .wv-sign .wv-body-md h2, .wv-sign .wv-footer-md h2, .wv-sign .wv-minor-md h2,
.wv-sign .wv-header-md h3, .wv-sign .wv-body-md h3, .wv-sign .wv-footer-md h3, .wv-sign .wv-minor-md h3,
.wv-sign .wv-header-md h4, .wv-sign .wv-body-md h4, .wv-sign .wv-footer-md h4, .wv-sign .wv-minor-md h4,
.wv-sign .wv-header-md h5, .wv-sign .wv-body-md h5, .wv-sign .wv-footer-md h5, .wv-sign .wv-minor-md h5,
.wv-sign .wv-header-md h6, .wv-sign .wv-body-md h6, .wv-sign .wv-footer-md h6, .wv-sign .wv-minor-md h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-sign .wv-playerhdr { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.wv-sign label { font-size: 12px; color: #555; font-weight: bold; display: block; }
.wv-sign input[type=text] { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
.wv-sign .wv-minor-toggle { padding: 10px; background: #fffbea; border: 1px solid #f4e3a0; border-radius: 4px; }
.wv-sign .wv-submit { padding: 12px 24px; font-weight: bold; background: #2b5; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
.wv-sign .wv-status-ok { color: #060; font-weight: bold; }
.wv-sign .wv-status-err { color: #a00; font-weight: bold; }
.wv-sign .wv-notice { padding: 16px; background: #fdd; border: 1px solid #c99; border-radius: 4px; }
</style>

<?php if (!$tpl): ?>
<div class="wv-sign">
	<h1>Digital Waiver</h1>
	<div class="wv-notice">This <?= htmlspecialchars($wv['scope']) ?> has not enabled digital waivers yet. Please check back later or contact a local officer.</div>
</div>
<?php return; endif; ?>

<div class="wv-sign">
	<div class="wv-section wv-header-md"><?= $md($tpl['HeaderMarkdown']) ?></div>

	<form id="wvSignForm">
		<input type="hidden" name="TemplateId" value="<?= (int)$tpl['TemplateId'] ?>">
		<input type="hidden" name="KingdomId"  value="<?= (int)$prefill['KingdomId'] ?>">
		<input type="hidden" name="ParkId"     value="<?= (int)$prefill['ParkId'] ?>">

		<div class="wv-section">
			<h2>Your Information</h2>
			<div class="wv-playerhdr">
				<div><label>First (legal) name</label><input type="text" name="MundaneFirst" required value="<?= htmlspecialchars($prefill['MundaneFirst']) ?>"></div>
				<div><label>Last (legal) name</label><input type="text" name="MundaneLast" required value="<?= htmlspecialchars($prefill['MundaneLast']) ?>"></div>
				<div><label>Persona name</label><input type="text" name="PersonaName" value="<?= htmlspecialchars($prefill['PersonaName']) ?>"></div>
				<div><label>Home park / kingdom</label><input type="text" value="(auto-captured from your profile)" disabled></div>
			</div>
		</div>

		<div class="wv-section wv-body-md"><?= $md($tpl['BodyMarkdown']) ?></div>

		<div class="wv-section wv-minor-toggle">
			<label><input type="checkbox" name="IsMinor" id="wvIsMinor" value="1"> I am signing for a minor (under 18) &mdash; show guardian/representative fields</label>
		</div>

		<div class="wv-section" id="wvMinorBlock" style="display:none;">
			<div class="wv-minor-md"><?= $md($tpl['MinorMarkdown']) ?></div>
			<div class="wv-playerhdr" style="margin-top:10px;">
				<div><label>Representative first name</label><input type="text" name="MinorRepFirst"></div>
				<div><label>Representative last name</label> <input type="text" name="MinorRepLast"></div>
				<div style="grid-column: span 2;"><label>Relationship to minor</label><input type="text" name="MinorRepRelationship" placeholder="e.g. mother, legal guardian"></div>
			</div>
		</div>

		<div class="wv-section">
			<h2>Signature</h2>
			<?php wv_render_signature_widget('wvSigMain', 'signature', 'Type your full legal name'); ?>
			<p style="font-size: 12px; color: #666; margin-top: 6px;">Signed date: <?= date('F j, Y') ?> (auto-recorded)</p>
		</div>

		<div class="wv-section wv-footer-md"><?= $md($tpl['FooterMarkdown']) ?></div>

		<button type="submit" class="wv-submit">Submit Signed Waiver</button>
		<span id="wvSubmitStatus"></span>
	</form>
</div>

<script>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.js.inc') ?>
</script>
<script>
(function(){
	const form = document.getElementById('wvSignForm');
	if (!form) return;
	const isMinor = document.getElementById('wvIsMinor');
	const minorBlock = document.getElementById('wvMinorBlock');
	isMinor.addEventListener('change', () => minorBlock.style.display = isMinor.checked ? '' : 'none');

	form.addEventListener('submit', async (e) => {
		e.preventDefault();
		const status = document.getElementById('wvSubmitStatus');
		const fd = new FormData(form);
		fd.set('SignatureType', form.querySelector('.wv-sig-type').value);
		fd.set('SignatureData', form.querySelector('.wv-sig-data').value);
		if (!fd.get('SignatureData')) { status.className = 'wv-status-err'; status.textContent = 'Please sign before submitting.'; return; }
		status.className = ''; status.textContent = 'Submitting…';
		try {
			const r = await fetch('<?= UIR ?>WaiverAjax/submitSignature', { method: 'POST', body: fd, credentials: 'same-origin' });
			const j = await r.json();
			if (j.status === 0) {
				window.location = '<?= UIR ?>Waiver/review/' + j.SignatureId;
			} else {
				status.className = 'wv-status-err';
				status.textContent = j.error || 'Submit failed';
			}
		} catch (err) {
			status.className = 'wv-status-err';
			status.textContent = 'Network error';
		}
	});
})();
</script>
