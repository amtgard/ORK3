<div class='info-container'>
	<h3><?=$principality_name; ?></h3>
	<?=$principality_info['Info']['PrincipalityInfo']['HasHeraldry']==1?"<img src='{$principality_info[HeraldryUrl][Url]}' class='heraldry-img' />":"" ?>
	<ul>
		<li><a href='<?=UIR ?>Search/principality/<?=$principality_id ?>'>Search Players</a></li>
		<li><a href='<?=UIR ?>Award/principality/<?=$principality_id ?>'>Enter Awards</a></li>
		<li><a href='<?=UIR ?>Treasury/principality/<?=$PrincipalityInfo['PrincipalityId'] ?>'>Treasury</a></li>
	</ul>
</div>

<div class='info-container'>
	<h3>Parks</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Park</th>
				<th>Ave.</th>
				<th>Total</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($park_summary['PrincipalityParkAveragesSummary'])) $park_summary['PrincipalityParkAveragesSummary'] = array(); ?>
<?php foreach ($park_summary['PrincipalityParkAveragesSummary'] as $k => $park): ?>
<?php 	if ($park['IsPrincipality'] == 0) : ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Park/index/<?=$park['ParkId'];?>&park_name=<?=$park['ParkName'];?>"'>
				<td><?=$park['ParkName'] ?></td>
				<td class='data-column'><?=sprintf("%0.02f",($park['AttendanceCount']/26)); ?></td>
				<td class='data-column'><?=$park['AttendanceCount']; ?></td>
			</tr>
<?php 	endif; ?>
<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Reports</h3>
	<ul>
		<li>
			Players
			<ul>
				<li><a href='<?=UIR ?>Reports/roster/Principality&id=<?=$principality_id ?>'>Player Roster</a></li>
				<li><a href='<?=UIR ?>Reports/active/Principality&id=<?=$principality_id ?>'>Active Players</a></li>
				<li><a href='<?=UIR ?>Reports/duespaid/Principality&id=<?=$principality_id ?>'>Dues Paid Players</a></li>
				<li><a href='<?=UIR ?>Reports/waivered/Principality&id=<?=$principality_id ?>'>Waivered Players</a></li>
				<li><a href='<?=UIR ?>Reports/unwaivered/Principality&id=<?=$principality_id ?>'>Unwaivered Players</a></li>
				<li><a href='<?=UIR ?>Reports/active_waivered_duespaid/Principality&id=<?=$principality_id ?>'>Active, Waivered, Dues Paid Players</a></li>
			</ul>
		</li>
		<li>
			Awards
			<ul>
				<li><a href='<?=UIR ?>Reports/knights_and_masters&PrincipalityId=<?=$principality_id ?>'>Knights and Masters</a></li>
				<li><a href='<?=UIR ?>Reports/player_awards&Ladder=8&PrincipalityId=<?=$principality_id ?>'>Principality-level Awards</a></li>
				<li><a href='<?=UIR ?>Reports/guilds&PrincipalityId=<?=$principality_id ?>'>Principality Guilds</a></li>
			</ul>
		</li>
		<li>
			Attendance
			<ul>
				<li><a href='<?=UIR ?>Reports/attendance/Principality/<?=$principality_id ?>/Weeks/1'>Past Week</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Principality/<?=$principality_id ?>/Months/1'>Past Month</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Principality/<?=$principality_id ?>/Months/3'>Past 3 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Principality/<?=$principality_id ?>/Months/6'>Past 6 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Principality/<?=$principality_id ?>/Months/12'>Past 12 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Principality/<?=$principality_id ?>/All'>All</a></li>
			</ul>
		</li>
		<li>
			Heraldry
			<ul>
				<li><a href='<?=UIR ?>Reports/parkheraldry/<?=$principality_id ?>'>Principality Heraldry, Parks</a></li>
				<li><a href='<?=UIR ?>Reports/playerheraldry/<?=$principality_id ?>'>Principality Heraldry, Players</a></li>
			</ul>
		</li>
		<li><a href=''>Treasury Report</a></li>
		<li><a href='<?=UIR ?>Unit/unitlist&PrincipalityId=<?=$principality_id ?>'>Companies and Households</a></li>
	</ul>
</div>

<div class='info-container'>
	<h3>Events</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Park</th>
				<th>Event</th>
				<th>Next Date</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($event_summary as $k => $event): ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Event/index/<?=$event['EventId'];?>"'>
				<td><?=$event['ParkName'] ?></td>
				<td><?=$event['Name'] ?></td>
				<td><?=0 == $event['NextDate']?"":date("M. j, Y", strtotime($event['NextDate'])) ?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
	<h3>Tournaments</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Tournament</th>
				<th>Park</th>
				<th>Event</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($principality_tournaments['Tournaments'])) $principality_tournaments['Tournaments'] = array(); ?>
<?php foreach ($principality_tournaments['Tournaments'] as $k => $tournament) : ?>
			<tr>
				<td><?=$tournament['Name'] ?></td>
				<td><?=$tournament['ParkName'] ?></td>
				<td><?=$tournament['EventName'] ?></td>
				<td><?=date("M. j, Y", strtotime($tournament['DateTime'])) ?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
</div>
<div class='info-container'>
	<h3>Calendar</h3>
</div>