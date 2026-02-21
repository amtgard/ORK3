<?php
	// ---- Normalize data into clean local variables ----
	$parkInfo    = $park_info['ParkInfo']     ?? [];
	$heraldryUrl = $park_info['Heraldry']['Url'] ?? '';
	$hasHeraldry = !empty($parkInfo['HasHeraldry']);
	$parkTitle   = trim($parkInfo['ParkTitle']   ?? '');
	$description = trim($parkInfo['Description'] ?? '');
	$directions  = trim($parkInfo['Directions']  ?? '');
	$websiteUrl  = trim($parkInfo['Url']          ?? '');

	$officerList    = $park_officers['Officers']          ?? [];
	$parkDayList    = $park_days['ParkDays']              ?? [];
	$eventList      = (array)($event_summary              ?? []);
	$tournamentList = $park_tournaments['Tournaments']    ?? [];

	// Extract Monarch & Regent for hero display
	$monarch = null; $regent = null;
	foreach ($officerList as $o) {
		if ($o['OfficerRole'] === 'Monarch') $monarch = $o;
		if ($o['OfficerRole'] === 'Regent')  $regent  = $o;
	}

	// Parse park main location for map links
	$parkLat = null; $parkLng = null;
	if (!empty($parkInfo['Location'])) {
		$loc = @json_decode(stripslashes((string)$parkInfo['Location']));
		if ($loc) {
			$latlng = isset($loc->location) ? $loc->location
				: (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
			if ($latlng && is_numeric($latlng->lat ?? null)) {
				$parkLat = (float)$latlng->lat;
				$parkLng = (float)$latlng->lng;
			}
		}
	}
	$parkMapUrl = (!is_null($parkLat))
		? 'https://maps.google.com/maps?q=@' . $parkLat . ',' . $parkLng
		: null;

	// Group all players by 6-month period (0 = 0–6 months ago, 1 = 6–12, etc.)
	$allPlayers = $park_players ?? [];
	$nowTs      = time();
	$playerPeriods  = [];
	$heraldryPeriods = [];
	foreach ($allPlayers as $p) {
		$ts = strtotime($p['LastSignin']);
		$period = max(0, (int)floor(($nowTs - $ts) / (30.44 * 24 * 3600) / 6));
		$playerPeriods[$period][] = $p;
		if ($p['HasHeraldry']) $heraldryPeriods[$period][] = $p;
	}
	ksort($playerPeriods);
	ksort($heraldryPeriods);

	$playerList    = $playerPeriods[0] ?? [];  // 0–6 months (used for stats row count)
	$totalHeraldry = array_sum(array_map('count', $heraldryPeriods));

	$firstTab = count($parkDayList) > 0 ? 'schedule' : 'events';
?>

<style type="text/css">
/* ========================================
   CRM-Style Park Profile
   All classes prefixed with pk- to avoid collisions
   ======================================== */

/* ---- Reset / Base ---- */
.pk-hero, .pk-stats-row, .pk-layout, .pk-sidebar, .pk-main,
.pk-card, .pk-tab-nav, .pk-tab-panel, .pk-table {
	box-sizing: border-box;
}

/* ---- Hero ---- */
.pk-hero {
	position: relative;
	background-color: #1a4731;
	overflow: hidden;
	margin-bottom: 0;
}
.pk-hero-bg {
	position: absolute;
	inset: 0;
	background-size: cover;
	background-position: center;
	opacity: 0.08;
	filter: blur(4px);
	transform: scale(1.05);
}
.pk-hero-content {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: 20px;
	padding: 24px 20px 20px;
	color: #fff;
}
.pk-hero-left { flex-shrink: 0; }
.pk-heraldry-frame {
	width: 90px;
	height: 90px;
	border-radius: 8px;
	overflow: hidden;
	border: 2px solid rgba(255,255,255,0.3);
	background: rgba(255,255,255,0.1);
	display: flex;
	align-items: center;
	justify-content: center;
}
.pk-heraldry-frame img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.pk-hero-center { flex: 1; min-width: 0; }
.pk-kingdom-link { margin-bottom: 4px; }
.pk-kingdom-link a {
	color: rgba(255,255,255,0.7);
	text-decoration: none;
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}
.pk-kingdom-link a:hover { color: #fff; }
.pk-park-name {
	font-size: 28px;
	font-weight: 700;
	color: #fff;
	margin: 0 0 4px 0;
	line-height: 1.15;
	background: transparent;
	border: none;
	padding: 0;
}
.pk-officers-inline {
	color: rgba(255,255,255,0.85);
	font-size: 13px;
	line-height: 1.8;
	margin-top: 4px;
}
.pk-officers-inline a {
	color: #fff;
	font-weight: 600;
	text-decoration: none;
}
.pk-officers-inline a:hover { text-decoration: underline; }
.pk-officers-sep { margin: 0 10px; opacity: 0.35; }
.pk-vacant { color: rgba(255,255,255,0.45); font-style: italic; }
.pk-park-title-badge {
	display: inline-block;
	background: rgba(255,255,255,0.15);
	color: rgba(255,255,255,0.9);
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 12px;
	margin-bottom: 8px;
	font-weight: 500;
}
.pk-hero-right { flex-shrink: 0; display: flex; align-items: flex-start; }
.pk-hero-actions {
	display: flex;
	flex-direction: column;
	gap: 7px;
}
.pk-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 7px 14px;
	border-radius: 5px;
	font-size: 13px;
	font-weight: 500;
	text-decoration: none;
	white-space: nowrap;
	cursor: pointer;
	border: none;
}
.pk-btn-white   { background: #fff; color: #2d3748; }
.pk-btn-white:hover { background: #f0f0f0; color: #2d3748; }
.pk-btn-outline { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.3); }
.pk-btn-outline:hover { background: rgba(255,255,255,0.2); color: #fff; }
.pk-btn-primary { background: #2c5282; color: #fff; }
.pk-btn-primary:hover:not(:disabled) { background: #2a4a7f; }
.pk-btn-secondary { background: #edf2f7; color: #4a5568; }
.pk-btn-secondary:hover:not(:disabled) { background: #e2e8f0; }
.pk-btn:disabled, .pk-btn[disabled] { opacity: 0.4; cursor: not-allowed; }

/* ---- Stats row ---- */
.pk-stats-row {
	display: flex;
	background: #fff;
	border-bottom: 1px solid #e2e8f0;
}
.pk-stat-card {
	flex: 1;
	padding: 14px 12px;
	text-align: center;
	border-right: 1px solid #e2e8f0;
}
.pk-stat-card:last-child { border-right: none; }
.pk-stat-card-link {
	cursor: pointer;
	transition: background 0.15s, transform 0.12s;
}
.pk-stat-card-link:hover {
	background: #ebf8ff;
	transform: translateY(-2px);
}
.pk-stat-icon { font-size: 13px; color: #a0aec0; margin-bottom: 3px; }
.pk-stat-value { font-size: 22px; font-weight: 700; color: #2b6cb0; line-height: 1.1; }
.pk-stat-label { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }

/* ---- Main layout ---- */
.pk-layout {
	display: flex;
	align-items: flex-start;
	background: #f7f9fc;
	min-height: 400px;
}
.pk-sidebar {
	width: 240px;
	flex-shrink: 0;
	padding: 16px 12px;
	border-right: 1px solid #e2e8f0;
	background: #fff;
}
.pk-main {
	flex: 1;
	min-width: 0;
	padding: 16px;
}

/* ---- Cards ---- */
.pk-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 14px;
	margin-bottom: 12px;
}
.pk-card h4 {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: #718096;
	margin: 0 0 10px 0;
}
.pk-link-list { list-style: none; margin: 0; padding: 0; }
.pk-link-list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 5px 0;
	border-bottom: 1px solid #f0f0f0;
	font-size: 13px;
}
.pk-link-list li:last-child { border-bottom: none; }
.pk-link-icon { width: 18px; text-align: center; color: #a0aec0; font-size: 12px; flex-shrink: 0; }
.pk-link-list a { color: #2b6cb0; text-decoration: none; }
.pk-link-list a:hover { text-decoration: underline; }
.pk-officer-list { list-style: none; margin: 0; padding: 0; }
.pk-officer-list li { font-size: 13px; padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
.pk-officer-list li:last-child { border-bottom: none; }
.pk-officer-role { font-size: 11px; color: #718096; display: block; }
.pk-officer-name a { color: #2b6cb0; text-decoration: none; }
.pk-officer-name a:hover { text-decoration: underline; }
.pk-description-text { font-size: 13px; color: #4a5568; line-height: 1.5; }

/* ---- Tabs ---- */
.pk-tabs { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
.pk-tab-nav {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	background: #f7fafc;
	border-bottom: 1px solid #e2e8f0;
	overflow-x: auto;
}
.pk-tab-nav li {
	padding: 11px 16px;
	font-size: 13px;
	color: #718096;
	cursor: pointer;
	white-space: nowrap;
	display: flex;
	align-items: center;
	gap: 6px;
	border-bottom: 2px solid transparent;
}
.pk-tab-nav li:hover { color: #2b6cb0; background: #edf2f7; }
.pk-tab-active { color: #2b6cb0 !important; border-bottom-color: #2b6cb0 !important; font-weight: 600; }
.pk-tab-count { font-size: 11px; color: #a0aec0; }
.pk-tab-panel { padding: 16px; }

/* ---- Tables ---- */
.pk-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.pk-table th {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	padding: 8px 10px;
	text-align: left;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	cursor: pointer;
	white-space: nowrap;
}
.pk-table th:hover { background: #edf2f7; }
.pk-table td {
	border: 1px solid #e2e8f0;
	padding: 8px 10px;
	vertical-align: middle;
	color: #2d3748;
}
.pk-table tr:hover td { background: #f7fafc; }
.pk-table tbody tr { cursor: pointer; }
.pk-sort-asc::after  { content: ' ▲'; font-size: 10px; }
.pk-sort-desc::after { content: ' ▼'; font-size: 10px; }
.pk-tiny-heraldry {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	vertical-align: middle;
}
.pk-tiny-heraldry img { width: 24px; height: 24px; object-fit: contain; border-radius: 3px; }
.pk-date-col { white-space: nowrap; color: #718096; }

/* ---- Pagination ---- */
.pk-pagination {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: 10px;
	flex-wrap: wrap;
}
.pk-page-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 30px;
	height: 30px;
	padding: 0 6px;
	border: 1px solid #e2e8f0;
	border-radius: 4px;
	background: #fff;
	color: #4a5568;
	font-size: 12px;
	cursor: pointer;
	text-decoration: none;
}
.pk-page-btn:hover { background: #edf2f7; }
.pk-page-btn.pk-page-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
.pk-page-btn.pk-page-disabled { color: #a0aec0; cursor: default; pointer-events: none; }
.pk-page-ellipsis { color: #a0aec0; font-size: 12px; padding: 0 4px; }

/* ---- Schedule (park days) ---- */
.pk-schedule-header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 14px;
	flex-wrap: wrap;
}
.pk-map-link {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: #2b6cb0;
	text-decoration: none;
	padding: 5px 10px;
	border: 1px solid #bee3f8;
	border-radius: 5px;
	background: #ebf8ff;
}
.pk-map-link:hover { background: #bee3f8; }
.pk-directions-panel {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 12px 14px;
	margin-bottom: 14px;
}
.pk-directions-panel h5 {
	font-size: 12px;
	font-weight: 700;
	color: #718096;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0 0 6px 0;
}
.pk-directions-text { font-size: 13px; color: #4a5568; line-height: 1.5; }
.pk-schedule-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}
.pk-schedule-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 14px;
	display: flex;
	gap: 12px;
	align-items: flex-start;
}
.pk-schedule-icon {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: #ebf8ff;
	display: flex;
	align-items: center;
	justify-content: center;
	color: #2b6cb0;
	font-size: 15px;
	flex-shrink: 0;
}
.pk-schedule-icon.icon-fighter { background: #fff5f5; color: #c53030; }
.pk-schedule-icon.icon-arts    { background: #faf5ff; color: #6b46c1; }
.pk-schedule-icon.icon-other   { background: #fffbeb; color: #d69e2e; }
.pk-schedule-info { flex: 1; min-width: 0; }
.pk-schedule-when { font-weight: 600; font-size: 14px; color: #2d3748; }
.pk-schedule-time { font-size: 13px; color: #4a5568; margin-top: 2px; }
.pk-schedule-purpose {
	display: inline-block;
	margin-top: 5px;
	font-size: 11px;
	font-weight: 600;
	padding: 2px 7px;
	border-radius: 10px;
	background: #ebf8ff;
	color: #2b6cb0;
}
.pk-schedule-purpose.purpose-fighter { background: #fff5f5; color: #c53030; }
.pk-schedule-purpose.purpose-arts    { background: #faf5ff; color: #6b46c1; }
.pk-schedule-purpose.purpose-other   { background: #fffbeb; color: #d69e2e; }
.pk-schedule-address { font-size: 12px; color: #718096; margin-top: 4px; }
.pk-schedule-map-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	color: #2b6cb0;
	text-decoration: none;
	margin-top: 6px;
}
.pk-schedule-map-link:hover { text-decoration: underline; }

/* ---- Reports grid ---- */
.pk-reports-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 14px;
}
.pk-reports-section h5 {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: #718096;
	margin: 0 0 8px 0;
	padding-bottom: 5px;
	border-bottom: 1px solid #e2e8f0;
}
.pk-reports-section ul {
	list-style: none;
	margin: 0;
	padding: 0;
}
.pk-reports-section li { margin-bottom: 5px; }
.pk-reports-section a { font-size: 13px; color: #2b6cb0; text-decoration: none; }
.pk-reports-section a:hover { text-decoration: underline; }

/* ---- Empty state ---- */
.pk-empty {
	text-align: center;
	color: #a0aec0;
	font-size: 13px;
	padding: 30px 0;
	font-style: italic;
}

/* ---- Players tab ---- */
.pk-players-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
	flex-wrap: wrap;
	gap: 8px;
}
.pk-players-toolbar-left { font-size: 13px; color: #718096; }
.pk-players-toolbar-right { display: flex; align-items: center; gap: 8px; }
.pk-player-search-wrap {
	position: relative;
	display: flex;
	align-items: center;
}
.pk-player-search-icon {
	position: absolute;
	left: 8px;
	color: #a0aec0;
	font-size: 12px;
	pointer-events: none;
}
.pk-player-search-input {
	border: 1px solid #e2e8f0;
	border-radius: 5px;
	padding: 5px 10px 5px 26px;
	font-size: 13px;
	width: 180px;
	outline: none;
	transition: border-color 0.15s;
}
.pk-player-search-input:focus { border-color: #bee3f8; }
.pk-hoa-section.pk-search-hidden { display: none; }
.pk-view-toggle {
	display: flex;
	border: 1px solid #e2e8f0;
	border-radius: 5px;
	overflow: hidden;
}
.pk-view-btn {
	padding: 5px 11px;
	font-size: 12px;
	color: #718096;
	cursor: pointer;
	background: #fff;
	border: none;
	display: flex;
	align-items: center;
	gap: 5px;
	line-height: 1;
}
.pk-view-btn + .pk-view-btn { border-left: 1px solid #e2e8f0; }
.pk-view-btn.pk-view-active { background: #2b6cb0; color: #fff; }
.pk-alpha-group { margin-bottom: 14px; }
.pk-alpha-header {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.1em;
	color: #a0aec0;
	text-transform: uppercase;
	border-bottom: 1px solid #e2e8f0;
	padding-bottom: 4px;
	margin-bottom: 8px;
}
.pk-players-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
	gap: 8px;
}
.pk-player-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 11px 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	text-decoration: none;
	color: inherit;
	position: relative;
	overflow: hidden;
}
.pk-player-card:hover {
	box-shadow: 0 2px 8px rgba(0,0,0,0.08);
	border-color: #bee3f8;
}
/* Heraldry blurred background — mirrors hero pattern */
.pk-player-card-hbg::before {
	content: '';
	position: absolute;
	inset: 0;
	background-image: var(--hbg);
	background-size: cover;
	background-position: center;
	opacity: 0.15;
	filter: blur(2px);
	transform: scale(1.08);
	pointer-events: none;
}
.pk-player-card-hbg > * { position: relative; }
.pk-player-card-top {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}
.pk-player-avatar {
	width: 34px;
	height: 34px;
	border-radius: 50%;
	background: #ebf8ff;
	color: #2b6cb0;
	font-size: 14px;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	overflow: hidden;
}
.pk-player-avatar img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.pk-player-name {
	font-size: 13px;
	font-weight: 600;
	color: #2d3748;
	line-height: 1.3;
	word-break: break-word;
}
.pk-officer-pill {
	display: inline-block;
	font-size: 10px;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 10px;
	background: #fef3c7;
	color: #92400e;
	white-space: nowrap;
	margin-top: 2px;
	margin-right: 2px;
}
.pk-player-stats {
	font-size: 11px;
	color: #718096;
	line-height: 1.7;
}
.pk-player-stats span { display: block; }

/* ---- Responsive ---- */
@media (max-width: 768px) {
	.pk-hero-content { flex-wrap: wrap; }
	.pk-hero-right { width: 100%; }
	.pk-hero-actions { flex-direction: row; flex-wrap: wrap; }
	.pk-layout { flex-direction: column; }
	.pk-sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e2e8f0; }
	.pk-stats-row { flex-wrap: wrap; }
	.pk-stat-card { flex: 1 1 50%; min-width: 0; }
	.pk-reports-grid { grid-template-columns: 1fr; }
	.pk-park-name { font-size: 22px; }
	.pk-schedule-grid { grid-template-columns: 1fr; }
	.pk-players-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
	.pk-hoa-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); }
}

/* ---- Period label (Load More separators) ---- */
.pk-period-label {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.07em;
	color: #a0aec0;
	text-transform: uppercase;
	border-bottom: 1px solid #e2e8f0;
	padding-bottom: 4px;
	margin: 18px 0 10px;
}

/* ---- Load More button ---- */
.pk-load-more-wrap {
	text-align: center;
	padding: 18px 0 8px;
}
.pk-load-more-btn {
	background: #fff;
	border: 1.5px solid #cbd5e0;
	border-radius: 6px;
	padding: 8px 22px;
	font-size: 13px;
	font-weight: 600;
	color: #4a5568;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	gap: 7px;
	transition: border-color 0.15s, color 0.15s;
}
.pk-load-more-btn:hover {
	border-color: #2b6cb0;
	color: #2b6cb0;
}
.pk-load-more-hint {
	display: block;
	font-size: 11px;
	color: #a0aec0;
	margin-top: 6px;
}

/* ---- Hall of Arms gallery ---- */
.pk-hoa-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 14px;
	margin-bottom: 6px;
}
.pk-hoa-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 12px 8px 10px;
	text-align: center;
	text-decoration: none;
	color: inherit;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 7px;
	transition: box-shadow 0.15s, border-color 0.15s;
}
.pk-hoa-card:hover {
	box-shadow: 0 2px 10px rgba(0,0,0,0.09);
	border-color: #bee3f8;
}
.pk-hoa-heraldry {
	width: 90px;
	height: 90px;
	object-fit: contain;
}
.pk-hoa-name {
	font-size: 11px;
	font-weight: 600;
	color: #2d3748;
	line-height: 1.3;
	word-break: break-word;
}
/* ---- Award entry modal (pk-award-*) ---- */
.pk-award-type-row { display:flex; gap:8px; margin-bottom:16px; }
.pk-award-type-btn { flex:1; padding:8px 0; border:2px solid #e2e8f0; border-radius:8px; background:#fff; font-size:13px; font-weight:600; color:#4a5568; cursor:pointer; transition:all 0.15s; text-align:center; }
.pk-award-type-btn.pk-active { border-color:#2c5282; background:#ebf4ff; color:#2c5282; }
.pk-rank-pills-wrap { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
.pk-rank-pill { width:36px; height:36px; border:2px solid #e2e8f0; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; cursor:pointer; background:#fff; color:#4a5568; transition:all 0.12s; user-select:none; }
.pk-rank-pill.pk-rank-held { background:#ebf4ff; border-color:#90cdf4; color:#2b6cb0; }
.pk-rank-pill.pk-rank-suggested { border-color:#68d391; }
.pk-rank-pill.pk-rank-selected { background:#2c5282; border-color:#2c5282; color:#fff; }
.pk-officer-chips { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:6px; }
.pk-officer-chip { padding:4px 10px; background:#edf2f7; border:1px solid #e2e8f0; border-radius:14px; font-size:12px; color:#2d3748; cursor:pointer; }
.pk-officer-chip.pk-selected { background:#ebf4ff; border-color:#90cdf4; color:#2b6cb0; font-weight:600; }
.pk-ac-results { margin-top:4px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; max-height:140px; overflow-y:auto; display:none; }
.pk-ac-results.pk-ac-open { display:block; }
.pk-ac-item { padding:8px 12px; font-size:13px; cursor:pointer; color:#2d3748; border-bottom:1px solid #f7fafc; }
.pk-ac-item:hover { background:#ebf4ff; color:#2b6cb0; }
.pk-award-success { background:#f0fff4; border:1px solid #9ae6b4; border-radius:6px; padding:8px 12px; margin-bottom:12px; color:#276749; font-size:13px; }
.pk-btn-ghost { background:transparent; border:1px solid transparent; color:#4a5568; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:13px; }
.pk-btn-ghost:hover { background:rgba(255,255,255,0.15); }
.pk-badge-ladder { display:inline-flex; align-items:center; gap:4px; padding:1px 8px; border-radius:12px; font-size:11px; font-weight:700; background:#fefcbf; color:#744210; }
/* Reuse pn- overlay/modal styles for pk- award modal */
#pk-award-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1100; opacity:0; pointer-events:none; transition:opacity 0.2s; }
#pk-award-overlay.pk-open { opacity:1; pointer-events:all; }
#pk-award-overlay .pk-modal-box { background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3); max-height:90vh; display:flex; flex-direction:column; }
#pk-award-overlay .pk-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
#pk-award-overlay .pk-modal-title { font-size:16px; font-weight:700; color:#1a202c; margin:0; }
#pk-award-overlay .pk-modal-close-btn { background:none; border:none; font-size:22px; color:#a0aec0; cursor:pointer; line-height:1; padding:0 4px; }
#pk-award-overlay .pk-modal-close-btn:hover { color:#4a5568; }
#pk-award-overlay .pk-modal-body { padding:20px; overflow-y:auto; flex:1; }
#pk-award-overlay .pk-modal-footer { padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
#pk-award-overlay .pk-acct-field { margin-bottom:14px; }
#pk-award-overlay .pk-acct-field label { display:block; font-size:12px; font-weight:600; color:#4a5568; margin-bottom:5px; text-transform:uppercase; letter-spacing:0.04em; }
#pk-award-overlay .pk-acct-field input[type=text],
#pk-award-overlay .pk-acct-field input[type=date],
#pk-award-overlay .pk-acct-field select,
#pk-award-overlay .pk-acct-field textarea { width:100%; padding:8px 10px; border:1.5px solid #e2e8f0; border-radius:6px; font-size:13px; color:#2d3748; background:#fff; box-sizing:border-box; }
#pk-award-overlay .pk-acct-field input:focus,
#pk-award-overlay .pk-acct-field select:focus,
#pk-award-overlay .pk-acct-field textarea:focus { outline:none; border-color:#90cdf4; box-shadow:0 0 0 3px rgba(66,153,225,0.15); }
#pk-award-overlay .pk-form-error { display:none; background:#fff5f5; border:1px solid #fed7d7; border-radius:6px; padding:8px 12px; margin-bottom:12px; color:#c53030; font-size:13px; }
#pk-award-overlay .pk-char-count { font-size:11px; color:#a0aec0; margin-top:3px; display:block; }
#pk-award-overlay .pk-award-info-line { font-size:11px; color:#718096; margin-top:4px; min-height:16px; }
</style>

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<div class="pk-hero">
	<div class="pk-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="pk-hero-content">

		<!-- Heraldry -->
		<div class="pk-hero-left">
			<?php if ($hasHeraldry): ?>
			<div class="pk-heraldry-frame">
				<img class="heraldry-img" src="<?= htmlspecialchars($heraldryUrl) ?>"
				     alt="<?= htmlspecialchars($park_name) ?> heraldry"
				     crossorigin="anonymous"
				     onload="pkApplyHeroColor(this)">
			</div>
			<?php endif; ?>
		</div>

		<!-- Name / title / officers -->
		<div class="pk-hero-center">
			<div class="pk-kingdom-link">
				<a href="<?= UIR ?>Kingdom/index/<?= $kingdom_id ?>">
					<i class="fas fa-crown"></i> <?= htmlspecialchars($kingdom_name) ?>
				</a>
			</div>
			<h1 class="pk-park-name"><?= htmlspecialchars($park_name) ?></h1>
			<?php if (!empty($parkTitle)): ?>
				<span class="pk-park-title-badge"><?= htmlspecialchars($parkTitle) ?></span>
			<?php endif; ?>
			<div class="pk-officers-inline">
				<?php if ($monarch): ?>
					<i class="fas fa-crown" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					Monarch:&nbsp;
					<?php if (!empty($monarch['MundaneId']) && $monarch['MundaneId'] > 0): ?>
						<a href="<?= UIR ?>Player/index/<?= $monarch['MundaneId'] ?>"><?= htmlspecialchars($monarch['Persona']) ?></a>
					<?php else: ?>
						<span class="pk-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- Action buttons -->
		<div class="pk-hero-right">
			<div class="pk-hero-actions">
				<?php if ($LoggedIn): ?>
					<a class="pk-btn pk-btn-white" href="<?= UIR ?>Attendance/park/<?= $park_id ?>">
						<i class="fas fa-clipboard-check"></i> Enter Attendance
					</a>
					<button class="pk-btn pk-btn-outline" onclick="pkOpenAwardModal()">
						<i class="fas fa-medal"></i> Enter Awards
					</button>
				<?php endif; ?>
				<a class="pk-btn <?= $LoggedIn ? 'pk-btn-outline' : 'pk-btn-white' ?>" href="<?= UIR ?>Search/park/<?= $park_id ?>">
					<i class="fas fa-search"></i> Search Players
				</a>
				<?php if ($LoggedIn): ?>
					<a class="pk-btn pk-btn-outline" href="<?= UIR ?>Admin/park/<?= $park_id ?>">
						<i class="fas fa-cog"></i> Admin
					</a>
				<?php endif; ?>
			</div>
		</div>

	</div>
</div>

<!-- =============================================
     ZONE 2: Stats Row
     ============================================= -->
<div class="pk-stats-row">
	<div class="pk-stat-card pk-stat-card-link" onclick="pkActivateTab('schedule')">
		<div class="pk-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="pk-stat-value"><?= count($parkDayList) ?></div>
		<div class="pk-stat-label">Park Day<?= count($parkDayList) != 1 ? 's' : '' ?></div>
	</div>
	<div class="pk-stat-card pk-stat-card-link" onclick="pkActivateTab('events')">
		<div class="pk-stat-icon"><i class="fas fa-flag"></i></div>
		<div class="pk-stat-value"><?= count($eventList) ?></div>
		<div class="pk-stat-label">Event<?= count($eventList) != 1 ? 's' : '' ?></div>
	</div>
	<div class="pk-stat-card pk-stat-card-link" onclick="pkActivateTab('events')">
		<div class="pk-stat-icon"><i class="fas fa-trophy"></i></div>
		<div class="pk-stat-value"><?= count($tournamentList) ?></div>
		<div class="pk-stat-label">Tournament<?= count($tournamentList) != 1 ? 's' : '' ?></div>
	</div>
	<div class="pk-stat-card">
		<div class="pk-stat-icon"><i class="fas fa-users"></i></div>
		<div class="pk-stat-value"><?= count($officerList) ?></div>
		<div class="pk-stat-label">Officer<?= count($officerList) != 1 ? 's' : '' ?></div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Tabbed Main
     ============================================= -->
<div class="pk-layout">

	<!-- ---- Sidebar ---- -->
	<aside class="pk-sidebar">

		<!-- Officers -->
		<?php if (!empty($officerList)): ?>
		<div class="pk-card">
			<h4><i class="fas fa-crown"></i> Officers</h4>
			<ul class="pk-officer-list">
				<?php foreach ($officerList as $o): ?>
				<li>
					<span class="pk-officer-role"><?= htmlspecialchars($o['OfficerRole']) ?></span>
					<span class="pk-officer-name">
						<?php if (!empty($o['MundaneId']) && $o['MundaneId'] > 0): ?>
							<a href="<?= UIR ?>Player/index/<?= $o['MundaneId'] ?>"><?= htmlspecialchars($o['Persona']) ?></a>
						<?php else: ?>
							<em style="color:#a0aec0">Vacant</em>
						<?php endif; ?>
					</span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Quick Links -->
		<div class="pk-card">
			<h4><i class="fas fa-link"></i> Quick Links</h4>
			<ul class="pk-link-list">
				<li>
					<span class="pk-link-icon"><i class="fas fa-search"></i></span>
					<a href="<?= UIR ?>Search/park/<?= $park_id ?>">Search Players</a>
				</li>
				<li>
					<span class="pk-link-icon"><i class="fas fa-image"></i></span>
					<a href="<?= UIR ?>Reports/playerheraldry/<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Park Heraldry</a>
				</li>
				<?php if ($LoggedIn): ?>
				<li>
					<span class="pk-link-icon"><i class="fas fa-eye"></i></span>
					<a href="<?= UIR ?>Attendance/behold/<?= $park_id ?>">Behold!</a>
				</li>
				<?php endif; ?>
				<li>
					<span class="pk-link-icon"><i class="fas fa-users"></i></span>
					<a href="<?= UIR ?>Unit/unitlist&ParkId=<?= $park_id ?>">Companies &amp; Households</a>
				</li>
				<?php if (!empty($websiteUrl)): ?>
				<li>
					<span class="pk-link-icon"><i class="fas fa-globe"></i></span>
					<a href="<?= htmlspecialchars($websiteUrl) ?>" target="_blank" rel="noopener">Website</a>
				</li>
				<?php endif; ?>
				<?php if ($parkMapUrl): ?>
				<li>
					<span class="pk-link-icon"><i class="fas fa-map-marker-alt"></i></span>
					<a href="<?= $parkMapUrl ?>" target="_blank" rel="noopener">Park Map</a>
				</li>
				<?php endif; ?>
			</ul>
		</div>

		<!-- Description -->
		<?php if (!empty($description)): ?>
		<div class="pk-card">
			<h4><i class="fas fa-info-circle"></i> About</h4>
			<div class="pk-description-text"><?= nl2br(htmlspecialchars($description)) ?></div>
		</div>
		<?php endif; ?>

	</aside>

	<!-- ---- Tabbed Main ---- -->
	<div class="pk-main">
		<div class="pk-tabs">

			<!-- Tab navigation -->
			<ul class="pk-tab-nav">
				<li data-pktab="schedule" class="<?= $firstTab === 'schedule' ? 'pk-tab-active' : '' ?>">
					<i class="fas fa-calendar"></i> Schedule
					<?php if (count($parkDayList) > 0): ?>
						<span class="pk-tab-count">(<?= count($parkDayList) ?>)</span>
					<?php endif; ?>
				</li>
				<li data-pktab="events" class="<?= $firstTab === 'events' ? 'pk-tab-active' : '' ?>">
					<i class="fas fa-flag"></i> Events
					<span class="pk-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-pktab="players">
					<i class="fas fa-users"></i> Players
					<span class="pk-tab-count">(<?= count($allPlayers) ?>)</span>
				</li>
				<?php if ($totalHeraldry > 0): ?>
				<li data-pktab="heraldry">
					<i class="fas fa-shield-alt"></i> Hall of Arms
					<span class="pk-tab-count">(<?= $totalHeraldry ?>)</span>
				</li>
				<?php endif; ?>
				<li data-pktab="reports">
					<i class="fas fa-chart-bar"></i> Reports
				</li>
			</ul>

			<!-- Schedule Tab -->
			<div class="pk-tab-panel" id="pk-tab-schedule" <?= $firstTab !== 'schedule' ? 'style="display:none"' : '' ?>>
				<?php if (count($parkDayList) > 0): ?>
					<div class="pk-schedule-header">
						<?php if ($parkMapUrl): ?>
							<a class="pk-map-link" href="<?= $parkMapUrl ?>" target="_blank" rel="noopener">
								<i class="fas fa-map-marker-alt"></i> View on Google Maps
							</a>
						<?php endif; ?>
					</div>
					<?php if (!empty($directions)): ?>
					<div class="pk-directions-panel">
						<h5><i class="fas fa-directions"></i> Getting There</h5>
						<div class="pk-directions-text"><?= nl2br(htmlspecialchars($directions)) ?></div>
					</div>
					<?php endif; ?>
					<div class="pk-schedule-grid">
						<?php foreach ($parkDayList as $day): ?>
						<?php
							switch ($day['Recurrence']) {
								case 'weekly':        $recText = 'Every ' . $day['WeekDay']; break;
								case 'week-of-month': $recText = 'Every ' . shortScale::toDigith($day['WeekOfMonth']) . ' ' . $day['WeekDay']; break;
								case 'monthly':       $recText = 'Monthly on the ' . shortScale::toDigith($day['MonthDay']); break;
								default:              $recText = $day['Recurrence'];
							}
							switch ($day['Purpose']) {
								case 'fighter-practice': $purposeLabel = 'Fighter Practice'; $purposeCls = 'purpose-fighter'; $iconCls = 'icon-fighter'; $iconFa = 'fa-fist-raised'; break;
								case 'arts-day':         $purposeLabel = 'A&amp;S Day';       $purposeCls = 'purpose-arts';    $iconCls = 'icon-arts';    $iconFa = 'fa-palette';    break;
								case 'other':            $purposeLabel = 'Other';             $purposeCls = 'purpose-other';   $iconCls = 'icon-other';   $iconFa = 'fa-star';       break;
								default:                 $purposeLabel = 'Park Day';          $purposeCls = '';                $iconCls = '';             $iconFa = 'fa-shield-alt';
							}
							// Day-specific map URL
							$dayMapUrl = null;
							if (!empty($day['Location'])) {
								$dl = @json_decode(stripslashes($day['Location']));
								if ($dl) {
									$dlatlng = isset($dl->location) ? $dl->location : (isset($dl->bounds->northeast) ? $dl->bounds->northeast : null);
									if ($dlatlng && is_numeric($dlatlng->lat ?? null))
										$dayMapUrl = 'https://maps.google.com/maps?z=14&t=m&q=loc:' . $dlatlng->lat . '+' . $dlatlng->lng;
								}
							} elseif (!empty($day['MapUrl'])) {
								$dayMapUrl = $day['MapUrl'];
							} elseif ($parkMapUrl) {
								$dayMapUrl = $parkMapUrl;
							}
						?>
						<div class="pk-schedule-card">
							<div class="pk-schedule-icon <?= $iconCls ?>">
								<i class="fas <?= $iconFa ?>"></i>
							</div>
							<div class="pk-schedule-info">
								<div class="pk-schedule-when"><?= htmlspecialchars($recText) ?></div>
								<div class="pk-schedule-time"><?= date('g:i A', strtotime($day['Time'])) ?></div>
								<span class="pk-schedule-purpose <?= $purposeCls ?>"><?= $purposeLabel ?></span>
								<?php if (!empty($day['Address'])): ?>
									<div class="pk-schedule-address"><?= htmlspecialchars($day['Address']) ?></div>
								<?php endif; ?>
								<?php if ($dayMapUrl): ?>
									<a class="pk-schedule-map-link" href="<?= htmlspecialchars($dayMapUrl) ?>" target="_blank" rel="noopener">
										<i class="fas fa-map-marker-alt"></i> Map
									</a>
								<?php endif; ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				<?php else: ?>
					<div class="pk-empty">No park days scheduled</div>
				<?php endif; ?>
			</div>

			<!-- Events Tab -->
			<div class="pk-tab-panel" id="pk-tab-events" <?= $firstTab !== 'events' ? 'style="display:none"' : '' ?>>
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
					<h4 style="margin:0;font-size:14px;font-weight:700;color:#4a5568;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
					<?php if ($CanManagePark): ?>
					<a href="<?= UIR ?>Admin/manageevent&ParkId=<?= $park_id ?>" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;">
						<i class="fas fa-plus"></i> Add Event
					</a>
					<?php endif; ?>
				</div>
				<?php if (count($eventList) > 0): ?>
					<table class="pk-table" id="pk-events-table">
						<thead>
							<tr>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="date">Next Date</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
							<tr onclick='window.location.href="<?= UIR ?><?= $event['NextDetailId'] ? 'Eventnew/index/' . $event['EventId'] . '/' . $event['NextDetailId'] : 'Eventtemplatenew/index/' . $event['EventId'] ?>"'>
								<td>
									<div class="pk-tiny-heraldry">
										<?php if ($event['HasHeraldry'] == 1): ?>
											<img src="<?= HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $event['EventId'])) ?>"
											     onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'">
										<?php else: ?>
											<img src="<?= HTTP_EVENT_HERALDRY ?>00000.jpg">
										<?php endif; ?>
										<?= htmlspecialchars($event['Name']) ?>
									</div>
								</td>
								<td class="pk-date-col" data-sortval="<?= $event['NextDate'] ?>">
									<?= 0 == $event['NextDate'] ? '' : date('M. j, Y', strtotime($event['NextDate'])) ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="pk-pagination" id="pk-events-table-pages"></div>
				<?php else: ?>
					<div class="pk-empty">No events found</div>
				<?php endif; ?>

				<div style="display:flex;align-items:center;justify-content:space-between;margin:20px 0 10px;border-top:1px solid #e2e8f0;padding-top:16px;">
					<h4 style="margin:0;font-size:14px;font-weight:700;color:#4a5568;"><i class="fas fa-trophy" style="margin-right:6px;color:#a0aec0"></i>Tournaments</h4>
					<?php if ($CanManagePark): ?>
					<a href="<?= UIR ?>Tournament/create&KingdomId=<?= $kingdom_id ?>" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;">
						<i class="fas fa-plus"></i> Add Tournament
					</a>
					<?php endif; ?>
				</div>
				<?php if (count($tournamentList) > 0): ?>
					<table class="pk-table" id="pk-tournaments-table">
						<thead>
							<tr>
								<th data-sorttype="text">Tournament</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="date">Date</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($tournamentList as $t): ?>
							<tr onclick='window.location.href="<?= UIR ?>Tournament/worksheet/<?= $t['TournamentId'] ?>"'>
								<td><?= htmlspecialchars($t['Name']) ?></td>
								<td><?= htmlspecialchars($t['EventName']) ?></td>
								<td class="pk-date-col" data-sortval="<?= $t['DateTime'] ?>">
									<?= date('M. j, Y', strtotime($t['DateTime'])) ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="pk-pagination" id="pk-tournaments-table-pages"></div>
				<?php else: ?>
					<div class="pk-empty">No tournaments found</div>
				<?php endif; ?>
			</div>


			<!-- Players Tab -->
			<div class="pk-tab-panel" id="pk-tab-players" style="display:none">
				<?php if (count($allPlayers) > 0): ?>
					<div class="pk-players-toolbar">
						<span class="pk-players-toolbar-left">
							<?= count($playerPeriods[0] ?? []) ?> active member<?= count($playerPeriods[0] ?? []) != 1 ? 's' : '' ?> (past 6 months)<?php if (count($allPlayers) > count($playerPeriods[0] ?? [])): ?> &middot; <?= count($allPlayers) ?> total<?php endif; ?>
						</span>
						<div class="pk-players-toolbar-right">
							<div class="pk-player-search-wrap">
								<i class="fas fa-search pk-player-search-icon"></i>
								<input type="text" id="pk-player-search" class="pk-player-search-input" placeholder="Search all playersâ¦" autocomplete="off">
							</div>
							<div class="pk-view-toggle">
								<button class="pk-view-btn pk-view-active" data-pkview="cards">
									<i class="fas fa-th-large"></i> Cards
								</button>
								<button class="pk-view-btn" data-pkview="list">
									<i class="fas fa-list"></i> List
								</button>
							</div>
						</div>
					</div>

					<!-- Card view (default) -->
					<div id="pk-players-cards">
						<!-- Period 0 (0–6 months) always visible -->
						<div class="pk-players-grid">
							<?php foreach ($playerPeriods[0] ?? [] as $p): ?>
							<?php
								$initial = htmlspecialchars(strtoupper(mb_substr($p['Persona'], 0, 1)));
								$heraldryBgSrc = $p['HasHeraldry']
									? HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId']))
									: null;
								if ($p['HasImage']) {
									$avatarSrc = HTTP_PLAYER_IMAGE . Common::resolve_image_ext(DIR_PLAYER_IMAGE, sprintf('%06d', $p['MundaneId']));
								} elseif ($p['HasHeraldry']) {
									$avatarSrc = $heraldryBgSrc;
								} else {
									$avatarSrc = null;
								}
							?>
							<a class="pk-player-card<?= $heraldryBgSrc ? ' pk-player-card-hbg' : '' ?>"
							   <?= $heraldryBgSrc ? 'style="--hbg: url(\'' . htmlspecialchars($heraldryBgSrc) . '\')"'  : '' ?>
							   href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>">
								<div class="pk-player-card-top">
									<div class="pk-player-avatar">
										<?php if ($avatarSrc): ?>
											<img src="<?= htmlspecialchars($avatarSrc) ?>"
											     alt=""
											     onerror="pkAvatarFallback(this,'<?= $initial ?>')">
										<?php else: ?>
											<?= $initial ?>
										<?php endif; ?>
									</div>
									<div>
										<div class="pk-player-name"><?= htmlspecialchars($p['Persona']) ?></div>
										<?php if (!empty($p['OfficerRoles'])): ?>
											<?php foreach (explode(', ', $p['OfficerRoles']) as $role): ?>
												<span class="pk-officer-pill"><?= htmlspecialchars(trim($role)) ?></span>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
								</div>
								<div class="pk-player-stats">
									<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> <?= $p['SigninCount'] ?> sign-in<?= $p['SigninCount'] != 1 ? 's' : '' ?></span>
									<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> <?= date('M j', strtotime($p['LastSignin'])) ?></span>
									<?php if (!empty($p['LastClass'])): ?>
										<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> <?= htmlspecialchars($p['LastClass']) ?></span>
									<?php endif; ?>
								</div>
							</a>
							<?php endforeach; ?>
						</div>

						<!-- Period 1+ (hidden; revealed by Load More) -->
						<?php foreach (array_slice($playerPeriods, 1, null, true) as $pkPeriod => $pkPeriodPlayers): ?>
						<div class="pk-period-block" id="pk-players-block-<?= $pkPeriod ?>" style="display:none">
							<div class="pk-period-label"><?= $pkPeriod * 6 ?>–<?= ($pkPeriod + 1) * 6 ?> months ago</div>
							<div class="pk-players-grid">
								<?php foreach ($pkPeriodPlayers as $p): ?>
								<?php
									$initial = htmlspecialchars(strtoupper(mb_substr($p['Persona'], 0, 1)));
									$heraldryBgSrc = $p['HasHeraldry']
										? HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId']))
										: null;
									if ($p['HasImage']) {
										$avatarSrc = HTTP_PLAYER_IMAGE . Common::resolve_image_ext(DIR_PLAYER_IMAGE, sprintf('%06d', $p['MundaneId']));
									} elseif ($p['HasHeraldry']) {
										$avatarSrc = $heraldryBgSrc;
									} else {
										$avatarSrc = null;
									}
								?>
								<a class="pk-player-card<?= $heraldryBgSrc ? ' pk-player-card-hbg' : '' ?>"
								   <?= $heraldryBgSrc ? 'style="--hbg: url(\'' . htmlspecialchars($heraldryBgSrc) . '\')"'  : '' ?>
								   href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>">
									<div class="pk-player-card-top">
										<div class="pk-player-avatar">
											<?php if ($avatarSrc): ?>
												<img src="<?= htmlspecialchars($avatarSrc) ?>"
												     alt=""
												     onerror="pkAvatarFallback(this,'<?= $initial ?>')">
											<?php else: ?>
												<?= $initial ?>
											<?php endif; ?>
										</div>
										<div>
											<div class="pk-player-name"><?= htmlspecialchars($p['Persona']) ?></div>
											<?php if (!empty($p['OfficerRoles'])): ?>
												<?php foreach (explode(', ', $p['OfficerRoles']) as $role): ?>
													<span class="pk-officer-pill"><?= htmlspecialchars(trim($role)) ?></span>
												<?php endforeach; ?>
											<?php endif; ?>
										</div>
									</div>
									<div class="pk-player-stats">
										<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> <?= $p['SigninCount'] ?> sign-in<?= $p['SigninCount'] != 1 ? 's' : '' ?></span>
										<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> <?= date('M j', strtotime($p['LastSignin'])) ?></span>
										<?php if (!empty($p['LastClass'])): ?>
											<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> <?= htmlspecialchars($p['LastClass']) ?></span>
										<?php endif; ?>
									</div>
								</a>
								<?php endforeach; ?>
							</div>
						</div>
						<?php endforeach; ?>

						<?php if (count($playerPeriods) > 1): ?>
						<div class="pk-load-more-wrap" data-next="1" data-group="pk-players">
							<button class="pk-load-more-btn" onclick="pkLoadMoreCards('pk-players', this)">
								<i class="fas fa-chevron-down"></i> Load More...
							</button>
							<span class="pk-load-more-hint">Showing <?= count($playerPeriods[0] ?? []) ?> of <?= count($allPlayers) ?> members</span>
						</div>
						<?php endif; ?>
					</div><!-- /pk-players-cards -->

					<!-- List view (hidden by default) -->
					<div id="pk-players-list" style="display:none">
						<table class="pk-table" id="pk-players-table">
							<thead>
								<tr>
									<th data-sorttype="text">Persona</th>
									<th data-sorttype="numeric">Sign-ins</th>
									<th data-sorttype="date">Last Visit</th>
									<th data-sorttype="text">Last Class</th>
									<th data-sorttype="text">Role</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($playerPeriods[0] ?? [] as $p): ?>
								<tr onclick='window.location.href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>"'>
									<td>
										<?= htmlspecialchars($p['Persona']) ?>
										<?php if (!empty($p['OfficerRoles'])): ?>
											<?php foreach (explode(', ', $p['OfficerRoles']) as $role): ?>
												<span class="pk-officer-pill"><?= htmlspecialchars(trim($role)) ?></span>
											<?php endforeach; ?>
										<?php endif; ?>
									</td>
									<td data-sortval="<?= $p['SigninCount'] ?>"><?= $p['SigninCount'] ?></td>
									<td class="pk-date-col" data-sortval="<?= $p['LastSignin'] ?>">
										<?= date('M j, Y', strtotime($p['LastSignin'])) ?>
									</td>
									<td><?= htmlspecialchars($p['LastClass'] ?? '') ?></td>
									<td><?= htmlspecialchars($p['OfficerRoles'] ?? '') ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<!-- Hidden row templates for older periods -->
						<?php foreach (array_slice($playerPeriods, 1, null, true) as $pkPeriod => $pkPeriodPlayers): ?>
						<template id="pk-players-tmpl-<?= $pkPeriod ?>">
							<?php foreach ($pkPeriodPlayers as $p): ?>
							<tr onclick='window.location.href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>"'>
								<td>
									<?= htmlspecialchars($p['Persona']) ?>
									<?php if (!empty($p['OfficerRoles'])): ?>
										<?php foreach (explode(', ', $p['OfficerRoles']) as $role): ?>
											<span class="pk-officer-pill"><?= htmlspecialchars(trim($role)) ?></span>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td data-sortval="<?= $p['SigninCount'] ?>"><?= $p['SigninCount'] ?></td>
								<td class="pk-date-col" data-sortval="<?= $p['LastSignin'] ?>">
									<?= date('M j, Y', strtotime($p['LastSignin'])) ?>
								</td>
								<td><?= htmlspecialchars($p['LastClass'] ?? '') ?></td>
								<td><?= htmlspecialchars($p['OfficerRoles'] ?? '') ?></td>
							</tr>
							<?php endforeach; ?>
						</template>
						<?php endforeach; ?>
						<?php if (count($playerPeriods) > 1): ?>
						<div class="pk-load-more-wrap pk-load-more-list" data-next="1">
							<button class="pk-load-more-btn" onclick="pkLoadMoreList('pk-players-table', 'pk-players-tmpl', this)">
								<i class="fas fa-chevron-down"></i> Load More...
							</button>
							<span class="pk-load-more-hint">Showing <?= count($playerPeriods[0] ?? []) ?> of <?= count($allPlayers) ?> members</span>
						</div>
						<?php endif; ?>
						<div class="pk-pagination" id="pk-players-table-pages"></div>
					</div><!-- /pk-players-list -->
				<?php else: ?>
					<div class="pk-empty">No players found</div>
				<?php endif; ?>
			</div><!-- /pk-tab-players -->

			<!-- Hall of Arms Tab -->
			<?php if ($totalHeraldry > 0): ?>
			<div class="pk-tab-panel" id="pk-tab-heraldry" style="display:none">
				<div class="pk-players-toolbar">
					<span class="pk-players-toolbar-left">
						<?= count($heraldryPeriods[0] ?? []) ?> device<?= count($heraldryPeriods[0] ?? []) != 1 ? 's' : '' ?> (past 6 months)<?php if ($totalHeraldry > count($heraldryPeriods[0] ?? [])): ?> &middot; <?= $totalHeraldry ?> total<?php endif; ?>
					</span>
				</div>
				<!-- Period 0 (0–6 months) -->
				<div class="pk-hoa-grid" id="pk-hoa-grid-0">
					<?php foreach ($heraldryPeriods[0] ?? [] as $p): ?>
					<a class="pk-hoa-card" href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>">
						<img class="pk-hoa-heraldry"
						     src="<?= HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId'])) ?>"
						     alt="<?= htmlspecialchars($p['Persona']) ?>"
						     onerror="this.closest('.pk-hoa-card').style.display='none'">
						<div class="pk-hoa-name"><?= htmlspecialchars($p['Persona']) ?></div>
						<?php if (!empty($p['OfficerRoles'])): ?>
							<span class="pk-officer-pill"><?= htmlspecialchars(explode(', ', $p['OfficerRoles'])[0]) ?></span>
						<?php endif; ?>
					</a>
					<?php endforeach; ?>
				</div>
				<!-- Period 1+ (hidden; revealed by Load More) -->
				<?php foreach (array_slice($heraldryPeriods, 1, null, true) as $hoaPeriod => $hoaPlayers): ?>
				<div class="pk-period-block" id="pk-hoa-block-<?= $hoaPeriod ?>" style="display:none">
					<div class="pk-period-label"><?= $hoaPeriod * 6 ?>–<?= ($hoaPeriod + 1) * 6 ?> months ago</div>
					<div class="pk-hoa-grid">
						<?php foreach ($hoaPlayers as $p): ?>
						<a class="pk-hoa-card" href="<?= UIR ?>Player/index/<?= $p['MundaneId'] ?>">
							<img class="pk-hoa-heraldry"
							     src="<?= HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId'])) ?>"
							     alt="<?= htmlspecialchars($p['Persona']) ?>"
							     onerror="this.closest('.pk-hoa-card').style.display='none'">
							<div class="pk-hoa-name"><?= htmlspecialchars($p['Persona']) ?></div>
							<?php if (!empty($p['OfficerRoles'])): ?>
								<span class="pk-officer-pill"><?= htmlspecialchars(explode(', ', $p['OfficerRoles'])[0]) ?></span>
							<?php endif; ?>
						</a>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endforeach; ?>
				<?php if (count($heraldryPeriods) > 1): ?>
				<div class="pk-load-more-wrap" data-next="1" data-group="pk-hoa">
					<button class="pk-load-more-btn" onclick="pkLoadMoreCards('pk-hoa', this)">
						<i class="fas fa-chevron-down"></i> Load More...
					</button>
					<span class="pk-load-more-hint">Showing <?= count($heraldryPeriods[0] ?? []) ?> of <?= $totalHeraldry ?> devices</span>
				</div>
				<?php endif; ?>
			</div><!-- /pk-tab-heraldry -->
			<?php endif; ?>

			<!-- Reports Tab -->
			<div class="pk-tab-panel" id="pk-tab-reports" style="display:none">
				<div class="pk-reports-grid">
					<div class="pk-reports-section">
						<h5>Players</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/roster/Park&id=<?= $park_id ?>">Player Roster</a></li>
							<li><a href="<?= UIR ?>Reports/inactive/Park&id=<?= $park_id ?>">Inactive Players</a></li>
							<li><a href="<?= UIR ?>Reports/active/Park&id=<?= $park_id ?>">Active Players</a></li>
							<li><a href="<?= UIR ?>Reports/dues/Park&id=<?= $park_id ?>">Dues Paid</a></li>
							<li><a href="<?= UIR ?>Reports/waivered/Park&id=<?= $park_id ?>">Waivered Players</a></li>
							<li><a href="<?= UIR ?>Reports/unwaivered/Park&id=<?= $park_id ?>">Unwaivered Players</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Park&id=<?= $park_id ?>">Suspended Players</a></li>
							<li><a href="<?= UIR ?>Reports/active_duespaid/Park&id=<?= $park_id ?>">Player Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/active_waivered_duespaid/Park&id=<?= $park_id ?>">Waivered Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/reeve&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Corpora Qualified</a></li>
						</ul>
					</div>
					<div class="pk-reports-section">
						<h5>Attendance</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/3">Past 3 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/6">Past 6 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/All">All Time</a></li>
						</ul>
					</div>
					<div class="pk-reports-section">
						<h5>Awards</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Recommendations</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=0&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Player Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Class Masters</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Park Guilds</a></li>
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Custom Awards</a></li>
						</ul>
					</div>
				</div>
			</div>

		</div><!-- /pk-tabs -->
	</div><!-- /pk-main -->

</div><!-- /pk-layout -->

<!-- =============================================
     JavaScript
     ============================================= -->
<script type="text/javascript">

// ---- Player avatar image fallback ----
// Called onerror on player images; hides the img and shows the initial letter instead.
function pkAvatarFallback(img, initial) {
	img.style.display = 'none';
	img.parentElement.innerText = initial;
}

// ---- Load More: card view (reveals next hidden period block) ----
// group: prefix string like 'pk-players' or 'pk-hoa'
// btn:   the button element inside .pk-load-more-wrap[data-next][data-group]
function pkLoadMoreCards(group, btn) {
	var $wrap = $(btn).closest('.pk-load-more-wrap');
	var next  = parseInt($wrap.attr('data-next') || '1');
	var $block = $('#' + group + '-block-' + next);
	if ($block.length) {
		$block.show();
		var newNext = next + 1;
		$wrap.attr('data-next', newNext);
		if (!$('#' + group + '-block-' + newNext).length) {
			$wrap.hide(); // no more periods to load
		}
	} else {
		$wrap.hide();
	}
}

// ---- Load More: list view (appends template rows to table, re-paginates) ----
// tableId:  id of the <table> element
// tmplBase: id prefix of <template> elements (e.g. 'pk-players-tmpl')
// btn:      the button element inside .pk-load-more-wrap[data-next]
function pkLoadMoreList(tableId, tmplBase, btn) {
	var $wrap  = $(btn).closest('.pk-load-more-wrap');
	var next   = parseInt($wrap.attr('data-next') || '1');
	var tmpl   = document.getElementById(tmplBase + '-' + next);
	if (tmpl) {
		var $tbody = $('#' + tableId + ' tbody');
		// Clone template content and append to tbody
		var frag = document.importNode(tmpl.content, true);
		$(frag.querySelectorAll('tr')).appendTo($tbody);
		var newNext = next + 1;
		$wrap.attr('data-next', newNext);
		// Re-paginate from page 1 with the expanded row set
		pkPaginate($('#' + tableId), 1);
		if (!document.getElementById(tmplBase + '-' + newNext)) {
			$wrap.hide();
		}
	} else {
		$wrap.hide();
	}
}

// ---- Hero color from heraldry ----
// Samples the heraldry image via Canvas to find its dominant non-white, non-black
// color and applies a darkened version as the hero background.
function pkApplyHeroColor(img) {
	var canvas = document.createElement('canvas');
	canvas.width = 60; canvas.height = 60;
	var ctx = canvas.getContext('2d');
	try {
		ctx.drawImage(img, 0, 0, 60, 60);
		var px = ctx.getImageData(0, 0, 60, 60).data;
		var buckets = {};
		for (var i = 0; i < px.length; i += 4) {
			var r = px[i], g = px[i+1], b = px[i+2], a = px[i+3];
			if (a < 120) continue;
			if (r > 215 && g > 215 && b > 215) continue;
			if (r < 25  && g < 25  && b < 25)  continue;
			var key = (r >> 4) + ',' + (g >> 4) + ',' + (b >> 4);
			buckets[key] = (buckets[key] || 0) + 1;
		}
		var best = null, bestN = 0;
		for (var k in buckets) { if (buckets[k] > bestN) { bestN = buckets[k]; best = k; } }
		if (!best) return;
		var parts = best.split(',');
		var dr = parseInt(parts[0]) * 16 + 8;
		var dg = parseInt(parts[1]) * 16 + 8;
		var db = parseInt(parts[2]) * 16 + 8;
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
		var finalS = Math.max(s, 0.28);
		var heroEl = document.querySelector('.pk-hero');
		if (heroEl) {
			heroEl.style.backgroundColor =
				'hsl(' + Math.round(h*360) + ',' + Math.round(finalS*100) + '%,18%)';
		}
	} catch(e) { /* CORS or tainted canvas — keep default green */ }
}

// ---- Tab activation ----
function pkActivateTab(tab) {
	$('.pk-tab-nav li').removeClass('pk-tab-active');
	$('.pk-tab-nav li[data-pktab="' + tab + '"]').addClass('pk-tab-active');
	$('.pk-tab-panel').hide();
	$('#pk-tab-' + tab).show();
	$('html, body').animate({ scrollTop: $('.pk-tabs').offset().top - 20 }, 250);
}

// ---- Pagination helpers ----
function pkPageRange(current, total) {
	var pages = [];
	if (total <= 7) {
		for (var p = 1; p <= total; p++) pages.push(p);
	} else {
		pages.push(1);
		if (current > 3) pages.push(-1);
		for (var p = Math.max(2, current-1); p <= Math.min(total-1, current+1); p++) pages.push(p);
		if (current < total - 2) pages.push(-1);
		pages.push(total);
	}
	return pages;
}

function pkRenderPagination($table, current, total, containerId) {
	var $c = $('#' + containerId);
	$c.empty();
	if (total <= 1) return;
	var pages = pkPageRange(current, total);
	var prevDisabled = current === 1 ? ' pk-page-disabled' : '';
	$c.append('<span class="pk-page-btn pk-page-prev' + prevDisabled + '">&#8249;</span>');
	for (var i = 0; i < pages.length; i++) {
		if (pages[i] === -1) {
			$c.append('<span class="pk-page-ellipsis">&hellip;</span>');
		} else {
			var active = pages[i] === current ? ' pk-page-active' : '';
			$c.append('<span class="pk-page-btn pk-page-num' + active + '" data-page="' + pages[i] + '">' + pages[i] + '</span>');
		}
	}
	var nextDisabled = current === total ? ' pk-page-disabled' : '';
	$c.append('<span class="pk-page-btn pk-page-next' + nextDisabled + '">&#8250;</span>');
}

function pkPaginate($table, page) {
	var perPage = 10;
	var $rows = $table.find('tbody tr');
	var total = Math.ceil($rows.length / perPage);
	if (total <= 1) return;
	$rows.hide();
	$rows.slice((page-1)*perPage, page*perPage).show();
	var containerId = $table.attr('id') + '-pages';
	pkRenderPagination($table, page, total, containerId);
	$table.data('pk-page', page);
	$table.data('pk-total', total);
}

// ---- Sort helpers ----
function pkSortDesc($table, colIdx, type) {
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').toArray();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIdx).data('sortval') || $(a).find('td').eq(colIdx).text().trim();
		var bVal = $(b).find('td').eq(colIdx).data('sortval') || $(b).find('td').eq(colIdx).text().trim();
		if (type === 'date') {
			return new Date(bVal) - new Date(aVal);
		} else if (type === 'numeric') {
			return parseFloat(bVal) - parseFloat(aVal);
		} else {
			return bVal.localeCompare(aVal);
		}
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

function pkSortTable($table, colIdx, type, dir) {
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').toArray();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIdx).data('sortval') || $(a).find('td').eq(colIdx).text().trim();
		var bVal = $(b).find('td').eq(colIdx).data('sortval') || $(b).find('td').eq(colIdx).text().trim();
		var cmp;
		if (type === 'date') {
			cmp = new Date(aVal) - new Date(bVal);
		} else if (type === 'numeric') {
			cmp = parseFloat(aVal) - parseFloat(bVal);
		} else {
			cmp = aVal.localeCompare(bVal);
		}
		return dir === 'desc' ? -cmp : cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

$(document).ready(function() {

	// ---- Hero color from heraldry ----
	var pkHeraldryImg = document.querySelector('.pk-heraldry-frame img');
	if (pkHeraldryImg) {
		if (pkHeraldryImg.complete && pkHeraldryImg.naturalWidth) {
			pkApplyHeroColor(pkHeraldryImg);
		} else {
			pkHeraldryImg.addEventListener('load', function() { pkApplyHeroColor(this); });
		}
	}

	// ---- Tab switching ----
	$('.pk-tab-nav li').on('click', function() {
		pkActivateTab($(this).attr('data-pktab'));
	});

	// ---- Sortable table headers ----
	$('.pk-table thead th').on('click', function() {
		var $th = $(this);
		var $table = $th.closest('table');
		var colIdx = $th.index();
		var type = $th.attr('data-sorttype') || 'text';
		var curDir = $th.data('sortdir') || 'none';
		var newDir = (curDir === 'asc') ? 'desc' : 'asc';
		$table.find('thead th').removeClass('pk-sort-asc pk-sort-desc').removeData('sortdir');
		$th.addClass('pk-sort-' + newDir).data('sortdir', newDir);
		pkSortTable($table, colIdx, type, newDir);
		var page = $table.data('pk-page') || 1;
		pkPaginate($table, 1);
	});

	// ---- Pagination click handlers ----
	$(document).on('click', '.pk-page-num', function() {
		var page = parseInt($(this).data('page'));
		var $table = $(this).closest('.pk-tab-panel').find('.pk-table');
		pkPaginate($table, page);
	});
	$(document).on('click', '.pk-page-prev:not(.pk-page-disabled)', function() {
		var $table = $(this).closest('.pk-tab-panel').find('.pk-table');
		var page = Math.max(1, ($table.data('pk-page') || 1) - 1);
		pkPaginate($table, page);
	});
	$(document).on('click', '.pk-page-next:not(.pk-page-disabled)', function() {
		var $table = $(this).closest('.pk-tab-panel').find('.pk-table');
		var total = $table.data('pk-total') || 1;
		var page = Math.min(total, ($table.data('pk-page') || 1) + 1);
		pkPaginate($table, page);
	});

	// ---- Player search (filters all .pk-hoa-card across all periods) ----
	$('#pk-player-search').on('input', function() {
		var q = $(this).val().trim().toLowerCase();
		if (q === '') {
			// Restore: show all sections and cards
			$('.pk-hoa-section').removeClass('pk-search-hidden');
			$('.pk-hoa-card').show();
		} else {
			$('.pk-hoa-card').each(function() {
				var name = $(this).find('.pk-hoa-name').text().toLowerCase();
				$(this).toggle(name.indexOf(q) !== -1);
			});
			// Hide period section headings that have no visible cards
			$('.pk-hoa-section').each(function() {
				var hasVisible = $(this).find('.pk-hoa-card:visible').length > 0;
				$(this).toggleClass('pk-search-hidden', !hasVisible);
			});
		}
	});

	// ---- Players view toggle (cards / list) ----
	$('[data-pkview]').on('click', function() {
		var view = $(this).attr('data-pkview');
		$('[data-pkview]').removeClass('pk-view-active');
		$(this).addClass('pk-view-active');
		if (view === 'list') {
			$('#pk-players-cards').hide();
			$('#pk-players-list').show();
		} else {
			$('#pk-players-list').hide();
			$('#pk-players-cards').show();
		}
	});

	// ---- Default sort + paginate ----
	<?php if (count($eventList) > 0): ?>
	pkSortDesc($('#pk-events-table'), 1, 'date');
	pkPaginate($('#pk-events-table'), 1);
	<?php endif; ?>
	<?php if (count($tournamentList) > 0): ?>
	pkSortDesc($('#pk-tournaments-table'), 2, 'date');
	pkPaginate($('#pk-tournaments-table'), 1);
	<?php endif; ?>
	<?php if (count($playerList) > 0): ?>
	pkPaginate($('#pk-players-table'), 1);
	<?php endif; ?>

});
</script>
<?php if ($LoggedIn): ?>
<div id="pk-award-overlay">
	<div class="pk-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title" id="pk-award-modal-title"><i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award</h3>
			<button class="pk-modal-close-btn" id="pk-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body" id="pk-award-modal-body">
			<div class="pk-award-success" id="pk-award-success" style="display:none">
				<i class="fas fa-check-circle"></i> Award saved!
			</div>
			<div class="pk-form-error" id="pk-award-error"></div>

			<!-- Award Type Toggle -->
			<div class="pk-award-type-row">
				<button type="button" class="pk-award-type-btn pk-active" id="pk-award-type-awards">
					<i class="fas fa-medal" style="margin-right:5px"></i>Awards
				</button>
				<button type="button" class="pk-award-type-btn" id="pk-award-type-officers">
					<i class="fas fa-crown" style="margin-right:5px"></i>Officer Titles
				</button>
			</div>

			<!-- Player search -->
			<div class="pk-acct-field">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-award-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="pk-award-player-id" value="" />
				<div class="pk-ac-results" id="pk-award-player-results"></div>
			</div>

			<!-- Award Select -->
			<div class="pk-acct-field">
				<label for="pk-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="pk-award-select" name="KingdomAwardId">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
				<div class="pk-award-info-line" id="pk-award-info-line"></div>
			</div>

			<!-- Custom Award Name -->
			<div class="pk-acct-field" id="pk-award-custom-row" style="display:none">
				<label for="pk-award-custom-name">Custom Award Name</label>
				<input type="text" id="pk-award-custom-name" maxlength="64" placeholder="Enter custom award name..." />
			</div>

			<!-- Rank Picker -->
			<div class="pk-acct-field" id="pk-award-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
				<div class="pk-rank-pills-wrap" id="pk-rank-pills"></div>
				<input type="hidden" id="pk-award-rank-val" value="" />
			</div>

			<!-- Date -->
			<div class="pk-acct-field">
				<label for="pk-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" id="pk-award-date" />
			</div>

			<!-- Given By -->
			<div class="pk-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="pk-officer-chips" id="pk-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="pk-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="pk-award-givenby-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="pk-award-givenby-id" value="" />
				<div class="pk-ac-results" id="pk-award-givenby-results"></div>
			</div>

			<!-- Given At -->
			<div class="pk-acct-field">
				<label>Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="pk-award-givenat-text"
				       placeholder="Search park, kingdom, or event..."
				       autocomplete="off"
				       value="<?= htmlspecialchars($park_name ?? '') ?>" />
				<div class="pk-ac-results" id="pk-award-givenat-results"></div>
				<input type="hidden" id="pk-award-park-id" value="<?= (int)$park_id ?>" />
				<input type="hidden" id="pk-award-kingdom-id" value="0" />
				<input type="hidden" id="pk-award-event-id" value="0" />
			</div>

			<!-- Note -->
			<div class="pk-acct-field">
				<label for="pk-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea id="pk-award-note" rows="3" maxlength="400" placeholder="What was this award given for?"></textarea>
				<span class="pk-char-count" id="pk-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn-ghost" id="pk-award-cancel">Close</button>
			<div style="display:flex;gap:8px">
				<button class="pk-btn pk-btn-secondary" id="pk-award-save-same" disabled>
					<i class="fas fa-plus"></i> Add + Same Player
				</button>
				<button class="pk-btn pk-btn-primary" id="pk-award-save-new" disabled>
					<i class="fas fa-plus"></i> Add + New Player
				</button>
			</div>
		</div>
	</div>
</div>
<script>
(function() {
	var UIR_JS     = <?= json_encode(UIR) ?>;
	var SEARCH_URL = '<?= HTTP_SERVICE ?>Search/SearchService.php';
	var KINGDOM_ID = <?= (int)$kingdom_id ?>;
	var PARK_ID    = <?= (int)$park_id ?>;
	var awardOptHTML   = <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>;
	var officerOptHTML = <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>;
	var currentType = 'awards';
	var givenByTimer, givenAtTimer, playerTimer;

	function gid(id) { return document.getElementById(id); }

	function checkRequired() {
		var ok = !!gid('pk-award-player-id').value
		      && !!gid('pk-award-select').value
		      && !!gid('pk-award-givenby-id').value
		      && !!gid('pk-award-date').value;
		gid('pk-award-save-new').disabled  = !ok;
		gid('pk-award-save-same').disabled = !ok;
	}

	function setAwardType(type) {
		currentType = type;
		var isOfficer = type === 'officers';
		gid('pk-award-modal-title').innerHTML = isOfficer
			? '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title'
			: '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award';
		gid('pk-award-select').innerHTML = isOfficer ? officerOptHTML : awardOptHTML;
		gid('pk-award-rank-row').style.display   = 'none';
		gid('pk-award-rank-val').value           = '';
		gid('pk-award-info-line').innerHTML      = '';
		gid('pk-award-type-awards').classList.toggle('pk-active', !isOfficer);
		gid('pk-award-type-officers').classList.toggle('pk-active', isOfficer);
		checkRequired();
	}

	gid('pk-award-type-awards').addEventListener('click',   function() { setAwardType('awards'); });
	gid('pk-award-type-officers').addEventListener('click', function() { setAwardType('officers'); });

	function buildRankPills(awardId) {
		var row   = gid('pk-award-rank-row');
		var wrap  = gid('pk-rank-pills');
		var input = gid('pk-award-rank-val');
		wrap.innerHTML = '';
		input.value = '';
		row.style.display = 'none';
		if (!awardId) return;
		var opt = gid('pk-award-select').querySelector('option[value="' + awardId + '"]');
		if (!opt || opt.getAttribute('data-is-ladder') !== '1') return;
		row.style.display = '';
		for (var r = 1; r <= 10; r++) {
			var pill = document.createElement('button');
			pill.type      = 'button';
			pill.className = 'pk-rank-pill';
			pill.textContent = r;
			pill.dataset.rank = r;
			pill.addEventListener('click', (function(rank, el) {
				return function() {
					document.querySelectorAll('#pk-rank-pills .pk-rank-pill').forEach(function(p) { p.classList.remove('pk-rank-selected'); });
					el.classList.add('pk-rank-selected');
					input.value = rank;
				};
			})(r, pill));
			wrap.appendChild(pill);
		}
	}

	gid('pk-award-select').addEventListener('change', function() {
		var awardId = this.value;
		var isCustom = this.options[this.selectedIndex] && this.options[this.selectedIndex].text.toLowerCase().indexOf('custom') >= 0;
		gid('pk-award-custom-row').style.display = isCustom ? '' : 'none';
		buildRankPills(awardId);
		var infoEl = gid('pk-award-info-line');
		if (awardId) {
			var opt = this.querySelector('option[value="' + awardId + '"]');
			infoEl.innerHTML = opt && opt.getAttribute('data-is-ladder') === '1'
				? '<span class="pk-badge-ladder"><i class="fas fa-layer-group"></i> Ladder Award</span>'
				: '';
		} else { infoEl.innerHTML = ''; }
		checkRequired();
	});

	// Player search autocomplete
	gid('pk-award-player-text').addEventListener('input', function() {
		gid('pk-award-player-id').value = '';
		checkRequired();
		var term = this.value.trim();
		if (term.length < 2) { gid('pk-award-player-results').classList.remove('pk-ac-open'); return; }
		clearTimeout(playerTimer);
		playerTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=8';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('pk-award-player-results');
				el.innerHTML = (data && data.length)
					? data.map(function(p) {
						return '<div class="pk-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
							+ p.Persona + ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr||'') + ':' + (p.PAbbr||'') + ')</span></div>';
					}).join('')
					: '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
				el.classList.add('pk-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('pk-award-player-results').addEventListener('click', function(e) {
		var item = e.target.closest('.pk-ac-item[data-id]');
		if (!item) return;
		gid('pk-award-player-text').value = decodeURIComponent(item.dataset.name);
		gid('pk-award-player-id').value   = item.dataset.id;
		this.classList.remove('pk-ac-open');
		checkRequired();
	});

	// Given By — officer chips + search
	<?php if (!empty($PreloadOfficers)): ?>
	document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(c) { c.classList.remove('pk-selected'); });
			this.classList.add('pk-selected');
			gid('pk-award-givenby-text').value = this.dataset.name;
			gid('pk-award-givenby-id').value   = this.dataset.id;
			gid('pk-award-givenby-results').classList.remove('pk-ac-open');
			checkRequired();
		});
	});
	<?php endif; ?>
	gid('pk-award-givenby-text').addEventListener('input', function() {
		gid('pk-award-givenby-id').value = '';
		document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(c) { c.classList.remove('pk-selected'); });
		checkRequired();
		var term = this.value.trim();
		if (term.length < 2) { gid('pk-award-givenby-results').classList.remove('pk-ac-open'); return; }
		clearTimeout(givenByTimer);
		givenByTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('pk-award-givenby-results');
				el.innerHTML = (data && data.length)
					? data.map(function(p) {
						return '<div class="pk-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
							+ p.Persona + ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr||'') + ':' + (p.PAbbr||'') + ')</span></div>';
					}).join('')
					: '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No results</div>';
				el.classList.add('pk-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('pk-award-givenby-results').addEventListener('click', function(e) {
		var item = e.target.closest('.pk-ac-item[data-id]');
		if (!item) return;
		gid('pk-award-givenby-text').value = decodeURIComponent(item.dataset.name);
		gid('pk-award-givenby-id').value   = item.dataset.id;
		this.classList.remove('pk-ac-open');
		checkRequired();
	});

	// Given At — location search
	gid('pk-award-givenat-text').addEventListener('input', function() {
		var term = this.value.trim();
		if (term.length < 2) { gid('pk-award-givenat-results').classList.remove('pk-ac-open'); return; }
		clearTimeout(givenAtTimer);
		givenAtTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FLocation&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('pk-award-givenat-results');
				el.innerHTML = (data && data.length)
					? data.map(function(loc) {
						return '<div class="pk-ac-item" data-pid="' + (loc.ParkId||0) + '" data-kid="' + (loc.KingdomId||0) + '" data-eid="' + (loc.EventId||0) + '" data-name="' + encodeURIComponent(loc.LocationName||loc.ShortName||'') + '">'
							+ (loc.LocationName || loc.ShortName || '') + '</div>';
					}).join('')
					: '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No locations found</div>';
				el.classList.add('pk-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('pk-award-givenat-results').addEventListener('click', function(e) {
		var item = e.target.closest('.pk-ac-item');
		if (!item || !item.dataset.name) return;
		gid('pk-award-givenat-text').value    = decodeURIComponent(item.dataset.name);
		gid('pk-award-park-id').value         = item.dataset.pid || '0';
		gid('pk-award-kingdom-id').value      = item.dataset.kid || '0';
		gid('pk-award-event-id').value        = item.dataset.eid || '0';
		this.classList.remove('pk-ac-open');
	});

	// Note char counter
	gid('pk-award-note').addEventListener('input', function() {
		var rem = 400 - this.value.length;
		var el  = gid('pk-award-char-count');
		el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
	});

	gid('pk-award-select').addEventListener('change', checkRequired);
	gid('pk-award-date').addEventListener('change', checkRequired);
	gid('pk-award-date').addEventListener('input',  checkRequired);

	// ---- Open / Close ----
	window.pkOpenAwardModal = function() {
		var today = new Date();
		gid('pk-award-error').style.display      = 'none';
		gid('pk-award-error').textContent        = '';
		gid('pk-award-success').style.display    = 'none';
		gid('pk-award-player-text').value        = '';
		gid('pk-award-player-id').value          = '';
		gid('pk-award-player-results').classList.remove('pk-ac-open');
		gid('pk-award-note').value               = '';
		gid('pk-award-char-count').textContent   = '400 characters remaining';
		gid('pk-award-givenby-text').value       = '';
		gid('pk-award-givenby-id').value         = '';
		gid('pk-award-givenby-results').classList.remove('pk-ac-open');
		gid('pk-award-givenat-text').value       = <?= json_encode($park_name ?? '') ?>;
		gid('pk-award-park-id').value            = '<?= (int)$park_id ?>';
		gid('pk-award-kingdom-id').value         = '0';
		gid('pk-award-event-id').value           = '0';
		gid('pk-award-givenat-results').classList.remove('pk-ac-open');
		gid('pk-award-custom-name').value        = '';
		gid('pk-award-custom-row').style.display = 'none';
		gid('pk-award-rank-row').style.display   = 'none';
		gid('pk-award-rank-val').value           = '';
		gid('pk-award-info-line').innerHTML      = '';
		document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(c) { c.classList.remove('pk-selected'); });
		gid('pk-award-date').value = today.getFullYear() + '-'
			+ String(today.getMonth() + 1).padStart(2, '0') + '-'
			+ String(today.getDate()).padStart(2, '0');
		setAwardType('awards');
		checkRequired();
		gid('pk-award-overlay').classList.add('pk-open');
		document.body.style.overflow = 'hidden';
		gid('pk-award-player-text').focus();
	};
	window.pkCloseAwardModal = function() {
		gid('pk-award-overlay').classList.remove('pk-open');
		document.body.style.overflow = '';
	};

	gid('pk-award-close-btn').addEventListener('click', pkCloseAwardModal);
	gid('pk-award-cancel').addEventListener('click',    pkCloseAwardModal);
	gid('pk-award-overlay').addEventListener('click', function(e) {
		if (e.target === this) pkCloseAwardModal();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('pk-award-overlay').classList.contains('pk-open'))
			pkCloseAwardModal();
	});

	// ---- Save helpers ----
	var pkSuccessTimer = null;
	function pkShowSuccess() {
		var el = gid('pk-award-success');
		el.style.display = '';
		clearTimeout(pkSuccessTimer);
		pkSuccessTimer = setTimeout(function() { el.style.display = 'none'; }, 3000);
	}
	function pkClearPlayer() {
		gid('pk-award-player-text').value = '';
		gid('pk-award-player-id').value   = '';
		gid('pk-award-player-results').classList.remove('pk-ac-open');
	}
	function pkClearAward() {
		gid('pk-award-select').value             = '';
		gid('pk-award-rank-val').value           = '';
		gid('pk-award-rank-row').style.display   = 'none';
		gid('pk-rank-pills').innerHTML           = '';
		gid('pk-award-note').value               = '';
		gid('pk-award-char-count').textContent   = '400 characters remaining';
		gid('pk-award-info-line').innerHTML      = '';
		gid('pk-award-custom-name').value        = '';
		gid('pk-award-custom-row').style.display = 'none';
		checkRequired();
	}
	function pkDoSave(onSuccess) {
		var errEl    = gid('pk-award-error');
		var playerId = gid('pk-award-player-id').value;
		var awardId  = gid('pk-award-select').value;
		var giverId  = gid('pk-award-givenby-id').value;
		var date     = gid('pk-award-date').value;

		errEl.style.display = 'none';
		if (!playerId) { errEl.textContent = 'Please select a player.';             errEl.style.display = ''; return; }
		if (!awardId)  { errEl.textContent = 'Please select an award.';             errEl.style.display = ''; return; }
		if (!giverId)  { errEl.textContent = 'Please select who gave this award.';  errEl.style.display = ''; return; }
		if (!date)     { errEl.textContent = 'Please enter the award date.';        errEl.style.display = ''; return; }

		var fd = new FormData();
		fd.append('KingdomAwardId', awardId);
		fd.append('GivenById',      giverId);
		fd.append('Date',           date);
		fd.append('ParkId',         gid('pk-award-park-id').value    || '0');
		fd.append('KingdomId',      gid('pk-award-kingdom-id').value || '0');
		fd.append('EventId',        gid('pk-award-event-id').value   || '0');
		fd.append('Note',           gid('pk-award-note').value       || '');
		var rank = gid('pk-award-rank-val').value;
		if (rank) fd.append('Rank', rank);
		var customName = gid('pk-award-custom-name') ? gid('pk-award-custom-name').value.trim() : '';
		if (customName) fd.append('AwardName', customName);

		var btnNew  = gid('pk-award-save-new');
		var btnSame = gid('pk-award-save-same');
		btnNew.disabled = btnSame.disabled = true;
		btnNew.innerHTML  = '<i class="fas fa-spinner fa-spin"></i>';
		btnSame.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

		var saveUrl = UIR_JS + 'Admin/player/' + playerId + '/addaward';
		fetch(saveUrl, { method: 'POST', body: fd })
			.then(function(resp) {
				if (!resp.ok) throw new Error('Server returned ' + resp.status);
				onSuccess();
			})
			.catch(function(err) {
				errEl.textContent = 'Save failed: ' + err.message;
				errEl.style.display = '';
			})
			.finally(function() {
				btnNew.innerHTML  = '<i class="fas fa-plus"></i> Add + New Player';
				btnSame.innerHTML = '<i class="fas fa-plus"></i> Add + Same Player';
				checkRequired();
			});
	}

	// "Add + New Player" — clear player + award/rank/note, keep date/giver/location
	gid('pk-award-save-new').addEventListener('click', function() {
		pkDoSave(function() { pkShowSuccess(); pkClearPlayer(); pkClearAward(); gid('pk-award-player-text').focus(); });
	});
	// "Add + Same Player" — clear only award/rank/note, keep player + date/giver/location
	gid('pk-award-save-same').addEventListener('click', function() {
		pkDoSave(function() { pkShowSuccess(); pkClearAward(); gid('pk-award-select').focus(); });
	});
})();
</script>
<?php endif; ?>
