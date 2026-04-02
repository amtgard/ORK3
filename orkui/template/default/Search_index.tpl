<style>
/* ── Player Search ── all classes prefixed sr- ─────────────────────────── */

.sr-page { display:flex; flex-direction:column; gap:14px; }

/* ── Header ── */
.sr-header {
	display:flex; align-items:center; gap:12px;
	padding:14px 0 6px;
}
.sr-header-icon {
	width:42px; height:42px; border-radius:50%;
	background:#2b6cb0; color:#fff;
	display:flex; align-items:center; justify-content:center;
	font-size:18px; flex-shrink:0;
}
.sr-header-title {
	font-size:22px; font-weight:700; color:#2d3748; margin:0;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
.sr-header-sub { font-size:13px; color:#718096; margin-top:1px; }

/* ── Search bar ── */
.sr-search-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	padding:16px 20px; display:flex; align-items:center; gap:14px;
}
.sr-search-label {
	font-size:13px; font-weight:600; color:#4a5568; white-space:nowrap;
	display:flex; align-items:center; gap:6px;
}
.sr-search-label i { color:#3182ce; }
.sr-search-input-wrap {
	flex:1; position:relative;
}
.sr-search-input {
	width:100%; padding:8px 12px 8px 36px;
	border:1px solid #cbd5e0; border-radius:6px;
	font-size:14px; color:#2d3748;
	outline:none; box-sizing:border-box;
	transition:border-color .15s, box-shadow .15s;
}
.sr-search-input:focus {
	border-color:#3182ce;
	box-shadow:0 0 0 3px rgba(49,130,206,.15);
}
.sr-search-icon {
	position:absolute; left:10px; top:50%; transform:translateY(-50%);
	color:#a0aec0; font-size:13px; pointer-events:none;
}
.sr-search-hint {
	font-size:12px; color:#a0aec0; white-space:nowrap;
}

/* ── Results card ── */
.sr-results-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	overflow:hidden;
}
.sr-results-header {
	display:flex; align-items:center; justify-content:space-between;
	padding:10px 16px; border-bottom:1px solid #e2e8f0;
	background:#f7fafc;
}
.sr-results-title {
	font-size:13px; font-weight:700; color:#2d3748;
	display:flex; align-items:center; gap:6px;
}
.sr-results-title i { color:#3182ce; }
.sr-results-count {
	font-size:12px; color:#718096; background:#edf2f7;
	padding:2px 8px; border-radius:10px;
}
.sr-table {
	width:100%; border-collapse:collapse;
}
.sr-table thead tr {
	background:#f7fafc;
}
.sr-table th {
	font-size:11px; font-weight:700; text-transform:uppercase;
	letter-spacing:.06em; color:#718096;
	padding:9px 14px; text-align:left;
	border-bottom:1px solid #e2e8f0;
}
.sr-table td {
	padding:10px 14px; font-size:13px; color:#4a5568;
	border-bottom:1px solid #f0f4f8;
	vertical-align:middle;
}
.sr-table tbody tr:last-child td { border-bottom:none; }
.sr-table tbody tr {
	cursor:pointer; transition:background .12s;
}
.sr-table tbody tr:hover { background:#ebf4ff; }
.sr-table tbody tr:hover td { color:#2d3748; }

/* Active/inactive states */
.sr-row-inactive td { color:#a0aec0; font-style:italic; }
.sr-row-inactive:hover td { color:#718096; }
.sr-badge-inactive {
	display:inline-block; font-size:10px; font-weight:600;
	background:#f0f0f0; color:#a0aec0; border-radius:4px;
	padding:1px 5px; margin-left:5px; vertical-align:middle;
	font-style:normal;
}

/* Banned state */
.sr-row-banned td { background:#fff5f5; }
.sr-row-banned:hover td { background:#fed7d7; }
.sr-badge-banned {
	display:inline-block; font-size:10px; font-weight:600;
	background:#fc8181; color:#fff; border-radius:4px;
	padding:1px 5px; margin-left:5px; vertical-align:middle;
	font-style:normal;
}

/* Player name column */
.sr-player-name { font-weight:600; color:#2d3748; }
.sr-row-inactive .sr-player-name { font-weight:400; color:#a0aec0; }

/* Empty / loading states */
.sr-empty {
	text-align:center; padding:40px 16px;
	color:#a0aec0; font-size:13px; font-style:italic;
}
.sr-empty i { display:block; font-size:24px; margin-bottom:8px; color:#cbd5e0; }

@media (max-width:768px) {
	.sr-search-card { flex-direction:column; align-items:stretch; gap:10px; }
	.sr-search-hint { display:none; }
	.sr-table th:first-child,
	.sr-table td:first-child { display:none; } /* hide Kingdom on mobile */
}
</style>

<div class="sr-page">

	<!-- Header -->
	<div class="sr-header">
		<div class="sr-header-icon"><i class="fas fa-search"></i></div>
		<div>
			<h1 class="sr-header-title">Player Search</h1>
			<div class="sr-header-sub">
				<?php if (!empty($KingdomId) || !empty($ParkId)): ?>
					Searching within <?= !empty($ParkId) ? 'this park' : 'this kingdom' ?> &mdash; includes inactive players
				<?php else: ?>
					Search across all of Amtgard &mdash; includes inactive players
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Search input -->
	<div class="sr-search-card">
		<div class="sr-search-label"><i class="fas fa-user"></i> Player</div>
		<div class="sr-search-input-wrap">
			<i class="fas fa-search sr-search-icon"></i>
			<input type="text" id="sr-player-input" class="sr-search-input"
				placeholder="Type a player or persona name…"
				autocomplete="off" />
		</div>
		<div class="sr-search-hint">Results update as you type</div>
		<input type="hidden" id="sr-kingdom-id" value="<?= (int)($KingdomId ?? 0) ?>" />
		<input type="hidden" id="sr-park-id"    value="<?= (int)($ParkId ?? 0) ?>" />
	</div>

	<!-- Results -->
	<div class="sr-results-card">
		<div class="sr-results-header">
			<div class="sr-results-title"><i class="fas fa-users"></i> Players</div>
			<div class="sr-results-count" id="sr-count" style="display:none"></div>
		</div>
		<table class="sr-table" id="sr-table">
			<thead>
				<tr>
					<th>Kingdom</th>
					<th>Park</th>
					<th>Player</th>
				</tr>
			</thead>
			<tbody id="sr-tbody">
				<tr><td colspan="3" class="sr-empty"><i class="fas fa-search"></i>Enter a name above to search for players.</td></tr>
			</tbody>
		</table>
	</div>

</div><!-- /.sr-page -->

<script>
(function() {
	var _timer   = null;
	var _current = '';
	var _kid     = parseInt(document.getElementById('sr-kingdom-id').value) || 0;
	var _pid     = parseInt(document.getElementById('sr-park-id').value)    || 0;

	function renderResults(data) {
		var tbody  = document.getElementById('sr-tbody');
		var countEl = document.getElementById('sr-count');

		if (!data || data.length === 0) {
			tbody.innerHTML = '<tr><td colspan="3" class="sr-empty">'
				+ '<i class="fas fa-user-slash"></i>No players found.</td></tr>';
			countEl.style.display = 'none';
			return;
		}

		// Sort: active first, then inactive, banned last
		data.sort(function(a, b) {
			var aBanned = a.Suspended == 1 ? 1 : 0;
			var bBanned = b.Suspended == 1 ? 1 : 0;
			if (aBanned !== bBanned) return aBanned - bBanned;
			if (b.Active !== a.Active) return b.Active - a.Active;
			var pa = (a.Persona || '').toLowerCase();
			var pb = (b.Persona || '').toLowerCase();
			return pa < pb ? -1 : pa > pb ? 1 : 0;
		});

		var html = '';
		for (var i = 0; i < data.length; i++) {
			var v       = data[i];
			var active  = v.Active !== 0;
			var banned  = v.Suspended == 1;
			var rowCls  = banned ? ' sr-row-banned' : (active ? '' : ' sr-row-inactive');
			var kingdom = v.KingdomName || '';
			var park    = v.ParkName    || '';
			var persona = v.Persona     || v.UserName || '';
			var badge   = banned ? '<span class="sr-badge-banned">Banned</span>'
			            : (active ? '' : '<span class="sr-badge-inactive">Inactive</span>');
			var url     = '<?= UIR ?>Player/profile/' + v.MundaneId;

			html += '<tr class="sr-row' + rowCls + '" onclick="window.location.href=\'' + url + '\'">'
				+ '<td>' + kingdom + '</td>'
				+ '<td>' + park + '</td>'
				+ '<td><span class="sr-player-name">' + persona + '</span>' + badge + '</td>'
				+ '</tr>';
		}

		if (data.length >= 100) {
			html += '<tr><td colspan="3" class="sr-empty" style="padding:10px 16px;font-style:normal;color:#c05621;background:#fffaf0">'
				+ '<i class="fas fa-exclamation-triangle" style="color:#dd6b20"></i>'
				+ '&ensp;Showing the first 100 results &mdash; please refine your search if you don\'t see who you\'re looking for.'
				+ '</td></tr>';
		}
		tbody.innerHTML = html;
		countEl.textContent = data.length >= 100 ? '100+ results' : data.length + ' result' + (data.length === 1 ? '' : 's');
		countEl.style.display = '';
	}

	function doSearch(term) {
		var params = {
			Action:  'Search/Player',
			search:  term.trim(),
			type:    'all',
			limit:   100
		};
		if (_kid > 0) params.kingdom_id = _kid;
		if (_pid > 0) params.park_id    = _pid;

		$.getJSON('<?= HTTP_SERVICE ?>Search/SearchService.php', params, function(data) {
			if (term === _current) renderResults(data);
		});
	}

	document.getElementById('sr-player-input').addEventListener('input', function() {
		var term = this.value;
		_current = term;
		clearTimeout(_timer);
		if (term.length < 2) {
			document.getElementById('sr-tbody').innerHTML =
				'<tr><td colspan="3" class="sr-empty"><i class="fas fa-search"></i>Enter a name above to search for players.</td></tr>';
			document.getElementById('sr-count').style.display = 'none';
			return;
		}
		_timer = setTimeout(function() { doSearch(term); }, 300);
	});
})();
</script>
