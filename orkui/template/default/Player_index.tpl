<div class='info-container <?=(($Player['Suspended'])==1)?"suspended-player":"" ?>' id='player-editor'>
<h3><?=$Player['Persona'] ?></h3>
	<form class='form-container' >
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Player['HasHeraldry']>0?$Player['Heraldry']:HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
			</span>
		</div>
		<div>
			<span>Image:</span>
			<span>
				<img class='heraldry-img' src='<?=$Player['HasImage']>0?HTTP_PLAYER_IMAGE . sprintf('%06d', $Player['MundaneId']) . '.jpg':HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
			</span>
		</div>
	</form>
</div>
<div class='info-container <?=(($Player['Suspended'])==1)?"suspended-player":"" ?>' id='player-editor'>
	<h3>Player Details</h3>
	<form class='form-container' >
		<div>
			<span>Given Name:</span>
			<span class='form-informational-field'><?=$Player['GivenName'] ?></span>
		</div>
		<div>
			<span>Surname:</span>
			<span class='form-informational-field'><?=$Player['Surname'] ?></span>
		</div>
		<div>
			<span>Persona:</span>
			<span class='form-informational-field'><?=$Player['Persona'] ?></span>
		</div>
		<div>
			<span>Username:</span>
			<span class='form-informational-field'><?=$Player['UserName'] ?></span>
		</div>
		<div>
			<span>Restricted:</span>
			<span><input type='checkbox' value='Restricted' <?=($Player['Restricted'])==1?"Checked":"" ?> DISABLED name='Restricted' id='Restricted' /></span>
		</div>
		<div>
			<span>Company:</span>
			<span class='form-informational-field'><?=$Player['Company'] ?></span>
		</div>
		<div>
			<span>Suspended:</span>
			<span><input type='checkbox' value='Suspended' <?=(($Player['Suspended'])==1)?"CHECKED":"" ?> DISABLED name='PenaltyBox' id='PenaltyBox' /></span>
		</div>
	<?php if ($Player['Suspended']==1) : ?>
		<div>
			<span>Suspended At:</span>
			<span class='form-informational-field'><?=$Player['SuspendedAt'] ?></span>
		</div>
		<div>
			<span>Suspended At:</span>
			<span class='form-informational-field'><?=$Player['SuspendedUntil'] ?></span>
		</div>
		<div>
			<span>Suspended At:</span>
			<span class='form-informational-field'><?=$Player['Suspension'] ?></span>
		</div>
	<?php endif; ?>
		<div>
			<span>Enabled:</span>
			<span><input type='checkbox' value='Active' <?=(($Player['Active'])==1 && ($Player['Suspended'])==0)?"CHECKED":"" ?> DISABLED name='Active' id='Active' /></span>
		</div>
		<div>
			<span>Dues Paid:</span>
			<span class='form-informational-field'><?=$Player['DuesThrough']==0?"No":$Player['DuesThrough'] ?></span>
		</div>
	</form>
</div>

<div class='info-container'>
	<h3>Companies &amp; Households</h3>
	<table class='information-table' id='Attendance'>
		<thead>
			<tr>
				<th>Name</th>
				<th>Type</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Units['Units'])) $Units['Units'] = array(); ?>
<?php foreach ($Units['Units'] as $key => $unit) : ?>
			<tr>
				<td><a href='<?=UIR ?>Unit/index/<?=$unit['UnitId'] ?>'><?=$unit['Name'] ?></td>
				<td><?=ucfirst($unit['Type']) ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Classes</h3>
	<form class='form-container'>
		<table class='information-table' id='Classes'>
			<thead>
				<tr>
					<th>Class</th>
					<th>Credits</th>
					<th>Level</th>
				</tr>
			</thead>
			<tbody>
<?php if (!is_array($Details['Classes'])) $Details['Classes'] = array(); ?>
<?php foreach ($Details['Classes'] as $key => $detail) : ?>
				<tr>
					<td><?=$detail['ClassName'] ?></td>
					<td class='data-column'><?=$detail['Credits'] + (isset($Player_index)?$Player_index['Class_' . $detail['ClassId']]:$detail['Reconciled']) ?></td>
					<td class='data-column'><?=abs(min(ceil(($detail['Credits'] + (isset($Player_index)?$Player_index['Class_' . $detail['ClassId']]:$detail['Reconciled']))/12),6)) ?></td>
				</tr>
<?php endforeach ?>
			</tbody>
		</table>
	</form>
	<script type='text/javascript'>
		$(document).ready(function() {
			$('#Classes tbody tr').each(function(k, trow) {
				var credits = Number($(trow).find('td:nth-child(2)').html());
				var level = 1;
				if (credits >= 53)
					level = 6;
				else if (credits >= 34)
					level = 5;
				else if (credits >= 21)
					level = 4;
				else if (credits >= 12)
					level = 3;
				else if (credits >= 5)
					level = 2;
				$(trow).find('td:nth-child(3)').html(level);
			});
		});
	</script>
</div>

<div class='info-container'>
    <h3>Notes</h3>
	<table class='information-table form-container' id='Notes'>
		<thead>
			<tr>
				<th>Note</th>
				<th>Description</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Notes)) foreach ($Notes as $key => $note) : ?>
    		<tr>
				<td><?=$note['Note'] ?></td>
    			<td><?=$note['Description'] ?></td>
    			<td class='form-informational-field' style='text-wrap: nowrap'><?=$note['Date'] . (strtotime($note['DateComplete'])>0?(" - " . $note['DateComplete']):"") ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Awards &amp; Titles</h3>
	<table class='information-table form-container' id='Awards'>
		<thead>
			<tr>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
				<th>Entered By</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Awards'])) $Details['Awards'] = array(); ?>
<?php foreach ($Details['Awards'] as $key => $detail) : ?>
    		<tr>
				<td style='white-space: nowrap;'><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?><?=(trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'])!=$detail['Name']?" <span class='form-informational-field'>[$detail[Name]]</span>":"" ?></td>
				<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
				<td class='form-informational-field' style='white-space: nowrap;'><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
				<td style='white-space: nowrap;'><a href='<?=UIR ?>Player/index/<?=$detail['GivenById'] ?>'><?=substr($detail['GivenBy'],0,30) ?></a></td>
				<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
				<td><?=$detail['Note'] ?></td>
				<td><a href="<?=UIR.'Player/index/'.$detail['EnteredById'] ?>"><?=$detail['EnteredBy'] ?></a></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Attendance</h3>
	<table class='information-table' id='Attendance'>
		<thead>
			<tr>
				<th>Date</th>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Event</th>
				<th>Class</th>
				<th>Credits</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Attendance'])) $Details['Attendance'] = array(); ?>
<?php foreach ($Details['Attendance'] as $key => $detail) : ?>
			<tr>
				<td><a href='<?=UIR ?>Attendance/<?=$detail['ParkId']>0?'park':'event' ?>/<?=(($detail['ParkId']>0)?($detail['ParkId'].'&AttendanceDate='.$detail['Date']):($detail['EventId'].'/'.$detail['EventCalendarDetailId'])) ?>'><?=$detail['Date'] ?></td>
				<td><a href='<?=UIR ?>Kingdom/index/<?=$detail['KingdomId'] ?>'><?=$detail['KingdomName'] ?></a></td>
				<td><a href='<?=UIR ?>Park/index/<?=$detail['ParkId'] ?>'><?=$detail['ParkName'] ?></a></td>
				<td><a href='<?=UIR ?>Attendance/event/<?=$detail['EventId'] ?>/<?=$detail['EventCalendarDetailId'] ?>'><?=$detail['EventName'] ?></a></td>
				<td><?=trimlen($detail['Flavor'])>0?$detail['Flavor']:$detail['ClassName'] ?></td>
				<td class='data-column'><?=$detail['Credits'] ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

