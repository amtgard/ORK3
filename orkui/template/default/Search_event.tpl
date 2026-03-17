<style>
/* ── Event Search ── all classes prefixed se- ───────────────────────────── */

.se-page { display:flex; flex-direction:column; gap:14px; }

/* ── Header ── */
.se-header {
	display:flex; align-items:center; gap:12px;
	padding:14px 0 6px;
}
.se-header-icon {
	width:42px; height:42px; border-radius:50%;
	background:#2b6cb0; color:#fff;
	display:flex; align-items:center; justify-content:center;
	font-size:18px; flex-shrink:0;
}
.se-header-title {
	font-size:22px; font-weight:700; color:#2d3748; margin:0;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
.se-header-sub { font-size:13px; color:#718096; margin-top:1px; }

/* ── Search bar ── */
.se-search-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	padding:16px 20px; display:flex; align-items:center; gap:14px;
}
.se-search-label {
	font-size:13px; font-weight:600; color:#4a5568; white-space:nowrap;
	display:flex; align-items:center; gap:6px;
}
.se-search-label i { color:#3182ce; }
.se-search-input-wrap { flex:1; position:relative; }
.se-search-input {
	width:100%; padding:8px 12px 8px 36px;
	border:1px solid #cbd5e0; border-radius:6px;
	font-size:14px; color:#2d3748;
	outline:none; box-sizing:border-box;
	transition:border-color .15s, box-shadow .15s;
}
.se-search-input:focus {
	border-color:#3182ce;
	box-shadow:0 0 0 3px rgba(49,130,206,.15);
}
.se-search-icon {
	position:absolute; left:10px; top:50%; transform:translateY(-50%);
	color:#a0aec0; font-size:13px; pointer-events:none;
}
.se-search-hint { font-size:12px; color:#a0aec0; white-space:nowrap; }

/* ── Results cards ── */
.se-results-card {
	background:#fff; border:1px solid #e2e8f0; border-radius:8px;
	overflow:hidden;
}
.se-results-header {
	display:flex; align-items:center; justify-content:space-between;
	padding:10px 16px; border-bottom:1px solid #e2e8f0;
	background:#f7fafc;
}
.se-results-title {
	font-size:13px; font-weight:700; color:#2d3748;
	display:flex; align-items:center; gap:6px;
}
.se-results-title i { color:#3182ce; }
.se-results-title.past i { color:#718096; }
.se-results-count {
	font-size:12px; color:#718096; background:#edf2f7;
	padding:2px 8px; border-radius:10px;
}
.se-table {
	width:100%; border-collapse:collapse;
}
.se-table th {
	font-size:11px; font-weight:700; text-transform:uppercase;
	letter-spacing:.06em; color:#718096;
	padding:9px 14px; text-align:left;
	border-bottom:1px solid #e2e8f0; background:#f7fafc;
}
.se-table td {
	padding:10px 14px; font-size:13px; color:#4a5568;
	border-bottom:1px solid #f0f4f8; vertical-align:middle;
}
.se-table tbody tr:last-child td { border-bottom:none; }
.se-table tbody tr { cursor:pointer; transition:background .12s; }
.se-table tbody tr:hover { background:#ebf4ff; }
.se-table tbody tr:hover td { color:#2d3748; }
.se-event-name { font-weight:600; color:#2d3748; }
.se-date-badge {
	display:inline-flex; align-items:center; gap:4px;
	background:#ebf4ff; color:#2b6cb0;
	font-size:11px; font-weight:600;
	padding:2px 7px; border-radius:4px; white-space:nowrap;
}
.se-date-badge i { font-size:10px; }
.se-date-past {
	display:inline-flex; align-items:center; gap:4px;
	background:#f0f0f0; color:#718096;
	font-size:11px; font-weight:600;
	padding:2px 7px; border-radius:4px; white-space:nowrap;
}
.se-date-past i { font-size:10px; }
.se-no-date { font-size:12px; color:#a0aec0; font-style:italic; }

/* ── Empty / loading ── */
.se-empty {
	text-align:center; padding:32px 16px;
	color:#a0aec0; font-size:13px; font-style:italic;
}
.se-empty i { display:block; font-size:22px; margin-bottom:8px; color:#cbd5e0; }
.se-hidden { display:none; }

@media (max-width:768px) {
	.se-search-card { flex-direction:column; align-items:stretch; gap:10px; }
	.se-search-hint { display:none; }
}
</style>

<div class="se-page">

	<!-- Header -->
	<div class="se-header">
		<div class="se-header-icon"><i class="fas fa-calendar-alt"></i></div>
		<div>
			<h1 class="se-header-title">Event Search</h1>
			<div class="se-header-sub">
				<?php if (!empty($KingdomId) || !empty($ParkId)): ?>
					Events within <?= !empty($ParkId) ? 'this park' : 'this kingdom' ?>
				<?php else: ?>
					Event occurrences across Amtgard
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Search input -->
	<div class="se-search-card">
		<div class="se-search-label"><i class="fas fa-calendar-day"></i> Event</div>
		<div class="se-search-input-wrap">
			<i class="fas fa-search se-search-icon"></i>
			<input type="text" id="se-event-input" class="se-search-input"
				placeholder="Search events by name…"
				autocomplete="off" />
		</div>
		<div class="se-search-hint">Results update as you type</div>
		<input type="hidden" id="se-kingdom-id" value="<?= (int)($KingdomId ?? 0) ?>" />
		<input type="hidden" id="se-park-id"    value="<?= (int)($ParkId ?? 0) ?>" />
		<input type="hidden" id="se-unit-id"    value="<?= (int)($UnitId ?? 0) ?>" />
	</div>

	<!-- Upcoming Events -->
	<div class="se-results-card" id="se-upcoming-card">
		<div class="se-results-header">
			<div class="se-results-title"><i class="fas fa-calendar-check"></i> Upcoming Events</div>
			<div class="se-results-count" id="se-upcoming-count" style="display:none"></div>
		</div>
		<table class="se-table">
			<thead>
				<tr><th>Event</th><th>Date</th><th>Kingdom</th><th>Park</th></tr>
			</thead>
			<tbody id="se-upcoming-tbody">
				<tr><td colspan="4" class="se-empty"><i class="fas fa-calendar-alt"></i>Enter a name above to search for events.</td></tr>
			</tbody>
		</table>
	</div>

	<!-- Past Events -->
	<div class="se-results-card se-hidden" id="se-past-card">
		<div class="se-results-header">
			<div class="se-results-title past"><i class="fas fa-history"></i> Past Events</div>
			<div class="se-results-count" id="se-past-count" style="display:none"></div>
		</div>
		<table class="se-table">
			<thead>
				<tr><th>Event</th><th>Date</th><th>Kingdom</th><th>Park</th></tr>
			</thead>
			<tbody id="se-past-tbody">
			</tbody>
		</table>
	</div>

</div><!-- /.se-page -->

<script>
(function() {
	var _timer   = null;
	var _current = '';
	var _kid     = parseInt(document.getElementById('se-kingdom-id').value) || 0;
	var _pid     = parseInt(document.getElementById('se-park-id').value)    || 0;
	var _uid_val = parseInt(document.getElementById('se-unit-id').value)    || 0;

	function formatDate(dateStr) {
		if (!dateStr) return null;
		var d = new Date(dateStr + 'T00:00:00');
		if (isNaN(d.getTime())) return dateStr;
		return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function navLink(href, label) {
		return '<a href="' + href + '" onclick="event.stopPropagation()" style="color:inherit;text-decoration:none">'
			+ label + '</a>';
	}

	function buildRow(v, isPast) {
		var name    = v.Name        || '';
		var url     = v.NextDetailId
			? '<?= UIR ?>Event/detail/' + v.EventId + '/' + v.NextDetailId
			: '<?= UIR ?>Event/index/' + v.EventId;
		var dateFmt = v.NextDate ? formatDate(v.NextDate) : null;
		var dateCel;
		if (dateFmt) {
			var cls = isPast ? 'se-date-past' : 'se-date-badge';
			dateCel = '<span class="' + cls + '"><i class="fas fa-calendar"></i>' + dateFmt + '</span>';
		} else {
			dateCel = '<span class="se-no-date">—</span>';
		}
		var kingdomCel = v.KingdomName && v.KingdomId
			? navLink('<?= UIR ?>Kingdom/profile/' + v.KingdomId, v.KingdomName)
			: (v.KingdomName || '');
		var parkCel = v.ParkName && v.ParkId
			? navLink('<?= UIR ?>Park/profile/' + v.ParkId, v.ParkName)
			: (v.ParkName || '');
		return '<tr onclick="window.location.href=\'' + url + '\'">'
			+ '<td><span class="se-event-name">' + name + '</span></td>'
			+ '<td>' + dateCel + '</td>'
			+ '<td>' + kingdomCel + '</td>'
			+ '<td>' + parkCel + '</td>'
			+ '</tr>';
	}

	function renderResults(upcoming, past) {
		var uTbody  = document.getElementById('se-upcoming-tbody');
		var pTbody  = document.getElementById('se-past-tbody');
		var uCount  = document.getElementById('se-upcoming-count');
		var pCount  = document.getElementById('se-past-count');
		var pCard   = document.getElementById('se-past-card');

		// Upcoming table
		if (upcoming.length === 0) {
			uTbody.innerHTML = '<tr><td colspan="4" class="se-empty">'
				+ '<i class="fas fa-calendar-check"></i>No upcoming events found.</td></tr>';
			uCount.style.display = 'none';
		} else {
			uTbody.innerHTML = upcoming.map(function(v) { return buildRow(v, false); }).join('');
			uCount.textContent = upcoming.length + ' result' + (upcoming.length === 1 ? '' : 's');
			uCount.style.display = '';
		}

		// Past table — only show the card if there are results
		if (past.length === 0) {
			pCard.classList.add('se-hidden');
		} else {
			pCard.classList.remove('se-hidden');
			pTbody.innerHTML = past.map(function(v) { return buildRow(v, true); }).join('');
			pCount.textContent = past.length + ' result' + (past.length === 1 ? '' : 's');
			pCount.style.display = '';
		}
	}

	function doSearch(term) {
		var base = {
			Action: 'Search/Event',
			name:   term.trim(),
			limit:  50
		};
		if (_kid > 0)     base.kingdom_id = _kid;
		if (_pid > 0)     base.park_id    = _pid;
		if (_uid_val > 0) base.unit_id    = _uid_val;

		var upcomingData = null;
		var allData      = null;

		function tryRender() {
			if (upcomingData === null || allData === null) return;
			if (term !== _current) return;

			// Only show events that resolve to a real kingdom or park name
			// (filters orphans whose kingdom_id points to a deleted kingdom)
			function hasLocation(v) {
				return (v.KingdomName && v.KingdomName.trim().length > 0) ||
				       (v.ParkName    && v.ParkName.trim().length    > 0);
			}

			// Server already separates upcoming from past — just filter by location
			var upcoming = upcomingData.filter(hasLocation);
			var past     = allData.filter(hasLocation);

			renderResults(upcoming, past);
		}

		// Request 1: events with a scheduled upcoming date (sorted by date asc)
		$.getJSON('<?= HTTP_SERVICE ?>Search/SearchService.php',
			$.extend({}, base, { date_order: 1 }),
			function(data) { upcomingData = data || []; tryRender(); }
		);

		// Request 2: past occurrences (current=0 picks most recent past calendardetail per event)
		$.getJSON('<?= HTTP_SERVICE ?>Search/SearchService.php',
			$.extend({}, base, { current: 0 }),
			function(data) { allData = data || []; tryRender(); }
		);
	}

	function resetTables() {
		var msg = '<tr><td colspan="4" class="se-empty"><i class="fas fa-calendar-alt"></i>Enter a name above to search for events.</td></tr>';
		document.getElementById('se-upcoming-tbody').innerHTML = msg;
		document.getElementById('se-upcoming-count').style.display = 'none';
		document.getElementById('se-past-card').classList.add('se-hidden');
		document.getElementById('se-past-count').style.display = 'none';
	}

	document.getElementById('se-event-input').addEventListener('input', function() {
		var term = this.value;
		_current = term;
		clearTimeout(_timer);
		if (term.length < 2) { resetTables(); return; }
		_timer = setTimeout(function() { doSearch(term); }, 300);
	});
})();
</script>
