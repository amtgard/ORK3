<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $this->data['_wv'];
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
window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
</body></html>
