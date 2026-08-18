<?php
$wv = $_wv;
$filter = $wv['filter'];
$page   = $wv['page'];
$sigs   = $wv['signatures'];
$total  = $wv['total'];
$scope  = $wv['scope'];
$eid    = $wv['entity_id'];
$pageSize    = (int)($wv['pageSize'] ?? 10);
if ($pageSize < 1) $pageSize = 10;
$entity_name = $wv['entity_name'] ?? '';
$pages  = max(1, (int)ceil($total / $pageSize));
$scopeLabel  = ucfirst($scope);
$profileUrl  = UIR . ($scope === 'park' ? 'Park' : 'Kingdom') . '/index/' . (int)$eid;
$rangeFrom   = $total > 0 ? (($page - 1) * $pageSize) + 1 : 0;
$rangeTo     = min($page * $pageSize, $total);
$filterTips  = [
	'pending'    => 'Awaiting officer verification',
	'verified'   => 'Officer-confirmed and on file',
	'rejected'   => 'Reviewed and declined — signer should re-sign',
	'stale'      => 'Signed on an older template version — player should re-sign',
	'superseded' => 'Replaced by a newer submission',
	'all'        => 'Show signatures of every status',
];
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
.wv-queue .wv-hero { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 16px 18px; background: #f7f7fb; border: 1px solid #e2e2ea; border-radius: 8px; margin: 8px 0 4px; }
.wv-queue .wv-hero-main { display: flex; align-items: center; gap: 14px; }
.wv-queue .wv-hero-icon { font-size: 28px; color: #5b21b6; line-height: 1; }
.wv-queue .wv-hero-text h1 { margin: 0; }
.wv-queue .wv-scope-chip { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; padding: 4px 12px; background: #ede9fe; border: 1px solid #ddd6fe; border-radius: 16px; font-size: 13px; color: #5b21b6; text-decoration: none; }
.wv-queue .wv-scope-chip:hover { background: #ddd6fe; }
.wv-queue .wv-scope-chip .wv-scope-kind { opacity: .75; }
.wv-queue .wv-summary { font-size: 13px; color: #666; margin: 10px 0 4px; }
.wv-queue .wv-scope-badge { display: inline-block; padding: 3px 9px; border-radius: 10px; font-size: 11px; font-weight: bold; text-transform: capitalize; background: #e0e7ff; color: #3730a3; }
.wv-queue .wv-scope-badge.wv-scope-park { background: #d1fae5; color: #065f46; }
.wv-queue .wv-filters { display: flex; gap: 6px; margin: 12px 0; }
.wv-queue .wv-chip { padding: 6px 12px; background: #eee; border: 1px solid #ccc; border-radius: 16px; cursor: pointer; font-size: 13px; text-decoration: none; color: #333; }
.wv-queue .wv-chip.wv-active { background: #333; color: #fff; }
.wv-queue .wv-chip[data-tip] { position: relative; }
.wv-queue .wv-chip[data-tip]:hover::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #2d3748; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; pointer-events: none; z-index: 10; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
.wv-queue .wv-chip[data-tip]:hover::before { content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); border: 4px solid transparent; border-top-color: #2d3748; pointer-events: none; z-index: 10; }
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

.wv-queue .wv-pager .wv-gap { border: none; padding: 6px 4px; color: #999; }
.wv-queue .wv-pager .wv-disabled { opacity: .4; pointer-events: none; }

/* --- Dark mode --- */
html[data-theme="dark"] .wv-queue { color: #e2e8f0; }
html[data-theme="dark"] .wv-queue .wv-hero { background: #1f2937; border-color: #4a5568; }
html[data-theme="dark"] .wv-queue .wv-hero-icon { color: #c4b5fd; }
html[data-theme="dark"] .wv-queue .wv-scope-chip { background: #2d2548; border-color: #4c3a78; color: #c4b5fd; }
html[data-theme="dark"] .wv-queue .wv-scope-chip:hover { background: #3a2f5c; }
html[data-theme="dark"] .wv-queue .wv-summary { color: #a0aec0; }
html[data-theme="dark"] .wv-queue .wv-scope-badge { background: #2d3561; color: #c7d2fe; }
html[data-theme="dark"] .wv-queue .wv-scope-badge.wv-scope-park { background: #133b2c; color: #9ae6b4; }
html[data-theme="dark"] .wv-queue .wv-pager .wv-gap { color: #718096; }
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
	<div class="wv-hero">
		<div class="wv-hero-main">
			<i class="fa fa-list-alt wv-hero-icon" aria-hidden="true"></i>
			<div class="wv-hero-text">
				<h1>Waiver Queue</h1>
				<a class="wv-scope-chip" href="<?= htmlspecialchars($profileUrl) ?>">
					<i class="fa fa-arrow-left" aria-hidden="true"></i>
					<span class="wv-scope-kind"><?= htmlspecialchars($scopeLabel) ?>:</span>
					<span><?= htmlspecialchars($entity_name !== '' ? $entity_name : ($scopeLabel . ' #' . (int)$eid)) ?></span>
				</a>
			</div>
		</div>
	</div>
	<div class="wv-filters">
	<?php foreach (['pending','verified','rejected','stale','all'] as $f): ?>
		<a class="wv-chip <?= $filter === $f ? 'wv-active' : '' ?>" data-tip="<?= htmlspecialchars($filterTips[$f] ?? '') ?>" href="<?= htmlspecialchars(wv_filter_url($scope, $eid, $f, 1)) ?>"><?= ucfirst($f) ?></a>
	<?php endforeach; ?>
	</div>
	<div class="wv-summary">
		<?php if ($total > 0): ?>Showing <?= (int)$rangeFrom ?>&ndash;<?= (int)$rangeTo ?> of <?= (int)$total ?><?php else: ?>No signatures to show<?php endif; ?>
	</div>
	<table>
		<thead><tr><th>Player</th><th>Signed</th><th>Status</th><th>Scope</th><th></th></tr></thead>
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
				<td><?= $s['SignedAt'] ? htmlspecialchars(date('F j, Y', strtotime($s['SignedAt']))) : '—' ?></td>
				<td><span class="wv-badge wv-badge-<?= htmlspecialchars($s['VerificationStatus']) ?>"><?= htmlspecialchars($s['VerificationStatus']) ?></span></td>
				<td>
					<?php $tplScope = strtolower((string)($s['TemplateScope'] ?? '')); ?>
					<?php if ($tplScope): ?><span class="wv-scope-badge <?= $tplScope === 'park' ? 'wv-scope-park' : '' ?>"><?= htmlspecialchars(ucfirst($tplScope)) ?></span><?php else: ?>&mdash;<?php endif; ?>
				</td>
				<td><a href="<?= UIR ?>Waiver/review/<?= (int)$s['SignatureId'] ?>">Review &rarr;</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ($pages > 1): ?>
	<div class="wv-pager">
		<?php
		// Windowed pager: page 1, last page, current +/- 2, '…' for gaps, Prev/Next
		$win = [];
		for ($i = 1; $i <= $pages; $i++) {
			if ($i === 1 || $i === $pages || ($i >= $page - 2 && $i <= $page + 2)) $win[] = $i;
		}
		$prev = max(1, $page - 1);
		$next = min($pages, $page + 1);
		?>
		<a class="<?= $page <= 1 ? 'wv-disabled' : '' ?>" href="<?= htmlspecialchars(wv_filter_url($scope, $eid, $filter, $prev)) ?>">&laquo; Prev</a>
		<?php $last = 0; foreach ($win as $i): ?>
			<?php if ($last && $i > $last + 1): ?><span class="wv-gap">&hellip;</span><?php endif; ?>
			<?php if ($i === $page): ?><span class="wv-current"><?= $i ?></span>
			<?php else: ?><a href="<?= htmlspecialchars(wv_filter_url($scope, $eid, $filter, $i)) ?>"><?= $i ?></a><?php endif; ?>
			<?php $last = $i; endforeach; ?>
		<a class="<?= $page >= $pages ? 'wv-disabled' : '' ?>" href="<?= htmlspecialchars(wv_filter_url($scope, $eid, $filter, $next)) ?>">Next &raquo;</a>
	</div>
	<?php endif; ?>
</div>
