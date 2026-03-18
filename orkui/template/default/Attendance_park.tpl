<?php
global $Session;

/* ── Pre-compute stats & chart data ───────────────── */
$att_rows      = is_array($AttendanceReport['Attendance']) ? $AttendanceReport['Attendance'] : [];
$total         = count($att_rows);
$total_credits = 0;
$class_counts  = [];

$has_events = false;
foreach ($att_rows as $row) {
	$total_credits += (int)($row['Credits'] ?? 1);
	$cname = strlen($row['Flavor'] ?? '') > 0 ? $row['Flavor'] : ($row['ClassName'] ?? 'Unknown');
	$class_counts[$cname] = ($class_counts[$cname] ?? 0) + 1;
	if (!empty($row['EventId'])) $has_events = true;
}
arsort($class_counts);
$class_chart_h = 280;

/* MundaneIds already on the attendance list for this date */
$already_added_ids = [];
foreach ($att_rows as $row) {
	$mid = (int)($row['MundaneId'] ?? 0);
	if ($mid > 0) $already_added_ids[] = $mid;
}
$already_added_ids = array_values(array_unique($already_added_ids));

/* Scope — from first row or session */
$pname = '';
$pid   = 0;
$kname = '';
$kid   = 0;
if (!empty($att_rows)) {
	$first = reset($att_rows);
	$pname = $first['ParkName']    ?? '';
	$pid   = (int)($first['ParkId']    ?? 0);
	$kname = $first['KingdomName'] ?? '';
	$kid   = (int)($first['KingdomId'] ?? 0);
} elseif (!empty($Session->park_name)) {
	$pname = $Session->park_name;
	$pid   = (int)($Session->park_id ?? 0);
	$kname = $Session->kingdom_name;
	$kid   = (int)($Session->kingdom_id ?? 0);
}

$show_chart = $total > 0;
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
.att-chart-card {
	background: #fff;
	border: 1px solid #e5e7eb;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 16px;
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
.ui-autocomplete-separator {
	padding: 2px 12px;
	cursor: default;
	pointer-events: none;
	color: #999;
	font-size: 11px;
}
/* ── Quick Add modal ──────────────────────────────── */
.att-qa-open-btn {
	width: 100%;
	padding: 7px;
	background: #fff;
	color: #4338ca;
	border: 1.5px solid #c7d2fe;
	border-radius: 6px;
	font-size: 0.85rem;
	font-weight: 600;
	cursor: pointer;
	margin-top: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
}
.att-qa-open-btn:hover { background: #eef2ff; border-color: #a5b4fc; }
.att-qa-overlay {
	display: none;
	position: fixed;
	inset: 0;
	background: rgba(0,0,0,0.45);
	z-index: 9990;
	align-items: center;
	justify-content: center;
}
.att-qa-overlay.att-qa-open { display: flex; }
.att-qa-modal {
	background: #fff;
	border-radius: 12px;
	box-shadow: 0 20px 60px rgba(0,0,0,0.3);
	width: 680px;
	max-width: 96vw;
	max-height: 82vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.att-qa-modal-header {
	background: #1e1b4b;
	color: #fff;
	padding: 14px 20px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-shrink: 0;
}
.att-qa-modal-title {
	font-size: 0.95rem;
	font-weight: 700;
	display: flex;
	align-items: center;
	gap: 8px;
}
.att-qa-header-right {
	display: flex;
	align-items: center;
	gap: 14px;
}
.att-qa-date-label {
	font-size: 0.78rem;
	color: rgba(255,255,255,0.7);
	display: flex;
	align-items: center;
	gap: 6px;
}
.att-qa-date-input {
	padding: 4px 8px;
	border: 1px solid rgba(255,255,255,0.3);
	border-radius: 5px;
	background: rgba(255,255,255,0.12);
	color: #fff;
	font-size: 0.82rem;
	width: 120px;
}
.att-qa-close-btn {
	background: none;
	border: none;
	color: rgba(255,255,255,0.7);
	font-size: 1.3rem;
	cursor: pointer;
	line-height: 1;
	padding: 0 2px;
}
.att-qa-close-btn:hover { color: #fff; }
.att-qa-modal-body {
	padding: 16px 20px;
	overflow-y: auto;
	flex: 1;
}
.att-qa-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.87rem;
}
.att-qa-table th {
	text-align: left;
	font-size: 0.72rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: #6b7280;
	border-bottom: 2px solid #e5e7eb;
	padding: 0 8px 8px;
}
.att-qa-table td {
	padding: 6px 8px;
	border-bottom: 1px solid #f3f4f6;
	vertical-align: middle;
}
.att-qa-table tr.att-qa-done td { opacity: 0.4; }
.att-qa-table tr:last-child td { border-bottom: none; }
.att-qa-select {
	padding: 5px 6px;
	border: 1px solid #d1d5db;
	border-radius: 5px;
	font-size: 0.84rem;
	min-width: 140px;
}
.att-qa-credits-input {
	padding: 5px 6px;
	border: 1px solid #d1d5db;
	border-radius: 5px;
	font-size: 0.84rem;
	width: 56px;
	text-align: center;
}
.att-qa-add-btn {
	padding: 5px 14px;
	background: #4338ca;
	color: #fff;
	border: none;
	border-radius: 5px;
	font-size: 0.82rem;
	font-weight: 600;
	cursor: pointer;
	white-space: nowrap;
}
.att-qa-add-btn:hover:not(:disabled) { background: #3730a3; }
.att-qa-add-btn:disabled { opacity: 0.6; cursor: default; }
.att-qa-done-mark { color: #16a34a; font-weight: 700; font-size: 1rem; }
.att-qa-empty {
	text-align: center;
	color: #9ca3af;
	padding: 30px 0;
	font-size: 0.87rem;
}
.att-qa-feedback {
	margin-top: 10px;
	padding: 8px 12px;
	border-radius: 6px;
	font-size: 0.84rem;
}
.att-qa-feedback.att-qa-fb-err  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.att-qa-feedback.att-qa-fb-ok   { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
</style>

<div class="rp-root">

	<!-- ── Header ──────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-calendar-day rp-header-icon"></i>
				<h1 class="rp-header-title">Park Attendance &mdash; <?=htmlspecialchars($AttendanceDate)?></h1>
			</div>
			<div class="rp-header-scope">
<?php if ($pname) : ?>
				<a class="rp-scope-chip" href="<?=UIR.'Park/profile/'.$pid?>">
					<i class="fas fa-tree"></i>
					<?=htmlspecialchars($pname)?>
				</a>
<?php endif; ?>
<?php if ($kname) : ?>
				<a class="rp-scope-chip" href="<?=UIR.'Kingdom/profile/'.$kid?>" style="margin-left:4px;">
					<i class="fas fa-chess-rook"></i>
					<?=htmlspecialchars($kname)?>
				</a>
<?php endif; ?>
			</div>
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
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number"><?=count($class_counts)?></div>
			<div class="rp-stat-label">Classes Played</div>
		</div>
	</div>

	<!-- ── Body: form sidebar + main content ────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
<?php if ($CanAddAttendance) : ?>
		<div class="rp-sidebar">
			<div class="att-form-card">
				<div class="att-form-card-header">
					<i class="fas fa-plus-circle"></i> Add Attendance
				</div>
				<div class="att-form-card-body">
<?php if ($Error) : ?>
					<div style="color:#dc2626;font-size:0.82rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:8px 10px;margin-bottom:12px;"><?=$Error?></div>
<?php endif; ?>
					<form method="post" action="<?=UIR?>Attendance/park/<?=$Id?>/new">
						<div class="att-form-group">
							<label class="att-form-label" for="AttendanceDate">Date</label>
							<input class="att-form-input" type="text" name="AttendanceDate" id="AttendanceDate"
								value="<?=trimlen($Attendance_park['AttendanceDate'])?$Attendance_park['AttendanceDate']:$AttendanceDate?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="KingdomName">Player's Kingdom</label>
							<input class="att-form-input" type="text" name="KingdomName" id="KingdomName"
								value="<?=html_encode(trimlen($Attendance_park['KingdomName'])?$Attendance_park['KingdomName']:$DefaultKingdomName)?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="ParkName">Player's Park</label>
							<input class="att-form-input" type="text" name="ParkName" id="ParkName"
								value="<?=html_encode(trimlen($Attendance_park['ParkName'])?$Attendance_park['ParkName']:$DefaultParkName)?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="PlayerName">Player</label>
							<input class="att-form-input" type="text" name="PlayerName" id="PlayerName"
								value="<?=html_encode($Attendance_park['PlayerName'])?>">
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="ClassId">Class</label>
							<select class="att-form-select" name="ClassId" id="ClassId">
								<option value="">— select one —</option>
<?php foreach ($Classes['Classes'] as $class) : ?>
								<option value="<?=$class['ClassId']?>"<?=($Attendance_park['ClassId']==$class['ClassId']?' selected':'')?>><?=htmlspecialchars($class['Name'])?></option>
<?php endforeach; ?>
							</select>
						</div>
						<div class="att-form-group">
							<label class="att-form-label" for="Credits">Credits</label>
							<input class="att-form-input" type="text" name="Credits" id="Credits"
								value="<?=valid_id($Attendance_park['Credits'])?$Attendance_park['Credits']:$DefaultCredits?>">
						</div>
					<button class="att-form-btn" type="submit">Add Attendance</button>
					<button class="att-qa-open-btn" type="button" id="att-qa-open">
						<i class="fas fa-users"></i> Quick Add — Recent Attendees
					</button>
					<input type="hidden" id="KingdomId" name="KingdomId"
							value="<?=valid_id($Attendance_park['KingdomId'])?$Attendance_park['KingdomId']:$DefaultKingdomId?>">
						<input type="hidden" id="ParkId" name="ParkId"
							value="<?=valid_id($Attendance_park['ParkId'])?$Attendance_park['ParkId']:$DefaultParkId?>">
						<input type="hidden" id="MundaneId" name="MundaneId"
							value="<?=$Attendance_park['MundaneId']?>">
					</form>
				</div>
			</div>
		</div><!-- /rp-sidebar -->
<?php endif; ?>

		<!-- Table area -->
		<div class="rp-table-area">
<?php if ($show_chart) : ?>
			<div class="att-chart-card" style="margin-bottom:16px;">
				<div class="att-chart-title"><i class="fas fa-shield-alt"></i> Attendees by Class <i class="fas fa-chevron-down att-chart-chevron"></i></div>
				<div class="att-chart-body">
					<div id="att-class-chart" style="height:<?=$class_chart_h?>px;"></div>
				</div>
			</div>
<?php endif; ?>
<?php if ($total === 0) : ?>
			<div style="padding:40px 0;text-align:center;color:#9ca3af;">
				<i class="fas fa-calendar-times" style="font-size:2rem;margin-bottom:8px;display:block;"></i>
				No attendance records for this date.
			</div>
<?php else : ?>
			<table id="att-park-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Player</th>
						<th>Home Kingdom</th>
						<th>Home Park</th>
						<th>Class</th>
						<th>Credits</th>
						<th>Entered By</th>
<?php if ($has_events) : ?><th>Event</th><?php endif; ?>
<?php if ($CanAddAttendance) : ?>
						<th></th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($att_rows as $row) : ?>
				<tr>
					<td>
<?php if ((int)$row['MundaneId'] === 0) : ?>
						<?=htmlspecialchars($row['AttendancePersona'])?><?=strlen($row['Note']??'')>0?' ('.htmlspecialchars($row['Note']).')':''?>
<?php else : ?>
						<a href="<?=UIR.'Player/profile/'.$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'])?></a>
<?php endif; ?>
					</td>
					<td><?php if (!empty($row['FromKingdomId'])) : ?><a href="<?=UIR.'Kingdom/profile/'.$row['FromKingdomId']?>"><?=htmlspecialchars($row['FromKingdomName']??'')?></a><?php else : ?><?=htmlspecialchars($row['FromKingdomName']??'')?><?php endif; ?></td>
					<td><?php if (!empty($row['FromParkId'])) : ?><a href="<?=UIR.'Park/profile/'.$row['FromParkId']?>"><?=htmlspecialchars($row['FromParkName']??'')?></a><?php else : ?><?=htmlspecialchars($row['FromParkName']??'')?><?php endif; ?></td>
					<td><?=htmlspecialchars(strlen($row['Flavor']??'')>0?$row['Flavor']:$row['ClassName'])?></td>
					<td><?=(int)$row['Credits']?></td>
					<td><a href="<?=UIR.'Player/profile/'.$row['EnteredById']?>"><?=htmlspecialchars($row['EnteredBy']??'')?></a></td>
<?php if ($has_events) : ?><td><?php if (!empty($row['EventId'])) : ?><a href="<?=UIR.'Event/detail/'.$row['EventId'].'/'.$row['EventCalendarDetailId']?>"><?=htmlspecialchars($row['EventName']??'')?></a><?php endif; ?></td><?php endif; ?>
<?php if ($CanAddAttendance) : ?>
					<td style="text-align:center;">
						<a class="att-del-link" href="<?=UIR?>Attendance/park/<?=$Id?>/delete/<?=$row['AttendanceId']?>&AttendanceDate=<?=$AttendanceDate?>" title="Remove">&times;</a>
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

<?php if ($CanAddAttendance) : ?>
<!-- ── Quick Add Modal ──────────────────────────────── -->
<div class="att-qa-overlay" id="att-qa-overlay">
	<div class="att-qa-modal">
		<div class="att-qa-modal-header">
			<div class="att-qa-modal-title">
				<i class="fas fa-users"></i> Quick Add &mdash; Recent Attendees
			</div>
			<div class="att-qa-header-right">
				<label class="att-qa-date-label">
					<i class="fas fa-calendar-day"></i>
					<input class="att-qa-date-input" type="text" id="att-qa-date" readonly>
				</label>
				<button class="att-qa-close-btn" id="att-qa-close" title="Close">&times;</button>
			</div>
		</div>
		<div class="att-qa-modal-body">
			<div id="att-qa-empty" class="att-qa-empty" style="display:none">
				No attendance records in the last 90 days.
			</div>
			<table class="att-qa-table" id="att-qa-table" style="display:none">
				<thead>
					<tr>
						<th>Player</th>
						<th>Last Seen</th>
						<th>Class</th>
						<th>Credits</th>
						<th></th>
					</tr>
				</thead>
				<tbody id="att-qa-tbody"></tbody>
			</table>
			<div id="att-qa-feedback" class="att-qa-feedback" style="display:none"></div>
		</div>
	</div>
</div>
<?php endif; ?>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>

<script>
$(function() {
<?php if ($CanAddAttendance) : ?>
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

	/* ── Player autocomplete (local + kingdom outsiders) */
	var playerAC = $('#PlayerName').autocomplete({
		source: function(request, response) {
			var park_id    = $('#ParkId').val();
			var kingdom_id = $('#KingdomId').val();
			var search     = request.term;
			var svcUrl     = '<?=HTTP_SERVICE?>Search/SearchService.php';

			if (!park_id || park_id == '0') {
				$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, kingdom_id: kingdom_id, limit: 15 }, function(data) {
					response($.map(data, function(v) { return { label: v.Persona, value: { MundaneId: v.MundaneId, PenaltyBox: v.PenaltyBox } }; }));
				});
				return;
			}
			$.when(
				$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, park_id: park_id, kingdom_id: kingdom_id, limit: 8 }),
				$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, kingdom_id: kingdom_id, limit: 15 }),
				$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, limit: 10 })
			).done(function(parkRes, kingdomRes, globalRes) {
				var seenIds = {}, suggestions = [], kingdomOutsiders = [], globalOutsiders = [];
				$.each(parkRes[0], function(i, v) {
					seenIds[v.MundaneId] = true;
					suggestions.push({ label: v.Persona, value: { MundaneId: v.MundaneId, PenaltyBox: v.PenaltyBox } });
				});
				$.each(kingdomRes[0], function(i, v) {
					if (!seenIds[v.MundaneId]) {
						seenIds[v.MundaneId] = true;
						var abbr = (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : v.ParkName;
						kingdomOutsiders.push({ label: v.Persona + ' (' + abbr + ')', value: { MundaneId: v.MundaneId, PenaltyBox: v.PenaltyBox } });
					}
				});
				$.each(globalRes[0], function(i, v) {
					if (!seenIds[v.MundaneId]) {
						seenIds[v.MundaneId] = true;
						var abbr = (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : v.ParkName;
						globalOutsiders.push({ label: v.Persona + ' (' + abbr + ')', value: { MundaneId: v.MundaneId, PenaltyBox: v.PenaltyBox } });
					}
				});
				if (suggestions.length > 0 && (kingdomOutsiders.length > 0 || globalOutsiders.length > 0))
					suggestions.push({ label: '', value: null, separator: true });
				if (kingdomOutsiders.length > 0 && globalOutsiders.length > 0)
					kingdomOutsiders.push({ label: '', value: null, separator: true });
				response(suggestions.concat(kingdomOutsiders).concat(globalOutsiders));
			});
		},
		focus:  function(e, ui) { if (!ui.item.value) return false; return showLabel('#PlayerName', ui); },
		delay:  250,
		select: function(e, ui) {
			if (!ui.item.value) return false;
			showLabel('#PlayerName', ui);
			$('#MundaneId').val(ui.item.value.MundaneId);
			return false;
		},
		change: function(e, ui) { if (!ui.item) { showLabel('#PlayerName', null); $('#MundaneId').val(null); } return false; }
	}).focus(function() { if (!this.value) $(this).trigger('keydown.autocomplete'); });

	playerAC.data('autocomplete')._renderItem = function(ul, item) {
		if (item.separator)
			return $('<li class="ui-autocomplete-separator">').text('── Kingdom ──').appendTo(ul);
		return $('<li></li>').data('item.autocomplete', item).append($('<a>').text(item.label)).appendTo(ul);
	};

	/* ── Quick Add Modal ────────────────────────────── */
	(function() {
		var RECENT     = <?=json_encode(array_values(is_array($RecentAttendees['Attendees'] ?? null) ? $RecentAttendees['Attendees'] : []))?>;
		var CLASSES    = <?=json_encode(array_values($Classes['Classes'] ?? []))?>;
		var ADD_URL    = '<?=UIR?>AttendanceAjax/park/<?=(int)$Id?>/add';
		var ADDED_IDS  = new Set(<?=json_encode($already_added_ids)?>);
		var dirty      = false;
		var built      = false;

		function openModal() {
			document.getElementById('att-qa-date').value = document.getElementById('AttendanceDate').value;
			if (!built) buildRows();
			document.getElementById('att-qa-overlay').classList.add('att-qa-open');
			document.body.style.overflow = 'hidden';
		}
		function closeModal() {
			document.getElementById('att-qa-overlay').classList.remove('att-qa-open');
			document.body.style.overflow = '';
			if (dirty) window.location.reload();
		}

		function makeClassSelect(selectedId) {
			var sel = document.createElement('select');
			sel.className = 'att-qa-select';
			var blank = document.createElement('option');
			blank.value = ''; blank.textContent = '— class —';
			sel.appendChild(blank);
			CLASSES.forEach(function(c) {
				var opt = document.createElement('option');
				opt.value = c.ClassId; opt.textContent = c.Name;
				if (String(c.ClassId) === String(selectedId)) opt.selected = true;
				sel.appendChild(opt);
			});
			return sel;
		}

		function buildRows() {
			var tbody    = document.getElementById('att-qa-tbody');
			var empty    = document.getElementById('att-qa-empty');
			var table    = document.getElementById('att-qa-table');
			var eligible = RECENT.filter(function(a) { return !ADDED_IDS.has(a.MundaneId); });
			if (!eligible.length) { empty.style.display = ''; return; }
			table.style.display = '';
			eligible.forEach(function(a) {
				var tr = document.createElement('tr');
				tr.dataset.mundaneId = a.MundaneId;

				var td1 = document.createElement('td');
				td1.innerHTML = '<a href="<?=UIR?>Player/profile/' + a.MundaneId + '" target="_blank">' + a.Persona + '</a>';
				tr.appendChild(td1);

				var td2 = document.createElement('td');
				td2.style.color = '#9ca3af';
				td2.style.fontSize = '0.8rem';
				td2.textContent = a.LastSignIn || '';
				tr.appendChild(td2);

				var td3 = document.createElement('td');
				td3.appendChild(makeClassSelect(a.ClassId));
				tr.appendChild(td3);

				var td4 = document.createElement('td');
				var ci  = document.createElement('input');
				ci.type = 'number'; ci.min = '0.5'; ci.step = '0.5'; ci.value = '1';
				ci.className = 'att-qa-credits-input';
				td4.appendChild(ci); tr.appendChild(td4);

				var td5  = document.createElement('td');
				var btn  = document.createElement('button');
				btn.className = 'att-qa-add-btn'; btn.textContent = 'Add';
				btn.addEventListener('click', function() { doAdd(tr, a, btn); });
				td5.appendChild(btn); tr.appendChild(td5);

				tbody.appendChild(tr);
			});
			built = true;
		}

		function doAdd(tr, attendee, btn) {
			var classId = tr.querySelector('.att-qa-select').value;
			var credits = tr.querySelector('.att-qa-credits-input').value;
			if (!classId) { showFeedback('Select a class for ' + attendee.Persona + '.', false); return; }
			btn.disabled = true; btn.textContent = '\u2026';
			$.ajax({
				url: ADD_URL, type: 'POST',
				data: {
					AttendanceDate: document.getElementById('att-qa-date').value,
					MundaneId: attendee.MundaneId,
					ClassId:   classId,
					Credits:   credits
				},
				success: function(res) {
					if (res.status === 0) {
						tr.classList.add('att-qa-done');
						btn.parentNode.innerHTML = '<span class="att-qa-done-mark">&#10003;</span>';
						ADDED_IDS.add(attendee.MundaneId);
						dirty = true;
						hideFeedback();
					} else {
						btn.disabled = false; btn.textContent = 'Add';
						showFeedback(res.error || 'An error occurred.', false);
					}
				},
				error: function() {
					btn.disabled = false; btn.textContent = 'Add';
					showFeedback('Server error — please try again.', false);
				}
			});
		}

		function showFeedback(msg, ok) {
			var el = document.getElementById('att-qa-feedback');
			el.textContent = msg;
			el.className = 'att-qa-feedback ' + (ok ? 'att-qa-fb-ok' : 'att-qa-fb-err');
			el.style.display = '';
		}
		function hideFeedback() {
			document.getElementById('att-qa-feedback').style.display = 'none';
		}

		document.getElementById('att-qa-open').addEventListener('click', openModal);
		document.getElementById('att-qa-close').addEventListener('click', closeModal);
		document.getElementById('att-qa-overlay').addEventListener('click', function(e) {
			if (e.target === this) closeModal();
		});
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') closeModal();
		});
	})();
<?php endif; ?>

	/* ── Chart collapse toggle ──────────────────────── */
	document.querySelectorAll('.att-chart-title').forEach(function(title) {
		title.addEventListener('click', function() {
			title.closest('.att-chart-card').classList.toggle('att-collapsed');
		});
	});

<?php if ($total > 0) : ?>
	/* ── DataTable ───────────────────────────────────── */
	var table = $('#att-park-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Park Attendance <?=addslashes($AttendanceDate)?>', exportOptions: { columns: ':not(:last-child)' } },
			{ extend: 'print', exportOptions: { columns: ':not(:last-child)' } }
		],
		columnDefs: [
			{ targets: [4], type: 'num', className: 'dt-right' },
<?php if ($has_events) : ?>
			{ targets: [6], orderable: false, searchable: false },
<?php endif; ?>
<?php if ($CanAddAttendance) : ?>
			{ targets: [-1], orderable: false, searchable: false },
<?php endif; ?>
		],
		order: [[0, 'asc']],
		pageLength: 25,
		fixedHeader: { headerOffset: 48 },
		scrollX: true
	});
	$('#att-btn-export').on('click', function() { table.button(0).trigger(); });
	$('#att-btn-print' ).on('click', function() { table.button(1).trigger(); });

	/* ── Class chart ─────────────────────────────────── */
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
