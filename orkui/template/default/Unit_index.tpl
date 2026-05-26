<?php
require_once(DIR_LIB . 'Parsedown.php');
function un_markdown(string $text): string {
	$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($text);
	return preg_replace('/<img[^>]*>/i', '', $html);
}

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

$_can_edit   = !empty($CanEdit);
$_err        = $SaveError ?? '';
$_base_url   = UIR . "Unit/index/$_unit_id";
// Pre-built <option> list for the "filter players by" kingdom dropdown
// (shared by the add-member and add-manager search modals).
$_kingdom_options = '<option value="">All Kingdoms</option>';
foreach (($FilterKingdoms ?? array()) as $_fk) {
	$_kingdom_options .= '<option value="' . (int)$_fk['KingdomId'] . '">' . htmlspecialchars($_fk['KingdomName']) . '</option>';
}

$_type_icon  = $_type === 'Company' ? 'fa-shield-alt' : ($_type === 'Household' ? 'fa-home' : 'fa-users');
$_hero_color = $_type === 'Company' ? '#1a3654' : ($_type === 'Household' ? '#2d1b54' : '#1a365d');

/* ── Retire / Claim / Transfer state (computed in controller) ── */
$_type_l        = strtolower($_type);
$_logged_in     = !empty($LoggedIn);
$_can_officer   = !empty($CanOfficerManage);
$_mgr_count     = (int)($ManagerCount ?? 0);
$_is_manager    = !empty($IsManager);
$_is_sole       = !empty($IsSoleMember);
$_can_claim     = !empty($CanClaim);
$_is_retired    = empty($UnitActive);
$_show_addmgr   = !empty($ShowAddManager);
$_transfer_targets = $TransferTargets ?? [];
$_can_transfer  = count($_transfer_targets) > 0;
$_nonmgr_members   = $NonManagerMembers ?? [];
// Retire card: managers/officers always, plus the sole-member exception.
$_show_retire   = !$_is_retired && ($_can_edit || $_can_officer || $_is_sole);
// Claim/unmanaged card: any logged-in viewer when the unit has no managers.
$_show_claim    = !$_is_retired && $_logged_in && $_mgr_count === 0;
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
/* Override dark-mode h1 rule which has higher specificity (html[data-theme] h1 > .class) */
html[data-theme="dark"] .un-hero-name,
html:not([data-theme="light"]):not([data-theme="dark"]) .un-hero-name {
	background: transparent;
	border: none;
	color: #fff;
	text-shadow: 0 1px 3px rgba(0,0,0,0.35);
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
	color: var(--ork-text-secondary);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	display: flex;
	align-items: center;
	gap: 6px;
}

/* ── Roster card wrapper ─────────────────────────────────── */
.un-roster-card {
	background: var(--ork-card-bg);
	border: 1px solid var(--ork-border);
	border-radius: 8px;
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

/* ── Inline action area ──────────────────────────────────── */
.un-action-btns { display: flex; gap: 4px; white-space: nowrap; }

/* ── Error banner ────────────────────────────────────────── */
.un-error-banner {
	background: var(--ork-alert-danger-bg);
	border: 1px solid var(--ork-alert-danger-border);
	border-radius: 6px;
	color: var(--ork-alert-danger-text);
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
	border: 1px solid var(--ork-input-border);
	border-radius: 6px;
	font-size: 14px;
	color: var(--ork-text);
	box-sizing: border-box;
	background: var(--ork-input-bg);
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
	color: var(--ork-text-secondary);
	padding: 6px 0;
	display: block;
	width: 100%;
}
.un-field-hint {
	font-size: 11px;
	color: var(--ork-text-lighter);
	margin-top: 3px;
}
/* "Filter players by" cascade (kingdom → park) in the add-member/manager modals */
.un-filter-field select { width: 100%; }
.un-filter-park { margin-top: 6px; }

/* ── Player search autocomplete (shared across modals) ───── */

.un-player-search { position: relative; }
.un-ac-results {
	position: absolute;
	top: calc(100% + 2px);
	left: 0; right: 0;
	background: var(--ork-card-bg);
	border: 1px solid var(--ork-border-dark);
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
	color: var(--ork-text-lighter);
	text-transform: uppercase;
	letter-spacing: 0.06em;
	background: var(--ork-bg-secondary);
	border-bottom: 1px solid var(--ork-border);
}
.un-ac-item {
	padding: 8px 12px;
	font-size: 13px;
	color: var(--ork-text);
	cursor: pointer;
	transition: background 0.1s;
	display: flex;
	align-items: center;
	gap: 8px;
}
.un-ac-item:hover, .un-ac-item.un-ac-focused { background: var(--ork-bg-tertiary); }
.un-ac-scope {
	font-size: 10px;
	color: var(--ork-text-muted);
	margin-left: auto;
	white-space: nowrap;
}
.un-ac-empty {
	padding: 10px 12px;
	font-size: 13px;
	color: var(--ork-text-muted);
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

<?php if ($_is_retired): ?>
		<div class="pn-card un-retired-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;"><span><i class="fas fa-box-archive"></i> Retired</span></h4>
			<p class="un-card-text">This <?=htmlspecialchars($_type_l)?> has been retired and is hidden from listings and search.</p>
<?php if ($_can_officer): ?>
			<form method="post" action="<?=htmlspecialchars($_base_url)?>">
				<input type="hidden" name="Action" value="restore_unit">
				<button type="submit" class="pn-btn pn-btn-primary" style="width:100%;">
					<i class="fas fa-rotate-left"></i> Reactivate This <?=htmlspecialchars($_type)?>
				</button>
			</form>
<?php else: ?>
			<p class="un-card-text" style="font-style:italic;">A member of monarchy for your park or kingdom can reactivate it.</p>
<?php endif; ?>
		</div>
<?php endif; ?>

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
			<div class="kn-description-body" style="font-size:13px;color:var(--ork-text-secondary);">
				<?=un_markdown($_desc)?>
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
			<div class="kn-description-body" style="font-size:13px;color:var(--ork-text-secondary);">
				<?=un_markdown($_history)?>
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
<?php foreach ($_auths as $_auth):
	$__aid      = (int)$_auth['AuthorizationId'];
	$__mgr_js   = addslashes($_auth['Persona'] ?: $_auth['UserName']);
?>
				<li>
					<span class="kn-officer-role" style="font-size:10px;">Manager</span>
					<span class="kn-officer-name" style="display:flex;align-items:center;justify-content:space-between;">
						<a href="<?=UIR?>Player/profile/<?=(int)$_auth['MundaneId']?>">
							<?=htmlspecialchars($_auth['Persona'] ?: $_auth['UserName'])?>
						</a>
						<form method="post" action="<?=htmlspecialchars($_base_url)?>" id="un-mgr-form-<?=$__aid?>" style="display:none">
							<input type="hidden" name="Action" value="deleteauth">
							<input type="hidden" name="AuthorizationId" value="<?=$__aid?>">
						</form>
						<button class="pn-btn pn-btn-ghost pn-btn-sm"
							onclick="pnConfirm({title:'Remove Manager',message:'Remove <?=$__mgr_js?> as a manager?',confirmText:'Remove',danger:true},function(){document.getElementById('un-mgr-form-<?=$__aid?>').submit()})"
							title="Remove manager" style="color:#e53e3e;">
							<i class="fas fa-times"></i>
						</button>
					</span>
				</li>
<?php endforeach; ?>
			</ul>
<?php else: ?>
			<p style="font-size:12px;color:var(--ork-text-muted);font-style:italic;margin:0;">No managers assigned.</p>
<?php endif; ?>
		</div>
<?php endif; ?>


<!-- ── Claim / Add-Manager Card (unmanaged units) ───────── -->
<?php if ($_show_claim): ?>
		<div class="pn-card un-claim-card">
<?php if ($_can_claim): ?>
			<h4 style="display:flex;align-items:center;justify-content:space-between;"><span><i class="fas fa-hand-sparkles"></i> Claim This <?=htmlspecialchars($_type)?></span></h4>
			<p class="un-card-text">This <?=htmlspecialchars($_type_l)?> has no manager. As a leader of <?=htmlspecialchars($_name)?>, you can take over managing it.</p>
			<form method="post" action="<?=htmlspecialchars($_base_url)?>">
				<input type="hidden" name="Action" value="claim_unit">
				<button type="submit" class="pn-btn pn-btn-primary" style="width:100%;">
					<i class="fas fa-user-shield"></i> Claim <?=htmlspecialchars($_name)?>
				</button>
			</form>
<?php else: ?>
			<h4 style="display:flex;align-items:center;justify-content:space-between;"><span><i class="fas fa-user-slash"></i> Unmanaged <?=htmlspecialchars($_type)?></span></h4>
<?php if (!$_can_officer): ?>
			<p class="un-card-text">It looks like no players currently have permission to manage this <?=htmlspecialchars($_type_l)?>. If you feel you should be given access to do so, please contact a member of monarchy for your park or kingdom and ask them to transfer the unit to you.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($_can_officer): ?>
			<div class="un-card-section">
				<div class="un-card-subhead"><i class="fas fa-user-plus"></i> Add A Manager</div>
				<p class="un-card-text">This unit has no managing players. You can add one by clicking the button below. Please be certain the player requesting to claim this unit has a legitimate reason for doing so.</p>
				<button type="button" class="pn-btn pn-btn-secondary" style="width:100%;" onclick="unOpenModal('un-modal-add-manager')">
					<i class="fas fa-user-plus"></i> Add A Manager
				</button>
			</div>
<?php endif; ?>
		</div>
<?php endif; ?>

<!-- ── Retire Card ──────────────────────────────────────── -->
<?php if ($_show_retire): ?>
		<div class="pn-card un-retire-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;"><span><i class="fas fa-box-archive"></i> Retire <?=htmlspecialchars($_type)?></span></h4>
			<p class="un-card-text">If <?=htmlspecialchars($_name)?> is no longer active or has disbanded, you can retire it here.</p>
			<button type="button" class="pn-btn pn-btn-ghost" style="width:100%;color:#c05621;border-color:#c05621;" onclick="unOpenModal('un-modal-retire')">
				<i class="fas fa-box-archive"></i> Retire This Unit
			</button>
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
	$_persona_js  = addslashes($_persona);
	$_last_signin = $_m['LastSignIn'] ?? '';
	$_is_active   = !empty($_last_signin) && $_last_signin >= $_cutoff;
?>
				<tr>
					<td>
						<a href="<?=UIR?>Player/profile/<?=(int)$_m['MundaneId']?>"
							style="color:var(--ork-link);text-decoration:none;font-weight:500;">
							<?=htmlspecialchars($_persona)?>
						</a>
						<?php if (!$_is_active && !empty($_last_signin)): ?>
						<span style="font-size:10px;color:var(--ork-text-lighter);margin-left:4px;">(inactive)</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if (!empty($_m['ParkId'])): ?>
						<a href="<?=UIR?>Park/profile/<?=(int)$_m['ParkId']?>"
							style="color:var(--ork-text-secondary);text-decoration:none;">
							<?=htmlspecialchars($_m['ParkName'] ?? '')?>
						</a>
						<?php else: ?>
						<?=htmlspecialchars($_m['ParkName'] ?? '')?>
						<?php endif; ?>
					</td>
					<td>
						<?php if (!empty($_m['KingdomId'])): ?>
						<a href="<?=UIR?>Kingdom/profile/<?=(int)$_m['KingdomId']?>"
							style="color:var(--ork-text-secondary);text-decoration:none;">
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
						<form method="post" action="<?=htmlspecialchars($_base_url)?>" id="un-retire-form-<?=$_um_id?>" style="display:none">
							<input type="hidden" name="Action" value="retire_member">
							<input type="hidden" name="UnitMundaneId" value="<?=$_um_id?>">
						</form>
						<form method="post" action="<?=htmlspecialchars($_base_url)?>" id="un-remove-form-<?=$_um_id?>" style="display:none">
							<input type="hidden" name="Action" value="remove_member">
							<input type="hidden" name="UnitMundaneId" value="<?=$_um_id?>">
						</form>
						<div class="un-action-btns">
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="unOpenEditMember(<?=$_um_id?>, '<?=$_role_esc?>', '<?=$_title_esc?>')"
								title="Edit role / title">
								<i class="fas fa-pen"></i>
							</button>
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="pnConfirm({title:'Retire Member',message:'Retire <?=$_persona_js?> from the unit?',confirmText:'Retire',danger:true},function(){document.getElementById('un-retire-form-<?=$_um_id?>').submit()})"
								title="Retire member" style="color:#c05621;">
								<i class="fas fa-user-minus"></i>
							</button>
							<button class="pn-btn pn-btn-ghost pn-btn-sm"
								onclick="pnConfirm({title:'Remove Member',message:'Permanently remove <?=$_persona_js?> from the unit?',confirmText:'Remove',danger:true},function(){document.getElementById('un-remove-form-<?=$_um_id?>').submit()})"
								title="Remove member" style="color:#e53e3e;">
								<i class="fas fa-times"></i>
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
				<button type="button" id="un-img-remove-btn" class="pn-btn pn-btn-ghost" style="color:#e53e3e;border-color:var(--ork-alert-danger-border);font-size:12px;padding:4px 14px">
					<i class="fas fa-trash"></i> Remove Heraldry
				</button>
				<div id="un-img-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:var(--ork-alert-danger-bg);border:1px solid var(--ork-alert-danger-border);border-radius:6px;font-size:13px;color:var(--ork-alert-danger-text);text-align:left">
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
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--ork-link-bright)"></i>
			<p style="margin-top:12px;color:var(--ork-text-muted)">Uploading&hellip;</p>
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
			<button class="pn-modal-close-btn" onclick="unCloseDetailsModal()">&times;</button>
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
						<div style="font-size:14px;color:var(--ork-text);padding:8px 0 2px;">
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
					<label style="display:flex;align-items:center;gap:6px;">
						Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
						<button type="button" class="kn-md-help-btn" onclick="document.getElementById('un-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
					</label>
					<textarea name="Description" rows="4"><?=htmlspecialchars($_desc)?></textarea>
				</div>
				<div class="pn-acct-field">
					<label style="display:flex;align-items:center;gap:6px;">
						History <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
						<button type="button" class="kn-md-help-btn" onclick="document.getElementById('un-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
					</label>
					<textarea name="History" rows="4"><?=htmlspecialchars($_history)?></textarea>
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseDetailsModal()">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary" id="un-details-save-btn" disabled>
					<i class="fas fa-save"></i> Save
				</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Markdown Help Modal ─────────────────── -->
<div id="un-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-hashtag" style="margin-right:8px;color:var(--ork-link)"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('un-md-help-overlay').classList.remove('kn-open')">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:16px 20px">
			<table class="kn-md-help-table">
				<thead><tr><th>You type</th><th>Result</th></tr></thead>
				<tbody>
					<tr><td><code>**bold**</code></td><td><strong>bold</strong></td></tr>
					<tr><td><code>*italic*</code></td><td><em>italic</em></td></tr>
					<tr><td><code>~~strikethrough~~</code></td><td><s>strikethrough</s></td></tr>
					<tr><td><code>[link](https://...)</code></td><td><a href="#">link</a></td></tr>
					<tr><td><code>`inline code`</code></td><td><code>inline code</code></td></tr>
					<tr><td><code>- item</code></td><td>• Bullet list</td></tr>
					<tr><td><code>1. item</code></td><td>1. Numbered list</td></tr>
					<tr><td><code># Heading</code></td><td><strong>Large heading</strong></td></tr>
					<tr><td><code>## Heading</code></td><td><strong>Smaller heading</strong></td></tr>
					<tr><td><code>&gt; quote</code></td><td><em>Blockquote</em></td></tr>
					<tr><td>Blank line</td><td>New paragraph</td></tr>
					<tr><td>Single newline</td><td>Line break</td></tr>
				</tbody>
			</table>
		</div>
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
				<div class="pn-acct-field un-filter-field">
					<label>Filter players by</label>
					<select class="un-filter-kingdom" id="un-am-filter-kingdom"><?=$_kingdom_options?></select>
					<select class="un-filter-park" id="un-am-filter-park" style="display:none;"><option value="">All Parks</option></select>
				</div>
				<div class="pn-acct-field">
					<label>Player</label>
					<div class="pn-award-search-bar un-player-search" id="un-am-wrap">
						<input type="text" class="pn-award-search-input" id="un-am-input"
							placeholder="Search any player — all kingdoms…"
							autocomplete="off">
						<div class="un-ac-results" id="un-am-results"></div>
					</div>
					<input type="hidden" name="MundaneId" id="un-am-mundane-id">
					<div class="un-field-hint">Searches all players across every kingdom and park. Use the filter above to narrow to a kingdom or park (or type a <code>GP: name</code> / <code>BS:IK name</code> prefix).</div>
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
					<label>Title <span style="font-weight:400;color:var(--ork-text-lighter);">(optional)</span></label>
					<input type="text" name="Title" placeholder="Honorific or rank">
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary"
					onclick="unCloseModal('un-modal-add-member')">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary">
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

<?php endif; /* end $_can_edit modals */ ?>

<?php if ($_show_addmgr): ?>
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
<?php if (count($_nonmgr_members) > 0): ?>
				<div class="pn-acct-field">
					<label>Existing member <span style="font-weight:400;color:var(--ork-text-lighter);">(quick pick)</span></label>
					<select id="un-mg-member-pick">
						<option value="">— Choose a current member —</option>
<?php foreach ($_nonmgr_members as $_nm): ?>
						<option value="<?=(int)$_nm['MundaneId']?>"><?=htmlspecialchars($_nm['Persona'])?></option>
<?php endforeach; ?>
					</select>
					<div class="un-field-hint">Promote a current member to manager, or search for any player below.</div>
				</div>
				<div class="un-mg-or-divider">or search any player</div>
<?php endif; ?>
				<div class="pn-acct-field un-filter-field">
					<label>Filter players by</label>
					<select class="un-filter-kingdom" id="un-mg-filter-kingdom"><?=$_kingdom_options?></select>
					<select class="un-filter-park" id="un-mg-filter-park" style="display:none;"><option value="">All Parks</option></select>
				</div>
				<div class="pn-acct-field">
					<label>Player</label>
					<div class="pn-award-search-bar un-player-search" id="un-mg-wrap">
						<input type="text" class="pn-award-search-input" id="un-mg-input"
							placeholder="Search any player — all kingdoms…"
							autocomplete="off">
						<div class="un-ac-results" id="un-mg-results"></div>
					</div>
					<input type="hidden" name="MundaneId" id="un-mg-mundane-id">
					<div class="un-field-hint">Searches all players across every kingdom and park. Use the filter above to narrow to a kingdom or park (or type a <code>GP: name</code> / <code>BS:IK name</code> prefix).</div>
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

<?php endif; /* end $_show_addmgr */ ?>

<?php if ($_show_retire): ?>
<!-- ── Retire Modal ─────────────────────────────────────── -->
<div class="pn-overlay" id="un-modal-retire">
	<div class="pn-modal-box" style="max-width:460px">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-box-archive" style="margin-right:8px;color:#c05621"></i>Retire <?=htmlspecialchars($_name)?></h3>
			<button class="pn-modal-close-btn" onclick="unCloseModal('un-modal-retire')">&times;</button>
		</div>
<?php if ($_can_transfer): ?>
		<div class="pn-modal-body">
			<p style="margin:0 0 14px;color:var(--ork-text);font-size:14px;">
				Are you sure you want to retire <strong><?=htmlspecialchars($_name)?></strong>? You can alternatively transfer ownership to another member.
			</p>
			<form method="post" action="<?=htmlspecialchars($_base_url)?>">
				<input type="hidden" name="Action" value="transfer_ownership">
				<div class="pn-acct-field">
					<label>Transfer ownership to</label>
					<select name="MundaneId" id="un-transfer-target" required>
						<option value="">— Select a member —</option>
<?php
$_mgr_open = false; $_mem_open = false;
foreach ($_transfer_targets as $_tt):
	if ($_tt['IsManager'] && !$_mgr_open) { echo '<optgroup label="Managers">'; $_mgr_open = true; }
	if (!$_tt['IsManager'] && !$_mem_open) { if ($_mgr_open) echo '</optgroup>'; echo '<optgroup label="Members">'; $_mem_open = true; }
?>
						<option value="<?=(int)$_tt['MundaneId']?>"><?=htmlspecialchars($_tt['Persona'])?></option>
<?php endforeach; if ($_mgr_open || $_mem_open) echo '</optgroup>'; ?>
					</select>
					<div class="un-field-hint">The selected member becomes a manager and you step down as owner. The <?=htmlspecialchars($_type_l)?> stays active.</div>
				</div>
				<button type="submit" class="pn-btn pn-btn-primary" style="width:100%;">
					<i class="fas fa-people-arrows"></i> Transfer Ownership
				</button>
			</form>
			<div class="un-mg-or-divider">or retire the <?=htmlspecialchars($_type_l)?></div>
			<div class="un-retire-note">
				Retiring hides this <?=htmlspecialchars($_type_l)?> from listings and search. You will need a member of monarchy to reactivate it.
			</div>
			<form method="post" action="<?=htmlspecialchars($_base_url)?>">
				<input type="hidden" name="Action" value="retire_unit">
				<button type="submit" class="pn-btn pn-btn-ghost" style="width:100%;color:#c05621;border-color:#c05621;">
					<i class="fas fa-box-archive"></i> Retire This <?=htmlspecialchars($_type)?>
				</button>
			</form>
		</div>
<?php else: ?>
		<div class="pn-modal-body">
			<p style="margin:0 0 14px;color:var(--ork-text);font-size:14px;">
				Confirm you wish to retire <strong><?=htmlspecialchars($_name)?></strong> here. Please note, you will need a member of monarchy to reactivate a retired <?=htmlspecialchars($_type_l)?>.
			</p>
			<form method="post" action="<?=htmlspecialchars($_base_url)?>">
				<input type="hidden" name="Action" value="retire_unit">
				<div class="pn-modal-footer" style="padding:0;border:0;">
					<button type="button" class="pn-btn pn-btn-secondary" onclick="unCloseModal('un-modal-retire')">Cancel</button>
					<button type="submit" class="pn-btn pn-btn-primary" style="background:#c05621;border-color:#c05621;">
						<i class="fas fa-box-archive"></i> Retire This <?=htmlspecialchars($_type)?>
					</button>
				</div>
			</form>
		</div>
<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
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

<?php if ($_can_edit || $_show_addmgr || $_show_retire): ?>
// ── Shared modal helpers ──────────────────────────────────
function unOpenModal(id) {
	var el = document.getElementById(id);
	if (el) el.classList.add('pn-open');
}
function unCloseModal(id) {
	var el = document.getElementById(id);
	if (el) el.classList.remove('pn-open');
}
/* Close modals on backdrop click */
document.querySelectorAll('.pn-overlay').forEach(function (overlay) {
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) overlay.classList.remove('pn-open');
	});
});
/* Escape closes whichever modal is open (elements vary by role, so guard) */
document.addEventListener('keydown', function(e) {
	if (e.key !== 'Escape') return;
	var mdHelp  = document.getElementById('un-md-help-overlay');
	var details = document.getElementById('un-modal-details');
	if (mdHelp && mdHelp.classList.contains('kn-open')) {
		mdHelp.classList.remove('kn-open');
	} else if (details && details.classList.contains('pn-open')) {
		unCloseDetailsModal();
	} else {
		['un-modal-add-member', 'un-modal-edit-member', 'un-modal-add-manager', 'un-modal-retire'].forEach(unCloseModal);
	}
}, true);
<?php endif; ?>

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
				document.getElementById('un-img-step-done').style.display = '';
				setTimeout(function() { window.location.reload(); }, 1200);
			} else {
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

// ── Details modal dirty tracking ──
var _unDetailsForm = document.getElementById('un-modal-details') && document.querySelector('#un-modal-details form');
var _unDetailsOriginals = {};
var _unDetailsSaveBtn = document.getElementById('un-details-save-btn');

(function() {
	var form = document.querySelector('#un-modal-details form');
	if (!form) return;
	_unDetailsForm = form;
	form.querySelectorAll('input, textarea').forEach(function(el) {
		if (el.name) _unDetailsOriginals[el.name] = el.value;
	});
	form.querySelectorAll('input, textarea').forEach(function(el) {
		el.addEventListener('input', unCheckDetailsDirty);
		el.addEventListener('change', unCheckDetailsDirty);
	});
})();

function unCheckDetailsDirty() {
	if (!_unDetailsForm) return;
	var dirty = false;
	_unDetailsForm.querySelectorAll('input, textarea').forEach(function(el) {
		if (el.name && _unDetailsOriginals.hasOwnProperty(el.name) && el.value !== _unDetailsOriginals[el.name]) dirty = true;
	});
	if (_unDetailsSaveBtn) _unDetailsSaveBtn.disabled = !dirty;
}

function unRestoreDetailsForm() {
	if (!_unDetailsForm) return;
	_unDetailsForm.querySelectorAll('input, textarea').forEach(function(el) {
		if (el.name && _unDetailsOriginals.hasOwnProperty(el.name)) el.value = _unDetailsOriginals[el.name];
	});
	if (_unDetailsSaveBtn) _unDetailsSaveBtn.disabled = true;
}

function unCloseDetailsModal() {
	if (_unDetailsSaveBtn && !_unDetailsSaveBtn.disabled) {
		pnConfirm({ title: 'Unsaved Changes', message: 'You have unsaved changes. Discard them?', confirmText: 'Discard', danger: true }, function() {
			unRestoreDetailsForm();
			unCloseModal('un-modal-details');
		});
		return;
	}
	unCloseModal('un-modal-details');
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

<?php endif; /* end $_can_edit JS */ ?>

<?php if ($_can_edit || $_show_addmgr): ?>
/* ── Player search factory ──────────────────────────────── */
var UN_PSEARCH_BASE = '<?=UIR?>KingdomAjax/playersearch/';
var UN_SCOPE_KID  = <?=(int)($ScopeKingdomId ?? 0)?>;
var UN_SCOPE_PID  = <?=(int)($ScopeParkId ?? 0)?>;
var UN_UIR        = '<?=UIR?>';

function initPlayerSearch(cfg) {
	/* cfg: { inputId, resultsId, hiddenId, parkId, kingdomId, kingdomSelId, parkSelId } */
	var $input   = document.getElementById(cfg.inputId);
	var $results = document.getElementById(cfg.resultsId);
	var $hidden  = document.getElementById(cfg.hiddenId);
	var debounce, focusIdx = -1, searchSeq = 0;
	var seen = {};
	/* "Filter players by" selection (0 = All). When a kingdom is chosen the
	   search is scoped to it (and optionally a park) instead of going global. */
	var filterKingdom = 0, filterPark = 0;

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
		var nameSpan = document.createElement('span');
		nameSpan.textContent = label;
		el.appendChild(nameSpan);
		if (scope) {
			var scopeSpan = document.createElement('span');
			scopeSpan.className   = 'un-ac-scope';
			scopeSpan.textContent = scope;
			el.appendChild(scopeSpan);
		}
		el.addEventListener('mousedown', function (e) {
			e.preventDefault();
			selectPlayer(id, label + (scope ? ' (' + scope + ')' : ''));
		});
		return el;
	}

	function addGroup(label, players) {
		var unseen = (players || []).filter(function (p) { return !seen[p.MundaneId]; });
		if (!unseen.length) return;
		var hdr = document.createElement('div');
		hdr.className   = 'un-ac-group-label';
		hdr.textContent = label;
		$results.appendChild(hdr);
		unseen.forEach(function (p) {
			seen[p.MundaneId] = true;
			$results.appendChild(buildItem(p, ''));
		});
	}

	function showEmpty() {
		var empty = document.createElement('div');
		empty.className   = 'un-ac-empty';
		empty.textContent = 'No players found.';
		$results.appendChild(empty);
	}

	function runSearch(term) {
		/* Canonical playersearch endpoint: LIKE-based filtering, own-kingdom-first
		   ordering, robust scope handling. Mirrors the Event RSVP modal's tiers. */
		var kid  = cfg.kingdomId || 0;
		var pid  = cfg.parkId || 0;
		var base = UN_PSEARCH_BASE + kid;
		var q    = '&include_inactive=1&include_suspended=1&q=' + encodeURIComponent(term);
		var groups;
		// An abbreviation prefix ("nb:ff wolf") makes the server filter by abbreviation
		// and ignore scope/park_id — show one group so results aren't mislabelled.
		if (/^[a-z0-9]{2,3}:[a-z0-9*]{0,3}\s+\S/i.test(term)) {
			groups = [ { label: 'All Players', url: base + '&scope=all' + q } ];
		} else if (pid > 0 && kid > 0) {
			groups = [
				{ label: 'In Park',     url: base + '&scope=own&park_id=' + pid + q },
				{ label: 'In Kingdom',  url: base + '&scope=own' + q },
				{ label: 'All Players', url: base + '&scope=exclude' + q }
			];
		} else if (kid > 0) {
			groups = [
				{ label: 'In Kingdom',  url: base + '&scope=own' + q },
				{ label: 'All Players', url: base + '&scope=exclude' + q }
			];
		} else {
			groups = [ { label: 'All Players', url: base + '&scope=all' + q } ];
		}
		var mySeq = ++searchSeq;
		Promise.all(groups.map(function (g) {
			return fetch(g.url).then(function (r) { return r.json(); }).catch(function () { return []; });
		})).then(function (resArr) {
			if (mySeq !== searchSeq) return; // a newer keystroke superseded this response
			seen = {};
			$results.innerHTML = '';
			$hidden.value = '';
			groups.forEach(function (g, i) { addGroup(g.label, resArr[i] || []); });
			if (!$results.children.length) {
				var empty = document.createElement('div');
				empty.className   = 'un-ac-empty';
				empty.textContent = 'No players found.';
				$results.appendChild(empty);
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
			if (focusIdx >= 0) items[focusIdx].classList.remove('un-ac-focused');
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

	function rerun() {
		var term = $input.value.trim();
		if (term.length >= 2) runSearch(term); else closeResults();
	}

	if ($kSel) {
		$kSel.addEventListener('change', function () {
			filterKingdom = parseInt(this.value, 10) || 0;
			filterPark = 0;
			if ($pSel) {
				$pSel.innerHTML = '<option value="">All Parks</option>';
				if (filterKingdom) {
					$pSel.style.display = '';
					fetch(UN_UIR + 'KingdomAjax/kingdom/' + filterKingdom + '/getparks')
						.then(function (r) { return r.json(); })
						.then(function (d) {
							(d.parks || []).forEach(function (pk) {
								var o = document.createElement('option');
								o.value = pk.ParkId; o.textContent = pk.Name;
								$pSel.appendChild(o);
							});
						})
						.catch(function () { /* leave park filter as All Parks on error */ });
				} else {
					$pSel.style.display = 'none';
				}
			}
			rerun();
		});
	}
	if ($pSel) {
		$pSel.addEventListener('change', function () {
			filterPark = parseInt(this.value, 10) || 0;
			rerun();
		});
	}

	$input.addEventListener('blur', function () {
		setTimeout(closeResults, 150);
	});
}

/* Initialise search widgets per available modal */
<?php if ($_can_edit): ?>
initPlayerSearch({ inputId: 'un-am-input', resultsId: 'un-am-results', hiddenId: 'un-am-mundane-id', parkId: UN_SCOPE_PID, kingdomId: UN_SCOPE_KID, kingdomSelId: 'un-am-filter-kingdom', parkSelId: 'un-am-filter-park' });
document.getElementById('un-modal-add-member').addEventListener('transitionend', function () {
	if (!this.classList.contains('pn-open')) {
		document.getElementById('un-am-input').value = '';
		document.getElementById('un-am-mundane-id').value = '';
	}
});
<?php endif; ?>
<?php if ($_show_addmgr): ?>
initPlayerSearch({ inputId: 'un-mg-input', resultsId: 'un-mg-results', hiddenId: 'un-mg-mundane-id', parkId: UN_SCOPE_PID, kingdomId: UN_SCOPE_KID, kingdomSelId: 'un-mg-filter-kingdom', parkSelId: 'un-mg-filter-park' });
document.getElementById('un-modal-add-manager').addEventListener('transitionend', function () {
	if (!this.classList.contains('pn-open')) {
		document.getElementById('un-mg-input').value = '';
		document.getElementById('un-mg-mundane-id').value = '';
		var pick = document.getElementById('un-mg-member-pick');
		if (pick) { pick.value = ''; document.getElementById('un-mg-input').disabled = false; }
	}
});
/* Quick-pick: choosing an existing member fills the hidden id and locks search */
var _unMgPick = document.getElementById('un-mg-member-pick');
if (_unMgPick) {
	_unMgPick.addEventListener('change', function () {
		var input  = document.getElementById('un-mg-input');
		var hidden = document.getElementById('un-mg-mundane-id');
		if (this.value) {
			hidden.value = this.value;
			input.value  = this.options[this.selectedIndex].text;
			input.disabled = true;
		} else {
			hidden.value = '';
			input.value  = '';
			input.disabled = false;
		}
	});
}
<?php endif; ?>
<?php endif; ?>
</script>
<style>
/* DataTables pagination dark mode — end of page to guarantee last cascade position */
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button,
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button:hover {
  background-color: #2d3748 !important; background-image: none !important;
  border-color: #4a5568 !important; color: #cbd5e0 !important;
}
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button.current,
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button.current:hover {
  background-color: #2b6cb0 !important; background-image: none !important;
  color: #fff !important; border-color: #2b6cb0 !important;
}
html[data-theme="dark"] #un-roster-table_wrapper .dataTables_paginate .paginate_button.disabled {
  opacity: 0.4 !important;
}
</style>
<style>
/* ── Retire / Claim / Restore card elements ─────────────────────── */
.un-card-text {
  font-size: 13px;
  color: var(--ork-text-secondary);
  line-height: 1.5;
  margin: 0 0 12px;
}
.un-card-section {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--ork-border, #e2e8f0);
}
.un-card-subhead {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--ork-text-muted);
  margin: 0 0 8px;
  display: flex;
  align-items: center;
  gap: 6px;
  background: none;
  border: none;
  padding: 0;
}
.un-mg-or-divider {
  text-align: center;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--ork-text-lighter);
  margin: 16px 0;
  position: relative;
}
.un-mg-or-divider::before,
.un-mg-or-divider::after {
  content: '';
  position: absolute;
  top: 50%;
  width: 34%;
  height: 1px;
  background: var(--ork-border, #e2e8f0);
}
.un-mg-or-divider::before { left: 0; }
.un-mg-or-divider::after  { right: 0; }
.un-retire-note {
  font-size: 12px;
  color: var(--ork-text-secondary);
  background: var(--ork-alert-warning-bg, #fffaf0);
  border: 1px solid var(--ork-alert-warning-border, #f6e05e);
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 12px;
  line-height: 1.45;
}
.un-retired-card { border-left: 3px solid #c05621; }
html[data-theme="dark"] .un-retire-note {
  background: rgba(192, 86, 33, 0.12);
  border-color: rgba(192, 86, 33, 0.45);
  color: var(--ork-text-secondary);
}
</style>
