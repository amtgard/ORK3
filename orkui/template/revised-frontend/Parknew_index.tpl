<?php
	// ---- Normalize data into clean local variables ----
	$parkInfo    = $park_info['ParkInfo']     ?? [];
	$heraldryUrl = $park_info['Heraldry']['Url'] ?? '';
	$hasHeraldry = !empty($parkInfo['HasHeraldry']);
	$parkTitle   = trim($parkInfo['ParkTitle']   ?? '');
	$description = trim(str_replace(['<br />', '<br/>', '<br>'], '', $parkInfo['Description'] ?? ''));
	$directions  = trim(str_replace(['<br />', '<br/>', '<br>'], '', $parkInfo['Directions']  ?? ''));
	$websiteUrl  = trim($parkInfo['Url']          ?? '');
	$parkIsInactive = (trim($parkInfo['Active'] ?? 'Active') !== 'Active');

	$officerList    = $park_officers['Officers']          ?? [];
	$parkDayList    = $park_days['ParkDays']              ?? [];
	$eventList      = (array)($event_summary              ?? []);
	// [TOURNAMENTS HIDDEN] $tournamentList = [];

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
	$hoaPlayers12  = array_merge($heraldryPeriods[0] ?? [], $heraldryPeriods[1] ?? []);

	$firstTab = 'about';

	// Auto-link URLs in plain text fields
	function pk_autolink(string $text): string {
		$escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		$linked  = preg_replace(
			'~https?://[^\s<>\"]+~',
			'<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>',
			$escaped
		);
		return nl2br($linked);
	}


	// Pre-compute FullCalendar event data
	$pkCalEvents = [];
	foreach ($eventList as $ev) {
		if (!$ev['NextDate'] || $ev['NextDate'] === '0000-00-00') continue;
		if (!empty($ev['is_park_day'])) {
			$calEv = [
				'title' => $ev['Name'],
				'start' => $ev['NextDate'] . 'T' . $ev['park_day_time'],
				'color' => '#38a169', // Green for park days
				'description' => $ev['park_day_description'],
			];
		} else {
			$calEv = [
				'title' => $ev['Name'],
				'start' => $ev['NextDate'],
				'url'   => $ev['NextDetailId'] ? UIR . 'Event/detail/' . $ev['EventId'] . '/' . $ev['NextDetailId'] : '',
				'color' => '#2b6cb0', // Blue for regular events
			];
			$endRaw = $ev['NextEndDate'] ?? '';
			if ($endRaw && substr($endRaw, 0, 10) > substr($ev['NextDate'], 0, 10)) {
				$endDt = new DateTime(substr($endRaw, 0, 10));
				$endDt->modify('+1 day');
				$calEv['end'] = $endDt->format('Y-m-d');
			}
		}
		$pkCalEvents[] = $calEv;
	}

	// Pre-compute recurring park day occurrences for calendar (next 90 days)
	$pkCalParkDays  = [];
	$_pd_today      = new DateTime(); $_pd_today->setTime(0, 0, 0);
	$_pd_end        = (clone $_pd_today)->modify('+90 days');
	$_pd_dayNames   = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
	$_pd_colors     = ['fighter-practice'=>'#e53e3e','arts-day'=>'#805ad5','other'=>'#ed8936'];
	$_pd_labels     = ['fighter-practice'=>'Fighter Practice','arts-day'=>'A&S Day','other'=>'Other'];
	foreach ($parkDayList as $_pd) {
		$_pdColor   = $_pd_colors[$_pd['Purpose']] ?? '#38a169';
		$_pdLabel   = $_pd_labels[$_pd['Purpose']] ?? 'Park Day';
		$_pdTimeStr = date('H:i:s', strtotime($_pd['Time']));
		$_pdOccs    = [];
		switch ($_pd['Recurrence']) {
			case 'weekly':
				$_pdWdn = array_search(strtolower($_pd['WeekDay']), $_pd_dayNames);
				if ($_pdWdn === false) break;
				$_pdD = clone $_pd_today;
				while ((int)$_pdD->format('w') !== $_pdWdn) { $_pdD->modify('+1 day'); }
				while ($_pdD <= $_pd_end) { $_pdOccs[] = $_pdD->format('Y-m-d'); $_pdD->modify('+7 days'); }
				break;
			case 'week-of-month':
				$_pdWdn = array_search(strtolower($_pd['WeekDay']), $_pd_dayNames);
				if ($_pdWdn === false) break;
				$_pdWom = (int)$_pd['WeekOfMonth'];
				$_pdD   = clone $_pd_today; $_pdD->modify('first day of this month'); $_pdD->setTime(0,0,0);
				for ($_pdM = 0; $_pdM < 4; $_pdM++) {
					$_pdFd   = (int)$_pdD->format('w');
					$_pdDiff = ($_pdWdn - $_pdFd + 7) % 7;
					$_pdDom  = 1 + $_pdDiff + ($_pdWom - 1) * 7;
					if ($_pdDom <= (int)$_pdD->format('t')) {
						$_pdOcc = clone $_pdD; $_pdOcc->setDate((int)$_pdD->format('Y'), (int)$_pdD->format('n'), $_pdDom);
						if ($_pdOcc >= $_pd_today && $_pdOcc <= $_pd_end) $_pdOccs[] = $_pdOcc->format('Y-m-d');
					}
					$_pdD->modify('first day of next month');
				}
				break;
			case 'monthly':
				$_pdMd = (int)$_pd['MonthDay'];
				$_pdD  = clone $_pd_today; $_pdD->modify('first day of this month'); $_pdD->setTime(0,0,0);
				for ($_pdM = 0; $_pdM < 4; $_pdM++) {
					if ($_pdMd <= (int)$_pdD->format('t')) {
						$_pdOcc = clone $_pdD; $_pdOcc->setDate((int)$_pdD->format('Y'), (int)$_pdD->format('n'), $_pdMd);
						if ($_pdOcc >= $_pd_today && $_pdOcc <= $_pd_end) $_pdOccs[] = $_pdOcc->format('Y-m-d');
					}
					$_pdD->modify('first day of next month');
				}
				break;
		}
		foreach ($_pdOccs as $_pdOcc) {
			$_pdTitle = !empty($_pd['Online']) ? '(Online) ' . $_pdLabel : $_pdLabel;
			$pkCalParkDays[] = ['title'=>$_pdTitle,'start'=>$_pdOcc.'T'.$_pdTimeStr,'color'=>$_pdColor];
		}
	}

	// Next Park Day: earliest upcoming occurrence across all park days
	$nextParkDayDate = null;
	if (!empty($pkCalParkDays)) {
		$_starts = array_column($pkCalParkDays, 'start');
		sort($_starts);
		$nextParkDayDate = substr($_starts[0], 0, 10);
	}

	// Active Players: at least one sign-in in the past 365 days
	$activePlayersYear = count(array_filter($allPlayers, function($_ap) use ($nowTs) {
		return ($nowTs - strtotime($_ap['LastSignin'])) <= 365 * 24 * 3600;
	}));
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<div class="pk-hero<?= $parkIsInactive ? ' pk-hero--inactive' : '' ?>">
	<div class="pk-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="pk-hero-content">

		<!-- Heraldry -->
		<div class="pk-hero-left">
			<?php $displayHeraldryUrl = $hasHeraldry ? $heraldryUrl : HTTP_PARK_HERALDRY . '00000.jpg'; ?>
			<div class="pk-heraldry-frame<?= !empty($CanManagePark) ? ' pk-heraldry-editable' : '' ?>">

				<img class="heraldry-img" src="<?= htmlspecialchars($displayHeraldryUrl) ?>"
				     alt="<?= htmlspecialchars($park_name) ?> heraldry"
				     crossorigin="anonymous"
				     onload="typeof pkApplyHeroColor==='function'&&!<?= $parkIsInactive ? 'true' : 'false' ?>&&pkApplyHeroColor(this)">
				<?php if (!empty($CanManagePark)): ?>
				<button class="pk-heraldry-edit-btn" onclick="pkOpenHeraldryModal()" title="Change heraldry">
					<i class="fas fa-camera"></i>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<!-- Name / title / officers -->
		<div class="pk-hero-center">
			<div class="pk-kingdom-link">
				<a href="<?= UIR ?>Kingdom/profile/<?= $kingdom_id ?>">
					<i class="fas fa-crown"></i> <?= htmlspecialchars($kingdom_name) ?>
				</a>
			</div>
			<h1 class="pk-park-name"><?= htmlspecialchars($park_name) ?></h1>
			<div class="pk-hero-badges">
				<?php if (!empty($parkTitle)): ?>
					<span class="pk-park-title-badge"><?= htmlspecialchars($parkTitle) ?></span>
				<?php endif; ?>
				<?php
					$_heroCity     = trim($parkInfo['City']     ?? '');
					$_heroProvince = trim($parkInfo['Province'] ?? '');
					$_heroLocation = implode(', ', array_filter([$_heroCity, $_heroProvince]));
				?>
				<?php if ($parkIsInactive): ?>
					<span class="pk-inactive-badge"><i class="fas fa-moon"></i> Inactive Park</span>
				<?php endif; ?>
				<?php if ($_heroLocation): ?>
					<span class="pk-hero-location"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($_heroLocation) ?></span>
				<?php endif; ?>
			</div>
			<div class="pk-officers-inline">
				<?php if ($monarch): ?>
					<i class="fas fa-crown" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					Monarch:&nbsp;
					<?php if (!empty($monarch['MundaneId']) && $monarch['MundaneId'] > 0): ?>
						<a href="<?= UIR ?>Player/profile/<?= $monarch['MundaneId'] ?>"><?= htmlspecialchars($monarch['Persona']) ?></a>
					<?php else: ?>
						<span class="pk-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- Action buttons -->
		<div class="pk-hero-right">
			<div class="pk-hero-actions">
				<?php if (!empty($CanManagePark)): ?>
					<button class="pk-btn pk-btn-outline" onclick="pkOpenAttendanceModal()">
						<i class="fas fa-clipboard-list"></i> Enter Attendance
					</button>
					<button class="pk-btn pk-btn-outline" onclick="pkOpenAwardModal()">
						<i class="fas fa-medal"></i> Enter Awards
					</button>
					<button class="pk-btn pk-btn-outline" onclick="pkOpenAdminModal()">
						<i class="fas fa-cog"></i> Admin
					</button>
				<?php endif; ?>

			</div>
		</div>

	</div>
</div>

<!-- =============================================
     ZONE 2: Stats Row
     ============================================= -->
<div class="pk-stats-row">
	<div class="pk-stat-card<?= count($parkDayList) > 0 ? ' pk-stat-card-link' : '' ?>"<?php if (count($parkDayList) > 0): ?> onclick="pkActivateTab('about')"<?php endif; ?>>
		<div class="pk-stat-icon"><i class="fas fa-calendar-check"></i></div>
		<?php if ($nextParkDayDate): ?>
			<div class="pk-stat-value" style="font-size:1.1rem"><?= date('M j', strtotime($nextParkDayDate)) ?></div>
			<div class="pk-stat-sub"><?= date('l', strtotime($nextParkDayDate)) ?></div>
		<?php else: ?>
			<div class="pk-stat-value">&mdash;</div>
		<?php endif; ?>
		<div class="pk-stat-label">Next Park Day</div>
	</div>
	<div class="pk-stat-card pk-stat-card-link" onclick="pkActivateTab('players')">
		<div class="pk-stat-icon"><i class="fas fa-users"></i></div>
		<div class="pk-stat-value"><?= $activePlayersYear ?></div>
		<div class="pk-stat-label">Active Players</div>
	</div>
	<div class="pk-stat-card">
		<div class="pk-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="pk-stat-value"><?= $MonthlyAvg > 0 ? number_format($MonthlyAvg, 1) : '&mdash;' ?></div>
		<div class="pk-stat-label">Avg / Month</div>
	</div>
	<div class="pk-stat-card pk-stat-card-link" onclick="pkActivateTab('events')">
		<div class="pk-stat-icon"><i class="fas fa-flag"></i></div>
		<div class="pk-stat-value"><?= count($eventList) ?></div>
		<div class="pk-stat-label">Event<?= count($eventList) != 1 ? 's' : '' ?></div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Tabbed Main
     ============================================= -->
<div class="pk-layout">

	<!-- ---- Sidebar ---- -->
	<aside class="pk-sidebar">

		<!-- Officers -->
		<?php if (!empty($officerList) || !empty($CanManagePark)): ?>
		<div class="pk-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-crown"></i> Officers</span>
				<?php if (!empty($CanManagePark)): ?>
				<button onclick="pkOpenEditOfficersModal()" class="pk-edit-officers-btn" title="Edit officers">
					<i class="fas fa-pencil-alt"></i>
				</button>
				<?php endif; ?>
			</h4>
			<ul class="pk-officer-list">
				<?php foreach ($officerList as $o): ?>
				<li>
					<span class="pk-officer-role"><?= htmlspecialchars($o['OfficerRole']) ?></span>
					<span class="pk-officer-name">
						<?php if (!empty($o['MundaneId']) && $o['MundaneId'] > 0): ?>
							<a href="<?= UIR ?>Player/profile/<?= $o['MundaneId'] ?>"><?= htmlspecialchars($o['Persona']) ?></a>
						<?php else: ?>
							<em style="color:#a0aec0">Vacant</em>
						<?php endif; ?>
					</span>
				</li>
				<?php endforeach; ?>
				<?php if (empty($officerList)): ?>
				<li><em style="color:#a0aec0;font-size:12px">No officers on record</em></li>
				<?php endif; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Map Widget -->
		<?php if (!is_null($parkLat)): ?>
		<div class="pk-card" style="padding:0;overflow:hidden;border-radius:10px;">
			<iframe
				src="https://maps.google.com/maps?q=<?= $parkLat ?>,<?= $parkLng ?>&output=embed&z=11"
				width="100%"
				height="200"
				style="border:0;display:block"
				allowfullscreen=""
				loading="lazy"
				referrerpolicy="no-referrer-when-downgrade">
			</iframe>
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
				<?php if ($IsLoggedIn): ?>
				<?php /* <li>
					<span class="pk-link-icon"><i class="fas fa-eye"></i></span>
					<a href="<?= UIR ?>Attendance/behold/<?= $park_id ?>">Behold!</a>
				</li> */ ?>
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

	</aside>

	<!-- ---- Tabbed Main ---- -->
	<div class="pk-main">
		<div class="pk-tabs">

			<!-- Tab navigation -->
			<ul class="pk-tab-nav">
				<li data-pktab="about" class="pk-tab-active">
					<i class="fas fa-info-circle"></i><span class="pk-tab-label"> About</span>
				</li>
				<li data-pktab="events" class="">
					<i class="fas fa-flag"></i><span class="pk-tab-label"> Events</span>
					<span class="pk-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-pktab="players">
					<i class="fas fa-users"></i><span class="pk-tab-label"> Players</span>
					<span class="pk-tab-count">(<?= count($allPlayers) ?>)</span>
				</li>
				<?php if (count($hoaPlayers12) > 0): ?>
				<li data-pktab="heraldry">
					<i class="fas fa-shield-alt"></i><span class="pk-tab-label"> Hall of Arms</span>
					<span class="pk-tab-count">(<?= count($hoaPlayers12) ?>)</span>
				</li>
				<?php endif; ?>
				<li data-pktab="reports">
					<i class="fas fa-chart-bar"></i><span class="pk-tab-label"> Reports</span>
				</li>
				<?php if (!empty($ShowRecsTab)): ?>
				<li data-pktab="recommendations">
					<i class="fas fa-star"></i><span class="pk-tab-label"> Recommendations</span>
					<?php if (!empty($AwardRecommendations)): ?>
					<span class="pk-tab-count">(<?= count($AwardRecommendations) ?>)</span>
					<?php endif; ?>
				</li>
				<?php endif; ?>
				<?php if (!empty($CanManagePark)): ?>
				<li data-pktab="admin">
					<i class="fas fa-cog"></i><span class="pk-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
			</ul>
			<div class="pk-active-tab-label" id="pk-active-tab-label">About</div>

			<!-- About Tab -->
			<div class="pk-tab-panel" id="pk-tab-about">
				<?php
					$_addrParts = array_filter([trim($parkInfo['Address'] ?? ''), trim($parkInfo['City'] ?? '')]);
					$_addrLine1 = implode(', ', $_addrParts);
					$_addrLine2 = trim(implode(' ', array_filter([trim($parkInfo['Province'] ?? ''), trim($parkInfo['PostalCode'] ?? '')])));
					$_addrFull  = implode(', ', array_filter([$_addrLine1, $_addrLine2]));
				?>
				<div class="pk-about-grid">
					<?php if (!empty($description)): ?>
					<div class="pk-about-section">
						<div class="pk-about-label">About</div>
						<div class="pk-about-text"><?= pk_autolink($description) ?></div>
					</div>
					<?php endif; ?>

					<?php if (!empty($directions) || !empty($_addrFull)): ?>
					<div class="pk-about-section">
						<div class="pk-about-label">Directions</div>
						<?php if (!empty($_addrFull)): ?>
						<div class="pk-about-meta-row" style="margin-bottom:10px">
							<i class="fas fa-map-marker-alt"></i>
							<span><?= htmlspecialchars($_addrFull) ?></span>
						</div>
						<?php endif; ?>
						<?php if (!empty($directions)): ?>
						<div class="pk-about-text"><?= pk_autolink($directions) ?></div>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>

				<?php if (!empty($websiteUrl) || !empty($parkInfo['MapUrl'])): ?>
				<div class="pk-about-section pk-about-meta" style="margin-top:12px">
					<?php if (!empty($websiteUrl)): ?>
					<div class="pk-about-meta-row">
						<i class="fas fa-globe"></i>
						<a href="<?= htmlspecialchars($websiteUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($websiteUrl) ?></a>
					</div>
					<?php endif; ?>
					<?php if (!empty($parkInfo['MapUrl'])): ?>
					<div class="pk-about-meta-row">
						<i class="fas fa-map"></i>
						<a href="<?= htmlspecialchars($parkInfo['MapUrl']) ?>" target="_blank" rel="noopener">View on Map</a>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<!-- Schedule sub-section -->
				<div class="pk-about-section pk-about-schedule">
					<div class="pk-about-label" style="display:flex;align-items:center;justify-content:space-between;">
						<span><i class="fas fa-calendar" style="margin-right:6px;color:#a0aec0;"></i>Schedule</span>
						<?php if (!empty($CanManagePark)): ?>
						<button class="pk-btn pk-btn-primary pk-btn-sm" onclick="pkOpenAddDayModal()">
							<i class="fas fa-plus"></i> Add Park Day
						</button>
						<?php endif; ?>
					</div>
					<?php if (count($parkDayList) > 0): ?>
					<div class="pk-schedule-grid">
						<?php foreach ($parkDayList as $day): ?>
						<?php
							if (!function_exists('pk_ordinal')) {
								function pk_ordinal($n) {
									$n = (int)$n; $m = $n % 100;
									if ($m >= 11 && $m <= 13) return $n . 'th';
									return $n . (['th','st','nd','rd'][$n % 10] ?? 'th');
								}
							}
							switch ($day['Recurrence']) {
								case 'weekly':        $recText = 'Every ' . $day['WeekDay']; break;
								case 'week-of-month': $recText = 'Every ' . pk_ordinal($day['WeekOfMonth']) . ' ' . $day['WeekDay']; break;
								case 'monthly':       $recText = 'Monthly on the ' . pk_ordinal($day['MonthDay']); break;
								default:              $recText = $day['Recurrence'];
							}
							switch ($day['Purpose']) {
								case 'fighter-practice': $purposeLabel = 'Fighter Practice'; $purposeCls = 'purpose-fighter'; $iconCls = 'icon-fighter'; $iconFa = 'fa-user-shield'; break;
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
				<?php if (!empty($CanManagePark)): ?>
				<button class="pk-schedule-card-del" data-park-day-id="<?= (int)$day['ParkDayId'] ?>" title="Remove park day">&times;</button>
				<?php endif; ?>
							<div class="pk-schedule-icon <?= $iconCls ?>">
								<i class="fas <?= $iconFa ?>"></i>
							</div>
							<div class="pk-schedule-info">
								<div class="pk-schedule-when"><?= htmlspecialchars($recText) ?></div>
								<div class="pk-schedule-time"><?= date('g:i A', strtotime($day['Time'])) ?></div>
								<span class="pk-schedule-purpose <?= $purposeCls ?>"><?= $purposeLabel ?></span>
								<?php if (!empty($day['Online'])): ?>
									<span class="pk-schedule-online-badge"><i class="fas fa-wifi"></i> Online</span>
								<?php else: ?>
									<?php
										$_dayAddr   = trim($day['Address'] ?? '');
										$_dayCity   = trim($day['City'] ?? '');
										if ($_dayCity && stripos($_dayAddr, $_dayCity) !== false) {
											$_dayAddrStr = $_dayAddr;
										} else {
											$_dayAddrStr = implode(', ', array_filter([$_dayAddr, $_dayCity, trim($day['Province'] ?? ''), trim($day['PostalCode'] ?? '')]));
										}
									?>
									<?php if (!empty($_dayAddrStr)): ?>
										<div class="pk-schedule-address"><?= htmlspecialchars($_dayAddrStr) ?></div>
									<?php endif; ?>
									<?php if ($dayMapUrl): ?>
										<a class="pk-schedule-map-link" href="<?= htmlspecialchars($dayMapUrl) ?>" target="_blank" rel="noopener">
											<i class="fas fa-map-marker-alt"></i> Map
										</a>
									<?php endif; ?>
								<?php endif; ?>
							<?php if (!empty($day['Description'])): ?>
								<p class="pk-schedule-desc"><?= htmlspecialchars($day['Description']) ?></p>
							<?php endif; ?>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				<?php else: ?>
					<div class="pk-empty">No park days scheduled</div>
				<?php endif; ?>
				</div><!-- /pk-about-schedule -->
			</div><!-- /pk-tab-about -->

			<!-- Events Tab -->
			<div class="pk-tab-panel" id="pk-tab-events" style="display:none">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
					<h4 style="margin:0;font-size:14px;font-weight:700;color:#4a5568;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<button class="pk-view-btn pk-view-active" id="pk-ev-view-list" title="List view"><i class="fas fa-list"></i></button>
						<button class="pk-view-btn" id="pk-ev-view-cal" title="Calendar view"><i class="fas fa-calendar-alt"></i></button>
						<?php if (count($parkDayList) > 0): ?>
						<div id="pk-ev-filter-bar" style="display:flex;align-items:center;gap:5px;">
							<span style="font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.05em;margin-right:2px;">Show:</span>
							<button class="pk-filter-toggle pk-filter-on" data-filter="event">Events</button>
							<button class="pk-filter-toggle" data-filter="park-day">Park Days</button>
						</div>
						<?php endif; ?>
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
				<?php if (count($eventList) > 0 || count($parkDayList) > 0): ?>
					<table class="pk-table" id="pk-events-table">
						<thead>
							<tr>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="date">Next Date</th>
								<th data-sorttype="numeric">Going</th>
							<th data-sorttype="numeric">Interested</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
							<tr<?= $event['NextDetailId'] ? ' onclick="window.location.href=\''.UIR.'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] . '\'"' : '' ?>>
								<td>
									<div class="pk-tiny-heraldry">
										<?php if ($event['HasHeraldry'] == 1): ?>
											<img src="<?= HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $event['EventId'])) ?>"
											     loading="lazy"
											     onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'">
										<?php else: ?>
											<img loading="lazy" src="<?= HTTP_EVENT_HERALDRY ?>00000.jpg">
										<?php endif; ?>
										<?= htmlspecialchars($event['Name']) ?>
									</div>
								</td>
								<td class="pk-date-col" data-sortval="<?= $event['NextDate'] ?>">
									<?= 0 == $event['NextDate'] ? '' : date('M. j, Y', strtotime($event['NextDate'])) ?>
								</td>
								<td class="pk-date-col" style="text-align:center"><?= (int)($event['RsvpGoing'] ?? 0) ?: '—' ?></td>
							<td class="pk-date-col" style="text-align:center"><?= (int)($event['RsvpInterested'] ?? 0) ?: '—' ?></td>
							</tr>
							<?php endforeach; ?>
							<?php foreach ($parkDayList as $pkDay): ?>
							<?php
								switch ($pkDay['Recurrence']) {
									case 'weekly':        $pkDayRec = 'Every ' . $pkDay['WeekDay']; break;
									case 'week-of-month': $pkDayRec = 'Every ' . pk_ordinal($pkDay['WeekOfMonth']) . ' ' . $pkDay['WeekDay']; break;
									case 'monthly':       $pkDayRec = 'Monthly on the ' . pk_ordinal($pkDay['MonthDay']); break;
									default:              $pkDayRec = $pkDay['Recurrence'];
								}
								$pkPurposeLabels = ['fighter-practice'=>'Fighter Practice','arts-day'=>'A&S Day','other'=>'Other'];
								$pkDayLabel = $pkPurposeLabels[$pkDay['Purpose']] ?? 'Park Day';
							?>
							<tr data-type="park-day" style="display:none">
								<td>
									<i class="fas fa-calendar-day" style="margin-right:6px;color:#a0aec0"></i>
									<?= htmlspecialchars($pkDayLabel) ?><?php if (!empty($pkDay['Online'])): ?> <span class="pk-online-pill"><i class="fas fa-wifi"></i> Online</span><?php endif; ?>
								</td>
								<td class="pk-date-col" style="color:#718096;font-style:italic" data-sortval="<?= htmlspecialchars($pkDayRec) ?>">
									<?= htmlspecialchars($pkDayRec) ?> &middot; <?= date('g:i A', strtotime($pkDay['Time'])) ?>
								</td>
								<td class="pk-date-col" style="text-align:center;color:#a0aec0">&mdash;</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="pk-pagination" id="pk-events-table-pages"></div>
				<?php else: ?>
					<div class="pk-empty">No events found</div>
				<?php endif; ?>
				</div><!-- /pk-events-list-view -->

				<?php /* [TOURNAMENTS HIDDEN] */ ?>
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
							<?php if ($CanManagePark ?? false): ?>
							<div class="plr-action-group">
								<button class="plr-add-btn" onclick="pkOpenAddPlayerModal()"><i class="fas fa-user-plus"></i> Add Player</button>
								<div class="plr-gear-wrap">
									<button class="plr-gear-btn" id="pk-plr-gear-btn" aria-label="Player actions" aria-expanded="false" onclick="var m=this.nextElementSibling;var o=m.classList.toggle('open');this.setAttribute('aria-expanded',o)"><i class="fas fa-cog"></i></button>
									<div class="plr-gear-menu" id="pk-plr-gear-menu">
										<button class="plr-gear-item" onclick="pkOpenMovePlayerModal();document.getElementById('pk-plr-gear-menu').classList.remove('open')"><i class="fas fa-people-arrows"></i> Move Player</button>
										<button class="plr-gear-item" onclick="pkOpenMergePlayerModal();document.getElementById('pk-plr-gear-menu').classList.remove('open')"><i class="fas fa-compress-alt"></i> Merge Players</button>
									</div>
								</div>
							</div>
							<?php endif; ?>
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
							   href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>">
								<div class="pk-player-card-top">
									<div class="pk-player-avatar">
										<?php if ($avatarSrc): ?>
											<img src="<?= htmlspecialchars($avatarSrc) ?>"
											     alt=""
											     loading="lazy"
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
								   href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>">
									<div class="pk-player-card-top">
										<div class="pk-player-avatar">
											<?php if ($avatarSrc): ?>
												<img src="<?= htmlspecialchars($avatarSrc) ?>"
												     loading="lazy"
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
								<tr onclick='window.location.href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>"'>
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
							<tr onclick='window.location.href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>"'>
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
			<?php
				$_isOwnPark = !empty($IsOwnPark);
				$_currentUserHasHeraldry = true;
				if ($_isOwnPark) {
					foreach ($allPlayers as $_cp) {
						if ((int)$_cp['MundaneId'] === (int)$CurrentUserId) {
							$_currentUserHasHeraldry = (bool)$_cp['HasHeraldry'];
							break;
						}
					}
				}
				$_showHoaPrompt = $_isOwnPark && !$_currentUserHasHeraldry;
			?>
			<?php if (count($hoaPlayers12) > 0): ?>
			<div class="pk-tab-panel" id="pk-tab-heraldry" style="display:none">
				<div class="pk-players-toolbar">
					<span class="pk-players-toolbar-left">
						<?= count($hoaPlayers12) ?> device<?= count($hoaPlayers12) != 1 ? 's' : '' ?> (sign-in past 12 months)
					</span>
				</div>
				<div class="pk-hoa-search-wrap">
					<i class="fas fa-search"></i>
					<input type="text" class="pk-hoa-search" placeholder="Search by name…" autocomplete="off">
				</div>
				<div class="pk-hoa-grid" id="pk-hoa-grid">
					<?php foreach ($hoaPlayers12 as $_hoaIdx => $p): ?>
					<a class="pk-hoa-card"
					   href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>"
					   data-name="<?= htmlspecialchars(strtolower($p['Persona'])) ?>"
					   style="animation-delay:<?= min($_hoaIdx * 18, 600) ?>ms">
						<img class="pk-hoa-img"
						     src="<?= HTTP_PLAYER_HERALDRY . Common::resolve_image_ext(DIR_PLAYER_HERALDRY, sprintf('%06d', $p['MundaneId'])) ?>"
						     alt="<?= htmlspecialchars($p['Persona']) ?>"
						     loading="lazy"
						     onerror="this.closest('.pk-hoa-card').style.display='none'">
						<div class="pk-hoa-overlay">
							<div class="pk-hoa-overlay-name"><?= htmlspecialchars($p['Persona']) ?></div>
							<?php if (!empty($p['LastSignin'])): ?>
							<div class="pk-hoa-overlay-meta"><i class="fas fa-sign-in-alt" style="font-size:8px;"></i> <?= htmlspecialchars($p['LastSignin']) ?></div>
							<?php endif; ?>
							<?php if (!empty($p['OfficerRoles'])): ?>
							<div class="pk-hoa-overlay-meta"><i class="fas fa-crown" style="font-size:8px;"></i> <?= htmlspecialchars(explode(', ', $p['OfficerRoles'])[0]) ?></div>
							<?php endif; ?>
						</div>
					</a>
					<?php endforeach; ?>
				</div>
				<div id="pk-hoa-empty" style="display:none;padding:32px 0;text-align:center;color:#a0aec0;font-size:14px;">No results match your search.</div>
				<?php if ($_showHoaPrompt): ?>
				<div class="pk-hoa-cta">
					<i class="fas fa-shield-alt pk-hoa-cta-icon"></i>
					<div class="pk-hoa-cta-body">
						<strong>Your arms should be here!</strong> Visit <a href="<?= UIR ?>Player/profile/<?= (int)$CurrentUserId ?>">your profile</a> to upload your own heraldry. Don't have heraldry of your own? Reach out to your park or kingdom Regent to be connected with heraldic resources who can help.
					</div>
				</div>
				<?php endif; ?>
			</div><!-- /pk-tab-heraldry -->
			<?php endif; ?>

			<!-- Reports Tab -->
			<div class="pk-tab-panel" id="pk-tab-reports" style="display:none">
				<?php if (!$LoggedIn): ?>
				<div style="background:#eaf4fb;border:1px solid #b0d4ea;border-radius:4px;padding:8px 14px;margin-bottom:10px;font-size:0.9em;color:#1a5276;">
					<i class="fas fa-info-circle"></i> <a href="<?= UIR ?>Login" style="color:#1a5276;font-weight:600;">Log in</a> to see the full list of available reports.
				</div>
				<?php endif; ?>
				<div class="pk-reports-mobile-notice">
					<i class="fas fa-info-circle"></i>
					Some reports may not display as expected on mobile. For best results, view reports on a full screen device.
				</div>
				<div class="kn-reports-grid">
					<div class="kn-report-group">
						<h5><i class="fas fa-users"></i> Players</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/roster/Park&id=<?= $park_id ?>">Player Roster</a></li>
							<?php if ($LoggedIn): ?>
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
							<li><a href="<?= UIR ?>Reports/closest_parks&ParkId=<?= $park_id ?>"><i class="fas fa-map-marker-alt"></i> Closest Parks</a></li>
							<?php endif; ?>
						</ul>
					</div>
					<div class="kn-report-group">
						<h5><i class="fas fa-calendar-check"></i> Attendance</h5>
						<ul>
							<?php if ($LoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/3">Past 3 Months</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/6">Past 6 Months</a></li>
							<?php if ($LoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/All">All Time</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/event_attendance/Park/<?= $park_id ?>"><i class="fas fa-calendar-alt"></i> Event Attendance</a></li>
						</ul>
					</div>
					<?php if ($LoggedIn): ?>
					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Recommendations</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=0&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Player Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Class Masters</a></li>
							<li><a href="<?= UIR ?>Reports/ladder_grid&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Ladder Award Grid</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Park Guilds</a></li>
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Custom Awards</a></li>
						</ul>
					</div>
					<?php endif; ?>

				</div>
			</div>

			<!-- Admin Tab -->
			<?php if (!empty($CanManagePark)): ?>
			<div class="pk-tab-panel" id="pk-tab-admin" style="display:none">
				<div class="kn-report-cols">
					<div class="kn-report-group">
						<h5><i class="fas fa-users-cog"></i> Players</h5>
						<ul>
							<li><a href="#" onclick="pkOpenAddPlayerModal();return false;">Add Player</a></li>
							<li><a href="#" onclick="pkOpenMovePlayerModal();return false;">Move Player</a></li>
							<li><a href="#" onclick="pkOpenMergePlayerModal();return false;">Merge Players</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Park&id=<?= $park_id ?>">Suspensions</a></li>
						</ul>
					</div>
					<div class="kn-report-group">
						<h5><i class="fas fa-cog"></i> Park</h5>
						<ul>
							<li><a href="<?= UIR ?>Admin/permissions/Park/<?= $park_id ?>">Roles &amp; Permissions</a></li>
						</ul>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Recommendations Tab -->
			<?php if (!empty($ShowRecsTab)): ?>
			<div class="pk-tab-panel" id="pk-tab-recommendations" style="display:none">
				<?php if ($IsLoggedIn): ?>
				<div class="pk-tab-toolbar">
					<button class="pk-btn pk-btn-secondary" onclick="pkOpenRecModal()">
						<i class="fas fa-star"></i> Recommend an Award
					</button>
				</div>
				<?php endif; ?>
				<?php if (empty($AwardRecommendations)): ?>
				<div class="pk-recs-empty">There are no open award recommendations for <?= htmlspecialchars($park_name) ?>.</div>
				<?php else: ?>
				<div class="pk-recs-table-wrap">
					<table id="pk-rec-table" class="pk-recs-table display">
						<thead>
							<tr>
								<th>Player</th>
								<th>Award</th>
								<th>Rank</th>
								<th data-short="Rec. By">Recommended By</th>
								<th>Date</th>
								<th>Notes</th>
								<?php if (!empty($CanManagePark)): ?><th></th><?php endif; ?>
							</tr>
						</thead>
						<tbody id="pk-recs-tbody">
						<?php foreach ($AwardRecommendations as $rec): ?>
						<tr class="pk-rec-row" data-rec-id="<?= (int)$rec['RecommendationsId'] ?>">
							<td><a href="<?= UIR ?>Player/profile/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona']) ?></a></td>
							<td><?= htmlspecialchars($rec['AwardName']) ?></td>
							<td><?= (int)$rec['Rank'] > 0 ? (int)$rec['Rank'] : '&mdash;' ?></td>
							<td><?php if (!empty($rec['RecommendedById'])): ?><a href="<?= UIR ?>Player/profile/<?= (int)$rec['RecommendedById'] ?>"><?= htmlspecialchars($rec['RecommendedByName']) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
							<td><?= htmlspecialchars($rec['DateRecommended']) ?></td>
							<td class="pk-rec-notes">
<?php if (!empty($rec['Reason'])): ?>
							<span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($rec['Reason'], 0, 50)) ?><?php if (mb_strlen($rec['Reason']) > 50): ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($rec['Reason'], 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span><?php endif; ?></span>
<?php else: ?>&mdash;<?php endif; ?>
						</td>
							<?php if (!empty($CanManagePark)): ?>
							<td class="pk-rec-actions">
								<button class="pk-btn pk-btn-primary pk-rec-grant-btn"
									data-rec="<?= htmlspecialchars(json_encode(['MundaneId'=>(int)$rec['MundaneId'],'Persona'=>$rec['Persona'],'KingdomAwardId'=>(int)$rec['KingdomAwardId'],'Rank'=>(int)$rec['Rank'],'Reason'=>$rec['Reason']??''], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>">
									<i class="fas fa-medal"></i> Grant
								</button>
								<button class="pk-rec-dismiss-btn"
									data-rec-id="<?= (int)$rec['RecommendationsId'] ?>">
									<i class="fas fa-times"></i> Dismiss
								</button>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

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
	kingdomId:      <?= (int)($kingdom_id ?? 0) ?>,
	canManage:      <?= !empty($CanManagePark) ? 'true' : 'false' ?>,
	loggedIn:       <?= !empty($IsLoggedIn) ? 'true' : 'false' ?>,
	calEvents:      <?= json_encode(array_values($pkCalEvents ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	calParkDays:    <?= json_encode(array_values($pkCalParkDays ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	preloadOfficers:<?= json_encode($PreloadOfficers ?? []) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	classes:         <?= json_encode(array_values($Classes         ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	recentAttendees: <?= json_encode(array_values($RecentAttendees ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerList:     <?= json_encode(!empty($CanManagePark) ? array_map(function($o) {
		return ['OfficerRole' => $o['OfficerRole'], 'MundaneId' => (int)$o['MundaneId'], 'Persona' => $o['Persona']];
	}, $officerList) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkDetails: {
		url:         <?= json_encode($parkInfo['Url']         ?? '') ?>,
		address:     <?= json_encode($parkInfo['Address']     ?? '') ?>,
		city:        <?= json_encode($parkInfo['City']        ?? '') ?>,
		province:    <?= json_encode($parkInfo['Province']    ?? '') ?>,
		postalCode:  <?= json_encode($parkInfo['PostalCode']  ?? '') ?>,
		mapUrl:      <?= json_encode($parkInfo['MapUrl']      ?? '') ?>,
		description: <?= json_encode($description) ?>,
		directions:  <?= json_encode($directions) ?>,
	},
};
</script>
<?php if ($IsLoggedIn): ?>
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
					<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>Same Player
				</button>
				<button class="pk-btn pk-btn-primary" id="pk-award-save-new" disabled>
					<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>New Player
				</button>
			</div>
		</div>
	</div>
</div>
<div id="pk-att-overlay">
	<div class="pk-modal-box">

		<div class="pk-modal-header" style="padding:12px 20px">
			<h3 class="pk-modal-title">
				<i class="fas fa-clipboard-list" style="margin-right:8px;color:#2b6cb0"></i>Enter Attendance
			</h3>
			<button class="pk-modal-close-btn" id="pk-att-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pk-modal-body" id="pk-att-body">

			<div class="pk-att-date-row">
				<button type="button" class="pk-att-date-display" id="pk-att-date-display" aria-haspopup="true" aria-expanded="false">
					<i class="fas fa-calendar-alt"></i>
					<span id="pk-att-date-label"></span>
				</button>
				<input type="hidden" id="pk-att-date">
			</div>
			<div class="pk-att-cal" id="pk-att-cal" style="display:none">
				<div class="pk-att-cal-hdr">
					<button type="button" class="pk-att-cal-nav" id="pk-att-cal-prev">&#8249;</button>
					<span class="pk-att-cal-month" id="pk-att-cal-month"></span>
					<button type="button" class="pk-att-cal-nav" id="pk-att-cal-next">&#8250;</button>
				</div>
				<div class="pk-att-cal-dow">
					<span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
				</div>
				<div class="pk-att-cal-days" id="pk-att-cal-days"></div>
				<div class="pk-att-cal-footer">
					<button type="button" class="pk-att-cal-today-btn" id="pk-att-cal-today">Today</button>
				</div>
			</div>

			<div class="pk-att-feedback" id="pk-att-feedback" style="display:none"></div>

			<!-- Tab bar -->
			<div class="pk-att-tabs">
				<button class="pk-att-tab pk-att-tab-active" id="pk-att-tab-search" data-panel="pk-att-panel-search">
					<i class="fas fa-search"></i> Search
				</button>
				<button class="pk-att-tab" id="pk-att-tab-recent" data-panel="pk-att-panel-recent">
					<i class="fas fa-users"></i> Recent Park Attendees
				</button>
			</div>

			<!-- Search panel -->
			<div class="pk-att-tab-panel" id="pk-att-panel-search">
				<div class="pk-att-search-section-inner">
					<div class="pk-att-scope-btns">
						<button type="button" class="pk-att-scope-btn pk-att-scope-active" id="pk-att-scope-park">Park</button>
						<button type="button" class="pk-att-scope-btn" id="pk-att-scope-kingdom">Kingdom</button>
						<button type="button" class="pk-att-scope-btn" id="pk-att-scope-global">Global</button>
					</div>
					<div class="pk-att-search-row">
						<div class="pk-att-field pk-att-field-grow">
							<label>Player</label>
							<input type="text" id="pk-att-player-name" autocomplete="off">
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
			</div>

			<!-- Recent Attendees panel -->
			<div class="pk-att-tab-panel" id="pk-att-panel-recent" style="display:none">
				<table class="pk-att-qa-table">
					<thead><tr><th>Player</th><th>Class</th><th data-short="Cr.">Credits</th><th></th></tr></thead>
					<tbody id="pk-att-qa-tbody"></tbody>
				</table>
				<div class="pk-att-qa-empty" id="pk-att-qa-empty" style="display:none">
					No recent attendees in the last 90 days.
				</div>
			</div>

			<!-- Entered today (always visible, shared by both tabs) -->
			<div class="pk-att-entered-section">
				<div class="pk-att-section-label">
					<i class="fas fa-list-check" style="margin-right:6px;color:#a0aec0"></i>Attendance
					<span class="pk-att-entered-count" id="pk-att-entered-count"></span>
				</div>
				<div id="pk-att-entered-empty" class="pk-att-qa-empty">No entries yet for this date.</div>
				<table class="pk-att-entered-table" id="pk-att-entered-table" style="display:none">
					<thead><tr><th>Player</th><th>Class</th><th>Cr.</th><th></th></tr></thead>
					<tbody id="pk-att-entered-tbody"></tbody>
				</table>
			</div>



		</div><!-- /.pk-modal-body -->

		<div class="pk-modal-footer" style="justify-content:flex-end">
			<button class="pk-btn pk-btn-ghost" id="pk-att-done-btn">Done</button>
		</div>

	</div>
</div>

<!-- Recommend Award Modal -->
<div id="pk-rec-overlay">
	<div class="pk-modal-box" style="width:520px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-star" style="margin-right:8px;color:#d69e2e"></i>Make a Recommendation</h3>
			<button class="pk-modal-close-btn" id="pk-rec-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div class="pk-form-error" id="pk-rec-error" style="display:none"></div>
			<div class="pk-award-success" id="pk-rec-success" style="display:none">
				<i class="fas fa-check-circle"></i> Recommendation submitted!
			</div>
			<div class="pk-acct-field">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-rec-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="pk-rec-player-id" value="" />
				<div class="pk-ac-results" id="pk-rec-player-results"></div>
			</div>
			<div class="pk-acct-field">
				<label for="pk-rec-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="pk-rec-award-select">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
			</div>
			<div class="pk-acct-field" id="pk-rec-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<div class="pk-rank-pills-wrap" id="pk-rec-rank-pills"></div>
				<input type="hidden" id="pk-rec-rank-val" value="" />
			</div>
			<div class="pk-acct-field">
				<label for="pk-rec-reason">Reason <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-rec-reason" maxlength="400" placeholder="Why should this player receive this award?" />
				<span class="pk-char-count" id="pk-rec-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn-ghost" id="pk-rec-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-rec-submit" disabled>
				<i class="fas fa-paper-plane"></i> Submit Recommendation
			</button>
		</div>
	</div>
</div>

<?php endif; ?>

<?php if ($CanManagePark ?? false): ?>

<div class="pk-emod-overlay" id="pk-event-modal">
	<div class="pk-emod-box">
		<div class="pk-emod-header">
			<h3><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="pk-emod-close" onclick="pkCloseEventModal()">&times;</button>
		</div>
		<div class="pk-emod-body">
			<div class="pk-emod-field">
				<label class="pk-emod-label">Event Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="pk-emod-input" id="pk-event-name" autocomplete="off" placeholder="e.g. Summer Dragonmaster">
			</div>
			<div id="pk-emod-date-row" style="display:none;font-size:12px;color:#2b6cb0;margin-top:8px;padding:5px 8px;background:#ebf8ff;border-radius:5px;border-left:3px solid #90cdf4">
				<i class="fas fa-calendar-alt" style="margin-right:5px"></i><span id="pk-emod-date-text"></span>
			</div>
			<p class="pk-emod-hint" style="margin-top:8px">This event will be assigned to <strong><?= htmlspecialchars($park_name ?? '') ?></strong>. You'll set dates and details on the next page.</p>
			<div class="pk-emod-feedback" id="pk-emod-feedback" style="display:none"></div>
		</div>
		<div class="pk-emod-footer">
			<button class="pk-emod-btn-cancel" onclick="pkCloseEventModal()">Cancel</button>
			<button class="pk-emod-btn-go" id="pk-emod-go-btn" onclick="pkCreateEvent()" disabled>
				Create Event <i class="fas fa-arrow-right"></i>
			</button>
		</div>
	</div>
</div>

<?php endif; ?>

<?php if ($CanManagePark ?? false): ?>
<!-- Add Player Modal -->
<div id="pk-addplayer-overlay">
	<div class="pk-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Add Player</h3>
			<button class="pk-modal-close-btn" id="pk-addplayer-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div id="pk-addplayer-feedback" class="plr-feedback" style="display:none"></div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Persona <span class="plr-req">*</span></label>
					<input type="text" id="pk-addplayer-persona" placeholder="In-game name">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>First Name</label>
					<input type="text" id="pk-addplayer-given" placeholder="Given name">
				</div>
				<div class="plr-field">
					<label>Last Name</label>
					<input type="text" id="pk-addplayer-surname" placeholder="Surname">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Email</label>
					<input type="email" id="pk-addplayer-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Username <span class="plr-req">*</span></label>
					<input type="text" id="pk-addplayer-username" placeholder="min. 4 characters" autocomplete="new-password">
				</div>
				<div class="plr-field">
					<label>Password <span class="plr-req">*</span></label>
					<input type="password" id="pk-addplayer-password" placeholder="password" autocomplete="new-password">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Restricted</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="pk-addplayer-restricted" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="pk-addplayer-restricted" value="1"> Yes</label>
					</div>
				</div>
				<div class="plr-field">
					<label>Waivered</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="pk-addplayer-waivered" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="pk-addplayer-waivered" value="1"> Yes</label>
					</div>
				</div>
			</div>
			<div class="plr-field-row" id="pk-addplayer-waiver-row" style="display:none">
				<div class="plr-field plr-field-grow">
					<label>Waiver File <span class="plr-hint">(PDF, PNG, JPG, or GIF)</span></label>
					<input type="file" id="pk-addplayer-waiver" accept=".pdf,image/png,image/jpeg,image/gif">
				</div>
			</div>
		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn pk-btn-ghost" id="pk-addplayer-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-addplayer-submit">
				<i class="fas fa-user-plus"></i> Create Player
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Parknew: Edit Officers Modal
     ============================================= -->
<?php if (!empty($CanManagePark)): ?>
<div id="pk-editoff-overlay">
	<div class="pk-modal-box" style="width:520px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#2c5282"></i>Edit Officers</h3>
			<button class="pk-modal-close-btn" id="pk-editoff-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div id="pk-editoff-feedback" class="pk-editoff-feedback" style="display:none"></div>
			<p class="pk-editoff-hint">Search for a player to assign to each role, or click Vacate to remove the current officer.</p>
			<div id="pk-editoff-rows"></div>
		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn pk-btn-ghost" id="pk-editoff-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-editoff-submit"><i class="fas fa-save"></i> Save Officers</button>
		</div>
	</div>
</div>

<!-- =============================================
     Parknew: Add Park Day Modal
     ============================================= -->
<div id="pk-addday-overlay">
	<div class="pk-modal-box" style="width:540px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-calendar-plus" style="margin-right:8px;color:#2c5282"></i>Add Park Day</h3>
			<button class="pk-modal-close-btn" id="pk-addday-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div id="pk-addday-feedback" class="pk-addday-feedback" style="display:none"></div>

			<div class="pk-addday-field">
				<label>Purpose</label>
				<div class="pk-seg-group">
					<button type="button" class="pk-seg-btn pk-seg-active" data-group="purpose" data-val="fighter-practice">Fighter Practice</button>
					<button type="button" class="pk-seg-btn" data-group="purpose" data-val="arts-day">A&amp;S Day</button>
					<button type="button" class="pk-seg-btn" data-group="purpose" data-val="other">Other</button>
				</div>
				<input type="hidden" id="pk-addday-purpose" value="fighter-practice" />
			</div>

			<div class="pk-addday-field">
				<label>Recurrence</label>
				<div class="pk-seg-group">
					<button type="button" class="pk-seg-btn pk-seg-active" data-group="recurrence" data-val="weekly">Weekly</button>
					<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="week-of-month">Week of Month</button>
					<button type="button" class="pk-seg-btn" data-group="recurrence" data-val="monthly">Monthly</button>
				</div>
				<input type="hidden" id="pk-addday-recurrence" value="weekly" />
			</div>

			<div class="pk-addday-field" id="pk-addday-weekday-row">
				<label for="pk-addday-weekday">Day of Week</label>
				<select id="pk-addday-weekday">
					<option value="Monday">Monday</option>
					<option value="Tuesday">Tuesday</option>
					<option value="Wednesday">Wednesday</option>
					<option value="Thursday">Thursday</option>
					<option value="Friday">Friday</option>
					<option value="Saturday">Saturday</option>
					<option value="Sunday">Sunday</option>
				</select>
			</div>

			<div class="pk-addday-field" id="pk-addday-weekof-row" style="display:none">
				<label for="pk-addday-weekof">Week of Month</label>
				<select id="pk-addday-weekof">
					<option value="1">1st</option>
					<option value="2">2nd</option>
					<option value="3">3rd</option>
					<option value="4">4th</option>
					<option value="5">5th</option>
				</select>
			</div>

			<div class="pk-addday-field" id="pk-addday-monthday-row" style="display:none">
				<label for="pk-addday-monthday">Day of Month</label>
				<select id="pk-addday-monthday">
					<option value="1">1</option>
					<option value="2">2</option>
					<option value="3">3</option>
					<option value="4">4</option>
					<option value="5">5</option>
					<option value="6">6</option>
					<option value="7">7</option>
					<option value="8">8</option>
					<option value="9">9</option>
					<option value="10">10</option>
					<option value="11">11</option>
					<option value="12">12</option>
					<option value="13">13</option>
					<option value="14">14</option>
					<option value="15">15</option>
					<option value="16">16</option>
					<option value="17">17</option>
					<option value="18">18</option>
					<option value="19">19</option>
					<option value="20">20</option>
					<option value="21">21</option>
					<option value="22">22</option>
					<option value="23">23</option>
					<option value="24">24</option>
					<option value="25">25</option>
					<option value="26">26</option>
					<option value="27">27</option>
					<option value="28">28</option>
					<option value="29">29</option>
					<option value="30">30</option>
					<option value="31">31</option>
				</select>
			</div>

			<div class="pk-addday-field">
				<label for="pk-addday-time">Time <span style="color:#e53e3e">*</span></label>
				<input type="time" id="pk-addday-time" />
			</div>

			<div class="pk-addday-field">
				<label for="pk-addday-desc">Description <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="pk-addday-desc" maxlength="200" placeholder="e.g. Amtgard, practice, etc." />
			</div>

			<div class="pk-addday-field">
				<label>Location</label>
				<div class="pk-addday-loc-radio">
					<label><input type="radio" name="pk-addday-altloc" value="0" checked /> Use Park's Location</label>
					<label><input type="radio" name="pk-addday-altloc" value="1" /> Alternate Location</label>
					<label><input type="radio" name="pk-addday-altloc" value="online" id="pk-addday-online-radio" /> <i class="fas fa-wifi" style="margin-right:3px;color:#0891b2"></i> Online / Virtual</label>
				</div>
			</div>

			<div id="pk-addday-altloc-block" style="display:none">
				<div class="pk-addday-field">
					<label for="pk-addday-address">Address</label>
					<input type="text" id="pk-addday-address" maxlength="100" />
				</div>
				<div class="pk-addday-field-row">
					<div class="pk-addday-field">
						<label for="pk-addday-city">City</label>
						<input type="text" id="pk-addday-city" maxlength="60" />
					</div>
					<div class="pk-addday-field">
						<label for="pk-addday-province">State / Province</label>
						<input type="text" id="pk-addday-province" maxlength="40" />
					</div>
					<div class="pk-addday-field pk-addday-field-sm">
						<label for="pk-addday-postal">Postal Code</label>
						<input type="text" id="pk-addday-postal" maxlength="12" />
					</div>
				</div>
			</div>

		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn pk-btn-ghost" id="pk-addday-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-addday-submit"><i class="fas fa-calendar-plus"></i> Add Park Day</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if (!empty($CanManagePark)): ?>
<!-- Heraldry Upload Modal -->
<div id="pk-heraldry-overlay">
	<div class="pk-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-camera" style="margin-right:8px;color:#2c5282"></i>Change Heraldry</h3>
			<button class="pk-modal-close-btn" id="pk-heraldry-close-btn" aria-label="Close">&times;</button>
		</div>
		<!-- Step: select -->
		<div class="pk-modal-body" id="pk-heraldry-step-select">
			<label class="pn-upload-area" for="pk-heraldry-file-input" style="cursor:pointer">
				<i class="fas fa-cloud-upload-alt pn-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Accepts transparent images</small>
			</label>
			<input type="file" id="pk-heraldry-file-input" accept="image/png,image/jpeg,image/gif" style="display:none">
<?php if ($hasHeraldry): ?>
			<div style="text-align:center;margin-top:14px">
				<button type="button" id="pk-heraldry-remove-btn" class="pn-btn pn-btn-ghost" style="color:#e53e3e;border-color:#feb2b2;font-size:12px;padding:4px 14px">
					<i class="fas fa-trash"></i> Remove Heraldry
				</button>
				<div id="pk-heraldry-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:#fff5f5;border:1px solid #fed7d7;border-radius:6px;font-size:13px;color:#c53030;text-align:left">
					Remove this park's heraldry image?
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('pk-heraldry-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm" style="background:#e53e3e;color:#fff" onclick="pkDoRemoveHeraldry()">Yes, Remove</button>
					</div>
				</div>
			</div>
<?php endif; ?>
		</div>
		<!-- Step: uploading -->
		<div class="pk-modal-body" id="pk-heraldry-step-uploading" style="display:none;text-align:center;padding:40px 20px">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1"></i>
			<p style="margin-top:12px;color:#718096">Uploading&hellip;</p>
		</div>
		<!-- Step: done -->
		<div class="pk-modal-body" id="pk-heraldry-step-done" style="display:none;text-align:center;padding:40px 20px">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>

<!-- Move Player Modal -->
<style>
.pk-mp-toggle { display:flex; background:#edf2f7; border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.pk-mp-toggle-btn {
	flex:1; padding:6px 10px; border:none; border-radius:4px; font-size:12px; font-weight:600;
	cursor:pointer; background:transparent; color:#718096; transition:background 0.15s,color 0.15s;
}
.pk-mp-toggle-btn.pk-mp-active { background:#fff; color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
</style>
<div id="pk-moveplayer-overlay">
	<div class="pk-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="pk-modal-close-btn" id="pk-moveplayer-close-btn">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div id="pk-moveplayer-feedback" style="display:none"></div>
			<!-- Mode toggle -->
			<div class="pk-mp-toggle">
				<button class="pk-mp-toggle-btn pk-mp-active" id="pk-mp-btn-in" type="button">
					<i class="fas fa-arrow-right" style="margin-right:4px"></i>Transfer Into Your Park
				</button>
				<button class="pk-mp-toggle-btn" id="pk-mp-btn-out" type="button">
					<i class="fas fa-arrow-right" style="margin-right:4px"></i>Transfer to New Park
				</button>
			</div>
			<div class="pk-acct-field">
				<label id="pk-moveplayer-player-label">Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-moveplayer-player-name" autocomplete="off" placeholder="Search by name, or KD:PK name…">
				<input type="hidden" id="pk-moveplayer-player-id">
				<div class="pk-ac-results" id="pk-moveplayer-player-results"></div>
			</div>
			<div class="pk-acct-field" id="pk-moveplayer-park-section" style="margin-top:10px;display:none">
				<label>New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-moveplayer-park-name" autocomplete="off" placeholder="Search parks…">
				<input type="hidden" id="pk-moveplayer-park-id">
				<div class="pk-ac-results" id="pk-moveplayer-park-results"></div>
			</div>
		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn-ghost" id="pk-moveplayer-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-moveplayer-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- Merge Players Modal (Park) -->
<div id="pk-mergeplayer-overlay">
	<div class="pk-modal-box" style="width:540px;max-width:calc(100vw - 40px)">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-compress-alt" style="margin-right:8px;color:#c53030"></i>Merge Players</h3>
			<button class="pk-modal-close-btn" id="pk-mergeplayer-close-btn">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div id="pk-mergeplayer-feedback" style="display:none"></div>
			<div class="plr-merge-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div>
					<strong>This action is permanent and cannot be undone.</strong><br>
					The <em>Remove</em> player&rsquo;s account will be permanently deleted. All their awards, attendance, officer history, unit memberships, and notes will be transferred to the <em>Keep</em> player. Any attendance on the same date as an existing record will be dropped.
				</div>
			</div>
			<div class="pk-acct-field">
				<label>Player to Keep <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-merge-keep-name" placeholder="Search for player to keep&hellip;" autocomplete="off">
				<input type="hidden" id="pk-merge-keep-id">
				<div class="pk-ac-results" id="pk-merge-keep-results"></div>
			</div>
			<div class="pk-acct-field" style="margin-top:12px">
				<label>Player to Remove &mdash; <span style="color:#c53030;font-size:12px"><i class="fas fa-skull-crossbones"></i> this account will be permanently deleted</span> <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pk-merge-remove-name" placeholder="Search for player to remove&hellip;" autocomplete="off">
				<input type="hidden" id="pk-merge-remove-id">
				<div class="pk-ac-results" id="pk-merge-remove-results"></div>
			</div>
			<div class="plr-merge-summary" id="pk-merge-summary" style="display:none">
				<strong>What will happen when you click Merge:</strong>
				<ul>
					<li>All attendance &rarr; transferred to <strong id="pk-merge-keep-display"></strong> (duplicate dates dropped)</li>
					<li>All awards &amp; award history &rarr; transferred</li>
					<li>All officer roles &rarr; transferred</li>
					<li>All unit memberships &rarr; transferred</li>
					<li>Notes &rarr; transferred</li>
					<li style="color:#c53030"><strong id="pk-merge-remove-display"></strong>&rsquo;s account record is permanently deleted</li>
				</ul>
			</div>
		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn-ghost" id="pk-mergeplayer-cancel">Cancel</button>
			<button class="pk-btn" id="pk-mergeplayer-submit" disabled style="background:#c53030;color:#fff;border-color:#c53030"><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- Shared Confirmation Modal -->
<div id="kn-confirm-overlay">
	<div class="kn-modal-box kn-confirm-box">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title" id="kn-confirm-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#e53e3e"></i>Confirm</h3>
			<button class="kn-modal-close-btn" id="kn-confirm-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<p id="kn-confirm-message" style="margin:0;font-size:14px;color:#2d3748;line-height:1.6"></p>
		</div>
		<div class="kn-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="kn-btn-ghost" id="kn-confirm-cancel-btn">Cancel</button>
			<button class="kn-admin-save-btn kn-confirm-ok-btn" id="kn-confirm-ok-btn">Confirm</button>
		</div>
	</div>
</div>

<!-- Park Administration Modal -->
<div id="pk-admin-overlay">
	<div class="kn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">

		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-cog" style="margin-right:8px;color:#2b6cb0"></i>Park Administration</h3>
			<button class="kn-modal-close-btn" id="pk-admin-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="kn-modal-body">

			<!-- ── Panel: Park Details ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="pk-admin-hdr-details" aria-expanded="true">
					<span><i class="fas fa-edit" style="margin-right:6px;color:#a0aec0"></i>Park Details</span>
					<i class="fas fa-chevron-down kn-admin-chevron kn-admin-chevron-open" id="pk-admin-chev-details"></i>
				</button>
				<div class="kn-admin-panel-body" id="pk-admin-body-details">
					<div id="pk-admin-details-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-field">
						<label for="pk-editdetails-url">Website URL</label>
						<input type="url" id="pk-editdetails-url" placeholder="https://example.com" />
					</div>
					<div class="kn-admin-field">
						<label for="pk-editdetails-address">Street Address</label>
						<input type="text" id="pk-editdetails-address" placeholder="123 Main St" />
					</div>
					<div class="kn-admin-field-row">
						<div class="kn-admin-field">
							<label for="pk-editdetails-city">City</label>
							<input type="text" id="pk-editdetails-city" placeholder="City" />
						</div>
						<div class="kn-admin-field">
							<label for="pk-editdetails-province">Province / State</label>
							<input type="text" id="pk-editdetails-province" placeholder="State / Province" />
						</div>
					</div>
					<div class="kn-admin-field-row">
						<div class="kn-admin-field">
							<label for="pk-editdetails-postalcode">Postal Code</label>
							<input type="text" id="pk-editdetails-postalcode" placeholder="Zip / Postal Code" />
						</div>
						<div class="kn-admin-field">
							<label for="pk-editdetails-mapurl">Map URL</label>
							<input type="url" id="pk-editdetails-mapurl" placeholder="Google Maps link..." />
						</div>
					</div>
					<div class="kn-admin-field">
						<label for="pk-editdetails-description">Description</label>
						<textarea id="pk-editdetails-description" rows="4" placeholder="About this park..."></textarea>
					</div>
					<div class="kn-admin-field">
						<label for="pk-editdetails-directions">Directions</label>
						<textarea id="pk-editdetails-directions" rows="3" placeholder="How to find us..."></textarea>
					</div>
					<button class="kn-admin-save-btn" id="pk-admin-details-save">
						<i class="fas fa-save"></i> Save Details
					</button>
				</div>
			</div>

			<!-- ── Panel: Operations ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="pk-admin-hdr-ops" aria-expanded="false">
					<span><i class="fas fa-tools" style="margin-right:6px;color:#a0aec0"></i>Operations</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="pk-admin-chev-ops"></i>
				</button>
				<div class="kn-admin-panel-body" id="pk-admin-body-ops" style="display:none">
					<div id="pk-admin-ops-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-ops-row">
						<div class="kn-admin-ops-info">
							<strong>Reset Waivers</strong>
							<p>Clears all waiver records for this park. This action cannot be undone.</p>
						</div>
						<button class="kn-admin-ops-btn kn-admin-ops-btn-danger" id="pk-admin-reset-waivers-btn">
							<i class="fas fa-eraser"></i> Reset Waivers
						</button>
					</div>
				</div>
			</div>

		</div><!-- /.kn-modal-body -->

		<div class="kn-modal-footer" style="justify-content:flex-end">
			<button class="kn-btn-ghost" id="pk-admin-done-btn">Done</button>
		</div>

	</div>
</div>

<?php endif; ?>
<!-- [TOURNAMENTS HIDDEN] add-tournament modal -->
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
	if ($('#pk-rec-table').length) {
		$('#pk-rec-table').DataTable({
			order: [[4, 'desc']],
			columnDefs: [
				{ targets: [4], type: 'date' },
				<?php if (!empty($CanManagePark)): ?>
				{ targets: [-1], orderable: false, searchable: false },
				<?php endif; ?>
			],
			pageLength: 25
		});
	}
});
</script>