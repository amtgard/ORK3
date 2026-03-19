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

$_scopeType = $ScopeType ?? (isset($this->__session->park_id) ? 'park' : (isset($this->__session->kingdom_id) ? 'kingdom' : ''));
$_scopeId   = $ScopeId   ?? ($this->__session->park_id ?? ($this->__session->kingdom_id ?? null));

if ($_scopeType === 'park') {
	$first       = !empty($roster) ? reset($roster) : [];
	$scope_label = $ScopeName ?? ($first['ParkName'] ?? '');
	$scope_link  = UIR . 'Park/profile/'    . (int)$_scopeId;
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif ($_scopeType === 'kingdom') {
	$first       = !empty($roster) ? reset($roster) : [];
	$scope_label = $ScopeName ?? ($first['KingdomName'] ?? '');
	$scope_link  = UIR . 'Kingdom/profile/' . (int)$_scopeId;
	$scope_icon  = 'fa-chess-rook';
}

if ($variant === 'suspended' && !$scope_label) {
	$context_text = 'Players currently under a suspension throughout all Kingdoms. To remove or add a suspension, see the specific Kingdom level Suspended Player Roster report.';
} else {
	$context_text = sprintf($context_map[$variant] ?? $context_map['full'], $scope_label ?: $scope_noun);
}

/* ── Pre-sort suspended roster server-side ────────────────── */
if ($variant === 'suspended' && is_array($roster) && count($roster) > 1) {
	$_showKingdom = !isset($this->__session->kingdom_id);
	$_showPark    = !isset($this->__session->park_id);
	usort($roster, function($a, $b) use ($_showKingdom, $_showPark) {
		if ($_showKingdom) {
			$c = strcmp($a['KingdomName'] ?? '', $b['KingdomName'] ?? '');
			if ($c !== 0) return $c;
		}
		if ($_showPark) {
			$c = strcmp($a['ParkName'] ?? '', $b['ParkName'] ?? '');
			if ($c !== 0) return $c;
		}
		return strcmp($a['Persona'] ?? '', $b['Persona'] ?? '');
	});
}

/* ── Remove-suspension auth ────────────────────────────────── */
$_canRemoveAny = false;
$_canRemoveMap = [];
if ($variant === 'suspended' && $this->__session->user_id) {
	$_uid        = $this->__session->user_id;
	$_isOrkAdmin = Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_ADMIN);
if ($_isOrkAdmin) {
		$_canRemoveAny = true;
		// Mark every roster player as removable
		if (is_array($roster)) {
			foreach ($roster as $player) {
				$_canRemoveMap[(int)$player['MundaneId']] = true;
			}
		}
	} elseif (is_array($roster)) {
		// Non-admin: check per player's kingdom
		foreach ($roster as $player) {
			$mid = (int)$player['MundaneId'];
			$can = Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, (int)$player['KingdomId'], AUTH_EDIT);
			$_canRemoveMap[$mid] = $can;
			if ($can) $_canRemoveAny = true;
		}
	}
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
<?php if ($_canRemoveAny && $_scopeType === 'kingdom') : ?>
			<button class="rp-btn-ghost" id="rp-suspend-player-btn" style="color:#c53030;border-color:#fed7d7"><i class="fas fa-ban"></i> Suspend Player</button>
<?php endif; ?>
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
<?php if ($variant !== 'suspended') : ?>
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
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-ban"></i></div>
			<div class="rp-stat-number"><?=$suspended_count?></div>
			<div class="rp-stat-label">Suspended</div>
		</div>
	</div>
<?php endif; ?>

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
<?php if (!$is_suspended) : ?>
						<th>Waivered</th>
<?php endif; ?>
<?php if ($is_duespaid) : ?>
						<th>Dues Paid</th>
						<th>Dues Through</th>
<?php endif; ?>
						<th>Last Sign-in</th>
<?php if ($is_suspended) : ?>
						<th>Suspended At</th>
<?php endif; ?>
						<th>Suspended Until</th>
<?php if ($is_suspended) : ?>
						<th>Suspendator</th>
						<th>Suspension</th>
<?php endif; ?>
<?php if ($_canRemoveAny) : ?>
						<th>Actions</th>
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
<?php if (!$is_suspended) : ?>
					<td><?= $player['Waivered'] == 1 ? 'Waiver' : '' ?></td>
<?php endif; ?>
<?php 		if ($is_duespaid) : ?>
					<td><?= $player['DuesPaid']    ? 'Paid'              : '' ?></td>
					<td><?= $player['DuesThrough'] ? htmlspecialchars($player['DuesThrough']) : '' ?></td>
<?php 		endif; ?>
					<td><?=htmlspecialchars($player['LastSignIn'] ?? '')?></td>
<?php 		if ($is_suspended) : ?>
					<td><?=htmlspecialchars($player['SuspendedAt'] ?? '')?></td>
<?php 		endif; ?>
					<td><?php $_until = $player['SuspendedUntil'] ?? ''; echo ($_until && $_until !== '0000-00-00') ? htmlspecialchars($_until) : 'Indefinite'; ?></td>
<?php 		if ($is_suspended) : ?>
					<td><?=htmlspecialchars($player['Suspendator'] ?? '')?></td>
					<td><?=htmlspecialchars($player['Suspension']  ?? '')?></td>
<?php 		endif; ?>
<?php 		if ($_canRemoveAny) : ?>
					<td><?php if (!empty($_canRemoveMap[(int)$player['MundaneId']])) : ?>
						<a class="rp-action-link rp-remove-suspension"
							href="#"
							data-mundane-id="<?=(int)$player['MundaneId']?>"
							data-persona="<?=htmlspecialchars($player['Persona'] ?? 'this player')?>">
							<i class="fas fa-ban" style="margin-right:3px"></i> Remove
						</a>
					<?php endif; ?></td>
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
			{ targets: [0], responsivePriority: 1 },
<?php if ($_canRemoveAny) : ?>
			{ targets: [-1], orderable: false, searchable: false }
<?php endif; ?>
		],
		pageLength: 25,
		order: <?php
			$_colIdx    = 0;
			$sortOrder  = [];
			if (!isset($this->__session->kingdom_id)) {
				$sortOrder[] = [$_colIdx++, 'asc']; // Kingdom
			}
			if (!isset($this->__session->park_id)) {
				$sortOrder[] = [$_colIdx++, 'asc']; // Park
			}
			$sortOrder[] = [$_colIdx, 'asc'];        // Persona
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

<?php if ($_canRemoveAny) : ?>
<!-- Remove suspension overlay -->
<div id="rm-susp-overlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
	<div id="rm-susp-box" style="background:#fff;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.22);max-width:440px;width:90%;padding:28px 28px 22px;">
		<div style="font-size:1.08em;font-weight:600;color:#2d3748;margin-bottom:10px"><i class="fas fa-ban" style="color:#e53e3e;margin-right:7px"></i>Remove Suspension</div>
		<div id="rm-susp-body" style="color:#4a5568;font-size:.97em;margin-bottom:20px">You are about to remove a suspension, this cannot be undone. Are you sure you want to proceed?</div>
		<div style="display:flex;justify-content:flex-end;gap:10px">
			<button id="rm-susp-cancel" style="padding:7px 18px;border:1px solid #cbd5e0;background:#fff;color:#4a5568;border-radius:6px;cursor:pointer;font-size:.95em">Cancel</button>
			<button id="rm-susp-confirm" style="padding:7px 18px;background:#e53e3e;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.95em;font-weight:600">Remove Suspension</button>
		</div>
	</div>
</div>
<script>
(function() {
	var _suspUrl    = '<?= UIR ?>Admin/suspendplayer/submit';
	var _pendingMid = null;

	function showOverlay(mid) {
		_pendingMid = mid;
		var overlay = document.getElementById('rm-susp-overlay');
		overlay.style.display = 'flex';
	}
	function hideOverlay() {
		document.getElementById('rm-susp-overlay').style.display = 'none';
		_pendingMid = null;
	}

	document.getElementById('rm-susp-cancel').addEventListener('click', hideOverlay);
	document.getElementById('rm-susp-overlay').addEventListener('click', function(e) {
		if (e.target === this) hideOverlay();
	});

	document.getElementById('rm-susp-confirm').addEventListener('click', function() {
		if (!_pendingMid) return;
		var btn = this;
		btn.textContent = 'Removing…';
		btn.disabled = true;
		var fd = new FormData();
		fd.append('MundaneId', _pendingMid);
		fd.append('Suspended', '1'); // presence of Suspended → unsuspend
		fetch(_suspUrl, { method: 'POST', body: fd, redirect: 'follow' })
			.catch(function() {})
			.finally(function() { window.location.reload(); });
	});

	$(document).on('click', '.rp-remove-suspension', function(e) {
		e.preventDefault();
		showOverlay($(this).data('mundane-id'));
	});
})();
</script>
<?php endif; ?>

<?php if ($_canRemoveAny && $_scopeType === 'kingdom') : ?>
<!-- Suspend Player overlay -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<div id="sp-overlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;">
	<div id="sp-box" style="background:#fff;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.22);max-width:520px;width:95%;padding:28px 28px 22px;max-height:90vh;overflow-y:auto;">
		<div style="font-size:1.08em;font-weight:600;color:#2d3748;margin-bottom:18px"><i class="fas fa-ban" style="color:#e53e3e;margin-right:7px"></i>Suspend Player</div>

		<div id="sp-error" style="display:none;background:#fff5f5;border:1px solid #fc8181;color:#c53030;border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.93em"></div>

		<div style="margin-bottom:14px">
			<label style="display:block;font-size:.88em;font-weight:600;color:#4a5568;margin-bottom:4px">Park</label>
			<select id="sp-park" style="width:100%;padding:7px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.95em;background:#fff">
				<option value="">Loading parks…</option>
			</select>
		</div>

		<div style="margin-bottom:14px">
			<label style="display:block;font-size:.88em;font-weight:600;color:#4a5568;margin-bottom:4px">Player <span style="color:#e53e3e">*</span></label>
			<div style="position:relative">
				<input type="text" id="sp-player-text" autocomplete="off" placeholder="Search by persona or name…"
					style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.95em">
				<div id="sp-player-results" style="display:none;position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #cbd5e0;border-top:none;border-radius:0 0 6px 6px;z-index:10;max-height:180px;overflow-y:auto"></div>
			</div>
			<input type="hidden" id="sp-player-id">
		</div>

		<div style="display:flex;gap:14px;margin-bottom:14px">
			<div style="flex:1">
				<label style="display:block;font-size:.88em;font-weight:600;color:#4a5568;margin-bottom:4px">Suspended From <span style="color:#e53e3e">*</span></label>
				<input type="text" id="sp-from" autocomplete="off" placeholder="Select date"
					style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.95em">
			</div>
			<div style="flex:1">
				<label style="display:block;font-size:.88em;font-weight:600;color:#4a5568;margin-bottom:4px">Suspended Until</label>
				<input type="text" id="sp-until" autocomplete="off" placeholder="Select date"
					style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.95em">
				<label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:.88em;color:#4a5568;cursor:pointer">
					<input type="checkbox" id="sp-indefinite"> Indefinite
				</label>
			</div>
		</div>

		<div style="margin-bottom:20px">
			<label style="display:block;font-size:.88em;font-weight:600;color:#4a5568;margin-bottom:4px">Comment</label>
			<textarea id="sp-comment" maxlength="100" rows="3" placeholder="Reason for suspension (max 100 characters)"
				style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #cbd5e0;border-radius:6px;font-size:.95em;resize:vertical"></textarea>
			<div id="sp-char-count" style="text-align:right;font-size:.8em;color:#a0aec0;margin-top:2px">0 / 100</div>
		</div>

		<div style="display:flex;justify-content:flex-end;gap:10px">
			<button id="sp-cancel" style="padding:7px 18px;border:1px solid #cbd5e0;background:#fff;color:#4a5568;border-radius:6px;cursor:pointer;font-size:.95em">Cancel</button>
			<button id="sp-submit" style="padding:7px 18px;background:#c53030;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.95em;font-weight:600"><i class="fas fa-ban"></i> Suspend Player</button>
		</div>
	</div>
</div>
<script>
(function() {
	var _kingdomId  = <?= (int)$_scopeId ?>;
	var _suspendatorId = <?= (int)$this->__session->user_id ?>;
	var _suspUrl    = '<?= UIR ?>Admin/suspendplayer/submit';
	var _parksUrl   = '<?= UIR ?>KingdomAjax/kingdom/' + _kingdomId + '/getparks';
	var _searchUrl  = '<?= UIR ?>KingdomAjax/playersearch/' + _kingdomId;
	var _parksLoaded = false;
	var _fpFrom, _fpUntil;

	// Flatpickr init
	_fpFrom  = flatpickr('#sp-from',  { dateFormat: 'Y-m-d', defaultDate: 'today' });
	_fpUntil = flatpickr('#sp-until', {
		dateFormat: 'Y-m-d',
		onChange: function() { updateSubmitBtn(); }
	});

	function updateSubmitBtn() {
		var indefinite = document.getElementById('sp-indefinite').checked;
		var until      = document.getElementById('sp-until').value;
		var player     = document.getElementById('sp-player-id').value;
		var btn        = document.getElementById('sp-submit');
		var disabled   = !player || (!indefinite && !until);
		btn.disabled       = disabled;
		btn.style.opacity  = disabled ? '0.5' : '1';
		btn.style.cursor   = disabled ? 'not-allowed' : 'pointer';
	}

	// Indefinite toggle
	document.getElementById('sp-indefinite').addEventListener('change', function() {
		var untilInput = document.getElementById('sp-until');
		if (this.checked) {
			_fpUntil.clear();
			untilInput.disabled = true;
			untilInput.style.opacity = '0.4';
		} else {
			untilInput.disabled = false;
			untilInput.style.opacity = '1';
		}
		updateSubmitBtn();
	});

	// Char counter
	document.getElementById('sp-comment').addEventListener('input', function() {
		document.getElementById('sp-char-count').textContent = this.value.length + ' / 100';
	});

	// Load parks
	function loadParks() {
		if (_parksLoaded) return;
		fetch(_parksUrl)
			.then(function(r) { return r.json(); })
			.then(function(d) {
				var sel = document.getElementById('sp-park');
				sel.innerHTML = '<option value="">— Select park —</option>';
				(d.parks || []).forEach(function(p) {
					var opt = document.createElement('option');
					opt.value = p.ParkId;
					opt.textContent = p.Name;
					sel.appendChild(opt);
				});
				_parksLoaded = true;
			});
	}

	// Clear player when park changes
	document.getElementById('sp-park').addEventListener('change', function() {
		document.getElementById('sp-player-text').value = '';
		document.getElementById('sp-player-id').value   = '';
		document.getElementById('sp-player-results').style.display = 'none';
	});

	// Player search autocomplete
	var _searchTimer = null;
	document.getElementById('sp-player-text').addEventListener('input', function() {
		var q = this.value.trim();
		var results = document.getElementById('sp-player-results');
		document.getElementById('sp-player-id').value = '';
		updateSubmitBtn();
		clearTimeout(_searchTimer);
		if (q.length < 2) { results.style.display = 'none'; return; }
		_searchTimer = setTimeout(function() {
			var parkId = document.getElementById('sp-park').value;
			var parkParam = parkId ? '&park_id=' + encodeURIComponent(parkId) : '';
			fetch(_searchUrl + '&q=' + encodeURIComponent(q) + parkParam)
				.then(function(r) { return r.json(); })
				.then(function(data) {
					results.innerHTML = '';
					if (!data.length) { results.style.display = 'none'; return; }
					data.forEach(function(p) {
						var div = document.createElement('div');
						div.style.cssText = 'padding:7px 10px;cursor:pointer;font-size:.92em;border-bottom:1px solid #f0f0f0';
						div.textContent = p.Persona + (p.ParkName ? ' — ' + p.ParkName : '');
						div.addEventListener('mousedown', function(e) { e.preventDefault(); });
						div.addEventListener('click', function() {
							document.getElementById('sp-player-text').value = p.Persona;
							document.getElementById('sp-player-id').value   = p.MundaneId;
							results.style.display = 'none';
							updateSubmitBtn();
						});
						results.appendChild(div);
					});
					results.style.display = 'block';
				});
		}, 200);
	});
	document.getElementById('sp-player-text').addEventListener('blur', function() {
		setTimeout(function() { document.getElementById('sp-player-results').style.display = 'none'; }, 150);
	});

	// Open / close
	function openOverlay() {
		loadParks();
		document.getElementById('sp-error').style.display = 'none';
		document.getElementById('sp-player-text').value = '';
		document.getElementById('sp-player-id').value   = '';
		document.getElementById('sp-comment').value     = '';
		document.getElementById('sp-char-count').textContent = '0 / 100';
		document.getElementById('sp-indefinite').checked = false;
		var untilInput = document.getElementById('sp-until');
		untilInput.disabled = false;
		untilInput.style.opacity = '1';
		_fpFrom.setDate('today', true);
		_fpUntil.clear();
		updateSubmitBtn();
		var overlay = document.getElementById('sp-overlay');
		overlay.style.display = 'flex';
	}
	function closeOverlay() {
		document.getElementById('sp-overlay').style.display = 'none';
		var btn = document.getElementById('sp-submit');
		btn.textContent = 'Suspend Player';
		btn.innerHTML   = '<i class="fas fa-ban"></i> Suspend Player';
		btn.disabled    = false;
	}

	document.getElementById('sp-cancel').addEventListener('click', closeOverlay);
	document.getElementById('sp-overlay').addEventListener('click', function(e) {
		if (e.target === this) closeOverlay();
	});
	var openBtn = document.getElementById('rp-suspend-player-btn');
	if (openBtn) openBtn.addEventListener('click', openOverlay);

	// Submit
	document.getElementById('sp-submit').addEventListener('click', function() {
		var btn = this;
		var mundaneId = document.getElementById('sp-player-id').value;
		var from      = document.getElementById('sp-from').value;
		var indefinite = document.getElementById('sp-indefinite').checked;
		var until     = document.getElementById('sp-until').value;
		var comment   = document.getElementById('sp-comment').value.trim();

		document.getElementById('sp-error').style.display = 'none';

		if (!mundaneId) {
			var err = document.getElementById('sp-error');
			err.textContent = 'Please select a player.';
			err.style.display = 'block';
			return;
		}
		if (!from) {
			var err = document.getElementById('sp-error');
			err.textContent = 'Please select a Suspended From date.';
			err.style.display = 'block';
			return;
		}

		btn.innerHTML = 'Suspending…';
		btn.disabled  = true;

		var fd = new FormData();
		fd.append('MundaneId',    mundaneId);
		fd.append('SuspendatorId', _suspendatorId);
		fd.append('SuspendedAt',  from);
		if (!indefinite) fd.append('SuspendedUntil', until);
		fd.append('Suspension',   comment);
		// No 'Suspended' field → controller sets suspended = true

		fetch(_suspUrl, { method: 'POST', body: fd, redirect: 'follow' })
			.then(function() { window.location.reload(); })
			.catch(function() {
				var err = document.getElementById('sp-error');
				err.textContent = 'An error occurred. Please try again.';
				err.style.display = 'block';
				btn.innerHTML = '<i class="fas fa-ban"></i> Suspend Player';
				btn.disabled  = false;
			});
	});
})();
</script>
<?php endif; ?>
