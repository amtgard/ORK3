<div class='info-container'>
	<h3>New Player Attendance by Kingdom</h3>
	<form method="POST" action="<?=UIR?>Admin/new_player_attendance">
		<table class="search-table">
			<tr>
				<td><label for="StartDate">Start Date</label></td>
				<td><input type="text" id="StartDate" name="StartDate" class="datepicker" value="<?=htmlspecialchars($form['StartDate'] ?? '')?>" /></td>
				<td><label for="EndDate">End Date</label></td>
				<td><input type="text" id="EndDate" name="EndDate" class="datepicker" value="<?=htmlspecialchars($form['EndDate'] ?? '')?>" /></td>
				<td>
					<button type="submit" name="RunReport" value="1" class="button">Run Report</button>
				</td>
			</tr>
		</table>
	</form>
</div>

<?php if (isset($summary) && is_array($summary) && count($summary) > 0): ?>
<?php
	$period_label = htmlspecialchars(($form['StartDate'] ?? '') . ' to ' . ($form['EndDate'] ?? ''));
	$totals = array(
		'NewPlayers'       => 0,
		'ReturningPlayers' => 0,
		'NewPlayerVisits'  => 0
	);
	foreach ($summary as $row) {
		$totals['NewPlayers']       += $row['NewPlayers'];
		$totals['ReturningPlayers'] += $row['ReturningPlayers'];
		$totals['NewPlayerVisits']  += $row['NewPlayerVisits'];
	}
	$totals['ReturnPct']             = $totals['NewPlayers'] > 0
		? round(($totals['ReturningPlayers'] / $totals['NewPlayers']) * 100, 1) : 0;
	$totals['AvgVisitsPerNewPlayer'] = $totals['NewPlayers'] > 0
		? round($totals['NewPlayerVisits'] / $totals['NewPlayers'], 2) : 0;
?>
<div class='info-container'>
	<h3>New Player Attendance Summary</h3>
	<p><?=$period_label?></p>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Kingdom / Principality</th>
				<th title="Players whose very first sign-in ever falls within the date range">New Players</th>
				<th title="New players who signed in 2 or more times during the range">Returning Players</th>
				<th title="Returning ÷ New">Return %</th>
				<th title="Total sign-in events for new players within the range">New Player Visits</th>
				<th title="Total visits ÷ new players">Avg Visits / New Player</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($summary as $row): ?>
			<tr onclick='javascript:window.location.href="<?=UIR?>Kingdom/index/<?=$row['KingdomId']?>"' style="cursor:pointer;">
				<td><?=htmlspecialchars($row['KingdomName'])?></td>
				<td class="data-column"><?=$row['NewPlayers']?></td>
				<td class="data-column"><?=$row['ReturningPlayers']?></td>
				<td class="data-column"><?=$row['ReturnPct']?>%</td>
				<td class="data-column"><?=$row['NewPlayerVisits']?></td>
				<td class="data-column"><?=number_format($row['AvgVisitsPerNewPlayer'], 2)?></td>
			</tr>
<?php endforeach; ?>
<?php if (count($summary) > 1): ?>
			<tr class="static" style="background-color:#eee; color:#999; text-shadow:0px 2px 3px #fff; font-weight:bold; font-size:11pt; font-family:'Gill Sans MT','lucida sans unicode',helvetica,arial;">
				<td>Total</td>
				<td class="data-column"><?=$totals['NewPlayers']?></td>
				<td class="data-column"><?=$totals['ReturningPlayers']?></td>
				<td class="data-column"><?=$totals['ReturnPct']?>%</td>
				<td class="data-column"><?=$totals['NewPlayerVisits']?></td>
				<td class="data-column"><?=number_format($totals['AvgVisitsPerNewPlayer'], 2)?></td>
			</tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<?php elseif (isset($summary)): ?>
<div class='info-container'>
	<p>No new players found for the selected date range.</p>
</div>
<?php endif; ?>

<div class='info-container'>
	<h3>About This Report</h3>
	<dl>
		<dt><strong>New Players</strong></dt>
		<dd>A count of players whose very first sign-in in the entire ORK system falls within the selected date range. Each new player is attributed to the kingdom of the park where their first sign-in occurred.</dd>

		<dt><strong>Returning Players</strong></dt>
		<dd>Of the new players identified above, a count of those who signed in two or more times during the selected date range at parks within their attribution kingdom. Measures same-period retention.</dd>

		<dt><strong>Return %</strong></dt>
		<dd>Returning Players ÷ New Players × 100.</dd>

		<dt><strong>New Player Visits</strong></dt>
		<dd>The total number of individual sign-in events for all new players at parks within their attribution kingdom during the date range.</dd>

		<dt><strong>Avg Visits / New Player</strong></dt>
		<dd>New Player Visits ÷ New Players. Indicates how engaged new players were on average during the period.</dd>
	</dl>
</div>

<script>
$(function() {
	$('.datepicker').datepicker({dateFormat: 'yy-mm-dd'});
	$('.information-table').tablesorter({
		theme: 'jui',
		widgets: ['zebra', 'print', 'staticRow'],
		widgetOptions: {
			zebra: ['normal-row', 'alt-row']
		}
	});

	$('.print.button').click(function(e) {
		e.preventDefault();
		var $table = $(this).closest('.info-container').find('table.information-table');
		var config = $table.data('tablesorter');
		if (config) {
			$.tablesorter.printTable.process(config, config.widgetOptions);
		}
	});

	$('.download.button').click(function(e) {
		e.preventDefault();
		$(this).closest('.info-container').find('table.information-table').table2csv({filename: 'New Player Attendance by Kingdom'});
	});
});
</script>
