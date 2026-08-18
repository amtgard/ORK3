<?php
$wv = $_wv;
$tpl = $wv['template'];
$ki  = $wv['kingdom_info']['KingdomInfo'] ?? [];
$kingdomName = htmlspecialchars($ki['KingdomName'] ?? 'Kingdom');
$scope = $wv['scope'];
$cfTpl = [];
try { $cfTpl = json_decode($tpl['CustomFieldsJson'] ?? '[]', true) ?: []; } catch (Throwable $e) { $cfTpl = []; }
?>
<style>
.wv-preview-page { max-width: 900px; margin: 20px auto; padding: 0 16px; }
.wv-preview-page h1, .wv-preview-page h2, .wv-preview-page h3, .wv-preview-page h4, .wv-preview-page h5, .wv-preview-page h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-preview-page h1 { font-size: 26px; margin: 0 0 4px 0; }
.wv-preview-banner { padding: 10px 14px; background: #fef3c7; border: 1px solid #f0c36d; border-left: 4px solid #d69e2e; border-radius: 4px; color: #744210; font-size: 13px; margin-bottom: 14px; display: flex; align-items: center; gap: 10px; }
.wv-preview-banner i { font-size: 16px; color: #b7791f; }
.wv-preview-page .wv-section { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin: 14px 0; background: #fff; }
.wv-preview-page .wv-section h2 { font-size: 18px; margin: 0 0 8px 0; }
.wv-preview-page .wv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.wv-preview-page label { font-size: 12px; color: #555; font-weight: bold; display: block; margin-bottom: 2px; }
.wv-preview-page .wv-input-placeholder { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; background: #f9f9f9; min-height: 26px; color: #999; font-style: italic; font-size: 13px; }
.wv-preview-page .wv-empty-note { color: #999; font-style: italic; font-size: 13px; }
.wv-preview-page .wv-html-body :first-child { margin-top: 0; }
.wv-preview-page .wv-html-body :last-child { margin-bottom: 0; }
.wv-preview-page .wv-minor-toggle { padding: 10px; background: #fffbea; border: 1px solid #f4e3a0; border-radius: 4px; font-size: 13px; }
.wv-preview-page .wv-cf-row { margin-bottom: 10px; }

/* --- Dark mode --- */
html[data-theme="dark"] .wv-preview-page { color: #e2e8f0; }
html[data-theme="dark"] .wv-preview-banner { background: #3d2f0f; border-color: #8a6d2e; border-left-color: #d69e2e; color: #f5d97a; }
html[data-theme="dark"] .wv-preview-banner i { color: #f0c36d; }
html[data-theme="dark"] .wv-preview-page .wv-section { background: #1f2937; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-preview-page label { color: #cbd5e0; }
html[data-theme="dark"] .wv-preview-page .wv-input-placeholder { background: #2d3748; border-color: #4a5568; color: #718096; }
html[data-theme="dark"] .wv-preview-page .wv-empty-note { color: #718096; }
html[data-theme="dark"] .wv-preview-page .wv-minor-toggle { background: #3d2f0f; border-color: #8a6d2e; color: #f5d97a; }
html[data-theme="dark"] .wv-preview-page a { color: #a5b4fc; }
</style>
<div class="wv-preview-page">
	<div class="wv-preview-banner">
		<i class="fas fa-eye"></i>
		<div><strong>Preview mode</strong> &mdash; this is how the <?= htmlspecialchars($scope) ?> waiver will appear to a signing player. Form fields are decorative; nothing here can be submitted.</div>
	</div>

	<?php if ($tpl['HeaderHtml']): ?>
	<div class="wv-section wv-html-body"><?= $tpl['HeaderHtml'] /* already sanitized */ ?></div>
	<?php else: ?>
	<div class="wv-section"><span class="wv-empty-note">(Header is empty)</span></div>
	<?php endif; ?>

	<div class="wv-section">
		<h2>Your Information</h2>
		<div class="wv-grid">
			<div><label>First (legal) name</label><div class="wv-input-placeholder">player.given_name</div></div>
			<div><label>Last (legal) name</label><div class="wv-input-placeholder">player.surname</div></div>
			<div><label>Persona name</label><div class="wv-input-placeholder">player.persona</div></div>
			<?php if (!empty($tpl['RequiresPreferredName'])): ?>
			<div><label>Preferred name *</label><div class="wv-input-placeholder">required</div></div>
			<?php endif; ?>
			<?php if (!empty($tpl['RequiresGender'])): ?>
			<div><label>Gender *</label><div class="wv-input-placeholder">required</div></div>
			<?php endif; ?>
			<?php if (!empty($tpl['RequiresDob'])): ?>
			<div><label>Date of birth *</label><div class="wv-input-placeholder">required</div></div>
			<?php endif; ?>
			<?php if (!empty($tpl['RequiresAddress'])): ?>
			<div style="grid-column: span 2;"><label>Address *</label><div class="wv-input-placeholder">required</div></div>
			<?php endif; ?>
			<?php if (!empty($tpl['RequiresPhone'])): ?>
			<div><label>Phone *</label><div class="wv-input-placeholder">required</div></div>
			<?php endif; ?>
			<?php if (!empty($tpl['RequiresEmail'])): ?>
			<div><label>Email *</label><div class="wv-input-placeholder">required</div></div>
			<?php endif; ?>
			<div><label>Home park / kingdom</label><div class="wv-input-placeholder">auto-captured</div></div>
		</div>
	</div>

	<?php if (!empty($tpl['RequiresEmergencyContact'])): ?>
	<div class="wv-section">
		<h2>Emergency Contact</h2>
		<div class="wv-grid">
			<div><label>Contact name *</label><div class="wv-input-placeholder">required</div></div>
			<div><label>Relationship *</label><div class="wv-input-placeholder">e.g. spouse, parent</div></div>
			<div><label>Phone *</label><div class="wv-input-placeholder">required</div></div>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($tpl['BodyHtml']): ?>
	<div class="wv-section wv-html-body"><?= $tpl['BodyHtml'] /* sanitized */ ?></div>
	<?php else: ?>
	<div class="wv-section"><span class="wv-empty-note">(Waiver body is empty)</span></div>
	<?php endif; ?>

	<?php if (is_array($cfTpl) && count($cfTpl) > 0): ?>
	<div class="wv-section">
		<h2>Acknowledgements &amp; Additional Information</h2>
		<?php foreach ($cfTpl as $f):
			$type  = (string)($f['type'] ?? 'text');
			$label = (string)($f['label'] ?? '');
			$req   = !empty($f['required']);
			$opts  = is_array($f['options'] ?? null) ? $f['options'] : [];
			if ($label === '') continue;
		?>
		<div class="wv-cf-row">
			<?php if ($type === 'checkbox'): ?>
				<label><input type="checkbox" disabled> <?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
			<?php elseif ($type === 'radio'): ?>
				<label><?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
				<?php foreach ($opts as $o): ?>
					<label style="display:inline-block; margin-right:10px; font-weight:normal;"><input type="radio" disabled> <?= htmlspecialchars((string)$o) ?></label>
				<?php endforeach; ?>
			<?php elseif ($type === 'select'): ?>
				<label><?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
				<div class="wv-input-placeholder">&mdash; select &mdash;</div>
			<?php elseif ($type === 'textarea'): ?>
				<label><?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
				<div class="wv-input-placeholder" style="min-height:48px;">(free text)</div>
			<?php elseif ($type === 'date'): ?>
				<label><?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
				<div class="wv-input-placeholder">yyyy-mm-dd</div>
			<?php elseif ($type === 'initial'): ?>
				<label><?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
				<div class="wv-input-placeholder" style="width:80px;">XXX</div>
			<?php else: ?>
				<label><?= htmlspecialchars($label) ?><?= $req ? ' *' : '' ?></label>
				<div class="wv-input-placeholder">(text input)</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="wv-section wv-minor-toggle">
		<label><input type="checkbox" disabled> I am signing for a minor (under 18) &mdash; show guardian/representative fields</label>
	</div>

	<?php if ($tpl['MinorHtml']): ?>
	<div class="wv-section wv-html-body">
		<?= $tpl['MinorHtml'] /* sanitized */ ?>
		<h3 style="margin-top:10px;">Guardian / Representative</h3>
		<div class="wv-grid">
			<div><label>Representative first name</label><div class="wv-input-placeholder">first</div></div>
			<div><label>Representative last name</label><div class="wv-input-placeholder">last</div></div>
			<div style="grid-column: span 2;"><label>Relationship to minor</label><div class="wv-input-placeholder">e.g. mother, legal guardian</div></div>
		</div>
		<p style="font-size:12px; color:#888; margin-top:8px;">Up to <?= (int)$tpl['MaxMinors'] ?> minor<?= (int)$tpl['MaxMinors'] === 1 ? '' : 's' ?> may be listed per signing.</p>
	</div>
	<?php endif; ?>

	<?php if (!empty($tpl['RequiresWitness'])): ?>
	<div class="wv-section">
		<h2>Witness</h2>
		<div class="wv-grid">
			<div style="grid-column: span 2;"><label>Witness printed name *</label><div class="wv-input-placeholder">required</div></div>
		</div>
		<label style="margin-top:8px;">Witness signature</label>
		<div class="wv-input-placeholder" style="min-height: 60px;">(drawn or typed signature)</div>
	</div>
	<?php endif; ?>

	<div class="wv-section">
		<h2>Signature</h2>
		<div class="wv-input-placeholder" style="min-height: 60px;">(drawn or typed signature)</div>
		<p style="font-size: 12px; color: #888; margin-top: 6px;">Signed date will be auto-recorded.</p>
	</div>

	<?php if ($tpl['FooterHtml']): ?>
	<div class="wv-section wv-html-body"><?= $tpl['FooterHtml'] /* sanitized */ ?></div>
	<?php else: ?>
	<div class="wv-section"><span class="wv-empty-note">(Footer is empty)</span></div>
	<?php endif; ?>
</div>
