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
$psScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$psParkId    = ($psScopeType === 'park') ? (int) ($SiteNavScopeId ?? 0) : 0;
if ($psParkId <= 0) {
    return;
}

$psWhen = '';
$psWhere = '';
$psMap = '';
try {
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
    $psBest = null;
    // Same past-date guard as park_hero.tpl. Park::CalculateNextParkDay() returns
    // an ALREADY-PAST date for 'week-of-month' (Nth weekday of the current month)
    // and 'monthly', and the min() below would let that stale candidate win —
    // pinning a date that has been and gone to the top of every page, and hiding
    // the park's real weekly day while it did so. Guarded at the consumer: the
    // calculator is shared and out of scope here.
    $psTodayTs = strtotime('today');
    foreach ((array) ($psDays['ParkDays'] ?? array()) as $psDay) {
        if (!is_array($psDay) || !class_exists('Park')) {
            continue;
        }
        $psNext = Park::CalculateNextParkDay(
            $psDay['Recurrence'] ?? '', $psDay['WeekOfMonth'] ?? 0, $psDay['MonthDay'] ?? 0,
            $psDay['WeekDay'] ?? '', null, $psDay['StartDate'] ?? null, $psDay['WeekInterval'] ?? 0
        );
        if (!$psNext) {
            continue;
        }
        $psNextTs = strtotime($psNext);
        if ($psNextTs === false || $psNextTs < $psTodayTs) {
            continue;
        }
        if ($psBest === null || $psNextTs < strtotime($psBest['d'])) {
            $psBest = array('d' => $psNext, 't' => (string) ($psDay['Time'] ?? ''),
                            'w' => (string) ($psDay['WeekDay'] ?? ''));
        }
    }
    if ($psBest !== null) {
        $psWhen = ($psBest['w'] !== '') ? $psBest['w'] . 's' : date('l', strtotime($psBest['d'])) . 's';
        if ($psBest['t'] !== '' && $psBest['t'] !== '00:00:00') {
            $psWhen .= ' ' . date('g:i A', strtotime($psBest['t']));
        }
    }
} catch (\Throwable $e) {
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
