<?php
/* ── Population Explorer ─────────────────────────────────────
   Controller: Controller_Reports::population_explorer
   Data: pe_scope_type, pe_scope_id, pe_scope_name, pe_registry,
         pe_initial, pe_link_error, pe_link_param, pe_no_scope, pe_bad_scope, pe_load_error
   The builder itself lives in script/populationexplorer.js and
   reads only window.PE. */
$pe_scope_type = $pe_scope_type ?? null;
$pe_scope_id   = (int)($pe_scope_id ?? 0);
$pe_scope_name = (string)($pe_scope_name ?? '');
$pe_registry   = is_array($pe_registry ?? null) ? $pe_registry : [];
$pe_initial    = $pe_initial ?? null;
$pe_link_error = $pe_link_error ?? null;
$pe_no_scope   = !empty($pe_no_scope);
$pe_bad_scope  = !empty($pe_bad_scope);
$pe_load_error = !empty($pe_load_error);
$pe_link_param = (string)($pe_link_param ?? 'pe');
$pe_ready      = !$pe_no_scope && !$pe_bad_scope && !$pe_load_error && $pe_scope_type !== null && !empty($pe_registry);

$pe_is_park    = ($pe_scope_type === 'Park');
// Officers of the scope also get the restricted Suspended / Banned filters (PublicRegistry).
$pe_officer    = !empty($pe_registry['officer']);
$pe_scope_link = $pe_scope_type ? UIR . ($pe_is_park ? 'Park' : 'Kingdom') . '/profile/' . $pe_scope_id : '';
$pe_scope_icon = $pe_is_park ? 'fa-tree' : 'fa-chess-rook';

// Option names (and ladder criterion labels) come straight from the DB; strip
// magic-quotes-era escapes for display.
foreach ((array)($pe_registry['criteria'] ?? []) as $_id => $_def) {
	if (is_array($_def) && isset($_def['label']) && is_string($_def['label'])) {
		$pe_registry['criteria'][$_id]['label'] = stripslashes($_def['label']);
	}
}
if (isset($pe_registry['options']) && is_array($pe_registry['options'])) {
	foreach (['class', 'award', 'park', 'kingdom'] as $_k) {
		foreach ((array)($pe_registry['options'][$_k] ?? []) as $_i => $_o) {
			if (isset($_o[1]) && is_string($_o[1])) {
				$pe_registry['options'][$_k][$_i][1] = stripslashes($_o[1]);
			}
		}
	}
	foreach ((array)($pe_registry['options']['order'] ?? []) as $_pe => $_list) {
		foreach ((array)$_list as $_i => $_o) {
			if (isset($_o[1]) && is_string($_o[1])) {
				$pe_registry['options']['order'][$_pe][$_i][1] = stripslashes($_o[1]);
			}
		}
	}
}

$pe_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$pe_page_url   = UIR . 'Reports/population_explorer&' . ($pe_is_park ? 'ParkId' : 'KingdomId') . '=' . $pe_scope_id;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__ . '/style/reports.css')?>">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/populationexplorer.css?v=<?=filemtime(__DIR__ . '/style/populationexplorer.css')?>">

<div class="rp-root pe-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-users-viewfinder rp-header-icon"></i>
				<h1 class="rp-header-title">Population Explorer</h1>
			</div>
<?php if ($pe_ready && $pe_scope_name !== '') : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=htmlspecialchars($pe_scope_link)?>">
					<i class="fas <?=$pe_scope_icon?>"></i>
					<?=htmlspecialchars($pe_scope_name)?>
				</a>
			</div>
<?php endif; ?>
		</div>
<?php if ($pe_ready) : ?>
		<div class="rp-header-actions">
			<button type="button" class="rp-btn-ghost" id="pe-help-open" aria-haspopup="dialog" aria-controls="pe-help" aria-expanded="false"><i class="fas fa-circle-question" aria-hidden="true"></i> Help</button>
		</div>
<?php endif; ?>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Build a filter from rules joined by AND / OR, choose the columns you want, then Run. Groups nest, so you can mix &ldquo;all of&rdquo; and &ldquo;any of&rdquo;. Results are limited to players in this <?=$pe_is_park ? 'park' : 'kingdom'?> and capped at 5,000 rows; Copy link shares the exact query.<?php if ($pe_ready && !$pe_officer) : ?> The Suspended and Banned filters are only offered to officers of this <?=$pe_is_park ? 'park' : 'kingdom'?>.<?php endif; ?></span>
	</div>

<?php if ($pe_bad_scope) : ?>
	<div class="rp-empty-state pe-empty-page">
		<i class="fas fa-circle-question"></i>
		<h3 class="pe-empty-title">That <?=$pe_is_park ? 'park' : 'kingdom'?> could not be found</h3>
		<p>Open Population Explorer from a kingdom or park&rsquo;s Reports tab to choose who to explore.</p>
	</div>
<?php elseif ($pe_load_error) : ?>
	<div class="rp-empty-state pe-empty-page" role="alert">
		<i class="fas fa-circle-exclamation"></i>
		<h3 class="pe-empty-title">The report options could not be loaded</h3>
		<p>Something went wrong reading the class, award and park lists. Reload the page to try again.</p>
	</div>
<?php elseif (!$pe_ready) : ?>
	<div class="rp-empty-state pe-empty-page">
		<i class="fas fa-users-viewfinder"></i>
		<h3 class="pe-empty-title">No kingdom or park selected</h3>
		<p>Open Population Explorer from a kingdom or park&rsquo;s Reports tab to choose who to explore.</p>
	</div>
<?php else : ?>

<?php if ($pe_link_error) : ?>
	<div class="pe-banner pe-banner-warn" role="alert">
		<i class="fas fa-triangle-exclamation"></i>
		<span><?=htmlspecialchars(rtrim((string)$pe_link_error, '. ') . '.')?> The builder has been reset.</span>
	</div>
<?php endif; ?>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number" id="pe-stat-results">&mdash;</div>
			<div class="rp-stat-label">Results</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percent"></i></div>
			<div class="rp-stat-number" id="pe-stat-pct">&mdash;</div>
			<div class="rp-stat-label">Of <?=$pe_is_park ? 'park' : 'kingdom'?> players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-stopwatch"></i></div>
			<div class="rp-stat-number" id="pe-stat-time">&mdash;</div>
			<div class="rp-stat-label">Run time</div>
		</div>
	</div>

	<div class="rp-main pe-main">

		<!-- Filters -->
		<section class="rp-filter-card pe-card" id="pe-filters-card">
			<div class="rp-filter-card-header pe-card-head">
				<button type="button" class="pe-card-toggle" id="pe-filters-toggle" aria-expanded="true" aria-controls="pe-filters-body">
					<i class="fas fa-filter" aria-hidden="true"></i>
					<span class="pe-card-title">Filters</span>
					<span class="pe-card-summary" id="pe-filters-summary"></span>
					<i class="fas fa-chevron-down pe-card-chevron" aria-hidden="true"></i>
				</button>
			</div>
			<div class="rp-filter-card-body" id="pe-filters-body">
				<div id="pe-builder" class="pe-builder"></div>
				<div class="pe-builder-foot">
					<button type="button" class="pe-btn-link" id="pe-clear">Clear all rules</button>
				</div>
			</div>
		</section>

		<!-- Columns -->
		<section class="rp-filter-card pe-card" id="pe-columns-card">
			<div class="rp-filter-card-header pe-card-head">
				<button type="button" class="pe-card-toggle" id="pe-columns-toggle" aria-expanded="true" aria-controls="pe-columns-body">
					<i class="fas fa-table-columns" aria-hidden="true"></i>
					<span class="pe-card-title">Columns</span>
					<span class="pe-card-summary" id="pe-columns-summary"></span>
					<i class="fas fa-chevron-down pe-card-chevron" aria-hidden="true"></i>
				</button>
				<button type="button" class="pe-btn-link pe-card-header-link" id="pe-columns-reset">Reset</button>
			</div>
			<div class="rp-filter-card-body" id="pe-columns-body">
				<div id="pe-columns" class="pe-columns"></div>
			</div>
		</section>

		<!-- Actions: outside both folding cards, so Run is always in reach (spec §5). -->
		<div class="pe-action-bar" id="pe-action-bar" role="group" aria-label="Report actions">
			<button type="button" class="pe-btn-run" id="pe-run"><i class="fas fa-play" aria-hidden="true"></i> <span>Run</span></button>
			<span class="pe-dirty-hint" id="pe-dirty" hidden><i class="fas fa-circle-info" aria-hidden="true"></i> Filters changed since last run</span>
			<div class="pe-action-bar-end">
				<button type="button" class="pe-btn-action" id="pe-copy-link" data-tip="Copy a link that reopens this exact filter and column set"><i class="fas fa-link" aria-hidden="true"></i> Copy link</button>
				<button type="button" class="pe-btn-action" id="pe-export" aria-disabled="true" data-tip="Run the report first, then export the results to Excel"><i class="fas fa-file-excel" aria-hidden="true"></i> Export</button>
			</div>
		</div>

		<!-- Results -->
		<section class="rp-filter-card pe-card" id="pe-results-card">
			<div class="rp-filter-card-header"><i class="fas fa-list"></i> Results</div>
			<div class="rp-filter-card-body pe-results-body">
				<div id="pe-results-msg"></div>
				<p class="pe-stale-note" id="pe-stale" hidden><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> The table below is from your last successful run.</p>
				<div class="rp-table-area pe-table-area" id="pe-table-area" hidden></div>
				<div class="rp-empty-state pe-results-empty" id="pe-results-idle">
					<i class="fas fa-users-viewfinder"></i>
					<p>Build a filter and press <strong>Run</strong> to see matching players.</p>
				</div>
			</div>
		</section>

	</div><!-- /rp-main -->

	<div class="pe-toast" id="pe-toast" role="status" aria-live="polite" hidden></div>
	<!-- Persistent live region: run results and errors are announced here. -->
	<div class="pe-sr-only" id="pe-live" role="status" aria-live="polite" aria-atomic="true"></div>

<?php
	/* ── Help guide (dialog) ──────────────────────────────────
	   Static prose; the Criteria reference (#pe-help-ref) is built by
	   script/populationexplorer-help.js from PE.registry. Every claim here is
	   backed by class.PopulationExplorer.php / populationexplorer.js; keep the
	   two in step when either changes. */
	$pe_h = function ($s) {
		return htmlspecialchars((string)$s, ENT_QUOTES);
	};
	// A read-only rule in the builder's look. $val: text, or a list of chips.
	$pe_rule = function ($crit, $op, $val) use ($pe_h) {
		$v = is_array($val)
			? implode(' ', array_map(function ($c) use ($pe_h) { return '<span class="pe-help-chip">' . $pe_h($c) . '</span>'; }, $val))
			: '<span class="pe-help-rule-val">' . $pe_h($val) . '</span>';
		return '<span class="pe-help-rule"><span class="pe-help-rule-crit">' . $pe_h($crit) . '</span>'
			. '<span class="pe-help-rule-op">' . $pe_h($op) . '</span>' . $v . '</span>';
	};
	$pe_join = function ($op) {
		return '<span class="pe-connector-label pe-connector-' . ($op === 'OR' ? 'or' : 'and') . '">' . ($op === 'OR' ? 'OR' : 'AND') . '</span>';
	};
	$pe_word = $pe_is_park ? 'park' : 'kingdom';
?>
	<div class="pe-help-overlay" id="pe-help" hidden>
		<div class="pe-help-box" role="dialog" aria-modal="true" aria-labelledby="pe-help-title">
			<div class="pe-help-header">
				<h2 class="pe-help-title" id="pe-help-title" tabindex="-1"><i class="fas fa-circle-question" aria-hidden="true"></i> Population Explorer guide</h2>
				<button type="button" class="pe-help-close" id="pe-help-close" aria-label="Close guide"><i class="fas fa-xmark" aria-hidden="true"></i></button>
			</div>
			<div class="pe-help-body" id="pe-help-body" tabindex="0" role="region" aria-label="Guide text">

				<nav class="pe-help-toc" aria-label="Guide contents">
					<ol>
						<li><a href="#pe-help-what">What this report does</a></li>
						<li><a href="#pe-help-start">Quick start</a></li>
						<li><a href="#pe-help-rules">Rules</a></li>
						<li><a href="#pe-help-groups">Groups: AND and OR</a></li>
						<li><a href="#pe-help-not">&ldquo;Not&rdquo; rules and missing values</a></li>
						<li><a href="#pe-help-ref">Criteria reference</a></li>
						<li><a href="#pe-help-results">Columns and results</a></li>
						<li><a href="#pe-help-export">Export</a></li>
						<li><a href="#pe-help-share">Share links</a></li>
						<li><a href="#pe-help-officer">Officer-only filters</a></li>
						<li><a href="#pe-help-trouble">Troubleshooting</a></li>
					</ol>
				</nav>

				<section class="pe-help-section" aria-labelledby="pe-help-what">
					<h3 class="pe-help-h" id="pe-help-what" tabindex="-1">What this report does</h3>
					<p>Population Explorer lists the players of one kingdom or park who match the rules you set, with the columns you choose. Anyone who is logged in can use it for any kingdom or park. You are looking at <strong><?=$pe_h($pe_scope_name)?></strong>.</p>
					<ul>
<?php if ($pe_is_park) : ?>
						<li><strong>Who is included:</strong> every player whose home park is this park, active or not.</li>
<?php else : ?>
						<li><strong>Who is included:</strong> every player whose home kingdom is this kingdom, active or not. If the kingdom counts its principalities in its statistics, their players are included too.</li>
<?php endif; ?>
						<li><strong>What you can filter on:</strong> sign-ins, home and last sign-in location, dues, waivers, active status, awards and peerages, ladder award ranks, and reeve and corpora qualifications.</li>
						<li><strong>What it shows:</strong> personas only. There are no real names or email addresses, on screen or in the export.</li>
					</ul>
					<p>Inactive players count unless you leave them out with <?=$pe_rule('Active', 'is', 'Yes')?>.</p>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-start">
					<h3 class="pe-help-h" id="pe-help-start" tabindex="-1">Quick start</h3>
					<ol class="pe-help-steps">
						<li><strong>Add a rule.</strong> In Filters, press <span class="pe-help-btn"><i class="fas fa-plus" aria-hidden="true"></i> Rule</span>, then choose a criterion, an operator and a value. Add as many rules as you need.</li>
						<li><strong>Pick columns.</strong> Tick the columns you want in Columns. Persona is always included.</li>
						<li><strong>Run.</strong> Press <strong>Run</strong>, below the Columns card, or Ctrl+Enter (&#8984;+Enter on a Mac) while you are in a rule. The matching players appear under Results.</li>
					</ol>
					<p>With no rules at all, Run lists everyone in this <?=$pe_word?>.</p>
					<p>After a successful run, the Filters and Columns cards fold up so the results move up the page. Click a card&rsquo;s title to open it again. While a card is folded, its title shows a short summary, such as &ldquo;3 rules (AND)&rdquo;. Run, Copy link and Export stay in view between the cards and the results, folded or not.</p>
					<p>If you change a rule or a column while a run is still working, the cards stay open when its results arrive, your cursor stays where it is, and the results are marked &ldquo;Filters changed since last run&rdquo;.</p>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-rules">
					<h3 class="pe-help-h" id="pe-help-rules" tabindex="-1">Rules</h3>
					<p>Each rule reads <strong>Criteria</strong> | <strong>Operator</strong> | <strong>Value</strong>, for example <?=$pe_rule('Last sign-in date', '≥', 'Jan 1, 2025')?>. Changing the criterion resets the operator and the value. Some criteria carry a note, for example about players with no value; hover over the operator or tab to it to read it.</p>
					<dl class="pe-help-dl">
						<dt>Dates</dt>
						<dd>Pick a day from the calendar. Dates show as &ldquo;Jan 1, 2025&rdquo;.</dd>
						<dt>Numbers</dt>
						<dd>Whole numbers. Some criteria have limits; ladder ranks, for example, are 0 or more.</dd>
						<dt>Between</dt>
						<dd>Two boxes, and both ends count: between 3 and 5 includes 3 and 5. You can fill them in either order. When you leave the pair, or press Run or Copy link, they are put smallest &rarr; largest.</dd>
						<dt>Pick-lists</dt>
						<dd>Classes, parks, kingdoms, awards and orders. Type to search, then pick; each choice becomes a chip. <em>is</em> and <em>is not</em> take one choice; <em>is any of</em> and <em>is none of</em> take several. Remove a chip with its &times; or with Backspace.</dd>
						<dt>Yes / No</dt>
						<dd>A two-button switch.</dd>
						<dt>Peerages</dt>
						<dd><em>has any of</em>, <em>has all of</em> or <em>has none of</em> the orders you pick, or <em>holds any</em> Yes / No for &ldquo;holds at least one at all&rdquo;.</dd>
						<dt>&ldquo;Last N months&rdquo;</dt>
						<dd>Sign-ins in last N months and Classes played in last N months have their own <strong>N</strong> box: 1 to 60 months, starting at 6.</dd>
					</dl>
					<p><strong>Last sign-in date or days ago?</strong> &ldquo;Last sign-in days ago&rdquo; counts whole days since the player&rsquo;s last sign-in, so it makes a rolling window you never have to update: <?=$pe_rule('Last sign-in days ago', '>', '180')?> finds players not seen for more than 180 days, and <?=$pe_rule('Last sign-in days ago', '≤', '30')?> finds players seen in the last 30. Players who have never signed in are not matched by any days-ago rule; use <?=$pe_rule('Total sign-ins', '=', '0')?> to find them.</p>
					<p><strong>Errors.</strong> If a rule is unfinished or not valid, Run stops. The rule is outlined in red with the reason under it, such as &ldquo;Choose at least one&rdquo;, and Results says &ldquo;Fix the highlighted rule&rdquo;. Editing the rule clears the error.</p>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-groups">
					<h3 class="pe-help-h" id="pe-help-groups" tabindex="-1">Groups: AND and OR</h3>
					<p>Every rule sits in a group, and the group&rsquo;s switch decides how its rules combine:</p>
					<ul>
						<li><?=$pe_join('AND')?> A player must match <strong>every</strong> rule. Each rule you add narrows the list.</li>
						<li><?=$pe_join('OR')?> A player must match <strong>at least one</strong> rule. Each rule you add widens the list.</li>
					</ul>
					<p>Click AND or OR at the top of a group to switch it; the label between the rules follows. <span class="pe-help-btn"><i class="fas fa-layer-group" aria-hidden="true"></i> Group</span> adds a group inside the current one, with one rule and the opposite switch, so you can mix &ldquo;all of&rdquo; and &ldquo;any of&rdquo;. Groups nest up to three levels deep, counting the outer one. Empty groups are ignored when the report runs.</p>
					<h4 class="pe-help-h4">Worked example</h4>
					<p>Knights who have signed in since the start of 2025 and have paid dues, and who either come often or did not last play Druid:</p>
					<div class="pe-help-tree" aria-hidden="true">
						<div class="pe-help-group">
							<?=$pe_rule('Last sign-in date', '≥', 'Jan 1, 2025')?>
							<?=$pe_join('AND')?>
							<?=$pe_rule('Knighthood', 'has any of', ['Knight of the Flame', 'Knight of the Sword', 'Knight of the Crown'])?>
							<?=$pe_join('AND')?>
							<?=$pe_rule('Dues paid', 'is', 'Yes')?>
							<?=$pe_join('AND')?>
							<div class="pe-help-group pe-help-group-or">
								<?=$pe_rule('Sign-ins in last N months (N = 6)', '>', '5')?>
								<?=$pe_join('OR')?>
								<?=$pe_rule('Last class played', 'is not', 'Druid')?>
							</div>
						</div>
					</div>
					<ol class="pe-help-steps">
						<li>Leave the outer group on <strong>AND</strong>.</li>
						<li>Press <strong>+ Rule</strong> and set <?=$pe_rule('Last sign-in date', '≥', 'Jan 1, 2025')?>.</li>
						<li>Press <strong>+ Rule</strong> and set <?=$pe_rule('Knighthood', 'has any of', ['Knight of the Flame', 'Knight of the Sword', 'Knight of the Crown'])?>.</li>
						<li>Press <strong>+ Rule</strong> and set <?=$pe_rule('Dues paid', 'is', 'Yes')?>.</li>
						<li>Press <strong>+ Group</strong>. The new group starts on <strong>OR</strong> with one rule: make it <?=$pe_rule('Sign-ins in last N months', '>', '5')?> with <strong>N</strong> = 6.</li>
						<li>Inside that group, press <strong>+ Rule</strong> and set <?=$pe_rule('Last class played', 'is not', 'Druid')?>.</li>
						<li>Press <strong>Run</strong>.</li>
					</ol>
					<p>It reads: signed in on or after Jan 1, 2025 <strong>and</strong> holds the Flame, Sword or Crown <strong>and</strong> has paid dues <strong>and</strong> (more than 5 sign-ins in the last 6 months <strong>or</strong> last played a class other than Druid). A player with no recorded class does not pass the Druid rule (see the next section), so they need the sign-ins rule instead.</p>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-not">
					<h3 class="pe-help-h" id="pe-help-not" tabindex="-1">&ldquo;Not&rdquo; rules and missing values</h3>
					<p>Some fields can be empty for a player. A rule on an empty field never matches that player, and that includes &ldquo;not&rdquo; rules (<em>&ne;</em>, <em>is not</em>, <em>is none of</em>). For example, <?=$pe_rule('Last class played', 'is not', 'Druid')?> skips players who have no sign-in with a class. The fields that can be empty:</p>
					<ul>
						<li><strong>Last sign-in date</strong> and <strong>Last sign-in days ago</strong>: empty for players who have never signed in.</li>
						<li><strong>Player since</strong>: empty for players with no sign-in dated 1988 or later.</li>
						<li><strong>Last class played</strong>: empty for players with no sign-in that recorded a class.</li>
						<li><strong>Last sign-in park</strong>: empty for players who have never signed in, or whose last sign-in was at an event with no park.</li>
						<li><strong>Dues paid through</strong>: empty for players with no dues paid to this <?=$pe_word?>.</li>
					</ul>
					<p>These &ldquo;not&rdquo; rules work the other way and <strong>do</strong> include players who have nothing:</p>
					<ul>
						<li><strong>Classes played in last N months</strong> with <em>is not</em> or <em>is none of</em> means &ldquo;did not play these classes in that time&rdquo;, so it also matches players with no sign-ins in that time.</li>
						<li><strong>Has award</strong>, <strong>Knighthood</strong>, <strong>Masterhood</strong>, <strong>Paragon</strong> and <strong>Squire / Page / Man-At-Arms held</strong> with <em>is not</em>, <em>is none of</em> or <em>has none of</em> also match players who hold none at all.</li>
						<li><strong>Ladder award ranks</strong> count &ldquo;no award in this ladder&rdquo; as rank 0, so any rule that rank 0 satisfies includes those players, such as <em>&ne; 5</em>, <em>&lt; 3</em> or <em>= 0</em>.</li>
					</ul>
					<p>Counts (Total sign-ins, Sign-ins in last N months, Award count) are 0 for a player with none, so they are never empty.</p>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-ref">
					<h3 class="pe-help-h" id="pe-help-ref" tabindex="-1">Criteria reference</h3>
					<p>Every criterion you can use on this <?=$pe_word?>, with its operators. The list is built from the same source as the rule picker, so the two always agree.</p>
					<div class="pe-help-ref" id="pe-help-ref-list"></div>
					<h4 class="pe-help-h4">Definitions</h4>
					<dl class="pe-help-dl">
						<dt>Player since</dt>
						<dd>The date of the player&rsquo;s first sign-in on or after Jan 1, 1988, as on the player profile. Earlier dates (such as 0000-00-00) are data-entry errors and are ignored.</dd>
						<dt>Dues paid and Dues paid through</dt>
						<dd>Worked out the same way as the Dues report, from dues paid to this <?=$pe_word?> that have not been revoked. Dues paid is Yes when those dues run through today or later, or are lifetime dues. Dues paid through is the latest such date; lifetime dues show as &ldquo;Lifetime&rdquo;.</dd>
						<dt>Last class played</dt>
						<dd>The class on the player&rsquo;s most recent sign-in that recorded a class.</dd>
						<dt>Last sign-in park</dt>
						<dd>The park of the player&rsquo;s most recent sign-in (the last one entered, if there were several that day). A sign-in at an event with no park counts as no park.</dd>
						<dt>Held awards and peerages</dt>
						<dd>Revoked and stripped awards don&rsquo;t count. A knighthood&rsquo;s order is the award itself: Knight of the Flame, of the Sword, of the Crown, and so on.</dd>
						<dt>Squire / Page / Man-At-Arms held</dt>
						<dd>Exactly those three. Lords-Page and Apprentice are not included.</dd>
						<dt>Any award received date</dt>
						<dd>Award dates before 1980 (such as 0000-00-00) count as unknown and are ignored.</dd>
						<dt>Ladder award ranks</dt>
						<?php if ($pe_is_park) : ?>
						<dd>One criterion per ladder award: the standard ladders, under the park&rsquo;s kingdom&rsquo;s own names where it renames them, plus the kingdom-specific ladders the ORK recognises for that kingdom. When a kingdom-specific ladder shares its name with another ladder, the kingdom&rsquo;s abbreviation is added to it, and its number too if that is still not enough. A player&rsquo;s rank is the highest rank recorded or the number of those awards they hold, whichever is larger, and 0 if they hold none.</dd>
<?php else : ?>
						<dd>One criterion per ladder award: the standard ladders, under this kingdom&rsquo;s own names where it renames them, plus the kingdom-specific ladders the ORK recognises for this kingdom (and for its principalities, when they are included). When a kingdom-specific ladder shares its name with another ladder, the kingdom&rsquo;s abbreviation is added to it, and its number too if that is still not enough. A player&rsquo;s rank is the highest rank recorded or the number of those awards they hold, whichever is larger, and 0 if they hold none.</dd>
<?php endif; ?>
						<dt>Reeve qualified and Corpora qualified</dt>
						<dd>The same rule as the Reeve Qualified and Corpora Qualified reports: the qualification is recorded, its expiry date has not passed, and the player is in good standing in the ORK<?php if ($pe_officer) : ?> (not suspended)<?php endif; ?>. Anyone who misses one of these shows No.</dd>
					</dl>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-results">
					<h3 class="pe-help-h" id="pe-help-results" tabindex="-1">Columns and results</h3>
					<ul>
						<li><strong>Columns:</strong> tick the ones to show. They appear in the order of the checkboxes. Persona is always included and links to the player&rsquo;s profile. Reset goes back to the defaults: Persona, Home park, Last sign-in date and Dues paid.</li>
						<li><strong>The table:</strong> click a heading to sort. &ldquo;Search results&rdquo; filters the rows already loaded. Show 25 rows per page, or more up to All, or Print the table.</li>
						<li><strong>Stats:</strong> <em>Results</em> is the number of matching players. <em>Of <?=$pe_word?> players</em> is that number as a share of everyone in this <?=$pe_word?>. <em>Run time</em> is how long the run took.</li>
						<li><strong>5,000-row limit:</strong> the table holds at most 5,000 players. When more match, a banner says &ldquo;Showing 5,000 of N&rdquo; and Results shows the full count. Narrow the filter to see everyone.</li>
						<li><strong>&ldquo;Filters changed since last run&rdquo;:</strong> appears beside Run when your rules or columns differ from the last run, so you know the table is out of date. A folded Filters card says &ldquo;changed since last run&rdquo; in its title.</li>
						<li><strong>After an error:</strong> if a run fails, the stats show &ldquo;&mdash;&rdquo; and the previous table stays, dimmed, under &ldquo;The table below is from your last successful run.&rdquo;</li>
					</ul>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-export">
					<h3 class="pe-help-h" id="pe-help-export" tabindex="-1">Export</h3>
					<ul>
						<li>Export downloads an Excel file (.xlsx) of your <strong>last run</strong>. It works once a run has succeeded. The rules and columns of that run are run again when you click, so the data is current; changes you have made since are not included until you Run again.</li>
						<li>Dates are written as text, YYYY-MM-DD. Yes / No fields say &ldquo;Yes&rdquo; or &ldquo;No&rdquo;. Lifetime dues say &ldquo;Lifetime&rdquo;.</li>
						<li>The same 5,000-row limit applies. When it is reached, a note at the bottom says &ldquo;Showing first 5,000 of N matches&rdquo;.</li>
					</ul>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-share">
					<h3 class="pe-help-h" id="pe-help-share" tabindex="-1">Share links</h3>
					<ul>
						<li><strong>Copy link</strong> copies a link holding your rules, your columns and this <?=$pe_word?>. It never holds the results. The link is also put in your address bar.</li>
						<li>Whoever opens it must be logged in. The report runs straight away, with current data.</li>
						<li>Finish or remove any unfinished rule first; Copy link highlights it.</li>
						<li>A very large filter is too long for a link; remove a few rules and try again.</li>
						<li>If a link holds a rule that is not valid here, such as another kingdom&rsquo;s ladder, the page opens with a warning and an empty builder.</li>
						<li>The officer-only rules (Suspended, Banned) are refused the same way for anyone who is not an officer of that kingdom or park.</li>
					</ul>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-officer">
					<h3 class="pe-help-h" id="pe-help-officer" tabindex="-1">Officer-only filters</h3>
					<p>The <strong>Suspended</strong> and <strong>Banned</strong> filters are offered only to people with officer authority over the kingdom or park being viewed: for a kingdom, its kingdom officers (for a principality, its parent kingdom&rsquo;s officers too); for a park, its park officers and its kingdom&rsquo;s officers (for a park in a principality, the parent kingdom&rsquo;s officers too); and ORK administrators. Everyone else does not see them in the rule picker, and a link or export that uses them is refused.</p>
<?php if ($pe_officer) : ?>
					<p>You are an officer here, so you will find them in the Status group.</p>
<?php else : ?>
					<p>They are not in your rule picker here.</p>
<?php endif; ?>
				</section>

				<section class="pe-help-section" aria-labelledby="pe-help-trouble">
					<h3 class="pe-help-h" id="pe-help-trouble" tabindex="-1">Troubleshooting</h3>
					<dl class="pe-help-dl">
						<dt>&ldquo;This query took too long — narrow your filter.&rdquo;</dt>
						<dd>Very broad filters on large kingdoms can take a while. A run has up to three database steps, and any single step that runs past 10 seconds is stopped and the run ends with this message, so in the worst case it can take about 30 seconds to appear. Add a rule that cuts the list down early, such as a recent Last sign-in date, or run it for a park instead of the whole kingdom.</dd>
						<dt>&ldquo;Another Population Explorer run of yours is still in progress&rdquo;</dt>
						<dd>You can run one report at a time. A run you started in another tab, or before reloading this page, is still working; it stops on its own within the time limit above. Wait for it, then press Run (or Export) again.</dd>
						<dt>&ldquo;Fix the highlighted rule&rdquo;</dt>
						<dd>The rule outlined in red says what is wrong under it. If the Filters card was folded, it opens and scrolls to that rule.</dd>
						<dt>A yellow banner ending &ldquo;The builder has been reset.&rdquo;</dt>
						<dd>The link you opened held a rule that is not valid here (see Share links). Build the filter again.</dd>
						<dt>&ldquo;Your session has ended.&rdquo;</dt>
						<dd>Log in again with the link in the message, then press Run.</dd>
						<dt>&ldquo;Could not reach the server.&rdquo;</dt>
						<dd>Check your connection and try again.</dd>
						<dt>Too many rules or choices</dt>
						<dd>A filter can hold up to 40 rules, and a pick-list up to 100 choices.</dd>
						<dt>No players match</dt>
						<dd>Loosen a rule, or switch a group to OR.</dd>
					</dl>
				</section>

			</div>
		</div>
	</div>

<?php endif; ?>
</div><!-- /rp-root -->

<?php if ($pe_ready) : ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
window.PE = <?=json_encode([
	'registry' => $pe_registry,
	'scope'    => ['type' => $pe_scope_type, 'id' => $pe_scope_id, 'name' => $pe_scope_name],
	'initial'  => $pe_initial,
	'urls'     => [
		'run'    => UIR . 'Reports/population_explorer_json',
		'export' => UIR . 'Reports/population_explorer_export',
		// Share links carry the filter in `pe`, never `q` (analytics logs `q` as a site search).
		'share'  => $pe_page_url . '&' . $pe_link_param . '=',
		'player' => UIR . 'Player/profile/',
	],
], $pe_json_flags)?>;
</script>
<script src="<?=HTTP_TEMPLATE?>default/script/populationexplorer.js?v=<?=filemtime(__DIR__ . '/script/populationexplorer.js')?>"></script>
<script src="<?=HTTP_TEMPLATE?>default/script/populationexplorer-help.js?v=<?=filemtime(__DIR__ . '/script/populationexplorer-help.js')?>"></script>
<?php endif; ?>
