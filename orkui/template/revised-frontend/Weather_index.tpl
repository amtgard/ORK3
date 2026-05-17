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
			$kingdoms = $wxRollupKingdoms($bk['Thunderstorms'] ?? array());
			$leadParts[] = "Of parks playing today, $n " . ($n === 1 ? 'faces' : 'face') . ' thunderstorms' .
				($kingdoms ? " — primarily in $kingdoms" : '') . '.';
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
			$kingdoms = $wxRollupKingdoms($bk['Severe wind'] ?? array());
			$leadParts[] = "Severe wind gusts (40+ mph / 65+ km/h) at $n " . ($n === 1 ? 'park' : 'parks') . ' playing today' .
				($kingdoms ? ", across $kingdoms" : '') . '.';
		}
		if (!empty($bc['Very high UV'])) {
			$n = $bc['Very high UV'];
			$kingdoms = $wxRollupKingdoms($bk['Very high UV'] ?? array());
			$leadParts[] = "Very high UV at $n " . ($n === 1 ? 'park' : 'parks') . ' playing today' .
				($kingdoms ? " in $kingdoms" : '') . '.';
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
			' scheduled in the next 7 days</a>.';
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
.wx-root { max-width: 1100px; margin: 0 auto; padding: 16px 12px 40px; }
.wx-rundown { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 18px 22px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
.wx-rundown h2 { margin: 0 0 10px; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.wx-rundown p { margin: 0 0 8px; font-size: 14.5px; line-height: 1.55; color: var(--ork-text, #2d3748); }
.wx-rundown p:last-child { margin-bottom: 0; }
.wx-rundown .wx-jump-park { color: var(--ork-link-bright, #2b6cb0); font-weight: 600; text-decoration: none; border-bottom: 1px dotted currentColor; cursor: pointer; }
.wx-rundown .wx-jump-park:hover { border-bottom-style: solid; }
.wx-rundown .wx-events-link { color: var(--ork-link-bright, #2b6cb0); text-decoration: none; border-bottom: 1px dotted currentColor; }
.wx-rundown .wx-events-link::after { content: ' ↓'; opacity: .6; }
.wx-rundown .wx-events-link:hover { border-bottom-style: solid; }

.wx-events { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 14px 18px; }
.wx-events-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--ork-border, #e2e8f0); }
.wx-events-header h2 { margin: 0; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.wx-events-header .wx-events-attr { font-size: 11px; color: var(--ork-text-muted, #a0aec0); }
.wx-events-header .wx-events-attr a { color: inherit; text-decoration: none; }
.wx-events-header .wx-events-attr a:hover { color: var(--ork-link); }

.wx-event { display: grid; grid-template-columns: 70px 1fr auto; gap: 14px; padding: 10px 0; border-bottom: 1px solid var(--ork-border, #edf2f7); align-items: center; }
.wx-event:last-child { border-bottom: 0; }
.wx-event-date { font-size: 12px; color: var(--ork-text-muted, #718096); font-variant-numeric: tabular-nums; text-align: center; }
.wx-event-date .wx-event-month { display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
.wx-event-date .wx-event-day   { display: block; font-size: 20px; font-weight: 700; color: var(--ork-text, #2d3748); line-height: 1.1; }
.wx-event-date .wx-event-dow   { display: block; font-size: 10px; }
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
.wx-strip { display: flex; gap: 6px; margin: 20px 0 14px; flex-wrap: wrap; }
.wx-pill { flex: 1 1 0; min-width: 70px; padding: 8px 10px; border-radius: 8px; background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); cursor: pointer; text-align: center; transition: background .12s, border-color .12s, transform .12s; }
.wx-pill:hover { background: var(--ork-bg-secondary, #f7fafc); }
.wx-pill.active { border-color: var(--ork-link-bright, #2b6cb0); background: var(--ork-link-bright, #2b6cb0); color: #fff; }
.wx-pill.active .wx-pill-date { color: rgba(255,255,255,0.85); }
.wx-pill-day { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.wx-pill-date { font-size: 11px; color: var(--ork-text-muted, #718096); margin-top: 2px; }

/* Play list */
.wx-play { background: var(--ork-card-bg, #fff); border: 1px solid var(--ork-border, #e2e8f0); border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; }
.wx-play-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--ork-border, #e2e8f0); }
.wx-play-header h2 { margin: 0; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--ork-text-muted, #718096); background: none; border: none; padding: 0; border-radius: 0; text-shadow: none; box-shadow: none; }
.wx-play-count { font-size: 11px; color: var(--ork-text-muted, #a0aec0); }
.wx-play-row { display: grid; grid-template-columns: 1fr auto; gap: 14px; padding: 9px 0; border-bottom: 1px solid var(--ork-border, #edf2f7); align-items: center; }
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
</style>

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
			</button>
		<?php endforeach; ?>
	</div>

	<!-- ── Play list (for selected day) ───────────────────── -->
	<div class="wx-play" id="wx-play">
		<div class="wx-play-header">
			<h2 id="wx-play-title">🏕️ Play Today</h2>
			<span class="wx-play-count" id="wx-play-count"><?= count($playToday) ?> parks</span>
		</div>
		<div id="wx-play-body">
			<?php if (empty($playToday)): ?>
				<div class="wx-empty">No in-person play scheduled.</div>
			<?php else: ?>
				<?php foreach ($playToday as $p):
					$fc = $p['forecast'] ?? null;
					$kshort = Weather::short_kingdom($p['kingdom_name'] ?? '');
					$scheds = array();
					foreach ($p['schedules'] as $s) {
						$t = $s['time'] ? date('g:i A', strtotime($s['time'])) : '';
						$scheds[] = htmlspecialchars($s['purpose']) . ($t ? " at $t" : '');
					}
				?>
				<div class="wx-play-row">
					<div class="wx-play-main">
						<div class="wx-play-park">
							<a href="<?= UIR ?>Park/profile/<?= (int)$p['park_id'] ?>"><?= htmlspecialchars($p['park_name']) ?></a>
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
							<div class="wx-play-empty">forecast unavailable</div>
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
			<h2>📅 Upcoming Events — next 7 days</h2>
			<span class="wx-events-attr">Weather by <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo</a></span>
		</div>

		<?php if (empty($events)): ?>
			<div class="wx-empty">No events scheduled in the next 7 days.</div>
		<?php else: ?>
			<?php foreach ($events as $ev):
				$ts = strtotime($ev['event_start']);
				$dayDate = substr($ev['event_start'], 0, 10);
				$today = date('Y-m-d');
				$isToday = ($dayDate === $today);
			?>
			<div class="wx-event">
				<div class="wx-event-date">
					<span class="wx-event-month"><?= date('M', $ts) ?></span>
					<span class="wx-event-day"><?= date('j', $ts) ?></span>
					<span class="wx-event-dow"><?= $isToday ? 'Today' : date('D', $ts) ?></span>
				</div>
				<div class="wx-event-main">
					<div class="wx-event-name">
						<a href="<?= UIR ?>Event/detail/<?= (int)$ev['event_id'] ?>/<?= (int)$ev['event_calendardetail_id'] ?>">
							<?= htmlspecialchars($ev['name']) ?>
						</a>
					</div>
					<div class="wx-event-loc">
						<?php $kingdomShort = Weather::short_kingdom($ev['kingdom_name'] ?? '');
						      $parts = array();
						      if (!empty($ev['park_id']) && !empty($ev['park_name'])) {
						          $parts[] = '<a href="' . UIR . 'Park/profile/' . (int)$ev['park_id'] . '">' . htmlspecialchars($ev['park_name']) . '</a>';
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

<script>
(function() {
	var UIR = '<?= UIR ?>';
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
				var n = bc['Thunderstorms'], k = rollupKingdoms(bk['Thunderstorms']);
				leadParts.push('Of parks playing ' + dayLabel + ', ' + n + ' ' + (n === 1 ? 'faces' : 'face') + ' thunderstorms' + (k ? ' — primarily in ' + k : '') + '.');
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
				var n = bc['Severe wind'], k = rollupKingdoms(bk['Severe wind']);
				leadParts.push('Severe wind gusts (40+ mph / 65+ km/h) at ' + n + ' ' + (n === 1 ? 'park' : 'parks') + ' playing ' + dayLabel + (k ? ', across ' + k : '') + '.');
			}
			if (bc['Very high UV']) {
				var n = bc['Very high UV'], k = rollupKingdoms(bk['Very high UV']);
				leadParts.push('Very high UV at ' + n + ' ' + (n === 1 ? 'park' : 'parks') + ' playing ' + dayLabel + (k ? ' in ' + k : '') + '.');
			}
		}

		var standoutLine = '';
		if (standout) {
			var icons = { 'Extreme heat':'🥵', 'Frostbite risk':'🥶', 'Thunderstorms':'⛈️', 'Severe wind':'💨', 'Very high UV':'🌞' };
			var pieces = (standout.flags || []).map(function(f) { return (icons[f] || '⚠️') + ' ' + esc(f); });
			standoutLine = '<a href="' + UIR + 'Park/profile/' + standout.park_id + '" class="wx-jump-park" data-park-id="' + standout.park_id + '">' + esc(standout.name) + '</a> carries the worst forecast — ' + pieces.join(' · ');
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
		// Preserve the existing 'coming up' paragraph (it's today-relative, not day-scoped)
		var existingComingUp = document.querySelector('#wx-rundown-body p:last-child');
		var preservedComingUp = '';
		if (existingComingUp && existingComingUp.querySelector('a[href="#upcoming-events"]')) {
			preservedComingUp = existingComingUp.outerHTML;
		}
		document.getElementById('wx-rundown-body').innerHTML = html + preservedComingUp;
	}

	function renderPlay(rows, dayLabel) {
		var title = dayLabel === 'today' ? '🏕️ Play Today' : '🏕️ Play ' + dayLabel.replace(/\b\w/, function(c) { return c.toUpperCase(); });
		document.getElementById('wx-play-title').textContent = title;
		document.getElementById('wx-play-count').textContent = rows.length + (rows.length === 1 ? ' park' : ' parks');
		var body = document.getElementById('wx-play-body');
		if (!rows.length) {
			body.innerHTML = '<div class="wx-empty">No in-person play scheduled.</div>';
			return;
		}
		var html = '';
		rows.forEach(function(p) {
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
				wx = '<div class="wx-play-empty">forecast unavailable</div>';
			}
			html += '<div class="wx-play-row">' +
				'<div class="wx-play-main">' +
					'<div class="wx-play-park"><a href="' + UIR + 'Park/profile/' + p.park_id + '">' + esc(p.park_name) + '</a></div>' +
					'<div class="wx-play-meta">' + scheds.join(' &middot; ') + (k ? ' &middot; ' + esc(k) : '') + '</div>' +
				'</div>' +
				'<div class="wx-play-wx">' + wx + '</div>' +
			'</div>';
		});
		body.innerHTML = html;
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
				renderPlay(d.play || [], dayLabel);
			})
			.catch(function() {
				document.getElementById('wx-play-body').innerHTML = '<div class="wx-empty">Request failed.</div>';
			});
	}

	document.querySelectorAll('.wx-pill').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var date = this.dataset.date;
			var today = new Date().toISOString().slice(0, 10);
			var dayLabel = (date === today) ? 'today' :
				new Date(date + 'T00:00:00').toLocaleDateString([], { weekday: 'long' });
			activatePill(date);
			fetchAndRender(date, dayLabel);
		});
	});
})();
</script>
