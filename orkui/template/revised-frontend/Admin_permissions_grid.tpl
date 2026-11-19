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
.pg-legend-icon-self {
	color: #4299e1;
	font-size: 13px;
}

/* Table wrapper. overflow-x:auto alone computed overflow-y to auto, which made this
   box the sticky containing block for the thead -- while never scrolling vertically,
   so the header never stuck to anything. Giving it a height makes it a real scroller
   in both axes, which is what the sticky header and the sticky first column need. */
.pg-table-wrap {
	overflow: auto;
	max-height: calc(100vh - 220px);
	-webkit-overflow-scrolling: touch;
	position: relative;
}

/* Grid table */
.pg-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
	min-width: 700px;
}

/* Sticky header + frozen permission column: scrolled down and right at once, the
   grid used to show neither a row label nor a column label. Both axes stay put now. */
/* The 8/9/2/6 z-indexes below are a table-local stacking order only, deliberately far
   below --ork-z-nav (9999) so the sticky header passes under the fixed nav. */
.pg-table thead th {
	position: sticky;
	top: 0;
	z-index: 8;
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
}
.pg-table thead th:first-child {
	text-align: left;
	min-width: 280px;
	padding-left: 24px;
	left: 0;
	z-index: 9;
}
.pg-table tbody tr.pg-row td:first-child {
	position: sticky;
	left: 0;
	z-index: 2;
	border-right: 1px solid #e2e8f0;
}
/* A sticky cell is its own stacking context, so a tooltip inside one paints under the
   next sticky cell down until the hovered cell itself is lifted. */
.pg-table tbody tr.pg-row td:first-child:hover,
.pg-table tbody tr.pg-row td:first-child:focus-within {
	z-index: 6;
}
/* A colspan cell is as wide as the table, so sticking the cell itself parks the label
   off screen; the inner span is what has to stay. */
.pg-section-header .pg-section-toggle {
	position: sticky;
	left: 24px;
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

/* Alternating stripes, stamped per section by PHP: nth-child() counted the interleaved
   section-header rows, so the banding restarted on an arbitrary parity each section.
   These backgrounds are also what makes the frozen first column opaque. */
.pg-table tbody tr.pg-stripe-odd td {
	background: #fff;
}
.pg-table tbody tr.pg-stripe-even td {
	background: #f7fafc;
}

/* Permission name cell: the raw key is the identifier officers grep for, so it is
   rendered, not hidden behind a hover tip. */
.pg-perm-name {
	display: block;
	font-weight: 500;
	color: #2d3748;
}
.pg-perm-key {
	display: block;
	font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	font-size: 11px;
	color: #a0aec0;
	word-break: break-all;
}
.pg-perm-flag {
	display: inline-block;
	margin-top: 3px;
	padding: 1px 7px;
	border-radius: 10px;
	font-size: 10px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	white-space: normal;
}
.pg-perm-flag-reserved {
	background: #fffaf0;
	border: 1px solid #f6ad55;
	color: #c05621;
}
.pg-perm-flag-none {
	background: #fff5f5;
	border: 1px solid #feb2b2;
	color: #c53030;
}

/* Filter / highlight bar */
.pg-filter-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 12px 24px;
	background: #f7fafc;
	border-bottom: 1px solid #e2e8f0;
}
.pg-filter-field {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	color: #4a5568;
}
.pg-filter-field input,
.pg-filter-field select {
	font-size: 13px;
	padding: 6px 10px;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	background: #fff;
	color: #2d3748;
	min-width: 220px;
}
.pg-filter-field select {
	min-width: 180px;
}
.pg-filter-status {
	font-size: 12px;
	color: #718096;
}
/* Non-selected columns dim so one role can be read straight down. */
/* Dim the cell CONTENT, never the element: element opacity takes the cell background
   with it, and a see-through sticky header shows the rows scrolling under it. */
.pg-table th.pg-dim {
	color: rgba(255, 255, 255, 0.4);
}
.pg-table td.pg-dim > i {
	opacity: 0.28;
}

/* Tooltips.
   revised.css IS loaded on this page (verified in the rendered HTML), and its generic
   rule -- [data-tip]:not(.a):not(.b):not(.c):not(.d):not(.e):hover::after, specificity
   (0,7,1) -- matches every element here, because none of those five classes is a .pg-*
   one. It outranks this block's (0,3,1), so without !important the generic rule wins
   every property the two share: its min-width:max-content beats the max-width below and
   the tip renders as one long non-wrapping line (the house rule says data-tip must
   wrap), and its left:50%/transform/bottom beat the anchoring here, which also defeats
   the .pg-tip-up flip and the last-column right-anchor further down. !important is the
   established way out -- same precedent as .kn-emod-check-label in revised.css and
   .ka-action-card-orkadmin in admin-console.css. Only the properties the generic rule
   actually sets are marked; the rest need no help. */
.pg-card [data-tip] {
	position: relative;
}
.pg-card [data-tip]:hover::after,
.pg-card [data-tip]:focus-visible::after {
	content: attr(data-tip);
	position: absolute;
	left: 12px !important;
	right: auto;
	top: calc(100% - 4px);
	bottom: auto !important;
	transform: none !important;
	z-index: var(--z-tooltip);
	width: max-content;
	min-width: 0 !important;
	max-width: 320px !important;
	white-space: pre-line !important;
	text-align: left;
	text-transform: none;
	letter-spacing: 0;
	font-size: 11px;
	font-weight: 400;
	line-height: 1.5;
	padding: 8px 10px;
	border-radius: 6px;
	background: #1a202c;
	color: #fff;
	box-shadow: 0 4px 14px rgba(0,0,0,0.28);
	pointer-events: none;
}
/* Near the foot of the scroller a downward tip is clipped by the wrap; the inline
   script flips these upward. */
.pg-card [data-tip].pg-tip-up:hover::after,
.pg-card [data-tip].pg-tip-up:focus-visible::after {
	top: auto !important;
	bottom: calc(100% - 4px) !important;
}
.pg-table th[data-tip]:last-child:hover::after,
.pg-table td[data-tip]:last-child:hover::after {
	left: auto !important;
	right: 12px !important;
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
.pg-icon-self {
	color: #4299e1;
	font-size: 15px;
}
.pg-icon-lock {
	color: #dd6b20;
	font-size: 13px;
}

/* Collapsed or filtered-out rows */
.pg-table tbody tr.pg-hidden {
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
				.pg-legend { padding: 10px 12px; gap: 12px; }
	.pg-header-icon { display: none; }
	.pg-table thead th:first-child { min-width: 200px; }
	.pg-table-wrap { max-height: calc(100vh - 160px); }
	.pg-filter-bar { padding: 10px 12px; }
	.pg-filter-field input, .pg-filter-field select { min-width: 0; width: 100%; }
	.pg-filter-field { flex: 1 1 100%; }
}

/* Print styles */
@media print {
	.pg-card { box-shadow: none; border: 1px solid #ccc; }
	.pg-section-header td { cursor: default; }
	.pg-table tbody tr.pg-hidden { display: table-row !important; }
	.pg-table-wrap { max-height: none; overflow: visible; }
}

/* -----------------------------------------------
   DARK MODE OVERRIDES (pg-*)
   .pg-header (navy gradient) and .pg-table thead th
   are intentionally left alone — correct in both themes.
   ----------------------------------------------- */
/* The card heading sits on the navy header, not on the page. orkui.css paints EVERY
   h1-h6 with a grey pill (background-color, border, padding) and its dark variant --
   html[data-theme="dark"] h2 -- has specificity (0,1,1), which outranks the plain
   .pg-header-title class that resets it for light mode. Without this the title rendered
   as a grey slab across the header in dark mode only. Same reset, matching specificity. */
html[data-theme="dark"] .pg-header-title,
html[data-theme="dark"] .pg-header .pg-header-title {
	background: none;
	background-color: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
	color: #fff;
	text-shadow: 0 1px 3px rgba(0,0,0,0.4);
}
html[data-theme="dark"] .pg-card {
	background: var(--ork-card-bg);
}
html[data-theme="dark"] .pg-legend {
	background: var(--ork-card-bg);
	border-bottom-color: var(--ork-border);
	color: var(--ork-text-muted);
}
html[data-theme="dark"] .pg-legend-icon-dash {
	color: var(--ork-text-lighter);
}
html[data-theme="dark"] .pg-section-header td {
	background: var(--ork-bg-tertiary);
	color: var(--ork-text);
	border-top-color: var(--ork-border);
	border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .pg-section-header td:hover {
	background: var(--ork-border);
}
html[data-theme="dark"] .pg-section-header .pg-section-icon {
	background: var(--ork-card-bg);
	border-color: var(--ork-border);
	color: var(--ork-text-secondary);
}
html[data-theme="dark"] .pg-section-header .pg-chevron,
html[data-theme="dark"] .pg-section-header .pg-section-count {
	color: var(--ork-text-muted);
}
html[data-theme="dark"] .pg-table tbody tr.pg-stripe-odd td {
	background: var(--ork-card-bg);
}
html[data-theme="dark"] .pg-table tbody tr.pg-stripe-even td {
	background: var(--ork-bg-tertiary);
}
/* The frozen column is only readable while its background stays opaque. */
html[data-theme="dark"] .pg-table tbody tr.pg-row td:first-child {
	border-right-color: var(--ork-border);
}
html[data-theme="dark"] .pg-perm-name {
	color: var(--ork-text);
}
html[data-theme="dark"] .pg-perm-key {
	color: var(--ork-text-muted);
}
html[data-theme="dark"] .pg-perm-flag-reserved {
	background: rgba(221,107,32,0.14);
	border-color: rgba(246,173,85,0.55);
	color: #f6ad55;
}
html[data-theme="dark"] .pg-perm-flag-none {
	background: rgba(197,48,48,0.16);
	border-color: rgba(254,178,178,0.45);
	color: #feb2b2;
}
html[data-theme="dark"] .pg-filter-bar {
	background: var(--ork-bg-secondary);
	border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .pg-filter-field {
	color: var(--ork-text-secondary);
}
html[data-theme="dark"] .pg-filter-field input,
html[data-theme="dark"] .pg-filter-field select {
	background: var(--ork-card-bg);
	border-color: var(--ork-border);
	color: var(--ork-text);
}
html[data-theme="dark"] .pg-filter-status {
	color: var(--ork-text-muted);
}
html[data-theme="dark"] .pg-card [data-tip]:hover::after,
html[data-theme="dark"] .pg-card [data-tip]:focus-visible::after {
	background: var(--ork-bg-tertiary);
	color: var(--ork-text);
	border: 1px solid var(--ork-border);
}
html[data-theme="dark"] .pg-table tbody tr.pg-row td {
	border-bottom-color: var(--ork-border);
}
html[data-theme="dark"] .pg-table tbody tr.pg-row td:first-child {
	color: var(--ork-text-secondary);
}
html[data-theme="dark"] .pg-icon-dash {
	color: var(--ork-text-lighter);
}
/* The 6% blue column-hover tint reads over #fff/#f7fafc rows but disappears
   over the dark stripes; the JS at the foot of this file adds/removes
   .pg-col-highlight on mouseover, so the cue has to survive the theme. */
html[data-theme="dark"] .pg-table tbody td.pg-col-highlight {
	background: rgba(66, 153, 225, 0.18) !important;
}
/* #dd6b20 on the dark section header falls to ~2.7:1; lift it to the
   badge-orange text token's dark value. */
html[data-theme="dark"] .pg-section-header .pg-section-note {
	color: #f6ad55;
}
</style>
<?php
/*
 * Live permissions grid.
 *
 * This page previously rendered a hand-written mock-up: 1,238 lines containing no PHP
 * at all, every role column and every check mark invented. It is auth-gated and linked
 * from the kingdom console, so a kingdom read fabricated role definitions as a statement
 * about its own access. Everything below now derives from PermissionRegistry (the
 * permissions) and ork_role_permission (the grants), for THIS kingdom's roles.
 *
 * Data in (from Controller_Admin::permissionsgrid):
 *   $PgPermissions  key => [display_name, description, scope_type, category]
 *   $PgRoles        list of role records (system roles + this kingdom's custom roles)
 *   $PgGranted      permission key => [role_id => true]
 *   $PgScopeType    'Kingdom' | 'Park'
 *   $PgScopeName    the kingdom's or the park's name, per $PgScopeType
 *   $PgKingdomName  the kingdom that OWNS these roles (same as $PgScopeName for a
 *                   kingdom visit; the park's parent kingdom for a park visit)
 */
$pgPermissions = is_array($PgPermissions ?? null) ? $PgPermissions : [];
$pgRoles       = is_array($PgRoles ?? null) ? $PgRoles : [];
$pgGranted     = is_array($PgGranted ?? null) ? $PgGranted : [];
$pgScopeType   = (string)($PgScopeType ?? 'Kingdom');
$pgScopeName   = (string)($PgScopeName ?? '');
$pgKingdomName = (string)($PgKingdomName ?? '');
$pgTotal       = count($pgPermissions);

/* Reserved to the ORK team. These keys live in the registry on purpose and are
   deliberately NOT enforced against a kingdom role: Admin_kingdom.tpl renders both
   tiles greyed with "This action must be completed by an ORK Administrator." The
   crown roles nevertheless carry the rows (the seed CROSS JOIN grants every non-global
   key), so drawing them from ork_role_permission alone would tick Create Park for the
   Monarch one click from the tile that says otherwise. The grid states the policy
   instead, and keeps them out of every role's held count.

   The set is derived from the registry so a future ORK-reserved key flows into the
   grid without a template edit, but it is NOT derived from the registry display name
   ALONE. PermissionRegistry has no structured reserved flag today, so the only marker
   it carries is the "(ORK Administrator)" parenthetical on the display name -- an
   editable UI string. Rewording it ("(ORK Admin)", "(ORK Administrator only)", a
   trailing period, dropping it) would silently empty this set, and the failure runs in
   the dangerous direction: the two keys would rejoin every role's held count and lose
   the "ORK Administrator only" flag, so the grid would tick Create Park for the Monarch
   one click from the tile that says the opposite. That is exactly what the paragraph
   above exists to prevent, and nothing else guards it (RbacRegistryParityTest keeps its
   own UNENFORCED_BY_DESIGN copy of these keys but asserts nothing about the suffix).

   So the policy has an explicit floor -- the keys that are reserved TODAY, which this
   template will flag whatever the registry's wording does -- plus a tolerant read of
   the marker, which is what lets a NEW reserved key arrive without touching this file.
   The two are unioned: neither half can quietly shrink the set.

   DURABLE FIX (needs a file outside this template's scope): give the registry entries a
   structured reserved flag -- a fifth element, or PermissionRegistry::GetReservedKeys()
   surfaced through RBACService -- and this block becomes a straight read of it, with the
   floor and the string parse both deleted.

   Global keys are excluded because they are ORK-only by scope already and are counted
   as such below. */
$pgReservedFloor = [
    'kingdom.park.create' => true,
    'kingdom.park.claim'  => true,
];
$pgReserved = [];
foreach ($pgPermissions as $pgKey => $pgDef) {
    if ((string)($pgDef[2] ?? '') === 'global') {
        continue;
    }
    if (isset($pgReservedFloor[$pgKey])
        || preg_match('/\(\s*ORK\s+Admin(?:istrator)?\b[^)]*\)[\s.]*$/i', (string)($pgDef[0] ?? ''))) {
        $pgReserved[$pgKey] = true;
    }
}

/* The ORK Administrator role holds only the global keys in ork_role_permission --
   admins reach everything else through RBACService::HasPermission()'s IsAdmin()
   short-circuit, not through a grant. Rendering its column from the grant table alone
   put "8 / 79" beside a legend line promising the exact opposite. */
$pgAdminRoleId = 0;
foreach ($pgRoles as $pgRole) {
    if ((string)($pgRole['Name'] ?? '') === 'ork_admin') {
        $pgAdminRoleId = (int)$pgRole['RoleId'];
    }
}

/* Section order and headings. Keyed by the registry's own scope_type so a new
   permission lands in a section without this template being touched; a scope the map
   does not know still renders, under its raw name, rather than disappearing. */
$pgSections = [
    'global'  => ['label' => 'Installation (ORK Administrators)', 'icon' => 'fa-server'],
    'kingdom' => ['label' => 'Kingdom',                            'icon' => 'fa-crown'],
    'park'    => ['label' => 'Park, Players & Records',            'icon' => 'fa-map-marker-alt'],
    'event'   => ['label' => 'Events & Tournaments',               'icon' => 'fa-calendar-day'],
    'unit'    => ['label' => 'Units',                              'icon' => 'fa-shield-alt'],
];

/* Crown offices first. GetAvailableRoles() orders system roles alphabetically, which
   buried Monarch, Prime Minister and Regent in the middle of eleven columns with
   Attendance Clerk and Award Manager ahead of them. These three are the roles a kingdom
   reads the grid to check, so they lead -- in Corpora order, not alphabetical. Everything
   else keeps the order it arrived in. Reindexed with array_values() because $pgIdx is the
   column number the colgroup, the header, the body cells and the focus-role filter all
   share; a gap in the keys would misalign the column-hover and role-focus features. */
$pgCrownOrder = ['monarch' => 0, 'regent' => 1, 'prime_minister' => 2];
$pgCrown = [];
$pgRest  = [];
foreach ($pgRoles as $pgRole) {
    $pgName = (string)($pgRole['Name'] ?? '');
    if (isset($pgCrownOrder[$pgName])) {
        $pgCrown[$pgCrownOrder[$pgName]] = $pgRole;
    } else {
        $pgRest[] = $pgRole;
    }
}
ksort($pgCrown);
$pgRoles = array_values(array_merge(array_values($pgCrown), $pgRest));

/* Sections that start closed. The installation section is ORK-team operations -- nothing
   a kingdom officer can hold or act on -- so it opens collapsed and stays one click away
   rather than pushing the kingdom's own permissions below the fold. */
$pgCollapsedByDefault = ['global' => true];

$pgBuckets = [];
foreach ($pgPermissions as $pgKey => $pgDef) {
    $pgBuckets[(string)($pgDef[2] ?? 'other')][$pgKey] = $pgDef;
}
foreach (array_keys($pgBuckets) as $pgSlug) {
    if (!isset($pgSections[$pgSlug])) {
        $pgSections[$pgSlug] = ['label' => ucfirst($pgSlug), 'icon' => 'fa-cube'];
    }
}

/* Permissions no role grants. Start from every key a kingdom role COULD hold -- global
   keys are ORK-team only and the two reserved keys are policy-excluded, so neither counts
   as a gap -- then strike out everything some role actually grants. What survives is a
   capability nobody in this kingdom can exercise, which the body marks so it reads as a
   gap rather than a row of dashes.

   The per-role tallies that used to share this loop are gone with the summary bar above
   the grid: the same numbers are readable straight down each column, and the bar cost a
   full screen of height before the first permission. */
$pgNoRole = [];
foreach ($pgPermissions as $pgKey => $pgDef) {
    if (!isset($pgReserved[$pgKey]) && (string)($pgDef[2] ?? '') !== 'global') {
        $pgNoRole[$pgKey] = true;
    }
}
$pgRoleIds = [];
foreach ($pgRoles as $pgRole) {
    $pgRoleIds[(int)$pgRole['RoleId']] = true;
}
foreach ($pgGranted as $pgGrantKey => $pgRoleSet) {
    foreach (array_keys($pgRoleSet) as $pgRoleId) {
        if (isset($pgRoleIds[$pgRoleId])) {
            unset($pgNoRole[$pgGrantKey]);
        }
    }
}
?>
<div class="pg-card">
	<!-- Header -->
	<div class="pg-header">
		<h2 class="pg-header-title">Permissions Grid</h2>
		<p class="pg-header-sub">
			<?php if ($pgScopeType === 'Park' && $pgScopeName !== '' && $pgKingdomName !== ''): ?>
				Roles are defined by <?= htmlspecialchars($pgKingdomName) ?>, the kingdom <?= htmlspecialchars($pgScopeName) ?> belongs to &mdash; this is what each one can do.
				A role granted at park scope confers only its park, event and unit permissions.
			<?php elseif ($pgScopeName !== ''): ?>
				Roles available to <?= htmlspecialchars($pgScopeName) ?> and what each one can do
			<?php else: ?>
				Roles available here and what each one can do
			<?php endif; ?>
		</p>
		<i class="fas fa-shield-alt pg-header-icon"></i>
	</div>

	<?php if ($pgTotal === 0 || count($pgRoles) === 0): ?>
		<div class="pg-legend">
			<span class="pg-legend-item">No roles are defined for this kingdom yet.</span>
		</div>
	<?php else: ?>

	<!-- Legend -->
	<div class="pg-legend">
		<span class="pg-legend-item">
			<i class="fas fa-check-circle pg-legend-icon-check"></i>
			This role grants the permission
		</span>
		<span class="pg-legend-item">
			<i class="fas fa-minus pg-legend-icon-dash"></i>
			This role does not grant it
		</span>
		<span class="pg-legend-item">
			<i class="fas fa-user-check pg-legend-icon-self"></i>
			ORK Administrators hold every permission, with or without a role
		</span>
		<span class="pg-legend-item">
			<i class="fas fa-lock pg-icon-lock"></i>
			Reserved to the ORK team &mdash; no kingdom role can exercise it
		</span>
	</div>

	<!-- Filter / highlight bar -->
	<div class="pg-filter-bar">
		<label class="pg-filter-field" for="pgFilterText">
			<i class="fas fa-search"></i>
			<input type="text" id="pgFilterText" placeholder="Filter by name or key" autocomplete="off">
		</label>
		<label class="pg-filter-field" for="pgFilterRole">
			Focus role
			<select id="pgFilterRole">
				<option value="">All roles</option>
				<?php foreach ($pgRoles as $pgIdx => $pgRole): ?>
				<option value="<?= (int)$pgIdx + 1 ?>"><?= htmlspecialchars($pgRole['DisplayName']) ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<span class="pg-filter-status" id="pgFilterStatus" aria-live="polite"></span>
	</div>

	<!-- Grid table -->
	<div class="pg-table-wrap">
		<table class="pg-table" id="pgPermissionsTable">
			<colgroup>
				<col>
				<?php foreach ($pgRoles as $pgIdx => $pgRole): ?>
				<col class="pg-col" data-col="<?= (int)$pgIdx + 1 ?>">
				<?php endforeach; ?>
			</colgroup>
			<thead>
				<tr>
					<th>Permission</th>
					<?php foreach ($pgRoles as $pgIdx => $pgRole): ?>
					<th data-col="<?= (int)$pgIdx + 1 ?>"<?= empty($pgRole['IsSystem']) ? ' data-tip="Custom role, defined by this kingdom"' : '' ?>><?= htmlspecialchars($pgRole['DisplayName']) ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($pgSections as $pgSlug => $pgSection): ?>
					<?php if (empty($pgBuckets[$pgSlug])) {
                        continue;
                    } ?>
					<?php $pgSectionClosed = !empty($pgCollapsedByDefault[$pgSlug]); ?>
				<tr class="pg-section-header<?= $pgSectionClosed ? ' pg-collapsed' : '' ?>" data-section="<?= htmlspecialchars($pgSlug) ?>" role="button" tabindex="0" aria-expanded="<?= $pgSectionClosed ? 'false' : 'true' ?>">
					<td colspan="<?= count($pgRoles) + 1 ?>">
						<span class="pg-section-toggle">
							<i class="fas fa-chevron-down pg-chevron"></i>
							<span class="pg-section-icon"><i class="fas <?= htmlspecialchars($pgSection['icon']) ?>"></i></span>
							<?= htmlspecialchars($pgSection['label']) ?>
							<span class="pg-section-count" data-total="<?= count($pgBuckets[$pgSlug]) ?>"><?= count($pgBuckets[$pgSlug]) ?> permissions</span>
						</span>
					</td>
				</tr>
					<?php $pgStripe = 0; ?>
					<?php foreach ($pgBuckets[$pgSlug] as $pgKey => $pgDef): ?>
						<?php
                        $pgIsReserved = isset($pgReserved[$pgKey]);
                        $pgStripe++;
                        ?>
				<?php /* pg-hidden is emitted server-side, not left to the script: applyVisibility()
                         only runs from the toggle and filter handlers, never at init, so a
                         collapsed-by-default section would render open and then blink shut --
                         or stay open entirely with JS off. Print styles force these back
                         visible, so a printed grid is still complete. */ ?>
				<tr class="pg-row <?= $pgStripe % 2 ? 'pg-stripe-odd' : 'pg-stripe-even' ?><?= $pgSectionClosed ? ' pg-hidden' : '' ?>" data-section="<?= htmlspecialchars($pgSlug) ?>" data-search="<?= htmlspecialchars(strtolower((string)($pgDef[0] ?? '') . ' ' . $pgKey), ENT_QUOTES) ?>">
					<td<?= ($pgDef[1] ?? '') !== '' ? ' data-tip="' . htmlspecialchars((string)$pgDef[1], ENT_QUOTES) . '" tabindex="0"' : '' ?>>
						<span class="pg-perm-name"><?= htmlspecialchars((string)($pgDef[0] ?? $pgKey)) ?></span>
						<span class="pg-perm-key"><?= htmlspecialchars($pgKey) ?></span>
						<?php if ($pgIsReserved): ?>
						<span class="pg-perm-flag pg-perm-flag-reserved">ORK Administrator only</span>
						<?php elseif (isset($pgNoRole[$pgKey])): ?>
						<span class="pg-perm-flag pg-perm-flag-none">No role grants this</span>
						<?php endif; ?>
					</td>
						<?php foreach ($pgRoles as $pgRole): ?>
							<?php
                            $pgIsAdminRole = $pgAdminRoleId > 0 && (int)$pgRole['RoleId'] === $pgAdminRoleId;
                            if ($pgIsAdminRole) {
                                // Not read from the grant table: an ORK Administrator holds
                                // everything through the IsAdmin() bypass.
                                $pgIcon = 'fa-user-check pg-icon-self';
                            } elseif ($pgIsReserved) {
                                // Rows exist for the crown roles, but nothing enforces them and
                                // the console tile refuses the action, so a check here would be
                                // a claim the product does not honour.
                                $pgIcon = 'fa-lock pg-icon-lock';
                            } else {
                                $pgIcon = isset($pgGranted[$pgKey][(int)$pgRole['RoleId']])
                                    ? 'fa-check-circle pg-icon-check'
                                    : 'fa-minus pg-icon-dash';
                            }
                            ?>
					<td><i class="fas <?= $pgIcon ?>"></i></td>
						<?php endforeach; ?>
				</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>

<!-- =============================================
     PG INLINE JAVASCRIPT
     ============================================= -->
<script>
(function() {
	'use strict';

	var table = document.getElementById('pgPermissionsTable');
	if (!table) return;

	/* ----- Section collapse/expand (mouse AND keyboard) ----- */
	var sectionHeaders = table.querySelectorAll('.pg-section-header');
	function toggleSection(header) {
		var section = header.getAttribute('data-section');
		var isCollapsed = header.classList.toggle('pg-collapsed');
		header.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
		applyVisibility();
	}
	sectionHeaders.forEach(function(header) {
		header.addEventListener('click', function() { toggleSection(this); });
		header.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
				e.preventDefault();
				toggleSection(this);
			}
		});
	});

	/* ----- Text filter ----- */
	var filterInput  = document.getElementById('pgFilterText');
	var filterStatus = document.getElementById('pgFilterStatus');
	var allRows      = Array.prototype.slice.call(table.querySelectorAll('tr.pg-row'));
	var term         = '';

	function applyVisibility() {
		var shown = 0;
		var perSection = {};
		allRows.forEach(function(row) {
			var section = row.getAttribute('data-section');
			var matches = term === '' || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
			if (matches) {
				perSection[section] = (perSection[section] || 0) + 1;
				shown++;
			}
			var header = table.querySelector('.pg-section-header[data-section="' + section + '"]');
			var collapsed = header && header.classList.contains('pg-collapsed');
			row.classList.toggle('pg-hidden', !matches || !!collapsed);
		});
		sectionHeaders.forEach(function(header) {
			var section = header.getAttribute('data-section');
			var count = perSection[section] || 0;
			var label = header.querySelector('.pg-section-count');
			if (label) {
				var total = label.getAttribute('data-total');
				label.textContent = (term === '' || count === Number(total))
					? total + ' permissions'
					: count + ' of ' + total + ' permissions';
			}
			/* A section with no match is noise, but never hide it while unfiltered. */
			header.classList.toggle('pg-hidden', term !== '' && count === 0);
		});
		if (filterStatus) {
			filterStatus.textContent = term === ''
				? ''
				: shown + (shown === 1 ? ' permission matches' : ' permissions match');
		}
	}

	if (filterInput) {
		filterInput.addEventListener('input', function() {
			term = this.value.trim().toLowerCase();
			applyVisibility();
		});
	}

	/* ----- Focus one role's column ----- */
	var roleSelect = document.getElementById('pgFilterRole');
	if (roleSelect) {
		roleSelect.addEventListener('change', function() {
			var pick = parseInt(this.value, 10);
			table.querySelectorAll('.pg-dim').forEach(function(el) {
				el.classList.remove('pg-dim');
			});
			if (!pick) return;
			table.querySelectorAll('tr').forEach(function(row) {
				if (row.classList.contains('pg-section-header')) return;
				for (var i = 1; i < row.children.length; i++) {
					if (i !== pick) row.children[i].classList.add('pg-dim');
				}
			});
		});
	}

	/* ----- Tooltips flip up near the foot of the scroller, which would clip them ----- */
	var wrap = table.closest('.pg-table-wrap');
	table.addEventListener('mouseover', function(e) {
		var tipped = e.target.closest('[data-tip]');
		if (!tipped || !wrap) return;
		var room = wrap.getBoundingClientRect().bottom - tipped.getBoundingClientRect().bottom;
		tipped.classList.toggle('pg-tip-up', room < 110);
	});

	/* ----- Column hover highlight ----- */
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
