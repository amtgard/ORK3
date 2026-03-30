<?php
$version = $ork_version ?? '3.x';
?>

<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
/* ── Release Notes page-specific styles ─────────────────── */
.rn-body {
	max-width: 860px;
	margin: 16px auto 0;
	padding: 0;
}

.rn-release {
	background: #fff;
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	margin-bottom: 20px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	overflow: hidden;
}

.rn-release-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 14px 20px;
	background: var(--rp-bg-light);
	border-bottom: 1px solid var(--rp-border);
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
	border-bottom: 1px solid var(--rp-border);
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
	$rel_date = date('F j, Y', strtotime($release['date']));
?>
		<div class="rn-release">
			<div class="rn-release-header">
				<div class="rn-release-title">v<?=htmlspecialchars($release['version'])?></div>
				<div class="rn-release-date"><?=htmlspecialchars($rel_date)?></div>
			</div>
			<ul class="rn-items">
<?php foreach ($release['items'] as $item) : ?>
				<li class="rn-item">
					<div class="rn-item-icon"><i class="<?=htmlspecialchars($item['icon'])?>"></i></div>
					<div class="rn-item-content">
						<div class="rn-item-title"><?=htmlspecialchars($item['title'])?></div>
						<p class="rn-item-body"><?=htmlspecialchars($item['body'])?></p>
					</div>
				</li>
<?php endforeach; ?>
			</ul>
		</div>
<?php endforeach; ?>

	</div>
</div>
