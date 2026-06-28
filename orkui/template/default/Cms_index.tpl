<?php
/**
 * Cms_index.tpl — CMS page list. PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Receives (from Controller_Cms::index):
 *   $Pages        list of ['page_id','slug','type','title','status','updated_at', ...]
 *   $Search       current search string
 *   $StatusFilter '', 'draft', or 'published'
 *   $Caps         ['create','edit','publish','delete','media','nav','roles' => bool]
 *   $PageTypes    (optional) list of ['type','label','blocks'] for the New-Page chooser
 *   $Message      (optional) flash/notice string
 *   UIR, HTTP_TEMPLATE (constants)
 */

$pages   = isset($Pages) && is_array($Pages) ? $Pages : array();
$caps    = isset($Caps) && is_array($Caps) ? $Caps : array();
$search  = isset($Search) ? (string)$Search : '';
$statusF = isset($StatusFilter) ? (string)$StatusFilter : '';
$message = isset($Message) ? (string)$Message : '';

// Page-type label lookup for the table + the New-Page chooser.
$pageTypes = isset($PageTypes) && is_array($PageTypes) ? $PageTypes : array(
    array('type' => 'composed',   'label' => 'Composed / Landing'),
    array('type' => 'article',    'label' => 'Article / Text'),
    array('type' => 'media',      'label' => 'Media / Gallery'),
    array('type' => 'resource',   'label' => 'Resource / Document'),
    array('type' => 'blog_index', 'label' => 'Blog Index'),
    array('type' => 'dynamic',    'label' => 'Dynamic Data'),
);
// Prefer the controller's full human-label map (covers legacy/system types not
// in the New-Page chooser); fall back to deriving labels from $pageTypes.
$typeLabels = isset($TypeLabels) && is_array($TypeLabels) ? $TypeLabels : array();
foreach ($pageTypes as $pt) {
    if (!isset($typeLabels[$pt['type']])) {
        $typeLabels[$pt['type']] = $pt['label'];
    }
}

$canCreate  = !empty($caps['create']);
$canEdit    = !empty($caps['edit']);
$canPublish = !empty($caps['publish']);
$canDelete  = !empty($caps['delete']);

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'pages';
$cmsTitle   = 'Pages';
$cmsSub     = 'Static pages on your public site';
$cmsActions = $canCreate
    ? '<button type="button" class="cms-btn cms-btn-primary" id="cmsNewPageBtn"><i class="fas fa-plus"></i> New Page</button>'
    : '';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <?php if ($message !== ''): ?>
        <div class="cms-notice"><?= $h($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($pages)): ?>
    <div class="cms-filters">
        <select id="cmsStatusFilter" class="cms-select" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="Published"<?= $statusF === 'published' ? ' selected' : '' ?>>Published</option>
            <option value="Draft"<?= $statusF === 'draft' ? ' selected' : '' ?>>Draft</option>
        </select>
    </div>

    <?php /* ---- Bulk-action bar (revealed when ≥1 row checked) ---- */ ?>
    <div class="cms-bulkbar" id="cmsBulkBar" role="region" aria-label="Bulk actions">
        <span class="cms-bulkbar-count" id="cmsBulkCount"><i class="fas fa-check-square"></i>0 selected</span>
        <div class="cms-bulkbar-actions">
            <?php if ($canPublish): ?>
                <button type="button" class="cms-btn cms-btn-sm" data-bulk="publish"><i class="fas fa-globe"></i> Publish</button>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" data-bulk="unpublish"><i class="fas fa-eye-slash"></i> Unpublish</button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-danger" data-bulk="delete"><i class="fas fa-trash"></i> Delete</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="cms-table-wrap">
        <table class="cms-table" id="cms-pages-table">
            <thead>
                <tr>
                    <th scope="col" class="cms-check-col"><input type="checkbox" class="cms-check" id="cmsCheckAll" aria-label="Select all pages on this page"></th>
                    <th scope="col">Title</th>
                    <th scope="col">Type</th>
                    <th scope="col">Status</th>
                    <th scope="col">Updated</th>
                    <th scope="col" style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pages)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="cms-empty">
                                <div class="cms-empty-icon"><i class="fas fa-file-alt"></i></div>
                                <div class="cms-empty-copy">No pages yet.</div>
                                <?php if ($canCreate): ?>
                                    <button type="button" class="cms-btn cms-btn-primary cms-empty-cta" id="cmsNewPageEmptyBtn">
                                        <i class="fas fa-plus"></i> New Page
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pages as $p):
                        $pid       = (int)($p['page_id'] ?? 0);
                        $title     = (string)($p['title'] ?? '(untitled)');
                        $slug      = (string)($p['slug'] ?? '');
                        $type      = (string)($p['type'] ?? 'composed');
                        $status    = (string)($p['status'] ?? 'draft');
                        $isSystem  = !empty($p['is_system']);
                        $updated   = (string)($p['updated_at'] ?? '');
                        $typeLabel = isset($typeLabels[$type]) ? $typeLabels[$type] : ucwords(str_replace('_', ' ', $type));
                        $isPub     = ($status === 'published');
                        $updatedFmt = $updated !== '' ? date('M j, Y g:i A', strtotime($updated)) : '—';
                    ?>
                    <tr data-page-id="<?= $pid ?>" data-system="<?= $isSystem ? 1 : 0 ?>">
                        <td class="cms-check-col" data-label="">
                            <input type="checkbox" class="cms-check cms-row-check" value="<?= $pid ?>" aria-label="Select <?= $h($title) ?>">
                        </td>
                        <td data-label="Title">
                            <div class="cms-pg-title"><?= $h($title) ?>
                                <?php if ($isSystem): ?><span class="cms-badge cms-badge-system" style="margin-left:6px;">System</span><?php endif; ?>
                            </div>
                            <?php if ($slug !== ''): ?>
                                <?php if ($isPub): ?>
                                    <?php $liveUrl = ($slug === 'home') ? UIR : UIR . 'Page/view/' . rawurlencode($slug); ?>
                                    <a class="cms-pg-slug cms-pg-slug-link" href="<?= $h($liveUrl) ?>" target="_blank" rel="noopener noreferrer">/<?= $h($slug) ?> <i class="fas fa-external-link-alt"></i></a>
                                <?php else: ?>
                                    <div class="cms-pg-slug">/<?= $h($slug) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Type"><?= $h($typeLabel) ?></td>
                        <td data-label="Status">
                            <span class="cms-badge cms-badge-<?= $isPub ? 'published' : 'draft' ?>" data-status-badge>
                                <?= $isPub ? 'Published' : 'Draft' ?>
                            </span>
                        </td>
                        <td data-label="Updated" class="cms-muted"><?= $h($updatedFmt) ?></td>
                        <td data-label="Actions">
                            <div class="cms-row-actions">
                                <?php if ($canEdit || $canCreate): ?>
                                    <a class="cms-btn cms-btn-sm" href="<?= UIR ?>Cms/edit/<?= $pid ?>"><i class="fas fa-pen"></i> Edit</a>
                                <?php endif; ?>
                                <div class="cms-overflow">
                                    <button type="button" class="cms-overflow-btn" data-overflow-toggle
                                            aria-haspopup="true" aria-expanded="false" data-tip="More actions">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="cms-overflow-menu" role="menu">
                                        <a class="cms-overflow-item" role="menuitem" href="<?= UIR ?>Cms/preview/<?= $pid ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                                        <?php if ($canPublish): ?>
                                            <button type="button" class="cms-overflow-item" role="menuitem"
                                                    data-pubtoggle
                                                    data-page-id="<?= $pid ?>"
                                                    data-status="<?= $isPub ? 'published' : 'draft' ?>">
                                                <?php if ($isPub): ?><i class="fas fa-eye-slash"></i> Unpublish<?php else: ?><i class="fas fa-globe"></i> Publish<?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canDelete && !$isSystem): ?>
                                            <div class="cms-overflow-sep"></div>
                                            <button type="button" class="cms-overflow-item cms-overflow-danger" role="menuitem"
                                                    data-delete
                                                    data-page-id="<?= $pid ?>"
                                                    data-title="<?= $h($title) ?>">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<?php /* ---- New-Page type chooser modal ---- */ ?>
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
                    <a class="cms-typecard" href="<?= UIR ?>Cms/edit/new&type=<?= $h($pt['type']) ?>">
                        <strong><?= $h($pt['label']) ?></strong>
                        <span><?= $h($pt['type']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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

<div class="cms-toast" id="cmsToast" role="status" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
    'use strict';
    var UIR = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';

    /* ---- DataTables: sorting, pagination, search ---- */
    <?php if (!empty($pages)): ?>
    var dt = null;
    if (window.jQuery && jQuery.fn.DataTable) {
        dt = jQuery('#cms-pages-table').DataTable({
            dom: 'lfrtip',
            pageLength: 25,
            order: [[4, 'desc']], // Updated DESC (col 0 is the checkbox)
            columnDefs: [
                { targets: [0], orderable: false, searchable: false }, // Checkbox
                { targets: [4], type: 'date' },
                { targets: [5], orderable: false, searchable: false } // Actions
            ]
        });
        var statusSel = document.getElementById('cmsStatusFilter');
        if (statusSel) {
            statusSel.addEventListener('change', function () {
                // Status column (index 3). "Published"/"Draft" don't overlap, so a
                // plain (non-regex, non-smart) contains match is safe and survives
                // the badge cell's surrounding whitespace.
                dt.column(3).search(statusSel.value, false, false).draw();
            });
        }
        // On page/search/filter redraw, the select-all reflects only the visible page.
        dt.on('draw', function () { syncSelectAll(); refreshBulkBar(); });
    }
    <?php endif; ?>

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

    /* ---- New Page ---- */
    var newModal = document.getElementById('cmsNewModal');
    if (newModal) {
        ['cmsNewPageBtn', 'cmsNewPageEmptyBtn'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) { b.addEventListener('click', function () { openModal(newModal); }); }
        });
    }

    /* ---- POST helper ---- */
    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(AJAX + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ---- Publish / Unpublish ---- */
    document.querySelectorAll('[data-pubtoggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pid = btn.getAttribute('data-page-id');
            var cur = btn.getAttribute('data-status');
            var publishing = (cur !== 'published');
            var endpoint = publishing ? 'publish' : 'unpublish';
            btn.disabled = true;
            post(endpoint, { page_id: pid }).then(function (res) {
                btn.disabled = false;
                if (!res || !res.ok) { toast((res && res.error) || 'Action failed.', 'error'); return; }
                var nowPub = (res.status === 'published');
                btn.setAttribute('data-status', nowPub ? 'published' : 'draft');
                btn.innerHTML = nowPub
                    ? '<i class="fas fa-eye-slash"></i> Unpublish'
                    : '<i class="fas fa-globe"></i> Publish';
                var row = btn.closest('tr');
                var badge = row ? row.querySelector('[data-status-badge]') : null;
                if (badge) {
                    badge.className = 'cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
                    badge.textContent = nowPub ? 'Published' : 'Draft';
                }
                toast(nowPub ? 'Page published.' : 'Page unpublished.', 'ok');
            }).catch(function () { btn.disabled = false; toast('Network error.', 'error'); });
        });
    });

    /* ---- Confirm modal (no native confirm) — callback-based so single + bulk
           delete share one dialog. ---- */
    var confirmModal = document.getElementById('cmsConfirmModal');
    var confirmBody = document.getElementById('cmsConfirmBody');
    var confirmOk = document.getElementById('cmsConfirmOk');
    var confirmAction = null;
    function askConfirm(message, onYes) {
        confirmAction = onYes;
        if (confirmBody) { confirmBody.textContent = message; }
        openModal(confirmModal);
    }
    if (confirmOk) {
        confirmOk.addEventListener('click', function () {
            var fn = confirmAction;
            confirmAction = null;
            if (typeof fn === 'function') { fn(); }
        });
    }

    /* ---- Single delete ---- */
    document.querySelectorAll('[data-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pid = btn.getAttribute('data-page-id');
            var title = btn.getAttribute('data-title') || 'this page';
            askConfirm('Delete "' + title + '"? This removes the page and all of its blocks. This cannot be undone.', function () {
                var okBtn = confirmOk;
                if (okBtn) { okBtn.disabled = true; }
                post('deletepage', { page_id: pid }).then(function (res) {
                    if (okBtn) { okBtn.disabled = false; }
                    closeModal(confirmModal);
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    var row = document.querySelector('tr[data-page-id="' + pid + '"]');
                    if (row && dt) { dt.row(row).remove().draw(false); } else if (row) { row.parentNode.removeChild(row); }
                    toast('Page deleted.', 'ok');
                }).catch(function () { if (okBtn) { okBtn.disabled = false; } toast('Network error.', 'error'); });
            });
        });
    });

    /* ====================================================================
     * Row-action overflow menu (⋯) — lightweight dropdown, keyboard-reachable.
     * ==================================================================== */
    function closeAllOverflow(except) {
        document.querySelectorAll('.cms-overflow.cms-open').forEach(function (o) {
            if (o !== except) {
                o.classList.remove('cms-open');
                var b = o.querySelector('[data-overflow-toggle]');
                if (b) { b.setAttribute('aria-expanded', 'false'); }
            }
        });
    }
    // Menu is position:fixed (escapes the table-wrap overflow:hidden clip); anchor it
    // to the trigger and flip upward when it would run past the viewport bottom.
    function positionOverflowMenu(toggle, menu) {
        var r = toggle.getBoundingClientRect();
        menu.style.visibility = 'hidden';
        menu.style.display = 'block';
        var mh = menu.offsetHeight, mw = menu.offsetWidth;
        var left = r.right - mw;                // right-align to the trigger
        if (left < 6) { left = 6; }
        if (left + mw > window.innerWidth - 6) { left = window.innerWidth - 6 - mw; }
        var top = r.bottom + 4;
        if (top + mh > window.innerHeight - 6 && r.top - 4 - mh > 6) {
            top = r.top - 4 - mh;              // flip upward
        }
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        menu.style.display = '';
        menu.style.visibility = '';
    }
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-overflow-toggle]');
        if (toggle) {
            var wrap = toggle.closest('.cms-overflow');
            var willOpen = !wrap.classList.contains('cms-open');
            closeAllOverflow(wrap);
            wrap.classList.toggle('cms-open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) {
                var menu = wrap.querySelector('.cms-overflow-menu');
                if (menu) { positionOverflowMenu(toggle, menu); }
            }
            return;
        }
        // Clicking a menu item (or anywhere else) closes any open menu.
        if (!e.target.closest('.cms-overflow-menu')) { closeAllOverflow(null); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeAllOverflow(null); }
    });
    // A fixed-positioned menu would strand on scroll/resize — just close it.
    window.addEventListener('scroll', function () { closeAllOverflow(null); }, true);
    window.addEventListener('resize', function () { closeAllOverflow(null); });

    /* ====================================================================
     * Bulk select + bulk actions
     * ==================================================================== */
    var bulkBar = document.getElementById('cmsBulkBar');
    var bulkCount = document.getElementById('cmsBulkCount');
    var checkAll = document.getElementById('cmsCheckAll');

    function visibleRowChecks() {
        // Only checkboxes present in the live DOM tbody (= current DataTables page).
        return Array.prototype.slice.call(
            document.querySelectorAll('#cms-pages-table tbody .cms-row-check')
        );
    }
    function checkedIds() {
        return visibleRowChecks().filter(function (c) { return c.checked; })
            .map(function (c) { return c.value; });
    }
    function refreshBulkBar() {
        if (!bulkBar) { return; }
        var n = checkedIds().length;
        if (bulkCount) { bulkCount.innerHTML = '<i class="fas fa-check-square"></i>' + n + ' selected'; }
        bulkBar.classList.toggle('cms-open', n > 0);
    }
    function syncSelectAll() {
        if (!checkAll) { return; }
        var boxes = visibleRowChecks();
        var checked = boxes.filter(function (c) { return c.checked; }).length;
        checkAll.checked = boxes.length > 0 && checked === boxes.length;
        checkAll.indeterminate = checked > 0 && checked < boxes.length;
    }
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            visibleRowChecks().forEach(function (c) { c.checked = checkAll.checked; });
            refreshBulkBar();
        });
    }
    // Delegate per-row checkbox changes (rows can re-render on DataTables redraw).
    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('cms-row-check')) {
            syncSelectAll();
            refreshBulkBar();
        }
    });

    function setBulkBusy(busy) {
        if (!bulkBar) { return; }
        bulkBar.classList.toggle('cms-busy', busy);
    }
    function runBulk(endpoint, ids, doneMsg, removeRows) {
        setBulkBusy(true);
        var ok = 0, fail = 0;
        var jobs = ids.map(function (id) {
            return post(endpoint, { page_id: id }).then(function (res) {
                if (res && res.ok) {
                    ok++;
                    if (removeRows) {
                        var row = document.querySelector('tr[data-page-id="' + id + '"]');
                        if (row && dt) { dt.row(row).remove(); } else if (row) { row.parentNode.removeChild(row); }
                    } else {
                        // Reflect status on the in-place row badge + toggle.
                        var nowPub = (res.status === 'published');
                        var row2 = document.querySelector('tr[data-page-id="' + id + '"]');
                        if (row2) {
                            var badge = row2.querySelector('[data-status-badge]');
                            if (badge) {
                                badge.className = 'cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
                                badge.textContent = nowPub ? 'Published' : 'Draft';
                            }
                            var tgl = row2.querySelector('[data-pubtoggle]');
                            if (tgl) {
                                tgl.setAttribute('data-status', nowPub ? 'published' : 'draft');
                                tgl.innerHTML = nowPub
                                    ? '<i class="fas fa-eye-slash"></i> Unpublish'
                                    : '<i class="fas fa-globe"></i> Publish';
                            }
                        }
                    }
                } else { fail++; }
            }).catch(function () { fail++; });
        });
        Promise.all(jobs).then(function () {
            if (removeRows && dt) { dt.draw(false); }
            setBulkBusy(false);
            if (checkAll) { checkAll.checked = false; checkAll.indeterminate = false; }
            refreshBulkBar();
            syncSelectAll();
            var msg = doneMsg + ' (' + ok + ' done' + (fail ? ', ' + fail + ' failed' : '') + ').';
            toast(msg, fail ? 'error' : 'ok');
        });
    }

    document.querySelectorAll('[data-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ids = checkedIds();
            if (!ids.length) { return; }
            var act = btn.getAttribute('data-bulk');
            if (act === 'publish') {
                runBulk('publish', ids, 'Published', false);
            } else if (act === 'unpublish') {
                runBulk('unpublish', ids, 'Unpublished', false);
            } else if (act === 'delete') {
                var n = ids.length;
                askConfirm('Delete ' + n + ' page' + (n === 1 ? '' : 's') + '? This cannot be undone.', function () {
                    closeModal(confirmModal);
                    runBulk('deletepage', ids, 'Deleted', true);
                });
            }
        });
    });
})();
</script>
