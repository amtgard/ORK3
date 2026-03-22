<?php
$_pdm_parks  = is_array($parks  ?? null) ? $parks  : [];
$_pdm_matrix = is_array($matrix ?? null) ? $matrix : [];
$_pdm_count  = count($_pdm_parks);
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

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
		<span>Straight-line distance in miles between every pair of active parks with GPS coordinates on file. Color scale is relative to this kingdom's own min/max — <span style="background:hsl(120,70%,65%);padding:0 4px;border-radius:3px;">green</span> = closest, <span style="background:hsl(55,95%,60%);padding:0 4px;border-radius:3px;">yellow</span> = mid, <span style="background:hsl(25,90%,50%);color:#fff;padding:0 4px;border-radius:3px;">orange</span> = farthest. Does not account for roads or terrain.</span>
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
	$all_miles = [];
	foreach ($_pdm_matrix as $row) foreach ($row as $miles) $all_miles[] = $miles;
	$min_miles = $all_miles ? min($all_miles) : 0;
	$max_miles = $all_miles ? max($all_miles) : 1;
	$range     = max($max_miles - $min_miles, 1);
?>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
			<div class="rp-stat-number"><?=$_pdm_count?></div>
			<div class="rp-stat-label">Parks with Coordinates</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-compress-arrows-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($min_miles, 1)?> mi</div>
			<div class="rp-stat-label">Shortest Distance</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-expand-arrows-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($max_miles, 1)?> mi</div>
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
				$t = ($miles - $min_miles) / $range;
				if ($t < 0.5) {
					$tt = $t * 2;
					$hue = round(120 - $tt * 65); $sat = round(70 + $tt * 25); $light = round(65 - $tt * 5);
				} else {
					$tt = ($t - 0.5) * 2;
					$hue = round(55 - $tt * 30); $sat = round(95 - $tt * 5); $light = round(60 - $tt * 10);
				}
?>
					<td style="background:hsl(<?=$hue?>,<?=$sat?>%,<?=$light?>%)" title="<?=htmlspecialchars($row['Name'])?> → <?=htmlspecialchars($col['Name'])?>"><?=$miles?></td>
<?php 		else: ?>
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
