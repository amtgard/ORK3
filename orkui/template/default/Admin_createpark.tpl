<div class='info-container'>
	<h3>Create Park</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/createpark/submit'>
		<div>
			<span>Name:</span>
			<span><input type='text' class='name-field required-field' value='<?=$Admin_createpark['Name'] ?>' name='Name' /></span>
		</div>
		<div>
			<span>Abbreviation:</span>
			<span><input type='text' class='alphanumeric-field required-field' maxlength="4" value='<?=$Admin_createpark['Abbreviation'] ?>' name='Abbreviation' /></span>
		</div>
		<div>
			<span>Park Title:</span>
			<span>
				<select name='ParkTitleId' class='required-field'>
<?php foreach ($ParkTitleId_options as $value => $display): ?>
					<option value='<?=$value ?>' <?=($value==$Admin_createpark['ParkTitleId']?"SELECTED":"") ?>><?=$display ?></option>
<?php endforeach; ?>
				</select>
			</span>
		</div>
		<div>
			<span><input type='hidden' name='kingdom_id' value='<?=$KingdomId ?>' /></span>
			<span><input type='submit' value='Create Park' name='CreatePark' /></span>
		</div>
	</form>
</div>