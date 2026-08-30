<?php
/* =====================================================================
   Park admin console.
   ---------------------------------------------------------------------
   NOT a port of Admin_kingdom.tpl. That page is built around
   $AdminDashboard / $AdminInfo / $KingdomInfo / $kingdom_info -- a data
   contract with no park equivalent, including a work-queue dashboard
   Kingdom::GetAdminDashboard() computes and nothing on the Park side
   supplies. This page's only data dependency is $ParkInfo, which
   Admin::park() (and Admin::reset_waivers()) already load.

   It carries the SAME link set as the legacy orkui/template/default/
   Admin_park.tpl -- restyled with the .ka-* chrome Admin_kingdom.tpl
   already defines -- minus "Set Park Officers", which the officers card
   below replaces.
   ----------------------------------------------------------------- */
$parkName = htmlspecialchars($ParkInfo['ParkName'] ?? '');
$parkId   = (int)($ParkInfo['ParkId'] ?? 0);
$uir      = UIR;
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/admin-console.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/admin-console.css') ?>">

<?php if (strlen($Message ?? '') > 0) : ?>
	<div class="success-message"><?= $Message ?></div>
<?php endif; ?>
<?php if (strlen($Error ?? '') > 0) : ?>
	<div class="error-message"><?= $Error ?></div>
<?php endif; ?>

<!-- =============================================
     HERO
     ============================================= -->
<div class="ka-hero">
	<div class="ka-hero-content">
		<div class="ka-hero-info">
			<a href="<?= $uir ?>Park/profile/<?= $parkId ?>" class="ka-hero-back"><i class="fas fa-arrow-left"></i> Back to Park</a>
			<h1 class="ka-hero-title"><a href="<?= $uir ?>Park/profile/<?= $parkId ?>"><?= $parkName ?></a></h1>
			<div class="ka-hero-sub">Park Administration</div>
			<div class="ka-hero-badges">
				<span class="ka-hero-badge"><i class="fas fa-map-marker-alt"></i> Park</span>
			</div>
		</div>
	</div>
</div>

<!-- =============================================
     OFFICERS CARD
     ---------------------------------------------
     Hosts the shared Manage Officers partial (also used by the kingdom
     console) scoped to this park. $mo_can_manage is hardcoded true
     because Admin::park()'s own front-door check (which accepts park
     standing alongside kingdom standing) has already gated the whole
     page -- nobody without standing over this park reaches this include.
     ============================================= -->
<div class="ka-card">
	<div class="ka-card-header">
		<div class="ka-card-title"><i class="fas fa-user-shield"></i> Officers</div>
	</div>
	<div class="ka-card-body">
<?php
$mo_kingdom_id = (int)($ParkInfo['KingdomId'] ?? 0);
$mo_park_id    = (int)($ParkInfo['ParkId'] ?? 0);
$mo_can_manage = true; // the route's front-door check already gated this page
include __DIR__ . '/partials/_manage_officers.tpl';
?>
	</div>
</div>

<!-- =============================================
     LINKS -- same set as the legacy page, restyled
     ============================================= -->
<div class="ka-sections-grid">

	<div class="ka-section">
		<div class="ka-section-title"><i class="fas fa-cog"></i> Park Settings</div>
		<div class="ka-action-tiles">
			<a class="ka-action-card" href="<?= $uir ?>Admin/editpark/<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-sliders-h"></i></div>
				<div class="ka-action-label">Configure Park</div>
				<div class="ka-action-desc">Name, abbreviation, location, active status</div>
			</a>
		</div>
	</div>

	<div class="ka-section">
		<div class="ka-section-title"><i class="fas fa-tools"></i> Operations</div>
		<div class="ka-action-tiles">
			<a class="ka-action-card unimplemented" href="<?= $uir ?>Admin/downloadpark/<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-download"></i></div>
				<div class="ka-action-label">Download Park Dataset</div>
				<div class="ka-action-desc">Export this park's records</div>
			</a>
			<a class="ka-action-card" href="<?= $uir ?>Admin/createplayer/park/<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-user-plus"></i></div>
				<div class="ka-action-label">Create Player</div>
				<div class="ka-action-desc">Register a new player account</div>
			</a>
			<a class="ka-action-card" href="<?= $uir ?>Admin/claimplayer/park/<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-exchange-alt"></i></div>
				<div class="ka-action-label">Move Player</div>
				<div class="ka-action-desc">Transfer a player to this park</div>
			</a>
			<a class="ka-action-card" href="<?= $uir ?>Admin/mergeplayer/park/<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-compress-arrows-alt"></i></div>
				<div class="ka-action-label">Merge Players</div>
				<div class="ka-action-desc">Combine two duplicate records &mdash; permanent</div>
			</a>
<?php if (!empty($CanResetWaivers)) : ?>
			<a class="ka-action-card ka-reset-waivers-link" id="ka-reset-waivers-link"
				href="<?= $uir ?>Admin/resetwaivers/park/<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-undo"></i></div>
				<div class="ka-action-label">Reset Waivers</div>
				<div class="ka-action-desc">Clear waivers for every player in this park</div>
			</a>
<?php endif; ?>
			<a class="ka-action-card" href="<?= $uir ?>Admin/createevent">
				<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-calendar-plus"></i></div>
				<div class="ka-action-label">Schedule an Event</div>
				<div class="ka-action-desc">Add an event for this park</div>
			</a>
			<a class="ka-action-card unimplemented" href="<?= $uir ?>Tournament/create&ParkId=<?= $parkId ?>">
				<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-trophy"></i></div>
				<div class="ka-action-label">Create Tournament</div>
				<div class="ka-action-desc">Set up a new tournament for this park</div>
			</a>
		</div>
	</div>

</div>

<script>
// Reset Waivers keeps its confirmation, but through the Manage Officers
// partial's own confirm modal (moShowConfirm / moCloseConfirm) -- never
// jQuery UI dialog() and never a native confirm(). The officers card
// above is always rendered on this page (mo_can_manage is hardcoded
// true), so those functions are always defined by the time this runs.
(function () {
	var link = document.getElementById('ka-reset-waivers-link');
	if (!link) { return; }
	link.addEventListener('click', function (e) {
		e.preventDefault();
		var targetUrl = link.getAttribute('href');
		if (typeof window.moShowConfirm !== 'function') { return; }
		window.moShowConfirm(
			'Reset Waivers',
			'This will reset all waivers for the park. This action cannot be undone. Continue?',
			'Reset Waivers',
			function () {
				window.moCloseConfirm();
				window.location.href = targetUrl;
			}
		);
	});
})();
</script>
