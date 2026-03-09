<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total         = 0;
$total_credits = 0;
$total_weeks   = 0;
$dues_paid     = 0;

if (is_array($active_players)) {
	foreach ($active_players as $player) {
		$total++;
		$total_credits += (int)($player['TotalMonthlyCredits'] ?? 0);
		$total_weeks   += (int)($player['WeeksAttended']       ?? 0);
		if (isset($activewaivereduespaid)) {
			$dues_paid += (int)($player['DuesPaid'] ?? 0);
		}
	}
}

$avg_credits = $total > 0 ? round($total_credits / $total, 1) : 0;
$avg_weeks   = $total > 0 ? round($total_weeks   / $total, 1) : 0;
$dues_pct    = $total > 0 ? round(100 * $dues_paid / $total)  : 0;

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'kingdom';

if (isset($this->__session->park_id) && !empty($active_players)) {
	$first       = reset($active_players);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/index/'    . (int)$this->__session->park_id;
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (isset($this->__session->kingdom_id) && !empty($active_players)) {
	$first       = reset($active_players);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/index/' . (int)$this->__session->kingdom_id;
	$scope_icon  = 'fa-chess-rook';
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
				<i class="fas fa-crown rp-header-icon"></i>
				<h1 class="rp-header-title">Active Masters</h1>
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
		<span>Masters who hold a Master-level peerage award and meet this <?=$scope_label ? htmlspecialchars($scope_label) : $scope_noun ?>'s minimum attendance and credit requirements within the last 6 months. Players who do not meet the configured minimums will not appear.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-crown"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Active Masters</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-star"></i></div>
			<div class="rp-stat-number"><?=$avg_credits?></div>
			<div class="rp-stat-label">Avg Credits</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-check"></i></div>
			<div class="rp-stat-number"><?=$avg_weeks?></div>
			<div class="rp-stat-label">Avg Weeks</div>
		</div>
<?php if (isset($activewaivereduespaid)) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percentage"></i></div>
			<div class="rp-stat-number"><?=$dues_pct?>%</div>
			<div class="rp-stat-label">Dues Rate</div>
		</div>
<?php endif; ?>
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
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">Player's in-game name; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Weeks</span>
						<span class="rp-col-guide-desc">Distinct calendar weeks with at least one attendance in the last 6 months.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park Weeks</span>
						<span class="rp-col-guide-desc">Weeks attending any park-level event.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Attendances</span>
						<span class="rp-col-guide-desc">Total individual check-in records during the period.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Credits</span>
						<span class="rp-col-guide-desc">Credits earned with each month capped at the kingdom's configured maximum.</span>
					</div>
<?php if (isset($activewaivereduespaid)) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Dues Paid</span>
						<span class="rp-col-guide-desc">Indicates the player has a current, non-revoked dues record on file.</span>
					</div>
<?php endif; ?>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="masters-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Weeks</th>
						<th>Park Weeks</th>
						<th>Attendances</th>
						<th>Credits</th>
<?php if (isset($activewaivereduespaid)) : ?>
						<th>Dues Paid</th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($active_players)) : ?>
<?php 	foreach ($active_players as $player) : ?>
				<tr>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
					<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId']?>'><?=htmlspecialchars($player['KingdomName'])?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
					<td><a href='<?=UIR.'Park/index/'.$player['ParkId']?>'><?=htmlspecialchars($player['ParkName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Player/index/'.$player['MundaneId']?>'><?=htmlspecialchars($player['Persona'])?></a></td>
					<td><?=(int)$player['WeeksAttended']?></td>
					<td><?=(int)$player['ParkDaysAttended']?></td>
					<td><?=(int)$player['DaysAttended']?></td>
					<td><?=(int)$player['TotalMonthlyCredits']?></td>
<?php 		if (isset($activewaivereduespaid)) : ?>
					<td><?=$player['DuesPaid'] ? 'Dues Paid' : ''?></td>
<?php 		endif; ?>
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
	var numericStart = <?= 1
		+ (!isset($this->__session->kingdom_id) ? 1 : 0)
		+ (!isset($this->__session->park_id)    ? 1 : 0) ?>;

	var numericCols = [];
	for (var i = numericStart; i < numericStart + 4; i++) numericCols.push(i);

	var table = $('#masters-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{
				extend: 'csv',
				filename: 'Active Masters',
				exportOptions: { columns: ':visible' }
			},
			{
				extend: 'print',
				exportOptions: { columns: ':visible' }
			}
		],
		columnDefs: [
			{ targets: numericCols, type: 'num', className: 'dt-right' },
			{ targets: numericCols, responsivePriority: 10001 },
			{ targets: [0],         responsivePriority: 1 }
		],
		pageLength: 25,
		order: <?php
			$sortOrder = [];
			if (!isset($this->__session->park_id)) {
				$parkCol    = !isset($this->__session->kingdom_id) ? 1 : 0;
				$sortOrder[] = [$parkCol, 'asc'];
			}
			$personaCol = (!isset($this->__session->kingdom_id) ? 1 : 0)
				+ (!isset($this->__session->park_id) ? 1 : 0);
			$sortOrder[] = [$personaCol, 'asc'];
			echo json_encode($sortOrder);
		?>,
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
