<?php
$_pdm_parks  = is_array($parks  ?? null) ? $parks  : [];
$_pdm_matrix = is_array($matrix ?? null) ? $matrix : [];
$_pdm_count  = count($_pdm_parks);
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
.rp-table-area { width: 100%; box-sizing: border-box; }
.pdm-wrap { overflow-x: auto; margin-top: 4px; }
.pdm-table { width: 100%; }
.pdm-table { border-collapse: collapse; white-space: nowrap; font-size: 0.8em; }
.pdm-table th, .pdm-table td { border: 1px solid #e2e8f0; padding: 4px 6px; text-align: center; }
.pdm-table td.pdm-rowhead { text-align: left; font-weight: 600; white-space: nowrap; background: #f7fafc; }
.pdm-table td.pdm-rowhead a { color: var(--rp-accent); text-decoration: none; }
.pdm-table td.pdm-rowhead a:hover { text-decoration: underline; }
.pdm-table td.pdm-location { text-align: left; white-space: nowrap; background: #f7fafc; color: #718096; font-size: 0.9em; }
.pdm-table th.pdm-colhead { vertical-align: bottom; padding: 4px 2px; background: #f7fafc; }
.pdm-table th.pdm-colhead > div {
	writing-mode: vertical-rl; transform: rotate(180deg);
	white-space: nowrap; max-height: 120px; overflow: hidden;
	text-overflow: ellipsis; font-weight: 600; cursor: default;
}
.pdm-table td.pdm-self { background: #edf2f7; color: #a0aec0; }
.pdm-table th.pdm-corner { background: #f7fafc; }
html[data-theme="dark"] .pdm-table th, html[data-theme="dark"] .pdm-table td { border-color: #4a5568; }
html[data-theme="dark"] .pdm-table td.pdm-rowhead { background: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .pdm-table td.pdm-rowhead a { color: #90cdf4; }
html[data-theme="dark"] .pdm-table td.pdm-location { background: #2d3748; color: #718096; }
html[data-theme="dark"] .pdm-table th.pdm-colhead { background: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .pdm-table th.pdm-corner { background: #2d3748; }
html[data-theme="dark"] .pdm-table td.pdm-self { background: #374151; color: #718096; }
</style>

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-th rp-header-icon"></i>
				<h1 class="rp-header-title">Park Distance Matrix</h1>
			</div>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Straight-line distance in miles between every pair of active parks with GPS coordinates on file. Color scale is relative to this kingdom's own min/max — <span style="background:hsl(120,70%,65%);color:rgba(0,0,0,0.8);padding:0 4px;border-radius:3px;">green</span> = closest, <span style="background:hsl(55,95%,60%);color:rgba(0,0,0,0.8);padding:0 4px;border-radius:3px;">yellow</span> = mid, <span style="background:hsl(25,90%,50%);color:#fff;padding:0 4px;border-radius:3px;">orange</span> = farthest. Does not account for roads or terrain.</span>
	</div>

<?php if (!empty($error)): ?>
	<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
		<i class="fas fa-exclamation-circle" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.4;"></i>
		<?=htmlspecialchars($error)?>
	</div>
<?php elseif ($_pdm_count === 0): ?>
	<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
		<i class="fas fa-map" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.3;"></i>
		No parks with geocoordinates found for this kingdom.
	</div>
<?php else:
	// --- Geographic outlier detection -------------------------------------
	// A park whose nearest neighbor is dramatically farther than the typical
	// nearest-neighbor distance (e.g. an island or out-of-region park) blows
	// out the global max and crushes every in-region pair into the "closest"
	// end of the color scale. Detect such parks and (a) exclude their pairs
	// from the min/max that drives the scale, (b) paint their row/column the
	// "farthest" color so they read as off-the-charts distant.
	$_pdm_nn = []; // park_id => distance to its nearest other park (miles)
	foreach ($_pdm_matrix as $rid => $cells) {
		if ($cells) $_pdm_nn[$rid] = min($cells);
	}
	$_nn_sorted = array_values($_pdm_nn);
	sort($_nn_sorted);
	$_median_nn = 0.0;
	if ($_nn_sorted) {
		$_m = intdiv(count($_nn_sorted), 2);
		$_median_nn = (count($_nn_sorted) % 2)
			? $_nn_sorted[$_m]
			: ($_nn_sorted[$_m - 1] + $_nn_sorted[$_m]) / 2;
	}
	// Must be both a strong relative outlier (>5x median) and absolutely far
	// (>150 mi), so tight or sparse-but-legitimate kingdoms are never flagged.
	$_outlier_cut  = max($_median_nn * 5, 150);
	$_pdm_outliers = []; // park_id => true
	foreach ($_pdm_nn as $pid => $d) {
		if ($d > $_outlier_cut) $_pdm_outliers[$pid] = true;
	}

	// Color scale spans only in-region pairs (neither endpoint an outlier).
	$scale_miles = [];
	foreach ($_pdm_matrix as $rid => $cells) {
		if (isset($_pdm_outliers[$rid])) continue;
		foreach ($cells as $cid => $miles) {
			if (isset($_pdm_outliers[$cid])) continue;
			$scale_miles[] = $miles;
		}
	}
	$min_miles = $scale_miles ? min($scale_miles) : 0;
	$max_miles = $scale_miles ? max($scale_miles) : 1;
	$range     = max($max_miles - $min_miles, 1);

	// True overall extremes (every pair) for the summary cards.
	$all_miles = [];
	foreach ($_pdm_matrix as $row) foreach ($row as $miles) $all_miles[] = $miles;
	$true_min = $all_miles ? min($all_miles) : 0;
	$true_max = $all_miles ? max($all_miles) : 1;

	// Outlier cells reuse the scale's "farthest" color so they blend in with
	// the in-region maximum rather than calling attention to themselves.
	$_pdm_outlier_bg = 'hsl(25,90%,50%)';
?>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
			<div class="rp-stat-number"><?=$_pdm_count?></div>
			<div class="rp-stat-label">Parks with Coordinates</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-compress-arrows-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($true_min, 1)?> mi</div>
			<div class="rp-stat-label">Shortest Distance</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-expand-arrows-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($true_max, 1)?> mi</div>
			<div class="rp-stat-label">Longest Distance</div>
		</div>
	</div>

	<div class="rp-table-area">
		<div class="pdm-wrap">
			<table class="pdm-table">
				<thead>
					<tr>
						<th class="pdm-corner"></th>
						<th class="pdm-corner"></th>
<?php foreach ($_pdm_parks as $col_id => $col): ?>
						<th class="pdm-colhead" title="<?=htmlspecialchars($col['Name'])?>"><div><?=htmlspecialchars($col['Name'])?></div></th>
<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_pdm_parks as $row_id => $row): ?>
				<tr>
					<td class="pdm-rowhead"><a href="<?=UIR?>Park/profile/<?=$row_id?>"><?=htmlspecialchars($row['Name'])?></a></td>
					<td class="pdm-location"><?=htmlspecialchars(trim($row['City'] . ', ' . $row['Province'], ', '))?></td>
<?php 	foreach ($_pdm_parks as $col_id => $col):
			if ($row_id === $col_id): ?>
					<td class="pdm-self" title="<?=htmlspecialchars($row['Name'])?>">—</td>
<?php 		elseif (isset($_pdm_matrix[$row_id][$col_id])):
				$miles = $_pdm_matrix[$row_id][$col_id];
				if (isset($_pdm_outliers[$row_id]) || isset($_pdm_outliers[$col_id])):
?>
					<td style="background:<?=$_pdm_outlier_bg?>;color:rgba(0,0,0,0.8)" title="<?=htmlspecialchars($row['Name'])?> → <?=htmlspecialchars($col['Name'])?>"><?=$miles?></td>
<?php 			else:
					$t = ($miles - $min_miles) / $range;
					$t = $t < 0 ? 0 : ($t > 1 ? 1 : $t);
					if ($t < 0.5) {
						$tt = $t * 2;
						$hue = round(120 - $tt * 65); $sat = round(70 + $tt * 25); $light = round(65 - $tt * 5);
					} else {
						$tt = ($t - 0.5) * 2;
						$hue = round(55 - $tt * 30); $sat = round(95 - $tt * 5); $light = round(60 - $tt * 10);
					}
?>
					<td style="background:hsl(<?=$hue?>,<?=$sat?>%,<?=$light?>%);color:rgba(0,0,0,0.8)" title="<?=htmlspecialchars($row['Name'])?> → <?=htmlspecialchars($col['Name'])?>"><?=$miles?></td>
<?php 			endif;
			else: ?>
					<td>N/A</td>
<?php 		endif; endforeach; ?>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

<?php endif; ?>

</div>
<script>
(function(){
	var c = document.getElementById("theme_container");
	if (c) { c.style.maxWidth = "none"; c.style.width = "98%"; }
}());
</script>
