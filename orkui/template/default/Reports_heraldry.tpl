<?php
$_hr_all        = is_array($Heraldry) ? $Heraldry : [];
$_hr_type       = $HeraldryType ?? 'unknown';
$_hr_is_player  = $_hr_type === 'Mundane';
$_one_year_ago  = date('Y-m-d', strtotime('-1 year'));

/* Only include items that have heraldry */
$_hr_items = array_values(array_filter($_hr_all, fn($i) => $i['HasHeraldry']));
usort($_hr_items, fn($a, $b) => strcasecmp($a['Name'], $b['Name']));

$_hr_total  = count($_hr_items);
$_hr_active = 0;
foreach ($_hr_items as $_item) {
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
/* ── Shield Wall Gallery ─────────────────────────────── */
.hw-gallery {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
	gap: 5px;
	padding: 16px 0 4px;
}

.hw-card {
	position: relative;
	aspect-ratio: 1 / 1;
	overflow: hidden;
	border-radius: 5px;
	background: #f1f5f9;
	text-decoration: none;
	display: block;
	cursor: pointer;
	opacity: 0;
	transform: scale(0.94);
	animation: hw-fadein 0.3s ease forwards;
}
@keyframes hw-fadein {
	to { opacity: 1; transform: scale(1); }
}

.hw-card-img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	display: block;
	transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
	padding: 6px;
	box-sizing: border-box;
}
.hw-card:hover .hw-card-img {
	transform: scale(1.1);
}

/* Hover overlay */
.hw-card-overlay {
	position: absolute;
	inset: 0;
	background: linear-gradient(
		to bottom,
		transparent 35%,
		rgba(15, 23, 42, 0.55) 65%,
		rgba(15, 23, 42, 0.85) 100%
	);
	display: flex;
	flex-direction: column;
	justify-content: flex-end;
	padding: 8px 7px 7px;
	opacity: 0;
	transition: opacity 0.2s ease;
	pointer-events: none;
}
.hw-card:hover .hw-card-overlay,
.hw-card:focus-visible .hw-card-overlay {
	opacity: 1;
}

.hw-card-name {
	color: #fff;
	font-size: 11px;
	font-weight: 700;
	line-height: 1.3;
	text-shadow: 0 1px 3px rgba(0,0,0,0.6);
	word-break: break-word;
}
.hw-card-meta {
	color: rgba(255,255,255,0.72);
	font-size: 9.5px;
	margin-top: 2px;
	text-shadow: 0 1px 2px rgba(0,0,0,0.5);
}

/* Inactive treatment */
.hw-card[data-active="0"] .hw-card-img {
	filter: grayscale(75%) opacity(0.45);
}
.hw-card[data-active="0"]:hover .hw-card-img {
	filter: grayscale(0%) opacity(1);
}

/* ── Search bar ──────────────────────────────────────── */
.hw-search-wrap {
	position: relative;
	max-width: 280px;
}
.hw-search-wrap i {
	position: absolute;
	left: 10px;
	top: 50%;
	transform: translateY(-50%);
	color: var(--rp-text-hint);
	font-size: 13px;
	pointer-events: none;
}
#hw-search {
	width: 100%;
	padding: 7px 10px 7px 32px;
	border: 1px solid var(--rp-border);
	border-radius: 6px;
	font-size: 13px;
	color: var(--rp-text);
	background: #fff;
	box-sizing: border-box;
	outline: none;
	transition: border-color 0.15s;
}
#hw-search:focus {
	border-color: var(--rp-accent-mid);
}

/* ── Filter row ──────────────────────────────────────── */
.hw-controls {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin: 14px 0 6px;
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
			<div class="rp-stat-label"><?=$_hr_label?> with Heraldry</div>
		</div>
<?php if ($_hr_is_player): ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=$_hr_active?></div>
			<div class="rp-stat-label">Active Players</div>
		</div>
<?php endif; ?>
	</div>

	<div class="rp-table-area" style="padding: 0 16px 24px;">

		<div class="hw-controls">
			<div class="hw-search-wrap">
				<i class="fas fa-search"></i>
				<input type="text" id="hw-search" placeholder="Search by name…">
			</div>
<?php if ($_hr_is_player): ?>
			<div class="rp-filter-pills" style="margin:0;">
				<button class="rp-filter-pill active" id="hw-pill-active">Active Only</button>
				<button class="rp-filter-pill" id="hw-pill-inactive">
					<i class="fas fa-eye-slash" style="font-size:10px;"></i> Include Inactive
				</button>
			</div>
<?php endif; ?>
		</div>

<?php if ($_hr_total === 0): ?>
		<div style="padding:40px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			<i class="fas <?=$_hr_icon?>" style="font-size:32px;display:block;margin-bottom:12px;opacity:0.25;"></i>
			No <?=strtolower($_hr_label)?> with heraldry found.
		</div>
<?php else: ?>
		<div class="hw-gallery" id="hw-gallery">
<?php foreach ($_hr_items as $_idx => $_item):
	$_signin    = $_item['LastSignin'] ?? '';
	$_is_active = !$_hr_is_player || (!empty($_signin) && $_signin >= $_one_year_ago);
	$_delay     = min($_idx * 18, 600);
?>
			<a class="hw-card"
				href="<?=htmlspecialchars($_item['Url'])?>"
				data-name="<?=htmlspecialchars(strtolower($_item['Name']))?>"
				data-active="<?=$_is_active ? '1' : '0'?>"
				style="animation-delay:<?=$_delay?>ms">
				<img class="hw-card-img"
					src="<?=htmlspecialchars($_item['HeraldryUrl']['Url'])?>"
					onerror="this.onerror=null;this.src='<?=$Blank?>'"
					loading="lazy"
					alt="<?=htmlspecialchars($_item['Name'])?>">
				<div class="hw-card-overlay">
					<div class="hw-card-name"><?=htmlspecialchars($_item['Name'])?></div>
<?php if ($_hr_is_player && !empty($_signin)): ?>
					<div class="hw-card-meta"><i class="fas fa-sign-in-alt" style="font-size:8px;"></i> <?=htmlspecialchars($_signin)?></div>
<?php endif; ?>
				</div>
			</a>
<?php endforeach; ?>
		</div>
		<div id="hw-empty-msg" style="display:none;padding:40px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			No results match your current filters.
		</div>
<?php endif; ?>

	</div>
</div>

<script>
(function () {
	var isPlayerType    = <?=$_hr_is_player ? 'true' : 'false'?>;
	var includeInactive = false;
	var searchTerm      = '';

	function applyFilters() {
		var cards   = document.querySelectorAll('#hw-gallery .hw-card');
		var visible = 0;

		cards.forEach(function (card) {
			var isActive = card.dataset.active === '1';
			var name     = card.dataset.name || '';

			var show = true;
			if (isPlayerType && !includeInactive && !isActive) show = false;
			if (searchTerm && name.indexOf(searchTerm) === -1) show = false;

			card.style.display = show ? '' : 'none';
			if (show) visible++;
		});

		var emptyMsg = document.getElementById('hw-empty-msg');
		if (emptyMsg) emptyMsg.style.display = (visible === 0) ? '' : 'none';
	}

	/* Search */
	var searchInput = document.getElementById('hw-search');
	if (searchInput) {
		searchInput.addEventListener('input', function () {
			searchTerm = this.value.trim().toLowerCase();
			applyFilters();
		});
	}

	/* Active Only / Include Inactive pills */
	document.getElementById('hw-pill-active')?.addEventListener('click', function () {
		includeInactive = false;
		this.classList.add('active');
		document.getElementById('hw-pill-inactive').classList.remove('active');
		document.getElementById('hw-pill-inactive').querySelector('i').className = 'fas fa-eye-slash';
		document.getElementById('hw-pill-inactive').querySelector('i').style.fontSize = '10px';
		applyFilters();
	});
	var inactivePill = document.getElementById('hw-pill-inactive');
	if (inactivePill) {
		inactivePill.addEventListener('click', function () {
			includeInactive = true;
			inactivePill.classList.add('active');
			document.getElementById('hw-pill-active').classList.remove('active');
			inactivePill.querySelector('i').className = 'fas fa-eye';
			inactivePill.querySelector('i').style.fontSize = '10px';
			applyFilters();
		});
	}

	/* Run on load */
	applyFilters();
}());
</script>
