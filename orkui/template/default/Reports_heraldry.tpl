<?php
$_hr_items      = is_array($Heraldry) ? $Heraldry : [];
$_hr_type       = $HeraldryType ?? 'unknown';
$_hr_is_player  = $_hr_type === 'Mundane';
$_hr_total      = count($_hr_items);
$_hr_has_h      = 0;
$_hr_active     = 0;
$_one_year_ago  = date('Y-m-d', strtotime('-1 year'));

foreach ($_hr_items as $_item) {
	if ($_item['HasHeraldry']) $_hr_has_h++;
	if ($_hr_is_player && !empty($_item['LastSignin']) && $_item['LastSignin'] >= $_one_year_ago) {
		$_hr_active++;
	}
}

$_hr_icon = match($_hr_type) {
	'Park'    => 'fa-map-marker-alt',
	'Kingdom' => 'fa-crown',
	'Unit'    => 'fa-shield-alt',
	'Event'   => 'fa-calendar-alt',
	default   => 'fa-users',
};
$_hr_label = match($_hr_type) {
	'Park'    => 'Parks',
	'Kingdom' => 'Kingdoms',
	'Unit'    => 'Units',
	'Event'   => 'Events',
	default   => 'Players',
};
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
.hr-gallery {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 14px;
	padding: 18px 0 4px;
}
.hr-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 7px;
	padding: 12px 8px 10px;
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	background: #fff;
	transition: box-shadow 0.15s, border-color 0.15s;
	text-align: center;
	cursor: pointer;
	text-decoration: none;
	color: inherit;
}
.hr-card:hover {
	box-shadow: 0 3px 12px rgba(67,56,202,0.12);
	border-color: var(--rp-accent-mid);
}
.hr-card-img {
	width: 80px;
	height: 80px;
	object-fit: contain;
	border-radius: 6px;
	border: 1px solid var(--rp-border);
	background: var(--rp-bg-light);
}
.hr-card-name {
	font-size: 12px;
	font-weight: 600;
	color: var(--rp-accent);
	line-height: 1.3;
	word-break: break-word;
}
.hr-card-meta {
	font-size: 10px;
	color: var(--rp-text-hint);
	line-height: 1.2;
}
.hr-no-heraldry .hr-card-img {
	opacity: 0.3;
}
.hr-inactive-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 8px;
	font-size: 9px;
	font-weight: 700;
	text-transform: uppercase;
	background: #f1f5f9;
	color: #94a3b8;
	border: 1px solid #e2e8f0;
}
</style>

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas <?=$_hr_icon?> rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($page_title ?? ($_hr_label . ' Heraldry'))?></h1>
			</div>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas <?=$_hr_icon?>"></i></div>
			<div class="rp-stat-number"><?=$_hr_total?></div>
			<div class="rp-stat-label">Total <?=$_hr_label?></div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-image"></i></div>
			<div class="rp-stat-number"><?=$_hr_has_h?></div>
			<div class="rp-stat-label">Has Heraldry</div>
		</div>
<?php if ($_hr_is_player): ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number" id="hr-stat-active"><?=$_hr_active?></div>
			<div class="rp-stat-label">Active Players</div>
		</div>
<?php endif; ?>
	</div>

	<div class="rp-table-area" style="padding: 0 16px 20px;">

		<div class="rp-filter-pills" style="margin: 14px 0;">
			<button class="rp-filter-pill active" id="hr-pill-heraldry" data-heraldry="0">All <?=$_hr_label?></button>
			<button class="rp-filter-pill" id="hr-pill-heraldry-only" data-heraldry="1">Has Heraldry Only</button>
<?php if ($_hr_is_player): ?>
			<button class="rp-filter-pill" id="hr-pill-inactive" style="margin-left:8px;">
				<i class="fas fa-eye-slash" style="font-size:10px;"></i> Include Inactive
			</button>
<?php endif; ?>
		</div>

<?php if ($_hr_total === 0): ?>
		<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			<i class="fas <?=$_hr_icon?>" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.3;"></i>
			No <?=strtolower($_hr_label)?> found.
		</div>
<?php else: ?>
		<div class="hr-gallery" id="hr-gallery">
<?php foreach ($_hr_items as $_item):
	$_img     = $_item['HasHeraldry'] ? $_item['HeraldryUrl']['Url'] : $Blank;
	$_signin  = $_item['LastSignin'] ?? '';
	$_is_active = !$_hr_is_player || (!empty($_signin) && $_signin >= $_one_year_ago);
	$_card_class = 'hr-card' . (!$_item['HasHeraldry'] ? ' hr-no-heraldry' : '');
?>
			<a class="<?=$_card_class?>"
				href="<?=htmlspecialchars($_item['Url'])?>"
				data-has-heraldry="<?=(int)$_item['HasHeraldry']?>"
				data-last-signin="<?=htmlspecialchars($_signin)?>"
				data-active="<?=$_is_active ? '1' : '0'?>">
				<img class="hr-card-img"
					src="<?=htmlspecialchars($_img)?>"
					onerror="this.onerror=null;this.src='<?=$Blank?>'"
					alt="">
				<span class="hr-card-name"><?=htmlspecialchars($_item['Name'])?></span>
<?php if ($_hr_is_player && !empty($_signin)): ?>
				<span class="hr-card-meta"><?=htmlspecialchars($_signin)?></span>
<?php elseif ($_hr_is_player): ?>
				<span class="hr-inactive-badge">No sign-in</span>
<?php endif; ?>
			</a>
<?php endforeach; ?>
		</div>
		<div id="hr-empty-msg" style="display:none;padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			No results match your current filters.
		</div>
<?php endif; ?>

	</div>

</div>

<script>
(function () {
	var isPlayerType    = <?=$_hr_is_player ? 'true' : 'false'?>;
	var includeInactive = false;
	var heraldryOnly    = false;

	var oneYearAgo = new Date();
	oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);

	function applyFilters() {
		var cards   = document.querySelectorAll('#hr-gallery .hr-card');
		var visible = 0;

		cards.forEach(function (card) {
			var hasH    = card.dataset.hasHeraldry === '1';
			var isActive = card.dataset.active === '1';

			var show = true;
			if (heraldryOnly && !hasH)              show = false;
			if (isPlayerType && !includeInactive && !isActive) show = false;

			card.style.display = show ? '' : 'none';
			if (show) visible++;
		});

		var emptyMsg = document.getElementById('hr-empty-msg');
		if (emptyMsg) emptyMsg.style.display = visible === 0 ? '' : 'none';
	}

	/* Heraldry pills */
	document.getElementById('hr-pill-heraldry')?.addEventListener('click', function () {
		heraldryOnly = false;
		document.getElementById('hr-pill-heraldry').classList.add('active');
		document.getElementById('hr-pill-heraldry-only').classList.remove('active');
		applyFilters();
	});
	document.getElementById('hr-pill-heraldry-only')?.addEventListener('click', function () {
		heraldryOnly = true;
		document.getElementById('hr-pill-heraldry-only').classList.add('active');
		document.getElementById('hr-pill-heraldry').classList.remove('active');
		applyFilters();
	});

	/* Include Inactive toggle (player only) */
	var inactivePill = document.getElementById('hr-pill-inactive');
	if (inactivePill) {
		inactivePill.addEventListener('click', function () {
			includeInactive = !includeInactive;
			inactivePill.classList.toggle('active', includeInactive);
			inactivePill.querySelector('i').className = includeInactive
				? 'fas fa-eye'
				: 'fas fa-eye-slash';
			inactivePill.querySelector('i').style.fontSize = '10px';
			applyFilters();
		});

		/* Apply active-only filter on load for player reports */
		applyFilters();
	}
}());
</script>
