<?php
/* ── Pre-compute totals when summary exists ── */
$_npa_has_summary = isset($summary) && is_array($summary) && count($summary) > 0;

if ($_npa_has_summary) {
	if (!isset($totals) || !is_array($totals)) {
		$totals = ['NewPlayers' => 0, 'ReturningPlayers' => 0, 'NewPlayerVisits' => 0];
		foreach ($summary as $_row) {
			$totals['NewPlayers']       += (int)$_row['NewPlayers'];
			$totals['ReturningPlayers'] += (int)$_row['ReturningPlayers'];
			$totals['NewPlayerVisits']  += (int)$_row['NewPlayerVisits'];
		}
		$totals['ReturnPct']             = $totals['NewPlayers'] > 0
			? round(($totals['ReturningPlayers'] / $totals['NewPlayers']) * 100, 1) : 0;
		$totals['AvgVisitsPerNewPlayer'] = $totals['NewPlayers'] > 0
			? round($totals['NewPlayerVisits'] / $totals['NewPlayers'], 2) : 0;
	}

	/* chart data */
	$chart_parks     = array_column($summary, 'ParkName');
	$chart_new       = array_map('intval', array_column($summary, 'NewPlayers'));
	$chart_returning = array_map('intval', array_column($summary, 'ReturningPlayers'));
}

$_npa_has_details = isset($player_details) && is_array($player_details) && count($player_details) > 0;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">

<style>
.rp-param-form { display: flex; flex-direction: column; gap: 10px; }
.rp-form-group { display: flex; flex-direction: column; gap: 3px; }
.rp-form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); }
.rp-form-input, .rp-form-select { width: 100%; border: 1px solid var(--rp-border); border-radius: 5px; padding: 6px 8px; font-size: 13px; color: var(--rp-text); box-sizing: border-box; }
.rp-form-input:focus, .rp-form-select:focus { outline: none; border-color: #6366f1; }
.rp-form-check { display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--rp-text-body); }
.rp-btn-run { width: 100%; padding: 8px 0; background: #4338ca; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.rp-btn-run:hover { background: #3730a3; }
.rp-table-section-title { font-size: 14px; font-weight: 700; color: var(--rp-text); margin: 20px 0 10px; padding-bottom: 6px; border-bottom: 2px solid var(--rp-border); }
.rp-table-section-title:first-child { margin-top: 0; }
.rp-empty-state { padding: 32px 16px; text-align: center; color: var(--rp-text-muted); font-size: 14px; }
.rp-empty-state i { font-size: 28px; display: block; margin-bottom: 10px; opacity: 0.4; }
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-user-plus rp-header-icon"></i>
				<h1 class="rp-header-title">New Player Attendance</h1>
			</div>
		</div>
		<div class="rp-header-actions">
<?php if ($_npa_has_summary): ?>
			<button class="rp-btn-ghost" id="rp-btn-csv-summary"><i class="fas fa-download"></i> Export CSV</button>
<?php endif; ?>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Track new player acquisition and retention for parks within this kingdom.</span>
	</div>

	<!-- Stats row (only when results exist) -->
<?php if ($_npa_has_summary): ?>
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-plus"></i></div>
			<div class="rp-stat-number"><?=number_format($totals['NewPlayers'])?></div>
			<div class="rp-stat-label">Total New Players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-redo-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($totals['ReturningPlayers'])?></div>
			<div class="rp-stat-label">Total Returning</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percentage"></i></div>
			<div class="rp-stat-number"><?=$totals['ReturnPct']?>%</div>
			<div class="rp-stat-label">Overall Return %</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-check"></i></div>
			<div class="rp-stat-number"><?=number_format($totals['AvgVisitsPerNewPlayer'], 2)?></div>
			<div class="rp-stat-label">Avg Visits / New Player</div>
		</div>
	</div>

	<!-- Charts row -->
	<div class="rp-charts-row rp-charts-visible" id="rp-charts-row">
		<div id="npa-chart" style="width:100%;height:300px;"></div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<!-- Report Parameters card -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sliders-h"></i> Report Parameters</div>
				<div class="rp-filter-card-body">
<?php if (isset($no_kingdom)): ?>
					<p style="font-size:12px;color:var(--rp-text-muted);margin:0;">Please navigate to a kingdom first to use this report.</p>
<?php else: ?>
					<form method="POST" action="<?=UIR?>Reports/new_player_attendance" class="rp-param-form">
						<div class="rp-form-group">
							<label for="StartDate">Start Date</label>
							<input type="text" id="StartDate" name="StartDate" class="datepicker rp-form-input" value="<?=htmlspecialchars($form['StartDate'] ?? '')?>">
						</div>
						<div class="rp-form-group">
							<label for="EndDate">End Date</label>
							<input type="text" id="EndDate" name="EndDate" class="datepicker rp-form-input" value="<?=htmlspecialchars($form['EndDate'] ?? '')?>">
						</div>
						<div class="rp-form-group">
							<label for="ParkId">Park</label>
							<select id="ParkId" name="ParkId" class="rp-form-select">
								<option value="0">All Parks</option>
<?php if (is_array($parks)): ?>
<?php foreach ($parks as $park): ?>
<?php if ($park['Active'] != 'Active') continue; ?>
								<option value="<?=$park['ParkId']?>"<?=($form['ParkId'] ?? 0) == $park['ParkId'] ? ' selected' : ''?>><?=htmlspecialchars($park['Name'])?></option>
<?php endforeach; ?>
<?php endif; ?>
							</select>
						</div>
						<div class="rp-form-group">
							<label class="rp-form-check">
								<input type="checkbox" name="ShowPlayerDetails" value="1"<?=!empty($form['ShowPlayerDetails']) ? ' checked' : ''?>>
								Show Player Details
							</label>
						</div>
						<button type="submit" name="RunReport" value="1" class="rp-btn-run">Run Report</button>
					</form>
<?php endif; ?>
				</div>
			</div>

			<!-- About This Report card (collapsible) -->
			<div class="rp-filter-card">
				<details open>
					<summary class="rp-filter-card-header" style="cursor:pointer;">
						<i class="fas fa-info-circle"></i> About This Report
					</summary>
					<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;">
						<dl style="margin:0;color:var(--rp-text-body);">
							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">New Players</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">A count of players whose very first sign-in in the entire ORK system falls within the selected date range. Players who played elsewhere before this period are not counted, even if this is their first visit to this kingdom.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Park Attribution</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">Each new player is credited to the park where their first sign-in occurred. If a player signed in at multiple parks on the same day as their first-ever sign-in, the park with the lowest system ID is used as the tiebreaker.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Returning Players</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">Of the new players identified above, a count of those who signed in two or more times during the selected date range. This measures same-period retention — how many newcomers came back at least once before the period ended.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Return %</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">Returning Players ÷ New Players × 100. A higher percentage indicates that more new players returned for a second visit within the period.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">New Player Visits</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">The total number of individual sign-in events (attendance rows) for all new players during the date range. Each sign-in on a distinct date counts as one visit — the credits field is not summed.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Avg Visits / New Player</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">New Player Visits ÷ New Players. Indicates how engaged new players were on average during the period.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Last Sign-in Date (Player Details)</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">The most recent sign-in date for each player across all time, not limited to the report period. This lets you see whether a new player from a past muster is still active today.</dd>
						</dl>
					</div>
				</details>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
<?php if (isset($no_kingdom)): ?>
			<div class="rp-empty-state">
				<i class="fas fa-chess-rook"></i>
				Please navigate to a kingdom first to use this report.
			</div>
<?php elseif (!isset($summary)): ?>
			<div class="rp-empty-state">
				<i class="fas fa-filter"></i>
				Configure parameters and click Run Report.
			</div>
<?php elseif (!$_npa_has_summary): ?>
			<div class="rp-empty-state">
				<i class="fas fa-search"></i>
				No new players found for the selected date range and park.
			</div>
<?php else: ?>
			<!-- Summary table -->
			<div class="rp-table-section-title">New Player Attendance Summary</div>
			<table id="npa-summary-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Park Name</th>
						<th class="dt-right">New Players</th>
						<th class="dt-right">Returning Players</th>
						<th class="dt-right">Return %</th>
						<th class="dt-right">New Player Visits</th>
						<th class="dt-right">Avg Visits / New Player</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($summary as $row): ?>
					<tr>
						<td><?=htmlspecialchars($row['ParkName'])?></td>
						<td class="dt-right"><?=(int)$row['NewPlayers']?></td>
						<td class="dt-right"><?=(int)$row['ReturningPlayers']?></td>
						<td class="dt-right"><?=$row['ReturnPct']?>%</td>
						<td class="dt-right"><?=(int)$row['NewPlayerVisits']?></td>
						<td class="dt-right"><?=number_format($row['AvgVisitsPerNewPlayer'], 2)?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
<?php if (count($summary) > 1): ?>
				<tfoot>
					<tr>
						<td><strong>Total</strong></td>
						<td class="dt-right"><strong><?=$totals['NewPlayers']?></strong></td>
						<td class="dt-right"><strong><?=$totals['ReturningPlayers']?></strong></td>
						<td class="dt-right"><strong><?=$totals['ReturnPct']?>%</strong></td>
						<td class="dt-right"><strong><?=$totals['NewPlayerVisits']?></strong></td>
						<td class="dt-right"><strong><?=number_format($totals['AvgVisitsPerNewPlayer'], 2)?></strong></td>
					</tr>
				</tfoot>
<?php endif; ?>
			</table>

<?php if ($_npa_has_details): ?>
			<!-- Player details table -->
			<div class="rp-table-section-title" style="margin-top:28px;">Player Details</div>
			<table id="npa-details-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Park</th>
						<th>Persona</th>
						<th class="dt-right">First Sign-in</th>
						<th class="dt-right">Visits in Period</th>
						<th class="dt-right">Last Sign-in</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($player_details as $player): ?>
					<tr>
						<td><?=htmlspecialchars($player['ParkName'])?></td>
						<td><a href="<?=UIR?>Player/index/<?=$player['MundaneId']?>"><?=htmlspecialchars($player['Persona'])?></a></td>
						<td class="dt-right"><?=htmlspecialchars($player['FirstSignInDate'])?></td>
						<td class="dt-right"><?=(int)$player['VisitsInPeriod']?></td>
						<td class="dt-right"><?=htmlspecialchars($player['LastSignInDate'])?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>

<?php endif; ?>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
(function () {
	/* ── Datepicker ── */
	$('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' });

<?php if ($_npa_has_summary): ?>
	/* ── Summary DataTable ── */
	$('#npa-summary-table').DataTable({
		dom        : 'lfrtip',
		scrollX    : true,
		fixedHeader: { headerOffset: 48 },
		pageLength : 25,
		columnDefs : [
			{ targets: [0], type: 'string' },
			{ targets: [1, 2, 4], type: 'num', className: 'dt-right' },
			{ targets: [3, 5],    type: 'num', className: 'dt-right' }
		]
	});

	/* ── Export CSV ── */
	$('#rp-btn-csv-summary').on('click', function () {
		var dt = $('#npa-summary-table').DataTable();
		var csv = [];
		var headers = [];
		$('#npa-summary-table thead th').each(function () { headers.push($(this).text().trim()); });
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
		a.download = 'new-player-attendance.csv';
		a.click();
	});

<?php if ($_npa_has_details): ?>
	/* ── Details DataTable ── */
	$('#npa-details-table').DataTable({
		dom        : 'lfrtip',
		scrollX    : true,
		fixedHeader: { headerOffset: 48 },
		pageLength : 25,
		columnDefs : [
			{ targets: [0, 1], type: 'string' },
			{ targets: [2, 4], type: 'date',   className: 'dt-right' },
			{ targets: [3],    type: 'num',    className: 'dt-right' }
		]
	});
<?php endif; ?>

	/* ── Highcharts grouped bar chart ── */
	new Highcharts.Chart({
		chart  : { renderTo: 'npa-chart', type: 'column', style: { fontFamily: 'inherit' } },
		title  : { text: 'New vs Returning Players by Park' },
		xAxis  : {
			categories: <?=json_encode($chart_parks)?>,
			labels    : { rotation: -30, style: { fontSize: '11px' } }
		},
		yAxis  : { title: { text: 'Players' }, allowDecimals: false, min: 0 },
		series : [
			{ name: 'New Players',       data: <?=json_encode($chart_new)?>,       color: '#4338ca' },
			{ name: 'Returning Players', data: <?=json_encode($chart_returning)?>,  color: '#10b981' }
		],
		credits: { enabled: false },
		legend : { enabled: true }
	});
<?php endif; ?>
}());
</script>
