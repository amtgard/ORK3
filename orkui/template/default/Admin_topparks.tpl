<?php
$_has_data  = is_array($TopParks) && count($TopParks) > 0;
$_week_count = max(1, (int)$WeekCount);

/* stats */
$_top_avg  = 0;
$_top_name = '';
if ($_has_data) {
	$_top_avg  = round(reset($TopParks)['AttendanceCount'] / $_week_count, 2);
	$_top_name = reset($TopParks)['ParkName'] ?? '';

	/* chart data — top 15 for readability */
	$_chart_slice  = array_slice($TopParks, 0, 15);
	$_chart_parks  = array_map(function($p) { return $p['ParkName'] ?? ''; }, $_chart_slice);
	$_chart_avgs   = array_map(function($p) use ($_week_count) {
		return round($p['AttendanceCount'] / $_week_count, 2);
	}, $_chart_slice);
	/* reverse so highest bar is at top in horizontal chart */
	$_chart_parks = array_reverse($_chart_parks);
	$_chart_avgs  = array_reverse($_chart_avgs);
}

$_active_filters = [];
if (!empty($NativePopulace)) $_active_filters[] = 'Local Players Only';
if (!empty($Waivered))       $_active_filters[] = 'Waivered Players Only';
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
.rp-param-form { display: flex; flex-direction: column; gap: 10px; }
.rp-form-group { display: flex; flex-direction: column; gap: 3px; }
.rp-form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); }
.rp-form-input { width: 100%; border: 1px solid var(--rp-border); border-radius: 5px; padding: 6px 8px; font-size: 13px; color: var(--rp-text); box-sizing: border-box; }
.rp-form-input:focus { outline: none; border-color: #6366f1; }
.rp-form-check { display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--rp-text-body); cursor: pointer; }
.rp-btn-run { width: 100%; padding: 8px 0; background: #4338ca; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.rp-btn-run:hover { background: #3730a3; }
.rp-rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; font-size: 11px; font-weight: 700; background: #e2e8f0; color: #4a5568; }
.rp-rank-badge.rp-rank-1 { background: #fef3c7; color: #92400e; }
.rp-rank-badge.rp-rank-2 { background: #f1f5f9; color: #475569; }
.rp-rank-badge.rp-rank-3 { background: #fde8d8; color: #7c3d12; }
.rp-filter-pill-active { display: inline-flex; align-items: center; gap: 4px; background: #ede9fe; color: #5b21b6; border-radius: 12px; padding: 2px 8px; font-size: 11px; font-weight: 600; margin: 2px; }
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-trophy rp-header-icon"></i>
				<h1 class="rp-header-title">Top <?=$Limit?> Parks by Attendance</h1>
			</div>
<?php if (!empty($_active_filters)): ?>
			<div class="rp-header-scope">
				<?php foreach ($_active_filters as $_f): ?>
				<span class="rp-filter-pill-active"><i class="fas fa-filter"></i> <?=htmlspecialchars($_f)?></span>
				<?php endforeach; ?>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
<?php if ($_has_data): ?>
			<button class="rp-btn-ghost" id="tp-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
<?php endif; ?>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Ranked by average weekly attendance from <strong><?=htmlspecialchars($StartDate)?></strong> to <strong><?=htmlspecialchars($EndDate)?></strong> (<?=$_week_count?> weeks).</span>
	</div>

<?php if ($_has_data): ?>
	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=count($TopParks)?></div>
			<div class="rp-stat-label">Parks Ranked</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-week"></i></div>
			<div class="rp-stat-number"><?=$_week_count?></div>
			<div class="rp-stat-label">Weeks in Range</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-trophy"></i></div>
			<div class="rp-stat-number"><?=number_format($_top_avg, 1)?></div>
			<div class="rp-stat-label">#1 Weekly Avg</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-medal"></i></div>
			<div class="rp-stat-number" style="font-size:14px;line-height:1.2;"><?=htmlspecialchars($_top_name)?></div>
			<div class="rp-stat-label">#1 Park</div>
		</div>
	</div>

	<!-- Chart -->
	<div class="rp-charts-row rp-charts-visible">
		<div id="tp-chart" style="width:100%;height:<?=min(15, count($TopParks)) * 28 + 60?>px;"></div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sliders-h"></i> Parameters</div>
				<div class="rp-filter-card-body">
					<form method="POST" action="<?=UIR?>Admin/topparks" class="rp-param-form">
						<div class="rp-form-group">
							<label for="StartDate">Start Date</label>
							<input type="text" id="StartDate" name="StartDate" class="datepicker rp-form-input" value="<?=htmlspecialchars($StartDate)?>">
						</div>
						<div class="rp-form-group">
							<label for="EndDate">End Date</label>
							<input type="text" id="EndDate" name="EndDate" class="datepicker rp-form-input" value="<?=htmlspecialchars($EndDate)?>">
						</div>
						<div class="rp-form-group">
							<label class="rp-form-check">
								<input type="checkbox" name="NativePopulace" value="1"<?=!empty($NativePopulace) ? ' checked' : ''?>>
								Local Players Only
							</label>
						</div>
						<div class="rp-form-group">
							<label class="rp-form-check">
								<input type="checkbox" name="Waivered" value="1"<?=!empty($Waivered) ? ' checked' : ''?>>
								Waivered Players Only
							</label>
						</div>
						<button type="submit" class="rp-btn-run">Update</button>
					</form>
				</div>
			</div>

			<div class="rp-filter-card">
				<details>
					<summary class="rp-filter-card-header" style="cursor:pointer;">
						<i class="fas fa-info-circle"></i> About This Report
					</summary>
					<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;">
						<dl style="margin:0;color:var(--rp-text-body);">
							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Weekly Average</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">Total deduplicated attendance divided by weeks in range. Each player counts once per calendar week per park regardless of how many days they attended.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Local Players Only</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">Counts only players whose home park matches the park being measured. Filters out visitors and traveling players.</dd>

							<dt style="font-weight:700;margin-top:8px;color:var(--rp-text);">Waivered Players Only</dt>
							<dd style="margin:2px 0 0 0;color:var(--rp-text-muted);">Counts only attendance by players with a signed waiver on file.</dd>
						</dl>
					</div>
				</details>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
<?php if (!$_has_data): ?>
			<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
				<i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
				No data available for the selected parameters.
			</div>
<?php else: ?>
			<table class="rp-table display" style="width:100%">
				<thead>
					<tr>
						<th style="width:50px;">Rank</th>
						<th class="dt-right" style="width:100px;">Weekly Avg</th>
						<th>Park</th>
						<th>Kingdom</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($TopParks as $rank => $park): ?>
				<tr style="cursor:pointer;" onclick="window.location.href='<?=UIR?>Park/profile/<?=(int)$park['ParkId']?>'">
					<td>
						<span class="rp-rank-badge"><?=($rank+1)?></span>
					</td>
					<td class="dt-right"><strong><?=number_format($park['AttendanceCount'] / $_week_count, 2)?></strong></td>
					<td><?=htmlspecialchars(stripslashes($park['ParkName'] ?? ''))?></td>
					<td><?=htmlspecialchars(stripslashes($park['KingdomName'] ?? ''))?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script>
$(function () {
	$('.datepicker').datepicker({ dateFormat: 'yy-mm-dd' });

<?php if ($_has_data): ?>
	$('#tp-btn-csv').on('click', function () {
		var rows = [['Rank','Weekly Avg','Park','Kingdom']];
		<?php foreach ($TopParks as $rank => $park): ?>
		rows.push([<?=($rank+1)?>, '<?=number_format($park['AttendanceCount'] / $_week_count, 2)?>', '<?=addslashes(stripslashes($park['ParkName'] ?? ''))?>', '<?=addslashes(stripslashes($park['KingdomName'] ?? ''))?>']);
		<?php endforeach; ?>
		var csv = rows.map(function(r) { return r.map(function(v) { return '"'+String(v).replace(/"/g,'""')+'"'; }).join(','); }).join('\n');
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		a.download = 'top-parks-attendance.csv';
		a.click();
	});

	new Highcharts.Chart({
		chart  : { renderTo: 'tp-chart', type: 'bar', style: { fontFamily: 'inherit' }, marginLeft: 160 },
		title  : { text: null },
		xAxis  : { categories: <?=json_encode($_chart_parks)?>, labels: { style: { fontSize: '11px' } } },
		yAxis  : { title: { text: 'Weekly Avg Attendance' }, allowDecimals: true, min: 0 },
		series : [{ name: 'Weekly Avg', data: <?=json_encode($_chart_avgs)?>, color: '#4338ca', showInLegend: false }],
		tooltip: { valueSuffix: ' players/week', valueDecimals: 2 },
		credits: { enabled: false },
		plotOptions: { bar: { dataLabels: { enabled: true, format: '{y:.2f}', style: { fontSize: '10px' } } } }
	});
<?php endif; ?>
});
</script>
