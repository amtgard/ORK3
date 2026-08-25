<?php
/**
 * Partial: _park_strip.tpl — the sticky quick-facts strip (park scope only).
 *
 * SHELL CHROME, not a block: an officer must not be able to delete it by accident.
 *
 * This is the mechanism that resolves the three-audience tension. New players,
 * locals and visiting players all want the SAME fact first — when and where — and
 * diverge only on the second. So the shared fact is hoisted above the split and the
 * split happens below it: the local reads this and leaves in four seconds, the
 * newcomer scrolls past it into reassurance, the visitor taps Contact.
 *
 * THREE DEGRADATION TIERS. This is what makes it safe to pin:
 *   1. park days exist (308 of 342)      -> time, place, directions
 *   2. no park days but an address (98%) -> place and directions ONLY.
 *                                           Never invent or approximate a time.
 *   3. neither                            -> render NOTHING. A permanently sticky
 *      "Meeting times coming soon" follows the visitor down every page announcing
 *      that the site is unfinished.
 */
// The sole call site (Site_shell.tpl) includes org_header.tpl one line earlier and
// that file already require_once's the helpers, so they are in fact always defined
// here; this is a belt-and-braces guard (_helpers.tpl is idempotent and emits
// nothing) so this partial does not silently depend on the include order of a file
// it does not own.
if (!function_exists('fdBlockCache') && defined('DIR_TEMPLATE')) {
    require_once DIR_TEMPLATE . 'default/frontdoor/_helpers.tpl';
}

// Park scope only — outside it there is no park to source, so render nothing.
// (See fdScopedOrgId() in _helpers.tpl.)
$psParkId = fdScopedOrgId($SiteNavScopeType ?? '', $SiteNavScopeId ?? 0, 'park');
if ($psParkId <= 0) {
    return;
}

// SHELL chrome renders on EVERY page of a park's org site — home, page, post,
// blog — so uncached it charges every anonymous hit two model round trips
// (GetParkDetails() alone is three queries) for meeting-day and address data
// that changes a handful of times a year. Cached whole, exactly like the
// park-scoped dynamic blocks, through the same read-through wrapper they use.

$psWhen = '';
$psWhere = '';
$psMap = '';
try {
    $psBuild = function () use ($psParkId) {
        $psModel = new APIModel('Park');
        $psPark  = $psModel->GetParkDetails(array('ParkId' => $psParkId));
        $psPark  = is_array($psPark) ? $psPark : array();

        $psWhere = trim(implode(', ', array_filter(array(
            trim((string) ($psPark['City'] ?? '')),
            trim((string) ($psPark['Province'] ?? '')),
        ))));
        $psMap = trim((string) ($psPark['MapUrl'] ?? ''));
        if ($psMap !== '' && !preg_match('#^https?://#i', $psMap)) {
            $psMap = '';
        }
        if ($psMap === '' && $psWhere !== '') {
            $psMap = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(
                trim((string) ($psPark['Address'] ?? '')) . ' ' . $psWhere
            );
        }

        $psDays = $psModel->GetParkDays(array('ParkId' => $psParkId));
        // Store the park-day ROWS, never the model envelope. GetParkDays() always
        // returns ['Status' => Success(), 'ParkDays' => [...]], and Success() is
        // itself a non-empty array — so caching the envelope would make every
        // payload look non-empty and defeat CmsRenderCache's empty-payload gate,
        // pinning a failed fetch (a find() that returned false on a DB blip, so no
        // City/Province and no days) as an EMPTY strip on every page of this park's
        // site for the whole TTL. Flattened, a failed build is all-empty and the
        // cache declines to store it, exactly as that gate intends.
        return array(
            'where' => $psWhere,
            'map'   => $psMap,
            'days'  => (array) ($psDays['ParkDays'] ?? array()),
        );
    };
    $psData = fdBlockCache(
        CmsRenderCache::NS_PARK_STRIP,
        CmsRenderCache::ParkStripKey($psParkId),
        CmsRenderCache::TTL,
        $psBuild
    );
    $psData = is_array($psData) ? $psData : array();

    $psWhere = (string) ($psData['where'] ?? '');
    $psMap   = (string) ($psData['map'] ?? '');
    $psDays  = (array) ($psData['days'] ?? array());
    // Shared with park_hero.tpl, past-date guard included — see fdSoonestParkDay()
    // in _helpers.tpl for why that guard is load-bearing.
    $psBest = fdSoonestParkDay($psDays);
    if ($psBest !== null) {
        $psWhen = ($psBest['w'] !== '') ? $psBest['w'] . 's' : date('l', strtotime($psBest['d'])) . 's';
        if ($psBest['t'] !== '' && $psBest['t'] !== '00:00:00') {
            $psWhen .= ' ' . date('g:i A', strtotime($psBest['t']));
        }
    }
} catch (\Throwable $e) {
    // Both fetches sit inside the closure, so a throw from either GetParkDetails()
    // or GetParkDays() drops straight to tier 3 (no where/when at all). Accepted:
    // both calls go through Success(), so a shared failure cause would already have
    // left GetParkDetails empty and tier 2 would never have rendered regardless.
    $psWhen = '';
}

// Tier 3: nothing truthful to say.
if ($psWhen === '' && $psWhere === '') {
    return;
}
?>
<div class="pk-strip">
    <?php if ($psWhen !== ''): ?>
        <span><i class="fas fa-clock" aria-hidden="true"></i><?= htmlspecialchars($psWhen, ENT_QUOTES) ?></span>
    <?php endif; ?>
    <?php if ($psWhere !== ''): ?>
        <span><i class="fas fa-map-marker-alt" aria-hidden="true"></i><?= htmlspecialchars($psWhere, ENT_QUOTES) ?></span>
    <?php endif; ?>
    <?php if ($psMap !== ''): ?>
        <a class="pk-strip-link" href="<?= htmlspecialchars($psMap, ENT_QUOTES) ?>" target="_blank" rel="noopener">Directions <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
    <?php endif; ?>
</div>
