<?php
global $Session;

/* ── Pre-compute stats & chart data ───────────────── */
$att_rows      = is_array($AttendanceReport['Attendance']) ? $AttendanceReport['Attendance'] : [];
$total         = count($att_rows);
$total_credits = 0;
$park_counts   = [];
$class_counts  = [];

foreach ($att_rows as $row) {
	$total_credits += (int)($row['Credits'] ?? 1);
	$pname = $row['ParkName'] ?? 'Unknown';
	$cname = strlen($row['Flavor'] ?? '') > 0 ? $row['Flavor'] : ($row['ClassName'] ?? 'Unknown');
	$park_counts[$pname]  = ($park_counts[$pname]  ?? 0) + 1;
	$class_counts[$cname] = ($class_counts[$cname] ?? 0) + 1;
}
arsort($park_counts);
arsort($class_counts);

$park_chart_h  = 300;
$class_chart_h = 300;

/* Kingdom scope from first row or session */
$kname = '';
$kid   = 0;
if (!empty($att_rows)) {
	$first = reset($att_rows);
	$kname = $first['KingdomName'] ?? '';
	$kid   = (int)($first['KingdomId'] ?? 0);
} elseif (!empty($Session->kingdom_name)) {
	$kname = $Session->kingdom_name;
	$kid   = (int)($Session->kingdom_id ?? 0);
}

$show_charts = $total > 0;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
/* ── Attendance-specific styles ───────────────────── */
.att-form-card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 16px;
}
.att-form-card-header {
	background: #f3f4f6;
	padding: 10px 16px;
	font-size: 0.82rem;
	font-weight: 600;
	color: #374151;
	border-bottom: 1px solid #e5e7eb;
	display: flex;
	align-items: center;
	gap: 8px;
}
.att-form-card-body { padding: 14px 16px; }
.att-form-group { margin-bottom: 10px; }
.att-form-label {
	display: block;
	font-size: 0.72rem;
	font-weight: 600;
	color: #6b7280;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-bottom: 3px;
}
.att-form-input, .att-form-select {
	width: 100%;
	padding: 7px 10px;
	border: 1px solid #d1d5db;
	border-radius: 6px;
	font-size: 0.87rem;
	color: #111827;
	background: #fff;
	box-sizing: border-box;
}
.att-form-input:focus, .att-form-select:focus {
	outline: none;
	border-color: #6366f1;
	box-shadow: 0 0 0 2px rgba(99,102,241,0.15);
}
.att-form-btn {
	width: 100%;
	padding: 8px;
	background: #4338ca;
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 0.87rem;
	font-weight: 600;
	cursor: pointer;
	margin-top: 6px;
}
.att-form-btn:hover { background: #3730a3; }
.att-charts-row {
	display: flex;
	gap: 20px;
	padding: 16px 0;
}
.att-chart-card {
	flex: 1;
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	overflow: hidden;
}
.att-chart-title {
	padding: 10px 16px;
	font-size: 0.8rem;
	font-weight: 600;
	color: #374151;
	background: #f9fafb;
	border-bottom: 1px solid #e5e7eb;
	display: flex;
	align-items: center;
	gap: 7px;
	cursor: pointer;
	user-select: none;
}
.att-chart-title:hover { background: #f1f5f9; }
.att-chart-chevron { margin-left: auto; transition: transform 0.2s ease; color: #9ca3af; }
.att-chart-card.att-collapsed .att-chart-chevron { transform: rotate(-90deg); }
.att-chart-card.att-collapsed .att-chart-body { display: none; }
.att-chart-body { padding: 12px 8px; }
.att-del-link {
	color: #ef4444;
	text-decoration: none;
	font-size: 1rem;
	font-weight: 700;
}
.att-del-link:hover { color: #b91c1c; }
</style>

<div class="rp-root">

	<!-- ── Header ──────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-calendar-day rp-header-icon"></i>
				<h1 class="rp-header-title">Kingdom Attendance &mdash; <?=htmlspecialchars($AttendanceDate)?></h1>
			</div>
<?php if ($kname) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=UIR.'Kingdom/index/'.$kid?>">
					<i class="fas fa-chess-rook"></i>
					<?=htmlspecialchars($kname)?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" id="att-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost" id="att-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- ── Stats row ────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Attendees</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-star"></i></div>
			<div class="rp-stat-number"><?=$total_credits?></div>
			<div class="rp-stat-label">Total Credits</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=count($park_counts)?></div>
			<div class="rp-stat-label">Parks Represented</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number"><?=count($class_counts)?></div>
			<div class="rp-stat-label">Classes Played</div>
		</div>
	</div>

	<!-- ── Charts row ───────────────────────────────────── -->
<?php if ($show_charts) : ?>
	<div class="att-charts-row">
		<div class="att-chart-card">
			<div class="att-chart-title"><i class="fas fa-tree"></i> Attendees by Park <i class="fas fa-chevron-down att-chart-chevron"></i></div>
			<div class="att-chart-body">
				<div id="att-park-chart" style="height:<?=$park_chart_h?>px;"></div>
			</div>
		</div>
		<div class="att-chart-card">
			<div class="att-chart-title"><i class="fas fa-shield-alt"></i> Attendees by Class <i class="fas fa-chevron-down att-chart-chevron"></i></div>
			<div class="att-chart-body">
				<div id="att-class-chart" style="height:<?=$class_chart_h?>px;"></div>
			</div>
		</div>
	</div>
<?php endif; ?>

	<!-- ── Body: form sidebar + table ───────────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="att-form-card">
				<div class="att-form-card-header">
					<i class="fas fa-plus-circle"></i> Add Attendance
				</div>
				<div class="att-form-card-body">
<?php if ($Error) : ?>
					<div style="color:#dc2626;font-size:0.82rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:8px 10px;margin-bottom:12px;"><?=$Error?></div>
<?php endif; ?>
					<form method="post" action="<?=UIR?>Attendance/kingdom/<?=$Id?>/new">
						<div class="att-form-group">
							<label class="att-form-label" for="AttendanceDate">Date</label>
							<input class="att-form-input" type="text" name="AttendanceDate" id="AttendanceDate"
								value="<?=trimlen($Attendance_kingdom['AttendanceDate'])?$Attendance_kingdom['AttendanceDate']:$AttendanceDate?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="KingdomName">Player's Kingdom</label>
							<input class="att-form-input" type="text" name="KingdomName" id="KingdomName"
								value="<?=html_encode(trimlen($Attendance_kingdom['KingdomName'])?$Attendance_kingdom['KingdomName']:$Session->kingdom_name)?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="ParkName">Player's Park</label>
							<input class="att-form-input" type="text" name="ParkName" id="ParkName"
								value="<?=html_encode(trimlen($Attendance_kingdom['ParkName'])?$Attendance_kingdom['ParkName']:$Session->park_name)?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="PlayerName">Player</label>
							<input class="att-form-input" type="text" name="PlayerName" id="PlayerName"
								value="<?=html_encode($Attendance_kingdom['PlayerName'])?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="ClassId">Class</label>
							<select class="att-form-select" name="ClassId" id="ClassId">
								<option value="">— select one —</option>
<?php foreach ($Classes['Classes'] as $class) : ?>
								<option value="<?=$class['ClassId']?>"<?=($Attendance_kingdom['ClassId']==$class['ClassId']?' selected':'')?>><?=htmlspecialchars($class['Name'])?></option>
<?php endforeach; ?>
							</select>
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="Credits">Credits</label>
							<input class="att-form-input" type="text" name="Credits" id="Credits"
								value="<?=valid_id($Attendance_kingdom['Credits'])?$Attendance_kingdom['Credits']:$DefaultCredits?>">
						</div>
<?php if ($LoggedIn) : ?>
						<button class="att-form-btn" type="submit">Add Attendance</button>
<?php endif; ?>
						<input type="hidden" id="KingdomId" name="KingdomId"
							value="<?=valid_id($Attendance_kingdom['KingdomId'])?$Attendance_kingdom['KingdomId']:$Session->kingdom_id?>">
						<input type="hidden" id="ParkId" name="ParkId"
							value="<?=valid_id($Attendance_kingdom['ParkId'])?$Attendance_kingdom['ParkId']:$Session->park_id?>">
						<input type="hidden" id="MundaneId" name="MundaneId"
							value="<?=$Attendance_kingdom['MundaneId']?>">
					</form>
				</div>
			</div>
		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
<?php if ($total === 0) : ?>
			<div style="padding:40px 0;text-align:center;color:#9ca3af;">
				<i class="fas fa-calendar-times" style="font-size:2rem;margin-bottom:8px;display:block;"></i>
				No attendance records for this date.
			</div>
<?php else : ?>
			<table id="att-kingdom-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Park</th>
						<th>Player</th>
						<th>Class</th>
						<th>Credits</th>
						<th>Entered By</th>
<?php if ($LoggedIn) : ?>
						<th></th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($att_rows as $row) : ?>
				<tr>
					<td><a href="<?=UIR.'Park/index/'.$row['ParkId']?>"><?=htmlspecialchars($row['ParkName'])?></a></td>
					<td>
<?php if ((int)$row['MundaneId'] === 0) : ?>
						<?=htmlspecialchars($row['AttendancePersona'])?><?=strlen($row['Note']??'')>0?' ('.htmlspecialchars($row['Note']).')':''?>
<?php else : ?>
						<a href="<?=UIR.'Player/index/'.$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'])?></a>
<?php endif; ?>
					</td>
					<td><?=htmlspecialchars(strlen($row['Flavor']??'')>0?$row['Flavor']:$row['ClassName'])?></td>
					<td><?=(int)$row['Credits']?></td>
					<td><a href="<?=UIR.'Player/index/'.$row['EnteredById']?>"><?=htmlspecialchars($row['EnteredBy']??'')?></a></td>
<?php if ($LoggedIn) : ?>
					<td style="text-align:center;">
						<a class="att-del-link" href="<?=UIR?>Attendance/kingdom/<?=$Id?>/delete/<?=$row['AttendanceId']?>&AttendanceDate=<?=$AttendanceDate?>" title="Remove">&times;</a>
					</td>
<?php endif; ?>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
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

<script>
$(function() {
	/* ── Datepicker ──────────────────────────────────── */
	$('#AttendanceDate').datepicker({ dateFormat: 'yy-mm-dd' });

	/* ── Kingdom autocomplete ────────────────────────── */
	$('#KingdomName').autocomplete({
		source: function(request, response) {
			$.getJSON('<?=HTTP_SERVICE?>Search/SearchService.php', {
				Action: 'Search/Kingdom', name: request.term, limit: 6
			}, function(data) {
				response($.map(data, function(v) { return { label: v.Name, value: v.KingdomId }; }));
			});
		},
		focus:  function(e, ui) { return showLabel('#KingdomName', ui); },
		delay:  250,
		select: function(e, ui) { showLabel('#KingdomName', ui); $('#KingdomId').val(ui.item.value); return false; },
		change: function(e, ui) { if (!ui.item) { showLabel('#KingdomName', null); $('#KingdomId').val(null); } return false; },
		minLength: 0
	}).focus(function() { if (!this.value) $(this).trigger('keydown.autocomplete'); });

	/* ── Park autocomplete ───────────────────────────── */
	$('#ParkName').autocomplete({
		source: function(request, response) {
			$.getJSON('<?=HTTP_SERVICE?>Search/SearchService.php', {
				Action: 'Search/Park', name: request.term,
				kingdom_id: $('#KingdomId').val(), limit: 6
			}, function(data) {
				response($.map(data, function(v) { return { label: v.Name, value: v.ParkId }; }));
			});
		},
		focus:  function(e, ui) { return showLabel('#ParkName', ui); },
		delay:  250,
		select: function(e, ui) { showLabel('#ParkName', ui); $('#ParkId').val(ui.item.value); return false; },
		change: function(e, ui) { if (!ui.item) { showLabel('#ParkName', null); $('#ParkId').val(null); } return false; }
	}).focus(function() { if (!this.value) $(this).trigger('keydown.autocomplete'); });

	/* ── Player autocomplete ─────────────────────────── */
	$('#PlayerName').autocomplete({
		source: function(request, response) {
			$.getJSON('<?=HTTP_SERVICE?>Search/SearchService.php', {
				Action: 'Search/Player', type: 'all', search: request.term,
				kingdom_id: $('#KingdomId').val(), limit: 15
			}, function(data) {
				response($.map(data, function(v) { return { label: v.Persona, value: v.MundaneId + '|' + v.PenaltyBox }; }));
			});
		},
		focus:  function(e, ui) { return showLabel('#PlayerName', ui); },
		delay:  250,
		select: function(e, ui) {
			showLabel('#PlayerName', ui);
			$('#MundaneId').val(ui.item.value.split('|')[0]);
			return false;
		},
		change: function(e, ui) { if (!ui.item) { showLabel('#PlayerName', null); $('#MundaneId').val(null); } return false; }
	}).focus(function() { if (!this.value) $(this).trigger('keydown.autocomplete'); });

	/* ── Chart collapse toggle ──────────────────────── */
	document.querySelectorAll('.att-chart-title').forEach(function(title) {
		title.addEventListener('click', function() {
			title.closest('.att-chart-card').classList.toggle('att-collapsed');
		});
	});

<?php if ($total > 0) : ?>
	/* ── DataTable ───────────────────────────────────── */
	var table = $('#att-kingdom-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Kingdom Attendance <?=addslashes($AttendanceDate)?>', exportOptions: { columns: ':not(:last-child)' } },
			{ extend: 'print', exportOptions: { columns: ':not(:last-child)' } }
		],
		columnDefs: [
			{ targets: [3], type: 'num', className: 'dt-right' },
<?php if ($LoggedIn) : ?>
			{ targets: [-1], orderable: false, searchable: false }
<?php endif; ?>
		],
		order: [[0, 'asc'], [1, 'asc']],
		pageLength: 25,
		fixedHeader: { headerOffset: 48 },
		scrollX: true
	});
	$('#att-btn-export').on('click', function() { table.button(0).trigger(); });
	$('#att-btn-print' ).on('click', function() { table.button(1).trigger(); });

	/* ── Charts ──────────────────────────────────────── */
	new Highcharts.Chart({
		chart: { renderTo: 'att-park-chart', type: 'column',
			style: { fontFamily: 'inherit' } },
		title: { text: null },
		xAxis: {
			categories: <?=json_encode(array_keys($park_counts))?>,
			labels: { rotation: -45, align: 'right', style: { fontSize: '12px' } }
		},
		yAxis: { title: { text: null }, allowDecimals: false, min: 0 },
		series: [{ name: 'Attendees', data: <?=json_encode(array_values($park_counts))?>, color: '#4338ca' }],
		legend: { enabled: false },
		credits: { enabled: false },
		tooltip: { headerFormat: '', pointFormat: '<b>{point.category}</b>: {point.y}' },
		plotOptions: { column: { dataLabels: { enabled: true } } }
	});

	new Highcharts.Chart({
		chart: { renderTo: 'att-class-chart', type: 'column',
			style: { fontFamily: 'inherit' } },
		title: { text: null },
		xAxis: {
			categories: <?=json_encode(array_keys($class_counts))?>,
			labels: { rotation: -45, align: 'right', style: { fontSize: '12px' } }
		},
		yAxis: { title: { text: null }, allowDecimals: false, min: 0 },
		series: [{ name: 'Attendees', data: <?=json_encode(array_values($class_counts))?>, color: '#7c3aed' }],
		legend: { enabled: false },
		credits: { enabled: false },
		tooltip: { headerFormat: '', pointFormat: '<b>{point.category}</b>: {point.y}' },
		plotOptions: { column: { dataLabels: { enabled: true } } }
	});
<?php endif; ?>
});
</script>
