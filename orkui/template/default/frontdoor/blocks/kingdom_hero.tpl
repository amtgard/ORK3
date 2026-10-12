<?php
/**
 * Partial: kingdom_hero.tpl — DYNAMIC block (kingdom scope only).
 *
 * The crest hero, one level up from park_hero.tpl. Same argument, same frame: a
 * kingdom cannot lean on photography either — it has a device and a colour, and
 * a banner photograph worth putting behind a headline is the exception — so the
 * anchor is the device itself, framed hard enough that the FRAME reads as the
 * design decision. It is built to look FINISHED with no photo, and with no
 * device at all it degrades to a monogram: this partial NEVER emits an <img>
 * with an empty src.
 *
 * Renders NOTHING outside kingdom scope (same contract as kingdom_parks /
 * kingdom_officers): on the global front door or a park site there is no single
 * org to source, so it returns before it emits anything.
 *
 * Receives: $blockFields {kicker, heading, tagline, cta_label, cta_href},
 *           $SiteNavScope*, $SiteSlug, UIR. This list is the contract the editor
 *           schema in cms-block-editor.js mirrors — a field declared in one
 *           place and not the other is either uneditable or unpublishable.
 *
 * DELIBERATELY UNCACHED, unlike park_hero. Its two lookups are the kingdom's own
 * row and its heraldry path; the cache namespaces, their key builders and the
 * per-scope bust lists all live together in CmsRenderCache (NS_*, KingdomKeys(),
 * BustScope()), and a namespace added without a matching entry in KingdomKeys()
 * would survive CmsAjax::clearrendercache — pinning a stale crest for a kingdom
 * that had just changed its device. A cheap read every time beats a wrong one
 * for half an hour.
 */
$khKingdomId = fdScopedOrgId($SiteNavScopeType ?? '', $SiteNavScopeId ?? 0, 'kingdom');
if ($khKingdomId <= 0) {
    return;
}

// Through APIModel like every other org-scoped block here — a template never
// talks to $DB, and the SQL stays in Kingdom::GetKingdomShortInfo().
$khInfo = array();
try {
    if (class_exists('APIModel')) {
        $khShort = (new APIModel('Kingdom'))->GetKingdomShortInfo(array('KingdomId' => $khKingdomId));
        if (is_array($khShort) && !empty($khShort['KingdomInfo']) && is_array($khShort['KingdomInfo'])) {
            $khInfo = $khShort['KingdomInfo'];
        }
    }
} catch (\Throwable $e) {
    $khInfo = array();
}

$khName = trim(stripslashes((string) ($khInfo['KingdomName'] ?? '')));

// --- The seal -------------------------------------------------------------
// Gate on HasHeraldry, NEVER on a truthy URL: resolve_heraldry_url() returns a
// guaranteed-404 path when no file exists, so a URL check always looks positive
// — and an <img> pointed at it is the broken image this block must never show.
// Same resolve path the theme seed uses (CmsSite::_heraldryPath gates on the
// same column, and Heraldry owns the filename).
$khDeviceUrl = '';
$khIsCut     = false;
if (!empty($khInfo['HasHeraldry'])) {
    try {
        $khH = (new APIModel('Heraldry'))->GetHeraldryUrl(array('Type' => 'Kingdom', 'Id' => $khKingdomId));
        if (is_array($khH) && !empty($khH['Url'])) {
            $khDeviceUrl = (string) $khH['Url'];
            // A .jpg is opaque, so its own background BECOMES the plate when
            // cover-cropped to the disc. A .png was written with alpha and its
            // transparent margin already trimmed, so it floats, matted.
            $khIsCut = (bool) preg_match('/\.jpe?g(\?|$)/i', $khDeviceUrl);
        }
    } catch (\Throwable $e) {
        $khDeviceUrl = '';
    }
}

// Eyebrow states the org's real rank — Amtgard terminology doing real work.
// A principality is not a kingdom and must not be told it is one (the same
// distinction CmsSite::OrgUnitNoun() enforces in the seeded copy).
$khEyebrow = trim((string) ($blockFields['kicker'] ?? ''));
if ($khEyebrow === '') {
    $khEyebrow = (!empty($khInfo['IsPrincipality']) ? 'Principality' : 'Kingdom') . ' of Amtgard';
}
$khHeading = trim((string) ($blockFields['heading'] ?? '')) ?: $khName;
$khTagline = trim((string) ($blockFields['tagline'] ?? ''));

// Monogram fallback: initials, not a generic placeholder crest, which would make
// every deviceless org look identical and unloved.
$khMonogram = '';
if ($khDeviceUrl === '') {
    foreach (array_slice(preg_split('/\s+/', $khName, -1, PREG_SPLIT_NO_EMPTY), 0, 3) as $khW) {
        $khMonogram .= mb_strtoupper(mb_substr($khW, 0, 1));
    }
    $khMonogram = mb_substr($khMonogram, 0, 3);
}

// The CTA is seeded as the STABLE 'Page/view/{slug}' form (CmsSite::_sitePageHref)
// so a site-slug rename cannot strand it — resolve it against the CURRENT slug
// here, at render time, exactly as steps.tpl does. See fdSiteInternalHref() in
// frontdoor/_helpers.tpl.
$khCtaLabel = trim((string) ($blockFields['cta_label'] ?? ''));
$khCtaHref  = fdSiteInternalHref(
    trim((string) ($blockFields['cta_href'] ?? '')),
    isset($SiteSlug) ? (string) $SiteSlug : ''
);

// Nothing resolved and nothing authored → render nothing rather than an empty
// coloured band. Matches the cta_band / rich_text pattern: silent for visitors,
// a hint for the author in preview.
if ($khHeading === '' && $khTagline === '' && $khCtaLabel === '' && $khDeviceUrl === '') {
    if (!empty($fdIsPreview)) {
        fdEmptyBlockNotice('This kingdom hero has no kingdom to show yet.');
    }
    return;
}
?>
<header class="kh-hero">
    <div class="kh-hero-field" aria-hidden="true"></div>
    <div class="kh-hero-inner">
        <div>
            <?php if ($khEyebrow !== ''): ?>
                <p class="kh-eyebrow"><?= htmlspecialchars($khEyebrow, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($khHeading !== ''): ?>
                <h1 class="kh-name"><?= htmlspecialchars($khHeading, ENT_QUOTES) ?></h1>
            <?php endif; ?>
            <?php if ($khTagline !== ''): ?>
                <p class="kh-tagline"><?= htmlspecialchars($khTagline, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($khCtaLabel !== ''): ?>
                <div class="kh-actions">
                    <a class="fd-btn-gold" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($khCtaHref), ENT_QUOTES) ?>"><?= htmlspecialchars($khCtaLabel, ENT_QUOTES) ?></a>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($khDeviceUrl !== ''): ?>
            <div class="kh-seal <?= $khIsCut ? 'is-cut' : 'is-matted' ?>">
                <img src="<?= htmlspecialchars($khDeviceUrl, ENT_QUOTES) ?>" alt="Arms of <?= htmlspecialchars($khName, ENT_QUOTES) ?>">
            </div>
        <?php elseif ($khMonogram !== ''): ?>
            <div class="kh-seal is-monogram" role="img" aria-label="<?= htmlspecialchars($khName, ENT_QUOTES) ?>"><?= htmlspecialchars($khMonogram, ENT_QUOTES) ?></div>
        <?php endif; ?>
    </div>
</header>
