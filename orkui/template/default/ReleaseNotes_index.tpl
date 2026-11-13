<?php
$version = $ork_version ?? '3.x';
?>

<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
/* ── Release Notes page-specific styles ─────────────────── */
.rn-body {
	max-width: 860px;
	margin: 16px auto 0;
	padding: 0;
}

.rn-release {
	background: var(--ork-card-bg);
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	margin-bottom: 20px;
	box-shadow: var(--ork-shadow-card);
	overflow: hidden;
}

.rn-release-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px 20px;
	background: var(--rp-bg-light);
	border-bottom: 1px solid var(--rp-divider);
}

.rn-release-title {
	font-size: 16px;
	font-weight: 700;
	color: var(--rp-text);
	margin: 0;
}

.rn-release-date {
	font-size: 12px;
	color: var(--rp-text-muted);
	font-weight: 600;
}

.rn-items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.rn-item {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	padding: 14px 20px;
	border-bottom: 1px solid var(--rp-divider);
}

.rn-item:last-child {
	border-bottom: none;
}

.rn-item-icon {
	width: 36px;
	height: 36px;
	border-radius: 8px;
	background: var(--rp-accent);
	color: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 15px;
	flex-shrink: 0;
	margin-top: 1px;
}

.rn-item-content {
	flex: 1;
	min-width: 0;
}

.rn-item-title {
	font-size: 14px;
	font-weight: 700;
	color: var(--rp-text);
	margin: 0 0 3px 0;
}

.rn-item-body {
	font-size: 13px;
	color: var(--rp-text-body);
	line-height: 1.5;
	margin: 0;
}

/* ── ORK 0.1: the pinned ORKPLE II release, in green phosphor ── */
html[data-theme] .rn-release.rn-orkple,
.rn-release.rn-orkple {
	--orkple-phosphor: #7cf5c4;
	--orkple-screen: #0b0f0d;
	border-color: #1f3b31;
}

/* .rn-release.rn-orkple beats orkui.css's html[data-theme="dark"] .rn-release-header,
   so the CRT header stays screen-black in both themes. */
.rn-release.rn-orkple .rn-release-header {
	background: var(--orkple-screen);
	border-bottom-color: #1f3b31;
}

.rn-orkple .rn-release-title,
.rn-orkple .rn-release-date {
	color: var(--orkple-phosphor);
	font-family: "Courier New", Courier, monospace;
	letter-spacing: 1px;
	text-transform: uppercase;
	text-shadow: 0 0 6px rgba(124, 245, 196, 0.45);
}

.rn-orkple .rn-item-icon {
	background: var(--orkple-screen);
	color: var(--orkple-phosphor);
}

.rn-orkple-insert {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
	padding: 8px 14px;
	border: 2px solid var(--orkple-screen);
	border-radius: 4px;
	background: var(--orkple-phosphor);
	color: var(--orkple-screen);
	font: 700 13px/1 "Courier New", Courier, monospace;
	letter-spacing: 1px;
	text-transform: uppercase;
	cursor: pointer;
}

.rn-orkple-insert:hover,
.rn-orkple-insert:focus-visible {
	background: var(--orkple-screen);
	color: var(--orkple-phosphor);
}

html[data-theme="dark"] .rn-orkple-insert {
	border-color: var(--orkple-phosphor);
}
</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-bullhorn rp-header-icon"></i>
				<h1 class="rp-header-title">ORK Release Notes</h1>
			</div>
			<div class="rp-header-scope">
				<span style="color:rgba(255,255,255,0.65);">ORK v<?=htmlspecialchars($version)?></span>
			</div>
		</div>
	</div>

	<!-- ── Context strip ─────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>A log of new features, improvements, and fixes shipped to the ORK.</span>
	</div>

	<!-- ── Release entries ────────────────────────────────── -->
	<div class="rn-body">

<?php foreach ($releases as $release) :
	$rel_date = $release['display_date'] ?? date('F j, Y', strtotime($release['date']));
	$rel_label = $release['label'] ?? 'v' . $release['version'];
?>
		<div class="rn-release<?=!empty($release['orkple']) ? ' rn-orkple' : ''?>" id="<?=htmlspecialchars($release['anchor'])?>">
			<div class="rn-release-header">
				<div class="rn-release-title"><?=htmlspecialchars($rel_label)?></div>
				<div class="rn-release-date"><?=htmlspecialchars($rel_date)?></div>
			</div>
			<ul class="rn-items">
<?php foreach ($release['items'] as $item) : ?>
				<li class="rn-item">
					<div class="rn-item-icon"><i class="<?=htmlspecialchars($item['icon'])?>"></i></div>
					<div class="rn-item-content">
						<div class="rn-item-title"><?=htmlspecialchars($item['title'])?></div>
						<p class="rn-item-body"><?=htmlspecialchars($item['body'])?></p>
<?php if (($item['action'] ?? '') === 'orkple' && !empty($OrkpleEnabled)) : ?>
						<button type="button" class="rn-orkple-insert" data-orkple-insert><i class="fas fa-save" aria-hidden="true"></i> Insert Floppy Disk</button>
<?php endif; ?>
					</div>
				</li>
<?php endforeach; ?>
			</ul>
		</div>
<?php endforeach; ?>

	</div>
</div>
