<?php
/**
 * In-document SVG filters the certificates reference by id.
 *
 * Shared by every page that renders a scroll, because a filter referenced by
 * `url(#id)` resolves against the DOCUMENT, not the stylesheet — so a page that
 * loads scrollery.css but omits these defs paints the unfiltered image instead,
 * silently. That is exactly what happened to the composer's live preview: the
 * print route carried the defs and the create page did not, so a dark-ground
 * certificate previewed a white heraldry plate and printed correct line art.
 *
 * Defined ONCE per page, not per certificate: duplicate filter ids resolve to
 * the first in the document, so extra copies are dead weight on a bulk print.
 *
 * Only the filters actually used are copied over from
 * arc-scroll/src/resources/filters.html — Arcanyst's animated `arcanyst` glow
 * filters are chrome for its own UI and carry two indefinite SMIL animations each.
 */
?>
<svg version="1.1" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
     style="visibility:hidden;position:fixed;top:0;left:0;width:0;height:0;">
    <defs>
        <filter id="OWL_edge-detect">
            <feFlood x="-10%" y="-10%" width="120%" height="120%" flood-color="white" flood-opacity="1" result="flood"></feFlood>
            <feGaussianBlur stdDeviation="1" in="SourceGraphic" />
            <feComposite x="-10%" y="-10%" width="120%" height="120%" in2="flood" mode="over"></feComposite>
            <feConvolveMatrix x="-10%" y="-10%" width="120%" height="120%" order="3 3" preserveAlpha="true" edgeMode="none" kernelMatrix="0 -2 0 -2 8 -2 0 -2 0"/>
            <feColorMatrix
                type="matrix"
                values="0 0 0 0 1
                        0 0 0 0 1
                        0 0 0 0 1
                        1 1 1 0 0" />
        </filter>
    </defs>
</svg>
<?php /*
    The knotwork border's symbol library, hoisted onto the page beside the filters.

    Same rule, same seam, a much larger number: the library is ~48 KB and every
    `custom` scroll references it — custom being the generic fallback, so the worst
    real event (391 awards, all Custom Award) used to carry it once per certificate.

    It rides along HERE rather than as a second page-level call because every page
    that renders a scroll already calls this partial exactly once, at page level,
    which is precisely the guarantee the border defs need — and one seam is one
    thing to remember rather than two. It is not merely an optimisation to hoist
    them: Scroll::Render() caches composed certificates, so a certificate partial
    that decided for itself whether to include the library would cache that decision
    and serve it to pages where it was wrong. See _border_defs.tpl.
*/ ?>
<?php scroll_partial('border_defs'); ?>
<?php /*
    The Custom Border's knot, on the same seam for the same reason: _knot.tpl emits only
    <use> references, so the symbols must already be on the page, put there by the page
    and never by a (cached) certificate. See _knot_defs.tpl.
*/ ?>
<?php scroll_partial('knot_defs'); ?>
