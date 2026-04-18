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
			<tr><td colspan="5" style="text-align:center; padding: 20px; color: #666;">No signatures match this filter.</td></tr>
		<?php endif; foreach ($sigs as $s): ?>
			<tr>
				<td>
					<strong><?= htmlspecialchars($s['PersonaName'] ?: ($s['MundaneFirst'] . ' ' . $s['MundaneLast'])) ?></strong>
					<?php if ($s['PersonaName']): ?><br><span style="color: #666; font-size: 12px;"><?= htmlspecialchars($s['MundaneFirst'] . ' ' . $s['MundaneLast']) ?></span><?php endif; ?>
					<?php if ($s['IsMinor']): ?><br><span style="color: #a50; font-size: 11px;"><em>minor &mdash; rep: <?= htmlspecialchars($s['MinorRepFirst'] . ' ' . $s['MinorRepLast']) ?></em></span><?php endif; ?>
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
