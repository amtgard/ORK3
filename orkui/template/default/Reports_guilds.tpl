<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$total         = 0;
$unique_guilds  = [];
$unique_members = [];

if (is_array($Guilds)) {
	foreach ($Guilds as $guild) {
		$total++;
		if (!empty($guild['ClassName']))  $unique_guilds[$guild['ClassName']] = true;
		if (!empty($guild['MundaneId']))  $unique_members[$guild['MundaneId']] = true;
	}
}

$guild_names = array_keys($unique_guilds);
sort($guild_names);

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';

if (isset($this->__session->park_id) && !empty($Guilds)) {
	$first       = reset($Guilds);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)$this->__session->park_id;
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (isset($this->__session->kingdom_id) && !empty($Guilds)) {
	$first       = reset($Guilds);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/profile/' . (int)$this->__session->kingdom_id;
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
				<i class="fas fa-shield-alt rp-header-icon"></i>
				<h1 class="rp-header-title">Kingdom Guilds</h1>
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
		<span>Active guild members within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?> based on class attendance in the last 6 months.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number"><?=count($unique_guilds)?></div>
			<div class="rp-stat-label">Active Guilds</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=count($unique_members)?></div>
			<div class="rp-stat-label">Guild Members</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-list"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Total Memberships</div>
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
<?php if (!empty($guild_names)) : ?>
					<p style="font-size:0.8rem;color:#6b7280;margin:0 0 8px;">Filter by guild:</p>
					<div class="rp-guild-pills">
						<button class="rp-guild-pill rp-guild-pill-active" data-guild="">All</button>
<?php foreach ($guild_names as $gname) : ?>
						<button class="rp-guild-pill" data-guild="<?=htmlspecialchars($gname, ENT_QUOTES)?>"><?=htmlspecialchars($gname)?></button>
<?php endforeach; ?>
					</div>
<?php else : ?>
					<p class="rp-no-filters">No guilds found.</p>
<?php endif; ?>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Guild</span>
						<span class="rp-col-guide-desc">The class/guild name.</span>
					</div>
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
						<span class="rp-col-guide-name">Player</span>
						<span class="rp-col-guide-desc">Player's persona; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Attendance</span>
						<span class="rp-col-guide-desc">Number of times this player has attended as this class in the reporting period.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="guilds-report-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Guild</th>
<?php if (!isset($this->__session->kingdom_id)) : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Player</th>
						<th>Attendance</th>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($Guilds)) : ?>
<?php 	foreach ($Guilds as $guild) : ?>
				<tr>
					<td><?=htmlspecialchars($guild['ClassName'])?></td>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
					<td><a href='<?=UIR.'Kingdom/profile/'.$guild['KingdomId']?>'><?=htmlspecialchars($guild['KingdomName'])?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
					<td><a href='<?=UIR.'Park/profile/'.$guild['ParkId']?>'><?=htmlspecialchars($guild['ParkName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Player/profile/'.$guild['MundaneId']?>'><?=htmlspecialchars($guild['Persona'])?></a></td>
					<td><?=(int)$guild['AttendanceCount']?></td>
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
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
$(function() {
	var attendanceCol = <?= 2
		+ (!isset($this->__session->kingdom_id) ? 1 : 0)
		+ (!isset($this->__session->park_id)    ? 1 : 0) ?>;

	var table = $('#guilds-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Kingdom Guilds', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [attendanceCol], type: 'num', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[0, 'asc'], [attendanceCol, 'desc']],
		fixedHeader : { headerOffset: 48 },
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	/* ── Guild pill filter ──────────────────────────────── */
	$('.rp-guild-pill').on('click', function() {
		$('.rp-guild-pill').removeClass('rp-guild-pill-active');
		$(this).addClass('rp-guild-pill-active');
		var guild = $(this).data('guild');
		if (guild) {
			table.column(0).search('^' + $.fn.dataTable.util.escapeRegex(guild) + '$', true, false).draw();
		} else {
			table.column(0).search('').draw();
		}
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
