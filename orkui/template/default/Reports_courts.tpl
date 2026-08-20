<?php
/* Court Report — list of courts (with given awards) in a date range. */
$scope_qs = ($ScopeType === 'park')
	? ('ParkId=' . (int)$ParkId . ($KingdomId ? '&KingdomId=' . (int)$KingdomId : ''))
	: ('KingdomId=' . (int)$KingdomId);
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.cr-wrap { max-width: 980px; margin: 0 auto; padding: 16px; }
.cr-head { margin-bottom: 16px; }
.cr-head h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; font-size: 22px; margin: 0 0 4px; }
.cr-sub { color: #718096; font-size: 13px; }
.cr-filter { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; }
.cr-filter label { display: block; font-size: 12px; color: #4a5568; margin-bottom: 4px; }
.cr-filter input[type=text] { padding: 7px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 14px; }
.cr-btn { padding: 8px 16px; border: none; border-radius: 5px; background: #4c51bf; color: #fff; font-size: 14px; cursor: pointer; }
.cr-list { list-style: none; margin: 0; padding: 0; }
.cr-court { display: block; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; text-decoration: none; color: inherit; transition: box-shadow .15s, border-color .15s; }
.cr-court:hover { border-color: #4c51bf; box-shadow: 0 2px 8px rgba(76,81,191,.12); }
.cr-court-top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.cr-court-name { font-weight: 600; font-size: 16px; }
.cr-court-date { color: #718096; font-size: 13px; white-space: nowrap; }
.cr-court-meta { color: #718096; font-size: 13px; margin-top: 4px; }
.cr-badge { display: inline-block; background: #ebf4ff; color: #3c4ba6; border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
.cr-empty { text-align: center; color: #718096; padding: 40px 20px; border: 1px dashed #cbd5e0; border-radius: 8px; }
html[data-theme="dark"] .cr-sub, html[data-theme="dark"] .cr-court-date, html[data-theme="dark"] .cr-court-meta { color: #a0aec0; }
html[data-theme="dark"] .cr-filter { background: #1f2733; border-color: #2d3748; }
html[data-theme="dark"] .cr-filter label { color: #cbd5e0; }
html[data-theme="dark"] .cr-filter input[type=text] { background: #2d3748; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .cr-court { border-color: #2d3748; background: #1a202c; }
html[data-theme="dark"] .cr-court:hover { border-color: #667eea; }
html[data-theme="dark"] .cr-badge { background: #2a3656; color: #b9c6ff; }
html[data-theme="dark"] .cr-empty { border-color: #2d3748; color: #a0aec0; }
</style>

<div class="cr-wrap">
	<div class="cr-head">
		<h1><i class="fas fa-gavel" style="margin-right:8px;color:#4c51bf"></i>Court Report</h1>
		<div class="cr-sub"><?= htmlspecialchars($LocationName) ?> · confirmed awards given at court</div>
	</div>

	<form class="cr-filter" method="get" action="<?= UIR ?>Reports/courts">
		<input type="hidden" name="Route" value="Reports/courts">
		<?php if ($ScopeType === 'park'): ?>
			<input type="hidden" name="ParkId" value="<?= (int)$ParkId ?>">
			<?php if ($KingdomId): ?><input type="hidden" name="KingdomId" value="<?= (int)$KingdomId ?>"><?php endif; ?>
		<?php else: ?>
			<input type="hidden" name="KingdomId" value="<?= (int)$KingdomId ?>">
		<?php endif; ?>
		<div>
			<label>From</label>
			<input type="text" id="cr-from" name="From" autocomplete="off" value="<?= htmlspecialchars($From) ?>">
		</div>
		<div>
			<label>Until</label>
			<input type="text" id="cr-until" name="Until" autocomplete="off" value="<?= htmlspecialchars($Until) ?>">
		</div>
		<button type="submit" class="cr-btn"><i class="fas fa-search" style="margin-right:5px"></i>Search</button>
	</form>

	<?php if (empty($Courts)): ?>
		<div class="cr-empty">No courts with confirmed awards in this date range.</div>
	<?php else: ?>
		<ul class="cr-list">
			<?php foreach ($Courts as $c): ?>
				<?php
					$detail = UIR . 'Reports/court&CourtId=' . (int)$c['CourtId'] . '&' . $scope_qs;
					if ($From)  $detail .= '&From='  . rawurlencode($From);
					if ($Until) $detail .= '&Until=' . rawurlencode($Until);
					$court_scope = ($c['ParkId'] > 0) ? ($c['ParkName'] ?? 'Park court') : 'Kingdom court';
				?>
				<a class="cr-court" href="<?= htmlspecialchars($detail) ?>">
					<div class="cr-court-top">
						<span class="cr-court-name"><?= htmlspecialchars($c['Name']) ?></span>
						<span class="cr-court-date"><?= $c['CourtDate'] ? date('F j, Y', strtotime($c['CourtDate'])) : 'Date TBD' ?></span>
					</div>
					<div class="cr-court-meta">
						<span class="cr-badge"><?= (int)$c['GivenCount'] ?> award<?= $c['GivenCount'] == 1 ? '' : 's' ?></span>
						&nbsp; <?= htmlspecialchars($court_scope) ?>
						<?php if (!empty($c['EventName'])): ?> · <?= htmlspecialchars($c['EventName']) ?><?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<script>
(function() {
	flatpickr('#cr-from',  { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
	flatpickr('#cr-until', { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
})();
</script>
