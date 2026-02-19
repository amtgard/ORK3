<div id='parknew-preview-banner' style='display:block;width:calc(100% - 44px);background:#eaf4fb;border:1px solid #b0d4ea;border-radius:4px;padding:10px 16px;margin:10px;font-size:0.95em;color:#1a5276;'>
	Want a sneak preview of our new, enhanced park profile? <a href='<?=UIR ?>Parknew/index/<?=$park_id ?>'>Check it out here</a>. Note: Clicking any link will return you to the regular design.
</div>

<div class='info-container'>
	<h3><?=$this->__session->park_name; ?></h3>
	<?=$park_info['ParkInfo']['HasHeraldry']==1?"<img src='{$park_info["Heraldry"]["Url"]}' class='heraldry-img' />":"" ?>
	<ul>
<?php if ($LoggedIn) : ?>
		<li><a href='<?=UIR ?>Attendance/park/<?=$park_id ?>'>Enter Attendance</a></li>
		<li><a href='<?=UIR ?>Award/park/<?=$park_id ?>'>Enter Awards</a></li>
		<li><a href='<?=UIR ?>Attendance/behold/<?=$park_id ?>'>Behold!</a></li>
<?php endif ; ?>
		<li><a href='<?=UIR ?>Search/park/<?=$park_id ?>'>Search Players</a></li>
		<li><a href='<?=UIR ?>Reports/playerheraldry/<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Park Heraldry, Players</a></li>
		<li><a href='<?=UIR ?>Unit/unitlist&ParkId=<?=$park_id ?>'>Companies and Households</a></li>
		<li><a href='<?=UIR ?>Treasury/park/<?=$park_info['ParkInfo']['ParkId'] ?>'>Treasury</a></li>
		<li><?php $location = json_decode(stripslashes($park_info['ParkInfo']['Location'])); $location = ((isset($location->location))?$location->location:$location->bounds->northeast);  ?>
			<a href="http://maps.google.com/maps?q=@<?= $location->lat . ',' . $location->lng ?>">Park Map</a>
		</li>
		<?php if (!empty($park_info['ParkInfo']['Url'])): ?>
		<li><a href='<?=$park_info['ParkInfo']['Url'] ?>' target="_blank">Website</a></li>
		<?php endif; ?>
	</ul>
	<?php if (!empty($park_officers['Officers'])): ?>
	<h4>Park Monarchy</h4>
		<ul>
			<?php foreach ($park_officers['Officers'] as $key => $officer): ?>
				<li><?= $officer['OfficerRole']; ?>: <?php if (!empty($officer['MundaneId']) && $officer['MundaneId'] > 0): ?><a href="<?=UIR.'Player/index/'.$officer['MundaneId'] ?>"><?= $officer['Persona']; ?></a><?php else: ?>(Vacant)<?php endif; ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>	
	<?php foreach ([
		'Park Title'  => 'ParkTitle',
		'Description' => 'Description',
		'Directions'  => 'Directions'
	] as $label => $field): ?>
		<?php if (trimlen($park_info['ParkInfo'][$field]) > 0): ?>
			<h3><?= $label ?></h3>
			<div style='max-width: 600px;'><?= $park_info['ParkInfo'][$field] ?></div>
			<br>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
<div class='info-container'>
	<h3>Park Days</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editpark/<?=$ParkId ?>&Action=config'>
    <style>
      .address-row {
        max-width: 10vw;
      }
      @media only screen and (max-width: 800px) {
        .address-row {
          max-width: 30vw;
        }
        .park-day-type {
          display: none;
        }
      }
    </style>
		<table class='information-table'>
			<thead>
				<tr>
					<th>When</th>
					<th>Time</th>
					<th class='park-day-type'>Purpose</th>
					<th>Map</th>
					<th>Location</th>
				</tr>
			</thead>
			<tbody>
<?php foreach($park_days['ParkDays'] as $key => $day) : ?>
			<tr>
				<td>
<?php
	switch ($day['Recurrence']) {
		case 'weekly': echo "Every " . $day['WeekDay']; break;
		case 'week-of-month': echo "Every " . shortScale::toDigith($day['WeekOfMonth']) . " " . $day['WeekDay'];	break;
		case 'monthly': echo "Every month on the " . shortScale::toDigith($day['MonthDay']); break;
	}
?>
				</td>
				<td><?=date('g:i A', strtotime($day['Time'])) ?></td>
				<td class='park-day-type'>
<?php 
	switch($day['Purpose']) { 
		case 'park-day': echo 'Regular Park Day'; break; 
		case 'fighter-practice': echo 'Fighter Practice'; break; 
		case 'arts-day': echo 'A&amp;S Day'; break; 
		case 'other': echo 'Other'; break; 
	} 
?>
				</td>
				<td>
<?php
  if (trimlen($day['Location']) > 0) {
    $daylocation = json_decode(stripslashes($day['Location'])); 
    $daylocation = ((isset($daylocation->location)) ? $daylocation->location : $daylocation->bounds->northeast);
    $mapurl = "http://maps.google.com/maps?z=14&t=m&q=loc:{$daylocation->lat}+{$daylocation->lng}";
    $mapname = "Alternate Park";
  } else if (trimlen($day['MapUrl']) > 0) {
    $mapurl = $day['MapUrl'];
    $mapname = "Alternate Park";
  } else {
    $mapurl = "http://maps.google.com/maps?z=14&t=m&q=loc:{$location->lat}+{$location->lng}";
    $mapname = "Regular Park";
  }
?>
          <a target='_new' href='<?=$mapurl ?>'><?=$mapname ?></a>
        </td>
				<td class='address-row'><?=$day['Address'] ?></td>
			</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</form>
</div>

<div class='info-container'>
	<h3>Reports</h3>
	<ul>
		<li>
			Players
			<ul>
				<li><a href='<?=UIR ?>Reports/roster/Park&id=<?=$park_id ?>'>Player Roster</a></li>
				<li><a href='<?=UIR ?>Reports/inactive/Park&id=<?=$park_id ?>'>Inactive Player Roster</a></li>
				<li><a href='<?=UIR ?>Reports/active/Park&id=<?=$park_id ?>'>Active Players</a></li>
				<li><a href='<?=UIR ?>Reports/dues/Park&id=<?=$park_id ?>'>Dues Paid Players</a></li>
				<li><a href='<?=UIR ?>Reports/waivered/Park&id=<?=$park_id ?>'>Waivered Players</a></li>
				<li><a href='<?=UIR ?>Reports/unwaivered/Park&id=<?=$park_id ?>'>Unwaivered Players</a></li>
				<li><a href='<?=UIR ?>Reports/suspended/Park&id=<?=$park_id ?>'>Suspended Players</a></li>
   			<li><a href='<?=UIR ?>Reports/active_duespaid/Park&id=<?=$park_id ?>'>Player Attendance</a></li>
				<li><a href='<?=UIR ?>Reports/active_waivered_duespaid/Park&id=<?=$park_id ?>'>Waivered Player Attendance</a></li>
				<li><a href='<?=UIR ?>Reports/reeve&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Reeve Qualified</a></li>
				<li><a href='<?=UIR ?>Reports/corpora&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Corpora Qualified</a></li>
				<li><a class="unimplemented" href='<?=UIR ?>Reports/duespaid/Park&id=<?=$park_id ?>'>Dues Paid Players (OLD)</a></li>
			</ul>
		</li>
		<li><a href='<?=UIR ?>Reports/guilds&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Park Guilds</a></li>
		<li><a href='<?=UIR ?>Reports/player_award_recommendations&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Award Recommendations</a></li>
		<li><a href='<?=UIR ?>Reports/player_awards&Ladder=0&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Player Awards</a></li>
		<li><a href='<?=UIR ?>Reports/class_masters&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Class Masters/Paragons</a></li>
		<li><a href='<?=UIR ?>Reports/custom_awards&KingdomId=<?=$kingdom_id ?>&ParkId=<?=$park_id ?>'>Custom Awards</a></li>
		<li>
			Attendance
			<ul>
				<li><a href='<?=UIR ?>Reports/attendance/Park/<?=$park_id ?>/Weeks/1'>Past Week</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Park/<?=$park_id ?>/Months/1'>Past Month</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Park/<?=$park_id ?>/Months/3'>Past 3 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Park/<?=$park_id ?>/Months/6'>Past 6 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Park/<?=$park_id ?>/Months/12'>Past 12 Months</a></li>
				<li><a href='<?=UIR ?>Reports/attendance/Park/<?=$park_id ?>/All'>All</a></li>
			</ul>
		</li>
		<li><a href='' class='unimplemented'>Treasury Report</a></li>
	</ul>
</div>

<div class='info-container'>
	<h3>Events</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Event</th>
				<th>Next Date</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($event_summary)) $event_summary = array() ?>
<?php foreach ($event_summary as $k => $event): ?>
			<tr onclick='javascript:window.location.href="<?=UIR;?>Event/index/<?=$event['EventId'];?>"'>
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
				<th>Event</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($park_tournaments['Tournaments'] as $k => $tournament) : ?>
			<tr onClick='window.document.location.href="<?=UIR ?>Tournament/worksheet/<?=$tournament['TournamentId'] ?>"'>
				<td><?=$tournament['Name'] ?></td>
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
		<li><a href='<?=UIR ?>Search/park/<?=$park_id ?>'>Players</a></li>
		<li><a href='<?=UIR ?>Search/unit&ParkId=<?=$park_id ?>'>Companies &amp; Households</a></li>
		<li><a href='<?=UIR ?>Search/event&ParkId=<?=$park_id ?>'>Events</a></li>
	</ul>
</div>
