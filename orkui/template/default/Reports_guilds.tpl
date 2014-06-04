<div class='info-container'>
	<h3>Guilds</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Guild</th>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
				<th>Park</th>
<?php endif; ?>
				<th>Player</th>
				<th>Attendance</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Guilds)): ?>
<?php 	foreach ($Guilds as $k => $guild): ?>
			<tr>
				<td><?=$guild['ClassName'] ?></td>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$guild['KingdomId'] ?>'><?=$guild['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Park/index/'.$guild['ParkId'] ?>'><?=$guild['ParkName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Player/index/'.$guild['MundaneId'] ?>'><?=$guild['Persona'] ?></a></td>
				<td><?=$guild['AttendanceCount'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>