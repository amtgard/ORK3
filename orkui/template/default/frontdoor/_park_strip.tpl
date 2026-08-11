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
    foreach ((array) ($psDays['ParkDays'] ?? array()) as $psDay) {
        if (!is_array($psDay) || !class_exists('Park')) {
            continue;
        }
        $psNext = Park::CalculateNextParkDay(
            $psDay['Recurrence'] ?? '', $psDay['WeekOfMonth'] ?? 0, $psDay['MonthDay'] ?? 0,
            $psDay['WeekDay'] ?? '', null, $psDay['StartDate'] ?? null, $psDay['WeekInterval'] ?? 0
        );
        if ($psNext && ($psBest === null || strtotime($psNext) < strtotime($psBest['d']))) {
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
<style>
.pk-strip { position: sticky; top: 0; z-index: 40; display: flex; flex-wrap: wrap;
    align-items: center; gap: 6px 16px; padding: 9px clamp(14px, 3vw, 28px);
    background: var(--fd-primary); color: var(--fd-primary-contrast);
    font-size: calc(var(--fd-font-scale, 1) * .9375rem); }
.pk-strip i { margin-right: 6px; opacity: .8; }
#theme_container a.pk-strip-link { color: var(--fd-accent-on-primary, var(--fd-accent)); font-weight: 600; }
#theme_container a.pk-strip-link:hover { color: var(--fd-primary-contrast); }
/* Fix round 1 (Task 10 review): in dark mode, `html[data-theme="dark"]
   #theme_container a` (default.theme) is (1,1,2) — ONE specificity notch above
   the plain `#theme_container a.pk-strip-link` rule above ((1,1,1): the extra
   `html` element in the trap's selector outweighs this rule's extra class), so
   the global link-blue trap silently won regardless of what
   --fd-accent-on-primary resolved to. Measured live: "Directions" rendered
   #63b3ed (the trap's color), not the token, at 2.77:1 against the dark
   primary. Restate at matching specificity, same pattern already used below
   for .fd-nav-login. */
html[data-theme="dark"] #theme_container a.pk-strip-link { color: var(--fd-accent-on-primary, var(--fd-accent)); }
html[data-theme="dark"] #theme_container a.pk-strip-link:hover { color: var(--fd-primary-contrast); }
@media (max-width: 520px) { .pk-strip { font-size: calc(var(--fd-font-scale, 1) * .875rem); } }
</style>
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
