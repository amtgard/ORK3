<?php
/**
 * Partial: kingdom_officers.tpl — DYNAMIC block (org-scoped).
 *
 * Shows the CURRENT ORK officers (kingdom seats) for the site's owning kingdom.
 * Pairs with the authored `staff_roster` block, which covers the Board of
 * Directors / non-ORK roles — this block is the live half sourced from ORK data.
 *
 * Self-sourcing like blog_feed.tpl: no controller injects officers onto arbitrary
 * site pages, so this partial reads them itself via the Kingdom lib
 * (new APIModel('Kingdom') → Kingdom::GetOfficers). Public view (Token '') exposes
 * only Persona — real given/surnames are suppressed by the lib.
 *
 * Scope: derives kingdom_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * kingdom scope (global front door / park / unit sites) — never errors, never fatals.
 *
 * Receives: $blockFields { kicker?, heading?, limit? }, UIR, $SiteNavScope*.
 */
$koScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$koScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$koKingdomId = ($koScopeType === 'kingdom') ? $koScopeId : 0;

// Dropped on a non-kingdom / global page → no single kingdom to source. Render
// nothing at all rather than a broken or misleading empty box.
if ($koKingdomId <= 0) {
    return;
}

$koKicker  = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$koHeading = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Our Officers';
$koLimit   = isset($blockFields['limit']) ? (int) $blockFields['limit'] : 12;
if ($koLimit < 1) {
    $koLimit = 12;
}
if ($koLimit > 24) {
    $koLimit = 24;
}

$koRows = [];
if (class_exists('APIModel')) {
    try {
        $koModel  = new APIModel('Kingdom');
        // Token '' → public view: only Persona is exposed (real names suppressed).
        $koResult = $koModel->GetOfficers(['KingdomId' => $koKingdomId, 'Token' => '']);
        if (is_array($koResult) && isset($koResult['Officers']) && is_array($koResult['Officers'])) {
            $koRows = $koResult['Officers'];
        }
    } catch (\Throwable $e) {
        $koRows = [];
    }
}
$koRows = array_slice($koRows, 0, $koLimit);

// Role label normalization: prod stores capitalized ENUM roles, but the shared
// local DB was migrated to lowercase canonical keys — match case-insensitively.
$koRoleLabels = [
    'monarch'        => 'Monarch',
    'regent'         => 'Regent',
    'prime minister' => 'Prime Minister',
    'champion'       => 'Champion',
    'gmr'            => 'GMR',
];

// Optional per-officer avatar from PLAYER heraldry (hidden on load error).
// Guarded: a construction failure must not 500 the whole page (the block
// degrades to icon avatars).
try {
    $koHeraldry = class_exists('APIModel') ? new APIModel('Heraldry') : null;
} catch (\Throwable $e) {
    $koHeraldry = null;
}
?>
<style>
.ko-block { background: var(--fd-bg); }
.ko-head { margin-bottom: 18px; }
.ko-title { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0; font-size: 24px; }
.ko-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.ko-card {
    background: var(--fd-bg); border: 1px solid #e4e8f0; border-radius: 10px;
    padding: 18px 14px; text-align: center;
}
.ko-avatar {
    width: 72px; height: 72px; margin: 0 auto 10px; border-radius: 50%;
    background: #eef1f6; border: 2px solid var(--gold, #d4af37); overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.ko-avatar img { width: 100%; height: 100%; object-fit: cover; }
.ko-avatar i { font-size: 28px; color: #b8bfce; }
.ko-role { font-size: 12px; color: #b8860b; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.ko-name { font-weight: 700; font-size: 15px; margin-top: 4px; color: var(--fd-text); }
.ko-empty { color: #8899aa; font-style: italic; text-align: center; padding: 18px; }

@media (max-width: 900px) { .ko-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 520px) { .ko-grid { grid-template-columns: 1fr; } }

html[data-theme="dark"] .ko-block { background: transparent; }
html[data-theme="dark"] .ko-card { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .ko-avatar { background: #232c42; }
html[data-theme="dark"] .ko-name { color: #eef2fa; }
html[data-theme="dark"] .ko-avatar i { color: #7d8aa5; }
</style>
<div class="fd-pad fd-section-light ko-block" style="background:#fff;">
    <div class="ko-head">
        <?php if ($koKicker !== ''): ?>
            <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($koKicker, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if ($koHeading !== ''): ?>
            <h3 class="ko-title fd-sec-title"><?= htmlspecialchars($koHeading, ENT_QUOTES) ?></h3>
        <?php endif; ?>
    </div>

    <?php if (empty($koRows)): ?>
        <div class="ko-empty">Officer roster coming soon.</div>
    <?php else: ?>
        <div class="ko-grid">
            <?php foreach ($koRows as $koRow): ?>
                <?php
                $koPersona = trim((string) ($koRow['Persona'] ?? ''));
                $koRole    = trim((string) ($koRow['OfficerRole'] ?? $koRow['Role'] ?? ''));
                if ($koPersona === '' && $koRole === '') {
                    continue;
                }
                $koMundaneId = (int) ($koRow['MundaneId'] ?? 0);
                $koRoleLabel = $koRoleLabels[strtolower($koRole)] ?? $koRole;
                $koNameOut   = htmlspecialchars(stripslashes($koPersona !== '' ? $koPersona : 'Officer'), ENT_QUOTES);
                $koRoleOut   = htmlspecialchars(stripslashes($koRoleLabel), ENT_QUOTES);

                $koAvatarUrl = '';
                if ($koHeraldry !== null && $koMundaneId > 0) {
                    try {
                        $koH = $koHeraldry->GetHeraldryUrl(['Type' => 'Player', 'Id' => $koMundaneId]);
                        if (is_array($koH) && !empty($koH['Url'])) {
                            $koAvatarUrl = (string) $koH['Url'];
                        }
                    } catch (\Throwable $e) {
                        $koAvatarUrl = '';
                    }
                }
                ?>
                <div class="ko-card">
                    <div class="ko-avatar">
                        <?php // GetHeraldryUrl always returns a path (no has-heraldry
                        // signal), so show the shield icon by default and reveal the
                        // image only if it actually loads — a 404 leaves the icon in
                        // place with no broken-image flash. ?>
                        <i class="fas fa-user-shield"></i>
                        <?php if ($koAvatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($koAvatarUrl, ENT_QUOTES) ?>"
                                 style="display:none;"
                                 onload="this.style.display='';this.parentNode.querySelector('i').style.display='none';"
                                 alt="<?= $koNameOut ?>">
                        <?php endif; ?>
                    </div>
                    <?php if ($koRoleOut !== ''): ?>
                        <div class="ko-role"><?= $koRoleOut ?></div>
                    <?php endif; ?>
                    <div class="ko-name"><?= $koNameOut ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
