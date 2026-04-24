<?php
$_has_data = is_array($Parks) && count($Parks) > 0;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
.rp-param-form { display: flex; flex-direction: column; gap: 10px; }
.rp-form-group { display: flex; flex-direction: column; gap: 3px; }
.rp-form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); }
.rp-form-input { width: 100%; border: 1px solid var(--rp-border); border-radius: 5px; padding: 6px 8px; font-size: 13px; color: var(--rp-text); background: var(--rp-card-bg); box-sizing: border-box; }
.rp-form-input:focus { outline: none; border-color: #6366f1; }
.rp-btn-run { width: 100%; padding: 8px 0; background: #4338ca; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.rp-btn-run:hover { background: #3730a3; }
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-ban rp-header-icon"></i>
				<h1 class="rp-header-title">Inactive Parks</h1>
			</div>
		</div>
		<div class="rp-header-actions">
<?php if ($_has_data): ?>
			<button class="rp-btn-ghost" id="ip-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
<?php endif; ?>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>All parks with <strong>Retired</strong> status<?= $KingdomId ? ' in <strong>' . htmlspecialchars($Kingdoms[$KingdomId] ?? '') . '</strong>' : ' across all kingdoms' ?>. <?= $_has_data ? count($Parks) . ' parks found.' : '' ?></span>
	</div>

<?php if ($_has_data): ?>
	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-ban"></i></div>
			<div class="rp-stat-number"><?=count($Parks)?></div>
			<div class="rp-stat-label">Inactive Parks</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-globe"></i></div>
			<div class="rp-stat-number"><?=count(array_unique(array_column($Parks, 'KingdomId')))?></div>
			<div class="rp-stat-label">Kingdoms</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-times"></i></div>
			<?php
				$_with_att = array_filter($Parks, fn($p) => !empty($p['LastAttendance']));
				$_no_att   = count($Parks) - count($_with_att);
			?>
			<div class="rp-stat-number"><?=$_no_att?></div>
			<div class="rp-stat-label">No Attendance Ever</div>
		</div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sliders-h"></i> Filter</div>
				<div class="rp-filter-card-body">
					<form method="GET" action="<?=UIR?>Admin/inactiveparks" class="rp-param-form">
						<div class="rp-form-group">
							<label for="KingdomId">Kingdom</label>
							<select id="KingdomId" name="KingdomId" class="rp-form-input">
								<option value="0">All Kingdoms</option>
								<?php foreach ($Kingdoms as $_kid => $_kname): ?>
								<option value="<?=(int)$_kid?>" <?=$KingdomId == $_kid ? 'selected' : ''?>><?=htmlspecialchars($_kname)?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<button type="submit" class="rp-btn-run">Filter</button>
					</form>
				</div>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
<?php if (!$_has_data): ?>
			<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
				<i class="fas fa-check-circle" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
				No inactive parks found<?= $KingdomId ? ' for the selected kingdom.' : '.' ?>
			</div>
<?php else: ?>
			<table class="rp-table display" id="ip-table" style="width:100%">
				<thead>
					<tr>
						<th>Park</th>
						<th>Kingdom</th>
						<th>Type</th>
						<th>Last Attendance</th>
						<th>Modified</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($Parks as $_park): ?>
				<tr style="cursor:pointer;" onclick="window.location.href='<?=UIR?>Park/profile/<?=(int)$_park['ParkId']?>'">
					<td><?=htmlspecialchars(stripslashes($_park['ParkName'] ?? ''))?></td>
					<td><?=htmlspecialchars(stripslashes($_park['KingdomName'] ?? ''))?></td>
					<td><?=htmlspecialchars($_park['ParkType'] ?? '—')?></td>
					<td><?= !empty($_park['LastAttendance']) ? htmlspecialchars($_park['LastAttendance']) : '<span style="color:var(--rp-text-muted)">Never</span>' ?></td>
					<td><?= !empty($_park['Modified']) ? htmlspecialchars(substr($_park['Modified'], 0, 10)) : '—' ?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script>
$(function () {
<?php if ($_has_data): ?>
	$('#ip-table').DataTable({
		dom: 'lfrtip',
		pageLength: 50,
		order: [[1, 'asc'], [0, 'asc']],
		columnDefs: [{ targets: [3, 4], type: 'date' }]
	});

	$('#ip-btn-csv').on('click', function () {
		var rows = [['Park', 'Kingdom', 'Type', 'Last Attendance', 'Modified']];
		<?php foreach ($Parks as $_park): ?>
		rows.push([
			'<?=addslashes(stripslashes($_park['ParkName'] ?? ''))?>',
			'<?=addslashes(stripslashes($_park['KingdomName'] ?? ''))?>',
			'<?=addslashes($_park['ParkType'] ?? '')?>',
			'<?=addslashes($_park['LastAttendance'] ?? '')?>',
			'<?=addslashes(substr($_park['Modified'] ?? '', 0, 10))?>'
		]);
		<?php endforeach; ?>
		var csv = rows.map(function(r) { return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		a.download = 'inactive-parks.csv';
		a.click();
	});
<?php endif; ?>
});
</script>
