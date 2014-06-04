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
				$('form').attr('action','<?=UIR ?>Admin/transferpark/' + ui.item.value);
				$('h3').text('Move Park to ' + ui.item.label);
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Move Park to <?=$KingdomName ?></h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	
	<form class='form-container' method='post' action='<?=UIR ?>Admin/transferpark/<?=$KingdomId ?>'>
		<div>
			<span>Park:</span>
			<span><input type='text' value='<?=$Admin_transferpark['ParkName'] ?>' name='ParkName' id='ParkName' /></span>
		</div>
<?php if (!valid_id($KingdomId)) : ?>
		<div>
			<span>Move To Kingdom:</span>
			<span><input type='text' class='required-field' value='<?=$Admin_transferpark['KingdomName'] ?>' name='KingdomName' id='KingdomName' /></span>
		</div>
<?php endif; ?>
		<div>
			<span></span>
			<span><input type='submit' value='Transfer' name='Transfer' /></span>
		</div>
		<input type='hidden' name='ParkId' id='ParkId' value='<?=$Admin_moveplayer['ParkId'] ?>' />
	</form>
</div>
