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
.sh-metric-label { color: var(--rp-text-secondary, #6b7280); }
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

/* Load test */
.sh-lt-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
.sh-lt-group { display: flex; flex-direction: column; gap: 3px; }
.sh-lt-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted, #9ca3af); }
.sh-lt-select, .sh-lt-input {
	padding: 6px 10px;
	border: 1px solid var(--rp-border, #d1d5db);
	border-radius: 6px;
	font-size: 0.84rem;
	color: var(--rp-text, #111827);
	background: var(--rp-card-bg, #fff);
}
.sh-lt-slider-row { display: flex; align-items: center; gap: 8px; }
.sh-lt-slider { width: 120px; }
.sh-lt-slider-val { font-weight: 700; min-width: 2ch; font-variant-numeric: tabular-nums; }
.sh-lt-btn {
	padding: 7px 20px;
	border: none;
	border-radius: 6px;
	font-size: 0.85rem;
	font-weight: 700;
	cursor: pointer;
}
.sh-lt-btn-start { background: #4338ca; color: #fff; }
.sh-lt-btn-start:hover { background: #3730a3; }
.sh-lt-btn-stop  { background: #dc2626; color: #fff; }
.sh-lt-btn-stop:hover  { background: #b91c1c; }
.sh-lt-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
.sh-lt-stat { background: var(--rp-bg-secondary, #f9fafb); border: 1px solid var(--rp-border, #e5e7eb); border-radius: 8px; padding: 10px 12px; text-align: center; }
.sh-lt-stat-val { font-size: 1.4rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--rp-text, #111827); }
.sh-lt-stat-label { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted, #9ca3af); margin-top: 2px; }
.sh-lt-log { margin-top: 12px; max-height: 120px; overflow-y: auto; font-size: 0.75rem; font-family: monospace; color: var(--rp-text-secondary, #374151); background: var(--rp-bg-secondary, #f9fafb); border: 1px solid var(--rp-border, #e5e7eb); border-radius: 6px; padding: 8px 10px; }

/* Status indicator */
.sh-status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; }
.sh-status-dot.live { background: #16a34a; animation: sh-pulse 2s infinite; }
@keyframes sh-pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
.sh-last-updated { font-size: 0.72rem; color: var(--rp-text-muted, #9ca3af); margin-left: auto; }
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

	<!-- Load Test Panel -->
	<div class="sh-panel">
		<div class="sh-panel-hdr"><i class="fas fa-tachometer-alt"></i> Load Test</div>
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

</div><!-- /rp-root -->

<script>
(function() {
	var UIR = '<?=UIR?>';
	var prevQuestions = null;
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
			var lbl = i < active ? 'A' : 'I';
			wHtml += '<div class="sh-worker ' + cls + '" title="Worker ' + (i+1) + ': ' + (i < active ? 'Active' : 'Idle') + '">' + lbl + '</div>';
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
		var questions  = parseInt(db['Questions'] || 0, 10);
		var qps        = '—';
		if (prevQuestions !== null && prevPollTime !== null) {
			var dt = (now - prevPollTime) / 1000;
			if (dt > 0) qps = ((questions - prevQuestions) / dt).toFixed(1);
		}
		prevQuestions = questions;
		prevPollTime  = now;

		var running   = parseInt(db['Threads_running']    || 0, 10);
		var connected = parseInt(db['Threads_connected']  || 0, 10);
		var slow      = parseInt(db['Slow_queries']       || 0, 10);
		var maxConn   = parseInt(db['Max_used_connections'] || 0, 10);
		var uptime    = parseInt(db['Uptime'] || 0, 10);
		var h = Math.floor(uptime/3600), m = Math.floor((uptime%3600)/60);
		var uptimeStr = h + 'h ' + m + 'm';

		document.getElementById('sh-db-metrics').innerHTML =
			metric('Queries / sec', qps, '') +
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

	/* ── Load tester ────────────────────────────────── */
	var ltRunning  = false;
	var ltResults  = [];   // {ts, ms, err}
	var ltErrors   = 0;
	var ltTotal    = 0;

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

		var target  = UIR + document.getElementById('sh-lt-target').value;
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
		var window = ltResults.filter(function(r) { return now - r.ts < 5000 && !r.err; });
		var rps    = window.length > 0 ? (window.length / Math.min(5, (now - ltResults[0].ts) / 1000)).toFixed(1) : '—';
		var times  = window.map(function(r) { return r.ms; }).sort(function(a,b){return a-b;});
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
})();
</script>
