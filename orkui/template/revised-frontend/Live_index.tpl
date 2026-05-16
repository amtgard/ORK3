<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>

<style>
/* ============ Live attendance ============ */
.lv-layout {
	display: grid;
	grid-template-columns: 1fr 360px;
	grid-template-rows: auto 1fr auto;
	height: 100vh; /* JS overrides at runtime to (innerHeight - layout.top) so it never spills below */
	background: var(--ork-bg);
	color: var(--ork-text);
	overflow: hidden;
}
.lv-header {
	grid-column: 1 / 3;
	display: flex; align-items: center; gap: 24px;
	padding: 14px 20px;
	background: var(--ork-card-bg);
	border-bottom: 1px solid var(--ork-border);
}
.lv-header h1 { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: .02em; background: none; border: none; padding: 0; border-radius: 0; color: inherit; text-shadow: none; box-shadow: none; }
.lv-status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #48bb78; margin-right: 4px; animation: lv-pulse 2s ease-in-out infinite; }
@keyframes lv-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }
.lv-subhead { display: flex; gap: 16px; font-size: 12px; color: var(--ork-text-muted); }
.lv-subhead b { color: var(--ork-text); font-weight: 600; }
.lv-headline { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.lv-headline .lv-big { font-size: 36px; font-weight: 800; color: #48bb78; line-height: 1; }
.lv-headline .lv-label { font-size: 11px; color: var(--ork-text-muted); text-transform: uppercase; letter-spacing: .1em; line-height: 1.3; }
.lv-help-btn { background: var(--ork-bg-secondary); border: 1px solid var(--ork-border); color: var(--ork-text-muted); width: 26px; height: 26px; border-radius: 50%; cursor: pointer; font-size: 13px; line-height: 1; padding: 0; }
.lv-help-btn:hover { color: var(--ork-text); }
.lv-help-pop {
	position: absolute; top: 110px; right: 24px; z-index: 2000;
	background: var(--ork-card-bg); border: 1px solid var(--ork-border); border-radius: 6px;
	box-shadow: 0 4px 14px rgba(0,0,0,0.25); padding: 12px 14px; min-width: 260px; display: none;
	font-size: 12px;
}
.lv-help-pop.open { display: block; }
.lv-help-pop h4 { margin: 0 0 8px 0; font-size: 12px; color: var(--ork-text-muted); text-transform: uppercase; letter-spacing: .08em; }
.lv-help-pop dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; }
.lv-help-pop dt { font-family: 'SF Mono', Menlo, monospace; background: var(--ork-bg-secondary); padding: 2px 6px; border-radius: 3px; border: 1px solid var(--ork-border); font-size: 11px; }
.lv-help-pop dd { margin: 0; align-self: center; color: var(--ork-text); }

#lv-map { background: var(--ork-bg-tertiary); }
.lv-aside {
	background: var(--ork-card-bg); border-left: 1px solid var(--ork-border);
	display: flex; flex-direction: column; min-height: 0;
}
.lv-sb-section { padding: 10px 12px; border-bottom: 1px solid var(--ork-border); position: relative; }
.lv-search-box { position: relative; background: var(--ork-bg-secondary); border: 1px solid var(--ork-border); border-radius: 6px; }
.lv-search-box input { width: 100%; padding: 8px 32px 8px 12px; background: transparent; color: var(--ork-text); border: 0; outline: 0; font-size: 13px; font-family: inherit; }
.lv-search-box .lv-search-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--ork-text-muted); font-size: 13px; pointer-events: none; }
.lv-search-box kbd { position: absolute; right: 32px; top: 50%; transform: translateY(-50%); color: var(--ork-text-muted); font-size: 10px; background: var(--ork-bg); padding: 1px 5px; border: 1px solid var(--ork-border); border-radius: 3px; pointer-events: none; }
.lv-search-results {
	position: absolute; top: 100%; left: 12px; right: 12px; z-index: 50;
	background: var(--ork-card-bg); border: 1px solid var(--ork-link-bright); border-radius: 6px;
	box-shadow: 0 8px 24px rgba(0,0,0,0.25), 0 0 0 1px rgba(66,153,225,0.15);
	max-height: 280px; overflow-y: auto; display: none; margin-top: 6px;
}
.lv-search-results.open { display: block; }
.lv-search-result { padding: 9px 12px 9px 16px; cursor: pointer; font-size: 13px; border-bottom: 1px solid var(--ork-border); border-left: 3px solid transparent; transition: background .1s, border-left-color .1s; }
.lv-search-result:last-child { border-bottom: 0; }
.lv-search-result:hover { background: var(--ork-bg-secondary); }
.lv-search-result.kbd-active { background: var(--ork-bg-tertiary); border-left-color: var(--ork-link-bright); }
.lv-search-result .lv-sr-name { font-weight: 600; color: var(--ork-text); }
.lv-search-result .lv-sr-loc { font-size: 11px; color: var(--ork-text-muted); margin-top: 2px; }

.lv-park-info { display: none; }
.lv-park-info.open { display: block; }
.lv-pi-head { display: flex; align-items: flex-start; gap: 6px; margin-bottom: 8px; }
.lv-pi-head h3 { margin: 0; font-size: 14px; flex: 1; color: var(--ork-text); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.lv-pi-actions { display: flex; gap: 4px; }
.lv-pi-btn { background: var(--ork-bg-secondary); border: 1px solid var(--ork-border); color: var(--ork-text-muted); cursor: pointer; padding: 3px 7px; font-size: 12px; border-radius: 4px; text-decoration: none; line-height: 1; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; }
.lv-pi-btn:hover { background: var(--ork-bg-tertiary); color: var(--ork-text); }
.lv-pi-key { background: var(--ork-bg); border: 1px solid var(--ork-border); color: var(--ork-text-muted); padding: 1px 5px; border-radius: 3px; font-size: 10px; font-family: 'SF Mono', Menlo, monospace; line-height: 1.2; }
.lv-pi-loc { font-size: 11px; color: var(--ork-text-muted); margin-bottom: 10px; }
.lv-pi-stats { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
.lv-pi-stat { text-align: center; padding: 6px 4px; background: var(--ork-bg-secondary); border-radius: 4px; }
.lv-pi-stat .lv-num { font-size: 20px; font-weight: 700; color: #48bb78; line-height: 1; }
.lv-pi-stat .lv-lbl { font-size: 9px; color: var(--ork-text-muted); text-transform: uppercase; letter-spacing: .06em; margin-top: 4px; }

.lv-ticker-head { padding: 12px 16px; border-bottom: 1px solid var(--ork-border); font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--ork-text-muted); display: flex; justify-content: space-between; align-items: center; }
.lv-ticker { flex: 1 1 auto; overflow-y: auto; padding: 8px 0; }
.lv-t-row { display: flex; align-items: baseline; gap: 10px; padding: 7px 16px; font-size: 13px; border-bottom: 1px solid var(--ork-border); }
.lv-t-row.first-ever { background: linear-gradient(90deg, rgba(246,224,94,0.15), transparent); }
.lv-t-row .lv-t-time { color: var(--ork-text-muted); font-size: 11px; font-variant-numeric: tabular-nums; min-width: 56px; }
.lv-t-row .lv-t-msg { flex: 1; color: var(--ork-text); }
.lv-t-row .lv-t-park { color: var(--ork-link-bright); font-weight: 600; cursor: pointer; }
.lv-t-row .lv-t-park:hover { text-decoration: underline; }
.lv-t-row .lv-t-celebrate { color: #d69e2e; font-weight: 700; }
.lv-t-row.enter { animation: lv-enter .35s ease; }
@keyframes lv-enter { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }

.lv-legend { font-size: 11px; color: var(--ork-text-muted); padding: 8px 16px; border-top: 1px solid var(--ork-border); }
.lv-legend .lv-sw { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin: 0 4px 0 12px; vertical-align: middle; }
.lv-legend .lv-sw.s1 { background: rgba(72,187,120,0.85); }
.lv-legend .lv-sw.s2 { background: rgba(72,187,120,0.45); }
.lv-legend .lv-sw.s3 { background: rgba(72,187,120,0.18); }
.lv-legend .lv-sw.ev { background: #ed8936; }

.lv-toast-wrap { position: fixed; top: 110px; left: 50%; transform: translateX(-50%); z-index: 10000; display: flex; flex-direction: column; gap: 8px; align-items: center; pointer-events: none; }
.lv-toast { background: linear-gradient(135deg, #d69e2e, #f6e05e); color: #1a202c; font-weight: 700; padding: 10px 18px; border-radius: 24px; box-shadow: 0 4px 24px rgba(246,224,94,0.4); animation: lv-pop 4s forwards; }
@keyframes lv-pop { 0% { opacity: 0; transform: scale(0.7); } 10% { opacity: 1; transform: scale(1.05); } 20% { transform: scale(1); } 80% { opacity: 1; transform: scale(1); } 100% { opacity: 0; transform: scale(0.95) translateY(-8px); } }

@media (max-width: 768px) {
	.lv-layout { grid-template-columns: 1fr; }
	.lv-aside { border-left: 0; border-top: 1px solid var(--ork-border); max-height: 50vh; }
}
</style>

<div class="lv-layout">
	<div class="lv-header">
		<h1><span class="lv-status-dot"></span> Live Attendance</h1>
		<div class="lv-subhead">
			<span>past <b>24h</b></span>
			<span><b id="lv-park-count">—</b> parks</span>
			<span><b id="lv-signin-count">—</b> sign-ins</span>
		</div>
		<div class="lv-headline">
			<div class="lv-big" id="lv-active-now">0</div>
			<div class="lv-label">active right now<br>(past 3h)</div>
		</div>
		<button class="lv-help-btn" id="lv-help-btn" title="Keyboard shortcuts">?</button>
	</div>
	<div class="lv-help-pop" id="lv-help-pop">
		<h4>Keyboard shortcuts</h4>
		<dl>
			<dt>/</dt>     <dd>Focus search</dd>
			<dt>↑ ↓</dt>   <dd>Navigate search results</dd>
			<dt>Enter</dt> <dd>Pick highlighted result</dd>
			<dt>Esc</dt>   <dd>Close search · close info · fit map</dd>
		</dl>
	</div>

	<div id="lv-map"></div>

	<aside class="lv-aside">
		<div class="lv-sb-section">
			<div class="lv-search-box">
				<input type="text" id="lv-search" placeholder="Search park or event…" autocomplete="off">
				<kbd>/</kbd>
				<span class="lv-search-icon">🔍</span>
			</div>
			<div class="lv-search-results" id="lv-search-results"></div>
		</div>
		<div class="lv-sb-section lv-park-info" id="lv-park-info">
			<div class="lv-pi-head">
				<h3 id="lv-pi-name">Park name</h3>
				<div class="lv-pi-actions">
					<a class="lv-pi-btn" id="lv-pi-external" href="#" target="_blank" rel="noopener" title="Open in ORK">↗</a>
					<button class="lv-pi-btn" id="lv-pi-close" title="Close"><kbd class="lv-pi-key">Esc</kbd> ×</button>
				</div>
			</div>
			<div class="lv-pi-loc" id="lv-pi-loc">Kingdom · City</div>
			<div class="lv-pi-stats">
				<div class="lv-pi-stat"><div class="lv-num" id="lv-pi-day">0</div><div class="lv-lbl">past 24h</div></div>
				<div class="lv-pi-stat"><div class="lv-num" id="lv-pi-3h">0</div><div class="lv-lbl">past 3h</div></div>
				<div class="lv-pi-stat"><div class="lv-num" id="lv-pi-30m">0</div><div class="lv-lbl">past 30m</div></div>
			</div>
		</div>
		<div class="lv-ticker-head">
			<span>Live ticker</span>
			<span id="lv-ticker-count">0 events</span>
		</div>
		<div class="lv-ticker" id="lv-ticker"></div>
		<div class="lv-legend">
			Rings: <span class="lv-sw s1"></span>past 30m
			<span class="lv-sw s2"></span>past 3h
			<span class="lv-sw s3"></span>past 24h
			<span style="margin-left:12px;"><span class="lv-sw ev"></span>📅 event</span>
		</div>
	</aside>
</div>

<div class="lv-toast-wrap" id="lv-toasts"></div>

<script>
(function() {
	// Wait for Leaflet (loaded deferred from CDN)
	function whenReady(fn) {
		if (window.L) return fn();
		setTimeout(() => whenReady(fn), 50);
	}

	const STATS_POLL_MS  = 30000;
	const RECENT_POLL_MS = 10000;
	const ORK_BASE       = '<?= UIR ?>';
	const TIER_ICON = {
		'Outpost': '⛺', 'Freehold': '⛺', 'Burg': '⛺',
		'Shire':   '🏘️',
		'Barony':  '🏰',
		'Duchy':   '🏯',
		'Grand Duchy': '👑', 'Kingdom': '👑'
	};

	// In-memory state
	let parkLayers   = {};   // park_id → { outer, middle, inner, dayCount, h3Count, m30Count, meta }
	let eventLayers  = {};   // event_id → same shape (only when coord_source != 'none')
	let eventsMeta   = {};   // event_id → { name, kingdom, coord_source, lat, lng, calendar_detail_id }
	let parksMeta    = {};   // park_id → { name, kingdom, city, province, title, tier, lat, lng }
	let lastSigninTs = '';   // most recent entered_at we've shown (dedup ticker polls)
	let map = null;
	let initialBounds = null;
	let focusedKind = null, focusedId = null;

	const ticker        = document.getElementById('lv-ticker');
	const tickerCount   = document.getElementById('lv-ticker-count');
	const activeNowEl   = document.getElementById('lv-active-now');
	const parkCountEl   = document.getElementById('lv-park-count');
	const signinCountEl = document.getElementById('lv-signin-count');
	const piPanel       = document.getElementById('lv-park-info');
	const piName        = document.getElementById('lv-pi-name');
	const piLoc         = document.getElementById('lv-pi-loc');
	const piDay         = document.getElementById('lv-pi-day');
	const pi3h          = document.getElementById('lv-pi-3h');
	const pi30m         = document.getElementById('lv-pi-30m');
	const piExternal    = document.getElementById('lv-pi-external');
	const toasts        = document.getElementById('lv-toasts');
	const searchInput   = document.getElementById('lv-search');
	const searchResults = document.getElementById('lv-search-results');
	const helpBtn       = document.getElementById('lv-help-btn');
	const helpPop       = document.getElementById('lv-help-pop');

	function sizeOf(n) {
		if (n === 0) return 0;
		if (!map) return 10;
		const z = map.getZoom();
		const zoomScale = Math.min(1.0, 0.55 + (z - 4) * 0.07);
		return (8 + Math.log2(n + 1) * 4.5) * zoomScale;
	}

	// Walks ticker rows that fell back to "Park #N" / generic event labels
	// (because they rendered before metadata arrived) and rewrites them.
	function relabelUnresolvedTicker() {
		const rows = ticker.querySelectorAll('.lv-t-row .lv-t-park');
		rows.forEach(span => {
			const pid = span.dataset.pid;
			const eid = span.dataset.eid;
			let realName = null, realIcon = null;
			if (pid && parksMeta[pid]) {
				realName = parksMeta[pid].name;
				realIcon = TIER_ICON[parksMeta[pid].title] || '📍';
			} else if (eid && eventsMeta[eid]) {
				realName = eventsMeta[eid].name;
				realIcon = '📅';
			}
			if (!realName || span.textContent === realName) return;
			span.textContent = realName;
			const row = span.closest('.lv-t-row');
			const iconEl = row && row.querySelector('.lv-t-icon');
			if (iconEl) iconEl.textContent = realIcon;
		});
	}

	function fmtClockShort(iso) {
		const d = new Date(iso);
		return isNaN(d.getTime()) ? '' : d.toLocaleTimeString(undefined, { hour12: false, hour: '2-digit', minute: '2-digit' });
	}

	// Tile layer picks the right Carto style based on the current data-theme
	// attribute. The default.theme bootstrap script sets data-theme="dark" or
	// "light" before our script runs (handling system pref as well).
	let tileLayer = null;
	function isDarkTheme() {
		return document.documentElement.getAttribute('data-theme') === 'dark';
	}
	function applyMapTheme() {
		if (!map) return;
		const url = isDarkTheme()
			? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
			: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
		if (tileLayer) map.removeLayer(tileLayer);
		tileLayer = L.tileLayer(url, { maxZoom: 19, subdomains: 'abcd' }).addTo(map);
	}

	// Fit the layout exactly between the top nav and the viewport bottom —
	// avoids guessing nav height and prevents the legend slipping below.
	const layoutEl = document.querySelector('.lv-layout');
	function fitLayout() {
		const top = layoutEl.getBoundingClientRect().top + window.scrollY;
		layoutEl.style.height = (window.innerHeight - top) + 'px';
		if (map) setTimeout(() => map.invalidateSize(), 50);
	}
	fitLayout();
	window.addEventListener('resize', fitLayout);

	whenReady(() => {
		map = L.map('lv-map', { zoomControl: true, attributionControl: false }).setView([39.5, -98.35], 4);
		fitLayout();
		applyMapTheme();
		map.on('zoomend', applyCircles);

		// Swap tiles when user toggles the theme via the nav button
		new MutationObserver(applyMapTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

		// Sequence first poll: stats first so parksMeta/eventsMeta is populated
		// BEFORE we render the recent backlog (otherwise rows show as "Park #N").
		pollStats().then(() => pollRecent());
		setInterval(pollStats, STATS_POLL_MS);
		setInterval(pollRecent, RECENT_POLL_MS);
	});

	// --- Stats poll ----------------------------------------------------
	async function pollStats() {
		try {
			const r = await fetch(ORK_BASE + 'Live/stats', { credentials: 'same-origin' });
			const d = await r.json();
			if (d.status !== 0) return;
			parksMeta  = d.parks  || {};
			eventsMeta = d.events || {};
			activeNowEl.textContent = (d.active_3h || 0).toLocaleString();
			rebuildMapLayers(d);
			parkCountEl.textContent = Object.keys(parksMeta).length;
			signinCountEl.textContent = Object.values(parksMeta).reduce((s, p) => s + (p.day || 0), 0) +
			                            Object.values(eventsMeta).reduce((s, e) => s + (e.day || 0), 0);
			refreshFocusedStats();
			relabelUnresolvedTicker();
		} catch (e) {
			console.error('Live/stats poll failed', e);
		}
	}

	function rebuildMapLayers(d) {
		const fresh = {};
		// Parks
		for (const pid in (d.parks || {})) {
			const p = d.parks[pid];
			if (!p.lat || !p.lng) continue;
			let pl = parkLayers[pid];
			if (!pl) {
				const center = [p.lat, p.lng];
				const outer  = L.circleMarker(center, { radius: 0, color: 'rgba(72,187,120,0.5)', weight: 1, fillColor: '#48bb78', fillOpacity: 0.12 }).addTo(map);
				const middle = L.circleMarker(center, { radius: 0, color: 'rgba(72,187,120,0.8)', weight: 1, fillColor: '#48bb78', fillOpacity: 0.30 }).addTo(map);
				const inner  = L.circleMarker(center, { radius: 0, color: '#48bb78',              weight: 1, fillColor: '#48bb78', fillOpacity: 0.85 }).addTo(map);
				inner.bindTooltip(p.name, { direction: 'top', opacity: 0.9 });
				[outer, middle, inner].forEach(layer => layer.on('click', () => openParkInfo(pid)));
				pl = { outer, middle, inner };
			}
			pl.dayCount = p.day || 0;
			pl.h3Count  = p.h3  || 0;
			pl.m30Count = p.m30 || 0;
			fresh[pid] = pl;
		}
		// Drop layers no longer present
		for (const pid in parkLayers) {
			if (!fresh[pid]) {
				map.removeLayer(parkLayers[pid].outer);
				map.removeLayer(parkLayers[pid].middle);
				map.removeLayer(parkLayers[pid].inner);
			}
		}
		parkLayers = fresh;

		// Events
		const eFresh = {};
		for (const eid in (d.events || {})) {
			const em = d.events[eid];
			if (em.coord_source === 'none' || !em.lat || !em.lng) continue;
			let eb = eventLayers[eid];
			if (!eb) {
				const center = [em.lat, em.lng];
				const outer  = L.circleMarker(center, { radius: 0, color: 'rgba(237,137,54,0.5)', weight: 1, fillColor: '#ed8936', fillOpacity: 0.12 }).addTo(map);
				const middle = L.circleMarker(center, { radius: 0, color: 'rgba(237,137,54,0.8)', weight: 1, fillColor: '#ed8936', fillOpacity: 0.30 }).addTo(map);
				const inner  = L.circleMarker(center, { radius: 0, color: '#ed8936',              weight: 1, fillColor: '#ed8936', fillOpacity: 0.85 }).addTo(map);
				inner.bindTooltip('📅 ' + em.name + (em.coord_source === 'at_park' ? ' (host park)' : ''), { direction: 'top', opacity: 0.9 });
				[outer, middle, inner].forEach(layer => layer.on('click', () => openEventInfo(eid)));
				eb = { outer, middle, inner };
			}
			eb.dayCount = em.day || 0;
			eb.h3Count  = em.h3  || 0;
			eb.m30Count = em.m30 || 0;
			eFresh[eid] = eb;
		}
		for (const eid in eventLayers) {
			if (!eFresh[eid]) {
				map.removeLayer(eventLayers[eid].outer);
				map.removeLayer(eventLayers[eid].middle);
				map.removeLayer(eventLayers[eid].inner);
			}
		}
		eventLayers = eFresh;

		// Fit bounds once on first stats with data
		if (!initialBounds) {
			const lats = [], lngs = [];
			for (const pid in parksMeta)  { const p = parksMeta[pid];  if (p.lat && p.lng) { lats.push(p.lat); lngs.push(p.lng); } }
			for (const eid in eventsMeta) { const e = eventsMeta[eid]; if (e.lat && e.lng && e.coord_source !== 'none') { lats.push(e.lat); lngs.push(e.lng); } }
			if (lats.length > 0) {
				initialBounds = [[Math.min(...lats), Math.min(...lngs)], [Math.max(...lats), Math.max(...lngs)]];
				map.fitBounds(initialBounds, { padding: [40, 40] });
			}
		}
		applyCircles();
	}

	function applyCircles() {
		for (const pid in parkLayers) {
			const pl = parkLayers[pid];
			pl.outer.setRadius(sizeOf(pl.dayCount));
			pl.middle.setRadius(sizeOf(pl.h3Count));
			pl.inner.setRadius(sizeOf(pl.m30Count));
		}
		for (const eid in eventLayers) {
			const eb = eventLayers[eid];
			eb.outer.setRadius(sizeOf(eb.dayCount));
			eb.middle.setRadius(sizeOf(eb.h3Count));
			eb.inner.setRadius(sizeOf(eb.m30Count));
		}
	}

	// --- Recent poll w/ jitter-buffered ticker --------------------------
	// First poll: render the backlog immediately (no animation) so the user
	// arrives at a populated ticker, not an empty one that floods over 10s.
	// Subsequent polls: only the genuinely new signins flow through the
	// jitter buffer, paced over RECENT_POLL_MS so the feed feels alive.
	let pendingSignins = [];
	let drainTimer = null;
	let firstRecentPoll = true;

	async function pollRecent() {
		try {
			const r = await fetch(ORK_BASE + 'Live/recent', { credentials: 'same-origin' });
			const d = await r.json();
			if (d.status !== 0) return;
			const signins = d.signins || [];
			if (signins.length === 0) return;

			if (firstRecentPoll) {
				// Newest-first from server; render oldest-first so newest ends up on top
				for (let i = signins.length - 1; i >= 0; i--) {
					pushTicker(signins[i], { animate: false });
				}
				lastSigninTs = signins[0][0];
				firstRecentPoll = false;
				return;
			}

			const fresh = [];
			for (let i = signins.length - 1; i >= 0; i--) {
				if (signins[i][0] > lastSigninTs) fresh.push(signins[i]);
			}
			if (fresh.length === 0) return;
			lastSigninTs = signins[0][0];

			// At low arrival rates, just push them in; no point pacing 1-2 events.
			if (fresh.length <= 2) {
				fresh.forEach(s => pushTicker(s));
				return;
			}
			fresh.forEach(s => pendingSignins.push(s));
			startDrain();
		} catch (e) {
			console.error('Live/recent poll failed', e);
		}
	}

	function startDrain() {
		if (drainTimer) return;
		const tick = () => {
			if (pendingSignins.length === 0) { drainTimer = null; return; }
			const batch = pendingSignins.length;
			const interval = Math.max(200, Math.floor((RECENT_POLL_MS - 1000) / Math.max(1, batch)));
			const s = pendingSignins.shift();
			pushTicker(s);
			drainTimer = setTimeout(tick, interval);
		};
		tick();
	}

	function pushTicker(s, opts) {
		const animate = !opts || opts.animate !== false;
		const [iso, pid, eid, cdid, isFirst] = s;
		const isEvent = (pid === 0 && eid && eventsMeta[eid]);
		const pname  = isEvent ? eventsMeta[eid].name : (parksMeta[pid] ? parksMeta[pid].name : ('Park #' + pid));
		const icon   = isEvent ? '📅' : (parksMeta[pid] ? (TIER_ICON[parksMeta[pid].title] || '📍') : '📍');
		const linkAttrs = isEvent
			? `class="lv-t-park" data-eid="${eid}" data-cdid="${cdid || ''}"`
			: `class="lv-t-park" data-pid="${pid}"`;
		const iconSpan = `<span class="lv-t-icon">${icon}</span>`;
		const target = `<span ${linkAttrs}>${pname}</span>`;
		const row = document.createElement('div');
		row.className = 'lv-t-row' + (animate ? ' enter' : '') + (isFirst ? ' first-ever' : '');
		const timeStr = fmtClockShort(iso);
		if (isFirst) {
			row.innerHTML = `<span class="lv-t-time">${timeStr}</span><span class="lv-t-msg"><span class="lv-t-celebrate">🎉 First sign-in ever at ${iconSpan} ${target}!</span></span>`;
			if (animate) {
				const toast = document.createElement('div');
				toast.className = 'lv-toast';
				toast.textContent = `🎉 First sign-in ever at ${pname}!`;
				toasts.appendChild(toast);
				setTimeout(() => toast.remove(), 4000);
			}
		} else {
			row.innerHTML = `<span class="lv-t-time">${timeStr}</span><span class="lv-t-msg">${iconSpan} ${target}</span>`;
		}
		ticker.insertBefore(row, ticker.firstChild);
		while (ticker.childNodes.length > 80) ticker.removeChild(ticker.lastChild);
		tickerCount.textContent = ticker.childNodes.length + ' shown';
	}

	// --- Park / Event info card ----------------------------------------
	function openParkInfo(pid) {
		const p = parksMeta[pid]; if (!p) return;
		focusedKind = 'park'; focusedId = String(pid);
		const icon = TIER_ICON[p.title] || '📍';
		piName.textContent = `${icon} ${p.name}`;
		piLoc.textContent = [p.title, p.kingdom, [p.city, p.province].filter(Boolean).join(', ')].filter(Boolean).join(' · ') || '—';
		piExternal.href = ORK_BASE + 'Park/index/' + pid;
		piExternal.style.display = '';
		refreshFocusedStats();
		piPanel.classList.add('open');
		if (p.lat && p.lng) {
			const z = Math.max(map.getZoom(), 6);
			map.flyTo([p.lat, p.lng], z, { duration: 0.6 });
		}
	}
	function openEventInfo(eid, cdid) {
		const em = eventsMeta[eid]; if (!em) return;
		focusedKind = 'event'; focusedId = String(eid);
		piName.textContent = '📅 ' + em.name;
		piLoc.textContent = em.kingdom || '—';
		const effectiveCdid = cdid || em.calendar_detail_id;
		piExternal.href = ORK_BASE + 'Event/detail/' + eid + (effectiveCdid ? '/' + effectiveCdid : '');
		piExternal.style.display = '';
		refreshFocusedStats();
		piPanel.classList.add('open');
		if (em.lat && em.lng && em.coord_source !== 'none') {
			const z = Math.max(map.getZoom(), 6);
			map.flyTo([em.lat, em.lng], z, { duration: 0.6 });
		}
	}
	function refreshFocusedStats() {
		if (!focusedId) return;
		const src = focusedKind === 'park' ? parksMeta[focusedId] : eventsMeta[focusedId];
		piDay.textContent = src ? (src.day || 0) : 0;
		pi3h.textContent  = src ? (src.h3  || 0) : 0;
		pi30m.textContent = src ? (src.m30 || 0) : 0;
	}
	document.getElementById('lv-pi-close').addEventListener('click', () => {
		piPanel.classList.remove('open'); focusedId = null; focusedKind = null;
	});

	// --- Ticker / search clicks ----------------------------------------
	document.addEventListener('click', (e) => {
		const t = e.target.closest('#lv-ticker .lv-t-park'); if (!t) return;
		if (t.dataset.eid) openEventInfo(t.dataset.eid, t.dataset.cdid);
		else if (t.dataset.pid) openParkInfo(t.dataset.pid);
	});

	// --- Search --------------------------------------------------------
	let kbdIndex = -1;
	function buildSearchable() {
		const out = [];
		for (const pid in parksMeta)  out.push({ kind: 'park',  id: pid, name: parksMeta[pid].name,  sub: [parksMeta[pid].kingdom, parksMeta[pid].city].filter(Boolean).join(' · ') });
		for (const eid in eventsMeta) out.push({ kind: 'event', id: eid, name: '📅 ' + eventsMeta[eid].name, sub: (eventsMeta[eid].kingdom || '') + ' · event' });
		return out;
	}
	function renderSearch(q) {
		const ql = q.trim().toLowerCase();
		if (!ql) { searchResults.classList.remove('open'); searchResults.innerHTML = ''; kbdIndex = -1; return; }
		const hits = buildSearchable().filter(s => s.name.toLowerCase().includes(ql)).slice(0, 8);
		if (hits.length === 0) { searchResults.classList.remove('open'); kbdIndex = -1; return; }
		searchResults.innerHTML = hits.map((h, i) =>
			`<div class="lv-search-result${i === 0 ? ' kbd-active' : ''}" data-kind="${h.kind}" data-id="${h.id}">
				<div class="lv-sr-name">${h.name}</div><div class="lv-sr-loc">${h.sub}</div>
			</div>`).join('');
		searchResults.classList.add('open');
		kbdIndex = 0;
	}
	function activateResult(idx) {
		const rows = searchResults.querySelectorAll('.lv-search-result');
		rows.forEach(r => r.classList.remove('kbd-active'));
		if (idx >= 0 && idx < rows.length) {
			rows[idx].classList.add('kbd-active');
			rows[idx].scrollIntoView({ block: 'nearest' });
		}
		kbdIndex = idx;
	}
	function pickResult(idx) {
		const rows = searchResults.querySelectorAll('.lv-search-result');
		if (idx < 0 || idx >= rows.length) return false;
		const r = rows[idx];
		if (r.dataset.kind === 'park') openParkInfo(r.dataset.id);
		else openEventInfo(r.dataset.id);
		searchResults.classList.remove('open'); searchInput.value = ''; kbdIndex = -1; searchInput.blur();
		return true;
	}
	searchInput.addEventListener('input', e => renderSearch(e.target.value));
	searchInput.addEventListener('focus', e => renderSearch(e.target.value));
	searchInput.addEventListener('keydown', e => {
		const open = searchResults.classList.contains('open');
		if (e.key === 'ArrowDown' && open) {
			e.preventDefault();
			const n = searchResults.querySelectorAll('.lv-search-result').length;
			activateResult(Math.min(kbdIndex + 1, n - 1));
		} else if (e.key === 'ArrowUp' && open) {
			e.preventDefault();
			activateResult(Math.max(kbdIndex - 1, 0));
		} else if (e.key === 'Enter' && open) {
			e.preventDefault();
			if (!pickResult(kbdIndex < 0 ? 0 : kbdIndex)) searchResults.classList.remove('open');
		} else if (e.key === 'Escape') {
			e.preventDefault(); e.stopPropagation();
			searchResults.classList.remove('open'); searchInput.blur();
		}
	});
	searchResults.addEventListener('click', e => {
		const r = e.target.closest('.lv-search-result'); if (!r) return;
		const rows = Array.from(searchResults.querySelectorAll('.lv-search-result'));
		pickResult(rows.indexOf(r));
	});
	document.addEventListener('click', e => {
		if (!e.target.closest('.lv-search-box') && !e.target.closest('.lv-search-results')) searchResults.classList.remove('open');
	});

	// --- Help popover + global keys ------------------------------------
	helpBtn.addEventListener('click', e => { e.stopPropagation(); helpPop.classList.toggle('open'); });
	document.addEventListener('click', e => {
		if (!e.target.closest('#lv-help-pop') && e.target !== helpBtn) helpPop.classList.remove('open');
	});
	function fitMap() { if (map && initialBounds) map.flyToBounds(initialBounds, { padding: [40, 40], duration: 0.6 }); }
	document.addEventListener('keydown', e => {
		const inField = e.target.closest('input, textarea, [contenteditable]');
		if (e.key === '/' && !inField) { e.preventDefault(); searchInput.focus(); searchInput.select(); }
		else if (e.key === 'Escape') {
			if (helpPop.classList.contains('open')) { helpPop.classList.remove('open'); return; }
			fitMap(); piPanel.classList.remove('open'); focusedId = null; focusedKind = null;
		}
	});
})();
</script>
