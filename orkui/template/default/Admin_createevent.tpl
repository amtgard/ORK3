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
			<span><input type='text' name="State" /></span>
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
			<span><input type='checkbox' value='Yes' checked='checked' name='Current' /></span>
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