<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total             = 0;
$unique_recipients = [];
$unique_award_names = [];

if (is_array($Awards)) {
	foreach ($Awards as $award) {
		$total++;
		if (!empty($award['MundaneId']))       $unique_recipients[$award['MundaneId']] = true;
		if (!empty($award['CustomAwardName'])) $unique_award_names[$award['CustomAwardName']] = true;
	}
}

$report_title = $page_title ?? 'Custom Awards';

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
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-ribbon rp-header-icon"></i>
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
		<span>Park-defined custom awards granted to players within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?>.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-ribbon"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Total Custom Awards</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=count($unique_recipients)?></div>
			<div class="rp-stat-label">Unique Recipients</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-list"></i></div>
			<div class="rp-stat-number"><?=count($unique_award_names)?></div>
			<div class="rp-stat-label">Distinct Award Types</div>
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
					<p class="rp-no-filters">This report has no filter options.</p>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
<?php if (!isset($this->__session->park_id)) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">The player's home kingdom.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The park that granted the award.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">The player who received the award.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Custom Award</span>
						<span class="rp-col-guide-desc">Name of the park-defined custom award.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">Date the award was granted.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Given By</span>
						<span class="rp-col-guide-desc">The officer or player who granted the award.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Note</span>
						<span class="rp-col-guide-desc">Any notes or reason attached to the award.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="customawards-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (!isset($this->__session->park_id)) : ?>
						<th>Kingdom</th>
<?php endif; ?>
						<th>Park</th>
						<th>Persona</th>
						<th>Custom Award</th>
						<th>Date</th>
						<th>Given By</th>
						<th>Note</th>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($Awards)) : ?>
<?php 	foreach ($Awards as $award) : ?>
				<tr>
<?php 		if (!isset($this->__session->park_id)) : ?>
					<td><a href='<?=UIR.'Kingdom/profile/'.$award['KingdomId']?>'><?=htmlspecialchars($award['KingdomName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Park/profile/'.$award['ParkId']?>'><?=htmlspecialchars($award['ParkName'])?></a></td>
					<td><a href='<?=UIR.'Player/profile/'.$award['MundaneId']?>'><?=htmlspecialchars($award['Persona'])?></a></td>
					<td><?=htmlspecialchars($award['CustomAwardName'])?></td>
					<td><?=htmlspecialchars($award['Date'] ?? '')?></td>
					<td><?php if (valid_id($award['GivenById'])) : ?><a href='<?=UIR.'Player/profile/'.$award['GivenById']?>'><?=htmlspecialchars($award['GivenBy'])?></a><?php else : ?><?=htmlspecialchars($award['GivenBy'] ?? '')?><?php endif; ?></td>
					<td><?=htmlspecialchars($award['Note'] ?? '')?></td>
				</tr>
<?php 	endforeach; ?>
<?php endif; ?>
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
	var dateCol = <?= (!isset($this->__session->park_id) ? 1 : 0) + 3 ?>;

	var table = $('#customawards-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: '<?=addslashes($report_title)?>', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [dateCol], type: 'date', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: <?php
			$sortOrder = [];
			$parkCol    = !isset($this->__session->park_id) ? 1 : 0;
			$sortOrder[] = [$parkCol, 'asc'];
			$personaCol  = (!isset($this->__session->park_id) ? 1 : 0) + 1;
			$sortOrder[] = [$personaCol, 'asc'];
			echo json_encode($sortOrder);
		?>,
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
