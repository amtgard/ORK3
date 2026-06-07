<?php
// Pre-process: kingdoms list for filter, default dates
$kingdoms     = is_array($Kingdoms ?? null) ? $Kingdoms : [];
$prevYear     = (int)date('Y') - 1;
$defaultStart = $prevYear . '-01-01';
$defaultEnd   = $prevYear . '-12-31';
$minDate      = (string)($MinDate ?? '');  // earliest attendance date (for "All Time")
$maxDate      = (string)($MaxDate ?? '');  // latest attendance date
$rangeLimitMonths = (int)($RangeLimitMonths ?? 0);  // 12 in production, 0 = unlimited (local/dev)
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=20240101">

<!-- ============================================================
     STATE OF AMTGARD REPORT
     Route: Admin/stateofamtgard
     ============================================================ -->

<!-- HERO HEADER -->
<div class="cp-hero" style="background:linear-gradient(135deg,#1a1a2e 0%,#c0392b 100%);color:#fff;padding:32px 28px 24px;border-radius:8px;margin-bottom:24px;position:relative;overflow:hidden">
  <div style="position:relative;z-index:1">
    <h1 style="background:transparent!important;border:none!important;padding:0!important;border-radius:0!important;text-shadow:0 2px 8px rgba(0,0,0,0.4)!important;margin:0 0 6px 0;font-size:1.9rem;color:#fff">
      <i class="fas fa-globe" style="margin-right:10px;opacity:0.85"></i>State of Amtgard Report
    </h1>
    <p style="margin:0;opacity:0.85;font-size:0.95rem">Annual status report on recruitment, retention, parks, and class data from the ORK</p>
  </div>
</div>

<!-- PRINT HEADER (hidden on screen, shown only when printing to PDF) -->
<div id="sor-print-header" style="display:none;margin-bottom:16px;padding:12px 16px;border-bottom:2px solid #c0392b">
  <div style="font-size:1.1rem;font-weight:700;color:#1a1a2e;margin-bottom:4px">State of Amtgard Report</div>
  <div id="sor-print-meta" style="font-size:0.85rem;color:#555"></div>
</div>

<!-- FILTER CARD -->
<div class="sor-filter-card">
  <h2 class="sor-filter-heading"><i class="fas fa-filter" style="margin-right:8px"></i>Report Filters</h2>
  <div class="sor-filter-row">
    <div class="sor-filter-group">
      <label for="sor-start">Start Date</label>
      <input type="date" id="sor-start" value="<?= htmlspecialchars($defaultStart) ?>">
    </div>
    <div class="sor-filter-group">
      <label for="sor-end">End Date</label>
      <input type="date" id="sor-end" value="<?= htmlspecialchars($defaultEnd) ?>">
    </div>
    <?php if ($minDate !== '' && $maxDate !== '' && $rangeLimitMonths === 0): ?>
    <div class="sor-filter-group sor-filter-quick">
      <label>&nbsp;</label>
      <button type="button" id="sor-btn-all-time" class="sor-btn-sm"
              data-min="<?= htmlspecialchars($minDate) ?>" data-max="<?= htmlspecialchars($maxDate) ?>"
              title="Set dates to the full attendance history (<?= htmlspecialchars($minDate) ?> &ndash; <?= htmlspecialchars($maxDate) ?>)">All Time</button>
    </div>
    <?php endif; ?>
    <div class="sor-filter-group sor-filter-kingdoms">
      <label for="sor-kingdoms">Kingdoms <small style="font-weight:400;color:#888">(Ctrl+click to multi-select)</small></label>
      <select id="sor-kingdoms" multiple size="6">
        <?php foreach ($kingdoms as $k): ?>
        <option value="<?= (int)$k['kingdom_id'] ?>" selected><?= htmlspecialchars($k['kingdom_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="sor-filter-kingdom-actions">
        <button type="button" id="sor-btn-select-all" class="sor-btn-sm">Select All</button>
        <button type="button" id="sor-btn-select-all-except-freeholds" class="sor-btn-sm">Select All Except Freeholds</button>
        <button type="button" id="sor-btn-clear-all" class="sor-btn-sm">Clear All</button>
      </div>
    </div>
  </div>
  <div class="sor-filter-actions">
    <button id="sor-generate-btn" class="sor-btn-generate" onclick="sorGenerate()">
      <i class="fas fa-chart-bar"></i> Generate Report
    </button>
    <button id="sor-print-btn" class="sor-btn-generate sor-btn-print" onclick="window.print()" disabled
            title="Generate the report first, then save it as a PDF">
      <i class="fas fa-file-pdf"></i> Print / Save as PDF
    </button>
    <span id="sor-status" class="sor-status"></span>
  </div>
</div>

<!-- Kingdom multi-select helpers — isolated script so any error in the
     main orchestrator IIFE cannot prevent these buttons from working. -->
<script>
// Max reporting-window span enforced in production (server-crash guard); 0 = unlimited (local/dev).
window.SOR_RANGE_LIMIT_MONTHS = <?= (int)$rangeLimitMonths ?>;
(function () {
	function sorKingdomSelect(predicate) {
		var sel = document.getElementById('sor-kingdoms');
		if (!sel) return;
		for (var i = 0; i < sel.options.length; i++) {
			sel.options[i].selected = predicate(sel.options[i]);
		}
	}
	window.sorSelectAll = function () { sorKingdomSelect(function () { return true; }); };
	window.sorClearAll  = function () { sorKingdomSelect(function () { return false; }); };
	window.sorSelectAllExceptFreeholds = function () {
		sorKingdomSelect(function (opt) {
			return opt.text.toLowerCase().indexOf('freeholds') === -1;
		});
	};
	// "All Time": set the date range to the full attendance history (data-min/data-max
	// are emitted server-side from MIN/MAX(attendance.date)).
	window.sorAllTime = function () {
		var btn = document.getElementById('sor-btn-all-time');
		var s = document.getElementById('sor-start');
		var e = document.getElementById('sor-end');
		if (!btn || !s || !e) return;
		if (btn.dataset.min) s.value = btn.dataset.min;
		if (btn.dataset.max) e.value = btn.dataset.max;
	};
	var bind = function (id, fn) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', fn);
	};
	bind('sor-btn-select-all',                  window.sorSelectAll);
	bind('sor-btn-select-all-except-freeholds', window.sorSelectAllExceptFreeholds);
	bind('sor-btn-clear-all',                   window.sorClearAll);
	bind('sor-btn-all-time',                    window.sorAllTime);
}());
</script>

<!-- REPORT SECTIONS (hidden until generated) -->
<div id="sor-report" style="display:none">

  <!-- Executive Scorecard (populated after all sections load) -->
  <div id="sor-scorecard" style="display:none"></div>

  <!-- Table of Contents -->
  <nav id="sor-toc" class="sor-toc" aria-label="Report sections">
    <div class="sor-toc-title"><i class="fas fa-list" style="margin-right:6px"></i>Sections</div>
    <ul class="sor-toc-list">
      <li><a href="#sor-section-players"><i class="fas fa-users"></i>Player Statistics</a></li>
      <li><a href="#sor-section-kingdoms"><i class="fas fa-crown"></i>Sign-Ins by Kingdom</a></li>
      <li><a href="#sor-section-classes"><i class="fas fa-graduation-cap"></i>Sign-Ins by Class</a></li>
      <li><a href="#sor-section-parks"><i class="fas fa-map-marker-alt"></i>Parks Analysis</a></li>
      <li><a href="#sor-section-awards"><i class="fas fa-medal"></i>Award Grants</a></li>
    </ul>
  </nav>

  <!-- Players section -->
  <div class="sor-report-section">
    <div class="sor-section-divider">
      <h2 class="sor-section-heading"><i class="fas fa-users" style="margin-right:8px"></i>Player Statistics</h2>
    </div>
<div id="sor-section-players" class="sor-section">

  <!-- Skeleton -->
  <div class="sor-skeleton" id="sor-players-skeleton">
    <div class="sor-skeleton-row">
      <div class="sor-skeleton-card"></div>
      <div class="sor-skeleton-card"></div>
      <div class="sor-skeleton-card"></div>
      <div class="sor-skeleton-card"></div>
    </div>
    <div class="sor-skeleton-bar medium"></div>
    <div class="sor-skeleton-bar"></div>
    <div class="sor-skeleton-bar short"></div>
    <div class="sor-skeleton-chart"></div>
  </div>

  <!-- Main content (hidden until renderSorPlayers called) -->
  <div id="sor-players-content" style="display:none">

    <!-- 1. Period stat cards -->
    <div class="sor-players-cards" id="sor-players-period-cards">
      <!-- injected by JS -->
    </div>

    <!-- 1b. Cohort funnel (injected by renderSorCohorts) -->
    <div id="sor-players-funnel" style="display:none">
      <div class="sor-players-funnel-wrap">
        <span class="sor-players-chart-title">Figure 3a &mdash; Player Engagement Funnel</span>
        <div id="sor-players-funnel-chart" style="height:130px"></div>
      </div>
    </div>

    <!-- 2. Normal player callout -->
    <div class="sor-players-normal-callout">
      <strong>What is a "normal player"?</strong>
      A normal player is defined as someone with <strong>4 or more sign-ins</strong> and <strong>at least 12 credits</strong> during the report period.
      Players with only 1&ndash;3 sign-ins are visitors or trial attendees and are excluded from normal-player averages
      to give a more representative picture of engaged community members.
    </div>

    <!-- 3. Two-column prose stats -->
    <div class="sor-players-prose" id="sor-players-prose">
      <!-- injected by JS -->
    </div>

    <!-- 4. Trend chart -->
    <div class="sor-players-chart-wrap">
      <span class="sor-players-chart-title">Figure 3b &mdash; Activity &amp; Engagement Trend</span>
      <div id="sor-players-chart"></div>
    </div>


    <!-- 4b. Longevity chart (injected when longevity data loads) -->
    <div id="sor-players-longevity" style="display:none;margin-bottom:20px">
      <span class="sor-players-chart-title">Figure 3c &mdash; Playerbase Longevity</span>
      <div class="sor-longevity-layout" style="margin-top:8px">
        <div id="sor-longevity-chart" style="min-height:300px;flex:1 1 400px"></div>
        <div id="sor-longevity-legend" class="sor-longevity-legend"></div>
      </div>
      <p class="sor-note" style="margin-top:6px">
        Longevity is measured from a player&#8217;s first-ever attendance record to the end of the report period.
        Only players active during the selected date range are included.
      </p>
    </div>

    <!-- 4c. New-player conversion & retention (fixed mature-cohort analysis) -->
    <div id="sor-players-retention" style="display:none;margin-bottom:20px">
      <span class="sor-players-chart-title">Figure 3d &mdash; New Player Conversion &amp; Retention</span>
      <div id="sor-retention-body" style="margin-top:8px"></div>
      <p class="sor-note" style="margin-top:6px">
        Fixed-cohort analysis: only players whose first-ever sign-in was at least 2 years before the latest data, so retention isn&#8217;t censored by recent joiners. This panel ignores the date range above (it always studies mature cohorts) but respects the kingdom filter. A &ldquo;real joiner&rdquo; is anyone with 4+ sign-in days &mdash; matching this report&#8217;s definition of 1&ndash;3 sign-ins as visitors/trial attendees. &ldquo;Still active at N&rdquo; means they actually attended within 3 months of that anniversary, not merely that their first&ndash;last span reached it.
      </p>
    </div>

    <!-- 5. 10-year aggregate (collapsible) -->
    <div class="sor-players-tenyear" id="sor-players-tenyear">
      <button class="sor-players-tenyear-toggle" id="sor-players-tenyear-btn" onclick="sorPlayersToggleTenYear()">
        &#128337; Past 10 Years &mdash; Aggregate Statistics
        <i class="sor-players-tenyear-arrow">&#9660;</i>
      </button>
      <div class="sor-players-tenyear-body" id="sor-players-tenyear-body">
        <div class="sor-players-cards compact" id="sor-players-tenyear-cards">
          <!-- injected by JS -->
        </div>
        <div class="sor-players-normal-callout" style="margin-bottom:20px">
          <strong>10-year window:</strong> Aggregates all sign-ins and players recorded over the past decade.
          Normal-player averages are especially meaningful here as long-term engagement patterns emerge across
          multiple years of participation.
        </div>
        <div class="sor-players-prose" id="sor-players-tenyear-prose">
          <!-- injected by JS -->
        </div>
      </div>
    </div>

  </div><!-- /sor-players-content -->
</div><!-- /sor-section-players -->
    <div class="sor-back-to-top"><a href="#sor-toc"><i class="fas fa-arrow-up"></i> Back to Top</a></div>
  </div>

  <!-- Kingdoms section -->
  <div class="sor-report-section">
    <div class="sor-section-divider">
      <h2 class="sor-section-heading"><i class="fas fa-crown" style="margin-right:8px"></i>Sign-Ins by Kingdom</h2>
    </div>
<div id="sor-section-kingdoms" class="sor-section">

  <!-- Section header -->
  <div class="sor-section-header">
    <span class="sor-section-subtitle" id="sor-kingdoms-period">&mdash;</span>
  </div>

  <!-- Skeleton shown while data loads -->
  <div class="sor-skeleton" id="sor-kingdoms-skeleton">
    <div class="sor-skeleton-header"></div>
    <div class="sor-skeleton-row" style="width:100%"></div>
    <div class="sor-skeleton-row" style="width:97%"></div>
    <div class="sor-skeleton-row" style="width:95%"></div>
    <div class="sor-skeleton-row" style="width:98%"></div>
    <div class="sor-skeleton-row" style="width:93%"></div>
    <div class="sor-skeleton-row" style="width:96%"></div>
    <div class="sor-skeleton-row" style="width:91%"></div>
    <div class="sor-skeleton-chart"></div>
  </div>

  <!-- Content area — hidden until renderSorKingdoms() is called -->
  <div class="sor-kingdoms-content" id="sor-kingdoms-content" style="display:none">
    <div class="sor-kingdoms-stacked-layout">

      <!-- TOP: ranked table (full-width) -->
      <div class="sor-kingdoms-table-section">
        <table class="sor-table" id="sor-kingdoms-table">
          <thead>
            <tr>
              <th data-col="rank"       class="sor-sort-asc" style="width:42px">
                <span>Rank</span><em class="sor-sort-icon"></em>
              </th>
              <th data-col="name" style="min-width:140px">
                <span>Kingdom</span><em class="sor-sort-icon"></em>
              </th>
              <th data-col="sign_ins" style="width:90px">
                <span>Sign-Ins</span><em class="sor-sort-icon"></em>
              </th>
              <th data-col="percentage" style="width:110px">
                <span>% of Total</span><em class="sor-sort-icon"></em>
              </th>
              <th data-col="yoy_change" style="width:80px;text-align:right">
                <span>YoY &Delta;</span><em class="sor-sort-icon"></em>
              </th>
              <th data-col="yoy_pct" style="width:70px;text-align:right">
                <span>YoY %</span><em class="sor-sort-icon"></em>
              </th>
            </tr>
          </thead>
          <tbody id="sor-kingdoms-tbody">
            <!-- rows injected by renderSorKingdoms() -->
          </tbody>
        </table>
      </div>

      <!-- BOTTOM: full-width chart -->
      <div class="sor-kingdoms-chart-section">
        <div class="sor-chart-card">
          <div id="sor-kingdoms-chart" style="height:400px;min-height:300px"></div>
        </div>
      </div>

    </div>
  </div>

</div><!-- /sor-section-kingdoms -->
    <div class="sor-back-to-top"><a href="#sor-toc"><i class="fas fa-arrow-up"></i> Back to Top</a></div>
  </div>

  <!-- Classes section -->
  <div class="sor-report-section">
    <div class="sor-section-divider">
      <h2 class="sor-section-heading"><i class="fas fa-graduation-cap" style="margin-right:8px"></i>Sign-Ins by Class</h2>
    </div>
<div id="sor-section-classes" class="sor-section">

  <!-- Loading skeleton -->
  <div class="sor-skeleton" id="sor-classes-skeleton">
    <div class="sor-skeleton-col">
      <div class="sor-skeleton-block sor-skeleton-header"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
      <div class="sor-skeleton-block sor-skeleton-row"></div>
    </div>
    <div class="sor-skeleton-col">
      <div class="sor-skeleton-block sor-skeleton-header" style="width:40%"></div>
      <div class="sor-skeleton-block sor-skeleton-chart"></div>
    </div>
  </div>

  <!-- Actual content (hidden until data loaded) -->
  <div id="sor-classes-content" style="display:none">
    <div id="sor-diversity-card-wrap"></div>
    <div class="sor-dual-layout">

      <div class="sor-table-wrap">
        <p class="sor-table-label">Table 2 &mdash; Class Sign-In Counts (Ranked)</p>
        <table class="sor-class-table" id="sor-classes-table">
          <caption>* Classes marked &#x26A0; may have inflated sign-in counts.</caption>
          <thead>
            <tr>
              <th class="sor-col-rank">#</th>
              <th>Class</th>
              <th class="sor-col-signins">Sign-Ins</th>
              <th class="sor-col-pct">Percentage</th>
            </tr>
          </thead>
          <tbody id="sor-classes-tbody"></tbody>
        </table>
      </div>

      <div class="sor-chart-wrap">
        <p class="sor-table-label">Figure 2 &mdash; Sign-In Distribution by Class (%)</p>
        <div id="sor-classes-chart" style="height:350px"></div>
        <p class="sor-note">&#x26A0; Sign-ins for Color and Warrior may be inflated due to individual kingdom corpora requirements.</p>
      </div>

    </div>
  </div>

</div>
<!-- ===== END SECTION ===== -->
    <div class="sor-back-to-top"><a href="#sor-toc"><i class="fas fa-arrow-up"></i> Back to Top</a></div>
  </div>

  <!-- Parks section -->
  <div class="sor-report-section">
    <div class="sor-section-divider">
      <h2 class="sor-section-heading"><i class="fas fa-map-marker-alt" style="margin-right:8px"></i>Parks Analysis</h2>
    </div>
<div id="sor-section-parks" class="sor-section">

  <!-- Loading skeleton -->
  <div class="sor-skeleton" id="sor-parks-skeleton">
    <div class="sor-parks-skeleton-cards">
      <div class="sor-parks-skeleton-card sor-skeleton-bar"></div>
      <div class="sor-parks-skeleton-card sor-skeleton-bar"></div>
      <div class="sor-parks-skeleton-card sor-skeleton-bar"></div>
      <div class="sor-parks-skeleton-card sor-skeleton-bar"></div>
    </div>
    <div class="sor-skeleton-bar sor-parks-skeleton-text" style="width:80%"></div>
    <div class="sor-skeleton-bar sor-parks-skeleton-text" style="width:65%"></div>
    <div class="sor-skeleton-bar sor-parks-skeleton-text sor-parks-skeleton-text-short"></div>
    <div class="sor-skeleton-bar sor-parks-skeleton-table"></div>
    <div class="sor-skeleton-bar sor-parks-skeleton-chart"></div>
  </div>

  <!-- Rendered content (hidden until data arrives) -->
  <div id="sor-parks-content" style="display:none">

    <!-- 1. Summary stats bar -->
    <div class="sor-parks-stats-bar" id="sor-parks-stats-bar"></div>

    <!-- 2. Narrative + expandable lists -->
    <div class="sor-parks-narrative" id="sor-parks-narrative"></div>

    <!-- Net Park Change chart -->
    <div class="sor-parks-chart-section" style="margin-bottom:24px">
      <div class="sor-parks-chart-title">Figure 5a &mdash; Net Park Change by Kingdom</div>
      <div id="sor-parks-net-chart" style="height:220px"></div>
    </div>

    <div class="sor-parks-expandable" id="sor-parks-new-expandable">
      <button class="sor-parks-expandable-header" id="sor-parks-new-toggle"
              onclick="sorParksToggle('new')" type="button">
        <span id="sor-parks-new-toggle-label">New Parks</span>
        <span class="sor-parks-expand-icon">&#9660;</span>
      </button>
      <div class="sor-parks-expandable-body" id="sor-parks-new-body"></div>
    </div>

    <div class="sor-parks-expandable" id="sor-parks-lost-expandable" style="margin-bottom:24px">
      <button class="sor-parks-expandable-header" id="sor-parks-lost-toggle"
              onclick="sorParksToggle('lost')" type="button">
        <span id="sor-parks-lost-toggle-label">Lost Parks</span>
        <span class="sor-parks-expand-icon">&#9660;</span>
      </button>
      <div class="sor-parks-expandable-body" id="sor-parks-lost-body"></div>
    </div>

    <!-- 3. At-risk alert -->
    <div id="sor-parks-risk-alert"></div>

    <!-- 4. Parks by Kingdom table -->
    <div class="sor-parks-table-wrap">
      <table class="sor-parks-table" id="sor-parks-kingdom-table">
        <thead>
          <tr>
            <th onclick="sorParksSort(0)" data-col="0">Kingdom <span class="sor-parks-sort-icon"></span></th>
            <th onclick="sorParksSort(1)" data-col="1" style="text-align:right">Active Parks <span class="sor-parks-sort-icon"></span></th>
            <th onclick="sorParksSort(2)" data-col="2" style="text-align:right">Retired Parks <span class="sor-parks-sort-icon"></span></th>
            <th onclick="sorParksSort(3)" data-col="3" style="text-align:right">Ratio <span class="sor-parks-sort-icon"></span></th>
          </tr>
        </thead>
        <tbody id="sor-parks-table-body"></tbody>
      </table>
    </div>

    <!-- 5. Parks charts -->
    <div class="sor-parks-chart-section">
      <div class="sor-parks-chart-title">Figure 5b &mdash; Park Health by Kingdom (Stacked)</div>
      <div id="sor-parks-health-chart"></div>
    </div>
    <div class="sor-parks-chart-section" style="margin-top:24px">
      <div class="sor-parks-chart-title">Figure 5c &mdash; Active vs. Retired Parks (Grouped)</div>
      <div class="sor-parks-chart-outer">
        <div id="sor-parks-chart-container"></div>
      </div>
    </div>

  </div><!-- /sor-parks-content -->
</div><!-- /sor-section-parks -->
    <div class="sor-back-to-top"><a href="#sor-toc"><i class="fas fa-arrow-up"></i> Back to Top</a></div>
  </div>

  <!-- Award Grants section -->
  <div class="sor-report-section">
    <div class="sor-section-divider">
      <h2 class="sor-section-heading"><i class="fas fa-medal" style="margin-right:8px"></i>Award Grants</h2>
    </div>
<div id="sor-section-awards" class="sor-section">

  <!-- Section header -->
  <div class="sor-section-header">
    <span class="sor-section-subtitle" id="sor-awards-period">Newly granted Knights, Paragons, and Masters in the selected period.</span>
  </div>

  <!-- Loading skeleton -->
  <div class="sor-skeleton" id="sor-awards-skeleton">
    <div class="sor-skeleton-row">
      <div class="sor-skeleton-card"></div>
      <div class="sor-skeleton-card"></div>
      <div class="sor-skeleton-card"></div>
    </div>
    <div class="sor-skeleton-bar sor-awards-skeleton-chart"></div>
    <div class="sor-awards-skeleton-charts">
      <div class="sor-skeleton-chart sor-awards-skeleton-pie"></div>
      <div class="sor-skeleton-chart sor-awards-skeleton-pie"></div>
      <div class="sor-skeleton-chart sor-awards-skeleton-pie"></div>
    </div>
    <div class="sor-skeleton-row" style="width:96%"></div>
    <div class="sor-skeleton-row" style="width:90%"></div>
    <div class="sor-skeleton-row" style="width:93%"></div>
  </div>

  <!-- Rendered content -->
  <div id="sor-awards-content" style="display:none">

    <!-- 0. Data accuracy note -->
    <div class="sor-awards-data-note" role="note">
      <i class="fas fa-info-circle sor-awards-data-note-icon" aria-hidden="true"></i>
      <span><strong>Note:</strong> This data is dependent on accurate recording of awards. Older awards applied as custom titles, etc. will not be counted and should be reconciled.</span>
    </div>

    <!-- 1. KPI summary row -->
    <div class="sor-awards-kpi-row" id="sor-awards-kpi-row"></div>

    <!-- 1b. 10-year peerage grant trend -->
    <div class="sor-chart-card sor-awards-chart-card sor-awards-trend-card" id="sor-awards-trend-card">
      <div id="sor-awards-trend-chart" class="sor-awards-trend-chart"></div>
    </div>

    <!-- 2. Four pie charts in a 2x2 grid -->
    <div class="sor-awards-charts-row">
      <div class="sor-chart-card sor-awards-chart-card">
        <div class="sor-awards-chart-title">Overall Peerage Split</div>
        <div class="sor-awards-chart-sub" id="sor-awards-overall-sub">&mdash;</div>
        <div id="sor-awards-overall-chart" class="sor-awards-pie"></div>
      </div>
      <div class="sor-chart-card sor-awards-chart-card">
        <div class="sor-awards-chart-title">Knights</div>
        <div class="sor-awards-chart-sub" id="sor-awards-knights-sub">&mdash;</div>
        <div id="sor-awards-knights-chart" class="sor-awards-pie"></div>
      </div>
      <div class="sor-chart-card sor-awards-chart-card">
        <div class="sor-awards-chart-title">Paragons</div>
        <div class="sor-awards-chart-sub" id="sor-awards-paragons-sub">&mdash;</div>
        <div id="sor-awards-paragons-chart" class="sor-awards-pie"></div>
      </div>
      <div class="sor-chart-card sor-awards-chart-card">
        <div class="sor-awards-chart-title">Masters</div>
        <div class="sor-awards-chart-sub" id="sor-awards-masters-sub">&mdash;</div>
        <div id="sor-awards-masters-chart" class="sor-awards-pie"></div>
      </div>
    </div>

    <!-- 3. Top kingdoms table -->
    <div class="sor-awards-table-wrap">
      <div class="sor-awards-table-title">Kingdoms by Peerage Grants</div>
      <table class="sor-table sor-awards-table" id="sor-awards-kingdoms-table">
        <thead>
          <tr>
            <th style="text-align:left">Kingdom</th>
            <th style="text-align:right;width:80px">Knights</th>
            <th style="text-align:right;width:90px">Paragons</th>
            <th style="text-align:right;width:80px">Masters</th>
            <th style="text-align:right;width:70px">Total</th>
          </tr>
        </thead>
        <tbody id="sor-awards-kingdoms-tbody"></tbody>
      </table>
    </div>

  </div><!-- /sor-awards-content -->
</div><!-- /sor-section-awards -->
    <div class="sor-back-to-top"><a href="#sor-toc"><i class="fas fa-arrow-up"></i> Back to Top</a></div>
  </div>


</div><!-- /sor-report -->

<!-- ============================================================
     STYLES
     ============================================================ -->
<style>
/* ---- Table of Contents ---- */
html {
  scroll-behavior: smooth;
  scroll-padding-top: 80px;
}
.sor-toc {
  background: var(--ork-card-bg, #fff);
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  padding: 14px 18px;
  margin-bottom: 24px;
}
.sor-toc-title {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #4a5568;
  margin-bottom: 10px;
}
.sor-toc-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.sor-toc-list li {
  margin: 0;
}
.sor-toc-list a {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  background: #f7fafc;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  color: #2d3748;
  font-size: 0.85rem;
  font-weight: 600;
  text-decoration: none;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.sor-toc-list a:hover {
  background: #edf2f7;
  border-color: #c0392b;
  color: #c0392b;
  transform: translateY(-1px);
}
.sor-toc-list a i {
  font-size: 0.8rem;
  opacity: 0.75;
}

/* ---- Back to Top ---- */
.sor-back-to-top {
  text-align: right;
  margin-top: 16px;
  padding-top: 10px;
  border-top: 1px dashed #e2e8f0;
}
.sor-back-to-top a {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: #718096;
  font-size: 0.82rem;
  font-weight: 600;
  text-decoration: none;
  padding: 4px 10px;
  border-radius: 4px;
  transition: color 0.15s ease, background 0.15s ease;
}
.sor-back-to-top a:hover {
  color: #c0392b;
  background: #f7fafc;
}
.sor-back-to-top a i {
  font-size: 0.75rem;
}

/* ---- Filter Card ---- */
.sor-filter-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.09);
  padding: 22px 24px 18px;
  margin-bottom: 28px;
}

.sor-filter-heading {
  background: transparent !important;
  border: none !important;
  padding: 0 0 14px 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  margin: 0 0 16px 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: #2d3748;
  border-bottom: 2px solid #edf2f7 !important;
}

.sor-filter-row {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  align-items: flex-start;
  margin-bottom: 16px;
}

.sor-filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.sor-filter-group label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #4a5568;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.sor-filter-group input[type="date"] {
  padding: 7px 10px;
  border: 1px solid #d1d5db;
  border-radius: 5px;
  font-size: 0.9rem;
  color: #2d3748;
  background: #f9fafb;
  outline: none;
  transition: border-color 0.15s;
}

.sor-filter-group input[type="date"]:focus {
  border-color: #c0392b;
  background: #fff;
}

.sor-filter-kingdoms select {
  border: 1px solid #d1d5db;
  border-radius: 5px;
  padding: 6px 8px;
  font-size: 0.88rem;
  min-width: 200px;
  max-width: 360px;
  background: #f9fafb;
  color: #2d3748;
  outline: none;
}

.sor-filter-kingdoms select:focus {
  border-color: #c0392b;
  background: #fff;
}

/* Setting custom backgrounds on <option> disables the browser's native
   selection highlight, so we paint it ourselves. The linear-gradient hack
   wins over the option's flat background-color in Chromium/WebKit. */
.sor-filter-kingdoms select option:checked,
.sor-filter-kingdoms select option:checked:hover {
  background: linear-gradient(0deg, #c0392b 0%, #c0392b 100%) !important;
  color: #fff !important;
  font-weight: 600;
}

.sor-filter-kingdom-actions {
  display: flex;
  gap: 8px;
  margin-top: 4px;
}

.sor-btn-sm {
  padding: 4px 10px;
  font-size: 0.78rem;
  border: 1px solid #d1d5db;
  border-radius: 4px;
  background: #f4f4f5;
  color: #4a5568;
  cursor: pointer;
  transition: background 0.12s;
}
.sor-btn-sm:hover { background: #e4e4e7; }

.sor-filter-actions {
  display: flex;
  align-items: center;
  gap: 16px;
}

.sor-btn-generate {
  background: #c0392b;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 10px 22px;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, transform 0.1s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.sor-btn-generate:hover { background: #a93226; }
.sor-btn-generate:active { transform: scale(0.98); }
.sor-btn-generate:disabled { background: #e2e8f0; color: #a0aec0; cursor: not-allowed; }
/* Print / Save as PDF — distinct (blue) from the red Generate; greys out until ready. */
.sor-btn-print { background: #2980b9; margin-left: 8px; }
.sor-btn-print:hover:not(:disabled) { background: #1f6391; }

.sor-status {
  font-size: 0.85rem;
  color: #718096;
  font-style: italic;
}

.sor-status.sor-status-error {
  color: #c0392b;
  font-style: normal;
  font-weight: 600;
}

.sor-status.sor-status-ok {
  color: #27ae60;
  font-style: normal;
}

/* ---- Report section spacing ---- */
.sor-report-section {
  margin-bottom: 40px;
}

.sor-section-divider {
  margin-bottom: 16px;
  padding-bottom: 10px;
  border-bottom: 2px solid #e2e8f0;
}

.sor-section-heading {
  background: transparent !important;
  border: none !important;
  padding: 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
  color: #2d3748;
}

/* ---- Executive Scorecard ---- */
#sor-scorecard {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 28px;
  padding: 0 4px;
}
.sor-kpi-card {
  flex: 1 1 130px;
  background: #fff;
  border-radius: 10px;
  border-top: 4px solid #ccc;
  padding: 16px 14px 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  text-align: center;
  min-width: 110px;
}
.sor-kpi-icon  { font-size: 18px; opacity: 0.45; margin-bottom: 4px; display: block; }
.sor-kpi-value { font-size: 1.7rem; font-weight: 700; line-height: 1.1; display: block; }
.sor-kpi-label { font-size: 0.68rem; font-weight: 600; color: #888;
                 text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; display: block; }
.sor-kpi-sub   { font-size: 0.65rem; color: #aaa; display: block; margin-top: 2px; }

/* ---- Cohort Funnel ---- */
.sor-players-funnel-wrap {
  margin: 0 0 20px;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8ecf0;
  padding: 14px 16px 6px;
}

/* ---- Parks enhancement charts ---- */
#sor-parks-net-chart    { background: #fff; border-radius: 8px; padding: 8px; }
#sor-parks-health-chart { background: #fff; border-radius: 8px; padding: 8px; }

/* ---- Diversity Index Card ---- */
.sor-diversity-card {
  background: #f8f9fc;
  border: 1px solid #e2e8f0;
  border-left: 4px solid #2980b9;
  border-radius: 6px;
  padding: 10px 14px;
  margin-bottom: 12px;
  font-size: 0.9rem;
  color: #2d3748;
}
/* ---- Accent red for engagement rate ---- */
.sor-players-card.accent-red { border-top-color: #c0392b; }
.sor-players-card.accent-red .sor-players-card-value { color: #c0392b; }
</style>

<style>
/* ----------------------------------------------------------
   SOR Section: Kingdoms — scoped to .sor-section
   ---------------------------------------------------------- */

/* ---- Base section shell ---- */
.sor-section {
  font-family: inherit;
  color: #2d3748;
  max-width: 1100px;
}

/* ---- Section header ---- */
.sor-section-header {
  display: flex;
  align-items: baseline;
  gap: 12px;
  margin-bottom: 18px;
  padding-bottom: 10px;
  border-bottom: 3px solid #c0392b;
}

.sor-section-title {
  font-size: 20px;
  font-weight: 700;
  color: #c0392b;
  margin: 0;
  /* Override global orkui.css h-tag styles */
  background: transparent !important;
  border: none !important;
  padding: 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  box-shadow: none !important;
}

.sor-section-subtitle {
  font-size: 13px;
  color: #718096;
  font-weight: 400;
}

/* ---- Skeleton loader ---- */
#sor-kingdoms-skeleton {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 4px 0;
}

.sor-skeleton-header {
  width: 240px;
  height: 22px;
  border-radius: 4px;
  background: linear-gradient(90deg, #e2e8f0 25%, #edf2f7 50%, #e2e8f0 75%);
  background-size: 400% 100%;
  animation: sor-shimmer 1.4s ease-in-out infinite;
  margin-bottom: 6px;
}

.sor-skeleton-row {
  height: 34px;
  border-radius: 4px;
  background: linear-gradient(90deg, #e2e8f0 25%, #edf2f7 50%, #e2e8f0 75%);
  background-size: 400% 100%;
  animation: sor-shimmer 1.4s ease-in-out infinite;
}

.sor-skeleton-row:nth-child(2)  { animation-delay: 0.04s; }
.sor-skeleton-row:nth-child(3)  { animation-delay: 0.08s; }
.sor-skeleton-row:nth-child(4)  { animation-delay: 0.12s; }
.sor-skeleton-row:nth-child(5)  { animation-delay: 0.16s; }
.sor-skeleton-row:nth-child(6)  { animation-delay: 0.20s; }
.sor-skeleton-row:nth-child(7)  { animation-delay: 0.24s; }
.sor-skeleton-row:nth-child(8)  { animation-delay: 0.28s; }

.sor-skeleton-chart {
  height: 220px;
  border-radius: 4px;
  background: linear-gradient(90deg, #e2e8f0 25%, #edf2f7 50%, #e2e8f0 75%);
  background-size: 400% 100%;
  animation: sor-shimmer 1.4s ease-in-out infinite 0.35s;
  margin-top: 10px;
}

@keyframes sor-shimmer {
  0%   { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}

/* ---- Dual layout: table + chart side by side ---- */
.sor-dual-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

.sor-table-wrap {
  flex: 0 0 auto;
  width: 100%;
  max-width: 460px;
  min-width: 280px;
}

.sor-chart-wrap {
  flex: 1 1 0;
  min-width: 0;
}

@media (max-width: 820px) {
  .sor-dual-layout {
    flex-direction: column;
  }
  .sor-table-wrap {
    max-width: 100%;
    width: 100%;
  }
  .sor-chart-wrap {
    width: 100%;
  }
}

/* ---- Table ---- */
.sor-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.08);
  border-radius: 6px;
  overflow: hidden;
  background: #fff;
}

.sor-table thead th {
  background-color: #c0392b !important;
  color: #fff !important;
  font-weight: 600;
  padding: 9px 12px;
  text-align: left;
  white-space: nowrap;
  cursor: pointer;
  user-select: none;
  border: none !important;
  font-size: 12px;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  /* Override global orkui.css h-tag / th styles */
  border-radius: 0 !important;
  text-shadow: none !important;
  box-shadow: none !important;
}

.sor-table thead th:hover {
  background-color: #a93226 !important;
}

.sor-table thead th .sor-sort-icon {
  font-style: normal;
  margin-left: 4px;
  opacity: 0.6;
  font-size: 10px;
}

.sor-table thead th.sor-sort-asc  .sor-sort-icon::after { content: " \25B2"; opacity: 1; }
.sor-table thead th.sor-sort-desc .sor-sort-icon::after { content: " \25BC"; opacity: 1; }
.sor-table thead th:not(.sor-sort-asc):not(.sor-sort-desc) .sor-sort-icon::after { content: " \25B6"; opacity: 0.35; }

.sor-table tbody tr {
  transition: background 0.1s;
  border-bottom: 1px solid #f0f0f0;
}

.sor-table tbody tr:last-child { border-bottom: none; }

/* Alternating rows (overridden by medal rows below) */
.sor-table tbody tr:nth-child(even) { background: #fafafa; }
.sor-table tbody tr:nth-child(odd)  { background: #fff; }

/* Hover highlight */
.sor-table tbody tr:hover { background: #fff5f5 !important; }

/* Medal rows: top 3 */
.sor-table tbody tr.sor-rank-1 { background: linear-gradient(90deg, #fffbec, #fff8dc) !important; }
.sor-table tbody tr.sor-rank-2 { background: linear-gradient(90deg, #f8f8f5, #f0f0ea) !important; }
.sor-table tbody tr.sor-rank-3 { background: linear-gradient(90deg, #fff4ef, #fde8df) !important; }
.sor-table tbody tr.sor-rank-1:hover { background: #fef3c7 !important; }
.sor-table tbody tr.sor-rank-2:hover { background: #e8e8e0 !important; }
.sor-table tbody tr.sor-rank-3:hover { background: #fddfd4 !important; }

/* Ranks 4-5: light blue */
.sor-table tbody tr.sor-rank-4,
.sor-table tbody tr.sor-rank-5 { background: linear-gradient(90deg, #ebf8ff, #e3f2fd) !important; }
.sor-table tbody tr.sor-rank-4:hover,
.sor-table tbody tr.sor-rank-5:hover { background: #d1ecf8 !important; }

.sor-table td {
  padding: 8px 12px;
  vertical-align: middle;
}

/* Rank badge */
.sor-rank-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 700;
  background: #e2e8f0;
  color: #4a5568;
}

.sor-table tbody tr.sor-rank-1 .sor-rank-badge { background: #f6c90e; color: #5a4500; }
.sor-table tbody tr.sor-rank-2 .sor-rank-badge { background: #b0bec5; color: #263238; }
.sor-table tbody tr.sor-rank-3 .sor-rank-badge { background: #cd7f32; color: #fff; }
.sor-table tbody tr.sor-rank-4 .sor-rank-badge,
.sor-table tbody tr.sor-rank-5 .sor-rank-badge { background: #90caf9; color: #0d47a1; }

/* Kingdom name */
.sor-kingdom-name-cell {
  max-width: 200px;
  line-height: 1.35;
  font-weight: 500;
}

/* Percentage cell with in-cell bar */
.sor-pct-cell  { min-width: 90px; }

.sor-pct-value {
  font-weight: 600;
  font-size: 13px;
  margin-bottom: 4px;
  display: block;
}

.sor-pct-bar-track {
  height: 4px;
  background: #edf2f7;
  border-radius: 2px;
  overflow: hidden;
  width: 100%;
}

.sor-pct-bar-fill {
  height: 100%;
  background: #c0392b;
  border-radius: 2px;
  transition: width 0.55s ease;
}

/* Override bar color per medal tier */
.sor-table tbody tr.sor-rank-1 .sor-pct-bar-fill { background: #e6a817; }
.sor-table tbody tr.sor-rank-2 .sor-pct-bar-fill { background: #90a4ae; }
.sor-table tbody tr.sor-rank-3 .sor-pct-bar-fill { background: #cd7f32; }
.sor-table tbody tr.sor-rank-4 .sor-pct-bar-fill,
.sor-table tbody tr.sor-rank-5 .sor-pct-bar-fill { background: #42a5f5; }

/* Sign-in count */
.sor-count-cell {
  font-variant-numeric: tabular-nums;
  color: #4a5568;
  white-space: nowrap;
}

/* Chart card */
.sor-chart-card {
  background: #fff;
  border-radius: 6px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.08);
  padding: 16px 8px 8px 8px;
  box-sizing: border-box;
}

/* Kingdoms stacked layout */
.sor-kingdoms-stacked-layout { display: flex; flex-direction: column; gap: 20px; }
.sor-kingdoms-table-section  { width: 100%; overflow-x: auto; }
.sor-kingdoms-chart-section  { width: 100%; }

/* Tooltips on stat cards */
.sor-tip-card { position: relative; cursor: default; }
.sor-tip-card[data-tip]::after {
  content: attr(data-tip);
  display: none;
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  background: #1a1a2e;
  color: #e8eaf6;
  font-size: 0.7rem;
  font-weight: 400;
  line-height: 1.4;
  padding: 7px 11px;
  border-radius: 6px;
  width: 220px;
  white-space: normal;
  text-transform: none;
  letter-spacing: 0;
  z-index: 200;
  pointer-events: none;
  box-shadow: 0 4px 12px rgba(0,0,0,0.35);
}
.sor-tip-card[data-tip]:hover::after { display: block; }
</style>

<style>
/* ===== SOR Class Sign-Ins Section ===== */

.sor-section {
  margin-bottom: 40px;
}

/* ---- Skeleton shimmer ---- */
#sor-classes-skeleton {
  display: flex;
  gap: 20px;
}

.sor-skeleton-col {
  flex: 1;
}

#sor-classes-skeleton .sor-skeleton-block {
  border-radius: 4px;
  background: linear-gradient(90deg, #e0e0e0 25%, #f0f0f0 50%, #e0e0e0 75%);
  background-size: 200% 100%;
  animation: sor-shimmer 1.4s infinite;
  margin-bottom: 10px;
}

#sor-classes-skeleton .sor-skeleton-header {
  height: 28px;
  width: 55%;
  margin-bottom: 16px;
}

#sor-classes-skeleton .sor-skeleton-row {
  height: 36px;
}

#sor-classes-skeleton .sor-skeleton-chart {
  height: 320px;
  margin-top: 4px;
}

/* ---- Dual layout ---- */
.sor-dual-layout {
  display: flex;
  gap: 28px;
  align-items: flex-start;
  flex-wrap: wrap;
}

.sor-table-wrap {
  flex: 0 0 420px;
  min-width: 300px;
}

.sor-chart-wrap {
  flex: 1;
  min-width: 320px;
}

/* ---- Table ---- */
.sor-class-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
  box-shadow: 0 1px 4px rgba(0,0,0,0.09);
  background: #fff;
  border-radius: 6px;
  overflow: hidden;
}

.sor-class-table caption {
  text-align: left;
  font-size: 0.8rem;
  color: #666;
  padding: 4px 0 8px 0;
  caption-side: bottom;
}

.sor-class-table thead tr {
  background: #2c3e50;
  color: #fff;
}

.sor-class-table thead th {
  padding: 9px 12px;
  text-align: left;
  font-weight: 600;
  font-size: 0.82rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}

.sor-class-table thead th.sor-col-rank,
.sor-class-table tbody td.sor-col-rank {
  text-align: center;
  width: 44px;
}

.sor-class-table thead th.sor-col-signins,
.sor-class-table tbody td.sor-col-signins {
  text-align: right;
  width: 80px;
}

.sor-class-table thead th.sor-col-pct,
.sor-class-table tbody td.sor-col-pct {
  width: 130px;
}

.sor-class-table tbody tr {
  border-bottom: 1px solid #f0f0f0;
  transition: background 0.15s;
}

.sor-class-table tbody tr:last-child {
  border-bottom: none;
}

.sor-class-table tbody tr:hover {
  background: #f8f9fa;
}

.sor-class-table tbody td {
  padding: 8px 12px;
  color: #333;
  vertical-align: middle;
}

.sor-class-table .sor-rank-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  font-size: 0.75rem;
  font-weight: 700;
  background: #e8eaf0;
  color: #555;
}

.sor-class-table .sor-rank-badge.sor-rank-top3 {
  background: #2c3e50;
  color: #fff;
}

.sor-class-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  margin-right: 6px;
  vertical-align: middle;
  flex-shrink: 0;
}

.sor-class-name-cell {
  display: flex;
  align-items: center;
}

/* Warning tooltip */
.sor-warn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  margin-left: 5px;
  position: relative;
  color: #e67e22;
  font-size: 0.85rem;
  flex-shrink: 0;
}

.sor-warn-icon .sor-tooltip {
  visibility: hidden;
  opacity: 0;
  width: 210px;
  background: #2c3e50;
  color: #fff;
  font-size: 0.75rem;
  line-height: 1.4;
  padding: 7px 9px;
  border-radius: 5px;
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  pointer-events: none;
  transition: opacity 0.2s;
  z-index: 100;
  white-space: normal;
  box-shadow: 0 2px 8px rgba(0,0,0,0.25);
}

.sor-warn-icon .sor-tooltip::after {
  content: "";
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: #2c3e50;
}

.sor-warn-icon:hover .sor-tooltip,
.sor-warn-icon:focus .sor-tooltip {
  visibility: visible;
  opacity: 1;
}

/* Percentage bar cell */
.sor-class-table .sor-pct-cell {
  display: flex;
  align-items: center;
  gap: 7px;
}

.sor-pct-bar-bg {
  flex: 1;
  height: 10px;
  background: #eee;
  border-radius: 5px;
  overflow: hidden;
  min-width: 40px;
}

.sor-pct-bar-fill {
  height: 100%;
  border-radius: 5px;
  transition: width 0.6s ease;
}

.sor-pct-label {
  font-size: 0.78rem;
  font-weight: 600;
  color: #444;
  min-width: 38px;
  text-align: right;
  white-space: nowrap;
}

/* Sign-in count */
.sor-signins-val {
  font-variant-numeric: tabular-nums;
  font-weight: 500;
}

/* ---- Chart wrap ---- */
.sor-chart-title {
  font-size: 0.9rem;
  font-weight: 600;
  color: #555;
  margin: 0 0 8px 0;
  text-align: center;
}

#sor-classes-chart {
  border-radius: 6px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,0.09);
  background: #fff;
}

.sor-note {
  margin: 8px 0 0 0;
  font-size: 0.78rem;
  color: #888;
  line-height: 1.5;
}

.sor-table-label {
  font-size: 0.78rem;
  color: #888;
  margin: 0 0 6px 0;
}
</style>

<style>
/* sor-parks- prefixed styles */

.sor-parks-stats-bar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}

.sor-parks-stat-card {
  flex: 1 1 140px;
  background: #fff;
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 14px 16px;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0,0,0,0.07);
}

.sor-parks-stat-card .sor-parks-stat-value {
  font-size: 2rem;
  font-weight: 700;
  line-height: 1.1;
  color: #2c5f6e;
}

.sor-parks-stat-card.sor-parks-stat-new .sor-parks-stat-value {
  color: #2e7d32;
}

.sor-parks-stat-card.sor-parks-stat-lost .sor-parks-stat-value {
  color: #c62828;
}

.sor-parks-stat-card.sor-parks-stat-risk .sor-parks-stat-value {
  color: #e65100;
}

.sor-parks-stat-card .sor-parks-stat-label {
  font-size: 0.78rem;
  color: #666;
  margin-top: 4px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Narrative block */
.sor-parks-narrative {
  margin-bottom: 20px;
}

.sor-parks-narrative p {
  margin: 0 0 10px 0;
  font-size: 0.95rem;
  line-height: 1.6;
  color: #333;
}

.sor-parks-narrative strong {
  color: #2c5f6e;
}

/* Expandable park lists */
.sor-parks-expandable {
  margin-bottom: 20px;
  border: 1px solid #ddd;
  border-radius: 6px;
  overflow: hidden;
}

.sor-parks-expandable-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: #f5f5f5;
  cursor: pointer;
  user-select: none;
  font-size: 0.9rem;
  font-weight: 600;
  color: #333;
  border: none;
  width: 100%;
  text-align: left;
}

.sor-parks-expandable-header:hover {
  background: #ececec;
}

.sor-parks-expandable-header .sor-parks-expand-icon {
  font-size: 0.75rem;
  color: #888;
  transition: transform 0.2s;
}

.sor-parks-expandable-header.sor-parks-open .sor-parks-expand-icon {
  transform: rotate(180deg);
}

.sor-parks-expandable-body {
  display: none;
  padding: 12px 14px;
  background: #fff;
}

.sor-parks-expandable-body.sor-parks-visible {
  display: block;
}

.sor-parks-kingdom-group {
  margin-bottom: 14px;
}

.sor-parks-kingdom-group:last-child {
  margin-bottom: 0;
}

.sor-parks-kingdom-label {
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #555;
  margin-bottom: 6px;
  padding-bottom: 4px;
  border-bottom: 1px solid #eee;
}

.sor-parks-park-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.sor-parks-park-pill {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 0.82rem;
  line-height: 1.5;
}

.sor-parks-pill-new {
  background: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #a5d6a7;
}

.sor-parks-pill-lost {
  background: #ffebee;
  color: #c62828;
  border: 1px solid #ef9a9a;
}

/* At-risk alert */
.sor-parks-alert {
  background: #fff8e1;
  border: 1px solid #ffcc02;
  border-left: 4px solid #f9a825;
  border-radius: 6px;
  padding: 14px 16px;
  margin-bottom: 24px;
}

.sor-parks-alert-title {
  font-weight: 700;
  font-size: 0.92rem;
  color: #6d4c00;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.sor-parks-alert-subtitle {
  font-size: 0.82rem;
  color: #7a5800;
  margin-bottom: 10px;
  font-style: italic;
}

.sor-parks-risk-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.83rem;
}

.sor-parks-risk-table th {
  background: transparent !important;
  border: none !important;
  padding: 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  text-align: left;
  padding: 4px 8px !important;
  color: #6d4c00;
  border-bottom: 1px solid #ffe082 !important;
  font-weight: 700;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.sor-parks-risk-table td {
  padding: 5px 8px;
  color: #5a3c00;
  border-bottom: 1px solid #fff3cd;
}

.sor-parks-risk-table tr:last-child td {
  border-bottom: none;
}

.sor-parks-risk-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 0.78rem;
  font-weight: 700;
}

.sor-parks-risk-high {
  background: #ffcdd2;
  color: #b71c1c;
}

.sor-parks-risk-med {
  background: #ffe0b2;
  color: #bf360c;
}

/* Kingdom table */
.sor-parks-table-wrap {
  overflow-x: auto;
  margin-bottom: 24px;
  border: 1px solid #ddd;
  border-radius: 6px;
}

.sor-parks-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.sor-parks-table thead th {
  background: #2c5f6e !important;
  border: none !important;
  padding: 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  color: #fff !important;
  padding: 10px 12px !important;
  text-align: left;
  font-weight: 600;
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  cursor: pointer;
  white-space: nowrap;
  user-select: none;
}

.sor-parks-table thead th:hover {
  background: #245060 !important;
}

.sor-parks-table thead th .sor-parks-sort-icon {
  margin-left: 4px;
  opacity: 0.6;
  font-size: 0.7rem;
}

.sor-parks-table thead th.sor-parks-sort-asc .sor-parks-sort-icon::after { content: ' \25b2'; }
.sor-parks-table thead th.sor-parks-sort-desc .sor-parks-sort-icon::after { content: ' \25bc'; }
.sor-parks-table thead th:not(.sor-parks-sort-asc):not(.sor-parks-sort-desc) .sor-parks-sort-icon::after { content: ' \21c5'; }

.sor-parks-table tbody tr:nth-child(even) {
  background: #f9f9f9;
}

.sor-parks-table tbody tr:hover {
  background: #f0f7fa;
}

.sor-parks-table td {
  padding: 8px 12px;
  border-bottom: 1px solid #eee;
  color: #333;
}

.sor-parks-table tbody tr:last-child td {
  border-bottom: none;
}

.sor-parks-ratio-good {
  color: #2e7d32;
  font-weight: 600;
}

.sor-parks-ratio-bad {
  color: #c62828;
  font-weight: 600;
}

.sor-parks-ratio-neutral {
  color: #888;
}

/* Chart */
.sor-parks-chart-section {
  margin-bottom: 8px;
}

.sor-parks-chart-title {
  font-size: 0.88rem;
  font-weight: 700;
  color: #333;
  margin-bottom: 8px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.sor-parks-chart-outer {
  overflow-x: auto;
  border: 1px solid #ddd;
  border-radius: 6px;
  background: #fff;
}

#sor-parks-chart-container {
  min-width: 600px;
  height: 350px;
}

/* Skeleton */
.sor-skeleton-bar {
  background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
  background-size: 200% 100%;
  animation: sor-shimmer 1.4s infinite;
  border-radius: 4px;
}

.sor-parks-skeleton-cards {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 24px;
}

.sor-parks-skeleton-card {
  flex: 1 1 140px;
  height: 72px;
  border-radius: 6px;
}

.sor-parks-skeleton-text {
  height: 14px;
  margin-bottom: 8px;
  border-radius: 3px;
}

.sor-parks-skeleton-text-short {
  width: 60%;
}

.sor-parks-skeleton-table {
  height: 200px;
  border-radius: 6px;
  margin-bottom: 24px;
}

.sor-parks-skeleton-chart {
  height: 350px;
  border-radius: 6px;
}

/* Section heading reset (global h-tags get gray box in orkui.css) */
#sor-section-parks h2,
#sor-section-parks h3,
#sor-section-parks h4 {
  background: transparent !important;
  border: none !important;
  padding: 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
}
</style>

<style>
/* ================================================
   sor-players- section styles
   ================================================ */

/* Skeleton loader */
#sor-players-skeleton {
  padding: 16px 0;
}
#sor-players-skeleton .sor-skeleton-row {
  display: flex;
  gap: 12px;
  margin-bottom: 14px;
}
.sor-skeleton-card {
  flex: 1;
  height: 96px;
  border-radius: 8px;
  background: linear-gradient(90deg, #e0e0e0 25%, #ebebeb 50%, #e0e0e0 75%);
  background-size: 400% 100%;
  animation: sor-shimmer 1.4s infinite;
}
#sor-players-skeleton .sor-skeleton-bar {
  height: 20px;
  border-radius: 4px;
  background: linear-gradient(90deg, #e0e0e0 25%, #ebebeb 50%, #e0e0e0 75%);
  background-size: 400% 100%;
  animation: sor-shimmer 1.4s infinite;
  margin-bottom: 10px;
}
#sor-players-skeleton .sor-skeleton-bar.short { width: 60%; }
#sor-players-skeleton .sor-skeleton-bar.medium { width: 80%; }
#sor-players-skeleton .sor-skeleton-chart {
  height: 300px;
  border-radius: 8px;
  background: linear-gradient(90deg, #e0e0e0 25%, #ebebeb 50%, #e0e0e0 75%);
  background-size: 400% 100%;
  animation: sor-shimmer 1.4s infinite;
  margin-top: 16px;
}

/* ---- Stat cards ---- */
.sor-players-cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 24px;
}
@media (max-width: 900px) {
  .sor-players-cards { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
  .sor-players-cards { grid-template-columns: 1fr; }
}

.sor-players-card {
  background: #fff;
  border-radius: 10px;
  padding: 18px 16px 14px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  position: relative;
  overflow: hidden;
  border-top: 4px solid #c0392b;
  transition: transform 0.15s, box-shadow 0.15s;
}
.sor-players-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(0,0,0,0.12);
}
.sor-players-card-icon {
  position: absolute;
  top: 12px;
  right: 14px;
  font-size: 28px;
  opacity: 0.13;
  line-height: 1;
  user-select: none;
}
.sor-players-card-value {
  font-size: 2.1rem;
  font-weight: 700;
  color: #1a1a2e;
  line-height: 1.15;
  margin-bottom: 4px;
  letter-spacing: -0.5px;
}
.sor-players-card-label {
  font-size: 0.78rem;
  font-weight: 600;
  color: #888;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.sor-players-card.accent-blue { border-top-color: #2980b9; }
.sor-players-card.accent-green { border-top-color: #27ae60; }
.sor-players-card.accent-amber { border-top-color: #e67e22; }

/* ---- Prose stats ---- */
.sor-players-prose {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 24px;
}
@media (max-width: 700px) {
  .sor-players-prose { grid-template-columns: 1fr; }
}
.sor-players-prose-col {
  background: #fff;
  border-radius: 10px;
  padding: 18px 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.07);
  font-size: 0.93rem;
  color: #333;
  line-height: 1.7;
}
.sor-players-prose-col h4 {
  /* reset global h4 styles */
  background: transparent !important;
  border: none !important;
  padding: 0 0 6px 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  margin: 0 0 10px 0;
  font-size: 1rem;
  font-weight: 700;
  color: #1a1a2e;
  border-bottom: 2px solid #eee !important;
}
.sor-players-prose-col strong {
  color: #1a1a2e;
}

/* ---- Normal player callout ---- */
.sor-players-normal-callout {
  background: #fffbf0;
  border-left: 4px solid #e67e22;
  border-radius: 0 8px 8px 0;
  padding: 10px 14px;
  font-size: 0.83rem;
  color: #7a5c1e;
  margin-bottom: 20px;
  line-height: 1.6;
}
.sor-players-normal-callout strong {
  color: #7a5c1e;
}

/* ---- Trend chart ---- */
.sor-players-chart-wrap {
  background: #fff;
  border-radius: 10px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.07);
  margin-bottom: 24px;
}
.sor-players-chart-title {
  /* reset global heading styles */
  background: transparent !important;
  border: none !important;
  padding: 0 0 12px 0 !important;
  border-radius: 0 !important;
  text-shadow: none !important;
  margin: 0 0 4px 0;
  font-size: 1rem;
  font-weight: 700;
  color: #1a1a2e;
  border-bottom: 2px solid #eee !important;
  display: block;
}
#sor-players-chart {
  width: 100%;
  height: 300px;
}

/* ---- 10-year section ---- */
.sor-players-tenyear {
  margin-bottom: 8px;
}
.sor-players-tenyear-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #1a1a2e;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 11px 18px;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  width: 100%;
  text-align: left;
  transition: background 0.15s;
  letter-spacing: 0.2px;
}
.sor-players-tenyear-toggle:hover {
  background: #2c2c4e;
}
.sor-players-tenyear-arrow {
  margin-left: auto;
  transition: transform 0.25s;
  font-style: normal;
}
.sor-players-tenyear-toggle.open .sor-players-tenyear-arrow {
  transform: rotate(180deg);
}
.sor-players-tenyear-body {
  display: none;
  padding: 20px 0 4px 0;
}
.sor-players-tenyear-body.open {
  display: block;
}

/* smaller cards for 10yr */
.sor-players-cards.compact .sor-players-card {
  padding: 14px 12px 12px;
}
.sor-players-cards.compact .sor-players-card-value {
  font-size: 1.65rem;
}
</style>

<style>
/* ===== New Player Retention ===== */
.sor-ret-grid { display:flex; flex-wrap:wrap; gap:10px; margin:8px 0; }
.sor-ret-card { flex:1 1 130px; min-width:120px; background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 10px; text-align:center; }
.sor-ret-val { font-size:22px; font-weight:700; color:#2b6cb0; }
.sor-ret-lbl { font-size:11px; color:#718096; margin-top:4px; line-height:1.3; }
.sor-ret-sub { font-size:13px; font-weight:600; color:#4a5568; margin-top:6px; }
html[data-theme="dark"] .sor-ret-card { background:rgba(255,255,255,0.04); border-color:var(--ork-input-border,#2d3748); }
html[data-theme="dark"] .sor-ret-val { color:#63b3ed; }
html[data-theme="dark"] .sor-ret-lbl { color:var(--ork-text-muted,#a0aec0); }
html[data-theme="dark"] .sor-ret-sub { color:var(--ork-text,#e2e8f0); }

/* ===== Longevity Section ===== */
.sor-longevity-layout {
  display: flex;
  gap: 24px;
  align-items: flex-start;
  flex-wrap: wrap;
}
#sor-longevity-chart {
  flex: 1 1 400px;
  min-width: 300px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.08);
  padding: 12px;
}
.sor-longevity-legend {
  flex: 0 0 210px;
  min-width: 160px;
  align-self: center;
}
.sor-lon-legend-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
  font-size: 0.82rem;
  border-bottom: 1px solid #f0f0f0;
}
.sor-lon-legend-item:last-child { border-bottom: none; }
.sor-lon-swatch { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }
.sor-lon-label  { flex: 1; color: #444; font-weight: 600; }
.sor-lon-count  { color: #888; font-size: 0.78rem; white-space: nowrap; }
.sor-lon-pct    { color: #2980b9; font-size: 0.78rem; font-weight: 700; min-width: 38px; text-align: right; }

/* ====================================================
   PRINT / PDF STYLES
   ==================================================== */
@media print {
  /* THE fix for the blank/clipped PDF: the global theme constrains <body> to the
     viewport height with overflow:hidden (a scroll pane). In print that clips
     everything past the first page. Force the document to grow naturally so all
     content flows across pages. */
  html, body {
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow: visible !important;
  }

  /* Print-only header */
  #sor-print-header { display: block !important; }

  /* Hide navigation chrome (TOC + back-to-top links) */
  .sor-toc,
  .sor-back-to-top { display: none !important; }

  /* Hide screen-only controls */
  .sor-filter-card { display: none !important; }

  /* Hide the global site nav bar (home/search/avatar) so it doesn't appear
     atop the PDF — the report's own print header carries the title/params. */
  #newmenu { display: none !important; }

  /* Force report visible regardless of generation state */
  #sor-report { display: block !important; }

  /* Force all section content panels visible */
  #sor-players-content,
  #sor-kingdoms-content,
  #sor-classes-content,
  #sor-parks-content { display: block !important; }

  #sor-players-longevity,
  #sor-players-funnel { display: block !important; }

  /* Hide skeleton loaders */
  #sor-players-skeleton,
  #sor-kingdoms-skeleton,
  #sor-classes-skeleton,
  #sor-parks-skeleton { display: none !important; }

  /* Scorecard */
  #sor-scorecard { display: flex !important; }

  /* Force expandable bodies open */
  .sor-parks-expandable-body { display: block !important; }
  .sor-players-tenyear-body  { display: block !important; }

  /* Hero: force gradient to print */
  .cp-hero {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    border-radius: 0 !important;
    margin-bottom: 16px !important;
    page-break-after: avoid;
    break-after: avoid;
  }

  /* Remove box shadows */
  * { box-shadow: none !important; }

  /* Force background colors to print for tables, badges, cards */
  .sor-table thead th,
  .sor-class-table thead th,
  .sor-parks-table thead th,
  .sor-rank-badge,
  .sor-kpi-card,
  .sor-players-card,
  .sor-parks-stat-card,
  .sor-parks-alert,
  .sor-players-normal-callout,
  .sor-diversity-card {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  /* Charts: force SVG colors */
  #sor-kingdoms-chart,
  #sor-classes-chart,
  #sor-players-chart,
  #sor-parks-health-chart,
  #sor-parks-net-chart,
  #sor-parks-chart-container,
  #sor-longevity-chart,
  #sor-players-funnel-chart {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  /* Page breaks.
     IMPORTANT: do NOT set break-inside:avoid on the section wrapper — each section
     is taller than a printed page, and Chrome clips oversized "avoid" blocks
     (dropping everything past the first page = the blank/2-page PDF bug). Let
     sections flow across pages; keep only small cards/KPIs intact (below). */
  .sor-report-section {
    page-break-before: auto;
    margin-bottom: 20px !important;
  }
  .sor-section-divider {
    page-break-after: avoid;
    break-after: avoid;
  }

  /* Avoid splitting cards and KPIs across pages */
  .sor-kpi-card,
  .sor-players-card { page-break-inside: avoid; break-inside: avoid; }

  /* Repeat table headers on each printed page */
  thead { display: table-header-group; }
  tfoot { display: table-footer-group; }

  /* Section heading reset (safety — no grey box in print) */
  .sor-section-heading,
  .sor-section-title {
    background: transparent !important;
    border: none !important;
    padding: 0 !important;
    border-radius: 0 !important;
    text-shadow: none !important;
  }
}
</style>

<style>
/* ================================================================
   DARK MODE OVERRIDES  — keyed off html[data-theme="dark"]
   These rules apply ONLY in dark mode; light mode is untouched.
   Inherits ORK3 vars from revised.css (--ork-card-bg, --ork-text, etc).
   ================================================================ */

/* ---- Filter Card ---- */
html[data-theme="dark"] .sor-filter-card {
  background: var(--ork-card-bg);
  box-shadow: 0 2px 12px rgba(0,0,0,0.4);
}
html[data-theme="dark"] .sor-filter-heading {
  color: var(--ork-text);
  border-bottom-color: var(--ork-border) !important;
}
html[data-theme="dark"] .sor-filter-group label {
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-filter-group input[type="date"],
html[data-theme="dark"] .sor-filter-kingdoms select {
  background: var(--ork-input-bg);
  border-color: var(--ork-input-border);
  color: var(--ork-text);
  color-scheme: dark;
}
html[data-theme="dark"] .sor-filter-group input[type="date"]:focus,
html[data-theme="dark"] .sor-filter-kingdoms select:focus {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-filter-kingdoms select option {
  background: var(--ork-input-bg);
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-filter-kingdoms select option:checked,
html[data-theme="dark"] .sor-filter-kingdoms select option:checked:hover {
  background: linear-gradient(0deg, #e74c3c 0%, #e74c3c 100%) !important;
  color: #fff !important;
}
html[data-theme="dark"] .sor-btn-sm {
  background: var(--ork-bg-tertiary);
  border-color: var(--ork-border);
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-btn-sm:hover {
  background: var(--ork-bg-secondary);
}
html[data-theme="dark"] .sor-btn-generate:disabled {
  background: var(--ork-bg-tertiary);
  color: var(--ork-text-muted);
}
html[data-theme="dark"] .sor-help-text {
  color: var(--ork-text-muted);
}

/* ---- Section heading colors ---- */
html[data-theme="dark"] .sor-section-heading {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-toc {
  background: var(--ork-card-bg);
  border-color: var(--ork-border);
}
html[data-theme="dark"] .sor-toc-title {
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-toc-list a {
  background: var(--ork-bg, #1e2329);
  border-color: var(--ork-border);
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-toc-list a:hover {
  background: var(--ork-card-bg);
  border-color: #e74c3c;
  color: #e74c3c;
}
html[data-theme="dark"] .sor-back-to-top {
  border-top-color: var(--ork-border);
}
html[data-theme="dark"] .sor-back-to-top a {
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-back-to-top a:hover {
  color: #e74c3c;
  background: var(--ork-card-bg);
}
html[data-theme="dark"] .sor-section {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-section-subtitle {
  color: var(--ork-text-muted);
}

/* ---- Executive Scorecard / KPI cards ---- */
html[data-theme="dark"] .sor-kpi-card {
  background: var(--ork-card-bg);
  box-shadow: 0 2px 8px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-kpi-label {
  color: var(--ork-text-muted);
}
html[data-theme="dark"] .sor-kpi-sub {
  color: var(--ork-text-lighter);
}

/* ---- Cohort funnel wrap ---- */
html[data-theme="dark"] .sor-players-funnel-wrap {
  background: var(--ork-card-bg);
  border-color: var(--ork-border);
}

/* ---- Parks net/health chart cards ---- */
html[data-theme="dark"] #sor-parks-net-chart,
html[data-theme="dark"] #sor-parks-health-chart {
  background: var(--ork-card-bg);
}

/* ---- Diversity Index Card ---- */
html[data-theme="dark"] .sor-diversity-card {
  background: var(--ork-bg-secondary);
  border-color: var(--ork-border);
  color: var(--ork-text);
}

/* ---- Kingdoms table (.sor-table) ---- */
html[data-theme="dark"] .sor-table {
  background: var(--ork-card-bg);
  box-shadow: 0 1px 6px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-table tbody tr {
  border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .sor-table tbody tr:nth-child(odd) {
  background: var(--ork-card-bg);
}
html[data-theme="dark"] .sor-table tbody tr:nth-child(even) {
  background: var(--ork-bg-secondary);
}
html[data-theme="dark"] .sor-table tbody tr:hover {
  background: var(--ork-bg-tertiary) !important;
}
/* Medal rows: darken the soft pastel gradients for readability */
html[data-theme="dark"] .sor-table tbody tr.sor-rank-1 {
  background: linear-gradient(90deg, #5a4500, #6b5200) !important;
  color: #fff8dc;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-2 {
  background: linear-gradient(90deg, #2d3748, #374151) !important;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-3 {
  background: linear-gradient(90deg, #6b3a1e, #7b4520) !important;
  color: #fde8df;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-1:hover {
  background: #7a5e00 !important;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-2:hover {
  background: #455067 !important;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-3:hover {
  background: #8a4a22 !important;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-4,
html[data-theme="dark"] .sor-table tbody tr.sor-rank-5 {
  background: linear-gradient(90deg, #1e3a52, #234a68) !important;
  color: #d1ecf8;
}
html[data-theme="dark"] .sor-table tbody tr.sor-rank-4:hover,
html[data-theme="dark"] .sor-table tbody tr.sor-rank-5:hover {
  background: #2a557a !important;
}
html[data-theme="dark"] .sor-rank-badge {
  background: var(--ork-bg-tertiary);
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-pct-bar-track {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-count-cell {
  color: var(--ork-text-secondary);
}

/* ---- Chart card (.sor-chart-card) ---- */
html[data-theme="dark"] .sor-chart-card {
  background: var(--ork-card-bg);
  box-shadow: 0 1px 6px rgba(0,0,0,0.35);
}

/* ---- Class table (.sor-class-table) ---- */
html[data-theme="dark"] .sor-class-table {
  background: var(--ork-card-bg);
  box-shadow: 0 1px 4px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-class-table caption {
  color: var(--ork-text-muted);
}
html[data-theme="dark"] .sor-class-table tbody tr {
  border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .sor-class-table tbody tr:hover {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-class-table tbody td {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-class-table .sor-rank-badge {
  background: var(--ork-bg-tertiary);
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-pct-bar-bg {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-pct-label {
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-chart-title {
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] #sor-classes-chart {
  background: var(--ork-card-bg);
  box-shadow: 0 1px 4px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-note,
html[data-theme="dark"] .sor-table-label {
  color: var(--ork-text-muted);
}

/* ---- Parks stat cards ---- */
html[data-theme="dark"] .sor-parks-stat-card {
  background: var(--ork-card-bg);
  border-color: var(--ork-border);
  box-shadow: 0 1px 3px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-parks-stat-card .sor-parks-stat-value {
  /* Default cyan stays visible; brighten for dark bg */
  color: #4fb3c6;
}
html[data-theme="dark"] .sor-parks-stat-card.sor-parks-stat-new .sor-parks-stat-value {
  color: #68d391;
}
html[data-theme="dark"] .sor-parks-stat-card.sor-parks-stat-lost .sor-parks-stat-value {
  color: #fc8181;
}
html[data-theme="dark"] .sor-parks-stat-card.sor-parks-stat-risk .sor-parks-stat-value {
  color: #f6ad55;
}
html[data-theme="dark"] .sor-parks-stat-card .sor-parks-stat-label {
  color: var(--ork-text-muted);
}

/* ---- Parks narrative ---- */
html[data-theme="dark"] .sor-parks-narrative p {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-parks-narrative strong {
  color: #4fb3c6;
}

/* ---- Parks expandable lists ---- */
html[data-theme="dark"] .sor-parks-expandable {
  border-color: var(--ork-border);
}
html[data-theme="dark"] .sor-parks-expandable-header {
  background: var(--ork-bg-secondary);
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-parks-expandable-header:hover {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-parks-expandable-header .sor-parks-expand-icon {
  color: var(--ork-text-muted);
}
html[data-theme="dark"] .sor-parks-expandable-body {
  background: var(--ork-card-bg);
}
html[data-theme="dark"] .sor-parks-kingdom-label {
  color: var(--ork-text-secondary);
  border-bottom-color: var(--ork-border);
}
/* Pills: darken pastel backgrounds */
html[data-theme="dark"] .sor-parks-pill-new {
  background: #22543d;
  color: #9ae6b4;
  border-color: #2f855a;
}
html[data-theme="dark"] .sor-parks-pill-lost {
  background: #742a2a;
  color: #feb2b2;
  border-color: #c53030;
}

/* ---- At-risk alert ---- */
html[data-theme="dark"] .sor-parks-alert {
  background: #4a3a10;
  border-color: #b7791f;
  border-left-color: #f6ad55;
}
html[data-theme="dark"] .sor-parks-alert-title,
html[data-theme="dark"] .sor-parks-alert-subtitle {
  color: #fbd38d;
}
html[data-theme="dark"] .sor-parks-risk-table th {
  color: #fbd38d;
  border-bottom-color: #b7791f !important;
}
html[data-theme="dark"] .sor-parks-risk-table td {
  color: #fef3c7;
  border-bottom-color: #6b5523;
}
html[data-theme="dark"] .sor-parks-risk-high {
  background: #742a2a;
  color: #fed7d7;
}
html[data-theme="dark"] .sor-parks-risk-med {
  background: #7b341e;
  color: #fbd38d;
}

/* ---- Parks kingdom table ---- */
html[data-theme="dark"] .sor-parks-table-wrap {
  border-color: var(--ork-border);
}
html[data-theme="dark"] .sor-parks-table tbody tr:nth-child(even) {
  background: var(--ork-bg-secondary);
}
html[data-theme="dark"] .sor-parks-table tbody tr:hover {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-parks-table td {
  color: var(--ork-text);
  border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .sor-parks-ratio-good {
  color: #68d391;
}
html[data-theme="dark"] .sor-parks-ratio-bad {
  color: #fc8181;
}
html[data-theme="dark"] .sor-parks-ratio-neutral {
  color: var(--ork-text-muted);
}

/* ---- Parks chart ---- */
html[data-theme="dark"] .sor-parks-chart-title {
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-parks-chart-outer {
  background: var(--ork-card-bg);
  border-color: var(--ork-border);
}

/* ---- Skeleton loaders (all variants) ---- */
html[data-theme="dark"] .sor-skeleton-header,
html[data-theme="dark"] .sor-skeleton-row,
html[data-theme="dark"] .sor-skeleton-chart {
  background: linear-gradient(90deg, #2d3748 25%, #374151 50%, #2d3748 75%);
  background-size: 400% 100%;
}
html[data-theme="dark"] .sor-skeleton-bar,
html[data-theme="dark"] .sor-parks-skeleton-card,
html[data-theme="dark"] .sor-parks-skeleton-text,
html[data-theme="dark"] .sor-parks-skeleton-table,
html[data-theme="dark"] .sor-parks-skeleton-chart {
  background: linear-gradient(90deg, #2d3748 25%, #374151 50%, #2d3748 75%);
  background-size: 200% 100%;
}

/* ---- Players stat cards ---- */
html[data-theme="dark"] .sor-players-card {
  background: var(--ork-card-bg);
  box-shadow: 0 2px 8px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-players-card:hover {
  box-shadow: 0 6px 18px rgba(0,0,0,0.45);
}
html[data-theme="dark"] .sor-players-card-value {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-players-card-label {
  color: var(--ork-text-muted);
}

/* ---- Players prose cols ---- */
html[data-theme="dark"] .sor-players-prose-col {
  background: var(--ork-card-bg);
  box-shadow: 0 2px 8px rgba(0,0,0,0.35);
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-players-prose-col h4 {
  color: var(--ork-text);
  border-bottom-color: var(--ork-border) !important;
}
html[data-theme="dark"] .sor-players-prose-col strong {
  color: var(--ork-text);
}

/* ---- Normal-player callout ---- */
html[data-theme="dark"] .sor-players-normal-callout {
  background: #4a3a10;
  border-left-color: #f6ad55;
  color: #fbd38d;
}
html[data-theme="dark"] .sor-players-normal-callout strong {
  color: #fbd38d;
}

/* ---- Players chart wrap & title ---- */
html[data-theme="dark"] .sor-players-chart-wrap {
  background: var(--ork-card-bg);
  box-shadow: 0 2px 8px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-players-chart-title {
  color: var(--ork-text);
  border-bottom-color: var(--ork-border) !important;
}

/* ---- 10-year toggle button (already dark navy in light mode; keep but tune hover) ---- */
html[data-theme="dark"] .sor-players-tenyear-toggle {
  background: var(--ork-bg-tertiary);
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-players-tenyear-toggle:hover {
  background: var(--ork-bg-secondary);
}

/* ---- Longevity chart wrap & legend ---- */
html[data-theme="dark"] #sor-longevity-chart {
  background: var(--ork-card-bg);
  box-shadow: 0 1px 6px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-lon-legend-item {
  border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .sor-lon-label {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-lon-count {
  color: var(--ork-text-muted);
}
html[data-theme="dark"] .sor-lon-pct {
  color: var(--ork-link-bright);
}

/* ============================================================
   Award Grants Section
   ============================================================ */
.sor-awards-kpi-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.sor-awards-charts-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
  margin-bottom: 28px;
}
.sor-awards-chart-card {
  padding: 16px 14px 12px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.sor-awards-chart-title {
  font-size: 1rem;
  font-weight: 700;
  color: #2d3748;
  text-align: center;
  margin: 0 0 2px;
}
.sor-awards-chart-sub {
  text-align: center;
  font-size: 0.78rem;
  color: #718096;
  margin-bottom: 6px;
  letter-spacing: 0.02em;
}
.sor-awards-pie {
  height: 440px;
  min-height: 380px;
  width: 100%;
}
.sor-awards-table-wrap {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 6px rgba(0,0,0,0.08);
  padding: 14px 16px 16px;
  overflow-x: auto;
}
.sor-awards-table-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #2d3748;
  margin-bottom: 10px;
  letter-spacing: 0.02em;
}
.sor-awards-table {
  width: 100%;
  border-collapse: collapse;
  margin: 0;
  box-shadow: none;
}
.sor-awards-table thead th {
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #4a5568;
  padding: 8px 10px;
  border-bottom: 2px solid #e2e8f0;
  background: transparent;
}
.sor-awards-table tbody td {
  padding: 8px 10px;
  border-bottom: 1px solid #edf2f7;
  font-size: 0.88rem;
  color: #2d3748;
  vertical-align: middle;
}
.sor-awards-table tbody tr:nth-child(even) {
  background: #f8fafc;
}
.sor-awards-table tbody tr:hover {
  background: #edf2f7;
}
.sor-awards-recipient-link {
  color: #2980b9;
  text-decoration: none;
  font-weight: 600;
}
.sor-awards-recipient-link:hover {
  text-decoration: underline;
}
.sor-awards-peerage-pill {
  display: inline-block;
  padding: 2px 9px;
  border-radius: 10px;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.sor-awards-peerage-knight  { background: #fde2dc; color: #9c2a1a; }
.sor-awards-peerage-paragon { background: #e7e0f5; color: #553c9a; }
.sor-awards-peerage-master  { background: #d6ecf3; color: #1b5a73; }

.sor-awards-skeleton-charts {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin: 14px 0;
}
.sor-awards-skeleton-pie {
  height: 260px;
}
.sor-awards-skeleton-chart {
  height: 280px;
  margin: 12px 0 14px;
  border-radius: 8px;
}

/* ---- Data accuracy note ---- */
.sor-awards-data-note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 14px;
  margin: 0 0 16px;
  background: var(--ork-info-bg, #eef5fb);
  border: 1px solid var(--ork-border, #cfe2f3);
  border-left: 3px solid #2980b9;
  border-radius: 6px;
  font-size: 0.82rem;
  line-height: 1.45;
  color: var(--ork-text-secondary, #4a5568);
}
.sor-awards-data-note-icon {
  color: #2980b9;
  font-size: 0.95rem;
  margin-top: 2px;
  flex-shrink: 0;
}
.sor-awards-data-note strong {
  color: var(--ork-text, #2d3748);
  font-weight: 700;
}

/* ---- 10-year trend chart ---- */
.sor-awards-trend-card {
  margin-bottom: 24px;
  padding: 14px 14px 10px;
}
.sor-awards-trend-chart {
  width: 100%;
  height: 320px;
  min-height: 280px;
}

@media (max-width: 900px) {
  .sor-awards-kpi-row,
  .sor-awards-charts-row,
  .sor-awards-skeleton-charts {
    grid-template-columns: 1fr;
  }
  .sor-awards-pie {
    height: 280px;
  }
}

/* ---- Dark mode overrides ---- */
html[data-theme="dark"] #sor-section-awards .sor-section-subtitle,
html[data-theme="dark"] .sor-awards-chart-sub {
  color: var(--ork-text-muted);
}
html[data-theme="dark"] .sor-awards-data-note {
  background: rgba(41, 128, 185, 0.10);
  border-color: var(--ork-border);
  border-left-color: #4aa3d9;
  color: var(--ork-text-secondary);
}
html[data-theme="dark"] .sor-awards-data-note-icon {
  color: #4aa3d9;
}
html[data-theme="dark"] .sor-awards-data-note strong {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-awards-chart-card,
html[data-theme="dark"] .sor-awards-table-wrap {
  background: var(--ork-card-bg);
  box-shadow: 0 1px 6px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .sor-awards-chart-title,
html[data-theme="dark"] .sor-awards-table-title {
  color: var(--ork-text);
}
html[data-theme="dark"] .sor-awards-table thead th {
  color: var(--ork-text-secondary);
  border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .sor-awards-table tbody td {
  color: var(--ork-text);
  border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .sor-awards-table tbody tr:nth-child(even) {
  background: var(--ork-bg-secondary);
}
html[data-theme="dark"] .sor-awards-table tbody tr:hover {
  background: var(--ork-bg-tertiary);
}
html[data-theme="dark"] .sor-awards-recipient-link {
  color: var(--ork-link, #4fb3c6);
}
html[data-theme="dark"] .sor-awards-recipient-link:hover {
  color: var(--ork-link-bright, #7fd4e6);
}
html[data-theme="dark"] .sor-awards-peerage-knight {
  background: #5a2a20; color: #fed7d7;
}
html[data-theme="dark"] .sor-awards-peerage-paragon {
  background: #3c2f5e; color: #d6bcfa;
}
html[data-theme="dark"] .sor-awards-peerage-master {
  background: #1e3a4d; color: #bee3f0;
}
html[data-theme="dark"] .sor-awards-skeleton-pie {
  background: linear-gradient(90deg, #2d3748 25%, #374151 50%, #2d3748 75%);
  background-size: 400% 100%;
}

</style>

<!-- ============================================================
     SECTION JS
     ============================================================ -->
<script>
(function () {
  'use strict';

  /* ----------------------------------------------------------------
     Internal state
  ---------------------------------------------------------------- */
  var _sorKingdomsData = [];
  var _sorSortCol      = 'rank';
  var _sorSortDir      = 'asc';
  var _sorHandlersSet  = false;

  /* ----------------------------------------------------------------
     Minimal HTML-escape helper
  ---------------------------------------------------------------- */
  function sorEsc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ----------------------------------------------------------------
     Format integer with locale-aware thousands separators
  ---------------------------------------------------------------- */
  function sorFmt(n) {
    return parseInt(n, 10).toLocaleString();
  }

  /* ----------------------------------------------------------------
     Return CSS row class for top-5 medal styling
  ---------------------------------------------------------------- */
  function sorRankCls(rank) {
    var r = parseInt(rank, 10);
    return r >= 1 && r <= 5 ? 'sor-rank-' + r : '';
  }

  /* ----------------------------------------------------------------
     Render table body from a (pre-sorted) array of kingdom objects
  ---------------------------------------------------------------- */
  function sorKingdomYoyDelta(row) {
    if (row.yoy_change == null) return '<span style="color:#aaa">&mdash;</span>';
    var v = parseInt(row.yoy_change, 10);
    var clr = v > 0 ? '#27ae60' : (v < 0 ? '#c0392b' : '#888');
    var arrow = v > 0 ? '&#9650;' : (v < 0 ? '&#9660;' : '');
    return '<span style="color:' + clr + '">' + arrow + ' ' + Math.abs(v).toLocaleString('en-US') + '</span>';
  }

  function sorKingdomYoyPct(row) {
    if (row.yoy_pct_change == null) return '<span style="color:#aaa">&mdash;</span>';
    var v = parseFloat(row.yoy_pct_change);
    var clr = v > 0 ? '#27ae60' : (v < 0 ? '#c0392b' : '#888');
    var sign = v > 0 ? '+' : '';
    return '<span style="color:' + clr + '">' + sign + v.toFixed(1) + '%</span>';
  }

  function sorRenderTable(rows) {
    var tbody = document.getElementById('sor-kingdoms-tbody');
    if (!tbody) return;

    // Max pct for bar scaling
    var maxPct = 0.001;
    for (var i = 0; i < rows.length; i++) {
      var p = parseFloat(rows[i].percentage) || 0;
      if (p > maxPct) maxPct = p;
    }

    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var r      = rows[i];
      var rank   = parseInt(r.rank, 10);
      var pct    = parseFloat(r.percentage) || 0;
      var barW   = Math.round((pct / maxPct) * 100);
      var cls    = sorRankCls(rank);

      html += '<tr' + (cls ? ' class="' + cls + '"' : '') + '>';
      html += '<td style="text-align:center"><span class="sor-rank-badge">' + rank + '</span></td>';
      html += '<td class="sor-kingdom-name-cell">' + sorEsc(r.kingdom_name) + '</td>';
      html += '<td class="sor-count-cell">' + sorFmt(r.sign_in_count) + '</td>';
      html += '<td class="sor-pct-cell">'
            +   '<span class="sor-pct-value">' + pct.toFixed(1) + '%</span>'
            +   '<div class="sor-pct-bar-track">'
            +     '<div class="sor-pct-bar-fill" style="width:' + barW + '%"></div>'
            +   '</div>'
            + '</td>';
      html += '<td style="text-align:right">' + sorKingdomYoyDelta(r) + '</td>';
      html += '<td style="text-align:right">' + sorKingdomYoyPct(r) + '</td>';
      html += '</tr>';
    }
    tbody.innerHTML = html;
  }

  /* ----------------------------------------------------------------
     Sort data by column + direction
  ---------------------------------------------------------------- */
  function sorSort(col, dir) {
    var copy = _sorKingdomsData.slice();
    copy.sort(function (a, b) {
      var av, bv;
      if (col === 'name') {
        av = String(a.kingdom_name).toLowerCase();
        bv = String(b.kingdom_name).toLowerCase();
        return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
      }
      if (col === 'sign_ins') {
        av = parseInt(a.sign_in_count, 10);
        bv = parseInt(b.sign_in_count, 10);
      } else if (col === 'percentage') {
        av = parseFloat(a.percentage);
        bv = parseFloat(b.percentage);
      } else if (col === 'yoy_change') {
        av = parseInt(a.yoy_change, 10) || 0;
        bv = parseInt(b.yoy_change, 10) || 0;
      } else if (col === 'yoy_pct') {
        av = parseFloat(a.yoy_pct_change) || 0;
        bv = parseFloat(b.yoy_pct_change) || 0;
      } else {
        // rank
        av = parseInt(a.rank, 10);
        bv = parseInt(b.rank, 10);
      }
      return dir === 'asc' ? av - bv : bv - av;
    });
    return copy;
  }

  /* ----------------------------------------------------------------
     Attach click-to-sort on table header cells (once)
  ---------------------------------------------------------------- */
  function sorAttachSort() {
    if (_sorHandlersSet) return;
    _sorHandlersSet = true;

    var ths = document.querySelectorAll('#sor-kingdoms-table thead th[data-col]');
    for (var i = 0; i < ths.length; i++) {
      (function (th) {
        th.addEventListener('click', function () {
          var col = th.getAttribute('data-col');
          if (_sorSortCol === col) {
            _sorSortDir = _sorSortDir === 'asc' ? 'desc' : 'asc';
          } else {
            _sorSortCol = col;
            _sorSortDir = col === 'rank' ? 'asc' : 'desc';
          }

          // Update header indicator classes
          var allThs = document.querySelectorAll('#sor-kingdoms-table thead th');
          for (var j = 0; j < allThs.length; j++) {
            allThs[j].classList.remove('sor-sort-asc', 'sor-sort-desc');
          }
          th.classList.add('sor-sort-' + _sorSortDir);

          sorRenderTable(sorSort(_sorSortCol, _sorSortDir));
        });
      }(ths[i]));
    }
  }

  /* ----------------------------------------------------------------
     Highcharts v3 horizontal bar chart
  ---------------------------------------------------------------- */
  function sorRenderChart(kingdoms) {
    // Alphabetical order for visual consistency
    var sorted = kingdoms.slice().sort(function (a, b) {
      return String(a.kingdom_name).localeCompare(String(b.kingdom_name));
    });

    var categories = [];
    var values     = [];
    for (var i = 0; i < sorted.length; i++) {
      categories.push(sorted[i].kingdom_name);
      values.push(parseFloat(sorted[i].percentage) || 0);
    }

    // Grow chart height proportionally to kingdom count
    var dynHeight = Math.max(300, categories.length * 32 + 80);
    var chartEl   = document.getElementById('sor-kingdoms-chart');
    if (chartEl) chartEl.style.height = dynHeight + 'px';

    // Highcharts v3 API (same as v4/v5 for these features)
    if (typeof Highcharts !== 'undefined') {
      window.sorDestroyChart('sor-kingdoms-chart');
      new Highcharts.Chart({
        chart: {
          renderTo:        'sor-kingdoms-chart',
          type:            'bar',
          backgroundColor: 'transparent',
          style:           { fontFamily: 'inherit' },
          animation:       { duration: 550 },
          marginRight:     60   // room for data labels
        },
        title:    { text: null },
        subtitle: { text: null },
        credits:  { enabled: false },
        exporting: { enabled: false },

        xAxis: {
          categories: categories,
          labels: {
            style:  { fontSize: '11px', color: window.sorThemeColor('--ork-text-secondary', '#4a5568') },
            reserveSpace: true
          },
          lineColor:  window.sorThemeColor('--ork-border', '#e2e8f0'),
          tickColor:  window.sorThemeColor('--ork-border', '#e2e8f0')
        },

        yAxis: {
          title: {
            text:  '% of Total Sign-Ins',
            style: { color: window.sorThemeColor('--ork-text-muted', '#718096'), fontSize: '11px' }
          },
          labels: {
            formatter: function () { return this.value + '%'; },
            style:     { fontSize: '11px', color: window.sorThemeColor('--ork-text-muted', '#718096') }
          },
          gridLineColor: window.sorThemeColor('--ork-border', '#edf2f7'),
          min: 0
        },

        legend: { enabled: true, align: "center", verticalAlign: "bottom",
                  itemStyle: { fontWeight: "600", fontSize: "12px", color: window.sorThemeColor('--ork-text', '#2d3748') } },

        tooltip: {
          formatter: function () {
            return '<b>' + this.x + '</b><br/>Share: <b>' + this.y.toFixed(1) + '%</b>';
          },
          backgroundColor: 'rgba(26,26,26,0.88)',
          style:           { color: '#fff' },
          borderWidth:     0,
          shadow:          false
        },

        plotOptions: {
          bar: {
            dataLabels: {
              enabled:   true,
              formatter: function () { return this.y.toFixed(1) + '%'; },
              style: {
                fontSize:   '11px',
                fontWeight: '600',
                color:      '#4a5568',
                textShadow: 'none'
              }
            },
            colorByPoint: true,
            borderWidth:  0,
            borderRadius: 2,
            pointPadding: 0.06,
            groupPadding: 0.04
          }
        },

        /* 20-color cycle — uses Amtgard-friendly reds/blues then broadens out */
        colors: [
          '#c0392b','#2980b9','#27ae60','#8e44ad','#f39c12',
          '#16a085','#d35400','#2c3e50','#1abc9c','#e74c3c',
          '#3498db','#2ecc71','#9b59b6','#f1c40f','#e67e22',
          '#117a65','#6c3483','#1a252f','#784212','#922b21'
        ],

        series: [{
          name: 'Sign-In Share',
          data: values
        }]
      });
    } else {
      document.getElementById('sor-kingdoms-chart').innerHTML = '<p style="color:#999;padding:20px;text-align:center">Chart unavailable.</p>';
    }
  }

  /* ----------------------------------------------------------------
     PUBLIC: renderSorKingdoms(data)
     Called by the report page orchestrator once AJAX resolves.
  ---------------------------------------------------------------- */
  window.renderSorKingdoms = function (data) {
    var skeleton = document.getElementById('sor-kingdoms-skeleton');
    var content  = document.getElementById('sor-kingdoms-content');

    // Guard: bad payload
    if (!data || !Array.isArray(data.kingdoms) || data.kingdoms.length === 0) {
      if (skeleton) skeleton.style.display = 'none';
      if (content) {
        content.style.display = '';
        content.innerHTML = '<p style="color:#999;padding:30px;text-align:center"><i class="fas fa-info-circle"></i> No data available for the selected period and kingdoms.</p>';
      }
      return;
    }

    _sorKingdomsData = data.kingdoms;

    // 1. Hide skeleton, reveal content
    if (skeleton) skeleton.style.display = 'none';
    if (content)  content.style.display  = '';

    // 2. Hook up column-sort clicks
    sorAttachSort();

    // 3. Render table sorted by rank ascending (default)
    sorRenderTable(sorSort('rank', 'asc'));

    // 4. Render Highcharts bar chart
    sorRenderChart(data.kingdoms);
  };

}());
</script>

<script>
(function () {

  /* Class color palette */
  var SOR_CLASS_COLORS = {
    "Anti-Paladin": "#a0a0a0",
    "Archer":       "#FFA500",
    "Assassin":     "#333333",
    "Barbarian":    "#cccccc",
    "Bard":         "#ADD8E6",
    "Color":        "#ff6b6b",
    "Druid":        "#8B4513",
    "Healer":       "#e74c3c",
    "Monk":         "#808080",
    "Monster":      "#000080",
    "Paladin":      "#f1c40f",
    "Peasant":      "#27ae60",
    "Reeve":        "#2c3e50",
    "Scout":        "#2ecc71",
    "Warrior":      "#9b59b6",
    "Wizard":       "#f39c12"
  };

  function colorForClass(name) {
    return SOR_CLASS_COLORS[name] || "#888888";
  }

  function fmtNumber(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  function fmtPct(p) {
    return p.toFixed(1) + "%";
  }

  /**
   * renderSorClasses(data)
   * Called with the AJAX JSON payload.
   * data.classes = array of class objects (sorted by rank ascending from server,
   * but we sort defensively here too).
   */
  // FIX B: local HTML-escape helper for innerHTML concatenation in this IIFE
  function esc(s) {
    if (s == null) return "";
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  window.renderSorClasses = function (data) {
    var classes = (data && data.classes) ? data.classes.slice() : [];

    // T-4: No-data empty state
    if (!classes || classes.length === 0) {
      document.getElementById('sor-classes-skeleton').style.display = 'none';
      document.getElementById('sor-classes-content').style.display = 'block';
      document.getElementById('sor-classes-tbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:#999;padding:20px">No data for the selected period.</td></tr>';
      document.getElementById('sor-classes-chart').innerHTML = '<p style="color:#999;padding:20px;text-align:center">No data available.</p>';
      return;
    }

    /* Sort by rank for the table */
    classes.sort(function (a, b) { return a.rank - b.rank; });

    /* Max pct for bar scaling */
    var maxPct = 0;
    for (var i = 0; i < classes.length; i++) {
      if (classes[i].percentage > maxPct) maxPct = classes[i].percentage;
    }

    /* ---- Build table rows ---- */
    var tbody = document.getElementById("sor-classes-tbody");
    tbody.innerHTML = "";

    for (var i = 0; i < classes.length; i++) {
      var c = classes[i];
      var color = colorForClass(c.class_name);
      var barWidthPct = maxPct > 0 ? (c.percentage / maxPct * 100).toFixed(1) : 0;
      var isTop3 = c.rank <= 3;

      var warnHtml = "";
      if (c.inflated_note) {
        warnHtml = "<span class=\'sor-warn-icon\' tabindex=\'0\' aria-label=\'Warning\'>"
          + "&#x26A0;"
          + "<span class=\'sor-tooltip\'>May be inflated due to individual kingdom corpora requirements.</span>"
          + "</span>";
      }

      var tr = document.createElement("tr");
      tr.innerHTML =
        "<td class=\'sor-col-rank\'>"
          + "<span class=\'sor-rank-badge" + (isTop3 ? " sor-rank-top3" : "") + "\'>#" + c.rank + "</span>"
        + "</td>"
        + "<td>"
          + "<span class=\'sor-class-name-cell\'>"
            + "<span class=\'sor-class-dot\' style=\'background:" + color + "\'></span>"
            + "<span>" + esc(c.class_name) + "</span>"
            + warnHtml
          + "</span>"
        + "</td>"
        + "<td class=\'sor-col-signins\'><span class=\'sor-signins-val\'>" + fmtNumber(c.sign_in_count) + "</span></td>"
        + "<td class=\'sor-col-pct\'>"
          + "<div class=\'sor-pct-cell\'>"
            + "<div class=\'sor-pct-bar-bg\'>"
              + "<div class=\'sor-pct-bar-fill\' style=\'width:" + barWidthPct + "%;background:" + color + "\'></div>"
            + "</div>"
            + "<span class=\'sor-pct-label\'>" + fmtPct(c.percentage) + "</span>"
          + "</div>"
        + "</td>";

      tbody.appendChild(tr);
    }

    /* ---- Build chart data (ranked descending) ---- */
    var sorted = classes.slice().sort(function (a, b) { return b.sign_in_count - a.sign_in_count; });

    // Compute class diversity index (Shannon entropy, normalised 0-1)
    var totalPct = 0;
    for (var di = 0; di < classes.length; di++) totalPct += classes[di].percentage / 100;
    var entropy = 0;
    for (var di = 0; di < classes.length; di++) {
      var pp = classes[di].percentage / 100;
      if (pp > 0) entropy -= pp * Math.log2(pp);
    }
    var maxEntropy = classes.length > 1 ? Math.log2(classes.length) : 1;
    var diversityIdx = maxEntropy > 0 ? (entropy / maxEntropy).toFixed(2) : "0.00";
    // Inject diversity card before the table
    var divWrap = document.getElementById('sor-diversity-card-wrap');
    if (divWrap) {
      divWrap.innerHTML = '<div class="sor-diversity-card" id="sor-diversity-card">' +
        '<strong>Class Diversity Index:</strong> ' + diversityIdx +
        ' / 1.00 <span style="font-size:0.78rem;color:#888">(higher = more even spread across classes)</span>' +
        '</div>';
    }

    var chartCategories = [];
    var chartData = [];

    for (var j = 0; j < sorted.length; j++) {
      var cls = sorted[j];
      chartCategories.push(cls.class_name);
      chartData.push({
        y: cls.percentage,
        color: colorForClass(cls.class_name),
        borderColor: cls.inflated_note ? '#e67e22' : 'transparent',
        borderWidth: cls.inflated_note ? 2 : 0
      });
    }

    /* ---- Show content, hide skeleton (before chart so Highcharts can measure dimensions) ---- */
    document.getElementById("sor-classes-skeleton").style.display = "none";
    document.getElementById("sor-classes-content").style.display = "";

    /* ---- Render Highcharts bar chart ---- */
    // T-4: Guard: Highcharts availability check
    if (typeof Highcharts === 'undefined') {
      document.getElementById('sor-classes-chart').innerHTML = '<p style="color:#999;padding:20px">Chart unavailable.</p>';
    } else {
    window.sorDestroyChart('sor-classes-chart');
    new Highcharts.Chart({
      chart: {
        renderTo: "sor-classes-chart",
        type: "bar",
        height: Math.max(300, chartCategories.length * 28 + 80),
        style: { fontFamily: "inherit" },
        backgroundColor: "transparent",
        margin: [10, 80, 20, 130]
      },
      title: { text: null },
      credits: { enabled: false },
      legend: { enabled: false },
      xAxis: {
        categories: chartCategories,
        labels: { style: { fontSize: "11px", color: "#333" } },
        lineColor: "#ddd",
        tickColor: "#ddd"
      },
      yAxis: {
        title: { text: "% of Sign-Ins", style: { fontSize: "11px", color: "#777" } },
        gridLineColor: "#f0f0f0",
        labels: { formatter: function () { return this.value + "%"; },
                  style: { fontSize: "10px", color: "#777" } },
        min: 0
      },
      tooltip: {
        backgroundColor: "#1a1a2e",
        borderColor: "#c0392b",
        style: { color: "#fff" },
        formatter: function () {
          var note = this.point.borderWidth > 0
            ? '<br/><span style="color:#e67e22">&#9888; May be inflated</span>' : "";
          return "<b>" + this.x + "</b><br/>" + this.y.toFixed(1) + "% of sign-ins" + note;
        }
      },
      plotOptions: {
        bar: {
          borderRadius: 2,
          pointPadding: 0.05,
          groupPadding: 0.04,
          dataLabels: {
            enabled: true,
            formatter: function () { return this.y.toFixed(1) + "%"; },
            align: "right",
            inside: false,
            style: { fontSize: "10px", fontWeight: "600",
                     color: "#444", textShadow: "none" }
          }
        }
      },
      series: [{
        name: "Sign-In %",
        data: chartData
      }]
    });
    } // end Highcharts guard
  };

})();
</script>

<script>
(function () {

  /* ------------------------------------------------------------------ */
  /* State                                                                */
  /* ------------------------------------------------------------------ */
  var _sorParksTableData    = [];
  var _sorParksTableSortCol = 0;
  var _sorParksTableSortAsc = true;

  /* ------------------------------------------------------------------ */
  /* Public entry point — called by parent page after AJAX completes      */
  /* ------------------------------------------------------------------ */
  window.renderSorParks = function (data) {
    var skeleton = document.getElementById('sor-parks-skeleton');
    var content  = document.getElementById('sor-parks-content');
    function showError(msg) {
      if (skeleton) skeleton.style.display = 'none';
      if (content)  content.style.display = '';
      var bar = document.getElementById('sor-parks-stats-bar');
      if (bar) bar.innerHTML = '<p style="color:#c0392b;padding:20px">' + msg + '</p>';
    }
    if (!data || !data.parks) { showError('Error loading parks data.'); return; }
    var d = data.parks;
    // Show content BEFORE charts so Highcharts can measure container dimensions (error #13)
    if (skeleton) skeleton.style.display = 'none';
    if (content)  content.style.display  = '';
    try {
      sorParksRenderStats(d);
      sorParksRenderNarrative(d);
      sorParksRenderExpandable('new',  d.new_parks  || [], d.new_by_kingdom  || []);
      sorParksRenderExpandable('lost', d.lost_parks || [], d.lost_by_kingdom || []);
      sorParksRenderRiskAlert(d.downward_trend_parks || []);
      sorParksRenderTable(d.parks_by_kingdom || []);
      sorParksRenderChart(d.parks_by_kingdom || []);
      sorParksRenderNetChart(d);
      sorParksRenderHealthChart(d.parks_by_kingdom || []);
    } catch (e) {
      console.error('Parks render error:', e);
      showError('Parks render error: ' + (e && e.message ? e.message : String(e)));
      return;
    }
  };

  /* ------------------------------------------------------------------ */
  /* 1. Stats bar                                                         */
  /* ------------------------------------------------------------------ */
  function sorParksRenderStats(d) {
    var atRisk  = (d.downward_trend_parks || []).length;
    var netPark = (d.new_parks_count || 0) - (d.lost_parks_count || 0);
    var netMod  = netPark > 0 ? 'sor-parks-stat-new' : (netPark < 0 ? 'sor-parks-stat-lost' : '');
    var netVal  = (netPark > 0 ? '+' : '') + netPark;
    var cards = [
      { label: 'Total Active Parks', value: d.total_active      || 0, mod: '' },
      { label: 'Avg Parks / Kingdom',value: d.avg_per_kingdom   || 0, mod: '' },
      { label: 'Net Park Change',    value: netVal,                   mod: netMod },
      { label: 'New in Period',      value: d.new_parks_count   || 0, mod: 'sor-parks-stat-new' },
      { label: 'Lost in Period',     value: d.lost_parks_count  || 0, mod: 'sor-parks-stat-lost' },
      { label: 'At Risk',            value: atRisk,                   mod: 'sor-parks-stat-risk' }
    ];
    var html = '';
    cards.forEach(function (c) {
      html += '<div class="sor-parks-stat-card ' + c.mod + '">' +
                '<div class="sor-parks-stat-value">' + c.value + '</div>' +
                '<div class="sor-parks-stat-label">'  + c.label + '</div>' +
              '</div>';
    });
    document.getElementById('sor-parks-stats-bar').innerHTML = html;
  }

  /* ------------------------------------------------------------------ */
  /* 2. Narrative paragraph                                               */
  /* ------------------------------------------------------------------ */
  function sorParksRenderNarrative(d) {
    var newCount  = d.new_parks_count  || 0;
    var lostCount = d.lost_parks_count || 0;
    var newByKd   = d.new_by_kingdom   || [];
    var lostByKd  = d.lost_by_kingdom  || [];

    var lines = [];

    /* Opener */
    lines.push(
      '<strong>' + newCount  + ' new park'  + (newCount  !== 1 ? 's' : '') + '</strong> were founded and ' +
      '<strong>' + lostCount + ' park' + (lostCount !== 1 ? 's' : '') + '</strong> were lost during this period.'
    );

    /* New parks by kingdom */
    if (newByKd.length) {
      var parts = newByKd.map(function (k) {
        return '<strong>' + sorEsc(k.count) + '</strong> new park' +
               (k.count !== 1 ? 's were' : ' was') +
               ' founded in <strong>' + sorEsc(k.kingdom_name) + '</strong>';
      });
      lines.push(parts.join('; ') + '.');
    }

    /* Lost parks by kingdom */
    if (lostByKd.length) {
      var lparts = lostByKd.map(function (k) {
        return '<strong>' + sorEsc(k.count) + '</strong> park' +
               (k.count !== 1 ? 's were' : ' was') +
               ' lost in <strong>' + sorEsc(k.kingdom_name) + '</strong>';
      });
      lines.push(lparts.join('; ') + '.');
    }

    document.getElementById('sor-parks-narrative').innerHTML =
      lines.map(function (l) { return '<p>' + l + '</p>'; }).join('');
  }

  /* ------------------------------------------------------------------ */
  /* 2b. Expandable new / lost lists                                      */
  /* ------------------------------------------------------------------ */
  function sorParksRenderExpandable(type, parks, byKingdom) {
    var labelEl   = document.getElementById('sor-parks-' + type + '-toggle-label');
    var bodyEl    = document.getElementById('sor-parks-' + type + '-body');
    var pillClass = (type === 'new') ? 'sor-parks-pill-new' : 'sor-parks-pill-lost';
    var count     = parks.length;
    var verb      = (type === 'new') ? 'new' : 'lost';

    if (labelEl) {
      labelEl.textContent = 'Show ' + count + ' ' + verb + ' park' + (count !== 1 ? 's' : '');
    }

    /* Group by kingdom — preserve insertion order */
    var grouped = {};
    var order   = [];
    parks.forEach(function (p) {
      var kd = p.kingdom_name || 'Unknown';
      if (!grouped[kd]) { grouped[kd] = []; order.push(kd); }
      grouped[kd].push(p.park_name);
    });

    var html = '';
    order.forEach(function (kd) {
      html += '<div class="sor-parks-kingdom-group">' +
                '<div class="sor-parks-kingdom-label">' + sorEsc(kd) + '</div>' +
                '<ul class="sor-parks-park-list">';
      grouped[kd].forEach(function (name) {
        html += '<li><span class="sor-parks-park-pill ' + pillClass + '">' + sorEsc(name) + '</span></li>';
      });
      html += '</ul></div>';
    });

    if (bodyEl) {
      bodyEl.innerHTML = html ||
        '<em style="color:#888;font-size:0.85rem">No parks to display.</em>';
    }
  }

  /* Public toggle — called from inline onclick */
  window.sorParksToggle = function (type) {
    var headerEl = document.getElementById('sor-parks-' + type + '-toggle');
    var bodyEl   = document.getElementById('sor-parks-' + type + '-body');
    if (!headerEl || !bodyEl) { return; }
    var isOpen = bodyEl.classList.contains('sor-parks-visible');
    bodyEl.classList.toggle('sor-parks-visible', !isOpen);
    headerEl.classList.toggle('sor-parks-open', !isOpen);
  };

  /* ------------------------------------------------------------------ */
  /* 3. At-risk alert box                                                 */
  /* ------------------------------------------------------------------ */
  function sorParksRenderRiskAlert(parks) {
    var el = document.getElementById('sor-parks-risk-alert');
    if (!parks || parks.length === 0) { el.innerHTML = ''; return; }

    var rows = parks.map(function (p) {
      var r      = parseFloat(p.spearman_r) || 0;
      var rLabel = r.toFixed(2);
      var badge  = (r < -0.8)
        ? '<span class="sor-parks-risk-badge sor-parks-risk-high">High</span>'
        : '<span class="sor-parks-risk-badge sor-parks-risk-med">Moderate</span>';
      var rSpan  = '<span style="font-size:0.75rem;color:#888;margin-left:4px">r\u00a0=\u00a0' + rLabel + '</span>';
      // Build compact year-by-year sparkline text
      var trendHtml = '&mdash;';
      if (p.trend_years && p.trend_years.length) {
        var maxC = Math.max.apply(null, p.trend_years.map(function(y){return y.count;})) || 1;
        trendHtml = p.trend_years.map(function (y) {
          var ht = Math.max(4, Math.round((y.count / maxC) * 24));
          var clr = y.count <= 5 ? '#e74c3c' : (y.count <= 12 ? '#e67e22' : '#7f8c8d');
          return '<span title="' + y.year + ': ' + y.count + ' sign-ins" style="display:inline-block;width:10px;height:' + ht + 'px;background:' + clr + ';margin:0 1px;vertical-align:bottom;border-radius:2px 2px 0 0"></span>';
        }).join('');
        trendHtml = '<span style="display:inline-flex;align-items:flex-end;height:26px;gap:0">' + trendHtml + '</span>';
      }
      return '<tr>' +
               '<td>' + sorEsc(p.park_name)    + '</td>' +
               '<td>' + sorEsc(p.kingdom_name) + '</td>' +
               '<td style="text-align:right">' +
                 (p.last_year_count != null ? p.last_year_count : '&mdash;') +
               '</td>' +
               '<td style="text-align:center">' + trendHtml + '</td>' +
               '<td style="text-align:center">' + badge + rSpan + '</td>' +
             '</tr>';
    }).join('');

    el.innerHTML =
      '<div class="sor-parks-alert">' +
        '<div class="sor-parks-alert-title">&#9888; At-Risk Parks (' + parks.length + ')</div>' +
        '<div class="sor-parks-alert-subtitle">' +
          'Parks with a statistically declining sign-in trend (Spearman r &lt; &minus;0.5) over the past 5 years, ' +
          'with &le;20 sign-ins in the most recent year. ' +
          '<strong>High risk</strong> = r &lt; &minus;0.8 (steep decline). ' +
          '<strong>Moderate</strong> = &minus;0.8 &le; r &lt; &minus;0.5 (moderate decline).' +
        '</div>' +
        '<table class="sor-parks-risk-table">' +
          '<thead><tr>' +
            '<th>Park</th>' +
            '<th>Kingdom</th>' +
            '<th style="text-align:right">Recent Sign-Ins</th>' +
            '<th style="text-align:center">Year-by-Year</th>' +
            '<th style="text-align:center">Risk Level</th>' +
          '</tr></thead>' +
          '<tbody>' + rows + '</tbody>' +
        '</table>' +
      '</div>';
  }

  /* ------------------------------------------------------------------ */
  /* 4. Parks by Kingdom table                                            */
  /* ------------------------------------------------------------------ */
  function sorParksRenderTable(rows) {
    _sorParksTableData = rows.slice();
    /* Default sort: kingdom name A-Z */
    _sorParksTableSortCol = 0;
    _sorParksTableSortAsc = true;
    sorParksSortAndRender();
  }

  window.sorParksSort = function (col) {
    if (_sorParksTableSortCol === col) {
      _sorParksTableSortAsc = !_sorParksTableSortAsc;
    } else {
      _sorParksTableSortCol = col;
      _sorParksTableSortAsc = (col !== 1 && col !== 2 && col !== 3); /* numeric cols default desc */
    }
    sorParksSortAndRender();
  };

  function sorParksSortAndRender() {
    var col = _sorParksTableSortCol;
    var asc = _sorParksTableSortAsc;

    var sorted = _sorParksTableData.slice().sort(function (a, b) {
      var va, vb;
      switch (col) {
        case 1:
          va = +(a.active_parks  || 0);
          vb = +(b.active_parks  || 0);
          break;
        case 2:
          va = +(a.retired_parks || 0);
          vb = +(b.retired_parks || 0);
          break;
        case 3:
          va = parseFloat(a.ratio);
          vb = parseFloat(b.ratio);
          if (isNaN(va)) { va = -Infinity; }
          if (isNaN(vb)) { vb = -Infinity; }
          break;
        default:
          va = (a.kingdom_name || '').toLowerCase();
          vb = (b.kingdom_name || '').toLowerCase();
      }
      if (va < vb) { return asc ? -1 :  1; }
      if (va > vb) { return asc ?  1 : -1; }
      return 0;
    });

    var html = sorted.map(function (r) {
      var ratio    = parseFloat(r.ratio);
      var ratioStr = isNaN(ratio) ? '&mdash;' : ratio.toFixed(1);
      var ratioCls = isNaN(ratio)
        ? 'sor-parks-ratio-neutral'
        : (ratio >= 1 ? 'sor-parks-ratio-good' : 'sor-parks-ratio-bad');

      return '<tr>' +
               '<td>' + sorEsc(r.kingdom_name || '') + '</td>' +
               '<td style="text-align:right">' + (r.active_parks  || 0) + '</td>' +
               '<td style="text-align:right">' + (r.retired_parks || 0) + '</td>' +
               '<td style="text-align:right" class="' + ratioCls + '">' + ratioStr + '</td>' +
             '</tr>';
    }).join('');

    document.getElementById('sor-parks-table-body').innerHTML = html;

    /* Update sort-arrow classes on headers */
    var ths = document.querySelectorAll('#sor-parks-kingdom-table thead th');
    ths.forEach(function (th, i) {
      th.classList.remove('sor-parks-sort-asc', 'sor-parks-sort-desc');
      if (i === col) {
        th.classList.add(asc ? 'sor-parks-sort-asc' : 'sor-parks-sort-desc');
      }
    });
  }

  /* ------------------------------------------------------------------ */
  /* 5. Grouped bar chart (Highcharts)                                    */
  /* ------------------------------------------------------------------ */
  function sorParksRenderChart(rows) {
    var container = document.getElementById('sor-parks-chart-container');
    if (typeof Highcharts === 'undefined') {
      container.innerHTML =
        '<p style="padding:16px;color:#888;font-size:0.85rem">' +
          'Chart unavailable (Highcharts not loaded).' +
        '</p>';
      return;
    }

    /* Sort alphabetically by kingdom name */
    var sorted = rows.slice().sort(function (a, b) {
      return (a.kingdom_name || '').localeCompare(b.kingdom_name || '');
    });

    var categories  = sorted.map(function (r) { return r.kingdom_name || ''; });
    var activeData  = sorted.map(function (r) { return +(r.active_parks  || 0); });
    var retiredData = sorted.map(function (r) { return +(r.retired_parks || 0); });

    /* Dynamically widen container if many kingdoms */
    var minWidth = Math.max(600, categories.length * 60);
    container.style.width = minWidth + 'px';

    window.sorDestroyChart('sor-parks-chart-container');
    new Highcharts.Chart({

      chart: {
        renderTo: 'sor-parks-chart-container',
        type: 'column',
        height: 350,
        backgroundColor: 'transparent',
        style: { fontFamily: 'inherit' },
        marginBottom: 90
      },
      title:    { text: null },
      subtitle: { text: null },
      credits:  { enabled: false },
      legend: {
        enabled: true,
        align: 'right',
        verticalAlign: 'top',
        itemStyle: { fontWeight: '600', fontSize: '12px' }
      },
      xAxis: {
        categories: categories,
        labels: {
          rotation: -45,
          style: { fontSize: '11px', color: window.sorThemeColor('--ork-text-secondary', '#555') },
          align: 'right'
        },
        crosshair: { color: 'rgba(44,95,110,0.1)' }
      },
      yAxis: {
        min: 0,
        allowDecimals: false,
        title: {
          text: 'Number of Parks',
          style: { fontSize: '11px', color: window.sorThemeColor('--ork-text-muted', '#666') }
        },
        gridLineColor: window.sorThemeColor('--ork-border', '#f0f0f0'),
        labels: { style: { fontSize: '11px', color: window.sorThemeColor('--ork-text-muted', '#666') } }
      },
      tooltip: {
        shared: true,
        headerFormat: '<b>{point.key}</b><br/>',
        pointFormat: '<span style="color:{point.color}">●</span> {series.name}: <b>{point.y}</b><br/>'
      },
      plotOptions: {
        column: {
          grouping: true,
          borderWidth: 0,
          borderRadius: 2,
          pointPadding: 0.1,
          groupPadding: 0.15
        }
      },
      series: [
        { name: 'Active',  data: activeData,  color: '#2c8a7a' },
        { name: 'Retired', data: retiredData, color: '#e57373' }
      ]
    });
  }

  /* ------------------------------------------------------------------ */
  /* 5b. Net Park Change diverging chart                                  */
  /* ------------------------------------------------------------------ */
  function sorParksRenderNetChart(d) {
    var container = document.getElementById('sor-parks-net-chart');
    if (!container) return;
    if (typeof Highcharts === 'undefined') {
      container.innerHTML = '<p style="padding:12px;color:#888">Chart unavailable.</p>';
      return;
    }
    var newMap = {}, lostMap = {};
    (d.new_by_kingdom  || []).forEach(function (k) { newMap[k.kingdom_name]  = k.count; });
    (d.lost_by_kingdom || []).forEach(function (k) { lostMap[k.kingdom_name] = k.count; });
    var allKd = {};
    (d.new_by_kingdom  || []).forEach(function (k) { allKd[k.kingdom_name] = 1; });
    (d.lost_by_kingdom || []).forEach(function (k) { allKd[k.kingdom_name] = 1; });
    var kds = Object.keys(allKd).sort();
    if (kds.length === 0) { container.innerHTML = '<p style="padding:12px;color:#888;text-align:center">No new or lost parks this period.</p>'; return; }
    var netData = kds.map(function (kd) {
      var net = (newMap[kd] || 0) - (lostMap[kd] || 0);
      return { y: net, color: net > 0 ? '#27ae60' : (net < 0 ? '#c0392b' : '#aaa') };
    });
    window.sorDestroyChart('sor-parks-net-chart');
    new Highcharts.Chart({

      chart: { renderTo: 'sor-parks-net-chart', type: 'column', height: 220, backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
      title:   { text: null }, credits: { enabled: false }, legend: { enabled: false },
      xAxis: { categories: kds, labels: { rotation: -45, style: { fontSize: '10px' }, align: 'right' } },
      yAxis:   { title: { text: 'Net (New − Lost)' }, allowDecimals: false,
                 plotLines: [{ value: 0, color: window.sorThemeColor('--ork-text', '#2d3748'), width: 1.5, zIndex: 4 }] },
      tooltip: { backgroundColor: '#1a1a2e', borderColor: '#c0392b', style: { color: '#fff' },
                 formatter: function () {
                   return '<b>' + this.x + '</b><br/>Net: <b>' + (this.y >= 0 ? '+' : '') + this.y + '</b>';
                 }},
      plotOptions: { column: { borderWidth: 0, borderRadius: 2,
                               dataLabels: { enabled: true, style: { fontSize: '10px', textShadow: 'none' },
                                            formatter: function () { return (this.y > 0 ? '+' : '') + this.y; } } } },
      series: [{ name: 'Net Change', data: netData }]
    });
  }

  /* ------------------------------------------------------------------ */
  /* 5c. Parks health stacked horizontal bar                              */
  /* ------------------------------------------------------------------ */
  function sorParksRenderHealthChart(rows) {
    var container = document.getElementById('sor-parks-health-chart');
    if (!container) return;
    if (typeof Highcharts === 'undefined') {
      container.innerHTML = '<p style="padding:12px;color:#888">Chart unavailable.</p>';
      return;
    }
    var sorted = rows.slice().sort(function (a, b) {
      return (b.active_parks || 0) - (a.active_parks || 0);
    });
    var cats   = sorted.map(function (r) { return r.kingdom_name || ''; });
    var actD   = sorted.map(function (r) { return +(r.active_parks  || 0); });
    var retD   = sorted.map(function (r) { return +(r.retired_parks || 0); });
    window.sorDestroyChart('sor-parks-health-chart');
    new Highcharts.Chart({

      chart: { renderTo: 'sor-parks-health-chart', type: 'bar', height: Math.max(320, cats.length * 22 + 100),
               backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
      title: { text: null }, credits: { enabled: false },
      legend: { enabled: true, align: 'right', verticalAlign: 'top' },
      xAxis: { categories: cats, labels: { style: { fontSize: '11px' } } },
      yAxis: { min: 0, allowDecimals: false, title: { text: 'Total Parks' } },
      tooltip: { shared: true, backgroundColor: '#1a1a2e', borderColor: '#c0392b',
                 style: { color: '#fff' },
                 formatter: function () {
                   var pts = this.points;
                   var act = pts[0] ? pts[0].y : 0, ret = pts[1] ? pts[1].y : 0;
                   var ratio = ret > 0 ? (act / ret).toFixed(1) + ':1' : 'N/A';
                   return '<b>' + this.x + '</b><br/>'
                     + '<span style="color:#27ae60">&#9679;</span> Active: <b>' + act + '</b><br/>'
                     + '<span style="color:#e57373">&#9679;</span> Retired: <b>' + ret + '</b><br/>'
                     + 'Ratio: <b>' + ratio + '</b>';
                 }},
      plotOptions: { bar: { stacking: 'normal', borderWidth: 0,
                            dataLabels: { enabled: true,
                                         formatter: function () { return this.y > 2 ? this.y : null; },
                                         style: { fontSize: '10px', color: '#fff', textShadow: 'none' } } } },
      series: [
        { name: 'Active',  data: actD, color: '#27ae60' },
        { name: 'Retired', data: retD, color: '#e57373' }
      ]
    });
  }

  /* ------------------------------------------------------------------ */
  /* Utility: HTML-escape                                                 */
  /* ------------------------------------------------------------------ */
  // FIX D: destroy any existing Highcharts instance rendered into the given container
  // before re-rendering, so repeated 'Generate Report' clicks don't stack charts.
  function sorDestroyChart(containerId) {
    if (typeof Highcharts === 'undefined' || !Highcharts.charts) return;
    for (var i = 0; i < Highcharts.charts.length; i++) {
      var c = Highcharts.charts[i];
      if (c && c.renderTo && c.renderTo.id === containerId) {
        try { c.destroy(); } catch (e) { /* swallow */ }
      }
    }
    var el = document.getElementById(containerId);
    if (el) el.innerHTML = '';
  }
  window.sorDestroyChart = sorDestroyChart;

  function sorEsc(str) {
    if (str == null) { return ''; }
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

})();
</script>

<script>
(function () {
  // ---- Helpers --------------------------------------------------------

  function fmtNum(n, decimals) {
    if (n == null) return "&mdash;";
    if (decimals != null) {
      return parseFloat(n).toLocaleString("en-US", {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
    }
    return parseInt(n, 10).toLocaleString("en-US");
  }

  function buildCard(value, label, icon, accentClass, tip) {
    return (
      "<div class=\"sor-players-card sor-tip-card " + (accentClass || "") + "\"" +
      (tip ? " data-tip=\"" + tip + "\"" : "") + ">" +
      "<span class=\"sor-players-card-icon\">" + icon + "</span>" +
      "<div class=\"sor-players-card-value\">" + value + "</div>" +
      "<div class=\"sor-players-card-label\">" + label + "</div>" +
      "</div>"
    );
  }

  function buildProseCol(title, stats, isNormal) {
    var s = stats;
    var intro = isNormal
      ? "Among <strong>normal players</strong> (4+ sign-ins), there were <strong>" + fmtNum(s.total_sign_ins) + "</strong> sign-ins recorded for <strong>" + fmtNum(s.count) + "</strong> players."
      : "There were <strong>" + fmtNum(s.total_sign_ins) + "</strong> sign-ins recorded for <strong>" + fmtNum(s.total_players) + "</strong> players this period.";

    var lifespanLine = s.avg_lifespan_years != null
      ? "<br>On average, a player participated for <strong>" + fmtNum(s.avg_lifespan_years, 1) + " years</strong>."
      : "";

    return (
      "<div class=\"sor-players-prose-col\">" +
      "<h4>" + title + "</h4>" +
      "<p>" + intro +
      " On average, a player signed in <strong>" + fmtNum(s.avg_sign_ins, 1) + "</strong> times" +
      " (std dev <strong>&plusmn;" + fmtNum(s.std_dev_sign_ins, 1) + "</strong>)." +
      " The range was <strong>" + fmtNum(s.min_sign_ins) + "</strong> to <strong>" + fmtNum(s.max_sign_ins) + "</strong> sign-ins." +
      lifespanLine +
      "</p>" +
      "<p>Players averaged <strong>" + fmtNum(s.avg_credits, 1) + " credits</strong>" +
      (s.avg_credits_per_week != null
        ? " (<strong>" + fmtNum(s.avg_credits_per_week, 1) + "</strong> credits/week)."
        : ".") +
      "</p>" +
      "</div>"
    );
  }

  // ---- Main render function -------------------------------------------

  window.renderSorPlayers = function (data) {
    var p  = data && data.players;
    var np = p && p.normal_players;
    var ty = p && p.ten_year;
    var tyNp = ty && ty.normal_players;

    // T-5: Null guard
    if (!p || !np || !ty || !tyNp) {
      document.getElementById('sor-players-skeleton').style.display = 'none';
      document.getElementById('sor-players-content').style.display = '';
      document.getElementById('sor-players-period-cards').innerHTML = '<p style="color:#c0392b;padding:20px">Error loading player data.</p>';
      return;
    }

    // --- Period stat cards ---
    var cardsHtml =
      buildCard(fmtNum(p.total_sign_ins), "Total Sign-Ins", "&#9997;", "",
        "Total attendance records in the period. Each park event check-in counts as one sign-in.") +
      buildCard(fmtNum(p.total_players), "Active Players", "&#128101;", "accent-blue",
        "Unique players (mundane IDs) with at least one sign-in during the period.") +
      buildCard(fmtNum(p.avg_sign_ins, 1), "Avg Sign-Ins / Player", "&#128200;", "accent-green",
        "Total sign-ins divided by unique active players. Higher = more frequent attendance.") +
      buildCard(fmtNum(p.normal_players.count), "Normal Players", "&#11088;", "accent-amber",
        "Players with 4+ sign-ins AND 12+ total credits earned. Identifies committed members vs casual visitors.") +
      buildCard((p.total_players > 0 ? ((p.normal_players.count / p.total_players) * 100).toFixed(1) : '0') + '%', "Engagement Rate", "&#127919;", "accent-red",
        "Normal Players / Active Players. What fraction of active players meet the committed-member threshold.");
    document.getElementById("sor-players-period-cards").innerHTML = cardsHtml;

    // --- Prose stats ---
    var allStats = {
      total_sign_ins:       p.total_sign_ins,
      total_players:        p.total_players,
      avg_sign_ins:         p.avg_sign_ins,
      std_dev_sign_ins:     p.std_dev_sign_ins,
      min_sign_ins:         p.min_sign_ins,
      max_sign_ins:         p.max_sign_ins,
      avg_credits:          p.avg_credits,
      avg_credits_per_week: p.avg_credits_per_week
    };
    var normalStats = {
      count:                p.normal_players.count,
      total_sign_ins:       p.normal_players.total_sign_ins,
      avg_sign_ins:         p.normal_players.avg_sign_ins,
      std_dev_sign_ins:     p.normal_players.std_dev_sign_ins,
      min_sign_ins:         p.normal_players.min_sign_ins,
      max_sign_ins:         p.normal_players.max_sign_ins,
      avg_credits:          p.normal_players.avg_credits,
      avg_credits_per_week: p.normal_players.avg_credits_per_week
    };
    document.getElementById("sor-players-prose").innerHTML =
      buildProseCol("All Players", allStats, false) +
      buildProseCol("Normal Players (4+ Sign-Ins)", normalStats, true);

    // --- Show content, hide skeleton before any chart renders (error #13) ---
    document.getElementById("sor-players-skeleton").style.display = "none";
    document.getElementById("sor-players-content").style.display = "";

    // --- Trend chart ---
    var trend       = p.trend_by_year        || [];
    var trendNormal = p.trend_normal_by_year  || [];
    var chartYears  = trend.map(function (r) { return r.year.toString(); });
    var chartValues = trend.map(function (r) { return r.sign_ins; });
    // Build normal-player values aligned to same years
    var normYearMap = {};
    trendNormal.forEach(function (r) { normYearMap[r.year] = r.normal_players; });
    var chartValuesNorm = trend.map(function (r) { return normYearMap[r.year] || 0; });

    if (typeof Highcharts !== 'undefined') {
      window.sorDestroyChart('sor-players-chart');
      new Highcharts.Chart({
        chart: {
          renderTo: "sor-players-chart",
          type: "line",
          height: 300,
          style: { fontFamily: "inherit" },
          backgroundColor: "transparent",
          plotBorderColor: "#e0e0e0",
          spacingTop: 10,
          spacingBottom: 10
        },
        title: { text: null },
        credits: { enabled: false },
        xAxis: {
          categories: chartYears,
          labels: {
            style: { fontSize: "11px", color: "#555" }
          },
          lineColor: "#ddd",
          tickColor: "#ddd"
        },
        yAxis: [
          {
            title: { text: "Annual Sign-Ins", style: { color: "#2980b9", fontSize: "11px" } },
            gridLineColor: "#efefef",
            labels: {
              formatter: function () {
                var v = this.value;
                return v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v;
              },
              style: { fontSize: "11px", color: "#2980b9" }
            },
            min: 0
          },
          {
            title: { text: "Normal Players", style: { color: "#c0392b", fontSize: "11px" } },
            opposite: true,
            gridLineColor: "transparent",
            labels: {
              formatter: function () {
                var v = this.value;
                return v >= 1000 ? (v / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : v;
              },
              style: { fontSize: "11px", color: "#c0392b" }
            },
            min: 0
          }
        ],
        tooltip: {
          shared: true,
          useHTML: true,
          backgroundColor: "#1a1a2e",
          borderColor: "#c0392b",
          borderRadius: 6,
          style: { color: "#fff", fontSize: "12px" },
          formatter: function () {
            var pts = this.points || [];
            var si   = pts[0] ? pts[0].y : 0;
            var norm = pts[1] ? pts[1].y : 0;
            return "<b>" + this.x + "</b><br/>"
              + '<span style="color:#90caf9">●</span> Sign-Ins: <b>' + si.toLocaleString('en-US') + '</b><br/>'
              + '<span style="color:#e67e7e">●</span> Normal Players: <b>' + norm.toLocaleString('en-US') + '</b>';
          }
        },
        plotOptions: {
          series: { animation: { duration: 500 } }
        },
        legend: {
          enabled: true,
          itemStyle: { fontSize: '11px', fontWeight: '600', color: window.sorThemeColor('--ork-text-secondary', '#555') }
        },
        series: [
          { name: "Annual Sign-Ins", type: "areaspline", yAxis: 0, data: chartValues,
            color: "#2980b9", fillOpacity: 0.12, lineWidth: 2,
            marker: { enabled: true, radius: 4, symbol: "circle",
                      fillColor: "#2980b9", lineColor: "#fff", lineWidth: 1 } },
          { name: "Normal Players",  type: "line",       yAxis: 1, data: chartValuesNorm,
            color: "#c0392b", lineWidth: 2.5, dashStyle: "ShortDash",
            marker: { enabled: true, radius: 5, symbol: "circle",
                      fillColor: "#c0392b", lineColor: "#fff", lineWidth: 1.5 } }
        ]
      });
    } else {
      document.getElementById('sor-players-chart').innerHTML = '<p style="color:#999;padding:20px;text-align:center">Chart unavailable.</p>';
    }

    // --- 10-year cards ---
    ty = p.ten_year;
    var tyCardsHtml =
      buildCard(fmtNum(ty.total_sign_ins), "Total Sign-Ins (10 yr)", "&#9997;", "") +
      buildCard(fmtNum(ty.total_players), "Unique Players (10 yr)", "&#128101;", "accent-blue") +
      buildCard(fmtNum(ty.avg_sign_ins, 1), "Avg Sign-Ins / Player", "&#128200;", "accent-green") +
      buildCard(fmtNum(ty.normal_players.count), "Normal Players", "&#11088;", "accent-amber");
    document.getElementById("sor-players-tenyear-cards").innerHTML = tyCardsHtml;

    // --- 10-year prose ---
    var tyAllStats = {
      total_sign_ins:       ty.total_sign_ins,
      total_players:        ty.total_players,
      avg_sign_ins:         ty.avg_sign_ins,
      std_dev_sign_ins:     ty.std_dev_sign_ins,
      min_sign_ins:         ty.min_sign_ins,
      max_sign_ins:         ty.max_sign_ins,
      avg_credits:          ty.avg_credits,
      avg_credits_per_week: ty.avg_credits_per_week,
      avg_lifespan_years:   ty.avg_lifespan_years
    };
    var tyNormalStats = {
      count:                ty.normal_players.count,
      total_sign_ins:       ty.normal_players.total_sign_ins,
      avg_sign_ins:         ty.normal_players.avg_sign_ins,
      std_dev_sign_ins:     ty.normal_players.std_dev_sign_ins,
      min_sign_ins:         ty.normal_players.min_sign_ins,
      max_sign_ins:         ty.normal_players.max_sign_ins,
      avg_credits:          ty.normal_players.avg_credits,
      avg_credits_per_week: ty.normal_players.avg_credits_per_week,
      avg_lifespan_years:   ty.normal_players.avg_lifespan_years
    };
    document.getElementById("sor-players-tenyear-prose").innerHTML =
      buildProseCol("All Players (10 Years)", tyAllStats, false) +
      buildProseCol("Normal Players — 10 Years", tyNormalStats, true);

  };


  // ---- Cohort funnel renderer (called from orchestrator cohorts fetch) ----
  // renderSorCohorts: just show container; chart drawn in sorDrawFunnelChart() after all payloads load
  window.renderSorCohorts = function (data) {
    if (data && data.cohorts) {
      var funnelEl = document.getElementById('sor-players-funnel');
      if (funnelEl) funnelEl.style.display = '';
    }
  };

  window.sorDrawFunnelChart = function () {
    var _p   = window._sorPayloads || {};
    var co   = _p['cohorts'] && _p['cohorts'].cohorts;
    var pPay = _p['players'] && _p['players'].players;
    if (!co || !pPay) return;
    if (typeof Highcharts === 'undefined') return;
    var funnelEl = document.getElementById('sor-players-funnel');
    if (funnelEl) funnelEl.style.display = '';
    var allP  = pPay.total_players || 0;
    var normP = (pPay.normal_players || {}).count || 0;
    var retP  = parseInt(co.returning_players || 0, 10);
    var newP  = parseInt(co.new_players       || 0, 10);
    var funnelData = [
      { name: 'All Active Players',    y: allP,  color: '#90caf9' },
      { name: 'Normal Players (4+ SI)',y: normP, color: '#2980b9' },
      { name: 'Returning Players',     y: retP,  color: '#27ae60' },
      { name: 'New Players',           y: newP,  color: '#f39c12' }
    ];
    var maxV = allP || 1;
    window.sorDestroyChart('sor-players-funnel-chart');
    new Highcharts.Chart({

      chart: { renderTo: 'sor-players-funnel-chart', type: 'bar', height: 140, backgroundColor: 'transparent',
               margin: [4, 120, 24, 170], style: { fontFamily: 'inherit' } },
      title: { text: null }, credits: { enabled: false }, legend: { enabled: false },
      xAxis: { categories: funnelData.map(function (d) { return d.name; }),
               labels: { style: { fontSize: '11px', fontWeight: '600' } } },
      yAxis: { min: 0, max: maxV, visible: false },
      tooltip: { backgroundColor: '#1a1a2e', borderColor: '#c0392b', style: { color: '#fff' },
                 formatter: function () {
                   var pct = maxV > 0 ? ((this.y / maxV) * 100).toFixed(1) : '0';
                   return '<b>' + this.x + '</b><br/>' + this.y.toLocaleString('en-US')
                     + ' players (' + pct + '% of active)';
                 }},
      plotOptions: { bar: { borderWidth: 0, borderRadius: 3,
        dataLabels: { enabled: true, align: 'right', inside: false,
          formatter: function () {
            var pct = maxV > 0 ? ' (' + ((this.y / maxV) * 100).toFixed(0) + '%)' : '';
            return this.y.toLocaleString('en-US') + pct;
          },
          style: { fontSize: '11px', fontWeight: '600', textShadow: 'none', color: window.sorThemeColor('--ork-text', '#333') } } } },
      series: [{ name: 'Players', data: funnelData.map(function (d) { return { y: d.y, color: d.color }; }) }]
    });
  };

  // ---- 10-year toggle ------------------------------------------------
  window.sorPlayersToggleTenYear = function () {
    var btn  = document.getElementById("sor-players-tenyear-btn");
    var body = document.getElementById("sor-players-tenyear-body");
    var isOpen = btn.classList.contains("open");
    if (isOpen) {
      btn.classList.remove("open");
      body.classList.remove("open");
    } else {
      btn.classList.add("open");
      body.classList.add("open");
    }
  };

}());
</script>

<script>
(function () {
  'use strict';

  var LON_COLORS = [
    '#3498db', '#2ecc71', '#e67e22', '#9b59b6',
    '#1abc9c', '#e74c3c', '#f39c12'
  ];

  window.renderSorLongevity = function (data) {
    var skeleton = null; // no dedicated longevity skeleton
    var content  = document.getElementById('sor-players-longevity');
    function showContainer() {
      if (skeleton) skeleton.style.display = 'none';
      if (content)  content.style.display  = '';
    }
    if (!data || !data.longevity) { return; }
    var buckets = data.longevity;
    var total   = 0;
    buckets.forEach(function (b) { total += (b.count || 0); });
    if (total === 0) { return; }

    // Build pie series data
    var pieData = buckets
      .filter(function (b) { return b.count > 0; })
      .map(function (b, i) {
        return {
          name:  b.label,
          y:     b.count,
          color: LON_COLORS[i % LON_COLORS.length]
        };
      });

    // Build legend HTML
    var legendHtml = '';
    pieData.forEach(function (d) {
      var pct = total > 0 ? ((d.y / total) * 100).toFixed(1) : '0';
      legendHtml +=
        '<div class="sor-lon-legend-item">' +
          '<div class="sor-lon-swatch" style="background:' + d.color + '"></div>' +
          '<span class="sor-lon-label">' + d.name + '</span>' +
          '<span class="sor-lon-count">' + d.y.toLocaleString('en-US') + '</span>' +
          '<span class="sor-lon-pct">&nbsp;' + pct + '%</span>' +
        '</div>';
    });
    var legendEl = document.getElementById('sor-longevity-legend');
    if (legendEl) legendEl.innerHTML = legendHtml;

    // Show container BEFORE Highcharts renders so it can measure dimensions (error #13)
    // Ensure the parent players-content is visible before showing longevity
    var playersContent = document.getElementById('sor-players-content');
    if (playersContent) playersContent.style.display = '';
    showContainer();

    // Draw pie chart (Highcharts v3 API)
    if (typeof Highcharts !== 'undefined') {
      window.sorDestroyChart('sor-longevity-chart');
      new Highcharts.Chart({
        chart: {
          renderTo: 'sor-longevity-chart',
          type: 'pie',
          height: 320,
          backgroundColor: 'transparent',
          style: { fontFamily: 'inherit' }
        },
        title:   { text: null },
        credits: { enabled: false },
        legend:  { enabled: false },
        tooltip: {
          backgroundColor: '#1a1a2e',
          borderColor:     '#2980b9',
          borderRadius:    6,
          style:           { color: '#fff', fontSize: '12px' },
          formatter: function () {
            var pct = total > 0 ? ((this.y / total) * 100).toFixed(1) : '0';
            return '<b>' + this.point.name + '</b><br/>' +
                   this.y.toLocaleString('en-US') + ' players (' + pct + '%)';
          }
        },
        plotOptions: {
          pie: {
            allowPointSelect: true,
            cursor: 'pointer',
            innerSize: '40%',
            dataLabels: {
              enabled: true,
              distance: 16,
              formatter: function () {
                var pct = total > 0 ? ((this.y / total) * 100).toFixed(1) : '0';
                return '<b>' + this.point.name + '</b><br/>' + pct + '%';
              },
              style: { fontSize: '11px', fontWeight: '600', textShadow: 'none', color: window.sorThemeColor('--ork-text', '#333') }
            }
          }
        },
        series: [{ name: 'Players', data: pieData }]
      });
    }
  };

}());
</script>


<!-- ============================================================
     ORCHESTRATOR
     ============================================================ -->
<script>
(function () {
  'use strict';

  // FIX C: Apply a theme-aware Highcharts default that reads ORK3's CSS vars
  // (`--ork-text*`, `--ork-border`) so chart axes/labels/legend render correctly
  // in both light mode and `html[data-theme="dark"]`. Each chart sets
  // `backgroundColor: 'transparent'` so it inherits the surrounding card surface.
  // The report is fully re-generated on click, so reading vars once at setOptions
  // time is sufficient — no live-toggle re-render needed.
  window.sorThemeColor = function (varName, fallback) {
    try {
      var v = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
      return v || fallback;
    } catch (e) { return fallback; }
  };

  // HTML-escape helper. Two sibling IIFEs define their own local sorEsc, but
  // this orchestrator IIFE (where renderSorAwards lives) was missing one —
  // calling `sorEsc(...)` inside the awards renderer threw a ReferenceError.
  function sorEsc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  if (typeof Highcharts !== 'undefined' && !window._sorChartThemeApplied) {
    var _axis   = window.sorThemeColor('--ork-text-secondary', '#4a5568');
    var _label  = window.sorThemeColor('--ork-text-muted',     '#718096');
    var _border = window.sorThemeColor('--ork-border',         '#e2e8f0');
    Highcharts.setOptions({
      chart: { backgroundColor: 'transparent' },
      xAxis: { lineColor: _border, tickColor: _border,
               labels: { style: { color: _label } } },
      yAxis: { gridLineColor: _border,
               labels: { style: { color: _label } } },
      legend: { itemStyle:      { color: _axis },
                itemHoverStyle: { color: window.sorThemeColor('--ork-text', '#2d3748') } }
    });
    window._sorChartThemeApplied = true;
  }

  var SOR_BASE_URL = '<?= UIR ?>AdminAjax/stateofamtgard/';

  // renderSorRetention: new-player conversion & retention KPI panel (no chart).
  window.renderSorRetention = function (data) {
    var content = document.getElementById('sor-players-retention');
    var body    = document.getElementById('sor-retention-body');
    if (!content || !body) return;
    var d = data && data.retention;
    if (!d || !d.headline || !d.headline.cohort) { content.style.display = 'none'; return; }
    var h = d.headline, rj = d.realjoiners || {};
    function pct(v) { return (v === null || v === undefined) ? '—' : (Math.round(v * 10) / 10) + '%'; }
    function num(v) { return (v === null || v === undefined) ? '—' : (Math.round(v * 10) / 10); }
    function n(v)   { return (v || 0).toLocaleString(); }
    function card(value, label) {
      return '<div class="sor-ret-card"><div class="sor-ret-val">' + value + '</div><div class="sor-ret-lbl">' + label + '</div></div>';
    }
    body.innerHTML =
        '<div class="sor-ret-grid">'
      +   card(n(h.cohort),            'new players<br>(mature cohort)')
      +   card(pct(h.pct_one_and_done),'one &amp; done<br>(never returned)')
      +   card(pct(h.pct_realjoin),    'became real joiners<br>(4+ sign-ins)')
      +   card(num(h.median_signins),  'median sign-ins<br>(per player)')
      + '</div>'
      + '<div class="sor-ret-sub">Among real joiners (' + n(rj.n) + '):</div>'
      + '<div class="sor-ret-grid">'
      +   card(pct(rj.active_1yr),              'still active<br>at 1 year')
      +   card(pct(rj.active_2yr),              'still active<br>at 2 years')
      +   card(num(rj.median_tenure_months) + ' mo', 'median tenure<br>(first&rarr;last)')
      +   card(num(rj.median_active_months) + ' mo', 'median active<br>months')
      + '</div>';
    var pc = document.getElementById('sor-players-content');
    if (pc) pc.style.display = '';
    content.style.display = '';
  };

  /* ----------------------------------------------------------------
     Build the query string from current filter values
  ---------------------------------------------------------------- */
  function sorBuildQs() {
    var start = document.getElementById('sor-start').value || '';
    var end   = document.getElementById('sor-end').value   || '';
    var qs = 'start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);

    var sel = document.getElementById('sor-kingdoms');
    if (sel) {
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].selected) {
          qs += '&kingdoms[]=' + encodeURIComponent(sel.options[i].value);
        }
      }
    }
    return qs;
  }

  /* ----------------------------------------------------------------
     Status helpers
  ---------------------------------------------------------------- */
  var _sorDone     = 0;
  var _sorTotal    = 0; // FIX F: self-incremented in sorFetch, reset to 0 in sorGenerate
  var _sorTimeoutHandle = null; // FIX G: cancelable timeout for hung fetches
  window._sorPayloads = {}; var _sorPayloads = window._sorPayloads;

  function sorSetStatus(msg, cls) {
    var el = document.getElementById('sor-status');
    if (!el) return;
    el.textContent = msg;
    el.className = 'sor-status' + (cls ? ' ' + cls : '');
  }

  function sorSectionDone(section) {
    _sorDone++;
    sorSetStatus('Loading… ' + _sorDone + '/' + _sorTotal + ' complete');
    if (_sorDone >= _sorTotal) {
      // FIX G: report finished naturally — cancel the timeout fallback
      if (_sorTimeoutHandle) { clearTimeout(_sorTimeoutHandle); _sorTimeoutHandle = null; }
      sorSetStatus('Report complete.', 'sor-status-ok');
      var btn = document.getElementById('sor-generate-btn');
      if (btn) btn.disabled = false;
      var pbtn = document.getElementById('sor-print-btn');
      if (pbtn) pbtn.disabled = false; // report is ready → allow Print / Save as PDF
      sorRenderScorecard();
    }
  }

  /* ----------------------------------------------------------------
     Fetch one section
  ---------------------------------------------------------------- */
  function sorFetch(section, qs, renderFn) {
    // _sorTotal is set up-front from the job list in sorGenerate (the pool calls
    // sorFetch gradually, so self-incrementing here made the "x/N" denominator grow).
    // UIR resolves to `.../index.php?Route=` (query-param routing), so the
    // section already sits inside the Route value and additional params must
    // be appended with `&`, not a fresh `?` — using `?` here puts a literal
    // `?` inside the Route value, the section won't match, and $_GET['start']
    // never populates.
    return fetch(SOR_BASE_URL + section + '&' + qs, {credentials: 'same-origin'})
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        _sorPayloads[section] = data;
        renderFn(data);
        sorSectionDone(section);
      })
      .catch(function (err) {
        sorSetStatus('Error loading ' + section + ': ' + err.message, 'sor-status-error');
        // Hide skeleton for sections that have one, so they don't hang
        var skMap = { parks: 'sor-parks-skeleton', players: 'sor-players-skeleton',
                      kingdoms: 'sor-kingdoms-skeleton', classes: 'sor-classes-skeleton',
                      longevity: null, cohorts: null, retention: null };
        var sk = skMap[section] && document.getElementById(skMap[section]);
        if (sk) sk.style.display = 'none';
        sorSectionDone(section);
      });
  }

  /* ----------------------------------------------------------------
     Main generate function — exposed globally for inline onclick
  ---------------------------------------------------------------- */
  window.sorGenerate = function () {
    var start = document.getElementById('sor-start').value;
    var end   = document.getElementById('sor-end').value;

    if (!start || !end) {
      sorSetStatus('Please select start and end dates.', 'sor-status-error');
      return;
    }
    if (start > end) {
      sorSetStatus('Start date must be before end date.', 'sor-status-error');
      return;
    }

    // Mirror the server's 12-month cap (production only). Computed via calendar-month
    // math so it matches PHP's strtotime('+12 months'); 0 = unlimited (local/dev).
    var limitMonths = (typeof window.SOR_RANGE_LIMIT_MONTHS === 'number') ? window.SOR_RANGE_LIMIT_MONTHS : 0;
    if (limitMonths > 0) {
      var _sd = new Date(start + 'T00:00:00');
      var _maxEnd = new Date(_sd.getFullYear(), _sd.getMonth() + limitMonths, _sd.getDate());
      if (new Date(end + 'T00:00:00') > _maxEnd) {
        sorSetStatus('The reporting window cannot exceed ' + limitMonths + ' months. Please narrow the date range.', 'sor-status-error');
        return;
      }
    }

    // Check at least one kingdom selected
    var sel = document.getElementById('sor-kingdoms');
    var anySelected = false;
    if (sel) {
      for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].selected) { anySelected = true; break; }
      }
    }
    if (!anySelected) {
      sorSetStatus('Please select at least one kingdom.', 'sor-status-error');
      return;
    }

    // Reset counters and show report container
    _sorDone = 0;
    _sorTotal = 0; // reset; set to the job count once the pool is built (below)
    if (_sorTimeoutHandle) { clearTimeout(_sorTimeoutHandle); _sorTimeoutHandle = null; } // FIX G
    window._sorPayloads = {}; _sorPayloads = window._sorPayloads;
    var scEl = document.getElementById('sor-scorecard');
    if (scEl) scEl.style.display = 'none';
    var btn = document.getElementById('sor-generate-btn');
    if (btn) btn.disabled = true;
    var pbtn = document.getElementById('sor-print-btn');
    if (pbtn) pbtn.disabled = true; // re-disable until this run completes
    sorSetStatus('Generating report…');

    // Show report area (sections start in skeleton state)
    document.getElementById('sor-report').style.display = '';

    // Ensure all skeleton loaders are visible and content is hidden
    // T-7: Use explicit IDs instead of attribute selector (avoids matching unrelated skeletons)
    var sorSkeletonIds = ['sor-players-skeleton','sor-kingdoms-skeleton','sor-classes-skeleton','sor-parks-skeleton','sor-awards-skeleton'];
    for (var k = 0; k < sorSkeletonIds.length; k++) { var el = document.getElementById(sorSkeletonIds[k]); if(el) el.style.display = ''; }
    var contents = ['sor-players-content','sor-kingdoms-content','sor-classes-content','sor-parks-content','sor-awards-content'];
    for (var c = 0; c < contents.length; c++) {
      var el = document.getElementById(contents[c]);
      if (el) el.style.display = 'none';
    }

    var qs = sorBuildQs();

    // T-2: Update period subtitle in kingdoms section
    var periodEl = document.getElementById('sor-kingdoms-period');
    if (periodEl) {
      var kStart = document.getElementById('sor-start').value;
      var kEnd   = document.getElementById('sor-end').value;
      periodEl.textContent = kStart + ' — ' + kEnd;
    }

    // Populate print header with report parameters
    var printMeta = document.getElementById('sor-print-meta');
    if (printMeta) {
      var selKd = [];
      if (sel) {
        for (var pi = 0; pi < sel.options.length; pi++) {
          if (sel.options[pi].selected) selKd.push(sel.options[pi].text);
        }
      }
      var kdLabel = (sel && selKd.length === sel.options.length)
        ? 'All Kingdoms'
        : selKd.slice(0, 5).join(', ') + (selKd.length > 5 ? ' (+' + (selKd.length - 5) + ' more)' : '');
      printMeta.textContent = 'Period: ' + start + '—' + end + '  •  Kingdoms: ' + kdLabel;
    }

    // Fire 6 parallel requests
    // Run sections through a small concurrency pool instead of all-at-once.
    // Firing 8 heavy aggregations in parallel saturated the DB on big
    // (all-time / all-kingdom) ranges, so individually-OK queries blew past the
    // timeout. Cap = 3, with heavy sections (classes/parks/players) interleaved
    // among light ones so at most one heavy query runs at a time.
    var SOR_MAX_CONCURRENCY = 3;
    var _sorJobs = [
      ['classes',   window.renderSorClasses],   // heavy
      ['kingdoms',  window.renderSorKingdoms],
      ['cohorts',   window.renderSorCohorts],
      ['parks',     window.renderSorParks],      // heavy
      ['longevity', window.renderSorLongevity],
      ['retention', window.renderSorRetention],
      ['players',   window.renderSorPlayers],    // heavy
      ['awards',    window.renderSorAwards]
    ];
    _sorTotal = _sorJobs.length; // fixed denominator so the "x/N" counter doesn't grow as the pool fires
    var _sorJobIdx = 0;
    function _sorRunNext() {
      if (_sorJobIdx >= _sorJobs.length) return;
      var job = _sorJobs[_sorJobIdx++];
      // sorFetch's .catch resolves, so the promise always settles → queue advances.
      sorFetch(job[0], qs, job[1]).then(_sorRunNext, _sorRunNext);
    }
    for (var _sc = 0; _sc < SOR_MAX_CONCURRENCY && _sc < _sorJobs.length; _sc++) _sorRunNext();

    // FIX G: timeout fallback. If any fetch hangs, force-finish the report with
    // partial data and re-enable the Generate button so the user isn't stuck.
    // Raised to 120s: with the concurrency pool, a full all-time / all-kingdom
    // run is serialized enough to legitimately take ~40-60s on a cold cache.
    _sorTimeoutHandle = setTimeout(function () {
      _sorTimeoutHandle = null;
      if (_sorDone >= _sorTotal) return; // already finished
      sorSetStatus('Some sections timed out — partial data shown.', 'sor-status-error');
      var btn = document.getElementById('sor-generate-btn');
      if (btn) btn.disabled = false;
      var pbtn = document.getElementById('sor-print-btn');
      if (pbtn) pbtn.disabled = false; // allow printing partial results
      try { sorRenderScorecard(); } catch (e) { /* swallow */ }
    }, 120000);
  };

  /* ----------------------------------------------------------------
     Executive Scorecard
  ---------------------------------------------------------------- */
  function sorRenderScorecard() {
    var pData  = _sorPayloads['players'] && _sorPayloads['players'].players;
    var pd     = _sorPayloads['parks']   && _sorPayloads['parks'].parks;
    var co     = _sorPayloads['cohorts'] && _sorPayloads['cohorts'].cohorts;
    var el     = document.getElementById('sor-scorecard');
    if (!el || !pData) return;
    var np       = pData.normal_players || {};
    var eng      = pData.total_players > 0
                   ? ((np.count / pData.total_players) * 100).toFixed(1) : '0.0';
    var netP     = pd ? ((pd.new_parks_count || 0) - (pd.lost_parks_count || 0)) : null;
    var atR      = pd ? (pd.downward_trend_parks || []).length : null;
    function fK(n) {
      // Full comma-separated counts (no "k" abbreviation) — clearer for an exec summary.
      return (parseInt(n, 10) || 0).toLocaleString('en-US');
    }
    function kCard(lbl, val, acc, icon, sub, tip) {
      return '<div class="sor-kpi-card sor-tip-card"' + (tip ? ' data-tip="' + tip + '"' : '') + ' style="border-top-color:' + acc + '">' +
             '<span class="sor-kpi-icon">' + icon + '</span>' +
             '<span class="sor-kpi-value" style="color:' + acc + '">' + val + '</span>' +
             '<span class="sor-kpi-label">' + lbl + '</span>' +
             (sub ? '<span class="sor-kpi-sub">' + sub + '</span>' : '') +
             '</div>';
    }
    var engAcc = parseFloat(eng) >= 40 ? '#27ae60' : (parseFloat(eng) >= 25 ? '#e67e22' : '#c0392b');
    var html =
      kCard('Total Sign-Ins',   fK(pData.total_sign_ins), '#2980b9', '&#9997;',  null,
        'Total attendance records in the period. Each park event check-in = one sign-in.') +
      kCard('Active Players',   fK(pData.total_players),  '#2980b9', '&#128101;',null,
        'Unique players with at least one sign-in during the selected date range.') +
      kCard('Normal Players',   fK(np.count),             '#c0392b', '&#11088;', null,
        'Players with 4+ sign-ins AND 12+ total credits. Filters casual visitors to identify committed members.') +
      kCard('Engagement Rate',  eng + '%',                engAcc,    '&#128200;','normal / all',
        'Normal Players / Active Players. What fraction of active players meet the committed-member threshold.') +
      (pd ? kCard('Active Parks', pd.total_active || 0, '#2c5f6e', '&#127957;', null,
        'Parks with Active status in the selected kingdoms.') : '') +
      (pd && netP !== null ? kCard('Net Park Change', (netP >= 0 ? '+' : '') + netP,
            netP >= 0 ? '#27ae60' : '#c0392b', netP >= 0 ? '&#9650;' : '&#9660;', 'new minus lost',
        'New parks founded minus parks retired during the period.') : '') +
      (pd && atR !== null ? kCard('At-Risk Parks', atR,
            atR === 0 ? '#27ae60' : (atR > 5 ? '#c0392b' : '#e67e22'), '&#9888;&#65039;', 'downward trend',
        'Parks with declining sign-in trend (Spearman r < -0.5 over 5 yrs) AND fewer than 20 sign-ins last year.') : '');
    el.innerHTML = html;
    el.style.display = 'flex';
    if (typeof sorDrawFunnelChart === 'function') sorDrawFunnelChart();
  }


  /* ----------------------------------------------------------------
     Award Grants — render function (called by sorFetch('awards'))
  ---------------------------------------------------------------- */
  // Overall split — matches trend chart palette so Knight/Paragon/Master
  // hold the same color across charts.
  var SOR_AWARDS_OVERALL_COLORS = ['#c0392b', '#2980b9', '#7d3c98'];

  var SOR_AWARDS_KNIGHT_COLORS = [
    '#c0392b', '#d4a017', '#27ae60', '#2980b9', '#7d3c98'
  ];
  var SOR_AWARDS_MASTER_COLORS = [
    '#c0392b', '#e67e22', '#d4a017', '#27ae60', '#16a085',
    '#2980b9', '#5d3fd3', '#8e44ad', '#7f8c8d'
  ];
  var SOR_AWARDS_PARAGON_COLORS = [
    '#c0392b', '#d35400', '#e67e22', '#f39c12', '#d4a017',
    '#a3b117', '#27ae60', '#16a085', '#1abc9c', '#2980b9',
    '#3498db', '#5d3fd3', '#7d3c98', '#8e44ad', '#c44569',
    '#922b21', '#4a5568'
  ];

  function sorAwardsFmtDate(iso) {
    if (!iso) return '';
    /* Accepts "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" */
    var s = String(iso).slice(0, 10);
    var parts = s.split('-');
    if (parts.length !== 3) return sorEsc(iso);
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10);
    var d = parseInt(parts[2], 10);
    if (!y || !m || !d) return sorEsc(iso);
    var months = ['Jan','Feb','Mar','Apr','May','Jun',
                  'Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[m - 1] + ' ' + d + ', ' + y;
  }

  function sorAwardsPeeragePill(tier) {
    var t = String(tier || '').toLowerCase();
    var cls = 'sor-awards-peerage-master';
    if (t.indexOf('knight') === 0)  cls = 'sor-awards-peerage-knight';
    else if (t.indexOf('paragon') === 0) cls = 'sor-awards-peerage-paragon';
    return '<span class="sor-awards-peerage-pill ' + cls + '">' + sorEsc(tier || '') + '</span>';
  }

  function sorAwardsRenderKpis(totals) {
    var k = parseInt(totals && totals.knights,  10) || 0;
    var p = parseInt(totals && totals.paragons, 10) || 0;
    var m = parseInt(totals && totals.masters,  10) || 0;
    function kpi(label, value, accent, icon) {
      return '<div class="sor-kpi-card" style="border-top-color:' + accent + '">' +
               '<span class="sor-kpi-icon">' + icon + '</span>' +
               '<span class="sor-kpi-value" style="color:' + accent + '">' + value.toLocaleString('en-US') + '</span>' +
               '<span class="sor-kpi-label">' + label + '</span>' +
             '</div>';
    }
    document.getElementById('sor-awards-kpi-row').innerHTML =
      kpi('Total Knights Granted',  k, '#c0392b', '&#9876;') +
      kpi('Total Paragons Granted', p, '#7d3c98', '&#127942;') +
      kpi('Total Masters Granted',  m, '#2980b9', '&#127891;');

    var ks = document.getElementById('sor-awards-knights-sub');
    var ps = document.getElementById('sor-awards-paragons-sub');
    var ms = document.getElementById('sor-awards-masters-sub');
    if (ks) ks.textContent = k.toLocaleString('en-US') + ' total';
    if (ps) ps.textContent = p.toLocaleString('en-US') + ' total';
    if (ms) ms.textContent = m.toLocaleString('en-US') + ' total';
  }

  /* Strip the peerage prefix so labels stay legible in tightly packed pies
     ("Knight of the Sword" → "Sword", "Paragon Wizard" → "Wizard",
     "Master Rose" → "Rose"). Names without a known prefix (Battlemaster,
     Warlord) are returned unchanged. */
  function sorAwardsShortName(name) {
    return String(name || '').replace(/^(Knight of the |Knight of |Master |Paragon )/i, '');
  }

  function sorAwardsDrawPie(containerId, rows, palette, seriesName) {
    if (typeof Highcharts === 'undefined') return;
    window.sorDestroyChart(containerId);

    /* Drop zero-count slices for clarity. Strip peerage prefix so labels fit. */
    var data = (rows || [])
      .filter(function (r) { return parseInt(r.count, 10) > 0; })
      .map(function (r) {
        var full = String(r.name || '');
        return {
          name:     sorAwardsShortName(full),
          fullName: full,
          y:        parseInt(r.count, 10) || 0
        };
      });

    var container = document.getElementById(containerId);
    if (!container) return;
    if (!data.length) {
      container.innerHTML =
        '<p style="color:#999;padding:40px 12px;text-align:center;font-size:0.85rem">' +
          'No grants in this period.' +
        '</p>';
      return;
    }

    var textColor      = window.sorThemeColor('--ork-text',           '#2d3748');
    var textSecondary  = window.sorThemeColor('--ork-text-secondary', '#4a5568');

    new Highcharts.Chart({
      chart: {
        renderTo:        containerId,
        type:            'pie',
        backgroundColor: 'transparent',
        style:           { fontFamily: 'inherit' },
        animation:       { duration: 550 },
        // Reserve horizontal room so connector legs + labels stay inside the
        // card (otherwise labels like "Battlemaster: 57" get cropped).
        marginLeft:      95,
        marginRight:     95,
        spacingTop:      10,
        spacingBottom:   10
      },
      title:    { text: null },
      subtitle: { text: null },
      credits:  { enabled: false },
      exporting: { enabled: false },
      colors:    palette,

      tooltip: {
        useHTML:         false,
        backgroundColor: 'rgba(26,26,26,0.88)',
        style:           { color: '#fff' },
        borderWidth:     0,
        shadow:          false,
        formatter: function () {
          return '<b>' + (this.point.fullName || this.point.name) + '</b><br/>' +
                 this.y.toLocaleString('en-US') + ' (' +
                 this.percentage.toFixed(1) + '%)';
        }
      },

      plotOptions: {
        pie: {
          allowPointSelect: false,
          cursor:           'pointer',
          borderWidth:      1,
          borderColor:      window.sorThemeColor('--ork-card-bg', '#ffffff'),
          // Reasonable pie size — cards are wider in the 2×2 layout so we
          // can afford a larger pie and still keep the gutters clean.
          size:             '72%',
          dataLabels: {
            enabled:        true,
            useHTML:        false,
            distance:       22,
            connectorWidth: 1,
            connectorPadding: 6,
            softConnector:  true,
            // Allow labels to render even if they would otherwise be clipped
            // — Highcharts auto-vertical-stacks them via softConnector.
            crop:           false,
            overflow:       'allow',
            // Align the label horizontally to the chart's left/right edge so
            // every label on a side starts at the same x — this prevents the
            // staggered cutoff seen with the default radial placement.
            alignTo:        'connectors',
            formatter: function () {
              if (this.percentage < 2) return null;
              return this.point.name + ': ' + this.y;
            },
            style: {
              fontSize:   '13px',
              fontWeight: '600',
              color:      textSecondary,
              textShadow: 'none'
            }
          },
          showInLegend: false
        }
      },

      series: [{
        name: seriesName,
        data: data
      }]
    });
  }

  function sorAwardsDrawTrend(trend) {
    var card      = document.getElementById('sor-awards-trend-card');
    var container = document.getElementById('sor-awards-trend-chart');
    if (!card || !container) return;

    // Hide if missing, empty, or entirely zero
    var hasData = Array.isArray(trend) && trend.length > 0;
    if (hasData) {
      var anyNonZero = false;
      for (var i = 0; i < trend.length; i++) {
        var t = trend[i] || {};
        if ((parseInt(t.knights, 10) || 0) +
            (parseInt(t.masters, 10) || 0) +
            (parseInt(t.paragons, 10) || 0) > 0) {
          anyNonZero = true;
          break;
        }
      }
      hasData = anyNonZero;
    }
    if (!hasData) {
      card.style.display = 'none';
      return;
    }
    card.style.display = '';

    if (typeof Highcharts === 'undefined') return;

    var years    = [];
    var knights  = [];
    var masters  = [];
    var paragons = [];
    for (var j = 0; j < trend.length; j++) {
      var row = trend[j] || {};
      years.push(parseInt(row.year, 10) || 0);
      knights.push(parseInt(row.knights, 10) || 0);
      masters.push(parseInt(row.masters, 10) || 0);
      paragons.push(parseInt(row.paragons, 10) || 0);
    }
    var startYear = years[0];
    var endYear   = years[years.length - 1];

    var textColor      = window.sorThemeColor('--ork-text',           '#2d3748');
    var textSecondary  = window.sorThemeColor('--ork-text-secondary', '#4a5568');
    var textMuted      = window.sorThemeColor('--ork-text-muted',     '#718096');
    var borderColor    = window.sorThemeColor('--ork-border',         '#e2e8f0');

    window.sorDestroyChart('sor-awards-trend-chart');
    new Highcharts.Chart({
      chart: {
        renderTo:        'sor-awards-trend-chart',
        type:            'spline',
        backgroundColor: 'transparent',
        style:           { fontFamily: 'inherit' },
        animation:       { duration: 600 },
        spacingTop:      8,
        spacingBottom:   4
      },
      title: {
        text: '10-Year Peerage Grant Trends',
        style: { fontSize: '0.95rem', fontWeight: '700', color: textColor }
      },
      subtitle: {
        text: startYear + '–' + endYear,
        style: { fontSize: '0.78rem', color: textMuted, letterSpacing: '0.02em' }
      },
      credits:   { enabled: false },
      exporting: { enabled: false },

      xAxis: {
        categories: years.map(function (y) { return String(y); }),
        labels: {
          style: { fontSize: '11px', color: textSecondary }
        },
        lineColor: borderColor,
        tickColor: borderColor
      },
      yAxis: {
        min: 0,
        allowDecimals: false,
        title: {
          text: 'Grants',
          style: { fontSize: '11px', color: textMuted }
        },
        labels: {
          style: { fontSize: '11px', color: textMuted }
        },
        gridLineColor: borderColor
      },

      legend: {
        align: 'center',
        verticalAlign: 'bottom',
        layout: 'horizontal',
        itemStyle: { fontSize: '12px', fontWeight: '600', color: textSecondary },
        itemHoverStyle: { color: textColor }
      },

      tooltip: {
        shared: true,
        useHTML: false,
        backgroundColor: 'rgba(26,26,26,0.88)',
        style:           { color: '#fff', fontSize: '12px' },
        borderWidth:     0,
        shadow:          false,
        headerFormat: '<b>Year {point.key}</b><br/>',
        pointFormat:  '{series.name}: <b>{point.y}</b><br/>'
      },

      plotOptions: {
        series: {
          marker: { enabled: true, radius: 3, symbol: 'circle' },
          lineWidth: 2,
          states: { hover: { lineWidth: 3 } }
        }
      },

      series: [
        { name: 'Knights',  data: knights,  color: '#c0392b' },
        { name: 'Masters',  data: masters,  color: '#7d3c98' },
        { name: 'Paragons', data: paragons, color: '#2980b9' }
      ]
    });
  }

  function sorAwardsRenderKingdomsTable(rows) {
    var tbody = document.getElementById('sor-awards-kingdoms-tbody');
    if (!tbody) return;
    rows = rows || [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#999;padding:18px">No peerage grants in this period.</td></tr>';
      return;
    }
    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      html += '<tr>' +
                '<td>' + sorEsc(r.kingdom_name || '') + '</td>' +
                '<td style="text-align:right">' + (parseInt(r.knights,  10) || 0).toLocaleString('en-US') + '</td>' +
                '<td style="text-align:right">' + (parseInt(r.paragons, 10) || 0).toLocaleString('en-US') + '</td>' +
                '<td style="text-align:right">' + (parseInt(r.masters,  10) || 0).toLocaleString('en-US') + '</td>' +
                '<td style="text-align:right"><strong>' + (parseInt(r.total, 10) || 0).toLocaleString('en-US') + '</strong></td>' +
              '</tr>';
    }
    tbody.innerHTML = html;
  }

  window.renderSorAwards = function (data) {
    var skeleton = document.getElementById('sor-awards-skeleton');
    var content  = document.getElementById('sor-awards-content');
    function showError(msg) {
      if (skeleton) skeleton.style.display = 'none';
      if (content)  content.style.display  = '';
      var row = document.getElementById('sor-awards-kpi-row');
      if (row) row.innerHTML = '<p style="color:#c0392b;padding:20px">' + msg + '</p>';
    }
    if (!data || !data.awards) { showError('Error loading awards data.'); return; }
    var a = data.awards;

    /* Show content before charts so Highcharts can measure container dimensions */
    if (skeleton) skeleton.style.display = 'none';
    if (content)  content.style.display  = '';

    try {
      sorAwardsRenderKpis(a.totals || {});
      sorAwardsDrawTrend(a.ten_year_trend || []);
      /* Synthesize an overall-split "pie rows" payload from the totals. */
      var t = a.totals || {};
      var overallRows = [
        { name: 'Knights',  count: parseInt(t.knights,  10) || 0 },
        { name: 'Paragons', count: parseInt(t.paragons, 10) || 0 },
        { name: 'Masters',  count: parseInt(t.masters,  10) || 0 }
      ];
      var overallTotal = overallRows.reduce(function (s, r) { return s + r.count; }, 0);
      var overallSub = document.getElementById('sor-awards-overall-sub');
      if (overallSub) overallSub.textContent = overallTotal.toLocaleString('en-US') + ' total';
      sorAwardsDrawPie('sor-awards-overall-chart',  overallRows,        SOR_AWARDS_OVERALL_COLORS, 'Peerage');
      sorAwardsDrawPie('sor-awards-knights-chart',  a.knights  || [], SOR_AWARDS_KNIGHT_COLORS,  'Knights');
      sorAwardsDrawPie('sor-awards-paragons-chart', a.paragons || [], SOR_AWARDS_PARAGON_COLORS, 'Paragons');
      sorAwardsDrawPie('sor-awards-masters-chart',  a.masters  || [], SOR_AWARDS_MASTER_COLORS,  'Masters');
      sorAwardsRenderKingdomsTable(a.top_kingdoms || []);
    } catch (e) {
      showError('Awards render error: ' + (e && e.message ? e.message : String(e)));
      return;
    }
  };


  // Kingdom multi-select handlers (sorSelectAll / sorClearAll /
  // sorSelectAllExceptFreeholds) live in the standalone <script> block
  // right after the filter card, so they survive errors in this IIFE.

}());
</script>
