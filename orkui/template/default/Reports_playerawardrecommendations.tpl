<?php
/* ── Auth check ──────────────────────────────────────────── */
$can_delete = false;
if ($this->__session->user_id) {
	if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_ADMIN, 0, AUTH_ADMIN)) {
		$can_delete = true;
	} else if (isset($this->__session->park_id)) {
		if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $this->__session->park_id, AUTH_EDIT)) {
			$can_delete = true;
		}
	} else if (isset($this->__session->kingdom_id)) {
		if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_KINGDOM, $this->__session->kingdom_id, AUTH_EDIT)) {
			$can_delete = true;
		}
	}
}

/* ── Pre-compute stats & scope ────────────────────────────── */
$total               = 0;
$unique_nominees     = [];
$unique_recommenders = [];

if (is_array($AwardRecommendations)) {
	foreach ($AwardRecommendations as $rec) {
		$total++;
		if (!empty($rec['MundaneId']))       $unique_nominees[$rec['MundaneId']] = true;
		if (!empty($rec['RecommendedById'])) $unique_recommenders[$rec['RecommendedById']] = true;
	}
}

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';

$_recScopeType = $ScopeType ?? (isset($this->__session->park_id) ? 'park' : (isset($this->__session->kingdom_id) ? 'kingdom' : ''));
$_first = !empty($AwardRecommendations) ? reset($AwardRecommendations) : [];

if ($_recScopeType === 'park') {
	$scope_label = $_first['ParkName']    ?? '';
	$scope_link  = UIR . 'Park/profile/'    . (int)($this->__session->park_id ?? 0);
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif ($_recScopeType === 'kingdom') {
	$scope_label = $_first['KingdomName'] ?? '';
	$scope_link  = UIR . 'Kingdom/profile/' . (int)($this->__session->kingdom_id ?? 0);
	$scope_icon  = 'fa-chess-rook';
	$scope_noun  = 'kingdom';
}

$_showKingdom = ($_recScopeType === '');
$_showPark    = ($_recScopeType === '' || $_recScopeType === 'kingdom');
/* date column index shifts with extra columns */
$_dateColIdx  = $_showKingdom ? 5 : ($_showPark ? 4 : 3);
$_actionsLast = $this->__session->user_id ? true : false;

/* Pre-sort so initial render matches the header sort indicators */
if (is_array($AwardRecommendations) && count($AwardRecommendations) > 1) {
	usort($AwardRecommendations, function($a, $b) use ($_showKingdom, $_showPark) {
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
				<i class="fas fa-star rp-header-icon"></i>
				<h1 class="rp-header-title">Award Recommendations</h1>
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
		<span>Pending award recommendations submitted for players within <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?>. Authorized officers and the original recommender may delete recommendations.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-star"></i></div>
			<div class="rp-stat-number"><?=$total?></div>
			<div class="rp-stat-label">Pending Recommendations</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user"></i></div>
			<div class="rp-stat-number"><?=count($unique_nominees)?></div>
			<div class="rp-stat-label">Unique Nominees</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=count($unique_recommenders)?></div>
			<div class="rp-stat-label">Unique Recommenders</div>
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
<?php if ($_showKingdom) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">The kingdom the nominee belongs to.</span>
					</div>
<?php endif; ?>
<?php if ($_showPark) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The park the nominee belongs to.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">The player being recommended for the award.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Award</span>
						<span class="rp-col-guide-desc">The award being recommended.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Rank</span>
						<span class="rp-col-guide-desc">Ladder rank being recommended, if applicable.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">Date the recommendation was submitted.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Sent By</span>
						<span class="rp-col-guide-desc">The player who submitted the recommendation.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Reason</span>
						<span class="rp-col-guide-desc">The recommender's justification for the award.</span>
					</div>
<?php if ($this->__session->user_id) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Actions</span>
						<span class="rp-col-guide-desc">Delete a recommendation (officers and the original recommender only).</span>
					</div>
<?php endif; ?>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<div id="rec-loading" style="text-align:center;padding:48px 32px;color:#a0aec0">
				<i class="fas fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px"></i>
				Loading report&hellip;
			</div>
			<div id="rec-table-wrap" style="opacity:0">
			<table id="rec-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if ($_showKingdom) : ?>
						<th>Kingdom</th>
<?php endif; ?>
<?php if ($_showPark) : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
						<th>Award</th>
						<th>Rank</th>
						<th>Date</th>
						<th>Sent By</th>
						<th>Reason</th>
<?php if ($this->__session->user_id) : ?>
						<th>Actions</th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php if (is_array($AwardRecommendations)) : ?>
<?php 	foreach ($AwardRecommendations as $rec) : ?>
				<tr>
<?php if ($_showKingdom) : ?>
					<td><a href="<?=UIR.'Kingdom/profile/'.(int)$rec['KingdomId']?>"><?=htmlspecialchars($rec['KingdomName'])?></a></td>
<?php endif; ?>
<?php if ($_showPark) : ?>
					<td><a href="<?=UIR.'Park/profile/'.(int)$rec['ParkId']?>"><?=htmlspecialchars($rec['ParkName'])?></a></td>
<?php endif; ?>
					<td><a href='<?=UIR.'Player/profile/'.$rec['MundaneId']?>'><?=htmlspecialchars($rec['Persona'])?></a></td>
					<td><?=htmlspecialchars($rec['AwardName'])?></td>
					<td><?=valid_id($rec['Rank'])?htmlspecialchars($rec['Rank']):''?></td>
					<td><?=htmlspecialchars($rec['DateRecommended'])?></td>
					<td><a href="<?=UIR.'Player/profile/'.$rec['RecommendedById']?>"><?=htmlspecialchars($rec['RecommendedByName'])?></a></td>
					<td><?=htmlspecialchars($rec['Reason'])?></td>
<?php 		if ($this->__session->user_id) : ?>
					<td>
<?php 			if ($can_delete || $this->__session->user_id == $rec['RecommendedById'] || $this->__session->user_id == $rec['MundaneId']) : ?>
						<a class="rp-action-link confirm-delete-rec" href="<?=UIR.'Player/profile/'.$rec['MundaneId'].'/deleterecommendation/'.$rec['RecommendationsId']?>"><i class="fas fa-trash-alt"></i> Delete</a>
<?php 			endif; ?>
					</td>
<?php 		endif; ?>
				</tr>
<?php 	endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
			</div><!-- /rec-table-wrap -->
		</div><!-- /rp-table-area -->

	</div><!-- /rp-body -->

</div><!-- /rp-root -->

<?php if ($this->__session->user_id) : ?>
<style>
#del-rec-overlay {
	display: none; position: fixed; inset: 0; z-index: 9999;
	background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
}
#del-rec-box {
	background: #fff; border-radius: 12px; max-width: 400px; width: 90%;
	box-shadow: 0 8px 32px rgba(0,0,0,0.22); overflow: hidden;
}
#del-rec-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
}
#del-rec-header h4 {
	margin: 0; font-size: 16px; font-weight: 600; color: #1a202c;
	background: none; border: none; padding: 0;
}
#del-rec-body { padding: 18px 20px 8px; }
#del-rec-body p { margin: 0; font-size: 14px; color: #4a5568; text-align: left; line-height: 1.5; }
.del-rec-actions { display: flex; gap: 10px; justify-content: flex-end; padding: 12px 20px 18px; }
.del-rec-actions button {
	padding: 8px 18px; border-radius: 6px; border: 1px solid #cbd5e0;
	font-size: 14px; cursor: pointer; background: #edf2f7; color: #4a5568; font-weight: 500;
}
.del-rec-actions button:hover { background: #e2e8f0; }
.del-rec-actions button.del-rec-ok {
	background: #c53030; color: #fff; border-color: #c53030;
}
.del-rec-actions button.del-rec-ok:hover { background: #9b2c2c; border-color: #9b2c2c; }
</style>
<div id="del-rec-overlay">
	<div id="del-rec-box">
		<div id="del-rec-header">
			<h4>Delete Recommendation?</h4>
		</div>
		<div id="del-rec-body">
			<p>Are you sure you want to delete this award recommendation? This cannot be undone.</p>
		</div>
		<div class="del-rec-actions">
			<button id="del-rec-cancel">Cancel</button>
			<button class="del-rec-ok" id="del-rec-ok">Delete</button>
		</div>
	</div>
</div>
<?php endif; ?>

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
	var table = $('#rec-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
<?php if ($this->__session->user_id) : ?>
			{ extend: 'csv',   filename: 'Award Recommendations', exportOptions: { columns: ':not(:last-child)' } },
			{ extend: 'print', exportOptions: { columns: ':not(:last-child)' } }
<?php else : ?>
			{ extend: 'csv',   filename: 'Award Recommendations', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
<?php endif; ?>
		],
		columnDefs: [
<?php if ($this->__session->user_id) : ?>
			{ targets: [-1], orderable: false, searchable: false, className: 'dt-center' },
<?php endif; ?>
			{ targets: [<?=$_dateColIdx?>], type: 'date', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: <?php
			if ($_showKingdom)     echo '[[0,"asc"],[1,"asc"],[2,"asc"]]'; // Kingdom, Park, Persona
			elseif ($_showPark)   echo '[[0,"asc"],[1,"asc"]]';           // Park, Persona
			else                  echo '[[0,"asc"]]';                     // Persona
		?>,
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('#rec-loading').hide();
	$('#rec-table-wrap').css('opacity', '1');

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });

<?php if ($this->__session->user_id) : ?>
	var _delRecUrl = null;
	document.getElementById('del-rec-cancel').addEventListener('click', function() {
		document.getElementById('del-rec-overlay').style.display = 'none';
		_delRecUrl = null;
	});
	document.getElementById('del-rec-ok').addEventListener('click', function() {
		if (!_delRecUrl) return;
		var btn = this;
		btn.disabled = true;
		btn.textContent = 'Deleting…';
		fetch(_delRecUrl, { redirect: 'follow' })
			.catch(function() {})
			.finally(function() { window.location.reload(); });
	});
	$('.confirm-delete-rec').on('click', function(e) {
		e.preventDefault();
		_delRecUrl = $(this).attr('href');
		document.getElementById('del-rec-overlay').style.display = 'flex';
	});
<?php endif; ?>
});
</script>
