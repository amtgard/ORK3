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

// ── Batch ID → Name lookups ──────────────────────────────────
// Collect all IDs from the 50 rendered rows upfront, then resolve
// in three IN queries so the page never issues per-row DB calls.
global $DB;
$_parkIds = []; $_kingdomIds = []; $_mundaneIds = []; $_eventIds = [];
foreach ($AuditRows as $_lr) {
	foreach ([$_lr['Parameters'], $_lr['PriorState'], $_lr['PostState']] as $_js) {
		$_d = @json_decode($_js, true) ?: [];
		foreach (['park_id','at_park_id','ParkId'] as $_k) if (!empty($_d[$_k])) $_parkIds[(int)$_d[$_k]] = true;
		foreach (['kingdom_id','at_kingdom_id','KingdomId'] as $_k) if (!empty($_d[$_k])) $_kingdomIds[(int)$_d[$_k]] = true;
		foreach (['mundane_id','given_by_id','stripped_from','MundaneId','RecipientId','FromMundaneId','ToMundaneId'] as $_k) if (!empty($_d[$_k])) $_mundaneIds[(int)$_d[$_k]] = true;
		foreach (['event_id','at_event_id','EventId'] as $_k) if (!empty($_d[$_k]) && (int)$_d[$_k] > 0) $_eventIds[(int)$_d[$_k]] = true;
	}
}
$_parkMap = []; $_kingdomMap = []; $_mundaneMap = []; $_eventMap = [];
if ($_parkIds) {
	$_ids = implode(',', array_keys($_parkIds));
	$DB->Clear(); $_r = $DB->DataSet("SELECT park_id, name FROM ork_park WHERE park_id IN ($_ids)");
	if ($_r && $_r->Size() > 0) do { $_parkMap[(int)$_r->park_id] = $_r->name; } while ($_r->Next());
}
if ($_kingdomIds) {
	$_ids = implode(',', array_keys($_kingdomIds));
	$DB->Clear(); $_r = $DB->DataSet("SELECT kingdom_id, name FROM ork_kingdom WHERE kingdom_id IN ($_ids)");
	if ($_r && $_r->Size() > 0) do { $_kingdomMap[(int)$_r->kingdom_id] = $_r->name; } while ($_r->Next());
}
if ($_mundaneIds) {
	$_ids = implode(',', array_keys($_mundaneIds));
	$DB->Clear(); $_r = $DB->DataSet("SELECT mundane_id, persona FROM ork_mundane WHERE mundane_id IN ($_ids)");
	if ($_r && $_r->Size() > 0) do { $_mundaneMap[(int)$_r->mundane_id] = $_r->persona; } while ($_r->Next());
}
if ($_eventIds) {
	$_ids = implode(',', array_keys($_eventIds));
	$DB->Clear(); $_r = $DB->DataSet("SELECT event_id, name FROM ork_event WHERE event_id IN ($_ids)");
	if ($_r && $_r->Size() > 0) do { $_eventMap[(int)$_r->event_id] = $_r->name; } while ($_r->Next());
}
$DB->Clear();

// Helper: render an ID cell as "Name (link)" or plain "#id" fallback
function _auditIdLink($type, $id, $nameMap) {
	$id = (int)$id;
	if (!$id) return '—';
	$name = $nameMap[$id] ?? null;
	switch ($type) {
		case 'park':    $url = UIR . 'Park/profile/'    . $id; break;
		case 'kingdom': $url = UIR . 'Kingdom/profile/' . $id; break;
		case 'player':  $url = UIR . 'Player/profile/'  . $id; break;
		case 'event':   $url = UIR . 'Event/view/'      . $id; break;
		default:        return $name ? htmlspecialchars($name) . ' (#' . $id . ')' : '#' . $id;
	}
	$label = $name ? htmlspecialchars($name) . ' <span style="color:#a0aec0;font-size:11px">#' . $id . '</span>' : '#' . $id;
	return '<a href="' . $url . '" style="color:var(--rp-accent)">' . $label . '</a>';
}

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
			if ($changed) return 'Changed: ' . implode(', ', $changed);
			return empty($b) ? 'Account updated (prior state unavailable)' : 'Account updated';
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
		case 'Attendance::RemoveAttendance':
			$att = $b ?: $p;
			$classNames = [1=>'Peasant',2=>'Warrior',3=>'Wizard',4=>'Archer',5=>'Bard',6=>'Healer',7=>'Monster',8=>'Druid',9=>'Assassin',10=>'Anti-Paladin'];
			$cls  = $classNames[(int)($att['class_id'] ?? 0)] ?? '';
			$date = $att['date'] ?? '';
			$detail = array_filter([$date, $cls]);
			$verb = ($method === 'Attendance::RemoveAttendance') ? 'Deleted' : 'Updated';
			return $verb . ' attendance' . ($detail ? ': ' . implode(', ', $detail) : '');
		default:
			return '';
	}
}

// Build detailed diff / detail HTML for the expand panel
function _auditDetail($method, $params, $prior, $post, $parkMap, $kingdomMap, $mundaneMap, $eventMap) {
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
			$hasPrior = !empty($b);
			$rows = '';
			foreach ($watchFields as $key => $label) {
				$old_val = isset($b[$key]) ? (string)$b[$key] : null;
				$new_val = isset($p[$key]) ? (string)$p[$key] : null;
				if ($new_val === null) continue;
				// If we have prior state, only show fields that actually changed
				if ($hasPrior && $old_val !== null && $old_val === $new_val) continue;
				$changed = $hasPrior && $old_val !== null && $old_val !== $new_val;
				$rows .= '<tr' . ($changed ? ' class="al-diff-changed"' : '') . '>'
				       . '<td class="al-diff-field">' . htmlspecialchars($label) . '</td>'
				       . '<td class="al-diff-old">'   . htmlspecialchars($old_val ?? '—') . '</td>'
				       . '<td class="al-diff-new">'   . htmlspecialchars($new_val) . '</td>'
				       . '</tr>';
			}
			$truncatedNotice = !$hasPrior
				? '<tr><td colspan="3" style="padding:8px;font-size:11px;color:#c05621;background:#fffaf0;border-radius:4px">'
				  . '<i class="fas fa-exclamation-triangle" style="margin-right:4px"></i>'
				  . 'Prior state unavailable — this record predates the audit schema fix (2026-04-21). '
				  . 'New values are shown but "Before" cannot be determined.</td></tr>'
				: '';
			if (!$rows && !$truncatedNotice) return '<em style="color:#a0aec0">No tracked fields changed.</em>';
			return '<table class="al-diff-table"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>' . $truncatedNotice . $rows . '</tbody></table>';

		case 'Player::AddAward':
		case 'Player::GiveAward':
		case 'Player::RemoveAward':
		case 'Player::revoke_award':
		case 'Player::ReactivateAward':
		case 'Player::UpdateAward':
			$state = $a ?: $b ?: $p;
			$html  = '<table class="al-diff-table"><tbody>';
			foreach (['KingdomAwardName' => 'Award', 'AwardName' => 'Canonical', 'Rank' => 'Rank',
			          'Date' => 'Date', 'Note' => 'Note', 'CustomName' => 'Custom Name'] as $k => $lbl) {
				if (isset($state[$k]) && $state[$k] !== '' && $state[$k] !== null)
					$html .= '<tr><td class="al-diff-field">' . $lbl . '</td><td colspan="2">' . htmlspecialchars($state[$k]) . '</td></tr>';
			}
			if (!empty($state['mundane_id']))
				$html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $state['mundane_id'], $mundaneMap) . '</td></tr>';
			if (!empty($state['given_by_id']))
				$html .= '<tr><td class="al-diff-field">Given By</td><td colspan="2">' . _auditIdLink('player', $state['given_by_id'], $mundaneMap) . '</td></tr>';
			if (!empty($state['at_park_id']))
				$html .= '<tr><td class="al-diff-field">At Park</td><td colspan="2">' . _auditIdLink('park', $state['at_park_id'], $parkMap) . '</td></tr>';
			if (!empty($state['at_kingdom_id']))
				$html .= '<tr><td class="al-diff-field">Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $state['at_kingdom_id'], $kingdomMap) . '</td></tr>';
			if (!empty($state['at_event_id']))
				$html .= '<tr><td class="al-diff-field">At Event</td><td colspan="2">' . _auditIdLink('event', $state['at_event_id'], $eventMap) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::MovePlayer':
			$html = '<table class="al-diff-table"><tbody>';
			$fromPark = !empty($b['ParkId']) ? _auditIdLink('park', $b['ParkId'], $parkMap) : (!empty($b['ParkName']) ? htmlspecialchars($b['ParkName']) : '—');
			$toPark   = !empty($p['ParkId']) ? _auditIdLink('park', $p['ParkId'], $parkMap) : '—';
			$html .= '<tr><td class="al-diff-field">From Park</td><td colspan="2">' . $fromPark . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">To Park</td><td colspan="2">' . $toPark . '</td></tr>';
			if (!empty($p['MundaneId']))
				$html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $p['MundaneId'], $mundaneMap) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::MergePlayer':
			$html = '<table class="al-diff-table"><tbody>';
			$html .= '<tr><td class="al-diff-field">Merged From</td><td colspan="2">' . _auditIdLink('player', $p['FromMundaneId'] ?? 0, $mundaneMap) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Merged Into</td><td colspan="2">' . _auditIdLink('player', $p['ToMundaneId'] ?? 0, $mundaneMap) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::SetPlayerSuspension':
			$html = '<table class="al-diff-table"><tbody>';
			if (!empty($p['MundaneId']))
				$html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $p['MundaneId'], $mundaneMap) . '</td></tr>';
			foreach (['SuspendedUntil' => 'Until', 'Suspension' => 'Reason', 'SuspensionPropagates' => 'Propagates'] as $k => $lbl) {
				if (isset($p[$k]) && $p[$k] !== '') $html .= '<tr><td class="al-diff-field">' . $lbl . '</td><td colspan="2">' . htmlspecialchars($p[$k]) . '</td></tr>';
			}
			if (!empty($p['SuspendedById']))
				$html .= '<tr><td class="al-diff-field">Suspended By</td><td colspan="2">' . _auditIdLink('player', $p['SuspendedById'], $mundaneMap) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::RemoveNote':
			if (!empty($b['Note'])) return '<div class="al-note-text">' . nl2br(htmlspecialchars($b['Note'])) . '</div>';
			return '<em style="color:#a0aec0">Note content not available.</em>';

		case 'Attendance::RemoveAttendance':
		case 'Attendance::SetAttendance':
			$att = $b ?: $a ?: $p;
			if (empty($att)) return '<em style="color:#a0aec0">No detail available.</em>';
			$classNames = [1=>'Peasant',2=>'Warrior',3=>'Wizard',4=>'Archer',5=>'Bard',6=>'Healer',7=>'Monster',8=>'Druid',9=>'Assassin',10=>'Anti-Paladin'];
			$className  = $classNames[(int)($att['class_id'] ?? 0)] ?? ('Class #' . (int)($att['class_id'] ?? 0));
			$html = '<table class="al-diff-table"><tbody>';
			$html .= '<tr><td class="al-diff-field">Attendance ID</td><td colspan="2">' . (int)($att['attendance_id'] ?? 0) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $att['mundane_id'] ?? 0, $mundaneMap) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Date</td><td colspan="2">' . htmlspecialchars($att['date'] ?? '') . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Class</td><td colspan="2">' . htmlspecialchars($className) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Credits</td><td colspan="2">' . htmlspecialchars($att['credits'] ?? '') . '</td></tr>';
			if (!empty($att['park_id']))  $html .= '<tr><td class="al-diff-field">Park</td><td colspan="2">' . _auditIdLink('park', $att['park_id'], $parkMap) . '</td></tr>';
			if (!empty($att['kingdom_id'])) $html .= '<tr><td class="al-diff-field">Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $att['kingdom_id'], $kingdomMap) . '</td></tr>';
			if (!empty($att['event_id'])) $html .= '<tr><td class="al-diff-field">Event</td><td colspan="2">' . _auditIdLink('event', $att['event_id'], $eventMap) . '</td></tr>';
			if (!empty($att['note']))     $html .= '<tr><td class="al-diff-field">Note</td><td colspan="2">' . htmlspecialchars($att['note']) . '</td></tr>';
			if (!empty($att['flavor']))   $html .= '<tr><td class="al-diff-field">Flavor</td><td colspan="2">' . htmlspecialchars($att['flavor']) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

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
		'Route'      => 'Admin/auditlog',
		'StartDate'  => $start,
		'EndDate'    => $end,
		'MethodCall' => $method,
		'ByWhomId'   => $bywhom ?: '',
		'EntityId'   => $entity ?: '',
		'Page'       => $page,
	], function($v) { return $v !== '' && $v !== null; }));
	return HTTP_UI_REMOTE . 'index.php?' . $q;
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
				<form method="get" action="<?=HTTP_UI_REMOTE?>index.php">
				<input type="hidden" name="Route" value="Admin/auditlog">
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
					<a href="<?=UIR?>Admin/auditlog" class="al-btn-clear" style="display:block;text-align:center;text-decoration:none;margin-top:6px;padding:6px 0;border:1px solid var(--rp-border);border-radius:6px;font-size:12px;color:var(--rp-text-muted);box-sizing:border-box">Clear Filters</a>
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
							<?=_auditDetail($_mc, $_r['Parameters'], $_r['PriorState'], $_r['PostState'], $_parkMap, $_kingdomMap, $_mundaneMap, $_eventMap)?>
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
