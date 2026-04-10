<?php
$data = $reconciliation;
$inactive_with_att = is_array($data['InactiveWithAttendance']) ? $data['InactiveWithAttendance'] : array();
$active_no_att     = is_array($data['ActiveNoAttendance'])     ? $data['ActiveNoAttendance']     : array();
$total_inactive = count($inactive_with_att);
$total_active   = count($active_no_att);

$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
if (($report_type ?? null) === 'Park') {
	if (!empty($inactive_with_att)) {
		$scope_label = $inactive_with_att[0]['ParkName'];
	} elseif (!empty($active_no_att)) {
		$scope_label = $active_no_att[0]['ParkName'];
	}
	$scope_link = UIR . 'Park/profile/' . (int)($report_id ?? 0);
	$scope_icon = 'fa-tree';
} elseif (($report_type ?? null) === 'Kingdom') {
	if (!empty($inactive_with_att)) {
		$scope_label = $inactive_with_att[0]['KingdomName'];
	} elseif (!empty($active_no_att)) {
		$scope_label = $active_no_att[0]['KingdomName'];
	}
	$scope_link = UIR . 'Kingdom/profile/' . (int)($report_id ?? 0);
	$scope_icon = 'fa-chess-rook';
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
.psr-section { margin-bottom: 32px; }
.psr-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-radius: 6px;
	margin-bottom: 12px;
}
.psr-section-header h3 {
	margin: 0; font-size: 15px; font-weight: 600;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.psr-section-header .psr-count {
	font-size: 13px; font-weight: 500; opacity: 0.85;
}
.psr-reactivate-header { background: #ebf8ff; color: #2b6cb0; border: 1px solid #bee3f8; }
.psr-deactivate-header { background: #fff5f5; color: #c53030; border: 1px solid #fed7d7; }

.psr-bulk-bar {
	display: flex; align-items: center; gap: 12px;
	padding: 8px 12px; background: #f7fafc; border: 1px solid #e2e8f0;
	border-radius: 5px; margin-bottom: 10px; font-size: 13px;
}
.psr-bulk-bar button {
	border: none; border-radius: 4px; padding: 5px 14px;
	font-size: 13px; font-weight: 500; cursor: pointer; color: #fff;
	transition: opacity 0.15s;
}
.psr-bulk-bar button:disabled { opacity: 0.4; cursor: default; }
.psr-bulk-bar button:not(:disabled):hover { opacity: 0.85; }
.psr-btn-reactivate { background: #2b6cb0; }
.psr-btn-deactivate { background: #c53030; }
.psr-bulk-bar .psr-selected-count { color: #4a5568; min-width: 90px; }
.psr-bulk-bar .psr-select-all-label { color: #4a5568; cursor: pointer; user-select: none; }
.psr-bulk-bar .psr-select-all-label input { margin-right: 4px; vertical-align: middle; }

.psr-action-btn {
	border: none; border-radius: 4px; padding: 4px 12px;
	font-size: 12px; font-weight: 500; cursor: pointer; color: #fff;
	transition: opacity 0.15s;
}
.psr-action-btn:hover { opacity: 0.85; }
.psr-action-btn:disabled { opacity: 0.4; cursor: default; }
.psr-action-btn.psr-btn-reactivate { background: #2b6cb0; }
.psr-action-btn.psr-btn-deactivate { background: #c53030; }

.psr-done-icon { color: #38a169; font-weight: 600; font-size: 13px; }
.psr-error-msg { color: #c53030; font-size: 12px; }

.psr-empty { text-align: center; padding: 24px; color: #a0aec0; font-style: italic; }

table.psr-table td input[type="checkbox"] { margin: 0; vertical-align: middle; }
/* =====================================================
   DARK MODE — Player status reconciliation (.psr-*)
   ===================================================== */
html[data-theme="dark"] .psr-reactivate-header { background: var(--ork-bg-secondary); color: #90cdf4; border-color: var(--ork-border); border-left: 3px solid #63b3ed; }
html[data-theme="dark"] .psr-reactivate-header h3 { color: #90cdf4 !important; }
html[data-theme="dark"] .psr-deactivate-header { background: var(--ork-bg-secondary); color: #fc8181; border-color: var(--ork-border); border-left: 3px solid #fc8181; }
html[data-theme="dark"] .psr-deactivate-header h3 { color: #fc8181 !important; }
html[data-theme="dark"] .psr-bulk-bar { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .psr-bulk-bar .psr-selected-count,
html[data-theme="dark"] .psr-bulk-bar .psr-select-all-label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .psr-done-icon { color: #68d391; }
html[data-theme="dark"] .psr-error-msg { color: #feb2b2; }
html[data-theme="dark"] .psr-empty { color: var(--ork-text-muted); }
html[data-theme="dark"] #psr-table-reactivate_wrapper .dataTables_filter input,
html[data-theme="dark"] #psr-table-deactivate_wrapper .dataTables_filter input,
html[data-theme="dark"] #psr-table-reactivate_wrapper .dataTables_length select,
html[data-theme="dark"] #psr-table-deactivate_wrapper .dataTables_length select {
  background: var(--ork-input-bg) !important; border-color: var(--ork-input-border) !important;
  color: var(--ork-text) !important; outline: none !important;
}
html[data-theme="dark"] #psr-table-reactivate_wrapper .dataTables_filter input:focus,
html[data-theme="dark"] #psr-table-deactivate_wrapper .dataTables_filter input:focus {
  border-color: #63b3ed !important; box-shadow: 0 0 0 3px rgba(99,179,237,0.15) !important;
}
html[data-theme="dark"] #psr-table-reactivate_wrapper .dataTables_filter label,
html[data-theme="dark"] #psr-table-deactivate_wrapper .dataTables_filter label,
html[data-theme="dark"] #psr-table-reactivate_wrapper .dataTables_length label,
html[data-theme="dark"] #psr-table-deactivate_wrapper .dataTables_length label,
html[data-theme="dark"] #psr-table-reactivate_wrapper .dataTables_info,
html[data-theme="dark"] #psr-table-deactivate_wrapper .dataTables_info { color: var(--ork-text-muted) !important; }
@media (prefers-color-scheme: dark) {
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-reactivate-header { background: var(--ork-bg-secondary); color: #90cdf4; border-color: var(--ork-border); border-left: 3px solid #63b3ed; }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-reactivate-header h3 { color: #90cdf4 !important; }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-deactivate-header { background: var(--ork-bg-secondary); color: #fc8181; border-color: var(--ork-border); border-left: 3px solid #fc8181; }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-deactivate-header h3 { color: #fc8181 !important; }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-bulk-bar { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-done-icon { color: #68d391; }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-error-msg { color: #feb2b2; }
  html:not([data-theme="light"]):not([data-theme="dark"]) .psr-empty { color: var(--ork-text-muted); }
  html:not([data-theme="light"]):not([data-theme="dark"]) #psr-table-reactivate_wrapper .dataTables_filter input,
  html:not([data-theme="light"]):not([data-theme="dark"]) #psr-table-deactivate_wrapper .dataTables_filter input,
  html:not([data-theme="light"]):not([data-theme="dark"]) #psr-table-reactivate_wrapper .dataTables_length select,
  html:not([data-theme="light"]):not([data-theme="dark"]) #psr-table-deactivate_wrapper .dataTables_length select {
    background: var(--ork-input-bg) !important; border-color: var(--ork-input-border) !important; color: var(--ork-text) !important; outline: none !important;
  }
}
</style>

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-user-check rp-header-icon"></i>
				<h1 class="rp-header-title">Player Status Reconciliation</h1>
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
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>
			This report identifies players whose active/inactive status may not match their attendance history.
			<strong>Inactive with recent attendance</strong> are marked inactive but have signed in within the past 6 months.
			<strong>Active with no recent attendance</strong> are marked active but have not signed in within the past 24 months.
		</span>
	</div>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-plus" style="color:#2b6cb0"></i></div>
			<div class="rp-stat-number"><?=$total_inactive?></div>
			<div class="rp-stat-label">Need Reactivation</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-minus" style="color:#c53030"></i></div>
			<div class="rp-stat-number"><?=$total_active?></div>
			<div class="rp-stat-label">Need Deactivation</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=$total_inactive + $total_active?></div>
			<div class="rp-stat-label">Total Mismatches</div>
		</div>
	</div>

	<div style="padding: 0 16px 24px;">

		<!-- Section 1: Inactive players with recent attendance (should be reactivated) -->
		<div class="psr-section" id="psr-section-reactivate">
			<div class="psr-section-header psr-reactivate-header">
				<h3><i class="fas fa-user-plus"></i> Inactive Players with Recent Attendance</h3>
				<span class="psr-count"><?=$total_inactive?> player<?=$total_inactive !== 1 ? 's' : ''?></span>
			</div>
<?php if ($total_inactive === 0): ?>
			<div class="psr-empty">No mismatches found — all inactive players have no recent attendance.</div>
<?php else: ?>
<?php if ($can_edit): ?>
			<div class="psr-bulk-bar" id="psr-bulk-reactivate">
				<label class="psr-select-all-label"><input type="checkbox" id="psr-select-all-reactivate" onchange="psrToggleAll('reactivate', this.checked)"> Select all</label>
				<span class="psr-selected-count" id="psr-count-reactivate">0 selected</span>
				<button class="psr-btn-reactivate" id="psr-bulk-btn-reactivate" disabled onclick="psrBulkAction('reactivate', 1)"><i class="fas fa-user-plus"></i> Reactivate Selected</button>
			</div>
<?php endif; ?>
			<table class="display psr-table" id="psr-table-reactivate" style="width:100%">
				<thead>
					<tr>
<?php if ($can_edit): ?>
						<th style="width:32px" data-orderable="false"></th>
<?php endif; ?>
<?php if ($report_type !== 'Park'): ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Last Sign-In</th>
						<th>Sign-Ins (6 mo)</th>
<?php if ($can_edit): ?>
						<th style="width:100px" data-orderable="false">Action</th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($inactive_with_att as $row): ?>
					<tr data-mundane-id="<?=(int)$row['MundaneId']?>">
<?php if ($can_edit): ?>
						<td><input type="checkbox" class="psr-cb-reactivate" value="<?=(int)$row['MundaneId']?>" onchange="psrUpdateCount('reactivate')"></td>
<?php endif; ?>
<?php if ($report_type !== 'Park'): ?>
						<td><a href="<?=UIR?>Park/profile/<?=(int)$row['ParkId']?>"><?=htmlspecialchars($row['ParkName'])?></a></td>
<?php endif; ?>
						<td><a href="<?=UIR?>Player/profile/<?=(int)$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'])?></a></td>
						<td><?=htmlspecialchars($row['LastSignIn'])?></td>
						<td class="dt-right"><?=(int)$row['SignInCount']?></td>
<?php if ($can_edit): ?>
						<td class="psr-action-cell">
							<button class="psr-action-btn psr-btn-reactivate" onclick="psrSingleAction(this, <?=(int)$row['MundaneId']?>, 1)"><i class="fas fa-user-plus"></i> Reactivate</button>
						</td>
<?php endif; ?>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div>

		<!-- Section 2: Active players with no recent attendance (should be deactivated) -->
		<div class="psr-section" id="psr-section-deactivate">
			<div class="psr-section-header psr-deactivate-header">
				<h3><i class="fas fa-user-minus"></i> Active Players with No Recent Attendance</h3>
				<span class="psr-count"><?=$total_active?> player<?=$total_active !== 1 ? 's' : ''?></span>
			</div>
<?php if ($total_active === 0): ?>
			<div class="psr-empty">No mismatches found — all active players have recent attendance.</div>
<?php else: ?>
<?php if ($can_edit): ?>
			<div class="psr-bulk-bar" id="psr-bulk-deactivate">
				<label class="psr-select-all-label"><input type="checkbox" id="psr-select-all-deactivate" onchange="psrToggleAll('deactivate', this.checked)"> Select all</label>
				<span class="psr-selected-count" id="psr-count-deactivate">0 selected</span>
				<button class="psr-btn-deactivate" id="psr-bulk-btn-deactivate" disabled onclick="psrBulkAction('deactivate', 0)"><i class="fas fa-user-minus"></i> Deactivate Selected</button>
			</div>
<?php endif; ?>
			<table class="display psr-table" id="psr-table-deactivate" style="width:100%">
				<thead>
					<tr>
<?php if ($can_edit): ?>
						<th style="width:32px" data-orderable="false"></th>
<?php endif; ?>
<?php if ($report_type !== 'Park'): ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Created On</th>
						<th>Last Sign-In</th>
<?php if ($can_edit): ?>
						<th style="width:100px" data-orderable="false">Action</th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($active_no_att as $row): ?>
					<tr data-mundane-id="<?=(int)$row['MundaneId']?>">
<?php if ($can_edit): ?>
						<td><input type="checkbox" class="psr-cb-deactivate" value="<?=(int)$row['MundaneId']?>" onchange="psrUpdateCount('deactivate')"></td>
<?php endif; ?>
<?php if ($report_type !== 'Park'): ?>
						<td><a href="<?=UIR?>Park/profile/<?=(int)$row['ParkId']?>"><?=htmlspecialchars($row['ParkName'])?></a></td>
<?php endif; ?>
						<td><a href="<?=UIR?>Player/profile/<?=(int)$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'])?></a></td>
						<td><?=$row['ParkMemberSince'] ? htmlspecialchars($row['ParkMemberSince']) : '<em style="color:#a0aec0">Unknown</em>'?></td>
						<td><?=$row['LastSignIn'] ? htmlspecialchars($row['LastSignIn']) : '<em style="color:#a0aec0">Never</em>'?></td>
<?php if ($can_edit): ?>
						<td class="psr-action-cell">
							<button class="psr-action-btn psr-btn-deactivate" onclick="psrSingleAction(this, <?=(int)$row['MundaneId']?>, 0)"><i class="fas fa-user-minus"></i> Deactivate</button>
						</td>
<?php endif; ?>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div>

	</div>

</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
<?php
	$react_col_start = ($can_edit ? 1 : 0) + ($report_type !== 'Park' ? 1 : 0);
	$deact_col_start = $react_col_start;
?>
	if ($('#psr-table-reactivate').length) {
		$('#psr-table-reactivate').DataTable({
			dom: 'lfrtip',
			pageLength: 25,
			order: [[<?=$react_col_start?>, 'asc']],
			columnDefs: [<?php if ($can_edit): ?>{ targets: [0], orderable: false },<?php endif; ?>]
		});
	}
	if ($('#psr-table-deactivate').length) {
		$('#psr-table-deactivate').DataTable({
			dom: 'lfrtip',
			pageLength: 25,
			order: [[<?=$deact_col_start?>, 'asc']],
			columnDefs: [<?php if ($can_edit): ?>{ targets: [0], orderable: false },<?php endif; ?>]
		});
	}
});

var PSR_AJAX_URL    = '<?=UIR?>Reports/set_player_active_json';
var PSR_SCOPE_TYPE  = '<?= addslashes($report_type ?? '') ?>';
var PSR_SCOPE_ID    = <?= (int)($report_id ?? 0) ?>;

function psrUpdateCount(group) {
	var checked = document.querySelectorAll('.psr-cb-' + group + ':checked');
	var label = document.getElementById('psr-count-' + group);
	var btn = document.getElementById('psr-bulk-btn-' + group);
	if (label) label.textContent = checked.length + ' selected';
	if (btn) btn.disabled = checked.length === 0;
}

function psrToggleAll(group, checked) {
	var boxes = document.querySelectorAll('.psr-cb-' + group);
	boxes.forEach(function(cb) {
		if (!cb.closest('tr').classList.contains('psr-row-done')) {
			cb.checked = checked;
		}
	});
	psrUpdateCount(group);
}

function psrSingleAction(btn, mundaneId, active) {
	btn.disabled = true;
	btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
	var form = new FormData();
	form.append('MundaneId', mundaneId);
	form.append('Active', active);
	form.append('ScopeType', PSR_SCOPE_TYPE);
	form.append('ScopeId', PSR_SCOPE_ID);
	fetch(PSR_AJAX_URL, { method: 'POST', body: form })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.status === 0) {
				var row = btn.closest('tr');
				row.classList.add('psr-row-done');
				var cell = btn.closest('td');
				cell.innerHTML = '<span class="psr-done-icon"><i class="fas fa-check"></i> Done</span>';
				var cb = row.querySelector('input[type="checkbox"]');
				if (cb) { cb.checked = false; cb.disabled = true; }
				var group = active === 1 ? 'reactivate' : 'deactivate';
				psrUpdateCount(group);
			} else {
				btn.disabled = false;
				btn.innerHTML = (active === 1 ? '<i class="fas fa-user-plus"></i> Reactivate' : '<i class="fas fa-user-minus"></i> Deactivate');
				var cell = btn.closest('td');
				var err = document.createElement('div');
				err.className = 'psr-error-msg';
				err.textContent = data.error || 'Failed';
				cell.appendChild(err);
				setTimeout(function() { if (err.parentNode) err.parentNode.removeChild(err); }, 5000);
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.innerHTML = (active === 1 ? '<i class="fas fa-user-plus"></i> Retry' : '<i class="fas fa-user-minus"></i> Retry');
		});
}

function psrBulkAction(group, active) {
	var boxes = document.querySelectorAll('.psr-cb-' + group + ':checked');
	if (boxes.length === 0) return;
	var label = active === 1 ? 'reactivate' : 'deactivate';
	if (!confirm('Are you sure you want to ' + label + ' ' + boxes.length + ' player' + (boxes.length > 1 ? 's' : '') + '?')) return;

	var bulkBtn = document.getElementById('psr-bulk-btn-' + group);
	if (bulkBtn) { bulkBtn.disabled = true; bulkBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...'; }

	var ids = [];
	boxes.forEach(function(cb) { ids.push(parseInt(cb.value)); });

	var completed = 0;
	var errors = 0;

	function processNext(i) {
		if (i >= ids.length) {
			if (bulkBtn) {
				bulkBtn.innerHTML = (active === 1 ? '<i class="fas fa-user-plus"></i> Reactivate Selected' : '<i class="fas fa-user-minus"></i> Deactivate Selected');
				bulkBtn.disabled = true;
			}
			psrUpdateCount(group);
			return;
		}
		var form = new FormData();
		form.append('MundaneId', ids[i]);
		form.append('Active', active);
		form.append('ScopeType', PSR_SCOPE_TYPE);
		form.append('ScopeId', PSR_SCOPE_ID);
		fetch(PSR_AJAX_URL, { method: 'POST', body: form })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				var row = document.querySelector('tr[data-mundane-id="' + ids[i] + '"]');
				if (data.status === 0 && row) {
					row.classList.add('psr-row-done');
					var actionCell = row.querySelector('.psr-action-cell');
					if (actionCell) actionCell.innerHTML = '<span class="psr-done-icon"><i class="fas fa-check"></i> Done</span>';
					var cb = row.querySelector('input[type="checkbox"]');
					if (cb) { cb.checked = false; cb.disabled = true; }
					completed++;
				} else {
					errors++;
					if (row) {
						var actionCell = row.querySelector('.psr-action-cell');
						if (actionCell) {
							var errSpan = actionCell.querySelector('.psr-error-msg');
							if (!errSpan) { errSpan = document.createElement('div'); errSpan.className = 'psr-error-msg'; actionCell.appendChild(errSpan); }
							errSpan.textContent = data.error || 'Failed';
						}
					}
				}
				processNext(i + 1);
			})
			.catch(function() {
				errors++;
				processNext(i + 1);
			});
	}
	processNext(0);
}
</script>
