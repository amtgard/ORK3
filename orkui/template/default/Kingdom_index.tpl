<div id='kingdomnew-preview-banner' style='display:block;width:calc(100% - 44px);background:#eaf4fb;border:1px solid #b0d4ea;border-radius:4px;padding:10px 16px;margin:10px;font-size:0.95em;color:#1a5276;'>
	Want a sneak preview of our new, enhanced kingdom profile? <a href='<?=UIR ?>Kingdomnew/index/<?=$kingdom_id ?>'>Check it out here</a>. Note: Clicking any link will return you to the regular design.
</div>

<div class='info-container'>
	<h3><?=$kingdom_name; ?></h3>
	<?=$kingdom_info['Info']['KingdomInfo']['HasHeraldry']==1?"<img src='{$kingdom_info["HeraldryUrl"]["Url"]}' class='heraldry-img' />":"" ?>
	<ul>
		<li><a href='<?=UIR ?>Search/kingdom/<?=$kingdom_id ?>'>Search Players</a></li>
<?php if ($LoggedIn) : ?>
		<li><a href='<?=UIR ?>Award/kingdom/<?=$kingdom_id ?>'>Enter Awards</a></li>
<?php endif ; ?>
		<li><a href='<?=UIR ?>Kingdom/map/<?=$kingdom_id ?>'>Kingdom Atlas</a></li>
		<li><a href='<?=UIR ?>Treasury/kingdom/<?=$KingdomInfo['KingdomId'] ?>'>Treasury</a></li>
	</ul>

	<?php if (!empty($kingdom_officers['Officers'])): ?>
	<h4>Monarchy</h4>
		<ul>
			<?php foreach ($kingdom_officers['Officers'] as $key => $officer): ?>
				<li><?= $officer['OfficerRole']; ?>: <?php if (!empty($officer['MundaneId']) && $officer['MundaneId'] > 0): ?><a href="<?=UIR.'Player/index/'.$officer['MundaneId'] ?>"><?= $officer['Persona']; ?></a><?php else: ?>(Vacant)<?php endif; ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<div class='info-container'>
	<h3>Parks</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Park</th>
				<th>Status</th>
				<th title="Average unique player sign-ins per week over the last 26 weeks (total ÷ 26)">Ave.</th>
				<th title="Average unique player sign-ins per month over the last 12 months (total ÷ 12)">Monthly</th>
				<th title="Total unique player sign-ins by week over the last 26 weeks. Multiple sign-ins in one week count once.">Total</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($park_summary['KingdomParkAveragesSummary'])) $park_summary['KingdomParkAveragesSummary'] = array() ?>
<?php $att = 0 ?>
<?php foreach ($park_summary['KingdomParkAveragesSummary'] as $k => $park): ?>
    <?php $att += $park['AttendanceCount']; ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Park/index/<?=$park['ParkId'];?>"' data-park-id='<?=$park['ParkId']?>'>
				<td>
					<div class='tiny-heraldry'>
						<?php if ($park['HasHeraldry']==1): ?>
							<img src="<?=HTTP_PARK_HERALDRY . Common::resolve_image_ext(DIR_PARK_HERALDRY, sprintf("%05d", $park['ParkId'])) ?>" onerror="this.src='<?=HTTP_PARK_HERALDRY ?>00000.jpg';">
						<?php else: ?>
							<img src="<?=HTTP_PARK_HERALDRY ?>00000.jpg">
						<?php endif; ?>
					</div>
					<?=$park['ParkName'] ?>
				</td>
				<td><?=!empty($park['Title']) ? $park['Title'] : '' ?></td>
				<td class='data-column'><?=sprintf("%0.02f",($park['AttendanceCount']/26)); ?></td>
				<td class='data-column monthly-stat'>—</td>
				<td class='data-column'><?=$park['AttendanceCount']; ?></td>
			</tr>
<?php endforeach; ?>
    		<tr>
				<td></td>
				<td></td>
				<td class='data-column'><?=sprintf("%0.02f",($att/26)); ?></td>
				<td class='data-column monthly-total'>—</td>
				<td class='data-column'><?=$att; ?></td>
			</tr>
<script>
jQuery(document).ready(function($) {
	$.get('<?=UIR?>Kingdom/park_monthly_json/<?=$kingdom_id?>', function(data) {
		var total = 0;
		$('[data-park-id]').each(function() {
			var parkId = $(this).data('park-id');
			var monthlyCount = data[parkId] || 0;
			total += monthlyCount;
			$(this).find('.monthly-stat').text((monthlyCount / 12).toFixed(1));
		});
		$('.monthly-total').text((total / 12).toFixed(1));
	}, 'json').fail(function() {
		$('.monthly-stat, .monthly-total').text('err');
	});
});
</script>
		</tbody>
	</table>
</div>

<?php if (!$IsPrinz && is_array($principalities['Principalities']) && (sizeof($principalities['Principalities']) > 0)) : ?>
<div class='info-container'>
	<h3>Principalities</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Principality</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($principalities['Principalities'])) $principalities['Principalities'] = array(); ?>
<?php foreach ($principalities['Principalities'] as $k => $prinz): ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Kingdom/index/<?=$prinz['KingdomId'];?>&kingdom_name=<?=$prinz['Name'];?>"'>
				<td>
					<div class='tiny-heraldry'>
						<img src="<?=HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf("%04d", $prinz['KingdomId'])) ?>" onerror="this.src='<?=HTTP_KINGDOM_HERALDRY ?>0000.jpg';">
					</div>
					<?=$prinz['Name'] ?>
				</td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php endif; ?>

<div class='info-container'>
	<h3>Reports</h3>
	<ul>
		<li>
			Players
			<ul>
				<li><a href='<?=UIR ?>Reports/roster/Kingdom&id=<?=$kingdom_id ?>'>Player Roster</a></li>
				<li><a href='<?=UIR ?>Reports/active/Kingdom&id=<?=$kingdom_id ?>'>Active Players</a></li>
				<li><a href='<?=UIR ?>Reports/dues/Kingdom&id=<?=$kingdom_id ?>'>Dues Paid Players</a></li>
				<li><a href='<?=UIR ?>Reports/waivered/Kingdom&id=<?=$kingdom_id ?>'>Waivered Players</a></li>
				<li><a href='<?=UIR ?>Reports/unwaivered/Kingdom&id=<?=$kingdom_id ?>'>Unwaivered Players</a></li>
				<li><a href='<?=UIR ?>Reports/suspended/Kingdom&id=<?=$kingdom_id ?>'>Suspended Players</a></li>
        <li><a href='<?=UIR ?>Reports/active_duespaid/Kingdom&id=<?=$kingdom_id ?>'>Player Attendance</a></li>
				<li><a href='<?=UIR ?>Reports/active_waivered_duespaid/Kingdom&id=<?=$kingdom_id ?>'>Waivered Player Attendance</a></li>
				<li><a href='<?=UIR ?>Reports/reeve&KingdomId=<?=$kingdom_id ?>'>Reeve Qualified</a></li>
				<li><a href='<?=UIR ?>Reports/corpora&KingdomId=<?=$kingdom_id ?>'>Corpora Qualified</a></li>
				<li><a class="unimplemented" href='<?=UIR ?>Reports/duespaid/Kingdom&id=<?=$kingdom_id ?>'>Dues Paid Players (OLD)</a></li>
			</ul>
		</li>
		<li>
			Awards
			<ul>
				<li><a href='<?=UIR ?>Reports/player_award_recommendations&KingdomId=<?=$kingdom_id ?>'>Award Recommendations</a></li>
				<li><a href='<?=UIR ?>Reports/knights_and_masters&KingdomId=<?=$kingdom_id ?>'>Knights and Masters</a></li>
				<li><a href='<?=UIR ?>Reports/knights_list&KingdomId=<?=$kingdom_id ?>'>Knights</a></li>
				<li><a href='<?=UIR ?>Reports/masters_list&KingdomId=<?=$kingdom_id ?>'>Masters</a></li>
				<li><a href='<?=UIR ?>Reports/player_awards&Ladder=8&KingdomId=<?=$kingdom_id ?>'><?=$IsPrinz?'Principality':'Kingdom' ?>-level Awards</a></li>
				<li><a href='<?=UIR ?>Reports/class_masters&KingdomId=<?=$kingdom_id ?>'>Class Masters/Paragons</a></li>
				<li><a href='<?=UIR ?>Reports/guilds&KingdomId=<?=$kingdom_id ?>'><?=$IsPrinz?'Principality':'Kingdom' ?> Guilds</a></li>
				<li><a href='<?=UIR ?>Reports/custom_awards&KingdomId=<?=$kingdom_id ?>'>Custom Awards</a></li>
			</ul>
		</li>
		<li>
			Attendance
			<ul>
				<li><a href='<?=UIR ?>Reports/attendance/Kingdom/<?=$kingdom_id ?>/Weeks/1'>Past Week</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Kingdom/<?=$kingdom_id ?>/Months/1'>Past Month</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Kingdom/<?=$kingdom_id ?>/Months/3'>Past 3 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Kingdom/<?=$kingdom_id ?>/Months/6'>Past 6 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Kingdom/<?=$kingdom_id ?>/Months/12'>Past 12 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Kingdom/<?=$kingdom_id ?>/All'>All</a></li>
				<li><a href='<?=UIR ?>Reports/park_attendance_explorer'>Park Attendance Explorer</a></li>
			</ul>
		</li>
		<li>
			Heraldry
			<ul>
				<li><a href='<?=UIR ?>Reports/parkheraldry/<?=$kingdom_id ?>'><?=$IsPrinz?'Principality':'Kingdom' ?> Heraldry, Parks</a></li>
				<li><a href='<?=UIR ?>Reports/playerheraldry/<?=$kingdom_id ?>'><?=$IsPrinz?'Principality':'Kingdom' ?> Heraldry, Players</a></li>
			</ul>
		</li>
		<li><a href='' class='unimplemented'>Treasury Report</a></li>
		<li><a href='<?=UIR ?>Unit/unitlist&KingdomId=<?=$kingdom_id ?>'>Companies and Households</a></li>
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
				<td>
					<div class='tiny-heraldry'>
						<?php if ($event['HasHeraldry']==1): ?>
							<img src="<?=HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf("%05d", $event['EventId'])) ?>" onerror="this.src='<?=HTTP_EVENT_HERALDRY ?>00000.jpg';">
						<?php else: ?>
							<img src="<?=HTTP_EVENT_HERALDRY ?>00000.jpg">
						<?php endif; ?>
					</div>
					<?=$event['Name'] ?>
				</td>
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
<?php foreach ($kingdom_tournaments['Tournaments'] as $k => $tournament) : ?>
			<tr onClick='window.document.location.href="<?=UIR ?>Tournament/worksheet/<?=$tournament['TournamentId'] ?>"'>
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
	<h3>Find</h3>
	<ul>
		<li><a href='<?=UIR ?>Search/kingdom/<?=$kingdom_id ?>'>Players</a></li>
		<li><a href='<?=UIR ?>Search/unit&KingdomId=<?=$kingdom_id ?>'>Companies &amp; Households</a></li>
		<li><a href='<?=UIR ?>Search/event&KingdomId=<?=$kingdom_id ?>'>Events</a></li>
	</ul>
</div>
<div class='info-container'>
	<h3>Calendar</h3>
</div>
