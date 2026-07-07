
(function() {
	var WX_SAFETY = {
		heat: {
			icon: '\u{1F975}',
			title: 'Extreme Heat Safety',
			body: 'When conditions include extreme heat, watch for heat exhaustion (heavy sweating, weakness, headache, nausea) and heat stroke (high body temperature, confusion, unconsciousness — call emergency services immediately). Hydrate, take breaks in shade, and check on vulnerable players. Some medications can raise your heat-illness risk — check the third link if you take prescriptions.',
			links: [
				{ label: 'CDC — Preventing Heat-Related Illness (US)', url: 'https://www.cdc.gov/extreme-heat/prevention/index.html' },
				{ label: 'Health Canada — Protect yourself from extreme heat', url: 'https://www.canada.ca/en/health-canada/services/climate-change-health/extreme-heat/how-protect-yourself.html' },
				{ label: 'Health Canada — Medications and extreme heat', url: 'https://www.canada.ca/en/health-canada/services/publications/healthy-living/extreme-heat-human-health-pharmacists-technicians.html' }
			]
		},
		cold: {
			icon: '\u{1F976}',
			title: 'Frostbite &amp; Hypothermia Safety',
			body: 'When conditions include frostbite risk, watch for numb or whitish/yellowish skin (frostbite) and shivering, confusion, slurred speech (hypothermia — call emergency services immediately). Cover exposed skin, stay out of the wind, and get to warmth if symptoms appear.',
			links: [
				{ label: 'CDC — Preventing Hypothermia (US)', url: 'https://www.cdc.gov/winter-weather/prevention/index.html' },
				{ label: 'Health Canada — Extreme cold', url: 'https://www.canada.ca/en/health-canada/services/healthy-living/your-health/environment/extreme-cold.html' }
			]
		}
	};
	// Exposed on window so the inline onclick on the Close button (defined
	// outside this IIFE) can reach it. Backdrop click + Esc + Close all
	// funnel through here so the keydown listener is consistently detached.
	window.closeSafetyDialog = function() {
		var el = document.getElementById('wx-safety-overlay');
		el.style.display = 'none';
		// Symmetric with the add — must pass the same `capture: true` (or a
		// boolean) or removeEventListener() silently no-ops.
		document.removeEventListener('keydown', _wxSafetyEsc, true);
	};
	function _wxSafetyEsc(e) {
		if (e.key === 'Escape' || e.key === 'Esc') {
			// stopImmediatePropagation prevents the Weather-page map's own
			// document-level Esc handler (which flies back to the zoomed-out
			// view) from firing when the user was just dismissing our modal.
			e.preventDefault();
			e.stopImmediatePropagation();
			closeSafetyDialog();
		}
	}
	window.wxSafetyDialog = function(kind) {
		var d = WX_SAFETY[kind]; if (!d) return;
		document.getElementById('wx-safety-icon').textContent  = d.icon;
		var titleEl = document.getElementById('wx-safety-title');
		var tmp = document.createElement('div'); tmp.innerHTML = d.title; titleEl.textContent = tmp.textContent;
		document.getElementById('wx-safety-body').textContent = d.body;
		var links = document.getElementById('wx-safety-links'); links.textContent = '';
		d.links.forEach(function(l) {
			var a = document.createElement('a');
			a.textContent = l.label;
			a.setAttribute('href', l.url);
			a.setAttribute('target', '_blank');
			a.setAttribute('rel', 'noopener noreferrer');
			a.style.color = '#2b6cb0'; a.style.textDecoration = 'underline'; a.style.display = 'block';
			links.appendChild(a);
		});
		var el = document.getElementById('wx-safety-overlay');
		el.style.display = 'flex';
		el.onclick = function(e) { if (e.target === el) closeSafetyDialog(); };
		// Attach at CAPTURE phase so we see Escape before the Weather-page
		// map's document-level bubble-phase handler (which zooms out any
		// open marker popup). closeSafetyDialog() detaches on any close path
		// so the listener never lingers when the modal isn't open.
		document.addEventListener('keydown', _wxSafetyEsc, true);
	};
	// JS-side equivalents of wx_safety_attrs / wx_safety_icon_html from
	// system/lib/ork3/wx_safety_helpers.php. Two helpers because they get
	// spliced into different parts of the badge markup — attrs on the
	// opening tag, icon into the label text.
	function _wxSafetyKind(label) {
		if (label === 'Extreme heat')   return 'heat';
		if (label === 'Frostbite risk') return 'cold';
		return null;
	}
	window.wxSafetyAttrs = function(label) {
		var kind = _wxSafetyKind(label);
		if (!kind) return '';
		return ' onclick="wxSafetyDialog(\'' + kind + '\')"'
			+ ' role="button" tabindex="0" data-wx-safety="' + kind + '"';
	};
	window.wxSafetyIconHtml = function(label) {
		if (!_wxSafetyKind(label)) return '';
		return ' <i class="fas fa-info-circle" style="margin-left:3px;opacity:0.8;font-size:0.9em"></i>';
	};
})();
