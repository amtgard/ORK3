<div class='info-container'>
	<h3>Create Company or Household</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Unit/create/<?=$MundaneId ?>&Action=create' enctype='multipart/form-data'>
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Unit['Details']['Unit']['HasHeraldry']?$Unit_heraldryurl['Url']:(HTTP_UNIT_HERALDRY.'00000.jpg') ?>' />
				<input type='file' name='Heraldry' class='restricted-image-type' id='Heraldry' />
			</span>
		</div>
		<div>
			<span>Name:</span>
			<span><input type='text' class='name-field required-field' value='<?=htmlentities($Unit['Details']['Unit']['Name'], ENT_QUOTES) ?>' name='Name' /></span>
		</div>
		<div>
			<span>Type:</span>
			<span>
				<select name='Type' class='required-field'>
					<option>Company</option>
					<option>Household</option>
					<option>Event</option>
				</select>
			</span>
		</div>
		<div>
			<span>Url:</span>
			<span><input type='text' value='<?=htmlentities($Unit['Details']['Unit']['Url'], ENT_QUOTES) ?>' name='Url' /></span>
		</div>
		<div>
			<span>Description:</span>
			<span class='form-informational-field'><textarea name='Description' rows=10 cols=50><?=$Unit['Details']['Unit']['Description'] ?></textarea></span>
		</div>
		<div>
			<span>History:</span>
			<span class='form-informational-field'><textarea name='History' rows=10 cols=50><?=$Unit['Details']['Unit']['History'] ?></textarea></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Create Unit' name='CreateUnit' /></span>
		</div>
	</form>
</div>