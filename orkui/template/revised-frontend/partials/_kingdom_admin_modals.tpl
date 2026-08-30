<?php
/* -----------------------------------------------------------------------
   Kingdom Admin -- modal layer and page script.

   Extracted from Admin_kingdom.tpl unchanged, to keep the console template
   readable: the page itself is now ~375 lines instead of 2,504. Included from
   the page, so it runs in the page's variable scope: $kid, $AdminInfo,
   $AdminConfig, $AdminAwards, $SystemAwards, $park_edit_lookup and the
   Can* flags must all be set before the include.
   ----------------------------------------------------------------------- */

/* Blast radius for Reset Waivers. Kingdom::GetAdminDashboard() counts
   ork_mundane rows with kingdom_id = this org AND waivered = 1 and applies NO
   active filter -- which is exactly the scope of the UPDATE that
   Player::ResetWaivers runs, so this is the true number of players the button
   clears, not an estimate.

   null (rather than 0) when the dashboard never supplied the key: "0 players"
   and "we could not work it out" are different statements, and only the first
   of them justifies disabling the button. */
$_kaQueue     = is_array($AdminDashboard['Queue'] ?? null) ? $AdminDashboard['Queue'] : [];
$_kaWaivered  = array_key_exists('WaiveredMembers', $_kaQueue) ? (int)$_kaQueue['WaiveredMembers'] : null;
$_kaEntityLc  = htmlspecialchars(strtolower($entityLabel));
?>

<style>
/* =============================================
   FORM-MODAL CHROME  (Configuration rows, fieldsets, choice cards)
   =============================================
   Local to this partial, the same way partials/_manage_officers.tpl carries its
   own mo-* block. These replace the inline `style="..."` strings the Configuration
   builder used to write on every row: an inline declaration is (1,0,0,0) and beats
   every html[data-theme="dark"] rule, so the old hardcoded #2d3748 / #718096 /
   #e2e8f0 were a guaranteed dark-mode bug -- near-black label text on the dark
   modal surface.

   Colours are token references (--ork-*), which orkui.css redefines per theme, so
   one declaration is correct in BOTH themes and cannot drift; that is the same
   reasoning admin-console.css documents for .ka-muted. Every token has a light
   fallback so the rule still paints if a surface loads without orkui.css. */

/* -- Configuration: grouped sections ---------------------------------- */
.ka-cfg-group + .ka-cfg-group { margin-top: 16px; }
.ka-cfg-group-title {
	font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
	color: var(--ork-text-muted, #718096); padding-bottom: 5px; margin-bottom: 2px;
	border-bottom: 1.5px solid var(--ork-border, #e2e8f0);
}
/* The control column is flex:0 1 auto and the label column min-width:0, so a long
   option label shrinks the select instead of pushing the row past the 560px box.
   The old markup did the opposite -- width:100% AND flex-shrink:0 on the select --
   which is what put a horizontal scrollbar on this modal. */
.ka-cfg-row {
	display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;
	padding: 10px 0; border-bottom: 1px solid var(--ork-border, #e2e8f0);
}
.ka-cfg-group .ka-cfg-row:last-child { border-bottom: 0; }
.ka-cfg-head { flex: 1 1 auto; min-width: 0; overflow-wrap: break-word; }
.ka-cfg-name {
	display: block; margin: 0; font-size: 13px; font-weight: 600;
	color: var(--ork-text, #2d3748); cursor: pointer;
}
.ka-cfg-help { font-size: 11.5px; line-height: 1.45; margin-top: 3px; color: var(--ork-text-muted, #718096); }
.ka-cfg-control {
	flex: 0 1 auto; min-width: 0; max-width: 100%;
	display: flex; align-items: center; justify-content: flex-end; gap: 6px; flex-wrap: wrap;
}
.ka-cfg-unit { font-size: 11px; white-space: nowrap; color: var(--ork-text-muted, #718096); }
.ka-cfg-empty { margin: 0; font-size: 13px; }
.ka-cfg-input {
	padding: 6px 9px; border: 1.5px solid var(--ork-input-border, #e2e8f0); border-radius: 6px;
	font-size: 13px; box-sizing: border-box; max-width: 100%;
	/* min-width:0 is the half that actually stops the overflow: a <select>'s
	   min-content width is its widest option, so without this it refuses to shrink
	   and pushes the 560px modal into a horizontal scrollbar. */
	min-width: 0;
	color: var(--ork-text, #2d3748); background: var(--ork-input-bg, #fff);
}
.ka-cfg-input:focus { outline: none; border-color: #90cdf4; box-shadow: 0 0 0 3px rgba(66,153,225,0.15); }
.ka-cfg-input-num    { width: 94px; }
.ka-cfg-input-select { max-width: 230px; }
.ka-cfg-input-color  { width: 54px; height: 32px; padding: 2px; cursor: pointer; }

/* Boolean settings are a toggle, never a text box. The On/Off word is drawn from
   CSS state rather than a change listener so it stays correct when the modal's
   reset-on-open restores the control programmatically (which fires no event). */
/* (0,2,0) so it beats admin-console.css's `.ka-toggle { display: inline-block }`
   regardless of which stylesheet the browser happens to apply last. */
.ka-toggle.ka-cfg-toggle { display: inline-flex; align-items: center; gap: 8px; }
.ka-cfg-toggle-state {
	font-size: 12px; font-weight: 600; min-width: 22px;
	color: var(--ork-text-muted, #718096);
}
.ka-cfg-toggle-state::after { content: 'Off'; }
.ka-cfg-toggle input:checked ~ .ka-cfg-toggle-state { color: var(--ork-badge-green-text, #276749); }
.ka-cfg-toggle input:checked ~ .ka-cfg-toggle-state::after { content: 'On'; }
/* admin-console.css hides the toggle's checkbox with opacity:0, which also hides
   the focus ring -- keyboard users had no idea where they were. */
.ka-toggle input:focus-visible + .ka-toggle-track { box-shadow: 0 0 0 3px rgba(66,153,225,0.45); }

/* -- Grouped radio sets ------------------------------------------------ */
/* A bare <label> above a radio pair is not a group name to a screen reader: the
   Create Player modal announced two unidentifiable "No / Yes" pairs. */
.ka-fieldset { border: 0; margin: 0 0 14px; padding: 0; min-width: 0; }
.ka-legend {
	display: block; float: none; padding: 0; margin-bottom: 5px;
	font-size: 12px; font-weight: 600; color: var(--ork-text-secondary, #4a5568);
}
.ka-legend-help { margin-top: 6px; }
/* The abbreviation box is normalised to uppercase on input; show it that way as
   the officer types rather than only after the handler fires. */
#ka-details-abbr { text-transform: uppercase; }

/* -- Either/or choice cards (Create Player password mode) -------------- */
.ka-choice { display: flex; flex-direction: column; gap: 8px; }
.ka-choice-opt {
	display: flex; align-items: flex-start; gap: 9px; padding: 9px 11px; cursor: pointer;
	border: 1.5px solid var(--ork-border, #e2e8f0); border-radius: 7px;
	transition: border-color 0.15s, background 0.15s;
}
.ka-choice-opt:hover { border-color: var(--ork-border-dark, #cbd5e0); }
.ka-choice-opt input { flex-shrink: 0; margin: 2px 0 0; }
.ka-choice-opt.ka-choice-on { border-color: #3182ce; background: rgba(49, 130, 206, 0.07); }
.ka-choice-title { display: block; font-size: 13px; font-weight: 600; color: var(--ork-text, #2d3748); }
.ka-choice-help { display: block; font-size: 11.5px; line-height: 1.45; margin-top: 2px; color: var(--ork-text-muted, #718096); }
.ka-choice-detail { margin: 10px 0 0; }

/* rgba(49,130,206,.07) over the dark modal surface is almost invisible; lift the
   selected card with the same blue at a weight that reads on #2d3748. */
html[data-theme="dark"] .ka-choice-opt.ka-choice-on { border-color: #63b3ed; background: rgba(99, 179, 237, 0.14); }
html[data-theme="dark"] .ka-cfg-input:focus,
html[data-theme="dark"] .ka-toggle input:focus-visible + .ka-toggle-track { border-color: #63b3ed; }

/* -- Autocomplete row status flags (Move / Merge / Create player search) --
   DELIBERATELY UNSCOPED. tnFixedAcPosition() reparents .kn-ac-results to
   <body>, so a `.ka-overlay .ka-ac-flag` selector would stop matching the
   instant the dropdown is repositioned -- which is every time it opens inside
   a modal. Two players sharing a persona is the exact case Merge Players
   exists for, so the row has to carry the distinguishing state. */
.ka-ac-meta { font-size: 11px; color: var(--ork-text-muted, #a0aec0); }
.ka-ac-flag {
	display: inline-block; margin-left: 5px; padding: 0 6px; border-radius: 9px;
	font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
	vertical-align: 1px;
}
.ka-ac-flag-inactive  { background: #edf2f7; color: #4a5568; }
.ka-ac-flag-suspended { background: #fed7d7; color: #822727; }
html[data-theme="dark"] .ka-ac-flag-inactive  { background: rgba(255,255,255,0.13); color: var(--ork-text-secondary); }
html[data-theme="dark"] .ka-ac-flag-suspended { background: var(--ork-badge-red-bg); color: var(--ork-badge-red-text); }

/* -- Merge Players: side-by-side preview ------------------------------- */
/* The cards carry no background of their own on purpose. --ork-bg is #fff in
   light and #1a202c in dark, while the modal surface goes the other way, so any
   token-backed fill would invert into the surface in one theme or the other.
   The border does the separating and is correct in both. */
.ka-mgp-preview { margin-top: 14px; }
.ka-mgp-cards {
	display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: stretch;
}
.ka-mgp-card {
	min-width: 0; padding: 10px 12px; border-radius: 8px;
	border: 1.5px solid var(--ork-border, #e2e8f0);
}
.ka-mgp-card-keep   { border-color: #38a169; }
.ka-mgp-card-remove { border-color: #c53030; }
html[data-theme="dark"] .ka-mgp-card-keep   { border-color: var(--ork-badge-green-text); }
html[data-theme="dark"] .ka-mgp-card-remove { border-color: var(--ork-badge-red-text); }
.ka-mgp-role {
	display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px; border-radius: 9px;
	font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
}
.ka-mgp-card-keep   .ka-mgp-role { background: #c6f6d5; color: #22543d; }
.ka-mgp-card-remove .ka-mgp-role { background: #fed7d7; color: #822727; }
html[data-theme="dark"] .ka-mgp-card-keep   .ka-mgp-role { background: var(--ork-badge-green-bg); color: var(--ork-badge-green-text); }
html[data-theme="dark"] .ka-mgp-card-remove .ka-mgp-role { background: var(--ork-badge-red-bg); color: var(--ork-badge-red-text); }
.ka-mgp-name {
	display: block; margin: 7px 0 1px; font-size: 14px; font-weight: 700;
	color: var(--ork-text, #2d3748); overflow-wrap: anywhere;
}
.ka-mgp-dl { margin: 7px 0 0; font-size: 11.5px; line-height: 1.55; }
.ka-mgp-dl > div { display: flex; gap: 8px; justify-content: space-between; }
.ka-mgp-dt { flex: 0 0 auto; color: var(--ork-text-muted, #718096); }
.ka-mgp-dd { min-width: 0; text-align: right; color: var(--ork-text, #2d3748); overflow-wrap: anywhere; }
.ka-mgp-link { display: inline-block; margin-top: 8px; font-size: 11.5px; font-weight: 600; }
/* Swap sits in the gutter between the two cards and is the whole point of the
   preview: picking Keep/Remove backwards is the failure this modal prevents. */
.ka-mgp-swap {
	align-self: center; display: inline-flex; align-items: center; justify-content: center;
	width: 34px; height: 34px; padding: 0; border-radius: 50%; cursor: pointer;
	border: 1.5px solid var(--ork-border-dark, #cbd5e0);
	background: var(--ork-bg, #fff); color: var(--ork-text-secondary, #4a5568);
	transition: border-color 0.15s, color 0.15s;
}
.ka-mgp-swap:hover:not(:disabled) { border-color: #3182ce; color: #3182ce; }
html[data-theme="dark"] .ka-mgp-swap { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .ka-mgp-swap:hover:not(:disabled) { border-color: #63b3ed; color: #63b3ed; }
.ka-mgp-swap-hint { display: block; margin-top: 8px; font-size: 11px; text-align: center; }
.ka-mgp-note { margin: 8px 0 0; font-size: 11px; line-height: 1.45; }
.ka-mgp-empty { font-size: 12px; }

/* -- Heraldry ---------------------------------------------------------- */
/* The preview border was inline (1,0,0,0), so dark mode could never touch it. */
.ka-her-figure { margin: 0 0 16px; text-align: center; }
.ka-her-preview {
	max-width: 200px; max-height: 200px; border-radius: 8px;
	border: 1px solid var(--ork-border, #e2e8f0);
}
.ka-her-caption { display: block; margin-top: 7px; font-size: 11.5px; }
.ka-her-actions { margin-top: 6px; font-size: 12px; }
.ka-her-facts { margin: 12px 0 0; padding-left: 18px; font-size: 11.5px; line-height: 1.6; }
.ka-her-facts li + li { margin-top: 2px; }
/* Destructive button hard left, so Remove is never wedged between Cancel and
   the primary action where a mis-aimed click lands on it. */
.ka-footer-left { margin-right: auto; }

/* -- Claim Park -------------------------------------------------------- */
/* Global link styling strips the affordance off a bare <a>, so the mailto is a
   button; the copy control beside it covers webmail users with no mail handler. */
.ka-cp-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 12px; }
.ka-cp-actions .adm-btn { text-decoration: none; }
.ka-cp-address {
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 12px; overflow-wrap: anywhere;
}
.ka-cp-policy { margin: 0 0 12px; font-size: 12.5px; line-height: 1.55; }
/* Same problem the CSS agent solved for .ka-icon-danger / .ka-icon-warn: the
   inline #276749 on this modal title measures ~2.3:1 on the dark modal surface. */
.ka-icon-ok { color: #276749; }
html[data-theme="dark"] .ka-icon-ok { color: var(--ork-badge-green-text); }
.ka-icon-brand { color: #6b46c1; }
html[data-theme="dark"] .ka-icon-brand { color: var(--ork-badge-purple-text); }

/* -- Operations -------------------------------------------------------- */
/* This <style> block is in the body and admin-console.css is a <link> in the
   head, so at equal (0,1,0) specificity these declarations win. That is
   deliberate: .ka-danger-note is inline-flex and .ka-notice-warn is flex, and
   the blast-radius line wants one layout whichever severity it is wearing. */
.ka-ops-blast {
	display: flex; align-items: flex-start; gap: 6px;
	margin-top: 6px; font-size: 12px; line-height: 1.45;
}
.ka-ops-blast i { flex-shrink: 0; margin-top: 2px; }
.ka-confirm-list { margin: 10px 0 0; padding-left: 18px; font-size: 13px; line-height: 1.6; }
.ka-confirm-list li + li { margin-top: 3px; }
/* The confirm body used to be a <p style="color:#2d3748"> -- an inline colour at
   (1,0,0,0) that no dark rule could reach, on the one dialog that fronts every
   destructive action in this console. */
.ka-confirm-body { font-size: 14px; line-height: 1.6; color: var(--ork-text, #2d3748); }
.ka-confirm-body p { margin: 0 0 8px; }
.ka-confirm-body p:last-child, .ka-confirm-body ul:last-child { margin-bottom: 0; }
/* Danger variant for the shared confirm dialog. Its OK button is the same navy
   primary as "Save Details" by default, which is the wrong signal in front of a
   permanent delete or a kingdom-wide clear. */
.ka-confirm-danger .ka-modal-box { border-top: 3px solid #c53030; }
html[data-theme="dark"] .ka-confirm-danger .ka-modal-box { border-top-color: var(--ork-badge-red-text); }
.ka-confirm-danger #ka-confirm-ok { background: #c53030; color: #fff; }
.ka-confirm-danger #ka-confirm-ok:hover:not(:disabled) { background: #9b2c2c; }
html[data-theme="dark"] .ka-confirm-danger #ka-confirm-ok {
	background: var(--ork-badge-red-bg); color: var(--ork-badge-red-text);
	box-shadow: inset 0 0 0 1.5px var(--ork-badge-red-text);
}

@media (max-width: 600px) {
	.ka-cfg-row { flex-direction: column; align-items: stretch; }
	.ka-cfg-control { justify-content: flex-start; }
	.ka-cfg-input-select { max-width: 100%; }
	/* One column: the swap button drops between the two cards and stays in
	   reading order, so its arrows are rotated to point up/down instead. */
	.ka-mgp-cards { grid-template-columns: 1fr; }
	.ka-mgp-swap { justify-self: center; transform: rotate(90deg); }
}
</style>

<!-- =============================================
     MODALS
     ============================================= -->

<!-- ---- Edit Details ---- -->
<div class="ka-overlay" id="ka-details-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-edit" style="margin-right:8px;color:#2b6cb0"></i>Edit Kingdom Details</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-details-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-details-feedback"></div>
			<div class="ka-field">
				<label for="ka-details-name">Kingdom Name <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-details-name" value="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>" required aria-required="true">
			</div>
			<!-- kingdom.abbreviation is varchar(3) and sql_mode is empty on this stack, so
			     MySQL truncates silently: "WETL" stored as "WET" while the box still read
			     "WETL" and the banner said saved. maxlength="3" plus the normalise-on-input
			     handler makes the browser enforce what the column enforces. -->
			<div class="ka-field">
				<label for="ka-details-abbr">Abbreviation <span class="ka-req" aria-hidden="true">*</span> <span class="ka-hint">(3 characters, letters &amp; numbers only)</span></label>
				<input type="text" id="ka-details-abbr" value="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" maxlength="3" required aria-required="true" aria-describedby="ka-details-abbr-hint" autocapitalize="characters" autocomplete="off" spellcheck="false">
				<div class="ka-hint" id="ka-details-abbr-hint">Stored as at most 3 uppercase characters &mdash; anything longer is cut off, so pick the three you want (e.g. <strong>WET</strong>, not <strong>WETL</strong>).</div>
				<div class="ka-notice-warn" id="ka-details-abbr-warn" role="status" style="display:none"></div>
			</div>
			<div class="ka-field">
				<label for="ka-details-description">Description <span class="ka-hint">(optional &mdash; Markdown supported)</span></label>
				<textarea id="ka-details-description" rows="4" style="resize:vertical" data-original="<?= htmlspecialchars($AdminInfo['Description'] ?? '') ?>"><?= htmlspecialchars($AdminInfo['Description'] ?? '') ?></textarea>
			</div>
			<div class="ka-field">
				<label for="ka-details-url">Website URL <span class="ka-hint">(optional)</span></label>
				<input type="url" id="ka-details-url" value="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" placeholder="https://">
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-details-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-details-save"><i class="fas fa-save"></i> Save Details</button>
		</div>
	</div>
</div>

<!-- ---- Configuration ---- -->
<div class="ka-overlay" id="ka-config-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-sliders-h" style="margin-right:8px;color:#2b6cb0"></i>Configuration</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-config-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-config-feedback"></div>
			<!-- Every setting is rendered ONCE, by the builder below, from the
			     ConfigRegistry definition the controller attaches to each row
			     (label / help / control / options / group).

			     There used to be a hardcoded "Recommendation Visibility" select here as
			     well, and the builder rendered the SAME key again as a free-text box
			     labelled "Award Recommendations Visibility". Save posted setconfig and
			     then setrecsvisibility, so the hardcoded select always overwrote whatever
			     the officer typed in the text box. Both the duplicate control and the
			     second endpoint call are gone: KingdomAjax setconfig is the single write
			     path and validates through the registry. -->
			<div id="ka-config-rows"></div>
			<p class="ka-muted ka-cfg-empty" id="ka-config-empty" style="display:none">There are no settings available to configure here.</p>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-config-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-config-save"><i class="fas fa-save"></i> Save Configuration</button>
		</div>
	</div>
</div>

<!-- ---- Heraldry ---- -->
<div class="ka-overlay" id="ka-heraldry-overlay">
	<div class="ka-modal-box" style="width:460px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-image ka-icon-brand" style="margin-right:8px"></i><?= htmlspecialchars($entityLabel) ?> Heraldry</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-heraldry-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-heraldry-feedback"></div>
			<figure class="ka-her-figure">
				<!-- alt + caption distinguish "this is your heraldry" from "this is the
				     placeholder every org without heraldry shows". They looked identical
				     before, which made Remove feel safe when it was not. -->
				<img id="ka-heraldry-preview" class="ka-her-preview"
					src="<?= htmlspecialchars($heraldryUrl) ?>"
					alt="<?= $hasHeraldry ? 'Current ' . htmlspecialchars(strtolower($entityLabel)) . ' heraldry' : 'Generic placeholder shield &mdash; no heraldry uploaded' ?>">
				<figcaption class="ka-her-caption ka-muted" id="ka-heraldry-caption"><?= $hasHeraldry ? 'Current heraldry' : 'No heraldry uploaded &mdash; the generic shield is shown' ?></figcaption>
				<?php if ($hasHeraldry): ?>
				<!-- Remove deletes the file outright and there is no copy kept anywhere,
				     so this link is what makes Remove a survivable action. -->
				<div class="ka-her-actions">
					<a href="<?= htmlspecialchars($heraldryUrl) ?>" download target="_blank" rel="noopener"><i class="fas fa-download" aria-hidden="true"></i> Download current image</a>
				</div>
				<?php endif; ?>
			</figure>
			<div class="ka-field">
				<label for="ka-heraldry-file">Upload New Heraldry <span class="ka-hint">(PNG, JPG or GIF)</span></label>
				<input type="file" id="ka-heraldry-file" accept="image/png,image/jpeg,image/gif">
				<div class="ka-hint" id="ka-heraldry-resize" role="status" aria-live="polite" style="display:none"></div>
			</div>
			<ul class="ka-her-facts ka-muted">
				<li>PNG, JPG and GIF are accepted.</li>
				<li>Anything over about 340&nbsp;KB is scaled down in your browser before it is sent, so a large photo uploads fine &mdash; it just arrives smaller.</li>
				<li>A square image works best: the profile badge and the banner behind this console both crop to a square.</li>
				<li>Transparent edges are trimmed off a PNG, so a device on a transparent background is cropped tight to the artwork.</li>
				<li>An animated GIF is saved as a single still frame.</li>
			</ul>
		</div>
		<div class="ka-modal-footer">
			<?php if ($hasHeraldry): ?>
			<button class="adm-btn adm-btn-danger ka-footer-left" id="ka-heraldry-remove"><i class="fas fa-trash"></i> Remove</button>
			<?php endif; ?>
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-heraldry-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-heraldry-upload" disabled><i class="fas fa-upload"></i> Upload</button>
		</div>
	</div>
</div>

<!-- ---- Park Titles ---- -->
<div class="ka-overlay" id="ka-parktitles-overlay">
	<div class="ka-modal-box" style="width:700px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#2b6cb0"></i>Park Titles</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-parktitles-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<details class="ka-help">
				<summary><i class="fas fa-circle-info" aria-hidden="true"></i> What these settings do</summary>
				<div class="ka-help-body">
					<p>Park titles are the ranks parks in your <?= strtolower($entityLabel) ?> are known by &mdash; Outpost, Shire, Barony and so on.</p>
					<dl>
						<dt>Title</dt>
						<dd>The name of the rank. It appears on each park&rsquo;s profile, in the <?= strtolower($entityLabel) ?>&rsquo;s park list, and in the Title menu under Edit Parks.</dd>
						<dt>Class</dt>
						<dd>Rank order &mdash; a higher number outranks a lower one. It sorts the park list so grander parks appear first, and it is the tier the public map reads. The seeded ranks step in tens (10, 20, 30&hellip;) so a new rank can be slotted between two existing ones. Two titles sharing a number have no defined order between them.</dd>
					</dl>
					<div class="ka-help-note">
						<strong>Min&nbsp;Att., Cutoff, Period and Len. are recorded but not yet used.</strong>
						The ORK does not promote or demote parks automatically &mdash; a park&rsquo;s title is set by hand under Edit&nbsp;Parks. Treat these four as a written record of your <?= strtolower($entityLabel) ?>&rsquo;s corpora requirements; changing them will not change any park&rsquo;s rank or produce any warning.
					</div>
				</div>
			</details>
			<div class="ka-feedback" id="ka-titles-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table" id="ka-titles-table">
					<thead>
						<tr>
							<th>Title</th>
							<th>Class</th>
							<th>Min Att.</th>
							<th>Cutoff</th>
							<th>Period</th>
							<th>Len.</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-titles-tbody">
						<!-- Built by JS -->
					</tbody>
					<tfoot>
						<tr>
							<td><input type="text" data-field="Title" placeholder="New title..."></td>
							<td><input type="number" data-field="Class" value="0" min="0"></td>
							<td><input type="number" data-field="MinimumAttendance" value="0" min="0"></td>
							<td><input type="number" data-field="MinimumCutoff" value="0" min="0"></td>
							<td>
								<select data-field="Period">
									<option value="month">Month</option>
									<option value="week">Week</option>
								</select>
							</td>
							<td><input type="number" data-field="Length" value="1" min="1"></td>
							<td></td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-parktitles-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-titles-save"><i class="fas fa-save"></i> Save Park Titles</button>
		</div>
	</div>
</div>

<!-- ---- Edit Parks ---- -->
<div class="ka-overlay" id="ka-editparks-overlay">
	<div class="ka-modal-box ka-modal-box-xl">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-map-marker-alt" style="margin-right:8px;color:#276749"></i>Edit Parks</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-editparks-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<!-- Non-scrolling toolbar. Sits directly above the table:
			     .ka-table-toolbar-bleed cancels the body's 20px SIDE padding so the
			     bar spans edge to edge. It has no negative top margin, so an
			     instructions box may precede it. -->
			<div class="ka-table-toolbar ka-table-toolbar-bleed">
				<label class="ka-hint" for="ka-parks-search">Filter</label>
				<input type="text" id="ka-parks-search" class="ka-report-filter" placeholder="Filter by park name or abbreviation&hellip;" autocomplete="off">
				<span class="ka-table-toolbar-spacer"></span>
				<span class="ka-table-count" id="ka-parks-count"></span>
			</div>
			<div class="ka-feedback" id="ka-parks-feedback"></div>
			<div class="ka-admin-table-wrap ka-admin-table-wrap-scroll">
				<table class="ka-admin-table ka-admin-table-sticky">
					<thead>
						<tr>
							<th>Park Name</th>
							<th>Title</th>
							<th>Abbr</th>
							<th style="text-align:center">Active</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-parks-tbody">
						<!-- Built by JS -->
					</tbody>
				</table>
				<div class="ka-table-empty" id="ka-parks-empty" style="display:none">
					<i class="fas fa-map-marker-alt"></i>
					<strong>No parks match that filter</strong>
					<div class="ka-table-empty-hint">Clear the filter to see every park in the kingdom.</div>
				</div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-editparks-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-parks-save"><i class="fas fa-save"></i> Save Parks</button>
		</div>
	</div>
</div>

<!-- ---- Awards ---- -->
<div class="ka-overlay" id="ka-awards-overlay">
	<div class="ka-modal-box ka-modal-box-xxl">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-medal" style="margin-right:8px;color:#6b46c1"></i>Manage Awards</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-awards-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<details class="ka-help">
			<summary><i class="fas fa-circle-info" aria-hidden="true"></i> What these columns do</summary>
			<div class="ka-help-body">
			<p>This is your <?= strtolower($entityLabel) ?>&rsquo;s award catalogue: which awards can be given out, and what each one is called here. Renaming a standard award nests it under the award it renames.</p>
			<dl>
			<dt>Award Name</dt>
			<dd>What this award is called in your <?= strtolower($entityLabel) ?>. It is the name used everywhere the award appears &mdash; when it is given out, on player profiles, in court reports and in award recommendations.</dd>
			<dt>Title?</dt>
			<dd>Marks this as a title rather than an award. Titles appear in the Titles section of a player&rsquo;s profile and in the title pickers; awards appear in their award history.</dd>
			<dt>Ladder</dt>
			<dd>A ladder award is granted in ranks, and players climb it over time. The standard Amtgard orders are set by Amtgard and locked here. You can turn any of your kingdom&rsquo;s own awards into a ladder and set its Max Rank (up to 12). Un-ticking Ladder only stops <em>new</em> ranks being offered &mdash; ranks already granted keep showing exactly as they are.</dd>
			<dt>Class</dt>
			<dd>Rank order among titles &mdash; a higher number outranks a lower one, so it decides the order titles are listed in on a player&rsquo;s profile, and which heading a title is grouped under in this list. Only meaningful when <em>Title?</em> is ticked. Seeded examples: Page&nbsp;5, Master&nbsp;10, Squire&nbsp;15, Knight&nbsp;20, Lord&nbsp;30, rising by tens.</dd>
			<dt>Retiring an award</dt>
			<dd>The trash icon retires an award rather than deleting it. A retired award can no longer be given out, but every player who already holds it keeps it and it still shows under its proper name. You can bring it back at any time.</dd>
			</dl>
			<div class="ka-help-note">
			<strong>Reign and Month are recorded but not yet used.</strong>
			They read as limits on how often an award may be given, but nothing in the ORK enforces them &mdash; no check is made when an award is granted. Setting them will not stop anyone giving the award out.
			</div>
			</div>
			</details>
			<!-- Non-scrolling toolbar. Sits directly above the table:
			     .ka-table-toolbar-bleed cancels the body's 20px SIDE padding so the
			     bar spans edge to edge. It has no negative top margin, so an
			     instructions box may precede it. -->
			<div class="ka-table-toolbar ka-table-toolbar-bleed">
				<label class="ka-hint" for="ka-awards-search">Search</label>
				<input type="text" id="ka-awards-search" class="ka-report-filter" placeholder="Search awards by name&hellip;" autocomplete="off">
				<button type="button" class="ka-add-btn" id="ka-awards-expandall" aria-pressed="false">
					<i class="fas fa-angle-double-down"></i> Expand all
				</button>
				<span class="ka-table-toolbar-spacer"></span>
				<span class="ka-table-count" id="ka-awards-count"></span>
			</div>
			<div class="ka-feedback" id="ka-awards-feedback"></div>
			<div class="ka-admin-table-wrap ka-admin-table-wrap-scroll">
				<table class="ka-admin-table ka-admin-table-sticky">
					<thead>
						<tr>
							<th>Award Name</th>
							<th>Reign</th>
							<th>Month</th>
							<th>Title?</th>
							<th>Ladder</th>
							<th>Max Rank</th>
							<th>Class</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-awards-tbody">
						<!-- Built by JS -->
					</tbody>
				</table>
				<div class="ka-table-empty" id="ka-awards-empty" style="display:none">
					<i class="fas fa-medal"></i>
					<strong>No awards match that search</strong>
					<div class="ka-table-empty-hint">Clear the search box to see every award group again.</div>
				</div>
			</div>
			<!-- Add Award Alias form -->
			<div id="ka-add-award-wrap" style="display:none" class="ka-add-award-wrap">
				<div class="ka-add-award-title">Add Award Alias</div>
				<p class="ka-form-hint">An award alias lets you create additional variations on existing system awards and titles.</p>
				<div class="ka-field">
					<label>System Award</label>
					<div class="ka-alias-picker-wrap">
						<input type="hidden" id="ka-new-award-id">
						<button type="button" class="ka-alias-trigger" id="ka-alias-trigger">
							<span class="ka-alias-label" id="ka-alias-label">Select a system award&hellip;</span>
							<i class="fas fa-chevron-down" style="font-size:11px;opacity:.5"></i>
						</button>
						<div class="ka-alias-dropdown" id="ka-alias-dropdown" style="display:none">
							<input type="text" class="ka-alias-search" id="ka-alias-search" placeholder="Search awards..." autocomplete="off">
							<div class="ka-alias-list" id="ka-alias-list"></div>
						</div>
					</div>
				</div>
				<div class="ka-award-row-fields">
					<div class="ka-field ka-field-grow">
						<label>Kingdom Name <span class="ka-hint">(your kingdom&rsquo;s name for this award)</span></label>
						<input type="text" id="ka-new-award-name" placeholder="e.g. Order of the Warrior">
					</div>
					<div class="ka-field">
						<label>Reign Limit</label>
						<input type="number" id="ka-new-reign" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field">
						<label>Month Limit</label>
						<input type="number" id="ka-new-month" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field ka-field-center">
						<label>Title?</label>
						<input type="checkbox" id="ka-new-istitle">
					</div>
					<div class="ka-field">
						<label>Title Class</label>
						<input type="number" id="ka-new-tclass" min="0" value="0" style="width:64px" disabled>
					</div>
					<div class="ka-field ka-field-center">
						<label>Ladder?</label>
						<input type="checkbox" id="ka-new-ladder">
					</div>
					<div class="ka-field">
						<label>Max Rank</label>
						<input type="number" id="ka-new-maxrank" min="1" max="12" value="10" style="width:64px" disabled>
					</div>
				</div>
				<div style="display:flex;gap:8px;margin-top:10px">
					<button class="ka-save-btn" id="ka-new-award-save"><i class="fas fa-plus"></i> Add Award Alias</button>
					<button class="adm-btn adm-btn-ghost" id="ka-new-award-cancel" style="font-size:13px">Cancel</button>
				</div>
			</div>
			<!-- Add Kingdom-Specific Award form -->
			<div id="ka-add-custom-wrap" style="display:none" class="ka-add-award-wrap">
				<div class="ka-add-award-title">Add Kingdom-Specific Award</div>
				<p class="ka-form-hint">A kingdom-specific award allows you to add awards only given out in your kingdom.</p>
				<div class="ka-award-row-fields">
					<div class="ka-field ka-field-grow">
						<label>Award Name</label>
						<input type="text" id="ka-custom-name" placeholder="e.g. Kingdom Spotlight">
					</div>
					<div class="ka-field">
						<label>Reign Limit</label>
						<input type="number" id="ka-custom-reign" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field">
						<label>Month Limit</label>
						<input type="number" id="ka-custom-month" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field ka-field-center">
						<label>Title?</label>
						<input type="checkbox" id="ka-custom-istitle">
					</div>
					<div class="ka-field">
						<label>Title Class</label>
						<input type="number" id="ka-custom-tclass" min="0" value="0" style="width:64px" disabled>
					</div>
					<div class="ka-field ka-field-center">
						<label>Ladder?</label>
						<input type="checkbox" id="ka-custom-ladder">
					</div>
					<div class="ka-field">
						<label>Max Rank</label>
						<input type="number" id="ka-custom-maxrank" min="1" max="12" value="10" style="width:64px" disabled>
					</div>
				</div>
				<div style="display:flex;gap:8px;margin-top:10px">
					<button class="ka-save-btn" id="ka-custom-save"><i class="fas fa-plus"></i> Add Award</button>
					<button class="adm-btn adm-btn-ghost" id="ka-custom-cancel" style="font-size:13px">Cancel</button>
				</div>
			</div>
		</div>
		<!-- Both "Add" buttons live in the footer, not at the bottom of the awards
		     table. The table is ~130 rows for a mature kingdom; stranded at the end
		     of that scroll they were effectively unreachable. -->
		<div class="ka-modal-footer">
			<button class="ka-add-btn" id="ka-awards-add-btn"><i class="fas fa-plus"></i> Add Award Alias</button>
			<button class="ka-add-btn" id="ka-custom-add-btn"><i class="fas fa-plus"></i> Add Kingdom-Specific Award</button>
			<span class="ka-table-toolbar-spacer"></span>
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-awards-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Create Player ---- -->
<div class="ka-overlay" id="ka-createplayer-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-createplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-cp-feedback"></div>
			<div class="ka-field">
				<label for="ka-cp-park">Home Park <span class="ka-req" aria-hidden="true">*</span></label>
				<select id="ka-cp-park" required aria-required="true">
					<option value="">-- select park --</option>
					<?php foreach ($park_edit_lookup ?? [] as $p): if ($p['Active'] !== 'Active') continue; ?>
					<option value="<?= (int)$p['ParkId'] ?>"><?= htmlspecialchars($p['Name']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label for="ka-cp-persona">Persona <span class="ka-req" aria-hidden="true">*</span></label>
					<input type="text" id="ka-cp-persona" placeholder="In-game name" required aria-required="true">
				</div>
				<div class="ka-field">
					<label for="ka-cp-email">Email</label>
					<input type="email" id="ka-cp-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label for="ka-cp-given">Given Name</label>
					<input type="text" id="ka-cp-given" placeholder="First name">
				</div>
				<div class="ka-field">
					<label for="ka-cp-surname">Surname</label>
					<input type="text" id="ka-cp-surname" placeholder="Last name">
				</div>
			</div>
			<div class="ka-field">
				<label for="ka-cp-username">Username <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-cp-username" autocomplete="new-password" placeholder="min. 4 characters" required aria-required="true">
			</div>

			<!-- Password is an explicit CHOICE, never a blank box.
			     Player::CreatePlayer() stores no credential at all when Password is
			     empty, because hashing salt + USERNAME + '' would let the account in
			     with an empty password box. That is deliberate, but as an unexplained
			     "optional" field it produced accounts nobody could sign in to and no
			     screen said so. The two branches below name both outcomes. -->
			<fieldset class="ka-fieldset">
				<legend class="ka-legend">Password</legend>
				<div class="ka-choice">
					<label class="ka-choice-opt">
						<input type="radio" name="ka-cp-pwmode" id="ka-cp-pwmode-self" value="self" checked>
						<span>
							<span class="ka-choice-title">Player sets their own password</span>
							<span class="ka-choice-help">No password is created now. The account exists but <strong>cannot be signed in to</strong> until the player sets one from the login page&rsquo;s &ldquo;forgot password&rdquo; link, so give them an email address they can receive.</span>
						</span>
					</label>
					<label class="ka-choice-opt">
						<input type="radio" name="ka-cp-pwmode" id="ka-cp-pwmode-temp" value="temp">
						<span>
							<span class="ka-choice-title">I&rsquo;ll set a temporary password</span>
							<span class="ka-choice-help">Hand it to the player in person and ask them to change it. Minimum 8 characters &mdash; the same floor self-registration enforces.</span>
						</span>
					</label>
				</div>
				<div class="ka-field ka-choice-detail" id="ka-cp-pw-wrap" style="display:none">
					<label for="ka-cp-password">Temporary Password <span class="ka-req" aria-hidden="true">*</span> <span class="ka-hint">(at least 8 characters)</span></label>
					<input type="password" id="ka-cp-password" autocomplete="new-password" minlength="8" placeholder="at least 8 characters">
				</div>
			</fieldset>

			<div class="ka-field-row">
				<fieldset class="ka-fieldset">
					<legend class="ka-legend">Restrict Mundane Name Visibility</legend>
					<div class="ka-radio-group">
						<label for="ka-cp-restricted-no"><input type="radio" id="ka-cp-restricted-no" name="ka-cp-restricted" value="0" checked> No</label>
						<label for="ka-cp-restricted-yes"><input type="radio" id="ka-cp-restricted-yes" name="ka-cp-restricted" value="1"> Yes</label>
					</div>
					<div class="ka-cfg-help ka-legend-help">Hides the player&rsquo;s real name from searches and public displays. Use it for members who prefer their mundane identity kept private.</div>
				</fieldset>
				<fieldset class="ka-fieldset">
					<legend class="ka-legend">Waivered</legend>
					<div class="ka-radio-group">
						<label for="ka-cp-waivered-no"><input type="radio" id="ka-cp-waivered-no" name="ka-cp-waivered" value="0" checked> No</label>
						<label for="ka-cp-waivered-yes"><input type="radio" id="ka-cp-waivered-yes" name="ka-cp-waivered" value="1"> Yes</label>
					</div>
					<div class="ka-notice-warn ka-legend-help"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>Only set this to Yes once the signed waiver is actually in hand. It is a legal record, not a convenience flag.</span></div>
				</fieldset>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-createplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-cp-submit"><i class="fas fa-user-plus"></i> Create Player</button>
		</div>
	</div>
</div>

<!-- ---- Move Player ---- -->
<div class="ka-overlay" id="ka-moveplayer-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-moveplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mp-feedback"></div>
			<div class="ka-field ka-field-ac">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mp-player-name" autocomplete="off" placeholder="Search players...">
				<input type="hidden" id="ka-mp-player-id">
				<div class="kn-ac-results" id="ka-mp-player-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label>New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mp-park-name" autocomplete="off" placeholder="Search all parks...">
				<input type="hidden" id="ka-mp-park-id">
				<div class="kn-ac-results" id="ka-mp-park-results"></div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-moveplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-mp-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- ---- Merge Players ---- -->
<div class="ka-overlay" id="ka-mergeplayer-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-compress-alt ka-icon-danger" style="margin-right:8px"></i>Merge Players</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-mergeplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mgp-feedback"></div>
			<!-- The old copy said everything "transfers". It does not: Player::MergePlayer
			     DELETEs the colliding rows in attendance / event_rsvp /
			     class_reconciliation before it re-points the rest, and ork_awards is a
			     plain UPDATE with no de-duplication. Officers were making an
			     irreversible decision against a description of a different operation. -->
			<div class="ka-warning">
				<i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
				<div>
					<strong>This permanently deletes the <em>Remove</em> player's account. It cannot be undone.</strong>
					<ul class="ka-confirm-list">
						<li><strong>Attendance is not fully merged.</strong> Any attendance the <em>Remove</em> player has on a date the <em>Keep</em> player also has a record for is <strong>deleted</strong>, not carried over. The rest transfers.</li>
						<li><strong>Event RSVPs and class reconciliations</strong> that would collide with one the <em>Keep</em> player already holds are <strong>deleted</strong> the same way.</li>
						<li><strong>Awards transfer with no de-duplication.</strong> If both players hold the same award, the surviving record ends up holding it <strong>twice</strong>, and you will have to strip the extra by hand afterwards.</li>
						<li>Officer history, unit memberships, dues, notes and recommendations transfer intact.</li>
						<li>The <em>Remove</em> player's login stops working. Only the <em>Keep</em> player's login survives.</li>
					</ul>
					<!-- No .ka-muted here: this sits on .ka-warning's tinted panel, where
					     --ork-text-muted is not guaranteed to hold contrast. Inheriting
					     the warning's own colour is correct in both themes. -->
					<p class="ka-mgp-note">The merge is written to the Audit Log, but there is no undo button &mdash; reversing one is a manual repair.</p>
				</div>
			</div>
			<div class="ka-field ka-field-ac">
				<label for="ka-mgp-keep-name">Player to Keep <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-mgp-keep-name" autocomplete="off" placeholder="Search for player to keep..." aria-required="true">
				<input type="hidden" id="ka-mgp-keep-id">
				<div class="kn-ac-results" id="ka-mgp-keep-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label for="ka-mgp-remove-name">Player to Remove &mdash; <span class="ka-danger-note"><i class="fas fa-skull-crossbones" aria-hidden="true"></i> permanently deleted</span> <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-mgp-remove-name" autocomplete="off" placeholder="Search for player to remove..." aria-required="true">
				<input type="hidden" id="ka-mgp-remove-id">
				<div class="kn-ac-results" id="ka-mgp-remove-results"></div>
			</div>
			<!-- Side-by-side preview, built by JS once both sides are picked. Two
			     players with the same persona is the exact case this modal exists for,
			     so a name in a text box is not enough to tell them apart. -->
			<div class="ka-mgp-preview" id="ka-mgp-preview" style="display:none">
				<div class="ka-mgp-cards">
					<div class="ka-mgp-card ka-mgp-card-keep" id="ka-mgp-card-keep"></div>
					<button type="button" class="ka-mgp-swap" id="ka-mgp-swap"
						data-tip="Swap Keep and Remove" aria-label="Swap the Keep and Remove players">
						<i class="fas fa-exchange-alt" aria-hidden="true"></i>
					</button>
					<div class="ka-mgp-card ka-mgp-card-remove" id="ka-mgp-card-remove"></div>
				</div>
				<span class="ka-mgp-swap-hint ka-muted" id="ka-mgp-swap-hint" role="status" aria-live="polite">Picked them the wrong way round? Use the swap button.</span>
				<!-- Honest about the gap: the kingdom player-search endpoint returns
				     park / kingdom / active / suspended and nothing else, so award,
				     attendance, office and unit counts genuinely are not available
				     here. Say so and point at the profiles rather than showing a
				     confident-looking zero. -->
				<p class="ka-mgp-note ka-muted">Award, attendance, office and unit counts are not available from this search. Open both profiles in a new tab if you need to compare them before committing.</p>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-mergeplayer-overlay')">Cancel</button>
			<!-- Wait-to-arm rather than a type-the-name gate: the first click starts a
			     five-second countdown, the second click commits. See the .ka-arm-btn
			     contract in admin-console.css. -->
			<button class="adm-btn adm-btn-danger ka-arm-btn" id="ka-mgp-submit" disabled>
				<i class="fas fa-compress-alt" aria-hidden="true"></i> <span id="ka-mgp-submit-label">Merge Players</span>
				<span class="ka-arm-count" id="ka-mgp-arm-count" role="status" aria-live="polite" style="display:none"></span>
			</button>
		</div>
	</div>
</div>

<!-- ---- Claim Park ---- -->
<div class="ka-overlay" id="ka-claimpark-overlay">
	<div class="ka-modal-box" style="width:460px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-flag ka-icon-ok" style="margin-right:8px"></i>Claim Park</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-claimpark-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-cp-feedback"></div>
			<!-- This modal can only ever be instructions: moving a park between orgs
			     needs global admin authority, which nobody reading this console has.
			     Saying so makes the dead end read as policy instead of half-built
			     software an officer should keep hunting for a button for. -->
			<p class="ka-cp-policy ka-muted-strong">
				A park can only be moved between <?= htmlspecialchars(strtolower($entityLabel)) ?>s by ORK staff, after the Board of Directors approves the transfer.
				There is no button here that will do it &mdash; send the request below and staff will make the change once it is approved.
			</p>
			<p class="ka-muted-strong" style="margin:0 0 10px">To claim a park, submit documentation authorising the move &mdash; Althing results if you have them &mdash; to:</p>
			<div class="ka-cp-actions">
				<a class="adm-btn adm-btn-primary" id="ka-cp-mailto"
					href="mailto:Contracts@amtgard.com?subject=<?= rawurlencode('Park Claim Request - ' . ($AdminInfo['Name'] ?? '')) ?>&body=<?= rawurlencode($entityLabel . ': ' . ($AdminInfo['Name'] ?? '') . "\nPark Name: \nAlthing Results: \nReason for Claim: ") ?>">
					<i class="fas fa-envelope" aria-hidden="true"></i> Email Contracts@amtgard.com
				</a>
				<!-- Not everyone has a mail handler wired to mailto:. The copy control is
				     the webmail escape hatch. -->
				<button type="button" class="adm-btn adm-btn-ghost" id="ka-cp-copy"
					data-address="Contracts@amtgard.com" data-tip="Copy the address to your clipboard">
					<i class="fas fa-copy" aria-hidden="true"></i> <span id="ka-cp-copy-label">Copy address</span>
				</button>
			</div>
			<p class="ka-cp-address ka-muted" style="margin:0 0 10px">Contracts@amtgard.com</p>
			<p class="ka-muted" style="margin:0;font-size:12px">Include the park name, your <?= htmlspecialchars(strtolower($entityLabel)) ?>, and any supporting documentation.</p>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-claimpark-overlay')">Close</button>
		</div>
	</div>
</div>

<!-- ---- Operations ---- -->
<div class="ka-overlay" id="ka-ops-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<!-- Titled to match the console tile that opens it ("Reset Waivers &
			     Status"). The tile and this header used to disagree, so the officer
			     could not tell whether they had opened the right thing. -->
			<h3 class="ka-modal-title"><i class="fas fa-cogs ka-icon-warn" style="margin-right:8px"></i>Reset Waivers &amp; Status</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-ops-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-ops-feedback"></div>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Reset Waivers</strong>
					<p>Sets every player's waiver flag back to unsigned. The update has <strong>no active filter</strong>, so it clears inactive and retired members along with your current roster.</p>
					<?php if ($_kaWaivered === null): ?>
					<span class="ka-ops-blast ka-notice-warn" id="ka-ops-blast"><i class="fas fa-question-circle" aria-hidden="true"></i><span>The number of players holding a waiver could not be read for this <?= $_kaEntityLc ?>. Check the Waivered Players report before you run this.</span></span>
					<?php elseif ($_kaWaivered === 0): ?>
					<span class="ka-ops-blast ka-muted" id="ka-ops-blast">No players in this <?= $_kaEntityLc ?> currently hold a waiver, so there is nothing to clear.</span>
					<?php else: ?>
					<span class="ka-ops-blast ka-danger-note" id="ka-ops-blast"><i class="fas fa-users" aria-hidden="true"></i><span><?= number_format($_kaWaivered) ?> player<?= $_kaWaivered === 1 ? '' : 's' ?> currently hold<?= $_kaWaivered === 1 ? 's' : '' ?> a waiver and would be cleared.</span></span>
					<?php endif; ?>
					<!-- The old copy said "cannot be undone", full stop. Player::ResetWaivers
					     writes a danger-audit row carrying every cleared mundane_id, so the
					     list is recoverable -- just not from this button. The Audit Log
					     itself is AUTH_ADMIN-only, so the link is gated; everyone else is
					     told the record exists rather than handed a link that bounces. -->
					<p class="ka-muted">There is no undo here, but every player cleared is recorded in the Audit Log<?php if (!empty($IsOrkAdmin)): ?> &mdash; <a href="<?= UIR ?>Admin/auditlog&amp;EntityType=Kingdom&amp;EntityId=<?= $kid ?>">open this <?= $_kaEntityLc ?>'s audit trail</a><?php else: ?>, which ORK staff can review on request<?php endif; ?>.</p>
				</div>
				<button class="ka-ops-btn ka-ops-btn-danger" id="ka-ops-reset-waivers"
					data-count="<?= $_kaWaivered === null ? '' : (int)$_kaWaivered ?>"
					data-entity="<?= $_kaEntityLc ?>"<?= $_kaWaivered === 0 ? ' disabled' : '' ?>>
					<i class="fas fa-eraser" aria-hidden="true"></i> Reset Waivers
				</button>
			</div>
			<?php if (!empty($IsOrkAdmin)):
				$isActive = ($AdminInfo['Active'] ?? 'Active') === 'Active'; ?>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Active Status</strong>
					<p>This <?= strtolower($entityLabel) ?> is currently <strong id="ka-ops-status-label"><?= $isActive ? 'Active' : 'Inactive' ?></strong>.</p>
				</div>
				<button class="ka-ops-btn<?= $isActive ? ' ka-ops-btn-danger' : '' ?>"
					id="ka-ops-status-toggle" data-active="<?= $isActive ? '1' : '0' ?>">
					<?php if ($isActive): ?>
						<i class="fas fa-ban"></i> Mark Inactive
					<?php else: ?>
						<i class="fas fa-check-circle"></i> Restore to Active
					<?php endif; ?>
				</button>
			</div>
			<?php endif; ?>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-ops-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Principality Status (ORK Admins only) ---- -->
<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
<div class="ka-overlay" id="ka-prinz-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#c05621"></i>Principality Status</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-prinz-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-prinz-feedback"></div>
			<p style="margin:0 0 12px;font-size:13px;color:#4a5568">
				This is a <strong>Principality</strong> sponsored by
				<strong><?= htmlspecialchars($AdminInfo['ParentKingdomName'] ?? '') ?></strong>.
			</p>
			<div class="ka-field ka-field-ac">
				<label>Change Sponsor Kingdom</label>
				<input type="text" id="ka-prinz-parent-name" autocomplete="off"
					placeholder="Search kingdoms..."
					value="<?= htmlspecialchars($AdminInfo['ParentKingdomName'] ?? '') ?>">
				<input type="hidden" id="ka-prinz-parent-id"
					value="<?= (int)($AdminInfo['ParentKingdomId'] ?? 0) ?>">
				<div class="kn-ac-results" id="ka-prinz-parent-results"></div>
			</div>
			<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
				<button class="ka-save-btn" id="ka-prinz-sponsor-save">
					<i class="fas fa-save"></i> Save Sponsor
				</button>
				<button class="ka-save-btn" id="ka-prinz-promote"
					style="background:#c05621;border-color:#c05621">
					<i class="fas fa-crown"></i> Convert to Kingdom
				</button>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-prinz-overlay')">Done</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- ---- Confirmation Dialog ---- -->
<div class="ka-confirm-overlay" id="ka-confirm-overlay">
	<div class="ka-modal-box ka-confirm-box">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title" id="ka-confirm-title"><i class="fas fa-exclamation-triangle ka-icon-danger" style="margin-right:8px"></i>Confirm</h3>
			<button class="ka-modal-close" id="ka-confirm-close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<!-- A <div>, not a <p>: kaConfirm(..., {html:true}) puts a <ul> in here to
			     spell out a blast radius, and a <ul> inside a <p> is invalid and gets
			     re-parented out of it by the browser. -->
			<div id="ka-confirm-message" class="ka-confirm-body"></div>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="adm-btn adm-btn-ghost" id="ka-confirm-cancel">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="ka-confirm-alt" style="display:none"></button>
			<button class="adm-btn adm-btn-primary" id="ka-confirm-ok">Confirm</button>
		</div>
	</div>
</div>

<?php if ($mo_can_manage): ?>
<!-- ---- Manage Officers Host Modal (--z-modal; partial sub-modals render at --z-modal-top / --z-help-overlay) ---- -->
<div class="ka-mo-overlay" id="ka-mo-overlay">
	<div class="ka-mo-box">
		<div class="ka-mo-header">
			<h3 class="ka-mo-title"><i class="fas fa-user-shield" style="color:#b7791f"></i> Manage Officers</h3>
			<button class="ka-mo-close" type="button" onclick="kaCloseManageOfficers()" aria-label="Close">&times;</button>
		</div>
		<div class="ka-mo-body">
<?php include __DIR__ . '/_manage_officers.tpl'; ?>
		</div>
	</div>
</div>
<script>
(function() {
	var overlay = document.getElementById('ka-mo-overlay');
	function openMo() {
		if (!overlay) return;
		overlay.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		// A prior visit may have left the transition wizard (#ot-root) visible if the
		// whole modal was dismissed (Escape / backdrop / X) while the wizard was open --
		// closeMo() only hides the overlay, it never runs the wizard's own teardown. Force
		// the officer-list view back to the front on every open so a stale wizard never
		// sits beneath the freshly-loaded #mo-cards (moLoad() shows #mo-cards on its own
		// timeline and does not know #ot-root exists).
		var otRoot  = document.getElementById('ot-root');
		var moCards = document.getElementById('mo-cards');
		if (otRoot)  { otRoot.style.display = 'none'; }
		if (moCards) { moCards.style.display = ''; }
		if (typeof window.moRefresh === 'function') { try { window.moRefresh(); } catch (e) {} }
	}
	function closeMo() {
		if (!overlay) return;
		overlay.classList.remove('ka-open');
		document.body.style.overflow = '';
	}
	window.kaOpenManageOfficers = openMo;
	window.kaCloseManageOfficers = closeMo;
	if (overlay) {
		overlay.addEventListener('click', function(e) { if (e.target === overlay) closeMo(); });
	}
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay && overlay.classList.contains('ka-open')) closeMo();
	});
})();
</script>
<?php endif; ?>

<!-- =============================================
     JAVASCRIPT
     ============================================= -->
<script>
var KaConfig = {
	uir:              '<?= UIR ?>',
	kingdomId:        <?= $kid ?>,
	kingdomName:      <?= json_encode($AdminInfo['Name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	/* "Kingdom" or "Principality". This console serves both, so no script-built
	   string may hardcode either word. */
	entityLabel:      <?= json_encode($entityLabel, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	entityLabelLc:    <?= json_encode(strtolower($entityLabel), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	hasHeraldry:      <?= $hasHeraldry ? 'true' : 'false' ?>,
	canManage:        true,
	isOrkAdmin:       <?= !empty($IsOrkAdmin) ? 'true' : 'false' ?>,
	parkTitleOptions: <?= json_encode($ParkTitleId_options ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkEditLookup:   <?= json_encode(array_values($park_edit_lookup ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:      <?= json_encode($AdminConfig ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles:  <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:      <?= json_encode($AdminAwards ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	systemAwards:     <?= json_encode($SystemAwards ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminInfo:        <?= json_encode($AdminInfo ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
/* Shared autocomplete positioner (kn-ac dropdowns inside a modal must escape the box's
   overflow, so they are re-anchored with position:fixed before every kn-ac-open).

   partials/_manage_officers.tpl also carries a guarded copy, but that partial is included
   ONLY inside `if ($mo_can_manage)`. Move Player, Merge Players and Create Player are not
   gated on officer-management rights, so for an officer without them that copy never
   loads and their dropdowns get clipped. Define it here, in the unconditional block, with
   the same guard the partial uses so whichever runs first wins and neither double-defines.
   revised.js (its usual home) is not loaded on this page. */
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
</script>
<script>
(function() {
	if (!KaConfig.canManage) return;

	var UIR = KaConfig.uir;
	var BASE_URL = UIR + 'KingdomAjax/kingdom/' + KaConfig.kingdomId + '/';

	function gid(id) { return document.getElementById(id); }

	/* ── Modal helpers ────────────────────────────── */

	/* Open overlays, bottom → top. Escape and the Tab trap act on the LAST entry only,
	   so dismissing a confirm no longer takes the modal that raised it with it. */
	var _kaStack  = [];
	var _kaOpener = {};   // overlay id → element that opened it, for focus restore
	var _kaClean  = {};   // overlay id → field snapshot taken when it opened

	/* Modals that must never reopen carrying an abandoned selection. Value = submit
	   buttons to re-disable on open (they are enabled only once a pick is made). */
	var KA_RESET_ON_OPEN = {
		'ka-moveplayer-overlay':   ['ka-mp-submit'],
		'ka-mergeplayer-overlay':  ['ka-mgp-submit'],
		'ka-createplayer-overlay': [],
		// Heraldry kept an abandoned pick AND its data: URI preview between opens, so
		// the modal could reopen showing an image that was never saved, over a
		// caption that said "New image — not saved yet" from the previous visit.
		'ka-heraldry-overlay':     ['ka-heraldry-upload'],
		// Configuration's controls are built once by JS, not re-fetched, so abandoned
		// edits would otherwise still be sitting in the boxes on the next open AND be
		// snapshotted as the clean baseline. The builder stamps defaultValue /
		// defaultChecked / defaultSelected with the STORED value, so this reset puts
		// the panel back to what the database actually holds.
		'ka-config-overlay':       []
	};

	/* Per-overlay hooks run on open, after reset-to-defaults and before the clean
	   snapshot is taken, so anything a hook changes counts as "not dirty". Used by
	   Create Player to re-sync the password-mode disclosure, which a programmatic
	   reset cannot do on its own (assigning .checked fires no change event). */
	var _kaOnOpen = {};
	function kaOnOpen(id, fn) { (_kaOnOpen[id] = _kaOnOpen[id] || []).push(fn); }

	/* Modal id -> function(done) that saves that modal's pending edits and calls
	   done(ok) when finished. A modal with no entry here simply gets the
	   two-button discard prompt: offering "Save Changes" for a modal we cannot
	   actually save would be a lie. Populated by each modal's own IIFE below,
	   once it has built whatever it needs to find its own dirty state. */
	var KA_MODAL_SAVE = {};

	/* Transient UI filters — typing in them is not "work", so they stay out of the
	   dirty check (the alias picker clears its own search box every time it opens). */
	var KA_NODIRTY_IDS = ['ka-alias-search', 'ka-awards-search', 'ka-parks-search'];

	var KA_FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function kaBoxOf(ov)   { return ov.querySelector('.ka-modal-box') || ov; }
	function kaPush(id)    { kaPop(id); _kaStack.push(id); }
	function kaPop(id)     { var i = _kaStack.indexOf(id); if (i >= 0) _kaStack.splice(i, 1); }
	function kaTop()       { return _kaStack.length ? gid(_kaStack[_kaStack.length - 1]) : null; }

	/* ── Dirty guard ──────────────────────────────── */
	function kaFields(ov) {
		return Array.prototype.slice.call(ov.querySelectorAll('input, select, textarea'))
			.filter(function(f) { return KA_NODIRTY_IDS.indexOf(f.id) === -1; });
	}
	function kaValueOf(f) {
		if (f.type === 'checkbox' || f.type === 'radio') return f.checked ? '1' : '0';
		return f.value == null ? '' : String(f.value);
	}
	function kaBaselineOf(f) {
		// Edit Details stamps data-original on its four fields and refreshes it after a
		// successful save — trust that over the live value when it is present.
		if (f.dataset && typeof f.dataset.original === 'string') return f.dataset.original;
		return kaValueOf(f);
	}
	function kaSnapshot(ov, useBaseline) {
		return kaFields(ov).map(useBaseline ? kaBaselineOf : kaValueOf).join('\u0001');
	}
	function kaIsDirty(ov) {
		return (ov.id in _kaClean) && kaSnapshot(ov, false) !== _kaClean[ov.id];
	}
	function kaMarkClean(ov) { if (ov && (ov.id in _kaClean)) _kaClean[ov.id] = kaSnapshot(ov, false); }

	/* ── Reset on open ────────────────────────────── */
	function kaResetFields(ov) {
		kaFields(ov).forEach(function(f) {
			if (f.type === 'checkbox' || f.type === 'radio') { f.checked = f.defaultChecked; return; }
			if (f.tagName === 'SELECT') {
				var idx = 0;
				for (var i = 0; i < f.options.length; i++) { if (f.options[i].defaultSelected) { idx = i; break; } }
				f.selectedIndex = idx;
				return;
			}
			if (f.type === 'file') { try { f.value = ''; } catch (e) {} return; }
			f.value = (typeof f.defaultValue === 'string') ? f.defaultValue : '';
		});
		// Autocomplete dropdowns keep their results — and their fixed position — between opens.
		ov.querySelectorAll('.kn-ac-results').forEach(function(r) {
			r.classList.remove('kn-ac-open');
			r.innerHTML = '';
			r.style.position = ''; r.style.top = ''; r.style.left = ''; r.style.width = ''; r.style.zIndex = '';
		});
		(KA_RESET_ON_OPEN[ov.id] || []).forEach(function(bid) { var b = gid(bid); if (b) b.disabled = true; });
		ov.querySelectorAll('.ka-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
	}

	/* ── Focus management ─────────────────────────── */
	function kaFocusables(ov) {
		return Array.prototype.slice.call(kaBoxOf(ov).querySelectorAll(KA_FOCUSABLE))
			.filter(function(f) { return f.type !== 'hidden' && f.offsetParent !== null; });
	}
	function kaFocusFirst(ov, preferId) {
		var preferred = preferId ? gid(preferId) : null;
		var items = kaFocusables(ov);
		var field = items.filter(function(f) { return f.tagName === 'INPUT' || f.tagName === 'SELECT' || f.tagName === 'TEXTAREA'; })[0];
		var target = preferred || field || items[0] || kaBoxOf(ov);
		if (target === kaBoxOf(ov)) target.setAttribute('tabindex', '-1');
		try { target.focus(); } catch (e) {}
	}
	function kaRestoreFocus(id) {
		var opener = _kaOpener[id];
		delete _kaOpener[id];
		if (!opener || opener === document.body || typeof opener.focus !== 'function') return;
		if (!document.contains(opener)) return;
		try { opener.focus(); } catch (e) {}
	}

	/* Safari does not focus a <button> on click, so document.activeElement is not a
	   reliable record of what opened a modal. Remember the last clicked control too. */
	var _kaLastClick = null;
	document.addEventListener('mousedown', function(e) {
		_kaLastClick = e.target && e.target.closest ? e.target.closest('button, a[href], [role="button"]') : null;
	}, true);
	function kaOpenerNow() {
		var a = document.activeElement;
		return (a && a !== document.body) ? a : _kaLastClick;
	}

	function kaOpenModal(id, opener) {
		var el = gid(id);
		if (!el) return;
		_kaOpener[id] = opener || kaOpenerNow();
		if (KA_RESET_ON_OPEN[id]) kaResetFields(el);
		(_kaOnOpen[id] || []).forEach(function(fn) { try { fn(el); } catch (e) {} });
		_kaClean[id] = kaSnapshot(el, true);
		el.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		kaPush(id);
		kaFocusFirst(el);
	}
	function kaCloseModal(id, force) {
		var el = gid(id);
		if (!el) return;
		if (!force && el.classList.contains('ka-open') && kaIsDirty(el)) {
			var saver = KA_MODAL_SAVE[id];
			if (saver) {
				// Three buttons. Discard Changes is the destructive middle action
				// (the alt slot, always red); Save Changes is the safe default on
				// OK (green) and reuses this modal's own save path -- if it fails,
				// the modal stays open (see the saver itself) so the officer never
				// sees "closed" when their edits did not actually land.
				kaConfirm('Discard your changes?', function() {
					saver(function(ok) { if (ok) kaCloseModal(id, true); });
				}, 'Unsaved Changes', {
					cancelLabel: 'Go Back',
					okLabel: 'Save Changes',
					altLabel: 'Discard Changes',
					onAlt: function() { kaCloseModal(id, true); }
				});
			} else {
				// No registered save action for this modal -- two buttons, same as
				// every other confirm in this file.
				kaConfirm('Discard your changes?', function() { kaCloseModal(id, true); }, 'Unsaved Changes', {
					cancelLabel: 'Go Back',
					okLabel: 'Discard Changes',
					danger: true
				});
			}
			return;
		}
		el.classList.remove('ka-open');
		kaPop(id);
		delete _kaClean[id];
		document.body.style.overflow = _kaStack.length ? 'hidden' : '';
		el.querySelectorAll('.ka-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
		kaRestoreFocus(id);
	}
	window.kaOpenModal  = kaOpenModal;
	window.kaCloseModal = kaCloseModal;

	/* Dialog semantics, wired from script so the markup stays untouched. */
	document.querySelectorAll('.ka-overlay, .ka-confirm-overlay').forEach(function(ov) {
		ov.setAttribute('role', ov.classList.contains('ka-confirm-overlay') ? 'alertdialog' : 'dialog');
		ov.setAttribute('aria-modal', 'true');
		var title = ov.querySelector('.ka-modal-title');
		if (title) {
			if (!title.id) title.id = ov.id + '-title';
			ov.setAttribute('aria-labelledby', title.id);
		}
	});
	document.querySelectorAll('.ka-feedback').forEach(function(f) {
		f.setAttribute('aria-live', 'polite');
		f.setAttribute('aria-atomic', 'true');
	});

	// Close on backdrop click
	document.querySelectorAll('.ka-overlay').forEach(function(ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) kaCloseModal(ov.id); });
	});
	// Close on Escape — topmost overlay only
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Escape') return;
		var ov = kaTop();
		if (!ov) return;
		// An open autocomplete is "topmost" too — its own handler dismisses it.
		if (ov.querySelector('.kn-ac-results.kn-ac-open')) return;
		if (ov.id === 'ka-confirm-overlay') kaCloseConfirm();
		else kaCloseModal(ov.id);
	});
	// Keep Tab inside the topmost overlay
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Tab') return;
		var ov = kaTop();
		if (!ov) return;
		var items = kaFocusables(ov);
		if (!items.length) return;
		var first = items[0], last = items[items.length - 1];
		if (!kaBoxOf(ov).contains(document.activeElement)) {
			e.preventDefault();
			(e.shiftKey ? last : first).focus();
		} else if (e.shiftKey && document.activeElement === first) {
			e.preventDefault(); last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault(); first.focus();
		}
	});

	/* ── Feedback helper ──────────────────────────── */
	function kaFeedback(id, msg, ok) {
		var el = gid(id);
		if (!el) return;
		el.className = 'ka-feedback ' + (ok ? 'ka-feedback-ok' : 'ka-feedback-err');
		// Announce to screen readers: successes politely, errors as alerts.
		el.setAttribute('role', ok ? 'status' : 'alert');
		el.setAttribute('aria-live', ok ? 'polite' : 'assertive');
		el.setAttribute('aria-atomic', 'true');
		// Show first, then write — a live region that is display:none when its text
		// changes is not reliably announced.
		el.style.display = 'block';
		el.innerHTML = msg;
		if (ok) {
			clearTimeout(el._t);
			el._t = setTimeout(function() { el.style.display = 'none'; }, 5000);
			// A successful save is the new clean baseline. Deferred one tick because
			// several callers clear their fields immediately after this returns.
			var ov = el.closest('.ka-overlay');
			if (ov) setTimeout(function() { kaMarkClean(ov); }, 0);
		}
	}
	function kaClearFeedback(id) {
		var el = gid(id); if (el) { el.style.display = 'none'; el.innerHTML = ''; }
	}

	/* ── Confirm dialog ───────────────────────────── */
	var _kaConfirmCb    = null;
	var _kaConfirmAltCb = null;
	/* kaConfirm(message, onConfirm, title, opts)
	     opts.danger      — .ka-confirm-danger on the overlay: red rule + red OK button.
	                        The default OK is the same navy primary as "Save Details",
	                        which is the wrong signal in front of a permanent delete.
	     opts.html        — treat `message` as markup. Only ever pass strings BUILT IN
	                        THIS FILE; never a server or user string.
	     opts.okLabel     — replaces "Confirm" on the OK button (escaped).
	     opts.okIcon      — FontAwesome classes for an icon before that label.
	     opts.cancelLabel — replaces "Cancel" on the Cancel button (escaped).
	     opts.altLabel    — shows a THIRD button (#ka-confirm-alt) with this label
	                        (escaped), between Cancel and OK. Absent = two buttons,
	                        exactly today's behaviour.
	     opts.onAlt       — callback fired when the alt button is clicked, the same
	                        way onConfirm fires for OK.
	   Title, labels and danger/alt state are reset on EVERY call: they are shared
	   controls, and a previous caller's values used to survive into the next,
	   unrelated confirm. The alt button reset is the important one — kaConfirm
	   reuses this one overlay for every caller in this file, so a three-button
	   prompt (Save Changes) must never leak its extra button into the next
	   ordinary two-button confirm (e.g. Retire Award). */
	function kaConfirm(message, onConfirm, title, opts) {
		var overlay = gid('ka-confirm-overlay');
		if (!overlay) return;
		opts = opts || {};
		var body = gid('ka-confirm-message');
		if (opts.html) { body.innerHTML = message; } else { body.textContent = message; }
		gid('ka-confirm-title').childNodes[1].textContent = ' ' + (title || 'Confirm');
		var cancelBtn = gid('ka-confirm-cancel');
		if (cancelBtn) cancelBtn.textContent = opts.cancelLabel || 'Cancel';
		var okBtn = gid('ka-confirm-ok');
		if (okBtn) {
			okBtn.innerHTML = (opts.okIcon ? '<i class="' + kaEsc(opts.okIcon) + '" aria-hidden="true"></i> ' : '')
				+ kaEsc(opts.okLabel || 'Confirm');
		}
		// Reset the third button to hidden UNCONDITIONALLY, before deciding whether
		// this call wants it. That order is the whole fix for the leak above.
		var altBtn = gid('ka-confirm-alt');
		_kaConfirmAltCb = null;
		if (altBtn) {
			altBtn.innerHTML = '';
			altBtn.style.display = 'none';
			if (opts.altLabel) {
				altBtn.innerHTML = kaEsc(opts.altLabel);
				altBtn.style.display = '';
				_kaConfirmAltCb = opts.onAlt || null;
			}
		}
		overlay.classList.toggle('ka-confirm-danger', !!opts.danger);
		overlay.classList.toggle('ka-confirm-triple', !!opts.altLabel);
		_kaConfirmCb = onConfirm;
		_kaOpener['ka-confirm-overlay'] = kaOpenerNow();
		overlay.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		kaPush('ka-confirm-overlay');
		// Land on Cancel/Go Back: the confirm usually guards something destructive,
		// and when a third button is present the destructive action (Discard
		// Changes) sits in the MIDDLE of the row, not on OK -- a stray Enter must
		// still land on the safe choice.
		kaFocusFirst(overlay, 'ka-confirm-cancel');
	}
	function kaCloseConfirm() {
		var overlay = gid('ka-confirm-overlay');
		if (overlay) overlay.classList.remove('ka-open');
		_kaConfirmCb    = null;
		_kaConfirmAltCb = null;
		kaPop('ka-confirm-overlay');
		document.body.style.overflow = _kaStack.length ? 'hidden' : '';
		kaRestoreFocus('ka-confirm-overlay');
	}
	gid('ka-confirm-ok') && gid('ka-confirm-ok').addEventListener('click', function() { var cb = _kaConfirmCb; kaCloseConfirm(); if (cb) cb(); });
	gid('ka-confirm-alt') && gid('ka-confirm-alt').addEventListener('click', function() { var cb = _kaConfirmAltCb; kaCloseConfirm(); if (cb) cb(); });
	gid('ka-confirm-cancel') && gid('ka-confirm-cancel').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-close') && gid('ka-confirm-close').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-overlay') && gid('ka-confirm-overlay').addEventListener('click', function(e) { if (e.target === this) kaCloseConfirm(); });

	/* ── Autocomplete helper (kn-ac-results pattern) ── */
	function kaAc(opts) {
		var input   = gid(opts.inputId);
		var hidden  = gid(opts.hiddenId);
		var results = gid(opts.resultsId);
		if (!input || !results) return;
		var timer = null, minLen = opts.minLen || 2;

		function acClose() { results.classList.remove('kn-ac-open'); results.innerHTML = ''; }
		function acOpen(items) {
			if (!items.length) {
				results.innerHTML = '<div class="kn-ac-item" style="color:#a0aec0;pointer-events:none">No results</div>';
				if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
				results.classList.add('kn-ac-open');
				return;
			}
			results.innerHTML = items.map(function(item) {
				return '<div class="kn-ac-item" tabindex="-1" data-id="' + item.id
					+ '" data-name="' + encodeURIComponent(item.label)
					+ (item.extra !== undefined ? '" data-extra="' + encodeURIComponent(item.extra) : '')
					+ '">' + item.html + '</div>';
			}).join('');
			// Fixed positioning for modals (shared helper)
			if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
			results.classList.add('kn-ac-open');
		}
		function selectItem(item) {
			input.value  = decodeURIComponent(item.dataset.name);
			hidden.value = item.dataset.id;
			acClose();
			if (opts.onSelect) opts.onSelect(item.dataset.id, input.value, item.dataset.extra ? decodeURIComponent(item.dataset.extra) : '');
		}
		input.addEventListener('input', function() {
			var term = this.value.trim();
			hidden.value = '';
			clearTimeout(timer);
			if (opts.onClear) opts.onClear();
			if (term.length < minLen) { acClose(); return; }
			timer = setTimeout(function() {
				opts.fetchFn(term, function(items) { acOpen(items); });
			}, 220);
		});
		results.addEventListener('click', function(e) {
			var item = e.target.closest('.kn-ac-item[data-id]');
			if (!item) return;
			selectItem(item);
		});
		document.addEventListener('click', function(e) {
			if (!e.target.closest('#' + opts.inputId + ', #' + opts.resultsId)) acClose();
		});
		input.addEventListener('keydown', function(e) {
			var items = results.querySelectorAll('.kn-ac-item[data-id]');
			if (!items.length) return;
			var focused = results.querySelector('.kn-ac-focused');
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				var next = focused ? (focused.nextElementSibling || items[0]) : items[0];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (next && next.dataset.id) next.classList.add('kn-ac-focused');
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var prev = focused ? (focused.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (prev && prev.dataset.id) prev.classList.add('kn-ac-focused');
			} else if (e.key === 'Enter' && focused) {
				e.preventDefault(); selectItem(focused);
			} else if (e.key === 'Escape') {
				acClose();
			}
		});
	}

	/* ── Search helpers ───────────────────────────── */
	function kaEsc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	/* Kingdom-scoped player search (house rule: player search is scoped, &q= form).
	   This page is a single kingdom's admin console, so scope=own is correct. */
	/* Row shape is fixed by SearchService::formatScopedPlayerRow's default format:
	   MundaneId, Persona, KingdomId, ParkId, KingdomName, ParkName, KAbbr, PAbbr,
	   Suspended, Active. Suspended came back on every row and the renderer threw it
	   away -- and the ID was never shown at all, so two players with the same
	   persona in the same park (the exact case Merge Players exists for) produced
	   two identical rows with no way to tell which was which. */
	function kaPlayerFlags(p) {
		var out = '';
		if (Number(p.Suspended) === 1) out += ' <span class="ka-ac-flag ka-ac-flag-suspended">suspended</span>';
		if (Number(p.Active) === 0)    out += ' <span class="ka-ac-flag ka-ac-flag-inactive">inactive</span>';
		return out;
	}
	function kaSearchPlayers(q, cb) {
		fetch(UIR + 'KingdomAjax/playersearch/' + KaConfig.kingdomId + '&q=' + encodeURIComponent(q) + '&scope=own&include_inactive=1&include_suspended=1')
		.then(function(r){return r.json();}).then(function(d) {
			cb((d || []).map(function(p) {
				return {
					id: p.MundaneId,
					label: p.Persona,
					extra: JSON.stringify(p),
					html: kaEsc(p.Persona)
						+ ' <span class="ka-ac-meta">(' + kaEsc(p.KAbbr) + ' &middot; ' + kaEsc(p.ParkName) + ' &middot; #' + kaEsc(p.MundaneId) + ')</span>'
						+ kaPlayerFlags(p)
				};
			}));
		}).catch(function(){cb([]);});
	}
	function kaSearchParks(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=park&q=' + encodeURIComponent(q))
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.parks || []).map(function(p) {
				return { id: p.id, label: p.name, extra: p.kingdom || '', html: kaEsc(p.name) + (p.kingdom ? ' <span style="color:#a0aec0;font-size:11px">[' + kaEsc(p.kingdom) + ']</span>' : '') };
			}));
		}).catch(function(){cb([]);});
	}
	function kaSearchKingdoms(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=kingdom&q=' + encodeURIComponent(q))
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.kingdoms || []).map(function(k) {
				return { id: k.id, label: k.name, html: kaEsc(k.name) + ' <span style="color:#a0aec0;font-size:11px">(' + kaEsc(k.abbr) + ')</span>' };
			}));
		}).catch(function(){cb([]);});
	}

	/* ── POST helper ──────────────────────────────── */
	/* onFinally (optional) runs on BOTH the success and the failure path, after the
	   button is re-enabled. Callers that swap a button's label into a busy state
	   need a hook that fires when the request lost, too -- without one a failed
	   POST leaves "Merging…" on the button forever. */
	function kaPost(url, data, btn, feedbackId, onSuccess, onFinally) {
		if (btn) btn.disabled = true;
		var fd = new FormData();
		Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
		fetch(url, { method: 'POST', body: fd })
		.then(function(r) { return r.json(); })
		.then(function(r) {
			if (btn) btn.disabled = false;
			if (onFinally) onFinally();
			if (r.status === 0) { onSuccess(r); }
			else { kaFeedback(feedbackId, r.error || 'An error occurred.', false); }
		})
		.catch(function() {
			if (btn) btn.disabled = false;
			if (onFinally) onFinally();
			kaFeedback(feedbackId, 'Request failed. Please try again.', false);
		});
	}

	/* ══════════════════════════════════════════════
	   EDIT DETAILS
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-details-save');
		if (!btn) return;

		/* kingdom.abbreviation is varchar(3). sql_mode is empty on this stack, so MySQL
		   truncates instead of erroring: "WETL" was stored as "WET" while the box still
		   showed "WETL" and the banner said saved. Normalise to exactly what the column
		   will keep, at the point the officer types it. */
		var ABBR_MAX = 3;
		function normAbbr(v) {
			return String(v || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, ABBR_MAX);
		}

		var abbrTimer = null;
		var abbrInp   = gid('ka-details-abbr');
		var abbrWarn  = gid('ka-details-abbr-warn');
		// null = not checked yet for the current value; true/false = the answer.
		var abbrTaken = false;

		function showAbbrWarn(msg) {
			if (!abbrWarn) return;
			if (!msg) { abbrWarn.textContent = ''; abbrWarn.style.display = 'none'; return; }
			abbrWarn.textContent = msg;
			abbrWarn.style.display = '';
		}

		/* One conflict check. Resolves { taken, name } and never rejects, so a failed
		   request cannot leave the save button stuck. */
		function checkAbbr(abbr) {
			var fd = new FormData();
			fd.append('Abbreviation', abbr);
			fd.append('ExcludeKingdomId', KaConfig.kingdomId);
			return fetch(BASE_URL + 'checkabbr', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) { return { taken: !!(r && r.taken), name: (r && r.name) || 'another kingdom', ok: true }; })
				.catch(function() { return { taken: false, name: '', ok: false }; });
		}

		function conflictMessage(abbr, name) {
			return '"' + abbr + '" is already used by ' + name + '. Pick a different abbreviation before saving.';
		}

		if (abbrInp) {
			abbrInp.addEventListener('input', function() {
				var norm = normAbbr(this.value);
				// Only reassign when it actually differs; assigning unconditionally would
				// jump the caret to the end on every keystroke.
				if (this.value !== norm) this.value = norm;
				clearTimeout(abbrTimer);
				abbrTaken = false;
				if (!norm) { showAbbrWarn(''); return; }
				abbrTimer = setTimeout(function() {
					checkAbbr(norm).then(function(res) {
						// The field may have moved on while the request was in flight.
						if (normAbbr(abbrInp.value) !== norm) return;
						abbrTaken = res.taken;
						showAbbrWarn(res.taken ? conflictMessage(norm, res.name) : '');
					});
				}, 400);
			});
		}

		function saveDetails(name, abbr) {
			var fd = new FormData();
			fd.append('Name', name);
			fd.append('Abbreviation', abbr);
			fd.append('Description', (gid('ka-details-description').value || '').trim());
			fd.append('Url', (gid('ka-details-url').value || '').trim());
			fetch(BASE_URL + 'setdetails', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					kaFeedback('ka-details-feedback', 'Details saved!', true);
					gid('ka-details-name').dataset.original = gid('ka-details-name').value;
					gid('ka-details-abbr').dataset.original = gid('ka-details-abbr').value;
					gid('ka-details-description').dataset.original = gid('ka-details-description').value;
					gid('ka-details-url').dataset.original = gid('ka-details-url').value;
					/* The page behind the modal kept rendering the OLD kingdom name -- the
					   hero title, the tab title and KaConfig all still held it, so the save
					   looked like it had not taken. Update them in place. */
					kaApplyKingdomName(name);
				} else { kaFeedback('ka-details-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
			})
			.catch(function() { btn.disabled = false; kaFeedback('ka-details-feedback', 'Request failed.', false); });
		}

		function kaApplyKingdomName(name) {
			var previous = KaConfig.kingdomName;
			KaConfig.kingdomName = name;
			var hero = document.querySelector('.ka-hero-title a') || document.querySelector('.ka-hero-title');
			if (hero) hero.textContent = name;
			if (previous && document.title.indexOf(previous) !== -1) {
				document.title = document.title.split(previous).join(name);
			}
		}

		btn.addEventListener('click', function() {
			kaClearFeedback('ka-details-feedback');
			var name = (gid('ka-details-name').value || '').trim();
			var abbr = normAbbr(gid('ka-details-abbr').value);
			if (abbrInp && abbrInp.value !== abbr) abbrInp.value = abbr;
			if (!name) { kaFeedback('ka-details-feedback', 'Kingdom name is required.', false); return; }
			if (!abbr) { kaFeedback('ka-details-feedback', 'Abbreviation is required.', false); return; }

			/* A duplicate abbreviation used to be advisory: the warning appeared and the
			   save went through anyway, leaving two kingdoms sharing one abbreviation.
			   Block it. The debounced check may not have fired yet (type, then hit Save),
			   so re-check here and decide on THAT answer rather than on stale state. */
			if (abbrTaken) {
				kaFeedback('ka-details-feedback', 'That abbreviation is already taken. Pick a different one.', false);
				if (abbrInp) abbrInp.focus();
				return;
			}
			clearTimeout(abbrTimer);
			btn.disabled = true;
			checkAbbr(abbr).then(function(res) {
				if (res.taken) {
					abbrTaken = true;
					btn.disabled = false;
					showAbbrWarn(conflictMessage(abbr, res.name));
					kaFeedback('ka-details-feedback', conflictMessage(abbr, res.name), false);
					if (abbrInp) abbrInp.focus();
					return;
				}
				// res.ok === false means the check itself failed, not that the
				// abbreviation is free. Refuse rather than guess.
				if (!res.ok) {
					btn.disabled = false;
					kaFeedback('ka-details-feedback', 'Could not confirm the abbreviation is free. Please try again.', false);
					return;
				}
				abbrTaken = false;
				showAbbrWarn('');
				saveDetails(name, abbr);
			});
		});
	})();

	/* ══════════════════════════════════════════════
	   CONFIGURATION
	   ══════════════════════════════════════════════ */
	(function() {
		var container = gid('ka-config-rows');
		var emptyMsg  = gid('ka-config-empty');
		var btn       = gid('ka-config-save');

		/* Every row arrives with its ConfigRegistry definition attached -- label, help
		   text, control type, allowed values and group. Nothing below guesses a widget
		   from the value's JS type any more, and no raw config key is ever displayed:
		   a row with no definition is not one this kingdom may edit, so it is dropped. */
		var rows = (KaConfig.adminConfig || []).filter(function(cfg) {
			return cfg && cfg.Key && cfg.Definition && cfg.Definition.control;
		});

		function el(tag, cls, text) {
			var n = document.createElement(tag);
			if (cls) n.className = cls;
			if (text !== null && text !== undefined) n.textContent = text;
			return n;
		}

		/* Every control stamps defaultValue / defaultChecked / defaultSelected as well
		   as the live value. KA_RESET_ON_OPEN restores this modal from those defaults,
		   so they have to carry the STORED setting rather than a blank. */
		function numberControl(def, value, id) {
			var inp = document.createElement('input');
			inp.type = 'number';
			inp.className = 'ka-cfg-input ka-cfg-input-num';
			if (def.min !== null && def.min !== undefined) inp.min = def.min;
			if (def.max !== null && def.max !== undefined) inp.max = def.max;
			inp.step = def.integer ? '1' : 'any';
			if (def.allow_blank) inp.placeholder = 'None';
			var v = (value === null || value === undefined) ? '' : String(value);
			inp.value = v;
			inp.defaultValue = v;
			inp.id = id;
			return inp;
		}

		function selectControl(def, value, id) {
			var sel = document.createElement('select');
			sel.className = 'ka-cfg-input ka-cfg-input-select';
			var options = def.options || {};
			Object.keys(options).forEach(function(k) {
				var o = document.createElement('option');
				o.value = k;
				o.textContent = options[k];
				if (String(k) === String(value)) { o.selected = true; o.defaultSelected = true; }
				sel.appendChild(o);
			});
			sel.id = id;
			return sel;
		}

		function colorControl(value, id) {
			var inp = document.createElement('input');
			inp.type = 'color';
			inp.className = 'ka-cfg-input ka-cfg-input-color';
			/* Stored as six hex digits with no leading '#'. An empty or malformed value
			   must not reach <input type=color> -- it silently becomes #000000, which
			   then saves as a deliberate black. */
			var hex = String((value === null || value === undefined) ? '' : value).replace(/^#/, '');
			var v = /^[0-9A-Fa-f]{6}$/.test(hex) ? ('#' + hex) : '#3182CE';
			inp.value = v;
			inp.defaultValue = v;
			inp.id = id;
			return inp;
		}

		function textControl(def, value, id) {
			var inp = document.createElement('input');
			inp.type = 'text';
			inp.className = 'ka-cfg-input';
			inp.maxLength = def.max_length ? def.max_length : 255;
			var v = (value === null || value === undefined) ? '' : String(value);
			inp.value = v;
			inp.defaultValue = v;
			inp.id = id;
			return inp;
		}

		/* Booleans used to render as a free-text box. Typing "yes" stored the string
		   "yes"; every reader evaluates (int)Value === 1, so the feature switched OFF
		   behind a green "saved" banner. A toggle can only ever produce '1' or '0'. */
		function booleanControl(value, id) {
			var wrap = el('label', 'ka-toggle ka-cfg-toggle');
			var inp  = document.createElement('input');
			inp.type = 'checkbox';
			inp.id = id;
			inp.checked = String(value) === '1';
			inp.defaultChecked = inp.checked;
			wrap.appendChild(inp);
			wrap.appendChild(el('span', 'ka-toggle-track'));
			// On/Off word is drawn by CSS from :checked, and hidden from assistive tech
			// because the checkbox already announces its own state.
			var state = el('span', 'ka-cfg-toggle-state');
			state.setAttribute('aria-hidden', 'true');
			wrap.appendChild(state);
			return { node: wrap, input: inp };
		}

		function buildRow(cfg) {
			var def   = cfg.Definition;
			var key   = String(cfg.Key);
			var domId = 'ka-cfg-' + key.replace(/[^A-Za-z0-9]/g, '');
			var row   = el('div', 'ka-cfg-row');

			var head = el('div', 'ka-cfg-head');
			var name = el('label', 'ka-cfg-name', def.label || key);
			head.appendChild(name);
			if (def.help) head.appendChild(el('div', 'ka-cfg-help', def.help));
			row.appendChild(head);

			var ctl     = el('div', 'ka-cfg-control');
			var primary = null;

			if (def.control === 'period') {
				// Composite {Period, Type}. json_encode round-trips it as an object, but
				// tolerate a still-encoded string rather than rendering two blank boxes.
				var parts = cfg.Value;
				if (typeof parts === 'string') { try { parts = JSON.parse(parts); } catch (e) { parts = null; } }
				if (!parts || typeof parts !== 'object') parts = {};
				var subs = def.sub || {};
				Object.keys(subs).forEach(function(subKey) {
					var subDef = subs[subKey] || {};
					var subId  = domId + '-' + subKey;
					var sub    = (subDef.control === 'select')
						? selectControl(subDef, parts[subKey], subId)
						: numberControl(subDef, parts[subKey], subId);
					sub.dataset.cfgKey     = key;
					sub.dataset.cfgSub     = subKey;
					sub.dataset.cfgControl = subDef.control || 'text';
					sub.setAttribute('aria-label', (def.label || key) + ' — ' + (subDef.label || subKey));
					ctl.appendChild(sub);
					if (!primary) primary = sub;
				});
			} else if (def.control === 'boolean') {
				var toggle = booleanControl(cfg.Value, domId);
				toggle.input.dataset.cfgKey     = key;
				toggle.input.dataset.cfgControl = 'boolean';
				ctl.appendChild(toggle.node);
				primary = toggle.input;
			} else {
				var node;
				if (def.control === 'select')      { node = selectControl(def, cfg.Value, domId); }
				else if (def.control === 'number') { node = numberControl(def, cfg.Value, domId); }
				else if (def.control === 'color')  { node = colorControl(cfg.Value, domId); }
				else                               { node = textControl(def, cfg.Value, domId); }
				node.dataset.cfgKey     = key;
				node.dataset.cfgControl = def.control;
				ctl.appendChild(node);
				if (def.control === 'number' && def.unit) ctl.appendChild(el('span', 'ka-cfg-unit', def.unit));
				primary = node;
			}

			if (primary && primary.id) name.setAttribute('for', primary.id);
			row.appendChild(ctl);
			return row;
		}

		if (container) {
			var currentGroup = null;
			var section = null;
			rows.forEach(function(cfg) {
				var groupLabel = cfg.GroupLabel || 'Settings';
				if (groupLabel !== currentGroup) {
					currentGroup = groupLabel;
					section = el('div', 'ka-cfg-group');
					// A <div>, not an <h4>: orkui.css paints every h1-h6 as a filled grey
					// pill and repeats it under html[data-theme="dark"] at (0,1,2), which
					// a plain class reset in a modal cannot beat.
					section.appendChild(el('div', 'ka-cfg-group-title', groupLabel));
					container.appendChild(section);
				}
				section.appendChild(buildRow(cfg));
			});
		}
		if (emptyMsg) emptyMsg.style.display = rows.length ? 'none' : '';

		if (!btn) return;
		if (!rows.length) { btn.disabled = true; return; }

		function eachControl(fn) {
			document.querySelectorAll('#ka-config-rows [data-cfg-key]').forEach(fn);
		}

		/* After a successful save the submitted values ARE the stored values, so they
		   become the baseline that reset-on-open and the dirty guard restore to. */
		function stampDefaults() {
			eachControl(function(inp) {
				if (inp.type === 'checkbox') { inp.defaultChecked = inp.checked; return; }
				if (inp.tagName === 'SELECT') {
					for (var i = 0; i < inp.options.length; i++) {
						inp.options[i].defaultSelected = (i === inp.selectedIndex);
					}
					return;
				}
				inp.defaultValue = inp.value;
			});
		}

		btn.addEventListener('click', function() {
			kaClearFeedback('ka-config-feedback');
			var fd = new FormData();
			var count = 0;
			eachControl(function(inp) {
				var key = inp.dataset.cfgKey;
				if (!key) return;
				var val;
				if (inp.dataset.cfgControl === 'boolean')    { val = inp.checked ? '1' : '0'; }
				else if (inp.dataset.cfgControl === 'color') { val = String(inp.value).replace(/^#/, ''); }
				else                                         { val = inp.value; }
				fd.append(
					inp.dataset.cfgSub ? ('Config[' + key + '][' + inp.dataset.cfgSub + ']') : ('Config[' + key + ']'),
					val
				);
				count++;
			});
			if (!count) { kaFeedback('ka-config-feedback', 'There is nothing to save.', false); return; }

			/* ONE request, posted by config KEY. KingdomAjax setconfig is the single
			   write path and validates every value through ConfigRegistry; the second
			   setrecsvisibility call that used to follow this one is gone, along with
			   the duplicate hardcoded control that fed it and always won the race. */
			btn.disabled = true;
			fetch(BASE_URL + 'setconfig', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					kaFeedback('ka-config-feedback', 'Configuration saved.', true);
					stampDefaults();
					var ov = gid('ka-config-overlay');
					if (ov) kaMarkClean(ov);
				} else {
					kaFeedback('ka-config-feedback', (r && r.error) ? r.error : 'Save failed.', false);
				}
			})
			.catch(function() { btn.disabled = false; kaFeedback('ka-config-feedback', 'Request failed.', false); });
		});
	})();

	/* ══════════════════════════════════════════════
	   HERALDRY
	   ══════════════════════════════════════════════ */
	(function() {
		/* Must stay identical to the budget resizeImageToLimit() targets and to the
		   Common::MAX_IMAGE_BASE64_LENGTH backstop in the domain. Sixteen other call
		   sites in orkui.js / revised.js use this same number. */
		var HERALDRY_MAX_BYTES = 348836;

		var fileInput = gid('ka-heraldry-file');
		var uploadBtn = gid('ka-heraldry-upload');
		var removeBtn = gid('ka-heraldry-remove');
		var notice    = gid('ka-heraldry-resize');
		var caption   = gid('ka-heraldry-caption');
		var preview   = gid('ka-heraldry-preview');
		var uploadIdleHtml = uploadBtn ? uploadBtn.innerHTML : '';
		var entityLc  = KaConfig.entityLabelLc || 'kingdom';
		// Captured at load, while the preview still holds what the server rendered.
		var savedSrc     = preview ? preview.getAttribute('src') : '';
		var savedAlt     = preview ? (preview.getAttribute('alt') || '') : '';
		var savedCaption = caption ? caption.textContent : '';

		/* The blob actually posted. It is the resized output when a resize ran, and
		   the raw pick otherwise. Kept alongside the input rather than only inside
		   it: writing back through DataTransfer is the documented way to swap an
		   <input type=file>'s selection, but if a browser refuses, the upload must
		   still send the SMALL image rather than silently reverting to the original
		   -- reverting is precisely the bug being fixed here. */
		var pendingBlob = null;

		function note(msg, isError) {
			if (!notice) return;
			if (!msg) { notice.style.display = 'none'; notice.textContent = ''; notice.classList.remove('ka-notice-warn'); return; }
			notice.style.display = '';
			notice.textContent = msg;
			notice.classList.toggle('ka-notice-warn', !!isError);
		}
		function showLocalPreview(blob) {
			if (!preview) return;
			var reader = new FileReader();
			reader.onload = function(e) {
				preview.src = e.target.result;
				preview.alt = 'Preview of the image you are about to upload';
				if (caption) caption.textContent = 'New image — not saved yet';
			};
			reader.readAsDataURL(blob);
		}
		function busy(on) {
			if (uploadBtn) {
				uploadBtn.disabled = on;
				uploadBtn.setAttribute('aria-busy', on ? 'true' : 'false');
				uploadBtn.innerHTML = on
					? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Uploading…'
					: uploadIdleHtml;
			}
			// A remove firing mid-upload would race the write it is trying to delete.
			if (removeBtn) removeBtn.disabled = on;
			if (fileInput) fileInput.disabled = on;
		}

		kaOnOpen('ka-heraldry-overlay', function() {
			pendingBlob = null;
			note('');
			if (fileInput) { fileInput.disabled = false; delete fileInput.dataset.resizeGen; }
			if (preview) { preview.src = savedSrc; preview.alt = savedAlt; }
			if (caption) caption.textContent = savedCaption;
			busy(false);
			if (uploadBtn) uploadBtn.disabled = true;
			if (removeBtn) { removeBtn.disabled = false; removeBtn.setAttribute('aria-busy', 'false'); }
		});

		if (fileInput) {
			fileInput.addEventListener('change', function() {
				var input = this;
				pendingBlob = null;
				note('');
				kaClearFeedback('ka-heraldry-feedback');
				if (uploadBtn) uploadBtn.disabled = !input.files.length;
				if (!input.files.length) { return; }

				var file = input.files[0];
				showLocalPreview(file);
				pendingBlob = file;
				if (file.size <= HERALDRY_MAX_BYTES) return;

				/* THE FIX. Every other heraldry upload path in the app downscales
				   before it posts; this modal was the only one that did not, so
				   anything over ~340 KB was rejected by the domain's size backstop.
				   resizeImageToLimit() is the shared global from orkui.js (loaded on
				   every page by default.theme) -- same threshold and the same
				   preservePng rule as its other callers, so a PNG keeps its alpha
				   instead of being flattened onto black. */
				if (typeof resizeImageToLimit !== 'function') {
					note('This image is larger than 340 KB and the resizer did not load, so it cannot be sent as-is. Please save a smaller copy and try again.', true);
					try { input.value = ''; } catch (e) {}
					pendingBlob = null;
					if (uploadBtn) uploadBtn.disabled = true;
					return;
				}

				// Resizing is async; a second pick while the first is still running
				// must not have its result written back over the newer one.
				var gen = (Number(input.dataset.resizeGen) || 0) + 1;
				input.dataset.resizeGen = String(gen);
				var isPng = (file.type === 'image/png');
				var originalKB = Math.round(file.size / 1024);

				if (uploadBtn) uploadBtn.disabled = true;
				note('Resizing…');
				resizeImageToLimit(file, HERALDRY_MAX_BYTES, function(blob, newW, newH) {
					if (input.dataset.resizeGen !== String(gen)) return;
					pendingBlob = blob;
					// Swap the input's own selection too, so what the control reports
					// and what gets posted are the same file.
					try {
						var dt = new DataTransfer();
						dt.items.add(new File([blob], isPng ? 'heraldry.png' : 'heraldry.jpg',
							{ type: isPng ? 'image/png' : 'image/jpeg' }));
						input.files = dt.files;
					} catch (e) { /* pendingBlob still carries the resized image */ }
					showLocalPreview(blob);
					note('Resized ' + originalKB + ' KB → ' + Math.round(blob.size / 1024) + ' KB ('
						+ newW + '×' + newH + ').');
					if (uploadBtn) uploadBtn.disabled = false;
				}, function(errMsg) {
					if (input.dataset.resizeGen !== String(gen)) return;
					note(errMsg || 'That image could not be resized in your browser. Please save a smaller copy and try again.', true);
					try { input.value = ''; } catch (e) {}
					pendingBlob = null;
					if (uploadBtn) uploadBtn.disabled = true;
				}, isPng);
			});
		}

		if (uploadBtn) {
			uploadBtn.addEventListener('click', function() {
				var blob = pendingBlob || (fileInput && fileInput.files.length ? fileInput.files[0] : null);
				if (!blob) return;
				// KingdomAjax setheraldry checks $_FILES['Heraldry']['type'], which the
				// browser fills from the blob's own type -- so a name is required for
				// the part to be treated as a file upload at all.
				var name = blob.name || (blob.type === 'image/png' ? 'heraldry.png' : 'heraldry.jpg');
				var fd = new FormData();
				fd.append('Heraldry', blob, name);
				busy(true);
				fetch(BASE_URL + 'setheraldry', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					if (r && r.status === 0) {
						// Deliberately left busy: the page is about to reload, and
						// re-enabling for a blink invites a second submit.
						kaFeedback('ka-heraldry-feedback', 'Heraldry updated. Refreshing…', true);
						setTimeout(function() { location.reload(); }, 1000);
						return;
					}
					busy(false);
					kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Upload failed.', false);
				})
				.catch(function() { busy(false); kaFeedback('ka-heraldry-feedback', 'Request failed.', false); });
			});
		}

		if (removeBtn) {
			removeBtn.addEventListener('click', function() {
				/* The old text was "Remove the kingdom heraldry?" -- a question that
				   describes none of what actually happens. The file is unlinked from
				   storage with no copy kept anywhere. */
				kaConfirm(
					'<p>This deletes the image file permanently. No copy is kept and there is no undo.</p>'
					+ '<ul class="ka-confirm-list">'
					+ '<li>The banner behind this console and the ' + kaEsc(entityLc) + ' profile badge both fall back to the generic ORK shield.</li>'
					+ '<li>Anywhere else this ' + kaEsc(entityLc) + '’s device is shown falls back with them.</li>'
					+ '<li>If there is any chance you will want it back, cancel and use <strong>Download current image</strong> first.</li>'
					+ '</ul>',
					function() {
						removeBtn.disabled = true;
						removeBtn.setAttribute('aria-busy', 'true');
						fetch(BASE_URL + 'removeheraldry', { method: 'POST', body: new FormData() })
						.then(function(r) { return r.json(); })
						.then(function(r) {
							if (r && r.status === 0) {
								kaFeedback('ka-heraldry-feedback', 'Heraldry removed. Refreshing…', true);
								setTimeout(function() { location.reload(); }, 1000);
								return;
							}
							removeBtn.disabled = false;
							removeBtn.setAttribute('aria-busy', 'false');
							kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Remove failed.', false);
						})
						.catch(function() {
							removeBtn.disabled = false;
							removeBtn.setAttribute('aria-busy', 'false');
							kaFeedback('ka-heraldry-feedback', 'Request failed.', false);
						});
					},
					'Delete Heraldry',
					{ danger: true, html: true, okLabel: 'Delete permanently', okIcon: 'fas fa-trash' }
				);
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PARK TITLES
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-titles-tbody');
		if (!tbody) return;

		function makeTitleRow(pt) {
			var tr = document.createElement('tr');
			tr.dataset.titleId = pt.ParkTitleId;
			function makeCell(type, field, val) {
				var td = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = type;
						inp.style.cssText = type === 'number' ? 'width:56px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:center' : 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
				inp.value = val;
				if (type === 'number') inp.min = '0';
				inp.dataset.field = field;
				td.appendChild(inp);
				return td;
			}
			var periodTd = document.createElement('td');
			var sel = document.createElement('select');
			sel.style.cssText = 'padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			sel.dataset.field = 'Period';
			['month','week'].forEach(function(v) {
				var o = document.createElement('option');
				o.value = v; o.textContent = v.charAt(0).toUpperCase() + v.slice(1);
				if (v === pt.Period) o.selected = true;
				sel.appendChild(o);
			});
			periodTd.appendChild(sel);

			var delTd = document.createElement('td');
			var delBtn = document.createElement('button');
			delBtn.className = 'ka-tdel';
			delBtn.innerHTML = '<i class="fas fa-trash"></i>';
			delBtn.title = 'Delete';
			(function(row, titleName, titleId) {
				delBtn.addEventListener('click', function() {
					kaConfirm('Delete "' + titleName + '"?', function() {
						delBtn.disabled = true;
						kaPost(BASE_URL + 'deletetitle', { ParkTitleId: titleId }, null, 'ka-titles-feedback', function() {
							row.parentNode && row.parentNode.removeChild(row);
							kaFeedback('ka-titles-feedback', 'Title deleted.', true);
						});
					}, 'Delete Title');
				});
			})(tr, pt.Title, pt.ParkTitleId);
			delTd.appendChild(delBtn);

			tr.appendChild(makeCell('text', 'Title', pt.Title));
			tr.appendChild(makeCell('number', 'Class', pt.Class));
			tr.appendChild(makeCell('number', 'MinimumAttendance', pt.MinimumAttendance));
			tr.appendChild(makeCell('number', 'MinimumCutoff', pt.MinimumCutoff));
			tr.appendChild(periodTd);
			tr.appendChild(makeCell('number', 'Length', pt.Length));
			tr.appendChild(delTd);
			return tr;
		}

		// Build initial rows
		(KaConfig.adminParkTitles || []).forEach(function(pt) { tbody.appendChild(makeTitleRow(pt)); });

		var btn = gid('ka-titles-save');
		if (btn) {
			btn.addEventListener('click', function() {
				kaClearFeedback('ka-titles-feedback');
				var data = {};
				tbody.querySelectorAll('tr').forEach(function(row) {
					var id = row.dataset.titleId;
					row.querySelectorAll('[data-field]').forEach(function(inp) { data[inp.dataset.field + '[' + id + ']'] = inp.value; });
				});
				var newTitle = document.querySelector('#ka-titles-table tfoot [data-field="Title"]');
				if (newTitle && newTitle.value.trim()) {
					document.querySelectorAll('#ka-titles-table tfoot [data-field]').forEach(function(inp) { data[inp.dataset.field + '[New]'] = inp.value; });
				}
				if (!Object.keys(data).length) { kaFeedback('ka-titles-feedback', 'No data to save.', false); return; }
				btn.disabled = true;
				var fd = new FormData();
				Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
				fetch(BASE_URL + 'setparktitles', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					btn.disabled = false;
					if (r && r.status === 0) {
						kaFeedback('ka-titles-feedback', 'Park titles saved!', true);
						document.querySelectorAll('#ka-titles-table tfoot [data-field]').forEach(function(inp) {
							inp.value = (inp.dataset.field === 'Length') ? '1' : (inp.type === 'number' ? '0' : '');
						});
					} else { kaFeedback('ka-titles-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-titles-feedback', 'Request failed.', false); });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   EDIT PARKS
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-parks-tbody');
		if (!tbody) return;

		var searchInp = gid('ka-parks-search');
		var countEl   = gid('ka-parks-count');
		var emptyEl   = gid('ka-parks-empty');
		var rows      = [];   // every rendered <tr>, in render order
		var rowById   = {};   // ParkId → <tr>

		/* ── Per-row dirty state ──────────────────────
		   Save posts ONLY the rows an officer actually touched. Sending all forty
		   rows made every park a write — and an audit-log entry — on every save,
		   and it buried the one park that failed in a wall of "Saved." results. */
		function rowSnapshot(tr) {
			// JSON.stringify, not join(sep): a park name can contain any separator
			// character we might pick, and two different rows must never hash alike.
			return JSON.stringify(Array.prototype.slice.call(tr.querySelectorAll('[data-field]')).map(function(f) {
				return (f.type === 'checkbox') ? (f.checked ? '1' : '0') : String(f.value == null ? '' : f.value);
			}));
		}
		function markRowClean(tr) {
			tr.dataset.clean = rowSnapshot(tr);
			tr.classList.remove('ka-row-dirty');
		}
		function refreshRowDirty(tr) {
			tr.classList.toggle('ka-row-dirty', rowSnapshot(tr) !== tr.dataset.clean);
		}

		/* A retired park is signalled by a CHIP, not by dimming the row. The old
		   .ka-admin-park-retired (opacity: 0.45) read as "this row is disabled" and
		   dropped its text and controls below the contrast floor, on a row whose
		   controls are still fully live. */
		function syncRetiredChip(tr) {
			var chk  = tr.querySelector('[data-field="Active"]');
			var chip = tr.querySelector('.ka-park-retired-chip');
			if (chip) chip.style.display = (chk && chk.checked) ? 'none' : '';
		}

		/* Inline per-park result. The batch used to collapse into one anonymous
		   error line; the controller now returns a result per park, so the failing
		   row says so on the row itself. */
		function setRowMessage(tr, msg, ok) {
			var host = tr.querySelector('.ka-park-msg');
			if (!msg) {
				if (host && host.parentNode) host.parentNode.removeChild(host);
				return;
			}
			if (!host) {
				host = document.createElement('div');
				tr.cells[0].appendChild(host);
			}
			host.className = 'ka-park-msg ' + (ok ? 'ka-muted' : 'ka-danger-note');
			host.textContent = msg;
		}
		function clearRowMessages() {
			rows.forEach(function(tr) { setRowMessage(tr, ''); });
		}

		/* The confirm renders into a plain <p>, so newlines collapse — this is written
		   as prose on purpose. It names the park and spells out exactly what retiring
		   does and does not do, because "Active" reads like a display flag and is in
		   fact a danger-audited RetirePark. */
		function retireConfirmText(name, wantActive) {
			if (wantActive) {
				return 'Restore ' + name + '? Its park days and events will start appearing on the '
					+ 'kingdom calendar again, and its officers will show on player profiles again. '
					+ 'Nothing is applied until you press Save Parks.';
			}
			return 'Retire ' + name + '? Players stay assigned to ' + name + ', so nobody loses their '
				+ 'home park. What changes: its park days and events stop appearing on the kingdom '
				+ 'calendar, and its officers stop showing on player profiles. You can restore '
				+ name + ' from this same screen later. Nothing is applied until you press Save Parks.';
		}

		function makeParkRow(park) {
			var tr = document.createElement('tr');
			var name = park.Name || '';
			tr.dataset.parkId = park.ParkId;
			tr.dataset.storedActive = (park.Active === 'Active') ? 'Active' : 'Retired';
			tr.dataset.haystack = (name + ' ' + (park.Abbreviation || '')).toLowerCase();

			// Every input below is styled by .ka-admin-table input[...] in
			// admin-console.css, which has a working html[data-theme="dark"] rule.
			// The inline style.cssText these used to carry hardcoded a light border
			// at (1,0,0,0) and beat that dark rule outright.
			var nameTd = document.createElement('td');
			var nameInp = document.createElement('input');
			nameInp.type = 'text';
			nameInp.value = name;
			nameInp.dataset.field = 'ParkName';
			nameInp.setAttribute('aria-label', 'Park name for ' + name);
			nameTd.appendChild(nameInp);

			var chip = document.createElement('span');
			chip.className = 'kn-badge kn-badge-gray ka-park-retired-chip';
			chip.textContent = 'Retired';
			nameTd.appendChild(chip);

			var dirtyFlag = document.createElement('span');
			dirtyFlag.className = 'ka-row-dirty-flag';
			dirtyFlag.textContent = 'Unsaved';
			nameTd.appendChild(dirtyFlag);

			var titleTd = document.createElement('td');
			var sel = document.createElement('select');
			sel.dataset.field = 'ParkTitle';
			sel.setAttribute('aria-label', 'Park title for ' + name);
			var opts = KaConfig.parkTitleOptions || {};
			Object.keys(opts).forEach(function(tid) {
				var o = document.createElement('option');
				o.value = tid; o.textContent = opts[tid];
				if (parseInt(tid, 10) === park.ParkTitleId) o.selected = true;
				sel.appendChild(o);
			});
			titleTd.appendChild(sel);

			var abbrTd = document.createElement('td');
			var abbrInp = document.createElement('input');
			abbrInp.type = 'text';
			abbrInp.value = park.Abbreviation || '';
			abbrInp.maxLength = 3;
			abbrInp.dataset.field = 'Abbreviation';
			abbrInp.setAttribute('aria-label', 'Abbreviation for ' + name);
			abbrTd.appendChild(abbrInp);

			var activeTd = document.createElement('td');
			activeTd.style.textAlign = 'center';
			var label = document.createElement('label');
			label.className = 'ka-toggle';
			var chk = document.createElement('input');
			chk.type = 'checkbox';
			chk.checked = (park.Active === 'Active');
			chk.dataset.field = 'Active';
			chk.setAttribute('aria-label', 'Active — ' + name);
			var track = document.createElement('span');
			track.className = 'ka-toggle-track';
			label.appendChild(chk);
			label.appendChild(track);
			activeTd.appendChild(label);

			/* The Active toggle is no longer a column edit — it routes to
			   RetirePark / RestorePark, a danger-audited operation. kaConfirm has no
			   cancel callback, so the toggle is reverted to the stored state first
			   and re-applied only if the officer confirms. Assigning .checked from
			   script does not re-fire 'change', so this cannot recurse. */
			chk.addEventListener('change', function() {
				var wanted = chk.checked;
				var stored = (tr.dataset.storedActive === 'Active');
				if (wanted === stored) { syncRetiredChip(tr); refreshRowDirty(tr); return; }
				chk.checked = stored;
				syncRetiredChip(tr);
				refreshRowDirty(tr);
				kaConfirm(retireConfirmText(name, wanted), function() {
					chk.checked = wanted;
					syncRetiredChip(tr);
					refreshRowDirty(tr);
				}, wanted ? 'Restore Park' : 'Retire Park');
			});

			var viewTd = document.createElement('td');
			var viewA = document.createElement('a');
			viewA.href = UIR + 'Park/profile/' + park.ParkId;
			viewA.target = '_blank';
			viewA.rel = 'noopener';
			// data-tip, not title= (house rule) — and an aria-label, because the
			// link's only content is a decorative icon.
			viewA.setAttribute('data-tip', 'View ' + name);
			viewA.setAttribute('aria-label', 'View ' + name + ' (opens in a new tab)');
			viewA.innerHTML = '<i class="fas fa-external-link-alt ka-muted" aria-hidden="true"></i>';
			viewTd.appendChild(viewA);

			tr.appendChild(nameTd);
			tr.appendChild(titleTd);
			tr.appendChild(abbrTd);
			tr.appendChild(activeTd);
			tr.appendChild(viewTd);

			[nameInp, sel, abbrInp].forEach(function(f) {
				f.addEventListener('input',  function() { refreshRowDirty(tr); });
				f.addEventListener('change', function() { refreshRowDirty(tr); });
			});
			markRowClean(tr);
			syncRetiredChip(tr);
			return tr;
		}

		function applyParkFilter() {
			var q = (searchInp ? searchInp.value : '').trim().toLowerCase();
			var shown = 0;
			rows.forEach(function(tr) {
				var show = !q || (tr.dataset.haystack || '').indexOf(q) !== -1;
				tr.style.display = show ? '' : 'none';
				if (show) shown++;
			});
			if (emptyEl) emptyEl.style.display = (q && shown === 0) ? '' : 'none';
			if (countEl) {
				countEl.textContent = q
					? (shown + ' of ' + rows.length + ' parks')
					: (rows.length + (rows.length === 1 ? ' park' : ' parks'));
			}
		}

		var parks = (KaConfig.parkEditLookup || []).slice();
		parks.sort(function(a, b) { return (a.Name || '').localeCompare(b.Name || ''); });
		parks.forEach(function(park) {
			var tr = makeParkRow(park);
			rows.push(tr);
			rowById[park.ParkId] = tr;
			tbody.appendChild(tr);
		});
		if (searchInp) searchInp.addEventListener('input', applyParkFilter);
		applyParkFilter();

		var btn = gid('ka-parks-save');
		if (btn) {
			btn.addEventListener('click', function() {
				kaClearFeedback('ka-parks-feedback');
				clearRowMessages();

				var payload = [];
				rows.forEach(function(tr) {
					if (!tr.classList.contains('ka-row-dirty')) return;
					var pid = parseInt(tr.dataset.parkId, 10);
					if (!pid) return;
					var p = { ParkId: pid };
					tr.querySelectorAll('[data-field]').forEach(function(inp) {
						p[inp.dataset.field] = (inp.type === 'checkbox') ? (inp.checked ? 'YES' : '') : inp.value;
					});
					payload.push(p);
				});
				if (!payload.length) { kaFeedback('ka-parks-feedback', 'No changes to save.', false); return; }

				btn.disabled = true;
				var fd = new FormData();
				fd.append('ParksJson', JSON.stringify(payload));
				fetch(BASE_URL + 'updateparks', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					btn.disabled = false;
					// Per-park results land on the rows themselves; a row that failed
					// stays dirty so the next save retries exactly that row.
					(r && r.results ? r.results : []).forEach(function(res) {
						var tr = rowById[res.parkId];
						if (!tr) return;
						setRowMessage(tr, res.message || '', !!res.ok);
						if (res.active) {
							tr.dataset.storedActive = res.active;
							var chk = tr.querySelector('[data-field="Active"]');
							if (chk) chk.checked = (res.active === 'Active');
							syncRetiredChip(tr);
						}
						if (res.ok) markRowClean(tr);
					});
					if (r && r.status === 0) {
						kaFeedback('ka-parks-feedback',
							payload.length === 1 ? '1 park saved.' : payload.length + ' parks saved.', true);
					} else {
						kaFeedback('ka-parks-feedback',
							(r && r.results && r.results.length)
								? 'Some parks could not be saved — see the highlighted rows below.'
								: ((r && r.error) ? r.error : 'Save failed.'),
							false);
					}
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-parks-feedback', 'Request failed.', false); });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   AWARDS
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-awards-tbody');
		if (!tbody) return;

		var searchInp = gid('ka-awards-search');
		var countEl   = gid('ka-awards-count');
		var emptyEl   = gid('ka-awards-empty');
		var expandBtn = gid('ka-awards-expandall');
		var groups    = [];   // { key, label, hdr, btn, countEl, rows: [ctx], collapsed }
		var totalRows = 0;

		/* ── Per-row dirty state ──────────────────────
		   Every one of the ~130 save buttons used to look identical whether the row
		   had been touched or not, so there was no way to tell what still needed
		   saving. Each row now carries its own baseline and shows the shared
		   .ka-row-dirty accent + "Unsaved" flag until it is saved. */
		function rowSnapshot(tr) {
			// JSON.stringify, not join(sep): an award name can contain any separator
			// character we might pick, and two different rows must never hash alike.
			return JSON.stringify(Array.prototype.slice.call(tr.querySelectorAll('[data-field]')).map(function(f) {
				return (f.type === 'checkbox') ? (f.checked ? '1' : '0') : String(f.value == null ? '' : f.value);
			}));
		}

		function holdersPhrase(n) {
			if (typeof n !== 'number' || n < 0) return '';
			if (n === 0) return 'No player holds it.';
			return (n === 1)
				? '1 player holds it and keeps it on their record.'
				: n.toLocaleString() + ' players hold it and keep it on their record.';
		}

		/* Retiring an award is a SOFT delete: the definition stays so every grant
		   already hanging off it keeps resolving its name, and it only disappears
		   from the pickers officers grant from. The confirm says so, and names the
		   holder count when the payload carries one (KingdomAjax returns the
		   authoritative count on the response either way, and the success message
		   below repeats it). */
		function retireConfirmText(aw) {
			var nm = aw.KingdomAwardName || 'this award';
			var held = (typeof aw.AwardingCount === 'number') ? holdersPhrase(aw.AwardingCount) : '';
			return 'Retire the award "' + nm + '"? '
				+ (held ? held + ' ' : 'Players who already have it keep it on their record. ')
				+ 'Retiring only takes it out of the lists officers grant from, so it can no longer '
				+ 'be given out. You can re-enable it from this same row at any time.';
		}

		function makeAwardRow(aw) {
			var tr = document.createElement('tr');
			var awName = aw.KingdomAwardName || '';
			tr.dataset.kawId = aw.KingdomAwardId;

			// Every input below is styled by .ka-admin-table input[...] in
			// admin-console.css, which has a working html[data-theme="dark"] rule.
			// The inline style.cssText these used to carry hardcoded a light border
			// at (1,0,0,0) and beat that dark rule outright.
			function ntd(isText, val, field, label) {
				var td  = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = isText ? 'text' : 'number';
				inp.value = val;
				inp.dataset.field = field;
				inp.setAttribute('aria-label', label);
				if (!isText) inp.min = '0';
				td.appendChild(inp);
				return { td: td, inp: inp };
			}

			var nameCell  = ntd(true,  awName,          'KingdomAwardName', 'Award name (' + awName + ')');
			var reignCell = ntd(false, aw.ReignLimit,   'ReignLimit',       'Reign limit for ' + awName);
			var monthCell = ntd(false, aw.MonthLimit,   'MonthLimit',       'Month limit for ' + awName);
			var classCell = ntd(false, aw.TitleClass,   'TitleClass',       'Title class for ' + awName);

			// The elbow is drawn in CSS (::before on the first cell), not inserted here:
			// a box-drawing character depends on a font that has it, and a real node in
			// the cell wraps above the full-width input.
			if (aw._aliasChild) {
				tr.classList.add('ka-award-alias-row');
			}

			// The (?) marker only earns its place when the nesting does not already show
			// what this renames -- a nested row sits directly under its original.
			var sysName = aw.AwardName || '';
			if (sysName && sysName !== awName && !aw._aliasChild) {
				var hint = document.createElement('span');
				hint.className = 'ka-alias-hint';
				hint.setAttribute('role', 'img');
				// data-tip, not title= (house rule).
				hint.setAttribute('data-tip', 'Alias for system award: ' + sysName);
				hint.setAttribute('aria-label', 'Alias for system award: ' + sysName);
				hint.innerHTML = '<i class="fas fa-question-circle" aria-hidden="true"></i>';
				nameCell.td.appendChild(hint);
			}

			// Soft-deleted awards are shown, not hidden: a retired award still has
			// grants pointing at it, and it has to be re-enableable.
			var disChip = document.createElement('span');
			disChip.className = 'kn-badge kn-badge-gray ka-award-disabled-chip';
			disChip.textContent = 'Disabled';
			nameCell.td.appendChild(disChip);

			var dirtyFlag = document.createElement('span');
			dirtyFlag.className = 'ka-row-dirty-flag';
			dirtyFlag.textContent = 'Unsaved';
			nameCell.td.appendChild(dirtyFlag);

			var titleTd = document.createElement('td');
			titleTd.style.textAlign = 'center';
			var titleCb = document.createElement('input');
			titleCb.type = 'checkbox';
			titleCb.checked = (aw.IsTitle === 1);
			titleCb.dataset.field = 'IsTitle';
			titleCb.setAttribute('aria-label', 'Is ' + awName + ' a title?');
			titleTd.appendChild(titleCb);

			// Official Amtgard ladders (the 16 standard orders) are keyed on
			// OfficialIsLadder, never the effective IsLadder -- IsLadder is 1 for a
			// kingdom's OWN ladder-ified awards too, and locking those would be wrong.
			var isOfficialLadder = (aw.OfficialIsLadder === 1);
			var officialLockTip = 'Standard Amtgard ladder award — this can\'t be changed.';

			var ladderTd = document.createElement('td');
			ladderTd.className = 'ka-award-ladder-cell';
			var ladderCb = document.createElement('input');
			ladderCb.type = 'checkbox';
			ladderCb.className = 'ka-award-ladder';
			ladderCb.checked = (aw.IsLadder === 1);
			ladderCb.dataset.field = 'IsLadder';
			ladderCb.setAttribute('aria-label', 'Is ' + awName + ' a ladder award?');
			if (isOfficialLadder) {
				ladderCb.disabled = true;
				ladderCb.setAttribute('data-tip', officialLockTip);
			}
			ladderTd.appendChild(ladderCb);

			var maxRankTd = document.createElement('td');
			maxRankTd.className = 'ka-award-maxrank-cell';
			var maxRankInp = document.createElement('input');
			maxRankInp.type = 'number';
			maxRankInp.className = 'ka-award-maxrank';
			maxRankInp.min = '1';
			maxRankInp.max = '12';
			maxRankInp.step = '1';
			maxRankInp.value = (aw.MaxLevel && aw.MaxLevel > 0) ? aw.MaxLevel : 10;
			maxRankInp.dataset.field = 'MaxLevel';
			maxRankInp.setAttribute('aria-label', 'Max rank for ' + awName);
			if (isOfficialLadder) {
				maxRankInp.setAttribute('data-tip', officialLockTip);
			}
			maxRankTd.appendChild(maxRankInp);

			var actionsTd = document.createElement('td');
			actionsTd.style.whiteSpace = 'nowrap';

			function iconBtn(cls, icon, tip) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = cls;
				b.innerHTML = '<i class="fas ' + icon + '" aria-hidden="true"></i>';
				b.setAttribute('data-tip', tip);
				b.setAttribute('aria-label', tip);
				return b;
			}
			var saveBtn    = iconBtn('ka-tsave', 'fa-save',  'Save ' + awName);
			var delBtn     = iconBtn('ka-tdel',  'fa-trash', 'Retire ' + awName);
			var restoreBtn = iconBtn('ka-tsave', 'fa-undo',  'Re-enable ' + awName);
			saveBtn.style.marginRight = '4px';
			actionsTd.appendChild(saveBtn);
			actionsTd.appendChild(delBtn);
			actionsTd.appendChild(restoreBtn);

			tr.appendChild(nameCell.td);
			tr.appendChild(reignCell.td);
			tr.appendChild(monthCell.td);
			tr.appendChild(titleTd);
			tr.appendChild(ladderTd);
			tr.appendChild(maxRankTd);
			tr.appendChild(classCell.td);
			tr.appendChild(actionsTd);

			var ctx = {
				tr: tr,
				aw: aw,
				haystack: (awName + ' ' + sysName + ' ' + (aw.TitleClass || '')).toLowerCase()
			};

			function markClean() {
				tr.dataset.clean = rowSnapshot(tr);
				tr.classList.remove('ka-row-dirty');
			}
			function refreshDirty() {
				tr.classList.toggle('ka-row-dirty', rowSnapshot(tr) !== tr.dataset.clean);
			}
			function syncTitleClass() {
				if (aw.Disabled) return;
				classCell.inp.disabled = !titleCb.checked;
			}
			// Max Rank is only meaningful while Ladder is ticked. An official ladder
			// stays locked here regardless of the checkbox's own .checked state --
			// this re-asserts the lock every time the row's disabled state is
			// recomputed (e.g. after a retire/re-enable round trip), the same way
			// syncTitleClass() re-asserts Title Class above.
			function syncLadderState() {
				if (aw.Disabled) return;
				if (isOfficialLadder) {
					ladderCb.disabled = true;
					maxRankInp.disabled = true;
					return;
				}
				maxRankInp.disabled = !ladderCb.checked;
			}
			function applyDisabledState() {
				var off = !!aw.Disabled;
				tr.querySelectorAll('[data-field]').forEach(function(f) { f.disabled = off; });
				syncTitleClass();
				syncLadderState();
				disChip.style.display    = off ? ''     : 'none';
				saveBtn.style.display    = off ? 'none' : '';
				delBtn.style.display     = off ? 'none' : '';
				restoreBtn.style.display = off ? ''     : 'none';
			}

			titleCb.addEventListener('change', function() {
				syncTitleClass();
				// Ladder and Title? are mutually exclusive. A disabled (official-locked)
				// Ladder checkbox must never be programmatically flipped.
				if (titleCb.checked && !ladderCb.disabled && ladderCb.checked) {
					ladderCb.checked = false;
					syncLadderState();
				}
			});
			ladderCb.addEventListener('change', function() {
				syncLadderState();
				if (ladderCb.checked && titleCb.checked) {
					titleCb.checked = false;
					syncTitleClass();
				}
			});
			[nameCell.inp, reignCell.inp, monthCell.inp, classCell.inp, titleCb, ladderCb, maxRankInp].forEach(function(f) {
				f.addEventListener('input',  refreshDirty);
				f.addEventListener('change', refreshDirty);
			});

			/* Shared by the row's own Save button AND the bulk "Save Changes" action on
			   the unsaved-changes prompt (KA_MODAL_SAVE below) -- one save request per
			   row, built on the same kaPost() every other save in this file uses, never
			   reimplemented. cb(ok, message) fires exactly once.
			   kaPost has no "did this fail" callback of its own -- on failure it writes
			   straight to ka-awards-feedback itself and calls neither of the callbacks
			   below, so a genuine error already lands on screen for the solo-row case
			   without any help here. What is missing is a signal the BULK saver can
			   count, which is what onFinally supplies: it runs on both outcomes, but
			   before onSuccess would have run, so markClean() has not happened yet even
			   on a success. Deferring one tick lets that synchronous onSuccess call (it
			   runs immediately after onFinally returns, same tick, before this timeout
			   fires) land first, so ka-row-dirty is the row's TRUE post-save state by
			   the time cb() is decided. */
			ctx.save = function(cb) {
				var newName = nameCell.inp.value.trim();
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: aw.KingdomAwardId,
					KingdomAwardName: newName,
					ReignLimit: reignCell.inp.value,
					MonthLimit: monthCell.inp.value,
					IsTitle: titleCb.checked ? 1 : 0,
					TitleClass: classCell.inp.value,
					IsLadder: ladderCb.checked ? 1 : 0,
					MaxLevel: parseInt(maxRankInp.value, 10) || 10
				}, saveBtn, 'ka-awards-feedback', function() {
					aw.KingdomAwardName = newName;
					aw.ReignLimit = reignCell.inp.value;
					aw.MonthLimit = monthCell.inp.value;
					aw.TitleClass = classCell.inp.value;
					aw.IsLadder = ladderCb.checked ? 1 : 0;
					aw.MaxLevel = parseInt(maxRankInp.value, 10) || 10;
					aw.IsTitle = titleCb.checked ? 1 : 0;
					ctx.haystack = (newName + ' ' + sysName + ' ' + classCell.inp.value).toLowerCase();
					markClean();
				}, function() {
					setTimeout(function() {
						var ok = !tr.classList.contains('ka-row-dirty');
						cb(ok, ok ? ('Saved "' + kaEsc(newName) + '".') : null);
					}, 0);
				});
			};
			saveBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				// On failure kaPost already wrote the real server error to
				// ka-awards-feedback above -- only the success message needs writing
				// here, or the generic failure text would stomp the specific one.
				ctx.save(function(ok, msg) { if (ok) kaFeedback('ka-awards-feedback', msg, true); });
			});

			delBtn.addEventListener('click', function() {
				kaConfirm(retireConfirmText(aw), function() {
					kaClearFeedback('ka-awards-feedback');
					kaPost(BASE_URL + 'deleteaward', { KingdomAwardId: aw.KingdomAwardId },
						delBtn, 'ka-awards-feedback', function(r) {
							// Soft delete: the row STAYS. Removing it hid an award that
							// is still in the database and still on players' records.
							aw.Disabled = 1;
							if (r && typeof r.awardingCount === 'number') aw.AwardingCount = r.awardingCount;
							applyDisabledState();
							markClean();
							var held = holdersPhrase(aw.AwardingCount);
							kaFeedback('ka-awards-feedback',
								'Retired "' + kaEsc(aw.KingdomAwardName || '') + '". '
								+ (held ? held + ' ' : '')
								+ 'It can no longer be given out. Use the re-enable button on its row to bring it back.',
								true);
						});
				}, 'Retire Award');
			});

			restoreBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				kaPost(BASE_URL + 'restoreaward', { KingdomAwardId: aw.KingdomAwardId },
					restoreBtn, 'ka-awards-feedback', function(r) {
						aw.Disabled = 0;
						if (r && typeof r.awardingCount === 'number') aw.AwardingCount = r.awardingCount;
						applyDisabledState();
						markClean();
						kaFeedback('ka-awards-feedback',
							'Re-enabled "' + kaEsc(aw.KingdomAwardName || '') + '". It can be given out again.', true);
					});
			});

			applyDisabledState();
			markClean();
			return ctx;
		}

		// Group awards using same logic as model.Award.php
		function classifyAward(aw) {
			var sysName = aw.AwardName || aw.KingdomAwardName || '';
			if (aw.AwardId === 0) return 'Kingdom-Specific';
			if (sysName === 'Custom Award') return 'Kingdom-Specific';
			if (aw.IsLadder) return 'Ladder Awards';
			if (sysName === 'Defender' || sysName === 'Master') return 'Noble Titles';
			if (sysName === 'Weaponmaster') return 'Offices & Other';
			if (aw.Peerage === 'Knight') return 'Knighthoods';
			if (aw.Peerage === 'Paragon') return 'Paragons';
			if (aw.Peerage === 'Master' || (aw.IsTitle && aw.TitleClass === 10)) return 'Masterhoods';
			if (['Squire','Man-At-Arms','Page','Lords-Page'].indexOf(aw.Peerage) >= 0 || sysName === 'Apprentice') return 'Associate Titles';
			if ((aw.IsTitle && aw.TitleClass >= 30) || sysName === 'Esquire') return 'Noble Titles';
			return 'Offices & Other';
		}

		/* Runs once on first load and again every time the modal reopens (see the
		   kaOnOpen registration below), so a discarded row edit (Task 5A's red
		   Discard Changes button) is actually gone from the screen, not just left
		   there looking unsaved. Resets groups/totalRows and rebuilds the tbody
		   from scratch -- a second call that appended instead of replacing would
		   double every row. */
		function kaRenderAwards() {
			// Preserve which groups the officer had expanded across the rebuild.
			// Per-row dirty state is deliberately NOT preserved -- that reset is
			// the entire point of re-rendering.
			var collapseByLabel = {};
			groups.forEach(function(g) { collapseByLabel[g.label] = g.collapsed; });
			tbody.innerHTML = '';
			groups = [];
			totalRows = 0;

		var groupOrder = ['Ladder Awards','Kingdom-Specific','Knighthoods','Masterhoods','Paragons','Noble Titles','Associate Titles','Offices & Other'];
		var bucket = {};
		groupOrder.forEach(function(g) { bucket[g] = []; });
		(KaConfig.adminAwards || []).forEach(function(aw) {
			var g = classifyAward(aw);
			if (!bucket[g]) { bucket[g] = []; groupOrder.push(g); }
			bucket[g].push(aw);
		});

		groupOrder.forEach(function(groupName, gi) {
			var items = bucket[groupName];
			if (!items || !items.length) return;

			// Alphabetical, not title_class order. title_class is a precedence
			// number an officer cannot see, so the old ordering looked random.
			items.sort(function(a, b) {
				return String(a.KingdomAwardName || '').localeCompare(String(b.KingdomAwardName || ''));
			});

			/* Nest renames under the award they rename. Two kingdom rows can point at one
			   system award -- "Man-at-Arms" alongside "Woman-at-Arms", "Lord" alongside
			   "Lux" -- and shown as flat siblings they read as unrelated awards. The row
			   whose name still matches the system award is the parent; the rest nest under
			   it. If every row was renamed there is no parent row, so the first one leads
			   and its tooltip still names the original.
			   AwardId 0 and the shared "Custom Award" placeholder are deliberately excluded:
			   those rows are independent kingdom awards that merely share an id. */
			items = (function(list) {
				var byAward = {}, order = [];
				list.forEach(function(aw) {
					var sys = aw.AwardName || '';
					var nestable = aw.AwardId > 0 && sys && sys !== 'Custom Award';
					var key = nestable ? ('a' + aw.AwardId) : ('solo' + order.length);
					if (!byAward[key]) { byAward[key] = []; order.push(key); }
					byAward[key].push(aw);
				});
				var out = [];
				order.forEach(function(key) {
					var set = byAward[key];
					if (set.length === 1) { set[0]._aliasChild = false; out.push(set[0]); return; }
					var pi = 0;
					for (var i = 0; i < set.length; i++) {
						if ((set[i].KingdomAwardName || '') === (set[i].AwardName || '')) { pi = i; break; }
					}
					var parent = set.splice(pi, 1)[0];
					parent._aliasChild = false;
					out.push(parent);
					set.forEach(function(child) { child._aliasChild = true; out.push(child); });
				});
				return out;
			})(items);

			/* Group header. A real <button>, so it is reachable by Tab and carries
			   aria-expanded — it used to be a bare <td> with a click listener, which
			   no keyboard or screen-reader user could operate at all.
			   The inline properties below are a colour-free reset ONLY: every visual
			   value (background, colour, size, weight, letter-spacing) is inherited
			   from .ka-award-group-hdr td, so nothing here can beat a dark-mode rule. */
			var hdr = document.createElement('tr');
			hdr.className = 'ka-award-group-hdr ka-collapsed';
			var hdrTd = document.createElement('td');
			hdrTd.colSpan = 8;
			var hdrBtn = document.createElement('button');
			hdrBtn.type = 'button';
			hdrBtn.id = 'ka-awgrp-' + gi;
			hdrBtn.setAttribute('aria-expanded', 'false');
			hdrBtn.style.display    = 'block';
			hdrBtn.style.width      = '100%';
			hdrBtn.style.textAlign  = 'left';
			hdrBtn.style.background = 'none';
			hdrBtn.style.border     = 'none';
			hdrBtn.style.padding    = '0';
			hdrBtn.style.margin     = '0';
			hdrBtn.style.font       = 'inherit';
			hdrBtn.style.color      = 'inherit';
			hdrBtn.style.cursor     = 'pointer';
			hdrBtn.innerHTML = '<i class="fas fa-chevron-down ka-award-group-chev" aria-hidden="true"></i>'
				+ kaEsc(groupName)
				+ '<span class="ka-award-group-count"></span>';
			hdrTd.appendChild(hdrBtn);
			hdr.appendChild(hdrTd);
			tbody.appendChild(hdr);

			var g = {
				key: gi,
				label: groupName,
				hdr: hdr,
				btn: hdrBtn,
				countEl: hdrBtn.querySelector('.ka-award-group-count'),
				rows: [],
				// Collapsed by default -- unless this is a re-render restoring a
				// group the officer had already expanded. (130 rows across eight
				// expanded groups is roughly eight screens of scroll before the
				// first useful action, hence the default.)
				collapsed: collapseByLabel.hasOwnProperty(groupName) ? collapseByLabel[groupName] : true
			};
			items.forEach(function(aw) {
				var ctx = makeAwardRow(aw);
				tbody.appendChild(ctx.tr);
				g.rows.push(ctx);
				totalRows++;
			});
			hdrBtn.addEventListener('click', function() {
				g.collapsed = !g.collapsed;
				syncExpandBtn();
				applyAwardFilter();
			});
			groups.push(g);
		});
		}
		kaRenderAwards();

		function syncExpandBtn() {
			if (!expandBtn) return;
			var anyCollapsed = groups.some(function(g) { return g.collapsed; });
			expandBtn.setAttribute('aria-pressed', anyCollapsed ? 'false' : 'true');
			expandBtn.innerHTML = anyCollapsed
				? '<i class="fas fa-angle-double-down" aria-hidden="true"></i> Expand all'
				: '<i class="fas fa-angle-double-up" aria-hidden="true"></i> Collapse all';
		}

		/* Ported from the sibling kingdom-profile awards panel in revised.js
		   (applyAdminAwardFilter): one pre-lowered haystack per row, built once at
		   render time and re-read on each keystroke instead of walking the DOM. */
		function applyAwardFilter() {
			var q = (searchInp ? searchInp.value : '').trim().toLowerCase();
			var shown = 0;
			groups.forEach(function(g) {
				var matched = 0;
				g.rows.forEach(function(ctx) {
					var hit = !q || ctx.haystack.indexOf(q) !== -1;
					if (hit) matched++;
					ctx.tr.style.display = (hit && !g.collapsed) ? '' : 'none';
				});
				shown += matched;
				g.hdr.style.display = matched ? '' : 'none';
				g.hdr.classList.toggle('ka-collapsed', g.collapsed);
				g.btn.setAttribute('aria-expanded', g.collapsed ? 'false' : 'true');
				if (g.countEl) {
					g.countEl.textContent = q
						? '(' + matched + ' of ' + g.rows.length + ')'
						: '(' + g.rows.length + ')';
				}
			});
			if (emptyEl) emptyEl.style.display = (q && shown === 0) ? '' : 'none';
			if (countEl) {
				countEl.textContent = q
					? (shown + ' of ' + totalRows + ' awards')
					: (totalRows + (totalRows === 1 ? ' award' : ' awards'));
			}
		}

		if (searchInp) {
			// Typing opens every group so a match can never hide inside a collapsed
			// one; clearing the box returns to the collapsed default.
			searchInp.addEventListener('input', function() {
				var searching = searchInp.value.trim().length > 0;
				groups.forEach(function(g) { g.collapsed = !searching; });
				syncExpandBtn();
				applyAwardFilter();
			});
		}
		if (expandBtn) {
			expandBtn.addEventListener('click', function() {
				var anyCollapsed = groups.some(function(g) { return g.collapsed; });
				groups.forEach(function(g) { g.collapsed = !anyCollapsed; });
				syncExpandBtn();
				applyAwardFilter();
			});
		}
		syncExpandBtn();
		applyAwardFilter();

		/* Re-render every time the modal opens, not just on first load. Without this
		   the table the officer sees is whatever was last built in this page session
		   -- so clicking "Discard Changes" (Task 5A) looked broken, since the edited
		   row was still sitting there on screen even though nothing bad had actually
		   reached the server. kaRenderAwards() resets its own group/collapse state
		   before rebuilding, and these two calls re-sync the header classes, row
		   visibility and search filter to match. */
		kaOnOpen('ka-awards-overlay', function() {
			kaRenderAwards();
			syncExpandBtn();
			applyAwardFilter();
		});

		/* Bulk save for the unsaved-changes guard's "Save Changes" button (Task 5A).
		   This modal has no single save action -- it has one per row -- so this walks
		   every group looking for rows still flagged ka-row-dirty and saves each one
		   through ctx.save(), the exact same request the row's own Save button fires.
		   All rows save concurrently; done(ok) fires once every request has settled.
		   A row that fails STAYS dirty (ctx.save only clears it on success) and the
		   aggregate message below says which ones -- the modal is never closed over a
		   save that did not actually land. */
		KA_MODAL_SAVE['ka-awards-overlay'] = function(done) {
			var dirty = [];
			groups.forEach(function(g) {
				g.rows.forEach(function(ctx) { if (ctx.tr.classList.contains('ka-row-dirty')) dirty.push(ctx); });
			});
			if (!dirty.length) { done(true); return; }
			kaClearFeedback('ka-awards-feedback');
			var remaining = dirty.length, failedNames = [];
			dirty.forEach(function(ctx) {
				ctx.save(function(ok) {
					if (!ok) failedNames.push(ctx.aw.KingdomAwardName || 'award');
					remaining--;
					if (remaining > 0) return;
					if (failedNames.length) {
						kaFeedback('ka-awards-feedback',
							'Could not save: ' + failedNames.map(kaEsc).join(', ')
							+ '. Everything else was saved -- fix and try again.',
							false);
						done(false);
					} else {
						kaFeedback('ka-awards-feedback',
							dirty.length === 1 ? '1 award saved.' : dirty.length + ' awards saved.', true);
						done(true);
					}
				});
			});
		};

		// Award alias / custom add forms. Both "Add" buttons now live in the modal
		// footer, so there is no button row inside the body to hide any more —
		// the two footer buttons are hidden individually while a form is open.
		var addBtn = gid('ka-awards-add-btn'), addWrap = gid('ka-add-award-wrap'), addCancel = gid('ka-new-award-cancel');
		var customBtn = gid('ka-custom-add-btn'), customWrap = gid('ka-add-custom-wrap'), customCancel = gid('ka-custom-cancel');

		function setAddButtons(visible) {
			if (addBtn) addBtn.style.display = visible ? '' : 'none';
			if (customBtn) customBtn.style.display = visible ? '' : 'none';
		}
		function revealForm(wrap) {
			if (!wrap) return;
			if (typeof wrap.scrollIntoView === 'function') {
				try { wrap.scrollIntoView({ block: 'nearest' }); } catch (e) { wrap.scrollIntoView(); }
			}
			var first = wrap.querySelector('input:not([type=hidden]):not([disabled]), button');
			if (first) { try { first.focus(); } catch (e) {} }
		}
		function showAliasForm() {
			if (addWrap) addWrap.style.display = '';
			if (customWrap) customWrap.style.display = 'none';
			setAddButtons(false);
			revealForm(addWrap);
		}
		function showCustomForm() {
			if (customWrap) customWrap.style.display = '';
			if (addWrap) addWrap.style.display = 'none';
			setAddButtons(false);
			revealForm(customWrap);
		}
		function showButtons() {
			if (addWrap) addWrap.style.display = 'none';
			if (customWrap) customWrap.style.display = 'none';
			setAddButtons(true);
		}

		if (addBtn) addBtn.addEventListener('click', showAliasForm);
		if (customBtn) customBtn.addEventListener('click', showCustomForm);
		if (addCancel) addCancel.addEventListener('click', showButtons);
		if (customCancel) customCancel.addEventListener('click', showButtons);

		// Title checkbox toggles
		var newIsTitleCb = gid('ka-new-istitle'), newTClassInp = gid('ka-new-tclass');
		if (newIsTitleCb && newTClassInp) newIsTitleCb.addEventListener('change', function() { newTClassInp.disabled = !this.checked; });
		var customIsTitleCb = gid('ka-custom-istitle'), customTClassInp = gid('ka-custom-tclass');
		if (customIsTitleCb && customTClassInp) customIsTitleCb.addEventListener('change', function() { customTClassInp.disabled = !this.checked; });

		// Ladder checkbox toggles. Max Rank is only meaningful while Ladder is
		// ticked, and Ladder/Title? are mutually exclusive -- same rules as the
		// per-row table below, applied to the two "add award" forms.
		var newLadderCb = gid('ka-new-ladder'), newMaxRankInp = gid('ka-new-maxrank');
		if (newLadderCb && newMaxRankInp) {
			newLadderCb.addEventListener('change', function() {
				newMaxRankInp.disabled = !this.checked;
				if (this.checked && newIsTitleCb && newIsTitleCb.checked) {
					newIsTitleCb.checked = false;
					if (newTClassInp) newTClassInp.disabled = true;
				}
			});
		}
		if (newIsTitleCb && newLadderCb) {
			newIsTitleCb.addEventListener('change', function() {
				if (this.checked && newLadderCb.checked) {
					newLadderCb.checked = false;
					if (newMaxRankInp) newMaxRankInp.disabled = true;
				}
			});
		}
		var customLadderCb = gid('ka-custom-ladder'), customMaxRankInp = gid('ka-custom-maxrank');
		if (customLadderCb && customMaxRankInp) {
			customLadderCb.addEventListener('change', function() {
				customMaxRankInp.disabled = !this.checked;
				if (this.checked && customIsTitleCb && customIsTitleCb.checked) {
					customIsTitleCb.checked = false;
					if (customTClassInp) customTClassInp.disabled = true;
				}
			});
		}
		if (customIsTitleCb && customLadderCb) {
			customIsTitleCb.addEventListener('change', function() {
				if (this.checked && customLadderCb.checked) {
					customLadderCb.checked = false;
					if (customMaxRankInp) customMaxRankInp.disabled = true;
				}
			});
		}

		// System award alias dropdown
		var trigger = gid('ka-alias-trigger'), dropdown = gid('ka-alias-dropdown'), searchInp2 = gid('ka-alias-search');
		var listEl = gid('ka-alias-list'), hiddenInp = gid('ka-new-award-id'), nameInp = gid('ka-new-award-name');
		var labelSpan = gid('ka-alias-label');
		var sysAwards = KaConfig.systemAwards || [];
		var aliasOpen = false;

		function buildAliasList(filter) {
			if (!listEl) return;
			listEl.innerHTML = '';
			var lc = (filter || '').toLowerCase(), count = 0;
			sysAwards.forEach(function(sa) {
				if (lc && sa.Name.toLowerCase().indexOf(lc) === -1) return;
				var div = document.createElement('div');
				div.className = 'ka-alias-item';
				div.textContent = sa.Name;
				div.addEventListener('click', function() { selectAlias(sa.AwardId, sa.Name); });
				listEl.appendChild(div);
				count++;
			});
			if (!count) { var empty = document.createElement('div'); empty.className = 'ka-alias-empty'; empty.textContent = 'No matching awards'; listEl.appendChild(empty); }
		}
		function selectAlias(id, name) {
			if (hiddenInp) hiddenInp.value = id;
			if (labelSpan) { labelSpan.textContent = name; }
			if (nameInp && !nameInp.value.trim()) nameInp.value = name;
			closeAlias();
		}
		function openAlias() { if (!dropdown || aliasOpen) return; aliasOpen = true; dropdown.style.display = ''; buildAliasList(''); if (searchInp2) { searchInp2.value = ''; searchInp2.focus(); } }
		function closeAlias() { if (!dropdown) return; aliasOpen = false; dropdown.style.display = 'none'; }
		if (trigger) trigger.addEventListener('click', function(e) { e.preventDefault(); aliasOpen ? closeAlias() : openAlias(); });
		if (searchInp2) { searchInp2.addEventListener('input', function() { buildAliasList(this.value); }); searchInp2.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAlias(); }); }
		document.addEventListener('click', function(e) { if (aliasOpen && trigger && dropdown && !trigger.contains(e.target) && !dropdown.contains(e.target)) closeAlias(); });

		// Save new alias
		var saveNewBtn = gid('ka-new-award-save');
		if (saveNewBtn) {
			saveNewBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				var awardId = parseInt((hiddenInp ? hiddenInp.value : '0') || '0', 10);
				var name = (nameInp ? nameInp.value : '').trim();
				if (!awardId) { kaFeedback('ka-awards-feedback', 'Please select a system award.', false); return; }
				if (!name) { kaFeedback('ka-awards-feedback', 'Award name is required.', false); return; }
				saveNewBtn.disabled = true;
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: 0, AwardId: awardId, KingdomAwardName: name,
					ReignLimit: gid('ka-new-reign').value, MonthLimit: gid('ka-new-month').value,
					IsTitle: gid('ka-new-istitle').checked ? 1 : 0, TitleClass: gid('ka-new-tclass').value,
					IsLadder: (newLadderCb && newLadderCb.checked) ? 1 : 0,
					MaxLevel: (newMaxRankInp && parseInt(newMaxRankInp.value, 10)) || 10
				}, null, 'ka-awards-feedback', function() {
					saveNewBtn.disabled = false;
					kaFeedback('ka-awards-feedback', 'Award alias created!', true);
					setTimeout(function() { location.reload(); }, 900);
				});
			});
		}

		// Save custom award
		var saveCustomBtn = gid('ka-custom-save');
		if (saveCustomBtn) {
			saveCustomBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				var name = (gid('ka-custom-name').value || '').trim();
				if (!name) { kaFeedback('ka-awards-feedback', 'Award name is required.', false); return; }
				saveCustomBtn.disabled = true;
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: 0, AwardId: 0, KingdomAwardName: name,
					ReignLimit: gid('ka-custom-reign').value, MonthLimit: gid('ka-custom-month').value,
					IsTitle: gid('ka-custom-istitle').checked ? 1 : 0, TitleClass: gid('ka-custom-tclass').value,
					IsLadder: (customLadderCb && customLadderCb.checked) ? 1 : 0,
					MaxLevel: (customMaxRankInp && parseInt(customMaxRankInp.value, 10)) || 10
				}, null, 'ka-awards-feedback', function() {
					saveCustomBtn.disabled = false;
					kaFeedback('ka-awards-feedback', 'Kingdom-specific award created!', true);
					setTimeout(function() { location.reload(); }, 900);
				});
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   CREATE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-cp-submit');
		if (!btn) return;

		/* Password is a two-way choice, not a box that may be left blank.
		   Player::CreatePlayer() writes NO credential row when Password is empty --
		   deliberately, because hashing salt + USERNAME + '' would let anyone in with an
		   empty password box. The consequence is that the account cannot be signed in to
		   at all, which an unlabelled "optional" field never said out loud. */
		var PW_MIN  = 8;
		var pwWrap  = gid('ka-cp-pw-wrap');
		var pwInput = gid('ka-cp-password');

		function pwMode() {
			var picked = document.querySelector('input[name="ka-cp-pwmode"]:checked');
			return picked ? picked.value : 'self';
		}
		function syncPwMode() {
			var temp = pwMode() === 'temp';
			if (pwWrap) pwWrap.style.display = temp ? '' : 'none';
			if (pwInput) {
				pwInput.required = temp;
				pwInput.setAttribute('aria-required', temp ? 'true' : 'false');
				// Never carry a typed password into the "player sets their own" branch --
				// it would be posted and silently create the credential anyway.
				if (!temp) { pwInput.value = ''; pwInput.defaultValue = ''; }
			}
			document.querySelectorAll('input[name="ka-cp-pwmode"]').forEach(function(r) {
				var card = r.closest('.ka-choice-opt');
				if (card) card.classList.toggle('ka-choice-on', r.checked);
			});
		}
		document.querySelectorAll('input[name="ka-cp-pwmode"]').forEach(function(r) {
			r.addEventListener('change', syncPwMode);
		});
		// Reset-on-open restores the radios programmatically, which fires no change
		// event, so the disclosure has to be re-synced by hand each time it opens.
		kaOnOpen('ka-createplayer-overlay', syncPwMode);
		syncPwMode();

		btn.addEventListener('click', function() {
			var parkId   = gid('ka-cp-park').value;
			var persona  = gid('ka-cp-persona').value.trim();
			var username = gid('ka-cp-username').value.trim();
			var temp     = pwMode() === 'temp';
			var password = temp && pwInput ? pwInput.value : '';
			if (!parkId)             { kaFeedback('ka-cp-feedback', 'Please select a home park.', false); return; }
			if (!persona)            { kaFeedback('ka-cp-feedback', 'Persona is required.', false); return; }
			if (!username)           { kaFeedback('ka-cp-feedback', 'Username is required.', false); return; }
			if (username.length < 4) { kaFeedback('ka-cp-feedback', 'Username must be at least 4 characters.', false); return; }
			if (temp && password.length < PW_MIN) {
				kaFeedback('ka-cp-feedback', 'A temporary password must be at least ' + PW_MIN + ' characters.', false);
				if (pwInput) pwInput.focus();
				return;
			}
			var restricted = document.querySelector('input[name="ka-cp-restricted"]:checked');
			var waivered   = document.querySelector('input[name="ka-cp-waivered"]:checked');
			kaPost(UIR + 'PlayerAjax/park/' + parkId + '/create', {
				Persona: persona,
				GivenName: gid('ka-cp-given').value.trim(),
				Surname: gid('ka-cp-surname').value.trim(),
				Email: gid('ka-cp-email').value.trim(),
				UserName: username,
				Password: password,
				Restricted: restricted ? restricted.value : '0',
				Waivered: waivered ? waivered.value : '0',
			}, btn, 'ka-cp-feedback', function(r) {
				window.location.href = UIR + 'Player/profile/' + r.mundaneId;
			});
		});
	})();

	/* ══════════════════════════════════════════════
	   MOVE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		function mpCheck() {
			var pid = gid('ka-mp-player-id').value;
			var pkid = gid('ka-mp-park-id').value;
			gid('ka-mp-submit').disabled = !(pid && pkid);
		}
		kaAc({ inputId:'ka-mp-player-name', hiddenId:'ka-mp-player-id', resultsId:'ka-mp-player-results',
			fetchFn: kaSearchPlayers, onSelect: mpCheck, onClear: mpCheck });
		kaAc({ inputId:'ka-mp-park-name', hiddenId:'ka-mp-park-id', resultsId:'ka-mp-park-results',
			fetchFn: kaSearchParks, onSelect: mpCheck, onClear: mpCheck });

		var btn = gid('ka-mp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var playerId = gid('ka-mp-player-id').value;
			var parkId   = gid('ka-mp-park-id').value;
			if (!playerId || !parkId) return;
			kaPost(UIR + 'PlayerAjax/player/' + playerId + '/moveplayer', { ParkId: parkId },
				btn, 'ka-mp-feedback', function() {
					kaFeedback('ka-mp-feedback',
						'Player moved successfully. <a href="' + UIR + 'Park/profile/' + parkId + '">View park</a> &middot; <a href="' + UIR + 'Player/profile/' + playerId + '">View player</a>', true);
					['ka-mp-player-name','ka-mp-park-name'].forEach(function(id){gid(id).value='';});
					['ka-mp-player-id','ka-mp-park-id'].forEach(function(id){gid(id).value='';});
					btn.disabled = true;
				});
		});
	})();

	/* ══════════════════════════════════════════════
	   MERGE PLAYERS
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-mgp-submit');
		if (!btn) return;

		var previewBox = gid('ka-mgp-preview');
		var countEl    = gid('ka-mgp-arm-count');
		var labelEl    = gid('ka-mgp-submit-label');
		var swapBtn    = gid('ka-mgp-swap');

		/* The full player-search row for each side, so the preview can show more
		   than the persona sitting in the text box. The payload is whatever
		   SearchService::formatScopedPlayerRow returns -- park, kingdom, abbreviation,
		   active and suspended. There is deliberately NO award / attendance / office /
		   unit count here: no endpoint on this page reports them, and inventing one
		   from the template is not this file's job. The profile links below are the
		   honest substitute. */
		var picked = { keep: null, remove: null };

		function statusOf(p) {
			var bits = [Number(p.Active) === 0 ? 'Inactive' : 'Active'];
			if (Number(p.Suspended) === 1) bits.push('Suspended');
			return bits.join(' &middot; ');
		}
		function row(label, value) {
			return '<div><span class="ka-mgp-dt">' + label + '</span><span class="ka-mgp-dd">' + value + '</span></div>';
		}
		function cardHtml(p, role) {
			var isKeep = (role === 'keep');
			return '<span class="ka-mgp-role">'
					+ '<i class="fas ' + (isKeep ? 'fa-shield-alt' : 'fa-skull-crossbones') + '" aria-hidden="true"></i> '
					+ (isKeep ? 'Keep' : 'Delete') + '</span>'
				+ '<span class="ka-mgp-name">' + kaEsc(p.Persona) + '</span>'
				+ '<div class="ka-mgp-dl">'
					// kaEsc() around the entity, not around the fallback: escaping
					// '&mdash;' would print the literal text "&mdash;".
					+ row('Park', p.ParkName ? kaEsc(p.ParkName) : '&mdash;')
					+ row('Kingdom', (p.KingdomName ? kaEsc(p.KingdomName) : '&mdash;') + (p.KAbbr ? ' (' + kaEsc(p.KAbbr) + ')' : ''))
					+ row('Status', statusOf(p))
					+ row('Player ID', '#' + kaEsc(p.MundaneId))
				+ '</div>'
				+ '<a class="ka-mgp-link" href="' + UIR + 'Player/profile/' + encodeURIComponent(p.MundaneId) + '" target="_blank" rel="noopener">'
					+ 'Open profile <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>';
		}
		function renderPreview() {
			if (!previewBox) return;
			if (!picked.keep || !picked.remove) { previewBox.style.display = 'none'; return; }
			gid('ka-mgp-card-keep').innerHTML   = cardHtml(picked.keep, 'keep');
			gid('ka-mgp-card-remove').innerHTML = cardHtml(picked.remove, 'remove');
			previewBox.style.display = '';
		}

		/* ---- Wait-to-arm (see the .ka-arm-btn contract in admin-console.css) ----
		   Deliberately NOT a type-the-name gate: the officer already typed the name
		   to find the player, so typing it again is muscle memory, not attention.
		   Five seconds of an inert button is.

		   `disabled` is the load-bearing half of the CSS contract, and disabling a
		   focused button drops focus to <body>, so focus is put back on the button
		   the moment it arms. The countdown itself lives in .ka-arm-count, which is
		   an aria-live region -- that is what carries progress for reduced-motion
		   and screen-reader users, since the CSS sweep is suppressed for them. */
		var ARM_SECONDS = 5;
		var armTimer = null, armLeft = 0, armed = false;

		function setLabel(text) { if (labelEl) labelEl.textContent = text; }
		function disarm() {
			if (armTimer) { clearInterval(armTimer); armTimer = null; }
			armed = false; armLeft = 0;
			btn.classList.remove('ka-arm-waiting', 'ka-arm-ready');
			btn.removeAttribute('aria-disabled');
			btn.style.removeProperty('--ka-arm-delay');
			if (countEl) { countEl.style.display = 'none'; countEl.textContent = ''; }
			setLabel('Merge Players');
		}
		function startArming() {
			armLeft = ARM_SECONDS;
			armed = false;
			btn.classList.remove('ka-arm-ready');
			btn.classList.add('ka-arm-waiting');
			btn.disabled = true;
			btn.setAttribute('aria-disabled', 'true');
			btn.style.setProperty('--ka-arm-delay', ARM_SECONDS + 's');
			setLabel('Merging in');
			if (countEl) { countEl.style.display = ''; countEl.textContent = String(armLeft); }
			if (armTimer) clearInterval(armTimer);
			armTimer = setInterval(function() {
				armLeft--;
				if (countEl) countEl.textContent = String(Math.max(0, armLeft));
				if (armLeft > 0) return;
				clearInterval(armTimer); armTimer = null;
				armed = true;
				btn.classList.remove('ka-arm-waiting');
				btn.classList.add('ka-arm-ready');
				btn.disabled = false;
				btn.removeAttribute('aria-disabled');
				if (countEl) countEl.style.display = 'none';
				setLabel('Confirm merge');
				try { btn.focus(); } catch (e) {}
			}, 1000);
		}

		function mgpCheck() {
			var keep   = gid('ka-mgp-keep-id').value;
			var remove = gid('ka-mgp-remove-id').value;
			// Any change to either side is a new decision: throw away the countdown.
			disarm();
			btn.disabled = !(keep && remove);
			if (!keep)   picked.keep = null;
			if (!remove) picked.remove = null;
			renderPreview();
		}
		function onPick(side) {
			return function(id, label, extra) {
				try { picked[side] = extra ? JSON.parse(extra) : null; } catch (e) { picked[side] = null; }
				mgpCheck();
			};
		}
		kaAc({ inputId:'ka-mgp-keep-name', hiddenId:'ka-mgp-keep-id', resultsId:'ka-mgp-keep-results',
			fetchFn: kaSearchPlayers, onSelect: onPick('keep'), onClear: mgpCheck });
		kaAc({ inputId:'ka-mgp-remove-name', hiddenId:'ka-mgp-remove-id', resultsId:'ka-mgp-remove-results',
			fetchFn: kaSearchPlayers, onSelect: onPick('remove'), onClear: mgpCheck });

		/* Picking Keep/Remove the wrong way round is the failure this whole modal
		   exists to prevent, and re-searching both boxes to fix it is exactly when
		   someone gives up and just clicks Merge. */
		if (swapBtn) {
			swapBtn.addEventListener('click', function() {
				var kn = gid('ka-mgp-keep-name'), rn = gid('ka-mgp-remove-name');
				var ki = gid('ka-mgp-keep-id'),   ri = gid('ka-mgp-remove-id');
				var t;
				t = kn.value; kn.value = rn.value; rn.value = t;
				t = ki.value; ki.value = ri.value; ri.value = t;
				t = picked.keep; picked.keep = picked.remove; picked.remove = t;
				mgpCheck();
				/* Not kaFeedback(): a success feedback re-baselines the dirty guard
				   (kaMarkClean), and swapping two picks saves nothing. This is a
				   plain aria-live status line instead. */
				var hint = gid('ka-mgp-swap-hint');
				if (hint) hint.textContent = kn.value
					? 'Swapped — ' + kn.value + ' is now the record that survives.'
					: 'Swapped.';
			});
		}

		// Reopening must not inherit a half-finished decision or a live countdown.
		var SWAP_HINT_IDLE = (gid('ka-mgp-swap-hint') || {}).textContent || '';
		kaOnOpen('ka-mergeplayer-overlay', function() {
			picked.keep = null; picked.remove = null;
			disarm();
			renderPreview();
			var hint = gid('ka-mgp-swap-hint');
			if (hint) hint.textContent = SWAP_HINT_IDLE;
		});

		btn.addEventListener('click', function() {
			var keepId   = gid('ka-mgp-keep-id').value;
			var removeId = gid('ka-mgp-remove-id').value;
			if (!keepId || !removeId) return;
			if (keepId === removeId) { kaFeedback('ka-mgp-feedback', 'Cannot merge a player with themselves.', false); return; }
			if (!armed) { startArming(); return; }
			disarm();
			setLabel('Merging…');
			kaPost(UIR + 'PlayerAjax/merge', { ToMundaneId: keepId, FromMundaneId: removeId },
				btn, 'ka-mgp-feedback',
				function() { window.location.href = UIR + 'Player/profile/' + keepId; },
				function() { setLabel('Merge Players'); });
		});
	})();

	/* ══════════════════════════════════════════════
	   CLAIM PARK
	   ══════════════════════════════════════════════ */
	(function() {
		var copyBtn = gid('ka-cp-copy');
		if (!copyBtn) return;
		var label = gid('ka-cp-copy-label');
		var idle  = label ? label.textContent : 'Copy address';
		var timer = null;

		function flash(text) {
			if (!label) return;
			label.textContent = text;
			clearTimeout(timer);
			timer = setTimeout(function() { label.textContent = idle; }, 2500);
		}
		copyBtn.addEventListener('click', function() {
			var address = copyBtn.dataset.address || '';
			// navigator.clipboard is https-only and absent in some embedded views;
			// the textarea + execCommand path is the fallback, and if BOTH fail the
			// address is still printed on the page underneath.
			var done = function() { flash('Copied'); kaFeedback('ka-cp-feedback', 'Address copied to your clipboard.', true); };
			var failed = function() { flash('Copy failed'); kaFeedback('ka-cp-feedback', 'Could not reach your clipboard &mdash; the address is written out below the buttons.', false); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(address).then(done).catch(function() { legacyCopy(address) ? done() : failed(); });
				return;
			}
			if (legacyCopy(address)) { done(); } else { failed(); }
		});
		function legacyCopy(text) {
			try {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.setAttribute('readonly', '');
				ta.style.position = 'fixed';
				ta.style.top = '-1000px';
				document.body.appendChild(ta);
				ta.select();
				var ok = document.execCommand('copy');
				document.body.removeChild(ta);
				return ok;
			} catch (e) { return false; }
		}
	})();

	/* ══════════════════════════════════════════════
	   OPERATIONS
	   ══════════════════════════════════════════════ */
	(function() {
		// Reset Waivers
		var resetBtn = gid('ka-ops-reset-waivers');
		if (resetBtn) {
			resetBtn.addEventListener('click', function() {
				var entityLc = resetBtn.dataset.entity || KaConfig.entityLabelLc || 'kingdom';
				var raw      = resetBtn.dataset.count;
				var count    = (raw === '' || raw === undefined) ? null : Number(raw);
				/* The confirm now carries the two facts the officer needs and did not
				   have: HOW MANY players it touches (read before the reset runs -- the
				   answer is always zero afterwards), and that the UPDATE has no active
				   filter, so it reaches inactive and retired members too. */
				var head = (count === null)
					? '<p>This clears the waiver flag for every player in this ' + kaEsc(entityLc) + '.</p>'
					: '<p>This clears the waiver flag for <strong>' + kaEsc(count.toLocaleString())
						+ '</strong> player' + (count === 1 ? '' : 's') + ' in this ' + kaEsc(entityLc) + '.</p>';
				kaConfirm(
					head
					+ '<ul class="ka-confirm-list">'
					+ '<li><strong>Inactive and retired members are cleared too.</strong> The update is scoped to this ' + kaEsc(entityLc) + ' and nothing else &mdash; there is no active filter.</li>'
					+ '<li>Everyone affected has to sign a new waiver before their flag can go back on.</li>'
					+ '<li>There is no undo here, but every player cleared is written to the Audit Log, so the list can be recovered.</li>'
					+ '</ul>',
					function() {
						resetBtn.disabled = true;
						kaPost(BASE_URL + 'resetwaivers', {}, null, 'ka-ops-feedback', function(r) {
							// Nothing left to clear, so the button has no work to do
							// until the page is reloaded with fresh counts.
							resetBtn.dataset.count = '0';
							var blast = gid('ka-ops-blast');
							if (blast) {
								blast.className = 'ka-ops-blast ka-muted';
								blast.textContent = 'No players in this ' + entityLc + ' currently hold a waiver, so there is nothing left to clear.';
							}
							kaFeedback('ka-ops-feedback', r.message || 'Waivers reset.', true);
						}, function() { resetBtn.disabled = (resetBtn.dataset.count === '0'); });
					},
					'Reset Waivers',
					{ danger: true, html: true, okLabel: 'Reset waivers', okIcon: 'fas fa-eraser' }
				);
			});
		}

		// Active Status toggle
		var statusBtn = gid('ka-ops-status-toggle');
		if (statusBtn) {
			statusBtn.addEventListener('click', function() {
				var isActive = statusBtn.dataset.active === '1';
				var newActive = isActive ? 'Retired' : 'Active';
				var label = isActive ? 'mark this as inactive' : 'restore this to active';
				kaConfirm('Are you sure you want to ' + label + '?', function() {
					kaClearFeedback('ka-ops-feedback');
					var fd = new FormData();
					fd.append('Active', newActive);
					fetch(BASE_URL + 'setstatus', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (r && r.status === 0) {
							statusBtn.dataset.active = newActive === 'Active' ? '1' : '0';
							gid('ka-ops-status-label').textContent = newActive === 'Active' ? 'Active' : 'Inactive';
							if (newActive === 'Active') {
								statusBtn.innerHTML = '<i class="fas fa-ban"></i> Mark Inactive';
								statusBtn.classList.add('ka-ops-btn-danger');
							} else {
								statusBtn.innerHTML = '<i class="fas fa-check-circle"></i> Restore to Active';
								statusBtn.classList.remove('ka-ops-btn-danger');
							}
							kaFeedback('ka-ops-feedback', newActive === 'Active' ? 'Restored to active.' : 'Marked inactive.', true);
						} else { kaFeedback('ka-ops-feedback', (r && r.error) ? r.error : 'Request failed.', false); }
					})
					.catch(function() { kaFeedback('ka-ops-feedback', 'Request failed.', false); });
				}, newActive === 'Active' ? 'Restore' : 'Mark Inactive',
					// Restoring is benign; taking an org offline is not, so only the
					// one direction gets the danger treatment.
					isActive
						? { danger: true, okLabel: 'Mark inactive', okIcon: 'fas fa-ban' }
						: { okLabel: 'Restore to active', okIcon: 'fas fa-check-circle' });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PRINCIPALITY STATUS
	   ══════════════════════════════════════════════ */
	(function() {
		if (!KaConfig.isOrkAdmin) return;

		kaAc({ inputId:'ka-prinz-parent-name', hiddenId:'ka-prinz-parent-id', resultsId:'ka-prinz-parent-results',
			fetchFn: kaSearchKingdoms });

		function doSetParent(newParentId, successMsg) {
			var fd = new FormData();
			fd.append('ParentKingdomId', newParentId);
			fetch(BASE_URL + 'setparent', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				if (r && r.status === 0) kaFeedback('ka-prinz-feedback', successMsg, true);
				else kaFeedback('ka-prinz-feedback', (r && r.error) ? r.error : 'Save failed.', false);
			})
			.catch(function() { kaFeedback('ka-prinz-feedback', 'Request failed.', false); });
		}

		var sponsorBtn = gid('ka-prinz-sponsor-save');
		if (sponsorBtn) {
			sponsorBtn.addEventListener('click', function() {
				kaClearFeedback('ka-prinz-feedback');
				var newParentId = parseInt(gid('ka-prinz-parent-id').value || '0', 10);
				if (!newParentId) { kaFeedback('ka-prinz-feedback', 'Please select a sponsor kingdom.', false); return; }
				doSetParent(newParentId, 'Sponsor kingdom updated.');
			});
		}

		var promoteBtn = gid('ka-prinz-promote');
		if (promoteBtn) {
			promoteBtn.addEventListener('click', function() {
				kaConfirm('Remove this principality\'s sponsor and make it a full kingdom?', function() {
					kaClearFeedback('ka-prinz-feedback');
					doSetParent(0, 'Converted to kingdom. Reload to see updated status.');
				}, 'Convert to Kingdom');
			});
		}
	})();

})();
</script>
