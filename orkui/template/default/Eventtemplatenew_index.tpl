<?php
	// ---- Normalize data ----
	$details     = $EventDetails ?? [];
	$info        = $EventInfo    ?? $details['EventInfo'][0] ?? [];
	$heraldryUrl = $details['HeraldryUrl']['Url'] ?? '';
	$hasHeraldry = !empty($details['HasHeraldry']);
	$eventName   = htmlspecialchars($details['Name'] ?? 'Event');
	$eventId     = (int)($info['EventId'] ?? 0);

	$kingdomId   = (int)($info['KingdomId'] ?? 0);
	$kingdomName = htmlspecialchars($info['KingdomName'] ?? '');
	$parkId      = (int)($info['ParkId']    ?? 0);
	$parkName    = htmlspecialchars($info['ParkName']    ?? '');
	$unitId      = (int)($info['UnitId']    ?? 0);
	$unitName    = htmlspecialchars($info['Unit']        ?? '');
	$mundaneId   = (int)($info['MundaneId'] ?? 0);
	$persona     = htmlspecialchars($info['Persona']     ?? '');

	$upcomingList = $Upcoming   ?? [];
	$pastList     = $Past       ?? [];
	$totalDates   = $TotalDates ?? 0;
	$nextDate     = $NextDate   ?? null;
	$canManage    = $CanManageEvent ?? false;
	$loggedIn     = $LoggedIn   ?? false;

	$nextDateLabel = $nextDate
		? date('M j, Y', strtotime($nextDate))
		: '<span style="color:#a0aec0">None</span>';

	// Owner label for stats card
	$ownerLabel = $parkName ?: $kingdomName ?: $unitName ?: $persona ?: '—';
?>

<style type="text/css">
/* ========================================
   Eventsnew — CRM-Style Event Template Profile
   All classes prefixed with en- to avoid collisions
   ======================================== */

.en-hero, .en-stats-row, .en-layout, .en-sidebar, .en-main,
.en-card, .en-tab-nav, .en-tab-panel, .en-table {
	box-sizing: border-box;
}

/* ---- Hero ---- */
.en-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 20px;
	min-height: 160px;
	background-color: #1a3a4f;
}
.en-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.14;
	filter: blur(6px);
}
.en-hero-content {
	position: relative;
	display: flex;
	align-items: center;
	padding: 24px 30px;
	gap: 24px;
	z-index: 1;
}
.en-heraldry-frame {
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
.en-heraldry-frame img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.en-hero-info { flex: 1; min-width: 0; }
.en-event-name {
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
.en-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}
.en-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	line-height: 1.4;
}
.en-badge-green { background: #c6f6d5; color: #276749; }
.en-owner-inline {
	color: rgba(255,255,255,0.85);
	font-size: 13px;
	line-height: 1.8;
}
.en-owner-inline a {
	color: #fff;
	font-weight: 600;
	text-decoration: none;
}
.en-owner-inline a:hover { text-decoration: underline; }
.en-owner-sep { margin: 0 8px; opacity: 0.35; }
.en-hero-actions {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}
.en-btn {
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
.en-btn:hover { opacity: 0.85; }
.en-btn-white   { background: #fff; color: #1a3a4f; }
.en-btn-outline { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.4); }

/* ---- Stats row ---- */
.en-stats-row {
	display: flex;
	background: #fff;
	border-bottom: 1px solid #e2e8f0;
}
.en-stat-card {
	flex: 1;
	padding: 14px 12px;
	text-align: center;
	border-right: 1px solid #e2e8f0;
}
.en-stat-card:last-child { border-right: none; }
.en-stat-icon { font-size: 13px; color: #a0aec0; margin-bottom: 3px; }
.en-stat-value { font-size: 22px; font-weight: 700; color: #2b6cb0; line-height: 1.1; }
.en-stat-label { font-size: 11px; color: #718096; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }

/* ---- Main layout ---- */
.en-layout {
	display: flex;
	align-items: flex-start;
	background: #f7f9fc;
	min-height: 400px;
}
.en-sidebar {
	width: 220px;
	flex-shrink: 0;
	padding: 16px 12px;
	border-right: 1px solid #e2e8f0;
	background: #fff;
}
.en-main { flex: 1; min-width: 0; padding: 16px; }

/* ---- Sidebar cards ---- */
.en-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 14px;
	margin-bottom: 12px;
}
.en-card h4 {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: #718096;
	margin: 0 0 10px 0;
}
.en-heraldry-large {
	width: 100%;
	aspect-ratio: 1;
	object-fit: contain;
	border-radius: 6px;
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	display: block;
	margin-bottom: 12px;
}
.en-link-list { list-style: none; margin: 0; padding: 0; }
.en-link-list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 5px 0;
	border-bottom: 1px solid #f0f0f0;
	font-size: 13px;
}
.en-link-list li:last-child { border-bottom: none; }
.en-link-icon { width: 18px; text-align: center; color: #a0aec0; font-size: 12px; flex-shrink: 0; }
.en-link-list a { color: #2b6cb0; text-decoration: none; }
.en-link-list a:hover { text-decoration: underline; }

/* ---- Tabs ---- */
.en-tabs { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
.en-tab-nav {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	background: #f7fafc;
	border-bottom: 1px solid #e2e8f0;
}
.en-tab-nav li {
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
.en-tab-nav li:hover { color: #2b6cb0; background: #edf2f7; }
.en-tab-active { color: #2b6cb0 !important; border-bottom-color: #2b6cb0 !important; font-weight: 600; }
.en-tab-count { font-size: 11px; color: #a0aec0; }
.en-tab-panel { padding: 16px; }

/* ---- Tables ---- */
.en-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.en-table th {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	padding: 8px 10px;
	text-align: left;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	white-space: nowrap;
}
.en-table td {
	border: 1px solid #e2e8f0;
	padding: 8px 10px;
	vertical-align: middle;
	color: #2d3748;
}
.en-table tr:hover td { background: #f7fafc; }
.en-table .en-past-row td { color: #a0aec0; }
.en-date-col { white-space: nowrap; }
.en-price-col { white-space: nowrap; color: #276749; font-weight: 600; }
.en-current-badge {
	display: inline-block;
	background: #c6f6d5;
	color: #276749;
	font-size: 10px;
	padding: 1px 7px;
	border-radius: 10px;
	font-weight: 600;
}

/* ---- Sub-section headers ---- */
.en-sub-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}
.en-sub-header h4 {
	font-size: 13px;
	font-weight: 700;
	color: #4a5568;
	margin: 0;
}
.en-sub-header h4 i { margin-right: 6px; color: #a0aec0; }
.en-empty { color: #a0aec0; font-size: 13px; padding: 12px 0; text-align: center; }
.en-section-divider {
	border: none;
	border-top: 1px solid #e2e8f0;
	margin: 20px 0 16px;
}

/* ---- Past toggle ---- */
.en-past-toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: #718096;
	cursor: pointer;
	padding: 4px 10px;
	border: 1px solid #e2e8f0;
	border-radius: 4px;
	background: #f7fafc;
	user-select: none;
}
.en-past-toggle:hover { background: #edf2f7; color: #2b6cb0; }

/* ---- Attendance link ---- */
.en-attend-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: #2b6cb0;
	text-decoration: none;
	font-size: 12px;
}
.en-attend-link:hover { text-decoration: underline; }
</style>

<?php // ---- HERO ---- ?>
<div class="en-hero" id="en-hero">
	<div class="en-hero-bg" id="en-hero-bg"
		<?php if ($heraldryUrl): ?>
			style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"
		<?php endif; ?>
	></div>
	<div class="en-hero-content">

		<div class="en-heraldry-frame">
			<img id="en-heraldry-img"
				src="<?= $heraldryUrl ?: (HTTP_EVENT_HERALDRY . '00000.jpg') ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="<?= $eventName ?> heraldry"
				crossorigin="anonymous">
		</div>

		<div class="en-hero-info">
			<h1 class="en-event-name"><?= $eventName ?></h1>
			<div class="en-badges">
				<span class="en-badge en-badge-green">
					<i class="fas fa-layer-group"></i> Event Template
				</span>
			</div>
			<div class="en-owner-inline">
				<?php if ($kingdomId): ?>
					<i class="fas fa-crown" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					<a href="<?= UIR ?>Kingdomnew/index/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php if ($kingdomId && $parkId): ?>
					<span class="en-owner-sep">›</span>
				<?php endif; ?>
				<?php if ($parkId): ?>
					<a href="<?= UIR ?>Parknew/index/<?= $parkId ?>"><?= $parkName ?></a>
				<?php endif; ?>
				<?php if ($unitId): ?>
					<?php if ($kingdomId || $parkId): ?><span class="en-owner-sep">›</span><?php endif; ?>
					<a href="<?= UIR ?>Unit/index/<?= $unitId ?>"><?= $unitName ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="en-hero-actions">
			<a class="en-btn en-btn-white"
				href="<?= UIR ?>Reports/attendance/Event/<?= $eventId ?>/All">
				<i class="fas fa-list-alt"></i> Attendance Report
			</a>
			<?php if ($canManage): ?>
			<a class="en-btn en-btn-outline"
				href="<?= UIR ?>Admin/event/<?= $eventId ?>">
				<i class="fas fa-calendar-plus"></i> Schedule Date
			</a>
			<?php endif; ?>
			<?php if ($loggedIn): ?>
			<a class="en-btn en-btn-outline"
				href="<?= UIR ?>Admin/event/<?= $eventId ?>">
				<i class="fas fa-cog"></i> Admin Panel
			</a>
			<?php endif; ?>
		</div>

	</div>
</div>

<?php // ---- STATS ROW ---- ?>
<div class="en-stats-row">
	<div class="en-stat-card">
		<div class="en-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="en-stat-value"><?= $totalDates ?></div>
		<div class="en-stat-label">Dates Scheduled</div>
	</div>
	<div class="en-stat-card">
		<div class="en-stat-icon"><i class="fas fa-clock"></i></div>
		<div class="en-stat-value"><?= count($upcomingList) ?></div>
		<div class="en-stat-label">Upcoming</div>
	</div>
	<div class="en-stat-card">
		<div class="en-stat-icon"><i class="fas fa-star"></i></div>
		<div class="en-stat-value" style="font-size:15px;padding-top:3px"><?= $nextDateLabel ?></div>
		<div class="en-stat-label">Next Date</div>
	</div>
	<div class="en-stat-card">
		<div class="en-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="en-stat-value" style="font-size:14px;padding-top:3px"><?= $ownerLabel ?></div>
		<div class="en-stat-label">Hosted By</div>
	</div>
</div>

<?php // ---- LAYOUT ---- ?>
<div class="en-layout">

	<?php // ---- SIDEBAR ---- ?>
	<div class="en-sidebar">

		<?php // Heraldry ?>
		<img class="en-heraldry-large"
			src="<?= $heraldryUrl ?: (HTTP_EVENT_HERALDRY . '00000.jpg') ?>"
			onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
			alt="">

		<?php // Owned By ?>
		<div class="en-card">
			<h4><i class="fas fa-sitemap" style="margin-right:5px"></i>Owned By</h4>
			<ul class="en-link-list">
				<?php if ($kingdomId): ?>
				<li>
					<span class="en-link-icon"><i class="fas fa-crown"></i></span>
					<a href="<?= UIR ?>Kingdomnew/index/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				</li>
				<?php endif; ?>
				<?php if ($parkId): ?>
				<li>
					<span class="en-link-icon"><i class="fas fa-map-marker-alt"></i></span>
					<a href="<?= UIR ?>Parknew/index/<?= $parkId ?>"><?= $parkName ?></a>
				</li>
				<?php endif; ?>
				<?php if ($unitId): ?>
				<li>
					<span class="en-link-icon"><i class="fas fa-shield-alt"></i></span>
					<a href="<?= UIR ?>Unit/index/<?= $unitId ?>"><?= $unitName ?></a>
				</li>
				<?php endif; ?>
				<?php if ($mundaneId): ?>
				<li>
					<span class="en-link-icon"><i class="fas fa-user"></i></span>
					<a href="<?= UIR ?>Playernew/index/<?= $mundaneId ?>"><?= $persona ?></a>
				</li>
				<?php endif; ?>
			</ul>
		</div>

		<?php // Quick Links ?>
		<div class="en-card">
			<h4><i class="fas fa-link" style="margin-right:5px"></i>Quick Links</h4>
			<ul class="en-link-list">
				<li>
					<span class="en-link-icon"><i class="fas fa-list-alt"></i></span>
					<a href="<?= UIR ?>Reports/attendance/Event/<?= $eventId ?>/All">Full Attendance</a>
				</li>
				<?php if ($loggedIn): ?>
				<li>
					<span class="en-link-icon"><i class="fas fa-cog"></i></span>
					<a href="<?= UIR ?>Admin/event/<?= $eventId ?>">Manage Template</a>
				</li>
				<?php endif; ?>
			</ul>
		</div>

	</div><!-- /.en-sidebar -->

	<?php // ---- MAIN CONTENT ---- ?>
	<div class="en-main">
		<div class="en-tabs">

			<ul class="en-tab-nav">
				<li class="en-tab-active" data-tab="en-tab-dates">
					<i class="fas fa-calendar-check"></i> Scheduled Dates
					<span class="en-tab-count"><?= $totalDates ?></span>
				</li>
			</ul>

			<?php // ---- Scheduled Dates Tab ---- ?>
			<div class="en-tab-panel" id="en-tab-dates">

				<?php // Upcoming sub-section ?>
				<div class="en-sub-header">
					<h4><i class="fas fa-clock"></i> Upcoming</h4>
					<?php if ($canManage): ?>
					<a href="<?= UIR ?>Admin/event/<?= $eventId ?>"
						style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;">
						<i class="fas fa-plus"></i> Add Scheduled Date
					</a>
					<?php endif; ?>
				</div>

				<?php if (count($upcomingList) > 0): ?>
				<table class="en-table" style="margin-bottom:0">
					<thead>
						<tr>
							<th>Date</th>
							<th>End</th>
							<th>Location</th>
							<th>Price</th>
							<th>Links</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($upcomingList as $cd): ?>
						<tr>
							<td class="en-date-col">
								<?= date('M j, Y', strtotime($cd['EventStart'])) ?>
								<?php if ($cd['Current'] == 1): ?>
									<span class="en-current-badge">Current</span>
								<?php endif; ?>
							</td>
							<td class="en-date-col">
								<?= $cd['EventEnd'] ? date('M j, Y', strtotime($cd['EventEnd'])) : '—' ?>
							</td>
							<td>
								<?php if ($cd['_MapLink']): ?>
									<a href="<?= htmlspecialchars($cd['_MapLink']) ?>" target="_blank"
										style="color:#2b6cb0;text-decoration:none;">
										<i class="fas fa-map-marker-alt" style="color:#fc8181;margin-right:4px"></i><?= htmlspecialchars($cd['_LocationDisplay']) ?: 'Map' ?>
									</a>
								<?php else: ?>
									<?= htmlspecialchars($cd['_LocationDisplay']) ?: '<span style="color:#a0aec0">—</span>' ?>
								<?php endif; ?>
							</td>
							<td class="en-price-col">
								<?= $cd['Price'] > 0 ? '$' . number_format((float)$cd['Price'], 2) : '<span style="color:#276749">Free</span>' ?>
							</td>
							<td style="white-space:nowrap">
								<a class="en-attend-link"
									href="<?= UIR ?>Eventnew/index/<?= $eventId ?>/<?= $cd['EventCalendarDetailId'] ?>">
									<i class="fas fa-info-circle"></i> Details
								</a>
								&nbsp;
								<a class="en-attend-link"
									href="<?= UIR ?>Attendance/event/<?= $eventId ?>/<?= $cd['EventCalendarDetailId'] ?>">
									<i class="fas fa-clipboard-list"></i> Attendance
								</a>
							</td>
						</tr>
						<?php if (!empty(trim($cd['Description'] ?? ''))): ?>
						<tr>
							<td colspan="5" style="background:#fafafa;color:#4a5568;font-size:12px;padding:6px 10px;">
								<?= nl2br(htmlspecialchars(strip_tags($cd['Description']))) ?>
							</td>
						</tr>
						<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
					<div class="en-empty"><i class="fas fa-calendar" style="margin-right:6px"></i>No upcoming dates scheduled</div>
				<?php endif; ?>

				<?php // Past sub-section ?>
				<?php if (count($pastList) > 0): ?>
				<hr class="en-section-divider">
				<div class="en-sub-header">
					<h4><i class="fas fa-history"></i> Past Dates</h4>
					<span class="en-past-toggle" id="en-past-toggle" onclick="enTogglePast(this)">
						<i class="fas fa-chevron-down" id="en-past-chevron"></i>
						Show <?= count($pastList) ?> past date<?= count($pastList) == 1 ? '' : 's' ?>
					</span>
				</div>
				<div id="en-past-section" style="display:none">
					<table class="en-table">
						<thead>
							<tr>
								<th>Date</th>
								<th>End</th>
								<th>Location</th>
								<th>Price</th>
								<th>Links</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($pastList as $cd): ?>
							<tr class="en-past-row">
								<td class="en-date-col"><?= date('M j, Y', strtotime($cd['EventStart'])) ?></td>
								<td class="en-date-col">
									<?= $cd['EventEnd'] ? date('M j, Y', strtotime($cd['EventEnd'])) : '—' ?>
								</td>
								<td>
									<?php if ($cd['_MapLink']): ?>
										<a href="<?= htmlspecialchars($cd['_MapLink']) ?>" target="_blank"
											style="color:#2b6cb0;text-decoration:none;opacity:0.7">
											<?= htmlspecialchars($cd['_LocationDisplay']) ?: 'Map' ?>
										</a>
									<?php else: ?>
										<?= htmlspecialchars($cd['_LocationDisplay']) ?: '<span style="color:#a0aec0">—</span>' ?>
									<?php endif; ?>
								</td>
								<td>
									<?= $cd['Price'] > 0 ? '$' . number_format((float)$cd['Price'], 2) : 'Free' ?>
								</td>
								<td style="white-space:nowrap">
									<a class="en-attend-link"
										href="<?= UIR ?>Eventnew/index/<?= $eventId ?>/<?= $cd['EventCalendarDetailId'] ?>">
										<i class="fas fa-info-circle"></i> Details
									</a>
									&nbsp;
									<a class="en-attend-link"
										href="<?= UIR ?>Attendance/event/<?= $eventId ?>/<?= $cd['EventCalendarDetailId'] ?>">
										<i class="fas fa-clipboard-list"></i> Attendance
									</a>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

			</div><!-- /.en-tab-panel -->

		</div><!-- /.en-tabs -->
	</div><!-- /.en-main -->

</div><!-- /.en-layout -->

<script>
(function() {
	// ---- Past dates toggle ----
	window.enTogglePast = function(btn) {
		var section  = document.getElementById('en-past-section');
		var chevron  = document.getElementById('en-past-chevron');
		var count    = <?= count($pastList) ?>;
		var isHidden = section.style.display === 'none';
		section.style.display = isHidden ? '' : 'none';
		chevron.className = isHidden ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
		btn.childNodes[btn.childNodes.length - 1].textContent =
			isHidden
				? ' Hide past dates'
				: ' Show ' + count + ' past date' + (count === 1 ? '' : 's');
	};

	// ---- Hero dominant-color tint ----
	function enApplyHeroColor() {
		var img = document.getElementById('en-heraldry-img');
		if (!img) return;
		function extract() {
			try {
				var c = document.createElement('canvas');
				c.width = 32; c.height = 32;
				var ctx = c.getContext('2d');
				ctx.drawImage(img, 0, 0, 32, 32);
				var d = ctx.getImageData(0, 0, 32, 32).data;
				var r=0,g=0,b=0,count=0;
				for (var i=0;i<d.length;i+=4) {
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
				var hero = document.getElementById('en-hero');
				if (hero) hero.style.backgroundColor = 'hsl('+Math.round(h)+','+Math.round(s*55)+'%,18%)';
			} catch(e){}
		}
		if (img.complete && img.naturalWidth > 0) { extract(); }
		else { img.addEventListener('load', extract); }
	}
	enApplyHeroColor();
})();
</script>
