<div class='info-container'>
	<h3>Kingdom Awards</h3>
	<table class='information-table'>
		<thead>
			<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<th>Kingdom</th>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
				<th>Park</th>
<?php endif; ?>
				<th>Persona</th>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Awards)): ?>
<?php 	foreach ($Awards as $k => $award): ?>
			<tr>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$award['KingdomId'] ?>'><?=$award['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Park/index/'.$award['ParkId'] ?>'><?=$award['ParkName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Player/index/'.$award['MundaneId'] ?>'><?=$award['Persona'] ?></a></td>
				<td><?=$award['AwardName'] ?></td>
				<td><?=valid_id($award['Rank'])?$award['Rank']:'' ?></td>
				<td><?=$award['Date'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>
