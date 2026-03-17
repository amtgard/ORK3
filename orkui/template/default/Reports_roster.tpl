<?php
/* ── Determine variant & pre-compute stats ────────────────── */
$is_suspended = isset($show_suspension);
$is_duespaid  = isset($show_duespaid);

$report_title = $page_title ?? 'Player Roster';

$variant = 'full';
if ($is_suspended)                                                    $variant = 'suspended';
elseif (stripos($report_title, 'inactive')   !== false)               $variant = 'inactive';
elseif (stripos($report_title, 'unwaivered') !== false)               $variant = 'unwaivered';
elseif (stripos($report_title, 'waivered')   !== false)               $variant = 'waivered';

$icon_map = [
	'full'       => 'fa-users',
	'waivered'   => 'fa-file-signature',
	'unwaivered' => 'fa-user-times',
	'inactive'   => 'fa-user-clock',
	'suspended'  => 'fa-ban',
];
$report_icon = $icon_map[$variant] ?? 'fa-users';

$context_map = [
	'full'       => 'All players registered to this %s, regardless of activity status.',
	'waivered'   => 'Players registered to this %s who have a signed waiver on file.',
	'unwaivered' => 'Players registered to this %s who do not have a signed waiver on file.',
	'inactive'   => 'Players registered to this %s who have not attended in the last 6 months.',
	'suspended'  => 'Players currently under a suspension within this %s.',
];

$total           = 0;
$waivered_count  = 0;
$suspended_count = 0;

if (is_array($roster)) {
	foreach ($roster as $player) {
		$total++;
		if (!empty($player['Waivered']))  $waivered_count++;
		if (!empty($player['Suspended'])) $suspended_count++;
	}
}

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'kingdom';

if (isset($this->__session->park_id) && !empty($roster)) {
	$first       = reset($roster);
	$scope_label = $first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)$this->__session->park_id;
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif (isset($this->__session->kingdom_id) && !empty($roster)) {
	$first       = reset($roster);
	$scope_label = $first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/profile/' . (int)$this->__session->kingdom_id;
	$scope_icon  = 'fa-chess-rook';
}

$context_text = sprintf($context_map[$variant] ?? $context_map['full'], $scope_label ?: $scope_noun);
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
		<span><?=htmlspecialchars($context_text)?></span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas <?=$report_icon?>"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label"><?=htmlspecialchars($report_title)?></div>
		</div>
<?php if ($variant !== 'waivered') : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-file-signature"></i></div>
			<div class="rp-stat-number"><?=$waivered_count?></div>
			<div class="rp-stat-label">Waivered</div>
		</div>
<?php endif; ?>
<?php if ($variant !== 'suspended') : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-ban"></i></div>
			<div class="rp-stat-number"><?=$suspended_count?></div>
			<div class="rp-stat-label">Suspended</div>
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
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">Player's in-game name; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Mundane</span>
						<span class="rp-col-guide-desc">Player's real name, visible only to authorized users. Shows "Restricted" when hidden.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Waivered</span>
						<span class="rp-col-guide-desc">Whether a signed waiver is on file for this player.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Last Sign-in</span>
						<span class="rp-col-guide-desc">Date of the player's most recent attendance record.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Suspended Until</span>
						<span class="rp-col-guide-desc">Date through which the player is suspended. Blank if not currently suspended.</span>
					</div>
<?php if ($is_suspended) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Suspended At</span>
						<span class="rp-col-guide-desc">Date the suspension was recorded in the system.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Suspendator</span>
						<span class="rp-col-guide-desc">The user who entered the suspension record.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Suspension</span>
						<span class="rp-col-guide-desc">Reason or notes attached to the suspension.</span>
					</div>
<?php endif; ?>
<?php if ($is_duespaid) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Dues Paid</span>
						<span class="rp-col-guide-desc">Whether the player has a current dues record on file.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Dues Through</span>
						<span class="rp-col-guide-desc">Date through which the dues record is valid.</span>
					</div>
<?php endif; ?>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<div id="rp-roster-loading" style="text-align:center;padding:48px 32px;color:#a0aec0">
				<i class="fas fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px"></i>
				Loading report&hellip;
			</div>
			<div id="rp-roster-table-wrap" style="opacity:0">
			<table id="roster-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Mundane</th>
						<th>Waivered</th>
<?php if ($is_duespaid) : ?>
						<th>Dues Paid</th>
						<th>Dues Through</th>
<?php endif; ?>
						<th>Last Sign-in</th>
						<th>Suspended Until</th>
<?php if ($is_suspended) : ?>
						<th>Suspended At</th>
						<th>Suspendator</th>
						<th>Suspension</th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($roster)) : ?>
<?php 	foreach ($roster as $player) : ?>
				<tr<?=$player['Suspended'] ? ' class="rp-row-suspended"' : ''?>>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
					<td><a href='<?=UIR.'Kingdom/profile/'.$player['KingdomId']?>'><?=htmlspecialchars($player['KingdomName'])?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
					<td><a href='<?=UIR.'Park/profile/'.$player['ParkId']?>'><?=htmlspecialchars($player['ParkName'])?></a></td>
<?php 		endif; ?>
					<td><a href='<?=UIR.'Player/profile/'.$player['MundaneId']?>'><?= trimlen($player['Persona']) > 0 ? htmlspecialchars($player['Persona']) : '<i>No Persona</i>' ?></a></td>
					<td><?= $player['Displayable'] == 0 ? "<span class='restricted-player-display'>Restricted</span>" : htmlspecialchars($player['Surname'].', '.$player['GivenName']) ?></td>
					<td><?= $player['Waivered'] == 1 ? 'Waiver' : '' ?></td>
<?php 		if ($is_duespaid) : ?>
					<td><?= $player['DuesPaid']    ? 'Paid'              : '' ?></td>
					<td><?= $player['DuesThrough'] ? htmlspecialchars($player['DuesThrough']) : '' ?></td>
<?php 		endif; ?>
					<td><?=htmlspecialchars($player['LastSignIn'] ?? '')?></td>
					<td><?=htmlspecialchars($player['SuspendedUntil'] ?? '')?></td>
<?php 		if ($is_suspended) : ?>
					<td><?=htmlspecialchars($player['SuspendedAt']   ?? '')?></td>
					<td><?=htmlspecialchars($player['Suspendator']   ?? '')?></td>
					<td><?=htmlspecialchars($player['Suspension']    ?? '')?></td>
<?php 		endif; ?>
				</tr>
<?php 	endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
			</div><!-- /rp-roster-table-wrap -->
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
	var table = $('#roster-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{
				extend: 'csv',
				filename: '<?=addslashes($report_title)?>',
				exportOptions: { columns: ':visible' }
			},
			{
				extend: 'print',
				exportOptions: { columns: ':visible' }
			}
		],
		columnDefs: [
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
		fixedColumns: { left: 1 },
		initComplete: function() {
			$('#rp-roster-loading').hide();
			$('#rp-roster-table-wrap').css('opacity', '1');
		}
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
