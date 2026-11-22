<?php
// Park Activity: one row per park with its last attendance and sign-in totals
// over a trailing window. Ported from jsork's "Freehold Activity" page.
$_has_data   = is_array($Parks) && count($Parks) > 0;
$_all_kd     = empty($KingdomId);
$_kd_name    = $_all_kd ? 'all kingdoms' : ($Kingdoms[$KingdomId] ?? '');
$_never      = $_has_data ? count(array_filter($Parks, fn($p) => empty($p['LastAttendance']))) : 0;
$_quiet      = $_has_data ? count(array_filter($Parks, fn($p) => !empty($p['LastAttendance']) && (int)$p['DaysInactive'] > 90)) : 0;
$_attend_sum = $_has_data ? array_sum(array_column($Parks, 'Attendances')) : 0;
$_csv_name   = 'park-activity-' . ($_all_kd ? 'all' : preg_replace('/[^a-z0-9]+/i', '-', strtolower($_kd_name))) . '-' . $ReportDate . '.csv';
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<style>
.rp-param-form { display: flex; flex-direction: column; gap: 10px; }
.rp-form-group { display: flex; flex-direction: column; gap: 3px; }
.rp-form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); }
.rp-form-input { width: 100%; border: 1px solid var(--ork-input-border); border-radius: 5px; padding: 6px 8px; font-size: 13px; color: var(--rp-text); background: var(--ork-input-bg); box-sizing: border-box; }
.rp-form-input:focus { outline: none; background: var(--ork-card-bg); border-color: #6366f1; }
.rp-form-check { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--rp-text); }
.rp-btn-run { width: 100%; padding: 8px 0; background: #4338ca; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.rp-btn-run:hover { background: #3730a3; }
.pa-num { text-align: right; font-variant-numeric: tabular-nums; }
.pa-muted { color: var(--rp-text-muted); }
.pa-warn { color: #b45309; font-weight: 600; }
.pa-never { color: #b91c1c; font-weight: 600; }
.pa-retired { font-size: 11px; padding: 1px 6px; border-radius: 10px; background: var(--rp-text-muted); color: #fff; margin-left: 6px; vertical-align: middle; }
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-chart-line rp-header-icon"></i>
				<h1 class="rp-header-title">Park Activity</h1>
			</div>
		</div>
		<div class="rp-header-actions">
<?php if ($_has_data): ?>
			<button class="rp-btn-ghost" id="pa-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
<?php endif; ?>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>
			<?= $IncludeRetired ? 'All' : 'All <strong>Active</strong>' ?> parks in <strong><?=htmlspecialchars($_kd_name)?></strong>
			with their most recent attendance and sign-in totals for the last <strong><?=(int)$Months?> months</strong>
			(<?=htmlspecialchars($WindowStart)?> to <?=htmlspecialchars($ReportDate)?>).
			<?= $_has_data ? count($Parks) . ' parks.' : '' ?>
			Generated <?=htmlspecialchars($ReportDate)?>.
		</span>
	</div>

<?php if ($_has_data): ?>
	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
			<div class="rp-stat-number"><?=count($Parks)?></div>
			<div class="rp-stat-label">Parks</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-clipboard-check"></i></div>
			<div class="rp-stat-number"><?=number_format($_attend_sum)?></div>
			<div class="rp-stat-label">Attendances (<?=(int)$Months?> mo)</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-hourglass-half"></i></div>
			<div class="rp-stat-number"><?=$_quiet?></div>
			<div class="rp-stat-label">Quiet &gt; 90 Days</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-times"></i></div>
			<div class="rp-stat-number"><?=$_never?></div>
			<div class="rp-stat-label">No Attendance Ever</div>
		</div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sliders-h"></i> Filter</div>
				<div class="rp-filter-card-body">
					<form method="GET" action="<?=UIR?>Admin/parkactivity" class="rp-param-form">
						<input type="hidden" name="Route" value="Admin/parkactivity">
						<div class="rp-form-group">
							<label for="KingdomId">Kingdom</label>
							<select id="KingdomId" name="KingdomId" class="rp-form-input">
								<?php if (!empty($IsAdmin)): ?>
								<option value="0">All Kingdoms</option>
								<?php endif; ?>
								<?php foreach ($Kingdoms as $_kid => $_kname): ?>
								<option value="<?=(int)$_kid?>" <?=$KingdomId == $_kid ? 'selected' : ''?>><?=htmlspecialchars($_kname)?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="rp-form-group">
							<label for="Months">Window</label>
							<select id="Months" name="Months" class="rp-form-input">
								<?php foreach (array(12 => 'Last 12 months', 24 => 'Last 24 months', 36 => 'Last 36 months', 60 => 'Last 5 years') as $_m => $_label): ?>
								<option value="<?=$_m?>" <?=(int)$Months === $_m ? 'selected' : ''?>><?=$_label?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<label class="rp-form-check">
							<input type="checkbox" name="IncludeRetired" value="1" <?=$IncludeRetired ? 'checked' : ''?>>
							Include retired parks
						</label>
						<button type="submit" class="rp-btn-run">Run Report</button>
					</form>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-question-circle"></i> Columns</div>
				<div class="rp-filter-card-body" style="font-size:12px;color:var(--rp-text-muted);line-height:1.5;">
					<strong>Last Attendance</strong> is the most recent sign-in date ever recorded at the park.<br>
					<strong>Days Inactive</strong> counts from that date to today.<br>
					<strong>Sign-in Days</strong>, <strong>Attendances</strong> and <strong>Players</strong> cover only the selected window.
				</div>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
<?php if (!$_has_data): ?>
			<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
				<i class="fas fa-check-circle" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
				No parks found<?= $_all_kd ? '.' : ' for the selected kingdom.' ?>
			</div>
<?php else: ?>
			<table class="rp-table display" id="pa-table" style="width:100%">
				<thead>
					<tr>
<?php if ($_all_kd): ?>
						<th>Kingdom</th>
<?php endif; ?>
						<th>Park</th>
						<th>Type</th>
						<th>Last Attendance</th>
						<th class="pa-num">Days Inactive</th>
						<th class="pa-num">Sign-in Days</th>
						<th class="pa-num">Attendances</th>
						<th class="pa-num">Players</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($Parks as $_park):
	$_last = $_park['LastAttendance'];
	$_days = $_park['DaysInactive'];
	$_days_class = $_last === null ? 'pa-never' : ((int)$_days > 90 ? 'pa-warn' : '');
?>
				<tr>
<?php if ($_all_kd): ?>
					<td><?=htmlspecialchars(stripslashes($_park['KingdomName'] ?? ''))?></td>
<?php endif; ?>
					<td>
						<a href="<?=UIR?>Park/profile/<?=(int)$_park['ParkId']?>"><?=htmlspecialchars(stripslashes($_park['ParkName'] ?? ''))?></a>
						<?php if (($_park['Active'] ?? 'Active') !== 'Active'): ?><span class="pa-retired">Retired</span><?php endif; ?>
					</td>
					<td><?=htmlspecialchars($_park['ParkType'] ?? '—')?></td>
					<td data-order="<?= $_last ? strtotime($_last) : 0 ?>"><?= $_last ? htmlspecialchars($_last) : '<span class="pa-never">Never</span>' ?></td>
					<td class="pa-num <?=$_days_class?>" data-order="<?= $_last === null ? 999999 : (int)$_days ?>"><?= $_last === null ? '—' : number_format((int)$_days) ?></td>
					<td class="pa-num <?= (int)$_park['SigninDays'] === 0 ? 'pa-muted' : '' ?>"><?=number_format((int)$_park['SigninDays'])?></td>
					<td class="pa-num <?= (int)$_park['Attendances'] === 0 ? 'pa-muted' : '' ?>"><?=number_format((int)$_park['Attendances'])?></td>
					<td class="pa-num <?= (int)$_park['DistinctPlayers'] === 0 ? 'pa-muted' : '' ?>"><?=number_format((int)$_park['DistinctPlayers'])?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
<?php if ($_has_data): ?>
	var _kdCols = <?= $_all_kd ? 1 : 0 ?>;
	$('#pa-table').DataTable({
		dom: 'lfrtip',
		scrollX: true,
		pageLength: 100,
		order: [[_kdCols, 'asc']],
		columnDefs: [
			{ targets: [_kdCols + 2, _kdCols + 3, _kdCols + 4, _kdCols + 5, _kdCols + 6], type: 'num' }
		]
	});

	// CSV mirrors the jsork sheet: a blank last attendance and 999999 days
	// inactive mark "never", so the rows still sort in a spreadsheet.
	$('#pa-btn-csv').on('click', function () {
		var rows = [[<?= $_all_kd ? "'Kingdom', " : '' ?>'Park', 'Type', 'Status', 'Last Attendance', 'Days Inactive', 'Sign-in Days (<?=(int)$Months?> mo)', 'Attendances (<?=(int)$Months?> mo)', 'Players (<?=(int)$Months?> mo)']];
		<?php foreach ($Parks as $_park): ?>
		rows.push([
			<?php if ($_all_kd): ?>'<?=addslashes(stripslashes($_park['KingdomName'] ?? ''))?>',<?php endif; ?>
			'<?=addslashes(stripslashes($_park['ParkName'] ?? ''))?>',
			'<?=addslashes($_park['ParkType'] ?? '')?>',
			'<?=addslashes($_park['Active'] ?? '')?>',
			'<?=addslashes($_park['LastAttendance'] ?? '')?>',
			'<?= $_park['LastAttendance'] === null ? 999999 : (int)$_park['DaysInactive'] ?>',
			'<?=(int)$_park['SigninDays']?>',
			'<?=(int)$_park['Attendances']?>',
			'<?=(int)$_park['DistinctPlayers']?>'
		]);
		<?php endforeach; ?>
		var csv = rows.map(function(r) { return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		a.download = '<?=addslashes($_csv_name)?>';
		a.click();
	});
<?php endif; ?>
});
</script>
