<?php
	require_once(DIR_LIB . 'Parsedown.php');
	/* -----------------------------------------------
	   Pre-process template data
	   ----------------------------------------------- */
	$parkList         = is_array($park_summary['KingdomParkAveragesSummary']) ? $park_summary['KingdomParkAveragesSummary'] : array();
	$parkCounts       = []; // loaded via AJAX (park_averages_json)
	$eventList        = is_array($event_summary) ? $event_summary : array();
	// [TOURNAMENTS HIDDEN] $tournamentList = [];
	$principalityList = is_array($principalities['Principalities']) ? $principalities['Principalities'] : array();
	$officerList      = is_array($kingdom_officers['Officers']) ? $kingdom_officers['Officers'] : array();

	// Aggregate attendance — weekly loaded now, monthly loaded via AJAX after page load
	$totalAtt = 0;
	foreach ($parkList as $p) {
		$totalAtt += (int)$p['AttendanceCount'];
	}

	// Heraldry
	$hasHeraldry = $kingdom_info['Info']['KingdomInfo']['HasHeraldry'] == 1;
	$heraldryUrl = $hasHeraldry
		? $kingdom_info['HeraldryUrl']['Url']
		: HTTP_KINGDOM_HERALDRY . '0000.jpg';
	$entityLabel = $IsPrinz ? 'Principality' : 'Kingdom';

	// Extract Monarch & Regent for hero display
	$monarch = null; $regent = null;
	foreach ($officerList as $o) {
		if ($o['OfficerRole'] === 'Monarch') $monarch = $o;
		if ($o['OfficerRole'] === 'Regent')  $regent  = $o;
	}

	// Players loaded via AJAX (players_json) — not available at render time
	$knAllPlayers    = [];
	$knPlayerPeriods = [];

	// Pre-compute map location data (server-side; embedded as JSON for lazy map init)
	if (!function_exists('kn_map_markdown')) {
		function kn_map_markdown(string $text): string {
			$clean = str_replace(['<br />', '<br/>', '<br>'], "\n", $text);
			$html  = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($clean);
			return preg_replace('/<img[^>]*>/i', '', $html);
		}
	}
	$knMapLocations = [];
	foreach ((array)$map_parks as $p) {
		$loc = @json_decode(stripslashes((string)$p['Location']));
		if (!$loc) continue;
		$latlng = isset($loc->location) ? $loc->location : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
		if (!$latlng || !is_numeric($latlng->lat) || !is_numeric($latlng->lng)) continue;
		$knMapLocations[] = [
			'name'     => ucwords($p['Name']),
			'lat'      => (float)$latlng->lat,
			'lng'      => (float)$latlng->lng,
			'id'       => (int)$p['ParkId'],
			'city'     => htmlspecialchars(trim($p['City'] ?? '')),
			'province' => htmlspecialchars(trim($p['Province'] ?? '')),
			'heraldry' => $p['HasHeraldry'] ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf('%05d', $p['ParkId'])) : '',
			'dir'      => kn_map_markdown($p['Directions'] ?? ''),
			'desc'     => kn_map_markdown($p['Description'] ?? ''),
		];
	}

	// --- Kingdom Design (header, About, Milestones customization) -------------------
	$_kInfo         = $kingdom_info['Info']['KingdomInfo'] ?? [];
	$aboutText      = (string)($_kInfo['AboutText']  ?? '');
	$ourHistoryText = (string)($_kInfo['OurHistory'] ?? '');
	$_knLegacyDesc  = (string)($_kInfo['Description'] ?? '');
	if (trim($aboutText) === '') { $aboutText = $_knLegacyDesc; }

	$knColorPrimary   = trim((string)($_kInfo['ColorPrimary']   ?? ''));
	$knColorAccent    = trim((string)($_kInfo['ColorAccent']    ?? ''));
	$knColorSecondary = trim((string)($_kInfo['ColorSecondary'] ?? ''));
	$knOverlay        = strtolower(trim((string)($_kInfo['HeroOverlay'] ?? 'med')));
	if (!in_array($knOverlay, ['low','med','high','vignette'], true)) $knOverlay = 'med';
	$knNameFont       = trim((string)($_kInfo['NameFont'] ?? ''));
	$knMilestoneCfg   = [];
	if (!empty($_kInfo['MilestoneConfig'])) {
		$_mc = json_decode($_kInfo['MilestoneConfig'], true);
		if (is_array($_mc)) $knMilestoneCfg = $_mc;
	}
	$knMsVisible = function($type) use ($knMilestoneCfg) {
		if (!array_key_exists($type, $knMilestoneCfg)) return true;
		return !empty($knMilestoneCfg[$type]);
	};
	$knMsNewestFirst = !empty($knMilestoneCfg['newest_first']);

	$knAllMilestones     = is_array($Milestones ?? null) ? $Milestones : [];
	$knVisibleMilestones = [];
	foreach ($knAllMilestones as $_knms) {
		$_t = $_knms['Type'] ?? 'custom';
		if ($knMsVisible($_t)) $knVisibleMilestones[] = $_knms;
	}
	if ($knMsNewestFirst) $knVisibleMilestones = array_reverse($knVisibleMilestones);
	$knHasMilestones = count($knVisibleMilestones) > 0;

	$knHeroFontCss    = $knNameFont !== '' ? ("'" . str_replace("'", '', $knNameFont) . "'") : '';
	$knOverlayOpacity = ['low' => 0.06, 'med' => 0.13, 'high' => 0.28, 'vignette' => 0.45][$knOverlay] ?? 0.13;

	// --- Phase 2 extras: tagline, social links, announcement, reign banner ---
	$knTagline         = trim((string)($_kInfo['Tagline'] ?? ''));
	$_knSocialRaw      = trim((string)($_kInfo['SocialLinks'] ?? ''));
	$knSocialLinks     = [];
	if ($_knSocialRaw !== '') {
		$_dec = json_decode($_knSocialRaw, true);
		if (is_array($_dec)) $knSocialLinks = $_dec;
	}
	$KN_SOCIAL_PLATFORMS = [
		'discord'   => ['label' => 'Discord',   'icon' => 'fab fa-discord',   'bg' => '#5865f2', 'placeholder' => 'https://discord.gg/...'],
		'facebook'  => ['label' => 'Facebook',  'icon' => 'fab fa-facebook',  'bg' => '#1877f2', 'placeholder' => 'https://facebook.com/...'],
		'instagram' => ['label' => 'Instagram', 'icon' => 'fab fa-instagram', 'bg' => 'linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)', 'placeholder' => 'https://instagram.com/...'],
		'threads'   => ['label' => 'Threads',   'icon' => 'fab fa-threads',   'bg' => '#000000', 'placeholder' => 'https://threads.net/...'],
		'bluesky'   => ['label' => 'Bluesky',   'icon' => 'fas fa-cloud',     'bg' => '#1185fe', 'placeholder' => 'https://bsky.app/...'],
		'twitter'   => ['label' => 'X',         'icon' => 'fab fa-x-twitter', 'bg' => '#000000', 'placeholder' => 'https://x.com/...'],
		'youtube'   => ['label' => 'YouTube',   'icon' => 'fab fa-youtube',   'bg' => '#ff0000', 'placeholder' => 'https://youtube.com/...'],
		'amtwiki'   => ['label' => 'AmtWiki',   'icon' => 'fas fa-book',      'bg' => '#6b7280', 'placeholder' => 'https://amtwiki.net/...'],
	];
	$knVisibleSocials = [];
	foreach ($KN_SOCIAL_PLATFORMS as $_slug => $_meta) {
		$_u = trim((string)($knSocialLinks[$_slug] ?? ''));
		if ($_u !== '') $knVisibleSocials[$_slug] = $_u;
	}

	$knAnnouncement       = trim((string)($_kInfo['Announcement'] ?? ''));
	$knAnnouncementUntil  = trim((string)($_kInfo['AnnouncementUntil'] ?? ''));
	$knShowAnnouncement   = false;
	if ($knAnnouncement !== '') {
		if ($knAnnouncementUntil === '' || $knAnnouncementUntil === '0000-00-00') {
			$knShowAnnouncement = true;
		} else {
			$knShowAnnouncement = (strtotime($knAnnouncementUntil) !== false && strtotime($knAnnouncementUntil) >= strtotime(date('Y-m-d')));
		}
	}

	$knMonarchReignStarted = trim((string)($_kInfo['MonarchReignStarted'] ?? ''));
	$knRegentReignStarted  = trim((string)($_kInfo['RegentReignStarted']  ?? ''));
	$knReignLore           = (string)($_kInfo['ReignLore'] ?? '');
	$knHasReignContent     = ($monarch && !empty($monarch['MundaneId'])) || ($regent && !empty($regent['MundaneId'])) || trim($knReignLore) !== '';

	if (!function_exists('kn_reign_avatar_url')) {
		function kn_reign_avatar_url($mundaneId): string {
			if ((int)$mundaneId <= 0) return '';
			return HTTP_PLAYER_IMAGE . Common::resolve_image_ext(DIR_PLAYER_IMAGE, sprintf('%06d', (int)$mundaneId));
		}
	}

	if (!function_exists('kn_markdown')) {
		function kn_markdown(string $text): string {
			$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($text);
			return preg_replace('/<img[^>]*>/i', '', $html);
		}
	}
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<?php if ($knNameFont !== ''): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= rawurlencode($knNameFont) ?>&display=swap">
<?php endif; ?>

<style>
/* --- Kingdom Design: hero customization ------------------------------------- */
<?php if ($knColorPrimary): ?>
.kn-hero {
	background-color: <?= htmlspecialchars($knColorPrimary) ?> !important;
<?php if ($knColorSecondary && $knColorSecondary !== $knColorPrimary): ?>
	background: linear-gradient(135deg, <?= htmlspecialchars($knColorPrimary) ?>, <?= htmlspecialchars($knColorSecondary) ?>) !important;
<?php endif; ?>
}
html[data-theme="dark"] .kn-hero {
	background-color: <?= htmlspecialchars($knColorPrimary) ?> !important;
<?php if ($knColorSecondary && $knColorSecondary !== $knColorPrimary): ?>
	background: linear-gradient(135deg, <?= htmlspecialchars($knColorPrimary) ?>, <?= htmlspecialchars($knColorSecondary) ?>) !important;
<?php endif; ?>
	filter: brightness(0.85);
}
<?php endif; ?>
<?php if ($knColorAccent): ?>
:root { --kn-accent: <?= htmlspecialchars($knColorAccent) ?>; }
.kn-tab-nav li.kn-tab-active { color: <?= htmlspecialchars($knColorAccent) ?> !important; border-bottom-color: <?= htmlspecialchars($knColorAccent) ?> !important; }
html[data-theme="dark"] .kn-tab-nav li.kn-tab-active { color: <?= htmlspecialchars($knColorAccent) ?> !important; border-bottom-color: <?= htmlspecialchars($knColorAccent) ?> !important; }
.kn-stat-card { position: relative; }
.kn-stat-card::before {
	content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
	background: <?= htmlspecialchars($knColorAccent) ?>;
	border-top-left-radius: 8px; border-top-right-radius: 8px;
}
.kn-parent-kingdom-link a i { color: <?= htmlspecialchars($knColorAccent) ?>; }
.kn-link-list .kn-link-icon i { color: <?= htmlspecialchars($knColorAccent) ?>; }
<?php endif; ?>
.kn-hero-bg { opacity: <?= $knOverlayOpacity ?> !important; }
<?php if ($knOverlay === 'vignette'): ?>
.kn-hero-bg {
	-webkit-mask-image: radial-gradient(ellipse at center, rgba(0,0,0,0.95) 38%, rgba(0,0,0,0) 78%);
	        mask-image: radial-gradient(ellipse at center, rgba(0,0,0,0.95) 38%, rgba(0,0,0,0) 78%);
}
<?php endif; ?>
<?php if ($knNameFont !== '' && $knHeroFontCss !== ''): ?>
.kn-kingdom-name { font-family: <?= $knHeroFontCss ?>, 'Cinzel', serif !important; letter-spacing: 0.02em; }
<?php endif; ?>

/* --- Kingdom Design: About markdown body + Our History + Milestones --------- */
.kn-about-text { line-height: 1.6; }
.kn-about-text h1, .kn-about-text h2, .kn-about-text h3, .kn-about-text h4 {
	margin-top: 1.1em; margin-bottom: 0.4em;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.kn-about-section-head {
	display: flex; align-items: center; justify-content: space-between;
	gap: 8px; margin-bottom: 6px;
}
.kn-about-edit-btn {
	background: transparent; border: 1px solid transparent; color: #a0aec0;
	padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 12px;
	display: inline-flex; align-items: center; gap: 4px;
	transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.kn-about-edit-btn:hover { background: rgba(49,130,206,0.08); color: #2b6cb0; border-color: rgba(49,130,206,0.25); }
html[data-theme="dark"] .kn-about-edit-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-about-edit-btn:hover { background: var(--ork-bg-tertiary); color: var(--ork-link); border-color: var(--ork-border); }
.kn-about-edit-btn[data-tip] { position: relative; }
.kn-about-edit-btn[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; font-style: italic; white-space: nowrap;
	padding: 4px 10px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0s; z-index: 500;
}
.kn-about-edit-btn[data-tip]:hover::after { opacity: 1; transition-delay: 0.4s; }
html[data-theme="dark"] .kn-about-edit-btn[data-tip]::after { background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border); }

/* Our History — visually distinct from About */
.kn-history-card .kn-bare-heading i.kn-history-icon { color: #a0aec0; }
.kn-history-body { line-height: 1.6; }
.kn-history-body h1, .kn-history-body h2, .kn-history-body h3, .kn-history-body h4 {
	margin-top: 1.1em; margin-bottom: 0.4em;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}

/* Milestones timeline (mirrors player .pn-timeline-* / park .pk-timeline-*) */
.kn-timeline-heading {
	font-size: 13px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.5px;
	margin: 0 0 14px 0;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	display: flex; align-items: center; gap: 8px;
}
html[data-theme="dark"] .kn-timeline-heading { color: var(--ork-text-secondary); }
.kn-timeline { position: relative; padding-left: 32px; }
.kn-timeline::before {
	content: ''; position: absolute; left: 11px; top: 4px; bottom: 4px; width: 2px;
	background: linear-gradient(to bottom, #cbd5e0, #cbd5e0 60%, transparent);
}
html[data-theme="dark"] .kn-timeline::before { background: linear-gradient(to bottom, var(--ork-border), var(--ork-border) 60%, transparent); }
.kn-timeline-row {
	position: relative; display: flex; align-items: center; gap: 12px;
	margin-bottom: 14px; min-height: 24px;
}
.kn-timeline-dot {
	position: absolute; left: -25px; top: 50%; transform: translateY(-50%);
	width: 24px; height: 24px; border-radius: 50%;
	background: #ebf8ff; color: #2b6cb0; display: flex; align-items: center; justify-content: center;
	font-size: 11px; border: 2px solid #fff; box-shadow: 0 0 0 1px #cbd5e0;
}
.kn-timeline-row.kn-ms-derived .kn-timeline-dot { background: #faf5ff; color: #6b46c1; box-shadow: 0 0 0 1px #d6bcfa; }
html[data-theme="dark"] .kn-timeline-dot { background: var(--ork-bg-tertiary); color: var(--ork-link); border-color: var(--ork-card-bg); box-shadow: 0 0 0 1px var(--ork-border); }
.kn-timeline-content {
	flex: 1; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
	font-size: 13px;
}
.kn-timeline-date { color: #718096; font-size: 11px; font-weight: 600; min-width: 80px; }
html[data-theme="dark"] .kn-timeline-date { color: var(--ork-text-muted); }
.kn-timeline-desc { color: #2d3748; }
html[data-theme="dark"] .kn-timeline-desc { color: var(--ork-text); }
.kn-timeline-row.kn-ms-derived .kn-timeline-desc { color: #553c9a; }
html[data-theme="dark"] .kn-timeline-row.kn-ms-derived .kn-timeline-desc { color: hsl(265, 60%, 75%); }
.kn-timeline-empty {
	font-size: 12px; color: #a0aec0; font-style: italic;
	padding: 10px 0;
}

/* --- Kingdom About Tab: in-tab layout for About/History/Milestones/Meta ----- */
.kn-about-grid { display: block; }
.kn-about-section {
	background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
	padding: 16px 18px; margin-bottom: 14px;
}
html[data-theme="dark"] .kn-about-section {
	background: var(--ork-card-bg, #2d3748);
	border-color: var(--ork-border, #4a5568);
	color: var(--ork-text, #e2e8f0);
}
.kn-about-label {
	font-size: 13px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.5px;
	display: flex; align-items: center;
}
html[data-theme="dark"] .kn-about-label { color: var(--ork-text-secondary); }

/* Section variants get a soft dashed top divider when stacked */
.kn-history-section { margin-top: 14px; }
.kn-timeline-section { margin-top: 14px; }

/* Meta row (website / parent kingdom) */
.kn-about-meta { padding: 12px 18px; }
.kn-about-meta-row {
	display: flex; align-items: center; gap: 8px;
	font-size: 13px; color: #4a5568; padding: 4px 0;
}
.kn-about-meta-row i { color: #a0aec0; width: 16px; text-align: center; }
.kn-about-meta-row a { color: #2b6cb0; text-decoration: none; }
.kn-about-meta-row a:hover { text-decoration: underline; }
html[data-theme="dark"] .kn-about-meta-row { color: var(--ork-text); }
html[data-theme="dark"] .kn-about-meta-row i { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-about-meta-row a { color: var(--ork-link); }

/* --- Phase 2: tagline ---------------------------------------------------- */
.kn-tagline {
	margin: 4px 0 6px 0;
	font-size: 15px;
	font-style: italic;
	color: #e2e8f0;
	text-shadow: 0 1px 2px rgba(0,0,0,0.4);
	line-height: 1.35;
	max-width: 60ch;
}

/* --- Phase 2: announcement banner --------------------------------------- */
.kn-announce {
	background: linear-gradient(90deg, #fef3c7, #fde68a);
	border-bottom: 2px solid #f59e0b;
	color: #78350f;
	padding: 10px 18px;
	display: flex; align-items: center; gap: 12px;
	font-size: 14px; line-height: 1.4;
}
.kn-announce i.kn-announce-icon {
	font-size: 18px; color: #b45309; flex-shrink: 0;
}
.kn-announce-body { flex: 1; }
.kn-announce-body strong { color: #78350f; margin-right: 6px; }
.kn-announce-until { font-size: 11px; color: #92400e; opacity: 0.85; white-space: nowrap; }
html[data-theme="dark"] .kn-announce {
	background: linear-gradient(90deg, rgba(245,158,11,0.22), rgba(245,158,11,0.32));
	border-bottom-color: #d97706;
	color: #fde68a;
}
html[data-theme="dark"] .kn-announce i.kn-announce-icon { color: #fbbf24; }
html[data-theme="dark"] .kn-announce-body strong { color: #fef3c7; }
html[data-theme="dark"] .kn-announce-until { color: #fbbf24; }

/* --- Phase 2: Connect (sidebar) — flat accent pills --------------------- */
.kn-connect-block { margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0; }
html[data-theme="dark"] .kn-connect-block { border-top-color: var(--ork-border); }
.kn-connect-subhead {
	display: flex; align-items: center; justify-content: space-between;
	font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
	color: #718096; font-weight: 600; margin-bottom: 8px;
}
html[data-theme="dark"] .kn-connect-subhead { color: var(--ork-text-muted); }
.kn-connect-subhead i { margin-right: 4px; opacity: 0.6; }
.kn-connect-edit {
	background: transparent; border: 0; cursor: pointer; color: #a0aec0; font-size: 11px; padding: 2px 6px; border-radius: 4px;
}
.kn-connect-edit:hover { background: rgba(49,130,206,0.08); color: #2b6cb0; }
html[data-theme="dark"] .kn-connect-edit:hover { background: var(--ork-bg-tertiary); color: var(--ork-link); }
.kn-connect-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.kn-connect-pill {
	width: 34px; height: 34px; border-radius: 50%;
	display: inline-flex; align-items: center; justify-content: center;
	color: #fff !important; text-decoration: none !important;
	font-size: 14px; line-height: 1;
	background: var(--kn-accent, #2b6cb0);
	transition: transform 0.12s, box-shadow 0.12s;
	position: relative;
}
.kn-connect-pill:hover { transform: scale(1.08); box-shadow: 0 3px 10px rgba(0,0,0,0.18); }
.kn-connect-pill[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.kn-connect-pill[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .kn-connect-pill[data-tip]::after {
	background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border);
}
.kn-connect-empty {
	display: inline-flex; font-size: 11px; color: #a0aec0; text-decoration: none;
	padding: 4px 8px; border: 1px dashed #cbd5e0; border-radius: 999px;
}
.kn-connect-empty:hover { color: #2b6cb0; border-color: #2b6cb0; }
html[data-theme="dark"] .kn-connect-empty { color: var(--ork-text-muted); border-color: var(--ork-border); }

/* --- Phase 2: reign banner ---------------------------------------------- */
.kn-reign-banner {
	background: linear-gradient(135deg, #faf089 0%, #f6ad55 60%, #c05621 100%);
	border: 1px solid #b7791f; border-radius: 10px;
	padding: 14px 16px; margin-bottom: 14px;
	color: #1a202c;
	position: relative; overflow: hidden;
}
.kn-reign-banner::before {
	content: ''; position: absolute; inset: 0;
	background: radial-gradient(circle at top right, rgba(255,255,255,0.18), transparent 60%);
	pointer-events: none;
}
.kn-reign-head {
	display: flex; align-items: center; gap: 8px;
	font-size: 12px; font-weight: 700;
	text-transform: uppercase; letter-spacing: 0.6px;
	color: #744210;
	margin-bottom: 10px;
	position: relative;
}
.kn-reign-head i { color: #b7791f; font-size: 14px; }
.kn-reign-grid {
	display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
	position: relative;
}
@media (max-width: 640px) { .kn-reign-grid { grid-template-columns: 1fr; } }
.kn-reign-card {
	display: flex; align-items: center; gap: 12px;
	background: rgba(255,255,255,0.78);
	border: 1px solid rgba(116, 66, 16, 0.25);
	border-radius: 8px;
	padding: 10px 12px;
	text-decoration: none;
	color: #2d3748;
	transition: background 0.15s, transform 0.12s;
}
.kn-reign-card:hover { background: rgba(255,255,255,0.92); transform: translateY(-1px); }
.kn-reign-avatar {
	width: 64px; height: 64px; border-radius: 50%;
	object-fit: cover; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,0.15);
	background: #cbd5e0; flex-shrink: 0;
}
.kn-reign-card-body { flex: 1; min-width: 0; }
.kn-reign-role {
	font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
	color: #744210; opacity: 0.85;
}
.kn-reign-name {
	font-size: 15px; font-weight: 600; color: #1a202c;
	line-height: 1.2; margin-top: 2px;
	white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kn-reign-since {
	font-size: 11px; color: #5c4309; opacity: 0.8; margin-top: 3px;
}
.kn-reign-lore {
	margin-top: 12px; position: relative;
	background: rgba(255,255,255,0.65);
	border-left: 3px solid #b7791f;
	padding: 8px 12px;
	border-radius: 4px;
	font-size: 13px; color: #2d3748;
}
.kn-reign-empty {
	position: relative;
	font-size: 12px; color: #5c4309; font-style: italic;
	padding: 6px 0;
}
html[data-theme="dark"] .kn-reign-banner {
	background: linear-gradient(135deg, #744210 0%, #7c2d12 60%, #4a1d0f 100%);
	border-color: #b7791f;
	color: #fef3c7;
}
html[data-theme="dark"] .kn-reign-head { color: #fbbf24; }
html[data-theme="dark"] .kn-reign-head i { color: #fbd38d; }
html[data-theme="dark"] .kn-reign-card {
	background: rgba(26, 32, 44, 0.65);
	border-color: rgba(251, 191, 36, 0.3);
	color: var(--ork-text);
}
html[data-theme="dark"] .kn-reign-card:hover { background: rgba(26, 32, 44, 0.85); }
html[data-theme="dark"] .kn-reign-role { color: #fbd38d; }
html[data-theme="dark"] .kn-reign-name { color: var(--ork-text); }
html[data-theme="dark"] .kn-reign-since { color: #fbbf24; opacity: 0.85; }
html[data-theme="dark"] .kn-reign-lore {
	background: rgba(26, 32, 44, 0.55);
	color: var(--ork-text);
	border-left-color: #fbbf24;
}
html[data-theme="dark"] .kn-reign-empty { color: #fbd38d; }

/* --- Phase 2: design modal extras (social inputs etc.) ------------------ */
.kn-dm-social-row {
	display: grid; grid-template-columns: 110px 1fr; gap: 8px; align-items: center;
	padding: 5px 0; border-bottom: 1px solid #edf2f7;
}
.kn-dm-social-row:last-child { border-bottom: none; }
html[data-theme="dark"] .kn-dm-social-row { border-bottom-color: var(--ork-border); }
.kn-dm-social-label {
	display: flex; align-items: center; gap: 6px;
	font-size: 12px; font-weight: 600; color: #4a5568;
}
html[data-theme="dark"] .kn-dm-social-label { color: var(--ork-text-secondary); }
.kn-dm-social-icon-chip {
	width: 22px; height: 22px; border-radius: 50%;
	display: inline-flex; align-items: center; justify-content: center;
	color: #fff; font-size: 11px;
}
.kn-dm-social-row input[type="url"] {
	width: 100%; padding: 6px 8px; font-size: 12px;
	border: 1px solid #cbd5e0; border-radius: 4px;
	background: #fff; color: #2d3748;
}
html[data-theme="dark"] .kn-dm-social-row input[type="url"] {
	background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text);
}

.kn-dm-counter {
	font-size: 11px; color: #718096; text-align: right; margin-top: 4px;
}
html[data-theme="dark"] .kn-dm-counter { color: var(--ork-text-muted); }
.kn-dm-counter.kn-over { color: #c53030; font-weight: 600; }
</style>

<!-- =============================================
     ZONE 1: Hero Header
     ============================================= -->
<?php if ($knShowAnnouncement): ?>
<div class="kn-announce" role="status">
	<i class="fas fa-bullhorn kn-announce-icon"></i>
	<div class="kn-announce-body"><strong>Announcement:</strong><?= htmlspecialchars($knAnnouncement) ?></div>
	<?php if ($knAnnouncementUntil !== '' && $knAnnouncementUntil !== '0000-00-00'): ?>
	<div class="kn-announce-until">Until <?= date('M j, Y', strtotime($knAnnouncementUntil)) ?></div>
	<?php endif; ?>
</div>
<?php endif; ?>
<div class="kn-hero">
	<div class="kn-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="kn-hero-content">

		<div class="kn-heraldry-wrap">
			<div class="kn-heraldry-frame<?= !empty($CanManageKingdom) ? ' kn-heraldry-editable' : '' ?>">
				<img class="heraldry-img" src="<?= htmlspecialchars($heraldryUrl) ?>"
				     alt="<?= htmlspecialchars($kingdom_name) ?>"
				     crossorigin="anonymous"
				     onload="typeof knApplyHeroColor==='function'&&knApplyHeroColor(this)">
			</div>
			<?php if (!empty($CanManageKingdom)): ?>
			<button class="kn-heraldry-edit-btn" onclick="knOpenHeraldryModal()" title="Change heraldry">
				<i class="fas fa-camera"></i>
			</button>
			<?php endif; ?>
		</div>

		<div class="kn-hero-info">
			<?php if ($IsPrinz && !empty($ParentKingdomId)): ?>
			<div class="kn-parent-kingdom-link">
				<a href="<?= UIR ?>Kingdom/profile/<?= (int)$ParentKingdomId ?>">
					<i class="fas fa-chess-rook"></i> <?= htmlspecialchars($ParentKingdomName) ?>
				</a>
			</div>
			<?php endif; ?>
			<h1 class="kn-kingdom-name"><?= htmlspecialchars($kingdom_name) ?></h1>
			<?php if ($knTagline !== ''): ?>
			<div class="kn-tagline"><?= htmlspecialchars($knTagline) ?></div>
			<?php endif; ?>
			<div class="kn-badges">
				<span class="kn-badge kn-badge-green">
					<i class="fas fa-shield-alt"></i> <?= $entityLabel ?>
				</span>
			</div>
			<div class="kn-officers-inline">
				<?php if ($monarch): ?>
					<i class="fas fa-crown" style="font-size:10px;opacity:0.6;margin-right:3px"></i>
					Monarch:&nbsp;
					<?php if (!empty($monarch['MundaneId']) && $monarch['MundaneId'] > 0): ?>
						<a href="<?= UIR ?>Player/profile/<?= $monarch['MundaneId'] ?>"><?= htmlspecialchars($monarch['Persona']) ?></a>
					<?php else: ?>
						<span class="kn-vacant">Vacant</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="kn-hero-actions">
			<?php if ($CanManageKingdom ?? false): ?>
				<button class="kn-btn kn-btn-outline" onclick="knOpenAwardModal()">
					<i class="fas fa-medal"></i> Enter Awards
				</button>
			<?php endif; ?>
			<a class="kn-btn kn-btn-outline" href="#" onclick="knActivateTab('map');return false;">
				<i class="fas fa-map"></i> Map
			</a>
			<?php if ($CanManageKingdom ?? false): ?>
			<button class="kn-btn kn-btn-outline" onclick="knOpenDesignModal()">
				<i class="fas fa-palette"></i> Design
			</button>
			<button class="kn-btn kn-btn-outline" onclick="knOpenAdminModal()">
				<i class="fas fa-cog"></i> Admin
			</button>
			<?php endif; ?>
		</div>

	</div>
</div>

<!-- =============================================
     ZONE 2: Dashboard Stats
     ============================================= -->
<div class="kn-stats-row">
	<div class="kn-stat-card kn-stat-card-link" onclick="knActivateTab('parks')">
		<div class="kn-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="kn-stat-number"><?= count($parkList) ?></div>
		<div class="kn-stat-label">Parks</div>
	</div>
	<div class="kn-stat-card kn-stat-card-link" onclick="knActivateTab('events')">
		<div class="kn-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="kn-stat-number"><?= count($eventList) ?></div>
		<div class="kn-stat-label">Events</div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-users"></i></div>
		<div class="kn-stat-number" id="kn-stat-avgwk">—</div>
		<div class="kn-stat-label">Avg / Week <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per week across all parks in this Kingdom, averaged over the past 6 months. A player attending multiple parks in one week counts once.</span></span></div>
	</div>
	<div class="kn-stat-card">
		<div class="kn-stat-icon"><i class="fas fa-chart-line"></i></div>
		<div class="kn-stat-number" id="kn-stat-avgmo">—</div>
		<div class="kn-stat-label">Avg / Month <span class="pk-stat-tip"><i class="fas fa-info-circle"></i><span class="pk-stat-tip-text">Distinct players per month across all parks in this Kingdom, averaged over the past 12 months. A player attending multiple parks in one month counts once.</span></span></div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Main Content
     ============================================= -->
<div class="kn-layout">

	<!-- ========== SIDEBAR ========== -->
	<div class="kn-sidebar">

		<!-- Officers -->
		<?php if (count($officerList) > 0 || ($CanManageKingdom ?? false)): ?>
		<div class="kn-card">
			<h4 class="kn-bare-heading" style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-crown"></i> Officers</span>
				<?php if ($CanManageKingdom ?? false): ?>
				<button onclick="knOpenEditOfficersModal()" class="kn-edit-officers-btn" title="Edit officers">
					<i class="fas fa-pencil-alt"></i>
				</button>
				<?php endif; ?>
			</h4>
			<ul class="kn-officer-list">
				<?php foreach ($officerList as $o): ?>
				<li>
					<span class="kn-officer-role"><?= htmlspecialchars($o['OfficerRole']) ?></span>
					<span class="kn-officer-name">
						<?php if (!empty($o['MundaneId']) && $o['MundaneId'] > 0): ?>
							<a href="<?= UIR ?>Player/profile/<?= $o['MundaneId'] ?>"><?= htmlspecialchars($o['Persona']) ?></a>
						<?php else: ?>
							<em style="color:#a0aec0">Vacant</em>
						<?php endif; ?>
					</span>
				</li>
				<?php endforeach; ?>
				<?php if (count($officerList) === 0): ?>
				<li><em style="color:#a0aec0;font-size:12px">No officers on record</em></li>
				<?php endif; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Quick Links -->
		<div class="kn-card">
			<h4 class="kn-bare-heading"><i class="fas fa-link"></i> Quick Links</h4>
			<ul class="kn-link-list">
				<li>
					<span class="kn-link-icon"><i class="fas fa-search"></i></span>
					<a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Search Players</a>
				</li>
				<?php if ($IsLoggedIn): ?>
					<li>
						<span class="kn-link-icon"><i class="fas fa-medal"></i></span>
						<a href="<?= UIR ?>Award/kingdom/<?= $kingdom_id ?>">Enter Awards</a>
					</li>
				<?php endif; ?>
				<li>
					<span class="kn-link-icon"><i class="fas fa-map-marked-alt"></i></span>
					<a href="#" onclick="knActivateTab('map');return false;">Kingdom Map</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-users"></i></span>
					<a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a>
				</li>
				<li>
					<span class="kn-link-icon"><i class="fas fa-calendar"></i></span>
					<a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Find Events</a>
				</li>
			</ul>
			<?php if (!empty($knVisibleSocials) || ($CanManageKingdom ?? false)): ?>
			<div class="kn-connect-block">
				<div class="kn-connect-subhead">
					<span><i class="fas fa-share-alt"></i> Connect</span>
					<?php if ($CanManageKingdom ?? false): ?>
					<button class="kn-connect-edit" type="button" onclick="knOpenDesignModal('about')" data-tip="Edit social links"><i class="fas fa-pencil-alt"></i></button>
					<?php endif; ?>
				</div>
				<?php if (!empty($knVisibleSocials)): ?>
				<div class="kn-connect-pills">
					<?php foreach ($knVisibleSocials as $_slug => $_url):
						$_meta = $KN_SOCIAL_PLATFORMS[$_slug];
					?>
					<a class="kn-connect-pill" href="<?= htmlspecialchars($_url) ?>" target="_blank" rel="noopener noreferrer" data-tip="<?= htmlspecialchars($_meta['label']) ?>">
						<i class="<?= $_meta['icon'] ?>"></i>
					</a>
					<?php endforeach; ?>
				</div>
				<?php elseif ($CanManageKingdom ?? false): ?>
				<a href="#" class="kn-connect-empty" onclick="event.preventDefault();knOpenDesignModal('about')">+ Add</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>

	</div>

	<!-- ========== MAIN CONTENT (Tabbed) ========== -->
	<div class="kn-main">
		<div class="kn-tabs">
			<ul class="kn-tab-nav">
				<li class="kn-tab-active" data-kntab="about">
					<i class="fas fa-info-circle"></i><span class="kn-tab-label"> About</span>
				</li>
				<li data-kntab="parks">
					<i class="fas fa-map-marker-alt"></i><span class="kn-tab-label"> Parks</span>
					<span class="kn-tab-count">(<?= count($parkList) ?>)</span>
				</li>
				<li data-kntab="events">
					<i class="fas fa-calendar-alt"></i><span class="kn-tab-label"> Events</span>
					<span class="kn-tab-count">(<?= count($eventList) ?>)</span>
				</li>
				<li data-kntab="map">
					<i class="fas fa-map"></i><span class="kn-tab-label"> Map</span>
				</li>
				<?php if (!$IsPrinz && count($principalityList) > 0): ?>
					<li data-kntab="principalities">
						<i class="fas fa-shield-alt"></i><span class="kn-tab-label"> Principalities</span>
						<span class="kn-tab-count">(<?= count($principalityList) ?>)</span>
					</li>
				<?php endif; ?>
				<li data-kntab="players" id="kn-tab-btn-players">
					<i class="fas fa-users"></i><span class="kn-tab-label"> Players</span>
					<span class="kn-tab-count" id="kn-players-tab-count"></span>
				</li>
				<li data-kntab="reports">
					<i class="fas fa-chart-bar"></i><span class="kn-tab-label"> Reports</span>
				</li>
				<?php if ($ShowRecsTab ?? false): ?>
				<li data-kntab="recommendations">
					<i class="fas fa-star"></i><span class="kn-tab-label"> Recommendations</span>
					<?php if (!empty($AwardRecommendations)): ?>
					<span class="kn-tab-count">(<?= count($AwardRecommendations) ?>)</span>
					<?php endif; ?>
				</li>
				<?php endif; ?>
				<?php if ($CanManageKingdom ?? false): ?>
				<li data-kntab="admin">
					<i class="fas fa-cog"></i><span class="kn-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
			</ul>
			<div class="kn-active-tab-label" id="kn-active-tab-label">About</div>

			<!-- About Tab -->
			<div class="kn-tab-panel" id="kn-tab-about">
				<?php if ($knHasReignContent || ($CanManageKingdom ?? false)): ?>
				<?php if ($knHasReignContent): ?>
				<div class="kn-reign-banner">
					<div class="kn-reign-head">
						<i class="fas fa-crown"></i>
						<span>Current Reign</span>
						<?php if ($CanManageKingdom ?? false): ?>
						<button class="kn-about-edit-btn" type="button" onclick="knOpenDesignModal('header')" data-tip="Edit Reign" style="margin-left:auto;background:transparent;border:0;color:#744210;cursor:pointer;font-size:11px">
							<i class="fas fa-pencil-alt"></i> Edit
						</button>
						<?php endif; ?>
					</div>
					<?php $_hasReignCard = ($monarch && !empty($monarch['MundaneId'])) || ($regent && !empty($regent['MundaneId'])); ?>
					<?php if ($_hasReignCard): ?>
					<div class="kn-reign-grid">
						<?php if ($monarch && !empty($monarch['MundaneId']) && $monarch['MundaneId'] > 0):
							$_mImg = kn_reign_avatar_url($monarch['MundaneId']);
							$_mInitial = htmlspecialchars(strtoupper(mb_substr($monarch['Persona'] ?? '?', 0, 1)));
						?>
						<a href="<?= UIR ?>Player/profile/<?= (int)$monarch['MundaneId'] ?>" class="kn-reign-card">
							<img src="<?= htmlspecialchars($_mImg) ?>" alt="" class="kn-reign-avatar" onerror="this.onerror=null;this.src='<?= HTTP_PLAYER_HERALDRY ?>000000.jpg'">
							<div class="kn-reign-card-body">
								<div class="kn-reign-role">Monarch</div>
								<div class="kn-reign-name"><?= htmlspecialchars($monarch['Persona']) ?></div>
								<?php if ($knMonarchReignStarted !== '' && $knMonarchReignStarted !== '0000-00-00' && strtotime($knMonarchReignStarted)): ?>
								<div class="kn-reign-since">Since <?= date('M Y', strtotime($knMonarchReignStarted)) ?></div>
								<?php endif; ?>
							</div>
						</a>
						<?php endif; ?>
						<?php if ($regent && !empty($regent['MundaneId']) && $regent['MundaneId'] > 0):
							$_rImg = kn_reign_avatar_url($regent['MundaneId']);
						?>
						<a href="<?= UIR ?>Player/profile/<?= (int)$regent['MundaneId'] ?>" class="kn-reign-card">
							<img src="<?= htmlspecialchars($_rImg) ?>" alt="" class="kn-reign-avatar" onerror="this.onerror=null;this.src='<?= HTTP_PLAYER_HERALDRY ?>000000.jpg'">
							<div class="kn-reign-card-body">
								<div class="kn-reign-role">Regent</div>
								<div class="kn-reign-name"><?= htmlspecialchars($regent['Persona']) ?></div>
								<?php if ($knRegentReignStarted !== '' && $knRegentReignStarted !== '0000-00-00' && strtotime($knRegentReignStarted)): ?>
								<div class="kn-reign-since">Since <?= date('M Y', strtotime($knRegentReignStarted)) ?></div>
								<?php endif; ?>
							</div>
						</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>
					<?php if (trim($knReignLore) !== ''): ?>
					<div class="kn-reign-lore kn-description-body"><?= kn_markdown($knReignLore) ?></div>
					<?php endif; ?>
				</div>
				<?php elseif ($CanManageKingdom ?? false): ?>
				<div class="kn-reign-banner">
					<div class="kn-reign-head"><i class="fas fa-crown"></i><span>Current Reign</span></div>
					<div class="kn-reign-empty">
						Showcase the current Monarch &amp; Regent. <a href="#" onclick="event.preventDefault();knOpenDesignModal('header')" style="color:#744210;text-decoration:underline">Set reign-start dates</a> or add lore.
					</div>
				</div>
				<?php endif; ?>
				<?php endif; ?>

				<div class="kn-about-grid">
					<?php if (!empty($aboutText) || ($CanManageKingdom ?? false)): ?>
					<div class="kn-about-section">
						<div class="kn-about-section-head">
							<div class="kn-about-label">About</div>
							<?php if ($CanManageKingdom ?? false): ?>
							<button class="kn-about-edit-btn" type="button" onclick="knOpenDesignModal('about')" data-tip="Edit About">
								<i class="fas fa-pencil-alt"></i> Edit
							</button>
							<?php endif; ?>
						</div>
						<?php if (!empty($aboutText)): ?>
						<div class="kn-about-text kn-description-body"><?= kn_markdown($aboutText) ?></div>
						<?php elseif ($CanManageKingdom ?? false): ?>
						<div class="kn-empty" style="text-align:left;font-size:13px;color:#a0aec0">
							No About content yet. <a href="#" onclick="event.preventDefault();knOpenDesignModal('about')">Add some</a> to introduce <?= htmlspecialchars($kingdom_name) ?> to visitors.
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>

				<?php if (!empty($ourHistoryText) || ($CanManageKingdom ?? false)): ?>
				<div class="kn-about-section kn-history-section">
					<div class="kn-about-section-head">
						<div class="kn-about-label"><i class="fas fa-scroll" style="margin-right:6px;color:#a0aec0"></i>Our History</div>
						<?php if ($CanManageKingdom ?? false): ?>
						<button class="kn-about-edit-btn" type="button" onclick="knOpenDesignModal('about')" data-tip="Edit Our History">
							<i class="fas fa-pencil-alt"></i> Edit
						</button>
						<?php endif; ?>
					</div>
					<?php if (!empty($ourHistoryText)): ?>
					<div class="kn-about-text kn-description-body"><?= kn_markdown($ourHistoryText) ?></div>
					<?php elseif ($CanManageKingdom ?? false): ?>
					<div class="kn-empty" style="text-align:left;font-size:13px;color:#a0aec0">
						Share the founding story, past officers, or notable moments. <a href="#" onclick="event.preventDefault();knOpenDesignModal('about')">Add Our History</a>.
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php
					$_knWebsiteUrl = trim((string)($_kInfo['Url'] ?? ''));
					$_knParentId   = (int)($_kInfo['ParentKingdomId'] ?? ($ParentKingdomId ?? 0));
					$_knParentName = trim((string)($ParentKingdomName ?? ''));
				?>
				<?php if ($_knWebsiteUrl !== '' || $_knParentId > 0): ?>
				<div class="kn-about-section kn-about-meta">
					<?php if ($_knWebsiteUrl !== ''): ?>
					<div class="kn-about-meta-row">
						<i class="fas fa-globe"></i>
						<a href="<?= htmlspecialchars($_knWebsiteUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($_knWebsiteUrl) ?></a>
					</div>
					<?php endif; ?>
					<?php if ($_knParentId > 0): ?>
					<div class="kn-about-meta-row">
						<i class="fas fa-chess-rook"></i>
						<a href="<?= UIR ?>Kingdom/profile/<?= $_knParentId ?>"><?= htmlspecialchars($_knParentName !== '' ? $_knParentName : 'Parent kingdom') ?></a>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ($knHasMilestones || ($CanManageKingdom ?? false)): ?>
				<div class="kn-about-section kn-timeline-section">
					<div class="kn-about-section-head">
						<h3 class="kn-timeline-heading"><i class="fas fa-stream"></i> Milestones</h3>
						<?php if ($CanManageKingdom ?? false): ?>
						<button class="kn-about-edit-btn" type="button" onclick="knOpenDesignModal('milestones')" data-tip="Manage milestones">
							<i class="fas fa-pencil-alt"></i> Manage
						</button>
						<?php endif; ?>
					</div>
					<?php if ($knHasMilestones): ?>
					<div class="kn-timeline">
						<?php foreach ($knVisibleMilestones as $_knms): ?>
						<div class="kn-timeline-row<?= !empty($_knms['IsDerived']) ? ' kn-ms-derived' : '' ?>">
							<div class="kn-timeline-dot">
								<i class="fas <?= htmlspecialchars(preg_replace('/[^a-z0-9-]/','', (string)($_knms['Icon'] ?? 'fa-star')) ?: 'fa-star') ?>"></i>
							</div>
							<div class="kn-timeline-content">
								<span class="kn-timeline-date"><?= !empty($_knms['MilestoneDate']) && $_knms['MilestoneDate'] !== '0000-00-00' ? date('M j, Y', strtotime($_knms['MilestoneDate'])) : '' ?></span>
								<span class="kn-timeline-desc"><?= htmlspecialchars((string)($_knms['Description'] ?? '')) ?></span>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php elseif ($CanManageKingdom ?? false): ?>
					<div class="kn-timeline-empty">
						No milestones yet. <a href="#" onclick="event.preventDefault();knOpenDesignModal('milestones')">Add the first one</a> — founding date, charter dates, notable events.
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</div><!-- /kn-tab-about -->

			<!-- Parks Tab -->
			<div class="kn-tab-panel" id="kn-tab-parks" style="display:none">
				<?php
					// Pre-sort alphabetically so tiles match default list order
					usort($parkList, function($a, $b) { return strcmp($a['ParkName'], $b['ParkName']); });
					// Pin the logged-in user's home park to the first slot
					$_upid = isset($UserParkId) ? (int)$UserParkId : 0;
					if ($_upid > 0) {
						$_pinIdx = array_search($_upid, array_column($parkList, 'ParkId'));
						if ($_pinIdx !== false) {
							$_pinned = array_splice($parkList, $_pinIdx, 1);
							$_pinned[0]['_pinned'] = true;
							array_unshift($parkList, $_pinned[0]);
						}
					}
				?>
				<?php if (count($parkList) > 0): ?>

					<!-- Toolbar -->
					<div class="kn-parks-toolbar">
						<button class="kn-view-btn" id="kn-view-tiles" title="Tile view">
							<i class="fas fa-th-large"></i>
						</button>
						<button class="kn-view-btn" id="kn-view-list" title="List view">
							<i class="fas fa-list"></i>
						</button>
						<button class="kn-view-btn" title="Map view" onclick="knActivateTab('map');return false;">
							<i class="fas fa-map"></i>
						</button>
						<?php if ($CanAddPark ?? false): ?>
						<button onclick="knOpenAddParkModal()" style="margin-left:auto;display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Park
						</button>
						<?php endif; ?>
					</div>

					<!-- Tile view -->
					<div id="kn-parks-tiles" class="kn-park-tiles">
						<?php foreach ($parkList as $park): ?>
							<?php $tileHeraldry = $park['HasHeraldry'] == 1
								? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId']))
								: HTTP_PARK_HERALDRY . '00000.jpg'; ?>
							<a class="kn-park-tile<?= !empty($park['_pinned']) ? ' kn-pinned' : '' ?>" href="<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>" data-park-id="<?= (int)$park['ParkId'] ?>">
								<div class="kn-park-tile-img-wrap">
									<?php if (!empty($park['_pinned'])): ?><span class="kn-park-pin-badge">Your Park</span><?php endif; ?>
									<img src="<?= $tileHeraldry ?>"
										loading="lazy"
										onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
										alt="<?= htmlspecialchars($park['ParkName']) ?>">
								</div>
								<div class="kn-park-tile-body">
									<div class="kn-park-tile-name"><?= htmlspecialchars($park['ParkName']) ?></div>
									<div class="kn-park-tile-type"><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : 'Park') ?></div>
									<div class="kn-park-tile-stats">
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val kn-avgwk-tile"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></div>
											<div class="kn-park-tile-stat-lbl">Avg/Wk</div>
										</div>
										<div class="kn-park-tile-stat">
											<div class="kn-park-tile-stat-val kn-avgmo-tile"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></div>
											<div class="kn-park-tile-stat-lbl">Avg/Mo</div>
										</div>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>

					<!-- List view -->
					<div id="kn-parks-list-view" style="display:none">
						<table class="kn-table kn-sortable" id="kn-parks-table">
							<thead>
								<tr>
									<th data-sorttype="text">Park</th>
									<th data-sorttype="text">Type</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average distinct players per week over the past 6 months">Avg/Wk</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Average distinct players per month over the past 12 months">Avg/Mo</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Distinct players who signed in at this park in the past 12 months">Total Players</th>
									<th data-sorttype="numeric" class="kn-col-numeric" title="Distinct players whose home park is here who signed in at this park in the past 12 months">Total Members</th>
									<?php if ($CanManageKingdom ?? false): ?><th data-sorttype="none" style="width:32px"></th><?php endif; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($parkList as $park): ?>
									<tr class="kn-row-link<?= !empty($park['_pinned']) ? ' kn-pinned-row' : '' ?>" data-park-id="<?= (int)$park['ParkId'] ?>" onclick="window.location.href='<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>'">
										<td class="kn-col-nowrap">
											<img class="kn-thumb"
												loading="lazy"
												src="<?= $park['HasHeraldry'] == 1 ? HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId'])) : HTTP_PARK_HERALDRY . '00000.jpg' ?>"
												onerror="this.src='<?= HTTP_PARK_HERALDRY ?>00000.jpg'"
												alt="">
											<a href="<?= UIR ?>Park/profile/<?= $park['ParkId'] ?>"><?= htmlspecialchars($park['ParkName']) ?></a>
											<?php if (!empty($park['_pinned'])): ?><span class="kn-park-pin-badge" style="position:static;margin-left:6px">Your Park</span><?php endif; ?>
										</td>
										<td><?= htmlspecialchars(!empty($park['Title']) ? $park['Title'] : '') ?></td>
										<td class="kn-col-numeric kn-avgwk-row"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></td>
										<td class="kn-col-numeric kn-avgmo-row"><i class="fas fa-spinner fa-spin kn-stat-spinner"></i></td>
										<td class="kn-col-numeric kn-tp-row">—</td>
										<td class="kn-col-numeric kn-tm-row">—</td>
										<?php if ($CanManageKingdom ?? false): ?>
										<td class="kn-col-edit" onclick="event.stopPropagation();knOpenEditParkModal(<?= (int)$park['ParkId'] ?>)" title="Edit park">
											<i class="fas fa-pencil-alt"></i>
										</td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr>
									<td colspan="2">Kingdom Total</td>
									<td class="kn-col-numeric" id="kn-total-avgwk">—</td>
									<td class="kn-col-numeric" id="kn-total-avgmo">—</td>
									<td class="kn-col-numeric" id="kn-total-tp" title="Sum across parks (players may be counted in multiple parks)">—</td>
									<td class="kn-col-numeric" id="kn-total-tm">—</td>
									<?php if ($CanManageKingdom ?? false): ?><td></td><?php endif; ?>
								</tr>
							</tfoot>
						</table>
					</div>

				<?php else: ?>
					<div class="kn-empty">No parks found</div>
				<?php endif; ?>
			</div>

			<style>
			.kn-sub-pop-title{font-weight:700;color:#2d3748;margin-bottom:8px;font-size:12px;text-transform:uppercase;letter-spacing:.05em}
			.kn-sub-pop-row{display:flex;gap:4px;margin-bottom:8px}
			.kn-sub-url-input{flex:1;font-size:11px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;color:#4a5568;background:#f7fafc;min-width:0}
			.kn-sub-copy-btn{padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;background:#edf2f7;cursor:pointer;color:#4a5568;font-size:12px}
			.kn-sub-copy-btn:hover{background:#e2e8f0}
			.kn-sub-gcal-btn{display:block;text-align:center;background:#4285f4;color:#fff;border-radius:5px;padding:7px 10px;font-size:12px;font-weight:600;text-decoration:none;margin-bottom:2px}
			.kn-sub-gcal-btn:hover{background:#3367d6;color:#fff}
			.kn-sub-webcal-btn{display:block;margin-top:6px;font-size:11px;color:#718096;text-align:center;text-decoration:none}
			.kn-sub-webcal-btn:hover{color:#4a5568}
			html[data-theme="dark"] .kn-sub-pop-title{color:var(--ork-text)}
			html[data-theme="dark"] .kn-sub-url-input{background:var(--ork-input-bg);border-color:var(--ork-input-border);color:var(--ork-text)}
			html[data-theme="dark"] .kn-sub-copy-btn{background:var(--ork-bg-tertiary);border-color:var(--ork-border);color:var(--ork-text-secondary)}
			html[data-theme="dark"] .kn-sub-copy-btn:hover{background:var(--ork-bg-secondary)}
			html[data-theme="dark"] .kn-sub-webcal-btn{color:var(--ork-text-muted)}
			html[data-theme="dark"] .kn-sub-webcal-btn:hover{color:var(--ork-text)}
			</style>
			<!-- Events Tab -->
			<div class="kn-tab-panel" id="kn-tab-events" style="display:none">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
					<h4 class="kn-bare-heading" style="margin:0;font-size:14px;font-weight:700;"><i class="fas fa-calendar-alt" style="margin-right:6px;color:#a0aec0"></i>Events</h4>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<button class="kn-view-btn kn-view-active" id="kn-ev-view-list" title="List view"><i class="fas fa-list"></i></button>
						<button class="kn-view-btn" id="kn-ev-view-cal" title="Calendar view"><i class="fas fa-calendar-alt"></i></button>
						<div id="kn-ev-filter-bar" style="display:flex;align-items:center;gap:5px;">
							<span style="font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.05em;margin-right:2px;">Show:</span>
							<button class="kn-filter-toggle kn-filter-on" data-filter="kingdom-event">Kingdom Events</button>
							<button class="kn-filter-toggle kn-filter-on" data-filter="park-event">Park Events</button>
							<button class="kn-filter-toggle" data-filter="park-day">Park Days</button>
						</div>
						<div class="kn-sub-wrap" id="kn-sub-wrap" style="position:relative">
							<button class="kn-view-btn" id="kn-sub-btn" title="Subscribe to calendar"
								onclick="(function(btn){var p=document.getElementById('kn-sub-pop');var r=btn.getBoundingClientRect();p.style.top=(r.bottom+6)+'px';p.style.right=(window.innerWidth-r.right)+'px';var show=p.style.display==='none';p.style.setProperty('display',show?'block':'none','important');event.stopPropagation();})(this)">
								<i class="fas fa-rss"></i>
							</button>
							<div class="kn-sub-pop" id="kn-sub-pop" style="display:none;position:fixed;z-index:9000;background:var(--ork-card-bg,#fff);border:1px solid var(--ork-border,#e2e8f0);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);padding:12px 14px;width:280px;font-size:13px;color:var(--ork-text,#2d3748)">
								<div class="kn-sub-pop-title"><i class="fas fa-calendar-check" style="margin-right:5px"></i>Subscribe to Events</div>
								<div class="kn-sub-pop-row">
									<input class="kn-sub-url-input" id="kn-sub-url-input" type="text"
										value="<?= htmlspecialchars($IcsUrl) ?>" readonly>
									<button class="kn-sub-copy-btn" onclick="knCopyIcsUrl()" title="Copy URL">
										<i class="fas fa-copy"></i>
									</button>
								</div>
								<a class="kn-sub-gcal-btn"
									href="https://calendar.google.com/calendar/r/settings/addbyurl?url=<?= urlencode($IcsUrl) ?>"
									target="_blank" rel="noopener">
									<i class="fab fa-google" style="margin-right:6px"></i>Add to Google Calendar
								</a>
								<a class="kn-sub-webcal-btn"
									href="webcal://<?= htmlspecialchars(preg_replace('#^https?://#', '', $IcsUrl)) ?>">
									<i class="fas fa-link" style="margin-right:4px"></i>webcal:// (direct app)
								</a>
							</div>
						</div>
						<?php if ($CanManageKingdom): ?>
						<button onclick="knOpenEventModal()" style="display:inline-flex;align-items:center;gap:5px;background:#276749;color:#fff;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
							<i class="fas fa-plus"></i> Add Event
						</button>
						<?php endif; ?>
					</div>
				</div>
				<!-- Calendar view (lazy-loaded FullCalendar) -->
				<div id="kn-events-cal-wrap" style="position:relative;display:none">
					<div id="kn-cal-loading" style="display:none;position:absolute;inset:0;background:var(--ork-overlay-light,rgba(255,255,255,0.88));z-index:10;align-items:center;justify-content:center;min-height:120px;">
						<i class="fas fa-spinner fa-spin" style="font-size:28px;color:#a0aec0"></i>
					</div>
					<div id="kn-events-cal"></div>
				</div>

				<!-- List view -->
				<div id="kn-events-list-view">
				<?php $hasParkDays = count($kingdom_park_days ?? []) > 0; ?>
				<?php $eventCount = count($eventList); ?>
				<?php $hasAnyRows = ($eventCount > 0) || $hasParkDays; ?>
				<table class="kn-table kn-sortable" id="kn-events-table"<?= $hasAnyRows ? '' : ' style="display:none"' ?>>
					<thead>
						<tr>
							<th data-sorttype="text">Event</th>
							<th data-sorttype="date">Next Date</th>
							<th data-sorttype="text">Park</th>
							<th data-sorttype="numeric">Going</th>
						<th data-sorttype="numeric">Interested</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($eventList as $event): ?>
							<tr class="kn-row-link" data-type="<?= $event['_IsParkEvent'] ? 'park-event' : 'kingdom-event' ?>"<?= $event['NextDetailId'] ? ' onclick="window.location.href=\''.UIR.'Event/detail/' . $event['EventId'] . '/' . $event['NextDetailId'] . '\'"' : '' ?>>
								<td class="kn-col-nowrap">
									<img class="kn-thumb <?= $event['_IsParkEvent'] ? 'kn-evt-park' : 'kn-evt-kingdom' ?>"
										loading="lazy"
										src="<?= $event['HasHeraldry'] == 1 ? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) : HTTP_EVENT_HERALDRY . '00000.jpg' ?>"
										onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
										alt="">
									<?php if ($event['NextDetailId']): ?><a href="<?= UIR ?>Event/detail/<?= $event['EventId'] ?>/<?= $event['NextDetailId'] ?>"><?= htmlspecialchars($event['Name']) ?></a><?php else: ?><?= htmlspecialchars($event['Name']) ?><?php endif; ?>
								</td>
								<td class="kn-col-nowrap">
									<?php if (0 != $event['NextDate'] && $event['NextDate'] != '0000-00-00'): ?>
										<?= date("M j, Y", strtotime($event['NextDate'])) ?>
										<?php if (strtotime($event['NextDate']) < time()): ?><span class='event-past-badge'>Past</span><?php endif; ?>
									<?php else: ?>
										<span style="color:#a0aec0">—</span>
									<?php endif; ?>
								</td>
								<td><?= htmlspecialchars($event['ParkName']) ?></td>
								<td style="text-align:center"><?= (int)($event['RsvpGoing'] ?? 0) ?: '—' ?></td>
							<td style="text-align:center"><?= (int)($event['RsvpInterested'] ?? 0) ?: '—' ?></td>
							</tr>
						<?php endforeach; ?>
					<?php foreach ($kingdom_park_days ?? [] as $day): ?>
						<tr class="kn-row-link" data-type="park-day" style="display:none" onclick="window.location.href='<?= UIR ?>Park/profile/<?= $day['ParkId'] ?>'">
							<td class="kn-col-nowrap" style="color:#718096;font-style:italic"><?= htmlspecialchars($day['Schedule']) ?></td>
							<td class="kn-col-nowrap">
								<i class="fas fa-calendar" style="margin-right:6px;color:#a0aec0"></i>
								<?php if (!empty($day['ParkAbbr'])): ?><strong style="color:#4a5568;margin-right:3px"><?= htmlspecialchars($day['ParkAbbr']) ?>:</strong><?php endif; ?>
								<?= htmlspecialchars($day['Purpose']) ?> — <?= (!empty($day['Time'])) ? date('g:i A', strtotime($day['Time'])) : '' ?>
							</td>
							<td><?= htmlspecialchars($day['ParkName']) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="kn-empty" id="kn-events-empty"<?= $hasAnyRows ? ' style="display:none"' : '' ?>>No upcoming events</div>

				<div class="kn-events-loadmore" id="kn-events-loadmore" data-next-window="1" data-loaded-event-count="<?= $eventCount ?>">
					<span class="kn-events-loadmore-msg">
						Showing <strong id="kn-events-loadmore-count"><?= $eventCount ?></strong>
						event<span id="kn-events-loadmore-plural"><?= $eventCount === 1 ? '' : 's' ?></span>
						in the next <strong id="kn-events-loadmore-months">12</strong> months.
					</span>
					<?php if (!empty($HasMoreEvents)): ?>
					<a href="#" class="kn-events-loadmore-link" id="kn-events-loadmore-link" onclick="knLoadMoreEvents(event); return false;">Load more <i class="fas fa-chevron-down" style="font-size:10px;margin-left:3px"></i></a>
					<?php endif; ?>
				</div>
				</div><!-- /kn-events-list-view -->

				<?php /* [TOURNAMENTS HIDDEN] */ ?>
			</div>


			<!-- Map Tab -->
			<div class="kn-tab-panel" id="kn-tab-map" style="display:none">
				<?php if (count($knMapLocations) > 0): ?>
					<div id="kn-map-loading" class="kn-map-loading">
						<i class="fas fa-spinner fa-spin" style="font-size:22px"></i>
						Loading map&hellip;
					</div>
					<div id="kn-map-container" style="display:none">
						<div class="kn-map-layout">
							<div class="kn-map-wrap">
								<div id="kn-map"></div>
							</div>
							<div class="kn-map-sidebar-wrap">
								<div class="kn-map-sidebar-card" id="kn-map-sidebar-card">
									<div class="kn-map-sidebar-empty" id="kn-map-sidebar-empty">
										<div class="kn-map-sidebar-empty-icon"><i class="fas fa-map-marker-alt"></i></div>
										<p>Click any park pin to see details.</p>
									</div>
									<div id="kn-map-sidebar-park" style="display:none; flex-direction:column; flex:1;">
										<div class="kn-park-hero" id="kn-park-hero"></div>
										<div class="kn-park-body" id="kn-park-body"></div>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php else: ?>
					<div class="kn-empty">No park location data available</div>
				<?php endif; ?>
			</div>

			<!-- Principalities Tab (only rendered if applicable) -->
			<?php if (!$IsPrinz && count($principalityList) > 0): ?>
				<div class="kn-tab-panel" id="kn-tab-principalities" style="display:none">
					<?php foreach ($principalityList as $prinz): ?>
						<div class="kn-prinz-row">
							<img class="kn-prinz-heraldry"
								loading="lazy"
								src="<?= HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prinz['KingdomId'])) ?>"
								onerror="this.src='<?= HTTP_KINGDOM_HERALDRY ?>0000.jpg'"
								alt="">
							<div class="kn-prinz-name">
								<a href="<?= UIR ?>Kingdom/profile/<?= $prinz['KingdomId'] ?>"><?= htmlspecialchars($prinz['Name']) ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Reports Tab -->
			<div class="kn-tab-panel" id="kn-tab-reports" style="display:none">
				<?php if (!$IsLoggedIn): ?>
				<div style="background:var(--ork-alert-info-bg,#eaf4fb);border:1px solid var(--ork-alert-info-border,#b0d4ea);border-radius:4px;padding:8px 14px;margin-bottom:10px;font-size:0.9em;color:var(--ork-alert-info-text,#1a5276);">
					<i class="fas fa-info-circle"></i> <a href="<?= UIR ?>Login" style="color:var(--ork-alert-info-text,#1a5276);font-weight:600;">Log in</a> to see the full list of available reports.
				</div>
				<?php endif; ?>
				<div class="pk-reports-mobile-notice">
					<i class="fas fa-info-circle"></i>
					<span>Some reports may not display as expected on mobile. For best results, view reports on a full screen device.</span>
				</div>
				<div class="kn-reports-grid">

					<div class="kn-report-group">
						<h5><i class="fas fa-users"></i> Players</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/roster/Kingdom&id=<?= $kingdom_id ?>">Player Roster</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/active/Kingdom&id=<?= $kingdom_id ?>">Active Players</a></li>
							<li><a href="<?= UIR ?>Reports/dues/Kingdom&id=<?= $kingdom_id ?>">Dues Paid</a></li>
							<li><a href="<?= UIR ?>Reports/waivered/Kingdom&id=<?= $kingdom_id ?>">Waivered</a></li>
							<li><a href="<?= UIR ?>Reports/unwaivered/Kingdom&id=<?= $kingdom_id ?>">Unwaivered</a></li>
							<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspended</a></li>
							<li><a href="<?= UIR ?>Reports/active_duespaid/Kingdom&id=<?= $kingdom_id ?>">Player Attendance</a></li>
							<li><a href="<?= UIR ?>Reports/active_waivered_duespaid/Kingdom&id=<?= $kingdom_id ?>">Waivered Attendance</a></li>
							<?php if (in_array((int)$kingdom_id, [3, 4, 6, 10, 12, 14, 17, 19, 20, 24, 25, 27, 31, 36, 38])): ?><li><a href="<?= UIR ?>Reports/voting_eligible/Kingdom&id=<?= $kingdom_id ?>">Voting Eligible</a></li><?php endif; ?>
							<li><a href="<?= UIR ?>Reports/reeve/Kingdom&id=<?= $kingdom_id ?>">Reeve Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/corpora/Kingdom&id=<?= $kingdom_id ?>">Corpora Qualified</a></li>
							<li><a href="<?= UIR ?>Reports/player_status_reconciliation/Kingdom&id=<?= $kingdom_id ?>">Player Status Reconciliation</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/kingdom_officer_directory&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-crown"></i> Park Officer Directory</a></li>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-medal"></i> Awards</h5>
						<ul>
							<?php if ($IsLoggedIn && (!empty($AwardRecsPublic) || !empty($CanManageKingdom))): ?>
							<li><a href="<?= UIR ?>Reports/player_award_recommendations&KingdomId=<?= $kingdom_id ?>">Recommendations</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/knights_and_masters&KingdomId=<?= $kingdom_id ?>">Knights &amp; Masters</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/knights_list&KingdomId=<?= $kingdom_id ?>">Knights</a></li>
							<li><a href="<?= UIR ?>Reports/masters_list&KingdomId=<?= $kingdom_id ?>">Masters</a></li>
							<li><a href="<?= UIR ?>Reports/player_awards&Ladder=8&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?>-level Awards</a></li>
							<li><a href="<?= UIR ?>Reports/class_masters&KingdomId=<?= $kingdom_id ?>">Class Masters/Paragons</a></li>
							<li><a href="<?= UIR ?>Reports/ladder_grid&KingdomId=<?= $kingdom_id ?>">Ladder Award Grid</a></li>
							<li><a href="<?= UIR ?>Reports/guilds&KingdomId=<?= $kingdom_id ?>"><?= $entityLabel ?> Guilds</a></li>
							<li><a href="<?= UIR ?>Reports/custom_awards&KingdomId=<?= $kingdom_id ?>">Custom Awards</a></li>
							<li><a href="<?= UIR ?>Reports/beltline_explorer&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-sitemap"></i> Beltline Explorer</a></li>
							<?php endif; ?>
						</ul>
					</div>

					<div class="kn-report-group">
						<h5><i class="fas fa-calendar-check"></i> Attendance</h5>
						<ul>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Weeks/1">Past Week</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/1">Past Month</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/3">Past 3 Months</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/6">Past 6 Months</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/Months/12">Past 12 Months</a></li>
							<li><a href="<?= UIR ?>Reports/attendance/Kingdom/<?= $kingdom_id ?>/All">All Time</a></li>
							<?php endif; ?>
							<li><a href="<?= UIR ?>Reports/event_attendance/Kingdom/<?= $kingdom_id ?>"><i class="fas fa-calendar-alt"></i> Event Attendance</a></li>
							<?php if ($IsLoggedIn): ?>
							<li><a href="<?= UIR ?>Reports/park_attendance_explorer"><i class="fas fa-chart-bar"></i> Park Attendance Explorer</a></li>
							<li><a href="<?= UIR ?>Reports/new_player_attendance"><i class="fas fa-user-plus"></i> New Player Attendance</a></li>
							<?php endif; ?>
						</ul>
					</div>

					<?php if ($IsLoggedIn): ?>
					<div class="kn-report-group">
						<h5><i class="fas fa-ellipsis-h"></i> Other</h5>
						<ul>
							<li><a href="<?= UIR ?>Reports/parkheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Parks</a></li>
							<li><a href="<?= UIR ?>Reports/playerheraldry/<?= $kingdom_id ?>"><?= $entityLabel ?> Heraldry, Players</a></li>
							<li><a href="<?= UIR ?>Reports/park_distance_matrix&KingdomId=<?= $kingdom_id ?>"><i class="fas fa-th"></i> Park Distance Matrix</a></li>
						</ul>
					</div>
					<?php endif; ?>

					<div class="kn-report-group">
						<h5><i class="fas fa-search"></i> Find</h5>
						<ul>
							<li><a href="<?= UIR ?>Search/kingdom/<?= $kingdom_id ?>">Players</a></li>
							<li><a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Companies &amp; Households</a></li>
							<li><a href="<?= UIR ?>Search/event&KingdomId=<?= $kingdom_id ?>">Events</a></li>
							<li><a href="<?= UIR ?>Unit/unitlist&KingdomId=<?= $kingdom_id ?>">Unit List</a></li>
						</ul>
					</div>



				</div>
			</div>

		<!-- Admin Tab -->
		<?php if ($CanManageKingdom ?? false): ?>
		<div class="kn-tab-panel" id="kn-tab-admin" style="display:none">
			<div class="kn-report-cols">
				<div class="kn-report-group">
					<h5><i class="fas fa-users-cog"></i> Players</h5>
					<ul>
						<li><a href="#" onclick="knOpenAddPlayerModal();return false;">Create Player</a></li>
						<li><a href="#" onclick="knOpenMovePlayerModal();return false;">Move Player</a></li>
						<li><a href="#" onclick="knOpenMergePlayerModal();return false;">Merge Players</a></li>
						<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kingdom_id ?>">Suspensions</a></li>
					</ul>
				</div>
				<div class="kn-report-group">
					<h5><i class="fas fa-cog"></i> Kingdom</h5>
					<ul>
						<li><a href="<?= UIR ?>Admin/permissions/Kingdom/<?= $kingdom_id ?>">Roles &amp; Permissions</a></li>
						<li><a href="#" onclick="knOpenClaimParkModal();return false;">Claim Park</a></li>
					</ul>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- Recommendations Tab -->
		<?php if ($ShowRecsTab ?? false): ?>
		<div class="kn-tab-panel" id="kn-tab-recommendations" style="display:none">
			<?php if ($IsLoggedIn): ?>
			<div class="pk-tab-toolbar">
				<button class="kn-btn kn-btn-secondary" onclick="knOpenRecModal()">
					<i class="fas fa-star"></i> Recommend an Award
				</button>
			</div>
			<?php endif; ?>
			<?php if (empty($AwardRecommendations)): ?>
			<div class="pk-recs-empty">There are no open award recommendations for <?= htmlspecialchars($kingdom_name) ?>.</div>
			<?php else: ?>
			<?php if ($CanManageKingdom ?? false): ?>
			<div class="kn-rec-filter-bar">
				<button class="kn-rec-filter-btn kn-rec-filter-active" data-filter="open">Open Recs</button>
				<button class="kn-rec-filter-btn" data-filter="below">Below Recommended</button>
				<button class="kn-rec-filter-btn" data-filter="nonladder">Non-Ladder</button>
				<button class="kn-rec-filter-btn" data-filter="already">At or Above Recommended</button>
				<button class="kn-rec-filter-btn" data-filter="all">All</button>
				<span class="kn-rec-filter-info">
					<button class="kn-rec-filter-info-btn" type="button" aria-label="Filter help"><i class="fas fa-question-circle"></i></button>
					<div class="kn-rec-filter-popover">
						<h4>About These Filters</h4>
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
					<button class="kn-rec-export-btn" type="button" onclick="knRecPrint()"><i class="fas fa-print"></i> Print</button>
					<button class="kn-rec-export-btn" type="button" onclick="knRecCsv()"><i class="fas fa-download"></i> CSV</button>
				</span>
			</div>
			<?php endif; ?>
				<div class="pk-recs-table-wrap">
				<table id="kn-rec-table" class="pk-recs-table display">
					<thead>
						<tr>
							<th>Player</th>
							<th>Award</th>
							<th>Rank</th>
							<th data-short="Rec. By">Recommended By</th>
							<th>Date</th>
							<th>Notes</th>
							<?php if (!empty($IsLoggedIn)): ?><th style="width:1%;white-space:nowrap"></th><?php endif; ?>
						</tr>
					</thead>
					<tbody id="kn-recs-tbody">
					<?php foreach ($AwardRecommendations as $rec): ?>
					<tr class="pk-rec-row"
						data-rec-id="<?= (int)$rec['RecommendationsId'] ?>"
						data-filter="<?= !empty($rec['AlreadyHas']) ? 'already' : ((int)$rec['Rank'] > 0 ? 'below' : 'nonladder') ?>">
						<td><a href="<?= UIR ?>Player/profile/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona']) ?></a></td>
						<td><?= htmlspecialchars($rec['AwardName']) ?></td>
						<td style="white-space:nowrap">
							<?= (int)$rec['Rank'] > 0 ? (int)$rec['Rank'] : '&mdash;' ?>
							<?php if (!empty($rec['AlreadyHas'])): ?>
							<span class="pk-rec-has-tip"
								title="<?= (int)$rec['Rank'] > 0 ? 'Player is currently at rank ' . (int)$rec['CurrentRank'] . ' as of ' . htmlspecialchars($rec['CurrentRankDate'] ?? '') : 'Player already has this award (granted ' . htmlspecialchars($rec['CurrentRankDate'] ?? 'unknown date') . ')' ?>">
								<i class="fas fa-info-circle"></i>
							</span>
							<?php endif; ?>
						</td>
						<td><?php if (!empty($rec['RecommendedById'])): ?><a href="<?= UIR ?>Player/profile/<?= (int)$rec['RecommendedById'] ?>"><?= htmlspecialchars($rec['RecommendedByName']) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
						<td><?= htmlspecialchars($rec['DateRecommended']) ?></td>
						<td class="pk-rec-notes"><?php if (!empty($rec['Reason'])): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($rec['Reason'], 0, 50)) ?><?php if (mb_strlen($rec['Reason']) > 50): ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($rec['Reason'], 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span><?php endif; ?></span><?php else: ?>&mdash;<?php endif; ?>
							<?php if (!empty($rec['ViewerCanEditReason'])): ?>
							<button class="rs-edit-reason-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-reason="<?= htmlspecialchars($rec['Reason'] ?? '', ENT_QUOTES) ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your reason"><i class="fas fa-pen"></i></button>
							<?php endif; ?>
							<?php if (!empty($rec['Seconds']) && is_array($rec['Seconds'])): ?>
							<div class="rs-seconds">
								<?php foreach ($rec['Seconds'] as $sec): ?>
								<div class="rs-second"><i class="fas fa-thumbs-up" style="color:#48bb78;font-size:10px"></i><a class="rs-supporter" href="<?= UIR ?>Player/profile/<?= (int)$sec['SupporterMundaneId'] ?>"><?= htmlspecialchars($sec['SupporterName'] ?? '') ?></a><?php if (!empty($sec['Notes'])): $_sn = $sec['Notes']; ?><span class="rs-notes">&mdash; "<?php if (mb_strlen($_sn) > 50): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($_sn, 0, 50)) ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($_sn, 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span></span><?php else: ?><?= htmlspecialchars($_sn) ?><?php endif; ?>"</span><?php else: ?><span class="rs-notes-empty">&mdash; (no comment)</span><?php endif; ?><?php $_canWithdrawSec = !empty($sec['IsMine']) || ($CanManageKingdom ?? false); if (!empty($sec['IsMine']) || $_canWithdrawSec): ?> <span class="rs-second-actions"><?php if (!empty($sec['IsMine'])): ?><button class="rs-second-edit" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-notes="<?= htmlspecialchars($sec['Notes'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your notes"><i class="fas fa-pen"></i></button><?php endif; ?><?php if ($_canWithdrawSec): ?><button class="rs-second-withdraw" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-supporter="<?= htmlspecialchars($sec['SupporterName'] ?? '', ENT_QUOTES) ?>" data-rstip="<?= !empty($sec['IsMine']) ? 'Withdraw your second' : 'Remove this second' ?>"><i class="fas fa-times"></i></button><?php endif; ?></span><?php endif; ?></div>
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
							<?php if ($CanManageKingdom ?? false): ?>
							<button class="pk-btn pk-btn-primary pk-rec-grant-btn"
								data-rec="<?= htmlspecialchars(json_encode(['RecommendationsId'=>(int)$rec['RecommendationsId'],'MundaneId'=>(int)$rec['MundaneId'],'Persona'=>$rec['Persona'],'KingdomAwardId'=>(int)$rec['KingdomAwardId'],'Rank'=>(int)$rec['Rank'],'Reason'=>$rec['Reason']??''])) ?>">
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
			<?php if ($CanManageKingdom ?? false): ?>
			<div class="pk-deleted-recs" id="kn-deleted-recs" data-loaded="0">
				<button type="button" class="pk-deleted-recs-toggle" id="kn-deleted-recs-toggle" aria-expanded="false">
					<span class="pk-deleted-recs-caret">&#9654;</span>
					<span class="pk-deleted-recs-toggle-label">Show Deleted Recommendations</span>
					<span class="pk-deleted-recs-count" id="kn-deleted-recs-count" style="display:none">0</span>
				</button>
				<div class="pk-deleted-recs-body" id="kn-deleted-recs-body" style="display:none">
					<div class="pk-deleted-recs-loading" id="kn-deleted-recs-loading">Loading&hellip;</div>
					<div class="pk-deleted-recs-empty" id="kn-deleted-recs-empty" style="display:none">No deleted recommendations.</div>
					<div class="pk-deleted-recs-search-wrap" style="display:none">
						<i class="fas fa-search"></i>
						<input type="text" class="pk-deleted-recs-search" placeholder="Search player, award, notes, or actor&hellip;" autocomplete="off">
					</div>
					<div class="pk-deleted-recs-no-match" style="display:none">No deleted recommendations match your search.</div>
					<div class="pk-deleted-recs-table-wrap" id="kn-deleted-recs-table-wrap" style="display:none">
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
							<tbody id="kn-deleted-recs-tbody"></tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- Players Tab -->
		<div class="kn-tab-panel" id="kn-tab-players" style="display:none">
			<div class="kn-players-toolbar">
				<span class="kn-players-toolbar-left" id="kn-players-summary">&hellip;</span>
				<div class="kn-players-toolbar-right">
					<div class="kn-player-search-wrap">
						<i class="fas fa-search kn-player-search-icon"></i>
						<input type="text" id="kn-player-search" class="kn-player-search-input" placeholder="Search all players&hellip;" autocomplete="off">
					</div>
					<button class="kn-view-btn" id="kn-active-only-btn" type="button" title="Show only members with sign-ins in the past 6 months"><i class="fas fa-filter"></i> Active only</button>
					<div class="kn-view-toggle">
						<button class="kn-view-btn kn-view-active" data-knview="cards"><i class="fas fa-th-large"></i> Cards</button>
						<button class="kn-view-btn" data-knview="list"><i class="fas fa-list"></i> List</button>
					</div>
					<?php if ($CanManageKingdom ?? false): ?>
					<div class="plr-action-group">
						<button class="plr-add-btn" onclick="knOpenAddPlayerModal()"><i class="fas fa-user-plus"></i> Create Player</button>
						<div class="plr-gear-wrap">
							<button class="plr-gear-btn" id="kn-plr-gear-btn" aria-label="Player actions" aria-expanded="false" onclick="var m=this.nextElementSibling;var o=m.classList.toggle('open');this.setAttribute('aria-expanded',o)"><i class="fas fa-cog"></i></button>
							<div class="plr-gear-menu" id="kn-plr-gear-menu">
								<button class="plr-gear-item" onclick="knOpenMovePlayerModal();document.getElementById('kn-plr-gear-menu').classList.remove('open')"><i class="fas fa-people-arrows"></i> Move Player</button>
								<button class="plr-gear-item" onclick="knOpenMergePlayerModal();document.getElementById('kn-plr-gear-menu').classList.remove('open')"><i class="fas fa-compress-alt"></i> Merge Players</button>
							</div>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<div id="kn-players-loading" style="text-align:center;padding:32px;color:#a0aec0"><i class="fas fa-spinner fa-spin"></i> Loading players&hellip;</div>
			<div id="kn-players-cards" style="display:none"></div>
			<div id="kn-players-list" style="display:none"></div>
		</div><!-- /kn-tab-players -->

		</div><!-- /kn-tabs -->
	</div><!-- /kn-main -->

</div><!-- /kn-layout -->

<!-- =============================================
     JavaScript
     ============================================= -->
<script>
var KnConfig = {
	uir:              '<?= UIR ?>',
	httpService:      '<?= HTTP_SERVICE ?>',
	kingdomId:        <?= (int)($kingdom_id ?? 0) ?>,
	kingdomName:      <?= json_encode($kingdom_name ?? '') ?>,
	canEdit:          <?= !empty($CanEditKingdom)   ? 'true' : 'false' ?>,
	canManage:        <?= !empty($CanManageKingdom) ? 'true' : 'false' ?>,
	canAddPark:       <?= !empty($CanAddPark) ? 'true' : 'false' ?>,
	loggedIn:         <?= !empty($IsLoggedIn) ? 'true' : 'false' ?>,
	parkTitleOptions: <?= json_encode($ParkTitleId_options ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkEditLookup:   <?= json_encode($CanManageKingdom ? array_values($park_edit_lookup ?? []) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerList:      <?= json_encode($CanManageKingdom ? array_map(function($o) { return ['OfficerRole' => $o['OfficerRole'], 'MundaneId' => (int)$o['MundaneId'], 'Persona' => $o['Persona']]; }, $officerList) : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	mapLocations:     <?= json_encode(array_values($knMapLocations ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	preloadOfficers:  <?= json_encode($PreloadOfficers ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	isOrkAdmin:      <?= !empty($IsOrkAdmin) ? 'true' : 'false' ?>,
	adminInfo:       <?= json_encode($AdminInfo       ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:     <?= json_encode($AdminConfig     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles: <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:     <?= json_encode($AdminAwards     ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	systemAwards:    <?= json_encode($SystemAwards    ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminRecsPublic: <?= !empty($AwardRecsPublic) ? 'true' : 'false' ?>,
};
</script>
<?php if ($IsLoggedIn): ?>
<div id="kn-award-overlay">
	<div class="kn-modal-box">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title" id="kn-award-modal-title"><i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award</h3>
			<button class="kn-modal-close-btn" id="kn-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body" id="kn-award-modal-body">
			<div class="kn-award-success" id="kn-award-success" style="display:none">
				<i class="fas fa-check-circle"></i> Award saved!
			</div>
			<div class="kn-form-error" id="kn-award-error"></div>

			<!-- Award Type Toggle -->
			<div class="kn-award-type-row">
				<button type="button" class="kn-award-type-btn kn-active" id="kn-award-type-awards">
					<i class="fas fa-medal" style="margin-right:5px"></i>Awards
				</button>
				<button type="button" class="kn-award-type-btn" id="kn-award-type-officers">
					<i class="fas fa-crown" style="margin-right:5px"></i>Officer Titles
				</button>
				<button type="button" class="kn-award-type-btn" id="kn-award-type-achievements">
					<i class="fas fa-star" style="margin-right:5px"></i>Achievement Titles
				</button>
				<button type="button" class="kn-award-type-btn" id="kn-award-type-associations">
					<i class="fas fa-handshake" style="margin-right:5px"></i>Associations
				</button>
			</div>

			<!-- Player search -->
			<div class="kn-acct-field">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-award-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-award-player-id" value="" />
				<div class="kn-ac-results" id="kn-award-player-results"></div>
			</div>

			<!-- Award Select -->
			<div class="kn-acct-field">
				<label for="kn-award-select" id="kn-award-select-label">Award <span style="color:#e53e3e">*</span></label>
				<select id="kn-award-select" name="KingdomAwardId">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
				<div class="kn-award-info-line" id="kn-award-info-line"></div>
			</div>

			<!-- Custom Award Name -->
			<div class="kn-acct-field" id="kn-award-custom-row" style="display:none">
				<label for="kn-award-custom-name">Custom Award Name</label>
				<input type="text" id="kn-award-custom-name" maxlength="64" placeholder="Enter custom award name..." />
			</div>

			<!-- Rank Picker -->
			<div class="kn-acct-field" id="kn-award-rank-row" style="display:none">
				<label>Rank <span id="kn-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
				<div class="kn-rank-pills-wrap" id="kn-rank-pills"></div>
				<input type="hidden" id="kn-award-rank-val" value="" />
			</div>

			<!-- Date -->
			<div class="kn-acct-field">
				<label for="kn-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" id="kn-award-date" />
			</div>

			<!-- Given By -->
			<div class="kn-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="kn-officer-chips" id="kn-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="kn-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="kn-award-givenby-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-award-givenby-id" value="" />
				<div class="kn-ac-results" id="kn-award-givenby-results"></div>
				<div id="kn-award-givenby-note" style="display:none;margin-top:6px;padding:8px 12px;background:#ebf8ff;border:1px solid #bee3f8;border-radius:6px;color:#2b6cb0;font-size:12px;line-height:1.5;"><i class="fas fa-info-circle" style="margin-right:5px"></i>This should reflect the person granting the association. For example, if a Knight is taking a Squire, enter the Knight's name here.</div>
			</div>

			<!-- Given At -->
			<div class="kn-acct-field">
				<label>Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="kn-award-givenat-text"
				       placeholder="Search park, kingdom, or event..."
				       autocomplete="off"
				       value="<?= htmlspecialchars($kingdom_name ?? '') ?>" />
				<div class="kn-ac-results" id="kn-award-givenat-results"></div>
				<input type="hidden" id="kn-award-park-id" value="0" />
				<input type="hidden" id="kn-award-kingdom-id" value="<?= (int)$kingdom_id ?>" />
				<input type="hidden" id="kn-award-event-id" value="0" />
			</div>

			<!-- Note -->
			<div class="kn-acct-field">
				<label for="kn-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea id="kn-award-note" rows="3" maxlength="400" placeholder="What was this award given for?"></textarea>
				<span class="kn-char-count" id="kn-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-award-cancel">Close</button>
			<div style="display:flex;gap:8px">
				<button class="kn-btn kn-btn-secondary" id="kn-award-save-same" disabled>
					<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>Same Player
				</button>
				<button class="kn-btn kn-btn-primary" id="kn-award-save-new" disabled>
					<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>New Player
				</button>
			</div>
		</div>
	</div>
</div>

<!-- Recommend Award Modal -->
<div id="kn-rec-overlay">
	<div class="kn-modal-box">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-star" style="margin-right:8px;color:#d69e2e"></i>Make a Recommendation</h3>
			<button class="kn-modal-close-btn" id="kn-rec-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div class="pk-form-error" id="kn-rec-error" style="display:none"></div>
			<div class="pk-award-success" id="kn-rec-success" style="display:none">
				<i class="fas fa-check-circle"></i> Recommendation submitted!
			</div>
			<div class="pk-acct-field">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-rec-player-text" placeholder="Search by persona..." autocomplete="off" />
				<input type="hidden" id="kn-rec-player-id" value="" />
				<div class="pk-ac-results" id="kn-rec-player-results"></div>
			</div>
			<div class="pk-acct-field">
				<label for="kn-rec-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="kn-rec-award-select">
					<option value="">Select award...</option>
					<?= $AwardOptions ?>
				</select>
				<div id="kn-rec-award-desc" class="pn-rec-award-desc" style="display:none"></div>
			</div>
			<div class="pk-acct-field" id="kn-rec-rank-row" style="display:none">
				<label>Rank <span id="kn-rec-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<div class="pk-rank-pills-wrap" id="kn-rec-rank-pills"></div>
				<input type="hidden" id="kn-rec-rank-val" value="" />
			</div>
			<div class="pk-acct-field">
				<label for="kn-rec-reason">Reason <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-rec-reason" maxlength="400" placeholder="Why should this player receive this award?" />
				<span class="pk-char-count" id="kn-rec-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="pk-btn-ghost" id="kn-rec-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-rec-submit" disabled>
				<i class="fas fa-paper-plane"></i> Submit Recommendation
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($CanManageKingdom ?? false): ?>

<div class="kn-emod-overlay" id="kn-event-modal">
	<div class="kn-emod-box">
		<div class="kn-emod-header">
			<h3><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="kn-emod-close" onclick="knCloseEventModal()">&times;</button>
		</div>
		<div class="kn-emod-body">
			<div class="kn-emod-field">
				<label class="kn-emod-label">Event Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="kn-emod-input" id="kn-event-name" autocomplete="off" placeholder="e.g. Summer Midreign">
			</div>
			<div id="kn-emod-date-row" style="display:none;font-size:12px;color:var(--ork-alert-info-text,#2b6cb0);margin-top:8px;padding:5px 8px;background:var(--ork-alert-info-bg,#ebf8ff);border-radius:5px;border-left:3px solid var(--ork-alert-info-border,#90cdf4)">
				<i class="fas fa-calendar-alt" style="margin-right:5px"></i><span id="kn-emod-date-text"></span>
			</div>
			<div class="kn-emod-field" style="margin-top:12px">
				<label class="kn-emod-label">Host Park <span style="color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0">(optional — leave blank for a kingdom-level event)</span></label>
				<input type="text" class="kn-emod-input" id="kn-event-park-name" autocomplete="off" placeholder="Search parks…">
				<input type="hidden" id="kn-event-park-id">
			</div>
			<div class="kn-emod-feedback" id="kn-emod-feedback" style="display:none"></div>
		</div>
		<div class="kn-emod-footer">
			<button class="kn-emod-btn-cancel" onclick="knCloseEventModal()">Cancel</button>
			<button class="kn-emod-btn-go" id="kn-emod-go-btn" onclick="knCreateEvent()" disabled>
				Create Event <i class="fas fa-arrow-right"></i>
			</button>
		</div>
	</div>
</div>

<!-- Add Park Modal -->
<div id="kn-addpark-overlay">
	<div class="kn-modal-box" style="width:460px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-plus-circle" style="margin-right:8px;color:#276749"></i>Add Park</h3>
			<button class="kn-modal-close-btn" id="kn-addpark-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-addpark-feedback" style="display:none"></div>
			<div class="kn-acct-field">
				<label for="kn-addpark-name">Park Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-addpark-name" placeholder="e.g. Eternal Darkness" maxlength="128" />
			</div>
			<div class="kn-acct-field">
				<label for="kn-addpark-abbr">Abbreviation <span style="color:#e53e3e">*</span> <span style="color:#a0aec0;font-size:11px;text-transform:none;letter-spacing:0">(up to 4 alphanumeric characters)</span></label>
				<input type="text" id="kn-addpark-abbr" placeholder="e.g. ED" maxlength="4" />
				<div id="kn-addpark-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
			</div>
			<div class="kn-acct-field">
				<label for="kn-addpark-title">Park Type <span style="color:#e53e3e">*</span></label>
				<select id="kn-addpark-title">
					<option value="">— select type —</option>
					<?php foreach ($ParkTitleId_options ?? [] as $ptId => $ptTitle): ?>
					<option value="<?= (int)$ptId ?>"><?= htmlspecialchars($ptTitle) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-addpark-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-addpark-submit">
				<i class="fas fa-plus"></i> Create Park
			</button>
		</div>
	</div>
</div>

<!-- Edit Park Modal -->
<div id="kn-editpark-overlay">
	<div class="kn-modal-box" style="width:460px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#276749"></i>Edit Park</h3>
			<button class="kn-modal-close-btn" id="kn-editpark-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-editpark-feedback" style="display:none"></div>
			<input type="hidden" id="kn-editpark-id" />
			<div class="kn-acct-field">
				<label for="kn-editpark-name">Park Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-editpark-name" maxlength="128" />
			</div>
			<div class="kn-acct-field">
				<label for="kn-editpark-abbr">Abbreviation <span style="color:#e53e3e">*</span> <span style="color:#a0aec0;font-size:11px;text-transform:none;letter-spacing:0">(up to 4 alphanumeric characters)</span></label>
				<input type="text" id="kn-editpark-abbr" maxlength="4" />
				<div id="kn-editpark-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
			</div>
			<div class="kn-acct-field">
				<label for="kn-editpark-title">Park Type <span style="color:#e53e3e">*</span></label>
				<select id="kn-editpark-title">
					<option value="">— select type —</option>
					<?php foreach ($ParkTitleId_options ?? [] as $ptId => $ptTitle): ?>
					<option value="<?= (int)$ptId ?>"><?= htmlspecialchars($ptTitle) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="kn-acct-field">
				<label style="display:flex;align-items:center;gap:10px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;font-weight:600;color:#4a5568;">
					<input type="checkbox" id="kn-editpark-active" style="width:16px;height:16px;cursor:pointer;" />
					Active (uncheck to mark Retired)
				</label>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-editpark-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-editpark-submit">
				<i class="fas fa-save"></i> Save Changes
			</button>
		</div>
	</div>
</div>

<!-- Heraldry Modal -->
<?php if (!empty($CanManageKingdom)): ?>
<div id="kn-heraldry-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-camera" style="margin-right:8px;color:#4a5568"></i>Change Heraldry</h3>
			<button class="pn-modal-close-btn" id="kn-heraldry-close-btn">&times;</button>
		</div>
		<div class="pn-modal-body" id="kn-heraldry-step-select">
			<label class="pn-upload-area" for="kn-heraldry-file-input" style="cursor:pointer;display:block;border:2px dashed #cbd5e0;border-radius:8px;padding:28px 20px;text-align:center;color:#718096">
				<i class="fas fa-image" style="font-size:28px;margin-bottom:8px;display:block"></i>
				Click to select an image<br><small style="color:#a0aec0">PNG, JPG, or GIF</small>
			</label>
			<input type="file" id="kn-heraldry-file-input" accept="image/png,image/jpeg,image/gif" style="display:none">
			<?php if ($hasHeraldry): ?>
			<div style="text-align:center;margin-top:14px">
				<button type="button" id="kn-heraldry-remove-btn" class="pn-btn pn-btn-ghost" style="color:#e53e3e;border-color:#feb2b2;font-size:12px;padding:4px 14px">
					<i class="fas fa-trash"></i> Remove Heraldry
				</button>
				<div id="kn-heraldry-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:var(--ork-alert-danger-bg,#fff5f5);border:1px solid var(--ork-alert-danger-border,#fed7d7);border-radius:6px;font-size:13px;color:var(--ork-alert-danger-text,#c53030);text-align:left">
					Remove this kingdom's heraldry image?
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('kn-heraldry-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm kn-btn-danger" onclick="knDoRemoveHeraldry()">Yes, Remove</button>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<div class="pn-modal-body" id="kn-heraldry-step-uploading" style="display:none;text-align:center;padding:32px 20px">
			<i class="fas fa-spinner fa-spin" style="font-size:28px;color:#718096;margin-bottom:10px;display:block"></i>
			<div style="color:#718096;font-size:14px">Uploading…</div>
		</div>
		<div class="pn-modal-body" id="kn-heraldry-step-done" style="display:none;text-align:center;padding:32px 20px">
			<i class="fas fa-check-circle" style="font-size:28px;color:#38a169;margin-bottom:10px;display:block"></i>
			<div style="color:#38a169;font-size:14px;font-weight:600">Done!</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Edit Officers Modal -->
<div id="kn-editoff-overlay">
	<div class="kn-modal-box" style="width:520px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#744210"></i>Edit Officers</h3>
			<button class="kn-modal-close-btn" id="kn-editoff-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-editoff-feedback" style="display:none"></div>
			<p class="kn-editoff-hint">Search and select a player for each role. Leave a field empty to skip that role. Use <strong>Vacate</strong> to remove the current holder.</p>
			<div id="kn-editoff-rows">
				<!-- Built by JS from KnConfig.officerList -->
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-editoff-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-editoff-submit">
				<i class="fas fa-save"></i> Save Officers
			</button>
		</div>
	</div>
</div>

<!-- Kingdom Admin Overlay -->
<div id="kn-admin-overlay">
	<div class="kn-modal-box" style="width:700px;max-width:calc(100vw - 40px);">

		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-cog" style="margin-right:8px;color:#2b6cb0"></i>Kingdom Administration</h3>
			<button class="kn-modal-close-btn" id="kn-admin-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="kn-modal-body" id="kn-admin-body">

			<!-- ── Panel: Kingdom Details ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-details" aria-expanded="true">
					<span><i class="fas fa-edit" style="margin-right:6px;color:#a0aec0"></i>Kingdom Details</span>
					<i class="fas fa-chevron-down kn-admin-chevron kn-admin-chevron-open" id="kn-admin-chev-details"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-details">
					<div id="kn-admin-details-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-field">
						<label for="kn-admin-name">Kingdom Name</label>
						<input type="text" id="kn-admin-name" value="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>">
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-abbr">Abbreviation <span class="kn-admin-hint-inline">(letters &amp; numbers only)</span></label>
						<input type="text" id="kn-admin-abbr" value="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" maxlength="8">
						<div id="kn-admin-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-description" style="display:flex;align-items:center;gap:6px;">
						Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
						<button type="button" class="kn-md-help-btn" onclick="document.getElementById('kn-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
					</label>
						<textarea id="kn-admin-description" rows="4" style="resize:vertical" data-original="<?= htmlspecialchars($AdminInfo['Description'] ?? '') ?>"><?= htmlspecialchars($AdminInfo['Description'] ?? '') ?></textarea>
					</div>
					<div class="kn-admin-field">
						<label for="kn-admin-url">Website URL <span class="kn-admin-hint-inline">(optional)</span></label>
						<input type="url" id="kn-admin-url" value="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" placeholder="https://">
					</div>
					<button class="kn-admin-save-btn" id="kn-admin-details-save"<?= (empty($AdminInfo['Name']) || empty($AdminInfo['Abbreviation'])) ? ' disabled' : '' ?>>
						<i class="fas fa-save"></i> Save Details
					</button>
				</div>
			</div>

			<!-- ── Panel: Principality (ORK Admins only, shown only when this entity is a principality) ── -->
		<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
		<div class="kn-admin-panel" id="kn-admin-panel-prinz">
			<button class="kn-admin-panel-hdr" id="kn-admin-hdr-prinz" aria-expanded="false">
				<span><i class="fas fa-crown" style="margin-right:6px;color:#a0aec0"></i>Principality Status</span>
				<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-prinz"></i>
			</button>
			<div class="kn-admin-panel-body" id="kn-admin-body-prinz" style="display:none">
				<div id="kn-admin-prinz-feedback" class="kn-admin-feedback" style="display:none"></div>
				<p style="margin:0 0 12px;font-size:13px;color:#4a5568">
					This is a <strong>Principality</strong> sponsored by
					<strong><?= htmlspecialchars($AdminInfo['ParentKingdomName']) ?></strong>.
				</p>
				<div class="kn-admin-field cp-field-ac" id="kn-admin-prinz-sponsor-row">
					<label>Change Sponsor Kingdom</label>
					<input type="text" id="kn-admin-prinz-parent-name" autocomplete="off"
						placeholder="Search kingdoms…"
						value="<?= htmlspecialchars($AdminInfo['ParentKingdomName']) ?>">
					<input type="hidden" id="kn-admin-prinz-parent-id"
						value="<?= (int)($AdminInfo['ParentKingdomId'] ?? 0) ?>">
					<div class="kn-ac-results" id="kn-admin-prinz-parent-results"></div>
				</div>
				<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
					<button class="kn-admin-save-btn" id="kn-admin-prinz-sponsor-save">
						<i class="fas fa-save"></i> Save Sponsor
					</button>
					<button class="kn-admin-save-btn" id="kn-admin-prinz-promote"
						style="background:#c05621;border-color:#c05621">
						<i class="fas fa-crown"></i> Convert to Kingdom
					</button>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- ── Panel: Configuration ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-config" aria-expanded="false">
					<span><i class="fas fa-sliders-h" style="margin-right:6px;color:#a0aec0"></i>Configuration</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-config"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-config" style="display:none">
					<div id="kn-admin-config-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-field kn-admin-recs-visibility-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid var(--ork-border,#e2e8f0);margin-bottom:12px">
						<div>
							<div style="font-size:13px;font-weight:600;color:var(--ork-text,#2d3748)">Recommendation Visibility</div>
							<div style="font-size:12px;color:var(--ork-text-muted,#718096);margin-top:3px">When Private, besides the monarchy, only the submitter can see their own recommendations.</div>
						</div>
						<select id="kn-admin-recs-public" style="font-size:13px;border:1.5px solid var(--ork-border,#e2e8f0);border-radius:6px;padding:5px 8px;flex-shrink:0">
							<option value="1" <?= !empty($AwardRecsPublic) ? 'selected' : '' ?>>Public</option>
							<option value="0" <?= empty($AwardRecsPublic) ? 'selected' : '' ?>>Private (monarchy and submitters only)</option>
						</select>
					</div>
					<div id="kn-admin-recs-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div id="kn-admin-config-rows">
						<!-- Built by JS from KnConfig.adminConfig -->
					</div>
					<button class="kn-admin-save-btn" id="kn-admin-config-save">
						<i class="fas fa-save"></i> Save Configuration
					</button>
				</div>
			</div>

			<!-- ── Panel: Park Titles ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-titles" aria-expanded="false">
					<span><i class="fas fa-flag" style="margin-right:6px;color:#a0aec0"></i>Park Titles</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-titles"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-titles" style="display:none">
					<div id="kn-admin-titles-feedback" class="kn-admin-feedback" style="display:none"></div>
					<table class="kn-admin-table" id="kn-admin-titles-table">
						<thead>
							<tr>
								<th>Title</th>
								<th><span class="kn-admin-th-tip" title="Title Class determines rank precedence. Higher values = higher rank (e.g. 20=Knight, 30=Lord, 50=Baron, 90=Duke).">Class <i class="fas fa-question-circle" style="font-size:9px;color:#a0aec0;cursor:help"></i></span></th>
								<th>Min Att.</th>
								<th>Cutoff</th>
								<th>Period</th>
								<th>Len.</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="kn-admin-titles-tbody">
							<!-- Built by JS -->
						</tbody>
						<tfoot>
							<tr class="kn-admin-titles-newrow">
								<td><input type="text"   class="kn-admin-tinput"   data-field="Title"             placeholder="New title…"></td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="Class"             value="0" min="0"></td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="MinimumAttendance" value="0" min="0"></td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="MinimumCutoff"     value="0" min="0"></td>
								<td>
									<select class="kn-admin-tselect" data-field="Period">
										<option value="month">Month</option>
										<option value="week">Week</option>
									</select>
								</td>
								<td><input type="number" class="kn-admin-tnumeric" data-field="Length"            value="1" min="1"></td>
								<td></td>
							</tr>
						</tfoot>
					</table>
					<button class="kn-admin-save-btn" id="kn-admin-titles-save">
						<i class="fas fa-save"></i> Save Park Titles
					</button>
				</div>
			</div>

			<!-- ── Panel: Awards ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-awards" aria-expanded="false">
					<span><i class="fas fa-award" style="margin-right:6px;color:#a0aec0"></i>Awards</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-awards"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-awards" style="display:none">
					<div id="kn-admin-awards-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-table-wrap"><table class="kn-admin-table kn-admin-awards-table">
						<thead>
							<tr>
								<th>Award Name</th>
								<th>Reign</th>
								<th>Month</th>
								<th>Title?</th>
								<th>Class</th>
								<th></th>
							</tr>
						</thead>
						<tbody id="kn-admin-awards-tbody">
							<!-- Built by JS -->
						</tbody>
					</table></div>
					<div class="kn-admin-add-award-wrap" id="kn-admin-add-award-wrap" style="display:none">
						<div class="kn-admin-add-award-title">Add Award Alias</div>
						<p class="kn-admin-form-hint">An award alias lets you create additional variations on existing system awards and titles. For example, the default system title of “Man-at-Arms” would have variations such as “Woman-at-Arms” or “Person-at-Arms.” You can add as many aliases as you would like.</p>
						<div class="kn-admin-field">
							<label>System Award</label>
							<div class="kn-admin-alias-picker-wrap" style="position:relative">
								<input type="hidden" id="kn-admin-new-award-id">
								<button type="button" class="kn-admin-alias-trigger" id="kn-admin-alias-trigger">
									<span class="kn-admin-alias-label">Select a system award&hellip;</span>
									<i class="fas fa-chevron-down" style="font-size:11px;opacity:.5"></i>
								</button>
								<div class="kn-admin-alias-dropdown" id="kn-admin-alias-dropdown" style="display:none">
									<input type="text" class="kn-admin-alias-search" id="kn-admin-alias-search" placeholder="Search awards&hellip;" autocomplete="off">
									<div class="kn-admin-alias-list" id="kn-admin-alias-list"></div>
								</div>
							</div>
						</div>
						<div class="kn-admin-award-row-fields">
							<div class="kn-admin-field kn-admin-field-grow">
								<label>Kingdom Name <span class="kn-admin-hint-inline">(your kingdom&rsquo;s name for this award)</span></label>
								<input type="text" id="kn-admin-new-award-name" placeholder="e.g. Order of the Warrior">
							</div>
							<div class="kn-admin-field">
								<label>Reign Limit</label>
								<input type="number" id="kn-admin-new-reign" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field">
								<label>Month Limit</label>
								<input type="number" id="kn-admin-new-month" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field kn-admin-field-center">
								<label>Title?</label>
								<input type="checkbox" id="kn-admin-new-istitle">
							</div>
							<div class="kn-admin-field">
								<label>Title Class <i class="fas fa-question-circle" title="Title Class determines rank precedence. Higher values = higher rank (e.g. 20=Knight, 30=Lord, 50=Baron, 90=Duke)." style="font-size:10px;color:#a0aec0;cursor:help"></i></label>
								<input type="number" id="kn-admin-new-tclass" min="0" value="0" style="width:64px" disabled>
							</div>
						</div>
						<div style="display:flex;gap:8px;margin-top:10px">
							<button class="kn-admin-save-btn" id="kn-admin-new-award-save">
								<i class="fas fa-plus"></i> Add Award Alias
							</button>
							<button class="kn-btn-ghost" id="kn-admin-new-award-cancel" style="font-size:13px">Cancel</button>
						</div>
					</div>
					<div class="kn-admin-add-award-wrap" id="kn-admin-add-custom-wrap" style="display:none">
						<div class="kn-admin-add-award-title">Add Kingdom-Specific Award</div>
						<p class="kn-admin-form-hint">A kingdom-specific award allows you to add awards only given out in your kingdom. For example, if your kingdom awards a custom award called “Order of the Key,” you can add it here so it can be marked in award records.</p>
						<div class="kn-admin-award-row-fields">
							<div class="kn-admin-field kn-admin-field-grow">
								<label>Award Name</label>
								<input type="text" id="kn-admin-custom-name" placeholder="e.g. Kingdom Spotlight">
							</div>
							<div class="kn-admin-field">
								<label>Reign Limit</label>
								<input type="number" id="kn-admin-custom-reign" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field">
								<label>Month Limit</label>
								<input type="number" id="kn-admin-custom-month" min="0" value="0" style="width:64px">
							</div>
							<div class="kn-admin-field kn-admin-field-center">
								<label>Title?</label>
								<input type="checkbox" id="kn-admin-custom-istitle">
							</div>
							<div class="kn-admin-field">
								<label>Title Class <i class="fas fa-question-circle" title="Title Class determines rank precedence. Higher values = higher rank (e.g. 20=Knight, 30=Lord, 50=Baron, 90=Duke)." style="font-size:10px;color:#a0aec0;cursor:help"></i></label>
								<input type="number" id="kn-admin-custom-tclass" min="0" value="0" style="width:64px" disabled>
							</div>
						</div>
						<div style="display:flex;gap:8px;margin-top:10px">
							<button class="kn-admin-save-btn" id="kn-admin-custom-save">
								<i class="fas fa-plus"></i> Add Award
							</button>
							<button class="kn-btn-ghost" id="kn-admin-custom-cancel" style="font-size:13px">Cancel</button>
						</div>
					</div>
					<div class="kn-admin-award-add-btns">
						<button class="kn-admin-add-btn" id="kn-admin-awards-add-btn">
							<i class="fas fa-plus"></i> Add Award Alias
						</button>
						<button class="kn-admin-add-btn" id="kn-admin-custom-add-btn">
							<i class="fas fa-plus"></i> Add Kingdom-Specific Award
						</button>
					</div>
				</div>
			</div>

			<!-- ── Panel: Edit Parks ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-parks" aria-expanded="false">
					<span><i class="fas fa-map-marker-alt" style="margin-right:6px;color:#a0aec0"></i>Edit Parks</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-parks"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-parks" style="display:none">
					<div id="kn-admin-parks-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-table-wrap">
						<table class="kn-admin-table kn-admin-parks-table">
							<thead>
								<tr>
									<th>Park Name</th>
									<th>Title</th>
									<th>Abbr</th>
									<th style="text-align:center">Active</th>
									<th></th>
								</tr>
							</thead>
							<tbody id="kn-admin-parks-tbody">
								<!-- Built by JS -->
							</tbody>
						</table></div>
					<button class="kn-admin-save-btn" id="kn-admin-parks-save">
						<i class="fas fa-save"></i> Save Parks
					</button>
				</div>
			</div>

			<?php if (!empty($CanAddPark)): ?>
			<!-- ── Panel: Operations ── -->
			<div class="kn-admin-panel">
				<button class="kn-admin-panel-hdr" id="kn-admin-hdr-ops" aria-expanded="false">
					<span><i class="fas fa-tools" style="margin-right:6px;color:#a0aec0"></i>Operations</span>
					<i class="fas fa-chevron-down kn-admin-chevron" id="kn-admin-chev-ops"></i>
				</button>
				<div class="kn-admin-panel-body" id="kn-admin-body-ops" style="display:none">
					<div id="kn-admin-ops-feedback" class="kn-admin-feedback" style="display:none"></div>
					<div class="kn-admin-ops-row">
						<div class="kn-admin-ops-info">
							<strong>Reset Waivers</strong>
							<p>Clears all waiver records for this <?= $IsPrinz ? 'principality' : 'kingdom' ?>. This action cannot be undone.</p>
						</div>
						<button class="kn-admin-ops-btn kn-admin-ops-btn-danger" id="kn-admin-reset-waivers-btn">
							<i class="fas fa-eraser"></i> Reset Waivers
						</button>
					</div>
					<?php if (!empty($IsOrkAdmin)):
						$isActive = ($AdminInfo['Active'] ?? 'Active') === 'Active'; ?>
					<div class="kn-admin-ops-row">
						<div class="kn-admin-ops-info">
							<strong>Active Status</strong>
							<p>This <?= $IsPrinz ? 'principality' : 'kingdom' ?> is currently <strong id="kn-admin-status-label"><?= $isActive ? 'Active' : 'Inactive' ?></strong>.</p>
						</div>
						<button class="kn-admin-ops-btn<?= $isActive ? ' kn-admin-ops-btn-danger' : '' ?>"
							id="kn-admin-status-toggle" data-active="<?= $isActive ? '1' : '0' ?>">
							<?php if ($isActive): ?>
								<i class="fas fa-ban"></i> Mark Inactive
							<?php else: ?>
								<i class="fas fa-check-circle"></i> Restore to Active
							<?php endif; ?>
						</button>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

		</div><!-- /.kn-modal-body -->

		<div class="kn-modal-footer" style="justify-content:flex-end">
			<button class="kn-btn-ghost" id="kn-admin-done-btn">Done</button>
		</div>

	</div>
</div>

<?php endif; ?>

<!-- Markdown Help Modal -->
<div id="kn-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-markdown" style="margin-right:8px;color:#2b6cb0"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('kn-md-help-overlay').classList.remove('kn-open')">&times;</button>
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

<!-- Confirmation Modal (shared) -->
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

<?php if ($CanManageKingdom ?? false): ?>
<!-- Add Player Modal -->
<div id="kn-addplayer-overlay">
	<div class="kn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
			<button class="kn-modal-close-btn" id="kn-addplayer-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-addplayer-feedback" class="plr-feedback" style="display:none"></div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Park <span class="plr-req">*</span></label>
					<select id="kn-addplayer-park">
						<option value="">— select park —</option>
					</select>
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Persona <span class="plr-req">*</span></label>
					<input type="text" id="kn-addplayer-persona" placeholder="In-game name">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>First Name</label>
					<input type="text" id="kn-addplayer-given" placeholder="Given name">
				</div>
				<div class="plr-field">
					<label>Last Name</label>
					<input type="text" id="kn-addplayer-surname" placeholder="Surname">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field plr-field-grow">
					<label>Email</label>
					<input type="email" id="kn-addplayer-email" placeholder="email@example.com">
					<div id="kn-addplayer-email-suggestion" class="esc-suggestion" role="alert">
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
					<input type="text" id="kn-addplayer-username" placeholder="min. 4 characters" autocomplete="new-password">
				</div>
				<div class="plr-field">
					<label>Password</label>
					<input type="password" id="kn-addplayer-password" placeholder="optional" autocomplete="new-password">
				</div>
			</div>
			<div class="plr-field-row">
				<div class="plr-field">
					<label>Restrict Mundane Name Visibility</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="kn-addplayer-restricted" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="kn-addplayer-restricted" value="1"> Yes</label>
					</div>
					<small style="display:block;color:var(--ork-text-muted);margin-top:4px">Hides the player's real name from searches and public displays. Use for members who prefer their mundane identity kept private.</small>
				</div>
				<div class="plr-field">
					<label>Waivered</label>
					<div class="plr-radio-row">
						<label class="plr-radio"><input type="radio" name="kn-addplayer-waivered" value="0" checked> No</label>
						<label class="plr-radio"><input type="radio" name="kn-addplayer-waivered" value="1"> Yes</label>
					</div>
				</div>
			</div>
			<div class="plr-field-row" id="kn-addplayer-waiver-row" style="display:none">
				<div class="plr-field plr-field-grow">
					<label>Waiver File <span class="plr-hint">(PDF, PNG, JPG, or GIF)</span></label>
					<input type="file" id="kn-addplayer-waiver" accept=".pdf,image/png,image/jpeg,image/gif">
				</div>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-addplayer-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-addplayer-submit">
				<i class="fas fa-user-plus"></i> Create Player
			</button>
		</div>
	</div>
</div>

<!-- Move Player Modal -->
<style>
.kn-mp-toggle { display:flex; background:#edf2f7; border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.kn-mp-toggle-btn {
	flex:1; padding:6px 8px; border:none; border-radius:4px; font-size:11px; font-weight:600;
	cursor:pointer; background:transparent; color:#718096; transition:background 0.15s,color 0.15s; white-space:nowrap;
}
.kn-mp-toggle-btn.kn-mp-active { background:#fff; color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
#kn-moveplayer-overlay .kn-modal-body { overflow:visible; }
#kn-moveplayer-overlay .kn-acct-field { position:relative; }
#kn-moveplayer-overlay .kn-ac-results { position:absolute; left:0; right:0; z-index:9999; }
/* Subscribe popover */
.kn-sub-wrap { position:relative; }
.kn-sub-pop {
	display:none !important; position:fixed; z-index:9000;
	background:var(--ork-card-bg); border:1px solid #e2e8f0; border-radius:8px;
	box-shadow:0 4px 16px rgba(0,0,0,0.12); padding:12px 14px; width:280px; font-size:13px;
}
.kn-sub-pop.kn-sub-open { display:block !important; }
.kn-sub-pop-title {
	font-weight:700; color:#2d3748; margin-bottom:8px; font-size:12px;
	text-transform:uppercase; letter-spacing:.05em;
}
.kn-sub-pop-row { display:flex; gap:4px; margin-bottom:8px; }
.kn-sub-url-input {
	flex:1; font-size:11px; padding:4px 6px; border:1px solid #e2e8f0;
	border-radius:4px; color:#4a5568; background:#f7fafc; min-width:0;
}
.kn-sub-copy-btn {
	padding:4px 8px; border:1px solid #e2e8f0; border-radius:4px;
	background:#edf2f7; cursor:pointer; color:#4a5568; font-size:12px;
}
.kn-sub-copy-btn:hover { background:#e2e8f0; }
.kn-sub-gcal-btn {
	display:block; text-align:center; background:#4285f4; color:#fff;
	border-radius:5px; padding:7px 10px; font-size:12px; font-weight:600; text-decoration:none;
}
.kn-sub-gcal-btn:hover { background:#3367d6; color:#fff; }
.kn-sub-webcal-btn {
	display:block; margin-top:6px; font-size:11px; color:#718096; text-align:center; text-decoration:none;
}
.kn-sub-webcal-btn:hover { color:#4a5568; }

/* ===================================================================
   DARK MODE OVERRIDES — Kingdomnew profile
   Activated by: html[data-theme="dark"]
   =================================================================== */
html[data-theme="dark"] .kn-stat-number { color: hsl(var(--kn-hue), var(--kn-sat), var(--ork-accent-lightness, 65%)); }
html[data-theme="dark"] .kn-stat-icon { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-stat-label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-card { background: var(--ork-card-bg, #2d3748) !important; border-color: var(--ork-border, #4a5568) !important; color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .kn-card-header { color: var(--ork-text); border-color: var(--ork-border); background: transparent; text-shadow: none; }
html[data-theme="dark"] .kn-officer-item { border-color: var(--ork-border); }
html[data-theme="dark"] .kn-officer-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-officer-name { color: var(--ork-text); }
html[data-theme="dark"] #theme_container .kn-officer-name a { color: hsl(calc(var(--kn-hue) + 35), 65%, var(--ork-accent-mid-lightness, 58%)); }
html[data-theme="dark"] #theme_container .kn-link-list a { color: hsl(calc(var(--kn-hue) + 35), 65%, var(--ork-accent-mid-lightness, 58%)); }
html[data-theme="dark"] .kn-tab-nav { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-tab-nav li { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-tab-nav li.kn-tab-active { background: var(--ork-card-bg); color: hsl(var(--kn-hue), var(--kn-sat), var(--ork-accent-lightness, 65%)); border-color: var(--ork-border); border-bottom-color: hsl(var(--kn-hue), var(--kn-sat), var(--ork-accent-lightness, 65%)); }
html[data-theme="dark"] .kn-tab-nav li:hover:not(.kn-tab-active) { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .kn-tab-count { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-table { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-table th { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-color: var(--ork-border); text-shadow: none; }
html[data-theme="dark"] .kn-table td { color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-row-link:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-sub-pop { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-sub-pop-title { color: var(--ork-text); }
html[data-theme="dark"] .kn-sub-url-input { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-sub-copy-btn { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-sub-copy-btn:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-sub-webcal-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-sub-webcal-btn:hover { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-modal-box { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-modal-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
html[data-theme="dark"] .kn-modal-title { color: var(--ork-text); }
html[data-theme="dark"] .kn-modal-body { background: var(--ork-card-bg); color: var(--ork-text); }
html[data-theme="dark"] .kn-modal-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-modal-close-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-modal-close-btn:hover { color: var(--ork-text); background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-acct-field label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-acct-field input[type="text"],
html[data-theme="dark"] .kn-acct-field input[type="date"],
html[data-theme="dark"] .kn-acct-field input[type="number"],
html[data-theme="dark"] .kn-acct-field select,
html[data-theme="dark"] .kn-acct-field textarea { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-mp-toggle { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .kn-mp-toggle-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-mp-toggle-btn.kn-mp-active { background: var(--ork-card-bg); color: var(--ork-link); }
html[data-theme="dark"] #theme_container .kn-reports-grid a { color: var(--ork-link); }
html[data-theme="dark"] #theme_container .kn-reports-grid a:hover { color: var(--ork-link-bright); }
html[data-theme="dark"] .kn-map-sidebar-card { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .kn-filter-toggle { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-filter-toggle.kn-filter-off { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-sidebar { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
/* Inline danger buttons */
.kn-btn-danger { background: #c53030; color: #fff; border-color: #c53030; }
html[data-theme="dark"] .kn-btn-danger { background: #fc8181; color: #1a202c; border-color: #fc8181; }

/* ============================================================
   </style>
<div id="kn-moveplayer-overlay">
	<div class="kn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="kn-modal-close-btn" id="kn-moveplayer-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-moveplayer-feedback" style="display:none"></div>
			<!-- Mode toggle -->
			<div class="kn-mp-toggle">
				<button class="kn-mp-toggle-btn kn-mp-active" id="kn-mp-btn-in" type="button">
					<i class="fas fa-arrow-right" style="margin-right:3px"></i>Transfer Into Kingdom
				</button>
				<button class="kn-mp-toggle-btn" id="kn-mp-btn-within" type="button">
					<i class="fas fa-arrows-alt-h" style="margin-right:3px"></i>Transfer Within Kingdom
				</button>
				<button class="kn-mp-toggle-btn" id="kn-mp-btn-out" type="button">
					<i class="fas fa-arrow-left" style="margin-right:3px"></i>Transfer Out of Kingdom
				</button>
			</div>
			<div class="kn-acct-field">
				<label id="kn-moveplayer-player-label">Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-moveplayer-player-name" autocomplete="off" placeholder="Search players outside this kingdom&hellip;">
				<input type="hidden" id="kn-moveplayer-player-id">
				<div class="kn-ac-results" id="kn-moveplayer-player-results"></div>
			</div>
			<div class="kn-acct-field" style="margin-top:10px">
				<label id="kn-moveplayer-park-label">New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-moveplayer-park-name" autocomplete="off" placeholder="Search parks in this kingdom&hellip;">
				<input type="hidden" id="kn-moveplayer-park-id">
				<div class="kn-ac-results" id="kn-moveplayer-park-results"></div>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-moveplayer-cancel">Cancel</button>
			<button class="kn-btn kn-btn-primary" id="kn-moveplayer-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- Merge Players Modal (Kingdom) -->
<div id="kn-mergeplayer-overlay">
	<div class="kn-modal-box" style="width:540px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-compress-alt" style="margin-right:8px;color:#c53030"></i>Merge Players</h3>
			<button class="kn-modal-close-btn" id="kn-mergeplayer-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body">
			<div id="kn-mergeplayer-feedback" style="display:none"></div>
			<div class="plr-merge-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div>
					<strong>This action is permanent and cannot be undone.</strong><br>
					The <em>Remove</em> player&rsquo;s account will be permanently deleted. All their awards, attendance, officer history, unit memberships, and notes will be transferred to the <em>Keep</em> player. Any attendance on the same date as an existing record will be dropped.
				</div>
			</div>
			<div class="kn-acct-field">
				<label>Player to Keep <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-merge-keep-name" placeholder="Search for player to keep&hellip;" autocomplete="off">
				<input type="hidden" id="kn-merge-keep-id">
				<div class="kn-ac-results" id="kn-merge-keep-results"></div>
			</div>
			<div class="kn-acct-field" style="margin-top:12px">
				<label>Player to Remove &mdash; <span style="color:#c53030;font-size:12px"><i class="fas fa-skull-crossbones"></i> this account will be permanently deleted</span> <span style="color:#e53e3e">*</span></label>
				<input type="text" id="kn-merge-remove-name" placeholder="Search for player to remove&hellip;" autocomplete="off">
				<input type="hidden" id="kn-merge-remove-id">
				<div class="kn-ac-results" id="kn-merge-remove-results"></div>
			</div>
			<div class="plr-merge-summary" id="kn-merge-summary" style="display:none">
				<strong>What will happen when you click Merge:</strong>
				<ul>
					<li>All attendance &rarr; transferred to <strong id="kn-merge-keep-display"></strong> (duplicate dates dropped)</li>
					<li>All awards &amp; award history &rarr; transferred</li>
					<li>All officer roles &rarr; transferred</li>
					<li>All unit memberships &rarr; transferred</li>
					<li>Notes &rarr; transferred</li>
					<li style="color:#c53030"><strong id="kn-merge-remove-display"></strong>&rsquo;s account record is permanently deleted</li>
				</ul>
			</div>
		</div>
		<div class="kn-modal-footer">
			<button class="kn-btn-ghost" id="kn-mergeplayer-cancel">Cancel</button>
			<button class="kn-btn kn-btn-danger" id="kn-mergeplayer-submit" disabled><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- Claim Park Modal -->
<div id="kn-claimpark-overlay">
	<div class="kn-modal-box" style="width:460px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#276749"></i>Claim Park</h3>
			<button class="kn-modal-close-btn" id="kn-claimpark-close-btn">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:20px">
			<p style="font-size:14px;color:var(--ork-text);margin:0 0 10px">To claim a park, please submit documentation, including Althing results if possible, authorizing the move to:</p>
			<p style="font-size:15px;font-weight:600;margin:0 0 14px">
				<a href="mailto:Contracts@amtgard.com?subject=<?= rawurlencode('Park Claim Request — ' . ($kingdom_name ?? '')) ?>&body=<?= rawurlencode("Kingdom: " . ($kingdom_name ?? '') . "\nPark Name: \nAlthing Results: \nReason for Claim: ") ?>">Contracts@amtgard.com</a>
			</p>
			<p style="font-size:12px;color:var(--ork-text-muted);margin:0">Include the park name, your kingdom, and any supporting documentation.</p>
		</div>
		<div class="kn-modal-footer" style="justify-content:flex-end">
			<button class="kn-btn-ghost" id="kn-claimpark-cancel">Close</button>
		</div>
	</div>
</div>


<!-- [TOURNAMENTS HIDDEN] add-tournament modal -->
<?php endif; ?>
<script>
(function() {
	var kingdomId = <?= (int)($kingdom_id ?? 0) ?>;
	if (!kingdomId) return;

	// ---- Park averages + player counts (AJAX) ----
	fetch('<?= UIR ?>Kingdom/park_averages_json/' + kingdomId)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			var totalAtt = 0, totalTp = 0, totalTm = 0;
			var wkCount = (data._kingdom && data._kingdom.wk_count) ? data._kingdom.wk_count : 26;
			var kingdomAtt = (data._kingdom && data._kingdom.att) ? data._kingdom.att : null;
			function knTrend(cur, prev, decimals) {
				if (prev === undefined) return '';
				if (cur > prev) return ' <span class="kn-trend kn-trend-up" title="Up from ' + prev.toFixed(decimals) + ' (prev period)">&#9650;</span>';
				if (cur < prev) return ' <span class="kn-trend kn-trend-dn" title="Down from ' + prev.toFixed(decimals) + ' (prev period)">&#9660;</span>';
				return '';
			}
			for (var parkId in data) {
				if (parkId === '_kingdom') continue;
				var att = data[parkId].att || 0, mo = data[parkId].mo || 0;
				var tp  = data[parkId].tp  || 0, tm = data[parkId].tm  || 0;
				var prevAtt = data[parkId].prev_att, prevMo = data[parkId].prev_mo;
				totalAtt += att; totalTp += tp; totalTm += tm;
				// Tile view
				var tile = document.querySelector('.kn-park-tile[data-park-id="' + parkId + '"]');
				if (tile) {
					var wkEl = tile.querySelector('.kn-avgwk-tile');
					var moEl = tile.querySelector('.kn-avgmo-tile');
					if (wkEl) wkEl.innerHTML = (att / wkCount).toFixed(1) + knTrend(att / wkCount, prevAtt !== undefined ? prevAtt / wkCount : undefined, 1);
					if (moEl) moEl.innerHTML = mo.toFixed(1) + knTrend(mo, prevMo !== undefined ? prevMo : undefined, 1);
				}
				// List view row
				var row = document.querySelector('tr[data-park-id="' + parkId + '"]');
				if (row) {
					var wkTd = row.querySelector('.kn-avgwk-row');
					var moTd = row.querySelector('.kn-avgmo-row');
					var tpTd = row.querySelector('.kn-tp-row');
					var tmTd = row.querySelector('.kn-tm-row');
					if (wkTd) { wkTd.innerHTML = (att / wkCount).toFixed(2) + knTrend(att / wkCount, prevAtt !== undefined ? prevAtt / wkCount : undefined, 2); wkTd.setAttribute('data-sortval', att / wkCount); }
					if (moTd) { moTd.innerHTML = mo.toFixed(1) + knTrend(mo, prevMo !== undefined ? prevMo : undefined, 1); moTd.setAttribute('data-sortval', mo); }
					if (tpTd) { tpTd.textContent = tp;  tpTd.setAttribute('data-sortval', tp); }
					if (tmTd) { tmTd.textContent = tm;  tmTd.setAttribute('data-sortval', tm); }
				}
			}
			// Stat cards — use kingdom-level deduped values (avoids double-counting multi-park players)
			var wkBase = kingdomAtt !== null ? kingdomAtt : totalAtt;
			var moBase = (data._kingdom && data._kingdom.mo) ? data._kingdom.mo : 0;
			var statWk = document.getElementById('kn-stat-avgwk');
			var statMo = document.getElementById('kn-stat-avgmo');
			if (statWk) statWk.textContent = (wkBase / wkCount).toFixed(1);
			if (statMo) statMo.textContent = moBase.toFixed(1);
			// Footer totals
			var footWk = document.getElementById('kn-total-avgwk');
			var footMo = document.getElementById('kn-total-avgmo');
			var footTp = document.getElementById('kn-total-tp');
			var footTm = document.getElementById('kn-total-tm');
			if (footWk) footWk.textContent = (wkBase / wkCount).toFixed(2);
			if (footMo) footMo.textContent = moBase.toFixed(1);
			if (footTp) footTp.textContent = totalTp;
			if (footTm) footTm.textContent = totalTm;
		})
		.catch(function(err) { console.error('Kingdom park_averages_json failed:', err); });

	// ---- Players tab: lazy-load on first click ----
	function knHtmlEsc(s) {
		return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
	function knFmtDate(s, long) {
		if (!s || s === '1970-01-01') return '—';
		var d = new Date(s + 'T00:00:00');
		return long
			? d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})
			: d.toLocaleDateString('en-US', {month:'short', day:'numeric'});
	}
	function knPlayerCardHtml(p, uir) {
		var initial = (p.persona || '?').charAt(0).toUpperCase();
		var avatarHtml = p.avatarUrl
			? '<img src="' + knHtmlEsc(p.avatarUrl) + '" loading="lazy" alt="" onerror="knAvatarFallback(this,\'' + initial + '\')">'
			: initial;
		var hbgAttr  = p.heraldryUrl ? ' style="--hbg:url(\'' + knHtmlEsc(p.heraldryUrl) + '\')"' : '';
		var hbgClass = p.heraldryUrl ? ' kn-player-card-hbg' : '';
		var pills = (p.officerRoles || '').split(', ').filter(Boolean).map(function(r) {
			return '<span class="kn-officer-pill">' + knHtmlEsc(r.trim()) + '</span>';
		}).join('');
		var classSpan = p.lastClass ? '<span><i class="fas fa-shield-alt" style="color:#b794f4;width:14px"></i> ' + knHtmlEsc(p.lastClass) + '</span>' : '';
		var mnAttr = p.mundaneName ? ' data-mundane-name="' + knHtmlEsc(p.mundaneName.toLowerCase()) + '"' : '';
		return '<a class="kn-player-card' + hbgClass + '"' + hbgAttr + mnAttr + ' data-signin-count="' + p.signinCount + '" href="' + uir + 'Player/profile/' + p.id + '">'
			+ '<div class="kn-player-card-top"><div class="kn-player-avatar">' + avatarHtml + '</div>'
			+ '<div><div class="kn-player-name">' + knHtmlEsc(p.persona) + '</div>' + pills + '</div></div>'
			+ '<div class="kn-player-stats">'
			+ '<span><i class="fas fa-map-marker-alt" style="color:#68d391;width:14px"></i> ' + knHtmlEsc(p.parkName) + '</span>'
			+ '<span><i class="fas fa-check-circle" style="color:#68d391;width:14px"></i> ' + p.signinCount + ' six month sign-in' + (p.signinCount !== 1 ? 's' : '') + '</span>'
			+ '<span><i class="fas fa-calendar-check" style="color:#63b3ed;width:14px"></i> ' + knFmtDate(p.lastSignin) + '</span>'
			+ classSpan + '</div></a>';
	}
	function knPlayerRowHtml(p, uir) {
		var pills = (p.officerRoles || '').split(', ').filter(Boolean).map(function(r) {
			return '<span class="kn-officer-pill">' + knHtmlEsc(r.trim()) + '</span>';
		}).join('');
		var mnAttr = p.mundaneName ? ' data-mundane-name="' + knHtmlEsc(p.mundaneName.toLowerCase()) + '"' : '';
		return '<tr' + mnAttr + ' data-signin-count="' + p.signinCount + '" onclick=\'window.location.href="' + uir + 'Player/profile/' + p.id + '"\'>'
			+ '<td>' + knHtmlEsc(p.persona) + pills + '</td>'
			+ '<td>' + knHtmlEsc(p.parkName || '') + '</td>'
			+ '<td data-sortval="' + p.signinCount + '">' + p.signinCount + '</td>'
			+ '<td class="kn-date-col" data-sortval="' + knHtmlEsc(p.lastSignin) + '">' + knFmtDate(p.lastSignin, true) + '</td>'
			+ '<td>' + knHtmlEsc(p.lastClass || '') + '</td>'
			+ '<td>' + knHtmlEsc(p.officerRoles || '') + '</td>'
			+ '</tr>';
	}

	var knPlayersLoaded = false;
	function knLoadPlayers() {
		if (knPlayersLoaded) return;
		var uir = '<?= UIR ?>';
		fetch(uir + 'Kingdom/players_json/' + kingdomId)
			.then(function(r) { return r.json(); })
			.then(function(data) {
				knPlayersLoaded = true;
				var players = data.players || [];
				var total   = players.length;

				// Bucket by year of last sign-in. Use a sentinel "Inactive" bucket for
				// players whose last_signin is the 1970 default (never attended).
				var byYear = {};
				var nowYear = new Date().getFullYear();
				var sixMoCutoff = Date.now() - 6 * 30.44 * 24 * 3600 * 1000;
				var activeRecent = 0;
				players.forEach(function(p) {
					var raw = p.lastSignin || '1970-01-01';
					var key;
					if (raw === '1970-01-01' || raw.indexOf('1970') === 0) {
						key = 'never';
					} else {
						key = raw.slice(0, 4); // YYYY
					}
					(byYear[key] = byYear[key] || []).push(p);
					var ts = new Date(raw + 'T00:00:00').getTime();
					if (ts >= sixMoCutoff) activeRecent++;
				});

				// Sort keys: real years descending (newest first), 'never' last.
				var yearKeys = Object.keys(byYear).filter(function(k){ return k !== 'never'; })
					.sort(function(a,b){ return b.localeCompare(a); });
				if (byYear.never) yearKeys.push('never');

				// Update tab count + summary line
				var tabCount = document.getElementById('kn-players-tab-count');
				if (tabCount) tabCount.textContent = '(' + total + ')';
				var summEl = document.getElementById('kn-players-summary');
				if (summEl) {
					summEl.textContent = activeRecent + ' active member' + (activeRecent!==1?'s':'')
						+ ' (past 6 months)' + (total > activeRecent ? ' · ' + total + ' total' : '');
				}

				// Build year-bucketed cards + list sections in one pass.
				var cardsEl = document.getElementById('kn-players-cards');
				var listEl  = document.getElementById('kn-players-list');
				var cardsHtml = [];
				var listHtml  = [];
				yearKeys.forEach(function(yk, idx) {
					var bucket = byYear[yk];
					var label  = (yk === 'never') ? 'No recorded sign-ins' : yk;
					if (yk !== 'never' && yk == nowYear) label = yk + ' (current)';
					var openAttr = idx === 0 ? ' open' : '';
					var count    = bucket.length;
					var summary  =
						'<summary class="kn-year-summary">'
						+ '<span class="kn-year-label">' + label + '</span>'
						+ '<span class="kn-year-count">' + count + ' member' + (count!==1?'s':'') + '</span>'
						+ '</summary>';

					cardsHtml.push(
						'<details class="kn-year-section"' + openAttr + ' data-year="' + yk + '">'
						+ summary
						+ '<div class="kn-players-grid">'
						+ bucket.map(function(p){ return knPlayerCardHtml(p, uir); }).join('')
						+ '</div></details>'
					);
					listHtml.push(
						'<details class="kn-year-section"' + openAttr + ' data-year="' + yk + '">'
						+ summary
						+ '<table class="kn-table kn-year-table"><thead><tr>'
						+ '<th data-sorttype="text">Persona</th>'
						+ '<th data-sorttype="text">Park</th>'
						+ '<th data-sorttype="numeric">6mo Sign-ins</th>'
						+ '<th data-sorttype="date">Last Visit</th>'
						+ '<th data-sorttype="text">Last Class</th>'
						+ '<th data-sorttype="text">Role</th>'
						+ '</tr></thead><tbody>'
						+ bucket.map(function(p){ return knPlayerRowHtml(p, uir); }).join('')
						+ '</tbody></table></details>'
					);
				});

				if (cardsEl) { cardsEl.innerHTML = cardsHtml.join(''); cardsEl.style.display = ''; }
				if (listEl)  { listEl.innerHTML  = listHtml.join('');  /* keeps display:none until view toggle */ }

				// Hide spinner
				var loadEl = document.getElementById('kn-players-loading');
				if (loadEl) loadEl.style.display = 'none';
			})
			.catch(function() {
				knPlayersLoaded = false;
				var loadEl = document.getElementById('kn-players-loading');
				if (loadEl) loadEl.innerHTML = '<span style="color:#e53e3e">Failed to load players.</span>';
			});
	}

	// Trigger on first Players tab click; also extend search to cover list rows
	document.addEventListener('DOMContentLoaded', function() {
		var btn = document.querySelector('[data-kntab="players"]');
		if (btn) btn.addEventListener('click', knLoadPlayers, {once: true});

		function knApplyPlayerFilters() {
			var qInput = document.getElementById('kn-player-search');
			var q = (qInput ? qInput.value : '').trim().toLowerCase();
			var aoBtn = document.getElementById('kn-active-only-btn');
			var activeOnly = aoBtn && aoBtn.classList.contains('kn-view-active');
			var roots = [
				document.getElementById('kn-players-cards'),
				document.getElementById('kn-players-list')
			];
			roots.forEach(function(root) {
				if (!root) return;
				root.querySelectorAll('.kn-player-card').forEach(function(card) {
					var nameEl = card.querySelector('.kn-player-name');
					var pName  = nameEl ? nameEl.textContent.toLowerCase() : '';
					var mn     = (card.dataset.mundaneName || '').toLowerCase();
					var sc     = parseInt(card.dataset.signinCount || '0', 10);
					var match  = (!q || pName.indexOf(q) !== -1 || mn.indexOf(q) !== -1) && (!activeOnly || sc > 0);
					card.style.display = match ? '' : 'none';
				});
				root.querySelectorAll('.kn-year-table tbody tr').forEach(function(row) {
					var persona = row.cells[0] ? row.cells[0].textContent.toLowerCase() : '';
					var mn      = (row.dataset.mundaneName || '').toLowerCase();
					var sc      = parseInt(row.dataset.signinCount || '0', 10);
					var match   = (!q || persona.indexOf(q) !== -1 || mn.indexOf(q) !== -1) && (!activeOnly || sc > 0);
					row.style.display = match ? '' : 'none';
				});
				var filtering = q || activeOnly;
				root.querySelectorAll('.kn-year-section').forEach(function(sec) {
					if (!filtering) { sec.style.display = ''; return; }
					var hasMatch = sec.querySelector('.kn-player-card:not([style*="display: none"]), .kn-year-table tbody tr:not([style*="display: none"])');
					sec.style.display = hasMatch ? '' : 'none';
					if (hasMatch) sec.open = true;
				});
			});
		}

		var searchInput = document.getElementById('kn-player-search');
		if (searchInput) searchInput.addEventListener('input', knApplyPlayerFilters);
		var aoBtn = document.getElementById('kn-active-only-btn');
		if (aoBtn) aoBtn.addEventListener('click', function() {
			this.classList.toggle('kn-view-active');
			knApplyPlayerFilters();
		});
	});

	window.knCopyIcsUrl = function() {
		var inp = document.getElementById('kn-sub-url-input');
		if (!inp) return;
		inp.select();
		inp.setSelectionRange(0, 99999);
		try {
			navigator.clipboard.writeText(inp.value).then(function() {
				var btn = document.querySelector('.kn-sub-copy-btn');
				if (btn) { btn.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(function(){ btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500); }
			});
		} catch(e) { document.execCommand('copy'); }
	}

	// Close subscribe popover on outside click
	document.addEventListener('click', function(e) {
		var wrap = document.getElementById('kn-sub-wrap');
		if (wrap && !wrap.contains(e.target)) {
			var pop = document.getElementById('kn-sub-pop');
			if (pop) pop.style.setProperty('display', 'none', 'important');
		}
	});

})();
</script>
<script>
// ---- Events: Load-more (next 12-month window) ----
function knLoadMoreEvents(ev) {
	if (ev) ev.preventDefault();
	var wrap = document.getElementById('kn-events-loadmore');
	var link = document.getElementById('kn-events-loadmore-link');
	if (!wrap || !link) return;
	var nextWindow = parseInt(wrap.dataset.nextWindow || '1', 10);
	var kingdomId = (window.KnConfig && KnConfig.kingdomId) || 0;
	var uir       = (window.KnConfig && KnConfig.uir)       || '<?= UIR ?>';
	if (!kingdomId) return;

	var origHtml = link.innerHTML;
	link.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
	link.style.pointerEvents = 'none';
	link.setAttribute('aria-busy', 'true');

	fetch(uir + 'Kingdom/events_more/' + kingdomId + '?window=' + nextWindow, { credentials: 'same-origin' })
		.then(function(r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r.json();
		})
		.then(function(data) {
			var table = document.getElementById('kn-events-table');
			var tbody = table ? table.querySelector('tbody') : null;
			if (!tbody) return;

			// Reveal the table if it was hidden (empty-state page)
			if (table.style.display === 'none') table.style.display = '';
			var emptyEl = document.getElementById('kn-events-empty');
			if (emptyEl) emptyEl.style.display = 'none';

			// Append the new rows
			var appended = 0;
			(data.Events || []).forEach(function(e) {
				// Skip duplicates (shouldn't happen with non-overlapping windows, but defensive)
				if (document.querySelector('#kn-events-table tr[data-event-id="' + e.EventId + '"]')) return;
				tbody.appendChild(knBuildEventRow(e, data.FallbackHeraldry, data.Uir || uir));
				appended++;
			});

			// Update count + months in footer
			var countEl  = document.getElementById('kn-events-loadmore-count');
			var pluralEl = document.getElementById('kn-events-loadmore-plural');
			var monthsEl = document.getElementById('kn-events-loadmore-months');
			var prevLoaded = parseInt(wrap.dataset.loadedEventCount || '0', 10);
			var total = prevLoaded + appended;
			wrap.dataset.loadedEventCount = String(total);
			if (countEl)  countEl.textContent  = String(total);
			if (pluralEl) pluralEl.textContent = (total === 1 ? '' : 's');
			if (monthsEl) monthsEl.textContent = String(data.EndMonths);

			wrap.dataset.nextWindow = String(nextWindow + 1);

			// Respect current filter toggles for newly-appended rows
			try {
				if (typeof knFilters === 'object' && knFilters) {
					Object.keys(knFilters).forEach(function(type) {
						if (!knFilters[type]) {
							var rows = tbody.querySelectorAll('tr[data-type="' + type + '"]');
							rows.forEach(function(tr) { tr.style.display = 'none'; });
						}
					});
				}
			} catch (e) { console.warn('[knLoadMoreEvents] filter reapply failed', e); }

			// Re-run pagination if present
			try { if (typeof knPaginate === 'function' && window.jQuery) knPaginate(window.jQuery('#kn-events-table'), 1); } catch(e) {}

			// Calendar view: invalidate so next open re-fetches
			try { if (window.knCalendar) knCalendar.refetchEvents(); } catch(e) {}

			if (!data.HasMore) {
				link.remove();
			} else {
				link.innerHTML = 'Load more <i class="fas fa-chevron-down" style="font-size:10px;margin-left:3px"></i>';
				link.style.pointerEvents = '';
				link.removeAttribute('aria-busy');
			}
		})
		.catch(function(err) {
			console.error('[knLoadMoreEvents]', err);
			link.innerHTML = origHtml;
			link.style.pointerEvents = '';
			link.removeAttribute('aria-busy');
		});
}

function knBuildEventRow(e, fallbackHeraldry, uir) {
	var tr = document.createElement('tr');
	tr.className = 'kn-row-link';
	tr.dataset.type    = e.IsParkEvent ? 'park-event' : 'kingdom-event';
	tr.dataset.eventId = String(e.EventId);
	var detailHref = e.NextDetailId ? (uir + 'Event/detail/' + e.EventId + '/' + e.NextDetailId) : '';
	if (detailHref) tr.setAttribute('onclick', "window.location.href='" + detailHref + "'");

	var nameHtml = detailHref
		? '<a href="' + detailHref + '">' + knEscape(e.Name) + '</a>'
		: knEscape(e.Name);
	var dateHtml = e.NextDateText ? knEscape(e.NextDateText) : '<span style="color:#a0aec0">&mdash;</span>';
	var heraldry = e.HeraldryUrl || fallbackHeraldry;

	tr.innerHTML =
		'<td class="kn-col-nowrap">' + dateHtml + '</td>' +
		'<td class="kn-col-nowrap">' +
			'<img class="kn-thumb ' + (e.IsParkEvent ? 'kn-evt-park' : 'kn-evt-kingdom') +
				'" loading="lazy" src="' + knEscapeAttr(heraldry) +
				'" onerror="this.src=\'' + knEscapeAttr(fallbackHeraldry) + '\'" alt="">' +
			nameHtml +
		'</td>' +
		'<td>' + knEscape(e.ParkName || '') + '</td>' +
		'<td style="text-align:center">' + (e.RsvpGoing > 0 ? e.RsvpGoing : '&mdash;') + '</td>' +
		'<td style="text-align:center">' + (e.RsvpInterested > 0 ? e.RsvpInterested : '&mdash;') + '</td>';
	return tr;
}

function knEscape(s) {
	var d = document.createElement('div');
	d.textContent = (s == null ? '' : String(s));
	return d.innerHTML;
}
function knEscapeAttr(s) {
	return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/email-spell-checker.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
window.knRecActiveFilter = 'open';
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
	if (settings.nTable.id !== 'kn-rec-table') return true;
	var filter = window.knRecActiveFilter || 'all';
	if (filter === 'all') return true;
	var row = settings.aoData[dataIndex].nTr;
	var rowFilter = row ? row.getAttribute('data-filter') : '';
	if (filter === 'open') return rowFilter !== 'already';
	return rowFilter === filter;
});
$(function() {
	if ($('#kn-rec-table').length) {
		window.knRecDT = $('#kn-rec-table').DataTable({
			order: [[4, 'desc']],
			columnDefs: [
				{ targets: [4], type: 'date' },
				<?php if ($CanManageKingdom ?? false): ?>
				{ targets: [-1], orderable: false, searchable: false },
				<?php endif; ?>
			],
			pageLength: 25
		});
	}
});
window.knRecPrint = function() { if (window.knRecDT) window.recsExportPrint(window.knRecDT, 'Award Recommendations \u2014 <?= htmlspecialchars(addslashes($kingdom_name)) ?>'); };
window.knRecCsv   = function() { if (window.knRecDT) window.recsExportCsv(window.knRecDT, 'recs-<?= preg_replace('/[^a-z0-9]+/i', '-', $kingdom_name) ?>.csv'); };
initEmailSpellCheck('kn-addplayer-email', 'kn-addplayer-email-suggestion');
</script>

<?php if (!empty($IsLoggedIn)): ?>
<script>
window.OrkRsCfg = {
	url:         null,  /* unused */
	uir:         '<?= UIR ?>',
	userId:      <?= (int)$this->__session->user_id ?>,
	userPersona: <?= json_encode($this->__session->persona ?? '') ?>,
	reload:      function() { location.reload(); }
};
</script>
<?php include __DIR__ . '/_recommendation_seconds_assets.tpl'; ?>
<?php endif; ?>

<?php if ($CanManageKingdom ?? false): ?>
<!-- =============================================
     Kingdom Design Modal
     ============================================= -->
<style>
/* --- Kingdom Design Modal: scoped styles (kn-dm-*) ----------------------------- */
.kn-dm-overlay {
	position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 10000;
	display: none; align-items: center; justify-content: center; padding: 20px;
}
.kn-dm-overlay.kn-open { display: flex; }
.kn-dm-modal {
	background: #fff; border-radius: 10px; width: 720px; max-width: calc(100vw - 40px);
	max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
	box-shadow: 0 18px 50px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .kn-dm-modal { background: var(--ork-card-bg); color: var(--ork-text); }
.kn-dm-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 14px 18px; border-bottom: 1px solid #e2e8f0;
}
html[data-theme="dark"] .kn-dm-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
.kn-dm-title {
	margin: 0; font-size: 16px; font-weight: 700; color: #2d3748;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	display: inline-flex; align-items: center; gap: 8px;
}
html[data-theme="dark"] .kn-dm-title { color: var(--ork-text); }
.kn-dm-close {
	background: transparent; border: 0; font-size: 22px; cursor: pointer; color: #718096;
	width: 32px; height: 32px; border-radius: 6px;
}
.kn-dm-close:hover { background: #f7fafc; color: #2d3748; }
html[data-theme="dark"] .kn-dm-close { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-dm-close:hover { background: var(--ork-bg-tertiary); color: var(--ork-text); }
.kn-dm-tabs {
	display: flex; gap: 4px; padding: 8px 18px 0 18px; border-bottom: 1px solid #e2e8f0;
	background: #f7fafc;
}
html[data-theme="dark"] .kn-dm-tabs { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.kn-dm-tab {
	background: transparent; border: 1px solid transparent; border-bottom: none;
	padding: 8px 14px; font-size: 13px; font-weight: 600; color: #718096; cursor: pointer;
	border-radius: 6px 6px 0 0; display: inline-flex; align-items: center; gap: 6px;
}
.kn-dm-tab.kn-active {
	background: #fff; color: #2b6cb0; border-color: #e2e8f0; border-bottom: 1px solid #fff;
	margin-bottom: -1px;
}
html[data-theme="dark"] .kn-dm-tab { color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-dm-tab.kn-active {
	background: var(--ork-card-bg); color: var(--ork-link); border-color: var(--ork-border);
	border-bottom-color: var(--ork-card-bg);
}
.kn-dm-body { padding: 18px; overflow-y: auto; flex: 1; }
.kn-dm-panel { display: none; }
.kn-dm-panel.kn-active { display: block; }
.kn-dm-error {
	display: none; background: #fff5f5; border: 1px solid #fc8181; color: #9b2c2c;
	border-radius: 6px; padding: 10px 12px; font-size: 13px; margin-bottom: 12px;
}
html[data-theme="dark"] .kn-dm-error { background: rgba(252,129,129,0.1); color: #fc8181; }
.kn-dm-field { margin-bottom: 14px; }
.kn-dm-field label {
	display: block; font-size: 12px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px;
}
html[data-theme="dark"] .kn-dm-field label { color: var(--ork-text-secondary); }
.kn-dm-field input[type="text"],
.kn-dm-field input[type="date"],
.kn-dm-field textarea,
.kn-dm-field select {
	width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 6px;
	font-size: 14px; color: #2d3748; box-sizing: border-box; background: #fff;
	font-family: inherit;
}
html[data-theme="dark"] .kn-dm-field input,
html[data-theme="dark"] .kn-dm-field textarea,
html[data-theme="dark"] .kn-dm-field select {
	background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text);
}
.kn-dm-field textarea { min-height: 140px; resize: vertical; line-height: 1.5; }
.kn-dm-hint { font-size: 12px; color: #718096; margin-top: 4px; }
html[data-theme="dark"] .kn-dm-hint { color: var(--ork-text-muted); }
.kn-dm-footer {
	border-top: 1px solid #e2e8f0; padding: 12px 18px;
	display: flex; justify-content: flex-end; gap: 8px; background: #f7fafc;
}
html[data-theme="dark"] .kn-dm-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.kn-dm-btn {
	padding: 8px 16px; font-size: 13px; font-weight: 600; border-radius: 6px;
	border: 1px solid #cbd5e0; background: #fff; color: #2d3748; cursor: pointer;
	display: inline-flex; align-items: center; gap: 6px;
}
html[data-theme="dark"] .kn-dm-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text); }
.kn-dm-btn-primary { background: #3182ce; color: #fff; border-color: #3182ce; }
.kn-dm-btn-primary:hover { background: #2c5282; border-color: #2c5282; }
.kn-dm-btn:disabled { opacity: 0.55; cursor: not-allowed; }

.kn-dm-preset-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; }
.kn-dm-swatch {
	height: 40px; border-radius: 6px; cursor: pointer; border: 2px solid transparent;
	box-shadow: 0 1px 2px rgba(0,0,0,0.1) inset;
}
.kn-dm-swatch.kn-selected { border-color: #2b6cb0; transform: scale(1.06); box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2b6cb0; }
html[data-theme="dark"] .kn-dm-swatch.kn-selected { box-shadow: 0 0 0 2px var(--ork-card-bg), 0 0 0 4px var(--ork-link); }
.kn-dm-color-row { display: flex; gap: 12px; flex-wrap: wrap; }
.kn-dm-color-col { flex: 1; min-width: 180px; }
.kn-dm-color-input { display: flex; align-items: center; gap: 6px; }
.kn-dm-color-input input[type="color"] {
	width: 38px; height: 36px; border: 1px solid #cbd5e0; border-radius: 6px;
	padding: 0; background: transparent; cursor: pointer;
}
.kn-dm-color-input input[type="text"] { flex: 1; }
.kn-dm-overlay-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.kn-dm-overlay-btn {
	flex: 1; min-width: 80px; padding: 8px 10px; font-size: 12px; font-weight: 600;
	border: 1px solid #cbd5e0; border-radius: 6px; background: #fff; color: #4a5568; cursor: pointer;
}
.kn-dm-overlay-btn.kn-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .kn-dm-overlay-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-dm-overlay-btn.kn-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }

.kn-dm-font-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.kn-dm-font-card {
	border: 1px solid #cbd5e0; border-radius: 8px; padding: 10px 8px; cursor: pointer;
	text-align: center; background: #fff;
}
.kn-dm-font-card.kn-active { border-color: #2b6cb0; background: #ebf8ff; box-shadow: 0 0 0 1px #2b6cb0; }
html[data-theme="dark"] .kn-dm-font-card { background: var(--ork-bg-tertiary); border-color: var(--ork-border); }
html[data-theme="dark"] .kn-dm-font-card.kn-active { background: rgba(43,108,176,0.15); border-color: var(--ork-link); box-shadow: 0 0 0 1px var(--ork-link); }
.kn-dm-font-sample { font-size: 19px; font-weight: 600; color: #2d3748; line-height: 1.2; margin-bottom: 4px; }
html[data-theme="dark"] .kn-dm-font-sample { color: var(--ork-text); }
.kn-dm-font-label { font-size: 11px; color: #718096; }
html[data-theme="dark"] .kn-dm-font-label { color: var(--ork-text-muted); }

.kn-dm-md-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.kn-dm-md-toggle { display: inline-flex; background: #edf2f7; border-radius: 6px; padding: 3px; gap: 3px; }
.kn-dm-md-toggle button {
	background: transparent; border: 0; padding: 5px 10px; font-size: 12px; font-weight: 600;
	cursor: pointer; border-radius: 4px; color: #718096;
}
.kn-dm-md-toggle button.kn-active { background: #fff; color: #2b6cb0; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
html[data-theme="dark"] .kn-dm-md-toggle { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .kn-dm-md-toggle button.kn-active { background: var(--ork-card-bg); color: var(--ork-link); }
.kn-dm-md-quick { display: flex; flex-wrap: wrap; gap: 6px; }
.kn-dm-md-quick-btn {
	background: #fff; border: 1px solid #cbd5e0; color: #4a5568;
	padding: 5px 10px; border-radius: 14px; font-size: 11px; font-weight: 600;
	cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
}
.kn-dm-md-quick-btn:hover { background: #ebf8ff; color: #2b6cb0; border-color: #90cdf4; }
html[data-theme="dark"] .kn-dm-md-quick-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-dm-md-quick-btn:hover { background: var(--ork-bg-secondary); color: var(--ork-link); border-color: var(--ork-link); }
.kn-dm-md-preview {
	border: 1px solid #cbd5e0; border-radius: 6px; padding: 12px 14px;
	min-height: 140px; background: #fafafa; font-size: 14px; line-height: 1.55; color: #2d3748;
}
html[data-theme="dark"] .kn-dm-md-preview { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text); }
.kn-dm-md-preview h1, .kn-dm-md-preview h2, .kn-dm-md-preview h3, .kn-dm-md-preview h4 {
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	margin-top: 0.9em; margin-bottom: 0.3em;
}

.kn-dm-ms-toggles { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 14px; }
.kn-dm-ms-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #4a5568; }
html[data-theme="dark"] .kn-dm-ms-toggle { color: var(--ork-text-secondary); }
.kn-dm-ms-list { border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 12px; max-height: 200px; overflow-y: auto; }
html[data-theme="dark"] .kn-dm-ms-list { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
.kn-dm-ms-row {
	display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid #edf2f7;
	font-size: 13px;
}
html[data-theme="dark"] .kn-dm-ms-row { border-color: var(--ork-border); }
.kn-dm-ms-row:last-child { border-bottom: none; }
.kn-dm-ms-row > i { color: #2b6cb0; width: 18px; text-align: center; }
.kn-dm-ms-row .kn-dm-ms-desc { flex: 1; color: #2d3748; }
html[data-theme="dark"] .kn-dm-ms-row .kn-dm-ms-desc { color: var(--ork-text); }
.kn-dm-ms-row .kn-dm-ms-date { color: #718096; font-size: 11px; min-width: 90px; }
html[data-theme="dark"] .kn-dm-ms-row .kn-dm-ms-date { color: var(--ork-text-muted); }
html[data-theme="dark"] .kn-dm-ms-row > i { color: var(--ork-link); }
html[data-theme="dark"] .kn-dm-ms-row button { color: #fc8181; }
html[data-theme="dark"] .kn-dm-ms-row button:hover { background: var(--ork-bg-tertiary); }
.kn-dm-ms-row button {
	background: transparent; border: 0; color: #e53e3e; cursor: pointer; padding: 4px 6px;
}
.kn-dm-ms-row button[data-tip] { position: relative; }
.kn-dm-ms-row button[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 4px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.kn-dm-ms-row button[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .kn-dm-ms-row button[data-tip]::after {
	background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border);
}
.kn-dm-ms-add { display: grid; grid-template-columns: 1fr 140px 90px; gap: 8px; align-items: end; }
@media (max-width: 600px) { .kn-dm-ms-add { grid-template-columns: 1fr; } }
.kn-dm-ms-icons { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.kn-dm-ms-icon-opt {
	width: 28px; height: 28px; border: 1px solid #cbd5e0; border-radius: 6px;
	display: flex; align-items: center; justify-content: center; cursor: pointer;
	background: #fff; color: #4a5568;
}
.kn-dm-ms-icon-opt.kn-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .kn-dm-ms-icon-opt { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .kn-dm-ms-icon-opt.kn-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }
</style>

<div class="kn-dm-overlay" id="kn-dm-overlay">
	<div class="kn-dm-modal">
		<div class="kn-dm-header">
			<h3 class="kn-dm-title"><i class="fas fa-palette"></i>Design <?= htmlspecialchars($kingdom_name) ?></h3>
			<button class="kn-dm-close" id="kn-dm-close" aria-label="Close">&times;</button>
		</div>
		<div class="kn-dm-tabs">
			<button class="kn-dm-tab kn-active" data-kntab-dm="header"><i class="fas fa-image"></i> Header</button>
			<button class="kn-dm-tab" data-kntab-dm="about"><i class="fas fa-scroll"></i> About</button>
			<button class="kn-dm-tab" data-kntab-dm="milestones"><i class="fas fa-stream"></i> Milestones</button>
		</div>
		<div class="kn-dm-body">
			<div class="kn-dm-error" id="kn-dm-error"></div>

			<div class="kn-dm-panel kn-active" id="kn-dm-panel-header">
				<div class="kn-dm-hint" style="margin-bottom:12px"><i class="fas fa-moon" style="margin-right:6px"></i><strong>Dark mode viewers</strong> see your hero with a slight darkening filter so colors stay readable. Preview both themes with the moon icon in the site header before saving.</div>

				<div class="kn-dm-field">
					<label>Color Presets</label>
					<div class="kn-dm-preset-grid" id="kn-dm-presets">
						<div class="kn-dm-swatch" data-primary="#2c5282" data-accent="#4299e1" style="background:#2c5282"></div>
						<div class="kn-dm-swatch" data-primary="#276749" data-accent="#48bb78" style="background:#276749"></div>
						<div class="kn-dm-swatch" data-primary="#9b2c2c" data-accent="#fc8181" style="background:#9b2c2c"></div>
						<div class="kn-dm-swatch" data-primary="#553c9a" data-accent="#9f7aea" style="background:#553c9a"></div>
						<div class="kn-dm-swatch" data-primary="#975a16" data-accent="#ecc94b" style="background:#975a16"></div>
						<div class="kn-dm-swatch" data-primary="#2d3748" data-accent="#a0aec0" style="background:#2d3748"></div>
						<div class="kn-dm-swatch" data-primary="#285e61" data-accent="#38b2ac" style="background:#285e61"></div>
						<div class="kn-dm-swatch" data-primary="#744210" data-accent="#ed8936" style="background:#744210"></div>
					</div>
				</div>

				<div class="kn-dm-field">
					<label>Gradient Presets</label>
					<div class="kn-dm-preset-grid" id="kn-dm-gradient-presets">
						<div class="kn-dm-swatch" data-primary="#1a365d" data-accent="#4299e1" data-secondary="#553c9a" style="background:linear-gradient(135deg,#1a365d,#553c9a)"></div>
						<div class="kn-dm-swatch" data-primary="#1a4731" data-accent="#48bb78" data-secondary="#2c5282" style="background:linear-gradient(135deg,#1a4731,#2c5282)"></div>
						<div class="kn-dm-swatch" data-primary="#742a2a" data-accent="#fc8181" data-secondary="#975a16" style="background:linear-gradient(135deg,#742a2a,#975a16)"></div>
						<div class="kn-dm-swatch" data-primary="#44337a" data-accent="#d6bcfa" data-secondary="#97266d" style="background:linear-gradient(135deg,#44337a,#97266d)"></div>
						<div class="kn-dm-swatch" data-primary="#234e52" data-accent="#38b2ac" data-secondary="#276749" style="background:linear-gradient(135deg,#234e52,#276749)"></div>
						<div class="kn-dm-swatch" data-primary="#2c5282" data-accent="#4299e1" data-secondary="#285e61" style="background:linear-gradient(135deg,#2c5282,#285e61)"></div>
						<div class="kn-dm-swatch" data-primary="#744210" data-accent="#ecc94b" data-secondary="#9b2c2c" style="background:linear-gradient(135deg,#744210,#9b2c2c)"></div>
						<div class="kn-dm-swatch" data-primary="#1a202c" data-accent="#a0aec0" data-secondary="#2d3748" style="background:linear-gradient(135deg,#1a202c,#2d3748)"></div>
					</div>
				</div>

				<div class="kn-dm-field">
					<label>Custom Colors</label>
					<div class="kn-dm-color-row">
						<div class="kn-dm-color-col">
							<div class="kn-dm-hint" style="margin-bottom:4px">Primary (hero background)</div>
							<div class="kn-dm-color-input">
								<input type="color" id="kn-dm-color-primary" value="<?= htmlspecialchars($knColorPrimary ?: '#2c5282') ?>" />
								<input type="text" id="kn-dm-color-primary-hex" value="<?= htmlspecialchars($knColorPrimary ?: '#2c5282') ?>" maxlength="7" />
							</div>
						</div>
						<div class="kn-dm-color-col">
							<div class="kn-dm-hint" style="margin-bottom:4px">Accent (links &amp; tabs)</div>
							<div class="kn-dm-color-input">
								<input type="color" id="kn-dm-color-accent" value="<?= htmlspecialchars($knColorAccent ?: '#4299e1') ?>" />
								<input type="text" id="kn-dm-color-accent-hex" value="<?= htmlspecialchars($knColorAccent ?: '#4299e1') ?>" maxlength="7" />
							</div>
						</div>
					</div>
				</div>

				<div class="kn-dm-field">
					<label>Gradient (Optional)</label>
					<div class="kn-dm-color-row">
						<div class="kn-dm-color-col">
							<div class="kn-dm-hint" style="margin-bottom:4px">Secondary color</div>
							<div class="kn-dm-color-input">
								<input type="color" id="kn-dm-color-secondary" value="<?= htmlspecialchars($knColorSecondary ?: ($knColorPrimary ?: '#2c5282')) ?>" />
								<input type="text" id="kn-dm-color-secondary-hex" value="<?= htmlspecialchars($knColorSecondary) ?>" maxlength="7" placeholder="None" />
							</div>
						</div>
						<div class="kn-dm-color-col" style="display:flex;align-items:center;padding-top:18px">
							<label style="text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;color:#4a5568;font-size:13px;margin-bottom:0">
								<input type="checkbox" id="kn-dm-gradient-enabled" <?= $knColorSecondary !== '' ? 'checked' : '' ?> />
								Enable gradient
							</label>
						</div>
					</div>
				</div>

				<div class="kn-dm-field">
					<label>Heraldry Overlay Strength</label>
					<div class="kn-dm-hint" style="margin-bottom:6px">Controls how much the kingdom heraldry shows through the hero background.</div>
					<div class="kn-dm-overlay-btns">
						<button type="button" class="kn-dm-overlay-btn<?= $knOverlay === 'low' ? ' kn-active' : '' ?>" data-overlay="low">Low</button>
						<button type="button" class="kn-dm-overlay-btn<?= $knOverlay === 'med' ? ' kn-active' : '' ?>" data-overlay="med">Medium</button>
						<button type="button" class="kn-dm-overlay-btn<?= $knOverlay === 'high' ? ' kn-active' : '' ?>" data-overlay="high">High</button>
						<button type="button" class="kn-dm-overlay-btn<?= $knOverlay === 'vignette' ? ' kn-active' : '' ?>" data-overlay="vignette">Vignette</button>
					</div>
					<input type="hidden" id="kn-dm-hero-overlay" value="<?= htmlspecialchars($knOverlay) ?>" />
				</div>

				<div class="kn-dm-field">
					<label>Name Font</label>
					<div class="kn-dm-hint" style="margin-bottom:6px">A decorative font for the kingdom name in the hero. Viewers with accessibility fonts enabled will see their preferred font instead.</div>
					<div class="kn-dm-font-picker" id="kn-dm-font-picker"></div>
				</div>

				<div class="kn-dm-field">
					<label>Tagline</label>
					<div class="kn-dm-hint" style="margin-bottom:6px">A short one-liner that appears under the kingdom name in the hero. 160 characters max.</div>
					<input type="text" id="kn-dm-tagline" maxlength="160" value="<?= htmlspecialchars($knTagline) ?>" placeholder="e.g. Honor, Glory, and the Sound of Sword on Shield." style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px" />
					<div class="kn-dm-counter" id="kn-dm-tagline-counter">0 / 160</div>
				</div>

				<div class="kn-dm-field">
					<label>Announcement Banner</label>
					<div class="kn-dm-hint" style="margin-bottom:6px">A short amber banner that appears above the hero. Use for upcoming events, weather cancellations, or kingdom news. 280 characters max.</div>
					<textarea id="kn-dm-announcement" maxlength="280" placeholder="e.g. Crown List sign-ups close Friday at midnight. RSVP on the events page!" style="width:100%;padding:8px 10px;font-size:13px;border:1px solid #cbd5e0;border-radius:5px;min-height:60px;resize:vertical"><?= htmlspecialchars($knAnnouncement) ?></textarea>
					<div class="kn-dm-counter" id="kn-dm-announcement-counter">0 / 280</div>
					<div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
						<label style="font-size:12px;color:#4a5568;text-transform:none;letter-spacing:0;margin-bottom:0">Show until (optional):</label>
						<input type="date" id="kn-dm-announcement-until" value="<?= htmlspecialchars(($knAnnouncementUntil !== '' && $knAnnouncementUntil !== '0000-00-00') ? $knAnnouncementUntil : '') ?>" style="padding:5px 8px;font-size:12px;border:1px solid #cbd5e0;border-radius:4px" />
						<button type="button" id="kn-dm-announcement-clear" style="background:transparent;border:0;color:#718096;font-size:11px;cursor:pointer;text-decoration:underline">Clear date</button>
					</div>
				</div>

				<div class="kn-dm-field">
					<label>Reign Banner</label>
					<div class="kn-dm-hint" style="margin-bottom:8px">Personae are derived from current Monarch &amp; Regent on the Officers list. Set reign-start dates and add optional lore (Markdown supported, 2,000 char max).</div>
					<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
						<div>
							<label style="font-size:11px;color:#4a5568;text-transform:none;letter-spacing:0">Monarch reign started</label>
							<input type="date" id="kn-dm-monarch-reign" value="<?= htmlspecialchars(($knMonarchReignStarted !== '' && $knMonarchReignStarted !== '0000-00-00') ? $knMonarchReignStarted : '') ?>" style="width:100%;padding:6px 8px;font-size:12px;border:1px solid #cbd5e0;border-radius:4px" />
						</div>
						<div>
							<label style="font-size:11px;color:#4a5568;text-transform:none;letter-spacing:0">Regent reign started</label>
							<input type="date" id="kn-dm-regent-reign" value="<?= htmlspecialchars(($knRegentReignStarted !== '' && $knRegentReignStarted !== '0000-00-00') ? $knRegentReignStarted : '') ?>" style="width:100%;padding:6px 8px;font-size:12px;border:1px solid #cbd5e0;border-radius:4px" />
						</div>
					</div>
					<div class="kn-dm-md-toolbar">
						<label style="margin-bottom:0;font-size:11px;text-transform:none;letter-spacing:0;color:#4a5568">Reign Lore (optional, Markdown)</label>
						<div class="kn-dm-md-toggle">
							<button type="button" class="kn-active" data-knmd-target="edit" data-knmd-field="reign">Write</button>
							<button type="button" data-knmd-target="preview" data-knmd-field="reign">Preview</button>
						</div>
					</div>
					<textarea id="kn-dm-reign-text" maxlength="2000" placeholder="e.g. Their Royal Majesties were crowned at Spring Coronation after a hard-fought Crown List..." style="width:100%;min-height:120px"><?= htmlspecialchars($knReignLore) ?></textarea>
					<div class="kn-dm-md-preview" id="kn-dm-reign-preview" style="display:none"></div>
					<div class="kn-dm-counter" id="kn-dm-reign-counter">0 / 2,000</div>
				</div>
			</div>

			<div class="kn-dm-panel" id="kn-dm-panel-about">
				<div class="kn-dm-field">
					<label>Social Links</label>
					<div class="kn-dm-hint" style="margin-bottom:8px">Add any platforms your kingdom uses. Empty fields aren't shown. We'll add <code>https://</code> automatically if you omit it.</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#5865f2"><i class="fab fa-discord"></i></span>Discord</div>
						<input type="url" data-knsoc="discord" placeholder="https://discord.gg/..." value="<?= htmlspecialchars((string)($knSocialLinks['discord'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#1877f2"><i class="fab fa-facebook"></i></span>Facebook</div>
						<input type="url" data-knsoc="facebook" placeholder="https://facebook.com/..." value="<?= htmlspecialchars((string)($knSocialLinks['facebook'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:linear-gradient(135deg,#f09433,#dc2743,#bc1888)"><i class="fab fa-instagram"></i></span>Instagram</div>
						<input type="url" data-knsoc="instagram" placeholder="https://instagram.com/..." value="<?= htmlspecialchars((string)($knSocialLinks['instagram'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#000"><i class="fab fa-threads"></i></span>Threads</div>
						<input type="url" data-knsoc="threads" placeholder="https://threads.net/..." value="<?= htmlspecialchars((string)($knSocialLinks['threads'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#1185fe"><i class="fas fa-cloud"></i></span>Bluesky</div>
						<input type="url" data-knsoc="bluesky" placeholder="https://bsky.app/..." value="<?= htmlspecialchars((string)($knSocialLinks['bluesky'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#000"><i class="fab fa-x-twitter"></i></span>X</div>
						<input type="url" data-knsoc="twitter" placeholder="https://x.com/..." value="<?= htmlspecialchars((string)($knSocialLinks['twitter'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#ff0000"><i class="fab fa-youtube"></i></span>YouTube</div>
						<input type="url" data-knsoc="youtube" placeholder="https://youtube.com/..." value="<?= htmlspecialchars((string)($knSocialLinks['youtube'] ?? '')) ?>" maxlength="500" />
					</div>
					<div class="kn-dm-social-row">
						<div class="kn-dm-social-label"><span class="kn-dm-social-icon-chip" style="background:#6b7280"><i class="fas fa-book"></i></span>AmtWiki</div>
						<input type="url" data-knsoc="amtwiki" placeholder="https://amtwiki.net/..." value="<?= htmlspecialchars((string)($knSocialLinks['amtwiki'] ?? '')) ?>" maxlength="500" />
					</div>
				</div>

				<div class="kn-dm-hint" style="margin-bottom:14px"><i class="fas fa-info-circle" style="margin-right:6px"></i>Both fields below support <strong>Markdown</strong>. Use <em>About</em> for a current snapshot of the kingdom; use <em>Our History</em> for the founding story, past reigns, and notable moments.</div>

				<div class="kn-dm-field">
					<div class="kn-dm-md-toolbar">
						<label style="margin-bottom:0">About <?= htmlspecialchars($kingdom_name) ?></label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="kn-dm-md-toggle">
								<button type="button" class="kn-active" data-knmd-target="edit" data-knmd-field="about">Write</button>
								<button type="button" data-knmd-target="preview" data-knmd-field="about">Preview</button>
							</div>
							<div class="kn-dm-md-quick">
								<button type="button" class="kn-dm-md-quick-btn" data-knquick="newbies" data-knfield="about"><i class="fas fa-hand-sparkles"></i> New Player Welcome</button>
								<button type="button" class="kn-dm-md-quick-btn" data-knquick="vibe" data-knfield="about"><i class="fas fa-fire"></i> Kingdom Vibe</button>
								<button type="button" class="kn-dm-md-quick-btn" data-knquick="findus" data-knfield="about"><i class="fas fa-map-marker-alt"></i> Where We Play</button>
							</div>
						</div>
					</div>
					<textarea id="kn-dm-about-text" maxlength="10000" placeholder="Welcome to the kingdom... (Markdown supported)"><?= htmlspecialchars($aboutText) ?></textarea>
					<div class="kn-dm-md-preview" id="kn-dm-about-preview" style="display:none"></div>
				</div>

				<div class="kn-dm-field">
					<div class="kn-dm-md-toolbar">
						<label style="margin-bottom:0">Our History</label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="kn-dm-md-toggle">
								<button type="button" class="kn-active" data-knmd-target="edit" data-knmd-field="history">Write</button>
								<button type="button" data-knmd-target="preview" data-knmd-field="history">Preview</button>
							</div>
							<div class="kn-dm-md-quick">
								<button type="button" class="kn-dm-md-quick-btn" data-knquick="founding" data-knfield="history"><i class="fas fa-flag"></i> Founding</button>
								<button type="button" class="kn-dm-md-quick-btn" data-knquick="charter" data-knfield="history"><i class="fas fa-chess-rook"></i> Charter</button>
								<button type="button" class="kn-dm-md-quick-btn" data-knquick="pastmonarchs" data-knfield="history"><i class="fas fa-crown"></i> Past Monarchs</button>
							</div>
						</div>
					</div>
					<textarea id="kn-dm-history-text" maxlength="10000" placeholder="The kingdom was founded in... (Markdown supported)"><?= htmlspecialchars($ourHistoryText) ?></textarea>
					<div class="kn-dm-md-preview" id="kn-dm-history-preview" style="display:none"></div>
				</div>
			</div>

			<div class="kn-dm-panel" id="kn-dm-panel-milestones">
				<div class="kn-dm-hint" style="margin-bottom:10px">Milestones appear in the sidebar in date order. Some are derived from attendance data (first sign-in, count thresholds); the rest are custom entries you add below.</div>

				<div class="kn-dm-field">
					<label>Visible Milestone Types</label>
					<div class="kn-dm-ms-toggles" id="kn-dm-ms-toggles">
						<label class="kn-dm-ms-toggle"><input type="checkbox" data-knms-type="first_attendance" <?= $knMsVisible('first_attendance') ? 'checked' : '' ?> /> <i class="fas fa-door-open"></i> First Attendance</label>
						<label class="kn-dm-ms-toggle"><input type="checkbox" data-knms-type="attendance_count" <?= $knMsVisible('attendance_count') ? 'checked' : '' ?> /> <i class="fas fa-clipboard-list"></i> Attendance Crossings</label>
						<label class="kn-dm-ms-toggle"><input type="checkbox" data-knms-type="distinct_members" <?= $knMsVisible('distinct_members') ? 'checked' : '' ?> /> <i class="fas fa-users"></i> Member Crossings</label>
						<label class="kn-dm-ms-toggle"><input type="checkbox" data-knms-type="custom" <?= $knMsVisible('custom') ? 'checked' : '' ?> /> <i class="fas fa-pen"></i> Custom Milestones</label>
					</div>
					<label class="kn-dm-ms-toggle" style="margin-top:4px">
						<input type="checkbox" id="kn-dm-ms-newest-first" <?= $knMsNewestFirst ? 'checked' : '' ?> />
						Show newest first
					</label>
				</div>

				<div class="kn-dm-field">
					<label>Custom Milestones</label>
					<div class="kn-dm-ms-list" id="kn-dm-ms-list"></div>
					<div class="kn-dm-ms-add">
						<div>
							<input type="text" id="kn-dm-ms-add-desc" placeholder="What happened?" maxlength="500" />
						</div>
						<div>
							<input type="date" id="kn-dm-ms-add-date" />
						</div>
						<div>
							<button type="button" class="kn-dm-btn kn-dm-btn-primary" id="kn-dm-ms-add-btn" style="width:100%"><i class="fas fa-plus"></i> Add</button>
						</div>
					</div>
					<div class="kn-dm-ms-icons" id="kn-dm-ms-icons" style="margin-top:8px">
						<?php $_knIcons = ['fa-star','fa-trophy','fa-flag','fa-chess-rook','fa-crown','fa-medal','fa-shield-alt','fa-fire','fa-bolt','fa-scroll','fa-campground','fa-map-marker-alt','fa-users','fa-dragon','fa-hammer','fa-heart']; ?>
						<?php foreach ($_knIcons as $_ic): ?>
						<div class="kn-dm-ms-icon-opt<?= $_ic === 'fa-star' ? ' kn-active' : '' ?>" data-icon="<?= htmlspecialchars($_ic) ?>"><i class="fas <?= htmlspecialchars($_ic) ?>"></i></div>
						<?php endforeach; ?>
					</div>
					<div class="kn-dm-hint" id="kn-dm-ms-add-err" style="color:#c53030;display:none;margin-top:6px"></div>
				</div>
			</div>
		</div>
		<div class="kn-dm-footer">
			<button class="kn-dm-btn" id="kn-dm-cancel">Cancel</button>
			<button class="kn-dm-btn kn-dm-btn-primary" id="kn-dm-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<script>
(function() {
	var KINGDOM_ID = <?= (int)$kingdom_id ?>;
	var BASE_UIR = '<?= UIR ?>';
	var KN_FONTS = [
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
	var KN_QUICKS = {
		newbies: 'New players welcome! Every park in the kingdom keeps loaner gear on hand and our experienced fighters love teaching the ropes. Find a park near you on the Map tab.',
		vibe:    "## The Vibe\n\nFamily-friendly, hard-hitting, and warm. Whether you swing a sword, paint a banner, or sing a song around the fire, there's a place for you here.",
		findus:  "## Where We Play\n\nWe have parks across the kingdom — check the **Map** tab to find your nearest one. Visit a park day, an event, or both.",
		founding:"## Founding\n\nThe kingdom was founded in **YYYY** by a group of players meeting at _location_. It was first chartered under _circumstances_.",
		charter: "## Charter History\n\n- **YYYY** — Chartered as a Shire under Kingdom of _parent_\n- **YYYY** — Elevated to a Principality\n- **YYYY** — Elevated to a full Kingdom\n_(Edit dates and lines as appropriate)_",
		pastmonarchs:"## Past Monarchs & Regents\n\n- **YYYY–YYYY** — _Persona_ (Monarch), _Persona_ (Regent)\n- **YYYY–YYYY** — _Persona_ (Monarch), _Persona_ (Regent)"
	};
	var INITIAL_CUSTOM_MS = <?php
		$customOnly = array_values(array_filter($knAllMilestones, function($m){ return empty($m['IsDerived']); }));
		echo json_encode(array_map(function($m){
			return [
				'MilestoneId'   => (int)$m['MilestoneId'],
				'Icon'          => $m['Icon'],
				'Description'   => $m['Description'],
				'MilestoneDate' => $m['MilestoneDate'],
			];
		}, $customOnly));
	?>;
	var knSelectedFont = <?= json_encode($knNameFont) ?>;
	var knSelectedIcon = 'fa-star';
	var customMs = INITIAL_CUSTOM_MS.slice();

	function gid(id) { return document.getElementById(id); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

	window.knOpenDesignModal = function(panel) {
		gid('kn-dm-overlay').classList.add('kn-open');
		document.body.style.overflow = 'hidden';
		if (panel) knSwitchDmPanel(panel);
		renderCustomMsList();
	};
	function close() {
		gid('kn-dm-overlay').classList.remove('kn-open');
		document.body.style.overflow = '';
	}
	gid('kn-dm-close').addEventListener('click', close);
	gid('kn-dm-cancel').addEventListener('click', close);
	gid('kn-dm-overlay').addEventListener('click', function(e) {
		if (e.target === this) close();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('kn-dm-overlay').classList.contains('kn-open')) close();
	});

	function knSwitchDmPanel(name) {
		document.querySelectorAll('.kn-dm-tab').forEach(function(t) { t.classList.remove('kn-active'); });
		document.querySelectorAll('.kn-dm-panel').forEach(function(p) { p.classList.remove('kn-active'); });
		var tab = document.querySelector('.kn-dm-tab[data-kntab-dm="' + name + '"]');
		var panel = gid('kn-dm-panel-' + name);
		if (tab) tab.classList.add('kn-active');
		if (panel) panel.classList.add('kn-active');
	}
	document.querySelectorAll('.kn-dm-tab').forEach(function(t) {
		t.addEventListener('click', function() { knSwitchDmPanel(t.dataset.kntabDm); });
	});

	var swatches = document.querySelectorAll('.kn-dm-swatch');
	swatches.forEach(function(sw) {
		sw.addEventListener('click', function() {
			swatches.forEach(function(s) { s.classList.remove('kn-selected'); });
			sw.classList.add('kn-selected');
			gid('kn-dm-color-primary').value     = sw.dataset.primary;
			gid('kn-dm-color-primary-hex').value = sw.dataset.primary;
			gid('kn-dm-color-accent').value      = sw.dataset.accent;
			gid('kn-dm-color-accent-hex').value  = sw.dataset.accent;
			if (sw.dataset.secondary) {
				gid('kn-dm-color-secondary').value     = sw.dataset.secondary;
				gid('kn-dm-color-secondary-hex').value = sw.dataset.secondary;
				gid('kn-dm-gradient-enabled').checked  = true;
			} else {
				gid('kn-dm-color-secondary-hex').value = '';
				gid('kn-dm-gradient-enabled').checked  = false;
			}
		});
	});
	function syncHex(colorId, hexId) {
		gid(colorId).addEventListener('input', function() { gid(hexId).value = this.value; });
		gid(hexId).addEventListener('input', function() {
			if (/^#[0-9a-f]{6}$/i.test(this.value)) { gid(colorId).value = this.value; }
		});
	}
	syncHex('kn-dm-color-primary',   'kn-dm-color-primary-hex');
	syncHex('kn-dm-color-accent',    'kn-dm-color-accent-hex');
	syncHex('kn-dm-color-secondary', 'kn-dm-color-secondary-hex');

	document.querySelectorAll('.kn-dm-overlay-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.kn-dm-overlay-btn').forEach(function(b) { b.classList.remove('kn-active'); });
			btn.classList.add('kn-active');
			gid('kn-dm-hero-overlay').value = btn.dataset.overlay;
		});
	});

	function knLoadFont(key) {
		if (!key) return;
		if (document.querySelector('link[data-kn-font="' + key + '"]')) return;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = 'https://fonts.googleapis.com/css2?family=' + key.replace(/ /g, '+') + '&display=swap';
		link.setAttribute('data-kn-font', key);
		document.head.appendChild(link);
	}
	function knRenderFontPicker() {
		var container = gid('kn-dm-font-picker');
		var sample = <?= json_encode($kingdom_name) ?>;
		var html = '';
		for (var i = 0; i < KN_FONTS.length; i++) {
			var f = KN_FONTS[i];
			var active = f.key === knSelectedFont;
			html += '<div class="kn-dm-font-card' + (active ? ' kn-active' : '') + '" data-font-key="' + esc(f.key) + '">'
				 +    '<div class="kn-dm-font-sample" style="font-family:' + f.family + '">' + esc(sample) + '</div>'
				 +    '<div class="kn-dm-font-label">' + esc(f.label) + '</div>'
				 + '</div>';
			knLoadFont(f.key);
		}
		container.innerHTML = html;
		container.addEventListener('click', function(e) {
			var card = e.target.closest('.kn-dm-font-card');
			if (!card) return;
			knSelectedFont = card.dataset.fontKey;
			container.querySelectorAll('.kn-dm-font-card').forEach(function(c) {
				c.classList.toggle('kn-active', c === card);
			});
		});
	}
	knRenderFontPicker();

	function knBindCounter(taId, counterId, limit, formatted) {
		var ta = gid(taId); var c = gid(counterId);
		if (!ta || !c) return;
		function upd() {
			var n = (ta.value || '').length;
			c.textContent = n + ' / ' + (formatted || limit);
			c.classList.toggle('kn-over', n > limit);
		}
		ta.addEventListener('input', upd);
		upd();
	}
	knBindCounter('kn-dm-tagline',       'kn-dm-tagline-counter',       160);
	knBindCounter('kn-dm-announcement',  'kn-dm-announcement-counter',  280);
	knBindCounter('kn-dm-reign-text',    'kn-dm-reign-counter',         2000, '2,000');
	var _knAnClear = gid('kn-dm-announcement-clear');
	if (_knAnClear) _knAnClear.addEventListener('click', function() { gid('kn-dm-announcement-until').value = ''; });

	function knTaIdForField(field) {
		if (field === 'about')   return 'kn-dm-about-text';
		if (field === 'history') return 'kn-dm-history-text';
		if (field === 'reign')   return 'kn-dm-reign-text';
		return 'kn-dm-' + field + '-text';
	}

	document.querySelectorAll('[data-knmd-target]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var field  = btn.dataset.knmdField;
			var target = btn.dataset.knmdTarget;
			var ta     = gid(knTaIdForField(field));
			var pv     = gid('kn-dm-' + field + '-preview');
			btn.parentElement.querySelectorAll('button').forEach(function(b) { b.classList.remove('kn-active'); });
			btn.classList.add('kn-active');
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

	document.querySelectorAll('[data-knquick]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var key   = btn.dataset.knquick;
			var field = btn.dataset.knfield;
			var ta    = gid('kn-dm-' + (field === 'about' ? 'about-text' : 'history-text'));
			if (!ta) return;
			var writeBtn = document.querySelector('[data-knmd-field="' + field + '"][data-knmd-target="edit"]');
			if (writeBtn && !writeBtn.classList.contains('kn-active')) writeBtn.click();
			var snippet = KN_QUICKS[key] || '';
			if (!snippet) return;
			var existing = ta.value;
			var sep = existing.length === 0 ? '' : (existing.endsWith('\n\n') ? '' : (existing.endsWith('\n') ? '\n' : '\n\n'));
			ta.value = existing + sep + snippet;
			ta.focus();
			ta.setSelectionRange(ta.value.length, ta.value.length);
		});
	});

	function renderCustomMsList() {
		var list = gid('kn-dm-ms-list');
		if (!list) return;
		var newestFirst = gid('kn-dm-ms-newest-first').checked;
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
			html += '<div class="kn-dm-ms-row" data-ms-id="' + m.MilestoneId + '">'
				 +    '<i class="fas ' + icon + '"></i>'
				 +    '<span class="kn-dm-ms-desc">' + esc(m.Description) + '</span>'
				 +    '<span class="kn-dm-ms-date">' + dateStr + '</span>'
				 +    '<button type="button" data-tip="Delete" onclick="knDeleteKingdomMilestone(' + m.MilestoneId + ')"><i class="fas fa-trash"></i></button>'
				 + '</div>';
		}
		list.innerHTML = html;
	}
	gid('kn-dm-ms-newest-first').addEventListener('change', renderCustomMsList);

	var iconGrid = gid('kn-dm-ms-icons');
	iconGrid.addEventListener('click', function(e) {
		var opt = e.target.closest('.kn-dm-ms-icon-opt');
		if (!opt) return;
		iconGrid.querySelectorAll('.kn-dm-ms-icon-opt').forEach(function(o) { o.classList.remove('kn-active'); });
		opt.classList.add('kn-active');
		knSelectedIcon = opt.dataset.icon;
	});

	gid('kn-dm-ms-add-btn').addEventListener('click', function() {
		var desc = gid('kn-dm-ms-add-desc').value.trim();
		var date = gid('kn-dm-ms-add-date').value;
		var err  = gid('kn-dm-ms-add-err');
		err.style.display = 'none';
		if (!desc) { err.textContent = 'Description is required.'; err.style.display = ''; return; }
		if (!date) { err.textContent = 'Date is required.'; err.style.display = ''; return; }
		var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
		var fd = new FormData();
		fd.append('Description', desc);
		fd.append('MilestoneDate', date);
		fd.append('Icon', knSelectedIcon);
		fetch(BASE_UIR + 'KingdomAjax/kingdom/' + KINGDOM_ID + '/addmilestone', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					customMs.push({
						MilestoneId: result.milestoneId,
						Icon: knSelectedIcon,
						Description: desc,
						MilestoneDate: date
					});
					renderCustomMsList();
					gid('kn-dm-ms-add-desc').value = '';
					gid('kn-dm-ms-add-date').value = '';
					iconGrid.querySelectorAll('.kn-dm-ms-icon-opt').forEach(function(o) { o.classList.remove('kn-active'); });
					iconGrid.querySelector('[data-icon="fa-star"]').classList.add('kn-active');
					knSelectedIcon = 'fa-star';
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

	window.knDeleteKingdomMilestone = function(id) {
		if (!confirm('Delete this milestone?')) return;
		var fd = new FormData();
		fd.append('MilestoneId', id);
		fetch(BASE_UIR + 'KingdomAjax/kingdom/' + KINGDOM_ID + '/deletemilestone', { method: 'POST', body: fd })
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

	gid('kn-dm-save').addEventListener('click', function() {
		var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		var errEl = gid('kn-dm-error'); errEl.style.display = 'none';
		var fd = new FormData();
		fd.append('AboutText', gid('kn-dm-about-text').value);
		fd.append('OurHistory', gid('kn-dm-history-text').value);
		fd.append('ColorPrimary', gid('kn-dm-color-primary').value);
		fd.append('ColorAccent', gid('kn-dm-color-accent').value);
		fd.append('ColorSecondary', gid('kn-dm-gradient-enabled').checked ? gid('kn-dm-color-secondary').value : '');
		fd.append('HeroOverlay', gid('kn-dm-hero-overlay').value);
		fd.append('NameFont', knSelectedFont || '');
		var msConfig = {};
		document.querySelectorAll('#kn-dm-ms-toggles input[data-knms-type]').forEach(function(t) {
			msConfig[t.dataset.knmsType] = t.checked ? 1 : 0;
		});
		msConfig['newest_first'] = gid('kn-dm-ms-newest-first').checked ? 1 : 0;
		fd.append('MilestoneConfig', JSON.stringify(msConfig));

		fd.append('Tagline', gid('kn-dm-tagline').value);
		fd.append('Announcement', gid('kn-dm-announcement').value);
		fd.append('AnnouncementUntil', gid('kn-dm-announcement-until').value);
		fd.append('MonarchReignStarted', gid('kn-dm-monarch-reign').value);
		fd.append('RegentReignStarted', gid('kn-dm-regent-reign').value);
		fd.append('ReignLore', gid('kn-dm-reign-text').value);

		var socialPayload = {};
		document.querySelectorAll('[data-knsoc]').forEach(function(inp) {
			var v = (inp.value || '').trim();
			if (v) socialPayload[inp.dataset.knsoc] = v;
		});
		fd.append('SocialLinks', JSON.stringify(socialPayload));

		fetch(BASE_UIR + 'KingdomAjax/kingdom/' + KINGDOM_ID + '/savedesign', { method: 'POST', body: fd })
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

	renderCustomMsList();
})();
</script>
<?php endif; ?>
