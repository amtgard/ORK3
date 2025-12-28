<script type='text/javascript'>

  var awardoptions = "<option value=''></option><?=$AwardOptions ?>";

  var officeroptions = "<option value=''></option><?=$OfficerOptions ?>";

	$(document).ready(function() {
    $( '[name="awardtype"]' ).on('click', function() {
      var awards = awardoptions;
      if ($(this).val() == 'officers') {
        awards = officeroptions;
      }
      $('#AwardId').html(awards);
    });
  
		$( '#Cancel' ).hide();
		$( '#Date' ).datepicker({dateFormat: 'yy-mm-dd'});
		$( '#AwardNameField' ).hide();
		$( '#AwardId' ).change(function() {
			if ($('#AwardId :selected').text() == 'Custom Award')
				$( '#AwardNameField' ).show();
			else
				$( '#AwardNameField' ).hide();
		});
		$( '[name="awardtype"]'  ).change(function() {
			if($(this).val() == 'officers'){
				$( '#AwardNameField' ).hide();
				$( '#AwardRankField' ).hide();
			}else{
				$( '#AwardRankField' ).show();
			}
		});
		$( '#Rank' ).blur(function() {
			rank = $( '#Rank' ).val();
			if (isNaN(rank) || rank < 1 || rank > 10) {
				$( '#Rank' ).val('').fadeOut('slow', function() {
					$( '#Rank' ).css('background-color', '#fff0f0');
					$( '#Rank' ).css('border-color', 'red');
					$( '#Rank' ).fadeIn('slow', function() {
						$( '#Rank' ).animate({ borderColor: '#CCC', backgroundColor: '#fff8c0' }, 'slow' );
					});
				});
			} else {
				$( '#Rank' ).val(Math.round(rank));
			}
		});
		$( "#GivenAt" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Location',
						type: 'all',
						name: request.term,
						date: $('#Date').val(),
						limit: 8
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.LocationName, value: array2json(val) });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				details = eval("(" + ui.item.value + ")");
				showLabel('#GivenAt', details['ShortName']);
					// Set side-effects
				setSideEffects(details);
				return false;
			}, 
			delay: 250,
			select: function (e, ui) {
				details = eval("(" + ui.item.value + ")");
				showLabel('#GivenAt', details['ShortName']);
					// Set side-effects
				setSideEffects(details);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#GivenBy',null);
					$('#MundaneId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
		$( "#GivenBy" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						kingdom_id: <?=$KingdomId ?>,
						limit: 6
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Persona + ' (' + val.KAbbr + ':' + val.PAbbr + ')', value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#GivenBy', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#GivenBy', ui);
				$('#GivenById').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#GivenBy',null);
					$('#GivenById').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});		
		$( "#GivenTo" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						kingdom_id: <?=$KingdomId ?>,
						limit: 6
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
				return showLabel('#GivenTo', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#GivenTo', ui);
				$('#MundaneId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#GivenTo',null);
					$('#MundaneId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
	});
	function setSideEffects(details) {
		$( '#KingdomId' ).val(details['KingdomId']);
		$( '#ParkId' ).val(details['ParkId']);
		$( '#EventId' ).val(details['EventId']);
	}
</script>

<div class='info-container' id='award-editor'>
	<h3>Add Award</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	
	<form class='form-container' method='post' action='<?=UIR ?>Award/<?=$Call ?>/<?=$Id ?>/addaward'>
		<div id='AwardType'>
			<span>Type:</span>
			<span><input type='radio' name='awardtype' value='awards' checked>Awards <input type='radio' name='awardtype' value='officers'>Officers</span>
		</div>
		<div>
			<span>Award:</span>
			<span>
				<select name='AwardId' id='AwardId'>
					<option value=''></option>
<?=$AwardOptions ?>
				</select>
			</span>
		</div>
		<div id='AwardNameField'>
			<span>Award Name:</span>
			<span><input type='text' value='<?=isset($Admin_player)?$Admin_player['AwardName']:$Player['AwardName'] ?>' name='AwardName' id='AwardName' /></span>
		</div>
		<div id='AwardRankField'>
			<span>Rank:</span>
			<span><input type='text' value='<?=$Award_addawards['Rank'] ?>' name='Rank' id='Rank' /></span>
		</div>
		<div>
			<span>Date:</span>
			<span><input type='text' value='<?=$Award_addawards['Date'] ?>' name='Date' id='Date' /></span>
		</div>
		<div>
			<span>Given To:</span>
			<span><input type='text' value='<?=$Award_addawards['GivenTo'] ?>' name='GivenTo' id='GivenTo' /></span>
		</div>
		<div>
			<span>Given By:</span>
			<span><input type='text' value='<?=$Award_addawards['GivenBy'] ?>' name='GivenBy' id='GivenBy' /></span>
		</div>
		<div>
			<span>Given At:</span>
			<span><input type='text' value='<?=$Award_addawards['GivenAt'] ?>' name='GivenAt' id='GivenAt' /></span>
		</div>
		<div>
			<span>Given For:</span>
			<span><input type='text' value='<?=$Award_addawards['Note'] ?>' name='Note' id='Note' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' id='Add' value='Add' /><button type='button' id='Cancel' value='Cancel'>Cancel</button></span>
		</div>
		<input type='hidden' id='GivenById' name='GivenById' value='<?=$Award_addawards['GivenById'] ?>' />
		<input type='hidden' id='MundaneId' name='MundaneId' value='<?=$Award_addawards['MundaneId'] ?>' />
		<input type='hidden' id='ParkId' name='ParkId' value='<?=$Award_addawards['ParkId'] ?>' />
		<input type='hidden' id='KingdomId' name='KingdomId' value='<?=$Award_addawards['KingdomId'] ?>' />
		<input type='hidden' id='EventId' name='EventId' value='<?=$Award_addawards['EventId'] ?>' />
	</form>
</div>
