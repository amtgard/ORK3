<?php
	// ---- Normalize data into clean local variables ----
	$parkInfo    = $park_info['ParkInfo']     ?? [];
	$heraldryUrl = $park_info['Heraldry']['Url'] ?? '';
	$hasHeraldry = !empty($parkInfo['HasHeraldry']);
	$hasBanner       = !empty($parkInfo['HasBanner']);
	$bannerShowLogo  = !isset($parkInfo['BannerShowLogo']) || (int)$parkInfo['BannerShowLogo'] !== 0;
	$bannerVignette  = !isset($parkInfo['BannerVignette']) || (int)$parkInfo['BannerVignette'] !== 0;
	$bannerOffsetX   = isset($parkInfo['BannerOffsetX']) ? max(0, min(100, (int)$parkInfo['BannerOffsetX'])) : 50;
	$bannerOffsetY   = isset($parkInfo['BannerOffsetY']) ? max(0, min(100, (int)$parkInfo['BannerOffsetY'])) : 50;
	$bannerUrl       = '';
	if ($hasBanner) {
		$bannerFile = Common::resolve_image_ext(DIR_PARK_BANNER, sprintf('%05d', (int)($parkInfo['ParkId'] ?? 0)));
		$bannerFs   = DIR_PARK_BANNER . $bannerFile;
		if (file_exists($bannerFs)) {
			$bannerUrl = HTTP_PARK_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
		}
	}
	$pkCanManageBanner = !empty($CanManagePark);
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

	// Group all players by year of last sign-in (newest year first; 'never' last).
	// Also separately track players whose last signin is within the past 6 months
	// (for the "active member" stat) and 12 months (for the Heraldry hall of arms).
	$allPlayers      = $park_players ?? [];
	$nowTs           = time();
	$nowYear         = (int)date('Y');
	$sixMoCutoff     = strtotime('-6 months');
	$twelveMoCutoff  = strtotime('-12 months');
	$playersByYear   = [];   // key: 'YYYY' or 'never', value: array of players
	$playerList      = [];   // active in past 6 months
	$hoaPlayers12    = [];   // heraldry holders active in past 12 months
	$totalHeraldry   = 0;
	foreach ($allPlayers as $p) {
		$ts  = strtotime($p['LastSignin']);
		$key = ($ts && $ts > 0 && date('Y', $ts) !== '1970') ? date('Y', $ts) : 'never';
		$playersByYear[$key][] = $p;
		if (!empty($p['HasHeraldry'])) {
			$totalHeraldry++;
			if ($ts >= $twelveMoCutoff) $hoaPlayers12[] = $p;
		}
		if ($ts >= $sixMoCutoff) $playerList[] = $p;
	}
	// Sort: real years descending (newest first); 'never' bucket goes last.
	uksort($playersByYear, function ($a, $b) {
		if ($a === 'never') return 1;
		if ($b === 'never') return -1;
		return strcmp($b, $a);
	});

	$firstTab = 'about';

	// Render Markdown for display (no images)
	require_once(DIR_LIB . 'Parsedown.php');
	if (!function_exists('pk_markdown')) {
		function pk_markdown(string $text): string {
			$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($text);
			return preg_replace('/<img[^>]*>/i', '', $html);
		}
	}


	// Pre-compute FullCalendar event data
	$pkCalEvents = [];
	foreach ($eventList as $ev) {
		if (!$ev['NextDate'] || $ev['NextDate'] === '0000-00-00') continue;
		if (!empty($ev['_IsCalendarItem'])) {
			$allDay = !empty($ev['AllDay']);
			$calEv = [
				'title'         => $ev['Name'],
				'start'         => $allDay ? substr($ev['NextDate'], 0, 10) : $ev['NextDate'],
				'color'         => '#64748b',
				'type'          => 'calendar-item',
				'allDay'        => $allDay,
				'extendedProps' => [
					'calendarItemId' => $ev['CalendarItemId'],
					'description'    => $ev['Description'] ?? '',
					'rawStart'       => $ev['NextDate'],
					'rawEnd'         => $ev['NextEndDate'] ?? $ev['NextDate'],
				],
			];
			$startDate = substr($ev['NextDate'], 0, 10);
			$endDate   = substr($ev['NextEndDate'] ?? $ev['NextDate'], 0, 10);
			if ($endDate > $startDate) {
				$endDt = new DateTime($endDate);
				if ($allDay) $endDt->modify('+1 day');
				$calEv['end'] = $allDay ? $endDt->format('Y-m-d') : ($ev['NextEndDate'] ?? '');
			} elseif (!$allDay) {
				$calEv['end'] = $ev['NextEndDate'] ?? '';
			}
		} elseif (!empty($ev['is_park_day'])) {
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
				'extendedProps' => [
					'eventId'  => (int)$ev['EventId'],
					'detailId' => (int)$ev['NextDetailId'],
					'isDraft'  => (($ev['Status'] ?? 'published') === 'draft'),
				],
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
	$_pd_colors     = ['park-day'=>'#38a169','fighter-practice'=>'#e53e3e','arts-day'=>'#805ad5','other'=>'#ed8936'];
	$_pd_labels     = ['park-day'=>'Park Day','fighter-practice'=>'Fighter Practice','arts-day'=>'A&S Day','other'=>'Other'];
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

	// Active Players: last sign-in within the past 6 months — matches the Players tab subtitle
	$activePlayersYear = count($playerList);
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<?php
	$_heroBgUrl    = $bannerUrl ?: $heraldryUrl;
	$_heroClasses  = 'pk-hero';
	if ($parkIsInactive)              $_heroClasses .= ' pk-hero--inactive';
	if ($bannerUrl)                    $_heroClasses .= ' pk-hero-has-banner';
	if ($bannerUrl && $bannerVignette) $_heroClasses .= ' pk-hero-vignette';
	if ($pkCanManageBanner)            $_heroClasses .= ' pk-hero-editable';
	$_pkShowLogo = !$bannerUrl || $bannerShowLogo;
	$_bgStyle = '';
	if ($_heroBgUrl) {
		$_bgStyle = "background-image: url('" . htmlspecialchars($_heroBgUrl) . "');";
		if ($bannerUrl) {
			$_bgStyle .= ' background-position: ' . $bannerOffsetX . '% ' . $bannerOffsetY . '%;';
		}
	}
?>
<div class="<?= $_heroClasses ?>" id="pk-hero">
	<div class="pk-hero-bg"<?php if ($_bgStyle): ?> style="<?= $_bgStyle ?>"<?php endif; ?>></div>
	<?php if ($pkCanManageBanner): ?>
	<button type="button" class="pk-banner-edit-btn"
			onclick="pkOpenBannerModal()"
			aria-label="<?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?>">
		<i class="fas fa-image"></i>
		<span class="pk-banner-edit-label"> <?= $bannerUrl ? 'Update Banner Image' : 'Add Banner Image' ?></span>
		<i class="fas fa-pencil-alt pk-banner-edit-pencil" aria-hidden="true"></i>
	</button>
	<?php endif; ?>
	<div class="pk-hero-content">

		<!-- Heraldry -->
		<?php if ($_pkShowLogo): ?>
		<div class="pk-hero-left">
			<?php $displayHeraldryUrl = $hasHeraldry ? $heraldryUrl : HTTP_PARK_HERALDRY . '00000.jpg'; ?>
			<div class="pk-heraldry-frame<?= !empty($CanAdminPark) ? ' pk-heraldry-editable' : '' ?>">

				<img class="heraldry-img" src="<?= htmlspecialchars($displayHeraldryUrl) ?>"
				     alt="<?= htmlspecialchars($park_name) ?> heraldry"
				     crossorigin="anonymous"
				     onload="typeof pkApplyHeroColor==='function'&&!<?= $parkIsInactive ? 'true' : 'false' ?>&&pkApplyHeroColor(this)">
				<?php if (!empty($CanAdminPark)): ?>
				<button class="pk-heraldry-edit-btn" onclick="pkOpenHeraldryModal()" title="Change heraldry">
					<i class="fas fa-camera"></i>
				</button>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

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
				<?php endif; ?>
				<?php if (!empty($CanAdminPark)): ?>
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
		<div class="pk-stat-icon"><i class="fas fa-chart-bar"></i></div>
		<div class="pk-stat-value"><?= $WeeklyAvg > 0 ? number_format($WeeklyAvg, 1) : '&mdash;' ?></div>
		<div class="pk-stat-label">Avg / Week <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per week, averaged over the past 6 months. Each player counts once per week. This matches the Top Parks ranking formula.</span></span></div>
	</div>
	<div class="pk-stat-card">
		<div class="pk-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="pk-stat-value"><?= $MonthlyAvg > 0 ? number_format($MonthlyAvg, 1) : '&mdash;' ?></div>
		<div class="pk-stat-label">Avg / Month <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per month, averaged over the past 12 months. Higher than Avg/Week because a monthly window captures players who don&rsquo;t attend every week.</span></span></div>
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
			<h4 class="kn-bare-heading" style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-crown"></i> Officers</span>
				<?php if (!empty($CanAdminPark)): ?>
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
			<h4 class="kn-bare-heading"><i class="fas fa-link"></i> Quick Links</h4>
			<ul class="pk-link-list">
				<li>
					<span class="pk-link-icon"><i class="fas fa-search"></i></span>
					<a href="<?= UIR ?>Search/park/<?= $park_id ?>">Search Players</a>
				</li>
				<?php if ($IsLoggedIn): ?>
				<li>
					<span class="pk-link-icon"><i class="fas fa-image"></i></span>
					<a href="<?= UIR ?>Reports/playerheraldry/<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Park Heraldry</a>
				</li>
				<?php endif; ?>
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
				<?php if (!empty($CanAdminPark)): ?>
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
						<div class="pk-about-text kn-description-body"><?= pk_markdown($description) ?></div>
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
						<div class="pk-about-text kn-description-body"><?= pk_markdown($directions) ?></div>
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
						<?php if (!empty($CanAdminPark)): ?>
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
								case 'park-day':         $purposeLabel = 'Park Day';          $purposeCls = 'purpose-parkday'; $iconCls = 'icon-parkday'; $iconFa = 'fa-shield-alt'; break;
								case 'fighter-practice': $purposeLabel = 'Fighter Practice';  $purposeCls = 'purpose-fighter'; $iconCls = 'icon-fighter'; $iconFa = 'fa-user-shield'; break;
								case 'arts-day':         $purposeLabel = 'A&S Day';           $purposeCls = 'purpose-arts';    $iconCls = 'icon-arts';    $iconFa = 'fa-palette';    break;
								case 'other':            $purposeLabel = 'Other';             $purposeCls = 'purpose-other';   $iconCls = 'icon-other';   $iconFa = 'fa-star';       break;
								default:                 $purposeLabel = 'Park Day';          $purposeCls = 'purpose-parkday'; $iconCls = 'icon-parkday'; $iconFa = 'fa-shield-alt';
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
						<div class="pk-schedule-card"
					data-day-id="<?= (int)$day['ParkDayId'] ?>"
					data-purpose="<?= htmlspecialchars($day['Purpose'] ?? '') ?>"
					data-recurrence="<?= htmlspecialchars($day['Recurrence'] ?? '') ?>"
					data-weekday="<?= htmlspecialchars($day['WeekDay'] ?? '') ?>"
					data-weekof="<?= (int)($day['WeekOfMonth'] ?? 0) ?>"
					data-monthday="<?= (int)($day['MonthDay'] ?? 0) ?>"
					data-time="<?= htmlspecialchars($day['Time'] ?? '') ?>"
					data-desc="<?= htmlspecialchars($day['Description'] ?? '', ENT_QUOTES) ?>"
					data-online="<?= (int)($day['Online'] ?? 0) ?>"
					data-altloc="<?= (int)($day['AlternateLocation'] ?? 0) ?>"
					data-address="<?= htmlspecialchars($day['Address'] ?? '', ENT_QUOTES) ?>"
					data-city="<?= htmlspecialchars($day['City'] ?? '', ENT_QUOTES) ?>"
					data-province="<?= htmlspecialchars($day['Province'] ?? '', ENT_QUOTES) ?>"
					data-postal="<?= htmlspecialchars($day['PostalCode'] ?? '', ENT_QUOTES) ?>">
				<?php if (!empty($CanAdminPark)): ?>
				<button class="pk-schedule-card-edit" title="Edit park day"><i class="fas fa-pencil-alt"></i></button>
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
					<h4 class="kn-bare-heading" style="margin:0;font-size:14px;font-weight:700;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<button class="pk-view-btn pk-view-active" id="pk-ev-view-list" title="List view"><i class="fas fa-list"></i></button>
						<button class="pk-view-btn" id="pk-ev-view-cal" title="Calendar view"><i class="fas fa-calendar-alt"></i></button>
						<button class="pk-view-btn" id="pk-ev-view-map" title="Map view"><i class="fas fa-map-marked-alt"></i></button>
						<div id="pk-ev-filter-bar" style="display:flex;align-items:center;gap:5px;">
							<span style="font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.05em;margin-right:2px;">Show:</span>
							<button class="pk-filter-toggle pk-filter-on" data-filter="event">Events</button>
							<button class="pk-filter-toggle pk-filter-on" data-filter="calendar-item">Calendar Items</button>
							<?php if (count($parkDayList) > 0): ?>
							<button class="pk-filter-toggle" data-filter="park-day">Park Days</button>
							<?php endif; ?>
						</div>
						<?php if ($CanAdminPark): ?>
						<button onclick="pkOpenEventModal()"style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Event
						</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Calendar view (lazy-loaded FullCalendar) -->
				<div id="pk-events-cal" style="display:none"></div>

				<!-- Map view (lazy-loaded Google Maps) -->
				<div id="pk-events-map-wrap" style="position:relative;display:none">
					<div id="pk-events-map" style="width:100%;height:480px;border-radius:8px;border:1px solid #e2e8f0;"></div>
					<div id="pk-events-map-footer" style="margin-top:8px;font-size:12px;color:#718096;display:none"></div>
				</div>

				<!-- List view -->
				<div id="pk-events-list-view">
				<?php if (count($eventList) > 0 || count($parkDayList) > 0): ?>
					<table class="pk-table" id="pk-events-table">
						<thead>
							<tr>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="date">Next Date</th>
								<th colspan="2" style="text-align:center;">RSVP</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($eventList as $event): ?>
								<?php if (!empty($event['_IsCalendarItem'])): ?>
								<?php $ciOff = !empty($event['IsOfficerOnly']); $ciLoc = !empty($event['IsLocalsOnly']); ?>
								<tr class="<?= $ciOff ? 'pk-officer-only' : '' ?> <?= $ciLoc ? 'pk-locals-only' : '' ?>" data-type="calendar-item" onclick="pkShowCalendarItemOverlay(<?= (int)$event['CalendarItemId'] ?>)">
									<td>
										<span class="pk-ci-pill"><i class="fas fa-calendar-day"></i> Calendar Item</span>
										<?php if ($ciOff): ?><span class="pk-officer-pill" data-tip="Officer-only — hidden from non-officers"><i class="fas fa-shield-alt"></i></span><?php endif; ?><?php if ($ciLoc): ?><span class="pk-locals-pill" data-tip="Locals-only — hidden from out-of-area players"><i class="fas fa-map-marker-alt"></i></span><?php endif; ?>
										<?= htmlspecialchars($event['Name']) ?>
										<?php if ($event['NextDetailId']): ?>
											<span class="pk-copy-link" data-url="<?= HTTP_UI ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>" onclick="event.stopPropagation(); pkCopyEventLink(this)" data-tip="Copy the event link and share to boost RSVPs!"><i class="fas fa-link"></i></span>
										<?php endif; ?>
									</td>
									<td class="pk-date-col" data-sortval="<?= htmlspecialchars($event['NextDate']) ?>">
										<?= $event['NextDate'] ? date('M. j, Y', strtotime($event['NextDate'])) : '' ?>
									</td>
									<td class="pk-date-col" style="text-align:center;color:#a0aec0">—</td>
									<td class="pk-date-col" style="text-align:center;color:#a0aec0">—</td>
								</tr>
								<?php else: ?>
								<?php $isDraft = (($event['Status'] ?? 'published') === 'draft'); ?>
								<tr class="<?= $isDraft ? 'pk-row-draft' : '' ?>" data-type="event"<?= $event['NextDetailId'] ? ' onclick="if(event.target.closest(\'.pk-rsvp-wrap\'))return; window.location.href=\''.UIR.'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] . '\'"' : '' ?>>
									<td>
										<div class="pk-tiny-heraldry">
											<?php if ($event['HasHeraldry'] == 1): ?>
												<img src="<?= HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $event['EventId'])) ?>"
												     loading="lazy"
												     onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'">
											<?php else: ?>
												<img loading="lazy" src="<?= HTTP_EVENT_HERALDRY ?>00000.jpg">
											<?php endif; ?>
											<?php if ($isDraft): ?><span class="pk-draft-pill" data-tip="Draft — hidden from members. Publish to make visible.">DRAFT</span><?php endif; ?><?= htmlspecialchars($event['Name']) ?>
											<?php if ($event['NextDetailId']): ?>
												<span class="pk-copy-link" data-url="<?= HTTP_UI ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>" onclick="event.stopPropagation(); pkCopyEventLink(this)" data-tip="Copy the event link and share to boost RSVPs!"><i class="fas fa-link"></i></span>
											<?php endif; ?>
										</div>
									</td>
									<td class="pk-date-col" data-sortval="<?= $event['NextDate'] ?>">
										<?php if (0 != $event['NextDate']): ?>
											<?= date('M. j, Y', strtotime($event['NextDate'])) ?>
											<?php if (strtotime($event['NextDate']) < time()): ?><span class='event-past-badge'>Past</span><?php endif; ?>
										<?php endif; ?>
									</td>
									<td class="pk-date-col" colspan="2" style="text-align:center;padding:6px 8px;">
										<?php if ((int)$event['NextDetailId'] > 0): ?>
											<span class="pk-rsvp-wrap" data-detail="<?= (int)$event['NextDetailId'] ?>" data-going="<?= (int)($event['RsvpGoing'] ?? 0) ?>" data-interested="<?= (int)($event['RsvpInterested'] ?? 0) ?>" data-mine="<?= htmlspecialchars($event['MyRsvp'] ?? '') ?>"></span>
										<?php else: ?>
											<span style="color:#a0aec0">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php foreach ($parkDayList as $pkDay): ?>
							<?php
								switch ($pkDay['Recurrence']) {
									case 'weekly':        $pkDayRec = 'Every ' . $pkDay['WeekDay']; break;
									case 'week-of-month': $pkDayRec = 'Every ' . pk_ordinal($pkDay['WeekOfMonth']) . ' ' . $pkDay['WeekDay']; break;
									case 'monthly':       $pkDayRec = 'Monthly on the ' . pk_ordinal($pkDay['MonthDay']); break;
									default:              $pkDayRec = $pkDay['Recurrence'];
								}
								$pkPurposeLabels = ['park-day'=>'Park Day','fighter-practice'=>'Fighter Practice','arts-day'=>'A&S Day','other'=>'Other'];
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
							<?= count($playerList) ?> active member<?= count($playerList) != 1 ? 's' : '' ?> (past 6 months)<?php if (count($allPlayers) > count($playerList)): ?> &middot; <?= count($allPlayers) ?> total<?php endif; ?>
						</span>
						<div class="pk-players-toolbar-right">
							<div class="pk-player-search-wrap">
								<i class="fas fa-search pk-player-search-icon"></i>
								<input type="text" id="pk-player-search" class="pk-player-search-input" placeholder="Search all players…" autocomplete="off">
							</div>
							<button class="pk-view-btn" id="pk-active-only-btn" type="button" title="Show only members with sign-ins in the past 6 months"><i class="fas fa-filter"></i> Active only</button>
							<div class="pk-view-toggle">
								<button class="pk-view-btn pk-view-active" data-pkview="cards">
									<i class="fas fa-th-large"></i> Cards
								</button>
								<button class="pk-view-btn" data-pkview="list">
									<i class="fas fa-list"></i> List
								</button>
							</div>
							<?php if ($CanAdminPark ?? false): ?>
							<div class="plr-action-group">
								<button class="plr-add-btn" onclick="pkOpenAddPlayerModal()"><i class="fas fa-user-plus"></i> Create Player</button>
								<div class="plr-gear-wrap">
									<button class="plr-gear-btn" id="pk-plr-gear-btn" aria-label="Player actions" aria-expanded="false" onclick="var m=this.nextElementSibling;var o=m.classList.toggle('open');this.setAttribute('aria-expanded',o)"><i class="fas fa-cog"></i></button>
									<div class="plr-gear-menu" id="pk-plr-gear-menu">
										<button class="plr-gear-item" onclick="pkOpenMovePlayerModal();document.getElementById('pk-plr-gear-menu').classList.remove('open')"><i class="fas fa-people-arrows"></i> Move Player</button>
										<?php if (!empty($CanMergePlayers)): ?><button class="plr-gear-item" onclick="pkOpenMergePlayerModal();document.getElementById('pk-plr-gear-menu').classList.remove('open')"><i class="fas fa-compress-alt"></i> Merge Players</button><?php endif; ?>
									</div>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>

					<?php
						// Renderers reused for each year section.
						$pkRenderCard = function (array $p) {
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
							   <?= !empty($p['MundaneName']) ? 'data-mundane-name="' . htmlspecialchars(strtolower($p['MundaneName'])) . '"' : '' ?>
							   data-signin-count="<?= (int)$p['SigninCount'] ?>"
							   href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>">
								<div class="pk-player-card-top">
									<div class="pk-player-avatar">
										<?php if ($avatarSrc): ?>
											<img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" loading="lazy" onerror="pkAvatarFallback(this,'<?= $initial ?>')">
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
									<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> <?= $p['SigninCount'] ?> six month sign-in<?= $p['SigninCount'] != 1 ? 's' : '' ?></span>
									<?php $_lsTs = strtotime($p['LastSignin']); ?>
									<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> <?= ($_lsTs > 0 && date('Y', $_lsTs) !== '1970') ? date('M j, Y', $_lsTs) : '—' ?></span>
									<?php
										// "Last here" indicator when the player's most recent sign-in wasn't at this park.
										$_lpTs = !empty($p['LastSigninAtPark']) ? strtotime($p['LastSigninAtPark']) : 0;
										if (!empty($p['LastSigninAtPark']) && $p['LastSigninAtPark'] !== $p['LastSignin']):
											$_hereTxt = ($_lpTs > 0 && date('Y', $_lpTs) !== '1970') ? 'here ' . date('M j, Y', $_lpTs) : 'never here';
									?>
										<span style="color:#a0aec0"><i class="fas fa-flag" style="color:#a0aec0;width:14px"></i> <?= htmlspecialchars($_hereTxt) ?></span>
									<?php endif; ?>
									<?php if (!empty($p['LastClass'])): ?>
										<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> <?= htmlspecialchars($p['LastClass']) ?></span>
									<?php endif; ?>
								</div>
							</a>
							<?php
						};

						$pkRenderRow = function (array $p) {
							?>
							<tr <?= !empty($p['MundaneName']) ? 'data-mundane-name="' . htmlspecialchars(strtolower($p['MundaneName'])) . '"' : '' ?> data-signin-count="<?= (int)$p['SigninCount'] ?>" onclick='window.location.href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>"'>
								<td>
									<?= htmlspecialchars($p['Persona']) ?>
									<?php if (!empty($p['OfficerRoles'])): ?>
										<?php foreach (explode(', ', $p['OfficerRoles']) as $role): ?>
											<span class="pk-officer-pill"><?= htmlspecialchars(trim($role)) ?></span>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td data-sortval="<?= $p['SigninCount'] ?>"><?= $p['SigninCount'] ?></td>
								<?php
									$_lsTsRow = strtotime($p['LastSignin']);
									$_lpTsRow = !empty($p['LastSigninAtPark']) ? strtotime($p['LastSigninAtPark']) : 0;
									$_dateCell = ($_lsTsRow > 0 && date('Y', $_lsTsRow) !== '1970') ? date('M j, Y', $_lsTsRow) : '—';
									if (!empty($p['LastSigninAtPark']) && $p['LastSigninAtPark'] !== $p['LastSignin']) {
										$_hereTxtRow = ($_lpTsRow > 0 && date('Y', $_lpTsRow) !== '1970') ? 'here ' . date('M j, Y', $_lpTsRow) : 'never here';
										$_dateCell .= ' <span style="color:#a0aec0;font-size:.85em">(' . htmlspecialchars($_hereTxtRow) . ')</span>';
									}
								?>
								<td class="pk-date-col" data-sortval="<?= $p['LastSignin'] ?>"><?= $_dateCell ?></td>
								<td><?= htmlspecialchars($p['LastClass'] ?? '') ?></td>
								<td><?= htmlspecialchars($p['OfficerRoles'] ?? '') ?></td>
							</tr>
							<?php
						};

						$pkYearLabel = function ($year) use ($nowYear) {
							if ($year === 'never') return 'No recorded sign-ins';
							return ((int)$year === $nowYear) ? ($year . ' (current)') : (string)$year;
						};
					?>

					<!-- Card view (default) — one details/year section -->
					<div id="pk-players-cards">
						<?php $_pkIdx = 0; foreach ($playersByYear as $_pkYear => $_pkYearPlayers): ?>
						<details class="pk-year-section"<?= $_pkIdx === 0 ? ' open' : '' ?> data-year="<?= htmlspecialchars((string)$_pkYear) ?>">
							<summary class="pk-year-summary">
								<span class="pk-year-label"><?= htmlspecialchars($pkYearLabel($_pkYear)) ?></span>
								<span class="pk-year-count"><?= count($_pkYearPlayers) ?> member<?= count($_pkYearPlayers) != 1 ? 's' : '' ?></span>
							</summary>
							<div class="pk-players-grid">
								<?php foreach ($_pkYearPlayers as $p) { $pkRenderCard($p); } ?>
							</div>
						</details>
						<?php $_pkIdx++; endforeach; ?>
					</div><!-- /pk-players-cards -->

					<!-- List view (hidden by default) — one details/year section, each with its own table -->
					<div id="pk-players-list" style="display:none">
						<?php $_pkIdx = 0; foreach ($playersByYear as $_pkYear => $_pkYearPlayers): ?>
						<details class="pk-year-section"<?= $_pkIdx === 0 ? ' open' : '' ?> data-year="<?= htmlspecialchars((string)$_pkYear) ?>">
							<summary class="pk-year-summary">
								<span class="pk-year-label"><?= htmlspecialchars($pkYearLabel($_pkYear)) ?></span>
								<span class="pk-year-count"><?= count($_pkYearPlayers) ?> member<?= count($_pkYearPlayers) != 1 ? 's' : '' ?></span>
							</summary>
							<table class="pk-table pk-year-table">
								<thead>
									<tr>
										<th data-sorttype="text">Persona</th>
										<th data-sorttype="numeric">6mo Sign-ins</th>
										<th data-sorttype="date">Last Visit</th>
										<th data-sorttype="text">Last Class</th>
										<th data-sorttype="text">Role</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($_pkYearPlayers as $p) { $pkRenderRow($p); } ?>
								</tbody>
							</table>
						</details>
						<?php $_pkIdx++; endforeach; ?>
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
				<?php if (!$IsLoggedIn): ?>
				<div style="background:var(--ork-alert-info-bg,#eaf4fb);border:1px solid var(--ork-alert-info-border,#b0d4ea);border-radius:4px;padding:8px 14px;margin-bottom:10px;font-size:0.9em;color:var(--ork-alert-info-text,#1a5276);">
					<i class="fas fa-info-circle"></i> <a href="<?= UIR ?>Login" style="color:var(--ork-alert-info-text,#1a5276);font-weight:600;">Log in</a> to see the full list of available reports.
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
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/inactive/Park&id=<?= $park_id ?>">Inactive Players</a></li>
							<li><a href="<?= UIR ?>Reports/active/Park&id=<?= $park_id ?>">Active Players</a></li>
							<li><a href="<?= UIR ?>Reports/dues/Park&id=<?= $park_id ?>">Dues Paid</a></li>
							<li><a href="<?= UIR ?>Reports/waivered/Park&id=<?= $park_id ?>">Waivered Players</a></li>
							<li><a href="<?= UIR ?>Reports/unwaivered/Park&id=<?= $park_id ?>">Unwaivered Players</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Park&id=<?= $park_id ?>">Suspended Players</a></li>
							<li><a href="<?= UIR ?>Reports/active_duespaid/Park&id=<?= $park_id ?>">Player Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/active_waivered_duespaid/Park&id=<?= $park_id ?>">Waivered Attendance</a></li>
							<?php if (in_array((int)$kingdom_id, [3, 4, 6, 10, 12, 14, 17, 19, 20, 24, 25, 27, 31, 36, 38])): ?><li><a href="<?= UIR ?>Reports/voting_eligible/Park&id=<?= $park_id ?>">Voting Eligible</a></li><?php endif; ?>
							<li><a href="<?= UIR ?>Reports/reeve&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Corpora Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/player_status_reconciliation/Park&id=<?= $park_id ?>">Player Status Reconciliation</a></li>
							<li><a href="<?= UIR ?>Reports/closest_parks&ParkId=<?= $park_id ?>"><i class="fas fa-map-marker-alt"></i> Closest Parks</a></li>
							<?php endif; ?>
						</ul>
					</div>
					<div class="kn-report-group">
						<h5><i class="fas fa-calendar-check"></i> Attendance</h5>
						<ul>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/3">Past 3 Months</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/6">Past 6 Months</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Park/<?= $park_id ?>/All">All Time</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/event_attendance/Park/<?= $park_id ?>"><i class="fas fa-calendar-alt"></i> Event Attendance</a></li>
						</ul>
					</div>
					<?php if ($IsLoggedIn): ?>
					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<?php if (!empty($AwardRecsPublic) || !empty($CanAdminPark)): ?>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>&ParkId=<?= $park_id ?>">Recommendations</a></li>
							<?php endif; ?>
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
			<?php if (!empty($CanAdminPark)): ?>
			<div class="pk-tab-panel" id="pk-tab-admin" style="display:none">
				<div class="kn-report-cols">
					<div class="kn-report-group">
						<h5><i class="fas fa-users-cog"></i> Players</h5>
						<ul>
							<li><a href="#" onclick="pkOpenAddPlayerModal();return false;">Create Player</a></li>
							<li><a href="#" onclick="pkOpenMovePlayerModal();return false;">Move Player</a></li>
							<?php if (!empty($CanMergePlayers)): ?><li><a href="#" onclick="pkOpenMergePlayerModal();return false;">Merge Players</a></li><?php endif; ?>
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
				<?php if (!empty($CanAdminPark)): ?>
				<div class="kn-rec-filter-bar">
					<button class="kn-rec-filter-btn kn-rec-filter-active" data-filter="open">Open Recs</button>
					<button class="kn-rec-filter-btn" data-filter="below">Below Recommended</button>
					<button class="kn-rec-filter-btn" data-filter="nonladder">Non-Ladder</button>
					<button class="kn-rec-filter-btn" data-filter="already">At or Above Recommended</button>
					<button class="kn-rec-filter-btn" data-filter="all">All</button>
					<span class="kn-rec-filter-info">
						<button class="kn-rec-filter-info-btn" type="button" aria-label="Filter help"><i class="fas fa-question-circle"></i></button>
						<div class="kn-rec-filter-popover">
							<h4 class="kn-bare-heading">About These Filters</h4>
							<dl>
								<dt>Open Recs <small style="font-weight:400;color:#718096">(default)</small></dt>
								<dd>All pending recommendations &mdash; both rank-based and flat awards. Hides recs that have already been fulfilled.</dd>
								<dt>Below Recommended</dt>
								<dd>Players who haven&rsquo;t yet reached the recommended rank. The core action list &mdash; Grant these.</dd>
								<dt>Non-Ladder</dt>
								<dd>Includes titles such as Master, Noble, or Knight, custom awards, and other non-ranked options. Grant or Delete as appropriate.</dd>
								<dt>At or Above Recommended</dt>
								<dd>Players who already hold this award at or above the recommended rank. The rec has been fulfilled &mdash; Delete these to keep the list tidy.</dd>
								<dt>All</dt>
								<dd>Every recommendation regardless of status. Use for a full audit.</dd>
							</dl>
						</div>
					</span>
					<span class="kn-rec-export-btns">
						<button class="kn-rec-export-btn" type="button" onclick="pkRecPrint()"><i class="fas fa-print"></i> Print</button>
						<button class="kn-rec-export-btn" type="button" onclick="pkRecCsv()"><i class="fas fa-download"></i> CSV</button>
					</span>
				</div>
				<?php endif; ?>
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
								<?php if (!empty($IsLoggedIn)): ?><th></th><?php endif; ?>
							</tr>
						</thead>
						<tbody id="pk-recs-tbody">
						<?php foreach ($AwardRecommendations as $rec): ?>
						<tr class="pk-rec-row"
							data-rec-id="<?= (int)$rec['RecommendationsId'] ?>"
							data-filter="<?= !empty($rec['AlreadyHas']) ? 'already' : ((int)$rec['Rank'] > 0 ? 'below' : 'nonladder') ?>">
							<td><a href="<?= UIR ?>Player/profile/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona']) ?></a></td>
							<td><?= htmlspecialchars($rec['AwardName']) ?></td>
							<td><?= (int)$rec['Rank'] > 0 ? (int)$rec['Rank'] : '&mdash;' ?></td>
							<td><?php if (!empty($rec['RecommendedById'])): ?><a href="<?= UIR ?>Player/profile/<?= (int)$rec['RecommendedById'] ?>"><?= htmlspecialchars($rec['RecommendedByName']) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
							<td><?= htmlspecialchars($rec['DateRecommended']) ?></td>
							<td class="pk-rec-notes">
<?php if (!empty($rec['Reason'])): ?>
							<span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($rec['Reason'], 0, 50)) ?><?php if (mb_strlen($rec['Reason']) > 50): ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($rec['Reason'], 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span><?php endif; ?></span>
<?php else: ?>&mdash;<?php endif; ?>
								<?php if (!empty($rec['ViewerCanEditReason'])): ?>
								<button class="rs-edit-reason-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-reason="<?= htmlspecialchars($rec['Reason'] ?? '', ENT_QUOTES) ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your reason"><i class="fas fa-pen"></i></button>
								<?php endif; ?>
								<?php if (!empty($rec['Seconds']) && is_array($rec['Seconds'])): ?>
								<div class="rs-seconds">
									<?php foreach ($rec['Seconds'] as $sec): ?>
									<div class="rs-second"><i class="fas fa-thumbs-up" style="color:#48bb78;font-size:10px"></i><a class="rs-supporter" href="<?= UIR ?>Player/profile/<?= (int)$sec['SupporterMundaneId'] ?>"><?= htmlspecialchars($sec['SupporterName'] ?? '') ?></a><?php if (!empty($sec['Notes'])): $_sn = $sec['Notes']; ?><span class="rs-notes">&mdash; "<?php if (mb_strlen($_sn) > 50): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($_sn, 0, 50)) ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($_sn, 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span></span><?php else: ?><?= htmlspecialchars($_sn) ?><?php endif; ?>"</span><?php else: ?><span class="rs-notes-empty">&mdash; (no comment)</span><?php endif; ?><?php $_canWithdrawSec = !empty($sec['IsMine']) || ($CanAdminPark ?? false); if (!empty($sec['IsMine']) || $_canWithdrawSec): ?> <span class="rs-second-actions"><?php if (!empty($sec['IsMine'])): ?><button class="rs-second-edit" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-notes="<?= htmlspecialchars($sec['Notes'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your notes"><i class="fas fa-pen"></i></button><?php endif; ?><?php if ($_canWithdrawSec): ?><button class="rs-second-withdraw" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-supporter="<?= htmlspecialchars($sec['SupporterName'] ?? '', ENT_QUOTES) ?>" data-rstip="<?= !empty($sec['IsMine']) ? 'Withdraw your second' : 'Remove this second' ?>"><i class="fas fa-times"></i></button><?php endif; ?></span><?php endif; ?></div>
									<?php endforeach; ?>
								</div>
								<?php endif; ?>
							</td>
							<?php if (!empty($IsLoggedIn)): ?>
							<td class="pk-rec-actions rs-tip-right" style="white-space:nowrap;text-align:right;width:1%">
								<?php if (!empty($rec['SecondsCount'])): $_sc = (int)$rec['SecondsCount']; ?>
								<span class="rs-seconds-badge" data-rstip="<?= $_sc ?> supporting <?= $_sc === 1 ? 'second' : 'seconds' ?>"><i class="fas fa-thumbs-up"></i><?= $_sc ?></span>
								<?php endif; ?>
								<?php if (!empty($rec['ViewerCanSecond'])): ?>
								<button class="rs-action-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-recipient="<?= htmlspecialchars($rec['Persona'] ?? '', ENT_QUOTES) ?>" data-rstip="Second this recommendation and add your feedback."><i class="fas fa-plus"></i></button>
								<?php endif; ?>
								<?php if (!empty($CanAdminPark)): ?>
								<button class="pk-btn pk-btn-primary pk-rec-grant-btn"
									data-rec="<?= htmlspecialchars(json_encode(['RecommendationsId'=>(int)$rec['RecommendationsId'],'MundaneId'=>(int)$rec['MundaneId'],'Persona'=>$rec['Persona'],'KingdomAwardId'=>(int)$rec['KingdomAwardId'],'Rank'=>(int)$rec['Rank'],'Reason'=>$rec['Reason']??''], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>">
									<i class="fas fa-medal"></i> Grant
								</button>
								<button class="pk-rec-dismiss-btn"
									data-rec-id="<?= (int)$rec['RecommendationsId'] ?>">
									<i class="fas fa-times"></i> Delete
								</button>
								<?php endif; ?>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
				<?php if (!empty($CanAdminPark)): ?>
				<div class="pk-deleted-recs" id="pk-deleted-recs" data-loaded="0">
					<button type="button" class="pk-deleted-recs-toggle" id="pk-deleted-recs-toggle" aria-expanded="false">
						<span class="pk-deleted-recs-caret">&#9654;</span>
						<span class="pk-deleted-recs-toggle-label">Show Deleted Recommendations</span>
						<span class="pk-deleted-recs-count" id="pk-deleted-recs-count" style="display:none">0</span>
					</button>
					<div class="pk-deleted-recs-body" id="pk-deleted-recs-body" style="display:none">
						<div class="pk-deleted-recs-loading" id="pk-deleted-recs-loading">Loading&hellip;</div>
						<div class="pk-deleted-recs-empty" id="pk-deleted-recs-empty" style="display:none">No deleted recommendations.</div>
						<div class="pk-deleted-recs-search-wrap" style="display:none">
							<i class="fas fa-search"></i>
							<input type="text" class="pk-deleted-recs-search" placeholder="Search player, award, notes, or actor&hellip;" autocomplete="off">
						</div>
						<div class="pk-deleted-recs-no-match" style="display:none">No deleted recommendations match your search.</div>
						<div class="pk-deleted-recs-table-wrap" id="pk-deleted-recs-table-wrap" style="display:none">
							<table class="pk-deleted-recs-table">
								<thead>
									<tr>
										<th>Player</th>
										<th>Award</th>
										<th>Rank</th>
										<th>Notes</th>
										<th>Date Rec.</th>
										<th>Recommended By</th>
										<th>Deleted At</th>
										<th>Deleted By</th>
										<th></th>
									</tr>
								</thead>
								<tbody id="pk-deleted-recs-tbody"></tbody>
							</table>
						</div>
					</div>
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
	canAdmin:       <?= !empty($CanAdminPark)  ? 'true' : 'false' ?>,
	loggedIn:       <?= !empty($IsLoggedIn) ? 'true' : 'false' ?>,
	calEvents:      <?= json_encode(array_values($pkCalEvents ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	calParkDays:    <?= json_encode(array_values($pkCalParkDays ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	preloadOfficers:<?= json_encode($PreloadOfficers ?? []) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	classes:         <?= json_encode(array_values($Classes         ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	recentAttendees: <?= json_encode(array_values($RecentAttendees ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerList:     <?= json_encode(!empty($CanAdminPark) ? array_map(function($o) {
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
window.pkEventMapLocations  = <?= json_encode(array_values($pkEventMapLocations ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.pkEventMapNoLocCount = <?= (int)($pkEventMapNoLocCount ?? 0) ?>;
</script>
<script>
var PkBannerConfig = {
	uir:            '<?= UIR ?>',
	canManage:      <?= $pkCanManageBanner ? 'true' : 'false' ?>,
	entityId:       <?= (int)($parkInfo['ParkId'] ?? 0) ?>,
	hasBanner:      <?= $hasBanner ? 'true' : 'false' ?>,
	bannerShowLogo: <?= $bannerShowLogo ? 'true' : 'false' ?>,
	bannerVignette: <?= $bannerVignette ? 'true' : 'false' ?>,
	bannerOffsetX:  <?= (int)$bannerOffsetX ?>,
	bannerOffsetY:  <?= (int)$bannerOffsetY ?>,
	bannerUrl:      <?= json_encode($bannerUrl) ?>,
};
</script>
<?php if (!empty($CanAdminPark)): ?>
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
				<button type="button" class="pk-award-type-btn" id="pk-award-type-achievements">
					<i class="fas fa-star" style="margin-right:5px"></i>Achievement Titles
				</button>
				<button type="button" class="pk-award-type-btn" id="pk-award-type-associations">
					<i class="fas fa-handshake" style="margin-right:5px"></i>Associations
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
				<label for="pk-award-select" id="pk-award-select-label">Award <span style="color:#e53e3e">*</span></label>
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
				<label>Rank <span id="pk-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
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
				<div id="pk-award-givenby-note" style="display:none;margin-top:6px;padding:8px 12px;background:#ebf8ff;border:1px solid #bee3f8;border-radius:6px;color:#2b6cb0;font-size:12px;line-height:1.5;"><i class="fas fa-info-circle" style="margin-right:5px"></i>This should reflect the person granting the association. For example, if a Knight is taking a Squire, enter the Knight's name here.</div>
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
				<input type="hidden" id="pk-award-kingdom-id" value="<?= (int)($kingdom_id ?? 0) ?>" />
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

<?php endif; ?>

<?php if (!empty($CanManagePark)): ?>
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

			<div id="pk-att-event-nudge" class="pk-att-event-nudge" style="display:none">
				<div class="pk-att-event-nudge-icon"><i class="fas fa-info-circle"></i></div>
				<div class="pk-att-event-nudge-body">
					<p class="pk-att-event-nudge-text">It looks like <strong id="pk-att-event-nudge-name"></strong> is currently happening. Would you like to capture attendance on that event instead? Using event attendance makes for better and more accurate reporting.</p>
					<a class="pk-att-event-nudge-btn" id="pk-att-event-nudge-link" href="#">Go To Event <i class="fas fa-arrow-right"></i></a>
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
			<?php if (!empty($CanManagePark)): ?>
				<button class="pk-att-tab" id="pk-att-tab-link" data-panel="pk-att-panel-link">
					<i class="fas fa-link"></i> Sign-in Link
				</button>
			<?php endif; ?>
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

			<?php if (!empty($CanManagePark)): ?>
			<!-- Sign-in Link panel -->
			<div class="pk-att-tab-panel" id="pk-att-panel-link" style="display:none">
				<div class="pk-att-search-section-inner">
					<div class="pk-att-search-row">
						<div class="pk-att-field pk-att-field-sm">
							<label>Duration (hrs)</label>
							<input type="number" id="pk-att-link-hours" min="1" max="96" step="1" value="3">
						</div>
						<div class="pk-att-field pk-att-field-sm">
							<label>Credits</label>
							<input type="number" id="pk-att-link-credits" min="0.5" max="10" step="0.5" value="1">
						</div>
						<div class="pk-att-field pk-att-field-btn">
							<label>&nbsp;</label>
							<button class="pk-btn pk-btn-primary" id="pk-att-link-gen-btn">
								<i class="fas fa-link"></i> Generate
							</button>
						</div>
					</div>
					<div id="pk-att-link-result" style="display:none;margin-top:12px">
						<div class="pk-att-link-url-row" style="display:flex;gap:8px;align-items:center">
							<input type="text" id="pk-att-link-url" readonly
								style="flex:1;min-width:0;font-size:12px;padding:6px 8px;border:1px solid #cbd5e0;border-radius:4px;background:#f7fafc">
							<button class="pk-btn pk-btn-secondary" id="pk-att-link-copy-btn" style="white-space:nowrap">
								<i class="fas fa-copy"></i> Copy
							</button>
							<button class="pk-btn pk-btn-secondary" id="pk-att-link-qr-btn" style="white-space:nowrap">
								<i class="fas fa-qrcode"></i> QR
							</button>
						</div>
						<div id="pk-att-link-expires" style="margin-top:6px;font-size:11px;color:#718096"></div>
					</div>
					<div style="margin-top:10px;font-size:11px;color:#718096">
						<i class="fas fa-info-circle"></i> Players log in and select their class to record attendance.
					</div>
					<!-- Active links collapsible -->
					<div id="pk-att-links-wrap" style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:10px">
						<button type="button" id="pk-att-links-toggle" style="background:none;border:none;padding:0;cursor:pointer;font-size:12px;color:#4a5568;display:flex;align-items:center;gap:6px">
							<i class="fas fa-chevron-right" id="pk-att-links-chevron" style="font-size:10px;transition:transform 0.15s"></i>
							<span>Active Links</span> <span id="pk-att-links-count" style="color:#a0aec0"></span>
						</button>
						<div id="pk-att-links-body" style="display:none;margin-top:8px">
							<div id="pk-att-links-loading" style="font-size:12px;color:#a0aec0">Loading&hellip;</div>
							<div id="pk-att-links-empty" style="display:none;font-size:12px;color:#a0aec0">No active links.</div>
							<table id="pk-att-links-table" style="display:none;width:100%;border-collapse:collapse;font-size:12px">
								<thead><tr style="color:#718096;text-align:left">
									<th style="padding:4px 6px;font-weight:600">Expires</th>
									<th style="padding:4px 6px;font-weight:600">Cr.</th>
									<th style="padding:4px 6px"></th>
								</tr></thead>
								<tbody id="pk-att-links-tbody"></tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>

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

<!-- QR Code Modal -->
<div id="pk-qr-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9100;align-items:center;justify-content:center" onclick="if(event.target===this)pkCloseQrModal()">
	<div style="background:#fff;border-radius:12px;padding:28px 28px 20px;box-shadow:0 8px 32px rgba(0,0,0,0.22);max-width:320px;width:calc(100vw - 40px);text-align:center">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
			<span style="font-weight:700;font-size:15px;color:var(--ork-text,#2d3748)"><i class="fas fa-qrcode" style="margin-right:8px;color:var(--ork-link,#2b6cb0)"></i>Scan to Sign In</span>
			<button onclick="pkCloseQrModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#a0aec0;line-height:1">&times;</button>
		</div>
		<img id="pk-qr-img" src="" alt="QR Code" style="width:220px;height:220px;border:1px solid #e2e8f0;border-radius:6px;display:block;margin:0 auto 14px">
		<div id="pk-qr-expires" style="font-size:11px;color:#718096;margin-bottom:14px"></div>
		<a id="pk-qr-download" href="" download="signin-qr.png" class="pk-btn pk-btn-secondary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:13px">
			<i class="fas fa-download"></i> Download PNG
		</a>
	</div>
</div>

<!-- Recommend Award Modal — outside CanManagePark block; any logged-in user can submit -->
</div><!-- /CanManagePark -->
<?php endif; ?>
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
				<div id="pk-rec-award-desc" class="pn-rec-award-desc" style="display:none"></div>
			</div>
			<div class="pk-acct-field" id="pk-rec-rank-row" style="display:none">
				<label>Rank <span id="pk-rec-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
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

<?php if ($CanAdminPark ?? false): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="pk-emod-overlay" id="pk-event-modal">
	<div class="pk-emod-box">
		<div class="pk-emod-header">
			<h3 id="pk-emod-title" class="kn-bare-heading"><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="pk-emod-close" onclick="pkCloseEventModal()">&times;</button>
		</div>
		<div class="pk-emod-body">

			<div class="pk-emod-typesel">
				<label class="pk-emod-typeopt">
					<input type="radio" name="pk-emod-type" value="event" checked>
					<span><i class="fas fa-flag"></i> Amtgard Event</span>
				</label>
				<label class="pk-emod-typeopt">
					<input type="radio" name="pk-emod-type" value="calendar-item">
					<span><i class="fas fa-calendar-day"></i> Calendar Item</span>
				</label>
			</div>

			<div class="pk-emod-field">
				<label class="pk-emod-label">Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="pk-emod-input" id="pk-event-name" autocomplete="off" placeholder="e.g. Summer Dragonmaster">
			</div>
			<div id="pk-emod-date-row" style="display:none;font-size:12px;color:var(--ork-alert-info-text,#2b6cb0);margin-top:8px;padding:5px 8px;background:var(--ork-alert-info-bg,#ebf8ff);border-radius:5px;border-left:3px solid var(--ork-alert-info-border,#90cdf4)">
				<i class="fas fa-calendar-alt" style="margin-right:5px"></i><span id="pk-emod-date-text"></span>
			</div>
			<p class="pk-emod-hint pk-emod-event-only" style="margin-top:8px">This event will be assigned to <strong><?= htmlspecialchars($park_name ?? '') ?></strong>. You'll set dates and details on the next page.</p>

			<!-- Calendar-item-only fields -->
			<div class="pk-emod-ci-only" style="display:none">
				<div class="pk-emod-field" style="margin-top:12px">
					<label class="pk-emod-check-label">
						<input type="checkbox" id="pk-ci-allday"> All day
					</label>
				</div>
				<div class="pk-emod-field" style="margin-top:6px">
					<label class="pk-emod-check-label" data-tip="Officer-only items are visible only to ORK admins and people serving as Monarch / Regent / PM / Champion of this kingdom or park.">
						<input type="checkbox" id="pk-ci-officer-only"> <i class="fas fa-shield-alt" style="margin:0 4px 0 2px;color:#805ad5"></i>Only Display to Officers
					</label>
				</div>
				<div class="pk-emod-field" style="margin-top:6px">
					<label class="pk-emod-check-label" data-tip="Locals-only items are visible only to ORK admins and to logged-in players whose home park (or kingdom, for kingdom-level items) matches.">
						<input type="checkbox" id="pk-ci-locals-only"> <i class="fas fa-map-marker-alt" style="margin:0 4px 0 2px;color:#0d9488"></i>Only Display to Local Park/Kingdom Players
					</label>
				</div>
				<div style="display:flex;gap:10px;margin-top:8px">
					<div class="pk-emod-field" style="flex:1">
						<label class="pk-emod-label">Start <span style="color:#e53e3e">*</span></label>
						<input type="text" class="pk-emod-input" id="pk-ci-start" autocomplete="off" placeholder="Select start…">
					</div>
					<div class="pk-emod-field" style="flex:1">
						<label class="pk-emod-label">End <span style="color:#e53e3e">*</span></label>
						<input type="text" class="pk-emod-input" id="pk-ci-end" autocomplete="off" placeholder="Select end…">
					</div>
				</div>
				<div class="pk-emod-field" style="margin-top:10px">
					<label class="pk-emod-label">Description</label>
					<textarea class="pk-emod-input" id="pk-ci-description" rows="3" placeholder="Optional details…"></textarea>
				</div>
				<div class="pk-emod-ci-note">
					<i class="fas fa-info-circle" style="margin-right:6px"></i>
					Calendar Items are lightweight. They do <strong>not</strong> support RSVPs, sign-ins, schedules, attendance, heraldry, pricing, or event authorization lists. Use an Amtgard Event for those.
				</div>
			</div>

			<div class="pk-emod-feedback" id="pk-emod-feedback" style="display:none"></div>
		</div>
		<div class="pk-emod-footer">
			<button class="pk-emod-btn-cancel" onclick="pkCloseEventModal()">Cancel</button>
			<button class="pk-emod-btn-cancel pk-emod-draft-btn" id="pk-emod-draft-btn" onclick="pkCreateEvent('draft')" disabled style="display:none;font-size:12px;">
				<i class="fas fa-eye-slash"></i> Save as Draft
			</button>
			<button class="pk-emod-btn-go" id="pk-emod-go-btn" onclick="pkCreateEvent()" disabled>
				<span id="pk-emod-go-label">Create Event</span> <i class="fas fa-arrow-right"></i>
			</button>
		</div>
	</div>
</div>

<?php endif; ?>

<!-- Event Preview Overlay (calendar quick-look) -->
<div class="evpv-overlay" id="evpv-overlay">
	<div class="evpv-box">
		<div class="evpv-header">
			<div class="evpv-header-meta">
				<span class="evpv-kind-pill" id="evpv-kind-pill"><i class="fas fa-flag"></i> <span id="evpv-kind-label">Amtgard Event</span></span>
				<span class="kn-draft-pill" id="evpv-draft-pill" style="display:none">DRAFT</span>
			</div>
			<button class="evpv-close" onclick="evpvClose()" aria-label="Close">&times;</button>
		</div>
		<div class="evpv-body">
			<div class="evpv-hero">
				<img class="evpv-heraldry" id="evpv-heraldry" alt="" loading="lazy">
				<div class="evpv-hero-text">
					<a class="evpv-name" id="evpv-name" href="#"></a>
					<div class="evpv-meta-row">
						<span class="evpv-meta-date"><i class="far fa-calendar-alt"></i> <span id="evpv-date"></span></span>
					</div>
					<div class="evpv-meta-row">
						<span class="evpv-meta-time" id="evpv-time-row"><i class="far fa-clock"></i> <span id="evpv-time"></span></span>
						<span class="evpv-meta-park" id="evpv-park-row" style="display:none"><i class="fas fa-tree"></i> <span id="evpv-park"></span></span>
					</div>
				</div>
			</div>
			<div class="evpv-description" id="evpv-description" style="display:none"></div>
			<div class="evpv-rsvp-row">
				<span class="kn-rsvp-wrap" id="evpv-rsvp"></span>
			</div>
		</div>
		<div class="evpv-footer">
			<button class="kn-emod-btn-cancel" onclick="evpvClose()">Close</button>
			<a class="evpv-cta" id="evpv-cta" href="#"><i class="fas fa-arrow-right"></i> See Full Details</a>
		</div>
	</div>
</div>

<!-- Calendar Item Detail Overlay (read/edit/delete) — available to all viewers -->
<div class="pk-ci-overlay" id="pk-ci-overlay">
	<div class="pk-ci-box">
		<div class="pk-ci-header">
			<h3><i class="fas fa-calendar-day" style="margin-right:8px;color:#64748b"></i>Calendar Item</h3>
			<button class="pk-emod-close" onclick="pkCloseCalendarItemOverlay()">&times;</button>
		</div>
		<div class="pk-ci-body">
			<div class="pk-ci-name" id="pk-ci-view-name"></div>
			<div class="pk-ci-meta">
				<i class="fas fa-clock" style="margin-right:6px;color:#a0aec0"></i>
				<span id="pk-ci-view-when"></span>
			</div>
			<div class="pk-ci-scope" id="pk-ci-view-scope"></div>
			<div class="pk-ci-description" id="pk-ci-view-desc"></div>
		</div>
		<div class="pk-ci-footer">
			<button class="pk-emod-btn-cancel" onclick="pkCloseCalendarItemOverlay()">Close</button>
			<button class="pk-emod-btn-cancel" id="pk-ci-edit-btn" style="display:none" onclick="pkEditCalendarItem()">
				<i class="fas fa-pencil-alt"></i> Edit
			</button>
			<button class="pk-emod-btn-cancel" id="pk-ci-delete-btn" style="display:none;color:#c53030;border-color:#fc8181" onclick="pkDeleteCalendarItem()">
				<i class="fas fa-trash"></i> Delete
			</button>
		</div>
	</div>
</div>

<?php if ($CanAdminPark ?? false): ?>
<!-- Add Player Modal -->
<div id="pk-addplayer-overlay">
	<div class="pk-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
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
					<div id="pk-addplayer-email-suggestion" class="esc-suggestion" role="alert">
						<i class="fas fa-magic"></i>
						<span class="esc-suggestion-text">Did you mean <strong></strong>?</span>
						<button type="button" class="esc-suggestion-use">Use it</button>
						<button type="button" class="esc-suggestion-dismiss" aria-label="Dismiss">&times;</button>
					</div>
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Username <span class="plr-req">*</span></label>
					<input type="text" id="pk-addplayer-username" placeholder="min. 4 characters" autocomplete="new-password">
				</div>
				<div class="plr-field">
					<label>Password</label>
					<input type="password" id="pk-addplayer-password" placeholder="optional" autocomplete="new-password">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Restrict Mundane Name Visibility</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="pk-addplayer-restricted" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="pk-addplayer-restricted" value="1"> Yes</label>
					</div>
					<small style="display:block;color:var(--ork-text-muted);margin-top:4px">Hides the player's real name from searches and public displays. Use for members who prefer their mundane identity kept private.</small>
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
		<div class="pk-modal-footer" style="justify-content:flex-end;gap:8px;">
			<button class="pk-btn pk-btn-secondary pk-selfreg-trigger" id="pk-selfreg-btn" onclick="pkOpenSelfRegModal()" style="margin-right:auto;">
				<i class="fas fa-qrcode"></i> Self Registration
			</button>
			<button class="pk-btn pk-btn-ghost" id="pk-addplayer-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-addplayer-submit">
				<i class="fas fa-user-plus"></i> Create Player
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<style>
/* ---- Instant tooltip (data-tip) ---- */
[data-tip] { position: relative; }
[data-tip]::before, [data-tip]::after {
	position: absolute; left: 50%; bottom: 100%; pointer-events: none;
	opacity: 0; transition: opacity 0.08s;
}
[data-tip]::after {
	content: attr(data-tip); transform: translateX(-50%) translateY(-4px);
	background: #2d3748; color: #fff; font-size: 11px; font-weight: 500;
	padding: 4px 9px; border-radius: 4px; white-space: nowrap; z-index: 900;
}
[data-tip]::before {
	content: ''; transform: translateX(-50%); margin-bottom: -4px;
	border: 5px solid transparent; border-top-color: #2d3748; z-index: 901;
}
[data-tip]:hover::before, [data-tip]:hover::after { opacity: 1; }

/* ---- Copy-link icon ---- */
.pk-copy-link {
	display: inline-flex; align-items: center; justify-content: center;
	margin-left: 5px; font-size: 11px; color: #a0aec0;
	cursor: pointer; opacity: 0; transition: opacity 0.15s;
	position: relative;
}
tr:hover .pk-copy-link { opacity: 1; }
.pk-copy-link:hover { color: #4299e1; }
.pk-copy-link.pk-copied::after {
	content: 'Copied!' !important; position: absolute; bottom: 100%; left: 50%;
	transform: translateX(-50%); background: #2d3748; color: #fff;
	font-size: 11px; padding: 3px 8px; border-radius: 4px; white-space: nowrap;
	pointer-events: none; opacity: 1; animation: pkCopiedFade 1.4s forwards;
}
@keyframes pkCopiedFade {
	0%,70% { opacity: 1; } 100% { opacity: 0; }
}
</style>

<?php if ($CanAdminPark ?? false): ?>
<!-- Self-Registration QR Modal -->
<style>
/* ---- Self-Registration QR Modal ---- */
#pk-selfreg-overlay {
	position: fixed; inset: 0;
	background: rgba(0,0,0,0.5);
	display: flex; align-items: center; justify-content: center;
	z-index: var(--z-modal, 1100);
	opacity: 0; pointer-events: none; visibility: hidden;
	transition: opacity 0.2s, visibility 0s 0.2s;
}
#pk-selfreg-overlay.pk-selfreg-open {
	opacity: 1; pointer-events: auto; visibility: visible;
	transition: opacity 0.2s, visibility 0s 0s;
}
#pk-selfreg-overlay .pk-modal-box {
	background: #fff; border-radius: 12px;
	box-shadow: 0 20px 60px rgba(0,0,0,0.3);
	max-height: 90vh; display: flex; flex-direction: column;
}
#pk-selfreg-overlay .pk-modal-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 16px 20px; border-bottom: 1px solid #e2e8f0; flex-shrink: 0;
}
#pk-selfreg-overlay .pk-modal-title {
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
#pk-selfreg-overlay .pk-modal-close-btn {
	background: none; border: none; font-size: 22px; color: #a0aec0;
	cursor: pointer; line-height: 1; padding: 0 4px;
}
#pk-selfreg-overlay .pk-modal-close-btn:hover { color: #4a5568; }
#pk-selfreg-overlay .pk-modal-body {
	padding: 20px; overflow-y: auto; flex: 1;
}
#pk-selfreg-overlay .pk-modal-footer {
	padding: 14px 20px; border-top: 1px solid #e2e8f0;
	display: flex; align-items: center; flex-shrink: 0;
}

/* Warning banner */
.pk-selfreg-warning {
	background: #fffbeb;
	border: 1px solid #f6e05e;
	color: #744210;
	border-radius: 8px;
	padding: 10px 14px;
	font-size: 13px;
	margin-bottom: 20px;
	text-align: left;
	line-height: 1.5;
}
.pk-selfreg-warning i {
	color: #d69e2e;
	margin-right: 6px;
}
.pk-selfreg-note {
	background: #ebf8ff;
	border: 1px solid #90cdf4;
	color: #2a4365;
	border-radius: 8px;
	padding: 10px 14px;
	font-size: 13px;
	margin-bottom: 20px;
	text-align: left;
	line-height: 1.5;
}
.pk-selfreg-note i {
	color: #3182ce;
	margin-right: 6px;
}

/* QR container + anti-copy shield */
#pk-selfreg-qr-wrap {
	position: relative;
	display: inline-block;
	margin: 0 auto 16px;
	background: #fff;
	padding: 10px;
	border-radius: 6px;
}
.pk-selfreg-qr-container {
	user-select: none;
	pointer-events: none;
	-webkit-user-select: none;
}
.pk-selfreg-qr-container canvas,
.pk-selfreg-qr-container img {
	display: block;
	margin: 0 auto;
}
.pk-selfreg-qr-shield {
	position: absolute; inset: 0;
	z-index: 2;
	background: transparent;
	cursor: default;
}

/* A18: Expired badge overlay */
.pk-selfreg-expired-badge {
	position: absolute;
	top: 50%; left: 50%;
	transform: translate(-50%, -50%);
	background: rgba(255,255,255,0.85);
	backdrop-filter: blur(4px);
	-webkit-backdrop-filter: blur(4px);
	color: #c53030;
	font-size: 20px;
	font-weight: 700;
	padding: 12px 28px;
	border-radius: 8px;
	z-index: 3;
	display: none;
}

/* Timer */
.pk-selfreg-timer-row {
	font-size: 18px;
	font-weight: 600;
	color: #2d3748;
	margin: 12px 0 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
}
.pk-selfreg-timer-row i {
	color: #a0aec0;
	font-size: 14px;
}
.pk-selfreg-timer-expired {
	color: #c53030;
}

/* Regenerate button */
#pk-selfreg-regen-btn {
	margin-top: 8px;
}
</style>
<div id="pk-selfreg-overlay">
	<div class="pk-modal-box" style="width:420px;max-width:calc(100vw - 40px);">
		<div class="pk-modal-header">
			<h3 class="pk-modal-title"><i class="fas fa-qrcode" style="margin-right:8px;color:#2c5282"></i>Self Registration</h3>
			<button class="pk-modal-close-btn" id="pk-selfreg-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body" style="text-align:center;">
			<div id="pk-selfreg-feedback" class="plr-feedback" style="display:none" aria-live="polite" role="status"></div>
			<div class="pk-selfreg-warning">
				<i class="fas fa-exclamation-triangle"></i>
				Do not distribute this self-registration QR code. This is designed to be used for in-person registration only.
			</div>
			<div class="pk-selfreg-note">
				<i class="fas fa-info-circle"></i>
				The new player will be assigned a Color credit for today to ensure they show in Active Player lists, but you can change this to any other class at a later time.
			</div>
			<div id="pk-selfreg-qr-wrap">
				<div id="pk-selfreg-qr" class="pk-selfreg-qr-container"></div>
				<div class="pk-selfreg-qr-shield" id="pk-selfreg-shield"></div>
				<div class="pk-selfreg-expired-badge" id="pk-selfreg-expired-badge">Expired</div>
			</div>
			<div class="pk-selfreg-timer-row" aria-live="polite">
				<i class="fas fa-clock"></i>
				<span id="pk-selfreg-timer">--:--</span>
			</div>
			<button class="pk-btn pk-btn-primary" id="pk-selfreg-regen-btn" style="display:none;">
				<i class="fas fa-sync-alt"></i> Regenerate
			</button>
		</div>
		<div class="pk-modal-footer" style="justify-content:center;">
			<button class="pk-btn pk-btn-ghost" id="pk-selfreg-cancel">Close</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Parknew: Edit Officers Modal
     ============================================= -->
<?php if (!empty($CanAdminPark)): ?>
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
			<h3 class="pk-modal-title" id="pk-addday-title"><i class="fas fa-calendar-plus" style="margin-right:8px;color:#2c5282" id="pk-addday-title-icon"></i><span id="pk-addday-title-text">Add Park Day</span></h3>
			<button class="pk-modal-close-btn" id="pk-addday-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pk-modal-body">
			<div id="pk-addday-feedback" class="pk-addday-feedback" style="display:none"></div>
				<input type="hidden" id="pk-addday-id" value="0" />

			<div class="pk-addday-field">
				<label>Purpose</label>
				<div class="pk-seg-group">
					<button type="button" class="pk-seg-btn pk-seg-active" data-group="purpose" data-val="park-day">Park Day</button>
					<button type="button" class="pk-seg-btn" data-group="purpose" data-val="fighter-practice">Fighter Practice</button>
					<button type="button" class="pk-seg-btn" data-group="purpose" data-val="arts-day">A&amp;S Day</button>
					<button type="button" class="pk-seg-btn" data-group="purpose" data-val="other">Other</button>
				</div>
				<input type="hidden" id="pk-addday-purpose" value="park-day" />
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

		<div id="pk-addday-delete-section" style="display:none;border-top:1px solid #e2e8f0;margin-top:16px;padding-top:16px;">
				<button class="pk-btn pk-btn-danger" id="pk-addday-delete" type="button" style="width:100%"><i class="fas fa-trash-alt"></i> Delete Park Day</button>
			</div>

		</div>
		<div class="pk-modal-footer">
			<button class="pk-btn pk-btn-ghost" id="pk-addday-cancel">Cancel</button>
			<button class="pk-btn pk-btn-primary" id="pk-addday-submit"><i class="fas fa-calendar-plus" id="pk-addday-submit-icon"></i> <span id="pk-addday-submit-text">Add Park Day</span></button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if (!empty($CanAdminPark)): ?>
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
				<div id="pk-heraldry-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:var(--ork-alert-danger-bg,#fff5f5);border:1px solid var(--ork-alert-danger-border,#fed7d7);border-radius:6px;font-size:13px;color:var(--ork-alert-danger-text,#c53030);text-align:left">
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
.pk-mp-toggle { display:flex; background:var(--ork-surface-hover); border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.pk-mp-toggle-btn {
	flex:1; padding:6px 10px; border:none; border-radius:4px; font-size:12px; font-weight:600;
	cursor:pointer; background:transparent; color:var(--ork-text-muted); transition:background 0.15s,color 0.15s;
}
.pk-mp-toggle-btn.pk-mp-active { background:#fff; color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.1); }

/* ===================================================================
   DARK MODE OVERRIDES — Parknew profile
   Activated by: html[data-theme="dark"]
   =================================================================== */
html[data-theme="dark"] .pk-stat-number { color: hsl(var(--pk-hue), var(--pk-sat), var(--ork-accent-lightness, 65%)); }
html[data-theme="dark"] .pk-stat-icon { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-stat-label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-card { background: var(--ork-card-bg, #2d3748) !important; border-color: var(--ork-border, #4a5568) !important; color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .pk-card-header { color: var(--ork-text); border-color: var(--ork-border); background: transparent; text-shadow: none; }
html[data-theme="dark"] .pk-sidebar { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-tab-nav { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-tab-nav li { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-tab-nav li.pk-tab-active { background: var(--ork-card-bg); color: hsl(var(--pk-hue), var(--pk-sat), var(--ork-accent-lightness, 65%)); border-color: var(--ork-border); border-bottom-color: hsl(var(--pk-hue), var(--pk-sat), var(--ork-accent-lightness, 65%)); }
html[data-theme="dark"] .pk-tab-nav li:hover:not(.pk-tab-active) { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .pk-tab-count { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-table { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-table th { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-color: var(--ork-border); text-shadow: none; }
html[data-theme="dark"] .pk-table td { color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-table tbody tr:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pk-day-card { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-day-time { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-day-name { color: var(--ork-text); }
html[data-theme="dark"] .pk-day-addr { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-modal-box { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .pk-modal-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
html[data-theme="dark"] .pk-modal-title { color: var(--ork-text); }
html[data-theme="dark"] .pk-modal-body { background: var(--ork-card-bg); color: var(--ork-text); }
html[data-theme="dark"] .pk-modal-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-modal-close-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-modal-close-btn:hover { color: var(--ork-text); background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pk-acct-field label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-acct-field input[type="text"],
html[data-theme="dark"] .pk-acct-field input[type="date"],
html[data-theme="dark"] .pk-acct-field input[type="number"],
html[data-theme="dark"] .pk-acct-field select,
html[data-theme="dark"] .pk-acct-field textarea { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .pk-mp-toggle { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .pk-mp-toggle-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-mp-toggle-btn.pk-mp-active { background: var(--ork-card-bg); color: var(--ork-link); }
html[data-theme="dark"] .pk-officer-item { border-color: var(--ork-border); }
html[data-theme="dark"] .pk-officer-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-officer-name { color: var(--ork-text); }
html[data-theme="dark"] #theme_container .pk-officer-name a { color: hsl(calc(var(--pk-hue) + 35), 65%, var(--ork-accent-mid-lightness, 58%)); }
html[data-theme="dark"] .pk-empty { color: var(--ork-text-muted); }
/* FullCalendar dark overrides */
html[data-theme="dark"] .fc-toolbar { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .fc-toolbar-title { color: var(--ork-text); }
html[data-theme="dark"] .fc-col-header { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .fc-col-header-cell { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .fc-daygrid-day { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .fc-daygrid-day-number { color: var(--ork-text-secondary); }
html[data-theme="dark"] .fc-day-today { background: var(--ork-bg-tertiary) !important; }
html[data-theme="dark"] .fc-button { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .fc-button:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .fc-button-primary:not(:disabled):active,
html[data-theme="dark"] .fc-button-primary:not(:disabled).fc-button-active { background: var(--ork-bg-tertiary); border-color: var(--ork-border); }

/* ---- Self-Registration QR modal — dark mode ---- */
html[data-theme="dark"] #pk-selfreg-overlay .pk-modal-box {
	background: var(--ork-card-bg);
	box-shadow: 0 20px 60px rgba(0,0,0,0.5);
}
html[data-theme="dark"] #pk-selfreg-overlay .pk-modal-header { border-bottom-color: var(--ork-border); background: var(--ork-bg-secondary); }
html[data-theme="dark"] #pk-selfreg-overlay .pk-modal-footer { border-top-color: var(--ork-border); background: var(--ork-bg-secondary); }
html[data-theme="dark"] #pk-selfreg-overlay .pk-modal-close-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] #pk-selfreg-overlay .pk-modal-close-btn:hover { color: var(--ork-text); }
html[data-theme="dark"] .pk-selfreg-warning {
	background: #744210;
	border-color: #975a16;
	color: #fbd38d;
}
html[data-theme="dark"] .pk-selfreg-warning i { color: #f6ad55; }
html[data-theme="dark"] .pk-selfreg-note {
	background: #1a365d;
	border-color: #2c5282;
	color: #90cdf4;
}
html[data-theme="dark"] .pk-selfreg-note i { color: #63b3ed; }
html[data-theme="dark"] .pk-selfreg-timer-row { color: var(--ork-text); }
html[data-theme="dark"] .pk-selfreg-timer-row i { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-selfreg-timer-expired { color: #fc8181 !important; }
html[data-theme="dark"] .pk-selfreg-expired-badge {
	background: rgba(45,55,72,0.85);
	color: #fc8181;
}

/* ---- Sign-in QR overlay (#pk-qr-overlay) — dark mode ---- */
html[data-theme="dark"] #pk-qr-overlay > div { background: var(--ork-card-bg) !important; box-shadow: 0 8px 32px rgba(0,0,0,0.5) !important; color: var(--ork-text); }
html[data-theme="dark"] #pk-qr-img { border-color: var(--ork-border) !important; background: #fff; }
html[data-theme="dark"] #pk-qr-expires { color: var(--ork-text-muted) !important; }

/* ---- Sign-in Link panel inputs/labels (within pk-att-* tabs) — dark mode ---- */
html[data-theme="dark"] #pk-att-link-url { background: var(--ork-input-bg) !important; border-color: var(--ork-input-border) !important; color: var(--ork-text); }
html[data-theme="dark"] #pk-att-link-expires { color: var(--ork-text-muted) !important; }
html[data-theme="dark"] #pk-att-links-wrap { border-top-color: var(--ork-border) !important; }
html[data-theme="dark"] #pk-att-links-toggle { color: var(--ork-text-secondary) !important; }
html[data-theme="dark"] #pk-att-links-loading,
html[data-theme="dark"] #pk-att-links-empty { color: var(--ork-text-muted) !important; }
html[data-theme="dark"] #pk-att-links-table thead tr,
html[data-theme="dark"] #pk-att-links-table th { color: var(--ork-text-muted) !important; }
html[data-theme="dark"] #pk-att-links-count { color: var(--ork-text-muted) !important; }

/* Copy-link / data-tip — dark mode */
html[data-theme="dark"] [data-tip]::after { background: #1a202c; color: #f7fafc; box-shadow: 0 0 0 1px var(--ork-border); }
html[data-theme="dark"] [data-tip]::before { border-top-color: #1a202c; }
html[data-theme="dark"] .pk-copy-link { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-copy-link:hover { color: #63b3ed; }
html[data-theme="dark"] .pk-copy-link.pk-copied::after { background: #1a202c; color: #f7fafc; box-shadow: 0 0 0 1px var(--ork-border); }

/* ============================================================
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
			<div class="plr-merge-scope-note">
				<i class="fas fa-info-circle"></i>
				Both players must be members of <strong><?= htmlspecialchars($park_name ?? 'this Park') ?></strong>. To merge players across Parks or Kingdoms, use the Kingdom profile&rsquo;s Merge Players tool.
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
			<p id="kn-confirm-message" style="margin:0;font-size:14px;color:var(--ork-text,#2d3748);line-height:1.6"></p>
		</div>
		<div class="kn-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="kn-btn-ghost" id="kn-confirm-cancel-btn">Cancel</button>
			<button class="kn-admin-save-btn kn-confirm-ok-btn" id="kn-confirm-ok-btn">Confirm</button>
		</div>
	</div>
</div>

<!-- Markdown Help Modal -->
<div id="pk-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-hashtag" style="margin-right:8px;color:#2b6cb0"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('pk-md-help-overlay').classList.remove('kn-open')">&times;</button>
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
						<label for="pk-editdetails-description" style="display:flex;align-items:center;gap:6px;">
							Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
							<button type="button" class="kn-md-help-btn" onclick="document.getElementById('pk-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
						</label>
						<textarea id="pk-editdetails-description" rows="4" placeholder="About this park..." data-original=""></textarea>
					</div>
					<div class="kn-admin-field">
						<label for="pk-editdetails-directions" style="display:flex;align-items:center;gap:6px;">
							Directions <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
							<button type="button" class="kn-md-help-btn" onclick="document.getElementById('pk-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
						</label>
						<textarea id="pk-editdetails-directions" rows="3" placeholder="How to find us..." data-original=""></textarea>
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
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/email-spell-checker.min.js"></script>
<?php if ($CanAdminPark ?? false): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<?php endif; ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
function pkCopyEventLink(el) {
	var url = el.getAttribute('data-url');
	navigator.clipboard.writeText(url).then(function() {
		el.classList.add('pk-copied');
		setTimeout(function() { el.classList.remove('pk-copied'); }, 1500);
	});
}
window.pkRecActiveFilter = 'open';
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
	if (settings.nTable.id !== 'pk-rec-table') return true;
	var filter = window.pkRecActiveFilter || 'all';
	if (filter === 'all') return true;
	var row = settings.aoData[dataIndex].nTr;
	var rowFilter = row ? row.getAttribute('data-filter') : '';
	if (filter === 'open') return rowFilter !== 'already';
	return rowFilter === filter;
});
$(function() {
	if ($('#pk-rec-table').length) {
		window.pkRecDT = $('#pk-rec-table').DataTable({
			order: [[4, 'desc']],
			columnDefs: [
				{ targets: [4], type: 'date' },
				<?php if (!empty($CanAdminPark)): ?>
				{ targets: [-1], orderable: false, searchable: false },
				<?php endif; ?>
			],
			pageLength: 25
		});
	}
});
window.pkRecPrint = function() { if (window.pkRecDT) window.recsExportPrint(window.pkRecDT, 'Award Recommendations \u2014 <?= htmlspecialchars(addslashes($park_name)) ?>'); };
window.pkRecCsv   = function() { if (window.pkRecDT) window.recsExportCsv(window.pkRecDT, 'recs-<?= preg_replace('/[^a-z0-9]+/i', '-', $park_name) ?>.csv'); };
initEmailSpellCheck('pk-addplayer-email', 'pk-addplayer-email-suggestion');
</script>

<?php if (!empty($IsLoggedIn)): ?>
<script>
window.OrkRsCfg = {
	uir:         '<?= UIR ?>',
	userId:      <?= (int)$this->__session->user_id ?>,
	userPersona: <?= json_encode($this->__session->persona ?? '') ?>,
	reload:      function() { location.reload(); }
};
</script>
<?php include __DIR__ . '/_recommendation_seconds_assets.tpl'; ?>
<?php endif; ?>

<!-- pk-banner-modal (ported from event) -->
<div class="pk-img-overlay pk-banner-modal" id="pk-banner-overlay">
	<div class="pk-img-modal" style="width:min(680px, 96vw)">
		<div class="pk-img-modal-header">
			<span class="pk-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Banner Image</span>
			<button class="pk-img-close-btn" id="pk-banner-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pk-img-modal-body" id="pk-banner-step-select">
			<p style="margin:0 0 12px;font-size:13px;color:#4a5568;line-height:1.5">
				Banners are full-bleed across the event header. Recommended size <strong>1800 &times; 240&nbsp;px</strong> (7.5:1). The shaded zones below are reserved for the logo, title, badges, and crumb — keep important art on the right side so it isn't covered by overlays.
			</p>

			<div class="pk-banner-wireframes">
				<figure class="pk-banner-wireframe pk-banner-wf-desktop">
					<figcaption><i class="fas fa-desktop"></i> Desktop &middot; 1800 &times; 240 px</figcaption>
					<svg viewBox="0 0 600 80" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true" focusable="false">
						<rect x="0" y="0" width="600" height="80" fill="#cbd5e0"/>
						<rect x="0" y="0" width="360" height="80" fill="url(#wfLeftFade)" opacity="0.55"/>
						<rect x="0" y="58" width="600" height="22" fill="url(#wfBottomFade)" opacity="0.55"/>
						<rect x="20" y="14" width="52" height="52" rx="3" fill="#a0aec0" stroke="#fff" stroke-width="1.2"/>
						<rect x="84" y="22" width="170" height="10" rx="1.5" fill="#fff"/>
						<rect x="84" y="38" width="52" height="7" rx="1.5" fill="#fff" opacity="0.85"/>
						<rect x="142" y="38" width="46" height="7" rx="1.5" fill="#fff" opacity="0.85"/>
						<rect x="84" y="62" width="120" height="5" rx="1" fill="#fff" opacity="0.7"/>
						<text x="470" y="44" text-anchor="middle" font-size="10" fill="#2d3748" font-weight="700">Safe zone for art</text>
						<text x="596" y="11" text-anchor="end" font-size="7" fill="#2d3748" opacity="0.55">1800px wide</text>
						<text x="4"   y="78" text-anchor="start" font-size="7" fill="#2d3748" opacity="0.55">240px tall</text>
						<defs>
							<linearGradient id="wfLeftFade" x1="0" y1="0" x2="1" y2="0">
								<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
							</linearGradient>
							<linearGradient id="wfBottomFade" x1="0" y1="1" x2="0" y2="0">
								<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
							</linearGradient>
						</defs>
					</svg>
				</figure>

				<figure class="pk-banner-wireframe pk-banner-wf-mobile">
					<figcaption><i class="fas fa-mobile-alt"></i> Mobile &middot; middle ~32%</figcaption>
					<svg viewBox="0 0 600 80" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" aria-hidden="true" focusable="false">
						<!-- Saved banner (1800 × 240) drawn at 7.5:1 to match the desktop wireframe -->
						<rect x="0"   y="0" width="204" height="80" fill="#e2e8f0"/>
						<rect x="396" y="0" width="204" height="80" fill="#e2e8f0"/>
						<rect x="204" y="0" width="192" height="80" fill="#cbd5e0"/>
						<rect x="204" y="0" width="192" height="80" fill="url(#wfMobileFade)" opacity="0.40"/>
						<!-- Tiny logo + title inside the middle band -->
						<rect x="216" y="22" width="36" height="36" rx="3" fill="#a0aec0" stroke="#fff" stroke-width="1.2"/>
						<rect x="262" y="30" width="120" height="9" rx="1.5" fill="#fff"/>
						<rect x="262" y="46" width="80"  height="6" rx="1.5" fill="#fff" opacity="0.85"/>
						<!-- Cropped labels on each flank -->
						<text x="100" y="46" text-anchor="middle" font-size="10" fill="#718096" font-weight="600">cropped</text>
						<text x="498" y="46" text-anchor="middle" font-size="10" fill="#718096" font-weight="600">cropped</text>
						<!-- Mobile-safe band markers -->
						<line x1="204" y1="0" x2="204" y2="80" stroke="#4299e1" stroke-width="1.5" stroke-dasharray="4 3" opacity="0.65"/>
						<line x1="396" y1="0" x2="396" y2="80" stroke="#4299e1" stroke-width="1.5" stroke-dasharray="4 3" opacity="0.65"/>
						<text x="596" y="11" text-anchor="end" font-size="7" fill="#2d3748" opacity="0.55">1800px wide</text>
						<text x="4"   y="78" text-anchor="start" font-size="7" fill="#2d3748" opacity="0.55">240px tall</text>
						<defs>
							<linearGradient id="wfMobileFade" x1="0" y1="0" x2="0" y2="1">
								<stop offset="0" stop-color="#000" stop-opacity="0"/>
								<stop offset="1" stop-color="#000" stop-opacity="0.5"/>
							</linearGradient>
						</defs>
					</svg>
				</figure>
			</div>
			<p class="pk-banner-wf-hint">
				<i class="fas fa-info-circle"></i> On phones, the banner is cropped to the middle third — keep your subject centred so it survives.
			</p>

			<div class="pk-banner-config">
				<label class="pk-banner-toggle">
					<input type="checkbox" id="pk-banner-show-logo" checked>
					<span>Show Park Heraldry on Left</span>
					<small>When off, the logo is hidden and the title/crumb shifts left.</small>
				</label>
				<label class="pk-banner-toggle">
					<input type="checkbox" id="pk-banner-vignette" checked>
					<span>Apply Vignette Effect</span>
					<small>Adds a soft radial blur and darkening only over the safe zones, so overlay text and pills stay legible.</small>
				</label>
			</div>

			<label class="pk-upload-area" for="pk-banner-file-input" style="margin-top:14px">
				<i class="fas fa-cloud-upload-alt pk-upload-icon"></i>
				Click to choose a banner image
				<small>JPG, PNG &middot; Max 1&nbsp;MB (larger images auto-resized)</small>
			</label>
			<input type="file" id="pk-banner-file-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png" style="display:none;" />
			<div id="pk-banner-resize-notice" style="font-size:12px;color:#888;min-height:16px;margin-top:6px;"></div>
			<div class="pk-img-form-error" id="pk-banner-error" style="display:none;"></div>

			<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:12px;flex-wrap:wrap">
				<?php if ($hasBanner): ?>
				<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
					<button class="pk-btn pk-btn-outline" id="pk-banner-adjust-btn" type="button" style="font-size:12px;padding:5px 14px"><i class="fas fa-arrows-alt"></i> Adjust Image Framing</button>
					<button class="pk-btn pk-btn-outline" id="pk-banner-save-config-btn" type="button" style="font-size:12px;padding:5px 14px"><i class="fas fa-save"></i> Save settings only</button>
				</div>
				<button class="pk-btn pk-btn-outline" id="pk-banner-remove-btn" type="button" style="font-size:12px;padding:5px 14px;border-color:#feb2b2;color:#e53e3e;"><i class="fas fa-trash"></i> Remove Banner</button>
				<?php else: ?>
				<span class="ec-field-hint">Upload a banner first to unlock the display toggles.</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="pk-img-modal-body" id="pk-banner-step-position" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#4a5568;line-height:1.5">
				Drag your image to set what shows through. The translucent shapes on top are where the logo, title, badges, and crumb will land — anything behind them will be partly covered.
			</p>
			<div class="pk-banner-position-wrap">
				<canvas id="pk-banner-position-canvas" class="pk-banner-position-canvas" width="1800" height="240"></canvas>
				<svg class="pk-banner-position-overlay" viewBox="0 0 1800 240" preserveAspectRatio="none" aria-hidden="true" focusable="false">
					<!-- Faint vignette tint for safe zones (matches the real .pk-hero-vignette) -->
					<rect x="0" y="0" width="900" height="240" fill="url(#posLeftFade)" opacity="0.40"/>
					<rect x="0" y="150" width="1800" height="90" fill="url(#posBottomFade)" opacity="0.35"/>
					<!-- Logo placeholder (~110px tall in real layout, vertically centered) -->
					<rect x="45" y="65" width="110" height="110" rx="8" fill="rgba(255,255,255,0.35)" stroke="#fff" stroke-width="2.5"/>
					<text x="100" y="128" text-anchor="middle" font-size="16" fill="#fff" font-weight="700" opacity="0.85">LOGO</text>
					<!-- Title bar -->
					<rect x="180" y="78" width="520" height="28" rx="3" fill="rgba(255,255,255,0.45)"/>
					<text x="190" y="99" font-size="20" font-weight="700" fill="#1a202c" opacity="0.78">Event Title goes here</text>
					<!-- Badges row -->
					<rect x="180" y="118" width="100" height="20" rx="10" fill="rgba(72,187,120,0.55)"/>
					<rect x="290" y="118" width="115" height="20" rx="10" fill="rgba(66,153,225,0.55)"/>
					<rect x="415" y="118" width="90"  height="20" rx="10" fill="rgba(159,122,234,0.55)"/>
					<!-- Crumb -->
					<rect x="180" y="150" width="260" height="12" rx="2" fill="rgba(255,255,255,0.40)"/>
					<!-- Mobile-safe band markers: middle ~32% of width -->
					<line x1="612"  y1="0" x2="612"  y2="240" stroke="#fff" stroke-width="2" stroke-dasharray="8 6" opacity="0.55"/>
					<line x1="1188" y1="0" x2="1188" y2="240" stroke="#fff" stroke-width="2" stroke-dasharray="8 6" opacity="0.55"/>
					<text x="900" y="16" text-anchor="middle" font-size="12" fill="#fff" font-weight="600" opacity="0.75">mobile shows this band</text>
					<defs>
						<linearGradient id="posLeftFade" x1="0" y1="0" x2="1" y2="0">
							<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
						</linearGradient>
						<linearGradient id="posBottomFade" x1="0" y1="1" x2="0" y2="0">
							<stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#000" stop-opacity="0"/>
						</linearGradient>
					</defs>
				</svg>
			</div>
			<p class="pk-banner-position-hint">
				<i class="fas fa-arrows-alt"></i>
				<span id="pk-banner-position-hint-text">Click and drag to position the image.</span>
			</p>
			<div class="pk-img-form-error" id="pk-banner-position-error" style="display:none;"></div>
			<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:12px">
				<button class="pk-btn pk-btn-outline" id="pk-banner-position-back-btn" type="button" style="font-size:12px;padding:5px 14px"><i class="fas fa-arrow-left"></i> Back</button>
				<button class="pk-btn pk-btn-white" id="pk-banner-position-confirm-btn" type="button" style="font-size:13px;padding:7px 18px">Use This View <i class="fas fa-check"></i></button>
			</div>
		</div>

		<div class="pk-img-modal-body" id="pk-banner-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#4a5568;">Uploading…</p>
		</div>
		<div class="pk-img-modal-body" id="pk-banner-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>

