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
	'Player::RevokeDues'           => 'Dues Revoked',
	'Kingdom::RemoveAward'         => 'Kingdom Award Deleted',
	'Park::MergeParks'             => 'Parks Merged',
	'Park::TransferPark'           => 'Park Transferred',
];

// ── JSON helpers (defined early — used in batch collector and render functions) ──
function _jsonExtract($json, $keys) {
	$out = [];
	foreach ($keys as $k) {
		if (preg_match('/"' . preg_quote($k, '/') . '"\s*:\s*(\d+)/', $json, $m))
			$out[$k] = (int)$m[1];
		elseif (preg_match('/"' . preg_quote($k, '/') . '"\s*:\s*"([^"\\\\]*)"/', $json, $m))
			$out[$k] = $m[1];
	}
	return $out;
}
function _jsonDecode($json, $fallbackKeys = []) {
	$r = @json_decode($json, true);
	if (is_array($r)) return $r;
	if ($fallbackKeys && is_string($json) && strlen($json) > 0)
		return _jsonExtract($json, $fallbackKeys);
	return [];
}

// ── Batch ID → Name lookups ──────────────────────────────────
// Collect all IDs from the 50 rendered rows upfront, then resolve
// in three IN queries so the page never issues per-row DB calls.
global $DB;
$_parkIds = []; $_kingdomIds = []; $_mundaneIds = []; $_eventIds = []; $_kawardIds = [];
foreach ($AuditRows as $_lr) {
	foreach ([$_lr['Parameters'], $_lr['PriorState'], $_lr['PostState']] as $_js) {
		$_d = @json_decode($_js, true);
		if (!is_array($_d)) $_d = _jsonExtract($_js ?? '', ['ParkId','park_id','KingdomId','kingdom_id','MundaneId','mundane_id','given_by_id','stripped_from','RecipientId','FromMundaneId','ToMundaneId','event_id','at_park_id','at_kingdom_id','at_event_id','kingdomaward_id','KingdomAwardId','old_kingdom_id','new_kingdom_id','from_park_id','to_park_id','from_kingdom_id','to_kingdom_id']);
		foreach (['park_id','at_park_id','ParkId','from_park_id','to_park_id'] as $_k) if (!empty($_d[$_k])) $_parkIds[(int)$_d[$_k]] = true;
		foreach (['kingdom_id','at_kingdom_id','KingdomId','old_kingdom_id','new_kingdom_id','from_kingdom_id','to_kingdom_id'] as $_k) if (!empty($_d[$_k])) $_kingdomIds[(int)$_d[$_k]] = true;
		foreach (['mundane_id','given_by_id','given_by','stripped_from','MundaneId','RecipientId','FromMundaneId','ToMundaneId','SuspendedById'] as $_k) if (!empty($_d[$_k]) && is_numeric($_d[$_k])) $_mundaneIds[(int)$_d[$_k]] = true;
	// entity_id is usually a mundane_id — collect it too
	if (!empty($_lr['EntityId'])) $_mundaneIds[(int)$_lr['EntityId']] = true;
		foreach (['event_id','at_event_id','EventId'] as $_k) if (!empty($_d[$_k]) && (int)$_d[$_k] > 0) $_eventIds[(int)$_d[$_k]] = true;
		foreach (['kingdomaward_id','KingdomAwardId'] as $_k) if (!empty($_d[$_k])) $_kawardIds[(int)$_d[$_k]] = true;
	}
}
$_parkMap = []; $_kingdomMap = []; $_mundaneMap = []; $_eventMap = []; $_kawardMap = []; $_classMap = [];
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
if ($_kawardIds) {
	$_ids = implode(',', array_keys($_kawardIds));
	$DB->Clear(); $_r = $DB->DataSet("SELECT kingdomaward_id, name FROM ork_kingdomaward WHERE kingdomaward_id IN ($_ids)");
	if ($_r && $_r->Size() > 0) do { $_kawardMap[(int)$_r->kingdomaward_id] = $_r->name; } while ($_r->Next());
}
$DB->Clear(); $_r = $DB->DataSet("SELECT class_id, name FROM ork_class ORDER BY class_id");
if ($_r && $_r->Size() > 0) do { $_classMap[(int)$_r->class_id] = $_r->name; } while ($_r->Next());
$DB->Clear();

// Resolve names for current filter player IDs so the picker shows names, not raw numbers
$_filterPlayerNames = [];
$_filterIds = array_filter([(int)($ByWhomFilter ?? 0), (int)($EntityFilter ?? 0)]);
if ($_filterIds) {
	$_ids = implode(',', $_filterIds);
	$DB->Clear(); $_r = $DB->DataSet("SELECT mundane_id, persona FROM ork_mundane WHERE mundane_id IN ($_ids)");
	if ($_r && $_r->Size() > 0) do { $_filterPlayerNames[(int)$_r->mundane_id] = $_r->persona; } while ($_r->Next());
	$DB->Clear();
}

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
function _auditSummary($method, $params, $prior, $post, $kawardMap = [], $parkMap = [], $kingdomMap = [], $classMap = []) {
	$p = _jsonDecode($params, ['ParkId','MundaneId','KingdomId','FromMundaneId','ToMundaneId','SuspendedUntil','ClassId','Credits']);
	$b = _jsonDecode($prior,  ['ParkId','park_id','KingdomId','kingdom_id','MundaneId','mundane_id','Persona','class_id','date','credits','dues_until','dues_for_life','name']);
	$a = _jsonDecode($post,   ['ParkId','park_id','KingdomId','MundaneId','mundane_id','Persona']);
	switch ($method) {
		case 'Player::UpdatePlayer':
		case 'Player::update_player':
			$changed = [];
			if (($p['PasswordChanged'] ?? 0) == 1 || !empty($p['Password']))
				$changed[] = 'Password';
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
			$_kid = (int)($p['KingdomAwardId'] ?? $a['kingdomaward_id'] ?? 0);
			$name = $a['KingdomAwardName'] ?? $a['AwardName'] ?? ($kawardMap[$_kid] ?? ($p['KingdomAwardId'] ? 'award #' . $p['KingdomAwardId'] : ''));
			$rank = isset($p['Rank']) && $p['Rank'] > 0 ? ' rank ' . $p['Rank'] : '';
			return 'Gave ' . htmlspecialchars($name . $rank);
		case 'Player::RemoveAward':
			$_kid = (int)($b['kingdomaward_id'] ?? 0);
			$name = $b['KingdomAwardName'] ?? $b['AwardName'] ?? ($kawardMap[$_kid] ?? ($b['kingdomaward_id'] ? '#' . $b['kingdomaward_id'] : ''));
			return 'Deleted award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Player::revoke_award':
			$_kid = (int)($b['kingdomaward_id'] ?? 0);
			$name = $b['KingdomAwardName'] ?? $b['AwardName'] ?? ($kawardMap[$_kid] ?? ($b['kingdomaward_id'] ? '#' . $b['kingdomaward_id'] : ''));
			return 'Revoked award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Player::ReactivateAward':
			$_kid = (int)($a['kingdomaward_id'] ?? 0);
			$name = $a['KingdomAwardName'] ?? $a['AwardName'] ?? ($kawardMap[$_kid] ?? '');
			return 'Reactivated award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Player::UpdateAward':
			return 'Updated award record';
		case 'Player::MergePlayer':
			$from = $b['Persona'] ?? ($p['FromMundaneId'] ? 'player #' . $p['FromMundaneId'] : '');
			$to   = $a['Persona'] ?? ($p['ToMundaneId']   ? 'player #' . $p['ToMundaneId']   : '');
			return 'Merged ' . htmlspecialchars($from) . ' → ' . htmlspecialchars($to);
		case 'Player::MovePlayer':
			$_fpid = (int)($b['park_id'] ?? $b['ParkId'] ?? 0);
			$_tpid = (int)($p['ParkId'] ?? 0);
			$old = $_fpid ? ($parkMap[$_fpid] ?? 'park #' . $_fpid) : '';
			$new = $_tpid ? ($parkMap[$_tpid] ?? 'park #' . $_tpid) : '';
			return 'Moved' . ($old ? ' from ' . htmlspecialchars($old) : '') . ($new ? ' to ' . htmlspecialchars($new) : '');
		case 'Player::SetPlayerSuspension':
			$_newSuspended = isset($p['Suspended']) ? (int)$p['Suspended'] : -1;
			$_oldSuspended = isset($b['Suspended']) ? (int)$b['Suspended'] : -1;
			if ($_newSuspended === 0) return 'Suspension lifted';
			if ($_newSuspended === 1 && $_oldSuspended === 0) {
				$until = $p['SuspendedUntil'] ?? '';
				return 'Suspended' . ($until ? ' until ' . htmlspecialchars($until) : '');
			}
			if ($_newSuspended === 1 && $_oldSuspended === 1) return 'Suspension modified';
			// Fallback for old records without prior state
			$until = $p['SuspendedUntil'] ?? '';
			return ($until || !empty($p['Suspension'])) ? 'Suspended' . ($until ? ' until ' . htmlspecialchars($until) : '') : 'Suspension changed';
		case 'Player::RemoveNote':
			$_noteText = $b['note'] ?? $b['Note'] ?? '';
			$_snippet = $_noteText ? mb_strimwidth(strip_tags($_noteText), 0, 60, '…') : '';
			return 'Deleted note' . ($_snippet ? ': "' . htmlspecialchars($_snippet) . '"' : '');
		case 'Attendance::SetAttendance':
		case 'Attendance::RemoveAttendance':
			$att = $b ?: $p;
			$_cid = (int)($att['class_id'] ?? 0);
			$cls  = $classMap[$_cid] ?? ($att['class_id'] !== null ? 'Class #' . $_cid : '');
			$date = $att['date'] ?? '';
			$detail = array_filter([$date, $cls]);
			$verb = ($method === 'Attendance::RemoveAttendance') ? 'Deleted' : 'Updated';
			return $verb . ' attendance' . ($detail ? ': ' . implode(', ', $detail) : '');
		case 'Player::RevokeDues':
			$_kid = (int)($b['kingdom_id'] ?? 0);
			$kingdom = $_kid ? ($kingdomMap[$_kid] ?? 'kingdom #' . $_kid) : '';
			$until = $b['dues_until'] ?? '';
			$detail = array_filter([$kingdom, $until ? 'until ' . $until : '']);
			return 'Revoked dues' . ($detail ? ': ' . implode(', ', $detail) : '');
		case 'Kingdom::RemoveAward':
			$name = $b['name'] ?? '';
			return 'Deleted kingdom award' . ($name ? ': ' . htmlspecialchars($name) : '');
		case 'Park::MergeParks':
			$_fp = (int)($b['from_park_id'] ?? $p['FromParkId'] ?? 0);
			$_tp = (int)($b['to_park_id']   ?? $p['ToParkId']   ?? 0);
			return 'Merged ' . htmlspecialchars($parkMap[$_fp] ?? 'park #' . $_fp)
				. ' → ' . htmlspecialchars($parkMap[$_tp] ?? 'park #' . $_tp);
		case 'Park::TransferPark':
			$_pid  = (int)($b['park_id']        ?? $p['ParkId']    ?? 0);
			$_okid = (int)($b['old_kingdom_id'] ?? 0);
			$_nkid = (int)($b['new_kingdom_id'] ?? $p['KingdomId'] ?? 0);
			return 'Transferred ' . htmlspecialchars($parkMap[$_pid] ?? 'park #' . $_pid)
				. ' from ' . htmlspecialchars($kingdomMap[$_okid] ?? 'kingdom #' . $_okid)
				. ' to '   . htmlspecialchars($kingdomMap[$_nkid] ?? 'kingdom #' . $_nkid);
		default:
			return '';
	}
}

// Build detailed diff / detail HTML for the expand panel
function _auditDetail($method, $params, $prior, $post, $parkMap, $kingdomMap, $mundaneMap, $eventMap, $kawardMap = [], $classMap = []) {
	$p = _jsonDecode($params, ['ParkId','MundaneId','KingdomId','FromMundaneId','ToMundaneId','SuspendedUntil','SuspendedById','ClassId','Credits','Date']);
	$b = _jsonDecode($prior,  ['ParkId','park_id','KingdomId','kingdom_id','MundaneId','mundane_id','Persona','class_id','date','credits','dues_until','dues_for_life','name','kingdomaward_id','given_by','given_by_id','at_park_id','at_kingdom_id','at_event_id','SuspendedById']);
	$a = _jsonDecode($post,   ['ParkId','park_id','KingdomId','MundaneId','mundane_id','Persona','kingdomaward_id']);

	switch ($method) {
		case 'Player::UpdatePlayer':
			$watchFields = [
				'Persona' => 'Persona', 'GivenName' => 'Given Name', 'Surname' => 'Surname',
				'OtherName' => 'Other Name', 'Email' => 'Email', 'UserName' => 'Username',
				'Active' => 'Active', 'Suspended' => 'Suspended', 'Restricted' => 'Restricted',
				'Waivered' => 'Waivered', 'ReeveQualified' => 'Reeve Qualified',
				'ReeveQualifiedUntil' => 'Reeve Qualified Until',
				'CorporaQualified' => 'Corpora Qualified',
				'CorporaQualifiedUntil' => 'Corpora Qualified Until',
				'ParkMemberSince' => 'Member Since',
				'PronounId' => 'Pronoun',
				'PasswordChanged' => 'Password',
			];
			$hasPrior = !empty($b);
			$_dateFields = ['ReeveQualifiedUntil', 'CorporaQualifiedUntil', 'ParkMemberSince'];
			$_fmtVal = function($key, $val) use ($_dateFields) {
				if ($key === 'PasswordChanged') return $val == 1 ? 'Changed' : '—';
				if ($val === null || $val === '' || $val === '0000-00-00') return '—';
				if (in_array($key, $_dateFields)) {
					if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) return '—';
					$ts = strtotime($val);
					if (!$ts || $ts < 0) return '—';
					return date('M j, Y', $ts);
				}
				return $val;
			};
			$rows = '';
			foreach ($watchFields as $key => $label) {
				// PasswordChanged lives in parameters ($p), not in prior/post state.
				// Legacy records stored the raw Password field instead.
				if ($key === 'PasswordChanged') {
					if (($p['PasswordChanged'] ?? 0) != 1 && empty($p['Password'])) continue;
					$rows .= '<tr class="al-diff-changed">'
					       . '<td class="al-diff-field">Password</td>'
					       . '<td class="al-diff-old">—</td>'
					       . '<td class="al-diff-new" style="color:#276749">Changed</td>'
					       . '</tr>';
					continue;
				}
				$old_val = isset($b[$key]) ? (string)$b[$key] : null;
				$new_val = isset($p[$key]) ? (string)$p[$key] : null;
				if ($new_val === null) continue;
				// If we have prior state, only show fields that actually changed
				if ($hasPrior && $old_val !== null && $old_val === $new_val) continue;
				// Skip empty Until fields when not changing (reduces noise)
				if (in_array($key, ['ReeveQualifiedUntil','CorporaQualifiedUntil']) && (!$new_val || $new_val === '0000-00-00') && !$old_val) continue;
				$changed = $hasPrior && $old_val !== null && $old_val !== $new_val;
				$rows .= '<tr' . ($changed ? ' class="al-diff-changed"' : '') . '>'
				       . '<td class="al-diff-field">' . htmlspecialchars($label) . '</td>'
				       . '<td class="' . ($changed ? 'al-diff-old' : 'al-diff-val') . '">' . htmlspecialchars($_fmtVal($key, $old_val)) . '</td>'
				       . '<td class="' . ($changed ? 'al-diff-new' : 'al-diff-val') . '">' . htmlspecialchars($_fmtVal($key, $new_val)) . '</td>'
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
			// Resolve award name: prefer stored KingdomAwardName, fall back to kawardMap lookup
			$_awardName = $state['KingdomAwardName'] ?? $state['AwardName'] ?? null;
			if (!$_awardName && !empty($state['kingdomaward_id'])) {
				$_awardName = $kawardMap[(int)$state['kingdomaward_id']] ?? null;
			}
			if (!$_awardName && !empty($p['KingdomAwardId'])) {
				$_awardName = $kawardMap[(int)$p['KingdomAwardId']] ?? null;
			}
			if ($_awardName)
				$html .= '<tr><td class="al-diff-field">Award</td><td colspan="2">' . htmlspecialchars($_awardName) . '</td></tr>';
			elseif (!empty($state['kingdomaward_id']))
				$html .= '<tr><td class="al-diff-field">Award</td><td colspan="2"><em style="color:#a0aec0">Unknown award #' . (int)$state['kingdomaward_id'] . '</em></td></tr>';
			$_rank = $state['Rank'] ?? $state['rank'] ?? null;
			if ($_rank !== null && $_rank !== '')
				$html .= '<tr><td class="al-diff-field">Rank</td><td colspan="2">' . (int)$_rank . '</td></tr>';
			$_fieldPairs = [
				'Date'       => ['Date', 'date'],
				'Note'       => ['Note', 'note'],
				'Custom Name'=> ['CustomName', 'custom_name'],
				'Canonical'  => ['AwardName'],
			];
			foreach ($_fieldPairs as $_lbl => $_keys) {
				foreach ($_keys as $_fk) {
					if (isset($state[$_fk]) && $state[$_fk] !== '' && $state[$_fk] !== null) {
						$html .= '<tr><td class="al-diff-field">' . $_lbl . '</td><td colspan="2">' . htmlspecialchars($state[$_fk]) . '</td></tr>';
						break;
					}
				}
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
			$_fpid = (int)($b['park_id'] ?? $b['ParkId'] ?? 0);
			$fromPark = $_fpid ? _auditIdLink('park', $_fpid, $parkMap) : '—';
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
			$_hasPrior = !empty($b);
			$_suspFields = [
				'Suspended'            => ['lbl' => 'Status',       'fmt' => 'bool_suspended'],
				'SuspendedById'        => ['lbl' => 'Suspended By', 'fmt' => 'player_link'],
				'SuspendedUntil'       => ['lbl' => 'Until',        'fmt' => 'text'],
				'Suspension'           => ['lbl' => 'Reason',       'fmt' => 'text'],
				'SuspensionPropagates' => ['lbl' => 'Propagates',   'fmt' => 'bool'],
			];
			if ($_hasPrior) {
				$html = '<table class="al-diff-table"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
				foreach ($_suspFields as $_k => $_f) {
					$_old = isset($b[$_k]) ? (string)$b[$_k] : null;
					$_new = isset($p[$_k]) ? (string)$p[$_k] : null;
					// MySQL null-date sentinel is semantically empty — treat as null
					if ($_old === '0000-00-00' || $_old === '') $_old = null;
					if ($_new === '0000-00-00' || $_new === '') $_new = null;
					if ($_old === null && $_new === null) continue;
					if ($_f['fmt'] === 'bool_suspended') { $_old = $_old !== null ? ($_old ? 'Suspended' : 'Not Suspended') : null; $_new = $_new !== null ? ($_new ? 'Suspended' : 'Not Suspended') : null; }
					elseif ($_f['fmt'] === 'bool') { $_old = $_old !== null ? ($_old ? 'Yes' : 'No') : null; $_new = $_new !== null ? ($_new ? 'Yes' : 'No') : null; }
					elseif ($_f['fmt'] === 'player_link') {
						$_oldId = (int)($_old ?? 0); $_newId = (int)($_new ?? 0);
						if (!$_oldId && !$_newId) continue;
						$_changed = $_oldId !== $_newId;
						$html .= '<tr' . ($_changed ? ' class="al-diff-changed"' : '') . '>'
						       . '<td class="al-diff-field">' . $_f['lbl'] . '</td>'
						       . '<td class="' . ($_changed ? 'al-diff-old' : 'al-diff-val') . '">' . _auditIdLink('player', $_oldId, $mundaneMap) . '</td>'
						       . '<td class="' . ($_changed ? 'al-diff-new' : 'al-diff-val') . '">' . _auditIdLink('player', $_newId, $mundaneMap) . '</td>'
						       . '</tr>';
						continue;
					}
					$_changed = $_old !== null && $_new !== null && $_old !== $_new;
					$html .= '<tr' . ($_changed ? ' class="al-diff-changed"' : '') . '>'
					       . '<td class="al-diff-field">' . $_f['lbl'] . '</td>'
					       . '<td class="' . ($_changed ? 'al-diff-old' : 'al-diff-val') . '">' . htmlspecialchars($_old ?? '—') . '</td>'
					       . '<td class="' . ($_changed ? 'al-diff-new' : 'al-diff-val') . '">' . htmlspecialchars($_new ?? '—') . '</td>'
					       . '</tr>';
				}
			} else {
				$html = '<table class="al-diff-table"><tbody>';
				foreach ($_suspFields as $_k => $_f) {
					if (!isset($p[$_k]) || $p[$_k] === '' || $p[$_k] === '0000-00-00') continue;
					if ($_f['fmt'] === 'player_link') {
						$_pid = (int)$p[$_k];
						if (!$_pid) continue;
						$html .= '<tr><td class="al-diff-field">' . $_f['lbl'] . '</td><td colspan="2">' . _auditIdLink('player', $_pid, $mundaneMap) . '</td></tr>';
						continue;
					}
					$_val = $_f['fmt'] === 'bool_suspended' ? ($p[$_k] ? 'Suspended' : 'Not Suspended') : ($_f['fmt'] === 'bool' ? ($p[$_k] ? 'Yes' : 'No') : htmlspecialchars($p[$_k]));
					$html .= '<tr><td class="al-diff-field">' . $_f['lbl'] . '</td><td colspan="2">' . $_val . '</td></tr>';
				}
			}
			if (!empty($p['MundaneId']))
				$html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $p['MundaneId'], $mundaneMap) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::RemoveNote':
			$html = '<table class="al-diff-table"><tbody>';
			if (!empty($b['mundane_id']))  $html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">'      . _auditIdLink('player', $b['mundane_id'], $mundaneMap) . '</td></tr>';
			if (isset($b['note']) && $b['note'] !== '')        $html .= '<tr><td class="al-diff-field">Title</td><td colspan="2">'       . htmlspecialchars($b['note'])        . '</td></tr>';
			if (isset($b['description']) && $b['description'] !== '') $html .= '<tr><td class="al-diff-field">Description</td><td colspan="2">' . nl2br(htmlspecialchars($b['description'])) . '</td></tr>';
			if (!empty($b['date']))        $html .= '<tr><td class="al-diff-field">Date</td><td colspan="2">'        . htmlspecialchars($b['date'])        . '</td></tr>';
			if (!empty($b['given_by']))    $html .= '<tr><td class="al-diff-field">Given By</td><td colspan="2">'    . _auditIdLink('player', (int)$b['given_by'], $mundaneMap)    . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Attendance::RemoveAttendance':
		case 'Attendance::SetAttendance':
			if (empty($b) && empty($p)) return '<em style="color:#a0aec0">No detail available.</em>';
			$html = '<table class="al-diff-table">';
			if ($method === 'Attendance::SetAttendance' && !empty($b) && !empty($p)) {
				// Show before/after diff for edits
				$html .= '<thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
				$_attFields = [
					'Date'    => ['old_key' => 'date',     'new_key' => 'Date'],
					'Class'   => ['old_key' => 'class_id', 'new_key' => 'ClassId', 'use_class_map' => true],
					'Credits' => ['old_key' => 'credits',  'new_key' => 'Credits'],
					'Note'    => ['old_key' => 'note',     'new_key' => 'Note'],
					'Flavor'  => ['old_key' => 'flavor',   'new_key' => 'Flavor'],
				];
				foreach ($_attFields as $_lbl => $_f) {
					$_old = isset($b[$_f['old_key']]) ? (string)$b[$_f['old_key']] : null;
					$_new = isset($p[$_f['new_key']]) ? (string)$p[$_f['new_key']] : null;
					if ($_new === null && $_old === null) continue;
					if (!empty($_f['use_class_map'])) {
						$_old = $_old !== null ? ($classMap[(int)$_old] ?? 'Class #' . (int)$_old) : null;
						$_new = $_new !== null ? ($classMap[(int)$_new] ?? 'Class #' . (int)$_new) : null;
					}
					$_changed = $_old !== null && $_new !== null && $_old !== $_new;
					$html .= '<tr' . ($_changed ? ' class="al-diff-changed"' : '') . '>'
					       . '<td class="al-diff-field">' . $_lbl . '</td>'
					       . '<td class="' . ($_changed ? 'al-diff-old' : 'al-diff-val') . '">' . htmlspecialchars($_old ?? '—') . '</td>'
					       . '<td class="' . ($_changed ? 'al-diff-new' : 'al-diff-val') . '">' . htmlspecialchars($_new ?? '—') . '</td>'
					       . '</tr>';
				}
			} else {
				// RemoveAttendance or no prior state — flat view
				$att = $b ?: $p;
				$html .= '<tbody>';
				$html .= '<tr><td class="al-diff-field">Date</td><td colspan="2">' . htmlspecialchars($att['date'] ?? '') . '</td></tr>';
				$_cid = (int)($att['class_id'] ?? 0);
				$html .= '<tr><td class="al-diff-field">Class</td><td colspan="2">' . htmlspecialchars($classMap[$_cid] ?? 'Class #' . $_cid) . '</td></tr>';
				$html .= '<tr><td class="al-diff-field">Credits</td><td colspan="2">' . htmlspecialchars($att['credits'] ?? '') . '</td></tr>';
				if (!empty($att['note']))   $html .= '<tr><td class="al-diff-field">Note</td><td colspan="2">' . htmlspecialchars($att['note']) . '</td></tr>';
				if (!empty($att['flavor'])) $html .= '<tr><td class="al-diff-field">Flavor</td><td colspan="2">' . htmlspecialchars($att['flavor']) . '</td></tr>';
			}
			// Context rows shown in both cases
			$att = $b ?: $p;
			if (!empty($att['attendance_id'])) $html .= '<tr><td class="al-diff-field">Attendance ID</td><td colspan="2">' . (int)$att['attendance_id'] . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $att['mundane_id'] ?? 0, $mundaneMap) . '</td></tr>';
			if (!empty($att['park_id']))    $html .= '<tr><td class="al-diff-field">Park</td><td colspan="2">'    . _auditIdLink('park',    $att['park_id'],    $parkMap)    . '</td></tr>';
			if (!empty($att['kingdom_id'])) $html .= '<tr><td class="al-diff-field">Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $att['kingdom_id'], $kingdomMap) . '</td></tr>';
			if (!empty($att['event_id']))   $html .= '<tr><td class="al-diff-field">Event</td><td colspan="2">'   . _auditIdLink('event',   $att['event_id'],   $eventMap)   . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Player::RevokeDues':
			$html = '<table class="al-diff-table"><tbody>';
			if (!empty($b['mundane_id']))  $html .= '<tr><td class="al-diff-field">Player</td><td colspan="2">' . _auditIdLink('player', $b['mundane_id'], $mundaneMap) . '</td></tr>';
			if (!empty($b['kingdom_id'])) $html .= '<tr><td class="al-diff-field">Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $b['kingdom_id'], $kingdomMap) . '</td></tr>';
			if (!empty($b['park_id']))    $html .= '<tr><td class="al-diff-field">Park</td><td colspan="2">' . _auditIdLink('park', $b['park_id'], $parkMap) . '</td></tr>';
			if (!empty($b['dues_from']))  $html .= '<tr><td class="al-diff-field">Dues From</td><td colspan="2">' . htmlspecialchars($b['dues_from']) . '</td></tr>';
			if (!empty($b['dues_until'])) $html .= '<tr><td class="al-diff-field">Dues Until</td><td colspan="2">' . htmlspecialchars($b['dues_until']) . '</td></tr>';
			if (!empty($b['dues_for_life'])) $html .= '<tr><td class="al-diff-field">Lifetime</td><td colspan="2">Yes</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Kingdom::RemoveAward':
			$html = '<table class="al-diff-table"><tbody>';
			if (!empty($b['name']))        $html .= '<tr><td class="al-diff-field">Award Name</td><td colspan="2">' . htmlspecialchars($b['name']) . '</td></tr>';
			if (!empty($b['kingdom_id'])) $html .= '<tr><td class="al-diff-field">Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $b['kingdom_id'], $kingdomMap) . '</td></tr>';
			if (isset($b['reign_limit']) && $b['reign_limit'] > 0) $html .= '<tr><td class="al-diff-field">Reign Limit</td><td colspan="2">' . (int)$b['reign_limit'] . '</td></tr>';
			if (isset($b['month_limit']) && $b['month_limit'] > 0) $html .= '<tr><td class="al-diff-field">Month Limit</td><td colspan="2">' . (int)$b['month_limit'] . '</td></tr>';
			if (!empty($b['is_title']))    $html .= '<tr><td class="al-diff-field">Is Title</td><td colspan="2">Yes</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Park::MergeParks':
			$html = '<table class="al-diff-table"><tbody>';
			$html .= '<tr><td class="al-diff-field">From Park</td><td colspan="2">' . _auditIdLink('park', $b['from_park_id'] ?? $p['FromParkId'] ?? 0, $parkMap) . '</td></tr>';
			if (!empty($b['from_kingdom_id'])) $html .= '<tr><td class="al-diff-field">From Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $b['from_kingdom_id'], $kingdomMap) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">Into Park</td><td colspan="2">' . _auditIdLink('park', $b['to_park_id'] ?? $p['ToParkId'] ?? 0, $parkMap) . '</td></tr>';
			if (!empty($b['to_kingdom_id'])) $html .= '<tr><td class="al-diff-field">Into Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $b['to_kingdom_id'], $kingdomMap) . '</td></tr>';
			$html .= '</tbody></table>';
			return $html;

		case 'Park::TransferPark':
			$html = '<table class="al-diff-table"><tbody>';
			$html .= '<tr><td class="al-diff-field">Park</td><td colspan="2">' . _auditIdLink('park', $b['park_id'] ?? $p['ParkId'] ?? 0, $parkMap) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">From Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $b['old_kingdom_id'] ?? 0, $kingdomMap) . '</td></tr>';
			$html .= '<tr><td class="al-diff-field">To Kingdom</td><td colspan="2">' . _auditIdLink('kingdom', $b['new_kingdom_id'] ?? $p['KingdomId'] ?? 0, $kingdomMap) . '</td></tr>';
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
                     color:var(--rp-text-muted); margin:0 0 12px; background:transparent; border:none; text-shadow:none; padding:0; }
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
html[data-theme="dark"] .al-badge-update  { background:#1e3a5f; color:#90cdf4; }
html[data-theme="dark"] .al-badge-award   { background:#1a3a2a; color:#68d391; }
html[data-theme="dark"] .al-badge-remove  { background:#3b1a1a; color:#fc8181; }
html[data-theme="dark"] .al-badge-move    { background:#3b2f0f; color:#f6e05e; }
html[data-theme="dark"] .al-badge-merge   { background:#2d1f47; color:#b794f4; }
html[data-theme="dark"] .al-badge-suspend { background:#3b1a1a; color:#fc8181; }
html[data-theme="dark"] .al-badge-attend  { background:#1a2f4a; color:#76c7f0; }
html[data-theme="dark"] .al-badge-default { background:#2d3748; color:#a0aec0; }
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
.al-diff-val      { color:#4a5568; max-width:220px; word-break:break-all; }
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
html[data-theme="dark"] .al-filter-card  { background:#2d3748; border-color:#4a5568; }
html[data-theme="dark"] .al-filter-card h3 { color:#a0aec0; background:transparent; border:none; text-shadow:none; }
html[data-theme="dark"] .al-form-group label { color:#a0aec0; }
html[data-theme="dark"] .al-form-input   { background:#1a202c; border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .al-table th     { background:#374151; }
html[data-theme="dark"] .al-table tbody tr:hover { background:#374151; }
html[data-theme="dark"] .al-detail-row td { background:#374151; }
html[data-theme="dark"] .al-diff-table th { background:#374151; color:#e2e8f0; }
html[data-theme="dark"] .al-diff-table td { border-color:#4a5568; color:#e2e8f0; }
html[data-theme="dark"] .al-diff-field   { color:#a0aec0; }
html[data-theme="dark"] .al-diff-val     { color:#e2e8f0; }
html[data-theme="dark"] .al-diff-old     { color:#fc8181; }
html[data-theme="dark"] .al-diff-new     { color:#68d391; }
html[data-theme="dark"] .al-diff-changed  { background:rgba(255,237,153,0.08); }
html[data-theme="dark"] .al-page-link     { border-color:#4a5568; color:#90cdf4; }
html[data-theme="dark"] .al-page-link:hover { background:#374151; }
html[data-theme="dark"] .al-btn-clear     { border-color:#4a5568; color:#a0aec0; }
html[data-theme="dark"] .al-table         { color:#e2e8f0; }

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
						<label>By Whom</label>
						<input class="al-form-input al-player-text" id="alByWhomText" type="text" autocomplete="off"
						       placeholder="Search name or paste ID…"
						       value="<?=htmlspecialchars($_filterPlayerNames[(int)($ByWhomFilter??0)] ?? ((int)$ByWhomFilter ? '#'.(int)$ByWhomFilter : ''))?>">
						<input type="hidden" name="ByWhomId" id="alByWhomId" value="<?=(int)$ByWhomFilter ?: ''?>">
					</div>
					<div class="al-form-group">
						<label>Affected Player</label>
						<input class="al-form-input al-player-text" id="alEntityText" type="text" autocomplete="off"
						       placeholder="Search name or paste ID…"
						       value="<?=htmlspecialchars($_filterPlayerNames[(int)($EntityFilter??0)] ?? ((int)$EntityFilter ? '#'.(int)$EntityFilter : ''))?>">
						<input type="hidden" name="EntityId" id="alEntityId" value="<?=(int)$EntityFilter ?: ''?>">
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
						if (empty($_r['MethodCall'])) continue;
						$_mc      = $_r['MethodCall'];
						$_label   = $_actionLabels[$_mc] ?? $_mc;
						$_summary = _auditSummary($_mc, $_r['Parameters'], $_r['PriorState'], $_r['PostState'], $_kawardMap, $_parkMap, $_kingdomMap, $_classMap);

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
							<?php
							// For award actions with EntityId=0 (pre-fix records), fall back to mundane_id from post_state
							$_displayEntityId = (int)$_r['EntityId'];
							if (!$_displayEntityId && in_array($_mc, ['Player::AddAward','Player::GiveAward','Player::RemoveAward','Player::revoke_award','Player::ReactivateAward','Player::UpdateAward'])) {
								$_awardState = @json_decode($_r['PostState'], true) ?: (@json_decode($_r['PriorState'], true) ?: []);
								if (!empty($_awardState['mundane_id'])) $_displayEntityId = (int)$_awardState['mundane_id'];
							}
							if ($_displayEntityId > 0):
								$_eName = $_mundaneMap[$_displayEntityId] ?? null; ?>
							<a href="<?=UIR?>Player/profile/<?=$_displayEntityId?>" style="color:var(--rp-accent)">
								<?= $_eName ? htmlspecialchars($_eName) : '#' . $_displayEntityId ?>
							</a>
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
							<?=_auditDetail($_mc, $_r['Parameters'], $_r['PriorState'], $_r['PostState'], $_parkMap, $_kingdomMap, $_mundaneMap, $_eventMap, $_kawardMap, $_classMap)?>
							<div style="margin-top:8px;font-size:11px;color:var(--rp-text-hint)">
								audit id: <?=(int)$_r['Id']?>
							</div>
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

function alInitPlayerPicker(textId, hiddenId) {
	var $txt = $('#' + textId), $hid = $('#' + hiddenId);

	$txt.autocomplete({
		source: function(req, resp) {
			$.getJSON('../orkservice/Search/SearchService.php', {
				Action: 'Search/Player', type: 'all', search: req.term, limit: 8
			}, function(data) {
				var items = [];
				$.each(data || [], function(i, v) {
					items.push({ label: v.Persona + ' (' + v.KAbbr + ':' + v.PAbbr + ')', value: v.MundaneId, persona: v.Persona });
				});
				resp(items);
			});
		},
		minLength: 2,
		focus: function(e, ui) { return false; },
		select: function(e, ui) { $txt.val(ui.item.persona); $hid.val(ui.item.value); return false; }
	});

	function resolveById(id) {
		$.getJSON('<?=UIR?>PlayerAjax/player/' + id + '/info', function(data) {
			if (data && data.status === 0) {
				$txt.val(data.Persona);
				$hid.val(data.MundaneId);
			} else {
				$hid.val(id); // keep the number as-is
			}
		}).fail(function() { $hid.val(id); });
	}

	$txt.on('paste', function() {
		setTimeout(function() {
			var v = $txt.val().trim();
			if (/^\d+$/.test(v) && parseInt(v) > 0) resolveById(parseInt(v));
		}, 100);
	});

	$txt.on('input', function() {
		if ($txt.val().trim() === '') $hid.val('');
	});
}

$(function() {
	alInitPlayerPicker('alByWhomText', 'alByWhomId');
	alInitPlayerPicker('alEntityText', 'alEntityId');
});
</script>
