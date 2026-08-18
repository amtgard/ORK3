<?php
$wv = $_wv;
$ki = $kingdom_info['KingdomInfo'] ?? [];
$kingdomName = htmlspecialchars($ki['KingdomName'] ?? 'Kingdom');
$token       = htmlspecialchars($wv['token']);
$kingdomId   = (int)$wv['kingdom_id'];
$kk = $wv['kingdom_template'];
$pk = $wv['park_template'];
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>revised-frontend/lib/trix/trix.css">
<script src="<?=HTTP_TEMPLATE?>revised-frontend/lib/trix/trix.umd.min.js"></script>
<style>
.wv-builder { max-width: 1200px; margin: 0 auto 20px; padding: 0 16px; }
.wv-builder h2, .wv-builder h3, .wv-builder h4, .wv-builder h5, .wv-builder h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-root { max-width: 1200px; margin: 20px auto 0; padding: 0 16px; }
.wv-root .rp-root { padding: 0; }
.wv-disclaimer { display: flex; gap: 12px; align-items: flex-start; margin: 12px 0 0 0; padding: 12px 16px; background: #fff8e1; border: 1px solid #f0c36d; border-left: 4px solid #d69e2e; border-radius: 4px; color: #5c4400; font-size: 13px; line-height: 1.5; }
.wv-disclaimer i { color: #b7791f; font-size: 18px; margin-top: 1px; flex-shrink: 0; }
.wv-disclaimer strong { color: #744210; }

/* Scope tabs (Kingdom / Park) */
.wv-builder .wv-tabs { display: flex; gap: 4px; margin: 20px 0 0 0; border-bottom: 2px solid #ccc; }
.wv-builder .wv-tab { padding: 10px 18px; cursor: pointer; background: #eee; border: 1px solid #ccc; border-bottom: none; border-radius: 6px 6px 0 0; }
.wv-builder .wv-tab.wv-active { background: #fff; font-weight: bold; border-bottom: 2px solid #fff; margin-bottom: -2px; }
.wv-builder .wv-pane { background: #fff; padding: 16px; border: 1px solid #ccc; border-top: none; }

/* Save bar */
.wv-builder .wv-save-bar { display: flex; align-items: center; gap: 12px; margin: 0 0 14px 0; padding: 10px; background: #f4f4f4; border: 1px solid #ddd; border-radius: 4px; flex-wrap: wrap; }
.wv-builder .wv-save-bar button { padding: 8px 18px; font-weight: 600; cursor: pointer; border-radius: 4px; border: 1px solid #999; background: #fff; }
.wv-builder .wv-save-bar .wv-btn-save    { background: #2f855a; color: #fff; border-color: #2f855a; }
.wv-builder .wv-save-bar .wv-btn-save:hover    { background: #276749; }
.wv-builder .wv-save-bar .wv-btn-preview { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
.wv-builder .wv-save-bar .wv-btn-preview:hover { background: #2c5282; }
.wv-builder .wv-save-bar .wv-enable { margin-left: auto; display: flex; align-items: center; gap: 6px; }
.wv-builder .wv-version-label { font-size: 12px; color: #666; }
.wv-builder .wv-status-ok   { color: #060; font-weight: bold; }
.wv-builder .wv-status-err  { color: #a00; font-weight: bold; }

/* Section sub-tabs (Data Capture / Page Layout / Waiver Body) */
.wv-builder .wv-sect-tabs { display: flex; gap: 4px; margin-bottom: 12px; border-bottom: 1px solid #ddd; }
.wv-builder .wv-sect-tab { padding: 8px 16px; cursor: pointer; font-size: 14px; font-weight: 500; color: #666; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.wv-builder .wv-sect-tab.wv-active { color: #2b6cb0; border-bottom-color: #2b6cb0; font-weight: 600; }
.wv-builder .wv-sect-pane { display: none; }
.wv-builder .wv-sect-pane.wv-active { display: block; }

/* Form fields */
.wv-builder .wv-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
.wv-builder .wv-field > label { font-weight: 600; font-size: 13px; color: #333; }
.wv-builder input[type=number], .wv-builder input[type=text] { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
.wv-builder .wv-locked { background: #f0f0f0; border: 1px dashed #999; padding: 12px; border-radius: 4px; color: #555; font-size: 13px; }

/* Fields & Demographics */
.wv-builder .wv-fields-pane { background: #fafcff; border: 1px solid #d4e0ee; border-radius: 6px; padding: 14px; margin-bottom: 14px; }
.wv-builder .wv-fields-pane h3 { margin: 0 0 6px 0; font-size: 16px; }
.wv-builder .wv-hint { font-size: 12px; color: #666; margin: 0 0 10px 0; }
.wv-builder .wv-dem-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 8px 12px; margin-bottom: 10px; }
.wv-builder .wv-dem-grid label { font-weight: normal; font-size: 13px; }
.wv-builder .wv-max-minors-field input[type=number] { width: 80px; }

/* Custom Fields Editor */
.wv-builder .wv-cfe { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
.wv-builder .wv-cfe-row { display: grid; grid-template-columns: auto 1fr 140px auto auto auto; gap: 6px; align-items: center; padding: 6px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
.wv-builder .wv-cfe-row .wv-cfe-grab { cursor: grab; color: #888; font-size: 18px; padding: 0 4px; user-select: none; }
.wv-builder .wv-cfe-row input[type=text] { padding: 4px 6px; border: 1px solid #ccc; border-radius: 3px; }
.wv-builder .wv-cfe-row select { padding: 4px; }
.wv-builder .wv-cfe-row .wv-cfe-del { color: #a00; cursor: pointer; background: none; border: none; font-size: 18px; }
.wv-builder [data-tip] { position: relative; }
.wv-builder [data-tip]:hover::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #2d3748; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; pointer-events: none; z-index: 30; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
.wv-builder [data-tip]:hover::before { content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); border: 4px solid transparent; border-top-color: #2d3748; pointer-events: none; z-index: 30; }
.wv-builder .wv-cfe-options { grid-column: 2 / -1; padding-left: 26px; display: none; }
.wv-builder .wv-cfe-options textarea { width: 100%; min-height: 48px; font-family: monospace; font-size: 12px; border: 1px solid #ddd; border-radius: 3px; padding: 4px; }
.wv-builder .wv-cfe-row.wv-cfe-has-opts .wv-cfe-options { display: block; }
.wv-builder .wv-cfe-add { margin-top: 4px; padding: 6px 10px; cursor: pointer; }

/* Trix editor */
.wv-builder trix-editor { min-height: 160px; max-height: 480px; overflow-y: auto; background: #fff; border: 1px solid #ccc; border-radius: 0 0 4px 4px; padding: 10px 12px; font-size: 14px; line-height: 1.5; }
.wv-builder trix-editor:empty:before { content: attr(placeholder); color: #aaa; font-style: italic; }
.wv-builder trix-toolbar { background: #f4f4f4; border: 1px solid #ccc; border-bottom: none; border-radius: 4px 4px 0 0; padding: 4px; }
.wv-builder trix-toolbar .trix-button { font-size: 13px; }
/* Hide file-attach button — waivers should never have arbitrary attachments. */
.wv-builder trix-toolbar .trix-button-group--file-tools,
.wv-builder trix-editor [data-trix-attachment] { display: none !important; }
.wv-builder .wv-body-tall trix-editor { min-height: 320px; }

/* --- Dark mode --- */
html[data-theme="dark"] .wv-disclaimer { background: #3d2f0f; border-color: #8a6d2e; border-left-color: #d69e2e; color: #f5d97a; }
html[data-theme="dark"] .wv-disclaimer strong { color: #ffd966; }
html[data-theme="dark"] .wv-disclaimer i { color: #f0c36d; }
html[data-theme="dark"] .wv-builder .wv-tabs { border-bottom-color: #4a5568; }
html[data-theme="dark"] .wv-builder .wv-tab { background: #2d3748; border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .wv-builder .wv-tab.wv-active { background: #1f2937; color: #e2e8f0; border-bottom-color: #1f2937; }
html[data-theme="dark"] .wv-builder .wv-pane { background: #1f2937; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-field > label { color: #e2e8f0; }
html[data-theme="dark"] .wv-builder input[type=text],
html[data-theme="dark"] .wv-builder input[type=number] { background: #374151; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder input[type=text]:focus,
html[data-theme="dark"] .wv-builder input[type=number]:focus { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129,140,248,0.2); outline: none; }
html[data-theme="dark"] .wv-builder select { background: #374151; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-save-bar { background: #2d3748; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-save-bar button { background: #4a5568; color: #e2e8f0; border-color: #718096; }
html[data-theme="dark"] .wv-builder .wv-save-bar .wv-btn-save { background: #38a169; border-color: #38a169; }
html[data-theme="dark"] .wv-builder .wv-save-bar .wv-btn-save:hover { background: #2f855a; }
html[data-theme="dark"] .wv-builder .wv-save-bar .wv-btn-preview { background: #4c5fa8; border-color: #4c5fa8; }
html[data-theme="dark"] .wv-builder .wv-save-bar .wv-btn-preview:hover { background: #5b6cb9; }
html[data-theme="dark"] .wv-builder .wv-locked { background: #2d3748; border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .wv-builder .wv-version-label,
html[data-theme="dark"] .wv-builder .wv-hint { color: #a0aec0; }
html[data-theme="dark"] .wv-builder .wv-sect-tabs { border-bottom-color: #4a5568; }
html[data-theme="dark"] .wv-builder .wv-sect-tab { color: #a0aec0; }
html[data-theme="dark"] .wv-builder .wv-sect-tab.wv-active { color: #a5b4fc; border-bottom-color: #a5b4fc; }
html[data-theme="dark"] .wv-builder .wv-fields-pane { background: #232d3f; border-color: #3a4a6b; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-cfe-row { background: #2d3748; border-color: #4a5568; }
html[data-theme="dark"] .wv-builder .wv-cfe-row .wv-cfe-grab { color: #a0aec0; }
html[data-theme="dark"] .wv-builder .wv-cfe-row .wv-cfe-del { color: #fc8181; }
html[data-theme="dark"] .wv-builder [data-tip]:hover::after { background: #e2e8f0; color: #1a202c; box-shadow: 0 2px 6px rgba(0,0,0,0.5); }
html[data-theme="dark"] .wv-builder [data-tip]:hover::before { border-top-color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-cfe-options textarea { background: #1f2937; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-status-ok { color: #68d391; }
html[data-theme="dark"] .wv-builder .wv-status-err { color: #fc8181; }
html[data-theme="dark"] .wv-builder trix-editor { background: #1a202c; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder trix-editor:empty:before { color: #718096; }
html[data-theme="dark"] .wv-builder trix-toolbar { background: #2d3748; border-color: #4a5568; }
html[data-theme="dark"] .wv-builder trix-toolbar .trix-button { color: #e2e8f0; background: transparent; border-color: #4a5568; }
html[data-theme="dark"] .wv-builder trix-toolbar .trix-button.trix-active,
html[data-theme="dark"] .wv-builder trix-toolbar .trix-button:not(:disabled):hover { background: #4a5568; }
html[data-theme="dark"] .wv-builder trix-toolbar .trix-button--icon::before { filter: invert(0.92); }
html[data-theme="dark"] .wv-builder trix-editor a { color: #a5b4fc; }

/* Change History link (save bar) */
.wv-builder .wv-save-bar .wv-history-link { font-size: 12px; color: #2b6cb0; text-decoration: none; padding: 6px 8px; border-radius: 4px; font-weight: 600; }
.wv-builder .wv-save-bar .wv-history-link:hover { text-decoration: underline; background: rgba(43,108,176,0.08); }
html[data-theme="dark"] .wv-builder .wv-save-bar .wv-history-link { color: #93c5fd; background: transparent; border: none; }
html[data-theme="dark"] .wv-builder .wv-save-bar .wv-history-link:hover { background: rgba(147,197,253,0.12); }

/* Publish New Version modal */
.wv-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: flex-start; justify-content: center; padding: 60px 16px; overflow-y: auto; }
.wv-modal-overlay.wv-open { display: flex; }
.wv-modal { background: #fff; border-radius: 8px; width: 100%; max-width: 520px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); overflow: hidden; }
.wv-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #f7fafc; }
.wv-modal-head h3 { margin: 0; font-size: 17px; background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; }
.wv-modal-x { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: #718096; padding: 0 4px; }
.wv-modal-x:hover { color: #2d3748; }
.wv-modal-body { padding: 18px; }
.wv-modal-intro { margin: 0 0 14px 0; font-size: 13px; color: #555; line-height: 1.5; }
.wv-ver-first-hint { margin: 0 0 14px 0; font-size: 12px; color: #2b6cb0; background: #ebf4ff; border: 1px solid #bcd4f0; border-radius: 4px; padding: 8px 10px; }
.wv-modal-body .wv-field input[type=text], .wv-modal-body .wv-field textarea { padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; font-family: inherit; width: 100%; box-sizing: border-box; }
.wv-modal-body .wv-field > label { font-weight: 600; font-size: 13px; color: #333; }
.wv-modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 18px; border-top: 1px solid #e2e8f0; background: #f7fafc; }
.wv-modal-foot button { padding: 8px 18px; font-weight: 600; cursor: pointer; border-radius: 4px; border: 1px solid #999; font-size: 13px; }
.wv-btn-cancel { background: #fff; color: #444; }
.wv-btn-cancel:hover { background: #f0f0f0; }
.wv-btn-confirm { background: #2f855a; color: #fff; border-color: #2f855a; }
.wv-btn-confirm:hover { background: #276749; }
.wv-modal-body .wv-field input.wv-input-err { border-color: #e53e3e; box-shadow: 0 0 0 3px rgba(229,62,62,0.18); }
html[data-theme="dark"] .wv-modal-body .wv-field input.wv-input-err { border-color: #fc8181; box-shadow: 0 0 0 3px rgba(252,129,129,0.22); }
html[data-theme="dark"] .wv-modal { background: #1f2937; }
html[data-theme="dark"] .wv-modal-head, html[data-theme="dark"] .wv-modal-foot { background: #2d3748; border-color: #4a5568; }
html[data-theme="dark"] .wv-modal-head h3 { color: #e2e8f0; }
html[data-theme="dark"] .wv-modal-x { color: #a0aec0; }
html[data-theme="dark"] .wv-modal-x:hover { color: #e2e8f0; }
html[data-theme="dark"] .wv-modal-intro { color: #a0aec0; }
html[data-theme="dark"] .wv-ver-first-hint { color: #93c5fd; background: #1e2d44; border-color: #3a4a6b; }
html[data-theme="dark"] .wv-modal-body .wv-field > label { color: #e2e8f0; }
html[data-theme="dark"] .wv-modal-body .wv-field input[type=text], html[data-theme="dark"] .wv-modal-body .wv-field textarea { background: #374151; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-modal-body .wv-field input[type=text]:focus, html[data-theme="dark"] .wv-modal-body .wv-field textarea:focus { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129,140,248,0.2); outline: none; }
html[data-theme="dark"] .wv-btn-cancel { background: #4a5568; color: #e2e8f0; border-color: #718096; }
html[data-theme="dark"] .wv-btn-cancel:hover { background: #5a6678; }
html[data-theme="dark"] .wv-btn-confirm { background: #38a169; border-color: #38a169; }
html[data-theme="dark"] .wv-btn-confirm:hover { background: #2f855a; }
</style>
<div class="wv-root">
	<div class="rp-root">
		<div class="rp-header">
			<div class="rp-header-left">
				<div class="rp-header-icon-title">
					<i class="fas fa-file-signature rp-header-icon"></i>
					<h1 class="rp-header-title">Digital Waiver Builder</h1>
				</div>
				<div class="rp-header-scope">
					<a class="rp-scope-chip" href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>">
						<i class="fas fa-chess-rook"></i>
						<?= $kingdomName ?>
					</a>
				</div>
			</div>
		</div>
		<div class="rp-context">
			<i class="fas fa-info-circle rp-context-icon"></i>
			<span>Design waivers used at kingdom events and at park days. Kingdom admins only.</span>
		</div>
		<div class="wv-disclaimer" role="alert">
			<i class="fas fa-exclamation-triangle"></i>
			<div><strong>Notice:</strong> The Digital Waiver Service is provided as an optional module of the Amtgard Online Record Keeper. By enabling this module, you declare that you have performed all necessary due diligence related to the use and deployment of this module and online waivers in general. You declare that you have confirmed with your Board of Directors or other relevant entity that this online waiver may be used in your kingdom. You declare that the Amtgard Online Record Keeper is permitted to store all details you have designated as required.</div>
		</div>
	</div>
</div>
<div class="wv-builder">
	<div class="wv-tabs">
		<div class="wv-tab wv-active" data-scope="kingdom">Kingdom Waiver</div>
		<div class="wv-tab" data-scope="park">Park Waiver</div>
	</div>

	<?php foreach (['kingdom', 'park'] as $scope): $tpl = ($scope === 'kingdom' ? $kk : $pk); ?>
	<div class="wv-pane" data-scope="<?= $scope ?>" style="<?= $scope === 'park' ? 'display:none;' : '' ?>">
		<form class="wv-form" data-scope="<?= $scope ?>">
			<input type="hidden" name="Scope" value="<?= $scope ?>">
			<input type="hidden" name="KingdomId" value="<?= $kingdomId ?>">

			<div class="wv-save-bar">
				<button type="submit" class="wv-btn-save"><i class="fas fa-save"></i> Save &amp; Publish New Version</button>
				<button type="button" class="wv-btn-preview"><i class="fas fa-eye"></i> View Preview</button>
				<span class="wv-version-label">Current: <?= $tpl ? 'v' . (int)$tpl['Version'] : '(none)' ?></span>
				<span class="wv-status"></span>
				<label class="wv-enable">
					<input type="checkbox" name="IsEnabled" value="1" <?= ($tpl && (int)$tpl['IsEnabled'] === 1) ? 'checked' : '' ?>>
					Enabled (players can sign)
				</label>
				<a class="wv-history-link" href="<?= UIR ?>Reports/waiverhistory/Kingdom&id=<?= $kingdomId ?>&scope=<?= $scope ?>">Change History &rarr;</a>
			</div>

			<div class="wv-sect-tabs" role="tablist">
				<div class="wv-sect-tab wv-active" data-sect="data">Data Capture</div>
				<div class="wv-sect-tab"            data-sect="layout">Page Layout</div>
				<div class="wv-sect-tab"            data-sect="body">Waiver Body</div>
			</div>

			<!-- Data Capture: fields, demographics, max minors, custom fields -->
			<div class="wv-sect-pane wv-active" data-sect="data">
				<div class="wv-fields-pane">
					<h3>Fields &amp; Demographics</h3>
					<p class="wv-hint">Toggle the data you want signers to provide. All demographics prefill from the signer's profile where available.</p>
					<div class="wv-dem-grid">
						<label><input type="checkbox" name="RequiresDob"               value="1" <?= (!empty($tpl['RequiresDob']))              ? 'checked' : '' ?>> Date of birth</label>
						<label><input type="checkbox" name="RequiresPreferredName"     value="1" <?= (!empty($tpl['RequiresPreferredName']))    ? 'checked' : '' ?>> Preferred name</label>
						<label><input type="checkbox" name="RequiresGender"            value="1" <?= (!empty($tpl['RequiresGender']))           ? 'checked' : '' ?>> Gender</label>
						<label><input type="checkbox" name="RequiresAddress"           value="1" <?= (!empty($tpl['RequiresAddress']))          ? 'checked' : '' ?>> Address</label>
						<label><input type="checkbox" name="RequiresPhone"             value="1" <?= (!empty($tpl['RequiresPhone']))            ? 'checked' : '' ?>> Phone</label>
						<label><input type="checkbox" name="RequiresEmail"             value="1" <?= (!empty($tpl['RequiresEmail']))            ? 'checked' : '' ?>> Email</label>
						<label><input type="checkbox" name="RequiresEmergencyContact" value="1" <?= (!empty($tpl['RequiresEmergencyContact'])) ? 'checked' : '' ?>> Emergency contact</label>
						<label><input type="checkbox" name="RequiresWitness"           value="1" <?= (!empty($tpl['RequiresWitness']))          ? 'checked' : '' ?>> Witness signature</label>
					</div>
					<div class="wv-field wv-max-minors-field">
						<label>Maximum minors per signing</label>
						<input type="number" name="MaxMinors" min="1" max="6" value="<?= (int)($tpl['MaxMinors'] ?? 1) ?>">
						<span class="wv-hint">1 for individual waivers; up to 6 for family waivers.</span>
					</div>
					<div class="wv-field">
						<label>Custom fields</label>
						<div class="wv-cfe" data-scope="<?= $scope ?>"></div>
						<button type="button" class="wv-cfe-add">+ Add custom field</button>
						<input type="hidden" name="CustomFieldsJson" value='<?= htmlspecialchars($tpl['CustomFieldsJson'] ?? '[]', ENT_QUOTES) ?>'>
					</div>
				</div>
			</div>

			<!-- Page Layout: header / footer (plus auto-fill notes) -->
			<div class="wv-sect-pane" data-sect="layout">
				<div class="wv-field">
					<label>Header (shown on every page)</label>
					<input id="wv-header-html-<?= $scope ?>" type="hidden" name="HeaderHtml" value="<?= htmlspecialchars($tpl['HeaderHtml'] ?? '', ENT_QUOTES) ?>">
					<trix-editor input="wv-header-html-<?= $scope ?>" placeholder="Title, organization name, intro&hellip;"></trix-editor>
				</div>
				<div class="wv-field">
					<label>Player Header (fixed &mdash; not editable)</label>
					<div class="wv-locked">Fields: First Name, Last Name, Persona Name, Home Park, Home Kingdom. Auto-filled from the signing player's profile.</div>
				</div>
				<div class="wv-field">
					<label>Signature Block (fixed &mdash; not editable)</label>
					<div class="wv-locked">Players choose drawn (finger/mouse) or typed signature. Date auto-recorded at submission.</div>
				</div>
				<div class="wv-field">
					<label>Footer (shown on every page)</label>
					<input id="wv-footer-html-<?= $scope ?>" type="hidden" name="FooterHtml" value="<?= htmlspecialchars($tpl['FooterHtml'] ?? '', ENT_QUOTES) ?>">
					<trix-editor input="wv-footer-html-<?= $scope ?>" placeholder="Copyright, page footer, contact info&hellip;"></trix-editor>
				</div>
			</div>

			<!-- Waiver Body: main body + minor-representative text -->
			<div class="wv-sect-pane" data-sect="body">
				<div class="wv-field wv-body-tall">
					<label>Waiver Details (the body of the waiver)</label>
					<input id="wv-body-html-<?= $scope ?>" type="hidden" name="BodyHtml" value="<?= htmlspecialchars($tpl['BodyHtml'] ?? '', ENT_QUOTES) ?>">
					<trix-editor input="wv-body-html-<?= $scope ?>" placeholder="Assumption of risk, terms, indemnity&hellip;"></trix-editor>
				</div>
				<div class="wv-field">
					<label>Minor Representative Text (shown when signer marks as minor)</label>
					<input id="wv-minor-html-<?= $scope ?>" type="hidden" name="MinorHtml" value="<?= htmlspecialchars($tpl['MinorHtml'] ?? '', ENT_QUOTES) ?>">
					<trix-editor input="wv-minor-html-<?= $scope ?>" placeholder="Guardian acknowledgement, minor-specific terms&hellip;"></trix-editor>
				</div>
			</div>
		</form>
	</div>
	<?php endforeach; ?>

	<!-- Publish New Version modal (reusable across scope tabs) -->
	<div class="wv-modal-overlay" id="wv-version-modal" aria-hidden="true">
		<div class="wv-modal" role="dialog" aria-modal="true" aria-labelledby="wv-ver-title">
			<div class="wv-modal-head">
				<h3 id="wv-ver-title">Publish New Version</h3>
				<button type="button" class="wv-modal-x" data-tip="Close" aria-label="Close">&times;</button>
			</div>
			<div class="wv-modal-body">
				<p class="wv-modal-intro">Each save publishes a new, immutable version of this waiver. Give it a name and describe what changed.</p>
				<p class="wv-ver-first-hint" id="wv-ver-first-hint" hidden>First version &mdash; describe the initial publication.</p>
				<div class="wv-field">
					<label for="wv-ver-name">Version name</label>
					<input type="text" id="wv-ver-name" maxlength="120" placeholder="e.g. Spring 2026 revision">
				</div>
				<div class="wv-field">
					<label for="wv-ver-reason">Summary of changes</label>
					<textarea id="wv-ver-reason" rows="4" placeholder="What changed in this version and why?"></textarea>
				</div>
			</div>
			<div class="wv-modal-foot">
				<button type="button" class="wv-btn-cancel" id="wv-ver-cancel">Cancel</button>
				<button type="button" class="wv-btn-confirm" id="wv-ver-confirm"><i class="fas fa-upload"></i> Publish Version</button>
			</div>
		</div>
	</div>
</div>
<script>
window.WvBuilderConfig = { token: "<?= $token ?>", kingdomId: <?= $kingdomId ?> };
</script>
<script>
(function(){
	if (!window.WvBuilderConfig || !window.WvBuilderConfig.token) return; // admin gate

	// Scope tabs
	const scopeTabs  = document.querySelectorAll('.wv-builder > .wv-tabs > .wv-tab');
	const scopePanes = document.querySelectorAll('.wv-builder > .wv-pane');
	scopeTabs.forEach(t => t.addEventListener('click', () => {
		scopeTabs.forEach(x => x.classList.remove('wv-active'));
		t.classList.add('wv-active');
		scopePanes.forEach(p => p.style.display = (p.dataset.scope === t.dataset.scope) ? '' : 'none');
	}));

	// Section sub-tabs
	document.querySelectorAll('.wv-pane').forEach(pane => {
		const tabs  = pane.querySelectorAll('.wv-sect-tab');
		const panes = pane.querySelectorAll('.wv-sect-pane');
		tabs.forEach(t => t.addEventListener('click', () => {
			tabs.forEach(x => x.classList.remove('wv-active'));
			panes.forEach(x => x.classList.remove('wv-active'));
			t.classList.add('wv-active');
			pane.querySelector('.wv-sect-pane[data-sect="' + t.dataset.sect + '"]').classList.add('wv-active');
		}));
	});

	document.querySelectorAll('.wv-builder .wv-form').forEach(form => {
		// Custom Fields Editor
		const cfe = form.querySelector('.wv-cfe');
		const cfeHidden = form.querySelector('input[name="CustomFieldsJson"]');
		const cfeAddBtn = form.querySelector('.wv-cfe-add');
		const typeOpts = ['text','textarea','checkbox','initial','radio','select','date'];
		function slugify(s) {
			return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32) || ('f_' + Math.random().toString(36).slice(2, 8));
		}
		function uniqueId(existing, proposed) {
			let id = proposed; let i = 1;
			while (existing.has(id)) { id = (proposed + '_' + (++i)).slice(0, 32); }
			return id;
		}
		function collectState() {
			return [...cfe.querySelectorAll('.wv-cfe-row')].map(r => {
				const type = r.querySelector('select[name^=cfe_type]').value;
				const entry = {
					id:       r.querySelector('input[name^=cfe_id]').value.trim(),
					label:    r.querySelector('input[name^=cfe_label]').value.trim(),
					type:     type,
					required: r.querySelector('input[name^=cfe_req]').checked,
				};
				if (type === 'radio' || type === 'select') {
					const raw = r.querySelector('textarea[name^=cfe_opts]').value;
					entry.options = raw.split(/[\n,]+/).map(s => s.trim()).filter(Boolean);
				}
				return entry;
			}).filter(e => e.label);
		}
		function syncHidden() { cfeHidden.value = JSON.stringify(collectState()); }
		function makeRow(entry) {
			const row = document.createElement('div');
			row.className = 'wv-cfe-row';
			const hasOpts = (entry.type === 'radio' || entry.type === 'select');
			if (hasOpts) row.classList.add('wv-cfe-has-opts');
			row.innerHTML = ''
				+ '<span class="wv-cfe-grab" data-tip="Drag to reorder">⋮⋮</span>'
				+ '<input type="text" name="cfe_label" placeholder="Field label" value="' + (entry.label || '').replace(/"/g,'&quot;') + '">'
				+ '<select name="cfe_type">' + typeOpts.map(tp => '<option value="' + tp + '"' + (tp === entry.type ? ' selected' : '') + '>' + tp + '</option>').join('') + '</select>'
				+ '<label><input type="checkbox" name="cfe_req"' + (entry.required ? ' checked' : '') + '> req</label>'
				+ '<input type="text" name="cfe_id" placeholder="id" value="' + (entry.id || '').replace(/"/g,'&quot;') + '" size="10">'
				+ '<button type="button" class="wv-cfe-del" data-tip="Delete">×</button>'
				+ '<div class="wv-cfe-options"><textarea name="cfe_opts" placeholder="One option per line or comma-separated">' + ((entry.options || []).join('\n')) + '</textarea></div>';
			row.querySelector('.wv-cfe-del').addEventListener('click', () => { row.remove(); syncHidden(); });
			row.querySelector('select[name=cfe_type]').addEventListener('change', (ev) => {
				const t2 = ev.target.value;
				row.classList.toggle('wv-cfe-has-opts', (t2 === 'radio' || t2 === 'select'));
				syncHidden();
			});
			row.querySelector('input[name=cfe_label]').addEventListener('blur', (ev) => {
				const idInput = row.querySelector('input[name=cfe_id]');
				if (!idInput.value) {
					const used = new Set([...cfe.querySelectorAll('input[name=cfe_id]')].map(i => i.value).filter(Boolean));
					idInput.value = uniqueId(used, slugify(ev.target.value));
					syncHidden();
				}
			});
			row.addEventListener('input', syncHidden);
			row.addEventListener('change', syncHidden);
			row.setAttribute('draggable', 'true');
			row.addEventListener('dragstart', (ev) => { row.dataset.dragging = '1'; ev.dataTransfer.effectAllowed = 'move'; });
			row.addEventListener('dragend',   () => { delete row.dataset.dragging; syncHidden(); });
			row.addEventListener('dragover',  (ev) => {
				ev.preventDefault();
				const dragging = cfe.querySelector('.wv-cfe-row[data-dragging="1"]');
				if (dragging && dragging !== row) {
					const rect = row.getBoundingClientRect();
					cfe.insertBefore(dragging, (ev.clientY - rect.top < rect.height / 2) ? row : row.nextSibling);
				}
			});
			return row;
		}
		try {
			const initial = JSON.parse(cfeHidden.value || '[]');
			if (Array.isArray(initial)) initial.forEach(entry => cfe.appendChild(makeRow(entry)));
		} catch (e) { /* bad seed, start empty */ }
		cfeAddBtn.addEventListener('click', () => {
			cfe.appendChild(makeRow({ type: 'text', label: '', required: false }));
			syncHidden();
		});
		syncHidden();

		// Save → open the Publish New Version modal (capture which form triggered it)
		form.addEventListener('submit', (e) => {
			e.preventDefault();
			syncHidden();
			openVersionModal(form);
		});

		// View Preview: open new tab via a hidden form post
		const previewBtn = form.querySelector('.wv-btn-preview');
		previewBtn.addEventListener('click', () => {
			syncHidden();
			const fd = new FormData(form);
			const ghost = document.createElement('form');
			ghost.method = 'POST';
			ghost.action = '<?= UIR ?>Waiver/preview/<?= $kingdomId ?>';
			ghost.target = '_blank';
			ghost.style.display = 'none';
			fd.forEach((v, k) => {
				const i = document.createElement('input');
				i.type = 'hidden'; i.name = k; i.value = v;
				ghost.appendChild(i);
			});
			document.body.appendChild(ghost);
			ghost.submit();
			document.body.removeChild(ghost);
		});
	});

	// --- Publish New Version modal (shared across scope tabs) ---
	const overlay   = document.getElementById('wv-version-modal');
	const nameInput = document.getElementById('wv-ver-name');
	const reasonInp = document.getElementById('wv-ver-reason');
	const firstHint = document.getElementById('wv-ver-first-hint');
	const cancelBtn = document.getElementById('wv-ver-cancel');
	const confirmBtn= document.getElementById('wv-ver-confirm');
	const closeBtn  = overlay ? overlay.querySelector('.wv-modal-x') : null;
	let activeForm = null; // the .wv-form that triggered the modal

	function closeVersionModal() {
		if (!overlay) return;
		overlay.classList.remove('wv-open');
		overlay.setAttribute('aria-hidden', 'true');
		activeForm = null;
	}

	function openVersionModal(form) {
		if (!overlay) return;
		activeForm = form;
		const scope = form.dataset.scope;
		nameInput.value = '';
		if (firstHint) firstHint.hidden = true;
		overlay.classList.add('wv-open');
		overlay.setAttribute('aria-hidden', 'false');
		nameInput.focus();
		// Prefill from server (suggested version name + first-version default reason)
		fetch('<?= UIR ?>WaiverAjax/versionDefaults?KingdomId=<?= $kingdomId ?>&Scope=' + encodeURIComponent(scope), { credentials: 'same-origin' })
			.then(r => r.json())
			.then(j => {
				if (!j || activeForm !== form) return; // a different scope may have opened meanwhile
				if (j.VersionName) nameInput.value = j.VersionName;
				if (j.IsFirst) {
					if (firstHint) firstHint.hidden = false;
					if (!reasonInp.value.trim() && j.DefaultReason) reasonInp.value = j.DefaultReason;
				}
			})
			.catch(() => { /* prefill is best-effort; user can still type a name */ });
	}

	async function doSave(form, versionName, changeReason) {
		const fd = new FormData(form);
		fd.set('VersionName', versionName);
		fd.set('ChangeReason', changeReason);
		const status = form.querySelector('.wv-status');
		status.className = 'wv-status'; status.textContent = 'Saving…';
		try {
			const r = await fetch('<?= UIR ?>WaiverAjax/saveTemplate', { method: 'POST', body: fd, credentials: 'same-origin' });
			const j = await r.json();
			if (j.status === 0) {
				status.className = 'wv-status wv-status-ok';
				status.textContent = 'Saved — v' + j.Version;
				const label = form.querySelector('.wv-version-label');
				if (label) label.textContent = 'Current: v' + j.Version;
				closeVersionModal();
			} else {
				status.className = 'wv-status wv-status-err';
				status.textContent = j.error || 'Save failed';
			}
		} catch (err) {
			status.className = 'wv-status wv-status-err';
			status.textContent = 'Network error';
		}
	}

	if (overlay) {
		if (cancelBtn) cancelBtn.addEventListener('click', closeVersionModal);
		if (closeBtn)  closeBtn.addEventListener('click', closeVersionModal);
		overlay.addEventListener('click', (e) => { if (e.target === overlay) closeVersionModal(); });
		document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('wv-open')) closeVersionModal(); });
		if (confirmBtn) confirmBtn.addEventListener('click', () => {
			if (!activeForm) return;
			// Require a non-empty version name; fall back to placeholder/suggested value, never submit empty.
			let name = nameInput.value.trim();
			if (!name) { nameInput.focus(); nameInput.classList.add('wv-input-err'); return; }
			nameInput.classList.remove('wv-input-err');
			doSave(activeForm, name, reasonInp.value.trim());
		});
		nameInput.addEventListener('input', () => nameInput.classList.remove('wv-input-err'));
	}
})();
</script>
