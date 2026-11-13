<?php
/* =====================================================================
   Correct the Rolls tab -- rendered INTO the Manage Officers modal body.
   ---------------------------------------------------------------------
   Officer history is edited on its own page now (Reports/officer_history
   with edit=1: newest term first, grouped by office, season entry). This
   tab is only an explanation plus a link, so the modal and the page can
   never drift apart as two full editors.

   Reads $mo_kingdom_id / $mo_park_id / $mo_can_edit_history from the host
   partial (_manage_officers.tpl). $mo_can_edit_history is
   OfficerPosition::can_edit_history_for() -- the gate the history writes
   enforce -- so "Edit the rolls" is offered only to someone the ledger will let
   edit. Everyone else gets "View the rolls" and the permission it takes.
   window.crShowTab() switches the host's two tabs.
   ===================================================================== */
$cr_kid  = (int)($mo_kingdom_id ?? 0);
$cr_pid  = (int)($mo_park_id ?? 0);
$cr_edit = !empty($mo_can_edit_history);
$cr_url  = UIR . 'Reports/officer_history&KingdomId=' . $cr_kid . ($cr_pid > 0 ? '&ParkId=' . $cr_pid : '');
?>
<div class="mo-tabpanel" id="mo-tabpanel-rolls" role="tabpanel" aria-labelledby="mo-tabbtn-rolls" hidden>
	<div class="cr-intro">
		<p class="cr-lede">Officer history is edited on its own page: newest term first, grouped by office, with season entry. This is the authoritative record behind the public History tab.</p>
		<?php if ($cr_edit) { ?>
		<a class="kn-btn kn-btn-primary" href="<?= htmlspecialchars($cr_url . '&edit=1', ENT_QUOTES) ?>">
			<i class="fas fa-scroll" aria-hidden="true"></i> Edit the rolls
		</a>
		<?php } else { ?>
		<a class="kn-btn kn-btn-secondary" href="<?= htmlspecialchars($cr_url, ENT_QUOTES) ?>">
			<i class="fas fa-scroll" aria-hidden="true"></i> View the rolls
		</a>
		<p class="cr-perm-note">Editing the rolls needs the <?= $cr_pid > 0 ? 'park' : 'kingdom' ?> Officer History permission.</p>
		<?php } ?>
	</div>
</div>

<style>
/* ============ Correct the Rolls tab ============ */
.cr-intro { margin-bottom:14px; }
.cr-lede { font-size:13px; color:#718096; line-height:1.5; margin:0 0 14px 0; }
html[data-theme="dark"] .cr-lede { color:var(--ork-text-secondary); }
.cr-perm-note { font-size:12px; color:#718096; margin:8px 0 0 0; }
html[data-theme="dark"] .cr-perm-note { color:var(--ork-text-secondary); }
</style>

<script>
(function () {
	window.crOpen = function() { crShowTab('rolls'); };
	window.crShowTab = function(tab) {
		var isRolls = (tab === 'rolls');
		document.getElementById('mo-tabbtn-positions').classList.toggle('mo-tab-active', !isRolls);
		document.getElementById('mo-tabbtn-positions').setAttribute('aria-selected', String(!isRolls));
		document.getElementById('mo-tabbtn-rolls').classList.toggle('mo-tab-active', isRolls);
		document.getElementById('mo-tabbtn-rolls').setAttribute('aria-selected', String(isRolls));
		document.getElementById('mo-tabpanel-positions').hidden = isRolls;
		document.getElementById('mo-tabpanel-rolls').hidden = !isRolls;
	};
})();
</script>
