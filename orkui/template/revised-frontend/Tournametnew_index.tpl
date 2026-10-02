<?php
// ---- Normalize controller data into clean local variables ----
$tournament        = $tournament        ?? [];
$brackets          = $brackets          ?? [];
$bracketData       = $bracket_data      ?? [];
$standingsData     = $standings_data    ?? [];
$placementsData    = $placements_data   ?? [];
$totalBrackets     = (int)($TotalBrackets     ?? 0);
$totalParticipants = (int)($TotalParticipants ?? 0);
$totalMatches      = (int)($TotalMatches      ?? 0);
$canManage         = !empty($CanManageTournament);
$loggedIn          = !empty($LoggedIn);
$canManageReeves   = !empty($CanManageReeves);
$isOrganizerReeve  = !empty($IsOrganizerReeve);
$isBracketRunner   = !empty($IsBracketRunner);
$hasActiveBracket  = !empty($HasActiveBracket);
$isSpectator       = !empty($Spectator);
$reeves            = $Reeves ?? [];
// Current logged-in user's MundaneId (recommendation "recommended by" + reeve self-exclusion)
$tCurrentUserId    = isset($this->__session->user_id) ? (int)$this->__session->user_id : 0;
// Staff who may issue award recommendations from the standings table
$canRecommend      = $canManage || $isBracketRunner;
// Staff who may record match results + enter points scores (organizers, bracket
// runners, and organizer reeves — not just full tournament managers). Bracket
// runners exist specifically to record results, so they must reach the Record
// Result modal DOM + the points-grid edit controls.
$canRecordResult   = $canManage || $isBracketRunner || $isOrganizerReeve;
// Role display labels for reeve badges
$reeveRoleLabels   = ['organizer' => 'Organizer', 'bracket_runner' => 'Bracket Runner'];

$tid          = (int)($tournament['TournamentId']          ?? 0);
$tName        = $tournament['Name']                        ?? 'Tournament';
$tDescription = trim($tournament['Description']            ?? '');
$tUrl         = trim($tournament['Url']                    ?? '');
// Only ever render the Url as a link when it is an http(s) URL. Anything else
// (javascript:, data:, vbscript:, relative, empty) is treated as non-linkable
// to prevent stored-XSS via the href. $tUrlIsLink gates the <a>; callers that
// still want to show the raw text can use htmlspecialchars($tUrl) directly.
$tUrlIsLink   = ($tUrl !== '' && preg_match('~^https?://~i', $tUrl) === 1);
$tDate        = $tournament['DateTime']                    ?? '';
$tKingdomId   = (int)($tournament['KingdomId']             ?? 0);
// Effective player-search kingdom: tournament > park > event kingdom (controller-resolved)
$tSearchKingdomId = (int)($SearchKingdomId                 ?? 0);
$tKingdomName = $tournament['KingdomName']                 ?? '';
$tParkId      = (int)($tournament['ParkId']                ?? 0);
$tParkName    = $tournament['ParkName']                    ?? '';
$tEventName   = $tournament['EventName']                   ?? '';
$tECDId       = (int)($tournament['EventCalendarDetailId'] ?? 0);
$tEventId     = (int)($tournament['EventId']               ?? 0);
$tEventLabel  = $tournament_event_label ?? '';
$standingsPoints = $standings_points ?? [5,4,3,2,1,0,0,0];

$displayDate   = ($tDate && substr($tDate, 0, 10) !== '0000-00-00')
	? date('F j, Y', strtotime($tDate))
	: 'Date TBD';
$shortDate     = ($tDate && substr($tDate, 0, 10) !== '0000-00-00')
	? date('M j, Y', strtotime($tDate))
	: '—';

if (!function_exists('tnParticipantPills')) {
	function tnParticipantPills(array $p): string {
		$html = '';
		if (($p['WarriorCount'] ?? 0) > 0) {
			$wc = min((int)$p['WarriorCount'], 10);
			$html .= '<span class="tn-pill tn-pill-warrior" data-tip="Order of the Warrior x' . (int)$p['WarriorCount'] . '">' . $wc . '</span>';
		}
		if (!empty($p['IsWarlord']))
			$html .= '<span class="tn-pill tn-pill-warlord" data-tip="Warlord">W</span>';
		if (!empty($p['IsKnightSword']))
			$html .= '<span class="tn-pill tn-pill-knight" data-tip="Knight of the Sword">K</span>';
		return $html ? '<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle">' . $html . '</span>' : '';
	}
}
if (!function_exists('tnPidShield')) {
	// Stable per-person player number, rendered as a distinctive shield badge.
	function tnPidShield($n): string {
		$n = (int)$n;
		if ($n <= 0) return '';
		return '<span class="tn-pid" data-tip="Player #' . $n . ' — same number across every bracket">' . $n . '</span>';
	}
}
if (!function_exists('tnMatchWinnerSide')) {
	// PHP twin of the JS tnWinnerSide(): 1 | 2 | 0. Mirrors TournamentReport::ResolveWinnerId —
	// "N-forfeits"/"N-is-disqualified"/"N-is-bye" name the losing side; legacy 'forfeit'/'disqualified' = P2 wins.
	function tnMatchWinnerSide($result): int {
		switch ((string)$result) {
			case '1-wins': case '2-forfeits': case '2-is-disqualified': case '2-is-bye':
				return 1;
			case '2-wins': case 'forfeit': case 'disqualified':
			case '1-forfeits': case '1-is-disqualified': case '1-is-bye':
				return 2;
			default:
				return 0;
		}
	}
}
if (!function_exists('tnOrdinal')) {
	function tnOrdinal(int $n): string {
		$v = abs($n) % 100;
		if ($v >= 11 && $v <= 13) return $n . 'th';
		return $n . (['th','st','nd','rd'][$v % 10] ?? 'th');
	}
}

// Style label map for display
$styleLabelMap = [
	'Single Sword'    => 'Single Sword',
	'Florentine'      => 'Florentine',
	'Sword and Shield'=> 'Sword & Shield',
	'Great Weapon'    => 'Great Weapon',
	'Missile'         => 'Missile',
	'Other'           => 'Other',
	'Open Weapons'    => 'Open Weapons',
	'Jugging'         => 'Jugging',
	'Battlegame'      => 'Battlegame',
	'Quest'           => 'Quest',
];
$methodLabelMap = [
	'single'      => 'Single Elimination',
	'double'      => 'Double Elimination',
	'swiss'       => 'Swiss',
	'round-robin' => 'Round Robin',
	'ironman'     => 'Ironman',
	'points'      => 'Points',
];

// Unique styles across all brackets for hero badges
$heroStyles = [];
foreach ($brackets as $b) {
	$heroStyles[$b['Style']] = true;
}
$heroStyles = array_keys($heroStyles);
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/ork-datatables.css?v=<?= filemtime(__DIR__ . '/style/ork-datatables.css') ?>">

<style>
/* ---- Tournament Profile (tn-) ---- */
:root { --tn-accent: #276749; --tn-accent-light: #f0fff4; }

/* In-product tooltips (project convention: no native data-tip="" tooltips) */
[data-tip] { position:relative; cursor:help; }
[data-tip]::after { content:attr(data-tip); position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); background:#2d3748; color:#fff; font-size:11px; padding:4px 10px; border-radius:4px; white-space:normal; width:max-content; max-width:240px; pointer-events:none; opacity:0; transition:opacity 0.05s; z-index:500; }
[data-tip]::before { content:''; position:absolute; bottom:calc(100% + 2px); left:50%; transform:translateX(-50%); border:4px solid transparent; border-top-color:#2d3748; pointer-events:none; opacity:0; transition:opacity 0.05s; z-index:500; }
/* Right-edge action buttons: anchor the bubble to the right so long tips don't clip past the viewport. */
[data-tip][data-tip-right]::after { left:auto; right:0; transform:none; }
[data-tip][data-tip-right]::before { left:auto; right:8px; transform:none; }
[data-tip]:hover::after, [data-tip]:hover::before { opacity:1; }
html[data-theme="dark"] [data-tip]::after { background:#1a202c; color:#e2e8f0; }
html[data-theme="dark"] [data-tip]::before { border-top-color:#1a202c; }

/* Hero */
.tn-hero { position:relative; background:linear-gradient(135deg,#1a202c 0%,#2d3748 100%); color:#fff; padding:28px 24px 22px; border-radius:0 0 12px 12px; margin-bottom:0; overflow:hidden; }
.tn-hero-bg { position:absolute; inset:0; background-size:cover; background-position:center; opacity:0.12; pointer-events:none; }
.tn-hero-content { position:relative; display:flex; align-items:flex-start; gap:18px; flex-wrap:wrap; }
.tn-hero-icon { width:72px; height:72px; background:rgba(255,255,255,0.1); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:32px; flex-shrink:0; border:2px solid rgba(255,255,255,0.18); }
.tn-hero-center { flex:1; min-width:0; }
.tn-hero-center h1 { background:transparent!important; border:none!important; padding:0!important; border-radius:0!important; text-shadow:0 2px 8px rgba(0,0,0,0.35)!important; font-size:1.8rem; font-weight:800; color:#fff; margin:0 0 6px; line-height:1.15; }
.tn-breadcrumb { font-size:12px; color:rgba(255,255,255,0.6); margin-bottom:6px; }
.tn-breadcrumb a { color:rgba(255,255,255,0.75); text-decoration:none; }
.tn-breadcrumb a:hover { color:#fff; }
.tn-breadcrumb span { margin:0 5px; }
.tn-hero-badges { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.tn-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; }
.tn-badge-style  { background:rgba(255,255,255,0.15); color:#fff; border:1px solid rgba(255,255,255,0.25); }
.tn-badge-date   { background:rgba(39,103,73,0.6); color:#9ae6b4; border:1px solid rgba(39,103,73,0.5); }
.tn-badge-event  { background:rgba(49,130,206,0.4); color:#bee3f8; border:1px solid rgba(49,130,206,0.4); }
.tn-hero-right { flex-shrink:0; display:flex; align-items:flex-start; }
.tn-hero-actions { display:flex; flex-direction:column; gap:8px; }

/* Playtest warning */
.tn-playtest-warn { display:flex; align-items:flex-start; gap:10px; margin:14px 0 0; padding:11px 14px; background:#fffaf0; border:1px solid #fbd38d; border-left:4px solid #dd6b20; border-radius:8px; color:#7b341e; font-size:13px; line-height:1.45; }
.tn-playtest-warn-icon { font-size:16px; color:#dd6b20; flex-shrink:0; margin-top:2px; }
.tn-playtest-warn-text { flex:1; }
.tn-playtest-warn-text strong { color:#7b341e; font-weight:700; }

/* ---- Spectator Mode banner (Feature 1) ---- */
.tn-spectator-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:9px 16px; background:#fff5f5; border:1px solid #feb2b2; border-left:4px solid #e53e3e; border-radius:8px; margin:0 0 14px; color:#742a2a; font-size:13px; }
.tn-spectator-bar.tn-spectator-hidden { display:none; }
.tn-spectator-msg { display:inline-flex; align-items:center; gap:8px; }
.tn-spectator-msg strong { color:#742a2a; font-weight:700; }
.tn-spectator-dot { width:9px; height:9px; border-radius:50%; background:#e53e3e; box-shadow:0 0 0 0 rgba(229,62,62,0.6); animation:tnSpectatorPulse 1.6s infinite; flex-shrink:0; }
@keyframes tnSpectatorPulse { 0% { box-shadow:0 0 0 0 rgba(229,62,62,0.55); } 70% { box-shadow:0 0 0 7px rgba(229,62,62,0); } 100% { box-shadow:0 0 0 0 rgba(229,62,62,0); } }
.tn-spectator-sync { color:#9b2c2c; font-size:11px; font-weight:500; }
.tn-spectator-sync.tn-spectator-flash { color:#276749; }
.tn-spectator-dismiss { background:transparent; border:none; color:#9b2c2c; cursor:pointer; font-size:14px; padding:2px 6px; border-radius:4px; line-height:1; }
.tn-spectator-dismiss:hover { background:rgba(229,62,62,0.12); color:#742a2a; }
@media (prefers-reduced-motion: reduce) { .tn-spectator-dot { animation:none; } }

/* ---- Tournament Reeves panel (Feature 2) ---- */
.tn-reeves-card { margin-top:18px; padding:14px 16px; background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; }
.tn-reeves-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.tn-reeves-title { margin:0; font-size:14px; font-weight:700; color:#2d3748; background:transparent; border:none; padding:0; border-radius:0; text-shadow:none; }
.tn-reeves-title i { color:#276749; margin-right:5px; }
.tn-reeves-sub { margin:8px 0 12px; font-size:12px; color:#718096; line-height:1.5; }
.tn-reeves-sub strong { color:#4a5568; }
.tn-reeves-list { list-style:none; margin:0; padding:0; }
.tn-reeves-empty { padding:10px 4px; font-size:13px; color:#a0aec0; }
.tn-reeve-row { display:flex; align-items:center; gap:10px; padding:8px 4px; border-bottom:1px solid #edf2f7; }
.tn-reeve-row:last-child { border-bottom:none; }
.tn-reeve-persona { flex:1; font-size:13px; font-weight:600; }
.tn-reeve-persona a { color:#276749; text-decoration:none; }
.tn-reeve-persona a:hover { text-decoration:underline; }
.tn-reeve-badge { font-size:11px; font-weight:700; padding:2px 9px; border-radius:11px; white-space:nowrap; }
.tn-reeve-badge-organizer { background:#e9d8fd; color:#553c9a; }
.tn-reeve-badge-bracket_runner { background:#c6f6d5; color:#22543d; }
.tn-reeve-remove { background:transparent; border:none; color:#cbd5e0; cursor:pointer; font-size:13px; padding:3px 7px; border-radius:4px; line-height:1; }
.tn-reeve-remove:hover { background:#fed7d7; color:#c53030; }
.tn-reeve-remove.tn-reeve-confirm { background:#fed7d7; color:#c53030; font-size:11px; font-weight:700; }

/* ---- Recommend column + modal (Feature 3) ---- */
.tn-rec-actions { display:inline-flex; gap:6px; white-space:nowrap; }
.tn-rec-btn { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:3px 9px; border-radius:13px; border:1px solid #e2e8f0; background:#fff; color:#4a5568; cursor:pointer; }
.tn-rec-btn i { color:#d69e2e; }
.tn-rec-btn:hover { background:#fffaf0; border-color:#f6e05e; color:#744210; }
.tn-rec-target { font-size:13px; color:#4a5568; line-height:1.5; margin:0 0 12px; }
.tn-rec-target strong { color:#1a202c; }
.tn-rank-pills { display:flex; flex-wrap:wrap; gap:6px; }
.tn-rank-pill { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; font-weight:600; color:#4a5568; cursor:pointer; user-select:none; background:transparent; padding:0; margin:0; box-sizing:content-box; font-family:inherit; line-height:1; }
.tn-rank-pill:focus-visible { outline:2px solid #276749; outline-offset:2px; }
.tn-rank-pill:hover { border-color:#276749; }
.tn-rank-pill.tn-rank-selected { background:#276749; border-color:#276749; color:#fff; }
.tn-rec-standing { font-size:12px; color:#4a5568; margin:0 0 8px; min-height:16px; }
.tn-rec-standing-topped { color:#c05621; font-weight:600; }
.tn-char-count { display:block; margin-top:5px; font-size:11px; color:#a0aec0; }
.tn-char-count.tn-char-warn { color:#dd6b20; }

/* Stats row */
.tn-stats-row { display:flex; gap:12px; padding:14px 0; flex-wrap:wrap; }
.tn-stat-card { flex:1; min-width:120px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 12px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
.tn-stat-card-link { cursor:pointer; transition:box-shadow 0.15s, border-color 0.15s; }
.tn-stat-card-link:hover { border-color:#276749; box-shadow:0 2px 8px rgba(39,103,73,0.12); }
.tn-stat-icon { font-size:18px; color:#a0aec0; margin-bottom:4px; }
.tn-stat-value { font-size:1.6rem; font-weight:800; color:#1a202c; line-height:1; }
.tn-stat-sub { font-size:11px; color:#718096; margin-top:2px; }
.tn-stat-label { font-size:11px; color:#718096; margin-top:4px; text-transform:uppercase; letter-spacing:0.5px; font-weight:600; }

/* Layout */
.tn-layout { display:flex; gap:18px; align-items:flex-start; }
.tn-sidebar { width:264px; flex-shrink:0; display:flex; flex-direction:column; gap:12px; }
.tn-main { flex:1; min-width:0; }

/* Card */
.tn-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
.tn-card h4 { margin:0 0 10px; font-size:13px; font-weight:700; color:#4a5568; display:flex; align-items:center; gap:6px; background:transparent!important; border:none!important; padding:0!important; border-radius:0!important; text-shadow:none!important; }
.tn-detail-row { display:flex; align-items:flex-start; gap:8px; padding:5px 0; font-size:13px; color:#4a5568; border-bottom:1px solid #f0f4f8; }
.tn-detail-row:last-child { border-bottom:none; }
.tn-detail-icon { width:16px; color:#a0aec0; flex-shrink:0; margin-top:2px; text-align:center; }
.tn-detail-text { flex:1; word-break:break-word; }
.tn-detail-text a { color:#276749; text-decoration:none; }
.tn-detail-text a:hover { text-decoration:underline; }

/* Tabs */
/* overflow:clip (hidden fallback) — clips like hidden but is not a scroll container, so a
   focus()/scrollIntoView() on wide Run-view content can't shift the tab strip sideways. */
.tn-tabs { background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden; overflow:clip; }
.tn-tab-nav { list-style:none; margin:0; padding:0; display:flex; flex-wrap:wrap; border-bottom:1px solid #e2e8f0; overflow:hidden; overflow:clip; }
.tn-tab-nav::-webkit-scrollbar { display:none; }
.tn-tab-nav li { padding:11px 16px; font-size:13px; font-weight:600; color:#718096; cursor:pointer; border-bottom:2px solid transparent; white-space:nowrap; display:flex; align-items:center; gap:5px; }
.tn-tab-nav li:hover { color:#276749; background:#f7fafc; }
.tn-tab-active { color:#276749!important; border-bottom-color:#276749!important; background:#fff!important; }
.tn-tab-count { font-size:11px; color:#a0aec0; }
.tn-tab-panel { padding:16px 18px; }

/* Bracket cards */
.tn-bracket-card { border:1px solid #e2e8f0; border-radius:8px; margin-bottom:14px; overflow:hidden; border-left:4px solid #a0aec0; }
.tn-bracket-card[data-method="single"] { border-left-color:#276749; }
.tn-bracket-card[data-method="double"] { border-left-color:#2b6cb0; }
.tn-bracket-card[data-method="swiss"] { border-left-color:#d69e2e; }
.tn-bracket-card[data-method="round-robin"] { border-left-color:#9f7aea; }
.tn-bracket-card[data-method="ironman"] { border-left-color:#e53e3e; }
.tn-bracket-card[data-method="points"] { border-left-color:#0bc5ea; }
/* Points-bracket pip styles (Fixed mode pips and AddBracket preview) */
.tn-pip {
	display:inline-flex; align-items:center; justify-content:center;
	min-width:32px; height:28px; padding:0 10px;
	border:1px solid #cbd5e0; border-radius:14px;
	background:#fff; color:#2d3748;
	font-weight:600; font-size:13px; cursor:pointer;
	user-select:none;
	transition:background-color .1s, color .1s, border-color .1s;
}
.tn-pip:hover { border-color:#4a5568; }
.tn-pip.tn-pip-selected { background:#2b6cb0; color:#fff; border-color:#2b6cb0; }
.tn-pip-preview { cursor:default; }
html[data-theme="dark"] .tn-pip { background:#2d3748; color:#e2e8f0; border-color:#4a5568; }
html[data-theme="dark"] .tn-pip:hover { border-color:#a0aec0; }
html[data-theme="dark"] .tn-pip.tn-pip-selected { background:#3182ce; color:#fff; border-color:#3182ce; }
/* Points-bracket grid */
.tn-points-wrap { margin:8px 0; }
.tn-points-ribbon {
	display:flex; flex-wrap:wrap; gap:14px; padding:8px 12px; margin-bottom:8px;
	background:#f7fafc; border:1px solid #e2e8f0; border-radius:6px;
	font-size:13px;
}
.tn-points-rib-item strong { color:#2b6cb0; margin-right:4px; }
.tn-points-grid-scroll { overflow-x:auto; }
.tn-points-grid {
	border-collapse:separate; border-spacing:0;
	width:100%; min-width:520px;
	background:transparent;
}
.tn-points-grid th, .tn-points-grid td {
	padding:6px 8px; border-bottom:1px solid #edf2f7; vertical-align:middle;
}
.tn-points-grid th {
	background:#edf2f7; color:#2d3748;
	font-size:12px; font-weight:600; text-align:center;
	border:none; padding:6px 8px; border-radius:0;
}
.tn-points-grid th.tn-points-col-player,
.tn-points-grid td.tn-points-col-player {
	text-align:left; position:sticky; left:0; z-index:1; min-width:160px;
}
.tn-points-grid th.tn-points-col-total,
.tn-points-grid td.tn-points-col-total {
	text-align:right; font-weight:700; position:sticky; right:0; z-index:1; white-space:nowrap;
	/* Fixed border-box width so the sticky '+' column can sit exactly left of it. */
	box-sizing:border-box; width:var(--tn-pts-total-w, 84px); min-width:var(--tn-pts-total-w, 84px);
}
/* '+' (add round) column: sticky just left of Total so new rounds never slide under it. */
.tn-points-grid th.tn-points-col-add,
.tn-points-grid td.tn-points-col-add {
	position:sticky; right:var(--tn-pts-total-w, 84px); z-index:1;
}
/* Frozen Player/Total columns need an OPAQUE background so scrolled round
   cells don't bleed through. Explicit light values here; dark-mode overrides
   below. (Header cells inherit the dark th bg via the later dark th rule.) */
.tn-points-grid td.tn-points-col-player,
.tn-points-grid td.tn-points-col-add,
.tn-points-grid td.tn-points-col-total { background:#fff; }
.tn-points-grid th.tn-points-col-player,
.tn-points-grid th.tn-points-col-add,
.tn-points-grid th.tn-points-col-total { background:#edf2f7; }
.tn-points-cell { text-align:center; }
/* Keep each round's pip group on one line; extra rounds scroll horizontally
   inside .tn-points-grid-scroll instead of wrapping. */
.tn-points-grid .tn-pips { display:inline-flex; flex-wrap:nowrap; gap:4px; white-space:nowrap; }
@media (min-width: 769px) {
	.tn-points-grid td.tn-points-col-player { white-space:nowrap; min-width:180px; }
}
.tn-points-row-inactive { opacity:.55; }
.tn-points-input {
	width:48px; padding:4px; text-align:center;
	border:1px solid #cbd5e0; border-radius:4px;
	font-size:13px; background:#fff; color:#2d3748;
}
.tn-points-input:focus { outline:none; border-color:#3182ce; box-shadow:0 0 0 2px rgba(49,130,206,.25); }
.tn-points-input.tn-points-err { border-color:#e53e3e; }
.tn-points-readonly { color:#4a5568; }
.tn-points-status {
	display:inline-block; width:14px; height:14px; margin-left:4px; vertical-align:middle;
}
.tn-points-status.tn-saving::before { content:'...'; color:#a0aec0; }
.tn-points-status.tn-saved::before  { content:'OK'; color:#48bb78; font-size:10px; font-weight:700; }
.tn-points-status.tn-error::before  { content:'!'; color:#e53e3e; font-weight:700; }
html[data-theme="dark"] .tn-points-ribbon { background:#2d3748; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .tn-points-rib-item strong { color:#63b3ed; }
html[data-theme="dark"] .tn-points-grid th { background:#2d3748; color:#e2e8f0; }
html[data-theme="dark"] .tn-points-grid td { border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .tn-points-grid td.tn-points-col-player { background:#1a202c; }
html[data-theme="dark"] .tn-points-grid td.tn-points-col-total,
html[data-theme="dark"] .tn-points-grid td.tn-points-col-add { background:#1a202c; }
html[data-theme="dark"] .tn-points-input { background:#2d3748; color:#e2e8f0; border-color:#4a5568; }
html[data-theme="dark"] .tn-points-readonly { color:#a0aec0; }

/* Leaderboard / roster tables — theme-aware text (task #106). These tables are
   built as HTML strings in JS and formerly used hardcoded inline colors (a
   near-white #e2e8f0 rank illegible on the light table, dark-green links and
   #718096 grays) that ignored the theme toggle. Classes carry light + dark. */
.tn-lb-rank { color:#64748b; font-weight:700; }
.tn-lb-rank-tied { color:#64748b; font-weight:400; }
.tn-lb-link { color:#276749; text-decoration:none; }
.tn-lb-muted { color:#64748b; }
.tn-lb-points { color:#276749; font-weight:800; }
html[data-theme="dark"] .tn-lb-rank { color:#cbd5e0; }
html[data-theme="dark"] .tn-lb-rank-tied { color:#a0aec0; }
html[data-theme="dark"] .tn-lb-link { color:#68d391; }
html[data-theme="dark"] .tn-lb-muted { color:#a0aec0; }
html[data-theme="dark"] .tn-lb-points { color:#68d391; }

/* Points-bracket mobile sizing: finger-friendly pips + inputs under .tn-mobile. */
.tn-mobile .tn-points-grid { min-width:380px; }
.tn-mobile .tn-points-grid th,
.tn-mobile .tn-points-grid td { padding:8px 6px; }
.tn-mobile .tn-points-grid td.tn-points-col-player { min-width:120px; font-size:13px; }
.tn-mobile .tn-pip { min-width:36px; height:32px; font-size:14px; }
.tn-mobile .tn-points-input { width:54px; height:32px; font-size:14px; }
.tn-mobile .tn-points-ribbon { font-size:12px; gap:10px; }

.tn-bracket-card:last-child { margin-bottom:0; }
.tn-bracket-header { background:#f7fafc; padding:12px 14px; display:flex; align-items:center; gap:10px; border-bottom:1px solid #e2e8f0; }
.tn-bracket-header h4 { margin:0; font-size:14px; font-weight:700; color:#1a202c; background:transparent!important; border:none!important; padding:0!important; border-radius:0!important; text-shadow:none!important; }
.tn-bracket-meta { font-size:12px; color:#718096; display:flex; gap:10px; flex-wrap:wrap; margin-top:3px; }
.tn-bracket-meta span { display:inline-flex; align-items:center; gap:3px; }
.tn-bracket-body { padding:12px 14px; }
.tn-participant-list { list-style:none; margin:0; padding:0; }
.tn-participant-list li { display:flex; align-items:center; gap:8px; padding:5px 0; font-size:13px; color:#4a5568; border-bottom:1px solid #f0f4f8; }
.tn-participant-list li:last-child { border-bottom:none; }
.tn-participant-seed { width:20px; height:20px; background:#e2e8f0; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; color:#718096; flex-shrink:0; }
/* Stable player-ID shield (same number across every bracket) + seed marker */
.tn-pid { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:21px; padding:0 5px 3px; margin-right:4px; font-size:10px; font-weight:800; line-height:1; color:#fff; background:linear-gradient(135deg,#4c51bf,#5a67d8); -webkit-clip-path:polygon(0 0,100% 0,100% 60%,50% 100%,0 60%); clip-path:polygon(0 0,100% 0,100% 60%,50% 100%,0 60%); flex-shrink:0; box-shadow:0 1px 2px rgba(0,0,0,0.18); }
td .tn-pid { vertical-align:middle; }
.tn-bv-tree .tn-pid { height:18px; min-width:15px; font-size:9px; padding:0 4px 2px; margin-right:3px; }
.tn-seedling { color:#68a063; }
.tn-boutlist-seed .tn-seedling, .tn-bv-seed .tn-seedling { margin-right:2px; opacity:0.9; }
html[data-theme="dark"] .tn-pid { background:linear-gradient(135deg,#667eea,#7f9cf5); color:#fff; }
html[data-theme="dark"] .tn-seedling { color:#9ae6b4; }
/* Inline alias editor */
.tn-alias-edit { background:none; border:none; padding:0 4px; margin-left:2px; cursor:pointer; color:#a0aec0; font-size:11px; opacity:0; transition:opacity .12s,color .12s; }
.tn-participant-list li:hover .tn-alias-edit { opacity:1; }
.tn-table tr:hover .tn-alias-edit { opacity:1; }
.tn-alias-edit:focus { opacity:1; outline:none; }
.tn-alias-edit:hover { color:#276749; }
.tn-alias-input { font-size:13px; font-weight:600; padding:1px 5px; border:1px solid #276749; border-radius:4px; min-width:120px; max-width:220px; }
.tn-alias-warn { margin-top:6px; padding:7px 10px; font-size:12px; line-height:1.4; background:#fefcbf; border:1px solid #f6e05e; border-radius:6px; color:#975a16; }
html[data-theme="dark"] .tn-alias-warn { background:#3b3214; border-color:#9c7a1a; color:#f6e05e; }
html[data-theme="dark"] .tn-alias-edit { color:#718096; }
html[data-theme="dark"] .tn-alias-edit:hover { color:#9ae6b4; }
html[data-theme="dark"] .tn-alias-input { background:#1a202c; color:#e2e8f0; border-color:#38a169; }
.tn-empty { color:#a0aec0; font-size:13px; font-style:italic; padding:8px 0; }
.tn-remove-participant { background:none; border:none; color:#cbd5e0; cursor:pointer; font-size:15px; padding:0 2px; line-height:1; flex-shrink:0; }
.tn-remove-participant:hover { color:#e53e3e; }
.tn-remove-participant:disabled, .tn-remove-participant:disabled:hover { color:#cbd5e0; opacity:.5; cursor:not-allowed; }
.tn-pill { display:inline-flex; align-items:center; justify-content:center; font-size:9px; font-weight:700; border-radius:10px; padding:1px 5px; line-height:1.4; letter-spacing:0.3px; flex-shrink:0; }
.tn-pill-warrior { background:#ebf8ff; color:#2b6cb0; border:1px solid #bee3f8; }
.tn-team-member-tag { display:inline-flex; align-items:center; gap:4px; background:#ebf8ff; color:#2b6cb0; border:1px solid #bee3f8; border-radius:12px; padding:3px 10px; font-size:12px; font-weight:600; margin:2px 4px 2px 0; }
.tn-team-member-remove { background:none; border:none; color:#2b6cb0; cursor:pointer; font-size:14px; line-height:1; padding:0 0 0 4px; }
.tn-createteam-reglist { display:flex; flex-wrap:wrap; gap:6px; max-height:160px; overflow-y:auto; }
.tn-createteam-regchip { display:inline-flex; align-items:center; gap:5px; background:#f7fafc; color:#2d3748; border:1px solid #e2e8f0; border-radius:14px; padding:3px 10px; font-size:12px; font-weight:600; cursor:pointer; user-select:none; }
.tn-createteam-regchip:hover { background:#ebf8ff; border-color:#bee3f8; color:#2b6cb0; }
.tn-createteam-regchip i { font-size:10px; color:#718096; }
.tn-createteam-regchip:hover i { color:#2b6cb0; }
.tn-createteam-regchip.tn-createteam-regchip-added { background:#ebf8ff; color:#a0aec0; border-color:#bee3f8; cursor:default; opacity:0.6; }
.tn-createteam-reglist .tn-createteam-regempty { font-size:12px; color:#a0aec0; padding:2px 0; }
html[data-theme="dark"] .tn-createteam-regchip { background:rgba(255,255,255,0.04); color:#cbd5e0; border-color:rgba(255,255,255,0.12); }
html[data-theme="dark"] .tn-createteam-regchip:hover { background:rgba(49,130,206,0.2); color:#90cdf4; border-color:rgba(49,130,206,0.4); }
html[data-theme="dark"] .tn-createteam-regchip i { color:#a0aec0; }
html[data-theme="dark"] .tn-createteam-regchip:hover i { color:#90cdf4; }
html[data-theme="dark"] .tn-createteam-regchip.tn-createteam-regchip-added { background:rgba(49,130,206,0.12); color:#718096; border-color:rgba(49,130,206,0.25); }
html[data-theme="dark"] .tn-createteam-reglist .tn-createteam-regempty { color:#718096; }
.tn-pill-warlord { background:#fff8e1; color:#b45309; border:1px solid #fcd34d; }
.tn-pill-knight  { background:#f0fff4; color:#276749; border:1px solid #9ae6b4; }
.tn-pill-complete { background:#f0fff4; color:#276749; border:1px solid #9ae6b4; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; display:inline-flex; align-items:center; gap:4px; }
.tn-bracket-status { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; line-height:1.4; }
.tn-bracket-status-setup { background:#edf2f7; color:#718096; }
.tn-bracket-status-active { background:#f0fff4; color:#276749; border:1px solid #c6f6d5; }
.tn-bracket-status-active i { animation: tn-pulse 1.5s ease-in-out infinite; }
@keyframes tn-pulse { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
.tn-bracket-status-complete { background:#ebf8ff; color:#2b6cb0; border:1px solid #bee3f8; }
.tn-bracket-status-finalized { background:#faf5ff; color:#6b46c1; border:1px solid #e9d8fd; }
.tn-placement-list { list-style:none; padding:0; margin:0; }
.tn-placement-list li { display:flex; align-items:center; gap:8px; padding:5px 6px; border-bottom:1px solid #f0f4f8; }
.tn-placement-list li:last-child { border-bottom:none; }
.tn-placement-num { font-weight:700; color:#276749; min-width:34px; font-size:12px; flex-shrink:0; }
.tn-placement-spacer { height:6px; border-bottom:none !important; }
.tn-pill-team-wl { background:#e9d8fd; color:#553c9a; border:1px solid #d6bcfa; }
.tn-team-roster-btn { background:none; border:none; color:#276749; cursor:pointer; font-size:11px; font-weight:600; padding:0 4px; white-space:nowrap; text-decoration:none; display:inline-flex; align-items:center; gap:3px; }
.tn-team-roster-btn:hover { text-decoration:underline; }
.tn-team-roster-row td { background:#f7fafc; padding:4px 10px 8px 30px !important; border-bottom:1px solid #e2e8f0; font-size:12px; }
.tn-roster-member { display:inline-flex; align-items:center; gap:4px; margin:2px 8px 2px 0; color:#4a5568; }
.tn-bout-pips { display:flex; gap:7px; justify-content:center; margin-top:8px; }
.tn-bout-pip { width:24px; height:24px; border-radius:50%; border:2px solid #cbd5e0; background:#fff; cursor:pointer; padding:0; transition:background .15s, border-color .15s, transform .1s; flex-shrink:0; }
.tn-bout-pip:hover { border-color:#718096; transform:scale(1.15); }
.tn-bout-pip.tn-pip-win  { background:#276749; border-color:#276749; }
.tn-bout-pip.tn-pip-loss { background:#e53e3e; border-color:#e53e3e; }
.tn-bout-score { text-align:center; font-size:13px; font-weight:700; color:#276749; margin-top:10px; min-height:18px; }
.tn-btn-danger { background:#fff; color:#e53e3e; border:1px solid #e53e3e; }
.tn-btn-danger:hover { background:#e53e3e; color:#fff; }
.tn-bracket-toggle { background:none; border:none; color:#a0aec0; cursor:pointer; padding:4px 6px; display:flex; align-items:center; flex-shrink:0; }
.tn-bracket-toggle:hover { color:#4a5568; }
.tn-bracket-toggle i { transition:transform .2s; }
.tn-bracket-card.tn-collapsed .tn-bracket-toggle i { transform:rotate(-90deg); }
.tn-bracket-card.tn-collapsed .tn-bracket-body { display:none; }
.tn-quickadd-row { display:flex; align-items:center; gap:8px; padding:5px 0; border-bottom:1px solid #f0f4f8; font-size:13px; }
.tn-quickadd-row:last-child { border-bottom:none; }
.tn-quickadd-name { flex:1; color:#4a5568; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tn-quickadd-row.tn-quickadd-done .tn-quickadd-name { color:#a0aec0; text-decoration:line-through; }
.tn-bracket-actions { display:flex; gap:8px; margin-top:10px; padding-top:10px; border-top:1px solid #f0f4f8; }

/* Buttons */
.tn-btn { display:inline-flex; align-items:center; gap:5px; padding:7px 14px; border-radius:6px; font-size:13px; font-weight:600; border:none; cursor:pointer; transition:background 0.15s; }
.tn-btn-primary { background:#276749; color:#fff; }
.tn-btn-primary:hover { background:#1e4e36; }
.tn-btn-outline { background:transparent; color:#276749; border:1px solid #276749; }
.tn-btn-outline:hover { background:#f0fff4; }
.tn-btn-ghost { background:transparent; color:#718096; border:1px solid #e2e8f0; }
.tn-btn-ghost:hover { background:#f7fafc; }
.tn-btn-sm { padding:4px 10px; font-size:12px; }
.tn-btn:disabled { opacity:0.6; cursor:not-allowed; }

/* Tables */
.tn-table { width:100%; border-collapse:collapse; font-size:13px; }
.tn-table th { background:#f7fafc; padding:8px 10px; text-align:left; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0; }
.tn-table td { padding:8px 10px; border-bottom:1px solid #f0f4f8; color:#4a5568; }
/* Muted cells use inline color:#a0aec0 (~2.3:1 on white); darken to #64748b (~4.8:1) in light mode for WCAG AA. */
.tn-table [style*="color:#a0aec0"], .tn-table [style*="color:#718096"] { color:#64748b !important; }
.tn-table tr:last-child td { border-bottom:none; }
/* DataTables-managed .tn-table: keep the tn look over jquery.dataTables.css defaults */
table.dataTable.tn-table { border-collapse:collapse; }
table.dataTable.tn-table > thead > tr > th { padding:8px 10px; border-bottom:2px solid #e2e8f0; }
table.dataTable.tn-table > thead > tr > th.sorting,
table.dataTable.tn-table > thead > tr > th.sorting_asc,
table.dataTable.tn-table > thead > tr > th.sorting_desc { padding-right:26px; }
table.dataTable.tn-table.no-footer { border-bottom:none; }
/* Tie-group gap between rank groups (only while the table is in rank order) */
table.dataTable.tn-rank-ordered > tbody > tr.tn-tie-gap > td { border-top:6px solid transparent; }
/* Participants roster (registration surface) */
.tn-roster-bar { display:flex; justify-content:flex-end; margin-bottom:10px; }
.tn-roster-bar-split { justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.tn-subtabs { display:inline-flex; gap:2px; background:#edf2f7; border:1px solid #e2e8f0; border-radius:8px; padding:2px; }
.tn-subtab { border:none; background:transparent; color:#718096; font-size:12px; font-weight:600; padding:5px 14px; border-radius:6px; cursor:pointer; transition:background .15s,color .15s; }
.tn-subtab:hover { color:#2d3748; }
.tn-subtab-active { background:#fff; color:#276749; box-shadow:0 1px 2px rgba(0,0,0,0.08); }
.tn-roster-actions { display:inline-flex; gap:6px; align-items:center; }
.tn-team-chip { display:inline-block; background:#ebf8ff; color:#2b6cb0; border:1px solid #bee3f8; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:600; margin:0 4px 4px 0; }
.tn-reg-chip { display:inline-block; background:#ebf8ff; color:#2b6cb0; border:1px solid #bee3f8; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:600; margin:0 4px 4px 0; }
.tn-reg-unassigned { color:#64748b; font-size:12px; font-style:italic; }
.tn-reg-withdrawn td { opacity:0.55; }
.tn-reg-wd-badge { display:inline-block; background:#fed7d7; color:#9b2c2c; border-radius:8px; padding:0 7px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; vertical-align:middle; }
/* Per-registrant row action buttons */
.tn-reg-actions { display:flex; gap:4px; justify-content:flex-end; align-items:center; }
.tn-reg-act-btn { background:#fff; border:1px solid #e2e8f0; color:#4a5568; border-radius:6px; width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:12px; padding:0; transition:background .15s,border-color .15s,color .15s; }
.tn-reg-act-btn:hover { background:#f7fafc; border-color:#cbd5e0; color:#2d3748; }
.tn-reg-act-btn:disabled { opacity:0.5; cursor:default; }
.tn-reg-act-btn.tn-reg-act-danger { color:#e53e3e; border-color:#fed7d7; }
.tn-reg-act-btn.tn-reg-act-danger:hover { background:#e53e3e; border-color:#e53e3e; color:#fff; }
.tn-reg-act-btn.tn-reg-act-wd { color:#dd6b20; border-color:#feebc8; }
.tn-reg-act-btn.tn-reg-act-wd:hover { background:#dd6b20; border-color:#dd6b20; color:#fff; }
.tn-reg-act-btn.tn-reg-act-reactivate { color:#276749; border-color:#c6f6d5; }
.tn-reg-act-btn.tn-reg-act-reactivate:hover { background:#276749; border-color:#276749; color:#fff; }
/* Assign-to-brackets modal checkbox list */
.tn-assign-list { display:flex; flex-direction:column; gap:2px; max-height:340px; overflow-y:auto; }
.tn-assign-row { display:flex; align-items:center; gap:10px; padding:9px 10px; border:1px solid #edf2f7; border-radius:8px; background:#fff; }
.tn-assign-row.tn-assign-disabled { opacity:0.6; background:#f7fafc; }
.tn-assign-row input[type=checkbox] { width:16px; height:16px; flex-shrink:0; accent-color:#276749; cursor:pointer; }
.tn-assign-row.tn-assign-disabled input[type=checkbox] { cursor:default; }
.tn-assign-row label { flex:1; font-size:13px; font-weight:600; color:#2d3748; cursor:pointer; margin:0; text-transform:none; letter-spacing:0; }
.tn-assign-row.tn-assign-disabled label { cursor:default; }
.tn-assign-meta { font-size:11px; font-weight:600; color:#a0aec0; text-transform:uppercase; letter-spacing:0.4px; }
.tn-assign-meta.tn-assign-meta-locked { color:#c05621; }
.tn-assign-empty { color:#a0aec0; font-size:13px; font-style:italic; padding:8px 2px; }
.tn-btn.tn-team-act-danger { color:#e53e3e; border-color:#fed7d7; }
.tn-btn.tn-team-act-danger:hover { background:#e53e3e; border-color:#e53e3e; color:#fff; }
/* Assign Participants (bulk) modal */
.tn-assignparts-filter { flex:1; min-width:0; padding:7px 10px; border:1px solid #cbd5e0; border-radius:6px; font-size:13px; color:#2d3748; background:#fff; }
.tn-assignparts-filter::placeholder { color:#a0aec0; }
.tn-assignparts-filter:focus { outline:none; border-color:#276749; box-shadow:0 0 0 2px rgba(39,103,73,0.15); }
.tn-assignparts-list { display:flex; flex-direction:column; gap:2px; max-height:360px; overflow-y:auto; }
.tn-assignparts-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border:1px solid #edf2f7; border-radius:8px; background:#fff; cursor:pointer; }
.tn-assignparts-row:hover { background:#f7fafc; }
.tn-assignparts-row.tn-assignparts-hidden { display:none; }
.tn-assignparts-row input[type=checkbox] { width:16px; height:16px; flex-shrink:0; accent-color:#276749; cursor:pointer; }
.tn-assignparts-main { flex:1; min-width:0; display:flex; flex-direction:column; gap:2px; }
.tn-assignparts-alias { font-size:13px; font-weight:600; color:#2d3748; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.tn-assignparts-sub { font-size:11px; color:#718096; }
.tn-assignparts-row.tn-assignparts-wd { opacity:0.6; }
.tn-assignparts-row.tn-assignparts-wd .tn-assignparts-alias { text-decoration:line-through; color:#d69e2e; }
.tn-assignparts-empty { color:#a0aec0; font-size:13px; font-style:italic; padding:12px 4px; text-align:center; }
.tn-assignparts-empty a { color:#276749; font-weight:600; cursor:pointer; text-decoration:underline; font-style:normal; }

/* Modals */
/* Closed overlays are visibility:hidden so their controls leave the Tab order;
   the visibility flip is delayed on close so the opacity fade-out still plays. */
.tn-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:1100; opacity:0; pointer-events:none; visibility:hidden; transition:opacity 0.2s, visibility 0s linear 0.2s; }
.tn-overlay.tn-open { opacity:1; pointer-events:auto; visibility:visible; transition:opacity 0.2s, visibility 0s; }
.tn-overlay .tn-modal-box { background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.3); max-height:90vh; display:flex; flex-direction:column; transform:translateY(8px); transition:transform 0.2s, opacity 0.2s; opacity:0; }
.tn-overlay.tn-open .tn-modal-box { transform:translateY(0); opacity:1; }
.tn-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
.tn-modal-title { font-size:16px; font-weight:700; color:#1a202c; margin:0; background:transparent!important; border:none!important; padding:0!important; border-radius:0!important; text-shadow:none!important; }
.tn-modal-close { background:none; border:none; font-size:22px; color:#a0aec0; cursor:pointer; line-height:1; padding:0 4px; }
.tn-modal-close:hover { color:#4a5568; }
.tn-modal-body { padding:20px; overflow-y:auto; flex:1; }
/* Override the global orkui.css p { text-align:justify } inside tn modals. */
.tn-modal-body p { text-align:start; }
.tn-modal-footer { padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-shrink:0; }
.tn-field { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
.tn-field label { font-size:12px; font-weight:700; color:#4a5568; text-transform:uppercase; letter-spacing:0.4px; }
.tn-field input, .tn-field select, .tn-field textarea { width:100%; padding:8px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; color:#1a202c; box-sizing:border-box; }
.tn-field input:focus, .tn-field select:focus, .tn-field textarea:focus { outline:none; border-color:#276749; box-shadow:0 0 0 2px rgba(39,103,73,0.12); }
.tn-feedback { font-size:13px; font-weight:600; margin-bottom:12px; display:none; }
.tn-feedback-err { color:#c53030; }
.tn-confirm-warn { margin:12px 0 0; color:#c53030; font-weight:600; }
.tn-feedback-ok  { color:#276749; }
.tn-field-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.tn-seg { display:flex; gap:6px; flex-wrap:wrap; }
.tn-seg-btn { flex:1 1 0; min-width:80px; padding:8px 10px; font-size:12px; font-weight:600; line-height:1.2; text-align:center; border:1px solid #e2e8f0; border-radius:6px; background:#fff; color:#2d3748; cursor:pointer; transition:border-color .15s,background .15s,color .15s; }
.tn-seg-btn:hover { border-color:#276749; }
.tn-seg-btn.tn-seg-active { background:#276749; border-color:#276749; color:#fff; }
.tn-field-hint { font-size:11px; color:#718096; margin-top:6px; }

/* Bracket visualization */
.tn-bv-viewport { overflow-x:auto; -webkit-overflow-scrolling:touch; min-width:0; max-width:100%; }
.tn-bv-wrap { padding-bottom:8px; }
.tn-bv-tree { display:flex; gap:0; align-items:stretch; min-width:max-content; position:relative; }
.tn-bv-round { display:flex; flex-direction:column; min-width:190px; padding:0 14px; }
.tn-bv-round-body { display:flex; flex-direction:column; justify-content:space-around; flex:1; }
.tn-bv-round-label { font-size:11px; font-weight:700; color:#a0aec0; text-transform:uppercase; letter-spacing:0.5px; text-align:center; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #e2e8f0; }
.tn-bv-match { border:1px solid #e2e8f0; border-radius:7px; overflow:hidden; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin:6px 0; position:relative; z-index:1; }
.tn-bv-match.tn-bv-clickable { cursor:pointer; border-color:#276749; }
.tn-bv-match.tn-bv-clickable:hover { box-shadow:0 2px 8px rgba(39,103,73,0.18); background:#f0fff4; }
.tn-bv-hit:focus-visible { outline:2px solid #3182ce; outline-offset:-2px; }
.tn-bv-match.tn-bv-resolved { border-color:#c6f6d5; background:#f0fff4; }
.tn-bv-slot { display:flex; align-items:center; gap:6px; padding:6px 10px; font-size:13px; min-height:32px; box-sizing:border-box; }
.tn-bv-slot:first-child { border-bottom:1px solid #e2e8f0; }
.tn-bv-slot.tn-bv-winner { font-weight:700; color:#276749; }
.tn-bv-slot.tn-bv-loser  { color:#a0aec0; text-decoration:line-through; }
.tn-bv-slot.tn-bv-bye    { color:#cbd5e0; font-style:italic; font-size:12px; }
.tn-mobile .tn-bv-slot { min-height:28px; padding:4px 8px; }
.tn-bv-seed { width:18px; height:18px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:9px; font-weight:700; color:#718096; flex-shrink:0; }
.tn-bv-result-pill { font-size:10px; font-weight:700; padding:1px 6px; border-radius:10px; background:#c6f6d5; color:#276749; margin-left:auto; flex-shrink:0; }
.tn-bv-reset-btn { position:absolute; top:4px; right:4px; background:none; border:none; padding:3px 5px; border-radius:4px; color:#cbd5e0; cursor:pointer; font-size:13px; line-height:1; opacity:0; transition:opacity .15s,color .15s,background .15s; }
.tn-bv-match:hover .tn-bv-reset-btn { opacity:1; }
.tn-bv-reset-btn:hover { color:#e53e3e; background:#fff5f5; }
.tn-bv-reset-btn.tn-bv-reset-confirm { opacity:1; color:#e53e3e; background:#fff5f5; font-weight:700; font-size:10px; border:1px solid #e53e3e; padding:2px 5px; border-radius:4px; white-space:nowrap; }
.tn-bv-reset-btn:disabled { opacity:.3; cursor:not-allowed; }
/* The card (overflow:hidden) and the scroll viewport clip a CSS bubble here, so the reset tip renders through the body-level .tn-bv-tooltip instead. */
.tn-bv-reset-btn[data-tip]::after, .tn-bv-reset-btn[data-tip]::before { display:none; }
/* ── Ironman / King of the Hill tap-to-win view ── */
.tn-im-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px; }
.tn-im-fight-num { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:#718096; }
.tn-im-king-badge { display:flex; align-items:center; gap:6px; background:#f0fff4; border:1px solid #9ae6b4; border-radius:20px; padding:5px 12px; }
.tn-im-king-badge-crown { color:#d69e2e; font-size:13px; }
.tn-im-king-badge-label { font-size:10px; font-weight:700; text-transform:uppercase; color:#276749; letter-spacing:0.5px; }
.tn-im-king-badge-name { font-size:13px; font-weight:700; color:#1a202c; }
.tn-im-king-badge-streak { font-size:11px; font-weight:700; color:#276749; background:#c6f6d5; border-radius:10px; padding:1px 6px; }
.tn-im-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:10px; margin-bottom:16px; }
.tn-im-card { border:2px solid #e2e8f0; border-radius:10px; padding:12px 8px 10px; background:#fff; text-align:center; position:relative; transition:border-color .15s,background .15s,transform .1s,box-shadow .15s; }
.tn-im-card.tn-im-card-king { border-color:#3182ce; background:#ebf8ff; } /* border-color overridden per-ring by JS */
.tn-im-card.tn-im-card-btn { cursor:pointer; }
.tn-im-card.tn-im-card-btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
.tn-im-card.tn-im-card-btn:active { transform:translateY(0); }
.tn-im-card.tn-im-card-btn.tn-im-card-king:hover { background:#bee3f8; }
.tn-im-card-crown { position:absolute; top:5px; right:7px; color:#d69e2e; font-size:11px; }
.tn-im-avatar { width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; color:#fff; margin:0 auto 8px; }
.tn-im-card-name { font-size:11px; font-weight:700; color:#1a202c; line-height:1.2; word-break:break-word; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.2px; }
.tn-im-card-wins { font-size:11px; color:#718096; }
.tn-im-card-wins i { font-size:10px; margin-right:2px; }
.tn-im-card-streak { font-size:11px; color:#dd6b20; font-weight:700; }
.tn-im-card-streak i { font-size:10px; margin-right:2px; }
.tn-im-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#718096; margin-bottom:8px; }
.tn-im-history { margin-top:4px; }
.tn-im-history-row { display:flex; justify-content:space-between; align-items:center; padding:5px 8px; border-radius:4px; font-size:12px; }
.tn-im-history-row:nth-child(odd) { background:#f7fafc; }
.tn-im-history-fight { color:#a0aec0; font-size:10px; font-weight:700; margin-right:8px; }
.tn-im-history-winner { font-weight:700; color:#276749; }
.tn-im-history-loser { color:#a0aec0; text-decoration:line-through; }
.tn-im-history-expand { padding:4px 8px; font-size:11px; color:#3182ce; cursor:pointer; font-weight:600; }
.tn-im-history-expand:hover { text-decoration:underline; }
.tn-im-qe-wrap { display:flex; align-items:center; gap:8px; background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; padding:6px 12px; }
.tn-im-qe-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#718096; white-space:nowrap; }
.tn-im-qe-input { width:80px; padding:6px 10px; border:2px solid #e2e8f0; border-radius:6px; font-size:18px; font-weight:700; text-align:center; outline:none; transition:border-color .15s; }
.tn-im-qe-input:focus { border-color:#3182ce; }
.tn-im-qe-status { font-size:12px; flex:1; color:#718096; }
.tn-im-qe-status.ok { color:#276749; font-weight:600; }
.tn-im-qe-status.err { color:#e53e3e; font-weight:600; }
.tn-im-timer-bar { display:flex; align-items:center; gap:10px; margin-bottom:14px; background:#1a202c; border-radius:10px; padding:10px 16px; flex-wrap:wrap; }
.tn-im-timer-display { font-size:28px; font-weight:800; font-variant-numeric:tabular-nums; letter-spacing:1px; color:#fff; min-width:90px; }
.tn-im-timer-display.running { color:#68d391; }
.tn-im-timer-display.expired { color:#fc8181; }
.tn-im-timer-display.grace { color:#ed8936; animation:tn-grace-pulse 1s ease-in-out infinite; }
.tn-im-timer-bar.warning { animation:tn-bar-warn 1.2s ease-in-out infinite; }
@keyframes tn-bar-warn { 0%,100% { box-shadow:none; } 50% { box-shadow:0 0 0 4px rgba(229,62,62,0.35); } }
@keyframes tn-grace-pulse { 0%,100% { opacity:1; } 50% { opacity:.55; } }
.tn-im-timer-btn { padding:6px 16px; border-radius:6px; font-size:13px; font-weight:700; border:none; cursor:pointer; transition:background .15s; }
.tn-im-timer-btn.start { background:#276749; color:#fff; }
.tn-im-timer-btn.start:hover { background:#22543d; }
.tn-im-timer-btn.add { background:#2d3748; color:#a0aec0; }
.tn-im-timer-btn.add:hover { background:#4a5568; color:#fff; }
.tn-im-timer-btn.end { background:transparent; color:#fc8181; border:1px solid #fc8181; }
.tn-im-timer-btn.end:hover { background:#fc8181; color:#fff; }
.tn-im-timer-btn.pause { background:#2d3748; color:#ecc94b; border:1px solid #4a5568; }
.tn-im-timer-btn.pause:hover { background:#4a5568; color:#fefcbf; }
.tn-im-timer-btn.standings { background:#3182ce; color:#fff; margin-left:auto; }
.tn-im-timer-btn.standings:hover { background:#2c5282; }
.tn-im-timer-display.paused { color:#ecc94b; }
.tn-im-timer-locked { font-size:11px; color:#a0aec0; margin-left:auto; }
.tn-im-rings-wrap { display:flex; flex-direction:column; gap:18px; }
.tn-im-ring { border:3px solid #e2e8f0; border-radius:12px; padding:14px 14px 10px; background:#fff; }
.tn-im-ring-header { display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
.tn-im-card-blocked { opacity:0.38; cursor:not-allowed !important; pointer-events:none; }

.tn-bk-pills { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
.tn-bk-pill { padding:5px 14px; border-radius:20px; font-size:12px; font-weight:600; border:1px solid #e2e8f0; background:#fff; color:#718096; cursor:pointer; transition:background .15s,border-color .15s,color .15s; white-space:nowrap; }
.tn-bk-pill:hover { border-color:#276749; color:#276749; background:#f0fff4; }
.tn-bk-pill.tn-bk-pill-active { background:#276749; border-color:#276749; color:#fff; }
.tn-rr-round-body { display:flex; flex-wrap:wrap; gap:10px; padding:4px 0; }
.tn-rr-round-body .tn-bv-match { min-width:260px; flex:1 1 260px; margin:0; }
.tn-bv-round-nav { display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
.tn-bv-round-btn { padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; border:1px solid #e2e8f0; background:#fff; color:#718096; cursor:pointer; }
.tn-bv-round-btn.active { background:#276749; color:#fff; border-color:#276749; }
.tn-bv-round-btn.tn-rr-complete:not(.active) { background:#3182ce; color:#fff; border-color:#2b6cb0; }
.tn-bv-round-btn.tn-bv-round-btn-tb:not(.active) { background:#fffaf0; color:#c05621; border-color:#fbd38d; }
.tn-bv-round-btn.tn-bv-round-btn-tb.active { background:#dd6b20; color:#fff; border-color:#dd6b20; }
@keyframes tn-pill-pulse { 0%,100% { box-shadow:0 0 0 0 rgba(49,130,206,0); } 50% { box-shadow:0 0 0 5px rgba(49,130,206,0.38); } }
.tn-bv-round-btn.tn-rr-next-pulse { animation:tn-pill-pulse 1.4s ease-in-out 3; }
.tn-bv-section-label { font-size:11px; font-weight:700; color:#718096; text-transform:uppercase; letter-spacing:0.5px; margin:14px 0 8px; padding-bottom:4px; border-bottom:1px solid #e2e8f0; }
.tn-bv-generate-bar { display:flex; align-items:center; gap:10px; padding:12px 14px; background:#f7fafc; border:1px dashed #e2e8f0; border-radius:8px; margin-bottom:14px; }
.tn-bv-status-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; }
.tn-bv-status-setup    { background:#e2e8f0; color:#718096; }
.tn-bv-status-active   { background:#bee3f8; color:#2b6cb0; }
.tn-bv-status-complete  { background:#c6f6d5; color:#276749; }
.tn-bv-status-finalized { background:#fefcbf; color:#744210; }
.tn-bv-empty { color:#a0aec0; font-size:13px; font-style:italic; padding:16px 0; text-align:center; }
.tn-bv-section-hdr { display:flex; align-items:center; gap:7px; padding:5px 12px; border-radius:5px; margin:14px 0 6px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
.tn-bv-section-hdr.winners { border-left:3px solid #276749; background:#f0fff4; color:#276749; }
.tn-bv-section-hdr.losers { border-left:3px solid #d97706; background:#fffbeb; color:#b7791f; }
.tn-bv-section-hdr.grand-final { border-left:3px solid #6b46c1; background:#faf5ff; color:#6b46c1; }
.tn-gf-confirm-banner { background:#fefcbf; border:1px solid #ecc94b; border-radius:7px; padding:12px 16px; margin:0 0 12px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.tn-gf-confirm-text { color:#744210; font-size:13px; display:flex; align-items:center; gap:7px; flex:1; }
.tn-gf-confirm-btns { display:flex; flex-direction:column; gap:6px; flex-shrink:0; }
.tn-gf-inline-btns { display:flex; flex-direction:column; gap:6px; justify-content:center; margin-left:10px; }
.tn-gf-confirm-yes { background:#38a169; color:#fff; border:none; border-radius:5px; padding:6px 13px; cursor:pointer; font-size:13px; font-weight:600; display:flex; align-items:center; gap:5px; }
.tn-gf-confirm-yes:hover { background:#2f855a; }
.tn-gf-confirm-no { background:#e53e3e; color:#fff; border:none; border-radius:5px; padding:6px 13px; cursor:pointer; font-size:13px; font-weight:600; display:flex; align-items:center; gap:5px; }
.tn-gf-confirm-no:hover { background:#c53030; }
.tn-bv-progress-info { font-size:11px; color:#a0aec0; }
.tn-bv-progress-info .tn-bv-pi-ready { color:#276749; font-weight:700; }
.tn-bv-bout-row { display:flex; align-items:center; gap:4px; padding:4px 10px 5px; justify-content:center; border-top:1px solid #e2e8f0; background:#f7fafc; }
.tn-bv-bout-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.tn-bv-bout-dot.tn-bd-1 { background:#276749; }
.tn-bv-bout-dot.tn-bd-2 { background:#e53e3e; }
.tn-bv-tbd-label { font-size:10px; color:#a0aec0; font-style:italic; }
.tn-rr-standings { width:100%; border-collapse:collapse; font-size:12px; margin-top:18px; }
.tn-rr-standings caption { font-size:11px; font-weight:700; color:#718096; text-transform:uppercase; letter-spacing:0.4px; text-align:left; padding:0 0 6px; caption-side:top; }
.tn-rr-standings th { background:#f7fafc; padding:5px 10px; font-size:11px; font-weight:700; color:#718096; text-transform:uppercase; letter-spacing:0.4px; border-bottom:2px solid #e2e8f0; text-align:center; }
.tn-rr-standings th:nth-child(2) { text-align:left; }
.tn-rr-standings td { padding:6px 10px; border-bottom:1px solid #f0f4f8; color:#4a5568; text-align:center; }
.tn-rr-standings td:nth-child(2) { font-weight:600; color:#2d3748; text-align:left; }
.tn-rr-standings tr:last-child td { border-bottom:none; }
.tn-rr-standings .tn-rr-std-top td { background:#f0fff4; }

/* ================================================================
   ROUND ROBIN ENHANCEMENTS
   Prefix: tn-rr-  (round-robin specific additions)
   ================================================================ */

/* ── View Toggle (Rounds / Matrix) ── */
.tn-rr-view-toggle {
	display:inline-flex;
	border-radius:20px;
	overflow:hidden;
	border:1px solid #e2e8f0;
	background:#fff;
}
.tn-rr-view-toggle-btn {
	padding:4px 14px;
	font-size:12px;
	font-weight:600;
	color:#718096;
	background:#fff;
	border:none;
	cursor:pointer;
	transition:background .15s, color .15s;
	white-space:nowrap;
}
.tn-rr-view-toggle-btn:first-child { border-right:1px solid #e2e8f0; }
.tn-rr-view-toggle-btn:hover:not(.active) { background:#f7fafc; color:#4a5568; }
.tn-rr-view-toggle-btn.active { background:#276749; color:#fff; }

/* ── Cross-Table Matrix View ── */
.tn-rr-matrix-wrap {
	overflow-x:auto;
	-webkit-overflow-scrolling:touch;
	margin-top:12px;
	border:1px solid #e2e8f0;
	border-radius:8px;
	background:#fff;
}
.tn-rr-matrix {
	width:100%;
	border-collapse:separate;
	border-spacing:0;
	font-size:12px;
	min-width:400px;
}
.tn-rr-matrix th,
.tn-rr-matrix td {
	padding:6px 10px;
	text-align:center;
	border-bottom:1px solid #f0f4f8;
	border-right:1px solid #f0f4f8;
}
.tn-rr-matrix th:last-child,
.tn-rr-matrix td:last-child { border-right:none; }
.tn-rr-matrix tr:last-child td { border-bottom:none; }
.tn-rr-matrix thead th {
	position:sticky;
	top:0;
	z-index:3;
	background:#f7fafc;
	font-size:11px;
	font-weight:700;
	color:#718096;
	text-transform:uppercase;
	letter-spacing:0.3px;
	border-bottom:2px solid #e2e8f0;
	white-space:nowrap;
	max-width:80px;
	overflow:hidden;
	text-overflow:ellipsis;
}
.tn-rr-mx-player-col {
	position:sticky;
	left:0;
	z-index:2;
	background:#f7fafc;
	font-weight:600;
	color:#2d3748;
	text-align:left !important;
	white-space:nowrap;
	min-width:120px;
	border-right:2px solid #e2e8f0 !important;
}
.tn-rr-matrix thead th:first-child {
	position:sticky;
	left:0;
	z-index:4;
	border-right:2px solid #e2e8f0;
}
.tn-rr-mx-win { background:#f0fff4; color:#276749; font-weight:700; }
.tn-rr-mx-loss { background:#fff5f5; color:#e53e3e; font-weight:600; }
.tn-rr-mx-tie { background:#fefcbf; color:#744210; font-weight:600; }
.tn-rr-mx-self {
	background:#edf2f7; color:#cbd5e0; cursor:default; position:relative;
}
.tn-rr-mx-self::after {
	content:'';
	position:absolute;
	top:0; left:0; right:0; bottom:0;
	background:repeating-linear-gradient(-45deg, transparent, transparent 3px, rgba(0,0,0,0.04) 3px, rgba(0,0,0,0.04) 6px);
	pointer-events:none;
}
.tn-rr-mx-pending { color:#a0aec0; font-style:italic; }
.tn-rr-mx-cell-clickable { cursor:pointer; transition:background .12s, box-shadow .12s; }
.tn-rr-mx-cell-clickable:hover { box-shadow:inset 0 0 0 2px #276749; background:#f0fff4; }
.tn-rr-mx-avatar {
	width:20px; height:20px; border-radius:50%;
	display:inline-flex; align-items:center; justify-content:center;
	font-size:9px; font-weight:800; color:#fff; flex-shrink:0;
	vertical-align:middle; margin-right:4px;
}
.tn-rr-mx-avatar-sm { width:16px; height:16px; font-size:7px; margin-right:2px; }
.tn-rr-mx-col-header { vertical-align:bottom; padding:8px 6px !important; }
.tn-rr-mx-col-name { display:block; margin-top:3px; font-size:10px; }
.tn-rr-mx-player-name { vertical-align:middle; }
.tn-rr-mx-result { display:block; font-size:13px; line-height:1.2; }
.tn-rr-mx-bouts { display:block; font-size:9px; color:inherit; opacity:0.65; line-height:1; margin-top:1px; }
.tn-rr-mx-corner { min-width:120px; }

/* ── Enhanced Standings Table ── */
.tn-rr-standings-enhanced { width:100%; border-collapse:collapse; font-size:12px; margin-top:18px; }
.tn-rr-standings-enhanced caption {
	font-size:11px; font-weight:700; color:#718096;
	text-transform:uppercase; letter-spacing:0.4px;
	text-align:left; padding:0 0 6px; caption-side:top;
}
.tn-rr-standings-enhanced th {
	background:#f7fafc; padding:6px 10px; font-size:11px; font-weight:700;
	color:#718096; text-transform:uppercase; letter-spacing:0.4px;
	border-bottom:2px solid #e2e8f0; text-align:center;
}
.tn-rr-standings-enhanced td {
	padding:7px 10px; border-bottom:1px solid #f0f4f8;
	color:#4a5568; text-align:center; vertical-align:middle;
}
.tn-rr-standings-enhanced tr:last-child td { border-bottom:none; }
.tn-rr-std-col-player { text-align:left !important; }
.tn-rr-std-col-rank { width:44px; }
.tn-rr-std-col-progress { min-width:90px; }
.tn-rr-std-rank { text-align:center; }
.tn-rr-std-medal { font-size:16px; line-height:1; }
.tn-rr-std-player { text-align:left !important; }
.tn-rr-std-player-wrap { display:flex; align-items:center; gap:8px; }
.tn-rr-std-avatar {
	width:24px; height:24px; border-radius:50%;
	display:flex; align-items:center; justify-content:center;
	font-size:9px; font-weight:800; color:#fff; flex-shrink:0;
}
.tn-rr-std-name-wrap { display:flex; flex-direction:column; gap:1px; }
.tn-rr-std-name { font-weight:600; color:#2d3748; font-size:12px; }
.tn-rr-std-park { font-size:10px; color:#a0aec0; display:block; }
.tn-rr-std-w { color:#276749; font-weight:700; }
.tn-rr-std-l { color:#e53e3e; font-weight:600; }
.tn-rr-std-t { color:#744210; font-weight:600; }
.tn-rr-std-pts { font-weight:800; color:#1a202c; font-size:14px; }
.tn-rr-std-winpct { font-weight:700; color:#276749; font-size:11px; }
.tn-rr-std-bar {
	display:inline-flex; align-items:center; gap:6px;
}
.tn-rr-std-bar-track {
	width:50px; height:8px; background:#e2e8f0;
	border-radius:4px; overflow:hidden; flex-shrink:0;
}
.tn-rr-std-bar-fill {
	height:100%; border-radius:4px;
	background:linear-gradient(90deg, #38a169, #276749);
	transition:width .3s ease;
}
.tn-rr-std-bar-text { font-size:10px; color:#718096; font-weight:600; white-space:nowrap; }
.tn-rr-std-caption-progress { font-size:10px; color:#a0aec0; margin-left:8px; font-weight:400; }
.tn-rr-std-active td { background:#ebf8ff !important; box-shadow:inset 3px 0 0 #3182ce; }
.tn-rr-std-clickable tr { cursor:pointer; transition:background .12s; }
.tn-rr-std-clickable tr:hover td { background:#f7fafc; }

/* ── Overall Progress Bar ── */
.tn-rr-progress-wrap { margin:12px 0; }
.tn-rr-progress-bar {
	position:relative; width:100%; height:22px;
	background:#e2e8f0; border-radius:11px; overflow:hidden;
	box-shadow:inset 0 1px 2px rgba(0,0,0,0.06);
}
.tn-rr-progress-fill {
	height:100%; border-radius:11px;
	background:linear-gradient(90deg, #38a169 0%, #276749 100%);
	transition:width .4s ease; min-width:0;
}
.tn-rr-progress-label {
	position:absolute; top:0; left:0; right:0; bottom:0;
	display:flex; align-items:center; justify-content:center;
	font-size:11px; font-weight:700; color:#fff;
	text-shadow:0 1px 2px rgba(0,0,0,0.2); pointer-events:none;
}
.tn-rr-progress-bar.tn-rr-progress-low .tn-rr-progress-label {
	color:#4a5568; text-shadow:none;
}
.tn-rr-round-count {
	display:inline-block; font-size:9px; font-weight:700; color:#a0aec0;
	background:#f7fafc; border:1px solid #e2e8f0;
	padding:0 5px; border-radius:8px; margin-left:4px;
	vertical-align:middle; line-height:16px;
}
.tn-bv-round-btn.active .tn-rr-round-count {
	color:rgba(255,255,255,0.8); background:rgba(255,255,255,0.15); border-color:rgba(255,255,255,0.25);
}
.tn-bv-round-btn.tn-rr-complete:not(.active) .tn-rr-round-count {
	color:rgba(255,255,255,0.8); background:rgba(255,255,255,0.15); border-color:rgba(255,255,255,0.25);
}

/* ── Champion Banner sub-elements ── */
.tn-bv-champion-row { display:flex; align-items:center; gap:12px; }
.tn-bv-champion-trophy { font-size:32px; flex-shrink:0; }
.tn-bv-podium-rank { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; }
.tn-bv-podium-avatar {
	width:28px; height:28px; border-radius:50%;
	display:flex; align-items:center; justify-content:center;
	font-size:10px; font-weight:800; color:#fff; margin:4px auto;
}
.tn-bv-podium-name { font-size:12px; font-weight:700; color:#1a202c; text-align:center; }
.tn-bv-podium-park { font-size:10px; color:#718096; text-align:center; }
.tn-bv-podium-stats { font-size:10px; color:#276749; font-weight:600; text-align:center; margin-top:2px; }

/* ── Match Card Record Badge ── */
.tn-rr-card-record {
	font-size:9px; font-weight:600; color:#a0aec0;
	white-space:nowrap; flex-shrink:0; margin-left:4px;
}

/* ── Player Focus Mode ── */
.tn-rr-focus-active .tn-bv-match:not(.tn-rr-focus-match) {
	opacity:0.3; filter:grayscale(0.5); transition:opacity .2s, filter .2s;
}
.tn-rr-focus-active .tn-rr-focus-match {
	border-color:#3182ce;
	box-shadow:0 0 0 2px rgba(49,130,206,0.25), 0 2px 8px rgba(49,130,206,0.15);
	transition:border-color .2s, box-shadow .2s;
}
.tn-rr-focus-active .tn-rr-matrix tr:not(.tn-rr-focus-row) td:not(.tn-rr-mx-player-col) { opacity:0.3; }
.tn-rr-focus-active .tn-rr-standings-enhanced tbody tr:not(.tn-rr-std-active) { opacity:0.45; }
.tn-rr-focus-banner {
	display:flex; align-items:center; gap:8px;
	padding:8px 14px; background:#ebf8ff; border:1px solid #bee3f8;
	border-radius:8px; margin-bottom:12px;
	font-size:12px; color:#2b6cb0; font-weight:600;
}
.tn-rr-focus-banner-name { font-weight:800; color:#1a202c; }
.tn-rr-focus-banner-close {
	margin-left:auto; background:none; border:none;
	font-size:16px; color:#3182ce; cursor:pointer;
	padding:2px 6px; border-radius:4px; line-height:1;
	transition:background .12s, color .12s;
}
.tn-rr-focus-banner-close:hover { background:#bee3f8; color:#2b6cb0; }

/* ── Mobile Overrides ── */
@media (max-width: 768px) {
	.tn-rr-matrix th, .tn-rr-matrix td { padding:4px 6px; font-size:11px; }
	.tn-rr-mx-player-col { min-width:90px; font-size:11px; }
	.tn-rr-standings-enhanced th, .tn-rr-standings-enhanced td { padding:5px 6px; font-size:11px; }
	.tn-rr-std-bar-track { width:40px; }
	.tn-rr-focus-banner { flex-wrap:wrap; font-size:11px; }
	.tn-rr-progress-bar { height:18px; }
	.tn-rr-progress-label { font-size:10px; }
}
@media (max-width: 480px) {
	.tn-rr-matrix-wrap { position:relative; }
	.tn-rr-matrix-wrap::after {
		content:''; position:absolute; top:0; right:0; bottom:0; width:24px;
		background:linear-gradient(90deg, transparent, rgba(255,255,255,0.85));
		pointer-events:none; border-radius:0 8px 8px 0;
	}
	.tn-rr-matrix th, .tn-rr-matrix td { padding:4px 5px; font-size:10px; min-width:36px; }
	.tn-rr-mx-player-col { min-width:70px; font-size:10px; }
	.tn-rr-mx-cell-clickable { min-height:36px; min-width:36px; }
	.tn-rr-std-bar-track { width:32px; height:6px; }
	.tn-rr-std-winpct { font-size:10px; }
	.tn-rr-std-pts { font-size:12px; }
	.tn-rr-view-toggle { width:100%; }
	.tn-rr-view-toggle-btn { flex:1; text-align:center; padding:8px 12px; }
	.tn-rr-progress-bar { height:16px; border-radius:8px; }
	.tn-rr-progress-fill { border-radius:8px; }
	.tn-rr-progress-label { font-size:9px; }
	.tn-rr-focus-banner-close { padding:6px 10px; font-size:18px; }
}


/* -- Bracket Viz Enhancements -- */
.tn-bv-match-num { position:absolute; top:3px; left:6px; font-size:9px; font-weight:700; color:#a0aec0; letter-spacing:0.3px; z-index:2; }
/* Corner badge (e.g. '3rd Place'): reserve a top strip so it never covers slot 1's name / W pill (or the reset button on the right). */
.tn-bv-match.tn-bv-has-badge { padding-top:18px; }
.tn-bv-match-num.tn-bv-tb-badge { line-height:12px; padding:1px 6px; border-radius:3px; background:#dd6b20; color:#fff; }
.tn-bv-match.tn-bv-bye-match { border-style:dashed; border-color:#e2e8f0; background:#fafafa; opacity:0.7; }
.tn-bv-match.tn-bv-bye-match .tn-bv-slot { color:#cbd5e0; }
.tn-bv-bye-label { font-size:9px; color:#a0aec0; text-align:center; padding:2px 0; font-style:italic; border-top:1px dashed #e2e8f0; }
.tn-bv-match.tn-bv-next-playable { animation:tn-next-pulse 2s ease-in-out infinite; }
@keyframes tn-next-pulse { 0%,100% { box-shadow:0 0 0 0 rgba(39,103,73,0); } 50% { box-shadow:0 0 0 4px rgba(39,103,73,0.2); } }
.tn-bv-avatar { width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:9px; font-weight:800; color:#fff; flex-shrink:0; line-height:1; }
.tn-bv-tooltip { position:fixed; background:#1a202c; color:#fff; padding:8px 12px; border-radius:6px; font-size:12px; line-height:1.4; z-index:2000; pointer-events:none; max-width:260px; box-shadow:0 4px 12px rgba(0,0,0,0.25); opacity:0; transition:opacity .15s; }
.tn-bv-tooltip.tn-bv-tooltip-show { opacity:1; }
.tn-bv-tooltip-name { font-weight:700; }
.tn-bv-tooltip-park { color:#a0aec0; font-size:11px; }
.tn-bv-tooltip-bouts { margin-top:4px; font-size:11px; color:#cbd5e0; }
.tn-bv-champion-banner { display:flex; align-items:center; gap:12px; padding:14px 18px; background:linear-gradient(135deg,#f6e05e 0%,#ecc94b 100%); border-radius:8px; margin-bottom:14px; box-shadow:0 2px 8px rgba(236,201,75,0.3); }
.tn-bv-champion-crown { font-size:28px; flex-shrink:0; }
.tn-bv-champion-info { flex:1; }
.tn-bv-champion-label { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:1px; color:#744210; }
.tn-bv-champion-name { font-size:18px; font-weight:800; color:#1a202c; }
.tn-bv-champion-park { font-size:12px; color:#744210; }
.tn-bv-podium { display:flex; gap:8px; flex-wrap:wrap; }
.tn-bv-podium-card { display:flex; align-items:center; gap:8px; padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; }
.tn-bv-podium-1st { background:#fefcbf; color:#744210; border:1px solid #ecc94b; }
.tn-bv-podium-2nd { background:#e2e8f0; color:#4a5568; border:1px solid #cbd5e0; }
.tn-bv-podium-3rd { background:#fed7aa; color:#7b341e; border:1px solid #f6ad55; }
.tn-bv-podium-num { font-size:11px; font-weight:800; }
.tn-bv-zoom-controls { display:flex; align-items:center; gap:6px; margin-bottom:10px; }
.tn-bv-zoom-btn { width:28px; height:28px; border-radius:6px; border:1px solid #e2e8f0; background:#fff; color:#718096; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; transition:background .15s,color .15s; }
.tn-bv-zoom-btn:hover { background:#f0fff4; color:#276749; border-color:#276749; }
.tn-bv-zoom-level { font-size:11px; color:#a0aec0; min-width:36px; text-align:center; }
.tn-bv-section-hdr.tiebreaker-3rd,
.tn-bv-section-hdr.tiebreaker-rr { border-left:3px solid #dd6b20; background:#fffaf0; color:#c05621; }
.tn-bv-losers-compact .tn-bv-round { min-width:160px; padding:0 8px; }
.tn-bv-losers-compact .tn-bv-match { margin:3px 0; }
.tn-bv-losers-compact .tn-bv-slot { padding:4px 8px; font-size:12px; min-height:28px; }
.tn-bv-losers-compact .tn-bv-seed { width:16px; height:16px; font-size:8px; }
.tn-bv-losers-compact .tn-bv-avatar { width:16px; height:16px; font-size:7px; }
.tn-bv-losers-compact .tn-bv-round-label { font-size:10px; margin-bottom:6px; padding-bottom:4px; }

/* Responsive */
@media (max-width: 768px) {
	.tn-layout { flex-direction:column; }
	.tn-sidebar { width:100%; }
	.tn-hero-content { flex-direction:column; gap:12px; }
	.tn-stats-row { gap:8px; }
	.tn-stat-card { min-width:calc(50% - 4px); }
	.tn-field-row { grid-template-columns:1fr; }
	.tn-tab-nav { flex-wrap:wrap; overflow:hidden; overflow:clip; }
	.tn-bv-round { min-width:150px; padding:0 8px; }
	.tn-rr-round-body .tn-bv-match { min-width:100%; flex:1 1 100%; }
	.tn-rr-standings th, .tn-rr-standings td { padding:5px 8px; font-size:12px; }
}
@media (max-width: 480px) {
	/* Hero */
	.tn-hero-icon { width:52px; height:52px; font-size:24px; }
	.tn-stat-value { font-size:clamp(1.1rem, 5vw, 1.6rem); }
	/* Buttons — approach 44px touch target */
	.tn-btn { padding:10px 16px; }
	.tn-btn-sm { padding:7px 12px; }
	/* Ironman grid — smaller min card width */
	.tn-im-grid { grid-template-columns:repeat(auto-fill,minmax(90px,1fr)); }
	.tn-im-avatar { width:44px; height:44px; font-size:14px; }
	.tn-im-qe-wrap { flex-wrap:wrap; }
	.tn-im-qe-input { width:60px; padding:8px 12px; font-size:16px; }
	/* Ring header — stack vertically */
	.tn-im-ring-header { flex-direction:column; align-items:flex-start; gap:10px; }
	/* Timer bar — stack buttons */
	.tn-im-timer-bar { flex-direction:column; align-items:flex-start; gap:8px; }
	.tn-im-timer-display { font-size:22px; min-width:unset; }
	.tn-im-timer-btn { padding:10px 16px; width:100%; text-align:center; }
	/* Bracket viz rounds narrower */
	.tn-bv-round { min-width:110px; }
	/* Bout pips — larger touch target */
	.tn-bout-pip { width:32px; height:32px; font-size:12px; }
	/* Participant remove button */
	.tn-remove-participant { padding:6px 10px; }
	/* Autocomplete dropdown shorter */
	.kn-ac-results { max-height:150px; }
	/* Modal padding tighter on small screens */
	.tn-modal-body { padding:14px; }
	.tn-modal-footer { padding:10px 14px; flex-wrap:wrap; }
	.tn-modal-footer .tn-btn { flex:1 1 auto; text-align:center; }
}
/* Touch devices — show hover-only elements always */
@media (hover:none) {
	.tn-bv-reset-btn { opacity:1 !important; }
	.tn-im-card.tn-im-card-btn:active { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.12); }
	.tn-bout-pip:active { transform:scale(1.15); }
}
/* Landscape mobile — compress modal & hero height */
@media (max-height: 600px) and (orientation:landscape) {
	.tn-modal-body { max-height:70vh; }
	.tn-hero { padding:14px 12px; }
}
/* ── Check-in system ── */

/* ── Participant status menu ── */
.tn-status-wrap { position:relative; flex-shrink:0; }
.tn-status-btn { background:none; border:none; color:#a0aec0; cursor:pointer; padding:2px 5px; font-size:14px; line-height:1; }
.tn-status-btn:hover { color:#4a5568; }
.tn-status-menu { display:none; position:absolute; right:0; top:100%; background:#fff; border:1px solid #e2e8f0; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,.12); z-index:50; min-width:150px; overflow:hidden; }
.tn-status-menu.tn-status-open { display:block; }
.tn-status-menu-item { padding:8px 14px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; border:0; border-bottom:1px solid #f0f4f8; white-space:nowrap; width:100%; background:none; color:inherit; font-family:inherit; text-align:left; }
.tn-status-menu-item:last-child { border-bottom:none; }
.tn-status-menu-item:hover, .tn-status-menu-item:focus { background:#f7fafc; outline:2px solid transparent; outline-offset:-2px; }
.tn-status-menu-item:focus-visible { box-shadow:inset 0 0 0 2px #3182ce; }
.tn-status-menu-item.tn-sm-active { color:#276749; }
.tn-status-menu-item .tn-sm-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.tn-sm-dot-active { background:#38a169; }
.tn-sm-dot-absent { background:#a0aec0; }
.tn-sm-dot-withdrawn { background:#d69e2e; }
.tn-sm-dot-disqualified { background:#e53e3e; }
/* Visual states on participant row */
.tn-participant-list li.tn-pstatus-withdrawn span:not(.tn-participant-seed):not(.tn-pid):not(.tn-status-wrap):not(.tn-status-btn) { text-decoration:line-through; color:#d69e2e; }
.tn-participant-list li.tn-pstatus-disqualified span:not(.tn-participant-seed):not(.tn-pid):not(.tn-status-wrap):not(.tn-status-btn) { text-decoration:line-through; color:#e53e3e; }
.tn-pstatus-pill { font-size:9px; font-weight:700; padding:1px 6px; border-radius:10px; margin-left:4px; text-decoration:none !important; }
.tn-pstatus-pill-withdrawn { background:#fefcbf; color:#b45309; border:1px solid #fcd34d; }
.tn-pstatus-pill-disqualified { background:#fff5f5; color:#e53e3e; border:1px solid #fc8181; }
.tn-match-void-pill { background:#edf2f7; color:#718096; border:1px solid #cbd5e0; }
.tn-match-voided td:not(:nth-child(3)) { text-decoration:line-through; opacity:0.6; }

/* --- G2: participant-list mobile two-line reflow (CSS only — no markup change) --- */
.tn-mobile .tn-participant-list li {
	flex-wrap:wrap;
	row-gap:2px;
	min-height:52px;
	padding:10px 8px;
	font-size:14px;
	align-items:center;
}
/* Line-1 ordering: handle, seed, name-block, then status/remove pushed right */
.tn-mobile .tn-participant-list li > .tn-dnd-handle { order:0; }
.tn-mobile .tn-participant-list li > .tn-participant-seed,
.tn-mobile .tn-participant-list li > .tn-seed-enhanced { order:1; }
.tn-mobile .tn-participant-list li > span[style*="flex:1"] { order:2; flex:1 1 auto; min-width:0; font-size:14px; }
.tn-mobile .tn-participant-list li > .tn-status-wrap { order:8; margin-left:auto; }
.tn-mobile .tn-participant-list li > .tn-remove-participant { order:9; }
/* Park / persona name (the muted 11px direct-child span) drops to its own full-width line 2 */
.tn-mobile .tn-participant-list li > span[style*="font-size:11px"] {
	order:10;
	flex:0 0 100%;
	width:100%;
	margin-left:28px;
	font-size:11px;
}
/* Bigger touch targets for the per-row controls */
.tn-mobile .tn-participant-list li .tn-status-btn {
	min-width:44px;
	min-height:44px;
	display:inline-flex;
	align-items:center;
	justify-content:center;
	font-size:18px;
}
.tn-mobile .tn-participant-list li .tn-remove-participant {
	min-width:36px;
	min-height:44px;
	display:inline-flex;
	align-items:center;
	justify-content:center;
	font-size:18px;
}
/* Keep the absolute desktop status menu out of the mobile flow (action sheet replaces it) */
.tn-mobile .tn-participant-list li .tn-status-menu { display:none !important; }

/* ── Quick Result Entry (inline on bracket viz) ── */
.tn-qr-bar { display:flex; align-items:center; gap:6px; padding:6px 10px; border-top:1px solid #e2e8f0; background:#f7fafc; }
.tn-qr-btn { padding:4px 10px; border-radius:5px; font-size:11px; font-weight:700; border:none; cursor:pointer; transition:background .15s; }
.tn-qr-btn-p1, .tn-qr-btn-p2 { display:inline-flex; align-items:center; min-width:0; max-width:50%; flex:0 1 auto; white-space:nowrap; }
.tn-qr-name { overflow:hidden; text-overflow:ellipsis; min-width:0; }
.tn-qr-sfx { flex-shrink:0; }
#tn-bv-container:has(.tn-qr-bar) [data-tip]::after, #tn-bv-container:has(.tn-qr-bar) [data-tip]::before { display:none; }
.tn-bv-slot .tn-pstatus-pill { flex-shrink:0; margin-left:0; }
.tn-qr-btn-p1 { background:#276749; color:#fff; }
.tn-qr-btn-p1:hover { background:#22543d; }
.tn-qr-btn-p2 { background:#3182ce; color:#fff; }
.tn-qr-btn-p2:hover { background:#2b6cb0; }
.tn-qr-btn-tie { background:#e2e8f0; color:#4a5568; }
.tn-qr-btn-tie:hover { background:#cbd5e0; }
.tn-qr-more { font-size:11px; color:#3182ce; cursor:pointer; text-decoration:none; margin-left:auto; flex-shrink:0; background:none; border:none; padding:0; font-family:inherit; }
.tn-qr-more:hover { text-decoration:underline; }
.tn-bv-match.tn-qr-expanded { border-color:#276749; box-shadow:0 2px 8px rgba(39,103,73,0.18); }

/* ── Bout Score Pill ── */
.tn-bout-score-pill { display:inline-flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; padding:2px 8px; border-radius:10px; background:#c6f6d5; color:#276749; margin-top:2px; }

/* ── Round Status Badge ── */

/* ── Enhanced Seed Display ── */
.tn-seed-enhanced { width:24px; height:24px; background:linear-gradient(135deg,#276749,#38a169); color:#fff; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:800; flex-shrink:0; box-shadow:0 1px 3px rgba(0,0,0,0.12); transition:transform .15s,box-shadow .15s; }
.tn-dnd-list li[draggable='true']:hover .tn-seed-enhanced { transform:scale(1.1); box-shadow:0 2px 6px rgba(39,103,73,0.25); }
.tn-dnd-over .tn-seed-enhanced { background:linear-gradient(135deg,#d69e2e,#ecc94b); }

/* DnD reorder */
.tn-dnd-over { background:#f0fff4!important; outline:2px dashed #276749; border-radius:6px; }
.tn-dnd-handle { color:#cbd5e0; margin-right:4px; cursor:grab; font-size:11px; }
.tn-dnd-list.tn-dnd-error { position:relative; }
.tn-dnd-list.tn-dnd-error::before { content:attr(data-error); display:block; background:#fed7d7; color:#742a2a; padding:6px 10px; border-radius:4px; margin-bottom:6px; font-size:12px; font-weight:600; }
html[data-theme="dark"] .tn-dnd-list.tn-dnd-error::before { background:#742a2a; color:#fed7d7; }
/* Touch reorder (B2): lifted row follows the finger; placeholder marks drop slot. */
.tn-dnd-list li.tn-dnd-lifted { position:fixed; z-index:10000; margin:0; list-style:none; background:#fff; box-shadow:0 8px 24px rgba(0,0,0,0.28); border-radius:8px; transform:scale(1.03); pointer-events:none; transition:none; }
.tn-dnd-list li.tn-dnd-placeholder { list-style:none; background:repeating-linear-gradient(45deg,#f0fff4,#f0fff4 6px,#e6fffa 6px,#e6fffa 12px); border:2px dashed #276749; border-radius:6px; box-sizing:border-box; }
html[data-theme="dark"] .tn-dnd-list li.tn-dnd-lifted { background:#1a202c; box-shadow:0 8px 24px rgba(0,0,0,0.6); }
html[data-theme="dark"] .tn-dnd-list li.tn-dnd-placeholder { background:rgba(56,161,105,0.18); border-color:#68d391; }
/* Bigger touch target for the grip on mobile (spec §3.2). */
.tn-mobile .tn-dnd-list .tn-dnd-handle { min-width:24px; padding:8px 6px; font-size:14px; touch-action:none; }
.tn-bv-stale-warning { background:#fed7d7; color:#742a2a; padding:8px 12px; border-radius:4px; margin-bottom:8px; font-size:13px; }
html[data-theme="dark"] .tn-bv-stale-warning { background:#742a2a; color:#fed7d7; }

/* Autocomplete dropdown: house .kn-ac-results / .kn-ac-item (revised.css); only the empty state is local */
.kn-ac-item.kn-ac-empty { color:#a0aec0; cursor:default; }

/* =================================================================
   DARK MODE — Tournament Profile (tn-)
   Surface map: card #2d3748, alt #1a202c, modal #1a202c, border #2d3748/#4a5568
   Text map: primary #f7fafc, secondary #cbd5e0, muted #a0aec0, faint #718096
   Accent green: #68d391 (links/wins), tinted bg rgba(56,161,105,.15)
   ================================================================= */
html[data-theme="dark"] {
	--tn-accent: #68d391;
	--tn-accent-light: rgba(56,161,105,0.15);
}
/* Hero (already dark gradient) — only adjust translucent text */
html[data-theme="dark"] .tn-hero-icon { background:rgba(255,255,255,0.08); border-color:rgba(255,255,255,0.18); }

/* Playtest warning */
html[data-theme="dark"] .tn-playtest-warn { background:rgba(221,107,32,0.15); border-color:rgba(221,107,32,0.5); border-left-color:#dd6b20; color:#fbd38d; }
html[data-theme="dark"] .tn-playtest-warn-icon { color:#f6ad55; }
html[data-theme="dark"] .tn-playtest-warn-text strong { color:#fbd38d; }
html[data-theme="dark"] .tn-spectator-bar { background:rgba(229,62,62,0.14); border-color:rgba(229,62,62,0.5); border-left-color:#e53e3e; color:#feb2b2; }
html[data-theme="dark"] .tn-spectator-bar strong { color:#fc8181; }
html[data-theme="dark"] .tn-spectator-sync { color:#fc8181; }
html[data-theme="dark"] .tn-spectator-sync.tn-spectator-flash { color:#68d391; }
html[data-theme="dark"] .tn-spectator-dismiss { color:#fc8181; }
html[data-theme="dark"] .tn-spectator-dismiss:hover { background:rgba(229,62,62,0.2); color:#feb2b2; }
/* Reeves panel (dark) */
html[data-theme="dark"] .tn-reeves-card { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-reeves-title { color:#f7fafc; }
html[data-theme="dark"] .tn-reeves-title i { color:#68d391; }
html[data-theme="dark"] .tn-reeves-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-reeves-sub strong { color:#cbd5e0; }
html[data-theme="dark"] .tn-reeves-empty { color:#718096; }
html[data-theme="dark"] .tn-reeve-row { border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-reeve-persona a { color:#68d391; }
html[data-theme="dark"] .tn-reeve-badge-organizer { background:#44337a; color:#d6bcfa; }
html[data-theme="dark"] .tn-reeve-badge-bracket_runner { background:#22543d; color:#9ae6b4; }
html[data-theme="dark"] .tn-reeve-remove { color:#718096; }
html[data-theme="dark"] .tn-reeve-remove:hover { background:#742a2a; color:#feb2b2; }
html[data-theme="dark"] .tn-reeve-remove.tn-reeve-confirm { background:#742a2a; color:#feb2b2; }
/* Recommend column + modal (dark) */
html[data-theme="dark"] .tn-rec-btn { background:#2d3748; border-color:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-rec-btn i { color:#f6e05e; }
html[data-theme="dark"] .tn-rec-btn:hover { background:#1a202c; border-color:#d69e2e; color:#fbd38d; }
html[data-theme="dark"] .tn-rec-target { color:#cbd5e0; }
html[data-theme="dark"] .tn-rec-target strong { color:#f7fafc; }
html[data-theme="dark"] .tn-rank-pill { background:#2d3748; border-color:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-rank-pill:hover { border-color:#68d391; }
html[data-theme="dark"] .tn-rank-pill:focus-visible { outline-color:#68d391; }
html[data-theme="dark"] .tn-rank-pill.tn-rank-selected { background:#276749; border-color:#68d391; color:#fff; }
html[data-theme="dark"] .tn-rec-standing { color:#a0aec0; }
html[data-theme="dark"] .tn-rec-standing-topped { color:#f6ad55; }
html[data-theme="dark"] .tn-char-count { color:#718096; }
html[data-theme="dark"] .tn-char-count.tn-char-warn { color:#f6ad55; }

/* Stats row */
html[data-theme="dark"] .tn-stat-card { background:#2d3748; border-color:#4a5568; box-shadow:0 1px 3px rgba(0,0,0,0.3); }
html[data-theme="dark"] .tn-stat-card-link:hover { border-color:#68d391; box-shadow:0 2px 8px rgba(104,211,145,0.2); }
html[data-theme="dark"] .tn-stat-icon { color:#718096; }
html[data-theme="dark"] .tn-stat-value { color:#f7fafc; }
html[data-theme="dark"] .tn-stat-sub,
html[data-theme="dark"] .tn-stat-label { color:#a0aec0; }

/* Card */
html[data-theme="dark"] .tn-card { background:#2d3748; border-color:#4a5568; box-shadow:0 1px 3px rgba(0,0,0,0.3); }
html[data-theme="dark"] .tn-card h4 { color:#cbd5e0; }
html[data-theme="dark"] .tn-detail-row { color:#cbd5e0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-detail-icon { color:#718096; }
html[data-theme="dark"] .tn-detail-text a { color:#68d391; }

/* Tabs */
html[data-theme="dark"] .tn-tabs { background:#2d3748; border-color:#4a5568; box-shadow:0 1px 3px rgba(0,0,0,0.3); }
html[data-theme="dark"] .tn-tab-nav { border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-tab-nav li { color:#a0aec0; }
html[data-theme="dark"] .tn-tab-nav li:hover { color:#68d391; background:#1a202c; }
html[data-theme="dark"] .tn-tab-active { color:#68d391!important; border-bottom-color:#68d391!important; background:#2d3748!important; }
html[data-theme="dark"] .tn-tab-count { color:#718096; }

/* Bracket cards */
html[data-theme="dark"] .tn-bracket-card { border-color:#4a5568; }
html[data-theme="dark"] .tn-bracket-header { background:#1a202c; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-bracket-header h4 { color:#f7fafc; }
html[data-theme="dark"] .tn-bracket-meta { color:#a0aec0; }
html[data-theme="dark"] .tn-participant-list li { color:#cbd5e0; border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-participant-seed { background:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-empty { color:#718096; }
html[data-theme="dark"] .tn-remove-participant { color:#718096; }
html[data-theme="dark"] .tn-remove-participant:hover { color:#fc8181; }
html[data-theme="dark"] .tn-remove-participant:disabled, html[data-theme="dark"] .tn-remove-participant:disabled:hover { color:#718096; }

/* Pills (warrior/warlord/knight/complete) — darken backgrounds, keep colored text legible */
html[data-theme="dark"] .tn-pill-warrior { background:rgba(49,130,206,0.2); color:#90cdf4; border-color:rgba(49,130,206,0.4); }
html[data-theme="dark"] .tn-team-member-tag { background:rgba(49,130,206,0.2); color:#90cdf4; border-color:rgba(49,130,206,0.4); }
html[data-theme="dark"] .tn-team-member-remove { color:#90cdf4; }
html[data-theme="dark"] .tn-pill-warlord { background:rgba(180,83,9,0.25); color:#fbd38d; border-color:rgba(252,211,77,0.45); }
html[data-theme="dark"] .tn-pill-knight  { background:rgba(56,161,105,0.2); color:#9ae6b4; border-color:rgba(154,230,180,0.4); }
html[data-theme="dark"] .tn-pill-complete { background:rgba(56,161,105,0.2); color:#9ae6b4; border-color:rgba(154,230,180,0.4); }

/* Bracket status badges */
html[data-theme="dark"] .tn-bracket-status-setup { background:#2d3748; color:#a0aec0; }
html[data-theme="dark"] .tn-bracket-status-active { background:rgba(56,161,105,0.2); color:#9ae6b4; border-color:rgba(154,230,180,0.4); }
html[data-theme="dark"] .tn-bracket-status-complete { background:rgba(49,130,206,0.2); color:#90cdf4; border-color:rgba(49,130,206,0.4); }
html[data-theme="dark"] .tn-bracket-status-finalized { background:rgba(107,70,193,0.25); color:#d6bcfa; border-color:rgba(159,122,234,0.45); }

html[data-theme="dark"] .tn-placement-list li { border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-placement-num { color:#68d391; }
html[data-theme="dark"] .tn-bout-pip { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-bout-pip:hover { border-color:#a0aec0; }
html[data-theme="dark"] .tn-bout-pip.tn-pip-win  { background:#38a169; border-color:#68d391; }
html[data-theme="dark"] .tn-bout-pip.tn-pip-loss { background:#c53030; border-color:#fc8181; }
html[data-theme="dark"] .tn-bout-score { color:#68d391; }

/* Buttons */
html[data-theme="dark"] .tn-btn-primary { background:#38a169; color:#f7fafc; }
html[data-theme="dark"] .tn-btn-primary:hover { background:#48bb78; }
html[data-theme="dark"] .tn-btn-outline { color:#9ae6b4; border-color:#68d391; }
html[data-theme="dark"] .tn-btn-outline:hover { background:rgba(56,161,105,0.18); }
html[data-theme="dark"] .tn-btn-ghost { color:#cbd5e0; border-color:#4a5568; background:transparent; }
html[data-theme="dark"] .tn-btn-ghost:hover { background:#2d3748; color:#f7fafc; }
html[data-theme="dark"] .tn-btn-danger { background:transparent; color:#fc8181; border-color:#fc8181; }
html[data-theme="dark"] .tn-btn-danger:hover { background:#c53030; color:#fff; }
html[data-theme="dark"] .tn-bracket-toggle { color:#718096; }
html[data-theme="dark"] .tn-bracket-toggle:hover { color:#cbd5e0; }
html[data-theme="dark"] .tn-quickadd-row { border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-quickadd-name { color:#cbd5e0; }
html[data-theme="dark"] .tn-quickadd-row.tn-quickadd-done .tn-quickadd-name { color:#718096; }
html[data-theme="dark"] .tn-bracket-actions { border-top-color:#2d3748; }

/* Tables */
html[data-theme="dark"] .tn-table th { background:#1a202c; color:#a0aec0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-table td { color:#cbd5e0; border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-table [style*="color:#a0aec0"] { color:#a0aec0 !important; }
html[data-theme="dark"] table.dataTable.tn-table > thead > tr > th { border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-reg-chip { background:#2a4365; color:#90cdf4; border-color:#2c5282; }
html[data-theme="dark"] .tn-team-chip { background:#2a4365; color:#90cdf4; border-color:#2c5282; }
html[data-theme="dark"] .tn-reg-unassigned { color:#718096; }
html[data-theme="dark"] .tn-subtabs { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .tn-subtab { color:#a0aec0; }
html[data-theme="dark"] .tn-subtab:hover { color:#f7fafc; }
html[data-theme="dark"] .tn-subtab-active { background:#1a202c; color:#9ae6b4; box-shadow:0 1px 2px rgba(0,0,0,0.4); }
html[data-theme="dark"] .tn-reg-wd-badge { background:#742a2a; color:#feb2b2; }
html[data-theme="dark"] .tn-reg-act-btn { background:#2d3748; border-color:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-reg-act-btn:hover { background:#374151; border-color:#718096; color:#f7fafc; }
html[data-theme="dark"] .tn-reg-act-btn.tn-reg-act-danger { color:#fc8181; border-color:#742a2a; }
html[data-theme="dark"] .tn-reg-act-btn.tn-reg-act-danger:hover { background:#c53030; border-color:#c53030; color:#fff; }
html[data-theme="dark"] .tn-reg-act-btn.tn-reg-act-wd { color:#f6ad55; border-color:#7b341e; }
html[data-theme="dark"] .tn-reg-act-btn.tn-reg-act-wd:hover { background:#c05621; border-color:#c05621; color:#fff; }
html[data-theme="dark"] .tn-reg-act-btn.tn-reg-act-reactivate { color:#9ae6b4; border-color:#22543d; }
html[data-theme="dark"] .tn-reg-act-btn.tn-reg-act-reactivate:hover { background:#276749; border-color:#276749; color:#fff; }
html[data-theme="dark"] .tn-assign-row { background:#2d3748; border-color:#3a4658; }
html[data-theme="dark"] .tn-assign-row.tn-assign-disabled { background:#252d3a; }
html[data-theme="dark"] .tn-assign-row label { color:#e2e8f0; }
html[data-theme="dark"] .tn-assign-meta { color:#718096; }
html[data-theme="dark"] .tn-assign-meta.tn-assign-meta-locked { color:#f6ad55; }
html[data-theme="dark"] .tn-assign-empty { color:#718096; }
html[data-theme="dark"] .tn-btn.tn-team-act-danger { color:#fc8181; border-color:#742a2a; }
html[data-theme="dark"] .tn-btn.tn-team-act-danger:hover { background:#c53030; border-color:#c53030; color:#fff; }
html[data-theme="dark"] .tn-assignparts-filter { background:#2d3748; border-color:#3a4658; color:#e2e8f0; }
html[data-theme="dark"] .tn-assignparts-filter::placeholder { color:#718096; }
html[data-theme="dark"] .tn-assignparts-filter:focus { border-color:#48bb78; box-shadow:0 0 0 2px rgba(72,187,120,0.2); }
html[data-theme="dark"] .tn-assignparts-row { background:#2d3748; border-color:#3a4658; }
html[data-theme="dark"] .tn-assignparts-row:hover { background:#323d4f; }
html[data-theme="dark"] .tn-assignparts-alias { color:#e2e8f0; }
html[data-theme="dark"] .tn-assignparts-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-assignparts-row.tn-assignparts-wd .tn-assignparts-alias { color:#f6ad55; }
html[data-theme="dark"] .tn-assignparts-empty { color:#718096; }
html[data-theme="dark"] .tn-assignparts-empty a { color:#48bb78; }
/* Team UI */
html[data-theme="dark"] .tn-pill-team-wl { background:#44337a; color:#e9d8fd; border-color:#805ad5; }
html[data-theme="dark"] .tn-team-roster-btn { color:#9ae6b4; }
html[data-theme="dark"] .tn-team-roster-row td { background:#1a202c !important; border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-addteam-label { color:#f7fafc; }
html[data-theme="dark"] .tn-roster-member { color:#cbd5e0; }

/* Modals */
html[data-theme="dark"] .tn-overlay { background:rgba(0,0,0,0.7); }
html[data-theme="dark"] .tn-overlay .tn-modal-box { background:#1a202c; box-shadow:0 20px 60px rgba(0,0,0,0.6); }
html[data-theme="dark"] .tn-modal-header { border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-modal-title { color:#f7fafc; }
html[data-theme="dark"] .tn-modal-close { color:#718096; }
html[data-theme="dark"] .tn-modal-close:hover { color:#cbd5e0; }
html[data-theme="dark"] .tn-modal-footer { border-top-color:#2d3748; }
html[data-theme="dark"] .tn-confirm-warn { color:#fc8181; }
html[data-theme="dark"] .tn-field label { color:#cbd5e0; }
html[data-theme="dark"] .tn-field input,
html[data-theme="dark"] .tn-field select,
html[data-theme="dark"] .tn-field textarea { background:#2d3748; border-color:#4a5568; color:#f7fafc; }
html[data-theme="dark"] .tn-field input::placeholder,
html[data-theme="dark"] .tn-field textarea::placeholder { color:#718096; }
html[data-theme="dark"] .tn-field input:focus,
html[data-theme="dark"] .tn-field select:focus,
html[data-theme="dark"] .tn-field textarea:focus { border-color:#68d391; box-shadow:0 0 0 2px rgba(104,211,145,0.2); }
html[data-theme="dark"] .tn-seg-btn { background:#2d3748; border-color:#4a5568; color:#f7fafc; }
html[data-theme="dark"] .tn-seg-btn:hover { border-color:#68d391; }
html[data-theme="dark"] .tn-seg-btn.tn-seg-active { background:#276749; border-color:#68d391; color:#fff; }
html[data-theme="dark"] .tn-field-hint { color:#a0aec0; }
html[data-theme="dark"] .tn-feedback-err { color:#fc8181; }
html[data-theme="dark"] .tn-feedback-ok  { color:#9ae6b4; }

/* Bracket visualization */
html[data-theme="dark"] .tn-bv-round-label { color:#a0aec0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-bv-match { background:#2d3748; border-color:#4a5568; box-shadow:0 1px 3px rgba(0,0,0,0.3); }
html[data-theme="dark"] .tn-bv-match.tn-bv-clickable { border-color:#68d391; }
html[data-theme="dark"] .tn-bv-match.tn-bv-clickable:hover { box-shadow:0 2px 8px rgba(104,211,145,0.25); background:rgba(56,161,105,0.12); }
html[data-theme="dark"] .tn-bv-hit:focus-visible { outline-color:#63b3ed; }
html[data-theme="dark"] .tn-bv-match.tn-bv-resolved { border-color:#38a169; background:rgba(56,161,105,0.12); }
html[data-theme="dark"] .tn-bv-slot:first-child { border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-bv-slot.tn-bv-winner { color:#9ae6b4; }
html[data-theme="dark"] .tn-bv-slot.tn-bv-loser  { color:#a0aec0; }
html[data-theme="dark"] .tn-bv-slot.tn-bv-bye    { color:#718096; }
html[data-theme="dark"] .tn-bv-seed { background:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-bv-result-pill { background:rgba(56,161,105,0.25); color:#9ae6b4; }
html[data-theme="dark"] .tn-bv-reset-btn { color:#4a5568; }
html[data-theme="dark"] .tn-bv-reset-btn:hover { color:#fc8181; background:rgba(229,62,62,0.18); }
html[data-theme="dark"] .tn-bv-reset-btn.tn-bv-reset-confirm { color:#fc8181; background:rgba(229,62,62,0.18); border-color:#fc8181; }

/* Ironman / KOTH */
html[data-theme="dark"] .tn-im-fight-num { color:#a0aec0; }
html[data-theme="dark"] .tn-im-king-badge { background:rgba(56,161,105,0.18); border-color:rgba(154,230,180,0.4); }
html[data-theme="dark"] .tn-im-king-badge-crown { color:#ecc94b; }
html[data-theme="dark"] .tn-im-king-badge-label { color:#9ae6b4; }
html[data-theme="dark"] .tn-im-king-badge-name { color:#f7fafc; }
html[data-theme="dark"] .tn-im-king-badge-streak { color:#9ae6b4; background:rgba(56,161,105,0.3); }
html[data-theme="dark"] .tn-im-card { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .tn-im-card.tn-im-card-king { background:rgba(49,130,206,0.18); border-color:#3182ce; }
html[data-theme="dark"] .tn-im-card.tn-im-card-btn:hover { box-shadow:0 4px 12px rgba(0,0,0,0.5); }
html[data-theme="dark"] .tn-im-card.tn-im-card-btn.tn-im-card-king:hover { background:rgba(49,130,206,0.3); }
html[data-theme="dark"] .tn-im-card-name { color:#f7fafc; }
html[data-theme="dark"] .tn-im-card-wins { color:#a0aec0; }
html[data-theme="dark"] .tn-im-card-streak { color:#f6ad55; }
html[data-theme="dark"] .tn-im-section-title { color:#a0aec0; }
html[data-theme="dark"] .tn-im-history-row:nth-child(odd) { background:#1a202c; }
html[data-theme="dark"] .tn-im-history-fight { color:#718096; }
html[data-theme="dark"] .tn-im-history-winner { color:#9ae6b4; }
html[data-theme="dark"] .tn-im-history-loser { color:#718096; }
html[data-theme="dark"] .tn-im-history-expand { color:#90cdf4; }
html[data-theme="dark"] .tn-im-qe-wrap { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-im-qe-label { color:#a0aec0; }
html[data-theme="dark"] .tn-im-qe-input { background:#2d3748; border-color:#4a5568; color:#f7fafc; }
html[data-theme="dark"] .tn-im-qe-input:focus { border-color:#3182ce; }
html[data-theme="dark"] .tn-im-qe-status { color:#a0aec0; }
html[data-theme="dark"] .tn-im-qe-status.ok { color:#9ae6b4; }
html[data-theme="dark"] .tn-im-qe-status.err { color:#fc8181; }
/* Timer bar already dark — keep as-is */
html[data-theme="dark"] .tn-im-ring { background:#2d3748; border-color:#4a5568; }

/* Method pills (sidebar bracket method picker) */
html[data-theme="dark"] .tn-bk-pill { background:#2d3748; border-color:#4a5568; color:#a0aec0; }
html[data-theme="dark"] .tn-bk-pill:hover { border-color:#68d391; color:#9ae6b4; background:rgba(56,161,105,0.15); }
html[data-theme="dark"] .tn-bk-pill.tn-bk-pill-active { background:#38a169; border-color:#68d391; color:#fff; }

/* Round nav buttons */
html[data-theme="dark"] .tn-bv-round-btn { background:#2d3748; border-color:#4a5568; color:#a0aec0; }
html[data-theme="dark"] .tn-bv-round-btn.active { background:#38a169; color:#fff; border-color:#68d391; }
html[data-theme="dark"] .tn-bv-round-btn.tn-rr-complete:not(.active) { background:#3182ce; border-color:#4299e1; }
html[data-theme="dark"] .tn-bv-round-btn.tn-bv-round-btn-tb:not(.active) { background:rgba(221,107,32,0.18); color:#fbd38d; border-color:rgba(221,107,32,0.5); }
html[data-theme="dark"] .tn-bv-round-btn.tn-bv-round-btn-tb.active { background:#dd6b20; color:#fff; border-color:#dd6b20; }

/* Section headers */
html[data-theme="dark"] .tn-bv-section-label { color:#a0aec0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-bv-generate-bar { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-bv-status-setup    { background:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-bv-status-active   { background:rgba(49,130,206,0.3); color:#90cdf4; }
html[data-theme="dark"] .tn-bv-status-complete  { background:rgba(56,161,105,0.3); color:#9ae6b4; }
html[data-theme="dark"] .tn-bv-status-finalized { background:rgba(180,83,9,0.3); color:#fbd38d; }
html[data-theme="dark"] .tn-bv-empty { color:#718096; }
html[data-theme="dark"] .tn-bv-section-hdr.winners { background:rgba(56,161,105,0.18); color:#9ae6b4; border-left-color:#68d391; }
html[data-theme="dark"] .tn-bv-section-hdr.losers { background:rgba(180,83,9,0.2); color:#fbd38d; border-left-color:#d97706; }
html[data-theme="dark"] .tn-bv-section-hdr.grand-final { background:rgba(107,70,193,0.25); color:#d6bcfa; border-left-color:#9f7aea; }

html[data-theme="dark"] .tn-gf-confirm-banner { background:rgba(180,83,9,0.2); border-color:#ecc94b; }
html[data-theme="dark"] .tn-gf-confirm-text { color:#fbd38d; }

html[data-theme="dark"] .tn-bv-progress-info { color:#a0aec0; }
html[data-theme="dark"] .tn-bv-progress-info .tn-bv-pi-ready { color:#9ae6b4; }
html[data-theme="dark"] .tn-bv-bout-row { background:#1a202c; border-top-color:#4a5568; }
html[data-theme="dark"] .tn-bv-tbd-label { color:#718096; }
/* ── Quick Bracket (spec 2026-10-02) ── */
.tn-qb-btnstack { display:flex; flex-direction:column; align-items:stretch; gap:6px; }
.tn-qb-field + .tn-qb-field { margin-top:18px; }
.tn-qb-field-label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#4a5568; margin-bottom:8px; }
.tn-qb-chips { display:flex; flex-wrap:wrap; gap:8px; }
.tn-qb-chip { min-width:52px; min-height:44px; padding:8px 14px; border:1px solid #cbd5e0; border-radius:8px; background:#fff; color:#2d3748; font-weight:600; font-size:14px; cursor:pointer; }
.tn-qb-chip[aria-checked="true"] { background:#2b6cb0; border-color:#2b6cb0; color:#fff; }
.tn-qb-chip:focus-visible { outline:2px solid #2b6cb0; outline-offset:2px; }
.tn-qb-toolbar { display:flex; align-items:center; flex-wrap:wrap; gap:8px; padding:10px 12px; margin-bottom:10px; border:1px solid #e2e8f0; border-radius:8px; background:#f7fafc; }
.tn-qb-badge { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:#975a16; text-transform:uppercase; letter-spacing:.03em; }
.tn-qb-count { flex:1; min-width:140px; font-size:13px; color:#4a5568; }
.tn-qb-note { font-size:12px; color:#718096; margin:0 0 8px; }
.tn-qb-runnote { flex-basis:100%; margin:0; font-size:12px; color:#2c5282; }
.tn-qb-line { position:relative; }
.tn-qb-empty { cursor:pointer; }
.tn-qb-empty.tn-qb-ro { cursor:default; }
.tn-qb-empty.tn-qb-ro:hover { background:transparent; }
.tn-qb-empty:hover, .tn-qb-empty:focus-visible { background:#ebf8ff; outline:none; }
.tn-qb-hint { color:#718096; font-style:italic; font-size:12px; }
.tn-qb-input { flex:1; min-width:0; border:none; outline:none; background:transparent; font:inherit; font-size:13px; color:inherit; padding:0; }
.tn-qb-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tn-qb-alias { font-style:italic; }
.tn-qb-alias-tag { flex-shrink:0; font-size:10px; font-weight:700; text-transform:uppercase; color:#718096; border:1px solid #cbd5e0; border-radius:4px; padding:0 4px; }
.tn-qb-clear { flex-shrink:0; border:none; background:none; color:#a0aec0; cursor:pointer; padding:0 4px; font-size:16px; line-height:1; opacity:0; }
.tn-qb-filled:hover .tn-qb-clear, .tn-qb-clear:focus-visible { opacity:1; color:#c53030; }
.tn-qb-filled[draggable="true"] { cursor:grab; }
.tn-qb-drop { box-shadow:inset 0 0 0 2px #3182ce; }
.tn-qb-swap-src { background:#fefcbf; }
.tn-qb-ac-hdr { padding:4px 10px; font-size:11px; font-weight:700; text-transform:uppercase; color:#718096; cursor:default; }
.tn-qb-ac-alias { border-top:1px solid #e2e8f0; }
.tn-qb-ac-alias .tn-qb-ac-sub, .tn-qb-ac-sub { color:#a0aec0; font-size:11px; }
.kn-ac-item.tn-qb-ac-hi { background:#ebf8ff; }
@media (hover:none) { .tn-qb-clear { opacity:1; } }
.tn-mobile .tn-qb-line { min-height:44px; }
.tn-mobile .tn-qb-clear { opacity:1; min-width:44px; min-height:44px; }
.tn-mobile .tn-qb-toolbar .tn-btn, .tn-mobile .tn-qb-btnstack .tn-btn { min-height:44px; }
html[data-theme="dark"] .tn-qb-field-label { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-chip { background:#2d3748; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .tn-qb-chip[aria-checked="true"] { background:#2b6cb0; border-color:#4299e1; color:#fff; }
html[data-theme="dark"] .tn-qb-chip:focus-visible { outline-color:#90cdf4; }
html[data-theme="dark"] .tn-qb-toolbar { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-qb-badge { color:#f6e05e; }
html[data-theme="dark"] .tn-qb-count, html[data-theme="dark"] .tn-qb-note { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-runnote { color:#90cdf4; }
html[data-theme="dark"] .tn-qb-empty:hover, html[data-theme="dark"] .tn-qb-empty:focus-visible { background:#2a4365; }
html[data-theme="dark"] .tn-qb-empty.tn-qb-ro:hover { background:transparent; }
html[data-theme="dark"] .tn-qb-hint { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-input::placeholder { color:#a0aec0; opacity:1; }
html[data-theme="dark"] .tn-qb-alias-tag { color:#a0aec0; border-color:#4a5568; }
html[data-theme="dark"] .tn-qb-clear { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-filled:hover .tn-qb-clear, html[data-theme="dark"] .tn-qb-clear:focus-visible { color:#fc8181; }
html[data-theme="dark"] .tn-qb-drop { box-shadow:inset 0 0 0 2px #63b3ed; }
html[data-theme="dark"] .tn-qb-swap-src { background:#5f370e; }
html[data-theme="dark"] .tn-qb-ac-hdr, html[data-theme="dark"] .tn-qb-ac-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-qb-ac-alias { border-top-color:#4a5568; }
html[data-theme="dark"] .kn-ac-item.tn-qb-ac-hi { background:#2a4365; }

/* Round-robin standings & matrix */
html[data-theme="dark"] .tn-rr-standings caption { color:#a0aec0; }
html[data-theme="dark"] .tn-rr-standings th { background:#1a202c; color:#a0aec0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-rr-standings td { color:#cbd5e0; border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-rr-standings td:nth-child(2) { color:#f7fafc; }
html[data-theme="dark"] .tn-rr-standings .tn-rr-std-top td { background:rgba(56,161,105,0.15); }

html[data-theme="dark"] .tn-rr-view-toggle { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .tn-rr-view-toggle-btn { background:#2d3748; color:#a0aec0; }
html[data-theme="dark"] .tn-rr-view-toggle-btn:first-child { border-right-color:#4a5568; }
html[data-theme="dark"] .tn-rr-view-toggle-btn:hover:not(.active) { background:#1a202c; color:#cbd5e0; }
html[data-theme="dark"] .tn-rr-view-toggle-btn.active { background:#38a169; color:#fff; }

html[data-theme="dark"] .tn-rr-matrix-wrap { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .tn-rr-matrix th,
html[data-theme="dark"] .tn-rr-matrix td { border-bottom-color:#2d3748; border-right-color:#2d3748; }
html[data-theme="dark"] .tn-rr-matrix thead th { background:#1a202c; color:#a0aec0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-rr-mx-player-col { background:#1a202c; color:#f7fafc; border-right-color:#4a5568!important; }
html[data-theme="dark"] .tn-rr-matrix thead th:first-child { border-right-color:#4a5568; }
html[data-theme="dark"] .tn-rr-mx-win  { background:rgba(56,161,105,0.18); color:#9ae6b4; }
html[data-theme="dark"] .tn-rr-mx-loss { background:rgba(229,62,62,0.18); color:#fc8181; }
html[data-theme="dark"] .tn-rr-mx-tie  { background:rgba(180,83,9,0.2); color:#fbd38d; }
html[data-theme="dark"] .tn-rr-mx-self { background:#1a202c; color:#4a5568; }
html[data-theme="dark"] .tn-rr-mx-self::after { background:repeating-linear-gradient(-45deg, transparent, transparent 3px, rgba(255,255,255,0.04) 3px, rgba(255,255,255,0.04) 6px); }
html[data-theme="dark"] .tn-rr-mx-pending { color:#718096; }
html[data-theme="dark"] .tn-rr-mx-cell-clickable:hover { box-shadow:inset 0 0 0 2px #68d391; background:rgba(56,161,105,0.15); }

html[data-theme="dark"] .tn-rr-standings-enhanced caption { color:#a0aec0; }
html[data-theme="dark"] .tn-rr-standings-enhanced th { background:#1a202c; color:#a0aec0; border-bottom-color:#4a5568; }
html[data-theme="dark"] .tn-rr-standings-enhanced td { color:#cbd5e0; border-bottom-color:#2d3748; }
html[data-theme="dark"] .tn-rr-std-name { color:#f7fafc; }
html[data-theme="dark"] .tn-rr-std-park { color:#718096; }
html[data-theme="dark"] .tn-rr-std-w { color:#9ae6b4; }
html[data-theme="dark"] .tn-rr-std-l { color:#fc8181; }
html[data-theme="dark"] .tn-rr-std-t { color:#fbd38d; }
html[data-theme="dark"] .tn-rr-std-pts { color:#f7fafc; }
html[data-theme="dark"] .tn-rr-std-winpct { color:#9ae6b4; }
html[data-theme="dark"] .tn-rr-std-bar-track { background:#4a5568; }
html[data-theme="dark"] .tn-rr-std-bar-text { color:#a0aec0; }
html[data-theme="dark"] .tn-rr-std-caption-progress { color:#718096; }
html[data-theme="dark"] .tn-rr-std-active td { background:rgba(49,130,206,0.2)!important; box-shadow:inset 3px 0 0 #4299e1; }
html[data-theme="dark"] .tn-rr-std-clickable tr:hover td { background:#1a202c; }

html[data-theme="dark"] .tn-rr-progress-bar { background:#4a5568; box-shadow:inset 0 1px 2px rgba(0,0,0,0.3); }
html[data-theme="dark"] .tn-rr-progress-bar.tn-rr-progress-low .tn-rr-progress-label { color:#cbd5e0; }
html[data-theme="dark"] .tn-rr-round-count { background:#1a202c; border-color:#4a5568; color:#a0aec0; }

/* RR podium chips keep their pastel fills, so their inner text stays dark. */
html[data-theme="dark"] .tn-bv-podium-name { color:#1a202c; }
html[data-theme="dark"] .tn-bv-podium-park { color:#4a5568; }
html[data-theme="dark"] .tn-bv-podium-stats { color:#22543d; }
html[data-theme="dark"] .tn-rr-card-record { color:#718096; }

html[data-theme="dark"] .tn-rr-focus-banner { background:rgba(49,130,206,0.18); border-color:rgba(49,130,206,0.5); color:#90cdf4; }
html[data-theme="dark"] .tn-rr-focus-banner-name { color:#f7fafc; }
html[data-theme="dark"] .tn-rr-focus-banner-close { color:#90cdf4; }
html[data-theme="dark"] .tn-rr-focus-banner-close:hover { background:rgba(49,130,206,0.3); color:#bee3f8; }

/* Bracket viz extras */
html[data-theme="dark"] .tn-bv-match-num { color:#a0aec0; }
html[data-theme="dark"] .tn-bv-match-num.tn-bv-tb-badge { background:#c05621; color:#fff; }
html[data-theme="dark"] .tn-bv-match.tn-bv-bye-match { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-bv-match.tn-bv-bye-match .tn-bv-slot { color:#718096; }
html[data-theme="dark"] .tn-bv-bye-label { color:#a0aec0; border-top-color:#4a5568; }
/* Champion banner stays gold in both modes — podium pills sit ON the gold gradient,
   so they need backgrounds that contrast WITH the gold (not blend into it). */
html[data-theme="dark"] .tn-bv-podium-1st { background:#fefcbf; color:#744210; border-color:#ecc94b; }
html[data-theme="dark"] .tn-bv-podium-2nd { background:#e2e8f0; color:#4a5568; border-color:#cbd5e0; }
html[data-theme="dark"] .tn-bv-podium-3rd { background:#fed7aa; color:#7b341e; border-color:#f6ad55; }
/* Champion banner inner text — gold gradient stays light, so flip the inline-style override exemption */
html[data-theme="dark"] .tn-bv-champion-name { color:#1a202c; }
html[data-theme="dark"] .tn-bv-champion-label { color:#744210; }
html[data-theme="dark"] .tn-bv-champion-park { color:#744210; }
html[data-theme="dark"] .tn-bv-zoom-btn { background:#2d3748; border-color:#4a5568; color:#a0aec0; }
html[data-theme="dark"] .tn-bv-zoom-btn:hover { background:rgba(56,161,105,0.18); color:#9ae6b4; border-color:#68d391; }
html[data-theme="dark"] .tn-bv-zoom-level { color:#718096; }
html[data-theme="dark"] .tn-bv-section-hdr.tiebreaker-3rd,
html[data-theme="dark"] .tn-bv-section-hdr.tiebreaker-rr { background:rgba(221,107,32,0.2); color:#fbd38d; border-left-color:#dd6b20; }

/* Status menu / autocomplete dropdown */
html[data-theme="dark"] .tn-status-btn { color:#718096; }
html[data-theme="dark"] .tn-status-btn:hover { color:#cbd5e0; }
html[data-theme="dark"] .tn-status-menu { background:#2d3748; border-color:#4a5568; box-shadow:0 4px 12px rgba(0,0,0,0.4); }
html[data-theme="dark"] .tn-status-menu-item { color:#cbd5e0; border-bottom-color:#1a202c; }
html[data-theme="dark"] .tn-status-menu-item:hover, html[data-theme="dark"] .tn-status-menu-item:focus { background:#1a202c; }
html[data-theme="dark"] .tn-status-menu-item:focus-visible { box-shadow:inset 0 0 0 2px #63b3ed; }
html[data-theme="dark"] .tn-status-menu-item.tn-sm-active { color:#9ae6b4; }
html[data-theme="dark"] .tn-pstatus-pill-withdrawn { background:rgba(180,83,9,0.25); color:#fbd38d; border-color:rgba(252,211,77,0.45); }
html[data-theme="dark"] .tn-pstatus-pill-disqualified { background:rgba(229,62,62,0.18); color:#fc8181; border-color:rgba(252,129,129,0.4); }
html[data-theme="dark"] .tn-match-void-pill { background:#2d3748; color:#a0aec0; border-color:#4a5568; }

/* Quick result entry */
html[data-theme="dark"] .tn-qr-bar { background:#1a202c; border-top-color:#4a5568; }
html[data-theme="dark"] .tn-qr-btn-tie { background:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-qr-btn-tie:hover { background:#718096; }
html[data-theme="dark"] .tn-qr-more { color:#90cdf4; }
html[data-theme="dark"] .tn-bv-match.tn-qr-expanded { border-color:#68d391; box-shadow:0 2px 8px rgba(104,211,145,0.25); }

html[data-theme="dark"] .tn-bout-score-pill { background:rgba(56,161,105,0.25); color:#9ae6b4; }

/* DnD reorder */
html[data-theme="dark"] .tn-dnd-over { background:rgba(56,161,105,0.18)!important; outline-color:#68d391; }
html[data-theme="dark"] .tn-dnd-handle { color:#4a5568; }

/* Autocomplete */
html[data-theme="dark"] .kn-ac-item.kn-ac-empty { color:#718096; }

/* =================================================================
   Inline-style overrides (PHP/JS-rendered hardcoded colors)
   These hex codes appear repeatedly in inline style="" — neutralize
   without touching every occurrence. Keep in sync with audit results.
   ================================================================= */
/* Player profile links */
html[data-theme="dark"] [style*="color:#276749"] { color:#9ae6b4 !important; }
/* Park/secondary text — #718096 is borderline on dark, lift to #a0aec0 */
html[data-theme="dark"] [style*="color:#718096"] { color:#a0aec0 !important; }
/* #a0aec0 already reads ~5:1 on dark surfaces — leave as-is */
/* Inactive rank cells (#e2e8f0 too bright on dark, mute to subtle gray) */
html[data-theme="dark"] [style*="color:#e2e8f0"] { color:#4a5568 !important; }
/* Dark text bombs */
html[data-theme="dark"] [style*="color:#1a202c"] { color:#f7fafc !important; }
html[data-theme="dark"] [style*="color:#2d3748"] { color:#cbd5e0 !important; }
html[data-theme="dark"] [style*="color:#4a5568"] { color:#cbd5e0 !important; }
/* Trophy-gold and hero icon colors stay (they're warm highlights on dark gradients) */
/* Modal title icons (#276749, #3182ce) flip via the rules above */
/* Help-text light boxes */
html[data-theme="dark"] [style*="background:#f7fafc"] { background:#1a202c !important; border-color:#4a5568 !important; }
html[data-theme="dark"] [style*="background:#fff5f5"] { background:rgba(229,62,62,0.15) !important; }
/* JS-injected 4th-place podium card */
html[data-theme="dark"] .tn-bv-podium-card[style*="background:#f7fafc"] { background:#2d3748 !important; color:#a0aec0 !important; border-color:#4a5568 !important; }

/* Bracket-method left-border accents for cards already work in dark mode (color-only) */

/* ===== Focus Mode (full-screen running view) ===== */
.tn-focus-toggle { margin-left:auto; color:#276749 !important; gap:6px; font-weight:700; }
.tn-focus-toggle:hover { color:#276749 !important; background:#f0fff4 !important; }
.tn-focus-bar { display:none; position:sticky; top:0; z-index:50; align-items:center; justify-content:space-between; gap:12px; background:linear-gradient(135deg,#1a202c 0%,#2d3748 100%); color:#fff; padding:10px 16px; border-radius:0 0 12px 12px; margin-bottom:14px; box-shadow:0 2px 10px rgba(0,0,0,0.28); }
.tn-focus-bar-title { font-size:1.05rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tn-focus-bar-title i { color:#f6e05e; margin-right:8px; }
.tn-focus-exit { flex-shrink:0; background:rgba(255,255,255,0.12); color:#fff; border:1px solid rgba(255,255,255,0.32); border-radius:6px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; }
.tn-focus-exit:hover { background:rgba(255,255,255,0.22); }
#tn-root.tn-focus .tn-hero,
#tn-root.tn-focus .tn-playtest-warn,
#tn-root.tn-focus .tn-stats-row,
#tn-root.tn-focus .tn-sidebar { display:none !important; }
#tn-root.tn-focus .tn-focus-bar { display:flex; }
#tn-root.tn-focus .tn-bv-viewport { min-height:calc(100vh - 230px); }
/* Focus mode dark */
html[data-theme="dark"] .tn-focus-toggle,
html[data-theme="dark"] .tn-focus-toggle:hover { color:#9ae6b4 !important; }
html[data-theme="dark"] .tn-focus-toggle:hover { background:#2d3748 !important; }
html[data-theme="dark"] .tn-focus-bar { background:linear-gradient(135deg,#0f1419 0%,#1a202c 100%); box-shadow:0 2px 10px rgba(0,0,0,0.55); }

/* =============================================
   Mobile foundation (PHASE C0) — shared tokens + view-mode toggle.
   Mobile presentation keys off the JS-toggled `.tn-mobile` class on #tn-root
   (viewport-seeded, sessionStorage-persisted) — never bare media queries — so
   it is forceable and QA-able on desktop.
   ============================================= */
:root {
	--tn-touch: 44px;                                  /* min touch-target size */
	--tn-sheet-radius: 16px;                           /* bottom/action sheet top corners */
	--tn-deck-gap: 12px;                               /* gap between card-deck cards */
	--tn-safe-bottom: env(safe-area-inset-bottom, 0px);
	--tn-safe-top: env(safe-area-inset-top, 0px);
}

/* Floating mobile/desktop toggle pill — persistent control, bottom-right. */
.tn-mq-toggle {
	position:fixed;
	right:16px;
	bottom: calc(16px + env(safe-area-inset-bottom, 0px));
	z-index:1200;                                      /* above page content; below open sheets (which use higher) */
	display:inline-flex;
	align-items:center;
	gap:6px;
	min-height:var(--tn-touch);
	padding:0 16px;
	border:1px solid #cbd5e0;
	border-radius:999px;
	background:#fff;
	color:#276749;
	font-size:13px;
	font-weight:700;
	cursor:pointer;
	box-shadow:0 3px 12px rgba(0,0,0,0.18);
	-webkit-tap-highlight-color:transparent;
}
.tn-mq-toggle:hover { background:#f0fff4; }
.tn-mq-toggle[data-tip] { cursor:pointer; }
.tn-mq-toggle i { font-size:14px; }
html[data-theme="dark"] .tn-mq-toggle {
	background:#1a202c;
	color:#9ae6b4;
	border-color:#4a5568;
	box-shadow:0 3px 12px rgba(0,0,0,0.5);
}
html[data-theme="dark"] .tn-mq-toggle:hover { background:#2d3748; }
/* Only offer the mobile/desktop view toggle at mobile width. */
@media (min-width: 769px) { .tn-mq-toggle { display:none !important; } }

/* Global h1-h6 gray-box trap reset for any heading inside a mobile sheet/overlay.
   Keyed off .tn-overlay (every sheet presents an existing .tn-overlay element);
   page headings are outside overlays so they are correctly unaffected, and
   .tn-modal-title already self-resets with !important. */
.tn-mobile .tn-overlay h1,
.tn-mobile .tn-overlay h2,
.tn-mobile .tn-overlay h3,
.tn-mobile .tn-overlay h4 {
	background:transparent;
	border:none;
	padding:0;
	border-radius:0;
	text-shadow:none;
}

/* =============================================================
   PHASE C0 Task 3 — Bottom-sheet + action-sheet primitive.
   NO DOM DUPLICATION: existing `.tn-overlay`/`.tn-modal-box` markup is
   RE-STYLED into a bottom sheet only while `.tn-mobile` is active. On
   desktop these rules don't apply, so the existing centered-overlay
   behavior (lines 299-308) is preserved verbatim. `TnMobile.sheet.open`
   reuses the same `.tn-open` class as `tnOpenModal`, so the open/close
   transitions defined above carry over.
   The keyboard-safe footer is driven by --tn-vvh (visualViewport height,
   set in JS) so the sheet's height tracks the visible viewport above the
   soft keyboard; falls back to 100vh / 100dvh when JS hasn't set it.
   ============================================================= */

/* Bottom-anchor the overlay flex container under mobile. */
.tn-mobile .tn-overlay { align-items:flex-end; justify-content:center; }

/* The existing modal box becomes a bottom sheet: full-width, top-rounded,
   height capped to the visible viewport (above the keyboard). */
.tn-mobile .tn-overlay .tn-modal-box {
	width:100%;
	max-width:100%;
	border-radius:var(--tn-sheet-radius) var(--tn-sheet-radius) 0 0;
	/* Track the visible viewport (var set by JS); fall back gracefully. */
	max-height:92vh;
	max-height:92dvh;
	max-height:calc(var(--tn-vvh, 92vh) - 24px);
}
/* Sheets slide fully up from the bottom edge when closed (overrides the
   subtle 8px lift used by centered overlays) for a clear "sheet" motion. */
.tn-mobile .tn-overlay:not(.tn-open) .tn-modal-box { transform:translateY(100%); }
.tn-mobile .tn-overlay.tn-open .tn-modal-box { transform:translateY(0); }

/* Grab handle affordance at the top of every mobile sheet. */
.tn-mobile .tn-overlay .tn-modal-box::before {
	content:'';
	position:absolute;
	top:8px; left:50%;
	width:38px; height:4px;
	margin-left:-19px;
	border-radius:2px;
	background:#cbd5e0;
	pointer-events:none;
}
.tn-mobile .tn-overlay .tn-modal-header { position:sticky; top:0; z-index:2; background:#fff; padding-top:20px; }
/* Footer: sticky, pinned to bottom, full-width primary action, safe-area aware. */
.tn-mobile .tn-overlay .tn-modal-footer {
	position:sticky;
	bottom:0;
	z-index:2;
	background:#fff;
	padding-bottom:calc(14px + env(safe-area-inset-bottom, 0px));
	flex-wrap:wrap;
}
.tn-mobile .tn-overlay .tn-modal-footer .tn-btn-primary { flex:1 1 100%; order:-1; min-height:var(--tn-touch); text-align:center; }
.tn-mobile .tn-overlay .tn-modal-footer .tn-btn { min-height:var(--tn-touch); }
/* The scrollable region. .tn-modal-body already has overflow-y:auto + flex:1. */
.tn-mobile .tn-overlay .tn-modal-body { -webkit-overflow-scrolling:touch; }
/* Bout pips >=32px tappable inside a sheet, regardless of viewport width
   (the 480px media-query rule doesn't fire under forced .tn-mobile).
   Sizing only; win/loss coloring + tap-toggle remain class-driven. */
.tn-mobile .tn-overlay .tn-bout-pip { width:32px; height:32px; }
.tn-mobile .tn-overlay .tn-bout-pips { gap:10px; }

/* Larger, touch-friendly autocomplete rows inside a sheet (registration §3). */
.tn-mobile .kn-ac-results { max-height:min(50vh, 320px); }
.tn-mobile .kn-ac-item { padding:13px 14px; font-size:15px; min-height:var(--tn-touch); display:flex; align-items:center; }

/* --- Bulk Add (Paste Roster): near-full-height sheet, textarea fills body.
   The body becomes a flex column so the .tn-field wrapping the textarea (and
   the textarea itself) can flex to consume all remaining height — replacing
   the desktop fixed rows=N. Desktop is untouched (rules are .tn-mobile-scoped). */
.tn-mobile #tn-bulkadd-overlay .tn-modal-box { max-height:96vh; max-height:96dvh; max-height:calc(var(--tn-vvh, 96vh) - 12px); }
.tn-mobile #tn-bulkadd-overlay .tn-modal-body { display:flex; flex-direction:column; }
.tn-mobile #tn-bulkadd-overlay .tn-modal-body .tn-field:has(#tn-bulkadd-text) { flex:1; margin-bottom:0; min-height:0; }
.tn-mobile #tn-bulkadd-overlay #tn-bulkadd-text { flex:1; height:auto; min-height:140px; resize:none; }

/* --- Add Team: bigger member chips + larger remove target on mobile (§5).
   The member-tag container wraps; the body scrolls so a long roster doesn't
   push the sticky footer off-screen (body already overflow-y:auto + flex:1). */
.tn-addteam-label { color:#2d3748; }
.tn-mobile #tn-addteam-members { display:flex; flex-wrap:wrap; gap:8px; }
.tn-mobile #tn-addteam-members .tn-team-member-tag { font-size:14px; padding:6px 12px; min-height:36px; margin:0; }
.tn-mobile #tn-addteam-members .tn-team-member-remove { padding:6px 8px; min-width:32px; font-size:16px; }

/* --- Action-sheet variant: content-sized (auto-height) menu of options. --- */
.tn-mobile .tn-overlay.tn-sheet--action .tn-modal-box { max-height:80vh; max-height:80dvh; }
.tn-sheet-action-list { display:flex; flex-direction:column; padding:8px 0 calc(8px + env(safe-area-inset-bottom, 0px)); }
.tn-sheet-action-item {
	display:flex; align-items:center; gap:10px;
	width:100%;
	min-height:48px;
	padding:0 20px;
	border:none;
	background:none;
	font-size:16px;
	font-weight:600;
	color:#2d3748;
	text-align:left;
	cursor:pointer;
	-webkit-tap-highlight-color:transparent;
}
.tn-sheet-action-item:active { background:#f0fff4; }
.tn-sheet-action-item.tn-sheet-action--danger { color:#c53030; }
.tn-sheet-action-item.tn-sheet-action--danger:active { background:#fff5f5; }
.tn-sheet-action-cancel { border-top:1px solid #e2e8f0; color:#718096; font-weight:700; }

/* z-index: an OPEN sheet must cover the .tn-mq-toggle pill (z-index:1200). The
   base .tn-overlay is z-index:1100; raise it above the pill only when open and
   in mobile mode so the pill never pokes through a sheet. */
.tn-mobile .tn-overlay.tn-open { z-index:1300; }

/* ---- Dark-mode variants for the sheet surfaces ---- */
html[data-theme="dark"] .tn-mobile .tn-overlay .tn-modal-box::before { background:#4a5568; }
html[data-theme="dark"] .tn-mobile .tn-overlay .tn-modal-header { background:#1a202c; }
html[data-theme="dark"] .tn-mobile .tn-overlay .tn-modal-footer { background:#1a202c; }
html[data-theme="dark"] .tn-sheet-action-item { color:#e2e8f0; }
html[data-theme="dark"] .tn-sheet-action-item:active { background:#2d3748; }
html[data-theme="dark"] .tn-sheet-action-item.tn-sheet-action--danger { color:#fc8181; }
html[data-theme="dark"] .tn-sheet-action-item.tn-sheet-action--danger:active { background:#3b2222; }
html[data-theme="dark"] .tn-sheet-action-cancel { border-top-color:#2d3748; color:#a0aec0; }

/* =============================================================
   B3 — Mobile bracket-card actions: prominent Generate + "More" sheet.
   Desktop keeps its inline 6-button cluster (.tn-bracket-actions-desktop);
   the mobile bar (.tn-bracket-actions-mobile) is hidden off-mobile. Under
   .tn-mobile we swap: hide the cluster, show a full-width prominent Generate
   plus a compact "More" overflow button. Presentation only — both call the
   EXISTING action functions. Dark-mode parity at the bottom.
   ============================================================= */
.tn-bracket-actions-mobile { display:none; }
.tn-mobile .tn-bracket-actions-desktop { display:none !important; }
.tn-mobile .tn-bracket-actions-mobile {
	display:flex;
	gap:8px;
	align-items:stretch;
	width:100%;
	flex-wrap:nowrap;
}
.tn-mobile .tn-bracket-gen-mobile {
	flex:1 1 auto;
	min-height:var(--tn-touch, 44px);
	font-size:15px;
	font-weight:700;
	justify-content:center;
}
.tn-mobile .tn-bracket-more-mobile {
	flex:0 0 auto;
	min-width:var(--tn-touch, 44px);
	min-height:var(--tn-touch, 44px);
	justify-content:center;
}

/* =============================================================
   B1 — Bracket generation step-wizard (mobile only).
   The wizard is a presentation layer injected into the EXISTING
   Add/Edit Bracket overlays by JS at open-time. It reads/writes the
   SAME hidden inputs (#tn-addbracket-style, …-method, …-participants,
   …-rings, …-seeding, …-bestof, …-duration, …-stylenote) and triggers
   the EXISTING submit button. All rules are .tn-mobile-scoped so the
   desktop modal (all fields at once) is byte-identical to before.
   ============================================================= */
/* When the wizard is active on mobile, hide the desktop field layout and
   the legacy footer (the wizard injects its own footer + steps). */
.tn-mobile .tn-overlay.tn-wiz-active .tn-field-row,
.tn-mobile .tn-overlay.tn-wiz-active .tn-advanced-toggle,
.tn-mobile .tn-overlay.tn-wiz-active #tn-addbracket-advanced,
.tn-mobile .tn-overlay.tn-wiz-active #tn-editbracket-advanced,
.tn-mobile .tn-overlay.tn-wiz-active .tn-modal-footer { display:none !important; }

/* Progress: counter + dot bar, sits under the sticky header. */
.tn-mobile .tn-wiz-progress { padding:4px 0 10px; }
.tn-mobile .tn-wiz-count { font-size:12px; font-weight:700; color:#718096; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
.tn-mobile .tn-wiz-dots { display:flex; gap:6px; align-items:center; }
.tn-mobile .tn-wiz-dot { flex:1 1 0; height:5px; border-radius:3px; background:#e2e8f0; transition:background .15s; }
.tn-mobile .tn-wiz-dot.tn-wiz-dot--done { background:#68d391; }
.tn-mobile .tn-wiz-dot.tn-wiz-dot--cur { background:#276749; }

/* A step panel — only one visible at a time. */
.tn-mobile .tn-wiz-step { display:none; }
.tn-mobile .tn-wiz-step.tn-wiz-step--active { display:block; }
.tn-mobile .tn-wiz-step-title { font-size:13px; font-weight:700; color:#4a5568; text-transform:uppercase; letter-spacing:0.5px; margin:4px 0 6px; }
.tn-mobile .tn-wiz-step-hint { font-size:13px; color:#718096; margin:0 0 12px; line-height:1.4; }

/* Large radio rows (replace native selects for high-value decisions). */
.tn-mobile .tn-wiz-opts { display:flex; flex-direction:column; gap:8px; }
.tn-mobile .tn-wiz-opt {
	display:flex; align-items:center; gap:12px;
	width:100%; min-height:var(--tn-touch); padding:12px 14px;
	border:1px solid #e2e8f0; border-radius:10px; background:#fff;
	font-size:15px; color:#1a202c; text-align:left; cursor:pointer;
	-webkit-tap-highlight-color:transparent;
}
.tn-mobile .tn-wiz-opt:active { background:#f0fff4; }
.tn-mobile .tn-wiz-opt.tn-wiz-opt--sel { border-color:#276749; box-shadow:0 0 0 2px rgba(39,103,73,0.15); background:#f0fff4; }
.tn-mobile .tn-wiz-opt-main { flex:1 1 auto; min-width:0; }
.tn-mobile .tn-wiz-opt-label { font-weight:600; }
.tn-mobile .tn-wiz-opt-sub { display:block; font-size:12px; color:#718096; font-weight:400; margin-top:2px; }
.tn-mobile .tn-wiz-opt-radio { flex:0 0 auto; width:20px; height:20px; border-radius:50%; border:2px solid #cbd5e0; position:relative; }
.tn-mobile .tn-wiz-opt--sel .tn-wiz-opt-radio { border-color:#276749; }
.tn-mobile .tn-wiz-opt--sel .tn-wiz-opt-radio::after { content:''; position:absolute; top:3px; left:3px; width:10px; height:10px; border-radius:50%; background:#276749; }

/* Numeric stepper (rings / custom duration). */
.tn-mobile .tn-wiz-stepper { display:flex; align-items:center; justify-content:center; gap:18px; margin:8px 0 4px; }
.tn-mobile .tn-wiz-stepper-btn { width:52px; height:52px; border-radius:12px; border:1px solid #cbd5e0; background:#fff; font-size:26px; line-height:1; color:#276749; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.tn-mobile .tn-wiz-stepper-btn:active { background:#f0fff4; }
.tn-mobile .tn-wiz-stepper-btn:disabled { opacity:0.4; cursor:default; }
.tn-mobile .tn-wiz-stepper-val { min-width:64px; text-align:center; font-size:28px; font-weight:700; color:#1a202c; }

/* Chip presets (duration). */
.tn-mobile .tn-wiz-chips { display:flex; flex-wrap:wrap; gap:8px; margin:4px 0 10px; }
.tn-mobile .tn-wiz-chip { min-height:40px; padding:8px 16px; border:1px solid #cbd5e0; border-radius:20px; background:#fff; font-size:14px; font-weight:600; color:#2d3748; cursor:pointer; -webkit-tap-highlight-color:transparent; }
.tn-mobile .tn-wiz-chip.tn-wiz-chip--sel { border-color:#276749; background:#f0fff4; color:#276749; }

/* Info box (e.g. "Ironman adds a Duration step"). */
.tn-mobile .tn-wiz-info { display:flex; gap:8px; align-items:flex-start; padding:10px 12px; border-radius:8px; background:#ebf8ff; color:#2c5282; font-size:13px; line-height:1.4; margin-top:12px; }
.tn-mobile .tn-wiz-info i { margin-top:2px; }

/* Review summary card. */
.tn-mobile .tn-wiz-review { padding:14px; border:1px solid #e2e8f0; border-radius:10px; background:#f7fafc; }
.tn-mobile .tn-wiz-review-line { font-size:14px; color:#2d3748; margin-bottom:6px; }
.tn-mobile .tn-wiz-review-line strong { color:#1a202c; }
.tn-mobile .tn-wiz-review-line:last-child { margin-bottom:0; }

/* Wizard chrome only renders inside an active wizard on mobile. The footer
   and step root live permanently in the DOM (built once), so they must be
   hidden on desktop and whenever the wizard is not engaged — otherwise a
   stray unstyled Back/Next bar would show on the desktop modal. */
.tn-bracket-wizard,
.tn-wiz-footer { display:none; }
.tn-mobile .tn-overlay.tn-wiz-active .tn-bracket-wizard { display:block; }
.tn-mobile .tn-overlay.tn-wiz-active .tn-wiz-footer { display:flex; }

/* Wizard footer (injected): sticky Back / Next | Generate. */
.tn-mobile .tn-wiz-footer {
	position:sticky; bottom:0; z-index:2; background:#fff;
	display:flex; gap:10px; padding:14px 20px;
	padding-bottom:calc(14px + env(safe-area-inset-bottom, 0px));
	border-top:1px solid #e2e8f0;
}
.tn-mobile .tn-wiz-footer .tn-wiz-back { flex:0 0 auto; }
.tn-mobile .tn-wiz-footer .tn-wiz-next { flex:1 1 auto; min-height:var(--tn-touch); }

/* ---- Dark-mode parity for the wizard chrome ---- */
html[data-theme="dark"] .tn-mobile .tn-wiz-count { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-wiz-dot { background:#4a5568; }
html[data-theme="dark"] .tn-mobile .tn-wiz-dot.tn-wiz-dot--done { background:#48bb78; }
html[data-theme="dark"] .tn-mobile .tn-wiz-dot.tn-wiz-dot--cur { background:#68d391; }
html[data-theme="dark"] .tn-mobile .tn-wiz-step-title { color:#cbd5e0; }
html[data-theme="dark"] .tn-mobile .tn-wiz-step-hint { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-wiz-opt { background:#2d3748; border-color:#4a5568; color:#f7fafc; }
html[data-theme="dark"] .tn-mobile .tn-wiz-opt:active,
html[data-theme="dark"] .tn-mobile .tn-wiz-opt.tn-wiz-opt--sel { background:#22432f; border-color:#68d391; }
html[data-theme="dark"] .tn-mobile .tn-wiz-opt-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-wiz-opt-radio { border-color:#718096; }
html[data-theme="dark"] .tn-mobile .tn-wiz-opt--sel .tn-wiz-opt-radio { border-color:#68d391; }
html[data-theme="dark"] .tn-mobile .tn-wiz-opt--sel .tn-wiz-opt-radio::after { background:#68d391; }
html[data-theme="dark"] .tn-mobile .tn-wiz-stepper-btn { background:#2d3748; border-color:#4a5568; color:#68d391; }
html[data-theme="dark"] .tn-mobile .tn-wiz-stepper-btn:active { background:#22432f; }
html[data-theme="dark"] .tn-mobile .tn-wiz-stepper-val { color:#f7fafc; }
html[data-theme="dark"] .tn-mobile .tn-wiz-chip { background:#2d3748; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .tn-mobile .tn-wiz-chip.tn-wiz-chip--sel { background:#22432f; border-color:#68d391; color:#68d391; }
html[data-theme="dark"] .tn-mobile .tn-wiz-info { background:#1c3a4f; color:#bee3f8; }
html[data-theme="dark"] .tn-mobile .tn-wiz-review { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .tn-mobile .tn-wiz-review-line { color:#e2e8f0; }
html[data-theme="dark"] .tn-mobile .tn-wiz-review-line strong { color:#f7fafc; }
html[data-theme="dark"] .tn-mobile .tn-wiz-footer { background:#1a202c; border-top-color:#2d3748; }

/* =============================================================
   PHASE C0 Task 4 — Card-deck primitive (`tn-deck`).
   A vertical stack rendered by TnMobile.deck: item 0 is the FULL lead
   card (`.tn-deck-card--full`); items 1..n are COMPACT cards
   (`.tn-deck-card--compact`). Promotion (compact -> full) animates
   height/opacity. The layout is gated under `.tn-mobile` (the deck is a
   mobile presentation); on desktop these rules don't apply and the
   container is laid out by its consumer. >=44px tap targets via
   --tn-touch. Dark-mode variants at the bottom.
   ============================================================= */
/* Mobile horizontal-overflow containment: the column layout used
   align-items:flex-start, which sized .tn-main to its widest content and
   leaked horizontal scroll to the whole page. Clamp main to the viewport,
   let the deck header controls wrap, and break the Track Fights card meta
   (NOW / ROUND Â· MATCH) onto its own line so fighter names keep full width
   instead of collapsing to a single-letter ellipsis. */
.tn-mobile .tn-layout { align-items:stretch; }
.tn-mobile .tn-nu-header { flex-wrap:wrap; }
.tn-mobile .tn-nu-card-track .tn-nu-pos-label { order:-2; }
.tn-mobile .tn-nu-card-track .tn-nu-match-num { flex-basis:100%; order:-1; }

.tn-mobile .tn-deck { display:flex; flex-direction:column; gap:var(--tn-deck-gap); }

/* Shared card chrome. */
.tn-mobile .tn-deck-card {
	box-sizing:border-box;
	border:1px solid #e2e8f0;
	border-radius:12px;
	background:#fff;
	-webkit-tap-highlight-color:transparent;
	/* Promotion animation: compact<->full smoothly grows/fades. */
	transition: opacity .22s ease, max-height .28s ease, padding .22s ease, background-color .2s ease;
	overflow:hidden;
}

/* FULL lead card — generous padding; holds the action surface. */
.tn-mobile .tn-deck-card--full {
	padding:16px;
	max-height:2000px;            /* large cap so content is never clipped */
	opacity:1;
	box-shadow:0 2px 10px rgba(0,0,0,0.06);
}
/* The deck chrome already provides the card surface; shed the inner
   .tn-nu-card's own border/shadow/bg so the lead isn't a card-in-card.
   Stack its contents (names above the actions) for a touch layout. */
.tn-mobile .tn-deck-card--full .tn-nu-card {
	background:transparent; border:0; box-shadow:none; padding:0;
	flex-wrap:wrap;
}
.tn-mobile .tn-deck-card--full .tn-nu-actions { width:100%; justify-content:flex-start; }
.tn-mobile .tn-deck-card--full .tn-nu-btn { min-height:var(--tn-touch); }

/* COMPACT on-deck cards — single dense row, tappable to promote. */
.tn-mobile .tn-deck-card--compact {
	display:flex;
	align-items:center;
	gap:8px;
	min-height:var(--tn-touch);   /* >=44px tap target */
	padding:8px 14px;
	cursor:pointer;
	opacity:.92;
	background:#f7fafc;
	font-size:14px;
}
.tn-mobile .tn-deck-card--compact:active { background:#edf2f7; }
.tn-mobile .tn-deck-card--compact:hover  { opacity:1; }

/* Inner one-line row for the Match Deck compact card: side label + names. */
.tn-mobile .tn-deck-compact-row {
	display:flex; align-items:center; gap:10px;
	width:100%; min-width:0;
}
.tn-mobile .tn-deck-compact-side {
	flex:0 0 auto;
	font-size:11px; font-weight:700; letter-spacing:.03em;
	color:#4a5568; background:#e2e8f0;
	padding:2px 7px; border-radius:9px; white-space:nowrap;
}
.tn-mobile .tn-deck-compact-vs {
	flex:1 1 auto; min-width:0;
	display:flex; align-items:center; gap:7px;
	overflow:hidden;
}
.tn-mobile .tn-deck-compact-p {
	min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
	font-weight:600; color:#2d3748;
}
.tn-mobile .tn-deck-compact-x { flex:0 0 auto; color:#a0aec0; font-size:12px; }
.tn-mobile .tn-deck-compact-row .tn-nu-p-seed {
	display:inline-block; margin-right:4px; font-size:11px; font-weight:700;
	color:#718096;
}

/* 3-dot deck position indicator (consumer-optional; rendered by consumer
   markup, styled here for convenience). */
.tn-mobile .tn-deck-dots { display:flex; justify-content:center; gap:7px; padding:4px 0 2px; }
.tn-mobile .tn-deck-dot { width:7px; height:7px; border-radius:50%; background:#cbd5e0; }
.tn-mobile .tn-deck-dot.tn-deck-dot--on { background:#38a169; }

/* ---- Dark-mode variants ---- */
html[data-theme="dark"] .tn-mobile .tn-deck-card { border-color:#2d3748; background:#1a202c; }
html[data-theme="dark"] .tn-mobile .tn-deck-card--full { box-shadow:0 2px 10px rgba(0,0,0,0.5); }
html[data-theme="dark"] .tn-mobile .tn-deck-card--compact { background:#222b38; }
html[data-theme="dark"] .tn-mobile .tn-deck-card--compact:active { background:#2d3748; }
html[data-theme="dark"] .tn-mobile .tn-deck-compact-side { color:#cbd5e0; background:#2d3748; }
html[data-theme="dark"] .tn-mobile .tn-deck-compact-p { color:#e2e8f0; }
html[data-theme="dark"] .tn-mobile .tn-deck-compact-x { color:#718096; }
html[data-theme="dark"] .tn-mobile .tn-deck-compact-row .tn-nu-p-seed { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-deck-dot { background:#4a5568; }
html[data-theme="dark"] .tn-mobile .tn-deck-dot.tn-deck-dot--on { background:#48bb78; }

/* =============================================
   MOBILE IRONMAN / KING-OF-THE-HILL DECK (Track R3)
   Dedicated mobile card for ironman: a focused "king vs next challenger"
   lead for the SELECTED ring, plus a ring selector and a compact on-deck
   queue. Rendered into #tn-nextup (above the desktop rings grid, which
   stays as the roster/standings + timer view). NOT the TnMobile.deck
   primitive: the ironman flow has a single recordable fight (king vs
   head-of-queue), so swipe-through-bouts does not apply — ring switching
   is the navigation instead. Gated under .tn-mobile. Dark-mode parity below.
   ============================================= */
.tn-mobile .tn-imd-wrap { display:flex; flex-direction:column; gap:10px; }
.tn-mobile .tn-imd-header { display:flex; align-items:center; gap:8px; }
.tn-mobile .tn-imd-title { font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#1a202c; }
.tn-mobile .tn-imd-sub { font-size:12px; color:#718096; flex:1; }
/* Ring selector — segmented control (few rings) */
.tn-mobile .tn-imd-rings { display:flex; gap:6px; flex-wrap:wrap; }
.tn-mobile .tn-imd-ring-btn {
	flex:1 1 auto; min-height:var(--tn-touch); min-width:64px;
	border:2px solid #e2e8f0; background:#fff; border-radius:10px;
	font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em;
	color:#4a5568; cursor:pointer; padding:6px 8px;
	-webkit-tap-highlight-color:transparent; transition:background .15s,border-color .15s,color .15s;
}
.tn-mobile .tn-imd-ring-btn.tn-imd-ring-on { color:#fff; }
/* Lead card: king vs next challenger for the focused ring */
.tn-mobile .tn-imd-lead {
	border:2px solid #e2e8f0; border-radius:14px; background:#fff; padding:14px;
	box-shadow:0 2px 10px rgba(0,0,0,0.06); display:flex; flex-direction:column; gap:12px;
}
.tn-mobile .tn-imd-lead-top { display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
.tn-mobile .tn-imd-fight { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#718096; }
.tn-mobile .tn-imd-king-badge { display:flex; align-items:center; gap:6px; background:#f0fff4; border:1px solid #9ae6b4; border-radius:20px; padding:4px 11px; }
.tn-mobile .tn-imd-king-crown { color:#d69e2e; font-size:12px; }
.tn-mobile .tn-imd-king-label { font-size:10px; font-weight:700; text-transform:uppercase; color:#276749; letter-spacing:.04em; }
.tn-mobile .tn-imd-king-name { font-size:13px; font-weight:800; color:#1a202c; }
.tn-mobile .tn-imd-king-streak { font-size:11px; font-weight:700; color:#276749; background:#c6f6d5; border-radius:10px; padding:1px 7px; }
.tn-mobile .tn-imd-vs { display:flex; align-items:stretch; gap:10px; }
.tn-mobile .tn-imd-fighter {
	flex:1 1 0; min-width:0; border:2px solid #e2e8f0; border-radius:12px; padding:12px 8px;
	text-align:center; background:#fbfcfe;
}
.tn-mobile .tn-imd-fighter.tn-imd-fighter-king { border-color:#3182ce; background:#ebf8ff; }
.tn-mobile .tn-imd-avatar { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:800; color:#fff; margin:0 auto 8px; }
.tn-mobile .tn-imd-fname { font-size:13px; font-weight:800; color:#1a202c; line-height:1.2; word-break:break-word; text-transform:uppercase; letter-spacing:.02em; }
.tn-mobile .tn-imd-frole { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#718096; margin-top:3px; }
.tn-mobile .tn-imd-fwins { font-size:11px; color:#718096; margin-top:4px; }
.tn-mobile .tn-imd-vs-sep { flex:0 0 auto; display:flex; align-items:center; font-size:12px; font-weight:800; color:#a0aec0; }
.tn-mobile .tn-imd-win-btns { display:flex; gap:8px; }
.tn-mobile .tn-imd-win-btn {
	flex:1 1 0; min-height:var(--tn-touch); border:none; border-radius:10px;
	font-size:13px; font-weight:800; color:#fff; cursor:pointer; padding:10px 8px;
	-webkit-tap-highlight-color:transparent; transition:opacity .15s, transform .1s;
	overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.tn-mobile .tn-imd-win-btn:active { transform:scale(0.98); }
.tn-mobile .tn-imd-win-btn:disabled { opacity:.5; cursor:default; }
.tn-mobile .tn-imd-win-btn-king { background:#3182ce; }
.tn-mobile .tn-imd-win-btn-chal { background:#276749; }
.tn-mobile .tn-imd-status { font-size:12px; min-height:16px; color:#718096; text-align:center; }
.tn-mobile .tn-imd-status.ok { color:#276749; font-weight:700; }
.tn-mobile .tn-imd-status.err { color:#e53e3e; font-weight:700; }
.tn-mobile .tn-imd-locked { font-size:12px; color:#a0aec0; text-align:center; padding:6px; }
/* On-deck queue */
.tn-mobile .tn-imd-ondeck-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#718096; margin-top:2px; }
.tn-mobile .tn-imd-ondeck { display:flex; flex-direction:column; gap:6px; }
.tn-mobile .tn-imd-chip {
	display:flex; align-items:center; gap:9px; min-height:38px;
	border:1px solid #e2e8f0; border-radius:10px; background:#f7fafc; padding:6px 12px;
}
.tn-mobile .tn-imd-chip-pos { flex:0 0 auto; font-size:11px; font-weight:800; color:#a0aec0; min-width:18px; }
.tn-mobile .tn-imd-chip-seed { flex:0 0 auto; font-size:11px; font-weight:700; color:#718096; }
.tn-mobile .tn-imd-chip-name { flex:1 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; color:#2d3748; font-size:13px; }
.tn-mobile .tn-imd-empty { font-size:12px; color:#a0aec0; text-align:center; padding:8px; }

/* ---- Dark-mode parity ---- */
html[data-theme="dark"] .tn-mobile .tn-imd-title { color:#f7fafc; }
html[data-theme="dark"] .tn-mobile .tn-imd-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-ring-btn { background:#2d3748; border-color:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-mobile .tn-imd-lead { background:#1a202c; border-color:#2d3748; box-shadow:0 2px 10px rgba(0,0,0,0.5); }
html[data-theme="dark"] .tn-mobile .tn-imd-fight { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-king-badge { background:rgba(56,161,105,0.18); border-color:rgba(154,230,180,0.4); }
html[data-theme="dark"] .tn-mobile .tn-imd-king-crown { color:#ecc94b; }
html[data-theme="dark"] .tn-mobile .tn-imd-king-label { color:#9ae6b4; }
html[data-theme="dark"] .tn-mobile .tn-imd-king-name { color:#f7fafc; }
html[data-theme="dark"] .tn-mobile .tn-imd-king-streak { color:#9ae6b4; background:rgba(56,161,105,0.3); }
html[data-theme="dark"] .tn-mobile .tn-imd-fighter { background:#222b38; border-color:#4a5568; }
html[data-theme="dark"] .tn-mobile .tn-imd-fighter.tn-imd-fighter-king { background:rgba(49,130,206,0.18); border-color:#3182ce; }
html[data-theme="dark"] .tn-mobile .tn-imd-fname { color:#f7fafc; }
html[data-theme="dark"] .tn-mobile .tn-imd-frole { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-fwins { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-vs-sep { color:#718096; }
html[data-theme="dark"] .tn-mobile .tn-imd-status { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-status.ok { color:#9ae6b4; }
html[data-theme="dark"] .tn-mobile .tn-imd-status.err { color:#fc8181; }
html[data-theme="dark"] .tn-mobile .tn-imd-locked { color:#718096; }
html[data-theme="dark"] .tn-mobile .tn-imd-ondeck-title { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-chip { background:#222b38; border-color:#4a5568; }
html[data-theme="dark"] .tn-mobile .tn-imd-chip-pos { color:#718096; }
html[data-theme="dark"] .tn-mobile .tn-imd-chip-seed { color:#a0aec0; }
html[data-theme="dark"] .tn-mobile .tn-imd-chip-name { color:#e2e8f0; }
html[data-theme="dark"] .tn-mobile .tn-imd-empty { color:#718096; }
</style>

<!-- =============================================
     ZONE 1: Hero
     ============================================= -->
<div id="tn-root">

<?php if ($isSpectator): ?>
<!-- Spectator Mode banner (Feature 1) — auto-updates via /version poll loop -->
<div class="tn-spectator-bar" id="tn-spectator-bar">
	<span class="tn-spectator-msg">
		<span class="tn-spectator-dot" aria-hidden="true"></span>
		<strong>Spectator Mode</strong> — Live updates
		<span class="tn-spectator-sync" id="tn-spectator-sync" role="status" aria-live="polite"></span>
	</span>
	<button type="button" class="tn-spectator-dismiss" id="tn-spectator-dismiss" data-tip="Hide this bar (updates keep running)" aria-label="Hide spectator bar">
		<i class="fas fa-times"></i>
	</button>
</div>
<?php endif; ?>

<!-- Focus-mode slim bar (hidden unless focus mode active) -->
<div class="tn-focus-bar">
	<div class="tn-focus-bar-title"><i class="fas fa-trophy"></i><?= htmlspecialchars($tName) ?></div>
	<button class="tn-focus-exit" onclick="tnToggleFocus()"><i class="fas fa-compress"></i> Exit focus</button>
</div>

<div class="tn-hero">
	<div class="tn-hero-content">
		<!-- Trophy icon -->
		<div class="tn-hero-icon">
			<i class="fas fa-trophy" style="color:#f6e05e"></i>
		</div>

		<!-- Name / breadcrumb / badges -->
		<div class="tn-hero-center">
			<div class="tn-breadcrumb">
				<?php if ($tKingdomId > 0): ?>
					<a href="<?= UIR ?>Kingdom/index/<?= $tKingdomId ?>"><i class="fas fa-crown" style="font-size:10px"></i> <?= htmlspecialchars($tKingdomName) ?></a>
					<span>/</span>
				<?php endif; ?>
				<?php if ($tParkId > 0): ?>
					<a href="<?= UIR ?>Park/index/<?= $tParkId ?>"><?= htmlspecialchars($tParkName) ?></a>
					<span>/</span>
				<?php endif; ?>
				<?php if (!empty($tEventName)): ?>
					<span><?= htmlspecialchars($tEventName) ?></span>
					<span>/</span>
				<?php endif; ?>
				<span style="color:rgba(255,255,255,0.5)">Tournament</span>
			</div>

			<h1><?= htmlspecialchars($tName) ?></h1>

			<div class="tn-hero-badges">
				<span class="tn-badge tn-badge-date">
					<i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($shortDate) ?>
				</span>
				<?php foreach ($heroStyles as $style): ?>
					<span class="tn-badge tn-badge-style"><?= htmlspecialchars($styleLabelMap[$style] ?? $style) ?></span>
				<?php endforeach; ?>
				<?php if (!empty($tEventName)): ?>
					<span class="tn-badge tn-badge-event"><i class="fas fa-flag"></i> <?= htmlspecialchars($tEventName) ?></span>
				<?php endif; ?>
			</div>
		</div>

		<!-- Action buttons -->
		<?php if ($canManage): ?>
		<div class="tn-hero-right">
			<div class="tn-hero-actions">
				<button class="tn-btn tn-btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)" onclick="tnOpenEditTournamentModal()">
					<i class="fas fa-pencil-alt"></i> Edit
				</button>
				<button class="tn-btn tn-btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)" onclick="tnOpenAddBracketModal()">
					<i class="fas fa-plus"></i> Add Bracket
				</button>
				<button class="tn-btn tn-btn-outline" style="color:#fff;border-color:rgba(255,255,255,0.4)" onclick="TnQuickBracket.openModal()" data-tip="Empty seeded bracket — type names and start">
					<i class="fas fa-bolt"></i> Quick Bracket
				</button>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>

<!-- =============================================
     Playtest warning
     ============================================= -->
<div class="tn-playtest-warn" role="alert">
	<i class="fas fa-flask tn-playtest-warn-icon" aria-hidden="true"></i>
	<div class="tn-playtest-warn-text">
		<strong>The Amtgard ORK Tournament module is currently in open playtesting.</strong>
		Use at your own risk: your tournaments may not record as expected, standings may not calculate properly, bugs may impact your brackets or data. You've been warned!
	</div>
</div>

<!-- =============================================
     ZONE 2: Stats Row
     ============================================= -->
<div class="tn-stats-row">
	<div class="tn-stat-card<?= $totalBrackets > 0 ? ' tn-stat-card-link' : '' ?>"<?= $totalBrackets > 0 ? ' onclick="tnActivateTab(\'brackets\')" role="button" tabindex="0" data-tn-keyclick aria-label="View brackets"' : '' ?>>
		<div class="tn-stat-icon"><i class="fas fa-sitemap"></i></div>
		<div class="tn-stat-value"><?= $totalBrackets ?></div>
		<div class="tn-stat-label">Bracket<?= $totalBrackets != 1 ? 's' : '' ?></div>
	</div>
	<div class="tn-stat-card<?= $totalParticipants > 0 ? ' tn-stat-card-link' : '' ?>"<?= $totalParticipants > 0 ? ' onclick="tnActivateTab(\'participants\')" role="button" tabindex="0" data-tn-keyclick aria-label="View participants"' : '' ?>>
		<div class="tn-stat-icon"><i class="fas fa-users"></i></div>
		<div class="tn-stat-value" id="tn-stat-participants"><?= $totalParticipants ?></div>
		<div class="tn-stat-label">Participant<?= $totalParticipants != 1 ? 's' : '' ?></div>
	</div>
	<div class="tn-stat-card<?= $totalMatches > 0 ? ' tn-stat-card-link' : '' ?>"<?= $totalMatches > 0 ? ' onclick="tnActivateTab(\'bracketviz\')" role="button" tabindex="0" data-tn-keyclick aria-label="View matches"' : '' ?>>
		<div class="tn-stat-icon"><i class="fas fa-khanda"></i></div>
		<div class="tn-stat-value"><?= $totalMatches ?></div>
		<div class="tn-stat-label">Match<?= $totalMatches != 1 ? 'es' : '' ?></div>
	</div>
	<div class="tn-stat-card">
		<div class="tn-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<?php if ($tDate && substr($tDate, 0, 10) !== '0000-00-00'): ?>
			<div class="tn-stat-value" style="font-size:1.1rem"><?= date('M j', strtotime($tDate)) ?></div>
			<div class="tn-stat-sub"><?= date('Y', strtotime($tDate)) ?></div>
		<?php else: ?>
			<div class="tn-stat-value">&mdash;</div>
		<?php endif; ?>
		<div class="tn-stat-label">Date</div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Main
     ============================================= -->
<div class="tn-layout">

	<!-- ---- Sidebar ---- -->
	<aside class="tn-sidebar">

		<!-- Tournament details -->
		<div class="tn-card">
			<h4><i class="fas fa-info-circle"></i> Details</h4>
			<div class="tn-detail-row">
				<span class="tn-detail-icon"><i class="fas fa-calendar-alt"></i></span>
				<span class="tn-detail-text"><?= htmlspecialchars($displayDate) ?></span>
			</div>
			<?php if ($tParkId > 0): ?>
			<div class="tn-detail-row">
				<span class="tn-detail-icon"><i class="fas fa-map-marker-alt"></i></span>
				<span class="tn-detail-text"><a href="<?= UIR ?>Park/index/<?= $tParkId ?>"><?= htmlspecialchars($tParkName) ?></a></span>
			</div>
			<?php endif; ?>
			<?php if ($tKingdomId > 0): ?>
			<div class="tn-detail-row">
				<span class="tn-detail-icon"><i class="fas fa-crown"></i></span>
				<span class="tn-detail-text"><a href="<?= UIR ?>Kingdom/index/<?= $tKingdomId ?>"><?= htmlspecialchars($tKingdomName) ?></a></span>
			</div>
			<?php endif; ?>
			<?php if (!empty($tEventName)): ?>
			<div class="tn-detail-row">
				<span class="tn-detail-icon"><i class="fas fa-flag"></i></span>
				<span class="tn-detail-text"><?php if ($tEventId > 0 && $tECDId > 0): ?><a href="<?= UIR ?>Event/detail/<?= $tEventId ?>/<?= $tECDId ?>"><?= htmlspecialchars($tEventName) ?></a><?php else: ?><?= htmlspecialchars($tEventName) ?><?php endif; ?></span>
			</div>
			<?php endif; ?>
			<?php if (!empty($tUrl)): ?>
			<div class="tn-detail-row">
				<span class="tn-detail-icon"><i class="fas fa-globe"></i></span>
				<span class="tn-detail-text"><?php if ($tUrlIsLink): ?><a href="<?= htmlspecialchars($tUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($tUrl) ?></a><?php else: ?><?= htmlspecialchars($tUrl) ?><?php endif; ?></span>
			</div>
			<?php endif; ?>
		</div>

		<!-- Bracket summary -->
		<?php if ($totalBrackets > 0): ?>
		<div class="tn-card">
			<h4><i class="fas fa-sitemap"></i> Brackets</h4>
			<ul class="tn-participant-list">
				<?php foreach ($brackets as $i => $b): ?>
				<li style="cursor:pointer" role="button" tabindex="0" data-tn-keyclick onclick="tnActivateTab('brackets');tnScrollToBracket(<?= (int)$b['BracketId'] ?>)">
					<span class="tn-participant-seed"><?= $i + 1 ?></span>
					<span>
						<strong><?= htmlspecialchars($styleLabelMap[$b['Style']] ?? $b['Style']) ?><?= trim($b['StyleNote'] ?? '') !== '' ? ' (' . htmlspecialchars(trim($b['StyleNote'])) . ')' : '' ?></strong>
						<span style="color:#a0aec0;font-size:11px;margin-left:4px"><?= htmlspecialchars($methodLabelMap[$b['Method']] ?? $b['Method']) ?></span>
					</span>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

	</aside>

	<!-- ---- Main Tabbed Content ---- -->
	<div class="tn-main">
		<div class="tn-tabs">

			<ul class="tn-tab-nav" id="tn-tab-nav" role="tablist" aria-label="Tournament sections">
				<li data-tntab="about" class="tn-tab-active" role="tab" id="tn-tabhdr-about" aria-selected="true" aria-controls="tn-tab-about" tabindex="0" onclick="tnActivateTab('about')">
					<i class="fas fa-info-circle"></i> About
				</li>
				<li data-tntab="brackets" role="tab" id="tn-tabhdr-brackets" aria-selected="false" aria-controls="tn-tab-brackets" tabindex="-1" onclick="tnActivateTab('brackets')">
					<i class="fas fa-sitemap"></i> Brackets
					<span class="tn-tab-count">(<?= $totalBrackets ?>)</span>
				</li>
				<li data-tntab="participants" role="tab" id="tn-tabhdr-participants" aria-selected="false" aria-controls="tn-tab-participants" tabindex="-1" onclick="tnActivateTab('participants')">
					<i class="fas fa-users"></i> Participants
					<span class="tn-tab-count">(<?= $totalParticipants ?>)</span>
				</li>
				<li data-tntab="bracketviz" role="tab" id="tn-tabhdr-bracketviz" aria-selected="false" aria-controls="tn-tab-bracketviz" tabindex="-1" onclick="tnActivateTab('bracketviz')">
					<i class="fas fa-project-diagram"></i> Run Tournament
				</li>
				<li class="tn-focus-toggle" role="button" tabindex="0" data-tn-keyclick onclick="tnToggleFocus()" data-tip="Hide everything but the bracket">
					<i class="fas fa-expand"></i> Focus
				</li>
				<li data-tntab="standings" role="tab" id="tn-tabhdr-standings" aria-selected="false" aria-controls="tn-tab-standings" tabindex="-1" onclick="tnActivateTab('standings')">
					<i class="fas fa-medal"></i> Standings
				</li>
			</ul>

			<!-- About Tab -->
			<div class="tn-tab-panel" id="tn-tab-about" role="tabpanel" aria-labelledby="tn-tabhdr-about" tabindex="0">
				<?php if (!empty($tDescription)): ?>
				<div style="font-size:14px;line-height:1.6;color:#4a5568;margin-bottom:14px">
					<?= nl2br(htmlspecialchars($tDescription)) ?>
				</div>
				<?php else: ?>
				<div class="tn-empty">No description provided.</div>
				<?php endif; ?>

				<?php if ($tUrlIsLink): ?>
				<div style="margin-top:12px">
					<a href="<?= htmlspecialchars($tUrl) ?>" target="_blank" rel="noopener noreferrer" class="tn-btn tn-btn-outline tn-btn-sm">
						<i class="fas fa-external-link-alt"></i> Tournament Website
					</a>
				</div>
				<?php elseif (!empty($tUrl)): ?>
				<div style="margin-top:12px;font-size:13px;color:#718096">
					<i class="fas fa-globe"></i> <?= htmlspecialchars($tUrl) ?>
				</div>
				<?php endif; ?>

				<?php if ($canManageReeves): ?>
				<!-- Tournament Reeves panel (Feature 2) -->
				<div class="tn-reeves-card" id="tn-reeves-card">
					<div class="tn-reeves-head">
						<h4 class="tn-reeves-title"><i class="fas fa-user-shield"></i> Tournament Reeves</h4>
						<button type="button" class="tn-btn tn-btn-primary tn-btn-sm" id="tn-reeve-add-btn">
							<i class="fas fa-plus"></i> Add Reeve
						</button>
					</div>
					<p class="tn-reeves-sub">Reeves help run this tournament. <strong>Organizers</strong> can do everything you can; <strong>Bracket Runners</strong> can only record match results.</p>
					<ul class="tn-reeves-list" id="tn-reeves-list">
						<?php if (empty($reeves)): ?>
						<li class="tn-reeves-empty" id="tn-reeves-empty">No reeves assigned yet.</li>
						<?php else: foreach ($reeves as $_rv): ?>
						<?php $_rvRole = $_rv['Role'] ?? 'bracket_runner'; ?>
						<li class="tn-reeve-row" data-mundane-id="<?= (int)$_rv['MundaneId'] ?>">
							<span class="tn-reeve-persona"><a href="<?= UIR ?>Player/profile/<?= (int)$_rv['MundaneId'] ?>"><?= htmlspecialchars($_rv['Persona'] ?? ('#' . (int)$_rv['MundaneId'])) ?></a></span>
							<span class="tn-reeve-badge tn-reeve-badge-<?= htmlspecialchars($_rvRole) ?>"><?= htmlspecialchars($reeveRoleLabels[$_rvRole] ?? $_rvRole) ?></span>
							<button type="button" class="tn-reeve-remove" data-mundane-id="<?= (int)$_rv['MundaneId'] ?>" data-persona="<?= htmlspecialchars($_rv['Persona'] ?? '') ?>" data-tip="Remove reeve" aria-label="Remove reeve <?= htmlspecialchars($_rv['Persona'] ?? '') ?>"><i class="fas fa-times"></i></button>
						</li>
						<?php endforeach; endif; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>

			<!-- Brackets Tab -->
			<div class="tn-tab-panel" id="tn-tab-brackets" role="tabpanel" aria-labelledby="tn-tabhdr-brackets" tabindex="0" style="display:none">
				<?php if ($totalBrackets > 1): ?>
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:10px">
					<button class="tn-btn tn-btn-ghost tn-btn-sm" onclick="tnToggleAllBrackets()" id="tn-toggle-all-btn">
						<i class="fas fa-compress-arrows-alt"></i> <span>Collapse All</span>
					</button>
					<?php if ($canManage): ?>
					<div class="tn-qb-btnstack">
					<button class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnOpenAddBracketModal()">
						<i class="fas fa-plus"></i> Add Bracket
					</button>
					<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="TnQuickBracket.openModal()" data-tip="Empty seeded bracket — type names and start">
						<i class="fas fa-bolt"></i> Quick Bracket
					</button>
					</div>
					<?php endif; ?>
				</div>
				<?php elseif ($canManage): ?>
				<div style="display:flex;justify-content:flex-end;margin-bottom:14px">
					<div class="tn-qb-btnstack">
					<button class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnOpenAddBracketModal()">
						<i class="fas fa-plus"></i> Add Bracket
					</button>
					<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="TnQuickBracket.openModal()" data-tip="Empty seeded bracket — type names and start">
						<i class="fas fa-bolt"></i> Quick Bracket
					</button>
					</div>
				</div>
				<?php endif; ?>

				<?php if ($totalBrackets === 0): ?>
				<div class="tn-empty">No brackets yet.<?= $canManage ? ' Use "Add Bracket" or "Quick Bracket" to create one.' : '' ?></div>
				<?php else: ?>
					<?php foreach ($bracketData as $bid => $bd): ?>
					<?php $b = $bd['Bracket']; $pList = $bd['Participants']; $mList = $bd['Matches']; ?>
					<div class="tn-bracket-card" id="tn-bracket-<?= $bid ?>" data-method="<?= htmlspecialchars($b['Method']) ?>" data-status="<?= htmlspecialchars($b['Status'] ?: 'setup') ?>">
						<div class="tn-bracket-header">
							<button class="tn-bracket-toggle" onclick="tnToggleBracket(<?= $bid ?>)" data-tip="Collapse/expand"><i class="fas fa-chevron-down"></i></button>
							<div style="flex:1">
								<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
							<h4 style="margin:0"><?= htmlspecialchars($styleLabelMap[$b['Style']] ?? $b['Style']) ?><?= trim($b['StyleNote'] ?? '') !== '' ? ' (' . htmlspecialchars(trim($b['StyleNote'])) . ')' : '' ?></h4>
							<?php
							$_bStatus = $b['Status'] ?? 'setup';
							if ($_bStatus === '' || $_bStatus === 'setup'):
							?>
							<span class="tn-bracket-status tn-bracket-status-setup"><i class="fas fa-cog"></i> Setup</span>
							<?php elseif ($_bStatus === 'active'): ?>
							<span class="tn-bracket-status tn-bracket-status-active"><i class="fas fa-circle" style="font-size:8px"></i> Live</span>
							<?php elseif ($_bStatus === 'complete'): ?>
							<span class="tn-bracket-status tn-bracket-status-complete"><i class="fas fa-check-circle"></i> Complete</span>
							<?php elseif ($_bStatus === 'finalized'): ?>
							<span class="tn-bracket-status tn-bracket-status-finalized"><i class="fas fa-lock"></i> Finalized</span>
							<?php endif; ?>
						</div>
								<div class="tn-bracket-meta">
									<span><i class="fas fa-project-diagram"></i> <?= htmlspecialchars($methodLabelMap[$b['Method']] ?? $b['Method']) ?></span>
									<?php if (($b['Participants'] ?? 'individual') === 'team'):
										$_teamIds = array_unique(array_column($pList, 'ParticipantId'));
										$_teamCount = count($_teamIds);
										$_memberCount = array_sum(array_map(fn($p) => count($p['Members'] ?? []), $pList));
									?>
									<span data-tip="Teams"><i class="fas fa-users" style="color:#3182ce"></i> <?= $_teamCount ?></span>
									<span data-tip="Individual Members"><i class="fas fa-user" style="color:#805ad5"></i> <?= $_memberCount ?></span>
									<?php else: ?>
									<span data-tip="Individual Participants"><i class="fas fa-user" style="color:#805ad5"></i> <?= count($pList) ?></span>
									<?php endif; ?>
									<?php if ((int)$b['Rings'] > 1): ?>
									<span><i class="fas fa-circle"></i> <?= (int)$b['Rings'] ?> <?= ($b['Method'] ?? '') === 'swiss' ? 'Swiss rounds' : 'rings' ?></span>
									<?php endif; ?>
								</div>
								<div class="tn-bracket-meta">
									<?php $seedingLabels = ['warrior'=>'Orders of the Warrior','glicko2'=>'Performance Score','random-manual'=>'Random + Manual','glicko2-manual'=>'Performance + Manual']; ?>
									<?php if (isset($seedingLabels[$b['Seeding']])): ?>
									<span><i class="fas fa-random"></i> Seeding: <?= htmlspecialchars($seedingLabels[$b['Seeding']]) ?></span>
									<?php endif; ?>
								</div>
							</div>
							<?php if ($canManage): ?>
							<div class="tn-bracket-actions-desktop" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnOpenEditBracketModal(<?= $bid ?>, <?= htmlspecialchars(json_encode(['style'=>$b['Style'],'styleNote'=>$b['StyleNote'],'method'=>$b['Method'],'rings'=>(int)$b['Rings'],'participants'=>$b['Participants'],'seeding'=>$b['Seeding'],'durationMinutes'=>(int)($b['DurationMinutes']??0),'bestOf'=>(int)($b['BestOf']??1),'pointRounds'=>(int)($b['PointRounds']??3),'pointMode'=>($b['PointMode']??'fixed'),'pointScale'=>($b['PointScale']??'5,3,1,0')], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES) ?>)">
									<i class="fas fa-pencil-alt"></i> Edit
								</button>
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnCopyBracket(<?= $bid ?>, <?= $tid ?>)" data-tip="Duplicate this bracket with its participants" data-tip-right>
									<i class="fas fa-copy"></i> Copy
								</button>
								<?php $_rosterOpen = in_array($b['Status'] ?? '', ['setup', ''], true); ?>
								<?php if (($b['Participants'] ?? 'individual') === 'team'): ?>
								<?php if ($_rosterOpen): ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnOpenAddTeamModal(<?= $bid ?>, <?= $tid ?>)">
									<i class="fas fa-users"></i> Add Team
								</button>
								<?php else: ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" disabled data-tip="Teams are locked once the bracket starts.">
									<i class="fas fa-users"></i> Add Team
								</button>
								<?php endif; ?>
								<?php if ($b['Status'] === 'setup'): ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnOpenAssignTeamsModal(<?= $bid ?>)" data-tip="Assign registered teams to this bracket">
									<i class="fas fa-users"></i> Assign Teams
								</button>
								<?php else: ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" disabled data-tip="Teams are locked once the bracket starts.">
									<i class="fas fa-users"></i> Assign Teams
								</button>
								<?php endif; ?>
								<?php else: ?>
								<?php if ($_rosterOpen): ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnOpenAddParticipantModal(<?= $bid ?>, <?= $tid ?>)">
									<i class="fas fa-user-plus"></i> Add Participant
								</button>
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnOpenBulkAddModal(<?= $bid ?>, <?= $tid ?>)" data-tip="Paste a list of aliases, one per line">
									<i class="fas fa-clipboard-list"></i> Paste Roster
								</button>
								<?php else: ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" disabled data-tip="Participants are locked once the bracket starts.">
									<i class="fas fa-user-plus"></i> Add Participant
								</button>
								<button class="tn-btn tn-btn-outline tn-btn-sm" disabled data-tip="Participants are locked once the bracket starts.">
									<i class="fas fa-clipboard-list"></i> Paste Roster
								</button>
								<?php endif; ?>
								<?php if ($b['Status'] === 'setup'): ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" onclick="tnOpenAssignParticipantsModal(<?= $bid ?>)" data-tip="Assign registered participants to this bracket">
									<i class="fas fa-user-check"></i> Assign Participants
								</button>
								<?php else: ?>
								<button class="tn-btn tn-btn-outline tn-btn-sm" disabled data-tip="Participants are locked once the bracket starts.">
									<i class="fas fa-user-check"></i> Assign Participants
								</button>
								<?php endif; ?>
								<?php endif; ?>
								<?php
									// Generate only while in setup; Re-generate only for an active bracket that
									// has a draw. Active points/ironman brackets have no pre-built matches, so
									// neither applies (the server refuses) — Run Bracket covers them.
									$_isRegen = $b['Status'] === 'active' && count($mList) > 0;
									$_isSetup = in_array($b['Status'] ?? '', ['setup', ''], true);
								?>
								<?php if (count($pList) >= 2 && ($_isSetup || $_isRegen)): ?>
								<button
									class="tn-btn tn-btn-primary tn-btn-sm<?= $_isRegen ? ' tn-regen-btn' : '' ?>"
									<?php if ($_isRegen): ?>data-bid="<?= $bid ?>" data-tid="<?= $tid ?>" data-match-count="<?= count($mList) ?>" onclick="tnRegenArm(this, event)"<?php else: ?>onclick="tnGenerateMatches(<?= $bid ?>, <?= $tid ?>)"<?php endif; ?>>
									<i class="fas fa-play"></i> <?= $_isRegen ? 'Re-generate' : 'Generate' ?>
								</button>
								<?php endif; ?>
								<?php if (($b['Method'] ?? '') === 'points' && ($b['Status'] ?? '') === 'active'): ?>
								<button class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnFinalizePointsBracket(<?= $bid ?>)" data-tip="Lock scoring and record the final standings">
									<i class="fas fa-flag-checkered"></i> Finalize bracket
								</button>
								<?php endif; ?>
								<button class="tn-btn tn-btn-danger tn-btn-sm" onclick="tnDeleteBracket(<?= $bid ?>, <?= $tid ?>)" data-tip="Delete bracket" aria-label="Delete bracket">
									<i class="fas fa-times"></i>
								</button>
							</div>
							<?php
								// --- Mobile (.tn-mobile) action bar: prominent Generate + "More" sheet. ---
								// Desktop cluster above is hidden under .tn-mobile; this bar is shown.
								$_mIsTeam = ($b['Participants'] ?? 'individual') === 'team';
								$_mIsRegen = $b['Status'] === 'active' && count($mList) > 0;
								$_mCanGen = count($pList) >= 2 && (in_array($b['Status'] ?? '', ['setup', ''], true) || $_mIsRegen);
								$_mEditJson = htmlspecialchars(json_encode(['style'=>$b['Style'],'styleNote'=>$b['StyleNote'],'method'=>$b['Method'],'rings'=>(int)$b['Rings'],'participants'=>$b['Participants'],'seeding'=>$b['Seeding'],'durationMinutes'=>(int)($b['DurationMinutes']??0),'bestOf'=>(int)($b['BestOf']??1),'pointRounds'=>(int)($b['PointRounds']??3),'pointMode'=>($b['PointMode']??'fixed'),'pointScale'=>($b['PointScale']??'5,3,1,0')], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT), ENT_QUOTES); // JS object literal for tnOpenEditBracketModal
							?>
							<div class="tn-bracket-actions-mobile">
								<?php if ($_mCanGen): ?>
								<button type="button" class="tn-btn tn-btn-primary tn-bracket-gen-mobile"
									onclick="tnMobileGenerate(<?= $bid ?>, <?= $tid ?>, <?= $_mIsRegen ? 'true' : 'false' ?>, <?= count($mList) ?>)">
									<i class="fas fa-play"></i> <?= $_mIsRegen ? 'Re-generate' : 'Generate' ?>
								</button>
								<?php endif; ?>
								<button type="button" class="tn-btn tn-btn-outline tn-bracket-more-mobile"
									onclick="tnMobileBracketMore(<?= $bid ?>, <?= $tid ?>, <?= $_mIsTeam ? 'true' : 'false' ?>, <?= $_mEditJson ?>)" aria-label="More bracket actions">
									<i class="fas fa-ellipsis-h"></i>
								</button>
							</div>
							<?php endif; ?>
							<?php if (count($mList) > 0 || $b['Status'] === 'active'): ?>
							<button class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnGoToBracket(<?= $bid ?>)" style="margin-left:auto">
								<?php if ($canManage || $canRecordResult): ?>
								<i class="fas fa-play"></i> Run Bracket
								<?php else: ?>
								<i class="fas fa-eye"></i> View Bracket
								<?php endif; ?>
							</button>
							<?php endif; ?>
						</div>
						<div class="tn-bracket-body">
							<?php if (count($pList) === 0): ?>
							<div class="tn-empty">No participants yet.</div>
							<?php elseif (in_array($b['Status'], ['complete', 'finalized'], true) && !empty($standingsData[$bid])): ?>
<?php
	// Build lookup from ParticipantId -> full participant data (for pills + park)
	$_pLookup = [];
	foreach ($pList as $_part) { $_pLookup[(int)$_part['ParticipantId']] = $_part; }
	// Build competition-ranked placement rows with spacers for tied positions
	$_standings = $standingsData[$bid];
	$_plRows = [];
	$_plNum  = 1;
	$_i      = 0;
	// Single/double elimination: the top places come from the bracket itself (final,
	// 3rd-place match / losers final) via TournamentReport::GetBracketPlacements; only
	// the remaining entrants fall back to Points+Losses grouping below.
	if (in_array($b['Method'] ?? '', ['single', 'double'], true) && !empty($placementsData[$bid])) {
		// Single elim without a resolved 3rd-place match: the server's semifinal fallback
		// picks one loser as 3rd by match_id, not by play — take only 1st/2nd from it and
		// let the grouping below rank the semifinal losers (shared 3rd).
		$_plMax = PHP_INT_MAX;
		if (($b['Method'] ?? '') === 'single') {
			$_plMax = 2;
			foreach ($mList as $_tm) {
				if (($_tm['BracketSide'] ?? '') === 'tiebreaker-3rd' && !empty($_tm['Result']) && empty($_tm['Voided'])) { $_plMax = PHP_INT_MAX; break; }
			}
		}
		$_sById = [];
		foreach ($_standings as $_srow) { $_sById[(int)$_srow['ParticipantId']] = $_srow; }
		foreach ($placementsData[$bid] as $_plc) {
			$_plcId = (int)$_plc['ParticipantId'];
			if ((int)$_plc['Place'] > $_plMax) continue;
			if (!isset($_sById[$_plcId])) continue;
			$_plRows[] = ['type' => 'entry', 'pl' => (int)$_plc['Place'], 'data' => $_sById[$_plcId]];
			$_plNum = max($_plNum, (int)$_plc['Place'] + 1);
			unset($_sById[$_plcId]);
		}
		$_standings = array_values(array_filter($_standings, function ($_srow) use ($_sById) { return isset($_sById[(int)$_srow['ParticipantId']]); }));
	}
	while ($_i < count($_standings)) {
		$_j = $_i;
		while ($_j < count($_standings) && $_standings[$_j]['Points'] === $_standings[$_i]['Points'] && $_standings[$_j]['Losses'] === $_standings[$_i]['Losses']) $_j++;
		$_gs = $_j - $_i;
		// Tied entrants: same secondary order as the Run-view standings (seed, then alias).
		$_grp = array_slice($_standings, $_i, $_gs);
		usort($_grp, function ($_a, $_b) use ($_pLookup) {
			$_sa = (int)($_pLookup[(int)$_a['ParticipantId']]['Seed'] ?? 0) ?: 9999;
			$_sb = (int)($_pLookup[(int)$_b['ParticipantId']]['Seed'] ?? 0) ?: 9999;
			$_pa = $_pLookup[(int)$_a['ParticipantId']] ?? [];
			$_pb = $_pLookup[(int)$_b['ParticipantId']] ?? [];
			$_na = (string)(($_pa['Alias'] ?? '') ?: ($_pa['Persona'] ?? ''));
			$_nb = (string)(($_pb['Alias'] ?? '') ?: ($_pb['Persona'] ?? ''));
			return $_sa !== $_sb ? $_sa - $_sb : strcasecmp($_na, $_nb);
		});
		foreach ($_grp as $_grow) {
			$_plRows[] = ['type' => 'entry', 'pl' => $_plNum, 'data' => $_grow];
		}
		if ($_gs > 1) for ($_k = 0; $_k < $_gs - 1; $_k++) $_plRows[] = ['type' => 'spacer'];
		$_plNum += $_gs;
		$_i = $_j;
	}
?>
							<ul class="tn-placement-list">
								<?php foreach ($_plRows as $_row): ?>
								<?php if ($_row['type'] === 'spacer'): ?>
								<li class="tn-placement-spacer" aria-hidden="true"></li>
								<?php else: $_pd = $_row['data']; $_pp = $_pLookup[(int)$_pd['ParticipantId']] ?? []; $_plStatus = $_pd['Status'] ?? ($_pp['Status'] ?? 'active'); ?>
								<li>
									<span class="tn-placement-num"><?= tnOrdinal($_row['pl']) ?></span>
									<?php if ($_pd['IsTeam'] ?? false): ?>
									<span style="flex:1"><?= htmlspecialchars($_pd['Alias'] ?? '—') ?>
										<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle"><span class="tn-pill tn-pill-team-wl" data-tip="Team warrior level">⚔ <?= (int)($_pd['TeamWarriorLevel'] ?? 0) ?></span></span>
										<?php if ($_plStatus === 'withdrawn'): ?><span class="tn-pstatus-pill tn-pstatus-pill-withdrawn">WD</span><?php elseif ($_plStatus === 'disqualified'): ?><span class="tn-pstatus-pill tn-pstatus-pill-disqualified">DQ</span><?php endif; ?>
									</span>
									<?php if (!empty($_pd['Members'])): ?>
									<button class="tn-team-roster-btn" onclick="tnToggleRoster(this)" data-tip="Show/hide team roster">&#9658; <?= count($_pd['Members']) ?></button>
									<?php endif; ?>
									<?php else: ?>
									<span style="flex:1"><?= htmlspecialchars($_pd['Alias'] ?? '—') ?><?= !empty($_pp) ? tnParticipantPills($_pp) : '' ?><?php if ($_plStatus === 'withdrawn'): ?><span class="tn-pstatus-pill tn-pstatus-pill-withdrawn">WD</span><?php elseif ($_plStatus === 'disqualified'): ?><span class="tn-pstatus-pill tn-pstatus-pill-disqualified">DQ</span><?php endif; ?></span>
									<?php $_parkDisp = $_pp['ParkName'] ?? $_pd['ParkName'] ?? ''; ?>
									<?php if (!empty($_parkDisp)): ?>
									<span style="font-size:11px;color:#a0aec0"><?= htmlspecialchars($_parkDisp) ?></span>
									<?php endif; ?>
									<?php endif; ?>
								</li>
								<?php if (($_pd['IsTeam'] ?? false) && !empty($_pd['Members'])): ?>
								<li class="tn-team-roster-sub" style="display:none;flex-wrap:wrap;padding:4px 6px 6px 42px;gap:0">
									<?php foreach ($_pd['Members'] as $_tm): ?>
									<span class="tn-roster-member"><?= htmlspecialchars($_tm['Persona'] ?? '') ?><span class="tn-pill tn-pill-team-wl" data-tip="Warrior level" style="margin-left:3px">⚔<?= (int)$_tm['WarriorLevel'] ?></span></span>
									<?php endforeach; ?>
								</li>
								<?php endif; ?>
								<?php endif; ?>
								<?php endforeach; ?>
							</ul>
							<?php else: ?>
<?php $isDnd = $canManage && in_array($b['Seeding'] ?? '', ['manual','random-manual','glicko2-manual']); ?>
							<ul class="tn-participant-list<?= $isDnd ? ' tn-dnd-list' : '' ?>"<?= $isDnd ? ' data-bracket-id="' . $bid . '"' : '' ?>>
								<?php foreach ($pList as $i => $p): ?>
								<?php $_pStatus = $p['Status'] ?? 'active'; $_pStatusClass = ($_pStatus !== 'active') ? ' tn-pstatus-' . htmlspecialchars($_pStatus) : ''; ?>
								<li class="<?= $_pStatusClass ?>"<?= $isDnd ? ' data-pid="' . (int)$p['ParticipantId'] . '"' : '' ?> data-participant-id="<?= (int)$p['ParticipantId'] ?>" data-status="<?= htmlspecialchars($_pStatus) ?>">
																		<?php if ($isDnd): ?><span class="tn-dnd-handle" data-tn-no-swipe><i class="fas fa-grip-lines"></i></span><?php endif; ?>
									<?php $_pidBadge = tnPidShield((int)($p['ParticipantNumber'] ?? 0)); ?><?= $_pidBadge !== '' ? $_pidBadge : '<span class="' . ($isDnd ? 'tn-seed-enhanced' : 'tn-participant-seed') . '">' . ($i + 1) . '</span>' ?>
								<?php if ($p['IsTeam'] ?? false): ?>
									<span style="flex:1"><span class="tn-alias-text" data-alias="<?= htmlspecialchars($p['Alias'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($p['Alias'] ?: '—') ?></span><?php if ($canManage): ?><button class="tn-alias-edit" data-pid="<?= (int)$p['ParticipantId'] ?>" data-bid="<?= $bid ?>" data-tip="Edit name" aria-label="Edit name" onclick="tnEditAlias(this)"><i class="fas fa-pen"></i></button><?php endif; ?>
										<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle"><span class="tn-pill tn-pill-team-wl" data-tip="Team warrior level">⚔ <?= (int)($p['WarriorLevel'] ?? 0) ?></span></span>
									</span>
									<?php if (!empty($p['Members'])): ?>
									<button class="tn-team-roster-btn" onclick="tnToggleRoster(this)" data-tip="Show/hide team roster">&#9658; <?= count($p['Members']) ?></button>
									<?php endif; ?>
									<?php if ($canManage): ?>
									<span class="tn-status-wrap"><button type="button" class="tn-status-btn" onclick="tnToggleParticipantMenu(this)" data-tip="Set status" aria-label="Set status" aria-haspopup="menu" aria-expanded="false">&#8942;</button><div class="tn-status-menu" role="menu" aria-label="Participant status"><button type="button" role="menuitem" tabindex="-1" class="tn-status-menu-item<?= $_pStatus==='active'?' tn-sm-active':'' ?>" onclick="tnSetParticipantStatus(<?= (int)$p['ParticipantId'] ?>, 'active', <?= $bid ?>, this)"><span class="tn-sm-dot tn-sm-dot-active"></span>Active</button><button type="button" role="menuitem" tabindex="-1" class="tn-status-menu-item<?= $_pStatus==='withdrawn'?' tn-sm-active':'' ?>" onclick="tnWithdrawIntent(<?= (int)$p['ParticipantId'] ?>, 'withdrawn', <?= $bid ?>, this)"><span class="tn-sm-dot tn-sm-dot-withdrawn"></span>Withdrawn</button><button type="button" role="menuitem" tabindex="-1" class="tn-status-menu-item<?= $_pStatus==='disqualified'?' tn-sm-active':'' ?>" onclick="tnWithdrawIntent(<?= (int)$p['ParticipantId'] ?>, 'disqualified', <?= $bid ?>, this)"><span class="tn-sm-dot tn-sm-dot-disqualified"></span>Disqualified</button></div></span>
									<?php if (in_array($b['Status'] ?? '', ['setup', ''], true)): ?><button class="tn-remove-participant" data-pid="<?= (int)$p['ParticipantId'] ?>" data-bid="<?= $bid ?>" data-tid="<?= $tid ?>" data-tip="Remove participant" onclick="tnRemoveParticipant(this)">&times;</button><?php else: ?><button class="tn-remove-participant" disabled data-tip="Participants are locked once the bracket starts." aria-label="Remove participant (locked)">&times;</button><?php endif; ?>
									<?php endif; ?>
								<?php else: ?>
									<span style="flex:1">
										<?php if (!empty($p['Persona'])): ?>
											<?php if ($p['MundaneId'] > 0): ?><span class="tn-alias-text" data-alias="<?= htmlspecialchars($p['Alias'] ?? '', ENT_QUOTES) ?>"><a href="<?= UIR ?>Player/profile/<?= $p['MundaneId'] ?>" style="color:#276749;text-decoration:none"><?= htmlspecialchars($p['Alias'] ?: $p['Persona']) ?></a></span><?php else: ?><span class="tn-alias-text" data-alias="<?= htmlspecialchars($p['Alias'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($p['Alias'] ?: $p['Persona']) ?></span><?php endif; ?><?php if ($canManage): ?><button class="tn-alias-edit" data-pid="<?= (int)$p['ParticipantId'] ?>" data-bid="<?= $bid ?>" data-tip="Edit name" aria-label="Edit name" onclick="tnEditAlias(this)"><i class="fas fa-pen"></i></button><?php endif; ?>
											<?= tnParticipantPills($p) ?>
											<?php if ($p['Alias'] && $p['Alias'] !== $p['Persona']): ?>
												<span style="color:#a0aec0;font-size:11px">(<?= htmlspecialchars($p['Persona']) ?>)</span>
											<?php endif; ?>
										<?php else: ?>
											<span class="tn-alias-text" data-alias="<?= htmlspecialchars($p['Alias'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($p['Alias'] ?: '—') ?></span><?php if ($canManage): ?><button class="tn-alias-edit" data-pid="<?= (int)$p['ParticipantId'] ?>" data-bid="<?= $bid ?>" data-tip="Edit name" aria-label="Edit name" onclick="tnEditAlias(this)"><i class="fas fa-pen"></i></button><?php endif; ?><?= tnParticipantPills($p) ?>
										<?php endif; ?>
										<?php if ($_pStatus === 'withdrawn'): ?><span class="tn-pstatus-pill tn-pstatus-pill-withdrawn">WD</span><?php endif; ?>
										<?php if ($_pStatus === 'disqualified'): ?><span class="tn-pstatus-pill tn-pstatus-pill-disqualified">DQ</span><?php endif; ?>
									</span>
									<?php if (!empty($p['ParkName'])): ?>
									<span style="font-size:11px;color:#a0aec0"><?= htmlspecialchars($p['ParkName']) ?></span>
									<?php endif; ?>
									<?php if ($canManage): ?>
									<span class="tn-status-wrap"><button type="button" class="tn-status-btn" onclick="tnToggleParticipantMenu(this)" data-tip="Set status" aria-label="Set status" aria-haspopup="menu" aria-expanded="false">&#8942;</button><div class="tn-status-menu" role="menu" aria-label="Participant status"><button type="button" role="menuitem" tabindex="-1" class="tn-status-menu-item<?= $_pStatus==='active'?' tn-sm-active':'' ?>" onclick="tnSetParticipantStatus(<?= (int)$p['ParticipantId'] ?>, 'active', <?= $bid ?>, this)"><span class="tn-sm-dot tn-sm-dot-active"></span>Active</button><button type="button" role="menuitem" tabindex="-1" class="tn-status-menu-item<?= $_pStatus==='withdrawn'?' tn-sm-active':'' ?>" onclick="tnWithdrawIntent(<?= (int)$p['ParticipantId'] ?>, 'withdrawn', <?= $bid ?>, this)"><span class="tn-sm-dot tn-sm-dot-withdrawn"></span>Withdrawn</button><button type="button" role="menuitem" tabindex="-1" class="tn-status-menu-item<?= $_pStatus==='disqualified'?' tn-sm-active':'' ?>" onclick="tnWithdrawIntent(<?= (int)$p['ParticipantId'] ?>, 'disqualified', <?= $bid ?>, this)"><span class="tn-sm-dot tn-sm-dot-disqualified"></span>Disqualified</button></div></span>
									<?php if (in_array($b['Status'] ?? '', ['setup', ''], true)): ?><button class="tn-remove-participant" data-pid="<?= (int)$p['ParticipantId'] ?>" data-bid="<?= $bid ?>" data-tid="<?= $tid ?>" data-tip="Remove participant" onclick="tnRemoveParticipant(this)">&times;</button><?php else: ?><button class="tn-remove-participant" disabled data-tip="Participants are locked once the bracket starts." aria-label="Remove participant (locked)">&times;</button><?php endif; ?>
									<?php endif; ?>
								<?php endif; ?>
								</li>
								<?php if (($p['IsTeam'] ?? false) && !empty($p['Members'])): ?>
								<li class="tn-team-roster-sub" style="display:none;flex-wrap:wrap;padding:4px 6px 6px 46px;gap:0">
									<?php foreach ($p['Members'] as $_pm): ?>
									<span class="tn-roster-member"><?= htmlspecialchars($_pm['Persona'] ?? '') ?><span class="tn-pill tn-pill-team-wl" data-tip="Warrior level" style="margin-left:3px">⚔<?= (int)$_pm['WarriorLevel'] ?></span></span>
									<?php endforeach; ?>
								</li>
								<?php endif; ?>
								<?php endforeach; ?>
							</ul>
							<?php endif; ?>

							<?php if (($b['Method'] ?? '') === 'points'): ?>
							<?php
								$pmode   = $b['PointMode'] ?? 'fixed';
								$pscale  = ($pmode === 'fixed') ? array_map('trim', explode(',', (string)($b['PointScale'] ?? ''))) : [];
								$prounds = (int)($b['PointRounds'] ?? 0);
								$pstand  = $bd['PointStandings'] ?? [];
								// Until a score is entered every row sits at 0.00 — show the empty state, not a tie.
								$pscored = false;
								foreach ($pstand as $__row) {
									foreach (($__row['RoundScores'] ?? []) as $__v) {
										if ($__v !== null && $__v !== '') { $pscored = true; break 2; }
									}
								}
							?>
							<div class="tn-points-wrap" data-bid="<?= $bid ?>"
								data-mode="<?= htmlspecialchars($pmode) ?>"
								data-scale="<?= htmlspecialchars((string)($b['PointScale'] ?? '')) ?>"
								data-rounds="<?= $prounds ?>">

								<div class="tn-points-ribbon" id="tn-points-ribbon-<?= $bid ?>">
									<?php $__i = 0; foreach ($pscored ? $pstand : [] as $__row): if ($__row['Status'] !== 'active' && $__row['Status'] !== '') continue; if ($__i++ >= 5) break; ?>
										<span class="tn-points-rib-item">
											<strong><?= $__row['Tied'] ? 'T-' : '' ?><?= htmlspecialchars((string)$__row['Place']) ?></strong>
											<?= htmlspecialchars($__row['Alias']) ?> (<?= htmlspecialchars($__row['Total']) ?>)
										</span>
									<?php endforeach; ?>
									<?php if (!$pscored): ?>
										<span style="color:#a0aec0;font-size:13px">No scores yet.</span>
									<?php endif; ?>
								</div>

								<div class="tn-points-grid-scroll">
									<table class="tn-points-grid">
										<thead>
											<tr>
												<th class="tn-points-col-player">Player</th>
												<?php for ($__r = 1; $__r <= $prounds; $__r++): ?>
													<th class="tn-points-col-round">R<?= $__r ?></th>
												<?php endfor; ?>
												<?php if ($canRecordResult && ($b['Status'] ?? '') === 'active'): ?>
													<th class="tn-points-col-add">
														<button type="button" class="tn-btn tn-btn-sm tn-btn-outline" onclick="tnPointsAddRound(<?= $bid ?>, this)" data-tip="Add another round">+</button>
													</th>
												<?php endif; ?>
												<th class="tn-points-col-total">Total</th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($pstand as $__row): $__pid = (int)$__row['ParticipantId']; ?>
												<tr data-pid="<?= $__pid ?>" class="<?= ($__row['Status'] !== 'active' && $__row['Status'] !== '') ? 'tn-points-row-inactive' : '' ?>">
													<td class="tn-points-col-player">#<?= $__row['ParticipantNumber'] ?> <?= htmlspecialchars($__row['Alias']) ?></td>
													<?php for ($__r = 1; $__r <= $prounds; $__r++): $__val = $__row['RoundScores'][$__r-1] ?? null; ?>
														<td class="tn-points-cell" data-pid="<?= $__pid ?>" data-round="<?= $__r ?>" data-value="<?= htmlspecialchars((string)($__val ?? '')) ?>">
															<?php if (!$canRecordResult || in_array($b['Status'] ?? '', ['finalized', 'setup', ''], true)): ?>
																<span class="tn-points-readonly"><?= $__val !== null ? htmlspecialchars((string)$__val) : '-' ?></span>
															<?php elseif ($pmode === 'fixed'): ?>
																<div class="tn-pips">
																	<?php foreach ($pscale as $__sv): $__sel = ($__val !== null && (float)$__val === (float)$__sv); ?>
																		<span class="tn-pip <?= $__sel ? 'tn-pip-selected' : '' ?>" data-val="<?= htmlspecialchars($__sv) ?>"><?= htmlspecialchars($__sv) ?></span>
																	<?php endforeach; ?>
																</div>
															<?php else: ?>
																<input type="text" class="tn-points-input" inputmode="decimal" maxlength="5" value="<?= htmlspecialchars((string)($__val ?? '')) ?>">
															<?php endif; ?>
															<span class="tn-points-status" aria-hidden="true"></span>
														</td>
													<?php endfor; ?>
													<?php if ($canRecordResult && ($b['Status'] ?? '') === 'active'): ?>
														<td class="tn-points-col-add">&nbsp;</td>
													<?php endif; ?>
													<td class="tn-points-col-total"><?= htmlspecialchars((string)$__row['Total']) ?></td>
												</tr>
											<?php endforeach; ?>
											<?php if (empty($pstand)): ?>
												<tr><td colspan="<?= 2 + $prounds + ($canRecordResult && ($b['Status'] ?? '') === 'active' ? 1 : 0) ?>" style="text-align:center;color:#a0aec0;padding:16px">No participants yet.</td></tr>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
							<?php endif; /* method === points */?>

							<?php if (($b['Method'] ?? '') !== 'points' && count($mList) > 0): ?>
<?php $_isIronman = ($b['Method'] === 'ironman'); $_seqId = 'tn-seq-' . $bid; ?>
							<div style="margin-top:12px;border-top:1px solid #f0f4f8;padding-top:10px">
								<div style="display:flex;align-items:center;gap:6px<?= $_isIronman ? '' : ';margin-bottom:8px' ?>">
									<span style="font-size:12px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:0.5px;flex:1"><?= $_isIronman ? 'Match Sequence' : 'Match Results' ?></span>
									<?php if ($_isIronman): ?>
									<button onclick="tnToggleSeq('<?= $_seqId ?>')" style="background:none;border:none;color:#a0aec0;cursor:pointer;padding:2px 5px;font-size:11px;line-height:1" data-tip="Expand/collapse sequence">
										<i class="fas fa-chevron-down" id="<?= $_seqId ?>-icon" style="transform:rotate(-90deg);transition:transform .2s"></i>
									</button>
									<?php endif; ?>
								</div>
								<div id="<?= $_seqId ?>"<?= $_isIronman ? ' style="display:none;margin-top:8px"' : '' ?>>
								<?php $_isTeamBracket = ($b['Participants'] ?? 'individual') === 'team'; ?>
								<?php $_side = $_isTeamBracket ? 'Team' : 'Participant'; $_resLabels = ['1-wins' => $_side . ' 1 wins', '2-wins' => $_side . ' 2 wins', 'tie' => 'Tie', 'forfeit' => $_side . ' 1 forfeits', 'disqualified' => $_side . ' 1 disqualified', '1-forfeits' => $_side . ' 1 forfeits', '2-forfeits' => $_side . ' 2 forfeits', '1-is-disqualified' => $_side . ' 1 disqualified', '2-is-disqualified' => $_side . ' 2 disqualified'];
								// Result key => [side, verb]: labels name the side ('<Team name> wins') when its alias is known.
								$_resSides = ['1-wins' => [1, 'wins'], '2-wins' => [2, 'wins'], 'forfeit' => [1, 'forfeits'], 'disqualified' => [1, 'disqualified'], '1-forfeits' => [1, 'forfeits'], '2-forfeits' => [2, 'forfeits'], '1-is-disqualified' => [1, 'disqualified'], '2-is-disqualified' => [2, 'disqualified']]; ?>
								<table class="tn-table tn-match-results-table">
									<thead>
										<tr>
											<th><?= $_isIronman ? 'Fight' : 'Round' ?></th>
											<th><?= $_isTeamBracket ? 'Team 1' : 'Participant 1' ?></th>
											<th>Result</th>
											<th><?= $_isTeamBracket ? 'Team 2' : 'Participant 2' ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($mList as $m): ?>
										<?php
											$_mVoided = !empty($m['Voided']); $_mWin = tnMatchWinnerSide($m['Result'] ?? '');
											// One side empty and the result empty, a bye, or the present side advancing = a bye.
											// Ironman fights always have an empty side, so they never count.
											$_mE1 = trim((string)($m['Participant1Alias'] ?? '')) === ''; $_mE2 = trim((string)($m['Participant2Alias'] ?? '')) === '';
											$_mElim = in_array($b['Method'] ?? '', ['single', 'double'], true); $_mRes = $m['Result'] ?? '';
											$_mBye = !$_isIronman && ($_mE1 xor $_mE2) && (in_array($_mRes, ['1-is-bye', '2-is-bye'], true) || ($_mRes === '' && !$_mElim) || $_mWin === ($_mE1 ? 2 : 1));
										?>
										<tr<?= $_mVoided ? ' class="tn-match-voided"' : '' ?>>
											<td style="color:#a0aec0" data-order="<?= (int)($_isIronman ? ($m['Match'] ?? 0) : ($m['Round'] ?? 0)) ?>"><?php if ($_isIronman): ?>#<?= htmlspecialchars($m['Match'] ?? '') ?><?php elseif (($b['Method'] ?? '') === 'double'): ?><?php
												// Double-elim: prefix the side so W/L/GF rounds are distinguishable.
												$_mSide = $m['BracketSide'] ?? 'winners'; $_mRnd = (int)($m['Round'] ?? 0);
												echo htmlspecialchars($_mSide === 'grand-final' ? 'GF' . ($_mRnd > 1 ? ' R' . $_mRnd : '') : ($_mSide === 'losers' ? 'L R' . $_mRnd : ($_mSide === 'tiebreaker-3rd' ? '3rd' : 'R' . $_mRnd)));
											?><?php else: ?>R<?= htmlspecialchars($m['Round']) ?><?php endif; ?></td>
											<td><?php if ($_mWin === 1): ?><i class="fas fa-circle" style="color:#38a169;font-size:8px;margin-right:5px;vertical-align:middle"></i><?php endif; ?><?= htmlspecialchars($m['Participant1Alias'] ?? '—') ?></td>
											<td style="text-align:center;color:#718096"><?php if ($_mVoided): ?><span class="tn-pstatus-pill tn-match-void-pill" data-tip="Voided — excluded from standings">VOID</span><?php elseif ($_mBye): ?>Bye<?php else: ?><?php
												$_rs = $_resSides[$_mRes] ?? null; $_rsName = $_rs ? trim((string)($m['Participant' . $_rs[0] . 'Alias'] ?? '')) : '';
												echo htmlspecialchars($_rsName !== '' ? $_rsName . ' ' . $_rs[1] : ($_resLabels[$_mRes] ?? ($_mRes ?: '—')));
											?><?php endif; ?></td>
											<td><?php if ($_mWin === 2): ?><i class="fas fa-circle" style="color:#38a169;font-size:8px;margin-right:5px;vertical-align:middle"></i><?php endif; ?><?= htmlspecialchars($m['Participant2Alias'] ?? '—') ?></td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								</div>
							</div>
							<?php elseif (count($pList) > 0 && !in_array($b['Method'] ?? '', ['points', 'ironman'], true) && in_array($b['Status'] ?? '', ['setup', ''], true)): ?>
							<div class="tn-empty" style="margin-top:10px;padding-top:10px;border-top:1px solid #f0f4f8">
								No matches generated yet. Use "Generate" to create the bracket draw.
							</div>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<!-- Participants Tab -->
			<div class="tn-tab-panel" id="tn-tab-participants" role="tabpanel" aria-labelledby="tn-tabhdr-participants" tabindex="0" style="display:none">
				<div class="tn-roster-bar tn-roster-bar-split">
					<div class="tn-subtabs" role="tablist" aria-label="Participant type" onkeydown="tnParticipantsSubtabKey(event)">
						<button type="button" class="tn-subtab tn-subtab-active" id="tn-subtab-individuals" role="tab" aria-selected="true" aria-controls="tn-subpanel-individuals" tabindex="0" onclick="tnParticipantsSubtab('individuals')">Individuals</button>
						<button type="button" class="tn-subtab" id="tn-subtab-teams" role="tab" aria-selected="false" aria-controls="tn-subpanel-teams" tabindex="-1" onclick="tnParticipantsSubtab('teams')">Teams</button>
					</div>
<?php if ($canManage): ?>
					<div class="tn-roster-actions">
						<button class="tn-btn tn-btn-primary" id="tn-roster-action-individuals" onclick="tnOpenRegisterModal()"><i class="fas fa-user-plus"></i> Register Participant</button>
						<button class="tn-btn tn-btn-primary" id="tn-roster-action-teams" style="display:none" onclick="tnOpenCreateTeamModal()"><i class="fas fa-users"></i> Create Team</button>
					</div>
<?php endif; ?>
				</div>
				<div id="tn-subpanel-individuals" role="tabpanel" aria-labelledby="tn-subtab-individuals" tabindex="0">
				<div id="tn-roster-table-wrap">
<?php if (empty($registrants)): ?>
					<div class="tn-empty">No participants registered yet.</div>
<?php else: ?>
					<table class="tn-table" id="tn-roster-table">
						<thead>
							<tr>
								<th>Alias</th>
								<th>Player</th>
								<th>Park</th>
								<th>Warriors</th>
								<th>Brackets</th>
<?php if ($canManage): ?>								<th class="no-export"></th>
<?php endif; ?>							</tr>
						</thead>
						<tbody>
<?php foreach ($registrants as $_r): ?>
<?php $_withdrawn = (($_r['Status'] ?? '') === 'withdrawn'); ?>
							<tr data-pnum="<?= (int)($_r['ParticipantNumber'] ?? 0) ?>"<?= $_withdrawn ? ' class="tn-reg-withdrawn"' : '' ?>>
								<td style="font-weight:600"><?= htmlspecialchars($_r['Alias'] ?? '') ?: '&mdash;' ?><?php if ($_withdrawn): ?> <span class="tn-reg-wd-badge">Withdrawn</span><?php endif; ?></td>
								<td>
<?php if (!empty($_r['Persona']) && (int)($_r['MundaneId'] ?? 0) > 0): ?>
									<a href="<?= UIR ?>Player/profile/<?= (int)$_r['MundaneId'] ?>" style="color:#276749;text-decoration:none"><?= htmlspecialchars($_r['Persona']) ?></a>
<?php elseif (!empty($_r['Persona'])): ?>
									<?= htmlspecialchars($_r['Persona']) ?>
<?php else: ?>
									<span style="color:#a0aec0">&mdash;</span>
<?php endif; ?>
								</td>
								<td style="color:#718096"><?= htmlspecialchars(!empty($_r['ParkName']) ? $_r['ParkName'] : '') ?: '&mdash;' ?></td>
								<td><?= tnParticipantPills($_r) ?: '<span style="color:#a0aec0">&mdash;</span>' ?></td>
								<td>
<?php if (!empty($_r['Brackets'])): ?>
<?php foreach ($_r['Brackets'] as $_b): ?><span class="tn-reg-chip"><?= htmlspecialchars($styleLabelMap[$_b['BracketStyle']] ?? $_b['BracketStyle']) ?></span><?php endforeach; ?>
<?php else: ?>
									<span class="tn-reg-unassigned">Unassigned</span>
<?php endif; ?>
								</td>
<?php if ($canManage): ?>								<td><div class="tn-reg-actions" data-pnum="<?= (int)($_r['ParticipantNumber'] ?? 0) ?>"></div></td>
<?php endif; ?>							</tr>
<?php endforeach; ?>
						</tbody>
					</table>
<?php endif; ?>
				</div>
				</div>
				<div id="tn-subpanel-teams" role="tabpanel" aria-labelledby="tn-subtab-teams" tabindex="0" style="display:none">
					<div id="tn-teams-table-wrap">
<?php if (empty($registered_teams)): ?>
						<div class="tn-empty">No teams yet.</div>
<?php else: ?>
						<table class="tn-table" id="tn-teams-table">
							<thead>
								<tr>
									<th>Team</th>
									<th>Members</th>
									<th>Brackets</th>
<?php if ($canManage): ?>								<th class="no-export"></th>
<?php endif; ?>							</tr>
							</thead>
							<tbody>
<?php foreach ($registered_teams as $_t): ?>
<?php $_tnum = (int)($_t['TeamNumber'] ?? 0); $_members = $_t['Members'] ?? []; $_mcount = count($_members); ?>
								<tr data-tnum="<?= $_tnum ?>">
									<td style="font-weight:600"><?= htmlspecialchars($_t['Name'] ?? '') ?: '&mdash;' ?>
										<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle"><span class="tn-pill tn-pill-team-wl" data-tip="Team warrior level">⚔ <?= (int)($_t['WarriorLevel'] ?? 0) ?></span></span>
									</td>
									<td data-order="<?= $_mcount ?>">
<?php if ($_mcount): ?>
										<button class="tn-team-roster-btn" onclick="tnToggleRoster(this)" data-tip="Show/hide team roster">&#9658; <?= $_mcount ?></button><template class="tn-team-roster-src"><?php foreach ($_members as $_tm): ?><span class="tn-roster-member"><?= htmlspecialchars($_tm['Persona'] ?? '') ?><span class="tn-pill tn-pill-team-wl" data-tip="Warrior level" style="margin-left:3px">⚔<?= (int)($_tm['WarriorLevel'] ?? 0) ?></span></span><?php endforeach; ?></template>
<?php else: ?>
										<span style="color:#a0aec0">&mdash;</span>
<?php endif; ?>
									</td>
									<td>
<?php if (!empty($_t['Brackets'])): ?>
<?php foreach ($_t['Brackets'] as $_b): ?><span class="tn-team-chip"><?= htmlspecialchars($styleLabelMap[$_b['BracketStyle']] ?? $_b['BracketStyle']) ?></span><?php endforeach; ?>
<?php else: ?>
										<span class="tn-reg-unassigned">Unassigned</span>
<?php endif; ?>
									</td>
<?php if ($canManage): ?>								<td><div class="tn-team-actions" data-tnum="<?= $_tnum ?>"></div></td>
<?php endif; ?>							</tr>
<?php endforeach; ?>
							</tbody>
						</table>
<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Run Tournament Tab -->
			<div class="tn-tab-panel" id="tn-tab-bracketviz" role="tabpanel" aria-labelledby="tn-tabhdr-bracketviz" tabindex="0" style="display:none">
				<?php if ($totalBrackets === 0): ?>
				<div class="tn-bv-empty">No brackets yet.</div>
				<?php else: ?>
				<input type="hidden" id="tn-bv-bracket-select" value="<?= array_key_first($bracketData) ?? 0 ?>">
				<?php if ($totalBrackets > 1): ?>
				<div class="tn-bk-pills">
					<?php $bvFirst = true; foreach ($bracketData as $bvid => $bvd): $bvb = $bvd['Bracket']; ?>
					<button class="tn-bk-pill<?= $bvFirst ? ' tn-bk-pill-active' : '' ?>" data-bid="<?= $bvid ?>" onclick="tnBracketPillClick(this, <?= $bvid ?>)"><?= htmlspecialchars($styleLabelMap[$bvb['Style']] ?? $bvb['Style']) ?><?= trim($bvb['StyleNote'] ?? '') !== '' ? ' (' . htmlspecialchars(trim($bvb['StyleNote'])) . ')' : '' ?> &mdash; <?= htmlspecialchars($methodLabelMap[$bvb['Method']] ?? $bvb['Method']) ?></button>
					<?php $bvFirst = false; endforeach; ?>
				</div>
				<?php endif; ?>
				<?php if ($canRecordResult): ?><div id="tn-nextup"></div><?php endif; ?>
				<div id="tn-bv-container"></div>
				<?php endif; ?>
			</div>

			<!-- Standings Tab -->
			<div class="tn-tab-panel" id="tn-tab-standings" role="tabpanel" aria-labelledby="tn-tabhdr-standings" tabindex="0" style="display:none">
<?php
			// #58: a workbook is only meaningful once there is standings data to export.
			// With no completed/finalized bracket AND no recorded matches the export is an
			// empty file, so gate the link the same way the leaderboard empty-states gate.
			$hasStandingsData = ($totalMatches > 0);
			if (!$hasStandingsData && !empty($bracketData)) {
				foreach ($bracketData as $__exBid => $__exBd) {
					$__exSt = $__exBd['Bracket']['Status'] ?? '';
					if (in_array($__exSt, ['complete', 'finalized'], true) || !empty($standingsData[$__exBid])) {
						$hasStandingsData = true;
						break;
					}
				}
			}
?>
				<!-- Pills row + gear icon -->
				<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px">
					<div class="tn-bk-pills" id="tn-standings-pills" style="flex:1;flex-wrap:wrap">
						<button class="tn-bk-pill tn-bk-pill-active" data-bid="leaderboard" onclick="tnStandingsPillClick(this,'leaderboard')">
							<i class="fas fa-trophy" style="margin-right:5px;color:#d69e2e"></i>Leaderboard
						</button>
					</div>
					<button class="tn-btn tn-btn-ghost tn-btn-sm" id="tn-standings-refresh" onclick="tnRefreshStandings(this)" data-tip="Recalculate standings from the latest results" aria-label="Recalculate standings" data-tip-right style="padding:6px 10px;flex-shrink:0">
						<i class="fas fa-sync-alt"></i>
					</button>
<?php if ($hasStandingsData): ?>
					<a class="tn-btn tn-btn-ghost tn-btn-sm" href="<?= UIR ?>Tournament/export/<?= (int)$tournament['TournamentId'] ?>" data-tip="Download an .xlsx workbook of every bracket" data-tip-right style="flex-shrink:0;display:inline-flex;align-items:center;gap:6px;text-decoration:none">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						Export Results
					</a>
<?php else: ?>
					<span class="tn-btn tn-btn-ghost tn-btn-sm" aria-disabled="true" data-tip="Nothing to export yet — record match results or complete a bracket first" data-tip-right style="flex-shrink:0;display:inline-flex;align-items:center;gap:6px;opacity:.5;cursor:not-allowed">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						Export Results
					</span>
<?php endif; ?>
					<?php if ($canManage): ?>
					<button class="tn-btn tn-btn-ghost tn-btn-sm" onclick="tnOpenConfigStandingsModal()" data-tip="Configure standings points" aria-label="Configure standings points" style="padding:6px 10px;flex-shrink:0">
						<i class="fas fa-cog"></i>
					</button>
					<?php endif; ?>
				</div>

				<!-- Leaderboard section (default) -->
				<div class="tn-standings-section" data-stbid="leaderboard">
					<div style="margin-bottom:10px;font-size:12px;color:#718096">
						Points awarded by final bracket placement: <span id="tn-ldb-pts-summary" style="font-weight:600;color:#276749"></span>
					</div>
					<table class="tn-table" id="tn-leaderboard-table">
						<thead>
							<tr>
								<th>Rank</th>
								<th>Participant</th>
								<th>Park</th>
								<th style="text-align:center">Brackets</th>
								<th style="text-align:right">Total Pts</th>
								<?php if ($canRecommend): ?>
								<th class="no-export" style="text-align:center">Recommend for&hellip;</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody id="tn-leaderboard-body">
							<tr><td colspan="<?= $canRecommend ? 6 : 5 ?>" style="text-align:center;color:#a0aec0;padding:20px">Computing leaderboard…</td></tr>
						</tbody>
					</table>
				</div>

				<!-- Per-bracket standings sections (JS-rendered from TnConfig.standingsData) -->
				<div id="tn-standings-sections"></div>
			</div>

		</div><!-- /.tn-tabs -->
	</div><!-- /.tn-main -->

</div><!-- /.tn-layout -->

</div><!-- /#tn-root -->


<?php if ($canManage): ?>
<!-- =============================================
     Add Bracket Modal
     ============================================= -->
<div class="tn-overlay" id="tn-addbracket-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-sitemap" style="margin-right:8px;color:#276749"></i>Add Bracket</h3>
			<button class="tn-modal-close" id="tn-addbracket-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-addbracket-feedback" class="tn-feedback"></div>
			<div class="tn-field-row">
				<div class="tn-field">
					<label for="tn-addbracket-style">Weapon Style <span style="color:#e53e3e">*</span></label>
					<select id="tn-addbracket-style">
						<option value="Single Sword">Single Sword</option>
						<option value="Florentine">Florentine</option>
						<option value="Sword and Shield">Sword &amp; Shield</option>
						<option value="Great Weapon">Great Weapon</option>
						<option value="Missile">Missile</option>
						<option value="Jugging">Jugging</option>
						<option value="Battlegame">Battlegame</option>
						<option value="Quest">Quest</option>
						<option value="Open Weapons">Open Weapons</option>
						<option value="Other">Other</option>
					</select>
				</div>
				<div class="tn-field">
					<label for="tn-addbracket-method">Format <span style="color:#e53e3e">*</span></label>
					<select id="tn-addbracket-method">
						<option value="single">Single Elimination</option>
						<option value="double">Double Elimination</option>
						<option value="swiss">Swiss</option>
						<option value="round-robin">Round Robin</option>
						<option value="ironman">Ironman</option>
						<option value="points">Points</option>
					</select>
				</div>
			</div>
			<div id="tn-addbracket-points-config" class="tn-field-row" style="display:none">
				<div class="tn-field">
					<label for="tn-addbracket-point-rounds">Rounds <span style="color:#e53e3e">*</span></label>
					<input type="number" id="tn-addbracket-point-rounds" value="3" min="1" max="32">
				</div>
				<div class="tn-field">
					<label>Point Mode <span style="color:#e53e3e">*</span></label>
					<div style="display:flex;gap:12px;align-items:center;padding-top:6px">
						<label style="display:flex;gap:4px;align-items:center;font-weight:400;cursor:pointer">
							<input type="radio" name="tn-addbracket-point-mode" value="fixed" checked> Fixed Points
						</label>
						<label style="display:flex;gap:4px;align-items:center;font-weight:400;cursor:pointer">
							<input type="radio" name="tn-addbracket-point-mode" value="open"> Open Points
						</label>
					</div>
				</div>
			</div>
			<div id="tn-addbracket-point-scale-row" class="tn-field-row" style="display:none">
				<div class="tn-field" style="flex:1">
					<label for="tn-addbracket-point-scale">Point Scale <span style="color:#e53e3e">*</span></label>
					<input type="text" id="tn-addbracket-point-scale" value="5,3,1,0" placeholder="e.g. 5,3,1,0">
					<div style="font-size:11px;color:#718096;margin-top:4px">Comma-separated values shown as clickable pips. First value is highest.</div>
					<div id="tn-addbracket-point-scale-preview" style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap"></div>
					<div id="tn-addbracket-point-scale-err" style="display:none;color:#e53e3e;font-size:12px;margin-top:4px"></div>
				</div>
			</div>
			<div id="tn-addbracket-advanced">
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tn-addbracket-participants">Participants</label>
						<div class="tn-seg" id="tn-addbracket-participants">
							<button type="button" class="tn-seg-btn tn-seg-active" data-val="individual">Individual</button>
							<button type="button" class="tn-seg-btn" data-val="team">Team</button>
						</div>
					</div>
					<div class="tn-field">
						<label for="tn-addbracket-rings">Rings (concurrent)</label>
						<input type="number" id="tn-addbracket-rings" value="1" min="1" max="20">
					</div>
				</div>
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tn-addbracket-seeding">Seeding</label>
						<select id="tn-addbracket-seeding">
							<option value="random">Random</option>
							<option value="manual">Manual</option>
							<option value="warrior">Orders of the Warrior</option>
							<option value="glicko2">Performance Score</option>
							<option value="random-manual">Random + Manual Adjust</option>
							<option value="glicko2-manual">Performance + Manual Adjust</option>
						</select>
					</div>
					<div class="tn-field">
						<label for="tn-addbracket-bestof">Best of <span style="color:#a0aec0;font-size:11px;font-weight:400">(bouts per match)</span></label>
						<select id="tn-addbracket-bestof">
							<option value="1" selected>1 — single bout</option>
							<option value="3">3</option>
							<option value="5">5</option>
							<option value="7">7</option>
							<option value="9">9</option>
						</select>
					</div>
				</div>
				<div class="tn-field" id="tn-addbracket-duration-field" style="display:none">
					<label for="tn-addbracket-duration">Max Duration <span style="color:#a0aec0;font-size:11px;font-weight:400">(minutes, 0 = unlimited)</span></label>
					<input type="number" id="tn-addbracket-duration" value="0" min="0" max="480">
				</div>
				<div class="tn-field">
					<label for="tn-addbracket-stylenote">Style Note <span style="color:#a0aec0;font-size:11px;font-weight:400">(optional)</span></label>
					<input type="text" id="tn-addbracket-stylenote" placeholder="e.g. No shields allowed, florentine only…" maxlength="255">
				</div>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-addbracket-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-addbracket-submit">
				<i class="fas fa-plus"></i> Add Bracket
			</button>
		</div>
	</div>
</div>

<?php if ($canManage): ?>
<!-- =============================================
     Quick Bracket Modal (spec 2026-10-02)
     ============================================= -->
<div class="tn-overlay" id="tn-quickbracket-overlay" role="dialog" aria-modal="true" aria-labelledby="tn-qb-title">
	<div class="tn-modal-box" style="max-width:440px">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title" id="tn-qb-title"><i class="fas fa-bolt" style="margin-right:8px;color:#b7791f"></i>Quick Bracket</h3>
			<button class="tn-modal-close" type="button" aria-label="Close" onclick="tnCloseModal('tn-quickbracket-overlay')">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div class="tn-feedback" id="tn-qb-feedback" style="display:none"></div>
			<div class="tn-qb-field">
				<span class="tn-qb-field-label" id="tn-qb-method-lbl">Format</span>
				<div class="tn-qb-chips" id="tn-qb-method" role="radiogroup" aria-labelledby="tn-qb-method-lbl">
					<button type="button" class="tn-qb-chip" role="radio" data-value="single">Single Elimination</button>
					<button type="button" class="tn-qb-chip" role="radio" data-value="double">Double Elimination</button>
				</div>
			</div>
			<div class="tn-qb-field">
				<span class="tn-qb-field-label" id="tn-qb-size-lbl">Starting Size</span>
				<div class="tn-qb-chips" id="tn-qb-size" role="radiogroup" aria-labelledby="tn-qb-size-lbl">
					<?php foreach ([4, 8, 12, 16, 24, 32] as $_qbs): ?>
					<button type="button" class="tn-qb-chip" role="radio" data-value="<?= $_qbs ?>"><?= $_qbs ?></button>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" type="button" onclick="tnCloseModal('tn-quickbracket-overlay')">Cancel</button>
			<button class="tn-btn tn-btn-primary" type="button" id="tn-qb-generate"><i class="fas fa-bolt"></i> Generate</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Edit Bracket Modal
     ============================================= -->
<div class="tn-overlay" id="tn-editbracket-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#276749"></i>Edit Bracket</h3>
			<button class="tn-modal-close" id="tn-editbracket-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-editbracket-feedback" class="tn-feedback"></div>
			<input type="hidden" id="tn-editbracket-bid">
			<div class="tn-field-row">
				<div class="tn-field">
					<label for="tn-editbracket-style">Weapon Style <span style="color:#e53e3e">*</span></label>
					<select id="tn-editbracket-style">
						<option value="Single Sword">Single Sword</option>
						<option value="Florentine">Florentine</option>
						<option value="Sword and Shield">Sword &amp; Shield</option>
						<option value="Great Weapon">Great Weapon</option>
						<option value="Missile">Missile</option>
						<option value="Jugging">Jugging</option>
						<option value="Battlegame">Battlegame</option>
						<option value="Quest">Quest</option>
						<option value="Open Weapons">Open Weapons</option>
						<option value="Other">Other</option>
					</select>
				</div>
				<div class="tn-field">
					<label for="tn-editbracket-method">Format <span style="color:#e53e3e">*</span></label>
					<select id="tn-editbracket-method">
						<option value="single">Single Elimination</option>
						<option value="double">Double Elimination</option>
						<option value="swiss">Swiss</option>
						<option value="round-robin">Round Robin</option>
						<option value="ironman">Ironman</option>
						<option value="points">Points</option>
					</select>
				</div>
			</div>
			<div id="tn-editbracket-points-config" class="tn-field-row" style="display:none">
				<div class="tn-field">
					<label for="tn-editbracket-point-rounds">Rounds <span style="color:#e53e3e">*</span></label>
					<input type="number" id="tn-editbracket-point-rounds" value="3" min="1" max="32">
				</div>
				<div class="tn-field">
					<label>Point Mode <span style="color:#e53e3e">*</span></label>
					<div style="display:flex;gap:12px;align-items:center;padding-top:6px">
						<label style="display:flex;gap:4px;align-items:center;font-weight:400;cursor:pointer">
							<input type="radio" name="tn-editbracket-point-mode" value="fixed" checked> Fixed Points
						</label>
						<label style="display:flex;gap:4px;align-items:center;font-weight:400;cursor:pointer">
							<input type="radio" name="tn-editbracket-point-mode" value="open"> Open Points
						</label>
					</div>
				</div>
			</div>
			<div id="tn-editbracket-point-scale-row" class="tn-field-row" style="display:none">
				<div class="tn-field" style="flex:1">
					<label for="tn-editbracket-point-scale">Point Scale <span style="color:#e53e3e">*</span></label>
					<input type="text" id="tn-editbracket-point-scale" value="5,3,1,0" placeholder="e.g. 5,3,1,0">
					<div style="font-size:11px;color:#718096;margin-top:4px">Comma-separated values shown as clickable pips. First value is highest.</div>
					<div id="tn-editbracket-point-scale-preview" style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap"></div>
					<div id="tn-editbracket-point-scale-err" style="display:none;color:#e53e3e;font-size:12px;margin-top:4px"></div>
				</div>
			</div>
			<div id="tn-editbracket-advanced">
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tn-editbracket-participants">Participants</label>
						<div class="tn-seg" id="tn-editbracket-participants">
							<button type="button" class="tn-seg-btn tn-seg-active" data-val="individual">Individual</button>
							<button type="button" class="tn-seg-btn" data-val="team">Team</button>
						</div>
					</div>
					<div class="tn-field">
						<label for="tn-editbracket-rings">Rings (concurrent)</label>
						<input type="number" id="tn-editbracket-rings" value="1" min="1" max="20">
					</div>
				</div>
				<div class="tn-field-row">
					<div class="tn-field">
						<label for="tn-editbracket-seeding">Seeding</label>
						<select id="tn-editbracket-seeding">
							<option value="random">Random</option>
							<option value="manual">Manual</option>
							<option value="warrior">Orders of the Warrior</option>
							<option value="glicko2">Performance Score</option>
							<option value="random-manual">Random + Manual Adjust</option>
							<option value="glicko2-manual">Performance + Manual Adjust</option>
						</select>
					</div>
					<div class="tn-field">
						<label for="tn-editbracket-bestof">Best of <span style="color:#a0aec0;font-size:11px;font-weight:400">(bouts per match)</span></label>
						<select id="tn-editbracket-bestof">
							<option value="1">1 — single bout</option>
							<option value="3">3</option>
							<option value="5">5</option>
							<option value="7">7</option>
							<option value="9">9</option>
						</select>
					</div>
				</div>
				<div class="tn-field" id="tn-editbracket-duration-field" style="display:none">
					<label for="tn-editbracket-duration">Max Duration <span style="color:#a0aec0;font-size:11px;font-weight:400">(minutes, 0 = unlimited)</span></label>
					<input type="number" id="tn-editbracket-duration" value="0" min="0" max="480">
				</div>
				<div class="tn-field">
					<label for="tn-editbracket-stylenote">Style Note <span style="color:#a0aec0;font-size:11px;font-weight:400">(optional)</span></label>
					<input type="text" id="tn-editbracket-stylenote" placeholder="e.g. No shields allowed, florentine only…" maxlength="255">
				</div>
				<div class="tn-field" id="tn-editbracket-firstround-field" style="display:none">
					<label>How to handle the first round?</label>
					<div class="tn-seg" id="tn-editbracket-firstround">
						<button type="button" class="tn-seg-btn" data-val="play-in">Play-In for First Round Position</button>
						<button type="button" class="tn-seg-btn" data-val="byes">Assign Byes for First Round</button>
					</div>
					<div class="tn-field-hint" id="tn-editbracket-firstround-hint"></div>
				</div>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-editbracket-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-editbracket-submit">
				<i class="fas fa-save"></i> Save Changes
			</button>
		</div>
	</div>
</div>

<!-- =============================================
     Add Participant Modal
     ============================================= -->
<div class="tn-overlay" id="tn-addparticipant-overlay">
	<div class="tn-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Add Participant</h3>
			<button class="tn-modal-close" id="tn-addparticipant-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-addparticipant-feedback" class="tn-feedback"></div>
			<input type="hidden" id="tn-addparticipant-bracket-id" value="">
			<input type="hidden" id="tn-addparticipant-tournament-id" value="<?= $tid ?>">
			<div class="tn-field">
				<label>Player <span style="color:#a0aec0;font-size:11px;font-weight:400">(search to auto-fill name)</span></label>
				<div style="position:relative">
					<input type="text" id="tn-addparticipant-player-text" placeholder="Search by persona…" autocomplete="off">
					<input type="hidden" id="tn-addparticipant-player-id" value="0">
					<div id="tn-addparticipant-player-results" class="kn-ac-results"></div>
				</div>
			</div>
			<div class="tn-field">
				<label for="tn-addparticipant-alias">Alias / Fighter Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="tn-addparticipant-alias" placeholder="Name as it appears in the bracket" maxlength="100">
				<div id="tn-addparticipant-alias-warn" class="tn-alias-warn" style="display:none">For best display, we recommend choosing a shorter alias for this player. Don't worry, they will still be tied to the correct ORK persona.</div>
			</div>
		</div>
		<div id="tn-quickadd-section" style="display:none;margin-top:0;border-top:1px solid #e2e8f0;padding:12px 20px 4px">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
				<div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:0.5px">Quick Add from other brackets</div>
				<button id="tn-quickadd-addall" class="tn-btn tn-btn-outline tn-btn-sm" style="padding:2px 10px"><i class="fas fa-users"></i> Add All</button>
			</div>
			<div id="tn-quickadd-list" style="max-height:180px;overflow-y:auto"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-addparticipant-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-addparticipant-submit">
				<i class="fas fa-user-plus"></i> Add Participant
			</button>
		</div>
	</div>
</div>

<!-- =============================================
     Register Participant Modal (tournament-level roster)
     ============================================= -->
<?php if ($canManage): ?>
<div class="tn-overlay" id="tn-register-overlay">
	<div class="tn-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Register Participant</h3>
			<button class="tn-modal-close" id="tn-register-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-register-feedback" class="tn-feedback"></div>
			<div class="tn-field">
				<label>Player <span style="color:#a0aec0;font-size:11px;font-weight:400">(search to auto-fill name)</span></label>
				<div style="position:relative">
					<input type="text" id="tn-register-player-text" placeholder="Search by persona…" autocomplete="off">
					<input type="hidden" id="tn-register-player-id" value="0">
					<div id="tn-register-player-results" class="kn-ac-results"></div>
				</div>
			</div>
			<div class="tn-field">
				<label for="tn-register-alias">Alias / Fighter Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="tn-register-alias" placeholder="Name as it appears in standings" maxlength="100">
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-register-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-register-submit">
				<i class="fas fa-user-plus"></i> Register
			</button>
		</div>
	</div>
</div>

<!-- =============================================
     Assign Registrant to Brackets Modal (tournament-level roster)
     ============================================= -->
<div class="tn-overlay" id="tn-assign-overlay">
	<div class="tn-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-sitemap" style="margin-right:8px;color:#276749"></i>Assign to Brackets</h3>
			<button class="tn-modal-close" id="tn-assign-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-assign-feedback" class="tn-feedback"></div>
			<div id="tn-assign-subtitle" style="font-size:13px;color:#718096;margin-bottom:12px"></div>
			<div class="tn-assign-list" id="tn-assign-list"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-assign-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-assign-submit">
				<i class="fas fa-check"></i> Save
			</button>
		</div>
	</div>
</div>

<!-- =============================================
     Assign Team to Brackets Modal — team-level
     ============================================= -->
<div class="tn-overlay" id="tn-teamassign-overlay">
	<div class="tn-modal-box" style="width:480px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-sitemap" style="margin-right:8px;color:#276749"></i>Assign Team to Brackets</h3>
			<button class="tn-modal-close" id="tn-teamassign-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-teamassign-feedback" class="tn-feedback"></div>
			<div id="tn-teamassign-subtitle" style="font-size:13px;color:#718096;margin-bottom:12px"></div>
			<div class="tn-assign-list" id="tn-teamassign-list"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-teamassign-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-teamassign-submit">
				<i class="fas fa-check"></i> Save
			</button>
		</div>
	</div>
</div>

<!-- =============================================
     Assign Participants (bulk) Modal — bracket-level
     ============================================= -->
<div class="tn-overlay" id="tn-assignparts-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-user-check" style="margin-right:8px;color:#276749"></i>Assign Participants</h3>
			<button class="tn-modal-close" id="tn-assignparts-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-assignparts-feedback" class="tn-feedback"></div>
			<div id="tn-assignparts-subtitle" style="font-size:13px;color:#718096;margin-bottom:10px"></div>
			<div id="tn-assignparts-controls" style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
				<input type="text" id="tn-assignparts-filter" class="tn-assignparts-filter" placeholder="Filter by alias or persona…" autocomplete="off">
				<button type="button" class="tn-btn tn-btn-ghost tn-btn-sm" id="tn-assignparts-selectall">Select all</button>
				<button type="button" class="tn-btn tn-btn-ghost tn-btn-sm" id="tn-assignparts-clear">Clear</button>
			</div>
			<div class="tn-assignparts-list" id="tn-assignparts-list"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-assignparts-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-assignparts-submit">
				<i class="fas fa-check"></i> Save
			</button>
		</div>
	</div>
</div>

<!-- =============================================
     Assign Teams (bulk) Modal — bracket-level (team brackets)
     ============================================= -->
<div class="tn-overlay" id="tn-assignteams-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-users" style="margin-right:8px;color:#276749"></i>Assign Teams</h3>
			<button class="tn-modal-close" id="tn-assignteams-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-assignteams-feedback" class="tn-feedback"></div>
			<div id="tn-assignteams-subtitle" style="font-size:13px;color:#718096;margin-bottom:10px"></div>
			<div id="tn-assignteams-controls" style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
				<input type="text" id="tn-assignteams-filter" class="tn-assignparts-filter" placeholder="Filter by team name…" autocomplete="off">
				<button type="button" class="tn-btn tn-btn-ghost tn-btn-sm" id="tn-assignteams-selectall">Select all</button>
				<button type="button" class="tn-btn tn-btn-ghost tn-btn-sm" id="tn-assignteams-clear">Clear</button>
			</div>
			<div class="tn-assignparts-list" id="tn-assignteams-list"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-assignteams-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-assignteams-submit">
				<i class="fas fa-check"></i> Save
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Bulk Add (Paste Roster) Modal — one alias per line
     ============================================= -->
<div class="tn-overlay" id="tn-bulkadd-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-clipboard-list" style="margin-right:8px;color:#276749"></i>Paste Roster</h3>
			<button class="tn-modal-close" id="tn-bulkadd-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-bulkadd-feedback" class="tn-feedback"></div>
			<input type="hidden" id="tn-bulkadd-bracket-id" value="">
			<input type="hidden" id="tn-bulkadd-tournament-id" value="<?= $tid ?>">
			<p style="margin:0 0 10px;font-size:12px;color:#718096;line-height:1.5">
				One fighter per line. Paste from a signup sheet or type as fast as you can.
				Fighters won't be linked to player profiles, but you can fix that later from the bracket card.
			</p>
			<div class="tn-field">
				<label for="tn-bulkadd-text">ALIASES <span style="color:#e53e3e">*</span></label>
				<textarea id="tn-bulkadd-text" rows="10" placeholder="Sir Galahad&#10;Morgana&#10;The Grey Wolf&#10;..." style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.5"></textarea>
			</div>
			<div id="tn-bulkadd-progress" style="display:none;font-size:12px;color:#718096;margin-top:4px"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-bulkadd-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-bulkadd-submit">
				<i class="fas fa-users"></i> Add All
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Add Team Modal (for team brackets)
     ============================================= -->
<?php if ($canManage): ?>
<div class="tn-overlay" id="tn-addteam-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-users" style="margin-right:8px;color:#3182ce"></i>Add Team</h3>
			<button class="tn-modal-close" id="tn-addteam-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-addteam-feedback" class="tn-feedback"></div>
			<input type="hidden" id="tn-addteam-bracket-id" value="">
			<input type="hidden" id="tn-addteam-tournament-id" value="<?= $tid ?>">
			<!-- Step 1: Team Name -->
			<div id="tn-addteam-step1">
				<div class="tn-field">
					<label for="tn-addteam-name">Team Name <span style="color:#e53e3e">*</span></label>
					<input type="text" id="tn-addteam-name" placeholder="Enter team name" maxlength="100">
				</div>
			</div>
			<!-- Step 2: Add Members (hidden until team name set) -->
			<div id="tn-addteam-step2" style="display:none">
				<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
					<span style="font-size:13px;font-weight:700" class="tn-addteam-label" id="tn-addteam-label"></span>
					<span style="font-size:11px;color:#a0aec0">&mdash; add members below</span>
				</div>
				<div id="tn-addteam-members" style="margin-bottom:12px"></div>
				<div class="tn-field" style="margin-bottom:0">
					<label>Add Member <span style="color:#a0aec0;font-size:11px;font-weight:400">(search by persona)</span></label>
					<div style="position:relative">
						<input type="text" id="tn-addteam-player-text" placeholder="Search by persona…" autocomplete="off">
						<div id="tn-addteam-player-results" class="kn-ac-results"></div>
					</div>
				</div>
			</div>
		</div>
		<!-- Quick add from other brackets -->
		<div id="tn-teamquickadd-section" style="display:none;margin-top:0;border-top:1px solid #e2e8f0;padding:12px 20px 4px">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
				<div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:0.5px">Quick Add from other brackets</div>
			</div>
			<div id="tn-teamquickadd-list" style="max-height:180px;overflow-y:auto"></div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-addteam-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-addteam-next">
				Next: Add Members <i class="fas fa-arrow-right"></i>
			</button>
			<button class="tn-btn tn-btn-primary" id="tn-addteam-submit" style="display:none">
				<i class="fas fa-check"></i> Save Team
			</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Create / Edit Team Modal (tournament-level roster)
     ============================================= -->
<?php if ($canManage): ?>
<div class="tn-overlay" id="tn-createteam-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title" id="tn-createteam-title"><i class="fas fa-users" style="margin-right:8px;color:#3182ce"></i>Create Team</h3>
			<button class="tn-modal-close" id="tn-createteam-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-createteam-feedback" class="tn-feedback"></div>
			<input type="hidden" id="tn-createteam-number" value="">
			<div class="tn-field">
				<label for="tn-createteam-name">Team Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="tn-createteam-name" placeholder="Enter team name" maxlength="100" autocomplete="off">
			</div>
			<div class="tn-field" style="margin-bottom:8px">
				<label>Members <span style="color:#a0aec0;font-size:11px;font-weight:400">(at least one required)</span></label>
				<div id="tn-createteam-members" style="margin-bottom:10px"></div>
				<div style="position:relative">
					<input type="text" id="tn-createteam-player-text" placeholder="Search by persona…" autocomplete="off">
					<div id="tn-createteam-player-results" class="kn-ac-results"></div>
				</div>
			</div>
			<div id="tn-createteam-regsection" style="margin-top:4px">
				<div style="font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">Add from registered individuals</div>
				<div id="tn-createteam-reglist" class="tn-createteam-reglist"></div>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-createteam-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-createteam-submit">
				<i class="fas fa-check"></i> Save Team
			</button>
		</div>
	</div>
</div>
<?php endif; ?>


<!-- =============================================
     Configure Standings Modal
     ============================================= -->
<?php if ($canManage): ?>
<div class="tn-overlay" id="tn-configstandings-overlay">
	<div class="tn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-cog" style="margin-right:8px;color:#276749"></i>Configure Standings</h3>
			<button class="tn-modal-close" id="tn-cs-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-cs-feedback" class="tn-feedback"></div>
			<p style="font-size:13px;color:#718096;margin:0 0 16px">Points awarded for each final placement across all brackets.</p>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
				<?php
				$_ordinals = ['1st','2nd','3rd','4th','5th','6th','7th','8th'];
				foreach ($_ordinals as $_oi => $_oLabel):
				?>
				<div class="tn-field" style="margin:0">
					<label style="font-size:11px"><?= $_oLabel ?> Place</label>
					<input type="number" class="tn-cs-pts-input" data-idx="<?= $_oi ?>" min="0" max="999" step="0.5" value="<?= (float)($standingsPoints[$_oi] ?? 0) ?>" style="text-align:center">
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-cs-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-cs-submit">
				<i class="fas fa-save"></i> Save
			</button>
		</div>
	</div>
</div>

<!-- Pools to Brackets modal -->
<div class="tn-overlay" id="tn-poolstobrackets-overlay">
	<div class="tn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-sitemap" style="margin-right:8px;color:#276749"></i>Pools to Brackets</h3>
			<button class="tn-modal-close" id="tn-p2b-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-p2b-feedback" class="tn-feedback"></div>
			<p style="font-size:13px;color:#718096;margin:0 0 16px">Create a new elimination bracket seeded from the top finishers of this Ironman pool.</p>
			<input type="hidden" id="tn-p2b-source-bid" value="">
			<div class="tn-field">
				<label>Bracket Type</label>
				<select id="tn-p2b-method">
					<option value="single">Single Elimination</option>
					<option value="double">Double Elimination</option>
				</select>
			</div>
			<div class="tn-field">
				<label>Take Top <span id="tn-p2b-pool-note" style="color:#a0aec0;font-size:11px;font-weight:400"></span></label>
				<input type="number" id="tn-p2b-topx" min="2" value="8" style="text-align:center">
			</div>
			<div class="tn-field">
				<label>Seed By</label>
				<select id="tn-p2b-seed">
					<option value="standing">Ironman Standing</option>
					<option value="warrior">Orders of the Warrior</option>
					<option value="glicko2">Performance Score</option>
					<option value="random">Random</option>
				</select>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-p2b-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-p2b-submit">
				<i class="fas fa-sitemap"></i> Create Bracket
			</button>
		</div>
	</div>
</div>
<?php endif; ?>


<!-- =============================================
     Edit Tournament Modal
     ============================================= -->
<?php if ($canManage): ?>
<div class="tn-overlay" id="tn-edittournament-overlay">
	<div class="tn-modal-box" style="width:520px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#276749"></i>Edit Tournament</h3>
			<button class="tn-modal-close" id="tn-edittournament-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-edittournament-feedback" class="tn-feedback"></div>
			<div class="tn-field">
				<label for="tn-et-name">NAME <span style="color:#e53e3e">*</span></label>
				<input type="text" id="tn-et-name" maxlength="120" placeholder="Tournament name">
			</div>
			<div class="tn-field">
				<label for="tn-et-description">ABOUT</label>
				<textarea id="tn-et-description" rows="4" placeholder="Description (optional)"></textarea>
			</div>
			<div style="display:flex;gap:14px">
				<div class="tn-field" style="flex:1">
					<label for="tn-et-date">DATE</label>
					<input type="text" id="tn-et-date" placeholder="Select a date">
				</div>
				<div class="tn-field" style="flex:1">
					<label for="tn-et-url">URL</label>
					<input type="url" id="tn-et-url" maxlength="255" placeholder="https://...">
				</div>
			</div>
			<div class="tn-field" style="position:relative">
				<label for="tn-et-park-text">HOST PARK</label>
				<input type="text" id="tn-et-park-text" autocomplete="off" placeholder="Search for a park...">
				<input type="hidden" id="tn-et-park-id" value="0">
				<input type="hidden" id="tn-et-kingdom-id" value="0">
				<div id="tn-et-park-results" class="kn-ac-results"></div>
				<div id="tn-et-kingdom-display" style="margin-top:4px;font-size:12px;color:#718096"></div>
			</div>
			<div class="tn-field" style="position:relative">
				<label>EVENT <span style="color:#a0aec0;font-weight:400">(optional)</span></label>
				<div style="display:flex;gap:8px;align-items:center">
					<input type="text" id="tn-et-event-text" autocomplete="off" placeholder="Search by event name..." style="flex:1">
					<button type="button" id="tn-et-event-clear" class="tn-btn tn-btn-ghost" style="padding:6px 10px;white-space:nowrap;font-size:12px">Clear</button>
				</div>
				<input type="hidden" id="tn-et-ecd-id" value="0">
				<div id="tn-et-event-results" class="kn-ac-results"></div>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-edittournament-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-edittournament-submit">
				<i class="fas fa-save"></i> Save Changes
			</button>
		</div>
	</div>
</div>
<?php endif; ?>


<!-- =============================================
     Add Reeve Modal (Feature 2)
     ============================================= -->
<?php if ($canManageReeves): ?>
<div class="tn-overlay" id="tn-addreeve-overlay">
	<div class="tn-modal-box" style="width:460px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-user-shield" style="margin-right:8px;color:#276749"></i>Add Reeve</h3>
			<button class="tn-modal-close" id="tn-addreeve-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-addreeve-feedback" class="tn-feedback"></div>
			<div class="tn-field" style="position:relative">
				<label for="tn-addreeve-player-text">PLAYER <span style="color:#e53e3e">*</span></label>
				<input type="text" id="tn-addreeve-player-text" autocomplete="off" placeholder="Search by persona...">
				<input type="hidden" id="tn-addreeve-player-id" value="0">
				<div id="tn-addreeve-results" class="kn-ac-results"></div>
			</div>
			<div class="tn-field">
				<label for="tn-addreeve-role">ROLE</label>
				<select id="tn-addreeve-role">
					<option value="bracket_runner">Bracket Runner &mdash; record match results only</option>
					<option value="organizer">Organizer &mdash; full tournament management</option>
				</select>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-addreeve-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-addreeve-submit" disabled>
				<i class="fas fa-plus"></i> Add Reeve
			</button>
		</div>
	</div>
</div>
<?php endif; ?>


<!-- =============================================
     Record Result Modal
     ============================================= -->
<?php if ($canRecordResult): ?>
<div class="tn-overlay" id="tn-recordresult-overlay">
	<div class="tn-modal-box" style="width:460px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-check-circle" style="margin-right:8px;color:#276749"></i>Record Result</h3>
			<button class="tn-modal-close" id="tn-recordresult-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-recordresult-feedback" class="tn-feedback"></div>
			<input type="hidden" id="tn-recordresult-match-id" value="">
			<input type="hidden" id="tn-recordresult-tournament-id" value="<?= $tid ?>">
			<div style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:14px">
				<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
					<div style="flex:1;text-align:center">
						<div id="tn-rr-p1-name" style="font-size:14px;font-weight:700;color:#1a202c">—</div>
						<div class="tn-bout-pips" id="tn-rr-pips-1">
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="0"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="1"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="2"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="3"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="4"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="5"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="6"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="7"></button>
							<button class="tn-bout-pip" type="button" data-side="1" data-idx="8"></button>
						</div>
					</div>
					<div style="font-size:12px;color:#a0aec0;font-weight:600;padding-top:4px;flex-shrink:0">vs</div>
					<div style="flex:1;text-align:center">
						<div id="tn-rr-p2-name" style="font-size:14px;font-weight:700;color:#1a202c">—</div>
						<div class="tn-bout-pips" id="tn-rr-pips-2">
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="0"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="1"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="2"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="3"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="4"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="5"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="6"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="7"></button>
							<button class="tn-bout-pip" type="button" data-side="2" data-idx="8"></button>
						</div>
					</div>
				</div>
				<div id="tn-rr-bout-score" class="tn-bout-score"></div>
				<div id="tn-rr-round-info" style="text-align:center;font-size:11px;color:#a0aec0;margin-top:4px"></div>
			</div>
			<div class="tn-field">
				<label for="tn-rr-result">RESULT <span style="color:#e53e3e">*</span></label>
				<select id="tn-rr-result">
					<option value="">— select —</option>
					<option value="1-wins" id="tn-rr-opt-p1wins">— wins</option>
					<option value="2-wins" id="tn-rr-opt-p2wins">— wins</option>
					<option value="tie" id="tn-rr-opt-tie" disabled>Tie</option>
					<option value="1-forfeits" id="tn-rr-opt-p1ff">— forfeits</option>
					<option value="2-forfeits" id="tn-rr-opt-p2ff">— forfeits</option>
					<option value="1-is-disqualified" id="tn-rr-opt-p1dq">— disqualified</option>
					<option value="2-is-disqualified" id="tn-rr-opt-p2dq">— disqualified</option>
				</select>
			</div>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-recordresult-cancel">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-recordresult-submit">
				<i class="fas fa-check"></i> Save Result
			</button>
		</div>
	</div>
</div>
<?php endif; ?>


<?php if ($canRecommend): ?>
<!-- =============================================
     Recommend Award Modal (Feature 3)
     ============================================= -->
<div class="tn-overlay" id="tn-rec-overlay">
	<div class="tn-modal-box" style="width:460px;max-width:calc(100vw - 40px)">
		<div class="tn-modal-header">
			<h3 class="tn-modal-title"><i class="fas fa-star" style="margin-right:8px;color:#d69e2e"></i>Recommend an Award</h3>
			<button class="tn-modal-close" id="tn-rec-close">&times;</button>
		</div>
		<div class="tn-modal-body">
			<div id="tn-rec-feedback" class="tn-feedback"></div>
			<form id="tn-rec-form" method="post" action="">
				<input type="hidden" name="AwardId" id="tn-rec-award-id" value="">
				<p class="tn-rec-target">Recommending <strong id="tn-rec-persona"></strong> for <strong id="tn-rec-award-name"></strong>.</p>
				<div class="tn-field" id="tn-rec-rank-row">
					<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">&mdash; click to select</span></label>
					<div class="tn-rec-standing" id="tn-rec-standing"></div>
					<div class="tn-rank-pills" id="tn-rec-rank-pills" role="group" aria-label="Rank"></div>
					<input type="hidden" name="Rank" id="tn-rec-rank-val" value="">
				</div>
				<div class="tn-field">
					<label for="tn-rec-reason">Reason <span style="color:#e53e3e">*</span></label>
					<input type="text" name="Reason" id="tn-rec-reason" maxlength="400" placeholder="Why should this player receive this award?">
					<span class="tn-char-count" id="tn-rec-char-count">400 characters remaining</span>
				</div>
			</form>
		</div>
		<div class="tn-modal-footer">
			<button class="tn-btn tn-btn-ghost" id="tn-rec-cancel" type="button">Cancel</button>
			<button class="tn-btn tn-btn-primary" id="tn-rec-submit" type="button"><i class="fas fa-paper-plane"></i> Submit Recommendation</button>
		</div>
	</div>
</div>
<?php endif; ?>


<!-- =============================================
     Config + Scripts
     ============================================= -->
<script>
<?php
// #63 — Lazy-load the heaviest per-tab payload (BracketViz/Run match data).
// The full match set for EVERY bracket is the largest chunk embedded here and is
// only needed once the BracketViz/Run tab is opened. Ship bracketData WITHOUT the
// per-bracket Matches arrays (keeping keys, Bracket meta, and Participants that the
// collab loop, leaderboard, quick-add, and seeding read synchronously at load), plus
// a lightweight `_hasMatches` flag so the "default to Run tab" heuristic and
// firstBracketId() still work. Matches are fetched per bracket on first render via
// the existing refresh helper (see tnRenderBracketViz lazy-load guard) and cached.
$bracketDataLite = [];
foreach (($bracketData ?? []) as $__bid => $__bd) {
    $__copy = $__bd;
    $__copy['_hasMatches'] = !empty($__bd['Matches']) && count($__bd['Matches']) > 0;
    unset($__copy['Matches']);
    $bracketDataLite[$__bid] = $__copy;
}
?>
var TnConfig = {
	uir:                  <?= json_encode(UIR, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	httpService:          <?= json_encode(HTTP_SERVICE, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	tournamentId:         <?= $tid ?>,
	kingdomId:            <?= $tKingdomId ?>,
	searchKingdomId:      <?= $tSearchKingdomId ?>,
	kingdomName:          <?= json_encode($tKingdomName, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	parkId:               <?= $tParkId ?>,
	parkName:             <?= json_encode($tParkName, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	tournamentName:       <?= json_encode($tName, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	tournamentDescription:<?= json_encode($tDescription, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	tournamentDate:       <?= json_encode(($tDate && substr($tDate,0,10) !== '0000-00-00') ? substr($tDate,0,10) : '', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	tournamentUrl:        <?= json_encode($tUrl, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	ecdId:                <?= $tECDId ?>,
	eventLabel:           <?= json_encode($tEventLabel, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	eventName:            <?= json_encode($tEventName, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	canManage:            <?= $canManage ? 'true' : 'false' ?>,
	canManageReeves:      <?= $canManageReeves ? 'true' : 'false' ?>,
	isOrganizerReeve:     <?= $isOrganizerReeve ? 'true' : 'false' ?>,
	isBracketRunner:      <?= $isBracketRunner ? 'true' : 'false' ?>,
	canRecommend:         <?= $canRecommend ? 'true' : 'false' ?>,
	canRecordResult:      <?= $canRecordResult ? 'true' : 'false' ?>,
	hasActiveBracket:     <?= $hasActiveBracket ? 'true' : 'false' ?>,
	spectator:            <?= $isSpectator ? 'true' : 'false' ?>,
	currentUserId:        <?= $tCurrentUserId ?>,
	loggedIn:             <?= $loggedIn ? 'true' : 'false' ?>,
	bracketData:          <?= json_encode($bracketDataLite, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	methodLabels:         <?= json_encode($methodLabelMap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	styleLabels:          <?= json_encode($styleLabelMap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	standingsData:        <?= json_encode($standingsData, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	standingsPoints:      <?= json_encode($standingsPoints, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	registrants:          <?= json_encode($registrants ?? [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
	registeredTeams:      <?= json_encode($registered_teams ?? [], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
};
// Issue 2: Individual/Team segmented-control helpers (mirrors tn-...-firstround).
function tnSegGet(id) {
	var el = document.getElementById(id); if (!el) return '';
	var a = el.querySelector('.tn-seg-btn.tn-seg-active');
	return a ? a.getAttribute('data-val') : '';
}
function tnSegSet(id, val) {
	var el = document.getElementById(id); if (!el) return;
	Array.prototype.forEach.call(el.querySelectorAll('.tn-seg-btn'), function(b) {
		b.classList.toggle('tn-seg-active', b.getAttribute('data-val') === val);
	});
}
(function() {
	['tn-addbracket-participants', 'tn-editbracket-participants'].forEach(function(id) {
		var seg = document.getElementById(id); if (!seg) return;
		// Make the .tn-seg behave like a form control with a .value, so the mobile
		// wizard (radioStep) and the desktop toggle share one source of truth.
		try {
			Object.defineProperty(seg, 'value', {
				configurable: true,
				get: function() { return tnSegGet(id); },
				set: function(v) { tnSegSet(id, v); }
			});
		} catch (e) { /* already defined */ }
		seg.addEventListener('click', function(e) {
			var b = e.target.closest('.tn-seg-btn'); if (!b || b.disabled) return;
			Array.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(x) { x.classList.remove('tn-seg-active'); });
			b.classList.add('tn-seg-active');
		});
	});
})();
document.title = 'ORK 3: ' + TnConfig.tournamentName;

// =============================================
// Mobile foundation (PHASE C0) — TnMobile namespace.
// Created with the `|| {}` idiom so sibling foundation tasks (swipe, sheet,
// deck) can extend it without clobbering. Other tasks depend on the EXACT
// names below: TnMobile.viewMode.init()/.set('mobile'|'desktop')/.isMobile(),
// the `tn:viewmodechange` event, the `tn-mobile` class on #tn-root, and the
// sessionStorage key `tnViewMode_<tournamentId>`.
// =============================================
window.TnMobile = window.TnMobile || {};
// Shared helpers (de-duped): single source for #tn-root lookup and the
// mobile-view predicate. Local wrappers across the IIFEs delegate here.
TnMobile._rootEl = function() { return document.getElementById('tn-root'); };
TnMobile.isMobile = function() { return !!(TnMobile.viewMode && TnMobile.viewMode.isMobile && TnMobile.viewMode.isMobile()); };
(function() {
	var MQ_QUERY  = '(max-width:768px)';
	var STORE_KEY = 'tnViewMode_' + TnConfig.tournamentId;

	function rootEl()  { return TnMobile._rootEl(); }
	function stored()  { try { return sessionStorage.getItem(STORE_KEY); } catch (e) { return null; } }
	function persist(v) { try { sessionStorage.setItem(STORE_KEY, v); } catch (e) {} }

	var _mobile = false;   // current resolved state
	var _pill   = null;    // floating toggle pill element

	function applyClass(isMobile) {
		_mobile = !!isMobile;
		// Toggle on <body>, NOT #tn-root: the modal overlays/sheets are siblings of
		// #tn-root, so a class on #tn-root can't match `.tn-mobile .tn-overlay` rules.
		document.body.classList.toggle('tn-mobile', _mobile);
		var root = rootEl();
		if (root) {
			root.dispatchEvent(new CustomEvent('tn:viewmodechange', {
				bubbles: true,
				detail: { mode: _mobile ? 'mobile' : 'desktop', isMobile: _mobile }
			}));
		}
		updatePill();
	}

	function updatePill() {
		if (!_pill) return;
		// Pill shows the mode you'd switch TO.
		var toDesktop = _mobile;
		var label = toDesktop ? 'Desktop view' : 'Mobile view';
		var icon  = toDesktop ? 'fa-desktop'   : 'fa-mobile-alt';
		_pill.innerHTML = '<i class="fas ' + icon + '"></i><span>' + label + '</span>';
		_pill.setAttribute('data-tip', label);
		_pill.setAttribute('aria-label', label);
	}

	function renderPill() {
		if (_pill) return;
		_pill = document.createElement('button');
		_pill.type = 'button';
		_pill.className = 'tn-mq-toggle';
		_pill.addEventListener('click', function() {
			TnMobile.viewMode.set(_mobile ? 'desktop' : 'mobile');
		});
		document.body.appendChild(_pill);
		updatePill();
	}

	TnMobile.viewMode = {
		init: function() {
			renderPill();
			// Desktop width always renders desktop; the view override (and the
			// toggle pill, hidden by CSS above 768px) only apply at mobile width.
			var mql = window.matchMedia(MQ_QUERY);
			function resolve() {
				if (!mql.matches) { applyClass(false); return; }   // desktop width -> desktop
				applyClass(stored() !== 'desktop');                // mobile width -> mobile unless user chose desktop
			}
			resolve();
			var onChange = function() { resolve(); };
			if (mql.addEventListener)    mql.addEventListener('change', onChange);
			else if (mql.addListener)    mql.addListener(onChange); // legacy Safari
		},
		set: function(mode) {
			var isMobile = (mode === 'mobile');
			persist(isMobile ? 'mobile' : 'desktop'); // manual override
			applyClass(isMobile); // applyClass dispatches tn:viewmodechange
		},
		isMobile: function() { return _mobile; }
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() { TnMobile.viewMode.init(); });
	} else {
		TnMobile.viewMode.init();
	}
})();

// =============================================
// Swipe utility (PHASE C0 Task 2) — TnMobile.swipe.
//
//   var destroy = TnMobile.swipe(el, {
//       onLeft, onRight, onUp, onDown,   // all optional
//       threshold: 40,                   // min primary-axis distance to fire
//       restraint: 60                    // max cross-axis drift allowed
//   });
//   destroy();                           // detach listeners (re-mount-safe)
//
// Behavior:
//   - touchstart records the origin (x, y, time).
//   - touchmove axis-LOCKS to the dominant axis once |dx| OR |dy| first
//     exceeds START_DELTA (10px); once locked it stays that axis for the
//     whole gesture, so vertical scroll never masquerades as a horizontal
//     swipe (and vice-versa).
//   - touchend fires the matching callback ONLY when the primary-axis travel
//     is >= threshold AND the cross-axis drift is <= restraint.
//
// GESTURE-ARBITRATION CONTRACT (critical — C1 consumers rely on this):
// the swipe is fully SUPPRESSED (no callback, no interference with native
// scroll) when EITHER:
//   (a) the touchstart target is, or is inside, an element matching
//       [data-tn-no-swipe] (drag handles, sliders, etc.), OR
//   (b) TnMobile.dragActive === true (set by the touch-DnD reorder while a
//       drag is live). NOTE: dragActive is intentionally only READ here; a
//       later task owns setting it. An undefined property reads falsy, which
//       is the correct default.
//
// Listeners are registered {passive:true} — this utility NEVER calls
// preventDefault, so it stays scroll-friendly and lets the browser optimize.
// =============================================
(function() {
	TnMobile.swipe = function(el, opts) {
		if (!el) return function() {};
		opts = opts || {};
		var threshold = (typeof opts.threshold === 'number') ? opts.threshold : 40;
		var restraint = (typeof opts.restraint === 'number') ? opts.restraint : 60;
		var START_DELTA = 10; // px before we commit to an axis

		var startX = 0, startY = 0;
		var axis = null;        // null | 'x' | 'y' — locked once set
		var tracking = false;   // false when the gesture is suppressed
		var PASSIVE = { passive: true };

		function suppressed(target) {
			// (b) a live touch-DnD drag wins over any swipe.
			if (TnMobile.dragActive) return true;
			// (a) gesture starting on / inside an opt-out element.
			if (target && target.closest && target.closest('[data-tn-no-swipe]')) return true;
			return false;
		}

		function onStart(e) {
			var t = e.touches && e.touches[0];
			if (!t) { tracking = false; return; }
			if (suppressed(e.target)) { tracking = false; return; }
			tracking = true;
			axis = null;
			startX = t.clientX;
			startY = t.clientY;
		}

		function onMove(e) {
			if (!tracking) return;
			// dragActive can flip mid-gesture; respect it immediately.
			if (TnMobile.dragActive) { tracking = false; return; }
			var t = e.touches && e.touches[0];
			if (!t) return;
			if (axis) return; // already locked
			var dx = Math.abs(t.clientX - startX);
			var dy = Math.abs(t.clientY - startY);
			if (dx > START_DELTA || dy > START_DELTA) {
				axis = (dx >= dy) ? 'x' : 'y';
			}
		}

		function onEnd(e) {
			if (!tracking) return;
			tracking = false;
			if (TnMobile.dragActive) return;
			var t = (e.changedTouches && e.changedTouches[0]);
			if (!t) return;
			var dx = t.clientX - startX;
			var dy = t.clientY - startY;
			if (axis === 'x') {
				if (Math.abs(dx) >= threshold && Math.abs(dy) <= restraint) {
					if (dx < 0) { if (opts.onLeft)  opts.onLeft(e);  }
					else        { if (opts.onRight) opts.onRight(e); }
				}
			} else if (axis === 'y') {
				if (Math.abs(dy) >= threshold && Math.abs(dx) <= restraint) {
					if (dy < 0) { if (opts.onUp)   opts.onUp(e);   }
					else        { if (opts.onDown) opts.onDown(e); }
				}
			}
		}

		function onCancel() { tracking = false; axis = null; }

		el.addEventListener('touchstart',  onStart,  PASSIVE);
		el.addEventListener('touchmove',   onMove,   PASSIVE);
		el.addEventListener('touchend',    onEnd,    PASSIVE);
		el.addEventListener('touchcancel', onCancel, PASSIVE);

		return function destroy() {
			el.removeEventListener('touchstart',  onStart,  PASSIVE);
			el.removeEventListener('touchmove',   onMove,   PASSIVE);
			el.removeEventListener('touchend',    onEnd,    PASSIVE);
			el.removeEventListener('touchcancel', onCancel, PASSIVE);
		};
	};
})();

// =============================================
// Bottom-sheet + action-sheet primitive (PHASE C0 Task 3) — TnMobile.sheet.
//
// API (C1 tracks depend on these EXACT names):
//   TnMobile.sheet.open(el, { variant, onDismiss })
//   TnMobile.sheet.close(el)
//   TnMobile.sheet.actionSheet([{ label, danger, onTap }, ...])  -> element
//
// NO DOM DUPLICATION CONTRACT:
//   A sheet is a `.tn-mobile`-only PRESENTATION of an EXISTING `.tn-overlay`
//   element. `open(el)`/`close(el)` simply add/remove the same `.tn-open`
//   class the existing tnOpenModal/tnCloseModal use, so the existing
//   open/close transitions and DESKTOP centered-overlay behavior are
//   untouched. Under `.tn-mobile`, CSS re-styles that same markup into a
//   bottom sheet; on desktop it stays a centered overlay. The only extra
//   behavior `open` layers on is: backdrop-tap dismiss + swipe-down dismiss +
//   keyboard-safe height tracking — all of which are harmless on desktop
//   (swipe is touch-only; backdrop-tap matches the existing overlay UX).
//
//   `actionSheet(items)` is the ONE case that builds fresh DOM (there is no
//   existing markup for a generic option menu); it builds a `.tn-overlay`
//   styled with the `tn-sheet--action` auto-height variant and opens it via
//   the same `open()` path, then removes itself from the DOM on close.
//
// KEYBOARD-SAFE HEIGHT: while a sheet is open we set `--tn-vvh` on #tn-root to
// the visualViewport height (px) so the sheet's `max-height` (and thus its
// sticky footer) tracks the area above the soft keyboard. When
// `window.visualViewport` is undefined the var is left unset and the CSS falls
// back to its `92dvh`/`92vh` declarations.
// =============================================
(function() {
	function rootEl() { return TnMobile._rootEl(); }
	function isMobile() { return TnMobile.isMobile(); }

	// --- visualViewport height tracking (shared by all open sheets) ---
	var _vvCount = 0;          // number of currently-open sheets needing the var
	function applyVvh() {
		var root = rootEl();
		if (!root) return;
		var vv = window.visualViewport;
		if (vv) root.style.setProperty('--tn-vvh', vv.height + 'px');
		// No fallback assignment: CSS handles the undefined case via dvh/vh.
	}
	function onVvResize() { if (_vvCount > 0) applyVvh(); }
	if (window.visualViewport) {
		window.visualViewport.addEventListener('resize', onVvResize);
		window.visualViewport.addEventListener('scroll', onVvResize);
	}
	function startVvTracking() {
		_vvCount++;
		applyVvh();
	}
	function stopVvTracking() {
		_vvCount = Math.max(0, _vvCount - 1);
		if (_vvCount === 0) {
			var root = rootEl();
			if (root) root.style.removeProperty('--tn-vvh');
		}
	}

	// --- Focus trap (lightweight) ---
	var FOCUSABLE = 'a[href],area[href],input:not([disabled]),select:not([disabled]),' +
		'textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])';
	function trapFocus(box, e) {
		if (e.key !== 'Tab') return;
		var nodes = box.querySelectorAll(FOCUSABLE);
		if (!nodes.length) return;
		var first = nodes[0], last = nodes[nodes.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}

	TnMobile.sheet = {
		// open(el, opts): present an existing .tn-overlay element as a sheet
		// (mobile) or centered overlay (desktop). opts.variant === 'action'
		// applies the auto-height action-sheet style. opts.onDismiss runs on
		// backdrop-tap / swipe-down / Esc dismissal.
		open: function(el, opts) {
			if (!el) return;
			if (el._tnSheet) return el; // already open — avoid duplicate listeners / _vvCount drift
			opts = opts || {};
			var box = el.querySelector('.tn-modal-box');

			if (opts.variant === 'action') el.classList.add('tn-sheet--action');

			// Reuse the existing overlay open transition.
			el.classList.add('tn-open');

			// Everything below is the mobile sheet layer. On desktop the element
			// is just a normal centered overlay — skip the sheet-only bindings so
			// desktop behavior is identical to tnOpenModal.
			if (!isMobile()) {
				el._tnSheet = { opts: opts, mobile: false };
				return el;
			}

			startVvTracking();

			// Backdrop-tap to dismiss (tap on the overlay itself, not the box).
			var onBackdrop = function(ev) {
				if (ev.target === el) TnMobile.sheet.close(el);
			};
			el.addEventListener('click', onBackdrop);

			// Swipe-down on the box dismisses (per the foundation swipe util).
			var destroySwipe = box
				? TnMobile.swipe(box, { onDown: function() { TnMobile.sheet.close(el); }, threshold: 50 })
				: function() {};

			// Esc to dismiss + focus trap.
			var onKey = function(ev) {
				if (ev.key === 'Escape') { ev.preventDefault(); TnMobile.sheet.close(el); return; }
				if (box) trapFocus(box, ev);
			};
			document.addEventListener('keydown', onKey);

			el._tnSheet = {
				opts: opts,
				mobile: true,
				onBackdrop: onBackdrop,
				destroySwipe: destroySwipe,
				onKey: onKey
			};

			// Move focus into the sheet (first focusable, else the box).
			if (box) {
				var f = box.querySelector(FOCUSABLE);
				if (f) { try { f.focus(); } catch (e) {} }
			}
			return el;
		},

		// close(el): reverse the open animation and fire onDismiss. Tears down
		// every listener/tracker open() attached so there is no leak.
		close: function(el) {
			if (!el) return;
			var st = el._tnSheet || null;
			el.classList.remove('tn-open');

			if (st && st.mobile) {
				el.removeEventListener('click', st.onBackdrop);
				if (st.destroySwipe) st.destroySwipe();
				document.removeEventListener('keydown', st.onKey);
				stopVvTracking();
			}

			// Close any autocomplete dropdowns this sheet opened (they live on
			// <body>; their re-anchor listeners self-tear-down on close).
			document.querySelectorAll('.kn-ac-results.kn-ac-open')
				.forEach(function(d) { d.classList.remove('kn-ac-open'); });

			var onDismiss = st && st.opts && st.opts.onDismiss;

			// Action sheets are freshly built; remove from DOM after the
			// transition so they don't accumulate. Match the .2s overlay anim.
			if (el.classList.contains('tn-sheet--action')) {
				setTimeout(function() {
					if (el.parentNode) el.parentNode.removeChild(el);
				}, 250);
			}
			el._tnSheet = null;
			if (typeof onDismiss === 'function') onDismiss();
		},

		// actionSheet(items): build + open a content-sized action menu.
		// items: [{ label, danger?, onTap }]. Returns the overlay element.
		actionSheet: function(items) {
			items = items || [];
			var el = document.createElement('div');
			el.className = 'tn-overlay';
			var box = document.createElement('div');
			box.className = 'tn-modal-box';
			box.style.width = '100%';
			var list = document.createElement('div');
			list.className = 'tn-sheet-action-list';

			items.forEach(function(it) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'tn-sheet-action-item' + (it.danger ? ' tn-sheet-action--danger' : '');
				b.textContent = it.label || '';
				b.addEventListener('click', function() {
					TnMobile.sheet.close(el);
					if (typeof it.onTap === 'function') it.onTap();
				});
				list.appendChild(b);
			});

			// Always offer a cancel row.
			var cancel = document.createElement('button');
			cancel.type = 'button';
			cancel.className = 'tn-sheet-action-item tn-sheet-action-cancel';
			cancel.textContent = 'Cancel';
			cancel.addEventListener('click', function() { TnMobile.sheet.close(el); });
			list.appendChild(cancel);

			box.appendChild(list);
			el.appendChild(box);
			document.body.appendChild(el);

			// Force reflow so the slide-up transition runs from the closed state.
			void el.offsetWidth;
			TnMobile.sheet.open(el, { variant: 'action' });
			return el;
		}
	};

	// On switch to DESKTOP, any open sheet reverts to centered-overlay behavior:
	// drop the sheet-only listeners/trackers and the action variant marker, but
	// keep the overlay OPEN (it's still a valid centered modal on desktop). The
	// CSS sheet rules are gated on `.tn-mobile`, so removing that class (done by
	// viewMode) is what visually reverts it; here we just clean up the JS layer.
	(function bindViewModeRevert() {
		var root = rootEl();
		if (!root) {
			document.addEventListener('DOMContentLoaded', bindViewModeRevert);
			return;
		}
		root.addEventListener('tn:viewmodechange', function(ev) {
			if (ev.detail && ev.detail.isMobile) return; // only on -> desktop
			document.querySelectorAll('.tn-overlay.tn-open').forEach(function(el) {
				var st = el._tnSheet;
				if (!st || !st.mobile) return;
				el.removeEventListener('click', st.onBackdrop);
				if (st.destroySwipe) st.destroySwipe();
				document.removeEventListener('keydown', st.onKey);
				stopVvTracking();
				// Freshly-built action sheets have no desktop home — close them.
				if (el.classList.contains('tn-sheet--action')) {
					TnMobile.sheet.close(el);
				} else {
					// Existing overlay: keep open as a centered modal.
					st.mobile = false;
				}
			});
		});
	})();
})();

// =============================================
// Card-deck primitive (PHASE C0 Task 4) — TnMobile.deck.
//
// API (Track R depends on these EXACT names):
//   var handle = TnMobile.deck.mount(container, {
//       items,           // array of objects, each with a stable `id` (string|number)
//       renderFull,      // fn(item) -> HTML STRING for the lead (full) card body
//       renderCompact,   // fn(item) -> HTML STRING for a compact on-deck card body
//       onLeadChange     // fn(leadId) — optional; fired whenever the lead changes
//   });
//   handle.setLead(id)   // jump: make item with `id` the full lead (Bout-List contract)
//   handle.update(items) // re-render with a new array, preserving current lead by id
//   handle.getLeadId()   // current lead id (convenience)
//   handle.destroy()     // tear down swipe + clear container
//
// RENDER CONTRACT: renderFull/renderCompact return HTML STRINGS (matching this
//   file's templating conventions). The primitive wraps each in the
//   `.tn-deck-card--full` / `.tn-deck-card--compact` chrome.
//
// WINDOWING: the primitive renders the lead FULL + the REST compact for whatever
//   array it is given. It does NOT slice/window internally — the CONSUMER passes a
//   pre-sliced array (e.g. Track R passes current + next 2 = 3 items). This keeps
//   the "current + next N" policy out of the primitive.
//
// GESTURES: swipe-left = advance lead (next item), swipe-right = previous item,
//   clamped to [0, items.length-1]; tapping a compact card promotes it to lead.
//   Uses TnMobile.swipe, so it honors [data-tn-no-swipe] + TnMobile.dragActive.
//
// onLeadChange fires only when the lead id ACTUALLY changes (no redundant calls).
// =============================================
(function() {
	function idOf(item) { return (item && item.id != null) ? item.id : null; }

	// Find the array index of a given id; -1 if absent. Loose-ish compare so a
	// numeric id and its string form match (HTML data attrs round-trip as strings).
	function indexOfId(items, id) {
		if (id == null) return -1;
		for (var i = 0; i < items.length; i++) {
			var iid = idOf(items[i]);
			if (iid === id || String(iid) === String(id)) return i;
		}
		return -1;
	}

	function clamp(n, lo, hi) { return n < lo ? lo : (n > hi ? hi : n); }

	TnMobile.deck = {
		mount: function(container, opts) {
			if (!container) return null;
			opts = opts || {};
			var items        = Array.isArray(opts.items) ? opts.items.slice() : [];
			var renderFull   = opts.renderFull   || function() { return ''; };
			var renderCompact= opts.renderCompact || function() { return ''; };
			var onLeadChange = opts.onLeadChange || null;

			var leadIndex = 0;        // index into `items` that is the FULL lead
			var lastLeadId = undefined; // last id we announced via onLeadChange
			var destroySwipe = null;

			function currentLeadId() {
				return items.length ? idOf(items[leadIndex]) : null;
			}

			// Announce a lead change, but only if the id actually moved.
			function announce() {
				var id = currentLeadId();
				if (id === lastLeadId) return;
				lastLeadId = id;
				if (onLeadChange) onLeadChange(id);
			}

			function render() {
				// Build fresh DOM; full re-render is fine for these small lists.
				container.classList.add('tn-deck');
				container.innerHTML = '';
				leadIndex = clamp(leadIndex, 0, Math.max(0, items.length - 1));

				items.forEach(function(item, i) {
					var card = document.createElement('div');
					if (i === leadIndex) {
						card.className = 'tn-deck-card tn-deck-card--full';
						card.innerHTML = renderFull(item);
					} else {
						card.className = 'tn-deck-card tn-deck-card--compact';
						card.setAttribute('data-tn-deck-id', String(idOf(item)));
						card.innerHTML = renderCompact(item);
						// Tap-to-promote. (closure binds the item's id at render time)
						(function(promoteId) {
							card.addEventListener('click', function() { setLead(promoteId); });
						})(idOf(item));
					}
					container.appendChild(card);
				});
			}

			// --- Lead movement helpers (all funnel through here) ---
			function setLeadIndex(nextIndex) {
				if (!items.length) return;
				var clamped = clamp(nextIndex, 0, items.length - 1);
				if (clamped === leadIndex) return; // no-op: lead unchanged
				leadIndex = clamped;
				render();
				announce();
			}

			function setLead(id) {
				var idx = indexOfId(items, id);
				if (idx === -1) return;            // unknown id -> no-op
				setLeadIndex(idx);
			}

			function advance()  { setLeadIndex(leadIndex + 1); }
			function previous() { setLeadIndex(leadIndex - 1); }

			// --- Re-render with a new array, preserving the current lead by id ---
			function update(nextItems) {
				var prevLeadId = currentLeadId();
				items = Array.isArray(nextItems) ? nextItems.slice() : [];
				var keep = indexOfId(items, prevLeadId);
				leadIndex = (keep !== -1) ? keep : 0; // fall back to index 0 if gone
				render();
				announce();
			}

			function destroy() {
				if (destroySwipe) { destroySwipe(); destroySwipe = null; }
				container.innerHTML = '';
				container.classList.remove('tn-deck');
			}

			// Initial paint + swipe binding. Bind swipe ONCE on the container; it
			// reads the live `advance`/`previous` closures, so re-renders (which
			// replace child nodes) never leak listeners.
			render();
			destroySwipe = TnMobile.swipe(container, {
				onLeft:  function() { advance();  },
				onRight: function() { previous(); }
			});
			// Seed lastLeadId so the first real change announces (don't fire on mount).
			lastLeadId = currentLeadId();

			return {
				setLead:   setLead,
				update:    update,
				getLeadId: currentLeadId,
				destroy:   destroy
			};
		}
	};
})();
</script>

<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
// ---- Match result → winning side (1 | 2 | 0 for tie/score/unknown) ----
// Mirrors the server's TournamentReport::ResolveWinnerId / resolveWinnerLoser:
// "N-forfeits"/"N-is-disqualified"/"N-is-bye" name the DISADVANTAGED side, and the
// legacy side-less 'forfeit'/'disqualified' mean participant 1 lost (P2 wins).
function tnWinnerSide(result) {
	switch (String(result || '')) {
		case '1-wins': case '2-forfeits': case '2-is-disqualified': case '2-is-bye':
			return 1;
		case '2-wins': case 'forfeit': case 'disqualified':
		case '1-forfeits': case '1-is-disqualified': case '1-is-bye':
			return 2;
		default:
			return 0;
	}
}
// ---- Lazy Flatpickr loader ----
// Flatpickr is only needed when a manager opens the Edit Tournament modal, so
// we load the CDN assets on first use rather than on every (often read-only)
// page view. Subsequent calls invoke the callback immediately.
var _tnFpState = 'idle'; // idle | loading | ready | failed
var _tnFpQueue = [];
// CDN miss fallback: promote the (text) date field to a native date control so
// it stays a localized picker instead of exposing a bare ISO string (#109). The
// field's value is already Y-m-d, which is exactly what type=date expects and
// what the submit handler reads back, so the swap is transparent.
function tnDateNativeFallback() {
	var dEl = document.getElementById('tn-et-date');
	if (dEl && dEl.type !== 'date') { try { dEl.type = 'date'; } catch (e) {} }
}
function tnEnsureFlatpickr(cb) {
	if (_tnFpState === 'ready' || typeof flatpickr === 'function') { _tnFpState = 'ready'; cb(); return; }
	if (_tnFpState === 'failed') { tnDateNativeFallback(); return; }   // don't re-hit a dead CDN
	_tnFpQueue.push(cb);
	if (_tnFpState === 'loading') return;
	_tnFpState = 'loading';
	var link = document.createElement('link');
	link.rel = 'stylesheet';
	link.href = 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css';
	document.head.appendChild(link);
	var scr = document.createElement('script');
	scr.src = 'https://cdn.jsdelivr.net/npm/flatpickr';
	scr.onload = function() {
		_tnFpState = 'ready';
		_tnFpQueue.forEach(function(fn) { fn(); });
		_tnFpQueue = [];
	};
	scr.onerror = function() {
		// CDN unavailable — fall back to the native date control instead of a raw ISO string.
		_tnFpState = 'failed';
		_tnFpQueue = [];
		tnDateNativeFallback();
	};
	document.head.appendChild(scr);
}

// ---- Tab switching ----
var _tnTabNav = null, _tnTabPanels = null;
function tnActivateTab(name) {
	if (!_tnTabNav)    _tnTabNav    = document.querySelectorAll('#tn-tab-nav li');
	if (!_tnTabPanels) _tnTabPanels = document.querySelectorAll('.tn-tab-panel');
	_tnTabNav.forEach(function(li) {
		var on = li.dataset.tntab === name;
		li.classList.toggle('tn-tab-active', on);
		// role=tab items carry aria-selected + roving tabindex (#103).
		if (li.getAttribute('role') === 'tab') {
			li.setAttribute('aria-selected', on ? 'true' : 'false');
			li.tabIndex = on ? 0 : -1;
		}
	});
	_tnTabPanels.forEach(function(p) {
		p.style.display = p.id === 'tn-tab-' + name ? '' : 'none';
	});
	// Keep the URL hash on the open tab so a reload / shared link returns here.
	if (document.getElementById('tn-tab-' + name) && window.location.hash !== '#' + name) {
		try { history.replaceState(history.state, '', '#' + name); } catch (e) {}
	}
}

// Keyboard operability for the primary tab bar + generic clickable cards/rows (#103).
(function() {
	var nav = document.getElementById('tn-tab-nav');
	if (nav) {
		nav.addEventListener('keydown', function(e) {
			var tab = e.target.closest ? e.target.closest('li[role="tab"]') : null;
			if (!tab) return;
			if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
				e.preventDefault();
				tnActivateTab(tab.dataset.tntab);
				tab.focus();
				return;
			}
			if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
			var tabs = Array.prototype.slice.call(nav.querySelectorAll('li[role="tab"]'));
			var i = tabs.indexOf(tab);
			var next = tab;
			if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = tabs[(i + 1) % tabs.length];
			else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = tabs[(i - 1 + tabs.length) % tabs.length];
			else if (e.key === 'Home') next = tabs[0];
			else if (e.key === 'End') next = tabs[tabs.length - 1];
			e.preventDefault();
			tnActivateTab(next.dataset.tntab);
			next.focus();
		});
	}
	// Generic: Enter/Space activates any role=button element opted in via data-tn-keyclick.
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
		var t = e.target;
		if (e.repeat && t && t.classList && t.classList.contains('tn-bv-hit')) { e.preventDefault(); return; }
		if (t && t.getAttribute && t.getAttribute('role') === 'button' && t.hasAttribute('data-tn-keyclick')) {
			e.preventDefault();
			t.click();
		}
	});
})();

function tnScrollToBracket(bracketId) {
	var el = document.getElementById('tn-bracket-' + bracketId);
	if (el) { setTimeout(function() { el.scrollIntoView({behavior:'smooth',block:'start',inline:'nearest'}); }, 80); }
}

// ---- Modal helpers ----
function tnEsc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ---- DataTables for the read-only tn tables (house rule) ----
// Wraps the shared orkInitDataTable (standard toolbar + CSV) without scrollX, so
// tables built inside hidden tabs/sections need no column re-measure on reveal.
// Re-rendered tables call tnDestroyDataTable() before replacing their markup.
function tnInitDataTable(table, opts) {
	if (!table || !window.jQuery || !jQuery.fn.dataTable || !window.orkInitDataTable) return null;
	opts = opts || {};
	return window.orkInitDataTable(jQuery(table), {
		order: opts.order || [],
		columnDefs: opts.columnDefs || [],
		csvName: opts.csvName,
		dt: jQuery.extend({ scrollX: false }, opts.dt || {})
	});
}
function tnDestroyDataTable(table) {
	if (table && window.jQuery && jQuery.fn.dataTable && jQuery.fn.dataTable.isDataTable(table)) {
		jQuery(table).DataTable().destroy();
		// destroy() leaves DataTables' sort aria-label on each <th>, and the next
		// init reads it back as the column's ariaTitle, so the "activate to sort"
		// suffix would stack on every re-render. Strip that stale state.
		jQuery(table).find('thead th').each(function() {
			this.removeAttribute('aria-sort');
			if (/: activate to sort column/.test(this.getAttribute('aria-label') || '')) this.removeAttribute('aria-label');
		});
	}
}
// Team member list for a DataTables child row (tnToggleRoster). A <template>
// keeps it out of the cell's visible text and CSV export.
function tnTeamRosterSrc(members) {
	return '<template class="tn-team-roster-src">' + (members || []).map(function(m) {
		return '<span class="tn-roster-member">' + tnEsc(m.Persona || '')
			+ '<span class="tn-pill tn-pill-team-wl" data-tip="Warrior level" style="margin-left:3px">⚔' + (parseInt(m.WarriorLevel, 10) || 0) + '</span></span>';
	}).join('') + '</template>';
}

function tnToggleSeq(id) {
	var el   = document.getElementById(id);
	var icon = document.getElementById(id + '-icon');
	if (!el) return;
	var open = el.style.display === 'none';
	el.style.display = open ? '' : 'none';
	if (icon) icon.style.transform = open ? '' : 'rotate(-90deg)';
}

// Toggle roster sub-row for a team participant. Works in ul-li lists and table rows.
// Button HTML: &#9658; (▸ closed) / &#9660; (▾ open) + member count.
function tnToggleRoster(btn) {
	var parent = btn.closest('li') || btn.closest('tr');
	if (!parent) return;
	// DataTables rows keep the roster in a <template> and show it as a child row.
	var src = parent.tagName === 'TR' ? parent.querySelector('template.tn-team-roster-src') : null;
	var tbl = src ? parent.closest('table') : null;
	if (tbl && window.jQuery && jQuery.fn.dataTable && jQuery.fn.dataTable.isDataTable(tbl)) {
		var dtRow = jQuery(tbl).DataTable().row(parent);
		var n = btn.getAttribute('data-roster-count') || btn.textContent.trim().replace(/[^0-9]/g, '');
		btn.setAttribute('data-roster-count', n);
		var opening = !dtRow.child.isShown();
		if (opening) dtRow.child(src.innerHTML, 'tn-team-roster-row').show();
		else dtRow.child.hide();
		btn.innerHTML = (opening ? '&#9660;' : '&#9658;') + ' ' + n;
		return;
	}
	// No DataTable (e.g. CDN failed): materialize the roster as a hidden sibling row once.
	if (src && !(parent.nextElementSibling && parent.nextElementSibling.classList.contains('tn-team-roster-row'))) {
		var fb = document.createElement('tr');
		fb.className = 'tn-team-roster-row';
		fb.style.display = 'none';
		var fbTd = document.createElement('td');
		fbTd.colSpan = parent.cells.length;
		fbTd.innerHTML = src.innerHTML;
		fb.appendChild(fbTd);
		parent.parentNode.insertBefore(fb, parent.nextSibling);
	}
	var sub = parent.nextElementSibling;
	if (!sub || (!sub.classList.contains('tn-team-roster-sub') && !sub.classList.contains('tn-team-roster-row'))) return;
	var isHidden = sub.style.display === 'none';
	var count = btn.getAttribute('data-roster-count') || btn.textContent.trim().replace(/[^0-9]/g, '');
	if (!btn.getAttribute('data-roster-count')) btn.setAttribute('data-roster-count', count);
	if (sub.tagName === 'LI') {
		sub.style.display = isHidden ? 'flex' : 'none';
	} else {
		sub.style.display = isHidden ? '' : 'none';
	}
	btn.innerHTML = (isHidden ? '&#9660;' : '&#9658;') + ' ' + count;
}

function tnToggleBracket(bid) {
	var card = document.getElementById('tn-bracket-' + bid);
	if (!card) return;
	card.classList.toggle('tn-collapsed');
	var key = 'tnCollapsed_' + TnConfig.tournamentId;
	var state = JSON.parse(sessionStorage.getItem(key) || '{}');
	state[bid] = card.classList.contains('tn-collapsed');
	sessionStorage.setItem(key, JSON.stringify(state));
}

// ---- Focus mode (full-screen running view) ----
function tnSetFocus(on) {
	var root = document.getElementById('tn-root');
	if (!root) return;
	root.classList.toggle('tn-focus', on);
	var li = document.querySelector('.tn-focus-toggle i');
	if (li) li.className = on ? 'fas fa-compress' : 'fas fa-expand';
	// Focus mode is the full-screen running view — it must land on the bracket
	// panel, otherwise entering Focus from another tab hides everything but shows
	// the wrong (non-bracket) panel and no bracket at all.
	if (on && typeof tnActivateTab === 'function') tnActivateTab('bracketviz');
	if (on) sessionStorage.setItem('tnFocusMode', '1');
	else    sessionStorage.removeItem('tnFocusMode');
}
function tnToggleFocus() {
	var root = document.getElementById('tn-root');
	tnSetFocus(!(root && root.classList.contains('tn-focus')));
}

function tnDeleteBracket(bid, tid) {
	tnConfirm({
		title: 'Delete bracket?',
		body: 'If the bracket has match data associated to it, including a completed bracket, it will be completely wiped. <strong>This cannot be undone.</strong>',
		confirmLabel: 'Delete',
		danger: true,
		onConfirm: function() {
			var fd = new FormData();
			fd.append('BracketId', bid);
			var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
			if (window.tnRegisterAction) window.tnRegisterAction(actionId);
			if (window.tnCollabNudge) window.tnCollabNudge();
			fd.append('ActionId', actionId);
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/deletebracket', { method:'POST', body:fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					if (d && d.status === 0) {
						sessionStorage.setItem('tnOpenTab', 'brackets');
						window.location.reload();
					} else {
						window.tnToast((d && d.error) ? d.error : 'Failed to delete bracket.');
					}
				})
				.catch(function() { window.tnToast('Request failed. Please try again.'); });
		}
	});
}

// ---- Copy Bracket ----
function tnCopyBracket(bid, tid) {
	tnConfirm({
		title: 'Copy bracket?',
		body: 'This will duplicate the bracket settings and all participants into a new bracket.',
		confirmLabel: 'Copy',
		danger: false,
		onConfirm: function() {
			var fd = new FormData();
			fd.append('BracketId', bid);
			var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
			if (window.tnRegisterAction) window.tnRegisterAction(actionId);
			if (window.tnCollabNudge) window.tnCollabNudge();
			fd.append('ActionId', actionId);
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/copybracket', { method:'POST', body:fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					if (d && d.status === 0) {
						sessionStorage.setItem('tnOpenTab', 'brackets');
						window.location.reload();
					} else {
						window.tnToast((d && d.error) ? d.error : 'Failed to copy bracket.');
					}
				})
				.catch(function() { window.tnToast('Request failed. Please try again.'); });
		}
	});
}

// ---- Collapse/Expand All Brackets ----
function tnToggleAllBrackets() {
	var cards = document.querySelectorAll('.tn-bracket-card');
	if (!cards.length) return;
	// If any are expanded, collapse all; otherwise expand all
	var anyExpanded = false;
	cards.forEach(function(c) { if (!c.classList.contains('tn-collapsed')) anyExpanded = true; });
	var key = 'tnCollapsed_' + TnConfig.tournamentId;
	var state = {};
	cards.forEach(function(c) {
		var bid = c.id.replace('tn-bracket-', '');
		if (anyExpanded) {
			c.classList.add('tn-collapsed');
			state[bid] = true;
		} else {
			c.classList.remove('tn-collapsed');
			state[bid] = false;
		}
	});
	sessionStorage.setItem(key, JSON.stringify(state));
	// Update button label
	var btn = document.getElementById('tn-toggle-all-btn');
	if (btn) {
		var span = btn.querySelector('span');
		var icon = btn.querySelector('i');
		if (anyExpanded) {
			if (span) span.textContent = 'Expand All';
			if (icon) { icon.className = 'fas fa-expand-arrows-alt'; }
		} else {
			if (span) span.textContent = 'Collapse All';
			if (icon) { icon.className = 'fas fa-compress-arrows-alt'; }
		}
	}
}

function tnRemoveParticipant(btn) {
	tnConfirm({
		title: 'Remove participant?',
		body: 'Remove this participant from the bracket?',
		confirmLabel: 'Remove',
		danger: true,
		onConfirm: function() { _tnRemoveParticipantConfirmed(btn); }
	});
}
function _tnRemoveParticipantConfirmed(btn) {
	var pid = btn.dataset.pid;
	var bid = btn.dataset.bid;
	var tid = btn.dataset.tid;
	var fd = new FormData();
	fd.append('ParticipantId', pid);
	fd.append('TournamentId',  tid);
	fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/removeparticipant', {method:'POST', body:fd})
		.then(function(r){ return r.json(); })
		.then(function(r){
			if (r.status === 0) {
				var li = btn.closest('li');
				var ul = li.parentNode;
				li.remove();
				ul.querySelectorAll('li').forEach(function(item, i) {
					var seed = item.querySelector('.tn-participant-seed');
					if (seed) seed.textContent = i + 1;
				});
				var card = document.getElementById('tn-bracket-' + bid);
				if (card) {
					var remaining = ul.querySelectorAll('li').length;
					card.querySelectorAll('span').forEach(function(s) {
						if (/\d+ participant/.test(s.textContent)) s.textContent = remaining + ' participant' + (remaining !== 1 ? 's' : '');
					});
				}
			} else {
				window.tnToast('Error: ' + (r.error || 'Could not remove participant.'));
			}
		})
		.catch(function(){ window.tnToast('Network error removing participant.'); });
}
// Apply dialog semantics + control labelling to a server-rendered overlay's
// modal box (idempotent — safe to call on every open). Centralised here so all
// ~15 static modals get role=dialog / aria-modal / aria-labelledby and a
// labelled, typed close button without touching each markup block. (#104)
function _tnApplyDialogA11y(ov) {
	if (!ov) return;
	var box = ov.querySelector('.tn-modal-box');
	if (box) {
		if (!box.getAttribute('role')) box.setAttribute('role', 'dialog');
		if (!box.getAttribute('aria-modal')) box.setAttribute('aria-modal', 'true');
		if (!box.hasAttribute('tabindex')) box.setAttribute('tabindex', '-1');
		var title = box.querySelector('.tn-modal-title');
		if (title && !box.getAttribute('aria-labelledby')) {
			if (!title.id) title.id = (ov.id || 'tn-modal') + '-title';
			box.setAttribute('aria-labelledby', title.id);
		}
	}
	var closeBtn = ov.querySelector('.tn-modal-close');
	if (closeBtn) {
		closeBtn.setAttribute('type', 'button');
		if (!closeBtn.getAttribute('aria-label')) closeBtn.setAttribute('aria-label', 'Close');
	}
}
function tnOpenModal(id) {
	var ov = document.getElementById(id);
	if (!ov) return;
	_tnApplyDialogA11y(ov);
	ov._tnPrevFocus = document.activeElement;   // restore on close (#104)
	if (window.tnHideTooltip) window.tnHideTooltip();   // don't leave a bracket hover card beside the modal
	ov.classList.add('tn-open');
	var box = ov.querySelector('.tn-modal-box');
	if (box) setTimeout(function() { try { box.focus({ preventScroll: true }); } catch (e) { box.focus(); } }, 0);
}
function tnCloseModal(id) {
	var ov = document.getElementById(id);
	if (!ov) return;
	var _prevFocus = ov._tnPrevFocus;
	ov._tnPrevFocus = null;
	var _restore = function() { if (_prevFocus && typeof _prevFocus.focus === 'function') { try { _prevFocus.focus({ preventScroll: true }); } catch (e) { try { _prevFocus.focus(); } catch (e2) {} } } };
	// Sheet-managed overlays (opened via TnMobile.sheet.open) must close through
	// TnMobile.sheet.close so its teardown runs — otherwise the backdrop/keydown/
	// swipe listeners and visualViewport refcount leak. sheet.close handles the
	// desktop-opened case (_tnSheet.mobile === false) identically to the legacy
	// path below, plus fires onDismiss, so desktop behavior is preserved.
	if (ov._tnSheet && window.TnMobile && TnMobile.sheet) { TnMobile.sheet.close(ov); _restore(); return; }
	ov.classList.remove('tn-open');
	// Autocomplete dropdowns are appended to <body> (see tnFixedAcPosition), so
	// they aren't hidden by the modal closing — close any open ones explicitly.
	document.querySelectorAll('.kn-ac-results.kn-ac-open')
		.forEach(function(d) { d.classList.remove('kn-ac-open'); });
	_restore();
}
function tnShowFeedback(elId, msg, ok) {
	var el = document.getElementById(elId);
	if (!el) return;
	el.textContent = msg;
	el.className = 'tn-feedback ' + (ok ? 'tn-feedback-ok' : 'tn-feedback-err');
	el.style.display = 'block';
}
// 1 -> '1st', 2 -> '2nd', 11..13 -> '11th'..'13th', 22 -> '22nd' (mirrors PHP tnOrdinal).
function tnOrdinal(n) {
	n = parseInt(n, 10) || 0;
	var v = Math.abs(n) % 100;
	if (v >= 11 && v <= 13) return n + 'th';
	return n + (['th','st','nd','rd'][v % 10] || 'th');
}
// Bracket display label — style name plus ' (StyleNote)' when set; mirrors the PHP
// bracket card / Run pill format so same-style brackets stay distinguishable.
function tnBracketLabel(br) {
	if (!br) return '';
	var base = (TnConfig.styleLabels || {})[br.Style] || br.Style || '';
	var note = String(br.StyleNote || '').trim();
	return note ? base + ' (' + note + ')' : base;
}
function tnHideFeedback(elId) {
	var el = document.getElementById(elId);
	if (el) el.style.display = 'none';
}

// ============================================================
// tnConfirm — reusable in-product confirmation modal (replaces
// native confirm()). Reuses the existing .tn-overlay/.tn-modal-*
// chrome so dark mode + styling come for free.
//   tnConfirm({ title, body, confirmLabel, cancelLabel, danger, onConfirm })
//   body is an HTML string. onConfirm fires only on confirm.
//   alertOnly: true hides the Cancel button (informational, single OK).
// ============================================================
var _tnConfirmState = { onConfirm: null, keyHandler: null };
function _tnEnsureConfirmModal() {
	var ov = document.getElementById('tn-confirm-overlay');
	if (ov) return ov;
	ov = document.createElement('div');
	ov.className = 'tn-overlay';
	ov.id = 'tn-confirm-overlay';
	ov.innerHTML =
		'<div class="tn-modal-box" style="width:440px;max-width:92vw" role="dialog" aria-modal="true">' +
			'<div class="tn-modal-header">' +
				'<h3 class="tn-modal-title" id="tn-confirm-title">Confirm</h3>' +
				'<button type="button" class="tn-modal-close" id="tn-confirm-x" aria-label="Close">&times;</button>' +
			'</div>' +
			'<div class="tn-modal-body" id="tn-confirm-body"></div>' +
			'<div class="tn-modal-footer">' +
				'<button type="button" class="tn-btn tn-btn-outline" id="tn-confirm-cancel">Cancel</button>' +
				'<button type="button" class="tn-btn tn-btn-primary" id="tn-confirm-ok">Confirm</button>' +
			'</div>' +
		'</div>';
	document.body.appendChild(ov);
	// Static teardown wiring (these never call onConfirm).
	var cancel = function() { _tnCloseConfirm(); };
	ov.querySelector('#tn-confirm-x').addEventListener('click', cancel);
	ov.querySelector('#tn-confirm-cancel').addEventListener('click', cancel);
	ov.addEventListener('click', function(e) { if (e.target === ov) cancel(); });
	return ov;
}
function _tnCloseConfirm() {
	var ov = document.getElementById('tn-confirm-overlay');
	var prevFocus = ov ? ov._tnPrevFocus : null;
	if (ov) { ov.classList.remove('tn-open'); ov._tnPrevFocus = null; }
	if (_tnConfirmState.keyHandler) {
		document.removeEventListener('keydown', _tnConfirmState.keyHandler, true);
		_tnConfirmState.keyHandler = null;
	}
	_tnConfirmState.onConfirm = null;
	// Return focus to the control that opened the confirm (stored by tnOpenModal).
	if (prevFocus && typeof prevFocus.focus === 'function') { try { prevFocus.focus({ preventScroll: true }); } catch (e) {} }
}
function tnConfirm(opts) {
	opts = opts || {};
	var ov = _tnEnsureConfirmModal();
	ov.querySelector('#tn-confirm-title').textContent = opts.title || 'Confirm';
	ov.querySelector('#tn-confirm-body').innerHTML = opts.body || '';
	var cancelBtn = ov.querySelector('#tn-confirm-cancel');
	cancelBtn.textContent = opts.cancelLabel || 'Cancel';
	cancelBtn.style.display = opts.alertOnly ? 'none' : '';   // reset on every open
	var okBtn = ov.querySelector('#tn-confirm-ok');
	okBtn.textContent = opts.confirmLabel || 'Confirm';
	okBtn.className = 'tn-btn ' + (opts.danger ? 'tn-btn-danger' : 'tn-btn-primary');
	// Fresh confirm binding (replace node to drop any prior handler so listeners
	// never stack across opens).
	var freshOk = okBtn.cloneNode(true);
	okBtn.parentNode.replaceChild(freshOk, okBtn);
	_tnConfirmState.onConfirm = (typeof opts.onConfirm === 'function') ? opts.onConfirm : null;
	freshOk.addEventListener('click', function() {
		var cb = _tnConfirmState.onConfirm;
		_tnCloseConfirm();
		if (cb) cb();
	});
	// Esc cancels, Enter confirms — bound per-open, removed on close.
	_tnConfirmState.keyHandler = function(e) {
		if (e.key === 'Escape') { e.preventDefault(); _tnCloseConfirm(); }
		else if (e.key === 'Enter') { e.preventDefault(); freshOk.click(); }
	};
	document.addEventListener('keydown', _tnConfirmState.keyHandler, true);
	tnOpenModal('tn-confirm-overlay');
	setTimeout(function() { freshOk.focus(); }, 0);
}

// ============================================================
// Delegated modal keyboard handling for every .tn-overlay:
//   Escape closes the topmost open overlay; Tab is trapped inside it.
// Bound on window (bubble) so it runs after any document-level handler; a
// handler that already dealt with the key (tnConfirm, mobile sheet, Bout List,
// inline editors) calls preventDefault and is left alone here.
// ============================================================
function _tnTopOverlay() {
	var open = document.querySelectorAll('.tn-overlay.tn-open');
	var top = null, topZ = -Infinity;
	for (var i = 0; i < open.length; i++) {
		var z = parseInt(window.getComputedStyle(open[i]).zIndex, 10) || 0;
		if (z >= topZ) { topZ = z; top = open[i]; }   // ties -> later in DOM wins
	}
	return top;
}
window.addEventListener('keydown', function(e) {
	if (e.defaultPrevented || (e.key !== 'Escape' && e.key !== 'Tab')) return;
	var ov = _tnTopOverlay();
	if (!ov) return;
	if (e.key === 'Escape') {
		e.preventDefault();
		if (ov.id === 'tn-confirm-overlay') _tnCloseConfirm();
		else if (ov.id) tnCloseModal(ov.id);
		else if (ov._tnSheet && window.TnMobile && TnMobile.sheet) TnMobile.sheet.close(ov);
		else ov.classList.remove('tn-open');
		return;
	}
	var box = ov.querySelector('.tn-modal-box') || ov;
	var nodes = Array.prototype.filter.call(
		box.querySelectorAll('a[href],area[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),button:not([disabled]),[tabindex]:not([tabindex="-1"])'),
		function(n) { return n.getClientRects().length > 0 && window.getComputedStyle(n).visibility !== 'hidden'; }
	);
	if (!nodes.length) { e.preventDefault(); return; }
	var first = nodes[0], last = nodes[nodes.length - 1], act = document.activeElement;
	if (!box.contains(act) || act === box) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
	else if (e.shiftKey && act === first) { e.preventDefault(); last.focus(); }
	else if (!e.shiftKey && act === last) { e.preventDefault(); first.focus(); }
});

// ============================================================
// Standings: leaderboard computation + configure points modal
// ============================================================
function tnGetPlacePts(rank) {
	var sp = TnConfig.standingsPoints || [5,4,3,2,1,0,0,0];
	var idx = parseInt(rank) - 1;
	return (idx >= 0 && idx < sp.length) ? (sp[idx] || 0) : 0;
}

function tnBracketCountsForPoints(bid) {
	var bd = (TnConfig.bracketData || {})[bid];
	var st = (bd && bd.Bracket && bd.Bracket.Status) || '';
	return st === 'complete' || st === 'finalized';
}

function tnComputeLeaderboard() {
	var sd   = TnConfig.standingsData || {};
	var bmap = TnConfig.bracketData   || {};
	var entries = {}; // key -> {Alias, MundaneId, Persona, ParkName, Points, BracketCount}
	for (var bid in sd) {
		if (!sd.hasOwnProperty(bid)) continue;
		var rows = sd[bid];
		// Place points count only once a bracket's placements are settled; live
		// per-bracket tables still show in-progress ranks.
		if (!tnBracketCountsForPoints(bid)) continue;
		var bLabel = '';
		if (bmap[bid] && bmap[bid].Bracket) {
			var br = bmap[bid].Bracket;
			var sl = tnBracketLabel(br);
			var ml = (TnConfig.methodLabels || {})[br.Method] || br.Method || '';
			bLabel = sl + (ml ? ' — ' + ml : '');
		}
		(rows || []).forEach(function(row) {
			var pts = tnGetPlacePts(row.Rank);
			var mid = parseInt(row.MundaneId) || 0;
			var key = mid > 0 ? 'mid:' + mid : 'alias:' + (row.Alias || '').toLowerCase().trim();
			if (!entries[key]) {
				entries[key] = { Alias: row.Alias || '', MundaneId: mid,
					Persona: row.Persona || '', ParkName: row.ParkName || '',
					Points: 0, BracketCount: 0, BracketLabels: [] };
			}
			entries[key].Points       += pts;
			entries[key].BracketCount++;
			entries[key].BracketLabels.push(bLabel || ('Bracket ' + bid));
		});
	}
	var list = Object.values(entries);
	list.sort(function(a, b) { return b.Points - a.Points || a.Alias.localeCompare(b.Alias); });
	var rank = 1;
	list.forEach(function(e, i) {
		if (i > 0 && list[i-1].Points !== e.Points) rank = i + 1;
		e.Rank = rank;
	});
	return list;
}

function tnRenderLeaderboard() {
	var tbody   = document.getElementById('tn-leaderboard-body');
	var summary = document.getElementById('tn-ldb-pts-summary');
	if (!tbody) return;
	var sp = TnConfig.standingsPoints || [5,4,3,2,1,0,0,0];
	if (summary) {
		var labels = ['1st','2nd','3rd','4th','5th','6th','7th','8th'];
		summary.textContent = labels.map(function(l,i){ return l+'='+sp[i]; }).filter(function(s,i){ return sp[i] > 0; }).join(', ');
	}
	var entries = tnComputeLeaderboard();
	// Tear down any previous DataTable first: destroy() puts every row back in
	// the tbody, so the rebuild below replaces all of them, not just one page.
	var table = document.getElementById('tn-leaderboard-table');
	tnDestroyDataTable(table);
	if (!entries.length) {
		var emptyMsg = Object.keys(TnConfig.standingsData || {}).length
			? 'Points are awarded when a bracket completes.'
			: 'No standings data yet.';
		tbody.innerHTML = '<tr><td colspan="' + (TnConfig.canRecommend ? 6 : 5) + '" style="text-align:center;color:#a0aec0;padding:20px">' + emptyMsg + '</td></tr>';
		return;
	}
	var rows = '';
	var prevRank = null;
	entries.forEach(function(e) {
		var rankCell = (e.Rank !== prevRank)
			? '<td class="tn-lb-rank">' + e.Rank + '</td>'
			: '<td class="tn-lb-rank-tied">' + e.Rank + '</td>';
		prevRank = e.Rank;
		var nameCell = e.MundaneId > 0
			? '<a href="' + TnConfig.uir + 'Player/profile/' + e.MundaneId + '" class="tn-lb-link" style="font-weight:600">' + tnEsc(e.Alias) + '</a>'
			: '<span style="font-weight:600">' + tnEsc(e.Alias) + '</span>';
		var recCell = '';
		if (TnConfig.canRecommend) {
			if (e.MundaneId > 0) {
				var pa = tnEsc(e.Alias || '');
				recCell = '<td style="text-align:center"><span class="tn-rec-actions">'
					+ '<button type="button" class="tn-rec-btn tn-rec-trigger" data-mundane-id="' + e.MundaneId + '" data-persona="' + pa + '" data-award="27"><i class="fas fa-star"></i> Warrior</button>'
					+ '<button type="button" class="tn-rec-btn tn-rec-trigger" data-mundane-id="' + e.MundaneId + '" data-persona="' + pa + '" data-award="33"><i class="fas fa-star"></i> Griffin</button>'
					+ '</span></td>';
			} else {
				recCell = '<td style="text-align:center;color:#cbd5e0;font-size:11px">—</td>';
			}
		}
		rows += '<tr>'
			+ rankCell
			+ '<td>' + nameCell + '</td>'
			+ '<td class="tn-lb-muted">' + tnEsc(e.ParkName || '—') + '</td>'
			+ '<td class="tn-lb-muted" style="text-align:center;font-size:12px">' + tnEsc(String(e.BracketCount)) + '</td>'
			+ '<td class="tn-lb-points" style="text-align:right;font-size:15px">' + tnEsc(String(e.Points)) + '</td>'
			+ recCell
			+ '</tr>';
	});
	tbody.innerHTML = rows;
	tnInitDataTable(table, {
		csvName: 'Tournament Leaderboard',
		columnDefs: TnConfig.canRecommend ? [{ targets: -1, orderable: false, searchable: false }] : []
	});
}

// Per-bracket standings pills + tables, rendered from TnConfig.standingsData so a
// refresh (tnRefreshStandings) can rebuild them without a page reload. Mirrors the
// former PHP-rendered markup (pid shield, warrior pills, team rosters, tie spacers).
function tnRenderStandingsTables() {
	var pillsWrap    = document.getElementById('tn-standings-pills');
	var sectionsWrap = document.getElementById('tn-standings-sections');
	if (!pillsWrap || !sectionsWrap) return;
	var sd    = TnConfig.standingsData || {};
	var bmap  = TnConfig.bracketData   || {};
	var canManage    = !!TnConfig.canManage;
	var canRecommend = !!TnConfig.canRecommend;
	var flag = function(v) { return !!v && v !== '0'; };

	// Remember the active pill so a re-render keeps the user's selection.
	var activeBtn = pillsWrap.querySelector('.tn-bk-pill-active');
	var activeBid = activeBtn ? String(activeBtn.dataset.bid) : 'leaderboard';

	pillsWrap.querySelectorAll('.tn-bk-pill').forEach(function(p) {
		if (p.dataset.bid !== 'leaderboard') p.remove();
	});

	var pillsHtml = '', sectionsHtml = '';
	Object.keys(sd).forEach(function(bid) {
		var rows = sd[bid] || [];
		var br = (bmap[bid] && bmap[bid].Bracket) ? bmap[bid].Bracket : null;
		var sl = br ? tnBracketLabel(br) : ('Bracket ' + bid);
		var ml = br ? ((TnConfig.methodLabels || {})[br.Method] || br.Method || '') : '';
		var isIronman = !!(br && br.Method === 'ironman');
		pillsHtml += '<button class="tn-bk-pill" data-bid="' + bid + '" onclick="tnStandingsPillClick(this,' + bid + ')">'
			+ tnEsc(sl) + (ml ? ' &mdash; ' + tnEsc(ml) : '') + '</button>';

		var html = '<div class="tn-standings-section" data-stbid="' + bid + '" style="display:none">';
		if (canManage && isIronman && rows.length) {
			html += '<div style="margin-bottom:12px">'
				+ '<button class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnOpenPoolsToBracketsModal(' + parseInt(bid, 10) + ', ' + rows.length + ')">'
				+ '<i class="fas fa-sitemap"></i> Pools to Brackets</button></div>';
		}
		if (!rows.length) {
			html += '<div class="tn-empty">No standings yet.</div></div>';
			sectionsHtml += html;
			return;
		}
		var tid = 'tn-standings-table-' + bid;
		html += '<table class="tn-table tn-standings-table" id="' + tid + '"><thead><tr>'
			+ '<th>Rank</th>'
			+ '<th>Participant</th>'
			+ '<th>Park</th>'
			+ '<th style="text-align:center">Wins</th>';
		if (isIronman) {
			html += '<th style="text-align:center">Max Streak</th>'
				+ '<th style="text-align:center">Cur Streak</th>'
				+ '<th style="text-align:right">Place Pts</th>';
		} else {
			html += '<th style="text-align:center">L</th>'
				+ '<th style="text-align:center">T</th>'
				+ '<th style="text-align:center">Byes</th>'
				+ '<th style="text-align:center">Pts</th>'
				+ '<th style="text-align:right">Place Pts</th>';
		}
		if (canRecommend) html += '<th class="no-export" style="text-align:center">Recommend for&hellip;</th>';
		html += '</tr></thead><tbody>';

		var prevRank = null, tieCount = 0;
		rows.forEach(function(r) {
			var rank = parseInt(r.Rank, 10) || 0;
			// First row after a tie group gets a gap (shown only in rank order).
			var tieGap = (prevRank !== null && rank !== prevRank && tieCount > 1);
			if (prevRank === null || rank !== prevRank) tieCount = 0;
			tieCount++;
			prevRank = rank;

			var isTeam  = flag(r.IsTeam);
			var members = r.Members || [];
			var pnum    = parseInt(r.ParticipantNumber, 10) || 0;

			var partCell = '';
			if (pnum > 0) partCell += '<span class="tn-pid" data-tip="Player #' + pnum + ' — same number across every bracket">' + pnum + '</span>';
			partCell += tnEsc(r.Alias || '—');
			if (isTeam) {
				partCell += '<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle"><span class="tn-pill tn-pill-team-wl" data-tip="Team warrior level">⚔ ' + (parseInt(r.TeamWarriorLevel, 10) || 0) + '</span></span>';
				if (members.length) partCell += '<button class="tn-team-roster-btn" onclick="tnToggleRoster(this)" data-tip="Show/hide team roster">&#9658; ' + members.length + '</button>'
					+ tnTeamRosterSrc(members);
			} else {
				// Warrior/award pills (mirror PHP tnParticipantPills)
				var pills = '';
				var wc = parseInt(r.WarriorCount, 10) || 0;
				if (wc > 0) pills += '<span class="tn-pill tn-pill-warrior" data-tip="Order of the Warrior x' + wc + '">' + Math.min(wc, 10) + '</span>';
				if (flag(r.IsWarlord))     pills += '<span class="tn-pill tn-pill-warlord" data-tip="Warlord">W</span>';
				if (flag(r.IsKnightSword)) pills += '<span class="tn-pill tn-pill-knight" data-tip="Knight of the Sword">K</span>';
				if (pills) partCell += '<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle">' + pills + '</span>';
			}

			html += '<tr' + (tieGap ? ' class="tn-tie-gap"' : '') + '>'
				+ '<td style="color:#a0aec0;font-weight:700">' + rank + '</td>'
				+ '<td style="font-weight:600">' + partCell + '</td>'
				+ '<td style="color:#718096">' + (isTeam ? '—' : (tnEsc(r.ParkName || '') || '—')) + '</td>'
				+ '<td style="text-align:center;color:#276749;font-weight:700">' + (parseInt(r.Wins, 10) || 0) + '</td>';
			if (isIronman) {
				html += '<td style="text-align:center;font-weight:700;color:#d69e2e">' + (parseInt(r.MaxStreak, 10) || 0) + '</td>'
					+ '<td style="text-align:center;color:#276749">' + (parseInt(r.CurrentStreak, 10) || 0) + '</td>';
			} else {
				html += '<td style="text-align:center;color:#e53e3e">' + (parseInt(r.Losses, 10) || 0) + '</td>'
					+ '<td style="text-align:center;color:#718096">' + (parseInt(r.Ties, 10) || 0) + '</td>'
					+ '<td style="text-align:center;color:#a0aec0">' + (parseInt(r.Byes, 10) || 0) + '</td>'
					+ '<td style="text-align:center;font-weight:800;color:#1a202c">' + (parseInt(r.Points, 10) || 0) + '</td>';
			}
			html += '<td style="text-align:right;font-weight:800;color:#276749" data-place-rank="' + rank + '">' + tnGetPlacePts(rank) + '</td>';
			if (canRecommend) {
				var mid = parseInt(r.MundaneId, 10) || 0;
				if (mid > 0) {
					var pa = tnEsc(r.Alias || '');
					html += '<td style="text-align:center"><span class="tn-rec-actions">'
						+ '<button type="button" class="tn-rec-btn tn-rec-trigger" data-mundane-id="' + mid + '" data-persona="' + pa + '" data-award="27"><i class="fas fa-star"></i> Warrior</button>'
						+ '<button type="button" class="tn-rec-btn tn-rec-trigger" data-mundane-id="' + mid + '" data-persona="' + pa + '" data-award="33"><i class="fas fa-star"></i> Griffin</button>'
						+ '</span></td>';
				} else {
					html += '<td style="text-align:center"><span style="color:#cbd5e0;font-size:11px">&mdash;</span></td>';
				}
			}
			html += '</tr>';
		});
		html += '</tbody></table></div>';
		sectionsHtml += html;
	});

	pillsWrap.insertAdjacentHTML('beforeend', pillsHtml);
	sectionsWrap.querySelectorAll('table.tn-standings-table').forEach(tnDestroyDataTable);
	sectionsWrap.innerHTML = sectionsHtml;
	sectionsWrap.querySelectorAll('table.tn-standings-table').forEach(function(t) {
		tnInitDataTable(t, {
			csvName: 'Bracket Standings',
			columnDefs: canRecommend ? [{ targets: -1, orderable: false, searchable: false }] : [],
			dt: {
				// Tie-group gaps only make sense in the default rank order.
				drawCallback: function() {
					var o = this.api().order();
					t.classList.toggle('tn-rank-ordered', !o.length || (o[0][0] === 0 && o[0][1] === 'asc'));
				}
			}
		});
	});

	// Restore the previous selection; fall back to the leaderboard if it vanished.
	if (activeBid !== 'leaderboard' && !(activeBid in sd)) activeBid = 'leaderboard';
	pillsWrap.querySelectorAll('.tn-bk-pill').forEach(function(b) {
		b.classList.toggle('tn-bk-pill-active', String(b.dataset.bid) === activeBid);
	});
	tnShowStandings(activeBid);
}

// Refresh button: refetch standings + points from the server and re-render the
// leaderboard and per-bracket tables in place (no page reload).
function tnRefreshStandings(btn, quiet) {
	var icon = btn ? btn.querySelector('i') : null;
	if (btn) btn.disabled = true;
	if (icon) icon.classList.add('fa-spin');
	var done = function() {
		if (btn) btn.disabled = false;
		if (icon) icon.classList.remove('fa-spin');
	};
	// Resolves true on success, false on failure (the Standings-tab wrapper re-marks stale).
	return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/standings')
		.then(function(r) { return r.json(); })
		.then(function(d) {
			done();
			if (!d || d.status !== 0) {
				window.tnToast('Error: ' + ((d && d.error) || 'Could not refresh standings'));
				return false;
			}
			TnConfig.standingsData = d.standings || {};
			if (Array.isArray(d.points) && d.points.length) TnConfig.standingsPoints = d.points;
			// Patch bracket meta so pills label correctly even for brackets created after page load.
			if (!TnConfig.bracketData) TnConfig.bracketData = {};
			(d.brackets || []).forEach(function(b) {
				var bid = parseInt(b.BracketId, 10) || 0;
				if (bid <= 0) return;
				if (!TnConfig.bracketData[bid]) TnConfig.bracketData[bid] = {};
				if (!TnConfig.bracketData[bid].Bracket) TnConfig.bracketData[bid].Bracket = { BracketId: bid };
				TnConfig.bracketData[bid].Bracket.Style  = b.Style;
				TnConfig.bracketData[bid].Bracket.Method = b.Method;
				TnConfig.bracketData[bid].Bracket.StyleNote = b.StyleNote || '';
				if (b.Status) TnConfig.bracketData[bid].Bracket.Status = b.Status;
			});
			tnRenderLeaderboard();
			tnRenderStandingsTables();
			if (!quiet) window.tnToast('Standings refreshed');
			return true;
		})
		.catch(function(err) { done(); window.tnToast('Request failed: ' + err); return false; });
}

document.addEventListener('DOMContentLoaded', function() {
	tnRenderLeaderboard();
	tnRenderStandingsTables();
});


// ---- Configure Standings Modal ----
(function() {
	if (!TnConfig.canManage) return;
	var OVERLAY = 'tn-configstandings-overlay';

	window.tnOpenConfigStandingsModal = function() {
		tnHideFeedback('tn-cs-feedback');
		// Pre-fill inputs from current TnConfig values
		document.querySelectorAll('.tn-cs-pts-input').forEach(function(inp) {
			var idx = parseInt(inp.dataset.idx);
			inp.value = (TnConfig.standingsPoints && TnConfig.standingsPoints[idx] !== undefined)
				? TnConfig.standingsPoints[idx] : 0;
		});
		tnOpenAsSheet(OVERLAY, {});
	};

	['tn-cs-close','tn-cs-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	var submitBtn = document.getElementById('tn-cs-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn = this;
			var pts = [];
			document.querySelectorAll('.tn-cs-pts-input').forEach(function(inp) {
				pts.push(Math.max(0, Math.round((parseFloat(inp.value) || 0) * 2) / 2));
			});
			if (pts.length !== 8) { tnShowFeedback('tn-cs-feedback','Invalid input.',false); return; }
			btn.disabled = true;
			var fd = new FormData();
			fd.append('Points', JSON.stringify(pts));
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/savestandingspoints', {method:'POST',body:fd})
				.then(function(r) { return r.json(); })
				.then(function(d) {
					btn.disabled = false;
					if (d && d.status === 0) {
						TnConfig.standingsPoints = d.points || pts;
						tnRenderLeaderboard();
						// Re-render Place Pts columns on visible bracket standings
						tnUpdatePlacePtsCols();
						tnShowFeedback('tn-cs-feedback','Saved!',true);
						setTimeout(function() { tnCloseModal(OVERLAY); }, 600);
					} else {
						tnShowFeedback('tn-cs-feedback',(d&&d.error)?d.error:'Failed to save.',false);
					}
				})
				.catch(function() { btn.disabled=false; tnShowFeedback('tn-cs-feedback','Request failed.',false); });
		});
	}
})();

(function() {
	if (!TnConfig.canManage) return;
	var OVERLAY = 'tn-poolstobrackets-overlay';

	window.tnOpenPoolsToBracketsModal = function(srcBid, poolSize) {
		tnHideFeedback('tn-p2b-feedback');
		document.getElementById('tn-p2b-source-bid').value = srcBid;
		var ps = Math.max(2, parseInt(poolSize) || 2);
		var topx = document.getElementById('tn-p2b-topx');
		topx.max = ps;
		topx.value = Math.min(8, ps);
		var note = document.getElementById('tn-p2b-pool-note');
		if (note) note.textContent = '(of ' + ps + ' in pool)';
		document.getElementById('tn-p2b-method').value = 'single';
		document.getElementById('tn-p2b-seed').value = 'standing';
		tnOpenAsSheet(OVERLAY, {});
	};

	['tn-p2b-close','tn-p2b-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	var submitBtn = document.getElementById('tn-p2b-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn = this;
			var srcBid = parseInt(document.getElementById('tn-p2b-source-bid').value) || 0;
			var method = document.getElementById('tn-p2b-method').value;
			var topx   = parseInt(document.getElementById('tn-p2b-topx').value) || 0;
			var seed   = document.getElementById('tn-p2b-seed').value;
			if (!srcBid)  { tnShowFeedback('tn-p2b-feedback','Missing source bracket.',false); return; }
			if (topx < 2) { tnShowFeedback('tn-p2b-feedback','Take at least 2 players.',false); return; }
			if (method === 'double' && topx < 3) { tnShowFeedback('tn-p2b-feedback','Double elimination needs at least 3 players.',false); return; }
			btn.disabled = true;
			tnShowFeedback('tn-p2b-feedback','Creating bracket\u2026',true);
			var fd = new FormData();
			fd.append('TournamentId', TnConfig.tournamentId);
			fd.append('Method', method);
			fd.append('TopX', topx);
			fd.append('SeedMethod', seed);
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + srcBid + '/poolstobrackets', {method:'POST',body:fd})
				.then(function(r) { return r.json(); })
				.then(function(d) {
					if (d && d.status === 0) {
						tnShowFeedback('tn-p2b-feedback','Bracket created!',true);
						sessionStorage.setItem('tnOpenTab','brackets');
						setTimeout(function() { window.location.reload(); }, 500);
					} else {
						btn.disabled = false;
						tnShowFeedback('tn-p2b-feedback',(d&&d.error)?d.error:'Failed to create bracket.',false);
					}
				})
				.catch(function() { btn.disabled=false; tnShowFeedback('tn-p2b-feedback','Request failed.',false); });
		});
	}
})();

function tnUpdatePlacePtsCols() {
	// Rebuild the bracket standings tables: they are DataTables, so off-page rows
	// aren't in the DOM and cached sort data would go stale if patched in place.
	tnRenderStandingsTables();
}

// Shared opener (de-duped): on mobile present the overlay as a bottom sheet
// via the foundation; otherwise fall through to the legacy centered modal.
// Reachable from every modal opener (defined on window, outside the
// canManage PHP guard so the separate bulk-add <script> can use it too).
window.tnOpenAsSheet = function(overlayId, opts) {
	var el = document.getElementById(overlayId);
	if (window.TnMobile && TnMobile.sheet && TnMobile.viewMode && TnMobile.viewMode.isMobile && TnMobile.viewMode.isMobile()) {
		if (el) { _tnApplyDialogA11y(el); el._tnPrevFocus = document.activeElement; }   // sheet path bypasses tnOpenModal (#104)
		TnMobile.sheet.open(el, opts || {});
	} else {
		tnOpenModal(overlayId);
	}
};

<?php if ($canManage): ?>
// ---- Add Bracket Modal ----
(function() {
	var OVERLAY = 'tn-addbracket-overlay';
	var ADD_URL = TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/addbracket';

	window.tnOpenAddBracketModal = function() {
		tnHideFeedback('tn-addbracket-feedback');
		var d = document.getElementById('tn-addbracket-duration'); if (d) d.value = 0;
		var mSel = document.getElementById('tn-addbracket-method');
		var dFld = document.getElementById('tn-addbracket-duration-field');
		if (dFld && mSel) dFld.style.display = (mSel.value === 'ironman') ? '' : 'none';
		// Mobile: present as a bottom sheet (swipe-down dismiss, sticky footer,
		// leak teardown via the foundation); desktop falls through to the legacy
		// centered overlay so behavior is byte-identical. The B1 wizard layers
		// its step UI on top after this returns (see the wizard module).
		tnOpenAsSheet(OVERLAY, {});
	};

	(function() {
		var mSel = document.getElementById('tn-addbracket-method');
		var dFld = document.getElementById('tn-addbracket-duration-field');
		function tnGateAddTeam(isIronman) {
			var seg = document.getElementById('tn-addbracket-participants'); if (!seg) return;
			var teamBtn = seg.querySelector('.tn-seg-btn[data-val="team"]'); if (!teamBtn) return;
			teamBtn.disabled = isIronman;
			teamBtn.style.opacity = isIronman ? '0.4' : '';
			teamBtn.style.cursor  = isIronman ? 'not-allowed' : '';
			if (isIronman && tnSegGet('tn-addbracket-participants') === 'team') tnSegSet('tn-addbracket-participants', 'individual');
		}
		function tnToggleAddDuration() {
			var isIronman = !!(mSel && mSel.value === 'ironman');
			if (dFld) dFld.style.display = isIronman ? '' : 'none';
			tnGateAddTeam(isIronman);
		}
		if (mSel) mSel.addEventListener('change', tnToggleAddDuration);
		tnToggleAddDuration();
	})();
	['tn-addbracket-close','tn-addbracket-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});

	var ov = document.getElementById(OVERLAY);
	if (ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });
	}

	var submitBtn = document.getElementById('tn-addbracket-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn   = this;
			var style = document.getElementById('tn-addbracket-style').value;
			var method = document.getElementById('tn-addbracket-method').value;
			if (!style || !method) { tnShowFeedback('tn-addbracket-feedback', 'Style and format are required.', false); return; }
			if (method === 'points' && window.tnAddBracketScaleError) {
				var _scaleErr = window.tnAddBracketScaleError();
				if (_scaleErr) { tnShowFeedback('tn-addbracket-feedback', _scaleErr, false); return; }
			}

			tnHideFeedback('tn-addbracket-feedback');   // clear a stale error before resubmitting
			btn.disabled = true;
			var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
			if (window.tnRegisterAction) window.tnRegisterAction(actionId);
			if (window.tnCollabNudge) window.tnCollabNudge();
			var fd = new FormData();
			fd.append('Style',        style);
			fd.append('Method',       method);
			fd.append('Participants', tnSegGet('tn-addbracket-participants') || 'individual');
			fd.append('Rings',        document.getElementById('tn-addbracket-rings').value);
			fd.append('Seeding',      document.getElementById('tn-addbracket-seeding').value);
			fd.append('StyleNote',    document.getElementById('tn-addbracket-stylenote').value);
			fd.append('DurationMinutes', document.getElementById('tn-addbracket-duration').value || 0);
			fd.append('BestOf',       document.getElementById('tn-addbracket-bestof').value || 1);
			fd.append('ActionId',     actionId);
				if (window.tnAddBracketIsPoints && window.tnAddBracketIsPoints()) {
					window.tnAddBracketAppendPointsFields(fd);
				}

			fetch(ADD_URL, { method:'POST', body:fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					btn.disabled = false;
					if (d && d.status === 0) {
						tnShowFeedback('tn-addbracket-feedback', 'Bracket added!', true);
						var _newBid = (d && d.bracketId) ? d.bracketId : 0;
						setTimeout(function() { tnCloseModal(OVERLAY); sessionStorage.setItem('tnOpenTab','brackets'); if (_newBid) sessionStorage.setItem('tnScrollBracket', _newBid); window.location.reload(); }, 800);
					} else {
						tnShowFeedback('tn-addbracket-feedback', (d && d.error) ? d.error : 'Failed to add bracket.', false);
					}
				})
				.catch(function() { btn.disabled = false; tnShowFeedback('tn-addbracket-feedback', 'Request failed. Please try again.', false); });
		});
	}
// ---- Points Bracket Config (visibility + pip preview + form hooks) ----
(function(){
	function tnPtsById(id) { return document.getElementById(id); }
	function tnPtsModeRadio() {
		var r = document.querySelector('input[name="tn-addbracket-point-mode"]:checked');
		return r ? r.value : 'fixed';
	}
	function tnSyncPointsVisibility() {
		var sel = tnPtsById('tn-addbracket-method');
		if (!sel) return;
		var isPoints = (sel.value === 'points');
		var cfg   = tnPtsById('tn-addbracket-points-config');
		var scale = tnPtsById('tn-addbracket-point-scale-row');
		if (cfg)   cfg.style.display   = isPoints ? '' : 'none';
		if (scale) scale.style.display = (isPoints && tnPtsModeRadio() === 'fixed') ? '' : 'none';
		// Swiss reuses the "rings" field as the ROUND count — relabel to match.
		var rlab = document.querySelector('label[for="tn-addbracket-rings"]');
		if (rlab) rlab.textContent = (sel.value === 'swiss') ? 'Swiss rounds' : 'Rings (concurrent)';
	}
	function tnRenderScalePreview() {
		var prev = tnPtsById('tn-addbracket-point-scale-preview');
		var err  = tnPtsById('tn-addbracket-point-scale-err');
		var inp  = tnPtsById('tn-addbracket-point-scale');
		if (!prev || !inp) return true;
		prev.innerHTML = '';
		if (err) err.style.display = 'none';
		var raw = (inp.value || '').trim();
		if (!raw) return true;
		var parts = raw.split(',').map(function(s){ return s.trim(); });
		var seen = {};
		for (var i = 0; i < parts.length; i++) {
			var v = parts[i];
			if (!/^\d+(\.\d{1,2})?$/.test(v) || +v < 0 || +v > 999.99) {
				if (err) { err.textContent = 'Invalid value: "' + v + '"'; err.style.display = ''; }
				return false;
			}
			var k = (+v).toFixed(2);
			if (seen[k]) {
				if (err) { err.textContent = 'Duplicate value: "' + v + '"'; err.style.display = ''; }
				return false;
			}
			seen[k] = true;
		}
		if (parts.length < 1 || parts.length > 16) {
			if (err) { err.textContent = 'Must have 1-16 values'; err.style.display = ''; }
			return false;
		}
		parts.forEach(function(v){
			var s = document.createElement('span');
			s.className = 'tn-pip tn-pip-preview';
			s.textContent = v;
			prev.appendChild(s);
		});
		return true;
	}

	// Bind via DOMContentLoaded — the modal HTML is present at page load, just hidden.
	// (getElementById is safe inside event handlers, not as IIFE-load guards.)
	document.addEventListener('DOMContentLoaded', function(){
		var sel = tnPtsById('tn-addbracket-method');
		if (!sel) return;
		sel.addEventListener('change', tnSyncPointsVisibility);
		document.querySelectorAll('input[name="tn-addbracket-point-mode"]').forEach(function(r){
			r.addEventListener('change', tnSyncPointsVisibility);
		});
		var scale = tnPtsById('tn-addbracket-point-scale');
		// A now-valid scale also clears the stale submit error in the modal feedback.
		if (scale) scale.addEventListener('input', function(){ if (tnRenderScalePreview()) tnHideFeedback('tn-addbracket-feedback'); });
		tnSyncPointsVisibility();
		tnRenderScalePreview();
	});

	// Expose for form-submit augmentation (used inside the addbracket submit listener)
	window.tnAddBracketIsPoints = function(){
		var sel = tnPtsById('tn-addbracket-method');
		return sel && sel.value === 'points';
	};
	// Returns '' when the point scale is valid (or not in play), else the error text.
	window.tnAddBracketScaleError = function(){
		if (tnPtsModeRadio() !== 'fixed' || tnRenderScalePreview()) return '';
		var err = tnPtsById('tn-addbracket-point-scale-err');
		return (err && err.textContent) || 'Invalid point scale.';
	};
	window.tnAddBracketAppendPointsFields = function(fd){
		fd.append('PointRounds', (tnPtsById('tn-addbracket-point-rounds') || {}).value || '0');
		var mode = (document.querySelector('input[name="tn-addbracket-point-mode"]:checked') || {}).value || 'fixed';
		fd.append('PointMode', mode);
		if (mode === 'fixed') {
			fd.append('PointScale', (tnPtsById('tn-addbracket-point-scale') || {}).value || '');
		}
	};
})();

})();

// ---- Edit Bracket Modal ----
(function() {
	var OVERLAY  = 'tn-editbracket-overlay';
	var EDIT_URL = TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/updatebracket';

	window.tnOpenEditBracketModal = function(bracketId, data) {
		tnHideFeedback('tn-editbracket-feedback');
		document.getElementById('tn-editbracket-bid').value = bracketId;
		document.getElementById('tn-editbracket-style').value        = data.style        || '';
		document.getElementById('tn-editbracket-method').value       = data.method       || 'single';
		tnSegSet('tn-editbracket-participants', data.participants || 'individual');
		document.getElementById('tn-editbracket-rings').value         = data.rings        || 1;
		document.getElementById('tn-editbracket-seeding').value       = data.seeding      || 'random';
		document.getElementById('tn-editbracket-stylenote').value     = data.styleNote    || '';
		var _ebo = document.getElementById('tn-editbracket-bestof');
		if (_ebo) _ebo.value = String(data.bestOf || 1);
		var _edur = document.getElementById('tn-editbracket-duration');
		var _edFld = document.getElementById('tn-editbracket-duration-field');
		if (_edur) _edur.value = data.durationMinutes || 0;
		if (_edFld) _edFld.style.display = (data.method === 'ironman') ? '' : 'none';
		// First-round mode (play-in vs byes): offer only when byes would dominate.
		(function(){
			var fld = document.getElementById('tn-editbracket-firstround-field');
			var seg = document.getElementById('tn-editbracket-firstround');
			var hint = document.getElementById('tn-editbracket-firstround-hint');
			if (!fld || !seg) return;
			var bd = (TnConfig.bracketData || {})[bracketId];
			var n = (bd && bd.Participants) ? bd.Participants.length : 0;
			if (!tnShouldOfferPlayIn(data.method, n)) {
				Array.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(x){ x.classList.remove('tn-seg-active'); });
				fld.style.display = 'none'; return;
			}
			fld.style.display = '';
			var P = 1; while (P < n) P *= 2;
			var byes = P - n;
			if (hint) hint.textContent = 'This bracket would otherwise show ' + byes + ' byes in round 1.';
			var saved = (bd && bd.Bracket && bd.Bracket.FirstRoundMode) ? bd.Bracket.FirstRoundMode : 'play-in';
			Array.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(b){
				b.classList.toggle('tn-seg-active', b.getAttribute('data-val') === saved);
			});
		})();
		// Trigger change so the ironman/team gate re-evaluates for the loaded data.
		var _emSel = document.getElementById('tn-editbracket-method');
		if (_emSel) _emSel.dispatchEvent(new Event('change'));
		// Populate Points fields and sync visibility for existing Points brackets
		(function(){
			var pr = document.getElementById('tn-editbracket-point-rounds');
			var ps = document.getElementById('tn-editbracket-point-scale');
			var pm = document.querySelectorAll('input[name="tn-editbracket-point-mode"]');
			if (pr) pr.value = data.pointRounds || 3;
			if (ps) ps.value = data.pointScale  || '5,3,1,0';
			var modeVal = data.pointMode || 'fixed';
			pm.forEach(function(r){ r.checked = (r.value === modeVal); });
			if (window.tnSyncEditPointsVisibility) tnSyncEditPointsVisibility();
			if (window.tnRenderEditScalePreview) tnRenderEditScalePreview();
		})();
		// Mobile: present as a bottom sheet (foundation handles dismiss/teardown);
		// desktop falls through to the legacy centered overlay unchanged. The B1
		// wizard layers its step UI on top after this returns.
		tnOpenAsSheet(OVERLAY, {});
	};

	(function() {
		var mSel = document.getElementById('tn-editbracket-method');
		var dFld = document.getElementById('tn-editbracket-duration-field');
		function tnGateEditTeam(isIronman) {
			var seg = document.getElementById('tn-editbracket-participants'); if (!seg) return;
			var teamBtn = seg.querySelector('.tn-seg-btn[data-val="team"]'); if (!teamBtn) return;
			teamBtn.disabled = isIronman;
			teamBtn.style.opacity = isIronman ? '0.4' : '';
			teamBtn.style.cursor  = isIronman ? 'not-allowed' : '';
			if (isIronman && tnSegGet('tn-editbracket-participants') === 'team') tnSegSet('tn-editbracket-participants', 'individual');
		}
		if (mSel) mSel.addEventListener('change', function() {
			var isIronman = (mSel.value === 'ironman');
			if (dFld) dFld.style.display = isIronman ? '' : 'none';
			tnGateEditTeam(isIronman);
		});
	})();
	['tn-editbracket-close', 'tn-editbracket-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});

	var ov = document.getElementById(OVERLAY);
	if (ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });
	}

	(function(){
		var seg = document.getElementById('tn-editbracket-firstround');
		if (!seg) return;
		seg.addEventListener('click', function(e){
			var b = e.target.closest('.tn-seg-btn'); if (!b) return;
			Array.prototype.forEach.call(seg.querySelectorAll('.tn-seg-btn'), function(x){ x.classList.remove('tn-seg-active'); });
			b.classList.add('tn-seg-active');
		});
	})();
	var submitBtn = document.getElementById('tn-editbracket-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn    = this;
			var style  = document.getElementById('tn-editbracket-style').value;
			var method = document.getElementById('tn-editbracket-method').value;
			if (!style || !method) { tnShowFeedback('tn-editbracket-feedback', 'Style and format are required.', false); return; }
			if (method === 'points' && window.tnEditBracketScaleError) {
				var _scaleErr = window.tnEditBracketScaleError();
				if (_scaleErr) { tnShowFeedback('tn-editbracket-feedback', _scaleErr, false); return; }
			}

			tnHideFeedback('tn-editbracket-feedback');   // clear a stale error before resubmitting
			btn.disabled = true;
			var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
			if (window.tnRegisterAction) window.tnRegisterAction(actionId);
			if (window.tnCollabNudge) window.tnCollabNudge();
			var fd = new FormData();
			fd.append('BracketId',    document.getElementById('tn-editbracket-bid').value);
			fd.append('Style',        style);
			fd.append('Method',       method);
			fd.append('Participants', tnSegGet('tn-editbracket-participants') || 'individual');
			fd.append('Rings',        document.getElementById('tn-editbracket-rings').value);
			fd.append('Seeding',      document.getElementById('tn-editbracket-seeding').value);
			fd.append('StyleNote',    document.getElementById('tn-editbracket-stylenote').value);
			fd.append('DurationMinutes', document.getElementById('tn-editbracket-duration').value || 0);
			fd.append('BestOf',       document.getElementById('tn-editbracket-bestof').value || 1);
			fd.append('ActionId',     actionId);
				if (window.tnEditBracketIsPoints && window.tnEditBracketIsPoints()) {
					window.tnEditBracketAppendPointsFields(fd);
				}
			// Only send FirstRoundMode when the control was actually offered (a segment
			// is active); otherwise omit it so the server preserves the existing value.
			var _fr = document.querySelector('#tn-editbracket-firstround .tn-seg-btn.tn-seg-active');
			if (_fr) fd.append('FirstRoundMode', _fr.getAttribute('data-val'));

			fetch(EDIT_URL, { method:'POST', body:fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					btn.disabled = false;
					if (d && d.status === 0) {
						tnShowFeedback('tn-editbracket-feedback', 'Bracket updated!', true);
						setTimeout(function() { tnCloseModal(OVERLAY); sessionStorage.setItem('tnOpenTab','brackets'); window.location.reload(); }, 800);
					} else {
						tnShowFeedback('tn-editbracket-feedback', (d && d.error) ? d.error : 'Failed to update bracket.', false);
					}
				})
				.catch(function() { btn.disabled = false; tnShowFeedback('tn-editbracket-feedback', 'Request failed. Please try again.', false); });
		});
	}
// ---- Points Bracket Config — Edit Modal ----
(function(){
	function tnEPtsById(id) { return document.getElementById(id); }
	function tnEPtsModeRadio() {
		var r = document.querySelector('input[name="tn-editbracket-point-mode"]:checked');
		return r ? r.value : 'fixed';
	}
	function tnSyncEditPointsVisibility() {
		var sel = tnEPtsById('tn-editbracket-method');
		if (!sel) return;
		var isPoints = (sel.value === 'points');
		var cfg   = tnEPtsById('tn-editbracket-points-config');
		var scale = tnEPtsById('tn-editbracket-point-scale-row');
		if (cfg)   cfg.style.display   = isPoints ? '' : 'none';
		if (scale) scale.style.display = (isPoints && tnEPtsModeRadio() === 'fixed') ? '' : 'none';
		// Swiss reuses the "rings" field as the ROUND count — relabel to match.
		var rlab = document.querySelector('label[for="tn-editbracket-rings"]');
		if (rlab) rlab.textContent = (sel.value === 'swiss') ? 'Swiss rounds' : 'Rings (concurrent)';
	}
	function tnRenderEditScalePreview() {
		var prev = tnEPtsById('tn-editbracket-point-scale-preview');
		var err  = tnEPtsById('tn-editbracket-point-scale-err');
		var inp  = tnEPtsById('tn-editbracket-point-scale');
		if (!prev || !inp) return true;
		prev.innerHTML = '';
		if (err) err.style.display = 'none';
		var raw = (inp.value || '').trim();
		if (!raw) return true;
		var parts = raw.split(',').map(function(s){ return s.trim(); });
		var seen = {};
		for (var i = 0; i < parts.length; i++) {
			var v = parts[i];
			if (!/^\d+(\.\d{1,2})?$/.test(v) || +v < 0 || +v > 999.99) {
				if (err) { err.textContent = 'Invalid value: "' + v + '"'; err.style.display = ''; }
				return false;
			}
			var k = (+v).toFixed(2);
			if (seen[k]) {
				if (err) { err.textContent = 'Duplicate value: "' + v + '"'; err.style.display = ''; }
				return false;
			}
			seen[k] = true;
		}
		if (parts.length < 1 || parts.length > 16) {
			if (err) { err.textContent = 'Must have 1-16 values'; err.style.display = ''; }
			return false;
		}
		parts.forEach(function(v){
			var s = document.createElement('span');
			s.className = 'tn-pip tn-pip-preview';
			s.textContent = v;
			prev.appendChild(s);
		});
		return true;
	}

	document.addEventListener('DOMContentLoaded', function(){
		var sel = tnEPtsById('tn-editbracket-method');
		if (!sel) return;
		sel.addEventListener('change', tnSyncEditPointsVisibility);
		document.querySelectorAll('input[name="tn-editbracket-point-mode"]').forEach(function(r){
			r.addEventListener('change', tnSyncEditPointsVisibility);
		});
		var scale = tnEPtsById('tn-editbracket-point-scale');
		// A now-valid scale also clears the stale submit error in the modal feedback.
		if (scale) scale.addEventListener('input', function(){ if (tnRenderEditScalePreview()) tnHideFeedback('tn-editbracket-feedback'); });
	});

	window.tnEditBracketIsPoints = function(){
		var sel = tnEPtsById('tn-editbracket-method');
		return sel && sel.value === 'points';
	};
	// Returns '' when the point scale is valid (or not in play), else the error text.
	window.tnEditBracketScaleError = function(){
		if (tnEPtsModeRadio() !== 'fixed' || tnRenderEditScalePreview()) return '';
		var err = tnEPtsById('tn-editbracket-point-scale-err');
		return (err && err.textContent) || 'Invalid point scale.';
	};
	window.tnEditBracketAppendPointsFields = function(fd){
		fd.append('PointRounds', (tnEPtsById('tn-editbracket-point-rounds') || {}).value || '0');
		var mode = (document.querySelector('input[name="tn-editbracket-point-mode"]:checked') || {}).value || 'fixed';
		fd.append('PointMode', mode);
		if (mode === 'fixed') {
			fd.append('PointScale', (tnEPtsById('tn-editbracket-point-scale') || {}).value || '');
		}
	};
	// Also sync when tnOpenEditBracketModal populates the form
	window.tnSyncEditPointsVisibility = tnSyncEditPointsVisibility;
	window.tnRenderEditScalePreview = tnRenderEditScalePreview;
})();

})();

// =============================================================
// B1 — Bracket generation step-wizard (mobile only).
// A presentation layer over the EXISTING Add/Edit Bracket overlays.
// It does NOT introduce a new submit path or endpoint: every wizard
// control reads/writes the SAME hidden inputs the desktop modal uses
// (#tn-{add,edit}bracket-style / -method / -participants / -rings /
// -seeding / -bestof / -duration / -stylenote), and the final
// "Generate" button clicks the EXISTING submit button
// (#tn-addbracket-submit / #tn-editbracket-submit), so the existing
// click handlers (addbracket / updatebracket fetch) run UNCHANGED.
//
// On desktop, and whenever .tn-mobile is not active, the wizard never
// engages — tnOpenAddBracketModal / tnOpenEditBracketModal fall through
// to their original behavior (all fields shown at once). Guarded on
// TnConfig.canManage + the runtime mobile check, never on element
// presence (the overlays are server-rendered, so this file's load
// order is irrelevant).
// =============================================================
(function() {
	if (!window.TnConfig || !TnConfig.canManage) return;

	function isMobileNow() { return !!(window.TnMobile && TnMobile.isMobile()); }

	// Disable/re-enable the Team wizard button and hidden input when ironman is active.
	function tnWizGateParticipants(ctrl, prefix, isIronman) {
		var panel = ctrl.stepEls.participants;
		if (!panel) return;
		var teamBtn = panel.querySelector('.tn-wiz-opt[data-val="team"]');
		if (teamBtn) {
			teamBtn.disabled = isIronman;
			teamBtn.classList.toggle('tn-wiz-opt--disabled', isIronman);
			if (isIronman && teamBtn.classList.contains('tn-wiz-opt--sel')) {
				teamBtn.classList.remove('tn-wiz-opt--sel');
				var indiBtn = panel.querySelector('.tn-wiz-opt[data-val="individual"]');
				if (indiBtn) indiBtn.classList.add('tn-wiz-opt--sel');
				var inp = document.getElementById(prefix + '-participants');
				if (inp) inp.value = 'individual';
			}
		}
	}

	// Per-overlay field id prefix ("tn-addbracket" | "tn-editbracket").
	function fld(prefix, name) { return document.getElementById(prefix + '-' + name); }

	// Option metadata (label + one-line helper) for the radio-row steps.
	var STYLE_OPTS = [
		['Single Sword','Single Sword'], ['Florentine','Florentine'],
		['Sword and Shield','Sword & Shield'], ['Great Weapon','Great Weapon'],
		['Missile','Missile'], ['Jugging','Jugging'], ['Battlegame','Battlegame'],
		['Quest','Quest'], ['Open Weapons','Open Weapons'], ['Other','Other']
	];
	var METHOD_OPTS = [
		['single','Single Elimination','One loss and you are out'],
		['double','Double Elimination','Two losses to be eliminated'],
		['swiss','Swiss','Fixed rounds, paired by record'],
		['round-robin','Round Robin','Everyone fights everyone'],
		['ironman','Ironman','Last fighter standing, timed'],
		['points','Points','Score per round, totals win']
	];
	var PARTICIPANT_OPTS = [
		['individual','Individual','One fighter per slot'],
		['team','Team','Teams compete as a unit']
	];
	var SEEDING_OPTS = [
		['random','Random','Shuffle the field'],
		['manual','Manual','Arrange every seed by hand'],
		['warrior','Orders of the Warrior','Seed by Warrior rank'],
		['glicko2','Performance Score','Seed by rating'],
		['random-manual','Random + Manual Adjust','Shuffle, then tweak'],
		['glicko2-manual','Performance + Manual Adjust','Rate, then tweak']
	];
	var BESTOF_OPTS = [
		['1','Single bout'], ['3','Best of 3'], ['5','Best of 5'],
		['7','Best of 7'], ['9','Best of 9']
	];
	var DURATION_CHIPS = [5,10,15,20,30];

	function methodLabel(v) { for (var i=0;i<METHOD_OPTS.length;i++) if (METHOD_OPTS[i][0]===v) return METHOD_OPTS[i][1]; return v; }
	function styleLabel(v) { for (var i=0;i<STYLE_OPTS.length;i++) if (STYLE_OPTS[i][0]===v) return STYLE_OPTS[i][1]; return v; }
	function seedingLabel(v) { for (var i=0;i<SEEDING_OPTS.length;i++) if (SEEDING_OPTS[i][0]===v) return SEEDING_OPTS[i][1]; return v; }
	function durationLabel(min) { min = parseInt(min,10)||0; return min <= 0 ? 'Unlimited' : (min + ' min'); }
	function bestOfLabel(v) { v = parseInt(v,10)||1; return v <= 1 ? 'Single bout' : ('Best of ' + v); }

	// Build the wizard DOM for one overlay (lazily, once). Stores controllers
	// on the overlay element so re-opens reuse the same nodes (and the same
	// hidden-input writes).
	function buildWizard(prefix, overlayId, submitBtnId) {
		var overlay = document.getElementById(overlayId);
		if (!overlay || overlay._tnWiz) return overlay && overlay._tnWiz;
		var body = overlay.querySelector('.tn-modal-body');
		if (!body) return null;

		var root = document.createElement('div');
		root.className = 'tn-bracket-wizard';

		// Progress header.
		var progress = document.createElement('div');
		progress.className = 'tn-wiz-progress';
		var count = document.createElement('div'); count.className = 'tn-wiz-count';
		var dots = document.createElement('div'); dots.className = 'tn-wiz-dots';
		progress.appendChild(count); progress.appendChild(dots);
		root.appendChild(progress);

		var stepsWrap = document.createElement('div');
		root.appendChild(stepsWrap);
		body.appendChild(root);

		// Footer (injected once, sibling of the legacy .tn-modal-footer which is
		// CSS-hidden while the wizard is active).
		var footer = document.createElement('div');
		footer.className = 'tn-wiz-footer';
		var backBtn = document.createElement('button');
		backBtn.type = 'button'; backBtn.className = 'tn-btn tn-btn-ghost tn-wiz-back'; backBtn.textContent = 'Back';
		var nextBtn = document.createElement('button');
		nextBtn.type = 'button'; nextBtn.className = 'tn-btn tn-btn-primary tn-wiz-next';
		footer.appendChild(backBtn); footer.appendChild(nextBtn);
		var modalBox = overlay.querySelector('.tn-modal-box');
		modalBox.appendChild(footer);

		var ctrl = {
			overlay: overlay, prefix: prefix, submitBtnId: submitBtnId,
			root: root, count: count, dots: dots, stepsWrap: stepsWrap,
			footer: footer, backBtn: backBtn, nextBtn: nextBtn,
			steps: [], stepEls: {}, idx: 0
		};

		// ---- Step builders. Each returns a panel element and wires its
		//      controls to write the EXISTING hidden inputs. ----
		function radioStep(key, title, hint, opts, inputName, withSub) {
			var panel = document.createElement('div');
			panel.className = 'tn-wiz-step';
			var h = document.createElement('div'); h.className = 'tn-wiz-step-title'; h.textContent = title;
			panel.appendChild(h);
			if (hint) { var ht = document.createElement('p'); ht.className = 'tn-wiz-step-hint'; ht.textContent = hint; panel.appendChild(ht); }
			var list = document.createElement('div'); list.className = 'tn-wiz-opts';
			var input = fld(prefix, inputName);
			opts.forEach(function(o) {
				var btn = document.createElement('button');
				btn.type = 'button'; btn.className = 'tn-wiz-opt'; btn.setAttribute('data-val', o[0]);
				var main = document.createElement('span'); main.className = 'tn-wiz-opt-main';
				var lbl = document.createElement('span'); lbl.className = 'tn-wiz-opt-label'; lbl.textContent = o[1];
				main.appendChild(lbl);
				if (withSub && o[2]) { var sub = document.createElement('span'); sub.className = 'tn-wiz-opt-sub'; sub.textContent = o[2]; main.appendChild(sub); }
				var radio = document.createElement('span'); radio.className = 'tn-wiz-opt-radio';
				btn.appendChild(main); btn.appendChild(radio);
				btn.addEventListener('click', function() {
					if (input) input.value = o[0];
					list.querySelectorAll('.tn-wiz-opt').forEach(function(x) { x.classList.remove('tn-wiz-opt--sel'); });
					btn.classList.add('tn-wiz-opt--sel');
					if (typeof panel._onPick === 'function') panel._onPick(o[0]);
				});
				list.appendChild(btn);
			});
			panel.appendChild(list);
			// Reflect-from-input on (re)entry.
			panel._sync = function() {
				var v = input ? input.value : '';
				list.querySelectorAll('.tn-wiz-opt').forEach(function(x) {
					x.classList.toggle('tn-wiz-opt--sel', x.getAttribute('data-val') === v);
				});
			};
			panel._extra = function() {};
			return panel;
		}

		// Style.
		ctrl.stepEls.style = radioStep('style','Weapon Style','Choose the weapon form for this bracket.', STYLE_OPTS, 'style', false);

		// Format/Method (drives the conditional Duration step). Includes an info
		// box (data-tip pattern, NOT title=) that appears for ironman.
		ctrl.stepEls.method = (function() {
			var panel = radioStep('method','Format','Pick the tournament format.', METHOD_OPTS, 'method', true);
			var info = document.createElement('div');
			info.className = 'tn-wiz-info'; info.style.display = 'none';
			info.innerHTML = '<i class="fas fa-info-circle"></i><span>Ironman adds a Duration step before Review.</span>';
			panel.appendChild(info);
			panel._onPick = function(v) {
				info.style.display = (v === 'ironman') ? '' : 'none';
				rebuildSteps();   // re-map step list; preserves all entered values
				// Gate: disable Team option in participants step when ironman selected.
				tnWizGateParticipants(ctrl, prefix, v === 'ironman');
			};
			var origSync = panel._sync;
			panel._sync = function() {
				origSync();
				var input = fld(prefix,'method');
				info.style.display = (input && input.value === 'ironman') ? '' : 'none';
			};
			return panel;
		})();

		// Participants.
		ctrl.stepEls.participants = (function() {
			var panel = radioStep('participants','Participants','Who competes in this bracket?', PARTICIPANT_OPTS, 'participants', true);
			var origSync = panel._sync;
			panel._sync = function() {
				origSync();
				// Re-apply ironman gate each time the step is rendered.
				var mEl = fld(prefix, 'method');
				tnWizGateParticipants(ctrl, prefix, !!(mEl && mEl.value === 'ironman'));
			};
			return panel;
		})();

		// Seeding.
		ctrl.stepEls.seeding = radioStep('seeding','Seeding','How are fighters placed in the bracket?', SEEDING_OPTS, 'seeding', true);

		// Rings (± stepper writing the existing number input).
		ctrl.stepEls.rings = (function() {
			var input = fld(prefix,'rings');
			var panel = document.createElement('div'); panel.className = 'tn-wiz-step';
			panel.innerHTML = '<div class="tn-wiz-step-title">Concurrent Rings</div>'
				+ '<p class="tn-wiz-step-hint">How many matches run at once? Swiss / Round Robin round counts derive from this.</p>';
			var wrap = document.createElement('div'); wrap.className = 'tn-wiz-stepper';
			var minus = document.createElement('button'); minus.type='button'; minus.className='tn-wiz-stepper-btn'; minus.textContent='\u2212';
			var val = document.createElement('div'); val.className = 'tn-wiz-stepper-val';
			var plus = document.createElement('button'); plus.type='button'; plus.className='tn-wiz-stepper-btn'; plus.textContent='+';
			wrap.appendChild(minus); wrap.appendChild(val); wrap.appendChild(plus);
			panel.appendChild(wrap);
			var MIN = 1, MAX = 20;
			function render() {
				var v = parseInt(input && input.value, 10); if (isNaN(v) || v < MIN) v = MIN; if (v > MAX) v = MAX;
				if (input) input.value = v;
				val.textContent = v;
				minus.disabled = (v <= MIN); plus.disabled = (v >= MAX);
			}
			minus.addEventListener('click', function() { var v = parseInt(input.value,10)||1; input.value = Math.max(MIN, v-1); render(); });
			plus.addEventListener('click', function() { var v = parseInt(input.value,10)||1; input.value = Math.min(MAX, v+1); render(); });
			panel._sync = render;
			panel._extra = function() {};
			return panel;
		})();

		// Best-of (radio rows over the existing select).
		ctrl.stepEls.bestof = radioStep('bestof','Bouts per Match','Number of bouts to decide each match. Less meaningful for Ironman / Score.', BESTOF_OPTS, 'bestof', false);

		// Duration (IRONMAN ONLY): chip presets + custom stepper, writing the
		// existing minutes input. 0 = Unlimited (human-readable label).
		ctrl.stepEls.duration = (function() {
			var input = fld(prefix,'duration');
			var panel = document.createElement('div'); panel.className = 'tn-wiz-step';
			panel.innerHTML = '<div class="tn-wiz-step-title">Max Duration</div>'
				+ '<p class="tn-wiz-step-hint">Time cap per ironman ring. 0 = Unlimited.</p>';
			var chips = document.createElement('div'); chips.className = 'tn-wiz-chips';
			var customChip;
			var stepperWrap = document.createElement('div'); stepperWrap.className = 'tn-wiz-stepper'; stepperWrap.style.display = 'none';
			var minus = document.createElement('button'); minus.type='button'; minus.className='tn-wiz-stepper-btn'; minus.textContent='\u2212';
			var sval = document.createElement('div'); sval.className='tn-wiz-stepper-val';
			var plus = document.createElement('button'); plus.type='button'; plus.className='tn-wiz-stepper-btn'; plus.textContent='+';
			stepperWrap.appendChild(minus); stepperWrap.appendChild(sval); stepperWrap.appendChild(plus);
			var readout = document.createElement('p'); readout.className = 'tn-wiz-step-hint'; readout.style.marginTop = '10px';
			function setVal(min) {
				min = parseInt(min,10); if (isNaN(min) || min < 0) min = 0; if (min > 480) min = 480;
				if (input) input.value = min;
				render();
			}
			function render() {
				var v = parseInt(input && input.value,10)||0;
				var preset = DURATION_CHIPS.indexOf(v) !== -1 || v === 0;
				chips.querySelectorAll('.tn-wiz-chip').forEach(function(c) {
					var cv = c.getAttribute('data-min');
					c.classList.toggle('tn-wiz-chip--sel', cv !== null && parseInt(cv,10) === v);
				});
				if (customChip) customChip.classList.toggle('tn-wiz-chip--sel', !preset);
				stepperWrap.style.display = preset ? 'none' : '';
				sval.textContent = v;
				readout.textContent = 'Selected: ' + durationLabel(v);
			}
			// Unlimited chip (0) first, then presets, then Custom.
			[[0,'Unlimited']].concat(DURATION_CHIPS.map(function(m){return [m, m+' min'];})).forEach(function(o) {
				var c = document.createElement('button'); c.type='button'; c.className='tn-wiz-chip'; c.setAttribute('data-min', o[0]); c.textContent = o[1];
				c.addEventListener('click', function() { setVal(o[0]); });
				chips.appendChild(c);
			});
			customChip = document.createElement('button'); customChip.type='button'; customChip.className='tn-wiz-chip'; customChip.textContent='Custom';
			customChip.addEventListener('click', function() {
				var v = parseInt(input && input.value,10)||0;
				if (DURATION_CHIPS.indexOf(v) !== -1 || v === 0) setVal(25); // jump to an off-preset start
				else render();
			});
			chips.appendChild(customChip);
			minus.addEventListener('click', function() { setVal((parseInt(input.value,10)||0) - 5); });
			plus.addEventListener('click', function() { setVal((parseInt(input.value,10)||0) + 5); });
			panel.appendChild(chips); panel.appendChild(stepperWrap); panel.appendChild(readout);
			panel._sync = render;
			panel._extra = function() {};
			return panel;
		})();

		// Review/Generate.
		ctrl.stepEls.review = (function() {
			var panel = document.createElement('div'); panel.className = 'tn-wiz-step';
			panel.innerHTML = '<div class="tn-wiz-step-title">Review</div>';
			var card = document.createElement('div'); card.className = 'tn-wiz-review';
			panel.appendChild(card);
			var note = document.createElement('div'); note.className = 'tn-wiz-info'; note.style.display = 'none';
			note.innerHTML = '<i class="fas fa-hand-pointer"></i><span>You will arrange seeds by dragging after creating the bracket.</span>';
			panel.appendChild(note);
			panel._sync = function() {
				var style = fld(prefix,'style').value;
				var method = fld(prefix,'method').value;
				var parts = fld(prefix,'participants').value;
				var seeding = fld(prefix,'seeding').value;
				var rings = fld(prefix,'rings').value;
				var bestof = fld(prefix,'bestof').value;
				var dur = fld(prefix,'duration').value;
				var snEl = fld(prefix,'stylenote'); var sn = snEl ? snEl.value.trim() : '';
				var lines = [];
				lines.push('<div class="tn-wiz-review-line"><strong>' + styleLabel(style) + '</strong> \u00b7 ' + methodLabel(method) + '</div>');
				lines.push('<div class="tn-wiz-review-line">' + (parts === 'team' ? 'Team' : 'Individual') + ' \u00b7 ' + seedingLabel(seeding) + ' seeding</div>');
				var ringTxt = rings + (parseInt(rings,10) === 1 ? ' ring' : ' rings');
				lines.push('<div class="tn-wiz-review-line">' + ringTxt + ' \u00b7 ' + bestOfLabel(bestof) + (method === 'ironman' ? (' \u00b7 ' + durationLabel(dur)) : '') + '</div>');
				if (sn) lines.push('<div class="tn-wiz-review-line">Style note: \u201c' + sn.replace(/</g,'&lt;') + '\u201d</div>');
				card.innerHTML = lines.join('');
				var isManual = (seeding === 'manual' || seeding === 'random-manual' || seeding === 'glicko2-manual');
				note.style.display = isManual ? '' : 'none';
			};
			panel._extra = function() {};
			return panel;
		})();

		// Compute the active step list given the current Method (dynamic).
		function activeStepKeys() {
			var keys = ['style','method','participants','seeding','rings','bestof'];
			var mEl = fld(prefix,'method');
			if (mEl && mEl.value === 'ironman') keys.push('duration');
			keys.push('review');
			return keys;
		}

		// (Re)build the step sequence WITHOUT losing entered values (values live
		// in the persistent hidden inputs; we only re-attach the panels and
		// re-clamp idx). Called on Method change.
		function rebuildSteps() {
			var keys = activeStepKeys();
			// Preserve the key the user is currently on if it still exists.
			var curKey = ctrl.steps[ctrl.idx];
			ctrl.steps = keys;
			// Re-attach panels in order.
			ctrl.stepsWrap.innerHTML = '';
			keys.forEach(function(k) { ctrl.stepsWrap.appendChild(ctrl.stepEls[k]); });
			var newIdx = keys.indexOf(curKey);
			ctrl.idx = (newIdx === -1) ? Math.min(ctrl.idx, keys.length - 1) : newIdx;
			renderStep();
		}

		function renderDots() {
			ctrl.dots.innerHTML = '';
			ctrl.count.textContent = 'Step ' + (ctrl.idx + 1) + ' of ' + ctrl.steps.length;
			for (var i = 0; i < ctrl.steps.length; i++) {
				var d = document.createElement('div');
				d.className = 'tn-wiz-dot' + (i < ctrl.idx ? ' tn-wiz-dot--done' : (i === ctrl.idx ? ' tn-wiz-dot--cur' : ''));
				ctrl.dots.appendChild(d);
			}
		}

		function renderStep() {
			ctrl.steps.forEach(function(k, i) {
				ctrl.stepEls[k].classList.toggle('tn-wiz-step--active', i === ctrl.idx);
			});
			var panel = ctrl.stepEls[ctrl.steps[ctrl.idx]];
			if (panel && typeof panel._sync === 'function') panel._sync();
			renderDots();
			ctrl.backBtn.style.visibility = (ctrl.idx === 0) ? 'hidden' : '';
			var isLast = (ctrl.idx === ctrl.steps.length - 1);
			if (isLast) {
				ctrl.nextBtn.innerHTML = (prefix === 'tn-editbracket')
					? '<i class="fas fa-save"></i> Save Bracket'
					: '<i class="fas fa-check"></i> Create Bracket';
			} else {
				ctrl.nextBtn.innerHTML = 'Next <i class="fas fa-arrow-right"></i>';
			}
			// Scroll the body to top so the new step starts at the top.
			var b = overlay.querySelector('.tn-modal-body'); if (b) b.scrollTop = 0;
		}

		function validateCurrent() {
			var k = ctrl.steps[ctrl.idx];
			if (k === 'style' && !fld(prefix,'style').value) return false;
			if (k === 'method' && !fld(prefix,'method').value) return false;
			return true;
		}

		ctrl.nextBtn.addEventListener('click', function() {
			if (ctrl.idx === ctrl.steps.length - 1) {
				// Final step → invoke the EXISTING submit handler/button.
				var sb = document.getElementById(submitBtnId);
				if (sb) sb.click();
				return;
			}
			if (!validateCurrent()) return;
			ctrl.idx++;
			renderStep();
		});
		ctrl.backBtn.addEventListener('click', function() {
			if (ctrl.idx > 0) { ctrl.idx--; renderStep(); }
		});

		// Swipe accelerators (foundation): left = Next (if valid), right = Back.
		if (window.TnMobile && TnMobile.swipe) {
			TnMobile.swipe(root, {
				onLeft: function() { if (ctrl.idx < ctrl.steps.length - 1 && validateCurrent()) { ctrl.idx++; renderStep(); } },
				onRight: function() { if (ctrl.idx > 0) { ctrl.idx--; renderStep(); } },
				threshold: 50
			});
		}

		ctrl.start = function() {
			ctrl.idx = 0;
			ctrl.steps = activeStepKeys();
			ctrl.stepsWrap.innerHTML = '';
			ctrl.steps.forEach(function(k) { ctrl.stepsWrap.appendChild(ctrl.stepEls[k]); });
			renderStep();
		};
		ctrl._rebuild = rebuildSteps;

		overlay._tnWiz = ctrl;
		return ctrl;
	}

	// Engage the wizard on an already-open overlay (called from the wrapped
	// open functions, AFTER they have populated the hidden inputs).
	function engage(prefix, overlayId, submitBtnId) {
		var overlay = document.getElementById(overlayId);
		if (!overlay) return;
		var ctrl = buildWizard(prefix, overlayId, submitBtnId);
		if (!ctrl) return;
		overlay.classList.add('tn-wiz-active');
		ctrl.start();
	}

	// Tear the wizard chrome down so the desktop modal renders normally if the
	// same overlay is later opened on desktop / after a view-mode flip.
	function disengage(overlayId) {
		var overlay = document.getElementById(overlayId);
		if (overlay) overlay.classList.remove('tn-wiz-active');
	}

	// ---- Wrap the existing open functions. The originals populate the hidden
	//      inputs and open the modal/sheet; we then layer the wizard on top
	//      (mobile only). Desktop falls through untouched. ----
	function wrap(fnName, prefix, overlayId, submitBtnId) {
		var orig = window[fnName];
		if (typeof orig !== 'function') return;
		window[fnName] = function() {
			var r = orig.apply(this, arguments);
			if (isMobileNow()) engage(prefix, overlayId, submitBtnId);
			else disengage(overlayId);
			return r;
		};
	}
	wrap('tnOpenAddBracketModal',  'tn-addbracket',  'tn-addbracket-overlay',  'tn-addbracket-submit');
	wrap('tnOpenEditBracketModal', 'tn-editbracket', 'tn-editbracket-overlay', 'tn-editbracket-submit');

	// On view-mode flip, drop the wizard chrome from any open bracket overlay so
	// it does not linger as a half-state when switching to desktop. The sheet
	// foundation handles the overlay's own sheet/centered reversion.
	document.addEventListener('tn:viewmodechange', function(e) {
		if (e.detail && e.detail.isMobile) return;
		disengage('tn-addbracket-overlay');
		disengage('tn-editbracket-overlay');
	});
})();

// Helper: position an autocomplete dropdown with fixed coords (breaks out of modal)
function tnFixedAcPosition(inputEl, dropdownEl) {
	// Move the dropdown to <body> so position:fixed resolves against the
	// viewport. Inside the modals, .tn-modal-box has a transform, which makes
	// it the containing block for fixed descendants (and .tn-modal-body clips
	// via overflow); appending to body escapes both.
	if (dropdownEl.parentNode !== document.body) {
		document.body.appendChild(dropdownEl);
	}
	var rect = inputEl.getBoundingClientRect();
	dropdownEl.style.position = 'fixed';
	dropdownEl.style.left     = rect.left + 'px';
	dropdownEl.style.width    = rect.width + 'px';
	dropdownEl.style.top      = (rect.bottom + 4) + 'px';
	dropdownEl.style.right    = 'auto';
	dropdownEl.style.zIndex   = '9999';

	// --- Re-anchor while open (PHASE C0 Task 3) ---------------------------
	// The snapshot above is correct at call time, but inside a bottom sheet the
	// body scrolls and the soft keyboard resizes the viewport, so the fixed
	// dropdown drifts from its input. While the dropdown is OPEN, listen for
	// scroll (capture: catches the sheet body's inner scroll too) and
	// visualViewport/window resize, and re-snapshot. Listeners are attached
	// once per dropdown and torn down automatically the moment the dropdown is
	// no longer open (class removed) or detached from the DOM — so no leak and
	// DESKTOP is unaffected: on desktop the dropdown closes the same way and
	// the listeners self-remove; while open, a re-snapshot is a harmless no-op
	// (the input doesn't move on a non-scrolling desktop modal).
	if (!dropdownEl._tnAcReanchor) {
		var vv = window.visualViewport || null;
		var reposition = function(e) {
			// Stop + clean up once the dropdown is closed or removed.
			var open = dropdownEl.parentNode &&
				dropdownEl.classList.contains('kn-ac-open');
			if (!open) { teardown(); return; }
			// Scrolling the result list itself never moves the input.
			if (e && e.type === 'scroll' && e.target === dropdownEl) return;
			// A dropdown with its own placement (e.g. flip above the input)
			// supplies it as _tnAcPlace; defer to it so re-anchoring keeps it.
			if (typeof dropdownEl._tnAcPlace === 'function') { dropdownEl._tnAcPlace(); return; }
			var r = inputEl.getBoundingClientRect();
			dropdownEl.style.left  = r.left + 'px';
			dropdownEl.style.width = r.width + 'px';
			dropdownEl.style.top   = (r.bottom + 4) + 'px';
		};
		var teardown = function() {
			window.removeEventListener('scroll', reposition, true);
			window.removeEventListener('resize', reposition);
			if (vv) {
				vv.removeEventListener('resize', reposition);
				vv.removeEventListener('scroll', reposition);
			}
			dropdownEl._tnAcReanchor = null;
		};
		window.addEventListener('scroll', reposition, true); // capture: inner scrollers too
		window.addEventListener('resize', reposition);
		if (vv) {
			vv.addEventListener('resize', reposition);
			vv.addEventListener('scroll', reposition);
		}
		dropdownEl._tnAcReanchor = teardown;
	}
}

// ---- Edit Tournament Modal ----
(function() {
	if (!TnConfig.canManage) return;
	var OVERLAY   = 'tn-edittournament-overlay';
	var parkTimer, eventTimer;

	window.tnOpenEditTournamentModal = function() {
		tnHideFeedback('tn-edittournament-feedback');
		var nameEl    = document.getElementById('tn-et-name');
		var descEl    = document.getElementById('tn-et-description');
		var dateEl    = document.getElementById('tn-et-date');
		var urlEl     = document.getElementById('tn-et-url');
		var parkTx    = document.getElementById('tn-et-park-text');
		var parkId    = document.getElementById('tn-et-park-id');
		var kId       = document.getElementById('tn-et-kingdom-id');
		var kDisp     = document.getElementById('tn-et-kingdom-display');
		var evTx      = document.getElementById('tn-et-event-text');
		var ecdEl     = document.getElementById('tn-et-ecd-id');
		if (nameEl)   nameEl.value  = TnConfig.tournamentName        || '';
		if (descEl)   descEl.value  = TnConfig.tournamentDescription || '';
		if (dateEl)   dateEl.value  = TnConfig.tournamentDate        || '';
		// Human-readable date display via Flatpickr (project convention: no
		// raw ISO visible). Flatpickr is lazy-loaded on first modal open so it
		// is not fetched on read-only page views. The hidden ISO value stays
		// on #tn-et-date.
		if (dateEl) {
			tnEnsureFlatpickr(function() {
				if (typeof flatpickr !== 'function') return;
				if (!dateEl._tnFp) {
					dateEl._tnFp = flatpickr(dateEl, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y',
						// Point the DATE label at the visible alt input so it has an accessible name.
						onReady: function(sel, str, fp) {
							if (!fp.altInput) return;
							fp.altInput.id = 'tn-et-date-alt';
							var lbl = document.querySelector('label[for="tn-et-date"]');
							if (lbl) lbl.setAttribute('for', 'tn-et-date-alt');
						}
					});
				}
				if (TnConfig.tournamentDate) dateEl._tnFp.setDate(TnConfig.tournamentDate, false);
				else dateEl._tnFp.clear();
			});
		}
		if (urlEl)    urlEl.value   = TnConfig.tournamentUrl         || '';
		if (parkTx)   parkTx.value  = TnConfig.parkName              || '';
		if (parkId)   parkId.value  = TnConfig.parkId                || 0;
		if (kId)      kId.value     = TnConfig.kingdomId             || 0;
		if (kDisp)    kDisp.textContent = TnConfig.kingdomName ? 'Kingdom: ' + TnConfig.kingdomName : '';
		if (evTx)     evTx.value    = TnConfig.eventLabel            || '';
		if (ecdEl)    ecdEl.value   = TnConfig.ecdId                 || 0;
		tnEtParkAcClose();
		tnEtEventAcClose();
		tnOpenAsSheet(OVERLAY, {});
	};

	// ---- Park autocomplete ----
	var parkInput  = document.getElementById('tn-et-park-text');
	var parkIdEl   = document.getElementById('tn-et-park-id');
	var kIdEl      = document.getElementById('tn-et-kingdom-id');
	var kDispEl    = document.getElementById('tn-et-kingdom-display');
	var parkAcEl   = document.getElementById('tn-et-park-results');

	function tnEtParkAcClose() {
		if (parkAcEl) { parkAcEl.innerHTML = ''; parkAcEl.classList.remove('kn-ac-open'); }
	}
	function tnEtParkAcRender(parks) {
		if (!parkAcEl) return;
		parkAcEl.innerHTML = '';
		if (!parks || !parks.length) {
			parkAcEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No parks found</div>';
			if (parkInput) tnFixedAcPosition(parkInput, parkAcEl);
			parkAcEl.classList.add('kn-ac-open');
			return;
		}
		parks.forEach(function(pk) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			var sub = pk.KingdomName ? ' <span style="color:#a0aec0;font-size:11px">(' + tnEsc(pk.KingdomName) + ')</span>' : '';
			item.innerHTML = tnEsc(pk.ParkName) + sub;
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				if (parkInput) parkInput.value = pk.ParkName    || '';
				if (parkIdEl)  parkIdEl.value  = pk.ParkId      || 0;
				if (kIdEl)     kIdEl.value     = pk.KingdomId   || 0;
				if (kDispEl)   kDispEl.textContent = pk.KingdomName ? 'Kingdom: ' + pk.KingdomName : '';
				tnEtParkAcClose();
			});
			parkAcEl.appendChild(item);
		});
		if (parkInput) tnFixedAcPosition(parkInput, parkAcEl);
		parkAcEl.classList.add('kn-ac-open');
	}
	if (parkInput && parkAcEl) {
		parkInput.addEventListener('input', function() {
			var term = this.value.trim();
			if (parkIdEl) parkIdEl.value = '0';
			clearTimeout(parkTimer);
			if (term.length < 2) { tnEtParkAcClose(); return; }
			parkTimer = setTimeout(function() {
				fetch(TnConfig.uir + 'TournamentAjax/parksearch&q=' + encodeURIComponent(term))
					.then(function(r) { return r.json(); })
					.then(function(data) { tnEtParkAcRender(Array.isArray(data) ? data : []); })
					.catch(function() { tnEtParkAcClose(); });
			}, 280);
		});
		parkInput.addEventListener('blur', function() { setTimeout(tnEtParkAcClose, 200); });
	}

	// ---- Event autocomplete ----
	var evInput  = document.getElementById('tn-et-event-text');
	var evEcdEl  = document.getElementById('tn-et-ecd-id');
	var evAcEl   = document.getElementById('tn-et-event-results');
	var evClear  = document.getElementById('tn-et-event-clear');

	function tnEtEventAcClose() {
		if (evAcEl) { evAcEl.innerHTML = ''; evAcEl.classList.remove('kn-ac-open'); }
	}
	function tnEtEventAcRender(events) {
		if (!evAcEl) return;
		evAcEl.innerHTML = '';
		if (!events || !events.length) {
			evAcEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No events found</div>';
			if (evInput) tnFixedAcPosition(evInput, evAcEl);
			evAcEl.classList.add('kn-ac-open');
			return;
		}
		events.forEach(function(ev) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			item.textContent = ev.Label || ev.EventName || '';
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				if (evInput) evInput.value = ev.Label || ev.EventName || '';
				if (evEcdEl) evEcdEl.value = ev.EcdId || 0;
				tnEtEventAcClose();
			});
			evAcEl.appendChild(item);
		});
		if (evInput) tnFixedAcPosition(evInput, evAcEl);
		evAcEl.classList.add('kn-ac-open');
	}
	if (evInput && evAcEl) {
		evInput.addEventListener('input', function() {
			var term = this.value.trim();
			if (evEcdEl) evEcdEl.value = '0';
			clearTimeout(eventTimer);
			if (term.length < 2) { tnEtEventAcClose(); return; }
			eventTimer = setTimeout(function() {
				fetch(TnConfig.uir + 'TournamentAjax/eventsearch&q=' + encodeURIComponent(term) + '&KingdomId=' + (parseInt(TnConfig.searchKingdomId, 10) || 0))
					.then(function(r) { return r.json(); })
					.then(function(data) { tnEtEventAcRender(Array.isArray(data) ? data : []); })
					.catch(function() { tnEtEventAcClose(); });
			}, 280);
		});
		evInput.addEventListener('blur', function() { setTimeout(tnEtEventAcClose, 200); });
	}
	if (evClear) {
		evClear.addEventListener('click', function() {
			if (evInput)  evInput.value  = '';
			if (evEcdEl)  evEcdEl.value  = '0';
			tnEtEventAcClose();
		});
	}

	['tn-edittournament-close', 'tn-edittournament-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	var submitBtn = document.getElementById('tn-edittournament-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn  = this;
			var name = document.getElementById('tn-et-name').value.trim();
			if (!name) { tnShowFeedback('tn-edittournament-feedback', 'Name is required.', false); return; }
			btn.disabled = true;
			var fd = new FormData();
			fd.append('Name',                  name);
			fd.append('Description',           document.getElementById('tn-et-description').value.trim());
			fd.append('Url',                   document.getElementById('tn-et-url').value.trim());
			fd.append('When',                  document.getElementById('tn-et-date').value);
			fd.append('ParkId',                document.getElementById('tn-et-park-id').value   || 0);
			fd.append('KingdomId',             document.getElementById('tn-et-kingdom-id').value || 0);
			fd.append('EventCalendarDetailId', document.getElementById('tn-et-ecd-id').value    || 0);
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/updatetournament', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					btn.disabled = false;
					if (d && d.status === 0) {
						tnShowFeedback('tn-edittournament-feedback', 'Tournament updated!', true);
						setTimeout(function() { tnCloseModal(OVERLAY); window.location.reload(); }, 800);
					} else {
						tnShowFeedback('tn-edittournament-feedback', (d && d.error) ? d.error : 'Failed to save changes.', false);
					}
				})
				.catch(function() { btn.disabled = false; tnShowFeedback('tn-edittournament-feedback', 'Request failed. Please try again.', false); });
		});
	}
})();

// ---- Add Participant Modal ----
(function() {
	var OVERLAY      = 'tn-addparticipant-overlay';
	var playerTimer;
	var _addedCount  = 0;

	window.tnOpenAddParticipantModal = function(bracketId, tournamentId) {
		_addedCount = 0;
		document.getElementById('tn-addparticipant-bracket-id').value    = bracketId;
		document.getElementById('tn-addparticipant-tournament-id').value = tournamentId;
		document.getElementById('tn-addparticipant-alias').value         = '';
		tnApAliasWarn();
		document.getElementById('tn-addparticipant-player-text').value   = '';
		document.getElementById('tn-addparticipant-player-id').value     = '0';
		tnAcClose();
		tnHideFeedback('tn-addparticipant-feedback');
		tnBuildQuickAddList(bracketId, tournamentId);
		// Mobile: present as a bottom sheet (swipe-down dismiss, sticky footer,
		// keyboard-safe height); desktop falls through to the legacy centered
		// overlay so behavior is byte-identical. tnCloseModal routes the
		// _tnSheet overlay back through TnMobile.sheet.close on teardown.
		tnOpenAsSheet(OVERLAY, {});
	};

	// Quick Add list — participants from other brackets not yet in target bracket
	function tnBuildQuickAddList(bracketId, tournamentId) {
		var section = document.getElementById('tn-quickadd-section');
		var panel   = document.getElementById('tn-quickadd-list');
		if (!section || !panel) return;
		panel.innerHTML = '';

		// Collect ParticipantIds already in the target bracket
		var inBracket = {};
		var bd = TnConfig.bracketData[bracketId];
		if (bd && bd.Participants) {
			bd.Participants.forEach(function(p) {
				inBracket['pid' + p.ParticipantId] = true;
				if (p.MundaneId > 0) inBracket['mid' + p.MundaneId] = true;
			});
		}

		// Gather candidates from all other brackets
		var candidates = [];
		var seen = {};
		for (var bid in TnConfig.bracketData) {
			if (parseInt(bid) === parseInt(bracketId)) continue;
			var bData = TnConfig.bracketData[bid];
			if (!bData || !bData.Participants) continue;
			bData.Participants.forEach(function(p) {
				var key = p.MundaneId > 0 ? ('mid' + p.MundaneId) : ('pid' + p.ParticipantId);
				if (!inBracket[key] && !seen[key]) {
					seen[key] = true;
					candidates.push(p);
				}
			});
		}

		if (candidates.length === 0) { section.style.display = 'none'; return; }
		section.style.display = '';

		// Wire Add All button
		var addAllBtn = document.getElementById('tn-quickadd-addall');
		if (addAllBtn) {
			addAllBtn.onclick = function() {
				var rows = document.querySelectorAll('#tn-quickadd-list .tn-quickadd-row:not(.tn-quickadd-done)');
				rows.forEach(function(row) {
					var btn = row.querySelector('button');
					if (btn && !btn.disabled) btn.click();
				});
			};
		}

		candidates.forEach(function(p) {
			var row = document.createElement('div');
			row.className = 'tn-quickadd-row';
			var nameEl = document.createElement('span');
			nameEl.className = 'tn-quickadd-name';
			nameEl.textContent = p.Alias || p.Persona || '—';
			if (p.Persona && p.Alias && p.Alias !== p.Persona) nameEl.setAttribute('data-tip', p.Persona);
			var btn = document.createElement('button');
			btn.className = 'tn-btn tn-btn-outline tn-btn-sm';
			btn.style.cssText = 'padding:2px 10px;flex-shrink:0';
			btn.innerHTML = '<i class="fas fa-plus"></i> Add';
			btn.addEventListener('click', function() { tnQuickAdd(p, bracketId, tournamentId, row); });
			row.appendChild(nameEl);
			row.appendChild(btn);
			panel.appendChild(row);
		});
	}

	function tnQuickAdd(p, bracketId, tournamentId, rowEl) {
		var alias     = p.Alias || p.Persona || '';
		var mundaneId = p.MundaneId || 0;
		if (!alias) return;
		var qBtn = rowEl.querySelector('button');
		if (qBtn) qBtn.disabled = true;
		var fd = new FormData();
		fd.append('Alias', alias);
		fd.append('MundaneId', mundaneId);
		fd.append('TournamentId', tournamentId);
		fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/addparticipant', {method:'POST', body:fd})
			.then(function(r){ return r.json(); })
			.then(function(d){
				if (d && d.status === 0) {
					_addedCount++;
					// Keep bracketData in sync so BracketViz + the quick-add list reflect
					// the add without a full reload (#59). Mirrors the team-add patch.
					if (TnConfig.bracketData[bracketId]) {
						if (!TnConfig.bracketData[bracketId].Participants) TnConfig.bracketData[bracketId].Participants = [];
						TnConfig.bracketData[bracketId].Participants.push({
							ParticipantId: d.participantId || 0,
							ParticipantNumber: d.participantNumber || 0,
							Alias: alias,
							MundaneId: mundaneId,
							Persona: (p && p.Persona) ? p.Persona : alias
						});
					}
					if (window.tnRefreshRoster) window.tnRefreshRoster();
					rowEl.classList.add('tn-quickadd-done');
					if (qBtn) { qBtn.innerHTML = '<i class="fas fa-check"></i>'; qBtn.disabled = true; }
					var card = document.getElementById('tn-bracket-' + bracketId);
					if (card) {
						var emptyEl = card.querySelector('.tn-bracket-body .tn-empty');
						if (emptyEl) emptyEl.remove();
						var ul = card.querySelector('.tn-participant-list');
						if (!ul) { ul = document.createElement('ul'); ul.className = 'tn-participant-list'; var body = card.querySelector('.tn-bracket-body'); if (body) body.insertBefore(ul, body.firstChild); }
						var num = ul.querySelectorAll('li').length + 1;
						var li  = document.createElement('li');
						var seedSpan = document.createElement('span');
						seedSpan.className = d.participantNumber ? 'tn-pid' : 'tn-participant-seed';
						seedSpan.textContent = d.participantNumber || num; if (d.participantNumber) seedSpan.setAttribute('data-tip', 'Player #' + d.participantNumber + ' — same number across every bracket');
						li.appendChild(seedSpan);
						var aliasSpan = document.createElement('span');
						aliasSpan.style.flex = '1';
						aliasSpan.textContent = alias;
						li.appendChild(aliasSpan);
						if (TnConfig.canManage) {
							var rmBtn = document.createElement('button');
							rmBtn.className = 'tn-remove-participant';
							rmBtn.setAttribute('data-pid', String(d.participantId || 0));
							rmBtn.setAttribute('data-bid', String(bracketId));
							rmBtn.setAttribute('data-tid', String(TnConfig.tournamentId));
							rmBtn.setAttribute('data-tip', 'Remove participant');
							rmBtn.innerHTML = '&times;';
							rmBtn.addEventListener('click', function() { tnRemoveParticipant(this); });
							li.appendChild(rmBtn);
						}
						ul.appendChild(li);
						var hdr = card.querySelector('.tn-bracket-header');
						if (hdr) hdr.querySelectorAll('span').forEach(function(s){ if(/\d+ participant/.test(s.textContent)) s.textContent = num + ' participant' + (num !== 1 ? 's' : ''); });
					}
				} else {
					if (qBtn) qBtn.disabled = false;
					tnShowFeedback('tn-addparticipant-feedback', (d && d.error) ? d.error : 'Failed to add.', false);
				}
			})
			.catch(function(){ if (qBtn) qBtn.disabled = false; tnShowFeedback('tn-addparticipant-feedback', 'Request failed.', false); });
	}

	// After adds, sync the on-screen BracketViz in place instead of a full reload (#59).
	// The bracket-card DOM + TnConfig.bracketData were already updated per-add, so we
	// only re-render the viz when the changed bracket is the one currently displayed
	// (mirrors the collab refetchBracket guard); otherwise the next tab activation
	// renders it from the patched bracketData.
	function tnAfterParticipantsAdded() {
		if (_addedCount <= 0) return;
		_addedCount = 0;
		var _apB = document.getElementById('tn-addparticipant-bracket-id');
		var _apBid = _apB ? parseInt(_apB.value) : 0;
		var _apSel = document.getElementById('tn-bv-bracket-select');
		var _apCur = _apSel ? parseInt(_apSel.value) : 0;
		if (_apBid && _apBid === _apCur && window.tnRenderBracketViz) tnRenderBracketViz(_apBid);
	}

	// Backdrop click — sync viz if participants were added
	var ov = document.getElementById(OVERLAY);
	if (ov) {
		ov.addEventListener('click', function(e) {
			if (e.target === ov) {
				tnCloseModal(OVERLAY);
				tnAfterParticipantsAdded();
			}
		});
	}

	// Player autocomplete — kingdom-scoped first, global SOAP fallback
	var playerInput = document.getElementById('tn-addparticipant-player-text');
	var playerIdEl  = document.getElementById('tn-addparticipant-player-id');
	var resultsEl   = document.getElementById('tn-addparticipant-player-results');

	function tnAcClose() {
		if (!resultsEl) return;
		resultsEl.classList.remove('kn-ac-open');
		resultsEl.innerHTML = '';
	}

	function tnAcRender(players) {
		resultsEl.innerHTML = '';
		if (!players || !players.length) {
			resultsEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No players found</div>';
			if (playerInput) tnFixedAcPosition(playerInput, resultsEl);
			resultsEl.classList.add('kn-ac-open');
			return;
		}
		players.forEach(function(pl) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			var label = tnEsc(pl.Persona || pl.Name || '');
			var sub   = pl.KAbbr ? (' <span style="color:#a0aec0;font-size:11px">(' + tnEsc(pl.KAbbr) + (pl.PAbbr ? ':' + tnEsc(pl.PAbbr) : '') + ')</span>') : '';
			item.innerHTML = label + sub;
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				var name = pl.Persona || pl.Name || '';
				playerInput.value = name;
				playerIdEl.value  = pl.MundaneId || pl.mundane_id || 0;
				// Always auto-fill alias (user can adjust)
				var aliasEl = document.getElementById('tn-addparticipant-alias');
				if (aliasEl) { aliasEl.value = name; tnApAliasWarn(); }
				tnAcClose();
			});
			resultsEl.appendChild(item);
		});
		if (playerInput) tnFixedAcPosition(playerInput, resultsEl);
		resultsEl.classList.add('kn-ac-open');
	}

	if (playerInput && resultsEl) {
		playerInput.addEventListener('input', function() {
			var term = this.value.trim();
			playerIdEl.value = '0';
			clearTimeout(playerTimer);
			if (term.length < 2) { tnAcClose(); return; }
			playerTimer = setTimeout(function() {
				if (!(TnConfig.searchKingdomId > 0)) { tnAcClose(); return; }
				// Tiered, non-exclusionary search: same-park -> same-kingdom -> everyone.
				var url = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId
					+ '&scope=tiered'
					+ (TnConfig.parkId > 0 ? '&ParkId=' + TnConfig.parkId : '')
					+ '&q=' + encodeURIComponent(term);
				fetch(url)
					.then(function(r) { return r.json(); })
					.then(function(data) { tnAcRender(data); })
					.catch(function(err) {
						console.error('[AddParticipant] tiered search failed:', err);
						tnAcClose();
					});
			}, 280);
		});
		playerInput.addEventListener('blur', function() {
			setTimeout(tnAcClose, 200);
		});
	}

	// Long-alias advisory: ORK personas can be long; a >20-char alias renders
	// poorly in brackets/standings. Warn (non-blocking) so the marshal can shorten it.
	function tnApAliasWarn() {
		var a = document.getElementById('tn-addparticipant-alias');
		var w = document.getElementById('tn-addparticipant-alias-warn');
		if (!a || !w) return;
		w.style.display = (a.value.trim().length > 20) ? '' : 'none';
	}
	var _tnApAliasEl = document.getElementById('tn-addparticipant-alias');
	if (_tnApAliasEl) _tnApAliasEl.addEventListener('input', tnApAliasWarn);
	function tnResetAddParticipantForm() {
		document.getElementById('tn-addparticipant-alias').value       = '';
		tnApAliasWarn();
		document.getElementById('tn-addparticipant-player-text').value = '';
		document.getElementById('tn-addparticipant-player-id').value   = '0';
		tnAcClose();
		if (playerInput) { setTimeout(function() { playerInput.focus(); }, 50); }
	}

	// Sync viz on close if participants were added
	['tn-addparticipant-close','tn-addparticipant-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() {
			tnCloseModal(OVERLAY);
			tnAfterParticipantsAdded();
		});
	});

	// Submit
	var submitBtn = document.getElementById('tn-addparticipant-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn          = this;
			var alias        = document.getElementById('tn-addparticipant-alias').value.trim();
			var bracketId    = document.getElementById('tn-addparticipant-bracket-id').value;
			var tournamentId = document.getElementById('tn-addparticipant-tournament-id').value;
			var mundaneId    = document.getElementById('tn-addparticipant-player-id').value;

			if (!alias) { tnShowFeedback('tn-addparticipant-feedback', 'Alias is required.', false); return; }

			var ADD_URL = TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/addparticipant';
			btn.disabled = true;
			var fd = new FormData();
			fd.append('Alias',        alias);
			fd.append('MundaneId',    mundaneId);
			fd.append('TournamentId', tournamentId);

			fetch(ADD_URL, { method:'POST', body:fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					btn.disabled = false;
					if (d && d.status === 0) {
						_addedCount++;
						// Keep bracketData in sync (#59) — see the quick-add path.
						if (TnConfig.bracketData[bracketId]) {
							if (!TnConfig.bracketData[bracketId].Participants) TnConfig.bracketData[bracketId].Participants = [];
							TnConfig.bracketData[bracketId].Participants.push({
								ParticipantId: d.participantId || 0,
								ParticipantNumber: d.participantNumber || 0,
								Alias: alias,
								MundaneId: parseInt(mundaneId) || 0,
								Persona: alias
							});
						}
						tnShowFeedback('tn-addparticipant-feedback', 'Added! (' + _addedCount + ' so far) Keep adding, or close when done.', true);
						if (window.tnRefreshRoster) window.tnRefreshRoster();
						// Update bracket card DOM in-place
						var card = document.getElementById('tn-bracket-' + bracketId);
						if (card) {
							var emptyEl = card.querySelector('.tn-bracket-body .tn-empty');
							if (emptyEl) emptyEl.remove();
							var ul = card.querySelector('.tn-participant-list');
							if (!ul) {
								ul = document.createElement('ul');
								ul.className = 'tn-participant-list';
								var body = card.querySelector('.tn-bracket-body');
								if (body) body.insertBefore(ul, body.firstChild);
							}
							var num = ul.querySelectorAll('li').length + 1;
							var li = document.createElement('li');
							var seedSpan2 = document.createElement('span');
							seedSpan2.className = d.participantNumber ? 'tn-pid' : 'tn-participant-seed';
							seedSpan2.textContent = d.participantNumber || num; if (d.participantNumber) seedSpan2.setAttribute('data-tip', 'Player #' + d.participantNumber + ' — same number across every bracket');
							li.appendChild(seedSpan2);
							var aliasSpan2 = document.createElement('span');
							aliasSpan2.style.flex = '1';
							aliasSpan2.textContent = alias;
							li.appendChild(aliasSpan2);
							if (TnConfig.canManage) {
								var rmBtn2 = document.createElement('button');
								rmBtn2.className = 'tn-remove-participant';
								rmBtn2.setAttribute('data-pid', String(d.participantId || 0));
								rmBtn2.setAttribute('data-bid', String(bracketId));
								rmBtn2.setAttribute('data-tid', String(TnConfig.tournamentId));
								rmBtn2.setAttribute('data-tip', 'Remove participant');
								rmBtn2.innerHTML = '&times;';
								rmBtn2.addEventListener('click', function() { tnRemoveParticipant(this); });
								li.appendChild(rmBtn2);
							}
							ul.appendChild(li);
							var hdr = card.querySelector('.tn-bracket-header');
							if (hdr) { hdr.querySelectorAll('span').forEach(function(s) { if (/\d+ participant/.test(s.textContent)) s.textContent = num + ' participant' + (num !== 1 ? 's' : ''); }); }
						}
						tnResetAddParticipantForm();
					} else {
						console.error('[AddParticipant] server error:', d);
						tnShowFeedback('tn-addparticipant-feedback', (d && d.error) ? d.error : 'Failed to add participant.', false);
					}
				})
				.catch(function(err) {
					btn.disabled = false;
					console.error('[AddParticipant] fetch failed:', err);
					tnShowFeedback('tn-addparticipant-feedback', 'Request failed. Please try again.', false);
				});
		});
	}
})();
<?php endif; ?>

// ---- Participants sub-tabs (Individuals | Teams) ----
// Defined unconditionally so later tasks can call them. Toggles the two
// subpanels, the active class on the toggle buttons, and (when present) the
// swapping action buttons.
function tnParticipantsSubtab(which) {
	var isTeams = (which === 'teams');
	var pInd = document.getElementById('tn-subpanel-individuals');
	var pTeam = document.getElementById('tn-subpanel-teams');
	if (pInd)  pInd.style.display  = isTeams ? 'none' : '';
	if (pTeam) pTeam.style.display = isTeams ? '' : 'none';
	var bInd = document.getElementById('tn-subtab-individuals');
	var bTeam = document.getElementById('tn-subtab-teams');
	if (bInd)  { bInd.classList.toggle('tn-subtab-active', !isTeams); bInd.setAttribute('aria-selected', !isTeams ? 'true' : 'false'); bInd.tabIndex = isTeams ? -1 : 0; }
	if (bTeam) { bTeam.classList.toggle('tn-subtab-active', isTeams); bTeam.setAttribute('aria-selected', isTeams ? 'true' : 'false'); bTeam.tabIndex = isTeams ? 0 : -1; }
	var aInd = document.getElementById('tn-roster-action-individuals');
	var aTeam = document.getElementById('tn-roster-action-teams');
	if (aInd)  aInd.style.display  = isTeams ? 'none' : '';
	if (aTeam) aTeam.style.display = isTeams ? '' : 'none';
}

// Roving-tabindex arrow-key switching for the Individuals | Teams subtabs (mirrors the main tab bar).
function tnParticipantsSubtabKey(e) {
	var k = e.key;
	if (k !== 'ArrowRight' && k !== 'ArrowLeft' && k !== 'ArrowDown' && k !== 'ArrowUp' && k !== 'Home' && k !== 'End') return;
	var tabs = [document.getElementById('tn-subtab-individuals'), document.getElementById('tn-subtab-teams')].filter(Boolean);
	if (!tabs.length) return;
	var i = tabs.indexOf(e.target);
	if (i < 0) return;
	var next;
	if (k === 'Home') next = tabs[0];
	else if (k === 'End') next = tabs[tabs.length - 1];
	else if (k === 'ArrowRight' || k === 'ArrowDown') next = tabs[(i + 1) % tabs.length];
	else next = tabs[(i - 1 + tabs.length) % tabs.length];
	e.preventDefault();
	tnParticipantsSubtab(next === tabs[1] ? 'teams' : 'individuals');
	next.focus();
}

// tnRenderTeamsRoster() rebuilds the Teams table from TnConfig.registeredTeams.
// Defined unconditionally (mirror of tnRenderRoster) so later tasks can refresh
// the teams roster after create/edit without a full page reload.
function tnRenderTeamsRoster() {
	var wrap = document.getElementById('tn-teams-table-wrap');
	if (!wrap) return;
	var teams = TnConfig.registeredTeams || [];
	var canManage = !!TnConfig.canManage;
	var styleLabels = TnConfig.styleLabels || {};

	tnDestroyDataTable(document.getElementById('tn-teams-table'));
	if (!teams.length) {
		wrap.innerHTML = '<div class="tn-empty">No teams yet.</div>';
		return;
	}

	var html = '<table class="tn-table" id="tn-teams-table"><thead><tr>'
		+ '<th>Team</th><th>Members</th><th>Brackets</th>'
		+ (canManage ? '<th class="no-export"></th>' : '')
		+ '</tr></thead><tbody>';

	teams.forEach(function(t) {
		var tnum = parseInt(t.TeamNumber, 10) || 0;
		var members = t.Members || [];

		var nameCell = (t.Name ? tnEsc(t.Name) : '&mdash;')
			+ '<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle">'
			+ '<span class="tn-pill tn-pill-team-wl" data-tip="Team warrior level">⚔ '
			+ (parseInt(t.WarriorLevel, 10) || 0) + '</span></span>';

		var membersCell;
		if (members.length) {
			membersCell = '<button class="tn-team-roster-btn" onclick="tnToggleRoster(this)" data-tip="Show/hide team roster">&#9658; ' + members.length + '</button>'
				+ tnTeamRosterSrc(members);
		} else {
			membersCell = '<span style="color:#a0aec0">&mdash;</span>';
		}

		var bracketsCell;
		if (t.Brackets && t.Brackets.length) {
			bracketsCell = t.Brackets.map(function(b) {
				var label = styleLabels[b.BracketStyle] || b.BracketStyle || '';
				return '<span class="tn-team-chip">' + tnEsc(label) + '</span>';
			}).join('');
		} else {
			bracketsCell = '<span class="tn-reg-unassigned">Unassigned</span>';
		}

		html += '<tr data-tnum="' + tnum + '">'
			+ '<td style="font-weight:600">' + nameCell + '</td>'
			+ '<td data-order="' + members.length + '">' + membersCell + '</td>'
			+ '<td>' + bracketsCell + '</td>'
			+ (canManage ? '<td><div class="tn-team-actions" data-tnum="' + tnum + '"></div></td>' : '')
			+ '</tr>';
	});

	html += '</tbody></table>';
	wrap.innerHTML = html;
	tnRenderTeamActions();
	tnInitTeamsTable();
}

// DataTables init for the Teams table. Runs after the action cells are wired
// (paging detaches off-page rows, so they must be populated first).
function tnInitTeamsTable() {
	tnInitDataTable(document.getElementById('tn-teams-table'), {
		csvName: 'Tournament Teams',
		columnDefs: TnConfig.canManage ? [{ targets: -1, orderable: false, searchable: false }] : []
	});
}

// Populate the per-row team action cells (.tn-team-actions) with an Edit button.
// Runs after tnRenderTeamsRoster() rebuilds the table and once on page load for
// the server-rendered rows. Manager-only (cells only exist when canManage).
function tnRenderTeamActions() {
	if (!TnConfig.canManage) return;
	document.querySelectorAll('.tn-team-actions').forEach(function(cell) {
		var tnum = parseInt(cell.dataset.tnum, 10) || 0;
		if (!tnum || cell.dataset.tnWired === '1') return;
		cell.dataset.tnWired = '1';
		var btn = document.createElement('button');
		btn.className = 'tn-btn tn-btn-outline tn-btn-sm';
		btn.setAttribute('data-tip', 'Edit team');
		btn.innerHTML = '<i class="fas fa-pen"></i> Edit';
		btn.addEventListener('click', function() { tnOpenEditTeamModal(tnum); });
		cell.appendChild(btn);

		var assignBtn = document.createElement('button');
		assignBtn.className = 'tn-btn tn-btn-outline tn-btn-sm';
		assignBtn.setAttribute('data-tip', 'Assign to brackets');
		assignBtn.innerHTML = '<i class="fas fa-sitemap"></i> Assign';
		assignBtn.addEventListener('click', function() { tnOpenTeamAssignModal(tnum); });
		cell.appendChild(assignBtn);

		var removeBtn = document.createElement('button');
		removeBtn.className = 'tn-btn tn-btn-outline tn-btn-sm tn-team-act-danger';
		removeBtn.setAttribute('data-tip', 'Remove team');
		removeBtn.innerHTML = '<i class="fas fa-trash"></i> Remove';
		removeBtn.addEventListener('click', function() { tnRemoveTeam(tnum); });
		cell.appendChild(removeBtn);
	});
}

// ============================================================
// Create / Edit Team modal (tournament-level roster).
// Mirrors the per-bracket "Add Team" member-builder + kingdom-scoped
// player search, plus a "pick from registered individuals" picker.
// OVERRIDES the Task 9 stub via direct assignment (not ||=).
// ============================================================
<?php if ($canManage): ?>
(function() {
	var OVERLAY = 'tn-createteam-overlay';
	var _ctMembers = [];   // [{MundaneId, Persona}]
	var _ctTimer;

	var nameEl    = document.getElementById('tn-createteam-name');
	var numberEl  = document.getElementById('tn-createteam-number');
	var titleEl   = document.getElementById('tn-createteam-title');
	var membersEl = document.getElementById('tn-createteam-members');
	var playerInput = document.getElementById('tn-createteam-player-text');
	var resultsEl   = document.getElementById('tn-createteam-player-results');
	var submitBtn   = document.getElementById('tn-createteam-submit');

	function ctMemberHas(mid) {
		for (var i = 0; i < _ctMembers.length; i++) {
			if (_ctMembers[i].MundaneId == mid) return true;
		}
		return false;
	}

	function tnAddCreateTeamMember(mundaneId, persona) {
		mundaneId = parseInt(mundaneId, 10) || 0;
		if (mundaneId <= 0 || ctMemberHas(mundaneId)) return;
		_ctMembers.push({MundaneId: mundaneId, Persona: persona || ''});
		tnHideFeedback('tn-createteam-feedback');
		tnRenderCreateTeamMembers();
		tnRenderCreateTeamRegList();
	}

	function tnRemoveCreateTeamMember(mundaneId) {
		_ctMembers = _ctMembers.filter(function(m) { return m.MundaneId != mundaneId; });
		tnHideFeedback('tn-createteam-feedback');
		tnRenderCreateTeamMembers();
		tnRenderCreateTeamRegList();
	}

	function tnRenderCreateTeamMembers() {
		if (!membersEl) return;
		membersEl.innerHTML = '';
		if (_ctMembers.length === 0) {
			membersEl.innerHTML = '<div style="font-size:12px;color:#a0aec0;padding:4px 0">No members added yet</div>';
			return;
		}
		_ctMembers.forEach(function(m) {
			var tag = document.createElement('span');
			tag.className = 'tn-team-member-tag';
			tag.innerHTML = '<i class="fas fa-user" style="font-size:10px"></i> ' + tnEsc(m.Persona);
			var x = document.createElement('button');
			x.className = 'tn-team-member-remove';
			x.innerHTML = '&times;';
			x.setAttribute('data-tip', 'Remove');
			x.addEventListener('click', function() { tnRemoveCreateTeamMember(m.MundaneId); });
			tag.appendChild(x);
			membersEl.appendChild(tag);
		});
	}

	// Set (map) of mundane_ids already rostered on ANOTHER registration team —
	// excluding the team currently being edited (identified by the hidden
	// #tn-createteam-number field; empty for a brand-new team, so nothing is
	// excluded). Layered UX guard over the server-side validation.
	function ctOtherTeamMemberSet() {
		var editingNum = parseInt((numberEl && numberEl.value) || '0', 10) || 0;
		var set = {};
		(TnConfig.registeredTeams || []).forEach(function(t) {
			if ((parseInt(t.TeamNumber, 10) || 0) === editingNum) return; // skip the team being edited
			(t.Members || []).forEach(function(m) {
				var mid = parseInt(m.MundaneId, 10) || 0;
				if (mid > 0) set[mid] = true;
			});
		});
		return set;
	}

	// "Pick from registered individuals": render TnConfig.registrants as
	// clickable add-chips; clicking adds that registrant as a member (deduped).
	function tnRenderCreateTeamRegList() {
		var listEl = document.getElementById('tn-createteam-reglist');
		if (!listEl) return;
		listEl.innerHTML = '';
		var otherSet = ctOtherTeamMemberSet();
		var regs = (TnConfig.registrants || []).filter(function(r) {
			return (parseInt(r.MundaneId, 10) || 0) > 0 && r.Status !== 'withdrawn';
		});
		if (!regs.length) {
			listEl.innerHTML = '<div class="tn-createteam-regempty">No registered individuals to add.</div>';
			return;
		}
		regs.forEach(function(r) {
			var mid = parseInt(r.MundaneId, 10) || 0;
			var added = ctMemberHas(mid);
			var onOther = !added && !!otherSet[mid];
			var chip = document.createElement('span');
			chip.className = 'tn-createteam-regchip'
				+ (added ? ' tn-createteam-regchip-added' : '')
				+ (onOther ? ' tn-createteam-regchip-added' : '');
			var label = r.Persona || r.Alias || '—';
			chip.innerHTML = '<i class="fas fa-' + (added ? 'check' : (onOther ? 'ban' : 'plus')) + '"></i>' + tnEsc(label);
			if (onOther) {
				chip.setAttribute('data-tip', 'Already on another team');
				chip.style.cursor = 'not-allowed';
			} else if (!added) {
				chip.addEventListener('click', function() {
					tnAddCreateTeamMember(mid, r.Persona || r.Alias || '');
				});
			}
			listEl.appendChild(chip);
		});
	}

	// ---- kingdom-scoped player search (mirror Add Team exactly) ----
	function ctAcClose() {
		if (!resultsEl) return;
		resultsEl.classList.remove('kn-ac-open');
		resultsEl.innerHTML = '';
	}

	function ctAcRender(players) {
		resultsEl.innerHTML = '';
		var otherSet = ctOtherTeamMemberSet();
		var filtered = (players || []).filter(function(pl) {
			var mid = pl.MundaneId || pl.mundane_id || 0;
			return mid > 0 && !ctMemberHas(mid) && !otherSet[mid];
		});
		if (!filtered.length) {
			resultsEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No players found</div>';
			if (playerInput) tnFixedAcPosition(playerInput, resultsEl);
			resultsEl.classList.add('kn-ac-open');
			return;
		}
		filtered.forEach(function(pl) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			var label = tnEsc(pl.Persona || pl.Name || '');
			var sub   = pl.KAbbr ? (' <span style="color:#a0aec0;font-size:11px">(' + tnEsc(pl.KAbbr) + (pl.PAbbr ? ':' + tnEsc(pl.PAbbr) : '') + ')</span>') : '';
			item.innerHTML = label + sub;
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				var mid  = pl.MundaneId || pl.mundane_id || 0;
				var name = pl.Persona || pl.Name || '';
				tnAddCreateTeamMember(mid, name);
				playerInput.value = '';
				ctAcClose();
				playerInput.focus();
			});
			resultsEl.appendChild(item);
		});
		if (playerInput) tnFixedAcPosition(playerInput, resultsEl);
		resultsEl.classList.add('kn-ac-open');
	}

	if (playerInput && resultsEl) {
		playerInput.addEventListener('input', function() {
			var term = this.value.trim();
			clearTimeout(_ctTimer);
			if (term.length < 2) { ctAcClose(); return; }
			_ctTimer = setTimeout(function() {
				if (!(TnConfig.searchKingdomId > 0)) { ctAcClose(); return; }
				fetch(TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId + '&q=' + encodeURIComponent(term))
					.then(function(r) { return r.json(); })
					.then(function(data) { ctAcRender(data); })
					.catch(function() { ctAcClose(); });
			}, 280);
		});
		playerInput.addEventListener('blur', function() { setTimeout(ctAcClose, 200); });
	}

	function ctReset() {
		_ctMembers = [];
		if (numberEl)  numberEl.value = '';
		if (nameEl)    nameEl.value = '';
		if (playerInput) playerInput.value = '';
		tnHideFeedback('tn-createteam-feedback');
		ctAcClose();
		tnRenderCreateTeamMembers();
		tnRenderCreateTeamRegList();
		if (submitBtn) submitBtn.disabled = false;
	}

	window.tnOpenCreateTeamModal = function() {
		ctReset();
		if (titleEl) titleEl.innerHTML = '<i class="fas fa-users" style="margin-right:8px;color:#3182ce"></i>Create Team';
		tnOpenAsSheet(OVERLAY, {});
		setTimeout(function() { if (nameEl) nameEl.focus(); }, 50);
	};

	window.tnOpenEditTeamModal = function(teamNumber) {
		teamNumber = parseInt(teamNumber, 10) || 0;
		ctReset();
		var team = null;
		(TnConfig.registeredTeams || []).forEach(function(t) {
			if ((parseInt(t.TeamNumber, 10) || 0) === teamNumber) team = t;
		});
		if (!team) { return; }
		if (numberEl) numberEl.value = teamNumber;
		if (nameEl)   nameEl.value = team.Name || '';
		_ctMembers = (team.Members || []).map(function(m) {
			return {MundaneId: parseInt(m.MundaneId, 10) || 0, Persona: m.Persona || ''};
		}).filter(function(m) { return m.MundaneId > 0; });
		if (titleEl) titleEl.innerHTML = '<i class="fas fa-users" style="margin-right:8px;color:#3182ce"></i>Edit Team';
		tnRenderCreateTeamMembers();
		tnRenderCreateTeamRegList();
		tnOpenAsSheet(OVERLAY, {});
		setTimeout(function() { if (nameEl) nameEl.focus(); }, 50);
	};

	function tnSubmitTeam() {
		var name = (nameEl ? nameEl.value.trim() : '');
		var teamNumber = parseInt(numberEl ? numberEl.value : '', 10) || 0;
		if (!name) { tnShowFeedback('tn-createteam-feedback', 'Team name is required.', false); return; }
		if (_ctMembers.length === 0) { tnShowFeedback('tn-createteam-feedback', 'Add at least one member to the team.', false); return; }

		if (submitBtn) submitBtn.disabled = true;
		var fd = new FormData();
		fd.append('Name', name);
		fd.append('Members', JSON.stringify(_ctMembers.map(function(m) { return {MundaneId: m.MundaneId}; })));
		var action = teamNumber > 0 ? 'updateteam' : 'createteam';
		if (teamNumber > 0) fd.append('TeamNumber', teamNumber);

		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/' + action, {method:'POST', body:fd})
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (submitBtn) submitBtn.disabled = false;
				if (d && d.status === 0) {
					var msg = teamNumber > 0
						? ('Team "' + name + '" updated.' + (d.RosterLocked ? ' (Roster locked — only the name was changed.)' : ''))
						: ('Team "' + name + '" created.');
					// Re-fetch the teams roster and re-render in place.
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/registeredteams')
						.then(function(r) { return r.json(); })
						.then(function(resp) {
							if (resp && resp.status === 0 && resp.teams) {
								TnConfig.registeredTeams = resp.teams;
								tnRenderTeamsRoster();
							}
							tnCloseModal(OVERLAY);
							// The modal's own feedback is hidden once it closes — confirm on the page.
							if (window.tnToast) window.tnToast(msg);
						})
						.catch(function() {
							tnCloseModal(OVERLAY);
							window.location.reload();
						});
				} else {
					tnShowFeedback('tn-createteam-feedback', (d && d.error) ? d.error : 'Failed to save team.', false);
				}
			})
			.catch(function() {
				if (submitBtn) submitBtn.disabled = false;
				tnShowFeedback('tn-createteam-feedback', 'Request failed.', false);
			});
	}

	if (submitBtn) submitBtn.addEventListener('click', tnSubmitTeam);

	// Close / cancel / overlay-click / Escape
	var ov = document.getElementById(OVERLAY);
	if (ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });
	}
	['tn-createteam-close','tn-createteam-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});

	// Wire the server-rendered team rows' Edit buttons on load.
	tnRenderTeamActions();
})();
<?php else: ?>
window.tnOpenCreateTeamModal = function() {};
window.tnOpenEditTeamModal   = function() {};
<?php endif; ?>

// ---- Participants Roster: render + Register modal ----
// tnRefreshRoster() re-fetches the roster and re-renders it. Every bracket add
// auto-registers the player server-side, so add paths (and peers' changes via
// collab) call this to keep the Participants tab current without a reload.
// Calls within 400ms coalesce into one fetch (e.g. typing a quick bracket).
window.tnRefreshRoster = (function() {
	var timer = null;
	return function() {
		if (!TnConfig.canManage) return; // the registrants endpoint needs a manager session
		clearTimeout(timer);
		timer = setTimeout(function() {
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/registrants')
				.then(function(r) { return r.json(); })
				.then(function(rd) {
					if (rd && rd.status === 0) {
						TnConfig.registrants = rd.registrants || [];
						tnRenderRoster();
					}
				})
				.catch(function() {});
		}, 400);
	};
})();

// tnRenderRoster() rebuilds the Participants table from TnConfig.registrants.
// Defined unconditionally so later tasks (assign-to-bracket, bulk modal) can
// call it to refresh the roster without a full page reload.
function tnRenderRoster() {
	var wrap = document.getElementById('tn-roster-table-wrap');
	if (!wrap) return;
	var regs = TnConfig.registrants || [];
	var canManage = !!TnConfig.canManage;
	var styleLabels = TnConfig.styleLabels || {};

	// Keep the header stat and the Participants tab count in step with the roster.
	// Same rule as the controller's TotalParticipants: distinct people by MundaneId,
	// falling back to the lower-cased alias for alias-only entries.
	var seen = {}, regCount = 0;
	regs.forEach(function(r) {
		var mid = parseInt(r.MundaneId, 10) || 0;
		var key = mid > 0 ? 'mid:' + mid : 'alias:' + String(r.Alias || '').trim().toLowerCase();
		if (!seen[key]) { seen[key] = true; regCount++; }
	});
	var statEl = document.getElementById('tn-stat-participants');
	if (statEl) statEl.textContent = regCount;
	var tabCount = document.querySelector('#tn-tabhdr-participants .tn-tab-count');
	if (tabCount) tabCount.textContent = '(' + regCount + ')';

	tnDestroyDataTable(document.getElementById('tn-roster-table'));
	if (!regs.length) {
		wrap.innerHTML = '<div class="tn-empty">No participants registered yet.</div>';
		return;
	}

	var html = '<table class="tn-table" id="tn-roster-table"><thead><tr>'
		+ '<th>Alias</th><th>Player</th><th>Park</th><th>Warriors</th><th>Brackets</th>'
		+ (canManage ? '<th class="no-export"></th>' : '')
		+ '</tr></thead><tbody>';

	regs.forEach(function(r) {
		var pnum = parseInt(r.ParticipantNumber, 10) || 0;
		var withdrawn = (r.Status === 'withdrawn');

		// Warrior/award pills (mirror PHP tnParticipantPills)
		var pills = '';
		var wc = parseInt(r.WarriorCount, 10) || 0;
		if (wc > 0) {
			var wcShow = Math.min(wc, 10);
			pills += '<span class="tn-pill tn-pill-warrior" data-tip="Order of the Warrior x' + wc + '">' + wcShow + '</span>';
		}
		if (r.IsWarlord) pills += '<span class="tn-pill tn-pill-warlord" data-tip="Warlord">W</span>';
		if (r.IsKnightSword) pills += '<span class="tn-pill tn-pill-knight" data-tip="Knight of the Sword">K</span>';
		var pillsCell = pills
			? '<span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle">' + pills + '</span>'
			: '<span style="color:#a0aec0">&mdash;</span>';

		// Player cell
		var mid = parseInt(r.MundaneId, 10) || 0;
		var playerCell;
		if (r.Persona && mid > 0) {
			playerCell = '<a href="' + TnConfig.uir + 'Player/profile/' + mid + '" class="tn-lb-link">' + tnEsc(r.Persona) + '</a>';
		} else if (r.Persona) {
			playerCell = tnEsc(r.Persona);
		} else {
			playerCell = '<span style="color:#a0aec0">&mdash;</span>';
		}

		// Brackets cell
		var bracketsCell;
		if (r.Brackets && r.Brackets.length) {
			bracketsCell = r.Brackets.map(function(b) {
				var label = styleLabels[b.BracketStyle] || b.BracketStyle || '';
				return '<span class="tn-reg-chip">' + tnEsc(label) + '</span>';
			}).join('');
		} else {
			bracketsCell = '<span class="tn-reg-unassigned">Unassigned</span>';
		}

		var aliasCell = (r.Alias ? tnEsc(r.Alias) : '&mdash;')
			+ (withdrawn ? ' <span class="tn-reg-wd-badge">Withdrawn</span>' : '');

		html += '<tr data-pnum="' + pnum + '"' + (withdrawn ? ' class="tn-reg-withdrawn"' : '') + '>'
			+ '<td style="font-weight:600">' + aliasCell + '</td>'
			+ '<td>' + playerCell + '</td>'
			+ '<td class="tn-lb-muted">' + (r.ParkName ? tnEsc(r.ParkName) : '&mdash;') + '</td>'
			+ '<td>' + pillsCell + '</td>'
			+ '<td>' + bracketsCell + '</td>'
			+ (canManage ? '<td>' + tnRegActionsHtml(r) + '</td>' : '')
			+ '</tr>';
	});

	html += '</tbody></table>';
	wrap.innerHTML = html;
	tnInitRosterTable();
}

// DataTables init for the Participants roster. Runs after the action cells are
// populated (paging detaches off-page rows, so they must be filled first).
function tnInitRosterTable() {
	tnInitDataTable(document.getElementById('tn-roster-table'), {
		csvName: 'Tournament Participants',
		columnDefs: TnConfig.canManage ? [{ targets: -1, orderable: false, searchable: false }] : []
	});
}

<?php if ($canManage): ?>
(function() {
	var OVERLAY = 'tn-register-overlay';
	var playerTimer;

	var playerInput = document.getElementById('tn-register-player-text');
	var playerIdEl  = document.getElementById('tn-register-player-id');
	var resultsEl   = document.getElementById('tn-register-player-results');

	function regAcClose() {
		if (!resultsEl) return;
		clearTimeout(playerTimer);
		resultsEl.classList.remove('kn-ac-open');
		resultsEl.innerHTML = '';
	}

	// Anchor under the input and cap the height so the list stops above the
	// modal footer (it must never cover Cancel/Register). Also used by the
	// tnFixedAcPosition re-anchor loop via _tnAcPlace.
	function regAcPlace() {
		if (!playerInput || !resultsEl) return;
		var r = playerInput.getBoundingClientRect();
		var top = r.bottom + 4;
		resultsEl.style.left  = r.left + 'px';
		resultsEl.style.width = r.width + 'px';
		resultsEl.style.top   = top + 'px';
		var foot = document.querySelector('#' + OVERLAY + ' .tn-modal-footer');
		var limit = foot ? foot.getBoundingClientRect().top : window.innerHeight;
		var room = Math.max(60, Math.floor(limit - top - 6));
		// Never grow past the stylesheet cap (220px / 150px / mobile min(50vh,320px)).
		resultsEl.style.maxHeight = '';
		var cssCap = parseFloat(getComputedStyle(resultsEl).maxHeight);
		resultsEl.style.maxHeight = (cssCap > 0 ? Math.min(room, cssCap) : room) + 'px';
		resultsEl.style.overflowY = 'auto';
	}
	if (resultsEl) {
		resultsEl._tnAcPlace = regAcPlace;
		// Pressing the list's scrollbar/padding must not blur the input (the blur-close would shut it mid-scroll).
		resultsEl.addEventListener('mousedown', function(e) { e.preventDefault(); });
	}

	function regAcRender(players) {
		resultsEl.innerHTML = '';
		if (!players || !players.length) {
			resultsEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No players found</div>';
			if (playerInput) { tnFixedAcPosition(playerInput, resultsEl); regAcPlace(); }
			resultsEl.classList.add('kn-ac-open');
			return;
		}
		players.forEach(function(pl) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			var label = tnEsc(pl.Persona || pl.Name || '');
			var sub   = pl.KAbbr ? (' <span style="color:#a0aec0;font-size:11px">(' + tnEsc(pl.KAbbr) + (pl.PAbbr ? ':' + tnEsc(pl.PAbbr) : '') + ')</span>') : '';
			item.innerHTML = label + sub;
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				var name = pl.Persona || pl.Name || '';
				playerInput.value = name;
				playerIdEl.value  = pl.MundaneId || pl.mundane_id || 0;
				var aliasEl = document.getElementById('tn-register-alias');
				if (aliasEl) aliasEl.value = name;
				regAcClose();
			});
			resultsEl.appendChild(item);
		});
		if (playerInput) { tnFixedAcPosition(playerInput, resultsEl); regAcPlace(); }
		resultsEl.classList.add('kn-ac-open');
	}

	if (playerInput && resultsEl) {
		playerInput.addEventListener('input', function() {
			var term = this.value.trim();
			playerIdEl.value = '0';
			clearTimeout(playerTimer);
			if (term.length < 2) { regAcClose(); return; }
			playerTimer = setTimeout(function() {
				if (!(TnConfig.searchKingdomId > 0)) { regAcClose(); return; }
				var url = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId + '&q=' + encodeURIComponent(term);
				fetch(url)
					.then(function(r) { return r.json(); })
					.then(function(data) {
						// A response landing after blur/Esc must not re-open the list over the footer.
						if (document.activeElement !== playerInput) return;
						regAcRender(data);
					})
					.catch(function(err) { console.error('[Register] kingdom search failed:', err); regAcClose(); });
			}, 280);
		});
		playerInput.addEventListener('blur', function() {
			setTimeout(regAcClose, 200);
		});
		// Esc closes just the dropdown; preventDefault keeps the modal's Esc handler from closing the modal.
		playerInput.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.key === 'Esc') && resultsEl.classList.contains('kn-ac-open')) {
				e.preventDefault();
				e.stopPropagation();
				regAcClose();
			}
		});
		// Close on any pointerdown outside the input/dropdown before the click lands.
		// Item selection runs on the item's own mousedown, so presses inside the list are left alone.
		document.addEventListener('pointerdown', function(e) {
			if (!resultsEl.classList.contains('kn-ac-open')) return;
			if (resultsEl.contains(e.target) || e.target === playerInput) return;
			regAcClose();
		}, true);
	}

	window.tnOpenRegisterModal = function() {
		if (playerInput) playerInput.value = '';
		if (playerIdEl)  playerIdEl.value = '0';
		var aliasEl = document.getElementById('tn-register-alias');
		if (aliasEl) aliasEl.value = '';
		regAcClose();
		tnHideFeedback('tn-register-feedback');
		tnOpenAsSheet(OVERLAY, {});
		if (playerInput) setTimeout(function() { playerInput.focus(); }, 50);
	};

	['tn-register-close', 'tn-register-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	window.tnSubmitRegister = function() {
		var btn      = document.getElementById('tn-register-submit');
		var alias    = (document.getElementById('tn-register-alias').value || '').trim();
		var mundaneId = document.getElementById('tn-register-player-id').value || '0';

		// Require at least one of alias / selected player
		if (!alias && (!mundaneId || mundaneId === '0')) {
			tnShowFeedback('tn-register-feedback', 'Enter an alias or search and select a player.', false);
			return;
		}

		var REG_URL = TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/register';
		btn.disabled = true;
		var fd = new FormData();
		fd.append('Alias',     alias);
		fd.append('MundaneId', mundaneId);

		fetch(REG_URL, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				btn.disabled = false;
				if (d && d.status === 0 && d.alreadyRegistered) {
					// Nothing changed: keep the modal open so the duplicate notice is seen.
					tnShowFeedback('tn-register-feedback', 'Already registered (#' + (parseInt(d.participantNumber, 10) || 0) + ')', false);
				} else if (d && d.status === 0) {
					tnShowFeedback('tn-register-feedback', 'Registered!', true);
					// Re-fetch the roster, update TnConfig, re-render the table.
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/registrants')
						.then(function(r) { return r.json(); })
						.then(function(rd) {
							if (rd && rd.status === 0) {
								TnConfig.registrants = rd.registrants || [];
								tnRenderRoster();
							}
							tnCloseModal(OVERLAY);
						})
						.catch(function(err) {
							console.error('[Register] roster refresh failed:', err);
							window.location.reload();
						});
				} else {
					console.error('[Register] server error:', d);
					tnShowFeedback('tn-register-feedback', (d && d.error) ? d.error : 'Failed to register participant.', false);
				}
			})
			.catch(function(err) {
				btn.disabled = false;
				console.error('[Register] fetch failed:', err);
				tnShowFeedback('tn-register-feedback', 'Request failed. Please try again.', false);
			});
	};

	var submitBtn = document.getElementById('tn-register-submit');
	if (submitBtn) submitBtn.addEventListener('click', tnSubmitRegister);
})();

// ============================================================
// Per-registrant roster actions (Assign to brackets / Withdraw /
// Reactivate / Remove). Buttons are emitted by tnRenderRoster()
// via tnRegActionsHtml(r) so they survive a roster refresh.
// Edit-alias is intentionally NOT offered here: a tournament-level
// registrant has no single bracket_id, and the updatealias endpoint
// is per-bracket (needs data-pid/data-bid). Alias edits remain on the
// per-bracket participant lists. (Task 11)
// ============================================================
(function() {
	var ASSIGN_OVERLAY = 'tn-assign-overlay';

	// Find a registrant object in TnConfig.registrants by ParticipantNumber.
	function regByPnum(pnum) {
		pnum = parseInt(pnum, 10) || 0;
		var regs = TnConfig.registrants || [];
		for (var i = 0; i < regs.length; i++) {
			if ((parseInt(regs[i].ParticipantNumber, 10) || 0) === pnum) return regs[i];
		}
		return null;
	}

	// Build the action-button HTML for one registrant row. Exposed globally so
	// tnRenderRoster() can call it; also used by the on-load population pass for
	// the server-rendered (empty) .tn-reg-actions cells.
	window.tnRegActionsHtml = function(r) {
		var pnum = parseInt(r.ParticipantNumber, 10) || 0;
		var withdrawn = (r.Status === 'withdrawn');
		var html = '<div class="tn-reg-actions" data-pnum="' + pnum + '">';
		html += '<button type="button" class="tn-reg-act-btn" data-tip="Assign to brackets" aria-label="Assign to brackets" '
			+ 'onclick="tnOpenAssignModal(' + pnum + ')"><i class="fas fa-sitemap"></i></button>';
		if (withdrawn) {
			html += '<button type="button" class="tn-reg-act-btn tn-reg-act-reactivate" data-tip="Reactivate" aria-label="Reactivate" '
				+ 'onclick="tnToggleRegStatus(' + pnum + ', this)"><i class="fas fa-undo"></i></button>';
		} else {
			html += '<button type="button" class="tn-reg-act-btn tn-reg-act-wd" data-tip="Withdraw" aria-label="Withdraw" '
				+ 'onclick="tnToggleRegStatus(' + pnum + ', this)"><i class="fas fa-user-slash"></i></button>';
		}
		html += '<button type="button" class="tn-reg-act-btn tn-reg-act-danger" data-tip="Remove" aria-label="Remove" '
			+ 'onclick="tnRemoveRegistrant(' + pnum + ')"><i class="fas fa-trash"></i></button>';
		html += '</div>';
		return html;
	};

	// Populate the server-rendered (initially empty) .tn-reg-actions cells once on
	// load. After this, refreshes go through tnRenderRoster() which emits buttons.
	function populateInitialActions() {
		document.querySelectorAll('#tn-roster-table-wrap .tn-reg-actions').forEach(function(cell) {
			if (cell.children.length) return; // already populated
			var r = regByPnum(cell.getAttribute('data-pnum'));
			if (!r) return;
			cell.outerHTML = window.tnRegActionsHtml(r);
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', populateInitialActions);
	} else {
		populateInitialActions();
	}

	// Re-fetch the roster from the server, update TnConfig, re-render. Returns a
	// promise so callers can chain UI updates (close modal, etc.).
	function refreshRoster() {
		return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/registrants')
			.then(function(r) { return r.json(); })
			.then(function(rd) {
				if (rd && rd.status === 0) {
					TnConfig.registrants = rd.registrants || [];
					tnRenderRoster();
				}
				return rd;
			});
	}

	// ---- Assign to brackets modal ----
	var _assignPnum = 0;

	window.tnOpenAssignModal = function(pnum) {
		_assignPnum = parseInt(pnum, 10) || 0;
		var r = regByPnum(_assignPnum);
		if (!r) return;
		tnHideFeedback('tn-assign-feedback');

		var sub = document.getElementById('tn-assign-subtitle');
		if (sub) sub.innerHTML = 'Assigning <strong>' + tnEsc(r.Alias || ('#' + _assignPnum)) + '</strong> to brackets.';

		// Set of bracket ids this registrant is currently in.
		var current = {};
		(r.Brackets || []).forEach(function(b) { current[parseInt(b.BracketId, 10) || 0] = true; });

		var bd = TnConfig.bracketData || {};
		var rows = '';
		Object.keys(bd).forEach(function(key) {
			var br = (bd[key] && bd[key].Bracket) ? bd[key].Bracket : null;
			if (!br) return;
			var bid    = parseInt(br.BracketId, 10) || 0;
			var status = br.Status || 'setup';
			var isTeam = (br.Participants === 'team');
			var label  = tnBracketLabel(br) || ('Bracket #' + bid);
			var checked = !!current[bid];

			// Team brackets are shown DISABLED (assignment here is individual-only).
			// Non-setup individual brackets are shown DISABLED (participants locked).
			var disabled = isTeam || (status !== 'setup');
			var tip = '';
			var meta = '';
			if (isTeam) {
				tip = 'Assignment is for individual brackets.';
				meta = '<span class="tn-assign-meta">Team</span>';
			} else if (status !== 'setup') {
				tip = 'Bracket has started — participants are locked.';
				meta = '<span class="tn-assign-meta tn-assign-meta-locked">' + tnEsc(status) + '</span>';
			} else {
				meta = '<span class="tn-assign-meta">Setup</span>';
			}

			rows += '<div class="tn-assign-row' + (disabled ? ' tn-assign-disabled' : '') + '"'
				+ (tip ? ' data-tip="' + tnEsc(tip) + '"' : '') + '>'
				+ '<input type="checkbox" id="tn-assign-cb-' + bid + '" data-bid="' + bid + '" '
				+ 'data-was="' + (checked ? '1' : '0') + '"'
				+ (checked ? ' checked' : '') + (disabled ? ' disabled' : '') + '>'
				+ '<label for="tn-assign-cb-' + bid + '">' + tnEsc(label) + '</label>'
				+ meta
				+ '</div>';
		});

		var list = document.getElementById('tn-assign-list');
		if (list) list.innerHTML = rows || '<div class="tn-assign-empty">No brackets in this tournament yet.</div>';

		tnOpenAsSheet(ASSIGN_OVERLAY, {});
	};

	window.tnSubmitAssign = function() {
		var btn = document.getElementById('tn-assign-submit');
		var pnum = _assignPnum;
		var list = document.getElementById('tn-assign-list');
		if (!list) return;

		// Diff checked-vs-was over enabled (setup individual) checkboxes only.
		var toAssign = [], toUnassign = [];
		list.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
			if (cb.disabled) return;
			var bid = parseInt(cb.getAttribute('data-bid'), 10) || 0;
			var was = cb.getAttribute('data-was') === '1';
			if (cb.checked && !was) toAssign.push(bid);
			else if (!cb.checked && was) toUnassign.push(bid);
		});

		if (!toAssign.length && !toUnassign.length) {
			tnCloseModal(ASSIGN_OVERLAY);
			return;
		}

		btn.disabled = true;

		function callBracket(action, bid) {
			var fd = new FormData();
			fd.append('TournamentId', TnConfig.tournamentId);
			fd.append('ParticipantNumbers', JSON.stringify([pnum]));
			return fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/' + action, { method: 'POST', body: fd })
				.then(function(res) { return res.json(); })
				.then(function(d) { return { bid: bid, action: action, d: d }; });
		}

		var ops = [];
		toAssign.forEach(function(bid)   { ops.push(['assign', bid]); });
		toUnassign.forEach(function(bid) { ops.push(['unassign', bid]); });

		// Run sequentially so one failure doesn't race the others.
		var errors = [];
		var chain = Promise.resolve();
		ops.forEach(function(op) {
			chain = chain.then(function() {
				return callBracket(op[0], op[1]).then(function(r) {
					if (!r.d || r.d.status !== 0) {
						errors.push((r.d && r.d.error) ? r.d.error : ('Failed to ' + r.action + ' bracket #' + r.bid));
					}
				});
			});
		});

		chain.then(function() {
			// Keep the touched Brackets-tab cards (participant list, counts) current too.
			if (window.tnRefreshBracketCard) window.tnRefreshBracketCard(ops.map(function(op) { return op[1]; }));
			return refreshRoster();
		}).then(function() {
			btn.disabled = false;
			if (errors.length) {
				tnShowFeedback('tn-assign-feedback', errors.join(' '), false);
			} else {
				tnCloseModal(ASSIGN_OVERLAY);
			}
		}).catch(function(err) {
			console.error('[Assign] failed:', err);
			btn.disabled = false;
			tnShowFeedback('tn-assign-feedback', 'Request failed. Please try again.', false);
		});
	};

	['tn-assign-close', 'tn-assign-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(ASSIGN_OVERLAY); });
	});
	var assignOv = document.getElementById(ASSIGN_OVERLAY);
	if (assignOv) assignOv.addEventListener('click', function(e) { if (e.target === assignOv) tnCloseModal(ASSIGN_OVERLAY); });
	var assignSubmit = document.getElementById('tn-assign-submit');
	if (assignSubmit) assignSubmit.addEventListener('click', tnSubmitAssign);

	// ---- Withdraw / Reactivate toggle ----
	window.tnToggleRegStatus = function(pnum, btn) {
		var r = regByPnum(pnum);
		if (!r) return;
		var newStatus = (r.Status === 'withdrawn') ? 'active' : 'withdrawn';
		if (btn) btn.disabled = true;
		var fd = new FormData();
		fd.append('ParticipantNumber', pnum);
		fd.append('Status', newStatus);
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/registrationstatus', { method: 'POST', body: fd })
			.then(function(res) { return res.json(); })
			.then(function(d) {
				if (d && d.status === 0) {
					r.Status = newStatus;
					tnRenderRoster();
				} else {
					if (btn) btn.disabled = false;
					console.error('[RegStatus] server error:', d);
				}
			})
			.catch(function(err) {
				if (btn) btn.disabled = false;
				console.error('[RegStatus] fetch failed:', err);
			});
	};

	// ---- Remove registrant ----
	window.tnRemoveRegistrant = function(pnum) {
		var r = regByPnum(pnum);
		if (!r) return;
		var name = r.Alias || ('participant #' + pnum);
		tnConfirm({
			danger: true,
			title: 'Remove registrant',
			body: 'Remove <strong>' + tnEsc(name) + '</strong> from this tournament? This also removes them from any setup brackets they are in.',
			confirmLabel: 'Remove',
			cancelLabel: 'Cancel',
			onConfirm: function() {
				var fd = new FormData();
				fd.append('ParticipantNumber', pnum);
				fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/removeregistrant', { method: 'POST', body: fd })
					.then(function(res) { return res.json(); })
					.then(function(d) {
						if (d && d.status === 0) {
							TnConfig.registrants = (TnConfig.registrants || []).filter(function(x) {
								return (parseInt(x.ParticipantNumber, 10) || 0) !== (parseInt(pnum, 10) || 0);
							});
							tnRenderRoster();
						} else {
							// Surface server error (e.g. in a started bracket) without removing.
							tnConfirm({
								title: 'Cannot remove',
								body: tnEsc((d && d.error) ? d.error : 'This participant could not be removed.'),
								confirmLabel: 'OK',
								alertOnly: true
							});
						}
					})
					.catch(function(err) {
						console.error('[RemoveRegistrant] fetch failed:', err);
						tnConfirm({ title: 'Error', body: 'Request failed. Please try again.', confirmLabel: 'OK', alertOnly: true });
					});
			}
		});
	};
})();
<?php endif; ?>

// ============================================================
// Team roster actions: assign-to-brackets modal + remove team.
// Mirrors the individual per-registrant assign IIFE above, but
// filters to TEAM brackets and uses the team assign/unassign and
// removeteam endpoints. Manager-only.
// ============================================================
<?php if ($canManage): ?>
(function() {
	var TEAMASSIGN_OVERLAY = 'tn-teamassign-overlay';
	var _teamAssignTnum = 0;

	// Find a team object in TnConfig.registeredTeams by TeamNumber.
	function teamByTnum(tnum) {
		tnum = parseInt(tnum, 10) || 0;
		var teams = TnConfig.registeredTeams || [];
		for (var i = 0; i < teams.length; i++) {
			if ((parseInt(teams[i].TeamNumber, 10) || 0) === tnum) return teams[i];
		}
		return null;
	}

	// Re-fetch the registered teams from the server, update TnConfig, re-render.
	function refreshTeams() {
		return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/registeredteams')
			.then(function(r) { return r.json(); })
			.then(function(rd) {
				if (rd && rd.status === 0) {
					TnConfig.registeredTeams = rd.teams || [];
					tnRenderTeamsRoster();
					tnRenderTeamActions();
				}
				return rd;
			});
	}

	// ---- Assign team to brackets modal ----
	window.tnOpenTeamAssignModal = function(tnum) {
		_teamAssignTnum = parseInt(tnum, 10) || 0;
		var t = teamByTnum(_teamAssignTnum);
		if (!t) return;
		tnHideFeedback('tn-teamassign-feedback');

		var sub = document.getElementById('tn-teamassign-subtitle');
		if (sub) sub.innerHTML = 'Assigning <strong>' + tnEsc(t.Name || ('Team #' + _teamAssignTnum)) + '</strong> to team brackets.';

		// Set of bracket ids this team is currently in.
		var current = {};
		(t.Brackets || []).forEach(function(b) { current[parseInt(b.BracketId, 10) || 0] = true; });

		var bd = TnConfig.bracketData || {};
		var rows = '';
		Object.keys(bd).forEach(function(key) {
			var br = (bd[key] && bd[key].Bracket) ? bd[key].Bracket : null;
			if (!br) return;
			if (br.Participants !== 'team') return; // team brackets only
			var bid    = parseInt(br.BracketId, 10) || 0;
			var status = br.Status || 'setup';
			var label  = tnBracketLabel(br) || ('Bracket #' + bid);
			var checked = !!current[bid];

			// Only SETUP team brackets are toggleable; others shown DISABLED.
			var disabled = (status !== 'setup');
			var tip = '';
			var meta = '';
			if (disabled) {
				tip = 'Teams are locked once the bracket starts.';
				meta = '<span class="tn-assign-meta tn-assign-meta-locked">' + tnEsc(status) + '</span>';
			} else {
				meta = '<span class="tn-assign-meta">Setup</span>';
			}

			rows += '<div class="tn-assign-row' + (disabled ? ' tn-assign-disabled' : '') + '"'
				+ (tip ? ' data-tip="' + tnEsc(tip) + '"' : '') + '>'
				+ '<input type="checkbox" id="tn-teamassign-cb-' + bid + '" data-bid="' + bid + '" '
				+ 'data-was="' + (checked ? '1' : '0') + '"'
				+ (checked ? ' checked' : '') + (disabled ? ' disabled' : '') + '>'
				+ '<label for="tn-teamassign-cb-' + bid + '">' + tnEsc(label) + '</label>'
				+ meta
				+ '</div>';
		});

		var list = document.getElementById('tn-teamassign-list');
		if (list) list.innerHTML = rows || '<div class="tn-assign-empty">No team brackets in this tournament yet.</div>';

		tnOpenAsSheet(TEAMASSIGN_OVERLAY, {});
	};

	window.tnSubmitTeamAssign = function(tnum) {
		var btn = document.getElementById('tn-teamassign-submit');
		var tn = parseInt(tnum, 10) || _teamAssignTnum;
		var list = document.getElementById('tn-teamassign-list');
		if (!list) return;

		// Diff checked-vs-was over enabled (setup team) checkboxes only.
		var toAssign = [], toUnassign = [];
		list.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
			if (cb.disabled) return;
			var bid = parseInt(cb.getAttribute('data-bid'), 10) || 0;
			var was = cb.getAttribute('data-was') === '1';
			if (cb.checked && !was) toAssign.push(bid);
			else if (!cb.checked && was) toUnassign.push(bid);
		});

		if (!toAssign.length && !toUnassign.length) {
			tnCloseModal(TEAMASSIGN_OVERLAY);
			return;
		}

		btn.disabled = true;

		// assign/unassign are PER-BRACKET endpoints (bid in URL), so loop brackets.
		function callBracket(action, bid) {
			var fd = new FormData();
			fd.append('TournamentId', TnConfig.tournamentId);
			fd.append('TeamNumbers', JSON.stringify([tn]));
			return fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/' + action, { method: 'POST', body: fd })
				.then(function(res) { return res.json(); })
				.then(function(d) { return { bid: bid, action: action, d: d }; });
		}

		var ops = [];
		toAssign.forEach(function(bid)   { ops.push(callBracket('assignteams', bid)); });
		toUnassign.forEach(function(bid) { ops.push(callBracket('unassignteams', bid)); });

		Promise.all(ops).then(function(results) {
			var errors = [];
			results.forEach(function(r) {
				if (!r.d || r.d.status !== 0) {
					errors.push((r.d && r.d.error) ? r.d.error : ('Failed to ' + r.action + ' bracket #' + r.bid));
				}
			});
			if (errors.length) {
				btn.disabled = false;
				tnShowFeedback('tn-teamassign-feedback', errors.join(' '), false);
				return;
			}
			// All succeeded. A full reload rebuilds roster + bracket cards, so skip the
			// interim refreshTeams() the reload would immediately discard (#58).
			tnShowFeedback('tn-teamassign-feedback', 'Saved.', true);
			setTimeout(function() { window.location.reload(); }, 600);
		}).catch(function(err) {
			console.error('[TeamAssign] failed:', err);
			btn.disabled = false;
			tnShowFeedback('tn-teamassign-feedback', 'Request failed. Please try again.', false);
		});
	};

	['tn-teamassign-close', 'tn-teamassign-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(TEAMASSIGN_OVERLAY); });
	});
	var taOv = document.getElementById(TEAMASSIGN_OVERLAY);
	if (taOv) taOv.addEventListener('click', function(e) { if (e.target === taOv) tnCloseModal(TEAMASSIGN_OVERLAY); });
	var taSubmit = document.getElementById('tn-teamassign-submit');
	if (taSubmit) taSubmit.addEventListener('click', function() { tnSubmitTeamAssign(_teamAssignTnum); });

	// ---- Remove team ----
	window.tnRemoveTeam = function(tnum) {
		var t = teamByTnum(tnum);
		if (!t) return;
		var name = t.Name || ('Team #' + tnum);
		tnConfirm({
			danger: true,
			title: 'Remove team',
			body: 'Remove <strong>' + tnEsc(name) + '</strong> from the tournament?',
			confirmLabel: 'Remove',
			cancelLabel: 'Cancel',
			onConfirm: function() {
				var fd = new FormData();
				fd.append('TeamNumber', tnum);
				fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/removeteam', { method: 'POST', body: fd })
					.then(function(res) { return res.json(); })
					.then(function(d) {
						if (d && d.status === 0) {
							TnConfig.registeredTeams = (TnConfig.registeredTeams || []).filter(function(x) {
								return (parseInt(x.TeamNumber, 10) || 0) !== (parseInt(tnum, 10) || 0);
							});
							tnRenderTeamsRoster();
							tnRenderTeamActions();
						} else {
							// Surface server error (e.g. in a started bracket) without removing.
							tnConfirm({
								title: 'Cannot remove',
								body: tnEsc((d && d.error) ? d.error : 'This team could not be removed.'),
								confirmLabel: 'OK',
								alertOnly: true
							});
						}
					})
					.catch(function(err) {
						console.error('[RemoveTeam] fetch failed:', err);
						tnConfirm({ title: 'Error', body: 'Request failed. Please try again.', confirmLabel: 'OK', alertOnly: true });
					});
			}
		});
	};
})();
<?php endif; ?>

// ---- Assign Participants (bulk) Modal — bracket-level ----
<?php if ($canManage): ?>
(function() {
	var OVERLAY = 'tn-assignparts-overlay';
	var _bid = 0;

	function el(id) { return document.getElementById(id); }

	// Build warrior/award pills for a registrant (mirrors tnRenderRoster / tnParticipantPills).
	function regPills(r) {
		var pills = '';
		var wc = parseInt(r.WarriorCount, 10) || 0;
		if (wc > 0) {
			pills += '<span class="tn-pill tn-pill-warrior" data-tip="Order of the Warrior x' + wc + '">' + Math.min(wc, 10) + '</span>';
		}
		if (r.IsWarlord) pills += '<span class="tn-pill tn-pill-warlord" data-tip="Warlord">W</span>';
		if (r.IsKnightSword) pills += '<span class="tn-pill tn-pill-knight" data-tip="Knight of the Sword">K</span>';
		return pills ? '<span style="display:inline-flex;gap:3px;vertical-align:middle">' + pills + '</span>' : '';
	}

	function rowMatches(row, term) {
		if (!term) return true;
		var hay = (row.getAttribute('data-search') || '');
		return hay.indexOf(term) !== -1;
	}

	function applyFilter() {
		var term = ((el('tn-assignparts-filter') || {}).value || '').trim().toLowerCase();
		var list = el('tn-assignparts-list');
		if (!list) return;
		list.querySelectorAll('.tn-assignparts-row').forEach(function(row) {
			if (rowMatches(row, term)) row.classList.remove('tn-assignparts-hidden');
			else row.classList.add('tn-assignparts-hidden');
		});
	}

	window.tnOpenAssignParticipantsModal = function(bid) {
		_bid = parseInt(bid, 10) || 0;
		tnHideFeedback('tn-assignparts-feedback');
		var filter = el('tn-assignparts-filter');
		if (filter) filter.value = '';

		var br = (TnConfig.bracketData && TnConfig.bracketData[_bid] && TnConfig.bracketData[_bid].Bracket) ? TnConfig.bracketData[_bid].Bracket : null;
		var label = tnBracketLabel(br) || ('Bracket #' + _bid);
		var sub = el('tn-assignparts-subtitle');
		if (sub) sub.innerHTML = 'Select participants for <strong>' + tnEsc(label) + '</strong>.';

		var regs = TnConfig.registrants || [];
		var list = el('tn-assignparts-list');
		if (!list) return;

		if (!regs.length) {
			list.innerHTML = '<div class="tn-assignparts-empty">No participants registered yet. '
				+ '<a onclick="tnActivateTab(\'participants\')">Register participants first</a>.</div>';
			tnOpenAsSheet(OVERLAY, {});
			return;
		}

		var rows = '';
		regs.forEach(function(r) {
			var pnum = parseInt(r.ParticipantNumber, 10) || 0;
			if (!pnum) return;
			var withdrawn = (r.Status === 'withdrawn');
			// Pre-check if this registrant is already in THIS bracket; withdrawn default unchecked.
			var inBracket = false;
			(r.Brackets || []).forEach(function(b) { if ((parseInt(b.BracketId, 10) || 0) === _bid) inBracket = true; });
			var checked = inBracket && !withdrawn;

			var alias = r.Alias || ('#' + pnum);
			var persona = r.Persona || '';
			var park = r.ParkName || '';
			var subBits = [];
			if (persona) subBits.push(tnEsc(persona));
			if (park) subBits.push(tnEsc(park));
			var subLine = subBits.join(' \u00b7 ');

			var search = (alias + ' ' + persona + ' ' + park).toLowerCase();

			rows += '<label class="tn-assignparts-row' + (withdrawn ? ' tn-assignparts-wd' : '') + '" '
				+ 'data-search="' + tnEsc(search) + '">'
				+ '<input type="checkbox" data-pnum="' + pnum + '" data-was="' + (checked ? '1' : '0') + '"' + (checked ? ' checked' : '') + '>'
				+ '<span class="tn-assignparts-main">'
				+ '<span class="tn-assignparts-alias">' + tnEsc(alias)
				+ (withdrawn ? ' <span class="tn-reg-wd-badge">Withdrawn</span>' : '')
				+ regPills(r) + '</span>'
				+ (subLine ? '<span class="tn-assignparts-sub">' + subLine + '</span>' : '')
				+ '</span>'
				+ '</label>';
		});
		list.innerHTML = rows || '<div class="tn-assignparts-empty">No participants registered yet.</div>';
		applyFilter();
		tnOpenAsSheet(OVERLAY, {});
	};

	window.tnSubmitAssignParticipants = function() {
		var btn = el('tn-assignparts-submit');
		var list = el('tn-assignparts-list');
		if (!list) return;

		var addArr = [], removeArr = [];
		list.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
			var pnum = parseInt(cb.getAttribute('data-pnum'), 10) || 0;
			if (!pnum) return;
			var was = cb.getAttribute('data-was') === '1';
			if (cb.checked && !was) addArr.push(pnum);
			else if (!cb.checked && was) removeArr.push(pnum);
		});

		if (!addArr.length && !removeArr.length) {
			tnCloseModal(OVERLAY);
			return;
		}

		if (btn) btn.disabled = true;
		var base = TnConfig.uir + 'TournamentAjax/bracket/' + _bid + '/';

		var errors = [];
		// Assign and unassign are independent operations — run them in parallel and
		// collect failures from each, so a failure in one does not silently drop the
		// other (e.g. an unchecked-bracket removal isn't lost when an add fails).
		function call(action, arr, failMsg) {
			if (!arr.length) return Promise.resolve(null);
			var fd = new FormData();
			fd.append('TournamentId', TnConfig.tournamentId);
			fd.append('ParticipantNumbers', JSON.stringify(arr));
			return fetch(base + action, { method: 'POST', body: fd })
				.then(function(res) { return res.json(); })
				.then(function(d) { if (!d || d.status !== 0) errors.push((d && d.error) ? d.error : failMsg); })
				.catch(function() { errors.push(failMsg); });
		}

		Promise.all([
			call('assign', addArr, 'Failed to assign participants.'),
			call('unassign', removeArr, 'Failed to remove participants.')
		])
			.then(function() {
				if (errors.length) {
					if (btn) btn.disabled = false;
					tnShowFeedback('tn-assignparts-feedback', errors.join(' '), false);
					return;
				}
				// Success. A full reload rebuilds roster chips + bracket card list, so
				// skip the interim registrants refetch the reload would discard (#58).
				tnShowFeedback('tn-assignparts-feedback', 'Saved!', true);
				try { sessionStorage.setItem('tnOpenTab', 'brackets'); sessionStorage.setItem('tnScrollBracket', _bid); } catch (e) {}
				setTimeout(function() { window.location.reload(); }, 500);
			})
			.catch(function(err) {
				console.error('[AssignParts] failed:', err);
				if (btn) btn.disabled = false;
				tnShowFeedback('tn-assignparts-feedback', 'Request failed. Please try again.', false);
			});
	};

	var filterEl = el('tn-assignparts-filter');
	if (filterEl) filterEl.addEventListener('input', applyFilter);

	var selectAll = el('tn-assignparts-selectall');
	if (selectAll) selectAll.addEventListener('click', function() {
		var list = el('tn-assignparts-list');
		if (!list) return;
		list.querySelectorAll('.tn-assignparts-row:not(.tn-assignparts-hidden) input[type=checkbox]').forEach(function(cb) { cb.checked = true; });
	});
	var clearBtn = el('tn-assignparts-clear');
	if (clearBtn) clearBtn.addEventListener('click', function() {
		var list = el('tn-assignparts-list');
		if (!list) return;
		list.querySelectorAll('.tn-assignparts-row:not(.tn-assignparts-hidden) input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
	});

	['tn-assignparts-close', 'tn-assignparts-cancel'].forEach(function(id) {
		var e = el(id);
		if (e) e.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = el(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });
	var submit = el('tn-assignparts-submit');
	if (submit) submit.addEventListener('click', tnSubmitAssignParticipants);
})();
<?php endif; ?>

// ---- Assign Teams (bulk) Modal — bracket-level (team brackets) ----
<?php if ($canManage): ?>
(function() {
	var OVERLAY = 'tn-assignteams-overlay';
	var _bid = 0;

	function el(id) { return document.getElementById(id); }

	// Team warrior-level pill (mirrors tnRenderTeamsRoster name-cell pill).
	function teamPill(t) {
		var wl = parseInt(t.WarriorLevel, 10) || 0;
		return '<span class="tn-pill tn-pill-team-wl" data-tip="Team warrior level">⚔ ' + wl + '</span>';
	}

	function rowMatches(row, term) {
		if (!term) return true;
		var hay = (row.getAttribute('data-search') || '');
		return hay.indexOf(term) !== -1;
	}

	function applyFilter() {
		var term = ((el('tn-assignteams-filter') || {}).value || '').trim().toLowerCase();
		var list = el('tn-assignteams-list');
		if (!list) return;
		list.querySelectorAll('.tn-assignparts-row').forEach(function(row) {
			if (rowMatches(row, term)) row.classList.remove('tn-assignparts-hidden');
			else row.classList.add('tn-assignparts-hidden');
		});
	}

	window.tnOpenAssignTeamsModal = function(bid) {
		_bid = parseInt(bid, 10) || 0;
		tnHideFeedback('tn-assignteams-feedback');
		var filter = el('tn-assignteams-filter');
		if (filter) filter.value = '';

		var br = (TnConfig.bracketData && TnConfig.bracketData[_bid] && TnConfig.bracketData[_bid].Bracket) ? TnConfig.bracketData[_bid].Bracket : null;
		var label = tnBracketLabel(br) || ('Bracket #' + _bid);
		var sub = el('tn-assignteams-subtitle');
		if (sub) sub.innerHTML = 'Select teams for <strong>' + tnEsc(label) + '</strong>.';

		var teams = TnConfig.registeredTeams || [];
		var list = el('tn-assignteams-list');
		if (!list) return;

		if (!teams.length) {
			list.innerHTML = '<div class="tn-assignparts-empty">No teams registered yet. '
				+ '<a onclick="tnCloseModal(\'' + OVERLAY + '\');tnParticipantsSubtab(\'teams\')">Create a team first</a>.</div>';
			tnOpenAsSheet(OVERLAY, {});
			return;
		}

		var rows = '';
		teams.forEach(function(t) {
			var tnum = parseInt(t.TeamNumber, 10) || 0;
			if (!tnum) return;
			var members = t.Members || [];
			// Pre-check if this team is already assigned to THIS bracket.
			var inBracket = false;
			(t.Brackets || []).forEach(function(b) { if ((parseInt(b.BracketId, 10) || 0) === _bid) inBracket = true; });
			var checked = inBracket;

			var name = t.Name || ('Team #' + tnum);
			var mc = members.length;
			var subLine = mc + (mc === 1 ? ' member' : ' members');

			var search = (name).toLowerCase();

			rows += '<label class="tn-assignparts-row" data-search="' + tnEsc(search) + '">'
				+ '<input type="checkbox" data-tnum="' + tnum + '" data-was="' + (checked ? '1' : '0') + '"' + (checked ? ' checked' : '') + '>'
				+ '<span class="tn-assignparts-main">'
				+ '<span class="tn-assignparts-alias">' + tnEsc(name) + ' ' + teamPill(t) + '</span>'
				+ '<span class="tn-assignparts-sub">' + subLine + '</span>'
				+ '</span>'
				+ '</label>';
		});
		list.innerHTML = rows || '<div class="tn-assignparts-empty">No teams registered yet.</div>';
		applyFilter();
		tnOpenAsSheet(OVERLAY, {});
	};

	window.tnSubmitAssignTeams = function() {
		var btn = el('tn-assignteams-submit');
		var list = el('tn-assignteams-list');
		if (!list) return;

		var addArr = [], removeArr = [];
		list.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
			var tnum = parseInt(cb.getAttribute('data-tnum'), 10) || 0;
			if (!tnum) return;
			var was = cb.getAttribute('data-was') === '1';
			if (cb.checked && !was) addArr.push(tnum);
			else if (!cb.checked && was) removeArr.push(tnum);
		});

		if (!addArr.length && !removeArr.length) {
			tnCloseModal(OVERLAY);
			return;
		}

		if (btn) btn.disabled = true;
		var base = TnConfig.uir + 'TournamentAjax/bracket/' + _bid + '/';

		var errors = [];
		// Assign and unassign are independent operations — run them in parallel and
		// collect failures from each, so a failure in one does not silently drop the other.
		function call(action, arr, failMsg) {
			if (!arr.length) return Promise.resolve(null);
			var fd = new FormData();
			fd.append('TournamentId', TnConfig.tournamentId);
			fd.append('TeamNumbers', JSON.stringify(arr));
			return fetch(base + action, { method: 'POST', body: fd })
				.then(function(res) { return res.json(); })
				.then(function(d) { if (!d || d.status !== 0) errors.push((d && d.error) ? d.error : failMsg); })
				.catch(function() { errors.push(failMsg); });
		}

		Promise.all([
			call('assignteams', addArr, 'Failed to assign teams.'),
			call('unassignteams', removeArr, 'Failed to remove teams.')
		])
			.then(function() {
				if (errors.length) {
					if (btn) btn.disabled = false;
					tnShowFeedback('tn-assignteams-feedback', errors.join(' '), false);
					return;
				}
				// Success. A full reload rebuilds team roster chips + bracket card team
				// list, so skip the interim registeredteams refetch reload discards (#58).
				tnShowFeedback('tn-assignteams-feedback', 'Saved!', true);
				try { sessionStorage.setItem('tnOpenTab', 'brackets'); sessionStorage.setItem('tnScrollBracket', _bid); } catch (e) {}
				setTimeout(function() { window.location.reload(); }, 500);
			})
			.catch(function(err) {
				console.error('[AssignTeams] failed:', err);
				if (btn) btn.disabled = false;
				tnShowFeedback('tn-assignteams-feedback', 'Request failed. Please try again.', false);
			});
	};

	var filterEl = el('tn-assignteams-filter');
	if (filterEl) filterEl.addEventListener('input', applyFilter);

	var selectAll = el('tn-assignteams-selectall');
	if (selectAll) selectAll.addEventListener('click', function() {
		var list = el('tn-assignteams-list');
		if (!list) return;
		list.querySelectorAll('.tn-assignparts-row:not(.tn-assignparts-hidden) input[type=checkbox]').forEach(function(cb) { cb.checked = true; });
	});
	var clearBtn = el('tn-assignteams-clear');
	if (clearBtn) clearBtn.addEventListener('click', function() {
		var list = el('tn-assignteams-list');
		if (!list) return;
		list.querySelectorAll('.tn-assignparts-row:not(.tn-assignparts-hidden) input[type=checkbox]').forEach(function(cb) { cb.checked = false; });
	});

	['tn-assignteams-close', 'tn-assignteams-cancel'].forEach(function(id) {
		var e = el(id);
		if (e) e.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = el(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });
	var submit = el('tn-assignteams-submit');
	if (submit) submit.addEventListener('click', tnSubmitAssignTeams);
})();
<?php endif; ?>

// ---- Add Team Modal (team brackets) ----
<?php if ($canManage): ?>
(function() {
	var OVERLAY = 'tn-addteam-overlay';
	var _teamMembers = [];   // [{MundaneId, Persona}]
	var _addedTeams  = 0;
	var _teamTimer;

	window.tnOpenAddTeamModal = function(bracketId, tournamentId) {
		_teamMembers = [];
		_addedTeams  = 0;
		document.getElementById('tn-addteam-bracket-id').value    = bracketId;
		document.getElementById('tn-addteam-tournament-id').value = tournamentId;
		document.getElementById('tn-addteam-name').value          = '';
		document.getElementById('tn-addteam-members').innerHTML   = '';
		tnHideFeedback('tn-addteam-feedback');
		// Show step 1, hide step 2
		document.getElementById('tn-addteam-step1').style.display  = '';
		document.getElementById('tn-addteam-step2').style.display  = 'none';
		document.getElementById('tn-addteam-next').style.display   = '';
		document.getElementById('tn-addteam-submit').style.display = 'none';
		document.getElementById('tn-teamquickadd-section').style.display = 'none';
		tnTeamAcClose();
		// Mobile: bottom sheet; desktop: legacy centered overlay (unchanged).
		tnOpenAsSheet(OVERLAY, {});
		setTimeout(function(){ document.getElementById('tn-addteam-name').focus(); }, 50);
	};

	// Collect mundane IDs already on teams in this bracket
	function tnGetAssignedMundaneIds(bracketId) {
		var assigned = {};
		var bd = TnConfig.bracketData[bracketId];
		if (bd && bd.Participants) {
			bd.Participants.forEach(function(p) {
				if (p.MundaneId > 0) assigned[p.MundaneId] = true;
				if (p.IsTeam && p.Members) p.Members.forEach(function(m){ if (m.MundaneId > 0) assigned[m.MundaneId] = true; });
			});
		}
		return assigned;
	}

	// Build quick-add list for team members (individuals from other brackets, minus already-assigned)
	function tnBuildTeamQuickAdd(bracketId) {
		var section = document.getElementById('tn-teamquickadd-section');
		var panel   = document.getElementById('tn-teamquickadd-list');
		if (!section || !panel) return;
		panel.innerHTML = '';

		var assigned = tnGetAssignedMundaneIds(bracketId);
		// Also exclude members already added to this team
		_teamMembers.forEach(function(m) { assigned[m.MundaneId] = true; });

		var candidates = [];
		var seen = {};
		for (var bid in TnConfig.bracketData) {
			var bData = TnConfig.bracketData[bid];
			if (!bData || !bData.Participants) continue;
			bData.Participants.forEach(function(p) {
				if (p.MundaneId > 0 && !assigned[p.MundaneId] && !seen[p.MundaneId]) {
					seen[p.MundaneId] = true;
					candidates.push(p);
				}
			});
		}

		if (candidates.length === 0) { section.style.display = 'none'; return; }
		section.style.display = '';

		candidates.forEach(function(p) {
			var row = document.createElement('div');
			row.className = 'tn-quickadd-row';
			row.dataset.mid = p.MundaneId;
			var nameEl = document.createElement('span');
			nameEl.className = 'tn-quickadd-name';
			nameEl.textContent = p.Persona || p.Alias || '—';
			var btn = document.createElement('button');
			btn.className = 'tn-btn tn-btn-outline tn-btn-sm';
			btn.style.cssText = 'padding:2px 10px;flex-shrink:0';
			btn.innerHTML = '<i class="fas fa-plus"></i> Add';
			btn.addEventListener('click', function() {
				tnAddTeamMember(p.MundaneId, p.Persona || p.Alias || '');
				row.classList.add('tn-quickadd-done');
				btn.innerHTML = '<i class="fas fa-check"></i>';
				btn.disabled = true;
			});
			row.appendChild(nameEl);
			row.appendChild(btn);
			panel.appendChild(row);
		});
	}

	function tnAddTeamMember(mundaneId, persona) {
		if (!mundaneId || mundaneId <= 0) return;
		// Deduplicate
		for (var i = 0; i < _teamMembers.length; i++) {
			if (_teamMembers[i].MundaneId == mundaneId) return;
		}
		_teamMembers.push({MundaneId: parseInt(mundaneId), Persona: persona});
		tnRenderTeamMembers();
		tnShowFeedback('tn-addteam-feedback', _teamMembers.length + ' member' + (_teamMembers.length !== 1 ? 's' : '') + ' added', true);
		// Disable matching quick-add row
		var qaRows = document.querySelectorAll('#tn-teamquickadd-list .tn-quickadd-row');
		qaRows.forEach(function(r) {
			if (r.dataset.mid == mundaneId) {
				r.classList.add('tn-quickadd-done');
				var b = r.querySelector('button');
				if (b) { b.innerHTML = '<i class="fas fa-check"></i>'; b.disabled = true; }
			}
		});
	}

	function tnRemoveTeamMember(mundaneId) {
		_teamMembers = _teamMembers.filter(function(m) { return m.MundaneId != mundaneId; });
		tnRenderTeamMembers();
		// Re-enable matching quick-add row
		var qaRows = document.querySelectorAll('#tn-teamquickadd-list .tn-quickadd-row');
		qaRows.forEach(function(r) {
			if (r.dataset.mid == mundaneId) {
				r.classList.remove('tn-quickadd-done');
				var b = r.querySelector('button');
				if (b) { b.innerHTML = '<i class="fas fa-plus"></i> Add'; b.disabled = false; }
			}
		});
	}

	function tnRenderTeamMembers() {
		var container = document.getElementById('tn-addteam-members');
		container.innerHTML = '';
		if (_teamMembers.length === 0) {
			container.innerHTML = '<div style="font-size:12px;color:#a0aec0;padding:4px 0">No members added yet</div>';
			return;
		}
		_teamMembers.forEach(function(m) {
			var tag = document.createElement('span');
			tag.className = 'tn-team-member-tag';
			tag.innerHTML = '<i class="fas fa-user" style="font-size:10px"></i> ' + tnEsc(m.Persona);
			var x = document.createElement('button');
			x.className = 'tn-team-member-remove';
			x.innerHTML = '&times;';
			x.setAttribute('data-tip', 'Remove');
			x.addEventListener('click', function() { tnRemoveTeamMember(m.MundaneId); });
			tag.appendChild(x);
			container.appendChild(tag);
		});
	}

	// Autocomplete for team member search
	var teamPlayerInput = document.getElementById('tn-addteam-player-text');
	var teamResultsEl   = document.getElementById('tn-addteam-player-results');

	function tnTeamAcClose() {
		if (!teamResultsEl) return;
		teamResultsEl.classList.remove('kn-ac-open');
		teamResultsEl.innerHTML = '';
	}

	function tnTeamAcRender(players) {
		teamResultsEl.innerHTML = '';
		var bracketId = document.getElementById('tn-addteam-bracket-id').value;
		var assigned = tnGetAssignedMundaneIds(bracketId);
		_teamMembers.forEach(function(m) { assigned[m.MundaneId] = true; });

		var filtered = (players || []).filter(function(pl) {
			var mid = pl.MundaneId || pl.mundane_id || 0;
			return mid > 0 && !assigned[mid];
		});

		if (!filtered.length) {
			teamResultsEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No players found</div>';
			if (teamPlayerInput) tnFixedAcPosition(teamPlayerInput, teamResultsEl);
			teamResultsEl.classList.add('kn-ac-open');
			return;
		}
		filtered.forEach(function(pl) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			var label = tnEsc(pl.Persona || pl.Name || '');
			var sub   = pl.KAbbr ? (' <span style="color:#a0aec0;font-size:11px">(' + tnEsc(pl.KAbbr) + (pl.PAbbr ? ':' + tnEsc(pl.PAbbr) : '') + ')</span>') : '';
			item.innerHTML = label + sub;
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				var mid  = pl.MundaneId || pl.mundane_id || 0;
				var name = pl.Persona || pl.Name || '';
				tnAddTeamMember(mid, name);
				teamPlayerInput.value = '';
				tnTeamAcClose();
				teamPlayerInput.focus();
			});
			teamResultsEl.appendChild(item);
		});
		if (teamPlayerInput) tnFixedAcPosition(teamPlayerInput, teamResultsEl);
		teamResultsEl.classList.add('kn-ac-open');
	}

	if (teamPlayerInput && teamResultsEl) {
		teamPlayerInput.addEventListener('input', function() {
			var term = this.value.trim();
			clearTimeout(_teamTimer);
			if (term.length < 2) { tnTeamAcClose(); return; }
			_teamTimer = setTimeout(function() {
				if (!(TnConfig.searchKingdomId > 0)) { tnTeamAcClose(); return; }
				fetch(TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId + '&q=' + encodeURIComponent(term))
					.then(function(r) { return r.json(); })
					.then(function(data) { tnTeamAcRender(data); })
					.catch(function() { tnTeamAcClose(); });
			}, 280);
		});
		teamPlayerInput.addEventListener('blur', function() { setTimeout(tnTeamAcClose, 200); });
	}

	// Next button: advance from step 1 (team name) to step 2 (add members)
	document.getElementById('tn-addteam-next').addEventListener('click', function() {
		var name = document.getElementById('tn-addteam-name').value.trim();
		if (!name) { tnShowFeedback('tn-addteam-feedback', 'Team name is required.', false); return; }
		document.getElementById('tn-addteam-step1').style.display  = 'none';
		document.getElementById('tn-addteam-step2').style.display  = '';
		document.getElementById('tn-addteam-next').style.display   = 'none';
		document.getElementById('tn-addteam-submit').style.display = '';
		document.getElementById('tn-addteam-label').textContent = name;
		tnHideFeedback('tn-addteam-feedback');
		tnRenderTeamMembers();
		var bracketId = document.getElementById('tn-addteam-bracket-id').value;
		tnBuildTeamQuickAdd(bracketId);
		setTimeout(function(){ if (teamPlayerInput) teamPlayerInput.focus(); }, 50);
	});

	// Submit: create team participant with Members array
	document.getElementById('tn-addteam-submit').addEventListener('click', function() {
		var btn          = this;
		var teamName     = document.getElementById('tn-addteam-name').value.trim();
		var bracketId    = document.getElementById('tn-addteam-bracket-id').value;
		var tournamentId = document.getElementById('tn-addteam-tournament-id').value;

		if (!teamName) { tnShowFeedback('tn-addteam-feedback', 'Team name is required.', false); return; }
		if (_teamMembers.length === 0) { tnShowFeedback('tn-addteam-feedback', 'Add at least one member to the team.', false); return; }

		btn.disabled = true;
		var fd = new FormData();
		fd.append('Alias', teamName);
		fd.append('MundaneId', 0);
		fd.append('TournamentId', tournamentId);
		fd.append('Members', JSON.stringify(_teamMembers));

		fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/addparticipant', {method:'POST', body:fd})
			.then(function(r){ return r.json(); })
			.then(function(d){
				btn.disabled = false;
				if (d && d.status === 0) {
					// Hide submit immediately so a fast double-click can't resubmit the
					// (now-cleared) roster during the step-1 reset + refocus window.
					document.getElementById('tn-addteam-submit').style.display = 'none';
					_addedTeams++;
					if (window.tnRefreshRoster) window.tnRefreshRoster();
					tnShowFeedback('tn-addteam-feedback', 'Team "' + tnEsc(teamName) + '" saved! (' + _addedTeams + ' team' + (_addedTeams !== 1 ? 's' : '') + ' added) — add another or close when done.', true);
					// Update local bracketData so assigned IDs are tracked for subsequent adds
					if (TnConfig.bracketData[bracketId]) {
						if (!TnConfig.bracketData[bracketId].Participants) TnConfig.bracketData[bracketId].Participants = [];
						// Push a single team-shaped entry matching the server return shape so
						// tnGetAssignedMundaneIds()/tnBuildTeamQuickAdd() see the team correctly.
						TnConfig.bracketData[bracketId].Participants.push({
							IsTeam: true,
							ParticipantId: d.participantId || 0,
							Alias: teamName,
							MundaneId: 0,
							Members: _teamMembers.map(function(m) { return {MundaneId: m.MundaneId, Persona: m.Persona}; })
						});
					}
					// Reset for next team
					_teamMembers = [];
					document.getElementById('tn-addteam-name').value = '';
					document.getElementById('tn-addteam-members').innerHTML = '';
					document.getElementById('tn-addteam-step1').style.display  = '';
					document.getElementById('tn-addteam-step2').style.display  = 'none';
					document.getElementById('tn-addteam-next').style.display   = '';
					document.getElementById('tn-addteam-submit').style.display = 'none';
					document.getElementById('tn-teamquickadd-section').style.display = 'none';
					setTimeout(function(){ document.getElementById('tn-addteam-name').focus(); }, 50);
				} else {
					tnShowFeedback('tn-addteam-feedback', (d && d.error) ? d.error : 'Failed to save team.', false);
				}
			})
			.catch(function(){
				btn.disabled = false;
				tnShowFeedback('tn-addteam-feedback', 'Request failed.', false);
			});
	});

	// Close/cancel handlers — reload if teams were added
	var teamOv = document.getElementById(OVERLAY);
	if (teamOv) {
		teamOv.addEventListener('click', function(e) {
			if (e.target === teamOv) {
				tnCloseModal(OVERLAY);
				if (_addedTeams > 0) { _addedTeams = 0; window.location.reload(); }
			}
		});
	}
	['tn-addteam-close','tn-addteam-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() {
			tnCloseModal(OVERLAY);
			if (_addedTeams > 0) { _addedTeams = 0; window.location.reload(); }
		});
	});
})();
<?php endif; ?>

// ============================================================
// Standings: bracket selector + sortable columns
// ============================================================
window.tnBracketPillClick = function(btn, bracketId) {
	document.querySelectorAll('.tn-bk-pills .tn-bk-pill').forEach(function(b) { b.classList.remove('tn-bk-pill-active'); });
	btn.classList.add('tn-bk-pill-active');
	var inp = document.getElementById('tn-bv-bracket-select');
	if (inp) inp.value = bracketId;
	tnRenderBracketViz(bracketId);
};

window.tnGoToBracket = function(bracketId) {
	tnActivateTab('bracketviz');
	var inp = document.getElementById('tn-bv-bracket-select');
	if (inp) inp.value = bracketId;
	document.querySelectorAll('#tn-tab-bracketviz .tn-bk-pill').forEach(function(b) {
		b.classList.toggle('tn-bk-pill-active', parseInt(b.dataset.bid) === bracketId);
	});
	tnRenderBracketViz(bracketId);
};

// Re-render one Brackets-tab card (status badge, actions, participant list, match
// table) and the header stats row in place from freshly server-rendered markup —
// the same PHP that builds them on page load — so in-place refreshes don't leave
// the card saying 'Setup' / 'No matches generated yet' with a stale Generate button.
// Accepts one bracket id or an array of them; either way the page is fetched once.
window.tnRefreshBracketCard = function(bracketIds) {
	var bids = (Array.isArray(bracketIds) ? bracketIds : [bracketIds])
		.map(function(b) { return parseInt(b, 10) || 0; })
		.filter(function(b, i, a) { return b && a.indexOf(b) === i; });
	if (!bids.length) return Promise.resolve();
	return fetch(window.location.href.split('#')[0], { credentials: 'same-origin' })
		.then(function(r) { return r.text(); })
		.then(function(html) {
			var doc = new DOMParser().parseFromString(html, 'text/html');
			bids.forEach(function(bid) {
				var fresh = doc.getElementById('tn-bracket-' + bid);
				var card  = document.getElementById('tn-bracket-' + bid);
				if (!fresh || !card) return;
				// Don't swap a card out from under someone scoring its points grid
				// (focused grid control or a save in flight): retry once they leave the card.
				// Buttons never defer — confirms return focus to the card button that opened them.
				var ae = document.activeElement;
				var editing = !!(ae && card.contains(ae) && ae.closest('.tn-points-grid-scroll, .tn-points-grid')
					&& (/^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName) || ae.classList.contains('tn-pip')));
				if (editing || card.querySelector('[data-tn-saving="1"]')) {
					if (editing) {
						if (!card._tnRefreshDeferred) {
							card._tnRefreshDeferred = true;
							card.addEventListener('focusout', function onOut() {
								card.removeEventListener('focusout', onOut);
								card._tnRefreshDeferred = false;
								if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bid);
							});
						}
					} else if (window.tnAfterResultRecorded) {
						window.tnAfterResultRecorded(bid);
					}
					return;
				}
				var node = document.importNode(fresh, true);
				if (card.classList.contains('tn-collapsed')) node.classList.add('tn-collapsed');
				card.querySelectorAll('table.tn-match-results-table').forEach(tnDestroyDataTable);
				card.parentNode.replaceChild(node, card);
				// A '+' (add round) on this card asked for the new round to stay in view.
				if (window._tnPtsScrollRight && window._tnPtsScrollRight[bid]) {
					delete window._tnPtsScrollRight[bid];
					var ptsScroll = node.querySelector('.tn-points-grid-scroll');
					if (ptsScroll) ptsScroll.scrollLeft = ptsScroll.scrollWidth;
				}
				var dnd = node.querySelector('.tn-dnd-list');
				if (dnd && typeof window.tnInitDnd === 'function') window.tnInitDnd(dnd, bid);
				// The swapped-in card is fresh markup, so re-apply the DataTables init
				// DOMContentLoaded gave the original (the old instance left with the old node).
				node.querySelectorAll('table.tn-match-results-table').forEach(function(t) {
					tnInitDataTable(t, { csvName: 'Match Results' });
				});
			});
			var freshStats = doc.querySelector('.tn-stats-row');
			var stats = document.querySelector('.tn-stats-row');
			if (freshStats && stats) stats.innerHTML = freshStats.innerHTML;
		})
		.catch(function(err) { console.warn('[tn] bracket card refresh failed', err); });
};

// Standings go stale after any result; refetch them the next time the Standings
// tab opens (see the tnActivateTab wrapper).
window.tnMarkStandingsDirty = function() { window._tnStandingsDirty = true; };

// Shared post-result hook for paths that refresh only the Run view (Record Result,
// quick win/bar, Next Up deck, ironman fast path): bring the Brackets-tab card and
// header stats in step and flag standings stale. Debounced so a rapid run of
// results (ironman streaks) costs one page fetch.
window.tnAfterResultRecorded = (function() {
	var pending = {}, timer = null;
	return function(bid) {
		window.tnMarkStandingsDirty();
		bid = parseInt(bid, 10) || 0;
		if (!bid) return;
		pending[bid] = true;
		clearTimeout(timer);
		timer = setTimeout(function() {
			var ids = Object.keys(pending);
			pending = {};
			if (window.tnRefreshBracketCard) window.tnRefreshBracketCard(ids);
		}, 600);
	};
})();

window.tnStandingsPillClick = function(btn, bracketId) {
	btn.closest('.tn-bk-pills').querySelectorAll('.tn-bk-pill').forEach(function(b) { b.classList.remove('tn-bk-pill-active'); });
	btn.classList.add('tn-bk-pill-active');
	tnShowStandings(bracketId);
};

window.tnShowStandings = function(bracketId) {
	var bid = String(bracketId);
	document.querySelectorAll('.tn-standings-section').forEach(function(s) {
		s.style.display = s.dataset.stbid === bid ? '' : 'none';
	});
};

// Jump to the Standings tab with a specific bracket pre-selected (used by the
// "View Standings" button on a completed ironman's timer row).
window.tnViewIronmanStandings = function(bracketId) {
	var panel = document.getElementById('tn-tab-standings');
	if (!panel) return; // no standings tab rendered (no standings data at load)
	tnActivateTab('standings');
	var pill = panel.querySelector('.tn-bk-pill[data-bid="' + bracketId + '"]');
	if (pill) tnStandingsPillClick(pill, bracketId);
	else if (typeof tnShowStandings === 'function') tnShowStandings(bracketId);
};

// DataTables for the server-rendered read-only tables. Registered after the
// roster action-cell population (DOMContentLoaded listeners run in order), so
// every row is filled before paging detaches the off-page ones.
document.addEventListener('DOMContentLoaded', function() {
	tnInitRosterTable();
	tnInitTeamsTable();
	document.querySelectorAll('table.tn-match-results-table').forEach(function(t) {
		tnInitDataTable(t, { csvName: 'Match Results' });
	});
});

// ============================================================
// Phase 7: Drag-and-drop seed reorder
// ============================================================
(function() {
	var dragSrc = null;

	// --- Shared persistence + DOM-renumber, used by BOTH desktop HTML5 DnD
	//     and the touch long-press reorder (B2). Reads the list's current
	//     DOM child order, renumbers the seed circles, and POSTs to the
	//     existing reorder endpoint; reverts to `prevOrder` on failure.
	//     `prevOrder` is the snapshot taken BEFORE the move (Array of <li>).
	// Shared seed-label renumber (used by commitReorder + the touch cancel path).
	function renumberList(list) {
		list.querySelectorAll('li[data-pid]').forEach(function(item, idx) {
			var seedEl = item.querySelector('.tn-seed-enhanced') || item.querySelector('.tn-participant-seed');
			if (seedEl) seedEl.textContent = idx + 1;
		});
	}
	function commitReorder(list, bracketId, prevOrder) {
		function renumber() { renumberList(list); }
		var newOrder = [];
		list.querySelectorAll('li[data-pid]').forEach(function(item) { newOrder.push(item.dataset.pid); });
		renumber();
		function restoreOrder() {
			prevOrder.forEach(function(item) { list.appendChild(item); });
			renumber();
			list.setAttribute('data-error', 'Reorder save failed — restored previous order');
			list.classList.add('tn-dnd-error');
			setTimeout(function() { list.classList.remove('tn-dnd-error'); list.removeAttribute('data-error'); }, 4000);
		}
		// Save new order (same endpoint + payload for desktop & touch).
		var url = TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/reorder';
		var fd  = new FormData();
		fd.append('Order', JSON.stringify(newOrder));
		fd.append('TournamentId', TnConfig.tournamentId);
		fetch(url, { method:'POST', body:fd }).then(function(r) { return r.json(); }).then(function(d) {
			if (!d || d.status !== 0) { console.warn('Reorder save failed', d); restoreOrder(); }
		}).catch(function(e) { console.warn('Reorder error', e); restoreOrder(); });
	}

	var dndDocGuards = {}; // bracketId -> document-level touch guard (see initDnd)
	function initDnd(list, bracketId) {
		var items = list.querySelectorAll('li[data-pid]');
		items.forEach(function(li) {
			li.setAttribute('draggable', 'true');
			li.style.cursor = 'grab';

			li.addEventListener('dragstart', function(e) {
				dragSrc = li;
				e.dataTransfer.effectAllowed = 'move';
				li.style.opacity = '0.5';
			});
			li.addEventListener('dragend', function() {
				li.style.opacity = '';
				list.querySelectorAll('li[data-pid]').forEach(function(i) { i.classList.remove('tn-dnd-over'); });
			});
			li.addEventListener('dragover', function(e) {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				if (li !== dragSrc) li.classList.add('tn-dnd-over');
			});
			li.addEventListener('dragleave', function() { li.classList.remove('tn-dnd-over'); });
			li.addEventListener('drop', function(e) {
				e.preventDefault();
				li.classList.remove('tn-dnd-over');
				if (!dragSrc || dragSrc === li) return;
				// Snapshot current order so we can revert if the save fails.
				var prevOrder = Array.prototype.slice.call(list.querySelectorAll('li[data-pid]'));
				// Insert dragSrc before this element
				var srcIdx = prevOrder.indexOf(dragSrc);
				var dstIdx = prevOrder.indexOf(li);
				if (srcIdx < dstIdx) list.insertBefore(dragSrc, li.nextSibling);
				else                 list.insertBefore(dragSrc, li);
				commitReorder(list, bracketId, prevOrder);
			});
		});

		initTouchDnd(list, bracketId);
	}

	// --- Touch long-press reorder (B2). HTML5 DnD does not fire on touch,
	//     so this is an additive path that drives the SAME commitReorder.
	//     Sets TnMobile.dragActive while a lift is live so TnMobile.swipe
	//     consumers (row swipe, deck) stand down (arbitration contract).
	function initTouchDnd(list, bracketId) {
		var LONG_PRESS_MS = 320;   // hold before lifting
		var MOVE_CANCEL   = 10;    // px of finger travel that cancels a pending lift (= scroll)
		var EDGE_ZONE     = 60;    // px from container edge that triggers autoscroll
		var EDGE_SPEED    = 10;    // px per frame autoscroll

		var pressTimer = null;
		var lifted     = null;     // the <li> currently lifted
		var placeholder = null;    // gap element marking the drop slot
		var prevOrder  = null;     // snapshot for revert
		var startY = 0, startX = 0;
		var grabOffsetY = 0;       // touch.clientY - row top at lift
		var rowH = 0;
		var rafId = null, edgeDir = 0;
		var scroller = null;       // nearest scrollable ancestor (or null → window)
		var dragSiblings = null;   // cached non-lifted rows for this drag (avoids per-move querySelectorAll)

		function findScroller(el) {
			var n = el.parentElement;
			while (n && n !== document.body) {
				var oy = getComputedStyle(n).overflowY;
				if ((oy === 'auto' || oy === 'scroll') && n.scrollHeight > n.clientHeight) return n;
				n = n.parentElement;
			}
			return null;
		}

		function clearPress() {
			if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
		}

		function stopAutoscroll() {
			edgeDir = 0;
			if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
		}

		function autoscrollStep() {
			if (!edgeDir) { rafId = null; return; }
			if (scroller) scroller.scrollTop += edgeDir * EDGE_SPEED;
			else          window.scrollBy(0, edgeDir * EDGE_SPEED);
			rafId = requestAnimationFrame(autoscrollStep);
		}

		function startAutoscroll(dir) {
			if (edgeDir === dir) return;
			edgeDir = dir;
			if (dir && !rafId) rafId = requestAnimationFrame(autoscrollStep);
		}

		function liftRow(li, clientY) {
			prevOrder = Array.prototype.slice.call(list.querySelectorAll('li[data-pid]'));
			scroller = findScroller(list);
			var rect = li.getBoundingClientRect();
			rowH = rect.height;
			grabOffsetY = clientY - rect.top;
			// Placeholder gap holds the slot height so the list doesn't collapse.
			placeholder = document.createElement('li');
			placeholder.className = 'tn-dnd-placeholder';
			placeholder.style.height = rowH + 'px';
			li.parentNode.insertBefore(placeholder, li.nextSibling);
			li.classList.add('tn-dnd-lifted');
			li.style.width = rect.width + 'px';
			li.style.left = rect.left + 'px';
			li.style.top = rect.top + 'px';
			lifted = li;
			TnMobile.dragActive = true;
			// Cache the sibling set once (rebuilt when the placeholder moves) so
			// moveLifted doesn't re-query the DOM on every touchmove.
			refreshDragSiblings();
			// Non-passive list touchmove only exists while a drag is live (registered
			// here, removed in dropRow/cancelLift) so idle scrolling stays smooth.
			list.addEventListener('touchmove', onListTouchMove, { passive: false });
			if (navigator.vibrate) { try { navigator.vibrate(15); } catch (e) {} }
			moveLifted(clientY);
		}

		// Rebuild the cached non-lifted sibling set (called at lift and whenever the
		// placeholder is repositioned, since that changes DOM order).
		function refreshDragSiblings() {
			dragSiblings = Array.prototype.slice.call(list.querySelectorAll('li[data-pid]:not(.tn-dnd-lifted)'));
		}

		// Renumber visible seed labels to match current DOM order (mirrors commitReorder.renumber).
		function renumberSeeds() { renumberList(list); }

		function moveLifted(clientY) {
			// Position the fixed lifted row to follow the finger.
			lifted.style.top = (clientY - grabOffsetY) + 'px';
			// Find the sibling whose midpoint we've crossed and move the placeholder.
			// Uses the cached sibling set (refreshed only when the placeholder moves).
			var siblings = dragSiblings || [];
			var target = null;
			for (var i = 0; i < siblings.length; i++) {
				var r = siblings[i].getBoundingClientRect();
				if (clientY < r.top + r.height / 2) { target = siblings[i]; break; }
			}
			if (target) {
				if (placeholder.nextSibling !== target) { list.insertBefore(placeholder, target); refreshDragSiblings(); }
			} else {
				if (placeholder !== list.lastElementChild) { list.appendChild(placeholder); refreshDragSiblings(); }
			}
		}

		function dropRow() {
			if (!lifted) return;
			// Drop the row into the placeholder slot (same DOM move as desktop drop).
			list.removeEventListener('touchmove', onListTouchMove, { passive: false });
			list.insertBefore(lifted, placeholder);
			if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
			lifted.classList.remove('tn-dnd-lifted');
			lifted.style.width = ''; lifted.style.left = ''; lifted.style.top = '';
			var movedList = list, movedBid = bracketId, snapshot = prevOrder;
			lifted = null; placeholder = null; prevOrder = null; dragSiblings = null;
			stopAutoscroll();
			TnMobile.dragActive = false;
			commitReorder(movedList, movedBid, snapshot);
		}

		function cancelLift() {
			if (!lifted) return;
			list.removeEventListener('touchmove', onListTouchMove, { passive: false });
			// Clear the lifted row's styling before restoring DOM order. The
			// placeholder no longer marks the original slot (moveLifted dragged it
			// to follow the finger), so restore from the prevOrder snapshot instead.
			if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
			lifted.classList.remove('tn-dnd-lifted');
			lifted.style.width = ''; lifted.style.left = ''; lifted.style.top = '';
			if (prevOrder) { prevOrder.forEach(function(item) { list.appendChild(item); }); renumberSeeds(); }
			lifted = null; placeholder = null; prevOrder = null; dragSiblings = null;
			stopAutoscroll();
			TnMobile.dragActive = false;
		}

		// Named so it can be add/removed per-drag (registered in liftRow, removed in
		// dropRow/cancelLift). Non-passive so it can preventDefault the page scroll.
		function onListTouchMove(e) {
			if (!lifted) return;
			var t = e.touches && e.touches[0];
			if (!t) return;
			e.preventDefault(); // only fires when a lift is engaged
			moveLifted(t.clientY);
			// Edge autoscroll vs the live scroll-container edges (cheap: this rect
			// read piggybacks on the layout moveLifted already forced this frame).
			var scEdge = scroller ? scroller.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
			if (t.clientY < scEdge.top + EDGE_ZONE)      startAutoscroll(-1);
			else if (t.clientY > scEdge.bottom - EDGE_ZONE) startAutoscroll(1);
			else                                          startAutoscroll(0);
		}

		list.querySelectorAll('li[data-pid]').forEach(function(li) {
			var handle = li.querySelector('.tn-dnd-handle') || li;

			handle.addEventListener('touchstart', function(e) {
				if (lifted) return;
				var t = e.touches && e.touches[0];
				if (!t) return;
				startX = t.clientX; startY = t.clientY;
				clearPress();
				pressTimer = setTimeout(function() {
					pressTimer = null;
					liftRow(li, startY);
				}, LONG_PRESS_MS);
			}, { passive: true });

			// touchmove BEFORE lift only watches for scroll-cancel (passive, no preventDefault).
			handle.addEventListener('touchmove', function(e) {
				if (lifted) return; // post-lift moves are handled on the list listener below
				var t = e.touches && e.touches[0];
				if (!t) return;
				if (pressTimer && (Math.abs(t.clientX - startX) > MOVE_CANCEL || Math.abs(t.clientY - startY) > MOVE_CANCEL)) {
					clearPress(); // finger moved → it's a scroll, abandon the pending lift
				}
			}, { passive: true });

			handle.addEventListener('touchend', clearPress, { passive: true });
			handle.addEventListener('touchcancel', clearPress, { passive: true });
		});

		list.addEventListener('touchend', function() { if (lifted) dropRow(); }, { passive: true });
		list.addEventListener('touchcancel', function() { if (lifted) cancelLift(); }, { passive: true });
		// Backstop: if `list` is detached mid-drag (bracket delete / re-render) its
		// own touchend/touchcancel never fire and TnMobile.dragActive would wedge
		// true, silently killing every swipe gesture until reload. Document-level
		// guards clean up; they no-op normally (this list's handler nulls `lifted`
		// first; other lists' `lifted` is already null).
		// One guard pair per bracket: a card re-render re-binds, so drop the previous
		// list's pair (cleaning up first, in case it was swapped out mid-drag).
		var prevGuard = dndDocGuards[bracketId];
		if (prevGuard) {
			prevGuard();
			document.removeEventListener('touchend',    prevGuard, { passive: true });
			document.removeEventListener('touchcancel', prevGuard, { passive: true });
		}
		var docGuard = function() { if (lifted) cancelLift(); };
		dndDocGuards[bracketId] = docGuard;
		document.addEventListener('touchend',    docGuard, { passive: true });
		document.addEventListener('touchcancel', docGuard, { passive: true });
	}

	document.addEventListener('DOMContentLoaded', function() {
		document.querySelectorAll('.tn-dnd-list').forEach(function(list) {
			var bracketId = parseInt(list.dataset.bracketId);
			initDnd(list, bracketId);
		});
	});
	// Re-bind a seed list swapped in by tnRefreshBracketCard.
	window.tnInitDnd = initDnd;
})();

// ============================================================
// Bracket Generation
// ============================================================
// Pure byes/rounds computation. Single source of truth shared by
// tnGenerateMatches and tnMobileGenerate (math copied verbatim from the
// canonical tnGenerateMatches path).
function tnComputeByesAndRounds(method, pCount, bracket) {
	var byes = 0, rounds = 0;
	if (method === 'single' || method === 'double') {
		var slots = 1;
		while (slots < pCount) slots *= 2;
		byes = slots - pCount;
		rounds = Math.round(Math.log2(slots));
		if (method === 'double') rounds = rounds + ' WR + ' + ((rounds - 1) * 2) + ' LR + GF';
	} else if (method === 'swiss') {
		var rings = Math.max(1, parseInt(bracket.Rings) || 1);
		rounds = rings > 1 ? rings : Math.ceil(Math.log2(pCount));
	} else if (method === 'round-robin') {
		rounds = pCount % 2 === 0 ? pCount - 1 : pCount;
	}
	return { byes: byes, rounds: rounds };
}
window.tnGenerateMatches = function(bracketId, tournamentId, skipConfirm) {
	if (!TnConfig.canManage) return;
	// Quick Bracket drafts start through quickstart so long-way (seed 0) entrants take the lowest
	// empty seats instead of sorting ahead of seed 1 (spec 2026-10-02).
	if (window.TnQuickBracket && TnQuickBracket.isDraftBracket(bracketId)) { TnQuickBracket.start(bracketId); return; }

	// Build pre-generate stats from TnConfig data
	var bd = TnConfig.bracketData[bracketId];
	if (!bd) { window.tnToast('Bracket data not found.'); return; }
	var bracket = bd.Bracket;
	var pCount  = (bd.Participants || []).length;
	var method  = bracket.Method || 'single';
	var methodLabel = TnConfig.methodLabels[method] || method;
	var styleLabel  = tnBracketLabel(bracket) || 'Bracket';
	var status  = bracket.Status || 'setup';
	var hasMatches = (bd.Matches || []).length > 0;

	// Calculate byes and rounds
	var _br = tnComputeByesAndRounds(method, pCount, bracket);
	var byes = _br.byes, rounds = _br.rounds;

	// Build confirmation body (HTML, rendered in the tnConfirm modal).
	if (!skipConfirm) {
		var body = '<p style="margin:0 0 10px;font-weight:600">' + tnEsc(styleLabel) + ' \u2014 ' + methodLabel + '</p>';
		body += '<ul style="margin:0;padding-left:18px">';
		body += '<li>Participants: ' + pCount + '</li>';
		if (byes > 0) body += '<li>First-round byes: ' + byes + '</li>';
		if (rounds) body += '<li>' + (method === 'swiss' ? 'Swiss rounds' : 'Rounds') + ': ' + rounds + '</li>';
		if (method !== 'swiss' && parseInt(bracket.Rings) > 1) body += '<li>Concurrent rings: ' + bracket.Rings + '</li>';
		body += '</ul>';
		var isRegen = (status === 'active' && hasMatches);
		if (isRegen) {
			body += '<p class="tn-confirm-warn">\u26a0\ufe0f This bracket is currently ACTIVE with existing match data. Re-generating will DELETE all current matches and results.</p>';
		}
		tnConfirm({
			title: 'Generate matches?',
			body: body,
			confirmLabel: 'Generate',
			danger: false,
			onConfirm: function() { tnGenerateMatches(bracketId, tournamentId, true); }
		});
		return;
	}

	var url = TnConfig.uir + 'TournamentAjax/tournament/' + tournamentId + '/generate';
	var fd  = new FormData();
	fd.append('BracketId', bracketId);
	// #30: tag the write so this tab's own generate echoes back as an own-action.
	var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
	if (window.tnRegisterAction) window.tnRegisterAction(actionId);
	fd.append('ActionId', actionId);

	fetch(url, { method:'POST', body:fd })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d && d.status === 0) {
				// Refresh in place so the Run view stays on the bracket we just
				// generated (a blanket reload re-seeds #tn-bv-bracket-select to
				// array_key_first($bracketData), snapping back to the first bracket).
				if (typeof window.tnRefreshAndRender === 'function') {
					var _sel = document.getElementById('tn-bv-bracket-select');
					if (_sel) _sel.value = bracketId;
					document.querySelectorAll('#tn-tab-bracketviz .tn-bk-pill').forEach(function(b) {
						b.classList.toggle('tn-bk-pill-active', parseInt(b.dataset.bid) === parseInt(bracketId));
					});
					window.tnRefreshAndRender(bracketId);
				} else {
					window.location.reload();
				}
			} else {
				window.tnToast((d && d.error) ? d.error : 'Failed to generate matches.');
			}
		})
		.catch(function() { window.tnToast('Request failed. Please try again.'); });
};

// ============================================================
// B3 — Mobile bracket-card actions (presentation only).
//   tnMobileGenerate:    styled confirm sheet -> EXISTING generate path.
//                        First-gen calls tnGenerateMatches(...,true) (skips
//                        the native confirm()); regen calls tnRegenCommit
//                        (the SAME clear+generate chain desktop's countdown
//                        commits, minus the countdown UI).
//   tnMobileBracketMore: "More" action sheet wiring the secondary card
//                        actions (Edit/Copy/Add/Paste/Delete) to their
//                        EXISTING functions. No new endpoints.
// Guarded on TnConfig.canManage + the .tn-mobile runtime check (never on
// element presence).
// ============================================================
window.tnMobileGenerate = function(bracketId, tournamentId, isRegen, matchCount) {
	if (!TnConfig.canManage) return;
	if (!(window.TnMobile && TnMobile.sheet && TnMobile.viewMode
		&& TnMobile.viewMode.isMobile && TnMobile.viewMode.isMobile())) {
		// Not mobile (e.g. flipped to desktop): fall back to the desktop path.
		tnGenerateMatches(bracketId, tournamentId);
		return;
	}
	var bd = TnConfig.bracketData[bracketId];
	var styleLabel = '', methodLabel = '', pCount = 0, byes = 0, rounds = 0;
	if (bd && bd.Bracket) {
		var bracket = bd.Bracket;
		pCount = (bd.Participants || []).length;
		var method = bracket.Method || 'single';
		methodLabel = TnConfig.methodLabels[method] || method;
		styleLabel = tnBracketLabel(bracket);
		// Byes/rounds via the shared helper (also used by tnGenerateMatches).
		var _br = tnComputeByesAndRounds(method, pCount, bracket);
		byes = _br.byes; rounds = _br.rounds;
	}
	var items = [];
	if (isRegen) {
		// Destructive: warn, then commit via the EXISTING tnRegenCommit chain.
		items.push({
			label: 'Regenerate — clears ' + matchCount + ' match' + (matchCount === 1 ? '' : 'es') + ' & results',
			danger: true,
			onTap: function() { tnRegenCommit(bracketId, tournamentId); }
		});
	} else {
		// First generation: informational; surfaces byes/rounds like the desktop
		// confirm() would. Calls tnGenerateMatches with skipConfirm=true.
		var sub = pCount + ' fighters';
		if (byes > 0) sub += ' → ' + byes + ' bye' + (byes === 1 ? '' : 's');
		if (rounds) sub += ' · ' + rounds + (typeof rounds === 'number' ? ' rounds' : '');
		items.push({
			label: 'Generate matches (' + sub + ')',
			onTap: function() { tnGenerateMatches(bracketId, tournamentId, true); }
		});
	}
	TnMobile.sheet.actionSheet(items);
};

window.tnMobileBracketMore = function(bracketId, tournamentId, isTeam, editData) {
	if (!TnConfig.canManage) return;
	if (!(window.TnMobile && TnMobile.sheet && TnMobile.viewMode
		&& TnMobile.viewMode.isMobile && TnMobile.viewMode.isMobile())) return;
	var items = [];
	// Edit opens the (B1) sheet-ified edit wizard via the existing opener.
	items.push({ label: 'Edit bracket', onTap: function() { tnOpenEditBracketModal(bracketId, editData); } });
	items.push({ label: 'Duplicate bracket', onTap: function() { tnCopyBracket(bracketId, tournamentId); } });
	// Roster adds only while the bracket is in setup (the server refuses otherwise).
	var _bd = TnConfig.bracketData && TnConfig.bracketData[bracketId];
	var _card = document.getElementById('tn-bracket-' + bracketId);
	var _st = (_bd && _bd.Bracket && _bd.Bracket.Status) || (_card && _card.getAttribute('data-status')) || 'setup';
	if (_st === 'setup') {
		if (isTeam) {
			items.push({ label: 'Add team', onTap: function() { tnOpenAddTeamModal(bracketId, tournamentId); } });
		} else {
			items.push({ label: 'Add participant', onTap: function() { tnOpenAddParticipantModal(bracketId, tournamentId); } });
			items.push({ label: 'Paste roster', onTap: function() { tnOpenBulkAddModal(bracketId, tournamentId); } });
		}
	}
	items.push({ label: 'Delete bracket', danger: true, onTap: function() { tnDeleteBracket(bracketId, tournamentId); } });
	TnMobile.sheet.actionSheet(items);
};

// ============================================================
// Bracket Visualization
// ============================================================
(function() {
	// Module-level refs for currently-open quick-result bar (closed by ref instead of
	// document-wide querySelectorAll on every match-card click).
	var _openQrBar = null, _openQrBox = null;
	// Exposed so the collab delta loop can tell when the local user has an open
	// quick-result bar (in-progress entry) and defer a destructive re-render.
	window.tnQrEntryOpen = function() { return !!_openQrBar; };
	// Per-bracket request sequence for the matches/brackets refresh paths
	// (tnRefreshAndRender, collab refetchBracket, spectator refreshAll): each fetch
	// takes a token up front and only the newest may write + paint, so an older
	// response landing late can't overwrite a newer render (e.g. drop the GF reset
	// banner). A stale response is still applied while Matches has never loaded, so
	// a failed newer fetch can't strand the bracket on 'Loading bracket…'.
	var _tnBvReqSeq = {};
	window.tnBvReqBegin = function(bid) { bid = parseInt(bid, 10) || 0; return (_tnBvReqSeq[bid] = (_tnBvReqSeq[bid] || 0) + 1); };
	window.tnBvReqCurrent = function(bid, tok) {
		bid = parseInt(bid, 10) || 0;
		if (tok === _tnBvReqSeq[bid]) return true;
		var _rb = TnConfig.bracketData && TnConfig.bracketData[bid];
		return !!(_rb && _rb.Matches === undefined);
	};
	// Find first bracket id with matches, or first bracket
	function firstBracketId() {
		var bd = TnConfig.bracketData;
		// prefer one with matches (Matches may be lazy — fall back to the _hasMatches flag, #63)
		for (var bidA in bd) {
			if (bd.hasOwnProperty(bidA) && ((bd[bidA].Matches && bd[bidA].Matches.length > 0) || bd[bidA]._hasMatches)) return parseInt(bidA);
		}
		for (var bidB in bd) { if (bd.hasOwnProperty(bidB)) return parseInt(bidB); }
		return 0;
	}

	// force: paint even while a Quick Bracket editor is open on this bracket (the editor is
	// reopened from its snapshot). Otherwise an open editor withholds the paint until it closes.
	function tnRefreshAndRender(bracketId, lazyLoad, force) {
		var tid = TnConfig.tournamentId;
		var _qbHold = function() { return !force && window.TnQuickBracket && window.TnQuickBracket.holdRepaint(bracketId); };
		// Invalidate any in-flight ironman fast-path fetch so it can't land after this fuller refresh.
		if (_tnImInfoSeq) _tnImInfoSeq[bracketId] = (_tnImInfoSeq[bracketId] || 0) + 1;
		var _reqTok = window.tnBvReqBegin(bracketId);
		Promise.all([
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/matches').then(function(r){ return r.json(); }),
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r){ return r.json(); }),
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/participants').then(function(r){ return r.json(); })
		]).then(function(results) {
			var mData = results[0], bData = results[1], pData = results[2];
			// A newer refresh of this bracket is in flight: let it write and paint the
			// matches; still keep Participants and the card/standings in step.
			if (!window.tnBvReqCurrent(bracketId, _reqTok)) {
				if (pData && pData.status === 0 && TnConfig.bracketData[bracketId]) TnConfig.bracketData[bracketId].Participants = pData.participants || [];
				if (!lazyLoad) {
					// The newer response may already have painted with older Participants
					// (e.g. a fresh WD/DQ): repaint from the store, which holds the newest Matches.
					var _sbd = TnConfig.bracketData[bracketId];
					if (_sbd && _sbd.Matches !== undefined && !window.tnQrEntryOpen() && !_qbHold()) tnRenderBracketViz(bracketId);
					if (window.tnRefreshBracketCard) window.tnRefreshBracketCard(bracketId);
					if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
				}
				return;
			}
			if (mData.status === 0 && TnConfig.bracketData[bracketId]) {
				TnConfig.bracketData[bracketId].Matches = mData.matches || [];
			} else if (TnConfig.bracketData[bracketId] && TnConfig.bracketData[bracketId].Matches === undefined) {
				// Server-side get_matches error on a never-loaded bracket: show the #52
				// Retry state instead of leaving the render guard on 'Loading bracket…'.
				TnConfig.bracketData[bracketId]._matchesLoadError = true;
			}
			if (bData.status === 0 && bData.brackets && TnConfig.bracketData[bracketId]) {
				var br = bData.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bracketId); });
				if (br) TnConfig.bracketData[bracketId].Bracket = br;
			}
			// Keep Participants fresh too (e.g. added in another tab).
			if (pData && pData.status === 0 && TnConfig.bracketData[bracketId]) {
				TnConfig.bracketData[bracketId].Participants = pData.participants || [];
			}
			if (!_qbHold()) tnRenderBracketViz(bracketId);
			// A real refresh (not the first lazy match load) follows a mutation: keep
			// the Brackets-tab card, header stats and standings in step with the Run view.
			if (!lazyLoad) {
				if (window.tnRefreshBracketCard) window.tnRefreshBracketCard(bracketId);
				if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
			}
		}).catch(function(err){
			console.warn('[tn] refresh failed', err);
			if (!window.tnBvReqCurrent(bracketId, _reqTok)) return;
			// #52: remember the load failed so the renderer shows an explicit error state
			// instead of masquerading as an empty bracket (enabled Generate + no wipe warning).
			if (TnConfig.bracketData[bracketId]) TnConfig.bracketData[bracketId]._matchesLoadError = true;
			if (!_qbHold()) tnRenderBracketViz(bracketId);
			if (window.tnShowStaleWarning) tnShowStaleWarning();
		});
	}

	window.tnRenderBracketViz = function(bracketId) {
		tnHideTooltip();
		var container = document.getElementById('tn-bv-container');
		if (!container) return;
		container.innerHTML = '';
		// Quick Bracket editor/swap state belongs to the draft render only. Decided up front, before
		// any early return, so loading/error/non-draft paints never leave an orphan input or dropdown
		// on <body>. A draft paint skips this: renderDraft closes the editor itself and keeps _restore.
		if (window.TnQuickBracket) {
			var _qbBd = TnConfig.bracketData[bracketId];
			if (!(_qbBd && window.TnQuickBracket.isDraft(_qbBd))) window.TnQuickBracket.teardown();
		}
		// The wipe detaches any open quick-result bar; drop the stale pointers so
		// tooltips and deferred peer repaints aren't suppressed forever.
		_openQrBar = null; _openQrBox = null;

		var bd = TnConfig.bracketData[bracketId];
		if (!bd) { container.innerHTML = '<div class="tn-bv-empty">Bracket not found.</div>'; return; }
		// Remember the Run bracket so a reload returns to it instead of the first one.
		try { sessionStorage.setItem('tnRunBracket_' + TnConfig.tournamentId, String(bracketId)); } catch (e) {}

		// #63 lazy-load: Matches are stripped from the initial payload and fetched on the
		// first render of each bracket via the existing refresh helper, then cached
		// (Matches becomes an array). The _matchesLoaded guard also prevents a failed
		// fetch (tnRefreshAndRender's catch re-renders without setting Matches) from looping.
		if (bd.Matches === undefined && !bd._matchesLoaded) {
			bd._matchesLoaded = true;
			container.innerHTML = '<div class="tn-bv-empty">Loading bracket…</div>';
			tnRefreshAndRender(bracketId, true);
			return;
		}
		// The lazy fetch is still in flight (another render — e.g. a collab refetch
		// or a tab switch — landed first): keep the placeholder rather than painting
		// an empty tree that would omit state such as the GF reset banner.
		if (bd.Matches === undefined && bd._matchesLoaded && !bd._matchesLoadError) {
			container.innerHTML = '<div class="tn-bv-empty">Loading bracket…</div>';
			return;
		}

		// #52: matches never loaded (fetch failed). Show an explicit, retryable error
		// state rather than the empty "No matches yet — Generate" state, which would
		// offer an enabled Generate and hide the destructive-regen warning.
		if (bd.Matches === undefined && bd._matchesLoadError) {
			container.innerHTML = '';
			var errBox = document.createElement('div');
			errBox.className = 'tn-bv-empty';
			errBox.innerHTML = 'Couldn\'t load matches. ';
			var retryBtn = document.createElement('button');
			retryBtn.type = 'button';
			retryBtn.className = 'tn-btn tn-btn-outline tn-btn-sm';
			retryBtn.innerHTML = '<i class="fas fa-redo"></i> Retry';
			retryBtn.onclick = function() { bd._matchesLoaded = false; bd._matchesLoadError = false; tnRenderBracketViz(bracketId); };
			errBox.appendChild(retryBtn);
			container.appendChild(errBox);
			return;
		}

		var bracket      = bd.Bracket;
		var matches      = bd.Matches  || [];
		var participants = bd.Participants || [];
		var method       = bracket.Method;

		// Participant lookup by id
		var pMap = {};
		participants.forEach(function(p) { pMap[p.ParticipantId] = p; });
		// Quick Bracket draft: an empty seeded draw filled in place (script/tournament-quickbracket.js).
		if (window.TnQuickBracket && window.TnQuickBracket.isDraft(bd)) {
			window.TnQuickBracket.renderDraft(container, bd, bracketId, function(c, ms, pm) { renderElimTree(c, ms, pm, method, bracketId); });
			return;
		}

		// Generate button bar
		if (TnConfig.canManage) {
			var bar = document.createElement('div');
			bar.className = 'tn-bv-generate-bar';
			var statusBadge = document.createElement('span');
			statusBadge.className = 'tn-bv-status-badge tn-bv-status-' + (bracket.Status || 'setup');
			statusBadge.textContent = (bracket.Status || 'setup').charAt(0).toUpperCase() + (bracket.Status || 'setup').slice(1);
			bar.appendChild(statusBadge);
			var label = document.createElement('span');
			label.style.cssText = 'font-size:13px;color:#4a5568;flex:1';
			label.textContent = tnBracketLabel(bracket) + ' — ' + (TnConfig.methodLabels[bracket.Method] || bracket.Method);
			bar.appendChild(label);
			if (matches.length > 0) {
				var resolvable = matches.filter(function(m) { return parseInt(m.Participant1Id) > 0 && parseInt(m.Participant2Id) > 0; }).length;
				// Count as resolved ONLY contestable matches (both participants) that carry a
				// result — excluding byes/auto-advanced walkovers so the numerator can never
				// exceed the denominator (was "7/5"; now "5/5 complete").
				var resolved   = matches.filter(function(m) { return m.Result && m.Result !== '' && parseInt(m.Participant1Id) > 0 && parseInt(m.Participant2Id) > 0; }).length;
				var ready      = resolvable - resolved;
				var progInfo = document.createElement('span');
				progInfo.className = 'tn-bv-progress-info';
				if (method === 'ironman') {
					// Ironman fights have participant_2_id = 0, so resolvable === 0 and an
					// "X/Y complete" ratio is meaningless ("3/0"). Show a fight count instead.
					var recorded = matches.filter(function(m) { return m.Result && m.Result !== ''; }).length;
					progInfo.textContent = recorded + ' fight' + (recorded === 1 ? '' : 's') + ' recorded';
				} else {
					progInfo.innerHTML = resolved + '/' + resolvable + ' complete' + (ready > 0 ? ' &middot; <span class="tn-bv-pi-ready">' + ready + ' ready</span>' : '');
				}
				bar.appendChild(progInfo);
			}
			// No Generate/Regenerate for points brackets (no draw) or once the bracket is
			// complete/finalized — matches the Brackets-tab card's server-side gate.
			// Generate only in setup; Regenerate only for an active bracket with a draw.
			if (participants.length >= 2 && method !== 'ironman' && method !== 'points'
				&& (!bracket.Status || bracket.Status === 'setup' || (bracket.Status === 'active' && matches.length > 0))) {
				var isRegenerate = matches.length > 0;
				var hasCompleted = isRegenerate && (typeof resolved !== 'undefined') && resolved > 0;
				var genBtn = document.createElement('button');
				genBtn.className = 'tn-btn tn-btn-primary tn-btn-sm';
				genBtn.innerHTML = '<i class="fas fa-play"></i> ' + (isRegenerate ? 'Regenerate' : 'Generate Matches');
				if (hasCompleted) {
					genBtn.disabled = true;
					genBtn.setAttribute('data-tip', 'Cannot regenerate: ' + resolved + ' match' + (resolved === 1 ? '' : 'es') + ' already completed. Reset completed matches first.');
				} else {
					genBtn.onclick = function() { tnGenerateMatches(bracketId, TnConfig.tournamentId); };
				}
				bar.appendChild(genBtn);
			}
			container.appendChild(bar);
		}

		if (matches.length === 0 && method !== 'ironman' && method !== 'points') {
			var empty = document.createElement('div');
			empty.className = 'tn-bv-empty';
			empty.textContent = participants.length < 2
				? 'Add at least 2 participants to generate a bracket.'
				: (!bracket.Status || bracket.Status === 'setup')
					? 'No matches yet. Click "Generate Matches" to build the bracket.'
					: 'No matches yet.';
			container.appendChild(empty);
			return;
		}

		// Route to correct renderer
		if (method === 'single' || method === 'double') {
			renderElimTree(container, matches, pMap, method, bracketId);
		} else if (method === 'ironman') {
			renderIronmanView(container, matches, pMap, participants, bracketId);
		} else if (method === 'points') {
			renderPointsView(container, bd, bracketId);
		} else {
			renderRoundTable(container, matches, pMap, bracketId);
		}
	};

	// -- Points-bracket renderer --
	// Builds the same grid the PHP fallback renders (see template branch for Method===points),
	// so the delegated pip-click and input-blur handlers attach without changes.
	function renderPointsView(container, bd, bracketId) {
		var bracket = bd.Bracket || {};
		var pmode   = bracket.PointMode || 'fixed';
		var rounds  = parseInt(bracket.PointRounds || 0, 10) || 0;
		var scaleRaw = String(bracket.PointScale || '');
		var scale   = (pmode === 'fixed' && scaleRaw)
			? scaleRaw.split(',').map(function(s){ return s.trim(); }).filter(Boolean)
			: [];
		var standings = bd.PointStandings || [];
		// A finalized or not-yet-generated (setup) points grid is read-only (the server rejects the save anyway).
		var canEdit = !!(TnConfig.canManage || TnConfig.isBracketRunner || TnConfig.isOrganizerReeve) && bracket.Status !== 'finalized' && (bracket.Status || 'setup') !== 'setup';
		var isActive = (bracket.Status || 'setup') === 'active';

		var wrap = document.createElement('div');
		wrap.className = 'tn-points-wrap';
		wrap.dataset.bid = bracketId;
		wrap.dataset.mode = pmode;
		wrap.dataset.scale = scaleRaw;
		wrap.dataset.rounds = rounds;

		// Ribbon
		var ribbon = document.createElement('div');
		ribbon.className = 'tn-points-ribbon';
		var topN = 0;
		var ribbonHtml = '';
		// Until a score is entered every row sits at 0.00 — show the empty state, not a tie.
		var anyScored = standings.some(function(r) {
			return (r.RoundScores || []).some(function(v) { return v != null && v !== ''; });
		});
		for (var k = 0; anyScored && k < standings.length && topN < 5; k++) {
			var row = standings[k];
			if (row.Status !== 'active' && row.Status !== '') continue;
			ribbonHtml += '<span class="tn-points-rib-item"><strong>' +
				(row.Tied ? 'T-' : '') + (row.Place == null ? '' : row.Place) + '</strong> ' +
				escapeHtml(row.Alias) + ' (' + escapeHtml(row.Total) + ')</span>';
			topN++;
		}
		if (topN === 0) ribbonHtml = '<span style="color:#a0aec0;font-size:13px">No scores yet.</span>';
		ribbon.innerHTML = ribbonHtml;
		wrap.appendChild(ribbon);

		// Points brackets have no final match, so finishing one is an explicit step.
		if (isActive && (TnConfig.canManage || TnConfig.isOrganizerReeve)) {
			var finBar = document.createElement('div');
			finBar.style.cssText = 'display:flex;justify-content:flex-end;margin:0 0 8px';
			finBar.innerHTML = '<button type="button" class="tn-btn tn-btn-primary tn-btn-sm" onclick="tnFinalizePointsBracket(' + bracketId + ')" data-tip="Lock scoring and record the final standings" data-tip-right><i class="fas fa-flag-checkered"></i> Finalize bracket</button>';
			wrap.appendChild(finBar);
		}

		// Table
		var scroll = document.createElement('div');
		scroll.className = 'tn-points-grid-scroll';
		var table = document.createElement('table');
		table.className = 'tn-points-grid';

		// Header
		var thead = document.createElement('thead');
		var trh = document.createElement('tr');
		trh.innerHTML = '<th class="tn-points-col-player">Player</th>';
		for (var r = 1; r <= rounds; r++) {
			trh.innerHTML += '<th class="tn-points-col-round">R' + r + '</th>';
		}
		var addColShown = canEdit && isActive;
		if (addColShown) {
			trh.innerHTML += '<th class="tn-points-col-add"><button type="button" class="tn-btn tn-btn-sm tn-btn-outline" onclick="tnPointsAddRound(' + bracketId + ', this)" data-tip="Add another round">+</button></th>';
		}
		trh.innerHTML += '<th class="tn-points-col-total">Total</th>';
		thead.appendChild(trh);
		table.appendChild(thead);

		// Body
		var tbody = document.createElement('tbody');
		if (standings.length === 0) {
			var tr = document.createElement('tr');
			var colspan = 2 + rounds + (addColShown ? 1 : 0);
			tr.innerHTML = '<td colspan="' + colspan + '" style="text-align:center;color:#a0aec0;padding:16px">No participants yet.</td>';
			tbody.appendChild(tr);
		} else {
			standings.forEach(function(row){
				var inactive = (row.Status !== 'active' && row.Status !== '');
				var tr = document.createElement('tr');
				tr.dataset.pid = row.ParticipantId;
				if (inactive) tr.className = 'tn-points-row-inactive';

				var html = '<td class="tn-points-col-player">#' + (row.ParticipantNumber || '') + ' ' + escapeHtml(row.Alias) + '</td>';
				for (var r = 1; r <= rounds; r++) {
					var val = (row.RoundScores && row.RoundScores[r-1] != null) ? String(row.RoundScores[r-1]) : '';
					html += '<td class="tn-points-cell" data-pid="' + row.ParticipantId + '" data-round="' + r + '" data-value="' + escapeHtml(val) + '">';
					if (!canEdit) {
						html += '<span class="tn-points-readonly">' + (val !== '' ? escapeHtml(val) : '-') + '</span>';
					} else if (pmode === 'fixed') {
						html += '<div class="tn-pips">';
						scale.forEach(function(sv){
							var selected = (val !== '' && parseFloat(val) === parseFloat(sv));
							html += '<span class="tn-pip' + (selected ? ' tn-pip-selected' : '') + '" data-val="' + escapeHtml(sv) + '">' + escapeHtml(sv) + '</span>';
						});
						html += '</div>';
					} else {
						html += '<input type="text" class="tn-points-input" inputmode="decimal" maxlength="5" value="' + escapeHtml(val) + '">';
					}
					html += '<span class="tn-points-status" aria-hidden="true"></span></td>';
				}
				if (addColShown) html += '<td class="tn-points-col-add">&nbsp;</td>';
				html += '<td class="tn-points-col-total">' + escapeHtml(row.Total || '0.00') + '</td>';
				tr.innerHTML = html;
				tbody.appendChild(tr);
			});
		}
		table.appendChild(tbody);
		scroll.appendChild(table);
		wrap.appendChild(scroll);

		container.appendChild(wrap);
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
			return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
		});
	}

	// ── Elimination tree renderer ──
	function renderElimTree(container, matches, pMap, method, bracketId) {
		// Fixed scroll viewport; the inner wrap is what we zoom so more bouts fit.
		var viewport = document.createElement('div');
		viewport.className = 'tn-bv-viewport';
		container.appendChild(viewport);
		var wrap = document.createElement('div');
		wrap.className = 'tn-bv-wrap';
		viewport.appendChild(wrap);

		// Zoom controls — persisted per bracket so collab re-renders don't reset it.
		var _zoomKey = 'tnZoom_' + (bracketId || 0);
		var zoomLevel = 100;
		try { var _zSaved = parseInt(sessionStorage.getItem(_zoomKey), 10); if (_zSaved >= 40 && _zSaved <= 150) zoomLevel = _zSaved; } catch (e) {}
		var zoomWrap = document.createElement('div');
		zoomWrap.className = 'tn-bv-zoom-controls';
		var zoomOut = document.createElement('button');
		zoomOut.className = 'tn-bv-zoom-btn'; zoomOut.innerHTML = '&minus;'; zoomOut.setAttribute('data-tip', 'Zoom out');
		var zoomIn = document.createElement('button');
		zoomIn.className = 'tn-bv-zoom-btn'; zoomIn.innerHTML = '&plus;'; zoomIn.setAttribute('data-tip', 'Zoom in');
		var zoomReset = document.createElement('button');
		zoomReset.className = 'tn-bv-zoom-btn'; zoomReset.innerHTML = '<i class="fas fa-compress-arrows-alt" style="font-size:11px"></i>'; zoomReset.setAttribute('data-tip', 'Reset zoom');
		var zoomLabel = document.createElement('span');
		zoomLabel.className = 'tn-bv-zoom-level'; zoomLabel.textContent = '100%';
		function applyZoom() {
			zoomLabel.textContent = zoomLevel + '%';
			// CSS zoom shrinks the content's layout box, so the fixed viewport shows
			// more of the bracket (transform:scale only shrinks visually, not layout).
			wrap.style.zoom = (zoomLevel / 100);
			try { sessionStorage.setItem(_zoomKey, String(zoomLevel)); } catch (e) {}
		}
		zoomOut.onclick = function() { zoomLevel = Math.max(40, zoomLevel - 10); applyZoom(); };
		zoomIn.onclick = function() { zoomLevel = Math.min(150, zoomLevel + 10); applyZoom(); };
		zoomReset.onclick = function() { zoomLevel = 100; applyZoom(); };
		zoomWrap.appendChild(zoomOut);
		zoomWrap.appendChild(zoomLabel);
		zoomWrap.appendChild(zoomIn);
		zoomWrap.appendChild(zoomReset);
		container.insertBefore(zoomWrap, viewport);
		applyZoom();   // restore persisted zoom on (re-)render

		// Separate sections: winners, losers, grand-final
		var sections = [
			{ key:'winners',     label:'Winners Bracket' },
			{ key:'losers',      label:'Second Chance Bracket' },
			{ key:'grand-final', label:'Grand Final' },
			
		];

		var hasSections = matches.some(function(m) { return m.BracketSide && m.BracketSide !== 'winners' && m.BracketSide !== 'tiebreaker-3rd'; });

		if (!hasSections) {
			// Single section
			renderSection(wrap, matches, pMap, null);
		} else {
			sections.forEach(function(s) {
				var sMatches = matches.filter(function(m) { var side = m.BracketSide || 'winners'; return side === s.key || (s.key === 'winners' && side === 'tiebreaker-3rd'); });
				if (!sMatches.length) return;
				var iconMap = {'winners':'fa-trophy','losers':'fa-shield-alt','grand-final':'fa-star','tiebreaker-3rd':'fa-medal'};
				var lbl = document.createElement('div');
				lbl.className = 'tn-bv-section-hdr ' + s.key;
				lbl.innerHTML = '<i class="fas ' + (iconMap[s.key] || 'fa-circle') + '"></i> ' + s.label;
				wrap.appendChild(lbl);
				if (s.key === 'losers') {
					var losersDiv = document.createElement('div');
					losersDiv.className = 'tn-bv-losers-compact';
					wrap.appendChild(losersDiv);
					renderSection(losersDiv, sMatches, pMap, s.key);
				} else {
					renderSection(wrap, sMatches, pMap, s.key);
				}
			});
		}

		// Champion / podium callout when bracket is complete/finalized
		var bd0 = TnConfig.bracketData[bracketId];
		var bracketSt = bd0 && bd0.Bracket ? (bd0.Bracket.Status || '') : '';
		if (bracketSt === 'complete' || bracketSt === 'finalized') {
			var finalMatches = matches.filter(function(m) {
				var side = m.BracketSide || 'winners';
				return side === 'grand-final' || (side === 'winners' && !hasSections);
			});
			if (!finalMatches.length) finalMatches = matches.filter(function(m){ return (m.BracketSide||'winners')==='winners'; });
			var maxFR = 0;
			finalMatches.forEach(function(m){ var rr = parseInt(m.Round)||0; if(rr>maxFR) maxFR=rr; });
			var finalMatch = finalMatches.filter(function(m){ return (parseInt(m.Round)||0)===maxFR && m.Result; })[0];
			if (finalMatch) {
				var _fSide = tnWinnerSide(finalMatch.Result);
				var champId = (_fSide === 1) ? parseInt(finalMatch.Participant1Id) : (_fSide === 2) ? parseInt(finalMatch.Participant2Id) : 0;
				var runnerUpId = (_fSide === 1) ? parseInt(finalMatch.Participant2Id) : (_fSide === 2) ? parseInt(finalMatch.Participant1Id) : 0;
				var champ = champId ? (pMap[champId]||null) : null;
				var runner = runnerUpId ? (pMap[runnerUpId]||null) : null;
				if (champ) {
					var championBanner = document.createElement('div');
					championBanner.className = 'tn-bv-champion-banner';
					var crownIcon = document.createElement('div');
					crownIcon.className = 'tn-bv-champion-crown';
					crownIcon.innerHTML = '<i class="fas fa-trophy" style="color:#744210;font-size:28px"></i>';
					championBanner.appendChild(crownIcon);
					var champInfo = document.createElement('div');
					champInfo.className = 'tn-bv-champion-info';
					champInfo.innerHTML = '<div class="tn-bv-champion-label">Champion</div>' + '<div class="tn-bv-champion-name">' + tnEscHtml(champ.Alias || champ.Persona || 'Unknown') + '</div>' + (!champ.IsTeam && champ.ParkName ? '<div class="tn-bv-champion-park">' + tnEscHtml(champ.ParkName) + '</div>' : '');
					championBanner.appendChild(champInfo);
					var podium = document.createElement('div');
					podium.className = 'tn-bv-podium';
					var podiumHtml = '<div class="tn-bv-podium-card tn-bv-podium-1st"><span class="tn-bv-podium-num">1st</span> ' + tnEscHtml(champ.Alias || champ.Persona || '?') + '</div>';
					if (runner) podiumHtml += '<div class="tn-bv-podium-card tn-bv-podium-2nd"><span class="tn-bv-podium-num">2nd</span> ' + tnEscHtml(runner.Alias || runner.Persona || '?') + '</div>';
					// 3rd/4th from tiebreaker-3rd match (single elim) or semifinal losers
					var tbMatch = matches.filter(function(m) { return m.BracketSide === 'tiebreaker-3rd' && m.Result; })[0];
					if (tbMatch) {
						var _tSide = tnWinnerSide(tbMatch.Result);
						var thirdId = (_tSide === 1) ? parseInt(tbMatch.Participant1Id) : (_tSide === 2) ? parseInt(tbMatch.Participant2Id) : 0;
						var fourthId = (_tSide === 1) ? parseInt(tbMatch.Participant2Id) : (_tSide === 2) ? parseInt(tbMatch.Participant1Id) : 0;
						var third = thirdId ? (pMap[thirdId] || null) : null;
						var fourth = fourthId ? (pMap[fourthId] || null) : null;
						if (third) podiumHtml += '<div class="tn-bv-podium-card tn-bv-podium-3rd"><span class="tn-bv-podium-num">3rd</span> ' + tnEscHtml(third.Alias || third.Persona || '?') + '</div>';
						if (fourth) podiumHtml += '<div class="tn-bv-podium-card" style="background:#f7fafc;color:#718096;border:1px solid #e2e8f0"><span class="tn-bv-podium-num">4th</span> ' + tnEscHtml(fourth.Alias || fourth.Persona || '?') + '</div>';
					}
					podium.innerHTML = podiumHtml;
					championBanner.appendChild(podium);
					wrap.insertBefore(championBanner, wrap.firstChild);
				}
			}
		}

		// ── Grand Final confirmation match banner + inline buttons ────────────
		// Show when: double-elim, GF round-1 decided with '2-wins' (LB winner won),
		// no confirmation match yet, and organizer hasn't already waived it.
		if (method === 'double' && TnConfig.canManage) {
			var bd = TnConfig.bracketData[bracketId];
			var bracketStatus = bd && bd.Bracket ? bd.Bracket.Status : '';
			var gfMatches = matches.filter(function(m) { return m.BracketSide === 'grand-final'; });
			var gfR1 = gfMatches.filter(function(m) { return parseInt(m.Round) === 1; });
			var gfR2 = gfMatches.filter(function(m) { return parseInt(m.Round) > 1; });
			var gfResetUnresolved = gfR2.filter(function(m) { return !m.Result || m.Result === ''; });
			if (gfR1.length === 1 && gfR1[0].Result === '2-wins' && bracketStatus !== 'finalized' && gfResetUnresolved.length >= 1) {
				// #3: GF2 now auto-creates, so an unresolved Grand-Final reset match
				// already exists. Offer to play it out (existing bracket entry) OR waive
				// the rematch and finalize on the GF1 result (WaiveReset=1 per contract).
				var tid = TnConfig.tournamentId;
				var _gfRefresh = function() {
					var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bracketId) : 0;
					Promise.all([
						fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/matches').then(function(r) { return r.json(); }),
						fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); })
					]).then(function(results) {
						var mData = results[0], bData = results[1];
						var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bracketId, _rt); // a newer fetch of this bracket writes + paints
						if (_rtCur && mData.status === 0 && TnConfig.bracketData[bracketId]) TnConfig.bracketData[bracketId].Matches = mData.matches;
						if (_rtCur && bData.status === 0 && bData.brackets && TnConfig.bracketData[bracketId]) {
							var br = bData.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bracketId); });
							if (br) TnConfig.bracketData[bracketId].Bracket = br;
						}
						if (_rtCur) tnRenderBracketViz(bracketId);
						// Brackets-tab card (status, results table) + stats + standings.
						if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bracketId);
					}).catch(function(err) { window.tnToast('Refresh error: ' + err); });
				};
				// Waiving finalizes on the GF1 result, so the GF1 WINNER (the Second-Chance
				// bracket champion, who took game 1) is crowned — NOT the winners-bracket
				// champion who lost GF1. Name the actual winner so the reeve isn't misled.
				var _winId = (gfR1[0].Result === '2-wins') ? parseInt(gfR1[0].Participant2Id) : parseInt(gfR1[0].Participant1Id);
				var _win = pMap[_winId] || null;
				var _winName = _win ? (_win.Alias || _win.Persona || 'the Second Chance champion') : 'the Second Chance champion';
				var doWaiveReset = function() {
					var fd = new FormData();
					fd.append('BracketId', bracketId);
					fd.append('WaiveReset', '1');
					if (window.tnTagAction) window.tnTagAction(fd);
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/completebracket', { method:'POST', body:fd })
						.then(function(r) { return r.json(); })
						.then(function(d) {
							if (d.status === 0) { _gfRefresh(); }
							else { window.tnToast('Error: ' + (d.error || 'Unknown error')); }
						}).catch(function(err) { window.tnToast('Request failed: ' + err); });
				};
				var rbanner = document.createElement('div');
				rbanner.className = 'tn-gf-confirm-banner';
				rbanner.innerHTML =
					'<div class="tn-gf-confirm-text"><i class="fas fa-exclamation-circle"></i> The Second Chance winner took the Grand Final — a reset match will decide the champion. Play it out, or waive the rematch.</div>' +
					'<div class="tn-gf-confirm-btns">' +
						'<button class="tn-gf-confirm-yes"><i class="fas fa-play-circle"></i> Play reset match</button>' +
						'<button class="tn-gf-confirm-no"><i class="fas fa-flag-checkered"></i> Waive rematch — declare ' + tnEscHtml(_winName) + ' the winner</button>' +
					'</div>';
				wrap.insertBefore(rbanner, wrap.firstChild);
				rbanner.querySelector('.tn-gf-confirm-yes').onclick = function() {
					if (rbanner.parentNode) rbanner.parentNode.removeChild(rbanner);
					var gfCard = wrap.querySelector('[data-matchid="' + gfResetUnresolved[0].MatchId + '"]');
					// Scroll after the banner-removal layout shift settles, not in the same frame.
					if (gfCard && gfCard.scrollIntoView) requestAnimationFrame(function() { gfCard.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' }); });
				};
				rbanner.querySelector('.tn-gf-confirm-no').onclick = doWaiveReset;
			} else if (gfR1.length === 1 && gfR1[0].Result === '2-wins' && gfR2.length === 0 && bracketStatus !== 'finalized') {
				var tid = TnConfig.tournamentId;

				var doConfirmYes = function() {
					var fd = new FormData();
					fd.append('BracketId', bracketId);
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/confirmationmatch', { method:'POST', body:fd })
						.then(function(r) { return r.json(); })
						.then(function(d) {
							if (d.status === 0) {
								var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bracketId) : 0;
								Promise.all([
									fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/matches').then(function(r) { return r.json(); }),
									fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); }),
								]).then(function(results) {
									var mData = results[0], bData = results[1];
									var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bracketId, _rt); // a newer fetch of this bracket writes + paints
									if (_rtCur && mData.status === 0 && TnConfig.bracketData[bracketId]) TnConfig.bracketData[bracketId].Matches = mData.matches;
									if (_rtCur && bData.status === 0 && bData.brackets && TnConfig.bracketData[bracketId]) {
										var br = bData.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bracketId); });
										if (br) TnConfig.bracketData[bracketId].Bracket = br;
									}
									if (_rtCur) tnRenderBracketViz(bracketId);
									if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bracketId);
								}).catch(function(err) { window.tnToast('Refresh error: ' + err); });
							} else {
								window.tnToast('Error: ' + (d.error || 'Unknown error'));
							}
						}).catch(function(err) { window.tnToast('Request failed: ' + err); });
				};

				var doConfirmNo = function() {
					var fd = new FormData();
					fd.append('BracketId', bracketId);
					if (window.tnTagAction) window.tnTagAction(fd);
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/completebracket', { method:'POST', body:fd })
						.then(function(r) { return r.json(); })
						.then(function(d) {
							if (d.status === 0) {
								// The server finalized the bracket; refetch so the Run view, the
								// Brackets-tab card, stats and standings all show the real status.
								tnRefreshAndRender(bracketId);
							} else {
								window.tnToast('Error: ' + (d.error || 'Unknown error'));
							}
						}).catch(function(err) { window.tnToast('Request failed: ' + err); });
				};

				// Yellow banner above the bracket
				var banner = document.createElement('div');
				banner.className = 'tn-gf-confirm-banner';
				banner.innerHTML =
					'<div class="tn-gf-confirm-text"><i class="fas fa-exclamation-circle"></i> The Second Chance winner claimed the Grand Final — the champion may need to lose twice. Is a confirmation match required?</div>' +
					'<div class="tn-gf-confirm-btns">' +
						'<button class="tn-gf-confirm-yes"><i class="fas fa-check-circle"></i> Confirmation Match</button>' +
						'<button class="tn-gf-confirm-no"><i class="fas fa-times-circle"></i> Tournament Complete</button>' +
					'</div>';
				wrap.insertBefore(banner, wrap.firstChild);
				banner.querySelector('.tn-gf-confirm-yes').onclick = doConfirmYes;
				banner.querySelector('.tn-gf-confirm-no').onclick = doConfirmNo;

				// Inline stacked buttons to the right of the GF match card
				var gfBox = wrap.querySelector('[data-matchid="' + gfR1[0].MatchId + '"]');
				if (gfBox && gfBox.parentElement) {
					var btnCol = document.createElement('div');
					btnCol.className = 'tn-gf-inline-btns';
					btnCol.innerHTML =
						'<button class="tn-gf-confirm-yes"><i class="fas fa-check-circle"></i> Confirmation Match</button>' +
						'<button class="tn-gf-confirm-no"><i class="fas fa-times-circle"></i> Tournament Complete</button>';
					gfBox.parentElement.appendChild(btnCol);
					btnCol.querySelector('.tn-gf-confirm-yes').onclick = doConfirmYes;
					btnCol.querySelector('.tn-gf-confirm-no').onclick = doConfirmNo;
				}
			}
		}

		// ── Single-elim 3rd place tiebreaker banner ─────────────────────────────
		// Show as soon as the finals pairing is set (both semifinals resolved) so the
		// organizer can bracket out the 3rd/4th fight while the finals are still pending.
		// The "Tournament Complete" (waive) button only appears once the bracket is
		// actually complete; requires ≥2 rounds (semis exist), no tiebreaker-3rd match
		// yet, and bracket not finalized.
		if (method === 'single' && TnConfig.canManage) {
			var bd2 = TnConfig.bracketData[bracketId];
			var bracketStatus2 = bd2 && bd2.Bracket ? bd2.Bracket.Status : '';
			var hasTiebreaker = matches.some(function(m) { return m.BracketSide === 'tiebreaker-3rd'; });
			var maxWRRound = 0;
			matches.forEach(function(m) {
				if ((m.BracketSide || 'winners') === 'winners') maxWRRound = Math.max(maxWRRound, parseInt(m.Round) || 0);
			});
			var finalsReady = matches.some(function(m) {
				return (m.BracketSide || 'winners') === 'winners' && (parseInt(m.Round) || 0) === maxWRRound
					&& parseInt(m.Participant1Id) > 0 && parseInt(m.Participant2Id) > 0;
			});
			var bracketDone = (bracketStatus2 === 'complete');
			if ((bracketDone || finalsReady) && bracketStatus2 !== 'finalized' && !hasTiebreaker && maxWRRound >= 2) {
				var tid2 = TnConfig.tournamentId;

				var doTiebreakerYes = function() {
					var fd = new FormData();
					fd.append('BracketId', bracketId);
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid2 + '/tiebreakerfor3rd', { method:'POST', body:fd })
						.then(function(r) { return r.json(); })
						.then(function(d) {
							if (d.status === 0) {
								var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bracketId) : 0;
								Promise.all([
									fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/matches').then(function(r) { return r.json(); }),
									fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid2 + '/brackets').then(function(r) { return r.json(); }),
								]).then(function(results) {
									var mData = results[0], bData = results[1];
									var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bracketId, _rt); // a newer fetch of this bracket writes + paints
									if (_rtCur && mData.status === 0 && TnConfig.bracketData[bracketId]) TnConfig.bracketData[bracketId].Matches = mData.matches;
									if (_rtCur && bData.status === 0 && bData.brackets && TnConfig.bracketData[bracketId]) {
										var br = bData.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bracketId); });
										if (br) TnConfig.bracketData[bracketId].Bracket = br;
									}
									if (_rtCur) tnRenderBracketViz(bracketId);
									if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bracketId);
								}).catch(function(err) { window.tnToast('Refresh error: ' + err); });
							} else {
								window.tnToast('Error: ' + (d.error || 'Unknown error'));
							}
						}).catch(function(err) { window.tnToast('Request failed: ' + err); });
				};

				var doTiebreakerNo = function() {
					var fd = new FormData();
					fd.append('BracketId', bracketId);
					if (window.tnTagAction) window.tnTagAction(fd);
					fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid2 + '/completebracket', { method:'POST', body:fd })
						.then(function(r) { return r.json(); })
						.then(function(d) {
							if (d.status === 0) {
								// The server finalized the bracket; refetch so the Run view, the
								// Brackets-tab card, stats and standings all show the real status.
								tnRefreshAndRender(bracketId);
							} else {
								window.tnToast('Error: ' + (d.error || 'Unknown error'));
							}
						}).catch(function(err) { window.tnToast('Request failed: ' + err); });
				};

				// Yellow banner above the bracket
				var tbBanner = document.createElement('div');
				tbBanner.className = 'tn-gf-confirm-banner';
				var tbText = bracketDone
					? 'The bracket is complete — the two semifinal runners-up are tied for 3rd place. Is a tiebreaker match needed?'
					: 'The finals are set — the two semifinal runners-up are tied for 3rd place. Bracket out a 3rd/4th place match?';
				tbBanner.innerHTML =
					'<div class="tn-gf-confirm-text"><i class="fas fa-medal"></i> ' + tbText + '</div>' +
					'<div class="tn-gf-confirm-btns">' +
						'<button class="tn-gf-confirm-yes"><i class="fas fa-check-circle"></i> Tiebreaker for 3rd</button>' +
						(bracketDone ? '<button class="tn-gf-confirm-no"><i class="fas fa-times-circle"></i> Tournament Complete</button>' : '') +
					'</div>';
				wrap.insertBefore(tbBanner, wrap.firstChild);
				tbBanner.querySelector('.tn-gf-confirm-yes').onclick = doTiebreakerYes;
				var tbNoBtn = tbBanner.querySelector('.tn-gf-confirm-no');
				if (tbNoBtn) tbNoBtn.onclick = doTiebreakerNo;
			}
		}
	}

	function tnDrawBracketConnectors(tree, rounds, maxRound) {
		if (maxRound < 2) return;
		function draw() {
			var _old = tree.querySelector(':scope > svg.tn-bv-connectors');
			if (_old && _old.parentNode) _old.parentNode.removeChild(_old);
			var treeRect = tree.getBoundingClientRect();
			if (!treeRect.width) return;

			var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:0';
			svg.classList.add('tn-bv-connectors');

			// One-pass index of matchId → element to avoid O(matches) attribute-selector queries per pair
			var matchBoxByMid = {};
			tree.querySelectorAll('[data-matchid]').forEach(function(el) { matchBoxByMid[el.getAttribute('data-matchid')] = el; });

			// PASS 1 — gather all pairs and read every getBoundingClientRect
			// up front so the path-building pass triggers zero forced reflows.
			var pairs = [];
			for (var r = 1; r < maxRound; r++) {
				var srcRound = (rounds[r] || []).slice().sort(function(a,b){ return (a.Order||0)-(b.Order||0); });
				var dstRound = (rounds[r+1] || []).slice().sort(function(a,b){ return (a.Order||0)-(b.Order||0); });
				for (var i = 0; i < srcRound.length; i += 2) {
					var m1   = srcRound[i];
					var m2   = srcRound[i+1] || null;
					var mDst = dstRound[Math.floor(i/2)] || null;
					if (!mDst) continue;
					var box1   = matchBoxByMid[m1.MatchId];
					var box2   = m2  ? matchBoxByMid[m2.MatchId]  : null;
					var boxDst = matchBoxByMid[mDst.MatchId];
					if (!box1 || !boxDst) continue;
					pairs.push({
						m1: m1, m2: m2, mDst: mDst,
						r1: box1.getBoundingClientRect(),
						r2: box2 ? box2.getBoundingClientRect() : null,
						rDst: boxDst.getBoundingClientRect()
					});
				}
			}

			// Orthogonal connector arm: horizontal stub from (fx,fy) to column x=cx,
			// a rounded corner, then a straight vertical to y=ty. Reads as a clean
			// bracket line at any vertical span (curvy beziers looked like glitches
			// on the long later-round arms).
			function elbow(fx, fy, cx, ty) {
				var dx = cx - fx, dy = ty - fy;
				if (Math.abs(dy) < 0.5) return 'M' + fx + ',' + fy + ' L' + cx + ',' + ty;
				var r = Math.min(8, Math.abs(dx), Math.abs(dy));
				var hs = dx >= 0 ? 1 : -1, vs = dy >= 0 ? 1 : -1;
				return 'M' + fx + ',' + fy + ' L' + (cx - hs*r) + ',' + fy +
				       ' Q' + cx + ',' + fy + ' ' + cx + ',' + (fy + vs*r) + ' L' + cx + ',' + ty;
			}

			// PASS 2 — build SVG paths from the cached rects (no layout reads)
			pairs.forEach(function(pr) {
					var m1   = pr.m1;
					var m2   = pr.m2;
					var r1   = pr.r1;
					var rDst = pr.rDst;
					var box2 = pr.r2;
					var x1   = r1.right   - treeRect.left;
					var y1   = r1.top     - treeRect.top  + r1.height / 2;
					var xDst = rDst.left  - treeRect.left;
					var yDst = rDst.top   - treeRect.top  + rDst.height / 2;
					var xMid = (x1 + xDst) / 2;

					var m1Resolved = m1.Result && m1.Result !== '';
					var m2Resolved = m2 && m2.Result && m2.Result !== '';

					if (box2) {
						var r2 = box2;
						var x2 = r2.right - treeRect.left;
						var y2 = r2.top - treeRect.top + r2.height / 2;
						var yMid = (y1 + y2) / 2;

						var color1 = m1Resolved ? '#48bb78' : '#cbd5e0';
						var p1 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
						p1.setAttribute('d', elbow(x1, y1, xMid, yMid));
						p1.setAttribute('stroke', color1); p1.setAttribute('stroke-width', m1Resolved ? '2.5' : '1.5');
						p1.setAttribute('fill', 'none'); p1.setAttribute('stroke-linecap', 'round');
						svg.appendChild(p1);

						var color2 = m2Resolved ? '#48bb78' : '#cbd5e0';
						var p2 = document.createElementNS('http://www.w3.org/2000/svg', 'path');
						p2.setAttribute('d', elbow(x2, y2, xMid, yMid));
						p2.setAttribute('stroke', color2); p2.setAttribute('stroke-width', m2Resolved ? '2.5' : '1.5');
						p2.setAttribute('fill', 'none'); p2.setAttribute('stroke-linecap', 'round');
						svg.appendChild(p2);

						var colorC = (m1Resolved && m2Resolved) ? '#48bb78' : '#cbd5e0';
						var pC = document.createElementNS('http://www.w3.org/2000/svg', 'path');
						pC.setAttribute('d', 'M'+xMid+','+yMid+' L'+xDst+','+yDst);
						pC.setAttribute('stroke', colorC); pC.setAttribute('stroke-width', (m1Resolved && m2Resolved) ? '2.5' : '1.5');
						pC.setAttribute('fill', 'none'); pC.setAttribute('stroke-linecap', 'round');
						svg.appendChild(pC);
					} else {
						var colorS = m1Resolved ? '#48bb78' : '#cbd5e0';
						var pS = document.createElementNS('http://www.w3.org/2000/svg', 'path');
						pS.setAttribute('d', elbow(x1, y1, xMid, yDst) + ' L'+xDst+','+yDst);
						pS.setAttribute('stroke', colorS); pS.setAttribute('stroke-width', m1Resolved ? '2.5' : '1.5');
						pS.setAttribute('fill', 'none'); pS.setAttribute('stroke-linecap', 'round');
						svg.appendChild(pS);
					}
			});
			tree.insertBefore(svg, tree.firstChild);
		}
		requestAnimationFrame(draw);
		// Reactive connectors: a one-shot draw goes stale the moment the layout
		// reflows (window/container resize, sidebar, zoom reflow, late font load),
		// which is the classic 'connectors drift' bug. Bind the redraw to the live
		// layout via ResizeObserver so the SVG link layer always tracks the boxes.
		if (window.ResizeObserver) {
			if (tree._tnConnObs) tree._tnConnObs.disconnect();
			var _pending = false;
			tree._tnConnObs = new ResizeObserver(function() {
				if (_pending) return; _pending = true;
				requestAnimationFrame(function() { _pending = false; draw(); });
			});
			tree._tnConnObs.observe(tree);
			var _vp = tree.closest('.tn-bv-viewport');
			if (_vp) tree._tnConnObs.observe(_vp);
		}
	}
	function isMatchResettable(m, allMatches) {
		var bid = m.BracketId;
		var bd  = TnConfig.bracketData[bid];
		var method = bd && bd.Bracket ? bd.Bracket.Method : '';
		if (method !== 'single' && method !== 'double') return true;
		// Use the FULL bracket's matches (not the section-local list) so a
		// downstream match in another DE section (e.g. loser routed to LB)
		// is visible and correctly blocks reset.
		var fullMatches = (bd && bd.Matches) ? bd.Matches : allMatches;
		var p1 = parseInt(m.Participant1Id) || 0;
		var p2 = parseInt(m.Participant2Id) || 0;
		var r  = parseInt(m.Round);
		// Mirror the server's ResetMatch guards (class.Tournament.php resetMatchInternal):
		//  1. a later-round result on the SAME bracket_side involving either fighter;
		//  2. (double) a played Grand Final involving either fighter, unless this IS the GF;
		//  3. (winners side) any played tiebreaker round.
		//  4. (double, winners side) the loser was routed to the losers bracket and has
		//     already played there: the server only clears that LB slot while it is
		//     unplayed, so resetting would orphan the loser in a played LB match.
		var side = m.BracketSide || '';
		var res  = m.Result || '';
		var loserId = 0;
		if (['1-wins', '2-forfeits', '2-is-disqualified'].indexOf(res) !== -1) loserId = p2;
		else if (['2-wins', 'forfeit', 'disqualified', '1-forfeits', '1-is-disqualified'].indexOf(res) !== -1) loserId = p1;
		var involves = function(dm) {
			var d1 = parseInt(dm.Participant1Id) || 0;
			var d2 = parseInt(dm.Participant2Id) || 0;
			return (p1 && (d1 === p1 || d2 === p1)) || (p2 && (d1 === p2 || d2 === p2));
		};
		return !fullMatches.some(function(dm) {
			if (!dm.Result) return false;
			var dside = dm.BracketSide || '';
			if (dside === side && parseInt(dm.Round) > r && involves(dm)) return true;
			if (method === 'double' && side !== 'grand-final' && dside === 'grand-final' && involves(dm)) return true;
			if (side === 'winners' && dside === 'tiebreaker') return true;
			if (method === 'double' && side === 'winners' && loserId && dside === 'losers') {
				if ((parseInt(dm.Participant1Id) || 0) === loserId || (parseInt(dm.Participant2Id) || 0) === loserId) return true;
			}
			return false;
		});
	}
	// #35: expose the resettability test so the shared "undo" result toast (defined
	// near tnToast, outside this IIFE) can decide whether to offer an Undo affordance.
	window.tnIsMatchResettable = function(m) { return isMatchResettable(m, (m && m.BracketId && TnConfig.bracketData[m.BracketId] && TnConfig.bracketData[m.BracketId].Matches) || []); };

	// Should the 'Play-In' first-round option be offered for this bracket?
	// True only for single/double elim where the field is not a power of two AND
	// contested round-1 matches (N - P/2) are fewer than half the round-1 slots
	// (P/4) -- i.e. byes outnumber real matches at least 2:1. See spec table.
	// Attached to window so the edit-bracket modal code (a separate IIFE) can call it.
	window.tnShouldOfferPlayIn = function(method, n) {
		n = parseInt(n) || 0;
		if (method !== 'single' && method !== 'double') return false;
		if (n < 3) return false;
		var P = 1; while (P < n) P *= 2;          // next power of two >= n
		if (P === n) return false;                 // power of two -> no byes
		var contested = n - P / 2;
		return contested < P / 4;
	};

	function renderSection(wrap, matches, pMap, side) {
		// Group by round
		var rounds = {};
		var maxRound = 0;
		matches.forEach(function(m) {
			var r = parseInt(m.Round) || 1;
			if (!rounds[r]) rounds[r] = [];
			rounds[r].push(m);
			if (r > maxRound) maxRound = r;
		});

		// Play-in presentation: only for single/double-elim winners side, when the
		// bracket's FirstRoundMode is 'play-in' AND round 1 actually has bye matches.
		var _playIn = false;
		(function(){
			if (side !== null && side !== 'winners') return;
			var bid = matches.length ? matches[0].BracketId : null;
			var bd = (TnConfig.bracketData || {})[bid];
			if (!bd || !bd.Bracket) return;
			var method = bd.Bracket.Method;
			if (method !== 'single' && method !== 'double') return;
			if (bd.Bracket.FirstRoundMode !== 'play-in') return;
			var r1 = (rounds[1] || []);
			var hasBye = r1.some(function(m){
				var a = parseInt(m.Participant1Id) || 0, b = parseInt(m.Participant2Id) || 0;
				return (!a && b) || (a && !b);
			});
			_playIn = hasBye;
		})();

		var tree = document.createElement('div');
		tree.className = 'tn-bv-tree';

		for (var r = 1; r <= maxRound; r++) {
			var rMatches = (rounds[r] || []).sort(function(a,b) { return (a.Order||0)-(b.Order||0); });
			if (rMatches.length === 0) continue; // Skip empty rounds (e.g. tiebreaker-3rd has only one round)
			var col = document.createElement('div');
			col.className = 'tn-bv-round';
			var lbl = document.createElement('div');
			lbl.className = 'tn-bv-round-label';
			if (_playIn && r === 1) {
				lbl.textContent = 'Play-In';
			} else if (side === 'grand-final') {
				lbl.textContent = 'Grand Final';
			} else if (maxRound === 1) {
				lbl.textContent = 'Final';
			} else if (r === maxRound) {
				lbl.textContent = 'Final';
			} else if (r === maxRound - 1) {
				lbl.textContent = 'Semifinal';
			} else if (r === maxRound - 2) {
				lbl.textContent = 'Quarterfinal';
			} else {
				lbl.textContent = 'Round ' + r;
			}
			col.appendChild(lbl);

			var body = document.createElement('div');
			body.className = 'tn-bv-round-body';
			rMatches.forEach(function(m) {
				if (_playIn && r === 1) {
					var a = parseInt(m.Participant1Id) || 0, b = parseInt(m.Participant2Id) || 0;
					if ((!a && b) || (a && !b)) {
						// Bye match: render an invisible spacer that preserves the slot
						// position (and thus connector alignment) without drawing a box
						// or a connector line (no data-matchid -> connector skips it).
						var sp = buildMatchBox(m, pMap, matches);
						sp.removeAttribute('data-matchid');
						sp.style.visibility = 'hidden';
						sp.style.pointerEvents = 'none';
						sp.setAttribute('aria-hidden', 'true');
						body.appendChild(sp);
						return;
					}
				}
				body.appendChild(buildMatchBox(m, pMap, matches));
			});
			col.appendChild(body);
			tree.appendChild(col);
		}
		wrap.appendChild(tree);
		tnDrawBracketConnectors(tree, rounds, maxRound);
	}

	// Avatar color palette (stable per participant id)
	var _tnAvatarColors = ['#276749','#2b6cb0','#6b46c1','#c05621','#b83280','#2c7a7b','#744210','#2d3748','#9b2c2c','#1a365d'];
	function tnAvatarColor(pid) { return _tnAvatarColors[(parseInt(pid)||0) % _tnAvatarColors.length]; }
	function tnInitials(name) { if (!name) return '?'; var parts = name.trim().split(/\s+/); return parts.length > 1 ? (parts[0][0]+parts[parts.length-1][0]).toUpperCase() : name.substring(0,2).toUpperCase(); }

	// Tooltip singleton
	var _tnTooltipEl = null;
	function tnShowTooltip(e, html) {
		if (!_tnTooltipEl) { _tnTooltipEl = document.createElement('div'); _tnTooltipEl.className = 'tn-bv-tooltip'; document.body.appendChild(_tnTooltipEl); }
		_tnTooltipEl.innerHTML = html;
		_tnTooltipEl.classList.add('tn-bv-tooltip-show');
		var x = e.clientX + 12, y = e.clientY + 12;
		if (x + 260 > window.innerWidth) x = e.clientX - 270;
		if (y + 100 > window.innerHeight) y = e.clientY - 110;
		_tnTooltipEl.style.left = x + 'px'; _tnTooltipEl.style.top = y + 'px';
	}
	function tnHideTooltip() { if (_tnTooltipEl) _tnTooltipEl.classList.remove('tn-bv-tooltip-show'); }
	// Exposed so opening any modal (tnOpenModal) or Record Result can dismiss a lingering hover card.
	window.tnHideTooltip = tnHideTooltip;
	function tnEscHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	function buildMatchBox(m, pMap, sectionMatches) {
		if (m._qbDraft && window.TnQuickBracket) return window.TnQuickBracket.buildDraftBox(m);
		var p1Id = parseInt(m.Participant1Id) || 0;
		var p2Id = parseInt(m.Participant2Id) || 0;
		var p1   = p1Id ? (pMap[p1Id] || null) : null;
		var p2   = p2Id ? (pMap[p2Id] || null) : null;
		// Only show the seed badge if at least one participant in this bracket is
		// actually seeded — otherwise it's just a column of "?" noise.
		var anySeed = false;
		for (var _spid in pMap) { if (pMap[_spid] && (parseInt(pMap[_spid].Seed) || 0) > 0) { anySeed = true; break; } }
		var hasResult = m.Result && m.Result !== '';
		var isClickable = !hasResult && p1 && p2 && TnConfig.canRecordResult;

		// An empty slot is a true bye only in round 1 (not the Grand Final / 3rd-place
		// match, which are fed from elsewhere) or when its elimination feeder is itself
		// an empty bye. Otherwise the feeder is still pending: 'Awaiting Rd N', not 'Bye'.
		// Round-robin / swiss (and other non-elimination) byes are stored as real rows
		// in any round, so an empty slot there is always a bye.
		var _mRound = parseInt(m.Round) || 1;
		var _mSide  = m.BracketSide || '';
		var _mBd    = TnConfig.bracketData[m.BracketId];
		var _mMeth  = (_mBd && _mBd.Bracket) ? _mBd.Bracket.Method : '';
		function slotIsBye(slotNum) {
			if (_mMeth !== 'single' && _mMeth !== 'double') return true;
			if (_mSide === 'grand-final' || _mSide === 'tiebreaker-3rd') return false;
			if (hasResult) return true;
			var _bdm = (TnConfig.bracketData[m.BracketId] && TnConfig.bracketData[m.BracketId].Matches) || sectionMatches || [];
			var _mNum = parseInt(m.Match, 10) || 0;
			// Losers-side round 1 is fed by winners-R1 losers (server routing: slot 1 from
			// WR1 match N, slot 2 from WR1 match wr1_count-N+1). It's a bye only when that
			// feeder is itself a bye (no loser to route); otherwise it's still 'Awaiting'.
			if (_mSide === 'losers' && _mRound <= 1) {
				if (!_mNum) return false;
				var _wr1 = _bdm.filter(function(fm) { return fm.BracketSide === 'winners' && parseInt(fm.Round) === 1; });
				var _wNum = slotNum === 1 ? _mNum : (_wr1.length - _mNum + 1);
				var wFeeder = _wr1.find(function(fm) { return parseInt(fm.Match, 10) === _wNum; });
				return !!wFeeder && ((parseInt(wFeeder.Participant1Id) || 0) <= 0 || (parseInt(wFeeder.Participant2Id) || 0) <= 0);
			}
			if (_mSide !== '' && _mSide !== 'winners') return false;
			if (_mRound <= 1) return true;
			if (!_mNum) return false;
			var _fNum = slotNum === 1 ? (2 * _mNum - 1) : (2 * _mNum);
			var feeder = _bdm.find(function(fm) {
				return (fm.BracketSide || '') === _mSide && parseInt(fm.Round) === _mRound - 1 && parseInt(fm.Match, 10) === _fNum;
			});
			return !!feeder && !(parseInt(feeder.Participant1Id) || 0) && !(parseInt(feeder.Participant2Id) || 0);
		}
		var p1IsBye = !p1Id && slotIsBye(1);
		var p2IsBye = !p2Id && slotIsBye(2);
		var isBye = (p1IsBye && p2Id) || (p1Id && p2IsBye) || (p1Id === -1 || p2Id === -1);

		var box = document.createElement('div');
		box.className = 'tn-bv-match';
		box.dataset.matchid = m.MatchId || '';
		if (isClickable) {
			box.className += ' tn-bv-clickable';
		}
		// The two slots sit in their own wrapper so the keyboard/AT button role
		// never contains the quick-result bar's buttons (no nested interactives).
		var hit = document.createElement('div');
		hit.className = 'tn-bv-hit';
		if (isClickable) {
			hit.setAttribute('role', 'button');
			hit.setAttribute('tabindex', '0');
			hit.setAttribute('data-tn-keyclick', '');
			hit.setAttribute('aria-expanded', 'false');
			hit.setAttribute('aria-label', 'Record result: ' + (p1.Alias || p1.Persona || 'Side 1') + ' vs ' + (p2.Alias || p2.Persona || 'Side 2'));
		}
		if (hasResult)   box.className += ' tn-bv-resolved';
		if (isBye && !hasResult) box.className += ' tn-bv-bye-match';
		if (!hasResult && p1 && p2) box.className += ' tn-bv-next-playable';
		if (m._pending) box.className += ' tn-match-pending';

			if (m.BracketSide === 'tiebreaker-3rd') {
			var tbLabel = document.createElement('span');
			tbLabel.className = 'tn-bv-match-num tn-bv-tb-badge';
			tbLabel.textContent = '3rd Place';
			box.classList.add('tn-bv-has-badge');
			box.appendChild(tbLabel);
		}

		[
			{ pid:p1Id, p:p1, slot:1 },
			{ pid:p2Id, p:p2, slot:2 },
		].forEach(function(info) {
			var slot = document.createElement('div');
			slot.className = 'tn-bv-slot';

			if (hasResult) {
				var w = tnWinnerSide(m.Result) === info.slot;
				if (w) slot.classList.add('tn-bv-winner');
				else   slot.classList.add('tn-bv-loser');
			}

			if (!info.pid && (info.slot === 1 ? p1IsBye : p2IsBye)) {
				slot.classList.add('tn-bv-bye');
				slot.innerHTML = (anySeed ? '<span class="tn-bv-seed">—</span>' : '') + '<span>Bye</span>';
			} else if (!info.p) {
				var awaitLabel = parseInt(m.Round) > 1 ? 'Awaiting Rd ' + (parseInt(m.Round) - 1) : (_mSide === 'losers' ? 'Awaiting' : 'TBD');
				slot.innerHTML = (anySeed ? '<span class="tn-bv-seed">?</span>' : '') + '<span class="tn-bv-tbd-label">' + awaitLabel + '</span>';
			} else {
				var displayName = info.p.Alias || info.p.Persona || '—';
				var av = document.createElement('span');
				av.className = 'tn-bv-avatar';
				av.style.background = tnAvatarColor(info.pid);
				av.textContent = tnInitials(displayName);
				slot.appendChild(av);
					var _pidn = parseInt(info.p.ParticipantNumber, 10) || 0;
					if (_pidn > 0) {
						var pidEl = document.createElement('span');
						pidEl.className = 'tn-pid';
						pidEl.textContent = _pidn;
						pidEl.setAttribute('data-tip', 'Player #' + _pidn + ' — same number across every bracket');
						slot.appendChild(pidEl);
					}

				if (anySeed) {
					var seed = document.createElement('span');
					seed.className = 'tn-bv-seed';
					seed.setAttribute('data-tip', 'Seed'); seed.innerHTML = '<i class="fas fa-seedling tn-seedling"></i>' + (info.p.Seed || '?');
					slot.appendChild(seed);
				}
				var name = document.createElement('span');
				name.textContent = displayName;
				slot.appendChild(name);
				// Same WD/DQ pill the Brackets tab shows, so a reeve running matches can
				// see the fighter is out.
				var _pst = String(info.p.Status || '').toLowerCase();
				if (_pst === 'withdrawn' || _pst === 'disqualified') {
					var stPill = document.createElement('span');
					stPill.className = 'tn-pstatus-pill tn-pstatus-pill-' + _pst;
					stPill.textContent = _pst === 'withdrawn' ? 'WD' : 'DQ';
					stPill.setAttribute('data-tip', _pst === 'withdrawn' ? 'Withdrawn' : 'Disqualified');
					slot.appendChild(stPill);
				}
				if (hasResult && info.slot === 1 && tnWinnerSide(m.Result) === 1) {
					var pill = document.createElement('span');
					pill.className = 'tn-bv-result-pill';
					pill.textContent = 'W';
					slot.appendChild(pill);
				} else if (hasResult && info.slot === 2 && tnWinnerSide(m.Result) === 2) {
					var pill = document.createElement('span');
					pill.className = 'tn-bv-result-pill';
					pill.textContent = 'W';
					slot.appendChild(pill);
				}
			}
			hit.appendChild(slot);
		});
		box.appendChild(hit);

		// Bye auto-advance label
		if (isBye && !hasResult) {
			var byeLabel = document.createElement('div');
			byeLabel.className = 'tn-bv-bye-label';
			byeLabel.textContent = 'Auto-advance';
			box.appendChild(byeLabel);
		}

		// Tooltip on hover
		if (p1 || p2) {
			box.addEventListener('mouseenter', function(e) {
				var lines = [];
				[p1, p2].forEach(function(pp, idx) {
					if (!pp) return;
					var pName = pp.Alias || pp.Persona || 'Unknown';
					lines.push('<div class="tn-bv-tooltip-name">' + (idx+1) + '. ' + tnEscHtml(pName) + '</div>');
					if (pp.IsTeam && pp.Members && pp.Members.length) {
						var memberList = pp.Members.map(function(m) { return tnEscHtml(m.Persona || m.MundaneId || "?"); }).join(", ");
						lines.push('<div class="tn-bv-tooltip-park">' + memberList + '</div>');
					} else {
						var pPark = pp.ParkName || '';
						if (pPark) lines.push('<div class="tn-bv-tooltip-park">' + tnEscHtml(pPark) + '</div>');
					}
				});
				if (hasResult) {
					var _tipSide = tnWinnerSide(m.Result);
					var resultLabel = _tipSide === 1 ? tnEscHtml((p1 && (p1.Alias || p1.Persona)) || 'Side 1') + ' wins'
							: _tipSide === 2 ? tnEscHtml((p2 && (p2.Alias || p2.Persona)) || 'Side 2') + ' wins'
							: m.Result === 'tie' ? 'Tie' : tnEscHtml(m.Result);
					if (_tipSide && m.Result !== '1-wins' && m.Result !== '2-wins') resultLabel += ' (' + tnEscHtml(m.Result) + ')';
					lines.push('<div class="tn-bv-tooltip-bouts">Result: ' + resultLabel + '</div>');
					try {
						var ba = (m.Bouts && m.Bouts !== '[]') ? JSON.parse(m.Bouts) : [];
						if (ba.length > 0) {
							var w1 = ba.filter(function(b){return b==='1'}).length;
							var w2 = ba.filter(function(b){return b==='2'}).length;
							lines.push('<div class="tn-bv-tooltip-bouts">Bouts: ' + w1 + '-' + w2 + '</div>');
						}
					} catch(ex){}
				}
				box._tnTipHtml = lines.join('');
				if (_openQrBar) return; // suppressed while a quick-result bar is open
				tnShowTooltip(e, box._tnTipHtml);
			});
			box.addEventListener('mousemove', function(e) {
				if (_openQrBar) return; // suppressed while a quick-result bar is open
				// Over the reset button its own data-tip (shown by the button's handler) wins.
				if (e.target.closest && e.target.closest('.tn-bv-reset-btn')) return;
				if (box._tnTipHtml) tnShowTooltip(e, box._tnTipHtml);
			});
			box.addEventListener('mouseleave', tnHideTooltip);
		}

		if (hasResult) {
			var boutsArr = [];
			try { boutsArr = (m.Bouts && m.Bouts !== '[]') ? JSON.parse(m.Bouts) : []; } catch(e) {}
			if (boutsArr.length > 0) {
				var p1Bouts = 0, p2Bouts = 0;
				boutsArr.forEach(function(b) { if (b === '1') p1Bouts++; else if (b === '2') p2Bouts++; });
				var boutRow = document.createElement('div');
				boutRow.className = 'tn-bv-bout-row';
				// Bout score pill (winner-loser format)
				var _bSide = tnWinnerSide(m.Result);
				var winBouts = (_bSide === 2) ? p2Bouts : p1Bouts;
				var loseBouts = (_bSide === 2) ? p1Bouts : p2Bouts;
				var scorePill = document.createElement('span');
				scorePill.className = 'tn-bout-score-pill';
				scorePill.textContent = winBouts + '-' + loseBouts;
				scorePill.setAttribute('data-tip', 'Bout score (winner-loser)');
				boutRow.appendChild(scorePill);
				// Also show bout dots
				boutsArr.forEach(function(b) {
					var dot = document.createElement('span');
					var winSide = (_bSide === 2) ? '2' : '1';
					dot.className = 'tn-bv-bout-dot ' + (b === winSide ? 'tn-bd-1' : 'tn-bd-2');
					boutRow.appendChild(dot);
				});
				box.appendChild(boutRow);
			} else if (m.Score) {
				// Fallback: show Score field as a pill if no bouts recorded
				var scoreRow = document.createElement('div');
				scoreRow.className = 'tn-bv-bout-row';
				var scorePill = document.createElement('span');
				scorePill.className = 'tn-bout-score-pill';
				scorePill.textContent = m.Score;
				scorePill.setAttribute('data-tip', 'Match score');
				scoreRow.appendChild(scorePill);
				box.appendChild(scoreRow);
			}
		}
		if (isClickable) {
			// Click on the match card opens quick result inline
			box.addEventListener('click', function(e) {
				// Don't toggle if clicking a button inside
				if (e.target.closest('.tn-qr-bar') || e.target.closest('.tn-bv-reset-btn')) return;
				var existing = box.querySelector('.tn-qr-bar');
				if (existing) {
					existing.remove();
					box.classList.remove('tn-qr-expanded');
					hit.setAttribute('aria-expanded', 'false');
					if (_openQrBar === existing) { _openQrBar = null; _openQrBox = null; }
					return;
				}
				// Close previously open bar by reference instead of querying the whole document
				if (_openQrBar) { _openQrBar.remove(); _openQrBar = null; }
				if (_openQrBox) {
					_openQrBox.classList.remove('tn-qr-expanded');
					var _prevHit = _openQrBox.querySelector('.tn-bv-hit[aria-expanded]');
					if (_prevHit) _prevHit.setAttribute('aria-expanded', 'false');
					_openQrBox = null;
				}
				var qrBar = document.createElement('div');
				qrBar.className = 'tn-qr-bar';
				var p1Label = p1 ? (p1.Alias || p1.Persona || 'P1') : 'P1';
				var p2Label = p2 ? (p2.Alias || p2.Persona || 'P2') : 'P2';
				// Button label = name (CSS end-ellipsis) + fixed ' Wins' suffix, so the verb
				// always stays visible; the full name is in aria-label. When both names share a
				// long prefix ('E2E-2026 Alice' / 'E2E-2026 Bob'), end-ellipsis would render two
				// identical buttons, so middle-truncate instead: head + '…' + distinct tail.
				var _qrCp = 0;
				while (_qrCp < p1Label.length && _qrCp < p2Label.length && p1Label.charAt(_qrCp) === p2Label.charAt(_qrCp)) _qrCp++;
				var _qrMid = _qrCp >= 4 && _qrCp < p1Label.length && _qrCp < p2Label.length;
				var _qrText = function(label) {
					if (!_qrMid || label.length <= 10) return label;
					// Start the tail at the word holding the first differing char ('…Alice' / '…Alan').
					var st = label.lastIndexOf(' ', _qrCp - 1) + 1;
					if (st < 4) st = _qrCp;
					return label.substring(0, 4).replace(/\s+$/, '') + '\u2026' + label.substring(st);
				};
				var _qrLabel = function(btn, label) {
					var nm = document.createElement('span');
					nm.className = 'tn-qr-name';
					nm.textContent = _qrText(label);
					var sfx = document.createElement('span');
					sfx.className = 'tn-qr-sfx';
					sfx.textContent = '\u00a0Wins';
					btn.appendChild(nm);
					btn.appendChild(sfx);
					btn.setAttribute('aria-label', label + ' wins');
				};
				var btn1 = document.createElement('button');
				btn1.className = 'tn-qr-btn tn-qr-btn-p1';
				_qrLabel(btn1, p1Label);
				btn1.onclick = function(ev) { tnSubmitQuickResult(m.MatchId, '1-wins', ev); };
				var btn2 = document.createElement('button');
				btn2.className = 'tn-qr-btn tn-qr-btn-p2';
				_qrLabel(btn2, p2Label);
				btn2.onclick = function(ev) { tnSubmitQuickResult(m.MatchId, '2-wins', ev); };
				// Only offer Tie where it doesn't strand advancement (round-robin/swiss/points);
				// in single/double/ironman elimination a tie leaves no winner to advance.
				var _qrSel = document.getElementById('tn-bv-bracket-select');
				var _qrBid = _qrSel ? parseInt(_qrSel.value) : 0;
				var _qrMethod = (_qrBid && TnConfig.bracketData[_qrBid] && TnConfig.bracketData[_qrBid].Bracket)
					? (TnConfig.bracketData[_qrBid].Bracket.Method || '') : '';
				var _qrAllowTie = !window.tnMethodAllowsTie || window.tnMethodAllowsTie(_qrMethod);
				var moreLink = document.createElement('button');
				moreLink.type = 'button';
				moreLink.className = 'tn-qr-more';
				moreLink.textContent = 'More Options';
				moreLink.onclick = function(ev) { ev.stopPropagation(); tnOpenRecordResult(m, p1, p2); };
				qrBar.appendChild(btn1);
				qrBar.appendChild(btn2);
				if (_qrAllowTie) {
					var btnTie = document.createElement('button');
					btnTie.className = 'tn-qr-btn tn-qr-btn-tie';
					btnTie.textContent = 'Tie';
					btnTie.onclick = function(ev) { tnSubmitQuickResult(m.MatchId, 'tie', ev); };
					qrBar.appendChild(btnTie);
				}
				qrBar.appendChild(moreLink);
				box.appendChild(qrBar);
				box.classList.add('tn-qr-expanded');
				hit.setAttribute('aria-expanded', 'true');
				_openQrBar = qrBar;
				_openQrBox = box;
				// Hover tooltips would cover the bar: hide the match tooltip now; the
				// mouseenter/mousemove handlers and CSS keep them off while it's open.
				tnHideTooltip();
			});
		}

		if (hasResult && TnConfig.canManage) {
			var canReset = isMatchResettable(m, sectionMatches || []);
			var resetBtn = document.createElement('button');
			resetBtn.className = 'tn-bv-reset-btn';
			resetBtn.innerHTML = '&#9851;';
			resetBtn.setAttribute('data-tip', canReset ? 'Reset this match' : 'Cannot reset: a later match has been played');
			resetBtn.setAttribute('data-tip-right', '');
			resetBtn.setAttribute('aria-label', canReset ? 'Reset this match' : 'Cannot reset: a later match has been played');
			if (!canReset) resetBtn.disabled = true;
			// Body-level tooltip: a CSS data-tip bubble is clipped by the card / bracket viewport.
			var showResetTip = function(e) { tnShowTooltip(e, tnEscHtml(resetBtn.getAttribute('data-tip') || '')); };
			resetBtn.addEventListener('mouseenter', showResetTip);
			resetBtn.addEventListener('mousemove', showResetTip);
			resetBtn.addEventListener('mouseleave', tnHideTooltip);
			var tnResetConfirmed = false;
			var tnResetTimer = null;
			resetBtn.addEventListener('click', function(e) {
				e.stopPropagation();
				if (!tnResetConfirmed) {
					tnResetConfirmed = true;
					resetBtn.classList.add('tn-bv-reset-confirm');
					resetBtn.innerHTML = 'Confirm?';
					resetBtn.setAttribute('data-tip', 'Click again to confirm reset');
					tnResetTimer = setTimeout(function() {
						tnResetConfirmed = false;
						resetBtn.classList.remove('tn-bv-reset-confirm');
						resetBtn.innerHTML = '&#9851;';
						resetBtn.setAttribute('data-tip', 'Reset this match');
					}, 3000);
				} else {
					clearTimeout(tnResetTimer);
					resetBtn.disabled = true;
					var tid = TnConfig.tournamentId;
					var url = TnConfig.uir + 'TournamentAjax/match/' + m.MatchId + '/' + tid + '/reset';
					// #29: tag the reset so the reeve's own undo echoes back as a
					// recognized own-action (no spurious "someone reset" toast) and keep
					// our collab cursor ahead of the returned seq.
					var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
					if (window.tnRegisterAction) window.tnRegisterAction(actionId);
					if (window.tnCollabNudge) window.tnCollabNudge();
					var rfd = new FormData();
					rfd.append('ActionId', actionId);
					fetch(url, { method: 'POST', body: rfd })
						.then(function(res) { return res.json(); })
						.then(function(d) {
							if (d && d.status === 0) {
								if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
								var sel = document.getElementById('tn-bv-bracket-select');
								var bid = sel ? parseInt(sel.value) : 0;
								if (bid && TnConfig.bracketData[bid]) {
									var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0;
									Promise.all([
										fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r2) { return r2.json(); }),
										fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r2) { return r2.json(); })
									]).then(function(res2) {
										var md = res2[0], bd2 = res2[1];
										var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bid, _rt); // a newer fetch of this bracket writes + paints
										if (_rtCur && md && md.status === 0) TnConfig.bracketData[bid].Matches = md.matches;
										if (_rtCur && bd2 && bd2.status === 0 && bd2.brackets && TnConfig.bracketData[bid]) {
											var br = bd2.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bid); });
											if (br) TnConfig.bracketData[bid].Bracket = br;
										}
										if (_rtCur) tnRenderBracketViz(bid);
										if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bid);
									}).catch(function(err) { console.warn('[tn] refresh failed', err); if (window.tnShowStaleWarning) tnShowStaleWarning(); });
								}
							} else {
								window.tnToast((d && d.error) ? d.error : 'Reset failed.');
								resetBtn.disabled = false;
							}
						})
						.catch(function() { window.tnToast('Request failed.'); resetBtn.disabled = false; });
				}
			});
			box.appendChild(resetBtn);
		}
		return box;
	}

	// ── Ironman / King of the Hill renderer ──
	function getIronmanWinnerId(m) {
		var side = tnWinnerSide(m.Result);
		if (side === 1) return parseInt(m.Participant1Id) || 0;
		if (side === 2) return parseInt(m.Participant2Id) || 0;
		return 0;
	}

	function computeIronmanStats(completedMatches, ringNumber) {
		// If ringNumber provided, filter to that ring only
		var filtered = (ringNumber != null)
			? completedMatches.filter(function(m){ return (parseInt(m.RingNumber)||1) === ringNumber; })
			: completedMatches;
		var wins = {}, maxStreak = {}, currentKingId = 0, currentStreak = 0;
		filtered.forEach(function(m) {
			var w = getIronmanWinnerId(m);
			if (!w) return;
			wins[w] = (wins[w] || 0) + 1;
			if (w === currentKingId) {
				currentStreak++;
			} else {
				currentKingId = w;
				currentStreak = 1;
			}
			if (!maxStreak[w] || currentStreak > maxStreak[w]) maxStreak[w] = currentStreak;
		});
		return { wins: wins, maxStreak: maxStreak, currentKingId: currentKingId, currentStreak: currentStreak };
	}

	// Global (cross-ring) stats matching the server's denormalized columns: total wins
	// and CURRENT streak per fighter. Streak resets when a fighter is dethroned (a
	// different winner appears on the hill they held); it never carries across rings on
	// its own because a ring change only follows a defeat. One pass per full render.
	function computeIronmanGlobalStats(completedMatches) {
		var wins = {}, streak = {}, maxStreak = {}, king = {};
		completedMatches.forEach(function(m) {
			var w = getIronmanWinnerId(m);
			if (!w) return;
			var ring = parseInt(m.RingNumber) || 1;
			var pk = king[ring] || 0;
			if (pk && pk !== w) streak[pk] = 0;
			wins[w]   = (wins[w] || 0) + 1;
			streak[w] = (streak[w] || 0) + 1;
			if (!maxStreak[w] || streak[w] > maxStreak[w]) maxStreak[w] = streak[w];
			king[ring] = w;
		});
		return { wins: wins, streak: streak, maxStreak: maxStreak };
	}

	function computeIronmanQueue(completedMatches, pMap, excludeIds) {
		var lastLossIdx = {}, appeared = {};
		completedMatches.forEach(function(m, idx) {
			var p1 = parseInt(m.Participant1Id) || 0;
			var p2 = parseInt(m.Participant2Id) || 0;
			if (p1) appeared[p1] = true;
			if (p2) appeared[p2] = true;
			var loser = 0;
			var side = tnWinnerSide(m.Result);
			if (side === 1) loser = p2;
			else if (side === 2) loser = p1;
			if (loser) lastLossIdx[loser] = idx;
		});
		var ids = Object.keys(pMap).map(Number).filter(function(id) {
			return excludeIds.indexOf(id) === -1;
		});
		ids.sort(function(a, b) {
			var aFresh = !appeared[a], bFresh = !appeared[b];
			if (aFresh && bFresh) return (parseInt(pMap[a].Seed)||0) - (parseInt(pMap[b].Seed)||0);
			if (aFresh) return -1;
			if (bFresh) return 1;
			var aL = lastLossIdx[a] !== undefined ? lastLossIdx[a] : Infinity;
			var bL = lastLossIdx[b] !== undefined ? lastLossIdx[b] : Infinity;
			return aL - bL;
		});
		return ids;
	}

	// Ring color palette (border + label bg) for up to 8 rings
	var TN_RING_COLORS = ['','#3182ce','#38a169','#e53e3e','#805ad5','#dd6b20','#d69e2e','#d53f8c','#744210'];
	// Exported for the mobile ironman deck (Track R3, separate <script> IIFE).
	// REUSE — these are the SAME functions the desktop renderIronmanView uses;
	// the deck does NOT reimplement king/queue/win logic.
	window.tnIronmanHelpers = {
		getWinnerId: getIronmanWinnerId,
		computeStats: computeIronmanStats,
		computeGlobalStats: computeIronmanGlobalStats,
		computeQueue: computeIronmanQueue,
		ringColors: TN_RING_COLORS
	};
	window.tnRefreshAndRender = tnRefreshAndRender;

	// Apply a recorded ironman win to the DOM. Fast path (king held the hill — the
	// common rapid-streak case) patches numbers in place: no rebuild, no lost focus.
	// A king change is structural, so fall back to a full refresh + render.
	function tnIronmanApplyWin(bracketId, d, ringN, winnerName, inputEl, statusEl) {
		if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
		if (!d || d.KingChanged) {
			if (winnerName) window['_tnLastWinner_' + bracketId + '_r' + ringN] = winnerName;
			tnRefreshAndRender(bracketId);
			return;
		}
		var root = document.getElementById('tn-bv-container');
		if (!root) { tnRefreshAndRender(bracketId); return; }
		// Fast path skips tnRefreshAndRender, so refresh the Brackets card + stats here.
		if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bracketId);
		// ...and the fight count + per-ring Fight History, which live off the Matches list.
		tnIronmanRefreshFightInfo(bracketId);
		// Winner's wins + streak, on every ring's card for this fighter.
		root.querySelectorAll('.tn-im-card[data-pid="' + d.WinnerId + '"]').forEach(function(card) {
			var winsEl = card.querySelector('.tn-im-card-wins');
			if (winsEl) winsEl.innerHTML = '<i class="fas fa-trophy"></i> ' + d.WinnerWins;
			var stEl = card.querySelector('.tn-im-card-streak');
			if (d.WinnerStreak >= 2) {
				if (!stEl) { stEl = document.createElement('div'); stEl.className = 'tn-im-card-streak'; card.appendChild(stEl); }
				stEl.innerHTML = '<i class="fas fa-link"></i> ' + d.WinnerStreak;
			} else if (stEl) {
				stEl.parentNode.removeChild(stEl);
			}
		});
		// Ring-local: fight counter + king badge streak.
		var ring = root.querySelector('.tn-im-ring[data-ring="' + ringN + '"]');
		if (ring) {
			var fn = ring.querySelector('.tn-im-fight-num');
			if (fn) { var mch = fn.textContent.match(/(\d+)/); fn.textContent = 'Fight #' + ((mch ? parseInt(mch[1]) : 1) + 1); }
			var kb = ring.querySelector('.tn-im-king-badge');
			if (kb) {
				var ks = kb.querySelector('.tn-im-king-badge-streak');
				if (d.WinnerStreak > 1) {
					if (!ks) { ks = document.createElement('span'); ks.className = 'tn-im-king-badge-streak'; kb.appendChild(ks); }
					ks.textContent = d.WinnerStreak;
				} else if (ks) { ks.parentNode.removeChild(ks); }
			}
		}
		if (inputEl)  { inputEl.value = ''; inputEl.disabled = false; setTimeout(function(){ inputEl.focus(); }, 0); }
		if (statusEl) { statusEl.className = 'tn-im-qe-status ok'; statusEl.textContent = '\u2713 ' + (winnerName || '') + ' won'; }
	}

	// Title + list nodes for one ring's Fight History (newest first, last 20, 2 shown
	// until expanded). Shared by the full render and the fast-path refresh below.
	function tnIronmanHistoryNodes(rCompleted, pMap, ringCount, rNum) {
		var hTitle = document.createElement('div');
		hTitle.className = 'tn-im-section-title';
		hTitle.textContent = ringCount > 1 ? 'Ring ' + rNum + ' Fight History' : 'Fight History';

		var hList = document.createElement('div');
		hList.className = 'tn-im-history';
		var _allRows = rCompleted.slice().reverse().slice(0, 20);
		var _showMax = 2;
		_allRows.forEach(function(m, i) {
			var fNum  = rCompleted.length - i;
			var wId   = getIronmanWinnerId(m);
			var wName = pMap[wId] ? (pMap[wId].Alias || pMap[wId].Persona || '?') : '?';
			var row   = document.createElement('div');
			row.className = 'tn-im-history-row';
			if (i >= _showMax) row.style.display = 'none';
			row.innerHTML = '<span><span class="tn-im-history-fight">#' + fNum + '</span>'
				+ '<span class="tn-im-history-winner">' + tnEsc(wName) + '</span> won</span>';
			hList.appendChild(row);
		});
		if (_allRows.length > _showMax) {
			var _expandRow = document.createElement('div');
			_expandRow.className = 'tn-im-history-expand';
			var _hidden = _allRows.length - _showMax;
			_expandRow.innerHTML = '<span>&#9660; Show last ' + _hidden + ' fight' + (_hidden === 1 ? '' : 's') + '</span>';
			_expandRow.addEventListener('click', function() {
				hList.querySelectorAll('.tn-im-history-row').forEach(function(r) { r.style.display = ''; });
				_expandRow.remove();
			});
			hList.appendChild(_expandRow);
		}
		return [hTitle, hList];
	}

	// Fast-path companion to tnIronmanApplyWin: refetch the bracket's matches (the
	// ironmanwin response doesn't carry the new fight), store them, then patch the
	// 'N fights recorded' line and each ring's Fight History in place. Debounced per
	// bracket and last-request-wins, so a rapid streak costs one fetch and an older
	// response can never overwrite a newer one.
	var _tnImInfoTimers = {}, _tnImInfoSeq = {};
	function tnIronmanRefreshFightInfo(bracketId) {
		clearTimeout(_tnImInfoTimers[bracketId]);
		_tnImInfoTimers[bracketId] = setTimeout(function() {
			var mySeq = _tnImInfoSeq[bracketId] = (_tnImInfoSeq[bracketId] || 0) + 1;
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/matches', { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(mData) {
					if (mySeq !== _tnImInfoSeq[bracketId]) return;
					var bd = TnConfig.bracketData[bracketId];
					if (!bd || !mData || mData.status !== 0) return;
					bd.Matches = mData.matches || [];
					var root = document.getElementById('tn-bv-container');
					var ringsWrap = root && root.querySelector('.tn-im-rings-wrap[data-bid="' + bracketId + '"]');
					if (!ringsWrap) return; // another bracket is on screen now
					var completed = bd.Matches.filter(function(m) { return m.Result && m.Result !== ''; })
						.sort(function(a, b) { return (parseInt(a.Order) || 0) - (parseInt(b.Order) || 0); });
					var prog = root.querySelector('.tn-bv-progress-info');
					if (prog) prog.textContent = completed.length + ' fight' + (completed.length === 1 ? '' : 's') + ' recorded';
					var pMap = {};
					(bd.Participants || []).forEach(function(p) { pMap[p.ParticipantId] = p; });
					var rings = ringsWrap.querySelectorAll('.tn-im-ring');
					rings.forEach(function(ringDiv) {
						var rNum = parseInt(ringDiv.dataset.ring, 10) || 1;
						var rCompleted = completed.filter(function(m) { return (parseInt(m.RingNumber) || 1) === rNum; });
						var oldTitle = ringDiv.querySelector('.tn-im-section-title');
						var oldList  = ringDiv.querySelector('.tn-im-history');
						if (oldTitle) oldTitle.parentNode.removeChild(oldTitle);
						if (oldList) oldList.parentNode.removeChild(oldList);
						if (rCompleted.length > 0) {
							tnIronmanHistoryNodes(rCompleted, pMap, rings.length, rNum).forEach(function(n) { ringDiv.appendChild(n); });
						}
					});
				})
				.catch(function(err) { console.warn('[tn] ironman fight info refresh failed', err); });
		}, 300);
	}

	function renderIronmanView(container, matches, pMap, participants, bracketId) {
		var sorted    = matches.slice().sort(function(a,b){ return (parseInt(a.Order)||0)-(parseInt(b.Order)||0); });
		var completed = sorted.filter(function(m){ return m.Result && m.Result !== ''; });

		// Global wins + current streak per fighter (same numbers on every ring).
		var globalStats = computeIronmanGlobalStats(completed);

		var seedSorted = participants.slice().sort(function(a,b){
			var an = parseInt(a.ParticipantNumber) || parseInt(a.Seed) || 0;
			var bn = parseInt(b.ParticipantNumber) || parseInt(b.Seed) || 0;
			return an - bn;
		});

		// ── Timer state ──
		var _timerKey   = 'tn_im_timer_' + bracketId;
		var _durationMs = (parseInt((TnConfig.bracketData[bracketId] && TnConfig.bracketData[bracketId].Bracket && TnConfig.bracketData[bracketId].Bracket.DurationMinutes) || 0)) * 60000;
		var _graceMs    = 10000;
		var _timerState = null;
		try { _timerState = JSON.parse(localStorage.getItem(_timerKey) || 'null'); } catch(e) {}
		var _timerActive  = false;
		var _timerExpired = false;
		var _inGrace      = false;
		var _timerPaused  = false;
		var _graceRemMs   = 0;
		var _timerRemMs   = _durationMs;
		if (_timerState && _timerState.startedAt) {
			var _elapsed = Date.now() - _timerState.startedAt;
			if (_timerState.endedAt) {
				_timerExpired = true; _timerRemMs = 0;
			} else if (_timerState.graceStartedAt) {
				var _graceElapsed = Date.now() - _timerState.graceStartedAt;
				if (_graceElapsed >= _graceMs) {
					_timerExpired = true; _timerRemMs = 0; _graceRemMs = 0;
				} else {
					_inGrace = true; _graceRemMs = _graceMs - _graceElapsed; _timerRemMs = 0;
				}
			} else if (_elapsed >= _durationMs && _durationMs > 0) {
				_inGrace = true; _graceRemMs = _graceMs; _timerRemMs = 0;
				_timerState.graceStartedAt = Date.now();
				try { localStorage.setItem(_timerKey, JSON.stringify(_timerState)); } catch(e) {}
			} else if (_timerState.pausedAt) {
				_timerPaused = true;
				_timerRemMs  = _timerState.pausedRemMs || 0;
			} else {
				_timerActive = true;
				_timerRemMs  = _durationMs > 0 ? Math.max(0, _durationMs - _elapsed) : Infinity;
			}
		}
		var _timerUnlocked = (_durationMs === 0) || _timerActive || _inGrace || _timerPaused;

		// ── Timer bar (single bar above all rings) ──
		if (_durationMs > 0) {
			var timerBar = document.createElement('div');
			timerBar.className = 'tn-im-timer-bar' + (_timerRemMs <= 15000 && _timerActive ? ' warning' : '');

			var timerDisplay = document.createElement('div');
			timerDisplay.className = 'tn-im-timer-display' + (_inGrace ? ' grace' : (_timerActive ? ' running' : (_timerExpired ? ' expired' : (_timerPaused ? ' paused' : ''))));
			function _fmtTime(ms) {
				if (ms === Infinity || ms < 0) ms = 0;
				var totalSec = Math.ceil(ms / 1000);
				var m = Math.floor(totalSec / 60), s = totalSec % 60;
				return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
			}
			timerDisplay.textContent = _timerExpired ? 'ENDED' : (_inGrace ? 'FINISH RECORDING ' + _fmtTime(_graceRemMs) : (_timerPaused ? 'PAUSED ' + _fmtTime(_timerRemMs) : _fmtTime(_timerRemMs)));
			timerBar.appendChild(timerDisplay);

			if (!_timerActive && !_timerExpired && !_inGrace && TnConfig.canManage) {
				var startBtn = document.createElement('button');
				startBtn.className = 'tn-im-timer-btn start';
				if (_timerPaused) {
					startBtn.innerHTML = '<i class="fas fa-redo"></i> Restart';
					startBtn.onclick = function() {
						if (startBtn.dataset.confirming) {
							startBtn.disabled = true;
							var fd = new FormData();
							fd.append('TournamentId', TnConfig.tournamentId);
							if (window.tnTagAction) window.tnTagAction(fd);
							fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/clearmatches', { method: 'POST', body: fd })
								.then(function(r){ return r.json(); })
								.then(function(d){
									try { localStorage.setItem(_timerKey, JSON.stringify({ startedAt: Date.now(), endedAt: null })); } catch(e) {}
									// Tagged, so the matches_cleared echo is dropped: refetch Matches/Status here.
									tnRefreshAndRender(bracketId);
								})
								.catch(function(){ startBtn.disabled = false; window.tnToast('Error clearing results.'); });
						} else {
							startBtn.dataset.confirming = '1';
							startBtn.textContent = 'Confirm Restart?';
							startBtn.style.background = '#276749';
							setTimeout(function() {
								if (startBtn.parentNode) { delete startBtn.dataset.confirming; startBtn.innerHTML = '<i class="fas fa-redo"></i> Restart'; startBtn.style.background = ''; }
							}, 3000);
						}
					};
				} else {
					startBtn.innerHTML = '<i class="fas fa-play"></i> Start';
					startBtn.onclick = function() {
						var state = { startedAt: Date.now(), endedAt: null };
						try { localStorage.setItem(_timerKey, JSON.stringify(state)); } catch(e) {}
						tnRenderBracketViz(bracketId);
					};
				}
				timerBar.appendChild(startBtn);
			}

			// Expired/ENDED: offer recovery so an organizer is never stuck.
			// Restart runs a fresh full-duration clock; +1m reopens with a short extension.
			if (_timerExpired && TnConfig.canManage) {
				var restartBtn = document.createElement('button');
				restartBtn.className = 'tn-im-timer-btn start';
				restartBtn.innerHTML = '<i class="fas fa-redo"></i> Restart Timer';
				restartBtn.onclick = function() {
					try { localStorage.setItem(_timerKey, JSON.stringify({ startedAt: Date.now(), endedAt: null })); } catch(e) {}
					tnRenderBracketViz(bracketId);
				};
				timerBar.appendChild(restartBtn);

				var addEndedBtn = document.createElement('button');
				addEndedBtn.className = 'tn-im-timer-btn add';
				addEndedBtn.textContent = '+1m';
				addEndedBtn.onclick = function() {
					// Reopen with ~1 minute on the clock (clears the ended state).
					try { localStorage.setItem(_timerKey, JSON.stringify({ startedAt: Date.now() - Math.max(0, _durationMs - 60000), endedAt: null })); } catch(e) {}
					tnRenderBracketViz(bracketId);
				};
				timerBar.appendChild(addEndedBtn);
			}

			// Completed ironman (timer ended): anyone can jump to its standings.
			if (_timerExpired) {
				var standingsBtn = document.createElement('button');
				standingsBtn.className = 'tn-im-timer-btn standings';
				standingsBtn.innerHTML = '<i class="fas fa-medal"></i> View Standings';
				standingsBtn.onclick = function() { tnViewIronmanStandings(bracketId); };
				timerBar.appendChild(standingsBtn);
			}

			if (_inGrace && TnConfig.canManage) {
				var graceNote = document.createElement('span');
				graceNote.className = 'tn-im-timer-locked';
				graceNote.style.color = '#ed8936';
				graceNote.innerHTML = '<i class="fas fa-hourglass-end"></i> Finish recording — bracket completes automatically';
				timerBar.appendChild(graceNote);
			}

			if (_timerActive && TnConfig.canManage) {
				var pauseBtn = document.createElement('button');
				pauseBtn.className = 'tn-im-timer-btn pause';
				pauseBtn.innerHTML = '<i class="fas fa-pause"></i> Pause';
				pauseBtn.onclick = function() {
					try {
						var s = JSON.parse(localStorage.getItem(_timerKey) || 'null') || {};
						var _nowRem = _durationMs > 0 ? Math.max(0, _durationMs - (Date.now() - (s.startedAt || Date.now()))) : 0;
						s.pausedAt  = Date.now();
						s.pausedRemMs = _nowRem;
						localStorage.setItem(_timerKey, JSON.stringify(s));
					} catch(e) {}
					tnRenderBracketViz(bracketId);
				};
				timerBar.appendChild(pauseBtn);

				var addBtn = document.createElement('button');
				addBtn.className = 'tn-im-timer-btn add';
				addBtn.textContent = '+1m';
				addBtn.onclick = function() {
					try {
						var s = JSON.parse(localStorage.getItem(_timerKey) || 'null') || {};
						s.startedAt = (s.startedAt || Date.now()) + 60000;
						localStorage.setItem(_timerKey, JSON.stringify(s));
					} catch(e) {}
					tnRenderBracketViz(bracketId);
				};
				timerBar.appendChild(addBtn);

				var endBtn = document.createElement('button');
				endBtn.className = 'tn-im-timer-btn end';
				endBtn.textContent = 'End Early';
				endBtn.onclick = function() {
					if (endBtn.dataset.confirming) {
						try {
							var s = JSON.parse(localStorage.getItem(_timerKey) || 'null') || {};
							s.endedAt = Date.now();
							localStorage.setItem(_timerKey, JSON.stringify(s));
						} catch(e) {}
						tnRenderBracketViz(bracketId);
					} else {
						endBtn.dataset.confirming = '1';
						endBtn.textContent = 'Confirm End?';
						endBtn.style.background = '#fc8181';
						endBtn.style.color = '#fff';
						setTimeout(function() {
							if (endBtn.parentNode) { delete endBtn.dataset.confirming; endBtn.textContent = 'End Early'; endBtn.style.background = ''; endBtn.style.color = ''; }
						}, 3000);
					}
				};
				timerBar.appendChild(endBtn);
			}

			if (_timerPaused && TnConfig.canManage) {
				var resumeBtn = document.createElement('button');
				resumeBtn.className = 'tn-im-timer-btn pause';
				resumeBtn.innerHTML = '<i class="fas fa-play"></i> Resume';
				resumeBtn.onclick = function() {
					try {
						var s = JSON.parse(localStorage.getItem(_timerKey) || 'null') || {};
						s.startedAt = Date.now() - (_durationMs - (s.pausedRemMs || 0));
						delete s.pausedAt;
						delete s.pausedRemMs;
						localStorage.setItem(_timerKey, JSON.stringify(s));
					} catch(e) {}
					tnRenderBracketViz(bracketId);
				};
				timerBar.appendChild(resumeBtn);

				var addBtnP = document.createElement('button');
				addBtnP.className = 'tn-im-timer-btn add';
				addBtnP.textContent = '+1m';
				addBtnP.onclick = function() {
					try {
						var s = JSON.parse(localStorage.getItem(_timerKey) || 'null') || {};
						s.pausedRemMs = Math.min((s.pausedRemMs || 0) + 60000, _durationMs);
						localStorage.setItem(_timerKey, JSON.stringify(s));
					} catch(e) {}
					tnRenderBracketViz(bracketId);
				};
				timerBar.appendChild(addBtnP);

				var endBtnP = document.createElement('button');
				endBtnP.className = 'tn-im-timer-btn end';
				endBtnP.textContent = 'End Early';
				endBtnP.onclick = function() {
					if (endBtnP.dataset.confirming) {
						try {
							var s = JSON.parse(localStorage.getItem(_timerKey) || 'null') || {};
							s.endedAt = Date.now();
							delete s.pausedAt;
							delete s.pausedRemMs;
							localStorage.setItem(_timerKey, JSON.stringify(s));
						} catch(e) {}
						tnRenderBracketViz(bracketId);
					} else {
						endBtnP.dataset.confirming = '1';
						endBtnP.textContent = 'Confirm End?';
						endBtnP.style.background = '#fc8181';
						endBtnP.style.color = '#fff';
						setTimeout(function() {
							if (endBtnP.parentNode) { delete endBtnP.dataset.confirming; endBtnP.textContent = 'End Early'; endBtnP.style.background = ''; endBtnP.style.color = ''; }
						}, 3000);
					}
				};
				timerBar.appendChild(endBtnP);
			}

			if (!_timerUnlocked && !_timerExpired) {
				var lockNote = document.createElement('span');
				lockNote.className = 'tn-im-timer-locked';
				lockNote.innerHTML = '<i class="fas fa-lock"></i> Start timer to record fights';
				timerBar.appendChild(lockNote);
			}

			container.appendChild(timerBar);

			// Live countdown tick (active or in grace)
			if (_timerActive || _inGrace) {
				if (window['_tnTimerInterval_'+bracketId]) clearInterval(window['_tnTimerInterval_'+bracketId]);
				window['_tnTimerInterval_'+bracketId] = setInterval(function() {
					var state = null;
					try { state = JSON.parse(localStorage.getItem(_timerKey) || 'null'); } catch(e) {}
					if (!state || !state.startedAt) { clearInterval(window['_tnTimerInterval_'+bracketId]); return; }
					if (state.endedAt) { clearInterval(window['_tnTimerInterval_'+bracketId]); tnRenderBracketViz(bracketId); return; }
					var now = Date.now();
					var elapsed = now - state.startedAt;
					var rem = _durationMs - elapsed;
					if (rem > 0) {
						timerDisplay.textContent = _fmtTime(rem);
						timerDisplay.className = 'tn-im-timer-display running';
						if (rem <= 15000) { timerBar.classList.add('warning'); } else { timerBar.classList.remove('warning'); }
					} else if (!state.graceStartedAt) {
						state.graceStartedAt = now;
						try { localStorage.setItem(_timerKey, JSON.stringify(state)); } catch(e) {}
						timerDisplay.textContent = 'FINISH RECORDING ' + _fmtTime(10000);
						timerDisplay.className = 'tn-im-timer-display grace';
					} else {
						var graceRem = 10000 - (now - state.graceStartedAt);
						if (graceRem > 0) {
							timerDisplay.textContent = 'FINISH RECORDING ' + _fmtTime(graceRem);
							timerDisplay.className = 'tn-im-timer-display grace';
						} else {
							// Grace expired — lock cells immediately, then auto-complete
							clearInterval(window['_tnTimerInterval_'+bracketId]);
							timerDisplay.textContent = 'ENDED';
							timerDisplay.className = 'tn-im-timer-display expired';
							container.querySelectorAll('.tn-im-card-btn').forEach(function(b) { b.disabled = true; b.style.opacity = '0.45'; b.style.cursor = 'not-allowed'; });
							container.querySelectorAll('.tn-im-qe-input').forEach(function(el) { el.disabled = true; });
							var fd = new FormData();
							fd.append('BracketId', bracketId);
							if (window.tnTagAction) window.tnTagAction(fd);
							fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/completebracket', { method: 'POST', body: fd })
								// Tagged, so the bracket_finalized echo is dropped: refetch Status/card here.
								.then(function() { tnRefreshAndRender(bracketId); })
								.catch(function() { tnRenderBracketViz(bracketId); });
						}
					}
				}, 500);
			}
		}

		// ── Determine ring count ──
		var ringCount = Math.max(1, Math.min(8, parseInt(
			(TnConfig.bracketData[bracketId] && TnConfig.bracketData[bracketId].Bracket &&
			 TnConfig.bracketData[bracketId].Bracket.Rings) || 1
		) || 1)); // inner || 1 guards against parseInt returning NaN

		// ── Compute per-ring stats and kings — single pass, results cached ──
		// ringStats[rn] = computeIronmanStats result for that ring
		// ringKings[rn] = display king (fight history first, then seed default)
		// allKingRing[pid] = ring that pid is the REAL (fight-based) king of
		// Cross-ring blocking only uses real kings, never the seed default, to
		// avoid blocking a person just because they happen to be the Nth seed.
		var ringStats  = {};
		var ringKings  = {};
		var allKingRing = {}; // pid => ring they are the real fight-history king of
		for (var ringIdx = 1; ringIdx <= ringCount; ringIdx++) {
			var _rs = computeIronmanStats(completed, ringIdx);
			ringStats[ringIdx] = _rs;
			var _realKingId = _rs.currentKingId;
			// allKingRing only tracks fight-history kings — never seed defaults
			if (_realKingId) allKingRing[_realKingId] = ringIdx;
			// Display king: real king if fights exist, otherwise Nth seed as visual default
			if (!_realKingId && seedSorted.length > 0) {
				_realKingId = parseInt(seedSorted[(ringIdx - 1) % seedSorted.length].ParticipantId) || 0;
			}
			ringKings[ringIdx] = _realKingId;
		}

		// Avatar color palette
		var _avatarColors = ['#e53e3e','#38a169','#3182ce','#d69e2e','#805ad5','#dd6b20','#00b5d8','#d53f8c','#2d3748','#319795'];

		// Build pNumMap once (shared across rings)
		var pNumMap = {};
		participants.forEach(function(participant) {
			var num = parseInt(participant.ParticipantNumber) || parseInt(participant.Seed) || 0;
			if (!num) return;
			var pid = parseInt(participant.ParticipantId) || 0;
			if (pNumMap[num] !== undefined && pNumMap[num] !== pid) {
				console.warn('Ironman QE collision at number ' + num + '; participants ' + pNumMap[num] + ' and ' + pid);
				return; // keep the first participant assigned to this number
			}
			pNumMap[num] = pid;
		});

		// ── Rings wrapper ──
		var ringsWrap = document.createElement('div');
		ringsWrap.className = 'tn-im-rings-wrap';
		ringsWrap.dataset.bid = bracketId;
		container.appendChild(ringsWrap);

		// ── Render each ring ──
		for (var ringNum = 1; ringNum <= ringCount; ringNum++) {
			(function(rNum) {
				var rStats     = ringStats[rNum]; // reuse cached stats from pre-pass
				var kingId     = ringKings[rNum];
				var rCompleted = completed.filter(function(m){ return (parseInt(m.RingNumber)||1) === rNum; });
				var fightNum   = rCompleted.length + 1;
				var ringColor  = TN_RING_COLORS[rNum] || '#718096';

				// Ring container
				var ringDiv = document.createElement('div');
				ringDiv.className = 'tn-im-ring';
				ringDiv.style.borderColor = ringColor;
				ringDiv.dataset.ring = rNum;

				// Ring header: label + fight# + king badge
				var rHeader = document.createElement('div');
				rHeader.className = 'tn-im-ring-header';

				if (ringCount > 1) {
					var rLabel = document.createElement('span');
					rLabel.className = 'tn-im-ring-label';
					rLabel.style.cssText = 'background:' + ringColor + ';color:#fff;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.8px;border-radius:6px;padding:3px 10px;';
					rLabel.textContent = 'Ring ' + rNum;
					rHeader.appendChild(rLabel);
				}

				var fightNumBadge = document.createElement('div');
				fightNumBadge.className = 'tn-im-fight-num';
				fightNumBadge.textContent = 'Fight #' + fightNum;
				rHeader.appendChild(fightNumBadge);

				// Quick-entry inline in header (between fight# and king badge)
				var qeInput = null;
				if (TnConfig.canRecordResult && participants.length > 0 && _timerUnlocked) {
					var qeWrap = document.createElement('div');
					qeWrap.className = 'tn-im-qe-wrap';
					qeWrap.style.marginBottom = '0';
					if (ringCount > 1) qeWrap.style.borderColor = ringColor;

					var qeLabel = document.createElement('span');
					qeLabel.className = 'tn-im-qe-label';
					qeLabel.textContent = ringCount > 1 ? 'Ring ' + rNum + ' Winner #' : 'Winner #';
					qeWrap.appendChild(qeLabel);

					qeInput = document.createElement('input');
					qeInput.type = 'text';
					qeInput.inputMode = 'numeric';
					qeInput.className = 'tn-im-qe-input';
					qeInput.placeholder = '—';
					qeInput.autocomplete = 'off';
					qeWrap.appendChild(qeInput);

					var qeStatus = document.createElement('span');
					qeStatus.className = 'tn-im-qe-status';
					qeWrap.appendChild(qeStatus);

					// Show last winner for this ring after re-render
					var lastWinKey = '_tnLastWinner_' + bracketId + '_r' + rNum;
					if (window[lastWinKey]) {
						qeStatus.className = 'tn-im-qe-status ok';
						qeStatus.textContent = '\u2713 ' + window[lastWinKey] + ' — Fight #' + rCompleted.length + ' recorded';
						window[lastWinKey] = null;
					}

					qeInput.addEventListener('keydown', (function(ringN, statusEl, inputEl) {
						return function(e) {
							if (e.key !== 'Enter') return;
							e.preventDefault();
							var num = parseInt(inputEl.value.trim()) || 0;
							inputEl.value = '';
							if (!num || !pNumMap[num]) {
								statusEl.className = 'tn-im-qe-status err';
								statusEl.textContent = num ? 'No fighter #' + num : 'Enter a number';
								inputEl.focus();
								return;
							}
							var winnerId   = pNumMap[num];
							// Block if this fighter is the real king of a different ring
							if (allKingRing[winnerId] !== undefined && allKingRing[winnerId] !== ringN) {
								statusEl.className = 'tn-im-qe-status err';
								statusEl.textContent = 'Fighter #' + num + ' is King of Ring ' + allKingRing[winnerId];
								inputEl.focus();
								return;
							}
							var winnerName = pMap[winnerId] ? (pMap[winnerId].Alias || pMap[winnerId].Persona || '#' + num) : '#' + num;
							statusEl.className = 'tn-im-qe-status';
							statusEl.textContent = 'Recording\u2026';
							inputEl.disabled = true;
							var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
							if (window.tnRegisterAction) window.tnRegisterAction(actionId);
							if (window.tnCollabNudge) window.tnCollabNudge();
							var fd = new FormData();
							fd.append('WinnerId',     winnerId);
							fd.append('TournamentId', TnConfig.tournamentId);
							fd.append('RingNumber',   ringN);
							fd.append('ActionId',     actionId);
							var _ac = new AbortController();
							var _to = setTimeout(function(){ _ac.abort(); }, 9000);
							fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/ironmanwin', {method:'POST', body:fd, signal:_ac.signal})
								.then(function(r){ return r.json(); })
								.then(function(d){
									if (d.status !== 0) {
										statusEl.className = 'tn-im-qe-status err';
										statusEl.textContent = d.error || 'Error';
										inputEl.disabled = false;
										inputEl.focus();
										return;
									}
									if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
									inputEl.disabled = false;
									tnIronmanApplyWin(bracketId, d, ringN, winnerName, inputEl, statusEl);
								})
								.catch(function(err){
									statusEl.className = 'tn-im-qe-status err';
									statusEl.textContent = (err && err.name === 'AbortError') ? 'Timed out — try again' : 'Network error';
									inputEl.disabled = false;
									inputEl.focus();
									// #28: a 9s abort can fire AFTER the server committed. Drop our
									// own-action id so the later echo is treated as new and the bracket
									// refetches rather than silently skipping our own win.
									if (window.tnUnregisterAction) window.tnUnregisterAction(actionId);
								})
								.finally(function(){ clearTimeout(_to); });
						};
					})(rNum, qeStatus, qeInput));

					rHeader.appendChild(qeWrap);
				}

				if (kingId && pMap[kingId]) {
					var kName = pMap[kingId].Alias || pMap[kingId].Persona || '?';
					var kb = document.createElement('div');
					kb.className = 'tn-im-king-badge';
					kb.innerHTML = '<i class="fas fa-crown tn-im-king-badge-crown"></i>'
						+ '<span class="tn-im-king-badge-label">' + (ringCount > 1 ? 'King of Ring ' + rNum : 'King') + '</span>'
						+ '<span class="tn-im-king-badge-name">' + tnEsc(kName) + '</span>'
						+ ((globalStats.streak[kingId] || 0) > 1
							? '<span class="tn-im-king-badge-streak">' + (globalStats.streak[kingId] || 0) + '</span>'
							: '');
					rHeader.appendChild(kb);
				}
				ringDiv.appendChild(rHeader);
				// Auto-focus first ring's quick-entry
				if (rNum === 1 && qeInput) setTimeout(function(){ qeInput.focus(); }, 0);

				// Fighter grid
				var grid = document.createElement('div');
				grid.className = 'tn-im-grid';

				seedSorted.forEach(function(participant, idx) {
					var pid    = parseInt(participant.ParticipantId) || 0;
					var name   = participant.Alias || participant.Persona || '?';
					var wins   = globalStats.wins[pid] || 0;
					var streak = globalStats.streak[pid] || 0;
					var isKing = (pid === kingId);
					var color  = _avatarColors[idx % _avatarColors.length];
					var seedNum = parseInt(participant.ParticipantNumber) || parseInt(participant.Seed) || (idx + 1);

					// Blocked if this person is king of a DIFFERENT ring
					var isKingElsewhere = (!isKing && allKingRing[pid] !== undefined);

					var card = document.createElement('div');
					card.dataset.pid = pid;
					card.className = 'tn-im-card'
						+ (isKing ? ' tn-im-card-king' : '')
						+ (isKingElsewhere ? ' tn-im-card-blocked' : '');
					if (isKing) card.style.borderColor = ringColor;
					card.innerHTML = (isKing ? '<i class="fas fa-crown tn-im-card-crown"></i>' : '')
						+ (isKingElsewhere ? '<i class="fas fa-shield-alt tn-im-card-crown" style="color:#a0aec0" data-tip="King of Ring ' + allKingRing[pid] + '"></i>' : '')
						+ '<div class="tn-im-avatar" style="background:' + color + '">' + seedNum + '</div>'
						+ '<div class="tn-im-card-name">' + tnEsc(name) + '</div>'
						+ '<div class="tn-im-card-wins"><i class="fas fa-trophy"></i> ' + wins + '</div>'
						+ (streak >= 2 ? '<div class="tn-im-card-streak"><i class="fas fa-link"></i> ' + streak + '</div>' : '');

					if (TnConfig.canRecordResult && pid && _timerUnlocked && !isKingElsewhere) {
						card.classList.add('tn-im-card-btn');
						(function(winnerId, ringN) {
							card.onclick = function() {
								if (card.dataset.pending) return;
								card.dataset.pending = '1';
								grid.style.opacity = '0.5';
								grid.style.pointerEvents = 'none';
								var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
								if (window.tnRegisterAction) window.tnRegisterAction(actionId);
								if (window.tnCollabNudge) window.tnCollabNudge();
								var fd = new FormData();
								fd.append('WinnerId',     winnerId);
								fd.append('TournamentId', TnConfig.tournamentId);
								fd.append('RingNumber',   ringN);
								fd.append('ActionId',     actionId);
								var _ac = new AbortController();
								var _to = setTimeout(function(){ _ac.abort(); }, 9000);
								fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/ironmanwin', {method:'POST', body:fd, signal:_ac.signal})
									.then(function(r){ return r.json(); })
									.then(function(d){
										grid.style.opacity = ''; grid.style.pointerEvents = ''; delete card.dataset.pending;
										if (d.status !== 0) { window.tnToast('Error: ' + (d.error || 'Unknown')); tnRefreshAndRender(bracketId); return; }
										if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
										tnIronmanApplyWin(bracketId, d, ringN, null, null, null);
									})
									.catch(function(err){ grid.style.opacity = ''; grid.style.pointerEvents = ''; delete card.dataset.pending; if (err && err.name === 'AbortError') window.tnToast('Timed out — try again'); tnRefreshAndRender(bracketId); })
									.finally(function(){ clearTimeout(_to); });
							};
						})(pid, rNum);
					}
					grid.appendChild(card);
				});
				ringDiv.appendChild(grid);

				// Per-ring fight history
				if (rCompleted.length > 0) {
					tnIronmanHistoryNodes(rCompleted, pMap, ringCount, rNum).forEach(function(n) { ringDiv.appendChild(n); });
				}

				ringsWrap.appendChild(ringDiv);
			})(ringNum);
		}
	}

	// ── Cross-table matrix view (Round Robin) ──
	function renderMatrixView(container, matches, pMap) {
		var participants = Object.keys(pMap).map(function(id) { return pMap[id]; });
		participants.sort(function(a, b) {
			var seedA = parseInt(a.Seed, 10) || 9999;
			var seedB = parseInt(b.Seed, 10) || 9999;
			if (seedA !== seedB) return seedA - seedB;
			var nameA = (a.Alias || a.Persona || '').toLowerCase();
			var nameB = (b.Alias || b.Persona || '').toLowerCase();
			return nameA < nameB ? -1 : nameA > nameB ? 1 : 0;
		});

		var matchLookup = {};
		matches.forEach(function(match) {
			if (match.Participant1Id && match.Participant2Id) {
				matchLookup[match.Participant1Id + '-' + match.Participant2Id] = match;
				matchLookup[match.Participant2Id + '-' + match.Participant1Id] = match;
			}
		});

		function displayName(p) { return p.Alias || p.Persona || 'Unknown'; }
		function truncate(str, max) { return str.length <= max ? str : str.substring(0, max) + '\u2026'; }

		function makeAvatar(pid, small) {
			var av = document.createElement('span');
			av.className = small ? 'tn-rr-mx-avatar tn-rr-mx-avatar-sm' : 'tn-rr-mx-avatar';
			av.style.backgroundColor = tnAvatarColor(pid);
			av.textContent = tnInitials(displayName(pMap[pid]));
			return av;
		}

		function boutScore(match, playerId) {
			if (!match.Bouts) return null;
			try {
				var bouts = JSON.parse(match.Bouts);
				if (!Array.isArray(bouts) || bouts.length === 0) return null;
				var isP1 = String(match.Participant1Id) === String(playerId);
				var wins = 0, losses = 0;
				bouts.forEach(function(b) {
					if (isP1) { if (b === '1') wins++; else if (b === '2') losses++; }
					else { if (b === '2') wins++; else if (b === '1') losses++; }
				});
				return wins + '-' + losses;
			} catch(e) { return null; }
		}

		function cellResult(match, rowPlayerId) {
			if (!match || !match.Result) return { code: 'pending', label: '\u00B7' };
			var result = match.Result;
			var isP1 = String(match.Participant1Id) === String(rowPlayerId);
			var side = tnWinnerSide(result);
			if (side) return (side === 1) === isP1 ? { code: 'win', label: 'W' } : { code: 'loss', label: 'L' };
			if (result === 'tie') return { code: 'tie', label: 'T' };
			return { code: 'pending', label: '\u00B7' };
		}

		var wrap = document.createElement('div');
		wrap.className = 'tn-rr-matrix-wrap';
		var table = document.createElement('table');
		table.className = 'tn-rr-matrix';

		var thead = document.createElement('thead');
		var headerRow = document.createElement('tr');
		var cornerTh = document.createElement('th');
		cornerTh.className = 'tn-rr-mx-corner';
		headerRow.appendChild(cornerTh);

		participants.forEach(function(p) {
			var th = document.createElement('th');
			th.className = 'tn-rr-mx-col-header';
			th.appendChild(makeAvatar(p.ParticipantId, true));
			var nameSpan = document.createElement('span');
			nameSpan.className = 'tn-rr-mx-col-name';
			nameSpan.textContent = truncate(displayName(p), 8);
			nameSpan.setAttribute('data-tip', displayName(p));
			th.appendChild(nameSpan);
			headerRow.appendChild(th);
		});
		thead.appendChild(headerRow);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		participants.forEach(function(rowP) {
			var row = document.createElement('tr');
			row.dataset.pid = rowP.ParticipantId;

			var rowTh = document.createElement('th');
			rowTh.className = 'tn-rr-mx-player-col';
			rowTh.appendChild(makeAvatar(rowP.ParticipantId, false));
			var rowNameSpan = document.createElement('span');
			rowNameSpan.className = 'tn-rr-mx-player-name';
			rowNameSpan.textContent = displayName(rowP);
			rowTh.appendChild(rowNameSpan);
			row.appendChild(rowTh);

			participants.forEach(function(colP) {
				var td = document.createElement('td');
				if (String(rowP.ParticipantId) === String(colP.ParticipantId)) {
					td.className = 'tn-rr-mx-self';
					td.textContent = '\u2014';
				} else {
					var key = rowP.ParticipantId + '-' + colP.ParticipantId;
					var matchObj = matchLookup[key] || null;
					var res = cellResult(matchObj, rowP.ParticipantId);
					td.className = 'tn-rr-mx-' + res.code;

					var resultSpan = document.createElement('span');
					resultSpan.className = 'tn-rr-mx-result';
					resultSpan.textContent = res.label;
					td.appendChild(resultSpan);

					if (matchObj && res.code !== 'pending') {
						var bs = boutScore(matchObj, rowP.ParticipantId);
						if (bs) {
							var boutSpan = document.createElement('span');
							boutSpan.className = 'tn-rr-mx-bouts';
							boutSpan.textContent = bs;
							td.appendChild(boutSpan);
						}
					}

					if (TnConfig.canRecordResult && matchObj && matchObj.Participant1Id && matchObj.Participant2Id) {
						td.classList.add('tn-rr-mx-cell-clickable');
						(function(mObj) {
							td.addEventListener('click', function() {
								tnOpenRecordResult(mObj, pMap[mObj.Participant1Id], pMap[mObj.Participant2Id]);
							});
						})(matchObj);
					}
				}
				row.appendChild(td);
			});
			tbody.appendChild(row);
		});
		table.appendChild(tbody);
		wrap.appendChild(table);
		container.appendChild(wrap);
	}

	// ── Enhanced standings table (Round Robin) ──
	// Shared W/L/T/played tally for a round-robin bracket. Returns
	// { stats: {pid -> {w,l,t,played}}, completedMatches: n }.
	function computeRRStats(matches, pids) {
		var stats = {};
		pids.forEach(function(pid) { stats[pid] = { w: 0, l: 0, t: 0, played: 0 }; });
		var completedMatches = 0;
		matches.forEach(function(m) {
			if (!m.Result || m.Result === '' || m.Voided) return;
			if (parseInt(m.Participant1Id) > 0 && parseInt(m.Participant2Id) > 0) completedMatches++;
			var p1 = String(m.Participant1Id), p2 = String(m.Participant2Id);
			if (stats[p1]) stats[p1].played++;
			if (stats[p2]) stats[p2].played++;
			var side = tnWinnerSide(m.Result);
			if (side === 1) {
				if (stats[p1]) stats[p1].w++;
				if (stats[p2]) stats[p2].l++;
			} else if (side === 2) {
				if (stats[p2]) stats[p2].w++;
				if (stats[p1]) stats[p1].l++;
			} else if (m.Result === 'tie') {
				if (stats[p1]) stats[p1].t++;
				if (stats[p2]) stats[p2].t++;
			}
		});
		return { stats: stats, completedMatches: completedMatches };
	}

	// Match total for the RR/Swiss progress readouts, taken from the real match list
	// (both sides seated, not voided) so RR tiebreakers can't overshoot 100%. Swiss
	// pre-creates empty placeholder rounds, so its total is rounds x floor(n/2).
	function rrMatchTotal(matches, pids, bracketId) {
		var real = matches.filter(function(m) { return !m.Voided && parseInt(m.Participant1Id) > 0 && parseInt(m.Participant2Id) > 0; }).length;
		var bd = bracketId != null ? TnConfig.bracketData[bracketId] : null;
		var br = bd && bd.Bracket ? bd.Bracket : null;
		if (br && br.Method === 'swiss') {
			return Math.max(real, (parseInt(tnComputeByesAndRounds('swiss', pids.length, br).rounds) || 0) * Math.floor(pids.length / 2));
		}
		return real;
	}

	function renderEnhancedStandings(container, matches, pMap, onPlayerClick, precomputed, bracketId) {
		var pids = Object.keys(pMap);
		var totalPossible = rrMatchTotal(matches, pids, bracketId);
		var maxMatchesPerPlayer = pids.length > 1 ? pids.length - 1 : 0;
		var _bdS = bracketId != null ? TnConfig.bracketData[bracketId] : null;
		if (_bdS && _bdS.Bracket && _bdS.Bracket.Method === 'swiss') maxMatchesPerPlayer = parseInt(tnComputeByesAndRounds('swiss', pids.length, _bdS.Bracket).rounds) || 0;
		var _rr = precomputed || computeRRStats(matches, pids);
		var stats = _rr.stats;
		var completedMatches = _rr.completedMatches;

		var rows = [];
		pids.forEach(function(pid) {
			var s = stats[pid];
			var pts = s.w * 3 + s.t * 1;
			var pct = s.played > 0 ? ((s.w + 0.5 * s.t) / s.played) : 0; // ties count half
			rows.push({ p: pid, w: s.w, l: s.l, t: s.t, played: s.played, pts: pts, pct: pct, totalPossible: Math.max(maxMatchesPerPlayer, s.played) });
		});
		rows.sort(function(a, b) {
			if (b.pts !== a.pts) return b.pts - a.pts;
			if (b.w !== a.w) return b.w - a.w;
			if (a.l !== b.l) return a.l - b.l;
			// Tie order shared with the Brackets-tab card placements (PHP): seed, then alias.
			var seedA = (pMap[a.p] && parseInt(pMap[a.p].Seed, 10)) || 9999;
			var seedB = (pMap[b.p] && parseInt(pMap[b.p].Seed, 10)) || 9999;
			if (seedA !== seedB) return seedA - seedB;
			var nameA = (pMap[a.p] ? (pMap[a.p].Alias || pMap[a.p].Persona || '') : '').toLowerCase();
			var nameB = (pMap[b.p] ? (pMap[b.p].Alias || pMap[b.p].Persona || '') : '').toLowerCase();
			return nameA < nameB ? -1 : nameA > nameB ? 1 : 0;
		});
		rows.forEach(function(row, idx) {
			if (idx === 0) { row.rank = 1; }
			else {
				var prev = rows[idx - 1];
				row.rank = (row.pts === prev.pts && row.w === prev.w && row.l === prev.l && row.t === prev.t) ? prev.rank : idx + 1;
			}
		});

		var table = document.createElement('table');
		table.className = 'tn-rr-standings-enhanced';

		var caption = document.createElement('caption');
		caption.innerHTML = '<span style="font-weight:bold">Standings</span><span class="tn-rr-std-caption-progress">' + completedMatches + '/' + totalPossible + ' matches played</span>';
		table.appendChild(caption);

		var thead = document.createElement('thead');
		var hRow = document.createElement('tr');
		['Rank','Player','W','L','T','Pts','Win%','Progress'].forEach(function(col) {
			var th = document.createElement('th');
			th.textContent = col;
			th.className = 'tn-rr-std-col-' + col.toLowerCase().replace('%','pct');
			hRow.appendChild(th);
		});
		thead.appendChild(hRow);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		tbody.className = 'tn-rr-std-clickable';
		var activePlayerId = null;
		var rowEls = {};

		rows.forEach(function(row) {
			var tr = document.createElement('tr');
			tr.dataset.participantId = row.p;
			rowEls[row.p] = tr;

			tr.addEventListener('click', function() {
				if (activePlayerId === row.p) {
					tr.classList.remove('tn-rr-std-active');
					activePlayerId = null;
					if (onPlayerClick) onPlayerClick(null);
				} else {
					if (activePlayerId && rowEls[activePlayerId]) rowEls[activePlayerId].classList.remove('tn-rr-std-active');
					tr.classList.add('tn-rr-std-active');
					activePlayerId = row.p;
					if (onPlayerClick) onPlayerClick(row.p);
				}
			});

			// Rank
			var tdRank = document.createElement('td');
			tdRank.className = 'tn-rr-std-rank';
			if (row.rank <= 3) {
				var medals = { 1: '\uD83E\uDD47', 2: '\uD83E\uDD48', 3: '\uD83E\uDD49' };
				tdRank.innerHTML = '<span class="tn-rr-std-medal">' + medals[row.rank] + '</span>';
			} else {
				tdRank.textContent = row.rank;
			}
			tr.appendChild(tdRank);

			// Player
			var tdPlayer = document.createElement('td');
			tdPlayer.className = 'tn-rr-std-player';
			var playerWrap = document.createElement('div');
			playerWrap.className = 'tn-rr-std-player-wrap';
			var pInfo = pMap[row.p];
			var dName = pInfo ? (pInfo.Alias || pInfo.Persona || 'Unknown') : 'Unknown';
			var avatar = document.createElement('div');
			avatar.className = 'tn-rr-std-avatar';
			avatar.style.backgroundColor = tnAvatarColor(row.p);
			avatar.textContent = tnInitials(dName);
			var nameWrap = document.createElement('div');
			nameWrap.className = 'tn-rr-std-name-wrap';
			var nameSpan = document.createElement('span');
			nameSpan.className = 'tn-rr-std-name';
			nameSpan.textContent = dName;
			nameWrap.appendChild(nameSpan);
			if (pInfo && pInfo.ParkName) {
				var parkSpan = document.createElement('small');
				parkSpan.className = 'tn-rr-std-park';
				parkSpan.textContent = pInfo.ParkName;
				nameWrap.appendChild(parkSpan);
			}
			playerWrap.appendChild(avatar);
			playerWrap.appendChild(nameWrap);
			tdPlayer.appendChild(playerWrap);
			tr.appendChild(tdPlayer);

			// W / L / T
			var tdW = document.createElement('td'); tdW.className = 'tn-rr-std-w'; tdW.textContent = row.w; tr.appendChild(tdW);
			var tdL = document.createElement('td'); tdL.className = 'tn-rr-std-l'; tdL.textContent = row.l; tr.appendChild(tdL);
			var tdT = document.createElement('td'); tdT.className = 'tn-rr-std-t'; tdT.textContent = row.t; tr.appendChild(tdT);

			// Pts
			var tdPts = document.createElement('td'); tdPts.className = 'tn-rr-std-pts'; tdPts.textContent = row.pts; tr.appendChild(tdPts);

			// Win%
			var tdPct = document.createElement('td'); tdPct.className = 'tn-rr-std-winpct';
			tdPct.textContent = (row.pct * 100).toFixed(1) + '%'; tr.appendChild(tdPct);

			// Progress bar
			var tdProgress = document.createElement('td');
			tdProgress.className = 'tn-rr-std-progress';
			var barOuter = document.createElement('div');
			barOuter.className = 'tn-rr-std-bar';
			var barTrack = document.createElement('div');
			barTrack.className = 'tn-rr-std-bar-track';
			var barFill = document.createElement('div');
			barFill.className = 'tn-rr-std-bar-fill';
			var barPct = row.totalPossible > 0 ? (row.played / row.totalPossible) * 100 : 0;
			barFill.style.width = barPct.toFixed(1) + '%';
			barTrack.appendChild(barFill);
			barOuter.appendChild(barTrack);
			var barText = document.createElement('span');
			barText.className = 'tn-rr-std-bar-text';
			barText.textContent = row.played + '/' + row.totalPossible;
			barOuter.appendChild(barText);
			tdProgress.appendChild(barOuter);
			tr.appendChild(tdProgress);

			tbody.appendChild(tr);
		});
		table.appendChild(tbody);
		container.appendChild(table);

		container._tnSetActivePlayer = function(pid) {
			if (activePlayerId && rowEls[activePlayerId]) rowEls[activePlayerId].classList.remove('tn-rr-std-active');
			activePlayerId = pid;
			if (pid && rowEls[pid]) rowEls[pid].classList.add('tn-rr-std-active');
		};

		return rows;
	}

	// ── Champion banner for Round Robin ──
	function renderRRChampionBanner(container, stdRows, pMap) {
		if (!stdRows || stdRows.length === 0) return;

		var banner = document.createElement('div');
		banner.className = 'tn-bv-champion-banner';

		var champRow = document.createElement('div');
		champRow.className = 'tn-bv-champion-row';
		var trophyEl = document.createElement('div');
		trophyEl.className = 'tn-bv-champion-trophy';
		trophyEl.innerHTML = '&#x1F3C6;';
		champRow.appendChild(trophyEl);

		var champInfo = document.createElement('div');
		champInfo.className = 'tn-bv-champion-info';
		var champLabel = document.createElement('div');
		champLabel.className = 'tn-bv-champion-label';
		// Declared joint winners (tiebreaker declined) share rank 1 — name them all.
		var champs = stdRows.filter(function(r) { return r.rank === 1; });
		if (!champs.length) champs = [stdRows[0]];
		var joint = champs.length > 1;
		champLabel.textContent = joint ? 'Joint Champions' : 'Champion';
		champInfo.appendChild(champLabel);

		var champ = champs[0];
		var champData = joint ? null : pMap[champ.p];
		var champName = champs.map(function(r) {
			var cd = pMap[r.p];
			return cd ? (cd.Alias || cd.Persona || 'Unknown') : 'Unknown';
		}).join(', ');
		var champNameEl = document.createElement('div');
		champNameEl.className = 'tn-bv-champion-name';
		champNameEl.textContent = champName;
		champInfo.appendChild(champNameEl);

		if (champData && champData.ParkName) {
			var champPark = document.createElement('div');
			champPark.className = 'tn-bv-champion-park';
			champPark.textContent = champData.ParkName;
			champInfo.appendChild(champPark);
		}
		champRow.appendChild(champInfo);
		banner.appendChild(champRow);

		var podium = document.createElement('div');
		podium.className = 'tn-bv-podium';
		var medals = [
			{ rank: 1, cls: 'tn-bv-podium-1st', label: '1st' },
			{ rank: 2, cls: 'tn-bv-podium-2nd', label: '2nd' },
			{ rank: 3, cls: 'tn-bv-podium-3rd', label: '3rd' }
		];
		// One chip per player at each podium rank, so tied places (e.g. joint 1st)
		// all appear; tied chips are labelled 'T-1st'.
		medals.forEach(function(medal) {
			var tied = stdRows.filter(function(r) { return r.rank === medal.rank; });
			tied.forEach(function(player) {
				var card = document.createElement('div');
				card.className = 'tn-bv-podium-card ' + medal.cls;
				var rankBadge = document.createElement('div');
				rankBadge.className = 'tn-bv-podium-rank';
				rankBadge.textContent = (tied.length > 1 ? 'T-' : '') + medal.label;
				card.appendChild(rankBadge);
				var pData = pMap[player.p];
				var pName = pData ? (pData.Alias || pData.Persona || '?') : '?';
				var av = document.createElement('div');
				av.className = 'tn-bv-podium-avatar';
				av.style.backgroundColor = tnAvatarColor(player.p);
				av.textContent = tnInitials(pName);
				card.appendChild(av);
				var nameEl = document.createElement('div');
				nameEl.className = 'tn-bv-podium-name';
				nameEl.textContent = pName;
				card.appendChild(nameEl);
				if (pData && pData.ParkName) {
					var parkEl = document.createElement('div');
					parkEl.className = 'tn-bv-podium-park';
					parkEl.textContent = pData.ParkName;
					card.appendChild(parkEl);
				}
				var statsEl = document.createElement('div');
				statsEl.className = 'tn-bv-podium-stats';
				statsEl.textContent = player.w + 'W-' + player.l + 'L-' + player.t + 'T \u2022 ' + player.pts + ' pts';
				card.appendChild(statsEl);
				podium.appendChild(card);
			});
		});
		banner.appendChild(podium);
		container.insertBefore(banner, container.firstChild);
	}

	// ── Enhance match card for Round Robin (add W-L record) ──
	function enhanceRRMatchCard(box, m, pMap, rrStats) {
		var slots = box.querySelectorAll('.tn-bv-slot');
		[{ pid: parseInt(m.Participant1Id) || 0, idx: 0 }, { pid: parseInt(m.Participant2Id) || 0, idx: 1 }].forEach(function(info) {
			if (!info.pid || info.idx >= slots.length) return;
			var st = rrStats[String(info.pid)];
			if (!st || st.played === 0) return;
			var slot = slots[info.idx];
			var badge = document.createElement('span');
			badge.className = 'tn-rr-card-record';
			badge.textContent = '(' + st.w + '-' + st.l + (st.t > 0 ? '-' + st.t : '') + ')';
			badge.setAttribute('data-tip', st.w + 'W ' + st.l + 'L' + (st.t > 0 ? ' ' + st.t + 'T' : ''));
			var pill = slot.querySelector('.tn-bv-result-pill');
			if (pill) slot.insertBefore(badge, pill);
			else slot.appendChild(badge);
		});
	}

	// ── Round-table renderer (Swiss / Round Robin) — Enhanced ──
	function renderRoundTable(container, matches, pMap, bracketId) {
		// Compute shared stats once (reused by renderEnhancedStandings below)
		var pids = Object.keys(pMap);
		var totalPossible = rrMatchTotal(matches, pids, bracketId);
		var _rrComputed = computeRRStats(matches, pids);
		var rrStats = _rrComputed.stats;
		var completedMatches = _rrComputed.completedMatches;

		// Group by round
		var rounds = {};
		var maxRound = 0;
		matches.forEach(function(m) {
			var r = parseInt(m.Round) || 1;
			if (!rounds[r]) rounds[r] = [];
			rounds[r].push(m);
			if (r > maxRound) maxRound = r;
		});

		// Tiebreaker rounds: any round whose matches are all bracket_side='tiebreaker'.
		// Numbered TB1, TB2, ... in order. Regular rounds keep their numeric label.
		var tbRoundIndex = {}; // round_num => 1-based tb index
		var tbCount = 0;
		for (var _rN = 1; _rN <= maxRound; _rN++) {
			var _rms = rounds[_rN] || [];
			if (_rms.length > 0 && _rms.every(function(m) { return m.BracketSide === 'tiebreaker'; })) {
				tbCount++;
				tbRoundIndex[_rN] = tbCount;
			}
		}

		// Header: view toggle
		var headerRow = document.createElement('div');
		headerRow.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap';
		var viewToggle = document.createElement('div');
		viewToggle.className = 'tn-rr-view-toggle';
		var btnRounds = document.createElement('button');
		btnRounds.className = 'tn-rr-view-toggle-btn active';
		btnRounds.innerHTML = '<i class="fas fa-list" style="margin-right:5px"></i>Rounds';
		var btnMatrix = document.createElement('button');
		btnMatrix.className = 'tn-rr-view-toggle-btn';
		btnMatrix.innerHTML = '<i class="fas fa-th" style="margin-right:5px"></i>Matrix';
		viewToggle.appendChild(btnRounds);
		viewToggle.appendChild(btnMatrix);
		headerRow.appendChild(viewToggle);
		container.appendChild(headerRow);

		// Overall progress bar
		if (totalPossible > 0) {
			var progressWrap = document.createElement('div');
			progressWrap.className = 'tn-rr-progress-wrap';
			var progressBar = document.createElement('div');
			progressBar.className = 'tn-rr-progress-bar';
			var pctComplete = (completedMatches / totalPossible) * 100;
			if (pctComplete < 35) progressBar.classList.add('tn-rr-progress-low');
			var progressFill = document.createElement('div');
			progressFill.className = 'tn-rr-progress-fill';
			progressFill.style.width = pctComplete.toFixed(1) + '%';
			progressBar.appendChild(progressFill);
			var progressLabel = document.createElement('div');
			progressLabel.className = 'tn-rr-progress-label';
			progressLabel.textContent = completedMatches + ' of ' + totalPossible + ' matches complete (' + Math.round(pctComplete) + '%)';
			progressBar.appendChild(progressLabel);
			progressWrap.appendChild(progressBar);
			container.appendChild(progressWrap);
		}

		// Focus banner (hidden initially)
		var focusBanner = document.createElement('div');
		focusBanner.className = 'tn-rr-focus-banner';
		focusBanner.style.display = 'none';
		focusBanner.innerHTML = '<i class="fas fa-filter" style="font-size:11px"></i> Showing matches for <span class="tn-rr-focus-banner-name"></span>';
		var focusClose = document.createElement('button');
		focusClose.className = 'tn-rr-focus-banner-close';
		focusClose.innerHTML = '&times;';
		focusBanner.appendChild(focusClose);
		container.appendChild(focusBanner);

		var standingsContainer = null;
		var focusedPlayer = null;
		// Lazily-cached node lists so we don't re-scan the whole container on
		// every standings row click. Rebuilt only when the view re-renders
		// (this closure is recreated by each renderRoundTable call).
		var _focusMatchEls = null, _focusMatrixRows = null;

		function setPlayerFocus(pid) {
			focusedPlayer = pid;
			if (_focusMatchEls === null) _focusMatchEls = container.querySelectorAll('.tn-bv-match');
			if (_focusMatrixRows === null) _focusMatrixRows = container.querySelectorAll('.tn-rr-matrix tbody tr');
			if (pid) {
				container.classList.add('tn-rr-focus-active');
				var pInfo = pMap[pid];
				focusBanner.querySelector('.tn-rr-focus-banner-name').textContent = pInfo ? (pInfo.Alias || pInfo.Persona || '?') : '?';
				focusBanner.style.display = '';
				// Highlight matching match cards
				_focusMatchEls.forEach(function(card) {
					var mid = card.dataset.matchid;
					var m = matches.find(function(mm) { return String(mm.MatchId) === mid; });
					if (m && (String(m.Participant1Id) === String(pid) || String(m.Participant2Id) === String(pid))) {
						card.classList.add('tn-rr-focus-match');
					} else {
						card.classList.remove('tn-rr-focus-match');
					}
				});
				// Highlight matching matrix rows
				_focusMatrixRows.forEach(function(tr) {
					if (tr.dataset.pid === String(pid)) tr.classList.add('tn-rr-focus-row');
					else tr.classList.remove('tn-rr-focus-row');
				});
				if (standingsContainer && standingsContainer._tnSetActivePlayer) standingsContainer._tnSetActivePlayer(pid);
			} else {
				container.classList.remove('tn-rr-focus-active');
				focusBanner.style.display = 'none';
				container.querySelectorAll('.tn-rr-focus-match').forEach(function(el) { el.classList.remove('tn-rr-focus-match'); });
				container.querySelectorAll('.tn-rr-focus-row').forEach(function(el) { el.classList.remove('tn-rr-focus-row'); });
				if (standingsContainer && standingsContainer._tnSetActivePlayer) standingsContainer._tnSetActivePlayer(null);
			}
		}
		focusClose.onclick = function() { setPlayerFocus(null); };

		// ── Rounds view ──
		var roundsView = document.createElement('div');
		roundsView.className = 'tn-rr-rounds-view';

		var nav = document.createElement('div');
		nav.className = 'tn-bv-round-nav';
		var _savedRound = parseInt(sessionStorage.getItem('tnRRActiveRound_' + bracketId)) || 0;
		var _activeRound = (_savedRound >= 1 && _savedRound <= maxRound) ? _savedRound : 1;

		for (var r = 1; r <= maxRound; r++) {
			(function(round) {
				var btn = document.createElement('button');
				btn.className = 'tn-bv-round-btn' + (round === _activeRound ? ' active' : '');
				var rMatches = rounds[round] || [];
				var rDone = rMatches.filter(function(m) { return m.Result && m.Result !== ''; }).length;
				if (tbRoundIndex[round]) {
					btn.textContent = 'TB' + tbRoundIndex[round] + ' ';
					btn.classList.add('tn-bv-round-btn-tb');
				} else {
					btn.textContent = 'Round ' + round + ' ';
				}
				var countBadge = document.createElement('span');
				countBadge.className = 'tn-rr-round-count';
				countBadge.textContent = rDone + '/' + rMatches.length;
				btn.appendChild(countBadge);
				btn.dataset.round = round;
				btn.addEventListener('click', function() {
					nav.querySelectorAll('.tn-bv-round-btn').forEach(function(b) { b.classList.remove('active'); });
					btn.classList.add('active');
					roundsView.querySelectorAll('.tn-bv-round-section').forEach(function(s) {
						s.style.display = parseInt(s.dataset.round) === round ? '' : 'none';
					});
					sessionStorage.setItem('tnRRActiveRound_' + bracketId, round);
				});
				nav.appendChild(btn);
			})(r);
		}
		roundsView.appendChild(nav);

		for (var r = 1; r <= maxRound; r++) {
			(function(round) {
				var section = document.createElement('div');
				section.className = 'tn-bv-round-section';
				section.dataset.round = round;
				section.style.display = round === _activeRound ? '' : 'none';

				// Tiebreaker section header
				if (tbRoundIndex[round]) {
					var tbHdr = document.createElement('div');
					tbHdr.className = 'tn-bv-section-hdr tiebreaker-rr';
					var tiedNames = (rounds[round] || []).reduce(function(acc, m) {
						[m.Participant1Id, m.Participant2Id].forEach(function(pid) {
							var p = pMap[pid];
							var nm = p ? (p.Alias || p.Persona || ('#' + pid)) : ('#' + pid);
							if (acc.indexOf(nm) === -1) acc.push(nm);
						});
						return acc;
					}, []);
					tbHdr.innerHTML = '<i class="fas fa-medal"></i> Tiebreaker Round ' + tbRoundIndex[round] +
						' <span style="font-weight:600;opacity:0.8;text-transform:none;letter-spacing:0;font-size:11px;margin-left:6px;">— Mini Round-Robin: ' + tnEsc(tiedNames.join(', ')) + '</span>';
					section.appendChild(tbHdr);
				}

				var body = document.createElement('div');
				body.className = 'tn-rr-round-body';
				var rMatches = (rounds[round] || []).sort(function(a,b) { return (a.Order||0)-(b.Order||0); });
				rMatches.forEach(function(m) {
					var box = buildMatchBox(m, pMap, matches);
					enhanceRRMatchCard(box, m, pMap, rrStats);
					body.appendChild(box);
				});
				section.appendChild(body);
				roundsView.appendChild(section);
			})(r);
		}

		// Mark complete rounds
		var firstIncomplete = -1;
		for (var rc = 1; rc <= maxRound; rc++) {
			var rcMatches = rounds[rc] || [];
			var allDone = rcMatches.length > 0 && rcMatches.every(function(m) { return m.Result && m.Result !== ''; });
			if (allDone) {
				var rcBtn = nav.querySelector('[data-round="' + rc + '"]');
				if (rcBtn) rcBtn.classList.add('tn-rr-complete');
			} else if (firstIncomplete === -1) {
				firstIncomplete = rc;
			}
		}
		if (firstIncomplete > 1) {
			var pulseBtn = nav.querySelector('[data-round="' + firstIncomplete + '"]');
			if (pulseBtn) pulseBtn.classList.add('tn-rr-next-pulse');
		}

		container.appendChild(roundsView);

		// ── Matrix view (hidden by default) ──
		var matrixView = document.createElement('div');
		matrixView.className = 'tn-rr-matrix-view';
		matrixView.style.display = 'none';
		renderMatrixView(matrixView, matches, pMap);
		container.appendChild(matrixView);

		// View toggle wiring
		btnRounds.onclick = function() {
			btnRounds.classList.add('active'); btnMatrix.classList.remove('active');
			roundsView.style.display = ''; matrixView.style.display = 'none';
			sessionStorage.setItem('tnRRView_' + bracketId, 'rounds');
		};
		btnMatrix.onclick = function() {
			btnMatrix.classList.add('active'); btnRounds.classList.remove('active');
			matrixView.style.display = ''; roundsView.style.display = 'none';
			sessionStorage.setItem('tnRRView_' + bracketId, 'matrix');
		};
		// Restore saved view
		var _savedView = sessionStorage.getItem('tnRRView_' + bracketId);
		if (_savedView === 'matrix') { btnMatrix.onclick(); }

		// ── Enhanced standings ──
		if (completedMatches > 0) {
			standingsContainer = document.createElement('div');
			var stdRows = renderEnhancedStandings(standingsContainer, matches, pMap, function(pid) {
				setPlayerFocus(pid);
			}, _rrComputed, bracketId);
			container.appendChild(standingsContainer);

			// Champion banner (if bracket is complete/finalized)
			var bd0 = TnConfig.bracketData[bracketId];
			var bracketSt = bd0 && bd0.Bracket ? (bd0.Bracket.Status || '') : '';
			// ...but not while a tie for 1st is unresolved (tiebreaker not declined).
			var _tiedFor1st = (stdRows || []).filter(function(r) { return r.rank === 1; }).length >= 2
				&& parseInt((bd0 && bd0.Bracket && bd0.Bracket.TiebreakerDeclined) || 0) !== 1;
			if ((bracketSt === 'complete' || bracketSt === 'finalized') && !_tiedFor1st) {
				renderRRChampionBanner(container, stdRows, pMap);
			}

			// ── First-place tiebreaker banner ─────────────────────────────────────
			// Show when bracket is complete, 2+ players tied at rank 1, organizer
			// hasn't already declined, and there are no in-progress tiebreaker matches.
			if (TnConfig.canManage && bracketSt === 'complete') {
				var bdTb = TnConfig.bracketData[bracketId];
				var bracketRow = bdTb && bdTb.Bracket ? bdTb.Bracket : {};
				var declined = parseInt(bracketRow.TiebreakerDeclined || 0) === 1;
				// Tied at rank 1 — group by shared rank from renderEnhancedStandings
				var topTied = (function() {
					if (!stdRows || stdRows.length < 2) return [];
					var grp = stdRows.filter(function(r) { return r.rank === 1; });
					return grp.length >= 2 ? grp : [];
				})();
				if (!declined && topTied.length >= 2) {
					var tbBanner = document.createElement('div');
					tbBanner.className = 'tn-gf-confirm-banner';
					var nameListPlain = topTied.map(function(s) {
						var pi = pMap[s.p];
						return pi ? (pi.Alias || pi.Persona || ('#' + s.p)) : ('#' + s.p);
					}).join(', ');
					tbBanner.innerHTML =
						'<div class="tn-gf-confirm-text"><i class="fas fa-medal"></i> ' +
							'<strong>' + topTied.length + ' players are tied for 1st place</strong> (<span class="tn-tb-namelist"></span>). ' +
							'Run a tiebreaker round?</div>' +
						'<div class="tn-gf-confirm-btns">' +
							'<button class="tn-gf-confirm-yes"><i class="fas fa-check-circle"></i> Yes, Run Tiebreaker</button>' +
							'<button class="tn-gf-confirm-no"><i class="fas fa-handshake"></i> No, Joint Winners</button>' +
						'</div>';
					var nameListSpan = tbBanner.querySelector('.tn-tb-namelist');
					if (nameListSpan) nameListSpan.textContent = nameListPlain;
					container.insertBefore(tbBanner, container.firstChild);

					var tidRR = TnConfig.tournamentId;
					var refreshTb = function() {
						var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bracketId) : 0;
						Promise.all([
							fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bracketId + '/matches').then(function(r) { return r.json(); }),
							fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tidRR + '/brackets').then(function(r) { return r.json(); })
						]).then(function(results) {
							var mData = results[0], bData = results[1];
							var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bracketId, _rt); // a newer fetch of this bracket writes + paints
							if (_rtCur && mData.status === 0 && TnConfig.bracketData[bracketId]) TnConfig.bracketData[bracketId].Matches = mData.matches || [];
							if (_rtCur && bData.status === 0 && bData.brackets && TnConfig.bracketData[bracketId]) {
								var br = bData.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bracketId); });
								if (br) TnConfig.bracketData[bracketId].Bracket = br;
							}
							if (_rtCur) tnRenderBracketViz(bracketId);
							// Brackets-tab card (status badge, results) + stats + standings.
							if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bracketId);
						}).catch(function(err) { window.tnToast('Refresh error: ' + err); });
					};

					tbBanner.querySelector('.tn-gf-confirm-yes').onclick = function() {
						var fd = new FormData();
						fd.append('BracketId', bracketId);
						fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tidRR + '/roundrobintiebreaker', { method:'POST', body:fd })
							.then(function(r) { return r.json(); })
							.then(function(d) {
								if (d.status === 0) refreshTb();
								else window.tnToast('Error: ' + (d.error || 'Unknown error'));
							}).catch(function(err) { window.tnToast('Request failed: ' + err); });
					};

					tbBanner.querySelector('.tn-gf-confirm-no').onclick = function() {
						tnConfirm({
							title: 'Accept joint winners?',
							body: 'Accept <strong>' + topTied.length + '</strong> joint winners at 1st place?' + ((stdRows || []).length > topTied.length ? ' The next ranked player will be at ' + tnOrdinal(topTied.length + 1) + '.' : '') + ' <strong>This cannot be undone from the UI.</strong>',
							confirmLabel: 'Accept',
							danger: true,
							onConfirm: function() {
								var fd = new FormData();
								fd.append('BracketId', bracketId);
								fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tidRR + '/roundrobindecline', { method:'POST', body:fd })
									.then(function(r) { return r.json(); })
									.then(function(d) {
										if (d.status === 0) refreshTb();
										else window.tnToast('Error: ' + (d.error || 'Unknown error'));
									}).catch(function(err) { window.tnToast('Request failed: ' + err); });
							}
						});
					};
				}
			}
		}
	}


		// Initialize on page load
	document.addEventListener('DOMContentLoaded', function() {
		// Restore bracket collapse state across reloads
		var collKey = 'tnCollapsed_' + TnConfig.tournamentId;
		var collState = JSON.parse(sessionStorage.getItem(collKey) || '{}');
		Object.keys(collState).forEach(function(bid) {
			if (collState[bid]) {
				var card = document.getElementById('tn-bracket-' + bid);
				if (card) card.classList.add('tn-collapsed');
			}
		});

		// Restore the last-viewed Run bracket (set by tnRenderBracketViz) before any
		// tab activation reads #tn-bv-bracket-select.
		var _runBid = 0;
		try { _runBid = parseInt(sessionStorage.getItem('tnRunBracket_' + TnConfig.tournamentId), 10) || 0; } catch (e) {}
		if (_runBid && TnConfig.bracketData[_runBid]) {
			var _runSel = document.getElementById('tn-bv-bracket-select');
			if (_runSel) _runSel.value = _runBid;
			document.querySelectorAll('#tn-tab-bracketviz .tn-bk-pill').forEach(function(b) {
				b.classList.toggle('tn-bk-pill-active', parseInt(b.dataset.bid) === _runBid);
			});
		} else {
			_runBid = 0;
		}

		var tabToOpen = sessionStorage.getItem('tnOpenTab');
		if (tabToOpen) { sessionStorage.removeItem('tnOpenTab'); window._tnTabExplicit = true; tnActivateTab(tabToOpen); }
		// A just-added bracket: jump straight to it (expand + scroll) instead of
		// leaving the user at the top of the Brackets tab.
		var scrollBid = sessionStorage.getItem('tnScrollBracket');
		if (scrollBid) {
			sessionStorage.removeItem('tnScrollBracket');
			// Disable the browser's scroll restoration so it can't yank us back to
			// the top, then (after load) jump to the new bracket — re-asserting
			// across the window where restoration / late reflow may still fire.
			// Restoration is restored only well after everything settles; setting
			// it back to 'auto' at that point does not itself scroll.
			var _prevSR = ('scrollRestoration' in history) ? history.scrollRestoration : null;
			if (_prevSR !== null) history.scrollRestoration = 'manual';
			var _jumpToBracket = function() {
				var card = document.getElementById('tn-bracket-' + scrollBid);
				if (!card) return;
				card.classList.remove('tn-collapsed');
				card.scrollIntoView({block:'start', inline:'nearest'});
			};
			var _runJump = function() {
				[0, 250, 600, 1000].forEach(function(d) { setTimeout(_jumpToBracket, d); });
				if (_prevSR !== null) setTimeout(function() { history.scrollRestoration = _prevSR; }, 2500);
			};
			if (document.readyState === 'complete') _runJump();
			else window.addEventListener('load', _runJump);
		}
		// #63: Defer the BracketViz render (and its lazy match fetch) until the tab is
		// shown — the activation wrapper below, the "default to Run" auto-switch, and a
		// restored tnOpenTab all route through the wrapped tnActivateTab and render then.
		// Rendering eagerly here would fetch match data for About-only spectators who
		// never open the tab. Only render now if BracketViz is already the visible tab.
		var _bvPanel = document.getElementById('tn-tab-bracketviz');
		if (_bvPanel && _bvPanel.style.display !== 'none') {
			var firstId = _runBid || firstBracketId();
			if (firstId) tnRenderBracketViz(firstId);
		}

		// Restore focus mode across reloads (e.g. mid-tournament refresh)
		if (sessionStorage.getItem('tnFocusMode') === '1') tnSetFocus(true);

		// Esc exits focus mode (only when active, so modals keep their own Esc)
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Escape' || e.defaultPrevented) return;
			var root = document.getElementById('tn-root');
			if (root && root.classList.contains('tn-focus')) { tnSetFocus(false); }
		});
	});

	// Also render when tab is clicked
	var origActivate = window.tnActivateTab;
	// The bracket-viz hover card lives on <body>; a re-render or tab switch never
	// fires the match box's mouseleave, so hide it explicitly.
	var bvContainer = document.getElementById('tn-bv-container');
	if (bvContainer) bvContainer.addEventListener('mouseleave', tnHideTooltip);
	window.tnActivateTab = function(name) {
		tnHideTooltip();
		origActivate(name);
		if (name === 'bracketviz') {
			var sel = document.getElementById('tn-bv-bracket-select');
			var bid = sel ? parseInt(sel.value) : firstBracketId();
			if (bid) tnRenderBracketViz(bid);
		} else if (name === 'standings' && window._tnStandingsDirty && typeof tnRefreshStandings === 'function') {
			window._tnStandingsDirty = false;
			tnRefreshStandings(null, true).then(function(ok) { if (!ok) window._tnStandingsDirty = true; });
		}
	};
})();

// ============================================================
// Record Result Modal
// ============================================================
(function() {
	var OVERLAY = 'tn-recordresult-overlay';
	var bouts = [null, null, null, null, null, null, null, null, null]; // null | '1' | '2' per bout (up to best-of-9)
	var p1Name = '—', p2Name = '—';
	// Cached pip elements — populated lazily on first renderPips() call.
	var _pipCache1 = null, _pipCache2 = null;
	function ensurePipCache() {
		if (_pipCache1 && _pipCache1.length === 9) return;
		_pipCache1 = []; _pipCache2 = [];
		var c1 = document.getElementById('tn-rr-pips-1');
		var c2 = document.getElementById('tn-rr-pips-2');
		if (!c1 || !c2) { _pipCache1 = null; _pipCache2 = null; return; }
		for (var i = 0; i < 9; i++) {
			_pipCache1.push(c1.querySelector('[data-idx="' + i + '"]'));
			_pipCache2.push(c2.querySelector('[data-idx="' + i + '"]'));
		}
	}

	// ---- Pip rendering ----
	function renderPips() {
		ensurePipCache();
		if (!_pipCache1) return;
		for (var i = 0; i < 9; i++) {
			var pip1 = _pipCache1[i], pip2 = _pipCache2[i];
			if (!pip1 || !pip2) continue;
			pip1.className = 'tn-bout-pip';
			pip2.className = 'tn-bout-pip';
			if (bouts[i] === '1') { pip1.classList.add('tn-pip-win'); pip2.classList.add('tn-pip-loss'); }
			else if (bouts[i] === '2') { pip2.classList.add('tn-pip-win'); pip1.classList.add('tn-pip-loss'); }
		}
		updateScoreDisplay();
		updateResultFromBouts();
	}

	// ---- Score display ----
	function updateScoreDisplay() {
		var p1 = bouts.filter(function(b) { return b === '1'; }).length;
		var p2 = bouts.filter(function(b) { return b === '2'; }).length;
		var el = document.getElementById('tn-rr-bout-score');
		if (el) el.textContent = (p1 + p2 > 0) ? (p1 + ' – ' + p2) : '';
	}

	// ---- Auto-populate result dropdown from pip state ----
	function updateResultFromBouts() {
		var p1 = bouts.filter(function(b) { return b === '1'; }).length;
		var p2 = bouts.filter(function(b) { return b === '2'; }).length;
		var sel = document.getElementById('tn-rr-result');
		if (!sel || p1 + p2 === 0) return;
		if (p1 > p2)      sel.value = '1-wins';
		else if (p2 > p1) sel.value = '2-wins';
		else {
			// Even pips: only offer a tie in formats that can carry one. In an
			// elimination bracket a tie strands the match, so force a decider (#13).
			var _ov = document.getElementById(OVERLAY);
			var _m  = _ov ? _ov.getAttribute('data-method') : '';
			var _allowTie = window.tnMethodAllowsTie ? window.tnMethodAllowsTie(_m) : (_m === 'round-robin' || _m === 'swiss' || _m === 'points');
			sel.value = _allowTie ? 'tie' : '';
		}
	}

	// ---- Pip click handler ----
	['tn-rr-pips-1','tn-rr-pips-2'].forEach(function(containerId) {
		var container = document.getElementById(containerId);
		if (!container) return;
		container.addEventListener('click', function(e) {
			var pip = e.target.closest('.tn-bout-pip');
			if (!pip) return;
			var side = pip.dataset.side; // '1' or '2'
			var idx  = parseInt(pip.dataset.idx, 10);
			bouts[idx] = (bouts[idx] === side) ? null : side; // toggle or set
			renderPips();
		});
	});

	// ---- Open modal ----
	window.tnOpenRecordResult = function(match, p1, p2) {
		if (!TnConfig.canRecordResult) return;
		if (window.tnHideTooltip) window.tnHideTooltip();   // the mobile sheet path bypasses tnOpenModal
		bouts = [null, null, null, null, null, null, null, null, null];
		// #53: editing an already-recorded match — preload the stored bouts and the
		// recorded result instead of opening a blank form that invites an overwrite.
		var _isEdit = !!(match && match.Result && match.Result !== '');
		if (_isEdit && match.Bouts) {
			try {
				var _stored = JSON.parse(match.Bouts);
				if (Array.isArray(_stored)) {
					for (var _si = 0; _si < _stored.length && _si < 9; _si++) {
						bouts[_si] = (_stored[_si] === '1' || _stored[_si] === '2') ? _stored[_si] : null;
					}
				}
			} catch (e) {}
		}
		p1Name = p1 ? (p1.Alias || p1.Persona || '—') : '—';
		p2Name = p2 ? (p2.Alias || p2.Persona || '—') : '—';
		document.getElementById('tn-recordresult-match-id').value = match.MatchId;
		document.getElementById('tn-rr-p1-name').textContent = p1Name;
		document.getElementById('tn-rr-p2-name').textContent = p2Name;
		var opt1 = document.getElementById('tn-rr-opt-p1wins');
		var opt2 = document.getElementById('tn-rr-opt-p2wins');
		if (opt1) opt1.textContent = p1Name + ' wins';
		if (opt2) opt2.textContent = p2Name + ' wins';
		// Directional forfeit / DQ options — either participant can be the
		// forfeiting / disqualified side (values consumed by the result resolver).
		var _setOpt = function(id, txt) { var e = document.getElementById(id); if (e) e.textContent = txt; };
		_setOpt('tn-rr-opt-p1ff', p1Name + ' forfeits (' + p2Name + ' wins)');
		_setOpt('tn-rr-opt-p2ff', p2Name + ' forfeits (' + p1Name + ' wins)');
		_setOpt('tn-rr-opt-p1dq', p1Name + ' disqualified (' + p2Name + ' wins)');
		_setOpt('tn-rr-opt-p2dq', p2Name + ' disqualified (' + p1Name + ' wins)');
		var _bid = match.BracketId, _bdata = TnConfig.bracketData[_bid], _method = _bdata && _bdata.Bracket ? _bdata.Bracket.Method : '';
		var _bestOf = parseInt((_bdata && _bdata.Bracket && _bdata.Bracket.BestOf) || 1, 10);
		if ([1,3,5,7,9].indexOf(_bestOf) === -1) _bestOf = 1;
		// Show only the first _bestOf pip buttons on each side; the rest are hidden.
		['1','2'].forEach(function(side){
			for (var i = 0; i < 9; i++){
				var pip = document.querySelector('#tn-rr-pips-' + side + ' [data-idx="' + i + '"]');
				if (pip) pip.style.display = (i < _bestOf) ? '' : 'none';
			}
		});
		// Stash the effective best-of on the overlay so the auto-commit
		// layer (task 13) can read it for its remaining-pips math.
		var _ov = document.getElementById(OVERLAY);
		if (_ov) _ov.setAttribute('data-best-of', String(_bestOf));
		// #13: stash the bracket method so the pip/result logic can suppress ties in
		// elimination formats, and hide the Tie option unless the format allows one.
		if (_ov) _ov.setAttribute('data-method', _method || '');
		var _allowTie = window.tnMethodAllowsTie ? window.tnMethodAllowsTie(_method) : (_method === 'round-robin' || _method === 'swiss' || _method === 'points');
		var _tieOpt = document.getElementById('tn-rr-opt-tie');
		// display:none alone is ignored by iOS Safari's native picker and by keyboard
		// type-ahead, so also disable the option where ties aren't allowed.
		if (_tieOpt) { _tieOpt.style.display = _allowTie ? '' : 'none'; _tieOpt.disabled = !_allowTie; }
		document.getElementById('tn-rr-round-info').textContent = (_method === 'ironman'
			? 'Fight #' + (match.Match || '')
			: 'Round ' + match.Round + ', Match ' + (match.Match || ''))
			+ (_isEdit ? ' · Editing recorded result' : '');
		// #53: preload the recorded result for an edit (renderPips re-derives it from
		// the loaded pips for pip-based results; forfeit/DQ results have no pips so the
		// preset value survives).
		document.getElementById('tn-rr-result').value = _isEdit ? (match.Result || '') : '';
		document.getElementById('tn-rr-bout-score').textContent = '';
		renderPips();
		tnHideFeedback('tn-recordresult-feedback');
		// Present as a true bottom sheet on mobile (swipe-down dismiss, focus trap,
		// keyboard-safe footer via visualViewport). On desktop fall through to the
		// legacy centered-overlay open so behavior is byte-identical to before.
		// tnCloseModal already routes _tnSheet overlays through TnMobile.sheet.close,
		// so the submit-success + cancel/close paths tear down cleanly either way.
		tnOpenAsSheet(OVERLAY, {});
	};

	['tn-recordresult-close','tn-recordresult-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});

	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	var submitBtn = document.getElementById('tn-recordresult-submit');
	if (submitBtn) {
		submitBtn.addEventListener('click', function() {
			var btn    = this;
			var matchId = document.getElementById('tn-recordresult-match-id').value;
			var tid     = document.getElementById('tn-recordresult-tournament-id').value;
			var result  = document.getElementById('tn-rr-result').value;
			var p1w     = bouts.filter(function(b) { return b === '1'; }).length;
			var p2w     = bouts.filter(function(b) { return b === '2'; }).length;
			var winnerW = (tnWinnerSide(result) === 2) ? p2w : p1w;
			var loserW  = (tnWinnerSide(result) === 2) ? p1w : p2w;
			var score   = (p1w + p2w > 0) ? (winnerW + '-' + loserW) : '';

			if (!result) { tnShowFeedback('tn-recordresult-feedback', 'Please select a result.', false); return; }

			var url = TnConfig.uir + 'TournamentAjax/match/' + matchId + '/' + tid;
			btn.disabled = true;
			var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
			if (window.tnRegisterAction) window.tnRegisterAction(actionId);
			if (window.tnCollabNudge) window.tnCollabNudge();
			var fd = new FormData();
			fd.append('Result', result);
			fd.append('Score',  score);
			fd.append('Bouts',  JSON.stringify(bouts.filter(function(b) { return b !== null; })));
			fd.append('ActionId', actionId);

			fetch(url, { method:'POST', body:fd })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					btn.disabled = false;
					if (d && d.status === 0) {
						if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
						tnShowFeedback('tn-recordresult-feedback', 'Result saved!', true);
						setTimeout(function() {
							tnCloseModal(OVERLAY);
							var sel = document.getElementById('tn-bv-bracket-select');
							var bid = sel ? parseInt(sel.value) : 0;
							if (bid && TnConfig.bracketData[bid]) {
								var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0;
								Promise.all([
									fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r) { return r.json(); }),
									fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/brackets').then(function(r) { return r.json(); })
								]).then(function(res2) {
									var md = res2[0], bd2 = res2[1];
									var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bid, _rt); // a newer fetch of this bracket writes + paints
									if (_rtCur && md && md.status === 0) TnConfig.bracketData[bid].Matches = md.matches;
									if (_rtCur && bd2 && bd2.status === 0 && bd2.brackets && TnConfig.bracketData[bid]) {
										var br = bd2.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bid); });
										if (br) TnConfig.bracketData[bid].Bracket = br;
									}
									if (_rtCur) tnRenderBracketViz(bid);
									// #35: offer a tap-to-undo now that downstream state is fresh.
									if (window.tnResultRecordedToast) window.tnResultRecordedToast(matchId, bid);
								}).catch(function(err) { console.warn('[tn] refresh failed', err); if (window.tnShowStaleWarning) tnShowStaleWarning(); });
							}
						}, 400);
					} else {
						tnShowFeedback('tn-recordresult-feedback', (d && d.error) ? d.error : 'Failed to save result.', false);
					}
				})
				.catch(function() {
					btn.disabled = false;
					// #28: the write may have committed server-side before we timed out/failed.
					// Drop our own-action registration so the echo is treated as new and the
					// collab loop refetches, keeping the initiator's screen in sync.
					if (window.tnUnregisterAction) window.tnUnregisterAction(actionId);
					tnShowFeedback('tn-recordresult-feedback', 'Request failed.', false);
				});
		});
	}


window.tnToggleParticipantMenu = function(btn) {
	var menu = btn.parentNode.querySelector('.tn-status-menu');
	if (!menu) return;
	// G2: on mobile, replace the absolute dropdown with a bottom action sheet.
	// Reuse the SAME per-row menu items + remove button so the existing
	// tnSetParticipantStatus / tnRemoveParticipant logic (and its DOM updates)
	// run unchanged.
	if (window.TnMobile && TnMobile.sheet && TnMobile.viewMode
		&& TnMobile.viewMode.isMobile && TnMobile.viewMode.isMobile()) {
		var li = btn.closest('li');
		if (!li) return;
		var statusOf = function(s) { return menu.querySelector('.tn-status-menu-item .tn-sm-dot-' + s); };
		var itemFor = function(s) { var d = statusOf(s); return d ? d.parentNode : null; };
		var removeBtn = li.querySelector('.tn-remove-participant');
		var items = [];
		[['active','Active'], ['withdrawn','Withdrawn'], ['disqualified','Disqualified']].forEach(function(pair) {
			var menuItem = itemFor(pair[0]);
			if (!menuItem) return;
			var onclick = menuItem.getAttribute('onclick') || '';
			// Pull pid + bid out of the existing inline handler so we reuse the
			// exact same arguments the desktop dropdown uses; pass the real menu
			// item element as the 4th arg (menuItemEl) — tnSetParticipantStatus
			// needs it for .closest('li') / menu-state updates.
			var m = onclick.match(/(tnSetParticipantStatus|tnWithdrawIntent)\(\s*(\d+)\s*,\s*'([a-z]+)'\s*,\s*(\d+)/);
			items.push({
				label: pair[1] + (menuItem.classList.contains('tn-sm-active') ? '  \u2713' : ''),
				onTap: function() {
					if (m) {
						window[m[1]](parseInt(m[2], 10), m[3], parseInt(m[4], 10), menuItem);
					}
				}
			});
		});
		if (removeBtn && !removeBtn.disabled) {
			items.push({ label: 'Remove from bracket', danger: true, onTap: function() { tnRemoveParticipant(removeBtn); } });
		}
		TnMobile.sheet.actionSheet(items);
		return;
	}
	// Desktop: absolute dropdown (ARIA menu button). Close all other open menus first.
	document.querySelectorAll('.tn-status-menu.tn-status-open').forEach(function(m) {
		if (m !== menu) window.tnCloseStatusMenu(m, false);
	});
	if (menu.classList.contains('tn-status-open')) { window.tnCloseStatusMenu(menu, true); return; }
	menu.classList.add('tn-status-open');
	btn.setAttribute('aria-expanded', 'true');
	var first = menu.querySelector('[role="menuitem"].tn-sm-active') || menu.querySelector('[role="menuitem"]');
	if (first) first.focus();
};

// Closes a participant status menu, syncs aria-expanded, and optionally returns focus to its trigger.
window.tnCloseStatusMenu = function(menu, returnFocus) {
	if (!menu) return;
	var wasOpen = menu.classList.contains('tn-status-open');
	menu.classList.remove('tn-status-open');
	var trig = menu.parentNode ? menu.parentNode.querySelector('.tn-status-btn') : null;
	if (trig) {
		trig.setAttribute('aria-expanded', 'false');
		// Only reclaim focus if it is still inside the menu (don't steal it from a modal or another control).
		if (returnFocus && wasOpen && menu.contains(document.activeElement)) trig.focus();
	}
};

// Keyboard handling for participant status menus: arrows/Home/End move, Esc/Tab close.
document.addEventListener('keydown', function(e) {
	var menu = e.target && e.target.closest ? e.target.closest('.tn-status-menu.tn-status-open') : null;
	if (!menu) return;
	var items = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
	var i = items.indexOf(e.target);
	var next = null;
	if (e.key === 'ArrowDown') next = items[(i + 1) % items.length];
	else if (e.key === 'ArrowUp') next = items[(i - 1 + items.length) % items.length];
	else if (e.key === 'Home') next = items[0];
	else if (e.key === 'End') next = items[items.length - 1];
	else if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); e.stopPropagation(); window.tnCloseStatusMenu(menu, true); return; }
	else if (e.key === 'Tab') { window.tnCloseStatusMenu(menu, false); return; }
	else return;
	e.preventDefault();
	if (next) next.focus();
});

window.tnEditAlias = function(btn){
			var host = btn.closest('li, tr'); if (!host) return;
			var span = host.querySelector('.tn-alias-text'); if (!span || span.dataset.editing === '1') return;
			var pid = btn.getAttribute('data-pid'), bid = btn.getAttribute('data-bid');
			var current = span.getAttribute('data-alias') || span.textContent.trim();
			var originalHTML = span.innerHTML;
			span.dataset.editing = '1';
			var input = document.createElement('input');
			input.type = 'text'; input.className = 'tn-alias-input'; input.maxLength = 100; input.value = current;
			span.innerHTML = ''; span.appendChild(input);
			input.focus(); input.select();
			var done = false;
			function finish(save){
				if (done) return; done = true;
				var val = input.value.trim();
				if (!save || !val || val === current){ span.innerHTML = originalHTML; delete span.dataset.editing; return; }
				var fd = new FormData();
				fd.append('ParticipantId', pid); fd.append('TournamentId', TnConfig.tournamentId); fd.append('Alias', val);
				// #30: tag the rename so its echo is recognized as this tab's own change.
				var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
				if (window.tnRegisterAction) window.tnRegisterAction(actionId);
				fd.append('ActionId', actionId);
				fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/updatealias', {method:'POST', body:fd})
					.then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
					.then(function(d){
						span.innerHTML = originalHTML; delete span.dataset.editing;
						if (d && d.status === 0){
							var nm = d.alias || val; var link = span.querySelector('a');
							if (link) link.textContent = nm; else span.textContent = nm;
							span.setAttribute('data-alias', nm);
							// Patch the cached bracket data so the Run view / Match Results
							// don't keep showing the old name, then refetch + re-render.
							var bd = TnConfig.bracketData && TnConfig.bracketData[bid];
							if (bd) {
								(bd.Participants || []).forEach(function(p){ if (String(p.ParticipantId) === String(pid)) p.Alias = nm; });
								(bd.Matches || []).forEach(function(m){
									if (String(m.Participant1Id) === String(pid)) m.Participant1Alias = nm;
									if (String(m.Participant2Id) === String(pid)) m.Participant2Alias = nm;
								});
							}
							// Re-render the Run view only when it is showing this bracket;
							// otherwise it would swap in bid's content under another bracket's pill.
							var runSel = document.getElementById('tn-bv-bracket-select');
							if (runSel && String(runSel.value) === String(bid) && typeof window.tnRefreshAndRender === 'function') {
								window.tnRefreshAndRender(bid);
							} else {
								if (window.tnRefreshBracketCard) window.tnRefreshBracketCard(bid);
								if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
							}
						} else { window.tnToast((d && d.error) ? d.error : 'Failed to update name.'); }
					})
					.catch(function(){ span.innerHTML = originalHTML; delete span.dataset.editing; window.tnToast('Network error updating name.'); });
			}
			input.addEventListener('keydown', function(e){
				if (e.key === 'Enter'){ e.preventDefault(); finish(true); }
				else if (e.key === 'Escape'){ e.preventDefault(); finish(false); }
			});
			input.addEventListener('blur', function(){ finish(true); });
		};

	// Issue 3: for round-robin brackets, let the organizer choose how to resolve a
	// withdrawal/DQ (forfeit vs annul). Default per FIDE: <50% of this participant's
	// matches played -> annul; else forfeit. Other formats apply status directly.
	window.tnWithdrawIntent = function(pid, status, bid, menuItemEl) {
		var li = menuItemEl && menuItemEl.closest ? menuItemEl.closest('li') : null;
		var card = li && li.closest ? li.closest('.tn-bracket-card') : null;
		var method = card ? card.getAttribute('data-method') : '';
		// Only round-robin needs the forfeit/annul choice; others apply status directly.
		if (method !== 'round-robin') { window.tnSetParticipantStatus(pid, status, bid, menuItemEl, ''); return; }

		var verb = (status === 'disqualified') ? 'Disqualify' : 'Withdraw';
		function openModal(defMode) {
			// Close the ⋮ status menu so it doesn't linger behind the confirm.
			var openMenu = menuItemEl && menuItemEl.closest ? menuItemEl.closest('.tn-status-menu') : null;
			if (openMenu) window.tnCloseStatusMenu(openMenu, true);
			tnConfirm({
				title: verb + ' participant',
				body:
					'<p style="margin:0 0 10px">How should this round-robin participant\u2019s matches be resolved?</p>' +
					'<label style="display:block;margin-bottom:8px;cursor:pointer"><input type="radio" name="tn-wd-mode" value="forfeit"' + (defMode === 'forfeit' ? ' checked' : '') + '> <strong>Forfeit</strong> \u2014 already-fought matches stand; remaining matches become wins for their opponents.</label>' +
					'<label style="display:block;cursor:pointer"><input type="radio" name="tn-wd-mode" value="annul"' + (defMode === 'annul' ? ' checked' : '') + '> <strong>Annul</strong> \u2014 all of their matches stop counting toward everyone\u2019s standings.</label>',
				confirmLabel: verb,
				danger: true,
				onConfirm: function() {
					var sel = document.querySelector('input[name="tn-wd-mode"]:checked');
					window.tnSetParticipantStatus(pid, status, bid, menuItemEl, sel ? sel.value : defMode);
				}
			});
		}

		// FIDE default from this participant's played fraction.
		function defaultFrom(matches) {
			var total = 0, played = 0;
			(matches || []).forEach(function(m) {
				var p1 = parseInt(m.Participant1Id, 10), p2 = parseInt(m.Participant2Id, 10);
				if (p1 === pid || p2 === pid) {
					if (p1 > 0 && p2 > 0) { // ignore bye/placeholder slots
						total++;
						if ((m.Result || '').toString().trim() !== '') played++;
					}
				}
			});
			if (total === 0) return 'forfeit';
			return (played / total < 0.5) ? 'annul' : 'forfeit';
		}

		// Prefer cached matches; otherwise fetch; on any failure default to forfeit.
		var cached = (window.TnConfig && TnConfig.bracketData && TnConfig.bracketData[bid]) ? TnConfig.bracketData[bid].Matches : null;
		if (cached && cached.length) {
			openModal(defaultFrom(cached));
		} else {
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches')
				.then(function(r) { return r.json(); })
				.then(function(d) { openModal(defaultFrom(d && d.status === 0 ? d.matches : [])); })
				.catch(function() { openModal('forfeit'); });
		}
	};
		window.tnSetParticipantStatus = function(pid, status, bid, menuItemEl, mode) {
	var fd = new FormData();
	fd.append('ParticipantId', pid);
	fd.append('Status', status);
	fd.append('TournamentId', TnConfig.tournamentId);
	if (mode) fd.append('Mode', mode);
	var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
	if (window.tnRegisterAction) window.tnRegisterAction(actionId);
	if (window.tnCollabNudge) window.tnCollabNudge();
	fd.append('ActionId', actionId);
	fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/updateparticipantstatus', {method:'POST', body:fd})
		.then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
		.then(function(d) {
			if (d && d.status === 0) {
				if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
				var li = menuItemEl.closest('li');
				if (li) {
					// Clear all status classes
					['active','withdrawn','disqualified'].forEach(function(s) {
						li.classList.remove('tn-pstatus-' + s);
					});
					li.classList.remove('tn-checked');
					if (status !== 'active') li.classList.add('tn-pstatus-' + status);
					if (status === 'present') li.classList.add('tn-checked');
					li.dataset.status = status;


					// Update active indicator in menu
					var menu = menuItemEl.closest('.tn-status-menu');
					if (menu) {
						menu.querySelectorAll('.tn-status-menu-item').forEach(function(item) {
							item.classList.remove('tn-sm-active');
						});
						menuItemEl.classList.add('tn-sm-active');
					}

					// Update status pills
					li.querySelectorAll('.tn-pstatus-pill').forEach(function(pill) { pill.remove(); });
					var nameSpan = li.querySelector('span[style*="flex:1"]');
					if (nameSpan && status === 'withdrawn') {
						var pill = document.createElement('span');
						pill.className = 'tn-pstatus-pill tn-pstatus-pill-withdrawn';
						pill.textContent = 'WD';
						nameSpan.appendChild(pill);
					} else if (nameSpan && status === 'disqualified') {
						var pill = document.createElement('span');
						pill.className = 'tn-pstatus-pill tn-pstatus-pill-disqualified';
						pill.textContent = 'DQ';
						nameSpan.appendChild(pill);
					}
				}
				// Close the menu
				var menuWrap = menuItemEl.closest('.tn-status-menu');
				if (menuWrap) window.tnCloseStatusMenu(menuWrap, true);

				// Reflect bracket changes (walkover advancement / completion) immediately.
				if (typeof window.tnRefreshAndRender === 'function') window.tnRefreshAndRender(bid);
			} else {
				window.tnToast((d && d.error) ? d.error : 'Failed to update status.');
			}
		})
		.catch(function() { window.tnToast('Network error updating status.'); });
};

// Close status menus when clicking elsewhere
document.addEventListener('click', function(e) {
	if (!e.target.closest('.tn-status-wrap')) {
		document.querySelectorAll('.tn-status-menu.tn-status-open').forEach(function(m) {
			window.tnCloseStatusMenu(m, false);
		});
	}
});

// Shows a small non-blocking stale-data warning at the top of the bracket viz.
window.tnShowStaleWarning = function() {
	var cont = document.getElementById('tn-bv-container');
	if (!cont) return;
	if (cont.querySelector('.tn-bv-stale-warning')) return;
	var w = document.createElement('div');
	w.className = 'tn-bv-stale-warning';
	w.textContent = 'Refresh failed — reload page if data looks wrong';
	cont.insertBefore(w, cont.firstChild);
};

window.tnSubmitQuickResult = function(matchId, result, event) {
	if (event) event.stopPropagation();
	var btn = (event && event.currentTarget) ? event.currentTarget : ((event && event.target) ? event.target : null);
	if (btn) btn.disabled = true;
	var tid = TnConfig.tournamentId;

	var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
	if (window.tnRegisterAction) window.tnRegisterAction(actionId);

	// Optimistic: mark the match result locally + show a pending state immediately.
	var sel = document.getElementById('tn-bv-bracket-select');
	var bid = sel ? parseInt(sel.value) : 0;
	var prevResult = null, matchObj = null;
	if (bid && TnConfig.bracketData[bid]) {
		(TnConfig.bracketData[bid].Matches || []).forEach(function(m) {
			if (parseInt(m.MatchId) === parseInt(matchId)) { matchObj = m; }
		});
		if (matchObj) {
			prevResult = matchObj.Result;
			matchObj.Result = result;
			matchObj._pending = true;
			if (typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid);
		}
	}
	if (window.tnCollabNudge) window.tnCollabNudge();

	var fd = new FormData();
	fd.append('Result', result);
	fd.append('Score', '');
	fd.append('Bouts', '[]');
	fd.append('ActionId', actionId);

	fetch(TnConfig.uir + 'TournamentAjax/match/' + matchId + '/' + tid, {method:'POST', body:fd})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d && d.status === 0) {
				if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
				if (bid && TnConfig.bracketData[bid]) {
					var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0;
					Promise.all([
						fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r) { return r.json(); }),
						fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); })
					]).then(function(results) {
						var md = results[0], bd = results[1];
						var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bid, _rt); // a newer fetch of this bracket writes + paints
						if (_rtCur && md && md.status === 0) TnConfig.bracketData[bid].Matches = md.matches;
						if (_rtCur && bd && bd.status === 0 && bd.brackets && TnConfig.bracketData[bid]) {
							var br = bd.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(bid); });
							if (br) TnConfig.bracketData[bid].Bracket = br;
						}
						if (_rtCur) tnRenderBracketViz(bid);
						// #35: offer a tap-to-undo now that downstream state is fresh.
						if (window.tnResultRecordedToast) window.tnResultRecordedToast(matchId, bid);
					}).catch(function(err) { console.warn('[tn] refresh failed', err); tnShowStaleWarning(); });
				}
			} else {
				// Reject — roll back the optimistic change and tell the reeve why.
				if (matchObj) { matchObj.Result = prevResult; delete matchObj._pending; if (typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid); }
				if (btn) btn.disabled = false;
				if (window.tnToast) window.tnToast((d && d.error) ? d.error : 'Result not saved — it may have just been recorded by someone else.');
			}
		})
		.catch(function() {
			if (matchObj) { matchObj.Result = prevResult; delete matchObj._pending; if (typeof tnRenderBracketViz === 'function') tnRenderBracketViz(bid); }
			if (btn) btn.disabled = false;
			if (window.tnToast) window.tnToast('Network error recording result.');
		});
};

})();
</script>

<!-- =====================================================================
     UX workflow layer.
     Each section is an independent, revertable feature. Remove a
     section by deleting its labeled block — every section is a
     self-contained function invocation that does not depend on the
     others. No shared state beyond TnConfig.
     ===================================================================== -->
<style id="tn-ux-styles">
/* Next-Up strip — task 14. Same palette as the rest of the tn- page. */
#tn-nextup:empty { display:none; }
#tn-nextup { margin: 0 0 14px; }
.tn-nu-wrap { background:#f7fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px 14px; }
.tn-nu-header { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.tn-nu-title { font-size:11px; font-weight:800; color:#4a5568; letter-spacing:0.6px; text-transform:uppercase; }
.tn-nu-sub { font-size:11px; color:#a0aec0; }
.tn-nu-header .tn-boutlist-trigger { margin-left:auto; }
.tn-nu-header .tn-boutlist-trigger + .tn-nu-toggle { margin-left:8px; }
.tn-nu-header .tn-nu-toggle { margin-left:auto; }
.tn-nu-toggle { display:inline-flex; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden; background:#fff; flex-shrink:0; }
.tn-nu-toggle-btn { padding:6px 12px; font-size:10px; font-weight:700; color:#718096; background:#fff; border:none; cursor:pointer; border-right:1px solid #e2e8f0; text-transform:uppercase; letter-spacing:0.6px; }
.tn-nu-toggle-btn:last-child { border-right:none; }
.tn-nu-toggle-btn:hover:not(.tn-nu-toggle-on) { background:#f7fafc; color:#4a5568; }
.tn-nu-toggle-btn.tn-nu-toggle-on { background:#276749; color:#fff; }
.tn-nu-grid { display:grid; grid-template-columns:1fr; gap:10px; }
.tn-nu-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; display:flex; align-items:center; gap:10px; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
.tn-nu-pos-label { flex-shrink:0; padding:3px 10px; font-size:10px; font-weight:800; letter-spacing:0.6px; text-transform:uppercase; border-radius:10px; }
.tn-nu-pos-label.tn-nu-now  { background:#276749; color:#fff; }
.tn-nu-pos-label.tn-nu-deck { background:#edf2f7; color:#718096; border:1px solid #e2e8f0; }
.tn-nu-match-num { font-size:10px; color:#a0aec0; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }
.tn-nu-players { flex:1; min-width:0; font-size:14px; line-height:1.25; }
.tn-nu-players .tn-nu-p { font-weight:700; color:#1a202c; }
.tn-nu-players .tn-nu-vs { color:#a0aec0; font-size:11px; padding:0 6px; }
.tn-nu-players .tn-nu-p-seed { display:inline-block; width:16px; height:16px; border-radius:50%; background:#edf2f7; color:#718096; font-size:9px; font-weight:800; text-align:center; line-height:16px; margin-right:4px; vertical-align:middle; }
.tn-nu-actions { display:flex; gap:6px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end; }
.tn-nu-btn { padding:7px 12px; border-radius:6px; font-size:12px; font-weight:700; border:1px solid #e2e8f0; background:#fff; color:#4a5568; cursor:pointer; white-space:nowrap; }
.tn-nu-btn:hover { background:#f7fafc; border-color:#cbd5e0; }
.tn-nu-btn-p1, .tn-nu-btn-p2 { background:#276749; color:#fff; border-color:#276749; }
.tn-nu-btn-p1:hover, .tn-nu-btn-p2:hover { background:#1e4e36; }
.tn-nu-btn-tie { color:#718096; }
/* Track Fights mode: single horizontal row —  X ●●●●● vs ●●●●● Y */
.tn-nu-card-track { flex-direction:row; align-items:center; gap:12px; padding:10px 14px; flex-wrap:nowrap; }
.tn-nu-mini-name { font-size:14px; font-weight:700; color:#1a202c; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; display:flex; align-items:center; gap:6px; }
.tn-nu-mini-name-1 { flex:1 1 0; justify-content:flex-end; text-align:right; }
.tn-nu-mini-name-2 { flex:1 1 0; justify-content:flex-start; text-align:left; }
.tn-nu-mini-name span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tn-nu-track-pips { display:flex; gap:5px; flex-shrink:0; }
.tn-nu-track-pips .tn-bout-pip { width:22px; height:22px; }
.tn-nu-mini-vs { font-size:11px; font-weight:700; color:#a0aec0; text-transform:uppercase; letter-spacing:0.4px; flex-shrink:0; padding:0 2px; }
.tn-nu-card-track .tn-nu-btn-more { flex-shrink:0; }
.tn-nu-btn-end { flex-shrink:0; background:#c53030; color:#fff; border-color:#c53030; display:none; }
.tn-nu-btn-end:hover { background:#9b2c2c; border-color:#9b2c2c; }
.tn-nu-btn-end.tn-nu-btn-end-tie { background:#718096; border-color:#718096; }
.tn-nu-btn-end.tn-nu-btn-end-tie:hover { background:#4a5568; border-color:#4a5568; }
.tn-nu-btn-end.tn-nu-btn-end-show { display:inline-flex; }
.tn-nu-btn-more { color:#a0aec0; font-size:11px; font-weight:600; padding:7px 8px; }
.tn-nu-empty { font-size:12px; font-style:italic; color:#a0aec0; padding:4px 0; }
.tn-nu-card.tn-nu-card-error { animation:tnNuCardErr 1.2s ease; }
@keyframes tnNuCardErr { 0%,100% { box-shadow:none; } 20% { box-shadow:0 0 0 2px #e53e3e; background:#fff5f5; } }
@media (max-width: 720px) {
	.tn-nu-card:not(.tn-nu-card-track) { flex-direction:column; align-items:stretch; }
	.tn-nu-actions { justify-content:stretch; }
	.tn-nu-btn { flex:1; text-align:center; padding:10px 8px; }
}

/* Dark mode — Next-Up strip */
html[data-theme="dark"] .tn-nu-wrap { background:#1a202c; border-color:#4a5568; }
html[data-theme="dark"] .tn-nu-title { color:#cbd5e0; }
html[data-theme="dark"] .tn-nu-sub { color:#a0aec0; }
html[data-theme="dark"] .tn-nu-toggle { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .tn-nu-toggle-btn { background:#2d3748; color:#a0aec0; border-right-color:#4a5568; }
html[data-theme="dark"] .tn-nu-toggle-btn:hover:not(.tn-nu-toggle-on) { background:#1a202c; color:#cbd5e0; }
html[data-theme="dark"] .tn-nu-toggle-btn.tn-nu-toggle-on { background:#38a169; color:#fff; }
html[data-theme="dark"] .tn-nu-card { background:#2d3748; border-color:#4a5568; box-shadow:0 1px 3px rgba(0,0,0,0.3); }
html[data-theme="dark"] .tn-nu-pos-label.tn-nu-now  { background:#38a169; color:#fff; }
html[data-theme="dark"] .tn-nu-pos-label.tn-nu-deck { background:#1a202c; color:#a0aec0; border-color:#4a5568; }
html[data-theme="dark"] .tn-nu-match-num { color:#a0aec0; }
html[data-theme="dark"] .tn-nu-players .tn-nu-p { color:#f7fafc; }
html[data-theme="dark"] .tn-nu-players .tn-nu-vs { color:#a0aec0; }
html[data-theme="dark"] .tn-nu-players .tn-nu-p-seed { background:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-nu-btn { background:#1a202c; border-color:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-nu-btn:hover { background:#2d3748; border-color:#718096; }
html[data-theme="dark"] .tn-nu-btn-p1, html[data-theme="dark"] .tn-nu-btn-p2 { background:#38a169; color:#fff; border-color:#68d391; }
html[data-theme="dark"] .tn-nu-btn-p1:hover, html[data-theme="dark"] .tn-nu-btn-p2:hover { background:#48bb78; }
html[data-theme="dark"] .tn-nu-btn-tie { color:#a0aec0; }
html[data-theme="dark"] .tn-nu-mini-name { color:#f7fafc; }
html[data-theme="dark"] .tn-nu-mini-vs { color:#a0aec0; }
html[data-theme="dark"] .tn-nu-btn-end { background:#c53030; border-color:#c53030; color:#fff; }
html[data-theme="dark"] .tn-nu-btn-end:hover { background:#9b2c2c; border-color:#9b2c2c; }
html[data-theme="dark"] .tn-nu-btn-end.tn-nu-btn-end-tie { background:#4a5568; border-color:#4a5568; color:#cbd5e0; }
html[data-theme="dark"] .tn-nu-btn-end.tn-nu-btn-end-tie:hover { background:#718096; border-color:#718096; color:#fff; }
html[data-theme="dark"] .tn-nu-btn-more { color:#a0aec0; }
html[data-theme="dark"] .tn-nu-empty { color:#a0aec0; }

/* =============================================================
   Track R2 — mobile Bout List jump sheet. A scrollable list of EVERY
   bout (from tnSequencedBouts) in true fought order, with per-bout
   status (done / current / on-deck / upcoming / not-ready). Tapping a
   ready row jumps the Match Deck to lead with that bout (tnDeckFocus).
   Surfaced via a "Bout List" pill in the mobile deck header.
   ============================================================= */
.tn-boutlist-trigger {
	flex-shrink:0; display:inline-flex; align-items:center; gap:6px;
	padding:6px 12px; font-size:10px; font-weight:800; letter-spacing:0.6px;
	text-transform:uppercase; border-radius:6px; cursor:pointer;
	background:#fff; color:#276749; border:1px solid #c6f6d5;
}
.tn-boutlist-trigger:hover { background:#f0fff4; }
/* #56: shown on desktop (opens a centered panel) AND mobile (the jump sheet). */
.tn-mobile .tn-boutlist-trigger { min-height:var(--tn-touch, 44px); }
/* Desktop width for the Bout List sheet box (moved off the inline style so the
   `.tn-mobile .tn-overlay .tn-modal-box { width:100%; max-width:100% }` rule — higher
   specificity — wins on mobile and the sheet goes full-width like the others). */
.tn-boutlist-box { width:520px; max-width:calc(100vw - 40px); }

.tn-boutlist-count { font-size:12px; font-weight:700; color:#718096; }
.tn-boutlist-list { display:flex; flex-direction:column; }
.tn-boutlist-row {
	display:flex; align-items:center; gap:10px;
	padding:11px 6px; min-height:var(--tn-touch, 44px);
	border-bottom:1px solid #edf2f7; border-left:3px solid transparent;
	text-align:left; width:100%; background:none; cursor:pointer;
	font-size:14px; color:#2d3748;
}
.tn-boutlist-row:last-child { border-bottom:none; }
.tn-boutlist-glyph { flex-shrink:0; width:16px; text-align:center; font-size:13px; }
.tn-boutlist-side {
	flex-shrink:0; min-width:42px; padding:2px 7px; border-radius:9px;
	font-size:10px; font-weight:800; letter-spacing:0.4px; text-align:center;
	background:#edf2f7; color:#718096;
}
.tn-boutlist-bout { flex:1; min-width:0; display:flex; flex-direction:column; gap:1px; }
.tn-boutlist-names { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tn-boutlist-vs { color:#a0aec0; font-weight:600; margin:0 5px; font-size:12px; }
.tn-boutlist-seed {
	display:inline-block; min-width:15px; height:15px; line-height:15px;
	text-align:center; font-size:9px; font-weight:800; border-radius:8px;
	background:#edf2f7; color:#718096; margin-right:4px; padding:0 3px;
}
.tn-boutlist-trail { flex-shrink:0; display:flex; align-items:center; gap:6px; }
.tn-boutlist-statuspill {
	font-size:9px; font-weight:800; letter-spacing:0.4px; text-transform:uppercase;
	padding:2px 8px; border-radius:9px; white-space:nowrap;
}
/* status: done */
.tn-boutlist-row--done { color:#718096; }
.tn-boutlist-row--done .tn-boutlist-glyph { color:#38a169; }
.tn-boutlist-scorepill {
	font-size:10px; font-weight:800; padding:2px 8px; border-radius:10px;
	background:#c6f6d5; color:#276749; white-space:nowrap;
}
/* status: current */
.tn-boutlist-row--current { border-left-color:#38a169; background:#f0fff4; }
.tn-boutlist-row--current .tn-boutlist-glyph { color:#38a169; }
.tn-boutlist-row--current .tn-boutlist-statuspill { background:#276749; color:#fff; }
/* status: on-deck */
.tn-boutlist-row--ondeck .tn-boutlist-glyph { color:#d69e2e; }
.tn-boutlist-row--ondeck .tn-boutlist-statuspill { background:#fefcbf; color:#975a16; }
/* status: upcoming */
.tn-boutlist-row--upcoming .tn-boutlist-glyph { color:#a0aec0; }
.tn-boutlist-row--upcoming .tn-boutlist-statuspill { background:#edf2f7; color:#718096; }
/* status: not-ready (non-actionable) */
.tn-boutlist-row--notready { color:#a0aec0; cursor:default; pointer-events:none; font-style:italic; }
.tn-boutlist-row--notready .tn-boutlist-glyph { color:#cbd5e0; }
.tn-boutlist-row--notready .tn-boutlist-side { background:#f7fafc; color:#cbd5e0; }
.tn-boutlist-tbd { color:#a0aec0; font-style:italic; }
.tn-boutlist-empty { padding:24px 12px; text-align:center; color:#a0aec0; font-size:14px; }

/* #36 — reeve presence "others here" badge. Subtle, dark-mode aware. */
.tn-presence-badge {
	display:inline-flex; align-items:center; gap:3px; vertical-align:middle;
	margin-left:8px; padding:1px 7px; border-radius:9px; white-space:nowrap;
	font-size:10px; font-weight:800; letter-spacing:0.3px;
	background:#ebf8ff; color:#2b6cb0; border:1px solid #bee3f8;
}
.tn-presence-badge i { font-size:9px; opacity:0.85; }
html[data-theme="dark"] .tn-presence-badge { background:#1a2a3a; color:#90cdf4; border-color:#2c5282; }

/* Dark-mode parity */
html[data-theme="dark"] .tn-boutlist-trigger { background:#1a202c; color:#9ae6b4; border-color:#2f5540; }
html[data-theme="dark"] .tn-boutlist-trigger:hover { background:#22543d; }
html[data-theme="dark"] .tn-boutlist-count { color:#a0aec0; }
html[data-theme="dark"] .tn-boutlist-row { border-bottom-color:#2d3748; color:#e2e8f0; }
html[data-theme="dark"] .tn-boutlist-side { background:#2d3748; color:#a0aec0; }
html[data-theme="dark"] .tn-boutlist-vs { color:#718096; }
html[data-theme="dark"] .tn-boutlist-seed { background:#2d3748; color:#a0aec0; }
html[data-theme="dark"] .tn-boutlist-row--done { color:#a0aec0; }
html[data-theme="dark"] .tn-boutlist-row--done .tn-boutlist-glyph { color:#68d391; }
html[data-theme="dark"] .tn-boutlist-scorepill { background:rgba(56,161,105,0.25); color:#9ae6b4; }
html[data-theme="dark"] .tn-boutlist-row--current { border-left-color:#68d391; background:#22543d; }
html[data-theme="dark"] .tn-boutlist-row--current .tn-boutlist-glyph { color:#68d391; }
html[data-theme="dark"] .tn-boutlist-row--current .tn-boutlist-statuspill { background:#38a169; color:#fff; }
html[data-theme="dark"] .tn-boutlist-row--ondeck .tn-boutlist-glyph { color:#ecc94b; }
html[data-theme="dark"] .tn-boutlist-row--ondeck .tn-boutlist-statuspill { background:#5f4c12; color:#faf089; }
html[data-theme="dark"] .tn-boutlist-row--upcoming .tn-boutlist-glyph { color:#718096; }
html[data-theme="dark"] .tn-boutlist-row--upcoming .tn-boutlist-statuspill { background:#2d3748; color:#a0aec0; }
html[data-theme="dark"] .tn-boutlist-row--notready { color:#718096; }
html[data-theme="dark"] .tn-boutlist-row--notready .tn-boutlist-glyph { color:#4a5568; }
html[data-theme="dark"] .tn-boutlist-row--notready .tn-boutlist-side { background:#1a202c; color:#4a5568; }
html[data-theme="dark"] .tn-boutlist-tbd { color:#718096; }
html[data-theme="dark"] .tn-boutlist-empty { color:#718096; }
/* Team member-count chip (deck cards + bout list) */
.tn-team-chip {
	display:inline-block; min-width:15px; height:15px; line-height:15px;
	text-align:center; font-size:9px; font-weight:800; border-radius:8px;
	background:#bee3f8; color:#2b6cb0; margin-left:4px; padding:0 3px;
	vertical-align:middle; cursor:help;
}
html[data-theme="dark"] .tn-team-chip { background:#2a4a6b; color:#90cdf4; }
</style>
<script>
(function(){
	'use strict';
	function $(id){ return document.getElementById(id); }

	// ================================================================
	// TASK 11 · PASTE ROSTER
	// Bulk-adds participants one line at a time via the existing
	// TournamentAjax/bracket/{bid}/addparticipant endpoint. Shows inline
	// progress, single reload at the end.
	// ================================================================
	window.tnOpenBulkAddModal = function(bracketId, tournamentId){
		if (!TnConfig.canManage) return;
		$('tn-bulkadd-bracket-id').value    = bracketId;
		$('tn-bulkadd-tournament-id').value = tournamentId;
		$('tn-bulkadd-text').value = '';
		$('tn-bulkadd-feedback').style.display = 'none';
		$('tn-bulkadd-feedback').style.whiteSpace = '';
		$('tn-bulkadd-progress').style.display = 'none';
		$('tn-bulkadd-submit').disabled = false;
		tnUpdateBulkCount();
		// Mobile: near-full-height bottom sheet (textarea flex:1, sticky footer);
		// desktop: legacy centered overlay (unchanged).
		tnOpenAsSheet('tn-bulkadd-overlay', {});
		setTimeout(function(){ var t = $('tn-bulkadd-text'); if (t) t.focus(); }, 80);
	};
	function closeBulkAdd(){ tnCloseModal('tn-bulkadd-overlay'); }
	// Set after a partially-successful paste: the next close of the modal (any
	// path — buttons, backdrop, Esc, swipe) reloads so the successful adds appear.
	var _bulkReloadOnClose = false;
	function tnBulkReload(){
		_bulkReloadOnClose = false;
		try { sessionStorage.setItem('tnOpenTab', 'brackets'); } catch (e) {}
		window.location.reload();
	}
	// Live line-count -> Add button label ("Add 12 fighters"). Counts only
	// non-blank lines (matches the submit-time filter at the bulk handler).
	window.tnUpdateBulkCount = function(){
		var ta = $('tn-bulkadd-text'); var btn = $('tn-bulkadd-submit');
		if (!ta || !btn) return;
		var n = (ta.value || '').split(/\r?\n/).filter(function(l){ return l.trim().length > 0; }).length;
		var label = n > 0 ? ('Add ' + n + ' fighter' + (n === 1 ? '' : 's')) : 'Add All';
		btn.innerHTML = '<i class="fas fa-users"></i> ' + label;
	};
	var _bulkTa = $('tn-bulkadd-text');
	if (_bulkTa) _bulkTa.addEventListener('input', window.tnUpdateBulkCount);
	['tn-bulkadd-close','tn-bulkadd-cancel'].forEach(function(id){
		var el = $(id); if (el) el.addEventListener('click', closeBulkAdd);
	});
	var _bulkOv = $('tn-bulkadd-overlay');
	if (_bulkOv) _bulkOv.addEventListener('click', function(e){ if (e.target === _bulkOv) closeBulkAdd(); });
	if (_bulkOv && window.MutationObserver) new MutationObserver(function(){
		if (_bulkReloadOnClose && !_bulkOv.classList.contains('tn-open')) tnBulkReload();
	}).observe(_bulkOv, { attributes: true, attributeFilter: ['class'] });

	// ================================================================
	// TASK 15 · DEFAULT to Run Tournament tab when matches exist
	// The Brackets tab is the default landing surface, which makes
	// sense during setup. Once any bracket has generated matches,
	// the tournament is running and the marshal almost always wants
	// the Run Tournament tab instead. Honors a #hash override so
	// deep links still land where they aim.
	// ================================================================
	(function(){
		if (window.location.hash){
			var h = window.location.hash.replace('#','');
			// A tab-name hash deep-links to that tab (an explicit post-action tab still wins).
			if (['about','brackets','participants','bracketviz','standings'].indexOf(h) !== -1) {
				var activateHash = function(){
					if (window._tnTabExplicit) return;
					if (typeof window.tnActivateTab === 'function') window.tnActivateTab(h);
				};
				if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', activateHash);
				else activateHash();
				return;
			}
		}
		if (!TnConfig.bracketData) return;
		var anyMatches = false;
		for (var bid in TnConfig.bracketData){
			var bd = TnConfig.bracketData[bid];
			if (bd && (((bd.Matches || []).length > 0) || bd._hasMatches)){ anyMatches = true; break; }
		}
		if (!anyMatches) return;
		function activate(){
			// An explicit post-action navigation (e.g. just added a bracket) wins
			// over the "default to Run Tournament" heuristic.
			if (window._tnTabExplicit) return;
			if (typeof window.tnActivateTab === 'function') window.tnActivateTab('bracketviz');
		}
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', activate);
		else activate();
	})();

	// ================================================================
	// TASK 14 · NEXT-UP live strip
	// Pinned above the bracket visualization. Always shows up to 2
	// upcoming matches (labeled NOW and ON DECK), dynamically moving
	// forward as results come in. A header toggle flips between
	// "Quick Win" mode (P1/P2/Tie buttons → one tap commits) and
	// "Track Fights" mode (green/red bout pips → auto-commits on
	// mathematical majority). Mode choice persists across reloads.
	// ================================================================
	(function(){
		var nuHost = null;
		var mode = 'quick';            // 'quick' | 'track'
		var trackState = Object.create(null); // matchId -> [null|'1'|'2', × bestOf]
		var activeBestOf = 1;          // effective best-of for the currently rendered bracket
		var activeMethod = '';         // method of the currently rendered bracket (tie suppression, #13)
		var MODE_KEY = 'tn_nu_mode_' + (TnConfig.tournamentId || 0);

		// --- Mobile Match Deck (Track R) ---------------------------------------
		// On mobile, the Next-Up flow is presented through the TnMobile.deck
		// primitive: a 3-item window (current fight + next 2 on-deck). The deck
		// mounts INTO the SAME #tn-nextup host (nuHost), so the existing card
		// builders (quickCardHTML / trackCardHTML), pip handlers, repaintCardPips
		// and updateEndButton all keep working unchanged (they query nuHost and
		// match on data-mid). Only ONE rendering ever occupies #tn-nextup at a
		// time — the desktop grid OR the mobile deck — so there is no data-mid
		// collision. The deck handle is torn down whenever we leave mobile.
		var deckHandle = null;          // TnMobile.deck.mount() return, or null when desktop
		var deckBid = 0;                // bracket id the deck is currently rendering
		// Mobile ironman (king-of-the-hill) deck state. A DEDICATED card layout
		// (not TnMobile.deck): ironman has a single recordable fight per ring
		// (king vs head-of-queue), so swipe-through-bouts does not apply — ring
		// switching is the navigation. _imDeckRing persists the selected ring
		// across re-renders so a win doesn't bounce the organizer back to ring 1.
		var _imDeckMounted = false;     // true while a mobile ironman deck owns nuHost
		var _imDeckRing = 1;            // currently selected ring for the deck
		function destroyIronmanDeck(){ _imDeckMounted = false; }
		function isMobileView(){ return !!(window.TnMobile && TnMobile.isMobile()); }
		function destroyDeck(){
			if (deckHandle){ try { deckHandle.destroy(); } catch(e){} deckHandle = null; }
		}
		// Side label for compact on-deck cards: R{n}W / R{n}L for double-elim,
		// GF for grand-final, 3rd for the third-place tiebreaker, plain R{n} else.
		// (_side is already lowercased by the sequencer.)
		function tnDeckSideLabel(m){
			var side = (m && m._side) || '';
			var r = (m && m._round) || 0;
			if (side === 'grand-final') return 'GF';
			if (side === 'tiebreaker-3rd') return '3rd';
			if (side === 'winners') return 'R' + r + 'W';
			if (side === 'losers')  return 'R' + r + 'L';
			return 'R' + r;
		}

		function bestOfForBracket(bd){
			var n = parseInt((bd && bd.Bracket && bd.Bracket.BestOf) || 1, 10);
			return ([1,3,5,7,9].indexOf(n) !== -1) ? n : 1;
		}
		function emptyBouts(n){
			var a = []; for (var i = 0; i < n; i++) a.push(null); return a;
		}
		try {
			var saved = localStorage.getItem(MODE_KEY);
			if (saved === 'quick' || saved === 'track') mode = saved;
		} catch(e){}

		function parseRoundOrder(str){
			var s = String(str || '');
			var r = parseInt((s.match(/Round\s*(\d+)/i) || [])[1] || 0, 10);
			var o = parseInt((s.match(/Match\s*(\d+)/i) || [])[1] || 0, 10);
			return { round: r, order: o };
		}

		// tnBoutStage — assigns each match a global integer "stage" reflecting the
		// REAL fought order of a double-elimination tournament, where the Losers
		// Bracket opens after Winners R1 and both brackets advance in lockstep:
		//   WB1=1, WB2=3, WB3=6, WB4=9 ...          (winners / '' / single-elim)
		//   LB1=2, LB2=4, LB3=5, LB4=7, LB5=8, LB6=10 ...   (losers)
		//   grand-final after everything (game then reset), then 3rd-place TB last.
		// Verified against generate_double_elim() round numbering and the WR-loser
		// routing in PostMatchResult (WR1 losers -> LB1; WR round r>=2 -> LB (r-1)*2).
		function tnBoutStage(side, round){
			side = String(side || '').toLowerCase();
			var r = parseInt(round, 10) || 0;
			if (side === 'grand-final')   return 1e6 + r;
			if (side === 'tiebreaker-3rd') return 2e6 + r;
			if (side === 'losers'){
				if (r === 1) return 2;
				return 3 * Math.floor(r / 2) + 1 + (r % 2 === 1 ? 1 : 0);
			}
			if (side === 'winners' || side === ''){
				return (r === 1) ? 1 : 3 * (r - 1);
			}
			return 3e6 + r; // defensive: round-robin 'tiebreaker' / unknown sides
		}

		// tnSequencedBouts — SINGLE SOURCE OF TRUTH for bout ordering. Returns ALL of
		// the bracket's matches (mapped with the same _round/_order/_side fields the
		// deck/next-up consume) sorted by (stage ASC, then match order ASC).
		function tnSequencedBouts(bd){
			var matches = (bd && bd.Matches) || [];
			var ms = matches.map(function(m){
				var ro = parseRoundOrder(m.Match);
				var round = m.Round || ro.round;
				var side = (m.BracketSide || '').toLowerCase();
				return Object.assign({}, m, {
					_round: parseInt(round, 10) || 0,
					// The API returns m.Match as a plain integer; the regex is only a fallback.
					_order: parseInt(m.Match, 10) || parseInt(ro.order, 10) || 0,
					_side:  side,
					_stage: tnBoutStage(side, round)
				});
			});
			ms.sort(function(a, b){
				if (a._stage !== b._stage) return a._stage - b._stage;
				return a._order - b._order;
			});
			return ms;
		}

		// Ready matches (no result, both participants present) in true fought order.
		function nextUnresolved(bd){
			return tnSequencedBouts(bd).filter(function(m){
				if (m.Result) return false;
				if (!m.Participant1Id || !m.Participant2Id) return false;
				return true;
			});
		}

		function participantLookup(bd){
			var map = {};
			(bd && bd.Participants || []).forEach(function(p){ map[p.ParticipantId] = p; });
			return map;
		}

		// ========================================================================
		// Track R2 · Bout List jump sheet
		// A scrollable list of EVERY bout from tnSequencedBouts(bd) (true fought
		// order — the SAME source of truth the deck uses), each row tagged with a
		// status derived from nextUnresolved(bd): index 0 = current (deck lead),
		// 1..2 = on-deck, rest of the ready set = upcoming, matches with an empty
		// participant slot = not-ready, matches with a Result = done. Tapping a
		// ready row jumps the deck to lead with that bout (tnDeckFocus) and closes
		// the sheet; done rows re-open the Record Result modal; not-ready rows are
		// inert. Rebuilt from the live bd on every open so it can never go stale.
		// ========================================================================

		// tnDeckFocus(matchId) — Bout-List contract: make `matchId` the deck's
		// current lead (NOW). The tapped bout may sit beyond the current 3-item
		// window, so we re-window nextUnresolved() to lead with it (pin tapped as
		// NOW, then the next canonical ready bouts) and re-render the deck, then
		// setLead to guarantee it is the full lead. Exposed on window so the sheet
		// (built outside this IIFE's render closures) can reach the live handle.
		window.tnDeckFocus = function(matchId){
			if (!isMobileView()) return;
			var bid = deckBid;
			if (!bid || !TnConfig.bracketData || !TnConfig.bracketData[bid]) return;
			var bd = TnConfig.bracketData[bid];
			var ready = nextUnresolved(bd);
			var lead = null, rest = [];
			ready.forEach(function(m){
				if (String(m.MatchId) === String(matchId)) lead = m;
				else rest.push(m);
			});
			if (!lead) return;                       // not a ready bout -> ignore
			var pMap = participantLookup(bd);
			renderNextUpDeck(bid, bd, pMap, [lead].concat(rest).slice(0, 3));
			if (deckHandle) deckHandle.setLead(matchId);
		};

		// Winner/loser names + bout score for a resolved match (done rows).
		function tnBoutListResult(m, pMap){
			var p1 = pMap[m.Participant1Id] || {};
			var p2 = pMap[m.Participant2Id] || {};
			var p1Name = m.Participant1Alias || p1.Alias || p1.Persona || '\u2014';
			var p2Name = m.Participant2Alias || p2.Alias || p2.Persona || '\u2014';
			var r = m.Result;
			var winName, loseName, verb = 'def.';
			var side = tnWinnerSide(r);
			if (side === 2) { winName = p2Name; loseName = p1Name; }
			else            { winName = p1Name; loseName = p2Name; }
			if (/forfeit/.test(r || ''))           verb = 'def. (FF)';
			else if (/disqualified/.test(r || '')) verb = 'def. (DQ)';
			else if (r === 'tie')                  verb = 'tied';
			var score = '';
			try {
				var ba = (m.Bouts && m.Bouts !== '[]') ? JSON.parse(m.Bouts) : [];
				if (ba.length){
					var w1 = ba.filter(function(b){ return b === '1'; }).length;
					var w2 = ba.filter(function(b){ return b === '2'; }).length;
					var winW = (side === 2) ? w2 : w1;
					var loseW = (side === 2) ? w1 : w2;
					score = winW + '-' + loseW;
				}
			} catch(e){}
			return { winName: winName, loseName: loseName, verb: verb, score: score };
		}

		// Team member-count chip with roster tooltip (data-tip, never title=).
		function tnTeamChip(p){
			if (!p || !p.IsTeam || !p.Members || !p.Members.length) return '';
			var names = p.Members.map(function(m){ return tnEsc(m.Persona || ''); }).filter(Boolean).join(', ');
			return '<span class="tn-team-chip" data-tip="Members: ' + names + '">' + p.Members.length + '</span>';
		}

		// Seed pill + name (reusing the deck's seed lookup pattern).
		function tnPidShieldHTML(n){
				n = parseInt(n, 10) || 0;
				if (n <= 0) return '';
				return '<span class="tn-pid" data-tip="Player #' + n + ' — same number across every bracket">' + n + '</span>';
			}

			function tnBoutListName(name, seed, members){
			var pidn = (members && members.ParticipantNumber) ? parseInt(members.ParticipantNumber, 10) : 0;
				var shield = pidn ? tnPidShieldHTML(pidn) : '';
				var s = seed ? '<span class="tn-boutlist-seed" data-tip="Seed"><i class="fas fa-seedling tn-seedling"></i>' + parseInt(seed, 10) + '</span>' : '';
			var chip = (members && members.IsTeam) ? tnTeamChip(members) : '';
			return shield + s + tnEsc(name) + chip;
		}

		// Build (fresh, every open) the Bout List sheet overlay element from the
		// current bracket's bd. Returns the overlay; caller opens via TnMobile.sheet.
		function buildBoutListSheet(bid, bd){
			var all = tnSequencedBouts(bd);
			// `ready` is the unresolved subset of `all` — identical to
			// nextUnresolved(bd) (same filter, same order) but reuses the
			// already-computed `all` instead of re-sequencing.
			var readyIds = all.filter(function(m){
				if (m.Result) return false;
				if (!m.Participant1Id || !m.Participant2Id) return false;
				return true;
			}).map(function(m){ return String(m.MatchId); });
			var pMap = participantLookup(bd);

			var rows = all.map(function(m){
				var side = tnEsc(tnDeckSideLabel(m));
				var p1 = pMap[m.Participant1Id] || {};
				var p2 = pMap[m.Participant2Id] || {};
				var hasP1 = !!m.Participant1Id, hasP2 = !!m.Participant2Id;
				var status, glyph, statusPill = '', trailing = '', tappable = false;

				if (m.Result){
					status = 'done';
					glyph = '\u2713';                                   // check
					var res = tnBoutListResult(m, pMap);
					var namesHTML = '<span>' + tnEsc(res.winName) + '</span>' +
						'<span class="tn-boutlist-vs">' + res.verb + '</span>' +
						'<span>' + tnEsc(res.loseName) + '</span>';
					if (res.score) trailing = '<span class="tn-boutlist-scorepill" data-tip="Bout score (winner-loser)">' + tnEsc(res.score) + '</span>';
					tappable = true;                                    // re-open result
					return rowHTML(m, 'done', glyph, side, namesHTML, trailing, tappable);
				}

				if (!hasP1 || !hasP2){
					status = 'notready';
					glyph = '\u25cb';                                   // hollow circle
					var n1 = hasP1 ? tnBoutListName(m.Participant1Alias || p1.Alias || p1.Persona || '\u2014', p1.Seed, p1) : '<span class="tn-boutlist-tbd">TBD</span>';
					var n2 = hasP2 ? tnBoutListName(m.Participant2Alias || p2.Alias || p2.Persona || '\u2014', p2.Seed, p2) : '<span class="tn-boutlist-tbd">TBD</span>';
					var namesNR = '<span>' + n1 + '</span><span class="tn-boutlist-vs">vs</span><span>' + n2 + '</span>';
					return rowHTML(m, 'notready', glyph, side, namesNR, '', false);
				}

				// Ready (both participants, no result) — current / on-deck / upcoming.
				var idx = readyIds.indexOf(String(m.MatchId));
				if (idx === 0){ status = 'current'; glyph = '\u25b6'; statusPill = '<span class="tn-boutlist-statuspill">\u25cf Now</span>'; }
				else if (idx === 1 || idx === 2){ status = 'ondeck'; glyph = '\u25f7'; statusPill = '<span class="tn-boutlist-statuspill">On deck</span>'; }
				else { status = 'upcoming'; glyph = '\u25f7'; statusPill = '<span class="tn-boutlist-statuspill">Upcoming</span>'; }
				var rn1 = tnBoutListName(m.Participant1Alias || p1.Alias || p1.Persona || '\u2014', p1.Seed, p1);
				var rn2 = tnBoutListName(m.Participant2Alias || p2.Alias || p2.Persona || '\u2014', p2.Seed, p2);
				var namesR = '<span>' + rn1 + '</span><span class="tn-boutlist-vs">vs</span><span>' + rn2 + '</span>';
				return rowHTML(m, status, glyph, side, namesR, statusPill, true);
			}).join('');

			if (!rows) rows = '<div class="tn-boutlist-empty">No bouts in this bracket yet.</div>';

			var overlay = document.createElement('div');
			overlay.className = 'tn-overlay tn-sheet';
			overlay.innerHTML =
				'<div class="tn-modal-box tn-boutlist-box">' +
					'<div class="tn-modal-header">' +
						'<span class="tn-modal-title">Bout List <span class="tn-boutlist-count">' + all.length + ' bout' + (all.length === 1 ? '' : 's') + '</span></span>' +
						'<button type="button" class="tn-modal-close" data-tn-boutlist-close="1">&times;</button>' +
					'</div>' +
					'<div class="tn-modal-body">' +
						'<div class="tn-boutlist-list">' + rows + '</div>' +
					'</div>' +
				'</div>';

			// Close button.
			var closeBtn = overlay.querySelector('[data-tn-boutlist-close]');
			if (closeBtn) closeBtn.addEventListener('click', function(){ TnMobile.sheet.close(overlay); });

			// Row taps. Ready rows -> tnDeckFocus + close; done rows -> re-open
			// Record Result modal (after close so the sheet's teardown runs).
			overlay.querySelectorAll('.tn-boutlist-row[data-mid]').forEach(function(row){
				if (row.classList.contains('tn-boutlist-row--notready')) return;
				row.addEventListener('click', function(){
					var mid = row.getAttribute('data-mid');
					var st  = row.getAttribute('data-status');
					if (st === 'done'){
						TnMobile.sheet.close(overlay);
						var mo = (bd.Matches || []).find(function(mm){ return String(mm.MatchId) === String(mid); });
						if (mo && typeof window.tnOpenRecordResult === 'function'){
							if (mo.BracketId == null) mo.BracketId = bid; // ensure modal can resolve best-of/method
							window.tnOpenRecordResult(mo, pMap[mo.Participant1Id], pMap[mo.Participant2Id]);
						}
						return;
					}
					if (typeof window.tnDeckFocus === 'function') window.tnDeckFocus(mid);
					TnMobile.sheet.close(overlay);
				});
			});

			return overlay;
		}

		// Single Bout List row builder. status drives the modifier class + glyph
		// styling; tappable rows get role/tabindex for keyboard access.
		function rowHTML(m, status, glyph, side, namesHTML, trailing, tappable){
			var trailWrap = trailing ? '<span class="tn-boutlist-trail">' + trailing + '</span>' : '';
			return '<button type="button" class="tn-boutlist-row tn-boutlist-row--' + status + '"' +
				' data-mid="' + tnEsc(String(m.MatchId)) + '" data-status="' + status + '"' +
				(tappable ? '' : ' tabindex="-1" disabled') + '>' +
				'<span class="tn-boutlist-glyph">' + glyph + '</span>' +
				'<span class="tn-boutlist-side">' + side + '</span>' +
				'<span class="tn-boutlist-bout"><span class="tn-boutlist-names">' + namesHTML + '</span></span>' +
				trailWrap +
			'</button>';
		}

		// Open the Bout List for the current bracket. Rebuilds from live bd. On mobile
		// the bracket is the deck's (deckBid); on desktop (#56) the deck never mounts,
		// so fall back to the on-screen bracket from the viz selector. Reuses the SAME
		// buildBoutListSheet + tnSequencedBouts + status logic in both layouts. On
		// desktop it presents as a centered panel (TnMobile.sheet.open centers when not
		// mobile) and we add the backdrop/Esc dismissal that sheet.open only wires on mobile.
		function openBoutListSheet(){
			var bid = deckBid;
			if (!bid){ var bsel = $('tn-bv-bracket-select'); bid = bsel ? parseInt(bsel.value, 10) : 0; }
			if (!bid || !TnConfig.bracketData || !TnConfig.bracketData[bid]) return;
			var bd = TnConfig.bracketData[bid];
			if (!window.TnMobile || !TnMobile.sheet) return;
			var overlay = buildBoutListSheet(bid, bd);
			document.body.appendChild(overlay);
			void overlay.offsetWidth;                  // reflow for slide-up
			var isDesk = !isMobileView();
			function deskBackdrop(ev){ if (ev.target === overlay) TnMobile.sheet.close(overlay); }
			function deskKey(ev){ if (ev.key === 'Escape'){ ev.preventDefault(); TnMobile.sheet.close(overlay); } }
			TnMobile.sheet.open(overlay, { onDismiss: function(){
				if (isDesk){ overlay.removeEventListener('click', deskBackdrop); document.removeEventListener('keydown', deskKey); }
				if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
			}});
			if (isDesk){
				overlay.addEventListener('click', deskBackdrop);
				document.addEventListener('keydown', deskKey);
			}
			// Land on the current bout, not the top of a long list. The rect delta
			// is invariant under the sheet's slide-up transform; double rAF lets
			// layout settle first.
			requestAnimationFrame(function(){ requestAnimationFrame(function(){
				var body = overlay.querySelector('.tn-modal-body');
				var cur  = overlay.querySelector('.tn-boutlist-row--current');
				if (!body || !cur) return;
				var bodyRect = body.getBoundingClientRect(), curRect = cur.getBoundingClientRect();
				body.scrollTop += (curRect.top - bodyRect.top) - (body.clientHeight / 2) + (curRect.height / 2);
			}); });
		}

		function pipRowHTML(side, matchId){
			var bouts = trackState[matchId] || emptyBouts(activeBestOf);
			var row = '<div class="tn-nu-track-pips" data-side="' + side + '">';
			for (var i = 0; i < activeBestOf; i++){
				var cls = 'tn-bout-pip';
				if (bouts[i] === side) cls += ' tn-pip-win';
				else if (bouts[i] != null) cls += ' tn-pip-loss';
				row += '<button type="button" class="' + cls + '" data-side="' + side + '" data-idx="' + i + '"></button>';
			}
			row += '</div>';
			return row;
		}

		function headLine(m, posLabel){
			var sideLabels = { winners:'Winners', losers:'Losers', 'grand-final':'Grand Final', 'tiebreaker-3rd':'3rd Place', tiebreaker:'Tiebreaker' };
			var side = (m._side && m._side !== 'winners') ? ' &middot; ' + tnEsc(sideLabels[m._side] || m._side) : '';
			return '<span class="tn-nu-pos-label ' + (posLabel === 'NOW' ? 'tn-nu-now' : 'tn-nu-deck') + '">' + posLabel + '</span>' +
				'<span class="tn-nu-match-num">Round ' + m._round + ' &middot; Match ' + m._order + side + '</span>';
		}

		function quickCardHTML(m, pMap, posLabel){
			var p1 = pMap[m.Participant1Id] || {};
			var p2 = pMap[m.Participant2Id] || {};
			var p1Name = tnEsc(m.Participant1Alias || p1.Alias || p1.Persona || '—');
			var p2Name = tnEsc(m.Participant2Alias || p2.Alias || p2.Persona || '—');
			var p1Seed = p1.Seed ? '<span class="tn-nu-p-seed">' + parseInt(p1.Seed, 10) + '</span>' : '';
			var p2Seed = p2.Seed ? '<span class="tn-nu-p-seed">' + parseInt(p2.Seed, 10) + '</span>' : '';
			var p1Chip = tnTeamChip(p1);
			var p2Chip = tnTeamChip(p2);
			return (
				'<div class="tn-nu-card" data-mid="' + parseInt(m.MatchId, 10) + '">' +
					headLine(m, posLabel) +
					'<div style="min-width:0;flex:1">' +
						'<div class="tn-nu-players"><span class="tn-nu-p">' + p1Seed + p1Name + p1Chip + '</span><span class="tn-nu-vs">vs</span><span class="tn-nu-p">' + p2Seed + p2Name + p2Chip + '</span></div>' +
					'</div>' +
					(TnConfig.canRecordResult
						? '<div class="tn-nu-actions">' +
							'<button class="tn-nu-btn tn-nu-btn-p1" data-mid="' + parseInt(m.MatchId, 10) + '" data-r="1-wins" data-tip="' + p1Name + ' wins">' + p1Name + ' wins</button>' +
							'<button class="tn-nu-btn tn-nu-btn-p2" data-mid="' + parseInt(m.MatchId, 10) + '" data-r="2-wins" data-tip="' + p2Name + ' wins">' + p2Name + ' wins</button>' +
							((window.tnMethodAllowsTie && window.tnMethodAllowsTie(activeMethod))
								? '<button class="tn-nu-btn tn-nu-btn-tie" data-mid="' + parseInt(m.MatchId, 10) + '" data-r="tie">Tie</button>'
								: '') +
							'<button class="tn-nu-btn tn-nu-btn-more" data-mid="' + parseInt(m.MatchId, 10) + '" data-more="1" data-tip="Bouts / forfeit / DQ">⋯</button>' +
						'</div>'
						: '') +
				'</div>'
			);
		}

		function trackCardHTML(m, pMap, posLabel){
			var p1 = pMap[m.Participant1Id] || {};
			var p2 = pMap[m.Participant2Id] || {};
			var p1Name = tnEsc(m.Participant1Alias || p1.Alias || p1.Persona || '—');
			var p2Name = tnEsc(m.Participant2Alias || p2.Alias || p2.Persona || '—');
			var p1Seed = p1.Seed ? '<span class="tn-nu-p-seed">' + parseInt(p1.Seed, 10) + '</span>' : '';
			var p2Seed = p2.Seed ? '<span class="tn-nu-p-seed">' + parseInt(p2.Seed, 10) + '</span>' : '';
			var p1Chip = tnTeamChip(p1);
			var p2Chip = tnTeamChip(p2);
			return (
				'<div class="tn-nu-card tn-nu-card-track" data-mid="' + parseInt(m.MatchId, 10) + '" data-p1-name="' + p1Name + '" data-p2-name="' + p2Name + '">' +
					headLine(m, posLabel) +
					'<div class="tn-nu-mini-name tn-nu-mini-name-1">' + p1Seed + '<span>' + p1Name + '</span>' + p1Chip + '</div>' +
					pipRowHTML('1', m.MatchId) +
					'<span class="tn-nu-mini-vs">vs</span>' +
					pipRowHTML('2', m.MatchId) +
					'<div class="tn-nu-mini-name tn-nu-mini-name-2">' + p2Seed + '<span>' + p2Name + '</span>' + p2Chip + '</div>' +
					(TnConfig.canRecordResult
						? '<button class="tn-nu-btn tn-nu-btn-end" data-mid="' + parseInt(m.MatchId, 10) + '" data-end="1">End</button>' +
						  '<button class="tn-nu-btn tn-nu-btn-more" data-mid="' + parseInt(m.MatchId, 10) + '" data-more="1" data-tip="Bouts / forfeit / DQ · tap a pip to record a bout">⋯</button>'
						: '') +
				'</div>'
			);
		}

		// Compact on-deck card for the mobile deck: a dense one-line row of
		// fighter names + seeds + a side label (R2W / R1L / GF / 3rd). Tapping it
		// is wired by the deck primitive (data-tn-deck-id) to promote-to-lead.
		function deckCompactHTML(m, pMap){
			var p1 = pMap[m.Participant1Id] || {};
			var p2 = pMap[m.Participant2Id] || {};
			var p1Name = tnEsc(m.Participant1Alias || p1.Alias || p1.Persona || '\u2014');
			var p2Name = tnEsc(m.Participant2Alias || p2.Alias || p2.Persona || '\u2014');
			var p1Seed = p1.Seed ? '<span class="tn-nu-p-seed">' + parseInt(p1.Seed, 10) + '</span>' : '';
			var p2Seed = p2.Seed ? '<span class="tn-nu-p-seed">' + parseInt(p2.Seed, 10) + '</span>' : '';
			var p1Chip = tnTeamChip(p1);
			var p2Chip = tnTeamChip(p2);
			return (
				'<div class="tn-deck-compact-row" data-mid="' + parseInt(m.MatchId, 10) + '">' +
					'<span class="tn-deck-compact-side">' + tnEsc(tnDeckSideLabel(m)) + '</span>' +
					'<span class="tn-deck-compact-vs"><span class="tn-deck-compact-p">' + p1Seed + p1Name + p1Chip + '</span>' +
					'<span class="tn-deck-compact-x">vs</span>' +
					'<span class="tn-deck-compact-p">' + p2Seed + p2Name + p2Chip + '</span></span>' +
				'</div>'
			);
		}

		function repaintCardPips(matchId){
			if (!nuHost) return;
			var card = nuHost.querySelector('.tn-nu-card-track[data-mid="' + matchId + '"]');
			if (!card) return;
			var bouts = trackState[matchId] || emptyBouts(activeBestOf);
			for (var i = 0; i < activeBestOf; i++){
				var pip1 = card.querySelector('.tn-nu-track-pips[data-side="1"] [data-idx="' + i + '"]');
				var pip2 = card.querySelector('.tn-nu-track-pips[data-side="2"] [data-idx="' + i + '"]');
				if (!pip1 || !pip2) continue;
				pip1.className = 'tn-bout-pip';
				pip2.className = 'tn-bout-pip';
				if (bouts[i] === '1'){ pip1.classList.add('tn-pip-win'); pip2.classList.add('tn-pip-loss'); }
				else if (bouts[i] === '2'){ pip2.classList.add('tn-pip-win'); pip1.classList.add('tn-pip-loss'); }
			}
			updateEndButton(matchId);
		}

		function updateEndButton(matchId){
			if (!nuHost) return;
			var card = nuHost.querySelector('.tn-nu-card-track[data-mid="' + matchId + '"]');
			if (!card) return;
			var btn = card.querySelector('.tn-nu-btn-end');
			if (!btn) return;
			var bouts = trackState[matchId] || [];
			var p1 = bouts.filter(function(b){ return b === '1'; }).length;
			var p2 = bouts.filter(function(b){ return b === '2'; }).length;
			var total = p1 + p2;
			if (total === 0){
				btn.classList.remove('tn-nu-btn-end-show', 'tn-nu-btn-end-tie');
				btn.textContent = 'End';
				return;
			}
			var p1Name = card.getAttribute('data-p1-name') || 'P1';
			var p2Name = card.getAttribute('data-p2-name') || 'P2';
			btn.classList.add('tn-nu-btn-end-show');
			if (p1 > p2){
				btn.classList.remove('tn-nu-btn-end-tie');
				btn.textContent = 'End · ' + p1Name + ' wins';
			} else if (p2 > p1){
				btn.classList.remove('tn-nu-btn-end-tie');
				btn.textContent = 'End · ' + p2Name + ' wins';
			} else if (window.tnMethodAllowsTie && window.tnMethodAllowsTie(activeMethod)) {
				btn.classList.add('tn-nu-btn-end-tie');
				btn.textContent = 'End · Tie';
			} else {
				// Elimination: a tie can't end the match — hide End until a side leads (#13).
				btn.classList.remove('tn-nu-btn-end-show', 'tn-nu-btn-end-tie');
				btn.textContent = 'End';
			}
		}

		function handleEndClick(ev){
			var btn = ev.target.closest('.tn-nu-btn-end');
			if (!btn) return;
			ev.preventDefault();
			ev.stopPropagation();
			var matchId = btn.getAttribute('data-mid');
			if (!matchId) return;
			var bouts = trackState[matchId] || [];
			var p1 = bouts.filter(function(b){ return b === '1'; }).length;
			var p2 = bouts.filter(function(b){ return b === '2'; }).length;
			if (p1 + p2 === 0) return;
			// #13: never commit a tie in an elimination format — it strands the match.
			if (p1 === p2 && !(window.tnMethodAllowsTie && window.tnMethodAllowsTie(activeMethod))) return;
			cancelPendingCommit(matchId);
			var result = p1 > p2 ? '1-wins' : (p2 > p1 ? '2-wins' : 'tie');
			submitWithBouts(matchId, result);
		}

		// #51: a short toast-based undo window before an auto-detected result actually
		// commits, mirroring the Record Result modal's brief countdown. Reaching majority
		// on a pip click no longer commits instantly with no way back.
		var _pendingCommit = Object.create(null); // matchId -> timeout id
		function cancelPendingCommit(matchId){
			if (_pendingCommit[matchId]){ clearTimeout(_pendingCommit[matchId]); delete _pendingCommit[matchId]; }
		}
		function commitWithUndo(matchId, result){
			cancelPendingCommit(matchId);
			var card = nuHost && nuHost.querySelector('.tn-nu-card-track[data-mid="' + matchId + '"]');
			var winnerLabel = 'Tie';
			if (result === '1-wins') winnerLabel = (card && card.getAttribute('data-p1-name')) || 'P1';
			else if (result === '2-wins') winnerLabel = (card && card.getAttribute('data-p2-name')) || 'P2';
			_pendingCommit[matchId] = setTimeout(function(){
				delete _pendingCommit[matchId];
				submitWithBouts(matchId, result, true); // #35: pre-commit undo already shown
			}, 2500);
			if (window.tnToast) window.tnToast('Recorded ' + winnerLabel + ' — tap to undo', 2500, { onClick: function(){ cancelPendingCommit(matchId); } });
		}

		function evaluateMajority(matchId){
			var bouts = trackState[matchId] || [];
			var p1 = bouts.filter(function(b){ return b === '1'; }).length;
			var p2 = bouts.filter(function(b){ return b === '2'; }).length;
			var total = p1 + p2;
			var remaining = activeBestOf - total;
			var allowTie = !!(window.tnMethodAllowsTie && window.tnMethodAllowsTie(activeMethod));
			var decided = null;
			if (p1 > p2 && (p1 - p2) > remaining) decided = '1-wins';
			else if (p2 > p1 && (p2 - p1) > remaining) decided = '2-wins';
			// #13: only auto-resolve an even split as a tie where a tie is legal; in
			// elimination the organizer must add a decider bout instead.
			else if (total >= activeBestOf) decided = (p1 > p2) ? '1-wins' : (p2 > p1 ? '2-wins' : (allowTie ? 'tie' : null));
			if (decided) commitWithUndo(matchId, decided);
		}

		function submitWithBouts(matchId, result, skipUndoToast){
			var bouts = trackState[matchId] || [];
			// Preserve a snapshot; do NOT delete trackState until the server
			// confirms — a failed save would otherwise lose the bout pips.
			var savedBouts = bouts.slice();
			var compact = bouts.filter(function(b){ return b !== null; });
			var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
			if (window.tnRegisterAction) window.tnRegisterAction(actionId);
			if (window.tnCollabNudge) window.tnCollabNudge();
			var fd = new FormData();
			fd.append('Result', result);
			fd.append('Score', '');
			fd.append('Bouts', JSON.stringify(compact));
			fd.append('ActionId', actionId);
			// #32: surface WHY the save failed. Pass the server's error (or the
			// desktop concurrent-edit copy) so the organizer isn't left with only a
			// red flash and no reason.
			function restoreBouts(reason){
				trackState[matchId] = savedBouts;
				repaintCardPips(matchId);
				if (nuHost){
					var card = nuHost.querySelector('.tn-nu-card-track[data-mid="' + matchId + '"]');
					if (card){ card.classList.add('tn-nu-card-error'); setTimeout(function(){ card.classList.remove('tn-nu-card-error'); }, 1200); }
				}
				if (window.tnToast) window.tnToast(reason || 'Not saved — it may have just been recorded by someone else.');
			}
			fetch(TnConfig.uir + 'TournamentAjax/match/' + matchId + '/' + TnConfig.tournamentId, { method:'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(d){
					if (d && d.status === 0){
						if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
						delete trackState[matchId];
						refreshBracket();
						// The toast below runs the shared post-result hook; when it is
						// skipped (#51 auto-commit), run the hook directly.
						if (skipUndoToast && window.tnAfterResultRecorded){
							var _hs = $('tn-bv-bracket-select');
							window.tnAfterResultRecorded(deckBid || (_hs ? parseInt(_hs.value, 10) : 0));
						}
						// #35: offer a tap-to-undo (unless the auto-commit path already
						// showed its own pre-commit undo window, #51).
						if (!skipUndoToast && window.tnResultRecordedToast){
							var _bid = deckBid || (function(){ var s = $('tn-bv-bracket-select'); return s ? parseInt(s.value, 10) : 0; })();
							window.tnResultRecordedToast(matchId, _bid);
						}
					} else {
						restoreBouts(d && d.error ? d.error : null);
					}
				})
				.catch(function(){ restoreBouts('Network error — not saved. Check your connection and try again.'); });
		}

		function refreshBracket(){
			var sel = $('tn-bv-bracket-select');
			var bid = sel ? parseInt(sel.value, 10) : 0;
			if (!bid || !TnConfig.bracketData[bid]) return;
			var tid = TnConfig.tournamentId;
			var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0;
			Promise.all([
				fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r){ return r.json(); }),
				fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r){ return r.json(); })
			]).then(function(results){
				var md = results[0], bd = results[1];
				var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(bid, _rt); // a newer fetch of this bracket writes + paints
				if (_rtCur && md && md.status === 0) TnConfig.bracketData[bid].Matches = md.matches;
				if (_rtCur && bd && bd.status === 0 && bd.brackets && TnConfig.bracketData[bid]){
					var br = bd.brackets.find(function(b){ return parseInt(b.BracketId) === parseInt(bid); });
					if (br) TnConfig.bracketData[bid].Bracket = br;
				}
				if (!_rtCur){
					// superseded: the newer fetch paints
				} else if (typeof window.tnRenderBracketViz === 'function'){
					window.tnRenderBracketViz(bid);
				} else {
					window.tnRenderNextUp(bid);
				}
			}).catch(function(err){ console.warn('[tn] refresh failed', err); if (window.tnShowStaleWarning) tnShowStaleWarning(); });
		}

		function handlePipClick(ev){
			var pip = ev.target.closest('.tn-bout-pip');
			if (!pip) return;
			ev.preventDefault();
			ev.stopPropagation();
			var card = pip.closest('.tn-nu-card-track');
			if (!card) return;
			var matchId = card.getAttribute('data-mid');
			if (!matchId) return;
			var side = pip.getAttribute('data-side');
			var idx = parseInt(pip.getAttribute('data-idx'), 10);
			var bouts = trackState[matchId] || (trackState[matchId] = emptyBouts(activeBestOf));
			bouts[idx] = (bouts[idx] === side) ? null : side;
			repaintCardPips(matchId);
			evaluateMajority(matchId);
		}

		function bindQuickButtons(bd, pMap){
			nuHost.querySelectorAll('.tn-nu-btn').forEach(function(btn){
				btn.addEventListener('click', function(ev){
					ev.preventDefault();
					var mid = btn.getAttribute('data-mid');
					if (btn.getAttribute('data-more') === '1'){
						var matchObj = (bd.Matches || []).find(function(mm){ return String(mm.MatchId) === String(mid); });
						if (matchObj){
							var p1 = pMap[matchObj.Participant1Id];
							var p2 = pMap[matchObj.Participant2Id];
							if (typeof window.tnOpenRecordResult === 'function') window.tnOpenRecordResult(matchObj, p1, p2);
						}
						return;
					}
					var r = btn.getAttribute('data-r');
					if (typeof window.tnSubmitQuickResult === 'function'){
						window.tnSubmitQuickResult(mid, r, ev);
					}
				});
			});
		}

		function bindToggle(bracketId){
			nuHost.querySelectorAll('.tn-nu-toggle-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					var newMode = btn.getAttribute('data-mode');
					if (newMode === mode) return;
					mode = newMode;
					// Clear any partial pip state when leaving track mode so
					// we never half-commit a prior click path.
					trackState = Object.create(null);
					try { localStorage.setItem(MODE_KEY, mode); } catch(e){}
					window.tnRenderNextUp(bracketId);
				});
			});
		}

		// ====================================================================
		// MOBILE IRONMAN / KING-OF-THE-HILL DECK (Track R3)
		// Dedicated mobile card (NOT TnMobile.deck): a focused "king vs next
		// challenger" lead for the SELECTED ring, a ring selector, and a compact
		// on-deck challenger queue. ALL data + win logic is REUSED from the
		// desktop ironman view via window.tnIronmanHelpers (computeStats /
		// computeGlobalStats / computeQueue / getWinnerId) — no duplication. A
		// win posts to the SAME TournamentAjax .../ironmanwin endpoint and then
		// refreshes via window.tnRefreshAndRender (the desktop refetch+render
		// path), which re-invokes tnRenderNextUp and rebuilds this deck with the
		// advanced king/queue. Rendered into #tn-nextup, above the desktop rings
		// grid in #tn-bv-container (which stays as roster/standings + timer).
		// ====================================================================
		var _imAvatarColors = ['#e53e3e','#38a169','#3182ce','#d69e2e','#805ad5','#dd6b20','#00b5d8','#d53f8c','#2d3748','#319795'];

		// Read the ironman timer lock state from the SAME localStorage key the
		// desktop renderIronmanView uses. Unlocked => fights can be recorded.
		function imTimerUnlocked(bid, bd){
			var durMs = (parseInt((bd.Bracket && bd.Bracket.DurationMinutes) || 0, 10) || 0) * 60000;
			if (durMs === 0) return true; // no timer configured => always open
			var st = null;
			try { st = JSON.parse(localStorage.getItem('tn_im_timer_' + bid) || 'null'); } catch(e){}
			if (!st || !st.startedAt) return false;		  // not started => locked
			if (st.endedAt) return false;					 // ended => locked
			if (st.graceStartedAt){						   // in grace => still open briefly
				return (Date.now() - st.graceStartedAt) < 10000;
			}
			if (st.pausedAt) return true;					 // paused => recording still allowed
			return true;									  // running
		}

		function renderIronmanDeck(bid, bd){
			var H = window.tnIronmanHelpers;
			if (!H){ destroyIronmanDeck(); nuHost.innerHTML = ''; return; } // helpers not yet loaded
			var matches	  = (bd.Matches || []).slice().sort(function(a,b){ return (parseInt(a.Order)||0)-(parseInt(b.Order)||0); });
			var completed	= matches.filter(function(m){ return m.Result && m.Result !== ''; });
			var participants = bd.Participants || [];
			var pMap = {};
			participants.forEach(function(p){ pMap[p.ParticipantId] = p; });

			var ringCount = Math.max(1, Math.min(8, parseInt((bd.Bracket && bd.Bracket.Rings) || 1, 10) || 1));
			if (_imDeckRing < 1 || _imDeckRing > ringCount) _imDeckRing = 1;

			// Per-ring real kings (fight-history only) for cross-ring blocking — same
			// rule as desktop: a fighter who is the real king of another ring can't
			// be entered here.
			var seedSorted = participants.slice().sort(function(a,b){
				return (parseInt(a.ParticipantNumber)||parseInt(a.Seed)||0) - (parseInt(b.ParticipantNumber)||parseInt(b.Seed)||0);
			});
			var allKingRing = {};
			for (var ri = 1; ri <= ringCount; ri++){
				var rk = H.computeStats(completed, ri).currentKingId;
				if (rk) allKingRing[rk] = ri;
			}

			var globalStats = H.computeGlobalStats(completed);
			var unlocked	= imTimerUnlocked(bid, bd);
			var ringColor   = H.ringColors[_imDeckRing] || '#718096';

			_imDeckMounted = true;
			destroyDeck(); // ensure no elim deck handle lingers

			var wrap = document.createElement('div');
			wrap.className = 'tn-imd-wrap';

			// Header
			var header = document.createElement('div');
			header.className = 'tn-imd-header';
			header.innerHTML = '<span class="tn-imd-title">King of the Hill</span>'
				+ '<span class="tn-imd-sub">&mdash; tap the winner of each fight</span>';
			wrap.appendChild(header);

			// Ring selector (segmented). Only shown when >1 ring.
			if (ringCount > 1){
				var rings = document.createElement('div');
				rings.className = 'tn-imd-rings';
				for (var rn = 1; rn <= ringCount; rn++){
					(function(rNum){
						var btn = document.createElement('button');
						btn.type = 'button';
						btn.className = 'tn-imd-ring-btn' + (rNum === _imDeckRing ? ' tn-imd-ring-on' : '');
						var rc = H.ringColors[rNum] || '#718096';
						if (rNum === _imDeckRing){ btn.style.background = rc; btn.style.borderColor = rc; }
						btn.textContent = 'Ring ' + rNum;
						btn.onclick = function(){ _imDeckRing = rNum; renderIronmanDeck(bid, bd); };
						rings.appendChild(btn);
					})(rn);
				}
				wrap.appendChild(rings);
			}

			// --- Selected ring's king + challenger queue (REUSED helpers) ---
			var rStats	 = H.computeStats(completed, _imDeckRing);
			var kingId	 = rStats.currentKingId;
			var rCompleted = completed.filter(function(m){ return (parseInt(m.RingNumber)||1) === _imDeckRing; });
			var fightNum   = rCompleted.length + 1;

			// Display king default (Nth seed) when no fights yet — mirrors desktop.
			if (!kingId && seedSorted.length > 0){
				kingId = parseInt(seedSorted[(_imDeckRing - 1) % seedSorted.length].ParticipantId) || 0;
			}

			// Challenger queue for this ring: order from computeQueue, then drop
			// anyone who is the king of a DIFFERENT ring (can't fight here), and the
			// current king. Same exclusion intent as desktop's cross-ring blocking.
			var excludeIds = kingId ? [kingId] : [];
			var queue = H.computeQueue(completed, pMap, excludeIds).filter(function(pidv){
				return !(allKingRing[pidv] !== undefined && allKingRing[pidv] !== _imDeckRing);
			});
			var nextChallengerId = queue.length ? queue[0] : 0;

			// Lead card
			var lead = document.createElement('div');
			lead.className = 'tn-imd-lead';
			lead.style.borderColor = ringColor;

			var top = document.createElement('div');
			top.className = 'tn-imd-lead-top';
			top.innerHTML = '<span class="tn-imd-fight">' + (ringCount > 1 ? 'Ring ' + _imDeckRing + ' &middot; ' : '') + 'Fight #' + fightNum + '</span>';
			if (kingId && pMap[kingId]){
				var kName = pMap[kingId].Alias || pMap[kingId].Persona || '?';
				var kStreak = globalStats.streak[kingId] || 0;
				top.innerHTML += '<span class="tn-imd-king-badge">'
					+ '<i class="fas fa-crown tn-imd-king-crown"></i>'
					+ '<span class="tn-imd-king-label">King</span>'
					+ '<span class="tn-imd-king-name">' + tnEsc(kName) + '</span>'
					+ (kStreak > 1 ? '<span class="tn-imd-king-streak">' + kStreak + '</span>' : '')
					+ '</span>';
			}
			lead.appendChild(top);

			function fighterCardHTML(pid, isKing){
				var p = pMap[pid] || {};
				var name = p.Alias || p.Persona || '?';
				var seedNum = parseInt(p.ParticipantNumber) || parseInt(p.Seed) || '';
				var wins = globalStats.wins[pid] || 0;
				var color = _imAvatarColors[(seedSorted.findIndex(function(x){ return (parseInt(x.ParticipantId)||0) === pid; }) + _imDeckRing) % _imAvatarColors.length] || '#718096';
				return '<div class="tn-imd-fighter' + (isKing ? ' tn-imd-fighter-king' : '') + '">'
					+ '<div class="tn-imd-avatar" style="background:' + color + '">' + (seedNum || '?') + '</div>'
					+ '<div class="tn-imd-fname">' + tnEsc(name) + '</div>'
					+ '<div class="tn-imd-frole">' + (isKing ? 'On the Hill' : 'Challenger') + '</div>'
					+ '<div class="tn-imd-fwins"><i class="fas fa-trophy"></i> ' + wins + '</div>'
					+ '</div>';
			}

			if (kingId && nextChallengerId){
				var vs = document.createElement('div');
				vs.className = 'tn-imd-vs';
				vs.innerHTML = fighterCardHTML(kingId, true)
					+ '<div class="tn-imd-vs-sep">VS</div>'
					+ fighterCardHTML(nextChallengerId, false);
				lead.appendChild(vs);

				var status = document.createElement('div');
				status.className = 'tn-imd-status';
				lead.appendChild(status);

				if (TnConfig.canRecordResult && unlocked){
					var btns = document.createElement('div');
					btns.className = 'tn-imd-win-btns';
					var kingName = (pMap[kingId].Alias || pMap[kingId].Persona || 'King');
					var chalName = (pMap[nextChallengerId].Alias || pMap[nextChallengerId].Persona || 'Challenger');

					function makeWinBtn(winnerId, label, cls){
						var b = document.createElement('button');
						b.type = 'button';
						b.className = 'tn-imd-win-btn ' + cls;
						b.innerHTML = '<i class="fas fa-trophy"></i> ' + tnEsc(label) + ' won';
						b.onclick = function(){
							if (b.dataset.pending) return;
							b.dataset.pending = '1';
							btns.querySelectorAll('button').forEach(function(x){ x.disabled = true; });
							status.className = 'tn-imd-status';
							status.textContent = 'Recording…';
							// SAME endpoint + payload as the desktop ironman quick-entry.
							var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
							if (window.tnRegisterAction) window.tnRegisterAction(actionId);
							if (window.tnCollabNudge) window.tnCollabNudge();
							var fd = new FormData();
							fd.append('WinnerId',	 winnerId);
							fd.append('TournamentId', TnConfig.tournamentId);
							fd.append('RingNumber',   _imDeckRing);
							fd.append('ActionId',     actionId);
							var _ac = new AbortController();
							var _to = setTimeout(function(){ _ac.abort(); }, 9000);
							fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/ironmanwin', { method:'POST', body:fd, signal:_ac.signal })
								.then(function(r){ return r.json(); })
								.then(function(d){
									if (d.status !== 0){
										status.className = 'tn-imd-status err';
										status.textContent = d.error || 'Error';
										btns.querySelectorAll('button').forEach(function(x){ x.disabled = false; });
										delete b.dataset.pending;
										return;
									}
									if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
									// Refresh via the desktop refetch+render path; it re-invokes
									// tnRenderNextUp, which rebuilds this deck with the advanced
									// king/queue. _imDeckRing persists, so we stay on this ring.
									if (typeof window.tnRefreshAndRender === 'function') window.tnRefreshAndRender(bid);
									else if (typeof window.tnRenderBracketViz === 'function') window.tnRenderBracketViz(bid);
								})
								.catch(function(err){
									status.className = 'tn-imd-status err';
									status.textContent = (err && err.name === 'AbortError') ? 'Timed out — try again' : 'Network error';
									btns.querySelectorAll('button').forEach(function(x){ x.disabled = false; });
									delete b.dataset.pending;
								})
								.finally(function(){ clearTimeout(_to); });
						};
						return b;
					}
					btns.appendChild(makeWinBtn(kingId, kingName, 'tn-imd-win-btn-king'));
					btns.appendChild(makeWinBtn(nextChallengerId, chalName, 'tn-imd-win-btn-chal'));
					lead.appendChild(btns);
				} else if (!unlocked){
					var lk = document.createElement('div');
					lk.className = 'tn-imd-locked';
					lk.innerHTML = '<i class="fas fa-lock"></i> Start the timer to record fights';
					lead.appendChild(lk);
				}
			} else {
				var empty = document.createElement('div');
				empty.className = 'tn-imd-empty';
				empty.textContent = (participants.length < 1)
					? 'Add fighters to begin.'
					: (kingId ? 'No challenger available for this ring yet.' : 'No fighters seeded for this ring.');
				lead.appendChild(empty);
			}
			wrap.appendChild(lead);

			// On-deck challenger queue (next up to 3 after the current challenger).
			var onDeckIds = queue.slice(1, 4);
			if (onDeckIds.length){
				var odTitle = document.createElement('div');
				odTitle.className = 'tn-imd-ondeck-title';
				odTitle.textContent = 'On Deck';
				wrap.appendChild(odTitle);

				var od = document.createElement('div');
				od.className = 'tn-imd-ondeck';
				onDeckIds.forEach(function(pid, i){
					var p = pMap[pid] || {};
					var name = p.Alias || p.Persona || '?';
					var seedNum = parseInt(p.ParticipantNumber) || parseInt(p.Seed) || '';
					var chip = document.createElement('div');
					chip.className = 'tn-imd-chip';
					chip.innerHTML = '<span class="tn-imd-chip-pos">' + (i + 2) + '</span>'
						+ (seedNum ? '<span class="tn-imd-chip-seed">#' + seedNum + '</span>' : '')
						+ '<span class="tn-imd-chip-name">' + tnEsc(name) + '</span>';
					od.appendChild(chip);
				});
				wrap.appendChild(od);
			}

			nuHost.innerHTML = '';
			nuHost.appendChild(wrap);
		}

		window.tnRenderNextUp = function(bracketId){
			nuHost = nuHost || $('tn-nextup');
			if (!nuHost) return;
			// Result-entry tool: hidden entirely from spectators / non-result-recorders.
			if (!TnConfig.canRecordResult) { destroyDeck(); destroyIronmanDeck(); nuHost.innerHTML = ''; return; }
			var bid = parseInt(bracketId, 10);
			if (!bid || !TnConfig.bracketData || !TnConfig.bracketData[bid]){
				destroyDeck(); destroyIronmanDeck(); nuHost.innerHTML = '';
				return;
			}
			var bd = TnConfig.bracketData[bid];
			var method = (bd.Bracket && bd.Bracket.Method) || '';
			if (method === 'ironman'){
				// Mobile: focused king-vs-challenger deck (Track R3). Desktop: the
				// full rings grid + timer lives in #tn-bv-container (renderIronmanView),
				// so #tn-nextup stays empty there — no double render.
				if (isMobileView()){
					var imStatus = (bd.Bracket && bd.Bracket.Status) || '';
					if (imStatus === 'complete' || imStatus === 'finalized'){ destroyIronmanDeck(); nuHost.innerHTML = ''; return; }
					renderIronmanDeck(bid, bd);
					return;
				}
				destroyDeck(); destroyIronmanDeck(); nuHost.innerHTML = ''; return;
			}
			// Points brackets have no matches — nothing is ever "next up".
			if (method === 'points'){ destroyDeck(); destroyIronmanDeck(); nuHost.innerHTML = ''; return; }
			var status = (bd.Bracket && bd.Bracket.Status) || '';
			if (status === 'setup' || status === 'complete' || status === 'finalized'){
				destroyDeck(); destroyIronmanDeck(); nuHost.innerHTML = '';
				return;
			}
			activeBestOf = bestOfForBracket(bd);
			activeMethod = method;
			var unresolved = nextUnresolved(bd);
			// Purge track state for matches that no longer exist in the queue.
			Object.keys(trackState).forEach(function(mid){
				if (!unresolved.some(function(m){ return String(m.MatchId) === String(mid); })){
					delete trackState[mid];
				}
			});

			var toggleHTML =
				'<div class="tn-nu-toggle" role="tablist" aria-label="Next Up mode">' +
					'<button class="tn-nu-toggle-btn' + (mode === 'quick' ? ' tn-nu-toggle-on' : '') + '" data-mode="quick" role="tab" aria-selected="' + (mode === 'quick') + '">Quick Win</button>' +
					'<button class="tn-nu-toggle-btn' + (mode === 'track' ? ' tn-nu-toggle-on' : '') + '" data-mode="track" role="tab" aria-selected="' + (mode === 'track') + '">Track Fights</button>' +
				'</div>';

			if (!unresolved.length){
				destroyDeck();
				nuHost.innerHTML =
					'<div class="tn-nu-wrap"><div class="tn-nu-header">' +
					'<span class="tn-nu-title">Next up</span>' +
					'<span class="tn-nu-sub">&mdash; all ready matches are recorded. Waiting on later rounds.</span>' +
					toggleHTML +
					'</div></div>';
				bindToggle(bid);
				return;
			}

			var pMap = participantLookup(bd);
			var cardFn = (mode === 'track') ? trackCardHTML : quickCardHTML;

			// ---- Mobile: swipeable Match Deck -------------------------------
			// current fight (full) + next 2 on-deck (compact), windowed HERE to 3.
			destroyIronmanDeck();
			if (isMobileView() && window.TnMobile && TnMobile.deck){
				renderNextUpDeck(bid, bd, pMap, unresolved.slice(0, 3));
				return;
			}
			// Leaving mobile (or never mobile): make sure no stale deck lingers.
			destroyDeck();

			var show = unresolved.slice(0, 2);
			var labels = ['NOW', 'ON DECK'];

			// #56: expose the same scrollable, status-tagged bout queue the mobile deck
			// has, reachable from the desktop Next-Up strip. Reuses buildBoutListSheet.
			var boutListBtn = '<button type="button" class="tn-boutlist-trigger" data-tn-boutlist-open="1" aria-label="Open bout list">List ≡</button>';
			nuHost.innerHTML =
				'<div class="tn-nu-wrap">' +
					'<div class="tn-nu-header">' +
						'<span class="tn-nu-title">Next up</span>' +
						'<span class="tn-nu-sub">&mdash; updates after each result</span>' +
						boutListBtn +
						toggleHTML +
					'</div>' +
					'<div class="tn-nu-grid">' +
						show.map(function(m, i){ return cardFn(m, pMap, labels[i]); }).join('') +
					'</div>' +
				'</div>';

			bindToggle(bid);
			var _blBtn = nuHost.querySelector('[data-tn-boutlist-open]');
			if (_blBtn) _blBtn.addEventListener('click', function(ev){ ev.preventDefault(); openBoutListSheet(); });
			bindNuCardHandlers(bid, bd, pMap);
		};

		// Wire the per-card recording handlers (pips / End / ⋯ more / quick-win
		// buttons) on whatever .tn-nu-card markup is currently inside nuHost.
		// Shared by the desktop grid render AND the mobile deck render — the deck
		// mounts its FULL card into nuHost (same data-mid scoping), so the exact
		// same handlers and the exact same submit path apply in both layouts.
		function bindNuCardHandlers(bid, bd, pMap){
			if (!nuHost) return;
			if (mode === 'track'){
				nuHost.querySelectorAll('.tn-nu-card-track .tn-bout-pip').forEach(function(pip){
					pip.addEventListener('click', handlePipClick);
				});
				// End buttons — early-finish commit per card
				nuHost.querySelectorAll('.tn-nu-card-track .tn-nu-btn-end').forEach(function(btn){
					btn.addEventListener('click', handleEndClick);
				});
				// The ⋯ more button still works in track mode
				nuHost.querySelectorAll('.tn-nu-card-track .tn-nu-btn[data-more="1"]').forEach(function(btn){
					btn.addEventListener('click', function(ev){
						ev.preventDefault();
						var mid = btn.getAttribute('data-mid');
						var matchObj = (bd.Matches || []).find(function(mm){ return String(mm.MatchId) === String(mid); });
						if (matchObj){
							var p1 = pMap[matchObj.Participant1Id];
							var p2 = pMap[matchObj.Participant2Id];
							if (typeof window.tnOpenRecordResult === 'function') window.tnOpenRecordResult(matchObj, p1, p2);
						}
					});
				});
				// Seed button visibility for any pre-existing pip state (e.g. after a refresh preserves trackState)
				nuHost.querySelectorAll('.tn-nu-card-track').forEach(function(card){
					updateEndButton(card.getAttribute('data-mid'));
				});
			} else {
				bindQuickButtons(bd, pMap);
			}
		}

		// Live data the mounted deck's render closures read. Updated on every
		// renderNextUpDeck() call so deckHandle.update() re-renders with current
		// participants without needing a remount. deckMode tracks the Quick/Track
		// mode the deck was mounted with — a mode flip forces a remount.
		var deckBd = null, deckPMap = null, deckMode = null;

		// Mount-or-update the mobile Match Deck. items = pre-sliced window (current
		// + next 2). A Quick/Track toggle header (same markup + bindToggle as
		// desktop) sits above a #tn-deck-host child of nuHost; the deck mounts INTO
		// that child. Because the host stays a descendant of nuHost, every existing
		// nuHost.querySelector('.tn-nu-card-track[data-mid=...]') (repaintCardPips,
		// updateEndButton, the pip/End/more bindings) keeps matching. The FULL lead
		// card reuses the SAME builder as desktop (quickCardHTML / trackCardHTML),
		// so recording, bout pips and the submit path are byte-identical; compact
		// cards use deckCompactHTML.
		function renderNextUpDeck(bid, bd, pMap, items){
			deckBd = bd; deckPMap = pMap;
			var deckItems = items.map(function(m){ return Object.assign({ id: m.MatchId }, m); });

			// Re-bind the per-card recording handlers + repaint pips after each deck
			// (re)render. The deck rebuilds DOM on mount / setLead / update, so the
			// FULL card's pip/End/more/quick listeners must be re-attached.
			function afterRender(){
				if (deckBd && deckPMap) bindNuCardHandlers(deckBid, deckBd, deckPMap);
			}

			// Same bracket AND same mode -> just refresh the deck (preserves lead).
			var host = $('tn-deck-host');
			if (deckHandle && host && deckBid === bid && deckMode === mode){
				deckHandle.update(deckItems);
				afterRender();
				return;
			}

			// Fresh mount (first time / bracket changed / mode toggled). Render the
			// mode toggle header + an empty deck host, then mount the deck into it.
			destroyDeck();
			deckBid = bid;
			deckMode = mode;
			var toggleHTML =
				'<div class="tn-nu-toggle" role="tablist" aria-label="Next Up mode">' +
					'<button class="tn-nu-toggle-btn' + (mode === 'quick' ? ' tn-nu-toggle-on' : '') + '" data-mode="quick" role="tab" aria-selected="' + (mode === 'quick') + '">Quick Win</button>' +
					'<button class="tn-nu-toggle-btn' + (mode === 'track' ? ' tn-nu-toggle-on' : '') + '" data-mode="track" role="tab" aria-selected="' + (mode === 'track') + '">Track Fights</button>' +
				'</div>';
			// Bout List pill — mobile-only "where am I" jump sheet trigger.
			var boutListBtn = '<button type="button" class="tn-boutlist-trigger" data-tn-boutlist-open="1" aria-label="Open bout list">List \u2261</button>';
			nuHost.innerHTML =
				'<div class="tn-nu-wrap">' +
					'<div class="tn-nu-header"><span class="tn-nu-title">Next up</span>' +
					'<span class="tn-nu-sub">&mdash; swipe for on-deck</span>' + boutListBtn + toggleHTML + '</div>' +
					'<div id="tn-deck-host"></div>' +
				'</div>';
			bindToggle(bid);
			var blBtn = nuHost.querySelector('[data-tn-boutlist-open]');
			if (blBtn) blBtn.addEventListener('click', function(ev){ ev.preventDefault(); openBoutListSheet(); });
			host = $('tn-deck-host');
			deckHandle = TnMobile.deck.mount(host, {
				items: deckItems,
				renderFull: function(item){
					var fn = (deckMode === 'track') ? trackCardHTML : quickCardHTML;
					return fn(item, deckPMap || pMap, 'NOW');
				},
				renderCompact: function(item){
					return deckCompactHTML(item, deckPMap || pMap);
				},
				// Fires on swipe / tap-to-promote (lead actually moved) — re-bind so
				// the newly-promoted full card's recording handlers work.
				onLeadChange: function(){ afterRender(); }
			});
			afterRender();
		}

		// Wrap the base bracket viz renderer so Next-Up repaints on every
		// refresh (initial render + every match submit).
		(function wrapRender(){
			function attempt(){
				if (typeof window.tnRenderBracketViz !== 'function'){
					setTimeout(attempt, 60); return;
				}
				var original = window.tnRenderBracketViz;
				window.tnRenderBracketViz = function(bid){
					var ret = original.apply(this, arguments);
					try { window.tnRenderNextUp(bid); } catch(e){ console.warn('[tn-nextup] render failed', e); }
					return ret;
				};
				var sel = $('tn-bv-bracket-select');
				if (sel && sel.value) window.tnRenderNextUp(parseInt(sel.value, 10));
			}
			if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attempt);
			else attempt();
		})();

		// Switch Next-Up between the desktop grid and the mobile Match Deck when
		// the view mode flips. tnRenderNextUp re-enters the correct branch:
		// mobile -> mount/refresh the deck; desktop -> destroyDeck() then the grid.
		// We re-render against the currently selected bracket.
		(function bindViewModeSwitch(){
			var root = document.getElementById('tn-root');
			if (!root) return;
			root.addEventListener('tn:viewmodechange', function(){
				var sel = $('tn-bv-bracket-select');
				if (sel && sel.value){
					try { window.tnRenderNextUp(parseInt(sel.value, 10)); }
					catch(e){ console.warn('[tn-nextup] viewmode re-render failed', e); }
				}
			});
		})();
	})();

	// ================================================================
	// TASK 13 · Pip majority → soft auto-commit
	// The Record Result modal already mirrors pip state to the result
	// dropdown. What it does not do is save without a button click.
	// When one fighter reaches mathematical majority (3+ of 5, or any
	// state where the other side can no longer tie), we start a 2s
	// visible countdown and auto-click the submit button. Any pip
	// click or a modal close cancels.
	// ================================================================
	(function(){
		var rrOverlay  = $('tn-recordresult-overlay');
		var scoreEl    = $('tn-rr-bout-score');
		if (!rrOverlay || !scoreEl) return;

		var commitTimer = null;
		var countdownTimer = null;
		var originalScoreHTML = '';

		function countWins(side){
			return document.querySelectorAll('#tn-rr-pips-' + side + ' .tn-pip-win').length;
		}

		function cancelCommit(){
			if (commitTimer){ clearTimeout(commitTimer); commitTimer = null; }
			if (countdownTimer){ clearInterval(countdownTimer); countdownTimer = null; }
			if (scoreEl.dataset.tnAutocommit === '1'){
				scoreEl.dataset.tnAutocommit = '';
				scoreEl.style.color = '';
				// let renderPips / updateScoreDisplay repaint naturally on the next click
			}
		}

		function startCommit(winnerLabel){
			cancelCommit();
			scoreEl.dataset.tnAutocommit = '1';
			scoreEl.style.color = '#276749';
			var remaining = 2;
			// Build stable DOM ONCE so the cancel link doesn't race user clicks.
			scoreEl.innerHTML = '';
			var winStrong = document.createElement('strong');
			winStrong.textContent = winnerLabel;
			scoreEl.appendChild(winStrong);
			scoreEl.appendChild(document.createTextNode(' · saving in '));
			var numSpan = document.createElement('span');
			numSpan.className = 'tn-rr-countdown-num';
			numSpan.textContent = String(remaining);
			scoreEl.appendChild(numSpan);
			scoreEl.appendChild(document.createTextNode('s · '));
			var cancelLink = document.createElement('a');
			cancelLink.href = '#';
			cancelLink.id = 'tn-rr-cancel-commit';
			cancelLink.style.color = '#e53e3e';
			cancelLink.style.textDecoration = 'underline';
			cancelLink.textContent = 'cancel';
			cancelLink.addEventListener('click', function(e){ e.preventDefault(); cancelCommit(); });
			scoreEl.appendChild(cancelLink);
			countdownTimer = setInterval(function(){
				remaining--;
				if (remaining <= 0){ clearInterval(countdownTimer); countdownTimer = null; return; }
				numSpan.textContent = String(remaining);
			}, 1000);
			commitTimer = setTimeout(function(){
				commitTimer = null;
				scoreEl.dataset.tnAutocommit = '';
				scoreEl.style.color = '';
				// Abort if modal was closed mid-countdown.
				if (!rrOverlay.classList.contains('tn-open')) return;
				var sb = $('tn-recordresult-submit');
				if (sb && !sb.disabled) sb.click();
			}, 2000);
		}

		function evaluate(){
			var p1 = countWins(1);
			var p2 = countWins(2);
			var total = p1 + p2;
			if (total === 0){ cancelCommit(); return; }
			// Effective best-of is stashed on the overlay by tnOpenRecordResult.
			var bestOf = parseInt(rrOverlay.getAttribute('data-best-of') || '5', 10);
			if ([1,3,5,7,9].indexOf(bestOf) === -1) bestOf = 5;
			var remaining = bestOf - total;
			if (p1 > p2 && (p1 - p2) > remaining){
				var n1 = $('tn-rr-p1-name') ? $('tn-rr-p1-name').textContent : 'Player 1';
				startCommit(n1 + ' wins');
			} else if (p2 > p1 && (p2 - p1) > remaining){
				var n2 = $('tn-rr-p2-name') ? $('tn-rr-p2-name').textContent : 'Player 2';
				startCommit(n2 + ' wins');
			} else {
				cancelCommit();
			}
		}

		// Piggyback on pip clicks: the existing click handler fires first
		// (it was attached with addEventListener during IIFE init), then
		// our bubble-phase listener reads the freshly-painted pip classes.
		['tn-rr-pips-1','tn-rr-pips-2'].forEach(function(id){
			var el = $(id);
			if (!el) return;
			el.addEventListener('click', function(){
				setTimeout(evaluate, 0); // after existing handler completes
			});
		});

		// Closing the modal cancels any pending commit
		var mo = new MutationObserver(function(){
			if (!rrOverlay.classList.contains('tn-open')) cancelCommit();
		});
		mo.observe(rrOverlay, { attributes: true, attributeFilter: ['class'] });
	})();

	// Shared clear+generate chain (de-duped) used by both the desktop
	// undo-toast auto-commit and the mobile confirm-sheet commit. Chain:
	// clearmatches (also resets bracket status to 'setup') -> generate.
	// Direct fetch so we don't trip the tnGenerateMatches confirm() dialog
	// on top. `onError` runs in .catch BEFORE the shared alert (lets the
	// desktop caller re-enable its armed button).
	window.tnRegenFetch = function(bid, tid, onError){
		// #30: register + tag both writes so the acting reeve's own change is
		// recognized as an echo by the collab delta loop.
		// Two ids: action_id is unique per tournament, so a shared id would make the
		// server silently drop the second (matches_generated) event row.
		var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
		var genActionId = window.tnNewActionId ? window.tnNewActionId() : '';
		if (window.tnRegisterAction) { window.tnRegisterAction(actionId); window.tnRegisterAction(genActionId); }
		if (window.tnCollabNudge) window.tnCollabNudge();
		var fd1 = new FormData();
		fd1.append('TournamentId', tid);
		fd1.append('ActionId', actionId);
		fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/clearmatches', { method: 'POST', body: fd1 })
			.then(function(r){ return r.json(); })
			.then(function(d){
				if (!d || d.status !== 0) throw new Error((d && d.error) || 'Clear failed');
				var fd2 = new FormData();
				fd2.append('BracketId', bid);
				fd2.append('ActionId', genActionId);
				return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/generate', { method: 'POST', body: fd2 });
			})
			.then(function(r){ return r.json(); })
			.then(function(d){
				if (!d || d.status !== 0) throw new Error((d && d.error) || 'Generate failed');
				window.location.reload();
			})
			.catch(function(err){
				if (typeof onError === 'function') onError(err);
				window.tnToast('Re-generate failed: ' + (err && err.message ? err.message : err));
			});
	};

	// ================================================================
	// TASK 12 · UNDO-TOAST Re-generate
	// Clicking Re-generate arms a 4s countdown on the button itself.
	// At the end of the countdown it commits automatically. Clicking
	// the explicit "cancel" link inside the button during the
	// countdown aborts. Clicking the body of the button again while
	// armed is a no-op (the countdown is already doing the work).
	// Click-anywhere-else does not cancel — only the explicit cancel.
	// ================================================================
	(function(){
		var armedBtn = null;
		var tickTimer = null;
		var armedOriginal = null;

		function disarm(){
			if (!armedBtn) return;
			armedBtn.innerHTML = armedOriginal;
			armedBtn.classList.remove('tn-btn-danger');
			armedBtn.classList.add('tn-btn-primary');
			armedBtn.removeAttribute('data-armed');
			armedBtn.removeAttribute('aria-live');
			armedBtn = null;
			armedOriginal = null;
			if (tickTimer){ clearTimeout(tickTimer); tickTimer = null; }
		}

		function commit(bid, tid){
			// Capture the button before disarm() clears the reference, then
			// disable it so a double-click can't fire a second clear+generate.
			var cBtn = armedBtn;
			disarm();
			if (cBtn) cBtn.disabled = true;
			// Shared clear+generate chain; on error re-enable the armed button.
			window.tnRegenFetch(bid, tid, function(){ if (cBtn) cBtn.disabled = false; });
		}

		// Mobile path: a styled confirm sheet calls this directly (no armed
		// button, no countdown). Same clear+generate commit chain as desktop's
		// auto-commit (see commit() above); only the countdown UI is bypassed on touch.
		window.tnRegenCommit = function(bid, tid){
			window.tnRegenFetch(bid, tid);
		};

		window.tnRegenArm = function(btn, ev){
			if (ev) { ev.preventDefault(); ev.stopPropagation(); }
			if (!btn) return false;
			var bid = parseInt(btn.getAttribute('data-bid'), 10);
			var tid = parseInt(btn.getAttribute('data-tid'), 10);
			var n   = parseInt(btn.getAttribute('data-match-count'), 10) || 0;

			// Second click while armed. The "cancel" link disarms; a click anywhere
			// else on the armed button is the explicit confirmation that commits the
			// destructive wipe. We NEVER auto-commit on a timer (#50).
			if (btn.getAttribute('data-armed') === '1'){
				if (ev && ev.target && ev.target.closest('.tn-regen-cancel')){
					disarm();
				} else {
					commit(bid, tid);
				}
				return false;
			}

			// First click — arm + require an explicit second click to confirm. The
			// countdown counts down to CANCEL (auto-disarm), never to a commit.
			if (armedBtn && armedBtn !== btn) disarm();
			armedBtn = btn;
			armedOriginal = btn.innerHTML;
			btn.classList.remove('tn-btn-primary');
			btn.classList.add('tn-btn-danger');
			btn.setAttribute('data-armed', '1');
			btn.setAttribute('aria-live', 'assertive');
			var remaining = 4;
			function render(){
				btn.innerHTML =
					'<i class="fas fa-exclamation-triangle"></i> ' +
					'Click again to wipe ' + n + ' match' + (n===1?'':'es') + ' — cancels in ' + remaining + 's ' +
					'<span class="tn-regen-cancel" style="text-decoration:underline;margin-left:10px;font-weight:700;cursor:pointer">cancel</span>';
			}
			render();
			var tick = function(){
				remaining--;
				if (remaining <= 0){ disarm(); return; }
				render();
				tickTimer = setTimeout(tick, 1000);
			};
			tickTimer = setTimeout(tick, 1000);
			return false;
		};
	})();

	var _bulkBtn = $('tn-bulkadd-submit');
	if (_bulkBtn) _bulkBtn.addEventListener('click', function(){
		var text = $('tn-bulkadd-text').value || '';
		var bid  = $('tn-bulkadd-bracket-id').value;
		var tid  = $('tn-bulkadd-tournament-id').value;
		var fb   = $('tn-bulkadd-feedback');
		var prog = $('tn-bulkadd-progress');
		var lines = text.split(/\r?\n/).map(function(l){ return l.trim(); }).filter(function(l){ return l.length > 0; });
		if (!lines.length){
			tnShowFeedback('tn-bulkadd-feedback', 'Paste at least one alias.', false);
			return;
		}
		_bulkBtn.disabled = true;
		fb.style.display = 'none';
		prog.style.display = '';
		var ok = 0, fail = 0, done = 0;
		var total = lines.length;
		var failed = [];   // [{alias, error}] — kept for retry + per-line reasons
		function addOne(alias){
			var fd = new FormData();
			fd.append('Alias', alias);
			fd.append('TournamentId', tid);
			return fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/addparticipant', { method:'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(d){
					if (d && d.status === 0) ok++;
					else { fail++; failed.push({ alias: alias, error: (d && d.error) || 'Unknown error.' }); }
				})
				.catch(function(){ fail++; failed.push({ alias: alias, error: 'Network error.' }); })
				.finally(function(){ done++; prog.textContent = 'Adding ' + done + ' of ' + total + '…'; });
		}
		// Post one at a time so participant numbers follow the paste order.
		lines.reduce(function(p, alias){
			return p.then(function(){ return addOne(alias); });
		}, Promise.resolve()).then(function(){
			prog.textContent = 'Done — added ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.';
			if (!fail) {
				setTimeout(function(){ closeBulkAdd(); tnBulkReload(); }, 500);
				return;
			}
			// Partial/total failure: keep the modal open with only the failed
			// aliases back in the textarea so they can be fixed and retried.
			// Successful adds show up when the modal is closed (reload then).
			if (ok > 0) _bulkReloadOnClose = true;
			$('tn-bulkadd-text').value = failed.map(function(f){ return f.alias; }).join('\n');
			tnUpdateBulkCount();
			tnShowFeedback('tn-bulkadd-feedback',
				fail + ' line' + (fail === 1 ? '' : 's') + ' failed' + (ok ? ' (' + ok + ' added)' : '') + ':\n' +
				failed.map(function(f){ return '• ' + f.alias + ' — ' + f.error; }).join('\n'), false);
			fb.style.whiteSpace = 'pre-line';
			_bulkBtn.disabled = false;
		});
	});

})();
</script>

<!-- =============================================
     Tournament features: Spectator poll, Reeves panel, Recommend modal
     ============================================= -->
<style>
.tn-toast-wrap { position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
.tn-toast { background: #1f2937; color: #f9fafb; border: 1px solid #374151; border-radius: 8px; padding: 9px 14px; font-size: 13px; box-shadow: 0 4px 14px rgba(0,0,0,.25); opacity: 0; transform: translateY(8px); transition: opacity .18s, transform .18s; max-width: 88vw; }
.tn-toast.tn-toast-show { opacity: 1; transform: translateY(0); }
html[data-theme="dark"] .tn-toast { background: #e5e7eb; color: #111827; border-color: #d1d5db; }
.tn-match-pending { animation: tnPendingPulse 1s ease-in-out infinite; }
@keyframes tnPendingPulse { 0%,100% { opacity: 1; } 50% { opacity: .55; } }
</style>
<script>
window.tnToast = function(msg, ms, opts) {
	var wrap = document.getElementById('tn-toast-wrap');
	if (!wrap) { wrap = document.createElement('div'); wrap.id = 'tn-toast-wrap'; wrap.className = 'tn-toast-wrap'; wrap.setAttribute('role', 'status'); wrap.setAttribute('aria-live', 'polite'); wrap.setAttribute('aria-atomic', 'false'); document.body.appendChild(wrap); }
	var t = document.createElement('div');
	t.className = 'tn-toast';
	t.textContent = msg;
	// Errors announce assertively: role=alert on the toast node overrides the
	// polite wrap for this message only (#77). Auto-classified so the ~40 error
	// call sites don't each need an opts flag; opts.assertive forces it.
	var _isErr = (opts && opts.assertive) || /\b(error|fail(ed)?|network|timed out|could not|unable|not saved|not recorded)\b/i.test(msg || '');
	// Optional tap affordance (the wrap is pointer-events:none, so opt the toast back in).
	if (opts && typeof opts.onClick === 'function') {
		t.style.pointerEvents = 'auto';
		t.style.cursor = 'pointer';
		t.setAttribute('role', 'button');
		t.setAttribute('tabindex', '0');
		var act = function() { try { opts.onClick(); } finally { if (t.parentNode) t.parentNode.removeChild(t); } };
		t.addEventListener('click', act);
		t.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); act(); } });
	} else if (_isErr) {
		t.setAttribute('role', 'alert');   // assertive announcement for errors (#77)
	}
	// Stack new toasts ABOVE existing ones: the wrap is bottom-anchored, so appending
	// pushed a live toast (e.g. "tap to undo") upward and slid the new one under the
	// user's finger — a tap meant for Undo hit a collab "tap to view" toast instead
	// and jumped the Run view to the other bracket.
	wrap.insertBefore(t, wrap.firstChild);
	requestAnimationFrame(function() { t.classList.add('tn-toast-show'); });
	setTimeout(function() {
		t.classList.remove('tn-toast-show');
		setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 220);
	}, ms || 3200);
};

// #35: shared match-reset POST used by the "Undo" result toast. Mints + registers a
// fresh ActionId exactly like the #29 desktop reset handler so the undo echoes back
// as a recognized own-action (no spurious "someone reset" toast), keeps the collab
// cursor ahead of the returned seq, and refetches the affected bracket.
window.tnResetMatchAjax = function(matchId, bid) {
	var tid = TnConfig.tournamentId;
	var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
	if (window.tnRegisterAction) window.tnRegisterAction(actionId);
	if (window.tnCollabNudge) window.tnCollabNudge();
	var rfd = new FormData();
	rfd.append('ActionId', actionId);
	return fetch(TnConfig.uir + 'TournamentAjax/match/' + matchId + '/' + tid + '/reset', { method: 'POST', body: rfd })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d && d.status === 0) {
				if (typeof d.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(d.seq);
				var sel = document.getElementById('tn-bv-bracket-select');
				var cur = parseInt(bid) || (sel ? parseInt(sel.value) : 0);
				if (cur && TnConfig.bracketData[cur]) {
					var _rt = window.tnBvReqBegin ? window.tnBvReqBegin(cur) : 0;
					return Promise.all([
						fetch(TnConfig.uir + 'TournamentAjax/bracket/' + cur + '/matches').then(function(r) { return r.json(); }),
						fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); })
					]).then(function(res) {
						var md = res[0], bd2 = res[1];
						var _rtCur = !window.tnBvReqCurrent || window.tnBvReqCurrent(cur, _rt); // a newer fetch of this bracket writes + paints
						if (_rtCur && md && md.status === 0) TnConfig.bracketData[cur].Matches = md.matches;
						if (_rtCur && bd2 && bd2.status === 0 && bd2.brackets) {
							var br = bd2.brackets.find(function(b) { return parseInt(b.BracketId) === parseInt(cur); });
							if (br) TnConfig.bracketData[cur].Bracket = br;
						}
						if (_rtCur && typeof window.tnRenderBracketViz === 'function') window.tnRenderBracketViz(cur);
					}).catch(function(err) { console.warn('[tn] undo refresh failed', err); if (window.tnShowStaleWarning) tnShowStaleWarning(); });
				}
			} else if (window.tnToast) {
				window.tnToast((d && d.error) ? d.error : 'Undo failed.');
			}
		})
		.catch(function() { if (window.tnToast) window.tnToast('Undo request failed.'); });
};

// #35: one helper every record-result success path shares — shows "Result recorded"
// and, when the just-recorded match can actually be reset, offers a tap-to-Undo that
// reuses tnResetMatchAjax. Call AFTER the post-result refresh so the resettability
// test sees current downstream state. Accepts a matchId (+ optional bracket id).
window.tnResultRecordedToast = function(matchId, bid) {
	var resettable = false, b = parseInt(bid) || 0;
	if (!b) { var _bsel = document.getElementById('tn-bv-bracket-select'); b = _bsel ? (parseInt(_bsel.value) || 0) : 0; }
	if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(b);
	if (!window.tnToast) return;
	try {
		var mo = (b && TnConfig.bracketData[b] && TnConfig.bracketData[b].Matches)
			? TnConfig.bracketData[b].Matches.find(function(m) { return parseInt(m.MatchId) === parseInt(matchId); })
			: null;
		if (mo) {
			if (mo.BracketId == null) mo.BracketId = b;
			resettable = (typeof window.tnIsMatchResettable === 'function') ? window.tnIsMatchResettable(mo) : true;
		}
	} catch (e) {}
	if (resettable && matchId) {
		window.tnToast('Result recorded — tap to undo', 6000, { onClick: function() { window.tnResetMatchAjax(matchId, b); } });
	} else {
		window.tnToast('Result recorded', 2500);
	}
};

// Registry of action_ids this client originated. Used to drop our own changes when
// they echo back in the delta feed. Entries are pruned by seq progression, not wall
// clock: an id is dropped once the delta cursor has moved past the seq that echoed
// it (tnPruneOwnActions), so a tab backgrounded for minutes still recognises its own
// write when polling resumes. Ids that never echo (write lost) age out after 30min.
window.TnOwnActions = window.TnOwnActions || {};
window.tnRegisterAction = function(id) { if (id) window.TnOwnActions[id] = { t: Date.now(), seq: 0 }; };
window.tnIsOwnAction = function(id, seq) {
	if (!id) return false;
	// A reset that reopens a finalized bracket also emits 'bracket_reopened' under
	// the derived id '<id>:reopen' (the server's action_id key is unique per tournament).
	id = String(id).replace(/:reopen$/, '');
	var e = window.TnOwnActions[id];
	if (!e) return false;
	if (typeof seq === 'number' && seq > e.seq) e.seq = seq;
	return true;
};
window.tnPruneOwnActions = function(cursorSeq) {
	var now = Date.now();
	for (var k in window.TnOwnActions) {
		var e = window.TnOwnActions[k];
		if ((e.seq && typeof cursorSeq === 'number' && e.seq < cursorSeq) || (!e.seq && now - e.t > 1800000)) delete window.TnOwnActions[k];
	}
};
window.tnNewActionId = function() {
	return 'a-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
};
// Mint + register a fresh ActionId and append it to a write's FormData (#30).
window.tnTagAction = function(fd) {
	var id = window.tnNewActionId();
	window.tnRegisterAction(id);
	fd.append('ActionId', id);
	return id;
};
// Drop a previously-registered own-action id (e.g. after a local write failed/aborted)
// so that when the server-committed change echoes back it is treated as new (#28).
window.tnUnregisterAction = function(id) { if (id && window.TnOwnActions) delete window.TnOwnActions[id]; };
// A tie is only a valid, non-stranding result in non-elimination formats. In
// single/double/ironman elimination a committed tie leaves the bracket unable to
// advance, so the Tie option and every tie auto-resolution are suppressed there (#13).
// Ties are legitimate only where they don't strand advancement: round-robin, swiss
// (half-point standings, re-paired each round) and points. Single/double/ironman
// elimination MUST have a winner, so a tie there would leave nobody to advance.
window.tnMethodAllowsTie = function(method) { return method === 'round-robin' || method === 'swiss' || method === 'points'; };
</script>
<script>
// ============================================================
// Feature 1 — Spectator Mode: adaptive version polling
// ============================================================
(function() {
	if (!TnConfig.spectator) return;

	var bar     = document.getElementById('tn-spectator-bar');
	var dismiss = document.getElementById('tn-spectator-dismiss');
	var syncEl  = document.getElementById('tn-spectator-sync');
	if (dismiss && bar) {
		dismiss.addEventListener('click', function() {
			bar.classList.add('tn-spectator-hidden');
		});
	}

	// #33: spectators ride the SAME GetSeq/GetChanges cursor the reeve loop uses —
	// a seq bump fetches the delta feed and refetches ONLY the named bracket(s),
	// instead of refetching every bracket on any version change. Read-only: no
	// ActionId is minted and no toasts are shown.
	var clientSeq = null;
	var timer = null;
	var paused = document.hidden;   // a tab opened in the background starts paused
	var inFlight = false;

	function anyActive() {
		var bd = TnConfig.bracketData || {};
		for (var k in bd) {
			if (bd[k] && bd[k].Bracket && bd[k].Bracket.Status === 'active') return true;
		}
		return false;
	}

	function flashSync(msg) {
		if (!syncEl) return;
		syncEl.textContent = msg;
		syncEl.classList.add('tn-spectator-flash');
		setTimeout(function() { syncEl.classList.remove('tn-spectator-flash'); }, 2500);
	}

	// Refresh all brackets' data and re-render the currently-shown bracket viz.
	// Mirrors the post-result refresh path (Promise.all matches + brackets).
	function refreshAll() {
		var bd = TnConfig.bracketData || {};
		var bids = Object.keys(bd);
		if (!bids.length) return Promise.resolve();
		var toks = {};
		bids.forEach(function(bid) { toks[bid] = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0; });
		var isCur = function(bid) { return !window.tnBvReqCurrent || window.tnBvReqCurrent(bid, toks[bid]); };
		var calls = [
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/brackets').then(function(r) { return r.json(); })
		];
		bids.forEach(function(bid) {
			calls.push(
				fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches')
					.then(function(r) { return r.json(); })
					.then(function(md) { return { bid: bid, md: md }; })
			);
		});
		return Promise.all(calls).then(function(res) {
			var brResp = res[0];
			if (brResp && brResp.status === 0 && brResp.brackets) {
				brResp.brackets.forEach(function(br) {
					var id = parseInt(br.BracketId);
					if (TnConfig.bracketData[id] && (toks[id] === undefined || isCur(id))) TnConfig.bracketData[id].Bracket = br;
				});
			}
			for (var i = 1; i < res.length; i++) {
				var item = res[i];
				if (item.md && item.md.status === 0 && TnConfig.bracketData[item.bid] && isCur(item.bid)) {
					TnConfig.bracketData[item.bid].Matches = item.md.matches;
				}
			}
			if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
			// Re-render the currently-selected bracket viz, if present.
			var sel = document.getElementById('tn-bv-bracket-select');
			var curBid = sel ? parseInt(sel.value) : 0;
			if (curBid && TnConfig.bracketData[curBid] && isCur(curBid) && typeof tnRenderBracketViz === 'function'
				&& !(window.TnQuickBracket && window.TnQuickBracket.holdRepaint(curBid))) {
				tnRenderBracketViz(curBid);
			}
			// Refresh the standings leaderboard if that fn exists.
			if (typeof tnRenderLeaderboard === 'function') tnRenderLeaderboard();
		}).catch(function(err) {
			console.warn('[tn-spectator] refresh failed', err);
			if (window.tnShowStaleWarning) tnShowStaleWarning();
			// #25: reject so the caller does NOT advance lastVersion or flash "Updated"
			// on a failed refresh — the next poll retries the same version.
			throw err;
		});
	}

	// Participants JSON for a bracket still in setup (null otherwise / on failure).
	function _qbWantParticipants(bid) {
		var b = TnConfig.bracketData[bid] && TnConfig.bracketData[bid].Bracket;
		if (b && b.Status && b.Status !== 'setup') return Promise.resolve(null);
		return fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/participants')
			.then(function(r) { return r.json(); }).catch(function() { return null; });
	}

	// Refetch a SINGLE bracket's matches + meta and re-render it if it's on screen.
	// Mirrors refreshAll's per-bracket logic but for just one id (the delta target).
	function refetchBracket(bid) {
		bid = parseInt(bid);
		if (!bid) return Promise.resolve();
		var tok = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0;
		return Promise.all([
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r) { return r.json(); }),
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/brackets').then(function(r) { return r.json(); }),
			// Quick Bracket draft: Participants (seat map) is the whole state, so peers pull it too.
			_qbWantParticipants(bid)
		]).then(function(res) {
			var md = res[0], bd = res[1], pd = res[2];
			// A newer fetch of this bracket supersedes this response.
			if (TnConfig.bracketData[bid] && window.tnBvReqCurrent && !window.tnBvReqCurrent(bid, tok)) return;
			if (bd && bd.status === 0 && bd.brackets) {
				var br = bd.brackets.find(function(b) { return parseInt(b.BracketId) === bid; });
				if (br) {
					if (!TnConfig.bracketData[bid]) TnConfig.bracketData[bid] = { Bracket: br, Participants: [] };
					else TnConfig.bracketData[bid].Bracket = br;
				}
			}
			if (md && md.status === 0 && TnConfig.bracketData[bid]) TnConfig.bracketData[bid].Matches = md.matches;
			if (pd && pd.status === 0 && TnConfig.bracketData[bid]) TnConfig.bracketData[bid].Participants = pd.participants || [];
			if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
			var sel = document.getElementById('tn-bv-bracket-select');
			var curBid = sel ? parseInt(sel.value) : 0;
			if (bid === curBid && typeof tnRenderBracketViz === 'function'
				&& !(window.TnQuickBracket && window.TnQuickBracket.holdRepaint(bid))) tnRenderBracketViz(bid);
			if (typeof tnRenderLeaderboard === 'function') tnRenderLeaderboard();
		});
	}

	// Apply a /changes payload: refetch only the brackets the events name. A resync
	// flag (or a below-cursor seq) falls back to refreshAll. Advance the cursor ONLY
	// after every refetch fulfills so a failed refetch retries the same delta.
	function applyDeltas(data) {
		if (data.resync) { return refreshAll().then(function() { clientSeq = data.seq; }); }
		var events = data.events || [];
		var toRefetch = {};
		events.forEach(function(ev) { if (ev.BracketId) toRefetch[ev.BracketId] = true; });
		var bids = Object.keys(toRefetch);
		if (!bids.length) { clientSeq = data.seq; return Promise.resolve(); }
		return Promise.all(bids.map(function(b) { return refetchBracket(parseInt(b)); }))
			.then(function() { clientSeq = data.seq; });
	}

	function poll() {
		if (paused || inFlight) return;   // skip this tick if a cycle is still in flight
		inFlight = true;
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/seq')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (!d || d.status !== 0) return;
				if (clientSeq === null) { clientSeq = d.seq; return; }
				// Server reset/lost its seq (memcache flush, restart) — resync fully.
				if (d.resync || d.reset || d.seq < clientSeq) {
					flashSync('Updating…');
					return refreshAll().then(function() { clientSeq = d.seq; flashSync('Updated just now'); });
				}
				if (d.seq > clientSeq) {
					flashSync('Updating…');
					return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/changes&since=' + clientSeq)
						.then(function(r) { return r.json(); })
						.then(function(cd) {
							// #33 fallback: a failed /changes leaves the cursor in place so
							// the next tick retries the same delta rather than skipping it.
							if (cd && cd.status === 0) return applyDeltas(cd).then(function() { flashSync('Updated just now'); });
						});
				}
			})
			.catch(function(err) { console.warn('[tn-spectator] seq poll failed', err); })
			.finally(function() { inFlight = false; });
	}

	function schedule() {
		if (timer) clearTimeout(timer);
		if (paused) return;
		var interval = anyActive() ? 5000 : 20000;
		timer = setTimeout(function() { poll(); schedule(); }, interval);
	}

	document.addEventListener('visibilitychange', function() {
		if (document.hidden) {
			paused = true;
			if (timer) { clearTimeout(timer); timer = null; }
		} else {
			paused = false;
			poll();       // immediate refresh on return
			schedule();
		}
	});

	// Seed the baseline seq cursor then begin the loop.
	fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/seq')
		.then(function(r) { return r.json(); })
		.then(function(d) { if (d && d.status === 0) clientSeq = d.seq; })
		.catch(function() {})
		.finally(function() { schedule(); });
})();


// ============================================================
// Reeve live collaboration — seq heartbeat + per-bracket delta sync
// ============================================================
(function() {
	// Reeves only; spectators already have their own version loop.
	if (TnConfig.spectator || !TnConfig.loggedIn) return;
	if (!(TnConfig.canManage || TnConfig.isOrganizerReeve || TnConfig.isBracketRunner)) return;

	var clientSeq = null;
	var timer = null;
	var paused = document.hidden;   // a tab opened in the background starts paused
	var nudgeUntil = 0;
	var inFlight = false;

	// #27: persistent live-sync indicator. Flips to "Reconnecting…" after N consecutive
	// seq-poll failures (counted in the poll .catch) and back to "Live" on the next
	// successful poll, so a silently-dead heartbeat is visible instead of console-only.
	var seqFailures = 0;
	var RECONNECT_AFTER = 3;
	var syncChip = null, syncChipDot = null, syncChipText = null;
	(function() {
		syncChip = document.createElement('div');
		syncChip.id = 'tn-collab-chip';
		syncChip.setAttribute('role', 'status');
		syncChip.setAttribute('aria-live', 'polite');
		// z-index stays below .tn-overlay (1100) so open modals/sheets cover the chip.
		syncChip.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:1060;display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:16px;font-size:12px;font-weight:600;background:#1f2937;color:#e5e7eb;border:1px solid #374151;box-shadow:0 2px 8px rgba(0,0,0,.2);opacity:.9;';
		syncChipDot = document.createElement('span');
		syncChipDot.style.cssText = 'width:8px;height:8px;border-radius:50%;background:#38a169;flex-shrink:0;';
		syncChipText = document.createElement('span');
		syncChipText.textContent = 'Live';
		syncChip.appendChild(syncChipDot);
		syncChip.appendChild(syncChipText);
		if (document.body) document.body.appendChild(syncChip);
	})();
	function setSyncState(live) {
		if (!syncChip) return;
		if (live) {
			syncChipDot.style.background = '#38a169';
			syncChipText.textContent = 'Live';
		} else {
			syncChipDot.style.background = '#dd6b20';
			syncChipText.textContent = 'Reconnecting…';
		}
	}

	function anyActive() {
		var bd = TnConfig.bracketData || {};
		for (var k in bd) { if (bd[k] && bd[k].Bracket && bd[k].Bracket.Status === 'active') return true; }
		// A Quick Bracket draft on screen is live too: peers fill seats seconds apart.
		var sel = document.getElementById('tn-bv-bracket-select');
		var cur = sel ? parseInt(sel.value, 10) : 0;
		var box = document.getElementById('tn-bv-container');
		return !!(cur && window.TnQuickBracket && window.TnQuickBracket.isDraftBracket(cur)
			&& box && box.offsetParent !== null && box.querySelector('.tn-qb-toolbar'));
	}

	// Participants JSON for a bracket still in setup (null otherwise / on failure).
	function _qbWantParticipants(bid) {
		var b = TnConfig.bracketData[bid] && TnConfig.bracketData[bid].Bracket;
		if (b && b.Status && b.Status !== 'setup') return Promise.resolve(null);
		return fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/participants')
			.then(function(r) { return r.json(); }).catch(function() { return null; });
	}

	// Refetch a single bracket's matches + meta, then re-render if it's on screen.
	function refetchBracket(bid) {
		if (!bid) return Promise.resolve();
		bid = parseInt(bid);
		var tid = TnConfig.tournamentId;
		var tok = window.tnBvReqBegin ? window.tnBvReqBegin(bid) : 0;
		return Promise.all([
			fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/matches').then(function(r) { return r.json(); }),
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + tid + '/brackets').then(function(r) { return r.json(); }),
			// Quick Bracket draft: Participants (seat map) is the whole state, so peers pull it too.
			_qbWantParticipants(bid)
		]).then(function(res) {
			var md = res[0], bd = res[1], pd = res[2];
			// A newer fetch of this bracket supersedes this response (it writes + paints).
			var _stale = !!(TnConfig.bracketData[bid] && window.tnBvReqCurrent && !window.tnBvReqCurrent(bid, tok));
			if (!_stale) {
				// #26: a delta may reference a bracket another reeve just created. Seed a new
				// entry from the bracket list instead of bailing into a dead-end no-op.
				if (bd && bd.status === 0 && bd.brackets) {
					var br = bd.brackets.find(function(b) { return parseInt(b.BracketId) === bid; });
					if (br) {
						if (!TnConfig.bracketData[bid]) TnConfig.bracketData[bid] = { Bracket: br, Participants: [] };
						else TnConfig.bracketData[bid].Bracket = br;
					}
				}
				if (md && md.status === 0 && TnConfig.bracketData[bid]) TnConfig.bracketData[bid].Matches = md.matches;
				if (pd && pd.status === 0 && TnConfig.bracketData[bid]) TnConfig.bracketData[bid].Participants = pd.participants || [];
			}
			if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
			// Re-render / leaderboard step, run after the optional points-standings fetch.
			var _after = function() {
				var sel = document.getElementById('tn-bv-bracket-select');
				var curBid = sel ? parseInt(sel.value) : 0;
				if (bid === curBid && typeof tnRenderBracketViz === 'function') {
					if (window.tnQrEntryOpen && window.tnQrEntryOpen()) {
						// Local user has an open quick-result bar on the on-screen bracket —
						// deferring avoids destroying their in-progress entry. The poll loop
						// flushes _tnPendingRerenderBid once the bar is closed.
						window._tnPendingRerenderBid = bid;
					} else if (window.TnQuickBracket && window.TnQuickBracket.holdRepaint(bid)) {
						// Quick Bracket editor open on this draft: the data above is stored; the
						// repaint is withheld and happens once when the editor closes.
					} else {
						tnRenderBracketViz(bid);
					}
				}
				if (typeof tnRenderLeaderboard === 'function') tnRenderLeaderboard();
			};
			// #19: a Points bracket's per-cell standings live outside the matches payload,
			// so a peer's point_score/points_round change would otherwise never show. Pull
			// fresh standings before re-rendering (non-fatal if the endpoint is unavailable).
			// Done even when superseded: the newer fetch (e.g. tnRefreshAndRender) doesn't
			// pull point standings, and _after only repaints from the store (newest data).
			var _isPts = TnConfig.bracketData[bid] && TnConfig.bracketData[bid].Bracket && TnConfig.bracketData[bid].Bracket.Method === 'points';
			if (_isPts) {
				return fetch(TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/pointstandings')
					.then(function(r) { return r.json(); })
					.then(function(ps) { if (ps && ps.status === 0 && TnConfig.bracketData[bid]) TnConfig.bracketData[bid].PointStandings = ps.standings || []; })
					.catch(function() { /* non-fatal: keep prior standings */ })
					.then(_after);
			}
			// Superseded, non-points: the newer fetch writes + paints the matches.
			if (!_stale) _after();
		}).catch(function(err) {
			console.warn('[tn-collab] bracket refetch failed', err);
			if (window.tnShowStaleWarning) tnShowStaleWarning();
			// #25: reject so Promise.all in applyDeltas / fullResync rejects and the seq
			// cursor is left in place rather than advanced past an unsynced change.
			throw err;
		});
	}

	// Full resync: refetch every known bracket (used when the delta feed reports resync).
	function fullResync() {
		var bids = Object.keys(TnConfig.bracketData || {});
		if (!bids.length) return Promise.resolve();
		return Promise.all(bids.map(function(b) { return refetchBracket(parseInt(b)); }));
	}

	// #10: light bracket-list refresh — pulls the brackets list and refreshes each
	// bracket's meta (status/method/name → pills) WITHOUT refetching every bracket's
	// matches. Used when the server emits a tournament-scoped 'tournament_updated'.
	function lightBracketListRefresh() {
		return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/brackets')
			.then(function(r) { return r.json(); })
			.then(function(bd) {
				if (bd && bd.status === 0 && bd.brackets) {
					bd.brackets.forEach(function(br) {
						var id = parseInt(br.BracketId);
						if (TnConfig.bracketData[id]) TnConfig.bracketData[id].Bracket = br;
						else TnConfig.bracketData[id] = { Bracket: br, Participants: [] };
					});
				}
			})
			.catch(function() { /* non-fatal: pills just stay as-is until next sync */ });
	}

	function applyDeltas(data) {
		if (data.resync) { return fullResync().then(function() { clientSeq = data.seq; }); }
		var events = data.events || [];
		var bracketsToRefetch = {};
		var lastActor = '';
		var tournamentTouched = false;
		// Backstop for the ActionId registry: a change made by this same account (e.g.
		// in a tab whose registry was lost) still refetches, but isn't toasted as a peer's.
		var selfId = parseInt(TnConfig.currentUserId, 10) || 0;
		var peerTouched = false;
		var rosterTouched = false;
		events.forEach(function(ev) {
			if (window.tnIsOwnAction && window.tnIsOwnAction(ev.ActionId, parseInt(ev.Seq, 10) || 0)) return; // echo — already applied locally
			var _isPeer = !selfId || parseInt(ev.ActorId, 10) !== selfId;
			if (_isPeer) peerTouched = true;
			// A peer's placement/removal can add a registrant or change their brackets.
			if (ev.Type === 'participant_placed' || ev.Type === 'participant_removed') rosterTouched = true;
			// #10: a bracket-scoped event ('bracket_updated' etc.) refetches that
			// bracket; a tournament-scoped 'tournament_updated' (no bracket id) does
			// a light bracket-list refresh instead of a per-bracket refetch.
			if (ev.BracketId) bracketsToRefetch[ev.BracketId] = true;
			else if (ev.Type === 'tournament_updated') tournamentTouched = true;
			if (_isPeer && ev.ActorName) lastActor = ev.ActorName;
		});
		var bids = Object.keys(bracketsToRefetch);
		if (rosterTouched && window.tnRefreshRoster) window.tnRefreshRoster();
		// #25: no work to do (all echoes) is a successful sync — advance the cursor.
		// When there IS work, advance ONLY after every refetch fulfills so a failed
		// refetch leaves the cursor in place (and skips the success toast).
		if (!bids.length && !tournamentTouched) { clientSeq = data.seq; if (window.tnPruneOwnActions) window.tnPruneOwnActions(clientSeq); return Promise.resolve(); }
		var work = bids.map(function(b) { return refetchBracket(parseInt(b)); });
		if (tournamentTouched) work.push(lightBracketListRefresh());
		return Promise.all(work).then(function() {
			clientSeq = data.seq;
			if (window.tnPruneOwnActions) window.tnPruneOwnActions(clientSeq);
			// A tournament-only change (no bracket refetch) has no per-bracket toast.
			if (!bids.length) return;
			if (!window.tnToast || !peerTouched) return;
			// Give the toast a bracket label + a jump affordance when the changed
			// bracket isn't the one currently on screen.
			var changedBid = parseInt(bids[0]);
			var pill = document.querySelector('.tn-bk-pill[data-bid="' + changedBid + '"]');
			// Read the pill label WITHOUT the #36 presence badge that may be appended to it.
			var bname = 'Bracket ' + changedBid;
			if (pill) {
				var _pc = pill.cloneNode(true);
				var _pb = _pc.querySelector('.tn-presence-badge');
				if (_pb) _pb.parentNode.removeChild(_pb);
				bname = _pc.textContent.trim();
			}
			var inp = document.getElementById('tn-bv-bracket-select');
			var curBid = inp ? parseInt(inp.value) : 0;
			var offscreen = (bids.length === 1 && changedBid && changedBid !== curBid);
			var msg = (lastActor ? (lastActor + ' updated ') : 'Updated ') + bname + (offscreen ? ' — tap to view' : '');
			// #26: only offer the jump affordance when there is actually a pill/tab to
			// jump to — a bracket just seeded from a peer has no UI target yet.
			var opts = (offscreen && pill && typeof window.tnGoToBracket === 'function')
				? { onClick: function() { window.tnGoToBracket(changedBid); } }
				: null;
			window.tnToast(msg, 3200, opts);
		});
	}

	function poll() {
		// Flush any re-render deferred while the user had a quick-result bar open,
		// now that it's closed (see refetchBracket / #71).
		if (window._tnPendingRerenderBid && !(window.tnQrEntryOpen && window.tnQrEntryOpen())) {
			var pend = window._tnPendingRerenderBid;
			window._tnPendingRerenderBid = null;
			var psel = document.getElementById('tn-bv-bracket-select');
			var pcur = psel ? parseInt(psel.value) : 0;
			if (parseInt(pend) === pcur && typeof tnRenderBracketViz === 'function') tnRenderBracketViz(pend);
		}
		if (paused || inFlight) return;   // skip this tick if a cycle is still in flight
		inFlight = true;
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/seq')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				// #27: a response arrived — the heartbeat is alive again.
				seqFailures = 0; setSyncState(true);
				if (!d || d.status !== 0) return;
				if (clientSeq === null) { clientSeq = d.seq; return; }
				// Self-heal: server reset/lost its seq (memcache flush, restart) — it now
				// reports a value below our cursor. Rather than stall silently, resync.
				if (d.reset || d.seq < clientSeq) {
					return fullResync().then(function() { clientSeq = d.seq; });
				}
				if (d.seq > clientSeq) {
					return fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/changes&since=' + clientSeq)
						.then(function(r) { return r.json(); })
						.then(function(cd) { if (cd && cd.status === 0) return applyDeltas(cd); });
				}
			})
			.catch(function(err) {
				console.warn('[tn-collab] seq poll failed', err);
				// #27: count consecutive failures; surface "Reconnecting…" once past the
				// threshold so a stalled heartbeat isn't invisible.
				seqFailures++;
				if (seqFailures >= RECONNECT_AFTER) setSyncState(false);
			})
			.finally(function() { inFlight = false; });
	}

	function schedule() {
		if (timer) clearTimeout(timer);
		if (paused) return;
		var interval = (Date.now() < nudgeUntil) ? 750 : (anyActive() ? 1000 : 5000);
		timer = setTimeout(function() { poll(); schedule(); }, interval);
	}

	// Other client code calls this after a local edit to poll faster briefly.
	window.tnCollabNudge = function() { nudgeUntil = Date.now() + 4000; schedule(); };
	// Allow optimistic handlers to keep our cursor ahead of our own writes.
	// Every seq-returning local write (results, resets, ironman wins, point scores)
	// lands here, so it's also where the standings become stale.
	window.tnCollabBumpSeq = function(seq) {
		if (window.tnMarkStandingsDirty) window.tnMarkStandingsDirty();
		if (typeof seq === 'number' && seq > (clientSeq || 0)) clientSeq = seq;
	};

	document.addEventListener('visibilitychange', function() {
		if (document.hidden) { paused = true; if (timer) { clearTimeout(timer); timer = null; } }
		else { paused = false; poll(); schedule(); }
	});

	// Seed cursor, then start.
	fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/seq')
		.then(function(r) { return r.json(); })
		.then(function(d) { if (d && d.status === 0) clientSeq = d.seq; })
		.catch(function() {})
		.finally(function() { schedule(); });
})();


// ============================================================
// #36 — Reeve presence ("others here" badges)
// Reeve-only. On an ~8s heartbeat AND on bracket switch, POST the current selected
// bracket to /presence and render a subtle FA5 eye-icon "+N" badge of the OTHER
// reeves on each bracket switcher pill and the mobile deck header. Self is excluded
// via TnConfig.currentUserId; when own id is unknown we badge only 2+ present.
// ============================================================
(function() {
	if (TnConfig.spectator || !TnConfig.loggedIn) return;
	if (!(TnConfig.canManage || TnConfig.isOrganizerReeve || TnConfig.isBracketRunner)) return;

	var HEARTBEAT_MS = 8000;
	var selfId = parseInt(TnConfig.currentUserId, 10) || 0;
	var timer = null, paused = document.hidden;   // a background tab starts paused
	var lastPresence = [];

	function currentBid() {
		var sel = document.getElementById('tn-bv-bracket-select');
		return sel ? (parseInt(sel.value, 10) || 0) : 0;
	}

	// Group presence by bracket id, tracking total present + others (excluding self).
	function othersByBracket(presence) {
		var byBid = {};
		(presence || []).forEach(function(p) {
			var bid = parseInt(p.BracketId, 10) || 0;
			if (!bid) return;
			if (!byBid[bid]) byBid[bid] = { total: 0, others: 0, names: [] };
			byBid[bid].total++;
			if (selfId > 0 && parseInt(p.ReeveId, 10) === selfId) return;
			byBid[bid].others++;
			byBid[bid].names.push(p.Name || 'Reeve');
		});
		return byBid;
	}

	// How many to show: exact "others" when we know our own id, else only badge a
	// crowd of 2+ (we can't tell which entry is us). Never badge 0.
	function badgeCount(info) {
		if (!info) return 0;
		return selfId > 0 ? info.others : (info.total >= 2 ? info.total : 0);
	}

	function renderBadge(host, info) {
		if (!host) return;
		var existing = host.querySelector('.tn-presence-badge');
		var n = badgeCount(info);
		if (n <= 0) { if (existing) existing.parentNode.removeChild(existing); return; }
		var names = (info && info.names && info.names.length) ? info.names.join(', ') : '';
		var tip = n + ' other reeve' + (n === 1 ? '' : 's') + ' here' + (names ? ': ' + names : '');
		if (!existing) {
			existing = document.createElement('span');
			existing.className = 'tn-presence-badge';
			host.appendChild(existing);
		}
		existing.innerHTML = '<i class="fas fa-eye"></i>+' + n;
		existing.setAttribute('data-tip', tip);
	}

	function render() {
		var byBid = othersByBracket(lastPresence);
		// Bracket switcher pills (Run Tournament tab).
		var pills = document.querySelectorAll('#tn-tab-bracketviz .tn-bk-pill[data-bid]');
		Array.prototype.forEach.call(pills, function(pill) {
			var bid = parseInt(pill.getAttribute('data-bid'), 10) || 0;
			renderBadge(pill, byBid[bid]);
		});
		// Mobile deck header for the active bracket (the deck is mobile-only).
		if (window.TnMobile && TnMobile.isMobile()) {
			var deckHeader = document.querySelector('#tn-nextup .tn-nu-header');
			renderBadge(deckHeader, byBid[currentBid()]);
		}
	}

	function heartbeat() {
		var fd = new FormData();
		fd.append('BracketId', currentBid());
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/presence', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(d) { if (d && d.status === 0) { lastPresence = d.presence || []; render(); } })
			.catch(function() { /* presence is best-effort — silent on failure */ });
	}

	function schedule() {
		if (timer) clearTimeout(timer);
		if (paused) return;
		timer = setTimeout(function() { heartbeat(); schedule(); }, HEARTBEAT_MS);
	}

	// Re-POST immediately when the reeve switches brackets via the pills, so the
	// server sees the new bracket and peers' badges update without an 8s wait.
	document.addEventListener('click', function(ev) {
		var pill = ev.target.closest && ev.target.closest('#tn-tab-bracketviz .tn-bk-pill[data-bid]');
		if (pill) setTimeout(heartbeat, 0);
	});
	// Re-apply badges after a view-mode flip (the deck header is rebuilt).
	var root = document.getElementById('tn-root');
	if (root) root.addEventListener('tn:viewmodechange', function() { setTimeout(render, 0); });

	document.addEventListener('visibilitychange', function() {
		if (document.hidden) { paused = true; if (timer) { clearTimeout(timer); timer = null; } }
		else { paused = false; heartbeat(); schedule(); }
	});

	if (!paused) heartbeat();
	schedule();
})();


// ============================================================
// Feature 2 — Reeves Panel (Add / Remove)
// ============================================================
(function() {
	if (!TnConfig.canManageReeves) return;

	var OVERLAY     = 'tn-addreeve-overlay';
	var addBtn      = document.getElementById('tn-reeve-add-btn');
	var listEl      = document.getElementById('tn-reeves-list');
	var playerInput = document.getElementById('tn-addreeve-player-text');
	var playerIdEl  = document.getElementById('tn-addreeve-player-id');
	var roleSel     = document.getElementById('tn-addreeve-role');
	var resultsEl   = document.getElementById('tn-addreeve-results');
	var submitBtn   = document.getElementById('tn-addreeve-submit');
	var searchTimer = null;

	var ROLE_LABELS = { organizer: 'Organizer', bracket_runner: 'Bracket Runner' };

	function acClose() {
		if (!resultsEl) return;
		resultsEl.classList.remove('kn-ac-open');
		resultsEl.innerHTML = '';
	}

	function syncSubmitState() {
		if (submitBtn) submitBtn.disabled = !(parseInt(playerIdEl.value) > 0);
	}

	// Keep the fixed dropdown off the modal footer so a Cancel/Add click can never
	// land on a result row: cap it above the footer, or flip it above the input
	// when there is too little room below.
	function acPlace() {
		tnFixedAcPosition(playerInput, resultsEl);
		var ov = document.getElementById(OVERLAY);
		var footer = ov ? ov.querySelector('.tn-modal-footer') : null;
		if (!footer) return;
		var r = playerInput.getBoundingClientRect();
		var below = footer.getBoundingClientRect().top - (r.bottom + 4) - 6;
		if (below >= 120) {
			resultsEl.style.maxHeight = Math.min(220, below) + 'px';
			return;
		}
		resultsEl.style.maxHeight = Math.max(80, Math.min(220, r.top - 12)) + 'px';
		resultsEl.style.top = Math.max(4, r.top - 4 - resultsEl.offsetHeight) + 'px';
	}
	// Let tnFixedAcPosition's scroll/resize re-anchor reuse this placement.
	if (resultsEl) resultsEl._tnAcPlace = acPlace;

	function acRender(players) {
		resultsEl.innerHTML = '';
		if (!players || !players.length) {
			resultsEl.innerHTML = '<div class="kn-ac-item kn-ac-empty">No players found</div>';
			resultsEl.classList.add('kn-ac-open');
			acPlace();
			return;
		}
		players.forEach(function(pl) {
			var item = document.createElement('div');
			item.className = 'kn-ac-item';
			item.tabIndex = -1;
			var label = tnEsc(pl.Persona || pl.Name || '');
			var sub   = pl.KAbbr ? (' <span style="color:#a0aec0;font-size:11px">(' + tnEsc(pl.KAbbr) + (pl.PAbbr ? ':' + tnEsc(pl.PAbbr) : '') + ')</span>') : '';
			item.innerHTML = label + sub;
			item.addEventListener('mousedown', function(e) {
				e.preventDefault();
				playerInput.value = pl.Persona || pl.Name || '';
				playerIdEl.value  = pl.MundaneId || pl.mundane_id || 0;
				syncSubmitState();
				acClose();
			});
			resultsEl.appendChild(item);
		});
		resultsEl.classList.add('kn-ac-open');
		acPlace();
	}

	// Merge own + exclude scope, dedupe by MundaneId (project player-search rule)
	function search(term) {
		if (!(TnConfig.searchKingdomId > 0)) { acClose(); return; }
		var base = TnConfig.uir + 'KingdomAjax/playersearch/' + TnConfig.searchKingdomId;
		Promise.all([
			fetch(base + '&scope=own&q='     + encodeURIComponent(term)).then(function(r){ return r.json(); }).catch(function(){ return []; }),
			fetch(base + '&scope=exclude&q=' + encodeURIComponent(term)).then(function(r){ return r.json(); }).catch(function(){ return []; })
		]).then(function(res) {
			var seen = {}, merged = [];
			[].concat(res[0] || [], res[1] || []).forEach(function(pl) {
				var mid = pl.MundaneId || pl.mundane_id || 0;
				if (mid && !seen[mid]) { seen[mid] = true; merged.push(pl); }
			});
			acRender(merged);
		}).catch(function(err) { console.warn('[tn-reeve] search failed', err); acClose(); });
	}

	if (playerInput) {
		playerInput.addEventListener('input', function() {
			var term = this.value.trim();
			playerIdEl.value = '0';
			syncSubmitState();
			clearTimeout(searchTimer);
			if (term.length < 2) { acClose(); return; }
			searchTimer = setTimeout(function() { search(term); }, 280);
		});
		playerInput.addEventListener('blur', function() { setTimeout(acClose, 200); });
		playerInput.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && resultsEl && resultsEl.classList.contains('kn-ac-open')) {
				e.stopPropagation();
				acClose();
			}
		});
		// Close on any pointerdown outside the input/dropdown before the click lands.
		document.addEventListener('pointerdown', function(e) {
			if (!resultsEl || !resultsEl.classList.contains('kn-ac-open')) return;
			if (resultsEl.contains(e.target) || e.target === playerInput) return;
			acClose();
		}, true);
	}

	function resetForm() {
		if (playerInput) playerInput.value = '';
		if (playerIdEl)  playerIdEl.value = '0';
		if (roleSel)     roleSel.value = 'bracket_runner';
		tnHideFeedback('tn-addreeve-feedback');
		acClose();
		syncSubmitState();
	}

	if (addBtn) addBtn.addEventListener('click', function() {
		resetForm();
		tnOpenModal(OVERLAY);
		setTimeout(function() { if (playerInput) playerInput.focus(); }, 60);
	});
	['tn-addreeve-close','tn-addreeve-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	function renderReeveRow(mid, persona, role) {
		var li = document.createElement('li');
		li.className = 'tn-reeve-row';
		li.dataset.mundaneId = mid;
		var roleLabel = ROLE_LABELS[role] || role;
		li.innerHTML =
			'<span class="tn-reeve-persona"><a href="' + TnConfig.uir + 'Player/profile/' + mid + '">' + tnEsc(persona || ('#' + mid)) + '</a></span>'
			+ '<span class="tn-reeve-badge tn-reeve-badge-' + role + '">' + tnEsc(roleLabel) + '</span>'
			+ '<button type="button" class="tn-reeve-remove" data-mundane-id="' + mid + '" data-persona="' + tnEsc(persona || '') + '" data-tip="Remove reeve" aria-label="' + tnEsc('Remove reeve ' + (persona || ('#' + mid))) + '"><i class="fas fa-times"></i></button>';
		return li;
	}

	function upsertReeve(mid, persona, role) {
		var empty = document.getElementById('tn-reeves-empty');
		if (empty) empty.remove();
		var existing = listEl.querySelector('.tn-reeve-row[data-mundane-id="' + mid + '"]');
		var row = renderReeveRow(mid, persona, role);
		if (existing) existing.replaceWith(row);
		else listEl.appendChild(row);
	}

	if (submitBtn) submitBtn.addEventListener('click', function() {
		var mid  = parseInt(playerIdEl.value) || 0;
		var role = roleSel ? roleSel.value : 'bracket_runner';
		var persona = playerInput ? playerInput.value.trim() : '';
		if (mid <= 0) { tnShowFeedback('tn-addreeve-feedback', 'Please select a player.', false); return; }
		submitBtn.disabled = true;
		var fd = new FormData();
		fd.append('MundaneId', mid);
		fd.append('Role', role);
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/addreeve', { method:'POST', body:fd })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				submitBtn.disabled = false;
				if (d && d.status === 0) {
					upsertReeve(mid, persona, d.role || role);
					tnCloseModal(OVERLAY);
				} else {
					tnShowFeedback('tn-addreeve-feedback', (d && d.error) ? d.error : 'Could not add reeve.', false);
				}
			})
			.catch(function() { submitBtn.disabled = false; tnShowFeedback('tn-addreeve-feedback', 'Request failed.', false); });
	});

	// Inline-confirm remove via event delegation on the list.
	if (listEl) listEl.addEventListener('click', function(e) {
		var btn = e.target.closest ? e.target.closest('.tn-reeve-remove') : null;
		if (!btn) return;
		var mid = parseInt(btn.dataset.mundaneId) || 0;
		if (!btn.classList.contains('tn-reeve-confirm')) {
			btn.classList.add('tn-reeve-confirm');
			btn.innerHTML = 'Remove?';
			var revert = setTimeout(function() {
				btn.classList.remove('tn-reeve-confirm');
				btn.innerHTML = '<i class="fas fa-times"></i>';
			}, 3000);
			btn._tnRevert = revert;
			return;
		}
		if (btn._tnRevert) clearTimeout(btn._tnRevert);
		btn.disabled = true;
		var fd = new FormData();
		fd.append('MundaneId', mid);
		fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/removereeve', { method:'POST', body:fd })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d && d.status === 0) {
					// Removed yourself: your manage rights may be gone, so reload to
					// drop the Edit / Add Bracket / Add Reeve controls.
					var selfId = parseInt(TnConfig.currentUserId, 10) || 0;
					if (selfId && mid === selfId) { window.location.reload(); return; }
					var row = btn.closest('.tn-reeve-row');
					if (row) row.remove();
					if (listEl && !listEl.querySelector('.tn-reeve-row')) {
						var li = document.createElement('li');
						li.className = 'tn-reeves-empty';
						li.id = 'tn-reeves-empty';
						li.textContent = 'No reeves assigned yet.';
						listEl.appendChild(li);
					}
				} else {
					btn.disabled = false;
					btn.classList.remove('tn-reeve-confirm');
					btn.innerHTML = '<i class="fas fa-times"></i>';
					window.tnToast((d && d.error) ? d.error : 'Could not remove reeve.');
				}
			})
			.catch(function() {
				btn.disabled = false;
				btn.classList.remove('tn-reeve-confirm');
				btn.innerHTML = '<i class="fas fa-times"></i>';
				window.tnToast('Request failed.');
			});
	});
})();


// ============================================================
// Feature 3 — Standings "Recommend for…" modal
// ============================================================
(function() {
	if (!TnConfig.canRecommend) return;

	var OVERLAY  = 'tn-rec-overlay';
	var AWARD_NAMES = { 27: 'Order of the Warrior', 33: 'Order of the Griffin' };
	var MAX_RANK = 10;
	var form        = document.getElementById('tn-rec-form');
	var personaEl   = document.getElementById('tn-rec-persona');
	var awardNameEl = document.getElementById('tn-rec-award-name');
	var awardIdEl   = document.getElementById('tn-rec-award-id');
	var rankPills   = document.getElementById('tn-rec-rank-pills');
	var rankVal     = document.getElementById('tn-rec-rank-val');
	var reasonEl    = document.getElementById('tn-rec-reason');
	var charCount   = document.getElementById('tn-rec-char-count');
	var submitBtn   = document.getElementById('tn-rec-submit');
	var standingEl  = document.getElementById('tn-rec-standing');
	var recMundaneId = 0;
	var recOpenSeq   = 0;
	// Last ladder-standing lookup, so the helper text can follow the selected pill.
	var recStanding  = null;

	function standingText(rank) {
		if (!recStanding) return '';
		return recStanding.current > 0
			? ('Currently rank ' + recStanding.current + ' of ' + recStanding.maxRank + ' \u2014 recommending for rank ' + rank + '.')
			: ('No rank held yet \u2014 recommending for rank ' + rank + '.');
	}

	function buildRankPills(maxRank, selectRank) {
		if (!rankPills) return;
		maxRank = parseInt(maxRank) || MAX_RANK;
		rankPills.innerHTML = '';
		rankVal.value = '';
		for (var r = 1; r <= maxRank; r++) {
			// Real buttons: focusable, and Enter/Space fire the click handler natively.
			var pill = document.createElement('button');
			pill.type = 'button';
			pill.className = 'tn-rank-pill';
			pill.textContent = r;
			pill.dataset.rank = r;
			pill.setAttribute('aria-label', 'Rank ' + r);
			pill.setAttribute('aria-pressed', 'false');
			if (selectRank && r === selectRank) {
				pill.classList.add('tn-rank-selected');
				pill.setAttribute('aria-pressed', 'true');
				rankVal.value = r;
			}
			rankPills.appendChild(pill);
		}
	}

	if (rankPills) rankPills.addEventListener('click', function(e) {
		var p = e.target.closest ? e.target.closest('.tn-rank-pill') : null;
		if (!p) return;
		rankPills.querySelectorAll('.tn-rank-pill').forEach(function(x) { x.classList.remove('tn-rank-selected'); x.setAttribute('aria-pressed', 'false'); });
		p.classList.add('tn-rank-selected');
		p.setAttribute('aria-pressed', 'true');
		rankVal.value = p.dataset.rank;
		if (standingEl && recStanding) standingEl.textContent = standingText(p.dataset.rank);
	});

	if (reasonEl && charCount) reasonEl.addEventListener('input', function() {
		var remaining = 400 - this.value.length;
		charCount.textContent = remaining + ' character' + (remaining !== 1 ? 's' : '') + ' remaining';
		charCount.classList.toggle('tn-char-warn', remaining < 50);
	});

	// Global entry point used by server-rendered standings rows + delegated triggers.
	window.tnOpenRecModal = function(mundaneId, persona, awardId) {
		mundaneId = parseInt(mundaneId) || 0;
		awardId   = parseInt(awardId) || 0;
		if (mundaneId <= 0 || !awardId) return;
		tnHideFeedback('tn-rec-feedback');
		recMundaneId = mundaneId;
		recStanding  = null;
		recOpenSeq++;
		if (awardIdEl)   awardIdEl.value = awardId;
		if (personaEl)   personaEl.textContent = persona || ('Player #' + mundaneId);
		if (awardNameEl) awardNameEl.textContent = AWARD_NAMES[awardId] || ('Award #' + awardId);
		if (reasonEl)  reasonEl.value = '';
		if (charCount) { charCount.textContent = '400 characters remaining'; charCount.classList.remove('tn-char-warn'); }
		if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Recommendation'; }
		// Default pills until the standing lookup returns; none preselected yet.
		buildRankPills(MAX_RANK, 0);
		if (standingEl) { standingEl.textContent = 'Checking current standing\u2026'; standingEl.className = 'tn-rec-standing'; }
		tnOpenModal(OVERLAY);
		setTimeout(function() { if (reasonEl) reasonEl.focus(); }, 60);

		// Look up the recipient's current ladder rank and pre-select the nearest one up.
		var who = persona || ('Player #' + mundaneId);
		var openSeq = recOpenSeq;
		fetch(TnConfig.uir + 'PlayerAjax/ladderstanding&MundaneId=' + mundaneId + '&AwardId=' + awardId, { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (openSeq !== recOpenSeq) return; // modal reopened for someone else
				if (!d || d.status !== 0) { if (standingEl) standingEl.textContent = ''; return; }
				var maxRank = parseInt(d.MaxRank) || MAX_RANK;
				var current = parseInt(d.CurrentRank) || 0;
				var next    = parseInt(d.NextRank) || 1;
				if (d.ToppedOut) {
					buildRankPills(maxRank, 0);
					if (standingEl) {
						standingEl.textContent = who + ' has already reached the top of ' + (d.LadderName || 'this ladder') + '.';
						standingEl.className = 'tn-rec-standing tn-rec-standing-topped';
					}
					if (submitBtn) submitBtn.disabled = true;
					return;
				}
				buildRankPills(maxRank, next);
				recStanding = { current: current, maxRank: maxRank };
				if (standingEl) {
					standingEl.textContent = standingText(next);
					standingEl.className = 'tn-rec-standing';
				}
			})
			.catch(function() { if (openSeq === recOpenSeq && standingEl) standingEl.textContent = ''; });
	};

	['tn-rec-close','tn-rec-cancel'].forEach(function(id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', function() { tnCloseModal(OVERLAY); });
	});
	var ov = document.getElementById(OVERLAY);
	if (ov) ov.addEventListener('click', function(e) { if (e.target === ov) tnCloseModal(OVERLAY); });

	function resetSubmitBtn() {
		if (!submitBtn) return;
		submitBtn.disabled = false;
		submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Recommendation';
	}

	// POST to the shared AJAX add-recommendation action (same endpoint the Kingdom
	// profile uses) so the organizer stays on Standings. The kingdom segment only
	// scopes the route; the award resolves against the recipient's own kingdom.
	function submitRec() {
		if (!submitBtn || submitBtn.disabled) return;
		var reason = reasonEl ? reasonEl.value.trim() : '';
		if (!reason) { tnShowFeedback('tn-rec-feedback', 'Please provide a reason.', false); return; }
		var kid = parseInt(TnConfig.searchKingdomId || TnConfig.kingdomId) || 0;
		if (!recMundaneId || !kid) { tnShowFeedback('tn-rec-feedback', 'Could not determine the kingdom for this recommendation.', false); return; }
		tnHideFeedback('tn-rec-feedback');
		var fd = new FormData();
		fd.append('MundaneId', recMundaneId);
		fd.append('AwardId',   awardIdEl ? awardIdEl.value : '');
		fd.append('Reason',    reason);
		if (rankVal && rankVal.value) fd.append('Rank', rankVal.value);
		var seq = recOpenSeq;
		submitBtn.disabled = true;
		submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
		fetch(TnConfig.uir + 'KingdomAjax/kingdom/' + kid + '/addrecommendation', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d && d.status === 0) {
					tnShowFeedback('tn-rec-feedback', 'Recommendation submitted!', true);
					setTimeout(function() {
						if (seq !== recOpenSeq) return;
						var ovNow = document.getElementById(OVERLAY);
						if (ovNow && ovNow.classList.contains('tn-open')) tnCloseModal(OVERLAY);
						resetSubmitBtn();
					}, 1200);
				} else {
					tnShowFeedback('tn-rec-feedback', (d && d.error) ? d.error : 'Could not submit the recommendation.', false);
					resetSubmitBtn();
				}
			})
			.catch(function() {
				tnShowFeedback('tn-rec-feedback', 'Request failed. Please try again.', false);
				resetSubmitBtn();
			});
	}

	if (submitBtn) submitBtn.addEventListener('click', submitRec);
	// Enter in the single Reason input would natively submit the form — route it through fetch.
	if (form) form.addEventListener('submit', function(e) { e.preventDefault(); submitRec(); });

	// Delegated trigger for JS-rendered leaderboard buttons.
	document.addEventListener('click', function(e) {
		var btn = e.target.closest ? e.target.closest('.tn-rec-trigger') : null;
		if (!btn) return;
		tnOpenRecModal(btn.dataset.mundaneId, btn.dataset.persona, btn.dataset.award);
	});
})();

// =============================================
// Task 10 — Points-bracket Fixed-mode pip click + auto-save
// Delegated click handler for .tn-pip elements. POSTs to
// TournamentAjax/tournament/{tid}/savepointscore, then updates
// the Total column and standings ribbon live from the response.
// Exposes window.tnPointsPostSave and window.tnPointsRenderStandings
// for reuse by Task 11 (Open-mode) and Task 12 (Add Round).
// =============================================
(function(){
	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function(c){
			return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' })[c];
		});
	}

	function setCellStatus(cell, cls) {
		var s = cell.querySelector('.tn-points-status');
		if (!s) return;
		s.className = 'tn-points-status' + (cls ? ' ' + cls : '');
	}

	function renderStandings(bid, standings, cellEl) {
		// Scope to the active bracket viewer container; the per-bracket card
		// in tn-bracket-body also renders a (currently-hidden) grid with the
		// same data-bid, and updates must not land on that copy.
		var wrap = (cellEl && cellEl.closest('.tn-points-wrap'))
		        || document.querySelector('#tn-bv-container .tn-points-wrap[data-bid="' + bid + '"]')
		        || document.querySelector('.tn-points-wrap[data-bid="' + bid + '"]');
		if (!wrap) return;
		var ribbon = wrap.querySelector('.tn-points-ribbon');
		if (ribbon) {
			var html = '';
			var i = 0;
			// Until a score is entered every row sits at 0.00 — show the empty state, not a tie.
			var anyScored = standings.some(function(r) {
				return (r.RoundScores || []).some(function(v) { return v != null && v !== ''; });
			});
			for (var k = 0; anyScored && k < standings.length && i < 5; k++) {
				var row = standings[k];
				if (row.Status !== 'active' && row.Status !== '') continue;
				html += '<span class="tn-points-rib-item"><strong>' +
					(row.Tied ? 'T-' : '') + (row.Place == null ? '' : row.Place) + '</strong> ' +
					escapeHtml(row.Alias) + ' (' + escapeHtml(row.Total) + ')</span>';
				i++;
			}
			if (i === 0) html = '<span style="color:#a0aec0;font-size:13px">No scores yet.</span>';
			ribbon.innerHTML = html;
		}
		// Update each row's Total column
		standings.forEach(function(row){
			var tr = wrap.querySelector('tr[data-pid="' + row.ParticipantId + '"]');
			if (!tr) return;
			var tot = tr.querySelector('.tn-points-col-total');
			if (tot) tot.textContent = row.Total;
		});
	}

	function postSave(bid, pid, round, value, cellEl) {
		setCellStatus(cellEl, 'tn-saving');
		var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
		if (window.tnRegisterAction) window.tnRegisterAction(actionId);
		if (window.tnCollabNudge) window.tnCollabNudge();
		var fd = new FormData();
		fd.append('BracketId', bid);
		fd.append('ParticipantId', pid);
		fd.append('Round', round);
		if (value !== null && value !== undefined) fd.append('Points', value);
		fd.append('ActionId', actionId);

		var url = TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/savepointscore';
		fetch(url, { method:'POST', body:fd, credentials:'same-origin' })
			.then(function(r){ return r.json(); })
			.then(function(j){
				// #31: release the in-flight guard now that the request resolved.
				cellEl.dataset.tnSaving = ''; cellEl.style.pointerEvents = '';
				if (j.status !== 0) throw new Error(j.error || 'Save failed');
				if (typeof j.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(j.seq);
				var pts = (j.detail && j.detail.Cell) ? j.detail.Cell.Points : null;
				cellEl.dataset.value = (pts === null || pts === undefined) ? '' : pts;
				setCellStatus(cellEl, 'tn-saved');
				setTimeout(function(){
					var s = cellEl.querySelector('.tn-points-status');
					if (s && s.classList.contains('tn-saved')) s.className = 'tn-points-status';
				}, 800);
				if (j.detail && j.detail.Standings) {
					renderStandings(bid, j.detail.Standings, cellEl);
					// Keep the cached standings current so any later re-render of the
					// Run view (e.g. after Finalize) shows the saved scores.
					if (TnConfig.bracketData && TnConfig.bracketData[bid]) TnConfig.bracketData[bid].PointStandings = j.detail.Standings;
				}
				// Brackets-tab card grid/totals + standings; the hook is debounced, so a
				// run of pip clicks costs one page fetch.
				if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bid);
			})
			.catch(function(e){
				// #31: release the in-flight guard on failure too, so the cell stays usable.
				cellEl.dataset.tnSaving = ''; cellEl.style.pointerEvents = '';
				// Roll the optimistic pip selection back to the last saved value.
				var _saved = cellEl.dataset.value || '';
				cellEl.querySelectorAll('.tn-pip').forEach(function(s){
					s.classList.toggle('tn-pip-selected', _saved !== '' && parseFloat(s.dataset.val) === parseFloat(_saved));
				});
				setCellStatus(cellEl, 'tn-error');
				var s = cellEl.querySelector('.tn-points-status');
				if (s) s.setAttribute('data-tip', String(e.message || e));
				console.log('[points] save failed', e);
			});
	}

	document.addEventListener('click', function(ev){
		var pip = ev.target.closest('.tn-pip:not(.tn-pip-preview)');
		if (!pip) return;
		var cell = pip.closest('.tn-points-cell');
		if (!cell) return;
		var wrap = cell.closest('.tn-points-wrap');
		if (!wrap) return;
		// Only fixed-mode cells have pips, but guard anyway
		if (wrap.dataset.mode !== 'fixed') return;
		// A finalized or setup (not yet generated) points bracket is read-only.
		var _pbd = TnConfig.bracketData && TnConfig.bracketData[wrap.dataset.bid];
		if (_pbd && _pbd.Bracket && (_pbd.Bracket.Status === 'finalized' || (_pbd.Bracket.Status || 'setup') === 'setup')) return;
		// #31: in-flight guard — ignore rapid re-clicks on a cell whose save is still
		// pending so responses can't resolve out of order and clobber a newer value.
		if (cell.dataset.tnSaving === '1') return;

		var bid    = wrap.dataset.bid;
		var pid    = cell.dataset.pid;
		var round  = cell.dataset.round;
		var clickedVal  = pip.dataset.val;
		var currentVal  = cell.dataset.value || '';
		var willClear   = (currentVal !== '' && parseFloat(currentVal) === parseFloat(clickedVal));

		// Optimistic UI: update selection immediately
		cell.querySelectorAll('.tn-pip').forEach(function(s){ s.classList.remove('tn-pip-selected'); });
		if (!willClear) pip.classList.add('tn-pip-selected');

		// #31: mark the cell in-flight (cleared by postSave on resolve) and freeze
		// further pip clicks on it until the save completes.
		cell.dataset.tnSaving = '1';
		cell.style.pointerEvents = 'none';
		postSave(bid, pid, round, willClear ? null : clickedVal, cell);
	});

	// Expose helpers for Task 11 (open-mode input) and Task 12 (Add Round)
	window.tnPointsPostSave = postSave;
	window.tnPointsRenderStandings = renderStandings;
})();

// =============================================
// Task 11 -- Points-bracket Open-mode input + auto-save
// Commits the cell value on blur/Enter; validates non-negative decimal
// (<= 2 dp, <= 999.99); flashes red border + reverts on invalid input.
// Reuses window.tnPointsPostSave from Task 10.
// =============================================
(function(){
	function validateOpen(v) {
		if (v === '' || v === null) return { ok:true, value:null };
		if (!/^\d+(\.\d{1,2})?$/.test(v)) return { ok:false, error:'Use non-negative decimal (<=2 dp).' };
		var f = parseFloat(v);
		if (f < 0 || f > 999.99) return { ok:false, error:'Out of range (0-999.99).' };
		return { ok:true, value:f.toFixed(2) };
	}

	function commit(input) {
		var cell = input.closest('.tn-points-cell');
		if (!cell) return;
		var wrap = cell.closest('.tn-points-wrap');
		if (!wrap) return;
		var bid   = wrap.dataset.bid;
		var pid   = cell.dataset.pid;
		var round = cell.dataset.round;
		var raw   = (input.value || '').trim();
		var prev  = cell.dataset.value || '';

		var prevNum = (prev === '') ? null : parseFloat(prev);
		var rawNum  = (raw === '')  ? null : parseFloat(raw);
		if (prevNum === rawNum) return;
		if (prevNum === null && rawNum === null) return;

		var v = validateOpen(raw);
		if (!v.ok) {
			input.classList.add('tn-points-err');
			input.value = prev;
			// #55: surface WHY it was rejected via the cell's status tooltip (the same
			// affordance postSave uses for save errors), not just a silent red flash.
			var st = cell.querySelector('.tn-points-status');
			if (st) {
				st.className = 'tn-points-status tn-error';
				st.setAttribute('data-tip', v.error || 'Invalid value');
				setTimeout(function(){ st.className = 'tn-points-status'; st.removeAttribute('data-tip'); }, 2500);
			}
			setTimeout(function(){ input.classList.remove('tn-points-err'); }, 800);
			return;
		}
		input.classList.remove('tn-points-err');

		if (typeof window.tnPointsPostSave === 'function') {
			window.tnPointsPostSave(bid, pid, round, v.value, cell);
		} else {
			console.log('[points] tnPointsPostSave not available');
		}
	}

	document.addEventListener('blur', function(ev){
		var t = ev.target;
		if (t && t.classList && t.classList.contains('tn-points-input')) {
			commit(t);
		}
	}, true);

	document.addEventListener('keydown', function(ev){
		if (ev.key !== 'Enter') return;
		var t = ev.target;
		if (t && t.classList && t.classList.contains('tn-points-input')) {
			ev.preventDefault();
			t.blur();
		}
	});

	document.addEventListener('input', function(ev){
		var t = ev.target;
		if (!(t && t.classList && t.classList.contains('tn-points-input'))) return;
		var cleaned = (t.value || '').replace(/[^\d.]/g, '');
		var firstDot = cleaned.indexOf('.');
		if (firstDot !== -1) {
			cleaned = cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
		}
		if (cleaned !== t.value) t.value = cleaned;
	});
})();

// =============================================
// Task 12 — Points-bracket Add Round button
// POSTs addpointsround; on success appends a new round column header
// + a matching blank cell (pips or input) to every participant row,
// in place — no full re-render needed.
// =============================================
window.tnPointsAddRound = function(bid, srcBtn) {
	// Resolve the grid the '+' was clicked in (Run view or Brackets-tab card).
	var wrap = (srcBtn && srcBtn.closest && srcBtn.closest('.tn-points-wrap'))
	        || document.querySelector('#tn-bv-container .tn-points-wrap[data-bid="' + bid + '"]')
	        || document.querySelector('.tn-points-wrap[data-bid="' + bid + '"]');
	if (!wrap) return;
	var btn = wrap.querySelector('.tn-points-col-add button');
	if (btn) btn.disabled = true;

	var actionId = window.tnNewActionId ? window.tnNewActionId() : '';
	if (window.tnRegisterAction) window.tnRegisterAction(actionId);
	if (window.tnCollabNudge) window.tnCollabNudge();
	var fd = new FormData();
	fd.append('BracketId', bid);
	fd.append('ActionId', actionId);
	var url = TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/addpointsround';

	fetch(url, { method:'POST', body:fd, credentials:'same-origin' })
		.then(function(r){ return r.json(); })
		.then(function(j){
			if (j.status !== 0) throw new Error(j.error || 'Add round failed');
			if (typeof j.seq === 'number' && window.tnCollabBumpSeq) window.tnCollabBumpSeq(j.seq);
			var newRound = (j.detail && j.detail.PointRounds) ? j.detail.PointRounds : null;
			if (!newRound) return;

			wrap.dataset.rounds = newRound;
			var mode  = wrap.dataset.mode || 'fixed';
			var scale = String(wrap.dataset.scale || '').split(',')
				.map(function(s){ return s.trim(); })
				.filter(Boolean);

			var headRow = wrap.querySelector('thead tr');
			var addColTh = headRow.querySelector('.tn-points-col-add')
				|| headRow.querySelector('.tn-points-col-total');
			var newTh = document.createElement('th');
			newTh.className = 'tn-points-col-round';
			newTh.textContent = 'R' + newRound;
			headRow.insertBefore(newTh, addColTh);

			wrap.querySelectorAll('tbody tr').forEach(function(tr){
				var pid = tr.dataset.pid;
				if (!pid) return;
				var td = document.createElement('td');
				td.className = 'tn-points-cell';
				td.dataset.pid = pid;
				td.dataset.round = newRound;
				td.dataset.value = '';
				if (mode === 'fixed') {
					var pipsHtml = '<div class="tn-pips">' + scale.map(function(v){
						return '<span class="tn-pip" data-val="' + v + '">' + v + '</span>';
					}).join('') + '</div>';
					td.innerHTML = pipsHtml + '<span class="tn-points-status" aria-hidden="true"></span>';
				} else {
					td.innerHTML = '<input type="text" class="tn-points-input" inputmode="decimal" maxlength="5">'
						+ '<span class="tn-points-status" aria-hidden="true"></span>';
				}
				var addColTd = tr.querySelector('.tn-points-col-add')
					|| tr.querySelector('.tn-points-col-total');
				tr.insertBefore(td, addColTd);
			});
			// Bring the new round into view (it lands at the right edge, just
			// left of the sticky '+' / Total columns).
			var gridScroll = wrap.querySelector('.tn-points-grid-scroll');
			if (gridScroll) gridScroll.scrollLeft = gridScroll.scrollWidth;
			// The Brackets-tab card is about to be swapped for fresh markup
			// (scrollLeft 0): ask tnRefreshBracketCard to scroll it right again.
			if (!wrap.closest('#tn-bv-container')) {
				window._tnPtsScrollRight = window._tnPtsScrollRight || {};
				window._tnPtsScrollRight[bid] = true;
			}

			try {
				if (TnConfig.bracketData[bid] && TnConfig.bracketData[bid].Bracket) {
					TnConfig.bracketData[bid].Bracket.PointRounds = newRound;
				}
			} catch (e) { /* non-fatal */ }
			// Brackets-tab card grid gets the new round column too.
			if (window.tnAfterResultRecorded) window.tnAfterResultRecorded(bid);
		})
		.catch(function(e){
			console.log('[points] add round failed', e);
			window.tnToast('Could not add a round: ' + (e && e.message ? e.message : e));
		})
		.finally(function(){
			if (btn) btn.disabled = false;
		});
};

// Finalize an active points bracket (no final match ever completes one). Uses the
// same completebracket endpoint as the elimination waive paths; the refresh then
// brings the Run view (read-only grid), the Brackets-tab card and stats in step.
window.tnFinalizePointsBracket = function(bid) {
	bid = parseInt(bid, 10) || 0;
	if (!bid || !(TnConfig.canManage || TnConfig.isOrganizerReeve)) return;
	tnConfirm({
		title: 'Finalize bracket?',
		body: 'Finalizing locks scoring: no more rounds can be added and no scores can be changed. The current totals become the final standings.',
		confirmLabel: 'Finalize',
		onConfirm: function() {
			var fd = new FormData();
			fd.append('BracketId', bid);
			if (window.tnTagAction) window.tnTagAction(fd);
			fetch(TnConfig.uir + 'TournamentAjax/tournament/' + TnConfig.tournamentId + '/completebracket', { method:'POST', body:fd, credentials:'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					if (d.status !== 0) { window.tnToast('Error: ' + (d.error || 'Could not finalize bracket.')); return; }
					window.tnToast('Bracket finalized.');
					if (typeof window.tnRefreshAndRender === 'function') window.tnRefreshAndRender(bid);
					else if (window.tnRefreshBracketCard) window.tnRefreshBracketCard(bid);
				})
				.catch(function(err) { window.tnToast('Request failed: ' + err); });
		}
	});
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/tournament-quickbracket.js?v=<?= filemtime(__DIR__ . '/script/tournament-quickbracket.js') ?>"></script>
