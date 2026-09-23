/*!
 * ORK event-schedule embed widget — drop-in, no dependencies.
 *
 * Usage on any third-party page:
 *   <div class="ork-schedule" data-event="123" data-detail="456"></div>
 *   <script src="https://YOUR-ORK-HOST/orkui/template/embed/ork-schedule.js"></script>
 *
 * - data-event   (required) the event id
 * - data-detail  (optional) the occurrence id; omit for the next upcoming date
 * - data-base    (optional) ORK base URL ending in /orkui/, if you host this
 *                 script somewhere other than the ORK itself (e.g. a CDN)
 *
 * The widget derives the API location from its own <script src>, fetches the
 * public CORS endpoint (EventEmbed/schedule), and renders a compact schedule
 * that links back to the full event page. Call window.OrkSchedule.render()
 * again after injecting new .ork-schedule nodes (e.g. in a single-page app).
 */
(function () {
	'use strict';

	// Capture the executing <script> now — document.currentScript is only valid
	// during initial synchronous execution.
	var ME = document.currentScript;
	var STYLE_ID = 'ork-sched-styles';

	// Left-accent colour per schedule category, matching the ORK event page.
	var CAT_COLORS = {
		'Administrative':    '#546e7a',
		'Tournament':        '#b8860b',
		'Battlegame':        '#c0392b',
		'Arts and Sciences': '#7b1fa2',
		'Class':             '#1565c0',
		'Feast and Food':    '#e65100',
		'Court':             '#4e342e',
		'Meeting':           '#276749',
		'Other':             '#757575'
	};

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// Work out the ORK base URL (…/orkui/) from an explicit override or this
	// script's own src, then build the JSON endpoint under it.
	function apiEndpoint(container) {
		var base = container.getAttribute('data-base') || '';
		if (!base) {
			var src = (ME && ME.src) || '';
			base = src.replace(/template\/embed\/ork-schedule\.js.*$/i, '');
		}
		if (base && base.charAt(base.length - 1) !== '/') base += '/';
		return base + 'index.php?Route=EventEmbed/schedule/';
	}

	function injectStyles() {
		if (document.getElementById(STYLE_ID)) return;
		var css =
'.ork-sched{max-width:560px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;' +
'color:#2d3748;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-sizing:border-box;' +
'box-shadow:0 1px 3px rgba(0,0,0,.06);line-height:1.4}' +
'.ork-sched *{box-sizing:border-box}' +
'.ork-sched-title{font-size:17px;font-weight:700;color:#1a202c;text-decoration:none;display:inline-block}' +
'.ork-sched-title:hover{text-decoration:underline}' +
'.ork-sched-date{font-size:13px;color:#718096;margin:2px 0 12px}' +
'.ork-sched-day{margin:0 0 14px}' +
'.ork-sched-day-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#a0aec0;margin:0 0 6px}' +
'.ork-sched-list{list-style:none;margin:0;padding:0}' +
'.ork-sched-item{display:flex;gap:10px;padding:7px 0 7px 10px;border-left:3px solid #cbd5e0;margin:0 0 4px;border-radius:2px}' +
'.ork-sched-time{flex:0 0 auto;min-width:72px;font-size:12px;font-weight:600;color:#4a5568;white-space:nowrap;padding-top:1px}' +
'.ork-sched-body{flex:1 1 auto;min-width:0}' +
'.ork-sched-item-title{font-size:14px;font-weight:600;color:#2d3748}' +
'.ork-sched-loc,.ork-sched-leads{display:block;font-size:12px;color:#718096;margin-top:1px}' +
'.ork-sched-more{display:inline-block;margin-top:4px;font-size:13px;font-weight:600;color:#2b6cb0;text-decoration:none}' +
'.ork-sched-more:hover{text-decoration:underline}' +
'.ork-sched-empty,.ork-sched-msg{font-size:13px;color:#718096;padding:6px 0}' +
'@media (prefers-color-scheme:dark){' +
'.ork-sched{background:#1a202c;border-color:#2d3748;color:#e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.4)}' +
'.ork-sched-title{color:#f7fafc}.ork-sched-date{color:#a0aec0}' +
'.ork-sched-item{border-left-color:#4a5568}.ork-sched-time{color:#cbd5e0}' +
'.ork-sched-item-title{color:#edf2f7}.ork-sched-loc,.ork-sched-leads,.ork-sched-empty,.ork-sched-msg{color:#a0aec0}' +
'.ork-sched-more{color:#63b3ed}}';
		var el = document.createElement('style');
		el.id = STYLE_ID;
		el.textContent = css;
		(document.head || document.documentElement).appendChild(el);
	}

	function renderItem(item) {
		var accent = CAT_COLORS[item.category] || '#cbd5e0';
		var parts = [];
		parts.push('<li class="ork-sched-item" style="border-left-color:' + accent + '">');
		parts.push('<span class="ork-sched-time">' + esc(item.time_label) + '</span>');
		parts.push('<span class="ork-sched-body">');
		parts.push('<span class="ork-sched-item-title">' + esc(item.title) + '</span>');
		if (item.location) parts.push('<span class="ork-sched-loc">' + esc(item.location) + '</span>');
		if (item.leads && item.leads.length) {
			parts.push('<span class="ork-sched-leads">' + esc(item.leads.join(', ')) + '</span>');
		}
		parts.push('</span></li>');
		return parts.join('');
	}

	function renderData(container, data) {
		var html = ['<div class="ork-sched" role="region" aria-label="Event schedule">'];
		html.push('<a class="ork-sched-title" href="' + esc(data.detail_url) + '" target="_blank" rel="noopener">' + esc(data.name) + '</a>');
		var sub = data.date_label || '';
		if (data.park_name) sub += (sub ? ' · ' : '') + data.park_name;
		if (sub) html.push('<div class="ork-sched-date">' + esc(sub) + '</div>');

		var days = data.days || [];
		var total = days.reduce(function (n, d) { return n + (d.items ? d.items.length : 0); }, 0);
		if (!total) {
			html.push('<div class="ork-sched-empty">No schedule posted yet.</div>');
		} else {
			days.forEach(function (day) {
				if (!day.items || !day.items.length) return;
				html.push('<div class="ork-sched-day">');
				if (days.length > 1 || day.day_label) {
					html.push('<div class="ork-sched-day-label">' + esc(day.day_label) + '</div>');
				}
				html.push('<ul class="ork-sched-list">');
				day.items.forEach(function (it) { html.push(renderItem(it)); });
				html.push('</ul></div>');
			});
		}
		var moreUrl = data.grid_url || data.schedule_url || data.detail_url;
		html.push('<a class="ork-sched-more" href="' + esc(moreUrl) + '" target="_blank" rel="noopener">See full schedule →</a>');
		html.push('</div>');
		container.innerHTML = html.join('');
	}

	function renderMessage(container, msg) {
		container.innerHTML = '<div class="ork-sched"><div class="ork-sched-msg">' + esc(msg) + '</div></div>';
	}

	function load(container) {
		if (container.getAttribute('data-ork-loaded') === '1') return;
		container.setAttribute('data-ork-loaded', '1');

		var eventId  = container.getAttribute('data-event');
		var detailId = container.getAttribute('data-detail') || '';
		if (!eventId) { renderMessage(container, 'Missing data-event id.'); return; }

		renderMessage(container, 'Loading schedule…');
		var url = apiEndpoint(container) + encodeURIComponent(eventId) + '/' + encodeURIComponent(detailId);

		fetch(url, { credentials: 'omit' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.ok) {
					renderMessage(container, (data && data.error) || 'Schedule unavailable.');
					return;
				}
				renderData(container, data);
			})
			.catch(function () {
				container.removeAttribute('data-ork-loaded'); // let a later render() retry
				renderMessage(container, 'Could not load schedule.');
			});
	}

	function render(root) {
		injectStyles();
		var scope = root || document;
		var nodes = scope.querySelectorAll('.ork-schedule');
		Array.prototype.forEach.call(nodes, load);
	}

	window.OrkSchedule = { render: render };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { render(); });
	} else {
		render();
	}
})();
