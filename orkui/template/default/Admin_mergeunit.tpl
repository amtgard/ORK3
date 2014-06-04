<script type='text/javascript'>
	$(function() {
		$( "#FromUnit" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Unit',
						name: request.term,
						limit: 6
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Name, value: val.UnitId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#FromUnit', ui);
			}, 
			delay: 50,
			select: function (e, ui) {
				showLabel('#FromUnit', ui);
				$('#FromUnitId').val(ui.item.value);
				return false;
			}
		});
		$( "#ToUnit" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
    					Action: 'Search/Unit',
						name: request.term,
						limit: 6
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Name, value: val.UnitId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#ToUnit', ui);
			}, 
			delay: 50,
			select: function (e, ui) {
				showLabel('#ToUnit', ui);
				$('#ToUnitId').val(ui.item.value);
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Merge Units</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	<form class='form-container' method='post' action='<?=UIR ?>Admin/mergeunit/submit'>
		<div>
			<span>From Unit:</span>
			<span><input type='text' value='<?=$Admin_mergeunit['FromUnit'] ?>' name='FromUnit' id='FromUnit' /></span>
		</div>
		<div>
			<span>To Unit:</span>
			<span><input type='text' class='required-field' value='<?=$Admin_mergeunit['ToUnit'] ?>' name='ToUnit' id='ToUnit' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Merge Unit' name='Merge Unit' /></span>
		</div>
		<input type='hidden' name='FromUnitId' id='FromUnitId' value='<?=$Admin_mergeunit['FromUnitId'] ?>' />
		<input type='hidden' name='ToUnitId' id='ToUnitId' value='<?=$Admin_mergeunit['ToUnitId'] ?>' />
	</form>
</div>
