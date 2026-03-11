<?php
/* ── Pre-compute stats ── */
$_ul_units      = is_array($Units['Units']) ? $Units['Units'] : [];
$_ul_total      = count($_ul_units);
$_ul_companies  = 0;
$_ul_households = 0;
$_ul_events     = 0;
$_ul_scoped     = !empty($ScopeLabel);

$_one_year_ago  = date('Y-m-d', strtotime('-1 year'));

$_ul_active_total      = 0;
$_ul_active_companies  = 0;
$_ul_active_households = 0;
$_ul_active_events     = 0;

foreach ($_ul_units as $_u) {
	if ($_u['Type'] === 'Company')   $_ul_companies++;
	elseif ($_u['Type'] === 'Event') $_ul_events++;
	else                             $_ul_households++;

	$_is_active = !empty($_u['LastActivityDate']) && $_u['LastActivityDate'] >= $_one_year_ago;
	if ($_is_active) {
		$_ul_active_total++;
		if ($_u['Type'] === 'Company')        $_ul_active_companies++;
		elseif ($_u['Type'] === 'Event')      $_ul_active_events++;
		else                                  $_ul_active_households++;
	}
}
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<style>
.ul-thumb {
	width: 38px; height: 38px; border-radius: 5px;
	object-fit: cover; border: 1px solid var(--rp-border);
	background: var(--rp-bg-light); display: block;
}
.ul-type-badge {
	display: inline-block; padding: 2px 8px; border-radius: 10px;
	font-size: 10px; font-weight: 700; text-transform: uppercase;
	letter-spacing: 0.04em; margin-left: 6px; vertical-align: middle;
}
.ul-badge-company   { background: #e0e7ff; color: #3730a3; }
.ul-badge-household { background: #d1fae5; color: #065f46; }
.ul-badge-event     { background: #fef3c7; color: #92400e; }
.ul-name-link { font-weight: 600; color: var(--rp-accent); text-decoration: none; }
.ul-name-link:hover { color: var(--rp-accent-mid); text-decoration: underline; }
#ul-table td:first-child, #ul-table th:first-child { width: 50px; padding-right: 4px; }

/* ── Mobile ─────────────────────────────────────────── */
.rp-table-area { overflow-x: auto; -webkit-overflow-scrolling: touch; }

@media (max-width: 600px) {
	.ul-col-leaders, .ul-col-web { display: none; }
	.ul-thumb { width: 28px; height: 28px; }
	.rp-context { font-size: 12px; padding: 8px 10px; }
	.rp-stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
	.rp-stat-card { flex: unset; }
}

/* ── Create Unit Modal ───────────────────────────────── */
.uc-overlay {
	position: fixed; inset: 0; background: rgba(0,0,0,0.45);
	display: flex; align-items: center; justify-content: center;
	z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.18s;
}
.uc-overlay.uc-open { opacity: 1; pointer-events: auto; }
.uc-modal {
	background: #fff; border-radius: 10px; width: 460px;
	max-width: calc(100vw - 32px); box-shadow: 0 8px 32px rgba(0,0,0,0.18);
	transform: translateY(12px); transition: transform 0.18s;
}
.uc-overlay.uc-open .uc-modal { transform: none; }
.uc-modal-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
}
.uc-modal-title {
	font-size: 16px; font-weight: 700; color: #1a202c; margin: 0;
	background: transparent !important; border: none !important; padding: 0 !important;
	border-radius: 0 !important; text-shadow: none !important;
}
.uc-close-btn {
	background: none; border: none; font-size: 20px; color: #718096;
	cursor: pointer; line-height: 1; padding: 0 4px;
}
.uc-close-btn:hover { color: #2d3748; }
.uc-modal-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.uc-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 5px; }
.uc-field input, .uc-field select {
	width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px;
	font-size: 14px; color: #2d3748; box-sizing: border-box; font-family: inherit;
	transition: border-color 0.15s;
}
.uc-field input:focus, .uc-field select:focus {
	outline: none; border-color: #3182ce; box-shadow: 0 0 0 2px rgba(49,130,206,0.12);
}
.uc-modal-footer {
	display: flex; justify-content: flex-end; gap: 8px;
	padding: 14px 20px; border-top: 1px solid #e2e8f0;
}
.uc-btn {
	border: none; border-radius: 6px; font-size: 13px; font-weight: 600;
	cursor: pointer; padding: 7px 16px; transition: background 0.15s;
}
.uc-btn-secondary { background: #edf2f7; color: #4a5568; }
.uc-btn-secondary:hover { background: #e2e8f0; }
.uc-btn-primary { background: #3182ce; color: #fff; }
.uc-btn-primary:hover { background: #2b6cb0; }
.uc-new-btn {
	display: inline-flex; align-items: center; gap: 6px;
	background: #3182ce; color: #fff; border: none; border-radius: 6px;
	font-size: 13px; font-weight: 600; padding: 6px 14px; cursor: pointer;
	transition: background 0.15s;
}
.uc-new-btn:hover { background: #2b6cb0; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-shield-alt rp-header-icon"></i>
				<h1 class="rp-header-title">Units</h1>
			</div>
<?php if ($_ul_scoped): ?>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-map-marker-alt"></i>
					<span class="rp-scope-chip-label">Scope:</span>
					<?=htmlspecialchars($ScopeLabel)?>
				</span>
			</div>
<?php endif; ?>
		</div>
<?php if (!empty($LoggedIn)): ?>
		<div class="rp-header-actions">
			<button class="uc-new-btn" id="uc-open-btn">
				<i class="fas fa-plus"></i> New Unit
			</button>
		</div>
<?php endif; ?>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number" id="ul-stat-total"><?=$_ul_active_total?></div>
			<div class="rp-stat-label">Active Units</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number" id="ul-stat-companies"><?=$_ul_active_companies?></div>
			<div class="rp-stat-label">Active Companies</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-home"></i></div>
			<div class="rp-stat-number" id="ul-stat-households"><?=$_ul_active_households?></div>
			<div class="rp-stat-label">Active Households</div>
		</div>
<?php if ($_ul_events > 0): ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-alt"></i></div>
			<div class="rp-stat-number"><?=$_ul_active_events?></div>
			<div class="rp-stat-label">Active Events</div>
		</div>
<?php endif; ?>
	</div>

	<!-- Table area -->
	<div class="rp-table-area">

		<!-- Descriptor -->
		<div class="rp-context" style="margin-bottom:14px;border-radius:6px;border:1px solid var(--rp-border);">
			<i class="fas fa-info-circle rp-context-icon"></i>
			<span>Showing active companies and households — units where at least one member has signed in within the past year. To include inactive units, click <strong>Include Inactive</strong> below.</span>
		</div>

		<!-- Type + activity filter pills -->
		<div class="rp-filter-pills" style="margin-bottom:14px;">
			<button class="rp-filter-pill active" data-type="">All Types</button>
			<button class="rp-filter-pill" data-type="Company">Companies</button>
			<button class="rp-filter-pill" data-type="Household">Households</button>
<?php if ($_ul_events > 0): ?>
			<button class="rp-filter-pill" data-type="Event">Events</button>
<?php endif; ?>
			<button class="rp-filter-pill" id="ul-pill-inactive" data-inactive="0" style="margin-left:8px;">
				<i class="fas fa-eye-slash" style="font-size:10px;"></i> Include Inactive
			</button>
		</div>

<?php if ($_ul_total === 0): ?>
		<div style="padding:32px 16px;text-align:center;color:var(--rp-text-muted);font-size:14px;">
			<i class="fas fa-shield-alt" style="font-size:28px;display:block;margin-bottom:10px;opacity:0.3;"></i>
			No units found.
		</div>
<?php else: ?>
		<table id="ul-table" class="dataTable" style="width:100%">
			<thead>
				<tr>
					<th></th>
					<th>Name</th>
					<th style="display:none">Type</th>
					<th style="display:none">LastActivity</th>
<?php if ($_ul_scoped): ?>
					<th style="white-space:normal;min-width:60px;">In <?=htmlspecialchars($ScopeLabel)?></th>
<?php endif; ?>
					<th>Members</th>
					<th>Active</th>
					<th class="ul-col-leaders">Leaders</th>
					<th class="ul-col-web" style="text-align:center">Web</th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($_ul_units as $unit):
	$_thumb = $unit['HasHeraldry']
		? HTTP_UNIT_HERALDRY . sprintf('%05d', $unit['UnitId']) . '.jpg'
		: HTTP_UNIT_HERALDRY . '00000.jpg';
	$_badge_class = match($unit['Type']) {
		'Company' => 'ul-badge-company',
		'Event'   => 'ul-badge-event',
		default   => 'ul-badge-household',
	};
	$_last_activity = $unit['LastActivityDate'] ?? '';
?>
				<tr>
					<td>
						<img class="ul-thumb"
							src="<?=$_thumb?>"
							onerror="this.onerror=null;this.src='<?=HTTP_UNIT_HERALDRY?>00000.jpg'"
							alt="">
					</td>
					<td>
						<a class="ul-name-link" href="<?=UIR?>Unit/index/<?=$unit['UnitId']?>"><?=htmlspecialchars($unit['Name'])?></a>
						<span class="ul-type-badge <?=$_badge_class?>"><?=htmlspecialchars($unit['Type'])?></span>
					</td>
					<td style="display:none"><?=htmlspecialchars($unit['Type'])?></td>
					<td style="display:none"><?=htmlspecialchars($_last_activity)?></td>
<?php if ($_ul_scoped): ?>
					<td><?=(int)$unit['MemberCount']?></td>
<?php endif; ?>
					<td><?=(int)$unit['TotalMemberCount']?></td>
					<td><?=(int)$unit['ActiveMemberCount']?></td>
					<td class="ul-col-leaders"><?=htmlspecialchars($unit['LeaderNames'] ?? '')?></td>
					<td class="ul-col-web" style="text-align:center"><?php if (!empty($unit['Url'])): ?><a href="<?=htmlspecialchars($unit['Url'])?>" target="_blank" rel="noopener" title="<?=htmlspecialchars($unit['Url'])?>"><i class="fas fa-external-link-alt" style="color:#3730a3;font-size:13px"></i></a><?php else: ?>&nbsp;<?php endif; ?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
<?php endif; ?>

	</div><!-- /.rp-table-area -->

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
	if (!$('#ul-table').length) return;

	var TYPE_COL     = 2;
	var ACTIVITY_COL = 3;
	var includeInactive = false;
	var activeTypeFilter = '';

	var oneYearAgo = new Date();
	oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);

	/* Custom activity filter — runs on every draw */
	$.fn.dataTable.ext.search.push(function (settings, data) {
		if (settings.nTable.id !== 'ul-table') return true;
		if (includeInactive) return true;
		var lastActivity = data[ACTIVITY_COL];
		if (!lastActivity) return false;
		return new Date(lastActivity) >= oneYearAgo;
	});

	var table = $('#ul-table').DataTable({
		dom       : 'lfrtip',
		pageLength: 25,
		order     : [[1, 'asc']],
		columnDefs: [
			{ targets: 0,            orderable: false, searchable: false },
			{ targets: -1,           orderable: false, searchable: false },
			{ targets: TYPE_COL,     visible: false },
			{ targets: ACTIVITY_COL, visible: false }
		]
	});

	/* Type filter pills */
	$('.rp-filter-pill[data-type]').on('click', function () {
		$('.rp-filter-pill[data-type]').removeClass('active');
		$(this).addClass('active');
		activeTypeFilter = $(this).data('type');
		table.column(TYPE_COL).search(
			activeTypeFilter ? '^' + activeTypeFilter + '$' : '',
			true, false
		).draw();
	});

	/* Include Inactive toggle */
	$('#ul-pill-inactive').on('click', function () {
		includeInactive = !includeInactive;
		$(this).toggleClass('active', includeInactive);
		$(this).find('i').toggleClass('fa-eye-slash', !includeInactive).toggleClass('fa-eye', includeInactive);
		table.draw();
	});
}());
</script>

<?php if (!empty($LoggedIn)): ?>
<div class="uc-overlay" id="uc-overlay">
	<div class="uc-modal">
		<div class="uc-modal-header">
			<h3 class="uc-modal-title"><i class="fas fa-shield-alt" style="margin-right:8px;color:#3182ce"></i>Create Company or Household</h3>
			<button class="uc-close-btn" id="uc-close-btn" aria-label="Close">&times;</button>
		</div>
		<form method="post" action="<?=UIR?>Unit/create/<?=(int)$this->__session->user_id?>">
			<input type="hidden" name="Action" value="create">
			<div class="uc-modal-body">
				<div class="uc-field">
					<label>Name <span style="color:#e53e3e">*</span></label>
					<input type="text" name="Name" required placeholder="Enter a name…" id="uc-name-input" autocomplete="off">
				</div>
				<div class="uc-field">
					<label>Type</label>
					<select name="Type">
						<option value="Household">Household</option>
						<option value="Company">Company</option>
					</select>
				</div>
				<div class="uc-field">
					<label>Website URL <span style="font-weight:400;color:#a0aec0;">(optional)</span></label>
					<input type="url" name="Url" placeholder="https://…">
				</div>
			</div>
			<div class="uc-modal-footer">
				<button type="button" class="uc-btn uc-btn-secondary" id="uc-cancel-btn">Cancel</button>
				<button type="submit" class="uc-btn uc-btn-primary"><i class="fas fa-plus"></i> Create</button>
			</div>
		</form>
	</div>
</div>
<script>
(function() {
	var overlay   = document.getElementById('uc-overlay');
	var openBtn   = document.getElementById('uc-open-btn');
	var closeBtn  = document.getElementById('uc-close-btn');
	var cancelBtn = document.getElementById('uc-cancel-btn');
	if (!overlay || !openBtn) return;

	function openModal() {
		overlay.classList.add('uc-open');
		document.body.style.overflow = 'hidden';
		var n = document.getElementById('uc-name-input');
		if (n) setTimeout(function() { n.focus(); }, 50);
	}
	function closeModal() {
		overlay.classList.remove('uc-open');
		document.body.style.overflow = '';
	}

	openBtn.addEventListener('click', openModal);
	closeBtn.addEventListener('click', closeModal);
	cancelBtn.addEventListener('click', closeModal);
	overlay.addEventListener('click', function(e) { if (e.target === this) closeModal(); });
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && overlay.classList.contains('uc-open'))
			closeModal();
	});
}());
</script>
<?php endif; ?>
