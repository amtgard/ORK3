<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$stats   = is_array($_wv_stats)   ? $_wv_stats   : array();
$players = is_array($_wv_players) ? $_wv_players : array();
$monthly = is_array($_wv_monthly) ? $_wv_monthly : array();

$st_total          = (int)($stats['total']                ?? 0);
$st_pending        = (int)($stats['pending_active']        ?? 0);
$st_stale          = (int)($stats['stale']                 ?? 0);
$st_verified       = (int)($stats['verified']              ?? 0);
$st_rejected       = (int)($stats['rejected']              ?? 0);
$st_unsigned       = (int)($stats['unsigned']              ?? 0);
$st_minor          = (int)($stats['minor_guardian_count']  ?? 0);
$st_avg_days       = (float)($stats['avg_days_pending']    ?? 0);
$st_compliance     = (int)($stats['compliance_pct']        ?? 0);

/* Compliance colour band → CSS class (dark-mode aware via .wv-compliance-*) */
$compliance_class = 'wv-compliance-red';                       // red < 50
if      ($st_compliance >= 90) $compliance_class = 'wv-compliance-green'; // green
elseif  ($st_compliance >= 50) $compliance_class = 'wv-compliance-amber'; // amber

$can_mundane = !empty($CanViewMundane);

/* Scope chip */
$scope_label = isset($EntityName) ? $EntityName : '';
$scope_link  = isset($EntityUrl)  ? $EntityUrl  : (UIR . 'Reports');
$scope_icon  = ($Type === 'Park') ? 'fa-tree' : 'fa-chess-rook';
$scope_noun  = ($Type === 'Park') ? 'park'    : 'kingdom';

/* Chart series (oldest → newest) — only render when >= 2 months */
$chart_months = array();
$chart_counts = array();
foreach ($monthly as $m) {
	// Human-readable label e.g. "May 2026"
	$ts = strtotime(($m['month'] ?? '') . '-01');
	$chart_months[] = $ts ? date('M Y', $ts) : ($m['month'] ?? '');
	$chart_counts[] = (int)($m['count'] ?? 0);
}
$show_chart = count($chart_counts) >= 2;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">

<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<style>
.dt-buttons { display:none; }

/* Heading resets (orkui.css gives all h1-h6 a gray pill) */
.rp-root h1, .rp-root h2, .rp-root h3, .rp-root h4, .rp-root h5, .rp-root h6 {
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}

/* ── Status badge pills ──────────────────────────────────── */
.wv-badge {
	display: inline-block; padding: 2px 9px; border-radius: 999px;
	font-size: 0.78rem; font-weight: 600; line-height: 1.5; white-space: nowrap;
}
.wv-badge-pending  { background:#fef3c7; color:#92400e; }  /* amber  */
.wv-badge-verified { background:#d1fae5; color:#065f46; }  /* green  */
.wv-badge-rejected { background:#fee2e2; color:#991b1b; }  /* red    */
.wv-badge-stale    { background:#e5e7eb; color:#374151; }  /* gray   */
.wv-badge-unsigned { background:#fef9c3; color:#854d0e; }  /* yellow */

.wv-tpl-badge {
	display:inline-block; padding:1px 7px; border-radius:4px;
	background:#eef2ff; color:#3730a3; font-size:0.75rem; font-weight:600;
}

/* ── Compliance stat-number colour band ──────────────────── */
.wv-compliance-green { color:#059669; }
.wv-compliance-amber { color:#d97706; }
.wv-compliance-red   { color:#dc2626; }

/* Row highlight for unsigned members */
tr.rp-row-unsigned td { background:#fffbeb; }

/* ── Dark mode overrides ─────────────────────────────────── */
html[data-theme="dark"] .wv-badge-pending  { background:#3f2d0b; color:#fcd34d; }
html[data-theme="dark"] .wv-badge-verified { background:#0b3a2c; color:#6ee7b7; }
html[data-theme="dark"] .wv-badge-rejected { background:#3f1414; color:#fca5a5; }
html[data-theme="dark"] .wv-badge-stale    { background:#2a2f3a; color:#cbd5e1; }
html[data-theme="dark"] .wv-badge-unsigned { background:#3a3209; color:#fde68a; }
html[data-theme="dark"] .wv-tpl-badge      { background:#1e1b4b; color:#c7d2fe; }
html[data-theme="dark"] tr.rp-row-unsigned td { background:#2b2509; }
html[data-theme="dark"] .wv-compliance-green { color:#34d399; }
html[data-theme="dark"] .wv-compliance-amber { color:#fbbf24; }
html[data-theme="dark"] .wv-compliance-red   { color:#f87171; }
</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-file-signature rp-header-icon"></i>
				<h1 class="rp-header-title">Digital Waivers</h1>
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
		<span>Digital waiver compliance for <?=$scope_label ? htmlspecialchars($scope_label) : 'this ' . $scope_noun ?> — who has a signed waiver on file, who is awaiting officer verification, and who has not yet signed. Last updated <?=date('F j, Y g:i A')?>.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-file-signature"></i></div>
			<div class="rp-stat-number"><?=$st_total?></div>
			<div class="rp-stat-label">Total Signatures</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-hourglass-half"></i></div>
			<div class="rp-stat-number"><?=$st_pending?></div>
			<div class="rp-stat-label">Pending Review</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-check-circle"></i></div>
			<div class="rp-stat-number"><?=$st_verified?></div>
			<div class="rp-stat-label">Verified</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-clock-rotate-left"></i></div>
			<div class="rp-stat-number"><?=$st_stale?></div>
			<div class="rp-stat-label">Stale (Re-sign)</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percent"></i></div>
			<div class="rp-stat-number <?=$compliance_class?>"><?=$st_compliance?>%</div>
			<div class="rp-stat-label">Compliance</div>
		</div>
	</div>

<?php if ($show_chart) : ?>
	<!-- ── Trend chart ────────────────────────────────────── -->
	<div class="rp-charts-row rp-charts-visible" id="rp-charts-row">
		<div id="waivers-chart" style="width:100%;height:300px;"></div>
	</div>
<?php endif; ?>

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
						<button class="rp-filter-pill active" data-filter="pending">Pending</button>
						<button class="rp-filter-pill active" data-filter="verified">Verified</button>
						<button class="rp-filter-pill active" data-filter="rejected">Rejected</button>
						<button class="rp-filter-pill active" data-filter="stale">Stale</button>
						<button class="rp-filter-pill active" data-filter="unsigned">Unsigned</button>
					</div>
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
<?php if ($can_mundane) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Mundane</span>
						<span class="rp-col-guide-desc">Player's real name (authorized users only).</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The player's home park.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Status</span>
						<span class="rp-col-guide-desc"><b>Verified</b> = officer-confirmed. <b>Pending</b> = signed, awaiting review. <b>Stale</b> = signed against an old template version. <b>Unsigned</b> = no waiver on file (yellow row).</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Signed / Verified</span>
						<span class="rp-col-guide-desc">Dates the waiver was signed and officer-verified.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Template</span>
						<span class="rp-col-guide-desc">The waiver version and scope the signature was made against.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Days Waiting</span>
						<span class="rp-col-guide-desc">Days a pending signature has been awaiting officer review.</span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<table id="waivers-report-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Persona</th>
<?php if ($can_mundane) : ?>
						<th>Mundane</th>
<?php endif; ?>
						<th>Park</th>
						<th>Status</th>
						<th>Signed</th>
						<th>Verified</th>
						<th>Template</th>
						<th>Days Waiting</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($players as $p) :
	$status = $p['Status'] ?? null; // null = unsigned
	$is_stale = ($status === 'pending' && isset($p['TemplateActive']) && (int)$p['TemplateActive'] === 0);

	// Row class for filter pills
	$row_class = '';
	if      ($status === null)      $row_class = 'rp-row-unsigned';
	elseif  ($is_stale)             $row_class = 'rp-row-stale';
	elseif  ($status === 'pending') $row_class = 'rp-row-pending';
	elseif  ($status === 'rejected')$row_class = 'rp-row-rejected';
	elseif  ($status === 'verified')$row_class = 'rp-row-verified';
	else                            $row_class = 'rp-row-' . preg_replace('/[^a-z]/', '', (string)$status);

	// Status badge
	if ($status === null) {
		$badge = '<span class="wv-badge wv-badge-unsigned">Unsigned</span>';
		$sort_status = 'Unsigned';
	} elseif ($is_stale) {
		$badge = '<span class="wv-badge wv-badge-stale">Stale</span>';
		$sort_status = 'Stale';
	} elseif ($status === 'pending') {
		$badge = '<span class="wv-badge wv-badge-pending">Pending</span>';
		$sort_status = 'Pending';
	} elseif ($status === 'verified') {
		$badge = '<span class="wv-badge wv-badge-verified">Verified</span>';
		$sort_status = 'Verified';
	} elseif ($status === 'rejected') {
		$badge = '<span class="wv-badge wv-badge-rejected">Rejected</span>';
		$sort_status = 'Rejected';
	} else {
		$badge = '<span class="wv-badge wv-badge-stale">' . htmlspecialchars(ucfirst((string)$status)) . '</span>';
		$sort_status = ucfirst((string)$status);
	}

	$signed_at   = !empty($p['SignedAt'])   ? date('F j, Y', strtotime($p['SignedAt']))   : '';
	$verified_at = !empty($p['VerifiedAt']) ? date('F j, Y', strtotime($p['VerifiedAt'])) : '';
	$signed_sort = !empty($p['SignedAt'])   ? date('Y-m-d', strtotime($p['SignedAt']))    : '';
	$verified_sort = !empty($p['VerifiedAt']) ? date('Y-m-d', strtotime($p['VerifiedAt'])) : '';

	$tpl = '';
	if (!empty($p['TemplateVersion'])) {
		$tpl = '<span class="wv-tpl-badge">v' . (int)$p['TemplateVersion'] . ' ' . htmlspecialchars((string)($p['TemplateScope'] ?? '')) . '</span>';
	}

	$days = ($status === 'pending' && $p['DaysWaiting'] !== null) ? (int)$p['DaysWaiting'] : null;
?>
				<tr class="<?=$row_class?>">
					<td><a href="<?=UIR.'Playernew/index/'.(int)$p['MundaneId']?>"><?=htmlspecialchars($p['Persona'] ?? '')?></a></td>
<?php if ($can_mundane) : ?>
					<td><?=htmlspecialchars(trim(($p['GivenName'] ?? '') . ' ' . ($p['Surname'] ?? '')))?></td>
<?php endif; ?>
					<td><a href="<?=UIR.'Park/profile/'.(int)$p['ParkId']?>"><?=htmlspecialchars($p['ParkName'] ?? '')?></a></td>
					<td data-order="<?=htmlspecialchars($sort_status)?>"><?=$badge?></td>
					<td data-order="<?=htmlspecialchars($signed_sort)?>"><?=$signed_at !== '' ? htmlspecialchars($signed_at) : '&mdash;'?></td>
					<td data-order="<?=htmlspecialchars($verified_sort)?>"><?=$verified_at !== '' ? htmlspecialchars($verified_at) : '&mdash;'?></td>
					<td><?=$tpl !== '' ? $tpl : '&mdash;'?></td>
					<td data-order="<?=$days !== null ? $days : -1?>"><?=$days !== null ? $days : ''?></td>
				</tr>
<?php endforeach; ?>
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

<script>
$(function() {
	/* ── Row filter state ────────────────────────────────── */
	var filterState = { pending: true, verified: true, rejected: true, stale: true, unsigned: true };

	var table = $('#waivers-report-table').DataTable({
		dom: 'Blfrtip',
		buttons: [
			{ extend: 'csv',   filename: 'Digital Waivers', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		pageLength: 25,
		order: [[<?=$can_mundane ? 4 : 3?>, 'desc']],   /* Signed date desc */
		fixedHeader: { headerOffset: document.getElementById('newmenu') ? document.getElementById('newmenu').offsetHeight : 0 },
		autoWidth: true
	});

	$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
		if (settings.nTable.id !== 'waivers-report-table') return true;
		var $row = $(table.row(dataIndex).node());
		if (!filterState.pending  && $row.hasClass('rp-row-pending'))  return false;
		if (!filterState.verified && $row.hasClass('rp-row-verified')) return false;
		if (!filterState.rejected && $row.hasClass('rp-row-rejected')) return false;
		if (!filterState.stale    && $row.hasClass('rp-row-stale'))    return false;
		if (!filterState.unsigned && $row.hasClass('rp-row-unsigned')) return false;
		return true;
	});

	$('.rp-filter-pill').on('click', function() {
		var key = $(this).data('filter');
		filterState[key] = !filterState[key];
		$(this).toggleClass('active', filterState[key]);
		table.draw();
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });

<?php if ($show_chart) : ?>
	/* ── Monthly trend chart ─────────────────────────────── */
	if (typeof Highcharts !== 'undefined' && document.getElementById('waivers-chart')) {
		var wvMonths = <?=json_encode($chart_months)?>;
		var wvCounts = <?=json_encode($chart_counts)?>;
		new Highcharts.Chart({
			chart: { renderTo: 'waivers-chart', type: 'column' },
			credits: { enabled: false },
			title: { text: 'New Signatures by Month' },
			xAxis: { categories: wvMonths, labels: { style: { fontSize: '11px' } } },
			yAxis: { min: 0, allowDecimals: false, title: { text: 'Signatures' } },
			legend: { enabled: false },
			series: [{ name: 'Signatures', data: wvCounts, color: '#4338ca' }]
		});
	}
<?php endif; ?>
});
</script>
