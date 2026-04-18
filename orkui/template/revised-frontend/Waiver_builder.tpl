<?php
$wv = $this->data['_wv'];
$ki = $this->data['kingdom_info']['KingdomInfo'] ?? [];
$kingdomName = htmlspecialchars($ki['KingdomName'] ?? 'Kingdom');
$token       = htmlspecialchars($wv['token']);
$kingdomId   = (int)$wv['kingdom_id'];
$kk = $wv['kingdom_template'];
$pk = $wv['park_template'];
?>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
.wv-builder { max-width: 1200px; margin: 20px auto; padding: 0 16px; }
.wv-builder h1, .wv-builder h2, .wv-builder h3, .wv-builder h4, .wv-builder h5, .wv-builder h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-builder h1 { font-size: 28px; margin-bottom: 4px; }
.wv-builder .wv-tabs { display: flex; gap: 4px; margin: 20px 0 0 0; border-bottom: 2px solid #ccc; }
.wv-builder .wv-tab { padding: 10px 18px; cursor: pointer; background: #eee; border: 1px solid #ccc; border-bottom: none; border-radius: 6px 6px 0 0; }
.wv-builder .wv-tab.wv-active { background: #fff; font-weight: bold; border-bottom: 2px solid #fff; margin-bottom: -2px; }
.wv-builder .wv-pane { background: #fff; padding: 16px; border: 1px solid #ccc; border-top: none; }
.wv-builder .wv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.wv-builder .wv-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.wv-builder .wv-field label { font-weight: bold; font-size: 13px; color: #333; }
.wv-builder textarea { width: 100%; min-height: 120px; font-family: monospace; font-size: 13px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
.wv-builder .wv-preview { border: 1px solid #ddd; padding: 12px; border-radius: 4px; background: #f9f9f9; min-height: 120px; font-size: 14px; }
.wv-builder .wv-preview h1, .wv-builder .wv-preview h2, .wv-builder .wv-preview h3, .wv-builder .wv-preview h4, .wv-builder .wv-preview h5, .wv-builder .wv-preview h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-builder .wv-save-bar { display: flex; align-items: center; gap: 12px; margin: 10px 0; padding: 10px; background: #f4f4f4; border: 1px solid #ccc; border-radius: 4px; }
.wv-builder .wv-save-bar button { padding: 8px 20px; font-weight: bold; cursor: pointer; }
.wv-builder .wv-enable { margin-left: auto; }
.wv-builder .wv-locked { background: #f0f0f0; border: 1px dashed #999; padding: 12px; border-radius: 4px; color: #555; font-size: 13px; }
.wv-builder .wv-version-label { font-size: 12px; color: #666; }
.wv-builder .wv-status-ok   { color: #060; font-weight: bold; }
.wv-builder .wv-status-err  { color: #a00; font-weight: bold; }
</style>
<div class="wv-builder">
	<h1><?= $kingdomName ?> &mdash; Digital Waiver Builder</h1>
	<p>Design waivers used at kingdom events and at park days. Kingdom admins only.</p>

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
				<button type="submit">Save &amp; Publish New Version</button>
				<span class="wv-version-label">Current: <?= $tpl ? 'v' . (int)$tpl['Version'] : '(none)' ?></span>
				<label class="wv-enable">
					<input type="checkbox" name="IsEnabled" value="1" <?= ($tpl && (int)$tpl['IsEnabled'] === 1) ? 'checked' : '' ?>>
					Enabled (players can sign)
				</label>
				<span class="wv-status"></span>
			</div>

			<div class="wv-grid">
				<div>
					<div class="wv-field">
						<label>Header (shown on every page &mdash; markdown)</label>
						<textarea name="HeaderMarkdown" rows="3"><?= htmlspecialchars($tpl['HeaderMarkdown'] ?? '') ?></textarea>
					</div>
					<div class="wv-field">
						<label>Player Header (fixed &mdash; not editable)</label>
						<div class="wv-locked">Fields: First Name, Last Name, Persona Name, Home Park, Home Kingdom. Auto-filled from the signing player's profile.</div>
					</div>
					<div class="wv-field">
						<label>Waiver Details (body &mdash; markdown)</label>
						<textarea name="BodyMarkdown" rows="14"><?= htmlspecialchars($tpl['BodyMarkdown'] ?? '') ?></textarea>
					</div>
					<div class="wv-field">
						<label>Signature Block (fixed &mdash; not editable)</label>
						<div class="wv-locked">Players choose drawn (finger/mouse) or typed signature. Date auto-recorded at submission.</div>
					</div>
					<div class="wv-field">
						<label>Minor Representative Text (markdown &mdash; shown when signer marks as minor)</label>
						<textarea name="MinorMarkdown" rows="4"><?= htmlspecialchars($tpl['MinorMarkdown'] ?? '') ?></textarea>
					</div>
					<div class="wv-field">
						<label>Footer (shown on every page &mdash; markdown)</label>
						<textarea name="FooterMarkdown" rows="3"><?= htmlspecialchars($tpl['FooterMarkdown'] ?? '') ?></textarea>
					</div>
				</div>
				<div>
					<label><strong>Live Preview</strong></label>
					<div class="wv-preview wv-preview-header"></div>
					<hr>
					<div class="wv-preview wv-preview-body"></div>
					<hr>
					<div class="wv-preview wv-preview-minor"></div>
					<hr>
					<div class="wv-preview wv-preview-footer"></div>
				</div>
			</div>
		</form>
	</div>
	<?php endforeach; ?>
</div>
<script>
window.WvBuilderConfig = { token: "<?= $token ?>" };
</script>
<script>
(function(){
	if (!window.WvBuilderConfig || !window.WvBuilderConfig.token) return; // admin gate
	const tabs = document.querySelectorAll('.wv-builder .wv-tab');
	const panes = document.querySelectorAll('.wv-builder .wv-pane');
	tabs.forEach(t => t.addEventListener('click', () => {
		tabs.forEach(x => x.classList.remove('wv-active'));
		t.classList.add('wv-active');
		panes.forEach(p => p.style.display = (p.dataset.scope === t.dataset.scope) ? '' : 'none');
	}));

	function debounce(fn, ms) { let id; return function(...args) { clearTimeout(id); id = setTimeout(() => fn.apply(this, args), ms); }; }

	async function renderPreview(form) {
		const headerTA = form.querySelector('[name=HeaderMarkdown]');
		const bodyTA   = form.querySelector('[name=BodyMarkdown]');
		const minorTA  = form.querySelector('[name=MinorMarkdown]');
		const footerTA = form.querySelector('[name=FooterMarkdown]');
		const pane = form.closest('.wv-pane');
		const pv = { h: pane.querySelector('.wv-preview-header'), b: pane.querySelector('.wv-preview-body'), m: pane.querySelector('.wv-preview-minor'), f: pane.querySelector('.wv-preview-footer') };
		const parts = [[headerTA.value, pv.h], [bodyTA.value, pv.b], [minorTA.value, pv.m], [footerTA.value, pv.f]];
		for (const [md, el] of parts) {
			const fd = new FormData(); fd.append('Markdown', md);
			const r = await fetch('<?= UIR ?>WaiverAjax/previewMarkdown', { method: 'POST', body: fd, credentials: 'same-origin' });
			const j = await r.json();
			el.innerHTML = j.Html || '';
		}
	}

	const debouncedRender = debounce(renderPreview, 350);

	document.querySelectorAll('.wv-builder .wv-form').forEach(form => {
		form.addEventListener('input', () => debouncedRender(form));
		renderPreview(form);
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
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
	});
})();
</script>
