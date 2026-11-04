<?php
/* =====================================================================
   Correct the Rolls tab -- rendered INTO the Manage Officers modal body.
   ---------------------------------------------------------------------
   Officer history is edited on its own page now (Reports/officer_history
   with edit=1: newest term first, grouped by office, season entry). This
   tab is only an explanation plus a link, so the modal and the page can
   never drift apart as two full editors.

   Reads $mo_kingdom_id / $mo_park_id from the host partial
   (_manage_officers.tpl). window.crShowTab() switches the host's two tabs.
   ===================================================================== */
$cr_kid = (int)($mo_kingdom_id ?? 0);
$cr_pid = (int)($mo_park_id ?? 0);
$cr_url = UIR . 'Reports/officer_history&KingdomId=' . $cr_kid . ($cr_pid > 0 ? '&ParkId=' . $cr_pid : '') . '&edit=1';
?>
<div class="mo-tabpanel" id="mo-tabpanel-rolls" role="tabpanel" aria-labelledby="mo-tabbtn-rolls" hidden>
	<div class="cr-intro">
		<p class="cr-lede">Officer history is now edited on its own page: newest term first, grouped by office, with season entry. This is the authoritative record behind the public History tab.</p>
		<a class="kn-btn kn-btn-primary" href="<?= htmlspecialchars($cr_url, ENT_QUOTES) ?>">
			<i class="fas fa-scroll" aria-hidden="true"></i> Edit Officer History
		</a>
	</div>
</div>

<style>
/* ============ Correct the Rolls tab ============ */
.cr-intro { margin-bottom:14px; }
.cr-lede { font-size:13px; color:#718096; line-height:1.5; margin:0 0 14px 0; }
html[data-theme="dark"] .cr-lede { color:var(--ork-text-secondary); }
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
