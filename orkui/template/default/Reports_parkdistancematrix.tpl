<style>
.pdm-wrap { overflow-x: auto; }
.pdm-table { border-collapse: collapse; white-space: nowrap; font-size: 0.8em; }
.pdm-table th, .pdm-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; }
.pdm-table td.pdm-rowhead { text-align: left; font-weight: bold; white-space: nowrap; background: #f5f5f5; }
.pdm-table td.pdm-location { text-align: left; white-space: nowrap; background: #f5f5f5; color: #555; }
.pdm-table th.pdm-colhead { vertical-align: bottom; padding: 4px 2px; background: #f5f5f5; }
.pdm-table th.pdm-colhead > div {
	writing-mode: vertical-rl;
	transform: rotate(180deg);
	white-space: nowrap;
	max-height: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	font-weight: bold;
	cursor: default;
}
.pdm-table td.pdm-self { background: #ddd; color: #999; }
.pdm-table th.pdm-corner { background: #f5f5f5; }
</style>

<div class='info-container'>
	<h3>Park Distance Matrix (miles)</h3>
<?php if (!empty($error)): ?>
	<p class="error"><?=htmlspecialchars($error)?></p>
<?php elseif (empty($parks)): ?>
	<p>No parks with geocoordinates found for this kingdom.</p>
<?php else:
	// Compute global min/max across all off-diagonal cells for HSL normalization
	$all_miles = array();
	foreach ($matrix as $row) foreach ($row as $miles) $all_miles[] = $miles;
	$min_miles = $all_miles ? min($all_miles) : 0;
	$max_miles = $all_miles ? max($all_miles) : 1;
	$range     = max($max_miles - $min_miles, 1);
?>
	<p><?=count($parks)?> parks with coordinates. Distances shown in miles.</p>
	<div class="pdm-wrap">
		<table class="pdm-table">
			<thead>
				<tr>
					<th class="pdm-corner"></th>
					<th class="pdm-corner"></th>
<?php foreach ($parks as $col_id => $col): ?>
					<th class="pdm-colhead" title="<?=htmlspecialchars($col['Name'])?>">
						<div><?=htmlspecialchars($col['Name'])?></div>
					</th>
<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
<?php foreach ($parks as $row_id => $row): ?>
				<tr>
					<td class="pdm-rowhead"><a href="<?=UIR.'Park/index/'.$row_id?>"><?=htmlspecialchars($row['Name'])?></a></td>
					<td class="pdm-location"><?=htmlspecialchars(trim($row['City'].', '.$row['Province'], ', '))?></td>
<?php 	foreach ($parks as $col_id => $col):
			if ($row_id === $col_id):
?>
					<td class="pdm-self" title="<?=htmlspecialchars($row['Name'])?>">—</td>
<?php 		elseif (isset($matrix[$row_id][$col_id])):
				$miles = $matrix[$row_id][$col_id];
				$t = ($miles - $min_miles) / $range;  // 0 = closest, 1 = farthest
				// Piecewise: green(120,70%,65%) → yellow(55,95%,60%) → orange(25,90%,50%)
				if ($t < 0.5) {
					$tt    = $t * 2;
					$hue   = round(120 - ($tt * 65));  // 120 → 55
					$sat   = round(70  + ($tt * 25));  // 70% → 95%
					$light = round(65  - ($tt * 5));   // 65% → 60%
				} else {
					$tt    = ($t - 0.5) * 2;
					$hue   = round(55  - ($tt * 30));  // 55 → 25
					$sat   = round(95  - ($tt * 5));   // 95% → 90%
					$light = round(60  - ($tt * 10));  // 60% → 50%
				}
				$style = "background:hsl($hue,$sat%,$light%)";
?>
					<td style="<?=$style?>" title="<?=htmlspecialchars($row['Name'])?> → <?=htmlspecialchars($col['Name'])?>"><?=$miles?></td>
<?php 		else: ?>
					<td>N/A</td>
<?php 		endif; ?>
<?php 	endforeach; ?>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
</div>

<div class='info-container'>
	<h3>About this Report</h3>
	<p>The <strong>Park Distance Matrix</strong> shows the straight-line distance in miles between every pair of active parks in this kingdom that have GPS coordinates on file.</p>
	<ul>
		<li><strong>Rows &amp; columns:</strong> Each park appears once as a row (with its city/state) and once as a column header. The cell where a row and column intersect shows the distance between those two parks.</li>
		<li><strong>Diagonal:</strong> Cells where a park intersects with itself are shown as "—" (zero distance).</li>
		<li><strong>Distance method:</strong> Great-circle distance using the Haversine formula (<code>ST_Distance_Sphere</code>), measuring the shortest path along the Earth's surface. Straight-line only — roads and terrain are not considered.</li>
		<li><strong>Units:</strong> Miles, rounded to one decimal place.</li>
		<li><strong>Color scale:</strong> Cells are color-coded from <span style="background:hsl(120,70%,65%);padding:0 6px;border-radius:3px;">green</span> (shortest distance) through <span style="background:hsl(55,95%,60%);padding:0 6px;border-radius:3px;">yellow</span> (midpoint) to <span style="background:hsl(25,90%,50%);color:#fff;padding:0 6px;border-radius:3px;">orange</span> (longest distance). The scale is relative to this kingdom's own min and max distances, so colors always span the full range regardless of how spread out the parks are.</li>
		<li><strong>Exclusions:</strong> Parks with no coordinates on file and Retired parks are not included.</li>
	</ul>
</div>
