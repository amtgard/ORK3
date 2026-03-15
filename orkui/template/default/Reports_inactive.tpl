<?php
$_roster = is_array($roster) ? $roster : [];
$_total  = count($_roster);

/* Scope chip */
$_scope_label = '';
$_scope_link  = '';
$_scope_icon  = 'fa-globe';
$_scope_noun  = 'scope';

if (($ScopeType ?? '') === 'park' && $_total > 0) {
	$_first       = reset($_roster);
	$_scope_label = $_first['ParkName']    ?? '';
	$_scope_link  = UIR . 'Park/profile/'    . (int)($ScopeId ?? 0);
	$_scope_icon  = 'fa-tree';
	$_scope_noun  = 'park';
} elseif (($ScopeType ?? '') === 'kingdom' && $_total > 0) {
	$_first       = reset($_roster);
	$_scope_label = $_first['KingdomName'] ?? '';
	$_scope_link  = UIR . 'Kingdom/profile/' . (int)($ScopeId ?? 0);
	$_scope_icon  = 'fa-chess-rook';
	$_scope_noun  = 'kingdom';
}
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-user-slash rp-header-icon"></i>
				<h1 class="rp-header-title">Inactive Players</h1>
			</div>
<?php if ($_scope_label): ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=$_scope_link?>">
					<i class="fas <?=$_scope_icon?>"></i>
					<?=htmlspecialchars($_scope_label)?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Players within <?=$_scope_label ? htmlspecialchars($_scope_label) : 'this ' . $_scope_noun?> whose accounts are marked inactive. These players have not been removed but no longer appear in active rosters.</span>
	</div>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-slash"></i></div>
			<div class="rp-stat-number"><?=$_total?></div>
			<div class="rp-stat-label">Inactive Players</div>
		</div>
	</div>

	<div class="rp-table-area">
<?php if ($_total === 0): ?>
		<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			<i class="fas fa-user-check" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.3;"></i>
			No inactive players found<?=$_scope_label ? ' in ' . htmlspecialchars($_scope_label) : ''?>.
		</div>
<?php else: ?>
		<table id="inactive-table" class="dataTable" style="width:100%">
			<thead>
				<tr>
					<th>Kingdom</th>
					<th>Park</th>
					<th>Persona</th>
					<th>Waivered</th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($_roster as $player): ?>
			<tr>
				<td><a href="<?=UIR?>Kingdom/profile/<?=(int)$player['KingdomId']?>"><?=htmlspecialchars($player['KingdomName'])?></a></td>
				<td><a href="<?=UIR?>Park/profile/<?=(int)$player['ParkId']?>"><?=htmlspecialchars($player['ParkName'])?></a></td>
				<td><a href="<?=UIR?>Player/profile/<?=(int)$player['MundaneId']?>"><?=htmlspecialchars($player['Persona'] ?: '(No Persona)')?></a></td>
				<td><?=$player['Waivered'] ? 'Yes' : ''?></td>
			</tr>
<?php endforeach; ?>
			</tbody>
		</table>
<?php endif; ?>
	</div>

</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
$(function () {
	if (!$('#inactive-table').length) return;
	var table = $('#inactive-table').DataTable({
		dom        : 'lfrtip',
		buttons    : [
			{ extend: 'csv',   filename: 'inactive-players', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		pageLength : 25,
		order      : [[0, 'asc'], [1, 'asc'], [2, 'asc']],
		fixedHeader: { headerOffset: 48 },
		scrollX    : true
	});
	$('.rp-btn-export').on('click', function () { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function () { table.button(1).trigger(); });
});
</script>
