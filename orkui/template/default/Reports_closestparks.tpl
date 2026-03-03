<div class='info-container'>
	<h3>Closest Parks<?php if (!empty($origin_park)): ?> to <?=htmlspecialchars($origin_park)?><?php endif; ?></h3>
<?php if (!empty($error)): ?>
	<p class="error"><?=htmlspecialchars($error)?></p>
<?php elseif (empty($parks)): ?>
	<p>No nearby parks found. This park may not have geocoordinates on file.</p>
<?php else: ?>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
				<th>#</th>
				<th>Park</th>
				<th>Kingdom</th>
				<th>Location</th>
				<th>Distance</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($parks as $i => $park): ?>
			<tr>
				<td><?=$i + 1?></td>
				<td><a href='<?=UIR.'Park/index/'.$park['ParkId']?>'><?=htmlspecialchars($park['ParkName'])?></a></td>
				<td><?=htmlspecialchars($park['KingdomName'])?></td>
				<td><?=htmlspecialchars(trim($park['City'].', '.$park['Province'], ', '))?></td>
				<td><?=htmlspecialchars($park['Miles'])?> mi</td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>

<script>
	$(function() {
		$(".information-table").tablesorter({
			theme: 'jui',
			widgets: ["zebra", "filter", "print"],
			widgetOptions: {
				zebra: ["normal-row", "alt-row"],
				print_title: '',
				print_dataAttrib: 'data-name',
				print_rows: 'f',
				print_columns: 's',
				print_extraCSS: '',
				print_now: true,
				print_callback: function(config, $table, printStyle) {
					$.tablesorter.printTable.printOutput(config, $table.html(), printStyle);
				}
			}
		});

		$('.print.button').click(function(e) {
			e.preventDefault();
			$('.tablesorter').trigger('printTable');
		});

		$('.download.button').click(function(e) {
			e.preventDefault();
			$("table.information-table").table2csv({"filename": "Closest Parks", "excludeRows": ".tablesorter-filter-row"});
		});
	});
</script>
<?php endif; ?>
</div>

<div class='info-container'>
	<h3>About this Report</h3>
	<p>The <strong>Closest Parks</strong> report lists the 10 nearest active Amtgard parks to this park, ranked by straight-line distance.</p>
	<ul>
		<li><strong>Data source:</strong> Each park's recorded GPS coordinates (latitude &amp; longitude) stored in the ORK.</li>
		<li><strong>Distance method:</strong> Great-circle distance using the Haversine formula (<code>ST_Distance_Sphere</code>), which measures the shortest path along the surface of the Earth. This is straight-line distance — it does not account for roads, terrain, or travel time.</li>
		<li><strong>Units:</strong> Miles.</li>
		<li><strong>Exclusions:</strong> Parks with no coordinates on file, and Retired parks, are excluded. If this park has no coordinates on file, the report cannot run.</li>
	</ul>
</div>
