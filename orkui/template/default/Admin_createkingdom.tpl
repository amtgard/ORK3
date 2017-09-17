<script type='text/javascript'>
	function MangleParentKingdom() {
		ch = $('#IsPrincipality').attr('checked');
		if ($('#IsPrincipality').attr('checked')=='checked') {
			$( '#Sponsor' ).show();
			$( 'Input[Name="CreateKingdom"]' ).val('Create Principality');
		} else {
			$('#ParentKingdomId').val();
			$( '#Sponsor' ).hide();
			$( 'Input[Name="CreateKingdom"]' ).val('Create Kingdom');
			$( '#ParentKingdomId' ).val(0);
			$( '#ParentKingdomName' ).val(0);
		}
	}

	$(document).ready(function() {
		$( '#Sponsor' ).hide();
		$( "#ParentKingdomName" ).autocomplete({
			source: function( request, response ) {
				$.getJSON(
					"<?=HTTP_SERVICE ?>Search/SearchService.php",
					{
						Action: 'Search/Kingdom',
						name: request.term
					},
					function( data ) {
						var suggestions = [];
						$.each(data, function(i, val) {
							suggestions.push({label: val.Name, value: val.KingdomId });
						});
						response(suggestions);
					}
				);
			},
			focus: function( event, ui ) {
				return showLabel('#ParentKingdomName', ui);
			}, 
			delay: 500,
			select: function (e, ui) {
				showLabel('#ParentKingdomName', ui);
				$('#ParentKingdomId').val(ui.item.value);
				return false;
			},
			change: function (e, ui) {
				if (ui.item == null) {
					showLabel('#ParentKingdomName',null);
					$('#ParentKingdomId').val(null);
				}
				return false;
			},
			minLength: 0
		}).focus(function() {
			if (this.value == "")
				$(this).trigger('keydown.autocomplete');
		});
	});
</script>		
		
<div class='info-container'>
	<h3>Create Kingdom</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/createkingdom/submit'  enctype='multipart/form-data'>
		<div>
			<span>Name:</span>
			<span><input type='text' value='<?=$Admin_createkingdom['Name'] ?>' name='Name' class='required-field name-field' /></span>
		</div>
		<div>
			<span>Heraldry:</span>
			<span>
				<input type='file' name='Heraldry' id='Heraldry' />
			</span>
		</div>
		<div>
			<span>Abbreviation:</span>
			<span><input type='text' value='<?=$Admin_createkingdom['Abbreviation'] ?>' class='required-field alphanumeric-field' name='Abbreviation' maxlength="3" /></span>
		</div>
		<div>
			<span>Principality:</span>
			<span><input type='checkbox' onClick='javascript:MangleParentKingdom()' value='YES' <?=$Admin_createkingdom['IsPrincipality']=='YES'?'Checked':'' ?> id='IsPrincipality' /></span>
		</div>
		<div id='Sponsor'>
			<span>Sponsor:</span>
			<span>
				<input type='text' value='<?=$Admin_createkingdom['ParentKingdomName'] ?>' id='ParentKingdomName' />
				<input type='hidden' value='<?=$Admin_createkingdom['ParentKingdomId'] ?>' name='ParentKingdomId' id='ParentKingdomId' />
			</span>
		</div>
		<div>
			<span>Attendance Period Type:</span>
			<span>
				<select name='AttendancePeriodType' class='required-field'>
<?php foreach ($AttendancePeriodType_options as $value => $display): ?>
					<option value='<?=$value ?>' <?=($value==$Admin_createkingdom['AttendancePeriodType']?"SELECTED":"") ?>><?=$display ?></option>
<?php endforeach; ?>
				</select>
			</span>
		</div>
		<div>
			<span>Attendance Weekly Minimum:</span>
			<span><input type='text' value='<?=isset($Admin_createkingdom['AttendanceWeeklyMinimum'])?$Admin_createkingdom['AttendanceWeeklyMinimum']:"2" ?>' class='required-field numeric-field remove-float' name='AttendanceMinimum' /></span>
		</div>
    	<div>
			<span>Attendance Daily Minimum:</span>
			<span><input type='text' value='<?=isset($Admin_createkingdom['AttendanceDailyMinimum'])?$Admin_createkingdom['AttendanceDailyMinimum']:"6" ?>' class='required-field numeric-field remove-float' name='AttendanceMinimum' /></span>
		</div>
		<div>
			<span>Attendance Credit Minimum:</span>
			<span><input type='text' value='<?=isset($Admin_createkingdom['AttendanceCreditMinimum'])?$Admin_createkingdom['AttendanceCreditMinimum']:"9" ?>' class='required-field numeric-field remove-float' name='AttendanceCreditMinimum' /></span>
		</div>
		<div>
			<span>Dues Period Type:</span>
			<span>
				<select name='DuesPeriodType' class='required-field'>
<?php foreach ($DuesPeriodType_options as $value => $display): ?>
					<option value='<?=$value ?>' <?=($value==$Admin_createkingdom['DuesPeriodType']?"SELECTED":"") ?>><?=$display ?></option>
<?php endforeach; ?>
				</select>
			</span>
		</div>
		<div>
			<span>Dues Period:</span>
			<span><input type='text' value='<?=isset($Admin_createkingdom['DuesPeriod'])?$Admin_createkingdom['DuesPeriod']:"6" ?>' class='required-field numeric-field remove-float' name='DuesPeriod' /></span>
		</div>
		<div>
			<span>Dues Amount:</span>
			<span><input type='text' value='<?=isset($Admin_createkingdom['DuesAmount'])?$Admin_createkingdom['DuesAmount']:"6" ?>' class='required-field numeric-field remove-float' name='DuesAmount' /></span>
		</div>
		<div>
			<span>Kingdom Dues Take:</span>
			<span><input type='text' value='<?=isset($Admin_createkingdom['KingdomDuesTake'])?$Admin_createkingdom['KingdomDuesTake']:"1" ?>' class='required-field numeric-field remove-float' name='KingdomDuesTake' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Create Kingdom' name='CreateKingdom' /></span>
		</div>
	</form>
</div>
