<div class='info-container'>
	<h3><?=$KingdomInfo['KingdomName'] ?> Administration</h3>
	<ul>
		<li><a href='<?=UIR ?>Admin/createpark/kingdom/<?=$this->__session->kingdom_id ?>'>Create Park</a></li>
		<li><a href='<?=UIR ?>Admin/setkingdomofficers&KingdomId=<?=$KingdomInfo['KingdomId'] ?>'>Set <?=$IsPrinz?'Principality':'Kingdom' ?> Officers</a></li>
		<li><a href='<?=UIR ?>Admin/editkingdom/<?=$KingdomInfo['KingdomId'] ?>'>Configure <?=$IsPrinz?'Principality':'Kingdom' ?></a></li>
		<li><a href='<?=UIR ?>Admin/editparks/<?=$KingdomInfo['KingdomId'] ?>'>Configure Parks</a></li>
	</ul>
</div>
<div class='info-container'>
	<h3><?=$KingdomInfo['KingdomName'] ?> Operations</h3>
	<ul>
		<li>Player Operations
			<ul>
				<li><a href='<?=UIR ?>Admin/createplayer/kingdom/<?=$KingdomInfo['KingdomId'] ?>'>Create Player</a></li>
				<li><a href='<?=UIR ?>Admin/claimplayer/kingdom/<?=$KingdomInfo['KingdomId'] ?>'>Move Player</a></li>
				<li><a href='<?=UIR ?>Admin/mergeplayer/kingdom/<?=$KingdomInfo['KingdomId'] ?>'>Merge Players</a></li>
				<li><a href='<?=UIR ?>Admin/suspendplayer/kingdom/<?=$KingdomInfo['KingdomId'] ?>'>Suspensions</a></li>
			</ul>
		</li>
		<li><a href='<?=UIR ?>Admin/transferpark/<?=$KingdomInfo['KingdomId'] ?>'>Claim Park</a></li>
		<li>Events
			<ul>
				<li><a href='<?=UIR ?>Admin/createevent'>Schedule an Event</a></li>
				<li><a href='<?=UIR ?>Admin/manageevent'>Event Templates</a></li>
			</ul>
		</li>
		<li><a href='<?=UIR ?>Admin/downloadkingdom/<?=$KingdomInfo['KingdomId'] ?>' class='unimplemented'>Download <?=$IsPrinz?'Principality':'Kingdom' ?> Dataset</a></li>
		<li><a href='<?=UIR ?>Tournament/create&KingdomId=<?=$KingdomInfo['KingdomId'] ?>' class='unimplemented'>Create Tournament</a></li>	</ul>
</div>
<div class='info-container'>
    <h3><?=$KingdomInfo['KingdomName'] ?> Reports</h3>
	<ul>
		<li>Peerage
			<ul>
				<li><a href='<?=UIR ?>Reports/knights/Kingdom&id=<?=$KingdomInfo['KingdomId'] ?>'>Active Knights</a></li>
				<li><a href='<?=UIR ?>Reports/masters/Kingdom&id=<?=$KingdomInfo['KingdomId'] ?>'>Active Masters</a></li>
			</ul>
		</li>
	</ul>
</div>
