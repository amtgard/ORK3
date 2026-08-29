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
	<!-- Load FAILURE. Deliberately separate from #mo-empty below: "we could not read
	     the positions" and "no positions exist yet" need different words and
	     different next steps. -->
	<div id="mo-error" class="mo-loaderr" style="display:none"></div>
	<div id="mo-cards" style="display:none">
		<!-- Nothing configured at all (no crown AND no supporting offices). -->
		<div class="mo-empty" id="mo-empty" style="display:none">
			<div class="mo-empty-icon"><i class="fas fa-user-shield"></i></div>
			<div class="mo-empty-title">This kingdom&rsquo;s officer positions aren&rsquo;t set up yet</div>
			<div class="mo-empty-text">
				Officers listed elsewhere on this page aren&rsquo;t linked to a position until one exists here &mdash;
				create the offices your kingdom actually uses, then attach the people who hold them.
			</div>
			<button type="button" class="kn-btn kn-btn-primary" onclick="moOpenCreate()">
				<i class="fas fa-plus" style="margin-right:6px"></i> Create your first position
			</button>
		</div>
		<!-- Re-ordering is drag-only and drag is a pointer gesture, so below the house
		     768px breakpoint the grips are hidden and this replaces them. Display is
		     driven ENTIRELY by the stylesheet (none -> flex at <=768px); moRender only
		     ever sets it to 'none' (nothing configured) or '' (back to the stylesheet). -->
		<div class="mo-reorder-note" id="mo-reorder-note" role="note">
			<i class="fas fa-desktop" aria-hidden="true"></i>
			<span>Visit this page on desktop to re-order your officer hierarchy.</span>
		</div>
		<!-- ONE list: crown and supporting offices render together, ordered by sort
		     order, nesting shown by indentation. Classification is still carried in the
		     data (and edited on the position) — it is only the visual split that is gone,
		     marked inline by the gold crown glyph on the title. -->
		<div class="mo-list" id="mo-cards-list"></div>
		<div class="mo-retired-panel" id="mo-retired-panel" style="display:none">
			<h4 class="mo-group-title"><i class="fas fa-archive"></i> Retired Positions</h4>
			<div class="mo-list" id="mo-cards-retired"></div>
		</div>
	</div>
</div>

<!-- ============ Manage Officers — Sub-modals ============
     Chrome = the SHARED .ka-overlay / .ka-modal-* classes from admin-console.css
     (the same set the host Manage Officers modal and every other kingdom-admin
     dialog use). It already carries the <=600px full-screen rule and the dark
     palette, which the private copy that used to live here did not. Stacking
     (--z-modal-top / --z-help-overlay, above the host modal) and box width are
     the only local overrides; see the <style> block below. -->

<!-- Create/Edit Position Modal -->
<div id="mo-pos-overlay" class="ka-overlay ka-overlay-top" role="dialog" aria-modal="true" aria-labelledby="mo-pos-title">
	<div class="ka-modal-box ka-modal-box-lg">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-user-shield" style="margin-right:8px;color:var(--ork-badge-blue-text)"></i><span id="mo-pos-title">Create Position</span></h3>
			<button type="button" class="ka-modal-close" onclick="moClosePos()" aria-label="Close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<!-- Enter submits: the footer Save button is type=submit bound back here
			     with form="mo-pos-form", so the form can live inside the scrolling
			     body without becoming a flex child of the shared box chrome. -->
			<form id="mo-pos-form" autocomplete="off">
				<div class="ka-feedback ka-feedback-err" id="mo-pos-error"></div>
				<input type="hidden" id="mo-pos-id" value="" />

				<div class="ka-field">
					<label for="mo-pos-title-input">Title <span class="ka-req" aria-hidden="true">*</span></label>
					<input type="text" id="mo-pos-title-input" placeholder="e.g. Knight Marshal" autocomplete="off" required aria-required="true" />
				</div>

				<div class="ka-field">
					<label for="mo-pos-alias">Display Alias <span class="ka-hint">(optional &mdash; what members see instead of the official title)</span></label>
					<input type="text" id="mo-pos-alias" placeholder="Leave blank to use the official title" autocomplete="off" />
				</div>

				<div class="ka-field">
					<label id="mo-pos-class-label">Classification</label>
					<div class="mo-seg" id="mo-pos-class-seg" role="group" aria-labelledby="mo-pos-class-label">
						<button type="button" class="mo-seg-btn mo-seg-active" data-class="crown" onclick="moSetClass('crown')">Crown</button>
						<button type="button" class="mo-seg-btn" data-class="supporting" onclick="moSetClass('supporting')">Supporting</button>
					</div>
					<div class="mo-pinned-note" id="mo-pos-class-lock" style="display:none">
						<i class="fas fa-lock"></i> Core office &mdash; classification is locked to Crown.
					</div>
				</div>

				<div class="ka-field" id="mo-pos-hidevac-wrap">
					<label class="mo-check-label" for="mo-pos-hidevac"><input type="checkbox" id="mo-pos-hidevac" /> <span>Hide this office when vacant <span class="ka-hint">(non-Crown only &mdash; empty office is hidden from public displays)</span></span></label>
				</div>

				<div class="ka-field">
					<label for="mo-pos-parent">Reports To <span class="ka-hint">(optional &mdash; this office reports to / is a deputy of)</span></label>
					<select id="mo-pos-parent"><option value="">&mdash; None (top-level) &mdash;</option></select>
				</div>

				<div class="ka-field">
					<label id="mo-pos-rbac-label">Permissions</label>
					<div class="mo-seg" id="mo-pos-rbac-seg" role="group" aria-labelledby="mo-pos-rbac-label">
						<button type="button" class="mo-seg-btn mo-seg-active" data-rbac="existing" onclick="moSetRbacMode('existing')">Use existing role</button>
						<button type="button" class="mo-seg-btn" data-rbac="custom" onclick="moSetRbacMode('custom')">Build custom set</button>
						<button type="button" class="mo-seg-btn" data-rbac="none" onclick="moSetRbacMode('none')">None &mdash; no extra access</button>
					</div>
				</div>

				<div class="ka-field" id="mo-pos-none-wrap" style="display:none">
					<div class="mo-pinned-note">This office gets no special permissions &mdash; it is recorded and displayed only.</div>
				</div>

				<div class="ka-field" id="mo-pos-role-wrap">
					<label for="mo-pos-role">Role</label>
					<select id="mo-pos-role"><option value="">Loading roles...</option></select>
					<div class="mo-role-desc" id="mo-pos-role-desc"></div>
				</div>

				<div class="ka-field" id="mo-pos-perm-wrap" style="display:none">
					<label id="mo-pos-perm-label">Permissions in custom set</label>
					<div class="mo-perm-grid" id="mo-pos-perm-grid" role="group" aria-labelledby="mo-pos-perm-label">
						<div class="mo-muted" style="padding:8px">Loading permissions...</div>
					</div>
					<div class="mo-perm-hint" id="mo-pos-perm-hint"></div>
				</div>
			</form>
		</div>
		<div class="ka-modal-footer">
			<button type="button" class="kn-btn kn-btn-secondary" onclick="moClosePos()">Cancel</button>
			<button type="submit" form="mo-pos-form" class="kn-btn kn-btn-primary" id="mo-pos-save-btn">
				<i class="fas fa-save" style="margin-right:4px"></i> Save Position
			</button>
		</div>
	</div>
</div>

<!-- Set Occupant Modal -->
<div id="mo-occ-overlay" class="ka-overlay ka-overlay-top" role="dialog" aria-modal="true" aria-labelledby="mo-occ-heading">
	<div class="ka-modal-box ka-modal-box-md">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title" id="mo-occ-heading"><i class="fas fa-user-plus" style="margin-right:8px;color:var(--ork-badge-green-text)"></i>Set Occupant &mdash; <span id="mo-occ-title"></span></h3>
			<button type="button" class="ka-modal-close" onclick="moCloseOcc()" aria-label="Close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<form id="mo-occ-form" autocomplete="off">
				<div class="ka-feedback ka-feedback-err" id="mo-occ-error"></div>
				<input type="hidden" id="mo-occ-pos-id" value="" />

				<div class="ka-field ka-field-ac">
					<label for="mo-occ-player-text">Player <span class="ka-req" aria-hidden="true">*</span></label>
					<input type="text" id="mo-occ-player-text" placeholder="Search by persona..." autocomplete="off" required aria-required="true" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="mo-occ-player-results" />
					<input type="hidden" id="mo-occ-player-id" value="" />
					<div class="kn-ac-results" id="mo-occ-player-results" style="position:fixed"></div>
				</div>

				<div class="ka-field-row">
					<div class="ka-field">
						<!-- NO `required` on the date inputs: flatpickr's altInput turns the
						     real input into type=hidden, and a required hidden control aborts
						     submission with an unfocusable-invalid-control error. Term start
						     is validated in moSaveOcc() instead. -->
						<label for="mo-occ-start">Term Start <span class="ka-req" aria-hidden="true">*</span></label>
						<input type="text" id="mo-occ-start" autocomplete="off" aria-required="true" />
					</div>
					<div class="ka-field">
						<label for="mo-occ-end">Term End <span class="ka-hint">(optional)</span></label>
						<input type="text" id="mo-occ-end" autocomplete="off" />
					</div>
				</div>

				<div class="ka-field">
					<label for="mo-occ-note">Note <span class="ka-hint">(optional)</span></label>
					<textarea id="mo-occ-note" rows="2" maxlength="500" placeholder="e.g. Reign 42, appointed mid-term..."></textarea>
				</div>
			</form>
		</div>
		<div class="ka-modal-footer">
			<button type="button" class="kn-btn kn-btn-secondary" onclick="moCloseOcc()">Cancel</button>
			<button type="submit" form="mo-occ-form" class="kn-btn kn-btn-primary" id="mo-occ-save-btn">
				<i class="fas fa-save" style="margin-right:4px"></i> Set Occupant
			</button>
		</div>
	</div>
</div>

<!-- Confirm (Retire / Vacate) Modal -->
<div id="mo-confirm-overlay" class="ka-overlay ka-overlay-topmost" role="dialog" aria-modal="true" aria-labelledby="mo-confirm-title">
	<div class="ka-modal-box ka-modal-box-sm">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-exclamation-triangle ka-icon-warn" style="margin-right:8px"></i><span id="mo-confirm-title">Confirm</span></h3>
			<button type="button" class="ka-modal-close" onclick="moCloseConfirm()" aria-label="Close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="mo-warn-box" id="mo-confirm-body"></div>
		</div>
		<div class="ka-modal-footer">
			<button type="button" class="kn-btn kn-btn-secondary" id="mo-confirm-cancel" onclick="moCloseConfirm()">Cancel</button>
			<button type="button" class="kn-btn kn-btn-danger" id="mo-confirm-ok" onclick="moConfirmGo()">Confirm</button>
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
@media (prefers-reduced-motion:reduce) { .mo-retired-caret { transition:none; } }
.mo-retired-toggle.mo-open .mo-retired-caret { transform:rotate(180deg); }
html[data-theme="dark"] .mo-retired-toggle { border-color:var(--ork-border); color:var(--ork-text-secondary); }
html[data-theme="dark"] .mo-retired-toggle:hover { background:var(--ork-bg-tertiary); color:var(--ork-text); }

.mo-loaderr { background:#fff5f5; border:1px solid #fed7d7; border-radius:6px; padding:10px 14px; color:#c53030; font-size:13px; }
html[data-theme="dark"] .mo-loaderr { background:rgba(252,129,129,0.12); border-color:#fc8181; color:#fc8181; }

.mo-group-title {
	font-size:14px; font-weight:700; color:#2d3748; margin:0 0 12px 0;
	display:flex; align-items:center; gap:8px;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
html[data-theme="dark"] .mo-group-title { color:var(--ork-text); }

/* ---- Rows (one flat, indented list; no crown/supporting split) ---- */
.mo-list { display:flex; flex-direction:column; gap:8px; }

.mo-row {
	display:flex; align-items:flex-start; gap:10px; flex-wrap:wrap;
	background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:9px 12px;
}
html[data-theme="dark"] .mo-row { background:var(--ork-card-bg); border-color:var(--ork-border); }
.mo-row.mo-retired { opacity:0.72; }
/* Crown rows are marked by the gold crown glyph on the title, not by a group heading. */
.mo-row-main { flex:1 1 240px; min-width:0; display:flex; flex-direction:column; gap:4px; }
.mo-row-head { display:flex; align-items:flex-start; gap:8px; }
.mo-title { font-size:14px; font-weight:700; color:#2d3748; line-height:1.3; flex:1; min-width:0; overflow-wrap:anywhere; }
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

/* Actions sit at the END of the row, right-aligned. They wrap under the row body
   before they ever push it sideways — nothing here may make the modal scroll
   horizontally. */
.mo-actions { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
.mo-row-actions { flex:0 1 auto; margin-left:auto; margin-top:0; justify-content:flex-end; align-items:flex-start; }
/* These tips sit at the row's right edge and the host modal box clips horizontal
   overflow, so right-anchor them instead of centring (same reason, and the same
   !important, as .mo-occ-remove below). */
.mo-row-actions [data-tip]:hover::after, .mo-row-actions [data-tip]:focus-visible::after {
	left:auto !important; right:0 !important; transform:none !important;
}
.mo-act-btn {
	background:#edf2f7; border:1px solid #e2e8f0; border-radius:5px; padding:5px 9px;
	font-size:12px; font-weight:600; color:#4a5568; cursor:pointer; display:inline-flex; align-items:center; gap:4px;
}
.mo-act-btn:hover:not(:disabled) { background:#e2e8f0; }
.mo-act-btn:focus-visible { outline:2px solid #3182ce; outline-offset:1px; }
.mo-act-btn:disabled { opacity:0.45; cursor:not-allowed; }
html[data-theme="dark"] .mo-act-btn { background:var(--ork-bg-tertiary); border-color:var(--ork-border); color:var(--ork-text-secondary); }
html[data-theme="dark"] .mo-act-btn:hover:not(:disabled) { background:var(--ork-bg-secondary); color:var(--ork-text); }
.mo-act-danger { color:#c53030; }
html[data-theme="dark"] .mo-act-danger { color:#fc8181; }

/* Reclassify dropdown */
.mo-reclass { position:relative; display:inline-block; }
/* right-anchored: the trigger now lives at the row's right edge, and a left-anchored
   menu ran off the modal box. */
.mo-reclass-menu {
	display:none; position:absolute; top:100%; left:auto; right:0; z-index:50; margin-top:4px;
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

/* Nesting is shown by indenting the .mo-children container inside the one list. */
.mo-node { display:flex; flex-direction:column; gap:8px; }
.mo-children { display:flex; flex-direction:column; gap:8px; margin-left:18px; padding-left:14px; border-left:2px solid #e2e8f0; margin-top:8px; }
html[data-theme="dark"] .mo-children { border-left-color:var(--ork-border); }

/* ---- Drag handle + drag state (desktop only; see the <=768px block below) ----
   Native HTML5 drag/drop. The HANDLE is draggable, not the row, so selecting text
   or hitting a button never starts a drag. */
.mo-grip {
	flex:0 0 auto; margin-top:1px; background:none; border:1px solid transparent; border-radius:5px;
	color:#a0aec0; cursor:grab; font-size:13px; line-height:1; padding:5px 4px;
}
.mo-grip:hover { background:#edf2f7; color:#4a5568; }
/* --ork-blue-link, not a literal: #3182ce measures 2.98:1 on the dark card surface
   (--ork-card-bg #2d3748), under the 3:1 minimum for a non-text focus indicator.
   The token is #3182ce in light and #63b3ed in dark (5.25:1). */
.mo-grip:focus-visible { outline:2px solid var(--ork-blue-link); outline-offset:1px; color:#4a5568; }
.mo-grip:active { cursor:grabbing; }
/* Inert placeholder: keeps the row aligned, explains itself, drags nowhere. */
.mo-grip-off { cursor:default; opacity:0.35; }
.mo-grip-off:hover { background:none; color:#a0aec0; }
/* The inert grip's explanation was previously data-tip only -- hover is mouse-only, so a
   screen-reader user got no hint that the row cannot be re-ordered or why. */
.mo-sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; }
html[data-theme="dark"] .mo-grip-off:hover { background:none; color:var(--ork-text-muted); }
html[data-theme="dark"] .mo-grip { color:var(--ork-text-muted); }
html[data-theme="dark"] .mo-grip:hover { background:var(--ork-bg-tertiary); color:var(--ork-text); }
html[data-theme="dark"] .mo-grip:focus-visible { color:var(--ork-text); }
.mo-grip[data-tip]:hover::after,
.mo-grip[data-tip]:focus-visible::after { left:0 !important; right:auto !important; transform:none !important; }

.mo-node.mo-dragging > .mo-row { opacity:0.45; }
/* Insertion marker. box-shadow, not a border, so nothing reflows mid-drag. */
.mo-node.mo-drop-before > .mo-row { box-shadow:0 -3px 0 0 #3182ce; }
.mo-node.mo-drop-after  > .mo-row { box-shadow:0  3px 0 0 #3182ce; }
html[data-theme="dark"] .mo-node.mo-drop-before > .mo-row { box-shadow:0 -3px 0 0 #63b3ed; }
html[data-theme="dark"] .mo-node.mo-drop-after  > .mo-row { box-shadow:0  3px 0 0 #63b3ed; }

/* Mobile: no drag handles, one infobox instead. Everything else stays usable. */
.mo-reorder-note {
	display:none; align-items:flex-start; gap:8px; margin-bottom:12px;
	padding:10px 12px; border-radius:8px; font-size:12.5px; line-height:1.45;
	background:var(--ork-badge-blue-bg); color:var(--ork-badge-blue-text);
	border:1px solid var(--ork-badge-blue-bg);
}
.mo-reorder-note i { margin-top:2px; flex-shrink:0; }
@media (max-width:768px) {
	.mo-grip { display:none; }
	.mo-reorder-note { display:flex; }
	.mo-row-actions { margin-left:0; width:100%; justify-content:flex-start; }
	.mo-row-actions [data-tip]:hover::after, .mo-row-actions [data-tip]:focus-visible::after {
		left:0 !important; right:auto !important;
	}
	.mo-children { margin-left:8px; padding-left:10px; }
}
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
/* Headings are the controller's officer-facing group labels ("Kingdom Settings",
   not the raw "config" slug), so they are NOT uppercased here. */
.mo-perm-cat-title { font-size:12px; font-weight:700; color:#4a5568; margin-bottom:6px; }
html[data-theme="dark"] .mo-perm-cat-title { color:var(--ork-text-secondary); }
.mo-perm-item { margin-bottom:9px; }
.mo-perm-item:last-child { margin-bottom:0; }
.mo-perm-label { display:flex; align-items:flex-start; gap:7px; font-size:13px; color:#2d3748; cursor:pointer; }
html[data-theme="dark"] .mo-perm-label { color:var(--ork-text); }
.mo-perm-label input { margin:2px 0 0 0; flex-shrink:0; }
.mo-perm-name { font-weight:600; }
.mo-perm-desc { font-size:11.5px; color:#718096; line-height:1.45; margin:2px 0 0 22px; }
html[data-theme="dark"] .mo-perm-desc { color:var(--ork-text-muted); }
.mo-perm-hint { font-size:11.5px; color:#718096; margin-top:6px; }
html[data-theme="dark"] .mo-perm-hint { color:var(--ork-text-muted); }

/* Warning box (confirm modal) */
.mo-warn-box { font-size:14px; color:#2d3748; line-height:1.5; }
html[data-theme="dark"] .mo-warn-box { color:var(--ork-text); }

/* ---- Sub-modal chrome ----------------------------------------------------
   The three sub-modals above carry NO private chrome. They use the shared
   admin-console.css set end to end: .ka-overlay (+ .ka-overlay-top /
   .ka-overlay-topmost for stacking above the host modal), .ka-modal-box
   (+ -sm/-md/-lg for width), .ka-modal-header/-title/-close/-body/-footer,
   .ka-field/.ka-field-row/.ka-hint/.ka-req and .ka-feedback. That set already
   carries the <=600px full-screen rule and the dark palette, neither of which
   the private .mo-modal-* copy that used to live here had. Do NOT re-add
   header/body/footer/field/width/z-index rules below. */

/* Empty state: NO positions configured at all. Deliberately distinct from
   #mo-error, which keeps its own copy for a load failure — "nothing is set up"
   and "we could not read what is set up" are different problems. Token-driven,
   so one declaration each covers light AND dark and there is no
   html[data-theme="dark"] block to keep in sync. */
.mo-empty {
	display:flex; flex-direction:column; align-items:center; text-align:center;
	gap:10px; padding:38px 20px; border-radius:10px;
	border:1px dashed var(--ork-border-dark); background:var(--ork-bg-secondary);
}
.mo-empty-icon {
	width:56px; height:56px; border-radius:50%; font-size:22px;
	background:var(--ork-badge-blue-bg); color:var(--ork-badge-blue-text);
	display:flex; align-items:center; justify-content:center;
}
.mo-empty-title { font-size:16px; font-weight:700; color:var(--ork-text); }
.mo-empty-text { font-size:13px; color:var(--ork-text-muted); line-height:1.55; max-width:52ch; }
.mo-empty .kn-btn { margin-top:4px; }

/* Occupant row: name + per-holder remove control (supporting offices only) */
.mo-occ-row { display:flex; align-items:center; gap:6px; }
.mo-occ-row .mo-occ-name { flex:1; min-width:0; overflow-wrap:anywhere; }
.mo-occ-remove {
	flex-shrink:0; background:none; border:1px solid transparent; border-radius:5px;
	color:var(--ork-text-lighter); cursor:pointer; font-size:11px; line-height:1; padding:4px 6px;
}
.mo-occ-remove:hover, .mo-occ-remove:focus-visible {
	background:var(--ork-badge-red-bg); border-color:var(--ork-badge-red-bg); color:var(--ork-badge-red-text);
}
.mo-occ-remove:focus-visible { outline:2px solid #3182ce; outline-offset:1px; }

/* The remove control sits at the card's right edge, and the host modal box clips
   horizontal overflow — so right-anchor its tip instead of centering it. !important
   because the generic [data-tip]:not(...)x5 rule in revised.css outspecifies this. */
.mo-occ-remove[data-tip]:hover::after { left:auto !important; right:0 !important; transform:none !important; }

/* Confirm-dialog persona list (Vacate All / Retire name every affected persona) */
.mo-confirm-list { margin:8px 0 0; padding-left:20px; }
.mo-confirm-list li { margin:2px 0; font-weight:600; }

</style>

<!-- ============ Manage Officers — JS module ============ -->
<script>
window.MoConfig = { kingdomId: <?= (int)$mo_kingdom_id ?>, canManage: true, uir: '<?= UIR ?>' };
	/* The z-index an autocomplete dropdown must carry to clear the modal scale.
	   Derived from the token scale (--z-modal-top + 1) rather than hardcoded: this
	   value is an INLINE style, so a literal 10001 beats -- and defeats -- the
	   stylesheet rule admin-console.css sets for .ka-field-ac .kn-ac-results, and
	   lands BELOW the .ka-overlay / .ka-overlay-top modals (10100 / 10200), which
	   is the dropdown-behind-the-modal bug. Same derivation as revised.js.
	   Computed once; the literal is only a fallback if tokens.css is absent. */
	if (typeof window.tnAcZIndex !== 'function') {
		window.tnAcZIndex = (function () {
			var cached = null;
			return function () {
				if (cached !== null) return cached;
				var top = 0;
				try {
					top = parseInt(getComputedStyle(document.documentElement)
						.getPropertyValue('--z-modal-top'), 10);
				} catch (e) { top = 0; }
				cached = (top > 0) ? (top + 1) : 10201;
				return cached;
			};
		})();
	}
	if (typeof window.tnFixedAcPosition !== 'function') {
	  window.tnFixedAcPosition = function (input, dropdown) {
	    if (!input || !dropdown) return;
	    var r = input.getBoundingClientRect();
	    dropdown.style.position = 'fixed';
	    dropdown.style.top = (r.bottom + 2) + 'px';
	    dropdown.style.left = r.left + 'px';
	    dropdown.style.width = r.width + 'px';
	    dropdown.style.zIndex = String(window.tnAcZIndex());
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
	var moPosKeys = [];         // permission keys the position being edited already has
	var moPosIsCustom = false;  // that position owns its own permission set (RbacMode custom)
	var moPermDirty = false;    // the user has ticked/unticked in THIS modal session

	function esc(s) {
		if (s === null || s === undefined) return '';
		var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML;
	}
	// esc() escapes &<> only (textContent round-trip). Attribute values also need the
	// quotes escaped or a persona containing one breaks out of the attribute.
	function escAttr(s) {
		return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	// Term dates arrive from the controller ALREADY human-formatted ("Aug 26, 2026"),
	// so there is nothing to reformat here. A genuinely absent date yields '' and the
	// caller omits the whole line rather than printing a placeholder.
	function termLine(o) {
		var start = o.TermStart ? esc(o.TermStart) : '';
		var end   = o.TermEnd   ? esc(o.TermEnd)   : '';
		if (!start && !end) return '';
		if (start && end)   return 'Term: ' + start + ' \u2013 ' + end;
		if (start)          return 'Term: ' + start + ' \u2013 present';
		return 'Term ended ' + end;
	}
	// Every current holder of a position, crown or supporting, as one flat list.
	function occupantsOf(pos) {
		if (!pos) return [];
		if (pos.Classification === 'crown') {
			return (pos.Occupant && pos.Occupant.MundaneId) ? [pos.Occupant] : [];
		}
		return (pos.Occupants || []).filter(function(o) { return o && o.MundaneId; });
	}
	// Autocomplete dismissal lives at module scope so the Escape handler and the
	// dropdown's own handlers close it exactly the same way.
	function moAcClose() {
		var input   = document.getElementById('mo-occ-player-text');
		var results = document.getElementById('mo-occ-player-results');
		if (results) { results.innerHTML = ''; results.classList.remove('kn-ac-open'); }
		if (input) input.setAttribute('aria-expanded', 'false');
	}
	function personaList(occs) {
		return '<ul class="mo-confirm-list">' + occs.map(function(o) {
			return '<li>' + esc(o.Persona || 'Unknown') + '</li>';
		}).join('') + '</ul>';
	}

	// .ka-feedback is display:none in the stylesheet, so showing it needs an explicit
	// 'block' — style.display = '' would fall back to the stylesheet's none.
	function moFormError(id, message) {
		var el = document.getElementById(id);
		if (!el) return;
		el.textContent = message;
		el.style.display = 'block';
	}
	function moClearFormError(id) {
		var el = document.getElementById(id);
		if (el) el.style.display = 'none';
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
		var occs = occupantsOf(pos);
		if (!occs.length) return '<div class="mo-occupant"><span class="mo-vacant">(Vacant)</span></div>';
		var pid = parseInt(pos.PositionId, 10);
		// Supporting offices can hold several people at once, so each one gets its own
		// remove control (MundaneId-scoped vacate). A crown office has exactly one
		// holder — the card-level Vacate button already does that job.
		var perHolder = pos.Classification !== 'crown';
		var html = '';
		for (var i = 0; i < occs.length; i++) {
			var o = occs[i];
			var mid = parseInt(o.MundaneId, 10) || 0;
			var name = o.Persona || 'Unknown';
			var tip = 'Remove ' + name + ' from ' + (pos.DisplayTitle || pos.Title || 'this office');
			var row = '<a href="' + UIR + 'Player/profile/' + mid + '" class="mo-occ-name">' + esc(name) + '</a>';
			if (perHolder && mid) {
				row += '<button type="button" class="mo-occ-remove" data-tip="' + escAttr(tip) + '"' +
				       ' aria-label="' + escAttr(tip) + '"' +
				       ' onclick="moVacateHolder(' + pid + ',' + mid + ')"><i class="fas fa-user-minus"></i></button>';
			}
			html += '<div class="mo-occupant"><div class="mo-occ-row">' + row + '</div></div>';
			var term = termLine(o);
			if (term) html += '<div class="mo-term">' + term + '</div>';
		}
		return html;
	}

	// Drag handle. A <span role=button tabindex=0> rather than a <button>: form
	// controls have browser-specific drag quirks, and this way the same element is
	// both the pointer handle and the keyboard re-order control (arrow keys).
	// effParentId is the parent this row is DRAWN under. It differs from the row's real
	// ParentPositionId only when that parent is not in the visible set — a child of a
	// RETIRED office, which RetirePosition deliberately does not reparent. The server's
	// ReorderSiblings refuses a group its parent_position_id does not match, so such a
	// row gets an inert placeholder instead of a handle that could only ever error.
	function gripHtml(pos, effParentId) {
		var pid      = parseInt(pos.PositionId, 10);
		var realPar  = parseInt(pos.ParentPositionId || 0, 10) || 0;
		if (realPar !== (parseInt(effParentId || 0, 10) || 0)) {
			return '<span class="mo-grip mo-grip-off"' +
				' data-tip="This office reports to a retired position, so it has no sibling group to re-order within. Change its “Reports To” to re-order it.">' +
				'<i class="fas fa-grip-vertical" aria-hidden="true"></i>' +
				'<span class="mo-sr-only">Re-ordering unavailable: this office reports to a retired position. Change its Reports To setting to re-order it.</span>' +
				'</span>';
		}
		var label = 'Re-order ' + (pos.DisplayTitle || pos.Title || 'this office') +
		            ' among the offices at its level: drag, or press the up and down arrow keys';
		return '<span class="mo-grip" role="button" tabindex="0" draggable="true" data-pid="' + pid + '"' +
			' data-tip="Drag to re-order among this office\'s siblings — or focus and press the up/down arrow keys"' +
			' aria-label="' + escAttr(label) + '"><i class="fas fa-grip-vertical" aria-hidden="true"></i></span>';
	}

	function rowHtml(pos, effParentId) {
		var isCrown  = pos.Classification === 'crown';
		var isPinned = parseInt(pos.IsPinned, 10) === 1;
		var pid = parseInt(pos.PositionId, 10);
		// occupantsOf() drops MundaneId=0 rows. Vacating the LAST holder of a supporting
		// office blanks the occupancy row rather than deleting it, so a raw
		// Occupants.length would still count that office as filled.
		var holders = occupantsOf(pos);
		var filled = holders.length > 0;

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
			// One holder = "Vacate"; several = "Vacate All", because that is exactly what
			// the button does. Both route to the vacateall endpoint and both name every
			// affected persona in the confirm.
			var vacLabel = holders.length > 1 ? 'Vacate All' : 'Vacate';
			var vacTip = holders.length > 1
				? 'End the term for all ' + holders.length + ' current holders of this office'
				: 'End the current term and remove this office\'s permissions';
			acts += '<button class="mo-act-btn" data-tip="' + escAttr(vacTip) + '" onclick="moVacate(' + pid + ')"><i class="fas fa-user-minus"></i> ' + vacLabel + '</button>';
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

		// Reports-to caption (when this card has a parent that exists somewhere).
		// When the row is NOT rendered beneath that parent -- its parent is retired, so
		// RetirePosition left the child pointing at a position that is no longer in the
		// active tree -- the row sits at top level while still claiming to report to
		// someone. Say why, inline: the grip's tooltip explains it too, but a tooltip is
		// hover-only and this contradiction is visible at rest.
		var reportsTo = '';
		var parentId = parseInt(pos.ParentPositionId || 0, 10);
		if (parentId) {
			var parent = findPos(parentId);
			if (parent) {
				var nestedUnderParent = parentId === (parseInt(effParentId || 0, 10) || 0);
				var parentGone = !nestedUnderParent && (moData.retired || []).some(function (r) {
					return parseInt(r.PositionId, 10) === parentId;
				});
				reportsTo = '<div class="mo-reports-to"><i class="fas fa-level-up-alt fa-rotate-90"></i> Reports to '
					+ esc(parent.DisplayTitle || parent.Title)
					+ (parentGone ? ' (retired)' : '') + '</div>';
			}
		}

		// Hidden-when-vacant chip: supporting + flagged + currently vacant
		var hiddenChip = '';
		if (!isCrown && parseInt(pos.HideWhenVacant || 0, 10) === 1 && !filled) {
			hiddenChip = '<span class="mo-chip-hidden" data-tip="This office is hidden from public displays while vacant"><i class="fas fa-eye-slash"></i> Hidden when vacant</span>';
		}

		// Row order: handle, body, then the actions at the END, right-aligned.
		return '<div class="mo-row">' +
			gripHtml(pos, effParentId) +
			'<div class="mo-row-main">' +
				'<div class="mo-row-head"><div class="mo-title">' + titleHtml + '</div>' + lock + '</div>' +
				reportsTo +
				occupantLine(pos) +
				hiddenChip +
			'</div>' +
			'<div class="mo-actions mo-row-actions">' + acts + '</div>' +
			'</div>';
	}

	function retiredRowHtml(pos) {
		var pid = parseInt(pos.PositionId, 10);
		var titleHtml = (pos.Classification === 'crown' ? '<i class="fas fa-crown mo-crown-glyph"></i>' : '') + esc(pos.DisplayTitle || pos.Title);
		// No drag handle: retired positions are not part of the live hierarchy.
		return '<div class="mo-row mo-retired">' +
			'<div class="mo-row-main">' +
				'<div class="mo-row-head"><div class="mo-title">' + titleHtml + '</div></div>' +
				// RetiredAt already arrives human-formatted from the controller; re-parsing it
				// as ISO produced a truncated string ("Aug 26, 20").
				'<div class="mo-term">Retired' + (pos.RetiredAt ? ' ' + esc(pos.RetiredAt) : '') + '</div>' +
			'</div>' +
			'<div class="mo-actions mo-row-actions"><button class="mo-act-btn" onclick="moReinstate(' + pid + ')"><i class="fas fa-undo"></i> Reinstate</button></div>' +
			'</div>';
	}

	// Build a parent->children tree over the WHOLE active set (crown + supporting in
	// one list), then render nested. A position whose ParentPositionId is null/0, or
	// whose parent is not in the visible set (retired, deleted), renders as top-level
	// (never dropped). Recursive.
	// Ties on sort_order fall back to crown-first then title so the initial order is
	// deterministic — the server orders by (classification, sort_order) per group, so
	// merged rows can collide on sort_order until they are dragged into place.
	function sortBySort(a, b) {
		var d = (parseInt(a.SortOrder || 0, 10)) - (parseInt(b.SortOrder || 0, 10));
		if (d) return d;
		var ac = (a.Classification === 'crown') ? 0 : 1;
		var bc = (b.Classification === 'crown') ? 0 : 1;
		if (ac !== bc) return ac - bc;
		return String(a.DisplayTitle || a.Title || '').localeCompare(String(b.DisplayTitle || b.Title || ''));
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
		// data-parent is the EFFECTIVE parent this row is drawn under (0 for a root),
		// which is what the reorder POST has to send. It differs from the raw
		// ParentPositionId only when the real parent is not in the visible set.
		function nodeHtml(pos, depth, effParentId) {
			var pid = parseInt(pos.PositionId, 10);
			if (seen[pid]) return '';   // cycle guard
			seen[pid] = true;
			var html = '<div class="mo-node" data-pid="' + pid + '" data-parent="' + effParentId + '">' + rowHtml(pos, effParentId);
			var kids = (childrenOf[pid] || []).slice().sort(sortBySort);
			if (kids.length && depth < 12) {
				html += '<div class="mo-children" data-parent="' + pid + '">';
				kids.forEach(function(k) { html += nodeHtml(k, depth + 1, pid); });
				html += '</div>';
			}
			html += '</div>';
			return html;
		}
		var out = '';
		roots.forEach(function(r) { out += nodeHtml(r, 0, 0); });
		return out;
	}

	function moRender() {
		var crown = moData.crown || [], supporting = moData.supporting || [], retired = moData.retired || [];
		// ONE list. Classification still rides along in the data (and is still edited and
		// reclassified per position) — it just no longer splits the display; a crown
		// office is marked inline by the gold crown glyph.
		var active  = crown.concat(supporting);
		var emptyEl = document.getElementById('mo-empty');
		var listEl  = document.getElementById('mo-cards-list');
		var noteEl  = document.getElementById('mo-reorder-note');

		// NOTHING configured: one real empty state instead of a muted "No X offices."
		// sentence, which read as a load result and contradicted the work-queue card
		// sitting beside this modal ("All 5 crown offices filled").
		var nothingConfigured = !active.length;
		emptyEl.style.display = nothingConfigured ? '' : 'none';
		listEl.style.display  = nothingConfigured ? 'none' : '';
		// '' (not 'block'/'flex') so the stylesheet keeps deciding: hidden on desktop,
		// shown below 768px.
		if (noteEl) noteEl.style.display = nothingConfigured ? 'none' : '';

		listEl.innerHTML = nothingConfigured ? '' : renderGroupTree(active);
		moRestoreGripFocus();

		var toggle = document.getElementById('mo-retired-toggle');
		if (retired.length) {
			document.getElementById('mo-retired-count').textContent = retired.length;
			document.getElementById('mo-cards-retired').innerHTML = retired.map(retiredRowHtml).join('');
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
	// onErr is optional: callers that changed the DOM optimistically (drag re-order)
	// use it to release their in-flight lock and re-sync from the server.
	function moPost(action, data, onOk, onErr) {
		function fail() { if (typeof onErr === 'function') { try { onErr(); } catch (e) {} } }
		$.post(base() + action, data, function(resp) {
			if (resp && resp.status === 0) { onOk(resp); }
			else {
				try { console.error('[ManageOfficers] ' + action + ' failed:', resp); } catch (e) {}
				moShowNotice('Action Failed', esc((resp && resp.error) ? resp.error : 'Action failed.'));
				fail();
			}
		}, 'json').fail(function(xhr, st, err) {
			try { console.error('[ManageOfficers] ' + action + ' network error:', (xhr && xhr.status), err || st); } catch (e) {}
			moShowNotice('Network Error', 'The request could not be completed. Please check your connection and try again.');
			fail();
		});
	}

	window.moReclassify = function(pid, cls) {
		document.querySelectorAll('.mo-reclass.mo-open').forEach(function(x){ x.classList.remove('mo-open'); });
		moPost('reclassify', { PositionId: pid, Classification: cls }, function() { moRefresh(); });
	};

	// Vacate ALL holders of an office. The old copy said "the occupant's" (singular)
	// while clearing everyone; it now names the office and lists every affected
	// persona, the same way moRetire does.
	window.moVacate = function(pid) {
		var pos  = findPos(pid);
		var dt   = pos ? (pos.DisplayTitle || pos.Title) : 'this position';
		var occs = occupantsOf(pos);

		if (!occs.length) {
			moShowNotice('Nothing to Vacate', '<strong>' + esc(dt) + '</strong> has no current holder.');
			return;
		}

		var many = occs.length > 1;
		var msg = (many
				? 'Vacating <strong>' + esc(dt) + '</strong> will end the current term for all ' + occs.length + ' holders:'
				: 'Vacating <strong>' + esc(dt) + '</strong> will end the current term for:') +
			personaList(occs) +
			'<p style="margin:10px 0 0">Their officer permissions for this office are removed. Continue?</p>';

		moShowConfirm(many ? 'Vacate All Officers' : 'Vacate Position', msg, many ? 'Vacate All' : 'Vacate', function() {
			moPost('vacateall', { PositionId: pid }, function() { moCloseConfirm(); moRefresh(); });
		});
	};

	// Vacate ONE holder (supporting offices, which can have several at once).
	window.moVacateHolder = function(pid, mid) {
		var pos = findPos(pid);
		var dt  = pos ? (pos.DisplayTitle || pos.Title) : 'this position';
		var occs = occupantsOf(pos);
		var match = occs.filter(function(o) { return parseInt(o.MundaneId, 10) === parseInt(mid, 10); })[0];
		var who = match ? (match.Persona || 'Unknown') : 'this member';
		var others = occs.length - (match ? 1 : 0);

		var msg = 'Remove <strong>' + esc(who) + '</strong> from <strong>' + esc(dt) + '</strong>? ' +
			'This ends their term and removes their officer permissions for this office.' +
			(others > 0 ? ' The other ' + others + ' holder' + (others > 1 ? 's are' : ' is') + ' not affected.' : '');

		moShowConfirm('Remove Officer', msg, 'Remove', function() {
			moPost('vacateholder', { PositionId: pid, MundaneId: mid }, function() { moCloseConfirm(); moRefresh(); });
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

	// ---------- Re-order (drag handle, sibling-scoped) ----------
	// Native HTML5 drag and drop, plus an arrow-key equivalent on the same handle so
	// the feature is not pointer-only. SCOPE RULE: a row may only move AMONG ITS OWN
	// SIBLINGS. A drag never changes nesting — re-parenting stays the job of the
	// "Reports To" picker in the edit modal.
	var moReorderBusy  = false;
	var moFocusGripPid = 0;

	function moList()       { return document.getElementById('mo-cards-list'); }
	function moNodeOf(el)   { return (el && el.closest) ? el.closest('.mo-node') : null; }
	function moSiblings(node) {
		return Array.prototype.filter.call(node.parentNode.children, function(c) {
			return c.classList && c.classList.contains('mo-node');
		});
	}
	function moClearDropMarks() {
		var list = moList();
		if (!list) return;
		list.querySelectorAll('.mo-drop-before, .mo-drop-after').forEach(function(n) {
			n.classList.remove('mo-drop-before');
			n.classList.remove('mo-drop-after');
		});
	}
	// Same DOM parent === same parent position: top-level rows all sit directly in
	// #mo-cards-list, and every other row sits in exactly one .mo-children container
	// belonging to its parent office. Anything else — another branch, a different
	// depth, the dragged row's own subtree — is not a legal target.
	function moCanDrop(dragged, over) {
		return !!(dragged && over && over !== dragged &&
			!dragged.contains(over) && over.parentNode === dragged.parentNode);
	}
	function moDropBefore(over, clientY) {
		var row = over.querySelector('.mo-row');
		var r = (row || over).getBoundingClientRect();
		return clientY < (r.top + r.height / 2);
	}
	// POST the new sibling order for ONE parent, then re-sync from the server.
	function moPersistOrder(node) {
		if (moReorderBusy) return;
		var parentId = parseInt(node.getAttribute('data-parent') || 0, 10) || 0;   // 0 = top level
		// EXCLUDE ORPHANS. A child of a RETIRED office renders at top level (RetirePosition
		// deliberately does not reparent children), but its real parent_position_id still
		// points at that retired row. ReorderSiblings rejects the WHOLE batch if any id's
		// real parent differs from the posted ParentPositionId, so including such a row
		// would break every top-level re-order for that kingdom permanently — the Core Five
		// included. Those rows carry an inert grip; renumber around them. The server allows
		// a partial list on purpose: unlisted siblings keep their sort_order.
		var order = moSiblings(node)
			.filter(function(n) { return !n.querySelector(':scope > .mo-row > .mo-grip-off'); })
			.map(function(n) { return parseInt(n.getAttribute('data-pid'), 10) || 0; })
			.filter(function(id) { return id > 0; })
			.join(',');
		if (!order) return;
		// Held until moRender() finishes, NOT until the POST returns: clearing it early lets
		// a second keypress post while the first refresh is still in flight, and the older
		// response then rebuilds the list without the second move — the row visibly jumps back.
		moReorderBusy  = true;
		moFocusGripPid = parseInt(node.getAttribute('data-pid'), 10) || 0;
		moPost('reorderpositions', { ParentPositionId: parentId, Order: order }, function() {
			moRefresh();
		}, function() {
			// The row was moved optimistically — re-read the server's truth rather than
			// leaving the list showing an order that was never saved.
			moFocusGripPid = 0;
			moRefresh();
		});
	}
	// moRender() rebuilds every row, so a keyboard user would lose their place.
	function moRestoreGripFocus() {
		if (!moFocusGripPid) return;
		var pid = moFocusGripPid;
		moFocusGripPid = 0;
		var list = moList();
		var grip = list ? list.querySelector('.mo-node[data-pid="' + pid + '"] > .mo-row > .mo-grip') : null;
		if (grip && grip.offsetParent !== null) { try { grip.focus(); } catch (e) {} }
	}
	function moMoveByKey(node, dir) {
		var sibs = moSiblings(node);
		var i = sibs.indexOf(node);
		var j = i + dir;
		if (i < 0 || j < 0 || j >= sibs.length) return;   // already at the end of its group
		if (dir < 0) { node.parentNode.insertBefore(node, sibs[j]); }
		else         { node.parentNode.insertBefore(node, sibs[j].nextSibling); }
		moPersistOrder(node);
	}

	(function moBindReorder() {
		// Bound ONCE to the container, which outlives every re-render (moRender only
		// replaces its innerHTML).
		var list = moList();
		if (!list) return;

		list.addEventListener('dragstart', function(e) {
			var grip = (e.target && e.target.closest) ? e.target.closest('.mo-grip') : null;
			var node = grip ? moNodeOf(grip) : null;
			// Only the handle starts a drag. Cancelling here also stops the browser's
			// native drag of the occupant link / selected text inside a row.
			if (!node || moReorderBusy) { e.preventDefault(); return; }
			node.classList.add('mo-dragging');
			try {
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData('text/plain', node.getAttribute('data-pid') || '');
				var row = node.querySelector('.mo-row');
				if (row && e.dataTransfer.setDragImage) { e.dataTransfer.setDragImage(row, 14, 14); }
			} catch (err) {}
		});

		list.addEventListener('dragover', function(e) {
			var dragged = list.querySelector('.mo-node.mo-dragging');
			if (!dragged) return;
			var over = moNodeOf(e.target);
			moClearDropMarks();
			// NOT calling preventDefault() is exactly what rejects an out-of-group drop:
			// the browser shows the no-drop cursor and never fires a 'drop' event.
			if (!moCanDrop(dragged, over)) return;
			e.preventDefault();
			try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
			over.classList.add(moDropBefore(over, e.clientY) ? 'mo-drop-before' : 'mo-drop-after');
		});

		list.addEventListener('drop', function(e) {
			var dragged = list.querySelector('.mo-node.mo-dragging');
			var over    = moNodeOf(e.target);
			e.preventDefault();
			moClearDropMarks();
			if (moReorderBusy || !moCanDrop(dragged, over)) return;   // nothing moves
			var before = moDropBefore(over, e.clientY);
			over.parentNode.insertBefore(dragged, before ? over : over.nextSibling);
			moPersistOrder(dragged);
		});

		list.addEventListener('dragend', function() {
			var dragged = list.querySelector('.mo-node.mo-dragging');
			if (dragged) dragged.classList.remove('mo-dragging');
			moClearDropMarks();
		});

		// Keyboard equivalent, same sibling scope: focus a handle, Up/Down to move.
		list.addEventListener('keydown', function(e) {
			var grip = (e.target && e.target.closest) ? e.target.closest('.mo-grip') : null;
			if (!grip) return;
			if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
			var node = moNodeOf(grip);
			if (!node || moReorderBusy) return;
			e.preventDefault();
			moMoveByKey(node, e.key === 'ArrowUp' ? -1 : 1);
		});
	})();

	// ---------- Sub-modal overlay plumbing (shared .ka-overlay chrome) ----------
	// Topmost FIRST. Escape, backdrop-click and the focus trap all work off this order
	// so a nested overlay never takes the whole stack down with it.
	var MO_STACK = ['mo-confirm-overlay', 'mo-occ-overlay', 'mo-pos-overlay'];
	var MO_CLOSERS = {
		'mo-confirm-overlay': function() { window.moCloseConfirm(); },
		'mo-occ-overlay':     function() { window.moCloseOcc(); },
		'mo-pos-overlay':     function() { window.moClosePos(); }
	};
	var moLastFocus = {};

	function moIsOpen(id) {
		var el = document.getElementById(id);
		return !!(el && el.classList.contains('ka-open'));
	}
	function moTopOverlay() {
		for (var i = 0; i < MO_STACK.length; i++) { if (moIsOpen(MO_STACK[i])) return MO_STACK[i]; }
		return null;
	}
	var MO_FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), ' +
	                   'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	function moFocusables(root) {
		// offsetParent filters out everything display:none inside the modal (the role
		// select in custom mode, the perm grid in existing mode, flatpickr's hidden
		// original inputs) so Tab never lands on an invisible control.
		return Array.prototype.filter.call(root.querySelectorAll(MO_FOCUSABLE), function(el) {
			return el.offsetParent !== null;
		});
	}
	function moOpenOverlay(id, focusId) {
		var el = document.getElementById(id);
		if (!el) return;
		moLastFocus[id] = document.activeElement;
		el.classList.add('ka-open');
		var f = focusId ? document.getElementById(focusId) : null;
		if (!f || f.offsetParent === null) f = moFocusables(el)[0];
		if (f) {
			try { f.focus(); if (typeof f.select === 'function') f.select(); } catch (e) {}
		}
	}
	function moCloseOverlay(id) {
		var el = document.getElementById(id);
		if (!el) return;
		el.classList.remove('ka-open');
		var prev = moLastFocus[id];
		moLastFocus[id] = null;
		if (prev && typeof prev.focus === 'function' && document.contains(prev)) {
			try { prev.focus(); } catch (e) {}
		}
	}

	MO_STACK.forEach(function(id) {
		var el = document.getElementById(id);
		if (!el) return;

		// Backdrop click. The mousedown guard stops a text selection that happens to
		// END on the backdrop from throwing the form away.
		el.addEventListener('mousedown', function(e) { el._moBackdrop = (e.target === el); });
		el.addEventListener('click', function(e) {
			var fromBackdrop = el._moBackdrop;
			el._moBackdrop = false;
			if (e.target === el && fromBackdrop) MO_CLOSERS[id]();
		});

		// Focus trap.
		el.addEventListener('keydown', function(e) {
			if (e.key !== 'Tab') return;
			var items = moFocusables(el);
			if (!items.length) return;
			var first = items[0], last = items[items.length - 1];
			if (e.shiftKey) {
				if (document.activeElement === first || !el.contains(document.activeElement)) { e.preventDefault(); last.focus(); }
			} else if (document.activeElement === last || !el.contains(document.activeElement)) {
				e.preventDefault(); first.focus();
			}
		});
	});

	// Escape closes ONLY the topmost open layer. Capture phase so it runs before the
	// host Manage Officers modal's own document-level Escape handler, which used to
	// tear down the whole stack — dismissing an autocomplete destroyed the form AND
	// the console modal behind it. When none of our overlays are open we do nothing
	// and the host handler runs as usual.
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Escape' && e.key !== 'Esc') return;
		var top = moTopOverlay();
		if (!top) return;

		// An open autocomplete is its own topmost layer above the occupant form.
		var acr = document.getElementById('mo-occ-player-results');
		var acOpen = !!(acr && acr.classList.contains('kn-ac-open'));
		if (top === 'mo-occ-overlay' && acOpen) {
			moAcClose();
		} else {
			MO_CLOSERS[top]();
		}
		e.preventDefault();
		e.stopPropagation();
		if (e.stopImmediatePropagation) e.stopImmediatePropagation();
	}, true);

	// ---------- Confirm modal ----------
	function moShowConfirm(title, bodyHtml, okLabel, fn) {
		document.getElementById('mo-confirm-title').textContent = title;
		document.getElementById('mo-confirm-body').innerHTML = bodyHtml;
		document.getElementById('mo-confirm-ok').textContent = okLabel;
		moConfirmFn = fn;
		// Focus Cancel, never the destructive button — Escape and Enter should both be
		// safe on a dialog whose primary action removes an officer.
		moOpenOverlay('mo-confirm-overlay', 'mo-confirm-cancel');
	}
	// Notice = the same modal with a single dismiss action (native alert() is banned).
	function moShowNotice(title, bodyHtml) {
		moShowConfirm(title, bodyHtml, 'OK', function() { moCloseConfirm(); });
	}
	window.moCloseConfirm = function() { moCloseOverlay('mo-confirm-overlay'); moConfirmFn = null; };
	window.moConfirmGo = function() { if (moConfirmFn) moConfirmFn(); };

	// ---------- Create/Edit Position modal ----------
	// A failed fetch is NOT an empty list: surface the error in the modal + console and
	// leave the cache null so the next open retries instead of showing a bogus "none".
	function moLoadFailed(what, detail) {
		try { console.error('[ManageOfficers] Failed to load ' + what + ':', detail); } catch (e) {}
		moFormError('mo-pos-error', 'Could not load ' + what + '. Please try again or reload the page.');
	}
	function ensureRoles(cb) {
		if (moRoles) { cb(); return; }
		$.getJSON(base() + 'roles', function(resp) {
			if (resp && resp.status === 0) { moRoles = resp.data || []; }
			else { moRoles = null; moLoadFailed('officer roles', (resp && resp.error) || resp); }
			cb();
		}).fail(function(xhr, st, err) {
			moRoles = null;
			moLoadFailed('officer roles', 'HTTP ' + ((xhr && xhr.status) || '?') + ' ' + (err || st || ''));
			cb();
		});
	}
	function ensurePerms(cb) {
		if (moPerms) { cb(); return; }
		$.getJSON(base() + 'permissions', function(resp) {
			if (resp && resp.status === 0) { moPerms = resp.data || []; }
			else { moPerms = null; moLoadFailed('officer permissions', (resp && resp.error) || resp); }
			cb();
		}).fail(function(xhr, st, err) {
			moPerms = null;
			moLoadFailed('officer permissions', 'HTTP ' + ((xhr && xhr.status) || '?') + ' ' + (err || st || ''));
			cb();
		});
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
		// Picking a different role re-seeds the custom grid, unless the user has already
		// adjusted it by hand (moPermDirty) — their edits are never silently discarded.
		if (moRbacMode === 'custom' && !moPermDirty) refreshPermGrid(true);
	};

	// Populate "Reports To" from the currently-loaded positions (crown + supporting),
	// excluding the position being edited AND its whole descendant subtree - picking a
	// descendant would build a cycle the server (OfficerPosition::WouldCreateCycle)
	// rejects, so never offer it. selectedId pre-selects.
	function collectSubtree(list, rootId) {
		var excluded = {};
		rootId = parseInt(rootId || 0, 10);
		if (!rootId) return excluded;
		var childrenOf = {};
		list.forEach(function(p) {
			var par = parseInt(p.ParentPositionId || 0, 10);
			if (par) { (childrenOf[par] = childrenOf[par] || []).push(parseInt(p.PositionId, 10)); }
		});
		var queue = [rootId];
		while (queue.length) {
			var id = queue.shift();
			if (excluded[id]) continue;      // guard against pre-existing cycles in the data
			excluded[id] = true;
			(childrenOf[id] || []).forEach(function(kid) { if (!excluded[kid]) queue.push(kid); });
		}
		return excluded;
	}
	function renderParentSelect(selectedId, excludeId) {
		var sel = document.getElementById('mo-pos-parent');
		if (!sel) return;
		var all = (moData.crown || []).concat(moData.supporting || []);
		// Options come from active positions only, but the DESCENDANT map must also walk
		// retired ones: RetirePosition sets retired_at and never reparents children, so an
		// active position can still reach its ancestor through a retired node. Excluding
		// retired rows here offered a descendant as a parent, and the server rejected it.
		var excluded = collectSubtree(all.concat(moData.retired || []), excludeId);
		var opts = '<option value="">\u2014 None (top-level) \u2014</option>';
		all.slice().sort(function(a, b) {
			return (parseInt(a.SortOrder || 0, 10)) - (parseInt(b.SortOrder || 0, 10));
		}).forEach(function(pos) {
			var pid = parseInt(pos.PositionId, 10);
			if (excluded[pid]) return; // itself or one of its descendants would be a cycle
			var label = pos.DisplayTitle || pos.Title || ('#' + pid);
			opts += '<option value="' + pid + '"' + (pid === parseInt(selectedId || 0, 10) ? ' selected' : '') + '>' + esc(label) + '</option>';
		});
		sel.innerHTML = opts;
	}

	// role_id -> permission keys, learned from the positions already bound to that role.
	// posBase() ships PermissionKeys for every kingdom-owned role, so any role an office
	// in this kingdom already uses can seed the custom grid. Shared system roles do not
	// carry their keys in the /roles payload, so they seed nothing (see moPermHint).
	function roleKeyIndex() {
		var map = {};
		var all = (moData.crown || []).concat(moData.supporting || []).concat(moData.retired || []);
		all.forEach(function(p) {
			var rid = parseInt(p.RbacRoleId || 0, 10);
			if (rid > 0 && !map[rid] && p.PermissionKeys && p.PermissionKeys.length) {
				map[rid] = p.PermissionKeys.slice();
			}
		});
		return map;
	}
	function currentRoleId() {
		var sel = document.getElementById('mo-pos-role');
		return sel ? (parseInt(sel.value || 0, 10) || 0) : 0;
	}
	function currentGridKeys() {
		var keys = [];
		document.querySelectorAll('#mo-pos-perm-grid .mo-perm-cb:checked').forEach(function(cb) { keys.push(cb.value); });
		return keys;
	}
	// What the grid should show right now:
	//   1. the user's own ticks, once they have touched the grid in THIS modal session
	//   2. the permission set the position being edited already has (custom round-trip)
	//   3. the currently selected role's set, so "custom" starts as "a role, adjusted"
	function desiredPermKeys() {
		if (moPermDirty) return currentGridKeys();
		if (moPosKeys && moPosKeys.length) return moPosKeys.slice();
		return roleKeyIndex()[currentRoleId()] || [];
	}
	function moPermHint(prefilled) {
		var el = document.getElementById('mo-pos-perm-hint');
		if (!el) return;
		if (moPermDirty) { el.textContent = ''; return; }
		if (prefilled && moPosIsCustom) {
			el.textContent = 'This office\'s current permission set — tick or untick to change it.';
		} else if (prefilled) {
			el.textContent = 'Pre-filled from the selected role — tick or untick to adjust it for this office.';
		} else {
			el.textContent = 'Choose the permissions this office grants. Anyone appointed to it receives exactly this set.';
		}
	}
	function renderPermGrid(checkedKeys) {
		checkedKeys = checkedKeys || [];
		var grid = document.getElementById('mo-pos-perm-grid');
		if (!grid) return;
		if (!moPerms || !moPerms.length) { grid.innerHTML = '<div class="mo-muted" style="padding:8px">No permissions available.</div>'; return; }
		// Category headings are the controller's officer-facing labels ("Kingdom
		// Settings"), not the raw slug, and the server emits them in a deliberate
		// order — so bucket in first-seen order rather than Object.keys() order.
		var order = [], cats = {};
		moPerms.forEach(function(p) {
			var c = p.Category || 'Other';
			if (!cats[c]) { cats[c] = []; order.push(c); }
			cats[c].push(p);
		});
		var html = '';
		order.forEach(function(cat) {
			html += '<div class="mo-perm-cat"><div class="mo-perm-cat-title">' + esc(cat) + '</div>';
			cats[cat].forEach(function(p) {
				var ck = checkedKeys.indexOf(p.Key) !== -1 ? ' checked' : '';
				var cbId = 'mo-perm-' + String(p.Key).replace(/[^A-Za-z0-9]+/g, '-');
				html += '<div class="mo-perm-item">' +
					'<label class="mo-perm-label" for="' + escAttr(cbId) + '">' +
					'<input type="checkbox" class="mo-perm-cb" id="' + escAttr(cbId) + '" value="' + escAttr(p.Key) + '"' + ck + '>' +
					'<span class="mo-perm-name">' + esc(p.DisplayName || p.Key) + '</span></label>' +
					(p.Description ? '<div class="mo-perm-desc">' + esc(p.Description) + '</div>' : '') +
					'</div>';
			});
			html += '</div>';
		});
		grid.innerHTML = html;
	}
	// Re-render the grid from scratch. Called on EVERY mode switch and EVERY modal
	// open — it used to render once on first load, so opening a second position
	// inherited the first one's ticks.
	// allowFetch=false is the "modal just opened" case: re-render from what is already
	// cached so a hidden grid can never carry the previous position's ticks into the
	// next save, but do NOT spend a request on a catalogue the user may never open.
	function refreshPermGrid(allowFetch) {
		var grid = document.getElementById('mo-pos-perm-grid');
		if (!grid) return;
		var apply = function() {
			var keys = desiredPermKeys();
			renderPermGrid(keys);
			moPermHint(!moPermDirty && keys.length > 0);
		};
		if (moPerms === null) {
			if (allowFetch) { ensurePerms(apply); return; }
			grid.innerHTML = '<div class="mo-muted" style="padding:8px">Loading permissions...</div>';
			moPermHint(false);
			return;
		}
		apply();
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
		if (mode === 'custom') refreshPermGrid(true);
	};

	function openPosModal() {
		moClearFormError('mo-pos-error');
		document.getElementById('mo-pos-role').onchange = window.moUpdateRoleDesc;
		// Re-render the grid on EVERY open, whatever the mode: a hidden grid still
		// holds the previous position's ticks, and moSavePos() reads the DOM.
		refreshPermGrid(false);
		moOpenOverlay('mo-pos-overlay', 'mo-pos-title-input');
	}

	window.moOpenCreate = function() {
		moEditId = 0;
		moPinnedClass = false;
		moPosKeys = [];
		moPosIsCustom = false;
		moPermDirty = false;
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
		// The position's own permission set, so a custom office round-trips instead of
		// being re-saved with whatever was left in the grid from the last one opened.
		moPosKeys = (pos.PermissionKeys || []).slice();
		moPosIsCustom = (pos.RbacMode === 'custom');
		moPermDirty = false;
		document.getElementById('mo-pos-title').textContent = 'Edit Position';
		document.getElementById('mo-pos-id').value = pid;
		document.getElementById('mo-pos-title-input').value = pos.Title || '';
		document.getElementById('mo-pos-alias').value = pos.TitleAlias || '';

		var lockEl = document.getElementById('mo-pos-class-lock');
		lockEl.style.display = moPinnedClass ? '' : 'none';
		document.querySelectorAll('#mo-pos-class-seg .mo-seg-btn').forEach(function(b){ b.disabled = moPinnedClass; });
		// EditPosition refuses a reparent of a pinned/system position, and it returns BEFORE
		// applying anything -- so leaving this enabled would silently discard the title and
		// sort-order edits in the same submit. Lock it the way classification is locked.
		var parentSel = document.getElementById('mo-pos-parent');
		if (parentSel) { parentSel.disabled = moPinnedClass; }
		moPinnedClass = false; // allow moSetClass to set initial value
		moSetClass(pos.Classification === 'crown' ? 'crown' : 'supporting');
		moPinnedClass = parseInt(pos.IsPinned, 10) === 1;

		// Reports To: pre-select current parent, exclude self.
		renderParentSelect(parseInt(pos.ParentPositionId || 0, 10), pid);
		// Hide-when-vacant: reflect current value (moSetClass above already
		// forced it off + hidden if Crown).
		var hvCb = document.getElementById('mo-pos-hidevac');
		if (hvCb) hvCb.checked = (pos.Classification !== 'crown' && parseInt(pos.HideWhenVacant || 0, 10) === 1);

		// RbacMode comes from the server now: 'none' (no role bound), 'custom' (bound to
		// the kingdom-owned role this office created for itself) or 'existing'. Pinned /
		// system positions always show 'existing' — they keep their locked system role.
		var posRid = parseInt(pos.RbacRoleId || 0, 10);
		var mode = pos.RbacMode;
		if (mode !== 'none' && mode !== 'custom' && mode !== 'existing') {
			mode = posRid === 0 ? 'none' : 'existing';   // older payload without RbacMode
		}
		if (moPinnedClass) mode = 'existing';
		moSetRbacMode(mode);
		ensureRoles(function() { renderRoleSelect(posRid); });
		openPosModal();
	};

	window.moClosePos = function() { moCloseOverlay('mo-pos-overlay'); };

	window.moSavePos = function() {
		var title = document.getElementById('mo-pos-title-input').value.trim();
		var alias = document.getElementById('mo-pos-alias').value.trim();
		if (!title) { moFormError('mo-pos-error', 'Title is required.'); return; }
		moClearFormError('mo-pos-error');

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
			if (!rid) { moFormError('mo-pos-error', 'Please select a role.'); return; }
			data.RoleId = rid;
		} else if (moRbacMode === 'none') {
			// None: no RoleId / PermissionKeys; server stores rbac_role_id=0.
		} else {
			var keys = currentGridKeys();
			if (!keys.length) { moFormError('mo-pos-error', 'Select at least one permission.'); return; }
			data['PermissionKeys'] = keys; // array -> PHP $_POST['PermissionKeys']
		}

		var btn = document.getElementById('mo-pos-save-btn');
		btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		var action = moEditId ? 'editposition' : 'createposition';
		if (moEditId) data.PositionId = moEditId;

		$.post(base() + action, data, function(resp) {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Position';
			if (resp && resp.status === 0) { moClosePos(); moRefresh(); }
			else { moFormError('mo-pos-error', (resp && resp.error) ? resp.error : 'Failed to save position.'); }
		}, 'json').fail(function() {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Save Position';
			moFormError('mo-pos-error', 'Network error.');
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
		moClearFormError('mo-occ-error');
		document.getElementById('mo-occ-pos-id').value = pid;
		document.getElementById('mo-occ-title').textContent = pos ? (pos.DisplayTitle || pos.Title) : '';
		document.getElementById('mo-occ-player-text').value = '';
		document.getElementById('mo-occ-player-id').value = '';
		document.getElementById('mo-occ-note').value = '';
		moAcClose();
		initOccFp();
		if (moStartFp) moStartFp.setDate(new Date(), true);
		if (moEndFp)   moEndFp.clear();
		else { document.getElementById('mo-occ-start').value = ''; document.getElementById('mo-occ-end').value = ''; }
		// Open AFTER flatpickr has run: altInput replaces the visible date field, and
		// moFocusables() has to see the final controls to trap focus correctly.
		moOpenOverlay('mo-occ-overlay', 'mo-occ-player-text');
	};

	window.moCloseOcc = function() {
		moCloseOverlay('mo-occ-overlay');
		moAcClose();
	};

	window.moSaveOcc = function() {
		var pid = document.getElementById('mo-occ-pos-id').value;
		var mid = document.getElementById('mo-occ-player-id').value;
		var start = document.getElementById('mo-occ-start').value;
		var end   = document.getElementById('mo-occ-end').value;
		var note  = document.getElementById('mo-occ-note').value;
		if (!mid)   { moFormError('mo-occ-error', 'Please pick a player from the search results.'); return; }
		if (!start) { moFormError('mo-occ-error', 'Term start is required.'); return; }
		moClearFormError('mo-occ-error');

		var btn = document.getElementById('mo-occ-save-btn');
		btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		$.post(base() + 'setoccupant', { PositionId: pid, MundaneId: mid, TermStart: start, TermEnd: end, Note: note }, function(resp) {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Set Occupant';
			if (resp && resp.status === 0) { moCloseOcc(); moRefresh(); }
			else { moFormError('mo-occ-error', (resp && resp.error) ? resp.error : 'Failed to set occupant.'); }
		}, 'json').fail(function() {
			btn.disabled = false; btn.innerHTML = '<i class="fas fa-save" style="margin-right:4px"></i> Set Occupant';
			moFormError('mo-occ-error', 'Network error.');
		});
	};

	// ---------- Form submit (Enter) + custom-permission dirty tracking ----------
	(function() {
		var posForm = document.getElementById('mo-pos-form');
		if (posForm) {
			posForm.addEventListener('submit', function(e) { e.preventDefault(); window.moSavePos(); });
		}
		var occForm = document.getElementById('mo-occ-form');
		if (occForm) {
			occForm.addEventListener('submit', function(e) { e.preventDefault(); window.moSaveOcc(); });
		}
		// Delegated: the grid's contents are replaced on every render.
		var grid = document.getElementById('mo-pos-perm-grid');
		if (grid) {
			grid.addEventListener('change', function(e) {
				if (e.target && e.target.classList && e.target.classList.contains('mo-perm-cb')) {
					moPermDirty = true;
					moPermHint(false);
				}
			});
		}
	})();

	// ---------- Occupant player autocomplete (kingdom-scoped, kn-ac-results) ----------
	(function() {
		var input   = document.getElementById('mo-occ-player-text');
		var hidden  = document.getElementById('mo-occ-player-id');
		var results = document.getElementById('mo-occ-player-results');
		if (!input) return;
		var debounce;
		function moAcSelect(el) {
			if (!el) return;
			input.value  = el.getAttribute('data-persona') || '';
			hidden.value = el.getAttribute('data-id') || '';
			moAcClose();
		}
		function moAcOpen() {
			if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
			results.classList.add('kn-ac-open');
			input.setAttribute('aria-expanded', 'true');
		}
		input.addEventListener('input', function() {
			clearTimeout(debounce);
			hidden.value = '';
			var q = input.value.trim();
			if (q.length < 2) { moAcClose(); return; }
			debounce = setTimeout(function() {
				$.getJSON(searchUrl(q), function(data) {
					results.innerHTML = '';
					if (!data || data.length === 0) {
						results.innerHTML = '<div class="kn-ac-item kn-ac-empty">No results</div>';
						moAcOpen();
						return;
					}
					for (var i = 0; i < data.length; i++) {
						var d = data[i];
						var el = document.createElement('div');
						el.className = 'kn-ac-item';
						el.setAttribute('data-id', d.MundaneId);
						el.setAttribute('data-persona', d.Persona || '');
						el.innerHTML = esc(d.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + esc((d.KAbbr||'') + ':' + (d.PAbbr||'')) + ')</span>';
						el.addEventListener('click', (function(node) {
							return function() { moAcSelect(node); };
						})(el));
						results.appendChild(el);
					}
					moAcOpen();
				});
			}, 250);
		});
		input.addEventListener('keydown', function(e) {
			// Enter inside an open dropdown must never reach the <form>: it picks the
			// highlighted result (or does nothing), it does not submit the modal.
			var acOpen = results.classList.contains('kn-ac-open');
			var items = results.querySelectorAll('.kn-ac-item[data-id]');
			if (!items.length) {
				if (e.key === 'Enter' && acOpen) e.preventDefault();
				return;
			}
			var focused = results.querySelector('.kn-ac-focused');
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				var next = focused ? (focused.nextElementSibling || items[0]) : items[0];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (next && next.getAttribute('data-id')) next.classList.add('kn-ac-focused');
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var prev = focused ? (focused.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (prev && prev.getAttribute('data-id')) prev.classList.add('kn-ac-focused');
			} else if (e.key === 'Enter' && acOpen) {
				e.preventDefault();
				if (focused) moAcSelect(focused);
			} else if (e.key === 'Escape') {
				// Normally unreachable — the document-capture Escape handler above closes
				// the dropdown first — but kept so the field still behaves on its own.
				moAcClose();
			}
		});
		document.addEventListener('click', function(e) {
			if (!results.contains(e.target) && e.target !== input) moAcClose();
		});
	})();

	// Initial load (partial HTML is present at this point since the script follows it).
	moLoad();
})();
</script>
