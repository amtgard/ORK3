<div class='info-container'>
	<h3>Parks</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0) : ?>
	<div class='success-message'><?=$Message ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Admin/editparks/<?=$KingdomId ?>&Action=update'>
		<table class='information-table action-table'>
			<thead>
				<tr>
					<th>Park</th>
					<th>Title</th>
					<th>Active</th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($ParkInfo['Parks'] as $k => $park): ?>
				<tr>
					<td><a href='<?=UIR;?>Park/index/<?=$park['ParkId'];?>&park_name=<?=$park['Name'];?>'><?=$park['Name'] ?></a></td>
					<td>
						<select name='ParkTitle[<?=$park['ParkId'] ?>]'>
<?php foreach ($ParkInfo['Titles'] as $t => $title) : ?>
							<option value='<?=$title['ParkTitleId'] ?>' <?=$title['ParkTitleId']==$park['ParkTitleId']?'SELECTED':'' ?> ><?=$title['Title'] ?></option>
<?php endforeach; ?>
						</select>
					</td>
					<td><input type='checkbox' name='Active[<?=$park['ParkId'] ?>]' value='YES' <?=$park['Active']=='Active'?'CHECKED':''; ?> ></td>
				</tr>
<?php endforeach; ?>
				<tr>
					<td colspan='3'><input type='submit' value='Update' /></td>
				</tr>
			</tbody>
		</table>
	</form>
</div>
