<?php $__h = function($v, $double = true){ return is_scalar($v) ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8', $double) : ''; }; ?>
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
				OrkPlayerSearch.reattach(document.getElementById('FromPlayerName'), { parkId: parseInt(ui.item.value, 10) || 0 });
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
				OrkPlayerSearch.reattach(document.getElementById('ToPlayerName'), { parkId: parseInt(ui.item.value, 10) || 0 });
				return false;
			}
		});
		OrkPlayerSearch.attach(document.getElementById('FromPlayerName'), {
			uir: '<?=UIR ?>',
			parkId: <?=valid_id($ParkId) ? (int)$ParkId : 0 ?>,
			kingdomId: <?=valid_id($KingdomId) ? (int)$KingdomId : 0 ?>,
			includeInactive: true,
			excludeIds: function() { return [parseInt(document.getElementById('ToMundaneId').value) || 0]; },
			onSelect: function(p) {
				document.getElementById('FromMundaneId').value = p.MundaneId;
			},
			onClear: function() {
				document.getElementById('FromMundaneId').value = '';
			}
		});
		OrkPlayerSearch.attach(document.getElementById('ToPlayerName'), {
			uir: '<?=UIR ?>',
			parkId: <?=valid_id($ParkId) ? (int)$ParkId : 0 ?>,
			kingdomId: <?=valid_id($KingdomId) ? (int)$KingdomId : 0 ?>,
			includeInactive: true,
			excludeIds: function() { return [parseInt(document.getElementById('FromMundaneId').value) || 0]; },
			onSelect: function(p) {
				document.getElementById('ToMundaneId').value = p.MundaneId;
			},
			onClear: function() {
				document.getElementById('ToMundaneId').value = '';
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
			<span><input type='text' value='<?=$__h($Admin_mergeplayer['FromParkName'] ?? '') ?>' name='FromParkName' id='FromParkName' /></span>
		</div>
<?php endif ; ?>
		<div>
			<span>From Player (this player will be destroyed):</span>
			<span><input type='text' value='<?=$__h($Admin_mergeplayer['FromPlayerName'] ?? '') ?>' name='FromPlayerName' id='FromPlayerName' /></span>
		</div>
<?php if (! valid_id($ParkId)) : ?>
		<div>
			<span>To Park:</span>
			<span><input type='text' class='required-field' value='<?=$__h($Admin_mergeplayer['ToParkName'] ?? '') ?>' name='ToParkName' id='ToParkName' /></span>
		</div>
<?php endif ; ?>
		<div>
			<span>To Player:</span>
			<span><input type='text' class='required-field' value='<?=$__h($Admin_mergeplayer['ToPlayerName'] ?? '') ?>' name='ToPlayerName' id='ToPlayerName' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Merge Player' name='Merge Player' /></span>
		</div>
		<input type='hidden' name='FromMundaneId' id='FromMundaneId' value='<?=$__h($Admin_mergeplayer['FromMundaneId'] ?? '') ?>' />
		<input type='hidden' name='FromParkId' id='FromParkId' value='<?=$__h(valid_id($ParkId)?$ParkId:$Admin_mergeplayer['FromParkId']) ?>' />
		<input type='hidden' name='ToMundaneId' id='ToMundaneId' value='<?=$__h($Admin_mergeplayer['ToMundaneId'] ?? '') ?>' />
		<input type='hidden' name='ToParkId' id='ToParkId' value='<?=$__h(valid_id($ParkId)?$ParkId:$Admin_mergeplayer['ToParkId']) ?>' />
	</form>
</div>
