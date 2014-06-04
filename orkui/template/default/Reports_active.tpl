<?php
    $total = 0;
    $dues_paid = 0;
?>
<div class='info-container'>
<?php if (isset($activewaivereduespaid)) : ?>
	<h3>Player Activity Report</h3>
<?php else: ?>
	<h3>Active Players</h3>
<?php endif; ?>
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
				<th>Weeks</th>
    			<th>Park Weeks</th>
    			<th>Attendances</th>
				<th>Monthly Credits</th>
				<th>All Credits</th>
				<th>RoP Credits</th>
<?php if (isset($activewaivereduespaid)) : ?>
				<th>Dues Paid</th>
<?php endif; ?>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($active_players)): ?>
<?php 	foreach ($active_players as $k => $player): ?>
<?php       $total++; ?>
			<tr>
<?php 		if (!isset($this->__session->kingdom_id)) : ?>
				<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId'] ?>'><?=$player['KingdomName'] ?></a></td>
<?php 		endif; ?>
<?php 		if (!isset($this->__session->park_id)) : ?>
				<td><a href='<?=UIR.'Park/index/'.$player['ParkId'] ?>'><?=$player['ParkName'] ?></a></td>
<?php 		endif; ?>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId'] ?>'><?=$player['Persona'] ?></a></td>
				<td class='data-column'><?=$player['WeeksAttended'] ?></td>
    			<td class='data-column'><?=$player['ParkDaysAttended'] ?></td>
    			<td class='data-column'><?=$player['DaysAttended'] ?></td>
				<td class='data-column'><?=$player['TotalMonthlyCredits'] ?></td>
				<td class='data-column'><?=$player['DailyCredits'] ?></td>
				<td class='data-column'><?=$player['RopLimitedCredits'] ?></td>
<?php if (isset($activewaivereduespaid)) : ?>
<?php       $dues_paid += $player['DuesPaid']; ?>
				<td><?=$player['DuesPaid']?"Dues Paid":"" ?></td>
<?php endif; ?>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
            <tr>
                <td colspan='<?=(6 + (!isset($this->__session->kingdom_id)?1:0) + (!isset($this->__session->park_id)?1:0)) ?></td>'></td>
                <td>Total: <?=$total ?></td>
<?php if (isset($activewaivereduespaid)) : ?>
                <td>Dues Paid: <?=$dues_paid ?></td>
<?php endif; ?>
            </tr>
		</tbody>
	</table>
</div>