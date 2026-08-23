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
    array('type' => 'composed',   'label' => 'Landing page'),
    array('type' => 'article',    'label' => 'Article'),
    array('type' => 'media',      'label' => 'Photo gallery'),
    array('type' => 'resource',   'label' => 'Documents & downloads'),
    array('type' => 'blog_index', 'label' => 'News index'),
    array('type' => 'dynamic',    'label' => 'Live ORK data'),
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

// E128: per-row lifetime view counts (page_id => int). Defensive — a missing or
// empty map means counts simply don't render.
$pageViewCounts = isset($pageViewCounts) && is_array($pageViewCounts) ? $pageViewCounts : array();

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// L2: "Jul 8, 2026 5:04 PM" on all 21 rows told an author nothing — every page
// was touched in the same import, so the column read as one repeated string.
// What an author actually wants from it is HOW LONG AGO. The exact timestamp is
// still one hover away (data-tip on the cell) and the real epoch still drives
// the sort (data-order), so nothing here sorts on the rendered words.
$relTime = function ($ts) {
    $diff = time() - (int)$ts;
    if ($diff < 0) {
        $diff = 0; // clock skew / a future updated_at reads as "just now"
    }
    if ($diff < 60) {
        return 'just now';
    }
    $steps = array(
        array(3600,     60,       'minute'),
        array(86400,    3600,     'hour'),
        array(604800,   86400,    'day'),
        array(2592000,  604800,   'week'),
        array(31536000, 2592000,  'month'),
        array(PHP_INT_MAX, 31536000, 'year'),
    );
    foreach ($steps as $s) {
        if ($diff < $s[0]) {
            $n = (int)floor($diff / $s[1]);
            if ($n < 1) {
                $n = 1;
            }
            return $n . ' ' . $s[2] . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'a long time ago';
};
// Active scope query ('&scope=k:5' or '') threaded onto every intra-admin link
// so navigating into an editor/preview stays in the current org scope.
$scopeQ = isset($CmsScopeQuery) ? (string)$CmsScopeQuery : '';
?>
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

    <?php /* ---- Bulk-action bar (revealed when ≥1 row checked) ---- */ ?>
    <?php if (!empty($pages)): ?>
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
        <?php /* L2: ONE filter strip. The status <select> used to float above the
                card while DataTables' raw "Show 25 entries" / "Search:" chrome sat
                unstyled inside it — two filter controls in two places, one of them
                undesigned. The DataTables widgets are relocated into this strip on
                init (see cmsListbarDt below) so all three read as one product. */ ?>
        <?php if (!empty($pages)): ?>
        <div class="cms-listbar" id="cmsListbar">
            <div class="cms-listbar-search" id="cmsListbarSearch"></div>
            <select id="cmsStatusFilter" class="cms-select cms-listbar-select" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="Published"<?= $statusF === 'published' ? ' selected' : '' ?>>Published</option>
                <option value="Draft"<?= $statusF === 'draft' ? ' selected' : '' ?>>Draft</option>
            </select>
            <div class="cms-listbar-len" id="cmsListbarLen"></div>
        </div>
        <?php endif; ?>
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
                        $updatedTs = $updated !== '' ? (int)strtotime($updated) : 0;
                        // Human-readable absolute stays reachable on hover; the epoch
                        // is what DataTables sorts on (data-order), never the words.
                        $updatedAbs = $updatedTs > 0 ? date('F j, Y g:i A', $updatedTs) : '';
                        $updatedRel = $updatedTs > 0 ? $relTime($updatedTs) : '';
                        $statusWord = $isPub ? 'Published' : 'Draft';
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
                                <?php $liveUrl = $isPub ? (string)($p['live_href'] ?? '') : ''; ?>
                                <?php if ($liveUrl !== ''): ?>
                                    <a class="cms-pg-slug cms-pg-slug-link" href="<?= $h($liveUrl) ?>" target="_blank" rel="noopener noreferrer">/<?= $h($slug) ?> <i class="fas fa-external-link-alt"></i></a>
                                <?php else: ?>
                                    <div class="cms-pg-slug">/<?= $h($slug) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php // E128: per-row lifetime views — only when the map has this page. ?>
                            <?php if (array_key_exists($pid, $pageViewCounts)): ?>
                                <div class="cms-pg-slug cms-muted" data-tip="Lifetime views on the public site">
                                    <i class="fas fa-chart-line"></i> <?= number_format((int)$pageViewCounts[$pid]) ?> view<?= (int)$pageViewCounts[$pid] === 1 ? '' : 's' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Type"><?= $h($typeLabel) ?></td>
                        <?php /* L2: Published is the norm on this list — a chip on every
                                row is 21 repetitions of "nothing to see". Only the
                                exceptions get a chip. data-search/data-order keep the
                                real word as the filter + sort value (DataTables reads
                                those HTML5 attributes), and .cms-sr-only keeps it in
                                the accessibility tree for a screen reader. */ ?>
                        <td data-label="Status" data-status-cell
                            data-search="<?= $statusWord ?>" data-order="<?= $statusWord ?>">
                            <?php if ($isPub): ?>
                                <span class="cms-sr-only">Published</span>
                            <?php else: ?>
                                <span class="cms-badge cms-badge-draft" data-status-badge>Draft</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Updated" class="cms-muted" data-order="<?= $updatedTs ?>"
                            <?= $updatedAbs !== '' ? 'data-tip="' . $h($updatedAbs) . '"' : '' ?>><?= $updatedRel !== '' ? $h($updatedRel) : '—' ?></td>
                        <td data-label="Actions">
                            <div class="cms-row-actions">
                                <?php if ($canEdit || $canCreate): ?>
                                    <a class="cms-btn cms-btn-sm" href="<?= UIR ?>Cms/edit/<?= $pid ?><?= $scopeQ ?>"><i class="fas fa-pen"></i> Edit</a>
                                <?php endif; ?>
                                <div class="cms-overflow">
                                    <button type="button" class="cms-overflow-btn" data-overflow-toggle
                                            aria-haspopup="true" aria-expanded="false" data-tip="More actions" aria-label="More actions">
                                        <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                                    </button>
                                    <div class="cms-overflow-menu" role="menu">
                                        <a class="cms-overflow-item" role="menuitem" href="<?= UIR ?>Cms/preview/<?= $pid ?><?= $scopeQ ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a>
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
            language: {
                // Plain product wording instead of the library's stock phrasing.
                lengthMenu: 'Show _MENU_ pages',
                search: '',
                searchPlaceholder: 'Search pages…',
                info: '_START_–_END_ of _TOTAL_ pages',
                infoEmpty: 'No pages',
                infoFiltered: '(of _MAX_)',
                zeroRecords: 'No pages match that search.'
            },
            columnDefs: [
                { targets: [0], orderable: false, searchable: false }, // Checkbox
                // Updated: the cells carry data-order="<unix epoch>", which
                // DataTables picks up as this column's sort/type source, so the
                // relative wording ("3 weeks ago") is display-only and can never
                // sort alphabetically. 'num' pins the comparison to the epoch.
                { targets: [4], type: 'num' },
                { targets: [5], orderable: false, searchable: false } // Actions
            ]
        });
        // L2: fold DataTables' own controls into the designed filter strip. Its
        // markup is generated on init, so the move happens here rather than in
        // the template. Guarded — a missing node just leaves the widget where
        // DataTables put it.
        var lbSearch = document.getElementById('cmsListbarSearch');
        var lbLen = document.getElementById('cmsListbarLen');
        var dtFilter = document.getElementById('cms-pages-table_filter');
        var dtLength = document.getElementById('cms-pages-table_length');
        if (lbSearch && dtFilter) {
            lbSearch.appendChild(dtFilter);
            // language.search:'' drops the visible "Search:" label, so the input
            // needs its name back for assistive tech — a placeholder is not one.
            var dtSearchInput = dtFilter.querySelector('input');
            if (dtSearchInput) { dtSearchInput.setAttribute('aria-label', 'Search pages'); }
        }
        if (lbLen && dtLength) { lbLen.appendChild(dtLength); }

        var statusSel = document.getElementById('cmsStatusFilter');
        if (statusSel) {
            statusSel.addEventListener('change', function () {
                // Status column (index 3). Each cell carries data-search with the
                // bare word, so this matches the VALUE — not, as it used to, any
                // substring of the badge markup that happened to contain it.
                dt.column(3).search(statusSel.value, false, false).draw();
            });
        }
        // On page/search/filter redraw, the select-all reflects only the visible page.
        dt.on('draw', function () { syncSelectAll(); refreshBulkBar(); });
    }
    <?php endif; ?>

    /* ---- Status cell: a chip only when the status is NOT the common case.
       Published is the norm, so a published row shows nothing visible and keeps
       the word in .cms-sr-only for assistive tech; the filter/sort value lives on
       the cell's data-search/data-order, which is why the cell is repainted (and
       the DataTables cache invalidated) rather than just the badge's text. ---- */
    function paintStatusCell(row, nowPub) {
        if (!row) { return; }
        var cell = row.querySelector('[data-status-cell]');
        if (!cell) { return; }
        var word = nowPub ? 'Published' : 'Draft';
        cell.setAttribute('data-search', word);
        cell.setAttribute('data-order', word);
        cell.innerHTML = nowPub
            ? '<span class="cms-sr-only">Published</span>'
            : '<span class="cms-badge cms-badge-draft" data-status-badge>Draft</span>';
        if (dt) { dt.cell(cell).invalidate(); }
    }

    /* ---- toast (shared: CmsAdmin.toast) ---- */
    var toast = CmsAdmin.toast;

    /* ---- C2: undoable toast (shared: CmsAdmin.undoableToast) — delete is a
       soft-delete (deleted_at), so the row can be brought back and the toast
       offers an Undo that calls the restore endpoint. ---- */
    var undoableToast = CmsAdmin.undoableToast;

    /* ---- modal helpers (shared: CmsAdmin.modal; backdrop/Esc handled there) ---- */
    var openModal = CmsAdmin.modal.open;
    var closeModal = CmsAdmin.modal.close;

    /* ---- New Page ---- */
    var newModal = document.getElementById('cmsNewModal');
    if (newModal) {
        ['cmsNewPageBtn', 'cmsNewPageEmptyBtn'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) { b.addEventListener('click', function () { openModal(newModal); }); }
        });
    }

    /* ---- POST helper (shared: CmsAdmin.post — CSRF header + scope) ---- */
    var post = CmsAdmin.post;

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
                paintStatusCell(btn.closest('tr'), nowPub);
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
            askConfirm('Delete "' + title + '"? This removes the page and all of its blocks. You can undo this right afterward from the toast that appears.', function () {
                var okBtn = confirmOk;
                if (okBtn) { okBtn.disabled = true; }
                post('deletepage', { page_id: pid }).then(function (res) {
                    if (okBtn) { okBtn.disabled = false; }
                    closeModal(confirmModal);
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    var row = document.querySelector('tr[data-page-id="' + pid + '"]');
                    if (row && dt) { dt.row(row).remove().draw(false); } else if (row) { row.parentNode.removeChild(row); }
                    // C2: soft-delete → offer Undo (restorepage). Restoring re-reads
                    // the list so the row (and its DataTables state) comes back clean.
                    undoableToast('Page deleted.', function () {
                        post('restorepage', { page_id: pid }).then(function (r) {
                            if (r && r.ok) { toast('Page restored.', 'ok'); window.location.reload(); }
                            else { toast((r && r.error) || 'Restore failed.', 'error'); }
                        }).catch(function () { toast('Network error.', 'error'); });
                    });
                }).catch(function () { if (okBtn) { okBtn.disabled = false; } toast('Network error.', 'error'); });
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
    // between the pages list and the posts list. Kept behind local function
    // declarations so callers earlier in this IIFE keep working unchanged.
    var bulk = CmsAdmin.bulkSelect('#cms-pages-table');
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
                            paintStatusCell(row2, nowPub);
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
