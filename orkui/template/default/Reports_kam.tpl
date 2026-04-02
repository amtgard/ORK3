<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total          = 0;
$total_knights  = 0;
$total_masters  = 0;
$unique_parks   = [];
$unique_kingdoms = [];

if (is_array($Awards)) {
	foreach ($Awards as $award) {
		$total++;
		if (($award['Peerage'] ?? '') === 'Knight') $total_knights++;
		if (($award['Peerage'] ?? '') === 'Master') $total_masters++;
		if (!empty($award['ParkId']))    $unique_parks[$award['ParkId']] = true;
		if (!empty($award['KingdomId'])) $unique_kingdoms[$award['KingdomId']] = true;
	}
}

$report_title = $page_title ?? 'Knights & Masters List';

/* Icon based on title */
$icon_map = [
	'Knights List'          => 'fa-chess-king',
	'Masters List'          => 'fa-crown',
	'Knights & Masters List'=> 'fa-award',
];
$report_icon = 'fa-award';
foreach ($icon_map as $kw => $ic) {
	if (stripos($report_title, $kw) !== false) { $report_icon = $ic; break; }
}

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';

if (isset($this->__session->park_id) && !empty($Awards)) {
	$first       = reset($Awards);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)$this->__session->park_id;
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (isset($this->__session->kingdom_id) && !empty($Awards)) {
	$first       = reset($Awards);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/profile/' . (int)$this->__session->kingdom_id;
	$scope_icon  = 'fa-chess-rook';
	$scope_noun  = 'kingdom';
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas <?=$report_icon?> rp-header-icon"></i>
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
		<span>Players <?=$scope_label ? 'within ' . htmlspecialchars($scope_label) : 'across all Kingdoms' ?> who hold a Knight or Master-level peerage award.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas <?=$report_icon?>"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Total</div>
		</div>
<?php if ($total_knights > 0) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chess-king"></i></div>
			<div class="rp-stat-number"><?=$total_knights?></div>
			<div class="rp-stat-label">Knights</div>
		</div>
<?php endif; ?>
<?php if ($total_masters > 0) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-crown"></i></div>
			<div class="rp-stat-number"><?=$total_masters?></div>
			<div class="rp-stat-label">Masters</div>
		</div>
<?php endif; ?>
<?php if (!isset($this->__session->kingdom_id)) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chess-rook"></i></div>
			<div class="rp-stat-number"><?=count($unique_kingdoms)?></div>
			<div class="rp-stat-label">Kingdoms</div>
		</div>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=count($unique_parks)?></div>
			<div class="rp-stat-label">Parks Represented</div>
		</div>
<?php endif; ?>
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
					<p class="rp-no-filters">This report has no filter options.</p>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
<?php if (!isset($this->__session->kingdom_id)) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">The player's home kingdom.</span>
					</div>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
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
						<span class="rp-col-guide-desc">The Knight or Master peerage title held by the player.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date Given</span>
						<span class="rp-col-guide-desc">The date the award was granted.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<div id="kam-loading" style="text-align:center;padding:40px 0;">
				<i class="fas fa-spinner fa-spin fa-2x" style="color:#999;"></i>
			</div>
			<div id="kam-table-wrap" style="opacity:0;">
			<table id="kam-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Award</th>
						<th>Date Given</th>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($Awards)) : ?>
<?php 	foreach ($Awards as $award) : ?>
				<tr>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
					<td><a href='<?=UIR.'Kingdom/profile/'.$award['KingdomId']?>'><?=htmlspecialchars($award['KingdomName'])?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
					<td><a href='<?=UIR.'Park/profile/'.$award['ParkId']?>'><?=htmlspecialchars($award['ParkName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Player/profile/'.$award['MundaneId']?>'><?=htmlspecialchars($award['Persona'])?></a></td>
					<td><?=htmlspecialchars($award['AwardName'])?></td>
					<td><?=htmlspecialchars($award['Date'] ?? '')?></td>
				</tr>
<?php 	endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
			</div><!-- /kam-table-wrap -->
		</div><!-- /rp-table-area -->

	</div><!-- /rp-body -->

</div><!-- /rp-root -->


<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(function() {
	var table = $('#kam-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: '<?=addslashes($report_title)?>', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: <?php
			$sortOrder = [];
			$col = 0;
			if (!isset($this->__session->kingdom_id)) { $sortOrder[] = [$col, 'asc']; $col++; }
			if (!isset($this->__session->park_id))    { $sortOrder[] = [$col, 'asc']; $col++; }
			$sortOrder[] = [$col, 'asc']; // Persona
			echo json_encode($sortOrder);
		?>,
		scrollX: true,
		initComplete: function() {
			$('#kam-loading').hide();
			$('#kam-table-wrap').css('opacity', 1);
		}
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
