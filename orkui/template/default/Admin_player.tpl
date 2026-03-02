<div class='info-container' id='player-editor'>
	<h3><?=$Player['Persona'] ?></h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/update' enctype="multipart/form-data">
		<div>
			<span>Heraldry:</span>
			<span>
				<span style='position:relative;display:inline-block;'>
					<img class='heraldry-img' src='<?=($Player['HasHeraldry']>0?$Player['Heraldry']:HTTP_PLAYER_HERALDRY . '000000.jpg') . '?t=' . time() ?>' />
<?php if ($Player['HasHeraldry'] > 0 && ($this->__session->user_id == $Player['MundaneId'] || Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_EDIT))) : ?>
					<button type='button' onclick="if(confirm('This will remove the image. This cannot be undone. Continue?')){var f=document.createElement('form');f.method='post';f.action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/removeheraldry';document.body.appendChild(f);f.submit();}" style='position:absolute;top:0;right:0;line-height:1;padding:2px 5px;cursor:pointer;'>&times;</button>
<?php endif; ?>
				</span>
				<br/>
				<input type='file' class='restricted-image-type' name='Heraldry' id='Heraldry' />
			</span>
		</div>
		<div>
			<span>Image:</span>
			<span>
				<span style='position:relative;display:inline-block;'>
					<img class='heraldry-img' src='<?=($Player['HasImage']>0?$Player['Image']:HTTP_PLAYER_HERALDRY . '000000.jpg') . '?t=' . time() ?>' />
<?php if ($Player['HasImage'] > 0 && ($this->__session->user_id == $Player['MundaneId'] || Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_EDIT))) : ?>
					<button type='button' onclick="if(confirm('This will remove the image. This cannot be undone. Continue?')){var f=document.createElement('form');f.method='post';f.action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/removepicture';document.body.appendChild(f);f.submit();}" style='position:absolute;top:0;right:0;line-height:1;padding:2px 5px;cursor:pointer;'>&times;</button>
<?php endif; ?>
				</span>
				<br/>
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
		<div>
			<span></span>
			<span style="font-size: 10px;">Supported Types: JPG, GIF, PNG</span>
		</div>
		<div>
			<span></span>
			<span style="font-size: 10px;">Max Size: 340 KB (348836 bytes)</span>
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
			<span>Pronouns:</span>
			<span>
				<select name="PronounId">
					<option value="">Choose...</option>
					<?php echo (!empty($PronounOptions)) ? $PronounOptions : ''; ?>
				</select>
				<a id="pronoun-picker" href="#">custom</a>
			</span>
			<input id="pronoun_custom" type="hidden" name="PronounCustom" value='<?php echo isset($Admin_player)?$Admin_player['PronounCustom']:$Player['PronounCustom']; ?>' />
		</div>
		<div>
			<span>&nbsp;</span>
			<span id="pselect_display" style="padding-left: 10px;"></span>
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
			<span style="color:orange; text-align: center; display:inline-block !important;">Notice: Dues can now  <br>be found in their own section!</span>	
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
				<td class='deletion'><a class="confirm-remove-unit" href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/quitunit/<?=$unit['UnitMundaneId'] ?>'>&times;</a></td>
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
	<div id="delete-award" title="Confirmation Required">
		Are you sure you want to delete this award entry?
	</div>
	<div id="delete-note" title="Confirmation Required">
		Are you sure you want to delete this Historic Import entry?
	</div>
	<div id="strip-award" title="Confirmation Required">
		Are you sure you want to strip this award entry?
	</div>
	<div id="strip-all" title="Confirmation Required">
		Are you sure you want to strip ALL award entries?
	</div>
	<div id="remove-unit" title="Confirmation Required">
		Are you sure you want to remove this player from the unit?
	</div>
</div>
<div id="reconcile-award-dialog" title="Reconcile Historical Award" style="display:none;">
	<form id="reconcile-form" class="form-container" method="post" action="" style="margin:0;">
		<div>
			<span>Award:</span>
			<span><select name="KingdomAwardId" id="ReconcileAwardId" style="min-width:200px;">
				<option value=""></option>
<?=$AwardOptions ?>
			</select></span>
		</div>
		<div>
			<span>Rank:</span>
			<span><input type="text" class="numeric-field" style="float:none;width:50px;" name="Rank" id="ReconcileRank" /></span>
		</div>
		<div>
			<span>Date:</span>
			<span><span id="ReconcileDateDisplay" style="font-style:italic;color:#666;"></span></span>
		</div>
		<div>
			<span>Given By:</span>
			<span><input type="text" name="GivenByText" id="ReconcileGivenBy" style="width:250px;" /></span>
		</div>
		<div>
			<span>Given At:</span>
			<span><input type="text" name="GivenAtText" id="ReconcileGivenAt" style="width:250px;" /></span>
		</div>
		<div>
			<span>Note:</span>
			<span><input type="text" name="Note" id="ReconcileNote" maxlength="400" style="width:250px;" /></span>
		</div>
		<div>
			<span>Original Note:</span>
			<span id="ReconcileOriginalNote" style="color:#888;font-style:italic;"></span>
		</div>
		<input type="hidden" id="ReconcileAwardsId" name="AwardsId" value="" />
		<input type="hidden" id="ReconcileGivenById" name="GivenById" value="" />
		<input type="hidden" id="ReconcileParkId" name="ParkId" value="" />
		<input type="hidden" id="ReconcileKingdomId" name="KingdomId" value="" />
		<input type="hidden" id="ReconcileEventId" name="EventId" value="" />
		<input type="hidden" id="ReconcileHiddenDate" name="Date" value="" />
	</form>
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
		$(".confirm-delete-award").click(function(e) {
			e.preventDefault();
			var targetUrl = $(this).attr("href");

			$( "#delete-award" ).dialog({ width: 460,
				buttons: { 
					"Cancel": function() { $(this).dialog("close"); }, 
					"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
				}
			 });
		});
		$(".confirm-delete-note").click(function(e) {
			e.preventDefault();
			var targetUrl = $(this).attr("href");

			$( "#delete-note" ).dialog({ width: 460,
				buttons: { 
					"Cancel": function() { $(this).dialog("close"); }, 
					"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
				}
			 });
		});
		$(".confirm-strip-award").click(function(e) {
			e.preventDefault();
			var targetUrl = $(this).attr("href");

			$( "#strip-award" ).dialog({ width: 460,
				buttons: { 
					"Cancel": function() { $(this).dialog("close"); }, 
					"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
				}
			 });
		});
		$(".confirm-remove-unit").click(function(e) {
			e.preventDefault();
			var targetUrl = $(this).attr("href");

			$( "#remove-unit" ).dialog({ width: 460,
				buttons: { 
					"Cancel": function() { $(this).dialog("close"); }, 
					"Confirm": function() { window.location.href = targetUrl; $(this).dialog("close"); } 
				}
			 });
		});
		$("form input[name=strip-all]").click(function(e) {
			e.preventDefault();
			console.log('strip butto clicked');
			var thisBtn = $(e.target);

			$( "#strip-all" ).dialog({ width: 460,
				buttons: { 
					"Cancel": function() { $(this).dialog("close"); }, 
					"Confirm": function() { thisBtn.closest("form").submit(); $(this).dialog("close"); } 
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
					$('#GivenById').val('');
				}
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
		var givenBySelected = false;
		var preloadedOfficers = [
<?php if (is_array($PreloadOfficers)) foreach ($PreloadOfficers as $officer) : ?>
			{label: <?=json_encode($officer['Persona'] . ' (' . $officer['Role'] . ')') ?>, value: <?=intval($officer['MundaneId']) ?>},
<?php endforeach; ?>
		];
		$( "#GivenBy" ).autocomplete({
			minLength: 0,
			source: function( request, response ) {
				if (request.term === '') {
					response(preloadedOfficers.concat([{label: '...or start typing to search.', value: -1}]));
					return;
				}
				park_id = $('#ParkId').val();
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
				if (ui.item.value === -1) return false;
				return showLabel('#GivenBy', ui);
			},
			delay: 250,
			select: function (e, ui) {
				if (ui.item.value === -1) return false;
				showLabel('#GivenBy', ui);
				$('#GivenById').val(ui.item.value);
				givenBySelected = true;
				checkRequiredFields();
				return false;
			},
			change: function (e, ui) {
				if (!givenBySelected) {
					showLabel('#GivenBy',null);
					$('#GivenById').val('');
				}
				givenBySelected = false;
				checkRequiredFields();
				return false;
			}
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
		checkRequiredFields();
	});

	function setSideEffects(details) {
		$( '#KingdomId' ).val(details['KingdomId']);
		$( '#ParkId' ).val(details['ParkId']);
		$( '#EventId' ).val(details['EventId']);
	}
	function checkRequiredFields() {
		var hasAward = $('#AwardId').val() !== '' && $('#AwardId').val() !== null;
		var hasGivenBy = $('#GivenById').val() !== '' && $('#GivenById').val() !== null && $('#GivenById').val() > 0;
		$('#AddAward').prop('disabled', !(hasAward && hasGivenBy));
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
    			<td class='deletion'><a class="confirm-delete-note" href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/deletenote/<?=$note['NoteId'] ?>'>&times;</a></td>
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
		<div id='AwardRankField'>
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
			<span><input type='submit' id='AddAward' value='Add' disabled /><button type='button' id='Cancel' value='Cancel'>Cancel</button></span>
		</div>
		<input type='hidden' id='MundaneId' name='MundaneId' value='<?=$Player['MundaneId'] ?>' />
		<input type='hidden' id='GivenById' name='GivenById' value='<?=isset($Admin_player)?$Admin_player['GivenById']:'' ?>' />
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
			checkRequiredFields();
		})
		$( '[name="awardtype"]'  ).change(function() {
			if($(this).val() == 'officers'){
				$( '#AwardNameField' ).hide();
				$( '#AwardRankField' ).hide();
			}else{
				$( '#AwardRankField' ).show();
			}
		});
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
		$( '#AddAward' ).val('Add');
		$( '#Cancel' ).hide();
		$( '#AwardId' ).val('');
		$( '#Rank' ).val('');
		$( '#Date' ).val('');
		$( '#GivenBy' ).val('');
		$( '#GivenAt' ).val('');
		$( '#Note' ).val('');
		$( '#MundaneId' ).val('<?=$Player['MundaneId'] ?>');
		$( '#GivenById' ).val('');
		$( '#ParkId' ).val('');
		$( '#KingdomId' ).val('');
		$( '#EventId' ).val('');
		checkRequiredFields();
	}

	function EditAward(id) {
    return;
        Reset();
		$( '#award-editor h3' ).text('Update Award');
		$( '#award-editor form' ).attr('action', '<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/updateaward/' + id.toString() );
		$( '#Cancel' ).show();
		$( '#AddAward' ).val('Update');
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
				$( '#GivenById' ).val(data['GivenById']);
				$( '#ParkId' ).val(data['ParkId']);
				$( '#KingdomId' ).val(data['KingdomId']);
				$( '#EventId' ).val(data['EventId']);
			});
	}

	function OpenReconcileDialog(awardsId, awardDate, currentNote, currentKingdomAwardId, currentRank) {
		$('#ReconcileAwardsId').val(awardsId);
		$('#ReconcileHiddenDate').val(awardDate);
		$('#ReconcileDateDisplay').text(awardDate);
		$('#ReconcileAwardId').val(currentKingdomAwardId);
		$('#ReconcileRank').val(currentRank > 0 ? currentRank : '');
		$('#ReconcileNote').val(currentNote);
		$('#ReconcileOriginalNote').text(currentNote);
		$('#ReconcileGivenBy').val('');
		$('#ReconcileGivenById').val('');
		$('#ReconcileGivenAt').val('');
		$('#ReconcileParkId').val('');
		$('#ReconcileKingdomId').val('');
		$('#ReconcileEventId').val('');
		$('#reconcile-form').attr('action', '<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/reconcileaward/' + awardsId);

		$('#reconcile-award-dialog').dialog({
			width: 560,
			modal: true,
			buttons: {
				'Save': function() {
					$('#reconcile-form').submit();
					$(this).dialog('close');
				},
				'Cancel': function() {
					$(this).dialog('close');
				}
			}
		});
	}

	$(document).ready(function() {
		var reconcileGivenBySelected = false;

		$('#ReconcileGivenBy').autocomplete({
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
				$('#ReconcileGivenBy').val(ui.item.display);
				return false;
			},
			select: function(e, ui) {
				$('#ReconcileGivenBy').val(ui.item.display);
				$('#ReconcileGivenById').val(ui.item.value);
				reconcileGivenBySelected = true;
				return false;
			},
			change: function(e, ui) {
				if (!reconcileGivenBySelected) {
					$('#ReconcileGivenById').val('');
				}
				reconcileGivenBySelected = false;
				return false;
			}
		});

		$('#ReconcileGivenAt').autocomplete({
			source: function(request, response) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{ Action: 'Search/Location', type: 'all', name: request.term, date: $('#ReconcileHiddenDate').val(), limit: 8 },
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
				$('#ReconcileGivenAt').val(details['ShortName']);
				$('#ReconcileParkId').val(details['ParkId']);
				$('#ReconcileKingdomId').val(details['KingdomId']);
				$('#ReconcileEventId').val(details['EventId']);
				return false;
			},
			select: function(e, ui) {
				var details = eval('(' + ui.item.value + ')');
				$('#ReconcileGivenAt').val(details['ShortName']);
				$('#ReconcileParkId').val(details['ParkId']);
				$('#ReconcileKingdomId').val(details['KingdomId']);
				$('#ReconcileEventId').val(details['EventId']);
				return false;
			}
		});
	});
</script>

<div class='info-container'>
	<h3>Awards<?php if (!empty(array_filter((array)$Details['Awards'], function($a){ return $a['IsHistorical']; }))): ?> <a href='<?=UIR ?>Reconcile/index/<?=$Player['MundaneId'] ?>' style='font-size:0.7em;font-weight:normal;margin-left:12px;'>[Bulk Reconcile Historical Awards &rarr;]</a><?php endif ?></h3>
	<div class='info-container skip-fold'>
		<div style='padding: 16px 0'>Strip <b>all Awards &amp; Titles</b> or choose from below. Details will be recorded for posterity.</div>
		<form class='form-container' method='post' action='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeallawards/' id='burn-it-all'>
			<div>
				<span>Strip Awards Reason</span>
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
				<th>Reconcile</th>
				<th class='deletion'>&times;</th>
				<th>Strip</th>
			</tr>
		</thead>
		<tbody>
<?php if (!is_array($Details['Awards'])) $Details['Awards'] = array(); ?>
<?php foreach ($Details['Awards'] as $key => $detail) : ?>
<?php if (in_array($detail['OfficerRole'], ['none', null]) && $detail['IsTitle'] != 1) : ?>
			<tr onClick='javascript:EditAward(<?=$detail['AwardsId'] ?>)' awardsid='<?=$detail['AwardsId'] ?>' awardid='' rank='' givenby='' parkid='' kingdomid='' eventid=''<?=$detail['IsHistorical']?" style='background-color:#fffbe6;'":'' ?>>
				<td><?=trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'] ?><?=(trimlen($detail['CustomAwardName'])>0?$detail['CustomAwardName']:$detail['KingdomAwardName'])!=$detail['Name']?" <i>($detail[Name])</i>":"" ?></td>
				<td><?=valid_id($detail['Rank'])?$detail['Rank']:'' ?></td>
				<td class='award-date'><?=strtotime($detail['Date'])>0?$detail['Date']:'' ?></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$detail['GivenById'] ?>'><?=$detail['GivenBy'] ?></a></td>
				<td><?=trimlen($detail['ParkName'])>0?"$detail[ParkName], $detail[KingdomName]":(valid_id($detail['EventId'])?"$detail[EventName]":"$detail[KingdomName]") ?></td>
				<td class='award-note'><?=$detail['Note'] ?></td>
				<td><?php if ($detail['IsHistorical']): ?><button type='button' class='reconcile-award-btn' onclick='event.stopPropagation(); OpenReconcileDialog(<?=$detail['AwardsId'] ?>, <?=json_encode($detail['Date']) ?>, <?=json_encode($detail['Note']) ?>, <?=$detail['KingdomAwardId'] ?>, <?=intval($detail['Rank']) ?>)'>Reconcile</button><?php endif ?></td>
				<td class='deletion'><a class="confirm-delete-award" href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/deleteaward/<?=$detail['AwardsId'] ?>'>&times;</a></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeaward/<?=$detail['AwardsId'] ?>/' class='confirm-strip-award revocation'>Strip</a></td>
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
				<span>Strip Awards Reason</span>
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
				<td class='deletion'><a class="confirm-delete-award" href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/deleteaward/<?=$detail['AwardsId'] ?>'>&times;</a></td>
				<td><a href='<?=UIR ?>Admin/player/<?=$Player['MundaneId'] ?>/revokeaward/<?=$detail['AwardsId'] ?>/' class='confirm-strip-award revocation'>Strip</a></td>
			</tr>
<?php endif ?>
<?php endforeach ?>
		</tbody>
	</table>
</div>

<script type='text/javascript'>
/*Example popup code to show functionality of the popup function*/
var buttons = [
	{text:"Select", value:true, class:"ok", checkForm:true},
	{text:"Cancel", value:false, class:"no"},
	{text:"Need help?", value:"yolo", class:"help", close:false}
];

<?php $pronoun_custom_arr = isset($Admin_player)? json_decode($Admin_player['PronounCustom']) : json_decode($Player['PronounCustom']);
$curr_custom_pronoun_txt = $Player['PronounCustomText'];
 ?>

var pnform = `
	<div class="pchoice">
		<span>Subjective</span>
		<select name="p_subject" multiple>
			<option value="">Choose...</option>
			<?php if (!empty($PronounList)): ?>
				<?php foreach($PronounList['subjective'] as $s): ?>
					<?php $selected = (!empty($pronoun_custom_arr) && ( $pronoun_custom_arr->s == $s['value']) || (is_array($pronoun_custom_arr->s) &&  in_array($s['value'], $pronoun_custom_arr->s))) ? 'selected' : ''; ?>
					<option value="<?php echo $s['value']; ?>" <?php echo $selected; ?>><?php echo $s['display']; ?></option>
				<?php endforeach; ?>
			<?php endif ?>
		</select>
		<div style="clear:both;"></div>
	</div>
	<div class="pchoice">
		<span>Objective</span>
		<select name="p_object" multiple>
			<option value="">Choose...</option>
			<?php if (!empty($PronounList)): ?>
				<?php foreach($PronounList['objective'] as $s): ?>
					<?php $selected = (!empty($pronoun_custom_arr) && ( $pronoun_custom_arr->o == $s['value']) || (is_array($pronoun_custom_arr->o) &&  in_array($s['value'], $pronoun_custom_arr->o))) ? 'selected' : ''; ?>
					<option value="<?php echo $s['value']; ?>" <?php echo $selected; ?>><?php echo $s['display']; ?></option>
				<?php endforeach; ?>
			<?php endif ?>
		</select>
		<div style="clear:both;"></div>
	</div>
	<div class="pchoice">
		<span>Possessive determininer</span>
		<select name="p_possessive" multiple>
			<option value="">Choose...</option>
			<?php if (!empty($PronounList)): ?>
				<?php foreach($PronounList['possessive'] as $s): ?>
					<?php $selected = (!empty($pronoun_custom_arr) && ( $pronoun_custom_arr->p == $s['value']) || (is_array($pronoun_custom_arr->p) &&  in_array($s['value'], $pronoun_custom_arr->p))) ? 'selected' : ''; ?>
					<option value="<?php echo $s['value']; ?>" <?php echo $selected; ?>><?php echo $s['display']; ?></option>
				<?php endforeach; ?>
			<?php endif ?>
		</select>
		<div style="clear:both;"></div>
	</div>
	<div class="pchoice">
		<span>Possessive pronoun</span>
		<select name="p_possessivepronoun" multiple>
			<option value="">Choose...</option>
			<?php if (!empty($PronounList)): ?>
				<?php foreach($PronounList['possessivepronoun'] as $s): ?>
					<?php $selected = (!empty($pronoun_custom_arr) && ( $pronoun_custom_arr->pp == $s['value']) || (is_array($pronoun_custom_arr->pp) &&  in_array($s['value'], $pronoun_custom_arr->pp))) ? 'selected' : ''; ?>
					<option value="<?php echo $s['value']; ?>" <?php echo $selected; ?>><?php echo $s['display']; ?></option>
				<?php endforeach; ?>
			<?php endif ?>
		</select>
		<div style="clear:both;"></div>
	</div>
	<div class="pchoice">
		<span>Reflexive</span>
		<select name="p_reflexive" multiple>
			<option value="">Choose...</option>
			<?php if (!empty($PronounList)): ?>
				<?php foreach($PronounList['reflexive'] as $s): ?>
					<?php $selected = (!empty($pronoun_custom_arr) && ( $pronoun_custom_arr->r == $s['value']) || (is_array($pronoun_custom_arr->r) &&  in_array($s['value'], $pronoun_custom_arr->r))) ? 'selected' : ''; ?>
					<option value="<?php echo $s['value']; ?>" <?php echo $selected; ?>><?php echo $s['display']; ?></option>
				<?php endforeach; ?>
			<?php endif ?>
		</select>
		<div style="clear:both;"></div>
	</div>
	`;

var example = new popup(buttons, "Pronoun Picker", pnform);
var save_pronoun_custom = $('#pronoun_custom').val() || null;
if (save_pronoun_custom != null) {
	$('#pselect_display').text('<?php echo $curr_custom_pronoun_txt; ?>');

}
example.draggable(true);
example.addClass("example");
$('#pronoun-picker').on('click', function (e) {
	e.preventDefault();
	example.open(function(r, f) {
		if (r == false) return ;
		if (r == true) {
			var blip = '';
			var blip2 = '';
			var blip3 = '';
			var blip4 = '';
			var blip5 = '';
			var anySelected = false;
			$('select[name=p_subject] option:selected').each(function(i, el) {if(!$(el).val()){return;}anySelected = true;blip += (i != 0) ? '/' + $(el).text() : $(el).text()});
			$('select[name=p_object] option:selected').each(function(i, el) {if(!$(el).val()){return;} anySelected = true; blip2 += (i != 0) ? '/' + $(el).text() : $(el).text()})
			$('select[name=p_possessive] option:selected').each(function(i, el) {if(!$(el).val()){return;} anySelected = true; blip3 += (i != 0) ? '/' + $(el).text() : $(el).text()})
			$('select[name=p_possessivepronoun] option:selected').each(function(i, el) {if(!$(el).val()){return;} anySelected = true; blip4 += (i != 0) ? '/' + $(el).text() : $(el).text()})
			$('select[name=p_reflexive] option:selected').each(function(i, el) {if(!$(el).val()){return;}anySelected = true;blip5 += (i != 0) ? '/' + $(el).text() : $(el).text()});
			if (anySelected) {
				$('#pselect_display').text(blip + ' [' + blip2 + ' ' + blip3 + ' ' + blip4 + ' ' + blip5 + ']');
				$('#pronoun_custom').val(JSON.stringify(
					{
						s: $('select[name=p_subject]').val() || ['0'],
						o: $('select[name=p_object]').val() || ['0'],
						p: $('select[name=p_possessive]').val() || ['0'],
						pp: $('select[name=p_possessivepronoun]').val() || ['0'],
						r: $('select[name=p_reflexive]').val() || ['0']
					}));
			} else {
				$('#pselect_display').text("");
				$('#pronoun_custom').val("");
			}
		}
		if(r == "yolo") {
			var whut = new popup([{text: "Close"}], "Need help?", "<p>If you don't see your pronouns, let us know on the <a href=\"https://www.facebook.com/groups/orkupdates\" target=\"_blank\">ORK Facebook Group</a></p>");
			whut.draggable(true);
			whut.open();
		}
	});
});

/*Popup function*/
function popup(buttons, title, html) {
	var popup_html = "<div class=\"popup_wrapper\"><form class=\"popup\">";
	if(title) {
		popup_html += "<h2 class=\"title\">"+title+"</h2>";
	}
	if(html) {
		popup_html += html;
	}
	if(buttons) {
		popup_html += "<div class=\"buttons\">";
		for(var x = 0; x < buttons.length; x++) {
			var bClass = buttons[x]["class"] ? " class="+buttons[x]["class"]:"";
			var bCheckForm = buttons[x]["checkForm"] ? " data-checkForm="+buttons[x]["checkForm"]:"";
			var bClose = buttons[x]["close"] === false ? " data-close="+buttons[x]["close"]:"";
			var bValue = buttons[x]["value"] !== undefined	? " data-value="+buttons[x]["value"]:"";
			var bText = buttons[x]["text"] || "";
			popup_html += "<button"+bClass+bClose+bCheckForm+bValue+">"+bText+"</button>";
		}
		popup_html += "</div>";
	}
	popup_html += "</form></div>";
	var popup = $(popup_html);
	var form = popup.children("form");
	var top;
	function open() {
		$("body").append(popup);
		popup.fadeIn(500);
		top = $("body").scrollTop();
		$("html").css({"position":"fixed", "top":-top});
	}
	function close() {
		popup.fadeOut(200, function() {
			popup.remove();
			$("html").css({"position":"static", "top":0});
			$("html, body").scrollTop(top);
		});
	}
	this.open = function(f) {
		var r = $.Deferred();
		open();
		var closed = false;
		popup.on("click", ".buttons button", function(e) {
			e.preventDefault();
			var value = $(this).data("value");
			var checkForm = $(this).data("checkForm");
			var autoClose = $(this).data("close");
			if(!form[0].checkValidity() && checkForm) {
				$('<input type="submit">').hide().appendTo(form).click().remove();
			} else {
				if(!closed) {
					r.notify(value, form);
				}
				if(autoClose !== false) {
					close();
					closed = true;
				}
			}
		});
		return r.progress(f);
	};
	this.close = function() {
		close();
	};
	this.addClass = function(fClass) {
		$(popup).addClass(fClass);
	};
	this.removeClass = function(fClass) {
		$(popup).removeClass(fClass);
	};
	this.draggable = function(fDraggable) {
		if(fDraggable) {
			draggable = true;
			form.children(".title").css("cursor", "move");
		} else {
			draggable = false;
			form.children(".title").css("cursor", "inherit");
		}
	};
	var draggable = false;
	var dragging = false;
	var fX;
	var fX;
	var y;
	var x;
	form.children(".title").on("mousedown touchstart", function(e) {
		if (draggable) {
			dragging = true;
			fY = form.offset().top;
			fX = form.offset().left;
			console.log(e);
			y = e.pageY || e.originalEvent.touches[0].pageY;
			x = e.pageX || e.originalEvent.touches[0].pageX;
			form.css("user-select", "none");
		}
	});
	$("html").on("mousemove touchmove", function(e) {
		if (dragging && draggable) {
			mY = e.pageY || e.originalEvent.touches[0].pageY;
			mX = e.pageX || e.originalEvent.touches[0].pageX;
			form.offset({
				top: fY + mY - y,
				left: fX + mX - x
			});
			if(form.offset().top < 0) {
				form.offset({top: 0});
			}
			if(form.offset().left < 0) {
				form.offset({left: 0});
			}
			if(popup.height() - form.offset().top - form.outerHeight() < 0) {
				form.offset({top: popup.height() - form.outerHeight()});
			}
			if(popup.width() - form.offset().left - form.outerWidth() < 0) {
				form.offset({left: popup.width() - form.outerWidth()});
			}
		}
	});
	$("html").on("mouseup touchend", function(e) {
		if (draggable) {
			dragging = false;
			form.css("user-select", "inherit");
		}
	});
}
</script>

<style>
html {
	overflow-y: scroll;
	/* Important for the code that disables scrolling */
	height: 100%;
	width: 100%;
}
.popup_wrapper {
	background: rgba(232,232,232,.8);
	z-index: 9999;
	overflow-y: scroll;
	position: fixed;
	top: 0;
	left: 0;
	bottom: 0;
	right: 0;
	/*Hide scrollbar*/
	right: auto;
	padding-right: 20px;
	width: 100%;
	/*Fallback*/
	text-align: center;
	white-space: nowrap;
	font-size: 0;
	/*Flexbox*/
	display: flex;
	justify-content: center;
	align-items: center;
}
.popup_wrapper:before {
	/*Fallback*/
	content: "";
	display: inline-block;
	height: 100%;
	vertical-align: middle;
}
.popup_wrapper .popup {
	background: #fff;
	width: 400px;
	border-radius: 3px;
	box-shadow: 0 5px 20px rgba(0,0,0,.1);
	border: 1px solid rgba(0,0,0,.03);
	background-clip: padding-box;
	margin: 20px;
	font-family: 'Roboto', sans-serif;
	/*Fallback*/
	display: inline-block;
	vertical-align: middle;
	text-align: initial;
	font-size: initial;
	white-space: initial;
	/*IE6-9 doesn't support initial*/
	text-align: left\9;
	font-size: 16px\9;
	white-space: normal\9;
}
/*IE10+ doesn't support initial*/
_:-ms-lang(x),
.popup {
	text-align: left;
	font-size: 16px;
	white-space: normal;
}

.popup div.pchoice {
	margin-left: 25px;
	padding: 10px 40px 10px 0;
}

.popup div.pchoice select {
	float: right;
}

.popup_wrapper .popup .title {
	font-size: 18px;
	color: #444;
	line-height: 64px;
	padding: 0 20px;
	margin-bottom: 10px;
}
.popup_wrapper .popup p {
	font-size: 16px;
	color: #777;
	line-height: 32px;
	padding: 0 20px;
}
.popup_wrapper .popup .buttons button {
	font-family: 'Roboto', sans-serif;
	font-size: 14px;
	font-weight: 700;
	color: #777;
	line-height: 36px;
	padding: 0 10px;
	margin: 20px 20px 20px 0;
	border: 0;
	border-radius: 3px;
	background: none;
	float: right;
	cursor: pointer;
	outline: 0;
}
.popup_wrapper .popup .buttons button.help {
	float: left;
}
.popup_wrapper .popup .buttons button:hover {
	background: #eee;
}
.popup_wrapper .popup .buttons button:active {
	background: #ddd;
}
.popup_wrapper .popup .buttons button.ok {
	color: #176299;
}
.popup_wrapper .popup .buttons button.no {
	color: #ff0000;
}
</style>