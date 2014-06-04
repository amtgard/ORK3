<script type='text/javascript'>
	$(function() {
		$( "#UserName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
                        limit: 15
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.UserName + ' (' + val.Persona + ' ' + val.Mundane + ')', value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				ui.item.label = ui.item.label.split(" (")[0];
				return showLabel('#UserName', ui);
			}, 
			delay: 50,
			select: function (e, ui) {
				ui.item.label = ui.item.label.split(" (")[0];
				showLabel('#UserName', ui);
				$('#MundaneId').val(ui.item.value);
				return false;
			}
		});
		$( "#AuthAsset" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: function() { 
							switch ($('#AuthType').val()) {
								case 'Park': return 'Search/Park';
								case 'Kingdom': return 'Search/Kingdom';
								case 'Event': return 'Search/Event';
								case 'Unit': return 'Search/Unit';
								default: return false;
							}
						},
						name: request.term
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							switch ($('#AuthType').val()) {
								case 'Park': suggestions.push({label: val.Name , value: val.ParkId }); break;
								case 'Kingdom': suggestions.push({label: val.Name , value: val.KingdomId }); break;
								case 'Event': suggestions.push({label: val.Name , value: val.EventId }); break;
								case 'Unit': suggestions.push({label: val.Name , value: val.UnitId }); break;
								default: return false;
							}
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				ui.item.label = ui.item.label;
				return showLabel('#AuthAsset', ui);
			}, 
			delay: 50,
			select: function (e, ui) {
				ui.item.label = ui.item.label;
				showLabel('#AuthAsset', ui);
				$('#Id').val(ui.item.value);
				return false;
			}
		});
		$('#AuthType').change(function() {
			switch ($(this).val()) {
				case 'Park':
					$('#AuthAssetRow span').filter(':first').text('Park');
					$('#AuthAsset').removeAttr('disabled');
					break;
				case 'Kingdom':
					$('#AuthAssetRow span').filter(':first').text('Kingdom');
					$('#AuthAsset').removeAttr('disabled');
					break;
				case 'Event':
					$('#AuthAssetRow span').filter(':first').text('Event');
					$('#AuthAsset').removeAttr('disabled');
					break;
				case 'Unit':
					$('#AuthAssetRow span').filter(':first').text('Unit');
					$('#AuthAsset').removeAttr('disabled');
					break;
				default:
					$('#AuthAssetRow span').filter(':first').text('Administrator');
					$('#AuthAsset').val('');
					$('#Id').val('');
					$('#AuthAsset').attr('disabled','disabled');
					break;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Authorizations</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>User</th>
<?php if (!isset($this->__session->kingdom_id) || $display_all) : ?>
				<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id) || $display_all) : ?>
				<th>Park</th>
<?php endif; ?>
<?php if (!isset($this->__session->unit_id) || $display_all) : ?>
				<th>Unit</th>
<?php endif; ?>
<?php if (!isset($this->__session->event_id) || $display_all) : ?>
				<th>Event</th>
<?php endif; ?>
				<th>Role</th>
				<th>Officer</th>
				<th class='deletion'>&times;</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($NonOfficerAuths)): ?>
<?php 	foreach ($NonOfficerAuths as $k => $auth): ?>
			<tr>
				<td><a href='<?=UIR.'Player/index/'.$auth['MundaneId'] ?>'><?=$auth['Persona'] . ' (' . ($auth['Restricted']?'<span class="restricted-player-display">Restricted</span>':$auth['Surname'].', '.$auth['GivenName']) ?>)</a></td>
<?php 		if (!isset($this->__session->kingdom_id) || $display_all) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$auth['KingdomId'] ?>'><?=$auth['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id) || $display_all) : ?>
				<td><a href='<?=UIR.'Park/index/'.$auth['ParkId'] ?>'><?=$auth['ParkName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->unit_id) || $display_all) : ?>
				<td><a href='<?=UIR.'Unit/index/'.$auth['UnitId'] ?>'><?=$auth['UnitName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->event_id) || $display_all) : ?>
				<td><a href='<?=UIR.'Event/index/'.$auth['EventId'] ?>'><?=$auth['EventName'] ?></a></td>
<?php 		endif; ?>
				<td><?=$auth['Role'] ?></td>
				<td><?=$auth['OfficerRole'] ?></td>
				<td class='deletion'><a href='<?=UIR ?>Admin/authorization/Remove&AuthorizationId=<?=$auth['AuthorizationId'] ?>'>&times;</a></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Add Authorization</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/authorization/Add'>
		<div>
			<span>User Name:</span>
			<span><input type='text' value='<?=$Admin_authorization['UserName'] ?>' id='UserName' name='UserName' /></span>
		</div>
		<div>
			<span>Type:</span>
			<span>
				<select name='AuthType' id='AuthType'>
					<option value='admin'>Select ...</option>
<?php	foreach ($AuthTypes as $type => $label): ?>
<?php		if ($type == $Admin_authorization['AuthType']): ?>
					<option value='<?=$type ?>' selected='true'><?=$label ?></option>
<?php		else: ?>
					<option value='<?=$type ?>'><?=$label ?></option>
<?php		endif; ?>
<?php	endforeach; ?>
				</select>
			</span>
		</div>
		<div id='AuthAssetRow'>
			<span>Select</span>
			<span><input type='text' value='<?=$Admin_authorization['AuthAsset'] ?>' id='AuthAsset' name='AuthAsset' /></span>
		</div>
		<div>
			<span>Role:</span>
			<span>
				<select name='AuthRole' id='AuthRole'>
					<option value='admin'>Select ...</option>
<?php	foreach ($AuthRoles as $role => $label): ?>
<?php		if ($role == $Admin_authorization['AuthRole']): ?>
					<option value='<?=$role ?>' selected='true'><?=$label ?></option>
<?php		else: ?>
					<option value='<?=$role ?>'><?=$label ?></option>
<?php		endif; ?>
<?php	endforeach; ?>
				</select>
			</span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Add Authorization' name='AddAuthorization' /></span>
		</div>
		<input type='hidden' value='<?=$Admin_authorization['MundaneId'] ?>' id='MundaneId' name='MundaneId' />
		<input type='hidden' value='<?=$Admin_authorization['Id'] ?>' id='Id' name='Id' />
	</form>
</div>