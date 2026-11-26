<?php
/**
 * Guided Tours: every OrkTour in one place. Expects $TourGroups
 * (Controller_Tour::catalog()) and $TourProgress (tour id => {v, status, at}).
 * Plain divs, not h4/p: orkui.css styles those globally.
 */
$_gtProgress = is_array($TourProgress ?? null) ? $TourProgress : array();
?>
<style>
.gt-root { max-width: 1000px; margin: 16px auto 0; color: var(--ork-text); }
.gt-head { margin: 0 0 6px; font: 700 24px/1.25 system-ui, sans-serif; }
.gt-lede { margin: 0 0 6px; color: var(--ork-text-muted); font-size: 14px; line-height: 1.5; max-width: 70ch; }
.gt-group { margin: 26px 0 10px; color: var(--ork-text-muted); font: 700 12px/1 system-ui, sans-serif; letter-spacing: .12em; text-transform: uppercase; }
.gt-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.gt-card {
	display: flex; flex-direction: column; gap: 6px; padding: 16px 18px;
	background: var(--ork-card-bg); border: 1px solid var(--ork-border); border-radius: 8px;
}
.gt-title { display: flex; align-items: baseline; gap: 9px; font: 700 16px/1.3 system-ui, sans-serif; overflow-wrap: anywhere; }
.gt-title i { width: 18px; color: var(--ork-text-muted); font-size: 14px; text-align: center; }
.gt-about { font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; }
.gt-where, .gt-note { color: var(--ork-text-muted); font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; }
.gt-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: auto; padding-top: 8px; }
a.gt-btn, a.gt-btn:visited {
	display: inline-flex; align-items: center; gap: 7px; min-height: 36px; padding: 0 14px; box-sizing: border-box;
	background: var(--ork-blue-primary, #2b6cb0); border-radius: 6px; color: #fff; font-size: 13px; font-weight: 600; text-decoration: none;
}
a.gt-btn:hover, a.gt-btn:focus-visible { filter: brightness(1.1); color: #fff; }
.gt-done { display: inline-flex; align-items: center; gap: 6px; margin-left: auto; color: #2f855a; font-size: 12px; font-weight: 600; }
html[data-theme="dark"] .gt-done { color: #68d391; }
@media (max-width: 600px) {
	a.gt-btn { min-height: 44px; }
	.gt-head { font-size: 21px; }
}
</style>

<div class="gt-root">
	<div class="gt-head" role="heading" aria-level="1">Guided Tours</div>
	<div class="gt-lede">Short, step-by-step walk-throughs of the ORK. Each one opens the page it explains and points things out as you go. You can leave a tour at any time and take it again whenever you like.</div>

<?php foreach ((is_array($TourGroups ?? null) ? $TourGroups : array()) as $_gtGroup): ?>
	<div class="gt-group" role="heading" aria-level="2"><?= htmlspecialchars($_gtGroup['Title'], ENT_QUOTES) ?></div>
	<div class="gt-list">
<?php   foreach ($_gtGroup['Tours'] as $_gt):
		$_gtDone = 0;
		foreach ($_gt['Ids'] as $_gtId) {
			if ((($_gtProgress[$_gtId]['status'] ?? '') === 'completed')) { $_gtDone++; }
		}
		$_gtAll = count($_gt['Ids']);
?>
		<div class="gt-card">
			<div class="gt-title"><i class="fas <?= htmlspecialchars($_gt['Icon'], ENT_QUOTES) ?>" aria-hidden="true"></i><span><?= htmlspecialchars($_gt['Title'], ENT_QUOTES) ?></span></div>
			<div class="gt-about"><?= htmlspecialchars($_gt['About'], ENT_QUOTES) ?></div>
			<div class="gt-where"><?= htmlspecialchars($_gt['Where'], ENT_QUOTES) ?></div>
<?php     if (empty($_gt['Links']) && !empty($_gt['Note'])): ?>
			<div class="gt-note"><i class="fas fa-info-circle" aria-hidden="true"></i> <?= htmlspecialchars($_gt['Note'], ENT_QUOTES) ?></div>
<?php     endif; ?>
<?php     if (!empty($_gt['Links']) || $_gtDone > 0): ?>
			<div class="gt-foot">
<?php       foreach ($_gt['Links'] as $_gtLink): ?>
				<a class="gt-btn" href="<?= htmlspecialchars($_gtLink['Url'], ENT_QUOTES) ?>"><i class="fas fa-route" aria-hidden="true"></i> <?= htmlspecialchars($_gtLink['Label'], ENT_QUOTES) ?></a>
<?php       endforeach; ?>
<?php       if ($_gtDone > 0): ?>
				<span class="gt-done"><i class="fas fa-check-circle" aria-hidden="true"></i> <?= $_gtDone === $_gtAll ? 'Completed' : $_gtDone . ' of ' . $_gtAll . ' completed' ?></span>
<?php       endif; ?>
			</div>
<?php     endif; ?>
		</div>
<?php   endforeach; ?>
	</div>
<?php endforeach; ?>
</div>
