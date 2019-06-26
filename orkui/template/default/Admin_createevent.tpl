<script type='text/javascript'>
	$(document).ready(function() {
		$('#StartDate').datetimepicker({ dateFormat: "yy-mm-dd", showMinute: false });
		$('#EndDate').datetimepicker({ dateFormat: "yy-mm-dd", showMinute: false });
		$('#event-creation-form').submit(function() {
			$('#event-creation-form').attr('action', '<?=UIR ?>Admin/event/' + $('select[name=event_id]').val() + '/new');
		});
    
		$( "#AtParkName" ).autocomplete({
			source: function( request, response ) {
				kingdom_id = $('#KingdomId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term,
						kingdom_id: kingdom_id
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Name, value: val.ParkId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#AtParkName', ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel('#AtParkName', ui);
				$('#AtParkId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#AtParkName',null);
					$('#AtParkId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
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
			<span>Also:</span>
			<span>
        <input type='text' value="<?=htmlentities(isset($Admin_event['AtParkName'])?$Admin_event['AtParkName']:$this->__session->park_name) ?>" name='AtParkName' id='AtParkName' />
        <input type='hidden' name='AtParkId' value='<?=isset($Admin_event['AtParkId'])?$Admin_event['AtParkId']:$this->__session->park_id ?>' id='AtParkId' />
        <input type='hidden' name='KingdomId' value='<?=$this->__session->kingdom_id ?>' id='KingdomId' />
        <input type='hidden' name='ParkId' value='<?=$this->__session->kingdom_id ?>' id='ParkId' />
        <input type='hidden' name='MundaneId' value='<?=$Admin_event['MundaneId'] ?>' id='MundaneId' />
        <input type='hidden' name='UnitId' value='<?=$Admin_event['UnitId'] ?>' id='UnitId' />
      </span>
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