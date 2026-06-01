<?php
/* Amtgard Week in Review — global, blameless, omit-when-empty.
 * $recap is the decoded payload from ork_weekly_recap, or null if cron hasn't
 * run yet for this week. $week_start is the YYYY-MM-DD Monday requested.
 */

$has_recap        = is_array($recap) && !empty($recap['WeekStart']);
$scope_kid        = (int)($scope_kingdom_id ?? 0);
$scope_kname      = $scope_kingdom_name ?? '';
$is_kingdom_scope = $scope_kid > 0;
$url_base         = $is_kingdom_scope ? 'Recap/kingdom/' . $scope_kid . '/' : 'Recap/index/';
$url_json         = $is_kingdom_scope ? 'Recap/json_kingdom/' . $scope_kid . '/' : 'Recap/json/';

// Friendly date headline. Days are named so it's obvious this is a fixed
// Mon-Sun week (not a rolling 7 days from today, which is what most other
// reports do). Drop the year on the start if start + end share the same year.
$_ws_ts = $has_recap ? strtotime($recap['WeekStart']) : strtotime($week_start);
$_we_ts = $has_recap ? strtotime($recap['WeekEnd'])   : strtotime($week_start . ' +6 days');
$_same_year = date('Y', $_ws_ts) === date('Y', $_we_ts);
$week_headline = $_same_year
	? date('l, F j', $_ws_ts) . ' – ' . date('l, F j, Y', $_we_ts)
	: date('l, F j, Y', $_ws_ts) . ' – ' . date('l, F j, Y', $_we_ts);

// Helpers for park/kingdom links — leave blank when id is missing.
$park_link = function($id, $name) {
	if (empty($name)) return '';
	if (empty($id))   return htmlspecialchars($name);
	return '<a href="' . UIR . 'Park/profile/' . (int)$id . '">' . htmlspecialchars($name) . '</a>';
};
$kingdom_link = function($id, $name) {
	if (empty($name)) return '';
	if (empty($id))   return htmlspecialchars($name);
	return '<a href="' . UIR . 'Kingdom/profile/' . (int)$id . '">' . htmlspecialchars($name) . '</a>';
};
$player_link = function($id, $persona) {
	if (empty($persona)) return '';
	if (empty($id))      return htmlspecialchars($persona);
	return '<a href="' . UIR . 'Player/profile/' . (int)$id . '">' . htmlspecialchars($persona) . '</a>';
};
$event_link = function($event_id, $detail_id, $name) {
	if (empty($name))     return '';
	if (empty($event_id)) return htmlspecialchars($name);
	$url = UIR . 'Event/detail/' . (int)$event_id . (!empty($detail_id) ? '/' . (int)$detail_id : '');
	return '<a href="' . $url . '">' . htmlspecialchars($name) . '</a>';
};
$where_label = function($park_html, $kingdom_html) {
	if ($park_html && $kingdom_html) return $park_html . ' <span class="recap-muted">(' . $kingdom_html . ')</span>';
	return $park_html ?: $kingdom_html;
};

// Format helpers for the platform-stats section.
$format_count = function($n) {
	if ($n >= 1000000) return number_format($n / 1000000, 1) . 'M';
	if ($n >= 1000)    return number_format($n / 1000, 1) . 'K';
	return number_format($n);
};
$format_bytes = function($n) {
	if ($n >= 1000000000) return number_format($n / 1000000000, 1) . ' GB';
	if ($n >= 1000000)    return number_format($n / 1000000, 1) . ' MB';
	return number_format($n / 1000, 1) . ' KB';
};

// "Came back after X days" → "about 3 months" / "over a year" / "over 12 years".
// Gap is always >= 90d so the sub-60d branch is just defensive.
$humanize_days = function($days) {
	$d = (int)$days;
	if ($d < 60)   return $d . ' days';
	if ($d < 350)  return 'about ' . (int)round($d / 30) . ' months';
	if ($d < 547)  return 'over a year';
	if ($d < 913)  return 'about 2 years';
	return 'over ' . (int)floor($d / 365) . ' years';
};
?>

<style>
.recap-root { max-width: 900px; margin: 1.5em auto 3em; padding: 0 1em;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
	color: #222; line-height: 1.5; }

/* Hero — eyebrow + the date headline is the focal point */
.recap-hero { text-align: center; padding: 1.5em 0 1.8em; margin-bottom: 1.5em;
	border-bottom: 2px solid #f0e5d0; position: relative; }
.recap-hero-eyebrow { color: #c89b3f; font-size: 0.78em; letter-spacing: 0.22em;
	text-transform: uppercase; font-weight: 700; margin-bottom: 0.4em; }
.recap-hero-eyebrow .fas { margin-right: 0.4em; }
/* The site-wide h1-h6 rule in orkui.css applies a light-gray background, border,
 * and text-shadow to every heading — explicitly reset those for our headings. */
.recap-root h1, .recap-root h2 { background: transparent; border: none;
	text-shadow: none; padding: 0; border-radius: 0; }
.recap-hero h1 { font-size: 1.65em; margin: 0; font-weight: 600; color: #2a2a2a;
	letter-spacing: -0.01em; line-height: 1.25; }
.recap-hero-sub { color: #999; font-size: 0.82em; margin-top: 0.7em;
	font-style: italic; letter-spacing: 0.01em; }

/* Sections rendered as cards */
.recap-section { background: #fafaf7; border: 1px solid #ececec;
	border-radius: 10px; padding: 1.1em 1.4em 1em; margin-bottom: 1em;
	box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
.recap-section h2 { font-size: 1.08em; margin: 0 0 0.6em 0; color: #2a2a2a;
	font-weight: 600; display: flex; align-items: center; gap: 0.55em; border: none; padding: 0; }
.recap-section h2 .recap-section-icon { width: 1.6em; height: 1.6em; display: inline-flex;
	align-items: center; justify-content: center; border-radius: 50%; background: #f0e5d0;
	color: #a07d2a; font-size: 0.78em; flex-shrink: 0; }
.recap-section ul { list-style: none; padding-left: 0; margin: 0.4em 0 0; }
.recap-section li { padding: 0.4em 0; border-bottom: 1px solid #eee9dc; line-height: 1.4; }
.recap-section li:last-child { border-bottom: none; }
.recap-section li:first-child { font-weight: 500; }

.recap-rank { display: inline-block; width: 1.6em; color: #c89b3f; font-weight: 700; }
.recap-count { color: #888; font-size: 0.82em; margin-left: 0.3em; font-weight: 400; }
.recap-muted { color: #888; font-size: 0.9em; }

.recap-digest { margin: 0.2em 0 0.4em; color: #3a3a3a; line-height: 1.55; }
.recap-digest strong { color: #1a1a1a; }
.recap-digest a { color: #1a4c8c; text-decoration: none; font-weight: 500; }
.recap-digest a:hover { text-decoration: underline; }
.recap-trend { margin: 0.5em 0 0; color: #888; font-size: 0.9em; }
html[data-theme="dark"] .recap-trend { color: #94a3b8; }

.recap-tip { position: relative; display: inline-block; color: #a0aec0;
	cursor: help; margin-left: 6px; vertical-align: middle; font-size: 0.85em; }
.recap-tip:hover { color: #718096; }
.recap-tip-text {
	visibility: hidden; opacity: 0;
	position: absolute; top: calc(100% + 8px); left: 0;
	background: #1a202c; color: #e2e8f0;
	font-size: 12px; font-weight: 400; line-height: 1.5;
	padding: 10px 12px; border-radius: 6px; width: 280px;
	text-transform: none; letter-spacing: 0; text-align: left;
	pointer-events: none; transition: opacity 0.15s; z-index: 200;
	box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}
.recap-tip-text::before {
	content: ''; position: absolute; bottom: 100%; left: 14px;
	border: 5px solid transparent; border-bottom-color: #1a202c;
}
.recap-tip:hover .recap-tip-text,
.recap-tip:focus-within .recap-tip-text { visibility: visible; opacity: 1; }
html[data-theme="dark"] .recap-tip-text { background: #e2e8f0; color: #1a202c; }
html[data-theme="dark"] .recap-tip-text::before { border-bottom-color: #e2e8f0; }

.recap-section a { color: #1a4c8c; text-decoration: none; }
.recap-section a:hover { text-decoration: underline; }

.recap-section details { margin-top: 0.7em; padding-top: 0.4em; border-top: 1px dashed #e0d9c2; }
.recap-section details summary { cursor: pointer; color: #888; font-size: 0.88em;
	padding: 0.2em 0; user-select: none; list-style: none; }
.recap-section details summary::-webkit-details-marker { display: none; }
.recap-section details summary::before { content: '▸ '; color: #c89b3f; font-size: 0.9em; }
.recap-section details[open] summary::before { content: '▾ '; }
.recap-section details summary:hover { color: #444; }
.recap-section details ul { margin-top: 0.4em; }

.recap-empty { color: #999; font-style: italic; padding: 3em 0; text-align: center;
	background: #fafaf7; border-radius: 10px; border: 1px dashed #ddd; }

/* Navigation strip */
.recap-nav { display: flex; justify-content: space-between; align-items: center;
	margin: 0 0 1.5em; padding: 0.7em 1em; background: #fff; border: 1px solid #eee;
	border-radius: 8px; font-size: 0.9em; }
.recap-nav a { color: #1a4c8c; text-decoration: none; font-weight: 500; }
.recap-nav a:hover { text-decoration: underline; }
.recap-nav .recap-nav-mid { color: #bbb; }

.recap-archive { margin-top: 2em; padding: 0.9em 1.1em; background: #fafafa;
	border: 1px solid #eee; border-radius: 8px; font-size: 0.85em; color: #777;
	line-height: 1.9; }
.recap-archive a { color: #1a4c8c; text-decoration: none; margin: 0 0.35em;
	white-space: nowrap; }
.recap-archive a:hover { text-decoration: underline; }
.recap-archive a.current { color: #c89b3f; font-weight: 700; }

.recap-foot { margin-top: 1em; font-size: 0.78em; color: #aaa; text-align: center; }
.recap-foot a { color: #888; }

.recap-scope-picker { display: flex; justify-content: center; align-items: center;
	gap: 0.7em; margin: 0 0 1em; font-size: 0.92em; color: #555; }
.recap-scope-picker select { padding: 0.35em 0.6em; border: 1px solid #ccc;
	border-radius: 6px; background: #fff; font-size: 0.95em; color: #222;
	font-family: inherit; cursor: pointer; }
.recap-scope-picker select:hover { border-color: #999; }
html[data-theme="dark"] .recap-scope-picker { color: #cbd5e0; }
html[data-theme="dark"] .recap-scope-picker select {
	background: #2d3748; color: #e2e8f0; border-color: #4a5568; }
html[data-theme="dark"] .recap-scope-picker select:hover { border-color: #718096; }

/* Dark mode — match the palette used in default.theme (#1a202c body, #2d3748 cards) */
html[data-theme="dark"] .recap-root { color: #e2e8f0; }
html[data-theme="dark"] .recap-hero { border-bottom-color: #4a3b1f; }
html[data-theme="dark"] .recap-hero-eyebrow { color: #e0b95a; }
html[data-theme="dark"] .recap-hero h1 { color: #f1f5f9; }
html[data-theme="dark"] .recap-hero-sub { color: #6b7280; }
html[data-theme="dark"] .recap-section { background: #2d3748; border-color: #4a5568;
	box-shadow: 0 1px 2px rgba(0,0,0,0.3); }
html[data-theme="dark"] .recap-section h2 { color: #f1f5f9; }
html[data-theme="dark"] .recap-section h2 .recap-section-icon {
	background: #3f3422; color: #e0b95a; }
html[data-theme="dark"] .recap-section li { border-bottom-color: #3a4555; }
html[data-theme="dark"] .recap-section a { color: #63b3ed; }
html[data-theme="dark"] .recap-section a:hover { color: #90cdf4; }
html[data-theme="dark"] .recap-rank { color: #e0b95a; }
html[data-theme="dark"] .recap-count,
html[data-theme="dark"] .recap-muted { color: #a0aec0; }
html[data-theme="dark"] .recap-digest { color: #cbd5e0; }
html[data-theme="dark"] .recap-digest strong { color: #f1f5f9; }
html[data-theme="dark"] .recap-digest a { color: #63b3ed; }
html[data-theme="dark"] .recap-section details { border-top-color: #4a5568; }
html[data-theme="dark"] .recap-section details summary { color: #a0aec0; }
html[data-theme="dark"] .recap-section details summary:hover { color: #cbd5e0; }
html[data-theme="dark"] .recap-section details summary::before { color: #e0b95a; }
html[data-theme="dark"] .recap-empty { background: #2d3748; border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .recap-nav { background: #2d3748; border-color: #4a5568; }
html[data-theme="dark"] .recap-nav a { color: #63b3ed; }
html[data-theme="dark"] .recap-nav a:hover { color: #90cdf4; }
html[data-theme="dark"] .recap-nav .recap-nav-mid { color: #6b7280; }
html[data-theme="dark"] .recap-archive { background: #2d3748; border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .recap-archive a { color: #63b3ed; }
html[data-theme="dark"] .recap-archive a.current { color: #e0b95a; }
html[data-theme="dark"] .recap-foot,
html[data-theme="dark"] .recap-foot a { color: #6b7280; }
</style>

<div class="recap-root">

	<div class="recap-hero">
		<div class="recap-hero-eyebrow">
			<i class="fas fa-trophy"></i> Amtgard Week in Review
<?php if ($is_kingdom_scope) : ?>
			· <?=htmlspecialchars($scope_kname)?>
<?php endif; ?>
		</div>
		<h1><?=htmlspecialchars($week_headline)?></h1>
		<div class="recap-hero-sub">Weekly recaps are automatically produced early Monday mornings for the previous week.</div>
	</div>

<?php if (!empty($kingdom_list)) : ?>
	<div class="recap-scope-picker">
		<label for="recap-scope-select">Viewing:</label>
		<select id="recap-scope-select" onchange="if(this.value)location.href=this.value">
			<option value="<?=UIR ?>Recap/index/<?=urlencode($week_start)?>"<?=$is_kingdom_scope ? '' : ' selected'?>>All Kingdoms</option>
<?php foreach ($kingdom_list as $k) :
		$opt_url = UIR . 'Recap/kingdom/' . (int)$k['KingdomId'] . '/' . urlencode($week_start);
		$selected = ($scope_kid === (int)$k['KingdomId']) ? ' selected' : '';
?>
			<option value="<?=$opt_url?>"<?=$selected?>><?=htmlspecialchars($k['Name'])?></option>
<?php endforeach; ?>
		</select>
	</div>
<?php endif; ?>

<?php if (!empty($prev_week) || !empty($next_week)) : ?>
	<div class="recap-nav">
		<span>
<?php   if (!empty($prev_week)) : ?>
			<a href="<?=UIR ?><?=$url_base?><?=urlencode($prev_week)?>">← Week of <?=htmlspecialchars($prev_week)?></a>
<?php   else : ?>
			<span class="recap-nav-mid">← (earliest)</span>
<?php   endif; ?>
		</span>
		<span class="recap-nav-mid">·</span>
		<span>
<?php   if (!empty($next_week)) : ?>
			<a href="<?=UIR ?><?=$url_base?><?=urlencode($next_week)?>">Week of <?=htmlspecialchars($next_week)?> →</a>
<?php   else : ?>
			<span class="recap-nav-mid">(latest) →</span>
<?php   endif; ?>
		</span>
	</div>
<?php endif; ?>

<?php if (!$has_recap) : ?>
	<div class="recap-empty">
		The recap for this week hasn't been computed yet.<br>
		Check back Monday morning.
	</div>
<?php else :

	// ============================ Top events ============================
	if (!empty($recap['TopEvents'])) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-calendar-day"></i></span> Biggest Events of the Week</h2>
		<ul>
<?php   foreach ($recap['TopEvents'] as $i => $e) :
			$rank   = $i + 1;
			$where  = $where_label($park_link($e['ParkId'], $e['ParkName']), $kingdom_link($e['KingdomId'], $e['KingdomName']));
			$ename  = $event_link($e['EventId'], $e['EventCalendarDetailId'] ?? null, $e['EventName']);
?>
			<li>
				<span class="recap-rank"><?=$rank?>.</span>
				<strong><?=$ename?></strong>
<?php       if ($where) : ?> at <?=$where?><?php endif; ?>
				<span class="recap-count">— <?=(int)$e['Attendance']?> attendees</span>
			</li>
<?php   endforeach; ?>
		</ul>
	</section>
<?php endif;

	// ============================ Top parks ============================
	if (!empty($recap['TopParks'])) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-tree"></i></span> Most Active Parks</h2>
		<ul>
<?php   foreach ($recap['TopParks'] as $i => $p) :
			$rank    = $i + 1;
			$pk      = $park_link($p['ParkId'], $p['ParkName']);
			$kd      = $kingdom_link($p['KingdomId'], $p['KingdomName']);
?>
			<li>
				<span class="recap-rank"><?=$rank?>.</span>
				<?=$pk?> <span class="recap-muted">(<?=$kd?>)</span>
				<span class="recap-count">— <?=(int)$p['Attendance']?> unique attendees</span>
			</li>
<?php   endforeach; ?>
		</ul>
	</section>
<?php endif;

	// ============================ Knightings ============================
	if (!empty($recap['Knightings'])) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-shield-alt"></i></span> New Knights</h2>
		<ul>
<?php   foreach ($recap['Knightings'] as $k) :
			$who  = $player_link($k['MundaneId'], $k['Persona']);
			$where = $where_label($park_link($k['ParkId'], $k['ParkName']), $kingdom_link($k['KingdomId'], $k['KingdomName']));
?>
			<li>
				<strong><?=$who?></strong> — <?=htmlspecialchars($k['AwardName'])?>
<?php       if ($where) : ?> <span class="recap-muted">at <?=$where?></span><?php endif; ?>
			</li>
<?php   endforeach; ?>
		</ul>
	</section>
<?php endif;

	// ============================ Masterhoods ============================
	if (!empty($recap['Masterhoods'])) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-crown"></i></span> New Masters</h2>
		<ul>
<?php   foreach ($recap['Masterhoods'] as $m) :
			$who  = $player_link($m['MundaneId'], $m['Persona']);
			$where = $where_label($park_link($m['ParkId'], $m['ParkName']), $kingdom_link($m['KingdomId'], $m['KingdomName']));
?>
			<li>
				<strong><?=$who?></strong> — <?=htmlspecialchars($m['AwardName'])?>
<?php       if ($where) : ?> <span class="recap-muted">at <?=$where?></span><?php endif; ?>
			</li>
<?php   endforeach; ?>
		</ul>
	</section>
<?php endif;

	// ============================ Paragons ============================
	if (!empty($recap['Paragons'])) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-gem"></i></span> New Paragons <span class="recap-count">(<?=count($recap['Paragons'])?>)</span></h2>
		<ul>
<?php   foreach ($recap['Paragons'] as $pg) :
			$who   = $player_link($pg['MundaneId'], $pg['Persona']);
			$where = $where_label($park_link($pg['ParkId'], $pg['ParkName']), $kingdom_link($pg['KingdomId'], $pg['KingdomName']));
?>
			<li>
				<strong><?=$who?></strong> — <?=htmlspecialchars($pg['AwardName'])?>
<?php       if ($where) : ?> <span class="recap-muted">at <?=$where?></span><?php endif; ?>
			</li>
<?php   endforeach; ?>
		</ul>
	</section>
<?php endif;

	// ============================ Milestone events ============================
	if (!empty($recap['MilestoneEvents'])) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-flag-checkered"></i></span> Milestone Events</h2>
		<ul>
<?php   foreach ($recap['MilestoneEvents'] as $m) :
			$where = $where_label($park_link($m['ParkId'], $m['ParkName']), $kingdom_link($m['KingdomId'], $m['KingdomName']));
?>
			<li>
				<strong><?=$event_link($m['EventId'], $m['EventCalendarDetailId'] ?? null, $m['EventName'])?></strong>
				— <?=(int)$m['EventNumber']?><sup>th</sup> event
<?php       if ($where) : ?> at <?=$where?><?php endif; ?>
			</li>
<?php   endforeach; ?>
		</ul>
	</section>
<?php endif;

	// ============================ New players ============================
	$new = $recap['NewPlayers'] ?? array();
	if (!empty($new['Count'])) :
		$np_list  = $new['Players'];
		$np_count = (int)$new['Count'];
		// Digest: first 5 personas, comma-separated, plus "and N others" if more.
		$np_preview = array_slice($np_list, 0, 5);
		$np_names   = array();
		foreach ($np_preview as $p) $np_names[] = $player_link($p['MundaneId'], $p['Persona']);
		$np_extra   = $np_count - count($np_preview);
?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-user-plus"></i></span> New Players <span class="recap-count">(<?=$np_count?>)</span></h2>
		<p class="recap-digest">
			Welcome to <?=implode(', ', $np_names)?><?php if ($np_extra > 0) : ?>, and <?=$np_extra?> other<?=$np_extra === 1 ? '' : 's'?><?php endif; ?>.
		</p>
<?php   if ($np_count > count($np_preview)) : ?>
		<details>
			<summary>See where they signed in</summary>
			<ul>
<?php       foreach ($np_list as $p) :
				$who   = $player_link($p['MundaneId'], $p['Persona']);
				$where = $where_label($park_link($p['ParkId'], $p['ParkName']), $kingdom_link($p['KingdomId'], $p['KingdomName']));
?>
				<li>
					<strong><?=$who?></strong>
<?php           if ($where) : ?> <span class="recap-muted">at <?=$where?></span><?php endif; ?>
				</li>
<?php       endforeach; ?>
			</ul>
		</details>
<?php   endif; ?>
	</section>
<?php endif;

	// ============================ Returning players ============================
	if (!empty($recap['ReturningPlayers'])) :
		$rp_list  = $recap['ReturningPlayers'];
		$rp_count = count($rp_list);
		// Digest: top 3 by days-away (list is already sorted that way), each with humanized gap.
		$rp_preview = array_slice($rp_list, 0, 3);
		$rp_phrases = array();
		foreach ($rp_preview as $p) {
			$who  = $player_link($p['MundaneId'], $p['Persona']);
			$gap  = $humanize_days($p['DaysAway']);
			$rp_phrases[] = $who . ' after ' . $gap;
		}
		$rp_extra = $rp_count - count($rp_preview);
?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-undo-alt"></i></span> Welcome Back <span class="recap-count">(<?=$rp_count?>)</span></h2>
		<p class="recap-digest">
			<?=implode('; ', $rp_phrases)?><?php if ($rp_extra > 0) : ?>; and <?=$rp_extra?> other<?=$rp_extra === 1 ? '' : 's'?><?php endif; ?>.
		</p>
<?php   if ($rp_count > count($rp_preview)) : ?>
		<details>
			<summary>See everyone who came back</summary>
			<ul>
<?php       foreach ($rp_list as $p) :
				$who   = $player_link($p['MundaneId'], $p['Persona']);
				$where = $where_label($park_link($p['ParkId'], $p['ParkName']), $kingdom_link($p['KingdomId'], $p['KingdomName']));
				$gap   = $humanize_days($p['DaysAway']);
?>
				<li>
					<strong><?=$who?></strong> — back after <?=$gap?>
<?php           if ($where) : ?> <span class="recap-muted">at <?=$where?></span><?php endif; ?>
				</li>
<?php       endforeach; ?>
			</ul>
		</details>
<?php   endif; ?>
	</section>
<?php endif; ?>

<?php
	// ============================ ORK platform stats (CF) ============================
	$ps = $recap['PlatformStats'] ?? null;
	if (is_array($ps)) :
		$req_str         = $format_count($ps['Requests']);
		$cache_hits_str  = $format_count($ps['CacheHits']);
		$origin_req_str  = $format_count(max(0, $ps['Requests'] - $ps['CacheHits']));
		$total_gb_str    = $format_bytes($ps['Bytes']);
		$origin_gb_str   = $format_bytes($ps['OriginBytes'] ?? 0);
		$cached_gb_str   = $format_bytes($ps['CachedBytes'] ?? 0);
		$us_str          = $format_count($ps['RequestsUS']);
		$ca_str          = $format_count($ps['RequestsCA']);
		$req_total    = max(1, $ps['Requests']);
		$cache_pct    = round(100 * $ps['CacheHits'] / $req_total);
		$bytes_total  = max(1, $ps['Bytes']);
		$cached_pct   = round(100 * ($ps['CachedBytes'] ?? 0) / $bytes_total);

		// WoW delta — only when the prior week ALSO has PlatformStats (i.e. both
		// weeks are inside CF's retention horizon). Neutral phrasing, no color.
		$prev_ps = $prev_recap['PlatformStats'] ?? null;
		$delta_line = '';
		if (is_array($prev_ps) && !empty($prev_ps['Requests'])) {
			$cur  = $ps['Requests'];
			$prev = $prev_ps['Requests'];
			$pct  = round(100 * ($cur - $prev) / $prev);
			$arrow = $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '·');
			$delta_line = sprintf('%s %s%% from the previous week (%s → %s).',
				$arrow, $pct >= 0 ? '+' . $pct : $pct,
				$format_count($prev), $format_count($cur));
		}
?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-globe-americas"></i></span> ORK Data <span class="recap-tip" tabindex="0"><i class="fas fa-info-circle"></i><span class="recap-tip-text">The ORK is a PHP/Database application hosted on Amazon Web Services behind Cloudflare's CDN. Cloudflare caches static assets (images, CSS, JavaScript) at edge locations near visitors — those requests never reach AWS, saving server load, bandwidth, and response time. Cloudflare also absorbs bot traffic and bad actors before they touch the origin. These numbers show how that split played out for US and Canadian traffic this week.</span></span></h2>
		<p class="recap-digest">
			Cloudflare delivered <strong><?=$req_str?></strong> requests
			(<strong><?=$total_gb_str?></strong>)
			to the US (<?=$us_str?>) and Canada (<?=$ca_str?>).
			Of those, <strong><?=$origin_req_str?></strong> requests
			(<?= 100 - $cache_pct ?>%, <?=$origin_gb_str?>) were served by the ORK itself;
			the remaining <?=$cache_hits_str?>
			(<?=$cache_pct?>%, <?=$cached_gb_str?>) were served from Cloudflare's cache.
		</p>
<?php   if ($delta_line) : ?>
		<p class="recap-trend"><em>Total requests <?=$delta_line?></em></p>
<?php   endif; ?>
<?php   if (!empty($ps['BlockedOrChallenged'])) : ?>
		<p class="recap-digest">
			Cloudflare also blocked or challenged <strong><?=$format_count($ps['BlockedOrChallenged'])?></strong> malicious requests this week.
		</p>
<?php   endif; ?>
	</section>
<?php endif; ?>

	<div class="recap-foot">
		Computed <?=htmlspecialchars($recap['ComputedAt'] ?? '')?> ·
		<a href="<?=UIR ?><?=$url_json?><?=urlencode($recap['WeekStart'])?>">JSON</a>
	</div>

<?php endif; // $has_recap ?>

<?php if (!empty($recent_weeks) && count($recent_weeks) > 1) : ?>
	<div class="recap-archive">
		Recent weeks:
<?php   foreach ($recent_weeks as $w) :
			$is_current = ($w === ($recap['WeekStart'] ?? $week_start));
?>
		<a href="<?=UIR ?><?=$url_base?><?=urlencode($w)?>" class="<?=$is_current ? 'current' : ''?>"><?=htmlspecialchars($w)?></a>
<?php   endforeach; ?>
	</div>
<?php endif; ?>

</div>
