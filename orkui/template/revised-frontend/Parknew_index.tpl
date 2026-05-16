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

	// --- Park Design (header, About, Milestones customization) -----------------------
	$aboutText      = (string)($parkInfo['AboutText']   ?? '');
	$ourHistoryText = (string)($parkInfo['OurHistory']  ?? '');
	// Fallback: if no customized about_text yet, keep showing the legacy description.
	if (trim($aboutText) === '') { $aboutText = $description; }

	$pkColorPrimary   = trim((string)($parkInfo['ColorPrimary']   ?? ''));
	$pkColorAccent    = trim((string)($parkInfo['ColorAccent']    ?? ''));
	$pkColorSecondary = trim((string)($parkInfo['ColorSecondary'] ?? ''));
	$pkOverlay        = strtolower(trim((string)($parkInfo['HeroOverlay'] ?? 'med')));
	if (!in_array($pkOverlay, ['low','med','high','vignette'], true)) $pkOverlay = 'med';
	$pkNameFont       = trim((string)($parkInfo['NameFont'] ?? ''));
	$pkMilestoneCfg   = [];
	if (!empty($parkInfo['MilestoneConfig'])) {
		$_mc = json_decode($parkInfo['MilestoneConfig'], true);
		if (is_array($_mc)) $pkMilestoneCfg = $_mc;
	}
	// Visibility default: ON for every known type unless config says 0.
	$pkMsVisible = function($type) use ($pkMilestoneCfg) {
		if (!array_key_exists($type, $pkMilestoneCfg)) return true;
		return !empty($pkMilestoneCfg[$type]);
	};
	$pkMsNewestFirst = !empty($pkMilestoneCfg['newest_first']);

	$pkAllMilestones  = is_array($Milestones ?? null) ? $Milestones : [];
	$pkVisibleMilestones = [];
	foreach ($pkAllMilestones as $_pkms) {
		$_t = $_pkms['Type'] ?? 'custom';
		if ($pkMsVisible($_t)) $pkVisibleMilestones[] = $_pkms;
	}
	if ($pkMsNewestFirst) $pkVisibleMilestones = array_reverse($pkVisibleMilestones);
	$pkHasMilestones = count($pkVisibleMilestones) > 0;

	// Hero font CSS — wrap font name in single quotes for CSS safety; bypass for viewer accessibility fonts is left to a future enhancement.
	$pkHeroFontCss = $pkNameFont !== '' ? ("'" . str_replace("'", '', $pkNameFont) . "'") : '';

	// Overlay opacity map for the heraldry background image on the hero.
	$pkOverlayOpacity = ['low' => 0.06, 'med' => 0.13, 'high' => 0.28, 'vignette' => 0.45][$pkOverlay] ?? 0.13;

	// --- Phase 2 customizations: tagline, social links, announcement ---
	$pkTagline           = trim((string)($parkInfo['Tagline'] ?? ''));
	$pkAnnouncement      = trim((string)($parkInfo['Announcement'] ?? ''));
	$pkAnnouncementUntil = trim((string)($parkInfo['AnnouncementUntil'] ?? ''));
	$pkShowAnnouncement  = ($pkAnnouncement !== '');
	if ($pkShowAnnouncement && $pkAnnouncementUntil !== '' && $pkAnnouncementUntil !== '0000-00-00') {
		$pkShowAnnouncement = (strtotime($pkAnnouncementUntil) >= strtotime(date('Y-m-d')));
	}
	$pkSocialRaw   = (string)($parkInfo['SocialLinks'] ?? '');
	$pkSocialLinks = [];
	if ($pkSocialRaw !== '') {
		$_sl = json_decode($pkSocialRaw, true);
		if (is_array($_sl)) {
			foreach ($_sl as $_k => $_v) {
				$_v = trim((string)$_v);
				if ($_v !== '' && preg_match('#^https?://#i', $_v)) {
					$pkSocialLinks[(string)$_k] = $_v;
				}
			}
		}
	}
	$pkSocialPlatforms = [
		'discord'   => ['label'=>'Discord',   'icon'=>'fab fa-discord',         'bg'=>'#5865f2', 'placeholder'=>'https://discord.gg/...'],
		'facebook'  => ['label'=>'Facebook',  'icon'=>'fab fa-facebook',        'bg'=>'#1877f2', 'placeholder'=>'https://facebook.com/...'],
		'instagram' => ['label'=>'Instagram', 'icon'=>'fab fa-instagram',       'bg'=>'#e4405f', 'placeholder'=>'https://instagram.com/...'],
		'threads'   => ['label'=>'Threads',   'icon'=>'fab fa-square-threads',  'bg'=>'#000000', 'placeholder'=>'https://threads.net/@...'],
		'bluesky'   => ['label'=>'Bluesky',   'icon'=>'fas fa-cloud',           'bg'=>'#1185fe', 'placeholder'=>'https://bsky.app/profile/...'],
		'twitter'   => ['label'=>'Twitter',   'icon'=>'fab fa-twitter',         'bg'=>'#1da1f2', 'placeholder'=>'https://twitter.com/...'],
		'youtube'   => ['label'=>'YouTube',   'icon'=>'fab fa-youtube',         'bg'=>'#ff0000', 'placeholder'=>'https://youtube.com/@...'],
		'amtwiki'   => ['label'=>'Amtwiki',   'icon'=>'fas fa-book',            'bg'=>'#4a5568', 'placeholder'=>'https://amtwiki.net/...'],
	];
	$pkHasSocial = false;
	foreach ($pkSocialPlatforms as $_slug => $_meta) {
		if (!empty($pkSocialLinks[$_slug])) { $pkHasSocial = true; break; }
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

	// Active Players: last sign-in within the past 6 months — matches the Players tab subtitle
	$activePlayersYear = count($playerList);
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<?php if ($pkNameFont !== ''): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= rawurlencode($pkNameFont) ?>&display=swap">
<?php endif; ?>

<style>
/* --- Park Design: hero customization ----------------------------------------- */
<?php if ($pkColorPrimary): ?>
.pk-hero {
	background-color: <?= htmlspecialchars($pkColorPrimary) ?> !important;
<?php if ($pkColorSecondary && $pkColorSecondary !== $pkColorPrimary): ?>
	background: linear-gradient(135deg, <?= htmlspecialchars($pkColorPrimary) ?>, <?= htmlspecialchars($pkColorSecondary) ?>) !important;
<?php endif; ?>
}
html[data-theme="dark"] .pk-hero {
	background-color: <?= htmlspecialchars($pkColorPrimary) ?> !important;
<?php if ($pkColorSecondary && $pkColorSecondary !== $pkColorPrimary): ?>
	background: linear-gradient(135deg, <?= htmlspecialchars($pkColorPrimary) ?>, <?= htmlspecialchars($pkColorSecondary) ?>) !important;
<?php endif; ?>
	filter: brightness(0.85);
}
<?php endif; ?>
<?php if ($pkColorAccent): ?>
:root { --pk-accent: <?= htmlspecialchars($pkColorAccent) ?>; }
.pk-tab-nav li.pk-tab-active { color: <?= htmlspecialchars($pkColorAccent) ?> !important; border-bottom-color: <?= htmlspecialchars($pkColorAccent) ?> !important; }
html[data-theme="dark"] .pk-tab-nav li.pk-tab-active { color: <?= htmlspecialchars($pkColorAccent) ?> !important; border-bottom-color: <?= htmlspecialchars($pkColorAccent) ?> !important; }
.pk-stat-card { position: relative; }
.pk-stat-card::before {
	content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
	background: <?= htmlspecialchars($pkColorAccent) ?>;
	border-top-left-radius: 8px; border-top-right-radius: 8px;
}
.pk-kingdom-link a i { color: <?= htmlspecialchars($pkColorAccent) ?>; }
<?php endif; ?>
.pk-hero-bg { opacity: <?= $pkOverlayOpacity ?> !important; }
<?php if ($pkOverlay === 'vignette'): ?>
.pk-hero-bg {
	-webkit-mask-image: radial-gradient(ellipse at center, rgba(0,0,0,0.95) 38%, rgba(0,0,0,0) 78%);
	        mask-image: radial-gradient(ellipse at center, rgba(0,0,0,0.95) 38%, rgba(0,0,0,0) 78%);
}
<?php endif; ?>
<?php if ($pkNameFont !== '' && $pkHeroFontCss !== ''): ?>
.pk-park-name { font-family: <?= $pkHeroFontCss ?>, 'Cinzel', serif !important; letter-spacing: 0.02em; }
<?php endif; ?>

/* --- Phase 2: Tagline --- */
.pk-tagline {
	font-style: italic; font-size: 13px; color: rgba(255,255,255,0.78);
	margin: 2px 0 4px; max-width: 640px;
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
html[data-theme="dark"] .pk-tagline { color: rgba(255,255,255,0.7); }

/* --- Phase 2: Announcement Banner --- */
.pk-announcement {
	display: flex; align-items: center; gap: 10px;
	background: #fffbeb; color: #92400e; border: 1px solid #fcd34d;
	border-radius: 8px; padding: 8px 14px; margin-bottom: 10px;
	font-size: 13px; line-height: 1.4;
}
.pk-announcement i.fa-bullhorn { color: #d97706; flex-shrink: 0; }
.pk-announcement-text { flex: 1; }
.pk-announcement-until { font-size: 11px; opacity: 0.8; font-style: italic; white-space: nowrap; }
html[data-theme="dark"] .pk-announcement {
	background: rgba(251,191,36,0.15); color: #fbbf24; border-color: rgba(251,191,36,0.35);
}
html[data-theme="dark"] .pk-announcement i.fa-bullhorn { color: #fbbf24; }

/* --- Phase 2: Connect (sidebar) --- */
.pk-connect-block { margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0; }
html[data-theme="dark"] .pk-connect-block { border-top-color: var(--ork-border); }
.pk-connect-subhead {
	display: flex; align-items: center; justify-content: space-between;
	font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
	color: #718096; font-weight: 600; margin-bottom: 8px;
}
html[data-theme="dark"] .pk-connect-subhead { color: var(--ork-text-muted); }
.pk-connect-subhead i { margin-right: 4px; opacity: 0.6; }
.pk-connect-edit {
	background: transparent; border: 0; cursor: pointer; color: #a0aec0; font-size: 11px; padding: 2px 6px; border-radius: 4px;
}
.pk-connect-edit:hover { background: rgba(49,130,206,0.08); color: #2b6cb0; }
html[data-theme="dark"] .pk-connect-edit:hover { background: var(--ork-bg-tertiary); color: var(--ork-link); }
.pk-connect-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.pk-connect-pill {
	width: 34px; height: 34px; border-radius: 50%;
	display: inline-flex; align-items: center; justify-content: center;
	color: #fff !important; text-decoration: none !important;
	font-size: 14px; line-height: 1;
	background: var(--pk-accent, #2b6cb0);
	transition: transform 0.12s, box-shadow 0.12s;
	position: relative;
}
.pk-connect-pill:hover { transform: scale(1.08); box-shadow: 0 3px 10px rgba(0,0,0,0.18); }
.pk-connect-pill[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.pk-connect-pill[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .pk-connect-pill[data-tip]::after {
	background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border);
}
.pk-connect-empty {
	display: inline-flex; font-size: 11px; color: #a0aec0; text-decoration: none;
	padding: 4px 8px; border: 1px dashed #cbd5e0; border-radius: 999px;
}
.pk-connect-empty:hover { color: #2b6cb0; border-color: #2b6cb0; }
html[data-theme="dark"] .pk-connect-empty { color: var(--ork-text-muted); border-color: var(--ork-border); }

/* --- Park Design: About markdown body + Our History + Milestones ------------- */
.pk-about-text { line-height: 1.6; }
.pk-about-text h1, .pk-about-text h2, .pk-about-text h3, .pk-about-text h4 {
	margin-top: 1.1em; margin-bottom: 0.4em;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.pk-about-section-head {
	display:flex; align-items:center; justify-content:space-between;
	gap:8px; margin-bottom:6px;
}
.pk-about-edit-btn {
	background: transparent; border: 1px solid transparent; color: #a0aec0;
	padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 12px;
	display:inline-flex; align-items:center; gap:4px;
	transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.pk-about-edit-btn:hover { background: rgba(49,130,206,0.08); color: #2b6cb0; border-color: rgba(49,130,206,0.25); }
html[data-theme="dark"] .pk-about-edit-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-about-edit-btn:hover { background: var(--ork-bg-tertiary); color: var(--ork-link); border-color: var(--ork-border); }
.pk-about-edit-btn[data-tip] { position: relative; }
.pk-about-edit-btn[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; font-style: italic; white-space: nowrap;
	padding: 4px 10px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0s; z-index: 500;
}
.pk-about-edit-btn[data-tip]:hover::after { opacity: 1; transition-delay: 0.4s; }
html[data-theme="dark"] .pk-about-edit-btn[data-tip]::after { background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border); }

/* Our History — visually distinct from About */
.pk-history-section { margin-top: 18px; padding-top: 18px; border-top: 1px dashed #e2e8f0; }
html[data-theme="dark"] .pk-history-section { border-top-color: var(--ork-border); }

/* Milestones timeline (mirrors player .pn-timeline-* with park prefix) */
.pk-timeline-section { margin-top: 22px; padding-top: 18px; border-top: 1px dashed #e2e8f0; }
html[data-theme="dark"] .pk-timeline-section { border-top-color: var(--ork-border); }
.pk-timeline-heading {
	font-size: 13px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.5px;
	margin: 0 0 14px 0;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	display: flex; align-items: center; gap: 8px;
}
html[data-theme="dark"] .pk-timeline-heading { color: var(--ork-text-secondary); }
.pk-timeline { position: relative; padding-left: 32px; }
.pk-timeline::before {
	content: ''; position: absolute; left: 11px; top: 4px; bottom: 4px; width: 2px;
	background: linear-gradient(to bottom, #cbd5e0, #cbd5e0 60%, transparent);
}
html[data-theme="dark"] .pk-timeline::before { background: linear-gradient(to bottom, var(--ork-border), var(--ork-border) 60%, transparent); }
.pk-timeline-row {
	position: relative; display: flex; align-items: center; gap: 12px;
	margin-bottom: 14px; min-height: 24px;
}
.pk-timeline-dot {
	position: absolute; left: -25px; top: 50%; transform: translateY(-50%);
	width: 24px; height: 24px; border-radius: 50%;
	background: #ebf8ff; color: #2b6cb0; display: flex; align-items: center; justify-content: center;
	font-size: 11px; border: 2px solid #fff; box-shadow: 0 0 0 1px #cbd5e0;
}
.pk-timeline-row.pk-ms-derived .pk-timeline-dot { background: #faf5ff; color: #6b46c1; box-shadow: 0 0 0 1px #d6bcfa; }
html[data-theme="dark"] .pk-timeline-dot { background: var(--ork-bg-tertiary); color: var(--ork-link); border-color: var(--ork-card-bg); box-shadow: 0 0 0 1px var(--ork-border); }
.pk-timeline-content {
	flex: 1; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
	font-size: 13px;
}
.pk-timeline-date { color: #718096; font-size: 11px; font-weight: 600; min-width: 80px; }
html[data-theme="dark"] .pk-timeline-date { color: var(--ork-text-muted); }
.pk-timeline-desc { color: #2d3748; }
html[data-theme="dark"] .pk-timeline-desc { color: var(--ork-text); }
.pk-timeline-row.pk-ms-derived .pk-timeline-desc { color: #553c9a; }
html[data-theme="dark"] .pk-timeline-row.pk-ms-derived .pk-timeline-desc { color: hsl(265, 60%, 75%); }
.pk-timeline-empty {
	font-size: 12px; color: #a0aec0; font-style: italic;
	padding: 10px 0;
}
</style>

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<?php if ($pkShowAnnouncement): ?>
<div class="pk-announcement">
	<i class="fas fa-bullhorn"></i>
	<span class="pk-announcement-text"><?= htmlspecialchars($pkAnnouncement) ?></span>
	<?php if ($pkAnnouncementUntil !== '' && $pkAnnouncementUntil !== '0000-00-00'): ?>
	<span class="pk-announcement-until">through <?= date('M j', strtotime($pkAnnouncementUntil)) ?></span>
	<?php endif; ?>
</div>
<?php endif; ?>
<div class="pk-hero<?= $parkIsInactive ? ' pk-hero--inactive' : '' ?>">
	<div class="pk-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="pk-hero-content">

		<!-- Heraldry -->
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

		<!-- Name / title / officers -->
		<div class="pk-hero-center">
			<div class="pk-kingdom-link">
				<a href="<?= UIR ?>Kingdom/profile/<?= $kingdom_id ?>">
					<i class="fas fa-crown"></i> <?= htmlspecialchars($kingdom_name) ?>
				</a>
			</div>
			<h1 class="pk-park-name"><?= htmlspecialchars($park_name) ?></h1>
			<?php if ($pkTagline !== ''): ?>
			<div class="pk-tagline"><?= htmlspecialchars($pkTagline) ?></div>
			<?php endif; ?>
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
					<button class="pk-btn pk-btn-outline" onclick="pkOpenDesignModal()">
						<i class="fas fa-palette"></i> Design
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
			<?php if ($pkHasSocial || !empty($CanAdminPark)): ?>
			<div class="pk-connect-block">
				<div class="pk-connect-subhead">
					<span><i class="fas fa-share-alt"></i> Connect</span>
					<?php if (!empty($CanAdminPark)): ?>
					<button class="pk-connect-edit" type="button" onclick="pkOpenDesignModal('about')" data-tip="Edit social links"><i class="fas fa-pencil-alt"></i></button>
					<?php endif; ?>
				</div>
				<?php if ($pkHasSocial): ?>
				<div class="pk-connect-pills">
					<?php foreach ($pkSocialPlatforms as $_slug => $_meta): ?>
						<?php if (!empty($pkSocialLinks[$_slug])): ?>
						<a href="<?= htmlspecialchars($pkSocialLinks[$_slug]) ?>" target="_blank" rel="noopener"
						   class="pk-connect-pill"
						   data-tip="<?= htmlspecialchars($_meta['label']) ?>">
							<i class="<?= htmlspecialchars($_meta['icon']) ?>"></i>
						</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<?php elseif (!empty($CanAdminPark)): ?>
				<a href="#" class="pk-connect-empty" onclick="event.preventDefault();pkOpenDesignModal('about')">+ Add</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
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
					<?php if (!empty($aboutText) || !empty($CanAdminPark)): ?>
					<div class="pk-about-section">
						<div class="pk-about-section-head">
							<div class="pk-about-label">About</div>
							<?php if (!empty($CanAdminPark)): ?>
							<button class="pk-about-edit-btn" type="button" onclick="pkOpenDesignModal('about')" data-tip="Edit About">
								<i class="fas fa-pencil-alt"></i> Edit
							</button>
							<?php endif; ?>
						</div>
						<?php if (!empty($aboutText)): ?>
						<div class="pk-about-text kn-description-body"><?= pk_markdown($aboutText) ?></div>
						<?php elseif (!empty($CanAdminPark)): ?>
						<div class="pk-empty" style="text-align:left;font-size:13px;color:#a0aec0">
							No About content yet. <a href="#" onclick="event.preventDefault();pkOpenDesignModal('about')">Add some</a> to introduce <?= htmlspecialchars($park_name) ?> to visitors.
						</div>
						<?php endif; ?>
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

				<?php if (!empty($ourHistoryText) || !empty($CanAdminPark)): ?>
				<div class="pk-about-section pk-history-section">
					<div class="pk-about-section-head">
						<div class="pk-about-label"><i class="fas fa-scroll" style="margin-right:6px;color:#a0aec0"></i>Our History</div>
						<?php if (!empty($CanAdminPark)): ?>
						<button class="pk-about-edit-btn" type="button" onclick="pkOpenDesignModal('about')" data-tip="Edit Our History">
							<i class="fas fa-pencil-alt"></i> Edit
						</button>
						<?php endif; ?>
					</div>
					<?php if (!empty($ourHistoryText)): ?>
					<div class="pk-about-text kn-description-body"><?= pk_markdown($ourHistoryText) ?></div>
					<?php elseif (!empty($CanAdminPark)): ?>
					<div class="pk-empty" style="text-align:left;font-size:13px;color:#a0aec0">
						Share the founding story, past officers, or notable moments. <a href="#" onclick="event.preventDefault();pkOpenDesignModal('about')">Add Our History</a>.
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

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

				<!-- Milestones timeline (derived + custom) -->
				<?php if ($pkHasMilestones || !empty($CanAdminPark)): ?>
				<div class="pk-about-section pk-timeline-section">
					<div class="pk-about-section-head">
						<h3 class="pk-timeline-heading"><i class="fas fa-stream"></i> Milestones</h3>
						<?php if (!empty($CanAdminPark)): ?>
						<button class="pk-about-edit-btn" type="button" onclick="pkOpenDesignModal('milestones')" data-tip="Manage milestones">
							<i class="fas fa-pencil-alt"></i> Manage
						</button>
						<?php endif; ?>
					</div>
					<?php if ($pkHasMilestones): ?>
					<div class="pk-timeline">
						<?php foreach ($pkVisibleMilestones as $_pkms): ?>
						<div class="pk-timeline-row<?= !empty($_pkms['IsDerived']) ? ' pk-ms-derived' : '' ?>">
							<div class="pk-timeline-dot">
								<i class="fas <?= htmlspecialchars(preg_replace('/[^a-z0-9-]/','', (string)($_pkms['Icon'] ?? 'fa-star')) ?: 'fa-star') ?>"></i>
							</div>
							<div class="pk-timeline-content">
								<span class="pk-timeline-date"><?= !empty($_pkms['MilestoneDate']) && $_pkms['MilestoneDate'] !== '0000-00-00' ? date('M j, Y', strtotime($_pkms['MilestoneDate'])) : '' ?></span>
								<span class="pk-timeline-desc"><?= htmlspecialchars((string)($_pkms['Description'] ?? '')) ?></span>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php elseif (!empty($CanAdminPark)): ?>
					<div class="pk-timeline-empty">
						No milestones yet. <a href="#" onclick="event.preventDefault();pkOpenDesignModal('milestones')">Add the first one</a> — founding date, elevation history, notable events.
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
								case 'fighter-practice': $purposeLabel = 'Fighter Practice'; $purposeCls = 'purpose-fighter'; $iconCls = 'icon-fighter'; $iconFa = 'fa-user-shield'; break;
								case 'arts-day':         $purposeLabel = 'A&S Day';       $purposeCls = 'purpose-arts';    $iconCls = 'icon-arts';    $iconFa = 'fa-palette';    break;
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
						<?php if (count($parkDayList) > 0): ?>
						<div id="pk-ev-filter-bar" style="display:flex;align-items:center;gap:5px;">
							<span style="font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.05em;margin-right:2px;">Show:</span>
							<button class="pk-filter-toggle pk-filter-on" data-filter="event">Events</button>
							<button class="pk-filter-toggle" data-filter="park-day">Park Days</button>
						</div>
						<?php endif; ?>
						<?php if ($CanAdminPark): ?>
						<button onclick="pkOpenEventModal()"style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
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
									<?php if (0 != $event['NextDate']): ?>
										<?= date('M. j, Y', strtotime($event['NextDate'])) ?>
										<?php if (strtotime($event['NextDate']) < time()): ?><span class='event-past-badge'>Past</span><?php endif; ?>
									<?php endif; ?>
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

<div class="pk-emod-overlay" id="pk-event-modal">
	<div class="pk-emod-box">
		<div class="pk-emod-header">
			<h3 class="kn-bare-heading"><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="pk-emod-close" onclick="pkCloseEventModal()">&times;</button>
		</div>
		<div class="pk-emod-body">
			<div class="pk-emod-field">
				<label class="pk-emod-label">Event Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="pk-emod-input" id="pk-event-name" autocomplete="off" placeholder="e.g. Summer Dragonmaster">
			</div>
			<div id="pk-emod-date-row" style="display:none;font-size:12px;color:var(--ork-alert-info-text,#2b6cb0);margin-top:8px;padding:5px 8px;background:var(--ork-alert-info-bg,#ebf8ff);border-radius:5px;border-left:3px solid var(--ork-alert-info-border,#90cdf4)">
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
.pk-mp-toggle { display:flex; background:#edf2f7; border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.pk-mp-toggle-btn {
	flex:1; padding:6px 10px; border:none; border-radius:4px; font-size:12px; font-weight:600;
	cursor:pointer; background:transparent; color:#718096; transition:background 0.15s,color 0.15s;
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
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
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

<?php if (!empty($CanAdminPark)): ?>
<!-- =============================================
     Park Design Modal
     ============================================= -->
<style>
/* --- Park Design Modal: scoped styles (pk-dm-*) ----------------------------- */
.pk-dm-overlay {
	position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 10000;
	display: none; align-items: center; justify-content: center; padding: 20px;
}
.pk-dm-overlay.pk-open { display: flex; }
.pk-dm-modal {
	background: #fff; border-radius: 10px; width: 720px; max-width: calc(100vw - 40px);
	max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
	box-shadow: 0 18px 50px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .pk-dm-modal { background: var(--ork-card-bg); color: var(--ork-text); }
.pk-dm-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 14px 18px; border-bottom: 1px solid #e2e8f0;
}
html[data-theme="dark"] .pk-dm-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
.pk-dm-title {
	margin: 0; font-size: 16px; font-weight: 700; color: #2d3748;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	display: inline-flex; align-items: center; gap: 8px;
}
html[data-theme="dark"] .pk-dm-title { color: var(--ork-text); }
.pk-dm-close {
	background: transparent; border: 0; font-size: 22px; cursor: pointer; color: #718096;
	width: 32px; height: 32px; border-radius: 6px;
}
.pk-dm-close:hover { background: #f7fafc; color: #2d3748; }
html[data-theme="dark"] .pk-dm-close { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-dm-close:hover { background: var(--ork-bg-tertiary); color: var(--ork-text); }
.pk-dm-tabs {
	display: flex; gap: 4px; padding: 8px 18px 0 18px; border-bottom: 1px solid #e2e8f0;
	background: #f7fafc;
}
html[data-theme="dark"] .pk-dm-tabs { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.pk-dm-tab {
	background: transparent; border: 1px solid transparent; border-bottom: none;
	padding: 8px 14px; font-size: 13px; font-weight: 600; color: #718096; cursor: pointer;
	border-radius: 6px 6px 0 0; display: inline-flex; align-items: center; gap: 6px;
}
.pk-dm-tab.pk-active {
	background: #fff; color: #2b6cb0; border-color: #e2e8f0; border-bottom: 1px solid #fff;
	margin-bottom: -1px;
}
html[data-theme="dark"] .pk-dm-tab { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-dm-tab.pk-active {
	background: var(--ork-card-bg); color: var(--ork-link); border-color: var(--ork-border);
	border-bottom-color: var(--ork-card-bg);
}
.pk-dm-body {
	padding: 18px; overflow-y: auto; flex: 1;
}
.pk-dm-panel { display: none; }
.pk-dm-panel.pk-active { display: block; }
.pk-dm-error {
	display: none; background: #fff5f5; border: 1px solid #fc8181; color: #9b2c2c;
	border-radius: 6px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px;
}
html[data-theme="dark"] .pk-dm-error { background: rgba(252,129,129,0.1); color: #fc8181; }
.pk-dm-field { margin-bottom: 14px; }
.pk-dm-field label {
	display: block; font-size: 12px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px;
}
html[data-theme="dark"] .pk-dm-field label { color: var(--ork-text-secondary); }
.pk-dm-field input[type="text"],
.pk-dm-field input[type="date"],
.pk-dm-field textarea,
.pk-dm-field select {
	width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 6px;
	font-size: 14px; color: #2d3748; box-sizing: border-box; background: #fff;
	font-family: inherit;
}
html[data-theme="dark"] .pk-dm-field input,
html[data-theme="dark"] .pk-dm-field textarea,
html[data-theme="dark"] .pk-dm-field select {
	background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text);
}
.pk-dm-field textarea { min-height: 140px; resize: vertical; line-height: 1.5; }
.pk-dm-hint {
	font-size: 12px; color: #718096; margin-top: 4px;
}
html[data-theme="dark"] .pk-dm-hint { color: var(--ork-text-muted); }
.pk-dm-footer {
	border-top: 1px solid #e2e8f0; padding: 12px 18px;
	display: flex; justify-content: flex-end; gap: 8px; background: #f7fafc;
}
html[data-theme="dark"] .pk-dm-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.pk-dm-btn {
	padding: 8px 16px; font-size: 13px; font-weight: 600; border-radius: 6px;
	border: 1px solid #cbd5e0; background: #fff; color: #2d3748; cursor: pointer;
	display: inline-flex; align-items: center; gap: 6px;
}
html[data-theme="dark"] .pk-dm-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text); }
.pk-dm-btn-primary { background: #3182ce; color: #fff; border-color: #3182ce; }
.pk-dm-btn-primary:hover { background: #2c5282; border-color: #2c5282; }
.pk-dm-btn:disabled { opacity: 0.55; cursor: not-allowed; }

/* --- Colors panel --- */
.pk-dm-preset-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; }
.pk-dm-swatch {
	height: 40px; border-radius: 6px; cursor: pointer; border: 2px solid transparent;
	box-shadow: 0 1px 2px rgba(0,0,0,0.1) inset;
}
.pk-dm-swatch.pk-selected { border-color: #2b6cb0; transform: scale(1.06); box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2b6cb0; }
html[data-theme="dark"] .pk-dm-swatch.pk-selected { box-shadow: 0 0 0 2px var(--ork-card-bg), 0 0 0 4px var(--ork-link); }
.pk-dm-color-row { display: flex; gap: 12px; flex-wrap: wrap; }
.pk-dm-color-col { flex: 1; min-width: 180px; }
.pk-dm-color-input { display: flex; align-items: center; gap: 6px; }
.pk-dm-color-input input[type="color"] {
	width: 38px; height: 36px; border: 1px solid #cbd5e0; border-radius: 6px;
	padding: 0; background: transparent; cursor: pointer;
}
.pk-dm-color-input input[type="text"] { flex: 1; }
.pk-dm-overlay-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.pk-dm-overlay-btn {
	flex: 1; min-width: 80px; padding: 8px 10px; font-size: 12px; font-weight: 600;
	border: 1px solid #cbd5e0; border-radius: 6px; background: #fff; color: #4a5568; cursor: pointer;
}
.pk-dm-overlay-btn.pk-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .pk-dm-overlay-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-dm-overlay-btn.pk-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }

/* --- Font picker --- */
.pk-dm-font-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.pk-dm-font-card {
	border: 1px solid #cbd5e0; border-radius: 8px; padding: 10px 8px; cursor: pointer;
	text-align: center; background: #fff;
}
.pk-dm-font-card.pk-active { border-color: #2b6cb0; background: #ebf8ff; box-shadow: 0 0 0 1px #2b6cb0; }
html[data-theme="dark"] .pk-dm-font-card { background: var(--ork-bg-tertiary); border-color: var(--ork-border); }
html[data-theme="dark"] .pk-dm-font-card.pk-active { background: rgba(43,108,176,0.15); border-color: var(--ork-link); box-shadow: 0 0 0 1px var(--ork-link); }
.pk-dm-font-sample { font-size: 19px; font-weight: 600; color: #2d3748; line-height: 1.2; margin-bottom: 4px; }
html[data-theme="dark"] .pk-dm-font-sample { color: var(--ork-text); }
.pk-dm-font-label { font-size: 11px; color: #718096; }
html[data-theme="dark"] .pk-dm-font-label { color: var(--ork-text-muted); }

/* --- About / Markdown editor --- */
.pk-dm-md-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.pk-dm-md-toggle { display: inline-flex; background: #edf2f7; border-radius: 6px; padding: 3px; gap: 3px; }
.pk-dm-md-toggle button {
	background: transparent; border: 0; padding: 5px 10px; font-size: 12px; font-weight: 600;
	cursor: pointer; border-radius: 4px; color: #718096;
}
.pk-dm-md-toggle button.pk-active { background: #fff; color: #2b6cb0; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
html[data-theme="dark"] .pk-dm-md-toggle { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pk-dm-md-toggle button.pk-active { background: var(--ork-card-bg); color: var(--ork-link); }
.pk-dm-md-quick { display: flex; flex-wrap: wrap; gap: 6px; }
.pk-dm-md-quick-btn {
	background: #fff; border: 1px solid #cbd5e0; color: #4a5568;
	padding: 5px 10px; border-radius: 14px; font-size: 11px; font-weight: 600;
	cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
}
.pk-dm-md-quick-btn:hover { background: #ebf8ff; color: #2b6cb0; border-color: #90cdf4; }
html[data-theme="dark"] .pk-dm-md-quick-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-dm-md-quick-btn:hover { background: var(--ork-bg-secondary); color: var(--ork-link); border-color: var(--ork-link); }
.pk-dm-md-preview {
	border: 1px solid #cbd5e0; border-radius: 6px; padding: 12px 14px;
	min-height: 140px; background: #fafafa; font-size: 14px; line-height: 1.55; color: #2d3748;
}
html[data-theme="dark"] .pk-dm-md-preview { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text); }
.pk-dm-md-preview h1, .pk-dm-md-preview h2, .pk-dm-md-preview h3, .pk-dm-md-preview h4 {
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	margin-top: 0.9em; margin-bottom: 0.3em;
}

/* --- Milestones panel --- */
.pk-dm-ms-toggles { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 14px; }
.pk-dm-ms-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #4a5568; }
html[data-theme="dark"] .pk-dm-ms-toggle { color: var(--ork-text-secondary); }
.pk-dm-ms-list { border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 12px; max-height: 200px; overflow-y: auto; }
html[data-theme="dark"] .pk-dm-ms-list { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
.pk-dm-ms-row {
	display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid #edf2f7;
	font-size: 13px;
}
html[data-theme="dark"] .pk-dm-ms-row { border-color: var(--ork-border); }
.pk-dm-ms-row:last-child { border-bottom: none; }
.pk-dm-ms-row > i { color: #2b6cb0; width: 18px; text-align: center; }
.pk-dm-ms-row .pk-dm-ms-desc { flex: 1; color: #2d3748; }
html[data-theme="dark"] .pk-dm-ms-row .pk-dm-ms-desc { color: var(--ork-text); }
.pk-dm-ms-row .pk-dm-ms-date { color: #718096; font-size: 11px; min-width: 90px; }
html[data-theme="dark"] .pk-dm-ms-row .pk-dm-ms-date { color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-dm-ms-row > i { color: var(--ork-link); }
html[data-theme="dark"] .pk-dm-ms-row button { color: #fc8181; }
html[data-theme="dark"] .pk-dm-ms-row button:hover { background: var(--ork-bg-tertiary); }
.pk-dm-ms-row button {
	background: transparent; border: 0; color: #e53e3e; cursor: pointer; padding: 4px 6px;
}
.pk-dm-ms-row button[data-tip] { position: relative; }
.pk-dm-ms-row button[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 4px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.pk-dm-ms-row button[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .pk-dm-ms-row button[data-tip]::after {
	background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border);
}
.pk-dm-ms-add { display: grid; grid-template-columns: 1fr 140px 90px; gap: 8px; align-items: end; }
@media (max-width: 600px) { .pk-dm-ms-add { grid-template-columns: 1fr; } }
.pk-dm-ms-icons {
	display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;
}
.pk-dm-ms-icon-opt {
	width: 28px; height: 28px; border: 1px solid #cbd5e0; border-radius: 6px;
	display: flex; align-items: center; justify-content: center; cursor: pointer;
	background: #fff; color: #4a5568;
}
.pk-dm-ms-icon-opt.pk-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .pk-dm-ms-icon-opt { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pk-dm-ms-icon-opt.pk-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }

/* Social links editor */
.pk-dm-social-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
@media (max-width: 640px) { .pk-dm-social-grid { grid-template-columns: 1fr; } }
.pk-dm-social-row { display: flex; align-items: center; gap: 6px; }
.pk-dm-social-icon {
	width: 28px; height: 28px; border-radius: 6px; color: #fff;
	display: inline-flex; align-items: center; justify-content: center;
	flex-shrink: 0; font-size: 13px;
}
.pk-dm-social-input { flex: 1; min-width: 0; }
.pk-dm-social-clear {
	width: 26px; height: 26px; border: 1px solid #e2e8f0; background: #fff;
	color: #718096; border-radius: 4px; cursor: pointer; flex-shrink: 0;
	display: inline-flex; align-items: center; justify-content: center; font-size: 11px;
	position: relative;
}
.pk-dm-social-clear:hover { color: #c53030; border-color: #fc8181; }
html[data-theme="dark"] .pk-dm-social-clear { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-muted); }
html[data-theme="dark"] .pk-dm-social-clear:hover { color: #fc8181; border-color: #fc8181; }
.pk-dm-social-clear[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 4px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.pk-dm-social-clear[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .pk-dm-social-clear[data-tip]::after { background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border); }
</style>

<div class="pk-dm-overlay" id="pk-dm-overlay">
	<div class="pk-dm-modal">
		<div class="pk-dm-header">
			<h3 class="pk-dm-title"><i class="fas fa-palette"></i>Design <?= htmlspecialchars($park_name) ?></h3>
			<button class="pk-dm-close" id="pk-dm-close" aria-label="Close">&times;</button>
		</div>
		<div class="pk-dm-tabs">
			<button class="pk-dm-tab pk-active" data-pktab-dm="header"><i class="fas fa-image"></i> Header</button>
			<button class="pk-dm-tab" data-pktab-dm="about"><i class="fas fa-scroll"></i> About</button>
			<button class="pk-dm-tab" data-pktab-dm="milestones"><i class="fas fa-stream"></i> Milestones</button>
		</div>
		<div class="pk-dm-body">
			<div class="pk-dm-error" id="pk-dm-error"></div>

			<!-- Header Panel -->
			<div class="pk-dm-panel pk-active" id="pk-dm-panel-header">
				<div class="pk-dm-hint" style="margin-bottom:12px"><i class="fas fa-moon" style="margin-right:6px"></i><strong>Dark mode viewers</strong> see your hero with a slight darkening filter so colors stay readable. Preview both themes with the moon icon in the site header before saving.</div>

				<div class="pk-dm-field">
					<label>Color Presets</label>
					<div class="pk-dm-preset-grid" id="pk-dm-presets">
						<div class="pk-dm-swatch" data-primary="#2c5282" data-accent="#4299e1" style="background:#2c5282"></div>
						<div class="pk-dm-swatch" data-primary="#276749" data-accent="#48bb78" style="background:#276749"></div>
						<div class="pk-dm-swatch" data-primary="#9b2c2c" data-accent="#fc8181" style="background:#9b2c2c"></div>
						<div class="pk-dm-swatch" data-primary="#553c9a" data-accent="#9f7aea" style="background:#553c9a"></div>
						<div class="pk-dm-swatch" data-primary="#975a16" data-accent="#ecc94b" style="background:#975a16"></div>
						<div class="pk-dm-swatch" data-primary="#2d3748" data-accent="#a0aec0" style="background:#2d3748"></div>
						<div class="pk-dm-swatch" data-primary="#285e61" data-accent="#38b2ac" style="background:#285e61"></div>
						<div class="pk-dm-swatch" data-primary="#744210" data-accent="#ed8936" style="background:#744210"></div>
					</div>
				</div>

				<div class="pk-dm-field">
					<label>Gradient Presets</label>
					<div class="pk-dm-preset-grid" id="pk-dm-gradient-presets">
						<div class="pk-dm-swatch" data-primary="#1a365d" data-accent="#4299e1" data-secondary="#553c9a" style="background:linear-gradient(135deg,#1a365d,#553c9a)"></div>
						<div class="pk-dm-swatch" data-primary="#1a4731" data-accent="#48bb78" data-secondary="#2c5282" style="background:linear-gradient(135deg,#1a4731,#2c5282)"></div>
						<div class="pk-dm-swatch" data-primary="#742a2a" data-accent="#fc8181" data-secondary="#975a16" style="background:linear-gradient(135deg,#742a2a,#975a16)"></div>
						<div class="pk-dm-swatch" data-primary="#44337a" data-accent="#d6bcfa" data-secondary="#97266d" style="background:linear-gradient(135deg,#44337a,#97266d)"></div>
						<div class="pk-dm-swatch" data-primary="#234e52" data-accent="#38b2ac" data-secondary="#276749" style="background:linear-gradient(135deg,#234e52,#276749)"></div>
						<div class="pk-dm-swatch" data-primary="#2c5282" data-accent="#4299e1" data-secondary="#285e61" style="background:linear-gradient(135deg,#2c5282,#285e61)"></div>
						<div class="pk-dm-swatch" data-primary="#744210" data-accent="#ecc94b" data-secondary="#9b2c2c" style="background:linear-gradient(135deg,#744210,#9b2c2c)"></div>
						<div class="pk-dm-swatch" data-primary="#1a202c" data-accent="#a0aec0" data-secondary="#2d3748" style="background:linear-gradient(135deg,#1a202c,#2d3748)"></div>
					</div>
				</div>

				<div class="pk-dm-field">
					<label>Custom Colors</label>
					<div class="pk-dm-color-row">
						<div class="pk-dm-color-col">
							<div class="pk-dm-hint" style="margin-bottom:4px">Primary (hero background)</div>
							<div class="pk-dm-color-input">
								<input type="color" id="pk-dm-color-primary" value="<?= htmlspecialchars($pkColorPrimary ?: '#2c5282') ?>" />
								<input type="text" id="pk-dm-color-primary-hex" value="<?= htmlspecialchars($pkColorPrimary ?: '#2c5282') ?>" maxlength="7" />
							</div>
						</div>
						<div class="pk-dm-color-col">
							<div class="pk-dm-hint" style="margin-bottom:4px">Accent (links &amp; tabs)</div>
							<div class="pk-dm-color-input">
								<input type="color" id="pk-dm-color-accent" value="<?= htmlspecialchars($pkColorAccent ?: '#4299e1') ?>" />
								<input type="text" id="pk-dm-color-accent-hex" value="<?= htmlspecialchars($pkColorAccent ?: '#4299e1') ?>" maxlength="7" />
							</div>
						</div>
					</div>
				</div>

				<div class="pk-dm-field">
					<label>Gradient (Optional)</label>
					<div class="pk-dm-color-row">
						<div class="pk-dm-color-col">
							<div class="pk-dm-hint" style="margin-bottom:4px">Secondary color</div>
							<div class="pk-dm-color-input">
								<input type="color" id="pk-dm-color-secondary" value="<?= htmlspecialchars($pkColorSecondary ?: ($pkColorPrimary ?: '#2c5282')) ?>" />
								<input type="text" id="pk-dm-color-secondary-hex" value="<?= htmlspecialchars($pkColorSecondary) ?>" maxlength="7" placeholder="None" />
							</div>
						</div>
						<div class="pk-dm-color-col" style="display:flex;align-items:center;padding-top:18px">
							<label style="text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;color:#4a5568;font-size:13px;margin-bottom:0">
								<input type="checkbox" id="pk-dm-gradient-enabled" <?= $pkColorSecondary !== '' ? 'checked' : '' ?> />
								Enable gradient
							</label>
						</div>
					</div>
				</div>

				<div class="pk-dm-field">
					<label>Heraldry Overlay Strength</label>
					<div class="pk-dm-hint" style="margin-bottom:6px">Controls how much the park heraldry shows through the hero background.</div>
					<div class="pk-dm-overlay-btns">
						<button type="button" class="pk-dm-overlay-btn<?= $pkOverlay === 'low' ? ' pk-active' : '' ?>" data-overlay="low">Low</button>
						<button type="button" class="pk-dm-overlay-btn<?= $pkOverlay === 'med' ? ' pk-active' : '' ?>" data-overlay="med">Medium</button>
						<button type="button" class="pk-dm-overlay-btn<?= $pkOverlay === 'high' ? ' pk-active' : '' ?>" data-overlay="high">High</button>
						<button type="button" class="pk-dm-overlay-btn<?= $pkOverlay === 'vignette' ? ' pk-active' : '' ?>" data-overlay="vignette">Vignette</button>
					</div>
					<input type="hidden" id="pk-dm-hero-overlay" value="<?= htmlspecialchars($pkOverlay) ?>" />
				</div>

				<div class="pk-dm-field">
					<label>Name Font</label>
					<div class="pk-dm-hint" style="margin-bottom:6px">A decorative font for the park name in the hero. Viewers with accessibility fonts enabled will see their preferred font instead.</div>
					<div class="pk-dm-font-picker" id="pk-dm-font-picker"></div>
				</div>

				<div class="pk-dm-field">
					<label>Tagline (optional)</label>
					<div class="pk-dm-hint" style="margin-bottom:6px">A short motto or descriptor shown under the park name.</div>
					<input type="text" id="pk-dm-tagline" maxlength="160" value="<?= htmlspecialchars($pkTagline) ?>" placeholder="e.g. Where steel meets song." />
				</div>

				<div class="pk-dm-field">
					<label>Announcement</label>
					<div class="pk-dm-hint" style="margin-bottom:6px">Pinned message shown above the hero. Leave "Show until" blank for indefinite. Plain text only.</div>
					<textarea id="pk-dm-announcement" maxlength="280" rows="3" placeholder="e.g. Practice cancelled Saturday — see you at the campout!"><?= htmlspecialchars($pkAnnouncement) ?></textarea>
					<div class="pk-dm-hint" id="pk-dm-announcement-count" style="text-align:right;margin-top:2px;display:none"></div>
					<div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
						<label for="pk-dm-announcement-until" style="text-transform:none;letter-spacing:0;font-weight:500;color:#4a5568;font-size:13px;margin-bottom:0">Show until (optional):</label>
						<input type="date" id="pk-dm-announcement-until" value="<?= htmlspecialchars($pkAnnouncementUntil && $pkAnnouncementUntil !== '0000-00-00' ? $pkAnnouncementUntil : '') ?>" style="max-width:200px" />
					</div>
				</div>
			</div>

			<!-- About Panel -->
			<div class="pk-dm-panel" id="pk-dm-panel-about">
				<div class="pk-dm-hint" style="margin-bottom:14px"><i class="fas fa-info-circle" style="margin-right:6px"></i>Both fields support <strong>Markdown</strong>. Use <em>About</em> for a current snapshot of the park; use <em>Our History</em> for the founding story, past elevations, and notable moments.</div>

				<div class="pk-dm-field">
					<div class="pk-dm-md-toolbar">
						<label style="margin-bottom:0">About <?= htmlspecialchars($park_name) ?></label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="pk-dm-md-toggle">
								<button type="button" class="pk-active" data-pkmd-target="edit" data-pkmd-field="about">Write</button>
								<button type="button" data-pkmd-target="preview" data-pkmd-field="about">Preview</button>
							</div>
							<div class="pk-dm-md-quick">
								<button type="button" class="pk-dm-md-quick-btn" data-pkquick="newbies" data-pkfield="about"><i class="fas fa-hand-sparkles"></i> New Player Welcome</button>
								<button type="button" class="pk-dm-md-quick-btn" data-pkquick="vibe" data-pkfield="about"><i class="fas fa-fire"></i> Park Vibe</button>
								<button type="button" class="pk-dm-md-quick-btn" data-pkquick="findus" data-pkfield="about"><i class="fas fa-map-marker-alt"></i> Find Us</button>
							</div>
						</div>
					</div>
					<textarea id="pk-dm-about-text" maxlength="10000" placeholder="Welcome to the park... (Markdown supported)"><?= htmlspecialchars($aboutText) ?></textarea>
					<div class="pk-dm-md-preview" id="pk-dm-about-preview" style="display:none"></div>
				</div>

				<div class="pk-dm-field">
					<div class="pk-dm-md-toolbar">
						<label style="margin-bottom:0">Our History</label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="pk-dm-md-toggle">
								<button type="button" class="pk-active" data-pkmd-target="edit" data-pkmd-field="history">Write</button>
								<button type="button" data-pkmd-target="preview" data-pkmd-field="history">Preview</button>
							</div>
							<div class="pk-dm-md-quick">
								<button type="button" class="pk-dm-md-quick-btn" data-pkquick="founding" data-pkfield="history"><i class="fas fa-flag"></i> Founding</button>
								<button type="button" class="pk-dm-md-quick-btn" data-pkquick="elevation" data-pkfield="history"><i class="fas fa-chess-rook"></i> Elevations</button>
								<button type="button" class="pk-dm-md-quick-btn" data-pkquick="pastofficers" data-pkfield="history"><i class="fas fa-crown"></i> Past Officers</button>
							</div>
						</div>
					</div>
					<textarea id="pk-dm-history-text" maxlength="10000" placeholder="The park was founded in... (Markdown supported)"><?= htmlspecialchars($ourHistoryText) ?></textarea>
					<div class="pk-dm-md-preview" id="pk-dm-history-preview" style="display:none"></div>
				</div>

				<div class="pk-dm-field">
					<label>Social Links</label>
					<div class="pk-dm-hint" style="margin-bottom:8px">Add the URL for each platform your park uses. Leave blank to omit.</div>
					<div class="pk-dm-social-grid">
						<?php foreach ($pkSocialPlatforms as $_slug => $_meta): ?>
						<div class="pk-dm-social-row">
							<span class="pk-dm-social-icon" style="background: <?= htmlspecialchars($_meta['bg']) ?>;"><i class="<?= htmlspecialchars($_meta['icon']) ?>"></i></span>
							<input type="url" class="pk-dm-social-input" data-pksocial="<?= htmlspecialchars($_slug) ?>"
							       placeholder="<?= htmlspecialchars($_meta['placeholder']) ?>"
							       maxlength="500"
							       value="<?= htmlspecialchars($pkSocialLinks[$_slug] ?? '') ?>" />
							<button type="button" class="pk-dm-social-clear" data-pksocial-clear="<?= htmlspecialchars($_slug) ?>" data-tip="Clear" aria-label="Clear"><i class="fas fa-times"></i></button>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- Milestones Panel -->
			<div class="pk-dm-panel" id="pk-dm-panel-milestones">
				<div class="pk-dm-hint" style="margin-bottom:10px">Milestones appear on the About tab in date order. Some are derived from attendance data (first sign-in, count thresholds); the rest are custom entries you add below.</div>

				<div class="pk-dm-field">
					<label>Visible Milestone Types</label>
					<div class="pk-dm-ms-toggles" id="pk-dm-ms-toggles">
						<label class="pk-dm-ms-toggle"><input type="checkbox" data-pkms-type="first_attendance" <?= $pkMsVisible('first_attendance') ? 'checked' : '' ?> /> <i class="fas fa-door-open"></i> First Attendance</label>
						<label class="pk-dm-ms-toggle"><input type="checkbox" data-pkms-type="attendance_count" <?= $pkMsVisible('attendance_count') ? 'checked' : '' ?> /> <i class="fas fa-clipboard-list"></i> Attendance Crossings</label>
						<label class="pk-dm-ms-toggle"><input type="checkbox" data-pkms-type="distinct_members" <?= $pkMsVisible('distinct_members') ? 'checked' : '' ?> /> <i class="fas fa-users"></i> Member Crossings</label>
						<label class="pk-dm-ms-toggle"><input type="checkbox" data-pkms-type="custom" <?= $pkMsVisible('custom') ? 'checked' : '' ?> /> <i class="fas fa-pen"></i> Custom Milestones</label>
					</div>
					<label class="pk-dm-ms-toggle" style="margin-top:4px">
						<input type="checkbox" id="pk-dm-ms-newest-first" <?= $pkMsNewestFirst ? 'checked' : '' ?> />
						Show newest first
					</label>
				</div>

				<div class="pk-dm-field">
					<label>Custom Milestones</label>
					<div class="pk-dm-ms-list" id="pk-dm-ms-list"></div>
					<div class="pk-dm-ms-add">
						<div>
							<input type="text" id="pk-dm-ms-add-desc" placeholder="What happened?" maxlength="500" />
						</div>
						<div>
							<input type="date" id="pk-dm-ms-add-date" />
						</div>
						<div>
							<button type="button" class="pk-dm-btn pk-dm-btn-primary" id="pk-dm-ms-add-btn" style="width:100%"><i class="fas fa-plus"></i> Add</button>
						</div>
					</div>
					<div class="pk-dm-ms-icons" id="pk-dm-ms-icons" style="margin-top:8px">
						<?php $_pkIcons = ['fa-star','fa-trophy','fa-flag','fa-chess-rook','fa-crown','fa-medal','fa-shield-alt','fa-fire','fa-bolt','fa-scroll','fa-campground','fa-map-marker-alt','fa-users','fa-dragon','fa-hammer','fa-heart']; ?>
						<?php foreach ($_pkIcons as $_ic): ?>
						<div class="pk-dm-ms-icon-opt<?= $_ic === 'fa-star' ? ' pk-active' : '' ?>" data-icon="<?= htmlspecialchars($_ic) ?>"><i class="fas <?= htmlspecialchars($_ic) ?>"></i></div>
						<?php endforeach; ?>
					</div>
					<div class="pk-dm-hint" id="pk-dm-ms-add-err" style="color:#c53030;display:none;margin-top:6px"></div>
				</div>
			</div>
		</div>
		<div class="pk-dm-footer">
			<button class="pk-dm-btn" id="pk-dm-cancel">Cancel</button>
			<button class="pk-dm-btn pk-dm-btn-primary" id="pk-dm-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>

<!-- Markdown libs (only loaded for editors — small CDN bundles) -->
<script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<script>
(function() {
	var PARK_ID = <?= (int)$park_id ?>;
	var BASE_UIR = '<?= UIR ?>';
	var PK_FONTS = [
		{ key:'', label:'Default', family:'inherit' },
		{ key:'Cinzel', label:'Cinzel', family:'Cinzel' },
		{ key:'Cinzel Decorative', label:'Cinzel Deco', family:"'Cinzel Decorative'" },
		{ key:'IM Fell English', label:'IM Fell English', family:"'IM Fell English'" },
		{ key:'UnifrakturMaguntia', label:'Unifraktur', family:'UnifrakturMaguntia' },
		{ key:'Metamorphous', label:'Metamorphous', family:'Metamorphous' },
		{ key:'Uncial Antiqua', label:'Uncial Antiqua', family:"'Uncial Antiqua'" },
		{ key:'Pirata One', label:'Pirata One', family:"'Pirata One'" },
		{ key:'Almendra', label:'Almendra', family:'Almendra' },
		{ key:'Pinyon Script', label:'Pinyon Script', family:"'Pinyon Script'" },
		{ key:'Great Vibes', label:'Great Vibes', family:"'Great Vibes'" }
	];
	var PK_QUICKS = {
		newbies: 'New players welcome! Loaner gear is always on hand and our experienced fighters love teaching the ropes. Just show up — no experience needed.',
		vibe:    "## The Vibe\n\nFamily-friendly, hard-hitting, and warm. Whether you swing a sword, paint a banner, or sing a song around the fire, there's a place for you here.",
		findus:  "## Find Us\n\n- **Address:** _add the address_\n- **Parking:** _quick parking notes here_\n- **Look for:** _identifying landmark / banner_",
		founding:"## Founding\n\nThe park was founded in **YYYY** by a small group meeting at _location_. It was first chartered as an **Outpost** under the Kingdom of _kingdom name_.",
		elevation:"## Elevation History\n\n- **YYYY** — Chartered as an Outpost\n- **YYYY** — Elevated to a Shire\n- **YYYY** — Elevated to a Barony\n_(Edit dates and lines as appropriate)_",
		pastofficers:"## Past Monarchs & Regents\n\n- **YYYY–YYYY** — _Persona_ (Monarch), _Persona_ (Regent)\n- **YYYY–YYYY** — _Persona_ (Monarch), _Persona_ (Regent)"
	};
	var INITIAL_CUSTOM_MS = <?php
		$customOnly = array_values(array_filter($pkAllMilestones, function($m){ return empty($m['IsDerived']); }));
		echo json_encode(array_map(function($m){
			return [
				'MilestoneId'   => (int)$m['MilestoneId'],
				'Icon'          => $m['Icon'],
				'Description'   => $m['Description'],
				'MilestoneDate' => $m['MilestoneDate'],
			];
		}, $customOnly));
	?>;
	var pkSelectedFont = <?= json_encode($pkNameFont) ?>;
	var pkSelectedIcon = 'fa-star';
	var customMs = INITIAL_CUSTOM_MS.slice();

	function gid(id) { return document.getElementById(id); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

	// --- Open / close ---
	window.pkOpenDesignModal = function(panel) {
		gid('pk-dm-overlay').classList.add('pk-open');
		document.body.style.overflow = 'hidden';
		if (panel) pkSwitchDmPanel(panel);
		renderCustomMsList();
	};
	function close() {
		gid('pk-dm-overlay').classList.remove('pk-open');
		document.body.style.overflow = '';
	}
	gid('pk-dm-close').addEventListener('click', close);
	gid('pk-dm-cancel').addEventListener('click', close);
	gid('pk-dm-overlay').addEventListener('click', function(e) {
		if (e.target === this) close();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('pk-dm-overlay').classList.contains('pk-open')) close();
	});

	// --- Tabs ---
	function pkSwitchDmPanel(name) {
		document.querySelectorAll('.pk-dm-tab').forEach(function(t) { t.classList.remove('pk-active'); });
		document.querySelectorAll('.pk-dm-panel').forEach(function(p) { p.classList.remove('pk-active'); });
		var tab = document.querySelector('.pk-dm-tab[data-pktab-dm="' + name + '"]');
		var panel = gid('pk-dm-panel-' + name);
		if (tab) tab.classList.add('pk-active');
		if (panel) panel.classList.add('pk-active');
	}
	document.querySelectorAll('.pk-dm-tab').forEach(function(t) {
		t.addEventListener('click', function() { pkSwitchDmPanel(t.dataset.pktabDm); });
	});

	// --- Colors panel ---
	var swatches = document.querySelectorAll('.pk-dm-swatch');
	swatches.forEach(function(sw) {
		sw.addEventListener('click', function() {
			swatches.forEach(function(s) { s.classList.remove('pk-selected'); });
			sw.classList.add('pk-selected');
			gid('pk-dm-color-primary').value     = sw.dataset.primary;
			gid('pk-dm-color-primary-hex').value = sw.dataset.primary;
			gid('pk-dm-color-accent').value      = sw.dataset.accent;
			gid('pk-dm-color-accent-hex').value  = sw.dataset.accent;
			if (sw.dataset.secondary) {
				gid('pk-dm-color-secondary').value     = sw.dataset.secondary;
				gid('pk-dm-color-secondary-hex').value = sw.dataset.secondary;
				gid('pk-dm-gradient-enabled').checked  = true;
			} else {
				gid('pk-dm-color-secondary-hex').value = '';
				gid('pk-dm-gradient-enabled').checked  = false;
			}
		});
	});
	function syncHex(colorId, hexId) {
		gid(colorId).addEventListener('input', function() { gid(hexId).value = this.value; });
		gid(hexId).addEventListener('input', function() {
			if (/^#[0-9a-f]{6}$/i.test(this.value)) { gid(colorId).value = this.value; }
		});
	}
	syncHex('pk-dm-color-primary',   'pk-dm-color-primary-hex');
	syncHex('pk-dm-color-accent',    'pk-dm-color-accent-hex');
	syncHex('pk-dm-color-secondary', 'pk-dm-color-secondary-hex');

	// --- Overlay buttons ---
	document.querySelectorAll('.pk-dm-overlay-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.pk-dm-overlay-btn').forEach(function(b) { b.classList.remove('pk-active'); });
			btn.classList.add('pk-active');
			gid('pk-dm-hero-overlay').value = btn.dataset.overlay;
		});
	});

	// --- Font picker ---
	function pkLoadFont(key) {
		if (!key) return;
		if (document.querySelector('link[data-pk-font="' + key + '"]')) return;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = 'https://fonts.googleapis.com/css2?family=' + key.replace(/ /g, '+') + '&display=swap';
		link.setAttribute('data-pk-font', key);
		document.head.appendChild(link);
	}
	function pkRenderFontPicker() {
		var container = gid('pk-dm-font-picker');
		var sample = <?= json_encode($park_name) ?>;
		var html = '';
		for (var i = 0; i < PK_FONTS.length; i++) {
			var f = PK_FONTS[i];
			var active = f.key === pkSelectedFont;
			html += '<div class="pk-dm-font-card' + (active ? ' pk-active' : '') + '" data-font-key="' + esc(f.key) + '">'
				 +    '<div class="pk-dm-font-sample" style="font-family:' + f.family + '">' + esc(sample) + '</div>'
				 +    '<div class="pk-dm-font-label">' + esc(f.label) + '</div>'
				 + '</div>';
			pkLoadFont(f.key);
		}
		container.innerHTML = html;
		container.addEventListener('click', function(e) {
			var card = e.target.closest('.pk-dm-font-card');
			if (!card) return;
			pkSelectedFont = card.dataset.fontKey;
			container.querySelectorAll('.pk-dm-font-card').forEach(function(c) {
				c.classList.toggle('pk-active', c === card);
			});
		});
	}
	pkRenderFontPicker();

	// --- Markdown preview toggles ---
	document.querySelectorAll('[data-pkmd-target]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var field  = btn.dataset.pkmdField;
			var target = btn.dataset.pkmdTarget;
			var ta     = gid('pk-dm-' + (field === 'about' ? 'about-text' : 'history-text'));
			var pv     = gid('pk-dm-' + field + '-preview');
			btn.parentElement.querySelectorAll('button').forEach(function(b) { b.classList.remove('pk-active'); });
			btn.classList.add('pk-active');
			if (target === 'preview') {
				ta.style.display = 'none';
				pv.style.display = '';
				if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
					pv.innerHTML = DOMPurify.sanitize(marked.parse(ta.value || ''));
				} else {
					pv.textContent = ta.value;
				}
			} else {
				ta.style.display = '';
				pv.style.display = 'none';
			}
		});
	});

	// --- Quick-add snippets ---
	document.querySelectorAll('[data-pkquick]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var key   = btn.dataset.pkquick;
			var field = btn.dataset.pkfield;
			var ta    = gid('pk-dm-' + (field === 'about' ? 'about-text' : 'history-text'));
			if (!ta) return;
			// Switch to Write mode
			var writeBtn = document.querySelector('[data-pkmd-field="' + field + '"][data-pkmd-target="edit"]');
			if (writeBtn && !writeBtn.classList.contains('pk-active')) writeBtn.click();
			var snippet = PK_QUICKS[key] || '';
			if (!snippet) return;
			var existing = ta.value;
			var sep = existing.length === 0 ? '' : (existing.endsWith('\n\n') ? '' : (existing.endsWith('\n') ? '\n' : '\n\n'));
			ta.value = existing + sep + snippet;
			ta.focus();
			ta.setSelectionRange(ta.value.length, ta.value.length);
		});
	});

	// --- Milestones ---
	function renderCustomMsList() {
		var list = gid('pk-dm-ms-list');
		if (!list) return;
		var newestFirst = gid('pk-dm-ms-newest-first').checked;
		customMs.sort(function(a, b) {
			var ad = a.MilestoneDate || '', bd = b.MilestoneDate || '';
			return newestFirst ? bd.localeCompare(ad) : ad.localeCompare(bd);
		});
		if (customMs.length === 0) {
			list.innerHTML = '<div style="padding:14px;font-size:12px;color:#a0aec0">No custom milestones yet.</div>';
			return;
		}
		var html = '';
		for (var i = 0; i < customMs.length; i++) {
			var m = customMs[i];
			var dateStr = m.MilestoneDate || '';
			if (dateStr && dateStr !== '0000-00-00') {
				var d = new Date(dateStr + 'T00:00:00');
				if (!isNaN(d.getTime())) dateStr = d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
			}
			var icon = (m.Icon || 'fa-star').replace(/[^a-z0-9-]/g, '');
			html += '<div class="pk-dm-ms-row" data-ms-id="' + m.MilestoneId + '">'
				 +    '<i class="fas ' + icon + '"></i>'
				 +    '<span class="pk-dm-ms-desc">' + esc(m.Description) + '</span>'
				 +    '<span class="pk-dm-ms-date">' + dateStr + '</span>'
				 +    '<button type="button" data-tip="Delete" onclick="pkDeleteParkMilestone(' + m.MilestoneId + ')"><i class="fas fa-trash"></i></button>'
				 + '</div>';
		}
		list.innerHTML = html;
	}
	gid('pk-dm-ms-newest-first').addEventListener('change', renderCustomMsList);

	// Icon grid selection
	var iconGrid = gid('pk-dm-ms-icons');
	iconGrid.addEventListener('click', function(e) {
		var opt = e.target.closest('.pk-dm-ms-icon-opt');
		if (!opt) return;
		iconGrid.querySelectorAll('.pk-dm-ms-icon-opt').forEach(function(o) { o.classList.remove('pk-active'); });
		opt.classList.add('pk-active');
		pkSelectedIcon = opt.dataset.icon;
	});

	gid('pk-dm-ms-add-btn').addEventListener('click', function() {
		var desc = gid('pk-dm-ms-add-desc').value.trim();
		var date = gid('pk-dm-ms-add-date').value;
		var err  = gid('pk-dm-ms-add-err');
		err.style.display = 'none';
		if (!desc) { err.textContent = 'Description is required.'; err.style.display = ''; return; }
		if (!date) { err.textContent = 'Date is required.'; err.style.display = ''; return; }
		var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
		var fd = new FormData();
		fd.append('Description', desc);
		fd.append('MilestoneDate', date);
		fd.append('Icon', pkSelectedIcon);
		fetch(BASE_UIR + 'ParkAjax/park/' + PARK_ID + '/addmilestone', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					customMs.push({
						MilestoneId: result.milestoneId,
						Icon: pkSelectedIcon,
						Description: desc,
						MilestoneDate: date
					});
					renderCustomMsList();
					gid('pk-dm-ms-add-desc').value = '';
					gid('pk-dm-ms-add-date').value = '';
					iconGrid.querySelectorAll('.pk-dm-ms-icon-opt').forEach(function(o) { o.classList.remove('pk-active'); });
					iconGrid.querySelector('[data-icon="fa-star"]').classList.add('pk-active');
					pkSelectedIcon = 'fa-star';
				} else {
					err.textContent = (result && result.error) || 'Failed to add milestone.';
					err.style.display = '';
				}
			})
			.catch(function() {
				err.textContent = 'Request failed.';
				err.style.display = '';
			})
			.finally(function() {
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-plus"></i> Add';
			});
	});

	window.pkDeleteParkMilestone = function(id) {
		if (!confirm('Delete this milestone?')) return;
		var fd = new FormData();
		fd.append('MilestoneId', id);
		fetch(BASE_UIR + 'ParkAjax/park/' + PARK_ID + '/deletemilestone', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					customMs = customMs.filter(function(m) { return m.MilestoneId !== id; });
					renderCustomMsList();
				} else {
					alert((result && result.error) || 'Failed to delete milestone.');
				}
			})
			.catch(function() { alert('Request failed.'); });
	};

	// --- Save ---
	gid('pk-dm-save').addEventListener('click', function() {
		var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		var errEl = gid('pk-dm-error'); errEl.style.display = 'none';
		var fd = new FormData();
		fd.append('AboutText', gid('pk-dm-about-text').value);
		fd.append('OurHistory', gid('pk-dm-history-text').value);
		fd.append('ColorPrimary', gid('pk-dm-color-primary').value);
		fd.append('ColorAccent', gid('pk-dm-color-accent').value);
		fd.append('ColorSecondary', gid('pk-dm-gradient-enabled').checked ? gid('pk-dm-color-secondary').value : '');
		fd.append('HeroOverlay', gid('pk-dm-hero-overlay').value);
		fd.append('NameFont', pkSelectedFont || '');
		var msConfig = {};
		document.querySelectorAll('#pk-dm-ms-toggles input[data-pkms-type]').forEach(function(t) {
			msConfig[t.dataset.pkmsType] = t.checked ? 1 : 0;
		});
		msConfig['newest_first'] = gid('pk-dm-ms-newest-first').checked ? 1 : 0;
		fd.append('MilestoneConfig', JSON.stringify(msConfig));

		fd.append('Tagline', gid('pk-dm-tagline').value);
		fd.append('Announcement', gid('pk-dm-announcement').value);
		fd.append('AnnouncementUntil', gid('pk-dm-announcement-until').value);

		var socialPayload = {};
		document.querySelectorAll('.pk-dm-social-input').forEach(function(inp) {
			var v = (inp.value || '').trim();
			if (v) socialPayload[inp.dataset.pksocial] = v;
		});
		fd.append('SocialLinks', Object.keys(socialPayload).length ? JSON.stringify(socialPayload) : '');

		fetch(BASE_UIR + 'ParkAjax/park/' + PARK_ID + '/savedesign', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					window.location.reload();
				} else {
					errEl.textContent = (result && result.error) || 'Save failed.';
					errEl.style.display = 'block';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
				}
			})
			.catch(function(e) {
				errEl.textContent = 'Request failed: ' + e.message;
				errEl.style.display = 'block';
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
			});
	});

	// --- Announcement char counter (shows past 240) ---
	(function() {
		var ta = gid('pk-dm-announcement'); var counter = gid('pk-dm-announcement-count');
		if (!ta || !counter) return;
		function upd() {
			var n = ta.value.length;
			if (n > 240) {
				counter.style.display = 'block';
				counter.textContent = n + ' / 280';
				counter.style.color = n >= 280 ? '#c53030' : '';
			} else {
				counter.style.display = 'none';
			}
		}
		ta.addEventListener('input', upd); upd();
	})();

	// --- Social clear buttons ---
	document.querySelectorAll('.pk-dm-social-clear').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var slug = btn.dataset.pksocialClear;
			var inp = document.querySelector('.pk-dm-social-input[data-pksocial="' + slug + '"]');
			if (inp) { inp.value = ''; inp.focus(); }
		});
	});

	// Initial render
	renderCustomMsList();
})();
</script>
<?php endif; ?>
