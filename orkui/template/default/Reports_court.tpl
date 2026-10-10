<?php
/* Court Report — one court's confirmed (given) awards. */
$c = $Court;
?>
<style>
.cr-wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
.cr-back { display: inline-block; margin-bottom: 14px; color: #4c51bf; text-decoration: none; font-size: 13px; }
.cr-back:hover { text-decoration: underline; }
.cr-head h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; font-size: 22px; margin: 0 0 4px; color: #2d3748; }
.cr-sub { color: #718096; font-size: 13px; margin-bottom: 18px; }
.cr-table { width: 100%; border-collapse: collapse; }
.cr-table th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #718096; border-bottom: 2px solid #e2e8f0; padding: 8px 10px; }
.cr-table td { padding: 10px; border-bottom: 1px solid #edf2f7; font-size: 14px; vertical-align: top; }
.cr-recipient a { color: #2d3748; font-weight: 600; text-decoration: none; }
.cr-recipient a:hover { text-decoration: underline; }
.cr-giver a { color: #2d3748; text-decoration: none; }
.cr-giver a:hover { text-decoration: underline; }
.cr-rank { color: #718096; font-size: 12px; margin-left: 6px; }
.cr-comment { color: #4a5568; }
.cr-artisan { display: block; font-size: 13px; }
.cr-artisan-role { color: #718096; }
.cr-maker { display: block; font-size: 12px; color: #718096; }
.cr-none { color: #a0aec0; font-style: italic; }
.cr-empty { text-align: center; color: #718096; padding: 40px 20px; border: 1px dashed #cbd5e0; border-radius: 8px; }
html[data-theme="dark"] .cr-sub, html[data-theme="dark"] .cr-rank, html[data-theme="dark"] .cr-maker, html[data-theme="dark"] .cr-artisan-role { color: #a0aec0; }
html[data-theme="dark"] .cr-table th { color: #a0aec0; border-color: #2d3748; }
html[data-theme="dark"] .cr-back { color: #9aa6ff; }
html[data-theme="dark"] .cr-table td { border-color: #2d3748; }
/* Qualified with #theme_container so these outrank default.theme's
   `html[data-theme="dark"] #theme_container a { color:#63b3ed }` (1,1,2) — an
   unqualified `html[data-theme="dark"] .cr-giver a` is only (0,2,2) and loses,
   same gotcha reports.css records at its .rp-scope-chip dark rule. */
html[data-theme="dark"] #theme_container .cr-recipient a { color: #e2e8f0; }
html[data-theme="dark"] #theme_container .cr-giver a { color: #e2e8f0; }
html[data-theme="dark"] .cr-comment { color: #cbd5e0; }
html[data-theme="dark"] .cr-none { color: #718096; }
html[data-theme="dark"] .cr-empty { border-color: #2d3748; color: #a0aec0; }
/* orkui.css ships html[data-theme="dark"] h1 (0,1,2) with a #374151 pill box, which
   outranks a plain .cr-head h1 (0,1,1) — so the reset has to be repeated here with the
   extra class component or the public title renders boxed in dark mode. */
html[data-theme="dark"] .cr-head h1 { background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; color: #e2e8f0; }

/* Phones: the 4-column table squeezed the award name over five lines and the citation
   into a ~6-character ribbon. Stack each row into a labelled card instead. */
@media (max-width: 600px) {
	.cr-wrap { padding: 12px; }
	.cr-back { padding: 12px 0; margin-bottom: 4px; }
	.cr-head h1 { font-size: 20px; }
	.cr-table thead { display: none; }
	.cr-table tr { display: block; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 10px; }
	.cr-table td { display: block; width: auto; padding: 0; border-bottom: none; }
	.cr-table td.cr-recipient { font-size: 16px; line-height: 1.4; }
	.cr-table td.cr-award { font-size: 15px; line-height: 1.4; margin-top: 2px; }
	.cr-table td.cr-comment, .cr-table td.cr-giver, .cr-table td.cr-artisans { margin-top: 9px; font-size: 14px; line-height: 1.5; }
	.cr-table td[data-label]::before { content: attr(data-label); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #718096; margin-bottom: 2px; }
	html[data-theme="dark"] .cr-table tr { border-color: #2d3748; }
	html[data-theme="dark"] .cr-table td[data-label]::before { color: #a0aec0; }
}
</style>

<div class="cr-wrap">
	<a class="cr-back" href="<?= htmlspecialchars($BackUrl) ?>"><i class="fas fa-arrow-left" style="margin-right:5px"></i>Back to Court Report</a>
	<div class="cr-head">
		<h1><i class="fas fa-gavel" style="margin-right:8px;color:#4c51bf"></i><?= htmlspecialchars($c['Name']) ?></h1>
	</div>
	<div class="cr-sub">
		<?= $c['CourtDate'] ? date('F j, Y', strtotime($c['CourtDate'])) : 'Date TBD' ?>
		· <?= $c['ParkId'] > 0 ? htmlspecialchars($c['ParkName'] ?? 'Park') : htmlspecialchars($c['KingdomName'] ?? 'Kingdom') ?>
		<?php if (!empty($c['EventName'])): ?> · <?= htmlspecialchars($c['EventName']) ?><?php endif; ?>
	</div>

	<?php if (empty($Awards)): ?>
		<div class="cr-empty">No confirmed awards recorded for this court.</div>
	<?php else: ?>
		<table class="cr-table">
			<thead>
				<tr><th>Recipient</th><th>Award</th><th>Granted By</th><th>Comments</th><th>Artisans</th></tr>
			</thead>
			<tbody>
				<?php foreach ($Awards as $a): ?>
				<tr>
					<td class="cr-recipient">
						<a href="<?= UIR ?>Player/profile/<?= (int)$a['MundaneId'] ?>"><?= htmlspecialchars($a['Persona']) ?></a>
						<?php if (!empty($a['ParkAbbrev'])): ?><span class="cr-rank"><?= htmlspecialchars($a['ParkAbbrev']) ?></span><?php endif; ?>
					</td>
					<td class="cr-award">
						<?= htmlspecialchars($a['AwardName']) ?>
						<?php if ($a['IsLadder'] && $a['Rank'] > 0): ?><span class="cr-rank">Rank <?= (int)$a['Rank'] ?></span><?php endif; ?>
					</td>
					<td class="cr-giver" data-label="Granted By">
						<?php if (!empty($a['GivenByPersona'])): ?>
							<?php if (!empty($a['GivenByMundaneId'])): ?>
								<a href="<?= UIR ?>Player/profile/<?= (int)$a['GivenByMundaneId'] ?>"><?= htmlspecialchars($a['GivenByPersona']) ?></a>
							<?php else: ?>
								<?= htmlspecialchars($a['GivenByPersona']) ?>
							<?php endif; ?>
						<?php else: ?><span class="cr-none">—</span><?php endif; ?>
					</td>
					<td class="cr-comment" data-label="Citation">
						<?= !empty($a['PublicComment']) ? nl2br(htmlspecialchars($a['PublicComment'])) : '<span class="cr-none">—</span>' ?>
					</td>
					<td class="cr-artisans" data-label="Artisans">
						<?php
							$has = false;
							if (!empty($a['ScrollMakerPersona'])): $has = true; ?>
							<span class="cr-maker">Scroll: <?= htmlspecialchars($a['ScrollMakerPersona']) ?></span>
						<?php endif; ?>
						<?php if (!empty($a['RegaliaMakerPersona'])): $has = true; ?>
							<span class="cr-maker">Regalia: <?= htmlspecialchars($a['RegaliaMakerPersona']) ?></span>
						<?php endif; ?>
						<?php foreach ($a['Artisans'] as $ar): $has = true; ?>
							<span class="cr-artisan"><?= htmlspecialchars($ar['Persona']) ?><?php if (!empty($ar['Contribution'])): ?><span class="cr-artisan-role"> — <?= htmlspecialchars($ar['Contribution']) ?></span><?php endif; ?></span>
						<?php endforeach; ?>
						<?php if (!$has): ?><span class="cr-none">—</span><?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
