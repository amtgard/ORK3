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

	function vacateConfirm(href, role) {
		var overlay = document.getElementById('vacate-confirm-overlay');
		document.getElementById('vacate-confirm-role').textContent = role;
		document.getElementById('vacate-confirm-ok').onclick = function() {
			window.location.href = href;
		};
		overlay.style.display = 'flex';
	}
</script>

<style>
#vacate-confirm-overlay {
	display: none; position: fixed; inset: 0; z-index: 9999;
	background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
}
#vacate-confirm-box {
	background: #fff; border-radius: 8px; padding: 28px 32px; max-width: 380px; width: 90%;
	box-shadow: 0 8px 32px rgba(0,0,0,0.18);
}
#vacate-confirm-box h4 { margin: 0 0 10px; font-size: 17px; color: #1a202c; }
#vacate-confirm-box p  { margin: 0 0 20px; font-size: 14px; color: #4a5568; }
.vacate-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
.vacate-confirm-actions button {
	padding: 7px 18px; border-radius: 5px; border: 1px solid #cbd5e0;
	font-size: 14px; cursor: pointer; background: #fff; color: #2d3748;
}
.vacate-confirm-actions button.vacate-ok {
	background: #c53030; color: #fff; border-color: #c53030;
}
</style>

<div id="vacate-confirm-overlay">
	<div id="vacate-confirm-box">
		<h4>Vacate Position?</h4>
		<p>Are you sure you want to vacate the <strong id="vacate-confirm-role"></strong> position?</p>
		<div class="vacate-confirm-actions">
			<button onclick="document.getElementById('vacate-confirm-overlay').style.display='none'">Cancel</button>
			<button class="vacate-ok" id="vacate-confirm-ok">Set Vacant</button>
		</div>
	</div>
</div>

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
					<td><a href='<?=UIR.'Player/profile/'.$auth['MundaneId'] ?>'><?=$auth['Persona'] . (($auth['Surname'] || $auth['GivenName']) ? ' (' . $auth['Surname'] . ', ' . $auth['GivenName'] . ')':'') ?></a></td>
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
						<a href='#' onclick="vacateConfirm('<?=UIR ?>Admin/<?=$vacateAction ?>&<?=$Type ?>=<?=$Id ?>&Role=<?=urlencode($auth['OfficerRole']) ?>', '<?=htmlspecialchars(addslashes($auth['OfficerRole'])) ?>'); return false;">Set Vacant</a>
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
