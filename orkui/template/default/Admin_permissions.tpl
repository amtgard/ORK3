<?php
	$type       = $PermType      ?? 'Kingdom';
	$entityId   = (int)($PermId  ?? 0);
	$entityName = $PermName      ?? '';
	$entityUrl  = $PermUrl       ?? (UIR . ($type === 'Kingdom' ? 'Kingdom/profile/' : 'Park/profile/') . $entityId);
	$auths      = is_array($PermAuths)     ? $PermAuths     : [];
	$parkAuths  = is_array($PermParkAuths) ? $PermParkAuths : [];
	$ajaxBaseMap = [
		'Kingdom' => UIR . 'KingdomAjax/kingdom/' . $entityId . '/',
		'Park'    => UIR . 'ParkAjax/park/'        . $entityId . '/',
		'Event'   => UIR . 'EventAjax/auth/'       . $entityId . '/',
	];
	$ajaxBase      = $ajaxBaseMap[$type] ?? $ajaxBaseMap['Park'];
	$canGrantAdmin = !empty($PermCanGrantAdmin);

	$eventCreator            = $PermEventCreator            ?? null;
	$inheritedParkAuths      = is_array($PermInheritedParkAuths)    ? $PermInheritedParkAuths    : [];
	$inheritedKingdomAuths   = is_array($PermInheritedKingdomAuths) ? $PermInheritedKingdomAuths : [];
	$inheritedParkName       = $PermInheritedParkName    ?? '';
	$inheritedKingdomName    = $PermInheritedKingdomName ?? '';

	// Group park auths by park name for rendering
	$parkAuthsByPark = [];
	foreach ($parkAuths as $a) {
		$parkAuthsByPark[$a['ParkName']][] = $a;
	}
	ksort($parkAuthsByPark);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
/* Green accent override */
.rp-root { --rp-accent-dark: #1a3d2b; --rp-accent: #276749; --rp-accent-mid: #38a169; }

/* Add-permission card */
.ap-card { background:#fff; border:1px solid var(--rp-border); border-radius:8px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.ap-card-header { border-radius:8px 8px 0 0; }
.ap-card-header { background:var(--rp-bg-light); border-bottom:1px solid var(--rp-border); padding:9px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--rp-text-muted); display:flex; align-items:center; gap:7px; }
.ap-card-body { padding:16px 18px; }
.ap-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.ap-field { display:flex; flex-direction:column; gap:5px; flex:1; min-width:180px; }
.ap-field label { font-size:11px; font-weight:700; color:var(--rp-text-muted); text-transform:uppercase; letter-spacing:.05em; }
.ap-field input,
.ap-field select { height:34px; border:1px solid #cbd5e0; border-radius:5px; padding:0 10px; font-size:13px; color:var(--rp-text); background:#fff; width:100%; box-sizing:border-box; font-family:inherit; }
.ap-field input:focus,
.ap-field select:focus { outline:none; border-color:var(--rp-accent-mid); box-shadow:0 0 0 2px rgba(56,161,105,.15); }
.ap-btn { height:34px; padding:0 18px; border-radius:5px; font-size:13px; font-weight:600; cursor:pointer; border:none; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; background:var(--rp-accent); color:#fff; font-family:inherit; }
.ap-btn:hover { background:var(--rp-accent-dark); }
.ap-btn:disabled { opacity:.5; cursor:not-allowed; }
.ap-feedback { font-size:13px; font-weight:600; margin-top:10px; display:none; }
.ap-feedback.ok  { color:var(--rp-accent); }
.ap-feedback.err { color:#e53e3e; }

/* Section headers between tables */
.ap-section { font-size:13px; font-weight:700; color:var(--rp-text-body); margin:24px 0 10px; display:flex; align-items:center; gap:7px; padding-bottom:7px; border-bottom:1px solid var(--rp-border); }
.ap-section i { color:var(--rp-text-muted); }

/* Explainer box */
.ap-explainer { background:#f0faf4; border:1px solid #c6e8d4; border-radius:8px; padding:14px 16px; margin-bottom:14px; font-size:13px; color:var(--rp-text-body); }
.ap-explainer-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#276749; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.ap-roles { display:flex; gap:10px; flex-wrap:wrap; }
.ap-role-block { flex:1; min-width:160px; background:#fff; border:1px solid #c6e8d4; border-radius:6px; padding:10px 12px; }
.ap-role-block-title { display:flex; align-items:center; gap:7px; margin-bottom:6px; }
.ap-role-block ul { margin:0; padding-left:16px; font-size:12px; color:var(--rp-text-muted); line-height:1.6; }
.ap-role-block ul li { margin-bottom:1px; }

/* Park group header row inside second table */
.ap-park-row td { background:var(--rp-bg-light); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--rp-text-muted); padding:6px 10px; border-bottom:1px solid var(--rp-border); }

/* Shared table styles */
.ap-table { width:100%; border-collapse:collapse; font-size:13px; }
.ap-table th { background:var(--rp-bg-light); border-bottom:2px solid var(--rp-border); padding:8px 10px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--rp-text-muted); white-space:nowrap; }
.ap-table th:last-child { text-align:right; }
.ap-table td { padding:10px 10px; border-bottom:1px solid #f0f4f8; vertical-align:middle; color:var(--rp-text); }
.ap-table tr:last-child td { border-bottom:none; }
.ap-table tr:hover td { background:#fafbfc; }

/* Role badges */
.ap-role { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.ap-role-create { background:#ebf8f3; color:#276749; }
.ap-role-edit   { background:#ebf4ff; color:#2b6cb0; }
.ap-role-admin  { background:#fff5f5; color:#c53030; }

/* Officer role pill */
.ap-officer { display:inline-block; padding:2px 7px; border-radius:10px; font-size:10px; font-weight:600; background:#fef3c7; color:#92400e; letter-spacing:.03em; margin-left:5px; }

/* Delete button */
.ap-del { background:none; border:1px solid #fed7d7; color:#e53e3e; border-radius:4px; padding:3px 10px; font-size:12px; cursor:pointer; font-family:inherit; }
.ap-del:hover { background:#fff5f5; }
.ap-del.ap-del-confirm { background:#e53e3e; color:#fff; border-color:#e53e3e; }

/* Empty states */
.ap-empty { padding:28px; text-align:center; color:var(--rp-text-hint); font-size:13px; }
.ap-empty i { font-size:20px; display:block; margin-bottom:8px; opacity:.4; }

.kn-ac-results { position:absolute; top:100%; left:0; right:0; z-index:9999; margin-top:4px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.12); max-height:220px; overflow-y:auto; display:none; }
.kn-ac-results.kn-ac-open { display:block; }
.kn-ac-item { padding:8px 12px; font-size:13px; cursor:pointer; color:#2d3748; border-bottom:1px solid #f7fafc; }
.kn-ac-item:last-child { border-bottom:none; }
.kn-ac-item:hover, .kn-ac-item.kn-ac-focused { background:#ebf4ff; color:#2c7a7b; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-user-shield rp-header-icon"></i>
				<h1 class="rp-header-title">Roles &amp; Permissions</h1>
			</div>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?= htmlspecialchars($entityUrl) ?>">
					<?php $scopeIcon = $type === 'Kingdom' ? 'chess-rook' : ($type === 'Event' ? 'calendar-alt' : 'tree'); ?>
					<i class="fas fa-<?= $scopeIcon ?>"></i>
					<span class="rp-scope-chip-label">Scope:</span>
					<?= htmlspecialchars($entityName) ?>
				</a>
			</div>
		</div>
		<div class="rp-header-actions">
			<a class="rp-btn-ghost" href="<?= htmlspecialchars($entityUrl) ?>">
				<i class="fas fa-arrow-left"></i> Back to <?= htmlspecialchars($type) ?>
			</a>
		</div>
	</div>

	<!-- Add Permission -->
	<div class="ap-card" style="margin-top:18px">
		<div class="ap-card-header">
			<i class="fas fa-plus-circle"></i>
			Add <?= htmlspecialchars($type) ?>-Level Permission
		</div>
		<div class="ap-card-body">
			<div class="ap-row">
				<div class="ap-field" style="flex:2">
					<label>Player</label>
					<div style="position:relative">
						<input type="text" id="ap-player-input" placeholder="Search by persona or username&hellip;" autocomplete="off">
						<input type="hidden" id="ap-player-id">
						<div class="kn-ac-results" id="ap-player-results"></div>
					</div>
				</div>
				<div class="ap-field" style="flex:1;min-width:140px">
					<label>Role</label>
					<select id="ap-role-select">
						<option value="create">Create</option>
						<option value="edit">Edit</option>
						<?php if ($canGrantAdmin && $type !== 'Event'): ?>
						<option value="admin">Administrator</option>
						<?php endif; ?>
					</select>
				</div>
				<button class="ap-btn" id="ap-add-btn" disabled>
					<i class="fas fa-plus"></i> Add
				</button>
			</div>
			<div class="ap-feedback" id="ap-feedback"></div>
		</div>
	</div>

	<!-- ── Table 1: Kingdom-level grants ─────────────────────── -->
	<div class="ap-section">
		<i class="fas fa-chess-rook"></i>
		<?= htmlspecialchars($entityName) ?> <?= htmlspecialchars($type) ?>-Level Permissions
	</div>

	<div class="ap-explainer">
		<div class="ap-explainer-title"><i class="fas fa-info-circle"></i> What <?= htmlspecialchars($type) ?>-level access controls</div>
		<div class="ap-roles">
			<div class="ap-role-block">
				<div class="ap-role-block-title"><span class="ap-role ap-role-create">Create</span></div>
				<ul>
					<?php if ($type === 'Kingdom'): ?>
					<li>Edit kingdom name, heraldry &amp; settings</li>
					<li>Set and vacate officer roles (Monarch, Regent, etc.)</li>
					<li>Add, edit, and claim parks within the kingdom</li>
					<li>Manage kingdom-level awards and park titles</li>
					<li>Track kingdom-wide attendance</li>
					<li>Grant and revoke permissions for others</li>
					<li>Inherits all park-level access within the kingdom</li>
					<?php elseif ($type === 'Event'): ?>
					<li>Add and remove event attendance</li>
					<li>Manage RSVPs</li>
					<li>Grant and revoke event-level permissions for others</li>
					<?php else: ?>
					<li>Edit park name, heraldry, schedule &amp; settings</li>
					<li>Set and vacate officer roles at the park</li>
					<li>Add, move, and merge players within the park</li>
					<li>Add and manage park events</li>
					<li>Enter awards and manage award recommendations</li>
					<li>Track park attendance</li>
					<li>Edit player records within the park</li>
					<li>Grant and revoke park-level permissions for others</li>
					<?php endif; ?>
				</ul>
			</div>
			<div class="ap-role-block">
				<div class="ap-role-block-title"><span class="ap-role ap-role-edit">Edit</span></div>
				<ul>
					<?php if ($type === 'Kingdom'): ?>
					<li>Track kingdom attendance (add and remove)</li>
					<li>Track kingdom event attendance (add and remove)</li>
					<li>Edit player records within the kingdom</li>
					<li>Cannot manage kingdom settings, events, awards, or grant permissions</li>
					<?php elseif ($type === 'Event'): ?>
					<li>Add and remove event attendance</li>
					<li>Manage RSVPs</li>
					<li>Cannot edit event details, heraldry, or grant permissions</li>
					<?php else: ?>
					<li>Track park attendance (add and remove)</li>
					<li>Track park event attendance (add and remove)</li>
					<li>Edit player records within the park</li>
					<li>Cannot manage park settings, events, awards, or grant permissions</li>
					<?php endif; ?>
				</ul>
			</div>
			<?php if ($type !== 'Event'): ?>
			<div class="ap-role-block">
				<div class="ap-role-block-title"><span class="ap-role ap-role-admin">Administrator</span></div>
				<ul>
					<li>System-wide access — satisfies <em>all</em> authorization checks regardless of scope</li>
					<li>Not scoped to this <?= strtolower(htmlspecialchars($type)) ?> alone</li>
					<li>Use sparingly; prefer Create for local admins</li>
				</ul>
			</div>
			<?php endif; ?>
		</div>
		<?php if ($type === 'Kingdom'): ?>
		<p style="margin:10px 0 0;font-size:12px;color:var(--rp-text-muted)"><i class="fas fa-level-up-alt" style="margin-right:4px"></i><strong>Cascade:</strong> Kingdom Create/Edit access automatically satisfies the same check at any park within the kingdom — no separate park grant needed.</p>
		<?php endif; ?>
	</div>

	<?php if ($type === 'Event'): ?>
	<!-- ── Inherited Access (Event) ──────────────────────────── -->
	<div class="ap-section" style="margin-top:24px">
		<i class="fas fa-sitemap"></i> Inherited Access
	</div>
	<div class="ap-explainer" style="margin-bottom:16px">
		<div class="ap-explainer-title"><i class="fas fa-info-circle"></i> Who can manage this event without an explicit grant</div>
		<p style="margin:0 0 8px;font-size:13px;color:var(--rp-text-body)">These people already have access through the event&rsquo;s parent park or kingdom. You do not need to add them here.</p>
	</div>
	<div class="rp-table-area" style="margin-bottom:24px;border-radius:8px">
		<table class="ap-table">
			<thead>
				<tr>
					<th>Person</th>
					<th>Access Via</th>
					<th>Role</th>
					<th>Officer</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($eventCreator): ?>
				<tr>
					<td>
						<strong><a href="<?= UIR ?>Player/profile/<?= (int)$eventCreator['MundaneId'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($eventCreator['Persona']) ?></a></strong>
						<?php if (!empty($eventCreator['GivenName']) || !empty($eventCreator['Surname'])): ?>
							<span style="color:var(--rp-text-muted);font-size:12px"> — <?= htmlspecialchars(trim($eventCreator['GivenName'] . ' ' . $eventCreator['Surname'])) ?></span>
						<?php endif; ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px"><i class="fas fa-star" style="color:#d4a017;margin-right:4px"></i>Event Creator</td>
					<td><span class="ap-role ap-role-create">Full Access</span></td>
					<td>—</td>
				</tr>
				<?php endif; ?>
				<?php foreach ($inheritedParkAuths as $a): ?>
				<tr>
					<td>
						<?php if ((int)$a['MundaneId'] > 0): ?>
						<strong><a href="<?= UIR ?>Player/profile/<?= (int)$a['MundaneId'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($a['Persona']) ?></a></strong>
						<?php if (!empty($a['GivenName']) || !empty($a['Surname'])): ?>
							<span style="color:var(--rp-text-muted);font-size:12px"> — <?= htmlspecialchars(trim($a['GivenName'] . ' ' . $a['Surname'])) ?></span>
						<?php endif; ?>
						<?php else: ?>
						<span style="color:var(--rp-text-hint);font-style:italic">(Vacant)</span>
						<?php endif; ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px"><i class="fas fa-tree" style="margin-right:4px"></i><?= htmlspecialchars($inheritedParkName) ?> (Park)</td>
					<td><span class="ap-role ap-role-<?= htmlspecialchars($a['Role']) ?>"><?= htmlspecialchars(ucfirst($a['Role'])) ?></span></td>
					<td>
						<?php if (!empty($a['OfficerRole'])): ?>
							<span class="ap-officer"><?= htmlspecialchars($a['OfficerRole']) ?></span>
						<?php else: ?>—<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				<?php foreach ($inheritedKingdomAuths as $a): ?>
				<tr>
					<td>
						<?php if ((int)$a['MundaneId'] > 0): ?>
						<strong><a href="<?= UIR ?>Player/profile/<?= (int)$a['MundaneId'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($a['Persona']) ?></a></strong>
						<?php if (!empty($a['GivenName']) || !empty($a['Surname'])): ?>
							<span style="color:var(--rp-text-muted);font-size:12px"> — <?= htmlspecialchars(trim($a['GivenName'] . ' ' . $a['Surname'])) ?></span>
						<?php endif; ?>
						<?php else: ?>
						<span style="color:var(--rp-text-hint);font-style:italic">(Vacant)</span>
						<?php endif; ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px"><i class="fas fa-chess-rook" style="margin-right:4px"></i><?= htmlspecialchars($inheritedKingdomName) ?> (Kingdom)</td>
					<td><span class="ap-role ap-role-<?= htmlspecialchars($a['Role']) ?>"><?= htmlspecialchars(ucfirst($a['Role'])) ?></span></td>
					<td>
						<?php if (!empty($a['OfficerRole'])): ?>
							<span class="ap-officer"><?= htmlspecialchars($a['OfficerRole']) ?></span>
						<?php else: ?>—<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				<?php if (!$eventCreator && !$inheritedParkAuths && !$inheritedKingdomAuths): ?>
				<tr><td colspan="4" style="color:var(--rp-text-hint);font-size:13px;padding:14px 10px">No inherited access found.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<div class="ap-section">
		<i class="fas fa-key"></i> Event-Specific Grants
	</div>
	<?php endif; ?>

	<div class="rp-table-area" style="margin-bottom:0;border-radius:8px 8px <?= ($type === 'Kingdom' && count($parkAuthsByPark)) ? '0 0' : '8px 8px' ?>">
		<?php if (count($auths) > 0): ?>
		<table class="ap-table" id="ap-kingdom-table">
			<thead>
				<tr>
					<th>Person</th>
					<th>Role</th>
					<th>Officer</th>
					<th>Last Modified</th>
					<?php if ($type !== 'Kingdom'): ?><th></th><?php endif; ?>
					<th></th>
				</tr>
			</thead>
			<tbody id="ap-kingdom-tbody">
				<?php foreach ($auths as $a): ?>
				<tr id="ap-row-<?= (int)$a['AuthorizationId'] ?>">
					<td>
						<?php if ((int)$a['MundaneId'] > 0): ?>
						<strong><a href="<?= UIR ?>Player/profile/<?= (int)$a['MundaneId'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($a['Persona']) ?></a></strong>
						<?php if (!empty($a['GivenName']) || !empty($a['Surname'])): ?>
							<span style="color:var(--rp-text-muted);font-size:12px"> — <?= htmlspecialchars(trim($a['GivenName'] . ' ' . $a['Surname'])) ?></span>
						<?php endif; ?>
						<?php if (!empty($a['UserName'])): ?>
							<div style="font-size:11px;color:var(--rp-text-hint)"><?= htmlspecialchars($a['UserName']) ?></div>
						<?php endif; ?>
						<?php else: ?>
						<span style="color:var(--rp-text-hint);font-style:italic">(Vacant)</span>
						<?php endif; ?>
					</td>
					<td><span class="ap-role ap-role-<?= htmlspecialchars($a['Role']) ?>"><?= htmlspecialchars(ucfirst($a['Role'])) ?></span></td>
					<td>
						<?php if (!empty($a['OfficerRole'])): ?>
							<span class="ap-officer"><?= htmlspecialchars($a['OfficerRole']) ?></span>
						<?php else: ?>
							<span style="color:var(--rp-text-hint);font-size:12px">—</span>
						<?php endif; ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap">
						<?= !empty($a['Modified']) ? date('M j, Y', strtotime($a['Modified'])) : '—' ?>
					</td>
					<td style="text-align:right">
						<?php if (empty($a['OfficerId'])): ?>
							<button class="ap-del" data-id="<?= (int)$a['AuthorizationId'] ?>">Remove</button>
						<?php else: ?>
							<span style="color:var(--rp-text-hint);font-size:11px">via officer</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else: ?>
		<div class="ap-empty" id="ap-kingdom-empty">
			<i class="fas fa-user-shield"></i>
			No <?= strtolower(htmlspecialchars($type)) ?>-level permissions granted yet.
		</div>
		<?php endif; ?>
	</div>

	<?php if ($type === 'Kingdom'): ?>
	<!-- ── Table 2: Park-level grants within this kingdom ────── -->
	<div class="ap-section" style="margin-top:32px">
		<i class="fas fa-tree"></i>
		Park-Level Permissions within <?= htmlspecialchars($entityName) ?>
	</div>

	<div class="ap-explainer">
		<div class="ap-explainer-title"><i class="fas fa-info-circle"></i> What park-level access controls</div>
		<div class="ap-roles">
			<div class="ap-role-block">
				<div class="ap-role-block-title"><span class="ap-role ap-role-create">Create</span></div>
				<ul>
					<li>Edit park name, heraldry, schedule &amp; settings</li>
					<li>Set and vacate officer roles at the park</li>
					<li>Add, move, and merge players within the park</li>
					<li>Add and manage park events</li>
					<li>Enter awards and manage award recommendations</li>
					<li>Track park attendance</li>
					<li>Edit player records within the park</li>
					<li>Grant and revoke park-level permissions for others</li>
				</ul>
			</div>
			<div class="ap-role-block">
				<div class="ap-role-block-title"><span class="ap-role ap-role-edit">Edit</span></div>
				<ul>
					<li>Track park attendance (add and remove)</li>
					<li>Track park event attendance (add and remove)</li>
					<li>Edit player records within the park</li>
					<li>Cannot manage park settings, events, awards, or grant permissions</li>
				</ul>
			</div>
			<div class="ap-role-block">
				<div class="ap-role-block-title"><span class="ap-role ap-role-admin">Administrator</span></div>
				<ul>
					<li>System-wide access — satisfies <em>all</em> authorization checks regardless of scope</li>
					<li>Not scoped to this park alone</li>
					<li>Use sparingly; prefer Create for local park admins</li>
				</ul>
			</div>
		</div>
		<p style="margin:10px 0 0;font-size:12px;color:var(--rp-text-muted)"><i class="fas fa-info-circle" style="margin-right:4px"></i>Officers listed here received their access automatically when assigned their officer role — it will be revoked when the role is vacated.</p>
	</div>

	<div class="rp-table-area" style="border-radius:8px;padding:0">
		<?php if (count($parkAuthsByPark) > 0): ?>
		<table class="ap-table">
			<thead>
				<tr>
					<th>Person</th>
					<th>Role</th>
					<th>Officer</th>
					<th>Last Modified</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($parkAuthsByPark as $parkName => $entries): ?>
				<tr class="ap-park-row">
					<td colspan="5">
						<a href="<?= UIR ?>Park/profile/<?= (int)$entries[0]['ParkId'] ?>" style="color:var(--rp-text-muted);text-decoration:none;">
							<i class="fas fa-tree" style="margin-right:5px;opacity:.6"></i><?= htmlspecialchars($parkName) ?>
						</a>
					</td>
				</tr>
				<?php foreach ($entries as $a): ?>
				<tr id="ap-row-<?= (int)$a['AuthorizationId'] ?>">
					<td>
						<?php if ((int)$a['MundaneId'] > 0): ?>
						<strong><a href="<?= UIR ?>Player/profile/<?= (int)$a['MundaneId'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($a['Persona']) ?></a></strong>
						<?php if (!empty($a['GivenName']) || !empty($a['Surname'])): ?>
							<span style="color:var(--rp-text-muted);font-size:12px"> — <?= htmlspecialchars(trim($a['GivenName'] . ' ' . $a['Surname'])) ?></span>
						<?php endif; ?>
						<?php if (!empty($a['UserName'])): ?>
							<div style="font-size:11px;color:var(--rp-text-hint)"><?= htmlspecialchars($a['UserName']) ?></div>
						<?php endif; ?>
						<?php else: ?>
						<span style="color:var(--rp-text-hint);font-style:italic">(Vacant)</span>
						<?php endif; ?>
					</td>
					<td><span class="ap-role ap-role-<?= htmlspecialchars($a['Role']) ?>"><?= htmlspecialchars(ucfirst($a['Role'])) ?></span></td>
					<td>
						<?php if (!empty($a['OfficerRole'])): ?>
							<span class="ap-officer"><?= htmlspecialchars($a['OfficerRole']) ?></span>
						<?php else: ?>
							<span style="color:var(--rp-text-hint);font-size:12px">—</span>
						<?php endif; ?>
					</td>
					<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap">
						<?= !empty($a['Modified']) ? date('M j, Y', strtotime($a['Modified'])) : '—' ?>
					</td>
					<td style="text-align:right">
						<?php if (empty($a['OfficerId'])): ?>
							<button class="ap-del" data-id="<?= (int)$a['AuthorizationId'] ?>">Remove</button>
						<?php else: ?>
							<span style="color:var(--rp-text-hint);font-size:11px">via officer</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else: ?>
		<div class="ap-empty">
			<i class="fas fa-tree"></i>
			No park-level permissions found within this kingdom.
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

</div><!-- /.rp-root -->

<script>
(function ($) {
	var AJAX_BASE = <?= json_encode($ajaxBase) ?>;

	// ── Autocomplete ──────────────────────────────────────────────
	var AP_PERM_TYPE = <?= json_encode($type) ?>;
	var AP_PERM_ID   = <?= (int)$entityId ?>;
	var AP_UIR       = <?= json_encode(UIR) ?>;

	function apEsc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	// ── Custom autocomplete dropdown ───────────────────────────────
	var apResults = document.getElementById('ap-player-results');
	var apInput   = document.getElementById('ap-player-input');
	var apTimer   = null;
	var apSeq     = 0; // increments on each search to discard stale responses

	function apClose() { apResults.classList.remove('kn-ac-open'); apResults.innerHTML = ''; }

	function apSelect(item) {
		apInput.value = decodeURIComponent(item.dataset.name);
		document.getElementById('ap-player-id').value  = item.dataset.id;
		document.getElementById('ap-add-btn').disabled = false;
		apClose();
		apInput.focus();
	}

	function apOpen(items) {
		if (!items.length) { apClose(); return; }
		apResults.innerHTML = items.map(function (p) {
			return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId
				+ '" data-name="' + encodeURIComponent(p.Persona) + '">'
				+ apEsc(p.Persona)
				+ ' <span style="color:#a0aec0;font-size:11px">(' + apEsc((p.KAbbr||'') + ':' + (p.PAbbr||'')) + ')</span></div>';
		}).join('');
		apResults.classList.add('kn-ac-open');
	}

	apInput.addEventListener('input', function () {
		var term = this.value.trim();
		document.getElementById('ap-player-id').value = '';
		document.getElementById('ap-add-btn').disabled = true;
		clearTimeout(apTimer);
		if (term.length < 2) { apClose(); return; }
		var seq = ++apSeq;
		apTimer = setTimeout(function () {
			var urlA, urlB;
			if (AP_PERM_TYPE === 'Kingdom') {
				urlA = AP_UIR + 'KingdomAjax/playersearch/' + AP_PERM_ID + '&scope=own&q='     + encodeURIComponent(term);
				urlB = AP_UIR + 'KingdomAjax/playersearch/' + AP_PERM_ID + '&scope=exclude&q=' + encodeURIComponent(term);
			} else {
				urlA = AJAX_BASE + 'playersearch&scope=own&q='     + encodeURIComponent(term);
				urlB = AJAX_BASE + 'playersearch&scope=exclude&q=' + encodeURIComponent(term);
			}
			$.when($.getJSON(urlA), $.getJSON(urlB)).then(function (rA, rB) {
				if (seq !== apSeq) return; // input changed while request was in flight
				var seen = {}, items = [];
				(rA[0] || []).forEach(function (p) { if (!seen[p.MundaneId]) { seen[p.MundaneId] = true; items.push(p); } });
				(rB[0] || []).forEach(function (p) { if (!seen[p.MundaneId]) { seen[p.MundaneId] = true; items.push(p); } });
				apOpen(items.slice(0, 15));
			}).fail(function () { if (seq === apSeq) apClose(); });
		}, 220);
	});

	// Keyboard navigation from the input field
	apInput.addEventListener('keydown', function (e) {
		if (!apResults.classList.contains('kn-ac-open')) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			var first = apResults.querySelector('.kn-ac-item');
			if (first) first.focus();
		} else if (e.key === 'Escape') {
			apClose();
		}
	});

	// Keyboard navigation within the dropdown
	apResults.addEventListener('keydown', function (e) {
		var focused = document.activeElement;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			var next = focused.nextElementSibling;
			if (next) next.focus(); else apResults.querySelector('.kn-ac-item').focus();
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			var prev = focused.previousElementSibling;
			if (prev) prev.focus(); else apInput.focus();
		} else if (e.key === 'Enter') {
			e.preventDefault();
			if (focused && focused.dataset.id) apSelect(focused);
		} else if (e.key === 'Escape') {
			apClose();
			apInput.focus();
		}
	});

	apResults.addEventListener('click', function (e) {
		var item = e.target.closest('.kn-ac-item[data-id]');
		if (!item) return;
		apSelect(item);
	});

	document.addEventListener('click', function (e) {
		if (!e.target.closest('#ap-player-input, #ap-player-results')) apClose();
	});

	// ── Add ───────────────────────────────────────────────────────
	function showFb(msg, ok) {
		$('#ap-feedback').text(msg).removeClass('ok err').addClass(ok ? 'ok' : 'err').show();
	}

	$('#ap-add-btn').on('click', function () {
		var btn  = this;
		var mid  = $('#ap-player-id').val();
		var role = $('#ap-role-select').val();
		if (!mid) { showFb('Select a player first.', false); return; }

		$(btn).prop('disabled', true);
		$.post(AJAX_BASE + 'addauth', { MundaneId: mid, Role: role }, function (r) {
			if (r && r.status === 0) {
				showFb('Permission added.', true);
				$('#ap-player-input').val('');
				$('#ap-player-id').val('');

				var badgeHtml = '<span class="ap-role ap-role-' + role + '">' + role.charAt(0).toUpperCase() + role.slice(1) + '</span>';
				var persona   = $('<div>').text(r.persona || '').html();
				var today     = new Date().toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
				var row = '<tr id="ap-row-' + r.authId + '">'
					+ '<td><strong>' + persona + '</strong></td>'
					+ '<td>' + badgeHtml + '</td>'
					+ '<td><span style="color:var(--rp-text-hint);font-size:12px">—</span></td>'
					+ '<td style="color:var(--rp-text-muted);font-size:12px;white-space:nowrap">' + today + '</td>'
					+ '<td style="text-align:right"><button class="ap-del" data-id="' + r.authId + '">Remove</button></td>'
					+ '</tr>';

				var $empty = $('#ap-kingdom-empty');
				if ($empty.length) {
					$empty.replaceWith(
						'<table class="ap-table" id="ap-kingdom-table">'
						+ '<thead><tr><th>Person</th><th>Role</th><th>Officer</th><th>Last Modified</th><th></th></tr></thead>'
						+ '<tbody id="ap-kingdom-tbody"></tbody></table>'
					);
				}
				$('#ap-kingdom-tbody').append(row);
				bindDelete($('#ap-kingdom-tbody tr:last-child .ap-del')[0]);
				$(btn).prop('disabled', false);
			} else {
				$(btn).prop('disabled', false);
				showFb((r && r.error) ? r.error : 'Failed to add permission.', false);
			}
		}, 'json').fail(function () {
			$(btn).prop('disabled', false);
			showFb('Request failed. Try again.', false);
		});
	});

	// ── Delete ────────────────────────────────────────────────────
	function bindDelete(btn) {
		$(btn).on('click', function () {
			var $btn = $(this);
			if (!$btn.hasClass('ap-del-confirm')) {
				$btn.addClass('ap-del-confirm').text('Confirm?');
				$btn[0]._t = setTimeout(function () { $btn.removeClass('ap-del-confirm').text('Remove'); }, 3000);
				return;
			}
			clearTimeout($btn[0]._t);
			$btn.prop('disabled', true).text('…');
			$.post(AJAX_BASE + 'removeauth', { AuthorizationId: $btn.data('id') }, function (r) {
				if (r && r.status === 0) {
					$('#ap-row-' + $btn.data('id')).remove();
				} else {
					$btn.prop('disabled', false).removeClass('ap-del-confirm').text('Remove');
					alert((r && r.error) ? r.error : 'Failed to remove permission.');
				}
			}, 'json').fail(function () {
				$btn.prop('disabled', false).removeClass('ap-del-confirm').text('Remove');
				alert('Request failed. Try again.');
			});
		});
	}

	$('.ap-del').each(function () { bindDelete(this); });

}(jQuery));
</script>
