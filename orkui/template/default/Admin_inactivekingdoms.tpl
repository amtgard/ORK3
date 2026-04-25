<?php
$_has_data = is_array($Kingdoms) && count($Kingdoms) > 0;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<style>
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-ban rp-header-icon"></i>
				<h1 class="rp-header-title">Inactive Kingdoms &amp; Principalities</h1>
			</div>
		</div>
		<div class="rp-header-actions">
<?php if ($_has_data): ?>
			<button class="rp-btn-ghost" id="ik-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
<?php endif; ?>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>All kingdoms and principalities with <strong>Retired</strong> status. <?= $_has_data ? count($Kingdoms) . ' found.' : '' ?></span>
	</div>

<?php if ($_has_data):
	$_kingdoms      = array_filter($Kingdoms, fn($k) => $k['ParentKingdomId'] == 0);
	$_principalities = array_filter($Kingdoms, fn($k) => $k['ParentKingdomId'] > 0);
	$_with_att      = array_filter($Kingdoms, fn($k) => !empty($k['LastAttendance']));
	$_no_att        = count($Kingdoms) - count($_with_att);
?>
	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-crown"></i></div>
			<div class="rp-stat-number"><?=count($_kingdoms)?></div>
			<div class="rp-stat-label">Inactive Kingdoms</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chess-king"></i></div>
			<div class="rp-stat-number"><?=count($_principalities)?></div>
			<div class="rp-stat-label">Inactive Principalities</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-times"></i></div>
			<div class="rp-stat-number"><?=$_no_att?></div>
			<div class="rp-stat-label">No Attendance Ever</div>
		</div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Table area (no sidebar needed — no filter params) -->
		<div class="rp-table-area" style="width:100%">
<?php if (!$_has_data): ?>
			<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
				<i class="fas fa-check-circle" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
				No inactive kingdoms or principalities found.
			</div>
<?php else: ?>
			<table class="rp-table display" id="ik-table" style="width:100%">
				<thead>
					<tr>
						<th>Name</th>
						<th>Type</th>
						<th>Parent Kingdom</th>
						<th>Last Attendance</th>
						<th>Modified</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($Kingdoms as $_k): ?>
				<tr style="cursor:pointer;" onclick="window.location.href='<?=UIR?>Kingdom/profile/<?=(int)$_k['KingdomId']?>'">
					<td><?=htmlspecialchars(stripslashes($_k['KingdomName'] ?? ''))?></td>
					<td><?=htmlspecialchars($_k['Type'])?></td>
					<td><?=htmlspecialchars(stripslashes($_k['ParentKingdomName'] ?? '—'))?></td>
					<td><?= !empty($_k['LastAttendance']) ? htmlspecialchars($_k['LastAttendance']) : '<span style="color:var(--rp-text-muted)">Never</span>' ?></td>
					<td><?= !empty($_k['Modified']) ? htmlspecialchars(substr($_k['Modified'], 0, 10)) : '—' ?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div>

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
<?php if ($_has_data): ?>
	var _skipWords = /^(the|kingdom|empire|freehold|principality|confederacy|of)\s+/i;
	function _kdSortKey(s) {
		s = s.trim();
		var prev;
		do { prev = s; s = s.replace(_skipWords, ''); } while (s !== prev);
		return s.toLowerCase();
	}
	$('#ik-table').DataTable({
		dom: 'lfrtip',
		pageLength: 50,
		order: [[0, 'asc']],
		columnDefs: [
			{ targets: [3, 4], type: 'date' },
			{
				targets: 0,
				render: function(data, type) {
					return type === 'sort' ? _kdSortKey(data) : data;
				}
			}
		]
	});

	$('#ik-btn-csv').on('click', function () {
		var rows = [['Name', 'Type', 'Parent Kingdom', 'Last Attendance', 'Modified']];
		<?php foreach ($Kingdoms as $_k): ?>
		rows.push([
			'<?=addslashes(stripslashes($_k['KingdomName'] ?? ''))?>',
			'<?=addslashes($_k['Type'])?>',
			'<?=addslashes(stripslashes($_k['ParentKingdomName'] ?? ''))?>',
			'<?=addslashes($_k['LastAttendance'] ?? '')?>',
			'<?=addslashes(substr($_k['Modified'] ?? '', 0, 10))?>'
		]);
		<?php endforeach; ?>
		var csv = rows.map(function(r) { return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		a.download = 'inactive-kingdoms.csv';
		a.click();
	});
<?php endif; ?>
});
</script>
