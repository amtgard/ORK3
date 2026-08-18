<?php
/* ─────────────────────────────────────────────────────────────────────────
   Release Feature Utilization — exec-facing adoption dashboard.
   Renders generically from the $Report contract (see Report::ReleaseFeatureUtilization()).
   Dark mode is driven by the shared --rp-* variables in reports.css plus the
   Highcharts dark-detection pattern copied from Admin_newplayerattendance.tpl.
   ───────────────────────────────────────────────────────────────────────── */

$Report   = (isset($Report) && is_array($Report)) ? $Report : [];
$releases = (isset($Report['releases']) && is_array($Report['releases'])) ? $Report['releases'] : [];
$totals   = (isset($Report['totals'])   && is_array($Report['totals']))   ? $Report['totals']   : [];
$genAt    = $Report['generated_at'] ?? date('Y-m-d H:i:s');

/* Collect every chart spec so we can emit one JS init block at the end. */
$_rfu_charts = [];

/* Friendly date formatter for release dates ("2026-05-13" -> "May 13, 2026"). */
$rfu_fmt_date = static function ($d) {
	if (empty($d)) {
		return '';
	}
	$ts = strtotime($d);
	return $ts ? date('M j, Y', $ts) : htmlspecialchars($d);
};
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
/* ── Layout shell ── */
.rfu-shell { display:flex; gap:22px; align-items:flex-start; }
.rfu-nav   { position:sticky; top:14px; flex:0 0 218px; align-self:flex-start; }
.rfu-main  { flex:1 1 auto; min-width:0; }

/* ── Jump nav ── */
.rfu-nav-card {
	background: var(--rp-bg-table, #fff);
	border: 1px solid var(--rp-border);
	border-radius: 10px;
	overflow: hidden;
}
.rfu-nav-head {
	font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
	color: var(--rp-text-muted);
	padding: 11px 14px 9px;
	border-bottom: 1px solid var(--rp-border);
	background: var(--rp-bg-light);
}
.rfu-nav-link {
	display: block; text-decoration: none;
	padding: 10px 14px;
	border-left: 3px solid transparent;
	color: var(--rp-text-body);
	transition: background .12s, border-color .12s, color .12s;
}
.rfu-nav-link + .rfu-nav-link { border-top: 1px solid var(--rp-border); }
.rfu-nav-link:hover { background: var(--rp-bg-light); border-left-color: var(--rp-accent-mid); }
.rfu-nav-link.is-active { background: var(--rp-bg-light); border-left-color: var(--rp-accent); }
.rfu-nav-ver  { font-weight: 800; font-size: 12.5px; color: var(--rp-accent); letter-spacing: .02em; }
.rfu-nav-name { font-size: 13px; font-weight: 700; color: var(--rp-text); }
.rfu-nav-date { font-size: 11px; color: var(--rp-text-muted); margin-top: 1px; }

/* ── Totals strip ── */
.rfu-totals {
	display: flex; flex-wrap: wrap; gap: 0;
	border: 1px solid var(--rp-border);
	border-radius: 10px;
	background: var(--rp-bg-table, #fff);
	overflow: hidden;
	margin-bottom: 22px;
}
.rfu-total {
	flex: 1 1 0; min-width: 150px;
	padding: 14px 18px;
	border-right: 1px solid var(--rp-border);
}
.rfu-total:last-child { border-right: none; }
.rfu-total-num   { font-size: 26px; font-weight: 800; color: var(--rp-text); line-height: 1.1; letter-spacing: -.01em; }
.rfu-total-label { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--rp-text-muted); margin-top: 3px; }

/* ── Release section ── */
.rfu-release { margin-bottom: 30px; scroll-margin-top: 14px; }
.rfu-release-head {
	display: flex; align-items: baseline; flex-wrap: wrap; gap: 10px;
	padding-bottom: 10px; margin-bottom: 16px;
	border-bottom: 2px solid var(--rp-border);
}
.rfu-pill {
	display: inline-flex; align-items: center; gap: 6px;
	background: var(--rp-accent); color: #fff;
	font-weight: 800; font-size: 13px; letter-spacing: .02em;
	padding: 4px 11px; border-radius: 999px;
	/* defeat orkui.css h1-h6 gray box */
	border: none; text-shadow: none;
}
.rfu-release-name {
	font-size: 20px; font-weight: 800; color: var(--rp-text); margin: 0;
	/* defeat orkui.css h1-h6 gray box */
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.rfu-release-date { font-size: 12.5px; color: var(--rp-text-muted); font-weight: 600; }
.rfu-release-blurb { width: 100%; font-size: 13.5px; color: var(--rp-text-body); line-height: 1.5; margin-top: 2px; }

/* ── Feature card ── */
.rfu-card {
	background: var(--rp-bg-table, #fff);
	border: 1px solid var(--rp-border);
	border-radius: 12px;
	padding: 18px 20px 20px;
	margin-bottom: 16px;
}
.rfu-card-title {
	font-size: 15.5px; font-weight: 800; color: var(--rp-text); margin: 0 0 2px;
	/* defeat orkui.css h1-h6 gray box */
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.rfu-card-desc { font-size: 13px; color: var(--rp-text-muted); line-height: 1.5; margin: 0 0 16px; }

/* ── KPI tiles ── */
.rfu-kpis {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
	gap: 12px;
	margin-bottom: 8px;
}
.rfu-kpi {
	position: relative;
	background: var(--rp-bg-light);
	border: 1px solid var(--rp-border);
	border-radius: 10px;
	padding: 13px 14px 14px;
}
.rfu-kpi-value { font-size: 27px; font-weight: 800; color: var(--rp-text); line-height: 1.05; letter-spacing: -.01em; }
.rfu-kpi-label {
	display: flex; align-items: center; gap: 5px;
	font-size: 12px; font-weight: 600; color: var(--rp-text-body); margin-top: 4px; line-height: 1.35;
}
.rfu-kpi-pct { font-size: 11.5px; font-weight: 700; color: var(--rp-accent); margin-top: 9px; }
.rfu-bar { height: 5px; border-radius: 999px; background: var(--rp-border); margin-top: 5px; overflow: hidden; }
.rfu-bar-fill { height: 100%; border-radius: 999px; background: var(--rp-accent); }

/* ── Before/after delta pill (themed for light + dark) ── */
.rfu-delta {
	display: inline-flex; align-items: center; gap: 4px;
	margin-top: 8px;
	padding: 2px 8px; border-radius: 999px;
	font-size: 11.5px; font-weight: 800; letter-spacing: .01em; line-height: 1.4;
	/* defeat any inherited heading styling */
	border: 1px solid transparent; text-shadow: none;
}
.rfu-delta-up   { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
.rfu-delta-down { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
.rfu-delta-flat { background: var(--rp-bg-light); color: var(--rp-text-muted); border-color: var(--rp-border); }
[data-theme="dark"] .rfu-delta-up   { background: rgba(16,185,129,.16);  color: #6ee7b7; border-color: rgba(16,185,129,.35); }
[data-theme="dark"] .rfu-delta-down { background: rgba(239,68,68,.16);   color: #fca5a5; border-color: rgba(239,68,68,.35); }
[data-theme="dark"] .rfu-delta-flat { background: rgba(148,163,184,.14); color: #cbd5e0; border-color: rgba(148,163,184,.3); }
@media (prefers-color-scheme: dark) {
	:root:not([data-theme="light"]) .rfu-delta-up   { background: rgba(16,185,129,.16);  color: #6ee7b7; border-color: rgba(16,185,129,.35); }
	:root:not([data-theme="light"]) .rfu-delta-down { background: rgba(239,68,68,.16);   color: #fca5a5; border-color: rgba(239,68,68,.35); }
	:root:not([data-theme="light"]) .rfu-delta-flat { background: rgba(148,163,184,.14); color: #cbd5e0; border-color: rgba(148,163,184,.3); }
}

/* ── Hint (CSS data-tip, no native title) ── */
.rfu-hint {
	display: inline-flex; align-items: center; justify-content: center;
	width: 14px; height: 14px; flex: 0 0 14px;
	border-radius: 50%; background: var(--rp-border); color: var(--rp-text-muted);
	font-size: 9px; font-weight: 800; font-style: normal; cursor: help; position: relative;
}
.rfu-hint::after {
	content: attr(data-tip);
	position: absolute; bottom: 150%; left: 50%; transform: translateX(-50%);
	white-space: normal; width: max-content; max-width: 220px;
	background: #1a2035; color: #f1f5f9;
	font-size: 11px; font-weight: 500; line-height: 1.4; text-align: left;
	padding: 7px 9px; border-radius: 6px;
	box-shadow: 0 4px 14px rgba(0,0,0,.35);
	opacity: 0; visibility: hidden; transition: opacity .12s; z-index: 50; pointer-events: none;
}
.rfu-hint:hover::after { opacity: 1; visibility: visible; }

/* ── Charts ── */
.rfu-charts { margin-top: 18px; display: grid; grid-template-columns: 1fr; gap: 18px; }
.rfu-chart-title { font-size: 12.5px; font-weight: 700; color: var(--rp-text-body); margin: 0 0 6px; }
.rfu-chart-empty {
	display: flex; flex-direction: column; align-items: center; justify-content: center;
	height: 200px; gap: 8px;
	border: 1px dashed var(--rp-border); border-radius: 10px;
	color: var(--rp-text-muted); font-size: 13px;
}
.rfu-chart-empty i { font-size: 24px; opacity: .4; }

/* ── Link tiles ── */
.rfu-links {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 12px;
	margin-top: 18px;
}
.rfu-link-tile {
	background: var(--rp-bg-light);
	border: 1px solid var(--rp-border);
	border-radius: 10px;
	padding: 13px 14px 12px;
	min-width: 0;
}
.rfu-link-title {
	font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase;
	color: var(--rp-text-muted);
	margin: 0 0 8px;
	line-height: 1.35;
}
.rfu-link-item { padding: 5px 0; min-width: 0; }
.rfu-link-item + .rfu-link-item { border-top: 1px solid var(--rp-border); }
.rfu-link-a {
	display: inline-flex; align-items: baseline; gap: 6px;
	font-size: 13px; font-weight: 600; line-height: 1.4;
	color: var(--rp-accent); text-decoration: none;
	max-width: 100%;
}
.rfu-link-a:hover { color: var(--rp-accent-mid); text-decoration: underline; }
.rfu-link-a i { font-size: 10px; opacity: .55; flex: 0 0 auto; }
.rfu-link-sub {
	font-size: 11.5px; color: var(--rp-text-muted); line-height: 1.35; margin-top: 1px;
	overflow-wrap: anywhere;
}

.rfu-empty-state { padding: 48px 16px; text-align: center; color: var(--rp-text-muted); font-size: 14px; }
.rfu-empty-state i { font-size: 30px; display: block; margin-bottom: 12px; opacity: .4; }

@media (max-width: 820px) {
	.rfu-shell { flex-direction: column; }
	.rfu-nav { position: static; flex-basis: auto; width: 100%; }
}
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-rocket rp-header-icon"></i>
				<h1 class="rp-header-title">Release Feature Utilization</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip"><i class="fas fa-clock"></i> Generated <?=htmlspecialchars($genAt)?></span>
			</div>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Adoption metrics for features shipped in each ORK release — how widely the org is actually using what we build.</span>
	</div>

<?php if (empty($releases)): ?>

	<div class="rfu-empty-state">
		<i class="fas fa-box-open"></i>
		No release utilization data is available yet.
	</div>

<?php else: ?>

	<!-- Totals strip -->
	<div class="rfu-totals" style="margin-top:20px;">
		<div class="rfu-total">
			<div class="rfu-total-num"><?=number_format((int)($totals['active_players'] ?? 0))?></div>
			<div class="rfu-total-label">Active Players</div>
		</div>
		<div class="rfu-total">
			<div class="rfu-total-num"><?=number_format((int)($totals['players_with_design'] ?? 0))?></div>
			<div class="rfu-total-label">Players With a Design</div>
		</div>
		<div class="rfu-total">
			<div class="rfu-total-num"><?=number_format((int)($totals['active_recommendations'] ?? 0))?></div>
			<div class="rfu-total-label">Active Recommendations</div>
		</div>
	</div>

	<div class="rfu-shell">

		<!-- Jump nav -->
		<nav class="rfu-nav">
			<div class="rfu-nav-card">
				<div class="rfu-nav-head"><i class="fas fa-list-ul"></i> Releases</div>
<?php foreach ($releases as $_ri => $_rel): ?>
<?php $_anchor = 'rfu-rel-' . $_ri; ?>
				<a class="rfu-nav-link<?=$_ri === 0 ? ' is-active' : ''?>" href="#<?=$_anchor?>" data-rfu-target="<?=$_anchor?>">
					<div class="rfu-nav-ver"><?=htmlspecialchars($_rel['version'] ?? '')?> <span class="rfu-nav-name"><?=htmlspecialchars($_rel['name'] ?? '')?></span></div>
					<div class="rfu-nav-date"><?=$rfu_fmt_date($_rel['date'] ?? '')?></div>
				</a>
<?php endforeach; ?>
			</div>
		</nav>

		<!-- Releases -->
		<div class="rfu-main">
<?php foreach ($releases as $_ri => $_rel): ?>
<?php
	$_anchor   = 'rfu-rel-' . $_ri;
	$_features = (isset($_rel['features']) && is_array($_rel['features'])) ? $_rel['features'] : [];
?>
			<section class="rfu-release" id="<?=$_anchor?>">
				<div class="rfu-release-head">
					<span class="rfu-pill"><i class="fas fa-tag"></i> <?=htmlspecialchars($_rel['version'] ?? '')?></span>
					<h2 class="rfu-release-name"><?=htmlspecialchars($_rel['name'] ?? '')?></h2>
<?php if (!empty($_rel['date'])): ?>
					<span class="rfu-release-date"><i class="far fa-calendar-alt"></i> <?=$rfu_fmt_date($_rel['date'])?></span>
<?php endif; ?>
<?php if (!empty($_rel['blurb'])): ?>
					<div class="rfu-release-blurb"><?=htmlspecialchars($_rel['blurb'])?></div>
<?php endif; ?>
				</div>

<?php if (empty($_features)): ?>
				<div class="rfu-card"><div class="rfu-card-desc" style="margin:0;">No features tracked for this release yet.</div></div>
<?php else: ?>
<?php foreach ($_features as $_fi => $_feat): ?>
<?php
	$_kpis   = (isset($_feat['kpis'])   && is_array($_feat['kpis']))   ? $_feat['kpis']   : [];
	$_charts = (isset($_feat['charts']) && is_array($_feat['charts'])) ? $_feat['charts'] : [];
	$_links  = (isset($_feat['links'])  && is_array($_feat['links']))  ? $_feat['links']  : [];
	/* Drop tiles with no items so the grid never renders empty. */
	$_links  = array_values(array_filter($_links, static function ($_l) {
		return is_array($_l) && !empty($_l['items']) && is_array($_l['items']);
	}));
?>
				<div class="rfu-card">
					<h3 class="rfu-card-title"><?=htmlspecialchars($_feat['title'] ?? ($_feat['key'] ?? 'Feature'))?></h3>
<?php if (!empty($_feat['description'])): ?>
					<p class="rfu-card-desc"><?=htmlspecialchars($_feat['description'])?></p>
<?php endif; ?>

<?php if (!empty($_kpis)): ?>
					<div class="rfu-kpis">
<?php foreach ($_kpis as $_kpi): ?>
<?php
	$_val   = $_kpi['value'] ?? 0;
	$_pct   = array_key_exists('pct', $_kpi) ? $_kpi['pct'] : null;
	$_sfx   = (isset($_kpi['suffix']) && $_kpi['suffix'] !== '') ? $_kpi['suffix'] : '';
	$_plbl  = (isset($_kpi['pctLabel']) && $_kpi['pctLabel'] !== '') ? $_kpi['pctLabel'] : 'of active players';
	$_hint  = $_kpi['hint'] ?? null;
	$_delta = (isset($_kpi['delta']) && $_kpi['delta'] !== '') ? $_kpi['delta'] : null;
	$_ddir  = $_kpi['deltaDir'] ?? null;
	$_dcls  = ($_ddir === 'up' || $_ddir === 'down') ? $_ddir : 'flat';
	$_dicon = $_ddir === 'up' ? 'fa-arrow-up' : ($_ddir === 'down' ? 'fa-arrow-down' : 'fa-minus');
	/* Rates/averages opt into a decimal place; counts stay whole. */
	$_vdec  = (int)($_kpi['decimals'] ?? 0);
?>
						<div class="rfu-kpi">
							<div class="rfu-kpi-value"><?=(is_numeric($_val) ? number_format((float)$_val, $_vdec) : htmlspecialchars((string)$_val)) . htmlspecialchars($_sfx)?></div>
							<div class="rfu-kpi-label">
								<span><?=htmlspecialchars($_kpi['label'] ?? '')?></span>
<?php if ($_hint !== null && $_hint !== ''): ?>
								<i class="rfu-hint" data-tip="<?=htmlspecialchars($_hint)?>">i</i>
<?php endif; ?>
							</div>
<?php if ($_pct !== null): ?>
<?php $_pctClamped = max(0, min(100, (float)$_pct)); ?>
							<div class="rfu-kpi-pct"><?=rtrim(rtrim(number_format((float)$_pct, 1), '0'), '.')?>% <?=htmlspecialchars($_plbl)?></div>
							<div class="rfu-bar"><div class="rfu-bar-fill" style="width:<?=$_pctClamped?>%;"></div></div>
<?php endif; ?>
<?php if ($_delta !== null): ?>
							<div class="rfu-delta rfu-delta-<?=$_dcls?>"><i class="fas <?=$_dicon?>"></i><?=htmlspecialchars((string)$_delta)?></div>
<?php endif; ?>
						</div>
<?php endforeach; ?>
					</div>
<?php endif; ?>

<?php if (!empty($_charts)): ?>
					<div class="rfu-charts">
<?php foreach ($_charts as $_chart): ?>
<?php
	$_cid = $_chart['id'] ?? ('rfu-chart-' . $_ri . '-' . $_fi . '-' . count($_rfu_charts));
	/* Determine emptiness for graceful placeholder. */
	$_type   = $_chart['type'] ?? 'column';
	$_hasData = false;
	if ($_type === 'pie') {
		$_hasData = !empty($_chart['data']) && array_sum(array_map('floatval', $_chart['data'])) > 0;
	} else {
		foreach (($_chart['series'] ?? []) as $_s) {
			if (!empty($_s['data']) && array_sum(array_map('floatval', $_s['data'])) > 0) { $_hasData = true; break; }
		}
	}
	if ($_hasData) {
		$_rfu_charts[] = $_chart + ['id' => $_cid];
	}
?>
						<div>
<?php if (!empty($_chart['title'])): ?>
							<div class="rfu-chart-title"><?=htmlspecialchars($_chart['title'])?></div>
<?php endif; ?>
<?php if ($_hasData): ?>
							<div id="<?=htmlspecialchars($_cid)?>" style="height:320px;"></div>
<?php else: ?>
							<div class="rfu-chart-empty"><i class="far fa-chart-bar"></i> No data yet</div>
<?php endif; ?>
						</div>
<?php endforeach; ?>
					</div>
<?php endif; ?>

<?php if (!empty($_links)): ?>
						<div class="rfu-links">
<?php foreach ($_links as $_link): ?>
<?php
	$_items = (isset($_link['items']) && is_array($_link['items'])) ? $_link['items'] : [];
?>
							<div class="rfu-link-tile">
<?php if (!empty($_link['title'])): ?>
								<div class="rfu-link-title"><?=htmlspecialchars($_link['title'])?></div>
<?php endif; ?>
<?php foreach ($_items as $_item): ?>
<?php
	$_route = (string)($_item['route'] ?? '');
	$_lbl   = (string)($_item['label'] ?? '');
	$_sub   = isset($_item['sub']) ? trim((string)$_item['sub']) : '';
?>
								<div class="rfu-link-item">
									<a class="rfu-link-a" href="<?=htmlspecialchars(UIR . $_route)?>" target="_blank" rel="noopener">
										<span><?=htmlspecialchars($_lbl)?></span>
										<i class="fas fa-external-link-alt"></i>
									</a>
<?php if ($_sub !== ''): ?>
									<div class="rfu-link-sub"><?=htmlspecialchars($_sub)?></div>
<?php endif; ?>
								</div>
<?php endforeach; ?>
							</div>
<?php endforeach; ?>
						</div>
<?php endif; ?>
				</div>
<?php endforeach; ?>
<?php endif; ?>
			</section>
<?php endforeach; ?>
		</div><!-- /.rfu-main -->

	</div><!-- /.rfu-shell -->

<?php endif; ?>

</div><!-- /.rp-root -->

<?php if (!empty($_rfu_charts)): ?>
<script src="https://code.highcharts.com/highcharts.js"></script>
<script>
(function () {
	/* Dark-mode detection — same approach as Admin_newplayerattendance.tpl */
	function _rfuIsDark() {
		var a = document.documentElement.getAttribute('data-theme');
		return a === 'dark' || (!a && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
	}
	function _rfuTooltipTheme(dk) {
		return { backgroundColor: dk ? '#1a2035' : '#fff', borderColor: dk ? '#818cf8' : '#ccc', style: { color: dk ? '#f1f5f9' : '#333' } };
	}

	/* Palette that reads in both themes. */
	var RFU_COLORS = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#84cc16', '#ec4899'];

	var specs = <?=json_encode(array_values($_rfu_charts))?>;
	var charts = [];

	function buildOne(spec) {
		var dk    = _rfuIsDark();
		var type  = spec.type || 'column';
		var axisLabel = dk ? '#cbd5e0' : '#333';
		var axisTitle = dk ? '#a0aec0' : '#666';
		var gridCol   = dk ? '#2d3748' : '#e6e6e6';
		var lineCol   = dk ? '#4a5568' : '#ccd6eb';

		var cfg = {
			chart  : { renderTo: spec.id, type: (type === 'bar' ? 'bar' : (type === 'pie' ? 'pie' : 'column')), backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
			title  : { text: null },
			colors : RFU_COLORS,
			credits: { enabled: false },
			tooltip: _rfuTooltipTheme(dk),
			legend : { enabled: true, itemStyle: { color: axisLabel }, itemHoverStyle: { color: dk ? '#fff' : '#000' } }
		};

		if (type === 'pie') {
			var cats = spec.categories || [];
			var vals = spec.data || [];
			var pieData = cats.map(function (c, i) { return { name: c, y: Number(vals[i]) || 0 }; });
			cfg.plotOptions = { pie: {
				allowPointSelect: true, cursor: 'pointer', borderWidth: 0,
				dataLabels: { enabled: true, format: '{point.name}: {point.y}', style: { color: axisLabel, textOutline: 'none', fontWeight: '600' } }
			}};
			cfg.series = [{ name: spec.title || 'Share', colorByPoint: true, data: pieData }];
		} else {
			cfg.xAxis = {
				categories: spec.categories || [],
				labels: { style: { fontSize: '11px', color: axisLabel } },
				lineColor: lineCol, tickColor: lineCol
			};
			if (type !== 'bar') { cfg.xAxis.labels.rotation = -25; }
			cfg.yAxis = {
				title: { text: null, style: { color: axisTitle } },
				allowDecimals: false, min: 0,
				labels: { style: { color: axisTitle } },
				gridLineColor: gridCol
			};
			cfg.plotOptions = { series: { borderWidth: 0 } };
			cfg.series = (spec.series || []).map(function (s, i) {
				return { name: s.name, data: (s.data || []).map(Number), color: RFU_COLORS[i % RFU_COLORS.length] };
			});
			/* Single-series column/bar: color points individually for visual clarity. */
			if (cfg.series.length === 1) {
				cfg.series[0].colorByPoint = true;
				cfg.legend.enabled = false;
			}
		}
		return new Highcharts.Chart(cfg);
	}

	function buildAll() {
		specs.forEach(function (spec) {
			if (document.getElementById(spec.id)) { charts.push(buildOne(spec)); }
		});
	}
	function rebuildAll() {
		charts.forEach(function (c) { try { c.destroy(); } catch (e) {} });
		charts = [];
		buildAll();
	}

	buildAll();
	new MutationObserver(rebuildAll).observe(document.documentElement, { attributeFilter: ['data-theme'] });
}());
</script>
<?php endif; ?>

<script>
/* Scrollspy for the jump nav (no dependency on chart presence). */
(function () {
	var links    = Array.prototype.slice.call(document.querySelectorAll('.rfu-nav-link[data-rfu-target]'));
	if (!links.length || !('IntersectionObserver' in window)) { return; }
	var sections = links.map(function (l) { return document.getElementById(l.getAttribute('data-rfu-target')); }).filter(Boolean);

	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (e) {
			if (!e.isIntersecting) { return; }
			links.forEach(function (l) {
				l.classList.toggle('is-active', l.getAttribute('data-rfu-target') === e.target.id);
			});
		});
	}, { rootMargin: '-20% 0px -70% 0px', threshold: 0 });

	sections.forEach(function (s) { io.observe(s); });
}());
</script>
