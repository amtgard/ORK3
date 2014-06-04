<div class='info-container'>
	<h3><?=$Unit['Details']['Unit']['Name'] ?></h3>
	<form class='form-container' >
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Unit['Details']['Unit']['HasHeraldry']?$Unit_heraldryurl['Url']:(HTTP_UNIT_HERALDRY.'00000.jpg') ?>' />
			</span>
		</div>
		<div>
			<span>Name:</span>
			<span class='form-informational-field'><?=htmlentities($Unit['Details']['Unit']['Name'], ENT_QUOTES) ?></span>
		</div>
		<div>
			<span>Type:</span>
			<span class='form-informational-field'><?=$Unit['Details']['Unit']['Type'] ?></span>
		</div>
		<div>
			<span>Url:</span>
			<span class='form-informational-field'><a href='<?=htmlentities($Unit['Details']['Unit']['Url'], ENT_QUOTES) ?>'>Website</a></span>
		</div>
		<div>
			<span>Description:</span>
			<span class='form-informational-field' style='white-space: normal'><?=$Unit['Details']['Unit']['Description'] ?></span>
		</div>
		<div>
			<span>History:</span>
			<span class='form-informational-field' style='white-space: normal'><?=$Unit['Details']['Unit']['History'] ?></span>
		</div>
	</form>
</div>

<div class='info-container'>
	<h3>Members</h3>
	<form method='post' id='ManageMembers' class='form-container' action='<?=UIR ?>Unit/index/<?=$Unit['Details']['Unit']['UnitId'] ?>&Action=addmember'>
		<table class='information-table action-table'>
			<thead>
				<tr>
					<th>Member</th>
					<th>Role</th>
					<th>Title</th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($Unit['Members']['Roster'] as $k => $member) : ?>
				<tr onClick="javascript:document.location.href='<?=UIR ?>Player/index/<?=$member['MundaneId'] ?>'">
					<td><?=$member['Persona'] ?></td>
					<td><?=ucfirst($member['UnitRole']) ?></td>
					<td><?=$member['UnitTitle'] ?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</form>
</div>
