<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total             = 0;
$total_credits     = 0;
$total_weeks       = 0;
$total_attendances = 0;
$dues_paid         = 0;
$park_counts       = [];

if (is_array($active_players)) {
	foreach ($active_players as $player) {
		$total++;
		$total_credits     += (int)($player['DailyCredits']  ?? 0);
		$total_weeks       += (int)($player['WeeksAttended'] ?? 0);
		$total_attendances += (int)($player['DaysAttended']  ?? 0);
		if (isset($activewaivereduespaid)) {
			$dues_paid += (int)($player['DuesPaid'] ?? 0);
		}
		$pname = $player['ParkName'] ?? 'Unknown';
		$park_counts[$pname] = ($park_counts[$pname] ?? 0) + 1;
	}
}

arsort($park_counts);
$chart_parks  = array_keys(array_slice($park_counts, 0, 20, true));
$chart_counts = array_values(array_slice($park_counts, 0, 20, true));

$avg_credits     = $total > 0 ? round($total_credits     / $total, 1) : 0;
$avg_weeks       = $total > 0 ? round($total_weeks       / $total, 1) : 0;
$avg_attendances = $total > 0 ? round($total_attendances / $total, 1) : 0;
$dues_pct        = $total > 0 ? round(100 * $dues_paid   / $total)    : 0;

$report_title = isset($activewaivereduespaid) ? 'Player Activity Report' : 'Active Players';

/* Scope chip — use explicit controller-provided type/id, not session */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';

if (($report_type ?? null) === 'Park' && !empty($active_players)) {
	$first       = reset($active_players);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/index/'    . (int)($report_id ?? 0);
	$scope_icon  = 'fa-tree';
} elseif (($report_type ?? null) === 'Kingdom' && !empty($active_players)) {
	$first       = reset($active_players);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/index/' . (int)($report_id ?? 0);
	$scope_icon  = 'fa-chess-rook';
}

$show_chart = ($report_type ?? null) === 'Kingdom' && count($chart_parks) > 1;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-users rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($report_title)?></h1>
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
		<span>
<?php if (isset($activewaivereduespaid)) : ?>
			Players who attended at least once in the last 6 months, meet this <?=$scope_label ? htmlspecialchars($scope_label) : 'kingdom' ?>'s minimum activity requirements, and have a dues record on file.
<?php else : ?>
			Players who attended at least once in the last 6 months and meet this <?=$scope_label ? htmlspecialchars($scope_label) : 'kingdom' ?>'s minimum attendance and credit requirements.
<?php endif; ?>
		</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Active Players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-star"></i></div>
			<div class="rp-stat-number"><?=$avg_credits?></div>
			<div class="rp-stat-label">Avg Credits</div>
		</div>
<?php if (isset($activewaivereduespaid)) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-check-circle"></i></div>
			<div class="rp-stat-number"><?=$dues_paid?></div>
			<div class="rp-stat-label">Dues Paid</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percentage"></i></div>
			<div class="rp-stat-number"><?=$dues_pct?>%</div>
			<div class="rp-stat-label">Dues Rate</div>
		</div>
<?php else : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-check"></i></div>
			<div class="rp-stat-number"><?=$avg_weeks?></div>
			<div class="rp-stat-label">Avg Weeks</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-flag-checkered"></i></div>
			<div class="rp-stat-number"><?=$avg_attendances?></div>
			<div class="rp-stat-label">Avg Attendances</div>
		</div>
<?php endif; ?>
	</div>

	<!-- ── Charts row ─────────────────────────────────────── -->
	<div class="rp-charts-row" id="rp-charts-row">
<?php if ($show_chart) : ?>
		<div id="active-parks-chart" style="width:100%;height:320px;"></div>
<?php endif; ?>
	</div>

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
						<span class="rp-col-guide-name">Weeks</span>
						<span class="rp-col-guide-desc">Distinct calendar weeks with at least one attendance in the last 6 months.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Local Park Weeks</span>
						<span class="rp-col-guide-desc">Weeks attended specifically at the player's home park.</span>
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
						<span class="rp-col-guide-name">Monthly Credits</span>
						<span class="rp-col-guide-desc">Credits earned with each month capped at the kingdom's configured maximum.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">All Credits</span>
						<span class="rp-col-guide-desc">Total credits across all attendance records, uncapped.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">RoP Credits</span>
						<span class="rp-col-guide-desc">Credits limited by the Rule of Participation maximum.</span>
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
			<table id="active-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (($report_type ?? null) !== 'Kingdom') : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if (($report_type ?? null) !== 'Park') : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Weeks</th>
						<th>Local Park Weeks</th>
						<th>Park Weeks</th>
						<th>Attendances</th>
						<th>Monthly Credits</th>
						<th>All Credits</th>
						<th>RoP Credits</th>
<?php if (isset($activewaivereduespaid)) : ?>
						<th>Dues Paid</th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($active_players)) : ?>
<?php 	foreach ($active_players as $player) : ?>
				<tr>
<?php 		if (($report_type ?? null) !== 'Kingdom') : ?>
					<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId']?>'><?=htmlspecialchars($player['KingdomName'])?></a></td>
<?php 		endif; ?>
<?php 		if (($report_type ?? null) !== 'Park') : ?>
					<td><a href='<?=UIR.'Park/index/'.$player['ParkId']?>'><?=htmlspecialchars($player['ParkName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Player/index/'.$player['MundaneId']?>'><?=htmlspecialchars($player['Persona'])?></a></td>
					<td><?=(int)$player['WeeksAttended']?></td>
					<td><?=(int)$player['LocalParkWeeksAttended']?></td>
					<td><?=(int)$player['ParkDaysAttended']?></td>
					<td><?=(int)$player['DaysAttended']?></td>
					<td><?=(int)$player['TotalMonthlyCredits']?></td>
					<td><?=(int)$player['DailyCredits']?></td>
					<td><?=(int)$player['RopLimitedCredits']?></td>
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
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
$(function() {
	var numericStart = <?= 1
		+ (($report_type ?? null) !== 'Kingdom' ? 1 : 0)
		+ (($report_type ?? null) !== 'Park'    ? 1 : 0) ?>;

	var numericCols = [];
	for (var i = numericStart; i < numericStart + 7; i++) numericCols.push(i);

	var table = $('#active-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{
				extend: 'csv',
				filename: '<?= isset($activewaivereduespaid) ? "Player Activity Report" : "Active Players" ?>',
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
			if (($report_type ?? null) !== 'Park') {
				$parkCol    = ($report_type ?? null) !== 'Kingdom' ? 1 : 0;
				$sortOrder[] = [$parkCol, 'asc'];
			}
			$personaCol = (($report_type ?? null) !== 'Kingdom' ? 1 : 0)
				+ (($report_type ?? null) !== 'Park' ? 1 : 0);
			$sortOrder[] = [$personaCol, 'asc'];
			echo json_encode($sortOrder);
		?>,
		fixedHeader : { headerOffset: 48 },
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });

<?php if ($show_chart) : ?>
	/* ── Park breakdown chart ───────────────────────────── */
	new Highcharts.Chart({
		chart: { renderTo: 'active-parks-chart', type: 'bar', style: { fontFamily: 'inherit' }, marginLeft: 150 },
		title: { text: 'Active Players by Park' },
		xAxis: {
			categories: <?= json_encode($chart_parks) ?>,
			labels: { style: { fontSize: '12px' } }
		},
		yAxis: { title: { text: 'Active Players' }, allowDecimals: false, min: 0 },
		series: [{ name: 'Active Players', data: <?= json_encode($chart_counts) ?>, color: '#4338ca' }],
		legend: { enabled: false },
		credits: { enabled: false },
		plotOptions: { bar: { dataLabels: { enabled: true } } }
	});
<?php endif; ?>
});
</script>
