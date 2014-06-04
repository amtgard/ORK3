<script type='text/javascript'>
	$(function() {
		$( "#SrcParkName" ).autocomplete({
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
				return showLabel('#SrcParkName', ui);
			}, 
			delay: 50,
			select: function (e, ui) {
				showLabel('#SrcParkName', ui);
				$('#SrcParkId').val(ui.item.value);
				return false;
			}
		});
		$( "#ParkName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term
						<?=valid_id($KingdomId)?", kingdom_id: $KingdomId":"" ?>
						<?=valid_id($ParkId)?", park_id: $ParkId":"" ?>
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
		$( "#PlayerName" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#SrcParkId').val();
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
							suggestions.push({label: val.Persona, value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#PlayerName', ui);
			}, 
			delay: 50,
			select: function (e, ui) {
				showLabel('#PlayerName', ui);
				$('#MundaneId').val(ui.item.value);
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Move Player</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	
	<form class='form-container' method='post' action='<?=UIR ?>Admin/moveplayer/submit'>
		<div>
			<span>Park:</span>
			<span><input type='text' value='<?=$Admin_moveplayer['SrcParkName'] ?>' name='SrcParkName' id='SrcParkName' /></span>
		</div>
		<div>
			<span>Player:</span>
			<span><input type='text' value='<?=$Admin_moveplayer['PlayerName'] ?>' name='PlayerName' id='PlayerName' /></span>
		</div>
<?php if (! valid_id($ParkId)) : ?>
		<div>
			<span>New Park:</span>
			<span><input type='text' class='required-field' value='<?=$Admin_moveplayer['ParkName'] ?>' name='ParkName' id='ParkName' /></span>
		</div>
<?php endif; ?>
		<div>
			<span></span>
			<span><input type='submit' value='Move Player' name='Move Player' /></span>
		</div>
		<input type='hidden' name='MundaneId' id='MundaneId' value='<?=$Admin_moveplayer['MundaneId'] ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=valid_id($ParkId)?$ParkId:$Admin_moveplayer['ParkId'] ?>' />
		<input type='hidden' name='SrcParkId' id='SrcParkId' value='<?=$Admin_moveplayer['SrcParkId'] ?>' />
	</form>
</div>
