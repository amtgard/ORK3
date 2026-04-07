<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$rows       = is_array($results ?? null) ? $results : [];
$stats      = is_array($stats ?? null) ? $stats : [];
$test_label = $test_label ?? 'Test';
$test_icon  = $test_icon  ?? 'fa-check';
$now        = time();

$qualPct = ($stats['ActivePlayers'] ?? 0) > 0
	? round(($stats['ActiveQualified'] / $stats['ActivePlayers']) * 100, 1)
	: 0;

/* Scope chip */
$scope_label = isset($KingdomName) ? $KingdomName : '';
$scope_link  = '';
$scope_icon  = 'fa-chess-rook';
if (($ScopeType ?? '') === 'kingdom' && !empty($ScopeId)) {
	$scope_link = UIR . 'Kingdom/profile/' . (int)$ScopeId;
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
.rp-pass-badge, .rp-fail-badge {
	display: inline-block;
	font-size: 0.75rem;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 4px;
	margin-left: 6px;
	vertical-align: middle;
}
.rp-pass-badge {
	background: #c6f6d5;
	color: #276749;
}
.rp-fail-badge {
	background: #fed7d7;
	color: #9b2c2c;
}
.rp-flag-badge {
	display: inline-block;
	font-size: 0.72rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 4px;
	background: #fefcbf;
	color: #975a16;
}
.rp-expired-row td {
	color: #a0aec0 !important;
}
.rp-expired-row td a {
	color: #a0aec0 !important;
}
</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas <?=htmlspecialchars($test_icon)?> rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($test_label)?> Results</h1>
			</div>
<?php if ($scope_label) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=$scope_link?>">
					<i class="fas <?=$scope_icon?>"></i>
					<?=htmlspecialchars($scope_label)?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>All <?=$test_label?> completions within <?=$scope_label ? htmlspecialchars($scope_label) : 'this kingdom'?>.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=$qualPct?>%</div>
			<div class="rp-stat-label">Active Qualified</div>
			<div class="rp-stat-hint"><?=$stats['ActiveQualified'] ?? 0?> of <?=$stats['ActivePlayers'] ?? 0?> active players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
			<div class="rp-stat-number"><?=$stats['PassRate6Mo'] ?? 0?>%</div>
			<div class="rp-stat-label">Pass Rate (6 mo)</div>
			<div class="rp-stat-hint"><?=$stats['PassRate6MoTotal'] ?? 0?> attempts</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-question-circle"></i></div>
			<div class="rp-stat-number"><?=$stats['ActiveQuestions'] ?? 0?></div>
			<div class="rp-stat-label">Active Questions</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-flag"></i></div>
			<div class="rp-stat-number"><?=$stats['FlaggedQuestions'] ?? 0?></div>
			<div class="rp-stat-label">Flagged Questions</div>
		</div>
	</div>

	<!-- ── Charts placeholder ─────────────────────────────── -->
	<div class="rp-charts-row" id="rp-charts-row"></div>

	<!-- ── Body: sidebar + table ──────────────────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">When the test was completed.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Player</span>
						<span class="rp-col-guide-desc">Player's persona name; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The player's home park.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Score</span>
						<span class="rp-col-guide-desc">Percentage score with pass/fail indicator.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Qualified Until</span>
						<span class="rp-col-guide-desc">Expiration date. Grayed rows are expired.</span>
					</div>
				</div>
			</div>
		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="test-results-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
						<th>Player</th>
						<th>Park</th>
						<th>Score</th>
						<th>Qualified Until</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as $row) :
	$passed  = $row['ScorePercent'] >= $row['PassPercent'];
	$expired = strtotime($row['ExpiresAt']) < $now;
?>
				<tr class="<?=$expired ? 'rp-expired-row' : ''?>">
					<td data-order="<?=htmlspecialchars($row['PassedAt'])?>"><?=date('M j, Y', strtotime($row['PassedAt']))?></td>
					<td><a href="<?=UIR?>Player/profile/<?=$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'] ?: 'No Persona')?></a></td>
					<td><?php if ($row['ParkId']): ?><a href="<?=UIR?>Park/profile/<?=$row['ParkId']?>"><?=htmlspecialchars($row['ParkName'])?></a><?php else: ?>—<?php endif; ?></td>
					<td data-order="<?=$row['ScorePercent']?>"><?=$row['ScorePercent']?>%<?php if ($passed): ?><span class="rp-pass-badge">Pass</span><?php else: ?><span class="rp-fail-badge">Fail</span><?php endif; ?></td>
					<td data-order="<?=htmlspecialchars($row['ExpiresAt'])?>"><?=$passed ? date('M j, Y', strtotime($row['ExpiresAt'])) : '—'?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
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
	var table = $('#test-results-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: <?=json_encode($test_label . ' Results')?>, exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [0], type: 'date' },
			{ targets: [3], className: 'dt-right' },
			{ targets: [4], type: 'date', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[0, 'desc']],
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
