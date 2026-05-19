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
.sh-workers { display: flex; flex-wrap: nowrap; gap: 4px; margin-bottom: 12px; }
.sh-worker {
	width: 20px; height: 20px;
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

/* Alert log */
.sh-al-subtitle { font-weight: 400; font-size: 0.7rem; color: var(--rp-text-muted, #9ca3af); margin-left: 4px; }
.sh-al-clear { margin-left: auto; font-size: 0.72rem; padding: 2px 10px; border: 1px solid var(--rp-border, #d1d5db); border-radius: 4px; background: transparent; cursor: pointer; color: var(--rp-text-muted, #6b7280); }
.sh-al-clear:hover { background: var(--rp-bg-secondary, #f3f4f6); }
.sh-al-entry { padding: 10px 0; border-bottom: 1px solid var(--rp-border, #e5e7eb); }
.sh-al-entry:last-child { border-bottom: none; }
.sh-al-header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
.sh-al-time { font-size: 0.75rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--rp-text, #111827); min-width: 70px; }
.sh-al-tag { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; font-size: 0.7rem; font-weight: 700; padding: 1px 7px; border-radius: 10px; }
.sh-al-ctx { margin-left: auto; font-size: 0.72rem; color: var(--rp-text-muted, #9ca3af); font-variant-numeric: tabular-nums; }
.sh-al-rows { display: flex; flex-direction: column; gap: 3px; padding-left: 8px; border-left: 2px solid var(--rp-border, #e5e7eb); }
.sh-al-row { display: flex; gap: 8px; font-size: 0.76rem; align-items: baseline; }
.sh-al-rowtime { min-width: 28px; font-weight: 700; font-variant-numeric: tabular-nums; }
.sh-al-state { min-width: 120px; color: var(--rp-text-muted, #9ca3af); font-size: 0.72rem; }
.sh-al-user  { min-width: 100px; color: var(--rp-text-muted, #9ca3af); font-size: 0.72rem; }
.sh-al-sql { font-family: monospace; font-size: 0.72rem; color: var(--rp-text-secondary, #374151); word-break: break-all; }
.sh-al-none { font-size: 0.76rem; color: var(--rp-text-muted, #9ca3af); font-style: italic; }

html[data-theme="dark"] .sh-al-clear { border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .sh-al-clear:hover { background: #374151; }
html[data-theme="dark"] .sh-al-entry { border-color: #4a5568; }
html[data-theme="dark"] .sh-al-time  { color: #e2e8f0; }
html[data-theme="dark"] .sh-al-tag   { background: #422006; border-color: #78350f; color: #fcd34d; }
html[data-theme="dark"] .sh-al-ctx   { color: #718096; }
html[data-theme="dark"] .sh-al-rows  { border-color: #4a5568; }
html[data-theme="dark"] .sh-al-state { color: #718096; }
html[data-theme="dark"] .sh-al-user  { color: #718096; }
html[data-theme="dark"] .sh-al-sql   { color: #a0aec0; }
html[data-theme="dark"] .sh-al-none  { color: #718096; }

/* Optional filesystem check */
.sh-fs-btn { font-size: 0.78rem; padding: 6px 14px; border: 1px solid var(--rp-border, #d1d5db); border-radius: 4px; background: var(--rp-bg-secondary, #f3f4f6); cursor: pointer; color: var(--rp-text, #111827); display: inline-flex; align-items: center; gap: 6px; }
.sh-fs-btn:hover:not(:disabled) { background: var(--rp-bg, #fff); }
.sh-fs-btn:disabled { opacity: 0.6; cursor: wait; }
.sh-fs-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--rp-border, #e5e7eb); font-size: 0.85rem; }
.sh-fs-row:last-child { border-bottom: none; }
.sh-fs-row.warn  > span:last-child { color: #b45309; font-weight: 600; }
.sh-fs-row.alert > span:last-child { color: #b91c1c; font-weight: 700; }
.sh-fs-checked { font-size: 0.7rem; color: var(--rp-text-muted, #9ca3af); margin-top: 8px; font-style: italic; }
#sh-fs-result { margin-top: 12px; }
html[data-theme="dark"] .sh-fs-btn { background: #374151; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .sh-fs-btn:hover:not(:disabled) { background: #4a5568; }
html[data-theme="dark"] .sh-fs-row { border-color: #4a5568; }
html[data-theme="dark"] .sh-fs-checked { color: #718096; }

<?php if (!empty($ShowLoadTest)): ?>
/* Load test (dev only) */
.sh-lt-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
.sh-lt-group { display: flex; flex-direction: column; gap: 3px; }
.sh-lt-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted, #9ca3af); }
.sh-lt-select { padding: 6px 10px; border: 1px solid var(--rp-border, #d1d5db); border-radius: 6px; font-size: 0.84rem; color: var(--rp-text, #111827); background: var(--rp-card-bg, #fff); }
.sh-lt-slider-row { display: flex; align-items: center; gap: 8px; }
.sh-lt-slider { width: 120px; }
.sh-lt-slider-val { font-weight: 700; min-width: 2ch; font-variant-numeric: tabular-nums; }
.sh-lt-btn { padding: 7px 20px; border: none; border-radius: 6px; font-size: 0.85rem; font-weight: 700; cursor: pointer; }
.sh-lt-btn-start { background: #4338ca; color: #fff; }
.sh-lt-btn-start:hover { background: #3730a3; }
.sh-lt-btn-stop  { background: #dc2626; color: #fff; }
.sh-lt-btn-stop:hover { background: #b91c1c; }
.sh-lt-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
.sh-lt-stat { background: var(--rp-bg-secondary, #f9fafb); border: 1px solid var(--rp-border, #e5e7eb); border-radius: 8px; padding: 10px 12px; text-align: center; }
.sh-lt-stat-val { font-size: 1.4rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--rp-text, #111827); }
.sh-lt-stat-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted, #9ca3af); margin-top: 2px; }
.sh-lt-log { margin-top: 12px; max-height: 120px; overflow-y: auto; font-size: 0.75rem; font-family: monospace; color: var(--rp-text-secondary, #374151); background: var(--rp-bg-secondary, #f9fafb); border: 1px solid var(--rp-border, #e5e7eb); border-radius: 6px; padding: 8px 10px; }
html[data-theme="dark"] .sh-lt-select { background: #374151; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .sh-lt-stat { background: #374151; border-color: #4a5568; }
html[data-theme="dark"] .sh-lt-stat-val { color: #e2e8f0; }
html[data-theme="dark"] .sh-lt-log { background: #1e2433; border-color: #4a5568; color: #a0aec0; }
<?php endif; ?>
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

		<!-- Memcache Panel -->
		<div class="sh-panel">
			<div class="sh-panel-hdr"><i class="fas fa-bolt"></i> Memcache</div>
			<div class="sh-panel-body" id="sh-mc-metrics"></div>
		</div>

		<!-- Weather Health Panel -->
		<div class="sh-panel">
			<div class="sh-panel-hdr"><i class="fas fa-cloud-sun"></i> Weather Data Freshness</div>
			<div class="sh-panel-body" id="sh-wx-metrics"></div>
		</div>

		<!-- Optional Checks -->
		<div class="sh-panel">
			<div class="sh-panel-hdr"><i class="fas fa-toolbox"></i> Optional Checks <span class="sh-al-subtitle">on demand</span></div>
			<div class="sh-panel-body">
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;align-items:center">
					<button class="sh-fs-btn" id="sh-fs-btn"><i class="fas fa-hdd"></i> Check Filesystem</button>
					<button class="sh-fs-btn" id="sh-memcache-btn"><i class="fas fa-database"></i> Check Memcache</button>
					<button class="sh-fs-btn" id="sh-opcache-btn"><i class="fab fa-php"></i> Check OPcache</button>
					<button class="sh-fs-btn" id="sh-weather-btn"><i class="fas fa-cloud-sun"></i> Refresh Weather</button>
					<button class="sh-fs-btn" id="sh-wxstats-btn"><i class="fas fa-chart-bar"></i> Weather API Stats</button>
					<button class="sh-fs-btn" id="sh-clear-btn" style="margin-left:auto"><i class="fas fa-times"></i> Clear</button>
				</div>
				<div id="sh-fs-result"></div>
				<div id="sh-memcache-result"></div>
				<div id="sh-opcache-result"></div>
				<div id="sh-weather-result"></div>
				<div id="sh-wxstats-result"></div>
			</div>
		</div>

	</div>

	<!-- Active Queries Panel — full width below the 3-up grid -->
	<div class="sh-panel" style="margin-bottom:16px">
		<div class="sh-panel-hdr"><i class="fas fa-terminal"></i> Active Queries</div>
		<div class="sh-panel-body" id="sh-procs"></div>
	</div>

	<!-- Active Workers Panel -->
	<div class="sh-panel" style="margin-bottom:16px">
		<div class="sh-panel-hdr"><i class="fas fa-running"></i> Active Workers <span class="sh-al-subtitle">PHP-FPM requests in flight</span></div>
		<div class="sh-panel-body" id="sh-workers-list"></div>
	</div>

	<?php if (!empty($ShowLoadTest)): ?>
	<!-- Load Test Panel (dev only) -->
	<div class="sh-panel" style="margin-bottom:16px">
		<div class="sh-panel-hdr"><i class="fas fa-tachometer-alt"></i> Load Test <span class="sh-al-subtitle">dev only</span></div>
		<div class="sh-panel-body">
			<div class="sh-lt-controls">
				<div class="sh-lt-group">
					<span class="sh-lt-label">Target Page</span>
					<select class="sh-lt-select" id="sh-lt-target">
<?php foreach ($LoadTestTargets as $t): ?>
						<option value="<?=htmlspecialchars($t['url'])?>"><?=htmlspecialchars($t['label'])?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div class="sh-lt-group">
					<span class="sh-lt-label">Concurrency</span>
					<div class="sh-lt-slider-row">
						<input type="range" class="sh-lt-slider" id="sh-lt-concurrency" min="1" max="20" value="3">
						<span class="sh-lt-slider-val" id="sh-lt-conc-val">3</span>
					</div>
				</div>
				<div class="sh-lt-group" style="margin-left:auto;">
					<span class="sh-lt-label">&nbsp;</span>
					<button class="sh-lt-btn sh-lt-btn-start" id="sh-lt-start">&#9654; Start</button>
				</div>
			</div>
			<div class="sh-lt-stats">
				<div class="sh-lt-stat"><div class="sh-lt-stat-val" id="sh-lt-rps">—</div><div class="sh-lt-stat-label">Req / sec</div></div>
				<div class="sh-lt-stat"><div class="sh-lt-stat-val" id="sh-lt-avg">—</div><div class="sh-lt-stat-label">Avg ms</div></div>
				<div class="sh-lt-stat"><div class="sh-lt-stat-val" id="sh-lt-p95">—</div><div class="sh-lt-stat-label">p95 ms</div></div>
				<div class="sh-lt-stat"><div class="sh-lt-stat-val" id="sh-lt-total">0</div><div class="sh-lt-stat-label">Requests</div></div>
				<div class="sh-lt-stat"><div class="sh-lt-stat-val" id="sh-lt-errors" style="color:#dc2626">0</div><div class="sh-lt-stat-label">Errors</div></div>
			</div>
			<div class="sh-lt-log" id="sh-lt-log" style="display:none"></div>
		</div>
	</div>
	<?php endif; ?>

	<!-- Threshold Event Log -->
	<div id="sh-alert-panel" class="sh-panel" style="display:none">
		<div class="sh-panel-hdr">
			<i class="fas fa-exclamation-triangle" style="color:#d97706"></i>
			Threshold Events
			<span class="sh-al-subtitle">captured while page is open</span>
			<button class="sh-al-clear" id="sh-al-clear">Clear</button>
		</div>
		<div class="sh-panel-body" id="sh-al-body"></div>
	</div>

</div><!-- /rp-root -->

<script>
(function() {
	var UIR = '<?=UIR?>';
	var currentQps = '—', currentSps = '—';
	var prevQuestions = null;
	var prevSelects   = null;
	var prevInserts   = null;
	var prevUpdates   = null;
	var prevDeletes   = null;
	var prevShowFields = null;
	var prevShowKeys   = null;
	var prevAccepted  = null;
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
		var startSince = parseInt(fpm['start since'] || 0, 10);
		// Requests/sec from accepted-conn delta. Uses prevPollTime which renderDb
		// updates at the end of its own pass; on the very first poll the rate is '—'.
		var rps = '—';
		if (prevAccepted !== null && prevPollTime !== null) {
			var dtFpm = (Date.now() - prevPollTime) / 1000;
			if (dtFpm > 0) rps = ((conn - prevAccepted) / dtFpm).toFixed(1);
		}
		prevAccepted = conn;
		// FPM master uptime; reset when the container/pool restarts.
		var d = Math.floor(startSince / 86400);
		var h = Math.floor((startSince % 86400) / 3600);
		var m = Math.floor((startSince % 3600) / 60);
		var fpmUptimeStr = d > 0 ? (d + 'd ' + h + 'h ' + m + 'm')
		                  : (h + 'h ' + m + 'm');

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
			metric('Requests / sec', rps, '') +
			metric('Total Accepted', conn.toLocaleString(), '') +
			metric('FPM Uptime', fpmUptimeStr, '');
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
				currentQps = qps; currentSps = sps;
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
		var lockNow   = parseInt(db['Innodb_row_lock_current_waits'] || 0, 10);
		var lockSum   = parseInt(db['Innodb_row_lock_waits']         || 0, 10);
		var lockMs    = parseInt(db['Innodb_row_lock_time']          || 0, 10);
		var uptime    = parseInt(db['Uptime'] || 0, 10);
		var h = Math.floor(uptime/3600), m = Math.floor((uptime%3600)/60);
		var uptimeStr = h + 'h ' + m + 'm';

		// Compact human-readable wait time: ms < 1s, s < 60s, then m + s.
		var lockTimeStr;
		if (lockMs < 1000)        lockTimeStr = lockMs + ' ms';
		else if (lockMs < 60000)  lockTimeStr = (lockMs / 1000).toFixed(1) + ' s';
		else                      lockTimeStr = Math.floor(lockMs / 60000) + 'm ' + Math.floor((lockMs % 60000) / 1000) + 's';

		document.getElementById('sh-db-metrics').innerHTML =
			metric('DB Statements / sec', qps, '') +
			metric('↳ Reads / sec', sps, '') +
			metric('↳ Writes / sec', wps, '') +
			metric('↳ Schema inspects / sec', sfps, '') +
			metric('Threads Running', running, running > 3 ? 'warn' : '') +
			metric('Threads Connected', connected, '') +
			metric('Slow Queries (total)', slow, slow > 0 ? 'warn' : '') +
			metric('Row Lock Waits (now)',   lockNow, lockNow > 0 ? 'warn' : '') +
			metric('Row Lock Waits (total)', lockSum.toLocaleString(), '') +
			metric('Row Lock Time (total)',  lockTimeStr, '') +
			metric('Peak Connections', maxConn, '') +
			metric('DB Uptime', uptimeStr, '');
	}

	/* ── Render process list ────────────────────────── */
	function renderProcs(procs) {
		if (!procs || !procs.length) {
			document.getElementById('sh-procs').innerHTML = '<div class="sh-empty"><i class="fas fa-check-circle" style="font-size:1.5rem;color:#16a34a;margin-bottom:6px;display:block"></i>No active queries</div>';
			return;
		}
		var html = '<table class="sh-proc-table"><thead><tr><th>Time</th><th>Cmd</th><th>User</th><th>State</th><th>Query</th></tr></thead><tbody>';
		procs.forEach(function(p) {
			var tc = p.time >= 5 ? 'sh-proc-very-slow' : (p.time >= 1 ? 'sh-proc-slow' : '');
			var host = p.host ? p.host.replace(/:.*$/, '') : '';
			var userDisplay = p.user + (host ? '@' + host : '');
			html += '<tr>' +
				'<td class="' + tc + '">' + p.time + 's</td>' +
				'<td>' + esc(p.command) + '</td>' +
				'<td style="color:#9ca3af;font-size:.85em">' + esc(userDisplay) + '</td>' +
				'<td style="color:#9ca3af">' + esc(p.state || '') + '</td>' +
				'<td class="sh-query" title="' + esc(p.info || '') + '">' + esc(p.info || '—') + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		document.getElementById('sh-procs').innerHTML = html;
	}

	function renderWorkers(workers) {
		var el = document.getElementById('sh-workers-list');
		if (!workers || !workers.length) {
			el.innerHTML = '<div class="sh-empty">No active workers</div>';
			return;
		}
		workers.sort(function(a, b) { return (b.duration || 0) - (a.duration || 0); });
		var html = '<table class="sh-proc-table"><thead><tr><th>Time</th><th>PID</th><th>Method</th><th>Request URI</th></tr></thead><tbody>';
		workers.forEach(function(w) {
			var ms = w.duration || 0;
			var sec = (ms / 1000000).toFixed(1);
			var tc = ms >= 5000000 ? 'sh-proc-very-slow' : (ms >= 1000000 ? 'sh-proc-slow' : '');
			html += '<tr>' +
				'<td class="' + tc + '">' + sec + 's</td>' +
				'<td style="color:#9ca3af">' + w.pid + '</td>' +
				'<td>' + esc(w.method || '') + '</td>' +
				'<td class="sh-query" title="' + esc(w.uri || '') + '">' + esc(w.uri || '—') + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		el.innerHTML = html;
	}

	function metric(label, val, cls) {
		return '<div class="sh-metric"><span class="sh-metric-label">' + label + '</span><span class="sh-metric-val ' + (cls||'') + '">' + val + '</span></div>';
	}

	/* ── Render Memcache panel ──────────────────────────
	 * Tracks prevMcItems / prevMcUptime / prevMcFlush across polls to flag
	 * the exact symptoms we saw with the weather stats bucket: keys vanishing
	 * with no eviction signal. A sudden curr_items drop >50%, a flush count
	 * bump, or an uptime that goes backwards all light up here in real-time
	 * instead of being invisible until someone clicks the on-demand panel.
	 */
	var prevMcItems  = null;
	var prevMcUptime = null;
	var prevMcFlush  = null;
	function renderMc(mc) {
		var el = document.getElementById('sh-mc-metrics');
		if (!mc) { el.innerHTML = '<div class="sh-empty">Memcache unavailable</div>'; return; }
		var d = Math.floor(mc.uptime / 86400);
		var h = Math.floor((mc.uptime % 86400) / 3600);
		var m = Math.floor((mc.uptime % 3600) / 60);
		var uptimeStr = d > 0 ? (d + 'd ' + h + 'h ' + m + 'm') : (h + 'h ' + m + 'm');

		// Drop/restart/flush detectors — only valid once we have a baseline.
		// Each event ALSO fires a sticky entry in the alert log so the user
		// doesn't need to be watching the live panel when it happens.
		var itemsCls = '', uptimeCls = '', flushCls = '', evCls = '';
		var note = '';
		var fired = [];
		if (prevMcItems !== null && mc.curr_items < prevMcItems * 0.5 && prevMcItems > 20) {
			itemsCls = 'alert';
			note = ' (was ' + fmtCount(prevMcItems) + ')';
			fired.push('Memcache items dropped: ' + fmtCount(prevMcItems) + ' → ' + fmtCount(mc.curr_items));
		}
		if (prevMcUptime !== null && mc.uptime < prevMcUptime) {
			uptimeCls = 'alert';
			fired.push('Memcache restarted (uptime: ' + fmtCount(prevMcUptime) + 's → ' + fmtCount(mc.uptime) + 's)');
		}
		if (prevMcFlush !== null && mc.cmd_flush > prevMcFlush) {
			flushCls = 'alert';
			fired.push('Memcache flush_all issued (count: ' + fmtCount(prevMcFlush) + ' → ' + fmtCount(mc.cmd_flush) + ')');
		}
		if (mc.evictions > 0) evCls = 'warn';

		if (fired.length) {
			alertLog.unshift({
				time:     new Date().toLocaleTimeString(),
				triggers: fired,
				kind:     'memcache',
				queries:  [],
				workers:  [],
				qps:      currentQps,
				sps:      currentSps,
			});
			if (alertLog.length > 30) alertLog.pop();
			renderAlertLog();
		}

		var memPct  = mc.limit_maxbytes ? Math.round(mc.bytes / mc.limit_maxbytes * 100 * 10) / 10 : 0;
		var memCls  = memPct >= 80 ? 'warn' : '';
		var totGets = mc.get_hits + mc.get_misses;
		var hitPct  = totGets ? Math.round(mc.get_hits / totGets * 100 * 10) / 10 : null;

		el.innerHTML =
			metric('Memory used', memPct + '% (' + fmtBytes(mc.bytes) + ' / ' + fmtBytes(mc.limit_maxbytes) + ')', memCls) +
			metric('Hit rate', totGets ? (hitPct + '%') : '— (no reads yet)', '') +
			metric('Items cached', fmtCount(mc.curr_items) + note, itemsCls) +
			metric('Uptime', uptimeStr, uptimeCls) +
			metric('Connections (cur / total)', fmtCount(mc.curr_connections) + ' / ' + fmtCount(mc.total_connections), '') +
			metric('Evictions (lifetime)', fmtCount(mc.evictions), evCls) +
			metric('Flushes (lifetime)', fmtCount(mc.cmd_flush), flushCls);

		prevMcItems  = mc.curr_items;
		prevMcUptime = mc.uptime;
		prevMcFlush  = mc.cmd_flush;
	}

	/* ── Render Weather Data Freshness ──────────────────
	 * Snapshot of how many active parks have recently-refreshed weather
	 * rows (vs aging or stale) plus upcoming event-venue counts. Lets the
	 * admin spot at a glance whether cron + lazy fallback are keeping up.
	 */
	function renderWx(wx) {
		var el = document.getElementById('sh-wx-metrics');
		if (!wx) { el.innerHTML = '<div class="sh-empty">Weather data unavailable</div>'; return; }
		var freshPct = wx.parks_active ? Math.round(wx.parks_fresh / wx.parks_active * 100) : 0;
		var stalePct = wx.parks_active ? Math.round(wx.parks_stale / wx.parks_active * 100) : 0;
		var freshCls = freshPct >= 80 ? '' : (freshPct >= 50 ? 'warn' : 'alert');
		var staleCls = stalePct >= 20 ? 'alert' : (stalePct >= 10 ? 'warn' : '');
		var oldestCls = '';
		if (wx.parks_oldest_min !== null) {
			if (wx.parks_oldest_min > 240) oldestCls = 'alert';
			else if (wx.parks_oldest_min > 120) oldestCls = 'warn';
		}
		var oldestStr = wx.parks_oldest_min === null ? '—' :
			(wx.parks_oldest_min < 60 ? wx.parks_oldest_min + ' min'
				: Math.floor(wx.parks_oldest_min / 60) + 'h ' + (wx.parks_oldest_min % 60) + 'm');

		el.innerHTML =
			metric('Active parks tracked', fmtCount(wx.parks_active), '') +
			metric('Fresh (< 90 min)', fmtCount(wx.parks_fresh) + ' (' + freshPct + '%)', freshCls) +
			metric('Aging (90 min – 4 h)', fmtCount(wx.parks_aging), '') +
			metric('Stale (> 4 h or no data)', fmtCount(wx.parks_stale) + ' (' + stalePct + '%)', staleCls) +
			metric('Oldest park row', oldestStr, oldestCls) +
			metric('Upcoming events (14 d)', fmtCount(wx.events_upcoming) + ' (' + fmtCount(wx.events_with_coords) + ' with venue coords)', '');
	}
	function esc(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	/* ── Threshold event log ───────────────────────── */
	var alertLog = [];
	var threshState = { workersFull: false, queueFull: false, threadsHigh: false, rowLockWaiting: false };
	var prevLockWaits = null;   // cumulative Innodb_row_lock_waits, last poll

	function checkThresholds(fpm, db, procs, workers) {
		var active  = fpm ? (fpm['active processes'] || 0) : 0;
		var total   = fpm ? (fpm['total processes']  || 0) : 0;
		var queue   = fpm ? (fpm['listen queue']     || 0) : 0;
		var threads = db  ? parseInt(db['Threads_running'] || 0, 10) : 0;
		var lockNow = db  ? parseInt(db['Innodb_row_lock_current_waits'] || 0, 10) : 0;
		var lockSum = db  ? parseInt(db['Innodb_row_lock_waits']         || 0, 10) : 0;

		var workersFull    = total > 0 && active >= total;
		var queueFull      = queue > 0;
		// Threshold-running is a noisy signal on a busy app — the 2s poll itself
		// often catches a handful of in-flight queries (including its own
		// PROCESSLIST snapshot query). Workers/queue triggers are the real
		// saturation signals. Bumped from >3 to >8.
		var threadsHigh    = threads > 8;
		var rowLockWaiting = lockNow > 0;
		// Catch fast transient waits that completed within a poll interval:
		// cumulative waits went up since last poll, even if current is back to 0.
		var newLockSince   = (prevLockWaits !== null && lockSum > prevLockWaits) ? (lockSum - prevLockWaits) : 0;

		var fired = [];
		if (workersFull    && !threshState.workersFull)    fired.push('Workers full (' + active + '/' + total + ')');
		if (queueFull      && !threshState.queueFull)      fired.push('Listen queue: ' + queue);
		if (threadsHigh    && !threshState.threadsHigh)    fired.push('Threads running: ' + threads);
		if (rowLockWaiting && !threshState.rowLockWaiting) fired.push('Row lock waits pending: ' + lockNow);
		// A single transient wait per 2s poll is common app-level noise. Only
		// flag bursts that suggest real contention on a hot row.
		else if (!rowLockWaiting && newLockSince >= 3)     fired.push('Row lock waits (transient): ' + newLockSince);

		threshState.workersFull    = workersFull;
		threshState.queueFull      = queueFull;
		threshState.threadsHigh    = threadsHigh;
		threshState.rowLockWaiting = rowLockWaiting;
		prevLockWaits              = lockSum;

		if (!fired.length) return;

		alertLog.unshift({
			time:     new Date().toLocaleTimeString(),
			triggers: fired,
			queries:  procs ? procs.slice() : [],
			workers:  workers ? workers.slice() : [],
			qps:      currentQps,
			sps:      currentSps,
		});
		if (alertLog.length > 30) alertLog.pop();
		renderAlertLog();
	}

	function renderAlertLog() {
		var panel = document.getElementById('sh-alert-panel');
		var body  = document.getElementById('sh-al-body');
		if (!alertLog.length) { panel.style.display = 'none'; return; }
		panel.style.display = '';
		var html = '';
		alertLog.forEach(function(e) {
			html += '<div class="sh-al-entry">';
			html += '<div class="sh-al-header">';
			html += '<span class="sh-al-time">' + e.time + '</span>';
			html += e.triggers.map(function(t) { return '<span class="sh-al-tag">' + esc(t) + '</span>'; }).join('');
			html += '<span class="sh-al-ctx">reads ' + e.sps + '/s &nbsp;&middot;&nbsp; stmts ' + e.qps + '/s</span>';
			html += '</div>';
			if (e.queries.length) {
				html += '<div class="sh-al-rows">';
				e.queries.forEach(function(p) {
					var tc = p.time >= 5 ? 'sh-proc-very-slow' : (p.time >= 1 ? 'sh-proc-slow' : '');
					var host = p.host ? p.host.replace(/:.*$/, '') : '';
					var userDisplay = p.user + (host ? '@' + host : '');
					html += '<div class="sh-al-row">' +
						'<span class="sh-al-rowtime ' + tc + '">' + p.time + 's</span>' +
						'<span class="sh-al-state">' + esc(p.state || p.command) + '</span>' +
						'<span class="sh-al-user">' + esc(userDisplay) + '</span>' +
						'<span class="sh-al-sql">' + esc(p.info || '—') + '</span>' +
						'</div>';
				});
				html += '</div>';
			} else if (e.kind !== 'memcache') {
				html += '<div class="sh-al-none">No active queries at capture time</div>';
			}
			if (e.workers && e.workers.length) {
				html += '<div class="sh-al-rows" style="margin-top:6px">';
				e.workers.sort(function(a, b) { return (b.duration || 0) - (a.duration || 0); });
				e.workers.forEach(function(w) {
					var ms = w.duration || 0;
					var sec = (ms / 1000000).toFixed(1);
					var tc = ms >= 5000000 ? 'sh-proc-very-slow' : (ms >= 1000000 ? 'sh-proc-slow' : '');
					html += '<div class="sh-al-row">' +
						'<span class="sh-al-rowtime ' + tc + '">' + sec + 's</span>' +
						'<span class="sh-al-state">pid ' + w.pid + '</span>' +
						'<span class="sh-al-user">' + esc(w.method || '') + '</span>' +
						'<span class="sh-al-sql">' + esc(w.uri || '—') + '</span>' +
						'</div>';
				});
				html += '</div>';
			}
			html += '</div>';
		});
		body.innerHTML = html;
	}

	document.getElementById('sh-al-clear').addEventListener('click', function() {
		alertLog = [];
		threshState = { workersFull: false, queueFull: false, threadsHigh: false };
		renderAlertLog();
	});

	/* ── Poll loop ──────────────────────────────────── */
	function poll() {
		fetch(UIR + 'Admin/ajax/serverhealth_stats', { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.status !== 0) return;
				renderFpm(d.fpm);
				renderDb(d.db);
				renderMc(d.memcache);
				renderWx(d.weather);
				renderProcs(d.processes);
				renderWorkers(d.workers);
				checkThresholds(d.fpm, d.db, d.processes, d.workers);
				document.getElementById('sh-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
			})
			.catch(function() {
				document.getElementById('sh-dot').classList.remove('live');
			});
	}
	poll();
	setInterval(poll, 2000);

	/* ── Optional filesystem check (on-demand) ───────── */
	function renderFsRow(label, value, cls) {
		return '<div class="sh-fs-row ' + (cls || '') + '"><span>' + label + '</span><span>' + value + '</span></div>';
	}
	function fmtBytes(n) {
		if (!n) return '—';
		if (n >= 1099511627776) return (n / 1099511627776).toFixed(1) + ' TB';
		if (n >= 1073741824)    return (n / 1073741824).toFixed(1) + ' GB';
		if (n >= 1048576)       return (n / 1048576).toFixed(1) + ' MB';
		return n + ' B';
	}
	function fmtCount(n) {
		if (!n) return '—';
		if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
		if (n >= 1000)    return (n / 1000).toFixed(0) + 'k';
		return String(n);
	}
	function wireCheckBtn(opts) {
		var btn    = document.getElementById(opts.btnId);
		var result = document.getElementById(opts.resultId);
		if (!btn || !result) return;
		btn.addEventListener('click', function() {
			btn.disabled  = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking…';
			fetch(UIR + 'Admin/ajax/' + opts.action, { credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(d) {
					if (!d || d.status !== 0) { result.innerHTML = '<div class="sh-empty">Check failed</div>'; return; }
					var html = opts.render(d) || '<div class="sh-empty">No data available</div>';
					html += '<div class="sh-fs-checked">Checked ' + new Date().toLocaleString() + '</div>';
					result.innerHTML = html;
				})
				.catch(function() {
					result.innerHTML = '<div class="sh-empty">Request failed</div>';
				})
				.finally(function() {
					btn.disabled  = false;
					btn.innerHTML = '<i class="fas fa-sync"></i> ' + opts.label;
				});
		});
	}

	wireCheckBtn({
		btnId:    'sh-fs-btn',
		resultId: 'sh-fs-result',
		action:   'serverhealth_fs_check',
		label:    'Check Filesystem',
		render:   function(d) {
			var html = '';
			if (d.disk) {
				var p = d.disk.used_pct;
				var cls = p >= 90 ? 'alert' : (p >= 80 ? 'warn' : '');
				html += renderFsRow('Disk usage', p + '% (' + fmtBytes(d.disk.total - d.disk.free) + ' / ' + fmtBytes(d.disk.total) + ')', cls);
			}
			if (d.inodes) {
				var p = d.inodes.used_pct;
				var cls = p >= 90 ? 'alert' : (p >= 80 ? 'warn' : '');
				html += renderFsRow('Inode usage', p + '% (' + fmtCount(d.inodes.used) + ' / ' + fmtCount(d.inodes.total) + ')', cls);
			}
			return html;
		},
	});

	wireCheckBtn({
		btnId:    'sh-memcache-btn',
		resultId: 'sh-memcache-result',
		action:   'serverhealth_memcache_check',
		label:    'Check Memcache',
		render:   function(d) {
			if (!d.memcache) return '';
			var m = d.memcache;
			var html = '';
			var sizePct = m.limit_maxbytes ? Math.round(m.bytes / m.limit_maxbytes * 100 * 10) / 10 : 0;
			var sizeCls = sizePct >= 90 ? 'alert' : (sizePct >= 75 ? 'warn' : '');
			html += renderFsRow('Memory used', sizePct + '% (' + fmtBytes(m.bytes) + ' / ' + fmtBytes(m.limit_maxbytes) + ')', sizeCls);
			var totalGets = (m.get_hits || 0) + (m.get_misses || 0);
			var hitRate = totalGets ? Math.round((m.get_hits / totalGets) * 1000) / 10 : 0;
			html += renderFsRow('Hit rate', totalGets ? (hitRate + '%') : '— (no reads yet)', '');
			html += renderFsRow('Items cached', fmtCount(m.curr_items), '');
			var evCls = (m.evictions > 0) ? 'warn' : '';
			html += renderFsRow('Evictions (lifetime)', fmtCount(m.evictions), evCls);
			return html;
		},
	});

	wireCheckBtn({
		btnId:    'sh-opcache-btn',
		resultId: 'sh-opcache-result',
		action:   'serverhealth_opcache_check',
		label:    'Check OPcache',
		render:   function(d) {
			if (!d.opcache) return '';
			var o = d.opcache;
			var html = '';
			var memPct = o.memory_total ? Math.round(o.memory_used / o.memory_total * 100 * 10) / 10 : 0;
			var memCls = memPct >= 90 ? 'alert' : (memPct >= 75 ? 'warn' : '');
			html += renderFsRow('Memory used', memPct + '% (' + fmtBytes(o.memory_used) + ' / ' + fmtBytes(o.memory_total) + ')', memCls);
			var wastedPct = o.memory_total ? Math.round(o.memory_wasted / o.memory_total * 100 * 10) / 10 : 0;
			var wastedCls = wastedPct >= 20 ? 'warn' : '';
			html += renderFsRow('Wasted memory', wastedPct + '% (' + fmtBytes(o.memory_wasted) + ')', wastedCls);
			html += renderFsRow('Cached scripts', fmtCount(o.scripts), '');
			html += renderFsRow('Hit rate', (Math.round(o.hit_rate * 10) / 10) + '%', '');
			return html;
		},
	});

	wireCheckBtn({
		btnId:    'sh-weather-btn',
		resultId: 'sh-weather-result',
		action:   'serverhealth_weather_refresh',
		label:    'Refresh Weather',
		render:   function(d) {
			if (!d.weather) return '';
			var w = d.weather;
			var html = '';
			html += renderFsRow('Parks refreshed', fmtCount(w.count), '');
			html += renderFsRow('Refresh duration', w.elapsed_ms + ' ms', '');
			if (w.previous_fetched_at) {
				html += renderFsRow('Previous fetch', w.previous_fetched_at + ' (' + w.previous_age_min + ' min ago)', '');
			}
			return html;
		},
	});

	// Clear button — empties every result div in the Optional Checks panel
	// so the screen isn't permanently cluttered with the last run's output.
	document.getElementById('sh-clear-btn').addEventListener('click', function() {
		['sh-fs-result', 'sh-memcache-result', 'sh-opcache-result', 'sh-weather-result', 'sh-wxstats-result']
			.forEach(function(id) { var el = document.getElementById(id); if (el) el.innerHTML = ''; });
	});

	wireCheckBtn({
		btnId:    'sh-wxstats-btn',
		resultId: 'sh-wxstats-result',
		action:   'serverhealth_weather_stats',
		label:    'Weather API Stats',
		render:   function(d) {
			if (!d.wx) return '';
			var w = d.wx;
			var html = '';
			if (w.cooldown_set_at) {
				html += renderFsRow('Cooldown active', 'set ' + w.cooldown_set_at + ' — clears ' + w.cooldown_clears_at, 'warn');
				// Diagnostic: per-clock remaining seconds. If server says
				// "overdue" but the key is still present, memcache isn't
				// TTL-evicting and we have a stuck key. A non-zero clock
				// skew points at a memcached/web clock mismatch instead.
				var srvLbl, srvCls;
				if (w.remaining_seconds_server === null) {
					srvLbl = '—'; srvCls = '';
				} else if (w.remaining_seconds_server >= 0) {
					srvLbl = w.remaining_seconds_server + 's remaining';
					srvCls = '';
				} else {
					srvLbl = (-w.remaining_seconds_server) + 's overdue (key not evicted)';
					srvCls = 'warn';
				}
				html += renderFsRow('Server clock view', srvLbl, srvCls);
				if (w.memcache_time) {
					var mcLbl;
					if (w.remaining_seconds_memcache === null) {
						mcLbl = '—';
					} else if (w.remaining_seconds_memcache >= 0) {
						mcLbl = w.remaining_seconds_memcache + 's remaining';
					} else {
						mcLbl = (-w.remaining_seconds_memcache) + 's overdue (key not evicted)';
					}
					html += renderFsRow('Memcache clock view', mcLbl + '  (mc time: ' + w.memcache_time + ', skew ' + w.clock_skew_seconds + 's)', '');
				}
			} else if (w.cooldown_present) {
				html += renderFsRow('Cooldown', 'key present but empty value (corrupt)', 'warn');
			} else {
				html += renderFsRow('Cooldown', 'inactive', '');
			}
			(w.days || []).forEach(function(day, i) {
				var label = (i === 0 ? 'Today (' : (i === 1 ? 'Yesterday (' : (i + ' days ago ('))) + day.date + ' UTC)';
				var cls = day.rate_limited > 0 ? 'warn' : '';
				// Per-endpoint touch count = real attempts + cooldown-blocked
				// touches. Without including blocks, the (fc/ar) split shows
				// zero even when something tried to call forecast and got
				// gated, which is misleading.
				var fcTouches = day.attempt_forecast + day.blocked_forecast;
				var arTouches = day.attempt_archive  + day.blocked_archive;
				var detail = day.attempt + ' attempts'
					+ ' · ' + day.success + ' ok'
					+ ' · ' + day.rate_limited + ' 429'
					+ ' · ' + day.error + ' err'
					+ ' · ' + day.blocked + ' blocked'
					+ '  (fc: ' + fcTouches + ', ar: ' + arTouches + ')';
				html += renderFsRow(label, detail, cls);
			});
			return html;
		},
	});

<?php if (!empty($ShowLoadTest)): ?>
	/* ── Load tester (dev only) ─────────────────────── */
	var ltRunning = false;
	var ltResults = [];
	var ltErrors  = 0;
	var ltTotal   = 0;

	document.getElementById('sh-lt-concurrency').addEventListener('input', function() {
		document.getElementById('sh-lt-conc-val').textContent = this.value;
	});

	document.getElementById('sh-lt-start').addEventListener('click', function() {
		if (ltRunning) {
			ltRunning = false;
			this.textContent = '▶ Start';
			this.className = 'sh-lt-btn sh-lt-btn-start';
			ltLog('— Stopped —');
			return;
		}
		ltRunning = true;
		ltResults = []; ltErrors = 0; ltTotal = 0;
		this.textContent = '⏹ Stop';
		this.className = 'sh-lt-btn sh-lt-btn-stop';
		document.getElementById('sh-lt-log').style.display = '';
		ltLog('Starting...');

		var target      = UIR + document.getElementById('sh-lt-target').value;
		var concurrency = parseInt(document.getElementById('sh-lt-concurrency').value, 10);

		function worker() {
			if (!ltRunning) return;
			var start = Date.now();
			fetch(target, { credentials: 'same-origin', cache: 'no-store' })
				.then(function(r) {
					var ms = Date.now() - start;
					ltResults.push({ ts: start, ms: ms, err: !r.ok });
					if (!r.ok) ltErrors++;
					ltTotal++;
					updateLtStats();
					worker();
				})
				.catch(function() {
					ltErrors++; ltTotal++;
					ltResults.push({ ts: Date.now(), ms: 0, err: true });
					updateLtStats();
					worker();
				});
		}
		for (var i = 0; i < concurrency; i++) worker();
	});

	function updateLtStats() {
		var now    = Date.now();
		var win    = ltResults.filter(function(r) { return now - r.ts < 5000 && !r.err; });
		var rps    = win.length > 0 ? (win.length / Math.min(5, (now - ltResults[0].ts) / 1000)).toFixed(1) : '—';
		var times  = win.map(function(r) { return r.ms; }).sort(function(a,b){return a-b;});
		var avg    = times.length ? Math.round(times.reduce(function(s,v){return s+v;},0) / times.length) : '—';
		var p95    = times.length ? times[Math.floor(times.length * 0.95)] : '—';
		document.getElementById('sh-lt-rps').textContent    = rps;
		document.getElementById('sh-lt-avg').textContent    = avg === '—' ? '—' : avg + ' ms';
		document.getElementById('sh-lt-p95').textContent    = p95 === '—' ? '—' : p95 + ' ms';
		document.getElementById('sh-lt-total').textContent  = ltTotal;
		document.getElementById('sh-lt-errors').textContent = ltErrors;
		if (ltTotal % 10 === 0 && avg !== '—') {
			ltLog('req=' + ltTotal + '  rps=' + rps + '  avg=' + avg + 'ms  p95=' + (p95==='—'?'—':p95+'ms') + '  err=' + ltErrors);
		}
	}

	function ltLog(msg) {
		var el = document.getElementById('sh-lt-log');
		el.textContent += new Date().toLocaleTimeString() + '  ' + msg + '\n';
		el.scrollTop = el.scrollHeight;
	}
<?php endif; ?>
})();
</script>
