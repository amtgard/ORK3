<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $_wv;
$sig = $wv['signature'];
$tpl = $sig['Template'];
$md = function($t) { return $t ? (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($t) : ''; };
$token = htmlspecialchars($wv['token']);
$isOfficer = $wv['is_officer'];
$isSigner  = $wv['is_signer'];
$canVerify = $isOfficer && in_array($sig['VerificationStatus'], ['pending']);
require_once(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.inc.php');
$of = $wv['officer_prefill'];
?>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.css.inc') ?>
.wv-review { max-width: 900px; margin: 20px auto; padding: 0 16px; background: #fff; }
.wv-review h1, .wv-review h2, .wv-review h3, .wv-review h4, .wv-review h5, .wv-review h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-review h1 { font-size: 26px; }
.wv-review .wv-section { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin: 14px 0; }
.wv-review .wv-playerhdr { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.wv-review .wv-fact { font-size: 13px; }
.wv-review .wv-fact strong { color: #333; }
.wv-review .wv-sig-rendered { min-height: 180px; border: 1px dashed #999; border-radius: 4px; padding: 10px; background: #fafafa; }
.wv-review .wv-verify-form input[type=text], .wv-review .wv-verify-form textarea { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
.wv-review .wv-verify-form label { display: block; font-size: 12px; color: #555; font-weight: bold; margin-top: 8px; }
.wv-review .wv-actions { display: flex; gap: 10px; margin-top: 12px; }
.wv-review .wv-approve { background: #2b5; color: #fff; padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
.wv-review .wv-reject  { background: #c33; color: #fff; padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
.wv-review .wv-verified-box { padding: 12px; background: #eef9ee; border: 1px solid #cdeac0; border-radius: 4px; font-size: 14px; }
.wv-review .wv-rejected-box { padding: 12px; background: #fdeeee; border: 1px solid #eac0c0; border-radius: 4px; font-size: 14px; }
.wv-review .wv-dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; }
.wv-review .wv-dl dt { font-weight: bold; color: #555; }
.wv-review .wv-dl dd { margin: 0; }
.wv-review .wv-minors-tbl { width: 100%; border-collapse: collapse; }
.wv-review .wv-minors-tbl th, .wv-review .wv-minors-tbl td { border: 1px solid #ddd; padding: 4px 8px; text-align: left; }
.wv-review .wv-minors-tbl thead { background: #f4f4f4; }
@media print {
	.wv-verify-form, .wv-actions, .wv-minor-toggle, body > header, body > nav, body > footer { display: none !important; }
	.wv-review { max-width: 100%; margin: 0; }
}
</style>
<div class="wv-review">
	<h1>Digital Waiver &mdash; Signed Record #<?= (int)$sig['SignatureId'] ?></h1>
	<p><a href="<?= UIR ?>Waiver/printable/<?= (int)$sig['SignatureId'] ?>" target="_blank">Open printable version &rarr;</a></p>

	<div class="wv-section"><?= $md($tpl['HeaderMarkdown'] ?? '') ?></div>

	<div class="wv-section">
		<h2>Signer</h2>
		<div class="wv-playerhdr">
			<div class="wv-fact"><strong>Legal Name:</strong> <?= htmlspecialchars($sig['MundaneFirst'] . ' ' . $sig['MundaneLast']) ?></div>
			<div class="wv-fact"><strong>Persona:</strong> <?= htmlspecialchars($sig['PersonaName']) ?></div>
			<div class="wv-fact"><strong>Park ID:</strong> <?= (int)$sig['ParkId'] ?></div>
			<div class="wv-fact"><strong>Kingdom ID:</strong> <?= (int)$sig['KingdomId'] ?></div>
			<div class="wv-fact"><strong>Signed:</strong> <?= htmlspecialchars($sig['SignedAt']) ?></div>
			<div class="wv-fact"><strong>Template:</strong> v<?= (int)$tpl['Version'] ?> (<?= htmlspecialchars($tpl['Scope']) ?>)</div>
		</div>
	</div>

	<?php $_sig = $sig; $_tpl = $tpl; ?>

	<?php if (!empty($_tpl['RequiresPreferredName']) || !empty($_tpl['RequiresDob']) || !empty($_tpl['RequiresGender']) || !empty($_tpl['RequiresAddress']) || !empty($_tpl['RequiresPhone']) || !empty($_tpl['RequiresEmail'])): ?>
	<div class="wv-section">
		<h2>Signer Demographics</h2>
		<dl class="wv-dl">
			<?php if (!empty($_tpl['RequiresPreferredName'])): ?><dt>Preferred name</dt><dd><?= htmlspecialchars($_sig['PreferredName'] ?? '') ?></dd><?php endif; ?>
			<?php if (!empty($_tpl['RequiresDob'])):           ?><dt>Date of birth</dt><dd><?= htmlspecialchars($_sig['Dob'] ?? '') ?></dd><?php endif; ?>
			<?php if (!empty($_tpl['RequiresGender'])):        ?><dt>Gender</dt><dd><?= htmlspecialchars($_sig['Gender'] ?? '') ?></dd><?php endif; ?>
			<?php if (!empty($_tpl['RequiresAddress'])):       ?><dt>Address</dt><dd><?= htmlspecialchars($_sig['Address'] ?? '') ?></dd><?php endif; ?>
			<?php if (!empty($_tpl['RequiresPhone'])):         ?><dt>Phone</dt><dd><?= htmlspecialchars($_sig['Phone'] ?? '') ?></dd><?php endif; ?>
			<?php if (!empty($_tpl['RequiresEmail'])):         ?><dt>Email</dt><dd><?= htmlspecialchars($_sig['Email'] ?? '') ?></dd><?php endif; ?>
		</dl>
	</div>
	<?php endif; ?>

	<?php if (!empty($_tpl['RequiresEmergencyContact'])): ?>
	<div class="wv-section">
		<h2>Emergency Contact</h2>
		<dl class="wv-dl">
			<dt>Name</dt><dd><?= htmlspecialchars($_sig['EmergencyContactName'] ?? '') ?></dd>
			<dt>Relationship</dt><dd><?= htmlspecialchars($_sig['EmergencyContactRelationship'] ?? '') ?></dd>
			<dt>Phone</dt><dd><?= htmlspecialchars($_sig['EmergencyContactPhone'] ?? '') ?></dd>
		</dl>
	</div>
	<?php endif; ?>

	<?php
	$cfTpl = json_decode($_tpl['CustomFieldsJson'] ?? '[]', true) ?: [];
	$cfResp = json_decode($_sig['CustomResponsesJson'] ?? '{}', true) ?: [];
	if (count($cfTpl) > 0): ?>
	<div class="wv-section">
		<h2>Acknowledgements &amp; Additional Information</h2>
		<dl class="wv-dl">
			<?php foreach ($cfTpl as $f):
				$id = (string)($f['id'] ?? ''); $type = (string)($f['type'] ?? ''); $val = $cfResp[$id] ?? '';
				if ($type === 'checkbox') $val = !empty($val) ? 'Yes' : 'No';
				if (is_array($val)) $val = implode(', ', $val);
			?>
			<dt><?= htmlspecialchars((string)($f['label'] ?? $id)) ?></dt>
			<dd><?= htmlspecialchars((string)$val) ?></dd>
			<?php endforeach; ?>
		</dl>
	</div>
	<?php endif; ?>

	<?php if (!empty($_sig['IsMinor']) && !empty($_sig['Minors'])): ?>
	<div class="wv-section">
		<h2>Minors Covered</h2>
		<table class="wv-minors-tbl">
			<thead><tr><th>Legal first</th><th>Legal last</th><th>Preferred</th><th>Persona</th><th>DOB</th></tr></thead>
			<tbody>
			<?php foreach ($_sig['Minors'] as $m): ?>
				<tr>
					<td><?= htmlspecialchars($m['LegalFirst'] ?? '') ?></td>
					<td><?= htmlspecialchars($m['LegalLast'] ?? '') ?></td>
					<td><?= htmlspecialchars($m['PreferredName'] ?? '') ?></td>
					<td><?= htmlspecialchars($m['PersonaName'] ?? '') ?></td>
					<td><?= htmlspecialchars($m['Dob'] ?? '') ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<?php if (!empty($_tpl['RequiresWitness'])): ?>
	<div class="wv-section">
		<h2>Witness</h2>
		<dl class="wv-dl">
			<dt>Name</dt><dd><?= htmlspecialchars($_sig['WitnessPrintedName'] ?? '') ?></dd>
			<dt>Signature</dt>
			<dd>
				<?php if (($_sig['WitnessSignatureType'] ?? '') === 'typed'): ?>
					<span style="font-family:'Homemade Apple', cursive; font-size: 22px;"><?= htmlspecialchars($_sig['WitnessSignatureData'] ?? '') ?></span>
				<?php elseif (($_sig['WitnessSignatureType'] ?? '') === 'drawn'): ?>
					<em>(drawn signature &mdash; see print view for canvas render)</em>
				<?php endif; ?>
			</dd>
		</dl>
	</div>
	<?php endif; ?>

	<div class="wv-section"><?= $md($tpl['BodyMarkdown'] ?? '') ?></div>

	<?php if ($sig['IsMinor']): ?>
	<div class="wv-section">
		<h2>Minor Representative</h2>
		<div><?= $md($tpl['MinorMarkdown'] ?? '') ?></div>
		<div class="wv-playerhdr" style="margin-top:10px;">
			<div class="wv-fact"><strong>Rep Name:</strong> <?= htmlspecialchars($sig['MinorRepFirst'] . ' ' . $sig['MinorRepLast']) ?></div>
			<div class="wv-fact"><strong>Relationship:</strong> <?= htmlspecialchars($sig['MinorRepRelationship']) ?></div>
		</div>
	</div>
	<?php endif; ?>

	<div class="wv-section">
		<h2>Player Signature</h2>
		<div class="wv-sig-rendered" id="wvPlayerSig"></div>
	</div>

	<div class="wv-section"><?= $md($tpl['FooterMarkdown'] ?? '') ?></div>

	<?php if ($canVerify): ?>
	<div class="wv-section wv-verify-form">
		<h2>Officer Verification</h2>
		<form id="wvVerifyForm">
			<input type="hidden" name="SignatureId" value="<?= (int)$sig['SignatureId'] ?>">
			<label>Printed Name</label>
			<input type="text" name="PrintedName" value="<?= htmlspecialchars($of['PrintedName']) ?>" required>
			<label>Persona Name</label>
			<input type="text" name="PersonaName" value="<?= htmlspecialchars($of['PersonaName']) ?>">
			<label>Office Title</label>
			<input type="text" name="OfficeTitle" placeholder="e.g. Prime Minister, Sheriff" required>
			<label>Date of Review</label>
			<input type="text" value="<?= date('F j, Y') ?>" disabled>
			<label>Officer Signature</label>
			<?php wv_render_signature_widget('wvVerifierSig', 'verifier', 'Type your full legal name'); ?>
			<label>Notes (required if rejecting)</label>
			<textarea name="Notes" rows="2"></textarea>
			<div class="wv-section wv-officer-intake">
				<h3>ID Check &amp; Intake (optional)</h3>
				<div class="wv-playerhdr">
					<div>
						<label>ID type</label>
						<select name="IdType">
							<option value="">&mdash;</option>
							<option>Driver License</option>
							<option>Passport</option>
							<option>State ID</option>
							<option>Military ID</option>
							<option>Other</option>
						</select>
					</div>
					<div>
						<label>ID number (last 4 stored)</label>
						<input type="text" name="IdNumber" inputmode="numeric" maxlength="32">
					</div>
					<div>
						<label>Age bracket</label>
						<select name="AgeBracket">
							<option value="">&mdash;</option>
							<option value="18+">18+</option>
							<option value="14+">14+</option>
							<option value="under14">Under 14</option>
						</select>
					</div>
					<div>
						<label><input type="checkbox" name="ScannedPaper" value="1"> Paper copy scanned &amp; filed</label>
					</div>
				</div>
			</div>
			<div class="wv-actions">
				<button type="button" class="wv-approve" data-action="verified">Verify</button>
				<button type="button" class="wv-reject"  data-action="rejected">Reject</button>
				<span id="wvVerifyStatus"></span>
			</div>
		</form>
	</div>
	<?php elseif ($sig['VerificationStatus'] === 'verified'): ?>
	<div class="wv-section wv-verified-box">
		<strong>&#10003; Verified</strong> by <?= htmlspecialchars($sig['VerifierPrintedName']) ?>
		(<?= htmlspecialchars($sig['VerifierOfficeTitle']) ?>) on <?= htmlspecialchars($sig['VerifiedAt']) ?>
	</div>
	<?php elseif (in_array($sig['VerificationStatus'], ['rejected','superseded'])): ?>
	<div class="wv-section wv-rejected-box">
		<strong>Status: <?= htmlspecialchars($sig['VerificationStatus']) ?></strong>
		<?php if ($sig['VerifierNotes']): ?> &mdash; notes: <?= htmlspecialchars($sig['VerifierNotes']) ?><?php endif; ?>
	</div>
	<?php endif; ?>
</div>

<script>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.js.inc') ?>
</script>
<script>
window.WvReviewConfig = {
	signatureType: <?= json_encode($sig['SignatureType']) ?>,
	signatureData: <?= json_encode($sig['SignatureData']) ?>,
	canVerify: <?= $canVerify ? 'true' : 'false' ?>
};
</script>
<script>
(function(){
	if (!window.WvReviewConfig) return;
	const target = document.getElementById('wvPlayerSig');
	if (target && window.wvRenderSignature) window.wvRenderSignature(target, WvReviewConfig.signatureType, WvReviewConfig.signatureData, 600, 180);

	if (!WvReviewConfig.canVerify) return;
	const form = document.getElementById('wvVerifyForm');
	const status = document.getElementById('wvVerifyStatus');

	async function submit(action) {
		const fd = new FormData(form);
		fd.append('Action', action);
		fd.set('SignatureType', form.querySelector('.wv-sig-type').value);
		fd.set('SignatureData', form.querySelector('.wv-sig-data').value);
		if (!fd.get('SignatureData')) { status.textContent = 'Please sign before submitting.'; return; }
		status.textContent = 'Saving…';
		const r = await fetch('<?= UIR ?>WaiverAjax/verifySignature', { method: 'POST', body: fd, credentials: 'same-origin' });
		const j = await r.json();
		if (j.status === 0) { window.location.reload(); }
		else { status.textContent = j.error || 'Failed'; }
	}

	form.querySelectorAll('.wv-actions button').forEach(b => b.addEventListener('click', () => submit(b.dataset.action)));
})();
</script>
