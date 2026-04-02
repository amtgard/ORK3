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
$awardIds   = array_keys($awardList);
$numPlayers = count($rows);
$numAwards  = count($awardList);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

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

/* Keep DT sort arrow on the player column */
.lg-table th.lg-col-player { cursor: pointer; }

/* Sticky player column */
.lg-table th.lg-col-player,
.lg-table td.lg-col-player {
	position: sticky; left: 0; z-index: 2;
	background: #fff;
	min-width: 160px; max-width: 220px;
	text-align: left; padding: 7px 12px;
	border-right: 2px solid var(--rp-border);
	white-space: normal; word-break: break-word;
}
.lg-table th.lg-col-player { z-index: 3; background: var(--rp-bg-light); }

/* Data cells */
.lg-table { border-collapse: collapse; min-width: 100%; white-space: nowrap; }

.lg-table tbody tr:nth-child(even) td           { background: #f9fafb; }
.lg-table tbody tr:nth-child(even) td.lg-col-player { background: #f4f5f7; }
.lg-table tbody tr:hover td                     { background: #eef2ff !important; }

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
	border: 1px solid var(--rp-border); background: #fff; color: var(--rp-text-muted);
	text-decoration: none; cursor: pointer; transition: all 0.15s;
}
.lg-pill:hover { border-color: var(--rp-accent-mid); color: var(--rp-accent); }
.lg-pill.lg-pill-active { background: var(--rp-accent); border-color: var(--rp-accent); color: #fff; }

/* Search bar */
.lg-search-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.lg-search-bar label { font-size: 0.82rem; color: var(--rp-text-muted); white-space: nowrap; }
.lg-search-bar input {
	padding: 5px 9px;
	border: 1px solid var(--rp-border);
	border-radius: 5px;
	font-size: 0.82rem;
	min-width: 200px;
	outline: none;
}
.lg-search-bar input:focus { border-color: var(--rp-accent-mid); }

@media print {
	.rp-header-actions, .lg-search-bar { display: none; }
	.lg-table th.lg-col-award { width: 28px; min-width: 28px; max-width: 28px; }
	.lg-th-inner { font-size: 0.65rem; width: 70px; }
}
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
			<button class="rp-btn-ghost" id="lg-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- ── Context ────────────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Ladder award ranks for all active players. <strong>M</strong> = Master. Numbers indicate current rank. Players with no ladder awards are hidden.</span>
	</div>

	<!-- ── Stats ──────────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?= $numPlayers ?></div>
			<div class="rp-stat-label">Players with awards</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-medal"></i></div>
			<div class="rp-stat-number"><?= $numAwards ?></div>
			<div class="rp-stat-label">Ladder awards</div>
		</div>
	</div>

<?php if (empty($rows) || empty($awardList)) : ?>
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
			</div>
		</div>

		<div style="overflow-x:auto;">
			<table class="lg-table" id="lg-grid-table">
				<thead>
					<tr class="lg-header-row">
						<th class="lg-col-player">Player</th>
<?php foreach ($awardList as $aid => $ainfo) : ?>
						<th class="lg-col-award" title="<?= htmlspecialchars($ainfo['Name']) ?>">
							<div class="lg-th-inner"><?= htmlspecialchars($ainfo['DisplayName'] ?? $ainfo['Name']) ?></div>
						</th>
<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as $row) : ?>
				<tr data-recent="<?= $row['RecentSignIn'] ? '1' : '0' ?>">
					<td class="lg-col-player">
						<a class="lg-player-link" href="<?= UIR . 'Player/profile/' . (int)$row['MundaneId'] ?>">
							<?= htmlspecialchars($row['Persona']) ?>
						</a>
					</td>
<?php foreach ($awardIds as $aid) : ?>
<?php   $info = $row['Awards'][$aid] ?? null; ?>
<?php   if ($info !== null && $info['IsMaster']) : ?>
				<td class="lg-col-award" data-order="999"><span class="lg-cell-master" title="Master">M</span></td>
<?php   elseif ($info !== null && $info['Rank'] !== null) : ?>
				<td class="lg-col-award" data-order="<?= (int)$info['Rank'] ?>"><span class="lg-cell-rank"><?= (int)$info['Rank'] ?></span></td>
<?php   else : ?>
				<td class="lg-col-award" data-order="0"><span class="lg-cell-empty">·</span></td>
<?php   endif; ?>
<?php endforeach; ?>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	</div><!-- /rp-table-area -->

<?php endif; ?>

</div><!-- /rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
(function () {
	var showInactive = false;
	var table;

	// Custom inactive filter — runs on every DataTables draw
	$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
		if (showInactive) return true;
		if (!table || settings.nTable.id !== 'lg-grid-table') return true;
		var row = table.row(dataIndex).node();
		return row && row.getAttribute('data-recent') === '1';
	});

	$(function () {
		table = $('#lg-grid-table').DataTable({
			dom       : 'rt',
			paging    : false,
			info      : false,
			order     : [[0, 'asc']],
			columnDefs: [
				{ targets: 0,    type: 'string', orderSequence: ['asc', 'desc'] },
				{ targets: '_all', type: 'num',  orderSequence: ['desc', 'asc'] }
			]
		});

		// Apply inactive filter on load
		table.draw();

		// Player name search
		$('#lg-search').on('input', function () {
			table.search(this.value).draw();
		});

		// Inactive toggle
		$('#lg-btn-inactive').on('click', function () {
			showInactive = !showInactive;
			$(this)
				.toggleClass('lg-pill-active', showInactive)
				.html(showInactive
					? '<i class="fas fa-eye-slash"></i> Showing All'
					: '<i class="fas fa-eye"></i> Include Inactive');
			table.draw();
		});

		$('#lg-btn-print').on('click', function () { window.print(); });
	});
})();
</script>
