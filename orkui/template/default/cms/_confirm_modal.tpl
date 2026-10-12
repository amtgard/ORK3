<?php
/**
 * cms/_confirm_modal.tpl — the shared OGRE confirm dialog.
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * One dialog serves every destructive CMS action (native confirm() is banned).
 * The markup is static: title, body, the optional detail region and the primary
 * button are all set per call by CmsAdmin.confirm() in script/cms-admin.js,
 * which resolves these ids lazily. Include it once per surface, after the page
 * content.
 *
 * Receives: nothing.
 */
?>
<div class="cms-modal-overlay" id="cmsConfirmModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cmsConfirmTitle">
        <div class="cms-modal-head">
            <h3 id="cmsConfirmTitle">Please confirm</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p class="cms-confirm-body" id="cmsConfirmBody"></p>
            <div id="cmsConfirmExtra"></div>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal id="cmsConfirmCancel">Cancel</button>
            <button type="button" class="cms-btn cms-btn-danger" id="cmsConfirmOk">Delete</button>
        </div>
    </div>
</div>
