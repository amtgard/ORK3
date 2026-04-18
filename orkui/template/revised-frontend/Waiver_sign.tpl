<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $_wv;
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
				<?php if (!empty($tpl['RequiresPreferredName'])): ?>
				<div><label>Preferred name</label><input type="text" name="PreferredName" required></div>
				<?php endif; ?>
				<?php if (!empty($tpl['RequiresGender'])): ?>
				<div><label>Gender</label><input type="text" name="Gender" required></div>
				<?php endif; ?>
				<?php if (!empty($tpl['RequiresDob'])): ?>
				<div><label>Date of birth</label><input type="date" name="Dob" required value="<?= htmlspecialchars($prefill['Dob'] ?? '') ?>"></div>
				<?php endif; ?>
				<?php if (!empty($tpl['RequiresAddress'])): ?>
				<div style="grid-column: span 2;"><label>Address</label><input type="text" name="Address" required value="<?= htmlspecialchars($prefill['Address'] ?? '') ?>"></div>
				<?php endif; ?>
				<?php if (!empty($tpl['RequiresPhone'])): ?>
				<div><label>Phone</label><input type="text" name="Phone" required value="<?= htmlspecialchars($prefill['Phone'] ?? '') ?>"></div>
				<?php endif; ?>
				<?php if (!empty($tpl['RequiresEmail'])): ?>
				<div><label>Email</label><input type="email" name="Email" required value="<?= htmlspecialchars($prefill['Email'] ?? '') ?>"></div>
				<?php endif; ?>
				<div><label>Home park / kingdom</label><input type="text" value="(auto-captured from your profile)" disabled></div>
			</div>
		</div>

		<?php if (!empty($tpl['RequiresEmergencyContact'])): ?>
		<div class="wv-section">
			<h2>Emergency Contact</h2>
			<div class="wv-playerhdr">
				<div><label>Contact name</label><input type="text" name="EmergencyContactName" required></div>
				<div><label>Relationship</label><input type="text" name="EmergencyContactRelationship" required placeholder="e.g. spouse, parent"></div>
				<div><label>Phone</label><input type="text" name="EmergencyContactPhone" required></div>
			</div>
		</div>
		<?php endif; ?>

		<div class="wv-section wv-body-md"><?= $md($tpl['BodyMarkdown']) ?></div>

		<?php
		$customFields = [];
		try { $customFields = json_decode($tpl['CustomFieldsJson'] ?? '[]', true) ?: []; } catch (Throwable $e) { $customFields = []; }
		if (is_array($customFields) && count($customFields) > 0):
		?>
		<div class="wv-section wv-custom-fields">
			<h2>Acknowledgements &amp; Additional Information</h2>
			<?php foreach ($customFields as $f):
				$fid   = preg_replace('/[^a-z0-9_]/', '', (string)($f['id'] ?? ''));
				if ($fid === '') continue;
				$type  = (string)($f['type'] ?? 'text');
				$label = (string)($f['label'] ?? '');
				$req   = !empty($f['required']);
				$name  = 'cf_' . $fid;
				$opts  = is_array($f['options'] ?? null) ? $f['options'] : [];
			?>
			<div class="wv-cf-row" data-cf-id="<?= htmlspecialchars($fid) ?>" data-cf-type="<?= htmlspecialchars($type) ?>" data-cf-req="<?= $req ? '1' : '0' ?>">
				<?php if ($type === 'checkbox'): ?>
					<label><input type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1" <?= $req ? 'data-wv-required="1"' : '' ?>> <?= htmlspecialchars($label) ?></label>
				<?php elseif ($type === 'initial'): ?>
					<label><?= htmlspecialchars($label) ?></label>
					<input type="text" name="<?= htmlspecialchars($name) ?>" maxlength="3" style="width:60px; text-transform:uppercase;" <?= $req ? 'data-wv-required="1"' : '' ?>>
				<?php elseif ($type === 'radio'): ?>
					<label><?= htmlspecialchars($label) ?></label>
					<div class="wv-cf-radio">
						<?php foreach ($opts as $i => $o): ?>
							<label><input type="radio" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars((string)$o) ?>" <?= $req ? 'data-wv-required="1"' : '' ?>> <?= htmlspecialchars((string)$o) ?></label>
						<?php endforeach; ?>
					</div>
				<?php elseif ($type === 'select'): ?>
					<label><?= htmlspecialchars($label) ?></label>
					<select name="<?= htmlspecialchars($name) ?>" <?= $req ? 'data-wv-required="1"' : '' ?>>
						<option value="">&mdash; select &mdash;</option>
						<?php foreach ($opts as $o): ?>
							<option value="<?= htmlspecialchars((string)$o) ?>"><?= htmlspecialchars((string)$o) ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ($type === 'textarea'): ?>
					<label><?= htmlspecialchars($label) ?></label>
					<textarea name="<?= htmlspecialchars($name) ?>" rows="3" <?= $req ? 'data-wv-required="1"' : '' ?>></textarea>
				<?php elseif ($type === 'date'): ?>
					<label><?= htmlspecialchars($label) ?></label>
					<input type="date" name="<?= htmlspecialchars($name) ?>" <?= $req ? 'data-wv-required="1"' : '' ?>>
				<?php else: ?>
					<label><?= htmlspecialchars($label) ?></label>
					<input type="text" name="<?= htmlspecialchars($name) ?>" <?= $req ? 'data-wv-required="1"' : '' ?>>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="wv-section wv-minor-toggle">
			<label><input type="checkbox" name="IsMinor" id="wvIsMinor" value="1"> I am signing for a minor (under 18) &mdash; show guardian/representative fields</label>
		</div>

		<div class="wv-section" id="wvMinorBlock" style="display:none;">
			<div class="wv-minor-md"><?= $md($tpl['MinorMarkdown']) ?></div>
			<h3 style="margin-top: 10px;">Guardian / Representative</h3>
			<div class="wv-playerhdr">
				<div><label>Representative first name</label><input type="text" name="MinorRepFirst"></div>
				<div><label>Representative last name</label> <input type="text" name="MinorRepLast"></div>
				<div style="grid-column: span 2;"><label>Relationship to minor</label><input type="text" name="MinorRepRelationship" placeholder="e.g. mother, legal guardian"></div>
			</div>
			<?php $maxMinors = max(1, (int)($tpl['MaxMinors'] ?? 1)); ?>
			<h3 style="margin-top: 14px;">Minors Covered</h3>
			<p class="wv-hint" style="font-size:12px; color:#666;">Enter the minor(s) this waiver covers. You may list up to <?= $maxMinors ?>.</p>
			<div id="wvMinorsList" data-max="<?= $maxMinors ?>"></div>
			<?php if ($maxMinors > 1): ?>
			<button type="button" id="wvMinorsAdd">+ Add minor</button>
			<?php endif; ?>
		</div>

		<?php if (!empty($tpl['RequiresWitness'])): ?>
		<div class="wv-section">
			<h2>Witness</h2>
			<div class="wv-playerhdr">
				<div style="grid-column: span 2;"><label>Witness printed name</label><input type="text" name="WitnessPrintedName" required></div>
			</div>
			<?php wv_render_signature_widget('wvSigWitness', 'witness', 'Type witness full legal name'); ?>
		</div>
		<?php endif; ?>

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

	// Minors repeater
	const minorsList = document.getElementById('wvMinorsList');
	const maxMinors  = minorsList ? parseInt(minorsList.dataset.max || '1', 10) : 1;
	function makeMinorRow(idx) {
		const d = document.createElement('div');
		d.className = 'wv-minor-row wv-playerhdr';
		d.style.cssText = 'border:1px solid #eee; padding:8px; border-radius:4px; margin-bottom:8px;';
		d.innerHTML =
			'<div><label>Legal first</label><input type="text" class="wv-minor-field" data-k="LegalFirst"></div>' +
			'<div><label>Legal last</label><input type="text"  class="wv-minor-field" data-k="LegalLast"></div>' +
			'<div><label>Preferred name</label><input type="text" class="wv-minor-field" data-k="PreferredName"></div>' +
			'<div><label>Persona name</label><input type="text"   class="wv-minor-field" data-k="PersonaName"></div>' +
			'<div><label>Date of birth</label><input type="date"  class="wv-minor-field" data-k="Dob"></div>' +
			(idx > 0 ? '<div style="align-self:center;"><button type="button" class="wv-minor-del">Remove</button></div>' : '');
		const del = d.querySelector('.wv-minor-del');
		if (del) del.addEventListener('click', () => d.remove());
		return d;
	}
	if (minorsList) minorsList.appendChild(makeMinorRow(0));
	const addBtn = document.getElementById('wvMinorsAdd');
	if (addBtn) addBtn.addEventListener('click', () => {
		const count = minorsList.querySelectorAll('.wv-minor-row').length;
		if (count >= maxMinors) return;
		minorsList.appendChild(makeMinorRow(count));
	});

	function collectMinors() {
		if (!minorsList) return [];
		return [...minorsList.querySelectorAll('.wv-minor-row')].map(row => {
			const o = {};
			row.querySelectorAll('.wv-minor-field').forEach(i => { o[i.dataset.k] = i.value; });
			return o;
		}).filter(o => (o.LegalFirst || o.LegalLast || o.PersonaName));
	}

	function collectCustom() {
		const out = {};
		form.querySelectorAll('.wv-cf-row').forEach(row => {
			const id = row.dataset.cfId;
			const type = row.dataset.cfType;
			if (type === 'checkbox') {
				const el = row.querySelector('input[type=checkbox]');
				out[id] = !!(el && el.checked);
			} else if (type === 'radio') {
				const el = row.querySelector('input[type=radio]:checked');
				out[id] = el ? el.value : '';
			} else {
				const el = row.querySelector('input, select, textarea');
				out[id] = el ? el.value : '';
			}
		});
		return out;
	}

	function firstMissingRequired() {
		const els = form.querySelectorAll('[data-wv-required="1"]');
		for (const el of els) {
			if (el.type === 'checkbox' && !el.checked) return el;
			if (el.type === 'radio') {
				const grp = form.querySelectorAll('input[type=radio][name="' + el.name + '"]');
				if (![...grp].some(g => g.checked)) return el;
				continue;
			}
			if (el.tagName === 'SELECT' || el.type === 'text' || el.type === 'textarea' || el.type === 'date' || el.type === 'email') {
				if (!(el.value || '').trim()) return el;
			}
		}
		return null;
	}

	form.addEventListener('submit', async (e) => {
		e.preventDefault();
		const status = document.getElementById('wvSubmitStatus');
		const missing = firstMissingRequired();
		if (missing) {
			status.className = 'wv-status-err';
			const label = (missing.closest('label') && missing.closest('label').textContent.trim()) || missing.name || 'a required field';
			status.textContent = 'Please complete: ' + label;
			missing.focus();
			return;
		}

		const fd = new FormData(form);
		const mainSig = form.querySelector('#wvSigMain');
		fd.set('SignatureType', mainSig.querySelector('.wv-sig-type').value);
		fd.set('SignatureData', mainSig.querySelector('.wv-sig-data').value);
		if (!fd.get('SignatureData')) { status.className = 'wv-status-err'; status.textContent = 'Please sign before submitting.'; return; }

		const witSig = form.querySelector('#wvSigWitness');
		if (witSig) {
			fd.set('WitnessSignatureType', witSig.querySelector('.wv-sig-type').value);
			fd.set('WitnessSignatureData', witSig.querySelector('.wv-sig-data').value);
		}

		const minors = isMinor.checked ? collectMinors() : [];
		fd.set('Minors', JSON.stringify(minors));
		minors.forEach((m, i) => {
			Object.keys(m).forEach(k => fd.append('Minors[' + i + '][' + k + ']', m[k] || ''));
		});
		fd.set('CustomResponsesJson', JSON.stringify(collectCustom()));

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
