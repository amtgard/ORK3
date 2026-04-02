<?php
/* ── Pre-compute stats and chart data from attendance_periodical ── */
$_att_dates  = isset($attendance_periodical['Dates']) && is_array($attendance_periodical['Dates'])
	? $attendance_periodical['Dates'] : [];

$chart_dates    = [];
$chart_counts   = [];
$chart_is_event = [];
foreach ($_att_dates as $_d) {
	$chart_dates[]  = valid_id($_d['EventId'])
		? (date('Y-m-d', strtotime($_d['EventStart'])) . ' – ' . date('Y-m-d', strtotime($_d['EventEnd'])))
		: date('Y-m-d', strtotime($_d['Date']));
	$chart_counts[]   = (int)$_d['DistinctPlayers'];
	$chart_is_event[] = valid_id($_d['EventId']) ? true : false;
}

$chart_dates    = array_reverse($chart_dates);
$chart_counts   = array_reverse($chart_counts);
$chart_is_event = array_reverse($chart_is_event);

/* Total Credits comes from attendance_summary to match the table/CSV */
$_summary_dates = isset($attendance_summary['Dates']) && is_array($attendance_summary['Dates'])
	? $attendance_summary['Dates'] : [];
$_att_total   = array_sum(array_column($_summary_dates, 'Attendees'));
/* Total Weeks matches the chart's weekly-aggregated data points */
$_att_records = count($chart_counts);
$_att_avg     = $_att_records ? $_att_total / $_att_records : 0;

/* Distinct player stats from dedicated API call */
$_distinct_total  = isset($distinct_stats['TotalDistinctPlayers']) ? (int)$distinct_stats['TotalDistinctPlayers'] : 0;
$_distinct_avg_wk = isset($distinct_stats['AvgDistinctPerWeek']) ? (float)$distinct_stats['AvgDistinctPerWeek'] : 0;

/* ── Trend %: compare avg of recent 4 weeks vs prior 4 weeks ── */
$_trend_pct = null;
$_trend_dir = 'flat';
if ($_att_records >= 8) {
	$_recent4 = array_slice($chart_counts, -4);
	$_prior4  = array_slice($chart_counts, -8, 4);
	$_recent_avg = array_sum($_recent4) / 4;
	$_prior_avg  = array_sum($_prior4) / 4;
	if ($_prior_avg > 0) {
		$_trend_pct = (($_recent_avg - $_prior_avg) / $_prior_avg) * 100;
		$_trend_dir = $_trend_pct > 2 ? 'up' : ($_trend_pct < -2 ? 'down' : 'flat');
	}
} elseif ($_att_records >= 4) {
	$half = (int)floor($_att_records / 2);
	$_recent = array_slice($chart_counts, -$half);
	$_prior  = array_slice($chart_counts, 0, $half);
	$_recent_avg = array_sum($_recent) / count($_recent);
	$_prior_avg  = array_sum($_prior) / count($_prior);
	if ($_prior_avg > 0) {
		$_trend_pct = (($_recent_avg - $_prior_avg) / $_prior_avg) * 100;
		$_trend_dir = $_trend_pct > 2 ? 'up' : ($_trend_pct < -2 ? 'down' : 'flat');
	}
}

/* ── Peak week ── */
$_peak_count = 0;
$_peak_date  = '';
if ($_att_records > 0) {
	$_peak_idx   = array_keys($chart_counts, max($chart_counts))[0];
	$_peak_count = $chart_counts[$_peak_idx];
	$_peak_date  = $chart_dates[$_peak_idx];
}

/* ── Unique parks (Kingdom scope only) ── */
$_unique_parks = 0;
if ($Type === 'Kingdom' && !empty($_summary_dates)) {
	$_park_ids = [];
	foreach ($_summary_dates as $_d) {
		if (!empty($_d['ParkId']) && (int)$_d['ParkId'] > 0) {
			$_park_ids[(int)$_d['ParkId']] = true;
		}
	}
	$_unique_parks = count($_park_ids);
}

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

/* ── Split summary rows into events, park days, and kingdom-level ── */
$_event_rows    = [];
$_parkday_rows  = [];
$_kingdom_rows  = [];
if ($Type !== 'Event') {
	foreach ($attendance_summary['Dates'] as $_row) {
		if (valid_id($_row['EventId'])) {
			$_event_rows[] = $_row;
		} elseif (valid_id($_row['ParkId'])) {
			$_parkday_rows[] = $_row;
		} elseif (valid_id($_row['KingdomId'])) {
			$_kingdom_rows[] = $_row;
		}
	}
} else {
	$_parkday_rows = $attendance_summary['Dates'];
}
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
		<span>Attendance over time<?php if ($scope_label && $scope_noun !== 'scope' && $scope_noun !== 'global'): ?> for this <?=$scope_noun?><?php endif; ?>. <?php if ($_att_records > 0): ?>Bars show distinct players per week<?php $has_events = in_array(true, $chart_is_event); if ($has_events): ?> — <strong style="color:#d97706;">amber bars</strong> indicate weeks with events<?php endif; ?>. The line shows the 4-week rolling average.<?php endif; ?></span>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card" title="Number of distinct players who signed in at least once during this period.">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=number_format($_distinct_total)?></div>
			<div class="rp-stat-label">Unique Players</div>
		</div>
		<div class="rp-stat-card" title="Average number of distinct players per week across this period.">
			<div class="rp-stat-icon"><i class="fas fa-calendar-week"></i></div>
			<div class="rp-stat-number"><?=number_format($_distinct_avg_wk, 1)?></div>
			<div class="rp-stat-label">Avg Players / Week</div>
		</div>
		<div class="rp-stat-card" title="<?php if ($_trend_pct !== null): ?>Change in average attendance: recent <?=($_att_records >= 8 ? '4' : (int)floor($_att_records/2))?> weeks vs prior <?=($_att_records >= 8 ? '4' : (int)floor($_att_records/2))?> weeks.<?php else: ?>Not enough data to calculate trend (need at least 4 weeks).<?php endif; ?>">
			<div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
<?php if ($_trend_pct !== null): ?>
			<div class="rp-stat-number" style="color:<?=$_trend_dir === 'up' ? '#059669' : ($_trend_dir === 'down' ? '#dc2626' : 'var(--rp-accent)')?>;">
				<?=$_trend_dir === 'up' ? '<i class="fas fa-arrow-up" style="font-size:18px;"></i>' : ($_trend_dir === 'down' ? '<i class="fas fa-arrow-down" style="font-size:18px;"></i>' : '<i class="fas fa-minus" style="font-size:18px;"></i>')?> <?=number_format(abs($_trend_pct), 1)?>%
			</div>
<?php else: ?>
			<div class="rp-stat-number" style="font-size:16px;color:var(--rp-text-hint);">—</div>
<?php endif; ?>
			<div class="rp-stat-label">Trend</div>
		</div>
		<div class="rp-stat-card" title="Highest single-week attendance in this period.">
			<div class="rp-stat-icon"><i class="fas fa-trophy"></i></div>
			<div class="rp-stat-number"><?=$_peak_count > 0 ? number_format($_peak_count) : '—'?></div>
			<div class="rp-stat-label">Peak Week</div>
<?php if ($_peak_date): ?>
			<div style="font-size:10px;color:var(--rp-text-hint);margin-top:2px;"><?=$_peak_date?></div>
<?php endif; ?>
		</div>
<?php if ($Type === 'Kingdom'): ?>
		<div class="rp-stat-card" title="Number of distinct parks with at least one attendance record in this period.">
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=number_format($_unique_parks)?></div>
			<div class="rp-stat-label">Active Parks</div>
		</div>
<?php else: ?>
		<div class="rp-stat-card" title="Number of weeks with recorded attendance in this period.">
			<div class="rp-stat-icon"><i class="fas fa-list-ol"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_records)?></div>
			<div class="rp-stat-label">Total Weeks</div>
		</div>
<?php endif; ?>
	</div>

	<!-- Charts row -->
<?php if ($_att_records > 0): ?>
	<div class="rp-charts-row rp-charts-visible" id="rp-charts-row">
		<div id="attendance-chart" style="width:100%;height:370px;"></div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<!-- About This Chart -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-info-circle"></i> Reading the Chart</div>
				<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;color:var(--rp-text-body);">
					<p style="margin:0 0 8px;">Each <strong style="color:#4338ca;">indigo bar</strong> shows distinct players for one week.</p>
					<p style="margin:0 0 8px;"><strong style="color:#d97706;">Amber bars</strong> highlight weeks that include an event — useful for seeing how events impact turnout.</p>
					<p style="margin:0;">The <strong style="color:#059669;">green line</strong> is a 4-week rolling average that smooths out week-to-week noise so you can spot the real trend.</p>
				</div>
			</div>

			<!-- Column guide -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-columns"></i> Column Guide</div>
				<div class="rp-filter-card-body">
<?php if ($Type === 'All'): ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">The park day date.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">Kingdom where attendance was recorded.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">Park chapter where attendance was recorded.</span>
					</div>
<?php else: ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">The park day date.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Players</span>
						<span class="rp-col-guide-desc">Distinct players who signed in during this period.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Sign-ins</span>
						<span class="rp-col-guide-desc">Total attendance sign-in records for this period.</span>
					</div>
				</div>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Tables column -->
		<div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:16px;">

<?php if (!empty($_event_rows)): ?>
		<!-- Events in period -->
		<div class="rp-table-area">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
				<i class="fas fa-calendar-alt" style="color:var(--rp-accent);font-size:14px;"></i>
				<span style="font-size:13px;font-weight:700;color:var(--rp-text);">Events in Period</span>
				<span style="font-size:12px;color:var(--rp-text-muted);">(<?=count($_event_rows)?>)</span>
			</div>
			<table id="events-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Event</th>
						<th class="dt-right">Players</th>
						<th class="dt-right">Sign-ins</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_event_rows as $ev): ?>
					<tr>
						<td>
							<?=date('Y-m-d', strtotime($ev['EventStart']))?> &mdash; <?=date('m-d', strtotime($ev['EventEnd']))?>
							&mdash;
							<a href="<?=UIR?>Event/detail/<?=$ev['EventId']?>/<?=$ev['EventCalendarDetailId']?>#ev-tab-attendance"><?=htmlspecialchars($ev['EventName'])?></a><?php if (!empty($ev['ParkName'])): ?>,
							<a href="<?=UIR?>Park/profile/<?=$ev['ParkId']?>" style="color:var(--rp-text-muted);"><?=htmlspecialchars($ev['ParkName'])?></a><?php endif; ?>
						</td>
						<td class="dt-right"><?=(int)$ev['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$ev['Attendees']?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>

<?php if (!empty($_kingdom_rows)): ?>
		<!-- Kingdom-level attendance -->
		<div class="rp-table-area">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
				<i class="fas fa-chess-rook" style="color:var(--rp-accent);font-size:14px;"></i>
				<span style="font-size:13px;font-weight:700;color:var(--rp-text);">Kingdom-Level Attendance</span>
				<span style="font-size:12px;color:var(--rp-text-muted);">(<?=count($_kingdom_rows)?>)</span>
			</div>
			<p style="font-size:12px;color:var(--rp-text-muted);margin:0 0 10px;line-height:1.5;">These attendance records were entered at the kingdom level without a park or event. They may represent event credits or bulk entries that were not linked to a specific park.</p>
			<table id="kingdom-att-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
						<th class="dt-right">Players</th>
						<th class="dt-right">Sign-ins</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_kingdom_rows as $kr): ?>
					<tr>
						<td><?=date('Y-m-d', strtotime($kr['Date']))?></td>
						<td class="dt-right"><?=(int)$kr['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$kr['Attendees']?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>

		<!-- Attendance table -->
		<div class="rp-table-area">
			<table id="attendance-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
<?php if ($Type === 'All'): ?>
						<th>Kingdom</th>
						<th>Park</th>
<?php endif; ?>
						<th class="dt-right">Players</th>
						<th class="dt-right">Sign-ins</th>
					</tr>
				</thead>
				<tbody>
<?php if ($Type === 'Park'): ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Attendance/park/<?=$date['ParkId']?>&AttendanceDate=<?=$date['Date']?>"><?=date('Y-m-d', strtotime($date['Date']))?></a></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php elseif ($Type === 'Event'): ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Event/detail/<?=$date['EventId']?>/<?=$date['EventCalendarDetailId']?>#ev-tab-attendance"><?=date('Y-m-d', strtotime($date['EventStart']))?></a></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php elseif ($Type === 'Kingdom'): ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Attendance/kingdom/<?=$date['KingdomId']?>&AttendanceDate=<?=$date['Date']?>"><?=date('Y-m-d', strtotime($date['Date']))?></a></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td>
							<a href="<?=UIR?>Attendance/park/<?=$date['ParkId']?>&AttendanceDate=<?=date('Y-m-d', strtotime($date['Date']))?>">
								<?=date('Y-m-d', strtotime($date['Date']))?>
							</a>
						</td>
						<td><a href="<?=UIR?>Kingdom/profile/<?=$date['KingdomId']?>"><?=htmlspecialchars($date['KingdomName'])?></a></td>
						<td><a href="<?=UIR?>Park/profile/<?=$date['ParkId']?>"><?=htmlspecialchars($date['ParkName'])?></a></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div><!-- /.rp-table-area -->

		</div><!-- /.tables-column -->

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
		{ targets: [0], type: 'html' },
		{ targets: [1, 2], type: 'string' },
		{ targets: [3, 4], type: 'num', className: 'dt-right' }
<?php else: ?>
		{ targets: [0], type: 'html' },
		{ targets: [1, 2], type: 'num', className: 'dt-right' }
<?php endif; ?>
	];

	var dtOpts = {
		dom          : 'lfrtip',
		fixedHeader  : { headerOffset: 48 },
		columnDefs   : dtCols,
		order        : [[0, 'desc']]
	};
<?php if ($Type === 'All'): ?>
	dtOpts.scrollX      = true;
	dtOpts.fixedColumns = { left: 1 };
<?php endif; ?>
	$('#attendance-table').DataTable(dtOpts);

	/* ── Events table ── */
	if ($('#events-table').length) {
		$('#events-table').DataTable({
			dom          : 'rt',
			paging       : false,
			info         : false,
			searching    : false,
			scrollX      : true,
			columnDefs   : [
				{ targets: [0], type: 'string' },
				{ targets: [1, 2], type: 'num', className: 'dt-right' }
			],
			order        : [[0, 'asc']]
		});
	}

	/* ── Kingdom-level table ── */
	if ($('#kingdom-att-table').length) {
		$('#kingdom-att-table').DataTable({
			dom          : 'rt',
			paging       : false,
			info         : false,
			searching    : false,
			columnDefs   : [
				{ targets: [0], type: 'html' },
				{ targets: [1, 2], type: 'num', className: 'dt-right' }
			],
			order        : [[0, 'desc']]
		});
	}

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
	/* ── Chart data ── */
	var chartCounts  = <?=json_encode($chart_counts)?>;
	var chartDates   = <?=json_encode($chart_dates)?>;
	var chartIsEvent = <?=json_encode($chart_is_event)?>;

	/* Split into two column series: regular park days vs event weeks */
	var regularData = [];
	var eventData   = [];
	for (var i = 0; i < chartCounts.length; i++) {
		if (chartIsEvent[i]) {
			regularData.push(null);
			eventData.push(chartCounts[i]);
		} else {
			regularData.push(chartCounts[i]);
			eventData.push(null);
		}
	}

	/* 4-week rolling average */
	var WINDOW = 4;
	var maData = [];
	for (var i = 0; i < chartCounts.length; i++) {
		if (i < WINDOW - 1) {
			maData.push(null);
		} else {
			var sum = 0;
			for (var j = i - WINDOW + 1; j <= i; j++) sum += chartCounts[j];
			maData.push(Math.round((sum / WINDOW) * 10) / 10);
		}
	}

	new Highcharts.Chart({
		chart  : { renderTo: 'attendance-chart', style: { fontFamily: 'inherit' } },
		title  : { text: 'Distinct Players per Week' },
		xAxis  : {
			categories   : chartDates,
			tickInterval : Math.max(1, Math.ceil(chartDates.length / 12)),
			labels       : { rotation: -45, style: { fontSize: '11px' } }
		},
		yAxis  : {
			title : { text: 'Distinct Players' },
			min   : 0
		},
		tooltip: {
			shared: true,
			formatter: function () {
				var s = '<b>' + this.x + '</b>';
				this.points.forEach(function (pt) {
					/* Combine the two column series into one "Attendance" line */
					if (pt.series.type === 'column') {
						s += '<br/><span style="color:' + pt.color + '">\u25A0</span> Players: <b>' + Highcharts.numberFormat(pt.y, 0) + '</b>';
						if (pt.series.options._isEvent) {
							s += ' <span style="color:#d97706;font-style:italic;">(event week)</span>';
						}
					} else {
						s += '<br/><span style="color:' + pt.color + '">\u2014</span> '
							+ pt.series.name + ': <b>' + Highcharts.numberFormat(pt.y, 1) + '</b>';
					}
				});
				return s;
			}
		},
		plotOptions: {
			column: {
				borderRadius: 3,
				borderWidth : 0,
				groupPadding: 0,
				pointPadding: 0.05
			}
		},
		series : [
			{
				name : 'Park Days',
				type : 'column',
				data : regularData,
				color: '#4338ca',
				_isEvent: false
			},
			{
				name : 'Event Weeks',
				type : 'column',
				data : eventData,
				color: '#d97706',
				_isEvent: true
			},
			{
				name      : '4-Week Avg',
				type      : 'spline',
				data      : maData,
				color     : '#059669',
				lineWidth : 2.5,
				marker    : { enabled: false },
				dashStyle : 'ShortDash',
				zIndex    : 5,
				connectNulls: false
			}
		],
		legend : {
			enabled     : true,
			align       : 'center',
			verticalAlign: 'bottom',
			itemStyle   : { fontSize: '12px', fontWeight: '600', color: '#4a5568' },
			symbolRadius: 3
		},
		credits: { enabled: false }
	});
<?php endif; ?>
}());
</script>
