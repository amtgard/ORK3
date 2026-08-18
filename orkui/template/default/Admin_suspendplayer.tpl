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

		OrkPlayerSearch.attach(document.getElementById('PlayerName'), {
			uir: '<?=UIR ?>',
			onSelect: function(p) {
				document.getElementById('MundaneId').value = p.MundaneId;
			},
			onClear: function() {
				document.getElementById('MundaneId').value = '';
			}
		});
		
		OrkPlayerSearch.attach(document.getElementById('Suspendator'), {
			uir: '<?=UIR ?>',
			kingdomId: <?=intval($this->__session->kingdom_id) ?>,
			onSelect: function(p) {
				document.getElementById('SuspendatorId').value = p.MundaneId;
			},
			onClear: function() {
				document.getElementById('SuspendatorId').value = '';
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
			<span style='vertical-align: middle'><b>Restore (Unsuspend)?</b></span>
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
