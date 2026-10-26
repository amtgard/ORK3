/*
 * Scrollery — measured autofit for the certificate: everything below the top half, plus
 * the gates that decide when fitting may start.
 *
 * WHAT THIS FILE IS FOR
 *
 * The certificate's text is sized by the server from formulas keyed on string length,
 * transliterated from Arcanyst. That is a guess, it is never checked, and the server has
 * no font metrics to do better. It is kept as the FIRST GUESS — a render with no
 * JavaScript still looks broadly right — and this file measures what the browser
 * actually laid out and shrinks until it fits. The failure it exists to catch is
 * invisible on screen and only exists on paper: Scrollery_render.tpl wraps each
 * certificate in `<svg style="overflow:hidden">`, so text that runs past its box is not
 * spilled where somebody would notice it, it is trimmed off.
 *
 * The citation's guess is the one worth stating, because this file re-derives it:
 *
 *     reason     font-size: max(0.55em, calc(0.8em - 0.15em * (L / 150)))
 *
 * _text.tpl stamps those numbers as data-af-len, so the formula lives in one place.
 *
 * WHAT IT DOES, per certificate (.Award)
 *
 *   top     the recipient, the order line, the issuing body and the flourishes hung on
 *           them belong to scrollery-typeset.js, which solves them together
 *           (ScrolleryTypeset.layoutTop). This file calls it and does nothing else to
 *           those blocks. The engine is required: with no engine on the page the top
 *           keeps the server's first guess and one console error says so.
 *   pass 2  the reason block — binary-search down until its own overflow:hidden box
 *           stops clipping it. A citation that still loses text is marked
 *           data-scroll-clipped, and #sc-clip-notice tells the officer.
 *   pass 3  if the whole column still overflows the page, scale the certificate's
 *           root font-size down, which takes every em on it along. The engine and the
 *           citation run once more against the new root.
 *   foot    the date and the signature, each on as few lines as the design can carry,
 *           and then the signature block's lift clear of the artwork, which measures
 *           the gap the citation left and so goes last.
 *
 * It also decides WHEN. The first pass waits for the document to be parsed, the
 * webfonts to settle and the images to decode (all five faces are font-display:block,
 * so before they land the type is not painted at all, and the engine reads the flourish
 * images' boxes). Two failsafes keep that from stalling a page: the image wait is capped,
 * and a 200-certificate bulk document stops fitting past a time budget and leaves the
 * remaining certificates on the server's guess. One certificate throwing never stops the
 * others. `ready` resolves after the first pass and never rejects; anything that prints
 * awaits it.
 *
 * IT IS IDEMPOTENT. Every element this file sizes has its first-seen size recorded once,
 * as a ratio to its parent's font-size, in data-af-base. Every run restores that baseline
 * before it fits, so running the pass twice on a 200-certificate bulk document produces
 * exactly what running it once produced — the sizes never compound. That also makes it
 * safe to call on every keystroke from the composer's live preview.
 *
 * EVERY SIZE IS WRITTEN IN `em`, NOT px. That is what lets pass 3 work at all: the
 * root scale multiplies through to the sizes the other passes chose, instead of leaving
 * them pinned at a pixel value while the page around them shrinks.
 *
 * MEASUREMENT IS IN LAYOUT PIXELS ONLY — scrollWidth / clientWidth / offsetWidth /
 * scrollHeight / clientHeight. Never getBoundingClientRect(): the certificate lives
 * inside an <svg viewBox="0 0 2550 3300" width="100%">, so a client rect comes back
 * multiplied by whatever the SVG is scaled to on screen, and mixing the two families
 * of numbers gives silent nonsense that changes with the window width. The two
 * sanctioned exceptions say what they do about it where they are: lineBoxes() reads
 * only a COUNT of rects, and the signature lift reads screen rects and converts them through
 * frameScale().
 *
 * PUBLIC API — see the bottom of the file. window.ScrolleryAutofit.
 */
(function (global, doc) {
    'use strict';

    /*
     * Per-target policy for the blocks this file fits itself.
     *
     * `lines` is the ladder of line counts the design will accept, best first, each with
     * the smallest size it is willing to pay for that many lines. Read the signer's row
     * as: "one line if that can be had at 0.55em or better; failing that two lines at
     * 0.40em or better; failing that three lines at 0.34em; failing that, the floor."
     *
     * The floors are ABSOLUTE em against the certificate root, not a fraction of the
     * server's guess, and that is deliberate. Fractions of the guess make the answer
     * depend on the guess: the composer previews a certificate the server rendered with
     * an empty string and then types into the DOM, while the print surface renders the
     * same string server-side, so the same text resolved to different sizes on the two
     * surfaces. With absolute floors the same string in the same box gets the same size
     * on both, which is the point of fitting the preview at all.
     */
    var POLICY = {
        /*
         * The date and the signature.
         *
         * Both come from the server at a flat 0.7em with no scaling of any kind, in a
         * box `section.SignAndDate > div { width: 7em }` wide. The longest date this
         * app can print — "27th September 2026" — takes THREE lines in that box, and a
         * 60-character signer takes four.
         *
         * That block is bottom-anchored (`align-items: end`), so every extra line grows
         * it UPWARD, and it is the direct cause of two of the three collision families
         * the sweep found at the foot of the page:
         *
         *   C∩A  the signature climbing into artwork that liftSignature() cannot lift
         *        it clear of, because the lift is clamped to the room the citation left
         *        and a long citation leaves none. Measured: crown 153 ink px with the
         *        signature across the laurel, dragon 72 over the knotwork, garber 41.
         *   C∩C  the block's top reaching the flavour line above it. Measured on
         *        garber, jovious and owl/cyanotype — the family nobody had reported,
         *        and the one the earlier parity method structurally could not see.
         *
         * Fitting them is the cause-level answer: fewer lines is a shorter block, a
         * shorter block reaches neither. The floors are low because these two are the
         * smallest type on the certificate by design and a date that has to be read
         * once beats a date that is printed across a laurel wreath.
         */
        date: {
            sel: '[data-scroll-text="date"]',
            lines: [
                { n: 1, floor: 0.42 },
                { n: 2, floor: 0.34 }
            ],
            minEm: 0.34
        },
        signer: {
            sel: '[data-scroll-text="givenBy"]',
            /* Two lines is the design for a signature — an officer's name and their
               title routinely take two — so the first tier is generous and the second
               is where the real work happens. */
            lines: [
                { n: 1, floor: 0.55 },
                { n: 2, floor: 0.40 },
                { n: 3, floor: 0.34 }
            ],
            minEm: 0.34
        },
        reason: {
            sel: '.Award_Reason',
            /* No line ladder: the reason's budget is its box, six ruled underlines
               deep, and its own overflow:hidden is the exact signal for "clipped".
               6,358 notes are over 120 characters, 1,658 over 200, and now that
               .Multiline finally applies white-space:pre-line the 230 notes with
               newlines in them take MORE vertical space than they used to, not less. */
            minEm: 0.34
        }
    };

    /*
     * Multi-up raises the LADDER floors — the date's and the signer's. Not the citation's;
     * see the note at the end of this comment.
     *
     * Every measurement in this file happens inside a 2550x3300 viewBox, which is
     * scale-free by construction — the whole point of the frame. That is normally a
     * feature and here it is the bug: at &up=2 or &up=4 the same certificate prints at
     * half its linear size, so a floor the policy comments justify as legible on paper
     * is half that on paper and nothing in the measurement can see the difference.
     *
     * The floors are therefore lifted by 1.5x when the page says it is a multi-up sheet.
     * NOT by the full 2x that would restore the physical size, which would put a floor
     * above the first rung of a ladder and make a block refuse every size it has.
     *
     * Raising a floor can only ever REFUSE a size, never choose a bigger one than the
     * server's guess: every use is guarded by Math.min against the base.
     *
     * Read from the document, so a surface opts in by saying what it is printing rather
     * than by calling into this file. Scrollery_render.tpl stamps data-scroll-up on
     * <body> from &up=. The engine reads the same attribute for the top half.
     *
     * WHY THE CITATION IS EXEMPT.
     *
     * A raised floor is a refusal to shrink. For the date and the signer that refusal is
     * harmless — they have a line ladder to fall down instead, and both wrap. The
     * citation has neither: its box is `overflow: hidden`, so the only thing a refusal
     * to shrink can produce is DELETED TEXT.
     *
     * Measured, before this exemption: of the 60 longest citations in the ORK, 0
     * were clipped at 1-up and 25 were clipped at &up=2 — 80 to 499 px each, up to
     * four of five lines gone. The cause was arithmetic, not geometry. The reason's
     * base is 0.55em and its floor 0.34em, so 1-up searches [0.34, 0.55] and lands
     * these citations at 0.38-0.49em. At 1.5x the floor becomes 0.51em, the range
     * collapses to [0.51, 0.55], nothing in it fits, and fitReason()'s
     * nothing-fitted branch restored the base — the size that clips MOST. The
     * certificate box was the same height either way (587 px vs 590 px on award
     * 384257); only the floor had moved.
     *
     * A citation at 0.39em on a half-scale sheet is about 4.7pt, which is small.
     * Text that is not on the page at all is smaller. The officer can read a tight
     * citation, or reprint that one 1-up; they cannot recover words the printer
     * never put down, and until now nothing told them any were missing.
     */
    var FLOOR_SCALE = (function () {
        var el = doc.querySelector('[data-scroll-up]');
        var up = el ? parseInt(el.getAttribute('data-scroll-up'), 10) : 0;
        return (up === 2 || up === 4) ? 1.5 : 1;
    }());

    function floorOf(em) {
        return em * FLOOR_SCALE;
    }

    /* Sub-pixel slack. Layout rounds; 1px of tolerance stops a shrink that buys
       nothing anyone could see. */
    var TOL = 1;

    /* How far the column may spill past .Award's content box before pass 3 will scale
       the whole certificate down. 24px of a 3300px page, absorbed by 510px of top
       padding, is not something anybody can see on paper, and pass 3 is the most
       drastic thing in this file — it should be reserved for real overflow. Several
       orders (crown for one) sit a few tens of pixels over as a matter of course,
       from the px-sized signature block their own rules pin, and no amount of type
       scaling would ever move that. */
    var COLUMN_SLACK = 24;

    /* Binary-search depth for the reason. Eight halvings of a range that is at most
       the full size resolve to well under a percent. */
    var STEPS = 8;

    /* A 200-certificate bulk document is the worst case, and every measurement forces
       a layout of a very large page. Past this budget the remaining certificates keep
       the server's first guess rather than making somebody wait at the print dialog.
       Nothing is left half-fitted: the deadline is only ever checked between whole
       certificates. */
    var BUDGET_MS = 6000;

    function px(el, prop) {
        return parseFloat(global.getComputedStyle(el)[prop]) || 0;
    }

    /*
     * The size an element is currently at, expressed the way we write sizes back:
     * as a multiple of its parent's font-size.
     */
    function currentEm(el) {
        var parent = el.parentElement;
        var parentPx = parent ? px(parent, 'fontSize') : 0;
        if (!parentPx) { return 0; }
        return px(el, 'fontSize') / parentPx;
    }

    /*
     * The server's own length formula, re-evaluated against the text actually in the
     * box.
     *
     * _text.tpl stamps `data-af-len="floor,intercept,slope,divisor,emptyLength"` —
     * the very numbers its own calc() uses, emitted as data so this file never
     * restates them and cannot drift from them.
     *
     * It is re-derived rather than read off the computed style because the composer
     * types into a preview the server rendered with a different string (usually an
     * empty one), so the computed ceiling there answers a question nobody asked.
     * Length is counted in code points ([...str]), which is what mb_strlen() counts
     * server-side — not str.length, which counts UTF-16 units and would double-count
     * exactly the astral characters mb_strlen() was introduced here to get right.
     */
    function formulaEm(el) {
        var spec = el.getAttribute('data-af-len');
        if (!spec) { return 0; }

        var n = spec.split(',').map(Number);
        if (n.length < 5 || n.some(isNaN)) { return 0; }

        var text = (el.textContent || '').trim();
        var len = Array.from(text).length;
        if (len === 0 && n[4] > 0) { len = n[4]; }

        return Math.max(n[0], n[1] + n[2] * (len / n[3]));
    }

    /*
     * The baseline this element is fitted DOWN FROM.
     *
     * A pure function of the element and its current text, so re-running the pass
     * always restores the same ceiling and the sizes never compound — that is the
     * whole of the idempotence guarantee. It is an em ratio rather than px so that
     * restoring it reproduces the server's calc() exactly (that calc() is in em too),
     * and so that pass 3's root scale carries it.
     */
    function baseEm(el) {
        var formula = formulaEm(el);
        if (formula > 0) {
            if (!el.hasAttribute('data-af-inline')) {
                el.setAttribute('data-af-inline', el.style.fontSize || '');
            }
            el.setAttribute('data-af-base', String(formula));
            return formula;
        }

        var stored = parseFloat(el.getAttribute('data-af-base'));
        if (stored > 0) { return stored; }

        var base = currentEm(el);
        if (!(base > 0)) { return 0; }
        el.setAttribute('data-af-base', String(base));
        /* The server's own inline declaration, kept verbatim so reset() can put back
           exactly what _text.tpl wrote rather than an equivalent-looking number.
           Clearing style.fontSize instead would DELETE the first guess and drop the
           element onto the stylesheet's flat .AwardeeName { font-size: 1.7em } — a
           bigger size than the guess it was supposed to be restoring. */
        el.setAttribute('data-af-inline', el.style.fontSize || '');
        return base;
    }

    function setEm(el, em) {
        el.style.fontSize = em.toFixed(4) + 'em';
    }

    /*
     * How many lines an INLINE element occupies.
     *
     * The date and the signature are the blocks this is for, and they are not always
     * block boxes with an offsetWidth worth solving from: an inline box reports 0, and
     * a box the column clips reports a width that has nothing to do with its text.
     * Counting the line boxes a Range covers answers the question directly instead.
     * The COUNT is used and never the geometry: a Range's rects are in screen pixels
     * while everything else here is in the 2550-wide layout space, and mixing those
     * two families is the bug this file's comments keep warning about. A count is
     * scale-free.
     *
     * Rects are collapsed by their top edge because a Range reports one rect per
     * fragment, not per line — a span containing markup would otherwise count high.
     */
    function lineBoxes(el) {
        var range = doc.createRange();
        range.selectNodeContents(el);

        var rects = range.getClientRects();
        var tops = [];
        for (var i = 0; i < rects.length; i++) {
            if (rects[i].width < 0.5 || rects[i].height < 0.5) { continue; }
            var top = Math.round(rects[i].top);
            if (tops.indexOf(top) === -1) { tops.push(top); }
        }
        return Math.max(1, tops.length);
    }

    /*
     * Fit an inline element by MEASURING the line count at a size rather than solving
     * for it. The ladder is the policy's: the best tier that can be had at or above its
     * floor wins, and the floor is absolute em so both surfaces agree.
     *
     * A descending binary search rather than a scan, for the same reason fitReason()
     * uses one — eight halvings resolve to well under a percent.
     */
    function fitInline(el, policy) {
        var base = baseEm(el);
        if (!(base > 0)) { return; }

        setEm(el, base);
        if (lineBoxes(el) <= policy.lines[0].n) { return; }

        for (var t = 0; t < policy.lines.length; t++) {
            var tier = policy.lines[t];
            var lo = Math.min(floorOf(tier.floor), base);
            var hi = base;
            var best = null;

            for (var i = 0; i < STEPS; i++) {
                var mid = (lo + hi) / 2;
                setEm(el, mid);
                if (lineBoxes(el) <= tier.n) {
                    best = mid;
                    lo = mid;
                } else {
                    hi = mid;
                }
            }

            if (best !== null) { setEm(el, best); return; }
        }

        /* Nothing fitted in any tier. The floor is the smallest size the design will
           pay for; below it the date or the signature stops being readable. */
        setEm(el, Math.min(base, floorOf(policy.minEm)));
    }

    function reasonTextEl(el) {
        return el.querySelector('[data-scroll-text="reason"]') || el;
    }

    /*
     * Is the citation being cut off by its own box?
     *
     * NOT `el.scrollHeight > el.clientHeight`. That was the obvious test and it is
     * wrong, measurably: the reason section also contains the absolutely-positioned
     * holder for its six ruled underline images, and that holder's own content
     * overflows it, so the section's scrollHeight is a flat ~994px on EVERY
     * certificate — a 103-character note measures the same as a 400-character one.
     * Written that way the search shrank every reason on every certificate straight
     * to the floor and still reported it clipped. Measured on live pages before and
     * after.
     *
     * The thing that can actually be clipped is the text paragraph, so measure that,
     * against the box's content height.
     */
    function reasonClipped(el, p) {
        return reasonOverflow(el, p) > TOL;
    }

    /* How much of the citation is being cut off, in px. The magnitude, not just the
       yes/no, because the nothing-fitted branch below has to choose the size that
       loses the LEAST text, and because the page says how bad it is. */
    function reasonOverflow(el, p) {
        var contentH = el.clientHeight - px(el, 'paddingTop') - px(el, 'paddingBottom');
        return Math.max(p.offsetHeight - contentH, p.scrollWidth - p.clientWidth);
    }

    function fitReason(el) {
        var base = baseEm(el);
        if (!(base > 0)) { return; }

        var p = reasonTextEl(el);

        setEm(el, base);
        if (!reasonClipped(el, p)) { markClipped(el, 0); return; }

        /* POLICY.reason.minEm raw, NOT floorOf() — the citation is exempt from the
           multi-up lift. See the FLOOR_SCALE comment for the measurement. */
        var lo = Math.min(POLICY.reason.minEm, base);
        var hi = base;
        var best = null;

        for (var i = 0; i < STEPS; i++) {
            var mid = (lo + hi) / 2;
            setEm(el, mid);
            if (reasonClipped(el, p)) {
                hi = mid;
            } else {
                best = mid;
                lo = mid;
            }
        }

        if (best !== null) {
            setEm(el, best);
            markClipped(el, 0);
            return;
        }

        /*
         * Nothing in range fitted. Take the size that loses the LEAST text.
         *
         * This used to restore the base unconditionally, on the reasoning that the
         * reason's box is `min-height: 2em` and shrinks with the text, so shrinking
         * "buys nothing and costs legibility". That is true for the pinned-box case
         * and false in general, and the two are not distinguishable by argument — so
         * measure both ends and keep the better one. On a pinned box the overflow is
         * equal and `<` keeps the base, which is the old behaviour exactly; where the
         * box is real, the floor recovers whole lines of the citation.
         *
         * Pass 3 is still the genuine fix where the COLUMN is what overflowed. It
         * does not reach this case: a citation clipped inside a box that the column
         * fits around leaves columnOverflow() at ~0, so pass 3 never fires.
         */
        setEm(el, base);
        var atBase = reasonOverflow(el, p);
        setEm(el, lo);
        var atFloor = reasonOverflow(el, p);

        if (atBase < atFloor) { setEm(el, base); }
        markClipped(el, Math.min(atBase, atFloor));
    }

    /*
     * Say so when the citation still does not fit.
     *
     * Clipping is `overflow: hidden`, which is silent by construction — the officer
     * gets a certificate whose citation stops mid-sentence and nothing anywhere says
     * words were dropped. The attribute is the signal; Scrollery_render.tpl counts
     * them and puts one notice on the page. Removed rather than set to 0 so that
     * `[data-scroll-clipped]` is a clean selector and a re-fit cannot leave a stale
     * flag on a citation that now fits.
     */
    function markClipped(el, overflowPx) {
        var award = el.closest ? el.closest('.Award') : null;

        if (overflowPx > TOL) {
            el.setAttribute('data-scroll-clipped', String(Math.round(overflowPx)));
            if (award) { award.setAttribute('data-scroll-clipped', '1'); }
        } else {
            el.removeAttribute('data-scroll-clipped');
            if (award) { award.removeAttribute('data-scroll-clipped'); }
        }
    }

    /*
     * How far the stack of text runs outside the page's text area.
     *
     * NOT `award.scrollHeight > award.clientHeight`, which never fires: .Award is
     * `justify-content: center` with padding of 20%/13% of a 2550px frame (510px top,
     * 331px bottom), so an over-full column spills symmetrically INTO that padding
     * and stays comfortably inside the padding box. Measured on a live page, a column
     * 326px over its content box still reported scrollHeight === clientHeight.
     *
     * That padding is not slack, it is the artwork: it is the inset that keeps the
     * type off the printed border and, on `zodiac`, off a much heavier frame still.
     * Text in it is text on top of the border art. So the box that matters is the
     * padding-defined content box, and the measurement is the first and last flex
     * children against its edges.
     */
    function columnOverflow(award) {
        var kids = award.children;
        if (!kids.length) { return 0; }

        var top = kids[0].offsetTop;
        var last = kids[kids.length - 1];
        var bottom = last.offsetTop + last.offsetHeight;

        return Math.max(0, px(award, 'paddingTop') - top)
            + Math.max(0, bottom - (award.clientHeight - px(award, 'paddingBottom')));
    }

    /*
     * Last resort. Everything above works inside the column; this shrinks the column's
     * own type scale, which every em on the certificate is relative to — so passes 1
     * and 2 come along for the ride rather than being invalidated.
     *
     * It converges because the things it does NOT shrink are the things that reserve
     * the page: section.SignAndDate is sized in px (350px shared, and up to 800px on
     * warrior rank 8), .Underline is 13px, and .Award's own padding is a percentage of
     * the frame's WIDTH. Only the type scales, which is the intent.
     */
    function fitColumn(award) {
        var base = baseEm(award);
        if (!(base > 0)) { return false; }

        var over = columnOverflow(award);
        if (over <= COLUMN_SLACK) { return false; }

        /* Pass 3's own floor moves with the others: scaling the whole certificate
           down is the last thing that should happen on a sheet that is already
           printing it at half size. Capped below 1 so a search range survives. */
        var floor = base * Math.min(0.95, 0.6 * FLOOR_SCALE);

        /*
         * Ask the floor first, and be willing to walk away.
         *
         * Not every overflow is made of type. `crown` pins section.SignAndDate at
         * flex-basis 450px / min-height 450px in PIXELS, and `warrior` rank 8 at
         * 800px; those do not scale with the root, so on some certificates the column
         * is a few tens of pixels over no matter what the type does. Measured on a
         * live crown certificate: 51px over at full size, and still 51px over at the
         * 0.6 floor. An unconditional search "succeeds" by handing back a certificate
         * printed 40% smaller with exactly the same overflow, which is strictly worse
         * than doing nothing. So: solve it, or leave it alone.
         */
        setEm(award, floor);
        var floorOver = columnOverflow(award);

        if (floorOver > TOL) {
            if (floorOver <= over / 2) {
                /* Cannot be cleared, but scaling still removes most of it. Keep the
                   floor — a certificate half off its margins beats one wholly off. */
                return true;
            }
            setEm(award, base);
            return false;
        }

        var lo = floor;
        var hi = base;
        var best = floor;

        for (var i = 0; i < STEPS; i++) {
            var mid = (lo + hi) / 2;
            setEm(award, mid);
            if (columnOverflow(award) > TOL) {
                hi = mid;
            } else {
                best = mid;
                lo = mid;
            }
        }
        setEm(award, best);
        return true;
    }

    /*
     * The typeset engine, or null when this page does not have one that can run.
     * Looked up on every call rather than once at load, so an engine that arrives
     * after this file (or is removed by a test) is honoured on the next fit.
     */
    function typesetEngine() {
        var t = global.ScrolleryTypeset;
        return t && typeof t.layoutTop === 'function' ? t : null;
    }

    /*
     * A page without the engine is a deployment fault (a stale cached copy of this file
     * paired with a page that never loaded the engine, or the engine's script failing to
     * arrive), and there is no second implementation to fall back to. Say so once per
     * page load — a bulk document would otherwise print it 200 times — and leave the
     * top of every certificate on the server's first guess.
     */
    var engineMissingReported = false;
    function reportMissingEngine() {
        if (engineMissingReported) { return; }
        engineMissingReported = true;
        if (global.console && typeof global.console.error === 'function') {
            global.console.error('scrollery-autofit: ScrolleryTypeset.layoutTop is missing, so '
                + 'the top of the certificate keeps the server\'s first-guess sizes. '
                + 'scrollery-typeset.js must be loaded before scrollery-autofit.js.');
        }
    }

    /*
     * Run it, and never let it take the rest of the certificate down with it. The
     * reason, the date and the signer below are still ours to fit, and fit() would
     * otherwise abandon the whole certificate on the engine's first exception. The
     * failure is left on the element, where a test or a person with devtools can see
     * which certificate it was and what it said.
     */
    function runEngine(award, engine) {
        try {
            engine.layoutTop(award);
            award.removeAttribute('data-sc-error');
        } catch (e) {
            award.setAttribute('data-sc-error', String(e && e.message ? e.message : e));
        }
    }

    /*
     * One certificate. Safe to call as often as you like.
     *
     * In order: restore the root; the engine sets the top (name, award line, issuing
     * body, ornaments); the citation is sized into the room that leaves; pass 3 scales
     * the root if the column still overflows, and if it did the engine and the citation
     * run once more against the new root; then the date and signer, and the signature
     * lift last, because it measures the gap the citation left.
     */
    function fitAward(award) {
        if (!award || !award.clientWidth) { return; }

        /* Restore the root first: everything below is measured in em against it. */
        var rootBase = baseEm(award);
        if (rootBase > 0) { setEm(award, rootBase); }

        /*
         * The top of the certificate — issuing body, name, award line, and the
         * ornaments hung on them — is the typeset engine's alone. It solves the five
         * blocks together, because they depend on each other (the award may not
         * outrank the name, the place may not outrank either, the flourishes are placed
         * against the type the others chose).
         *
         * There is no second implementation behind it. A page that never loaded the
         * engine says so once, on the console, and keeps the server's first guess for
         * the top; the bottom half below still fits, because it does not need the
         * engine.
         */
        var engine = typesetEngine();
        if (engine) {
            runEngine(award, engine);
        } else {
            reportMissingEngine();
        }

        var reason = award.querySelector(POLICY.reason.sel);
        if (reason) { fitReason(reason); }

        /*
         * Pass 3 last, and then the passes it invalidates if it fired. Shrinking the
         * root scale is the one thing that hands the column real room back, and a
         * citation that had no fitting size a moment ago very often has one now. Every
         * size the earlier passes chose is in em, so the root scale carries them down
         * with it and text that fitted still fits — in WIDTH. The engine is the
         * exception to "nothing else needs repeating": it also wrote pixel distances
         * (the place's gutter and reserve, the rules' offsets) and it judged the
         * vertical budget against the root size of that moment, so after a root scale
         * it runs again, before the reason is sized into whatever room that leaves.
         */
        if (fitColumn(award)) {
            if (engine) { runEngine(award, engine); }
            if (reason) { fitReason(reason); }
        }

        /* Before the lift, which measures the gap above a block whose height these
           two decide. A four-line signer is 150px of extra block, and liftSignature()
           would otherwise be asked to find room for it. */
        var date = award.querySelector(POLICY.date.sel);
        if (date) { fitInline(date, POLICY.date); }

        var signer = award.querySelector(POLICY.signer.sel);
        if (signer) { fitInline(signer, POLICY.signer); }

        /* Last: it measures the gap the citation left, which is only final now. */
        liftSignature(award);
    }

    /* ----------------------------------------------------------------------
     * The signature block's lift.
     *
     * Some plates put artwork exactly where the date and the signature go — crown's
     * corner ornaments and its laurel, dragon's knotwork at the top of the ladder,
     * garber's tape measure, griffon's fighters, and any of the full-page rank sets
     * on a composed scroll. scrollery.css declares how far that certificate WANTS to
     * raise its block, in --sc-sign-lift-want, from a measured sweep of every plate.
     *
     * It cannot be a static number on its own. The room above the block is whatever
     * the citation left, and the citation is the officer's text: six ruled lines of
     * it and there is no room at all. So the declared value is a ceiling, and this
     * clamps it to the gap actually present, leaving SIGN_MIN_GAP of clear paper.
     * Nothing is lifted into the citation, and a certificate with a short citation —
     * the common case — still gets the full lift.
     *
     * Runs last in fitAward(), after the citation has been sized, because the gap it
     * measures does not exist until then.
     * ---------------------------------------------------------------------- */

    /* Frame px of clear paper to keep between the citation and the block. 40px is
       1/7.5 inch at 300 dpi — enough that the two read as separate blocks. */
    var SIGN_MIN_GAP = 40;

    function signBlock(award) {
        return award.querySelector('section.SignAndDate');
    }

    /* The element the lift is declared on and set on: the certificate root, which is
       .award-template. The block is inside .Award, which is inside it. */
    function templateOf(award) {
        var el = award.parentNode;
        while (el && el.nodeType === 1) {
            if (el.classList && el.classList.contains('award-template')) { return el; }
            el = el.parentNode;
        }
        return null;
    }

    /* Screen px per frame px. Everything here is measured with getBoundingClientRect,
       which reports the SVG's scale, and compared against a value declared in frame
       px; offsetWidth is the unscaled layout width, so the ratio converts. */
    function frameScale(award) {
        var w = award.offsetWidth;
        return w > 0 ? award.getBoundingClientRect().width / w : 1;
    }

    /*
     * How far down an element is actually PAINTED, in screen px.
     *
     * getBoundingClientRect reports layout, and layout does not know about clipping:
     * the citation's six ruled underlines are laid out 145px apart whatever the
     * citation says, and the ones past the text are cut off by
     * `section.Award_Reason { overflow: hidden }` — which is exactly how the block
     * shows one rule per written line. Their rects are still down at the sixth rule,
     * so measuring rects alone said the citation reached 2707px on every certificate
     * and left no room to lift anything. It measured the invisible.
     *
     * So clamp each rect to every clipping ancestor up to the certificate.
     */
    function paintedBottom(el, stopAt) {
        var r = el.getBoundingClientRect();
        var bottom = r.bottom;
        var p = el.parentNode;
        while (p && p.nodeType === 1 && p !== stopAt) {
            var ov = global.getComputedStyle(p).overflow;
            if (ov && ov !== 'visible') {
                var pr = p.getBoundingClientRect();
                if (pr.bottom < bottom) { bottom = pr.bottom; }
            }
            p = p.parentNode;
        }
        return bottom > r.top ? bottom : null;   // clipped away entirely
    }

    /* The lowest ink on the certificate that is NOT in the signature block. Leaves
       only, and only those that draw something, so an empty wrapper stretched across
       the page (owl's filtered heraldry host is one) cannot pin the answer. */
    function lowestInkAbove(award, block) {
        var all = award.querySelectorAll('*');
        var lowest = null;
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            if (el === block || block.contains(el) || el.children.length) { continue; }
            if (el.tagName !== 'IMG' && !(el.textContent || '').trim()) { continue; }
            var r = el.getBoundingClientRect();
            if (r.width <= 0 || r.height <= 0) { continue; }
            var b = paintedBottom(el, award);
            if (b === null) { continue; }
            if (lowest === null || b > lowest) { lowest = b; }
        }
        return lowest;
    }

    /* The top of what the block actually DRAWS, which is well below the top of the
       block itself: it reserves 350-650px of height and sits its content at the end. */
    function contentTop(block) {
        var all = block.querySelectorAll('*');
        var top = null;
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            if (el.children.length) { continue; }
            if (el.tagName !== 'IMG' && !(el.textContent || '').trim()) { continue; }
            var r = el.getBoundingClientRect();
            if (r.width <= 0 || r.height <= 0) { continue; }
            if (top === null || r.top < top) { top = r.top; }
        }
        return top;
    }

    function liftSignature(award) {
        var tpl = templateOf(award);
        var block = signBlock(award);
        if (!tpl || !block) { return; }

        /* Measure at zero. The gap depends on where the block currently is, so a
           previous pass's answer has to come off first or this compounds. */
        tpl.style.setProperty('--sc-sign-lift', '0px');

        var want = parseFloat(
            global.getComputedStyle(tpl).getPropertyValue('--sc-sign-lift-want')
        ) || 0;
        if (want <= 0) { tpl.style.removeProperty('--sc-sign-lift'); return; }

        var top = contentTop(block);
        var above = lowestInkAbove(award, block);
        if (top === null) { tpl.style.removeProperty('--sc-sign-lift'); return; }

        var k = frameScale(award);
        /* Nothing above it at all (an award with no citation and no flavour): the
           whole declared lift is available. */
        var room = above === null ? want : ((top - above) / (k || 1)) - SIGN_MIN_GAP;
        var lift = Math.max(0, Math.min(want, Math.floor(room)));

        tpl.style.setProperty('--sc-sign-lift', lift + 'px');
    }

    function awardsIn(root) {
        var scope = root || doc;
        var list = [];

        if (scope.nodeType === 1 && scope.classList && scope.classList.contains('Award')) {
            list.push(scope);
        }
        if (scope.querySelectorAll) {
            Array.prototype.push.apply(list, scope.querySelectorAll('.Award'));
        }
        return list;
    }

    /*
     * Fit every certificate under `root` (default: the whole document).
     * Synchronous. Returns how many were fitted.
     */
    function fit(root) {
        var list = awardsIn(root);
        var deadline = (global.performance && global.performance.now
            ? global.performance.now()
            : Date.now()) + BUDGET_MS;
        var now = function () {
            return global.performance && global.performance.now
                ? global.performance.now()
                : Date.now();
        };
        var done = 0;

        for (var i = 0; i < list.length; i++) {
            /* Checked between certificates, never inside one, so nothing is ever left
               part-fitted. Past the budget the rest keep the server's first guess. */
            if (i > 0 && now() > deadline) { break; }
            try {
                fitAward(list[i]);
                done++;
            } catch (e) {
                /* One bad certificate must not stop the other 199, and must never
                   stop the print. */
            }
        }
        return done;
    }

    /* Put everything back to the server's first guess. Mostly for debugging. */
    function reset(root) {
        var list = awardsIn(root);
        for (var i = 0; i < list.length; i++) {
            var award = list[i];
            var els = [award].concat(Array.prototype.slice.call(
                award.querySelectorAll('[data-af-base]')
            ));
            for (var j = 0; j < els.length; j++) {
                els[j].style.fontSize = els[j].getAttribute('data-af-inline') || '';
                els[j].removeAttribute('data-af-base');
                els[j].removeAttribute('data-af-inline');
            }

            /* The clamped lift is this script's too, and leaving it behind would make
               the next measurement start from an already-raised block. */
            var tpl = templateOf(award);
            if (tpl) { tpl.style.removeProperty('--sc-sign-lift'); }
        }
    }

    /* Coalesce bursts — a live preview fires on every keystroke. */
    var pending = null;
    function fitSoon(root) {
        if (!global.requestAnimationFrame) { fit(root); return; }
        if (pending) { global.cancelAnimationFrame(pending); }
        pending = global.requestAnimationFrame(function () {
            pending = null;
            fit(root);
        });
    }

    /*
     * The first pass, gated on the webfonts (and, below, on the images).
     *
     * Measuring before the faces land measures the fallback serif, and BlackChancery
     * is nothing like a fallback serif — every size chosen would be wrong. All five
     * faces are font-display:block, so until they arrive the text is not merely the
     * wrong font, it is invisible and unmeasurable.
     *
     * document.fonts.ready settles whether the faces loaded or 404'd, so this cannot
     * hang on a missing font; the caller's own failsafe covers a browser with no
     * FontFaceSet at all.
     *
     * DOMContentLoaded is in the gate as well, and not for tidiness: this file is a
     * plain synchronous <script> at the end of <body>, and on a warm cache
     * document.fonts.ready can settle while the parser is still working through a
     * 200-certificate bulk document. Fitting then would fit whatever had been parsed
     * so far and silently leave the rest on the server's guess.
     */
    function whenParsed() {
        if (doc.readyState !== 'loading') { return Promise.resolve(); }
        return new Promise(function (resolve) {
            doc.addEventListener('DOMContentLoaded', resolve, { once: true });
        });
    }

    /*
     * Which certificates still lose citation text, after everything above has tried.
     *
     * Read off the DOM rather than accumulated in a counter, so it stays true across
     * a re-fit (the composer's live preview re-fits on every keystroke) and so a
     * caller can ask at any time rather than only at the end of a pass.
     */
    function clippedAwards(root) {
        var scope = root || doc;
        return scope.querySelectorAll
            ? Array.prototype.slice.call(scope.querySelectorAll('.Award[data-scroll-clipped]'))
            : [];
    }

    /*
     * Tell the page. `.sc-clip-notice` is emitted empty and hidden by
     * Scrollery_render.tpl; this fills it in and shows it, so a surface that does not
     * want the notice simply does not emit the element and nothing here has to know.
     */
    function reportClipping(root) {
        var el = doc.getElementById('sc-clip-notice');
        if (!el) { return; }

        var n = clippedAwards(root).length;
        if (n === 0) { el.hidden = true; el.textContent = ''; return; }

        el.textContent = n === 1
            ? 'One citation is too long for its certificate and is shown cut off. '
                + 'Print that one on its own sheet, or shorten the reason on the award record.'
            : n + ' citations are too long for their certificates and are shown cut off. '
                + 'Print those on single sheets, or shorten the reason on the award records.';
        el.hidden = false;
    }

    /*
     * The images, as well as the fonts.
     *
     * The typeset engine places the flourishes by reading their ink grids against the
     * box each image is laid out in, and an image that has not loaded has no box (or
     * the wrong one: height 0 until it decodes). It would be left where the stylesheet
     * put it and reported as placed without having been checked. So the first pass
     * waits for every <img> on the page to decode.
     *
     * A broken image rejects decode() and must not hold the page up, and an image the
     * browser is deferring (loading="lazy", offscreen in a long bulk document) never
     * settles at all, so the wait is capped: past IMAGE_WAIT_MS the pass runs with
     * what has arrived, which is what the page did before this gate existed.
     */
    var IMAGE_WAIT_MS = 4000;

    function whenImages() {
        var imgs = Array.prototype.slice.call(doc.images || []).filter(function (img) {
            return !(img.complete && img.naturalWidth > 0);
        });
        if (!imgs.length) { return Promise.resolve(); }

        var all = Promise.all(imgs.map(function (img) {
            if (typeof img.decode === 'function') {
                return img.decode().catch(function () { /* broken: nothing to wait for */ });
            }
            return new Promise(function (resolve) {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            });
        }));
        var cap = new Promise(function (resolve) { global.setTimeout(resolve, IMAGE_WAIT_MS); });
        return Promise.race([all, cap]);
    }

    var ready = Promise.all([
        whenParsed(),
        (doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve())
    ])
        .then(whenImages)
        .catch(function () { /* a font that failed to load still gets a fitted page */ })
        .then(function () {
            fit(doc);
            reportClipping(doc);
        });

    /*
     * A backstop for a face that arrives late: once the first pass is done, the next
     * time the browser finishes loading any font, fit once more. The callers that swap
     * a hand or signature in place wait on their own explicit load, so this is for the
     * ones that cannot — a face whose request began after the pass, from anywhere — and
     * costs one idempotent refit. One-shot, so it cannot become a refit-per-specimen
     * loop in the composer's picker.
     */
    ready.then(function () {
        if (!(doc.fonts && typeof doc.fonts.addEventListener === 'function')) { return; }
        doc.fonts.addEventListener('loadingdone', function () {
            fit(doc);
            reportClipping(doc);
        }, { once: true });
    });

    global.ScrolleryAutofit = {
        /* fit(root?) -> number. Synchronous, idempotent, safe to re-run. */
        fit: fit,
        /* fitSoon(root?) -> void. Same, coalesced to one animation frame. */
        fitSoon: fitSoon,
        /* fitAward(awardEl) -> void. One .Award element. */
        fitAward: fitAward,
        /* reset(root?) -> void. Back to the server's first guess. */
        reset: reset,
        /* clippedAwards(root?) -> Array<.Award>. Certificates whose citation still
           does not fit, i.e. is printing with words missing. */
        clippedAwards: clippedAwards,
        /* reportClipping(root?) -> void. Refresh #sc-clip-notice from that list.
           Call after re-fitting a preview. */
        reportClipping: reportClipping,
        /* ready: Promise, resolved after the initial pass, which waits for fonts and
           images. Anything that prints should await this. It never rejects. */
        ready: ready
    };
}(window, document));
