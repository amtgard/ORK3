<script type='text/javascript'>
	function EventList( request, response ) {
		park_id = $('#ParkId').val();
		kingdom_id = $('#KingdomId').val();
		unit_id = $('#UnitId').val();
		mundane_id = $('#MundaneId').val();
		$.getJSON(
			"<?=HTTP_SERVICE ?>Search/SearchService.php",
			{
				Action: 'Search/Event',
				kingdom_id: kingdom_id,
				park_id: park_id,
				unit_id: unit_id,
				mundane_id: mundane_id,
				name: (request!=null?request.term:''),
				limit: 20
			},
			function( data ) {
				$('#event-list-table tbody').html('');
				$.each(data, function(i, val) {
					$('#event-list-table tbody').append(
						"<tr onclick='javascript:window.location.href=\"<?=UIR ?>Event/index/" + val.EventId + "\"'>" +
							"<td>" + (val.Name!=null?val.Name:"") + "</td>" +
							"<td>" + (val.NextDate!=null?val.NextDate:"") + "</td>" +
							"<td>" + (val.KingdomName!=null?val.KingdomName:"") + "</td>" +
							"<td>" + (val.ParkName!=null?val.ParkName:"") + "</td>" +
							"<td>" + (val.Persona!=null?val.Persona:"") + "</td>" +
							"<td>" + (val.UnitName!=null?val.UnitName:"") + "</td>" +
						"</tr>");
				});
			}
		);
//		return response;
	}
	
	$(function() {
		$( "#EventName" ).autocomplete({
			source: function( request, response ) {
				EventList( request, response );
			},
			delay: 500,
			change: function (e, ui) {
				if (ui.item == null) {
					EventList(null,null);
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
			<span>Event:</span>
			<span><input type='text' value='<?=$Admin_moveplayer['EventName'] ?>' name='EventName' id='EventName' /></span>
		</div>
		<input type='hidden' name='KingdomId' id='KingdomId' value='<?=$KingdomId ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=$ParkId ?>' />
		<input type='hidden' name='UnitId' id='UnitId' value='<?=$UnitId ?>' />
	</form>
</div>

<div class='info-container'>
	<h3>Events</h3>
	<table class='information-table action-table' id="event-list-table">
		<thead>
			<tr>
				<th>Event</th>
				<th>When</th>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Player</th>
				<th>Unit</th>
			</tr>
		</thead>
		<tbody>
		</tbody>
	</table>
</div>
