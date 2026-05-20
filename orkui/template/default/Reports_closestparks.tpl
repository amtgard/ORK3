<?php
$_cp_parks  = is_array($parks ?? null) ? $parks : [];
$_cp_total  = count($_cp_parks);
$_cp_origin = htmlspecialchars($origin_park ?? '');

// ── Derived metrics ──────────────────────────────────────────
$_cp_within25  = 0;
$_cp_within50  = 0;
$_cp_within100 = 0;
$_cp_kingdoms  = [];
$_cp_miles     = [];
foreach ($_cp_parks as $_p) {
	$_m = (float)$_p['Miles'];
	$_cp_miles[] = $_m;
	if ($_m <= 25)  $_cp_within25++;
	if ($_m <= 50)  $_cp_within50++;
	if ($_m <= 100) $_cp_within100++;
	if (!empty($_p['KingdomName'])) $_cp_kingdoms[$_p['KingdomName']] = true;
}
$_cp_kingdom_ct = count($_cp_kingdoms);
sort($_cp_miles);
$_cp_median = 0;
if ($_cp_total > 0) {
	$_mid = intdiv($_cp_total, 2);
	$_cp_median = ($_cp_total % 2 === 1)
		? $_cp_miles[$_mid]
		: ($_cp_miles[$_mid - 1] + $_cp_miles[$_mid]) / 2;
}

// Distance bucket → [label, css-suffix] for the per-row badge
$_cp_bucket = function($m) {
	if ($m <= 25)  return ['Local',     'local'];
	if ($m <= 50)  return ['Day trip',  'day'];
	if ($m <= 100) return ['Road trip', 'road'];
	return ['Far', 'far'];
};
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-map-marker-alt rp-header-icon"></i>
				<h1 class="rp-header-title">Closest Parks<?php if ($_cp_origin): ?> to <?=$_cp_origin?><?php endif; ?></h1>
			</div>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" id="cp-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Nearest active parks by straight-line distance (Haversine/great-circle). Does not account for roads or travel time. Parks without GPS coordinates on file are excluded.</span>
	</div>

<?php if (!empty($error)): ?>
	<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
		<i class="fas fa-exclamation-circle" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
		<?=htmlspecialchars($error)?>
	</div>
<?php elseif ($_cp_total === 0): ?>
	<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
		<i class="fas fa-map-marker-alt" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.3;"></i>
		No nearby parks found. This park may not have geocoordinates on file.
	</div>
<?php else: ?>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
			<div class="rp-stat-number"><?=$_cp_total?></div>
			<div class="rp-stat-label">Nearby Parks</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-location-arrow"></i></div>
			<div class="rp-stat-number"><?=number_format($_cp_parks[0]['Miles'], 1)?> mi</div>
			<div class="rp-stat-label">Closest</div>
		</div>
		<div class="rp-stat-card">
			<span class="rp-stat-tip"><span class="rp-stat-tip-icon" title="Parks within 50 miles — a comfortable day trip by car.">i</span></span>
			<div class="rp-stat-icon"><i class="fas fa-car-side"></i></div>
			<div class="rp-stat-number"><?=$_cp_within50?></div>
			<div class="rp-stat-label">Within 50 mi</div>
		</div>
		<div class="rp-stat-card">
			<span class="rp-stat-tip"><span class="rp-stat-tip-icon" title="Parks within 100 miles — reachable for a longer day trip or an event.">i</span></span>
			<div class="rp-stat-icon"><i class="fas fa-road"></i></div>
			<div class="rp-stat-number"><?=$_cp_within100?></div>
			<div class="rp-stat-label">Within 100 mi</div>
		</div>
		<div class="rp-stat-card">
			<span class="rp-stat-tip"><span class="rp-stat-tip-icon" title="Distinct kingdoms represented among the nearby parks — a sense of how many realms border this area.">i</span></span>
			<div class="rp-stat-icon"><i class="fas fa-crown"></i></div>
			<div class="rp-stat-number"><?=$_cp_kingdom_ct?></div>
			<div class="rp-stat-label">Kingdoms</div>
		</div>
		<div class="rp-stat-card">
			<span class="rp-stat-tip"><span class="rp-stat-tip-icon" title="Median straight-line distance across all nearby parks — half are closer, half are farther. A measure of how isolated this park is.">i</span></span>
			<div class="rp-stat-icon"><i class="fas fa-ruler-horizontal"></i></div>
			<div class="rp-stat-number"><?=number_format($_cp_median, 1)?> mi</div>
			<div class="rp-stat-label">Median Distance</div>
		</div>
	</div>

	<div class="rp-table-area">
		<table id="cp-table" class="dataTable" style="width:100%">
			<thead>
				<tr>
					<th>#</th>
					<th>Park</th>
					<th>Kingdom</th>
					<th>Location</th>
					<th>Distance</th>
					<th>Range</th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($_cp_parks as $i => $park): ?>
<?php list($_bLabel, $_bClass) = $_cp_bucket((float)$park['Miles']); ?>
				<tr>
					<td><?=$i + 1?></td>
					<td><a href="<?=UIR?>Park/profile/<?=(int)$park['ParkId']?>"><?=htmlspecialchars($park['ParkName'])?></a></td>
					<td><?=htmlspecialchars($park['KingdomName'])?></td>
					<td><?=htmlspecialchars(trim($park['City'] . ', ' . $park['Province'], ', '))?></td>
					<td data-order="<?=$park['Miles']?>"><?=number_format($park['Miles'], 1)?> mi</td>
					<td data-order="<?=$park['Miles']?>"><span class="cp-badge cp-badge-<?=$_bClass?>"><?=$_bLabel?></span></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>

<?php endif; ?>

</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
	if (!$('#cp-table').length) return;

	var table = $('#cp-table').DataTable({
		dom       : 'lfrtip',
		pageLength: 25,
		order     : [[4, 'asc']],
		columnDefs: [{ targets: [0], orderable: false }]
	});

	$('#cp-btn-csv').on('click', function () {
		var headers = [];
		$('#cp-table thead th').each(function () { headers.push($(this).text().trim()); });
		var rows = [headers.join(',')];
		table.rows({ search: 'applied' }).every(function () {
			var cells = [];
			$(this.node()).find('td').each(function () {
				var text = $(this).text().trim().replace(/"/g, '""');
				cells.push('"' + text + '"');
			});
			rows.push(cells.join(','));
		});
		var blob = new Blob([rows.join('\n')], { type: 'text/csv' });
		var a    = document.createElement('a');
		a.href   = URL.createObjectURL(blob);
		a.download = 'closest-parks.csv';
		a.click();
	});
}());
</script>
