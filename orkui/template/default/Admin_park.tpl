<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<div class='info-container'>
	<h3><?=$ParkInfo['ParkName'] ?> Administration</h3>
	<ul>
		<li><a href='<?=UIR ?>Admin/setparkofficers&ParkId=<?=$ParkInfo['ParkId'] ?>'>Set Park Officers</a></li>
		<li><a href='<?=UIR ?>Admin/editpark/<?=$ParkInfo['ParkId'] ?>'>Configure Park</a></li>
	</ul>
</div>
<div class='info-container'>
	<h3><?=$ParkInfo['ParkName'] ?> Operations</h3>
	<ul>
		<li><a href='<?=UIR ?>Admin/downloadpark/<?=$ParkInfo['ParkId'] ?>' class='unimplemented'>Download Park Dataset</a></li>
		<li><a href='<?=UIR ?>Admin/createplayer/park/<?=$ParkInfo['ParkId'] ?>'>Create Player</a></li>
		<li><a href='<?=UIR ?>Admin/claimplayer/park/<?=$ParkInfo['ParkId'] ?>'>Move Player</a></li>
		<li><a href='<?=UIR ?>Admin/mergeplayer/park/<?=$ParkInfo['ParkId'] ?>'>Merge Players</a></li>
		<?php if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_ADMIN, 0, AUTH_ADMIN)) : ?>
			<li><a href='<?=UIR ?>Admin/resetwaivers/park/<?=$ParkInfo['ParkId'] ?>' class='confirm-reset-waivers'>Reset Waivers</a></li>
		<?php endif; ?>
		<li>Events
			<ul>
				<li><a href='<?=UIR ?>Admin/createevent'>Schedule an Event</a></li>
				<li><a href='<?=UIR ?>Admin/manageevent'>Event Templates</a></li>
			</ul>
		</li>
		<li><a href='<?=UIR ?>Tournament/create&ParkId=<?=$ParkInfo['ParkId'] ?>' class='unimplemented'>Create Tournament</a></li>
	</ul>
</div>
<div id="dialogs" style="display: none">
	<div id="reset-waivers" title="Confirmation Required">
		This will reset all waivers for the park. This action cannot be undone. Continue?
	</div>
</div>
<script>
$(document).ready(function() {
	$(".confirm-reset-waivers").click(function(e) {
		e.preventDefault();
		var targetUrl = $(this).attr("href");
		$("#reset-waivers").dialog({
			width: 460,
			modal: true,
			buttons: {
				"No": function() { $(this).dialog("close"); },
				"Yes": function() {
					window.location.href = targetUrl;
					$(this).dialog("close");
				}
			}
		});
	});
});
</script>
