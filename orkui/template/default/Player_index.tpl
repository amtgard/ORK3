<?php
    $passwordExpired = strtotime($Player['PasswordExpires']) - time() <= 0;
    if ($passwordExpired) {
      $passwordExpiring = 'Expired';
    } else {
      $passwordExpiring = date('Y-m-j', strtotime($Player['PasswordExpires']));
    }
?>
<div class='info-container <?=(($Player['Suspended'])==1)?"suspended-player":"" ?>' id='player-editor'>
<h3><?=$Player['Persona'] ?></h3>
	<form class='form-container' >
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Player['HasHeraldry']>0?$Player['Heraldry']:HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
			</span>
		</div>
		<div>
			<span>Image:</span>
			<span>
				<img class='heraldry-img' src='<?=$Player['HasImage']>0?$Player['Image'] :HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
			</span>
		</div>
	</form>
</div>
<div class='info-container <?=(($Player['Suspended'])==1)?"suspended-player":"" ?>' id='player-editor'>
	<h3>Player Details</h3>
	<?php if (strlen($Error) > 0) : ?>
		<div class='error-message'><?=$Error ?></div>
	<?php endif; ?>
	<?php if (strlen($Message) > 0) : ?>
		<div class='success-message'><?=$Message ?></div>
	<?php endif; ?>	
	<form class='form-container' >
		<div>
			<span>Given Name:</span>
			<span class='form-informational-field'><?=$Player['GivenName'] ?></span>
		</div>
		<div>
			<span>Surname:</span>
			<span class='form-informational-field'><?=$Player['Surname'] ?></span>
		</div>
		<div>
			<span>Persona:</span>
			<span class='form-informational-field'><?=$Player['Persona'] ?></span>
		</div>
		<div>
			<span>Username:</span>
			<span class='form-informational-field'><?=$Player['UserName'] ?></span>
		</div>
		<div>
			<span>Restricted:</span>
			<span><input type='checkbox' value='Restricted' <?=($Player['Restricted'])==1?"Checked":"" ?> DISABLED name='Restricted' id='Restricted' /></span>
		</div>
		<div>
			<span>Waivered:</span>
			<span><input type='checkbox' value='Waivered' <?=($Player['Waivered'])==1?"Checked":"" ?> DISABLED name='Waivered' id='Waivered' /></span>
		</div>
		<div>
			<span>Suspended:</span>
			<span><input type='checkbox' value='Suspended' <?=(($Player['Suspended'])==1)?"CHECKED":"" ?> DISABLED name='PenaltyBox' id='PenaltyBox' /></span>
		</div>
	<?php if ($Player['Suspended']==1) : ?>
		<div>
			<span>Suspended At:</span>
			<span class='form-informational-field'><?=$Player['SuspendedAt'] ?></span>
		</div>
		<div>
			<span>Suspended At:</span>
			<span class='form-informational-field'><?=$Player['SuspendedUntil'] ?></span>
		</div>
		<div>
			<span>Suspended At:</span>
			<span class='form-informational-field'><?=$Player['Suspension'] ?></span>
		</div>
	<?php endif; ?>
		<div>
			<span>Enabled:</span>
			<span><input type='checkbox' value='Active' <?=(($Player['Active'])==1 && ($Player['Suspended'])==0)?"CHECKED":"" ?> DISABLED name='Active' id='Active' /></span>
		</div>
		<div>
			<span>Dues Paid:</span>
			<span class='form-informational-field'><?=$Player['DuesThrough']==0?"No":$Player['DuesThrough'] ?></span>
		</div>
		<div>
			<span>Password Expires:</span>
      <span class='form-informational-field'><?=$passwordExpiring ?></span>
		</div>
		<div>
			<span>Reeve Qualified:</span>
			<span class='form-informational-field'><?=$Player['ReeveQualified']==0?"No":'Until ' . $Player['ReeveQualifiedUntil'] ?></span>
		</div>
		<div>
			<span>Corpora Qualified:</span>
			<span class='form-informational-field'><?=$Player['CorporaQualified']==0?"No":'Until ' . $Player['CorporaQualifiedUntil'] ?></span>
		</div>
		<div>
			<span>Park Member Since:</span>
      <span class='form-informational-field'><?=$Player['ParkMemberSince'] ?></span>
		</div>
  </form>
</div>

<div class='info-container'>
    <h3>Dues</h3>
	<table class='information-table form-container' id='DuesTable'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Dues Paid Until</th>
				<th>Dues Paid On</th>
				<th>Dues For Life?</th>
			</tr>
		</thead>
		<tbody>
			<?php if (is_array($Dues)) foreach ($Dues as $k => $v) : ?>
				<tr>
					<td><?= $v['KingdomName'] ?></td>
					<td><?= $v['ParkName'] ?></td>
					<td style="border: 2px dashed green; background-color: #ccf0cd;">
					<?= ($v['DuesForLife'] == 0) ? $v['DuesUntil']:'' ?>
					</td>								
					<td><?= $v['DuesFrom'] ?></td>
					<?php if ($v['DuesForLife'] == 1) : ?><td style="border: 2px dashed green; background-color: #ccf0cd;">
					<?php else : ?><td>
					<?php endif; ?>
					<?= ($v['DuesForLife'] == 1) ? 'Yes':'No' ?>
					</td>
				</tr>
			<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Companies &amp; Households</h3>
	<table class='information-table' id='Attendance'>
		<thead>
			<tr>
				<th>Name</th>
				<th>Type</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Units['Units'])) $Units['Units'] = array(); ?>
<?php foreach ($Units['Units'] as $key => $unit) : ?>
			<tr>
				<td><a href='<?=UIR ?>Unit/index/<?=$unit['UnitId'] ?>'><?=$unit['Name'] ?></td>
				<td><?=ucfirst($unit['Type']) ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Classes</h3>
	<form class='form-container'>
		<table class='information-table' id='Classes'>
			<thead>
				<tr>
					<th>Class</th>
					<th>Credits</th>
					<th>Level</th>
				</tr>
			</thead>
			<tbody>
<?php if (!is_array($Details['Classes'])) $Details['Classes'] = array(); ?>
<?php foreach ($Details['Classes'] as $key => $detail) : ?>
				<tr>
					<td><?=$detail['ClassName'] ?></td>
					<td class='data-column'><?=$detail['Credits'] + (isset($Player_index)?$Player_index['Class_' . $detail['ClassId']]:$detail['Reconciled']) ?></td>
					<td class='data-column'><?=abs(min(ceil(($detail['Credits'] + (isset($Player_index)?$Player_index['Class_' . $detail['ClassId']]:$detail['Reconciled']))/12),6)) ?></td>
				</tr>
<?php endforeach ?>
			</tbody>
		</table>
	</form>
	<script type='text/javascript'>
		$(document).ready(function() {
			$('#Classes tbody tr').each(function(k, trow) {
				var credits = Number($(trow).find('td:nth-child(2)').html());
				var level = 1;
				if (credits >= 53)
					level = 6;
				else if (credits >= 34)
					level = 5;
				else if (credits >= 21)
					level = 4;
				else if (credits >= 12)
					level = 3;
				else if (credits >= 5)
					level = 2;
				$(trow).find('td:nth-child(3)').html(level);
			});

			// Recommendation form validation
			$('#rec-form-submit').on('click', function(e){
				e.preventDefault()
				if ($('#recommendation-form select[name=KingdomAwardId]').val() && $('#recommendation-form input[name=Reason]').val() ) {
					$('#recommendation-form').submit();
				} else {
					alert('Select an award and give a reason.')
				}

			});

			$('#recommendation-form input[name=Reason]').simpleTxtCounter({
				maxLength: 400,
				countElem: '<span style="margin-left:5px;" class="form-text"></span>'
			});
		});
	</script>
</div>

<div class='info-container'>
    <h3>Historical Imports</h3>
	<table class='information-table form-container' id='Notes'>
		<thead>
			<tr>
				<th>Note</th>
				<th>Description</th>
				<th>Date</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Notes)) foreach ($Notes as $key => $note) : ?>
    		<tr>
				<td><?=$note['Note'] ?></td>
    			<td><?=$note['Description'] ?></td>
    			<td class='form-informational-field' style='text-wrap: nowrap'><?=$note['Date'] . (strtotime($note['DateComplete'])>0?(" - " . $note['DateComplete']):"") ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Awards</h3>
	<div style="background-color:#eee; margin: 5px 5px; padding: 5px 5px;">
		<?php if ($LoggedIn == true): ?>
			<form id="recommendation-form" class='form-container' method='post' action='<?=UIR ?>Player/index/<?=$Player['MundaneId'] ?>/addrecommendation'>
				Award:
				<select name="KingdomAwardId">
					<option>Select Award...</option>
					<?=$AwardOptions ?>
				</select>
				Rank: 
				<select name="Rank">
					<option value="">Select...</option>
					<option value="1">1st</option>
					<option value="2">2nd</option>
					<option value="3">3rd</option>
					<option value="4">4th</option>
					<option value="5">5th</option>
					<option value="6">6th</option>
					<option value="7">7th</option>
					<option value="8">8th</option>
					<option value="9">9th</option>
					<option value="10">10th</option>
				</select>
				Reason: <input type="text" name="Reason">
			</form>
			<button id="rec-form-submit" type="submit">Recommend</button>
		<?php else: ?>
			Login to send an award recommendation.

		<?php endif; ?>
	</div>
	<table class='information-table form-container' id='Awards'>
		<thead>
			<tr>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
				<th>Entered By</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Awards'])) $Details['Awards'] = array(); ?>
<?php foreach ($Details['Awards'] as $key => $detail) : ?>
<?php if (in_array($detail['OfficerRole'], ['none', null]) && $detail['IsTitle'] != 1) : ?>
    		<tr>
				<td style='white-space: nowrap;'><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?><?=(trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'])!=$detail['Name']?" <span class='form-informational-field'>[$detail[Name]]</span>":"" ?></td>
				<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
				<td class='form-informational-field' style='white-space: nowrap;'><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
				<td style='white-space: nowrap;'><a href='<?=UIR ?>Player/index/<?=$detail['GivenById'] ?>'><?=substr($detail['GivenBy'],0,30) ?></a></td>
				<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
				<td><?=$detail['Note'] ?></td>
				<td><a href="<?=UIR.'Player/index/'.$detail['EnteredById'] ?>"><?=$detail['EnteredBy'] ?></a></td>
			</tr>
<?php endif ?>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Titles</h3>
	<table class='information-table form-container' id='Awards'>
		<thead>
			<tr>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
				<th>Entered By</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Awards'])) $Details['Awards'] = array(); ?>
<?php foreach ($Details['Awards'] as $key => $detail) : ?>
<?php if (!in_array($detail['OfficerRole'], ['none', null]) || $detail['IsTitle'] == 1) : ?>
    		<tr>
				<td style='white-space: nowrap;'><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?><?=(trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'])!=$detail['Name']?" <span class='form-informational-field'>[$detail[Name]]</span>":"" ?></td>
				<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
				<td class='form-informational-field' style='white-space: nowrap;'><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
				<td style='white-space: nowrap;'><a href='<?=UIR ?>Player/index/<?=$detail['GivenById'] ?>'><?=substr($detail['GivenBy'],0,30) ?></a></td>
				<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
				<td><?=$detail['Note'] ?></td>
				<td><a href="<?=UIR.'Player/index/'.$detail['EnteredById'] ?>"><?=$detail['EnteredBy'] ?></a></td>
			</tr>
<?php endif ?>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Award Recommendations</h3>
	<table class='information-table form-container' id='AwardRecommendations'>
		<thead>
			<tr>
				<th class="filter-select">Award</th>
				<th class="filter-select">Rank</th>
				<th class="filter-select" style="min-width:80px;">Date</th>
				<th class="filter-select">Sent By</th>
				<th>Reason</th>
				<?php if($this->__session->user_id): ?>
					<th class="sorter-false filter-false">Actions</th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($AwardRecommendations)) $AwardRecommendations = array(); ?>
<?php foreach ($AwardRecommendations as $key => $recommendation) : ?>
			<tr>
				<td><?=$recommendation['AwardName'] ?></td>
				<td><?=valid_id($recommendation['Rank'])?$recommendation['Rank']:'' ?></td>
				<td><?=$recommendation['DateRecommended'] ?></td>
				<td><a href="<?=UIR.'Player/index/'.$recommendation['RecommendedById'] ?>"><?=$recommendation['RecommendedByName'] ?></a></td>
				<td><?=$recommendation['Reason'] ?></td>
				<?php if($this->__session->user_id): ?>
					<td>
						<?php if ($this->__session->user_id == $recommendation['RecommendedById'] || $this->__session->user_id == $recommendation['MundaneId']): ?>
							<a class="confirm-delete-recommendation" href="<?=UIR.'Player/index/' . $recommendation['MundaneId'] . '/deleterecommendation/'.$recommendation['RecommendationsId'] ?>">Delete</a> 
						<?php endif; ?>
					</td>
				<?php endif; ?>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Attendance</h3>
	<table class='information-table' id='Attendance'>
		<thead>
			<tr>
				<th>Date</th>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Event</th>
				<th>Class</th>
				<th>Credits</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Attendance'])) $Details['Attendance'] = array(); ?>
<?php foreach ($Details['Attendance'] as $key => $detail) : ?>
			<tr>
				<td><a href='<?=UIR ?>Attendance/<?=$detail['ParkId']>0?'park':'event' ?>/<?=(($detail['ParkId']>0)?($detail['ParkId'].'&AttendanceDate='.$detail['Date']):($detail['EventId'].'/'.$detail['EventCalendarDetailId'])) ?>'><?=$detail['Date'] ?></td>
				<td><a href='<?=UIR ?>Kingdom/index/<?=$detail['KingdomId'] ?>'><?=$detail['KingdomName'] ?></a></td>
				<td><a href='<?=UIR ?>Park/index/<?=$detail['ParkId'] ?>'><?=$detail['ParkName'] ?></a></td>
				<td><a href='<?=UIR ?>Attendance/event/<?=$detail['EventId'] ?>/<?=$detail['EventCalendarDetailId'] ?>'><?=$detail['EventName'] ?></a></td>
				<td><?=trimlen($detail['Flavor'])>0?$detail['Flavor']:$detail['ClassName'] ?></td>
				<td class='data-column'><?=$detail['Credits'] ?></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<?php if ($this->__session->user_id): ?>
	<div id="dialogs" style="display: none">
		<div id="delete-recommendation" title="Confirmation Required">
			Are you sure you want to delete this recommendation?
		</div>
	</div>
<?php endif; ?>
<script>
		<?php if ($this->__session->user_id): ?>
			$(".confirm-delete-recommendation").click(function(e) {
				e.preventDefault();
				var targetUrl = $(this).attr("href");

				$( "#delete-recommendation" ).dialog({ width: 460,
					buttons: { 
						"Cancel": function() { $(this).dialog("close"); }, 
						"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
					}
				});
			});
		<?php endif; ?>

</script>

