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
		<li>Events
			<ul>
				<li><a href='<?=UIR ?>Admin/createevent'>Schedule an Event</a></li>
				<li><a href='<?=UIR ?>Admin/manageevent'>Event Templates</a></li>
			</ul>
		</li>
	</ul>
</div>