<?php
/**
 * Available Surveys card — the open surveys the viewer is eligible for, from
 * the page shell's memoised SurveyAvailable entry (no fetch, no layout shift).
 *
 * Inputs (set by the including template):
 *   $_svcRows  list of SurveyAvailable rows to show (already filtered)
 *   $_svcWrap  card class for the host page: pna-card | kn-card | pk-card
 *   $_svcHead  'pna' for the profile's small caps title, anything else for the
 *              kn-bare-heading h4 the kingdom and park sidebars use
 *
 * Rendered on the own-profile My Amtgard tab (every eligible survey) and on a
 * kingdom or park page (that org's surveys plus Amtgard-wide ones), so players
 * meet their surveys where they already go, not only on their own profile.
 * The row classes are shared with the profile (pna-survey-row is what the e2e
 * pass looks for); the styles are scoped under .svc-list so the card renders
 * the same on pages that never load the profile's feed CSS.
 */
$_svcRows = is_array($_svcRows ?? null) ? $_svcRows : [];
$_svcWrap = (string) ($_svcWrap ?? 'pna-card');
$_svcHead = (string) ($_svcHead ?? 'pna');
if (!$_svcRows) { return; }
static $_svcCssDone = false;
?>
<?php if (!$_svcCssDone): $_svcCssDone = true; ?>
<style>
.svc-list .pna-feed-row{display:flex;flex-direction:column;align-items:stretch;gap:2px;padding:5px 0;border-bottom:1px solid var(--ork-divider);font-size:12.5px}
.svc-list .pna-feed-row:last-child{border-bottom:none}
.svc-list .pna-feed-label{flex:0 0 auto;color:var(--ork-text,#2d3748);font-weight:500;min-width:0;white-space:normal}
.svc-list .pna-survey-credit{display:inline-flex;align-self:flex-start;align-items:center;gap:4px;padding:1px 8px;border:1px solid var(--ork-border);border-radius:8px;font-size:11px;font-weight:600;background:var(--ork-surface-hover);color:var(--ork-text-secondary)}
.svc-list .pna-survey-meta{display:flex;align-items:center;gap:8px;min-height:44px}
.svc-list .pna-survey-meta .pna-feed-sub{flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;color:var(--ork-text-muted);font-size:11px}
.svc-list .pna-survey-meta .pna-survey-scope{flex:0 1 auto}
.svc-list .pna-survey-meta .pna-survey-close{flex:0 0 auto;overflow:visible;text-overflow:clip}
.svc-list .pna-survey-cta{margin-left:auto;flex-shrink:0;display:inline-flex;align-items:center;min-height:44px;padding:0 10px;font-weight:600;font-size:12px;color:var(--ork-link);text-decoration:none}
.svc-list .pna-survey-cta:hover{text-decoration:underline}
</style>
<?php endif; ?>
<div class="<?= htmlspecialchars($_svcWrap) ?> svc-card">
	<?php if ($_svcHead === 'pna'): ?>
	<div class="pna-card-title"><i class="fas fa-poll"></i> Available Surveys</div>
	<?php else: ?>
	<h4 class="kn-bare-heading"><i class="fas fa-poll"></i> Available Surveys</h4>
	<?php endif; ?>
	<div class="svc-list">
	<?php foreach ($_svcRows as $_sv):
		$_svScope   = htmlspecialchars((string) ($_sv['scope_label'] ?? ''));
		$_svCloseTs = !empty($_sv['close_at']) ? strtotime((string) $_sv['close_at']) : false;
		$_svClose   = $_svCloseTs ? 'closes ' . date('M j', $_svCloseTs) : '';
		$_svCta     = !empty($_sv['in_progress']) ? 'Continue' : 'Take survey';
	?>
		<div class="pna-feed-row pna-survey-row">
			<span class="pna-feed-label"><?= htmlspecialchars((string) $_sv['title']) ?></span>
			<?php if (!empty($_sv['credit_available'])): ?><span class="pna-survey-credit"><i class="fas fa-award" aria-hidden="true"></i> Earns a bonus credit</span><?php endif; ?>
			<span class="pna-survey-meta">
				<?php if ($_svScope !== ''): ?><span class="pna-feed-sub pna-survey-scope"><?= $_svScope ?></span><?php endif; ?>
				<?php if ($_svClose !== ''): ?><span class="pna-feed-sub pna-survey-close"><?= $_svScope !== '' ? '&middot; ' : '' ?><?= $_svClose ?></span><?php endif; ?>
				<a class="pna-survey-cta" aria-label="<?= htmlspecialchars($_svCta . ': ' . (string) $_sv['title']) ?>" href="<?= UIR ?>Survey/take/<?= (int) $_sv['survey_id'] ?>"><?= $_svCta ?></a>
			</span>
		</div>
	<?php endforeach; ?>
	</div>
</div>
