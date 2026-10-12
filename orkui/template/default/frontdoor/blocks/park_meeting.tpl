<?php
/**
 * Partial: park_meeting.tpl — DYNAMIC block (org-scoped, PARK sites).
 *
 * "When and where we meet" — the single most important thing a park's public page
 * can say, and the thing a newcomer searching for Amtgard actually came for.
 * Sourced live from the ORK's own park-day records, already resolved by
 * Park::GetPublicMeetingSchedule (recurrence vocabulary, venue-name cleaning and
 * the park-address inheritance rule all live there), so a park that keeps its ORK
 * record current can never publish a stale meeting time — and a mobile app asking
 * the same question gets the same answer without re-deriving any of it.
 *
 * Scope: derives park_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * park scope — never errors, never fatals.
 *
 * TRUST NOTE: GetParkDetails returns Directions/Description already run through
 * nl2br(stripslashes(...)) but NOT escaped, so they carry officer-entered text
 * with markup injected. They are ORK data, not CMS block fields, so the
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

// Cached whole, like the other org-scoped dynamic blocks: one model call on every
// anonymous hit otherwise. Keyed by (park, limit, directions flag) — the render
// varies on all three. The namespace, key format and clamp bounds come from
// CmsRenderCache, which CmsAjax::clearrendercache enumerates to flush this key
// space; declaring them in one place is what keeps the flush honest.
$pmData = fdBlockCache(
    CmsRenderCache::NS_PARK_MEETING,
    CmsRenderCache::MeetingKey($pmParkId, $pmLimit, $pmShowDir),
    CmsRenderCache::TTL,
    function () use ($pmParkId, $pmLimit) {
        // The schedule is RESOLVED IN THE DOMAIN: Park::GetPublicMeetingSchedule owns
        // the recurrence vocabulary, the address-inheritance rule and the venue-name
        // cleaning, so this block only formats and escapes what it is handed.
        $pmSchedule = [ 'Meetings' => [], 'Fallback' => [], 'Directions' => '' ];
        if (class_exists('APIModel')) {
            try {
                $pmModel  = new APIModel('Park');
                $pmResult = $pmModel->GetPublicMeetingSchedule([
                    'ParkId' => (int) $pmParkId,
                    'Limit'  => (int) $pmLimit,
                ]);
                if (is_array($pmResult)) {
                    $pmSchedule = $pmResult + $pmSchedule;
                }
            } catch (\Throwable $e) {
                $pmSchedule = [ 'Meetings' => [], 'Fallback' => [], 'Directions' => '' ];
            }
        }
        return $pmSchedule;
    }
);

$pmDays     = is_array($pmData['Meetings'] ?? null) ? $pmData['Meetings'] : [];
$pmFallback = is_array($pmData['Fallback'] ?? null) ? $pmData['Fallback'] : [];

/**
 * Escape officer-entered text that the Park lib already passed through nl2br(),
 * then restore only the <br> nl2br inserted. Everything else — including any tag
 * the officer typed by hand — stays inert.
 */
$pmSafeMultiline = static function ($raw) {
    $out = htmlspecialchars((string) $raw, ENT_QUOTES);
    return str_replace(['&lt;br /&gt;', '&lt;br/&gt;', '&lt;br&gt;'], '<br>', $out);
};

$pmDirections = $pmShowDir ? trim((string) ($pmData['Directions'] ?? '')) : '';
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
        <?php if (($pmFallback['AddressLine'] ?? '') !== ''): ?>
            <div class="pm-grid">
                <div class="pm-card">
                    <div class="pm-when"><i class="fas fa-map-marker-alt"></i> Where we play</div>
                    <?php if (($pmFallback['VenueName'] ?? '') !== ''): ?>
                        <div class="pm-place"><?= htmlspecialchars($pmFallback['VenueName'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div class="pm-addr"><?= htmlspecialchars($pmFallback['AddressLine'], ENT_QUOTES) ?></div>
                    <?php if ($pmShowMap && ($pmFallback['MapUrl'] ?? '') !== ''): ?>
                        <a class="pm-map" href="<?= htmlspecialchars($pmFallback['MapUrl'], ENT_QUOTES) ?>"
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
                $pmWhen    = (string) ($pmDay['WhenLabel'] ?? '');
                $pmTime    = (string) ($pmDay['TimeLabel'] ?? '');
                $pmPurpose = (string) ($pmDay['Purpose'] ?? '');
                $pmNote    = (string) ($pmDay['Description'] ?? '');
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

                    <?php if (($pmDay['VenueName'] ?? '') !== ''): ?>
                        <div class="pm-place"><?= htmlspecialchars($pmDay['VenueName'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if (($pmDay['AddressLine'] ?? '') !== ''): ?>
                        <div class="pm-addr"><?= htmlspecialchars($pmDay['AddressLine'], ENT_QUOTES) ?></div>
                    <?php endif; ?>

                    <?php if ($pmNote !== ''): ?>
                        <div class="pm-note"><?= $pmSafeMultiline($pmNote) ?></div>
                    <?php endif; ?>

                    <?php if ($pmShowMap && ($pmDay['MapUrl'] ?? '') !== ''): ?>
                        <a class="pm-map" href="<?= htmlspecialchars($pmDay['MapUrl'], ENT_QUOTES) ?>"
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
