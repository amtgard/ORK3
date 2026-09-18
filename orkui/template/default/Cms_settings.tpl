<?php
/**
 * Cms_settings.tpl — OGRE Settings: who can work on this site.
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * Receives (from Controller_Cms::settings):
 *   $Members       list of ['mundane_id','persona','given_name','surname',
 *                  'roles','role','role_label','granted_at','granted_by',
 *                  'granted_by_persona'] — people holding grants IN THIS SCOPE
 *   $RoleCatalog   list of ['key','label','blurb','capabilities'=>[['key','label'],…]]
 *                  built from CmsAuth's real ladder, lowest rung first
 *   $OrkAdmins     list of ['MundaneId','Persona','GivenName','Surname',
 *                  'LastLogin',…] — GLOBAL scope only
 *   $ShowOrkAdmins bool    whether this scope is the global front door
 *   $OrkAdminUrl   string  Admin → Permissions, where ORK Admin is managed
 *   $SettingsUid   int     the viewer, so their own row can be marked "You"
 *   $CmsScopeLabel string  org name ('' at global) — from the shell
 *   $CmsScopeNoun  string  'Kingdom' | 'Principality' | 'Park' ('' at global)
 *   $Caps          array   capability flags (rail)
 *   UIR, HTTP_TEMPLATE    (constants)
 *
 * SECURITY: Controller_Cms::settings() gates on roles.manage before this
 * renders, so every viewer here may already grant and revoke. Everything
 * interpolated below is escaped regardless — personas are user-supplied.
 */

$members = isset($Members) && is_array($Members) ? $Members : array();
$roles   = isset($RoleCatalog) && is_array($RoleCatalog) ? $RoleCatalog : array();
$orkAdmins = isset($OrkAdmins) && is_array($OrkAdmins) ? $OrkAdmins : array();
$showOrk   = !empty($ShowOrkAdmins);
$orkUrl    = isset($OrkAdminUrl) ? (string)$OrkAdminUrl : '';
$viewerId  = isset($SettingsUid) ? (int)$SettingsUid : 0;
$scopeLabel = isset($CmsScopeLabel) ? (string)$CmsScopeLabel : '';
$scopeNoun  = isset($CmsScopeNoun) ? (string)$CmsScopeNoun : '';
$isOrkAdmin = !empty($IsOrkAdmin);
$kingdomSitesOn = !empty($KingdomSitesEnabled);
$parkSitesOn    = !empty($ParkSitesEnabled);
$showAllowParks = !empty($ShowAllowParkSites);
$allowParksOn   = !empty($AllowParkSites);

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// The site this page is about, in words, for the copy below.
$siteName = $scopeLabel !== '' ? $scopeLabel : 'the Amtgard front door';

// Highest rung last in the catalog; the picker defaults to the LOWEST so an
// accidental Enter grants the least power, never the most.
$defaultRole = !empty($roles) ? $roles[0]['key'] : '';

// Role key → label, for the member table's badges without a second lookup.
$roleLabels = array();
foreach ($roles as $r) {
    $roleLabels[$r['key']] = $r['label'];
}

// A member's real name, when we have one, for the secondary line under persona.
$realName = function ($m) {
    return trim((string)($m['given_name'] ?? '') . ' ' . (string)($m['surname'] ?? ''));
};
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'settings';
$cmsTitle   = 'Settings';
$cmsSub     = 'Who can work on ' . $siteName;
$cmsActions = '<button type="button" class="cms-btn cms-btn-primary" id="cmsAddUserBtn">'
    . '<i class="fas fa-user-plus"></i> Add a user</button>';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <?php /* ---- Site-creation policy. GLOBAL: the two ORK-Admin switches that
            decide whether each tier may build sites at all. KINGDOM: this
            kingdom's own permission for its parks, shown only once the ORK has
            park sites switched on. Both gate CREATION only — a site that already
            exists keeps working either way. ---- */ ?>
    <?php if ($showOrk || $showAllowParks): ?>
    <section class="cms-settings-section" aria-labelledby="cmsPolicyHead">
        <div class="cms-section-head">
            <h2 class="cms-section-title" id="cmsPolicyHead">Site availability</h2>
            <p class="cms-section-sub">
                <?php if ($showOrk): ?>
                    Which parts of the ORK may build their own OGRE sites. These are
                    <strong>ORK Administrator</strong> settings, not OGRE ones — an OGRE
                    Administrator cannot change them. Switching one off stops new sites
                    being created; sites that already exist keep working.
                <?php else: ?>
                    Whether the parks in this <?= $h($scopeNoun !== '' ? strtolower($scopeNoun) : 'kingdom') ?>
                    may build their own OGRE sites. Switching it off stops new park sites
                    being created; parks that already have one keep it.
                <?php endif; ?>
            </p>
        </div>

        <div class="cms-policy-list">
            <?php if ($showOrk): ?>
                <?php
                $orkToggles = array(
                    array('CmsKingdomSitesEnabled', 'Enable Kingdom Sites',
                          'Lets kingdoms build and publish their own OGRE site.', $kingdomSitesOn),
                    array('CmsParkSitesEnabled', 'Enable Park Sites',
                          'Lets parks build their own site — each kingdom then decides whether ITS parks may.', $parkSitesOn),
                );
                foreach ($orkToggles as $t):
                ?>
                <div class="cms-policy-row">
                    <label class="cms-policy-label">
                        <span class="cms-switch">
                            <input type="checkbox" class="cms-policy-toggle"
                                   data-policy-key="<?= $h($t[0]) ?>"
                                   <?= $t[3] ? 'checked' : '' ?>
                                   <?= $isOrkAdmin ? '' : 'disabled' ?>>
                            <span class="cms-slider"></span>
                        </span>
                        <span class="cms-policy-text">
                            <strong><?= $h($t[1]) ?></strong>
                            <span class="cms-policy-note"><?= $h($t[2]) ?></span>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>

                <?php if (!$isOrkAdmin): ?>
                    <p class="cms-policy-locked">
                        <i class="fas fa-lock" aria-hidden="true"></i>
                        These are ORK Administrator settings. You can see them, but only an
                        ORK Administrator can change them.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($showAllowParks): ?>
                <div class="cms-policy-row">
                    <label class="cms-policy-label">
                        <span class="cms-switch">
                            <input type="checkbox" class="cms-policy-toggle"
                                   data-policy-key="CmsAllowParkSites"
                                   <?= $allowParksOn ? 'checked' : '' ?>>
                            <span class="cms-slider"></span>
                        </span>
                        <span class="cms-policy-text">
                            <strong>Allow Parks to Create Sites</strong>
                            <span class="cms-policy-note">
                                Lets the parks in this <?= $h($scopeNoun !== '' ? strtolower($scopeNoun) : 'kingdom') ?>
                                build their own OGRE site.
                            </span>
                        </span>
                    </label>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="cms-settings-section" aria-labelledby="cmsMembersHead">
        <div class="cms-section-head">
            <h2 class="cms-section-title" id="cmsMembersHead">Manage users</h2>
            <p class="cms-section-sub">
                These people can sign in to OGRE and work on <strong><?= $h($siteName) ?></strong>.
                A role here grants rights <em>in OGRE only</em> — it never confers any
                ORK administrative access.
            </p>
        </div>

        <?php if (empty($members)): ?>
            <div class="cms-empty">
                <div class="cms-empty-icon"><i class="fas fa-users" aria-hidden="true"></i></div>
                <div class="cms-empty-copy">
                    Nobody has been given OGRE access yet.
                    <?php if ($showOrk): ?>
                        For now only ORK Administrators can edit this site — add a user to let
                        someone work on it without giving them ORK-wide access.
                    <?php else: ?>
                        Add a user to let someone work on this site's pages, posts and media.
                    <?php endif; ?>
                </div>
                <button type="button" class="cms-btn cms-btn-primary cms-empty-cta" data-add-user>
                    <i class="fas fa-user-plus"></i> Add a user
                </button>
            </div>
        <?php else: ?>
            <div class="cms-table-wrap">
                <table class="cms-table" id="cms-members-table">
                    <thead>
                        <tr>
                            <th scope="col">Person</th>
                            <th scope="col">Role</th>
                            <th scope="col">Can do</th>
                            <th scope="col">Added</th>
                            <th scope="col" class="cms-actions-col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m):
                            $mid   = (int)$m['mundane_id'];
                            $isYou = ($mid === $viewerId && $viewerId > 0);
                            $name  = $realName($m);
                            $persona = (string)$m['persona'] !== '' ? (string)$m['persona'] : 'Player #' . $mid;
                            // Sort on the epoch, display the words — data-order keeps
                            // the date column from sorting alphabetically.
                            $grantedTs = strtotime((string)($m['granted_at'] ?? '')) ?: 0;
                        ?>
                        <tr data-mundane-id="<?= $mid ?>">
                            <td>
                                <a class="cms-member-name" href="<?= UIR ?>Player/profile/<?= $mid ?>">
                                    <?= $h($persona) ?></a><?php if ($isYou): ?><span class="cms-badge cms-badge-you">You</span><?php endif; ?>
                                <?php if ($name !== ''): ?>
                                    <span class="cms-member-real"><?= $h($name) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-role="<?= $h($m['role']) ?>">
                                <span class="cms-role-badge cms-role-<?= $h($m['role']) ?>">
                                    <?= $h($m['role_label'] !== '' ? $m['role_label'] : $m['role']) ?>
                                </span>
                            </td>
                            <td class="cms-role-caps-cell">
                                <?php
                                // The capability summary for the rung this person
                                // actually holds, so the table answers "what can
                                // they do" without a trip to the reference below.
                                $theirCaps = array();
                                foreach ($roles as $r) {
                                    if ($r['key'] === $m['role']) {
                                        $theirCaps = $r['capabilities'];
                                        break;
                                    }
                                }
                                $capNames = array();
                                foreach ($theirCaps as $c) {
                                    $capNames[] = $c['label'];
                                }
                                ?>
                                <span class="cms-cap-count" data-tip="<?= $h(implode(' · ', $capNames)) ?>">
                                    <?= count($capNames) ?> permission<?= count($capNames) === 1 ? '' : 's' ?>
                                </span>
                            </td>
                            <td data-order="<?= $grantedTs ?>">
                                <?= $h($cmsFmtDate($m['granted_at'] ?? '')) ?>
                                <?php if (!empty($m['granted_by_persona'])): ?>
                                    <span class="cms-member-real">by <?= $h($m['granted_by_persona']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="cms-actions-col">
                                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost"
                                        data-edit-user="<?= $mid ?>"
                                        data-persona="<?= $h($persona) ?>"
                                        data-role="<?= $h($m['role']) ?>">
                                    <i class="fas fa-pen"></i> Change role
                                </button>
                                <button type="button" class="cms-btn cms-btn-sm cms-btn-danger"
                                        data-remove-user="<?= $mid ?>"
                                        data-persona="<?= $h($persona) ?>">
                                    <i class="fas fa-user-minus"></i> Remove
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php /* ---- Role reference: rendered from CmsAuth::RoleCatalog(), so it
            always describes the capabilities the gates actually enforce. ---- */ ?>
    <section class="cms-settings-section" aria-labelledby="cmsRolesHead">
        <div class="cms-section-head">
            <h2 class="cms-section-title" id="cmsRolesHead">What each role can do</h2>
            <p class="cms-section-sub">
                Roles are cumulative — each one can do everything the roles above it can,
                plus its own additions. None of them grants any ORK access.
            </p>
        </div>

        <div class="cms-role-grid">
            <?php foreach ($roles as $i => $r): ?>
                <article class="cms-role-card cms-role-card-<?= $h($r['key']) ?>">
                    <header class="cms-role-card-head">
                        <span class="cms-role-badge cms-role-<?= $h($r['key']) ?>"><?= $h($r['label']) ?></span>
                        <span class="cms-role-rung">Level <?= $i + 1 ?> of <?= count($roles) ?></span>
                    </header>
                    <p class="cms-role-blurb"><?= $h($r['blurb']) ?></p>
                    <ul class="cms-role-caps">
                        <?php foreach ($r['capabilities'] as $c): ?>
                            <li><i class="fas fa-check" aria-hidden="true"></i><?= $h($c['label']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php
                    // Say plainly what this rung CANNOT do — the reason someone is
                    // reading this page is usually to find the rung that stops
                    // short of Theme or Settings.
                    $theirKeys = array();
                    foreach ($r['capabilities'] as $c) {
                        $theirKeys[] = $c['key'];
                    }
                    $missing = array();
                    foreach ($roles as $other) {
                        foreach ($other['capabilities'] as $c) {
                            if (!in_array($c['key'], $theirKeys, true) && !in_array($c['label'], $missing, true)) {
                                $missing[] = $c['label'];
                            }
                        }
                    }
                    ?>
                    <?php if (!empty($missing)): ?>
                        <ul class="cms-role-caps cms-role-caps-no">
                            <?php foreach ($missing as $mLabel): ?>
                                <li><i class="fas fa-times" aria-hidden="true"></i><?= $h($mLabel) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php /* ---- ORK Administrators. Read-only: they pass every OGRE gate via
            CmsAuth::IsSuperAdmin()'s short-circuit without holding a grant row,
            so a roster of grants alone would be quietly wrong about who can edit
            this site. The role itself is managed in Admin → Permissions, which
            stays the single place ORK-level authority is handed out. ---- */ ?>
    <?php if ($showOrk): ?>
    <section class="cms-settings-section" aria-labelledby="cmsOrkHead">
        <div class="cms-section-head">
            <h2 class="cms-section-title" id="cmsOrkHead">ORK Administrators</h2>
            <p class="cms-section-sub">
                These accounts hold the ORK Admin role, which grants full OGRE access
                everywhere — they do not need a role above and cannot be removed here.
                <?php if ($orkUrl !== ''): ?>
                    ORK Admin is managed in <a href="<?= $h($orkUrl) ?>">Admin → Permissions</a>.
                <?php endif; ?>
            </p>
        </div>

        <?php if (empty($orkAdmins)): ?>
            <div class="cms-notice">
                The ORK Administrator list is only visible to ORK Administrators.
                <?php if ($orkUrl !== ''): ?>
                    It can be viewed in <a href="<?= $h($orkUrl) ?>">Admin → Permissions</a>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="cms-table-wrap">
                <table class="cms-table" id="cms-orkadmins-table">
                    <thead>
                        <tr>
                            <th scope="col">Person</th>
                            <th scope="col">Name</th>
                            <th scope="col">Last login</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orkAdmins as $a):
                            $aid = (int)($a['MundaneId'] ?? 0);
                            $aName = trim((string)($a['GivenName'] ?? '') . ' ' . (string)($a['Surname'] ?? ''));
                            $loginTs = strtotime((string)($a['LastLogin'] ?? '')) ?: 0;
                        ?>
                        <tr>
                            <td>
                                <a class="cms-member-name" href="<?= UIR ?>Player/profile/<?= $aid ?>">
                                    <?= $h(($a['Persona'] ?? '') !== '' ? $a['Persona'] : 'Player #' . $aid) ?></a><?php if ($aid === $viewerId && $viewerId > 0): ?><span class="cms-badge cms-badge-you">You</span><?php endif; ?>
                            </td>
                            <td><?= $aName !== '' ? $h($aName) : '—' ?></td>
                            <td data-order="<?= $loginTs ?>"><?= $h($cmsFmtDate($a['LastLogin'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<?php /* ---- Add / change role modal. One dialog for both: adding a user and
        changing an existing one are the same write (SetUserRole), so they are
        the same form with a different heading and a locked person field. ---- */ ?>
<div class="cms-modal-overlay" id="cmsUserModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cmsUserModalTitle">
        <div class="cms-modal-head">
            <h3 id="cmsUserModalTitle">Add a user</h3>
            <button type="button" class="cms-modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="cms-modal-body">
            <div class="cms-field" id="cmsUserPickField">
                <label class="cms-label" for="cmsUserSearch">Person</label>
                <input type="text" class="cms-input" id="cmsUserSearch"
                       placeholder="Search by persona…" autocomplete="off">
                <input type="hidden" id="cmsUserMundaneId" value="">
                <p class="cms-help" id="cmsUserPickHint">
                    Start typing a persona, then choose from the list.
                </p>
            </div>

            <div class="cms-field" id="cmsUserLockedField" hidden>
                <label class="cms-label">Person</label>
                <p class="cms-locked-person" id="cmsUserLockedName"></p>
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsUserRole">Role</label>
                <select class="cms-select" id="cmsUserRole">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $h($r['key']) ?>"<?= $r['key'] === $defaultRole ? ' selected' : '' ?>>
                            <?= $h($r['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="cms-role-explain" id="cmsUserRoleExplain"></p>
            </div>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="cms-btn cms-btn-primary" id="cmsUserSave">Save</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/cms/_confirm_modal.tpl'; ?>

<div class="cms-toast" id="cmsToast" role="status" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
    'use strict';

    // The role catalog, so the modal can explain the selected role without a
    // round-trip. Same array the page above rendered from.
    var ROLES = <?= json_encode($roles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    // Which kingdom's roster the persona search covers. Resolved server-side
    // (a PARK site searches its parent kingdom, not its own id), and 0 only for
    // the global front door, where a site-wide search is the intended behaviour.
    var SCOPE_KINGDOM = <?= (int)(isset($SearchKingdomId) ? $SearchKingdomId : 0) ?>;

    var modal      = document.getElementById('cmsUserModal');
    var searchInp  = document.getElementById('cmsUserSearch');
    var midInp     = document.getElementById('cmsUserMundaneId');
    var roleSel    = document.getElementById('cmsUserRole');
    var explainEl  = document.getElementById('cmsUserRoleExplain');
    var titleEl    = document.getElementById('cmsUserModalTitle');
    var pickField  = document.getElementById('cmsUserPickField');
    var lockField  = document.getElementById('cmsUserLockedField');
    var lockName   = document.getElementById('cmsUserLockedName');
    var saveBtn    = document.getElementById('cmsUserSave');

    /* ---- DataTables on both rosters ---- */
    function initTable(sel, noun, orderCol) {
        var el = document.querySelector(sel);
        if (!el || !window.jQuery || !jQuery.fn.DataTable) { return; }
        jQuery(sel).DataTable({
            dom: 'lfrtip',
            pageLength: 25,
            order: [[orderCol, 'desc']],
            language: {
                lengthMenu: 'Show _MENU_ ' + noun,
                search: '',
                searchPlaceholder: 'Search ' + noun + '…',
                info: '_START_–_END_ of _TOTAL_ ' + noun,
                infoEmpty: 'No ' + noun,
                infoFiltered: '(of _MAX_)',
                zeroRecords: 'No ' + noun + ' match that search.'
            },
            columnDefs: [{ targets: [orderCol], type: 'num' }]
        });
        var filter = document.getElementById(el.id + '_filter');
        var input = filter ? filter.querySelector('input') : null;
        if (input) { input.setAttribute('aria-label', 'Search ' + noun); }
    }
    initTable('#cms-members-table', 'users', 3);
    initTable('#cms-orkadmins-table', 'administrators', 2);

    /* ---- Role picker explanation ---- */
    function roleByKey(key) {
        for (var i = 0; i < ROLES.length; i++) {
            if (ROLES[i].key === key) { return ROLES[i]; }
        }
        return null;
    }
    function explainRole() {
        var r = roleByKey(roleSel.value);
        if (!r) { explainEl.textContent = ''; return; }
        var caps = (r.capabilities || []).map(function (c) { return c.label; });
        explainEl.innerHTML = '<strong>' + CmsAdmin.esc(r.blurb) + '</strong>'
            + '<span class="cms-role-explain-caps">' + CmsAdmin.esc(caps.join(' · ')) + '</span>';
    }
    roleSel.addEventListener('change', explainRole);

    /* ---- Persona autocomplete (shared helper; body-appended + fixed) ---- */
    CmsAdmin.personaSearch(searchInp, {
        kingdomId: SCOPE_KINGDOM,
        onPick: function (row) {
            searchInp.value = row.Persona || '';
            midInp.value = row.MundaneId || '';
        }
    });
    // Typing after a pick invalidates it — otherwise an edited name could be
    // submitted against the previously chosen id.
    searchInp.addEventListener('input', function () { midInp.value = ''; });

    /* ---- Open: add vs. change ---- */
    function openAdd() {
        titleEl.textContent = 'Add a user';
        pickField.hidden = false;
        lockField.hidden = true;
        searchInp.value = '';
        midInp.value = '';
        roleSel.selectedIndex = 0;
        explainRole();
        CmsAdmin.modal.open(modal);
        setTimeout(function () { searchInp.focus(); }, 60);
    }
    function openEdit(mid, persona, role) {
        titleEl.textContent = 'Change role';
        pickField.hidden = true;
        lockField.hidden = false;
        lockName.textContent = persona;
        midInp.value = mid;
        roleSel.value = role;
        explainRole();
        CmsAdmin.modal.open(modal);
        setTimeout(function () { roleSel.focus(); }, 60);
    }

    document.addEventListener('click', function (e) {
        var add = e.target.closest('#cmsAddUserBtn, [data-add-user]');
        if (add) { openAdd(); return; }

        var edit = e.target.closest('[data-edit-user]');
        if (edit) {
            openEdit(edit.getAttribute('data-edit-user'),
                     edit.getAttribute('data-persona'),
                     edit.getAttribute('data-role'));
            return;
        }

        var remove = e.target.closest('[data-remove-user]');
        if (remove) {
            var rid = remove.getAttribute('data-remove-user');
            var rpersona = remove.getAttribute('data-persona');
            // Single options object — CmsAdmin.confirm's positional
            // (title, message, okLabel, onOk) form applies ONLY when the first
            // argument is a string; passing an object alongside positional
            // arguments silently drops the message, the label and onOk.
            CmsAdmin.confirm({
                title: 'Remove OGRE access',
                message: 'Remove all OGRE access for ' + rpersona + '? They keep their ORK account '
                    + 'and anything they have already published stays where it is.',
                okLabel: 'Remove access',
                onOk: function () {
                    CmsAdmin.confirmBusy(true);
                    CmsAdmin.post('removerole', { mundane_id: rid })
                        .then(function (r) {
                            CmsAdmin.confirmBusy(false);
                            if (!r || !r.ok) {
                                CmsAdmin.confirmClose();
                                CmsAdmin.toast((r && r.error) || 'Could not remove that user.', 'error');
                                return;
                            }
                            CmsAdmin.confirmClose();
                            CmsAdmin.toast(rpersona + ' no longer has OGRE access.', 'success');
                            setTimeout(function () { window.location.reload(); }, 700);
                        })
                        .catch(function () {
                            CmsAdmin.confirmBusy(false);
                            CmsAdmin.confirmClose();
                            CmsAdmin.toast('Could not reach the server.', 'error');
                        });
                }
            });
        }
    });

    /* ---- Save ---- */
    saveBtn.addEventListener('click', function () {
        var mid = parseInt(midInp.value, 10) || 0;
        if (mid <= 0) {
            CmsAdmin.toast('Choose a person from the search results first.', 'error');
            searchInp.focus();
            return;
        }
        saveBtn.disabled = true;
        CmsAdmin.post('setrole', { mundane_id: mid, role: roleSel.value })
            .then(function (r) {
                saveBtn.disabled = false;
                if (!r || !r.ok) {
                    CmsAdmin.toast((r && r.error) || 'Could not save that role.', 'error');
                    return;
                }
                CmsAdmin.modal.close(modal);
                CmsAdmin.toast('Saved — ' + r.role_label + '.', 'success');
                setTimeout(function () { window.location.reload(); }, 700);
            })
            .catch(function () {
                saveBtn.disabled = false;
                CmsAdmin.toast('Could not reach the server.', 'error');
            });
    });

    /* ---- Site-availability toggles ---- */
    document.querySelectorAll('.cms-policy-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var key = cb.getAttribute('data-policy-key');
            var on = cb.checked;
            cb.disabled = true;
            CmsAdmin.post('sitepolicy', { key: key, on: on ? '1' : '0' })
                .then(function (r) {
                    cb.disabled = false;
                    if (!r || !r.ok) {
                        cb.checked = !on;               // server refused — put it back
                        CmsAdmin.toast((r && r.error) || 'Could not save that setting.', 'error');
                        return;
                    }
                    CmsAdmin.toast('Saved.', 'success');
                    // Turning the global park switch OFF removes the kingdom-level
                    // control's reason to exist, and turning it ON reveals it, so
                    // reload rather than leave the page describing a stale chain.
                    if (key === 'CmsParkSitesEnabled') {
                        setTimeout(function () { window.location.reload(); }, 600);
                    }
                })
                .catch(function () {
                    cb.disabled = false;
                    cb.checked = !on;
                    CmsAdmin.toast('Could not reach the server.', 'error');
                });
        });
    });

    explainRole();
})();
</script>
