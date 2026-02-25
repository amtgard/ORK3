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

	// FullCalendar event objects (all occurrences)
	$enCalEvents = [];
	$now = time();
	foreach ( array_merge($upcomingList, $pastList) as $cd ) {
		$start   = date('Y-m-d', strtotime($cd['EventStart']));
		$end     = !empty($cd['EventEnd']) ? date('Y-m-d', strtotime($cd['EventEnd'])) : null;
		$isFuture = strtotime($cd['EventStart']) >= $now;
		$loc     = htmlspecialchars($cd['_LocationDisplay'] ?? '');
		$enCalEvents[] = [
			'id'              => (int)$cd['EventCalendarDetailId'],
			'title'           => $loc ?: date('M j, Y', strtotime($cd['EventStart'])),
			'start'           => $start,
			'end'             => $end,
			'url'             => UIR . 'Event/detail/' . $eventId . '/' . $cd['EventCalendarDetailId'],
			'backgroundColor' => $isFuture ? '#2b6cb0' : '#a0aec0',
			'borderColor'     => $isFuture ? '#2c5282' : '#718096',
			'textColor'       => '#ffffff',
		];
	}
	$enCalJson    = json_encode($enCalEvents, JSON_HEX_TAG | JSON_HEX_AMP);
	$enCalInitDate = $nextDate ? date('Y-m-d', strtotime($nextDate)) : date('Y-m-d');
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css">

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
					<a href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php if ($kingdomId && $parkId): ?>
					<span class="en-owner-sep">›</span>
				<?php endif; ?>
				<?php if ($parkId): ?>
					<a href="<?= UIR ?>Park/profile/<?= $parkId ?>"><?= $parkName ?></a>
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
			<button class="en-btn en-btn-outline" onclick="enOpenCreateModal()"
				style="border:none;cursor:pointer;">
				<i class="fas fa-calendar-plus"></i> Add Occurrence
			</button>
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
					<a href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				</li>
				<?php endif; ?>
				<?php if ($parkId): ?>
				<li>
					<span class="en-link-icon"><i class="fas fa-map-marker-alt"></i></span>
					<a href="<?= UIR ?>Park/profile/<?= $parkId ?>"><?= $parkName ?></a>
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
					<a href="<?= UIR ?>Player/profile/<?= $mundaneId ?>"><?= $persona ?></a>
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

			<ul class="en-tab-nav" id="en-tab-nav">
				<li class="en-tab-active" data-tab="en-tab-dates" onclick="enTab(this)">
					<i class="fas fa-calendar-check"></i> Scheduled Dates
					<span class="en-tab-count"><?= $totalDates ?></span>
				</li>
				<li data-tab="en-tab-calendar" onclick="enTab(this)">
					<i class="fas fa-calendar-alt"></i> Calendar
				</li>
			</ul>

			<?php // ---- Scheduled Dates Tab ---- ?>
			<div class="en-tab-panel" id="en-tab-dates">

				<?php // Upcoming sub-section ?>
				<div class="en-sub-header">
					<h4><i class="fas fa-clock"></i> Upcoming</h4>
					<?php if ($canManage): ?>
					<button onclick="enOpenCreateModal()"
						style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
						<i class="fas fa-plus"></i> Add Occurrence
					</button>
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
									href="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $cd['EventCalendarDetailId'] ?>">
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
										href="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $cd['EventCalendarDetailId'] ?>">
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

			</div><!-- /.en-tab-panel #en-tab-dates -->

			<?php // ---- Calendar Tab ---- ?>
			<div class="en-tab-panel" id="en-tab-calendar" style="display:none">
				<div id="en-calendar"></div>
			</div><!-- /.en-tab-panel #en-tab-calendar -->

		</div><!-- /.en-tabs -->
	</div><!-- /.en-main -->

</div><!-- /.en-layout -->

<script src="<?= HTTP_TEMPLATE ?>default/script/js/fullcalendar/core.global.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/js/fullcalendar/daygrid.global.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/js/fullcalendar/interaction.global.min.js"></script>

<script>
var EnConfig = {
	uir:        '<?= UIR ?>',
	httpService:'<?= HTTP_SERVICE ?>',
	calInitDate:<?= json_encode($enCalInitDate ?? '') ?>,
	calEvents:  <?= $enCalJson ?? '[]' ?>,
	pastCount:  <?= (int)count($pastList ?? []) ?>,
	canManage:  <?= !empty($canManage) ? 'true' : 'false' ?>,
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js"></script>

<?php if ($canManage): ?>

<div class="en-cmod-overlay" id="en-create-modal">
	<div class="en-cmod-box">
		<div class="en-cmod-header">
			<h3><i class="fas fa-calendar-plus" style="color:#276749;margin-right:8px"></i>Add New Occurrence</h3>
			<button class="en-cmod-close" onclick="enCloseCreateModal()">&times;</button>
		</div>
		<div class="en-cmod-body">
			<p class="en-cmod-event-name">
				<i class="fas fa-layer-group" style="color:#a0aec0;font-size:14px"></i>
				<?= $eventName ?>
			</p>
			<p>Create a new scheduled occurrence for this event template. You'll configure the dates, location, pricing, and other details on the next page.</p>
		</div>
		<div class="en-cmod-footer">
			<button class="en-cmod-btn-cancel" onclick="enCloseCreateModal()">Cancel</button>
			<a class="en-cmod-btn-go" href="<?= UIR ?>Event/create/<?= $eventId ?>">
				Continue <i class="fas fa-arrow-right"></i>
			</a>
		</div>
	</div>
</div>
<?php endif; ?>
