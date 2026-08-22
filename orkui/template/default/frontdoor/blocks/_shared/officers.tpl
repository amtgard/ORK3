<?php
/**
 * Shared partial: _shared/officers.tpl — the common body of the two DYNAMIC
 * officer-roster blocks, kingdom_officers.tpl and park_officers.tpl.
 *
 * Those two were byte-identical apart from six values (scope kind, cache
 * namespace, cache-key prefix, model class, officer argument key, and the
 * variable prefix). Everything else — the scope gate, the GhettoCache
 * hydrate/store, the avatar N+1 fix, the role-label map and the entire markup —
 * lived twice. It now lives here once; the two block files are thin adapters.
 *
 * WHY THIS IS IN A SUBDIRECTORY, and why the adapters keep their exact
 * filenames: render_blocks.tpl:39-41 dispatches on `blocks/{type}.tpl` after
 * running the block type through preg_replace('/[^a-z_]/', '', ...). That
 * sanitizer strips '/' and '.', so no authored block type can ever resolve to
 * `_shared/officers.tpl`. Putting the shared body in a subdirectory is therefore
 * a security property, not a stylistic one — a sibling file named
 * `blocks/officers.tpl` WOULD be reachable as a block type named "officers".
 *
 * Caller (adapter) must set, before including:
 *   $fdOffScopeKind   'kingdom'|'park' — the site scope this block requires
 *   $fdOffCacheNs     GhettoCache namespace — pass the CmsRenderCache::NS_*
 *                     constant, never a literal (the key space is an external
 *                     contract enumerated by CmsAjax::clearrendercache)
 *   $fdOffKeyPrefix   cache-key org prefix, CmsRenderCache::PREFIX_KINGDOM|PARK
 *   $fdOffModelClass  APIModel class to source officers from ('Kingdom'|'Park')
 *   $fdOffArgKey      that class's id argument name ('KingdomId'|'ParkId')
 *
 * Also receives, from render_blocks.tpl: $blockFields { kicker?, heading?,
 * limit? }, UIR, $SiteNavScopeType / $SiteNavScopeId.
 *
 * Public view only (Token '') — the libs suppress real given/surnames for
 * unauthenticated callers, so only Persona is ever exposed here.
 */
$fdOffScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$fdOffScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$fdOffOrgId     = ($fdOffScopeType === $fdOffScopeKind) ? $fdOffScopeId : 0;

// Dropped on a page outside this block's scope (global front door, or the other
// org kind) → no single org to source. Render nothing at all rather than a
// broken or misleading empty box.
if ($fdOffOrgId <= 0) {
    return;
}

$fdOffKicker  = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$fdOffHeading = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Our Officers';
$fdOffLimit   = fdClampLimit(
    $blockFields['limit'] ?? null,
    CmsRenderCache::OFFICERS_LIMIT_DEFAULT,
    CmsRenderCache::OFFICERS_LIMIT_MAX
);

// C5: this DYNAMIC block runs on every anonymous public hit and previously did
// an N+1 — one GetHeraldryUrl call PER officer — inside the render loop below,
// on top of the GetOfficers query. Resolve the roster AND every avatar up front
// in ONE pass, then cache the fully-hydrated result in GhettoCache keyed by
// (org, limit). Public officer data (Token '') is safe to share across viewers;
// a short TTL keeps it fresh. Cached hits render with ZERO model calls.
// The namespace, key format and limit clamp all come from CmsRenderCache, which
// CmsAjax::clearrendercache enumerates to flush this exact key space on demand —
// so an officer change can be pushed live without waiting the TTL out, and the
// two sides cannot drift apart into a flush that busts nothing.
// $fdOffResolved: list of ['persona','role','mundane_id','avatar'].
$fdOffResolved = fdBlockCache(
    $fdOffCacheNs,
    CmsRenderCache::OfficersKey($fdOffKeyPrefix, $fdOffOrgId, $fdOffLimit),
    CmsRenderCache::TTL,
    function () use ($fdOffOrgId, $fdOffLimit, $fdOffModelClass, $fdOffArgKey) {
        $fdOffRows = [];
        if (class_exists('APIModel')) {
            try {
                $fdOffModel = new APIModel($fdOffModelClass);
                // Token '' → public view: only Persona is exposed (real names suppressed).
                // The id is int-cast here as well as in the lib: Park::GetOfficers
                // interpolates it into SQL through mysql_real_escape_string(), which is
                // a no-op shim in this codebase.
                $fdOffResult = $fdOffModel->GetOfficers([$fdOffArgKey => (int) $fdOffOrgId, 'Token' => '']);
                if (is_array($fdOffResult) && isset($fdOffResult['Officers']) && is_array($fdOffResult['Officers'])) {
                    $fdOffRows = $fdOffResult['Officers'];
                }
            } catch (\Throwable $e) {
                $fdOffRows = [];
            }
        }
        $fdOffRows = array_slice($fdOffRows, 0, $fdOffLimit);

        // Optional per-officer avatar from PLAYER heraldry (hidden on load error).
        // Guarded: a construction failure must not 500 the whole page (the block
        // degrades to icon avatars).
        try {
            $fdOffHeraldry = class_exists('APIModel') ? new APIModel('Heraldry') : null;
        } catch (\Throwable $e) {
            $fdOffHeraldry = null;
        }

        $fdOffResolved = [];
        foreach ($fdOffRows as $fdOffRow) {
            $fdOffPersona = trim((string) ($fdOffRow['Persona'] ?? ''));
            $fdOffRole    = trim((string) ($fdOffRow['OfficerRole'] ?? $fdOffRow['Role'] ?? ''));
            // A seat with no PERSONA is a vacancy, whatever its role says. ork_officer
            // keeps a row per office with mundane_id = 0 when nobody holds it, and the
            // LEFT JOIN to ork_mundane then yields a NULL persona while `role` stays
            // populated ("Champion", "GMR", …). Requiring BOTH to be empty rendered
            // those vacancies as cards with an office title and nobody in them, on 187
            // of 342 active parks. A name is required; an office title is not.
            if ($fdOffPersona === '') {
                continue;
            }
            $fdOffMundaneId = (int) ($fdOffRow['MundaneId'] ?? 0);
            $fdOffAvatarUrl = '';
            if ($fdOffHeraldry !== null && $fdOffMundaneId > 0) {
                try {
                    $fdOffH = $fdOffHeraldry->GetHeraldryUrl(['Type' => 'Player', 'Id' => $fdOffMundaneId]);
                    if (is_array($fdOffH) && !empty($fdOffH['Url'])) {
                        $fdOffAvatarUrl = (string) $fdOffH['Url'];
                    }
                } catch (\Throwable $e) {
                    $fdOffAvatarUrl = '';
                }
            }
            $fdOffResolved[] = [
                'persona'    => $fdOffPersona,
                'role'       => $fdOffRole,
                'mundane_id' => $fdOffMundaneId,
                'avatar'     => $fdOffAvatarUrl,
            ];
        }
        return $fdOffResolved;
    }
);

// Role label normalization: prod stores capitalized ENUM roles, but the shared
// local DB was migrated to lowercase canonical keys — match case-insensitively.
// ork_officer.role carries the same canonical set at park level as at kingdom
// level, which is what every other ORK surface displays for a park's officers.
$fdOffRoleLabels = [
    'monarch'        => 'Monarch',
    'regent'         => 'Regent',
    'prime minister' => 'Prime Minister',
    'champion'       => 'Champion',
    'gmr'            => 'GMR',
];
?>
<?php // Static .ko-* CSS lives in frontdoor/css/blocks.css (loaded on every CMS
      // surface, org sites included) — one card grid shared by both blocks, and no
      // inline style element. ?>
<div class="fd-pad fd-section-light ko-block">
    <div class="ko-head">
        <?php if ($fdOffKicker !== ''): ?>
            <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($fdOffKicker, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if ($fdOffHeading !== ''): ?>
            <h2 class="ko-title fd-sec-title"><?= htmlspecialchars($fdOffHeading, ENT_QUOTES) ?></h2>
        <?php endif; ?>
    </div>

    <?php if (empty($fdOffResolved)): ?>
        <div class="ko-empty">Officer roster coming soon.</div>
    <?php else: ?>
        <div class="ko-grid">
            <?php foreach ($fdOffResolved as $fdOffRow): ?>
                <?php
                $fdOffPersona   = (string) ($fdOffRow['persona'] ?? '');
                $fdOffRole      = (string) ($fdOffRow['role'] ?? '');
                $fdOffAvatarUrl = (string) ($fdOffRow['avatar'] ?? '');
                $fdOffRoleLabel = $fdOffRoleLabels[strtolower($fdOffRole)] ?? $fdOffRole;
                $fdOffNameOut   = htmlspecialchars(stripslashes($fdOffPersona !== '' ? $fdOffPersona : 'Officer'), ENT_QUOTES);
                $fdOffRoleOut   = htmlspecialchars(stripslashes($fdOffRoleLabel), ENT_QUOTES);
                ?>
                <div class="ko-card">
                    <div class="ko-avatar">
                        <?php // GetHeraldryUrl always returns a path (no has-heraldry
                        // signal), so show the shield icon by default and reveal the
                        // image only if it actually loads — a 404 leaves the icon in
                        // place with no broken-image flash. ?>
                        <i class="fas fa-user-shield"></i>
                        <?php if ($fdOffAvatarUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($fdOffAvatarUrl, ENT_QUOTES) ?>"
                                 style="display:none;"
                                 onload="this.style.display='';this.parentNode.querySelector('i').style.display='none';"
                                 alt="">
                        <?php endif; ?>
                    </div>
                    <?php if ($fdOffRoleOut !== ''): ?>
                        <div class="ko-role"><?= $fdOffRoleOut ?></div>
                    <?php endif; ?>
                    <div class="ko-name"><?= $fdOffNameOut ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
