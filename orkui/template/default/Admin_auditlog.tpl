<?php
$_actionLabels = [
	'Player::UpdatePlayer'         => 'Player Updated',
	'Player::AddAward'             => 'Award Given',
	'Player::GiveAward'            => 'Award Given',
	'Player::RemoveAward'          => 'Award Deleted',
	'Player::revoke_award'         => 'Award Revoked',
	'Player::ReactivateAward'      => 'Award Reactivated',
	'Player::UpdateAward'          => 'Award Updated',
	'Player::MergePlayer'          => 'Players Merged',
	'Player::MovePlayer'           => 'Player Moved',
	'Player::SetPlayerSuspension'  => 'Suspension Changed',
	'Player::RemoveNote'           => 'Note Deleted',
	'Attendance::SetAttendance'    => 'Attendance Modified',
	'Attendance::RemoveAttendance' => 'Attendance Removed',
];

// Build a one-line summary from the action and stored JSON
function _auditSummary($method, $params, $prior, $post) {
	$p = @json_decode($params, true) ?: [];
	$b = @json_decode($prior,  true) ?: [];
	$a = @json_decode($post,   true) ?: [];
	switch ($method) {
		case 'Player::UpdatePlayer':
		case 'Player::update_player':
			$changed = [];
			$watchFields = ['Persona' => 'Persona', 'GivenName' => 'Given Name', 'Surname' => 'Surname',
			                'Email' => 'Email', 'UserName' => 'Username', 'Active' => 'Active',
			                'Suspended' => 'Suspended', 'Restricted' => 'Restricted'];
			foreach ($watchFields as $key => $label) {
				if (isset($p[$key]) && isset($b[$key]) && (string)$p[$key] !== (string)$b[$key])
					$changed[] = $label;
			}
			return $changed ? 'Changed: ' . implode(', ', $changed) : 'Account updated';
		case 'Player::AddAward':
		case 'Player::GiveAward':
			$name = $a['KingdomAwardName'] ?? $a['AwardName'] ?? ($p['KingdomAwardId'] ? 'award #' . $p['KingdomAwardId'] : '');
			$rank = isset($p['Rank']) && $p['Rank'] > 0 ? ' rank ' . $p['Rank'] : '';
			return 'Gave ' . htmlspecialchars($name . $rank);
		case 'Player::RemoveAward':
			$name = $b['KingdomAwardName'] ?? $b['AwardName'] ?? '';
			return 'Deleted award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Player::revoke_award':
			$name = $b['KingdomAwardName'] ?? $b['AwardName'] ?? '';
			return 'Revoked award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Player::ReactivateAward':
			$name = $a['KingdomAwardName'] ?? $a['AwardName'] ?? '';
			return 'Reactivated award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Player::UpdateAward':
			return 'Updated award record';
		case 'Player::MergePlayer':
			$from = $b['Persona'] ?? ($p['FromMundaneId'] ? 'player #' . $p['FromMundaneId'] : '');
			$to   = $a['Persona'] ?? ($p['ToMundaneId']   ? 'player #' . $p['ToMundaneId']   : '');
			return 'Merged ' . htmlspecialchars($from) . ' → ' . htmlspecialchars($to);
		case 'Player::MovePlayer':
			$old = $b['ParkName'] ?? ($b['ParkId'] ? 'park #' . $b['ParkId'] : '');
			$new = isset($p['ParkId']) ? 'park #' . $p['ParkId'] : '';
			return 'Moved from ' . htmlspecialchars($old) . ($new ? ' to ' . htmlspecialchars($new) : '');
		case 'Player::SetPlayerSuspension':
			if (!isset($prior) || $prior === 'null') return 'Suspension lifted';
			$until = $p['SuspendedUntil'] ?? '';
			return 'Suspended' . ($until ? ' until ' . htmlspecialchars($until) : '');
		case 'Player::RemoveNote':
			return 'Deleted note';
		case 'Attendance::SetAttendance':
			return 'Attendance record updated';
		case 'Attendance::RemoveAttendance':
			return 'Attendance record deleted';
		default:
			return '';
	}
}

// Build detailed diff / detail HTML for the expand panel
function _auditDetail($method, $params, $prior, $post) {
	$p = @json_decode($params, true) ?: [];
	$b = @json_decode($prior,  true) ?: [];
	$a = @json_decode($post,   true) ?: [];

	switch ($method) {
		case 'Player::UpdatePlayer':
			$watchFields = [
				'Persona' => 'Persona', 'GivenName' => 'Given Name', 'Surname' => 'Surname',
				'OtherName' => 'Other Name', 'Email' => 'Email', 'UserName' => 'Username',
				'Active' => 'Active', 'Suspended' => 'Suspended', 'Restricted' => 'Restricted',
				'Waivered' => 'Waivered', 'ReeveQualified' => 'Reeve Qualified',
				'CorporaQualified' => 'Corpora Qualified', 'ParkMemberSince' => 'Member Since',
				'PronounId' => 'Pronoun',
			];
			$rows = '';
			foreach ($watchFields as $key => $label) {
				$old_val = isset($b[$key]) ? (string)$b[$key] : null;
				$new_val = isset($p[$key]) ? (string)$p[$key] : null;
				if ($new_val === null) continue;
				$changed = $old_val !== null && $old_val !== $new_val;
				$rows .= '<tr' . ($changed ? ' class="al-diff-changed"' : '') . '>'
				       . '<td class="al-diff-field">' . htmlspecialchars($label) . '</td>'
				       . '<td class="al-diff-old">'   . htmlspecialchars($old_val ?? '—') . '</td>'
				       . '<td class="al-diff-new">'   . htmlspecialchars($new_val) . '</td>'
				       . '</tr>';
			}
			if (!$rows) return '<em style="color:#a0aec0">No tracked fields changed, or prior state was truncated.</em>';
			return '<table class="al-diff-table"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>' . $rows . '</tbody></table>';

		case 'Player::AddAward':
		case 'Player::GiveAward':
		case 'Player::RemoveAward':
		case 'Player::revoke_award':
		case 'Player::ReactivateAward':
		case 'Player::UpdateAward':
			$state = $a ?: $b ?: $p;
			$html  = '<table class="al-diff-table"><tbody>';
			foreach (['KingdomAwardName' => 'Award', 'AwardName' => 'Canonical', 'Rank' => 'Rank',
			          'Date' => 'Date', 'GivenByPersona' => 'Given By', 'Note' => 'Note',
			          'CustomName' => 'Custom Name'] as $k => $lbl) {
				if (!empty($state[$k]))
					$html .= '<tr><td class="al-diff-field">' . $lbl . '</td><td colspan="2">' . htmlspecialchars($state[$k]) . '</td></tr>';
			}
			$html .= '</tbody></table>';
			return $html;

		case 'Player::MovePlayer':
			$html = '<table class="al-diff-table"><tbody>';
			if (!empty($b['ParkName'])) $html .= '<tr><td class="al-diff-field">From Park</td><td colspan="2">' . htmlspecialchars($b['ParkName']) . '</td></tr>';
			if (!empty($p['ParkId']))   $html .= '<tr><td class="al-diff-field">To Park ID</td><td colspan="2">' . (int)$p['ParkId'] . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::MergePlayer':
			$html = '<table class="al-diff-table"><tbody>';
			if (!empty($b['Persona'])) $html .= '<tr><td class="al-diff-field">Merged From</td><td colspan="2">' . htmlspecialchars($b['Persona']) . ' (#' . (int)($p['FromMundaneId'] ?? 0) . ')</td></tr>';
			if (!empty($a['Persona'])) $html .= '<tr><td class="al-diff-field">Merged Into</td><td colspan="2">' . htmlspecialchars($a['Persona']) . ' (#' . (int)($p['ToMundaneId'] ?? 0) . ')</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::SetPlayerSuspension':
			$html = '<table class="al-diff-table"><tbody>';
			foreach (['SuspendedUntil' => 'Until', 'Suspension' => 'Reason', 'SuspensionPropagates' => 'Propagates'] as $k => $lbl) {
				if (isset($p[$k])) $html .= '<tr><td class="al-diff-field">' . $lbl . '</td><td colspan="2">' . htmlspecialchars($p[$k]) . '</td></tr>';
			}
			$html .= '</tbody></table>';
			return $html;

		case 'Player::RemoveNote':
			if (!empty($b['Note'])) return '<div class="al-note-text">' . nl2br(htmlspecialchars($b['Note'])) . '</div>';
			return '<em style="color:#a0aec0">Note content not available.</em>';

		default:
			$out = [];
			if ($prior && $prior !== 'null') $out[] = '<strong>Prior state:</strong><pre class="al-raw-json">' . htmlspecialchars($prior) . '</pre>';
			if ($post  && $post  !== 'null') $out[] = '<strong>Post state:</strong><pre class="al-raw-json">' . htmlspecialchars($post) . '</pre>';
			if ($params && $params !== '[]')  $out[] = '<strong>Parameters:</strong><pre class="al-raw-json">' . htmlspecialchars($params) . '</pre>';
			return $out ? implode('', $out) : '<em style="color:#a0aec0">No detail available.</em>';
	}
}

$_has_data = !empty($AuditRows);
$_total    = (int)$AuditTotal;
$_page     = (int)$AuditPage;
$_pages    = (int)$AuditPages;

// Preserve filters in pagination links
function _auditPageUrl($page, $start, $end, $method, $bywhom, $entity) {
	$q = http_build_query(array_filter([
		'StartDate'  => $start,
		'EndDate'    => $end,
		'MethodCall' => $method,
		'ByWhomId'   => $bywhom ?: '',
		'EntityId'   => $entity ?: '',
		'Page'       => $page,
	], function($v) { return $v !== '' && $v !== null; }));
	return UIR . 'Admin/auditlog&' . $q;
}
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
/* ── Audit log local styles ─────────────────────────────── */
.al-layout        { display:flex; gap:18px; align-items:flex-start; margin-top:16px; }
.al-sidebar       { width:220px; flex-shrink:0; }
.al-main          { flex:1; min-width:0; }
.al-filter-card   { background:#fff; border:1px solid var(--rp-border); border-radius:8px; padding:16px; }
.al-filter-card h3 { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
                     color:var(--rp-text-muted); margin:0 0 12px; }
.al-form-group    { display:flex; flex-direction:column; gap:3px; margin-bottom:10px; }
.al-form-group label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--rp-text-muted); }
.al-form-input    { border:1px solid var(--rp-border); border-radius:5px; padding:6px 8px; font-size:13px;
                    color:var(--rp-text); background:#fff; box-sizing:border-box; width:100%; }
.al-form-input:focus { outline:none; border-color:#6366f1; }
.al-btn-run       { width:100%; padding:8px 0; background:#4338ca; color:#fff; border:none; border-radius:6px;
                    font-size:13px; font-weight:700; cursor:pointer; margin-top:4px; }
.al-btn-run:hover { background:#3730a3; }
.al-btn-clear     { width:100%; padding:6px 0; background:transparent; color:var(--rp-text-muted);
                    border:1px solid var(--rp-border); border-radius:6px; font-size:12px; cursor:pointer; margin-top:6px; }
.al-table-wrap    { overflow-x:auto; }
.al-table         { width:100%; border-collapse:collapse; font-size:13px; }
.al-table th      { background:#f7fafc; border-bottom:2px solid var(--rp-border); padding:8px 10px;
                    text-align:left; font-size:11px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.04em; color:var(--rp-text-muted); white-space:nowrap; }
.al-table td      { padding:8px 10px; border-bottom:1px solid var(--rp-border); vertical-align:top; }
.al-table tbody tr:hover { background:#fafafa; }
.al-action-badge  { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:12px;
                    font-size:11px; font-weight:600; white-space:nowrap; }
.al-badge-update  { background:#ebf8ff; color:#2b6cb0; }
.al-badge-award   { background:#f0fff4; color:#276749; }
.al-badge-remove  { background:#fff5f5; color:#c53030; }
.al-badge-move    { background:#fffff0; color:#744210; }
.al-badge-merge   { background:#faf5ff; color:#6b46c1; }
.al-badge-suspend { background:#fff5f5; color:#c53030; }
.al-badge-attend  { background:#e8f4fd; color:#2c5282; }
.al-badge-default { background:#f7fafc; color:#4a5568; }
.al-expand-btn    { background:none; border:none; cursor:pointer; color:var(--rp-accent); font-size:14px;
                    padding:2px 6px; border-radius:4px; }
.al-expand-btn:hover { background:#eef2ff; }
.al-detail-row td { background:#f7fafc; border-bottom:1px solid var(--rp-border); padding:12px 16px; }
.al-diff-table    { width:100%; border-collapse:collapse; font-size:12px; }
.al-diff-table th { background:#edf2f7; padding:5px 8px; text-align:left; font-weight:700; color:#4a5568; }
.al-diff-table td { padding:5px 8px; border-top:1px solid #e2e8f0; }
.al-diff-field    { font-weight:600; color:#4a5568; width:130px; }
.al-diff-old      { color:#c53030; text-decoration:line-through; max-width:220px; word-break:break-all; }
.al-diff-new      { color:#276749; max-width:220px; word-break:break-all; }
.al-diff-changed  { background:#fffbeb; }
.al-raw-json      { font-size:11px; background:#2d3748; color:#e2e8f0; padding:8px; border-radius:4px;
                    overflow-x:auto; max-height:200px; white-space:pre-wrap; word-break:break-all; }
.al-note-text     { font-size:13px; line-height:1.6; color:var(--rp-text-body); padding:4px 0; }
.al-pagination    { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:16px; font-size:13px; }
.al-page-link     { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px;
                    padding:0 8px; border:1px solid var(--rp-border); border-radius:5px; color:var(--rp-accent);
                    text-decoration:none; font-size:13px; }
.al-page-link:hover { background:#eef2ff; }
.al-page-link.al-page-active { background:var(--rp-accent); color:#fff; border-color:var(--rp-accent); font-weight:700; }
.al-page-link.al-page-disabled { color:var(--rp-text-muted); pointer-events:none; }
.al-page-info     { color:var(--rp-text-muted); font-size:12px; margin-left:auto; }
.al-empty         { padding:40px 20px; text-align:center; color:var(--rp-text-muted); font-size:14px; }
.al-empty i       { font-size:32px; display:block; margin-bottom:10px; opacity:.4; }
.al-entity-note   { font-size:10px; color:var(--rp-text-hint); display:block; }

/* dark mode */
html[data-theme="dark"] .al-filter-card  { background:var(--ork-card-bg); border-color:var(--ork-border); }
html[data-theme="dark"] .al-form-input   { background:var(--ork-input-bg); border-color:var(--ork-input-border); color:var(--ork-text); }
html[data-theme="dark"] .al-table th     { background:var(--ork-bg-secondary); }
html[data-theme="dark"] .al-table tbody tr:hover { background:var(--ork-bg-secondary); }
html[data-theme="dark"] .al-detail-row td { background:var(--ork-bg-secondary); }
html[data-theme="dark"] .al-diff-table th { background:var(--ork-bg-tertiary); color:var(--ork-text); }
html[data-theme="dark"] .al-diff-table td { border-color:var(--ork-border); }
html[data-theme="dark"] .al-diff-changed  { background:rgba(255,237,153,0.08); }
html[data-theme="dark"] .al-page-link     { border-color:var(--ork-border); color:var(--ork-link-bright); }
html[data-theme="dark"] .al-page-link:hover { background:var(--ork-bg-secondary); }
html[data-theme="dark"] .al-btn-clear     { border-color:var(--ork-border); color:var(--ork-text-muted); }
html[data-theme="dark"] .al-table         { color:var(--ork-text); }

@media (max-width:800px) {
	.al-layout { flex-direction:column; }
	.al-sidebar { width:100%; }
}
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-history rp-header-icon"></i>
				<h1 class="rp-header-title">Audit Log</h1>
			</div>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Records all significant changes to players, awards, and attendance. Expand any row to see before/after detail.</span>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-list-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($_total)?></div>
			<div class="rp-stat-label">Records in Range</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-calendar-alt"></i></div>
			<div class="rp-stat-number" style="font-size:14px"><?=htmlspecialchars($StartDate)?> – <?=htmlspecialchars($EndDate)?></div>
			<div class="rp-stat-label">Date Range</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-file-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($_pages)?></div>
			<div class="rp-stat-label">Pages</div>
		</div>
	</div>

	<div class="al-layout">

		<!-- Sidebar filters -->
		<div class="al-sidebar">
			<div class="al-filter-card">
				<h3><i class="fas fa-filter" style="margin-right:5px"></i>Filters</h3>
				<form method="get" action="<?=UIR?>Admin/auditlog">
					<div class="al-form-group">
						<label>Start Date</label>
						<input class="al-form-input" type="date" name="StartDate" value="<?=htmlspecialchars($StartDate)?>">
					</div>
					<div class="al-form-group">
						<label>End Date</label>
						<input class="al-form-input" type="date" name="EndDate" value="<?=htmlspecialchars($EndDate)?>">
					</div>
					<div class="al-form-group">
						<label>Action Type</label>
						<select class="al-form-input" name="MethodCall">
							<option value="">— All —</option>
							<?php foreach ($AuditMethods as $_m): ?>
							<option value="<?=htmlspecialchars($_m)?>" <?=$MethodFilter === $_m ? 'selected' : ''?>>
								<?=htmlspecialchars($_actionLabels[$_m] ?? $_m)?>
							</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="al-form-group">
						<label>By Whom (Player ID)</label>
						<input class="al-form-input" type="number" name="ByWhomId" value="<?=(int)$ByWhomFilter ?: ''?>" placeholder="Mundane ID">
					</div>
					<div class="al-form-group">
						<label>Affected Player ID</label>
						<input class="al-form-input" type="number" name="EntityId" value="<?=(int)$EntityFilter ?: ''?>" placeholder="Mundane ID">
					</div>
					<button type="submit" class="al-btn-run">Run</button>
					<a href="<?=UIR?>Admin/auditlog" class="al-btn-clear" style="display:block;text-align:center;text-decoration:none;margin-top:6px;padding:6px 0;border:1px solid var(--rp-border);border-radius:6px;font-size:12px;color:var(--rp-text-muted)">Clear Filters</a>
				</form>
			</div>
		</div>

		<!-- Main results -->
		<div class="al-main">
			<?php if (!$_has_data): ?>
			<div class="al-empty">
				<i class="fas fa-history"></i>
				No records found for the selected filters.
			</div>
			<?php else: ?>
			<div class="al-table-wrap">
				<table class="al-table" id="al-table">
					<thead>
						<tr>
							<th>Timestamp</th>
							<th>By Whom</th>
							<th>Action</th>
							<th>Player</th>
							<th>Summary</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($AuditRows as $_i => $_r):
						$_mc      = $_r['MethodCall'];
						$_label   = $_actionLabels[$_mc] ?? $_mc;
						$_summary = _auditSummary($_mc, $_r['Parameters'], $_r['PriorState'], $_r['PostState']);

						// Badge CSS class
						if (strpos($_mc, 'UpdatePlayer') !== false) $_bc = 'al-badge-update';
						elseif (strpos($_mc, 'Award') !== false || strpos($_mc, 'award') !== false) {
							$_bc = (strpos($_mc, 'Remove') !== false || strpos($_mc, 'revoke') !== false) ? 'al-badge-remove' : 'al-badge-award';
						}
						elseif (strpos($_mc, 'Merge') !== false) $_bc = 'al-badge-merge';
						elseif (strpos($_mc, 'Move') !== false)  $_bc = 'al-badge-move';
						elseif (strpos($_mc, 'Suspension') !== false) $_bc = 'al-badge-suspend';
						elseif (strpos($_mc, 'Attendance') !== false) $_bc = 'al-badge-attend';
						else $_bc = 'al-badge-default';

						$_detailId = 'al-detail-' . $_i;
					?>
					<tr>
						<td style="white-space:nowrap;color:var(--rp-text-muted);font-size:12px">
							<?=htmlspecialchars(substr($_r['ModifiedAt'], 0, 16))?>
						</td>
						<td style="white-space:nowrap">
							<?php if ($_r['ByWhomId'] > 0): ?>
							<a href="<?=UIR?>Player/profile/<?=(int)$_r['ByWhomId']?>" style="color:var(--rp-accent)">
								<?=htmlspecialchars($_r['ByWhomPersona'] ?? '#' . $_r['ByWhomId'])?>
							</a>
							<?php else: ?>
							<span style="color:var(--rp-text-muted)">—</span>
							<?php endif; ?>
						</td>
						<td>
							<span class="al-action-badge <?=$_bc?>"><?=htmlspecialchars($_label)?></span>
						</td>
						<td>
							<?php if ($_r['EntityId'] > 0): ?>
							<a href="<?=UIR?>Player/profile/<?=(int)$_r['EntityId']?>" style="color:var(--rp-accent)">
								#<?=(int)$_r['EntityId']?>
							</a>
							<?php if (in_array($_mc, ['Player::AddAward', 'Player::GiveAward'])): ?>
							<span class="al-entity-note">award ID (pre-fix)</span>
							<?php endif; ?>
							<?php else: ?>
							<span style="color:var(--rp-text-muted)">—</span>
							<?php endif; ?>
						</td>
						<td style="font-size:12px;color:var(--rp-text-body)">
							<?=$_summary?>
						</td>
						<td>
							<button class="al-expand-btn" onclick="alToggle('<?=$_detailId?>', this)" title="Expand detail">
								<i class="fas fa-chevron-down"></i>
							</button>
						</td>
					</tr>
					<tr id="<?=$_detailId?>" style="display:none">
						<td colspan="6">
							<?=_auditDetail($_mc, $_r['Parameters'], $_r['PriorState'], $_r['PostState'])?>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<div class="al-pagination">
				<?php
				$_showPages = [];
				for ($i = 1; $i <= $_pages; $i++) {
					if ($i === 1 || $i === $_pages || abs($i - $_page) <= 2)
						$_showPages[] = $i;
				}
				$_prev = null;
				foreach ($_showPages as $_pg):
					if ($_prev !== null && $_pg - $_prev > 1): ?>
					<span style="color:var(--rp-text-muted)">…</span>
					<?php endif;
					$_pUrl = _auditPageUrl($_pg, $StartDate, $EndDate, $MethodFilter, $ByWhomFilter, $EntityFilter);
				?>
				<a href="<?=$_pUrl?>" class="al-page-link <?=$_pg === $_page ? 'al-page-active' : ''?>"><?=$_pg?></a>
				<?php $_prev = $_pg; endforeach; ?>
				<span class="al-page-info">
					Page <?=$_page?> of <?=$_pages?> &mdash; <?=number_format($_total)?> total records
				</span>
			</div>
			<?php endif; ?>
		</div>
	</div>

</div>

<script>
function alToggle(id, btn) {
	var row = document.getElementById(id);
	if (!row) return;
	var open = row.style.display !== 'none';
	row.style.display = open ? 'none' : 'table-row';
	btn.querySelector('i').className = open ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}
</script>
