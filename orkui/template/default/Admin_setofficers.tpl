<script type='text/javascript'>
	$(function() {
		$( ".officer-input" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						<?=($Type=='ParkId')?'park_id':'kingdom_id' ?>: <?=$Id ?>
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Persona, value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel($(this), ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel($(this), ui);
				thisid = '#' + $(this).attr('name') + 'Id';
				$( thisid ).val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel($(this),null);
					thisid = '#' + $(this).attr('name') + 'Id';
					$( thisid ).val(ui.item.value);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
	});
</script>

<?php if (!empty($Error)) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (!empty($Message)) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>
<div class='info-container'>
	<h3>Officers</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/<?=$Call ?>/post&<?=$Type ?>=<?=$Id ?>'>
		<table class='information-table'>
			<thead>
				<tr>
					<th>User</th>
					<th>Player</th>
					<th>Role</th>
					<th>Officer</th>
					<th>Set To</th>
					<th>Vacate</th>
				</tr>
			</thead>
			<tbody>
	<?php 	foreach ($Officers as $k => $auth): ?>
				<tr>
					<td><?=$auth['UserName'] ?></td>
					<td><a href='<?=UIR.'Player/index/'.$auth['MundaneId'] ?>'><?=$auth['Persona'] . (($auth['Surname'] || $auth['GivenName']) ? ' (' . $auth['Surname'] . ', ' . $auth['GivenName'] . ')':'') ?></a></td>
					<td><?=$auth['Role'] ?></td>
<?php if ($Type == 'KingdomId' && $Id == 34) : ?>
					<td>Champion</td>
<?php else : ?>
					<td><?=$auth['OfficerRole'] ?></td>
<?php endif; ?>
					<td><input type='text' class='officer-input' value='<?=$Award_setofficers[str_replace(' ', '_',$auth['OfficerRole'])] ?>' name='<?=str_replace(' ', '_',$auth['OfficerRole']) ?>' value='<?=str_replace(' ', '_',$auth['OfficerRole']) ?>'></td>
					<input type='hidden' id='<?=str_replace(' ', '_',$auth['OfficerRole']) ?>Id' name='<?=str_replace(' ', '_',$auth['OfficerRole']) ?>Id' value='<?=$Award_setofficers[str_replace(' ', '_',$auth['OfficerRole']).'Id'] ?>' />
					<td>
<?php if (!empty($auth['MundaneId']) && $auth['MundaneId'] > 0): ?>
						<?php $vacateAction = ($Type == 'KingdomId') ? 'vacatekingdomofficer' : 'vacateparkofficer'; ?>
						<a href='<?=UIR ?>Admin/<?=$vacateAction ?>&<?=$Type ?>=<?=$Id ?>&Role=<?=urlencode($auth['OfficerRole']) ?>' onclick="return confirm('Are you sure you want to vacate the <?=$auth['OfficerRole'] ?> position?');">Set Vacant</a>
<?php endif; ?>
					</td>
				</tr>
	<?php 	endforeach; ?>
				<tr>
					<td colspan='6'><input type='submit' value='Update' /></td>
				</tr>
			</tbody>
		</table>
	</form>
</div>
