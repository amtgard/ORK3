<?php
	require_once(DIR_LIB . 'Parsedown.php');
	function ev_markdown(string $text): string {
		$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($text);
		return preg_replace('/<img[^>]*>/i', '', $html);
	}

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
	$address    = $cd['Address']    ?? '';
	$city       = $cd['City']       ?? '';
	$province   = $cd['Province']   ?? '';
	$postalCode = $cd['PostalCode'] ?? '';
	$country    = $cd['Country']    ?? '';
	$locationDisplay = implode(', ', array_filter([$address, $city, $province, $country]));
	$mapQueryAddress = implode(', ', array_filter([$address, $city, $province, $postalCode, $country]));

	// Park address fallback (used when event has no address)
	$atParkAddress    = trim($AtParkAddress    ?? '');
	$atParkCity       = trim($AtParkCity       ?? '');
	$atParkProvince   = trim($AtParkProvince   ?? '');
	$atParkPostalCode = trim($AtParkPostalCode ?? '');
	$locationFallback = (!$locationDisplay && ($atParkCity || $atParkProvince))
		? implode(', ', array_filter([$atParkCity, $atParkProvince])) : '';

	// Park map link fallback: parse park location JSON when event has no map link
	if (!$mapLink && $locationFallback) {
		$_parkLoc = @json_decode(stripslashes((string)($AtParkLocation ?? '')));
		if ($_parkLoc) {
			$_parkPt = isset($_parkLoc->location) ? $_parkLoc->location
				: (isset($_parkLoc->bounds->northeast) ? $_parkLoc->bounds->northeast : null);
			if ($_parkPt && is_numeric($_parkPt->lat ?? null))
				$mapLink = 'https://maps.google.com/maps?q=@' . $_parkPt->lat . ',' . $_parkPt->lng;
		}
	}

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

	// [TOURNAMENTS HIDDEN]
	$tournaments  = [];
	$tourneyCount = 0;
	$attendanceList = $AttendanceReport['Attendance'] ?? [];
	$checkedInIds   = array_flip(array_column($attendanceList, 'MundaneId'));
	$attendanceForm = $Attendance_event ?? [];

	$defaultKingdomName = $DefaultKingdomName       ?? '';
	$defaultKingdomId   = $DefaultKingdomId         ?? 0;
	$defaultParkName    = $DefaultParkName          ?? '';
	$defaultParkId      = $DefaultParkId            ?? 0;
	$defaultCredits     = $DefaultAttendanceCredits ?? 1;

	// Detect attendance-date mismatch: event is in the future but attendance dates are all in the past
	$attDateMismatch = false;
	if (!empty($attendanceList) && $eventStart && strtotime($eventStart) > time()) {
		$allPast = true;
		foreach ($attendanceList as $_ar) {
			if (!empty($_ar['Date']) && strtotime($_ar['Date']) >= strtotime(date('Y-m-d'))) {
				$allPast = false;
				break;
			}
		}
		$attDateMismatch = $allPast;
	}

	$rsvpCounts    = is_array($RsvpCount ?? null) ? $RsvpCount : ['going' => 0, 'interested' => 0, 'total' => (int)($RsvpCount ?? 0)];
	$rsvpCount     = $rsvpCounts['total'];
	$userAttending = $UserAttending ?? false; // false or 'going' or 'interested'
	$rsvpList      = $RsvpList ?? [];
	$canManage           = $CanManageEvent ?? false;
	$canManageAttendance = $CanManageAttendance ?? false;
	$canDelete           = ($attendeeCount === 0 && $rsvpCount === 0);

	// Timezone
	$eventTz     = $EventTimezone     ?? 'UTC';
	$eventTzAbbr = $EventTimezoneAbbr ?? '';

	// Date badge label
	$startLabel = $eventStart ? date('M j, Y', strtotime($eventStart)) : '';
	$endLabel   = $eventEnd   ? date('M j, Y', strtotime($eventEnd))   : '';
	$dateBadgeText = $startLabel;
	if ( $endLabel && $endLabel !== $startLabel ) $dateBadgeText .= ' – ' . $endLabel;

	// Past-event check (date only, ignoring time)
	$_refDateStr  = $eventEnd ?: $eventStart;
	$isPastEvent  = $_refDateStr && (strtotime(date('Y-m-d', strtotime($_refDateStr))) < strtotime(date('Y-m-d')));
	// 24-hour check-in window
	$checkinOpenTs    = $eventStart ? strtotime($eventStart) - 86400 : 0;
	$checkinOpen      = !$isUpcoming || !$checkinOpenTs || time() >= $checkinOpenTs;
	$checkinOpenLabel = $checkinOpenTs ? date('D, M j, Y \\a\\t g:i A T', $checkinOpenTs) : '';
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
.ev-export-bar { display: flex; justify-content: flex-end; gap: 6px; margin-bottom: 10px; }
.ev-checkin-locked { display:flex; align-items:flex-start; gap:10px; background:#fffbeb; border:1px solid #f6e05e; border-radius:7px; padding:11px 14px; margin-bottom:14px; font-size:13px; color:#744210; line-height:1.45; }
.ev-checkin-locked i { color:#d69e2e; margin-top:1px; flex-shrink:0; }
.ev-icon-btn { background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 5px 9px; font-size: 13px; color: #4a5568; cursor: pointer; transition: background .15s, border-color .15s; line-height: 1; }
.ev-icon-btn:hover { background: #edf2f7; border-color: #cbd5e0; }
.ev-modal-btn-delete {
	background: #fff0f0; border: 1px solid #fc8181; color: #c53030;
	padding: 8px 14px; border-radius: 5px; font-size: 13px; font-weight: 600;
	cursor: pointer; transition: background .15s, border-color .15s;
}
.ev-modal-btn-delete:hover:not(:disabled) { background: #fed7d7; border-color: #e53e3e; }
.ev-modal-btn-delete-disabled { opacity: .45; cursor: not-allowed; }
.ev-del-detail-wrap { position: relative; display: inline-block; }
.ev-del-detail-tooltip {
	display: none; position: fixed; background: #1a202c; color: #fff; font-size: 12px;
	padding: 6px 11px; border-radius: 4px; white-space: nowrap;
	pointer-events: none; z-index: 9999; box-shadow: 0 2px 8px rgba(0,0,0,.25);
	transform: translateX(0) translateY(calc(-100% - 8px));
}
.ev-del-detail-tooltip::after {
	content: ''; position: absolute; top: 100%; left: 14px;
	border: 5px solid transparent; border-top-color: #1a202c;
}
.ev-del-detail-wrap:hover .ev-del-detail-tooltip { display: block; }
/* Heraldry edit overlay */
.ev-heraldry-edit-wrap { position: relative; display: inline-block; cursor: pointer; }
.ev-heraldry-edit-overlay {
	position: absolute; inset: 0; background: rgba(0,0,0,0); border-radius: 6px;
	display: flex; align-items: center; justify-content: center; transition: background .2s;
}
.ev-heraldry-edit-wrap:hover .ev-heraldry-edit-overlay { background: rgba(0,0,0,0.45); }
.ev-heraldry-edit-icon { color: #fff; font-size: 22px; opacity: 0; transition: opacity .2s; }
.ev-heraldry-edit-wrap:hover .ev-heraldry-edit-icon { opacity: 1; }
/* Image upload modal */
.ev-img-overlay {
	display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55);
	z-index: 1500; align-items: center; justify-content: center;
}
.ev-img-overlay.ev-open { display: flex; }
.ev-img-modal {
	background: #fff; border-radius: 10px; width: min(520px, 96vw);
	box-shadow: 0 8px 32px rgba(0,0,0,.22); overflow: hidden;
}
.ev-img-modal-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #f7fafc;
}
.ev-img-modal-title { font-size: 15px; font-weight: 700; color: #2d3748; margin: 0; }
.ev-img-close-btn { background: none; border: none; font-size: 20px; color: #718096; cursor: pointer; padding: 0 4px; }
.ev-img-modal-body { padding: 20px 22px; }
.ev-upload-area {
	display: flex; flex-direction: column; align-items: center; gap: 8px;
	border: 2px dashed #cbd5e0; border-radius: 8px; padding: 28px 20px;
	cursor: pointer; color: #4a5568; font-size: 14px; text-align: center;
	transition: border-color .15s, background .15s;
}
.ev-upload-area:hover { border-color: #4299e1; background: #ebf8ff; }
.ev-upload-icon { font-size: 32px; color: #a0aec0; }
.ev-upload-area small { font-size: 12px; color: #a0aec0; }
.ev-img-step-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
.ev-crop-wrap { overflow: auto; max-height: 360px; display: flex; justify-content: center; }
.ev-img-form-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 8px 12px; border-radius: 5px; font-size: 13px; margin-top: 8px; }
.ev-fp-title { background: #2b6cb0; color: #fff; font-size: 12px; font-weight: 700; padding: 6px 12px; text-align: center; letter-spacing: .04em; }
</style>

<?php // ---- HERO ---- ?>
<div class="ev-hero" id="ev-hero">
	<div class="ev-hero-bg"
		<?php if ($heraldryUrl): ?>
			style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"
		<?php endif; ?>
	></div>
	<div class="ev-hero-content">

		<div class="ev-heraldry-frame<?= $canManage ? ' ev-heraldry-edit-wrap' : '' ?>"<?= $canManage ? ' onclick="evOpenImgModal()" title="Change heraldry"' : '' ?>>
			<img id="ev-heraldry-img"
				src="<?= htmlspecialchars($heraldryUrl) ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="<?= $eventName ?> heraldry"
				crossorigin="anonymous">
			<?php if ($canManage): ?>
			<div class="ev-heraldry-edit-overlay"><i class="fas fa-camera ev-heraldry-edit-icon"></i></div>
			<?php endif; ?>
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
				<?= $eventName ?>
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
				href="<?= UIR ?>Reports/event_attendance/Kingdom/<?= $kingdomId ?>&filter=<?= urlencode($info['Name'] ?? '') ?>">
				<i class="fas fa-list-alt"></i> Attendance Report
			</a>
			<?php if ($CanManageEvent ?? false): ?>
			<button class="ev-btn ev-btn-outline" type="button" onclick="evOpenEditModal()">
				<i class="fas fa-pencil-alt"></i> Edit Details
			</button>
			<?php endif; ?>
			<?php if ($loggedIn && $isUpcoming): ?>
			<form method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/rsvp" style="margin:0;display:inline-flex;gap:6px">
				<button type="submit" name="status" value="going"
					class="ev-btn <?= $userAttending === 'going' ? 'ev-btn-primary' : 'ev-btn-outline' ?>">
					<i class="fas fa-check-circle"></i> <?= $userAttending === 'going' ? 'Going ✓' : 'Going' ?>
				</button>
				<button type="submit" name="status" value="interested"
					class="ev-btn <?= $userAttending === 'interested' ? 'ev-btn-secondary' : 'ev-btn-outline' ?>">
					<i class="fas fa-star"></i> <?= $userAttending === 'interested' ? 'Interested ✓' : 'Interested' ?>
				</button>
			</form>
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
	<?php $hasMapTab = (bool)($locationDisplay ?: $locationFallback); ?>
	<?php if (!$locationDisplay && $mapUrl): ?>
	<a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" class="ev-stat-card ev-stat-card-link" style="cursor:pointer;text-decoration:none;color:inherit">
		<div class="ev-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="ev-stat-value" style="font-size:14px;padding-top:3px;color:#4a90d9"><?= htmlspecialchars($mapUrlName ?: 'View Map') ?></div>
		<div class="ev-stat-label">Location</div>
	</a>
	<?php else: ?>
	<div class="ev-stat-card<?= $hasMapTab ? ' ev-stat-card-link' : '' ?>"<?= $hasMapTab ? ' onclick="evShowTab(document.querySelector(\'[data-tab=ev-tab-map]\'),\'ev-tab-map\')" title="View map"' : '' ?> style="<?= $hasMapTab ? 'cursor:pointer' : '' ?>">
		<div class="ev-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="ev-stat-value" style="font-size:14px;padding-top:3px">
			<?php $_dispLoc = $locationDisplay ?: $locationFallback; ?>
			<?= $_dispLoc ? htmlspecialchars($_dispLoc) : '<span style="color:#a0aec0">TBD</span>' ?>
		</div>
		<div class="ev-stat-label">Location</div>
	</div>
	<?php endif; ?>
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-users"></i></div>
		<div class="ev-stat-value"><?= $isUpcoming ? $rsvpCount : $attendeeCount ?></div>
		<div class="ev-stat-label"><?= $isUpcoming ? 'RSVPs' : 'Attendees' ?></div>
	</div>
</div>

<?php // ---- LAYOUT ---- ?>
<div class="ev-layout">

	<?php // ---- SIDEBAR ---- ?>
	<div class="ev-sidebar">

		<?php if ($canManage): ?>
		<div class="ev-heraldry-edit-wrap" onclick="evOpenImgModal()" title="Change heraldry">
			<img class="ev-heraldry-large"
				src="<?= htmlspecialchars($heraldryUrl) ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="">
			<div class="ev-heraldry-edit-overlay"><i class="fas fa-camera ev-heraldry-edit-icon"></i></div>
		</div>
		<?php else: ?>
		<img class="ev-heraldry-large"
			src="<?= htmlspecialchars($heraldryUrl) ?>"
			onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
			alt="">
		<?php endif; ?>

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
				<span class="ev-detail-value"><?= date('g:i A', strtotime($eventStart)) ?><?php if ($eventTzAbbr): ?> <span class="ev-tz-badge"><?= htmlspecialchars($eventTzAbbr) ?></span><?php endif; ?></span>
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

		<?php
			$_showAddress  = $address  ?: $atParkAddress;
			$_showCity     = $city     ?: $atParkCity;
			$_showProvince = $province ?: $atParkProvince;
			$_fromPark     = !($address || $city || $province) && ($atParkCity || $atParkProvince || $atParkAddress);
		?>
		<?php if ($locationDisplay || $locationFallback || $mapLink): ?>
		<?php // Location card ?>
		<div class="ev-card">
			<h4><i class="fas fa-map-marker-alt" style="margin-right:5px"></i>Location<?php if ($_fromPark): ?> <span style="font-size:11px;font-weight:400;color:#718096;margin-left:4px">(park address)</span><?php endif; ?></h4>
			<?php if ($_showAddress): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Address</span>
				<span class="ev-detail-value"><?= htmlspecialchars($_showAddress) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($_showCity): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">City</span>
				<span class="ev-detail-value"><?= htmlspecialchars($_showCity) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($_showProvince): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Region</span>
				<span class="ev-detail-value"><?= htmlspecialchars($_showProvince) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($postalCode): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Postal Code</span>
				<span class="ev-detail-value"><?= htmlspecialchars($postalCode) ?></span>
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


	</div><!-- /.ev-sidebar -->

	<?php // ---- MAIN CONTENT ---- ?>
	<div class="ev-main">

		<?php if (!empty($Error)): ?>
		<div class="ev-error"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i><?= htmlspecialchars($Error ?? '') ?></div>
		<?php endif; ?>

		<div class="ev-tabs">

			<ul class="ev-tab-nav" id="ev-tab-nav">
				<li class="ev-tab-active" data-tab="ev-tab-details" onclick="evShowTab(this,'ev-tab-details')">
					<i class="fas fa-align-left"></i><span class="ev-tab-label"> Details</span>
				</li>
				<li data-tab="ev-tab-attendance" onclick="evShowTab(this,'ev-tab-attendance')">
					<i class="fas fa-clipboard-list"></i><span class="ev-tab-label"> Attendance</span>
					<span class="ev-tab-count">(<?= $attendeeCount ?>)</span>
				</li>
				<?php /* [TOURNAMENTS HIDDEN] tab */ ?>
				<li data-tab="ev-tab-rsvp" onclick="evShowTab(this,'ev-tab-rsvp')">
					<i class="fas fa-calendar-check"></i><span class="ev-tab-label"> RSVPs</span>
					<span class="ev-tab-count">(<?= $rsvpCount ?>)</span>
				</li>
				<?php if ($hasMapTab): ?>
				<li data-tab="ev-tab-map" onclick="evShowTab(this,'ev-tab-map')">
					<i class="fas fa-map-marked-alt"></i><span class="ev-tab-label"> Map</span>
				</li>
				<?php endif; ?>
				<?php if ($canManage): ?>
				<li data-tab="ev-tab-admin" onclick="evShowTab(this,'ev-tab-admin')">
					<i class="fas fa-cog"></i><span class="ev-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
			</ul>

			<?php // ---- Details Tab ---- ?>
			<div class="ev-tab-panel ev-tab-visible" id="ev-tab-details">
				<?php if ($hasDescription): ?>
					<div class="ev-description kn-description-body"><?= ev_markdown(rawurldecode($description)) ?></div>
				<?php else: ?>
					<div class="ev-empty">
						<i class="fas fa-file-alt" style="margin-right:6px"></i>No description provided
					</div>
				<?php endif; ?>
			</div>

			<?php // ---- Attendance Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-attendance">

				<?php if ($attDateMismatch): ?>
				<div style="background:#fffbeb;border:1px solid #f6ad55;border-radius:6px;padding:12px 16px;margin-bottom:14px;color:#7b341e;font-size:13px;line-height:1.5;">
					<strong><i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>Warning:</strong>
					It looks like this future event already has attendance. This is most likely due to the event being edited to move it forward instead of a new event being created.
					Please edit this event to move it back to its original date and create a new event instead.
				</div>
				<?php endif; ?>
				<div class="ev-export-bar">
					<button class="ev-icon-btn" title="Export CSV" onclick="evExportAttendanceCsv()"><i class="fas fa-download"></i></button>
					<button class="ev-icon-btn" title="Print" onclick="evPrintAttendance()"><i class="fas fa-print"></i></button>
				</div>
				<?php if ($canManageAttendance): ?>
				<?php if (!$checkinOpen): ?>
				<div class="ev-checkin-locked"><i class="fas fa-clock"></i> Sign-ins for this event can be processed starting on <?= htmlspecialchars($checkinOpenLabel) ?>.</div>
				<?php else: ?>
				<div class="ev-att-form">
					<h4><i class="fas fa-plus-circle" style="margin-right:6px;color:#276749"></i>Add Attendance</h4>
					<form method="post" id="ev-attendance-form" action="<?= UIR ?>EventAjax/add_attendance/<?= $eventId ?>/<?= $detailId ?>" onsubmit="evHandleAttendanceSubmit(this); return false;">
						<div class="ev-form-row">
							<div class="ev-form-field">
								<label>Player</label>
								<input type="text" id="ev-PlayerName" name="PlayerName" style="width:200px"
									value="<?= htmlspecialchars($attendanceForm['PlayerName'] ?? '') ?>"
									autocomplete="off" placeholder="Search players…">
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
								<input type="text" id="ev-Credits" name="Credits" style="width:55px" oninput="evSyncCredits(this.value)"
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
				<?php endif; // $checkinOpen ?>
				<?php endif; // $canManageAttendance — re-opened below ?>

				<?php if (count($attendanceList) > 0): ?>
				<table class="display" id="ev-attendance-table" style="width:100%">
					<thead>
						<tr>
							<th>Player</th>
							<th>Kingdom</th>
							<th>Park</th>
							<th>Class</th>
							<th>Credits</th>
							<?php if ($canManageAttendance): ?>
							<th class="ev-del-cell"></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($attendanceList as $att): ?>
						<tr data-att-id="<?= (int)$att['AttendanceId'] ?>" data-mundane-id="<?= (int)$att['MundaneId'] ?>">
							<td><a href="<?= UIR ?>Player/profile/<?= (int)$att['MundaneId'] ?>"><?= htmlspecialchars($att['Persona']) ?></a></td>
							<td><?php if (!empty($att['KingdomId'])): ?><a href="<?= UIR ?>Kingdom/profile/<?= (int)$att['KingdomId'] ?>"><?= htmlspecialchars($att['KingdomName']) ?></a><?php else: ?><?= htmlspecialchars($att['KingdomName'] ?? '') ?><?php endif; ?></td>
							<td><?php if (!empty($att['ParkId'])): ?><a href="<?= UIR ?>Park/profile/<?= (int)$att['ParkId'] ?>"><?= htmlspecialchars($att['ParkName']) ?></a><?php else: ?><?= htmlspecialchars($att['ParkName'] ?? '') ?><?php endif; ?></td>
							<td><?= htmlspecialchars($att['ClassName']) ?></td>
							<td><?= htmlspecialchars($att['Credits']) ?></td>
							<?php if ($canManageAttendance): ?>
							<td class="ev-del-cell">
								<a class="ev-del-link" title="Remove" href="#"
									data-del-url="<?= UIR ?>AttendanceAjax/attendance/<?= (int)$att['AttendanceId'] ?>/delete"
									onclick="evConfirmAttDelete(event, this)">×</a>
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

			<?php /* [TOURNAMENTS HIDDEN] tab panel */ ?>

			<?php // ---- RSVPs Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-rsvp">
				<?php if (!$checkinOpen): ?>
				<div class="ev-checkin-locked"><i class="fas fa-clock"></i> Sign-ins for this event can be processed starting on <?= htmlspecialchars($checkinOpenLabel) ?>.</div>
				<?php endif; ?>
				<div style="display:flex;align-items:center;justify-content:space-between;margin:0 0 12px">
					<p style="font-size:.95em;color:#4a5568;margin:0">
						<i class="fas fa-check-circle" style="margin-right:4px;color:#276749"></i>
						<strong><?= $rsvpCounts['going'] ?></strong> Going
						&nbsp;&nbsp;
						<i class="fas fa-star" style="margin-right:4px;color:#b7791f"></i>
						<strong><?= $rsvpCounts['interested'] ?></strong> Interested
					</p>
					<div style="display:flex;gap:6px">
						<button class="ev-icon-btn" title="Export CSV" onclick="evExportRsvpCsv()"><i class="fas fa-download"></i></button>
						<button class="ev-icon-btn" title="Print" onclick="evPrintRsvp()"><i class="fas fa-print"></i></button>
					</div>
				</div>
				<?php if ($canManageAttendance): ?>
					<?php if (count($rsvpList) > 0): ?>
					<div style="margin-bottom:10px;position:relative;">
						<i class="fas fa-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:12px;pointer-events:none"></i>
						<input type="text" id="ev-rsvp-search" placeholder="Filter by name…" oninput="evFilterRsvp(this.value)"
							style="width:100%;box-sizing:border-box;padding:6px 28px 6px 28px;border:1px solid #e2e8f0;border-radius:5px;font-size:13px;color:#2d3748;">
						<button id="ev-rsvp-clear" onclick="evClearRsvpSearch()" title="Clear search"
							style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;color:#a0aec0;font-size:14px;cursor:pointer;padding:0;line-height:1;">&times;</button>
					</div>
					<table class="ev-table" id="ev-rsvp-table">
						<thead>
							<tr><th>Player</th><th>Status</th><th></th></tr>
						</thead>
						<tbody>
							<?php foreach ($rsvpList as $attendee): ?>
							<tr>
								<td><a href="<?= UIR ?>Player/profile/<?= $attendee['MundaneId'] ?>"><?= htmlspecialchars($attendee['Persona']) ?></a><?php if (!empty($attendee['KingdomAbbr']) || !empty($attendee['ParkAbbr'])): ?> <span style="font-size:.8em;color:#718096">(<?= htmlspecialchars($attendee['KingdomAbbr'] ?? '') ?>:<?= htmlspecialchars($attendee['ParkAbbr'] ?? '') ?>)</span><?php endif; ?></td>
								<td style="white-space:nowrap">
									<?php if ($attendee['Status'] === 'going'): ?>
										<i class="fas fa-check-circle" style="color:#276749;margin-right:4px"></i>Going
									<?php else: ?>
										<i class="fas fa-star" style="color:#b7791f;margin-right:4px"></i>Interested
									<?php endif; ?>
								</td>
								<td style="text-align:right;white-space:nowrap">
									<?php if (!isset($checkedInIds[$attendee['MundaneId']]) && $checkinOpen && !empty($attendee['LastClassId'])): ?>
									<button class="ev-checkin-btn ev-checkin-as-btn" type="button"
										data-mundane="<?= (int)$attendee['MundaneId'] ?>"
										onclick="evQuickCheckin(this, <?= (int)$attendee['MundaneId'] ?>, <?= (int)$attendee['LastClassId'] ?>)">
										<i class="fas fa-user-check"></i> Check-in as <?= htmlspecialchars($attendee['LastClassName'] ?? '') ?>
									</button>
									<?php endif; ?>
									<button class="ev-checkin-btn<?= isset($checkedInIds[$attendee['MundaneId']]) ? ' ev-checkin-done' : '' ?>" type="button"
										data-mundane="<?= (int)$attendee['MundaneId'] ?>"
										data-persona="<?= htmlspecialchars($attendee['Persona'], ENT_QUOTES) ?>"
										<?php if (!isset($checkedInIds[$attendee['MundaneId']]) && $checkinOpen): ?>
										onclick="evOpenCheckinModal(<?= (int)$attendee['MundaneId'] ?>, <?= htmlspecialchars(json_encode($attendee['Persona']), ENT_QUOTES) ?>)"
										<?php else: ?>disabled<?php endif; ?>>
										<i class="fas fa-user-check"></i> <?= isset($checkedInIds[$attendee['MundaneId']]) ? 'Checked In' : 'Check In' ?>
									</button>
									<button class="ev-rsvp-del-btn" type="button"
										onclick="evDeleteRsvp(this, <?= (int)$attendee['MundaneId'] ?>)" title="Remove RSVP">
										<i class="fas fa-times"></i>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php else: ?>
					<div class="ev-empty">
						<i class="fas fa-calendar-check" style="margin-right:6px"></i><?php echo $isPastEvent ? 'No RSVPs' : 'No RSVPs yet' ?>
					</div>
					<?php endif; ?>
				<?php elseif ($rsvpCount === 0): ?>
				<div class="ev-empty">
					<i class="fas fa-calendar-check" style="margin-right:6px"></i><?php echo $isPastEvent ? 'No RSVPs' : 'No RSVPs yet' ?>
				</div>
				<?php endif; ?>
			</div><!-- /.ev-tab-panel -->

			<?php if ($hasMapTab): ?>
			<?php
				$mapOpenUrl  = $mapLink ?: null;
				$mapQuery    = urlencode($mapQueryAddress);
				if ($mapLink && strpos($mapLink, 'q=@') !== false) {
					// lat/lng link — strip @ for embed (Google Maps embed doesn't accept @)
					$mapEmbedUrl = str_replace('?q=@', '?q=', $mapLink) . '&output=embed&z=14';
				} else {
					$mapEmbedUrl = 'https://maps.google.com/maps?q=' . $mapQuery . '&output=embed';
				}
				if (!$mapOpenUrl) $mapOpenUrl = 'https://maps.google.com/maps?q=' . $mapQuery;
			?>
			<?php // ---- Map Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-map">
				<div style="margin-bottom:10px;display:flex;justify-content:flex-end">
					<a href="<?= htmlspecialchars($mapOpenUrl) ?>" target="_blank" class="pk-btn pk-btn-secondary" style="font-size:13px;padding:6px 14px;text-decoration:none">
						<i class="fas fa-external-link-alt" style="margin-right:6px"></i>Open in Maps
					</a>
				</div>
				<div style="width:100%;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0">
					<iframe
						src="<?= htmlspecialchars($mapEmbedUrl) ?>"
						width="100%"
						height="400"
						style="border:0;display:block"
						allowfullscreen=""
						loading="lazy"
						referrerpolicy="no-referrer-when-downgrade"
					></iframe>
				</div>
			</div><!-- /.ev-tab-panel -->
			<?php endif; ?>

			<?php if ($canManage): ?>
			<div class="ev-tab-panel" id="ev-tab-admin">
				<?php if (!$checkinOpen): ?>
				<div class="ev-checkin-locked"><i class="fas fa-clock"></i> Sign-ins for this event can be processed starting on <?= htmlspecialchars($checkinOpenLabel) ?>.</div>
				<?php endif; ?>
				<ul style="margin:0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:8px">
					<li>
						<a href="<?= UIR ?>Admin/permissions/Event/<?= $eventId ?>/<?= $detailId ?>" style="display:inline-flex;align-items:center;gap:7px;padding:7px 14px;background:#f0faf4;border:1px solid #c6e8d4;border-radius:6px;font-size:13px;font-weight:600;color:#276749;text-decoration:none">
							<i class="fas fa-key"></i> Roles &amp; Permissions
						</a>
					</li>
				</ul>
			</div><!-- /.ev-tab-panel -->
			<?php endif; ?>

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
		<form method="post" id="ev-edit-form" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/edit">
			<div class="ev-modal-body">

				<?php if ($isPastEvent): ?>
				<div class="ev-modal-warning-box">
					<i class="fas fa-exclamation-triangle" style="margin-right:6px;flex-shrink:0;margin-top:2px"></i>
					<span><strong>Stop!</strong> You are editing a past event. This can impact data for the event including attendance credit assignment. Use caution before proceeding.</span>
				</div>
				<?php endif; ?>

				<div class="ev-modal-section">
					<h4>Event Name</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label>Name</label>
							<input type="text" name="EventName" value="<?= htmlspecialchars($info['Name'] ?? '') ?>" required>
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Dates &amp; Price</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>Start Date &amp; Time</label>
							<input type="text" name="StartDate" id="ev-fp-start" autocomplete="off"
								value="<?php $sTs = $eventStart ? strtotime($eventStart) : 0; echo ($sTs > 0) ? date('Y-m-d\TH:i', $sTs) : ''; ?>">
						</div>
						<div class="ev-modal-field">
							<label>End Date &amp; Time</label>
							<input type="text" name="EndDate" id="ev-fp-end" autocomplete="off"
								value="<?php $eTs = $eventEnd ? strtotime($eventEnd) : 0; echo ($eTs > 0) ? date('Y-m-d\TH:i', $eTs) : ''; ?>">
						</div>
						<div class="ev-modal-field" style="max-width:120px">
							<label>Price ($)</label>
							<input type="number" name="Price" min="0" step="0.01"
								value="<?= number_format($price, 2) ?>">
						</div>
					</div>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label>Timezone <span class="kn-admin-hint-inline">(leave blank to inherit from park/kingdom<?= $eventTzAbbr && empty($EventOwnTimezone ?? '') ? ': ' . htmlspecialchars($eventTzAbbr) : '' ?>)</span></label>
							<select name="EventTimezone" id="ev-edit-timezone">
								<option value="">— Inherit from park/kingdom —</option>
								<?php foreach ($TimezoneOptions ?? [] as $tzOpt): ?>
								<option value="<?= htmlspecialchars($tzOpt['value']) ?>"<?= ($EventOwnTimezone ?? '') === $tzOpt['value'] ? ' selected' : '' ?>><?= htmlspecialchars($tzOpt['label']) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Description</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label style="display:flex;align-items:center;gap:6px;">
								Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
								<button type="button" class="kn-md-help-btn" onclick="document.getElementById('ev-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
							</label>
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
					<?php if ($parkId > 0 || $atParkId > 0): ?>
					<div class="ev-modal-info-box"><i class="fas fa-info-circle"></i> If no address is provided, the park address will be used.</div>
					<?php endif; ?>
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
		</form>
		<div class="ev-modal-footer" style="justify-content:space-between;align-items:center;display:flex">
			<div>
<?php if ($canDelete): ?>
				<form method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/deletedetail" style="margin:0" onsubmit="evConfirmDeleteOccurrence(event, this)">
					<button type="submit" class="ev-modal-btn-delete">
						<i class="fas fa-trash-alt" style="margin-right:5px"></i>Delete Occurrence
					</button>
				</form>
<?php else: ?>
				<span class="ev-del-detail-wrap" onmouseenter="evPositionDelTooltip(this)" onmouseleave="">
					<button type="button" class="ev-modal-btn-delete ev-modal-btn-delete-disabled" disabled>
						<i class="fas fa-trash-alt" style="margin-right:5px"></i>Delete Occurrence
					</button>
					<span class="ev-del-detail-tooltip">You cannot delete an event that has associated attendance or RSVP data.</span>
				</span>
<?php endif; ?>
			</div>
			<div style="display:flex;gap:8px">
				<button type="button" class="ev-modal-btn-cancel" onclick="evCloseEditModal()">Cancel</button>
				<button type="submit" form="ev-edit-form" class="ev-modal-btn-save" id="ev-edit-save-btn" disabled>
					<i class="fas fa-save" style="margin-right:5px"></i>Save Changes
				</button>
			</div>
		</div>
	</div>
</div><!-- /.ev-edit-modal -->

<?php endif; ?>

<?php if ($canManageAttendance): ?>
<div class="ev-modal-overlay" id="ev-checkin-modal">
	<div class="ev-modal">
		<div class="ev-modal-header">
			<h3><i class="fas fa-user-check" style="margin-right:8px"></i>Check In <span id="ev-checkin-name"></span></h3>
			<button class="ev-modal-close" type="button" onclick="evCloseCheckinModal()">&times;</button>
		</div>
		<form id="ev-checkin-form"
			action="<?= UIR ?>EventAjax/add_attendance/<?= $eventId ?>/<?= $detailId ?>"
			onsubmit="evHandleCheckinSubmit(this); return false;">
			<input type="hidden" name="MundaneId" id="ev-checkin-mundane-id">
			<input type="hidden" name="AttendanceDate" value="<?= date('Y-m-d') ?>">
			<div class="ev-modal-body">
				<div class="ev-modal-row">
					<div class="ev-modal-field">
						<label>Class</label>
						<select name="ClassId">
							<?php foreach ($Classes ?? [] as $class): ?>
							<option value="<?= (int)$class['ClassId'] ?>"><?= htmlspecialchars($class['Name']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="ev-modal-field" style="max-width:100px">
						<label>Credits</label>
						<input type="number" name="Credits" value="1" min="0.25" step="0.25" oninput="evSyncCredits(this.value)">
					</div>
				</div>
			</div>
			<div class="ev-modal-footer">
				<button type="button" class="ev-modal-btn-cancel" onclick="evCloseCheckinModal()">Cancel</button>
				<button type="submit" class="ev-modal-btn-save">
					<i class="fas fa-user-check" style="margin-right:5px"></i>Check In
				</button>
			</div>
		</form>
	</div>
</div><!-- /.ev-checkin-modal -->
<?php endif; ?>

<!-- Markdown Help Modal -->
<div id="ev-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-hashtag" style="margin-right:8px;color:#2b6cb0"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('ev-md-help-overlay').classList.remove('kn-open')">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:16px 20px">
			<table class="kn-md-help-table">
				<thead><tr><th>You type</th><th>Result</th></tr></thead>
				<tbody>
					<tr><td><code>**bold**</code></td><td><strong>bold</strong></td></tr>
					<tr><td><code>*italic*</code></td><td><em>italic</em></td></tr>
					<tr><td><code>~~strikethrough~~</code></td><td><s>strikethrough</s></td></tr>
					<tr><td><code>[link](https://...)</code></td><td><a href="#">link</a></td></tr>
					<tr><td><code>`inline code`</code></td><td><code>inline code</code></td></tr>
					<tr><td><code>- item</code></td><td>• Bullet list</td></tr>
					<tr><td><code>1. item</code></td><td>1. Numbered list</td></tr>
					<tr><td><code># Heading</code></td><td><strong>Large heading</strong></td></tr>
					<tr><td><code>## Heading</code></td><td><strong>Smaller heading</strong></td></tr>
					<tr><td><code>&gt; quote</code></td><td><em>Blockquote</em></td></tr>
					<tr><td>Blank line</td><td>New paragraph</td></tr>
					<tr><td>Single newline</td><td>Line break</td></tr>
				</tbody>
			</table>
		</div>
	</div>
</div>

<script>
function evPositionDelTooltip(wrap) {
	var btn = wrap.querySelector('button');
	var tip = wrap.querySelector('.ev-del-detail-tooltip');
	if (!btn || !tip) return;
	var r = btn.getBoundingClientRect();
	tip.style.left = r.left + 'px';
	tip.style.top  = (r.top + window.scrollY) + 'px';
}

var _evSavedCredits = parseFloat(localStorage.getItem('ev_credits_default')) || null;
if (_evSavedCredits) { var _evCr = document.getElementById('ev-Credits'); if (_evCr) _evCr.value = _evSavedCredits; }
var EvConfig = {
	uir:        '<?= UIR ?>',
	httpService:'<?= HTTP_SERVICE ?>',
	canManage:  <?= !empty($canManage) ? 'true' : 'false' ?>,
	eventId:    <?= $eventId ?>,
	detailId:   <?= $detailId ?>,
	eventName:  <?= json_encode($info['Name'] ?? 'Event') ?>,
	eventDate:  <?= json_encode($eventStart ? date('Y-m-d', strtotime($eventStart)) : '') ?>
};
</script>
<?php if ($canManage): ?>
<!-- Event Heraldry Upload Modal -->
<div class="ev-img-overlay" id="ev-img-overlay">
	<div class="ev-img-modal">
		<div class="ev-img-modal-header">
			<span class="ev-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Event Heraldry</span>
			<button class="ev-img-close-btn" id="ev-img-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-select">
			<label class="ev-upload-area" for="ev-img-file-input">
				<i class="fas fa-cloud-upload-alt ev-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Max 340&nbsp;KB (larger images auto-resized)</small>
			</label>
			<input type="file" id="ev-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none;" />
			<div id="ev-img-resize-notice" style="font-size:12px;color:#888;min-height:16px;margin-top:6px;"></div>
			<div class="ev-img-form-error" id="ev-img-error" style="display:none;"></div>
			<div style="text-align:center;margin-top:10px">
				<button class="ev-btn ev-btn-outline" id="ev-img-remove-btn" type="button" style="font-size:12px;padding:4px 14px;border-color:#feb2b2;color:#e53e3e;"><i class="fas fa-trash"></i> Remove Heraldry</button>
			</div>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-crop" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#718096;">Drag inside the crop box to reposition it, or drag the corner handles to resize.</p>
			<div class="ev-crop-wrap"><canvas id="ev-img-canvas"></canvas></div>
			<div class="ev-img-step-actions">
				<button class="ev-btn ev-btn-outline" id="ev-img-back-btn"><i class="fas fa-arrow-left"></i> Choose Different</button>
				<button class="ev-btn ev-btn-white" id="ev-img-upload-btn"><i class="fas fa-upload"></i> Upload</button>
			</div>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#718096;">Uploading&hellip;</p>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
<script>
(function() {
    var hash = location.hash.replace('#', '');
    if (hash) {
        var li = document.querySelector('[data-tab="' + hash + '"]');
        if (li && typeof evShowTab === 'function') evShowTab(li, hash);
    }
})();
(function() {
	var _evAttDt = null;
	function initEvAttDt() {
		if (_evAttDt || !$.fn || !$.fn.DataTable) return;
		if (!document.getElementById('ev-attendance-table')) return;
		_evAttDt = $('#ev-attendance-table').DataTable({
			dom: 'lfrtip',
			order: [[0, 'asc']],
			autoWidth: false,
			columnDefs: [
<?php if ($canManageAttendance): ?>
				{ targets: [-1], orderable: false, searchable: false }
<?php endif; ?>
			],
			pageLength: 25
		});
		window._evAttDt = _evAttDt;
	}
	var _origEvShowTab = window.evShowTab;
	window.evShowTab = function(li, tabId) {
		if (typeof _origEvShowTab === 'function') _origEvShowTab(li, tabId);
		if (tabId === 'ev-tab-attendance') {
			setTimeout(function() { initEvAttDt(); }, 0);
		}
	};
	// Init now if the attendance tab is already visible on page load
	$(function() {
		if (document.querySelector('#ev-tab-attendance.ev-tab-visible')) initEvAttDt();
	});
	window.evInitAttDt = initEvAttDt;
})();
<?php if ($canManage && ($CalendarDetailCount ?? 1) > 1): ?>
(function() {
	var form = document.getElementById('ev-edit-form');
	if (!form) return;
	var originalName = <?= json_encode($info['Name'] ?? '') ?>;
	var detailCount  = <?= (int)($CalendarDetailCount ?? 1) ?>;
	form.addEventListener('submit', function(e) {
		var newName = (form.querySelector('[name="EventName"]') || {}).value || '';
		if (newName && newName !== originalName) {
			e.preventDefault();
			pnConfirm({
				title: 'Rename Event?',
				message: 'This event has ' + detailCount + ' scheduled dates. Renaming it will update the name for all ' + detailCount + ' occurrences.',
				confirmText: 'Rename',
				danger: true
			}, function() { form.submit(); });
		}
	});
})();
<?php endif; ?>

function evConfirmAttDelete(e, link) {
	e.preventDefault();
	var url = link.dataset.delUrl;
	if (!url) return;
	pnConfirm({ title: 'Remove Attendance?', message: 'Remove this attendance record? This cannot be undone.', confirmText: 'Remove', danger: true }, function() {
		link.textContent = '…';
		fetch(url, { method: 'POST' })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.status === 0) {
					var row = link.closest('tr');
					var mundaneId = row ? row.dataset.mundaneId : null;
					if (row) {
						if (window._evAttDt) { window._evAttDt.row(row).remove().draw(false); }
						else { row.remove(); }
					}
					if (mundaneId) {
						var rsvpBtn = document.querySelector('.ev-checkin-btn[data-mundane="' + mundaneId + '"]');
						if (rsvpBtn) {
							rsvpBtn.classList.remove('ev-checkin-done');
							rsvpBtn.disabled = false;
							var persona = rsvpBtn.dataset.persona || '';
							rsvpBtn.setAttribute('onclick', 'evOpenCheckinModal(' + mundaneId + ', ' + JSON.stringify(persona) + ')');
							rsvpBtn.innerHTML = '<i class="fas fa-user-check"></i> Check In';
						}
					}
					var tabCount = document.querySelector('[data-tab="ev-tab-attendance"] .ev-tab-count');
					if (tabCount) { tabCount.textContent = '(' + Math.max(0, (parseInt(tabCount.textContent.replace(/[^0-9]/g, '')) || 0) - 1) + ')'; }
				} else {
					link.textContent = '×';
					alert(data.error || 'Could not remove attendance.');
				}
			})
			.catch(function() { link.textContent = '×'; alert('Request failed.'); });
	});
}
function evExportCsv(filename, headers, rows) {
	var lines = [headers.map(function(h) { return '"' + h.replace(/"/g,'""') + '"'; }).join(',')];
	rows.forEach(function(row) { lines.push(row.map(function(v) { return '"' + String(v).replace(/"/g,'""') + '"'; }).join(',')); });
	var a = document.createElement('a');
	a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
	a.download = filename;
	a.click();
}
function evSyncCredits(val) {
	var n = parseFloat(val);
	if (!(n > 0)) return;
	var modal = document.querySelector('#ev-checkin-form [name="Credits"]');
	var form  = document.getElementById('ev-Credits');
	if (modal && modal !== document.activeElement) modal.value = n;
	if (form  && form  !== document.activeElement) form.value  = n;
	if (typeof evSaveCredits === 'function') evSaveCredits(n);
}
function evFilterRsvp(q) {
	q = q.toLowerCase();
	document.querySelectorAll('#ev-rsvp-table tbody tr').forEach(function(tr) {
		tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
	});
	var clr = document.getElementById('ev-rsvp-clear');
	if (clr) clr.style.display = q ? '' : 'none';
}
function evClearRsvpSearch() {
	var inp = document.getElementById('ev-rsvp-search');
	if (inp) { inp.value = ''; inp.focus(); }
	evFilterRsvp('');
}
function evPrintSection(contentHtml, title) {
	var w = window.open('', '_blank', 'width=800,height=600');
	w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + title + '</title><style>' +
		'body{font-family:Arial,sans-serif;font-size:13px;color:#1a202c;padding:20px}' +
		'h2{margin:0 0 4px;font-size:16px}' +
		'.ev-print-sub{font-size:12px;color:#718096;margin:0 0 14px}' +
		'table{border-collapse:collapse;width:100%}' +
		'th,td{border:1px solid #e2e8f0;padding:6px 10px;text-align:left;font-size:12px}' +
		'th{background:#f7fafc;font-weight:700}' +
		'tr:nth-child(even) td{background:#f7fafc}' +
		'a{color:inherit;text-decoration:none}' +
		'@media print{body{padding:0}}' +
	'</style></head><body>' + contentHtml + '</body></html>');
	w.document.close();
	setTimeout(function() { w.print(); }, 250);
}
function evPrintAttendance() {
	var tbl = document.querySelector('#ev-attendance-table');
	var tblHtml = '<p>No attendance recorded.</p>';
	if (tbl) {
		var clone = tbl.cloneNode(true);
		clone.querySelectorAll('tr').forEach(function(tr) {
			var last = tr.lastElementChild;
			if (last) last.remove();
		});
		tblHtml = clone.outerHTML;
	}
	var sub = EvConfig.eventDate || '';
	var header = '<h2>' + (EvConfig.eventName || 'Event') + ' — Attendance</h2>' + (sub ? '<p class="ev-print-sub">' + sub + '</p>' : '');
	evPrintSection(header + tblHtml, 'Attendance');
}
function evPrintRsvp() {
	var tbl = document.querySelector('#ev-rsvp-table');
	var going = document.querySelector('#ev-tab-rsvp .fa-check-circle')?.parentElement?.textContent?.trim() || '';
	var sub = (EvConfig.eventDate || '') + (going ? '  ·  ' + going : '');
	var header = '<h2>' + (EvConfig.eventName || 'Event') + ' — RSVPs</h2>' + (sub ? '<p class="ev-print-sub">' + sub + '</p>' : '');
	evPrintSection(header + (tbl ? tbl.outerHTML : '<p>No RSVPs.</p>'), 'RSVPs');
}
function evCsvSlug() {
	var name = (EvConfig.eventName || 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
	var date = EvConfig.eventDate || '';
	return (date ? date + '-' : '') + name;
}
function evExportAttendanceCsv() {
	var rows = [];
	document.querySelectorAll('#ev-attendance-table tbody tr').forEach(function(tr) {
		var c = tr.querySelectorAll('td');
		rows.push([c[0]?c[0].textContent.trim():'', c[1]?c[1].textContent.trim():'', c[2]?c[2].textContent.trim():'', c[3]?c[3].textContent.trim():'', c[4]?c[4].textContent.trim():'']);
	});
	evExportCsv(evCsvSlug() + '-attendance.csv', ['Player','Kingdom','Park','Class','Credits'], rows);
}
function evExportRsvpCsv() {
	var rows = [];
	document.querySelectorAll('#ev-rsvp-table tbody tr').forEach(function(tr) {
		if (tr.style.display === 'none') return;
		var c = tr.querySelectorAll('td');
		rows.push([c[0]?c[0].textContent.trim():'', c[1]?c[1].textContent.trim():'']);
	});
	evExportCsv(evCsvSlug() + '-rsvps.csv', ['Player','Status'], rows);
}
function evConfirmDeleteOccurrence(e, form) {
	e.preventDefault();
	pnConfirm({ title: 'Delete Occurrence?', message: 'Delete this event occurrence? This cannot be undone.', confirmText: 'Delete', danger: true }, function() {
		form.submit();
	});
}

// Flatpickr for event edit modal date fields
function fpAddTitle(label, calEl) {
	var title = document.createElement('div');
	title.className = 'ev-fp-title';
	title.textContent = label;
	calEl.insertBefore(title, calEl.firstChild);
}
var _fpOpts = {
	enableTime: true,
	dateFormat: 'Y-m-d\\TH:i',
	altInput: true,
	altFormat: 'M j, Y h:i K',
	minuteIncrement: 10,
	time_24hr: false
};
var _prevStartDate = null;
var _fpStart = flatpickr('#ev-fp-start', Object.assign({}, _fpOpts, {
	onReady: function(sel, str, fp) {
		fpAddTitle('Start Date & Time', fp.calendarContainer);
		_prevStartDate = sel[0] || null;
	},
	onChange: function(sel) {
		if (!sel[0]) return;
		var endDates = _fpEnd.selectedDates;
		if (endDates[0] && _prevStartDate) {
			var offset = endDates[0].getTime() - _prevStartDate.getTime();
			_fpEnd.setDate(new Date(sel[0].getTime() + offset), true);
		} else if (!endDates[0]) {
			_fpEnd.setDate(new Date(sel[0].getTime() + 60 * 60 * 1000), true);
		}
		_prevStartDate = sel[0];
	}
}));
var _fpEnd = flatpickr('#ev-fp-end', Object.assign({}, _fpOpts, {
	onReady: function(sel, str, fp) { fpAddTitle('End Date & Time', fp.calendarContainer); }
}));
</script>
