<?php
/* ── Pre-compute stats and chart data from attendance_periodical ── */
$_att_dates  = isset($attendance_periodical['Dates']) && is_array($attendance_periodical['Dates'])
	? $attendance_periodical['Dates'] : [];

$chart_dates  = [];
$chart_counts = [];
foreach ($_att_dates as $_d) {
	$chart_dates[]  = valid_id($_d['EventId'])
		? (date('Y-m-d', strtotime($_d['EventStart'])) . ' – ' . date('Y-m-d', strtotime($_d['EventEnd'])))
		: date('Y-m-d', strtotime($_d['Date']));
	$chart_counts[] = (int)$_d['Attendees'];
}

$chart_dates  = array_reverse($chart_dates);
$chart_counts = array_reverse($chart_counts);

/* Total Credits comes from attendance_summary to match the table/CSV */
$_summary_dates = isset($attendance_summary['Dates']) && is_array($attendance_summary['Dates'])
	? $attendance_summary['Dates'] : [];
$_att_total   = array_sum(array_column($_summary_dates, 'Attendees'));
/* Total Weeks matches the SPC chart's weekly-aggregated data points */
$_att_records = count($chart_counts);
/* Mean and sigma use periodical chart data to stay consistent with the SPC chart */
$_chart_count = count($chart_counts);
$_att_mean    = $_chart_count ? array_sum($chart_counts) / $_chart_count : 0;
$_att_variance = 0;
foreach ($chart_counts as $_c) {
	$_att_variance += pow($_c - $_att_mean, 2);
}
$_att_variance = $_chart_count ? $_att_variance / $_chart_count : 0;
$_att_sigma   = sqrt($_att_variance);

/* ── Scope chip ── */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';
if ($Type === 'Park') {
	$_first = !empty($attendance_summary['Dates']) ? $attendance_summary['Dates'][0] : [];
	$scope_label = htmlspecialchars($_first['ParkName'] ?? 'Park');
	$scope_link  = UIR . 'Park/profile/' . (isset($_first['ParkId']) ? $_first['ParkId'] : '');
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif ($Type === 'Kingdom') {
	$_first = !empty($attendance_summary['Dates']) ? $attendance_summary['Dates'][0] : [];
	$scope_label = htmlspecialchars($_first['KingdomName'] ?? 'Kingdom');
	$scope_link  = UIR . 'Kingdom/profile/' . (isset($_first['KingdomId']) ? $_first['KingdomId'] : '');
	$scope_icon  = 'fa-chess-rook';
	$scope_noun  = 'kingdom';
} elseif ($Type === 'Event') {
	$scope_label = 'Event';
	$scope_icon  = 'fa-calendar-alt';
	$scope_noun  = 'event';
} else {
	$scope_label = 'All';
	$scope_noun  = 'global';
}

if (!is_array($attendance_summary['Dates'])) $attendance_summary['Dates'] = [];
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-users rp-header-icon"></i>
				<h1 class="rp-header-title">Attendance</h1>
			</div>
<?php if ($scope_label): ?>
			<div class="rp-header-scope">
				<span class="rp-scope-chip-label">Scope:</span>
<?php if ($scope_link): ?>
				<a href="<?=$scope_link?>" class="rp-scope-chip">
					<i class="fas <?=$scope_icon?>"></i>
					<?=$scope_label?>
				</a>
<?php else: ?>
				<span class="rp-scope-chip">
					<i class="fas <?=$scope_icon?>"></i>
					<?=$scope_label?>
				</span>
<?php endif; ?>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" id="rp-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Attendance records over time<?php if ($scope_label && $scope_noun !== 'scope' && $scope_noun !== 'global'): ?> for this <?=$scope_noun?><?php endif; ?>. The SPC chart highlights periods that deviate significantly from the mean.</span>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card" title="Number of weeks with recorded attendance in this period.">
			<div class="rp-stat-icon"><i class="fas fa-list-ol"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_records)?></div>
			<div class="rp-stat-label">Total Weeks</div>
		</div>
		<div class="rp-stat-card" title="Sum of all attendance sign-ins across every park day in this period.">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_total)?></div>
			<div class="rp-stat-label">Total Credits</div>
		</div>
		<div class="rp-stat-card" title="Total credits divided by weeks with attendance. Note: the Kingdom page Avg/Week divides by a fixed 26-week window including weeks with no activity.">
			<div class="rp-stat-icon"><i class="fas fa-calendar-week"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_records ? $_att_total / $_att_records : 0, 1)?></div>
			<div class="rp-stat-label">Avg per Week <i class="fas fa-info-circle" style="font-size:11px;opacity:0.5"></i></div>
		</div>
		<div class="rp-stat-card" title="Mean of weekly attendance totals, used as the SPC chart centerline.">
			<div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_mean, 1)?></div>
			<div class="rp-stat-label">Mean Attendance</div>
		</div>
		<div class="rp-stat-card" title="Population standard deviation of weekly totals, used for SPC control limit lines (±1σ, ±2σ).">
			<div class="rp-stat-icon"><i class="fas fa-ruler-horizontal"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_sigma, 1)?></div>
			<div class="rp-stat-label">Std Deviation (σ)</div>
		</div>
	</div>

	<!-- Charts row -->
<?php if ($_att_records > 0): ?>
	<div class="rp-charts-row rp-charts-visible" id="rp-charts-row">
		<div id="attendance-spc-chart" style="width:100%;height:340px;"></div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<!-- About This Chart -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-info-circle"></i> About This Chart</div>
				<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;color:var(--rp-text-body);">
					<p style="margin:0 0 8px;">This is a <strong>Statistical Process Control (SPC)</strong> chart. The dark center line marks the mean attendance.</p>
					<p style="margin:0 0 8px;">The <strong style="color:#92740a;">amber dashed lines</strong> mark ±1 standard deviation (σ) — roughly 68% of periods should fall within this band under normal variation.</p>
					<p style="margin:0;">The <strong style="color:#E02810;">red dashed lines</strong> mark ±2σ — about 95% of periods should fall within this range. Points outside the ±2σ lines may indicate unusual events worth investigating.</p>
				</div>
			</div>

			<!-- Column guide -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-columns"></i> Column Guide</div>
				<div class="rp-filter-card-body">
<?php if ($Type === 'All'): ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">The park day or event date range.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">Kingdom where attendance was recorded.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">Park chapter where attendance was recorded.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Event</span>
						<span class="rp-col-guide-desc">Event name, if applicable.</span>
					</div>
<?php else: ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">The park day or event date range.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Count</span>
						<span class="rp-col-guide-desc">Number of attendees recorded for this period.</span>
					</div>
				</div>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="attendance-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
<?php if ($Type === 'All'): ?>
						<th>Kingdom</th>
						<th>Park</th>
						<th>Event</th>
<?php endif; ?>
						<th class="dt-right">Count</th>
					</tr>
				</thead>
				<tbody>
<?php if ($Type === 'Park'): ?>
<?php foreach ($attendance_summary['Dates'] as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Attendance/park/<?=$date['ParkId']?>&AttendanceDate=<?=$date['Date']?>"><?=valid_id($date['EventId']) ? (date('Y-m-d', strtotime($date['EventStart'])) . ' &mdash; ' . date('Y-m-d', strtotime($date['EventEnd']))) : date('Y-m-d', strtotime($date['Date']))?></a></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php elseif ($Type === 'Event'): ?>
<?php foreach ($attendance_summary['Dates'] as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Event/detail/<?=$date['EventId']?>/<?=$date['EventCalendarDetailId']?>#ev-tab-attendance"><?=date('Y-m-d', strtotime($date['EventStart']))?></a></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php elseif ($Type === 'Kingdom'): ?>
<?php foreach ($attendance_summary['Dates'] as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Attendance/kingdom/<?=$date['KingdomId']?>&AttendanceDate=<?=$date['Date']?>"><?=valid_id($date['EventId']) ? (date('Y-m-d', strtotime($date['EventStart'])) . ' &mdash; ' . date('m-d', strtotime($date['EventEnd']))) : date('Y-m-d', strtotime($date['Date']))?></a></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($attendance_summary['Dates'] as $date): ?>
					<tr>
						<td>
							<a href="<?=$date['ParkId'] > 0 ? (UIR . 'Attendance/park/' . $date['ParkId'] . '&AttendanceDate=' . date('Y-m-d', strtotime($date['Date']))) : (UIR . 'Event/detail/' . $date['EventId'] . '/' . $date['EventCalendarDetailId'] . '#ev-tab-attendance')?>">
								<?=valid_id($date['EventId']) ? (date('Y-m-d', strtotime($date['EventStart'])) . ' &mdash; ' . date('m-d', strtotime($date['EventEnd']))) : date('Y-m-d', strtotime($date['Date']))?>
							</a>
						</td>
						<td><a href="<?=UIR?>Kingdom/profile/<?=$date['KingdomId']?>"><?=htmlspecialchars($date['KingdomName'])?></a></td>
						<td><a href="<?=UIR?>Park/profile/<?=$date['ParkId']?>"><?=htmlspecialchars($date['ParkName'])?></a></td>
						<td><a href="<?=UIR?>Event/index/<?=$date['EventId']?>"><?=htmlspecialchars($date['EventName'])?></a></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
(function () {
	/* ── DataTable ── */
	var dtCols = [
<?php if ($Type === 'All'): ?>
		{ targets: [0], type: 'date' },
		{ targets: [1, 2, 3], type: 'string' },
		{ targets: [4], type: 'num', className: 'dt-right' }
<?php else: ?>
		{ targets: [0], type: 'date' },
		{ targets: [1], type: 'num', className: 'dt-right' }
<?php endif; ?>
	];

	$('#attendance-table').DataTable({
		dom          : 'lfrtip',
		scrollX      : true,
		fixedHeader  : { headerOffset: 48 },
		fixedColumns : { left: 1 },
		columnDefs   : dtCols,
		order        : [[0, 'desc']]
	});

	/* ── Export CSV via DataTables button ── */
	$('#rp-btn-csv').on('click', function () {
		var dt = $('#attendance-table').DataTable();
		var csv = [];
		var headers = [];
		$('#attendance-table thead th').each(function () { headers.push($(this).text().trim()); });
		csv.push(headers.join(','));
		dt.rows({ search: 'applied' }).every(function () {
			var row = this.data();
			var cells = [];
			$.each(row, function (i, v) {
				var text = $('<div>').html(v).text().replace(/"/g, '""');
				cells.push('"' + text + '"');
			});
			csv.push(cells.join(','));
		});
		var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = 'attendance.csv';
		a.click();
	});

<?php if ($_att_records > 0): ?>
	/* ── Highcharts SPC chart ── */
	var chartData  = <?=json_encode($chart_counts)?>;
	var chartDates = <?=json_encode($chart_dates)?>;

	var sum      = chartData.reduce(function (a, b) { return a + b; }, 0);
	var mean     = chartData.length ? sum / chartData.length : 0;
	var variance = chartData.length
		? chartData.reduce(function (a, b) { return a + Math.pow(b - mean, 2); }, 0) / chartData.length
		: 0;
	var sigma = Math.sqrt(variance);

	new Highcharts.Chart({
		chart  : { renderTo: 'attendance-spc-chart', type: 'line', style: { fontFamily: 'inherit' } },
		title  : { text: 'Attendance Over Time (Weekly Totals)' },
		xAxis  : {
			categories : chartDates,
			tickInterval: Math.ceil(chartDates.length / 12),
			labels     : { rotation: -45, style: { fontSize: '11px' } }
		},
		yAxis  : {
			title : { text: 'Attendees' },
			min   : 0,
			plotLines: [
				{ value: mean,             color: '#374151', width: 2, dashStyle: 'Solid', zIndex: 5, label: { text: 'Mean: '  + mean.toFixed(1),              align: 'right', style: { color: '#374151', fontWeight: '600', fontSize: '11px' } } },
				{ value: mean + sigma,     color: '#FCCA00', width: 2, dashStyle: 'Dash',  zIndex: 4, label: { text: '+1σ: '   + (mean + sigma).toFixed(1),     align: 'right', style: { color: '#92740a', fontSize: '11px' } } },
				{ value: mean - sigma,     color: '#FCCA00', width: 2, dashStyle: 'Dash',  zIndex: 4, label: { text: '−1σ: '   + (mean - sigma).toFixed(1),     align: 'right', style: { color: '#92740a', fontSize: '11px' } } },
				{ value: mean + 2 * sigma, color: '#E02810', width: 2, dashStyle: 'Dash',  zIndex: 3, label: { text: '+2σ: '   + (mean + 2 * sigma).toFixed(1), align: 'right', style: { color: '#E02810', fontSize: '11px' } } },
				{ value: mean - 2 * sigma, color: '#E02810', width: 2, dashStyle: 'Dash',  zIndex: 3, label: { text: '−2σ: '   + (mean - 2 * sigma).toFixed(1), align: 'right', style: { color: '#E02810', fontSize: '11px' } } }
			]
		},
		series : [{ name: 'Attendance', data: chartData, color: '#4338ca', lineWidth: 2, marker: { radius: 3 } }],
		legend : { enabled: false },
		credits: { enabled: false }
	});
<?php endif; ?>
}());
</script>
