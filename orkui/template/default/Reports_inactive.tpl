<div class='info-container'>
	<h3>Player Roster</h3>
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
				<th>Mundane</th>
				<th>Waivered</th>
<?php if (isset($show_duespaid)) : ?>
				<th>Dues Paid</th>
<?php endif; ?>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($roster)) : ?>
<?php 	foreach ($roster as $k => $player): ?>
			<tr <?=$player['PenaltyBox']==1?"class='penalty-box'":"" ?>>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId'] ?>'><?=$player['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Park/index/'.$player['ParkId'] ?>'><?=$player['ParkName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId'] ?>'><?=$player['Persona'] ?></a></td>
				<td><?=($player['Displayable']==0?"<span class='restricted-player-display'>Restricted</span>":$player['Surname'].', '.$player['GivenName']) ?></td>
				<td><?=($player['Waivered']==1?"Waiver":"") ?></td>
<?php if (isset($show_duespaid)) : ?>
				<td><?=($player['DuesPaid']?"Paid":"") ?></td>
<?php endif; ?>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>
<?php
