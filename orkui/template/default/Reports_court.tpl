<?php
/* Court Report — one court's confirmed (given) awards. */
$c = $Court;
?>
<style>
.cr-wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
.cr-back { display: inline-block; margin-bottom: 14px; color: #4c51bf; text-decoration: none; font-size: 13px; }
.cr-back:hover { text-decoration: underline; }
.cr-head h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; font-size: 22px; margin: 0 0 4px; }
.cr-sub { color: #718096; font-size: 13px; margin-bottom: 18px; }
.cr-table { width: 100%; border-collapse: collapse; }
.cr-table th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #718096; border-bottom: 2px solid #e2e8f0; padding: 8px 10px; }
.cr-table td { padding: 10px; border-bottom: 1px solid #edf2f7; font-size: 14px; vertical-align: top; }
.cr-recipient a { color: #2d3748; font-weight: 600; text-decoration: none; }
.cr-recipient a:hover { text-decoration: underline; }
.cr-rank { color: #718096; font-size: 12px; margin-left: 6px; }
.cr-comment { color: #4a5568; }
.cr-artisan { display: block; font-size: 13px; }
.cr-artisan-role { color: #718096; }
.cr-maker { display: block; font-size: 12px; color: #718096; }
.cr-none { color: #a0aec0; font-style: italic; }
.cr-empty { text-align: center; color: #718096; padding: 40px 20px; border: 1px dashed #cbd5e0; border-radius: 8px; }
html[data-theme="dark"] .cr-sub, html[data-theme="dark"] .cr-rank, html[data-theme="dark"] .cr-maker, html[data-theme="dark"] .cr-artisan-role { color: #a0aec0; }
html[data-theme="dark"] .cr-table th { color: #a0aec0; border-color: #2d3748; }
html[data-theme="dark"] .cr-table td { border-color: #2d3748; }
html[data-theme="dark"] .cr-recipient a { color: #e2e8f0; }
html[data-theme="dark"] .cr-comment { color: #cbd5e0; }
html[data-theme="dark"] .cr-empty { border-color: #2d3748; color: #a0aec0; }
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
				<tr><th>Recipient</th><th>Award</th><th>Comments</th><th>Artisans</th></tr>
			</thead>
			<tbody>
				<?php foreach ($Awards as $a): ?>
				<tr>
					<td class="cr-recipient">
						<a href="<?= UIR ?>Playernew/index/<?= (int)$a['MundaneId'] ?>"><?= htmlspecialchars($a['Persona']) ?></a>
						<?php if (!empty($a['ParkAbbrev'])): ?><span class="cr-rank"><?= htmlspecialchars($a['ParkAbbrev']) ?></span><?php endif; ?>
					</td>
					<td>
						<?= htmlspecialchars($a['AwardName']) ?>
						<?php if ($a['IsLadder'] && $a['Rank'] > 0): ?><span class="cr-rank">Rank <?= (int)$a['Rank'] ?></span><?php endif; ?>
					</td>
					<td class="cr-comment">
						<?= !empty($a['PublicComment']) ? nl2br(htmlspecialchars($a['PublicComment'])) : '<span class="cr-none">—</span>' ?>
					</td>
					<td>
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
