<?php
$_su_kingdom_id = valid_id($KingdomId ?? 0) ? (int)$KingdomId : 0;
$_su_park_id    = valid_id($ParkId    ?? 0) ? (int)$ParkId    : 0;
$_su_ajax_base  = UIR . 'Search/unitsearch';
$_su_ajax_params = '';
if ($_su_kingdom_id) $_su_ajax_params .= '&KingdomId=' . $_su_kingdom_id;
if ($_su_park_id)    $_su_ajax_params .= '&ParkId='    . $_su_park_id;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<style>
.su-thumb {
	width: 38px; height: 38px; border-radius: 5px;
	object-fit: cover; border: 1px solid var(--rp-border);
	background: var(--rp-bg-light); display: block;
}
.su-type-badge {
	display: inline-block; padding: 2px 8px; border-radius: 10px;
	font-size: 10px; font-weight: 700; text-transform: uppercase;
	letter-spacing: 0.04em; margin-left: 6px; vertical-align: middle;
}
.su-badge-company   { background: #e0e7ff; color: #3730a3; }
.su-badge-household { background: #d1fae5; color: #065f46; }
.su-badge-event     { background: #fef3c7; color: #92400e; }
.su-name-link { font-weight: 600; color: var(--rp-accent); text-decoration: none; }
.su-name-link:hover { color: var(--rp-accent-mid); text-decoration: underline; }
#su-table td:first-child, #su-table th:first-child { width: 50px; padding-right: 4px; }

.su-search-bar {
	display: flex; gap: 10px; align-items: center; margin-bottom: 14px;
	max-width: 50%;
}
.su-search-input {
	flex: 1; padding: 8px 12px; border: 1.5px solid var(--rp-border); border-radius: 6px;
	font-size: 14px; color: #2d3748; background: #fff; box-sizing: border-box; font-family: inherit;
	transition: border-color 0.15s;
}
.su-search-input:focus { outline: none; border-color: #3182ce; box-shadow: 0 0 0 2px rgba(49,130,206,0.12); }
.su-search-clear {
	background: none; border: none; color: #a0aec0; cursor: pointer; font-size: 18px;
	padding: 0 6px; line-height: 1; display: none;
}
.su-search-clear:hover { color: #4a5568; }
.su-search-btn {
	padding: 8px 18px; background: #38a169; color: #fff; border: none; border-radius: 6px;
	font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; font-family: inherit;
	transition: background 0.15s;
}
.su-search-btn:hover { background: #2f855a; }
.su-loading { text-align: center; padding: 32px; color: var(--rp-text-muted); font-size: 14px; }

/* Mobile */
.rp-table-area { overflow-x: auto; -webkit-overflow-scrolling: touch; }
@media (max-width: 600px) {
	.su-col-leaders, .su-col-web { display: none; }
	.su-thumb { width: 28px; height: 28px; }
	.rp-stats-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
	.rp-stat-card { flex: unset; }
}

/* Create Unit Modal */
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
.uc-close-btn { background: none; border: none; font-size: 20px; color: #718096; cursor: pointer; line-height: 1; padding: 0 4px; }
.uc-close-btn:hover { color: #2d3748; }
.uc-modal-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.uc-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 5px; }
.uc-field input, .uc-field select {
	width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px;
	font-size: 14px; color: #2d3748; box-sizing: border-box; font-family: inherit;
}
.uc-field input:focus, .uc-field select:focus { outline: none; border-color: #3182ce; box-shadow: 0 0 0 2px rgba(49,130,206,0.12); }
.uc-modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid #e2e8f0; }
.uc-btn { border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; padding: 7px 16px; }
.uc-btn-secondary { background: #edf2f7; color: #4a5568; }
.uc-btn-secondary:hover { background: #e2e8f0; }
.uc-btn-primary { background: #3182ce; color: #fff; }
.uc-btn-primary:hover { background: #2b6cb0; }
.uc-new-btn {
	display: inline-flex; align-items: center; gap: 6px;
	background: #3182ce; color: #fff; border: none; border-radius: 6px;
	font-size: 13px; font-weight: 600; padding: 6px 14px; cursor: pointer;
}
.uc-new-btn:hover { background: #2b6cb0; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-shield-alt rp-header-icon"></i>
				<h1 class="rp-header-title">Companies &amp; Households</h1>
			</div>
		</div>
<?php if (!empty($LoggedIn)): ?>
		<div class="rp-header-actions">
			<button class="uc-new-btn" id="uc-open-btn">
				<i class="fas fa-plus"></i> New Unit
			</button>
		</div>
<?php endif; ?>
	</div>

	<!-- Subheader -->
	<div class="rp-context" style="border-radius:0;border-left:none;border-right:none;border-top:none;">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>An <strong>active</strong> unit has at least one member with a sign-in recorded in the past 12 months.</span>
	</div>

	<!-- Stats row (populated by JS) -->
	<div class="rp-stats-row" id="su-stats-row" style="display:none">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number" id="su-stat-total">—</div>
			<div class="rp-stat-label">Active Units</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number" id="su-stat-companies">—</div>
			<div class="rp-stat-label">Active Companies</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-home"></i></div>
			<div class="rp-stat-number" id="su-stat-households">—</div>
			<div class="rp-stat-label">Active Households</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-database"></i></div>
			<div class="rp-stat-number" id="su-stat-shown">—</div>
			<div class="rp-stat-label">Results Shown</div>
		</div>
	</div>

	<div class="rp-table-area">

		<!-- Search bar -->
		<div class="su-search-bar">
			<input type="text" class="su-search-input" id="su-search-input"
				placeholder="Search by name (3+ characters)…">
			<button class="su-search-clear" id="su-search-clear" title="Clear search">&times;</button>
			<button class="su-search-btn" id="su-search-btn"><i class="fas fa-search"></i> Search</button>
		</div>

		<!-- Type filter pills -->
		<div class="rp-filter-pills" style="margin-bottom:14px;">
			<button class="rp-filter-pill active" data-type="">All Types</button>
			<button class="rp-filter-pill" data-type="Company">Companies</button>
			<button class="rp-filter-pill" data-type="Household">Households</button>
			<button class="rp-filter-pill" data-type="Event">Events</button>
			<button class="rp-filter-pill" id="su-pill-inactive" data-inactive="0" style="margin-left:8px;">
				<i class="fas fa-eye-slash" style="font-size:10px;"></i> Include Inactive
			</button>
		</div>

		<div id="su-loading" class="su-loading">
			<i class="fas fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.3;"></i>
			Type a name to search companies &amp; households.
		</div>

		<table id="su-table" class="dataTable" style="width:100%;display:none">
			<thead>
				<tr>
					<th></th>
					<th>Name</th>
					<th style="display:none">Type</th>
					<th style="display:none">LastActivity</th>
					<th>Active</th>
					<th>Total</th>
					<th class="su-col-leaders">Leaders</th>
					<th class="su-col-web" style="text-align:center">Web</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>

	</div>
</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
	var AJAX_BASE     = <?= json_encode(UIR . 'Search/unitsearch') ?>;
	var AJAX_PARAMS   = <?= json_encode($_su_ajax_params) ?>;
	var HERALDRY_BASE = <?= json_encode(HTTP_UNIT_HERALDRY) ?>;
	var UIR_VAL       = <?= json_encode(UIR) ?>;

	var TYPE_COL     = 2;
	var ACTIVITY_COL = 3;
	var includeInactive = false;
	var activeTypeFilter = '';
	var searchTimer = null;
	var currentQuery = '';
	var table = null;

	var oneYearAgo = new Date();
	oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);

	function badgeClass(type) {
		if (type === 'Company')   return 'su-badge-company';
		if (type === 'Event')     return 'su-badge-event';
		return 'su-badge-household';
	}

	function webCell(url) {
		if (!url) return '&nbsp;';
		var safe = url.replace(/"/g, '&quot;');
		return '<a href="' + safe + '" target="_blank" rel="noopener" title="' + safe + '"><i class="fas fa-external-link-alt" style="color:#3730a3;font-size:13px"></i></a>';
	}

	function buildRows(units) {
		return units.map(function (u) {
			var thumb = HERALDRY_BASE + (u.HasHeraldry ? (String(u.UnitId).padStart(5, '0') + '.jpg') : '00000.jpg');
			var imgHtml = '<img class="su-thumb" src="' + thumb + '" onerror="this.onerror=null;this.src=\'' + HERALDRY_BASE + '00000.jpg\'" alt="">';
			var nameHtml = '<a class="su-name-link" href="' + UIR_VAL + 'Unit/index/' + u.UnitId + '">' + $('<span>').text(u.Name).html() + '</a>'
				+ '<span class="su-type-badge ' + badgeClass(u.Type) + '">' + (u.Type || '') + '</span>';
			return [
				imgHtml,
				nameHtml,
				u.Type || '',
				u.LastActivityDate || '',
				u.ActiveMemberCount || 0,
				u.TotalMemberCount  || 0,
				$('<span>').text(u.LeaderNames || '').html(),
				webCell(u.Url || '')
			];
		});
	}

	function updateStats(units) {
		var oneYear = oneYearAgo.toISOString().slice(0, 10);
		var active = 0, companies = 0, households = 0;
		units.forEach(function (u) {
			var isActive = u.LastActivityDate && u.LastActivityDate >= oneYear;
			if (isActive) {
				active++;
				if (u.Type === 'Company')   companies++;
				else if (u.Type !== 'Event') households++;
			}
		});
		$('#su-stat-total').text(active);
		$('#su-stat-companies').text(companies);
		$('#su-stat-households').text(households);
		$('#su-stat-shown').text(units.length);
		$('#su-stats-row').show();
	}

	function loadData(q) {
		$('#su-loading').html('<i class="fas fa-spinner fa-spin" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.4;"></i>Loading units…').show();
		$('#su-table').hide();
		var url = AJAX_BASE + '&q=' + encodeURIComponent(q || '') + AJAX_PARAMS;
		$.getJSON(url, function (units) {
			$('#su-loading').hide();
			if (!units || !units.length) {
				$('#su-table').hide();
				$('#su-loading').text('No units found.').show();
				updateStats([]);
				return;
			}
			updateStats(units);
			var rows = buildRows(units);
			if (table) {
				table.clear().rows.add(rows).draw();
			} else {
				$.fn.dataTable.ext.search.push(function (settings, data) {
					if (settings.nTable.id !== 'su-table') return true;
					if (includeInactive) return true;
					var lastActivity = data[ACTIVITY_COL];
					if (!lastActivity) return false;
					return new Date(lastActivity) >= oneYearAgo;
				});
				table = $('#su-table').DataTable({
					data      : rows,
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
			}
			$('#su-table').show();

			if (activeTypeFilter) {
				table.column(TYPE_COL).search('^' + activeTypeFilter + '$', true, false).draw();
			}
		}).fail(function () {
			$('#su-loading').text('Failed to load units. Please refresh.').show();
		});
	}

	// Type filter pills
	$('.rp-filter-pill[data-type]').on('click', function () {
		$('.rp-filter-pill[data-type]').removeClass('active');
		$(this).addClass('active');
		activeTypeFilter = $(this).data('type');
		if (table) {
			table.column(TYPE_COL).search(
				activeTypeFilter ? '^' + activeTypeFilter + '$' : '',
				true, false
			).draw();
		}
	});

	// Include Inactive toggle
	$('#su-pill-inactive').on('click', function () {
		includeInactive = !includeInactive;
		$(this).toggleClass('active', includeInactive);
		$(this).find('i').toggleClass('fa-eye-slash', !includeInactive).toggleClass('fa-eye', includeInactive);
		if (table) table.draw();
	});

	function doSearch() {
		var val = $('#su-search-input').val().trim();
		if (val.length < 3) return;
		if (val === currentQuery) return;
		currentQuery = val;
		loadData(val);
	}

	function clearSearch() {
		if (table) { table.destroy(); table = null; }
		$('#su-table').hide();
		$('#su-stats-row').hide();
		$('#su-loading').html('<i class="fas fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.3;"></i>Type a name to search companies &amp; households.').show();
		$('#su-search-input').val('');
		$('#su-search-clear').hide();
		currentQuery = '';
	}

	$('#su-search-input').on('input', function () {
		$('#su-search-clear').css('display', this.value.length ? 'block' : 'none');
	}).on('keydown', function (e) {
		if (e.key === 'Enter') doSearch();
	});

	$('#su-search-btn').on('click', doSearch);

	$('#su-search-clear').on('click', clearSearch);

	// Initial state — no auto-load
	$('#su-loading').html('<i class="fas fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.3;"></i>Type a name to search companies &amp; households.').show();
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
