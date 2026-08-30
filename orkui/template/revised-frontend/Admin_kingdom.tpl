<?php
/* -----------------------------------------------
   Pre-process template data
   ----------------------------------------------- */
$kid          = (int)($kingdom_id ?? 0);
$kingdomName  = htmlspecialchars($KingdomInfo['KingdomName'] ?? $kingdom_name ?? '');
$entityLabel  = !empty($IsPrinz) ? 'Principality' : 'Kingdom';
$uir          = UIR;

// Heraldry
$hasHeraldry = !empty($kingdom_info['Info']['KingdomInfo']['HasHeraldry']);
$heraldryUrl = $hasHeraldry
	? ($kingdom_info['HeraldryUrl']['Url'] ?? (HTTP_KINGDOM_HERALDRY . '0000.jpg'))
	: HTTP_KINGDOM_HERALDRY . '0000.jpg';

// Work queue for the stat row. This used to render $TrendStats, which is only
// ever assigned by Admin::index() -- so on this page all four cards showed 0 with
// no delta. Kingdom::GetAdminDashboard() supplies kingdom-scoped counts instead,
// and each one is a set an officer can actually work rather than a year-to-date
// total they can only look at.
$_q = is_array($AdminDashboard['Queue'] ?? null) ? $AdminDashboard['Queue'] : [];
// Thresholds come back from the domain alongside the counts so the card copy
// cannot drift away from the SQL that produced them.
$_qw               = is_array($AdminDashboard['Windows'] ?? null) ? $AdminDashboard['Windows'] : [];
$_quietParkDays    = (int)($_qw['QuietParkDays'] ?? 0);
$_crownOffices     = is_array($_qw['CrownOffices'] ?? null) ? $_qw['CrownOffices'] : [];
$_crownOfficeCount = (int)($_qw['CrownOfficeCount'] ?? count($_crownOffices));

// Parks count. Always set by Admin::load_kingdom_admin_data(); no
// count($park_summary) fallback, because that summary is parent-only while
// ActiveParkCount is stat-scoped, so the two branches of a ?? would silently
// mean different things on a parent kingdom with principalities folded in.
$activeParkCount = (int)($ActiveParkCount ?? 0);

// Manage Officers host modal + card gate (partial: revised-frontend/partials/_manage_officers.tpl)
$mo_kingdom_id = (int)($kingdom_id ?? $kid ?? 0);
$mo_can_manage = !empty($can_manage_officer_positions);
$activePlayers   = $ActivePlayers ?? 0;
$totalAttendance = $TotalAttendance ?? 0;
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<!-- =============================================
     KA STYLES (ka- prefix)
     ============================================= -->
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/admin-console.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/admin-console.css') ?>">


<!-- =============================================
     HERO
     ============================================= -->
<div class="ka-hero">
	<div class="ka-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="ka-hero-content">

		<div class="ka-heraldry-frame">
			<img src="<?= htmlspecialchars($heraldryUrl) ?>" alt="<?= $kingdomName ?>">
		</div>

		<div class="ka-hero-info">
			<a href="<?= $uir ?>Kingdom/profile/<?= $kid ?>" class="ka-hero-back"><i class="fas fa-arrow-left"></i> Back to <?= $entityLabel ?></a>
			<h1 class="ka-hero-title"><a href="<?= $uir ?>Kingdom/profile/<?= $kid ?>"><?= $kingdomName ?></a></h1>
			<div class="ka-hero-sub"><?= $entityLabel ?> Administration</div>
			<div class="ka-hero-badges">
				<span class="ka-hero-badge"><i class="fas fa-shield-alt"></i> <?= $entityLabel ?></span>
				<?php if (!empty($IsOrkAdmin)): ?>
				<span class="ka-hero-badge"><i class="fas fa-star"></i> ORK Admin</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="ka-hero-stats">
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format($activeParkCount) ?></span>
				<span class="ka-hero-stat-lbl">Parks</span>
			</div>
			<div class="ka-hero-stat-div"></div>
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format($activePlayers) ?></span>
				<span class="ka-hero-stat-lbl">Players &middot; 26 wk</span>
			</div>
			<div class="ka-hero-stat-div"></div>
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format($totalAttendance) ?></span>
				<span class="ka-hero-stat-lbl">Attendance &middot; YTD</span>
			</div>
		</div>

	</div>
</div>

<!-- =============================================
     WORK QUEUE
     ============================================= -->
<?php
/* _ka_queue() -- the one queue card, shared with the Park console. It used to be
   defined inline here; the body is unchanged, it just lives in the partial now so
   there is only ever one of it. */
include __DIR__ . '/partials/_ka_queue.tpl';
?>
<div class="ka-ts-row">
<?php
_ka_queue(
	$_q['OpenRecommendations'] ?? 0,
	'fa-star',
	'Recommendations waiting',
	'Not yet granted, snoozed or passed to a park',
	'No recommendations waiting',
	$uir . 'Reports/player_award_recommendations&KingdomId=' . $kid
);
_ka_queue(
	$_q['UnwaiveredActive'] ?? 0,
	'fa-file-signature',
	'Players with no waiver',
	'Active players with no waiver on file',
	'Every active player is waivered',
	$uir . 'Reports/unwaivered/Kingdom&id=' . $kid
);
_ka_queue(
	$_q['QuietParks'] ?? 0,
	'fa-map-marker-alt',
	'Parks with no attendance',
	'Nothing recorded in ' . $_quietParkDays . ' days',
	'Every park has recorded attendance',
	$uir . 'Reports/park_attendance_explorer&KingdomId=' . $kid
);
_ka_queue(
	$_q['VacantCrownOffices'] ?? 0,
	'fa-crown',
	'Crown offices vacant',
	implode(', ', $_crownOffices),
	'All ' . $_crownOfficeCount . ' crown offices filled',
	'#',
	$mo_can_manage ? 'kaOpenManageOfficers()' : ''
);
?>
</div>

<!-- =============================================
     MAIN LAYOUT
     ============================================= -->
<div class="ka-layout">

	<!-- LEFT COLUMN — Actions -->
	<div class="ka-main">
		<!-- Sections run most-used first. Icon colour is one hue per section so it
		     encodes grouping instead of varying tile to tile, and red is reserved
		     for the two actions that cannot be walked back. -->
		<div class="ka-sections-grid">

			<!-- Players & Awards -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-user"></i> Players &amp; Awards</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-createplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-user-plus"></i></div>
						<div class="ka-action-label">Create Player</div>
						<div class="ka-action-desc">Register a new player account</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-awards-overlay')">
						<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-medal"></i></div>
						<div class="ka-action-label">Manage Awards</div>
						<div class="ka-action-desc">Award aliases and <?= strtolower($entityLabel) ?>-specific awards</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-moveplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-gold"><i class="fas fa-exchange-alt"></i></div>
						<div class="ka-action-label">Move Player</div>
						<div class="ka-action-desc">Transfer a player to a different park</div>
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
<?php if ($mo_can_manage): ?>
					<button class="ka-action-card" onclick="kaOpenManageOfficers()">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-users-cog"></i></div>
						<div class="ka-action-label">Officers</div>
						<div class="ka-action-desc">Who holds each office, terms and aliases</div>
					</button>
<?php endif; ?>
					<a class="ka-action-card" href="<?= $uir ?>Admin/roles/Kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-key"></i></div>
						<div class="ka-action-label">Roles &amp; Assignments</div>
						<div class="ka-action-desc">Roles in this <?= strtolower($entityLabel) ?> and who holds them</div>
					</a>
					<a class="ka-action-card" href="<?= $uir ?>Admin/permissions/Kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-shield-alt"></i></div>
						<div class="ka-action-label">Officer Permissions</div>
						<div class="ka-action-desc">Per-officer access for this <?= strtolower($entityLabel) ?></div>
					</a>
					<a class="ka-action-card" href="<?= $uir ?>Admin/permissionsgrid/Kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-th"></i></div>
						<div class="ka-action-label">Permission Reference</div>
						<div class="ka-action-desc">What each role is able to do</div>
					</a>
				</div>
			</div>

			<!-- Parks & Titles -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-map-marker-alt"></i> Parks &amp; Titles</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-editparks-overlay')">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-map-marker-alt"></i></div>
						<div class="ka-action-label">Edit Parks</div>
						<div class="ka-action-desc">Names, titles, abbreviations, active status</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-parktitles-overlay')">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-flag"></i></div>
						<div class="ka-action-label">Park Titles</div>
						<div class="ka-action-desc">Manage park type titles and requirements</div>
					</button>
					<?php if (!empty($CanAddPark)): ?>
					<a class="ka-action-card" href="<?= $uir ?>Admin/createpark/kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-plus-circle"></i></div>
						<div class="ka-action-label">Create Park</div>
						<div class="ka-action-desc">Add a new park to this <?= strtolower($entityLabel) ?></div>
					</a>
					<?php endif; ?>
					<button class="ka-action-card" onclick="kaOpenModal('ka-claimpark-overlay')">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-hand-holding"></i></div>
						<div class="ka-action-label">Claim Park</div>
						<div class="ka-action-desc">Request a park transfer to this <?= strtolower($entityLabel) ?></div>
					</button>
				</div>
			</div>

			<!-- Qualification Tests -->
			<?php if (!empty($CanManageTests)): ?>
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-clipboard-check"></i> Qualification Tests</div>
				<div class="ka-action-tiles">
					<?php if (!empty($CanConfigTests) || !empty($CanPublishTests)): ?>
					<a class="ka-action-card" href="<?= $uir ?>QualTest/manage/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-clipboard-check"></i></div>
						<div class="ka-action-label">Test Workspace</div>
						<div class="ka-action-desc">Pass criteria, versions, managers and publishing</div>
					</a>
					<?php endif; ?>
					<?php if (!empty($CanEditTestQuestions)): ?>
					<a class="ka-action-card" href="<?= $uir ?>QualTest/questions/<?= $kid ?>/reeve">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-gavel"></i></div>
						<div class="ka-action-label">Reeve's Test Questions</div>
						<div class="ka-action-desc">Author and edit the Reeve's question bank</div>
					</a>
					<a class="ka-action-card" href="<?= $uir ?>QualTest/questions/<?= $kid ?>/corpora">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-scroll"></i></div>
						<div class="ka-action-label">Corpora Test Questions</div>
						<div class="ka-action-desc">Author and edit the Corpora question bank</div>
					</a>
					<?php endif; ?>
					<?php if (!empty($CanViewTestResults) && !empty($QualTestReeveEnabled)): ?>
					<a class="ka-action-card" href="<?= $uir ?>Reports/reeve_test_results/Kingdom&id=<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-chart-bar"></i></div>
						<div class="ka-action-label">Reeve's Test Results</div>
						<div class="ka-action-desc">Who has passed, and how the Reeve's questions performed</div>
					</a>
					<?php endif; ?>
					<?php if (!empty($CanViewTestResults) && !empty($QualTestCorporaEnabled)): ?>
					<a class="ka-action-card" href="<?= $uir ?>Reports/corpora_test_results/Kingdom&id=<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-chart-bar"></i></div>
						<div class="ka-action-label">Corpora Test Results</div>
						<div class="ka-action-desc">Who has passed, and how the Corpora questions performed</div>
					</a>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- Kingdom Settings -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-crown"></i> <?= $entityLabel ?> Settings</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-details-overlay')">
						<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-edit"></i></div>
						<div class="ka-action-label">Edit Details</div>
						<div class="ka-action-desc">Name, abbreviation, description, URL</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-config-overlay')">
						<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-sliders-h"></i></div>
						<div class="ka-action-label">Configuration</div>
						<div class="ka-action-desc">Recommendation visibility &amp; settings</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-heraldry-overlay')">
						<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-image"></i></div>
						<div class="ka-action-label">Heraldry</div>
						<div class="ka-action-desc">Upload or change <?= strtolower($entityLabel) ?> heraldry</div>
					</button>
				</div>
			</div>

			<!-- Operations -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-tools"></i> Operations</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-ops-overlay')">
						<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-undo"></i></div>
						<div class="ka-action-label">Reset Waivers &amp; Status</div>
						<div class="ka-action-desc">Clear waivers or active flags <?= strtolower($entityLabel) ?>-wide</div>
					</button>
					<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
					<button class="ka-action-card" onclick="kaOpenModal('ka-prinz-overlay')">
						<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-crown"></i></div>
						<div class="ka-action-label">Principality Status</div>
						<div class="ka-action-desc">Change sponsor or promote to kingdom</div>
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
			<!-- Twenty links in a 300px column, and Reports_index.tpl is a one-line stub,
			     so this list is the de-facto reports hub. Give it a way to search. -->
			<div class="ka-report-filter-wrap">
				<input type="text" id="ka-report-filter" class="ka-report-filter"
					aria-label="Filter reports" placeholder="Filter reports&hellip;" autocomplete="off">
			</div>
			<div class="ka-report-empty" id="ka-report-empty" hidden>No report matches that.</div>
			<h6 class="ka-report-section-lbl">Activity</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/active/Kingdom&id=<?= $kid ?>"><i class="fas fa-users"></i><span>Active Players<span class="ka-report-list-desc">Players active in the last 6 months</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/park_attendance_explorer"><i class="fas fa-chart-line"></i><span>Park Attendance Explorer<span class="ka-report-list-desc">Interactive attendance analysis</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Peerage</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/knights/Kingdom&id=<?= $kid ?>"><i class="fas fa-chess-king"></i><span>Active Knights<span class="ka-report-list-desc">Currently active knights</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/masters/Kingdom&id=<?= $kid ?>"><i class="fas fa-graduation-cap"></i><span>Active Masters<span class="ka-report-list-desc">Currently active masters</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/knights_list/Kingdom&id=<?= $kid ?>"><i class="fas fa-list"></i><span>Knights List<span class="ka-report-list-desc">All knights past and present</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/masters_list/Kingdom&id=<?= $kid ?>"><i class="fas fa-list-alt"></i><span>Masters List<span class="ka-report-list-desc">All masters past and present</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/knights_and_masters/Kingdom&id=<?= $kid ?>"><i class="fas fa-crown"></i><span>Knights &amp; Masters<span class="ka-report-list-desc">Combined directory</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/class_masters/Kingdom&id=<?= $kid ?>"><i class="fas fa-hat-wizard"></i><span>Class Masters / Paragons<span class="ka-report-list-desc">Top players by class level</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Awards</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/player_awards/Kingdom&id=<?= $kid ?>"><i class="fas fa-medal"></i><span>Kingdom Awards<span class="ka-report-list-desc">All player awards in kingdom</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/custom_awards/Kingdom&id=<?= $kid ?>"><i class="fas fa-award"></i><span>Custom Awards<span class="ka-report-list-desc">Kingdom-specific awards</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kid ?>"><i class="fas fa-star"></i><span>Award Recommendations<span class="ka-report-list-desc">Submitted recommendations</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/guilds/Kingdom&id=<?= $kid ?>"><i class="fas fa-users-cog"></i><span>Kingdom Guilds<span class="ka-report-list-desc">Guilds registered in kingdom</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Roster</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/roster/Kingdom&id=<?= $kid ?>"><i class="fas fa-address-book"></i><span>Full Roster<span class="ka-report-list-desc">All registered players</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/waivered/Kingdom&id=<?= $kid ?>"><i class="fas fa-file-signature"></i><span>Waivered Players<span class="ka-report-list-desc">Players with active waivers</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/unwaivered/Kingdom&id=<?= $kid ?>"><i class="fas fa-file"></i><span>Unwaivered Players<span class="ka-report-list-desc">Players without waivers</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/inactive/Kingdom&id=<?= $kid ?>"><i class="fas fa-user-slash"></i><span>Inactive Players<span class="ka-report-list-desc">Players not active recently</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kid ?>"><i class="fas fa-user-clock"></i><span>Suspended Players<span class="ka-report-list-desc">Active and past suspensions</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Dues &amp; Compliance</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/dues/Kingdom&id=<?= $kid ?>"><i class="fas fa-dollar-sign"></i><span>Dues Paid<span class="ka-report-list-desc">Players with dues on record</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/reeve/Kingdom&id=<?= $kid ?>"><i class="fas fa-gavel"></i><span>Reeve Qualified<span class="ka-report-list-desc">Players qualified to reeve</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/corpora/Kingdom&id=<?= $kid ?>"><i class="fas fa-scroll"></i><span>Corpora Qualified<span class="ka-report-list-desc">Players meeting corpora requirements</span></span></a></li>
			</ul>
		</div>

		<div class="ka-card">
			<div class="ka-card-header">
				<div class="ka-card-title"><i class="fas fa-link"></i> Quick Links</div>
			</div>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Kingdom/profile/<?= $kid ?>"><i class="fas fa-arrow-left"></i><span>Back to Kingdom Profile<span class="ka-report-list-desc"><?= $kingdomName ?></span></span></a></li>
				<li><a href="<?= UIR ?>Attendance/kingdom/<?= $kid ?>"><i class="fas fa-clipboard-list"></i><span>Enter Attendance<span class="ka-report-list-desc">Record kingdom attendance</span></span></a></li>
			</ul>
		</div>

	</div><!-- /.ka-sidebar -->

</div><!-- /.ka-layout -->

<script>
// Reports filter. Hides non-matching links and any group heading left empty;
// clearing the box restores everything.
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

<?php include __DIR__ . '/partials/_kingdom_admin_modals.tpl'; ?>
