<?php
/**
 * Partial: steps.tpl
 * Receives: $blockFields (kicker, heading, band, steps[], cta?), UIR
 * steps[] each: n, title, body
 */
$stKicker  = $blockFields['kicker']  ?? '';
$stHeading = $blockFields['heading'] ?? '';
$stBand    = $blockFields['band']    ?? 'light';
$stSteps   = $blockFields['steps']   ?? [];
$stSteps   = is_array($stSteps) ? array_values(array_filter($stSteps, 'is_array')) : [];
$stCta     = $blockFields['cta']     ?? [];

// Nothing to say and nowhere to go → render nothing. The wrapper below paints a
// full-width band (muted vellum, or navy when $stBand is 'dark'), so an untouched
// starter block showed up as a blank colored stripe with no content at all.
// Matches the cta_band / hero_carousel / photo_mosaic pattern: silent for
// visitors, discoverable hint for the author in preview.
if (
    trim((string) $stKicker) === ''
    && trim((string) $stHeading) === ''
    && empty($stSteps)
    && trim((string) ($stCta['label'] ?? '')) === ''
) {
    if ($fdIsPreview) {
        fdEmptyBlockNotice('This steps block is empty.');
    }
    return;
}

$stIsDark  = ($stBand === 'dark');
// Light band: render via the .fd-section-muted class (no inline background) so
// the html[data-theme="dark"] override can win. The dark band keeps its inline
// navy (a deliberately dark band in either theme).
$stBandClass = $stIsDark ? '' : ' fd-section-muted';
$stBandStyle = $stIsDark ? ' style="background:var(--navy);color:var(--fd-primary-contrast);"' : '';
?>
<div class="fd-pad<?= $stBandClass ?>"<?= $stBandStyle ?>>
    <div style="text-align:center;margin-bottom:26px;">
        <?php if (!empty($stKicker)): ?>
            <?php // This block renders on EITHER band depending on the caller's
            // $stBand field (seeded park pages pass 'light'). Bare .fd-kicker is
            // var(--gold) — correct on the dark band, illegible (1.657:1) on the
            // light/muted one. Ten sibling partials that only ever render on a
            // light band hard-code "fd-kicker fd-kicker-d"; this one has to pick
            // the modifier at render time, the same way the heading two lines
            // below already conditions its color on $stIsDark. ?>
            <div class="fd-kicker<?= $stIsDark ? '' : ' fd-kicker-d' ?>" style="margin-bottom:8px;">
                <?= htmlspecialchars($stKicker, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($stHeading)): ?>
            <h2 class="fd-sec-title fd-serif" style="<?= $stIsDark ? 'color:var(--fd-primary-contrast);' : '' ?>">
                <?= htmlspecialchars($stHeading, ENT_QUOTES) ?>
            </h2>
        <?php endif; ?>
    </div>

    <?php if (!empty($stSteps)): ?>
        <?php // Clamp the desktop column count the way columns.tpl does: past four,
              // extra steps wrap onto a second row instead of shrinking every step
              // into an unreadable sliver. ?>
        <?php $stCols = max(1, min(4, count($stSteps))); ?>
        <div class="fdb-steps-grid fdb-steps-<?= (int) $stCols ?>">
            <?php foreach ($stSteps as $stStep): ?>
                <?php
                $stN     = (int)($stStep['n']     ?? 0);
                $stTitle = $stStep['title'] ?? '';
                $stBody  = $stStep['body']  ?? '';
                ?>
                <div style="text-align:center;">
                    <?php if ($stN > 0): ?>
                        <div style="width:54px;height:54px;border-radius:50%;background:var(--gold);color:#1a1205;font-weight:800;font-size:22px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                            <?= $stN ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($stTitle)): ?>
                        <div style="font-weight:700;font-size:17px;margin-bottom:6px;">
                            <?= htmlspecialchars($stTitle, ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($stBody)): ?>
                        <?php // #79: 'body' is an HTML field already run through CmsSanitizer::Clean
                              // on save; a second htmlspecialchars() here double-encoded "&" → "&amp;amp;".
                              // Emit the sanitized value raw (like rich_text). white-space:pre-line
                              // keeps authored newlines. ?>
                        <div class="<?= $stIsDark ? '' : 'fd-body-text' ?>" style="font-size:14px;white-space:pre-line;<?= $stIsDark ? 'color:var(--fd-primary-contrast);opacity:.85;' : '' ?>">
                            <?= $stBody ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($stCta['label'])): ?>
        <?php
        // A block-authored href may be the STABLE 'Page/view/{slug}' form the
        // park starter seeds (CmsSite::_sitePageHref()) rather than an already
        // site-scoped route, precisely so a site-slug rename can't strand it —
        // see fdSiteInternalHref()'s docblock in frontdoor/_helpers.tpl. Resolve
        // it here, at render time, using the CURRENT site slug.
        $stCtaHref = fdSiteInternalHref((string) ($stCta['href'] ?? ''), isset($SiteSlug) ? (string) $SiteSlug : '');
        ?>
        <div style="text-align:center;margin-top:26px;">
            <a class="fd-btn-gold" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($stCtaHref), ENT_QUOTES) ?>">
                <?= htmlspecialchars($stCta['label'], ENT_QUOTES) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
