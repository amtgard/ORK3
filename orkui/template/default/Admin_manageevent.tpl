<script type='text/javascript'>

	function SetEvents( request, response ) {
<?php if (!valid_id($CreateUnitId) && !valid_id($CreateMundaneId)) : ?>
		park_id = $('#ParkId').val() > 0 ? $('#ParkId').val() : 0;
		kingdom_id = $('#KingdomId').val();
<?php endif; ?>
<?php if (valid_id($CreateMundaneId)) : ?>
		mundane_id = $('#CreateMundaneId').val();
<?php endif; ?>
<?php if (valid_id($CreateUnitId)) : ?>
		unit_id = $('#CreateUnitId').val();
<?php endif; ?>
		$.ajax({
			url: "<?=HTTP_SERVICE ?>Search/SearchService.php",
			data: {
				Action: 'Search/Event',
<?php if (!valid_id($CreateUnitId) && !valid_id($CreateMundaneId)) : ?>
				park_id: park_id,
				kingdom_id: kingdom_id,
<?php endif; ?>
<?php if (valid_id($CreateMundaneId)) : ?>
				mundane_id: mundane_id,
<?php endif; ?>
<?php if (valid_id($CreateUnitId)) : ?>
				unit_id: unit_id,
<?php endif; ?>
				name: (request!=null?request.term:''),
				limit: 24
			},
			success: function( data ) {
				$('#EventListTable tbody').html('');
				$.each(data, function(i, val) {
					$('#EventListTable tbody').append("<tr onclick='javascript:window.location.href=\"<?=UIR ?>Admin/event/" + val.EventId + "\"'><td>" + (val.KingdomName!=null?val.KingdomName:"") + "</td><td>" + (val.ParkName!=null?val.ParkName:"") + "</td><td>" + (val.UnitName!=null?val.UnitName:"") + "</td><td>" + (val.Persona!=null?val.Persona:"") + "</td><td>" + val.Name + "</td></tr>");
				});
				if (response != null) {
					var suggestions = [];
					$.each(data, function(i, val) {
						suggestions.push({label: val.Name, value: val.EventId });
					});
					response(suggestions);
				}
			},
            dataType: "json",
            error: function(data) {
                var g = data;
            }
		});
//		return response;
	}

	$(function() {
		SetEvents(null,null);
		$('.create-ik-toggler').click(function() {
			if ($(this).val() == 0) {
				$('.kingdom-park-event').fadeOut('slow', function() {
					$('.personal-interkingdom').fadeIn('slow');
				});
			} else {
				$('.personal-interkingdom').fadeOut('slow',function() {
					$('.kingdom-park-event').fadeIn('slow');
				});
			}
		});
		
<?php if (!isset($this->__session->kingdom_id) && !isset($this->__session->park_id)) : ?>
		$('.personal-interkingdom').hide();
		$( "#KingdomName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Kingdom',
						name: request.term,
						limit: 6
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
			delay: 250,
			select: function (e, ui) {
				showLabel('#KingdomName', ui);
				$('#KingdomId').val(ui.item.value);
				SetEvents(null,null);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#KingdomName',null);
					$('#KingdomId').val(null);
					SetEvents(null,null);
				}
				return false;
			}
		});
		$( "#CreateKingdomName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Kingdom',
						name: request.term,
						limit: 6
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
				return showLabel('#CreateKingdomName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#CreateKingdomName', ui);
				$('#CreateKingdomId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#CreateKingdomName',null);
					$('#CreateKingdomId').val(null);
				}
				return false;
			}
		});
<?php endif ?>	
<?php if (!isset($this->__session->park_id)) : ?>
		$( "#ParkName" ).autocomplete({
			source: function( request, response ) {
				kingdom_id = $('#KingdomId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term,
						kingdom_id: <?=((isset($this->__session->kingdom_id) || isset($this->__session->park_id))?$this->__session->kingdom_id:"kingdom_id") ?>,
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
				return showLabel('#ParkName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#ParkName', ui);
				$('#ParkId').val(ui.item.value);
				SetEvents(null,null);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#ParkName',null);
					$('#ParkId').val(null);
					SetEvents(null,null);
				}
				return false;
			}
		});
		$( "#CreateParkName" ).autocomplete({
			source: function( request, response ) {
				kingdom_id = $('#CreateKingdomId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Park',
						name: request.term,
						kingdom_id: <?=((isset($this->__session->kingdom_id) || isset($this->__session->park_id))?$this->__session->kingdom_id:"kingdom_id") ?>,
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
				return showLabel('#CreateParkName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#CreateParkName', ui);
				$('#CreateParkId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#CreateParkName',null);
					$('#CreateParkId').val(null);
					SetEvents(null,null);
				}
				return false;
			}
		});
<?php endif ?>
		$( "#PlayerName" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				kingdom_id = $('#KingdomId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						park_id: <?= ((isset($this->__session->park_id))?$this->__session->park_id:"park_id.length>0?park_id:null") ?>,
						kingdom_id: kingdom_id,
						limit: 6
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
			delay: 250,
			select: function (e, ui) {
				showLabel('#PlayerName', ui);
				$('#MundaneId').val(ui.item.value.split("|")[0]);
				if (ui.item.value.split("|")[1] == "0") {
					$('input[name=Ban]:eq(0)').attr('checked', 'checked');
				} else {
					$('input[name=Ban]:eq(1)').attr('checked', 'checked');
				}
				SetEvents(null,null);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#PlayerName',null);
					$('#MundaneId').val(null);
					SetEvents(null,null);
				}
				return false;
			}
		});
		$( "#EventName" ).autocomplete({
			source: function( request, response ) {
				SetEvents( request, response );
			},
			focus: function( event, ui ) {
				return showLabel('#EventName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#EventName', ui);
				$('#EventId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#EventName',null);
					$('#EventId').val(null);
					SetEvents(null,null);
				}
				return false;
			}
		});
		$( "#CreatePlayerName" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#CreateParkId').val();
				kingdom_id = $('#CreateKingdomId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						park_id: <?=((isset($this->__session->park_id))?$this->__session->park_id:"park_id.length>0?park_id:null") ?>,
						kingdom_id: kingdom_id,
						limit: 6
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
				return showLabel('#CreatePlayerName', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#CreatePlayerName', ui);
				$('#CreateMundaneId').val(ui.item.value.split("|")[0]);
				if (ui.item.value.split("|")[1] == "0") {
					$('input[name=Ban]:eq(0)').attr('checked', 'checked');
				} else {
					$('input[name=Ban]:eq(1)').attr('checked', 'checked');
				}
				SetEvents(null,null);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#CreatePlayerName',null);
					$('#CreateMundaneId').val(null);
				}
				return false;
			}
		});
		$('#event-creation-form').hide();
		$('#imsure').click(function() {
		    $('#create-warning').hide('slow',function() {
        		$('#event-creation-form').show('slow');    
        	});
		});
	});
</script>

<style type='text/css'>
    .error-message h3 {
        border-color: #855;
        color: #855;
        background-color: #fed;
    }
</style>

<div class='info-container'>
	<h3>Create Event Template</h3>
<?php if (strlen($Error) > 0 || true) : ?>
	<div class='error-message'>
	    <?=$Error ?>
	    <div style='max-width: 350px;' id='create-warning'>
	        <h3>Are you sure?</h3>
	        
	        Are you sure you need a new event template?  This is very uncommon.  Your common Park and Kingdom event templates (such as Midreign and Crown Quals) already exist! Do a search for your Park or Kingdom's existing event templates and add a scheduled date to them.
	        
	        <p>
	        
	        <button id='imsure'>Yes, I'm Sure!</button>
	    </div>
	</div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>		
	<form id='event-creation-form' class='form-container' method='post' action='<?=UIR ?>Admin/manageevent/create<?=((isset($CreateUnitId)||isset($Admin_manageevent['CreateUnitId']))?"&UnitId=$CreateUnitId":"") ?><?=((isset($CreateMundaneId)||isset($Admin_manageevent['CreateMundaneId']))?"&MundaneId=$CreateMundaneId":"") ?>' enctype='multipart/form-data'>
<?php if (!isset($this->__session->kingdom_id) && !isset($this->__session->park_id)) : ?>
		<div>
			<span>Hosted:</span>
			<span>
				<input type='radio' value='1' class='create-ik-toggler' name='CreateInterKingdom' id='NotInterKingdom' <?=$Admin_manageevent['InterKingdom']!=1?"Checked":"" ?> /><label for="NotInterKingdom">Yes</label>
				<input type='radio' value='0' class='create-ik-toggler' name='CreateInterKingdom' id='IsInterKingdom' <?=$Admin_manageevent['InterKingdom']==1?"Checked":"" ?> /><label for="IsInterKingdom">No</label>
			</span>
		</div>
<?php endif ?>
<?php if (!isset($this->__session->kingdom_id)) : ?>
		<div class='kingdom-park-event'>
			<span>Kingdom:</span>
			<span><input type='text' value='<?=$Admin_manageevent['CreateKingdomName'] ?>' name='CreateKingdomName' id='CreateKingdomName' /></span>
		</div>
<?php endif ?>
<?php if (!isset($this->__session->park_id)) : ?>
		<div class='kingdom-park-event'
			<span>Park:</span>
			<span><input type='text' value='<?=$Admin_manageevent['CreateParkName'] ?>' name='CreateParkName' id='CreateParkName' /></span>
		</div>
<?php endif ?>
<?php if (!isset($this->__session->kingdom_id) && !isset($this->__session->park_id)) : ?>
		<div class='personal-interkingdom'>
			<span>Player:</span>
			<span><input type='text' value='<?=$Admin_manageevent['CreatePlayerName'] ?>' name='CreatePlayerName' id='CreatePlayerName' /></span>
		</div>
<?php endif ?>
		<div>
			<span>Template Name:</span>
			<span><input type='text' class='name-field' value='<?=$Admin_manageevent['CreateEventName'] ?>' name='CreateEventName' id='CreateEventName' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Create Event Template' name='Create Event' /></span>
		</div>
		<input type='hidden' name='CreateUnitId' id='CreateUnitId' value='<?=(isset($CreateUnitId)?$CreateUnitId:$Admin_manageevent['CreateUnitId']) ?>' />
		<input type='hidden' name='CreateKingdomId' id='CreateKingdomId' value='<?=(isset($this->__session->kingdom_id)?$this->__session->kingdom_id:$Admin_manageevent['CreateKingdomId']) ?>' />
		<input type='hidden' name='CreateMundaneId' id='CreateMundaneId' value='<?=isset($CreateMundaneId)?$CreateMundaneId:$Admin_manageevent['CreateMundaneId'] ?>' />
		<input type='hidden' name='CreateParkId' id='CreateParkId' value='<?=(isset($this->__session->park_id)?$this->__session->park_id:$Admin_manageevent['CreateParkId']) ?>' />
	</form>
</div>

<div class='info-container'>
	<h3>Find Existing Template</h3>
	<div class='form-container'>
<?php if (!isset($this->__session->kingdom_id)) : ?>
		<div>
			<span>Kingdom:</span>
			<span><input type='text' value='<?=$Admin_manageevent['KingdomName'] ?>' name='KingdomName' id='KingdomName' /></span>
		</div>
<?php endif ?>
<?php if (!isset($this->__session->park_id)) : ?>
		<div>
			<span>Park:</span>
			<span><input type='text' value='<?=$Admin_manageevent['ParkName'] ?>' name='ParkName' id='ParkName' /></span>
		</div>
<?php endif ?>
		<div>
			<span>Player:</span>
			<span><input type='text' value='<?=$Admin_manageevent['PlayerName'] ?>' name='PlayerName' id='PlayerName' /></span>
		</div>
		<div>
			<span>Template Name:</span>
			<span><input type='text' value='<?=$Admin_manageevent['EventName'] ?>' name='EventName' id='EventName' /></span>
		</div>
		<input type='hidden' name='KingdomId' id='KingdomId' value='<?=(isset($this->__session->kingdom_id)?$this->__session->kingdom_id:$Admin_manageevent['KingdomId']) ?>' />
		<input type='hidden' name='MundaneId' id='MundaneId' value='<?=$Admin_manageevent['MundaneId'] ?>' />
		<input type='hidden' name='ParkId' id='ParkId' value='<?=(isset($this->__session->park_id)?$this->__session->park_id:$Admin_manageevent['ParkId']) ?>' />
		<input type='hidden' name='EventId' id='EventId' value='<?=$Admin_manageevent['EventId'] ?>' />
	</div>
</div>

<div class='info-container'>
	<h3>Event Templates</h3>
	<table class='information-table action-table' id='EventListTable'>
		<thead>
			<tr>
				<th><?=$IsPrinz?'Principality':'Kingdom' ?></th>
				<th>Park</th>
				<th>Unit</th>
				<th>Player</th>
				<th>Template Name</th>
			</tr>
		</thead>
		<tbody>
		</tbody>
	</table>
</div>
