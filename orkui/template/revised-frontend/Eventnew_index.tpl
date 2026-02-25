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
	$atParkId    = (int)($cd['AtParkId']   ?? 0);
	$atParkName  = htmlspecialchars($AtParkName         ?? '');
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

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css">

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
			</div>
			<div class="ev-owner-inline">
				<i class="fas fa-layer-group" style="font-size:10px;opacity:0.6;margin-right:4px"></i>
				<a href="<?= UIR ?>Event/template/<?= $eventId ?>"><?= $eventName ?></a>
				<?php if ($kingdomId): ?>
					<span class="ev-owner-sep">›</span>
					<a href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php
					$breadcrumbParkId   = $atParkId   ?: $parkId;
					$breadcrumbParkName = $atParkId ? $atParkName : $parkName;
				?>
				<?php if ($breadcrumbParkId): ?>
					<span class="ev-owner-sep">›</span>
					<a href="<?= UIR ?>Park/profile/<?= $breadcrumbParkId ?>"><?= $breadcrumbParkName ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="ev-hero-actions">
			<a class="ev-btn ev-btn-white"
				href="<?= UIR ?>Reports/attendance/Event/<?= $eventId ?>/All">
				<i class="fas fa-list-alt"></i> Attendance Report
			</a>
			<a class="ev-btn ev-btn-outline"
				href="<?= UIR ?>Event/template/<?= $eventId ?>">
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
					<a href="<?= UIR ?>Event/template/<?= $eventId ?>">Event Template</a>
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
					<form method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/new">
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
							<td><a href="<?= UIR ?>Player/profile/<?= (int)$att['MundaneId'] ?>"><?= htmlspecialchars($att['Persona']) ?></a></td>
							<td><a href="<?= UIR ?>Kingdom/profile/<?= (int)$att['KingdomId'] ?>"><?= htmlspecialchars($att['KingdomName']) ?></a></td>
							<td><a href="<?= UIR ?>Park/profile/<?= (int)$att['ParkId'] ?>"><?= htmlspecialchars($att['ParkName']) ?></a></td>
							<td><?= htmlspecialchars($att['ClassName']) ?></td>
							<td><?= htmlspecialchars($att['Credits']) ?></td>
							<?php if ($loggedIn): ?>
							<td class="ev-del-cell">
								<a class="ev-del-link" title="Remove"
									href="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/delete/<?= (int)$att['AttendanceId'] ?>"
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
		<form method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/edit">
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
var EvConfig = {
	uir:        '<?= UIR ?>',
	httpService:'<?= HTTP_SERVICE ?>',
	canManage:  <?= !empty($canManage) ? 'true' : 'false' ?>,
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js"></script>
