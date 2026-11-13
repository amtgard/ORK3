<?php
/* -----------------------------------------------------------------------
   Admin-console MODAL CHROME -- the shared <style> block behind the .ka-*
   modals.

   Extracted VERBATIM from partials/_kingdom_admin_modals.tpl, where it used
   to sit inline, so the Park console can wear the same modal chrome without a
   second copy that drifts. It is the CSS counterpart of _ka_modal_core.tpl.

   It stays a BODY <style> block rather than moving into admin-console.css on
   purpose: several rules here (the .ka-ops-blast layout, .ka-confirm-danger)
   win over admin-console.css at EQUAL specificity, and they can only do that
   from a later source position than the stylesheet <link> in the head. Moving
   them would silently lose those overrides.

   Include it ONCE per page, before the modal markup. The guard makes a second
   include a no-op. Both consoles get the whole vocabulary, including the few
   blocks only one of them uses (Configuration rows, Claim Park) -- the same
   trade any shared stylesheet makes, and far cheaper than two copies.
   ----------------------------------------------------------------------- */
if (defined('KA_MODAL_CHROME_RENDERED')) {
    return;
}
define('KA_MODAL_CHROME_RENDERED', true);
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
