<script type='text/javascript'>
	function EditEvent(id) {
		$('#CancelButton').show();
		$.getJSON(
			"<?=HTTP_SERVICE ?>Search/SearchService.php",
			{
				Action: 'Search/CalendarDetail',
				event_calendardetail_id: id
			},
			function( data ) {
				$('#event-editor input[name="StartDate"]').val(data['EventStart']);
				$('#event-editor input[name="EndDate"]').val(data['EventEnd']);
				$('#event-editor input[name="Price"]').val(data['Price']);
				$('#event-editor input[name="Url"]').val(data['Url']);
				$('#event-editor input[name="UrlName"]').val(data['UrlName']);
				$('#event-editor input[name="Address"]').val(data['Address']);
				$('#event-editor input[name="City"]').val(data['City']);
				$('#event-editor input[name="State"]').val(data['Province']);
				$('#event-editor input[name="Zip"]').val(data['PostalCode']);
				$('#event-editor input[name="MapUrl"]').val(data['MapUrl']);
				$('#event-editor input[name="MapUrlName"]').val(data['MapUrlName']);
				$('#event-editor input[name="EventCalendarDetailId"]').val(id);
				$('#event-editor textarea[name="Description"]').val(urldecode(data['Description']));
				$('#event-editor form').attr('action','<?=UIR ?>Admin/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/edit');
				$('#CreateTournament').attr('href','<?=UIR ?>Tournament/create&EventCalendarDetailId=' + id);
				data['Current']==1?$('#event-editor input[name="Current"]').attr("checked","checked"):$('#event-editor input[name="Current"]').attr("checked",false);
			}
		);
	}

	$(document).ready(function() {
		/*
		$("textarea.wysiwyg-editor").sceditorBBCodePlugin({
			toolbar: "bold,italic,underline,subscript,superscript|left,center,right,justify|name,fontsize,fontcolor,removeformatting",
			style: "<?=HTTP_TEMPLATE;?>default/script/js/SCEditor/jquery.sceditor.default.css"
		});
		*/
		EditEvent($('.current-calendar-event').attr('calendar_event_id'));
		$('#CancelButton').hide();
		$('#CancelButton').click(function() {
			$('#event-editor input[name="StartDate"]').val('');
			$('#event-editor input[name="EndDate"]').val('');
			$('#event-editor input[name="Price"]').val('');
			$('#event-editor input[name="Url"]').val('');
			$('#event-editor input[name="UrlName"]').val('');
			$('#event-editor input[name="Address"]').val('');
			$('#event-editor input[name="City"]').val('');
			$('#event-editor input[name="State"]').val('');
			$('#event-editor input[name="Zip"]').val('');
			$('#event-editor input[name="MapUrl"]').val('');
			$('#event-editor input[name="MapUrlName"]').val('');
			$('#event-editor input[name="Current"]').attr("checked",false);
			$('#event-editor input[name="EventCalendarDetailId"]').val(0);
			$('#event-editor textarea[name="Description"]').val('');
			$('#event-editor form').attr('action','<?=UIR ?>Admin/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/new');
			$('#CreateTournament').attr('href','');
			$('#CancelButton').hide();
		});
		$('#StartDate').datetimepicker({ dateFormat: "yy-mm-dd", showMinute: false });
		$('#EndDate').datetimepicker({ dateFormat: "yy-mm-dd", showMinute: false });
		$( "#KingdomName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Kingdom',
						name: request.term
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Name, value: val.KingdomId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#KingdomName', ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel('#KingdomName', ui);
				$('#KingdomId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#KingdomName',null);
					$('#KingdomId').val(null);
				}
				return false;
			},
			minLength: 0
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
    
		$( "#ParkName" ).autocomplete({
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
				return showLabel('#ParkName', ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel('#ParkName', ui);
				$('#ParkId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#ParkName',null);
					$('#ParkId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});

    $( "#Persona" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				kingdom_id = $('#KingdomId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						park_id: park_id,
						kingdom_id: kingdom_id
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Persona, value: val.MundaneId + "|" + val.PenaltyBox });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#Persona', ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel('#Persona', ui);
				$('#MundaneId').val(ui.item.value.split("|")[0]);
				if (ui.item.value.split("|")[1] == "0") {
					$('input[name=Ban]:eq(0)').attr('checked', 'checked');
				} else {
					$('input[name=Ban]:eq(1)').attr('checked', 'checked');
				}
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#Persona',null);
					$('#MundaneId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
	});
</script>
<style type='text/css'>
	.sceditor-container {
		margin: 3px 0px 3px 10px;
	}
	.form-container button {
		margin: 3px 0px 3px 10px;
		float: right;
	}
	.past-calendar-event td {
		background-color: #ddd;
		color: #888;
	}
	.past-calendar-event td a {
		color: #666;
	}
</style>

<div class='info-container'>
	<h3><?=$EventDetails['Name'] ?></h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/update' enctype='multipart/form-data'>
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$EventDetails['HeraldryUrl']['Url'] . '?t=' . time() ?>' />
				<input type='file' class='restricted-image-type' name='Heraldry' id='Heraldry' />
			</span>
		</div>
    <div><div><b>Location</b></div></div>
		<div>
			<span>Kingdom</span>
			<span><input type='text' value='<?=isset($Admin_event['KingdomName'])?$Admin_event['KingdomName']:$EventDetails['EventInfo'][0]['KingdomName'] ?>' name='KingdomName' id='KingdomName' /></span>
		</div>
		<div>
			<span>Park</span>
			<span><input type='text' value='<?=isset($Admin_event['ParkName'])?$Admin_event['ParkName']:$EventDetails['EventInfo'][0]['ParkName'] ?>' name='ParkName' id='ParkName' /></span>
		</div>
		<div>
			<span>Player</span>
			<span><input type='text' value='<?=isset($Admin_event['PlayerName'])?$Admin_event['PlayerName']:$EventDetails['EventInfo'][0]['Persona'] ?>' name='Persona' id='Persona' /></span>
		</div>
		<div>
			<span>Group</span>
			<span><input type='text' value='<?=htmlentities(isset($Admin_event['UnitName'])?$Admin_event['UnitName']:$EventDetails['EventInfo'][0]['UnitName'], ENT_QUOTES) ?>' name='UnitName' id='UnitName' /></span>
		</div>
		<div>
			<span>Title</span>
			<span><input type='text' class='required-field' class='name-field' value='<?=htmlentities(isset($Admin_event['Name'])?$Admin_event['Name']:$EventDetails['EventInfo'][0]['Name'], ENT_QUOTES) ?>' name='Name' id='Name' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Update' /></td></span>
		</div>
		<input type='hidden' name='KingdomId' value='<?=$Admin_event['KingdomId'] ?>' id='KingdomId' />
		<input type='hidden' name='ParkId' value='<?=$Admin_event['ParkId'] ?>' id='ParkId' />
		<input type='hidden' name='MundaneId' value='<?=$Admin_event['MundaneId'] ?>' id='MundaneId' />
		<input type='hidden' name='UnitId' value='<?=$Admin_event['UnitId'] ?>' id='UnitId' />
	</form>
	<h3>Details</h3>
	<ul>
		<li><a href='<?=UIR ?>Reports/attendance/Event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/All'>Attendance</a></li>
	</ul>
</div>

<div class='info-container' id='event-editor'>
	<h3>Schedule a Date &mdash; Add or Edit Scheduled Instance</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/new'>
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

<div class='info-container'>
	<h3>Scheduled Dates &mdash; <?=$EventDetails['Name'] ?></h3>
	<table class='information-table action-table' id='EventListTable'>
		<thead>
			<tr>
				<th>Price</th>
				<th>Date</th>
				<th>Website</th>
				<th>Location</th>
				<th>Map</th>
				<th>Current</th>
				<th>Tournament</th>
				<th>Attendance</th>
				<th class='deletion'>&times;</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($EventDetails['CalendarEventDetails'])) $EventDetails['CalendarEventDetails'] = array(); ?>
<?php foreach ($EventDetails['CalendarEventDetails'] as $key => $detail) : ?>
			<tr onClick='javascript:EditEvent(<?=$detail['EventCalendarDetailId'] ?>)' class='<?=$detail['Current']=='1'?'current-calendar-event':'past-calendar-event' ?>' calendar_event_id='<?=$detail['EventCalendarDetailId'] ?>'>
				<td><?=$detail['Price'] ?></td>
				<td><?=date('Y-m-d, g A', strtotime($detail['EventStart'])) . '<br>' . date('Y-m-d, g A', strtotime($detail['EventEnd'])) ?></td>
				<td><?=$detail['UrlName'] ?> <a href='<?=$detail['Url'] ?>'>[ Link ]</a></td>
				<td>
					<?php
					$location = json_decode(stripslashes($detail['Location']));
					$location = ((isset($location->location))?$location->location:$location->bounds->northeast);
					$address = json_decode($detail['Geocode'], true);
					?>
					<a target='_new' href='http://maps.google.com/maps?z=14&t=m&q=loc:<?=$location->lat . "+" . $location->lng ?>'><?=$address['results'][0]['formatted_address']; ?></a>
				</td>
			<?php if (trimlen($detail['MapUrlName']) > 0) : ?>
				<td><?=$detail['MapUrlName'] ?> <a href='<?=$detail['MapUrl'] ?>'>[ Link ]</a></td>
			<?php else: ?>
				<td></td>
			<?php endif; ?>
				<td><?php if ($detail['Current'] == 1) : ?>Yes<?php else : ?>No<?php endif ?></td>
				<td><a href='<?=UIR ?>Tournament/index&EventCalendarDetailId=<?=$detail['EventCalendarDetailId'] ?>'>Tournaments</a></td>
				<td><a href='<?=UIR ?>Attendance/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/<?=$detail['EventCalendarDetailId'] ?>'>Attendance</a></td>
				<td class='deletion'><a href='<?=UIR ?>Admin/event/<?=$EventDetails['EventInfo'][0]['EventId'] ?>/delete&DetailId=<?=$detail['EventCalendarDetailId'] ?>'>&times;</a></td>
			</tr>
			<tr class='table-data-break'>
				<td colspan=9><?=nl2br($detail['Description']) ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

