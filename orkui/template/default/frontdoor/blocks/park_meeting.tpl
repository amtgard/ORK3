<?php
/**
 * Partial: park_meeting.tpl — DYNAMIC block (org-scoped, PARK sites).
 *
 * "When and where we meet" — the single most important thing a park's public page
 * can say, and the thing a newcomer searching for Amtgard actually came for.
 * Sourced live from the ORK's own park-day records (ork_parkday via
 * Park::GetParkDays) plus the park's address (Park::GetParkDetails), so a park
 * that keeps its ORK record current can never publish a stale meeting time.
 *
 * Scope: derives park_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * park scope — never errors, never fatals.
 *
 * TRUST NOTE: GetParkDetails returns Directions/Description already run through
 * nl2br(stripslashes(...)) but NOT escaped, so they carry officer-entered text
 * with markup injected. They are ORK data, not CMS block fields, so the E36
 * block-field allowlist does not cover them — this partial escapes them here and
 * restores only <br>, which is the one tag nl2br added.
 *
 * Receives: $blockFields { kicker?, heading?, show_directions?, show_map?, limit? },
 * UIR, $SiteNavScope*.
 */
$pmScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$pmScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$pmParkId    = ($pmScopeType === 'park') ? $pmScopeId : 0;

if ($pmParkId <= 0) {
    return;
}

$pmKicker  = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$pmHeading = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'When & Where We Meet';
$pmShowDir = !empty($blockFields['show_directions']);
$pmShowMap = !isset($blockFields['show_map']) || !empty($blockFields['show_map']);
$pmLimit   = fdClampLimit(
    $blockFields['limit'] ?? null,
    CmsRenderCache::MEETING_LIMIT_DEFAULT,
    CmsRenderCache::MEETING_LIMIT_MAX
);

// Cached whole, like the other org-scoped dynamic blocks: two model calls on every
// anonymous hit otherwise. Keyed by (park, limit, directions flag) — the render
// varies on all three. The namespace, key format and clamp bounds come from
// CmsRenderCache, which CmsAjax::clearrendercache enumerates to flush this key
// space; declaring them in one place is what keeps the flush honest.
$pmData = fdBlockCache(
    CmsRenderCache::NS_PARK_MEETING,
    CmsRenderCache::MeetingKey($pmParkId, $pmLimit, $pmShowDir),
    CmsRenderCache::TTL,
    function () use ($pmParkId, $pmLimit) {
    $pmDays = [];
    $pmPark = [];
    if (class_exists('APIModel')) {
        try {
            $pmModel = new APIModel('Park');

            $pmDayResult = $pmModel->GetParkDays(['ParkId' => (int) $pmParkId]);
            if (is_array($pmDayResult) && isset($pmDayResult['ParkDays']) && is_array($pmDayResult['ParkDays'])) {
                $pmDays = $pmDayResult['ParkDays'];
            }

            $pmDetail = $pmModel->GetParkDetails(['ParkId' => (int) $pmParkId]);
            if (is_array($pmDetail)) {
                $pmPark = $pmDetail;
            }
        } catch (\Throwable $e) {
            $pmDays = [];
            $pmPark = [];
        }
    }
    return ['days' => array_slice($pmDays, 0, $pmLimit), 'park' => $pmPark];
    }
);

$pmDays = is_array($pmData['days'] ?? null) ? $pmData['days'] : [];
$pmPark = is_array($pmData['park'] ?? null) ? $pmData['park'] : [];

/**
 * Escape officer-entered text that the Park lib already passed through nl2br(),
 * then restore only the <br> nl2br inserted. Everything else — including any tag
 * the officer typed by hand — stays inert.
 */
$pmSafeMultiline = static function ($raw) {
    $out = htmlspecialchars((string) $raw, ENT_QUOTES);
    return str_replace(['&lt;br /&gt;', '&lt;br/&gt;', '&lt;br&gt;'], '<br>', $out);
};

/** "6:00 PM" from a MySQL TIME. Returns '' for the unset 00:00:00 sentinel. */
$pmTimeLabel = static function ($raw) {
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '00:00:00') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $raw . ' UTC');
    return ($ts === false) ? '' : gmdate('g:i A', $ts);
};

/** Plain-English recurrence, e.g. "Every Saturday" / "2nd Sunday of the month". */
$pmWhenLabel = static function (array $d) {
    $day = trim((string) ($d['WeekDay'] ?? ''));
    if ($day === 'None') {
        $day = '';
    }
    $recurrence = (string) ($d['Recurrence'] ?? 'weekly');
    $ordinals   = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];

    switch ($recurrence) {
        case 'monthly':
            $md = (int) ($d['MonthDay'] ?? 0);
            return $md > 0 ? ('Monthly, on the ' . $md . date('S', mktime(0, 0, 0, 1, $md, 2000))) : 'Monthly';
        case 'week-of-month':
            $wom = (int) ($d['WeekOfMonth'] ?? 0);
            $ord = $ordinals[$wom] ?? '';
            if ($ord !== '' && $day !== '') {
                return $ord . ' ' . $day . ' of the month';
            }
            return $day !== '' ? ('Monthly on ' . $day) : 'Monthly';
        case 'every-x-weeks':
            $iv = (int) ($d['WeekInterval'] ?? 0);
            if ($iv > 1 && $day !== '') {
                return 'Every ' . $iv . ' weeks on ' . $day;
            }
            return $day !== '' ? ('Every ' . $day) : '';
        case 'weekly':
        default:
            return $day !== '' ? ('Every ' . $day) : 'Weekly';
    }
};

/**
 * Best available address for a park day, falling back to the park's own record.
 * Returns ['line' => string, 'map' => string].
 */
$pmPlace = static function (array $d, array $park) {
    $street = trim((string) ($d['Address'] ?? ''));
    $city   = trim((string) ($d['City'] ?? ''));
    $prov   = trim((string) ($d['Province'] ?? ''));
    $post   = trim((string) ($d['PostalCode'] ?? ''));
    // The venue NAME is the one genuinely unreliable field here, so it gets its own
    // guard. Two traps, both hit on live data:
    //
    //  1. `alternate_location` is a tinyint FLAG (0/1) — "this park day meets
    //     somewhere other than the park's usual spot" — NOT a name. Reading it as one
    //     printed a literal "0" on every card, because trim((string) 0) is "0", which
    //     is non-empty and so passed the `!== ''` guard at the call site.
    //  2. `location` is nominally a venue name but is in practice a geocode cache:
    //     of 806 ork_parkday rows, 522 hold a raw JSON blob ({"location":{"lat":…})
    //     and only 33 hold anything human. ork_park.location is worse — 781 JSON to
    //     267 human. Emitting it verbatim dumped that JSON onto the page.
    //
    // So: take the name from `location` only when it does not look like serialized
    // data. Anything starting with { or [, or containing a "key": pair, is a cache
    // artefact and is dropped — the address line below is the reliable venue info
    // and renders on its own.
    //  3. A third trap, same shape as the first two: the column holds the literal
    //     four-character string "null" — a stringified null from whatever wrote the
    //     row, not a venue called "null". It is non-empty, does not start with { or
    //     [, and carries no "key": pair, so it passed every guard above and printed
    //     `null` on the card. This is not one bad row: 33 ork_parkday and 267
    //     ork_park rows hold it. Treat the usual stringified-empty spellings as
    //     empty rather than migrating 300 rows.
    $pmCleanName = static function ($raw) {
        $v = trim((string) $raw);
        if ($v === '' || $v[0] === '{' || $v[0] === '[') {
            return '';
        }
        if (in_array(strtolower($v), ['null', 'nil', 'none', 'undefined', 'n/a'], true)) {
            return '';
        }
        return preg_match('/"[a-z_]+"\s*:/i', $v) ? '' : $v;
    };
    $name = $pmCleanName($d['Location'] ?? '');

    // A park day with no address of its own inherits the park's — UNLESS it is
    // flagged as meeting somewhere else, in which case the park's address is the
    // wrong answer and no address at all is the honest one.
    $isAlternate = !empty($d['AlternateLocation']);
    if ($street === '' && $city === '' && !$isAlternate) {
        $street = trim((string) ($park['Address'] ?? ''));
        $city   = trim((string) ($park['City'] ?? ''));
        $prov   = trim((string) ($park['Province'] ?? ''));
        $post   = trim((string) ($park['PostalCode'] ?? ''));
        if ($name === '') {
            $name = $pmCleanName($park['Location'] ?? '');
        }
    }

    $cityBits = array_filter([$city, $prov]);
    $line     = implode(', ', array_filter([$street, implode(', ', $cityBits), $post]));

    $map = trim((string) ($d['MapUrl'] ?? ''));
    if ($map === '') {
        $map = trim((string) ($park['MapUrl'] ?? ''));
    }
    // No stored map link → build a Google Maps search from whatever address we have.
    if ($map === '' && $line !== '') {
        $map = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($line);
    }
    // Only ever emit http(s) — a stored javascript: URL must not become an href.
    if ($map !== '' && !preg_match('#^https?://#i', $map)) {
        $map = '';
    }

    return ['line' => $line, 'name' => $name, 'map' => $map];
};

$pmDirections = $pmShowDir ? trim((string) ($pmPark['Directions'] ?? '')) : '';
?>
<div class="fd-pad fd-section-light pm-block" id="pk-meet">
    <div class="pm-head">
        <?php if ($pmKicker !== ''): ?>
            <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($pmKicker, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if ($pmHeading !== ''): ?>
            <h2 class="pm-title fd-sec-title"><?= htmlspecialchars($pmHeading, ENT_QUOTES) ?></h2>
        <?php endif; ?>
    </div>

    <?php if (empty($pmDays)): ?>
        <?php // No park-day records. Fall back to the park's own address rather than
              // rendering an empty box — an address alone is still useful. ?>
        <?php $pmFallback = $pmPlace([], $pmPark); ?>
        <?php if ($pmFallback['line'] !== ''): ?>
            <div class="pm-grid">
                <div class="pm-card">
                    <div class="pm-when"><i class="fas fa-map-marker-alt"></i> Where we play</div>
                    <?php if ($pmFallback['name'] !== ''): ?>
                        <div class="pm-place"><?= htmlspecialchars($pmFallback['name'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div class="pm-addr"><?= htmlspecialchars($pmFallback['line'], ENT_QUOTES) ?></div>
                    <?php if ($pmShowMap && $pmFallback['map'] !== ''): ?>
                        <a class="pm-map" href="<?= htmlspecialchars($pmFallback['map'], ENT_QUOTES) ?>"
                           target="_blank" rel="noopener noreferrer">Get directions <i class="fas fa-external-link-alt"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="pm-empty">Meeting times coming soon — check back shortly.</div>
        <?php endif; ?>
    <?php else: ?>
        <div class="pm-grid">
            <?php foreach ($pmDays as $pmDay): ?>
                <?php
                if (!is_array($pmDay)) {
                    continue;
                }
                $pmWhen    = $pmWhenLabel($pmDay);
                $pmTime    = $pmTimeLabel($pmDay['Time'] ?? '');
                $pmWhere   = $pmPlace($pmDay, $pmPark);
                $pmPurpose = trim((string) ($pmDay['Purpose'] ?? ''));
                $pmNote    = trim((string) ($pmDay['Description'] ?? ''));
                $pmOnline  = !empty($pmDay['Online']);
                ?>
                <div class="pm-card">
                    <div class="pm-when">
                        <i class="fas fa-calendar-alt"></i>
                        <?= htmlspecialchars($pmWhen !== '' ? $pmWhen : 'Regular meetup', ENT_QUOTES) ?>
                        <?php if ($pmTime !== ''): ?>
                            <span class="pm-time"><?= htmlspecialchars($pmTime, ENT_QUOTES) ?></span>
                        <?php endif; ?>
                        <?php if ($pmOnline): ?>
                            <span class="pm-online">Online</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($pmPurpose !== ''): ?>
                        <div class="pm-purpose"><?= htmlspecialchars($pmPurpose, ENT_QUOTES) ?></div>
                    <?php endif; ?>

                    <?php if ($pmWhere['name'] !== ''): ?>
                        <div class="pm-place"><?= htmlspecialchars($pmWhere['name'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($pmWhere['line'] !== ''): ?>
                        <div class="pm-addr"><?= htmlspecialchars($pmWhere['line'], ENT_QUOTES) ?></div>
                    <?php endif; ?>

                    <?php if ($pmNote !== ''): ?>
                        <div class="pm-note"><?= $pmSafeMultiline($pmNote) ?></div>
                    <?php endif; ?>

                    <?php if ($pmShowMap && $pmWhere['map'] !== ''): ?>
                        <a class="pm-map" href="<?= htmlspecialchars($pmWhere['map'], ENT_QUOTES) ?>"
                           target="_blank" rel="noopener noreferrer">Get directions <i class="fas fa-external-link-alt"></i></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($pmDirections !== ''): ?>
        <div class="pm-directions">
            <div class="pm-directions-h"><i class="fas fa-route"></i> Finding us</div>
            <div class="pm-directions-b"><?= $pmSafeMultiline($pmDirections) ?></div>
        </div>
    <?php endif; ?>
</div>
