<?php
/* ── Pre-compute scope ──────────────────────────────────────── */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';

if (($report_type ?? null) === 'Park') {
	$scope_label = str_replace(' Ladder Awards Grid', '', $page_title ?? '');
	$scope_link  = UIR . 'Park/profile/' . (int)($report_id ?? 0);
	$scope_icon  = 'fa-tree';
} elseif (($report_type ?? null) === 'Kingdom') {
	$scope_label = str_replace(' Ladder Awards Grid', '', $page_title ?? '');
	$scope_link  = UIR . 'Kingdom/profile/' . (int)($report_id ?? 0);
	$scope_icon  = 'fa-chess-rook';
}

$awardList  = is_array($LadderAwards) ? $LadderAwards : [];
$rows       = is_array($GridRows)     ? $GridRows     : [];
$isKingdom  = ($report_type ?? null) === 'Kingdom';

if ($isKingdom && !empty($rows)) {
	usort($rows, function ($a, $b) {
		$p = strcasecmp($a['ParkName'] ?? '', $b['ParkName'] ?? '');
		return $p !== 0 ? $p : strcasecmp($a['Persona'] ?? '', $b['Persona'] ?? '');
	});
}

// Sort awards: knighthood groups first, then ungrouped, alphabetically within each
$_groupOrder = ['Battle' => 0, 'Sword' => 1, 'Crown' => 2, 'Flame' => 3, 'Serpent' => 4, '' => 5];
uasort($awardList, function ($a, $b) use ($_groupOrder) {
	$ga = $_groupOrder[$a['KnightGroup'] ?? ''] ?? 5;
	$gb = $_groupOrder[$b['KnightGroup'] ?? ''] ?? 5;
	return $ga !== $gb ? $ga - $gb : strcasecmp($a['DisplayName'] ?? $a['Name'], $b['DisplayName'] ?? $b['Name']);
});
$awardIds = array_keys($awardList);

// Count columns per group for colspan on group header row
$_groupCounts = ['Battle' => 0, 'Sword' => 0, 'Crown' => 0, 'Flame' => 0, 'Serpent' => 0, '' => 0];
foreach ($awardList as $ainfo) {
	$g = $ainfo['KnightGroup'] ?? '';
	$_groupCounts[$g] = ($_groupCounts[$g] ?? 0) + 1;
}
$_groupLabels = [
	'Battle'  => 'Knight of Battle',
	'Sword'   => 'Knight of the Sword',
	'Crown'   => 'Knight of the Crown',
	'Flame'   => 'Knight of the Flame',
	'Serpent' => 'Knight of the Serpent',
];

$numPlayers = count($rows);
$numAwards  = count($awardList);

$numKnights = 0;
$numMasters = 0;
foreach ($rows as $r) {
	if (!empty($r['KnightGroups'])) $numKnights++;
	foreach ($r['Awards'] as $award) {
		if (!empty($award['IsMaster'])) { $numMasters++; break; }
	}
}
// Column indices: kingdom view has Persona(0), Park(1), Last Award(2), Last Sign-in(3), awards(4+)
//                park view has Persona(0), Last Award(1), Last Sign-in(2), awards(3+)
$awardColOffset = $isKingdom ? 4 : 3;

// Date cell: sorts on YYYYMMDD through data-order; a missing date is 0 (oldest) and shows a dash.
$renderAgeCell = function ($date, $label = '') {
	$date  = (string)($date ?? '');
	$text  = $date === '' ? '—' : trim($date . ' ' . $label);
	$order = (int)str_replace('-', '', substr($date, 0, 10));
	return '<td class="lg-col-date" data-order="' . $order . '">'
		. '<span class="lg-age" data-date="' . htmlspecialchars($date, ENT_QUOTES) . '">' . htmlspecialchars($text) . '</span>'
		. '</td>';
};

// One thead for both tables. $tipAttr names the award tooltip attribute: the main
// grid keeps its existing title; New Players cells are new, so they use data-tip.
$renderLadderThead = function ($tipAttr) use ($awardList, $isKingdom, $_groupCounts, $_groupLabels) {
?>
				<thead>
					<!-- Group label row -->
					<tr class="lg-group-header-row">
						<th class="lg-col-player" rowspan="2">Persona</th>
<?php if ($isKingdom) : ?>
						<th class="lg-col-park" rowspan="2">Park</th>
<?php endif; ?>
						<th class="lg-col-date" rowspan="2">Last Award</th>
						<th class="lg-col-date" rowspan="2">Last Sign-in</th>
<?php foreach (['Battle', 'Sword', 'Crown', 'Flame', 'Serpent', ''] as $_grp) : ?>
<?php   if (($_groupCounts[$_grp] ?? 0) > 0) : ?>
						<th colspan="<?= $_groupCounts[$_grp] ?>" class="lg-group-header-cell<?= $_grp ? ' lg-group-' . strtolower($_grp) : '' ?>">
							<?= $_grp ? htmlspecialchars($_groupLabels[$_grp]) : '' ?>
						</th>
<?php   endif; ?>
<?php endforeach; ?>
					</tr>
					<!-- Individual award columns -->
					<tr class="lg-header-row">
<?php foreach ($awardList as $aid => $ainfo) : ?>
<?php   $_gc = strtolower($ainfo['KnightGroup'] ?? ''); ?>
						<th class="lg-col-award<?= $_gc ? ' lg-group-' . $_gc : '' ?>" <?= $tipAttr ?>="<?= htmlspecialchars($ainfo['Name'], ENT_QUOTES) ?>">
							<div class="lg-th-inner"><?= htmlspecialchars($ainfo['DisplayName'] ?? $ainfo['Name']) ?></div>
						</th>
<?php endforeach; ?>
					</tr>
				</thead>
<?php
};

// LgGrid.awardColumns: JS orders integer-like keys numerically, so a keyed object
// cannot hold display order on its own. awardKeys below carries that order.
$_awardColumns = [];
foreach ($awardList as $_aid => $_ainfo) {
	$_awardColumns[$_aid] = [
		'Name'        => $_ainfo['Name'],
		'DisplayName' => $_ainfo['DisplayName'] ?? $_ainfo['Name'],
		'KnightGroup' => $_ainfo['KnightGroup'] ?? '',
	];
}
$_jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Smart Court needs court authority and at least one ladder column to click.
$_smartCourt = !empty($SmartCourt) && !empty($awardList);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
/* ── Grid-specific styles (layout handled by rp-* shared CSS) ── */

/* Rotating header cells */
.lg-table thead tr.lg-header-row { height: 90px; vertical-align: bottom; }

.lg-table th.lg-col-award {
	padding: 0; border: none;
	width: 36px; min-width: 36px; max-width: 36px;
	text-align: left; vertical-align: bottom;
	cursor: pointer;
}

.lg-th-inner {
	display: block;
	width: 90px;
	transform-origin: left bottom;
	transform: rotate(-45deg) translateX(18px);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	padding: 4px 6px 4px 2px;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--rp-text-body);
	transition: color 0.15s;
}

/* Suppress DataTables default sort arrows on rotated columns */
.lg-table th.lg-col-award.sorting::after,
.lg-table th.lg-col-award.sorting_asc::after,
.lg-table th.lg-col-award.sorting_desc::after { display: none !important; }

/* Highlight active sort column */
.lg-table th.lg-col-award.sorting_asc .lg-th-inner,
.lg-table th.lg-col-award.sorting_desc .lg-th-inner { color: var(--rp-accent); }

/* ── Knighthood group header row ─────────────────────────────── */
.lg-table thead tr.lg-group-header-row th {
	padding: 5px 6px;
	font-size: 0.68rem;
	font-weight: 700;
	text-align: center;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	border-bottom: 2px solid rgba(0,0,0,0.12);
	white-space: nowrap;
}
/* Group header spanning cells — override the 36px award column sizing */
.lg-group-header-cell {
	width: auto; min-width: unset; max-width: unset;
}

/* ── Knight group colour palette (belt trim colours) ─────────── */
/* Battle — blue trim */
th.lg-group-battle  { background: #dbeafe !important; color: #1e40af; }
th.lg-group-battle .lg-th-inner { color: #1e40af; }
.lg-group-header-row th.lg-group-battle { border-left: 3px solid #93c5fd; }
td.lg-group-battle  { background: #eff6ff !important; }
.lg-table tbody tr:nth-child(even) td.lg-group-battle { background: #e5f0fe !important; }

/* Sword — silver/grey trim */
th.lg-group-sword  { background: #e2e8f0 !important; color: #334155; }
th.lg-group-sword .lg-th-inner { color: #334155; }
.lg-group-header-row th.lg-group-sword { border-left: 3px solid #94a3b8; }
td.lg-group-sword  { background: #f8fafc !important; }
.lg-table tbody tr:nth-child(even) td.lg-group-sword { background: #f0f3f7 !important; }

/* Crown — gold trim */
th.lg-group-crown  { background: #fef9c3 !important; color: #713f12; }
th.lg-group-crown .lg-th-inner { color: #713f12; }
.lg-group-header-row th.lg-group-crown { border-left: 3px solid #fbbf24; }
td.lg-group-crown  { background: #fefce8 !important; }
.lg-table tbody tr:nth-child(even) td.lg-group-crown { background: #fdf8d4 !important; }

/* Flame — red trim */
th.lg-group-flame  { background: #fee2e2 !important; color: #991b1b; }
th.lg-group-flame .lg-th-inner { color: #991b1b; }
.lg-group-header-row th.lg-group-flame { border-left: 3px solid #fca5a5; }
td.lg-group-flame  { background: #fff5f5 !important; }
.lg-table tbody tr:nth-child(even) td.lg-group-flame { background: #fef0f0 !important; }

/* Serpent — green trim */
th.lg-group-serpent { background: #dcfce7 !important; color: #14532d; }
th.lg-group-serpent .lg-th-inner { color: #14532d; }
.lg-group-header-row th.lg-group-serpent { border-left: 3px solid #86efac; }
td.lg-group-serpent { background: #f0fdf5 !important; }
.lg-table tbody tr:nth-child(even) td.lg-group-serpent { background: #e6fbee !important; }

/* ── Knight highlight: player is a knight in that group ───────── */
/* Uses :nth-child(n) (matches every row) to reach (0,3,3) specificity —
   same level as the even-row group tint rules above, but declared after
   so the cascade gives these priority on even DOM rows too. */
.lg-table tbody tr:nth-child(n) td.lg-cell-knight-battle  { background: #93c5fd !important; }
.lg-table tbody tr:nth-child(n) td.lg-cell-knight-sword   { background: #cbd5e1 !important; }
.lg-table tbody tr:nth-child(n) td.lg-cell-knight-crown   { background: #fbbf24 !important; }
.lg-table tbody tr:nth-child(n) td.lg-cell-knight-flame   { background: #fca5a5 !important; }
.lg-table tbody tr:nth-child(n) td.lg-cell-knight-serpent { background: #86efac !important; }

/* Hover overrides — keep group tint, intensify knight highlight */
.lg-table tbody tr:hover td.lg-group-battle  { background: #dbeafe !important; }
.lg-table tbody tr:hover td.lg-group-sword   { background: #d8e4ef !important; }
.lg-table tbody tr:hover td.lg-group-crown   { background: #fdf4b8 !important; }
.lg-table tbody tr:hover td.lg-group-flame   { background: #fde0e0 !important; }
.lg-table tbody tr:hover td.lg-group-serpent { background: #d4f5e0 !important; }
.lg-table tbody tr:hover td.lg-cell-knight-battle  { background: #60a5fa !important; }
.lg-table tbody tr:hover td.lg-cell-knight-sword   { background: #94a3b8 !important; }
.lg-table tbody tr:hover td.lg-cell-knight-crown   { background: #f59e0b !important; }
.lg-table tbody tr:hover td.lg-cell-knight-flame   { background: #f87171 !important; }
.lg-table tbody tr:hover td.lg-cell-knight-serpent { background: #4ade80 !important; }

/* Keep DT sort arrow on the player and park columns */
.lg-table th.lg-col-player,
.lg-table th.lg-col-park { cursor: pointer; }

/* Sticky player column */
.lg-table th.lg-col-player,
.lg-table td.lg-col-player {
	position: sticky; left: 0; z-index: 2;
	background: #fff;
	min-width: 160px; max-width: 220px;
	text-align: left; padding: 7px 12px;
	border-right: 2px solid var(--rp-divider);
	white-space: normal; word-break: break-word;
}
.lg-table th.lg-col-player { z-index: 3; background: var(--rp-bg-light); vertical-align: middle; }
.lg-table th.lg-col-park   { background: var(--rp-bg-light); vertical-align: middle; }

/* Park column (kingdom view only) */
.lg-table th.lg-col-park,
.lg-table td.lg-col-park {
	min-width: 130px; max-width: 180px;
	text-align: left; padding: 7px 12px;
	border-right: 1px solid var(--rp-divider);
	white-space: normal; word-break: break-word;
	font-size: 0.85rem;
}
.lg-table th.lg-col-park { background: var(--rp-bg-light); }
.lg-table tbody tr:nth-child(even) td.lg-col-park { background: #f4f5f7; }
.lg-table tbody tr:hover td.lg-col-park { background: #eef2ff !important; }

/* Data cells */
/* separate + zero spacing: collapsed borders do not travel with sticky cells */
.lg-table { border-collapse: separate; border-spacing: 0; min-width: 100%; white-space: nowrap; }
/* collapse merged the Last Sign-in right rule with the first award's left rule; keep one line */
.lg-table td.lg-col-date + td.lg-col-award { border-left: 0; }

.lg-table tbody tr:nth-child(even) td           { background: #f9fafb; }
.lg-table tbody tr:nth-child(even) td.lg-col-player { background: #f4f5f7; }
.lg-table tbody tr:hover td                     { background: #eef2ff !important; }

/* Suspended players — tint the identity columns; award cells keep their group colors */
.lg-table tbody tr.rp-row-suspended td.lg-col-player,
.lg-table tbody tr.rp-row-suspended td.lg-col-park {
	background: var(--rp-row-suspended-bg, #fff5f5) !important;
}
.lg-table tbody tr.rp-row-suspended td.lg-col-player .lg-player-link,
.lg-table tbody tr.rp-row-suspended td.lg-col-park,
.lg-table tbody tr.rp-row-suspended td.lg-col-park .lg-player-link {
	color: var(--rp-row-suspended-text, #742a2a) !important;
}
.lg-table tbody tr.rp-row-suspended:hover td.lg-col-player,
.lg-table tbody tr.rp-row-suspended:hover td.lg-col-park {
	background: var(--rp-row-suspended-hover, #fed7d7) !important;
}

.lg-table td.lg-col-award {
	text-align: center; padding: 6px 4px;
	border-left: 1px solid #f0f0f0;
	font-size: 0.85rem;
	min-width: 36px; max-width: 36px; width: 36px;
}

.lg-cell-master { font-weight: 700; color: #7c3aed; font-size: 0.95rem; }
.lg-cell-rank   { color: var(--rp-text-body); }
.lg-cell-empty  { color: var(--rp-text-hint); font-size: 0.7rem; }

.lg-player-link {
	text-decoration: none;
	color: var(--rp-accent);
	font-size: 0.85rem;
}
.lg-player-link:hover { text-decoration: underline; color: var(--rp-accent-mid); }

/* Filter pills */
.lg-filter-pills { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.lg-pill {
	display: inline-flex; align-items: center; gap: 5px;
	padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 500;
	border: 1px solid var(--ork-input-border); background: #fff; color: var(--rp-text-muted);
	text-decoration: none; cursor: pointer; transition: all 0.15s;
}
.lg-pill:hover { border-color: var(--rp-accent-mid); color: var(--rp-accent); }
.lg-pill.lg-pill-active { background: var(--rp-accent); border-color: var(--rp-accent); color: #fff; }

/* Search bar */
.lg-search-bar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
.lg-search-bar label { font-size: 0.82rem; color: var(--rp-text-muted); white-space: nowrap; }
.lg-search-bar input {
	padding: 5px 9px;
	border: 1px solid var(--ork-input-border);
	background: var(--ork-input-bg);
	border-radius: 5px;
	font-size: 0.82rem;
	min-width: 200px;
	outline: none;
}
.lg-search-bar input:focus { border-color: var(--rp-accent-mid); background: var(--ork-card-bg); }

/* Dual range slider */
.lg-rank-range { display: flex; align-items: center; gap: 10px; }
.lg-range-label { font-size: 0.82rem; color: var(--rp-text-muted); white-space: nowrap; }
.lg-range-label strong { color: var(--rp-accent); min-width: 2ch; display: inline-block; text-align: center; }
.lg-dual-range {
	position: relative;
	width: 180px;
	height: 24px;
}
.lg-dual-range-track {
	position: absolute;
	top: 50%; transform: translateY(-50%);
	height: 4px; left: 0; right: 0;
	background: #e2e8f0; border-radius: 2px;
}
.lg-dual-range-fill {
	position: absolute;
	top: 50%; transform: translateY(-50%);
	height: 4px;
	background: var(--rp-accent); border-radius: 2px;
}
.lg-dual-range input[type="range"] {
	position: absolute;
	width: 100%; top: 50%; transform: translateY(-50%);
	margin: 0; padding: 0;
	background: transparent;
	pointer-events: none;
	-webkit-appearance: none; appearance: none;
	height: 4px;
}
.lg-dual-range input[type="range"]::-webkit-slider-thumb {
	-webkit-appearance: none; appearance: none;
	width: 16px; height: 16px; border-radius: 50%;
	background: var(--rp-accent);
	border: 2px solid #fff;
	box-shadow: 0 1px 3px rgba(0,0,0,0.25);
	cursor: pointer; pointer-events: all;
}
.lg-dual-range input[type="range"]::-moz-range-thumb {
	width: 16px; height: 16px; border-radius: 50%;
	background: var(--rp-accent);
	border: 2px solid #fff;
	box-shadow: 0 1px 3px rgba(0,0,0,0.25);
	cursor: pointer; pointer-events: all;
}
.lg-dual-range input[type="range"]:focus { outline: none; }
.lg-dual-range input[type="range"]:focus::-webkit-slider-thumb { box-shadow: 0 0 0 3px var(--rp-accent-mid); }

/* ── Award filter pill row ──────────────────────────────────── */
.lg-award-filter {
	display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
	margin-bottom: 10px; padding: 8px 10px;
	background: var(--ork-bg-inset); border: 1px solid var(--rp-border); border-radius: 6px;
}
.lg-award-filter-label { font-size: 0.78rem; color: var(--rp-text-muted); white-space: nowrap; margin-right: 2px; }
.lg-award-pills { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; flex: 1; }
.lg-award-pill-sep { width: 1px; height: 18px; background: var(--rp-border); margin: 0 2px; }

.lg-award-pill {
	padding: 2px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 500;
	border: 1px solid var(--ork-input-border); background: #fff; color: var(--rp-text-muted);
	cursor: pointer; transition: all 0.12s; white-space: nowrap;
}
.lg-award-pill:hover { border-color: var(--rp-accent-mid); color: var(--rp-accent); }
.lg-award-pill.lg-award-pill-active { color: #fff; border-color: transparent; }

/* Group tints on inactive pills */
.lg-award-pill-battle  { border-color: #93c5fd; color: #1e40af; }
.lg-award-pill-sword   { border-color: #94a3b8; color: #334155; }
.lg-award-pill-crown   { border-color: #fbbf24; color: #713f12; }
.lg-award-pill-flame   { border-color: #fca5a5; color: #991b1b; }
.lg-award-pill-serpent { border-color: #86efac; color: #14532d; }

/* Active state uses the group's header colour as background */
.lg-award-pill-battle.lg-award-pill-active  { background: #3b82f6; }
.lg-award-pill-sword.lg-award-pill-active   { background: #64748b; }
.lg-award-pill-crown.lg-award-pill-active   { background: #d97706; }
.lg-award-pill-flame.lg-award-pill-active   { background: #ef4444; }
.lg-award-pill-serpent.lg-award-pill-active { background: #22c55e; }
.lg-award-pill.lg-award-pill-active:not([class*="lg-award-pill-battle"]):not([class*="lg-award-pill-sword"]):not([class*="lg-award-pill-crown"]):not([class*="lg-award-pill-flame"]):not([class*="lg-award-pill-serpent"]) {
	background: var(--rp-accent);
}

/* ── Clickable stat card filter ─────────────────────────────── */
.rp-stat-card-filter { cursor: pointer; transition: box-shadow 0.15s, border-color 0.15s; border: 1px dashed var(--rp-border); }
.rp-stat-card-filter:hover { box-shadow: 0 0 0 2px var(--rp-accent-mid); border-color: var(--rp-accent-mid); }
.rp-stat-card-filter .rp-stat-label::after { content: ' \f0b0'; font-family: 'Font Awesome 7 Free'; font-weight: 900; font-size: 0.65rem; color: var(--rp-text-hint); margin-left: 4px; }
.rp-stat-card-filter.lg-stat-active {
	background: var(--rp-accent);
	color: #fff;
	box-shadow: 0 0 0 2px var(--rp-accent);
}
.rp-stat-card-filter.lg-stat-active .rp-stat-icon,
.rp-stat-card-filter.lg-stat-active .rp-stat-number,
.rp-stat-card-filter.lg-stat-active .rp-stat-label { color: #fff; }

/* ── Inactive player indicator ───────────────────────────────── */
.lg-showing-inactive tr[data-recent="0"] td { opacity: 0.55; }
.lg-showing-inactive tr[data-recent="0"] td.lg-col-player .lg-player-link { font-style: italic; }
.lg-showing-inactive tr[data-recent="0"] td.lg-col-player::after {
	content: 'inactive';
	display: inline-block;
	margin-left: 6px;
	font-size: 0.65rem;
	font-style: normal;
	font-weight: 600;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: #9ca3af;
	background: #f3f4f6;
	border: 1px solid #d1d5db;
	border-radius: 3px;
	padding: 1px 5px;
	vertical-align: middle;
}

/* ── Frozen header rows ────────────────────────────────────────
   The wrapper is the scroller (both axes), capped to the viewport below the 48px
   fixed navbar so the headers have something to stick to. Short grids stay under the
   cap and scroll with the page as before. --lg-head1-h is the group row's rendered
   height, set from the script. Stacking inside the scroller: body cells (auto) <
   sticky Persona cells (2) < header cells (4) < Persona header corner (5). */
.lg-scroll {
	overflow: auto;
	max-height: max(320px, calc(100vh - 64px));
	max-height: max(320px, calc(100dvh - 64px));
}
/* DataTables gives sortable header cells position:relative at (0,2,4); these outrank it
   so every header cell sticks. Sticky is also a positioned box for the sort arrows. */
.rp-table-area table.dataTable.lg-table thead > tr > th {
	position: sticky; top: 0; z-index: 4;
}
.rp-table-area table.dataTable.lg-table thead > tr.lg-header-row > th {
	top: var(--lg-head1-h, 30px);
}
.rp-table-area table.dataTable.lg-table thead > tr.lg-group-header-row > th.lg-col-player {
	left: 0; z-index: 5;
}

@media print {
	.lg-scroll { overflow: visible; max-height: none; }
	.rp-table-area table.dataTable.lg-table thead > tr > th { position: static !important; }
}

@media print {
	.rp-header-actions, .lg-search-bar { display: none; }
	.lg-table th.lg-col-award { width: 28px; min-width: 28px; max-width: 28px; }
	.lg-th-inner { font-size: 0.65rem; width: 70px; }
}
/* =====================================================
   DARK MODE — Ladder grid knight group palette
   ===================================================== */
html[data-theme="dark"] th.lg-group-battle  { background: #1e3a5f !important; color: #90cdf4; }
html[data-theme="dark"] th.lg-group-battle .lg-th-inner { color: #90cdf4; }
html[data-theme="dark"] .lg-group-header-row th.lg-group-battle { border-left-color: #2a4a7f; }
html[data-theme="dark"] td.lg-group-battle  { background: #1a2d45 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-group-battle { background: #172540 !important; }

html[data-theme="dark"] th.lg-group-sword  { background: #2d3748 !important; color: #cbd5e0; }
html[data-theme="dark"] th.lg-group-sword .lg-th-inner { color: #cbd5e0; }
html[data-theme="dark"] .lg-group-header-row th.lg-group-sword { border-left-color: #4a5568; }
html[data-theme="dark"] td.lg-group-sword  { background: #252f3d !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-group-sword { background: #202838 !important; }

html[data-theme="dark"] th.lg-group-crown  { background: #3d2b00 !important; color: #fbd38d; }
html[data-theme="dark"] th.lg-group-crown .lg-th-inner { color: #fbd38d; }
html[data-theme="dark"] .lg-group-header-row th.lg-group-crown { border-left-color: #975a16; }
html[data-theme="dark"] td.lg-group-crown  { background: #2e2000 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-group-crown { background: #281c00 !important; }

html[data-theme="dark"] th.lg-group-flame  { background: #3d1a1a !important; color: #fca5a5; }
html[data-theme="dark"] th.lg-group-flame .lg-th-inner { color: #fca5a5; }
html[data-theme="dark"] .lg-group-header-row th.lg-group-flame { border-left-color: #9b2c2c; }
html[data-theme="dark"] td.lg-group-flame  { background: #2e1414 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-group-flame { background: #281010 !important; }

html[data-theme="dark"] th.lg-group-serpent { background: #1a3a2a !important; color: #9ae6b4; }
html[data-theme="dark"] th.lg-group-serpent .lg-th-inner { color: #9ae6b4; }
html[data-theme="dark"] .lg-group-header-row th.lg-group-serpent { border-left-color: #276749; }
html[data-theme="dark"] td.lg-group-serpent { background: #142e22 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-group-serpent { background: #10261c !important; }

/* Knight cell highlights */
html[data-theme="dark"] .lg-table tbody tr:nth-child(n) td.lg-cell-knight-battle  { background: #2a4a7f !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(n) td.lg-cell-knight-sword   { background: #4a5568 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(n) td.lg-cell-knight-crown   { background: #7c4400 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(n) td.lg-cell-knight-flame   { background: #7c2020 !important; }
html[data-theme="dark"] .lg-table tbody tr:nth-child(n) td.lg-cell-knight-serpent { background: #1a5c3a !important; }

/* Hover */
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-group-battle  { background: #1e3a5f !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-group-sword   { background: #2d3748 !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-group-crown   { background: #3d2b00 !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-group-flame   { background: #3d1a1a !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-group-serpent { background: #1a3a2a !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-cell-knight-battle  { background: #3a5f9f !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-cell-knight-sword   { background: #5a6a80 !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-cell-knight-crown   { background: #9c5800 !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-cell-knight-flame   { background: #9c3030 !important; }
html[data-theme="dark"] .lg-table tbody tr:hover td.lg-cell-knight-serpent { background: #246b48 !important; }

/* Sticky player column + misc */
html[data-theme="dark"] .lg-table th.lg-col-player,
html[data-theme="dark"] .lg-table td.lg-col-player { background: var(--ork-card-bg); }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-col-park { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .lg-table tbody tr:nth-child(even) td.lg-col-player { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .lg-table td.lg-col-award { border-left-color: var(--ork-border); }
html[data-theme="dark"] .lg-award-filter { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .lg-pill { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-muted); }
html[data-theme="dark"] .lg-award-pill { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .lg-award-pill-battle  { border-color: #2a4a7f; color: #90cdf4; }
html[data-theme="dark"] .lg-award-pill-sword   { border-color: #4a5568; color: #cbd5e0; }
html[data-theme="dark"] .lg-award-pill-crown   { border-color: #975a16; color: #fbd38d; }
html[data-theme="dark"] .lg-award-pill-flame   { border-color: #9b2c2c; color: #fca5a5; }
html[data-theme="dark"] .lg-award-pill-serpent { border-color: #276749; color: #9ae6b4; }
/* Active state must out-rank the dark inactive tints above (same specificity, later wins) */
html[data-theme="dark"] .lg-award-pill.lg-award-pill-active { color: #fff; border-color: transparent; }
html[data-theme="dark"] .lg-award-pill-battle.lg-award-pill-active  { background: #3b82f6; }
html[data-theme="dark"] .lg-award-pill-sword.lg-award-pill-active   { background: #64748b; }
html[data-theme="dark"] .lg-award-pill-crown.lg-award-pill-active   { background: #d97706; }
html[data-theme="dark"] .lg-award-pill-flame.lg-award-pill-active   { background: #ef4444; }
html[data-theme="dark"] .lg-award-pill-serpent.lg-award-pill-active { background: #22c55e; }
html[data-theme="dark"] .lg-award-pill.lg-award-pill-active:not([class*="lg-award-pill-battle"]):not([class*="lg-award-pill-sword"]):not([class*="lg-award-pill-crown"]):not([class*="lg-award-pill-flame"]):not([class*="lg-award-pill-serpent"]) { background: var(--rp-accent); }
html[data-theme="dark"] .lg-dual-range-track { background: var(--ork-border); }
html[data-theme="dark"] .lg-showing-inactive tr[data-recent="0"] td.lg-col-player::after { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-muted); }
html[data-theme="dark"] .lg-cell-master { color: #c4b5fd; }

</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-th rp-header-icon"></i>
				<h1 class="rp-header-title"><?= htmlspecialchars($page_title ?? 'Ladder Awards Grid') ?></h1>
			</div>
<?php if ($scope_label) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?= $scope_link ?>">
					<i class="fas <?= $scope_icon ?>"></i>
					<?= htmlspecialchars($scope_label) ?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
<?php if ($_smartCourt) : ?>
			<button class="rp-btn-ghost lg-sc-btn" id="lg-sc-btn" type="button" aria-pressed="false"><i class="fas fa-wand-magic-sparkles"></i> <span id="lg-sc-btn-label">Smart Court</span></button>
<?php endif; ?>
			<button class="rp-btn-ghost" id="lg-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost" id="lg-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

<?php if ($_smartCourt) : ?>
	<!-- ── Smart Court bar ────────────────────────────────── -->
	<div class="lg-sc-msg lg-sc-notice" id="lg-sc-notice" role="status" hidden>
		<span class="lg-sc-msg-text"></span>
		<button class="lg-sc-msg-x" type="button" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>
	</div>
	<div class="lg-sc-bar" id="lg-sc-bar" hidden>
		<span class="lg-sc-bar-court"><i class="fas fa-clipboard-list"></i> <strong id="lg-sc-bar-court"></strong></span>
		<span class="lg-sc-bar-limit">
			<label for="lg-sc-limit">Ladder Limit Assist:</label>
			<select id="lg-sc-limit">
				<option value="0">Off</option>
<?php for ($_i = 1; $_i <= 10; $_i++) : ?>
				<option value="<?= $_i ?>"><?= $_i ?></option>
<?php endfor; ?>
			</select>
			<span class="lg-sc-q" tabindex="0" role="note" data-tip="If your park or region cannot give awards above a certain amount, set that limit here and any cell at or above that limit will gray out."><i class="fas fa-circle-question"></i></span>
		</span>
		<span class="lg-sc-legend">
			<span><span class="lg-sc-r">R</span> recommended higher</span>
			<span><i class="fas fa-hourglass-half lg-sc-glass"></i> active, overdue</span>
		</span>
	</div>
<?php endif; ?>

	<!-- ── Context ────────────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Ladder award ranks for all active players. <strong>M</strong> = Master. Numbers indicate current rank. Players with no ladder awards are hidden.
			Columns are grouped by Knighthood path with colour-coded headers
			(<span style="display:inline-block;width:10px;height:10px;background:#dbeafe;border:1px solid #93c5fd;border-radius:2px;vertical-align:middle;"></span> Battle,
			<span style="display:inline-block;width:10px;height:10px;background:#e2e8f0;border:1px solid #94a3b8;border-radius:2px;vertical-align:middle;"></span> Sword,
			<span style="display:inline-block;width:10px;height:10px;background:#fef9c3;border:1px solid #fbbf24;border-radius:2px;vertical-align:middle;"></span> Crown,
			<span style="display:inline-block;width:10px;height:10px;background:#fee2e2;border:1px solid #fca5a5;border-radius:2px;vertical-align:middle;"></span> Flame,
			<span style="display:inline-block;width:10px;height:10px;background:#dcfce7;border:1px solid #86efac;border-radius:2px;vertical-align:middle;"></span> Serpent).
			A player's cells are highlighted more strongly in any Knighthood group where they hold that Knighthood.
			You can filter by Knights and Masters by selecting the stat cards. You can also choose a range of values to show overall by using the Rank slider.</span>
	</div>

	<!-- ── Stats ──────────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?= $numPlayers ?></div>
			<div class="rp-stat-label">Players with awards</div>
		</div>
		<div class="rp-stat-card rp-stat-card-filter" id="lg-filter-knights" title="Click to filter by knights">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number"><?= $numKnights ?></div>
			<div class="rp-stat-label">Knights</div>
		</div>
		<div class="rp-stat-card rp-stat-card-filter" id="lg-filter-masters" title="Click to filter by masters">
			<div class="rp-stat-icon"><i class="fas fa-star"></i></div>
			<div class="rp-stat-number"><?= $numMasters ?></div>
			<div class="rp-stat-label">Masters</div>
		</div>
	</div>

<?php if (empty($awardList)) : ?>
	<div class="rp-table-area" style="text-align:center; padding:40px; color:var(--rp-text-hint);">
		<i class="fas fa-inbox" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
		No ladder award data found for this location.
	</div>
<?php else : ?>

	<!-- ── Table area ─────────────────────────────────────── -->
	<div class="rp-table-area">

		<div class="lg-search-bar">
			<div class="lg-filter-pills">
				<label for="lg-search"><i class="fas fa-search"></i> Filter players:</label>
				<input type="text" id="lg-search" placeholder="Type a persona name…">
				<button class="lg-pill" id="lg-btn-inactive" type="button"><i class="fas fa-eye"></i> Include Inactive</button>
				<button class="lg-pill" id="lg-btn-new" type="button" aria-pressed="false"><i class="fas fa-user-plus"></i> Show recent players without awards?</button>
			</div>
			<div class="lg-rank-range">
				<label class="lg-range-label">Rank: <strong id="lg-range-lo-val">1</strong> – <strong id="lg-range-hi-val">10+</strong></label>
				<div class="lg-dual-range">
					<div class="lg-dual-range-track"></div>
					<div class="lg-dual-range-fill" id="lg-range-fill"></div>
					<input type="range" id="lg-range-lo" min="1" max="10" value="1" step="1">
					<input type="range" id="lg-range-hi" min="1" max="10" value="10" step="1">
				</div>
			</div>
		</div>
		<div class="lg-award-filter" id="lg-award-filter">
			<span class="lg-award-filter-label"><i class="fas fa-filter"></i> Filter by award(s):</span>
			<div class="lg-award-pills" id="lg-award-pills">
<?php
$_prevGroup = null;
foreach ($awardList as $_aid => $_ainfo) :
	$_grp = strtolower($_ainfo['KnightGroup'] ?? '');
	if ($_grp !== $_prevGroup && $_prevGroup !== null) : ?>
				<span class="lg-award-pill-sep"></span>
<?php   endif; $_prevGroup = $_grp; ?>
				<button class="lg-award-pill<?= $_grp ? ' lg-award-pill-' . $_grp : '' ?>"
				        data-award-id="<?= $_aid ?>" type="button">
					<?= htmlspecialchars($_ainfo['DisplayName'] ?? $_ainfo['Name']) ?>
				</button>
<?php endforeach; ?>
			</div>
			<button class="lg-pill lg-award-clear" id="lg-award-clear" type="button" style="display:none;">
				<i class="fas fa-times"></i> Clear
			</button>
		</div>

		<div class="lg-scroll">
			<table class="lg-table" id="lg-grid-table">
<?php $renderLadderThead('title'); ?>
				<tbody>
<?php foreach ($rows as $row) : ?>
<?php   $_knightGroups = $row['KnightGroups'] ?? []; ?>
	<?php
	$_isKnight   = !empty($row['KnightGroups']) ? '1' : '0';
	$_isMaster   = '0';
	$_maxRank    = 0;
	$_awardRanks = [];
	foreach ($awardIds as $_aid) {
		$_aw = $row['Awards'][$_aid] ?? null;
		if ($_aw === null) { $_awardRanks[$_aid] = 0; continue; }
		if (!empty($_aw['IsMaster'])) {
			$_isMaster = '1'; $_maxRank = 13;
			$_awardRanks[$_aid] = 999;
		} else {
			$_r = (int)($_aw['Rank'] ?? 0);
			$_awardRanks[$_aid] = $_r;
			if ($_r > $_maxRank && $_maxRank < 13) $_maxRank = $_r;
		}
	}
?>
			<tr<?= !empty($row['Suspended']) ? ' class="rp-row-suspended" title="This player is currently under suspension"' : '' ?> data-recent="<?= $row['RecentSignIn'] ? '1' : '0' ?>" data-mundane-id="<?= (int)$row['MundaneId'] ?>" data-persona="<?= htmlspecialchars($row['Persona'], ENT_QUOTES) ?>" data-suspended="<?= !empty($row['Suspended']) ? '1' : '0' ?>" data-last-award="<?= htmlspecialchars((string)($row['LastAwardDate'] ?? ''), ENT_QUOTES) ?>" data-last-signin="<?= htmlspecialchars((string)($row['LastSignIn'] ?? ''), ENT_QUOTES) ?>" data-is-knight="<?= $_isKnight ?>" data-is-master="<?= $_isMaster ?>" data-max-rank="<?= $_maxRank ?>" data-awards="<?= htmlspecialchars(json_encode($_awardRanks), ENT_QUOTES) ?>">
					<td class="lg-col-player" data-order="<?= htmlspecialchars($row['Persona'], ENT_QUOTES) ?>">
						<a class="lg-player-link" href="<?= UIR . 'Player/profile/' . (int)$row['MundaneId'] ?>">
							<?= htmlspecialchars($row['Persona']) ?>
						</a>
						<span class="lg-name-flags"></span>
					</td>
<?php if ($isKingdom) : ?>
					<td class="lg-col-park" data-order="<?= htmlspecialchars($row['ParkName'] ?? '', ENT_QUOTES) ?>">
<?php   if (!empty($row['ParkId'])) : ?>
						<a class="lg-player-link" href="<?= UIR . 'Park/profile/' . (int)$row['ParkId'] ?>">
							<?= htmlspecialchars($row['ParkName']) ?>
						</a>
<?php   else : ?>
						<?= htmlspecialchars($row['ParkName']) ?>
<?php   endif; ?>
					</td>
<?php endif; ?>
					<?= $renderAgeCell($row['LastAwardDate'] ?? null, (string)($row['LastAwardLabel'] ?? '')) ?>
					<?= $renderAgeCell($row['LastSignIn'] ?? null) ?>
<?php foreach ($awardIds as $aid) : ?>
<?php   $info = $row['Awards'][$aid] ?? null; ?>
<?php   $_gc  = strtolower($awardList[$aid]['KnightGroup'] ?? ''); ?>
<?php   $_knighted = $_gc && isset($_knightGroups[ucfirst($_gc)]); ?>
<?php   $_tdClass = 'lg-col-award' . ($_gc ? ' lg-group-' . $_gc : '') . ($_knighted ? ' lg-cell-knight-' . $_gc : ''); ?>
<?php   $_colAttr = 'data-col="' . htmlspecialchars((string)$aid, ENT_QUOTES) . '"'; ?>
<?php
	$_awardedTip = '';
	if ($info !== null && !$info['IsMaster'] && !empty($info['Date'])) {
		$_awDays = (int) floor((strtotime(date('Y-m-d')) - strtotime($info['Date'])) / 86400);
		$_awardedTip = ' data-tip="' . htmlspecialchars('Awarded ' . $info['Date'] . ' (' . ($_awDays <= 0 ? 'today' : $_awDays . 'd ago') . ')', ENT_QUOTES) . '"';
	}
?>
<?php   if ($info !== null && $info['IsMaster']) : ?>
				<td class="<?= $_tdClass ?>" <?= $_colAttr ?> data-rank="<?= (int)($info['Rank'] ?? 0) ?>" data-master="1" data-order="999"><span class="lg-cell-master">M</span></td>
<?php   elseif ($info !== null && $info['Rank'] !== null) : ?>
				<td class="<?= $_tdClass ?>" <?= $_colAttr ?> data-rank="<?= (int)$info['Rank'] ?>" data-order="<?= (int)$info['Rank'] ?>"><span class="lg-cell-rank"<?= $_awardedTip ?>><?= (int)$info['Rank'] ?></span></td>
<?php   else : ?>
				<td class="<?= $_tdClass ?>" <?= $_colAttr ?> data-rank="0" data-order="0"><span class="lg-cell-empty">·</span></td>
<?php   endif; ?>
<?php endforeach; ?>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	</div><!-- /rp-table-area -->

	<!-- ── New Players ────────────────────────────────────── -->
	<div class="rp-table-area lg-new-box" id="lg-new-box" style="display:none;">
		<h2 class="lg-new-title">New Players</h2>
		<div class="lg-new-sub">Players without awards, with at least two sign-ins in the past 90 days.</div>
		<div class="lg-new-status" id="lg-new-status" role="status"></div>
		<div id="lg-new-wrap" class="lg-scroll" style="display:none;">
			<table class="lg-table" id="lg-new-table">
<?php $renderLadderThead('data-tip'); ?>
				<tbody></tbody>
			</table>
		</div>
	</div>

<?php endif; ?>

<?php if ($_smartCourt) : ?>
	<!-- ── Smart Court card, FAB, and modal (moved to body by lg-smart-court.js) ── -->
	<div class="lg-sc-card" id="lg-sc-card" hidden>
		<div class="lg-sc-card-head">
			<div class="lg-sc-card-title">
				<span><i class="fas fa-clipboard-list"></i> Pending Court</span>
				<span class="lg-sc-card-court" id="lg-sc-card-court"></span>
			</div>
			<button class="lg-sc-card-min-btn" id="lg-sc-minimize" type="button" aria-label="Minimize" data-tip="Minimize"><i class="fas fa-minus"></i></button>
		</div>
		<div class="lg-sc-msg" id="lg-sc-card-msg" role="status" hidden>
			<span class="lg-sc-msg-text"></span>
			<button class="lg-sc-msg-x" type="button" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>
		</div>
		<div class="lg-sc-list" id="lg-sc-list"></div>
		<div class="lg-sc-card-foot">
			<button class="lg-sc-clear" id="lg-sc-clear" type="button">Clear All</button>
			<a class="lg-sc-open" id="lg-sc-open" target="_blank" rel="noopener" aria-label="Open court in a new tab" data-tip="Open court in a new tab" hidden><i class="fas fa-up-right-from-square"></i></a>
			<button class="lg-sc-save" id="lg-sc-save" type="button">Save to Court</button>
		</div>
	</div>
	<button class="lg-sc-fab" id="lg-sc-fab" type="button" aria-label="Show pending court" hidden>
		<i class="fas fa-clipboard"></i>
		<span class="lg-sc-fab-count" id="lg-sc-fab-count" hidden>0</span>
	</button>

	<div class="lg-sc-scrim" id="lg-sc-scrim" hidden>
		<div class="lg-sc-modal" id="lg-sc-modal" role="dialog" aria-modal="true" aria-labelledby="lg-sc-modal-title" data-choice="new">
			<div class="lg-sc-modal-title" id="lg-sc-modal-title"><i class="fas fa-wand-magic-sparkles"></i> Smart Court</div>
			<form id="lg-sc-form" novalidate>
				<div class="lg-sc-modal-body">
					<div class="lg-sc-choice">
						<button type="button" id="lg-sc-pick-new">Start a new court</button>
						<button type="button" id="lg-sc-pick-existing">Add to an existing court</button>
					</div>
					<div id="lg-sc-fields-new">
						<div class="lg-sc-field">
							<label for="lg-sc-name">Court Name</label>
							<input type="text" id="lg-sc-name" maxlength="100" autocomplete="off">
						</div>
						<div class="lg-sc-field">
							<label for="lg-sc-date">Date</label>
							<input type="date" id="lg-sc-date">
						</div>
						<div class="lg-sc-field">
							<label for="lg-sc-event">Link to Event (optional)</label>
							<select id="lg-sc-event"></select>
						</div>
						<div class="lg-sc-field">
							<span class="lg-sc-field-label">How will this court be handled?</span>
							<div class="lg-sc-mode-opts">
								<label class="lg-sc-mode-opt">
									<input type="radio" name="lg-sc-mode" value="run" checked>
									<span class="lg-sc-mode-title"><i class="fas fa-bullhorn"></i> Run at Court</span>
									<span class="lg-sc-mode-desc">I'll grant awards live during the ceremony.</span>
								</label>
								<label class="lg-sc-mode-opt">
									<input type="radio" name="lg-sc-mode" value="plan">
									<span class="lg-sc-mode-title"><i class="fas fa-clipboard-list"></i> Lock as Plan</span>
									<span class="lg-sc-mode-desc">I'm preparing the order of court for someone to record later.</span>
								</label>
							</div>
						</div>
					</div>
					<div id="lg-sc-fields-existing" hidden>
						<div class="lg-sc-field">
							<label for="lg-sc-court">Court</label>
							<select id="lg-sc-court"></select>
						</div>
					</div>
					<div class="lg-sc-modal-error" id="lg-sc-modal-error" role="alert" hidden></div>
				</div>
				<div class="lg-sc-modal-foot">
					<button class="lg-sc-clear" id="lg-sc-cancel" type="button">Cancel</button>
					<button class="lg-sc-save" id="lg-sc-go" type="submit">Start</button>
				</div>
			</form>
		</div>
	</div>
<?php endif; ?>

</div><!-- /rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/lg-smart-court-core.js?v=<?=filemtime(__DIR__.'/script/lg-smart-court-core.js')?>"></script>

<script>
(function () {
	var showInactive   = false;
	var statFilter     = null; // null | 'knights' | 'masters'
	var rankMin = 1, rankMax = 10;
	var selectedAwards = new Set(); // empty = all awards
	var table;
	var newTable = null;   // DataTable for New Players, created once its rows exist
	var newState = 'idle'; // idle | loading | loaded
	var isKingdom = <?= $isKingdom ? 'true' : 'false' ?>;
	var uir = <?= json_encode(UIR, $_jsonFlags) ?>;
	var newPlayersUrl = <?= json_encode(UIR . 'Reports/ladder_new_players&KingdomId=' . (int)($grid_kingdom_id ?? 0) . '&ParkId=' . (int)($grid_park_id ?? 0), $_jsonFlags) ?>;

	// The contract lg-smart-court.js builds on.
	window.LgGrid = {
		table: null,
		newTable: null,
		awardColumns: <?= json_encode($_awardColumns, $_jsonFlags | JSON_FORCE_OBJECT) ?>,
		awardKeys: <?= json_encode(array_map('strval', $awardIds), $_jsonFlags) ?>, // display order
		isKingdom: isKingdom,
		hideSuspended: false,
		refresh: function () {
			paintAges(table);
			table.draw(false);
			if (newTable) {
				paintAges(newTable);
				newTable.draw(false);
			}
		}
	};

	// Custom filters — run on every DataTables draw
	$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
		if (!table || settings.nTable.id !== 'lg-grid-table') return true;
		var row = table.row(dataIndex).node();
		if (!row) return true;
		if (!showInactive && row.getAttribute('data-recent') !== '1') return false;
		if (statFilter === 'knights' && row.getAttribute('data-is-knight') !== '1') return false;
		if (statFilter === 'masters' && row.getAttribute('data-is-master') !== '1') return false;
		if (selectedAwards.size > 0) {
			// AND: player must have rank > 0 in every selected award.
			// Rank slider then applies to the highest rank across those awards.
			var awardData = JSON.parse(row.getAttribute('data-awards') || '{}');
			var effectiveMax = rankMax === 10 ? 999 : rankMax;
			var mr = 0;
			var allPresent = true;
			selectedAwards.forEach(function (aid) {
				var r = awardData[aid] || 0;
				if (r === 0) allPresent = false;
				if (r > mr) mr = r;
			});
			if (!allPresent) return false;                       // missing at least one selected award
			if (mr < rankMin || mr > effectiveMax) return false; // outside rank range
		} else if (rankMin > 1 || rankMax < 10) {
			var effectiveMax = rankMax === 10 ? 999 : rankMax;
			var mr = parseInt(row.getAttribute('data-max-rank')) || 0;
			if (mr < rankMin || mr > effectiveMax) return false;
		}
		return true;
	});

	// Smart Court hides suspended players from both tables
	$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
		if (!window.LgGrid.hideSuspended) return true;
		var id  = settings.nTable.id;
		var api = id === 'lg-grid-table' ? table : (id === 'lg-new-table' ? newTable : null);
		if (!api) return true;
		var row = api.row(dataIndex).node();
		return !row || row.getAttribute('data-suspended') !== '1';
	});

	function localToday() {
		var d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
	}

	// Reads row nodes, not the document: rows filtered out of the draw are detached.
	function paintAges(dt) {
		var today = localToday();
		dt.rows().nodes().to$().find('.lg-age').each(function () {
			var f = LgSmartCore.ageFraction(this.getAttribute('data-date'), today);
			if (f !== null) this.style.setProperty('--lg-age', f);
		});
	}

	// DataTables applies columnDefs last to first, so the first match wins: '_all'
	// must stay last. Persona and Park sort on data-order, and html keeps the name
	// filter from matching link markup.
	function gridColumnDefs() {
		var offset = <?= (int)$awardColOffset ?>;
		var defs = [{ targets: 0, type: 'html', orderSequence: ['asc', 'desc'] }];
		if (isKingdom) {
			defs.push({ targets: 1, type: 'html', orderSequence: ['asc', 'desc'] });
		}
		// Sortable, but the name filter must not match award names or dates
		defs.push({ targets: [offset - 2, offset - 1], searchable: false });
		defs.push({ targets: '_all', type: 'num', orderSequence: ['desc', 'asc'] });
		return defs;
	}

	function defaultOrder() {
		return isKingdom ? [[1, 'asc'], [0, 'asc']] : [[0, 'asc']];
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = text;
		return node;
	}

	function nameCell(className, text, href) {
		var td = el('td', className);
		td.setAttribute('data-order', text);
		if (href) {
			td.appendChild(el('a', 'lg-player-link', text)).href = href;
		} else {
			td.textContent = text;
		}
		return td;
	}

	function ageCell(date, label) {
		date = date || '';
		var td = el('td', 'lg-col-date');
		td.setAttribute('data-order', Number(date.slice(0, 10).replace(/-/g, '')) || 0);
		var age = el('span', 'lg-age', date ? (date + ' ' + (label || '')).trim() : '—');
		age.setAttribute('data-date', date);
		td.appendChild(age);
		return td;
	}

	// DOM APIs only: names reach the page through textContent and setAttribute.
	function buildNewRow(p) {
		var suspended = Number(p.Suspended) ? '1' : '0';
		var tr = el('tr', suspended === '1' ? 'rp-row-suspended' : '');
		tr.setAttribute('data-mundane-id', Number(p.MundaneId));
		tr.setAttribute('data-persona', p.Persona);
		tr.setAttribute('data-suspended', suspended);
		tr.setAttribute('data-last-award', p.LastAwardDate || '');
		tr.setAttribute('data-last-signin', p.LastSignIn || '');

		var name = nameCell('lg-col-player', p.Persona, uir + 'Player/profile/' + Number(p.MundaneId));
		name.appendChild(document.createTextNode(' '));
		name.appendChild(el('span', 'lg-name-flags'));
		tr.appendChild(name);

		if (isKingdom) {
			tr.appendChild(nameCell('lg-col-park', p.ParkName, Number(p.ParkId) ? uir + 'Park/profile/' + Number(p.ParkId) : ''));
		}
		tr.appendChild(ageCell(p.LastAwardDate, p.LastAwardLabel));
		tr.appendChild(ageCell(p.LastSignIn));

		window.LgGrid.awardKeys.forEach(function (key) {
			var group = window.LgGrid.awardColumns[key].KnightGroup.toLowerCase();
			var td = el('td', 'lg-col-award' + (group ? ' lg-group-' + group : ''));
			td.setAttribute('data-col', key);
			td.setAttribute('data-rank', '0');
			td.setAttribute('data-order', '0');
			td.appendChild(el('span', 'lg-cell-empty', '·'));
			tr.appendChild(td);
		});
		return tr;
	}

	function setNewStatus(text, isError) {
		$('#lg-new-status').text(text).toggleClass('lg-new-status-error', !!isError).toggle(text !== '');
	}

	function renderNewPlayers(players) {
		newState = 'loaded';
		if (!players.length) {
			setNewStatus('No recent players without awards.');
			return;
		}
		setNewStatus('');
		var rows = document.createDocumentFragment();
		players.forEach(function (p) { rows.appendChild(buildNewRow(p)); });
		document.querySelector('#lg-new-table tbody').appendChild(rows);
		$('#lg-new-wrap').show();
		syncHeadOffset();
		newTable = $('#lg-new-table').DataTable({
			dom       : 'rt',
			paging    : false,
			info      : false,
			autoWidth : false, // the box may have been toggled off while loading
			order     : defaultOrder(),
			columnDefs: gridColumnDefs()
		});
		window.LgGrid.newTable = newTable;
		$('#lg-new-table').on('draw.dt', syncHeadOffset);
		syncHeadOffset();
		paintAges(newTable);
		// Draw again now newTable is set, so the search filters and hideSuspended apply
		newTable.search($('#lg-search').val()).draw();
	}

	function failNewPlayers(message) {
		newState = 'idle'; // the next toggle retries
		setNewStatus(message, true);
	}

	function loadNewPlayers() {
		var failed = 'New Players could not be loaded. Turn the toggle off and on to try again.';
		newState = 'loading';
		setNewStatus('Loading…');
		$.getJSON(newPlayersUrl).done(function (r) {
			if (r && r.status === 0 && Array.isArray(r.players)) {
				renderNewPlayers(r.players);
			} else {
				failNewPlayers(r && r.status === 5 ? 'Your session has expired. Log in again to see New Players.' : failed);
			}
		}).fail(function () {
			failNewPlayers(failed);
		});
	}

	// Freeze the award-name row beneath the group row: its top is the group row's height.
	function syncHeadOffset() {
		['lg-grid-table', 'lg-new-table'].forEach(function (id) {
			var tbl = document.getElementById(id);
			var row = tbl && tbl.querySelector('thead tr.lg-group-header-row');
			var box = tbl && tbl.closest('.lg-scroll');
			var h = row ? row.getBoundingClientRect().height : 0;
			if (box && h > 0) box.style.setProperty('--lg-head1-h', h + 'px');
		});
	}
	window.addEventListener('resize', syncHeadOffset);

	$(function () {
		table = $('#lg-grid-table').DataTable({
			dom       : 'rt',
			paging    : false,
			info      : false,
			columnDefs: gridColumnDefs(),
			language  : { emptyTable: 'No ladder award data found for this location.' }
		});
		window.LgGrid.table = table;
		$('#lg-grid-table').on('draw.dt', syncHeadOffset);
		syncHeadOffset();
		paintAges(table);

		// Apply sort and inactive filter on load
		table.order(defaultOrder()).draw();

		// Player name search
		$('#lg-search').on('input', function () {
			table.search(this.value).draw();
			if (newTable) newTable.search(this.value).draw();
		});

		// New Players toggle: fetches on first use, then only shows and hides
		$('#lg-btn-new').on('click', function () {
			var on = !$(this).hasClass('lg-pill-active');
			$(this).toggleClass('lg-pill-active', on).attr('aria-pressed', on ? 'true' : 'false');
			$('#lg-new-box').toggle(on);
			if (on && newState === 'idle') loadNewPlayers();
		});

		// Inactive toggle
		$('#lg-btn-inactive').on('click', function () {
			showInactive = !showInactive;
			$(this)
				.toggleClass('lg-pill-active', showInactive)
				.html(showInactive
					? '<i class="fas fa-eye-slash"></i> Showing All'
					: '<i class="fas fa-eye"></i> Include Inactive');
			$('#lg-grid-table').toggleClass('lg-showing-inactive', showInactive);
			table.draw();
		});

		// Dual rank-range slider
		function updateRangeUI() {
			var lo = parseInt($('#lg-range-lo').val());
			var hi = parseInt($('#lg-range-hi').val());
			var pctLo = (lo - 1) / 9 * 100;
			var pctHi = (hi - 1) / 9 * 100;
			$('#lg-range-fill').css({ left: pctLo + '%', right: (100 - pctHi) + '%' });
			$('#lg-range-lo-val').text(lo);
			$('#lg-range-hi-val').text(hi === 10 ? '10+' : hi);
			rankMin = lo;
			rankMax = hi;
			// When lo has reached hi, raise lo's z-index so the user can drag it back left
			$('#lg-range-lo').css('z-index', lo >= hi ? 5 : 3);
			$('#lg-range-hi').css('z-index', lo >= hi ? 4 : 5);
		}
		updateRangeUI();

		$('#lg-range-lo').on('input', function () {
			if (parseInt($(this).val()) > parseInt($('#lg-range-hi').val()))
				$(this).val($('#lg-range-hi').val());
			updateRangeUI();
			table.draw();
		});
		$('#lg-range-hi').on('input', function () {
			if (parseInt($(this).val()) < parseInt($('#lg-range-lo').val()))
				$(this).val($('#lg-range-lo').val());
			updateRangeUI();
			table.draw();
		});

		// Knights / Masters stat card filters
		$('#lg-filter-knights, #lg-filter-masters').on('click', function () {
			var key = this.id === 'lg-filter-knights' ? 'knights' : 'masters';
			statFilter = (statFilter === key) ? null : key;
			$('#lg-filter-knights').toggleClass('lg-stat-active', statFilter === 'knights');
			$('#lg-filter-masters').toggleClass('lg-stat-active', statFilter === 'masters');
			table.draw();
		});

		// Award filter pills
		$('#lg-award-pills').on('click', '.lg-award-pill', function () {
			var aid = String($(this).data('award-id'));
			if (selectedAwards.has(aid)) {
				selectedAwards.delete(aid);
				$(this).removeClass('lg-award-pill-active');
			} else {
				selectedAwards.add(aid);
				$(this).addClass('lg-award-pill-active');
			}
			$('#lg-award-clear').toggle(selectedAwards.size > 0);
			table.draw();
		});
		$('#lg-award-clear').on('click', function () {
			selectedAwards.clear();
			$('#lg-award-pills .lg-award-pill').removeClass('lg-award-pill-active');
			$(this).hide();
			table.draw();
		});

		$('#lg-btn-print').on('click', function () { window.print(); });

		$('#lg-btn-csv').on('click', function () {
			var scopeLabel = <?= json_encode($scope_name ?? $scope_label) ?>;
			var slug = scopeLabel.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
			var filename = (slug || 'ladder') + '-ladder-grid-' + localToday() + '.csv';

			// Headers injected from PHP — avoids any DataTables DOM manipulation confusion
			var headers = <?= json_encode(
				array_merge(
					['Persona'],
					$isKingdom ? ['Park', 'Mundane ID'] : ['Mundane ID'],
					['Last Award', 'Last Sign-in'],
					array_values(array_map(function($a) { return $a['Name']; }, $awardList))
				)
			) ?>;

			// Build rows — all rows regardless of active/search filter
			var lines = [headers.map(csvCell).join(',')];
			table.rows().nodes().each(function (tr) {
				var cols = [tr.getAttribute('data-persona')];
				if (isKingdom) cols.push($(tr).children('td.lg-col-park').text().trim());
				cols.push($(tr).data('mundane-id') || '');
				$(tr).children('td.lg-col-date').each(function () {
					var age = this.querySelector('.lg-age');
					cols.push(age.getAttribute('data-date') ? age.textContent.trim() : '');
				});
				// Only the value spans: Smart Court markers in these cells stay out of the export
				$(tr).children('td.lg-col-award').each(function () {
					cols.push($(this).find('.lg-cell-rank, .lg-cell-master').text().trim());
				});
				lines.push(cols.map(csvCell).join(','));
			});

			var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = filename;
			a.click();
		});
	});

	function csvCell(v) {
		return '"' + String(v).replace(/"/g, '""') + '"';
	}

	// One fixed body-level tooltip for every [data-tip] on the page. A ::after tooltip
	// would be clipped by the grid's overflow-x wrapper. New Players headers use data-tip
	// for every viewer, so this lives here rather than in the officer script.
	(function initTooltip() {
		var GAP = 6, MARGIN = 8;
		var tip = el('div', 'lg-sc-tip');
		tip.setAttribute('role', 'tooltip');
		document.body.appendChild(tip);
		var anchor = null;

		function hide() {
			anchor = null;
			tip.style.display = 'none';
		}

		function show(target) {
			var text = target.getAttribute('data-tip');
			if (!text) { hide(); return; }
			anchor = target;
			tip.textContent = text;
			tip.style.display = 'block';
			var r = target.getBoundingClientRect();
			var w = tip.offsetWidth, h = tip.offsetHeight;
			var maxLeft = window.innerWidth - w - MARGIN, maxTop = window.innerHeight - h - MARGIN;
			var top = r.bottom + GAP > maxTop ? r.top - h - GAP : r.bottom + GAP;
			tip.style.left = Math.max(MARGIN, Math.min(r.left + r.width / 2 - w / 2, maxLeft)) + 'px';
			tip.style.top  = Math.max(MARGIN, top) + 'px';
		}

		function follow(e) {
			var target = e.target.closest ? e.target.closest('[data-tip]') : null;
			if (target === anchor) return;
			if (target) show(target); else hide();
		}

		document.addEventListener('mouseover', follow);
		document.addEventListener('focusin', follow);
		document.addEventListener('focusout', hide);
		document.documentElement.addEventListener('mouseleave', hide);
		window.addEventListener('scroll', hide, true);
		// A repaint can replace the node under the tooltip, and no mouseout follows.
		document.addEventListener('click', function () {
			if (anchor && !document.contains(anchor)) hide();
		});
	})();
})();
</script>
<?php if ($_smartCourt) : ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
window.LgSmartConfig = <?= json_encode([
	'KingdomId'    => (int)$SmartCourt['KingdomId'],
	'ParkId'       => (int)$SmartCourt['ParkId'],
	'Uid'          => (int)$SmartCourt['Uid'],
	'ColumnAwards' => (object)$SmartCourt['ColumnAwards'],
	'KaToColumn'   => (object)$SmartCourt['KaToColumn'],
	'Courts'       => array_values($SmartCourt['Courts']),
	'Events'       => array_values($SmartCourt['Events']),
	'Uir'          => UIR,
], $_jsonFlags | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<script src="<?= HTTP_TEMPLATE ?>default/script/lg-smart-court.js?v=<?=filemtime(__DIR__.'/script/lg-smart-court.js')?>"></script>
<?php endif; ?>
