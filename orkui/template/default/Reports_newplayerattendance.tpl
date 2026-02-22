<div class='info-container'>
	<h3>New Player Attendance</h3>

<?php if (isset($no_kingdom)): ?>
	<p>Please navigate to a kingdom first to use this report.</p>
<?php else: ?>
	<form method="POST" action="<?=UIR?>Reports/new_player_attendance">
		<table class="search-table">
			<tr>
				<td><label for="StartDate">Start Date</label></td>
				<td><input type="text" id="StartDate" name="StartDate" class="datepicker" value="<?=htmlspecialchars($form['StartDate'] ?? '')?>" /></td>
				<td><label for="EndDate">End Date</label></td>
				<td><input type="text" id="EndDate" name="EndDate" class="datepicker" value="<?=htmlspecialchars($form['EndDate'] ?? '')?>" /></td>
			</tr>
			<tr>
				<td><label for="ParkId">Park</label></td>
				<td>
					<select id="ParkId" name="ParkId">
						<option value="0">All Parks</option>
<?php if (is_array($parks)): ?>
<?php 	foreach ($parks as $park): ?>
<?php 		if ($park['Active'] != 'Active') continue; ?>
						<option value="<?=$park['ParkId']?>"<?=($form['ParkId'] ?? 0) == $park['ParkId'] ? ' selected' : ''?>><?=htmlspecialchars($park['Name'])?></option>
<?php 	endforeach; ?>
<?php endif; ?>
					</select>
				</td>
				<td><label for="ShowPlayerDetails">Show Player Details</label></td>
				<td><input type="checkbox" id="ShowPlayerDetails" name="ShowPlayerDetails" value="1"<?=!empty($form['ShowPlayerDetails']) ? ' checked' : ''?> /></td>
			</tr>
			<tr>
				<td colspan="4">
					<button type="submit" name="RunReport" value="1" class="button">Run Report</button>
				</td>
			</tr>
		</table>
	</form>
<?php endif; ?>
</div>

<?php if (isset($summary) && is_array($summary) && count($summary) > 0): ?>
<?php
	$period_label = htmlspecialchars(($form['StartDate'] ?? '') . ' to ' . ($form['EndDate'] ?? ''));
	$totals = array(
		'NewPlayers'            => 0,
		'ReturningPlayers'      => 0,
		'NewPlayerVisits'       => 0
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
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Period</th>
				<th>Park Name</th>
				<th title="Players whose very first sign-in ever falls within the date range">New Players</th>
				<th title="New players who signed in 2 or more times during the range">Returning Players</th>
				<th title="Returning ÷ New">Return %</th>
				<th title="Total sign-in events for new players within the range">New Player Visits</th>
				<th title="Total visits ÷ new players">Avg Visits / New Player</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($summary as $row): ?>
			<tr>
				<td><?=$period_label?></td>
				<td><?=htmlspecialchars($row['ParkName'])?></td>
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
				<td></td>
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
	<p>No new players found for the selected date range and park.</p>
</div>
<?php endif; ?>

<?php if (isset($player_details) && is_array($player_details) && count($player_details) > 0): ?>
<div class='info-container'>
	<h3>New Player Details</h3>
	<div class="actions"><button class="print button">Print</button> <button class="download button">Download CSV</button></div>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Park</th>
				<th>Persona Name</th>
				<th>First Sign-in Date</th>
				<th title="Sign-ins within the selected date range">Visits in Period</th>
				<th title="Most recent sign-in date across all time">Last Sign-in Date</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($player_details as $player): ?>
			<tr onclick='javascript:window.location.href="<?=UIR?>Player/index/<?=$player['MundaneId']?>"' style="cursor:pointer;">
				<td><?=htmlspecialchars($player['ParkName'])?></td>
				<td><?=htmlspecialchars($player['Persona'])?></td>
				<td class="data-column"><?=htmlspecialchars($player['FirstSignInDate'])?></td>
				<td class="data-column"><?=$player['VisitsInPeriod']?></td>
				<td class="data-column"><?=htmlspecialchars($player['LastSignInDate'])?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<div class='info-container'>
	<h3>About This Report</h3>
	<dl>
		<dt><strong>New Players</strong></dt>
		<dd>A count of players whose very first sign-in in the entire ORK system falls within the selected date range. Players who played elsewhere before this period are not counted, even if this is their first visit to this kingdom.</dd>

		<dt><strong>Park Attribution</strong></dt>
		<dd>Each new player is credited to the park where their first sign-in occurred. If a player signed in at multiple parks on the same day as their first-ever sign-in, the park with the lowest system ID is used as the tiebreaker.</dd>

		<dt><strong>Returning Players</strong></dt>
		<dd>Of the new players identified above, a count of those who signed in two or more times during the selected date range. This measures same-period retention — how many newcomers came back at least once before the period ended.</dd>

		<dt><strong>Return %</strong></dt>
		<dd>Returning Players ÷ New Players × 100. A higher percentage indicates that more new players returned for a second visit within the period.</dd>

		<dt><strong>New Player Visits</strong></dt>
		<dd>The total number of individual sign-in events (attendance rows) for all new players during the date range. Each sign-in on a distinct date counts as one visit — the credits field is not summed.</dd>

		<dt><strong>Avg Visits / New Player</strong></dt>
		<dd>New Player Visits ÷ New Players. Indicates how engaged new players were on average during the period.</dd>

		<dt><strong>Last Sign-in Date (Player Details)</strong></dt>
		<dd>The most recent sign-in date for each player across all time, not limited to the report period. This lets you see whether a new player from a past muster is still active today.</dd>
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
		$(this).closest('.info-container').find('table.information-table').table2csv({filename: 'New Player Attendance'});
	});
});
</script>
