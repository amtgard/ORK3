<?php
global $Session;

/* ── Pre-compute stats & chart data ───────────────── */
$att_rows      = is_array($AttendanceReport['Attendance']) ? $AttendanceReport['Attendance'] : [];
$total         = count($att_rows);
$total_credits = 0;
$park_counts   = [];
$class_counts  = [];

$has_events = false;
foreach ($att_rows as $row) {
	$total_credits += (int)($row['Credits'] ?? 1);
	$pname = $row['FromParkName'] ?? 'Unknown';
	$cname = strlen($row['Flavor'] ?? '') > 0 ? $row['Flavor'] : ($row['ClassName'] ?? 'Unknown');
	$park_counts[$pname]  = ($park_counts[$pname]  ?? 0) + 1;
	$class_counts[$cname] = ($class_counts[$cname] ?? 0) + 1;
	if (!empty($row['EventId'])) $has_events = true;
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
/* ── Edit Attendance Modal ───────────────────────── */
.att-edit-overlay {
	display: none; position: fixed; inset: 0;
	background: rgba(0,0,0,0.45); z-index: 9990;
	align-items: center; justify-content: center;
}
.att-edit-overlay.att-edit-open { display: flex; }
.att-edit-modal {
	background: #fff; border-radius: 10px;
	box-shadow: 0 20px 60px rgba(0,0,0,0.3);
	width: 360px; max-width: 96vw;
}
.att-edit-modal-header {
	background: #1e1b4b; color: #fff;
	padding: 14px 18px; border-radius: 10px 10px 0 0;
	display: flex; align-items: center; justify-content: space-between;
}
.att-edit-modal-title { font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.att-edit-modal-close {
	background: none; border: none; color: rgba(255,255,255,0.7);
	font-size: 1.2rem; cursor: pointer; padding: 0 2px; line-height: 1;
}
.att-edit-modal-close:hover { color: #fff; }
.att-edit-modal-body { padding: 18px 18px 10px; }
.att-edit-field { margin-bottom: 14px; }
.att-edit-label {
	display: block; font-size: 0.72rem; font-weight: 600;
	color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;
}
.att-edit-input, .att-edit-select {
	width: 100%; padding: 7px 10px; border: 1px solid #d1d5db;
	border-radius: 6px; font-size: 0.87rem; color: #111827;
	background: #fff; box-sizing: border-box;
}
.att-edit-input:focus, .att-edit-select:focus {
	outline: none; border-color: #6366f1;
	box-shadow: 0 0 0 2px rgba(99,102,241,0.15);
}
.att-edit-row { display: flex; gap: 12px; }
.att-edit-row .att-edit-field { flex: 1; }
.att-edit-row .att-edit-field.att-edit-field-sm { flex: 0 0 90px; }
.att-edit-feedback {
	background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b;
	border-radius: 6px; padding: 8px 12px; font-size: 0.82rem; margin-bottom: 12px; display: none;
}
.att-edit-modal-footer {
	padding: 12px 18px; border-top: 1px solid #f3f4f6;
	display: flex; justify-content: flex-end; gap: 8px;
}
.att-edit-btn-cancel {
	padding: 7px 16px; background: #f3f4f6; color: #374151;
	border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; cursor: pointer;
}
.att-edit-btn-save {
	padding: 7px 16px; background: #4338ca; color: #fff;
	border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
}
.att-edit-btn-save:hover:not(:disabled) { background: #3730a3; }
.att-edit-btn-save:disabled { opacity: 0.5; cursor: not-allowed; }
/* =====================================================
   DARK MODE — Attendance form + edit modal (.att-*)
   ===================================================== */
html[data-theme="dark"] .att-form-card { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .att-form-card-header { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .att-form-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .att-form-input, html[data-theme="dark"] .att-form-select { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .att-chart-card { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .att-chart-title { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .att-chart-title:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .att-edit-modal { background: var(--ork-card-bg); }
html[data-theme="dark"] .att-edit-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .att-edit-input, html[data-theme="dark"] .att-edit-select { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .att-edit-feedback { background: #742a2a; border-color: #9b2c2c; color: #feb2b2; }
html[data-theme="dark"] .att-edit-modal-footer { border-top-color: var(--ork-border); }
html[data-theme="dark"] .att-edit-btn-cancel { background: var(--ork-bg-secondary); color: var(--ork-text); border-color: var(--ork-border); }
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
				<a class="rp-scope-chip" href="<?=UIR.'Kingdom/profile/'.$kid?>">
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
<?php if ($CanAddAttendance) : ?>
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
<?php endif; ?>

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
				<tr data-att-id="<?=(int)$row['AttendanceId']?>" data-att-date="<?=htmlspecialchars($row['Date']??'')?>" data-att-class="<?=(int)$row['ClassId']?>" data-att-mundane="<?=(int)$row['MundaneId']?>">
					<td><a href="<?=UIR.'Park/profile/'.$row['ParkId']?>"><?=htmlspecialchars($row['ParkName'])?></a></td>
					<td>
<?php if ((int)$row['MundaneId'] === 0) : ?>
						<?=htmlspecialchars($row['AttendancePersona'])?><?=strlen($row['Note']??'')>0?' ('.htmlspecialchars($row['Note']).')':''?>
<?php else : ?>
						<a href="<?=UIR.'Player/profile/'.$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'])?></a>
<?php endif; ?>
					</td>
					<td><?php if (!empty($row['FromKingdomId'])) : ?><a href="<?=UIR.'Kingdom/profile/'.$row['FromKingdomId']?>"><?=htmlspecialchars($row['FromKingdomName']??'')?></a><?php else : ?><?=htmlspecialchars($row['FromKingdomName']??'')?><?php endif; ?></td>
					<td><?php if (!empty($row['FromParkId'])) : ?><a href="<?=UIR.'Park/profile/'.$row['FromParkId']?>"><?=htmlspecialchars($row['FromParkName']??'')?></a><?php else : ?><?=htmlspecialchars($row['FromParkName']??'')?><?php endif; ?></td>
					<td class="att-class-cell"><?=htmlspecialchars(strlen($row['Flavor']??'')>0?$row['Flavor']:$row['ClassName'])?></td>
					<td class="att-credits-cell"><?=(int)$row['Credits']?></td>
					<td class="att-enteredby-cell" data-enteredby-id="<?=(int)$row['EnteredById']?>"><a href="<?=UIR.'Player/profile/'.$row['EnteredById']?>"><?=htmlspecialchars($row['EnteredBy']??'')?></a></td>
<?php if ($has_events) : ?><td><?php if (!empty($row['EventId'])) : ?><a href="<?=UIR.'Event/detail/'.$row['EventId'].'/'.$row['EventCalendarDetailId']?>"><?=htmlspecialchars($row['EventName']??'')?></a><?php endif; ?></td><?php endif; ?>
<?php if ($CanAddAttendance) : ?>
					<td style="text-align:center;white-space:nowrap;">
						<button class="att-edit-btn" title="Edit class &amp; credits" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:0.8rem;margin-right:4px;" onclick="attOpenEdit(this)"><i class="fas fa-pencil-alt"></i></button>
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

<?php if ($CanAddAttendance) : ?>
<!-- ── Edit Attendance Modal ──────────────────────────── -->
<div class="att-edit-overlay" id="att-edit-overlay">
	<div class="att-edit-modal">
		<div class="att-edit-modal-header">
			<div class="att-edit-modal-title">
				<i class="fas fa-pencil-alt"></i> Edit Attendance
			</div>
			<button class="att-edit-modal-close" id="att-edit-close" title="Close">&times;</button>
		</div>
		<div class="att-edit-modal-body">
			<div class="att-edit-feedback" id="att-edit-feedback" style="display:none"></div>
			<input type="hidden" id="att-edit-id">
			<input type="hidden" id="att-edit-date">
			<input type="hidden" id="att-edit-mundane">
			<div class="att-edit-row">
				<div class="att-edit-field">
					<label class="att-edit-label">Class</label>
					<select class="att-edit-select" id="att-edit-class"></select>
				</div>
				<div class="att-edit-field att-edit-field-sm">
					<label class="att-edit-label">Credits</label>
					<input class="att-edit-input" type="number" id="att-edit-credits" min="0.5" max="4" step="0.5">
				</div>
			</div>
		</div>
		<div class="att-edit-modal-footer">
			<button class="att-edit-btn-cancel" id="att-edit-cancel">Cancel</button>
			<button class="att-edit-btn-save" id="att-edit-save">Save</button>
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
<?php endif; ?>

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
			{ targets: [5], type: 'num', className: 'dt-right' },
<?php if ($has_events) : ?>
			{ targets: [7], orderable: false, searchable: false },
<?php endif; ?>
<?php if ($CanAddAttendance) : ?>
			{ targets: [-1], orderable: false, searchable: false },
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
		chart: { renderTo: 'att-park-chart', type: 'column', backgroundColor: 'transparent',
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
		chart: { renderTo: 'att-class-chart', type: 'column', backgroundColor: 'transparent',
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

<?php if ($CanAddAttendance) : ?>
	/* ── Edit Attendance Modal ─────────────────────────── */
	var attLastCredits = 1;
	var ATT_CLASSES = <?=json_encode(array_values($Classes['Classes'] ?? []))?>;

	ATT_CLASSES.forEach(function(c) {
		$('#att-edit-class').append($('<option>').val(c.ClassId).text(c.Name));
	});

	window.attOpenEdit = function(btn) {
		var $tr = $(btn).closest('tr');
		$('#att-edit-id').val($tr.data('att-id'));
		$('#att-edit-date').val($tr.data('att-date'));
		$('#att-edit-mundane').val($tr.data('att-mundane'));
		$('#att-edit-class').val($tr.data('att-class')).css('border-color', '');
		$('#att-edit-credits').val(attLastCredits).css('border-color', '');
		$('#att-edit-feedback').hide().text('');
		$('#att-edit-save').prop('disabled', false).text('Save');
		$('#att-edit-overlay').addClass('att-edit-open');
		document.body.style.overflow = 'hidden';
	};

	function attCloseEdit() {
		$('#att-edit-overlay').removeClass('att-edit-open');
		document.body.style.overflow = '';
	}

	$('#att-edit-close, #att-edit-cancel').on('click', attCloseEdit);
	$('#att-edit-overlay').on('click', function(e) {
		if (e.target === this) attCloseEdit();
	});
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && $('#att-edit-overlay').hasClass('att-edit-open')) attCloseEdit();
	});

	$('#att-edit-save').on('click', function() {
		var newClassId = parseInt($('#att-edit-class').val(), 10);
		var newCredits = parseFloat($('#att-edit-credits').val());
		if (!newClassId) { $('#att-edit-class').css('border-color', '#ef4444'); return; }
		if (isNaN(newCredits) || newCredits < 0) { $('#att-edit-credits').css('border-color', '#ef4444'); return; }
		var attId     = $('#att-edit-id').val();
		var date      = $('#att-edit-date').val();
		var mundaneId = $('#att-edit-mundane').val();
		$('#att-edit-save').prop('disabled', true).text('…');
		$.post('<?=UIR?>AttendanceAjax/attendance/' + attId + '/edit', {
			Date: date, Credits: newCredits, ClassId: newClassId, MundaneId: mundaneId
		}, function(r) {
			if (r.status === 0) {
				var newClassName = $('#att-edit-class option:selected').text();
				var $tr = $('#att-kingdom-table').find('tr[data-att-id="' + attId + '"]');
				$tr.data('att-class', newClassId);
				$tr.find('.att-class-cell').text(newClassName);
				$tr.find('.att-credits-cell').text(newCredits);
				if (r.editor_id && r.editor_persona) {
					$tr.find('.att-enteredby-cell').html('<a href="<?=UIR?>Player/profile/' + r.editor_id + '">' + $('<span>').text(r.editor_persona).html() + '</a>');
				}
				attLastCredits = newCredits;
				attCloseEdit();
			} else {
				$('#att-edit-save').prop('disabled', false).text('Save');
				$('#att-edit-feedback').text(r.error || 'Failed to save.').show();
			}
		}, 'json').fail(function() {
			$('#att-edit-save').prop('disabled', false).text('Save');
			$('#att-edit-feedback').text('Request failed.').show();
		});
	});
<?php endif; ?>
});
</script>
