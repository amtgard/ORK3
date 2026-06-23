<?php
/**
 * Cms_posts.tpl — CMS blog-post list. PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Receives (from Controller_Cms::posts):
 *   $Posts      list of post rows (post_id, slug, title, excerpt, status,
 *               published_at, updated_at, author_name, tags=>[['name','slug'],...])
 *   $TagFilter  current tag-slug filter ('' = none)
 *   $AllTags    list of ['tag_id','name','slug','post_count']
 *   $Caps       ['create','edit','publish','delete','media','nav','roles' => bool]
 *   $Message    (optional) flash/notice string
 *   UIR, HTTP_TEMPLATE (constants)
 */

$posts   = isset($Posts) && is_array($Posts) ? $Posts : array();
$caps    = isset($Caps) && is_array($Caps) ? $Caps : array();
$tagF    = isset($TagFilter) ? (string)$TagFilter : '';
$allTags = isset($AllTags) && is_array($AllTags) ? $AllTags : array();
$message = isset($Message) ? (string)$Message : '';

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
        <h1 class="cms-title">Content Management</h1>
        <span class="cms-spacer"></span>
        <?php if ($canCreate): ?>
            <a class="cms-btn cms-btn-primary" href="<?= UIR ?>Cms/editpost/new">
                <i class="fas fa-plus"></i> New Post
            </a>
        <?php endif; ?>
    </div>

    <?php /* ---- Pages / Posts tabs ---- */ ?>
    <div class="cms-tabs">
        <a class="cms-tab" href="<?= UIR ?>Cms/index"><i class="fas fa-file-alt"></i> Pages</a>
        <a class="cms-tab cms-tab-active" href="<?= UIR ?>Cms/posts"><i class="fas fa-newspaper"></i> Posts</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="cms-notice"><?= $h($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($allTags)): ?>
    <div class="cms-filters" style="flex-wrap:wrap;">
        <span class="cms-muted" style="font-size:13px;align-self:center;">Filter by tag:</span>
        <a class="cms-btn cms-btn-sm<?= $tagF === '' ? ' cms-btn-primary' : ' cms-btn-ghost' ?>" href="<?= UIR ?>Cms/posts">All</a>
        <?php foreach ($allTags as $t):
            $tslug = (string)($t['slug'] ?? '');
            $tname = (string)($t['name'] ?? '');
            $tcnt  = (int)($t['post_count'] ?? 0);
            $active = ($tslug !== '' && $tslug === $tagF);
        ?>
            <a class="cms-btn cms-btn-sm<?= $active ? ' cms-btn-primary' : ' cms-btn-ghost' ?>"
               href="<?= UIR ?>Cms/posts&tag=<?= $h($tslug) ?>"><?= $h($tname) ?> <span class="cms-muted">(<?= $tcnt ?>)</span></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="cms-table-wrap">
        <table class="cms-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Author</th>
                    <th>Date</th>
                    <th>Tags</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($posts)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="cms-empty">
                                No posts<?= $tagF !== '' ? ' with that tag' : '' ?> yet.<?php if ($canCreate && $tagF === ''): ?> Use <strong>New Post</strong> to write one.<?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($posts as $p):
                        $pid       = (int)($p['post_id'] ?? 0);
                        $title     = (string)($p['title'] ?? '(untitled)');
                        $slug      = (string)($p['slug'] ?? '');
                        $status    = (string)($p['status'] ?? 'draft');
                        $author    = trim((string)($p['author_name'] ?? ''));
                        $pubAt     = (string)($p['published_at'] ?? '');
                        $updated   = (string)($p['updated_at'] ?? '');
                        $tags      = (isset($p['tags']) && is_array($p['tags'])) ? $p['tags'] : array();
                        $isPub     = ($status === 'published');
                        $dateSrc   = $isPub && $pubAt !== '' ? $pubAt : $updated;
                        $dateFmt   = $dateSrc !== '' ? date('M j, Y g:i A', strtotime($dateSrc)) : '—';
                    ?>
                    <tr data-post-id="<?= $pid ?>">
                        <td data-label="Title">
                            <div class="cms-pg-title"><?= $h($title) ?></div>
                            <?php if ($slug !== ''): ?><div class="cms-pg-slug">/<?= $h($slug) ?></div><?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="cms-badge cms-badge-<?= $isPub ? 'published' : 'draft' ?>" data-status-badge>
                                <?= $isPub ? 'Published' : 'Draft' ?>
                            </span>
                        </td>
                        <td data-label="Author" class="cms-muted"><?= $author !== '' ? $h($author) : '—' ?></td>
                        <td data-label="Date" class="cms-muted"><?= $h($dateFmt) ?></td>
                        <td data-label="Tags">
                            <?php if (empty($tags)): ?>
                                <span class="cms-muted">—</span>
                            <?php else: ?>
                                <?php foreach ($tags as $tg): ?>
                                    <span class="cms-badge cms-badge-scope"><?= $h((string)($tg['name'] ?? '')) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="cms-row-actions">
                                <?php if ($canEdit || $canCreate): ?>
                                    <a class="cms-btn cms-btn-sm" href="<?= UIR ?>Cms/editpost/<?= $pid ?>"><i class="fas fa-pen"></i> Edit</a>
                                <?php endif; ?>
                                <?php if ($slug !== ''): ?>
                                    <a class="cms-btn cms-btn-sm cms-btn-ghost" href="<?= UIR ?>Blog/post/<?= $h($slug) ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                                <?php endif; ?>
                                <?php if ($canPublish): ?>
                                    <button type="button"
                                            class="cms-btn cms-btn-sm cms-btn-ghost"
                                            data-pubtoggle
                                            data-post-id="<?= $pid ?>"
                                            data-status="<?= $isPub ? 'published' : 'draft' ?>">
                                        <?php if ($isPub): ?><i class="fas fa-eye-slash"></i> Unpublish<?php else: ?><i class="fas fa-globe"></i> Publish<?php endif; ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <button type="button"
                                            class="cms-btn cms-btn-sm cms-btn-danger"
                                            data-delete
                                            data-post-id="<?= $pid ?>"
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
            var pid = btn.getAttribute('data-post-id');
            var cur = btn.getAttribute('data-status');
            var publishing = (cur !== 'published');
            var endpoint = publishing ? 'publishpost' : 'unpublishpost';
            btn.disabled = true;
            post(endpoint, { post_id: pid }).then(function (res) {
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
                toast(nowPub ? 'Post published.' : 'Post unpublished.', 'ok');
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
            pendingDeleteId = btn.getAttribute('data-post-id');
            var title = btn.getAttribute('data-title') || 'this post';
            if (confirmBody) {
                confirmBody.textContent = 'Delete "' + title + '"? This removes the post and all of its content blocks. This cannot be undone.';
            }
            openModal(confirmModal);
        });
    });
    if (confirmOk) {
        confirmOk.addEventListener('click', function () {
            if (!pendingDeleteId) { return; }
            confirmOk.disabled = true;
            post('deletepost', { post_id: pendingDeleteId }).then(function (res) {
                confirmOk.disabled = false;
                closeModal(confirmModal);
                if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                var row = document.querySelector('tr[data-post-id="' + pendingDeleteId + '"]');
                if (row) { row.parentNode.removeChild(row); }
                pendingDeleteId = null;
                toast('Post deleted.', 'ok');
            }).catch(function () { confirmOk.disabled = false; toast('Network error.', 'error'); });
        });
    }
})();
</script>
