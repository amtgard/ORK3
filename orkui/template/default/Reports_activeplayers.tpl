<?php
    $total = 0;
?>
<div class='info-container'>
	<h3>Active Players</h3>
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
				<th>Attendance</th>
				<th>Credits</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($active_players as $k => $player): ?>
<?php       $total++; ?>
			<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<td><?=$player['KingdomName'] ?></td>
<?php endif; ?>
<?php if (!isset($this->__session->park_id)) : ?>
				<td><?=$player['ParkName'] ?></td>
<?php endif; ?>
				<td><?=$player['Persona'] ?></td>
				<td class='data-column'><?=$player['WeeksAttended'] ?></td>
				<td class='data-column'><?=$player['TotalCredits'] ?></td>
			</tr>
<?php endforeach; ?>
            <tr>
                <td colspan='<?=(3 + (!isset($this->__session->kingdom_id)?1:0) + (!isset($this->__session->park_id)?1:0)) ?></td>'></td>
                <td>Total: <?=$total ?></td>
            </tr>
		</tbody>
	</table>
</div>