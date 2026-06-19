<!-- =============================================
     PERMISSIONS GRID MOCKUP
     Standalone visualization — no AJAX, no forms
     ============================================= -->

<style>
/* -----------------------------------------------
   PG STYLES (pg- prefix)
   ----------------------------------------------- */

/* Card wrapper */
.pg-card {
	background: #fff;
	border-radius: 10px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 4px 14px rgba(0,0,0,0.04);
	margin-bottom: 24px;
	overflow: hidden;
}

/* Header */
.pg-header {
	background: linear-gradient(135deg, #1a365d 0%, #2d3748 60%, #1a202c 100%);
	padding: 28px 32px 24px;
	position: relative;
}
.pg-header-title {
	font-size: 22px;
	font-weight: 700;
	color: #fff;
	margin: 0 0 4px;
	background: transparent; border: none; padding: 0; border-radius: 0;
	text-shadow: 0 1px 3px rgba(0,0,0,0.4);
}
.pg-header-sub {
	font-size: 13px;
	color: rgba(255,255,255,0.6);
	margin: 0;
}
.pg-header-icon {
	position: absolute;
	right: 32px;
	top: 50%;
	transform: translateY(-50%);
	font-size: 48px;
	color: rgba(255,255,255,0.08);
}

/* Stats summary bar */
.pg-stats-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding: 16px 24px;
	background: #f7fafc;
	border-bottom: 1px solid #e2e8f0;
}
.pg-stat-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	background: #fff;
	border-radius: 8px;
	border: 1px solid #e2e8f0;
	font-size: 13px;
	color: #4a5568;
	white-space: nowrap;
}
.pg-stat-item.pg-stat-total {
	background: #1a365d;
	color: #fff;
	border-color: #1a365d;
	font-weight: 600;
}
.pg-stat-label {
	font-weight: 600;
}
.pg-stat-value {
	font-weight: 700;
	font-size: 15px;
}
.pg-stat-item.pg-stat-total .pg-stat-value {
	color: #fff;
}
.pg-stat-item:not(.pg-stat-total) .pg-stat-value {
	color: #1a365d;
}
.pg-stat-fraction {
	font-size: 11px;
	color: #a0aec0;
	font-weight: 400;
}
.pg-stat-item.pg-stat-total .pg-stat-fraction {
	color: rgba(255,255,255,0.6);
}
.pg-stat-bar-fill {
	width: 48px;
	height: 4px;
	background: #e2e8f0;
	border-radius: 2px;
	overflow: hidden;
	flex-shrink: 0;
}
.pg-stat-bar-fill-inner {
	height: 100%;
	border-radius: 2px;
	background: #38a169;
	transition: width 0.3s ease;
}
.pg-stat-item.pg-stat-total .pg-stat-bar-fill {
	display: none;
}

/* Legend */
.pg-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 20px;
	padding: 14px 24px;
	background: #fff;
	border-bottom: 1px solid #e2e8f0;
	font-size: 12px;
	color: #718096;
}
.pg-legend-item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
.pg-legend-icon-check {
	color: #38a169;
	font-size: 14px;
}
.pg-legend-icon-dash {
	color: #cbd5e0;
	font-size: 14px;
}
.pg-legend-icon-unit {
	color: #dd6b20;
	font-size: 11px;
}
.pg-legend-icon-self {
	color: #4299e1;
	font-size: 13px;
}

/* Table wrapper for horizontal scroll */
.pg-table-wrap {
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
}

/* Grid table */
.pg-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
	min-width: 700px;
}

/* Sticky header */
.pg-table thead {
	position: sticky;
	top: 0;
	z-index: 10;
}
.pg-table thead th {
	background: #1a365d;
	color: #fff;
	font-weight: 600;
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	padding: 12px 16px;
	border: none;
	white-space: nowrap;
	text-align: center;
	position: relative;
}
.pg-table thead th:first-child {
	text-align: left;
	min-width: 280px;
	padding-left: 24px;
}

/* Column hover highlight */
.pg-table colgroup .pg-col-hover {
	background: rgba(66, 153, 225, 0.06);
}

/* Section header rows */
.pg-section-header td {
	background: #edf2f7;
	font-weight: 700;
	font-size: 13px;
	color: #2d3748;
	padding: 10px 24px;
	border-bottom: 1px solid #e2e8f0;
	border-top: 2px solid #e2e8f0;
	cursor: pointer;
	user-select: none;
	-webkit-user-select: none;
}
.pg-section-header td:hover {
	background: #e2e8f0;
}
.pg-section-header .pg-section-toggle {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
.pg-section-header .pg-section-icon {
	width: 24px;
	height: 24px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: #fff;
	border-radius: 6px;
	border: 1px solid #e2e8f0;
	font-size: 11px;
	color: #4a5568;
}
.pg-section-header .pg-chevron {
	transition: transform 0.2s ease;
	font-size: 11px;
	color: #a0aec0;
}
.pg-section-header.pg-collapsed .pg-chevron {
	transform: rotate(-90deg);
}
.pg-section-header .pg-section-count {
	font-weight: 400;
	font-size: 11px;
	color: #a0aec0;
	margin-left: 6px;
}
.pg-section-header .pg-section-note {
	font-weight: 400;
	font-size: 11px;
	color: #dd6b20;
	margin-left: 8px;
	font-style: italic;
}

/* Data rows */
.pg-table tbody tr.pg-row td {
	padding: 8px 16px;
	border-bottom: 1px solid #f0f0f0;
	text-align: center;
	vertical-align: middle;
}
.pg-table tbody tr.pg-row td:first-child {
	text-align: left;
	padding-left: 40px;
	color: #4a5568;
	font-weight: 400;
}

/* Alternating stripes */
.pg-table tbody tr.pg-row:nth-child(odd) td {
	background: #fff;
}
.pg-table tbody tr.pg-row:nth-child(even) td {
	background: #f7fafc;
}

/* Check / dash / unit-owner icons */
.pg-icon-check {
	color: #38a169;
	font-size: 16px;
}
.pg-icon-dash {
	color: #cbd5e0;
	font-size: 14px;
}
.pg-icon-unit {
	color: #dd6b20;
	font-size: 10px;
}
.pg-icon-self {
	color: #4299e1;
	font-size: 14px;
}

/* Collapsed rows */
.pg-table tbody tr.pg-row.pg-hidden {
	display: none;
}

/* Column hover via JS-managed class */
.pg-table tbody td.pg-col-highlight {
	background: rgba(66, 153, 225, 0.06) !important;
}
.pg-table thead th.pg-col-highlight {
	background: #2a4a7f !important;
}

/* Responsive */
@media (max-width: 768px) {
	.pg-header { padding: 20px 16px 16px; }
	.pg-stats-bar { padding: 12px 12px; gap: 8px; }
	.pg-stat-item { padding: 6px 10px; font-size: 12px; }
	.pg-stat-bar-fill { display: none; }
	.pg-legend { padding: 10px 12px; gap: 12px; }
	.pg-header-icon { display: none; }
	.pg-table thead th:first-child { min-width: 200px; }
}

/* Print styles */
@media print {
	.pg-card { box-shadow: none; border: 1px solid #ccc; }
	.pg-section-header td { cursor: default; }
	.pg-table tbody tr.pg-row.pg-hidden { display: table-row !important; }
}
</style>

<div class="pg-card">
	<!-- Header -->
	<div class="pg-header">
		<h2 class="pg-header-title">Kingdom Permissions Grid</h2>
		<p class="pg-header-sub">Current officer capabilities based on existing authorization model</p>
		<i class="fas fa-shield-alt pg-header-icon"></i>
	</div>

	<!-- Stats summary bar -->
	<div class="pg-stats-bar">
		<div class="pg-stat-item pg-stat-total">
			<span class="pg-stat-label">Total Permissions</span>
			<span class="pg-stat-value">116</span>
		</div>
		<div class="pg-stat-item">
			<span class="pg-stat-label">Monarch</span>
			<span class="pg-stat-value">99</span>
			<span class="pg-stat-fraction">/ 116</span>
			<span class="pg-stat-bar-fill"><span class="pg-stat-bar-fill-inner" style="width:85.3%"></span></span>
		</div>
		<div class="pg-stat-item">
			<span class="pg-stat-label">Regent</span>
			<span class="pg-stat-value">99</span>
			<span class="pg-stat-fraction">/ 116</span>
			<span class="pg-stat-bar-fill"><span class="pg-stat-bar-fill-inner" style="width:85.3%"></span></span>
		</div>
		<div class="pg-stat-item">
			<span class="pg-stat-label">Prime Minister</span>
			<span class="pg-stat-value">99</span>
			<span class="pg-stat-fraction">/ 116</span>
			<span class="pg-stat-bar-fill"><span class="pg-stat-bar-fill-inner" style="width:85.3%"></span></span>
		</div>
		<div class="pg-stat-item">
			<span class="pg-stat-label">Champion</span>
			<span class="pg-stat-value">13</span>
			<span class="pg-stat-fraction">/ 116</span>
			<span class="pg-stat-bar-fill"><span class="pg-stat-bar-fill-inner" style="width:11.2%"></span></span>
		</div>
		<div class="pg-stat-item">
			<span class="pg-stat-label">GMR</span>
			<span class="pg-stat-value">13</span>
			<span class="pg-stat-fraction">/ 116</span>
			<span class="pg-stat-bar-fill"><span class="pg-stat-bar-fill-inner" style="width:11.2%"></span></span>
		</div>
			<div class="pg-stat-item">
				<span class="pg-stat-label">ORK Admin</span>
				<span class="pg-stat-value">116</span>
				<span class="pg-stat-fraction">/ 116</span>
				<span class="pg-stat-bar-fill"><span class="pg-stat-bar-fill-inner" style="width:100%"></span></span>
			</div>
	</div>

	<!-- Legend -->
	<div class="pg-legend">
		<span class="pg-legend-item">
			<i class="fas fa-check-circle pg-legend-icon-check"></i>
			Officer can perform this action
		</span>
		<span class="pg-legend-item">
			<i class="fas fa-minus pg-legend-icon-dash"></i>
			Officer cannot perform this action
		</span>
		<span class="pg-legend-item">
			<i class="fas fa-user-check pg-legend-icon-self"></i>
			Self-action (any logged-in user, own profile only)
		</span>
		<span class="pg-legend-item">
			<i class="fas fa-circle pg-legend-icon-unit"></i>
			Requires unit-level authorization (unit owner/founder)
		</span>
	</div>

	<!-- Grid table -->
	<div class="pg-table-wrap">
		<table class="pg-table" id="pgPermissionsTable">
			<colgroup>
				<col>
				<col class="pg-col" data-col="1">
				<col class="pg-col" data-col="2">
				<col class="pg-col" data-col="3">
				<col class="pg-col" data-col="4">
				<col class="pg-col" data-col="5">
				<col class="pg-col" data-col="6">
			</colgroup>
			<thead>
				<tr>
					<th>Permission</th>
					<th data-col="1">Monarch</th>
					<th data-col="2">Regent</th>
					<th data-col="3">Prime Minister</th>
					<th data-col="4">Champion</th>
					<th data-col="5">GMR</th>
					<th data-col="6">ORK Admin</th>
				</tr>
			</thead>
			<tbody>

				<!-- ===== KINGDOM ===== -->
				<tr class="pg-section-header" data-section="kingdom">
					<td colspan="7">
						<span class="pg-section-toggle">
							<i class="fas fa-chevron-down pg-chevron"></i>
							<span class="pg-section-icon"><i class="fas fa-crown"></i></span>
							Kingdom
							<span class="pg-section-count">23 permissions</span>
						</span>
					</td>
				</tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Edit Kingdom Details</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Create Park Title</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Edit Park Title</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Delete Park Title</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Create Kingdom Award</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Edit Kingdom Award</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Delete Kingdom Award</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Set Kingdom Officer</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Vacate Kingdom Officer</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Add Officer History</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Edit Officer History</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Delete Officer History</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Set Kingdom Heraldry</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Remove Kingdom Heraldry</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Update Kingdom Config</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Set Recs Visibility</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Add Kingdom Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Remove Kingdom Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Bulk Edit Parks</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom">
					<td>Claim Park (Transfer)</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

				<tr class="pg-row" data-section="kingdom"><td>Create Kingdom</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom"><td>Set Kingdom Status</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="kingdom"><td>Set Kingdom Parent</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

					<!-- ===== PLAYER ===== -->
					<tr class="pg-section-header" data-section="player">
						<td colspan="7">
							<span class="pg-section-toggle">
								<i class="fas fa-chevron-down pg-chevron"></i>
								<span class="pg-section-icon"><i class="fas fa-user"></i></span>
									Player
									<span class="pg-section-count">39 permissions</span>
								</span>
							</td>
						</tr>
					<tr class="pg-row" data-section="player"><td>Create Player</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Edit Own Player Details</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Edit Other Player Details</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Move Player</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Merge Players</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Player Suspension</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Own Heraldry</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Other Player Heraldry</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Remove Own Heraldry</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Remove Other Player Heraldry</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Own Image</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Other Player Image</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Remove Own Image</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Remove Other Player Image</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Waiver</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Restriction Flag</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Update Reeve Qualification</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Update Corpora Qualification</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Add Own Note</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Add Note to Other Player</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Edit Own Note</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Edit Other Player Note</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Remove Own Note</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Remove Other Player Note</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Add Award to Player</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Edit Player Award</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Revoke Player Award</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Revoke All Player Awards</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Delete Player Award</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Reconcile Player Award</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Add Award Recommendation</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Delete Own Recommendation</td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-user-check pg-icon-self"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Delete Other's Recommendation</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Add Dues</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Revoke Dues</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
					<tr class="pg-row" data-section="player"><td>Set Reconciled Credits</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>


			<tr class="pg-row" data-section="player"><td>Set Player Active Status</td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
			<tr class="pg-row" data-section="player"><td>Set Player Ban</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
			<tr class="pg-row" data-section="player"><td>Reset All Waivers</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

				<!-- ===== PARK ===== -->
				<tr class="pg-section-header" data-section="park">
					<td colspan="7">
						<span class="pg-section-toggle">
							<i class="fas fa-chevron-down pg-chevron"></i>
							<span class="pg-section-icon"><i class="fas fa-tree"></i></span>
							Park
							<span class="pg-section-count">18 permissions</span>
						</span>
					</td>
				</tr>
				<tr class="pg-row" data-section="park">
					<td>Create Park</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Edit Park Details</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Retire Park</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Restore Park</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Set Park Officer</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Vacate Park Officer</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Add Park Day</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Edit Park Day</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Delete Park Day</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Set Park Heraldry</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Remove Park Heraldry</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Add Park Officer History</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Edit Park Officer History</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Delete Park Officer History</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Add Park Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="park">
					<td>Remove Park Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

			<tr class="pg-row" data-section="park"><td>Transfer Park</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
			<tr class="pg-row" data-section="park"><td>Merge Parks</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

				<!-- ===== EVENT ===== -->
				<tr class="pg-section-header" data-section="event">
					<td colspan="7">
						<span class="pg-section-toggle">
							<i class="fas fa-chevron-down pg-chevron"></i>
							<span class="pg-section-icon"><i class="fas fa-calendar-alt"></i></span>
							Event
							<span class="pg-section-count">23 permissions</span>
						</span>
					</td>
				</tr>
				<tr class="pg-row" data-section="event">
					<td>Create Event</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Create Event Detail</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Edit Event Details</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Set Current Event Detail</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Delete Event Detail</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Delete Event</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Edit Event</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Set Event Heraldry</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Remove Event Heraldry</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Add Attendance</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Edit Attendance</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Delete Attendance</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Add/Toggle RSVP</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Remove RSVP</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Create Tournament</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Add Tournament Bracket</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Add Tournament Participant</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Delete Tournament</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Reconcile Event Attendance</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Add Event Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="event">
					<td>Remove Event Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

			<tr class="pg-row" data-section="event"><td>Create Attendance Class</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
			<tr class="pg-row" data-section="event"><td>Edit Attendance Class</td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-minus pg-icon-dash"></i></td><td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

				<!-- ===== UNIT ===== -->
				<tr class="pg-section-header" data-section="unit">
					<td colspan="7">
						<span class="pg-section-toggle">
							<i class="fas fa-chevron-down pg-chevron"></i>
							<span class="pg-section-icon"><i class="fas fa-users"></i></span>
							Unit
							<span class="pg-section-count">5 permissions</span>
						</span>
					</td>
				</tr>
				<tr class="pg-row" data-section="unit">
					<td>Create Unit</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit">
					<td>Convert Unit Type</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit">
					<td>Add Unit Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit">
					<td>Remove Unit Authorization</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit">
					<td>Add Unit Award</td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-check-circle pg-icon-check"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
					<td><i class="fas fa-minus pg-icon-dash"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

				<!-- ===== UNIT OWNER ACTIONS ===== -->
				<tr class="pg-section-header" data-section="unit-owner">
					<td colspan="7">
						<span class="pg-section-toggle">
							<i class="fas fa-chevron-down pg-chevron"></i>
							<span class="pg-section-icon"><i class="fas fa-user-shield"></i></span>
							Unit Owner Actions
							<span class="pg-section-count">8 permissions</span>
							<span class="pg-section-note">Managed by unit-level auth (AUTH_UNIT), not kingdom officers</span>
						</span>
					</td>
				</tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Edit Unit Details</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Add Unit Member</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Set Unit Member</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Retire Unit Member</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Remove Unit Member</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Upload Unit Heraldry</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Remove Unit Heraldry</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>
				<tr class="pg-row" data-section="unit-owner">
					<td>Merge Units</td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
					<td><i class="fas fa-circle pg-icon-unit"></i></td>
				<td><i class="fas fa-check-circle pg-icon-check"></i></td></tr>

			</tbody>
		</table>
	</div>
</div>

<!-- =============================================
     PG INLINE JAVASCRIPT
     ============================================= -->
<script>
(function() {
	'use strict';

	var table = document.getElementById('pgPermissionsTable');
	if (!table) return;

	/* ----- Section collapse/expand ----- */
	var sectionHeaders = table.querySelectorAll('.pg-section-header');
	sectionHeaders.forEach(function(header) {
		header.addEventListener('click', function() {
			var section = this.getAttribute('data-section');
			var isCollapsed = this.classList.toggle('pg-collapsed');
			var rows = table.querySelectorAll('tr.pg-row[data-section="' + section + '"]');
			rows.forEach(function(row) {
				if (isCollapsed) {
					row.classList.add('pg-hidden');
				} else {
					row.classList.remove('pg-hidden');
				}
			});
		});
	});

	/* ----- Column hover highlight ----- */
	var allCells = table.querySelectorAll('th[data-col], td');
	table.addEventListener('mouseover', function(e) {
		var cell = e.target.closest('td, th');
		if (!cell) return;
		var row = cell.parentElement;
		var idx = Array.prototype.indexOf.call(row.children, cell);
		if (idx < 1) return; // skip permission name column
		clearColumnHighlight();
		highlightColumn(idx);
	});
	table.addEventListener('mouseleave', function() {
		clearColumnHighlight();
	});

	function highlightColumn(colIndex) {
		var rows = table.querySelectorAll('tr');
		rows.forEach(function(row) {
			if (row.classList.contains('pg-section-header')) return;
			var cell = row.children[colIndex];
			if (cell) cell.classList.add('pg-col-highlight');
		});
	}
	function clearColumnHighlight() {
		table.querySelectorAll('.pg-col-highlight').forEach(function(el) {
			el.classList.remove('pg-col-highlight');
		});
	}
})();
</script>
