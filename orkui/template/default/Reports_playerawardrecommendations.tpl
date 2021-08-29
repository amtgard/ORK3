<div class='info-container'>
	<h3>Award Recommendations</h3>
	<table class='information-table'>
		<thead>
			<tr>
<?php if (!isset($this->__session->kingdom_id)) : ?>
				<th>Kingdom</th>
<?php endif; ?>
				<th>Persona</th>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Sent By</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($AwardRecommendations)): ?>
<?php 	foreach ($AwardRecommendations as $k => $recommendation): ?>
			<tr>
				<td><a href='<?=UIR.'Player/index/'.$recommendation['MundaneId'] ?>'><?=$recommendation['Persona'] ?></a></td>
				<td><?=$recommendation['AwardName'] ?></td>
				<td><?=valid_id($recommendation['Rank'])?$recommendation['Rank']:'' ?></td>
				<td><?=$recommendation['DateRecommended'] ?></td>
				<td><a href="<?=UIR.'Player/index/'.$recommendation['RecommendedById'] ?>"><?=$recommendation['RecommendedByName'] ?></a></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>
