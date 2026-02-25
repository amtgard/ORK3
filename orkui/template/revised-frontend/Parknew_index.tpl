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

	// Pre-compute FullCalendar event data
	$pkCalEvents = [];
	foreach ($eventList as $ev) {
		if (!$ev['NextDate'] || $ev['NextDate'] === '0000-00-00') continue;
		$pkCalEvents[] = [
			'title' => $ev['Name'],
			'start' => $ev['NextDate'],
			'url'   => UIR . ($ev['NextDetailId'] ? 'Event/detail/' . $ev['EventId'] . '/' . $ev['NextDetailId'] : 'Event/template/' . $ev['EventId']),
			'color' => '#2b6cb0',
		];
	}
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css">

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
					<button class="pk-btn pk-btn-white" onclick="pkOpenAttendanceModal()">
						<i class="fas fa-clipboard-list"></i> Enter Attendance
					</button>
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
					<div style="display:flex;align-items:center;gap:8px;">
						<button class="pk-view-btn pk-view-active" id="pk-ev-view-list" title="List view"><i class="fas fa-list"></i></button>
						<button class="pk-view-btn" id="pk-ev-view-cal" title="Calendar view"><i class="fas fa-calendar-alt"></i></button>
						<?php if ($CanManagePark): ?>
						<button onclick="pkOpenEventModal()" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Event
						</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Calendar view (lazy-loaded FullCalendar) -->
				<div id="pk-events-cal" style="display:none"></div>

				<!-- List view -->
				<div id="pk-events-list-view">
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
							<tr onclick='window.location.href="<?= UIR ?><?= $event['NextDetailId'] ? 'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] : 'Event/template/' . $event['EventId'] ?>"'>
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
				</div><!-- /pk-events-list-view -->

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
								<input type="text" id="pk-player-search" class="pk-player-search-input" placeholder="Search all players…" autocomplete="off">
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
<script>
var PkConfig = {
	uir:            '<?= UIR ?>',
	httpService:    '<?= HTTP_SERVICE ?>',
	parkId:         <?= (int)($park_id ?? 0) ?>,
	parkName:       <?= json_encode($park_name ?? '') ?>,
	kingdomId:      <?= (int)($park_info['KingdomInfo']['KingdomId'] ?? 0) ?>,
	canManage:      <?= !empty($CanManagePark) ? 'true' : 'false' ?>,
	calEvents:      <?= json_encode(array_values($pkCalEvents ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	preloadOfficers:<?= json_encode($PreloadOfficers ?? []) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	classes:         <?= json_encode(array_values($Classes         ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	recentAttendees: <?= json_encode(array_values($RecentAttendees ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js"></script>
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
<div id="pk-att-overlay">
	<div class="pk-modal-box" style="width:620px;max-width:calc(100vw - 40px);">

		<div class="pk-modal-header">
			<div class="pk-att-header-content">
				<h3 class="pk-modal-title">
					<i class="fas fa-clipboard-list" style="margin-right:8px;color:#2b6cb0"></i>Enter Attendance
				</h3>
				<div class="pk-att-header-fields">
					<div class="pk-att-hfield">
						<label>Date</label>
						<input type="date" id="pk-att-date">
					</div>
					<div class="pk-att-hfield pk-att-hfield-sm">
						<label>Credits</label>
						<input type="number" id="pk-att-credits-default" min="0.5" step="0.5" value="1">
					</div>
				</div>
			</div>
			<button class="pk-modal-close-btn" id="pk-att-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pk-modal-body" id="pk-att-body">

			<div class="pk-att-feedback" id="pk-att-feedback" style="display:none"></div>

			<!-- Search & Add -->
			<div class="pk-att-section pk-att-search-section">
				<div class="pk-att-section-label">
					<i class="fas fa-search" style="margin-right:6px;color:#a0aec0"></i>Add by Name
				</div>
				<div class="pk-att-search-row">
					<div class="pk-att-field pk-att-field-grow">
						<label>Player</label>
						<input type="text" id="pk-att-player-name" autocomplete="off" placeholder="Search players...">
						<input type="hidden" id="pk-att-player-id">
					</div>
					<div class="pk-att-field pk-att-field-class">
						<label>Class</label>
						<select id="pk-att-class-select">
							<option value="">— class —</option>
						</select>
					</div>
					<div class="pk-att-field pk-att-field-sm">
						<label>Credits</label>
						<input type="number" id="pk-att-search-credits" min="0.5" step="0.5" value="1">
					</div>
					<div class="pk-att-field pk-att-field-btn">
						<label>&nbsp;</label>
						<button class="pk-btn pk-btn-primary" id="pk-att-add-btn">
							<i class="fas fa-plus"></i> Add
						</button>
					</div>
				</div>
			</div>

			<!-- Quick Add (collapsible, collapsed by default) -->
			<div class="pk-att-section">
				<button class="pk-att-toggle" id="pk-att-qa-toggle" aria-expanded="false">
					<span><i class="fas fa-users" style="margin-right:6px;color:#a0aec0"></i>Quick Add &mdash; Recent Attendees</span>
					<i class="fas fa-chevron-down pk-att-chevron" id="pk-att-qa-chevron"></i>
				</button>
				<div class="pk-att-qa-wrap" id="pk-att-qa-wrap" style="display:none">
					<table class="pk-att-qa-table">
						<thead><tr><th>Player</th><th>Class</th><th>Credits</th><th></th></tr></thead>
						<tbody id="pk-att-qa-tbody"></tbody>
					</table>
					<div class="pk-att-qa-empty" id="pk-att-qa-empty" style="display:none">
						No recent attendees in the last 90 days.
					</div>
				</div>
			</div>

			<!-- Added this session -->
			<div class="pk-att-added-section" id="pk-att-added-section" style="display:none">
				<div class="pk-att-section-label">Added this session</div>
				<ul class="pk-att-added-list" id="pk-att-added-list"></ul>
			</div>

		</div><!-- /.pk-modal-body -->

		<div class="pk-modal-footer" style="justify-content:flex-end">
			<button class="pk-btn pk-btn-ghost" id="pk-att-done-btn">Done</button>
		</div>

	</div>
</div>

<?php endif; ?>

<?php if ($CanManagePark ?? false): ?>

<div class="pk-emod-overlay" id="pk-event-modal">
	<div class="pk-emod-box">
		<div class="pk-emod-header">
			<h3><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Add New Occurrence</h3>
			<button class="pk-emod-close" onclick="pkCloseEventModal()">&times;</button>
		</div>
		<div class="pk-emod-body">
			<p class="pk-emod-hint">Select a template to get started. You'll configure the dates, location, and details on the next page.</p>
			<label class="pk-emod-label">Event Template</label>
			<select class="pk-emod-select" id="pk-template-select">
				<option value="">Loading templates…</option>
			</select>
		</div>
		<div class="pk-emod-footer">
			<button class="pk-emod-btn-cancel" onclick="pkCloseEventModal()">Cancel</button>
			<button class="pk-emod-btn-go" id="pk-emod-go-btn" onclick="pkGoToEventCreate()" disabled>
				Continue <i class="fas fa-arrow-right"></i>
			</button>
		</div>
	</div>
</div>

<?php endif; ?>
