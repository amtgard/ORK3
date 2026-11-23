<?php /*
 * Rank-range filter control for the Kingdom + Park Recommendations panels (the
 * twin of the Recs Manager's Rank popover, which filters server-side; these panels
 * hold the whole list in a DataTable so revised.js filters client-side, on the
 * Rank cell's data-order / data-ladder / data-master).
 * Expects: $rkfRecs (the AwardRecommendations list), $rkfTable (DataTable id),
 * $rkfCol (zero-based Rank column), $rkfManage (CSS selector of the Manage Recs link).
 * Renders nothing unless the list holds at least one ladder recommendation.
 */
$rkfHasLadder = false;
foreach ((array)($rkfRecs ?? array()) as $_rkfRec) {
    // IsLadder is authoritative when present (same test as the Rank cell's data-ladder);
    // a Master rec also counts, since the scale's M stop filters it.
    $_rkfLadder = array_key_exists('IsLadder', $_rkfRec) ? !empty($_rkfRec['IsLadder']) : (int)($_rkfRec['Rank'] ?? 0) > 0;
    if ($_rkfLadder || !empty($_rkfRec['IsMaster'])) { $rkfHasLadder = true; break; }
}
if ($rkfHasLadder): ?>
<span class="rkf-root" data-rkf-table="<?= htmlspecialchars($rkfTable) ?>" data-rkf-col="<?= (int)$rkfCol ?>" data-rkf-manage="<?= htmlspecialchars($rkfManage) ?>">
	<button type="button" class="rkf-btn" aria-haspopup="dialog" aria-expanded="false"><i class="fas fa-filter" aria-hidden="true"></i> Rank: <span class="rkf-msum">All</span></button>
	<div class="rkf-pop" role="dialog" aria-label="Filter by rank" hidden>
		<div class="rkf-head">
			<span class="rkf-title">Show ranks</span>
			<span class="rkf-status">All ranks</span>
		</div>
		<div class="rkf-stops" role="group" aria-label="Rank range: pick a start, then an end">
			<span class="rkf-div" aria-hidden="true"></span>
			<span class="rkf-pill" aria-hidden="true" hidden></span>
			<?php for ($st = 1; $st <= 11; $st++) { ?>
			<button type="button" class="rkf-stop" data-stop="<?= $st ?>" tabindex="<?= $st === 1 ? '0' : '-1' ?>"<?= $st === 11 ? ' aria-label="M (Master award)" data-tip="Master award (e.g. Master Rose)"' : ($st === 10 ? ' aria-label="10 and above"' : '') ?>><span class="rkf-num"><?= $st === 11 ? 'M' : $st ?></span></button>
			<?php } ?>
		</div>
		<p class="rkf-hint" aria-live="polite">Tap a start rank, then an end rank.</p>
		<label class="rkf-nl"><input type="checkbox" class="rkf-nl-box" checked> Include non-ladder <span class="rkf-sub">(knighthoods, titles&hellip;)</span></label>
		<div class="rkf-foot">
			<button type="button" class="rkf-fbtn rkf-reset">Reset</button>
			<button type="button" class="rkf-fbtn rkf-fbtn-primary rkf-done">Done</button>
		</div>
	</div>
</span>
<?php endif; ?>
