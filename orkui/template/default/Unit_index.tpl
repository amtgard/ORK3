<?php
require_once(DIR_LIB . 'Parsedown.php');
function un_markdown(string $text): string {
	$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($text);
	return preg_replace('/<img[^>]*>/i', '', $html);
}

/* ── Data prep ─────────────────────────────────────────── */
$_unit     = $Unit['Details']['Unit'] ?? [];
$_members  = $Unit['Members']['Roster'] ?? [];
$_unit_id  = (int)($_unit['UnitId'] ?? 0);
$_type     = $_unit['Type'] ?? 'Unit';
$_name     = $_unit['Name'] ?? '';
$page_title = $_name;
$_desc     = $_unit['Description'] ?? '';
$_history  = $_unit['History'] ?? '';
$_url      = $_unit['Url'] ?? '';
$_hero_src = !empty($_unit['HasHeraldry']) ? ($Unit_heraldryurl['Url'] ?? '') : (HTTP_UNIT_HERALDRY . '00000.jpg');

$_total    = count($_members);
$_cutoff   = date('Y-m-d', strtotime('-1 year'));
$_active   = 0;
foreach ($_members as $_m) {
	if (!empty($_m['LastSignIn']) && $_m['LastSignIn'] >= $_cutoff) $_active++;
}

$_can_edit   = !empty($CanEdit);
$_err        = $SaveError ?? '';
$_base_url   = UIR . "Unit/index/$_unit_id";

$_type_icon  = $_type === 'Company' ? 'fa-shield-alt' : ($_type === 'Household' ? 'fa-home' : 'fa-users');
$_hero_color = $_type === 'Company' ? '#1a3654' : ($_type === 'Household' ? '#2d1b54' : '#1a365d');

/* ── Unit design (header, About, Our History, Milestones) ──────────────── */
$_about_text   = (string)($_unit['AboutText']  ?? '');
$_our_history  = (string)($_unit['OurHistory'] ?? '');
if (trim($_about_text)  === '') { $_about_text  = (string)$_desc; }
if (trim($_our_history) === '') { $_our_history = (string)$_history; }

$_un_color_primary   = trim((string)($_unit['ColorPrimary']   ?? ''));
$_un_color_accent    = trim((string)($_unit['ColorAccent']    ?? ''));
$_un_color_secondary = trim((string)($_unit['ColorSecondary'] ?? ''));
$_un_overlay         = strtolower(trim((string)($_unit['HeroOverlay'] ?? 'med')));
if (!in_array($_un_overlay, ['low','med','high','vignette'], true)) $_un_overlay = 'med';
$_un_name_font       = trim((string)($_unit['NameFont'] ?? ''));
$_un_milestone_cfg   = [];
if (!empty($_unit['MilestoneConfig'])) {
	$_mc = json_decode((string)$_unit['MilestoneConfig'], true);
	if (is_array($_mc)) $_un_milestone_cfg = $_mc;
}
$_un_ms_visible = function($type) use ($_un_milestone_cfg) {
	if (!array_key_exists($type, $_un_milestone_cfg)) return true;
	return !empty($_un_milestone_cfg[$type]);
};
$_un_ms_newest_first = !empty($_un_milestone_cfg['newest_first']);

$_un_all_ms = is_array($Milestones ?? null) ? $Milestones : [];
$_un_visible_ms = [];
foreach ($_un_all_ms as $_msr) {
	$_t = $_msr['Type'] ?? 'custom';
	if ($_un_ms_visible($_t)) $_un_visible_ms[] = $_msr;
}
if ($_un_ms_newest_first) $_un_visible_ms = array_reverse($_un_visible_ms);
$_un_has_ms = count($_un_visible_ms) > 0;

$_un_overlay_opacity = ['low' => 0.06, 'med' => 0.13, 'high' => 0.28, 'vignette' => 0.45][$_un_overlay] ?? 0.13;
$_un_hero_font_css = $_un_name_font !== '' ? ("'" . str_replace("'", '', $_un_name_font) . "'") : '';

/* ── Phase 2 customizations: tagline, social links, announcement, recruitment, how-to-join ── */
$_unTagline            = trim((string)($_unit['Tagline'] ?? ''));
$_unAnnouncement       = trim((string)($_unit['Announcement'] ?? ''));
$_unAnnouncementUntil  = trim((string)($_unit['AnnouncementUntil'] ?? ''));
$_unShowAnnouncement   = ($_unAnnouncement !== '');
if ($_unShowAnnouncement && $_unAnnouncementUntil !== '' && $_unAnnouncementUntil !== '0000-00-00') {
	$_unShowAnnouncement = (strtotime($_unAnnouncementUntil) >= strtotime(date('Y-m-d')));
}
$_unSocialRaw   = (string)($_unit['SocialLinks'] ?? '');
$_unSocialLinks = [];
if ($_unSocialRaw !== '') {
	$_sl = json_decode($_unSocialRaw, true);
	if (is_array($_sl)) {
		foreach ($_sl as $_k => $_v) {
			$_v = trim((string)$_v);
			if ($_v !== '' && preg_match('#^https?://#i', $_v)) {
				$_unSocialLinks[(string)$_k] = $_v;
			}
		}
	}
}
$_unSocialPlatforms = [
	'discord'   => ['label'=>'Discord',   'icon'=>'fab fa-discord',         'bg'=>'#5865f2', 'placeholder'=>'https://discord.gg/...'],
	'facebook'  => ['label'=>'Facebook',  'icon'=>'fab fa-facebook',        'bg'=>'#1877f2', 'placeholder'=>'https://facebook.com/...'],
	'instagram' => ['label'=>'Instagram', 'icon'=>'fab fa-instagram',       'bg'=>'#e4405f', 'placeholder'=>'https://instagram.com/...'],
	'threads'   => ['label'=>'Threads',   'icon'=>'fab fa-square-threads',  'bg'=>'#000000', 'placeholder'=>'https://threads.net/@...'],
	'bluesky'   => ['label'=>'Bluesky',   'icon'=>'fas fa-cloud',           'bg'=>'#1185fe', 'placeholder'=>'https://bsky.app/profile/...'],
	'twitter'   => ['label'=>'Twitter',   'icon'=>'fab fa-twitter',         'bg'=>'#1da1f2', 'placeholder'=>'https://twitter.com/...'],
	'youtube'   => ['label'=>'YouTube',   'icon'=>'fab fa-youtube',         'bg'=>'#ff0000', 'placeholder'=>'https://youtube.com/@...'],
	'amtwiki'   => ['label'=>'Amtwiki',   'icon'=>'fas fa-book',            'bg'=>'#4a5568', 'placeholder'=>'https://amtwiki.net/...'],
];
$_unHasSocial = false;
foreach ($_unSocialPlatforms as $_slug => $_meta) {
	if (!empty($_unSocialLinks[$_slug])) { $_unHasSocial = true; break; }
}
$_unRecruitmentStatus = strtolower(trim((string)($_unit['RecruitmentStatus'] ?? '')));
if (!in_array($_unRecruitmentStatus, ['open','invite','closed'], true)) $_unRecruitmentStatus = '';
$_unRecruitMeta = [
	'open'   => ['label'=>'Recruiting',  'icon'=>'fa-door-open', 'bg'=>'#48bb78', 'bgDark'=>'#22543d'],
	'invite' => ['label'=>'Invite Only', 'icon'=>'fa-envelope',  'bg'=>'#ed8936', 'bgDark'=>'#7b341e'],
	'closed' => ['label'=>'Closed',      'icon'=>'fa-lock',      'bg'=>'#718096', 'bgDark'=>'#2d3748'],
];
$_unHowToJoin = (string)($_unit['HowToJoin'] ?? '');
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>revised-frontend/style/revised.css?v=<?=filemtime(DIR_TEMPLATE.'revised-frontend/style/revised.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<?php if ($_un_name_font !== ''): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= rawurlencode($_un_name_font) ?>&display=swap">
<?php endif; ?>

<style>
/* ── Unit Hero ───────────────────────────────────────────── */
.un-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-top: 3px;
	margin-bottom: 20px;
	min-height: 160px;
	background-color: <?=$_hero_color?>;
}
.un-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.13;
	filter: blur(6px);
}
.un-hero-content {
	position: relative;
	display: flex;
	align-items: center;
	padding: 24px 30px;
	gap: 24px;
	z-index: 1;
}
.un-heraldry-wrap {
	position: relative;
	flex-shrink: 0;
}
.un-heraldry-frame {
	width: 110px;
	height: 110px;
	border-radius: 8px;
	overflow: hidden;
	border: 3px solid rgba(255,255,255,0.8);
	background: rgba(0,0,0,0.15);
	display: flex;
	align-items: center;
	justify-content: center;
}
.un-heraldry-frame img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 0;
	max-width: none;
	max-height: none;
}
.un-heraldry-edit-btn {
	position: absolute;
	bottom: 4px;
	right: 4px;
	width: 24px;
	height: 24px;
	background: rgba(0,0,0,0.6);
	border-radius: 50%;
	border: none;
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0;
	transition: opacity 0.18s;
	cursor: pointer;
	padding: 0;
}
.un-heraldry-wrap:hover .un-heraldry-edit-btn { opacity: 1; }
.un-heraldry-edit-btn i { color: #fff; font-size: 11px; pointer-events: none; }

.un-hero-info {
	flex: 1;
	min-width: 0;
}
.un-type-badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: rgba(255,255,255,0.9);
	background: rgba(255,255,255,0.18);
	border: 1px solid rgba(255,255,255,0.3);
	border-radius: 4px;
	padding: 3px 8px;
	margin-bottom: 8px;
}
.un-hero-name {
	font-size: 26px;
	font-weight: 700;
	color: #fff;
	margin: 0;
	line-height: 1.2;
	text-shadow: 0 1px 3px rgba(0,0,0,0.35);
	/* Reset global h1–h6 styles from orkui.css */
	background: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
}
/* Override dark-mode h1 rule which has higher specificity (html[data-theme] h1 > .class) */
html[data-theme="dark"] .un-hero-name,
html:not([data-theme="light"]):not([data-theme="dark"]) .un-hero-name {
	background: transparent;
	border: none;
	color: #fff;
	text-shadow: 0 1px 3px rgba(0,0,0,0.35);
}
.un-hero-actions {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}

/* ── Section header (Members + Add btn) ─────────────────── */
.un-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}
.un-section-title {
	font-size: 13px;
	font-weight: 700;
	color: var(--ork-text-secondary);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	display: flex;
	align-items: center;
	gap: 6px;
}

/* ── Roster card wrapper ─────────────────────────────────── */
.un-roster-card {
	background: var(--ork-card-bg);
	border: 1px solid var(--ork-border);
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

/* ── Inline action area ──────────────────────────────────── */
.un-action-btns { display: flex; gap: 4px; white-space: nowrap; }

/* ── Error banner ────────────────────────────────────────── */
.un-error-banner {
	background: var(--ork-alert-danger-bg);
	border: 1px solid var(--ork-alert-danger-border);
	border-radius: 6px;
	color: var(--ork-alert-danger-text);
	padding: 10px 14px;
	font-size: 13px;
	margin-bottom: 16px;
	display: flex;
	align-items: center;
	gap: 8px;
}

/* ── Modal title reset (h3 gets global gray-box from orkui.css) ─ */
.pn-modal-title {
	background: transparent !important;
	border: none !important;
	padding: 0 !important;
	border-radius: 0 !important;
	text-shadow: none !important;
	margin: 0 !important;
}

/* ── Modal field normalization ───────────────────────────── */
/* pn-acct-field covers text/email/password/select/textarea; add url/number/file */
.pn-modal-body .pn-acct-field input[type="url"],
.pn-modal-body .pn-acct-field input[type="number"] {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--ork-input-border);
	border-radius: 6px;
	font-size: 14px;
	color: var(--ork-text);
	box-sizing: border-box;
	background: var(--ork-input-bg);
	font-family: inherit;
	transition: border-color 0.15s;
}
.pn-modal-body .pn-acct-field input[type="url"]:focus,
.pn-modal-body .pn-acct-field input[type="number"]:focus {
	outline: none;
	border-color: #3182ce;
	box-shadow: 0 0 0 2px rgba(49,130,206,0.12);
}
.pn-modal-body .pn-acct-field input[type="file"] {
	font-size: 13px;
	color: var(--ork-text-secondary);
	padding: 6px 0;
	display: block;
	width: 100%;
}
.un-field-hint {
	font-size: 11px;
	color: var(--ork-text-lighter);
	margin-top: 3px;
}

/* ── Player search autocomplete (shared across modals) ───── */

.un-player-search { position: relative; }
.un-ac-results {
	position: absolute;
	top: calc(100% + 2px);
	left: 0; right: 0;
	background: var(--ork-card-bg);
	border: 1px solid var(--ork-border-dark);
	border-radius: 6px;
	box-shadow: 0 4px 16px rgba(0,0,0,0.12);
	z-index: 500;
	max-height: 260px;
	overflow-y: auto;
	display: none;
}
.un-ac-results.un-ac-open { display: block; }
.un-ac-group-label {
	padding: 6px 12px 3px;
	font-size: 10px;
	font-weight: 700;
	color: var(--ork-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	background: var(--ork-bg-secondary);
	border-bottom: 1px solid var(--ork-border);
}
.un-ac-item {
	padding: 8px 12px;
	font-size: 13px;
	color: var(--ork-text);
	cursor: pointer;
	transition: background 0.1s;
	display: flex;
	align-items: center;
	gap: 8px;
}
.un-ac-item:hover, .un-ac-item.un-ac-focused { background: var(--ork-bg-tertiary); }
.un-ac-scope {
	font-size: 10px;
	color: var(--ork-text-muted);
	margin-left: auto;
	white-space: nowrap;
}
.un-ac-empty {
	padding: 10px 12px;
	font-size: 13px;
	color: var(--ork-text-muted);
	font-style: italic;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
	/* Hero */
	.un-hero { margin-bottom: 10px; }
	.un-hero-content { flex-wrap: wrap; padding: 18px 20px; }
	.un-hero-actions { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; }
	.un-hero-name { font-size: 21px; }
	/* Sidebar above roster on mobile (override revised.css order values) */
	.pn-sidebar { order: 1 !important; }
	.pn-main    { order: 2 !important; }
	/* Hide button text labels */
	.un-btn-label { display: none; }
	/* Hide less-important roster columns */
	#un-roster-table th:nth-child(2),
	#un-roster-table td:nth-child(2),
	#un-roster-table th:nth-child(3),
	#un-roster-table td:nth-child(3),
	#un-roster-table th:nth-child(5),
	#un-roster-table td:nth-child(5) { display: none; }
}

/* ── Unit design: hero customization ─────────────────────── */
<?php if ($_un_color_primary): ?>
.un-hero {
	background-color: <?= htmlspecialchars($_un_color_primary) ?> !important;
<?php if ($_un_color_secondary && $_un_color_secondary !== $_un_color_primary): ?>
	background: linear-gradient(135deg, <?= htmlspecialchars($_un_color_primary) ?>, <?= htmlspecialchars($_un_color_secondary) ?>) !important;
<?php endif; ?>
}
html[data-theme="dark"] .un-hero {
	background-color: <?= htmlspecialchars($_un_color_primary) ?> !important;
<?php if ($_un_color_secondary && $_un_color_secondary !== $_un_color_primary): ?>
	background: linear-gradient(135deg, <?= htmlspecialchars($_un_color_primary) ?>, <?= htmlspecialchars($_un_color_secondary) ?>) !important;
<?php endif; ?>
	filter: brightness(0.85);
}
<?php endif; ?>
<?php if ($_un_color_accent): ?>
:root { --un-accent: <?= htmlspecialchars($_un_color_accent) ?>; }
.un-type-badge { border-color: <?= htmlspecialchars($_un_color_accent) ?>; color: <?= htmlspecialchars($_un_color_accent) ?>; background: rgba(255,255,255,0.95); }
.un-about-edit-btn:hover, .un-card-edit-btn:hover { color: <?= htmlspecialchars($_un_color_accent) ?>; }
<?php endif; ?>
.un-hero-bg { opacity: <?= $_un_overlay_opacity ?> !important; }
<?php if ($_un_overlay === 'vignette'): ?>
.un-hero-bg {
	-webkit-mask-image: radial-gradient(ellipse at center, rgba(0,0,0,0.95) 38%, rgba(0,0,0,0) 78%);
	        mask-image: radial-gradient(ellipse at center, rgba(0,0,0,0.95) 38%, rgba(0,0,0,0) 78%);
}
<?php endif; ?>
<?php if ($_un_name_font !== '' && $_un_hero_font_css !== ''): ?>
.un-hero-name { font-family: <?= $_un_hero_font_css ?>, 'Cinzel', serif !important; letter-spacing: 0.02em; }
<?php endif; ?>

/* ── Unit design: About markdown + Our History + Milestones ── */
.un-about-text { line-height: 1.6; font-size: 13px; color: var(--ork-text-secondary); }
.un-about-text h1, .un-about-text h2, .un-about-text h3, .un-about-text h4 {
	margin-top: 1.1em; margin-bottom: 0.4em;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.un-about-section-head {
	display: flex; align-items: center; justify-content: space-between;
	gap: 8px; margin-bottom: 6px;
}
.un-about-edit-btn {
	background: transparent; border: 1px solid transparent; color: #a0aec0;
	padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 12px;
	display: inline-flex; align-items: center; gap: 4px;
	transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.un-about-edit-btn:hover { background: rgba(49,130,206,0.08); color: #2b6cb0; border-color: rgba(49,130,206,0.25); }
html[data-theme="dark"] .un-about-edit-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .un-about-edit-btn:hover { background: var(--ork-bg-tertiary); color: var(--ork-link); border-color: var(--ork-border); }
.un-about-edit-btn[data-tip] { position: relative; }
.un-about-edit-btn[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; font-style: italic; white-space: nowrap;
	padding: 4px 10px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0s; z-index: 500;
}
.un-about-edit-btn[data-tip]:hover::after { opacity: 1; transition-delay: 0.4s; }
html[data-theme="dark"] .un-about-edit-btn[data-tip]::after { background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border); }

.un-fullwidth-section {
	background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
	padding: 18px 22px; margin-bottom: 20px;
}
html[data-theme="dark"] .un-fullwidth-section { background: var(--ork-card-bg); border-color: var(--ork-border); }
.un-fullwidth-head {
	display: flex; align-items: center; justify-content: space-between;
	margin-bottom: 10px;
}
.un-fullwidth-title {
	font-size: 13px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.5px;
	margin: 0;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	display: flex; align-items: center; gap: 8px;
}
html[data-theme="dark"] .un-fullwidth-title { color: var(--ork-text-secondary); }

/* Milestones timeline */
.un-timeline { position: relative; padding-left: 32px; margin-top: 6px; }
.un-timeline::before {
	content: ''; position: absolute; left: 11px; top: 4px; bottom: 4px; width: 2px;
	background: linear-gradient(to bottom, #cbd5e0, #cbd5e0 60%, transparent);
}
html[data-theme="dark"] .un-timeline::before { background: linear-gradient(to bottom, var(--ork-border), var(--ork-border) 60%, transparent); }
.un-timeline-row {
	position: relative; display: flex; align-items: center; gap: 12px;
	margin-bottom: 14px; min-height: 24px;
}
.un-timeline-dot {
	position: absolute; left: -25px; top: 50%; transform: translateY(-50%);
	width: 24px; height: 24px; border-radius: 50%;
	background: #ebf8ff; color: #2b6cb0; display: flex; align-items: center; justify-content: center;
	font-size: 11px; border: 2px solid #fff; box-shadow: 0 0 0 1px #cbd5e0;
}
.un-timeline-row.un-ms-derived .un-timeline-dot { background: #faf5ff; color: #6b46c1; box-shadow: 0 0 0 1px #d6bcfa; }
html[data-theme="dark"] .un-timeline-dot { background: var(--ork-bg-tertiary); color: var(--ork-link); border-color: var(--ork-card-bg); box-shadow: 0 0 0 1px var(--ork-border); }
.un-timeline-content {
	flex: 1; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
	font-size: 13px;
}
.un-timeline-date { color: #718096; font-size: 11px; font-weight: 600; min-width: 80px; }
html[data-theme="dark"] .un-timeline-date { color: var(--ork-text-muted); }
.un-timeline-desc { color: #2d3748; }
html[data-theme="dark"] .un-timeline-desc { color: var(--ork-text); }
.un-timeline-row.un-ms-derived .un-timeline-desc { color: #553c9a; }
html[data-theme="dark"] .un-timeline-row.un-ms-derived .un-timeline-desc { color: hsl(265, 60%, 75%); }
.un-timeline-empty {
	font-size: 12px; color: #a0aec0; font-style: italic;
	padding: 6px 0;
}

/* ── Tabs (About / Members) ──────────────────────────────── */
.un-tabs {
	background: var(--ork-card-bg);
	border: 1px solid #cbd5e0;
	border-radius: 8px;
	box-shadow: 0 2px 8px rgba(0,0,0,0.10);
	overflow: hidden;
	min-height: 50vh;
	margin-top: 4px;
}
html[data-theme="dark"] .un-tabs { border-color: var(--ork-border); }
.un-tab-nav {
	list-style: none;
	margin: 0; padding: 0;
	display: flex;
	border-bottom: 2px solid var(--ork-border, #e2e8f0);
	background: var(--ork-bg-secondary);
	flex-wrap: nowrap;
	overflow-x: auto;
	overflow-y: hidden;
}
.un-tab-nav li {
	padding: 12px 18px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 600;
	color: #718096;
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s, background 0.15s;
	white-space: nowrap;
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}
.un-tab-nav li:hover { color: var(--un-accent, #2b6cb0); background: #edf2f7; }
.un-tab-nav li.un-tab-active {
	color: var(--un-accent, #2b6cb0);
	border-bottom-color: var(--un-accent, #2b6cb0);
	background: var(--ork-card-bg);
}
.un-tab-count { font-size: 11px; color: #a0aec0; font-weight: 500; }
.un-tab-panel { padding: 18px 20px; }
html[data-theme="dark"] .un-tab-nav { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .un-tab-nav li { color: var(--ork-text-secondary); }
html[data-theme="dark"] .un-tab-nav li:hover:not(.un-tab-active) { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .un-tab-nav li.un-tab-active { background: var(--ork-card-bg); color: var(--un-accent, var(--ork-link)); border-bottom-color: var(--un-accent, var(--ork-link)); }
html[data-theme="dark"] .un-tab-count { color: var(--ork-text-muted); }

/* About tab inner sections — section dividers */
.un-about-section { margin-bottom: 6px; }
.un-tab-panel .un-fullwidth-section {
	background: transparent; border: none; box-shadow: none;
	padding: 0; margin-bottom: 0;
}
.un-tab-panel .un-fullwidth-section + .un-fullwidth-section,
.un-tab-panel .un-about-section + .un-fullwidth-section {
	margin-top: 18px; padding-top: 18px; border-top: 1px dashed #e2e8f0;
}
html[data-theme="dark"] .un-tab-panel .un-fullwidth-section + .un-fullwidth-section,
html[data-theme="dark"] .un-tab-panel .un-about-section + .un-fullwidth-section { border-top-color: var(--ork-border); }

/* Managers section inside About tab — officer-style list */
.un-managers-list { list-style: none; margin: 0; padding: 0; }
.un-managers-list li {
	font-size: 13px; padding: 6px 0; border-bottom: 1px solid #f0f0f0;
	display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.un-managers-list li:last-child { border-bottom: none; }
html[data-theme="dark"] .un-managers-list li { border-bottom-color: var(--ork-border); }
.un-mgr-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.un-mgr-role { font-size: 10px; color: #718096; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; }
html[data-theme="dark"] .un-mgr-role { color: var(--ork-text-muted); }
.un-mgr-name a { color: var(--ork-link); text-decoration: none; font-weight: 500; }
.un-mgr-name a:hover { text-decoration: underline; }
.un-managers-empty { font-size: 12px; color: var(--ork-text-muted); font-style: italic; margin: 0; padding: 4px 0; }

/* Members tab — section header sits flush at top */
#un-tab-members .un-section-header { margin-bottom: 14px; }
#un-tab-members .un-roster-card {
	border: none; box-shadow: none; background: transparent;
}

/* Mobile: tab panel padding tightens */
@media (max-width: 768px) {
	.un-tab-panel { padding: 14px 14px; }
	.un-tab-nav li { padding: 10px 14px; font-size: 12px; }
}

/* ── Phase 2: Tagline ─────────────────────────────────── */
.un-tagline {
	margin: 6px 0 0;
	font-size: 14px;
	font-style: italic;
	color: rgba(255,255,255,0.92);
	text-shadow: 0 1px 2px rgba(0,0,0,0.35);
	line-height: 1.35;
	max-width: 100%;
}
@media (max-width: 768px) {
	.un-tagline { font-size: 13px; }
}

/* ── Phase 2: Announcement Banner ─────────────────────── */
.un-announcement {
	display: flex; align-items: flex-start; gap: 10px;
	background: #fef3c7;
	border: 1px solid #fcd34d;
	border-left: 4px solid #d97706;
	color: #92400e;
	padding: 12px 16px;
	border-radius: 8px;
	margin-bottom: 12px;
	font-size: 13.5px;
	line-height: 1.45;
}
.un-announcement i { color: #d97706; flex-shrink: 0; font-size: 16px; margin-top: 2px; }
.un-announcement .un-ann-text { flex: 1; word-break: break-word; white-space: pre-wrap; }
html[data-theme="dark"] .un-announcement {
	background: rgba(217,119,6,0.12);
	border-color: rgba(217,119,6,0.45);
	border-left-color: #f59e0b;
	color: #fde68a;
}
html[data-theme="dark"] .un-announcement i { color: #f59e0b; }

/* ── Phase 2: Recruitment Pill ────────────────────────── */
.un-recruit-pill {
	display: inline-flex; align-items: center; gap: 5px;
	font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
	text-transform: uppercase; color: #fff;
	border-radius: 4px; padding: 3px 8px;
	margin-left: 6px;
	border: 1px solid rgba(255,255,255,0.18);
	box-shadow: 0 1px 2px rgba(0,0,0,0.18);
}
.un-recruit-pill i { font-size: 11px; }
.un-recruit-open   { background: #48bb78; }
.un-recruit-invite { background: #ed8936; }
.un-recruit-closed { background: #718096; }
html[data-theme="dark"] .un-recruit-open   { background: #22543d; border-color: rgba(255,255,255,0.12); }
html[data-theme="dark"] .un-recruit-invite { background: #7b341e; border-color: rgba(255,255,255,0.12); }
html[data-theme="dark"] .un-recruit-closed { background: #2d3748; border-color: rgba(255,255,255,0.12); }

/* ── Phase 2: Connect — compact strip in About tab ───── */
.un-connect-block {
	display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
	margin-bottom: 18px; padding: 10px 14px;
	background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px;
}
html[data-theme="dark"] .un-connect-block { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.un-connect-subhead {
	display: flex; align-items: center; gap: 8px;
	font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
	color: #718096; font-weight: 600;
}
html[data-theme="dark"] .un-connect-subhead { color: var(--ork-text-muted); }
.un-connect-subhead i { opacity: 0.7; }
.un-connect-edit {
	background: transparent; border: 0; cursor: pointer; color: #a0aec0; font-size: 11px; padding: 2px 6px; border-radius: 4px;
}
.un-connect-edit:hover { background: rgba(49,130,206,0.08); color: #2b6cb0; }
html[data-theme="dark"] .un-connect-edit:hover { background: var(--ork-bg-tertiary); color: var(--ork-link); }
.un-connect-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.un-connect-pill {
	width: 32px; height: 32px; border-radius: 50%;
	display: inline-flex; align-items: center; justify-content: center;
	color: #fff !important; text-decoration: none !important;
	font-size: 13px; line-height: 1;
	background: var(--un-accent, #4a5568);
	transition: transform 0.12s, box-shadow 0.12s;
	position: relative;
}
.un-connect-pill:hover { transform: scale(1.08); box-shadow: 0 3px 10px rgba(0,0,0,0.18); }
.un-connect-pill[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.un-connect-pill[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .un-connect-pill[data-tip]::after {
	background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border);
}
.un-connect-empty {
	display: inline-flex; font-size: 11px; color: #a0aec0; text-decoration: none;
	padding: 4px 8px; border: 1px dashed #cbd5e0; border-radius: 999px;
}
.un-connect-empty:hover { color: #2b6cb0; border-color: #2b6cb0; }
html[data-theme="dark"] .un-connect-empty { color: var(--ork-text-muted); border-color: var(--ork-border); }

/* ── Phase 2: How to Join section ─────────────────────── */
.un-howto-section .un-fullwidth-title i { color: #38a169; }
html[data-theme="dark"] .un-howto-section .un-fullwidth-title i { color: #68d391; }

/* ── Phase 2: Design modal — social inputs ────────────── */
.un-dm-social-list { display: flex; flex-direction: column; gap: 8px; }
.un-dm-social-row {
	display: grid;
	grid-template-columns: 120px 1fr;
	gap: 10px;
	align-items: center;
}
.un-dm-social-label {
	display: inline-flex; align-items: center; gap: 8px;
	font-size: 12px; font-weight: 600; color: #4a5568;
}
html[data-theme="dark"] .un-dm-social-label { color: var(--ork-text-secondary); }
.un-dm-social-icon {
	display: inline-flex; align-items: center; justify-content: center;
	width: 22px; height: 22px; border-radius: 50%;
	color: #fff; font-size: 11px;
}
@media (max-width: 600px) {
	.un-dm-social-row { grid-template-columns: 1fr; gap: 4px; }
}

/* ── Phase 2: Design modal — recruitment radio row ────── */
.un-dm-recruit-row { display: flex; flex-wrap: wrap; gap: 6px; }
.un-dm-recruit-opt {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 7px 12px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	background: #fff;
	font-size: 12px; font-weight: 600;
	color: #4a5568; cursor: pointer;
	transition: background 0.12s, border-color 0.12s, color 0.12s;
}
.un-dm-recruit-opt:hover { border-color: #cbd5e0; }
.un-dm-recruit-opt.un-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .un-dm-recruit-opt { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .un-dm-recruit-opt.un-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }

/* ── Phase 2: Design modal — section divider ──────────── */
.un-dm-section-title {
	font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
	color: #718096; margin: 18px 0 8px; padding-top: 14px;
	border-top: 1px solid #edf2f7;
}
.un-dm-section-title:first-child { margin-top: 0; padding-top: 0; border-top: none; }
html[data-theme="dark"] .un-dm-section-title { color: var(--ork-text-muted); border-top-color: var(--ork-border); }

</style>

<?php if ($_err): ?>
<div class="un-error-banner">
	<i class="fas fa-exclamation-circle"></i>
	<?=htmlspecialchars($_err)?>
</div>
<?php endif; ?>

<?php if ($_unShowAnnouncement): ?>
<div class="un-announcement" role="status">
	<i class="fas fa-bullhorn"></i>
	<div class="un-ann-text"><?= htmlspecialchars($_unAnnouncement) ?></div>
</div>
<?php endif; ?>

<!-- ── Hero ─────────────────────────────────────────────── -->
<div class="un-hero">
	<div class="un-hero-bg" style="background-image:url('<?=htmlspecialchars($_hero_src)?>')"></div>
	<div class="un-hero-content">

		<!-- Heraldry -->
		<div class="un-heraldry-wrap">
			<div class="un-heraldry-frame">
				<img class="heraldry-img" src="<?=htmlspecialchars($_hero_src)?>"
					onerror="this.onerror=null;this.src='<?=HTTP_UNIT_HERALDRY?>00000.jpg'"
					alt="<?=htmlspecialchars($_name)?>">
			</div>
<?php if ($_can_edit): ?>
			<button class="un-heraldry-edit-btn" onclick="unOpenHeraldryModal()" data-tip="Update heraldry">
				<i class="fas fa-camera"></i>
			</button>
<?php endif; ?>
		</div>

		<!-- Name / type -->
		<div class="un-hero-info">
			<div>
				<span class="un-type-badge">
					<i class="fas <?=$_type_icon?>"></i>
					<?=htmlspecialchars($_type)?>
				</span>
<?php if ($_unRecruitmentStatus !== ''): $_rm = $_unRecruitMeta[$_unRecruitmentStatus]; ?>
				<span class="un-recruit-pill un-recruit-<?= htmlspecialchars($_unRecruitmentStatus) ?>" data-tip="Recruitment status">
					<i class="fas <?= htmlspecialchars($_rm['icon']) ?>"></i>
					<?= htmlspecialchars($_rm['label']) ?>
				</span>
<?php endif; ?>
			</div>
			<h1 class="un-hero-name"><?=htmlspecialchars($_name)?></h1>
<?php if ($_unTagline !== ''): ?>
			<p class="un-tagline"><?= htmlspecialchars($_unTagline) ?></p>
<?php endif; ?>
		</div>

		<!-- Actions -->
		<div class="un-hero-actions">
<?php if (trimlen($_url) > 0): ?>
			<a class="pn-btn pn-btn-outline" href="<?=htmlspecialchars($_url)?>" target="_blank" rel="noopener noreferrer">
				<i class="fas fa-external-link-alt"></i><span class="un-btn-label"> Website</span>
			</a>
<?php endif; ?>
<?php if ($_can_edit): ?>
			<button class="pn-btn pn-btn-white" onclick="unOpenModal('un-modal-details')">
				<i class="fas fa-pen"></i><span class="un-btn-label"> Edit Details</span>
			</button>
			<button class="pn-btn pn-btn-white" onclick="unOpenDesignModal()">
				<i class="fas fa-palette"></i><span class="un-btn-label"> Design</span>
			</button>
<?php endif; ?>
		</div>

	</div>
</div>

<!-- ── Stats Row ─────────────────────────────────────────── -->
<div class="pn-stats-row">
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-users"></i></div>
		<div class="pn-stat-number"><?=$_total?></div>
		<div class="pn-stat-label">Members</div>
	</div>
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-user-check"></i></div>
		<div class="pn-stat-number"><?=$_active?></div>
		<div class="pn-stat-label">Active (12 mo)</div>
	</div>
</div>

<!-- ── Tabs ─────────────────────────────────────────────── -->
<div class="un-tabs">

	<ul class="un-tab-nav">
		<li data-untab="about" class="un-tab-active"><i class="fas fa-info-circle"></i> About</li>
		<li data-untab="members"><i class="fas fa-users"></i> Members <span class="un-tab-count">(<?=$_total?>)</span></li>
	</ul>

	<!-- ── About tab ─────────────────────────────────────── -->
	<div class="un-tab-panel un-tab-active" id="un-tab-about">

<?php if ($_unHasSocial || $_can_edit): ?>
		<div class="un-connect-block">
			<div class="un-connect-subhead">
				<span><i class="fas fa-share-alt"></i> Connect</span>
				<?php if ($_can_edit): ?>
				<button class="un-connect-edit" type="button" onclick="unOpenDesignModal('about')" data-tip="Edit social links"><i class="fas fa-pencil-alt"></i></button>
				<?php endif; ?>
			</div>
			<?php if ($_unHasSocial): ?>
			<div class="un-connect-pills">
				<?php foreach ($_unSocialPlatforms as $_slug => $_meta):
					if (empty($_unSocialLinks[$_slug])) continue;
				?>
				<a class="un-connect-pill" href="<?= htmlspecialchars($_unSocialLinks[$_slug]) ?>" target="_blank" rel="noopener noreferrer" data-tip="<?= htmlspecialchars($_meta['label']) ?>">
					<i class="<?= htmlspecialchars($_meta['icon']) ?>"></i>
				</a>
				<?php endforeach; ?>
			</div>
			<?php elseif ($_can_edit): ?>
			<a href="#" class="un-connect-empty" onclick="event.preventDefault();unOpenDesignModal('about')">+ Add</a>
			<?php endif; ?>
		</div>
<?php endif; ?>

<?php if (trim($_about_text) !== '' || $_can_edit): ?>
		<div class="un-about-section">
			<div class="un-fullwidth-head">
				<h3 class="un-fullwidth-title"><i class="fas fa-align-left"></i> About</h3>
				<?php if ($_can_edit): ?>
				<button class="un-about-edit-btn" onclick="unOpenDesignModal('about')" data-tip="Edit About">
					<i class="fas fa-pencil-alt"></i> Edit
				</button>
				<?php endif; ?>
			</div>
			<?php if (trim($_about_text) !== ''): ?>
			<div class="un-about-text kn-description-body">
				<?= un_markdown($_about_text) ?>
			</div>
			<?php elseif ($_can_edit): ?>
			<div class="un-timeline-empty" style="text-align:left">
				No About content yet. <a href="#" onclick="event.preventDefault();unOpenDesignModal('about')">Add some</a>.
			</div>
			<?php endif; ?>
		</div>
<?php endif; ?>

<?php if (trim($_unHowToJoin) !== '' || $_can_edit): ?>
		<div class="un-fullwidth-section un-howto-section">
			<div class="un-fullwidth-head">
				<h3 class="un-fullwidth-title"><i class="fas fa-handshake"></i> How to Join</h3>
				<?php if ($_can_edit): ?>
				<button class="un-about-edit-btn" onclick="unOpenDesignModal('about')" data-tip="Edit How to Join">
					<i class="fas fa-pencil-alt"></i> Edit
				</button>
				<?php endif; ?>
			</div>
			<?php if (trim($_unHowToJoin) !== ''): ?>
			<div class="un-about-text kn-description-body"><?= un_markdown($_unHowToJoin) ?></div>
			<?php elseif ($_can_edit): ?>
			<div class="un-timeline-empty" style="text-align:left">
				No how-to-join info yet. <a href="#" onclick="event.preventDefault();unOpenDesignModal('about')">Add some</a> to help prospective members understand the process.
			</div>
			<?php endif; ?>
		</div>
<?php endif; ?>

<?php if (trim($_our_history) !== '' || $_can_edit): ?>
		<!-- Our History -->
		<div class="un-fullwidth-section">
			<div class="un-fullwidth-head">
				<h3 class="un-fullwidth-title"><i class="fas fa-scroll"></i> Our History</h3>
				<?php if ($_can_edit): ?>
				<button class="un-about-edit-btn" onclick="unOpenDesignModal('about')" data-tip="Edit Our History">
					<i class="fas fa-pencil-alt"></i> Edit
				</button>
				<?php endif; ?>
			</div>
			<?php if (trim($_our_history) !== ''): ?>
			<div class="un-about-text kn-description-body"><?= un_markdown($_our_history) ?></div>
			<?php elseif ($_can_edit): ?>
			<div class="un-timeline-empty">
				Share the founding story, past officers, or notable moments. <a href="#" onclick="event.preventDefault();unOpenDesignModal('about')">Add Our History</a>.
			</div>
			<?php endif; ?>
		</div>
<?php endif; ?>

<?php if ($_un_has_ms || $_can_edit): ?>
		<!-- Milestones -->
		<div class="un-fullwidth-section">
			<div class="un-fullwidth-head">
				<h3 class="un-fullwidth-title"><i class="fas fa-stream"></i> Milestones</h3>
				<?php if ($_can_edit): ?>
				<button class="un-about-edit-btn" onclick="unOpenDesignModal('milestones')" data-tip="Manage milestones">
					<i class="fas fa-pencil-alt"></i> Manage
				</button>
				<?php endif; ?>
			</div>
			<?php if ($_un_has_ms): ?>
			<div class="un-timeline">
				<?php foreach ($_un_visible_ms as $_msr): ?>
				<div class="un-timeline-row<?= !empty($_msr['IsDerived']) ? ' un-ms-derived' : '' ?>">
					<div class="un-timeline-dot">
						<i class="fas <?= htmlspecialchars(preg_replace('/[^a-z0-9-]/','', (string)($_msr['Icon'] ?? 'fa-star')) ?: 'fa-star') ?>"></i>
					</div>
					<div class="un-timeline-content">
						<span class="un-timeline-date"><?= !empty($_msr['MilestoneDate']) && $_msr['MilestoneDate'] !== '0000-00-00' ? date('M j, Y', strtotime($_msr['MilestoneDate'])) : '' ?></span>
						<span class="un-timeline-desc"><?= htmlspecialchars((string)($_msr['Description'] ?? '')) ?></span>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php elseif ($_can_edit): ?>
			<div class="un-timeline-empty">
				No milestones yet. <a href="#" onclick="event.preventDefault();unOpenDesignModal('milestones')">Add the first one</a> — founding date, leadership changes, notable events.
			</div>
			<?php endif; ?>
		</div>
<?php endif; ?>

<?php
$_auths = $Unit['Authorizations']['Authorizations'] ?? [];
if ($_can_edit || count($_auths) > 0):
?>
		<!-- Managers -->
		<div class="un-fullwidth-section">
			<div class="un-fullwidth-head">
				<h3 class="un-fullwidth-title"><i class="fas fa-user-shield"></i> Managers</h3>
				<?php if ($_can_edit): ?>
				<button class="un-about-edit-btn" onclick="unOpenModal('un-modal-add-manager')" data-tip="Add manager">
					<i class="fas fa-plus"></i> Add
				</button>
				<?php endif; ?>
			</div>
<?php if (count($_auths) > 0): ?>
			<ul class="un-managers-list">
<?php foreach ($_auths as $_auth):
	$__aid    = (int)$_auth['AuthorizationId'];
	$__mgr_js = addslashes($_auth['Persona'] ?: $_auth['UserName']);
?>
				<li>
					<div class="un-mgr-info">
						<span class="un-mgr-role">Manager</span>
						<span class="un-mgr-name">
							<a href="<?=UIR?>Player/profile/<?=(int)$_auth['MundaneId']?>">
								<?=htmlspecialchars($_auth['Persona'] ?: $_auth['UserName'])?>
							</a>
						</span>
					</div>
					<?php if ($_can_edit): ?>
					<form method="post" action="<?=htmlspecialchars($_base_url)?>" id="un-mgr-form-<?=$__aid?>" style="display:none">
						<input type="hidden" name="Action" value="deleteauth">
						<input type="hidden" name="AuthorizationId" value="<?=$__aid?>">
					</form>
					<button class="pn-btn pn-btn-ghost pn-btn-sm"
						onclick="pnConfirm({title:'Remove Manager',message:'Remove <?=$__mgr_js?> as a manager?',confirmText:'Remove',danger:true},function(){document.getElementById('un-mgr-form-<?=$__aid?>').submit()})"
						data-tip="Remove manager" style="color:#e53e3e;">
						<i class="fas fa-times"></i>
					</button>
					<?php endif; ?>
				</li>
<?php endforeach; ?>
			</ul>
<?php else: ?>
			<p class="un-managers-empty">No managers assigned.</p>
<?php endif; ?>
		</div>
<?php endif; ?>

	</div><!-- /un-tab-about -->

	<!-- ── Members tab ───────────────────────────────────── -->
	<div class="un-tab-panel" id="un-tab-members" style="display:none">

		<div class="un-section-header">
			<div class="un-section-title">
				<i class="fas fa-users"></i> Members <span class="un-tab-count">(<?=$_total?>)</span>
			</div>
<?php if ($_can_edit): ?>
			<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="unOpenModal('un-modal-add-member')">
				<i class="fas fa-plus"></i><span class="un-btn-label"> Add Member</span>
			</button>
<?php endif; ?>
		</div>

		<div class="un-roster-card">
<?php if ($_total === 0): ?>
			<div class="pn-empty">
				<i class="fas fa-users" style="font-size:24px;display:block;margin-bottom:8px;opacity:0.25;"></i>
				No members found.
			</div>
<?php else: ?>
			<table id="un-roster-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Persona</th>
						<th>Park</th>
						<th>Kingdom</th>
						<th>Role</th>
						<th>Title</th>
						<th>Last Sign-in</th>
<?php if ($_can_edit): ?>
						<th></th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_members as $_m):
	$_persona     = trimlen($_m['Persona']) > 0 ? $_m['Persona'] : '(No Persona)';
	$_um_id       = (int)($_m['UnitMundaneId'] ?? 0);
	$_role_esc    = htmlspecialchars($_m['UnitRole']  ?? '', ENT_QUOTES);
	$_title_esc   = htmlspecialchars($_m['UnitTitle'] ?? '', ENT_QUOTES);
	$_persona_js  = addslashes($_persona);
	$_last_signin = $_m['LastSignIn'] ?? '';
	$_is_active   = !empty($_last_signin) && $_last_signin >= $_cutoff;
?>
				<tr>
					<td>
						<a href="<?=UIR?>Player/profile/<?=(int)$_m['MundaneId']?>"
							style="color:var(--ork-link);text-decoration:none;font-weight:500;">
							<?=htmlspecialchars($_persona)?>
						</a>
						<?php if (!$_is_active && !empty($_last_signin)): ?>
						<span style="font-size:10px;color:var(--ork-text-lighter);margin-left:4px;">(inactive)</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if (!empty($_m['ParkId'])): ?>
						<a href="<?=UIR?>Park/profile/<?=(int)$_m['ParkId']?>"
							style="color:var(--ork-text-secondary);text-decoration:none;">
							<?=htmlspecialchars($_m['ParkName'] ?? '')?>
						</a>
						<?php else: ?>
						<?=htmlspecialchars($_m['ParkName'] ?? '')?>
						<?php endif; ?>
					</td>
					<td>
						<?php if (!empty($_m['KingdomId'])): ?>
						<a href="<?=UIR?>Kingdom/profile/<?=(int)$_m['KingdomId']?>"
							style="color:var(--ork-text-secondary);text-decoration:none;">
							<?=htmlspecialchars($_m['KingdomName'] ?? '')?>
						</a>
						<?php else: ?>
						<?=htmlspecialchars($_m['KingdomName'] ?? '')?>
						<?php endif; ?>
					</td>
					<td><?=htmlspecialchars(ucfirst($_m['UnitRole'] ?? ''))?></td>
					<td><?=htmlspecialchars($_m['UnitTitle'] ?? '')?></td>
					<td data-order="<?=htmlspecialchars($_last_signin)?>">
						<?=htmlspecialchars($_last_signin ?: '—')?>
					</td>
<?php if ($_can_edit): ?>
					<td style="white-space:nowrap;">
						<form method="post" action="<?=htmlspecialchars($_base_url)?>" id="un-retire-form-<?=$_um_id?>" style="display:none">
							<input type="hidden" name="Action" value="retire_member">
							<input type="hidden" name="UnitMundaneId" value="<?=$_um_id?>">
						</form>
						<form method="post" action="<?=htmlspecialchars($_base_url)?>" id="un-remove-form-<?=$_um_id?>" style="display:none">
							<input type="hidden" name="Action" value="remove_member">
							<input type="hidden" name="UnitMundaneId" value="<?=$_um_id?>">
						</form>
						<div class="un-action-btns">
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="unOpenEditMember(<?=$_um_id?>, '<?=$_role_esc?>', '<?=$_title_esc?>')"
								data-tip="Edit role / title">
								<i class="fas fa-pen"></i>
							</button>
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="pnConfirm({title:'Retire Member',message:'Retire <?=$_persona_js?> from the unit?',confirmText:'Retire',danger:true},function(){document.getElementById('un-retire-form-<?=$_um_id?>').submit()})"
								data-tip="Retire member" style="color:#c05621;">
								<i class="fas fa-user-minus"></i>
							</button>
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="pnConfirm({title:'Remove Member',message:'Permanently remove <?=$_persona_js?> from the unit?',confirmText:'Remove',danger:true},function(){document.getElementById('un-remove-form-<?=$_um_id?>').submit()})"
								data-tip="Remove member" style="color:#e53e3e;">
								<i class="fas fa-times"></i>
							</button>
						</div>
					</td>
<?php endif; ?>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div><!-- /un-roster-card -->

	</div><!-- /un-tab-members -->

</div><!-- /un-tabs -->



<?php if ($_can_edit): ?>

<!-- ── Heraldry Modal ─────────────────────────────────── -->
<div class="pn-overlay" id="un-img-overlay" onclick="if(event.target===this)unCloseHeraldryModal()">
	<div class="pn-modal-box" style="max-width:420px">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Heraldry</h3>
			<button class="pn-modal-close-btn" onclick="unCloseHeraldryModal()" aria-label="Close">&times;</button>
		</div>
		<!-- Step: select -->
		<div class="pn-modal-body" id="un-img-step-select">
			<label class="pn-upload-area" for="un-img-file-input" style="cursor:pointer">
				<i class="fas fa-cloud-upload-alt pn-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Accepts transparent images</small>
			</label>
			<input type="file" id="un-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none">
<?php if (!empty($_unit['HasHeraldry'])): ?>
			<div style="text-align:center;margin-top:14px">
				<button type="button" id="un-img-remove-btn" class="pn-btn pn-btn-ghost" style="color:#e53e3e;border-color:var(--ork-alert-danger-border);font-size:12px;padding:4px 14px">
					<i class="fas fa-trash"></i> Remove Heraldry
				</button>
				<div id="un-img-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:var(--ork-alert-danger-bg);border:1px solid var(--ork-alert-danger-border);border-radius:6px;font-size:13px;color:var(--ork-alert-danger-text);text-align:left">
					Remove this unit's heraldry image?
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('un-img-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm" style="background:#e53e3e;color:#fff" onclick="unDoRemoveHeraldry()">Yes, Remove</button>
					</div>
				</div>
			</div>
<?php endif; ?>
		</div>
		<!-- Step: uploading -->
		<div class="pn-modal-body" id="un-img-step-uploading" style="display:none;text-align:center;padding:40px 20px">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--ork-link-bright)"></i>
			<p style="margin-top:12px;color:var(--ork-text-muted)">Uploading&hellip;</p>
		</div>
		<!-- Step: done -->
		<div class="pn-modal-body" id="un-img-step-done" style="display:none;text-align:center;padding:40px 20px">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>

<!-- ── Edit Details Modal ─────────────────────────────── -->
<div class="pn-overlay" id="un-modal-details">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-pen"></i> Edit Unit Details</h3>
			<button class="pn-modal-close-btn" onclick="unCloseDetailsModal()">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>" enctype="multipart/form-data">
			<input type="hidden" name="Action" value="save_details">
			<div class="pn-acct-modal-body">
				<div class="pn-acct-field">
					<label>Name</label>
					<input type="text" name="Name" value="<?=htmlspecialchars($_name)?>" required>
				</div>
				<div class="pn-acct-field" style="display:flex;align-items:center;gap:12px;">
					<div style="flex:1;">
						<label>Type</label>
						<div style="font-size:14px;color:var(--ork-text);padding:8px 0 2px;">
							<i class="fas <?=$_type_icon?>"></i> <?=htmlspecialchars($_type)?>
						</div>
					</div>
					<div style="flex-shrink:0;padding-top:22px;">
						<button type="button" class="pn-btn pn-btn-secondary pn-btn-sm" id="un-convert-btn"
							onclick="unConvertType('<?=($_type === 'Company' ? 'Household' : 'Company')?>')">
							<i class="fas <?=($_type === 'Company' ? 'fa-home' : 'fa-shield-alt')?>"></i>
							Convert to <?=($_type === 'Company' ? 'Household' : 'Company')?>
						</button>
					</div>
				</div>
				<div class="pn-acct-field">
					<label>Website URL</label>
					<input type="url" name="Url" value="<?=htmlspecialchars($_url)?>" placeholder="https://…">
				</div>
				<div class="pn-acct-field">
					<label style="display:flex;align-items:center;gap:6px;">
						Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
						<button type="button" class="kn-md-help-btn" onclick="document.getElementById('un-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
					</label>
					<textarea name="Description" rows="4"><?=htmlspecialchars($_desc)?></textarea>
				</div>
				<div class="pn-acct-field">
					<label style="display:flex;align-items:center;gap:6px;">
						History <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
						<button type="button" class="kn-md-help-btn" onclick="document.getElementById('un-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
					</label>
					<textarea name="History" rows="4"><?=htmlspecialchars($_history)?></textarea>
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseDetailsModal()">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary" id="un-details-save-btn" disabled>
					<i class="fas fa-save"></i> Save
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Markdown Help Modal ─────────────────── -->
<div id="un-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-hashtag" style="margin-right:8px;color:var(--ork-link)"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('un-md-help-overlay').classList.remove('kn-open')">&times;</button>
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

<!-- ── Add Member Modal ───────────────────────────────── -->
<div class="pn-overlay" id="un-modal-add-member">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-plus"></i> Add Member</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-add-member')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>">
			<input type="hidden" name="Action" value="add_member">
			<div class="pn-modal-body">
				<div class="pn-acct-field">
					<label>Player</label>
					<div class="pn-award-search-bar un-player-search" id="un-am-wrap">
						<input type="text" class="pn-award-search-input" id="un-am-input"
							placeholder="Search players…"
							autocomplete="off">
						<div class="un-ac-results" id="un-am-results"></div>
					</div>
					<input type="hidden" name="MundaneId" id="un-am-mundane-id">
				</div>
				<div class="pn-acct-field">
					<label>Role</label>
					<select name="Role" id="un-add-role">
						<option value="member">Member</option>
						<option value="captain">Captain</option>
						<option value="lord">Lord</option>
						<option value="organizer">Organizer</option>
					</select>
				</div>
				<div class="pn-acct-field">
					<label>Title <span style="font-weight:400;color:var(--ork-text-lighter);">(optional)</span></label>
					<input type="text" name="Title" placeholder="Honorific or rank">
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-add-member')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
					<i class="fas fa-plus"></i> Add
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Edit Member Modal ──────────────────────────────── -->
<div class="pn-overlay" id="un-modal-edit-member">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-edit"></i> Edit Member</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-edit-member')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>">
			<input type="hidden" name="Action" value="set_member">
			<input type="hidden" name="UnitMundaneId" id="un-edit-umid">
			<div class="pn-modal-body">
				<div class="pn-acct-field">
					<label>Role</label>
					<select name="Role" id="un-edit-role">
						<option value="member">Member</option>
						<option value="captain">Captain</option>
						<option value="lord">Lord</option>
						<option value="organizer">Organizer</option>
					</select>
				</div>
				<div class="pn-acct-field">
					<label>Title</label>
					<input type="text" name="Title" id="un-edit-title" placeholder="Honorific or rank">
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-edit-member')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
					<i class="fas fa-save"></i> Save
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Add Manager Modal ──────────────────────────────── -->
<div class="pn-overlay" id="un-modal-add-manager">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-shield"></i> Add Manager</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-add-manager')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>">
			<input type="hidden" name="Action" value="addauth">
			<div class="pn-modal-body">
				<div class="pn-acct-field">
					<label>Player</label>
					<div class="pn-award-search-bar un-player-search" id="un-mg-wrap">
						<input type="text" class="pn-award-search-input" id="un-mg-input"
							placeholder="Search players…"
							autocomplete="off">
						<div class="un-ac-results" id="un-mg-results"></div>
					</div>
					<input type="hidden" name="MundaneId" id="un-mg-mundane-id">
					<div class="un-field-hint">Managers can edit unit details and manage members.</div>
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-add-manager')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
					<i class="fas fa-plus"></i> Add Manager
				</button>
			</div>
		</form>
	</div>
</div>

<?php endif; ?>

<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(function () {
	if ($('#un-roster-table').length) {
		$('#un-roster-table').DataTable({
			dom         : 'lfrtip',
			orderClasses: false,
			buttons     : [
				{ extend: 'csv',   filename: '<?=addslashes($_name)?>-roster', exportOptions: { columns: ':not(:last-child)' } },
				{ extend: 'print', exportOptions: { columns: ':not(:last-child)' } }
			],
			pageLength: 25,
			order     : [[3, 'asc'], [0, 'asc']],
			columnDefs: [
				{ targets: 5, type: 'date' },
<?php if ($_can_edit): ?>
				{ targets: -1, orderable: false, searchable: false, width: '90px' }
<?php endif; ?>
			]
		});
	}

	// ── Tab switching (About / Members) ──────────────────────
	function unActivateTab(name) {
		document.querySelectorAll('.un-tab-nav li').forEach(function(x){ x.classList.remove('un-tab-active'); });
		document.querySelectorAll('.un-tab-panel').forEach(function(p){ p.style.display = 'none'; });
		var tab = document.querySelector('.un-tab-nav li[data-untab="' + name + '"]');
		var panel = document.getElementById('un-tab-' + name);
		if (tab)   tab.classList.add('un-tab-active');
		if (panel) panel.style.display = '';
		// Recalc DataTables column widths if Members tab becomes visible (table init'd while hidden)
		if (name === 'members' && $.fn.DataTable && $.fn.DataTable.isDataTable('#un-roster-table')) {
			$('#un-roster-table').DataTable().columns.adjust();
		}
	}
	document.querySelectorAll('.un-tab-nav li').forEach(function(t) {
		t.addEventListener('click', function() { unActivateTab(t.dataset.untab); });
	});
	// Optional URL ?tab=members deep-link
	var _unUrlTab = new URLSearchParams(window.location.search).get('tab');
	if (_unUrlTab && document.querySelector('.un-tab-nav li[data-untab="' + _unUrlTab + '"]')) {
		unActivateTab(_unUrlTab);
	}
});

<?php if ($_can_edit): ?>
// ── Heraldry modal ────────────────────────────────────────
function unOpenHeraldryModal() {
	document.getElementById('un-img-step-select').style.display    = '';
	document.getElementById('un-img-step-uploading').style.display = 'none';
	document.getElementById('un-img-step-done').style.display      = 'none';
	document.getElementById('un-img-file-input').value             = '';
	var rc = document.getElementById('un-img-remove-confirm');
	if (rc) rc.style.display = 'none';
	document.getElementById('un-img-overlay').classList.add('pn-open');
	document.body.style.overflow = 'hidden';
}
function unCloseHeraldryModal() {
	document.getElementById('un-img-overlay').classList.remove('pn-open');
	document.body.style.overflow = '';
}
document.getElementById('un-img-file-input').addEventListener('change', function() {
	if (!this.files[0]) return;
	var fd = new FormData();
	fd.append('Action', 'upload_heraldry');
	fd.append('Heraldry', this.files[0]);
	document.getElementById('un-img-step-select').style.display    = 'none';
	document.getElementById('un-img-step-uploading').style.display = '';
	fetch('<?=htmlspecialchars($_base_url)?>', { method: 'POST', body: fd })
		.then(function(r) {
			document.getElementById('un-img-step-uploading').style.display = 'none';
			if (r.ok) {
				document.getElementById('un-img-step-done').style.display = '';
				setTimeout(function() { window.location.reload(); }, 1200);
			} else {
				document.getElementById('un-img-step-select').style.display = '';
				alert('Upload failed. Please try again.');
			}
		});
});
var _unRemoveBtn = document.getElementById('un-img-remove-btn');
if (_unRemoveBtn) {
	_unRemoveBtn.addEventListener('click', function() {
		var rc = document.getElementById('un-img-remove-confirm');
		rc.style.display = rc.style.display === 'none' ? '' : 'none';
	});
}
function unDoRemoveHeraldry() {
	var fd = new FormData();
	fd.append('Action', 'remove_heraldry');
	document.getElementById('un-img-step-select').style.display    = 'none';
	document.getElementById('un-img-step-uploading').style.display = '';
	fetch('<?=htmlspecialchars($_base_url)?>', { method: 'POST', body: fd })
		.then(function(r) { if (r.ok) window.location.reload(); });
}

function unOpenModal(id) {
	document.getElementById(id).classList.add('pn-open');
}
function unCloseModal(id) {
	document.getElementById(id).classList.remove('pn-open');
}

// ── Details modal dirty tracking ──
var _unDetailsForm = document.getElementById('un-modal-details') && document.querySelector('#un-modal-details form');
var _unDetailsOriginals = {};
var _unDetailsSaveBtn = document.getElementById('un-details-save-btn');

(function() {
	var form = document.querySelector('#un-modal-details form');
	if (!form) return;
	_unDetailsForm = form;
	form.querySelectorAll('input, textarea').forEach(function(el) {
		if (el.name) _unDetailsOriginals[el.name] = el.value;
	});
	form.querySelectorAll('input, textarea').forEach(function(el) {
		el.addEventListener('input', unCheckDetailsDirty);
		el.addEventListener('change', unCheckDetailsDirty);
	});
})();

function unCheckDetailsDirty() {
	if (!_unDetailsForm) return;
	var dirty = false;
	_unDetailsForm.querySelectorAll('input, textarea').forEach(function(el) {
		if (el.name && _unDetailsOriginals.hasOwnProperty(el.name) && el.value !== _unDetailsOriginals[el.name]) dirty = true;
	});
	if (_unDetailsSaveBtn) _unDetailsSaveBtn.disabled = !dirty;
}

function unRestoreDetailsForm() {
	if (!_unDetailsForm) return;
	_unDetailsForm.querySelectorAll('input, textarea').forEach(function(el) {
		if (el.name && _unDetailsOriginals.hasOwnProperty(el.name)) el.value = _unDetailsOriginals[el.name];
	});
	if (_unDetailsSaveBtn) _unDetailsSaveBtn.disabled = true;
}

function unCloseDetailsModal() {
	if (_unDetailsSaveBtn && !_unDetailsSaveBtn.disabled) {
		pnConfirm({ title: 'Unsaved Changes', message: 'You have unsaved changes. Discard them?', confirmText: 'Discard', danger: true }, function() {
			unRestoreDetailsForm();
			unCloseModal('un-modal-details');
		});
		return;
	}
	unCloseModal('un-modal-details');
}
document.addEventListener('keydown', function(e) {
	if (e.key === 'Escape') {
		if (document.getElementById('un-md-help-overlay').classList.contains('kn-open')) {
			document.getElementById('un-md-help-overlay').classList.remove('kn-open');
		} else if (document.getElementById('un-modal-details').classList.contains('pn-open')) {
			unCloseDetailsModal();
		} else {
			['un-modal-add-member', 'un-modal-edit-member', 'un-modal-add-manager'].forEach(function(id) {
				unCloseModal(id);
			});
		}
	}
}, true);

function unConvertType(targetType) {
	var btn = document.getElementById('un-convert-btn');
	btn.disabled = true;
	btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting\u2026';
	var fd = new FormData();
	fd.append('Action', 'convert_type');
	fd.append('TargetType', targetType);
	fetch('<?=htmlspecialchars($_base_url)?>', { method: 'POST', body: fd })
		.then(function(r) {
			if (r.ok) {
				window.location.reload();
			} else {
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed';
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed';
		});
}
function unOpenEditMember(unitMundaneId, role, title) {
	document.getElementById('un-edit-umid').value  = unitMundaneId;
	/* select the matching option; fall back to 'member' if unrecognised */
	var sel = document.getElementById('un-edit-role');
	var found = false;
	for (var i = 0; i < sel.options.length; i++) {
		if (sel.options[i].value === role) { sel.selectedIndex = i; found = true; break; }
	}
	if (!found) sel.value = 'member';
	document.getElementById('un-edit-title').value = title;
	unOpenModal('un-modal-edit-member');
}

/* Close modals on backdrop click */
document.querySelectorAll('.pn-overlay').forEach(function (overlay) {
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) overlay.classList.remove('pn-open');
	});
});

/* ── Player search factory ──────────────────────────────── */
var UN_SEARCH_URL = '<?=HTTP_SERVICE?>Search/SearchService.php';
var UN_SCOPE_KID  = <?=(int)($ScopeKingdomId ?? 0)?>;
var UN_SCOPE_PID  = <?=(int)($ScopeParkId ?? 0)?>;

function initPlayerSearch(cfg) {
	/* cfg: { inputId, resultsId, hiddenId, parkId, kingdomId } */
	var $input   = document.getElementById(cfg.inputId);
	var $results = document.getElementById(cfg.resultsId);
	var $hidden  = document.getElementById(cfg.hiddenId);
	var debounce, focusIdx = -1;
	var seen = {};

	function closeResults() {
		$results.classList.remove('un-ac-open');
		$results.innerHTML = '';
		focusIdx = -1;
	}

	function selectPlayer(id, label) {
		$hidden.value  = id;
		$input.value   = label;
		closeResults();
	}

	function buildItem(player, groupClass) {
		var id    = player.MundaneId;
		var label = player.Persona;
		var scope = (player.KAbbr && player.PAbbr) ? player.KAbbr + ':' + player.PAbbr : (player.KAbbr || '');
		var el    = document.createElement('div');
		el.className   = 'un-ac-item' + (groupClass ? ' ' + groupClass : '');
		el.dataset.id  = id;
		el.dataset.lbl = label;
		el.innerHTML   = '<span>' + label + '</span>'
			+ (scope ? '<span class="un-ac-scope">' + scope + '</span>' : '');
		el.addEventListener('mousedown', function (e) {
			e.preventDefault();
			selectPlayer(id, label + (scope ? ' (' + scope + ')' : ''));
		});
		return el;
	}

	function addGroup(label, players) {
		if (!players.length) return;
		var hdr = document.createElement('div');
		hdr.className   = 'un-ac-group-label';
		hdr.textContent = label;
		$results.appendChild(hdr);
		players.forEach(function (p) {
			if (seen[p.MundaneId]) return;
			seen[p.MundaneId] = true;
			$results.appendChild(buildItem(p, ''));
		});
	}

	function runSearch(term) {
		seen = {};
		$results.innerHTML = '';
		$hidden.value = '';
		var base = { Action: 'Search/Player', type: 'all', search: term, limit: 8 };
		var calls = [];
		/* Three-tier: park → kingdom → global */
		if (cfg.parkId)    calls.push($.getJSON(UN_SEARCH_URL, $.extend({}, base, { park_id:    cfg.parkId })));
		if (cfg.kingdomId) calls.push($.getJSON(UN_SEARCH_URL, $.extend({}, base, { kingdom_id: cfg.kingdomId })));
		calls.push($.getJSON(UN_SEARCH_URL, base));

		$.when.apply($, calls).done(function () {
			var args = calls.length === 1 ? [arguments] : Array.prototype.slice.call(arguments);
			var parkRes    = (cfg.parkId    && args[0]) ? (args[0][0] || []) : [];
			var kingRes    = (cfg.kingdomId && args[cfg.parkId ? 1 : 0]) ? (args[cfg.parkId ? 1 : 0][0] || []) : [];
			var allRes     = (args[args.length - 1]  ? args[args.length - 1][0] : null) || [];

			var hasPark    = cfg.parkId    && parkRes.length;
			var hasKing    = cfg.kingdomId && kingRes.length;

			if (!hasPark && !hasKing && !allRes.length) {
				var empty = document.createElement('div');
				empty.className   = 'un-ac-empty';
				empty.textContent = 'No players found.';
				$results.appendChild(empty);
			} else {
				if (hasPark)  addGroup('In Park',    parkRes);
				if (hasKing)  addGroup('In Kingdom', kingRes);
				/* global results not already shown */
				var rest = allRes.filter(function (p) { return !seen[p.MundaneId]; });
				if (rest.length) addGroup('All Players', rest);
			}
			focusIdx = -1;
			$results.classList.add('un-ac-open');
		});
	}

	$input.addEventListener('input', function () {
		var term = this.value.trim();
		$hidden.value = '';
		clearTimeout(debounce);
		if (term.length < 2) { closeResults(); return; }
		debounce = setTimeout(function () { runSearch(term); }, 300);
	});

	$input.addEventListener('keydown', function (e) {
		var items = $results.querySelectorAll('.un-ac-item');
		if (!items.length) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			if (focusIdx >= 0) items[focusIdx].classList.remove('un-ac-focused');
			focusIdx = Math.min(focusIdx + 1, items.length - 1);
			items[focusIdx].classList.add('un-ac-focused');
			items[focusIdx].scrollIntoView({ block: 'nearest' });
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			if (focusIdx >= 0) items[focusIdx].classList.remove('un-ac-focused');
			focusIdx = Math.max(focusIdx - 1, 0);
			items[focusIdx].classList.add('un-ac-focused');
			items[focusIdx].scrollIntoView({ block: 'nearest' });
		} else if (e.key === 'Enter') {
			e.preventDefault();
			if (focusIdx >= 0 && items[focusIdx]) items[focusIdx].dispatchEvent(new MouseEvent('mousedown'));
		} else if (e.key === 'Escape') {
			closeResults();
		}
	});

	$input.addEventListener('blur', function () {
		setTimeout(closeResults, 150);
	});
}

/* Initialise both search widgets */
initPlayerSearch({ inputId: 'un-am-input', resultsId: 'un-am-results', hiddenId: 'un-am-mundane-id', parkId: UN_SCOPE_PID, kingdomId: UN_SCOPE_KID });
initPlayerSearch({ inputId: 'un-mg-input', resultsId: 'un-mg-results', hiddenId: 'un-mg-mundane-id', parkId: UN_SCOPE_PID, kingdomId: UN_SCOPE_KID });

/* Clear search fields when modals close */
document.getElementById('un-modal-add-member').addEventListener('transitionend', function () {
	if (!this.classList.contains('pn-open')) {
		document.getElementById('un-am-input').value = '';
		document.getElementById('un-am-mundane-id').value = '';
	}
});
document.getElementById('un-modal-add-manager').addEventListener('transitionend', function () {
	if (!this.classList.contains('pn-open')) {
		document.getElementById('un-mg-input').value = '';
		document.getElementById('un-mg-mundane-id').value = '';
	}
});
<?php endif; ?>
</script>
<style>
/* DataTables pagination dark mode — end of page to guarantee last cascade position */
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button,
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button:hover {
  background-color: #2d3748 !important; background-image: none !important;
  border-color: #4a5568 !important; color: #cbd5e0 !important;
}
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button.current,
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button.current:hover {
  background-color: #2b6cb0 !important; background-image: none !important;
  color: #fff !important; border-color: #2b6cb0 !important;
}
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button.disabled {
  opacity: 0.4 !important;
}
</style>

<?php if ($_can_edit): ?>
<!-- ── Design Modal ─────────────────────────────────────── -->
<style>
.un-dm-overlay {
	display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1000;
	align-items: center; justify-content: center; padding: 20px;
}
.un-dm-overlay.un-open { display: flex; }
.un-dm-modal {
	background: #fff; border-radius: 10px; width: 100%; max-width: 720px; max-height: 90vh;
	display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.35); color: #1a202c;
}
html[data-theme="dark"] .un-dm-modal { background: var(--ork-card-bg); color: var(--ork-text); }
.un-dm-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #f7fafc; border-radius: 10px 10px 0 0;
}
html[data-theme="dark"] .un-dm-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
.un-dm-title {
	margin: 0; font-size: 17px; font-weight: 700; color: #2d3748;
	display: flex; align-items: center; gap: 8px;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
html[data-theme="dark"] .un-dm-title { color: var(--ork-text); }
.un-dm-close {
	background: transparent; border: 0; cursor: pointer; font-size: 24px; color: #718096; line-height: 1;
	padding: 4px 10px; border-radius: 6px;
}
.un-dm-close:hover { background: #f7fafc; color: #2d3748; }
html[data-theme="dark"] .un-dm-close { color: var(--ork-text-muted); }
html[data-theme="dark"] .un-dm-close:hover { background: var(--ork-bg-tertiary); color: var(--ork-text); }
.un-dm-tabs {
	display: flex; gap: 4px; padding: 6px 10px 0 10px; background: #f7fafc; border-bottom: 1px solid #e2e8f0;
}
html[data-theme="dark"] .un-dm-tabs { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.un-dm-tab {
	background: transparent; border: 0; padding: 9px 14px; cursor: pointer;
	font-size: 13px; font-weight: 600; color: #718096; border-bottom: 2px solid transparent;
	display: inline-flex; align-items: center; gap: 6px;
}
.un-dm-tab.un-active {
	color: #2b6cb0; border-bottom-color: #2b6cb0;
}
html[data-theme="dark"] .un-dm-tab { color: var(--ork-text-secondary); }
html[data-theme="dark"] .un-dm-tab.un-active {
	color: var(--ork-link); border-bottom-color: var(--ork-link);
}
.un-dm-body {
	padding: 18px 22px; overflow-y: auto; flex: 1;
}
.un-dm-panel { display: none; }
.un-dm-panel.un-active { display: block; }
.un-dm-error {
	display: none; background: #fff5f5; color: #c53030; border: 1px solid #feb2b2;
	border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 13px;
}
html[data-theme="dark"] .un-dm-error { background: rgba(252,129,129,0.1); color: #fc8181; }
.un-dm-field { margin-bottom: 14px; }
.un-dm-field label {
	display: block; font-size: 11px; font-weight: 700; color: #4a5568;
	text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;
}
html[data-theme="dark"] .un-dm-field label { color: var(--ork-text-secondary); }
.un-dm-field input[type="text"],
.un-dm-field input[type="date"],
.un-dm-field textarea,
.un-dm-field select {
	width: 100%; padding: 8px 10px; font-size: 14px;
	border: 1px solid #cbd5e0; border-radius: 6px;
	background: #fff; color: #2d3748; font-family: inherit;
}
html[data-theme="dark"] .un-dm-field input,
html[data-theme="dark"] .un-dm-field textarea,
html[data-theme="dark"] .un-dm-field select {
	background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text);
}
.un-dm-field textarea { min-height: 140px; resize: vertical; line-height: 1.5; }
.un-dm-hint {
	font-size: 12px; color: #718096;
}
html[data-theme="dark"] .un-dm-hint { color: var(--ork-text-muted); }
.un-dm-footer {
	display: flex; gap: 8px; justify-content: flex-end; padding: 12px 18px;
	border-top: 1px solid #e2e8f0; background: #f7fafc; border-radius: 0 0 10px 10px;
}
html[data-theme="dark"] .un-dm-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
.un-dm-btn {
	background: #fff; border: 1px solid #cbd5e0; color: #4a5568;
	padding: 8px 14px; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer;
	display: inline-flex; align-items: center; gap: 5px;
}
html[data-theme="dark"] .un-dm-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text); }
.un-dm-btn-primary { background: #3182ce; color: #fff; border-color: #3182ce; }
.un-dm-btn-primary:hover { background: #2c5282; border-color: #2c5282; }
.un-dm-btn:disabled { opacity: 0.55; cursor: not-allowed; }

.un-dm-preset-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; }
.un-dm-swatch {
	height: 36px; border-radius: 6px; cursor: pointer;
	border: 2px solid transparent; transition: transform 0.12s, box-shadow 0.12s;
}
.un-dm-swatch.un-selected { border-color: #2b6cb0; transform: scale(1.06); box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2b6cb0; }
html[data-theme="dark"] .un-dm-swatch.un-selected { box-shadow: 0 0 0 2px var(--ork-card-bg), 0 0 0 4px var(--ork-link); }
.un-dm-color-row { display: flex; gap: 12px; flex-wrap: wrap; }
.un-dm-color-col { flex: 1; min-width: 180px; }
.un-dm-color-input { display: flex; align-items: center; gap: 6px; }
.un-dm-color-input input[type="color"] {
	width: 38px; height: 36px; border: 1px solid #cbd5e0; border-radius: 6px;
	padding: 0; background: transparent; cursor: pointer;
}
.un-dm-color-input input[type="text"] { flex: 1; }
.un-dm-overlay-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.un-dm-overlay-btn {
	flex: 1; min-width: 80px; padding: 8px 10px; font-size: 12px; font-weight: 600;
	border: 1px solid #cbd5e0; border-radius: 6px; background: #fff; color: #4a5568; cursor: pointer;
}
.un-dm-overlay-btn.un-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .un-dm-overlay-btn { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .un-dm-overlay-btn.un-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }

.un-dm-font-picker { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.un-dm-font-card {
	border: 1px solid #cbd5e0; border-radius: 8px; padding: 10px 8px; cursor: pointer;
	text-align: center; background: #fff;
}
.un-dm-font-card.un-active { border-color: #2b6cb0; background: #ebf8ff; box-shadow: 0 0 0 1px #2b6cb0; }
html[data-theme="dark"] .un-dm-font-card { background: var(--ork-bg-tertiary); border-color: var(--ork-border); }
html[data-theme="dark"] .un-dm-font-card.un-active { background: rgba(43,108,176,0.15); border-color: var(--ork-link); box-shadow: 0 0 0 1px var(--ork-link); }
.un-dm-font-sample { font-size: 19px; font-weight: 600; color: #2d3748; line-height: 1.2; margin-bottom: 4px; }
html[data-theme="dark"] .un-dm-font-sample { color: var(--ork-text); }
.un-dm-font-label { font-size: 11px; color: #718096; }
html[data-theme="dark"] .un-dm-font-label { color: var(--ork-text-muted); }

.un-dm-md-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.un-dm-md-toggle { display: inline-flex; background: #edf2f7; border-radius: 6px; padding: 3px; gap: 3px; }
.un-dm-md-toggle button {
	background: transparent; border: 0; padding: 5px 10px; font-size: 12px; font-weight: 600;
	cursor: pointer; border-radius: 4px; color: #718096;
}
.un-dm-md-toggle button.un-active { background: #fff; color: #2b6cb0; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
html[data-theme="dark"] .un-dm-md-toggle { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .un-dm-md-toggle button.un-active { background: var(--ork-card-bg); color: var(--ork-link); }
.un-dm-md-preview {
	border: 1px solid #cbd5e0; border-radius: 6px; padding: 12px 14px;
	min-height: 140px; background: #fafafa; font-size: 14px; line-height: 1.55; color: #2d3748;
}
html[data-theme="dark"] .un-dm-md-preview { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text); }
.un-dm-md-preview h1, .un-dm-md-preview h2, .un-dm-md-preview h3, .un-dm-md-preview h4 {
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
	margin-top: 0.9em; margin-bottom: 0.3em;
}

.un-dm-ms-toggles { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 14px; }
.un-dm-ms-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #4a5568; }
html[data-theme="dark"] .un-dm-ms-toggle { color: var(--ork-text-secondary); }
.un-dm-ms-list { border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 12px; max-height: 200px; overflow-y: auto; }
html[data-theme="dark"] .un-dm-ms-list { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
.un-dm-ms-row {
	display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid #edf2f7;
	font-size: 13px;
}
html[data-theme="dark"] .un-dm-ms-row { border-color: var(--ork-border); }
.un-dm-ms-row:last-child { border-bottom: none; }
.un-dm-ms-row > i { color: #2b6cb0; width: 18px; text-align: center; }
.un-dm-ms-row .un-dm-ms-desc { flex: 1; color: #2d3748; }
html[data-theme="dark"] .un-dm-ms-row .un-dm-ms-desc { color: var(--ork-text); }
.un-dm-ms-row .un-dm-ms-date { color: #718096; font-size: 11px; min-width: 90px; }
html[data-theme="dark"] .un-dm-ms-row .un-dm-ms-date { color: var(--ork-text-muted); }
html[data-theme="dark"] .un-dm-ms-row > i { color: var(--ork-link); }
html[data-theme="dark"] .un-dm-ms-row button { color: #fc8181; }
html[data-theme="dark"] .un-dm-ms-row button:hover { background: var(--ork-bg-tertiary); }
.un-dm-ms-row button {
	background: transparent; border: 0; color: #e53e3e; cursor: pointer; padding: 4px 6px;
}
.un-dm-ms-row button[data-tip] { position: relative; }
.un-dm-ms-row button[data-tip]::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 4px); right: 0;
	background: #2d3748; color: #fff; font-size: 11px; white-space: nowrap;
	padding: 3px 8px; border-radius: 4px; pointer-events: none; opacity: 0;
	transition: opacity 0.12s; z-index: 600;
}
.un-dm-ms-row button[data-tip]:hover::after { opacity: 1; transition-delay: 0.3s; }
html[data-theme="dark"] .un-dm-ms-row button[data-tip]::after {
	background: var(--ork-bg-tertiary); color: var(--ork-text); border: 1px solid var(--ork-border);
}
.un-dm-ms-add { display: grid; grid-template-columns: 1fr 140px 90px; gap: 8px; align-items: end; }
@media (max-width: 600px) { .un-dm-ms-add { grid-template-columns: 1fr; } }
.un-dm-ms-icons { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.un-dm-ms-icon-opt {
	width: 28px; height: 28px; border: 1px solid #cbd5e0; border-radius: 6px;
	display: flex; align-items: center; justify-content: center; cursor: pointer;
	background: #fff; color: #4a5568;
}
.un-dm-ms-icon-opt.un-active { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
html[data-theme="dark"] .un-dm-ms-icon-opt { background: var(--ork-bg-tertiary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .un-dm-ms-icon-opt.un-active { background: var(--ork-link); color: var(--ork-bg-secondary); border-color: var(--ork-link); }
</style>

<div class="un-dm-overlay" id="un-dm-overlay">
	<div class="un-dm-modal">
		<div class="un-dm-header">
			<h3 class="un-dm-title"><i class="fas fa-palette"></i>Design <?= htmlspecialchars($_name) ?></h3>
			<button class="un-dm-close" id="un-dm-close" aria-label="Close">&times;</button>
		</div>
		<div class="un-dm-tabs">
			<button class="un-dm-tab un-active" data-untab-dm="header"><i class="fas fa-image"></i> Header</button>
			<button class="un-dm-tab" data-untab-dm="about"><i class="fas fa-scroll"></i> About</button>
			<button class="un-dm-tab" data-untab-dm="milestones"><i class="fas fa-stream"></i> Milestones</button>
		</div>
		<div class="un-dm-body">
			<div class="un-dm-error" id="un-dm-error"></div>

			<!-- Header Panel -->
			<div class="un-dm-panel un-active" id="un-dm-panel-header">
				<div class="un-dm-hint" style="margin-bottom:12px"><i class="fas fa-moon" style="margin-right:6px"></i><strong>Dark mode viewers</strong> see your hero with a slight darkening filter so colors stay readable.</div>

				<div class="un-dm-field">
					<label>Color Presets</label>
					<div class="un-dm-preset-grid" id="un-dm-presets">
						<div class="un-dm-swatch" data-primary="#2c5282" data-accent="#4299e1" style="background:#2c5282"></div>
						<div class="un-dm-swatch" data-primary="#276749" data-accent="#48bb78" style="background:#276749"></div>
						<div class="un-dm-swatch" data-primary="#9b2c2c" data-accent="#fc8181" style="background:#9b2c2c"></div>
						<div class="un-dm-swatch" data-primary="#553c9a" data-accent="#9f7aea" style="background:#553c9a"></div>
						<div class="un-dm-swatch" data-primary="#975a16" data-accent="#ecc94b" style="background:#975a16"></div>
						<div class="un-dm-swatch" data-primary="#2d3748" data-accent="#a0aec0" style="background:#2d3748"></div>
						<div class="un-dm-swatch" data-primary="#285e61" data-accent="#38b2ac" style="background:#285e61"></div>
						<div class="un-dm-swatch" data-primary="#744210" data-accent="#ed8936" style="background:#744210"></div>
					</div>
				</div>

				<div class="un-dm-field">
					<label>Gradient Presets</label>
					<div class="un-dm-preset-grid" id="un-dm-gradient-presets">
						<div class="un-dm-swatch" data-primary="#1a365d" data-accent="#4299e1" data-secondary="#553c9a" style="background:linear-gradient(135deg,#1a365d,#553c9a)"></div>
						<div class="un-dm-swatch" data-primary="#1a4731" data-accent="#48bb78" data-secondary="#2c5282" style="background:linear-gradient(135deg,#1a4731,#2c5282)"></div>
						<div class="un-dm-swatch" data-primary="#742a2a" data-accent="#fc8181" data-secondary="#975a16" style="background:linear-gradient(135deg,#742a2a,#975a16)"></div>
						<div class="un-dm-swatch" data-primary="#44337a" data-accent="#d6bcfa" data-secondary="#97266d" style="background:linear-gradient(135deg,#44337a,#97266d)"></div>
						<div class="un-dm-swatch" data-primary="#234e52" data-accent="#38b2ac" data-secondary="#276749" style="background:linear-gradient(135deg,#234e52,#276749)"></div>
						<div class="un-dm-swatch" data-primary="#2c5282" data-accent="#4299e1" data-secondary="#285e61" style="background:linear-gradient(135deg,#2c5282,#285e61)"></div>
						<div class="un-dm-swatch" data-primary="#744210" data-accent="#ecc94b" data-secondary="#9b2c2c" style="background:linear-gradient(135deg,#744210,#9b2c2c)"></div>
						<div class="un-dm-swatch" data-primary="#1a202c" data-accent="#a0aec0" data-secondary="#2d3748" style="background:linear-gradient(135deg,#1a202c,#2d3748)"></div>
					</div>
				</div>

				<div class="un-dm-field">
					<label>Custom Colors</label>
					<div class="un-dm-color-row">
						<div class="un-dm-color-col">
							<div class="un-dm-hint" style="margin-bottom:4px">Primary (hero background)</div>
							<div class="un-dm-color-input">
								<input type="color" id="un-dm-color-primary" value="<?= htmlspecialchars($_un_color_primary ?: '#2c5282') ?>" />
								<input type="text" id="un-dm-color-primary-hex" value="<?= htmlspecialchars($_un_color_primary ?: '#2c5282') ?>" maxlength="7" />
							</div>
						</div>
						<div class="un-dm-color-col">
							<div class="un-dm-hint" style="margin-bottom:4px">Accent (badges &amp; pencils)</div>
							<div class="un-dm-color-input">
								<input type="color" id="un-dm-color-accent" value="<?= htmlspecialchars($_un_color_accent ?: '#4299e1') ?>" />
								<input type="text" id="un-dm-color-accent-hex" value="<?= htmlspecialchars($_un_color_accent ?: '#4299e1') ?>" maxlength="7" />
							</div>
						</div>
					</div>
				</div>

				<div class="un-dm-field">
					<label>Gradient (Optional)</label>
					<div class="un-dm-color-row">
						<div class="un-dm-color-col">
							<div class="un-dm-hint" style="margin-bottom:4px">Secondary color</div>
							<div class="un-dm-color-input">
								<input type="color" id="un-dm-color-secondary" value="<?= htmlspecialchars($_un_color_secondary ?: ($_un_color_primary ?: '#2c5282')) ?>" />
								<input type="text" id="un-dm-color-secondary-hex" value="<?= htmlspecialchars($_un_color_secondary) ?>" maxlength="7" placeholder="None" />
							</div>
						</div>
						<div class="un-dm-color-col" style="display:flex;align-items:center;padding-top:18px">
							<label style="text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;color:#4a5568;font-size:13px;margin-bottom:0">
								<input type="checkbox" id="un-dm-gradient-enabled" <?= $_un_color_secondary !== '' ? 'checked' : '' ?> />
								Enable gradient
							</label>
						</div>
					</div>
				</div>

				<div class="un-dm-field">
					<label>Heraldry Overlay Strength</label>
					<div class="un-dm-hint" style="margin-bottom:6px">Controls how much the unit heraldry shows through the hero background.</div>
					<div class="un-dm-overlay-btns">
						<button type="button" class="un-dm-overlay-btn<?= $_un_overlay === 'low' ? ' un-active' : '' ?>" data-overlay="low">Low</button>
						<button type="button" class="un-dm-overlay-btn<?= $_un_overlay === 'med' ? ' un-active' : '' ?>" data-overlay="med">Medium</button>
						<button type="button" class="un-dm-overlay-btn<?= $_un_overlay === 'high' ? ' un-active' : '' ?>" data-overlay="high">High</button>
						<button type="button" class="un-dm-overlay-btn<?= $_un_overlay === 'vignette' ? ' un-active' : '' ?>" data-overlay="vignette">Vignette</button>
					</div>
					<input type="hidden" id="un-dm-hero-overlay" value="<?= htmlspecialchars($_un_overlay) ?>" />
				</div>

				<div class="un-dm-field">
					<label>Name Font</label>
					<div class="un-dm-hint" style="margin-bottom:6px">A decorative font for the unit name in the hero.</div>
					<div class="un-dm-font-picker" id="un-dm-font-picker"></div>
				</div>

				<div class="un-dm-section-title">Tagline</div>
				<div class="un-dm-field">
					<label>Tagline</label>
					<div class="un-dm-hint" style="margin-bottom:6px">A short phrase shown under the unit name. Max 160 characters.</div>
					<input type="text" id="un-dm-tagline" maxlength="160" value="<?= htmlspecialchars($_unTagline) ?>" placeholder="e.g. Forging warriors since 2003." />
					<div class="un-dm-hint" style="margin-top:4px"><span id="un-dm-tagline-count"><?= strlen($_unTagline) ?></span>/160</div>
				</div>

				<div class="un-dm-section-title">Announcement Banner</div>
				<div class="un-dm-field">
					<label>Announcement</label>
					<div class="un-dm-hint" style="margin-bottom:6px">Shown as an amber banner above the hero. Plain text, max 280 characters.</div>
					<textarea id="un-dm-announcement" maxlength="280" placeholder="e.g. New member orientation this Saturday at 10 AM." style="min-height:70px"><?= htmlspecialchars($_unAnnouncement) ?></textarea>
					<div class="un-dm-hint" style="margin-top:4px"><span id="un-dm-announcement-count"><?= strlen($_unAnnouncement) ?></span>/280</div>
				</div>
				<div class="un-dm-field">
					<label>Show until (optional)</label>
					<div class="un-dm-hint" style="margin-bottom:6px">Banner auto-hides after this date. Leave blank to show indefinitely.</div>
					<input type="date" id="un-dm-announcement-until" value="<?= htmlspecialchars($_unAnnouncementUntil && $_unAnnouncementUntil !== '0000-00-00' ? $_unAnnouncementUntil : '') ?>" />
				</div>

				<div class="un-dm-section-title">Recruitment Status</div>
				<div class="un-dm-field">
					<label>Recruitment Status</label>
					<div class="un-dm-hint" style="margin-bottom:6px">Show recruitment status as a pill in the hero. &ldquo;Not Set&rdquo; hides the pill.</div>
					<div class="un-dm-recruit-row" id="un-dm-recruit-row">
						<button type="button" class="un-dm-recruit-opt<?= $_unRecruitmentStatus === 'open' ? ' un-active' : '' ?>" data-recruit="open"><i class="fas fa-door-open"></i> Recruiting</button>
						<button type="button" class="un-dm-recruit-opt<?= $_unRecruitmentStatus === 'invite' ? ' un-active' : '' ?>" data-recruit="invite"><i class="fas fa-envelope"></i> Invite Only</button>
						<button type="button" class="un-dm-recruit-opt<?= $_unRecruitmentStatus === 'closed' ? ' un-active' : '' ?>" data-recruit="closed"><i class="fas fa-lock"></i> Closed</button>
						<button type="button" class="un-dm-recruit-opt<?= $_unRecruitmentStatus === '' ? ' un-active' : '' ?>" data-recruit="">Not Set</button>
					</div>
					<input type="hidden" id="un-dm-recruitment-status" value="<?= htmlspecialchars($_unRecruitmentStatus) ?>" />
				</div>
			</div>

			<!-- About Panel -->
			<div class="un-dm-panel" id="un-dm-panel-about">
				<div class="un-dm-hint" style="margin-bottom:14px"><i class="fas fa-info-circle" style="margin-right:6px"></i>Both fields support <strong>Markdown</strong>. Use <em>About</em> for a current snapshot of the unit; use <em>Our History</em> for the founding story and notable moments.</div>

				<div class="un-dm-field">
					<div class="un-dm-md-toolbar">
						<label style="margin-bottom:0">About <?= htmlspecialchars($_name) ?></label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="un-dm-md-toggle">
								<button type="button" class="un-active" data-unmd-target="edit" data-unmd-field="about">Write</button>
								<button type="button" data-unmd-target="preview" data-unmd-field="about">Preview</button>
							</div>
						</div>
					</div>
					<textarea id="un-dm-about-text" maxlength="10000" placeholder="Welcome to the unit... (Markdown supported)"><?= htmlspecialchars($_about_text) ?></textarea>
					<div class="un-dm-md-preview" id="un-dm-about-preview" style="display:none"></div>
				</div>

				<div class="un-dm-field">
					<div class="un-dm-md-toolbar">
						<label style="margin-bottom:0">Our History</label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="un-dm-md-toggle">
								<button type="button" class="un-active" data-unmd-target="edit" data-unmd-field="history">Write</button>
								<button type="button" data-unmd-target="preview" data-unmd-field="history">Preview</button>
							</div>
						</div>
					</div>
					<textarea id="un-dm-history-text" maxlength="10000" placeholder="The unit was founded in... (Markdown supported)"><?= htmlspecialchars($_our_history) ?></textarea>
					<div class="un-dm-md-preview" id="un-dm-history-preview" style="display:none"></div>
				</div>

				<div class="un-dm-section-title">How to Join</div>
				<div class="un-dm-field">
					<div class="un-dm-md-toolbar">
						<label style="margin-bottom:0">How to Join</label>
						<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
							<div class="un-dm-md-toggle">
								<button type="button" class="un-active" data-unmd-target="edit" data-unmd-field="howto">Write</button>
								<button type="button" data-unmd-target="preview" data-unmd-field="howto">Preview</button>
							</div>
						</div>
					</div>
					<textarea id="un-dm-howto-text" maxlength="5000" placeholder="Reach out to our captain on Discord, then come to a fighter practice... (Markdown supported)"><?= htmlspecialchars($_unHowToJoin) ?></textarea>
					<div class="un-dm-md-preview" id="un-dm-howto-preview" style="display:none"></div>
					<div class="un-dm-hint" style="margin-top:4px">Optional. Markdown supported. Visible to anyone viewing the unit profile.</div>
				</div>

				<div class="un-dm-section-title">Social Links</div>
				<div class="un-dm-field">
					<div class="un-dm-hint" style="margin-bottom:8px">Add full URLs (https://...). Leave blank to hide a platform.</div>
					<div class="un-dm-social-list">
<?php foreach ($_unSocialPlatforms as $_slug => $_meta): ?>
						<div class="un-dm-social-row">
							<span class="un-dm-social-label">
								<span class="un-dm-social-icon" style="background:<?= htmlspecialchars($_meta['bg']) ?>"><i class="<?= htmlspecialchars($_meta['icon']) ?>"></i></span>
								<?= htmlspecialchars($_meta['label']) ?>
							</span>
							<input type="url" data-un-social="<?= htmlspecialchars($_slug) ?>" maxlength="500" placeholder="<?= htmlspecialchars($_meta['placeholder']) ?>" value="<?= htmlspecialchars($_unSocialLinks[$_slug] ?? '') ?>" />
						</div>
<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- Milestones Panel -->
			<div class="un-dm-panel" id="un-dm-panel-milestones">
				<div class="un-dm-hint" style="margin-bottom:10px">Milestones appear on the unit profile in date order. The single derived milestone (first recorded member activity) comes from attendance data; the rest are custom entries you add below.</div>

				<div class="un-dm-field">
					<label>Visible Milestone Types</label>
					<div class="un-dm-ms-toggles" id="un-dm-ms-toggles">
						<label class="un-dm-ms-toggle"><input type="checkbox" data-unms-type="first_member_activity" <?= $_un_ms_visible('first_member_activity') ? 'checked' : '' ?> /> <i class="fas fa-door-open"></i> First Member Activity</label>
						<label class="un-dm-ms-toggle"><input type="checkbox" data-unms-type="custom" <?= $_un_ms_visible('custom') ? 'checked' : '' ?> /> <i class="fas fa-pen"></i> Custom Milestones</label>
					</div>
					<label class="un-dm-ms-toggle" style="margin-top:4px">
						<input type="checkbox" id="un-dm-ms-newest-first" <?= $_un_ms_newest_first ? 'checked' : '' ?> />
						Show newest first
					</label>
				</div>

				<div class="un-dm-field">
					<label>Custom Milestones</label>
					<div class="un-dm-ms-list" id="un-dm-ms-list"></div>
					<div class="un-dm-ms-add">
						<div><input type="text" id="un-dm-ms-add-desc" placeholder="What happened?" maxlength="500" /></div>
						<div><input type="date" id="un-dm-ms-add-date" /></div>
						<div><button type="button" class="un-dm-btn un-dm-btn-primary" id="un-dm-ms-add-btn" style="width:100%"><i class="fas fa-plus"></i> Add</button></div>
					</div>
					<div class="un-dm-ms-icons" id="un-dm-ms-icons" style="margin-top:8px">
						<?php $_unIcons = ['fa-star','fa-trophy','fa-flag','fa-chess-rook','fa-crown','fa-medal','fa-shield-alt','fa-fire','fa-bolt','fa-scroll','fa-users','fa-dragon','fa-hammer','fa-heart','fa-home','fa-anchor']; ?>
						<?php foreach ($_unIcons as $_ic): ?>
						<div class="un-dm-ms-icon-opt<?= $_ic === 'fa-star' ? ' un-active' : '' ?>" data-icon="<?= htmlspecialchars($_ic) ?>"><i class="fas <?= htmlspecialchars($_ic) ?>"></i></div>
						<?php endforeach; ?>
					</div>
					<div class="un-dm-hint" id="un-dm-ms-add-err" style="color:#c53030;display:none;margin-top:6px"></div>
				</div>
			</div>
		</div>
		<div class="un-dm-footer">
			<button class="un-dm-btn" id="un-dm-cancel">Cancel</button>
			<button class="un-dm-btn un-dm-btn-primary" id="un-dm-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<script>
(function() {
	var UNIT_ID = <?= (int)$_unit_id ?>;
	var BASE_URL = '<?= htmlspecialchars($_base_url) ?>';
	var UN_FONTS = [
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
	var INITIAL_CUSTOM_MS = <?php
		$customOnly = array_values(array_filter($_un_all_ms, function($m){ return empty($m['IsDerived']); }));
		echo json_encode(array_map(function($m){
			return [
				'MilestoneId'   => (int)$m['MilestoneId'],
				'Icon'          => $m['Icon'],
				'Description'   => $m['Description'],
				'MilestoneDate' => $m['MilestoneDate'],
			];
		}, $customOnly));
	?>;
	var unSelectedFont = <?= json_encode($_un_name_font) ?>;
	var unSelectedIcon = 'fa-star';
	var customMs = INITIAL_CUSTOM_MS.slice();

	function gid(id) { return document.getElementById(id); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

	window.unOpenDesignModal = function(panel) {
		gid('un-dm-overlay').classList.add('un-open');
		document.body.style.overflow = 'hidden';
		if (panel) unSwitchDmPanel(panel);
		renderCustomMsList();
	};
	function close() {
		gid('un-dm-overlay').classList.remove('un-open');
		document.body.style.overflow = '';
	}
	gid('un-dm-close').addEventListener('click', close);
	gid('un-dm-cancel').addEventListener('click', close);
	gid('un-dm-overlay').addEventListener('click', function(e) {
		if (e.target === this) close();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('un-dm-overlay').classList.contains('un-open')) close();
	});

	function unSwitchDmPanel(name) {
		document.querySelectorAll('.un-dm-tab').forEach(function(t) { t.classList.remove('un-active'); });
		document.querySelectorAll('.un-dm-panel').forEach(function(p) { p.classList.remove('un-active'); });
		var tab = document.querySelector('.un-dm-tab[data-untab-dm="' + name + '"]');
		var panel = gid('un-dm-panel-' + name);
		if (tab) tab.classList.add('un-active');
		if (panel) panel.classList.add('un-active');
	}
	document.querySelectorAll('.un-dm-tab').forEach(function(t) {
		t.addEventListener('click', function() { unSwitchDmPanel(t.dataset.untabDm); });
	});

	var swatches = document.querySelectorAll('.un-dm-swatch');
	swatches.forEach(function(sw) {
		sw.addEventListener('click', function() {
			swatches.forEach(function(s) { s.classList.remove('un-selected'); });
			sw.classList.add('un-selected');
			gid('un-dm-color-primary').value     = sw.dataset.primary;
			gid('un-dm-color-primary-hex').value = sw.dataset.primary;
			gid('un-dm-color-accent').value      = sw.dataset.accent;
			gid('un-dm-color-accent-hex').value  = sw.dataset.accent;
			if (sw.dataset.secondary) {
				gid('un-dm-color-secondary').value     = sw.dataset.secondary;
				gid('un-dm-color-secondary-hex').value = sw.dataset.secondary;
				gid('un-dm-gradient-enabled').checked  = true;
			} else {
				gid('un-dm-color-secondary-hex').value = '';
				gid('un-dm-gradient-enabled').checked  = false;
			}
		});
	});
	function syncHex(colorId, hexId) {
		gid(colorId).addEventListener('input', function() { gid(hexId).value = this.value; });
		gid(hexId).addEventListener('input', function() {
			if (/^#[0-9a-f]{6}$/i.test(this.value)) { gid(colorId).value = this.value; }
		});
	}
	syncHex('un-dm-color-primary',   'un-dm-color-primary-hex');
	syncHex('un-dm-color-accent',    'un-dm-color-accent-hex');
	syncHex('un-dm-color-secondary', 'un-dm-color-secondary-hex');

	document.querySelectorAll('.un-dm-overlay-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.un-dm-overlay-btn').forEach(function(b) { b.classList.remove('un-active'); });
			btn.classList.add('un-active');
			gid('un-dm-hero-overlay').value = btn.dataset.overlay;
		});
	});

	function unLoadFont(key) {
		if (!key) return;
		if (document.querySelector('link[data-un-font="' + key + '"]')) return;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = 'https://fonts.googleapis.com/css2?family=' + key.replace(/ /g, '+') + '&display=swap';
		link.setAttribute('data-un-font', key);
		document.head.appendChild(link);
	}
	function unRenderFontPicker() {
		var container = gid('un-dm-font-picker');
		var sample = <?= json_encode($_name) ?>;
		var html = '';
		for (var i = 0; i < UN_FONTS.length; i++) {
			var f = UN_FONTS[i];
			var active = f.key === unSelectedFont;
			html += '<div class="un-dm-font-card' + (active ? ' un-active' : '') + '" data-font-key="' + esc(f.key) + '">'
				 +    '<div class="un-dm-font-sample" style="font-family:' + f.family + '">' + esc(sample) + '</div>'
				 +    '<div class="un-dm-font-label">' + esc(f.label) + '</div>'
				 + '</div>';
			unLoadFont(f.key);
		}
		container.innerHTML = html;
		container.addEventListener('click', function(e) {
			var card = e.target.closest('.un-dm-font-card');
			if (!card) return;
			unSelectedFont = card.dataset.fontKey;
			container.querySelectorAll('.un-dm-font-card').forEach(function(c) {
				c.classList.toggle('un-active', c === card);
			});
		});
	}
	unRenderFontPicker();

	document.querySelectorAll('[data-unmd-target]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var field  = btn.dataset.unmdField;
			var target = btn.dataset.unmdTarget;
			var taId   = field === 'about' ? 'un-dm-about-text' : (field === 'history' ? 'un-dm-history-text' : 'un-dm-howto-text');
			var ta     = gid(taId);
			var pv     = gid('un-dm-' + field + '-preview');
			btn.parentElement.querySelectorAll('button').forEach(function(b) { b.classList.remove('un-active'); });
			btn.classList.add('un-active');
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

	function renderCustomMsList() {
		var list = gid('un-dm-ms-list');
		if (!list) return;
		var newestFirst = gid('un-dm-ms-newest-first').checked;
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
			html += '<div class="un-dm-ms-row" data-ms-id="' + m.MilestoneId + '">'
				 +    '<i class="fas ' + icon + '"></i>'
				 +    '<span class="un-dm-ms-desc">' + esc(m.Description) + '</span>'
				 +    '<span class="un-dm-ms-date">' + dateStr + '</span>'
				 +    '<button type="button" data-tip="Delete" onclick="unDeleteUnitMilestone(' + m.MilestoneId + ')"><i class="fas fa-trash"></i></button>'
				 + '</div>';
		}
		list.innerHTML = html;
	}
	gid('un-dm-ms-newest-first').addEventListener('change', renderCustomMsList);

	var iconGrid = gid('un-dm-ms-icons');
	iconGrid.addEventListener('click', function(e) {
		var opt = e.target.closest('.un-dm-ms-icon-opt');
		if (!opt) return;
		iconGrid.querySelectorAll('.un-dm-ms-icon-opt').forEach(function(o) { o.classList.remove('un-active'); });
		opt.classList.add('un-active');
		unSelectedIcon = opt.dataset.icon;
	});

	gid('un-dm-ms-add-btn').addEventListener('click', function() {
		var desc = gid('un-dm-ms-add-desc').value.trim();
		var date = gid('un-dm-ms-add-date').value;
		var err  = gid('un-dm-ms-add-err');
		err.style.display = 'none';
		if (!desc) { err.textContent = 'Description is required.'; err.style.display = ''; return; }
		if (!date) { err.textContent = 'Date is required.'; err.style.display = ''; return; }
		var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
		var fd = new FormData();
		fd.append('Action', 'add_milestone');
		fd.append('Description', desc);
		fd.append('MilestoneDate', date);
		fd.append('Icon', unSelectedIcon);
		fetch(BASE_URL, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					customMs.push({
						MilestoneId: result.milestoneId,
						Icon: unSelectedIcon,
						Description: desc,
						MilestoneDate: date
					});
					renderCustomMsList();
					gid('un-dm-ms-add-desc').value = '';
					gid('un-dm-ms-add-date').value = '';
					iconGrid.querySelectorAll('.un-dm-ms-icon-opt').forEach(function(o) { o.classList.remove('un-active'); });
					iconGrid.querySelector('[data-icon="fa-star"]').classList.add('un-active');
					unSelectedIcon = 'fa-star';
				} else {
					err.textContent = (result && result.error) || 'Failed to add milestone.';
					err.style.display = '';
				}
			})
			.catch(function() { err.textContent = 'Request failed.'; err.style.display = ''; })
			.finally(function() {
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-plus"></i> Add';
			});
	});

	window.unDeleteUnitMilestone = function(id) {
		if (!confirm('Delete this milestone?')) return;
		var fd = new FormData();
		fd.append('Action', 'delete_milestone');
		fd.append('MilestoneId', id);
		fetch(BASE_URL, { method: 'POST', body: fd })
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

	(function(){
		var taglineEl = gid('un-dm-tagline'), tCount = gid('un-dm-tagline-count');
		if (taglineEl && tCount) taglineEl.addEventListener('input', function(){ tCount.textContent = taglineEl.value.length; });
		var annEl = gid('un-dm-announcement'), aCount = gid('un-dm-announcement-count');
		if (annEl && aCount) annEl.addEventListener('input', function(){ aCount.textContent = annEl.value.length; });
		var recruitRow = gid('un-dm-recruit-row');
		if (recruitRow) {
			recruitRow.addEventListener('click', function(e){
				var btn = e.target.closest('.un-dm-recruit-opt');
				if (!btn) return;
				recruitRow.querySelectorAll('.un-dm-recruit-opt').forEach(function(b){ b.classList.remove('un-active'); });
				btn.classList.add('un-active');
				gid('un-dm-recruitment-status').value = btn.dataset.recruit || '';
			});
		}
	})();

	gid('un-dm-save').addEventListener('click', function() {
		var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		var errEl = gid('un-dm-error'); errEl.style.display = 'none';
		var fd = new FormData();
		fd.append('Action', 'save_design');
		fd.append('AboutText', gid('un-dm-about-text').value);
		fd.append('OurHistory', gid('un-dm-history-text').value);
		fd.append('ColorPrimary', gid('un-dm-color-primary').value);
		fd.append('ColorAccent', gid('un-dm-color-accent').value);
		fd.append('ColorSecondary', gid('un-dm-gradient-enabled').checked ? gid('un-dm-color-secondary').value : '');
		fd.append('HeroOverlay', gid('un-dm-hero-overlay').value);
		fd.append('NameFont', unSelectedFont || '');
		var msConfig = {};
		document.querySelectorAll('#un-dm-ms-toggles input[data-unms-type]').forEach(function(t) {
			msConfig[t.dataset.unmsType] = t.checked ? 1 : 0;
		});
		msConfig['newest_first'] = gid('un-dm-ms-newest-first').checked ? 1 : 0;
		fd.append('MilestoneConfig', JSON.stringify(msConfig));
		fd.append('Tagline', gid('un-dm-tagline') ? gid('un-dm-tagline').value : '');
		fd.append('Announcement', gid('un-dm-announcement') ? gid('un-dm-announcement').value : '');
		fd.append('AnnouncementUntil', gid('un-dm-announcement-until') ? gid('un-dm-announcement-until').value : '');
		fd.append('RecruitmentStatus', gid('un-dm-recruitment-status') ? gid('un-dm-recruitment-status').value : '');
		fd.append('HowToJoin', gid('un-dm-howto-text') ? gid('un-dm-howto-text').value : '');
		var social = {};
		document.querySelectorAll('[data-un-social]').forEach(function(inp){
			var v = inp.value.trim();
			if (v !== '') social[inp.dataset.unSocial] = v;
		});
		fd.append('SocialLinks', JSON.stringify(social));

		fetch(BASE_URL, { method: 'POST', body: fd })
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
