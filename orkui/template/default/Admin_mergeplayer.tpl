<script type='text/javascript'>
	$(function() {
		$( "#FromParkName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term
						<?=valid_id($KingdomId)?", kingdom_id: $KingdomId":"" ?>
						<?=valid_id($ParkId)?", park_id: $ParkId":"" ?>,
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
				return showLabel('#FromParkName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#FromParkName', ui);
				$('#FromParkId').val(ui.item.value);
				return false;
			}
		});
		$( "#ToParkName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term
						<?=valid_id($KingdomId)?", kingdom_id: $KingdomId":"" ?>
						<?=valid_id($ParkId)?", park_id: $ParkId":"" ?>,
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
				return showLabel('#ToParkName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#ToParkName', ui);
				$('#ToParkId').val(ui.item.value);
				return false;
			}
		});
		$( "#FromPlayerName" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#FromParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						park_id: park_id.length>0?park_id:null,
						limit: 6
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
    						suggestions.push({label: ((val.Persona!=null||val.Persona.length>0)?val.Persona:"<i>No Persona</i>") + " (" + val.KAbbr + ":" + val.PAbbr + ")", value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#FromPlayerName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#FromPlayerName', ui);
				$('#FromMundaneId').val(ui.item.value);
				return false;
			}
		});
		$( "#ToPlayerName" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ToParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						park_id: park_id.length>0?park_id:null,
						limit: 6
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
    						suggestions.push({label: ((val.Persona!=null||val.Persona.length>0)?val.Persona:"<i>No Persona</i>") + " (" + val.KAbbr + ":" + val.PAbbr + ")", value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#ToPlayerName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#ToPlayerName', ui);
				$('#ToMundaneId').val(ui.item.value);
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Merge Players</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	<form class='form-container' method='post' action='<?=UIR ?>Admin/mergeplayer/submit'>
<?php if (! valid_id($ParkId)) : ?>
		<div>
			<span>From Park:</span>
			<span><input type='text' value='<?=$Admin_mergeplayer['FromParkName'] ?>' name='FromParkName' id='FromParkName' /></span>
		</div>
<?php endif ; ?>
		<div>
			<span>From Player (this player will be destroyed):</span>
			<span><input type='text' value='<?=$Admin_mergeplayer['FromPlayerName'] ?>' name='FromPlayerName' id='FromPlayerName' /></span>
		</div>
<?php if (! valid_id($ParkId)) : ?>
		<div>
			<span>To Park:</span>
			<span><input type='text' class='required-field' value='<?=$Admin_mergeplayer['ToParkName'] ?>' name='ToParkName' id='ToParkName' /></span>
		</div>
<?php endif ; ?>
		<div>
			<span>To Player:</span>
			<span><input type='text' class='required-field' value='<?=$Admin_mergeplayer['ToPlayerName'] ?>' name='ToPlayerName' id='ToPlayerName' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Merge Player' name='Merge Player' /></span>
		</div>
		<input type='hidden' name='FromMundaneId' id='FromMundaneId' value='<?=$Admin_mergeplayer['FromMundaneId'] ?>' />
		<input type='hidden' name='FromParkId' id='FromParkId' value='<?=valid_id($ParkId)?$ParkId:$Admin_mergeplayer['FromParkId'] ?>' />
		<input type='hidden' name='ToMundaneId' id='ToMundaneId' value='<?=$Admin_mergeplayer['ToMundaneId'] ?>' />
		<input type='hidden' name='ToParkId' id='ToParkId' value='<?=valid_id($ParkId)?$ParkId:$Admin_mergeplayer['ToParkId'] ?>' />
	</form>
</div>
