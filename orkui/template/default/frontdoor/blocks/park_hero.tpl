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

// NOTE the exact keys: Park::GetParkDetails() returns 'ParkName' (not 'Name')
// and 'KingdomId' (not 'KingdomName'). Verified against class.Park.php:482-528.
$phName    = trim((string) ($phPark['ParkName'] ?? ''));
$phTitle   = trim((string) ($phPark['ParkTitle'] ?? ''));
$phCity    = trim((string) ($phPark['City'] ?? ''));
$phProv    = trim((string) ($phPark['Province'] ?? ''));
$phRetired = (string) ($phPark['Active'] ?? 'Active') !== 'Active';

// The kingdom NAME is not in the park detail payload — only its id — so resolve it.
$phKingdom = '';
$phKingdomId = (int) ($phPark['KingdomId'] ?? 0);
if ($phKingdomId > 0) {
    global $DB;
    $DB->Clear();
    $DB->kingdom_id = $phKingdomId;
    $phKRes = $DB->DataSet(
        'SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = :kingdom_id LIMIT 1'
    );
    // DataSet() needs an explicit Next() before any field read.
    if ($phKRes && $phKRes->Next()) {
        $phKingdom = trim((string) $phKRes->name);
    }
}

// Eyebrow states the park's real rank and allegiance — Amtgard terminology doing
// real work, not decoration.
$phEyebrow = trim((string) ($blockFields['kicker'] ?? ''));
if ($phEyebrow === '') {
    $phEyebrow = trim(implode(' · ', array_filter(array($phTitle, $phKingdom))));
}
$phHeading = trim((string) ($blockFields['heading'] ?? '')) ?: $phName;
$phPlace   = trim(implode(', ', array_filter(array($phCity, $phProv))));

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
        if ($phWithinWeek && !empty($blockFields['show_weather']) && class_exists('Weather')) {
            $phF = (new Weather())->forecast_for_date($phParkId, date('Y-m-d', $phTs));
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

$phCtaLabel = trim((string) ($blockFields['cta_label'] ?? '')) ?: 'Plan your first visit';
$phCtaHref  = trim((string) ($blockFields['cta_href'] ?? '')) ?: '#pk-meet';
$phMapUrl   = trim((string) ($phPark['MapUrl'] ?? ''));
if ($phMapUrl !== '' && !preg_match('#^https?://#i', $phMapUrl)) {
    $phMapUrl = '';
}
$phPlaceholder = is_array($blockFields['placeholder_image'] ?? null) ? $blockFields['placeholder_image'] : array();
$phPhotoSrc = trim((string) ($phPlaceholder['display'] ?? $phPlaceholder['src'] ?? ''));
?>
<?php if (empty($fdStyleOnce['park_hero'])) : $fdStyleOnce['park_hero'] = true; ?>
<style>
/* scoped: pk-hero */
.pk-hero { position: relative; overflow: hidden; background: var(--fd-primary);
    color: var(--fd-primary-contrast); border-bottom: 3px solid var(--fd-accent-on-primary, var(--fd-primary-contrast));
    padding: clamp(40px, 7vw, 84px) clamp(20px, 4vw, 56px); }
/* Placeholder photo sits BEHIND the field at low opacity so removing it degrades
   to a finished crest hero rather than an empty frame. */
.pk-hero-photo { position: absolute; inset: 0; object-fit: cover; width: 100%; height: 100%;
    opacity: .22; }
/* Diapering: heralds incised a lattice into large flat tinctures precisely because
   unbroken flat colour reads as dead paint. CSS-only, so it never depends on the
   device file's alpha. */
.pk-hero-field { position: absolute; inset: 0; pointer-events: none;
    background-image:
        radial-gradient(circle at 1px 1px, rgba(255,255,255,.075) 1px, transparent 1.7px),
        repeating-linear-gradient( 60deg, transparent 0 15px, rgba(255,255,255,.042) 15px 16px),
        repeating-linear-gradient(-60deg, transparent 0 15px, rgba(255,255,255,.042) 15px 16px);
    background-size: 32px 55px, auto, auto;
    -webkit-mask-image: radial-gradient(115% 105% at 78% 45%, #000 18%, rgba(0,0,0,.28) 100%);
    mask-image: radial-gradient(115% 105% at 78% 45%, #000 18%, rgba(0,0,0,.28) 100%); }
.pk-hero-inner { position: relative; max-width: 1120px; margin-inline: auto; display: grid;
    grid-template-columns: minmax(0,1fr) auto; align-items: center; gap: clamp(24px, 5vw, 64px); }
.pk-eyebrow { font-family: var(--fd-font-body); font-weight: 700; font-size: .6875rem;
    letter-spacing: .16em; text-transform: uppercase; color: var(--fd-accent-on-primary, var(--fd-primary-contrast));
    margin: 0 0 10px; }
/* Reset the orkui global h1-h6 grey pill box. */
.pk-name { background: none; border: 0; padding: 0; border-radius: 0; margin: 0 0 10px;
    font-family: var(--fd-font-heading); color: var(--fd-primary-contrast);
    font-size: clamp(2.25rem, 1.35rem + 4.4vw, 4rem); line-height: .98; letter-spacing: -.015em; }
.pk-place { margin: 0 0 14px; opacity: .86; }
.pk-place i { margin-right: 7px; }
.pk-next { display: inline-block; margin: 0 0 22px; padding: 8px 14px;
    border-left: 3px solid var(--fd-accent-on-primary, var(--fd-primary-contrast));
    background: rgba(255,255,255,.09); border-radius: 0 var(--fd-radius, 6px) var(--fd-radius, 6px) 0; }
.pk-wx { margin-left: 10px; opacity: .85; }
.pk-actions { display: flex; flex-wrap: wrap; gap: 12px; }
/* The matting disc is a PLATE, and it is light in BOTH themes on purpose. It
   used to read --pk-paper, which dark mode remaps to var(--fd-bg) — a near-black
   — so the disc inverted under artwork that was drawn for white paper: a trimmed
   PNG device lost its dark outlines entirely and a cover-cropped JPG sat in a
   black ring. Heraldry is painted on a light field; the plate follows the
   artwork, not the page. --pk-seal-plate / --pk-seal-ink are defined once in
   frontdoor.css and deliberately NOT overridden in the dark block. */
.pk-seal { width: clamp(116px, 17vw, 196px); aspect-ratio: 1; display: grid; place-items: center;
    border-radius: 50%; background: var(--pk-seal-plate, #fbfcfd);
    box-shadow: 0 0 0 2px var(--fd-accent-on-primary, var(--fd-primary-contrast)),
                0 0 0 9px rgba(255,255,255,.13), 0 14px 34px rgba(0,0,0,.28); }
.pk-seal.is-matted img { width: 78%; height: 78%; object-fit: contain; }
.pk-seal.is-cut img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
/* Monogram ink, NOT --fd-primary: dark mode lifts the primary to L>=0.55 for
   legibility on a dark page, and that lifted colour on this permanently light
   plate is the same contrast failure in reverse. --pk-seal-ink is the org's own
   hue held dark in both themes. */
.pk-seal.is-monogram { color: var(--pk-seal-ink, var(--fd-primary)); font-family: var(--fd-font-heading);
    font-size: clamp(2.4rem, 6vw, 4.2rem); font-weight: 700; }
.pk-hero.is-retired { filter: saturate(.35); }
@media (max-width: 760px) {
    .pk-hero-inner { grid-template-columns: 1fr; gap: 22px; }
    .pk-seal { order: -1; width: 104px; }
    .pk-actions > a { flex: 1 1 auto; justify-content: center; }
}
</style>
<?php endif; ?>
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
