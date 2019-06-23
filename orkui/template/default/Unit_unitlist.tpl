<?php

?>
<div class='info-container'>
	<h3>Units</h3>
	<table class='information-table action-table'>
		<thead>
			<tr>
				<th>Type</th>
				<th>Name</th>
				<th>Members</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Units['Units'])):  ?>
	<?php foreach ($Units['Units'] as $k => $unit) : ?>
			<tr onclick='javascript:window.location.href="<?=UIR ?>Unit/index/<?=$unit['UnitId']; ?>";'>
				<td><?=$unit['Type']; ?></td>
				<td><a href='<?=UIR ?>Unit/index/<?=$unit['UnitId'] ?>'><?=$unit['Name']; ?></a></td>
				<td class='data-column'><?=$unit['MemberCount'] ?></td>
			</tr>
	<?php endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>
