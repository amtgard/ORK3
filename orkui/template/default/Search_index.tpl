<script type='text/javascript'>
	function PlayerList( request, response ) {
		park_id = $('#ParkId').val();
		kingdom_id = $('#KingdomId').val();
		mundane_id = $('#MundaneId').val();
		$.getJSON(
			"<?=HTTP_SERVICE ?>Search/SearchService.php",
			{
				Action: 'Search/Player',
				<?=valid_id($KingdomId)?"kingdom_id: $KingdomId,\n":"\n" ?>
				<?=valid_id($ParkId)?"park_id: $ParkId,\n":"\n" ?>
				search: (request!=null?request.term.trim():''),
				type: 'all',
				limit: 25
			},
			function( data ) {
				$('#player-list-table tbody').html('');
				$.each(data, function(i, val) {
					$('#player-list-table tbody').append(
						"<tr onclick='javascript:window.location.href=\"<?=UIR ?>Player/index/" + val.MundaneId + "\"'>" +
							"<td>" + (val.KingdomName!=null?val.KingdomName:"") + "</td>" +
							"<td>" + (val.ParkName!=null?val.ParkName:"") + "</td>" +
							"<td>" + (val.Persona!=null?val.Persona:"") + "</td>" +
						"</tr>");
				});
			}
		);
//		return response;
	}
	
	$(function() {
		$( "#PlayerName" ).autocomplete({
			source: function( request, response ) {
				PlayerList( request, response );
			},
			delay: 500
		});
	});
</script>

<div class='info-container'>
	<h3>Search</h3>
	<form class='form-container'>
		<div>
			<span>Player:</span>
			<span><input type='text' value='<?=$Admin_moveplayer['PlayerName'] ?>' name='PlayerName' id='PlayerName' /></span>
		</div>
		<input type='hidden' name='KingdomId' id='KingdomId' value='<?=$KingdomId ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=$ParkId ?>' />
	</form>
</div>

<div class='info-container'>
	<h3>Players</h3>
	<table class='information-table action-table' id="player-list-table">
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Player</th>
			</tr>
		</thead>
		<tbody>
		</tbody>
	</table>
</div>