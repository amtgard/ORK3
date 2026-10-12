<?php
/**
 * Partial: kingdom_parks.tpl — DYNAMIC block (org-scoped).
 *
 * Shows the ACTIVE parks for the site's owning kingdom as cards — optional
 * heraldry crest, park name, rank/title badge, and city/state — deep-linked to
 * each park's public profile. Sortable by park name, city, or state.
 *
 * Self-sourcing like blog_feed.tpl: reads parks itself via the Kingdom lib
 * (new APIModel('Kingdom') → Kingdom::GetActiveParks), which owns the active
 * filter, the sort modes, the row cap and the heraldry-URL resolve; this partial
 * only formats the rows it gets back.
 *
 * Scope: derives kingdom_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * kingdom scope (global front door / park / unit sites) — never errors, never fatals.
 *
 * Receives: $blockFields { kicker?, heading?, sort?, show_heraldry?, limit?,
 * more_href? }, UIR, $SiteNavScope*.
 */
$kpScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$kpScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$kpKingdomId = ($kpScopeType === 'kingdom') ? $kpScopeId : 0;

// Dropped on a non-kingdom / global page → no single kingdom to source. Render
// nothing at all rather than a broken or misleading empty box.
if ($kpKingdomId <= 0) {
    return;
}

$kpKicker   = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$kpHeading  = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Our Parks';
$kpLimit    = fdClampLimit(
    $blockFields['limit'] ?? null,
    CmsRenderCache::PARKS_LIMIT_DEFAULT,
    CmsRenderCache::PARKS_LIMIT_MAX
);
// The sort set is part of this block's cache-key space, so it comes from the
// same registry CmsAjax::clearrendercache enumerates.
$kpSort = isset($blockFields['sort']) ? (string) $blockFields['sort'] : 'name';
if (!in_array($kpSort, CmsRenderCache::PARKS_SORTS, true)) {
    $kpSort = 'name';
}
$kpShowHeraldry = !empty($blockFields['show_heraldry'])
    && (string) $blockFields['show_heraldry'] !== '0'
    && (string) $blockFields['show_heraldry'] !== 'false';
// Opt-in: fold the kingdom's principalities' parks into the list (the map block
// already plots them). Absent field → 0, so existing blocks are unchanged.
$kpIncludeChildren = !empty($blockFields['include_child_kingdoms'])
    && (string) $blockFields['include_child_kingdoms'] !== '0'
    && (string) $blockFields['include_child_kingdoms'] !== 'false'
    ? 1 : 0;
// The seeded teaser on a kingdom home page points "All parks" at this site's own
// Parks page in the stable 'Page/view/{slug}' form (CmsSite::_sitePageHref), which
// must be re-pointed onto the site's CURRENT /Site/page/{slug}/ route at render
// time — the global page route resolves global pages only, and would 404. Same
// one-line resolve steps.tpl does; see fdSiteInternalHref() in _helpers.tpl.
$kpMoreHref = isset($blockFields['more_href'])
    ? trim(fdSiteInternalHref((string) $blockFields['more_href'], isset($SiteSlug) ? (string) $SiteSlug : ''))
    : '';
if ($kpMoreHref === '#') {
    // Blank URL fields are rewritten to '#' by the save sanitizer — treat as unset.
    $kpMoreHref = '';
}

// Cached like kingdom_officers.tpl: this DYNAMIC block runs on
// every anonymous public hit and previously re-queried GetParks AND did a per-row
// file_exists() heraldry probe inside the render loop. Ask the lib for the
// resolved list (active-filtered, sorted, sliced, crests resolved) once, then
// cache that fully-hydrated result in GhettoCache keyed by (kingdom, limit, sort,
// show_heraldry). Public park data is safe to share across viewers; a short TTL
// keeps it fresh. Cached hits render with ZERO model calls and ZERO disk probes.
// $kpResolved: list of ['park_id','name','loc','title','crest'].
$kpResolved = fdBlockCache(
    CmsRenderCache::NS_KINGDOM_PARKS,
    // The child-kingdom variant is a DIFFERENT result set, so it needs its own
    // key — but the suffix is ParksKey()'s job, not this file's. Appending it
    // here put the key outside the set CmsRenderCache::BustScope() enumerates,
    // and since the seeded Our Parks block turns the roll-up ON, that
    // unflushable key was the only one a new kingdom site ever wrote.
    CmsRenderCache::ParksKey($kpKingdomId, $kpLimit, $kpSort, $kpShowHeraldry, $kpIncludeChildren),
    CmsRenderCache::TTL,
    function () use ($kpKingdomId, $kpLimit, $kpSort, $kpShowHeraldry, $kpIncludeChildren) {
    // Which parks count as active, the three display orders, the row cap and the
    // crest-URL resolve all live in Kingdom::GetActiveParks — this block only
    // renders what it hands back.
    if (!class_exists('APIModel')) {
        return [];
    }
    try {
        $kpModel = new APIModel('Kingdom');
        $kpRows  = $kpModel->GetActiveParks([
            'KingdomId'    => $kpKingdomId,
            'Sort'         => $kpSort,
            'Limit'        => $kpLimit,
            'WithHeraldry' => $kpShowHeraldry,
            'IncludeChildKingdoms' => $kpIncludeChildren,
        ]);
    } catch (\Throwable $e) {
        return [];
    }

    return is_array($kpRows) ? $kpRows : [];
    }
);

// The lib slices to $kpLimit, so a full page of rows probably means there are
// more parks we are not showing — while the kingdom_parks_map block above plots
// ALL of them. Never truncate silently: when the list is full, offer somewhere
// else to look. "Probably" is as far as we can honestly go — the lib returns no
// total, so a full page may also be an exact fit; the wording therefore claims
// no truncation, and matches the "find another group" phrasing the park starter
// already uses for this same Atlas destination. Suppressed when the block
// authors their own more_href, because the header already renders that link.
$kpAtlasLink = ($kpMoreHref === '') && (count($kpResolved) >= $kpLimit);
?>
<div class="fd-pad fd-section-light kp-block">
    <div class="kp-head">
        <div>
            <?php if ($kpKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($kpKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($kpHeading !== ''): ?>
                <h2 class="kp-title fd-sec-title"><?= htmlspecialchars($kpHeading, ENT_QUOTES) ?></h2>
            <?php endif; ?>
        </div>
        <?php if ($kpMoreHref !== ''): ?>
            <a class="kp-more" href="<?= htmlspecialchars($kpMoreHref, ENT_QUOTES) ?>">All parks &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($kpResolved)): ?>
        <div class="kp-empty">No active parks to show yet.</div>
    <?php else: ?>
        <div class="kp-grid">
            <?php foreach ($kpResolved as $kpRow): ?>
                <?php
                $kpParkId   = (int) ($kpRow['park_id'] ?? 0);
                $kpNameOut  = htmlspecialchars(stripslashes((string) ($kpRow['name'] ?? '')), ENT_QUOTES);
                $kpLocOut   = htmlspecialchars(stripslashes((string) ($kpRow['loc'] ?? '')), ENT_QUOTES);
                $kpTitleOut = htmlspecialchars(stripslashes((string) ($kpRow['title'] ?? '')), ENT_QUOTES);
                $kpHref     = UIR . 'Park/profile/' . $kpParkId;
                $kpCrest    = (string) ($kpRow['crest'] ?? '');
                ?>
                <a class="kp-card" href="<?= htmlspecialchars($kpHref, ENT_QUOTES) ?>">
                    <div class="kp-card-accent"></div>
                    <div class="kp-card-body">
                        <?php if ($kpShowHeraldry): ?>
                            <div class="kp-crest">
                                <?php // Show the crest by default; if the image 404s, fall back to the shield icon. ?>
                                <?php if ($kpCrest !== ''): ?>
                                    <img src="<?= htmlspecialchars($kpCrest, ENT_QUOTES) ?>" alt="<?= $kpNameOut ?> heraldry"
                                         onerror="this.style.display='none';this.parentNode.querySelector('i').style.display='';">
                                    <i class="fas fa-shield-alt" style="display:none;"></i>
                                <?php else: ?>
                                    <i class="fas fa-shield-alt"></i>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="kp-card-main">
                            <div class="kp-card-name"><?= $kpNameOut ?></div>
                            <?php if ($kpTitleOut !== ''): ?>
                                <div class="kp-badge"><?= $kpTitleOut ?></div>
                            <?php endif; ?>
                            <?php if ($kpLocOut !== ''): ?>
                                <div class="kp-card-loc"><i class="fas fa-map-marker-alt"></i><?= $kpLocOut ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($kpAtlasLink): ?>
            <div class="kp-more-foot">
                <a class="kp-more" href="<?= htmlspecialchars(UIR . 'Atlas', ENT_QUOTES) ?>">Find another Amtgard group &rarr;</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
