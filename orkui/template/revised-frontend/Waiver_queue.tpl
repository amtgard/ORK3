<?php
$wv = $_wv;
$filter = $wv['filter'];
$page   = $wv['page'];
$sigs   = $wv['signatures'];
$total  = $wv['total'];
$scope  = $wv['scope'];
$eid    = $wv['entity_id'];
$pages  = max(1, (int)ceil($total / 10));
if (!function_exists('wv_filter_url')) {
	function wv_filter_url($scope, $eid, $filter, $page) {
		return UIR . 'Waiver/queue/' . $scope . '/' . (int)$eid . '?filter=' . urlencode($filter) . '&page=' . (int)$page;
	}
}
?>
<style>
.wv-queue { max-width: 1200px; margin: 20px auto; padding: 0 16px; }
.wv-queue h1, .wv-queue h2, .wv-queue h3, .wv-queue h4, .wv-queue h5, .wv-queue h6 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-queue h1 { font-size: 26px; margin-bottom: 4px; }
.wv-queue .wv-filters { display: flex; gap: 6px; margin: 12px 0; }
.wv-queue .wv-chip { padding: 6px 12px; background: #eee; border: 1px solid #ccc; border-radius: 16px; cursor: pointer; font-size: 13px; text-decoration: none; color: #333; }
.wv-queue .wv-chip.wv-active { background: #333; color: #fff; }
.wv-queue table { width: 100%; border-collapse: collapse; background: #fff; }
.wv-queue th, .wv-queue td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
.wv-queue th { background: #f4f4f4; font-weight: bold; }
.wv-queue .wv-badge { display: inline-block; padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; }
.wv-queue .wv-badge-pending   { background: #ffc; color: #660; }
.wv-queue .wv-badge-verified  { background: #cfc; color: #060; }
.wv-queue .wv-badge-rejected  { background: #fcc; color: #900; }
.wv-queue .wv-badge-superseded { background: #eee; color: #555; }
.wv-queue .wv-pager { margin: 12px 0; display: flex; gap: 6px; }
.wv-queue .wv-pager a, .wv-queue .wv-pager span { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px; }
.wv-queue .wv-pager .wv-current { background: #333; color: #fff; border-color: #333; }
.wv-queue .wv-row-sub { color: #666; font-size: 12px; }
.wv-queue .wv-row-minor { color: #a50; font-size: 11px; }
.wv-queue .wv-empty { text-align: center; padding: 20px; color: #666; }

/* --- Dark mode --- */
html[data-theme="dark"] .wv-queue { color: #e2e8f0; }
html[data-theme="dark"] .wv-queue .wv-chip { background: #2d3748; border-color: #4a5568; color: #cbd5e0; }
html[data-theme="dark"] .wv-queue .wv-chip.wv-active { background: #a5b4fc; color: #1a202c; border-color: #a5b4fc; }
html[data-theme="dark"] .wv-queue table { background: #1f2937; }
html[data-theme="dark"] .wv-queue th { background: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .wv-queue td { color: #e2e8f0; }
html[data-theme="dark"] .wv-queue th, html[data-theme="dark"] .wv-queue td { border-bottom-color: #4a5568; }
html[data-theme="dark"] .wv-queue .wv-row-sub { color: #a0aec0; }
html[data-theme="dark"] .wv-queue .wv-row-minor { color: #f6ad55; }
html[data-theme="dark"] .wv-queue .wv-empty { color: #a0aec0; }
html[data-theme="dark"] .wv-queue .wv-badge-pending   { background: #5b4a1a; color: #f6e05e; }
html[data-theme="dark"] .wv-queue .wv-badge-verified  { background: #1c4532; color: #9ae6b4; }
html[data-theme="dark"] .wv-queue .wv-badge-rejected  { background: #5b2727; color: #feb2b2; }
html[data-theme="dark"] .wv-queue .wv-badge-superseded { background: #2d3748; color: #a0aec0; }
html[data-theme="dark"] .wv-queue .wv-pager a,
html[data-theme="dark"] .wv-queue .wv-pager span { background: #2d3748; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-queue .wv-pager .wv-current { background: #a5b4fc; color: #1a202c; border-color: #a5b4fc; }
html[data-theme="dark"] .wv-queue a { color: #a5b4fc; }
</style>
<div class="wv-queue">
	<h1>Digital Waiver Queue &mdash; <?= htmlspecialchars($scope) ?> #<?= (int)$eid ?></h1>
	<div class="wv-filters">
	<?php foreach (['pending','verified','rejected','stale','all'] as $f): ?>
		<a class="wv-chip <?= $filter === $f ? 'wv-active' : '' ?>" href="<?= htmlspecialchars(wv_filter_url($scope, $eid, $f, 1)) ?>"><?= ucfirst($f) ?></a>
	<?php endforeach; ?>
	</div>
	<table>
		<thead><tr><th>Player</th><th>Signed</th><th>Status</th><th>Template</th><th></th></tr></thead>
		<tbody>
		<?php if (!$sigs): ?>
			<tr><td colspan="5" class="wv-empty">No signatures match this filter.</td></tr>
		<?php endif; foreach ($sigs as $s): ?>
			<tr>
				<td>
					<strong><?= htmlspecialchars($s['PersonaName'] ?: ($s['MundaneFirst'] . ' ' . $s['MundaneLast'])) ?></strong>
					<?php if ($s['PersonaName']): ?><br><span class="wv-row-sub"><?= htmlspecialchars($s['MundaneFirst'] . ' ' . $s['MundaneLast']) ?></span><?php endif; ?>
					<?php if ($s['IsMinor']): ?><br><span class="wv-row-minor"><em>minor &mdash; rep: <?= htmlspecialchars($s['MinorRepFirst'] . ' ' . $s['MinorRepLast']) ?></em></span><?php endif; ?>
				</td>
				<td><?= htmlspecialchars($s['SignedAt']) ?></td>
				<td><span class="wv-badge wv-badge-<?= htmlspecialchars($s['VerificationStatus']) ?>"><?= htmlspecialchars($s['VerificationStatus']) ?></span></td>
				<td>#<?= (int)$s['TemplateId'] ?></td>
				<td><a href="<?= UIR ?>Waiver/review/<?= (int)$s['SignatureId'] ?>">Review &rarr;</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<div class="wv-pager">
	<?php for ($i = 1; $i <= $pages; $i++): ?>
		<?php if ($i === $page): ?><span class="wv-current"><?= $i ?></span>
		<?php else: ?><a href="<?= htmlspecialchars(wv_filter_url($scope, $eid, $filter, $i)) ?>"><?= $i ?></a><?php endif; ?>
	<?php endfor; ?>
	</div>
</div>
