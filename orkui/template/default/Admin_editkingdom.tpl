<div class='info-container'>
	<h3>Edit <?=$this->__session->kingdom_name ?></h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editkingdom/<?=$Kingdom_data['KingdomId'] ?>&Action=details' enctype='multipart/form-data'>
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Kingdom_heraldryurl['Url'] . '?t=' . time() ?>' />
				<input type='file' class='restricted-image-type' name='Heraldry' id='Heraldry' />
			</span>
		</div>
		<div>
			<span>Name:</span>
			<span><input type='text' class='name-field required-field' value='<?=$Kingdom_data['KingdomName'] ?>' name='Name' /></span>
		</div>
		<div>
			<span>Abbreviation:</span>
			<span><input type='text' maxlength="4" class='alphanumeric-field required-field' value='<?=$Kingdom_data['Abbreviation'] ?>' name='Abbreviation' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Edit <?=$IsPrinz?'Principality':'Kingdom' ?>' name='EditKingdom' /></span>
		</div>
	</form>
</div>

<style type='text/css'>
	.config-input {
		width: 100%;
		display: table-row;
	}
	.config-input>input, .config-input>select, .config-input>span {
		display: table-cell;
	}
</style>
<div class='info-container'>
	<h3>Configuration</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editkingdom/<?=$Kingdom_data['KingdomId'] ?>&Action=config'>
		<table class='information-table'>
			<thead>
				<tr>
					<th>Configuration</th>
					<th>Setting</th>
				</tr>
			</thead>
			<tbody>
<?php foreach($Kingdom_config as $key => $config) : ?>
<?php 	if (1 == $config['UserSetting']) : ?>
				<tr>
					<td><?=$config['Key'] ?></td>
					<td>
<?php 		if (is_object($config['Value'])) : ?>
<?php 			foreach (get_object_vars($config['Value']) as $v_key => $v_value) : ?>
<?php				if (is_object($config['AllowedValues']) && array_key_exists($v_key, get_object_vars($config['AllowedValues']))) : ?>
						<div class='config-input'>
							<span><!--<?=$v_key ?>--></span>
							<select name='Config[<?=$config['ConfigurationId'] ?>][<?=$v_key ?>]' value='<?=$v_value ?>' />
								<option> -</option>
<?php					foreach ($config['AllowedValues']->$v_key as $a_key => $a_value) : ?>
								<option <?=$a_value==$v_value?'SELECTED':'' ?> ><?=$a_value ?></option>
<?php					endforeach; ?>
							</select>
						</div>
<?php				else : ?>
						<div class='config-input'><span><!--<?=$v_key ?>--></span><input type='text' class='numeric-field remove-float' name='Config[<?=$config['ConfigurationId'] ?>][<?=$v_key ?>]' value='<?=$v_value ?>' /></div>
<?php				endif; ?>
<?php			endforeach; ?>
<?php 		elseif ($config['Type'] == 'number'): ?>
						<input type='text' class='numeric-field remove-float' name='Config[<?=$config['ConfigurationId'] ?>]' value='<?=$config['Value'] ?>' />
<?php     	else : ?>
						<input type='text' class='' name='Config[<?=$config['ConfigurationId'] ?>]' value='<?=$config['Value'] ?>' />
<?php 		endif; ?>
					</td>
				</tr>
<?php 	endif; ?>
<?php endforeach; ?>
				<tr>
        			<td><button type='button' id='add-config'>Add Config</button></td>
					<td><input type='submit' value='Update Config' /></td>
				</tr>
			</tbody>
		</table>
	</form>
</div>

<div class='info-container'>
	<h3>Park Titles</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editkingdom/<?=$Kingdom_data['KingdomId'] ?>&Action=parktitles'>
		<table class='information-table'>
			<thead>
				<tr>
					<th>Park Title</th>
					<th>Park Class</th>
					<th>Principality</th>
					<th>Minimum</th>
					<th>Cutoff</th>
					<th>Term</th>
					<th>Periods</th>
					<th class='deletion'>&times;</th>
				</tr>
			</thead>
			<tbody>
<?php foreach($Kingdom_parktitles as $key => $title) : ?>
				<tr>
					<td><input type='text' value='<?=$title['Title'] ?>' name='Title[<?=$title['ParkTitleId'] ?>]' /></td>
					<td><input type='text' class='numeric-field' value='<?=$title['Class'] ?>' name='Class[<?=$title['ParkTitleId'] ?>]' /></td>
					<td><input type='text' class='numeric-field' value='<?=$title['MinimumAttendance'] ?>' name='MinimumAttendance[<?=$title['ParkTitleId'] ?>]' /></td>
					<td><input type='text' class='numeric-field' value='<?=$title['MinimumCutoff'] ?>' name='MinimumCutoff[<?=$title['ParkTitleId'] ?>]' /></td>
					<td>
						<select name='Period[<?=$title['ParkTitleId'] ?>]'>
							<option value='month'>Month</option>
							<option value='week'>Week</option>
						</select>
					</td>
					<td><input type='text' class='numeric-field' value='<?=$title['Length'] ?>' name='Length[<?=$title['ParkTitleId'] ?>]' /></td>
					<td class='deletion'><a href='<?=UIR ?>Admin/editkingdom/<?=$Kingdom_data['KingdomId'] ?>&Action=deletetitle&ParkTitleId=<?=$title['ParkTitleId'] ?>'>&times;</a></td>
				</tr>
<?php endforeach; ?>
				<tr>
					<td><input type='text' value='' name='Title[New]' /></td>
					<td><input type='text' class='numeric-field' value='' name='Class[New]' /></td>
					<td><input type='text' class='numeric-field' value='' name='MinimumAttendance[New]' /></td>
					<td><input type='text' class='numeric-field' value='' name='MinimumCutoff[New]' /></td>
					<td>
						<select name='Period[New]'>
							<option value='month'>Month</option>
							<option value='week'>Week</option>
						</select>
					</td>
					<td><input type='text' class='numeric-field' name='Length[New]' /></td>
					
				</tr>
				<tr>
					<td colspan=8><input type='submit' value='Update Titles' ?></td>
				</tr>
			</tbody>
		</table>
	</form>
</div>

<div class='info-container'>
	<h3>Awards</h3>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editkingdom/<?=$Kingdom_data['KingdomId'] ?>&Action=awards'>
		<table class='information-table'>
			<thead>
				<tr>
					<th>Award</th>
					<th>Kingdom Name</th>
					<th>Reign Limit</th>
					<th>Month Limit</th>
					<th>Title</th>
					<th>Title Class</th>
					<th class='deletion'>&times;</th>
				</tr>
			</thead>
			<tbody>
<?php foreach($Kingdom_awards as $key => $award) : ?>
				<tr>
					<td><?=$award['AwardName'] ?></td>
					<td><input type='text' class='name-field' value="<?=$award['KingdomAwardName'] ?>" name='KingdomAwardName[<?=$award['KingdomAwardId'] ?>]' /></td>
					<td><input type='text' class='numeric-field' value='<?=$award['ReignLimit'] ?>' name='ReignLimit[<?=$award['KingdomAwardId'] ?>]' /></td>
					<td><input type='text' class='numeric-field' value='<?=$award['MonthLimit'] ?>' name='MonthLimit[<?=$award['KingdomAwardId'] ?>]' /></td>
					<td><input type='checkbox' value='1' name='IsTitle[<?=$award['KingdomAwardId'] ?>]' <?=$award['IsTitle']==1?'CHECKED':'' ?> <?=valid_id($award['AwardId'])?'DISABLED':''?> /></td>
					<td><input type='text' class='numeric-field' value='<?=$award['IsTitle']==1?$award['TitleClass']:'' ?>' name='TitleClass[<?=$award['KingdomAwardId'] ?>]' <?=($award['IsTitle']==1)?'':'DISABLED' ?> /></td>
					<?php if (! valid_id($award['AwardId'])) : ?>
					<td class='deletion'><a href='<?=UIR ?>Admin/editkingdom/<?=$Kingdom_data['KingdomId'] ?>&Action=deleteaward&KingdomAwardId=<?=$award['KingdomAwardId'] ?>'>&times;</a></td>
					<?php else : ?>
					<td></td>
					<?php endif; ?>
				</tr>
<?php endforeach; ?>
				<tr>
					<td>
						<select name='AwardId'>
							<option value='0'>  - None -  </option>
<?php foreach($Canonical_awards['Awards'] as $key => $award) : ?>
							<option value='<?=$award['AwardId'] ?>'><?=$award['AwardName'] ?></option>
<?php endforeach; ?>
						</select>
					</td>
					<td><input type='text' class='name-field' value='' name='KingdomAwardName[New]' ></td>
					<td><input type='text' class='numeric-field' value='' name='ReignLimit[New]' /></td>
					<td><input type='text' class='numeric-field' value='' name='MonthLimit[New]' /></td>
					<td><input type='checkbox' value='1' name='IsTitle[New]' /></td>
					<td><input class='numeric-field' type='text' value='' name='TitleClass[New]' /></td>
				</tr>
				<tr>
					<td colspan=7><input type='submit' value='Update Awards' ?></td>
				</tr>
			</tbody>
		</table>
	</form>
</div>

<!--
<?php print_r($Canonical_awards); ?>
-->