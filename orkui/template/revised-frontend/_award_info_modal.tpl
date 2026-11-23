<?php
/* =============================================
   Award Info Modal — the shared (i) "award details" dialog.
   Opened by any .pn-award-info-btn[data-awards-id]: the Awards/Titles rows on a
   player profile, and the granted rows of the closed-recs panels (player, Kingdom,
   Park) and the Recommendations Manager. Every viewer; filled lazily from
   PlayerAjax/award_info by script/award-info-modal.js.

   $aiShell = 'rm' renders it in the Recommendations Manager's own modal shell:
   that page runs under default.theme without revised.css, so .pn-overlay /
   .pn-modal-box / .pn-btn have no styles there. The ids, which the script keys on,
   are the same in both shells. Rendered once per page.
   ============================================= */
if (!empty($GLOBALS['ornAwardInfoModalRendered'])) {
    return;
}
$GLOBALS['ornAwardInfoModalRendered'] = true;
$aiRm = ($aiShell ?? '') === 'rm';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/award-info.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/award-info.css') ?>">
<?php if ($aiRm) { ?>
<div class="rm-modal-overlay rm-ai-overlay" id="pn-award-info-overlay" role="dialog" aria-modal="true" aria-labelledby="pn-award-info-title" aria-hidden="true" data-uir="<?= htmlspecialchars(UIR, ENT_QUOTES) ?>" data-ai-toggle-hidden="1" hidden>
	<div class="rm-modal pn-ai-box">
		<div class="rm-ai-head">
			<h2 class="rm-modal-title" id="pn-award-info-title"><span id="pn-award-info-name">Award Details</span></h2>
			<button type="button" class="rm-ai-close" id="pn-award-info-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-ai-body rm-ai-body" id="pn-award-info-body"></div>
		<div class="rm-modal-actions">
			<a class="rm-btn rm-btn-ghost" id="pn-award-info-view" style="display:none" target="_blank" rel="noopener"><i class="fas fa-eye"></i> View scroll</a>
			<a class="rm-btn rm-btn-primary" id="pn-award-info-print" style="display:none" target="_blank" rel="noopener"><i class="fas fa-print"></i> Print scroll</a>
			<button type="button" class="rm-btn rm-btn-ghost" id="pn-award-info-done">Close</button>
		</div>
	</div>
</div>
<?php } else { ?>
<div class="pn-overlay" id="pn-award-info-overlay" role="dialog" aria-modal="true" aria-labelledby="pn-award-info-title" aria-hidden="true" data-uir="<?= htmlspecialchars(UIR, ENT_QUOTES) ?>">
	<div class="pn-modal-box pn-ai-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title" id="pn-award-info-title"><i class="fas fa-info-circle pn-modal-title-icon"></i><span id="pn-award-info-name">Award Details</span></h3>
			<button type="button" class="pn-modal-close-btn" id="pn-award-info-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body pn-ai-body" id="pn-award-info-body"></div>
		<div class="pn-modal-footer">
			<?php /* Shown by award-info-modal.js only when PlayerAjax/award_info says CanPrint
			         for this viewer (the render route's own gate), so never a dead link. */ ?>
			<a class="pn-btn pn-btn-secondary" id="pn-award-info-view" style="display:none"
			   target="_blank" rel="noopener"><i class="fas fa-eye"></i> View scroll</a>
			<a class="pn-btn pn-btn-primary" id="pn-award-info-print" style="display:none"
			   target="_blank" rel="noopener"><i class="fas fa-print"></i> Print scroll</a>
			<button type="button" class="pn-btn pn-btn-secondary" id="pn-award-info-done">Close</button>
		</div>
	</div>
</div>
<?php } ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/award-info-modal.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/award-info-modal.js') ?>"></script>
