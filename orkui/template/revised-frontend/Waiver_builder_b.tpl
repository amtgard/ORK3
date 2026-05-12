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
<style>
.wv-builder { max-width: 1200px; margin: 0 auto 20px; padding: 0 16px; }
.wv-builder h2, .wv-builder h3, .wv-builder h4, .wv-builder h5, .wv-builder h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-root { max-width: 1200px; margin: 20px auto 0; padding: 0 16px; }
.wv-root .rp-root { padding: 0; }
.wv-disclaimer { display: flex; gap: 12px; align-items: flex-start; margin: 12px 0 0 0; padding: 12px 16px; background: #fff8e1; border: 1px solid #f0c36d; border-left: 4px solid #d69e2e; border-radius: 4px; color: #5c4400; font-size: 13px; line-height: 1.5; }
.wv-disclaimer i { color: #b7791f; font-size: 18px; margin-top: 1px; flex-shrink: 0; }
.wv-disclaimer strong { color: #744210; }
.wv-variant-banner { display: flex; align-items: center; gap: 10px; margin: 12px 0 0 0; padding: 8px 14px; background: #ebf4ff; border: 1px solid #bee3f8; border-left: 4px solid #3182ce; border-radius: 4px; color: #2c5282; font-size: 13px; }
.wv-variant-banner i { color: #3182ce; }
.wv-variant-banner a { color: #2b6cb0; font-weight: 600; }

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

/* Section sub-tabs */
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
.wv-builder .wv-cfe-options { grid-column: 2 / -1; padding-left: 26px; display: none; }
.wv-builder .wv-cfe-options textarea { width: 100%; min-height: 48px; font-family: monospace; font-size: 12px; border: 1px solid #ddd; border-radius: 3px; padding: 4px; }
.wv-builder .wv-cfe-row.wv-cfe-has-opts .wv-cfe-options { display: block; }
.wv-builder .wv-cfe-add { margin-top: 4px; padding: 6px 10px; cursor: pointer; }

/* Markdown editor (GH-style toolbar over textarea) */
.wv-md { border: 1px solid #ccc; border-radius: 4px; overflow: hidden; }
.wv-md-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 2px; padding: 4px 6px; background: #f6f8fa; border-bottom: 1px solid #d1d5da; }
.wv-md-toolbar .wv-md-btn { background: transparent; border: 1px solid transparent; border-radius: 4px; padding: 0; cursor: pointer; color: #24292e; height: 28px; min-width: 30px; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; line-height: 1; }
.wv-md-toolbar .wv-md-btn:hover { background: #e1e4e8; border-color: #d1d5da; }
.wv-md-toolbar .wv-md-sep { width: 1px; align-self: stretch; background: #d1d5da; margin: 4px 4px; }
/* Each button visually mirrors the markdown it produces. */
.wv-md-toolbar .wv-md-h1     { font-weight: 800; font-size: 17px; }
.wv-md-toolbar .wv-md-h2     { font-weight: 800; font-size: 14px; }
.wv-md-toolbar .wv-md-h3     { font-weight: 800; font-size: 12px; }
.wv-md-toolbar .wv-md-h1 sub,
.wv-md-toolbar .wv-md-h2 sub,
.wv-md-toolbar .wv-md-h3 sub { font-size: 0.65em; vertical-align: -0.25em; margin-left: 1px; font-weight: 700; }
.wv-md-toolbar .wv-md-bold   { font-weight: 900; font-size: 15px; font-family: Georgia, serif; }
.wv-md-toolbar .wv-md-italic { font-style: italic; font-weight: 700; font-size: 15px; font-family: Georgia, serif; }
.wv-md-toolbar .wv-md-strike { text-decoration: line-through; font-weight: 700; font-size: 14px; }
.wv-md-toolbar .wv-md-quote  { font-family: Georgia, serif; font-size: 22px; line-height: 0; padding-top: 6px; }
.wv-md-toolbar .wv-md-code   { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; letter-spacing: -0.5px; padding: 0 4px; }
.wv-md-toolbar .wv-md-codeblock { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; padding: 0 4px; background: #ececec; }
.wv-md-toolbar .wv-md-codeblock:hover { background: #d1d5da; }
.wv-md-toolbar .wv-md-link   { text-decoration: underline; color: #0366d6; font-size: 12px; padding: 0 6px; }
.wv-md-toolbar .wv-md-image  { font-family: ui-monospace, monospace; font-size: 11px; padding: 0 4px; }
.wv-md-toolbar .wv-md-ul     { font-size: 18px; line-height: 0.6; padding-bottom: 4px; }
.wv-md-toolbar .wv-md-ol     { font-family: ui-monospace, monospace; font-size: 12px; font-weight: 700; padding: 0 4px; }
.wv-md-toolbar .wv-md-task   { font-size: 14px; }
.wv-md-toolbar .wv-md-hr     { font-size: 22px; line-height: 0.4; letter-spacing: -3px; padding: 0 6px; color: #6a737d; }
.wv-md textarea { width: 100%; min-height: 180px; max-height: 480px; padding: 10px 12px; border: none; outline: none; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.55; resize: vertical; background: #fff; color: #24292e; box-sizing: border-box; }
.wv-md textarea:focus { background: #fff; }
.wv-md.wv-md-tall textarea { min-height: 320px; }

/* --- Dark mode --- */
html[data-theme="dark"] .wv-disclaimer { background: #3d2f0f; border-color: #8a6d2e; border-left-color: #d69e2e; color: #f5d97a; }
html[data-theme="dark"] .wv-disclaimer strong { color: #ffd966; }
html[data-theme="dark"] .wv-disclaimer i { color: #f0c36d; }
html[data-theme="dark"] .wv-variant-banner { background: #1e3a5f; border-color: #2c5282; border-left-color: #63b3ed; color: #bee3f8; }
html[data-theme="dark"] .wv-variant-banner i { color: #63b3ed; }
html[data-theme="dark"] .wv-variant-banner a { color: #a5b4fc; }
html[data-theme="dark"] .wv-builder .wv-tabs { border-bottom-color: #4a5568; }
html[data-theme="dark"] .wv-builder .wv-tab { background: #2d3748; border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .wv-builder .wv-tab.wv-active { background: #1f2937; color: #e2e8f0; border-bottom-color: #1f2937; }
html[data-theme="dark"] .wv-builder .wv-pane { background: #1f2937; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-field > label { color: #e2e8f0; }
html[data-theme="dark"] .wv-builder input[type=text],
html[data-theme="dark"] .wv-builder input[type=number] { background: #374151; border-color: #4a5568; color: #e2e8f0; }
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
html[data-theme="dark"] .wv-builder .wv-cfe-options textarea { background: #1f2937; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-builder .wv-status-ok { color: #68d391; }
html[data-theme="dark"] .wv-builder .wv-status-err { color: #fc8181; }
html[data-theme="dark"] .wv-md { border-color: #4a5568; }
html[data-theme="dark"] .wv-md-toolbar { background: #2d3748; border-bottom-color: #4a5568; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-btn { color: #e2e8f0; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-btn:hover { background: #4a5568; border-color: #4a5568; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-sep { background: #4a5568; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-link      { color: #a5b4fc; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-codeblock { background: #1a202c; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-codeblock:hover { background: #2d3748; }
html[data-theme="dark"] .wv-md-toolbar .wv-md-hr        { color: #a0aec0; }
html[data-theme="dark"] .wv-md textarea { background: #1a202c; color: #e2e8f0; }
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
		<div class="wv-variant-banner" role="status">
			<i class="fas fa-flask"></i>
			<div><strong>Variant B &mdash; Markdown</strong> &middot; Authoring with Markdown syntax and a GitHub-style toolbar. <a href="<?= UIR ?>Waiver/builder/<?= $kingdomId ?>/a">Switch to Rich Text variant &rarr;</a></div>
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
			<input type="hidden" name="Variant" value="b">
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
			</div>

			<div class="wv-sect-tabs" role="tablist">
				<div class="wv-sect-tab wv-active" data-sect="data">Data Capture</div>
				<div class="wv-sect-tab"            data-sect="layout">Page Layout</div>
				<div class="wv-sect-tab"            data-sect="body">Waiver Body</div>
			</div>

			<!-- Data Capture -->
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

			<!-- Page Layout -->
			<div class="wv-sect-pane" data-sect="layout">
				<div class="wv-field">
					<label>Header (shown on every page)</label>
					<div class="wv-md">
						<div class="wv-md-toolbar" data-target="HeaderMarkdown"></div>
						<textarea name="HeaderMarkdown" placeholder="Title, organization name, intro…"><?= htmlspecialchars($tpl['HeaderMarkdown'] ?? '') ?></textarea>
					</div>
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
					<div class="wv-md">
						<div class="wv-md-toolbar" data-target="FooterMarkdown"></div>
						<textarea name="FooterMarkdown" placeholder="Copyright, page footer, contact info…"><?= htmlspecialchars($tpl['FooterMarkdown'] ?? '') ?></textarea>
					</div>
				</div>
			</div>

			<!-- Waiver Body -->
			<div class="wv-sect-pane" data-sect="body">
				<div class="wv-field">
					<label>Waiver Details (the body of the waiver)</label>
					<div class="wv-md wv-md-tall">
						<div class="wv-md-toolbar" data-target="BodyMarkdown"></div>
						<textarea name="BodyMarkdown" placeholder="Assumption of risk, terms, indemnity…"><?= htmlspecialchars($tpl['BodyMarkdown'] ?? '') ?></textarea>
					</div>
				</div>
				<div class="wv-field">
					<label>Minor Representative Text (shown when signer marks as minor)</label>
					<div class="wv-md">
						<div class="wv-md-toolbar" data-target="MinorMarkdown"></div>
						<textarea name="MinorMarkdown" placeholder="Guardian acknowledgement, minor-specific terms…"><?= htmlspecialchars($tpl['MinorMarkdown'] ?? '') ?></textarea>
					</div>
				</div>
			</div>
		</form>
	</div>
	<?php endforeach; ?>
</div>
<script>
window.WvBuilderConfig = { token: "<?= $token ?>", kingdomId: <?= $kingdomId ?> };
</script>
<script>
(function(){
	if (!window.WvBuilderConfig || !window.WvBuilderConfig.token) return;

	// --- GH-style markdown toolbar ---
	// Each .wv-md-toolbar is rendered next to a textarea sibling.
	// label may be plain text or trusted markup (e.g. "H<sub>1</sub>").
	const buttons = [
		{ k: 'h1',     label: 'H<sub>1</sub>', cls: 'wv-md-h1',     title: 'Heading 1 (# )',     kind: 'line', prefix: '# ' },
		{ k: 'h2',     label: 'H<sub>2</sub>', cls: 'wv-md-h2',     title: 'Heading 2 (## )',    kind: 'line', prefix: '## ' },
		{ k: 'h3',     label: 'H<sub>3</sub>', cls: 'wv-md-h3',     title: 'Heading 3 (### )',   kind: 'line', prefix: '### ' },
		{ k: '_sep' },
		{ k: 'bold',   label: 'B',     cls: 'wv-md-bold',   title: 'Bold (Ctrl+B)',     kind: 'wrap', wrap: '**' },
		{ k: 'italic', label: 'I',     cls: 'wv-md-italic', title: 'Italic (Ctrl+I)',   kind: 'wrap', wrap: '*' },
		{ k: 'strike', label: 'S',     cls: 'wv-md-strike', title: 'Strikethrough',     kind: 'wrap', wrap: '~~' },
		{ k: '_sep' },
		{ k: 'quote',  label: '\u201c', cls: 'wv-md-quote',  title: 'Blockquote',        kind: 'line', prefix: '> ' },
		{ k: 'code',   label: '&lt;/&gt;', cls: 'wv-md-code', title: 'Inline code (\u0060\u0060)', kind: 'wrap', wrap: '\u0060' },
		{ k: 'codeblk',label: '{ }',   cls: 'wv-md-codeblock', title: 'Code block',     kind: 'block', open: '\u0060\u0060\u0060\n', close: '\n\u0060\u0060\u0060' },
		{ k: '_sep' },
		{ k: 'link',   label: 'link',  cls: 'wv-md-link',   title: 'Link (Ctrl+K)',     kind: 'link' },
		{ k: 'image',  label: 'img',   cls: 'wv-md-image',  title: 'Image',             kind: 'image' },
		{ k: '_sep' },
		{ k: 'ul',     label: '\u2022', cls: 'wv-md-ul',     title: 'Bulleted list',     kind: 'line', prefix: '- ' },
		{ k: 'ol',     label: '1.',    cls: 'wv-md-ol',     title: 'Numbered list',     kind: 'line-num' },
		{ k: 'task',   label: '\u2610', cls: 'wv-md-task',   title: 'Task list',         kind: 'line', prefix: '- [ ] ' },
		{ k: '_sep' },
		{ k: 'hr',     label: '\u2014\u2014', cls: 'wv-md-hr', title: 'Horizontal rule', kind: 'hr' },
	];
	function renderToolbar(bar) {
		buttons.forEach(b => {
			if (b.k === '_sep') {
				const s = document.createElement('span');
				s.className = 'wv-md-sep';
				bar.appendChild(s);
				return;
			}
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'wv-md-btn ' + (b.cls || '');
			btn.innerHTML = b.label; // trusted: static constants above
			btn.title = b.title;
			btn.dataset.k = b.k;
			btn.addEventListener('click', () => applyAction(bar, b));
			bar.appendChild(btn);
		});
	}
	function getTextarea(bar) {
		// textarea is the next sibling inside .wv-md container
		return bar.parentElement.querySelector('textarea');
	}
	function getSelection(ta) {
		return { start: ta.selectionStart, end: ta.selectionEnd, value: ta.value };
	}
	function setSelection(ta, start, end) {
		ta.focus();
		ta.setSelectionRange(start, end);
		// Fire input event so any listeners (none today) see the change.
		ta.dispatchEvent(new Event('input', { bubbles: true }));
	}
	function applyWrap(ta, marker) {
		const { start, end, value } = getSelection(ta);
		const selected = value.slice(start, end) || 'text';
		const before = value.slice(0, start);
		const after  = value.slice(end);
		ta.value = before + marker + selected + marker + after;
		setSelection(ta, start + marker.length, start + marker.length + selected.length);
	}
	function applyLine(ta, prefix) {
		let { start, end, value } = getSelection(ta);
		// Expand selection to whole lines.
		const lineStart = value.lastIndexOf('\n', start - 1) + 1;
		const lineEnd   = (value.indexOf('\n', end) === -1) ? value.length : value.indexOf('\n', end);
		const block = value.slice(lineStart, lineEnd);
		const newBlock = block.split('\n').map(l => prefix + l).join('\n');
		ta.value = value.slice(0, lineStart) + newBlock + value.slice(lineEnd);
		setSelection(ta, lineStart, lineStart + newBlock.length);
	}
	function applyLineNum(ta) {
		let { start, end, value } = getSelection(ta);
		const lineStart = value.lastIndexOf('\n', start - 1) + 1;
		const lineEnd   = (value.indexOf('\n', end) === -1) ? value.length : value.indexOf('\n', end);
		const block = value.slice(lineStart, lineEnd);
		const newBlock = block.split('\n').map((l, i) => (i + 1) + '. ' + l).join('\n');
		ta.value = value.slice(0, lineStart) + newBlock + value.slice(lineEnd);
		setSelection(ta, lineStart, lineStart + newBlock.length);
	}
	function applyLink(ta) {
		const { start, end, value } = getSelection(ta);
		const selected = value.slice(start, end) || 'link text';
		const url = window.prompt('Link URL (http/https/mailto):', 'https://');
		if (!url) return;
		const replacement = '[' + selected + '](' + url + ')';
		ta.value = value.slice(0, start) + replacement + value.slice(end);
		setSelection(ta, start + 1, start + 1 + selected.length);
	}
	function applyBlock(ta, open, close) {
		const { start, end, value } = getSelection(ta);
		const selected = value.slice(start, end) || 'code';
		ta.value = value.slice(0, start) + open + selected + close + value.slice(end);
		setSelection(ta, start + open.length, start + open.length + selected.length);
	}
	function applyImage(ta) {
		const { start, end, value } = getSelection(ta);
		const alt = value.slice(start, end) || 'alt text';
		const url = window.prompt('Image URL (http/https):', 'https://');
		if (!url) return;
		const replacement = '![' + alt + '](' + url + ')';
		ta.value = value.slice(0, start) + replacement + value.slice(end);
		setSelection(ta, start + 2, start + 2 + alt.length);
	}
	function applyHr(ta) {
		const { start, end, value } = getSelection(ta);
		// Insert a fenced HR on its own paragraph. Skip extra newlines if already at a blank line.
		const before = value.slice(0, start);
		const after  = value.slice(end);
		const lead   = before.endsWith('\n\n') ? '' : (before.endsWith('\n') || before === '') ? '\n' : '\n\n';
		const trail  = after.startsWith('\n\n') ? '' : after.startsWith('\n') ? '\n' : '\n\n';
		const insertion = lead + '---' + trail;
		ta.value = before + insertion + after;
		const cursor = start + insertion.length;
		setSelection(ta, cursor, cursor);
	}
	function applyAction(bar, b) {
		const ta = getTextarea(bar);
		if (!ta) return;
		if (b.kind === 'wrap')          applyWrap(ta, b.wrap);
		else if (b.kind === 'line')     applyLine(ta, b.prefix);
		else if (b.kind === 'line-num') applyLineNum(ta);
		else if (b.kind === 'link')     applyLink(ta);
		else if (b.kind === 'block')    applyBlock(ta, b.open, b.close);
		else if (b.kind === 'image')    applyImage(ta);
		else if (b.kind === 'hr')       applyHr(ta);
	}
	document.querySelectorAll('.wv-md-toolbar').forEach(renderToolbar);

	// Keyboard shortcuts on each textarea
	document.querySelectorAll('.wv-md textarea').forEach(ta => {
		ta.addEventListener('keydown', (e) => {
			if (!(e.metaKey || e.ctrlKey)) return;
			const key = e.key.toLowerCase();
			if (key === 'b') { e.preventDefault(); applyWrap(ta, '**'); }
			else if (key === 'i') { e.preventDefault(); applyWrap(ta, '*'); }
			else if (key === 'k') { e.preventDefault(); applyLink(ta); }
		});
	});

	// --- Scope tabs ---
	const scopeTabs  = document.querySelectorAll('.wv-builder > .wv-tabs > .wv-tab');
	const scopePanes = document.querySelectorAll('.wv-builder > .wv-pane');
	scopeTabs.forEach(t => t.addEventListener('click', () => {
		scopeTabs.forEach(x => x.classList.remove('wv-active'));
		t.classList.add('wv-active');
		scopePanes.forEach(p => p.style.display = (p.dataset.scope === t.dataset.scope) ? '' : 'none');
	}));

	// --- Section sub-tabs ---
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
		// Custom Fields Editor (identical to variant A)
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
				+ '<span class="wv-cfe-grab" title="Drag to reorder">⋮⋮</span>'
				+ '<input type="text" name="cfe_label" placeholder="Field label" value="' + (entry.label || '').replace(/"/g,'&quot;') + '">'
				+ '<select name="cfe_type">' + typeOpts.map(tp => '<option value="' + tp + '"' + (tp === entry.type ? ' selected' : '') + '>' + tp + '</option>').join('') + '</select>'
				+ '<label><input type="checkbox" name="cfe_req"' + (entry.required ? ' checked' : '') + '> req</label>'
				+ '<input type="text" name="cfe_id" placeholder="id" value="' + (entry.id || '').replace(/"/g,'&quot;') + '" size="10">'
				+ '<button type="button" class="wv-cfe-del" title="Delete">×</button>'
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

		// Save
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			syncHidden();
			const fd = new FormData(form);
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
				} else {
					status.className = 'wv-status wv-status-err';
					status.textContent = j.error || 'Save failed';
				}
			} catch (err) {
				status.className = 'wv-status wv-status-err';
				status.textContent = 'Network error';
			}
		});

		// Preview (POST to variant-B preview route in a new tab)
		const previewBtn = form.querySelector('.wv-btn-preview');
		previewBtn.addEventListener('click', () => {
			syncHidden();
			const fd = new FormData(form);
			const ghost = document.createElement('form');
			ghost.method = 'POST';
			ghost.action = '<?= UIR ?>Waiver/preview/<?= $kingdomId ?>/b';
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
})();
</script>
