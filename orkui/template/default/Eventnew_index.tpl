<?php
	// ---- Normalize data ----
	$info      = $EventInfo   ?? [];
	$cd        = $EventDetail ?? [];
	$eventId   = (int)($event_id  ?? 0);
	$detailId  = (int)($detail_id ?? 0);
	$loggedIn  = $LoggedIn ?? false;

	$eventName   = htmlspecialchars($info['Name'] ?? 'Event');
	$hasHeraldry = !empty($info['HasHeraldry']);
	$heraldryUrl = $hasHeraldry
		? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $eventId))
		: HTTP_EVENT_HERALDRY . '00000.jpg';

	$kingdomId   = (int)($info['KingdomId'] ?? 0);
	$kingdomName = htmlspecialchars($info['KingdomName'] ?? '');
	$parkId      = (int)($info['ParkId']    ?? 0);
	$parkName    = htmlspecialchars($info['ParkName']    ?? '');
	$unitId      = (int)($info['UnitId']    ?? 0);
	$unitName    = htmlspecialchars($info['Unit']        ?? '');
	$mundaneId   = (int)($info['MundaneId'] ?? 0);
	$persona     = htmlspecialchars($info['Persona']     ?? '');

	$isUpcoming    = $IsUpcoming     ?? false;
	$attendeeCount = $AttendanceCount ?? 0;
	$mapLink       = $MapLink        ?? '';

	$eventStart  = $cd['EventStart']  ?? null;
	$eventEnd    = $cd['EventEnd']    ?? null;
	$price       = (float)($cd['Price'] ?? 0);
	$description = $cd['Description'] ?? '';
	$hasDescription = !empty(trim($description));
	$websiteUrl  = $cd['Url']     ?? '';
	$websiteName = $cd['UrlName'] ?? '';
	$mapUrlName  = $cd['MapUrlName'] ?? '';
	$mapUrl      = $cd['MapUrl']     ?? '';
	$isCurrent   = ($cd['Current'] ?? 0) == 1;

	$city     = $cd['City']     ?? '';
	$province = $cd['Province'] ?? '';
	$country  = $cd['Country']  ?? '';
	$locationDisplay = implode(', ', array_filter([$city, $province, $country]));

	// Duration
	$durationLabel = '';
	if ( $eventStart && $eventEnd ) {
		$startTs = strtotime($eventStart);
		$endTs   = strtotime($eventEnd);
		if ( $endTs > $startTs ) {
			$days = (int)ceil(($endTs - $startTs) / 86400);
			$durationLabel = $days . ' day' . ($days == 1 ? '' : 's');
		}
	}

	$tournaments    = $Tournaments['Tournaments'] ?? [];
	$tourneyCount   = count($tournaments);
	$attendanceList = $AttendanceReport['Attendance'] ?? [];
	$attendanceForm = $Attendance_event ?? [];

	$defaultKingdomName = $DefaultKingdomName       ?? '';
	$defaultKingdomId   = $DefaultKingdomId         ?? 0;
	$defaultParkName    = $DefaultParkName          ?? '';
	$defaultParkId      = $DefaultParkId            ?? 0;
	$defaultCredits     = $DefaultAttendanceCredits ?? 1;

	// Date badge label
	$startLabel = $eventStart ? date('M j, Y', strtotime($eventStart)) : '';
	$endLabel   = $eventEnd   ? date('M j, Y', strtotime($eventEnd))   : '';
	$dateBadgeText = $startLabel;
	if ( $endLabel && $endLabel !== $startLabel ) $dateBadgeText .= ' – ' . $endLabel;
?>

<style type="text/css">
/* ========================================
   Eventnew — CRM-Style Event Occurrence Page
   All classes prefixed with ev- to avoid collisions
   ======================================== */

.ev-hero, .ev-stats-row, .ev-layout, .ev-sidebar, .ev-main,
.ev-card, .ev-tab-nav, .ev-tab-panel, .ev-table {
	box-sizing: border-box;
}

/* ---- Hero ---- */
.ev-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 20px;
	min-height: 160px;
	background-color: #2d3748;
}
.ev-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.14;
	filter: blur(6px);
}
.ev-hero-content {
	position: relative;
	display: flex;
	align-items: center;
	padding: 24px 30px;
	gap: 24px;
	z-index: 1;
}
.ev-heraldry-frame {
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
.ev-heraldry-frame img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.ev-hero-info { flex: 1; min-width: 0; }
.ev-event-name {
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
.ev-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}
.ev-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	line-height: 1.4;
}
.ev-badge-green  { background: #c6f6d5; color: #276749; }
.ev-badge-gray   { background: rgba(255,255,255,0.15); color: rgba(255,255,255,0.85); }
.ev-badge-yellow { background: #fefcbf; color: #744210; }
.ev-owner-inline {
	color: rgba(255,255,255,0.85);
	font-size: 13px;
	line-height: 1.8;
}
.ev-owner-inline a {
	color: #fff;
	font-weight: 600;
	text-decoration: none;
}
.ev-owner-inline a:hover { text-decoration: underline; }
.ev-owner-sep { margin: 0 8px; opacity: 0.35; }
.ev-hero-actions {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}
.ev-btn {
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
.ev-btn:hover { opacity: 0.85; }
.ev-btn-white   { background: #fff; color: #2d3748; }
.ev-btn-outline { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.4); }

/* ---- Stats row ---- */
.ev-stats-row {
	display: flex;
	background: #fff;
	border-bottom: 1px solid #e2e8f0;
}
.ev-stat-card {
	flex: 1;
	padding: 14px 12px;
	text-align: center;
	border-right: 1px solid #e2e8f0;
}
.ev-stat-card:last-child { border-right: none; }
.ev-stat-icon  { font-size: 13px; color: #a0aec0; margin-bottom: 3px; }
.ev-stat-value { font-size: 22px; font-weight: 700; color: #2b6cb0; line-height: 1.1; }
.ev-stat-label { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }

/* ---- Main layout ---- */
.ev-layout {
	display: flex;
	align-items: flex-start;
	background: #f7f9fc;
	min-height: 400px;
}
.ev-sidebar {
	width: 220px;
	flex-shrink: 0;
	padding: 16px 12px;
	border-right: 1px solid #e2e8f0;
	background: #fff;
}
.ev-main { flex: 1; min-width: 0; padding: 16px; }

/* ---- Sidebar cards ---- */
.ev-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 14px;
	margin-bottom: 12px;
}
.ev-card h4 {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: #718096;
	margin: 0 0 10px 0;
}
.ev-heraldry-large {
	width: 100%;
	aspect-ratio: 1;
	object-fit: contain;
	border-radius: 6px;
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	display: block;
	margin-bottom: 12px;
}
.ev-link-list { list-style: none; margin: 0; padding: 0; }
.ev-link-list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 5px 0;
	border-bottom: 1px solid #f0f0f0;
	font-size: 13px;
}
.ev-link-list li:last-child { border-bottom: none; }
.ev-link-icon { width: 18px; text-align: center; color: #a0aec0; font-size: 12px; flex-shrink: 0; }
.ev-link-list a { color: #2b6cb0; text-decoration: none; }
.ev-link-list a:hover { text-decoration: underline; }
.ev-detail-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	font-size: 13px;
	padding: 4px 0;
	border-bottom: 1px solid #f7f7f7;
	gap: 8px;
}
.ev-detail-row:last-child { border-bottom: none; }
.ev-detail-label { color: #718096; font-size: 12px; flex-shrink: 0; }
.ev-detail-value { color: #2d3748; font-weight: 500; text-align: right; }
.ev-map-btn {
	display: block;
	margin-top: 10px;
	text-align: center;
	background: #ebf8ff;
	color: #2b6cb0;
	border: 1px solid #bee3f8;
	border-radius: 5px;
	padding: 7px;
	font-size: 12px;
	font-weight: 600;
	text-decoration: none;
}
.ev-map-btn:hover { background: #bee3f8; }
.ev-current-pill {
	display: inline-block;
	background: #c6f6d5;
	color: #276749;
	font-size: 10px;
	padding: 1px 7px;
	border-radius: 10px;
	font-weight: 600;
	margin-left: 4px;
}

/* ---- Tabs ---- */
.ev-tabs { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
.ev-tab-nav {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	background: #f7fafc;
	border-bottom: 1px solid #e2e8f0;
}
.ev-tab-nav li {
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
.ev-tab-nav li:hover { color: #2b6cb0; background: #edf2f7; }
.ev-tab-active { color: #2b6cb0 !important; border-bottom-color: #2b6cb0 !important; font-weight: 600; }
.ev-tab-count { font-size: 11px; color: #a0aec0; }
.ev-tab-panel { padding: 16px; display: none; }
.ev-tab-panel.ev-tab-visible { display: block; }

/* ---- Tables ---- */
.ev-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.ev-table th {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	padding: 8px 10px;
	text-align: left;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	white-space: nowrap;
}
.ev-table td {
	border: 1px solid #e2e8f0;
	padding: 8px 10px;
	vertical-align: middle;
	color: #2d3748;
}
.ev-table tr:hover td { background: #f7fafc; }
.ev-table .ev-del-cell { text-align: center; width: 36px; }
.ev-del-link {
	color: #e53e3e;
	font-size: 16px;
	line-height: 1;
	text-decoration: none;
	font-weight: 700;
}
.ev-del-link:hover { opacity: 0.7; }

/* ---- Attendance form ---- */
.ev-att-form {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 14px;
	margin-bottom: 16px;
}
.ev-att-form h4 {
	font-size: 13px;
	font-weight: 700;
	color: #4a5568;
	margin: 0 0 12px 0;
}
.ev-form-row {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	align-items: flex-end;
	margin-bottom: 10px;
}
.ev-form-field { display: flex; flex-direction: column; gap: 3px; }
.ev-form-field label { font-size: 11px; font-weight: 600; color: #718096; text-transform: uppercase; letter-spacing: 0.04em; }
.ev-form-field input,
.ev-form-field select {
	padding: 6px 8px;
	border: 1px solid #cbd5e0;
	border-radius: 4px;
	font-size: 13px;
	background: #fff;
}
.ev-form-field input:focus,
.ev-form-field select:focus { outline: none; border-color: #63b3ed; box-shadow: 0 0 0 2px rgba(66,153,225,0.15); }
.ev-submit-btn {
	background: #276749;
	color: #fff;
	border: none;
	border-radius: 5px;
	padding: 7px 18px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}
.ev-submit-btn:hover { background: #22543d; }

/* ---- Description ---- */
.ev-description {
	font-size: 14px;
	color: #2d3748;
	line-height: 1.6;
}
.ev-empty { color: #a0aec0; font-size: 13px; padding: 20px 0; text-align: center; }

/* ---- Error banner ---- */
.ev-error {
	background: #fff5f5;
	border: 1px solid #feb2b2;
	border-radius: 5px;
	color: #c53030;
	padding: 10px 14px;
	font-size: 13px;
	margin-bottom: 12px;
}

/* ---- Edit Modal ---- */
.ev-modal-overlay {
	display: none;
	position: fixed;
	inset: 0;
	background: rgba(0,0,0,0.5);
	z-index: 1000;
	align-items: center;
	justify-content: center;
	padding: 20px;
}
.ev-modal-overlay.ev-modal-open { display: flex; }
.ev-modal {
	background: #fff;
	border-radius: 8px;
	max-width: 700px;
	width: 100%;
	max-height: 90vh;
	overflow-y: auto;
	box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.ev-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid #e2e8f0;
	background: #f7fafc;
	border-radius: 8px 8px 0 0;
}
.ev-modal-header h3 { margin: 0; font-size: 15px; font-weight: 700; color: #2d3748; }
.ev-modal-close {
	background: none;
	border: none;
	font-size: 20px;
	cursor: pointer;
	color: #718096;
	padding: 2px 6px;
	border-radius: 4px;
	line-height: 1;
}
.ev-modal-close:hover { background: #e2e8f0; color: #2d3748; }
.ev-modal-body { padding: 20px; }
.ev-modal-section { margin-bottom: 16px; }
.ev-modal-section h4 {
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: #718096;
	margin: 0 0 10px 0;
	padding-bottom: 6px;
	border-bottom: 1px solid #e2e8f0;
}
.ev-modal-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 10px; }
.ev-modal-field { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 160px; }
.ev-modal-field.ev-field-full { flex-basis: 100%; min-width: 100%; }
.ev-modal-field label {
	font-size: 11px;
	font-weight: 600;
	color: #718096;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
.ev-modal-field input[type="text"],
.ev-modal-field input[type="number"],
.ev-modal-field input[type="datetime-local"],
.ev-modal-field textarea {
	padding: 7px 9px;
	border: 1px solid #cbd5e0;
	border-radius: 4px;
	font-size: 13px;
	background: #fff;
	width: 100%;
	box-sizing: border-box;
}
.ev-modal-field input:focus,
.ev-modal-field textarea:focus { outline: none; border-color: #63b3ed; box-shadow: 0 0 0 2px rgba(66,153,225,0.15); }
.ev-modal-field textarea { resize: vertical; min-height: 80px; font-family: inherit; }
.ev-modal-check-row {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	color: #2d3748;
	margin-bottom: 10px;
}
.ev-modal-check-row input[type="checkbox"] { width: 15px; height: 15px; }
.ev-modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding: 14px 20px;
	border-top: 1px solid #e2e8f0;
	background: #f7fafc;
	border-radius: 0 0 8px 8px;
}
.ev-modal-btn-cancel {
	background: #e2e8f0;
	color: #4a5568;
	border: none;
	border-radius: 5px;
	padding: 8px 18px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}
.ev-modal-btn-cancel:hover { background: #cbd5e0; }
.ev-modal-btn-save {
	background: #2b6cb0;
	color: #fff;
	border: none;
	border-radius: 5px;
	padding: 8px 20px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}
.ev-modal-btn-save:hover { background: #2c5282; }
</style>

<?php // ---- HERO ---- ?>
<div class="ev-hero" id="ev-hero">
	<div class="ev-hero-bg"
		<?php if ($heraldryUrl): ?>
			style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"
		<?php endif; ?>
	></div>
	<div class="ev-hero-content">

		<div class="ev-heraldry-frame">
			<img id="ev-heraldry-img"
				src="<?= htmlspecialchars($heraldryUrl) ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="<?= $eventName ?> heraldry"
				crossorigin="anonymous">
		</div>

		<div class="ev-hero-info">
			<h1 class="ev-event-name"><?= $eventName ?></h1>
			<div class="ev-badges">
				<?php if ($dateBadgeText): ?>
				<span class="ev-badge <?= $isUpcoming ? 'ev-badge-green' : 'ev-badge-gray' ?>">
					<i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($dateBadgeText) ?>
				</span>
				<?php endif; ?>
				<span class="ev-badge <?= $isUpcoming ? 'ev-badge-green' : 'ev-badge-gray' ?>">
					<?= $isUpcoming ? '<i class="fas fa-clock"></i> Upcoming' : '<i class="fas fa-history"></i> Past' ?>
				</span>
				<?php if ($isCurrent): ?>
				<span class="ev-badge ev-badge-yellow">
					<i class="fas fa-star"></i> Current
				</span>
				<?php endif; ?>
			</div>
			<div class="ev-owner-inline">
				<i class="fas fa-layer-group" style="font-size:10px;opacity:0.6;margin-right:4px"></i>
				<a href="<?= UIR ?>Eventtemplatenew/index/<?= $eventId ?>"><?= $eventName ?></a>
				<?php if ($kingdomId): ?>
					<span class="ev-owner-sep">›</span>
					<a href="<?= UIR ?>Kingdomnew/index/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php if ($parkId): ?>
					<span class="ev-owner-sep">›</span>
					<a href="<?= UIR ?>Parknew/index/<?= $parkId ?>"><?= $parkName ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="ev-hero-actions">
			<a class="ev-btn ev-btn-white"
				href="<?= UIR ?>Reports/attendance/Event/<?= $eventId ?>/All">
				<i class="fas fa-list-alt"></i> Attendance Report
			</a>
			<a class="ev-btn ev-btn-outline"
				href="<?= UIR ?>Eventtemplatenew/index/<?= $eventId ?>">
				<i class="fas fa-layer-group"></i> Event Template
			</a>
			<?php if ($CanManageEvent ?? false): ?>
			<button class="ev-btn ev-btn-outline" type="button" onclick="evOpenEditModal()">
				<i class="fas fa-pencil-alt"></i> Edit Details
			</button>
			<?php endif; ?>
			<?php if ($loggedIn): ?>
			<a class="ev-btn ev-btn-outline"
				href="<?= UIR ?>Admin/event/<?= $eventId ?>">
				<i class="fas fa-cog"></i> Admin Panel
			</a>
			<?php endif; ?>
		</div>

	</div>
</div>

<?php // ---- STATS ROW ---- ?>
<div class="ev-stats-row">
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="ev-stat-value" style="font-size:15px;padding-top:3px">
			<?= $startLabel ?: '<span style="color:#a0aec0">TBD</span>' ?>
		</div>
		<div class="ev-stat-label">Date</div>
	</div>
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-ticket-alt"></i></div>
		<div class="ev-stat-value">
			<?php if ($price > 0): ?>
				$<?= number_format($price, 2) ?>
			<?php else: ?>
				<span style="color:#276749;font-size:16px">Free</span>
			<?php endif; ?>
		</div>
		<div class="ev-stat-label">Price</div>
	</div>
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="ev-stat-value" style="font-size:14px;padding-top:3px">
			<?= $locationDisplay ? htmlspecialchars($locationDisplay) : '<span style="color:#a0aec0">TBD</span>' ?>
		</div>
		<div class="ev-stat-label">Location</div>
	</div>
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-users"></i></div>
		<div class="ev-stat-value"><?= $attendeeCount ?></div>
		<div class="ev-stat-label">Attendees</div>
	</div>
</div>

<?php // ---- LAYOUT ---- ?>
<div class="ev-layout">

	<?php // ---- SIDEBAR ---- ?>
	<div class="ev-sidebar">

		<img class="ev-heraldry-large"
			src="<?= htmlspecialchars($heraldryUrl) ?>"
			onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
			alt="">

		<?php // Event Dates card ?>
		<div class="ev-card">
			<h4><i class="fas fa-calendar" style="margin-right:5px"></i>Event Dates</h4>
			<?php if ($eventStart): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Start</span>
				<span class="ev-detail-value"><?= date('M j, Y', strtotime($eventStart)) ?></span>
			</div>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Time</span>
				<span class="ev-detail-value"><?= date('g:i A', strtotime($eventStart)) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($eventEnd): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">End</span>
				<span class="ev-detail-value"><?= date('M j, Y', strtotime($eventEnd)) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($durationLabel): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Duration</span>
				<span class="ev-detail-value"><?= $durationLabel ?></span>
			</div>
			<?php endif; ?>
		</div>

		<?php if ($locationDisplay || $mapLink): ?>
		<?php // Location card ?>
		<div class="ev-card">
			<h4><i class="fas fa-map-marker-alt" style="margin-right:5px"></i>Location</h4>
			<?php if ($city): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">City</span>
				<span class="ev-detail-value"><?= htmlspecialchars($city) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($province): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Region</span>
				<span class="ev-detail-value"><?= htmlspecialchars($province) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($country): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Country</span>
				<span class="ev-detail-value"><?= htmlspecialchars($country) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($mapLink): ?>
			<a href="<?= htmlspecialchars($mapLink) ?>" target="_blank" class="ev-map-btn">
				<i class="fas fa-map"></i> Google Maps
			</a>
			<?php endif; ?>
			<?php if ($mapUrl && $mapUrlName): ?>
			<a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" class="ev-map-btn" style="margin-top:6px;background:#f0fff4;border-color:#9ae6b4;">
				<i class="fas fa-map-signs"></i> <?= htmlspecialchars($mapUrlName) ?>
			</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php // Quick Links card ?>
		<div class="ev-card">
			<h4><i class="fas fa-link" style="margin-right:5px"></i>Quick Links</h4>
			<ul class="ev-link-list">
				<?php if ($websiteUrl): ?>
				<li>
					<span class="ev-link-icon"><i class="fas fa-globe"></i></span>
					<a href="<?= htmlspecialchars($websiteUrl) ?>" target="_blank">
						<?= $websiteName ? htmlspecialchars($websiteName) : 'Event Website' ?>
					</a>
				</li>
				<?php endif; ?>
				<li>
					<span class="ev-link-icon"><i class="fas fa-list-alt"></i></span>
					<a href="<?= UIR ?>Reports/attendance/Event/<?= $eventId ?>/All">Full Attendance</a>
				</li>
				<li>
					<span class="ev-link-icon"><i class="fas fa-layer-group"></i></span>
					<a href="<?= UIR ?>Eventtemplatenew/index/<?= $eventId ?>">Event Template</a>
				</li>
				<?php if ($loggedIn): ?>
				<li>
					<span class="ev-link-icon"><i class="fas fa-cog"></i></span>
					<a href="<?= UIR ?>Admin/event/<?= $eventId ?>">Admin Panel</a>
				</li>
				<?php endif; ?>
			</ul>
		</div>

	</div><!-- /.ev-sidebar -->

	<?php // ---- MAIN CONTENT ---- ?>
	<div class="ev-main">

		<?php if (!empty($Error)): ?>
		<div class="ev-error"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i><?= $Error ?></div>
		<?php endif; ?>

		<div class="ev-tabs">

			<ul class="ev-tab-nav" id="ev-tab-nav">
				<li class="ev-tab-active" data-tab="ev-tab-details" onclick="evShowTab(this,'ev-tab-details')">
					<i class="fas fa-align-left"></i> Details
				</li>
				<li data-tab="ev-tab-attendance" onclick="evShowTab(this,'ev-tab-attendance')">
					<i class="fas fa-clipboard-list"></i> Attendance
					<span class="ev-tab-count"><?= $attendeeCount ?></span>
				</li>
				<li data-tab="ev-tab-tournaments" onclick="evShowTab(this,'ev-tab-tournaments')">
					<i class="fas fa-trophy"></i> Tournaments
					<span class="ev-tab-count"><?= $tourneyCount ?></span>
				</li>
			</ul>

			<?php // ---- Details Tab ---- ?>
			<div class="ev-tab-panel ev-tab-visible" id="ev-tab-details">
				<?php if ($hasDescription): ?>
					<div class="ev-description"><?= $description ?></div>
				<?php else: ?>
					<div class="ev-empty">
						<i class="fas fa-file-alt" style="margin-right:6px"></i>No description provided
					</div>
				<?php endif; ?>
			</div>

			<?php // ---- Attendance Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-attendance">

				<?php if ($loggedIn): ?>
				<div class="ev-att-form">
					<h4><i class="fas fa-plus-circle" style="margin-right:6px;color:#276749"></i>Add Attendance</h4>
					<form method="post" action="<?= UIR ?>Eventnew/index/<?= $eventId ?>/<?= $detailId ?>/new">
						<div class="ev-form-row">
							<div class="ev-form-field">
								<label>Kingdom</label>
								<input type="text" id="ev-KingdomName" name="KingdomName" style="width:140px"
									value="<?= htmlspecialchars($attendanceForm['KingdomName'] ?? $defaultKingdomName) ?>"
									autocomplete="off" placeholder="Search…">
								<input type="hidden" id="ev-KingdomId" name="KingdomId"
									value="<?= (int)($attendanceForm['KingdomId'] ?? $defaultKingdomId) ?>">
							</div>
							<div class="ev-form-field">
								<label>Park</label>
								<input type="text" id="ev-ParkName" name="ParkName" style="width:140px"
									value="<?= htmlspecialchars($attendanceForm['ParkName'] ?? $defaultParkName) ?>"
									autocomplete="off" placeholder="Search…">
								<input type="hidden" id="ev-ParkId" name="ParkId"
									value="<?= (int)($attendanceForm['ParkId'] ?? $defaultParkId) ?>">
							</div>
							<div class="ev-form-field">
								<label>Player</label>
								<input type="text" id="ev-PlayerName" name="PlayerName" style="width:160px"
									value="<?= htmlspecialchars($attendanceForm['PlayerName'] ?? '') ?>"
									autocomplete="off" placeholder="Search…">
								<input type="hidden" id="ev-MundaneId" name="MundaneId"
									value="<?= (int)($attendanceForm['MundaneId'] ?? 0) ?>">
							</div>
							<div class="ev-form-field">
								<label>Class</label>
								<select id="ev-ClassId" name="ClassId" style="width:120px">
									<option value="">— select —</option>
									<?php foreach ($Classes ?? [] as $class): ?>
									<option value="<?= (int)$class['ClassId'] ?>"
										<?= ($attendanceForm['ClassId'] ?? '') == $class['ClassId'] ? 'selected' : '' ?>>
										<?= htmlspecialchars($class['Name']) ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="ev-form-field">
								<label>Credits</label>
								<input type="text" id="ev-Credits" name="Credits" style="width:55px"
									value="<?= (float)($attendanceForm['Credits'] ?? $defaultCredits) ?>">
							</div>
							<div class="ev-form-field" style="justify-content:flex-end">
								<label>&nbsp;</label>
								<button type="submit" class="ev-submit-btn">
									<i class="fas fa-plus"></i> Add
								</button>
							</div>
						</div>
						<input type="hidden" id="ev-AttendanceDate" name="AttendanceDate"
							value="<?= $eventStart ? date('Y-m-d', strtotime($eventStart)) : date('Y-m-d') ?>">
					</form>
				</div>
				<?php endif; ?>

				<?php if (count($attendanceList) > 0): ?>
				<table class="ev-table">
					<thead>
						<tr>
							<th>Player</th>
							<th>Kingdom</th>
							<th>Park</th>
							<th>Class</th>
							<th>Credits</th>
							<?php if ($loggedIn): ?>
							<th class="ev-del-cell">&times;</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($attendanceList as $att): ?>
						<tr>
							<td><a href="<?= UIR ?>Playernew/index/<?= (int)$att['MundaneId'] ?>"><?= htmlspecialchars($att['Persona']) ?></a></td>
							<td><a href="<?= UIR ?>Kingdomnew/index/<?= (int)$att['KingdomId'] ?>"><?= htmlspecialchars($att['KingdomName']) ?></a></td>
							<td><a href="<?= UIR ?>Parknew/index/<?= (int)$att['ParkId'] ?>"><?= htmlspecialchars($att['ParkName']) ?></a></td>
							<td><?= htmlspecialchars($att['ClassName']) ?></td>
							<td><?= htmlspecialchars($att['Credits']) ?></td>
							<?php if ($loggedIn): ?>
							<td class="ev-del-cell">
								<a class="ev-del-link" title="Remove"
									href="<?= UIR ?>Eventnew/index/<?= $eventId ?>/<?= $detailId ?>/delete/<?= (int)$att['AttendanceId'] ?>"
									onclick="return confirm('Remove this attendance record?')">×</a>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="ev-empty">
					<i class="fas fa-clipboard" style="margin-right:6px"></i>No attendance recorded yet
				</div>
				<?php endif; ?>

			</div><!-- /.ev-tab-panel -->

			<?php // ---- Tournaments Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-tournaments">
				<?php if ($tourneyCount > 0): ?>
				<table class="ev-table">
					<thead>
						<tr>
							<th>Tournament</th>
							<th>Date</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($tournaments as $t): ?>
						<tr>
							<td>
								<a href="<?= UIR ?>Tournament/worksheet/<?= (int)$t['TournamentId'] ?>">
									<?= htmlspecialchars($t['Name'] ?? 'Tournament') ?>
								</a>
							</td>
							<td><?= $t['EventStart'] ? date('M j, Y', strtotime($t['EventStart'])) : '—' ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="ev-empty">
					<i class="fas fa-trophy" style="margin-right:6px"></i>No tournaments recorded
				</div>
				<?php endif; ?>
			</div><!-- /.ev-tab-panel -->

		</div><!-- /.ev-tabs -->
	</div><!-- /.ev-main -->

</div><!-- /.ev-layout -->

<?php if ($CanManageEvent ?? false): ?>
<div class="ev-modal-overlay" id="ev-edit-modal">
	<div class="ev-modal">
		<div class="ev-modal-header">
			<h3><i class="fas fa-pencil-alt" style="margin-right:8px"></i>Edit Event Details</h3>
			<button class="ev-modal-close" type="button" onclick="evCloseEditModal()">&times;</button>
		</div>
		<form method="post" action="<?= UIR ?>Eventnew/index/<?= $eventId ?>/<?= $detailId ?>/edit">
			<div class="ev-modal-body">

				<div class="ev-modal-section">
					<h4>Dates &amp; Price</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>Start Date &amp; Time</label>
							<input type="datetime-local" name="StartDate"
								value="<?= $eventStart ? date('Y-m-d\TH:i', strtotime($eventStart)) : '' ?>">
						</div>
						<div class="ev-modal-field">
							<label>End Date &amp; Time</label>
							<input type="datetime-local" name="EndDate"
								value="<?= $eventEnd ? date('Y-m-d\TH:i', strtotime($eventEnd)) : '' ?>">
						</div>
						<div class="ev-modal-field" style="max-width:120px">
							<label>Price ($)</label>
							<input type="number" name="Price" min="0" step="0.01"
								value="<?= number_format($price, 2) ?>">
						</div>
					</div>
					<div class="ev-modal-check-row">
						<input type="checkbox" name="Current" id="ev-edit-current" value="1"
							<?= $isCurrent ? 'checked' : '' ?>>
						<label for="ev-edit-current">Mark as Current (active/upcoming occurrence)</label>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Description</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label>Description</label>
							<textarea name="Description" rows="5"><?= htmlspecialchars(rawurldecode($description)) ?></textarea>
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Website Link</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>URL</label>
							<input type="text" name="Url"
								value="<?= htmlspecialchars($websiteUrl) ?>" placeholder="https://…">
						</div>
						<div class="ev-modal-field">
							<label>Link Text</label>
							<input type="text" name="UrlName"
								value="<?= htmlspecialchars($websiteName) ?>" placeholder="Event Website">
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Location</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label>Address</label>
							<input type="text" name="Address"
								value="<?= htmlspecialchars($cd['Address'] ?? '') ?>">
						</div>
					</div>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>City</label>
							<input type="text" name="City" value="<?= htmlspecialchars($city) ?>">
						</div>
						<div class="ev-modal-field">
							<label>Province / State</label>
							<input type="text" name="Province" value="<?= htmlspecialchars($province) ?>">
						</div>
						<div class="ev-modal-field" style="max-width:120px">
							<label>Postal Code</label>
							<input type="text" name="PostalCode"
								value="<?= htmlspecialchars($cd['PostalCode'] ?? '') ?>">
						</div>
						<div class="ev-modal-field" style="max-width:120px">
							<label>Country</label>
							<input type="text" name="Country" value="<?= htmlspecialchars($country) ?>">
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Map Link</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>Map URL</label>
							<input type="text" name="MapUrl"
								value="<?= htmlspecialchars($mapUrl) ?>" placeholder="https://maps.google.com/…">
						</div>
						<div class="ev-modal-field">
							<label>Map Link Text</label>
							<input type="text" name="MapUrlName"
								value="<?= htmlspecialchars($mapUrlName) ?>" placeholder="Campsite Map">
						</div>
					</div>
				</div>

			</div><!-- /.ev-modal-body -->
			<div class="ev-modal-footer">
				<button type="button" class="ev-modal-btn-cancel" onclick="evCloseEditModal()">Cancel</button>
				<button type="submit" class="ev-modal-btn-save">
					<i class="fas fa-save" style="margin-right:5px"></i>Save Changes
				</button>
			</div>
		</form>
	</div>
</div><!-- /.ev-edit-modal -->
<?php endif; ?>

<script>
(function() {
	// ---- Tab switching ----
	window.evShowTab = function(li, tabId) {
		var nav    = document.getElementById('ev-tab-nav');
		var panels = document.querySelectorAll('.ev-tab-panel');
		nav.querySelectorAll('li').forEach(function(el) {
			el.classList.remove('ev-tab-active');
		});
		panels.forEach(function(p) { p.classList.remove('ev-tab-visible'); });
		li.classList.add('ev-tab-active');
		var panel = document.getElementById(tabId);
		if (panel) panel.classList.add('ev-tab-visible');
	};

	// ---- Autocompletes ----
	$(document).ready(function() {
		function showLabel(sel, ui) {
			if (ui) $(sel).val(ui.item.label);
			return false;
		}

		$('#ev-KingdomName').autocomplete({
			source: function(req, res) {
				$.getJSON('<?= HTTP_SERVICE ?>Search/SearchService.php',
					{ Action: 'Search/Kingdom', name: req.term, limit: 6 },
					function(data) {
						res($.map(data, function(v) { return { label: v.Name, value: v.KingdomId }; }));
					});
			},
			focus:  function(e,ui) { return showLabel('#ev-KingdomName', ui); },
			delay: 250, minLength: 0,
			select: function(e,ui) { showLabel('#ev-KingdomName',ui); $('#ev-KingdomId').val(ui.item.value); return false; },
			change: function(e,ui) { if(!ui.item) { showLabel('#ev-KingdomName',null); $('#ev-KingdomId').val(''); } return false; }
		}).focus(function() { if(!this.value) $(this).trigger('keydown.autocomplete'); });

		$('#ev-ParkName').autocomplete({
			source: function(req, res) {
				$.getJSON('<?= HTTP_SERVICE ?>Search/SearchService.php',
					{ Action: 'Search/Park', name: req.term, kingdom_id: $('#ev-KingdomId').val(), limit: 6 },
					function(data) {
						res($.map(data, function(v) { return { label: v.Name, value: v.ParkId }; }));
					});
			},
			focus:  function(e,ui) { return showLabel('#ev-ParkName', ui); },
			delay: 250, minLength: 0,
			select: function(e,ui) { showLabel('#ev-ParkName',ui); $('#ev-ParkId').val(ui.item.value); return false; },
			change: function(e,ui) { if(!ui.item) { showLabel('#ev-ParkName',null); $('#ev-ParkId').val(''); } return false; }
		}).focus(function() { if(!this.value) $(this).trigger('keydown.autocomplete'); });

		$('#ev-PlayerName').autocomplete({
			source: function(req, res) {
				$.getJSON('<?= HTTP_SERVICE ?>Search/SearchService.php',
					{ Action: 'Search/Player', type: 'all', search: req.term,
					  park_id: $('#ev-ParkId').val(), kingdom_id: $('#ev-KingdomId').val(), limit: 15 },
					function(data) {
						res($.map(data, function(v) { return { label: v.Persona, value: v.MundaneId + '|' + v.PenaltyBox }; }));
					});
			},
			focus:  function(e,ui) { return showLabel('#ev-PlayerName', ui); },
			delay: 250, minLength: 0,
			select: function(e,ui) {
				showLabel('#ev-PlayerName', ui);
				$('#ev-MundaneId').val(ui.item.value.split('|')[0]);
				return false;
			},
			change: function(e,ui) { if(!ui.item) { showLabel('#ev-PlayerName',null); $('#ev-MundaneId').val(''); } return false; }
		}).focus(function() { if(!this.value) $(this).trigger('keydown.autocomplete'); });
	});

	// ---- Hero dominant-color tint ----
	function evApplyHeroColor() {
		var img = document.getElementById('ev-heraldry-img');
		if (!img) return;
		function extract() {
			try {
				var c = document.createElement('canvas');
				c.width = 32; c.height = 32;
				var ctx = c.getContext('2d');
				ctx.drawImage(img, 0, 0, 32, 32);
				var d = ctx.getImageData(0, 0, 32, 32).data;
				var r=0, g=0, b=0, count=0;
				for (var i=0; i<d.length; i+=4) {
					if (d[i+3]>30) { r+=d[i]; g+=d[i+1]; b+=d[i+2]; count++; }
				}
				if (!count) return;
				r=Math.round(r/count); g=Math.round(g/count); b=Math.round(b/count);
				var max=Math.max(r,g,b)/255, min=Math.min(r,g,b)/255;
				var l=(max+min)/2;
				var s = max===min ? 0 : (l<0.5 ? (max-min)/(max+min) : (max-min)/(2-max-min));
				var h=0;
				if (max!==min) {
					var d2=(max-min)/255;
					if (max===r/255) h=(g/255-b/255)/d2+(g<b?6:0);
					else if (max===g/255) h=(b/255-r/255)/d2+2;
					else h=(r/255-g/255)/d2+4;
					h*=60;
				}
				var hero = document.getElementById('ev-hero');
				if (hero) hero.style.backgroundColor = 'hsl('+Math.round(h)+','+Math.round(s*55)+'%,18%)';
			} catch(e){}
		}
		if (img.complete && img.naturalWidth > 0) { extract(); }
		else { img.addEventListener('load', extract); }
	}
	evApplyHeroColor();

	// ---- Edit modal ----
	window.evOpenEditModal = function() {
		var overlay = document.getElementById('ev-edit-modal');
		if (overlay) overlay.classList.add('ev-modal-open');
		document.body.style.overflow = 'hidden';
	};
	window.evCloseEditModal = function() {
		var overlay = document.getElementById('ev-edit-modal');
		if (overlay) overlay.classList.remove('ev-modal-open');
		document.body.style.overflow = '';
	};
	// Close on backdrop click
	document.addEventListener('click', function(e) {
		if (e.target && e.target.id === 'ev-edit-modal') evCloseEditModal();
	});
	// Close on Escape key
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') evCloseEditModal();
	});
})();
</script>
