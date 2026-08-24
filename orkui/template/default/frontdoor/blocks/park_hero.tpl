<?php
/**
 * Partial: park_hero.tpl — DYNAMIC block (park scope only).
 *
 * The crest hero. A park cannot lean on photography the way the global front door
 * does — 316 of 342 have a heraldry device and 5 have a banner photo — so the
 * anchor is the device itself, framed hard enough that the FRAME reads as the
 * design decision and the image reads as cargo. You cannot fix 342 images of
 * varying quality; you can frame them identically.
 *
 * Renders NOTHING outside park scope (same contract as park_meeting).
 *
 * Receives: $blockFields {kicker, heading, cta_label, cta_href, show_weather,
 *           placeholder_image}, $SiteNavScope*, UIR. This list is the contract
 *           the editor schema in cms-block-editor.js mirrors — a field declared
 *           in one place and not the other is either uneditable or unpublishable.
 */
$phScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$phScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$phParkId    = ($phScopeType === 'park') ? $phScopeId : 0;
if ($phParkId <= 0) {
    return;
}

$phShowWeather = !empty($blockFields['show_weather']);

// Cached whole, like every other org-scoped dynamic block. Uncached this hero
// pays five model round trips on EVERY anonymous hit to a park site's home page
// (park details, kingdom name, heraldry URL, park days, forecast) — and it is
// the one block that renders unconditionally. Keyed by (park, weather flag):
// the payload varies on both and on nothing else, since the remaining block
// fields are pure presentation and stay outside the cached region.
$phData = fdBlockCache(
    CmsRenderCache::NS_PARK_HERO,
    CmsRenderCache::ParkHeroKey($phParkId, $phShowWeather),
    CmsRenderCache::TTL,
    function () use ($phParkId, $phShowWeather) {
        $phPark = array();
        try {
            if (class_exists('APIModel')) {
                $phModel  = new APIModel('Park');
                $phDetail = $phModel->GetParkDetails(array('ParkId' => $phParkId));
                if (is_array($phDetail)) {
                    $phPark = $phDetail;
                }
            }
        } catch (\Throwable $e) {
            $phPark = array();
        }

        // The kingdom NAME is not in the park detail payload — only its id — so resolve it
        // through the model layer, exactly like the park/heraldry lookups above. The query
        // itself lives in Kingdom::GetKingdomName(); a template never talks to $DB.
        $phKingdom = '';
        $phKingdomId = (int) ($phPark['KingdomId'] ?? 0);
        if ($phKingdomId > 0) {
            try {
                if (class_exists('APIModel')) {
                    $phKingdom = (string) (new APIModel('Kingdom'))->GetKingdomName($phKingdomId);
                }
            } catch (\Throwable $e) {
                $phKingdom = '';
            }
        }

        // --- The seal -------------------------------------------------------------
        // Gate on has_heraldry, NEVER on a truthy URL: resolve_heraldry_url() returns a
        // guaranteed-404 path when no file exists, so a URL check always looks positive.
        $phDeviceUrl = '';
        $phIsCut     = false;
        if (!empty($phPark['HasHeraldry'])) {
            try {
                $phH = (new APIModel('Heraldry'))->GetHeraldryUrl(array('Type' => 'Park', 'Id' => $phParkId));
                if (is_array($phH) && !empty($phH['Url'])) {
                    $phDeviceUrl = (string) $phH['Url'];
                    // A .jpg is opaque, so its own background BECOMES the plate when
                    // cover-cropped to the disc. A .png was written with alpha and its
                    // transparent margin already trimmed, so it floats, matted.
                    $phIsCut = (bool) preg_match('/\.jpe?g(\?|$)/i', $phDeviceUrl);
                }
            } catch (\Throwable $e) {
                $phDeviceUrl = '';
            }
        }

        // --- Next game day + weather ---------------------------------------------
        $phNextLabel = '';
        $phWeather   = '';
        try {
            $phDays = (new APIModel('Park'))->GetParkDays(array('ParkId' => $phParkId));
            $phSoonest = null;
            // Park::CalculateNextParkDay() can return a date that has already happened:
            // 'week-of-month' resolves the Nth weekday of the CURRENT month (1st Sunday
            // is 2026-08-02 for the whole of August) and 'monthly' behaves the same way.
            // Taking the min() below would then let a stale date not only publish "next
            // game day" in the past, but SUPPRESS the park's correct weekly day, which is
            // strictly worse than showing nothing. The shared calculator is pre-existing
            // and used elsewhere, so guard here at the consumer rather than change it.
            $phTodayTs = strtotime('today');
            foreach ((array) ($phDays['ParkDays'] ?? array()) as $phDay) {
                if (!is_array($phDay) || !class_exists('Park')) {
                    continue;
                }
                $phWhen = Park::CalculateNextParkDay(
                    $phDay['Recurrence'] ?? '', $phDay['WeekOfMonth'] ?? 0, $phDay['MonthDay'] ?? 0,
                    $phDay['WeekDay'] ?? '', null, $phDay['StartDate'] ?? null, $phDay['WeekInterval'] ?? 0
                );
                if (!$phWhen) {
                    continue;
                }
                $phWhenTs = strtotime($phWhen);
                if ($phWhenTs === false || $phWhenTs < $phTodayTs) {
                    continue;
                }
                if ($phSoonest === null || $phWhenTs < strtotime($phSoonest['d'])) {
                    $phSoonest = array('d' => $phWhen, 't' => (string) ($phDay['Time'] ?? ''));
                }
            }
            if ($phSoonest !== null) {
                $phTs = strtotime($phSoonest['d']);
                $phNextLabel = date('l, F j', $phTs);
                if ($phSoonest['t'] !== '' && $phSoonest['t'] !== '00:00:00') {
                    $phNextLabel .= ' · ' . date('g:i A', strtotime($phSoonest['t']));
                }
                // Weather degrades SILENTLY past a 7-day horizon — the forecast table only
                // carries 7 days, and a stale or missing reading must never look broken.
                $phWithinWeek = ($phTs - time()) <= (7 * 86400);
                // Through APIModel like every other lookup in this file — a template does
                // not instantiate a domain class directly.
                if ($phWithinWeek && $phShowWeather && class_exists('APIModel')) {
                    $phF = (new APIModel('Weather'))->forecast_for_date($phParkId, date('Y-m-d', $phTs));
                    // Weather::forecast_from_row() returns 'hi_f' (float|null), NOT 'high' —
                    // see class.Weather.php:99-118. It always sets the key, using null for a
                    // missing reading, so isset() alone is not enough; the explicit !== null
                    // check is load-bearing, not decoration.
                    if (is_array($phF) && isset($phF['hi_f']) && $phF['hi_f'] !== null) {
                        $phWeather = round((float) $phF['hi_f']) . '°F';
                    }
                }
            }
        } catch (\Throwable $e) {
            $phNextLabel = '';
            $phWeather   = '';
        }

        return array(
            'Park'      => $phPark,
            'Kingdom'   => $phKingdom,
            'DeviceUrl' => $phDeviceUrl,
            'IsCut'     => $phIsCut,
            'NextLabel' => $phNextLabel,
            'Weather'   => $phWeather,
        );
    }
);

$phPark      = is_array($phData['Park'] ?? null) ? $phData['Park'] : array();
$phKingdom   = (string) ($phData['Kingdom'] ?? '');
$phDeviceUrl = (string) ($phData['DeviceUrl'] ?? '');
$phIsCut     = !empty($phData['IsCut']);
$phNextLabel = (string) ($phData['NextLabel'] ?? '');
$phWeather   = (string) ($phData['Weather'] ?? '');

// NOTE the exact keys: Park::GetParkDetails() returns 'ParkName' (not 'Name')
// and 'KingdomId' (not 'KingdomName'). Verified against class.Park.php:482-528.
$phName    = trim((string) ($phPark['ParkName'] ?? ''));
$phTitle   = trim((string) ($phPark['ParkTitle'] ?? ''));
$phCity    = trim((string) ($phPark['City'] ?? ''));
$phProv    = trim((string) ($phPark['Province'] ?? ''));
$phRetired = (string) ($phPark['Active'] ?? 'Active') !== 'Active';

// Eyebrow states the park's real rank and allegiance — Amtgard terminology doing
// real work, not decoration.
$phEyebrow = trim((string) ($blockFields['kicker'] ?? ''));
if ($phEyebrow === '') {
    $phEyebrow = trim(implode(' · ', array_filter(array($phTitle, $phKingdom))));
}
$phHeading = trim((string) ($blockFields['heading'] ?? '')) ?: $phName;
$phPlace   = trim(implode(', ', array_filter(array($phCity, $phProv))));

// Monogram fallback: initials, not the generic placeholder crest, which would make
// all 26 deviceless parks identical and unloved.
$phMonogram = '';
if ($phDeviceUrl === '') {
    $phWords = preg_split('/\s+/', $phName, -1, PREG_SPLIT_NO_EMPTY);
    foreach (array_slice($phWords, 0, 3) as $phW) {
        $phMonogram .= mb_strtoupper(mb_substr($phW, 0, 1));
    }
    $phMonogram = mb_substr($phMonogram, 0, 3);
}

$phCtaLabel = trim((string) ($blockFields['cta_label'] ?? '')) ?: 'Plan your first visit';
$phCtaHref  = trim((string) ($blockFields['cta_href'] ?? '')) ?: '#pk-meet';
$phMapUrl   = trim((string) ($phPark['MapUrl'] ?? ''));
if ($phMapUrl !== '' && !preg_match('#^https?://#i', $phMapUrl)) {
    $phMapUrl = '';
}
$phPlaceholder = is_array($blockFields['placeholder_image'] ?? null) ? $blockFields['placeholder_image'] : array();
$phPhotoSrc = trim((string) ($phPlaceholder['display'] ?? $phPlaceholder['src'] ?? ''));
?>
<header class="pk-hero<?= $phRetired ? ' is-retired' : '' ?>">
    <?php if ($phPhotoSrc !== ''): ?>
        <img class="pk-hero-photo" src="<?= htmlspecialchars($phPhotoSrc, ENT_QUOTES) ?>" alt="" aria-hidden="true">
    <?php endif; ?>
    <div class="pk-hero-field" aria-hidden="true"></div>
    <div class="pk-hero-inner">
        <div>
            <?php if ($phEyebrow !== ''): ?>
                <p class="pk-eyebrow"><?= htmlspecialchars($phEyebrow, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <h1 class="pk-name"><?= htmlspecialchars($phHeading, ENT_QUOTES) ?></h1>
            <?php if ($phPlace !== ''): ?>
                <p class="pk-place"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><?= htmlspecialchars($phPlace, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($phNextLabel !== ''): ?>
                <p class="pk-next">Next game day <b><?= htmlspecialchars($phNextLabel, ENT_QUOTES) ?></b>
                    <?php if ($phWeather !== ''): ?>
                        <span class="pk-wx"><i class="fas fa-cloud-sun" aria-hidden="true"></i> <?= htmlspecialchars($phWeather, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="pk-actions">
                <a class="fd-btn-gold" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($phCtaHref), ENT_QUOTES) ?>"><?= htmlspecialchars($phCtaLabel, ENT_QUOTES) ?></a>
                <?php if ($phMapUrl !== ''): ?>
                    <a class="fd-btn-ghost" href="<?= htmlspecialchars($phMapUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Get directions <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($phDeviceUrl !== ''): ?>
            <div class="pk-seal <?= $phIsCut ? 'is-cut' : 'is-matted' ?>">
                <img src="<?= htmlspecialchars($phDeviceUrl, ENT_QUOTES) ?>" alt="Arms of <?= htmlspecialchars($phName, ENT_QUOTES) ?>">
            </div>
        <?php else: ?>
            <div class="pk-seal is-monogram" role="img" aria-label="<?= htmlspecialchars($phName, ENT_QUOTES) ?>"><?= htmlspecialchars($phMonogram, ENT_QUOTES) ?></div>
        <?php endif; ?>
    </div>
</header>
