<?php
/*
 * Tournament Report — single tab-body partial.
 *
 * Renders exactly ONE section's inner markup, selected by $Section. Shared by two
 * callers so the output is identical whichever path produced it:
 *   - Reports_tournaments.tpl includes it inline for the Overview tab (first paint).
 *   - Reports::tournament_section() renders it standalone for the lazy AJAX tab loads.
 *
 * Each branch reads only its own $data key (guarded with ?? []), so the caller only
 * has to populate the one section it is rendering.
 */
	$section    = $Section ?? '';
	$scopeType  = $ScopeType ?? '';                 // 'kingdom' | 'park' | ''
	// Order of the Warrior level (0-12) -> display label: 12=Sword Knight, 11=Warlord, 1-10=ordinal, 0=Unranked.
	$warriorLabel = function ($lvl) {
		$lvl = (int)$lvl;
		if ($lvl >= 12) return 'Sword Knight';
		if ($lvl === 11) return 'Warlord';
		if ($lvl <= 0)  return 'Unranked';
		$suffix = ($lvl === 1) ? 'st' : (($lvl === 2) ? 'nd' : (($lvl === 3) ? 'rd' : 'th'));
		return $lvl . $suffix;
	};
?>
<?php if ($section === 'overview'): ?>
	<div class="tnr-grid2">
		<div class="tnr-card"><h4 class="tnr-h">By Style</h4>
<?php foreach (($ProgramStats['ByStyle']??[]) as $s): ?>
			<div class="tnr-bar-row"><span><?=htmlspecialchars($s['Key'])?></span><b><?=(int)$s['Count']?></b></div>
<?php endforeach; ?>
<?php if (empty($ProgramStats['ByStyle'])): ?><div class="tnr-empty">No tournaments in range.</div><?php endif; ?>
		</div>
		<div class="tnr-card"><h4 class="tnr-h">By Method</h4>
<?php foreach (($ProgramStats['ByMethod']??[]) as $s): ?>
			<div class="tnr-bar-row"><span><?=htmlspecialchars($s['Key'])?></span><b><?=(int)$s['Count']?></b></div>
<?php endforeach; ?>
<?php if (empty($ProgramStats['ByMethod'])): ?><div class="tnr-empty">No tournaments in range.</div><?php endif; ?>
		</div>
	</div>
	<div class="tnr-card"><h4 class="tnr-h">Activity Over Time</h4>
		<svg id="tnr-trend" class="tnr-trend" viewBox="0 0 720 220" preserveAspectRatio="xMidYMid meet"></svg>
		<div class="tnr-legend">Bar height = participants per month &middot; number on each bar = tournaments held</div>
	</div>
	<script>window.__tnrTrend = <?=json_encode($ProgramStats['Trend'] ?? [])?>;</script>

<?php elseif ($section === 'tournaments'): ?>
<?php foreach (($TournamentList['Tournaments'] ?? []) as $tour): $w = $tour['WarriorStats']; ?>
	<div class="tnr-tcard">
		<div class="tnr-tcard-top">
			<div class="tnr-tcard-info">
				<a class="tnr-tcard-name" href="<?=UIR?>Tournament/profile/<?=(int)$tour['TournamentId']?>"><?=htmlspecialchars($tour['Name'])?></a>
				<div class="tnr-tcard-meta"><?=date('M j, Y', strtotime($tour['DateTime']))?><?php if ($scopeType==='kingdom' && $tour['ParkName']): ?> &middot; <?=htmlspecialchars($tour['ParkName'])?><?php endif; ?> &middot; <?=(int)$tour['BracketCount']?> bracket<?=$tour['BracketCount']==1?'':'s'?> &middot; <?=(int)$tour['ParticipantCount']?> entrant<?=$tour['ParticipantCount']==1?'':'s'?></div>
				<div class="tnr-warstats">
					<span class="tnr-warstat tnr-warstat-hi" data-tip="Highest Order of the Warrior in the field">Highest: <b><?=htmlspecialchars($warriorLabel($w['HighestLevel'] ?? 0))?></b></span>
					<span class="tnr-warstat" data-tip="Average Order-of-the-Warrior level of the field"><b><?=htmlspecialchars($w['AvgLevel'])?></b> avg Warrior</span>
					<span class="tnr-warstat" data-tip="Median Order-of-the-Warrior level of the field"><b><?=htmlspecialchars($w['MedianLevel'])?></b> median</span>
				</div>
			</div>
<?php $dist = $w['Distribution'] ?? []; $maxc = 1; foreach (($dist ?: []) as $c) { if ($c > $maxc) $maxc = $c; } ?>
			<div class="tnr-rankdist" data-tip="Participants by Order of the Warrior rank (W = Warlord, K = Sword Knight)">
<?php for ($lvl = 1; $lvl <= 12; $lvl++): $c = (int)($dist[$lvl] ?? 0); $lab = $lvl <= 10 ? $lvl : ($lvl == 11 ? 'W' : 'K'); ?>
				<div class="tnr-rankcol<?=$lvl >= 11 ? ' tnr-rankcol-elite' : ''?>">
					<span class="tnr-rankcount<?=$c > 0 ? ' tnr-rankcount-has' : ''?>"><?=$c?></span>
					<span class="tnr-rankbar-wrap"><span class="tnr-rankbar" style="height:<?=$c > 0 ? max(3, (int)round(32 * $c / $maxc)) : 0?>px"></span></span>
					<span class="tnr-ranklabel"><?=$lab?></span>
				</div>
<?php endfor; ?>
			</div>
		</div>
		<table class="tnr-table tnr-tstandings">
			<thead><tr><th>#</th><th>Fighter</th><th>W</th><th>L</th><th>Win %</th><th data-tip="Order of the Warrior 0-12">Warrior</th></tr></thead>
			<tbody>
<?php foreach ($tour['TopParticipants'] as $i => $p): ?>
				<tr class="<?=$i >= 4 ? 'tnr-thidden' : ''?>">
					<td><?=$i+1?></td>
					<td><a href="<?=UIR?>Player/profile/<?=(int)$p['MundaneId']?>"><?=htmlspecialchars($p['Persona'])?></a></td>
					<td><?=(int)$p['Wins']?></td><td><?=(int)$p['Losses']?></td><td><?=(int)$p['WinPct']?>%</td><td><?=(int)$p['WarriorLevel']?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($tour['TopParticipants'])): ?>
				<tr><td colspan="6" class="tnr-tstandings-empty"><?=(int)($tour['ParticipantCount'] ?? 0) === 0 ? 'No entrants yet' : 'No ranked fighters &mdash; only linked players in individual brackets are ranked (alias-only and team entrants aren&rsquo;t)'?></td></tr>
<?php endif; ?>
			</tbody>
		</table>
<?php if (count($tour['TopParticipants']) > 4): ?>
		<button type="button" class="tnr-showmore">Show more</button>
<?php endif; ?>
	</div>
<?php endforeach; ?>
<?php if (empty($TournamentList['Tournaments'])): ?><div class="tnr-empty">No tournaments in range.</div><?php endif; ?>

<?php elseif ($section === 'fighters'): ?>
<?php if (!empty($Leaderboard['Fighters'])): ?>
	<div class="rp-table-area">
	<table class="tnr-table tnr-dt" id="tnr-fighters" data-csv-name="Tournament Fighters">
		<thead><tr>
			<th>Fighter</th>
			<th data-tip="Order of the Warrior 0-12">Warrior</th>
			<th>Tournaments</th>
			<th>W</th><th>L</th><th>Win %</th>
			<th>Championships</th><th>Podiums</th>
			<th>Streak</th><th data-tip="Wins vs fighters 3+ Warrior levels higher">Upsets</th>
		</tr></thead>
		<tbody>
<?php foreach (($Leaderboard['Fighters']??[]) as $f): ?>
			<tr>
				<td><a href="<?=UIR?>Player/profile/<?=(int)$f['MundaneId']?>"><?=htmlspecialchars($f['Persona'])?></a></td>
				<td><?=(int)$f['WarriorLevel']?></td>
				<td><?=(int)$f['TournamentsEntered']?></td>
				<td><?=(int)$f['Wins']?></td><td><?=(int)$f['Losses']?></td><td data-order="<?=(int)$f['WinPct']?>"><?=(int)$f['WinPct']?>%</td>
				<td><?=(int)$f['Championships']?></td><td><?=(int)$f['Podiums']?></td>
				<td><?=(int)$f['MaxStreak']?></td><td><?=(int)$f['UpsetWins']?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
	</div>
<?php else: ?><div class="tnr-empty">No individual-bracket results in range.</div><?php endif; ?>

<?php elseif ($section === 'awards'): ?>
<?php foreach (($AwardCandidates['Candidates']??[]) as $c): ?>
	<div class="tnr-cand <?=$c['OotWCandidate'] ? 'tnr-cand-ootw' : ''?>">
		<div class="tnr-cand-main">
			<a class="tnr-cand-name" href="<?=UIR?>Player/profile/<?=(int)$c['MundaneId']?>"><?=htmlspecialchars($c['Persona'])?></a>
			<span class="tnr-cand-wl">Warrior <?=(int)$c['WarriorLevel']?></span>
<?php if ($c['OotWCandidate']): ?><span class="tnr-pill">Order of the Warrior candidate</span><?php endif; ?>
			<div class="tnr-cand-note"><?=htmlspecialchars($c['EvidenceNote'])?></div>
		</div>
		<a class="rp-btn-ghost tnr-cand-btn" href="<?=UIR?>Player/profile/<?=(int)$c['MundaneId']?>" data-tip="Open this fighter's profile to make an award recommendation"><i class="fas fa-star"></i> Recommend</a>
	</div>
<?php endforeach; ?>
<?php if (empty($AwardCandidates['Candidates'])): ?><div class="tnr-empty">No recognition candidates meet the thresholds in range.</div><?php endif; ?>

<?php elseif ($section === 'parks'): ?>
<?php if (!empty($ParkComparison['Parks'])): ?>
	<div class="rp-table-area">
	<table class="tnr-table tnr-dt" id="tnr-parks" data-csv-name="Tournament Parks">
		<thead><tr><th>Park</th><th>Tournaments Hosted</th><th>Participants</th><th>Championships</th><th>Avg Warrior</th></tr></thead>
		<tbody>
<?php foreach (($ParkComparison['Parks']??[]) as $p): ?>
			<tr>
				<td><a href="<?=UIR?>Park/profile/<?=(int)$p['ParkId']?>"><?=htmlspecialchars($p['ParkName'])?></a></td>
				<td><?=(int)$p['TournamentsHosted']?></td><td><?=(int)$p['Participants']?></td>
				<td><?=(int)$p['Championships']?></td><td><?=htmlspecialchars($p['AvgWarriorLevel'])?></td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
	</div>
<?php else: ?><div class="tnr-empty">No park-hosted tournaments in range.</div><?php endif; ?>

<?php elseif ($section === 'teams'): ?>
	<p class="tnr-context">Team brackets that have concluded &mdash; champion and runner-up teams with their member rosters.</p>
<?php foreach (($TeamChampions ?? []) as $tc): ?>
	<div class="tnr-tcard tnr-tc-card">
		<div class="tnr-tcard-top">
			<div class="tnr-tcard-info">
				<a class="tnr-tcard-name" href="<?=UIR?>Tournament/profile/<?=(int)$tc['TournamentId']?>"><?=htmlspecialchars($tc['TournamentName'])?></a>
				<div class="tnr-tcard-meta"><?=date('M j, Y', strtotime($tc['TournamentDate']))?><?php if ($scopeType==='kingdom' && $tc['ParkName']): ?> &middot; <?=htmlspecialchars($tc['ParkName'])?><?php endif; ?> &middot; <?=htmlspecialchars(ucfirst($tc['Style']??''))?> &middot; <?=htmlspecialchars(ucfirst($tc['Method']??''))?></div>
			</div>
		</div>
		<div class="tnr-tc-podium">
			<!-- Champion -->
			<div class="tnr-tc-place tnr-tc-first">
				<div class="tnr-tc-badge"><i class="fas fa-trophy"></i> Champion</div>
				<div class="tnr-tc-teamname"><?=htmlspecialchars($tc['Champion']['TeamName'])?></div>
<?php if (!empty($tc['Champion']['Members'])): ?>
				<ul class="tnr-tc-roster">
<?php foreach ($tc['Champion']['Members'] as $m): ?>
					<li><a href="<?=UIR?>Player/profile/<?=(int)$m['MundaneId']?>"><?=htmlspecialchars($m['Persona'])?></a></li>
<?php endforeach; ?>
				</ul>
<?php endif; ?>
			</div>
<?php if ($tc['RunnerUp'] !== null): ?>
			<!-- Runner-up -->
			<div class="tnr-tc-place tnr-tc-second">
				<div class="tnr-tc-badge"><i class="fas fa-medal"></i> Runner-up</div>
				<div class="tnr-tc-teamname"><?=htmlspecialchars($tc['RunnerUp']['TeamName'])?></div>
<?php if (!empty($tc['RunnerUp']['Members'])): ?>
				<ul class="tnr-tc-roster">
<?php foreach ($tc['RunnerUp']['Members'] as $m): ?>
					<li><a href="<?=UIR?>Player/profile/<?=(int)$m['MundaneId']?>"><?=htmlspecialchars($m['Persona'])?></a></li>
<?php endforeach; ?>
				</ul>
<?php endif; ?>
			</div>
<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>
<?php endif; ?>
