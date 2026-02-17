<?php
$historical_awards = array_filter((array)$Details['Awards'], function($a) {
	return $a['IsHistorical'] && in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1;
});
$non_historical_awards = array_filter((array)$Details['Awards'], function($a) {
	return !$a['IsHistorical'] && in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1;
});
?>

<?php if (isset($Message)) : ?>
<div class='info-container' style='background:#e6ffe6;border:1px solid #9c9;padding:10px 16px;margin-bottom:12px;'><?=$Message ?></div>
<?php endif ?>

<div class='info-container'>
	<h3>Reconcile Historical Awards &mdash; <?=$Player['Persona'] ?></h3>
	<p style='color:#666;'>
		Historical awards (highlighted) are awards imported from the legacy system. They have no linked grantor or location.
		Fill in the fields below and click <strong>Save All</strong> to reconcile them. Use <strong>Auto-Assign Ranks</strong>
		to automatically number ladder awards in chronological order.
	</p>
	<p><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>'>&larr; Back to Admin Player Page</a></p>
</div>

<?php if (empty($historical_awards)) : ?>
<div class='info-container'>
	<p style='color:#666;font-style:italic;'>No historical awards found for this player.</p>
</div>
<?php else : ?>

<form method='post' action='<?=UIR ?>Reconcile/index/<?=$Player['MundaneId'] ?>/reconcileall' id='reconcile-all-form'>

<?php
// Group historical awards by KingdomAwardId for auto-assign rank buttons
$award_groups = array();
foreach ($historical_awards as $award) {
	$key = $award['KingdomAwardId'];
	if (!isset($award_groups[$key])) {
		$award_groups[$key] = array('name' => $award['KingdomAwardName'], 'is_ladder' => $award['IsLadder'], 'awards' => array());
	}
	$award_groups[$key]['awards'][] = $award;
}
?>

<?php foreach ($award_groups as $kingdom_award_id => $group) : ?>
<div class='info-container'>
	<h3>
		<?=htmlspecialchars($group['name']) ?>
		<?php if ($group['is_ladder']) : ?>
		<button type='button' class='auto-assign-btn' data-mundaneid='<?=$Player['MundaneId'] ?>' data-kingdomawardid='<?=$kingdom_award_id ?>' style='font-size:0.7em;font-weight:normal;margin-left:12px;'>
			Auto-Assign Ranks
		</button>
		<?php endif ?>
	</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Award</th>
				<?php if (!$group['is_ladder']) : ?><th>Custom Title</th><?php endif ?>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
				<th>Original Note</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($group['awards'] as $detail) : ?>
		<tr style='background-color:#fffbe6;'>
			<td>
				<select name='KingdomAwardId[<?=$detail['AwardsId'] ?>]' class='reconcile-award-select' style='min-width:160px;'>
					<option value=''></option>
					<?=$AwardOptions ?>
				</select>
				<script>document.currentScript.previousElementSibling.value = '<?=$detail['KingdomAwardId'] ?>';</script>
			</td>
			<?php if (!$group['is_ladder']) : ?>
			<td>
				<input type='text' name='CustomName[<?=$detail['AwardsId'] ?>]' value='<?=htmlspecialchars($detail['CustomAwardName'] ?? '') ?>' style='width:160px;' maxlength='200' placeholder='Custom title...' />
			</td>
			<?php endif ?>
			<td>
				<input type='text' name='Rank[<?=$detail['AwardsId'] ?>]' class='reconcile-rank numeric-field' data-awardsid='<?=$detail['AwardsId'] ?>' value='<?=valid_id($detail['Rank'])?$detail['Rank']:'' ?>' style='width:40px;float:none;' />
			</td>
			<td>
				<?=$detail['Date'] ?>
				<input type='hidden' name='Date[<?=$detail['AwardsId'] ?>]' value='<?=$detail['Date'] ?>' />
			</td>
			<td>
				<input type='text' name='GivenByText[<?=$detail['AwardsId'] ?>]' class='reconcile-givenby' data-awardsid='<?=$detail['AwardsId'] ?>' style='width:180px;' placeholder='Search player...' />
				<input type='hidden' name='GivenById[<?=$detail['AwardsId'] ?>]' id='GivenById_<?=$detail['AwardsId'] ?>' value='' />
			</td>
			<td>
				<input type='text' name='GivenAtText[<?=$detail['AwardsId'] ?>]' class='reconcile-givenat' data-awardsid='<?=$detail['AwardsId'] ?>' data-date='<?=$detail['Date'] ?>' style='width:180px;' placeholder='Search location...' />
				<input type='hidden' name='ParkId[<?=$detail['AwardsId'] ?>]' id='ParkId_<?=$detail['AwardsId'] ?>' value='' />
				<input type='hidden' name='KingdomId[<?=$detail['AwardsId'] ?>]' id='KingdomId_<?=$detail['AwardsId'] ?>' value='' />
				<input type='hidden' name='EventId[<?=$detail['AwardsId'] ?>]' id='EventId_<?=$detail['AwardsId'] ?>' value='' />
			</td>
			<td>
				<input type='text' name='Note[<?=$detail['AwardsId'] ?>]' value='<?=htmlspecialchars($detail['Note'] ?? '') ?>' style='width:220px;' maxlength='400' />
			</td>
			<td style='color:#888;font-style:italic;font-size:0.9em;'>
				<?=htmlspecialchars($detail['Note'] ?? '') ?>
			</td>
			<input type='hidden' name='AwardsId[]' value='<?=$detail['AwardsId'] ?>' />
		</tr>
<?php endforeach ?>
		</tbody>
	</table>
	<div style='text-align:right;padding:8px 0 4px 0;'>
		<input type='submit' value='Save All' style='font-size:0.95em;padding:4px 14px;' />
	</div>
</div>
<?php endforeach ?>

<div class='info-container'>
	<div style='padding:12px 0;'>
		<input type='submit' value='Save All' style='font-size:1.1em;padding:6px 20px;' />
	</div>
</div>

</form>

<?php endif ?>

<?php if (!empty($non_historical_awards)) : ?>
<div class='info-container'>
	<h3>Existing Awards (Already Reconciled)</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($non_historical_awards as $detail) : ?>
		<tr>
			<td><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?></td>
			<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
			<td><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
			<td><?=$detail['GivenBy'] ?></td>
			<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
			<td><?=$detail['Note'] ?></td>
		</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>
<?php endif ?>

<script type='text/javascript'>
$(document).ready(function() {

	// Player autocomplete for all Given By fields
	$('.reconcile-givenby').each(function() {
		var awardsId = $(this).data('awardsid');
		$(this).autocomplete({
			minLength: 1,
			source: function(request, response) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{ Action: 'Search/Player', type: 'all', search: request.term, limit: 6 },
					function(data) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Persona + ' (' + val.KAbbr + ':' + val.PAbbr + ')', value: val.MundaneId, display: val.Persona + ' (' + val.KAbbr + ':' + val.PAbbr + ')'});
						});
						response(suggestions);
					}
				);
			},
			delay: 250,
			focus: function(event, ui) {
				$(this).val(ui.item.display);
				return false;
			},
			select: function(e, ui) {
				$(this).val(ui.item.display);
				$('#GivenById_' + awardsId).val(ui.item.value);
				return false;
			},
			change: function(e, ui) {
				if (!ui.item) {
					$('#GivenById_' + awardsId).val('');
				}
				return false;
			}
		});
	});

	// Location autocomplete for all Given At fields
	$('.reconcile-givenat').each(function() {
		var awardsId = $(this).data('awardsid');
		var awardDate = $(this).data('date');
		$(this).autocomplete({
			source: function(request, response) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{ Action: 'Search/Location', type: 'all', name: request.term, date: awardDate, limit: 8 },
					function(data) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.LocationName, value: array2json(val), shortName: val.ShortName});
						});
						response(suggestions);
					}
				);
			},
			delay: 250,
			focus: function(event, ui) {
				var details = eval('(' + ui.item.value + ')');
				$(this).val(details['ShortName']);
				$('#ParkId_' + awardsId).val(details['ParkId']);
				$('#KingdomId_' + awardsId).val(details['KingdomId']);
				$('#EventId_' + awardsId).val(details['EventId']);
				return false;
			},
			select: function(e, ui) {
				var details = eval('(' + ui.item.value + ')');
				$(this).val(details['ShortName']);
				$('#ParkId_' + awardsId).val(details['ParkId']);
				$('#KingdomId_' + awardsId).val(details['KingdomId']);
				$('#EventId_' + awardsId).val(details['EventId']);
				return false;
			}
		});
	});

	// Auto-assign ranks buttons — updates fields in-place without page reload
	$('.auto-assign-btn').click(function() {
		var mundaneId = $(this).data('mundaneid');
		var kingdomAwardId = $(this).data('kingdomawardid');
		var btn = $(this);
		btn.prop('disabled', true).text('Assigning...');
		$.getJSON(
			"<?=UIR ?>Reconcile/index/" + mundaneId + "/autoassignranks/" + kingdomAwardId,
			function(data) {
				if (data.Status == 0 && data.Assignments) {
					$.each(data.Assignments, function(awardsId, rank) {
						$('input[name="Rank[' + awardsId + ']"]').val(rank);
					});
					btn.prop('disabled', false).text('Auto-Assign Ranks');
				} else {
					alert('Error assigning ranks. Please try again.');
					btn.prop('disabled', false).text('Auto-Assign Ranks');
				}
			}
		).fail(function() {
			alert('Error assigning ranks. Please try again.');
			btn.prop('disabled', false).text('Auto-Assign Ranks');
		});
	});

});
</script>
