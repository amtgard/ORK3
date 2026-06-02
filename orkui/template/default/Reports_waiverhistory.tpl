<?php
/* ── Reports_waiverhistory.tpl ─────────────────────────────────
 * Waiver Change History report.
 * PLAIN PHP rendered via extract()+include — use <?php ?>/<?= ?> only.
 * Data provided by controller.Reports::waiverhistory.
 * ─────────────────────────────────────────────────────────────── */

$versions   = is_array($_wv_versions ?? null) ? $_wv_versions : array();
$Scope      = isset($Scope) ? $Scope : 'kingdom';
$Type       = isset($Type) ? $Type : 'Kingdom';
$KingdomId  = (int)($KingdomId ?? 0);
$EntityId   = (int)($EntityId ?? 0);
$KingdomName= (string)($KingdomName ?? '');
$ShowScopeToggle = !empty($ShowScopeToggle);
$page_title = isset($page_title) ? $page_title : 'Waiver Change History';

$chain_label = ($Scope === 'park') ? 'Park Waiver' : 'Kingdom Waiver';

/* Toggle links (server round-trip, plain links) */
$toggle_kingdom_url = UIR . 'Reports/waiverhistory/Kingdom&id=' . $KingdomId . '&scope=kingdom';
$toggle_park_url    = UIR . 'Reports/waiverhistory/Kingdom&id=' . $KingdomId . '&scope=park';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">

<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<style>
/* Heading resets (orkui.css gives all h1-h6 a gray pill) */
.wvh-root h1, .wvh-root h2, .wvh-root h3, .wvh-root h4, .wvh-root h5, .wvh-root h6,
.wvh-modal h1, .wvh-modal h2, .wvh-modal h3, .wvh-modal h4, .wvh-modal h5, .wvh-modal h6 {
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}

/* ── Segmented scope toggle ──────────────────────────────── */
.wvh-toggle {
	display: inline-flex; border: 1px solid #d1d5db; border-radius: 8px;
	overflow: hidden; background: #f3f4f6; margin: 0 0 18px;
}
.wvh-toggle-opt {
	padding: 7px 18px; font-size: 0.85rem; font-weight: 600; text-decoration: none;
	color: #4b5563; background: transparent; border: none; line-height: 1.4;
	transition: background .12s, color .12s;
}
.wvh-toggle-opt + .wvh-toggle-opt { border-left: 1px solid #d1d5db; }
.wvh-toggle-opt:hover { background: #e5e7eb; color: #1f2937; }
.wvh-toggle-opt.wvh-active { background: #4338ca; color: #fff; }
.wvh-toggle-opt.wvh-active:hover { background: #3730a3; color: #fff; }

/* ── Status badges ───────────────────────────────────────── */
.wvh-badge {
	display: inline-block; padding: 2px 9px; border-radius: 999px;
	font-size: 0.74rem; font-weight: 700; line-height: 1.5; white-space: nowrap;
	letter-spacing: .03em; margin-right: 4px;
}
.wvh-badge-active  { background:#d1fae5; color:#065f46; }
.wvh-badge-enabled { background:#dbeafe; color:#1e40af; }
.wvh-badge-archived { color:#9ca3af; font-size:0.8rem; font-style:italic; }

.wvh-vnum {
	display:inline-block; padding:1px 8px; border-radius:4px;
	background:#eef2ff; color:#3730a3; font-size:0.8rem; font-weight:700;
}

.wvh-muted { color:#9ca3af; }

/* ── View button + tooltip ([data-tip], no native title=) ── */
.wvh-view-btn {
	position: relative;
	display: inline-flex; align-items: center; gap: 5px;
	padding: 4px 12px; border: 1px solid #c7d2fe; border-radius: 6px;
	background: #eef2ff; color: #3730a3; font-size: 0.8rem; font-weight: 600;
	cursor: pointer; line-height: 1.4;
}
.wvh-view-btn:hover { background: #e0e7ff; }
.wvh-view-btn[data-tip]:hover::after {
	content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%;
	transform: translateX(-50%); background:#2d3748; color:#fff; padding:4px 8px;
	border-radius:4px; font-size:11px; font-weight:600; white-space:nowrap;
	pointer-events:none; z-index:10; box-shadow:0 2px 6px rgba(0,0,0,0.2);
}
.wvh-view-btn[data-tip]:hover::before {
	content:''; position:absolute; bottom:100%; left:50%; transform:translateX(-50%);
	border:4px solid transparent; border-top-color:#2d3748; pointer-events:none; z-index:10;
}

/* ── Empty state ─────────────────────────────────────────── */
.wvh-empty {
	text-align:center; padding:48px 20px; color:#6b7280;
	border:1px dashed #d1d5db; border-radius:10px; background:#fafafa;
}
.wvh-empty i { font-size:2rem; display:block; margin-bottom:12px; color:#9ca3af; }

/* ── Read-only view modal ────────────────────────────────── */
.wvh-modal-overlay {
	display:none; position:fixed; inset:0; z-index:9999;
	background:rgba(15,23,42,0.55); align-items:flex-start; justify-content:center;
	overflow-y:auto; padding:40px 16px;
}
.wvh-modal-overlay.wvh-open { display:flex; }
.wvh-modal {
	background:#fff; border-radius:12px; width:100%; max-width:760px;
	box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;
	display:flex; flex-direction:column; max-height:calc(100vh - 80px);
}
.wvh-modal-header {
	display:flex; align-items:flex-start; justify-content:space-between;
	gap:12px; padding:18px 22px; border-bottom:1px solid #e5e7eb;
}
.wvh-modal-title { font-size:1.15rem; font-weight:700; color:#111827; margin:0; }
.wvh-modal-meta { font-size:0.8rem; color:#6b7280; margin-top:4px; }
.wvh-modal-close {
	background:none; border:none; font-size:1.6rem; line-height:1; cursor:pointer;
	color:#9ca3af; padding:0 4px;
}
.wvh-modal-close:hover { color:#111827; }
.wvh-modal-body { padding:18px 22px; overflow-y:auto; }
.wvh-ro-notice {
	display:flex; align-items:center; gap:8px;
	background:#fffbeb; border:1px solid #fcd34d; color:#92400e;
	padding:8px 12px; border-radius:6px; font-size:0.82rem; font-weight:600;
	margin-bottom:16px;
}
.wvh-ro-content {
	border:1px solid #e5e7eb; border-radius:8px; padding:18px; background:#fcfcfd;
}
.wvh-ro-block + .wvh-ro-block { margin-top:14px; padding-top:14px; border-top:1px dashed #e5e7eb; }
.wvh-ro-content img { max-width:100%; height:auto; }
.wvh-modal-error {
	background:#fee2e2; border:1px solid #fca5a5; color:#991b1b;
	padding:12px 14px; border-radius:6px; font-size:0.85rem; font-weight:600;
}
.wvh-modal-loading { text-align:center; padding:30px; color:#6b7280; font-size:0.9rem; }

/* ── Dark-mode overrides ─────────────────────────────────── */
html[data-theme="dark"] .wvh-toggle { background:#1f2530; border-color:#3a4150; }
html[data-theme="dark"] .wvh-toggle-opt { color:#9ca3af; }
html[data-theme="dark"] .wvh-toggle-opt + .wvh-toggle-opt { border-left-color:#3a4150; }
html[data-theme="dark"] .wvh-toggle-opt:hover { background:#2a313d; color:#e5e7eb; }
html[data-theme="dark"] .wvh-toggle-opt.wvh-active { background:#4338ca; color:#fff; }
html[data-theme="dark"] .wvh-toggle-opt.wvh-active:hover { background:#4f46e5; }

html[data-theme="dark"] .wvh-badge-active  { background:#0b3a2c; color:#6ee7b7; }
html[data-theme="dark"] .wvh-badge-enabled { background:#172554; color:#93c5fd; }
html[data-theme="dark"] .wvh-badge-archived { color:#6b7280; }
html[data-theme="dark"] .wvh-vnum { background:#1e1b4b; color:#c7d2fe; }
html[data-theme="dark"] .wvh-muted { color:#6b7280; }

html[data-theme="dark"] .wvh-view-btn { background:#1e1b4b; color:#c7d2fe; border-color:#312e81; }
html[data-theme="dark"] .wvh-view-btn:hover { background:#312e81; }

html[data-theme="dark"] .wvh-empty { background:#1a1f29; border-color:#3a4150; color:#9ca3af; }
html[data-theme="dark"] .wvh-empty i { color:#6b7280; }

html[data-theme="dark"] .wvh-modal { background:#1a1f29; }
html[data-theme="dark"] .wvh-modal-header { border-bottom-color:#3a4150; }
html[data-theme="dark"] .wvh-modal-title { color:#f3f4f6; }
html[data-theme="dark"] .wvh-modal-meta { color:#9ca3af; }
html[data-theme="dark"] .wvh-modal-close { color:#6b7280; }
html[data-theme="dark"] .wvh-modal-close:hover { color:#f3f4f6; }
html[data-theme="dark"] .wvh-ro-notice { background:#3a3209; border-color:#854d0e; color:#fde68a; }
html[data-theme="dark"] .wvh-ro-content { background:#161b23; border-color:#3a4150; color:#e5e7eb; }
html[data-theme="dark"] .wvh-ro-block + .wvh-ro-block { border-top-color:#3a4150; }
html[data-theme="dark"] .wvh-modal-error { background:#3f1414; border-color:#7f1d1d; color:#fca5a5; }
html[data-theme="dark"] .wvh-modal-loading { color:#9ca3af; }
</style>

<div class="rp-root wvh-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-clock-rotate-left rp-header-icon"></i>
				<h1 class="rp-header-title">Waiver Change History</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-chess-rook"></i>
					<?=htmlspecialchars($KingdomName !== '' ? $KingdomName : 'Kingdom')?>
					<span class="wvh-muted">&middot;</span>
					<?=htmlspecialchars($chain_label)?>
				</span>
			</div>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>A version-by-version history of the <strong><?=htmlspecialchars($chain_label)?></strong> for <?=htmlspecialchars($KingdomName !== '' ? $KingdomName : 'this kingdom')?> &mdash; every saved revision, who saved it, and why. Newest first. Click <em>View</em> to read any archived version (read-only).</span>
	</div>

<?php if ($ShowScopeToggle) : ?>
	<!-- ── Scope toggle ───────────────────────────────────── -->
	<div class="wvh-toggle" role="group" aria-label="Waiver chain">
		<a class="wvh-toggle-opt <?=($Scope !== 'park') ? 'wvh-active' : ''?>" href="<?=$toggle_kingdom_url?>">Kingdom Waiver</a>
		<a class="wvh-toggle-opt <?=($Scope === 'park') ? 'wvh-active' : ''?>" href="<?=$toggle_park_url?>">Park Waiver</a>
	</div>
<?php endif; ?>

<?php if (empty($versions)) : ?>
	<!-- ── Empty state ────────────────────────────────────── -->
	<div class="wvh-empty">
		<i class="fas fa-clock-rotate-left"></i>
		No saved versions yet.
	</div>
<?php else : ?>
	<!-- ── Versions table ─────────────────────────────────── -->
	<div class="rp-table-area">
		<table id="wvh-table" class="display" style="width:100%">
			<thead>
				<tr>
					<th>Version</th>
					<th>Name</th>
					<th>Saved</th>
					<th>Saved By</th>
					<th>Status</th>
					<th>Change Reason</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($versions as $v) :
	$tid       = (int)($v['TemplateId'] ?? 0);
	$vnum      = (int)($v['Version'] ?? 0);
	$vname     = (string)($v['VersionName'] ?? '');
	$reason    = trim((string)($v['ChangeReason'] ?? ''));
	$isActive  = !empty($v['IsActive']);
	$isEnabled = !empty($v['IsEnabled']);
	$createdBy = (string)($v['CreatedByName'] ?? '');
	$createdRaw= (string)($v['CreatedAt'] ?? '');
	$createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
	$createdDisp = $createdTs ? date('F j, Y g:i A', $createdTs) : '';
	$createdSort = $createdTs ? date('Y-m-d H:i:s', $createdTs) : '';
?>
				<tr>
					<td data-order="<?=$vnum?>"><span class="wvh-vnum">#<?=$vnum?></span></td>
					<td><?=$vname !== '' ? htmlspecialchars($vname) : '<span class="wvh-muted">&mdash;</span>'?></td>
					<td data-order="<?=htmlspecialchars($createdSort)?>"><?=$createdDisp !== '' ? htmlspecialchars($createdDisp) : '<span class="wvh-muted">&mdash;</span>'?></td>
					<td><?=$createdBy !== '' ? htmlspecialchars($createdBy) : '<span class="wvh-muted">&mdash;</span>'?></td>
					<td>
<?php if ($isActive) : ?><span class="wvh-badge wvh-badge-active">ACTIVE</span><?php endif; ?>
<?php if ($isEnabled) : ?><span class="wvh-badge wvh-badge-enabled">ENABLED</span><?php endif; ?>
<?php if (!$isActive && !$isEnabled) : ?><span class="wvh-badge-archived">archived</span><?php endif; ?>
					</td>
					<td><?=$reason !== '' ? htmlspecialchars($reason) : '<span class="wvh-muted">&mdash;</span>'?></td>
					<td>
						<button type="button" class="wvh-view-btn" data-tip="View this version" data-tid="<?=$tid?>">
							<i class="fas fa-eye"></i> View
						</button>
					</td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

</div><!-- /wvh-root -->

<!-- ── Read-only view modal ─────────────────────────────────── -->
<div class="wvh-modal-overlay" id="wvh-modal-overlay">
	<div class="wvh-modal" role="dialog" aria-modal="true" aria-labelledby="wvh-modal-title">
		<div class="wvh-modal-header">
			<div>
				<h2 class="wvh-modal-title" id="wvh-modal-title">Waiver Version</h2>
				<div class="wvh-modal-meta" id="wvh-modal-meta"></div>
			</div>
			<button type="button" class="wvh-modal-close" id="wvh-modal-close" aria-label="Close">&times;</button>
		</div>
		<div class="wvh-modal-body" id="wvh-modal-body">
			<div class="wvh-modal-loading">Loading&hellip;</div>
		</div>
	</div>
</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>

<script>
$(function () {
	var UIR      = <?=json_encode(UIR)?>;
	var WVH_KID  = <?=$KingdomId?>;
	var WVH_TYPE = '<?=addslashes($Type)?>';
	var WVH_EID  = <?=$EntityId?>;

	if (document.getElementById('wvh-table')) {
		$('#wvh-table').DataTable({
			pageLength: 25,
			order: [[2, 'desc']],   /* Saved date desc (newest first) */
			fixedHeader: { headerOffset: document.getElementById('newmenu') ? document.getElementById('newmenu').offsetHeight : 0 },
			columnDefs: [{ orderable: false, targets: 6 }]
		});
	}

	var $overlay = $('#wvh-modal-overlay');
	var $body    = $('#wvh-modal-body');
	var $title   = $('#wvh-modal-title');
	var $meta    = $('#wvh-modal-meta');

	function wvhEscape(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	function wvhFmtDate(raw) {
		if (!raw) return '';
		var t = raw.replace(' ', 'T');
		var d = new Date(t);
		if (isNaN(d.getTime())) return raw;
		var opts = { year:'numeric', month:'long', day:'numeric', hour:'numeric', minute:'2-digit' };
		return d.toLocaleString(undefined, opts);
	}

	function wvhCloseModal() {
		$overlay.removeClass('wvh-open');
	}

	function wvhOpenModal() {
		$title.text('Waiver Version');
		$meta.text('');
		$body.html('<div class="wvh-modal-loading">Loading&hellip;</div>');
		$overlay.addClass('wvh-open');
	}

	function wvhShowError(msg) {
		$body.html('<div class="wvh-modal-error">' + wvhEscape(msg) + '</div>');
	}

	$(document).on('click', '.wvh-view-btn', function () {
		var tid = $(this).data('tid');
		wvhOpenModal();

		fetch(UIR + 'WaiverAjax/versionContent?TemplateId=' + encodeURIComponent(tid)
			+ '&KingdomId=' + WVH_KID
			+ '&Type=' + encodeURIComponent(WVH_TYPE)
			+ '&EntityId=' + WVH_EID, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				var status = (data && typeof data.status !== 'undefined') ? data.status : 1;
				if (status !== 0) {
					wvhShowError((data && data.error) ? data.error : 'This version could not be loaded.');
					return;
				}

				$title.text(data.VersionName || 'Waiver Version');

				var metaBits = [];
				if (data.Version != null && data.Version !== '') metaBits.push('Version #' + wvhEscape(data.Version));
				if (data.CreatedAt) metaBits.push(wvhEscape(wvhFmtDate(data.CreatedAt)));
				if (data.ChangeReason) metaBits.push('Reason: ' + wvhEscape(data.ChangeReason));
				$meta.html(metaBits.join(' &nbsp;&middot;&nbsp; '));

				/* Stored HTML is already server-sanitized — inject via innerHTML. */
				var html = '<div class="wvh-ro-notice">'
					+ '<i class="fas fa-lock"></i> Read-only &mdash; this is an archived version.'
					+ '</div>'
					+ '<div class="wvh-ro-content">';

				var blocks = [data.HeaderHtml, data.BodyHtml, data.FooterHtml, data.MinorHtml];
				var any = false;
				blocks.forEach(function (b) {
					if (b != null && String(b).trim() !== '') {
						any = true;
						html += '<div class="wvh-ro-block">' + b + '</div>';
					}
				});
				if (!any) {
					html += '<div class="wvh-muted">This version has no content.</div>';
				}
				html += '</div>';

				$body.html(html);
			})
			.catch(function () {
				wvhShowError('A network error occurred while loading this version.');
			});
	});

	$('#wvh-modal-close').on('click', wvhCloseModal);
	$overlay.on('click', function (e) {
		if (e.target === this) wvhCloseModal();   /* click-outside-to-close */
	});
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $overlay.hasClass('wvh-open')) wvhCloseModal();
	});
});
</script>
