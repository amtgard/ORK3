<script type='text/javascript'>
	function PasswordsMatch() {
		color = $('#Password1').css('background-color');
		if ($('#Password1').val() != $('#Password2').val()) {
			$('#Password1').css('background-color','#FED');
			$('#Password2').css('background-color','#FED');
			$('#Password1').css('border-color','#844');
			$('#Password2').css('border-color','#844');
			$('#Password1').css('color','#855');
			$('#Password2').css('color','#855');
		} else if (color == "rgb(255, 238, 221)") {
			$('#Password1').css('background-color','#FFF8C0');
			$('#Password2').css('background-color','#FFF8C0');
			$('#Password1').css('border-color','#CCC');
			$('#Password2').css('border-color','#CCC');
			$('#Password1').css('color','#36B');
			$('#Password2').css('color','#36B');
		}
	}

	$(function() {
		$( "#ParkName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term,
						kingdom_id: <?=valid_id($KingdomId)?"$KingdomId":"$('#KingdomId').val()" ?>
						<?=valid_id($ParkId)?",park_id: $ParkId":"" ?>
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
			delay: 50,
			select: function (e, ui) {
				showLabel('#ParkName', ui);
				$('#ParkId').val(ui.item.value);
				return false;
			}
		});
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
			delay: 50,
			select: function (e, ui) {
				showLabel('#KingdomName', ui);
				$('#KingdomId').val(ui.item.value);
				return false;
			}
		});
		$('#Password1').blur(PasswordsMatch);
		$('#Password2').blur(PasswordsMatch);
	});
</script>

<div class='info-container'>
	<h3>Create Player</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	
	<form class='form-container' id='create-player-form' method='post' action='<?=UIR ?>Admin/createplayer/submit' enctype='multipart/form-data'>
<?php if (!valid_id($KingdomId)) : ?>
		<div>
			<span>Kingdom:</span>
			<span><input type='text' value='<?=$Admin_createplayer['KingdomName'] ?>' name='KingdomName' id='KingdomName' /></span>
		</div>
<?php endif; ?>
<?php if (!valid_id($ParkId)) : ?>
		<div>
			<span>Park:</span>
			<span><input type='text' value='<?=$Admin_createplayer['ParkName'] ?>' name='ParkName' id='ParkName' /></span>
		</div>
<?php endif; ?>
		<div>
			<span>Given Name:</span>
			<span><input type='text' class='name-field' value='<?=$Admin_createplayer['GivenName'] ?>' name='GivenName' /></span>
		</div>
		<div>
			<span>Surname:</span>
			<span><input type='text' class='name-field' value='<?=$Admin_createplayer['Surname'] ?>' name='Surname' /></span>
		</div>
		<div>
			<span>Persona Name:</span>
			<span><input type='text' class='name-field required-field' value='<?=$Admin_createplayer['Persona'] ?>' name='Persona' /></span>
		</div>
		<div>
			<span>Heraldry:</span>
			<span><input type='file' class='restricted-image-type' name='Heraldry' /></span>
		</div>
		<div>
			<span>Image:</span>
			<span><input type='file' class='restricted-image-type' name='PlayerImage' /></span>
		</div>
		<div>
			<span>Email:</span>
			<span><input type='text' class='most-emails-field' value='<?=$Admin_createplayer['Email'] ?>' name='Email' /></span>
		</div>
		<div>
			<span>UserName:</span>
			<span><input type='text' class='name-field required-field' value='<?=$Admin_createplayer['UserName'] ?>' name='UserName' /></span>
		</div>
		<div>
			<span>Password:</span>
			<span><input type='password' value='<?=$Admin_createplayer['Password'] ?>' name='Password' id='Password1' /></span>
		</div>
		<div>
			<span>Password (again):</span>
			<span><input type='password' value='<?=$Admin_createplayer['Password'] ?>' id='Password2' /></span>
		</div>
		<div>
			<span>Restricted:</span>
			<span><input type='radio' value='0' name='Retricted' id='NotRestricted' <?=$Admin_createplayer['Retricted']==0?"Checked":"" ?> /><label for="NotRestricted">Not Restricted </label>
			<input type='radio' value='1' name='Retricted' id='Restricted' <?=$Admin_createplayer['Retricted']==1?"Checked":"" ?> /><label for="Restricted">Restricted </label></span>
		</div>
		<div>
			<span>Waivered:</span>
			<span><input type='radio' value='0' name='Waivered' id='NotWaivered' <?=$Admin_createplayer['Waivered']==0?"Checked":"" ?> /><label for="NotWaivered">Not Waivered </label>
			<input type='radio' value='1' name='Waivered' id='Waivered' <?=$Admin_createplayer['Waivered']==1?"Checked":"" ?> /><label for="Waivered">Waivered </label></span>
		</div>
		<div>
			<span>Waiver:</span>
			<span><input type='file' class='restricted-document-type' name='Waiver' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Create Player' name='CreatePlayer' /></span>
		</div>
		<input type='hidden' name='KingdomId' id='KingdomId' value='<?=valid_id($Admin_createplayer['KingdomId'])?$Admin_createplayer['KingdomId']:$KingdomId ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=valid_id($Admin_createplayer['ParkId'])?$Admin_createplayer['ParkId']:$ParkId ?>' />
	</form>
</div>