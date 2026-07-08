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

// C5: this DYNAMIC block runs on every anonymous public hit and previously did
// an N+1 — one GetHeraldryUrl call PER officer — inside the render loop below,
// on top of the GetOfficers query. Resolve the roster AND every avatar up front
// in ONE pass, then cache the fully-hydrated result in GhettoCache keyed by
// (kingdom, limit). Public officer data (Token '') is safe to share across
// viewers; a short TTL keeps it fresh. Cached hits render with ZERO model calls.
// $koResolved: list of ['persona','role','mundane_id','avatar'].
$koResolved = null;
$koCache    = (isset(Ork3::$Lib) && is_object(Ork3::$Lib) && isset(Ork3::$Lib->ghettocache) && is_object(Ork3::$Lib->ghettocache))
    ? Ork3::$Lib->ghettocache : null;
$koCacheKey = 'k' . $koKingdomId . '.l' . $koLimit;
if ($koCache !== null) {
    $koHit = $koCache->get('frontdoor.kingdom_officers', $koCacheKey, 300);
    if (is_array($koHit)) {
        $koResolved = $koHit;
    }
}

if ($koResolved === null) {
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

    // Optional per-officer avatar from PLAYER heraldry (hidden on load error).
    // Guarded: a construction failure must not 500 the whole page (the block
    // degrades to icon avatars).
    try {
        $koHeraldry = class_exists('APIModel') ? new APIModel('Heraldry') : null;
    } catch (\Throwable $e) {
        $koHeraldry = null;
    }

    $koResolved = [];
    foreach ($koRows as $koRow) {
        $koPersona = trim((string) ($koRow['Persona'] ?? ''));
        $koRole    = trim((string) ($koRow['OfficerRole'] ?? $koRow['Role'] ?? ''));
        if ($koPersona === '' && $koRole === '') {
            continue;
        }
        $koMundaneId = (int) ($koRow['MundaneId'] ?? 0);
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
        $koResolved[] = [
            'persona'    => $koPersona,
            'role'       => $koRole,
            'mundane_id' => $koMundaneId,
            'avatar'     => $koAvatarUrl,
        ];
    }

    if ($koCache !== null) {
        $koCache->cache('frontdoor.kingdom_officers', $koCacheKey, $koResolved);
    }
}

// Role label normalization: prod stores capitalized ENUM roles, but the shared
// local DB was migrated to lowercase canonical keys — match case-insensitively.
$koRoleLabels = [
    'monarch'        => 'Monarch',
    'regent'         => 'Regent',
    'prime minister' => 'Prime Minister',
    'champion'       => 'Champion',
    'gmr'            => 'GMR',
];
?>
<?php // Static .ko-* CSS lives in frontdoor.css (loaded under orgsite.css on org
      // sites) — no per-render inline <style>. ?>
<div class="fd-pad fd-section-light ko-block">
    <div class="ko-head">
        <?php if ($koKicker !== ''): ?>
            <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($koKicker, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if ($koHeading !== ''): ?>
            <h2 class="ko-title fd-sec-title"><?= htmlspecialchars($koHeading, ENT_QUOTES) ?></h2>
        <?php endif; ?>
    </div>

    <?php if (empty($koResolved)): ?>
        <div class="ko-empty">Officer roster coming soon.</div>
    <?php else: ?>
        <div class="ko-grid">
            <?php foreach ($koResolved as $koRow): ?>
                <?php
                $koPersona   = (string) ($koRow['persona'] ?? '');
                $koRole      = (string) ($koRow['role'] ?? '');
                $koAvatarUrl = (string) ($koRow['avatar'] ?? '');
                $koRoleLabel = $koRoleLabels[strtolower($koRole)] ?? $koRole;
                $koNameOut   = htmlspecialchars(stripslashes($koPersona !== '' ? $koPersona : 'Officer'), ENT_QUOTES);
                $koRoleOut   = htmlspecialchars(stripslashes($koRoleLabel), ENT_QUOTES);
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
