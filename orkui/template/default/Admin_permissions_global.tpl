<?php
	$adminAuths = is_array($AdminAuths) ? $AdminAuths : [];
	$kingdoms   = is_array($Kingdoms)   ? $Kingdoms   : [];
	$ajaxBase   = UIR . 'AdminAjax/global/';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
.rp-root { --rp-accent-dark: #1a3d2b; --rp-accent: #276749; --rp-accent-mid: #38a169; }

.ap-card { background:#fff; border:1px solid var(--rp-border); border-radius:8px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.ap-card-header { border-radius:8px 8px 0 0; background:var(--rp-bg-light); border-bottom:1px solid var(--rp-border); padding:9px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--rp-text-muted); display:flex; align-items:center; gap:7px; }
.ap-card-body { padding:16px 18px; }
.ap-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.ap-field { display:flex; flex-direction:column; gap:5px; flex:1; min-width:180px; }
.ap-field label { font-size:11px; font-weight:700; color:var(--rp-text-muted); text-transform:uppercase; letter-spacing:.05em; }
.ap-field input { height:34px; border:1px solid #cbd5e0; border-radius:5px; padding:0 10px; font-size:13px; color:var(--rp-text); background:#fff; width:100%; box-sizing:border-box; font-family:inherit; }
.ap-field input:focus { outline:none; border-color:var(--rp-accent-mid); box-shadow:0 0 0 2px rgba(56,161,105,.15); }
.ap-btn { height:34px; padding:0 18px; border-radius:5px; font-size:13px; font-weight:600; cursor:pointer; border:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; background:var(--rp-accent); color:#fff; font-family:inherit; }
.ap-btn:hover { background:var(--rp-accent-dark); }
.ap-btn:disabled { opacity:.5; cursor:not-allowed; }
.ap-feedback { font-size:13px; font-weight:600; margin-top:10px; display:none; }
.ap-feedback.ok  { color:var(--rp-accent); }
.ap-feedback.err { color:#e53e3e; }

.ap-section { font-size:13px; font-weight:700; color:var(--rp-text-body); margin:24px 0 10px; display:flex; align-items:center; gap:7px; padding-bottom:7px; border-bottom:1px solid var(--rp-border); }
.ap-section i { color:var(--rp-text-muted); }

.ap-explainer { background:#f0faf4; border:1px solid #c6e8d4; border-radius:8px; padding:14px 16px; margin-bottom:14px; font-size:13px; color:var(--rp-text-body); }
.ap-explainer-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#276749; margin-bottom:8px; display:flex; align-items:center; gap:6px; }

.ap-table { width:100%; border-collapse:collapse; font-size:13px; }
.ap-table th { background:var(--rp-bg-light); border-bottom:2px solid var(--rp-border); padding:8px 10px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--rp-text-muted); white-space:nowrap; }
.ap-table th:last-child { text-align:right; }
.ap-table td { padding:10px 10px; border-bottom:1px solid #f0f4f8; vertical-align:middle; color:var(--rp-text); }
.ap-table tr:last-child td { border-bottom:none; }
.ap-table tr:hover td { background:#fafbfc; }

.ap-role { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.ap-role-admin { background:#fff5f5; color:#c53030; }

.ap-del { background:none; border:1px solid #fed7d7; color:#e53e3e; border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer; font-family:inherit; }
.ap-del:hover { background:#fff5f5; }
.ap-del.ap-del-confirm { background:#e53e3e; color:#fff; border-color:#e53e3e; }

.ap-empty { padding:28px; text-align:center; color:var(--rp-text-hint); font-size:13px; }
.ap-empty i { font-size:20px; display:block; margin-bottom:8px; opacity:.4; }

#ap-admin-table th.tablesorter-header:not(.sorter-false) { cursor:pointer; user-select:none; }
#ap-admin-table th.tablesorter-header:not(.sorter-false) .tablesorter-header-inner::after { font-family:'Font Awesome 5 Free'; font-weight:900; content:'\f0dc'; margin-left:5px; opacity:.35; font-size:10px; }
#ap-admin-table th.tablesorter-headerAsc .tablesorter-header-inner::after  { content:'\f0de' !important; opacity:1; color:var(--rp-accent); }
#ap-admin-table th.tablesorter-headerDesc .tablesorter-header-inner::after { content:'\f0dd' !important; opacity:1; color:var(--rp-accent); }

.kn-ac-results { position:absolute; top:100%; left:0; right:0; z-index:9999; margin-top:2px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.12); max-height:220px; overflow-y:auto; display:none; }
.kn-ac-results.kn-ac-open { display:block; }
.kn-ac-item { padding:8px 12px; font-size:13px; cursor:pointer; color:#2d3748; border-bottom:1px solid #f7fafc; }
.kn-ac-item:last-child { border-bottom:none; }
.kn-ac-item:hover, .kn-ac-item.kn-ac-focused { background:#ebf4ff; color:#2c7a7b; }

.gp-kd-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:10px; margin-top:4px; }
.gp-kd-tile { background:#fff; border:1px solid var(--rp-border); border-radius:7px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; font-size:13px; text-decoration:none; color:var(--rp-text); transition:box-shadow .12s, border-color .12s; }
.gp-kd-tile:hover { border-color:var(--rp-accent-mid); box-shadow:0 2px 8px rgba(56,161,105,.12); color:var(--rp-accent-dark); }
.gp-kd-tile i { opacity:.4; font-size:12px; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-shield-alt rp-header-icon"></i>
				<h1 class="rp-header-title">Global ORK Permissions</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-globe"></i>
					<span class="rp-scope-chip-label">Scope:</span>
					All of ORK
				</span>
			</div>
		</div>
		<div class="rp-header-actions">
			<a class="rp-btn-ghost" href="<?= UIR ?>Admin">
				<i class="fas fa-arrow-left"></i> Back to Admin
			</a>
		</div>
	</div>

	<!-- Add System Admin -->
	<div class="ap-card" style="margin-top:18px">
		<div class="ap-card-header">
			<i class="fas fa-plus-circle"></i>
			Grant System Administrator
		</div>
		<div class="ap-card-body">
			<div class="ap-explainer">
				<div class="ap-explainer-title"><i class="fas fa-exclamation-triangle"></i> Use sparingly</div>
				System Administrator access satisfies <strong>all</strong> authorization checks across every kingdom, park, and event — regardless of scope. Prefer granting Kingdom-level <em>Create</em> access to local admins instead.
			</div>
			<div class="ap-row">
				<div class="ap-field" style="flex:2">
					<label>Player</label>
					<div style="position:relative">
						<input type="text" id="ap-player-input" placeholder="Search by persona or username&hellip;" autocomplete="off" style="width:100%;box-sizing:border-box">
						<input type="hidden" id="ap-player-id">
						<div class="kn-ac-results" id="ap-player-results"></div>
					</div>
				</div>
				<button class="ap-btn" id="ap-add-btn" disabled>
					<i class="fas fa-plus"></i> Grant Admin
				</button>
			</div>
			<div class="ap-feedback" id="ap-feedback"></div>
		</div>
	</div>

	<!-- System Admins table -->
	<div class="ap-section">
		<i class="fas fa-user-shield"></i>
		System Administrators
		<span style="margin-left:6px;background:#fff5f5;color:#c53030;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700"><?= count($adminAuths) ?></span>
	</div>

	<div class="rp-table-area" style="margin-bottom:0;border-radius:8px">
		<?php if (count($adminAuths) > 0): ?>
		<table class="ap-table" id="ap-admin-table">
			<thead>
				<tr>
					<th>Person</th>
					<th data-sorter="false">Role</th>
					<th>Last Login</th>
					<th>Last Credit</th>
					<th>Last Modified</th>
					<th data-sorter="false"></th>
				</tr>
			</thead>
			<tbody id="ap-admin-tbody">
				<?php foreach ($adminAuths as $a): ?>
				<tr id="ap-row-<?= (int)$a['AuthorizationId'] ?>">
					<td>
						<a href="<?= UIR ?>Player/profile/<?= (int)$a['MundaneId'] ?>" style="font-weight:700;color:inherit;text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
							<?= !empty($a['Persona']) ? htmlspecialchars($a['Persona']) : '<span style="color:var(--rp-text-hint);font-style:italic">(No Persona)</span>' ?>
						</a>
						<?php if (!empty($a['GivenName']) || !empty($a['Surname'])): ?>
							<span style="color:var(--rp-text-muted);font-size:12px"> — <?= htmlspecialchars(trim($a['GivenName'] . ' ' . $a['Surname'])) ?></span>
						<?php endif; ?>
						<?php if (!empty($a['UserName'])): ?>
							<div style="font-size:11px;color:var(--rp-text-hint)"><?= htmlspecialchars($a['UserName']) ?></div>
						<?php endif; ?>
					</td>
					<td><span class="ap-role ap-role-admin">Administrator</span></td>
					<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap" data-sort="<?= !empty($a['LastLogin'])  ? date('Y-m-d', strtotime($a['LastLogin']))  : '0000-00-00' ?>">
						<?= !empty($a['LastLogin']) ? date('M j, Y', strtotime($a['LastLogin'])) : '—' ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap" data-sort="<?= !empty($a['LastCredit']) ? date('Y-m-d', strtotime($a['LastCredit'])) : '0000-00-00' ?>">
						<?= !empty($a['LastCredit']) ? date('M j, Y', strtotime($a['LastCredit'])) : '—' ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap" data-sort="<?= !empty($a['Modified'])   ? date('Y-m-d', strtotime($a['Modified']))   : '0000-00-00' ?>">
						<?= !empty($a['Modified']) ? date('M j, Y', strtotime($a['Modified'])) : '—' ?>
					</td>
					<td style="text-align:right">
						<button class="ap-del" data-id="<?= (int)$a['AuthorizationId'] ?>">Revoke</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else: ?>
		<div class="ap-empty" id="ap-admin-empty">
			<i class="fas fa-user-shield"></i>
			No system administrators found.
		</div>
		<?php endif; ?>
	</div>

	<!-- Kingdom permissions links -->
	<div class="ap-section" style="margin-top:32px">
		<i class="fas fa-chess-rook"></i>
		Kingdom Permissions
	</div>
	<p style="font-size:13px;color:var(--rp-text-muted);margin:0 0 12px">Manage roles &amp; grants for each kingdom and its parks.</p>

	<div class="gp-kd-grid">
		<?php foreach ($kingdoms as $k): ?>
		<a class="gp-kd-tile" href="<?= UIR ?>Admin/permissions/Kingdom/<?= (int)$k['KingdomId'] ?>">
			<?= htmlspecialchars($k['KingdomName']) ?>
			<i class="fas fa-chevron-right"></i>
		</a>
		<?php endforeach; ?>
	</div>

</div><!-- /.rp-root -->

<script>
(function ($) {
	var AJAX_BASE = <?= json_encode($ajaxBase) ?>;

	function apEsc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	// ── Autocomplete ─────────────────────────────────────────────
	var apResults = document.getElementById('ap-player-results');
	var apTimer   = null;

	function apClose() { apResults.classList.remove('kn-ac-open'); apResults.innerHTML = ''; }

	function apOpen(items) {
		if (!items.length) { apClose(); return; }
		apResults.innerHTML = items.map(function (p) {
			return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId
				+ '" data-name="' + encodeURIComponent(p.Persona) + '">'
				+ apEsc(p.Persona)
				+ ' <span style="color:#a0aec0;font-size:11px">(' + apEsc((p.KAbbr||'') + ':' + (p.PAbbr||'')) + ')</span></div>';
		}).join('');
		apResults.classList.add('kn-ac-open');
	}

	document.getElementById('ap-player-input').addEventListener('input', function () {
		var term = this.value.trim();
		document.getElementById('ap-player-id').value = '';
		document.getElementById('ap-add-btn').disabled = true;
		if (term.length < 2) { apClose(); return; }
		clearTimeout(apTimer);
		apTimer = setTimeout(function () {
			$.getJSON(AJAX_BASE + 'playersearch&q=' + encodeURIComponent(term), function (data) {
				apOpen(data || []);
			}).fail(apClose);
		}, 220);
	});

	apResults.addEventListener('click', function (e) {
		var item = e.target.closest('.kn-ac-item[data-id]');
		if (!item) return;
		document.getElementById('ap-player-input').value = decodeURIComponent(item.dataset.name);
		document.getElementById('ap-player-id').value    = item.dataset.id;
		document.getElementById('ap-add-btn').disabled   = false;
		apClose();
	});

	document.addEventListener('click', function (e) {
		if (!e.target.closest('#ap-player-input, #ap-player-results')) apClose();
	});

	// ── Add ──────────────────────────────────────────────────────
	function showFb(msg, ok) {
		$('#ap-feedback').text(msg).removeClass('ok err').addClass(ok ? 'ok' : 'err').show();
	}

	$('#ap-add-btn').on('click', function () {
		var btn = this;
		var mid = $('#ap-player-id').val();
		if (!mid) { showFb('Select a player first.', false); return; }

		$(btn).prop('disabled', true);
		$.post(AJAX_BASE + 'addauth', { MundaneId: mid }, function (r) {
			if (r && r.status === 0) {
				showFb('Administrator access granted.', true);
				$('#ap-player-input').val('');
				$('#ap-player-id').val('');

				var persona    = r.persona ? $('<div>').text(r.persona).html() : '<span style="color:var(--rp-text-hint);font-style:italic">(No Persona)</span>';
				var profileUrl = <?= json_encode(UIR) ?> + 'Player/profile/' + r.mundaneId;
				var today   = new Date().toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
				var row = '<tr id="ap-row-' + r.authId + '">'
					+ '<td><a href="' + profileUrl + '" style="font-weight:700;color:inherit;text-decoration:none" onmouseover="this.style.textDecoration=\'underline\';" onmouseout="this.style.textDecoration=\'none\'">' + persona + '</a></td>'
					+ '<td><span class="ap-role ap-role-admin">Administrator</span></td>'
					+ '<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap">—</td>'
					+ '<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap">—</td>'
					+ '<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap">' + today + '</td>'
					+ '<td style="text-align:right"><button class="ap-del" data-id="' + r.authId + '">Revoke</button></td>'
					+ '</tr>';

				var $empty = $('#ap-admin-empty');
				if ($empty.length) {
					$empty.replaceWith(
						'<table class="ap-table" id="ap-admin-table">'
						+ '<thead><tr><th>Person</th><th>Role</th><th>Last Login</th><th>Last Credit</th><th>Last Modified</th><th></th></tr></thead>'
						+ '<tbody id="ap-admin-tbody"></tbody></table>'
					);
				}
				$('#ap-admin-tbody').append(row);
				bindDelete($('#ap-admin-tbody tr:last-child .ap-del')[0]);
				$(btn).prop('disabled', false);
			} else {
				$(btn).prop('disabled', false);
				showFb((r && r.error) ? r.error : 'Failed to grant access.', false);
			}
		}, 'json').fail(function () {
			$(btn).prop('disabled', false);
			showFb('Request failed. Try again.', false);
		});
	});

	// ── Delete ────────────────────────────────────────────────────
	function bindDelete(btn) {
		$(btn).on('click', function () {
			var $btn = $(this);
			if (!$btn.hasClass('ap-del-confirm')) {
				$btn.addClass('ap-del-confirm').text('Confirm?');
				$btn[0]._t = setTimeout(function () { $btn.removeClass('ap-del-confirm').text('Revoke'); }, 3000);
				return;
			}
			clearTimeout($btn[0]._t);
			$btn.prop('disabled', true).text('…');
			$.post(AJAX_BASE + 'removeauth', { AuthorizationId: $btn.data('id') }, function (r) {
				if (r && r.status === 0) {
					$('#ap-row-' + $btn.data('id')).remove();
				} else {
					$btn.prop('disabled', false).removeClass('ap-del-confirm').text('Revoke');
					alert((r && r.error) ? r.error : 'Failed to revoke access.');
				}
			}, 'json').fail(function () {
				$btn.prop('disabled', false).removeClass('ap-del-confirm').text('Revoke');
				alert('Request failed. Try again.');
			});
		});
	}

	$('.ap-del').each(function () { bindDelete(this); });

	// ── Sortable table ───────────────────────────────────────────
	if ($('#ap-admin-table').length) {
		$('#ap-admin-table').tablesorter({
			headerTemplate: '{content}',
			textExtraction: function (node) {
				return node.getAttribute('data-sort') || node.textContent || node.innerText || '';
			}
		});
	}

}(jQuery));
</script>
