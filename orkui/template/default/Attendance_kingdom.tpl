<?php global $Session ?>

<script type='text/javascript'>

	$(document).ready(function() {
<?php if (valid_id($Attendance_index['ClassId'])) : ?>
		$('#Class').val($Attendance_index['ClassId']);
<?php endif ?>
		$( '#AttendanceDate' ).datepicker({dateFormat: 'yy-mm-dd'});
		$( "#KingdomName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Kingdom',
						name: request.term,
                        limit: 6
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
			delay: 250,
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
						kingdom_id: kingdom_id,
                        limit: 6
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
			delay: 250,
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
		$( "#PlayerName" ).autocomplete({
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
						kingdom_id: kingdom_id,
                        limit: 15
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
				return showLabel('#PlayerName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#PlayerName', ui);
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
					showLabel('#PlayerName',null);
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
<div class='info-container' id='event-editor'>
	<h3>Add Attendance</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Attendance/park/<?=$Id ?>/new'>
		<div>
			<span>Date:</span>
			<span><input type='text' class='required-field' value='<?=trimlen($Attendance_index['AttendanceDate'])?$Attendance_index['AttendanceDate']:$AttendanceDate ?>' name='AttendanceDate' id='AttendanceDate' /></span>
		</div>
		<div>
			<span>Kingdom:</span>
			<span><input type='text' class='required-field' value='<?=trimlen($Attendance_index['KingdomName'])?$Attendance_index['KingdomName']:$Session->kingdom_name ?>' name='KingdomName' id='KingdomName' /></span>
		</div>
		<div>
			<span>Park:</span>
			<span><input type='text' class='required-field' value='<?=trimlen($Attendance_index['ParkName'])?$Attendance_index['ParkName']:$Session->park_name ?>' name='ParkName' id='ParkName' /></span>
		</div>
		<div>
			<span>Player:</span>
			<span><input type='text' class='required-field' value='<?=$Attendance_index['PlayerName'] ?>' name='PlayerName' id='PlayerName' /></span>
		</div>
		<div>
			<span>Class:</span>
			<span>
				<select name='ClassId' id='ClassId' class='required-field'>
					<option value=''>-select one-</option>
<? foreach ($Classes['Classes'] as $k => $class) : ?>
					<option value='<?=$class['ClassId'] ?>'><?=$class['Name'] ?></option>
<? endforeach ?>
				</select>
			</span>
		</div>
		<div>
			<span>Credits:</span>
			<span><input type='text' class='required-field numeric-field remove-float' value='<?=valid_id($Attendance_index['Credits'])?$Attendance_index['Credits']:$DefaultCredits ?>' name='Credits' id='Credits' /></span>
		</div>
		<div>
			<span></span>
			<span><input value='Add' type='submit' /></span>
		</div>
		<input type='hidden' id='KingdomId' name='KingdomId' value='<?=valid_id($Attendance_index['KingdomId'])?$Attendance_index['KingdomId']:$Session->kingdom_id ?>' />
		<input type='hidden' id='ParkId' name='ParkId' value='<?=valid_id($Attendance_index['ParkId'])?$Attendance_index['ParkId']:$Session->park_id ?>' />
		<input type='hidden' id='MundaneId' name='MundaneId' value='<?=$Attendance_index['MundaneId'] ?>' />
	</form>
</div>

<div class='info-container'>
	<h3><?=$AttendanceDate ?></h3>
	<table class='information-table' id='EventListTable'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Event</th>
				<th>Player</th>
				<!--<th colspan='2'>From</th>-->
				<th>Class</th>
				<th>Credits</th>
				<th class='deletion'>&times;</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($AttendanceReport['Attendance'])) $AttendanceReport['Attendance'] = array(); ?>
<?php foreach ($AttendanceReport['Attendance'] as $key => $detail) : ?>
			<tr>
				<td><a href='<?=UIR ?>Kingdom/index/<?=$detail['KingdomId'] ?>'><?=$detail['KingdomName'] ?></a></td>
				<td><a href='<?=UIR ?>Park/index/<?=$detail['ParkId'] ?>'><?=$detail['ParkName'] ?></a></td>
				<td><a href='<?=UIR ?>Event/index/<?=$detail['EventId'] ?>'><?=$detail['EventName'] ?></td>
				<td><a href='<?=UIR ?>Player/index/<?=$detail['MundaneId'] ?>'><?=$detail['Persona'] ?></a></td>
				<!--<td><a href='<?=UIR ?>Park/index/<?=$detail['FromParkId'] ?>'><?=$detail['FromParkName'] ?></a></td>
				<td><a href='<?=UIR ?>Kingdom/index/<?=$detail['FromKingdomId'] ?>'><?=$detail['FromKingdomName'] ?></a></td>-->
				<td><?=$detail['ClassName'] ?></td>
				<td class='data-column'><?=$detail['Credits'] ?></td>
				<td class='deletion'><a href='<?=UIR ?>Attendance/park/<?=$Id ?>/delete/<?=$detail['AttendanceId'] ?>'>&times;</a></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>
