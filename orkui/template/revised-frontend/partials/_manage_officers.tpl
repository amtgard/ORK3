<?php
/* =====================================================================
   Manage Officers — reusable, page-agnostic partial
   ---------------------------------------------------------------------
   INCLUDE CONTRACT (set these PHP locals before including):
     $mo_kingdom_id (int)  — the kingdom whose officer positions to manage
     $mo_can_manage (bool) — must be truthy or this partial renders nothing
   The partial is self-contained: it emits its own CSS, its own JS module,
   its own window.MoConfig, and exposes window.moRefresh() to (re)load and
   render the officer cards into the #mo-cards container. Designed to be
   dropped inside a modal body on ANY revised-frontend page.
   ===================================================================== */
if (empty($mo_can_manage)) return;
$mo_kingdom_id = (int)($mo_kingdom_id ?? 0);
?>

<!-- ============ Manage Officers — UI ============ -->
<div class="mo-root">
	<div class="mo-toolbar">
		<button class="kn-btn kn-btn-primary" onclick="moOpenCreate()">
			<i class="fas fa-plus"></i> Create Position
		</button>
		<button class="mo-retired-toggle" id="mo-retired-toggle" onclick="moToggleRetired()" style="display:none">
			<i class="fas fa-archive"></i> Retired Positions (<span id="mo-retired-count">0</span>)
			<i class="fas fa-chevron-down mo-retired-caret" id="mo-retired-caret"></i>
		</button>
	</div>
	<div id="mo-loading" style="text-align:center;padding:24px;color:var(--ork-text-secondary,#a0aec0)">
		<i class="fas fa-spinner fa-spin"></i> Loading positions...
	</div>
	<div id="mo-error" class="mo-loaderr" style="display:none"></div>
	<div id="mo-cards" style="display:none">
		<div class="mo-group" id="mo-group-crown">
			<h4 class="mo-group-title"><i class="fas fa-crown" style="color:#d69e2e"></i> Crown Offices</h4>
			<div class="mo-cards-grid" id="mo-cards-crown"></div>
		</div>
		<div class="mo-group" id="mo-group-supporting">
			<h4 class="mo-group-title"><i class="fas fa-users"></i> Supporting Offices</h4>
			<div class="mo-cards-grid" id="mo-cards-supporting"></div>
		</div>
		<div class="mo-retired-panel" id="mo-retired-panel" style="display:none">
			<h4 class="mo-group-title"><i class="fas fa-archive"></i> Retired Positions</h4>
			<div class="mo-cards-grid" id="mo-cards-retired"></div>
		</div>
	</div>
</div>

<!-- ============ Manage Officers — Sub-modals (z-index >= 9000 to layer above host modal) ============ -->

<!-- Create/Edit Position Modal -->
<div id="mo-pos-overlay" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,0.45);align-items:center;justify-content:center">
	<div class="mo-modal-box" style="width:560px;max-width:calc(100vw - 40px)">
		<div class="mo-modal-header">
			<h3 class="mo-modal-title"><i class="fas fa-user-shield" style="margin-right:8px;color:#2b6cb0"></i><span id="mo-pos-title">Create Position</span></h3>
			<button class="mo-modal-close-btn" onclick="moClosePos()">&times;</button>
		</div>
		<div class="mo-modal-body" style="overflow:visible">
			<div class="mo-form-error" id="mo-pos-error" style="display:none"></div>
			<input type="hidden" id="mo-pos-id" value="" />

			<div class="mo-field">
				<label>Title <span style="color:#e53e3e">*</span></label>
				<input type="text" id="mo-pos-title-input" placeholder="e.g. Knight Marshal" autocomplete="off" />
			</div>

			<div class="mo-field">
				<label>Display Alias <span class="mo-muted">(optional &mdash; what members see instead of the official title)</span></label>
				<input type="text" id="mo-pos-alias" placeholder="Leave blank to use the official title" autocomplete="off" />
			</div>

			<div class="mo-field">
				<label>Classification</label>
				<div class="mo-seg" id="mo-pos-class-seg">
					<button type="button" class="mo-seg-btn mo-seg-active" data-class="crown" onclick="moSetClass('crown')">Crown</button>
					<button type="button" class="mo-seg-btn" data-class="supporting" onclick="moSetClass('supporting')">Supporting</button>
				</div>
				<div class="mo-pinned-note" id="mo-pos-class-lock" style="display:none">
					<i class="fas fa-lock"></i> Core office &mdash; classification is locked to Crown.
				</div>
			</div>

			<div class="mo-field" id="mo-pos-hidevac-wrap">
				<label class="mo-check-label"><input type="checkbox" id="mo-pos-hidevac" /> Hide this office when vacant <span class="mo-muted">(non-Crown only &mdash; empty office is hidden from public displays)</span></label>
			</div>

			<div class="mo-field">
				<label>Reports To <span class="mo-muted">(optional &mdash; this office reports to / is a deputy of)</span></label>
				<select id="mo-pos-parent"><option value="">&mdash; None (top-level) &mdash;</option></select>
			</div>

			<div class="mo-field">
				<label>Permissions</label>
				<div class="mo-seg" id="mo-pos-rbac-seg">
					<button type="button" class="mo-seg-btn mo-seg-active" data-rbac="existing" onclick="moSetRbacMode('existing')">Use existing role</button>
					<button type="button" class="mo-seg-btn" data-rbac="custom" onclick="moSetRbacMode('custom')">Build custom set</button>
					<button type="button" class="mo-seg-btn" data-rbac="none" onclick="moSetRbacMode('none')">None &mdash; no extra access</button>
				</div>
			</div>

			<div class="mo-field" id="mo-pos-none-wrap" style="display:none">
				<div class="mo-muted" style="padding:4px 0">This office gets no special permissions &mdash; it is recorded and displayed only.</div>
			</div>

			<div class="mo-field" id="mo-pos-role-wrap">
				<label>Role</label>
				<select id="mo-pos-role"><option value="">Loading roles...</option></select>
				<div class="mo-role-desc" id="mo-pos-role-desc"></div>
			</div>

			<div class="mo-field" id="mo-pos-perm-wrap" style="display:none">
				<label>Permissions in custom set</label>
				<div class="mo-perm-grid" id="mo-pos-perm-grid">
					<div class="mo-muted" style="padding:8px">Loading permissions...</div>
				</div>
			</div>
		</div>
		<div class="mo-modal-footer">
			<button class="kn-btn kn-btn-secondary" onclick="moClosePos()">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="mo-pos-save-btn" onclick="moSavePos()">
				<i class="fas fa-save" style="margin-right:4px"></i> Save Position
			</button>
		</div>
	</div>
</div>

<!-- Set Occupant Modal -->
<div id="mo-occ-overlay" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,0.45);align-items:center;justify-content:center">
	<div class="mo-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="mo-modal-header">
			<h3 class="mo-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Set Occupant &mdash; <span id="mo-occ-title"></span></h3>
			<button class="mo-modal-close-btn" onclick="moCloseOcc()">&times;</button>
		</div>
		<div class="mo-modal-body" style="overflow:visible">
			<div class="mo-form-error" id="mo-occ-error" style="display:none"></div>
			<input type="hidden" id="mo-occ-pos-id" value="" />

			<div class="mo-field" style="position:relative">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="mo-occ-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="mo-occ-player-id" value="" />
				<div class="kn-ac-results" id="mo-occ-player-results" style="position:fixed"></div>
			</div>

			<div style="display:flex;gap:12px">
				<div class="mo-field" style="flex:1">
					<label>Term Start <span style="color:#e53e3e">*</span></label>
					<input type="text" id="mo-occ-start" autocomplete="off" />
				</div>
				<div class="mo-field" style="flex:1">
					<label>Term End <span class="mo-muted">(optional)</span></label>
					<input type="text" id="mo-occ-end" autocomplete="off" />
				</div>
			</div>

			<div class="mo-field">
				<label>Note <span class="mo-muted">(optional)</span></label>
				<textarea id="mo-occ-note" rows="2" maxlength="500" placeholder="e.g. Reign 42, appointed mid-term..."></textarea>
			</div>
		</div>
		<div class="mo-modal-footer">
			<button class="kn-btn kn-btn-secondary" onclick="moCloseOcc()">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="mo-occ-save-btn" onclick="moSaveOcc()">
				<i class="fas fa-save" style="margin-right:4px"></i> Set Occupant
			</button>
		</div>
	</div>
</div>

<!-- Confirm (Retire / Vacate) Modal -->
<div id="mo-confirm-overlay" style="display:none;position:fixed;inset:0;z-index:9001;background:rgba(0,0,0,0.45);align-items:center;justify-content:center">
	<div class="mo-modal-box" style="width:460px;max-width:calc(100vw - 40px)">
		<div class="mo-modal-header">
			<h3 class="mo-modal-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#dd6b20"></i><span id="mo-confirm-title">Confirm</span></h3>
			<button class="mo-modal-close-btn" onclick="moCloseConfirm()">&times;</button>
		</div>
		<div class="mo-modal-body">
			<div class="mo-warn-box" id="mo-confirm-body"></div>
		</div>
		<div class="mo-modal-footer">
			<button class="kn-btn kn-btn-secondary" onclick="moCloseConfirm()">Cancel</button>
			<button class="kn-btn kn-btn-danger" id="mo-confirm-ok" onclick="moConfirmGo()">Confirm</button>
		</div>
	</div>
</div>

<style>
/* ============ Manage Officers (partial) ============ */
.mo-toolbar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.mo-retired-toggle {
	margin-left:auto; background:none; border:1px solid #e2e8f0; border-radius:6px;
	padding:7px 12px; font-size:13px; font-weight:600; color:#4a5568; cursor:pointer;
	display:inline-flex; align-items:center; gap:6px;
}
.mo-retired-toggle:hover { background:#f7fafc; }
.mo-retired-caret { transition:transform .15s ease; font-size:11px; }
.mo-retired-toggle.mo-open .mo-retired-caret { transform:rotate(180deg); }
html[data-theme="dark"] .mo-retired-toggle { border-color:var(--ork-border); color:var(--ork-text-secondary); }
html[data-theme="dark"] .mo-retired-toggle:hover { background:var(--ork-bg-tertiary); color:var(--ork-text); }

.mo-loaderr { background:#fff5f5; border:1px solid #fed7d7; border-radius:6px; padding:10px 14px; color:#c53030; font-size:13px; }
html[data-theme="dark"] .mo-loaderr { background:rgba(252,129,129,0.12); border-color:#fc8181; color:#fc8181; }

.mo-group { margin-bottom:24px; }
.mo-group-title {
	font-size:14px; font-weight:700; color:#2d3748; margin:0 0 12px 0;
	display:flex; align-items:center; gap:8px;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
html[data-theme="dark"] .mo-group-title { color:var(--ork-text); }
.mo-cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }

.mo-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px;
	display:flex; flex-direction:column; gap:8px; box-shadow:0 1px 3px rgba(0,0,0,0.06);
}
html[data-theme="dark"] .mo-card { background:var(--ork-card-bg); border-color:var(--ork-border); }
.mo-card.mo-card-crown { border-top:3px solid #d69e2e; }
.mo-card.mo-retired { opacity:0.72; }

.mo-card-head { display:flex; align-items:flex-start; gap:8px; }
.mo-title { font-size:15px; font-weight:700; color:#2d3748; line-height:1.25; flex:1; }
html[data-theme="dark"] .mo-title { color:var(--ork-text); }
.mo-title .mo-crown-glyph { color:#d69e2e; margin-right:5px; }
.mo-title .mo-official { font-size:12px; font-weight:400; color:#718096; }
html[data-theme="dark"] .mo-title .mo-official { color:var(--ork-text-secondary); }
.mo-pinned { color:#a0aec0; font-size:13px; }
html[data-theme="dark"] .mo-pinned { color:var(--ork-text-muted); }

.mo-occupant { font-size:13px; color:#2d3748; }
html[data-theme="dark"] .mo-occupant { color:var(--ork-text); }
.mo-occupant a { color:#2b6cb0; text-decoration:none; }
.mo-occupant a:hover { text-decoration:underline; }
html[data-theme="dark"] .mo-occupant a { color:hsl(210,80%,65%); }
.mo-vacant { font-style:italic; color:#a0aec0; }
html[data-theme="dark"] .mo-vacant { color:var(--ork-text-muted); }
.mo-term { font-size:12px; color:#718096; }
html[data-theme="dark"] .mo-term { color:var(--ork-text-secondary); }

.mo-actions { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
.mo-act-btn {
	background:#edf2f7; border:1px solid #e2e8f0; border-radius:5px; padding:5px 9px;
	font-size:12px; font-weight:600; color:#4a5568; cursor:pointer; display:inline-flex; align-items:center; gap:4px;
}
.mo-act-btn:hover:not(:disabled) { background:#e2e8f0; }
.mo-act-btn:disabled { opacity:0.45; cursor:not-allowed; }
html[data-theme="dark"] .mo-act-btn { background:var(--ork-bg-tertiary); border-color:var(--ork-border); color:var(--ork-text-secondary); }
html[data-theme="dark"] .mo-act-btn:hover:not(:disabled) { background:var(--ork-bg-secondary); color:var(--ork-text); }
.mo-act-danger { color:#c53030; }
html[data-theme="dark"] .mo-act-danger { color:#fc8181; }

/* Reclassify dropdown */
.mo-reclass { position:relative; display:inline-block; }
.mo-reclass-menu {
	display:none; position:absolute; top:100%; left:0; z-index:50; margin-top:4px;
	background:#fff; border:1px solid #e2e8f0; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.12);
	min-width:170px; overflow:hidden;
}
.mo-reclass.mo-open .mo-reclass-menu { display:block; }
.mo-reclass-menu button {
	display:block; width:100%; text-align:left; background:none; border:none;
	padding:8px 12px; font-size:13px; color:#2d3748; cursor:pointer;
}
.mo-reclass-menu button:hover { background:#f7fafc; }
html[data-theme="dark"] .mo-reclass-menu { background:var(--ork-card-bg); border-color:var(--ork-border); }
html[data-theme="dark"] .mo-reclass-menu button { color:var(--ork-text); }
html[data-theme="dark"] .mo-reclass-menu button:hover { background:var(--ork-bg-tertiary); }

.mo-retired-panel { border-top:1px dashed #e2e8f0; padding-top:18px; }
html[data-theme="dark"] .mo-retired-panel { border-top-color:var(--ork-border); }

.mo-muted { color:#a0aec0; font-weight:400; font-size:11px; }
html[data-theme="dark"] .mo-muted { color:var(--ork-text-muted); }

/* Checkbox label (modal) */
.mo-check-label { display:flex !important; align-items:flex-start; gap:8px; font-weight:600; color:#4a5568; cursor:pointer; }
.mo-check-label input[type=checkbox] { margin:2px 0 0 0; flex-shrink:0; }
html[data-theme="dark"] .mo-check-label { color:var(--ork-text-secondary); }

/* Nested / indented officer cards */
.mo-node { display:flex; flex-direction:column; gap:14px; }
.mo-children { display:flex; flex-direction:column; gap:14px; margin-left:22px; padding-left:14px; border-left:2px solid #e2e8f0; margin-top:14px; }
html[data-theme="dark"] .mo-children { border-left-color:var(--ork-border); }
.mo-reports-to { font-size:11px; color:#718096; display:flex; align-items:center; gap:5px; margin-top:-2px; }
.mo-reports-to i { font-size:10px; color:#a0aec0; }
html[data-theme="dark"] .mo-reports-to { color:var(--ork-text-secondary); }
html[data-theme="dark"] .mo-reports-to i { color:var(--ork-text-muted); }

/* Hidden-when-vacant chip */
.mo-chip-hidden {
	align-self:flex-start; display:inline-flex; align-items:center; gap:5px;
	font-size:11px; font-weight:600; color:#718096; background:#edf2f7;
	border:1px solid #e2e8f0; border-radius:10px; padding:2px 8px;
}
.mo-chip-hidden i { font-size:10px; }
html[data-theme="dark"] .mo-chip-hidden { color:var(--ork-text-secondary); background:var(--ork-bg-tertiary); border-color:var(--ork-border); }

/* Segmented controls */
.mo-seg { display:inline-flex; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden; }
.mo-seg-btn {
	background:#fff; border:none; padding:7px 14px; font-size:13px; font-weight:600;
	color:#718096; cursor:pointer; border-right:1px solid #e2e8f0;
}
.mo-seg-btn:last-child { border-right:none; }
.mo-seg-btn.mo-seg-active { background:#2b6cb0; color:#fff; }
.mo-seg-btn:disabled { opacity:0.45; cursor:not-allowed; }
html[data-theme="dark"] .mo-seg { border-color:var(--ork-border); }
html[data-theme="dark"] .mo-seg-btn { background:var(--ork-bg-tertiary); color:var(--ork-text-secondary); border-right-color:var(--ork-border); }
html[data-theme="dark"] .mo-seg-btn.mo-seg-active { background:#3182ce; color:#fff; }

.mo-pinned-note { font-size:12px; color:#718096; margin-top:6px; display:flex; align-items:center; gap:6px; }
html[data-theme="dark"] .mo-pinned-note { color:var(--ork-text-secondary); }

.mo-role-desc { font-size:12px; color:#718096; margin-top:6px; min-height:1em; }
html[data-theme="dark"] .mo-role-desc { color:var(--ork-text-secondary); }

/* Permission grid */
.mo-perm-grid { max-height:240px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; }
html[data-theme="dark"] .mo-perm-grid { border-color:var(--ork-border); background:var(--ork-bg-tertiary); }
.mo-perm-cat { margin-bottom:12px; }
.mo-perm-cat:last-child { margin-bottom:0; }
.mo-perm-cat-title { font-size:12px; font-weight:700; color:#4a5568; text-transform:uppercase; letter-spacing:.03em; margin-bottom:6px; }
html[data-theme="dark"] .mo-perm-cat-title { color:var(--ork-text-secondary); }
.mo-perm-item { display:flex; align-items:center; gap:7px; font-size:13px; color:#2d3748; margin-bottom:4px; cursor:pointer; }
html[data-theme="dark"] .mo-perm-item { color:var(--ork-text); }
.mo-perm-item input { margin:0; }

/* Warning box (confirm modal) */
.mo-warn-box { font-size:14px; color:#2d3748; line-height:1.5; }
html[data-theme="dark"] .mo-warn-box { color:var(--ork-text); }

/* Officer-modal scoped containers */
#mo-pos-overlay .mo-modal-box, #mo-occ-overlay .mo-modal-box, #mo-confirm-overlay .mo-modal-box {
	background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3);
	max-height:90vh; display:flex; flex-direction:column;
}
html[data-theme="dark"] #mo-pos-overlay .mo-modal-box,
html[data-theme="dark"] #mo-occ-overlay .mo-modal-box,
html[data-theme="dark"] #mo-confirm-overlay .mo-modal-box { background:var(--ork-card-bg); }
#mo-pos-overlay .mo-modal-header, #mo-occ-overlay .mo-modal-header, #mo-confirm-overlay .mo-modal-header {
	display:flex; align-items:center; justify-content:space-between;
	padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0;
}
html[data-theme="dark"] #mo-pos-overlay .mo-modal-header,
html[data-theme="dark"] #mo-occ-overlay .mo-modal-header,
html[data-theme="dark"] #mo-confirm-overlay .mo-modal-header { border-bottom-color:var(--ork-border); }
#mo-pos-overlay .mo-modal-title, #mo-occ-overlay .mo-modal-title, #mo-confirm-overlay .mo-modal-title {
	font-size:16px; font-weight:700; color:#2d3748; margin:0;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
html[data-theme="dark"] #mo-pos-overlay .mo-modal-title,
html[data-theme="dark"] #mo-occ-overlay .mo-modal-title,
html[data-theme="dark"] #mo-confirm-overlay .mo-modal-title { color:var(--ork-text); }
#mo-pos-overlay .mo-modal-close-btn, #mo-occ-overlay .mo-modal-close-btn, #mo-confirm-overlay .mo-modal-close-btn {
	background:none; border:none; font-size:22px; color:#a0aec0; cursor:pointer; padding:0 4px;
}
#mo-pos-overlay .mo-modal-close-btn:hover, #mo-occ-overlay .mo-modal-close-btn:hover, #mo-confirm-overlay .mo-modal-close-btn:hover { color:#4a5568; }
html[data-theme="dark"] #mo-pos-overlay .mo-modal-close-btn,
html[data-theme="dark"] #mo-occ-overlay .mo-modal-close-btn,
html[data-theme="dark"] #mo-confirm-overlay .mo-modal-close-btn { color:var(--ork-text-muted); }
#mo-pos-overlay .mo-modal-body, #mo-occ-overlay .mo-modal-body, #mo-confirm-overlay .mo-modal-body {
	padding:20px; overflow-y:auto; flex:1;
}
#mo-pos-overlay .mo-modal-footer, #mo-occ-overlay .mo-modal-footer, #mo-confirm-overlay .mo-modal-footer {
	padding:14px 20px; border-top:1px solid #e2e8f0;
	display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-shrink:0;
}
html[data-theme="dark"] #mo-pos-overlay .mo-modal-footer,
html[data-theme="dark"] #mo-occ-overlay .mo-modal-footer,
html[data-theme="dark"] #mo-confirm-overlay .mo-modal-footer { border-top-color:var(--ork-border); }
#mo-pos-overlay .mo-field, #mo-occ-overlay .mo-field { position:relative; margin-bottom:14px; }
#mo-pos-overlay .mo-field label, #mo-occ-overlay .mo-field label {
	display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:4px;
}
html[data-theme="dark"] #mo-pos-overlay .mo-field label,
html[data-theme="dark"] #mo-occ-overlay .mo-field label { color:var(--ork-text-secondary); }
#mo-pos-overlay .mo-field input[type=text],
#mo-pos-overlay .mo-field select,
#mo-pos-overlay .mo-field textarea,
#mo-occ-overlay .mo-field input[type=text],
#mo-occ-overlay .mo-field textarea {
	width:100%; padding:8px 10px; border:1px solid #e2e8f0; border-radius:6px;
	font-size:14px; color:#2d3748; background:#fff; box-sizing:border-box;
}
html[data-theme="dark"] #mo-pos-overlay .mo-field input[type=text],
html[data-theme="dark"] #mo-pos-overlay .mo-field select,
html[data-theme="dark"] #mo-pos-overlay .mo-field textarea,
html[data-theme="dark"] #mo-occ-overlay .mo-field input[type=text],
html[data-theme="dark"] #mo-occ-overlay .mo-field textarea {
	background:var(--ork-bg-tertiary); border-color:var(--ork-border); color:var(--ork-text);
}
#mo-pos-overlay .mo-field input:focus,
#mo-pos-overlay .mo-field select:focus,
#mo-pos-overlay .mo-field textarea:focus,
#mo-occ-overlay .mo-field input:focus,
#mo-occ-overlay .mo-field textarea:focus {
	outline:none; border-color:#3182ce; box-shadow:0 0 0 2px rgba(49,130,206,0.12);
}
#mo-pos-overlay .mo-form-error, #mo-occ-overlay .mo-form-error {
	background:#fff5f5; border:1px solid #fed7d7; border-radius:6px;
	padding:8px 12px; margin-bottom:12px; color:#c53030; font-size:13px;
}
html[data-theme="dark"] #mo-pos-overlay .mo-form-error,
html[data-theme="dark"] #mo-occ-overlay .mo-form-error { background:rgba(252,129,129,0.12); border-color:#fc8181; color:#fc8181; }
</style>

<!-- ============ Manage Officers — JS module ============ -->
<script>
window.MoConfig = { kingdomId: <?= (int)$mo_kingdom_id ?>, canManage: true, uir: '<?= UIR ?>' };
	if (typeof window.tnFixedAcPosition !== 'function') {
	  window.tnFixedAcPosition = function (input, dropdown) {
	    if (!input || !dropdown) return;
	    var r = input.getBoundingClientRect();
	    dropdown.style.position = 'fixed';
	    dropdown.style.top = (r.bottom + 2) + 'px';
	    dropdown.style.left = r.left + 'px';
	    dropdown.style.width = r.width + 'px';
	    dropdown.style.zIndex = '10001';
	  };
	}

/* Guarded flatpickr loader — don't double-load if already on the page. */
(function() {
	if (!document.querySelector('link[href*="flatpickr"]')) {
		var l = document.createElement('link');
		l.rel = 'stylesheet';
		l.href = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
		document.head.appendChild(l);
	}
	if (!window.flatpickr && !document.querySelector('script[src*="flatpickr"]')) {
		var s = document.createElement('script');
		s.src = 'https://cdn.jsdelivr.net/npm/flatpickr';
		document.head.appendChild(s);
	}
})();

(function() {
	// IIFE guard: config flag, NOT getElementById (external scripts load before modal HTML exists)
	if (!window.MoConfig || !MoConfig.canManage) return;

	var UIR        = MoConfig.uir || '';
	function base() { return UIR + 'OfficerAdminAjax/officer/' + MoConfig.kingdomId + '/'; }
	function searchUrl(q) { return UIR + 'KingdomAjax/playersearch/' + MoConfig.kingdomId + '&q=' + encodeURIComponent(q) + '&scope=own&include_inactive=1'; }

	var moData  = { crown: [], supporting: [], retired: [] };
	var moRoles = null;
	var moPerms = null;
	var moEditId = 0;       // 0 = create mode
	var moPinnedClass = false;
	var moClass = 'crown';
	var moRbacMode = 'existing';
	var moStartFp = null, moEndFp = null;
	var moConfirmFn = null;
	var moRetiredOpen = false;

	function esc(s) {
		if (s === null || s === undefined) return '';
		var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML;
	}
	function fmtDate(s) {
		if (!s) return '';
		var d = new Date(s + 'T00:00:00');
		if (isNaN(d.getTime())) return esc(s);
		var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
		return m[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
	}

	// ---------- Load + render ----------
	function moLoad() {
		var loadingEl = document.getElementById('mo-loading');
		var contentEl = document.getElementById('mo-cards');
		var errorEl   = document.getElementById('mo-error');
		if (!loadingEl) return; // partial not in DOM yet
		loadingEl.style.display = '';
		contentEl.style.display = 'none';
		errorEl.style.display = 'none';
		$.getJSON(base() + 'list', function(resp) {
			loadingEl.style.display = 'none';
			if (!resp || resp.status !== 0) {
				errorEl.textContent = (resp && resp.error) ? resp.error : 'Failed to load positions.';
				errorEl.style.display = '';
				return;
			}
			moData = resp.data || { crown: [], supporting: [], retired: [] };
			moRender();
			contentEl.style.display = '';
		}).fail(function() {
			loadingEl.style.display = 'none';
			errorEl.textContent = 'Network error loading positions.';
			errorEl.style.display = '';
		});
	}
	// Public refresh: (re)load + render the cards into #mo-cards.
	window.moRefresh = moLoad;

	function occupantLine(pos) {
		var occs = [];
		if (pos.Classification === 'crown') {
			if (pos.Occupant && pos.Occupant.MundaneId) occs = [pos.Occupant];
		} else {
			occs = pos.Occupants || [];
		}
		if (!occs.length) return '<div class="mo-occupant"><span class="mo-vacant">(Vacant)</span></div>';
		var html = '';
		for (var i = 0; i < occs.length; i++) {
			var o = occs[i];
			var term = 'Term: ' + (o.TermStart ? fmtDate(o.TermStart) : '?') + ' → ' + (o.TermEnd ? fmtDate(o.TermEnd) : '(current)');
			html += '<div class="mo-occupant"><a href="' + UIR + 'Player/profile/' + o.MundaneId + '">' + esc(o.Persona || 'Unknown') + '</a></div>' +
			        '<div class="mo-term">' + esc(term) + '</div>';
		}
		return html;
	}

	function cardHtml(pos) {
		var isCrown  = pos.Classification === 'crown';
		var isPinned = parseInt(pos.IsPinned, 10) === 1;
		var pid = parseInt(pos.PositionId, 10);
		var filled = isCrown ? !!(pos.Occupant && pos.Occupant.MundaneId) : !!((pos.Occupants || []).length);

		var titleHtml = '';
		if (isCrown) titleHtml += '<i class="fas fa-crown mo-crown-glyph"></i>';
		titleHtml += esc(pos.DisplayTitle || pos.Title);
		// muted official title when alias differs
		if (pos.TitleAlias && pos.Title && pos.TitleAlias !== pos.Title && (pos.DisplayTitle || '') !== (pos.Title || '')) {
			titleHtml += ' <span class="mo-official">(' + esc(pos.Title) + ')</span>';
		}
		var lock = isPinned ? '<span class="mo-pinned" data-tip="Core office — classification and retirement are locked"><i class="fas fa-lock"></i></span>' : '';

		// actions
		var acts = '';
		acts += '<button class="mo-act-btn" onclick="moOpenOcc(' + pid + ')"><i class="fas fa-user-plus"></i> Set Occupant</button>';
		if (filled) {
			acts += '<button class="mo-act-btn" onclick="moVacate(' + pid + ')"><i class="fas fa-user-minus"></i> Vacate</button>';
		}
		acts += '<button class="mo-act-btn" onclick="moOpenEdit(' + pid + ')"><i class="fas fa-pencil-alt"></i> Edit</button>';

		// reclassify dropdown
		if (isPinned) {
			acts += '<button class="mo-act-btn" disabled data-tip="Core office — classification and retirement are locked"><i class="fas fa-exchange-alt"></i> Reclassify</button>';
		} else {
			var target = isCrown ? 'supporting' : 'crown';
			var targetLabel = isCrown ? 'Move to Supporting' : 'Move to Crown';
			acts += '<span class="mo-reclass" id="mo-reclass-' + pid + '">' +
			        '<button class="mo-act-btn" onclick="moToggleReclass(' + pid + ')"><i class="fas fa-exchange-alt"></i> Reclassify</button>' +
			        '<div class="mo-reclass-menu"><button onclick="moReclassify(' + pid + ',\'' + target + '\')">' + targetLabel + '</button></div>' +
			        '</span>';
		}

		// retire
		if (isPinned) {
			acts += '<button class="mo-act-btn mo-act-danger" disabled data-tip="Core office — classification and retirement are locked"><i class="fas fa-archive"></i> Retire</button>';
		} else {
			acts += '<button class="mo-act-btn mo-act-danger" onclick="moRetire(' + pid + ')"><i class="fas fa-archive"></i> Retire</button>';
		}

		// Reports-to caption (when this card has a parent that exists somewhere)
		var reportsTo = '';
		var parentId = parseInt(pos.ParentPositionId || 0, 10);
		if (parentId) {
			var parent = findPos(parentId);
			if (parent) {
				reportsTo = '<div class="mo-reports-to"><i class="fas fa-level-up-alt fa-rotate-90"></i> Reports to ' + esc(parent.DisplayTitle || parent.Title) + '</div>';
			}
		}

		// Hidden-when-vacant chip: supporting + flagged + currently vacant
		var hiddenChip = '';
		if (!isCrown && parseInt(pos.HideWhenVacant || 0, 10) === 1 && !filled) {
			hiddenChip = '<span class="mo-chip-hidden" data-tip="This office is hidden from public displays while vacant"><i class="fas fa-eye-slash"></i> Hidden when vacant</span>';
		}

		return '<div class="mo-card' + (isCrown ? ' mo-card-crown' : '') + '">' +
			'<div class="mo-card-head"><div class="mo-title">' + titleHtml + '</div>' + lock + '</div>' +
			reportsTo +
			occupantLine(pos) +
			hiddenChip +
			'<div class="mo-actions">' + acts + '</div>' +
			'</div>';
	}

	function retiredCardHtml(pos) {
		var pid = parseInt(pos.PositionId, 10);
		var titleHtml = (pos.Classification === 'crown' ? '<i class="fas fa-crown mo-crown-glyph"></i>' : '') + esc(pos.DisplayTitle || pos.Title);
		return '<div class="mo-card mo-retired">' +
			'<div class="mo-card-head"><div class="mo-title">' + titleHtml + '</div></div>' +
			'<div class="mo-term">Retired' + (pos.RetiredAt ? ' ' + esc(fmtDate(String(pos.RetiredAt).substr(0,10))) : '') + '</div>' +
			'<div class="mo-actions"><button class="mo-act-btn" onclick="moReinstate(' + pid + ')"><i class="fas fa-undo"></i> Reinstate</button></div>' +
			'</div>';
	}

	// Build a parent->children tree WITHIN a single group, then render nested.
	// A position whose ParentPositionId is null/0, or whose parent is NOT in this
	// same group's visible set, renders as top-level (never dropped). Recursive.
	function sortBySort(a, b) {
		return (parseInt(a.SortOrder || 0, 10)) - (parseInt(b.SortOrder || 0, 10));
	}
	function renderGroupTree(list) {
		if (!list || !list.length) return '';
		var byId = {};
		list.forEach(function(p) { byId[parseInt(p.PositionId, 10)] = p; });
		var childrenOf = {};   // parentId -> [pos]
		var roots = [];
		list.forEach(function(p) {
			var par = parseInt(p.ParentPositionId || 0, 10);
			if (par && byId[par]) { (childrenOf[par] = childrenOf[par] || []).push(p); }
			else { roots.push(p); }    // top-level, or parent in another group / missing
		});
		roots.sort(sortBySort);
		var seen = {};
		function nodeHtml(pos, depth) {
			var pid = parseInt(pos.PositionId, 10);
			if (seen[pid]) return '';   // cycle guard
			seen[pid] = true;
			var html = '<div class="mo-node">' + cardHtml(pos);
			var kids = (childrenOf[pid] || []).slice().sort(sortBySort);
			if (kids.length && depth < 12) {
				html += '<div class="mo-children">';
				kids.forEach(function(k) { html += nodeHtml(k, depth + 1); });
				html += '</div>';
			}
			html += '</div>';
			return html;
		}
		var out = '';
		roots.forEach(function(r) { out += nodeHtml(r, 0); });
		return out;
	}

	function moRender() {
		var crown = moData.crown || [], supporting = moData.supporting || [], retired = moData.retired || [];
		document.getElementById('mo-cards-crown').innerHTML       = crown.length ? renderGroupTree(crown) : '<div class="mo-muted" style="padding:8px">No crown offices.</div>';
		document.getElementById('mo-cards-supporting').innerHTML  = supporting.length ? renderGroupTree(supporting) : '<div class="mo-muted" style="padding:8px">No supporting offices.</div>';

		var toggle = document.getElementById('mo-retired-toggle');
		if (retired.length) {
			document.getElementById('mo-retired-count').textContent = retired.length;
			document.getElementById('mo-cards-retired').innerHTML = retired.map(retiredCardHtml).join('');
			toggle.style.display = '';
		} else {
			toggle.style.display = 'none';
			moRetiredOpen = false;
			document.getElementById('mo-retired-panel').style.display = 'none';
			toggle.classList.remove('mo-open');
		}
	}

	window.moToggleRetired = function() {
		moRetiredOpen = !moRetiredOpen;
		document.getElementById('mo-retired-panel').style.display = moRetiredOpen ? '' : 'none';
		document.getElementById('mo-retired-toggle').classList.toggle('mo-open', moRetiredOpen);
	};

	window.moToggleReclass = function(pid) {
		var el = document.getElementById('mo-reclass-' + pid);
		if (!el) return;
		var open = el.classList.contains('mo-open');
		document.querySelectorAll('.mo-reclass.mo-open').forEach(function(x){ x.classList.remove('mo-open'); });
		if (!open) el.classList.add('mo-open');
	};
	document.addEventListener('click', function(e) {
		if (!e.target.closest || !e.target.closest('.mo-reclass')) {
			document.querySelectorAll('.mo-reclass.mo-open').forEach(function(x){ x.classList.remove('mo-open'); });
		}
	});

	// ---------- Mutations ----------
	function moPost(action, data, onOk) {
		$.post(base() + action, data, function(resp) {
			if (resp && resp.status === 0) { onOk(resp); }
			else { alert((resp && resp.error) ? resp.error : 'Action failed.'); }
		}, 'json').fail(function() { alert('Network error.'); });
	}

	window.moReclassify = function(pid, cls) {
		document.querySelectorAll('.mo-reclass.mo-open').forEach(function(x){ x.classList.remove('mo-open'); });
		moPost('reclassify', { PositionId: pid, Classification: cls }, function() { moRefresh(); });
	};

	window.moVacate = function(pid) {
		moShowConfirm('Vacate Position', 'This will end the current term and remove the occupant\'s officer permissions for this office. Continue?', 'Vacate', function() {
			moPost('vacate', { PositionId: pid }, function() { moCloseConfirm(); moRefresh(); });
		});
	};

	window.moRetire = function(pid) {
		var pos = findPos(pid);
		var who = '';
		if (pos) {
			if (pos.Classification === 'crown' && pos.Occupant && pos.Occupant.MundaneId) who = pos.Occupant.Persona;
			else if (pos.Occupants && pos.Occupants.length) who = pos.Occupants.map(function(o){ return o.Persona; }).join(', ');
		}
		var dt = pos ? (pos.DisplayTitle || pos.Title) : 'this position';
		var msg = who
			? 'Retiring <strong>' + esc(dt) + '</strong> will end the current term for <strong>' + esc(who) + '</strong> and remove their officer permissions. Continue?'
			: 'Retiring <strong>' + esc(dt) + '</strong> will hide it from pickers, the sidebar, the About panel, and reports. Continue?';
		moShowConfirm('Retire Position', msg, 'Retire', function() {
			moPost('retire', { PositionId: pid }, function() { moCloseConfirm(); moRefresh(); });
		});
	};

	window.moReinstate = function(pid) {
		moPost('reinstate', { PositionId: pid }, function() { moRefresh(); });
	};

	function findPos(pid) {
		var all = (moData.crown||[]).concat(moData.supporting||[]).concat(moData.retired||[]);
		for (var i = 0; i < all.length; i++) { if (parseInt(all[i].PositionId,10) === parseInt(pid,10)) return all[i]; }
		return null;
	}

	// ---------- Confirm modal ----------
	function moShowConfirm(title, bodyHtml, okLabel, fn) {
		document.getElementById('mo-confirm-title').textContent = title;
		document.getElementById('mo-confirm-body').innerHTML = bodyHtml;
		document.getElementById('mo-confirm-ok').textContent = okLabel;
		moConfirmFn = fn;
		document.getElementById('mo-confirm-overlay').style.display = 'flex';
	}
	window.moCloseConfirm = function() { document.getElementById('mo-confirm-overlay').style.display = 'none'; moConfirmFn = null; };
	window.moConfirmGo = function() { if (moConfirmFn) moConfirmFn(); };

	// ---------- Create/Edit Position modal ----------
	function ensureRoles(cb) {
		if (moRoles) { cb(); return; }
		$.getJSON(base() + 'roles', function(resp) {
			moRoles = (resp && resp.status === 0) ? (resp.data || []) : [];
			cb();
		}).fail(function() { moRoles = []; cb(); });
	}
	function ensurePerms(cb) {
		if (moPerms) { cb(); return; }
		$.getJSON(base() + 'permissions', function(resp) {
			moPerms = (resp && resp.status === 0) ? (resp.data || []) : [];
			cb();
		}).fail(function() { moPerms = []; cb(); });
	}

	function renderRoleSelect(selectedId) {
		var sel = document.getElementById('mo-pos-role');
		var opts = '<option value="">Select a role...</option>';
		(moRoles || []).forEach(function(r) {
			opts += '<option value="' + parseInt(r.RoleId,10) + '"' + (parseInt(r.RoleId,10) === parseInt(selectedId||0,10) ? ' selected' : '') + '>' + esc(r.DisplayName || r.Name) + '</option>';
		});
		sel.innerHTML = opts;
		moUpdateRoleDesc();
	}
	window.moUpdateRoleDesc = function() {
		var id = parseInt(document.getElementById('mo-pos-role').value || 0, 10);
		var r = (moRoles || []).filter(function(x){ return parseInt(x.RoleId,10) === id; })[0];
		document.getElementById('mo-pos-role-desc').textContent = (r && r.Description) ? r.Description : '';
	};

	// Populate "Reports To" from the currently-loaded positions (crown + supporting),
	// excluding the position being edited (no self-parenting). selectedId pre-selects.
	function renderParentSelect(selectedId, excludeId) {
		var sel = document.getElementById('mo-pos-parent');
		if (!sel) return;
		var all = (moData.crown || []).concat(moData.supporting || []);
		var opts = '<option value="">\u2014 None (top-level) \u2014</option>';
		all.slice().sort(function(a, b) {
			return (parseInt(a.SortOrder || 0, 10)) - (parseInt(b.SortOrder || 0, 10));
		}).forEach(function(pos) {
			var pid = parseInt(pos.PositionId, 10);
			if (excludeId && pid === parseInt(excludeId, 10)) return; // can't report to itself
			var label = pos.DisplayTitle || pos.Title || ('#' + pid);
			opts += '<option value="' + pid + '"' + (pid === parseInt(selectedId || 0, 10) ? ' selected' : '') + '>' + esc(label) + '</option>';
		});
		sel.innerHTML = opts;
	}

	function renderPermGrid(checkedKeys) {
		checkedKeys = checkedKeys || [];
		var grid = document.getElementById('mo-pos-perm-grid');
		if (!moPerms || !moPerms.length) { grid.innerHTML = '<div class="mo-muted" style="padding:8px">No permissions available.</div>'; return; }
		var cats = {};
		moPerms.forEach(function(p) { var c = p.Category || 'Other'; (cats[c] = cats[c] || []).push(p); });
		var html = '';
		Object.keys(cats).forEach(function(cat) {
			html += '<div class="mo-perm-cat"><div class="mo-perm-cat-title">' + esc(cat) + '</div>';
			cats[cat].forEach(function(p) {
				var ck = checkedKeys.indexOf(p.Key) !== -1 ? ' checked' : '';
				html += '<label class="mo-perm-item"><input type="checkbox" class="mo-perm-cb" value="' + esc(p.Key) + '"' + ck + '> ' + esc(p.DisplayName || p.Key) + '</label>';
			});
			html += '</div>';
		});
		grid.innerHTML = html;
	}

	window.moSetClass = function(cls) {
		if (moPinnedClass) return; // locked to crown
		moClass = cls;
		document.querySelectorAll('#mo-pos-class-seg .mo-seg-btn').forEach(function(b) {
			b.classList.toggle('mo-seg-active', b.getAttribute('data-class') === cls);
		});
		// Hide-when-vacant is supporting-only: hide + force-uncheck when Crown.
		var hvWrap = document.getElementById('mo-pos-hidevac-wrap');
		var hvCb   = document.getElementById('mo-pos-hidevac');
		if (hvWrap && hvCb) {
			if (cls === 'crown') { hvCb.checked = false; hvWrap.style.display = 'none'; }
			else { hvWrap.style.display = ''; }
		}
	};
	window.moSetRbacMode = function(mode) {
		moRbacMode = mode;
		document.querySelectorAll('#mo-pos-rbac-seg .mo-seg-btn').forEach(function(b) {
			b.classList.toggle('mo-seg-active', b.getAttribute('data-rbac') === mode);
		});
		document.getElementById('mo-pos-role-wrap').style.display = mode === 'existing' ? '' : 'none';
		document.getElementById('mo-pos-perm-wrap').style.display = mode === 'custom' ? '' : 'none';
		var noneWrap = document.getElementById('mo-pos-none-wrap');
		if (noneWrap) noneWrap.style.display = mode === 'none' ? '' : 'none';
		if (mode === 'custom' && moPerms === null) ensurePerms(function(){ renderPermGrid([]); });
	};

	function openPosModal() {
		document.getElementById('mo-pos-error').style.display = 'none';
		document.getElementById('mo-pos-overlay').style.display = 'flex';
		document.getElementById('mo-pos-role').onchange = window.moUpdateRoleDesc;
	}

	window.moOpenCreate = function() {
		moEditId = 0;
		moPinnedClass = false;
		document.getElementById('mo-pos-title').textContent = 'Create Position';
		document.getElementById('mo-pos-id').value = '';
		document.getElementById('mo-pos-title-input').value = '';
		document.getElementById('mo-pos-alias').value = '';
		document.getElementById('mo-pos-class-lock').style.display = 'none';
		document.querySelectorAll('#mo-pos-class-seg .mo-seg-btn').forEach(function(b){ b.disabled = false; });
		document.getElementById('mo-pos-hidevac').checked = false;
		renderParentSelect(0, 0);
		moSetClass('crown');
		moSetRbacMode('existing');
		ensureRoles(function() { renderRoleSelect(0); });
		openPosModal();
	};

	window.moOpenEdit = function(pid) {
		var pos = findPos(pid);
		if (!pos) return;
		moEditId = pid;
		moPinnedClass = parseInt(pos.IsPinned, 10) === 1;
		document.getElementById('mo-pos-title').textContent = 'Edit Position';
		document.getElementById('mo-pos-id').value = pid;
		document.getElementById('mo-pos-title-input').value = pos.Title || '';
		document.getElementById('mo-pos-alias').value = pos.TitleAlias || '';

		var lockEl = document.getElementById('mo-pos-class-lock');
		lockEl.style.display = moPinnedClass ? '' : 'none';
		document.querySelectorAll('#mo-pos-class-seg .mo-seg-btn').forEach(function(b){ b.disabled = moPinnedClass; });
		moPinnedClass = false; // allow moSetClass to set initial value
		moSetClass(pos.Classification === 'crown' ? 'crown' : 'supporting');
		moPinnedClass = parseInt(pos.IsPinned, 10) === 1;

		// Reports To: pre-select current parent, exclude self.
		renderParentSelect(parseInt(pos.ParentPositionId || 0, 10), pid);
		// Hide-when-vacant: reflect current value (moSetClass above already
		// forced it off + hidden if Crown).
		var hvCb = document.getElementById('mo-pos-hidevac');
		if (hvCb) hvCb.checked = (pos.Classification !== 'crown' && parseInt(pos.HideWhenVacant || 0, 10) === 1);

		// Pre-select None when the position has no role binding (rbac_role_id=0),
		// except for pinned/system positions (they keep their locked system role).
		var posRid = parseInt(pos.RbacRoleId || 0, 10);
		if (!moPinnedClass && posRid === 0) {
			moSetRbacMode('none');
		} else {
			moSetRbacMode('existing');
		}
		ensureRoles(function() { renderRoleSelect(posRid); });
		openPosModal();
	};

	window.moClosePos = function() { document.getElementById('mo-pos-overlay').style.display = 'none'; };

	window.moSavePos = function() {
		var errEl = document.getElementById('mo-pos-error');
		var title = document.getElementById('mo-pos-title-input').value.trim();
		var alias = document.getElementById('mo-pos-alias').value.trim();
		if (!title) { errEl.textContent = 'Title is required.'; errEl.style.display = ''; return; }
		errEl.style.display = 'none';

		var parentId = parseInt((document.getElementById('mo-pos-parent') || {}).value || 0, 10) || 0;
		var hideVac  = (moClass === 'crown') ? 0 : (document.getElementById('mo-pos-hidevac').checked ? 1 : 0);

		var data = {
			Title: title,
			TitleAlias: alias,                 // '' clears (not null) per yapo rule
			Classification: moClass,
			RbacMode: moRbacMode,
			ParentPositionId: parentId,        // 0 = none / top-level
			HideWhenVacant: hideVac            // crown forced to 0
		};
		if (moRbacMode === 'existing') {
			var rid = parseInt(document.getElementById('mo-pos-role').value || 0, 10);
			if (!rid) { errEl.textContent = 'Please select a role.'; errEl.style.display = ''; return; }
			data.RoleId = rid;
		} else if (moRbacMode === 'none') {
			// None: no RoleId / PermissionKeys; server stores rbac_role_id=0.
		} else {
			var keys = [];
			document.querySelectorAll('.mo-perm-cb:checked').forEach(function(cb){ keys.push(cb.value); });
			if (!keys.length) { errEl.textContent = 'Select at least one permission.'; errEl.style.display = ''; return; }
			data['PermissionKeys'] = keys; // array -> PHP $_POST['PermissionKeys']
		}

		var btn = document.getElementById('mo-pos-save-btn');
		btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		var action = moEditId ? 'editposition' : 'createposition';
		if (moEditId) data.PositionId = moEditId;

		$.post(base() + action, data, function(resp) {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Position';
			if (resp && resp.status === 0) { moClosePos(); moRefresh(); }
			else { errEl.textContent = (resp && resp.error) ? resp.error : 'Failed to save position.'; errEl.style.display = ''; }
		}, 'json').fail(function() {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Position';
			errEl.textContent = 'Network error.'; errEl.style.display = '';
		});
	};

	// ---------- Set Occupant modal ----------
	function initOccFp() {
		if (typeof flatpickr === 'undefined') return;
		var opts = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' };
		if (!moStartFp) moStartFp = flatpickr('#mo-occ-start', opts);
		if (!moEndFp)   moEndFp   = flatpickr('#mo-occ-end', opts);
	}

	window.moOpenOcc = function(pid) {
		var pos = findPos(pid);
		document.getElementById('mo-occ-error').style.display = 'none';
		document.getElementById('mo-occ-pos-id').value = pid;
		document.getElementById('mo-occ-title').textContent = pos ? (pos.DisplayTitle || pos.Title) : '';
		document.getElementById('mo-occ-player-text').value = '';
		document.getElementById('mo-occ-player-id').value = '';
		document.getElementById('mo-occ-note').value = '';
		document.getElementById('mo-occ-overlay').style.display = 'flex';
		initOccFp();
		if (moStartFp) moStartFp.setDate(new Date(), true);
		if (moEndFp)   moEndFp.clear();
		else { document.getElementById('mo-occ-start').value = ''; document.getElementById('mo-occ-end').value = ''; }
	};

	window.moCloseOcc = function() {
		document.getElementById('mo-occ-overlay').style.display = 'none';
		var r = document.getElementById('mo-occ-player-results');
		r.innerHTML = ''; r.classList.remove('kn-ac-open');
	};

	window.moSaveOcc = function() {
		var errEl = document.getElementById('mo-occ-error');
		var pid = document.getElementById('mo-occ-pos-id').value;
		var mid = document.getElementById('mo-occ-player-id').value;
		var start = document.getElementById('mo-occ-start').value;
		var end   = document.getElementById('mo-occ-end').value;
		var note  = document.getElementById('mo-occ-note').value;
		if (!mid)   { errEl.textContent = 'Please select a player.'; errEl.style.display = ''; return; }
		if (!start) { errEl.textContent = 'Term start is required.'; errEl.style.display = ''; return; }
		errEl.style.display = 'none';

		var btn = document.getElementById('mo-occ-save-btn');
		btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		$.post(base() + 'setoccupant', { PositionId: pid, MundaneId: mid, TermStart: start, TermEnd: end, Note: note }, function(resp) {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Set Occupant';
			if (resp && resp.status === 0) { moCloseOcc(); moRefresh(); }
			else { errEl.textContent = (resp && resp.error) ? resp.error : 'Failed to set occupant.'; errEl.style.display = ''; }
		}, 'json').fail(function() {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Set Occupant';
			errEl.textContent = 'Network error.'; errEl.style.display = '';
		});
	};

	// ---------- Occupant player autocomplete (kingdom-scoped, kn-ac-results) ----------
	(function() {
		var input   = document.getElementById('mo-occ-player-text');
		var hidden  = document.getElementById('mo-occ-player-id');
		var results = document.getElementById('mo-occ-player-results');
		if (!input) return;
		var debounce;
		input.addEventListener('input', function() {
			clearTimeout(debounce);
			hidden.value = '';
			var q = input.value.trim();
			if (q.length < 2) { results.innerHTML = ''; results.classList.remove('kn-ac-open'); return; }
			debounce = setTimeout(function() {
				$.getJSON(searchUrl(q), function(data) {
					results.innerHTML = '';
					if (!data || data.length === 0) {
						results.innerHTML = '<div class="kn-ac-item kn-ac-empty">No results</div>';
						if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
						results.classList.add('kn-ac-open');
						return;
					}
					for (var i = 0; i < data.length; i++) {
						var d = data[i];
						var el = document.createElement('div');
						el.className = 'kn-ac-item';
						el.setAttribute('data-id', d.MundaneId);
						el.innerHTML = '<span class="kn-ac-persona">' + esc(d.Persona) + '</span>' +
						               '<span class="kn-ac-park">' + esc((d.KAbbr||'') + ':' + (d.PAbbr||'')) + '</span>';
						el.addEventListener('click', (function(dd) {
							return function() {
								input.value = dd.Persona;
								hidden.value = dd.MundaneId;
								results.innerHTML = '';
								results.classList.remove('kn-ac-open');
							};
						})(d));
						results.appendChild(el);
					}
					if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
					results.classList.add('kn-ac-open');
				});
			}, 250);
		});
		document.addEventListener('click', function(e) {
			if (!results.contains(e.target) && e.target !== input) {
				results.innerHTML = '';
				results.classList.remove('kn-ac-open');
			}
		});
	})();

	// Initial load (partial HTML is present at this point since the script follows it).
	moLoad();
})();
</script>
