<?php
/* -----------------------------------------------
   Admin_roles.tpl — RBAC Role Management
   Pre-process template data
   ----------------------------------------------- */
$kid          = (int)($kingdom_id ?? 0);
$kingdomName  = htmlspecialchars($KingdomInfo['KingdomName'] ?? $kingdom_name ?? '');
$entityLabel  = !empty($IsPrinz) ? 'Principality' : 'Kingdom';
$uir          = UIR;

$hasHeraldry = !empty($kingdom_info['Info']['KingdomInfo']['HasHeraldry']);
$heraldryUrl = $hasHeraldry
	? ($kingdom_info['HeraldryUrl']['Url'] ?? (HTTP_KINGDOM_HERALDRY . '0000.jpg'))
	: HTTP_KINGDOM_HERALDRY . '0000.jpg';

// Role assignments (from controller)
$roleAssignments = is_array($RoleAssignments ?? null) ? $RoleAssignments : [];
$availableRoles  = is_array($AvailableRoles ?? null)  ? $AvailableRoles  : [];
$customRoles     = is_array($CustomRoles ?? null)      ? $CustomRoles     : [];
$allPermissions  = is_array($AllPermissions ?? null)   ? $AllPermissions  : [];
$userEffPerms    = is_array($UserEffectivePermissions ?? null) ? $UserEffectivePermissions : [];
$isAdmin         = !empty($IsOrkAdmin);

// Parks for scope selector
$parkList = [];
$parkSummary = is_array($park_summary['KingdomParkAveragesSummary'] ?? null) ? $park_summary['KingdomParkAveragesSummary'] : [];
foreach ($parkSummary as $ps) {
	$parkList[] = ['ParkId' => (int)($ps['ParkId'] ?? 0), 'Name' => $ps['Name'] ?? ''];
}
usort($parkList, function($a, $b) { return strcasecmp($a['Name'], $b['Name']); });

// Group permissions by category for editor
$permsByCategory = [];
foreach ($allPermissions as $key => $def) {
	$cat = $def[3] ?? 'other';
	if (!isset($permsByCategory[$cat])) $permsByCategory[$cat] = [];
	$permsByCategory[$cat][] = ['key' => $key, 'display' => $def[0], 'desc' => $def[1], 'scope' => $def[2]];
}
ksort($permsByCategory);

// Split assignments into kingdom-level vs park-level
$kdAssignments   = [];
$parkAssignments = [];
foreach ($roleAssignments as $a) {
	if (valid_id($a['ParkId'] ?? 0)) {
		$parkAssignments[] = $a;
	} else {
		$kdAssignments[] = $a;
	}
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<!-- =============================================
     AR STYLES (ar- prefix)
     ============================================= -->
<style>
/* Hero */
.ar-hero {
	position: relative; border-radius: 10px; overflow: hidden;
	margin-bottom: 20px; margin-top: 3px; min-height: 120px;
	background: linear-gradient(135deg, #1a365d 0%, #2d3748 60%, #1a202c 100%);
}
.ar-hero-bg {
	position: absolute; top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover; background-position: center; opacity: 0.12; filter: blur(6px);
}
.ar-hero-content {
	position: relative; z-index: 1; display: flex; align-items: center;
	padding: 24px 28px; gap: 18px;
}
.ar-heraldry-frame {
	width: 56px; height: 56px; border-radius: 12px; overflow: hidden;
	border: 2px solid rgba(255,255,255,0.25); flex-shrink: 0; background: rgba(255,255,255,0.08);
}
.ar-heraldry-frame img {
	width: 100%; height: 100%; object-fit: cover; display: block;
	border: none; padding: 0; margin: 0; max-width: none;
}
.ar-hero-info { flex: 1; min-width: 0; }
.ar-hero-title {
	font-size: 22px; font-weight: 700; color: #fff; margin: 0 0 4px;
	background: transparent; border: none; padding: 0; border-radius: 0;
	text-shadow: 0 1px 3px rgba(0,0,0,0.4);
}
.ar-hero-sub { font-size: 13px; color: rgba(255,255,255,0.6); }
.ar-hero-back {
	display: inline-flex; align-items: center; gap: 6px;
	color: rgba(255,255,255,0.7); font-size: 12px; text-decoration: none;
	transition: color 0.15s;
}
.ar-hero-back:hover { color: #fff; }

/* Layout */
.ar-layout { max-width: 1100px; margin: 0 auto; }

/* Card */
.ar-card {
	background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
	box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: hidden;
}
.ar-card-header {
	padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #f7fafc;
	display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.ar-card-title {
	font-size: 15px; font-weight: 700; color: #2d3748; display: flex; align-items: center; gap: 8px;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.ar-card-body { padding: 16px 20px; }
.ar-card-empty { padding: 24px; text-align: center; color: #a0aec0; font-size: 13px; }

/* Table */
.ar-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ar-table th {
	text-align: left; font-weight: 600; color: #718096; font-size: 11px;
	text-transform: uppercase; letter-spacing: 0.04em; padding: 8px 12px;
	border-bottom: 2px solid #e2e8f0; background: transparent;
}
.ar-table td { padding: 10px 12px; border-bottom: 1px solid #edf2f7; color: #2d3748; }
.ar-table tr:last-child td { border-bottom: none; }
.ar-table tr:hover td { background: #f7fafc; }
.ar-table .ar-role-badge {
	display: inline-block; padding: 2px 8px; border-radius: 10px;
	font-size: 11px; font-weight: 600;
}
.ar-role-system { background: #ebf4ff; color: #3182ce; }
.ar-role-custom { background: #f0fff4; color: #38a169; }

/* Buttons */
.ar-btn {
	display: inline-flex; align-items: center; gap: 5px;
	padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600;
	cursor: pointer; border: none; transition: all 0.15s;
}
.ar-btn-primary { background: #3182ce; color: #fff; }
.ar-btn-primary:hover { background: #2b6cb0; }
.ar-btn-danger { background: #e53e3e; color: #fff; }
.ar-btn-danger:hover { background: #c53030; }
.ar-btn-outline {
	background: transparent; color: #4a5568; border: 1.5px solid #cbd5e0;
}
.ar-btn-outline:hover { border-color: #90cdf4; color: #2b6cb0; }
.ar-btn-sm { padding: 4px 10px; font-size: 11px; }

/* Filter row */
.ar-filter-row { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
.ar-filter-row select, .ar-filter-row input {
	padding: 6px 10px; border: 1px solid #cbd5e0; border-radius: 6px;
	font-size: 13px; background: #fff;
}

/* Modal */
.ar-modal-overlay {
	display: none; position: fixed; inset: 0; z-index: 3000;
	background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
}
.ar-modal-overlay.ar-open { display: flex; }
.ar-modal {
	background: #fff; border-radius: 12px; width: 560px; max-width: 96vw;
	max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.ar-modal-header {
	padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
	display: flex; align-items: center; justify-content: space-between;
}
.ar-modal-title {
	font-size: 16px; font-weight: 700; color: #2d3748;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.ar-modal-close {
	background: none; border: none; font-size: 18px; color: #a0aec0;
	cursor: pointer; padding: 4px 8px; border-radius: 4px;
}
.ar-modal-close:hover { color: #e53e3e; background: #fff5f5; }
.ar-modal-body { padding: 16px 20px; }
.ar-modal-footer {
	padding: 12px 20px; border-top: 1px solid #e2e8f0;
	display: flex; gap: 8px; justify-content: flex-end;
}

/* Form fields */
.ar-field { margin-bottom: 12px; }
.ar-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px; }
.ar-field input, .ar-field select, .ar-field textarea {
	width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 6px;
	font-size: 13px; background: #fff; box-sizing: border-box;
}
.ar-field textarea { resize: vertical; min-height: 60px; }
.ar-field-ac { position: relative; }
.ar-field-ac .kn-ac-results { position: absolute; left: 0; right: 0; z-index: 9999; }

/* Permission checklist */
.ar-perm-group { margin-bottom: 14px; }
.ar-perm-group-title {
	font-size: 12px; font-weight: 700; text-transform: uppercase;
	letter-spacing: 0.05em; color: #718096; margin-bottom: 6px;
	padding-bottom: 4px; border-bottom: 1px solid #edf2f7;
}
.ar-perm-item {
	display: flex; align-items: flex-start; gap: 8px; padding: 4px 0;
}
.ar-perm-item input[type="checkbox"] { width: auto; margin-top: 3px; flex-shrink: 0; }
.ar-perm-item-label { font-size: 12px; color: #2d3748; font-weight: 600; }
.ar-perm-item-desc { font-size: 11px; color: #a0aec0; }
.ar-perm-item-locked { opacity: 0.4; }

/* Custom role cards */
.ar-role-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.ar-role-card {
	border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;
	background: #fff; transition: box-shadow 0.15s;
}
.ar-role-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.ar-role-card-name { font-size: 14px; font-weight: 700; color: #2d3748; margin-bottom: 4px; }
.ar-role-card-desc { font-size: 12px; color: #718096; margin-bottom: 8px; line-height: 1.4; }
.ar-role-card-stats { display: flex; gap: 14px; font-size: 11px; color: #a0aec0; margin-bottom: 8px; }
.ar-role-card-actions { display: flex; gap: 6px; }

/* Feedback toast */
.ar-toast {
	position: fixed; bottom: 20px; right: 20px; z-index: 4000;
	padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
	color: #fff; box-shadow: 0 4px 16px rgba(0,0,0,0.2);
	transform: translateY(80px); opacity: 0; transition: all 0.3s;
}
.ar-toast.ar-toast-show { transform: translateY(0); opacity: 1; }
.ar-toast-success { background: #38a169; }
.ar-toast-error { background: #e53e3e; }
</style>

<!-- =============================================
     HERO
     ============================================= -->
<div class="ar-hero">
	<div class="ar-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="ar-hero-content">
		<div class="ar-heraldry-frame">
			<img src="<?= htmlspecialchars($heraldryUrl) ?>" alt="<?= $kingdomName ?>">
		</div>
		<div class="ar-hero-info">
			<a href="<?= $uir ?>Admin/kingdom/<?= $kid ?>" class="ar-hero-back"><i class="fas fa-arrow-left"></i> Back to <?= $entityLabel ?> Admin</a>
			<h1 class="ar-hero-title">RBAC Role Management</h1>
			<div class="ar-hero-sub"><?= $kingdomName ?> &mdash; <?= $entityLabel ?></div>
		</div>
	</div>
</div>

<div class="ar-layout">

<!-- =============================================
     SECTION 1: KINGDOM ROLE ASSIGNMENTS
     ============================================= -->
<div class="ar-card">
	<div class="ar-card-header">
		<h2 class="ar-card-title"><i class="fas fa-crown"></i> <?= $entityLabel ?> Role Assignments</h2>
		<button class="ar-btn ar-btn-primary" onclick="arOpenAssignModal()"><i class="fas fa-plus"></i> Assign Role</button>
	</div>
	<div class="ar-card-body">
		<div class="ar-filter-row">
			<select class="ar-filter-role-select" data-table="ar-kd-assignments-table" onchange="arFilterAssignments(this)">
				<option value="">All Roles</option>
				<?php foreach ($availableRoles as $r): ?>
				<option value="<?= (int)$r['RoleId'] ?>"><?= htmlspecialchars($r['DisplayName']) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php if (empty($kdAssignments)): ?>
		<div class="ar-card-empty">No <?= strtolower($entityLabel) ?>-level role assignments.</div>
		<?php else: ?>
		<table class="ar-table ar-assignments-table" id="ar-kd-assignments-table">
			<thead>
				<tr>
					<th>Player</th>
					<th>Role</th>
					<th>Granted By</th>
					<th>Date</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($kdAssignments as $a): ?>
				<tr data-role-id="<?= (int)$a['RoleId'] ?>" data-ur-id="<?= (int)$a['UserRoleId'] ?>">
					<td>
						<a href="<?= $uir ?>Player/index/<?= (int)$a['MundaneId'] ?>" style="color:#3182ce;text-decoration:none;font-weight:600">
							<?= htmlspecialchars($a['Persona'] ?: $a['Username']) ?>
						</a>
					</td>
					<td>
						<span class="ar-role-badge <?= $a['IsSystem'] ? 'ar-role-system' : 'ar-role-custom' ?>">
							<?= htmlspecialchars($a['RoleDisplayName']) ?>
						</span>
					</td>
					<td><?= htmlspecialchars($a['GranterPersona'] ?? 'System') ?></td>
					<td><?= $a['CreatedAt'] ? date('M j, Y', strtotime($a['CreatedAt'])) : '&mdash;' ?></td>
					<td>
						<button class="ar-btn ar-btn-danger ar-btn-sm" onclick="arRevokeRole(<?= (int)$a['UserRoleId'] ?>, '<?= htmlspecialchars($a['Persona'] ?: $a['Username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['RoleDisplayName'], ENT_QUOTES) ?>')">
							<i class="fas fa-times"></i> Revoke
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>

<!-- =============================================
     SECTION 2: PARK ROLE ASSIGNMENTS
     ============================================= -->
<div class="ar-card">
	<div class="ar-card-header">
		<h2 class="ar-card-title"><i class="fas fa-map-marker-alt"></i> Park Role Assignments</h2>
	</div>
	<div class="ar-card-body">
		<div class="ar-filter-row">
			<select class="ar-filter-role-select" data-table="ar-park-assignments-table" onchange="arFilterAssignments(this)">
				<option value="">All Roles</option>
				<?php foreach ($availableRoles as $r): ?>
				<option value="<?= (int)$r['RoleId'] ?>"><?= htmlspecialchars($r['DisplayName']) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php if (empty($parkAssignments)): ?>
		<div class="ar-card-empty">No park-level role assignments.</div>
		<?php else: ?>
		<table class="ar-table ar-assignments-table" id="ar-park-assignments-table">
			<thead>
				<tr>
					<th>Player</th>
					<th>Role</th>
					<th>Park</th>
					<th>Granted By</th>
					<th>Date</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($parkAssignments as $a): ?>
				<tr data-role-id="<?= (int)$a['RoleId'] ?>" data-ur-id="<?= (int)$a['UserRoleId'] ?>">
					<td>
						<a href="<?= $uir ?>Player/index/<?= (int)$a['MundaneId'] ?>" style="color:#3182ce;text-decoration:none;font-weight:600">
							<?= htmlspecialchars($a['Persona'] ?: $a['Username']) ?>
						</a>
					</td>
					<td>
						<span class="ar-role-badge <?= $a['IsSystem'] ? 'ar-role-system' : 'ar-role-custom' ?>">
							<?= htmlspecialchars($a['RoleDisplayName']) ?>
						</span>
					</td>
					<td><?= htmlspecialchars($a['ParkName'] ?? '') ?></td>
					<td><?= htmlspecialchars($a['GranterPersona'] ?? 'System') ?></td>
					<td><?= $a['CreatedAt'] ? date('M j, Y', strtotime($a['CreatedAt'])) : '&mdash;' ?></td>
					<td>
						<button class="ar-btn ar-btn-danger ar-btn-sm" onclick="arRevokeRole(<?= (int)$a['UserRoleId'] ?>, '<?= htmlspecialchars($a['Persona'] ?: $a['Username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['RoleDisplayName'], ENT_QUOTES) ?>')">
							<i class="fas fa-times"></i> Revoke
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>

<!-- =============================================
     SECTION 3: CUSTOM ROLES
     ============================================= -->
<div class="ar-card">
	<div class="ar-card-header">
		<h2 class="ar-card-title"><i class="fas fa-puzzle-piece"></i> Custom Roles</h2>
		<button class="ar-btn ar-btn-primary" onclick="arOpenCreateRoleModal()"><i class="fas fa-plus"></i> Create Role</button>
	</div>
	<div class="ar-card-body">
		<?php if (empty($customRoles)): ?>
		<div class="ar-card-empty">No custom roles created for this <?= strtolower($entityLabel) ?> yet.</div>
		<?php else: ?>
		<div class="ar-role-cards">
			<?php foreach ($customRoles as $cr): ?>
			<div class="ar-role-card" data-role-id="<?= (int)$cr['RoleId'] ?>">
				<div class="ar-role-card-name"><?= htmlspecialchars($cr['DisplayName']) ?></div>
				<div class="ar-role-card-desc"><?= htmlspecialchars($cr['Description'] ?: 'No description') ?></div>
				<div class="ar-role-card-stats">
					<span><i class="fas fa-key"></i> <?= (int)$cr['PermCount'] ?> permissions</span>
					<span><i class="fas fa-users"></i> <?= (int)$cr['UserCount'] ?> users</span>
				</div>
				<div class="ar-role-card-actions">
					<button class="ar-btn ar-btn-outline ar-btn-sm" onclick="arOpenEditRoleModal(<?= (int)$cr['RoleId'] ?>, <?= htmlspecialchars(json_encode($cr), ENT_QUOTES) ?>)"><i class="fas fa-edit"></i> Edit</button>
					<button class="ar-btn ar-btn-danger ar-btn-sm" onclick="arDeleteRole(<?= (int)$cr['RoleId'] ?>, '<?= htmlspecialchars($cr['DisplayName'], ENT_QUOTES) ?>')"><i class="fas fa-trash"></i> Delete</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

</div><!-- /.ar-layout -->

<!-- =============================================
     MODAL: ASSIGN ROLE
     ============================================= -->
<div class="ar-modal-overlay" id="ar-assign-overlay">
	<div class="ar-modal">
		<div class="ar-modal-header">
			<h3 class="ar-modal-title">Assign Role</h3>
			<button class="ar-modal-close" onclick="arCloseModal('ar-assign-overlay')">&times;</button>
		</div>
		<div class="ar-modal-body">
			<div class="ar-field ar-field-ac">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ar-assign-player-name" autocomplete="off" placeholder="Search for a player...">
				<input type="hidden" id="ar-assign-player-id">
				<div class="kn-ac-results" id="ar-assign-player-results"></div>
			</div>
			<div class="ar-field">
				<label>Role <span style="color:#e53e3e">*</span></label>
				<select id="ar-assign-role-id">
					<option value="">Select a role...</option>
					<?php foreach ($availableRoles as $r): ?>
					<option value="<?= (int)$r['RoleId'] ?>"><?= htmlspecialchars($r['DisplayName']) ?> <?= $r['IsSystem'] ? '(System)' : '(Custom)' ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ar-field">
				<label>Scope</label>
				<select id="ar-assign-scope-type" onchange="arToggleScopeId()">
					<option value="kingdom"><?= $entityLabel ?></option>
					<option value="park">Specific Park</option>
				</select>
			</div>
			<div class="ar-field" id="ar-assign-park-wrap" style="display:none">
				<label>Park</label>
				<select id="ar-assign-scope-id">
					<?php foreach ($parkList as $pk): ?>
					<option value="<?= (int)$pk['ParkId'] ?>"><?= htmlspecialchars($pk['Name']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<div class="ar-modal-footer">
			<button class="ar-btn ar-btn-outline" onclick="arCloseModal('ar-assign-overlay')">Cancel</button>
			<button class="ar-btn ar-btn-primary" onclick="arSubmitAssign()"><i class="fas fa-check"></i> Assign</button>
		</div>
	</div>
</div>

<!-- =============================================
     MODAL: CREATE / EDIT ROLE
     ============================================= -->
<div class="ar-modal-overlay" id="ar-role-editor-overlay">
	<div class="ar-modal" style="width:640px">
		<div class="ar-modal-header">
			<h3 class="ar-modal-title" id="ar-role-editor-title">Create Custom Role</h3>
			<button class="ar-modal-close" onclick="arCloseModal('ar-role-editor-overlay')">&times;</button>
		</div>
		<div class="ar-modal-body">
			<input type="hidden" id="ar-role-editor-id" value="">
			<div class="ar-field">
				<label>Machine Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ar-role-editor-name" placeholder="e.g. herald_assistant" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only">
			</div>
			<div class="ar-field">
				<label>Display Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ar-role-editor-display" placeholder="e.g. Herald Assistant">
			</div>
			<div class="ar-field">
				<label>Description</label>
				<textarea id="ar-role-editor-desc" placeholder="Describe what this role is for..."></textarea>
			</div>
			<div class="ar-field">
				<label>Permissions</label>
				<div id="ar-role-editor-perms" style="max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
					<?php foreach ($permsByCategory as $cat => $perms): ?>
					<div class="ar-perm-group">
						<div class="ar-perm-group-title"><?= htmlspecialchars(ucfirst($cat)) ?></div>
						<?php foreach ($perms as $perm):
							$canGrant = $isAdmin || in_array($perm['key'], $userEffPerms);
						?>
						<div class="ar-perm-item <?= $canGrant ? '' : 'ar-perm-item-locked' ?>">
							<input type="checkbox" name="ar-perm[]" value="<?= htmlspecialchars($perm['key']) ?>" <?= $canGrant ? '' : 'disabled' ?>>
							<div>
								<div class="ar-perm-item-label"><?= htmlspecialchars($perm['display']) ?> <span style="color:#a0aec0;font-size:10px">[<?= htmlspecialchars($perm['scope']) ?>]</span></div>
								<div class="ar-perm-item-desc"><?= htmlspecialchars($perm['desc']) ?></div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<div class="ar-modal-footer">
			<button class="ar-btn ar-btn-outline" onclick="arCloseModal('ar-role-editor-overlay')">Cancel</button>
			<button class="ar-btn ar-btn-primary" onclick="arSubmitRoleEditor()"><i class="fas fa-save"></i> Save Role</button>
		</div>
	</div>
</div>

<!-- =============================================
     CONFIRM OVERLAY
     ============================================= -->
<div class="ar-modal-overlay" id="ar-confirm-overlay">
	<div class="ar-modal" style="width:400px">
		<div class="ar-modal-header">
			<h3 class="ar-modal-title">Confirm</h3>
			<button class="ar-modal-close" onclick="arCloseModal('ar-confirm-overlay')">&times;</button>
		</div>
		<div class="ar-modal-body">
			<p id="ar-confirm-msg" style="font-size:14px;color:#2d3748;margin:0"></p>
		</div>
		<div class="ar-modal-footer">
			<button class="ar-btn ar-btn-outline" onclick="arCloseModal('ar-confirm-overlay')">Cancel</button>
			<button class="ar-btn ar-btn-danger" id="ar-confirm-btn" onclick="arConfirmAction()">Confirm</button>
		</div>
	</div>
</div>

<!-- Toast -->
<div class="ar-toast" id="ar-toast"></div>

<!-- =============================================
     JAVASCRIPT
     ============================================= -->
<script>
(function() {
	var ArConfig = {
		kid: <?= $kid ?>,
		uir: '<?= $uir ?>',
		isAdmin: <?= $isAdmin ? 'true' : 'false' ?>
	};

	// === TOAST ===
	var toastTimer = null;
	function arToast(msg, type) {
		var el = document.getElementById('ar-toast');
		el.textContent = msg;
		el.className = 'ar-toast ar-toast-' + (type || 'success') + ' ar-toast-show';
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function() { el.classList.remove('ar-toast-show'); }, 3000);
	}
	window.arToast = arToast;

	// === MODAL HELPERS ===
	window.arCloseModal = function(id) {
		document.getElementById(id).classList.remove('ar-open');
	};
	function arOpenModal(id) {
		document.getElementById(id).classList.add('ar-open');
	}

	// === FILTER ASSIGNMENTS ===
	window.arFilterAssignments = function(selectEl) {
		var roleId = selectEl.value;
		var tableId = selectEl.dataset.table;
		var rows = document.querySelectorAll('#' + tableId + ' tbody tr');
		rows.forEach(function(row) {
			if (!roleId || row.dataset.roleId === roleId) {
				row.style.display = '';
			} else {
				row.style.display = 'none';
			}
		});
	};

	// === ASSIGN ROLE MODAL ===
	window.arOpenAssignModal = function() {
		document.getElementById('ar-assign-player-name').value = '';
		document.getElementById('ar-assign-player-id').value = '';
		document.getElementById('ar-assign-role-id').value = '';
		document.getElementById('ar-assign-scope-type').value = 'kingdom';
		document.getElementById('ar-assign-park-wrap').style.display = 'none';
		arOpenModal('ar-assign-overlay');
	};

	window.arToggleScopeId = function() {
		var st = document.getElementById('ar-assign-scope-type').value;
		document.getElementById('ar-assign-park-wrap').style.display = st === 'park' ? '' : 'none';
	};

	window.arSubmitAssign = function() {
		var playerId  = document.getElementById('ar-assign-player-id').value;
		var roleId    = document.getElementById('ar-assign-role-id').value;
		var scopeType = document.getElementById('ar-assign-scope-type').value;
		var scopeId   = scopeType === 'park'
			? document.getElementById('ar-assign-scope-id').value
			: ArConfig.kid;

		if (!playerId) { arToast('Select a player.', 'error'); return; }
		if (!roleId) { arToast('Select a role.', 'error'); return; }

		var fd = new FormData();
		fd.append('MundaneId', playerId);
		fd.append('RoleId', roleId);
		fd.append('ScopeType', scopeType);
		fd.append('ScopeId', scopeId);

		fetch(ArConfig.uir + 'KingdomAjax/rbac/' + ArConfig.kid + '/grantrole', {
			method: 'POST', body: fd
		}).then(function(r) { return r.json(); }).then(function(d) {
			if (d.status === 0) {
				arToast('Role assigned successfully.');
				arCloseModal('ar-assign-overlay');
				setTimeout(function() { location.reload(); }, 800);
			} else {
				arToast(d.error || 'Failed to assign role.', 'error');
			}
		}).catch(function() { arToast('Network error.', 'error'); });
	};

	// === REVOKE ROLE ===
	var pendingAction = null;
	window.arRevokeRole = function(userRoleId, persona, roleName) {
		document.getElementById('ar-confirm-msg').textContent =
			'Revoke "' + roleName + '" from ' + persona + '?';
		pendingAction = function() {
			var fd = new FormData();
			fd.append('UserRoleId', userRoleId);
			fetch(ArConfig.uir + 'KingdomAjax/rbac/' + ArConfig.kid + '/revokerole', {
				method: 'POST', body: fd
			}).then(function(r) { return r.json(); }).then(function(d) {
				if (d.status === 0) {
					arToast('Role revoked.');
					arCloseModal('ar-confirm-overlay');
					var row = document.querySelector('tr[data-ur-id="' + userRoleId + '"]');
					if (row) row.remove();
				} else {
					arToast(d.error || 'Failed to revoke role.', 'error');
				}
			}).catch(function() { arToast('Network error.', 'error'); });
		};
		arOpenModal('ar-confirm-overlay');
	};

	window.arConfirmAction = function() {
		if (pendingAction) pendingAction();
		pendingAction = null;
	};

	// === CREATE ROLE MODAL ===
	window.arOpenCreateRoleModal = function() {
		document.getElementById('ar-role-editor-id').value = '';
		document.getElementById('ar-role-editor-name').value = '';
		document.getElementById('ar-role-editor-name').disabled = false;
		document.getElementById('ar-role-editor-display').value = '';
		document.getElementById('ar-role-editor-desc').value = '';
		document.getElementById('ar-role-editor-title').textContent = 'Create Custom Role';
		// Uncheck all permissions
		document.querySelectorAll('#ar-role-editor-perms input[type="checkbox"]').forEach(function(cb) {
			cb.checked = false;
		});
		arOpenModal('ar-role-editor-overlay');
	};

	// === EDIT ROLE MODAL ===
	window.arOpenEditRoleModal = function(roleId, roleData) {
		if (typeof roleData === 'string') roleData = JSON.parse(roleData);
		document.getElementById('ar-role-editor-id').value = roleId;
		document.getElementById('ar-role-editor-name').value = roleData.Name || '';
		document.getElementById('ar-role-editor-name').disabled = true; // Can't change machine name
		document.getElementById('ar-role-editor-display').value = roleData.DisplayName || '';
		document.getElementById('ar-role-editor-desc').value = roleData.Description || '';
		document.getElementById('ar-role-editor-title').textContent = 'Edit Role: ' + (roleData.DisplayName || '');

		// Uncheck all, then check the role's permissions
		document.querySelectorAll('#ar-role-editor-perms input[type="checkbox"]').forEach(function(cb) {
			cb.checked = false;
		});
		if (roleData.Permissions && roleData.Permissions.length) {
			roleData.Permissions.forEach(function(p) {
				var key = p.Key || p.key || p;
				var cb = document.querySelector('#ar-role-editor-perms input[value="' + key + '"]');
				if (cb) cb.checked = true;
			});
		}
		arOpenModal('ar-role-editor-overlay');
	};

	// === SUBMIT ROLE EDITOR ===
	window.arSubmitRoleEditor = function() {
		var roleId  = document.getElementById('ar-role-editor-id').value;
		var name    = document.getElementById('ar-role-editor-name').value.trim();
		var display = document.getElementById('ar-role-editor-display').value.trim();
		var desc    = document.getElementById('ar-role-editor-desc').value.trim();

		if (!name || !display) { arToast('Name and display name are required.', 'error'); return; }

		var perms = [];
		document.querySelectorAll('#ar-role-editor-perms input[type="checkbox"]:checked').forEach(function(cb) {
			perms.push(cb.value);
		});

		var fd = new FormData();
		fd.append('DisplayName', display);
		fd.append('Description', desc);
		fd.append('Permissions', JSON.stringify(perms));

		var url, isEdit = roleId && roleId !== '';
		if (isEdit) {
			fd.append('RoleId', roleId);
			url = ArConfig.uir + 'KingdomAjax/rbac/' + ArConfig.kid + '/editrole';
		} else {
			fd.append('Name', name);
			fd.append('ScopeType', 'kingdom');
			url = ArConfig.uir + 'KingdomAjax/rbac/' + ArConfig.kid + '/createrole';
		}

		fetch(url, { method: 'POST', body: fd })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.status === 0) {
				arToast(isEdit ? 'Role updated.' : 'Role created.');
				arCloseModal('ar-role-editor-overlay');
				setTimeout(function() { location.reload(); }, 800);
			} else {
				arToast(d.error || 'Failed to save role.', 'error');
			}
		}).catch(function() { arToast('Network error.', 'error'); });
	};

	// === DELETE ROLE ===
	window.arDeleteRole = function(roleId, displayName) {
		document.getElementById('ar-confirm-msg').textContent =
			'Delete role "' + displayName + '"? All assignments will be removed.';
		pendingAction = function() {
			var fd = new FormData();
			fd.append('RoleId', roleId);
			fetch(ArConfig.uir + 'KingdomAjax/rbac/' + ArConfig.kid + '/deleterole', {
				method: 'POST', body: fd
			}).then(function(r) { return r.json(); }).then(function(d) {
				if (d.status === 0) {
					arToast('Role deleted.');
					arCloseModal('ar-confirm-overlay');
					var card = document.querySelector('.ar-role-card[data-role-id="' + roleId + '"]');
					if (card) card.remove();
				} else {
					arToast(d.error || 'Failed to delete role.', 'error');
				}
			}).catch(function() { arToast('Network error.', 'error'); });
		};
		arOpenModal('ar-confirm-overlay');
	};

	// === PLAYER SEARCH AUTOCOMPLETE (kn-ac-results pattern) ===
	function arBindPlayerAc(opts) {
		var input   = document.getElementById(opts.inputId);
		var hidden  = document.getElementById(opts.hiddenId);
		var results = document.getElementById(opts.resultsId);
		if (!input || !results) return;
		var timer = null, minLen = 2;

		function acClose() { results.classList.remove('kn-ac-open'); results.innerHTML = ''; }
		function acOpen(items) {
			if (!items.length) {
				results.innerHTML = '<div class="kn-ac-item" style="color:#a0aec0;pointer-events:none">No results</div>';
				// For modal dropdowns, use position:fixed
				arFixAcPosition(input, results);
				results.classList.add('kn-ac-open');
				return;
			}
			results.innerHTML = items.map(function(item) {
				return '<div class="kn-ac-item" tabindex="-1" data-id="' + item.id
					+ '" data-name="' + encodeURIComponent(item.label)
					+ '">' + item.label + '<span style="color:#a0aec0;font-size:11px;margin-left:6px">' + (item.sub || '') + '</span></div>';
			}).join('');
			// position:fixed for modals
			arFixAcPosition(input, results);
			results.classList.add('kn-ac-open');
		}
		function selectItem(item) {
			input.value  = decodeURIComponent(item.dataset.name);
			hidden.value = item.dataset.id;
			acClose();
		}
		input.addEventListener('input', function() {
			var q = input.value.trim();
			hidden.value = '';
			if (q.length < minLen) { acClose(); return; }
			clearTimeout(timer);
			timer = setTimeout(function() {
				fetch(ArConfig.uir + 'KingdomAjax/playersearch/' + ArConfig.kid + '?q=' + encodeURIComponent(q))
				.then(function(r) { return r.json(); })
				.then(function(d) {
					var items = (d.results || []).map(function(p) {
						return { id: p.MundaneId || p.mundane_id, label: p.Persona || p.persona, sub: p.ParkName || p.park || '' };
					});
					acOpen(items);
				}).catch(function() { acClose(); });
			}, 250);
		});
		results.addEventListener('click', function(e) {
			var item = e.target.closest('.kn-ac-item');
			if (item && item.dataset.id) selectItem(item);
		});
		document.addEventListener('click', function(e) {
			if (!input.contains(e.target) && !results.contains(e.target)) acClose();
		});
	}

	// Fixed position helper for autocomplete in modals
	function arFixAcPosition(inputEl, dropdownEl) {
		var rect = inputEl.getBoundingClientRect();
		dropdownEl.style.position = 'fixed';
		dropdownEl.style.left = rect.left + 'px';
		dropdownEl.style.top = rect.bottom + 'px';
		dropdownEl.style.width = rect.width + 'px';
	}

	// Initialize player search autocomplete
	arBindPlayerAc({
		inputId: 'ar-assign-player-name',
		hiddenId: 'ar-assign-player-id',
		resultsId: 'ar-assign-player-results'
	});

})();
</script>
