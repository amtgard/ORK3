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
