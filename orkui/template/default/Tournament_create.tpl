<div class='info-container'>
	<h3>Tournaments</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Name</th>
				<th>When</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Tournaments['Tournaments'])): ?>
<?php 	foreach ($Tournaments['Tournaments'] as $k => $tourney): ?>
			<tr>
				<td><a href='<?=UIR.'Tournament/worksheet/'.$tourney['TournamentId'] ?>'><?=htmlspecialchars((string)$tourney['Name'], ENT_QUOTES, 'UTF-8') ?></a></td>
				<td class='data-column'><?=htmlspecialchars((string)$tourney['DateTime'], ENT_QUOTES, 'UTF-8') ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>Create Tournament</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Tournament/create/create'>
		<div>
			<span>Name:</span>
			<span><input type='text' class='name-field required-field' value='<?=htmlspecialchars((string)($Tournament_create['Name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' name='Name' /></span>
		</div>
		<div>
			<span>Description:</span>
			<span><textarea name='Description' rows=10 cols=60 ><?=htmlspecialchars((string)($Tournament_create['Description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></span>
		</div>
		<div>
			<span>Url:</span>
			<span><input type='text' value='<?=htmlspecialchars((string)($Tournament_create['Url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' name='Url' /></span>
		</div>
		<div>
			<span>When:</span>
			<span><input type='text' class='hasDatePicker required-field' value='<?=htmlspecialchars((string)($Tournament_create['When'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' name='When' /></span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Create Tournament' name='CreateTournament' /></span>
		</div>
		<input type='hidden' name='KingdomId' value='<?=(int)$KingdomId ?>' />
		<input type='hidden' name='ParkId' value='<?=(int)$ParkId ?>' />
		<input type='hidden' name='EventCalendarDetailId' value='<?=(int)$EventCalendarDetailId ?>' />
	</form>
</div>