<?php
/**
 * Cms_nav.tpl — CMS navigation manager (the 'marketing' menu).
 * PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Receives (from Controller_Cms::nav):
 *   $Menu        menu name ('marketing')
 *   $NavItems    flat list of items (incl. disabled) from CmsNav::ListItems:
 *                ['nav_id','label','link_type','href','target','enabled'(bool),
 *                 'parent_id'(int|null),'ordering','page_id','post_id','url',
 *                 'target_label']
 *   $PickerPages list of ['page_id','title','slug','status', ...]
 *   $PickerPosts list of ['post_id','title','slug','status', ...]
 *   $Caps        ['create','edit','publish','delete','media','nav','roles' => bool]
 *   $Message     (optional) flash/notice string
 *   UIR, HTTP_TEMPLATE (constants)
 */

$menu     = isset($Menu) ? (string)$Menu : 'marketing';
$navItems = isset($NavItems) && is_array($NavItems) ? $NavItems : array();
$pages    = isset($PickerPages) && is_array($PickerPages) ? $PickerPages : array();
$posts    = isset($PickerPosts) && is_array($PickerPosts) ? $PickerPosts : array();
$caps     = isset($Caps) && is_array($Caps) ? $Caps : array();
$message  = isset($Message) ? (string)$Message : '';

$canManage = !empty($caps['nav']);

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Assemble the flat list into a top-level + children tree (one dropdown level).
$top = array();
$childrenByParent = array();
foreach ($navItems as $row) {
    $pid = isset($row['parent_id']) && $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
    if ($pid === 0) {
        $top[] = $row;
    } else {
        if (!isset($childrenByParent[$pid])) {
            $childrenByParent[$pid] = array();
        }
        $childrenByParent[$pid][] = $row;
    }
}

// Renderer for one item row (top-level or child).
$renderItem = function ($item, $isChild) use ($h, $canManage) {
    $navId    = (int)($item['nav_id'] ?? 0);
    $label    = (string)($item['label'] ?? '');
    $linkType = (string)($item['link_type'] ?? 'page');
    $href     = (string)($item['href'] ?? '#');
    $enabled  = !empty($item['enabled']);
    $tlabel   = (string)($item['target_label'] ?? '');
    // Same words the Add/Edit dialog uses for these choices — "Route" was the
    // developer's name for it, and no park officer has ever called a menu entry
    // that.
    $linkTypeLabel = array(
        'page' => 'Site page', 'post' => 'Blog post', 'url' => 'External link', 'dynamic' => 'ORK app page',
    );
    $ltl = isset($linkTypeLabel[$linkType]) ? $linkTypeLabel[$linkType] : ucfirst($linkType);
    $linkTypeIcon = array(
        'url' => 'fa-globe', 'page' => 'fa-file', 'post' => 'fa-newspaper', 'dynamic' => 'fa-route',
    );
    $lti = isset($linkTypeIcon[$linkType]) ? $linkTypeIcon[$linkType] : 'fa-link';
    // The middle column repeated the label on most rows ("About" → "About")
    // and differed on exactly the rows worth noticing ("Join" → "Join Now",
    // "Find a Chapter" → "Atlas"). Repeating it taught an author to stop reading
    // the column, which is where the differences were hiding. Show it only when
    // it actually says something the label does not. (A bare "#" never does.)
    $showTarget = ($tlabel !== '' && $tlabel !== '#' && strcasecmp(trim($tlabel), trim($label)) !== 0);
    // Every row tool names the ACTION and the ITEM it acts on: five identical
    // grey glyphs are a guessing game otherwise.
    $named = $label !== '' ? $label : '(untitled)';
    ?>
    <div class="cms-block-card<?= $enabled ? '' : ' cms-block-disabled' ?> cms-nav-item"
         data-nav-id="<?= $navId ?>"
         data-child="<?= $isChild ? 1 : 0 ?>"
         data-label="<?= $h($label) ?>"
         data-link-type="<?= $h($linkType) ?>"
         data-page-id="<?= (int)($item['page_id'] ?? 0) ?>"
         data-post-id="<?= (int)($item['post_id'] ?? 0) ?>"
         data-url="<?= $h((string)($item['url'] ?? '')) ?>"
         data-enabled="<?= $enabled ? 1 : 0 ?>"
         data-parent-id="<?= isset($item['parent_id']) && $item['parent_id'] !== null ? (int)$item['parent_id'] : 0 ?>">
        <div class="cms-block-head">
            <div class="cms-block-type">
                <i class="fas <?= $h($lti) ?> cms-nav-typeicon" aria-hidden="true" data-tip="<?= $h($ltl) ?>"></i>
                <span class="cms-nav-label"><?= $h($label !== '' ? $label : '(untitled)') ?></span>
            </div>
            <?php if ($showTarget): ?>
                <span class="cms-block-summary cms-nav-target" data-tip="Labelled “<?= $h($named) ?>” in the menu; points at “<?= $h($tlabel) ?>”">
                    <i class="fas fa-arrow-right cms-nav-target-arrow" aria-hidden="true"></i><span class="cms-nav-target-text"><?= $h($tlabel) ?></span>
                </span>
            <?php endif; ?>
            <?php if (!$enabled): ?>
                <span class="ork-badge cms-badge cms-badge-draft" style="margin-left:6px;">Hidden</span>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <div class="cms-block-tools">
                <button type="button" class="cms-icon-btn" data-act="up" data-tip="Move “<?= $h($named) ?>” up" aria-label="Move “<?= $h($named) ?>” up"><i class="fas fa-arrow-up" aria-hidden="true"></i></button>
                <button type="button" class="cms-icon-btn" data-act="down" data-tip="Move “<?= $h($named) ?>” down" aria-label="Move “<?= $h($named) ?>” down"><i class="fas fa-arrow-down" aria-hidden="true"></i></button>
                <?php if (!$isChild): ?>
                    <?php /* The third glyph was fa-level-down-alt — a corner arrow that
                            reads as "indent", "reply", or "move into", and is the one
                            tool on this row nobody could name. It adds a drop-down entry
                            underneath this one; fa-sitemap says hierarchy, and the tip
                            says the rest. */ ?>
                    <button type="button" class="cms-icon-btn" data-act="addchild" data-tip="Add a drop-down sub-item under “<?= $h($named) ?>”" aria-label="Add a drop-down sub-item under “<?= $h($named) ?>”"><i class="fas fa-sitemap" aria-hidden="true"></i></button>
                <?php endif; ?>
                <button type="button" class="cms-icon-btn" data-act="edit" data-tip="Edit “<?= $h($named) ?>”" aria-label="Edit “<?= $h($named) ?>”"><i class="fas fa-pen" aria-hidden="true"></i></button>
                <button type="button" class="cms-icon-btn cms-icon-danger" data-act="delete" data-tip="Delete “<?= $h($named) ?>”" aria-label="Delete “<?= $h($named) ?>”"><i class="fas fa-trash" aria-hidden="true"></i></button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>

<?php // Navigation-manager styling (.cms-nav-*) lives in the shared, cacheable
      // cms-admin.css (section "Navigation manager"), loaded once by
      // cms/_shell_top.tpl below — no per-render inline block. ?>

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'nav';
$cmsTitle   = 'Navigation';
$cmsSub     = 'Public site menu';
$cmsActions = $canManage
    ? '<button type="button" class="cms-btn cms-btn-primary" id="cmsNavAddBtn"><i class="fas fa-plus"></i> Add Item</button>'
    : '';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <?php if ($message !== ''): ?>
        <div class="cms-notice"><?= $h($message) ?></div>
    <?php endif; ?>

    <p class="cms-muted" style="font-size:13px;margin-top:0;">
        Manage the front-door (<strong><?= $h($menu) ?></strong>) navigation menu. Reorder with the
        arrows; top-level items can hold one level of drop-down sub-items.
    </p>

    <div id="cmsNavTree">
        <?php if (empty($top)): ?>
            <div class="cms-empty">
                <div class="cms-empty-icon"><i class="fas fa-bars"></i></div>
                <div class="cms-empty-copy">No navigation items yet.</div>
                <?php if ($canManage): ?>
                    <button type="button" class="cms-btn cms-btn-primary cms-empty-cta" id="cmsNavAddBtnEmpty">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($top as $item):
                $tid = (int)($item['nav_id'] ?? 0);
                $kids = isset($childrenByParent[$tid]) ? $childrenByParent[$tid] : array();
            ?>
                <div class="cms-nav-group" data-group-id="<?= $tid ?>">
                    <?php $renderItem($item, false); ?>
                    <div class="cms-nav-children" data-children-of="<?= $tid ?>">
                        <?php foreach ($kids as $kid): ?>
                            <?php $renderItem($kid, true); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<?php /* ---- Edit / Add item modal ---- */ ?>
<?php if ($canManage): ?>
<div class="cms-modal-overlay" id="cmsNavModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Navigation item">
        <div class="cms-modal-head">
            <h3 id="cmsNavModalTitle">Add navigation item</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <input type="hidden" id="cmsNavFieldId" value="0">
            <input type="hidden" id="cmsNavFieldParentId" value="0">

            <div class="cms-field">
                <label class="cms-label" for="cmsNavFieldLabel">Label</label>
                <input type="text" class="cms-input" id="cmsNavFieldLabel" maxlength="160" placeholder="e.g. About">
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsNavFieldType">Link type</label>
                <select class="cms-select" id="cmsNavFieldType">
                    <option value="page">Site Page</option>
                    <option value="post">Blog Post</option>
                    <option value="url">External URL</option>
                    <option value="dynamic">Link to an ORK app page (advanced)</option>
                </select>
            </div>

            <div class="cms-field cms-nav-picker" data-picker="page">
                <label class="cms-label" for="cmsNavFieldPage">Page</label>
                <select class="cms-select" id="cmsNavFieldPage">
                    <option value="0">— Select a page —</option>
                    <?php foreach ($pages as $pg):
                        $pgId = (int)($pg['page_id'] ?? 0);
                        $pgT  = (string)($pg['title'] ?? '(untitled)');
                        $pgS  = (string)($pg['slug'] ?? '');
                        $pgStat = (string)($pg['status'] ?? '');
                    ?>
                        <option value="<?= $pgId ?>"><?= $h($pgT) ?><?= $pgS !== '' ? ' (/' . $h($pgS) . ')' : '' ?><?= $pgStat === 'draft' ? ' — draft' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cms-field cms-nav-picker" data-picker="post">
                <label class="cms-label" for="cmsNavFieldPost">Post</label>
                <select class="cms-select" id="cmsNavFieldPost">
                    <option value="0">— Select a post —</option>
                    <?php foreach ($posts as $po):
                        $poId = (int)($po['post_id'] ?? 0);
                        $poT  = (string)($po['title'] ?? '(untitled)');
                        $poS  = (string)($po['slug'] ?? '');
                        $poStat = (string)($po['status'] ?? '');
                    ?>
                        <option value="<?= $poId ?>"><?= $h($poT) ?><?= $poS !== '' ? ' (/' . $h($poS) . ')' : '' ?><?= $poStat === 'draft' ? ' — draft' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cms-field cms-nav-picker" data-picker="url">
                <label class="cms-label" for="cmsNavFieldUrl">URL</label>
                <input type="text" class="cms-input" id="cmsNavFieldUrl" maxlength="512" placeholder="https://example.com">
                <div class="cms-help">A full external link. Off-site links open in a new tab.</div>
            </div>

            <div class="cms-field cms-nav-picker" data-picker="dynamic">
                <label class="cms-label" for="cmsNavFieldRoute">ORK app page</label>
                <input type="text" class="cms-input" id="cmsNavFieldRoute" maxlength="512" placeholder="e.g. Directory/index">
                <div class="cms-help">Links to a built-in ORK application page (not a CMS page). Enter the page's internal address, for example <code>Directory/index</code>. Most menus should use a <strong>CMS Page</strong> or <strong>External URL</strong> instead.</div>
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsNavFieldParentSel">Parent (drop-down)</label>
                <select class="cms-select" id="cmsNavFieldParentSel">
                    <option value="0">— Top level —</option>
                    <?php foreach ($top as $item):
                        $tid = (int)($item['nav_id'] ?? 0);
                        $tlb = (string)($item['label'] ?? '');
                    ?>
                        <option value="<?= $tid ?>"><?= $h($tlb !== '' ? $tlb : '(untitled)') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="cms-help">Sub-items appear in a drop-down under the chosen top-level item.</div>
            </div>

            <div class="cms-field" style="display:flex;align-items:center;gap:10px;">
                <label class="cms-switch">
                    <input type="checkbox" id="cmsNavFieldEnabled" checked>
                    <span class="cms-slider"></span>
                </label>
                <span class="cms-label" style="margin:0;">Visible in the menu</span>
            </div>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="cms-btn cms-btn-primary" id="cmsNavSave">Save</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/cms/_confirm_modal.tpl'; ?>
<?php endif; ?>

<div class="cms-toast" id="cmsToast" role="status" aria-live="polite" aria-atomic="true"></div>

<?php if ($canManage): ?>
<script>
(function () {
    'use strict';
    var UIR  = window.CMS_UIR;
    var AJAX = UIR + 'CmsAjax/';
    var MENU = <?= json_encode($menu) ?>;

    /* ---- toast (shared: CmsAdmin.toast) ---- */
    var toast = CmsAdmin.toast;

    /* ---- modal helpers (shared: CmsAdmin.modal; backdrop/Esc handled there) ---- */
    var openModal = CmsAdmin.modal.open;
    var closeModal = CmsAdmin.modal.close;

    /* ---- POST helper (shared: CmsAdmin.post — CSRF header + scope) ---- */
    var post = CmsAdmin.post;

    /* ---- Edit/Add modal wiring ---- */
    var modal   = document.getElementById('cmsNavModal');
    var fId     = document.getElementById('cmsNavFieldId');
    var fParent = document.getElementById('cmsNavFieldParentId');
    var fLabel  = document.getElementById('cmsNavFieldLabel');
    var fType   = document.getElementById('cmsNavFieldType');
    var fPage   = document.getElementById('cmsNavFieldPage');
    var fPost   = document.getElementById('cmsNavFieldPost');
    var fUrl    = document.getElementById('cmsNavFieldUrl');
    var fRoute  = document.getElementById('cmsNavFieldRoute');
    var fParentSel = document.getElementById('cmsNavFieldParentSel');
    var fEnabled = document.getElementById('cmsNavFieldEnabled');
    var modalTitle = document.getElementById('cmsNavModalTitle');

    // Show only the picker that matches the selected link type.
    function syncPickers() {
        var t = fType.value;
        document.querySelectorAll('.cms-nav-picker').forEach(function (el) {
            el.style.display = (el.getAttribute('data-picker') === t) ? '' : 'none';
        });
    }
    fType.addEventListener('change', syncPickers);

    function resetForm() {
        fId.value = '0';
        fParent.value = '0';
        fLabel.value = '';
        fType.value = 'page';
        fPage.value = '0';
        fPost.value = '0';
        fUrl.value = '';
        fRoute.value = '';
        fParentSel.value = '0';
        fEnabled.checked = true;
        // Re-enable every parent option that a prior edit may have hidden.
        Array.prototype.forEach.call(fParentSel.options, function (opt) {
            opt.disabled = false;
            opt.hidden = false;
        });
        syncPickers();
    }

    function openAdd(parentId) {
        resetForm();
        if (parentId && parentId > 0) {
            fParentSel.value = String(parentId);
        }
        modalTitle.textContent = 'Add navigation item';
        openModal(modal);
        fLabel.focus();
    }

    function openEditFromCard(card) {
        resetForm();
        fId.value     = card.getAttribute('data-nav-id') || '0';
        // An item can't be its own parent — exclude its own option.
        var ownId = card.getAttribute('data-nav-id') || '0';
        // The menu is exactly one level deep. A top-level item that already has
        // sub-items therefore can't be nested under another item: its own
        // children would land at depth 3, where nothing renders them.
        var ownGroup = card.closest('.cms-nav-group');
        var hasChildren = card.getAttribute('data-child') === '0' && !!ownGroup
            && !!ownGroup.querySelector(':scope > .cms-nav-children > .cms-nav-item');
        Array.prototype.forEach.call(fParentSel.options, function (opt) {
            if (opt.value === ownId && ownId !== '0') {
                opt.disabled = true;
                opt.hidden = true;
            } else if (hasChildren && opt.value !== '0') {
                opt.disabled = true;
            }
        });
        fLabel.value  = card.getAttribute('data-label') || '';
        fType.value   = card.getAttribute('data-link-type') || 'page';
        fPage.value   = card.getAttribute('data-page-id') || '0';
        fPost.value   = card.getAttribute('data-post-id') || '0';
        var rawUrl    = card.getAttribute('data-url') || '';
        if (fType.value === 'dynamic') { fRoute.value = rawUrl; } else { fUrl.value = rawUrl; }
        fParentSel.value = card.getAttribute('data-parent-id') || '0';
        fEnabled.checked = card.getAttribute('data-enabled') === '1';
        syncPickers();
        modalTitle.textContent = 'Edit navigation item';
        openModal(modal);
        fLabel.focus();
    }

    var addBtn = document.getElementById('cmsNavAddBtn');
    if (addBtn) { addBtn.addEventListener('click', function () { openAdd(0); }); }
    var addBtnEmpty = document.getElementById('cmsNavAddBtnEmpty');
    if (addBtnEmpty) { addBtnEmpty.addEventListener('click', function () { openAdd(0); }); }

    /* ---- Save ---- */
    var saveBtn = document.getElementById('cmsNavSave');
    if (saveBtn) { saveBtn.addEventListener('click', function () {
        var type = fType.value;
        var params = {
            nav_id:    fId.value,
            label:     fLabel.value.trim(),
            link_type: type,
            parent_id: fParentSel.value || '0',
            enabled:   fEnabled.checked ? 1 : 0
        };
        if (type === 'page') {
            params.page_id = fPage.value || '0';
        } else if (type === 'post') {
            params.post_id = fPost.value || '0';
        } else if (type === 'url') {
            params.url = fUrl.value.trim();
        } else if (type === 'dynamic') {
            params.url = fRoute.value.trim();
        }

        if (params.label === '') { toast('A label is required.', 'error'); fLabel.focus(); return; }

        saveBtn.disabled = true;
        post('savenavitem', params).then(function (res) {
            saveBtn.disabled = false;
            if (!res || !res.ok) { toast((res && res.error) || 'Save failed.', 'error'); return; }
            closeModal(modal);
            toast('Navigation saved.', 'ok');
            // Reload to re-render the tree with resolved labels/targets.
            reloadAfterOrderFlush();
        }).catch(function () { saveBtn.disabled = false; toast('Network error.', 'error'); });
    }); }

    /* ---- Card actions (edit / delete / add child / move) ---- */

    document.getElementById('cmsNavTree').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) { return; }
        var card = btn.closest('.cms-nav-item');
        if (!card) { return; }
        var act = btn.getAttribute('data-act');
        var navId = card.getAttribute('data-nav-id');

        if (act === 'edit') {
            openEditFromCard(card);
        } else if (act === 'addchild') {
            openAdd(parseInt(navId, 10) || 0);
        } else if (act === 'delete') {
            askDeleteNavItem(navId, card);
        } else if (act === 'up' || act === 'down') {
            moveCard(card, act);
        }
    });

    /* Deleting a nav item is irreversible, so it goes through the shared confirm
       dialog (CmsAdmin.confirm / cms/_confirm_modal.tpl). */
    function askDeleteNavItem(navId, card) {
        var label = card.getAttribute('data-label') || 'this item';
        var isParent = card.getAttribute('data-child') === '0';
        CmsAdmin.confirm(
            'Please confirm',
            'Delete "' + label + '"?'
                + (isParent ? ' This also removes any sub-items under it.' : '')
                + ' This cannot be undone.',
            'Delete',
            function () {
                CmsAdmin.confirmBusy(true);
                post('deletenavitem', { nav_id: navId }).then(function (res) {
                    CmsAdmin.confirmBusy(false);
                    CmsAdmin.confirmClose();
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    toast('Item deleted.', 'ok');
                    reloadAfterOrderFlush();
                }).catch(function () { CmsAdmin.confirmClose(); toast('Network error.', 'error'); });
            }
        );
    }

    /* ---- Reorder rollback ----
     * The click handler is delegated on #cmsNavTree itself, so snapshotting and
     * restoring the tree's innerHTML is safe (no per-card listeners are lost).
     * preMoveOrderHtml holds the last KNOWN-GOOD (persisted) order; it is captured
     * before the first move of a not-yet-saved burst and used to revert if the
     * debounced reordernav POST fails. */
    var navTreeEl = document.getElementById('cmsNavTree');
    var preMoveOrderHtml = null;

    /* ---- Move up/down within the item's sibling group, then persist order ---- */
    function moveCard(card, dir) {
        // Snapshot the pre-move order before the FIRST move of a pending batch, so a
        // failed save can roll the whole burst back to the last persisted state.
        if (preMoveOrderHtml === null && navTreeEl) { preMoveOrderHtml = navTreeEl.innerHTML; }
        // The reorderable unit at top level is the .cms-nav-group wrapper;
        // children move within their .cms-nav-children container.
        var isChild = card.getAttribute('data-child') === '1';
        var movable = isChild ? card : card.closest('.cms-nav-group');
        if (!movable) { return; }
        var container = movable.parentNode;
        if (dir === 'up') {
            var prev = movable.previousElementSibling;
            while (prev && !matchesMovable(prev, isChild)) { prev = prev.previousElementSibling; }
            if (prev) { container.insertBefore(movable, prev); }
        } else {
            var next = movable.nextElementSibling;
            while (next && !matchesMovable(next, isChild)) { next = next.nextElementSibling; }
            if (next) { container.insertBefore(next, movable); }
        }
        refreshMoveArrows();
        persistOrder();
    }
    function matchesMovable(el, isChild) {
        return isChild ? el.classList.contains('cms-nav-item') : el.classList.contains('cms-nav-group');
    }

    // Disable the first item's "up" arrow and the last item's "down" arrow in
    // every sibling group, so end-of-list clicks aren't dead.
    function refreshMoveArrows() {
        // Top-level groups are the reorderable units at the root.
        var topGroups = document.querySelectorAll('#cmsNavTree > .cms-nav-group');
        setEndArrows(Array.prototype.map.call(topGroups, function (g) {
            return g.querySelector(':scope > .cms-nav-item');
        }));
        // Each top-level group's children form their own sibling list.
        document.querySelectorAll('#cmsNavTree .cms-nav-children').forEach(function (box) {
            var kids = box.querySelectorAll(':scope > .cms-nav-item');
            setEndArrows(Array.prototype.slice.call(kids));
        });
    }
    function setEndArrows(cards) {
        cards = cards.filter(function (c) { return !!c; });
        cards.forEach(function (card, i) {
            var up = card.querySelector('[data-act="up"]');
            var down = card.querySelector('[data-act="down"]');
            if (up) { up.disabled = (i === 0); }
            if (down) { down.disabled = (i === cards.length - 1); }
        });
    }

    // Walk the rendered tree → ordered [{nav_id,parent_id,ordering}].
    function collectOrder() {
        var items = [];
        var groups = document.querySelectorAll('#cmsNavTree .cms-nav-group');
        var topOrder = 0;
        groups.forEach(function (group) {
            var topCard = group.querySelector(':scope > .cms-nav-item');
            if (!topCard) { return; }
            var topId = parseInt(topCard.getAttribute('data-nav-id'), 10) || 0;
            items.push({ nav_id: topId, parent_id: 0, ordering: topOrder });
            topOrder += 10;
            var childOrder = 0;
            group.querySelectorAll(':scope > .cms-nav-children > .cms-nav-item').forEach(function (childCard) {
                var cid = parseInt(childCard.getAttribute('data-nav-id'), 10) || 0;
                items.push({ nav_id: cid, parent_id: topId, ordering: childOrder });
                childOrder += 10;
            });
        });
        return items;
    }

    // Debounce the save so a burst of arrow clicks → one POST + one toast.
    var reorderTimer = null;
    var reorderSeq = 0;
    function sendOrder() {
        reorderTimer = null;
        var token = ++reorderSeq;
        var sentHtml = navTreeEl ? navTreeEl.innerHTML : null; // the order THIS request carries
        var items = collectOrder();
        return post('reordernav', { menu: MENU, items: JSON.stringify(items) }).then(function (res) {
            if (!res || !res.ok) { revertOrder((res && res.error) || 'Order not saved — retry.'); return; }
            // Only the newest request may touch the rollback snapshot; clearing it
            // from a stale response would disarm the rollback for a request that is
            // still outstanding. And if a later reorder is still pending — scheduled
            // in the debounce timer OR in flight — the order this request just
            // persisted becomes the rollback baseline instead of being cleared.
            if (token === reorderSeq) {
                preMoveOrderHtml = (reorderTimer === null) ? null : sentHtml;
            }
            toast('Order saved.', 'ok');
            refreshMoveArrows();
        }).catch(function () { revertOrder('Order not saved — check your connection and retry.'); });
    }
    function persistOrder() {
        clearTimeout(reorderTimer);
        reorderTimer = setTimeout(sendOrder, 500);
    }

    // A full page reload tears down the pending debounce timer, silently dropping
    // the reorder. Anything that reloads must flush first.
    function flushOrder() {
        if (reorderTimer !== null) {
            clearTimeout(reorderTimer);
            return sendOrder();
        }
        return Promise.resolve();
    }
    function reloadAfterOrderFlush() {
        var go = function () { window.location.reload(); };
        flushOrder().then(go, go);
    }

    // The save failed — roll the tree back to the last persisted order so the
    // on-screen order never diverges from what's stored, and surface a persistent
    // error toast prompting a retry (rather than a silent success illusion).
    function revertOrder(msg) {
        if (preMoveOrderHtml !== null && navTreeEl) {
            navTreeEl.innerHTML = preMoveOrderHtml;
            preMoveOrderHtml = null;
        }
        refreshMoveArrows();
        toast(msg, 'error');
    }

    syncPickers();
    refreshMoveArrows();
})();
</script>
<?php endif; ?>
