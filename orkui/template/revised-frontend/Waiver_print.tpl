<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $_wv;
$sig = $wv['signature'];
$tpl = $sig['Template'];
$md = function($t) { return $t ? (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($t) : ''; };
?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Waiver &mdash; <?= htmlspecialchars($sig['MundaneFirst'] . ' ' . $sig['MundaneLast']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
body { font-family: Georgia, serif; color: #111; margin: 0; padding: 0; }
h1, h2, h3, h4, h5, h6 { background: transparent; border: none; padding: 0; text-shadow: none; border-radius: 0; }
.wv-p { max-width: 780px; margin: 0 auto; padding: 20px; }
.wv-p header, .wv-p footer { font-size: 11px; color: #555; border-top: 1px solid #ccc; padding: 6px 0; }
.wv-p header { border-top: none; border-bottom: 1px solid #ccc; margin-bottom: 14px; }
.wv-p .wv-p-section { margin: 10px 0; line-height: 1.5; font-size: 13px; }
.wv-p .wv-p-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; font-size: 13px; }
.wv-p .wv-p-sig { min-height: 160px; border-bottom: 1px solid #333; padding-bottom: 4px; }
.wv-p .wv-p-typed-sig { font-family: 'Homemade Apple', 'Caveat', cursive; font-size: 28px; }
.wv-p .wv-section { border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin: 10px 0; page-break-inside: avoid; }
.wv-p .wv-section h2, .wv-p .wv-section h3 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0 0 6px 0; font-size: 14px; }
.wv-p .wv-dl { display: grid; grid-template-columns: max-content 1fr; gap: 3px 12px; font-size: 12px; }
.wv-p .wv-dl dt { font-weight: bold; color: #555; }
.wv-p .wv-dl dd { margin: 0; }
.wv-p .wv-minors-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.wv-p .wv-minors-tbl th, .wv-p .wv-minors-tbl td { border: 1px solid #ccc; padding: 3px 6px; text-align: left; }
.wv-p .wv-minors-tbl thead { background: #f4f4f4; }
@media print { .wv-p .wv-section { page-break-inside: avoid; } }
@page { margin: 1cm; }
</style>
</head>
<body>
<div class="wv-p">
	<header><?= strip_tags($md($tpl['HeaderMarkdown'] ?? ''), '<strong><em>') ?></header>
	<h1>Digital Waiver &mdash; Signed Record #<?= (int)$sig['SignatureId'] ?></h1>
	<div class="wv-p-grid">
		<div><strong>Legal Name:</strong> <?= htmlspecialchars($sig['MundaneFirst'] . ' ' . $sig['MundaneLast']) ?></div>
		<div><strong>Persona:</strong> <?= htmlspecialchars($sig['PersonaName']) ?></div>
		<div><strong>Park ID:</strong> <?= (int)$sig['ParkId'] ?></div>
		<div><strong>Kingdom ID:</strong> <?= (int)$sig['KingdomId'] ?></div>
		<div><strong>Signed:</strong> <?= htmlspecialchars($sig['SignedAt']) ?></div>
		<div><strong>Template:</strong> v<?= (int)$tpl['Version'] ?> (<?= htmlspecialchars($tpl['Scope']) ?>)</div>
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
					<canvas id="wvPrintWitnessCanvas" width="600" height="140" style="width:100%; max-width:600px; height:140px;"></canvas>
				<?php endif; ?>
			</dd>
		</dl>
	</div>
	<?php endif; ?>

	<div class="wv-p-section"><?= $md($tpl['BodyMarkdown'] ?? '') ?></div>
	<?php if ($sig['IsMinor']): ?>
	<div class="wv-p-section">
		<?= $md($tpl['MinorMarkdown'] ?? '') ?>
		<p><strong>Representative:</strong> <?= htmlspecialchars($sig['MinorRepFirst'] . ' ' . $sig['MinorRepLast']) ?> (<?= htmlspecialchars($sig['MinorRepRelationship']) ?>)</p>
	</div>
	<?php endif; ?>
	<h2>Signature</h2>
	<div class="wv-p-section wv-p-sig" id="wvPrintSig">
		<?php if ($sig['SignatureType'] === 'typed'): ?>
			<div class="wv-p-typed-sig"><?= htmlspecialchars($sig['SignatureData']) ?></div>
		<?php else: ?>
			<canvas id="wvPrintCanvas" width="600" height="160" style="width:100%; max-width:600px; height:160px;"></canvas>
		<?php endif; ?>
	</div>
	<?php if ($sig['VerificationStatus'] === 'verified'): ?>
	<h2>Officer Verification</h2>
	<div class="wv-p-grid">
		<div><strong>Verified by:</strong> <?= htmlspecialchars($sig['VerifierPrintedName']) ?></div>
		<div><strong>Persona:</strong> <?= htmlspecialchars($sig['VerifierPersonaName']) ?></div>
		<div><strong>Office:</strong> <?= htmlspecialchars($sig['VerifierOfficeTitle']) ?></div>
		<div><strong>Date:</strong> <?= htmlspecialchars($sig['VerifiedAt']) ?></div>
	</div>
	<div class="wv-p-section wv-p-sig">
		<?php if ($sig['VerifierSignatureType'] === 'typed'): ?>
			<div class="wv-p-typed-sig"><?= htmlspecialchars($sig['VerifierSignatureData']) ?></div>
		<?php elseif ($sig['VerifierSignatureData']): ?>
			<canvas id="wvPrintOfficerCanvas" width="600" height="140" style="width:100%; max-width:600px; height:140px;"></canvas>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<footer><?= strip_tags($md($tpl['FooterMarkdown'] ?? ''), '<strong><em>') ?></footer>
</div>
<script>
function drawSig(canvasId, dataJson) {
	const c = document.getElementById(canvasId); if (!c) return;
	let strokes; try { strokes = JSON.parse(dataJson); } catch(_) { return; }
	const ctx = c.getContext('2d');
	ctx.lineCap='round'; ctx.lineJoin='round'; ctx.lineWidth=2; ctx.strokeStyle='#111';
	strokes.forEach(s => {
		if (!s.length) return;
		ctx.beginPath();
		ctx.moveTo(s[0].x * c.width, s[0].y * c.height);
		for (let i = 1; i < s.length; i++) ctx.lineTo(s[i].x * c.width, s[i].y * c.height);
		ctx.stroke();
	});
}
<?php if ($sig['SignatureType'] === 'drawn'): ?>
drawSig('wvPrintCanvas', <?= json_encode($sig['SignatureData']) ?>);
<?php endif; ?>
<?php if ($sig['VerificationStatus'] === 'verified' && $sig['VerifierSignatureType'] === 'drawn' && $sig['VerifierSignatureData']): ?>
drawSig('wvPrintOfficerCanvas', <?= json_encode($sig['VerifierSignatureData']) ?>);
<?php endif; ?>
<?php if (!empty($tpl['RequiresWitness']) && ($sig['WitnessSignatureType'] ?? '') === 'drawn' && !empty($sig['WitnessSignatureData'])): ?>
drawSig('wvPrintWitnessCanvas', <?= json_encode($sig['WitnessSignatureData']) ?>);
<?php endif; ?>
window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
</body></html>
