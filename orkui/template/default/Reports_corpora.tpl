<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total    = 0;
$expiring = 0; // expires within 30 days
$now      = time();
$soon     = strtotime('+30 days');

if (is_array($corpora_qualified)) {
	foreach ($corpora_qualified as $player) {
		$total++;
		if (!empty($player['CorporaQualifiedUntil'])) {
			$exp = strtotime($player['CorporaQualifiedUntil']);
			if ($exp !== false && $exp >= $now && $exp <= $soon) $expiring++;
		}
	}
}

/* Scope chip — derived from controller-passed ScopeType/ScopeId */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';

if (($ScopeType ?? '') === 'park' && !empty($corpora_qualified)) {
	$first       = reset($corpora_qualified);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)($ScopeId ?? 0);
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (($ScopeType ?? '') === 'kingdom' && !empty($corpora_qualified)) {
	$first       = reset($corpora_qualified);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/profile/' . (int)($ScopeId ?? 0);
	$scope_icon  = 'fa-chess-rook';
	$scope_noun  = 'kingdom';
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-scroll rp-header-icon"></i>
				<h1 class="rp-header-title">Corpora Qualified</h1>
			</div>
<?php if ($scope_label) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=$scope_link?>">
					<i class="fas <?=$scope_icon?>"></i>
					<?=htmlspecialchars($scope_label)?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Players within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?> who have a current Corpora Qualification on file.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-scroll"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Qualified Players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-clock"></i></div>
			<div class="rp-stat-number"><?=$expiring?></div>
			<div class="rp-stat-label">Expiring in 30 Days</div>
		</div>
	</div>

	<!-- ── Charts placeholder ─────────────────────────────── -->
	<div class="rp-charts-row" id="rp-charts-row"></div>

	<!-- ── Body: sidebar + table ──────────────────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-filter"></i> Filters
				</div>
				<div class="rp-filter-card-body">
					<p class="rp-no-filters">This report has no filter options.</p>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">The kingdom the player belongs to.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The player's home park.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">Player's in-game name; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Qualified Until</span>
						<span class="rp-col-guide-desc">Date through which the player's Corpora Qualification is valid.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="corpora-report-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Kingdom</th>
						<th>Park</th>
						<th>Persona</th>
						<th>Qualified Until</th>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($corpora_qualified)) : ?>
<?php 	foreach ($corpora_qualified as $player) : ?>
				<tr>
					<td><a href='<?=UIR.'Kingdom/profile/'.$player['KingdomId']?>'><?=htmlspecialchars($player['KingdomName'])?></a></td>
					<td><a href='<?=UIR.'Park/profile/'.$player['ParkId']?>'><?=htmlspecialchars($player['ParkName'])?></a></td>
					<td><a href='<?=UIR.'Player/profile/'.$player['MundaneId']?>'><?=trimlen($player['Persona'])>0?htmlspecialchars($player['Persona']):'<i>No Persona</i>'?></a></td>
					<td><?=htmlspecialchars($player['CorporaQualifiedUntil'] ?? '')?></td>
				</tr>
<?php 	endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div><!-- /rp-table-area -->

	</div><!-- /rp-body -->

</div><!-- /rp-root -->


<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
$(function() {
	var table = $('#corpora-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Corpora Qualified', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [3], type: 'date', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[0, 'asc'], [1, 'asc'], [2, 'asc']],
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
