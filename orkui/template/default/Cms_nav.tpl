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
    $linkTypeLabel = array(
        'page' => 'Page', 'post' => 'Post', 'url' => 'URL', 'dynamic' => 'Route',
    );
    $ltl = isset($linkTypeLabel[$linkType]) ? $linkTypeLabel[$linkType] : ucfirst($linkType);
    $linkTypeIcon = array(
        'url' => 'fa-globe', 'page' => 'fa-file', 'post' => 'fa-newspaper', 'dynamic' => 'fa-route',
    );
    $lti = isset($linkTypeIcon[$linkType]) ? $linkTypeIcon[$linkType] : 'fa-link';
    // Suppress a bare "#" target — there's nothing meaningful to show.
    $showTarget = ($tlabel !== '' && $tlabel !== '#');
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
                <span class="cms-block-summary cms-nav-target"><?= $h($tlabel) ?></span>
            <?php endif; ?>
            <?php if (!$enabled): ?>
                <span class="cms-badge cms-badge-draft" style="margin-left:6px;">Hidden</span>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <div class="cms-block-tools">
                <button type="button" class="cms-icon-btn" data-act="up" data-tip="Move up"><i class="fas fa-arrow-up"></i></button>
                <button type="button" class="cms-icon-btn" data-act="down" data-tip="Move down"><i class="fas fa-arrow-down"></i></button>
                <?php if (!$isChild): ?>
                    <button type="button" class="cms-icon-btn" data-act="addchild" data-tip="Add sub-item"><i class="fas fa-level-down-alt"></i></button>
                <?php endif; ?>
                <button type="button" class="cms-icon-btn" data-act="edit" data-tip="Edit"><i class="fas fa-pen"></i></button>
                <button type="button" class="cms-icon-btn cms-icon-danger" data-act="delete" data-tip="Delete"><i class="fas fa-trash"></i></button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

<style>
/* Navigation manager — small layout tweaks on top of cms-admin.css. */
.cms-nav-item { margin-bottom: 8px; }
.cms-nav-children { margin: 4px 0 12px 28px; border-left: 2px solid var(--ork-border); padding-left: 12px; }
.cms-nav-children .cms-nav-item { margin-bottom: 6px; }
.cms-block-type { display: flex; align-items: center; gap: 8px; }
.cms-nav-typeicon { color: var(--cms-gold, #f0b429); font-size: 13px; width: 16px; text-align: center; flex: 0 0 auto; }
.cms-nav-label { font-weight: 600; color: var(--ork-text); }
.cms-nav-target { color: var(--ork-text-muted); font-size: 12.5px; margin-left: auto; padding: 0 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 40%; }
.cms-nav-empty-children { font-size: 12px; color: var(--ork-text-muted); padding: 2px 0 6px 28px; }
.cms-nav-addchild-row { margin: 0 0 16px 28px; }
</style>

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
            <input type="hidden" id="navFieldId" value="0">
            <input type="hidden" id="navFieldParentId" value="0">

            <div class="cms-field">
                <label class="cms-label" for="navFieldLabel">Label</label>
                <input type="text" class="cms-input" id="navFieldLabel" maxlength="160" placeholder="e.g. About">
            </div>

            <div class="cms-field">
                <label class="cms-label" for="navFieldType">Link type</label>
                <select class="cms-select" id="navFieldType">
                    <option value="page">CMS Page</option>
                    <option value="post">Blog Post</option>
                    <option value="url">External URL</option>
                    <option value="dynamic">Internal Route</option>
                </select>
            </div>

            <div class="cms-field cms-nav-picker" data-picker="page">
                <label class="cms-label" for="navFieldPage">Page</label>
                <select class="cms-select" id="navFieldPage">
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
                <label class="cms-label" for="navFieldPost">Post</label>
                <select class="cms-select" id="navFieldPost">
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
                <label class="cms-label" for="navFieldUrl">URL</label>
                <input type="text" class="cms-input" id="navFieldUrl" maxlength="512" placeholder="https://example.com">
                <div class="cms-help">A full external link. Off-site links open in a new tab.</div>
            </div>

            <div class="cms-field cms-nav-picker" data-picker="dynamic">
                <label class="cms-label" for="navFieldRoute">Internal route</label>
                <input type="text" class="cms-input" id="navFieldRoute" maxlength="512" placeholder="Directory/index">
                <div class="cms-help">An ORK route key, e.g. <code>Directory/index</code>.</div>
            </div>

            <div class="cms-field">
                <label class="cms-label" for="navFieldParentSel">Parent (drop-down)</label>
                <select class="cms-select" id="navFieldParentSel">
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
                    <input type="checkbox" id="navFieldEnabled" checked>
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

<?php /* ---- Confirm modal (Delete) ---- */ ?>
<div class="cms-modal-overlay" id="cmsConfirmModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Confirm">
        <div class="cms-modal-head">
            <h3 id="cmsConfirmTitle">Please confirm</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p id="cmsConfirmBody" style="margin:0;font-size:14px;"></p>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="cms-btn cms-btn-danger" id="cmsConfirmOk">Delete</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="cms-toast" id="cmsToast" role="status" aria-live="polite" aria-atomic="true"></div>

<?php if ($canManage): ?>
<script>
(function () {
    'use strict';
    var UIR  = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';
    var MENU = <?= json_encode($menu) ?>;

    /* ---- toast ---- */
    var toastEl = document.getElementById('cmsToast');
    var toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'cms-toast'; }, 3200);
    }

    /* ---- modal helpers ---- */
    function openModal(el) { if (el) { el.classList.add('cms-open'); } }
    function closeModal(el) { if (el) { el.classList.remove('cms-open'); } }
    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('.cms-modal-overlay')); return; }
        if (e.target.classList && e.target.classList.contains('cms-modal-overlay')) {
            closeModal(e.target);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cms-modal-overlay.cms-open').forEach(closeModal);
        }
    });

    /* ---- POST helper ---- */
    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(AJAX + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ---- Edit/Add modal wiring ---- */
    var modal   = document.getElementById('cmsNavModal');
    var fId     = document.getElementById('navFieldId');
    var fParent = document.getElementById('navFieldParentId');
    var fLabel  = document.getElementById('navFieldLabel');
    var fType   = document.getElementById('navFieldType');
    var fPage   = document.getElementById('navFieldPage');
    var fPost   = document.getElementById('navFieldPost');
    var fUrl    = document.getElementById('navFieldUrl');
    var fRoute  = document.getElementById('navFieldRoute');
    var fParentSel = document.getElementById('navFieldParentSel');
    var fEnabled = document.getElementById('navFieldEnabled');
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
        Array.prototype.forEach.call(fParentSel.options, function (opt) {
            if (opt.value === ownId && ownId !== '0') {
                opt.disabled = true;
                opt.hidden = true;
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
            window.location.reload();
        }).catch(function () { saveBtn.disabled = false; toast('Network error.', 'error'); });
    }); }

    /* ---- Card actions (edit / delete / add child / move) ---- */
    var confirmModal = document.getElementById('cmsConfirmModal');
    var confirmBody  = document.getElementById('cmsConfirmBody');
    var confirmOk    = document.getElementById('cmsConfirmOk');
    var pendingDeleteId = null;

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
            pendingDeleteId = navId;
            var label = card.getAttribute('data-label') || 'this item';
            var isParent = card.getAttribute('data-child') === '0';
            if (confirmBody) {
                confirmBody.textContent = 'Delete "' + label + '"?'
                    + (isParent ? ' This also removes any sub-items under it.' : '')
                    + ' This cannot be undone.';
            }
            openModal(confirmModal);
        } else if (act === 'up' || act === 'down') {
            moveCard(card, act);
        }
    });

    if (confirmOk) {
        confirmOk.addEventListener('click', function () {
            if (!pendingDeleteId) { return; }
            confirmOk.disabled = true;
            post('deletenavitem', { nav_id: pendingDeleteId }).then(function (res) {
                confirmOk.disabled = false;
                closeModal(confirmModal);
                if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                pendingDeleteId = null;
                toast('Item deleted.', 'ok');
                window.location.reload();
            }).catch(function () { confirmOk.disabled = false; toast('Network error.', 'error'); });
        });
    }

    /* ---- Move up/down within the item's sibling group, then persist order ---- */
    function moveCard(card, dir) {
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
    function persistOrder() {
        clearTimeout(reorderTimer);
        reorderTimer = setTimeout(function () {
            var items = collectOrder();
            post('reordernav', { menu: MENU, items: JSON.stringify(items) }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Reorder failed.', 'error'); return; }
                toast('Order saved.', 'ok');
                refreshMoveArrows();
            }).catch(function () { toast('Network error.', 'error'); });
        }, 500);
    }

    syncPickers();
    refreshMoveArrows();
})();
</script>
<?php endif; ?>
