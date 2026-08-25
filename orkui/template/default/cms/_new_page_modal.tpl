<?php
/**
 * cms/_new_page_modal.tpl — the "Create a page" type chooser.
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * The New-Page entry point appears on both the Dashboard and the Pages list, so
 * the chooser lives here and both surfaces include it after their content. The
 * opener is the host's own `#cmsNewBtn`-style button; this partial only renders
 * the dialog.
 *
 * Receives (from the host template, before including):
 *   $canCreate bool   whether the viewer may create pages (nothing renders if not)
 *   $pageTypes array  list of ['type'=>..,'label'=>..,'description'=>?..]
 *   $scopeQ    string '&scope=k:5' (or '') appended to the edit link
 *   $h         callable  the host's htmlspecialchars closure
 */
?>
<?php if ($canCreate): ?>
<div class="cms-modal-overlay" id="cmsNewModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Choose a page type">
        <div class="cms-modal-head">
            <h3>Create a page</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p class="cms-muted" style="margin-top:0;font-size:13px;">Pick a starting layout. You can add or remove any block afterward.</p>
            <div class="cms-typegrid">
                <?php foreach ($pageTypes as $pt): ?>
                    <?php // Plain-language description only — never the raw type slug (dev jargon). ?>
                    <a class="cms-typecard" href="<?= UIR ?>Cms/edit/new&type=<?= $h($pt['type']) ?><?= $scopeQ ?>">
                        <strong><?= $h($pt['label']) ?></strong>
                        <?php if (!empty($pt['description'])): ?>
                            <span><?= $h($pt['description']) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
