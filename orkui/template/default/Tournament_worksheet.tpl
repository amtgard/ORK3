<div class='info-container'>
	<h3>Add Bracket</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<form class='form-container' method='post' action='<?=UIR ?>Tournament/worksheet/<?=$tournament_id ?>&Action=addbracket'>
		<div>
			<span>Style:</span>
			<span>
				<select name='Style'>
					<option>Single Sword</option>
					<option>Florentine</option>
					<option>Sword and Shield</option>
					<option>Great Weapon</option>
					<option>Missile</option>
					<option>Other</option>
					<option>Jugging</option>
					<option>Battlegame</option>
					<option>Quest</option>
				</select>
			</span>
		</div>
		<div>
			<span>Note:</span>
			<span><input type='text' value='<?=$Tournament_create['StyleNote'] ?>' name='StyleNote' /></span>
		</div>
		<div>
			<span>Method:</span>
			<span>
				<select name='Method'>
					<option value='single'>Single Elimination</option>
					<option value='double'>Double Elimination</option>
					<option value='swiss'>Swiss</option>
					<option value='round-robin'>Round Robin</option>
					<option value='ironman'>Ironman</option>
					<option value='score'>Judge&apos;s Score</option>
				</select>
			</span>
		</div>
		<div>
			<span>Rings:</span>
			<span><input type='text' value='<?=$Tournament_create['Rings'] ?>' name='Rings' class='numeric-field' style='float: none;' /></span>
		</div>
		<div>
			<span>Competitors:</span>
			<span>
				<select name='Participants'>
					<option value='individual'>Individuals</option>
					<option value='team'>Teams</option>
				</select>
			</span>
		</div>
		<div>
			<span>Seeding:</span>
			<span>
				<select name='Seeding'>
					<option value='random'>Random</option>
					<option value='glicko2'>Random &plus; Manual</option>
					<option value='manual'>Manual</option>
					<option value='glicko2'>Performance Score</option>
					<option value='glicko2'>Performance &plus; Manual</option>
				</select>
			</span>
		</div>
		<div>
			<span></span>
			<span><input type='submit' value='Add Bracket' name='AddBracket' /></span>
		</div>
	</form>
</div>

<div class='info-container'>
	<h3>New Bracket</h3>
	<h3>Brackets</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Style</th>
				<th>Rings</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Tournaments['Tournaments'])): ?>
<?php 	foreach ($Tournaments['Tournaments'] as $k => $tourney): ?>
			<tr>
				<td><a href='<?=UIR.'Tournament/worksheet/'.$tourney['TournamentId'] ?>'><?=$tourney['Name'] ?></a></td>
				<td class='data-column'><?=$tourney['DateTime'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>

<div class='info-container'>
	<h3>New Team</h3>
	<h3>Teams</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Team Name</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Tournaments['Tournaments'])): ?>
<?php 	foreach ($Tournaments['Tournaments'] as $k => $tourney): ?>
			<tr>
				<td><a href='<?=UIR.'Tournament/worksheet/'.$tourney['TournamentId'] ?>'><?=$tourney['Name'] ?></a></td>
				<td class='data-column'><?=$tourney['DateTime'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>


<div class='info-container'>
	<h3>Add Competitor</h3>
</div>

<div class='info-container'>
	<h3>Bracket Officiants</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Ring</th>
				<th>Officiant</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Tournaments['Tournaments'])): ?>
<?php 	foreach ($Tournaments['Tournaments'] as $k => $tourney): ?>
			<tr>
				<td><a href='<?=UIR.'Tournament/worksheet/'.$tourney['TournamentId'] ?>'><?=$tourney['Name'] ?></a></td>
				<td class='data-column'><?=$tourney['DateTime'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
	<h3>Bracket Competitors</h3>
	<table class='information-table'>
		<thead>
			<tr>
				<th>Competitor</th>
			</tr>
		</thead>
		<tbody>
<?php if (is_array($Tournaments['Tournaments'])): ?>
<?php 	foreach ($Tournaments['Tournaments'] as $k => $tourney): ?>
			<tr>
				<td class='data-column'><?=$tourney['DateTime'] ?></td>
			</tr>
<?php 	endforeach; ?>
<?php endif; ?>
		</tbody>
	</table>
</div>
