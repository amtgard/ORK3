<?php
/* ── Data prep ─────────────────────────────────────────── */
$_unit     = $Unit['Details']['Unit'] ?? [];
$_members  = $Unit['Members']['Roster'] ?? [];
$_unit_id  = (int)($_unit['UnitId'] ?? 0);
$_type     = $_unit['Type'] ?? 'Unit';
$_name     = $_unit['Name'] ?? '';
$page_title = $_name;
$_desc     = $_unit['Description'] ?? '';
$_history  = $_unit['History'] ?? '';
$_url      = $_unit['Url'] ?? '';
$_hero_src = !empty($_unit['HasHeraldry']) ? ($Unit_heraldryurl['Url'] ?? '') : (HTTP_UNIT_HERALDRY . '00000.jpg');

$_total    = count($_members);
$_cutoff   = date('Y-m-d', strtotime('-1 year'));
$_active   = 0;
foreach ($_members as $_m) {
	if (!empty($_m['LastSignIn']) && $_m['LastSignIn'] >= $_cutoff) $_active++;
}

$_can_edit   = !empty($LoggedIn);
$_err        = $SaveError ?? '';
$_base_url   = UIR . "Unit/index/$_unit_id";

$_type_icon  = $_type === 'Company' ? 'fa-shield-alt' : ($_type === 'Household' ? 'fa-home' : 'fa-users');
$_hero_color = $_type === 'Company' ? '#1a3654' : ($_type === 'Household' ? '#2d1b54' : '#1a365d');
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>revised-frontend/style/revised.css?v=<?=filemtime(DIR_TEMPLATE.'revised-frontend/style/revised.css')?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<style>
/* ── Unit Hero ───────────────────────────────────────────── */
.un-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-top: 3px;
	margin-bottom: 20px;
	min-height: 160px;
	background-color: <?=$_hero_color?>;
}
.un-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.13;
	filter: blur(6px);
}
.un-hero-content {
	position: relative;
	display: flex;
	align-items: center;
	padding: 24px 30px;
	gap: 24px;
	z-index: 1;
}
.un-heraldry-wrap {
	position: relative;
	flex-shrink: 0;
}
.un-heraldry-frame {
	width: 110px;
	height: 110px;
	border-radius: 8px;
	overflow: hidden;
	border: 3px solid rgba(255,255,255,0.8);
	background: rgba(0,0,0,0.15);
	display: flex;
	align-items: center;
	justify-content: center;
}
.un-heraldry-frame img {
	width: 100%;
	height: 100%;
	object-fit: contain;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 0;
	max-width: none;
	max-height: none;
}
.un-heraldry-edit-btn {
	position: absolute;
	bottom: 4px;
	right: 4px;
	width: 24px;
	height: 24px;
	background: rgba(0,0,0,0.6);
	border-radius: 50%;
	border: none;
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0;
	transition: opacity 0.18s;
	cursor: pointer;
	padding: 0;
}
.un-heraldry-wrap:hover .un-heraldry-edit-btn { opacity: 1; }
.un-heraldry-edit-btn i { color: #fff; font-size: 11px; pointer-events: none; }

.un-hero-info {
	flex: 1;
	min-width: 0;
}
.un-type-badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: rgba(255,255,255,0.9);
	background: rgba(255,255,255,0.18);
	border: 1px solid rgba(255,255,255,0.3);
	border-radius: 4px;
	padding: 3px 8px;
	margin-bottom: 8px;
}
.un-hero-name {
	font-size: 26px;
	font-weight: 700;
	color: #fff;
	margin: 0;
	line-height: 1.2;
	text-shadow: 0 1px 3px rgba(0,0,0,0.35);
	/* Reset global h1–h6 styles from orkui.css */
	background: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
}
.un-hero-actions {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}

/* ── Section header (Members + Add btn) ─────────────────── */
.un-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 10px;
}
.un-section-title {
	font-size: 13px;
	font-weight: 700;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	display: flex;
	align-items: center;
	gap: 6px;
}

/* ── Roster card wrapper ─────────────────────────────────── */
.un-roster-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

/* ── Inline action area ──────────────────────────────────── */
.un-action-btns { display: flex; gap: 4px; white-space: nowrap; }

/* ── Error banner ────────────────────────────────────────── */
.un-error-banner {
	background: #fff5f5;
	border: 1px solid #fed7d7;
	border-radius: 6px;
	color: #c53030;
	padding: 10px 14px;
	font-size: 13px;
	margin-bottom: 16px;
	display: flex;
	align-items: center;
	gap: 8px;
}

/* ── Modal title reset (h3 gets global gray-box from orkui.css) ─ */
.pn-modal-title {
	background: transparent !important;
	border: none !important;
	padding: 0 !important;
	border-radius: 0 !important;
	text-shadow: none !important;
	margin: 0 !important;
}

/* ── Modal field normalization ───────────────────────────── */
/* pn-acct-field covers text/email/password/select/textarea; add url/number/file */
.pn-modal-body .pn-acct-field input[type="url"],
.pn-modal-body .pn-acct-field input[type="number"] {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	font-size: 14px;
	color: #2d3748;
	box-sizing: border-box;
	background: #fff;
	font-family: inherit;
	transition: border-color 0.15s;
}
.pn-modal-body .pn-acct-field input[type="url"]:focus,
.pn-modal-body .pn-acct-field input[type="number"]:focus {
	outline: none;
	border-color: #3182ce;
	box-shadow: 0 0 0 2px rgba(49,130,206,0.12);
}
.pn-modal-body .pn-acct-field input[type="file"] {
	font-size: 13px;
	color: #4a5568;
	padding: 6px 0;
	display: block;
	width: 100%;
}
.un-field-hint {
	font-size: 11px;
	color: #a0aec0;
	margin-top: 3px;
}

/* ── Player search autocomplete (shared across modals) ───── */

.un-player-search { position: relative; }
.un-ac-results {
	position: absolute;
	top: calc(100% + 2px);
	left: 0; right: 0;
	background: #fff;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	box-shadow: 0 4px 16px rgba(0,0,0,0.12);
	z-index: 500;
	max-height: 260px;
	overflow-y: auto;
	display: none;
}
.un-ac-results.un-ac-open { display: block; }
.un-ac-group-label {
	padding: 6px 12px 3px;
	font-size: 10px;
	font-weight: 700;
	color: #a0aec0;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	background: #f7fafc;
	border-bottom: 1px solid #edf2f7;
}
.un-ac-item {
	padding: 8px 12px;
	font-size: 13px;
	color: #2d3748;
	cursor: pointer;
	transition: background 0.1s;
	display: flex;
	align-items: center;
	gap: 8px;
}
.un-ac-item:hover, .un-ac-item.un-ac-focused { background: #ebf8ff; }
.un-ac-scope {
	font-size: 10px;
	color: #718096;
	margin-left: auto;
	white-space: nowrap;
}
.un-ac-empty {
	padding: 10px 12px;
	font-size: 13px;
	color: #a0aec0;
	font-style: italic;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 768px) {
	/* Hero */
	.un-hero { margin-bottom: 10px; }
	.un-hero-content { flex-wrap: wrap; padding: 18px 20px; }
	.un-hero-actions { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; }
	.un-hero-name { font-size: 21px; }
	/* Sidebar above roster on mobile (override revised.css order values) */
	.pn-sidebar { order: 1 !important; }
	.pn-main    { order: 2 !important; }
	/* Hide button text labels */
	.un-btn-label { display: none; }
	/* Hide less-important roster columns */
	#un-roster-table th:nth-child(2),
	#un-roster-table td:nth-child(2),
	#un-roster-table th:nth-child(3),
	#un-roster-table td:nth-child(3),
	#un-roster-table th:nth-child(5),
	#un-roster-table td:nth-child(5) { display: none; }
}
</style>

<?php if ($_err): ?>
<div class="un-error-banner">
	<i class="fas fa-exclamation-circle"></i>
	<?=htmlspecialchars($_err)?>
</div>
<?php endif; ?>

<!-- ── Hero ─────────────────────────────────────────────── -->
<div class="un-hero">
	<div class="un-hero-bg" style="background-image:url('<?=htmlspecialchars($_hero_src)?>')"></div>
	<div class="un-hero-content">

		<!-- Heraldry -->
		<div class="un-heraldry-wrap">
			<div class="un-heraldry-frame">
				<img class="heraldry-img" src="<?=htmlspecialchars($_hero_src)?>"
					onerror="this.onerror=null;this.src='<?=HTTP_UNIT_HERALDRY?>00000.jpg'"
					alt="<?=htmlspecialchars($_name)?>">
			</div>
<?php if ($_can_edit): ?>
			<button class="un-heraldry-edit-btn" onclick="unOpenHeraldryModal()" title="Update heraldry">
				<i class="fas fa-camera"></i>
			</button>
<?php endif; ?>
		</div>

		<!-- Name / type -->
		<div class="un-hero-info">
			<div class="un-type-badge">
				<i class="fas <?=$_type_icon?>"></i>
				<?=htmlspecialchars($_type)?>
			</div>
			<h1 class="un-hero-name"><?=htmlspecialchars($_name)?></h1>
		</div>

		<!-- Actions -->
		<div class="un-hero-actions">
<?php if (trimlen($_url) > 0): ?>
			<a class="pn-btn pn-btn-outline" href="<?=htmlspecialchars($_url)?>" target="_blank" rel="noopener noreferrer">
				<i class="fas fa-external-link-alt"></i><span class="un-btn-label"> Website</span>
			</a>
<?php endif; ?>
<?php if ($_can_edit): ?>
			<button class="pn-btn pn-btn-white" onclick="unOpenModal('un-modal-details')">
				<i class="fas fa-pen"></i><span class="un-btn-label"> Edit Details</span>
			</button>
<?php endif; ?>
		</div>

	</div>
</div>

<!-- ── Stats Row ─────────────────────────────────────────── -->
<div class="pn-stats-row">
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-users"></i></div>
		<div class="pn-stat-number"><?=$_total?></div>
		<div class="pn-stat-label">Members</div>
	</div>
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-user-check"></i></div>
		<div class="pn-stat-number"><?=$_active?></div>
		<div class="pn-stat-label">Active (12 mo)</div>
	</div>
</div>

<!-- ── Sidebar + Main ────────────────────────────────────── -->
<div class="pn-layout">

	<!-- Sidebar -->
	<aside class="pn-sidebar">

<?php if (trimlen($_desc) > 0 || $_can_edit): ?>
		<div class="pn-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-align-left"></i> About</span>
				<?php if ($_can_edit): ?>
				<button class="pn-card-edit-btn" onclick="unOpenModal('un-modal-details')" title="Edit details">
					<i class="fas fa-pen"></i>
				</button>
				<?php endif; ?>
			</h4>
			<div style="font-size:13px;line-height:1.7;color:#4a5568;">
				<?=nl2br($_desc)?>
			</div>
		</div>
<?php endif; ?>

<?php if (trimlen($_history) > 0 || $_can_edit): ?>
		<div class="pn-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-scroll"></i> History</span>
				<?php if ($_can_edit): ?>
				<button class="pn-card-edit-btn" onclick="unOpenModal('un-modal-details')" title="Edit details">
					<i class="fas fa-pen"></i>
				</button>
				<?php endif; ?>
			</h4>
			<div style="font-size:13px;line-height:1.7;color:#4a5568;">
				<?=nl2br(htmlspecialchars(str_replace(['\r\n', '\r', '\n'], "\n", $_history)))?>
			</div>
		</div>
<?php endif; ?>

<?php
$_auths = $Unit['Authorizations']['Authorizations'] ?? [];
if ($_can_edit && (count($_auths) > 0 || true)):
?>
		<div class="pn-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-user-shield"></i> Managers</span>
				<button class="pn-card-edit-btn" onclick="unOpenModal('un-modal-add-manager')" title="Add manager">
					<i class="fas fa-plus"></i>
				</button>
			</h4>
<?php if (count($_auths) > 0): ?>
			<ul class="kn-officer-list">
<?php foreach ($_auths as $_auth): $__aid = (int)$_auth['AuthorizationId']; ?>
				<li>
					<span class="kn-officer-role" style="font-size:10px;">Manager</span>
					<span class="kn-officer-name" style="display:flex;align-items:center;justify-content:space-between;">
						<a href="<?=UIR?>Player/profile/<?=(int)$_auth['MundaneId']?>">
							<?=htmlspecialchars($_auth['Persona'] ?: $_auth['UserName'])?>
						</a>
						<div id="un-mgr-btns-<?=$__aid?>">
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="unToggleConfirm('mgr', <?=$__aid?>)"
								title="Remove manager" style="color:#e53e3e;">
								<i class="fas fa-times"></i>
							</button>
						</div>
						<div class="pn-delete-confirm" id="un-mgr-<?=$__aid?>">
							<span style="color:#e53e3e;font-weight:600;">Remove manager?</span>
							<form method="post" action="<?=htmlspecialchars($_base_url)?>" style="display:inline">
								<input type="hidden" name="Action" value="deleteauth">
								<input type="hidden" name="AuthorizationId" value="<?=$__aid?>">
								<button type="submit" class="pn-delete-yes">Yes</button>
							</form>
							<button class="pn-delete-no"
								onclick="document.getElementById('un-mgr-<?=$__aid?>').classList.remove('pn-active');document.getElementById('un-mgr-btns-<?=$__aid?>').style.display=''">
								No
							</button>
						</div>
					</span>
				</li>
<?php endforeach; ?>
			</ul>
<?php else: ?>
			<p style="font-size:12px;color:#a0aec0;font-style:italic;margin:0;">No managers assigned.</p>
<?php endif; ?>
		</div>
<?php endif; ?>

	</aside>

	<!-- Main: roster -->
	<div class="pn-main">

		<div class="un-section-header">
			<div class="un-section-title">
				<i class="fas fa-users"></i> Members
			</div>
<?php if ($_can_edit): ?>
			<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="unOpenModal('un-modal-add-member')">
				<i class="fas fa-plus"></i><span class="un-btn-label"> Add Member</span>
			</button>
<?php endif; ?>
		</div>

		<div class="un-roster-card">
<?php if ($_total === 0): ?>
			<div class="pn-empty">
				<i class="fas fa-users" style="font-size:24px;display:block;margin-bottom:8px;opacity:0.25;"></i>
				No members found.
			</div>
<?php else: ?>
			<table id="un-roster-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Persona</th>
						<th>Park</th>
						<th>Kingdom</th>
						<th>Role</th>
						<th>Title</th>
						<th>Last Sign-in</th>
<?php if ($_can_edit): ?>
						<th></th>
<?php endif; ?>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_members as $_m):
	$_persona     = trimlen($_m['Persona']) > 0 ? $_m['Persona'] : '(No Persona)';
	$_um_id       = (int)($_m['UnitMundaneId'] ?? 0);
	$_role_esc    = htmlspecialchars($_m['UnitRole']  ?? '', ENT_QUOTES);
	$_title_esc   = htmlspecialchars($_m['UnitTitle'] ?? '', ENT_QUOTES);
	$_last_signin = $_m['LastSignIn'] ?? '';
	$_is_active   = !empty($_last_signin) && $_last_signin >= $_cutoff;
?>
				<tr>
					<td>
						<a href="<?=UIR?>Player/profile/<?=(int)$_m['MundaneId']?>"
							style="color:#2b6cb0;text-decoration:none;font-weight:500;">
							<?=htmlspecialchars($_persona)?>
						</a>
						<?php if (!$_is_active && !empty($_last_signin)): ?>
						<span style="font-size:10px;color:#a0aec0;margin-left:4px;">(inactive)</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if (!empty($_m['ParkId'])): ?>
						<a href="<?=UIR?>Park/profile/<?=(int)$_m['ParkId']?>"
							style="color:#4a5568;text-decoration:none;">
							<?=htmlspecialchars($_m['ParkName'] ?? '')?>
						</a>
						<?php else: ?>
						<?=htmlspecialchars($_m['ParkName'] ?? '')?>
						<?php endif; ?>
					</td>
					<td>
						<?php if (!empty($_m['KingdomId'])): ?>
						<a href="<?=UIR?>Kingdom/profile/<?=(int)$_m['KingdomId']?>"
							style="color:#4a5568;text-decoration:none;">
							<?=htmlspecialchars($_m['KingdomName'] ?? '')?>
						</a>
						<?php else: ?>
						<?=htmlspecialchars($_m['KingdomName'] ?? '')?>
						<?php endif; ?>
					</td>
					<td><?=htmlspecialchars(ucfirst($_m['UnitRole'] ?? ''))?></td>
					<td><?=htmlspecialchars($_m['UnitTitle'] ?? '')?></td>
					<td data-order="<?=htmlspecialchars($_last_signin)?>">
						<?=htmlspecialchars($_last_signin ?: '—')?>
					</td>
<?php if ($_can_edit): ?>
					<td style="white-space:nowrap;">
						<div class="un-action-btns" id="un-btns-<?=$_um_id?>">
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="unOpenEditMember(<?=$_um_id?>, '<?=$_role_esc?>', '<?=$_title_esc?>')"
								title="Edit role / title">
								<i class="fas fa-pen"></i>
							</button>
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="unToggleConfirm('retire', <?=$_um_id?>)"
								title="Retire member" style="color:#c05621;">
								<i class="fas fa-user-minus"></i>
							</button>
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="unToggleConfirm('remove', <?=$_um_id?>)"
								title="Remove member" style="color:#e53e3e;">
								<i class="fas fa-times"></i>
							</button>
						</div>
						<!-- Retire confirm -->
						<div class="pn-delete-confirm" id="un-retire-<?=$_um_id?>">
							<span>Retire?</span>
							<form method="post" action="<?=htmlspecialchars($_base_url)?>" style="display:inline">
								<input type="hidden" name="Action" value="retire_member">
								<input type="hidden" name="UnitMundaneId" value="<?=$_um_id?>">
								<button type="submit" class="pn-delete-yes" onclick="gtag('event','unit_member_retire')">Yes</button>
							</form>
							<button class="pn-delete-no"
								onclick="document.getElementById('un-retire-<?=$_um_id?>').classList.remove('pn-active');document.getElementById('un-btns-<?=$_um_id?>').style.display=''">
								No
							</button>
						</div>
						<!-- Remove confirm -->
						<div class="pn-delete-confirm" id="un-remove-<?=$_um_id?>">
							<span style="color:#e53e3e;font-weight:600;">Remove permanently?</span>
							<form method="post" action="<?=htmlspecialchars($_base_url)?>" style="display:inline">
								<input type="hidden" name="Action" value="remove_member">
								<input type="hidden" name="UnitMundaneId" value="<?=$_um_id?>">
								<button type="submit" class="pn-delete-yes" onclick="gtag('event','unit_member_remove')">Yes</button>
							</form>
							<button class="pn-delete-no"
								onclick="document.getElementById('un-remove-<?=$_um_id?>').classList.remove('pn-active');document.getElementById('un-btns-<?=$_um_id?>').style.display=''">
								No
							</button>
						</div>
					</td>
<?php endif; ?>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
<?php endif; ?>
		</div><!-- /un-roster-card -->

	</div><!-- /pn-main -->

</div><!-- /pn-layout -->


<?php if ($_can_edit): ?>

<!-- ── Heraldry Modal ─────────────────────────────────── -->
<div class="pn-overlay" id="un-img-overlay" onclick="if(event.target===this)unCloseHeraldryModal()">
	<div class="pn-modal-box" style="max-width:420px">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Heraldry</h3>
			<button class="pn-modal-close-btn" onclick="unCloseHeraldryModal()" aria-label="Close">&times;</button>
		</div>
		<!-- Step: select -->
		<div class="pn-modal-body" id="un-img-step-select">
			<label class="pn-upload-area" for="un-img-file-input" style="cursor:pointer">
				<i class="fas fa-cloud-upload-alt pn-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Accepts transparent images</small>
			</label>
			<input type="file" id="un-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none">
<?php if (!empty($_unit['HasHeraldry'])): ?>
			<div style="text-align:center;margin-top:14px">
				<button type="button" id="un-img-remove-btn" class="pn-btn pn-btn-ghost" style="color:#e53e3e;border-color:#feb2b2;font-size:12px;padding:4px 14px">
					<i class="fas fa-trash"></i> Remove Heraldry
				</button>
				<div id="un-img-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:#fff5f5;border:1px solid #fed7d7;border-radius:6px;font-size:13px;color:#c53030;text-align:left">
					Remove this unit's heraldry image?
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('un-img-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm" style="background:#e53e3e;color:#fff" onclick="unDoRemoveHeraldry()">Yes, Remove</button>
					</div>
				</div>
			</div>
<?php endif; ?>
		</div>
		<!-- Step: uploading -->
		<div class="pn-modal-body" id="un-img-step-uploading" style="display:none;text-align:center;padding:40px 20px">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1"></i>
			<p style="margin-top:12px;color:#718096">Uploading&hellip;</p>
		</div>
		<!-- Step: done -->
		<div class="pn-modal-body" id="un-img-step-done" style="display:none;text-align:center;padding:40px 20px">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>

<!-- ── Edit Details Modal ─────────────────────────────── -->
<div class="pn-overlay" id="un-modal-details">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-pen"></i> Edit Unit Details</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-details')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>" enctype="multipart/form-data">
			<input type="hidden" name="Action" value="save_details">
			<div class="pn-acct-modal-body">
				<div class="pn-acct-field">
					<label>Name</label>
					<input type="text" name="Name" value="<?=htmlspecialchars($_name)?>" required>
				</div>
				<div class="pn-acct-field" style="display:flex;align-items:center;gap:12px;">
					<div style="flex:1;">
						<label>Type</label>
						<div style="font-size:14px;color:#2d3748;padding:8px 0 2px;">
							<i class="fas <?=$_type_icon?>"></i> <?=htmlspecialchars($_type)?>
						</div>
					</div>
					<div style="flex-shrink:0;padding-top:22px;">
						<button type="button" class="pn-btn pn-btn-secondary pn-btn-sm" id="un-convert-btn"
							onclick="unConvertType('<?=($_type === 'Company' ? 'Household' : 'Company')?>')">
							<i class="fas <?=($_type === 'Company' ? 'fa-home' : 'fa-shield-alt')?>"></i>
							Convert to <?=($_type === 'Company' ? 'Household' : 'Company')?>
						</button>
					</div>
				</div>
				<div class="pn-acct-field">
					<label>Website URL</label>
					<input type="url" name="Url" value="<?=htmlspecialchars($_url)?>" placeholder="https://…">
				</div>
				<div class="pn-acct-field">
					<label>Description</label>
					<textarea name="Description" rows="4"><?=htmlspecialchars($_desc)?></textarea>
				</div>
				<div class="pn-acct-field">
					<label>History</label>
					<textarea name="History" rows="4"><?=htmlspecialchars($_history)?></textarea>
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-details')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
					<i class="fas fa-save"></i> Save
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Add Member Modal ───────────────────────────────── -->
<div class="pn-overlay" id="un-modal-add-member">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-plus"></i> Add Member</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-add-member')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>">
			<input type="hidden" name="Action" value="add_member">
			<div class="pn-modal-body">
				<div class="pn-acct-field">
					<label>Player</label>
					<div class="pn-award-search-bar un-player-search" id="un-am-wrap">
						<input type="text" class="pn-award-search-input" id="un-am-input"
							placeholder="Search players…"
							autocomplete="off">
						<div class="un-ac-results" id="un-am-results"></div>
					</div>
					<input type="hidden" name="MundaneId" id="un-am-mundane-id">
				</div>
				<div class="pn-acct-field">
					<label>Role</label>
					<select name="Role" id="un-add-role">
						<option value="member">Member</option>
						<option value="captain">Captain</option>
						<option value="lord">Lord</option>
						<option value="organizer">Organizer</option>
					</select>
				</div>
				<div class="pn-acct-field">
					<label>Title <span style="font-weight:400;color:#a0aec0;">(optional)</span></label>
					<input type="text" name="Title" placeholder="Honorific or rank">
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-add-member')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary" onclick="gtag('event','unit_member_add')">
					<i class="fas fa-plus"></i> Add
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Edit Member Modal ──────────────────────────────── -->
<div class="pn-overlay" id="un-modal-edit-member">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-edit"></i> Edit Member</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-edit-member')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>">
			<input type="hidden" name="Action" value="set_member">
			<input type="hidden" name="UnitMundaneId" id="un-edit-umid">
			<div class="pn-modal-body">
				<div class="pn-acct-field">
					<label>Role</label>
					<select name="Role" id="un-edit-role">
						<option value="member">Member</option>
						<option value="captain">Captain</option>
						<option value="lord">Lord</option>
						<option value="organizer">Organizer</option>
					</select>
				</div>
				<div class="pn-acct-field">
					<label>Title</label>
					<input type="text" name="Title" id="un-edit-title" placeholder="Honorific or rank">
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-edit-member')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
					<i class="fas fa-save"></i> Save
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Add Manager Modal ──────────────────────────────── -->
<div class="pn-overlay" id="un-modal-add-manager">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-shield"></i> Add Manager</h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-add-manager')">&times;</button>
		</div>
		<form method="post" action="<?=htmlspecialchars($_base_url)?>">
			<input type="hidden" name="Action" value="addauth">
			<div class="pn-modal-body">
				<div class="pn-acct-field">
					<label>Player</label>
					<div class="pn-award-search-bar un-player-search" id="un-mg-wrap">
						<input type="text" class="pn-award-search-input" id="un-mg-input"
							placeholder="Search players…"
							autocomplete="off">
						<div class="un-ac-results" id="un-mg-results"></div>
					</div>
					<input type="hidden" name="MundaneId" id="un-mg-mundane-id">
					<div class="un-field-hint">Managers can edit unit details and manage members.</div>
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-add-manager')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
					<i class="fas fa-plus"></i> Add Manager
				</button>
			</div>
		</form>
	</div>
</div>

<?php endif; ?>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(function () {
	if ($('#un-roster-table').length) {
		$('#un-roster-table').DataTable({
			dom         : 'lfrtip',
			orderClasses: false,
			buttons     : [
				{ extend: 'csv',   filename: '<?=addslashes($_name)?>-roster', exportOptions: { columns: ':not(:last-child)' } },
				{ extend: 'print', exportOptions: { columns: ':not(:last-child)' } }
			],
			pageLength: 25,
			order     : [[3, 'asc'], [0, 'asc']],
			columnDefs: [
				{ targets: 5, type: 'date' },
<?php if ($_can_edit): ?>
				{ targets: -1, orderable: false, searchable: false, width: '90px' }
<?php endif; ?>
			]
		});
	}
});

<?php if ($_can_edit): ?>
// ── Heraldry modal ────────────────────────────────────────
function unOpenHeraldryModal() {
	document.getElementById('un-img-step-select').style.display    = '';
	document.getElementById('un-img-step-uploading').style.display = 'none';
	document.getElementById('un-img-step-done').style.display      = 'none';
	document.getElementById('un-img-file-input').value             = '';
	var rc = document.getElementById('un-img-remove-confirm');
	if (rc) rc.style.display = 'none';
	document.getElementById('un-img-overlay').classList.add('pn-open');
	document.body.style.overflow = 'hidden';
}
function unCloseHeraldryModal() {
	document.getElementById('un-img-overlay').classList.remove('pn-open');
	document.body.style.overflow = '';
}
document.getElementById('un-img-file-input').addEventListener('change', function() {
	if (!this.files[0]) return;
	var fd = new FormData();
	fd.append('Action', 'upload_heraldry');
	fd.append('Heraldry', this.files[0]);
	document.getElementById('un-img-step-select').style.display    = 'none';
	document.getElementById('un-img-step-uploading').style.display = '';
	fetch('<?=htmlspecialchars($_base_url)?>', { method: 'POST', body: fd })
		.then(function(r) {
			document.getElementById('un-img-step-uploading').style.display = 'none';
			if (r.ok) {
				gtag('event', 'unit_heraldry_upload', { status: 'success' });
				document.getElementById('un-img-step-done').style.display = '';
				setTimeout(function() { window.location.reload(); }, 1200);
			} else {
				gtag('event', 'unit_heraldry_upload', { status: 'failed' });
				document.getElementById('un-img-step-select').style.display = '';
				alert('Upload failed. Please try again.');
			}
		});
});
var _unRemoveBtn = document.getElementById('un-img-remove-btn');
if (_unRemoveBtn) {
	_unRemoveBtn.addEventListener('click', function() {
		var rc = document.getElementById('un-img-remove-confirm');
		rc.style.display = rc.style.display === 'none' ? '' : 'none';
	});
}
function unDoRemoveHeraldry() {
	var fd = new FormData();
	fd.append('Action', 'remove_heraldry');
	document.getElementById('un-img-step-select').style.display    = 'none';
	document.getElementById('un-img-step-uploading').style.display = '';
	fetch('<?=htmlspecialchars($_base_url)?>', { method: 'POST', body: fd })
		.then(function(r) { if (r.ok) window.location.reload(); });
}

function unOpenModal(id) {
	document.getElementById(id).classList.add('pn-open');
}
function unCloseModal(id) {
	document.getElementById(id).classList.remove('pn-open');
}
function unConvertType(targetType) {
	var btn = document.getElementById('un-convert-btn');
	btn.disabled = true;
	btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting\u2026';
	var fd = new FormData();
	fd.append('Action', 'convert_type');
	fd.append('TargetType', targetType);
	fetch('<?=htmlspecialchars($_base_url)?>', { method: 'POST', body: fd })
		.then(function(r) {
			if (r.ok) {
				window.location.reload();
			} else {
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed';
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Failed';
		});
}
function unOpenEditMember(unitMundaneId, role, title) {
	document.getElementById('un-edit-umid').value  = unitMundaneId;
	/* select the matching option; fall back to 'member' if unrecognised */
	var sel = document.getElementById('un-edit-role');
	var found = false;
	for (var i = 0; i < sel.options.length; i++) {
		if (sel.options[i].value === role) { sel.selectedIndex = i; found = true; break; }
	}
	if (!found) sel.value = 'member';
	document.getElementById('un-edit-title').value = title;
	unOpenModal('un-modal-edit-member');
}
function unToggleConfirm(type, id) {
	/* hide all other open confirms */
	document.querySelectorAll('.pn-delete-confirm.pn-active').forEach(function (el) {
		el.classList.remove('pn-active');
		var suffix = el.id.replace('un-retire-','').replace('un-remove-','').replace('un-mgr-','');
		var btns = document.getElementById('un-mgr-btns-' + suffix) || document.getElementById('un-btns-' + suffix);
		if (btns) btns.style.display = '';
	});
	var el   = document.getElementById('un-' + type + '-' + id);
	var btns = type === 'mgr'
		? document.getElementById('un-mgr-btns-' + id)
		: document.getElementById('un-btns-' + id);
	if (el) {
		el.classList.add('pn-active');
		if (btns) btns.style.display = 'none';
	}
}
/* Close modals on backdrop click */
document.querySelectorAll('.pn-overlay').forEach(function (overlay) {
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) overlay.classList.remove('pn-open');
	});
});

/* ── Player search factory ──────────────────────────────── */
var UN_SEARCH_URL = '<?=HTTP_SERVICE?>Search/SearchService.php';
var UN_SCOPE_KID  = <?=(int)($ScopeKingdomId ?? 0)?>;
var UN_SCOPE_PID  = <?=(int)($ScopeParkId ?? 0)?>;

function initPlayerSearch(cfg) {
	/* cfg: { inputId, resultsId, hiddenId, parkId, kingdomId } */
	var $input   = document.getElementById(cfg.inputId);
	var $results = document.getElementById(cfg.resultsId);
	var $hidden  = document.getElementById(cfg.hiddenId);
	var debounce, focusIdx = -1;
	var seen = {};

	function closeResults() {
		$results.classList.remove('un-ac-open');
		$results.innerHTML = '';
		focusIdx = -1;
	}

	function selectPlayer(id, label) {
		$hidden.value  = id;
		$input.value   = label;
		closeResults();
	}

	function buildItem(player, groupClass) {
		var id    = player.MundaneId;
		var label = player.Persona;
		var scope = (player.KAbbr && player.PAbbr) ? player.KAbbr + ':' + player.PAbbr : (player.KAbbr || '');
		var el    = document.createElement('div');
		el.className   = 'un-ac-item' + (groupClass ? ' ' + groupClass : '');
		el.dataset.id  = id;
		el.dataset.lbl = label;
		el.innerHTML   = '<span>' + label + '</span>'
			+ (scope ? '<span class="un-ac-scope">' + scope + '</span>' : '');
		el.addEventListener('mousedown', function (e) {
			e.preventDefault();
			selectPlayer(id, label + (scope ? ' (' + scope + ')' : ''));
		});
		return el;
	}

	function addGroup(label, players) {
		if (!players.length) return;
		var hdr = document.createElement('div');
		hdr.className   = 'un-ac-group-label';
		hdr.textContent = label;
		$results.appendChild(hdr);
		players.forEach(function (p) {
			if (seen[p.MundaneId]) return;
			seen[p.MundaneId] = true;
			$results.appendChild(buildItem(p, ''));
		});
	}

	function runSearch(term) {
		seen = {};
		$results.innerHTML = '';
		$hidden.value = '';
		var base = { Action: 'Search/Player', type: 'all', search: term, limit: 8 };
		var calls = [];
		/* Three-tier: park → kingdom → global */
		if (cfg.parkId)    calls.push($.getJSON(UN_SEARCH_URL, $.extend({}, base, { park_id:    cfg.parkId })));
		if (cfg.kingdomId) calls.push($.getJSON(UN_SEARCH_URL, $.extend({}, base, { kingdom_id: cfg.kingdomId })));
		calls.push($.getJSON(UN_SEARCH_URL, base));

		$.when.apply($, calls).done(function () {
			var args = calls.length === 1 ? [arguments] : Array.prototype.slice.call(arguments);
			var parkRes    = (cfg.parkId    && args[0]) ? (args[0][0] || []) : [];
			var kingRes    = (cfg.kingdomId && args[cfg.parkId ? 1 : 0]) ? (args[cfg.parkId ? 1 : 0][0] || []) : [];
			var allRes     = (args[args.length - 1]  ? args[args.length - 1][0] : null) || [];

			var hasPark    = cfg.parkId    && parkRes.length;
			var hasKing    = cfg.kingdomId && kingRes.length;

			if (!hasPark && !hasKing && !allRes.length) {
				var empty = document.createElement('div');
				empty.className   = 'un-ac-empty';
				empty.textContent = 'No players found.';
				$results.appendChild(empty);
			} else {
				if (hasPark)  addGroup('In Park',    parkRes);
				if (hasKing)  addGroup('In Kingdom', kingRes);
				/* global results not already shown */
				var rest = allRes.filter(function (p) { return !seen[p.MundaneId]; });
				if (rest.length) addGroup('All Players', rest);
			}
			focusIdx = -1;
			$results.classList.add('un-ac-open');
		});
	}

	$input.addEventListener('input', function () {
		var term = this.value.trim();
		$hidden.value = '';
		clearTimeout(debounce);
		if (term.length < 2) { closeResults(); return; }
		debounce = setTimeout(function () { runSearch(term); }, 300);
	});

	$input.addEventListener('keydown', function (e) {
		var items = $results.querySelectorAll('.un-ac-item');
		if (!items.length) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			if (focusIdx > 0) items[focusIdx].classList.remove('un-ac-focused');
			focusIdx = Math.min(focusIdx + 1, items.length - 1);
			items[focusIdx].classList.add('un-ac-focused');
			items[focusIdx].scrollIntoView({ block: 'nearest' });
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			if (focusIdx >= 0) items[focusIdx].classList.remove('un-ac-focused');
			focusIdx = Math.max(focusIdx - 1, 0);
			items[focusIdx].classList.add('un-ac-focused');
			items[focusIdx].scrollIntoView({ block: 'nearest' });
		} else if (e.key === 'Enter') {
			e.preventDefault();
			if (focusIdx >= 0 && items[focusIdx]) items[focusIdx].dispatchEvent(new MouseEvent('mousedown'));
		} else if (e.key === 'Escape') {
			closeResults();
		}
	});

	$input.addEventListener('blur', function () {
		setTimeout(closeResults, 150);
	});
}

/* Initialise both search widgets */
initPlayerSearch({ inputId: 'un-am-input', resultsId: 'un-am-results', hiddenId: 'un-am-mundane-id', parkId: UN_SCOPE_PID, kingdomId: UN_SCOPE_KID });
initPlayerSearch({ inputId: 'un-mg-input', resultsId: 'un-mg-results', hiddenId: 'un-mg-mundane-id', parkId: UN_SCOPE_PID, kingdomId: UN_SCOPE_KID });

/* Clear search fields when modals close */
document.getElementById('un-modal-add-member').addEventListener('transitionend', function () {
	if (!this.classList.contains('pn-open')) {
		document.getElementById('un-am-input').value = '';
		document.getElementById('un-am-mundane-id').value = '';
	}
});
document.getElementById('un-modal-add-manager').addEventListener('transitionend', function () {
	if (!this.classList.contains('pn-open')) {
		document.getElementById('un-mg-input').value = '';
		document.getElementById('un-mg-mundane-id').value = '';
	}
});
<?php endif; ?>
</script>
