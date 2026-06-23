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
$typeLabels = array();
foreach ($pageTypes as $pt) {
    $typeLabels[$pt['type']] = $pt['label'];
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

<div class="cms-wrap">

    <div class="cms-topbar">
        <div class="cms-masthead">
            <i class="fas fa-feather-alt cms-masthead-mark"></i>
            <div>
                <div class="cms-masthead-word cms-title">The Scriptorium</div>
                <div class="cms-masthead-sub">Site Content · Pages</div>
            </div>
        </div>
        <span class="cms-spacer"></span>
        <?php if ($canCreate): ?>
            <button type="button" class="cms-btn cms-btn-primary" id="cmsNewPageBtn">
                <i class="fas fa-plus"></i> New Page
            </button>
        <?php endif; ?>
    </div>

    <?php /* ---- Pages / Posts tabs ---- */ ?>
    <div class="cms-tabs">
        <a class="cms-tab cms-tab-active" href="<?= UIR ?>Cms/index"><i class="fas fa-file-alt"></i> Pages</a>
        <a class="cms-tab" href="<?= UIR ?>Cms/posts"><i class="fas fa-newspaper"></i> Posts</a>
        <?php if (!empty($caps['nav'])): ?>
            <a class="cms-tab" href="<?= UIR ?>Cms/nav"><i class="fas fa-bars"></i> Navigation</a>
        <?php endif; ?>
    </div>

    <?php if ($message !== ''): ?>
        <div class="cms-notice"><?= $h($message) ?></div>
    <?php endif; ?>

    <form class="cms-filters" method="get" action="<?= UIR ?>Cms/index">
        <input type="hidden" name="Route" value="Cms/index">
        <input type="text" name="q" class="cms-input" placeholder="Search title or slug…" value="<?= $h($search) ?>">
        <select name="status" class="cms-select" onchange="this.form.submit()">
            <option value=""<?= $statusF === '' ? ' selected' : '' ?>>All statuses</option>
            <option value="published"<?= $statusF === 'published' ? ' selected' : '' ?>>Published</option>
            <option value="draft"<?= $statusF === 'draft' ? ' selected' : '' ?>>Draft</option>
        </select>
        <button type="submit" class="cms-btn"><i class="fas fa-search"></i> Filter</button>
        <?php if ($search !== '' || $statusF !== ''): ?>
            <a class="cms-btn cms-btn-ghost" href="<?= UIR ?>Cms/index">Clear</a>
        <?php endif; ?>
    </form>

    <div class="cms-table-wrap">
        <table class="cms-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pages)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="cms-empty">
                                <div class="cms-empty-icon">⚜</div>
                                <div class="cms-empty-copy">No pages yet. Start your first scroll.</div>
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
                        $typeLabel = isset($typeLabels[$type]) ? $typeLabels[$type] : ucfirst($type);
                        $isPub     = ($status === 'published');
                        $updatedFmt = $updated !== '' ? date('M j, Y g:i A', strtotime($updated)) : '—';
                    ?>
                    <tr data-page-id="<?= $pid ?>" data-system="<?= $isSystem ? 1 : 0 ?>">
                        <td data-label="Title">
                            <div class="cms-pg-title"><?= $h($title) ?>
                                <?php if ($isSystem): ?><span class="cms-badge cms-badge-system" style="margin-left:6px;">System</span><?php endif; ?>
                            </div>
                            <?php if ($slug !== ''): ?><div class="cms-pg-slug">/<?= $h($slug) ?></div><?php endif; ?>
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
                                <a class="cms-btn cms-btn-sm cms-btn-ghost" href="<?= UIR ?>Cms/preview/<?= $pid ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                                <?php if ($canPublish): ?>
                                    <button type="button"
                                            class="cms-btn cms-btn-sm cms-btn-ghost"
                                            data-pubtoggle
                                            data-page-id="<?= $pid ?>"
                                            data-status="<?= $isPub ? 'published' : 'draft' ?>">
                                        <?php if ($isPub): ?><i class="fas fa-eye-slash"></i> Unpublish<?php else: ?><i class="fas fa-globe"></i> Publish<?php endif; ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($canDelete && !$isSystem): ?>
                                    <button type="button"
                                            class="cms-btn cms-btn-sm cms-btn-danger"
                                            data-delete
                                            data-page-id="<?= $pid ?>"
                                            data-title="<?= $h($title) ?>">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

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

<div class="cms-toast" id="cmsToast"></div>

<script>
(function () {
    'use strict';
    var UIR = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';

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
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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

    /* ---- Delete (confirm modal, no native confirm) ---- */
    var confirmModal = document.getElementById('cmsConfirmModal');
    var confirmBody = document.getElementById('cmsConfirmBody');
    var confirmOk = document.getElementById('cmsConfirmOk');
    var pendingDeleteId = null;
    document.querySelectorAll('[data-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pendingDeleteId = btn.getAttribute('data-page-id');
            var title = btn.getAttribute('data-title') || 'this page';
            if (confirmBody) {
                confirmBody.textContent = 'Delete "' + title + '"? This removes the page and all of its blocks. This cannot be undone.';
            }
            openModal(confirmModal);
        });
    });
    if (confirmOk) {
        confirmOk.addEventListener('click', function () {
            if (!pendingDeleteId) { return; }
            confirmOk.disabled = true;
            post('deletepage', { page_id: pendingDeleteId }).then(function (res) {
                confirmOk.disabled = false;
                closeModal(confirmModal);
                if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                var row = document.querySelector('tr[data-page-id="' + pendingDeleteId + '"]');
                if (row) { row.parentNode.removeChild(row); }
                pendingDeleteId = null;
                toast('Page deleted.', 'ok');
            }).catch(function () { confirmOk.disabled = false; toast('Network error.', 'error'); });
        });
    }
})();
</script>
