<?php
	// Pre-process kingdoms and principalities
	$hmKingdoms = [];
	$hmPrinz    = [];
	$hmTotalParks = 0;
	$hmTotalAttendance = 0;
	if (is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'])) {
		foreach ($ActiveKingdomSummary['ActiveKingdomsSummaryList'] as $r) {
			$r['_weekly']   = $r['Attendance'] > 0 ? round($r['Attendance'] / 26.0, 1) : 0;
			$r['_monthly']  = $r['Monthly']    > 0 ? round($r['Monthly']    / 12.0, 1) : 0;
			$r['_heraldry'] = HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf('%04d', (int)$r['KingdomId']));
			if ((int)$r['ParentKingdomId'] === 0) {
				$hmKingdoms[]       = $r;
				$hmTotalParks      += (int)$r['ParkCount'];
				$hmTotalAttendance += (int)$r['Attendance'];
			} else {
				$hmPrinz[] = $r;
			}
		}
	}
	// Pin the logged-in user's home kingdom to the first slot
	$hmUserKingdomId = isset($UserKingdomId) ? (int)$UserKingdomId : 0;

	// Build a sort key by stripping common leading words before alphabetizing
	$hmSortKey = function($name) {
		$words    = preg_split('/\s+/', trim($name));
		$skip     = ['the', 'kingdom', 'empire', 'of'];
		$filtered = array_filter($words, function($w) use ($skip) {
			return !in_array(strtolower($w), $skip);
		});
		return strtolower(implode(' ', array_values($filtered)));
	};
	// Sort kingdoms alphabetically, ignoring "The", "Kingdom", "Empire", "Of"
	usort($hmKingdoms, function($a, $b) use ($hmSortKey) {
		return strcmp($hmSortKey($a['KingdomName']), $hmSortKey($b['KingdomName']));
	});
	usort($hmPrinz, function($a, $b) use ($hmSortKey) {
		return strcmp($hmSortKey($a['KingdomName']), $hmSortKey($b['KingdomName']));
	});
	// Move user's home kingdom to front after sorting
	if ($hmUserKingdomId > 0) {
		$pinIdx = array_search($hmUserKingdomId, array_map('intval', array_column($hmKingdoms, 'KingdomId')));
		if ($pinIdx !== false) {
			$pinned = array_splice($hmKingdoms, $pinIdx, 1);
			$pinned[0]['_pinned'] = true;
			array_unshift($hmKingdoms, $pinned[0]);
		}
	}

	$hmWeeklyAvg = $hmTotalAttendance > 0 ? round($hmTotalAttendance / 26.0) : 0;
	$hmEventList = is_array($EventSummary) ? $EventSummary : [];
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=MedievalSharp&display=swap" rel="stylesheet">

<style type="text/css">
/* ========================================
   Home / Landing Page
   All classes prefixed with hm- to avoid collisions
   ======================================== */

/* ---- Welcome banner ---- */
.hm-welcome-banner {
	text-align: center;
	padding: 14px 20px 6px;
	margin-bottom: 10px;
}
.hm-welcome-title {
	font-family: 'MedievalSharp', serif;
	font-size: 36px;
	color: #2d3748;
	letter-spacing: 0.02em;
	line-height: 1.2;
	margin: 0;
	text-shadow: 0 1px 2px rgba(0,0,0,0.08);
}
@media (max-width: 600px) {
	.hm-welcome-title { font-size: 24px; }
}

/* ---- Stats bar ---- */
.hm-stats-bar {
	display: flex;
	gap: 0;
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	margin-bottom: 14px;
	overflow: hidden;
}
.hm-stat-item {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 11px 12px;
	gap: 3px;
	border-right: 1px solid #e2e8f0;
}
.hm-stat-item:last-child { border-right: none; }
.hm-stat-value {
	font-size: 24px;
	font-weight: 700;
	color: #2d3748;
	line-height: 1;
}
.hm-stat-label {
	font-size: 11px;
	color: #718096;
	text-transform: uppercase;
	letter-spacing: 0.06em;
}

/* ---- Section header ---- */
.hm-section { margin-bottom: 20px; }
.hm-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
	padding-bottom: 7px;
	border-bottom: 2px solid #e2e8f0;
}
.hm-section-title {
	font-size: 17px;
	font-weight: 700;
	color: #2d3748;
}
.hm-section-title i { margin-right: 7px; color: #4a5568; }
.hm-view-all {
	font-size: 13px;
	color: #2b6cb0;
	text-decoration: none;
}
.hm-view-all:hover { text-decoration: underline; }

/* ---- Kingdom cards grid ---- */
.hm-kingdoms-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
	gap: 14px;
}
.hm-kingdom-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 10px;
	overflow: hidden;
	text-decoration: none;
	color: inherit;
	display: flex;
	flex-direction: column;
	transition: box-shadow 0.15s, transform 0.12s, border-color 0.15s;
	cursor: pointer;
}
.hm-kingdom-card:hover {
	box-shadow: 0 6px 20px rgba(0,0,0,0.12);
	transform: translateY(-3px);
	border-color: #bee3f8;
}
.hm-card-heraldry-wrap {
	background: #f7fafc;
	height: 140px;
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 14px 14px 0;
	overflow: hidden;
	position: relative;
}
/* Fade the bottom of the heraldry into white */
.hm-card-heraldry-wrap::after {
	content: '';
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	height: 72px;
	background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, rgba(255,255,255,0.6) 40%, #fff 100%);
	pointer-events: none;
}
.hm-card-heraldry {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	transition: transform 0.2s;
}
.hm-kingdom-card:hover .hm-card-heraldry { transform: scale(1.06); }
.hm-card-body {
	padding: 8px 14px 14px;
	margin-top: -18px;
	position: relative;
	z-index: 1;
	background: #fff;
	flex: 1;
}
.hm-card-name {
	font-size: 14px;
	font-weight: 700;
	color: #2d3748;
	margin-bottom: 8px;
	line-height: 1.3;
}
.hm-card-stats {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}
.hm-card-stat {
	font-size: 12px;
	color: #718096;
	display: flex;
	align-items: center;
	gap: 4px;
}
.hm-card-stat i { font-size: 10px; }

/* ---- Principalities grid (smaller cards) ---- */
.hm-prinz-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 12px;
}
.hm-prinz-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	text-decoration: none;
	color: inherit;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 14px 10px 12px;
	gap: 8px;
	text-align: center;
	transition: box-shadow 0.15s, border-color 0.15s;
}
.hm-prinz-card:hover {
	box-shadow: 0 3px 12px rgba(0,0,0,0.09);
	border-color: #bee3f8;
}
.hm-prinz-heraldry {
	width: 70px;
	height: 70px;
	object-fit: contain;
}
.hm-prinz-name {
	font-size: 12px;
	font-weight: 600;
	color: #2d3748;
	line-height: 1.3;
}
.hm-prinz-stat {
	font-size: 11px;
	color: #a0aec0;
}

/* ---- Bottom row: Events + Find ---- */
.hm-bottom-row {
	display: flex;
	gap: 28px;
	align-items: flex-start;
}
.hm-bottom-main { flex: 1 1 0; min-width: 0; }
.hm-bottom-side { width: 240px; flex-shrink: 0; }

/* ---- Events list ---- */
.hm-events-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.hm-event-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 9px 10px;
	border-radius: 6px;
	text-decoration: none;
	color: inherit;
	transition: background 0.1s;
}
.hm-event-row:hover { background: #f7fafc; }
.hm-event-heraldry {
	width: 36px;
	height: 36px;
	flex-shrink: 0;
	border-radius: 4px;
	overflow: hidden;
	background: #f0f4f8;
	display: flex;
	align-items: center;
	justify-content: center;
}
.hm-event-heraldry img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.hm-event-info { flex: 1; min-width: 0; }
.hm-event-name {
	font-size: 13px;
	font-weight: 600;
	color: #2d3748;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.hm-event-meta {
	font-size: 11px;
	color: #718096;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.hm-event-date {
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	flex-shrink: 0;
	background: #edf2f7;
	border-radius: 4px;
	padding: 3px 8px;
	white-space: nowrap;
}

/* ---- Find sidebar ---- */
.hm-find-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.hm-find-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 14px;
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 7px;
	font-size: 13px;
	font-weight: 500;
	color: #2d3748;
	text-decoration: none;
	transition: background 0.1s, border-color 0.1s, color 0.1s;
}
.hm-find-item:hover {
	background: #ebf8ff;
	border-color: #bee3f8;
	color: #2b6cb0;
}
.hm-find-item i {
	font-size: 14px;
	width: 18px;
	text-align: center;
	color: #4a5568;
}
.hm-find-item:hover i { color: #2b6cb0; }

/* ---- Pinned home kingdom indicator ---- */
.hm-kingdom-card.hm-pinned {
	border-color: #bee3f8;
	box-shadow: 0 0 0 2px #bee3f8;
}
.hm-pin-badge {
	position: absolute;
	top: 7px;
	right: 8px;
	background: rgba(255,255,255,0.88);
	border: 1px solid #bee3f8;
	border-radius: 10px;
	font-size: 10px;
	color: #2b6cb0;
	padding: 2px 7px;
	font-weight: 600;
	letter-spacing: 0.04em;
	z-index: 2;
	pointer-events: none;
}

/* ---- Empty state ---- */
.hm-empty {
	font-size: 13px;
	color: #a0aec0;
	font-style: italic;
	padding: 20px 0;
	text-align: center;
}

/* ---- Responsive ---- */
@media (max-width: 900px) {
	.hm-bottom-row { flex-direction: column; }
	.hm-bottom-side { width: 100%; }
	.hm-kingdoms-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
}
@media (max-width: 600px) {
	.hm-kingdoms-grid { grid-template-columns: repeat(3, 1fr); }
	.hm-stats-bar { flex-wrap: wrap; }
	.hm-stat-item { flex: 1 1 50%; border-bottom: 1px solid #e2e8f0; }
	.hm-stat-item:nth-child(odd) { border-right: 1px solid #e2e8f0; }
	.hm-stat-item:last-child,
	.hm-stat-item:nth-last-child(2):nth-child(odd) { border-bottom: none; }
	.hm-stat-value { font-size: 20px; }
}
</style>

<!-- =============================================
     Welcome Banner
     ============================================= -->
<div class="hm-welcome-banner">
	<h1 class="hm-welcome-title">Welcome to the Amtgard Online Record Keeper</h1>
</div>

<!-- =============================================
     Stats Bar
     ============================================= -->
<div class="hm-stats-bar">
	<div class="hm-stat-item">
		<span class="hm-stat-value"><?= count($hmKingdoms) ?></span>
		<span class="hm-stat-label">Kingdoms</span>
	</div>
	<?php if (count($hmPrinz) > 0): ?>
	<div class="hm-stat-item">
		<span class="hm-stat-value"><?= count($hmPrinz) ?></span>
		<span class="hm-stat-label">Principalities</span>
	</div>
	<?php endif; ?>
	<div class="hm-stat-item">
		<span class="hm-stat-value"><?= $hmTotalParks ?></span>
		<span class="hm-stat-label">Parks</span>
	</div>
	<div class="hm-stat-item">
		<span class="hm-stat-value">~<?= number_format($hmWeeklyAvg) ?></span>
		<span class="hm-stat-label">Players / Week</span>
	</div>
	<div class="hm-stat-item">
		<span class="hm-stat-value"><?= count($hmEventList) ?></span>
		<span class="hm-stat-label">Upcoming Events</span>
	</div>
</div>

<!-- =============================================
     Kingdoms
     ============================================= -->
<div class="hm-section">
	<div class="hm-section-header">
		<span class="hm-section-title"><i class="fas fa-crown"></i> Kingdoms</span>
		<a class="hm-view-all" href="<?= UIR ?>Atlas">Atlas &rarr;</a>
	</div>
	<div class="hm-kingdoms-grid">
		<?php foreach ($hmKingdoms as $k): ?>
		<a class="hm-kingdom-card<?= !empty($k['_pinned']) ? ' hm-pinned' : '' ?>" href="<?= UIR ?>Kingdomnew/index/<?= (int)$k['KingdomId'] ?>">
			<div class="hm-card-heraldry-wrap">
				<?php if (!empty($k['_pinned'])): ?><span class="hm-pin-badge">Your Kingdom</span><?php endif; ?>
				<img class="hm-card-heraldry"
				     src="<?= htmlspecialchars($k['_heraldry']) ?>"
				     onerror="this.closest('.hm-card-heraldry-wrap').style.background='#edf2f7'"
				     alt="<?= htmlspecialchars(stripslashes($k['KingdomName'])) ?> heraldry">
			</div>
			<div class="hm-card-body">
				<div class="hm-card-name"><?= htmlspecialchars(stripslashes($k['KingdomName'])) ?></div>
				<div class="hm-card-stats">
					<span class="hm-card-stat">
						<i class="fas fa-map-marker-alt"></i>
						<?= (int)$k['ParkCount'] ?> park<?= (int)$k['ParkCount'] != 1 ? 's' : '' ?>
					</span>
					<span class="hm-card-stat">
						<i class="fas fa-users"></i>
						<?= number_format($k['_weekly'], 1) ?>/wk
					</span>
				</div>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
</div>

<!-- =============================================
     Principalities (if any)
     ============================================= -->
<?php if (count($hmPrinz) > 0): ?>
<div class="hm-section">
	<div class="hm-section-header">
		<span class="hm-section-title"><i class="fas fa-shield-alt"></i> Principalities</span>
	</div>
	<div class="hm-prinz-grid">
		<?php foreach ($hmPrinz as $p): ?>
		<a class="hm-prinz-card" href="<?= UIR ?>Kingdomnew/index/<?= (int)$p['KingdomId'] ?>">
			<img class="hm-prinz-heraldry"
			     src="<?= htmlspecialchars($p['_heraldry']) ?>"
			     onerror="this.style.display='none'"
			     alt="<?= htmlspecialchars(stripslashes($p['KingdomName'])) ?>">
			<div class="hm-prinz-name"><?= htmlspecialchars(stripslashes($p['KingdomName'])) ?></div>
			<div class="hm-prinz-stat"><?= (int)$p['ParkCount'] ?> parks &middot; <?= number_format($p['_weekly'], 1) ?>/wk</div>
		</a>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Bottom: Events + Find
     ============================================= -->
<div class="hm-bottom-row">

	<!-- Upcoming Events -->
	<div class="hm-bottom-main">
		<div class="hm-section-header">
			<span class="hm-section-title"><i class="fas fa-flag"></i> Upcoming Events</span>
			<a class="hm-view-all" href="<?= UIR ?>Search/event">View All &rarr;</a>
		</div>
		<?php if (count($hmEventList) > 0): ?>
		<div class="hm-events-list">
			<?php foreach ($hmEventList as $event): ?>
			<a class="hm-event-row" href="<?= UIR ?>Event/index/<?= (int)$event['EventId'] ?>">
				<div class="hm-event-heraldry">
					<?php if ($event['HasHeraldry'] == 1): ?>
						<img src="<?= HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', (int)$event['EventId'])) ?>"
						     onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'">
					<?php else: ?>
						<img src="<?= HTTP_EVENT_HERALDRY ?>00000.jpg">
					<?php endif; ?>
				</div>
				<div class="hm-event-info">
					<div class="hm-event-name"><?= htmlspecialchars($event['Name']) ?></div>
					<div class="hm-event-meta"><?= htmlspecialchars($event['KingdomName']) ?> &mdash; <?= htmlspecialchars($event['ParkName']) ?></div>
				</div>
				<?php if (!empty($event['NextDate']) && $event['NextDate'] !== '0'): ?>
				<div class="hm-event-date"><?= date('M j', strtotime($event['NextDate'])) ?></div>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php else: ?>
		<div class="hm-empty">No upcoming events</div>
		<?php endif; ?>
	</div>

	<!-- Find sidebar -->
	<div class="hm-bottom-side">
		<div class="hm-section-header">
			<span class="hm-section-title"><i class="fas fa-search"></i> Find</span>
		</div>
		<div class="hm-find-list">
			<a class="hm-find-item" href="<?= UIR ?>Search/index">
				<i class="fas fa-user"></i> Search Players
			</a>
			<a class="hm-find-item" href="<?= UIR ?>Search/unit">
				<i class="fas fa-users"></i> Companies &amp; Households
			</a>
			<a class="hm-find-item" href="<?= UIR ?>Search/event">
				<i class="fas fa-flag"></i> Find Events
			</a>
			<a class="hm-find-item" href="<?= UIR ?>Atlas">
				<i class="fas fa-map-marked-alt"></i> Kingdom Atlas
			</a>
			<a class="hm-find-item" href="https://play.amtgard.com" target="_blank" rel="noopener">
				<i class="fas fa-map-marker-alt"></i> Find a Chapter Near You
			</a>
		</div>
	</div>

</div><!-- /hm-bottom-row -->
