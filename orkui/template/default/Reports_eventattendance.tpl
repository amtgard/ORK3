<?php
$events       = $events ?? [];
$scope_type   = $report_type ?? '';
$scope_id     = (int)($report_id ?? 0);

$total_events     = count($events);
$total_attendance = 0;
$total_rsvp       = 0;
foreach ($events as $ev) {
	$total_attendance += (int)$ev['AttendanceCount'];
	$total_rsvp       += (int)$ev['RsvpCount'];
}
$avg_attendance = $total_events > 0 ? round($total_attendance / $total_events, 1) : 0;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-calendar-alt rp-header-icon"></i>
				<h1 class="rp-header-title"><?=$page_title ?? 'Event Attendance Report'?></h1>
			</div>
		</div>
		<div class="rp-header-actions">
<?php if ($total_events > 0): ?>
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
<?php endif; ?>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Historical event attendance data. Each row represents one scheduled occurrence of an event, sorted most recent first.</span>
	</div>

<?php if (!empty($error)): ?>
	<div class="rp-empty"><?=htmlspecialchars($error)?></div>
<?php elseif ($total_events === 0): ?>
	<div class="rp-empty">No events found for this <?=strtolower($scope_type)?>.</div>
<?php else: ?>

	<!-- ── Stats row ──────────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-alt"></i></div>
			<div class="rp-stat-number"><?=$total_events?></div>
			<div class="rp-stat-label">Events</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-sign-in-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($total_attendance)?></div>
			<div class="rp-stat-label">Total Sign-ins</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
			<div class="rp-stat-number"><?=$avg_attendance?></div>
			<div class="rp-stat-label">Avg per Event</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=number_format($total_rsvp)?></div>
			<div class="rp-stat-label">Total RSVPs</div>
		</div>
	</div>

	<!-- ── Table ──────────────────────────────────────────────── -->
	<div class="rp-body">
		<div class="rp-main" style="flex:1;min-width:0">
			<table id="ea-table" class="display rp-table" style="width:100%">
				<thead>
					<tr>
						<th>Event</th>
<?php if ($scope_type === 'Kingdom'): ?>
						<th>Park</th>
<?php endif; ?>
						<th>Start</th>
						<th>End</th>
						<th>Location</th>
						<th>Attendance</th>
						<th>RSVPs</th>
						<th>Price</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($events as $ev): ?>
<?php
	$city     = trim($ev['City']     ?? '');
	$province = trim($ev['Province'] ?? '');
	$location = $city . ($city && $province ? ', ' : '') . $province;
	$price    = $ev['Price'];
?>
					<tr>
						<td><a href="<?=UIR?>Event/detail/<?=(int)$ev['EventId']?>/<?=(int)$ev['DetailId']?>"><?=htmlspecialchars($ev['EventName'])?></a></td>
<?php if ($scope_type === 'Kingdom'): ?>
						<td><?=htmlspecialchars($ev['ParkName'] ?? '')?></td>
<?php endif; ?>
						<td data-order="<?=htmlspecialchars($ev['EventStart'] ?? '')?>">
							<?=!empty($ev['EventStart']) ? date('M j, Y', strtotime($ev['EventStart'])) : '—'?>
						</td>
						<td data-order="<?=htmlspecialchars($ev['EventStart'] ?? '')?>">
							<?php
								$end = $ev['EventEnd'] ?? '';
								$endTs = $end ? strtotime($end) : 0;
								echo ($endTs > 0) ? date('M j, Y', $endTs) : (!empty($ev['EventStart']) ? date('M j, Y', strtotime($ev['EventStart'])) : '—');
							?>
						</td>
						<td><?=htmlspecialchars($location)?></td>
						<td class="rp-num"><?=(int)$ev['AttendanceCount']?></td>
						<td class="rp-num"><?=(int)$ev['RsvpCount']?></td>
						<td class="rp-num"><?=($price > 0) ? '$' . number_format((float)$price, 2) : '—'?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php endif; ?>

</div><!-- .rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
(function() {
	'use strict';
	$(function() {
		var startCol = <?=$scope_type === 'Kingdom' ? 2 : 1?>;
		var dt = $('#ea-table').DataTable({
			order: [[startCol, 'desc']],
			orderClasses: false,
			pageLength: 25,
			dom: 'lfrtip',
			buttons: [
				{ extend: 'csv',   text: 'Export CSV' },
				{ extend: 'print', text: 'Print' }
			],
			language: { search: 'Filter:' }
		});
		$('.rp-btn-export').on('click', function() { dt.button('.buttons-csv').trigger(); });
		$('.rp-btn-print').on('click', function() { dt.button('.buttons-print').trigger(); });
	});
})();
</script>
