<script>
	$(function() {
		$('#StartDate').datepicker({dateFormat: 'yy-mm-dd'});
		$('#EndDate').datepicker({dateFormat: 'yy-mm-dd'});
	});
</script>
<div class='info-container'>
	<h3>Top <?=$Limit; ?> Parks by Attendance</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/topparks'>
		<table>
			<tr>
				<td><label>Start Date</label></td>
				<td><input type='text' name='StartDate' id='StartDate' value='<?=htmlspecialchars($StartDate); ?>' /></td>
			</tr>
			<tr>
				<td><label>End Date</label></td>
				<td><input type='text' name='EndDate' id='EndDate' value='<?=htmlspecialchars($EndDate); ?>' /></td>
			</tr>
			<tr>
				<td><label>Local Players Only</label></td>
				<td><input type='checkbox' name='NativePopulace' value='1' <?=$NativePopulace ? "checked='checked'" : ''; ?> /></td>
			</tr>
			<tr>
				<td></td>
				<td><input type='submit' value='Update' /></td>
			</tr>
		</table>
	</form>
	<table class='information-table'>
		<thead>
			<tr>
				<th class='data-column'>Rank</th>
				<th class='data-column'>Weekly Avg</th>
				<th>Park</th>
				<th>Kingdom</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($TopParks)): ?>
	<?php foreach ($TopParks as $rank => $park): ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Park/index/<?=$park['ParkId']; ?>";'>
				<td class='data-column'><?=($rank + 1); ?></td>
				<td class='data-column'><?=sprintf("%0.2f", $park['AttendanceCount'] / $WeekCount); ?></td>
				<td><?=stripslashes($park['ParkName'] ?? ''); ?></td>
				<td><?=stripslashes($park['KingdomName'] ?? ''); ?></td>
			</tr>
	<?php endforeach; ?>
<?php else: ?>
			<tr><td colspan='4'>No data available.</td></tr>
<?php endif; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
	<h3>About This Report</h3>
	<p>This report ranks the top <?=$Limit; ?> parks across all active Amtgard kingdoms by their average weekly attendance over the selected date range (<?=htmlspecialchars($StartDate); ?> to <?=htmlspecialchars($EndDate); ?>, approximately <?=$WeekCount; ?> weeks).</p>
	<p><strong>How attendance is counted:</strong> Each player who attends a given park in a given calendar week counts once toward that park's total, regardless of how many days they attended that week. This deduplication prevents multi-day events from inflating a park's numbers.</p>
	<p><strong>How the weekly average is calculated:</strong> The total deduplicated attendance count over the date range is divided by the number of weeks in the range. A park with a weekly average of 10.5 had, on average, 10&ndash;11 unique players attending each week.</p>
	<p><strong>Local Players Only:</strong> When checked, only attendance by players whose home park matches the park being measured is counted. This filters out visitors and traveling players, giving a view of each park's core local membership activity rather than total foot traffic.</p>
	<p><strong>Filters applied:</strong> Only parks and kingdoms marked <em>Active</em> are included. Attendance records with no associated player (guest sign-ins with mundane ID 0) are excluded.</p>

</div>
