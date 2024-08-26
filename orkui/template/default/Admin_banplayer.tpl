<script type='text/javascript'>
	$(function() {
		$( "#ParkName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term
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
			}
		});
		$( "#PlayerName" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						park_id: park_id.length>0?park_id:null
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
			delay: 500,
			select: function (e, ui) {
				showLabel('#PlayerName', ui);
				$('#MundaneId').val(ui.item.value.split("|")[0]);
				if (ui.item.value.split("|")[1] == "0") {
					$('input[name=Ban]:eq(0)').attr('checked', 'checked');
				} else {
					$('input[name=Ban]:eq(1)').attr('checked', 'checked');
				}
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Set Player Ban</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/banplayer/submit'>
		<div>
			<span>Park:</span>
			<span><input type='text' class='name-field' value='<?=$Admin_banplayer['ParkName'] ?>' name='ParkName' id='ParkName' /></span>
		</div>
		<div>
			<span>Player:</span>
			<span><input type='text' class='name-field' value='<?=$Admin_banplayer['PlayerName'] ?>' name='PlayerName' id='PlayerName' /></span>
		</div>
		<div>
			<span>Ban:</span>
			<span><input type='radio' value='0' name='Ban' id='NotBanned' <?=$Admin_banplayer['Ban']==0?"Checked":"" ?> /><label for="NotBanned">Not Banned</label>
			<input type='radio' value='1' name='Ban' id='Banned' <?=$Admin_banplayer['Ban']==1?"Checked":"" ?> /><label for="Banned">Banned</label></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Set Ban' name='Set Ban' /></span>
		</div>
		<input type='hidden' name='MundaneId' id='MundaneId' value='<?=$Admin_banplayer['MundaneId'] ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=$Admin_banplayer['ParkId'] ?>' />
	</form>
</div>
<?php if (count($banned_players)>0): ?>
<div class='info-container'>
	<h3>Penalty Box</h3>
	<ul>
<?php foreach ($banned_players as $k => $info): ?>
<li><a href="<?=UIR.'Player/index/'.$info['MundaneId'] ?>"><?= $info['Persona']; ?></a></li>
<?php endforeach ?>
	</ul>
</div>
<?php endif; ?>
