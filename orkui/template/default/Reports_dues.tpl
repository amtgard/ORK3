<style>
	#dues-filter-box label {
		padding: 3px 3px;
		border: 1px dashed #666;
		margin: 0 10px 5px 0;
	}
</style>

<div class='info-container'>
	<h3>Dues Paid List</h3>
	<div id="dues-filter-box" style="border: 1px solid #ccc; margin-bottom:15px; padding-bottom: 15px;">
		<h4 style="width: 90%; margin: 5px 4px; margin: 5px auto 10px;">Filters</h4>
		<div style="width: 90%; margin: 0 auto;">
			<label>Unwaivered <input type="checkbox" value="unwaivered" name="filter" checked/></label>
			<label>Dues For Life <input type="checkbox" value="duesforlife" name="filter" checked/></label>
			<label>Suspended <input type="checkbox" value="suspended" name="filter" checked/></label>
		</div>
	</div>
	<strong>Total:</strong> <?= count($roster['DuesPaidList']); ?> <strong>Filtered Total:</strong> <span id="dues-filtered-total"><?= count($roster['DuesPaidList']); ?></span> (Hidden: <span id="dues-hidden-total">0</span>)
	<table id="dues-paid-list-table" class='information-table'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Persona</th>
				<?php if (!$roster['RestrictAccess']): ?>
					<th>Mundane</th>
				<?php endif; ?>
				<th>Waivered</th>
				<th>Date Paid</th>
				<th>Expires</th>
				<th>Dues For Life</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($roster['DuesPaidList'])) : ?>
<?php 	foreach ($roster['DuesPaidList'] as $k => $player): ?>
			<tr class="<?= (!empty($player['DuesForLife']))?' duesforlife':'' ?><?= (!empty($player['Suspended']))?' suspended penalty-box':'' ?><?= (empty($player['Waivered']))?' unwaivered':'' ?>">
				<td><a href='<?=UIR.'Kingdom/index/'.$player['KingdomId'] ?>'><?=$player['KingdomName'] ?></a></td>
				<td><a href='<?=UIR.'Park/index/'.$player['ParkId'] ?>'><?=$player['ParkName'] ?></a></td>
				<td><a href='<?=UIR.'Player/index/'.$player['MundaneId'] ?>'><?= $player['Persona'] ?></a></td>
				<?php if (!$roster['RestrictAccess']): ?>
					<td><?= $player[GivenName] . ' ' . $player['Surname'] ?></td>
				<?php endif; ?>
				<td><?= ($player['Waivered'])?'Yes':'' ?></td>
				<td><?=($player['DuesFrom']?$player['DuesFrom']:'') ?></td>
				<td style="border: 2px dashed green; background-color: #ccf0cd;"><?=($player['DuesUntil'] && $player['DuesForLife'] == 0?$player['DuesUntil']:'') ?></td>
				<td style="<?= ($player['DuesForLife'] == 1) ? 'border: 2px dashed green; background-color: #ccf0cd;' : '' ?>"><?=($player['DuesForLife'] == 1?'Yes':'') ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<script type='text/javascript'>
	$(document).ready(function() {
		$('#dues-filter-box input[name=filter]').on('click', function (e) {
			var filterclass = $(e.target).val();
			var ttl = parseInt(<?= count($roster['DuesPaidList']); ?>);
			var visible;
			if (this.checked) {
				$('#dues-paid-list-table .' + filterclass).show();

			} else {
				$('#dues-paid-list-table .' + filterclass).hide();
			}
			visible = parseInt($('#dues-paid-list-table tr:visible').length - 1);
			$('#dues-filtered-total').html(visible);
			$('#dues-hidden-total').html(ttl - visible);
		})
	});
</script>

<?php
