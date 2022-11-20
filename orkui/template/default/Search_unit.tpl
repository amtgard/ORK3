<script type='text/javascript'>
	function UnitList( request, response ) {
		park_id = $('#ParkId').val();
		kingdom_id = $('#KingdomId').val();
		mundane_id = $('#MundaneId').val();
		$.getJSON(
			"<?=HTTP_SERVICE ?>Search/SearchService.php",
			{
				Action: 'Search/Unit',
				kingdom_id: kingdom_id,
				park_id: park_id,
				mundane_id: mundane_id,
				name: (request!=null?request.term.trim():''),
				limit: 15
			},
			function( data ) {
				$('#unit-list-table tbody').html('');
				$.each(data, function(i, val) {
					$('#unit-list-table tbody').append(
						"<tr onclick='javascript:window.location.href=\"<?=UIR ?>Unit/index/" + val.UnitId + "\"'>" +
							"<td>" + (val.Name!=null?val.Name:"") + "</td>" +
							"<td>" + (val.Type!=null?val.Type:"") + "</td>" +
						"</tr>");
				});
			}
		);
//		return response;
	}
	
	$(function() {
		$( "#UnitName" ).autocomplete({
			source: function( request, response ) {
				UnitList( request, response );
			},
			delay: 500,
			change: function (e, ui) {
				if (ui.item == null) {
					UnitList(null,null);
				}
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Search</h3>
	<form class='form-container'>
		<div>
			<span>Unit:</span>
			<span><input type='text' value='<?=$Admin_moveplayer['UnitName'] ?>' name='UnitName' id='UnitName' /></span>
		</div>
		<input type='hidden' name='KingdomId' id='KingdomId' value='<?=$KingdomId ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=$ParkId ?>' />
		<input type='hidden' name='MundaneId' id='MundaneId' value='<?=$MundaneId ?>' />
	</form>
</div>

<div class='info-container'>
	<h3>Units</h3>
	<table class='information-table action-table' id="unit-list-table">
		<thead>
			<tr>
				<th>Unit</th>
				<th>Type</th>
			</tr>
		</thead>
		<tbody>
		</tbody>
	</table>
</div>
