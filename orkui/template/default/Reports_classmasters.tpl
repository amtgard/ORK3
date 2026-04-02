<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total          = 0;
$unique_classes = [];

if (is_array($Awards)) {
	foreach ($Awards as $award) {
		$total++;
		if (!empty($award['AwardName'])) $unique_classes[$award['AwardName']] = true;
	}
}

$class_names  = array_keys($unique_classes);
sort($class_names);

$report_title = $page_title ?? 'Class Masters/Paragons';

/* Scope chip — use explicit controller-provided type/id, not session */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';

if (($report_type ?? null) === 'Park' && !empty($Awards)) {
	$first       = reset($Awards);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)($report_id ?? 0);
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (($report_type ?? null) === 'Kingdom' && !empty($Awards)) {
	$first       = reset($Awards);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/profile/' . (int)($report_id ?? 0);
	$scope_icon  = 'fa-chess-rook';
	$scope_noun  = 'kingdom';
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
.rp-guild-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.rp-guild-pill {
	display: inline-block; padding: 4px 10px; border-radius: 999px;
	font-size: 0.78rem; font-weight: 500; cursor: pointer;
	border: 1.5px solid #c7d2fe; background: #fff; color: #4338ca;
	transition: background 0.15s, color 0.15s, border-color 0.15s;
	line-height: 1.4;
}
.rp-guild-pill:hover { background: #eef2ff; }
.rp-guild-pill.rp-guild-pill-active { background: #4338ca; color: #fff; border-color: #4338ca; }
</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-hat-wizard rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($report_title)?></h1>
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
		<span>Players within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?> who hold a Class Master or Paragon award. Last attended date reflects most recent park attendance.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-hat-wizard"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Class Masters/Paragons</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-layer-group"></i></div>
			<div class="rp-stat-number"><?=count($unique_classes)?></div>
			<div class="rp-stat-label">Distinct Classes</div>
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
					<i class="fas fa-filter"></i> Filters
				</div>
				<div class="rp-filter-card-body">
<?php if (!empty($class_names)) : ?>
					<p style="font-size:0.8rem;color:#6b7280;margin:0 0 8px;">Filter by class:</p>
					<div class="rp-guild-pills">
						<button class="rp-guild-pill rp-guild-pill-active" data-class="">All</button>
<?php foreach ($class_names as $cname) : ?>
						<button class="rp-guild-pill" data-class="<?=htmlspecialchars($cname, ENT_QUOTES)?>"><?=htmlspecialchars(preg_replace('/^Paragon\s+/i', '', $cname))?></button>
<?php endforeach; ?>
					</div>
<?php else : ?>
					<p class="rp-no-filters">No classes found.</p>
<?php endif; ?>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
<?php if (($report_type ?? null) !== 'Kingdom') : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">The player's home kingdom.</span>
					</div>
<?php endif; ?>
<?php if (($report_type ?? null) !== 'Park') : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The player's home park.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">Player's in-game name; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Award</span>
						<span class="rp-col-guide-desc">The Class Master or Paragon award held.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Awarded On</span>
						<span class="rp-col-guide-desc">Date the award was granted.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Last Attended</span>
						<span class="rp-col-guide-desc">Date of the player's most recent park attendance.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<div id="rp-classmasters-loading" style="text-align:center;padding:48px 32px;color:#a0aec0">
				<i class="fas fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px"></i>
				Loading report&hellip;
			</div>
			<div id="rp-classmasters-table-wrap" style="opacity:0">
			<table id="classmasters-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (($report_type ?? null) !== 'Kingdom') : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if (($report_type ?? null) !== 'Park') : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Award</th>
						<th>Awarded On</th>
						<th>Last Attended</th>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($Awards)) : ?>
<?php 	foreach ($Awards as $award) : ?>
				<tr>
<?php 		if (($report_type ?? null) !== 'Kingdom') : ?>
					<td><a href='<?=UIR.'Kingdom/profile/'.$award['KingdomId']?>'><?=htmlspecialchars($award['KingdomName'])?></a></td>
<?php 		endif; ?>
<?php 		if (($report_type ?? null) !== 'Park') : ?>
					<td><a href='<?=UIR.'Park/profile/'.$award['ParkId']?>'><?=htmlspecialchars($award['ParkName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Player/profile/'.$award['MundaneId']?>'><?=htmlspecialchars($award['Persona'])?></a></td>
					<td><?=htmlspecialchars($award['AwardName'])?></td>
					<td><?=htmlspecialchars($award['Date'] ?? '')?></td>
					<td><?=htmlspecialchars($award['LastAttended'] ?? '')?></td>
				</tr>
<?php 	endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
			</div><!-- /rp-classmasters-table-wrap -->
		</div><!-- /rp-table-area -->

	</div><!-- /rp-body -->

</div><!-- /rp-root -->


<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
$(function() {
	var awardCol = <?= 1
		+ (($report_type ?? null) !== 'Kingdom' ? 1 : 0)
		+ (($report_type ?? null) !== 'Park'    ? 1 : 0) ?>;

	var table = $('#classmasters-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: '<?=addslashes($report_title)?>', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [awardCol + 1, awardCol + 2], type: 'date', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: <?php
			$sortOrder = [];
			if (($report_type ?? null) !== 'Park') {
				$parkCol    = ($report_type ?? null) !== 'Kingdom' ? 1 : 0;
				$sortOrder[] = [$parkCol, 'asc'];
			}
			$personaCol = (($report_type ?? null) !== 'Kingdom' ? 1 : 0)
				+ (($report_type ?? null) !== 'Park' ? 1 : 0);
			$sortOrder[] = [$personaCol, 'asc'];
			echo json_encode($sortOrder);
		?>,
		fixedHeader : { headerOffset: 48 },
		scrollX     : true,
		fixedColumns: { left: 1 },
		initComplete: function() {
			$('#rp-classmasters-loading').hide();
			$('#rp-classmasters-table-wrap').css('opacity', '1');
		}
	});

	/* ── Class pill filter ──────────────────────────────── */
	$('.rp-guild-pill').on('click', function() {
		$('.rp-guild-pill').removeClass('rp-guild-pill-active');
		$(this).addClass('rp-guild-pill-active');
		var cls = $(this).data('class');
		if (cls) {
			table.column(awardCol).search('^' + $.fn.dataTable.util.escapeRegex(cls) + '$', true, false).draw();
		} else {
			table.column(awardCol).search('').draw();
		}
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
