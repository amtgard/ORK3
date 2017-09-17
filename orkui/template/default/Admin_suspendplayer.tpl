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
			delay: 500,
			select: function (e, ui) {
				showLabel('#SrcParkName', ui);
				$('#SrcParkId').val(ui.item.value);
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
			delay: 500,
			select: function (e, ui) {
				showLabel('#PlayerName', ui);
				$('#MundaneId').val(ui.item.value);
				return false;
			}
		});
		
		$( "#Suspendator" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						kingdom_id: <?=$this->__session->kingdom_id ?>
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
				return showLabel('#Suspendator', ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel('#Suspendator', ui);
				$('#SuspendatorId').val(ui.item.value);
				return false;
			}
		});
		
		$( '#SuspendedAt' ).datepicker({dateFormat: 'yy-mm-dd'});
		$( '#SuspendedUntil' ).datepicker({dateFormat: 'yy-mm-dd'});
	});
</script>

<div class='info-container'>
	<h3>Suspend Player</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	
	<form class='form-container' method='post' action='<?=UIR ?>Admin/suspendplayer/submit'>
		<div>
			<span>Park:</span>
			<span><input type='text' value='<?=$Admin_suspendplayer['SrcParkName'] ?>' name='SrcParkName' id='SrcParkName' /></span>
		</div>
		<div>
			<span>Player:</span>
			<span><input type='text' value='<?=$Admin_suspendplayer['PlayerName'] ?>' name='PlayerName' id='PlayerName' /></span>
		</div>
		<div>
			<span>Suspended From:</span>
			<span><input type='text' value='<?=$Admin_suspendplayer['SuspendedAt'] ?>' name='SuspendedAt' id='SuspendedAt' /></span>
		</div>
		<div>
			<span>Suspended Until:</span>
			<span><input type='text' value='<?=$Admin_suspendplayer['SuspendedUntil'] ?>' name='SuspendedUntil' id='SuspendedUntil' /></span>
		</div>
		<div>
			<span>Suspended By:</span>
			<span><input type='text' value='<?=$Admin_suspendplayer['Suspendator'] ?>' name='Suspendator' id='Suspendator' /></span>
		</div>
		<div>
			<span>Comment:</span>
			<span><input type='text' value='<?=$Admin_suspendplayer['Suspension'] ?>' name='Suspension' /></span>
		</div>
		<div>
			<span style='vertical-align: middle'><b>Free Willy?</b></span>
			<span style='height: 3em;'><input style='transform: scale(3); position: relative; top: 1em; left: 1em;' type='checkbox' value='<?=$Admin_suspendplayer['Suspended'] ?>' name='Suspended' CHECKED /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Set' name='Suspend Player' /></span>
		</div>
		<input type='hidden' name='MundaneId' id='MundaneId' value='<?=$Admin_suspendplayer['MundaneId'] ?>' />
		<input type='hidden' name='SuspendatorId' id='SuspendatorId' value='<?=$Admin_suspendplayer['SuspendatorId'] ?>' />
		<input type='hidden' name='SrcParkId' id='SrcParkId' value='<?=$Admin_suspendplayer['SrcParkId'] ?>' />
	</form>
</div>
