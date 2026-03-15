<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$players     = is_array($roster['DuesPaidList']) ? $roster['DuesPaidList'] : [];
$total        = count($players);
$dues_for_life = 0;
$suspended_ct  = 0;
$unwaivered_ct = 0;
$expiring_ct   = 0;
$now           = time();
$soon          = strtotime('+30 days');

foreach ($players as $p) {
	if (!empty($p['DuesForLife']))   $dues_for_life++;
	if (!empty($p['Suspended']))     $suspended_ct++;
	if (empty($p['Waivered']))       $unwaivered_ct++;
	if (!empty($p['DuesUntil']) && empty($p['DuesForLife'])) {
		$exp = strtotime($p['DuesUntil']);
		if ($exp !== false && $exp >= $now && $exp <= $soon) $expiring_ct++;
	}
}

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';

if (isset($this->__session->park_id) && !empty($players)) {
	$first       = reset($players);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)$this->__session->park_id;
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (isset($this->__session->kingdom_id) && !empty($players)) {
	$first       = reset($players);
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
				<i class="fas fa-dollar-sign rp-header-icon"></i>
				<h1 class="rp-header-title">Dues Paid List</h1>
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
		<span>Players within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?> who have a current dues record on file, including Dues for Life.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-dollar-sign"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Dues Paid</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-infinity"></i></div>
			<div class="rp-stat-number"><?=$dues_for_life?></div>
			<div class="rp-stat-label">Dues for Life</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
			<div class="rp-stat-number"><?=$expiring_ct?></div>
			<div class="rp-stat-label">Expiring in 30 Days</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-ban"></i></div>
			<div class="rp-stat-number"><?=$suspended_ct?></div>
			<div class="rp-stat-label">Suspended</div>
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
					<p style="font-size:0.8rem; color:var(--rp-text-muted); margin:0 0 8px;">Toggle row groups on or off:</p>
					<div class="rp-filter-pills">
						<button class="rp-filter-pill active" data-filter="unwaivered">Unwaivered</button>
						<button class="rp-filter-pill active" data-filter="duesforlife">Dues for Life</button>
						<button class="rp-filter-pill active" data-filter="suspended">Suspended</button>
					</div>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom / Park / Persona</span>
						<span class="rp-col-guide-desc">Player location and in-game name.</span>
					</div>
<?php if (!$roster['RestrictAccess']) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Mundane</span>
						<span class="rp-col-guide-desc">Player's real name (authorized users only).</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Waivered</span>
						<span class="rp-col-guide-desc">Whether a signed waiver is on file.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Suspended</span>
						<span class="rp-col-guide-desc">Whether the player currently has an active suspension.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date Paid</span>
						<span class="rp-col-guide-desc">The start date of the current dues record.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Expires</span>
						<span class="rp-col-guide-desc">Expiration date of the dues record. Blank for Dues for Life.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Dues for Life</span>
						<span class="rp-col-guide-desc">Indicates a lifetime dues record with no expiration.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="dues-report-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Kingdom</th>
						<th>Park</th>
						<th>Persona</th>
<?php if (!$roster['RestrictAccess']) : ?>
						<th>Mundane</th>
<?php endif; ?>
						<th>Waivered</th>
						<th>Suspended</th>
						<th>Date Paid</th>
						<th>Expires</th>
						<th>Dues for Life</th>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($roster['DuesPaidList'])) : ?>
<?php 	foreach ($roster['DuesPaidList'] as $player) : ?>
				<tr class="<?=(!empty($player['DuesForLife']))?'rp-row-duesforlife':''?><?=(!empty($player['Suspended']))?' rp-row-suspended':''?><?=(empty($player['Waivered']))?' rp-row-unwaivered':''?>">
					<td><a href='<?=UIR.'Kingdom/profile/'.$player['KingdomId']?>'><?=htmlspecialchars($player['KingdomName'])?></a></td>
					<td><a href='<?=UIR.'Park/profile/'.$player['ParkId']?>'><?=htmlspecialchars($player['ParkName'])?></a></td>
					<td><a href='<?=UIR.'Player/profile/'.$player['MundaneId']?>'><?=htmlspecialchars($player['Persona'])?></a></td>
<?php 		if (!$roster['RestrictAccess']) : ?>
					<td><?=htmlspecialchars($player['GivenName'] . ' ' . $player['Surname'])?></td>
<?php 		endif; ?>
					<td><?=(!empty($player['Waivered']))?'Yes':''?></td>
					<td><?=(!empty($player['Suspended']))?'Yes':''?></td>
					<td><?=htmlspecialchars($player['DuesFrom'] ?? '')?></td>
					<td><?=(!empty($player['DuesUntil']) && empty($player['DuesForLife']))?htmlspecialchars($player['DuesUntil']):''?></td>
					<td><?=(!empty($player['DuesForLife']))?'Yes':''?></td>
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
	/* ── Row filter state ────────────────────────────────── */
	var filterState = { unwaivered: true, duesforlife: true, suspended: true };

	$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
		if (settings.nTable.id !== 'dues-report-table') return true;
		var $row = $(table.row(dataIndex).node());
		if (!filterState.unwaivered && $row.hasClass('rp-row-unwaivered')) return false;
		if (!filterState.duesforlife && $row.hasClass('rp-row-duesforlife')) return false;
		if (!filterState.suspended  && $row.hasClass('rp-row-suspended'))   return false;
		return true;
	});

	var table = $('#dues-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Dues Paid List', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[0, 'asc'], [1, 'asc'], [2, 'asc']],
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	/* ── Filter pill toggles ─────────────────────────────── */
	$('.rp-filter-pill').on('click', function() {
		var key = $(this).data('filter');
		filterState[key] = !filterState[key];
		$(this).toggleClass('active', filterState[key]);
		table.draw();
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
