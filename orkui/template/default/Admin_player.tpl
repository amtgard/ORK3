<div class='info-container' id='player-editor'>
	<h3><?=$Player['Persona'] ?></h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/update' enctype="multipart/form-data">
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Player['HasHeraldry']>0?$Player['Heraldry']:HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
				<input type='file' class='restricted-image-type' name='Heraldry' id='Heraldry' />
			</span>
		</div>
		<div>
			<span>Image:</span>
			<span>
				<img class='heraldry-img' src='<?=$Player['HasImage']>0?$Player['Image']:HTTP_PLAYER_HERALDRY . '000000.jpg' ?>' />
				<input type='file' class='restricted-image-type' name='PlayerImage' id='PlayerImage' />
			</span>
		</div>
		<div>
			<span>Behold!</span>
			<span>
				<input type='file' class='restricted-image-type' name='PlayerFace' id='PlayerFace' />
			</span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Update Media' name='Update' /></span>
		</div>
	</form>
</div>

<div class='info-container' id='player-editor'>
	<h3>Player Details</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>	
	<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/update' enctype="application/x-www-form-urlencoded">
		<div>
			<span>Given Name:</span>
			<span><input type='text' class='name-field' value='<?=html_encode(isset($Admin_player)?$Admin_player['GivenName']:$Player['GivenName']) ?>' name='GivenName' id='GivenName' /></span>
		</div>
		<div>
			<span>Surname:</span>
			<span><input type='text' class='name-field' value='<?=html_encode(isset($Admin_player)?$Admin_player['Surname']:$Player['Surname']) ?>' name='Surname' id='Surname' /></span>
		</div>
		<div>
			<span>Persona:</span>
			<span><input type='text' class='required-field name-field' value='<?=html_encode(isset($Admin_player)?$Admin_player['Persona']:$Player['Persona']) ?>' name='Persona' id='Persona' /></span>
		</div>
		<div>
			<span>Username:</span>
			<span><input type='text' class='required-field name-field' value='<?=html_encode(isset($Admin_player)?$Admin_player['UserName']:$Player['UserName']) ?>' name='UserName' id='UserName' /></span>
		</div>
		<div>
			<span>Password:</span>
			<span><input type='password' value='<?=isset($Admin_player)?$Admin_player['Password']:$Player['Password'] ?>' name='Password' id='Password' /></span>
		</div>
		<div>
			<span>Password (Again):</span>
			<span><input type='password' value='<?=isset($Admin_player)?$Admin_player['PasswordAgain']:$Player['PasswordAgain'] ?>' name='PasswordAgain' id='PasswordAgain' /></span>
		</div>
		<div>
			<span>Email:</span>
			<span><input type='text' class='most-emails-field' value='<?=html_encode(isset($Admin_player)?$Admin_player['Email']:$Player['Email']) ?>' name='Email' id='Email' /></span>
		</div>
		<div>
			<span>Restricted:</span>
			<span><input type='checkbox' value='Restricted' <?=(isset($Admin_player)?$Admin_player['Restricted']:$Player['Restricted'])==1?"Checked":"" ?> name='Restricted' id='Restricted' /></span>
		</div>
		<div>
			<span>Company:</span>
			<span class='form-informational-field'><?=isset($Admin_player)?$Admin_player['Company']:$Player['Company'] ?></span>
		</div>
    <!--
		<div>
			<span>Penalty Box:</span>
			<span><input type='checkbox' value='Penalty' <?=((isset($Admin_player)?$Admin_player['PenaltyBox']:$Player['PenaltyBox'])==1)?"CHECKED":"" ?> DISABLED name='PenaltyBox' id='PenaltyBox' /></span>
		</div>
    -->
  	<div>
			<span>Waivered:</span>
			<span>
				<input type='radio' value='Waivered' <?=(isset($Admin_player)?$Admin_player['Waivered']:$Player['Waivered'])==1?"CHECKED":"" ?> name='Waivered' id='Waivered' /><label for='Waivered'>Waivered</label>
				<input type='radio' value='Lawsuit Bait' <?=(isset($Admin_player)?$Admin_player['Waivered']:$Player['Waivered'])==1?"":"CHECKED" ?> name='Waivered' id='NonWaivered' /><label for='NonWaivered'>No Waiver</label>
			</span>
		</div>
		<div>
			<span>Retired:</span>
			<span>
				<input type='radio' value='Active' <?=(isset($Admin_player)?$Admin_player['Active']:$Player['Active'])==1?"CHECKED":"" ?> name='Active' id='Active' /><label for='Active'>Visible</label>
				<input type='radio' value='Inactive' <?=(isset($Admin_player)?$Admin_player['Active']:$Player['Active'])==1?"":"CHECKED" ?> name='Active' id='InActive' /><label for='InActive'>Retired</label>
			</span>
		</div>
		<div class="unimplemented">
			<span style="color:orange; text-align: center; display:inline-block !important;">Notice: Dues can now  <br>be foundin their own section!</span>	
		</div>
		<div class="unimplemented">
			<span>Dues Paid:</span>
			<span class='form-informational-field'><?=$Player['DuesThrough']==0?"No":$Player['DuesThrough'] ?><input type="submit" value="Revoke Dues" name="RemoveDues" disabled="disabled" /></span>
		</div>
		<div class="unimplemented">
			<span>Dues Semesters:</span>
			<span>
				<input type='text' class='' value='<?=isset($Admin_player)?$Admin_player['DuesDate']:$Player['DuesDate'] ?>' name='DuesDate' id='DuesDate'  disabled="disabled"/>
				<input type='text' class='numeric-field integer-field' value='<?=isset($Admin_player)?$Admin_player['DuesSemesters']:$Player['DuesSemesters'] ?>' name='DuesSemesters' id='DuesSemesters' style='float: none;'  disabled="disabled"/>
			</span>
		</div>
		<div>
			<hr/>
		</div>
		<div>
			<span>Reeve Qualified:</span>
			
			<span>
				<input type='radio' value='1' <?=(isset($Admin_player)?$Admin_player['ReeveQualified']:$Player['ReeveQualified'])==1?"CHECKED":"" ?> name='ReeveQualified' id='ReeveQualified' /><label for='ReeveQualified'>Yes</label>
				<input type='radio' value='0' <?=(isset($Admin_player)?$Admin_player['ReeveQualified']:$Player['ReeveQualified'])==0?"CHECKED":"" ?> name='ReeveQualified' id='NotReeveQualified' /><label for='NotReeveQualified'>No</label>
			</span>
		</div>
		<div>
			<span>Reeve Until:</span>
			<span>
				<input type='text' class='' value='<?=isset($Admin_player)?$Admin_player['ReeveQualifiedUntil']:$Player['ReeveQualifiedUntil'] ?>' name='ReeveQualifiedUntil' id='ReeveQualifiedUntil' />
			</span>
		</div>
		<div>
			<span>Corpora Qualified:</span>
			
			<span>
				<input type='radio' value='1' <?=(isset($Admin_player)?$Admin_player['CorporaQualified']:$Player['CorporaQualified'])==1?"CHECKED":"" ?> name='CorporaQualified' id='CorporaQualified' /><label for='CorporaQualified'>Yes</label>
				<input type='radio' value='0' <?=(isset($Admin_player)?$Admin_player['CorporaQualified']:$Player['CorporaQualified'])==0?"CHECKED":"" ?> name='CorporaQualified' id='NotCorporaQualified' /><label for='NotCorporaQualified'>No</label>
			</span>
		</div>
		<div>
			<span>Corpora Until:</span>
			<span>
				<input type='text' class='' value='<?=isset($Admin_player)?$Admin_player['CorporaQualifiedUntil']:$Player['CorporaQualifiedUntil'] ?>' name='CorporaQualifiedUntil' id='CorporaQualifiedUntil' />
		</div>
		<div>
      <span>Park Member Since:</span>
			<span>
				<input type='text' class='' value='<?=isset($Admin_player)?$Admin_player['ParkMemberSince']:$Player['ParkMemberSince'] ?>' name='ParkMemberSince' id='ParkMemberSince' />
			</span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Update Details' name='Update' /></span>
		</div>
		<input type="hidden" name="MAX_FILE_SIZE" value="153600" />
	</form>
</div>

<div class='info-container'>
    <h3>Dues</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/adddues'>
		<div>
			<span>Date Paid:</span>
			<span><input type='text' value='<?= date('Y-m-d'); ?>' name='DuesFrom' id='DuesFrom' /> <i id="showDateUntil"></i></span>
		</div>
		<div>
			<span>Terms:</span>
			<span><input type='text' class='numeric-field' style='float:none;' value='1' name='Terms' id='Terms' /> 1 Term = <?= $KingdomConfig['DuesPeriod']['Value']->Period . ' ' .  $KingdomConfig['DuesPeriod']['Value']->Type . '(s)' ?></span>
		</div>
		<div id='DuesForLife'>
			<span>Dues For Life:</span>
			<span><input type='radio' name='DuesForLife' value='1' >Yes <input type='radio' name='DuesForLife' value='0' checked>No</span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' id='Add' value='Add Dues' /></span>
		</div>
		<input type='hidden' id='MundaneId' name='MundaneId' value='<?=isset($Admin_player)?$Admin_player['MundaneId']:0 ?>' />
		<input type='hidden' id='ParkId' name='ParkId' value='<?=isset($Admin_player)?$Admin_player['ParkId']:$Player['ParkId'] ?>' />
		<input type='hidden' id='KingdomId' name='KingdomId' value='<?=isset($Admin_player)?$Admin_player['KingdomId']:$Player['KingdomId'] ?>' />
	</form>
	<table class='information-table form-container' id='DuesTable'>
		<thead>
			<tr>
				<th>Kingdom</th>
				<th>Park</th>
				<th>Dues Paid Until</th>
				<th>Dues Paid On</th>
				<th>Dues For Life?</th>
				<th>&nbsp;</th>
			</tr>
		</thead>
		<tbody>
			<?php if (is_array($Dues)) foreach ($Dues as $k => $v) : ?>
				<tr class="<?= ($v['Revoked'] == 1) ? 'penalty-box': '' ?>">
					<td><?= $v['KingdomName'] ?></td>
					<td><?= $v['ParkName'] ?></td>
					<td style="<?= ($v['DuesUntil'] >= $v['DuesFrom']) ? 'border: 2px dashed green; background-color: #ccf0cd;' : '' ?>"><?= $v['DuesUntil'] ?></td>
					<td><?= $v['DuesFrom'] ?></td>
					<td style="<?= ($v['DuesForLife'] == 1) ? 'border: 2px dashed green; background-color: #ccf0cd;' : '' ?>"><?= ($v['DuesForLife'] == 1) ? 'Yes':'No' ?></td>
					<td>
						<?php if ($v['Revoked'] == 1): ?>
							Revoked
						<?php else: ?>
							<a class="confirm-revoke-dues" href="<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokedues/<?=$v['DuesId'] ?>">Revoke
						<?php endif; ?>
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
				<th>Quit</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Units['Units'])) $Units['Units'] = array(); ?>
<?php foreach ($Units['Units'] as $key => $unit) : ?>
			<tr>
				<td><a href='<?=UIR ?>Unit/index/<?=$unit['UnitId'] ?>'><?=$unit['Name'] ?></td>
				<td><?=ucfirst($unit['Type']) ?></td>
				<td class='deletion'><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/quitunit/<?=$unit['UnitMundaneId'] ?>'>&times;</a></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>


<div class='info-container'>
	<h3>Classes</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/updateclasses'>
		<table class='information-table' id='Classes'>
			<thead>
				<tr>
					<th>Class</th>
					<th>Credits</th>
					<th>Reconciled</th>
					<th>Level</th>
				</tr>
			</thead>
			<tbody>
<?php if (!is_array($Details['Classes'])) $Details['Classes'] = array(); ?>
<?php foreach ($Details['Classes'] as $key => $detail) : ?>
				<tr>
					<td><?=$detail['ClassName'] ?></td>
					<td class='data-column'><?=$detail['Credits'] + (isset($Admin_player)?$Admin_player['Class_' . $detail['ClassId']]:$detail['Reconciled']) ?></td>
					<td class='data-column'><input class='numeric-field' type='text' value='<?=0 + (isset($Admin_player)?$Admin_player['Reconciled'][$detail['ClassId']]:$detail['Reconciled']) ?>' name='Reconciled[<?=$detail['ClassId'] ?>]' /></td>
					<td class='data-column'><?=abs(min(ceil(($detail['Credits'] + (isset($Admin_player)?$Admin_player['Class_' . $detail['ClassId']]:$detail['Reconciled']))/12),6)) ?></td>
				</tr>
<?php endforeach ?>
				<tr>
					<td colspan='4'><input type='submit' value='Update' /></td>
				</tr>
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
				$(trow).find('td:nth-child(4)').html(level);
			});
		});
	</script></div>

<div id="dialogs" style="display: none">
	<div id="revoke-dues" title="Confirmation Required">
		Are you sure you want to revoke this dues entry?
	</div>
</div>
<script type="text/javascript">
	$(document).ready(function() {
		$(".confirm-revoke-dues").click(function(e) {
			e.preventDefault();
			var targetUrl = $(this).attr("href");

			$( "#revoke-dues" ).dialog({ width: 460,
				buttons: { 
					"Cancel": function() { $(this).dialog("close"); }, 
					"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
				}
			 });
		});
	});

</script>

<script type='text/javascript'>
	function calculateDuesUntil(pdate) {
		$('#showDateUntil').html('');
		var exdate = new Date(pdate.toString());
		if (exdate != "Invalid Date") {
			var terms = ($('#Terms').val()) ? $('#Terms').val() : 1
			<?php if ($KingdomConfig['DuesPeriod']['Value']->Type == 'month'): ?>
				var newDate = new Date(exdate.setMonth(exdate.getMonth() + (parseInt(terms) * parseInt(<?= $KingdomConfig['DuesPeriod']['Value']->Period ?>))));
			<?php endif; ?>
			<?php if ($KingdomConfig['DuesPeriod']['Value']->Type == 'week'): ?>
				var newDate = new Date(exdate.setDate(exdate.getDate() + (parseInt(terms) * parseInt(<?= $KingdomConfig['DuesPeriod']['Value']->Period ?>)))); 
			<?php endif; ?>
			if (parseInt(terms) > 0) {
				$('#showDateUntil').html('through ' + newDate.getFullYear() + '-' + ('0' + newDate.getMonth()).slice(-2) + '-' + ('0' + newDate.getDate()).slice(-2)) ;
			}
		} 
	}

	$(document).ready(function() {
		$( '#DuesFrom' ).datepicker({
			dateFormat: "yy-mm-dd", showMinute: false,
			onSelect: function(selectedDate) {
				$( '#DuesFrom' ).change();
			}
		});
		$('#Terms, #DuesFrom').on('change', function() {
			calculateDuesUntil($('#DuesFrom').val());
		});
		$( '#DuesDate' ).datepicker();
		$( '#ReeveQualifiedUntil' ).datepicker();
		$( '#CorporaQualifiedUntil' ).datepicker();
		$( '#ParkMemberSince' ).datepicker({ dateFormat: "yy-mm-dd", showMinute: false});
		$( '#Cancel' ).hide();
		$( '#Date' ).datepicker({dateFormat: 'yy-mm-dd'});
		$( '#Rank' ).blur(function() {
			rank = $( '#Rank' ).val();
			if (isNaN(rank) || rank < 1 || rank > 10) {
				$( '#Rank' ).val('').fadeOut('slow', function() {
					$( '#Rank' ).css('background-color', '#fff0f0');
					$( '#Rank' ).css('border-color', 'red');
					$( '#Rank' ).fadeIn('slow', function() {
						$( '#Rank' ).animate({ borderColor: '#CCC', backgroundColor: '#fff8c0' }, 'slow' );
					});
				});
			} else {
				$( '#Rank' ).val(Math.round(rank));
			}
		});
		$( "#GivenAt" ).autocomplete({
			source: function( request, response ) {
				park_id = $('#ParkId').val();
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Location',
						type: 'all',
						name: request.term,
						date: $('#Date').val(),
						limit: 8
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.LocationName, value: array2json(val) });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				details = eval("(" + ui.item.value + ")");
				showLabel('#GivenAt', details['ShortName']);
					// Set side-effects
				setSideEffects(details);
				return false;
			}, 
			delay: 250,
			select: function (e, ui) {
				details = eval("(" + ui.item.value + ")");
				showLabel('#GivenAt', details['ShortName']);
					// Set side-effects
				setSideEffects(details);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#GivenBy',null);
					$('#MundaneId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
		$( "#GivenBy" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Player',
						type: 'all',
						search: request.term,
						limit: 6
						<?php // ,kingdom_id: ($('#AwardId').val()>=74||$('#AwardId').val()<=77)?0:<?=$KingdomId ?>
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Persona + ' (' + val.KAbbr + ':' + val.PAbbr + ')', value: val.MundaneId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#GivenBy', ui);
			}, 
			delay: 250,
			select: function (e, ui) {
				showLabel('#GivenBy', ui);
				$('#MundaneId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#GivenBy',null);
					$('#MundaneId').val(null);
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
	});
	
	function setSideEffects(details) {
		$( '#KingdomId' ).val(details['KingdomId']);
		$( '#ParkId' ).val(details['ParkId']);
		$( '#EventId' ).val(details['EventId']);
	}
</script>


<div class='info-container'>
	<h3>Player Operations for <?=$Player['Persona'] ?></h3>
	<ul>
		<li><a href='<?=UIR ?>Unit/create/<?=$Player['MundaneId'] ?>'>Create Company, Household, or Event Group</a></li>
		<li>Events
			<ul>
				<li><a href='<?=UIR ?>Admin/createevent&MundaneId=<?=$Player['MundaneId'] ?>'>Create Event</a></li>
				<li><a href='<?=UIR ?>Admin/manageevent&MundaneId=<?=$Player['MundaneId'] ?>'>Event Templates</a></li>
			</ul>
		</li>
	</ul>
</div>

<div class='info-container'>
    <h3>Historical Imports</h3>
    <table class='information-table form-container' id='Notes'>
		<thead>
			<tr>
				<th>Note</th>
				<th>Description</th>
				<th>Date</th>
    			<th class='deletion'>&times;</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Notes)) foreach ($Notes as $key => $note) : ?>
    		<tr>
				<td><?=$note['Note'] ?></td>
    			<td><?=$note['Description'] ?></td>
    			<td class='form-informational-field' style='text-wrap: nowrap'><?=$note['Date'] . (strtotime($note['DateComplete'])>0?(" - " . $note['DateComplete']):"") ?></td>
    			<td class='deletion'><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/deletenote/<?=$note['NoteId'] ?>'>&times;</a></td>
			</tr>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container' id='award-editor'>
	<h3>Add Award</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/addaward'>
		<div id='AwardType'>
			<span>Type:</span>
			<span><input type='radio' name='awardtype' value='awards' checked>Awards <input type='radio' name='awardtype' value='officers'>Officers</span>
		</div>
		<div>
			<span>Award:</span>
			<span>
				<select name='KingdomAwardId' id='AwardId'>
					<option value=''></option>
<?=$AwardOptions ?>
				</select>
			</span>
		</div>
		<div id='AwardNameField'>
			<span>Award Name:</span>
			<span><input type='text' value='<?=isset($Admin_player)?$Admin_player['AwardName']:$Player['AwardName'] ?>' name='AwardName' id='AwardName' /></span>
		</div>
		<div>
			<span>Rank:</span>
			<span><input type='text' class='numeric-field' style='float:none;' value='<?=isset($Admin_player)?$Admin_player['Rank']:$Player['Rank'] ?>' name='Rank' id='Rank' /></span>
		</div>
		<div>
			<span>Date:</span>
			<span><input type='text' value='<?=isset($Admin_player)?$Admin_player['Date']:$Player['Date'] ?>' name='Date' id='Date' /></span>
		</div>
		<div>
			<span>Given By:</span>
			<span><input type='text' value='<?=isset($Admin_player)?$Admin_player['GivenBy']:$Player['GivenBy'] ?>' name='GivenBy' id='GivenBy' /></span>
		</div>
		<div>
			<span>Given At:</span>
			<span><input type='text' value='<?=isset($Admin_player)?$Admin_player['GivenAt']:$Player['GivenAt'] ?>' name='GivenAt' id='GivenAt' /></span>
		</div>
		<div>
			<span>Given For:</span>
			<span><input type='text' value='<?=isset($Admin_player)?$Admin_player['Note']:$Player['Note'] ?>' name='Note' id='Note' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' id='Add' value='Add' /><button type='button' id='Cancel' value='Cancel'>Cancel</button></span>
		</div>
		<input type='hidden' id='MundaneId' name='MundaneId' value='<?=isset($Admin_player)?$Admin_player['MundaneId']:0 ?>' />
		<input type='hidden' id='ParkId' name='ParkId' value='<?=isset($Admin_player)?$Admin_player['ParkId']:$Player['ParkId'] ?>' />
		<input type='hidden' id='KingdomId' name='KingdomId' value='<?=isset($Admin_player)?$Admin_player['KingdomId']:$Player['KingdomId'] ?>' />
		<input type='hidden' id='EventId' name='EventId' value='<?=isset($Admin_player)?$Admin_player['EventId']:$Player['EventId'] ?>' />
	</form>
</div>

<script type='text/javascript'>

  var awardoptions = "<option value=''></option><?=$AwardOptions ?>";

  var officeroptions = "<option value=''></option><?=$OfficerOptions ?>";

	$(document).ready(function() {
    $( '[name="awardtype"]' ).on('click', function() {
      var awards = awardoptions;
      if ($(this).val() == 'officers') {
        awards = officeroptions;
      }
      $('#AwardId').html(awards);
    });
  
		$( '#Cancel' ).click(function() { Reset(); });
		$( '.deletion a' ).click(function() { Reset(); });
		$( '#AwardNameField' ).hide();
		$( '#AwardId' ).change(function() {
			if ($('#AwardId :selected').text() == 'Custom Award')
				$( '#AwardNameField' ).show();
			else
				$( '#AwardNameField' ).hide();
		})
		$('a.revocation').on('click', function() {
			$(this).attr('href', $(this).attr('href') + $('input[name=revocation]').val());
		});
		$('#burn-it-all').on('submit', function() {
			$('#burn-it-all').attr('action', '<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeallawards/' + $('input[name=revocation]').val());
		});
	});
	
	function Reset() {
		$( '#award-editor h3' ).text('Add Award');
		$( '#award-editor form' ).attr('action', '<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/addaward' );
		$( '#Add' ).val('Add');
		$( '#Cancel' ).hide();
		$( '#AwardId' ).val('');
		$( '#Rank' ).val('');
		$( '#Date' ).val('');
		$( '#GivenBy' ).val('');
		$( '#GivenAt' ).val('');
		$( '#Note' ).val('');
		$( '#MundaneId' ).val('');
		$( '#ParkId' ).val('');
		$( '#KingdomId' ).val('');
		$( '#EventId' ).val('');
	}

	function EditAward(id) {
    return;
        Reset();
		$( '#award-editor h3' ).text('Update Award');
		$( '#award-editor form' ).attr('action', '<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/updateaward/' + id.toString() );
		$( '#Cancel' ).show();
		$( '#Add' ).val('Update');
		$.getJSON(
			"<?=HTTP_SERVICE ?>Search/SearchService.php",
			{
				Action: 'Search/PlayerAward',
				awards_id: id,
			},
			function(data) {
				$( '#AwardId' ).val(data['KingdomAwardId']);
				$( '#Rank' ).val(data['Rank']);
				$( '#Date' ).val(data['Date']);
				$( '#GivenBy' ).val(data['GivenBy']);
				$( '#GivenAt' ).val((data['AtEventId'] > 0)?(data['EventName']):((data['AtParkId']>0)?data['ParkName']:data['KingdomName']));
				$( '#Note' ).val(data['Note']);
				$( '#MundaneId' ).val(data['GivenById']);
				$( '#ParkId' ).val(data['ParkId']);
				$( '#KingdomId' ).val(data['KingdomId']);
				$( '#EventId' ).val(data['EventId']);
			});
	}
</script>

<div class='info-container'>
	<h3>Awards</h3>
	<div class='info-container skip-fold'>
		<div style='padding: 16px 0'>Strip <b>all Awards &amp; Titles</b> or choose from below. Details will be recorded for posterity.</div>
		<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeallawards/' id='burn-it-all'>
			<div>
				<span>Strip Award Details</span>
				<input type="text" name="revocation">
			</div>
			<div>
				<span></span>
				<input type="submit" name="strip-all" value="Strip All Awards">
			</div>
		</form>
	</div>
	<table class='information-table' id='Awards'>
		<thead>
			<tr>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
				<th class='deletion'>&times;</th>
				<th>Strip</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Awards'])) $Details['Awards'] = array(); ?>
<?php foreach ($Details['Awards'] as $key => $detail) : ?>
<?php if (in_array($detail['OfficerRole'], ['none', null]) && $detail['IsTitle'] != 1) : ?>
			<tr onClick='javascript:EditAward(<?=$detail['AwardsId'] ?>)' awardsid='<?=$detail['AwardsId'] ?>' awardid='' rank='' givenby='' parkid='' kingdomid='' eventid=''>
				<td><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?><?=(trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'])!=$detail['Name']?" <i>($detail[Name])</i>":"" ?></td>
				<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
				<td class='award-date'><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$detail['GivenById'] ?>'><?=$detail['GivenBy'] ?></a></td>
				<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
				<td class='award-note'><?=$detail['Note'] ?></td>
				<td class='deletion'><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/deleteaward/<?=$detail['AwardsId'] ?>'>&times;</a></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeaward/<?=$detail['AwardsId'] ?>/' class='revocation'>Strip</a></td>
			</tr>
<?php endif ?>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Titles</h3>
	<div class='info-container skip-fold'>
		<div style='padding: 16px 0'>Strip <b>all Awards &amp; Titles</b> or choose from below. Details will be recorded for posterity.</div>
		<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeallawards/' id='burn-it-all'>
			<div>
				<span>Strip Award Details</span>
				<input type="text" name="revocation">
			</div>
			<div>
				<span></span>
				<input type="submit" name="strip-all" value="Strip All Awards">
			</div>
		</form>
	</div>
	<table class='information-table' id='Awards'>
		<thead>
			<tr>
				<th>Award</th>
				<th>Rank</th>
				<th>Date</th>
				<th>Given By</th>
				<th>Given At</th>
				<th>Note</th>
				<th class='deletion'>&times;</th>
				<th>Strip</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Awards'])) $Details['Awards'] = array(); ?>
<?php foreach ($Details['Awards'] as $key => $detail) : ?>
<?php if (!in_array($detail['OfficerRole'], ['none', null]) || $detail['IsTitle'] == 1) : ?>
			<tr onClick='javascript:EditAward(<?=$detail['AwardsId'] ?>)' awardsid='<?=$detail['AwardsId'] ?>' awardid='' rank='' givenby='' parkid='' kingdomid='' eventid=''>
				<td><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?><?=(trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'])!=$detail['Name']?" <i>($detail[Name])</i>":"" ?></td>
				<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
				<td class='award-date'><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$detail['GivenById'] ?>'><?=$detail['GivenBy'] ?></a></td>
				<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
				<td class='award-note'><?=$detail['Note'] ?></td>
				<td class='deletion'><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/deleteaward/<?=$detail['AwardsId'] ?>'>&times;</a></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeaward/<?=$detail['AwardsId'] ?>/' class='revocation'>Strip</a></td>
			</tr>
<?php endif ?>
<?php endforeach ?>
		</tbody>
	</table>
</div>
