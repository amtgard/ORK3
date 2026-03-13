<?php
$_ul_scoped  = !empty($ScopeLabel);
$_scope_kid  = (int)($ScopeKingdomId ?? 0);
$_scope_pid  = (int)($ScopeParkId    ?? 0);
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

.ul-search-bar {
	display: flex; gap: 10px; align-items: center; margin-bottom: 14px;
	max-width: 50%;
}
.ul-search-input {
	flex: 1; padding: 8px 12px; border: 1.5px solid var(--rp-border); border-radius: 6px;
	font-size: 14px; color: #2d3748; background: #fff; box-sizing: border-box; font-family: inherit;
	transition: border-color 0.15s;
}
.ul-search-input:focus { outline: none; border-color: #3182ce; box-shadow: 0 0 0 2px rgba(49,130,206,0.12); }
.ul-search-clear {
	background: none; border: none; color: #a0aec0; cursor: pointer; font-size: 18px;
	padding: 0 6px; line-height: 1; display: none;
}
.ul-search-clear:hover { color: #4a5568; }
.ul-search-btn {
	padding: 8px 18px; background: #38a169; color: #fff; border: none; border-radius: 6px;
	font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; font-family: inherit;
	transition: background 0.15s;
}
.ul-search-btn:hover { background: #2f855a; }

.ul-limit-warn {
	display: none; align-items: flex-start; gap: 10px;
	background: #fefce8; border: 1px solid #ca8a04; border-radius: 6px;
	padding: 10px 14px; margin-bottom: 12px; font-size: 13px; color: #854d0e;
}
.ul-limit-warn i { color: #ca8a04; margin-top: 2px; flex-shrink: 0; }

.ul-loading { text-align: center; padding: 32px; color: var(--rp-text-muted); font-size: 14px; }

/* Mobile */
.rp-table-area { overflow-x: auto; -webkit-overflow-scrolling: touch; }
@media (max-width: 600px) {
	.ul-col-leaders, .ul-col-web { display: none; }
	.ul-thumb { width: 28px; height: 28px; }
	.ul-search-bar { max-width: 100%; }
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

	<!-- Context banner -->
	<div class="rp-context" style="border-radius:0;border-left:none;border-right:none;border-top:none;">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>An <strong>active</strong> unit has at least one member with a sign-in recorded in the past 12 months.</span>
	</div>

	<!-- Stats row (populated by JS after search) -->
	<div class="rp-stats-row" id="ul-stats-row" style="display:none">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number" id="ul-stat-total">—</div>
			<div class="rp-stat-label">Active Units</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number" id="ul-stat-companies">—</div>
			<div class="rp-stat-label">Active Companies</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-home"></i></div>
			<div class="rp-stat-number" id="ul-stat-households">—</div>
			<div class="rp-stat-label">Active Households</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-database"></i></div>
			<div class="rp-stat-number" id="ul-stat-shown">—</div>
			<div class="rp-stat-label">Results Shown</div>
		</div>
	</div>

	<div class="rp-table-area">

		<!-- Search bar -->
		<div class="ul-search-bar">
			<input type="text" class="ul-search-input" id="ul-search-input"
				placeholder="Search by name (3+ characters)…" autocomplete="off">
			<button class="ul-search-clear" id="ul-search-clear" title="Clear search">&times;</button>
			<button class="ul-search-btn" id="ul-search-btn"><i class="fas fa-search"></i> Search</button>
		</div>

		<!-- 250-result limit warning -->
		<div class="ul-limit-warn" id="ul-limit-warn">
			<i class="fas fa-exclamation-triangle"></i>
			<span>Your search has been limited to 250 results. Update your search criteria and click Search again to refine further.</span>
		</div>

		<!-- Type + activity filter pills -->
		<div class="rp-filter-pills" style="margin-bottom:14px;">
			<button class="rp-filter-pill active" data-type="">All Types</button>
			<button class="rp-filter-pill" data-type="Company">Companies</button>
			<button class="rp-filter-pill" data-type="Household">Households</button>
			<button class="rp-filter-pill" id="ul-pill-inactive" data-inactive="0" style="margin-left:8px;">
				<i class="fas fa-eye-slash" style="font-size:10px;"></i> Include Inactive
			</button>
		</div>

		<div id="ul-loading" class="ul-loading">
			<i class="fas fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.3;"></i>
			Type a name to search units.
		</div>

		<table id="ul-table" class="dataTable" style="width:100%;display:none">
			<thead>
				<tr>
					<th></th>
					<th>Name</th>
					<th style="display:none">Type</th>
					<th style="display:none">LastActivity</th>
<?php if ($_ul_scoped): ?>
					<th>In <?=htmlspecialchars($ScopeLabel)?></th>
<?php endif; ?>
					<th>Active</th>
					<th>Total</th>
					<th class="ul-col-leaders">Leaders</th>
					<th class="ul-col-web" style="text-align:center">Web</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>

	</div>

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
	var KID          = <?= $_scope_kid ?>;
	var PID          = <?= $_scope_pid ?>;
	var HAS_SCOPE    = <?= $_ul_scoped ? 'true' : 'false' ?>;
	var SCOPE_LABEL  = <?= json_encode($ScopeLabel ?? '') ?>;
	var HERALDRY     = <?= json_encode(HTTP_UNIT_HERALDRY) ?>;
	var UIR_BASE     = <?= json_encode(UIR) ?>;
	var AJAX_BASE    = <?= json_encode(UIR . 'Search/unitsearch') ?>;
	var LIMIT        = 250;

	// Column indices (type + activity cols are always hidden at indices 2,3)
	// Scoped member count at 4 when HAS_SCOPE, else absent
	var TYPE_COL     = 2;
	var ACTIVITY_COL = 3;
	var SCOPE_COL    = HAS_SCOPE ? 4 : -1;
	var WEB_COL      = HAS_SCOPE ? 8 : 7;

	var includeInactive  = false;
	var activeTypeFilter = '';
	var currentQuery     = '';
	var table            = null;

	var oneYearAgo = new Date();
	oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);

	function badgeClass(type) {
		if (type === 'Company')  return 'ul-badge-company';
		if (type === 'Event')    return 'ul-badge-event';
		return 'ul-badge-household';
	}

	function webCell(url) {
		if (!url) return '&nbsp;';
		var safe = url.replace(/"/g, '&quot;');
		return '<a href="' + safe + '" target="_blank" rel="noopener" title="' + safe + '"><i class="fas fa-external-link-alt" style="color:#3730a3;font-size:13px"></i></a>';
	}

	function buildRow(u) {
		var uid   = parseInt(u.UnitId) || 0;
		var thumb = HERALDRY + (u.HasHeraldry ? (String(uid).padStart(5, '0') + '.jpg') : '00000.jpg');
		var imgHtml  = '<img class="ul-thumb" src="' + thumb + '" onerror="this.onerror=null;this.src=\'' + HERALDRY + '00000.jpg\'" alt="">';
		var nameHtml = '<a class="ul-name-link" href="' + UIR_BASE + 'Unit/index/' + uid + '">'
			+ $('<span>').text(u.Name || '(Unnamed)').html() + '</a>'
			+ '<span class="ul-type-badge ' + badgeClass(u.Type) + '">' + (u.Type || '') + '</span>';

		var row = [
			imgHtml,
			nameHtml,
			u.Type || '',
			u.LastActivityDate || '',
		];

		if (HAS_SCOPE) {
			row.push(parseInt(u.MemberCount) || 0);
		}

		row.push(
			parseInt(u.ActiveMemberCount) || 0,
			parseInt(u.TotalMemberCount)  || 0,
			$('<span>').text(u.LeaderNames || '').html(),
			webCell(u.Url || '')
		);

		return row;
	}

	function updateStats(units) {
		var oneYear   = oneYearAgo.toISOString().slice(0, 10);
		var active    = 0, companies = 0, households = 0;
		units.forEach(function (u) {
			if (u.LastActivityDate && u.LastActivityDate >= oneYear) {
				active++;
				if (u.Type === 'Company')        companies++;
				else if (u.Type !== 'Event')     households++;
			}
		});
		$('#ul-stat-total').text(active);
		$('#ul-stat-companies').text(companies);
		$('#ul-stat-households').text(households);
		$('#ul-stat-shown').text(units.length);
		$('#ul-stats-row').show();
	}

	function loadData(q) {
		$('#ul-loading').html('<i class="fas fa-spinner fa-spin" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.4;"></i>Loading units…').show();
		$('#ul-table').hide();
		$('#ul-limit-warn').hide();

		var url = AJAX_BASE + '&q=' + encodeURIComponent(q || '');
		if (KID) url += '&KingdomId=' + KID;
		if (PID) url += '&ParkId='    + PID;

		$.getJSON(url, function (units) {
			$('#ul-loading').hide();
			if (!units || !units.length) {
				$('#ul-table').hide();
				$('#ul-loading').html('<i class="fas fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.3;"></i>No units found.').show();
				updateStats([]);
				return;
			}

			// Show limit warning if results hit the cap
			if (units.length >= LIMIT) {
				$('#ul-limit-warn').css('display', 'flex');
			}

			updateStats(units);
			var rows = units.map(buildRow);

			if (table) {
				table.clear().rows.add(rows).draw();
			} else {
				$.fn.dataTable.ext.search.push(function (settings, data) {
					if (settings.nTable.id !== 'ul-table') return true;
					if (includeInactive) return true;
					var lastActivity = data[ACTIVITY_COL];
					if (!lastActivity) return false;
					return new Date(lastActivity) >= oneYearAgo;
				});

				var colDefs = [
					{ targets: 0,            orderable: false, searchable: false },
					{ targets: WEB_COL,      orderable: false, searchable: false },
					{ targets: TYPE_COL,     visible: false },
					{ targets: ACTIVITY_COL, visible: false }
				];

				table = $('#ul-table').DataTable({
					data      : rows,
					dom       : 'lfrtip',
					pageLength: 25,
					order     : [[1, 'asc']],
					columnDefs: colDefs
				});
			}

			$('#ul-table').show();

			if (activeTypeFilter) {
				table.column(TYPE_COL).search('^' + activeTypeFilter + '$', true, false).draw();
			}
		}).fail(function () {
			$('#ul-loading').text('Search failed. Please try again.').show();
		});
	}

	function doSearch() {
		var val = $('#ul-search-input').val().trim();
		if (val.length < 3) return;
		if (val === currentQuery) return;
		currentQuery = val;
		loadData(val);
	}

	function clearSearch() {
		if (table) { table.destroy(); table = null; }
		$('#ul-table').hide();
		$('#ul-stats-row').hide();
		$('#ul-limit-warn').hide();
		$('#ul-loading').html('<i class="fas fa-search" style="font-size:22px;display:block;margin-bottom:8px;opacity:0.3;"></i>Type a name to search units.').show();
		$('#ul-search-input').val('');
		$('#ul-search-clear').hide();
		currentQuery = '';
	}

	$('#ul-search-input').on('input', function () {
		$('#ul-search-clear').css('display', this.value.length ? 'block' : 'none');
	}).on('keydown', function (e) {
		if (e.key === 'Enter') doSearch();
	});

	$('#ul-search-btn').on('click', doSearch);
	$('#ul-search-clear').on('click', clearSearch);

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
	$('#ul-pill-inactive').on('click', function () {
		includeInactive = !includeInactive;
		$(this).toggleClass('active', includeInactive);
		$(this).find('i').toggleClass('fa-eye-slash', !includeInactive).toggleClass('fa-eye', includeInactive);
		if (table) table.draw();
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
