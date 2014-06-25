<script type='text/javascript'>
	$(document).ready(function() {
		$('#StartDate').datetimepicker({ dateFormat: "yy-mm-dd", showMinute: false });
		$('#EndDate').datetimepicker({ dateFormat: "yy-mm-dd", showMinute: false });
		$('#event-creation-form').submit(function() {
			$('#event-creation-form').attr('action', '<?=UIR ?>Admin/event/' + $('select[name=event_id]').val() + '/new');
		});
	});
</script>

<div class='info-container'>
	<h3>Create Event</h3>
	<form  id='event-creation-form' class='form-container' method='post' action='<?=UIR ?>Admin/event'>
		<div>
			<span>Template:</span>
			<select name='event_id'>
				<option '0'> Select a Template</option>
				<option '0'> </option>
<?php foreach ($Events_list as $k => $event) : ?>
				<option value='<?=$event['EventId'] ?>'><?=$event['Name'] ?></option>
<?php endforeach ; ?>
			</select>
		</div>
		<div>
			<span>Start:</span>
			<span><input type='text' value='<?=$Admin_event['StartDate'] ?>' name='StartDate' id='StartDate' /></span>
		</div>
		<div>
			<span>End:</span>
			<span><input type='text' value='<?=$Admin_event['EndDate'] ?>' name='EndDate' id='EndDate' /></span>
		</div>
		<div>
			<span>Price:</span>
			<span><input type='text' class='numeric-field remove-float' value='<?=$Admin_event['Price'] ?>' name='Price' /></span>
		</div>
		<div>
			<span>Website:</span>
			<span><input type='text' value='<?=$Admin_event['Url'] ?>' name='Url' /></span>
		</div>
		<div>
			<span>Website Name:</span>
			<span><input type='text' class='name-field' style='letter-spacing: 0px' value='<?=$Admin_event['UrlName'] ?>' name='UrlName' /></span>
		</div>
		<div>
			<span>Address:</span>
			<span><input type='text' value='<?=$Admin_event['Address'] ?>' name='Address' /></span>
		</div>
		<div>
			<span>City:</span>
			<span><input type='text' value='<?=$Admin_event['City'] ?>' name='City' /></span>
		</div>
		<div>
			<span>State:</span>
			<span>
				<select name="State">
					<option value="AL">Alabama</option>
					<option value="AK">Alaska</option>
					<option value="AZ">Arizona</option>
					<option value="AR">Arkansas</option>
					<option value="CA">California</option>
					<option value="CO">Colorado</option>
					<option value="CT">Connecticut</option>
					<option value="DE">Delaware</option>
					<option value="DC">District of Columbia</option>
					<option value="FL">Florida</option>
					<option value="GA">Georgia</option>
					<option value="HI">Hawaii</option>
					<option value="ID">Idaho</option>
					<option value="IL">Illinois</option>
					<option value="IN">Indiana</option>
					<option value="IA">Iowa</option>
					<option value="KS">Kansas</option>
					<option value="KY">Kentucky</option>
					<option value="LA">Louisiana</option>
					<option value="ME">Maine</option>
					<option value="MD">Maryland</option>
					<option value="MA">Massachusetts</option>
					<option value="MI">Michigan</option>
					<option value="MN">Minnesota</option>
					<option value="MS">Mississippi</option>
					<option value="MO">Missouri</option>
					<option value="MT">Montana</option>
					<option value="NE">Nebraska</option>
					<option value="NV">Nevada</option>
					<option value="NH">New Hampshire</option>
					<option value="NJ">New Jersey</option>
					<option value="NM">New Mexico</option>
					<option value="NY">New York</option>
					<option value="NC">North Carolina</option>
					<option value="ND">North Dakota</option>
					<option value="OH">Ohio</option>
					<option value="OK">Oklahoma</option>
					<option value="OR">Oregon</option>
					<option value="PA">Pennsylvania</option>
					<option value="RI">Rhode Island</option>
					<option value="SC">South Carolina</option>
					<option value="SD">South Dakota</option>
					<option value="TN">Tennessee</option>
					<option value="TX">Texas</option>
					<option value="UT">Utah</option>
					<option value="VT">Vermont</option>
					<option value="VA">Virginia</option>
					<option value="WA">Washington</option>
					<option value="WV">West Virginia</option>
					<option value="WI">Wisconsin</option>
					<option value="WY">Wyoming</option>
				</select>			
			</span>
		</div>
		<div>
			<span>Zip:</span>
			<span><input type='text' value='<?=$Admin_event['Zip'] ?>' name='Zip' /></span>
		</div>
		<div>
			<span>Map URL:</span>
			<span><input type='text' value='<?=$Admin_event['MapUrl'] ?>' name='MapUrl' /></span>
		</div>
		<div>
			<span>Map Name:</span>
			<span><input type='text' style='letter-spacing: 0px' value='<?=$Admin_event['MapUrlName'] ?>' name='MapUrlName' /></span>
		</div>
		<div>
			<span>Current:</span>
			<span><input type='checkbox' value='Yes' <?=$Admin_event['Current']==1?"Checked":"" ?> name='Current' /></span>
		</div>
		<div>
			<span>Description:</span>
			<div><textarea class='wysiwyg-editor' style='width: 400px; height: 200px; margin-left: 10px;' name='Description'><?=$Admin_event['Description'] ?></textarea></div>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Submit' /><button type='button' id='CancelButton'>Cancel</button></span>
		</div>
		<input type='hidden' name='EventCalendarDetailId' value='<?=$Admin_event['EventCalendarDetailId'] ?>' id='EventCalendarDetailId' ?>
	</form>
</div>