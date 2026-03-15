<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total            = 0;
$unique_recipients = [];
$unique_awards    = [];

if (is_array($Awards)) {
	foreach ($Awards as $award) {
		$total++;
		if (!empty($award['MundaneId']))  $unique_recipients[$award['MundaneId']] = true;
		if (!empty($award['AwardName'])) $unique_awards[$award['AwardName']] = true;
	}
}

$report_title = $page_title ?? 'All Awards';

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
				<i class="fas fa-medal rp-header-icon"></i>
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
		<span>All awards on record for players within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?>, including Knights, Masters, and ladder awards.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-medal"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Total Awards</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=count($unique_recipients)?></div>
			<div class="rp-stat-label">Unique Recipients</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-list"></i></div>
			<div class="rp-stat-number"><?=count($unique_awards)?></div>
			<div class="rp-stat-label">Distinct Awards</div>
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
						<span class="rp-col-guide-desc">The award granted to the player.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Rank</span>
						<span class="rp-col-guide-desc">Ladder rank, if applicable.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">Date the award was granted.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Entered By</span>
						<span class="rp-col-guide-desc">The officer who entered the award record.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="awards-report-table" class="display" style="width:100%">
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
						<th>Rank</th>
						<th>Date</th>
						<th>Entered By</th>
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
					<td><?=valid_id($award['Rank'])?htmlspecialchars($award['Rank']):''?></td>
					<td><?=htmlspecialchars($award['Date'] ?? '')?></td>
					<td><a href="<?=UIR.'Player/profile/'.$award['EnteredById']?>"><?=htmlspecialchars($award['EnteredBy'])?></a></td>
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
	var dateCol = <?= 2
		+ (!isset($this->__session->kingdom_id) ? 1 : 0)
		+ (!isset($this->__session->park_id)    ? 1 : 0) ?>;

	var table = $('#awards-report-table').DataTable({
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
			if (!isset($this->__session->park_id)) {
				$parkCol    = !isset($this->__session->kingdom_id) ? 1 : 0;
				$sortOrder[] = [$parkCol, 'asc'];
			}
			$personaCol = (!isset($this->__session->kingdom_id) ? 1 : 0)
				+ (!isset($this->__session->park_id) ? 1 : 0);
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
