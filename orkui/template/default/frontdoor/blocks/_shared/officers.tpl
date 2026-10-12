<?php
/**
 * Shared partial: _shared/officers.tpl — the common body of the two DYNAMIC
 * officer-roster blocks, kingdom_officers.tpl and park_officers.tpl.
 *
 * Those two were byte-identical apart from six values (scope kind, cache
 * namespace, cache-key prefix, model class, officer argument key, and the
 * variable prefix). Everything else — the scope gate, the GhettoCache
 * hydrate/store and the entire markup — lived twice. It now lives here once;
 * the two block files are thin adapters.
 *
 * The subdirectory is a security property, not a stylistic one: render_blocks.tpl
 * dispatches on `blocks/{type}.tpl` after stripping everything but [a-z_] from the
 * block type, so `_shared/officers.tpl` is unreachable while a sibling
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
 * Public view only — this file NEVER decides who may see what. The whole
 * public-visibility policy (anonymous projection, vacant seats, restricted
 * players, role labels, avatar resolution) lives in the libs, behind
 * Kingdom::GetPublicOfficers / Park::GetPublicOfficers.
 */
// Dropped on a page outside this block's scope (global front door, or the other
// org kind) → no single org to source. Render nothing at all rather than a
// broken or misleading empty box. (See fdScopedOrgId() in _helpers.tpl.)
$fdOffOrgId = fdScopedOrgId($SiteNavScopeType ?? '', $SiteNavScopeId ?? 0, $fdOffScopeKind);
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

// This DYNAMIC block runs on every anonymous public hit, so it must not do an
// N+1 — one GetHeraldryUrl call PER officer — inside the render loop below, on
// top of the GetOfficers query. The lib resolves the roster AND every
// avatar in ONE pass (Kingdom/Park::GetPublicOfficers), and the fully-hydrated
// result is cached in GhettoCache keyed by (org, limit). Public officer data is
// safe to share across viewers; a short TTL keeps it fresh. Cached hits render
// with ZERO model calls.
// The namespace, key format and limit clamp all come from CmsRenderCache, which
// CmsAjax::clearrendercache enumerates to flush this exact key space on demand —
// so an officer change can be pushed live without waiting the TTL out, and the
// two sides cannot drift apart into a flush that busts nothing.
// $fdOffResolved: list of ['persona','role','mundane_id','avatar'].
// $fdOffFailed: set by the builder when it could not source the roster at all.
// A genuinely empty roster is worth caching; a transient model/DB failure is NOT
// — storing it would pin "Officer roster coming soon." on every anonymous hit
// for the whole TTL, long after the underlying problem cleared. The storeIf gate
// below therefore refuses to write a failed build, so the next hit retries.
$fdOffFailed   = false;
$fdOffResolved = fdBlockCache(
    $fdOffCacheNs,
    CmsRenderCache::OfficersKey($fdOffKeyPrefix, $fdOffOrgId, $fdOffLimit),
    CmsRenderCache::TTL,
    function () use ($fdOffOrgId, $fdOffLimit, $fdOffModelClass, $fdOffArgKey, &$fdOffFailed) {
        if (!class_exists('APIModel')) {
            $fdOffFailed = true;
            return [];
        }
        try {
            // No Token argument: GetPublicOfficers IS the public roster — the lib
            // owns the anonymous projection, the vacancy rule, the restricted-player
            // rule, the role labels and the avatar URL.
            $fdOffModel = new APIModel($fdOffModelClass);
            $fdOffRows  = $fdOffModel->GetPublicOfficers([$fdOffArgKey => (int) $fdOffOrgId, 'Limit' => $fdOffLimit]);
        } catch (\Throwable $e) {
            // A model/lib failure must not 500 the whole page — render nothing.
            $fdOffFailed = true;
            return [];
        }
        return is_array($fdOffRows) ? $fdOffRows : [];
    },
    // Belt and braces, deliberately. CmsRenderCache::Remember ANDs this gate with
    // its own empty-payload gate (CmsRenderCache::Remember), but re-asserting
    // the empty check here keeps this block correct even if that default changes:
    // the $fdOffFailed flag alone is NOT enough, because PDO runs in
    // ERRMODE_WARNING (Yapo2\YapoMysql) — a transient DB blip makes the query
    // return false with NO Throwable, the lib hands back an empty list, and the
    // flag stays false. Refusing to store an empty payload too costs one model
    // call per hit for a genuinely officer-less org, and keeps a blip from
    // pinning "Officer roster coming soon." for the whole TTL.
    function ($fdOffBuilt) use (&$fdOffFailed) {
        return !$fdOffFailed && is_array($fdOffBuilt) && $fdOffBuilt !== [];
    }
);
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
                $fdOffNameOut   = htmlspecialchars(stripslashes($fdOffPersona !== '' ? $fdOffPersona : 'Officer'), ENT_QUOTES);
                $fdOffRoleOut   = htmlspecialchars(stripslashes($fdOffRole), ENT_QUOTES);
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
