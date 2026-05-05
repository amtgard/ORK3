<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
.sh-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
@media (max-width: 900px) { .sh-grid { grid-template-columns: 1fr; } }

.sh-panel {
	background: var(--rp-card-bg, #fff);
	border: 1px solid var(--rp-border, #e5e7eb);
	border-radius: 10px;
	overflow: hidden;
}
.sh-panel-hdr {
	background: var(--rp-bg-secondary, #f9fafb);
	border-bottom: 1px solid var(--rp-border, #e5e7eb);
	padding: 10px 14px;
	font-size: 0.8rem;
	font-weight: 700;
	color: var(--rp-text-secondary, #374151);
	display: flex;
	align-items: center;
	gap: 7px;
}
.sh-panel-body { padding: 14px; }

/* Worker dots */
.sh-workers { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 12px; }
.sh-worker {
	width: 22px; height: 22px;
	border-radius: 4px;
	background: #e5e7eb;
	display: flex; align-items: center; justify-content: center;
	font-size: 0.6rem; font-weight: 700; color: #fff;
	transition: background 0.3s;
}
.sh-worker.active  { background: #4f46e5; }
.sh-worker.idle    { background: #16a34a; }

/* Metric rows */
.sh-metric { display: flex; justify-content: space-between; align-items: baseline; padding: 4px 0; border-bottom: 1px solid var(--rp-border, #f3f4f6); font-size: 0.82rem; }
.sh-metric:last-child { border-bottom: none; }
.sh-metric-label { color: var(--rp-text-muted, #6b7280); }
.sh-metric-val { font-weight: 700; font-variant-numeric: tabular-nums; color: var(--rp-text, #111827); }
.sh-metric-val.warn  { color: #d97706; }
.sh-metric-val.alert { color: #dc2626; }

/* Process table */
.sh-proc-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
.sh-proc-table th { text-align: left; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--rp-text-muted, #9ca3af); padding: 0 6px 6px; border-bottom: 2px solid var(--rp-border, #e5e7eb); }
.sh-proc-table td { padding: 4px 6px; border-bottom: 1px solid var(--rp-border, #f3f4f6); vertical-align: top; }
.sh-proc-table tr:last-child td { border-bottom: none; }
.sh-proc-table td.sh-query { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: monospace; font-size: 0.72rem; color: var(--rp-text-secondary, #374151); }
.sh-proc-slow { color: #d97706; font-weight: 700; }
.sh-proc-very-slow { color: #dc2626; font-weight: 700; }
.sh-empty { color: var(--rp-text-muted, #9ca3af); font-size: 0.8rem; text-align: center; padding: 16px 0; }

/* Worker legend */
.sh-workers-legend { display: flex; gap: 12px; margin-bottom: 10px; }
.sh-legend-item { display: flex; align-items: center; gap: 5px; font-size: 0.72rem; color: var(--rp-text-muted, #6b7280); }
.sh-legend-dot { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }
.sh-legend-dot.active { background: #4f46e5; }
.sh-legend-dot.idle   { background: #16a34a; }

/* Status indicator */
.sh-status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; }
.sh-status-dot.live { background: #16a34a; animation: sh-pulse 2s infinite; }
@keyframes sh-pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
.sh-last-updated { font-size: 0.72rem; color: var(--rp-text-muted, #9ca3af); margin-left: auto; }

/* Dark mode */
html[data-theme="dark"] .sh-panel {
	background: #2d3748;
	border-color: #4a5568;
}
html[data-theme="dark"] .sh-panel-hdr {
	background: #374151;
	border-color: #4a5568;
	color: #e2e8f0;
}
html[data-theme="dark"] .sh-metric {
	border-color: #4a5568;
}
html[data-theme="dark"] .sh-metric-label { color: #a0aec0; }
html[data-theme="dark"] .sh-metric-val   { color: #e2e8f0; }
html[data-theme="dark"] .sh-worker        { background: #374151; }
html[data-theme="dark"] .sh-worker.active { background: #4f46e5; }
html[data-theme="dark"] .sh-worker.idle   { background: #16a34a; }
html[data-theme="dark"] .sh-proc-table th {
	color: #718096;
	border-color: #4a5568;
}
html[data-theme="dark"] .sh-proc-table td {
	border-color: #4a5568;
	color: #cbd5e0;
}
html[data-theme="dark"] .sh-proc-table td.sh-query { color: #a0aec0; }
html[data-theme="dark"] .sh-empty        { color: #718096; }
html[data-theme="dark"] .sh-last-updated { color: #718096; }
</style>

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-heartbeat rp-header-icon"></i>
				<h1 class="rp-header-title">Server Health</h1>
			</div>
		</div>
		<div class="rp-header-actions" style="display:flex;align-items:center;gap:10px;">
			<span class="sh-status-dot live" id="sh-dot"></span>
			<span style="font-size:0.8rem;color:var(--rp-text-secondary,#374151)">Live</span>
			<span class="sh-last-updated" id="sh-updated">—</span>
		</div>
	</div>

	<div class="sh-grid">

		<!-- FPM Panel -->
		<div class="sh-panel">
			<div class="sh-panel-hdr"><i class="fas fa-cogs"></i> PHP-FPM Workers</div>
			<div class="sh-panel-body">
				<div class="sh-workers" id="sh-workers"></div>
				<div class="sh-workers-legend">
					<span class="sh-legend-item"><span class="sh-legend-dot active"></span> Active</span>
					<span class="sh-legend-item"><span class="sh-legend-dot idle"></span> Idle</span>
				</div>
				<div id="sh-fpm-metrics"></div>
			</div>
		</div>

		<!-- DB Panel -->
		<div class="sh-panel">
			<div class="sh-panel-hdr"><i class="fas fa-database"></i> Database</div>
			<div class="sh-panel-body" id="sh-db-metrics"></div>
		</div>

		<!-- Active Queries Panel -->
		<div class="sh-panel">
			<div class="sh-panel-hdr"><i class="fas fa-terminal"></i> Active Queries</div>
			<div class="sh-panel-body" id="sh-procs"></div>
		</div>

	</div>

</div><!-- /rp-root -->

<script>
(function() {
	var UIR = '<?=UIR?>';
	var prevQuestions = null;
	var prevSelects   = null;
	var prevInserts   = null;
	var prevUpdates   = null;
	var prevDeletes   = null;
	var prevShowFields = null;
	var prevShowKeys   = null;
	var prevPollTime  = null;

	/* ── Render FPM panel ───────────────────────────── */
	function renderFpm(fpm) {
		if (!fpm) {
			document.getElementById('sh-workers').innerHTML = '<span style="color:#9ca3af;font-size:0.8rem">FPM status unavailable</span>';
			document.getElementById('sh-fpm-metrics').innerHTML = '';
			return;
		}
		var total   = (fpm['total processes']   || 0);
		var active  = (fpm['active processes']  || 0);
		var idle    = (fpm['idle processes']    || 0);
		var queue   = (fpm['listen queue']      || 0);
		var maxHit  = (fpm['max children reached'] || 0);
		var slow    = (fpm['slow requests']     || 0);
		var conn    = (fpm['accepted conn']     || 0);

		var wHtml = '';
		for (var i = 0; i < total; i++) {
			var cls = i < active ? 'active' : 'idle';
			wHtml += '<div class="sh-worker ' + cls + '" title="Worker ' + (i+1) + ': ' + (i < active ? 'Active' : 'Idle') + '"></div>';
		}
		document.getElementById('sh-workers').innerHTML = wHtml;

		var queueClass = queue > 0 ? 'alert' : '';
		var slowClass  = slow  > 0 ? 'warn'  : '';
		var maxClass   = maxHit > 5 ? 'warn' : '';
		document.getElementById('sh-fpm-metrics').innerHTML =
			metric('Active / Total', active + ' / ' + total, active >= total ? 'alert' : '') +
			metric('Listen Queue', queue, queueClass) +
			metric('Max Children Reached', maxHit, maxClass) +
			metric('Slow Requests', slow, slowClass) +
			metric('Total Accepted', conn.toLocaleString(), '');
	}

	/* ── Render DB panel ────────────────────────────── */
	function renderDb(db) {
		if (!db || !Object.keys(db).length) {
			document.getElementById('sh-db-metrics').innerHTML = '<div class="sh-empty">No data</div>';
			return;
		}
		var now        = Date.now();
		var questions  = parseInt(db['Questions']        || 0, 10);
		var selects    = parseInt(db['Com_select']       || 0, 10);
		var inserts    = parseInt(db['Com_insert']       || 0, 10);
		var updates    = parseInt(db['Com_update']       || 0, 10);
		var deletes    = parseInt(db['Com_delete']       || 0, 10);
		var showFields = parseInt(db['Com_show_fields'] || 0, 10);
		var showKeys   = parseInt(db['Com_show_keys']   || 0, 10);
		var qps = '—', sps = '—', wps = '—', sfps = '—';
		if (prevQuestions !== null && prevPollTime !== null) {
			var dt = (now - prevPollTime) / 1000;
			if (dt > 0) {
				qps  = ((questions  - prevQuestions)  / dt).toFixed(1);
				sps  = ((selects    - prevSelects)    / dt).toFixed(1);
				var writes = (inserts - prevInserts) + (updates - prevUpdates) + (deletes - prevDeletes);
				wps  = (writes / dt).toFixed(1);
				var schemaInspects = (showFields - prevShowFields) + (showKeys - prevShowKeys);
				sfps = (schemaInspects / dt).toFixed(1);
			}
		}
		prevQuestions  = questions;
		prevSelects    = selects;
		prevInserts    = inserts;
		prevUpdates    = updates;
		prevDeletes    = deletes;
		prevShowFields = showFields;
		prevShowKeys   = showKeys;
		prevPollTime   = now;

		var running   = parseInt(db['Threads_running']      || 0, 10);
		var connected = parseInt(db['Threads_connected']    || 0, 10);
		var slow      = parseInt(db['Slow_queries']         || 0, 10);
		var maxConn   = parseInt(db['Max_used_connections'] || 0, 10);
		var uptime    = parseInt(db['Uptime'] || 0, 10);
		var h = Math.floor(uptime/3600), m = Math.floor((uptime%3600)/60);
		var uptimeStr = h + 'h ' + m + 'm';

		document.getElementById('sh-db-metrics').innerHTML =
			metric('DB Statements / sec', qps, '') +
			metric('↳ Reads / sec', sps, '') +
			metric('↳ Writes / sec', wps, '') +
			metric('↳ Schema inspects / sec', sfps, '') +
			metric('Threads Running', running, running > 3 ? 'warn' : '') +
			metric('Threads Connected', connected, '') +
			metric('Slow Queries (total)', slow, slow > 0 ? 'warn' : '') +
			metric('Peak Connections', maxConn, '') +
			metric('DB Uptime', uptimeStr, '');
	}

	/* ── Render process list ────────────────────────── */
	function renderProcs(procs) {
		if (!procs || !procs.length) {
			document.getElementById('sh-procs').innerHTML = '<div class="sh-empty"><i class="fas fa-check-circle" style="font-size:1.5rem;color:#16a34a;margin-bottom:6px;display:block"></i>No active queries</div>';
			return;
		}
		var html = '<table class="sh-proc-table"><thead><tr><th>Time</th><th>Cmd</th><th>State</th><th>Query</th></tr></thead><tbody>';
		procs.forEach(function(p) {
			var tc = p.time >= 5 ? 'sh-proc-very-slow' : (p.time >= 1 ? 'sh-proc-slow' : '');
			html += '<tr>' +
				'<td class="' + tc + '">' + p.time + 's</td>' +
				'<td>' + esc(p.command) + '</td>' +
				'<td style="color:#9ca3af">' + esc(p.state || '') + '</td>' +
				'<td class="sh-query" title="' + esc(p.info || '') + '">' + esc(p.info || '—') + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		document.getElementById('sh-procs').innerHTML = html;
	}

	function metric(label, val, cls) {
		return '<div class="sh-metric"><span class="sh-metric-label">' + label + '</span><span class="sh-metric-val ' + (cls||'') + '">' + val + '</span></div>';
	}
	function esc(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	/* ── Poll loop ──────────────────────────────────── */
	function poll() {
		fetch(UIR + 'Admin/ajax/serverhealth_stats', { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.status !== 0) return;
				renderFpm(d.fpm);
				renderDb(d.db);
				renderProcs(d.processes);
				document.getElementById('sh-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
			})
			.catch(function() {
				document.getElementById('sh-dot').classList.remove('live');
			});
	}
	poll();
	setInterval(poll, 2000);
})();
</script>
