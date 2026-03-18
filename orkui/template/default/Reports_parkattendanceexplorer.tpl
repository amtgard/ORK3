<?php
$show_avg_columns = isset($form) && in_array($form['Period'], array('Quarterly', 'Annually'));
$avg_by_uniques   = isset($form) && !empty($form['AvgByUniques']);
$has_results      = isset($mode) && (
	($mode == 'all_parks'   && is_array($attendance ?? null) && count($attendance) > 0) ||
	($mode == 'single_park' && isset($players) && count($players) > 0)
);

/* Stats for all_parks mode */
$stat_total_signins    = 0;
$stat_unique_players   = 0;
$stat_unique_members   = 0;
$stat_parks            = 0;

if ($has_results && ($mode ?? '') == 'all_parks') {
	$stat_total_signins  = $summary['TotalSignins']  ?? 0;
	$stat_unique_players = $summary['UniquePlayers'] ?? 0;
	$stat_unique_members = $summary['UniqueMembers'] ?? 0;
	$stat_parks          = $summary['RowCount']       ?? 0;
} elseif ($has_results && ($mode ?? '') == 'single_park') {
	foreach ($players as $p) {
		$stat_total_signins  += $p['Total'];
		$stat_unique_players++;
	}
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
				<i class="fas fa-chart-bar rp-header-icon"></i>
				<h1 class="rp-header-title">Park Attendance Explorer</h1>
			</div>
		</div>
		<div class="rp-header-actions">
<?php if ($has_results) : ?>
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
<?php endif; ?>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Explore park attendance data by period. Select <strong>All Parks</strong> for a kingdom summary, or choose a specific park for a player-level pivot view.</span>
	</div>

<?php if ($has_results) : ?>
	<!-- ── Stats row ────────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-sign-in-alt"></i></div>
			<div class="rp-stat-number"><?=$stat_total_signins?></div>
			<div class="rp-stat-label">Total Sign-Ins</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=$stat_unique_players?></div>
			<div class="rp-stat-label">Unique Players</div>
		</div>
<?php if (($mode ?? '') == 'all_parks') : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=$stat_unique_members?></div>
			<div class="rp-stat-label">Unique Members</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=$stat_parks?></div>
			<div class="rp-stat-label">Parks</div>
		</div>
<?php endif; ?>
	</div>
<?php endif; ?>

	<!-- ── Charts placeholder ─────────────────────────────── -->
	<div class="rp-charts-row" id="rp-charts-row"></div>

	<!-- ── Body: sidebar + main ───────────────────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

<?php if (!isset($no_kingdom)) : ?>
			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-sliders-h"></i> Report Parameters
				</div>
				<div class="rp-filter-card-body">
					<form method="POST" action="<?=UIR?>Reports/park_attendance_explorer" class="rp-explorer-form">
						<div class="rp-form-group">
							<label for="StartDate">Start Date</label>
							<input type="text" id="StartDate" name="StartDate" class="datepicker rp-form-input" value="<?=htmlspecialchars($form['StartDate'] ?? '')?>" />
						</div>
						<div class="rp-form-group">
							<label for="EndDate">End Date</label>
							<input type="text" id="EndDate" name="EndDate" class="datepicker rp-form-input" value="<?=htmlspecialchars($form['EndDate'] ?? '')?>" />
						</div>
						<div class="rp-form-group">
							<label for="Period">Period</label>
							<select id="Period" name="Period" class="rp-form-input">
<?php foreach(array('Weekly','Monthly','Quarterly','Annually') as $p): ?>
								<option value="<?=$p?>"<?=($form['Period'] ?? 'Monthly') == $p ? ' selected' : ''?>><?=$p?></option>
<?php endforeach; ?>
							</select>
						</div>
						<div class="rp-form-group">
							<label for="ParkId">Park</label>
							<select id="ParkId" name="ParkId" class="rp-form-input">
								<option value="0">All Parks</option>
<?php if (is_array($parks)): ?>
<?php 	foreach($parks as $park): ?>
<?php 		if ($park['Active'] != 'Active') continue; ?>
								<option value="<?=$park['ParkId']?>"<?=($form['ParkId'] ?? 0) == $park['ParkId'] ? ' selected' : ''?>><?=htmlspecialchars($park['Name'])?></option>
<?php 	endforeach; ?>
<?php endif; ?>
							</select>
						</div>
						<div class="rp-form-group">
							<label for="MinimumSignIns">Minimum Sign-Ins</label>
							<input type="number" id="MinimumSignIns" name="MinimumSignIns" min="0" step="1" class="rp-form-input" value="<?=htmlspecialchars($form['MinimumSignIns'] ?? '0')?>" />
						</div>
						<div class="rp-form-group rp-form-check">
							<label><input type="checkbox" id="AvgByUniques" name="AvgByUniques" value="1"<?=!empty($form['AvgByUniques']) ? ' checked' : ''?>> Average by Uniques?</label>
						</div>
						<div class="rp-form-group rp-form-check" id="local-players-row" style="<?=!empty($form['ParkId']) ? '' : 'display:none;'?>">
							<label><input type="checkbox" id="LocalPlayersOnly" name="LocalPlayersOnly" value="1"<?=!empty($form['LocalPlayersOnly']) ? ' checked' : ''?>> Local Players Only?</label>
						</div>
						<div class="rp-form-group">
							<button type="submit" name="RunReport" value="1" class="rp-btn-run">Run Report</button>
						</div>
					</form>
				</div>
			</div>
<?php endif; ?>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-book-open"></i> About This Report
				</div>
				<div class="rp-filter-card-body rp-about-body">
					<p>Explore attendance data for your kingdom's parks. Choose <strong>All Parks</strong> for a kingdom-wide summary, or select a specific park for a player-detail pivot view.</p>
					<p><strong>Period</strong> groups data by Week, Month, Quarter, or Year. Dates are automatically rounded to encompass full periods.</p>
					<p><strong>Minimum Sign-Ins</strong> filters players in the single-park view to only those with at least that many total sign-ins.</p>
					<p><strong>Local Players Only</strong> (single park) restricts results to players whose home park matches the selected park.</p>
					<p><strong>Average by Uniques</strong> uses Unique Players as the numerator for Avg Weekly/Monthly instead of Total Sign-Ins.</p>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Main content area -->
		<div class="rp-table-area">

<?php if (isset($no_kingdom)) : ?>
			<p class="rp-empty-state">Please navigate to a kingdom first to use this report.</p>

<?php elseif (!isset($mode)) : ?>
			<p class="rp-empty-state">Configure the report parameters and click <strong>Run Report</strong> to view results.</p>

<?php elseif ($mode == 'all_parks' && is_array($attendance ?? null) && count($attendance) > 0) : ?>

			<h3 class="rp-section-heading">Kingdom Attendance by Park</h3>
			<table id="explorer-allparks-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Park</th>
						<th>Period</th>
						<th>Total Sign-Ins</th>
						<th>Unique Players</th>
						<th>Unique Members</th>
<?php if ($show_avg_columns) : ?>
						<th>Avg Weekly <?=$avg_by_uniques ? 'Uniques' : 'Sign-Ins'?></th>
						<th>Avg Monthly <?=$avg_by_uniques ? 'Uniques' : 'Sign-Ins'?></th>
<?php endif; ?>
						<th>Members 2+</th>
						<th>Members 3+</th>
						<th>Members 4+</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($attendance as $row) : ?>
<?php if (!valid_id($row['ParkId'])) continue; ?>
				<tr>
					<td><a href='<?=UIR.'Park/profile/'.$row['ParkId']?>'><?=htmlspecialchars($row['ParkName'])?></a></td>
					<td><?=htmlspecialchars($row['PeriodLabel'])?></td>
					<td><?=(int)$row['TotalSignins']?></td>
					<td><?=(int)$row['UniquePlayers']?></td>
					<td><?=(int)$row['UniqueMembers']?></td>
<?php if ($show_avg_columns) : ?>
<?php $avg_num = $avg_by_uniques ? $row['UniquePlayers'] : $row['TotalSignins']; ?>
					<td><?=$row['WeeksInPeriod'] > 0 ? number_format($avg_num / $row['WeeksInPeriod'], 1) : '0'?></td>
					<td><?=$row['MonthsInPeriod'] > 0 ? number_format($avg_num / $row['MonthsInPeriod'], 1) : '0'?></td>
<?php endif; ?>
					<td><?=(int)$row['Members2Plus']?></td>
					<td><?=(int)$row['Members3Plus']?></td>
					<td><?=(int)$row['Members4Plus']?></td>
				</tr>
<?php endforeach; ?>
<?php if (isset($summary)) : ?>
				<tr class="rp-row-summary">
					<td><strong>Kingdom Totals</strong></td>
					<td></td>
					<td><strong><?=(int)$summary['TotalSignins']?></strong></td>
					<td><strong><?=(int)$summary['UniquePlayers']?></strong></td>
					<td><strong><?=(int)$summary['UniqueMembers']?></strong></td>
<?php if ($show_avg_columns) : ?>
<?php $avg_sum_num = $avg_by_uniques ? $summary['UniquePlayers'] : $summary['TotalSignins']; ?>
					<td><strong><?=$summary['WeeksInPeriod'] > 0 ? number_format($avg_sum_num / $summary['WeeksInPeriod'], 1) : '0'?></strong></td>
					<td><strong><?=$summary['MonthsInPeriod'] > 0 ? number_format($avg_sum_num / $summary['MonthsInPeriod'], 1) : '0'?></strong></td>
<?php endif; ?>
					<td><strong><?=(int)$summary['Members2Plus']?></strong></td>
					<td><strong><?=(int)$summary['Members3Plus']?></strong></td>
					<td><strong><?=(int)$summary['Members4Plus']?></strong></td>
				</tr>
<?php endif; ?>
				</tbody>
			</table>

<?php elseif ($mode == 'single_park' && isset($players) && count($players) > 0) : ?>

			<h3 class="rp-section-heading">Player Attendance Detail</h3>
			<table id="explorer-singlepark-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Player</th>
						<th>Waivered</th>
						<th>Dues Paid</th>
<?php foreach ($all_periods as $period) : ?>
						<th><?=htmlspecialchars($period)?></th>
<?php endforeach; ?>
						<th>Total</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($players as $player) : ?>
				<tr>
					<td><a href='<?=UIR.'Player/profile/'.$player['MundaneId']?>'><?=htmlspecialchars($player['Persona'])?></a></td>
					<td><?=$player['Waivered'] ? 'Yes' : 'No'?></td>
					<td><?=$player['DuesPaid'] ? htmlspecialchars($player['DuesPaid']) : ''?></td>
<?php 	foreach ($all_periods as $period) : ?>
					<td><?=(int)($player['Periods'][$period] ?? 0)?></td>
<?php 	endforeach; ?>
					<td><strong><?=(int)$player['Total']?></strong></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>

<?php else : ?>
			<p class="rp-empty-state">No attendance data found for the selected criteria.</p>
<?php endif; ?>

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
	$('#StartDate, #EndDate').datepicker({ dateFormat: 'yy-mm-dd' });

	$('#ParkId').on('change', function() {
		var parkId = parseInt($(this).val(), 10);
		if (parkId > 0) {
			$('#local-players-row').show();
		} else {
			$('#local-players-row').hide();
			$('#LocalPlayersOnly').prop('checked', false);
		}
	});

<?php if (($mode ?? '') == 'all_parks' && !empty($attendance)) : ?>
	var numericCols = [];
	for (var i = 2; i < 2 + <?= 3 + ($show_avg_columns ? 2 : 0) + 3 ?>; i++) numericCols.push(i);

	var apTable = $('#explorer-allparks-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Park Attendance Explorer', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: numericCols, type: 'num', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[0, 'asc'], [1, 'asc']],
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 },
		drawCallback: function() {
			var body = $(this.api().table().body());
			body.find('tr.rp-row-summary').appendTo(body);
		}
	});
	$('.rp-btn-export').on('click', function() { apTable.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { apTable.button(1).trigger(); });

<?php elseif (($mode ?? '') == 'single_park' && !empty($players)) : ?>
	var periodCount = <?= count($all_periods ?? []) ?>;
	var spNumericCols = [];
	for (var j = 3; j < 3 + periodCount + 1; j++) spNumericCols.push(j);

	var spTable = $('#explorer-singlepark-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Park Attendance Explorer', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: spNumericCols, type: 'num', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[-1, 'desc'], [0, 'asc']],
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});
	$('.rp-btn-export').on('click', function() { spTable.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { spTable.button(1).trigger(); });
<?php endif; ?>
});
</script>

<style>
/* ── Explorer-specific styles ────────────────────────────── */
.rp-explorer-form { display: flex; flex-direction: column; gap: 10px; }
.rp-form-group    { display: flex; flex-direction: column; gap: 4px; }
.rp-form-group label { font-size: 0.8rem; font-weight: 600; color: var(--rp-text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.rp-form-input    { padding: 6px 8px; border: 1px solid var(--rp-border); border-radius: 4px; font-size: 0.9rem; width: 100%; box-sizing: border-box; }
.rp-form-check    { flex-direction: row; align-items: center; }
.rp-form-check label { font-size: 0.85rem; text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--rp-text-body); display: flex; align-items: center; gap: 6px; cursor: pointer; }
.rp-btn-run       { background: var(--rp-accent); color: #fff; border: none; padding: 8px 16px; border-radius: 5px; font-weight: 600; cursor: pointer; width: 100%; font-size: 0.9rem; }
.rp-btn-run:hover { background: var(--rp-accent-mid); }
.rp-about-body    { font-size: 0.82rem; color: var(--rp-text-body); line-height: 1.5; }
.rp-about-body p  { margin: 0 0 8px; }
.rp-section-heading { font-size: 1rem; font-weight: 700; color: var(--rp-text); margin: 0 0 12px; padding: 0; background: none; border: none; }
.rp-empty-state   { color: var(--rp-text-muted); font-style: italic; padding: 24px 0; text-align: center; }
.rp-row-summary td { background-color: #f7fafc !important; font-weight: 600; border-top: 2px solid var(--rp-border) !important; }
</style>
