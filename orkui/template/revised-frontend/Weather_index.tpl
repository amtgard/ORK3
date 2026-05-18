<?php
	$rundown    = $Rundown ?? array();
	$events     = $UpcomingEvents ?? array();
	$dateStrip  = $DateStrip ?? array();
	$playToday  = $PlayToday ?? array();
	$selected   = $SelectedDate ?? date('Y-m-d');

	// ─── Rundown sentence builder ──────────────────────────────────
	// Pre-written slot templates; no LLM.
	$parts = array();

	$bc      = $rundown['badge_counts']   ?? array();
	$bk      = $rundown['badge_kingdoms'] ?? array();
	$standout = $rundown['standout_park'] ?? null;
	$ec      = (int)($rundown['event_count']      ?? 0);
	$econ    = (int)($rundown['event_concerning'] ?? 0);

	// Helper: format a list of kingdom rollups (top N) for a badge label
	$wxRollupKingdoms = function($map, $max = 5) {
		if (!is_array($map)) return '';
		arsort($map);
		$top = array_slice($map, 0, $max, true);
		$names = array();
		foreach ($top as $k => $count) {
			$short = Weather::short_kingdom($k);
			if ($short !== '') $names[] = $short;
		}
		$n = count($names);
		if ($n === 0) return '';
		if ($n === 1) return $names[0];
		if ($n === 2) return $names[0] . ' and ' . $names[1];
		return implode(', ', array_slice($names, 0, -1)) . ', and ' . end($names);
	};

	// Helper: build the " in X" / " — primarily in X" / ", across X" suffix
	// for a narrative line. Uses plain "in" when there's only one park OR
	// only one kingdom involved (since "primarily"/"across" both imply
	// multiple sources). Falls back to the badge-specific $multiPrefix when
	// multiple parks span multiple kingdoms.
	$wxKingdomPhrase = function($n, $map, $multiPrefix) use ($wxRollupKingdoms) {
		if (empty($map)) return '';
		$names = $wxRollupKingdoms($map);
		if ($names === '') return '';
		$kc = 0;
		foreach ($map as $k => $_count) {
			if (Weather::short_kingdom($k) !== '') $kc++;
		}
		if ($n === 1 || $kc === 1) return ' in ' . $names;
		return $multiPrefix . $names;
	};

	// Helper: park name as a link, "the Kingdom of Foo" suffix only when kingdom is non-Freeholds
	$wxParkLink = function($p) {
		if (!$p) return '';
		$name = htmlspecialchars($p['name'] ?? 'a park');
		$pid  = (int)($p['park_id'] ?? 0);
		if ($pid > 0) {
			return '<a href="#weather-map" data-park-id="' . $pid . '" class="wx-jump-park">' . $name . '</a>';
		}
		return $name;
	};

	$wxTemp = function($f) {
		if ($f === null) return '';
		$c = round(($f - 32) * 5 / 9);
		return round($f) . '°F / ' . $c . '°C';
	};

	// ─── Lead sentence(s). Counts here are scoped to parks with in-person play today —
	//    so a 113°F forecast at a park with no scheduled play doesn't show up,
	//    keeping the rundown actionable for "should I head out tonight?".
	$leadParts = array();
	$noPlayToday = !empty($rundown['no_play_today']);
	$nothingFlagged = empty($bc);
	if ($noPlayToday) {
		$leadParts[] = 'No parks have in-person play scheduled for today.';
	} elseif ($nothingFlagged) {
		$leadParts[] = 'Of the parks playing today, conditions look favorable across the board — no warnings flagged.';
	} else {
		if (!empty($bc['Thunderstorms'])) {
			$n = $bc['Thunderstorms'];
			$suffix = $wxKingdomPhrase($n, $bk['Thunderstorms'] ?? array(), ' — primarily in ');
			$leadParts[] = "Of parks playing today, $n " . ($n === 1 ? 'faces' : 'face') . ' thunderstorms' . $suffix . '.';
		}
		if (!empty($bc['Extreme heat'])) {
			$n = $bc['Extreme heat'];
			$leadParts[] = ($n === 1 ? 'One park playing today is' : "$n parks playing today are") . ' facing extreme heat.';
		}
		if (!empty($bc['Frostbite risk'])) {
			$n = $bc['Frostbite risk'];
			$leadParts[] = ($n === 1 ? 'One park playing today is' : "$n parks playing today are") . ' under frostbite-risk conditions.';
		}
		if (!empty($bc['Severe wind'])) {
			$n = $bc['Severe wind'];
			$suffix = $wxKingdomPhrase($n, $bk['Severe wind'] ?? array(), ', across ');
			$leadParts[] = "Severe wind gusts (40+ mph / 65+ km/h) at $n " . ($n === 1 ? 'park' : 'parks') . ' playing today' . $suffix . '.';
		}
		if (!empty($bc['Very high UV'])) {
			$n = $bc['Very high UV'];
			$suffix = $wxKingdomPhrase($n, $bk['Very high UV'] ?? array(), ' in ');
			$leadParts[] = "Very high UV at $n " . ($n === 1 ? 'park' : 'parks') . ' playing today' . $suffix . '.';
		}
	}

	// ─── Standout park callout (one line about the worst-hit park)
	$standoutLine = '';
	if ($standout) {
		$flags = $standout['flags'] ?? array();
		$icons = array(
			'Extreme heat'   => '🥵',
			'Frostbite risk' => '🥶',
			'Thunderstorms'  => '⛈️',
			'Severe wind'    => '💨',
			'Very high UV'   => '🌞',
		);
		$pieces = array();
		foreach ($flags as $f) {
			$pieces[] = ($icons[$f] ?? '⚠️') . ' ' . htmlspecialchars($f);
		}
		$standoutLine = $wxParkLink($standout) . ' carries the worst forecast — ' . implode(' · ', $pieces);
		if (!empty($standout['app_hi_f'])) {
			$standoutLine .= ' (' . $wxTemp($standout['app_hi_f']) . ' apparent).';
		} else {
			$standoutLine .= '.';
		}
	}

	// ─── Coming-up sentence
	$comingUp = '';
	if ($ec > 0) {
		$comingUp = '<a href="#upcoming-events" class="wx-events-link">' . $ec . ' ' . ($ec === 1 ? 'event' : 'events') .
			' in the week ahead</a>.';
		if ($econ > 0) {
			$comingUp .= ' ' . $econ . ' ' . ($econ === 1 ? 'falls' : 'fall') . ' on days with concerning forecasts; the rest look favorable.';
		} else {
			$comingUp .= ' All look favorable.';
		}
	}

	// ─── Fallback
	if (empty($leadParts) && !$standoutLine && !$comingUp) {
		$leadParts[] = 'Calm conditions across the realm today.';
	}

	// Human-readable reason for a missing forecast — mirrors the JS version
	$wxStatusText = function($s) {
		if ($s === 'dormant')     return 'no recent activity — weather not tracked';
		if ($s === 'no_coords')   return 'park location not set';
		if ($s === 'unavailable') return 'forecast unavailable';
		return '';
	};

	// WMO code → emoji (used by the events list)
	$wxIcon = function($c) {
		$c = (int)$c;
		if ($c === 0)                          return '☀️';
		if ($c === 1)                          return '🌤️';
		if ($c === 2)                          return '⛅';
		if ($c === 3)                          return '☁️';
		if ($c === 45 || $c === 48)            return '🌫️';
		if ($c >= 51 && $c <= 57)              return '🌦️';
		if ($c >= 61 && $c <= 67)              return '🌧️';
		if ($c >= 71 && $c <= 77)              return '❄️';
		if ($c >= 80 && $c <= 82)              return '🌦️';
		if ($c === 85 || $c === 86)            return '🌨️';
		if ($c >= 95 && $c <= 99)              return '⛈️';
		return '🌡️';
	};
?>

<style>
.wx-root { max-width: 1100px; margin: 0 auto; padding: 12px 12px 16px; display: flex; flex-direction: column; gap: 12px; }
.wx-root > .wx-rundown,
.wx-root > .wx-strip,
.wx-root > .wx-map-card,
.wx-root > .wx-lists,
.wx-root > .wx-lists > .wx-play,
.wx-root > .wx-lists > .wx-events { margin-bottom: 0; }

/* Layout: explicit pixel heights for the map + lists so they don't change
   when content reflows (rundown longer on warning-heavy days, banner
   appearing/disappearing, day with 18 parks vs 162). The internal scroll on
   each list keeps long content contained without resizing the card. */
@media (min-width: 900px) {
	.wx-map-card { display: flex; flex-direction: column; }
	.wx-map-card .wx-map { height: 500px; }
	.wx-lists { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
	.wx-lists > .wx-play,
	.wx-lists > .wx-events { display: flex; flex-direction: column; height: 400px; }
	.wx-lists > .wx-play > #wx-play-body,
	.wx-lists > .wx-events > .wx-events-body { flex: 1 1 auto; overflow-y: auto; min-height: 0; }
}
@media (max-width: 899px) {
	/* Narrow screens: free-flowing stack, modest map */
	.wx-map-card .wx-map { height: 320px; }
	.wx-lists > .wx-events { margin-top: 12px; }
}
.wx-rundown { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 14px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
/* Fixed rundown height so warning-heavy days don't push the map down.
   Short days have some breathing room; long days scroll internally. */
.wx-rundown { height: 140px; overflow-y: auto; }
.wx-rundown h2 { margin: 0 0 10px; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.wx-rundown p { margin: 0 0 8px; font-size: 14.5px; line-height: 1.55; color: var(--ork-text, #2d3748); }
.wx-rundown p:last-child { margin-bottom: 0; }
.wx-rundown .wx-jump-park { color: var(--ork-link-bright, #2b6cb0); font-weight: 600; text-decoration: none; border-bottom: 1px dotted currentColor; cursor: pointer; }
.wx-rundown .wx-jump-park:hover { border-bottom-style: solid; }
.wx-rundown .wx-events-link { color: var(--ork-link-bright, #2b6cb0); text-decoration: none; border-bottom: 1px dotted currentColor; }
.wx-rundown .wx-events-link::after { content: ' ↓'; opacity: .6; }
.wx-rundown .wx-events-link:hover { border-bottom-style: solid; }

.wx-events { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 14px 18px; }
.wx-events-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--ork-border, #e2e8f0); flex: 0 0 auto; }
.wx-events-header h2 { margin: 0; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.wx-events-header .wx-events-attr { font-size: 11px; color: var(--ork-text-muted, #a0aec0); }
.wx-events-header .wx-events-attr a { color: inherit; text-decoration: none; }
.wx-events-header .wx-events-attr a:hover { color: var(--ork-link); }

.wx-event { display: grid; grid-template-columns: 70px 1fr auto; gap: 14px; padding: 10px 0; border-bottom: 1px solid var(--ork-border, #edf2f7); align-items: center; }
.wx-event[data-lat]:hover, .wx-event[data-park-id]:hover { background: var(--ork-bg-secondary, #f7fafc); cursor: pointer; }
.wx-event:last-child { border-bottom: 0; }
.wx-event-date { font-size: 12px; color: var(--ork-text-muted, #718096); font-variant-numeric: tabular-nums; text-align: center; }
.wx-event-date .wx-event-month { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
.wx-event-date .wx-event-day   { display: block; font-size: 20px; font-weight: 700; color: var(--ork-text, #2d3748); line-height: 1.1; }
.wx-event-date .wx-event-dow   { display: block; font-size: 10px; }
.wx-event-range { font-style: italic; }
.wx-event-main { min-width: 0; }
.wx-event-name { font-size: 14.5px; font-weight: 600; color: var(--ork-text, #2d3748); }
.wx-event-name a { color: inherit; text-decoration: none; }
.wx-event-name a:hover { color: var(--ork-link-bright, #2b6cb0); }
.wx-event-loc { font-size: 12px; color: var(--ork-text-muted, #718096); margin-top: 2px; }
.wx-event-loc a { color: inherit; text-decoration: none; }
.wx-event-loc a:hover { text-decoration: underline; }
.wx-event-wx { text-align: right; font-size: 13px; min-width: 130px; }
.wx-event-wx .wx-event-temps { font-weight: 600; color: var(--ork-text, #2d3748); }
.wx-event-wx .wx-event-meta { font-size: 11px; color: var(--ork-text-muted, #718096); margin-top: 1px; }
.wx-event-wx .wx-event-empty { font-size: 11px; color: var(--ork-text-muted, #a0aec0); font-style: italic; }
.wx-event-badge { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 10px; font-weight: 600; margin-left: 4px; vertical-align: middle; }
.wx-event-badge-warning { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.wx-event-badge-caution { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
html[data-theme="dark"] .wx-event-badge-warning { background: #450a0a; color: #fca5a5; border-color: #7f1d1d; }
html[data-theme="dark"] .wx-event-badge-caution { background: #422006; color: #fcd34d; border-color: #78350f; }

.wx-empty { padding: 30px 12px; text-align: center; color: var(--ork-text-muted, #a0aec0); font-style: italic; }

/* Date strip */
.wx-strip { display: flex; gap: 6px; flex-wrap: wrap; }
.wx-pill { position: relative; flex: 1 1 0; min-width: 70px; padding: 8px 10px; border-radius: 8px; background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); cursor: pointer; text-align: center; color: var(--ork-text, #2d3748); font-family: inherit; transition: background .12s, border-color .12s; outline: none; }
.wx-pill:hover { background: var(--ork-bg-secondary, #f7fafc); }
/* Active pill: uniform 2px blue border + subtle tint. Use negative margin
   so the thicker border doesn't shift sibling pills. */
.wx-pill.active { border: 2px solid var(--ork-link-bright, #2b6cb0); margin: -1px; background: rgba(43, 108, 176, 0.08); }
/* Keyboard focus-visible ring (only when navigating with keyboard) */
.wx-pill:focus-visible { box-shadow: 0 0 0 2px var(--ork-link-bright, #2b6cb0); }
html[data-theme="dark"] .wx-pill { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .wx-pill:hover { background: rgba(255,255,255,0.04); }
html[data-theme="dark"] .wx-pill.active { background: rgba(99, 179, 237, 0.10); border-color: #63b3ed; }
html[data-theme="dark"] .wx-pill:focus-visible { box-shadow: 0 0 0 2px #63b3ed; }
.wx-pill-day { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.wx-pill-date { font-size: 11px; color: var(--ork-text-muted, #718096); margin-top: 2px; }
.wx-pill-dot { display: block; width: 7px; height: 7px; border-radius: 50%; margin: 5px auto 0; }

/* Play list */
.wx-play { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 14px 18px; }
.wx-play-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--ork-border, #e2e8f0); flex: 0 0 auto; }
.wx-play-header h2 { margin: 0; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.wx-play-controls { display: flex; align-items: center; gap: 10px; font-size: 11px; color: var(--ork-text-muted, #a0aec0); }
/* Fixed widths on count + toggle so changing mode/search doesn't reflow the header. */
.wx-play-count { font-size: 11px; color: var(--ork-text-muted, #a0aec0); min-width: 70px; text-align: right; font-variant-numeric: tabular-nums; }
.wx-play-toggle { background: transparent; border: 1px solid var(--ork-border, #cbd5e0); color: var(--ork-link-bright, #2b6cb0); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; cursor: pointer; min-width: 140px; text-align: center; }
.wx-play-toggle.wx-invisible { visibility: hidden; }
.wx-play-toggle:hover { background: var(--ork-bg-secondary, #f7fafc); }
html[data-theme="dark"] .wx-play-toggle:hover { background: rgba(255,255,255,0.05); }
/* Mode is conveyed by label text + the banner — no loud active-fill needed. */
.wx-play-toggle.active { background: rgba(43, 108, 176, 0.10); border-color: var(--ork-link-bright, #2b6cb0); }
html[data-theme="dark"] .wx-play-toggle.active { background: rgba(99, 179, 237, 0.12); border-color: #63b3ed; color: #63b3ed; }
.wx-play-search { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #cbd5e0); border-radius: 6px; padding: 3px 8px; font-size: 12px; color: var(--ork-text, #2d3748); width: 130px; outline: none; }
.wx-play-search:focus { border-color: var(--ork-link-bright, #2b6cb0); }
html[data-theme="dark"] .wx-play-search { background: var(--ork-bg-secondary, #1a202c); color: var(--ork-text, #e2e8f0); }
.wx-play-row.wx-filtered { display: none; }

/* Filter-state banner — appears between the header and the list when the
   "flagged" mode is active so users understand they're seeing a curated subset. */
.wx-play-mode { display: none; align-items: center; gap: 8px; padding: 6px 10px; margin: 0 0 8px; border-radius: 6px; background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; font-size: 11.5px; }
.wx-play-mode .wx-play-mode-icon { font-size: 14px; }
.wx-play-mode .wx-play-mode-clear { margin-left: auto; background: transparent; border: 0; color: #92400e; text-decoration: underline; font-size: 11px; cursor: pointer; padding: 0; }
.wx-play.is-flagged-mode .wx-play-mode { display: flex; }
html[data-theme="dark"] .wx-play-mode { background: #422006; color: #fcd34d; border-color: #78350f; }
html[data-theme="dark"] .wx-play-mode .wx-play-mode-clear { color: #fcd34d; }
.wx-play-row { display: grid; grid-template-columns: 1fr auto; gap: 14px; padding: 9px 0; border-bottom: 1px solid var(--ork-border, #edf2f7); align-items: center; }
.wx-play-row[data-park-id]:hover { background: var(--ork-bg-secondary, #f7fafc); cursor: pointer; }
.wx-play-row:last-child { border-bottom: 0; }
.wx-play-main { min-width: 0; }
.wx-play-park { font-size: 14px; font-weight: 600; color: var(--ork-text, #2d3748); }
.wx-play-park a { color: inherit; text-decoration: none; }
.wx-play-park a:hover { color: var(--ork-link-bright, #2b6cb0); }
.wx-play-meta { font-size: 11.5px; color: var(--ork-text-muted, #718096); margin-top: 2px; }
.wx-play-wx { text-align: right; font-size: 13px; min-width: 130px; }
.wx-play-wx .wx-play-temps { font-weight: 600; color: var(--ork-text, #2d3748); }
.wx-play-wx .wx-play-empty { font-size: 11px; color: var(--ork-text-muted, #a0aec0); font-style: italic; }

.wx-loading { padding: 20px; text-align: center; color: var(--ork-text-muted, #a0aec0); font-style: italic; }

/* Map */
.wx-map-card { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 14px 18px; }
.wx-map-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--ork-border, #e2e8f0); gap: 10px; }
.wx-map-header h2 { margin: 0; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
#wx-zoom-out { display: none; background: transparent; border: 1px solid var(--ork-border, #cbd5e0); color: var(--ork-text-muted, #718096); font-size: 11px; padding: 2px 8px; border-radius: 6px; cursor: pointer; white-space: nowrap; flex-shrink: 0; }
#wx-zoom-out:hover { background: var(--ork-bg-secondary, #f7fafc); color: var(--ork-text, #2d3748); }
html[data-theme="dark"] #wx-zoom-out:hover { background: rgba(255,255,255,0.05); }
#wx-zoom-out.visible { display: inline-block; }
.wx-map-legend { display: flex; gap: 10px; font-size: 11px; color: var(--ork-text-muted, #718096); flex-wrap: wrap; }
.wx-map-legend span { display: inline-flex; align-items: center; gap: 4px; }
.wx-map-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(0,0,0,0.2); }
.wx-map { width: 100%; height: 360px; border-radius: 6px; overflow: hidden; }
html[data-theme="dark"] .wx-map { background: #1a202c; }
/* Tone down Leaflet's default white zoom controls in dark mode */
html[data-theme="dark"] .wx-map .leaflet-bar a,
html[data-theme="dark"] .wx-map .leaflet-bar a:hover { background: #2d3748; color: #e2e8f0; border-bottom-color: #4a5568; }
html[data-theme="dark"] .wx-map .leaflet-bar a:hover { background: #4a5568; }
html[data-theme="dark"] .wx-map .leaflet-bar { border-color: #4a5568; box-shadow: 0 1px 3px rgba(0,0,0,0.5); }
html[data-theme="dark"] .wx-map .leaflet-bar a.leaflet-disabled { background: #1a202c; color: #4a5568; }
/* Leaflet popups in dark mode */
html[data-theme="dark"] .wx-map .leaflet-popup-content-wrapper { background: #2d3748; color: #e2e8f0; box-shadow: 0 3px 14px rgba(0,0,0,0.6); }
html[data-theme="dark"] .wx-map .leaflet-popup-tip { background: #2d3748; }
html[data-theme="dark"] .wx-map .leaflet-popup-close-button { color: #a0aec0; }
html[data-theme="dark"] .wx-map .leaflet-popup-close-button:hover { color: #e2e8f0; }
html[data-theme="dark"] .wx-map-popup .wx-pp-meta { color: #a0aec0; }
html[data-theme="dark"] .wx-map .leaflet-tooltip { background: #2d3748; color: #e2e8f0; border-color: #4a5568; box-shadow: 0 1px 3px rgba(0,0,0,0.5); }
html[data-theme="dark"] .wx-map .leaflet-tooltip-top:before { border-top-color: #2d3748; }
html[data-theme="dark"] .wx-map .leaflet-tooltip-bottom:before { border-bottom-color: #2d3748; }
html[data-theme="dark"] .wx-map .leaflet-tooltip-left:before { border-left-color: #2d3748; }
html[data-theme="dark"] .wx-map .leaflet-tooltip-right:before { border-right-color: #2d3748; }
.wx-map-popup { font-size: 12px; line-height: 1.4; }
.wx-map-popup .wx-pp-name { font-weight: 700; font-size: 13px; }
.wx-map-popup .wx-pp-name a { color: var(--ork-link-bright, #2b6cb0); text-decoration: none; }
.wx-map-popup .wx-pp-name a:hover { text-decoration: underline; }
.wx-map-popup .wx-pp-meta { color: var(--ork-text-muted, #718096); font-size: 11px; margin-top: 2px; }
.wx-map-popup .wx-pp-wx { margin-top: 4px; font-weight: 600; }
.wx-map-popup .wx-pp-badges { margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px; }
.wx-map-popup .wx-pp-badges .wx-event-badge { margin-left: 0; padding: 2px 8px; font-size: 10.5px; }

.wx-play-row.wx-hidden, .wx-event.wx-hidden { display: none; }
</style>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" defer></script>

<div class="wx-root" data-selected-date="<?= htmlspecialchars($selected) ?>">

	<!-- ── Rundown ──────────────────────────────────────────── -->
	<div class="wx-rundown" id="wx-rundown">
		<h2>🌤️ Amtgard Weather This Week</h2>
		<div id="wx-rundown-body">
		<?php if (!empty($leadParts)): ?>
			<p><?= implode(' ', $leadParts) ?></p>
		<?php endif; ?>
		<?php if ($standoutLine): ?>
			<p><?= $standoutLine ?></p>
		<?php endif; ?>
		<?php if ($comingUp): ?>
			<p><?= $comingUp ?></p>
		<?php endif; ?>
		</div>
	</div>

	<!-- ── Date strip ─────────────────────────────────────── -->
	<div class="wx-strip" id="wx-strip">
		<?php foreach ($dateStrip as $pill): ?>
			<button class="wx-pill<?= $pill['is_today'] ? ' active' : '' ?>"
			        data-date="<?= htmlspecialchars($pill['date']) ?>">
				<div class="wx-pill-day"><?= htmlspecialchars($pill['day_label']) ?></div>
				<div class="wx-pill-date"><?= htmlspecialchars($pill['date_label']) ?></div>
				<?php
					if ($pill['severity'] === 'warning')     { $dotStyle = 'background:#e53e3e;'; $dotTitle = 'Weather warning'; }
					elseif ($pill['severity'] === 'caution') { $dotStyle = 'background:#dd6b20;'; $dotTitle = 'Weather caution'; }
					else                                     { $dotStyle = 'visibility:hidden;';  $dotTitle = ''; }
				?>
				<span class="wx-pill-dot" style="<?= $dotStyle ?>"<?= $dotTitle ? ' title="' . $dotTitle . '"' : '' ?>></span>
			</button>
		<?php endforeach; ?>
	</div>

	<!-- ── Map (for selected day) ──────────────────────────── -->
	<div class="wx-map-card" id="wx-map-card">
		<div class="wx-map-header">
			<h2 id="wx-map-title">🗺️ Conditions Map — Today</h2>
			<button id="wx-zoom-out">Esc — Reset Zoom</button>
			<div class="wx-map-legend">
				<span><i style="background:#e53e3e;"></i>Warning</span>
				<span><i style="background:#dd6b20;"></i>Caution</span>
				<span><i style="background:#48bb78;"></i>Favorable</span>
				<span><i style="background:#a0aec0;"></i>No forecast</span>
			</div>
		</div>
		<div class="wx-map" id="wx-map"></div>
	</div>

	<!-- ── Lists (play + events, side-by-side on wide screens) ── -->
	<div class="wx-lists">

	<!-- ── Play list (for selected day) ───────────────────── -->
	<?php
		// Flagged = anything with a warning or caution badge. When any exist,
		// they're the only rows shown by default (severity-sorted); a button
		// reveals the full alphabetical list. When none exist, show the whole
		// list alphabetically and hide the toggle.
		$flaggedCount = 0;
		foreach ($playToday as $p) { if (!empty($p['badges'])) $flaggedCount++; }
		$initialMode = $flaggedCount > 0 ? 'flagged' : 'all';
	?>
	<div class="wx-play" id="wx-play">
		<div class="wx-play-header">
			<h2 id="wx-play-title">🏕️ Play Today</h2>
			<div class="wx-play-controls">
				<input type="search" class="wx-play-search" id="wx-play-search" placeholder="Filter parks…" autocomplete="off">
				<span class="wx-play-count" id="wx-play-count">
					<?php if ($initialMode === 'flagged'): ?>
						<?= $flaggedCount ?> of <?= count($playToday) ?>
					<?php else: ?>
						<?= count($playToday) ?> parks
					<?php endif; ?>
				</span>
				<?php if ($flaggedCount > 0): ?>
					<button class="wx-play-toggle" id="wx-play-toggle" data-mode="flagged"><?= count($playToday) > $flaggedCount ? 'Show all ' . count($playToday) : 'A–Z' ?></button>
				<?php endif; ?>
			</div>
		</div>
		<div class="wx-play-mode" id="wx-play-mode">
			<span class="wx-play-mode-icon">⚠️</span>
			<span>Showing only parks with <strong>weather concerns</strong> on this day.</span>
			<button type="button" class="wx-play-mode-clear" id="wx-play-mode-clear">Show all parks</button>
		</div>
		<div id="wx-play-body">
			<?php if (empty($playToday)): ?>
				<div class="wx-empty">No in-person play scheduled.</div>
			<?php else: ?>
				<?php foreach ($playToday as $i => $p):
					$fc = $p['forecast'] ?? null;
					$kshort = Weather::short_kingdom($p['kingdom_name'] ?? '');
					$scheds = array();
					foreach ($p['schedules'] as $s) {
						$t = $s['time'] ? date('g:i A', strtotime($s['time'])) : '';
						$scheds[] = htmlspecialchars($s['purpose']) . ($t ? " at $t" : '');
					}
					$flagged = !empty($p['badges']);
					$hideClass = ($initialMode === 'flagged' && !$flagged) ? ' wx-hidden' : '';
				?>
				<div class="wx-play-row<?= $hideClass ?>"
				     data-park-id="<?= (int)$p['park_id'] ?>"
				     data-park-name="<?= htmlspecialchars(mb_strtolower($p['park_name'])) ?>"
				     data-severity="<?= (int)($p['_score'] ?? 0) ?>"
				     data-flagged="<?= $flagged ? '1' : '0' ?>"
				     <?php if ($p['lat'] !== null): ?>data-lat="<?= htmlspecialchars((string)$p['lat']) ?>" data-lng="<?= htmlspecialchars((string)$p['lng']) ?>"<?php endif; ?>>
					<div class="wx-play-main">
						<div class="wx-play-park">
							<a href="<?= UIR ?>Park/profile/<?= (int)$p['park_id'] ?>" class="wx-jump" target="_blank" rel="noopener"><?= htmlspecialchars($p['park_name']) ?></a>
						</div>
						<div class="wx-play-meta">
							<?= implode(' &middot; ', $scheds) ?><?php if ($kshort): ?> &middot; <?= htmlspecialchars($kshort) ?><?php endif; ?>
						</div>
					</div>
					<div class="wx-play-wx">
						<?php if ($fc && $fc['hi_f'] !== null):
							$hiC = round(($fc['hi_f'] - 32) * 5 / 9);
							$loC = $fc['lo_f'] !== null ? round(($fc['lo_f'] - 32) * 5 / 9) : null;
						?>
							<div class="wx-play-temps">
								<?= $wxIcon($fc['code']) ?>
								<?= round($fc['hi_f']) ?>/<?= $hiC ?>°<?php if ($fc['lo_f'] !== null): ?> · L <?= round($fc['lo_f']) ?>/<?= $loC ?>°<?php endif; ?>
							</div>
							<?php if (!empty($p['badges'])): ?>
								<div class="wx-play-meta">
									<?php foreach ($p['badges'] as $b): ?>
										<span class="wx-event-badge wx-event-badge-<?= $b['severity'] ?>" title="<?= htmlspecialchars($b['label']) ?>"><?= $b['icon'] ?></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						<?php else: ?>
							<div class="wx-play-empty"><?= htmlspecialchars($wxStatusText($p['forecast_status'] ?? 'unavailable')) ?></div>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- ── Upcoming Events ─────────────────────────────────── -->
	<div class="wx-events" id="upcoming-events">
		<div class="wx-events-header">
			<h2 id="wx-events-title">📅 Upcoming Events — in the week ahead</h2>
			<span class="wx-events-attr">Weather by <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo</a></span>
		</div>
		<div class="wx-events-body">

		<?php if (empty($events)): ?>
			<div class="wx-empty">No events scheduled in the next 7 days.</div>
		<?php else: ?>
			<?php foreach ($events as $ev):
				$ts = strtotime($ev['event_start']);
				$dayDate = substr($ev['event_start'], 0, 10);
				$today = date('Y-m-d');
				$isToday = ($dayDate === $today);
			?>
			<div class="wx-event"
			     data-event-cd-id="<?= (int)$ev['event_calendardetail_id'] ?>"
			     data-event-start="<?= htmlspecialchars(substr($ev['event_start'], 0, 10)) ?>"
			     data-event-end="<?= htmlspecialchars(substr($ev['event_end'] ?? $ev['event_start'], 0, 10)) ?>"
			     <?php if (!empty($ev['lat']) && !empty($ev['lng'])): ?>data-lat="<?= htmlspecialchars((string)$ev['lat']) ?>" data-lng="<?= htmlspecialchars((string)$ev['lng']) ?>" data-name="<?= htmlspecialchars($ev['name']) ?>"<?php endif; ?>
			     <?php if (!empty($ev['park_id'])): ?>data-park-id="<?= (int)$ev['park_id'] ?>"<?php endif; ?>>
				<div class="wx-event-date">
					<span class="wx-event-month"><?= date('M', $ts) ?></span>
					<span class="wx-event-day"><?= date('j', $ts) ?></span>
					<span class="wx-event-dow"><?= $isToday ? 'Today' : date('D', $ts) ?></span>
				</div>
				<div class="wx-event-main">
					<div class="wx-event-name">
						<a href="<?= UIR ?>Event/detail/<?= (int)$ev['event_id'] ?>/<?= (int)$ev['event_calendardetail_id'] ?>" class="wx-jump" target="_blank" rel="noopener">
							<?= htmlspecialchars($ev['name']) ?>
						</a>
					</div>
					<div class="wx-event-loc">
						<?php $kingdomShort = Weather::short_kingdom($ev['kingdom_name'] ?? '');
						      $parts = array();
						      $endDate = substr($ev['event_end'] ?? $ev['event_start'], 0, 10);
						      $tsEnd = strtotime($endDate);
						      if ($endDate > $dayDate) {
						          $rangeLabel = date('M j', $ts) . '–' . (
						              date('n', $ts) === date('n', $tsEnd) ? date('j', $tsEnd) : date('M j', $tsEnd)
						          );
						      } else {
						          $rangeLabel = date('M j', $ts);
						      }
						      $parts[] = '<span class="wx-event-range">' . $rangeLabel . '</span>';
						      if (!empty($ev['park_id']) && !empty($ev['park_name'])) {
						          $parts[] = '<a href="' . UIR . 'Park/profile/' . (int)$ev['park_id'] . '" class="wx-jump" target="_blank" rel="noopener">' . htmlspecialchars($ev['park_name']) . '</a>';
						      }
						      if ($kingdomShort !== '') $parts[] = htmlspecialchars($kingdomShort);
						      echo implode(' · ', $parts);
						?>
					</div>
				</div>
				<div class="wx-event-wx">
					<?php $fc = $ev['forecast'] ?? null;
					      if ($fc && $fc['hi_f'] !== null):
					          $hiC = round(($fc['hi_f'] - 32) * 5 / 9);
					          $loC = $fc['lo_f'] !== null ? round(($fc['lo_f'] - 32) * 5 / 9) : null;
					?>
						<div class="wx-event-temps">
							<?= $wxIcon($fc['code']) ?>
							<?= round($fc['hi_f']) ?>/<?= $hiC ?>°<?php if ($fc['lo_f'] !== null): ?> · L <?= round($fc['lo_f']) ?>/<?= $loC ?>°<?php endif; ?>
						</div>
						<?php if (!empty($fc['precip_pct']) && $fc['precip_pct'] >= 20): ?>
							<div class="wx-event-meta"><?= (int)$fc['precip_pct'] ?>% rain</div>
						<?php endif; ?>
						<?php if (!empty($ev['badges'])): ?>
							<div class="wx-event-meta">
								<?php foreach ($ev['badges'] as $b): ?>
									<span class="wx-event-badge wx-event-badge-<?= $b['severity'] ?>" title="<?= htmlspecialchars($b['label']) ?>"><?= $b['icon'] ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php else: ?>
						<div class="wx-event-empty">forecast unavailable</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
		</div>
	</div>

	</div><!-- /.wx-lists -->

</div>

<script>
(function() {
	var UIR = '<?= UIR ?>';
	// Server-computed today in the server's local timezone — use this wherever
	// JS code needs to know "is this date today?". JS's new Date().toISOString()
	// returns UTC and will be off by one for users west of UTC late at night.
	var WX_TODAY = '<?= htmlspecialchars(date('Y-m-d')) ?>';
	var wxIcon = function(c) {
		c = +c;
		if (c === 0)                  return '☀️';
		if (c === 1)                  return '🌤️';
		if (c === 2)                  return '⛅';
		if (c === 3)                  return '☁️';
		if (c === 45 || c === 48)     return '🌫️';
		if (c >= 51 && c <= 57)       return '🌦️';
		if (c >= 61 && c <= 67)       return '🌧️';
		if (c >= 71 && c <= 77)       return '❄️';
		if (c >= 80 && c <= 82)       return '🌦️';
		if (c === 85 || c === 86)     return '🌨️';
		if (c >= 95 && c <= 99)       return '⛈️';
		return '🌡️';
	};
	function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	function tempPair(f) { var c = Math.round((f - 32) * 5 / 9); return Math.round(f) + '/' + c + '°'; }

	// Kingdom-name shortener mirrors Weather::short_kingdom in PHP
	function shortKingdom(name) {
		if (!name) return '';
		if (/Freeholds/i.test(name)) return '';
		var m = name.match(/^The (.+ Kingdom)$/i);
		if (m) return 'the ' + m[1];
		return name.replace(/^The /i, '').replace(/^(Kingdom|Empire|Principality) of /i, '');
	}

	function rollupKingdoms(map, max) {
		max = max || 5;
		if (!map) return '';
		var arr = Object.keys(map).map(function(k) { return [k, map[k]]; });
		arr.sort(function(a, b) { return b[1] - a[1]; });
		arr = arr.slice(0, max);
		var names = arr.map(function(e) { return shortKingdom(e[0]); }).filter(function(s) { return s; });
		if (names.length === 0) return '';
		if (names.length === 1) return names[0];
		if (names.length === 2) return names[0] + ' and ' + names[1];
		return names.slice(0, -1).join(', ') + ', and ' + names[names.length - 1];
	}

	// JS mirror of $wxKingdomPhrase — see PHP comment.
	function kingdomPhrase(n, map, multiPrefix) {
		if (!map) return '';
		var names = rollupKingdoms(map);
		if (!names) return '';
		var kc = 0;
		for (var k in map) {
			if (Object.prototype.hasOwnProperty.call(map, k) && shortKingdom(k) !== '') kc++;
		}
		if (n === 1 || kc === 1) return ' in ' + names;
		return multiPrefix + names;
	}

	function renderRundown(r, dayLabel) {
		var bc = r.badge_counts || {}, bk = r.badge_kingdoms || {};
		var standout = r.standout_park || null;
		var leadParts = [];
		var noPlay = r.no_play_today;
		var noFlags = Object.keys(bc).length === 0;
		if (noPlay) {
			leadParts.push('No parks have in-person play scheduled for ' + dayLabel + '.');
		} else if (noFlags) {
			leadParts.push('Of the parks playing ' + dayLabel + ', conditions look favorable across the board — no warnings flagged.');
		} else {
			if (bc['Thunderstorms']) {
				var n = bc['Thunderstorms'];
				var suffix = kingdomPhrase(n, bk['Thunderstorms'], ' — primarily in ');
				leadParts.push('Of parks playing ' + dayLabel + ', ' + n + ' ' + (n === 1 ? 'faces' : 'face') + ' thunderstorms' + suffix + '.');
			}
			if (bc['Extreme heat']) {
				var n = bc['Extreme heat'];
				leadParts.push((n === 1 ? 'One park playing ' + dayLabel + ' is' : n + ' parks playing ' + dayLabel + ' are') + ' facing extreme heat.');
			}
			if (bc['Frostbite risk']) {
				var n = bc['Frostbite risk'];
				leadParts.push((n === 1 ? 'One park playing ' + dayLabel + ' is' : n + ' parks playing ' + dayLabel + ' are') + ' under frostbite-risk conditions.');
			}
			if (bc['Severe wind']) {
				var n = bc['Severe wind'];
				var suffix = kingdomPhrase(n, bk['Severe wind'], ', across ');
				leadParts.push('Severe wind gusts (40+ mph / 65+ km/h) at ' + n + ' ' + (n === 1 ? 'park' : 'parks') + ' playing ' + dayLabel + suffix + '.');
			}
			if (bc['Very high UV']) {
				var n = bc['Very high UV'];
				var suffix = kingdomPhrase(n, bk['Very high UV'], ' in ');
				leadParts.push('Very high UV at ' + n + ' ' + (n === 1 ? 'park' : 'parks') + ' playing ' + dayLabel + suffix + '.');
			}
		}

		var standoutLine = '';
		if (standout) {
			var icons = { 'Extreme heat':'🥵', 'Frostbite risk':'🥶', 'Thunderstorms':'⛈️', 'Severe wind':'💨', 'Very high UV':'🌞' };
			var pieces = (standout.flags || []).map(function(f) { return (icons[f] || '⚠️') + ' ' + esc(f); });
			standoutLine = '<a href="' + UIR + 'Park/profile/' + standout.park_id + '" class="wx-jump-park" data-park-id="' + standout.park_id + '" target="_blank" rel="noopener">' + esc(standout.name) + '</a> carries the worst forecast — ' + pieces.join(' · ');
			if (standout.app_hi_f != null) {
				standoutLine += ' (' + tempPair(standout.app_hi_f) + ' apparent).';
			} else {
				standoutLine += '.';
			}
		}

		// Coming-up: ALWAYS today-relative — keep this constant even when pills shift.
		// We don't have the event count in the per-day response (we only computed it
		// for today on initial load), so leave the existing paragraph untouched.

		var html = '';
		if (leadParts.length) html += '<p>' + leadParts.join(' ') + '</p>';
		if (standoutLine)     html += '<p>' + standoutLine + '</p>';
		// "N events in the week ahead" — always relative to "now", shows on
		// every day pill so users see upcoming event concerns regardless of
		// which day they're inspecting.
		if (window.WX_COMING_UP_HTML) {
			html += window.WX_COMING_UP_HTML;
		}
		document.getElementById('wx-rundown-body').innerHTML = html;
	}

	// Severity tier from the highest-severity badge — drives map marker color.
	function rowTier(p) {
		if (!p.forecast || p.forecast.hi_f == null) return 'none';
		if (p.badges && p.badges.length) {
			for (var i = 0; i < p.badges.length; i++) {
				if (p.badges[i].severity === 'warning') return 'warning';
			}
			return 'caution';
		}
		return 'favorable';
	}
	var TIER_COLOR = { warning: '#e53e3e', caution: '#dd6b20', favorable: '#48bb78', none: '#a0aec0' };
	var TIER_ORDER = { warning: 0, caution: 1, favorable: 2, none: 3 };
	function statusText(s) {
		if (s === 'dormant')     return 'no recent activity — weather not tracked';
		if (s === 'no_coords')   return 'park location not set';
		return 'forecast unavailable';
	}

	var wxMap = null, wxTileLayer = null, wxMarkers = [], wxMarkersByPark = {};
	var wxTempMarker = null; // ephemeral marker for event-only venues (no park marker)
	var wxBounds = null;    // last fitted bounds — Esc flies back to this
	var wxZoomedIn = false; // true while user is zoomed into a specific park/event
	var wxOpeningPopup = false; // true in the instant before openPopup fires, so
	                            // popupclose can tell "replaced by another" vs "× clicked"
	var wxFitting = false;  // true during programmatic flyToBounds/fitBounds — suppresses
	                        // the zoomend listener so it doesn't re-show the button
	function setZoomedIn(v) {
		wxZoomedIn = v;
		var btn = document.getElementById('wx-zoom-out');
		if (btn) btn.classList.toggle('visible', !!v);
	}
	function fitWxMap() {
		if (wxMap && wxBounds) {
			wxFitting = true;
			wxMap.flyToBounds(wxBounds, { padding: [30, 30], maxZoom: 9, duration: 0.6 });
			wxMap.once('moveend', function() { wxFitting = false; });
		}
		setZoomedIn(false);
	}
	function isDarkTheme() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
	function wxApplyMapTheme() {
		if (!wxMap) return;
		var url = isDarkTheme()
			? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
			: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
		if (wxTileLayer) wxMap.removeLayer(wxTileLayer);
		wxTileLayer = L.tileLayer(url, { maxZoom: 19, subdomains: 'abcd' }).addTo(wxMap);
	}
	function ensureMap() {
		if (wxMap || typeof L === 'undefined') return wxMap;
		wxMap = L.map('wx-map', { zoomControl: true, attributionControl: false }).setView([39.5, -98.35], 4);
		wxApplyMapTheme();
		new MutationObserver(wxApplyMapTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
		onMapReady();
		return wxMap;
	}
	function renderMap(rows, dayLabel) {
		var shortDay = dayLabel === 'today' ? 'Today' : dayLabel.slice(0,1).toUpperCase() + dayLabel.slice(1,3);
		document.getElementById('wx-map-title').textContent = '🗺️ Conditions Map — ' + shortDay;
		if (!ensureMap()) return;
		// Close any open popup and reset zoom state when the user switches dates.
		wxMap.closePopup();
		setZoomedIn(false);
		// Clear old markers
		wxMarkers.forEach(function(m) { wxMap.removeLayer(m); });
		wxMarkers = [];
		wxMarkersByPark = {};
		if (wxTempMarker) { wxMap.removeLayer(wxTempMarker); wxTempMarker = null; }
		// Place worst tiers on top by sorting ascending by TIER_ORDER then adding in order
		// (Leaflet stacks later-added markers above earlier ones)
		var plot = rows.filter(function(p) { return p.lat != null && p.lng != null; })
			.slice()
			.sort(function(a, b) { return TIER_ORDER[rowTier(b)] - TIER_ORDER[rowTier(a)]; });
		var bounds = [];
		plot.forEach(function(p) {
			var tier = rowTier(p);
			var color = TIER_COLOR[tier];
			var marker = L.circleMarker([p.lat, p.lng], {
				radius: tier === 'warning' ? 8 : (tier === 'caution' ? 7 : 6),
				color: color, weight: 1, fillColor: color,
				fillOpacity: tier === 'none' ? 0.4 : 0.8
			}).addTo(wxMap);
			var fc = p.forecast;
			var wxLine = '';
			if (fc && fc.hi_f != null) {
				var loPart = fc.lo_f != null ? ' / L ' + tempPair(fc.lo_f) : '';
				wxLine = '<div class="wx-pp-wx">' + wxIcon(fc.code) + ' ' + tempPair(fc.hi_f) + loPart + '</div>';
				wxLine += renderBadgeChips(p.badges);
			} else {
				wxLine = '<div class="wx-pp-meta">' + esc(statusText(p.forecast_status)) + '</div>';
			}
			var k = shortKingdom(p.kingdom_name || '');
			marker.bindPopup(
				'<div class="wx-map-popup">' +
					'<div class="wx-pp-name"><a href="' + UIR + 'Park/profile/' + p.park_id + '" target="_blank" rel="noopener">' + esc(p.park_name) + '</a></div>' +
					(k ? '<div class="wx-pp-meta">' + esc(k) + '</div>' : '') +
					wxLine +
				'</div>'
			);
			var tip = p.park_name;
			if (tier === 'none') tip += ' — ' + statusText(p.forecast_status);
			marker.bindTooltip(tip, { direction: 'top', opacity: 0.9 });
			wxMarkers.push(marker);
			wxMarkersByPark[p.park_id] = marker;
			bounds.push([p.lat, p.lng]);
		});
		if (bounds.length) {
			wxBounds = bounds;
			wxFitting = true;
			wxMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 9 });
			wxMap.once('moveend', function() { wxFitting = false; });
		}
		setTimeout(function() { wxMap.invalidateSize(); }, 50);
	}

	function renderPlay(rows, dayLabel) {
		var shortDay = dayLabel === 'today' ? 'Today' : dayLabel.slice(0,1).toUpperCase() + dayLabel.slice(1,3);
		document.getElementById('wx-play-title').textContent = '🏕️ Play ' + shortDay;
		var body = document.getElementById('wx-play-body');
		if (!rows.length) {
			body.innerHTML = '<div class="wx-empty">No in-person play scheduled.</div>';
			document.getElementById('wx-play-count').textContent = '0 parks';
			ensurePlayToggle(0, 0);
			return;
		}
		var flaggedCount = 0;
		rows.forEach(function(p) { if (p.badges && p.badges.length) flaggedCount++; });
		// Reset the toggle to the default mode for the new day: flagged when
		// any exist, otherwise all. Re-create the button via ensurePlayToggle.
		var existingBtn = document.getElementById('wx-play-toggle');
		if (existingBtn) existingBtn.remove();
		var html = '';
		rows.forEach(function(p, i) {
			var k = shortKingdom(p.kingdom_name || '');
			var fc = p.forecast || null;
			var scheds = (p.schedules || []).map(function(s) {
				var t = '';
				if (s.time) {
					var parts = s.time.split(':');
					var d = new Date(); d.setHours(+parts[0], +parts[1] || 0, 0, 0);
					t = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
				}
				return esc(s.purpose) + (t ? ' at ' + t : '');
			});
			var wx = '';
			if (fc && fc.hi_f != null) {
				var loPart = fc.lo_f != null ? ' · L ' + tempPair(fc.lo_f) : '';
				wx = '<div class="wx-play-temps">' + wxIcon(fc.code) + ' ' + tempPair(fc.hi_f) + loPart + '</div>';
				if (p.badges && p.badges.length) {
					wx += '<div class="wx-play-meta">' +
						p.badges.map(function(b) { return '<span class="wx-event-badge wx-event-badge-' + b.severity + '" title="' + esc(b.label) + '">' + b.icon + '</span>'; }).join(' ') +
						'</div>';
				}
			} else {
				wx = '<div class="wx-play-empty">' + esc(statusText(p.forecast_status)) + '</div>';
			}
			var coordAttrs = (p.lat != null && p.lng != null)
				? ' data-lat="' + p.lat + '" data-lng="' + p.lng + '"' : '';
			var sev = +p._score || 0;
			var nameAttr = (p.park_name || '').toLowerCase();
			var flag = (p.badges && p.badges.length) ? '1' : '0';
			html += '<div class="wx-play-row" data-park-id="' + p.park_id + '"' +
				' data-park-name="' + esc(nameAttr) + '" data-severity="' + sev + '"' +
				' data-flagged="' + flag + '"' + coordAttrs + '>' +
				'<div class="wx-play-main">' +
					'<div class="wx-play-park"><a href="' + UIR + 'Park/profile/' + p.park_id + '" class="wx-jump" target="_blank" rel="noopener">' + esc(p.park_name) + '</a></div>' +
					'<div class="wx-play-meta">' + scheds.join(' &middot; ') + (k ? ' &middot; ' + esc(k) : '') + '</div>' +
				'</div>' +
				'<div class="wx-play-wx">' + wx + '</div>' +
			'</div>';
		});
		body.innerHTML = html;
		ensurePlayToggle(flaggedCount, rows.length);
		applyPlayState();
	}

	// Label the toggle based on the current mode + whether toggling makes sense.
	// Modes:
	//   "flagged" → show only parks with badges, severity-sorted. Default when
	//               any flagged parks exist.
	//   "all"     → show every park, alphabetical. Default when nothing's flagged.
	// Button only appears when both modes would show different content (i.e.
	// at least one flagged AND at least one unflagged).
	function labelToggle(btn, flaggedCount, total) {
		if (!btn) return;
		var mode = btn.dataset.mode || 'flagged';
		var hasUnflagged = flaggedCount < total;
		if (mode === 'flagged') {
			// Toggle would either reveal unflagged rows OR just re-sort.
			btn.textContent = hasUnflagged ? 'Show all ' + total : 'A–Z';
		} else {
			btn.textContent = hasUnflagged ? 'Show concerns only' : 'By severity';
		}
		btn.classList.toggle('active', mode === 'all');
	}

	function ensurePlayToggle(flaggedCount, total) {
		var controls = document.querySelector('#wx-play .wx-play-controls');
		if (!controls) return;
		var existing = document.getElementById('wx-play-toggle');
		// Hide toggle only when there's nothing flagged — in that case the
		// flagged mode would be empty, so default 'all' (alphabetical) and
		// drop the button.
		if (flaggedCount <= 0) {
			if (existing) existing.remove();
			return;
		}
		if (!existing) {
			existing = document.createElement('button');
			existing.id = 'wx-play-toggle';
			existing.className = 'wx-play-toggle';
			existing.dataset.mode = 'flagged';
			controls.appendChild(existing);
			existing.addEventListener('click', function() {
				existing.dataset.mode = existing.dataset.mode === 'flagged' ? 'all' : 'flagged';
				var rows = document.querySelectorAll('#wx-play-body .wx-play-row');
				var f = 0;
				rows.forEach(function(r) { if (r.dataset.flagged === '1') f++; });
				labelToggle(existing, f, rows.length);
				applyPlayState();
				var body = document.getElementById('wx-play-body');
				if (body) body.scrollTop = 0;
			});
		}
		labelToggle(existing, flaggedCount, total);
	}

	// Single source of truth for play-list visibility/order.
	//   - "flagged" mode (no search): severity-sorted, only data-flagged="1" visible
	//   - "all" mode OR any search: alphabetical, every matching row visible
	function applyPlayState() {
		var body = document.getElementById('wx-play-body');
		if (!body) return;
		var rows = Array.prototype.slice.call(body.querySelectorAll('.wx-play-row'));
		if (!rows.length) return;
		var searchEl = document.getElementById('wx-play-search');
		var query = (searchEl && searchEl.value || '').trim().toLowerCase();
		var toggle = document.getElementById('wx-play-toggle');
		// Default to 'all' when no toggle present (nothing flagged), 'flagged' otherwise.
		var mode = toggle ? toggle.dataset.mode : 'all';
		// Search always shows everything matching, alphabetically — overrides mode.
		var effectiveMode = query ? 'all' : mode;

		rows.sort(function(a, b) {
			if (effectiveMode === 'all') {
				return (a.dataset.parkName || '').localeCompare(b.dataset.parkName || '');
			}
			var ds = (+b.dataset.severity || 0) - (+a.dataset.severity || 0);
			if (ds !== 0) return ds;
			return (a.dataset.parkName || '').localeCompare(b.dataset.parkName || '');
		});
		rows.forEach(function(r) { body.appendChild(r); });

		var flaggedTotal = 0, shown = 0;
		rows.forEach(function(r) {
			if (r.dataset.flagged === '1') flaggedTotal++;
			r.classList.remove('wx-hidden', 'wx-filtered');
			var name = r.dataset.parkName || '';
			var matchesQuery = !query || name.indexOf(query) !== -1;
			if (!matchesQuery) { r.classList.add('wx-filtered'); return; }
			if (effectiveMode === 'flagged' && r.dataset.flagged !== '1') {
				r.classList.add('wx-hidden');
				return;
			}
			shown++;
		});

		// Count: "5 of 162" when filtered, "162 parks" when all, "3 / 162" while searching.
		var countEl = document.getElementById('wx-play-count');
		if (countEl) {
			if (query)                          countEl.textContent = shown + ' / ' + rows.length;
			else if (effectiveMode === 'flagged') countEl.textContent = shown + ' of ' + rows.length;
			else                                countEl.textContent = rows.length + (rows.length === 1 ? ' park' : ' parks');
		}

		// Banner: only visible in flagged mode (not while searching either —
		// search results aren't a "weather concerns" filter).
		var card = document.getElementById('wx-play');
		if (card) card.classList.toggle('is-flagged-mode', effectiveMode === 'flagged');

		// Toggle button: invisible (but space reserved) while searching. The
		// search already shows "all matching parks" regardless of mode, so the
		// button would either be a no-op or set stale state for when the
		// search is cleared. Reserving space prevents header reflow.
		if (toggle) toggle.classList.toggle('wx-invisible', !!query);
	}

	// Filter the events list to only show events that haven't ended before the
	// selected date. Multi-day events remain visible as long as their end date
	// is on or after the selected date.
	function applyEventsFilter(date) {
		var isToday = (date === WX_TODAY);
		var rows = document.querySelectorAll('#upcoming-events .wx-event');
		var shown = 0;
		rows.forEach(function(r) {
			var end = r.dataset.eventEnd || r.dataset.eventStart || '';
			var visible = !end || end >= date;
			r.classList.toggle('wx-hidden', !visible);
			if (visible) shown++;
		});
		var title = document.getElementById('wx-events-title');
		if (title) {
			if (isToday) {
				title.textContent = '📅 Upcoming Events — in the week ahead';
			} else {
				var d = new Date(date + 'T00:00:00');
				var lbl = d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
				title.textContent = '📅 Upcoming Events — from ' + lbl;
			}
		}
		var body = document.querySelector('#upcoming-events .wx-events-body');
		if (body) {
			var emptyEl = body.querySelector('.wx-events-empty-state');
			if (shown === 0 && rows.length > 0) {
				if (!emptyEl) {
					emptyEl = document.createElement('div');
					emptyEl.className = 'wx-empty wx-events-empty-state';
					body.appendChild(emptyEl);
				}
				emptyEl.textContent = 'No events on or after this date.';
			} else if (emptyEl) {
				emptyEl.remove();
			}
		}
	}

	function activatePill(date) {
		document.querySelectorAll('.wx-pill').forEach(function(b) {
			b.classList.toggle('active', b.dataset.date === date);
		});
		document.querySelector('.wx-root').dataset.selectedDate = date;
	}

	function fetchAndRender(date, dayLabel) {
		document.getElementById('wx-play-body').innerHTML = '<div class="wx-loading">Loading…</div>';
		fetch(UIR + 'Weather/day/' + date, { credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (!d || d.status !== 0) {
					document.getElementById('wx-play-body').innerHTML = '<div class="wx-empty">Could not load weather.</div>';
					return;
				}
				renderRundown(d.rundown || {}, dayLabel);
				renderMap(d.play || [], dayLabel);
				renderPlay(d.play || [], dayLabel);
			})
			.catch(function() {
				document.getElementById('wx-play-body').innerHTML = '<div class="wx-empty">Request failed.</div>';
			});
	}

	document.querySelectorAll('.wx-pill').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var date = this.dataset.date;
			var dayLabel = (date === WX_TODAY) ? 'today' :
				new Date(date + 'T00:00:00').toLocaleDateString([], { weekday: 'long' });
			activatePill(date);
			applyEventsFilter(date);
			fetchAndRender(date, dayLabel);
		});
	});

	// Stash the server-rendered "coming up" paragraph so we can re-insert it
	// when the user comes back to Today after viewing another day.
	(function() {
		var p = document.querySelector('#wx-rundown-body p:last-child');
		if (p && p.querySelector('a[href="#upcoming-events"]')) {
			window.WX_COMING_UP_HTML = p.outerHTML;
		}
	})();

	// Wire the server-rendered header toggle (initial page load — today's data)
	(function() {
		var initialToggle = document.getElementById('wx-play-toggle');
		if (initialToggle) {
			initialToggle.addEventListener('click', function() {
				initialToggle.dataset.mode = initialToggle.dataset.mode === 'flagged' ? 'all' : 'flagged';
				var rows = document.querySelectorAll('#wx-play-body .wx-play-row');
				var f = 0;
				rows.forEach(function(r) { if (r.dataset.flagged === '1') f++; });
				labelToggle(initialToggle, f, rows.length);
				applyPlayState();
				var body = document.getElementById('wx-play-body');
				if (body) body.scrollTop = 0;
			});
		}
		// Search input: debounced filter
		var search = document.getElementById('wx-play-search');
		if (search) {
			var timer = null;
			search.addEventListener('input', function() {
				clearTimeout(timer);
				timer = setTimeout(applyPlayState, 80);
			});
		}
		// Banner "Show all parks" — delegates to the header toggle so a single
		// state owns the mode.
		var modeClear = document.getElementById('wx-play-mode-clear');
		if (modeClear) {
			modeClear.addEventListener('click', function() {
				var t = document.getElementById('wx-play-toggle');
				if (t) t.click();
			});
		}
		// Normalize count text + banner visibility on first paint
		applyPlayState();
		// Apply event date filter for the initially selected date (today on first
		// load, but correct if someone navigates back with a stale pill state).
		applyEventsFilter(<?= json_encode($selected) ?>);
	})();

	// Esc: close any open popup and fly back to the full-map view.
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Escape') return;
		if (e.target.closest('input, textarea, [contenteditable]')) return;
		if (wxMap) wxMap.closePopup();
		fitWxMap();
	});

	// "Zoom out" button in the map header — same as Esc.
	document.getElementById('wx-zoom-out').addEventListener('click', function() {
		if (wxMap) wxMap.closePopup();
		fitWxMap();
	});

	// Closing a popup via its × button also zooms back out (only when we
	// triggered the zoom — wxZoomedIn tracks this).
	function onMapReady() {
		// Clear the flag once the new popup is actually open.
		wxMap.on('popupopen', function() { wxOpeningPopup = false; });
		// Only zoom out when the popup was closed by the user (× or Esc), not
		// when it was replaced by another popup (wxOpeningPopup is true then).
		wxMap.on('popupclose', function() {
			if (wxZoomedIn && !wxOpeningPopup) fitWxMap();
		});
		// Show the "zoom out" button whenever the user manually zooms or pans.
		// wxFitting suppresses this during our own programmatic flyToBounds calls.
		wxMap.on('zoomend dragend', function() {
			if (!wxFitting && wxBounds) setZoomedIn(true);
		});
	}

	// Make Leaflet recompute size after the flex layout settles, and on resize.
	window.addEventListener('resize', function() {
		if (wxMap) wxMap.invalidateSize();
	});

	// Render badges as the same colored chips used in the list rows — same
	// emoji, label visible (since the popup has room), severity-tinted background.
	function renderBadgeChips(badges) {
		if (!badges || !badges.length) return '';
		return '<div class="wx-pp-badges">' + badges.map(function(b) {
			return '<span class="wx-event-badge wx-event-badge-' + b.severity + '">' + b.icon + ' ' + esc(b.label) + '</span>';
		}).join('') + '</div>';
	}

	// Build the same popup body the map markers use, for an event jumped to
	// without a corresponding park marker. Same .wx-map-popup classes →
	// matches the styling, dark-mode rules, etc.
	function buildEventPopup(ev) {
		var fc = ev.forecast;
		var wxLine = '';
		if (fc && fc.hi_f != null) {
			var loPart = fc.lo_f != null ? ' / L ' + tempPair(fc.lo_f) : '';
			wxLine = '<div class="wx-pp-wx">' + wxIcon(fc.code) + ' ' + tempPair(fc.hi_f) + loPart + '</div>';
			wxLine += renderBadgeChips(ev.badges);
		} else {
			wxLine = '<div class="wx-pp-meta">forecast unavailable</div>';
		}
		var parts = [];
		if (ev.park_id && ev.park_name) {
			parts.push('<a href="' + UIR + 'Park/profile/' + ev.park_id + '" target="_blank" rel="noopener">' + esc(ev.park_name) + '</a>');
		}
		var k = shortKingdom(ev.kingdom_name || '');
		if (k) parts.push(esc(k));
		var locLine = parts.length ? '<div class="wx-pp-meta">' + parts.join(' · ') + '</div>' : '';
		var dateLine = '';
		var startStr = ev.event_start ? ev.event_start.slice(0, 10) : '';
		var endStr   = ev.event_end   ? ev.event_end.slice(0, 10)   : startStr;
		if (startStr) {
			var dtS = new Date(startStr + 'T00:00:00');
			if (!isNaN(dtS)) {
				var startLabel = dtS.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
				if (endStr && endStr > startStr) {
					var dtE = new Date(endStr + 'T00:00:00');
					var endLabel = !isNaN(dtE) ? dtE.toLocaleDateString([], { month: 'short', day: 'numeric' }) : '';
					dateLine = '<div class="wx-pp-meta">' + esc(startLabel) + (endLabel ? ' – ' + esc(endLabel) : '') + '</div>';
				} else {
					dateLine = '<div class="wx-pp-meta">' + esc(startLabel) + '</div>';
				}
			}
		}
		return '<div class="wx-map-popup">' +
			'<div class="wx-pp-name"><a href="' + UIR + 'Event/detail/' + ev.event_id + '/' + ev.event_calendardetail_id + '" target="_blank" rel="noopener">' + esc(ev.name) + '</a></div>' +
			dateLine + locLine + wxLine +
		'</div>';
	}

	// Map-jump: clicking a park/event row (or its link) zooms the map instead
	// of navigating away. Modifier-key clicks still follow the link so users
	// can open the profile in a new tab if they want.
	function flyToPark(parkId) {
		var pid = parseInt(parkId, 10);
		var marker = pid ? wxMarkersByPark[pid] : null;
		if (!marker || !wxMap) return false;
		var ll = marker.getLatLng();
		wxMap.flyTo([ll.lat, ll.lng], Math.max(wxMap.getZoom(), 9), { duration: 0.6 });
		setZoomedIn(true);
		setTimeout(function() { wxOpeningPopup = true; marker.openPopup(); }, 650);
		return true;
	}

	function flyToEvent(ev) {
		if (!wxMap || !ev) return false;
		// Anchor the event popup to the host park's marker when that park is
		// playing today (no duplicate pin), otherwise drop a blue marker at
		// the event's own coords (cd.lat/lng or at_park).
		var hostMarker = ev.park_id ? wxMarkersByPark[ev.park_id] : null;
		var ll;
		if (hostMarker) {
			var p = hostMarker.getLatLng();
			ll = [p.lat, p.lng];
		} else if (ev.lat != null && ev.lng != null) {
			ll = [+ev.lat, +ev.lng];
		} else {
			return false;
		}
		wxMap.flyTo(ll, Math.max(wxMap.getZoom(), 9), { duration: 0.6 });
		setZoomedIn(true);
		if (wxTempMarker) { wxMap.removeLayer(wxTempMarker); wxTempMarker = null; }
		var popup = L.popup({ offset: hostMarker ? [0, -8] : [0, 0] })
			.setLatLng(ll)
			.setContent(buildEventPopup(ev));
		if (!hostMarker) {
			// Visual anchor for event-only venues
			wxTempMarker = L.circleMarker(ll, {
				radius: 9, color: '#3182ce', weight: 2, fillColor: '#3182ce', fillOpacity: 0.6
			}).addTo(wxMap);
		}
		setTimeout(function() { wxOpeningPopup = true; popup.openOn(wxMap); }, 650);
		return true;
	}

	function jumpClickHandler(e) {
		// Let modifier-clicks fall through (open in new tab etc.)
		if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

		// Rundown park link (top-of-page summary)
		var jumpLink = e.target.closest('.wx-jump-park');
		if (jumpLink && jumpLink.dataset.parkId) {
			if (flyToPark(jumpLink.dataset.parkId)) {
				e.preventDefault();
				e.stopPropagation();
			}
			return;
		}

		var row = e.target.closest('.wx-play-row, .wx-event');
		if (!row || !row.closest('.wx-root')) return;
		var handled = false;
		if (row.classList.contains('wx-event')) {
			var cd = parseInt(row.dataset.eventCdId || '0', 10);
			var ev = cd && window.WX_EVENTS ? window.WX_EVENTS[cd] : null;
			if (ev) handled = flyToEvent(ev);
		} else {
			handled = flyToPark(row.dataset.parkId);
		}
		if (handled) {
			e.preventDefault();
			e.stopPropagation();
			var mapEl = document.getElementById('wx-map');
			if (mapEl) {
				var r = mapEl.getBoundingClientRect();
				if (r.top < 0 || r.bottom > window.innerHeight) {
					mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			}
		}
	}
	document.querySelector('.wx-root').addEventListener('click', jumpClickHandler);

	// Render the map for the initial date using the server-supplied play list
	function whenLeafletReady(cb) {
		if (typeof L !== 'undefined') return cb();
		var s = document.querySelector('script[src*="leaflet.js"]');
		if (s) s.addEventListener('load', cb);
		else window.addEventListener('load', cb);
	}
	// Events stay the same across date pills (always next-7-days from now), so
	// dump them once for the click→map-popup flow. Keep as an array (avoids
	// JSON_FORCE_OBJECT recursing into badges) and build the cd_id lookup in JS.
	(function() {
		var rows = <?= json_encode(array_map(function($ev) {
			return array(
				'event_id'                => (int)$ev['event_id'],
				'event_calendardetail_id' => (int)$ev['event_calendardetail_id'],
				'name'                    => $ev['name'],
				'park_id'                 => (int)($ev['park_id'] ?? 0),
				'park_name'               => $ev['park_name'],
				'kingdom_name'            => $ev['kingdom_name'],
				'event_start'             => $ev['event_start'],
				'event_end'               => $ev['event_end'] ?? $ev['event_start'],
				'lat'                     => $ev['lat'],
				'lng'                     => $ev['lng'],
				'forecast'                => $ev['forecast'],
				'badges'                  => $ev['badges'],
			);
		}, $events), JSON_UNESCAPED_SLASHES) ?>;
		window.WX_EVENTS = {};
		rows.forEach(function(r) { window.WX_EVENTS[r.event_calendardetail_id] = r; });
	})();

	whenLeafletReady(function() {
		var initialPlay = <?= json_encode(array_map(function($p) {
			return array(
				'park_id'         => (int)$p['park_id'],
				'park_name'       => $p['park_name'],
				'kingdom_name'    => $p['kingdom_name'],
				'lat'             => $p['lat'],
				'lng'             => $p['lng'],
				'forecast'        => $p['forecast'],
				'forecast_status' => $p['forecast_status'] ?? 'unavailable',
				'badges'          => $p['badges'],
			);
		}, $playToday), JSON_UNESCAPED_SLASHES) ?>;
		renderMap(initialPlay, 'today');
	});
})();
</script>
