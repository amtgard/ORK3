<?php
/* =====================================================================
   Park admin console.
   ---------------------------------------------------------------------
   The park mirror of Admin_kingdom.tpl: same hero, same work queue, same
   two-column layout, same modal engine (partials/_ka_modal_core.tpl) --
   with the actions a PARK actually has. It reads $ParkAdminDashboard
   (Park::GetAdminDashboard, via Admin::park()), which is the park-scoped
   counterpart of the kingdom console's $AdminDashboard. Nothing on this
   page reads $AdminDashboard; that array is kingdom-scoped and is not set
   on this route.

   Every action here is a modal over an endpoint that already exists --
   ParkAjax/park/{id}/... -- so the officer never leaves the console.
   ----------------------------------------------------------------- */
$parkName  = htmlspecialchars($ParkInfo['ParkName'] ?? '');
$parkId    = (int)($ParkInfo['ParkId'] ?? 0);
$kingdomId = (int)($ParkInfo['KingdomId'] ?? 0);
$uir       = UIR;

/* Detail fields, park days and the heraldry URL. Admin::park() loads all three
   from Park::GetParkDetails / GetParkDays / GetHeraldryUrl -- the same source
   the park profile reads, so the console cannot show a different address than
   the page it administers. */
$pkDetails = is_array($ParkDetails ?? null) ? $ParkDetails : [];
$pkDays    = is_array($ParkDays ?? null) ? $ParkDays : [];

/* Heraldry. HasHeraldry is the authoritative flag; the URL is only meaningful
   when it is set, so the generic shield stands in otherwise -- and the alt text
   below says which of the two is on screen. */
$hasHeraldry = !empty($ParkInfo['HasHeraldry']);
$heraldryUrl = ($hasHeraldry && !empty($ParkHeraldry['Url']))
	? $ParkHeraldry['Url']
	: HERALDRY_PARK_DEFAULT;

$parkTitle    = trim((string)($pkDetails['ParkTitle'] ?? ''));
$parkIsActive = (trim((string)($ParkInfo['Active'] ?? 'Active')) === 'Active');

/* ---- Dashboard ---------------------------------------------------------
   Read defensively: Admin::park() always sets the full shape, but a template
   that hard-indexes a missing key turns a data problem into a blank page. */
$_dash = is_array($ParkAdminDashboard ?? null) ? $ParkAdminDashboard : [];
$_st   = is_array($_dash['Standing'] ?? null) ? $_dash['Standing'] : [];
$_q    = is_array($_dash['Queue']    ?? null) ? $_dash['Queue']    : [];
$_w    = is_array($_dash['Windows']  ?? null) ? $_dash['Windows']  : [];

/* QuietDays is int|null, and the two are DIFFERENT STATEMENTS. NULL means this
   park has no attendance rows at all -- not "0 days since the last signin",
   which would claim somebody signed in today. It gets its own card state. */
$_quietDays      = array_key_exists('QuietDays', $_q) ? $_q['QuietDays'] : null;
$_quietThreshold = (int)($_w['QuietThreshold'] ?? 60);

/* Vacancies come from VacantOfficeNames ONLY. Never OfficeCount - VacantOffices:
   a hide_when_vacant position counts toward OfficeCount but never appears as a
   vacancy, so that subtraction is not "filled offices" and the clear-state copy
   below carries no filled count at all. */
$_vacantNames = is_array($_w['VacantOfficeNames'] ?? null) ? $_w['VacantOfficeNames'] : [];
$_vacantCount = (int)($_q['VacantOffices'] ?? 0);

/* Manage Officers host modal + card gate (partial: partials/_manage_officers.tpl,
   hosted from partials/_park_admin_modals.tpl exactly as the kingdom console
   hosts it). $mo_can_manage is true because Admin::park()'s own front-door check
   -- park.details.edit @AUTH_EDIT, or kingdom standing -- has already gated the
   whole page; nobody without standing over this park reaches this template. */
$mo_kingdom_id = $kingdomId;
$mo_park_id    = $parkId;
$mo_can_manage = true;
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
	<div class="ka-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="ka-hero-content">

		<div class="ka-heraldry-frame">
			<img src="<?= htmlspecialchars($heraldryUrl) ?>"
				alt="<?= $hasHeraldry ? htmlspecialchars($parkName) . ' heraldry' : 'Generic placeholder shield &mdash; no heraldry uploaded' ?>">
		</div>

		<div class="ka-hero-info">
			<a href="<?= $uir ?>Park/profile/<?= $parkId ?>" class="ka-hero-back"><i class="fas fa-arrow-left"></i> Back to Park</a>
			<h1 class="ka-hero-title"><a href="<?= $uir ?>Park/profile/<?= $parkId ?>"><?= $parkName ?></a></h1>
			<div class="ka-hero-sub">Park Administration</div>
			<div class="ka-hero-badges">
				<span class="ka-hero-badge"><i class="fas fa-map-marker-alt"></i> <?= $parkTitle !== '' ? htmlspecialchars($parkTitle) : 'Park' ?></span>
				<?php if (!$parkIsActive): ?>
				<span class="ka-hero-badge"><i class="fas fa-ban"></i> Inactive</span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Standing.
		     "Member records", NOT "Members": this counts every ork_mundane row
		     carrying this park_id, while the park profile's roster filters to
		     active + unsuspended. Both numbers are right and they do not match --
		     labelling this one "Members" invites an officer to compare the two and
		     conclude one of the pages is broken. -->
		<div class="ka-hero-stats">
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format((int)($_st['Members'] ?? 0)) ?></span>
				<span class="ka-hero-stat-lbl">Member records</span>
			</div>
			<div class="ka-hero-stat-div"></div>
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format((int)($_st['ActivePlayers'] ?? 0)) ?></span>
				<span class="ka-hero-stat-lbl">Active &middot; 26 wk</span>
			</div>
			<div class="ka-hero-stat-div"></div>
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format((int)($_st['AttendanceYtd'] ?? 0)) ?></span>
				<span class="ka-hero-stat-lbl">Attendance &middot; YTD</span>
			</div>
		</div>

	</div>
</div>

<!-- =============================================
     WORK QUEUE
     ============================================= -->
<?php include __DIR__ . '/partials/_ka_queue.tpl'; ?>
<div class="ka-ts-row">
<?php
_ka_queue(
	$_q['OpenRecommendations'] ?? 0,
	'fa-star',
	'Recommendations waiting',
	'Not yet granted, snoozed or passed on',
	'No recommendations waiting',
	$uir . 'Reports/player_award_recommendations&ParkId=' . $parkId
);
_ka_queue(
	$_q['UnwaiveredActive'] ?? 0,
	'fa-file-signature',
	'Players with no waiver',
	'Active players with no waiver on file',
	'Every active player is waivered',
	$uir . 'Reports/unwaivered/Park&id=' . $parkId
);
/* Attendance currency. Three states, not two:
     NULL          -> nothing has EVER been signed in here.
     <= threshold  -> current; the card goes quiet.
     >  threshold  -> the day count IS the number worth showing. */
_ka_queue(
	($_quietDays !== null && (int)$_quietDays > $_quietThreshold) ? (int)$_quietDays : 0,
	'fa-clipboard-list',
	'Days since the last signin',
	'Nothing recorded for over ' . $_quietThreshold . ' days',
	'Attendance is up to date',
	$uir . 'Attendance/park/' . $parkId,
	'',
	$_quietDays === null ? 'No signins on record' : ''
);
/* Vacancies. The clear line deliberately quotes NO total: hide_when_vacant
   offices are inside OfficeCount but never inside VacantOfficeNames, so
   "all N offices filled" would be a number this data cannot support. */
_ka_queue(
	$_vacantCount,
	'fa-user-shield',
	'Park offices vacant',
	implode(', ', $_vacantNames),
	'No park offices need filling',
	'#',
	'kaOpenManageOfficers()'
);
?>
</div>

<!-- =============================================
     MAIN LAYOUT
     ============================================= -->
<div class="ka-layout">

	<!-- LEFT COLUMN — Actions -->
	<div class="ka-main">
		<!-- One icon hue per section, so colour encodes the grouping rather than
		     varying tile to tile; red is reserved for what cannot be walked back. -->
		<div class="ka-sections-grid">

			<!-- Players -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-user"></i> Players</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-createplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-user-plus"></i></div>
						<div class="ka-action-label">Create Player</div>
						<div class="ka-action-desc">Register a new player at this park</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-moveplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-exchange-alt"></i></div>
						<div class="ka-action-label">Move Player</div>
						<div class="ka-action-desc">Bring a player to this park as their home</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-mergeplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-compress-arrows-alt"></i></div>
						<div class="ka-action-label">Merge Players</div>
						<div class="ka-action-desc">Combine two duplicate records &mdash; permanent</div>
					</button>
				</div>
			</div>

			<!-- Officers & Access -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-user-shield"></i> Officers &amp; Access</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenManageOfficers()">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-users-cog"></i></div>
						<div class="ka-action-label">Officers</div>
						<div class="ka-action-desc">Who holds each office, terms and aliases</div>
					</button>
					<a class="ka-action-card" href="<?= $uir ?>Admin/permissions/Park/<?= $parkId ?>">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-shield-alt"></i></div>
						<div class="ka-action-label">Officer Permissions</div>
						<div class="ka-action-desc">Per-officer access for this park</div>
					</a>
					<a class="ka-action-card" href="<?= $uir ?>Admin/permissionsgrid/Park/<?= $parkId ?>">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-th"></i></div>
						<div class="ka-action-label">Permission Reference</div>
						<div class="ka-action-desc">What each park office is able to do</div>
					</a>
				</div>
			</div>

			<!-- Park Settings -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-map-marker-alt"></i> Park Settings</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-details-overlay')">
						<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-edit"></i></div>
						<div class="ka-action-label">Edit Park Details</div>
						<div class="ka-action-desc">Address, map, website, description and directions</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-parkdays-overlay')">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-calendar-week"></i></div>
						<div class="ka-action-label">Park Days</div>
						<div class="ka-action-desc">When this park meets, and where<?= count($pkDays) > 0 ? ' &mdash; ' . count($pkDays) . ' scheduled' : '' ?></div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-heraldry-overlay')">
						<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-image"></i></div>
						<div class="ka-action-label">Heraldry</div>
						<div class="ka-action-desc">Upload or change this park&rsquo;s device</div>
					</button>
				</div>
			</div>

			<!-- Operations -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-tools"></i> Operations</div>
				<div class="ka-action-tiles">
					<a class="ka-action-card" href="<?= $uir ?>Attendance/park/<?= $parkId ?>">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-clipboard-list"></i></div>
						<div class="ka-action-label">Enter Attendance</div>
						<div class="ka-action-desc">Sign players in for a park day</div>
					</a>
<?php /* Opens the park profile's create-event modal, shared through
         partials/_event_create_modal.tpl (hosted from the modal partial at the
         foot of this page). This was the last tile on the console that left the
         page -- a plain link to the legacy full-page Admin/createevent form. */ ?>
					<button class="ka-action-card" onclick="pkOpenEventModal()">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-calendar-plus"></i></div>
						<div class="ka-action-label">Schedule an Event</div>
						<div class="ka-action-desc">Add an event or a calendar item for this park</div>
					</button>
					<?php /* DEPRECATED, not removed. Tournament::create() still exists and still
					   works, so the tile stays reachable -- taking a live capability away from
					   park officers before its replacement ships would be worse than leaving a
					   discouraged one visible. It is de-emphasised instead: grey surface, drained
					   icon, a "Deprecated" chip, and a data-tip saying what is coming. Drop the
					   whole tile when the new tournament creator lands. */ ?>
					<a class="ka-action-card ka-action-card-deprecated"
						href="<?= $uir ?>Tournament/create&amp;ParkId=<?= $parkId ?>"
						data-tip="The current tournament creator is deprecated. A replacement is in development; this still works in the meantime.">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-trophy"></i></div>
						<div class="ka-action-label">Create Tournament<span class="ka-dep-chip">Deprecated</span></div>
						<div class="ka-action-desc">Being replaced &mdash; the current creator still works for now</div>
					</a>
<?php if (!empty($CanResetWaivers)) : ?>
					<button class="ka-action-card" onclick="kaOpenModal('ka-ops-overlay')">
						<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-undo"></i></div>
						<div class="ka-action-label">Reset Waivers</div>
						<div class="ka-action-desc">Clear every waiver flag in this park</div>
					</button>
<?php endif; ?>
				</div>
			</div>

		</div>
	</div><!-- /.ka-main -->

	<!-- RIGHT COLUMN — Reports & Links -->
	<div class="ka-sidebar">

		<div class="ka-card" id="ka-reports-card">
			<div class="ka-card-header">
				<div class="ka-card-title"><i class="fas fa-chart-bar"></i> Reports</div>
			</div>
			<!-- Fourteen links in a 300px column. Same filter box, same behaviour and
			     the same script as the kingdom console's -- an officer who learned it
			     there does not have to learn it again here. -->
			<div class="ka-report-filter-wrap">
				<input type="text" id="ka-report-filter" class="ka-report-filter"
					aria-label="Filter reports" placeholder="Filter reports&hellip;" autocomplete="off">
			</div>
			<div class="ka-report-empty" id="ka-report-empty" hidden>No report matches that.</div>
			<h6 class="ka-report-section-lbl">Activity</h6>
			<ul class="ka-report-list">
				<li><a href="<?= $uir ?>Reports/active/Park&amp;id=<?= $parkId ?>"><i class="fas fa-users"></i><span>Active Players<span class="ka-report-list-desc">Players active in the last 6 months</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/attendance/Park&amp;id=<?= $parkId ?>"><i class="fas fa-clipboard-list"></i><span>Attendance<span class="ka-report-list-desc">Signins recorded at this park</span></span></a></li>
				<!-- The one park report that takes its id as a PATH segment rather than
				     &id= -- Reports::event_attendance() explodes $params on '/'. Linked
				     the &id= way it silently redirects home. -->
				<li><a href="<?= $uir ?>Reports/event_attendance/Park/<?= $parkId ?>"><i class="fas fa-calendar-check"></i><span>Event Attendance<span class="ka-report-list-desc">Signins recorded at events</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Roster</h6>
			<ul class="ka-report-list">
				<li><a href="<?= $uir ?>Reports/roster/Park&amp;id=<?= $parkId ?>"><i class="fas fa-address-book"></i><span>Full Roster<span class="ka-report-list-desc">Every player registered here</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/inactive/Park&amp;id=<?= $parkId ?>"><i class="fas fa-user-slash"></i><span>Inactive Players<span class="ka-report-list-desc">Players not active recently</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/suspended/Park&amp;id=<?= $parkId ?>"><i class="fas fa-user-clock"></i><span>Suspended Players<span class="ka-report-list-desc">Active and past suspensions</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/player_status_reconciliation/Park&amp;id=<?= $parkId ?>"><i class="fas fa-balance-scale"></i><span>Status Reconciliation<span class="ka-report-list-desc">Where active, waiver and dues disagree</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Waivers &amp; Dues</h6>
			<ul class="ka-report-list">
				<li><a href="<?= $uir ?>Reports/waivered/Park&amp;id=<?= $parkId ?>"><i class="fas fa-file-signature"></i><span>Waivered Players<span class="ka-report-list-desc">Players with a waiver on file</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/unwaivered/Park&amp;id=<?= $parkId ?>"><i class="fas fa-file"></i><span>Unwaivered Players<span class="ka-report-list-desc">Players with no waiver on file</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/dues/Park&amp;id=<?= $parkId ?>"><i class="fas fa-dollar-sign"></i><span>Dues<span class="ka-report-list-desc">Dues records on file</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/duespaid/Park&amp;id=<?= $parkId ?>"><i class="fas fa-receipt"></i><span>Dues Paid<span class="ka-report-list-desc">Players with dues paid</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/active_duespaid/Park&amp;id=<?= $parkId ?>"><i class="fas fa-user-check"></i><span>Active &amp; Dues Paid<span class="ka-report-list-desc">Active players with dues paid</span></span></a></li>
				<li><a href="<?= $uir ?>Reports/active_waivered_duespaid/Park&amp;id=<?= $parkId ?>"><i class="fas fa-clipboard-check"></i><span>Active, Waivered &amp; Paid<span class="ka-report-list-desc">All three on file at once</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Governance</h6>
			<ul class="ka-report-list">
				<li><a href="<?= $uir ?>Reports/voting_eligible/Park&amp;id=<?= $parkId ?>"><i class="fas fa-vote-yea"></i><span>Voting Eligible<span class="ka-report-list-desc">Players eligible to vote here</span></span></a></li>
			</ul>
		</div>

		<div class="ka-card">
			<div class="ka-card-header">
				<div class="ka-card-title"><i class="fas fa-link"></i> Quick Links</div>
			</div>
			<ul class="ka-report-list">
				<li><a href="<?= $uir ?>Park/profile/<?= $parkId ?>"><i class="fas fa-arrow-left"></i><span>Back to Park Profile<span class="ka-report-list-desc"><?= $parkName ?></span></span></a></li>
				<li><a href="<?= $uir ?>Attendance/park/<?= $parkId ?>"><i class="fas fa-clipboard-list"></i><span>Enter Attendance<span class="ka-report-list-desc">Record a park day&rsquo;s signins</span></span></a></li>
				<li><a href="<?= $uir ?>Kingdom/profile/<?= $kingdomId ?>"><i class="fas fa-shield-alt"></i><span>Parent Kingdom<span class="ka-report-list-desc">This park&rsquo;s kingdom profile</span></span></a></li>
			</ul>
		</div>

	</div><!-- /.ka-sidebar -->

</div><!-- /.ka-layout -->

<script>
// Reports filter. Hides non-matching links and any group heading left empty;
// clearing the box restores everything. Same behaviour as the kingdom console's.
(function () {
	var box  = document.getElementById('ka-report-filter');
	var card = document.getElementById('ka-reports-card');
	var none = document.getElementById('ka-report-empty');
	if (!box || !card || !none) { return; }

	var groups = [];
	Array.prototype.forEach.call(card.querySelectorAll('.ka-report-section-lbl'), function (h) {
		if (h.nextElementSibling && h.nextElementSibling.classList.contains('ka-report-list')) {
			groups.push({ heading: h, list: h.nextElementSibling });
		}
	});

	box.addEventListener('input', function () {
		var q = box.value.trim().toLowerCase();
		var shown = 0;
		groups.forEach(function (g) {
			var hits = 0;
			Array.prototype.forEach.call(g.list.querySelectorAll('li'), function (li) {
				var match = !q || li.textContent.toLowerCase().indexOf(q) !== -1;
				li.hidden = !match;
				if (match) { hits++; }
			});
			g.heading.hidden = !hits;
			g.list.hidden = !hits;
			shown += hits;
		});
		none.hidden = shown > 0;
	});
})();
</script>

<?php include __DIR__ . '/partials/_park_admin_modals.tpl'; ?>
