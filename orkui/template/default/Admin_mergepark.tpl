<script type='text/javascript'>
    $(function() {
		$( "#FromPark" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term,
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
				return showLabel('#FromPark', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#FromPark', ui);
				$('#FromParkId').val(ui.item.value);
				return false;
			}
		});
		$( "#ToPark" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
    					Action: 'Search/Park',
						name: request.term,
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
				return showLabel('#ToPark', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#ToPark', ui);
				$('#ToParkId').val(ui.item.value);
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Migrate Park Members</h3>
  This moves all of the members of the sending park to the recipient park, and closes out the permissions of the sending park.
  <p>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	<form class='form-container' method='post' action='<?=UIR ?>Admin/mergepark/submit'>
		<div>
			<span>From Park:</span>
			<span><input type='text' value='<?=$Admin_mergepark['FromPark'] ?>' name='FromPark' id='FromPark' /></span>
		</div>
		<div>
			<span>To Park:</span>
			<span><input type='text' class='required-field' value='<?=$Admin_mergepark['ToPark'] ?>' name='ToPark' id='ToPark' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Merge Park' name='MergePark' /></span>
		</div>
		<input type='hidden' name='FromParkId' id='FromParkId' value='<?=$Admin_mergepark['FromParkId'] ?>' />
		<input type='hidden' name='ToParkId' id='ToParkId' value='<?=$Admin_mergepark['ToParkId'] ?>' />
	</form>
</div>
