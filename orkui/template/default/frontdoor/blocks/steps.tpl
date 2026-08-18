<?php
/**
 * Partial: steps.tpl
 * Receives: $blockFields (kicker, heading, band, steps[], cta?), UIR
 * steps[] each: n, title, body
 */
$kicker  = $blockFields['kicker']  ?? '';
$heading = $blockFields['heading'] ?? '';
$band    = $blockFields['band']    ?? 'light';
$steps   = $blockFields['steps']   ?? [];
$cta     = $blockFields['cta']     ?? [];

$isDark  = ($band === 'dark');
// Light band: render via the .fd-section-muted class (no inline background) so
// the html[data-theme="dark"] override can win. The dark band keeps its inline
// navy (a deliberately dark band in either theme).
$bandClass = $isDark ? '' : ' fd-section-muted';
$bandStyle = $isDark ? ' style="background:var(--navy);color:var(--fd-primary-contrast);"' : '';
?>
<div class="fd-pad<?= $bandClass ?>"<?= $bandStyle ?>>
    <div style="text-align:center;margin-bottom:26px;">
        <?php if (!empty($kicker)): ?>
            <?php // Fix round 2 (Task 10 review, Finding C): this block renders on
            // EITHER band depending on the caller's $band field (seeded park pages
            // pass 'light'). Bare .fd-kicker is var(--gold) — correct on the dark
            // band, illegible (1.657:1) on the light/muted one. Ten sibling
            // partials that only ever render on a light band hard-code
            // "fd-kicker fd-kicker-d"; this one has to pick the modifier at
            // render time, the same way the heading two lines below already
            // conditions its color on $isDark. ?>
            <div class="fd-kicker<?= $isDark ? '' : ' fd-kicker-d' ?>" style="margin-bottom:8px;">
                <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($heading)): ?>
            <h2 class="fd-sec-title fd-serif" style="<?= $isDark ? 'color:var(--fd-primary-contrast);' : '' ?>">
                <?= htmlspecialchars($heading, ENT_QUOTES) ?>
            </h2>
        <?php endif; ?>
    </div>

    <?php if (!empty($steps)): ?>
<?php // Emit this block's static CSS at most once per request (dedupes repeats). ?>
<?php if (empty($fdStyleOnce['steps'])) : $fdStyleOnce['steps'] = true; ?>
        <style>
            @media (max-width:760px){.fdb-steps-grid{grid-template-columns:repeat(2,1fr) !important;}}
            @media (max-width:480px){.fdb-steps-grid{grid-template-columns:1fr !important;}}
        </style>
<?php endif; ?>
        <div class="fdb-steps-grid" style="display:grid;grid-template-columns:repeat(<?= count($steps) ?>,1fr);gap:20px;">
            <?php foreach ($steps as $step): ?>
                <?php
                $n     = (int)($step['n']     ?? 0);
                $title = $step['title'] ?? '';
                $body  = $step['body']  ?? '';
                ?>
                <div style="text-align:center;">
                    <?php if ($n > 0): ?>
                        <div style="width:54px;height:54px;border-radius:50%;background:var(--gold);color:#1a1205;font-weight:800;font-size:22px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                            <?= $n ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($title)): ?>
                        <div style="font-weight:700;font-size:17px;margin-bottom:6px;">
                            <?= htmlspecialchars($title, ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($body)): ?>
                        <?php // #79: 'body' is an HTML field already run through CmsSanitizer::Clean
                              // on save; a second htmlspecialchars() here double-encoded "&" → "&amp;amp;".
                              // Emit the sanitized value raw (like richtext). white-space:pre-line
                              // (#87) keeps authored newlines. ?>
                        <div class="<?= $isDark ? '' : 'fd-body-text' ?>" style="font-size:14px;white-space:pre-line;<?= $isDark ? 'color:var(--fd-primary-contrast);opacity:.85;' : '' ?>">
                            <?= $body ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($cta['label'])): ?>
        <?php
        // A block-authored href may be the STABLE 'Page/view/{slug}' form the
        // park starter seeds (CmsSite::_sitePageHref()) rather than an already
        // site-scoped route, precisely so a site-slug rename can't strand it —
        // see fdSiteInternalHref()'s docblock in frontdoor/_helpers.tpl. Resolve
        // it here, at render time, using the CURRENT site slug.
        $ctaHref = fdSiteInternalHref((string) ($cta['href'] ?? ''), isset($SiteSlug) ? (string) $SiteSlug : '');
        ?>
        <div style="text-align:center;margin-top:26px;">
            <a class="fd-btn-gold" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($ctaHref), ENT_QUOTES) ?>">
                <?= htmlspecialchars($cta['label'], ENT_QUOTES) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
