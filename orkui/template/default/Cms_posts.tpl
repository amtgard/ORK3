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

// E128: per-row lifetime view counts (post_id => int). Defensive — a missing or
// empty map means counts simply don't render.
$postViewCounts = isset($postViewCounts) && is_array($postViewCounts) ? $postViewCounts : array();

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
// Active scope query ('&scope=k:5' or '') threaded onto intra-admin links.
$scopeQ = isset($CmsScopeQuery) ? (string)$CmsScopeQuery : '';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'posts';
$cmsTitle   = 'Posts';
$cmsSub     = 'News and announcements';
$cmsActions = $canCreate
    ? '<button type="button" class="cms-btn cms-btn-primary" id="cmsNewPostBtn"><i class="fas fa-plus"></i> New Post</button>'
    : '';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <?php if ($message !== ''): ?>
        <div class="cms-notice"><?= $h($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($allTags)): ?>
    <div class="cms-filters" style="flex-wrap:wrap;">
        <span class="cms-muted" style="font-size:13px;align-self:center;">Filter by tag:</span>
        <a class="cms-btn cms-btn-sm<?= $tagF === '' ? ' cms-btn-primary' : ' cms-btn-ghost' ?>" href="<?= UIR ?>Cms/posts<?= $scopeQ ?>">All</a>
        <?php foreach ($allTags as $t):
            $tslug = (string)($t['slug'] ?? '');
            $tname = (string)($t['name'] ?? '');
            $tcnt  = (int)($t['post_count'] ?? 0);
            $active = ($tslug !== '' && $tslug === $tagF);
        ?>
            <a class="cms-btn cms-btn-sm<?= $active ? ' cms-btn-primary' : ' cms-btn-ghost' ?>"
               href="<?= UIR ?>Cms/posts&tag=<?= $h($tslug) ?><?= $scopeQ ?>"><?= $h($tname) ?> <span class="cms-muted">(<?= $tcnt ?>)</span></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($posts)): ?>
    <div class="cms-filters">
        <select id="cmsStatusFilter" class="cms-select" aria-label="Filter by status">
            <option value="">All statuses</option>
            <option value="Published">Published</option>
            <option value="Draft">Draft</option>
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
        <table class="cms-table" id="cms-posts-table">
            <thead>
                <tr>
                    <th scope="col" class="cms-check-col"><input type="checkbox" class="cms-check" id="cmsCheckAll" aria-label="Select all posts on this page"></th>
                    <th scope="col">Title</th>
                    <th scope="col">Status</th>
                    <th scope="col">Author</th>
                    <th scope="col">Date</th>
                    <th scope="col">Tags</th>
                    <th scope="col" style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($posts)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="cms-empty">
                                <div class="cms-empty-icon"><i class="fas fa-newspaper"></i></div>
                                <div class="cms-empty-copy">No posts yet.<?= $tagF !== '' ? ' (none with that tag)' : '' ?></div>
                                <?php if ($canCreate): ?>
                                    <button type="button" class="cms-btn cms-btn-primary cms-empty-cta" id="cmsNewPostBtnEmpty">
                                        <i class="fas fa-plus"></i> New Post
                                    </button>
                                <?php endif; ?>
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
                        $dateTs    = $dateSrc !== '' ? (int)strtotime($dateSrc) : 0;
                        // The epoch is what DataTables sorts on (data-order), never
                        // the rendered words; an unparseable date falls back to "—".
                        $dateFmt   = $dateTs > 0 ? date('M j, Y g:i A', $dateTs) : '—';
                    ?>
                    <tr data-post-id="<?= $pid ?>">
                        <td class="cms-check-col" data-label="">
                            <input type="checkbox" class="cms-check cms-row-check" value="<?= $pid ?>" aria-label="Select <?= $h($title) ?>">
                        </td>
                        <td data-label="Title">
                            <div class="cms-pg-title"><?= $h($title) ?></div>
                            <?php if ($slug !== ''): ?><div class="cms-pg-slug">/<?= $h($slug) ?></div><?php endif; ?>
                            <?php // E128: per-row lifetime views — only when the map has this post. ?>
                            <?php if (array_key_exists($pid, $postViewCounts)): ?>
                                <div class="cms-pg-slug cms-muted" data-tip="Lifetime views on the public site">
                                    <i class="fas fa-chart-line"></i> <?= number_format((int)$postViewCounts[$pid]) ?> view<?= (int)$postViewCounts[$pid] === 1 ? '' : 's' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="ork-badge cms-badge cms-badge-<?= $isPub ? 'published' : 'draft' ?>" data-status-badge>
                                <?= $isPub ? 'Published' : 'Draft' ?>
                            </span>
                        </td>
                        <td data-label="Author" class="cms-muted"><?= $author !== '' ? $h($author) : '—' ?></td>
                        <td data-label="Date" class="cms-muted" data-order="<?= $dateTs ?>"><?= $h($dateFmt) ?></td>
                        <td data-label="Tags">
                            <?php if (empty($tags)): ?>
                                <span class="cms-muted">—</span>
                            <?php else: ?>
                                <?php foreach ($tags as $tg): ?>
                                    <span class="ork-badge cms-badge cms-badge-scope"><?= $h((string)($tg['name'] ?? '')) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="cms-row-actions">
                                <?php if ($canEdit || $canCreate): ?>
                                    <a class="cms-btn cms-btn-sm" href="<?= UIR ?>Cms/editpost/<?= $pid ?><?= $scopeQ ?>"><i class="fas fa-pen"></i> Edit</a>
                                <?php endif; ?>
                                <div class="cms-overflow">
                                    <button type="button" class="cms-overflow-btn" data-overflow-toggle
                                            aria-haspopup="true" aria-expanded="false" data-tip="More actions" aria-label="More actions">
                                        <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                                    </button>
                                    <div class="cms-overflow-menu" role="menu">
                                        <a class="cms-overflow-item" role="menuitem" href="<?= UIR ?>Cms/previewpost/<?= $pid ?><?= $scopeQ ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                                        <?php if ($canPublish): ?>
                                            <button type="button" class="cms-overflow-item" role="menuitem"
                                                    data-pubtoggle
                                                    data-post-id="<?= $pid ?>"
                                                    data-status="<?= $isPub ? 'published' : 'draft' ?>">
                                                <?php if ($isPub): ?><i class="fas fa-eye-slash"></i> Unpublish<?php else: ?><i class="fas fa-globe"></i> Publish<?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canDelete): ?>
                                            <div class="cms-overflow-sep"></div>
                                            <button type="button" class="cms-overflow-item cms-overflow-danger" role="menuitem"
                                                    data-delete
                                                    data-post-id="<?= $pid ?>"
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

    <?php if ($canDelete): ?>
    <?php /* ---- Trash: soft-deleted posts, restorable (C2). Lazy-loaded on open
            via CmsAjax/listtrashedposts; each row offers Restore (restorepost). ---- */ ?>
    <div class="cms-trash-section" style="margin-top:22px;">
        <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsTrashToggle" aria-expanded="false" aria-controls="cmsTrashPanel">
            <i class="fas fa-trash-alt"></i> Trash <span class="cms-muted" id="cmsTrashCount"></span>
        </button>
        <div class="cms-trash-panel" id="cmsTrashPanel" hidden style="margin-top:12px;">
            <div class="cms-table-wrap">
                <table class="cms-table" id="cms-trash-table">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Author</th>
                            <th scope="col">Tags</th>
                            <th scope="col" style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="cmsTrashBody">
                        <tr><td colspan="4"><div class="cms-empty"><div class="cms-empty-copy"><span class="cms-spin"></span> Loading…</div></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

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
    var UIR = window.CMS_UIR;
    var AJAX = UIR + 'CmsAjax/';

    /* ---- DataTables: sorting, pagination, search ---- */
    <?php if (!empty($posts)): ?>
    var dt = null;
    if (window.jQuery && jQuery.fn.DataTable) {
        dt = jQuery('#cms-posts-table').DataTable({
            dom: 'lfrtip',
            pageLength: 25,
            order: [[4, 'desc']], // Date DESC (col 0 is the checkbox)
            columnDefs: [
                { targets: [0], orderable: false, searchable: false }, // Checkbox
                // Date: the cells carry data-order="<unix epoch>", which DataTables
                // picks up as this column's sort source, so the formatted wording is
                // display-only. 'num' pins the comparison to the epoch.
                { targets: [4], type: 'num' },
                { targets: [5], orderable: false }, // Tags
                { targets: [6], orderable: false, searchable: false } // Actions
            ]
        });
        var statusSel = document.getElementById('cmsStatusFilter');
        if (statusSel) {
            statusSel.addEventListener('change', function () {
                // Status column (index 2 for posts). "Published"/"Draft" don't overlap,
                // so a plain (non-regex, non-smart) contains match is safe and survives
                // the badge cell's surrounding whitespace.
                dt.column(2).search(statusSel.value, false, false).draw();
            });
        }
        // On page/search/filter redraw, the select-all reflects only the visible page.
        dt.on('draw', function () { syncSelectAll(); refreshBulkBar(); });
    }
    <?php endif; ?>

    /* ---- toast (shared: CmsAdmin.toast) ---- */
    var toast = CmsAdmin.toast;

    /* ---- C2: undoable toast (shared: CmsAdmin.undoableToast) — delete is a
       soft-delete (deleted_at), so the row can be brought back and the toast
       offers an Undo that calls restorepost. ---- */
    var undoableToast = CmsAdmin.undoableToast;

    /* ---- modal helpers (shared: CmsAdmin.modal; backdrop/Esc handled there) ---- */
    var openModal = CmsAdmin.modal.open;
    var closeModal = CmsAdmin.modal.close;

    /* ---- New Post (navigate to the post editor) ---- */
    function goNewPost() { window.location.href = UIR + 'Cms/editpost/new' + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''); }
    var newPostBtn = document.getElementById('cmsNewPostBtn');
    if (newPostBtn) { newPostBtn.addEventListener('click', goNewPost); }
    var newPostBtnEmpty = document.getElementById('cmsNewPostBtnEmpty');
    if (newPostBtnEmpty) { newPostBtnEmpty.addEventListener('click', goNewPost); }

    /* ---- POST helper (shared: CmsAdmin.post — CSRF header + scope) ---- */
    var post = CmsAdmin.post;

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
                    badge.className = 'ork-badge cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
                    badge.textContent = nowPub ? 'Published' : 'Draft';
                }
                toast(nowPub ? 'Post published.' : 'Post unpublished.', 'ok');
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
            var pid = btn.getAttribute('data-post-id');
            var title = btn.getAttribute('data-title') || 'this post';
            askConfirm('Delete "' + title + '"? This moves the post to the Trash, where it can be restored.', function () {
                var okEl = confirmOk;
                if (okEl) { okEl.disabled = true; }
                post('deletepost', { post_id: pid }).then(function (res) {
                    if (okEl) { okEl.disabled = false; }
                    closeModal(confirmModal);
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    var row = document.querySelector('tr[data-post-id="' + pid + '"]');
                    if (row && dt) { dt.row(row).remove().draw(false); } else if (row) { row.parentNode.removeChild(row); }
                    // C2: soft-delete → offer Undo (restorepost). Restoring re-reads
                    // the list so the row (and its DataTables state) comes back clean.
                    undoableToast('Post deleted.', function () {
                        post('restorepost', { post_id: pid }).then(function (r) {
                            if (r && r.ok) { toast('Post restored.', 'ok'); window.location.reload(); }
                            else { toast((r && r.error) || 'Restore failed.', 'error'); }
                        }).catch(function () { toast('Network error.', 'error'); });
                    });
                }).catch(function () { if (okEl) { okEl.disabled = false; } toast('Network error.', 'error'); });
            });
        });
    });

    /* ---- Row-action overflow menu (⋯) — shared: CmsAdmin.installOverflowMenus.
       The menu CLOSES on scroll/resize (it does not re-anchor). ---- */
    CmsAdmin.installOverflowMenus();

    /* ====================================================================
     * Bulk select + bulk actions
     * ==================================================================== */
    var bulkBar = document.getElementById('cmsBulkBar');
    var checkAll = document.getElementById('cmsCheckAll');

    // Shared plumbing (CmsAdmin.bulkSelect) — only the table selector differs
    // between the posts list and the pages list. Kept behind local function
    // declarations so callers earlier in this IIFE keep working unchanged.
    var bulk = CmsAdmin.bulkSelect('#cms-posts-table');
    function visibleRowChecks() { return bulk.visibleRowChecks(); }
    function checkedIds() { return bulk.checkedIds(); }
    function refreshBulkBar() { bulk.refreshBulkBar(); }
    function syncSelectAll() { bulk.syncSelectAll(); }

    function setBulkBusy(busy) {
        if (!bulkBar) { return; }
        bulkBar.classList.toggle('cms-busy', busy);
    }
    function runBulk(endpoint, ids, doneMsg, removeRows) {
        setBulkBusy(true);
        var ok = 0, fail = 0;
        var jobs = ids.map(function (id) {
            return post(endpoint, { post_id: id }).then(function (res) {
                if (res && res.ok) {
                    ok++;
                    if (removeRows) {
                        var row = document.querySelector('tr[data-post-id="' + id + '"]');
                        if (row && dt) { dt.row(row).remove(); } else if (row) { row.parentNode.removeChild(row); }
                    } else {
                        var nowPub = (res.status === 'published');
                        var row2 = document.querySelector('tr[data-post-id="' + id + '"]');
                        if (row2) {
                            var badge = row2.querySelector('[data-status-badge]');
                            if (badge) {
                                badge.className = 'ork-badge cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
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
                runBulk('publishpost', ids, 'Published', false);
            } else if (act === 'unpublish') {
                runBulk('unpublishpost', ids, 'Unpublished', false);
            } else if (act === 'delete') {
                var n = ids.length;
                askConfirm('Delete ' + n + ' post' + (n === 1 ? '' : 's') + '? They move to the Trash, where they can be restored.', function () {
                    closeModal(confirmModal);
                    runBulk('deletepost', ids, 'Deleted', true);
                });
            }
        });
    });

    /* ====================================================================
     * Trash panel — lazy-load soft-deleted posts; Restore brings them back.
     * Reads via CmsAjax/listtrashedposts (GET); Restore via restorepost (POST).
     * ==================================================================== */
    <?php if ($canDelete): ?>
    (function () {
        var toggle  = document.getElementById('cmsTrashToggle');
        var panel   = document.getElementById('cmsTrashPanel');
        var trBody  = document.getElementById('cmsTrashBody');
        var countEl = document.getElementById('cmsTrashCount');
        if (!toggle || !panel || !trBody) { return; }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function renderRows(posts) {
            if (countEl) { countEl.textContent = posts.length ? '(' + posts.length + ')' : ''; }
            if (!posts.length) {
                trBody.innerHTML = '<tr><td colspan="4"><div class="cms-empty"><div class="cms-empty-copy">The Trash is empty.</div></div></td></tr>';
                return;
            }
            trBody.innerHTML = '';
            posts.forEach(function (p) {
                var tr = document.createElement('tr');
                tr.setAttribute('data-trash-post-id', p.post_id);
                var tags = (p.tags || []).map(function (t) {
                    return '<span class="ork-badge cms-badge cms-badge-scope">' + esc(t.name || '') + '</span>';
                }).join(' ') || '<span class="cms-muted">—</span>';
                tr.innerHTML =
                    '<td data-label="Title"><div class="cms-pg-title">' + esc(p.title || '(untitled)') + '</div>' +
                    (p.slug ? '<div class="cms-pg-slug">/' + esc(p.slug) + '</div>' : '') + '</td>' +
                    '<td data-label="Author" class="cms-muted">' + (p.author_name ? esc(p.author_name) : '—') + '</td>' +
                    '<td data-label="Tags">' + tags + '</td>' +
                    '<td data-label="Actions" style="text-align:right;">' +
                        '<button type="button" class="cms-btn cms-btn-sm cms-btn-primary cms-trash-restore" data-post-id="' + esc(p.post_id) + '"><i class="fas fa-trash-restore"></i> Restore</button>' +
                    '</td>';
                trBody.appendChild(tr);
            });
        }

        function load() {
            trBody.innerHTML = '<tr><td colspan="4"><div class="cms-empty"><div class="cms-empty-copy"><span class="cms-spin"></span> Loading…</div></div></td></tr>';
            var url = AJAX + 'listtrashedposts' + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) {
                        trBody.innerHTML = '<tr><td colspan="4"><div class="cms-empty"><div class="cms-empty-copy">' +
                            esc((res && res.error) || 'Could not load the Trash.') + '</div></div></td></tr>';
                        return;
                    }
                    renderRows(res.posts || []);
                })
                .catch(function () {
                    trBody.innerHTML = '<tr><td colspan="4"><div class="cms-empty"><div class="cms-empty-copy">Network error.</div></div></td></tr>';
                });
        }

        toggle.addEventListener('click', function () {
            if (panel.hasAttribute('hidden')) {
                panel.removeAttribute('hidden');
                toggle.setAttribute('aria-expanded', 'true');
                // Always refetch on expand: a post deleted since the last open must
                // show up here, and the payload is small and lazily fetched anyway.
                load();
            } else {
                panel.setAttribute('hidden', '');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        trBody.addEventListener('click', function (e) {
            var btn = e.target.closest('.cms-trash-restore');
            if (!btn) { return; }
            var pid = btn.getAttribute('data-post-id');
            btn.disabled = true;
            post('restorepost', { post_id: pid }).then(function (res) {
                if (!res || !res.ok) { btn.disabled = false; toast((res && res.error) || 'Restore failed.', 'error'); return; }
                toast('Post restored.', 'ok');
                // Reload so the restored row rejoins the main list (and its
                // DataTables state) cleanly — mirrors the Undo path.
                window.location.reload();
            }).catch(function () { btn.disabled = false; toast('Network error.', 'error'); });
        });
    })();
    <?php endif; ?>
})();
</script>
