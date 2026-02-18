<?php global $Session ?>

<style>
.ui-autocomplete-separator { padding: 2px 12px; cursor: default; pointer-events: none; color: #999; font-size: 11px; }
</style>

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
		// Quick Add row submit
		$('#quick-add-table').on('click', '.qa-add-btn', function() {
			var row = $(this).closest('tr');
			$('#qa-date').val($('#AttendanceDate').val());
			$('#qa-mundane-id').val(row.data('mundane-id'));
			$('#qa-kingdom-id').val(row.data('kingdom-id'));
			$('#qa-kingdom-name').val(row.data('kingdom-name'));
			$('#qa-park-id').val(row.data('park-id'));
			$('#qa-park-name').val(row.data('park-name'));
			$('#qa-player-name').val(row.data('persona'));
			$('#qa-class-id').val(row.find('.qa-class').val());
			$('#qa-credits').val(row.find('.qa-credits').val());
			$('#quick-add-form').submit();
		});

		var playerAC = $( "#PlayerName" ).autocomplete({
			source: function( request, response ) {
				var park_id = $('#ParkId').val();
				var kingdom_id = $('#KingdomId').val();
				var search = request.term;
				var svcUrl = "<?=HTTP_SERVICE ?>Search/SearchService.php";

				if (!park_id || park_id == '0') {
					$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, kingdom_id: kingdom_id, limit: 15 }, function(data) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Persona, value: { MundaneId: val.MundaneId, PenaltyBox: val.PenaltyBox }});
						});
						response(suggestions);
					});
					return;
				}

				$.when(
					$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, park_id: park_id, kingdom_id: kingdom_id, limit: 8 }),
					$.getJSON(svcUrl, { Action: 'Search/Player', type: 'all', search: search, kingdom_id: kingdom_id, limit: 15 })
				).done(function(parkRes, kingdomRes) {
					var localIds = {};
					var suggestions = [];
					$.each(parkRes[0], function(i, val) {
						localIds[val.MundaneId] = true;
						suggestions.push({label: val.Persona, value: { MundaneId: val.MundaneId, PenaltyBox: val.PenaltyBox }});
					});
					var outsiders = [];
					$.each(kingdomRes[0], function(i, val) {
						if (!localIds[val.MundaneId]) {
							var abbr = (val.KAbbr && val.PAbbr) ? val.KAbbr + ':' + val.PAbbr : val.ParkName;
							outsiders.push({label: val.Persona + ' (' + abbr + ')', value: { MundaneId: val.MundaneId, PenaltyBox: val.PenaltyBox }});
						}
					});
					if (suggestions.length > 0 && outsiders.length > 0) {
						suggestions.push({label: '', value: null, separator: true});
					}
					response(suggestions.concat(outsiders));
				});
			},
			focus: function( event, ui ) {
				if (!ui.item.value) return false;
				return showLabel('#PlayerName', ui);
			},
			delay: 250,
			select: function (e, ui) {
				if (!ui.item.value) return false;
				showLabel('#PlayerName', ui);
				$('#MundaneId').val(ui.item.value.MundaneId);
				if (ui.item.value.PenaltyBox == "0") {
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
		playerAC.data('autocomplete')._renderItem = function(ul, item) {
			if (item.separator) {
				return $('<li class="ui-autocomplete-separator">').text('── Kingdom ──').appendTo(ul);
			}
			return $('<li></li>').data('item.autocomplete', item).append($('<a>').text(item.label)).appendTo(ul);
		};
	});
</script>
<div class='info-container' id='event-editor'>
	<h3>Add Attendance to <?=$Session->park_name ?></h3>
	<form class='form-container' method='post' action='<?=UIR ?>Attendance/park/<?=$Id ?>/new'>
		<div>
			<span>Date:</span>
			<span><input type='text' class='required-field' value='<?=trimlen($Attendance_index['AttendanceDate'])?$Attendance_index['AttendanceDate']:$AttendanceDate ?>' name='AttendanceDate' id='AttendanceDate' /></span>
		</div>
		<div>
			<span>Player's Kingdom:</span>
			<span><input type='text' class='required-field' value='<?=trimlen($Attendance_index['KingdomName'])?$Attendance_index['KingdomName']:$DefaultKingdomName ?>' name='KingdomName' id='KingdomName' /></span>
		</div>
		<div>
			<span>Player's Park:</span>
			<span><input type='text' class='required-field' value="<?=html_encode(trimlen($Attendance_index['ParkName'])?$Attendance_index['ParkName']:$DefaultParkName) ?>" name='ParkName' id='ParkName' /></span>
		</div>
		<div>
			<span>Player:</span>
			<span><input type='text' class='required-field' value="<?=html_encode($Attendance_index['PlayerName']) ?>" name='PlayerName' id='PlayerName' /></span>
		</div>
		<div>
			<span>Class:</span>
			<span>
				<select name='ClassId' id='ClassId' class='required-field'>
					<option value=''>-select one-</option>
<?php foreach ($Classes['Classes'] as $k => $class) : ?>
					<option value='<?=$class['ClassId'] ?>'><?=$class['Name'] ?></option>
<?php endforeach ?>
				</select>
			</span>
		</div>
		<div>
			<span>Credits:</span>
			<span><input type='text' class='required-field numeric-field remove-float' value='<?=valid_id($Attendance_index['Credits'])?$Attendance_index['Credits']:$DefaultCredits ?>' name='Credits' id='Credits' /></span>
		</div>
		<div>
			<span></span>
<?php if ($LoggedIn) : ?>
			<span><input value='Add' type='submit' /></span>
<?php endif ; ?>
		</div>
		<input type='hidden' id='KingdomId' name='KingdomId' value='<?=valid_id($Attendance_index['KingdomId'])?$Attendance_index['KingdomId']:$DefaultKingdomId ?>' />
		<input type='hidden' id='ParkId' name='ParkId' value='<?=valid_id($Attendance_index['ParkId'])?$Attendance_index['ParkId']:$DefaultParkId ?>' />
		<input type='hidden' id='MundaneId' name='MundaneId' value='<?=$Attendance_index['MundaneId'] ?>' />
	</form>
</div>

<div class='info-container'>
	<h3><?=$AttendanceDate ?></h3>
	<table class='information-table form-container' id='EventListTable'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Player</th>
				<th>Home Park</th>
				<th>Class</th>
				<th>Credits</th>
				<th>Entered By</th>
				<th class='deletion'>&times;</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($AttendanceReport['Attendance'])) $AttendanceReport['Attendance'] = array(); ?>
<?php foreach ($AttendanceReport['Attendance'] as $key => $detail) : ?>
			<tr>
				<td><a href='<?=UIR ?>Kingdom/index/<?=$detail['KingdomId'] ?>'><?=$detail['KingdomName'] ?></a></td>
				<td><a href='<?=UIR ?>Park/index/<?=$detail['ParkId'] ?>'><?=$detail['ParkName'] ?></a></td>
    <?php if ($detail['MundaneId']==0) : ?>
				<td class='form-informational-field'><?=$detail['AttendancePersona'] ?> (<?=$detail['Note'] ?>)</td>
				<td></td>
    <?php else : ?>
    			<td><a href='<?=UIR ?>Player/index/<?=$detail['MundaneId'] ?>'><?=$detail['Persona'] ?></a></td>
				<td><a href='<?=UIR ?>Park/index/<?=$detail['FromParkId'] ?>'><?=html_encode($detail['FromParkName']) ?></a></td>
    <?php endif ; ?>
				<td><?=strlen($detail['Flavor'])>0?$detail['Flavor']:$detail['ClassName'] ?></td>
				<td class='data-column'><?=$detail['Credits'] ?></td>
				<td class='data-column'><a href="<?=UIR.'Player/index/'.$detail['EnteredById'] ?>"><?=$detail['EnteredBy'] ?></a></td>
	<?php if ($LoggedIn) : ?>
				<td class='deletion'><a href='<?=UIR ?>Attendance/park/<?=$Id ?>/delete/<?=$detail['AttendanceId'] ?>&AttendanceDate=<?=$AttendanceDate ?>'>&times;</a></td>
	<?php endif ; ?>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<?php if ($LoggedIn && !empty($RecentAttendees['Attendees'])) : ?>
<form id='quick-add-form' method='post' action='<?=UIR ?>Attendance/park/<?=$Id ?>/new' style='display:none'>
	<input type='hidden' id='qa-date' name='AttendanceDate' />
	<input type='hidden' id='qa-kingdom-id' name='KingdomId' />
	<input type='hidden' id='qa-kingdom-name' name='KingdomName' />
	<input type='hidden' id='qa-park-id' name='ParkId' />
	<input type='hidden' id='qa-park-name' name='ParkName' />
	<input type='hidden' id='qa-mundane-id' name='MundaneId' />
	<input type='hidden' id='qa-player-name' name='PlayerName' />
	<input type='hidden' id='qa-class-id' name='ClassId' />
	<input type='hidden' id='qa-credits' name='Credits' />
</form>
<div class='info-container'>
	<h3>Quick Add &mdash; Recent Attendees</h3>
	<table class='information-table' id='quick-add-table'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Player</th>
				<th>Last Sign-In</th>
				<th>Class</th>
				<th>Credits</th>
				<th>Add</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($RecentAttendees['Attendees'] as $attendee) : ?>
			<tr	data-mundane-id='<?=$attendee['MundaneId'] ?>'
				data-kingdom-id='<?=$attendee['KingdomId'] ?>'
				data-kingdom-name='<?=html_encode($attendee['KingdomName']) ?>'
				data-park-id='<?=$attendee['ParkId'] ?>'
				data-park-name='<?=html_encode($attendee['ParkName']) ?>'
				data-persona='<?=html_encode($attendee['Persona']) ?>'>
				<td><a href='<?=UIR ?>Kingdom/index/<?=$attendee['KingdomId'] ?>'><?=html_encode($attendee['KingdomName']) ?></a></td>
				<td><a href='<?=UIR ?>Park/index/<?=$attendee['ParkId'] ?>'><?=html_encode($attendee['ParkName']) ?></a></td>
				<td><a href='<?=UIR ?>Player/index/<?=$attendee['MundaneId'] ?>'><?=html_encode($attendee['Persona']) ?></a></td>
				<td class='data-column'><?=$attendee['LastSignIn'] ?></td>
				<td>
					<select class='qa-class'>
<?php foreach ($Classes['Classes'] as $class) : ?>
						<option value='<?=$class['ClassId'] ?>'<?=$class['ClassId']==$attendee['ClassId']?' selected':'' ?>><?=$class['Name'] ?></option>
<?php endforeach ?>
					</select>
				</td>
				<td><input type='text' class='qa-credits numeric-field remove-float' value='1' size='3' /></td>
				<td><input type='button' class='qa-add-btn' value='Add' /></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>
