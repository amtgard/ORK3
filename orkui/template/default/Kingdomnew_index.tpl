<?php
	/* -----------------------------------------------
	   Pre-process template data
	   ----------------------------------------------- */
	$parkList         = is_array($park_summary['KingdomParkAveragesSummary']) ? $park_summary['KingdomParkAveragesSummary'] : array();
	$eventList        = is_array($event_summary) ? $event_summary : array();
	$tournamentList   = is_array($kingdom_tournaments['Tournaments']) ? $kingdom_tournaments['Tournaments'] : array();
	$principalityList = is_array($principalities['Principalities']) ? $principalities['Principalities'] : array();
	$officerList      = is_array($kingdom_officers['Officers']) ? $kingdom_officers['Officers'] : array();

	// Aggregate attendance across all parks
	$totalAtt = 0; $totalMonthly = 0;
	foreach ($parkList as $p) {
		$totalAtt     += (int)$p['AttendanceCount'];
		$totalMonthly += (int)$p['MonthlyCount'];
	}
	$avgWeek  = round($totalAtt / 26, 1);
	$avgMonth = round($totalMonthly / 12, 1);

	// Heraldry
	$hasHeraldry = $kingdom_info['Info']['KingdomInfo']['HasHeraldry'] == 1;
	$heraldryUrl = $hasHeraldry
		? $kingdom_info['HeraldryUrl']['Url']
		: HTTP_KINGDOM_HERALDRY . '0000.jpg';
	$entityLabel = $IsPrinz ? 'Principality' : 'Kingdom';

	// Extract Monarch & Regent for hero display
	$monarch = null; $regent = null;
	foreach ($officerList as $o) {
		if ($o['OfficerRole'] === 'Monarch') $monarch = $o;
		if ($o['OfficerRole'] === 'Regent')  $regent  = $o;
	}

	// Pre-compute map location data (server-side; embedded as JSON for lazy map init)
	$knMapLocations = [];
	foreach ((array)$map_parks as $p) {
		$loc = @json_decode(stripslashes((string)$p['Location']));
		if (!$loc) continue;
		$latlng = isset($loc->location) ? $loc->location : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
		if (!$latlng || !is_numeric($latlng->lat) || !is_numeric($latlng->lng)) continue;
		$heraldryHtml = $p['HasHeraldry'] ? '<img src="' . HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $p['ParkId'])) . '" style="max-width:60px;display:block;margin-bottom:6px">' : '';
		$knMapLocations[] = [
			'name' => ucwords($p['Name']),
			'lat'  => (float)$latlng->lat,
			'lng'  => (float)$latlng->lng,
			'id'   => (int)$p['ParkId'],
			'info' => $heraldryHtml . '<p>' . nl2br(htmlspecialchars($p['Directions'])) . '</p><h4>Description</h4><p>' . nl2br(htmlspecialchars($p['Description'])) . '</p>',
		];
	}
?>

<style type="text/css">
/* ========================================
   CRM-Style Kingdom Profile
   All classes prefixed with kn- to avoid collisions
   ======================================== */

/* Hero Header */
.kn-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 20px;
	min-height: 160px;
	background-color: #1a4731;
}
.kn-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.14;
	filter: blur(6px);
}
.kn-hero-content {
	position: relative;
	display: flex;
	align-items: center;
	padding: 24px 30px;
	gap: 24px;
	z-index: 1;
}
.kn-heraldry-frame {
	width: 110px;
	height: 110px;
	border-radius: 8px;
	overflow: hidden;
	border: 3px solid rgba(255,255,255,0.8);
	flex-shrink: 0;
	background: rgba(0,0,0,0.15);
	display: flex;
	align-items: center;
	justify-content: center;
}
.kn-heraldry-frame img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.kn-hero-info {
	flex: 1;
	min-width: 0;
}
.kn-kingdom-name {
	color: #fff;
	font-size: 28px;
	margin: 0 0 6px 0;
	font-weight: 700;
	text-shadow: 0 1px 4px rgba(0,0,0,0.4);
	line-height: 1.2;
	background: transparent;
	border: none;
	padding: 0;
}
.kn-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}
.kn-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	line-height: 1.4;
}
.kn-badge-green  { background: #c6f6d5; color: #276749; }
.kn-badge-purple { background: #e9d8fd; color: #553c9a; }
.kn-badge-gold   { background: #fefcbf; color: #744210; }
.kn-badge-blue   { background: #bee3f8; color: #2a4365; }
.kn-badge-gray   { background: #e2e8f0; color: #4a5568; }

.kn-officers-inline {
	color: rgba(255,255,255,0.85);
	font-size: 13px;
	line-height: 1.8;
}
.kn-officers-inline a {
	color: #fff;
	font-weight: 600;
	text-decoration: none;
}
.kn-officers-inline a:hover { text-decoration: underline; }
.kn-officers-sep { margin: 0 10px; opacity: 0.35; }
.kn-vacant { color: rgba(255,255,255,0.45); font-style: italic; }

.kn-hero-actions {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}
.kn-btn {
	display: inline-block;
	padding: 8px 16px;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;
	border: none;
	white-space: nowrap;
	transition: opacity 0.15s;
}
.kn-btn:hover { opacity: 0.85; }
.kn-btn-white   { background: #fff; color: #1a4731; }
.kn-btn-outline { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.4); }
.kn-btn-primary { background: #276749; color: #fff; }

/* Stats Row */
.kn-stats-row {
	display: flex;
	gap: 14px;
	margin-bottom: 20px;
}
.kn-stat-card {
	flex: 1;
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 14px 16px;
	text-align: center;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.kn-stat-icon { font-size: 16px; color: #a0aec0; margin-bottom: 4px; }
.kn-stat-number { font-size: 26px; font-weight: 700; color: #276749; line-height: 1.2; }
.kn-stat-label { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }

/* Two-Column Layout */
.kn-layout {
	display: flex;
	gap: 20px;
	align-items: flex-start;
}
.kn-sidebar { width: 280px; flex-shrink: 0; }
.kn-main    { flex: 1; min-width: 0; }

/* Cards */
.kn-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 16px 18px;
	margin-bottom: 14px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.kn-card h4 {
	margin: 0 0 10px 0;
	font-size: 12px;
	font-weight: 700;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.6px;
	padding: 0 0 8px 0;
	background: transparent;
	border: none;
	border-bottom: 1px solid #edf2f7;
	text-shadow: none;
	border-radius: 0;
}

/* Officer rows in sidebar */
.kn-officer-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 5px 0;
	border-bottom: 1px dotted #f0f0f0;
	font-size: 13px;
}
.kn-officer-row:last-child { border-bottom: none; }
.kn-officer-label { color: #718096; flex-shrink: 0; margin-right: 8px; white-space: nowrap; }
.kn-officer-value { color: #2d3748; font-weight: 500; text-align: right; }
.kn-officer-value a { color: #2b6cb0; text-decoration: none; }
.kn-officer-value a:hover { text-decoration: underline; }
.kn-officer-vacant { color: #a0aec0; font-style: italic; font-weight: 400; }

/* Link list (Quick Links sidebar) */
.kn-link-list { list-style: none; margin: 0; padding: 0; }
.kn-link-list li {
	padding: 5px 0;
	border-bottom: 1px dotted #f0f0f0;
	font-size: 13px;
}
.kn-link-list li:last-child { border-bottom: none; }
.kn-link-list a { color: #2b6cb0; text-decoration: none; }
.kn-link-list a:hover { text-decoration: underline; }
.kn-link-icon { width: 16px; color: #a0aec0; display: inline-block; text-align: center; margin-right: 5px; }

/* Tabs */
.kn-tabs {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	overflow: hidden;
}
.kn-tab-nav {
	list-style: none;
	margin: 0; padding: 0;
	display: flex;
	border-bottom: 2px solid #e2e8f0;
	background: #f7fafc;
	flex-wrap: nowrap;
	overflow-x: auto;
}
.kn-tab-nav li {
	padding: 12px 18px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 600;
	color: #718096;
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s;
	white-space: nowrap;
	flex-shrink: 0;
}
.kn-tab-nav li:hover { color: #276749; background: #edf2f7; }
.kn-tab-nav li.kn-tab-active {
	color: #276749;
	border-bottom-color: #276749;
	background: #fff;
}
.kn-tab-count { color: #a0aec0; font-weight: 400; font-size: 12px; margin-left: 3px; }
.kn-tab-panel { padding: 16px 18px; }

/* Tab Tables */
.kn-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.kn-table thead th {
	text-align: left;
	background: #f7fafc;
	color: #4a5568;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.3px;
	padding: 8px 10px;
	border-bottom: 1px solid #e2e8f0;
	cursor: pointer;
	user-select: none;
	-webkit-user-select: none;
	position: relative;
	padding-right: 20px;
	white-space: nowrap;
}
.kn-table thead th:hover { background: #edf2f7; }
.kn-table thead th.sort-asc::after {
	content: ' \25B2';
	position: absolute; right: 5px;
	color: #276749; font-size: 0.75em;
}
.kn-table thead th.sort-desc::after {
	content: ' \25BC';
	position: absolute; right: 5px;
	color: #276749; font-size: 0.75em;
}
.kn-table tfoot td {
	padding: 7px 10px;
	border-top: 2px solid #e2e8f0;
	background: #f7fafc;
	font-weight: 600;
	font-size: 12px;
	color: #4a5568;
}
.kn-table tfoot .kn-col-numeric { color: #276749; }
.kn-table tbody td {
	padding: 7px 10px;
	border-bottom: 1px solid #f0f4f8;
	color: #4a5568;
	vertical-align: middle;
}
.kn-table tbody tr { cursor: default; }
.kn-table tbody tr.kn-row-link { cursor: pointer; }
.kn-table tbody tr:hover { background: #f7fafc; }
.kn-table tbody tr:last-child td { border-bottom: none; }
.kn-table a { color: #2b6cb0; text-decoration: none; }
.kn-table a:hover { text-decoration: underline; }
.kn-col-numeric { text-align: right; }
.kn-col-nowrap  { white-space: nowrap; }

/* Park / event heraldry thumbnail */
.kn-thumb {
	width: 26px;
	height: 26px;
	border-radius: 3px;
	object-fit: contain;
	vertical-align: middle;
	margin-right: 8px;
	border: 1px solid #e2e8f0;
}

/* Parks tab toolbar (view toggle) */
.kn-parks-toolbar {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 3px;
	margin-bottom: 14px;
}
.kn-view-btn {
	width: 30px;
	height: 30px;
	border: 1px solid #e2e8f0;
	border-radius: 5px;
	background: #fff;
	color: #a0aec0;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 13px;
	transition: background 0.1s, color 0.1s, border-color 0.1s;
	padding: 0;
}
.kn-view-btn:hover { background: #edf2f7; color: #4a5568; border-color: #cbd5e0; }
.kn-view-btn.kn-view-active { background: #276749; color: #fff; border-color: #276749; }

/* Park tile grid */
.kn-park-tiles {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	gap: 14px;
}
.kn-park-tile {
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	overflow: hidden;
	background: #fff;
	cursor: pointer;
	transition: box-shadow 0.15s, transform 0.15s, border-color 0.15s;
	text-decoration: none;
	color: inherit;
	display: block;
}
.kn-park-tile:hover {
	box-shadow: 0 6px 20px rgba(0,0,0,0.10);
	transform: translateY(-2px);
	border-color: #276749;
	text-decoration: none;
}
.kn-park-tile-img-wrap {
	height: 96px;
	background: #f7fafc;
	border-bottom: 1px solid #e2e8f0;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 10px;
	box-sizing: border-box;
}
.kn-park-tile-img-wrap img {
	max-width: 100%;
	max-height: 76px;
	object-fit: contain;
}
.kn-park-tile-body {
	padding: 9px 11px 10px;
}
.kn-park-tile-name {
	font-size: 13px;
	font-weight: 700;
	color: #2d3748;
	margin-bottom: 1px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.kn-park-tile-type {
	font-size: 10px;
	color: #a0aec0;
	text-transform: uppercase;
	letter-spacing: 0.4px;
	margin-bottom: 7px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.kn-park-tile-stats {
	display: flex;
	gap: 6px;
	border-top: 1px solid #edf2f7;
	padding-top: 7px;
	margin-top: 0;
}
.kn-park-tile-stat {
	flex: 1;
	text-align: center;
}
.kn-park-tile-stat-val {
	font-size: 15px;
	font-weight: 700;
	color: #276749;
	line-height: 1.2;
}
.kn-park-tile-stat-lbl {
	font-size: 9px;
	color: #a0aec0;
	text-transform: uppercase;
	letter-spacing: 0.4px;
	margin-top: 1px;
}

/* Empty state */
.kn-empty {
	text-align: center;
	color: #a0aec0;
	padding: 30px 10px;
	font-size: 13px;
	font-style: italic;
}

/* Pagination */
.kn-pagination {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 0 0 0;
	margin-top: 6px;
	border-top: 1px solid #edf2f7;
}
.kn-pagination-info { color: #a0aec0; font-size: 12px; }
.kn-pagination-controls { display: flex; gap: 3px; align-items: center; }
.kn-page-btn {
	min-width: 28px; height: 28px;
	border: 1px solid #e2e8f0; border-radius: 4px;
	background: #fff; color: #4a5568;
	font-size: 12px; font-weight: 600;
	cursor: pointer; padding: 0 6px;
	display: inline-flex; align-items: center; justify-content: center;
	transition: background 0.1s, border-color 0.1s;
	line-height: 1;
}
.kn-page-btn:hover:not(:disabled) { background: #edf2f7; border-color: #cbd5e0; }
.kn-page-btn.kn-page-active { background: #276749; color: #fff; border-color: #276749; }
.kn-page-btn:disabled { opacity: 0.4; cursor: default; }
.kn-page-ellipsis { color: #a0aec0; padding: 0 3px; font-size: 12px; line-height: 28px; }

/* Reports tab — grouped cards */
.kn-reports-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
	gap: 14px;
}
.kn-report-group {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 12px 14px;
}
.kn-report-group h5 {
	margin: 0 0 8px 0;
	font-size: 11px;
	font-weight: 700;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding-bottom: 6px;
	border-bottom: 1px solid #e2e8f0;
	background: transparent;
	border-radius: 0;
	text-shadow: none;
}
.kn-report-group ul { list-style: none; margin: 0; padding: 0; }
.kn-report-group li { padding: 3px 0; }
.kn-report-group a { color: #2b6cb0; text-decoration: none; font-size: 12px; }
.kn-report-group a:hover { text-decoration: underline; }

/* Principality rows */
.kn-prinz-row {
	display: flex;
	align-items: center;
	padding: 9px 0;
	border-bottom: 1px dotted #f0f0f0;
	gap: 12px;
}
.kn-prinz-row:last-child { border-bottom: none; }
.kn-prinz-heraldry {
	width: 36px; height: 36px;
	border-radius: 4px;
	object-fit: contain;
	border: 1px solid #e2e8f0;
	flex-shrink: 0;
}
.kn-prinz-name { font-size: 14px; }
.kn-prinz-name a { color: #2b6cb0; text-decoration: none; font-weight: 600; }
.kn-prinz-name a:hover { text-decoration: underline; }

/* Responsive */
@media (max-width: 768px) {
	.kn-layout { flex-direction: column; }
	.kn-sidebar { width: 100%; }
	.kn-stats-row { flex-wrap: wrap; }
	.kn-stat-card { flex: 1 1 45%; min-width: 0; }
	.kn-reports-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 425px) {
	.kn-hero-content { flex-direction: column; text-align: center; padding: 16px; gap: 12px; }
	.kn-heraldry-frame { width: 80px; height: 80px; }
	.kn-kingdom-name { font-size: 22px; }
	.kn-badges { justify-content: center; }
	.kn-hero-actions { align-items: center; flex-direction: row; flex-wrap: wrap; justify-content: center; }
	.kn-tab-nav { -webkit-overflow-scrolling: touch; }
	.kn-tab-nav li { padding: 10px 12px; font-size: 12px; }
	.kn-tab-panel { padding: 12px 10px; overflow-x: auto; }
	.kn-reports-grid { grid-template-columns: 1fr; }
}

/* Map tab */
.kn-map-layout {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}
.kn-map-wrap { flex: 1; min-width: 0; }
#kn-map {
	width: 100%;
	height: 500px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	overflow: hidden;
}
.kn-map-directions-wrap { width: 230px; flex-shrink: 0; }
.kn-map-directions-wrap .kn-card { margin-bottom: 0; }
#kn-map-directions { font-size: 13px; color: #4a5568; max-height: 460px; overflow-y: auto; }
#kn-map-directions h4 { font-size: 12px; font-weight: 700; color: #4a5568; margin: 10px 0 4px; }
#kn-map-directions img { max-width: 80px; border-radius: 4px; }
.kn-map-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	color: #a0aec0;
	gap: 12px;
	font-size: 13px;
}
@media (max-width: 768px) {
	.kn-map-layout { flex-direction: column; }
	.kn-map-directions-wrap { width: 100%; }
	#kn-map { height: 320px; }
	#kn-map-directions { max-height: 200px; }
}
</style>

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<div class="kn-hero">
	<div class="kn-hero-bg" style="background-image: url('<?= $heraldryUrl ?>')"></div>
	<div class="kn-hero-content">

		<div class="kn-heraldry-frame">
			<img src="<?= $heraldryUrl ?>" alt="<?= htmlspecialchars($kingdom_name) ?>" />
		</div>

		<div class="kn-hero-info">
			<h1 class="kn-kingdom-name"><?= htmlspecialchars($kingdom_name) ?></h1>
			<div class="kn-badges">
				<span class="kn-badge kn-badge-green">
					<i class="fas fa-shield-alt"></i> <?= $entityLabel ?>
				</span>
				<?php if ($IsPrinz): ?>
					<span class="kn-badge kn-badge-purple">
						<i class="fas fa-crown"></i> Principality
					</span>
				<?php endif; ?>
			</div>
			<div class="kn-officers-inline">
				<?php if ($monarch): ?>
					<i class="fas fa-crown" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					Monarch:&nbsp;
					<?php if (!empty($monarch['MundaneId']) && $monarch['MundaneId'] > 0): ?>
						<a href="<?= UIR ?>Player/index/<?= $monarch['MundaneId'] ?>"><?= htmlspecialchars($monarch['Persona']) ?></a>
					<?php else: ?>
						<span class="kn-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ($monarch && $regent): ?>
					<span class="kn-officers-sep">|</span>
				<?php endif; ?>
				<?php if ($regent): ?>
					<i class="fas fa-star" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					Regent:&nbsp;
					<?php if (!empty($regent['MundaneId']) && $regent['MundaneId'] > 0): ?>
						<a href="<?= UIR ?>Player/index/<?= $regent['MundaneId'] ?>"><?= htmlspecialchars($regent['Persona']) ?></a>
					<?php else: ?>
						<span class="kn-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="kn-hero-actions">
			<a class="kn-btn kn-btn-white" href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">
				<i class="fas fa-search"></i> Search Players
			</a>
			<?php if ($LoggedIn): ?>
				<a class="kn-btn kn-btn-outline" href="<?= UIR ?>Award/kingdom/<?= $kingdom_id ?>">
					<i class="fas fa-medal"></i> Enter Awards
				</a>
			<?php endif; ?>
			<a class="kn-btn kn-btn-outline" href="#" onclick="knActivateTab('map');return false;">
				<i class="fas fa-map"></i> Atlas
			</a>
			<?php if ($LoggedIn): ?>
				<a class="kn-btn kn-btn-outline" href="<?= UIR ?>Admin/kingdom/<?= $kingdom_id ?>">
					<i class="fas fa-cog"></i> Admin
				</a>
			<?php endif; ?>
		</div>

	</div>
</div>

<!-- =============================================
     ZONE 2: Dashboard Stats
     ============================================= -->
<div class="kn-stats-row">
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="kn-stat-number"><?= count($parkList) ?></div>
		<div class="kn-stat-label">Parks</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="kn-stat-number"><?= count($eventList) ?></div>
		<div class="kn-stat-label">Events</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-users"></i></div>
		<div class="kn-stat-number"><?= $avgWeek ?></div>
		<div class="kn-stat-label">Avg / Week</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="kn-stat-number"><?= $avgMonth ?></div>
		<div class="kn-stat-label">Avg / Month</div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Main Content
     ============================================= -->
<div class="kn-layout">

	<!-- ========== SIDEBAR ========== -->
	<div class="kn-sidebar">

		<!-- Officers -->
		<div class="kn-card">
			<h4><i class="fas fa-crown"></i> Officers</h4>
			<?php if (count($officerList) > 0): ?>
				<?php foreach ($officerList as $o): ?>
					<div class="kn-officer-row">
						<span class="kn-officer-label"><?= htmlspecialchars($o['OfficerRole']) ?></span>
						<span class="kn-officer-value">
							<?php if (!empty($o['MundaneId']) && $o['MundaneId'] > 0): ?>
								<a href="<?= UIR ?>Player/index/<?= $o['MundaneId'] ?>"><?= htmlspecialchars($o['Persona']) ?></a>
							<?php else: ?>
								<span class="kn-officer-vacant">Vacant</span>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			<?php else: ?>
				<div class="kn-empty">No officers listed</div>
			<?php endif; ?>
		</div>

		<!-- Quick Links -->
		<div class="kn-card">
			<h4><i class="fas fa-link"></i> Quick Links</h4>
			<ul class="kn-link-list">
				<li>
					<span class="kn-link-icon"><i class="fas fa-search"></i></span>
					<a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Search Players</a>
				</li>
				<?php if ($LoggedIn): ?>
					<li>
						<span class="kn-link-icon"><i class="fas fa-medal"></i></span>
						<a href="<?= UIR ?>Award/kingdom/<?= $kingdom_id ?>">Enter Awards</a>
					</li>
				<?php endif; ?>
				<li>
					<span class="kn-link-icon"><i class="fas fa-map-marked-alt"></i></span>
					<a href="#" onclick="knActivateTab('map');return false;">Kingdom Atlas</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-coins"></i></span>
					<a href="<?= UIR ?>Treasury/kingdom/<?= $kingdom_id ?>">Treasury</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-users"></i></span>
					<a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-calendar"></i></span>
					<a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Find Events</a>
				</li>
				<?php if ($LoggedIn): ?>
					<li>
						<span class="kn-link-icon"><i class="fas fa-cog"></i></span>
						<a href="<?= UIR ?>Admin/kingdom/<?= $kingdom_id ?>">Admin Panel</a>
					</li>
				<?php endif; ?>
			</ul>
		</div>

	</div>

	<!-- ========== MAIN CONTENT (Tabbed) ========== -->
	<div class="kn-main">
		<div class="kn-tabs">
			<ul class="kn-tab-nav">
				<li class="kn-tab-active" data-kntab="parks">
					<i class="fas fa-map-marker-alt"></i> Parks
					<span class="kn-tab-count">(<?= count($parkList) ?>)</span>
				</li>
				<li data-kntab="events">
					<i class="fas fa-calendar-alt"></i> Events
					<span class="kn-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-kntab="tournaments">
					<i class="fas fa-trophy"></i> Tournaments
					<span class="kn-tab-count">(<?= count($tournamentList) ?>)</span>
				</li>
				<li data-kntab="map">
					<i class="fas fa-map"></i> Map
				</li>
				<?php if (!$IsPrinz && count($principalityList) > 0): ?>
					<li data-kntab="principalities">
						<i class="fas fa-shield-alt"></i> Principalities
						<span class="kn-tab-count">(<?= count($principalityList) ?>)</span>
					</li>
				<?php endif; ?>
				<li data-kntab="reports">
					<i class="fas fa-chart-bar"></i> Reports
				</li>
			</ul>

			<!-- Parks Tab -->
			<div class="kn-tab-panel" id="kn-tab-parks">
				<?php
					// Pre-sort alphabetically so tiles match default list order
					usort($parkList, function($a, $b) { return strcmp($a['ParkName'], $b['ParkName']); });
				?>
				<?php if (count($parkList) > 0): ?>

					<!-- Toolbar -->
					<div class="kn-parks-toolbar">
						<button class="kn-view-btn" id="kn-view-tiles" title="Tile view">
							<i class="fas fa-th-large"></i>
						</button>
						<button class="kn-view-btn" id="kn-view-list" title="List view">
							<i class="fas fa-list"></i>
						</button>
					</div>

					<!-- Tile view -->
					<div id="kn-parks-tiles" class="kn-park-tiles">
						<?php foreach ($parkList as $park): ?>
							<?php $tileHeraldry = $park['HasHeraldry'] == 1
								? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId']))
								: HTTP_PARK_HERALDRY . '00000.jpg'; ?>
							<a class="kn-park-tile" href="<?= UIR ?>Park/index/<?= $park['ParkId'] ?>">
								<div class="kn-park-tile-img-wrap">
									<img src="<?= $tileHeraldry ?>"
										onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
										alt="<?= htmlspecialchars($park['ParkName']) ?>">
								</div>
								<div class="kn-park-tile-body">
									<div class="kn-park-tile-name"><?= htmlspecialchars($park['ParkName']) ?></div>
									<div class="kn-park-tile-type"><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : 'Park') ?></div>
									<div class="kn-park-tile-stats">
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val"><?= sprintf("%.1f", $park['AttendanceCount'] / 26) ?></div>
											<div class="kn-park-tile-stat-lbl">Avg/Wk</div>
										</div>
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val"><?= (int)$park['AttendanceCount'] ?></div>
											<div class="kn-park-tile-stat-lbl">Total</div>
										</div>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>

					<!-- List view -->
					<div id="kn-parks-list-view" style="display:none">
						<table class="kn-table kn-sortable" id="kn-parks-table">
							<thead>
								<tr>
									<th data-sorttype="text">Park</th>
									<th data-sorttype="text">Type</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average unique sign-ins per week over 26 weeks">Avg/Wk</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average unique sign-ins per month over 12 months">Avg/Mo</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Total unique sign-ins over the last 26 weeks">Total</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($parkList as $park): ?>
									<tr class="kn-row-link" onclick="window.location.href='<?= UIR ?>Park/index/<?= $park['ParkId'] ?>'">
										<td class="kn-col-nowrap">
											<img class="kn-thumb"
												src="<?= $park['HasHeraldry'] == 1 ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId'])) : HTTP_PARK_HERALDRY . '00000.jpg' ?>"
												onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
												alt="">
											<a href="<?= UIR ?>Park/index/<?= $park['ParkId'] ?>"><?= htmlspecialchars($park['ParkName']) ?></a>
										</td>
										<td><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : '') ?></td>
										<td class="kn-col-numeric"><?= sprintf("%.2f", $park['AttendanceCount'] / 26) ?></td>
										<td class="kn-col-numeric"><?= sprintf("%.1f", $park['MonthlyCount'] / 12) ?></td>
										<td class="kn-col-numeric"><?= (int)$park['AttendanceCount'] ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr>
									<td colspan="2">Kingdom Total</td>
									<td class="kn-col-numeric"><?= sprintf("%.2f", $totalAtt / 26) ?></td>
									<td class="kn-col-numeric"><?= sprintf("%.1f", $totalMonthly / 12) ?></td>
									<td class="kn-col-numeric"><?= $totalAtt ?></td>
								</tr>
							</tfoot>
						</table>
					</div>

				<?php else: ?>
					<div class="kn-empty">No parks found</div>
				<?php endif; ?>
			</div>

			<!-- Events Tab -->
			<div class="kn-tab-panel" id="kn-tab-events" style="display:none">
				<?php if (count($eventList) > 0): ?>
					<table class="kn-table kn-sortable" id="kn-events-table">
						<thead>
							<tr>
								<th data-sorttype="date">Next Date</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Park</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
								<tr class="kn-row-link" onclick="window.location.href='<?= UIR ?>Event/index/<?= $event['EventId'] ?>'">
									<td class="kn-col-nowrap">
										<?= (0 != $event['NextDate'] && $event['NextDate'] != '0000-00-00')
											? date("M j, Y", strtotime($event['NextDate']))
											: '<span style="color:#a0aec0">—</span>' ?>
									</td>
									<td class="kn-col-nowrap">
										<img class="kn-thumb"
											src="<?= $event['HasHeraldry'] == 1 ? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) : HTTP_EVENT_HERALDRY . '00000.jpg' ?>"
											onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
											alt="">
										<a href="<?= UIR ?>Event/index/<?= $event['EventId'] ?>"><?= htmlspecialchars($event['Name']) ?></a>
									</td>
									<td><?= htmlspecialchars($event['ParkName']) ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="kn-empty">No upcoming events</div>
				<?php endif; ?>
			</div>

			<!-- Tournaments Tab -->
			<div class="kn-tab-panel" id="kn-tab-tournaments" style="display:none">
				<?php if (count($tournamentList) > 0): ?>
					<table class="kn-table kn-sortable" id="kn-tournaments-table">
						<thead>
							<tr>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Tournament</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="text">Event</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($tournamentList as $t): ?>
								<tr class="kn-row-link" onclick="window.location.href='<?= UIR ?>Tournament/worksheet/<?= $t['TournamentId'] ?>'">
									<td class="kn-col-nowrap"><?= date("M j, Y", strtotime($t['DateTime'])) ?></td>
									<td>
										<a href="<?= UIR ?>Tournament/worksheet/<?= $t['TournamentId'] ?>"><?= htmlspecialchars($t['Name']) ?></a>
									</td>
									<td><?= htmlspecialchars($t['ParkName']) ?></td>
									<td><?= htmlspecialchars($t['EventName']) ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="kn-empty">No tournaments found</div>
				<?php endif; ?>
			</div>

			<!-- Map Tab -->
			<div class="kn-tab-panel" id="kn-tab-map" style="display:none">
				<?php if (count($knMapLocations) > 0): ?>
					<div id="kn-map-loading" class="kn-map-loading">
						<i class="fas fa-spinner fa-spin" style="font-size:22px"></i>
						Loading map&hellip;
					</div>
					<div id="kn-map-container" style="display:none">
						<div class="kn-map-layout">
							<div class="kn-map-wrap">
								<div id="kn-map"></div>
							</div>
							<div class="kn-map-directions-wrap">
								<div class="kn-card">
									<h4 id="kn-directions-title"><i class="fas fa-directions"></i> Directions</h4>
									<div id="kn-map-directions">
										<p style="color:#a0aec0;font-style:italic">Click a park pin for details.</p>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php else: ?>
					<div class="kn-empty">No park location data available</div>
				<?php endif; ?>
			</div>

			<!-- Principalities Tab (only rendered if applicable) -->
			<?php if (!$IsPrinz && count($principalityList) > 0): ?>
				<div class="kn-tab-panel" id="kn-tab-principalities" style="display:none">
					<?php foreach ($principalityList as $prinz): ?>
						<div class="kn-prinz-row">
							<img class="kn-prinz-heraldry"
								src="<?= HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prinz['KingdomId'])) ?>"
								onerror="this.src='<?= HTTP_KINGDOM_HERALDRY ?>0000.jpg'"
								alt="">
							<div class="kn-prinz-name">
								<a href="<?= UIR ?>Kingdom/index/<?= $prinz['KingdomId'] ?>&kingdom_name=<?= urlencode($prinz['Name']) ?>"><?= htmlspecialchars($prinz['Name']) ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Reports Tab -->
			<div class="kn-tab-panel" id="kn-tab-reports" style="display:none">
				<div class="kn-reports-grid">

					<div class="kn-report-group">
						<h5><i class="fas fa-users"></i> Players</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/roster/Kingdom&id=<?= $kingdom_id ?>">Player Roster</a></li>
							<li><a href="<?= UIR ?>Reports/active/Kingdom&id=<?= $kingdom_id ?>">Active Players</a></li>
							<li><a href="<?= UIR ?>Reports/dues/Kingdom&id=<?= $kingdom_id ?>">Dues Paid</a></li>
							<li><a href="<?= UIR ?>Reports/waivered/Kingdom&id=<?= $kingdom_id ?>">Waivered</a></li>
							<li><a href="<?= UIR ?>Reports/unwaivered/Kingdom&id=<?= $kingdom_id ?>">Unwaivered</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspended</a></li>
							<li><a href="<?= UIR ?>Reports/active_duespaid/Kingdom&id=<?= $kingdom_id ?>">Player Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/active_waivered_duespaid/Kingdom&id=<?= $kingdom_id ?>">Waivered Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/reeve&KingdomId=<?= $kingdom_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora&KingdomId=<?= $kingdom_id ?>">Corpora Qualified</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>">Award Recommendations</a></li>
							<li><a href="<?= UIR ?>Reports/knights_and_masters&KingdomId=<?= $kingdom_id ?>">Knights &amp; Masters</a></li>
							<li><a href="<?= UIR ?>Reports/knights_list&KingdomId=<?= $kingdom_id ?>">Knights</a></li>
							<li><a href="<?= UIR ?>Reports/masters_list&KingdomId=<?= $kingdom_id ?>">Masters</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=8&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?>-level Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>">Class Masters/Paragons</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?> Guilds</a></li>
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>">Custom Awards</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-calendar-check"></i> Attendance</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/3">Past 3 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/6">Past 6 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/All">All Time</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-image"></i> Heraldry</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/parkheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Parks</a></li>
							<li><a href="<?= UIR ?>Reports/playerheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Players</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-search"></i> Find</h5>
						<ul>
							<li><a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Players</a></li>
							<li><a href="<?= UIR ?>Search/unit&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a></li>
							<li><a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Events</a></li>
							<li><a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Unit List</a></li>
						</ul>
					</div>

				</div>
			</div>

		</div><!-- /kn-tabs -->
	</div><!-- /kn-main -->

</div><!-- /kn-layout -->

<!-- =============================================
     JavaScript
     ============================================= -->
<script type="text/javascript">
// ---- Map data (server-rendered) ----
var knMapLocations = <?= json_encode(array_values($knMapLocations), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var knMapLoaded    = false;

// Defined globally so Google Maps API callback can find it
window.knInitMap = async function() {
	if (!document.getElementById('kn-map')) return;
	document.getElementById('kn-map-loading').style.display = 'none';
	document.getElementById('kn-map-container').style.display = 'block';

	const { Map } = await google.maps.importLibrary("maps");
	const { AdvancedMarkerElement, PinElement } = await google.maps.importLibrary("marker");

	var map = new google.maps.Map(document.getElementById('kn-map'), {
		center: {lat: 0, lng: 0},
		zoom: 2,
		mapId: 'ORK3_MAP_ID'
	});

	var LatLngList = [];
	for (var i = 0; i < knMapLocations.length; i++) {
		LatLngList.push(new google.maps.LatLng(knMapLocations[i].lat, knMapLocations[i].lng));
	}
	if (LatLngList.length > 0) {
		var bounds = new google.maps.LatLngBounds();
		for (var i = 0; i < LatLngList.length; i++) bounds.extend(LatLngList[i]);
		map.fitBounds(bounds);
		// fitBounds zoom used as-is (no pullback)
	}

	var infowindow = new google.maps.InfoWindow();
	for (var i = 0; i < knMapLocations.length; i++) {
		(function(loc) {
			var pinGlyph = new PinElement({ scale: 0.7 });
			var marker = new google.maps.marker.AdvancedMarkerElement({
				position: new google.maps.LatLng(loc.lat, loc.lng),
				map: map,
				title: loc.name,
				content: pinGlyph.element
			});
			google.maps.event.addListener(marker, 'click', function() {
				infowindow.setContent(
					"<b><a href='<?= UIR ?>Park/index/" + loc.id + "'>" + loc.name + "</a></b>" +
					"<div style='margin-top:8px;max-width:260px;font-size:12px'>" + loc.info + "</div>"
				);
				infowindow.open(map, marker);
				document.getElementById('kn-directions-title').innerHTML = '<i class="fas fa-directions"></i> ' + loc.name;
				document.getElementById('kn-map-directions').innerHTML = loc.info;
			});
		})(knMapLocations[i]);
	}
};

// ---- Activate a tab by name (used by buttons + links) ----
function knActivateTab(tab) {
	$('.kn-tab-nav li').removeClass('kn-tab-active');
	$('.kn-tab-nav li[data-kntab="' + tab + '"]').addClass('kn-tab-active');
	$('.kn-tab-panel').hide();
	$('#kn-tab-' + tab).show();
	if (tab === 'map' && !knMapLoaded && knMapLocations.length > 0) {
		knMapLoaded = true;
		var s = document.createElement('script');
		s.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyB_hIughnMCuRdutIvw_M_uwQUCREhHuI8&callback=knInitMap&v=weekly&libraries=marker';
		document.head.appendChild(s);
	}
	$('html, body').animate({ scrollTop: $('.kn-tabs').offset().top - 20 }, 250);
}

// ---- Hero color from heraldry ----
// Samples the heraldry image via Canvas to find its dominant non-white, non-black
// color and applies a darkened version as the hero background.
function knApplyHeroColor(img) {
	var canvas = document.createElement('canvas');
	canvas.width = 60; canvas.height = 60;
	var ctx = canvas.getContext('2d');
	try {
		ctx.drawImage(img, 0, 0, 60, 60);
		var px = ctx.getImageData(0, 0, 60, 60).data;
		var buckets = {};
		for (var i = 0; i < px.length; i += 4) {
			var r = px[i], g = px[i+1], b = px[i+2], a = px[i+3];
			if (a < 120) continue;                          // skip transparent
			if (r > 215 && g > 215 && b > 215) continue;   // skip near-white
			if (r < 25  && g < 25  && b < 25)  continue;   // skip near-black
			// Bucket colors into 16-step bins so similar shades merge
			var key = (r >> 4) + ',' + (g >> 4) + ',' + (b >> 4);
			buckets[key] = (buckets[key] || 0) + 1;
		}
		var best = null, bestN = 0;
		for (var k in buckets) { if (buckets[k] > bestN) { bestN = buckets[k]; best = k; } }
		if (!best) return;

		// Reconstruct mid-point of bucket
		var parts = best.split(',');
		var dr = parseInt(parts[0]) * 16 + 8;
		var dg = parseInt(parts[1]) * 16 + 8;
		var db = parseInt(parts[2]) * 16 + 8;

		// Convert to HSL
		var rf = dr/255, gf = dg/255, bf = db/255;
		var max = Math.max(rf,gf,bf), min = Math.min(rf,gf,bf);
		var h = 0, s = 0, l = (max+min)/2;
		if (max !== min) {
			var d = max - min;
			s = l > 0.5 ? d/(2-max-min) : d/(max+min);
			if      (max === rf) h = (gf-bf)/d + (gf < bf ? 6 : 0);
			else if (max === gf) h = (bf-rf)/d + 2;
			else                 h = (rf-gf)/d + 4;
			h /= 6;
		}

		// Clamp: keep hue, boost saturation if washed out, fix lightness for dark bg
		var finalS = Math.max(s, 0.28);
		var heroEl = document.querySelector('.kn-hero');
		if (heroEl) {
			heroEl.style.backgroundColor =
				'hsl(' + Math.round(h*360) + ',' + Math.round(finalS*100) + '%,18%)';
		}
	} catch(e) { /* CORS or tainted canvas — keep default green */ }
}

// ---- Pagination helpers ----
function knPageRange(current, total) {
	var pages = [];
	if (total <= 7) {
		for (var p = 1; p <= total; p++) pages.push(p);
	} else {
		pages.push(1);
		if (current > 3) pages.push(-1);
		var s = Math.max(2, current - 1);
		var e = Math.min(total - 1, current + 1);
		for (var p = s; p <= e; p++) pages.push(p);
		if (current < total - 2) pages.push(-1);
		pages.push(total);
	}
	return pages;
}

function knPaginate($table, page) {
	var pageSize = 25;
	var $rows = $table.find('tbody tr');
	var total = $rows.length;
	if (total === 0) return;
	var totalPages = Math.max(1, Math.ceil(total / pageSize));
	page = Math.max(1, Math.min(page, totalPages));
	$table.data('kn-page', page);
	$rows.each(function(i) {
		$(this).toggle(i >= (page - 1) * pageSize && i < page * pageSize);
	});
	var $pg = $table.next('.kn-pagination');
	if ($pg.length === 0) $pg = $('<div class="kn-pagination"></div>').insertAfter($table);
	if (total <= pageSize) { $pg.empty().hide(); return; }
	$pg.show();
	var start = (page - 1) * pageSize + 1;
	var end   = Math.min(page * pageSize, total);
	var html  = '<span class="kn-pagination-info">Showing ' + start + '\u2013' + end + ' of ' + total + '</span>';
	html += '<div class="kn-pagination-controls">';
	html += '<button class="kn-page-btn kn-page-prev"' + (page === 1 ? ' disabled' : '') + '>&#8249;</button>';
	var range = knPageRange(page, totalPages);
	for (var ri = 0; ri < range.length; ri++) {
		if (range[ri] === -1) {
			html += '<span class="kn-page-ellipsis">&hellip;</span>';
		} else {
			html += '<button class="kn-page-btn kn-page-num' + (range[ri] === page ? ' kn-page-active' : '') + '" data-page="' + range[ri] + '">' + range[ri] + '</button>';
		}
	}
	html += '<button class="kn-page-btn kn-page-next"' + (page === totalPages ? ' disabled' : '') + '>&#8250;</button>';
	html += '</div>';
	$pg.html(html);
}

function knSortDesc($table, colIndex, sortType) {
	if (!$table.length) return;
	$table.find('thead th').removeClass('sort-asc sort-desc');
	$table.find('thead th').eq(colIndex).addClass('sort-desc');
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').get();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIndex).text().trim();
		var bVal = $(b).find('td').eq(colIndex).text().trim();
		var cmp = 0;
		if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
		else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
		else                          cmp = aVal.localeCompare(bVal);
		return -cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

function knSortAsc($table, colIndex, sortType) {
	if (!$table.length) return;
	$table.find('thead th').removeClass('sort-asc sort-desc');
	$table.find('thead th').eq(colIndex).addClass('sort-asc');
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').get();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIndex).text().trim();
		var bVal = $(b).find('td').eq(colIndex).text().trim();
		var cmp = 0;
		if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
		else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
		else                          cmp = aVal.localeCompare(bVal);
		return cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

$(document).ready(function() {

	// ---- Hero color from heraldry ----
	var knHeraldryImg = document.querySelector('.kn-heraldry-frame img');
	if (knHeraldryImg) {
		if (knHeraldryImg.complete && knHeraldryImg.naturalWidth) {
			knApplyHeroColor(knHeraldryImg);
		} else {
			knHeraldryImg.addEventListener('load', function() { knApplyHeroColor(this); });
		}
	}

	// ---- Tab switching ----
	$('.kn-tab-nav li').on('click', function() {
		knActivateTab($(this).attr('data-kntab'));
	});

	// ---- Parks view toggle (tiles / list) ----
	function knSetParksView(view) {
		if (view === 'list') {
			$('#kn-parks-tiles').hide();
			$('#kn-parks-list-view').show();
			$('#kn-view-list').addClass('kn-view-active');
			$('#kn-view-tiles').removeClass('kn-view-active');
		} else {
			$('#kn-parks-list-view').hide();
			$('#kn-parks-tiles').show();
			$('#kn-view-tiles').addClass('kn-view-active');
			$('#kn-view-list').removeClass('kn-view-active');
		}
		try { localStorage.setItem('kn_parks_view', view); } catch(e) {}
	}
	$('#kn-view-tiles').on('click', function() { knSetParksView('tiles'); });
	$('#kn-view-list').on('click',  function() { knSetParksView('list'); });
	// Restore preference, defaulting to tiles
	try {
		knSetParksView(localStorage.getItem('kn_parks_view') || 'tiles');
	} catch(e) {
		knSetParksView('tiles');
	}

	// ---- Sortable tables ----
	$('.kn-sortable').each(function() {
		var $table = $(this);
		$table.find('thead th').on('click', function() {
			var colIndex = $(this).index();
			var sortType = $(this).data('sorttype') || 'text';
			var isAsc = !$(this).hasClass('sort-asc');
			$table.find('thead th').removeClass('sort-asc sort-desc');
			$(this).addClass(isAsc ? 'sort-asc' : 'sort-desc');
			var $tbody = $table.find('tbody');
			var rows = $tbody.find('tr').get();
			rows.sort(function(a, b) {
				var aVal = $(a).find('td').eq(colIndex).text().trim();
				var bVal = $(b).find('td').eq(colIndex).text().trim();
				var cmp = 0;
				if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
				else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
				else                          cmp = aVal.localeCompare(bVal);
				return isAsc ? cmp : -cmp;
			});
			$.each(rows, function(i, row) { $tbody.append(row); });
			knPaginate($table, 1);
		});
	});

	// ---- Pagination event delegation ----
	$(document).on('click', '.kn-page-num', function() {
		var $table = $(this).closest('.kn-pagination').prev('.kn-table');
		if ($table.length) knPaginate($table, parseInt($(this).data('page')));
	});
	$(document).on('click', '.kn-page-prev', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.kn-pagination').prev('.kn-table');
		if ($table.length) knPaginate($table, ($table.data('kn-page') || 1) - 1);
	});
	$(document).on('click', '.kn-page-next', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.kn-pagination').prev('.kn-table');
		if ($table.length) knPaginate($table, ($table.data('kn-page') || 1) + 1);
	});

	// ---- Default sort + initial pagination ----
	<?php if (count($parkList) > 0): ?>
	knSortAsc($('#kn-parks-table'), 0, 'text');
	knPaginate($('#kn-parks-table'), 1);
	<?php endif; ?>
	<?php if (count($eventList) > 0): ?>
	knSortDesc($('#kn-events-table'), 0, 'date');
	knPaginate($('#kn-events-table'), 1);
	<?php endif; ?>
	<?php if (count($tournamentList) > 0): ?>
	knSortDesc($('#kn-tournaments-table'), 0, 'date');
	knPaginate($('#kn-tournaments-table'), 1);
	<?php endif; ?>

});
</script>
