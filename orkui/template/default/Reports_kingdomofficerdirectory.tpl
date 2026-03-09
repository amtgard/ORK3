<?php
$_od_rows = is_array($OfficerDirectory) ? $OfficerDirectory : [];
$_od_total = count($_od_rows);

/* Count vacancies per role */
$_vacant = ['Monarch' => 0, 'Regent' => 0, 'PM' => 0, 'Champion' => 0, 'GMR' => 0];
foreach ($_od_rows as $_r) {
	if (empty($_r['MonarchPersona']))  $_vacant['Monarch']++;
	if (empty($_r['RegentPersona']))   $_vacant['Regent']++;
	if (empty($_r['PMPersona']))       $_vacant['PM']++;
	if (empty($_r['ChampionPersona'])) $_vacant['Champion']++;
	if (empty($_r['GMRPersona']))      $_vacant['GMR']++;
}
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<style>
.od-vacant { color: var(--rp-text-hint); font-style: italic; font-size: 12px; }
.od-officer-link { color: var(--rp-accent); text-decoration: none; font-weight: 500; }
.od-officer-link:hover { color: var(--rp-accent-mid); text-decoration: underline; }
.od-vacant-badge {
	display: inline-block; padding: 1px 7px; border-radius: 9px;
	font-size: 10px; font-weight: 700; text-transform: uppercase;
	letter-spacing: 0.05em; background: #fff1f2; color: #be123c;
	border: 1px solid #fecdd3;
}
</style>

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-crown rp-header-icon"></i>
				<h1 class="rp-header-title">Kingdom Officer Directory</h1>
			</div>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" id="od-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Current kingdom-level officers across all <?=$_od_total?> kingdoms. Officers are those assigned to the kingdom seat (not individual parks).</span>
	</div>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chess-rook"></i></div>
			<div class="rp-stat-number"><?=$_od_total?></div>
			<div class="rp-stat-label">Kingdoms</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-crown"></i></div>
			<div class="rp-stat-number"><?=$_vacant['Monarch']?></div>
			<div class="rp-stat-label">Vacant Monarchs</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-scroll"></i></div>
			<div class="rp-stat-number"><?=$_vacant['Regent']?></div>
			<div class="rp-stat-label">Vacant Regents</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-sword"></i></div>
			<div class="rp-stat-number"><?=$_vacant['Champion']?></div>
			<div class="rp-stat-label">Vacant Champions</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-book"></i></div>
			<div class="rp-stat-number"><?=$_vacant['GMR']?></div>
			<div class="rp-stat-label">Vacant GMRs</div>
		</div>
	</div>

	<div class="rp-table-area">
<?php if ($_od_total === 0): ?>
		<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			<i class="fas fa-crown" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.3;"></i>
			No kingdoms found.
		</div>
<?php else: ?>
		<table id="od-table" class="dataTable" style="width:100%">
			<thead>
				<tr>
					<th>Kingdom</th>
					<th>Monarch</th>
					<th>Regent</th>
					<th>Prime Minister</th>
					<th>Champion</th>
					<th>GMR</th>
				</tr>
			</thead>
			<tbody>
<?php
function _od_cell($persona, $id, $uir) {
	if (empty($persona) || !$id) return '<span class="od-vacant-badge">Vacant</span>';
	return '<a class="od-officer-link" href="' . $uir . 'Player/index/' . (int)$id . '">' . htmlspecialchars($persona) . '</a>';
}
foreach ($_od_rows as $row):
?>
				<tr>
					<td><a class="od-officer-link" href="<?=UIR?>Kingdom/index/<?=$row['KingdomId']?>"><?=htmlspecialchars($row['KingdomName'])?></a></td>
					<td><?=_od_cell($row['MonarchPersona'],  $row['MonarchId'],  UIR)?></td>
					<td><?=_od_cell($row['RegentPersona'],   $row['RegentId'],   UIR)?></td>
					<td><?=_od_cell($row['PMPersona'],       $row['PMId'],       UIR)?></td>
					<td><?=_od_cell($row['ChampionPersona'], $row['ChampionId'], UIR)?></td>
					<td><?=_od_cell($row['GMRPersona'],      $row['GMRId'],      UIR)?></td>
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
<script>
(function () {
	if (!$('#od-table').length) return;

	var table = $('#od-table').DataTable({
		dom        : 'lfrtip',
		pageLength : 50,
		scrollX    : true,
		order      : [[0, 'asc']],
		fixedHeader: { headerOffset: 48 },
		columnDefs : [{ targets: '_all', type: 'string' }]
	});

	/* CSV export — strips HTML from cells */
	$('#od-btn-csv').on('click', function () {
		var headers = [];
		$('#od-table thead th').each(function () { headers.push($(this).text().trim()); });
		var rows = [headers.join(',')];
		table.rows({ search: 'applied' }).every(function () {
			var cells = [];
			$(this.node()).find('td').each(function () {
				var text = $(this).text().trim().replace(/"/g, '""');
				cells.push('"' + text + '"');
			});
			rows.push(cells.join(','));
		});
		var blob = new Blob([rows.join('\n')], { type: 'text/csv' });
		var a    = document.createElement('a');
		a.href   = URL.createObjectURL(blob);
		a.download = 'kingdom-officer-directory.csv';
		a.click();
	});
}());
</script>
