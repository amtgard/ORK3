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
.wv-builder .wv-fields-pane { background: #fafcff; border: 1px solid #d4e0ee; border-radius: 6px; padding: 14px; margin-bottom: 14px; }
.wv-builder .wv-fields-pane h3 { margin: 0 0 6px 0; font-size: 16px; }
.wv-builder .wv-hint { font-size: 12px; color: #666; margin: 0 0 10px 0; }
.wv-builder .wv-dem-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 8px 12px; margin-bottom: 10px; }
.wv-builder .wv-dem-grid label { font-weight: normal; font-size: 13px; }
.wv-builder .wv-max-minors-field input[type=number] { width: 80px; }
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

		// --- Custom Fields Editor ---
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
			const rows = [...cfe.querySelectorAll('.wv-cfe-row')];
			return rows.map(r => {
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
		function syncHidden() {
			cfeHidden.value = JSON.stringify(collectState());
		}
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
			// Drag to reorder: HTML5 DnD
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
	});
})();
</script>
