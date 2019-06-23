<div class='info-container'>
	<h3>Edit <?=$this->__session->park_name ?></h3>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editpark/<?=$ParkId ?>&Action=details' enctype='multipart/form-data'>
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Park_data['HasHeraldry']?$Park_heraldry['Url']:HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
				<input type='file' name='Heraldry' class='restricted-image-type' id='Heraldry' />
			</span>
		</div>	
		<div>
			<span>Abbreviation:</span>
			<span class='form-informational-field'><?=$Park_data['Abbreviation'] ?></span>
		</div>
		<div>
			<span>Park Title:</span>
			<span class='form-informational-field'><?=$Park_data['ParkTitle'] ?></span>
		</div>
		<div>
			<span>Active:</span>
			<span class='form-informational-field'><?=$Park_data['Active']?'Active':'<i>Inactive</i>' ?></span>
		</div>
		<div>
			<span>Url:</span>
			<span><input type='text' value='<?=strlen($Admin_editpark['Url'])>0?$Admin_editpark['Url']:$Park_data['Url'] ?>' name='Url' /></span>
		</div>
		<div>
			<span>Address:</span>
			<span><input type='text' value='<?=strlen($Admin_editpark['Address'])>0?$Admin_editpark['Address']:$Park_data['Address'] ?>' name='Address' /></span>
		</div>
		<div>
			<span>City:</span>
			<span><input type='text' value='<?=strlen($Admin_editpark['City'])>0?$Admin_editpark['City']:$Park_data['City'] ?>' name='City' /></span>
		</div>
		<div>
			<span>State:</span>
			<span><input type='text' value='<?=strlen($Admin_editpark['Province'])>0?$Admin_editpark['Province']:$Park_data['Province'] ?>' name='Province' /></span>
		</div>
		<div>
			<span>Zip:</span>
			<span><input type='text' value='<?=strlen($Admin_editpark['PostalCode'])>0?$Admin_editpark['PostalCode']:$Park_data['PostalCode'] ?>' name='PostalCode' /></span>
		</div>
        <div>
			<span>Description:</span>
			<span><textarea type='text' rows='8' cols=40 name='Description'><?=strlen($Admin_editpark['Description'])>0?$Admin_editpark['Description']:$Park_data['Description'] ?></textarea></span>
		</div>
		<div>
			<span>Directions:</span>
			<span><textarea type='text' rows='8' cols=40 name='Directions'><?=strlen($Admin_editpark['Directions'])>0?$Admin_editpark['Directions']:$Park_data['Directions'] ?></textarea></span>
		</div>
		<div>
			<span>Map Url:</span>
			<span><input type='text' value='<?=strlen($Admin_editpark['MapUrl'])>0?$Admin_editpark['MapUrl']:$Park_data['MapUrl'] ?>' name='MapUrl' /></span>
		</div>
		<div>
			<span>Geo Location:</span>
			<span>
				<?php
				$location = json_decode(stripslashes($Park_data['Location']));
				$location = ((isset($location->location))?$location->location:$location->bounds->northeast);
				?>
				<a href='http://maps.google.com/maps?z=14&t=m&q=loc:<?=$location->lat . "+" . $location->lng ?>'><?=$location->lat . ", " . $location->lng ?></a>
			</span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Edit Park' name='EditPark' /></span>
		</div>
	</form>
</div>

<style type='text/css'>
	.config-input {
		width: 100%;
		display: table-row;
	}
	.config-input>input, .config-input>select, .config-input>span {
		display: table-cell;
	}
</style>
<div class='info-container'>
	<h3>Configuration</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editpark/<?=$ParkId ?>&Action=config'>
		<table class='information-table'>
			<thead>
				<tr>
					<th>Configuration</th>
					<th>Setting</th>
				</tr>
			</thead>
			<tbody>
<?php foreach($Park_config as $key => $config) : ?>
<?php 	if (1 == $config['UserSetting']) : ?>
				<tr>
					<td><?=$config['Key'] ?></td>
					<td>
<?php 		if (is_object($config['Value'])) : ?>
<?php 			foreach ($config['Value'] as $v_key => $v_value) : ?>
<?php				if (is_object($config['AllowedValues']) && array_key_exists($v_key, $config['AllowedValues'])) : ?>
						<div class='config-input'>
							<span><!--<?=$v_key ?>--></span>
							<select name='Config[<?=$config['ConfigurationId'] ?>][<?=$v_key ?>]' value='<?=$v_value ?>' />
								<option> -</option>
<?php					foreach ($config['AllowedValues']->$v_key as $a_key => $a_value) : ?>
								<option <?=$a_value==$v_value?'SELECTED':'' ?> ><?=$a_value ?></option>
<?php					endforeach; ?>
							</select>
						</div>
<?php				else : ?>
						<div class='config-input'><span><!--<?=$v_key ?>--></span><input type='text' class='numeric-field' name='Config[<?=$config['ConfigurationId'] ?>][<?=$v_key ?>]' value='<?=$v_value ?>' /></div>
<?php				endif; ?>
<?php			endforeach; ?>
<?php 		else : ?>
						<input type='text' class='numeric-field' name='Config[<?=$config['ConfigurationId'] ?>]' value='<?=$config['Value'] ?>' />
<?php 		endif; ?>
					</td>
				</tr>
<?php 	endif; ?>
<?php endforeach; ?>
				<tr>
					<td colspan=2><input type='submit' value='Update Config' /></td>
				</tr>
			</tbody>
		</table>
	</form>
</div>

<script type='text/javascript'>
	$(function() {
		$('.recurrence-inputs').hide();
		$('#weekday-input').show();
		$('#Recurrence').change(function() {
			$('.recurrence-inputs').hide();
			switch ($('#Recurrence').val()) {
				case 'weekly':
					$('#weekday-input').show();
					break;
				case 'monthly':
					$('#day-of-month-input').show();
					break;
				case 'week-of-month':
					$('#weekday-input').show();
					$('#week-of-month-input').show();
					break;
			}
		});
		$('#Time').timepicker({ showMinute: false });
		$('.alt-loc-item').hide();
		$('#alternate-location').change(function() {
			if ($('#alternate-location').attr('checked') != 'checked') {
				$('.alt-loc-item').hide();
			} else {
				$('.alt-loc-item').show();
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Edit Park Days</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editpark/<?=$ParkId ?>&Action=addparkday' enctype='multipart/form-data'>
		<div>
			<span>Recurrence:</span>
			<span>
				<select name='Recurrence' id='Recurrence' >
					<option value='weekly'>Weekly</option>
					<option value='monthly'>Monthly</option>
					<option value='week-of-month'>Week-of-Month</option>
				</select>
			</span>
		</div>
		<div id='weekday-input' class='recurrence-inputs'>
			<span>Weekday:</span>
			<span>
				<select name='WeekDay' />
					<option>Monday</option>
					<option>Tuesday</option>
					<option>Wednesday</option>
					<option>Thursday</option>
					<option>Friday</option>
					<option>Saturday</option>
					<option>Sunday</option>
				</select>
			</span>
		</div>
		<div id='week-of-month-input' class='recurrence-inputs'>
			<span>Week of Month:</span>
			<span><input type='text' class='numeric-field' style='float: none;' value='<?=$Park_data['WeekOfMonth'] ?>' name='WeekOfMonth' /></span>
		</div>
		<div id='day-of-month-input' class='recurrence-inputs'>
			<span>Day of Month:</span>
			<span><input type='text' class='numeric-field' style='float: none;' value='<?=$Park_data['MonthDay'] ?>' name='MonthDay' /></span>
		</div>
		<div>
			<span>Time:</span>
			<span><input class='required-field' type='text' value='<?=$Park_data['Time'] ?>' name='Time' id='Time' /></span>
		</div>
		<div>
			<span>Purpose:</span>
			<span>
				<select name='Purpose'>
					<option value='park-day'>Regular Park Day</option>
					<option value='fighter-practice'>Fighter Practice</option>
					<option value='arts-day'>A&amp;S</option>
					<option value='other'>Other</option>
				</select>
			</span>
		</div>
		<div>
			<span>Description:</span>
			<span><input type='text' value='<?=$Park_data['Description'] ?>' name='Description' /></span>
		</div>
		<div>
			<span>Alternate Location:</span>
			<span><input type='checkbox' id='alternate-location' val='1' name='AlternateLocation' /></span>
		</div>
		<div class='alt-loc-item'>
			<span>Address:</span>
			<span><input type='text' value='<?=$Park_data['Address'] ?>' name='Address' /></span>
		</div>
		<div class='alt-loc-item'>
			<span>City:</span>
			<span><input type='text' value='<?=$Park_data['City'] ?>' name='City' /></span>
		</div>
		<div class='alt-loc-item'>
			<span>State:</span>
			<span><input type='text' value='<?=$Park_data['Province'] ?>' name='Province' /></span>
		</div>
		<div class='alt-loc-item'>
			<span>Zip Code:</span>
			<span><input type='text' value='<?=$Park_data['PostalCode'] ?>' name='PostalCode' /></span>
		</div>
		<div class='alt-loc-item'>
			<span>Map Url:</span>
			<span><input type='text' value='<?=$Park_data['MapUrl'] ?>' name='MapUrl' /></span>
		</div>
		<div>
			<span>Information Url:</span>
			<span><input type='text' value='<?=$Park_data['Url'] ?>' name='Url' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Add Park Day' name='EditPark' /></span>
		</div>
	</form></div>

<div class='info-container'>
	<h3>Park Days</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>When</th>
				<th>Time</th>
				<th>Type</th>
				<th>Description</th>
				<th>Address</th>
				<th>Location Url</th>
				<th>Location</th>
				<th>Map Url</th>
				<th class='deletion'>&times;</th>
			</tr>
		</thead>
		<tbody>
<?php if (count($Park_days['ParkDays']) > 0) : ?>
<?php foreach($Park_days['ParkDays'] as $key => $day) : ?>
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
				<td><?=$day['Time'] ?></td>
				<td>
<?php 
	switch($day['Purpose']) { 
		case 'park-day': echo 'Regular Park Day'; break; 
		case 'fighter-practice': echo 'Fighter Practice'; break; 
		case 'arts-day': echo 'A&amp;S Day'; break; 
		case 'other': echo 'Other'; break; 
	} 
?>
				</td>
				<td><?=$day['Description'] ?></td>
				<td><?=$day['Address'] ?></td>
				<td><a href='<?=$day['LocationUrl'] ?>' target='_new'><?=trimlen($day['LocationUrl'])>0?'Location Url':'' ?></a></td>
				<td style='max-width: 20vw; overflow: hidden;'><?=$day['Location'] ?></td>
				<td><a href='<?=$day['MapUrl'] ?>' target='_new'><?=trimlen($day['MapUrl'])>0?'Map Url':'' ?></a></td>
				<td class='deletion'><a href='<?=UIR . 'Admin/editpark/' . $ParkId . '&Action=delete&ParkDayId=' . $day['ParkDayId'] ?>'>&times;</td>
			</tr>
<?php endforeach; ?>
<?php endif ; ?>
		</tbody>
	</table>
</div>

