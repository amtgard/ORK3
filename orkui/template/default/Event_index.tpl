
<div class='info-container'>
	<h3><?=$EventDetails['Name'] ?></h3>
	<form class='form-container'>
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$EventDetails['HeraldryUrl']['Url'] ?>' />
			</span>
		</div>
		<div>
			<span>Kingdom</span>
			<span class='form-informational-field'><?=$EventDetails['EventInfo'][0]['KingdomName'] ?></span>
		</div>
		<div>
			<span>Park</span>
			<span class='form-informational-field'><?=$EventDetails['EventInfo'][0]['ParkName'] ?></span>
		</div>
		<div>
			<span>Player</span>
			<span class='form-informational-field'><?=$EventDetails['EventInfo'][0]['Persona'] ?></span>
		</div>
		<div>
			<span>Group</span>
			<span class='form-informational-field'><a href='<?=UIR . 'Unit/index/' . $EventDetails['EventInfo'][0]['UnitId'] ?>'><?=$EventDetails['EventInfo'][0]['Unit'] ?></a></span>
		</div>
		<div>
			<span>Title</span>
			<span class='form-informational-field'><?=$EventDetails['EventInfo'][0]['Name'] ?></span>
		</div>
	</form>
	<h3>Details</h3>
	<ul>
		<li><a href='<?=UIR ?>Reports/attendance/Event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/All'>Attendance</a></li>
	</ul>
</div>

<div class='info-container'>
	<h3><?=$EventDetails['Name'] ?></h3>
	<table class='information-table action-table' id='EventListTable'>
		<thead>
			<tr>
				<th>Price</th>
				<th>Date</th>
				<th>Website</th>
				<th>Location</th>
				<th>Map</th>
				<th>Active</th>
				<th>Attendance</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($EventDetails['CalendarEventDetails'])) $EventDetails['CalendarEventDetails'] = array(); ?>
<?php foreach ($EventDetails['CalendarEventDetails'] as $key => $detail) : ?>
			<tr>
				<td><?=$detail['Price'] ?></td>
				<td><?=date('Y-m-d, g A', strtotime($detail['EventStart'])) . '<br>' . date('Y-m-d, g A', strtotime($detail['EventEnd'])) ?></td>
				<td><?=$detail['UrlName'] ?> <a href='<?=$detail['Url'] ?>'>[ Link ]</a></td>
				<td>
					<?php
					$location = json_decode(stripslashes($detail['Location']));
					$location = ((isset($location->location))?$location->location:$location->bounds->northeast);
					$address = json_decode($detail['Geocode'], true);
					?>
					<a target='_new' href="http://maps.google.com/maps?q=@<?= $location->lat . ',' . $location->lng ?>"><?=$address['results'][0]['formatted_address']; ?></a>
				</td>
				<td>
			<?php if (trimlen($detail['MapUrlName']) > 0) : ?>
				<?=$detail['MapUrlName'] ?> <a href='<?=$detail['MapUrl'] ?>'>[ Link ]</a>
			<?php endif; ?>
				</td>
				<td><?php if ($detail['Current'] == 1) : ?>Yes<?php else : ?>No<?php endif ?></td>
				<td><a href='<?=UIR ?>Attendance/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/<?=$detail['EventCalendarDetailId'] ?>'>Attendance</a></td>
			</tr>
			<tr class='table-data-break'>
				<td colspan=8><?=nl2br($detail['Description']) ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

