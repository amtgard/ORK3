/*
 * Scrollery — measured text autofit for the certificate.
 *
 * WHY THIS FILE EXISTS
 *
 * _text.tpl sizes three of the certificate's text blocks from a formula keyed on
 * string length, transliterated from Arcanyst:
 *
 *     recipient  font-size: max(1.36em, calc(2.55em + -0.374em * (L / 8)))
 *     reason     font-size: max(0.55em, calc(0.8em - 0.15em * (L / 150)))
 *     order      (no scaling at all — a flat 1.5em)
 *
 * That is a guess, and it is never checked. With .Award { font-size: 75pt } (= 100px
 * inside the 2550x3300 frame) the recipient floors at 136px for any name of about 32
 * characters or more — and the ORK holds 689 personas over 32 characters, 291 over
 * 40, and a 100-character maximum, against a content column of 1683px. The order
 * line has no scaling whatever, and 195 of 4,870 catalogue names are over 24
 * characters.
 *
 * The failure is invisible on screen and only exists on paper: Scrollery_render.tpl
 * wraps each certificate in `<svg style="overflow:hidden">`, so text that runs past
 * the column is not spilled where somebody would notice it — it is trimmed off.
 *
 * So: keep the formula as the FIRST GUESS (a render with no JavaScript still looks
 * broadly right, and the server has no font metrics to do better), then measure what
 * the browser actually laid out and shrink until it fits.
 *
 * WHAT IT DOES, per certificate (.Award):
 *
 *   pass 1  recipient and order line — solve for the largest size that puts the text
 *           on as few lines as the design can carry.
 *   pass 2  the reason block — binary-search down until its own overflow:hidden box
 *           stops clipping it.
 *   pass 3  if the whole column still overflows the page, scale the certificate's
 *           root font-size down, which takes every em on it along.
 *
 * IT IS IDEMPOTENT. Every element's first-seen size is recorded once, as a ratio to
 * its parent's font-size, in data-af-base. Every run restores that baseline before it
 * fits, so running the pass twice on a 200-certificate bulk document produces exactly
 * what running it once produced — the sizes never compound. That also makes it safe
 * to call on every keystroke from the composer's live preview.
 *
 * EVERY SIZE IS WRITTEN IN `em`, NOT px. That is what lets pass 3 work at all: the
 * root scale multiplies through to the sizes passes 1 and 2 chose, instead of leaving
 * them pinned at a pixel value while the page around them shrinks.
 *
 * MEASUREMENT IS IN LAYOUT PIXELS ONLY — scrollWidth / clientWidth / offsetWidth /
 * scrollHeight / clientHeight. Never getBoundingClientRect(): the certificate lives
 * inside an <svg viewBox="0 0 2550 3300" width="100%">, so a client rect comes back
 * multiplied by whatever the SVG is scaled to on screen, and mixing the two families
 * of numbers gives silent nonsense that changes with the window width.
 *
 * PUBLIC API — see the bottom of the file. window.ScrolleryAutofit.
 */
(function (global, doc) {
    'use strict';

    /*
     * Per-target policy.
     *
     * `lines` is the ladder of line counts the design will accept, best first, each
     * with the smallest size it is willing to pay for that many lines. Read the
     * recipient's row as: "one line if that can be had at 1.15em or better; failing
     * that two lines at 0.85em or better; failing that three lines at 0.6em; failing
     * that, the floor."
     *
     * The floors are ABSOLUTE em against the certificate root, not a fraction of the
     * server's guess, and that is deliberate. Fractions of the guess make the answer
     * depend on the guess: the composer previews a certificate the server rendered
     * with an empty recipient (guess 2.55em) and then types the name into the DOM,
     * while the print surface renders the same name server-side (guess 1.36em) — so
     * the same 100-character persona resolved to 103px in one and 69px in the other.
     * Measured. With absolute floors the same string in the same box gets the same
     * size on both surfaces, which is the point of fitting the preview at all.
     *
     * 0.6em is 60px inside a 2550px-wide frame that prints at 8.5in — about 14pt on
     * paper. Below that a certificate is not worth printing and something upstream is
     * wrong.
     */
    var POLICY = {
        name: {
            sel: '.AwardeeName',
            lines: [
                { n: 1, floor: 1.15 },
                { n: 2, floor: 0.85 },
                { n: 3, floor: 0.60 }
            ],
            minEm: 0.60
        },
        order: {
            sel: '.Order',
            lines: [
                { n: 1, floor: 1.05 },
                { n: 2, floor: 0.75 },
                { n: 3, floor: 0.55 }
            ],
            minEm: 0.55
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

    /* Two lines of prose rarely break at exactly half. Assume a line packs to about
       92% before the ragged edge, so "fits in n lines" is not solved as if the text
       could be poured perfectly. */
    var LINE_FILL = 0.92;

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
     * The column the text has to fit into: the .Award content box.
     *
     * Not the element's own clientWidth. .Award is `display:flex; flex-direction:
     * column; align-items:center`, so every child is sized to its own content on the
     * cross axis and simply GROWS past the column when the text is too long — an
     * unbreakable 27-character persona makes the <p> itself 2000px wide, and
     * `scrollWidth <= clientWidth` on that <p> is perfectly true while the name hangs
     * off both sides of the page. The stable number is the container's.
     */
    function columnWidth(award) {
        return award.clientWidth - px(award, 'paddingLeft') - px(award, 'paddingRight');
    }

    /*
     * The single-line width of an element's text at its current size.
     *
     * white-space:nowrap collapses the text to one line so the measurement is the
     * max-content width, which scales linearly with font-size — so one measurement
     * answers "what size puts this on one line", and on n lines, without a search.
     *
     * It has to be measured with nowrap rather than inferred from the wrapped box,
     * because .AwardeeName carries overflow-wrap:anywhere: with that on, a name that
     * cannot fit never overflows, it silently becomes more lines, and every
     * width-overflow test comes back clean.
     */
    function maxContentWidth(el) {
        var prevWhiteSpace = el.style.whiteSpace;
        var prevWrap = el.style.overflowWrap;
        el.style.whiteSpace = 'nowrap';
        el.style.overflowWrap = 'normal';
        var w = Math.max(el.scrollWidth, el.clientWidth, el.offsetWidth);
        el.style.whiteSpace = prevWhiteSpace;
        el.style.overflowWrap = prevWrap;
        return w;
    }

    /*
     * Recipient and order line. Closed-form rather than a search, because the
     * relationship is linear: text n times narrower fits on n times fewer lines.
     */
    function fitLines(el, policy, avail) {
        var base = baseEm(el);
        if (!(base > 0) || !(avail > 0)) { return; }

        setEm(el, base);

        if (!(el.textContent || '').trim()) { return; }

        var w = maxContentWidth(el);
        if (!(w > 0)) { return; }

        for (var i = 0; i < policy.lines.length; i++) {
            var tier = policy.lines[i];
            var fill = tier.n === 1 ? 1 : LINE_FILL;
            var want = base * (tier.n * avail * fill) / w;

            if (want >= base) {
                /* The server's guess already fits in this many lines. Nothing to do —
                   and never grow past the guess: the formula is also what governs how
                   this block sits against the ones above and below it. */
                return;
            }
            if (want >= tier.floor && want >= policy.minEm) {
                setEm(el, want);
                return;
            }
        }

        setEm(el, Math.min(base, policy.minEm));
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
    function reasonTextEl(el) {
        return el.querySelector('[data-scroll-text="reason"]') || el;
    }

    function reasonClipped(el, p) {
        var contentH = el.clientHeight - px(el, 'paddingTop') - px(el, 'paddingBottom');
        return p.offsetHeight > contentH + TOL
            || p.scrollWidth > p.clientWidth + TOL;
    }

    function fitReason(el) {
        var base = baseEm(el);
        if (!(base > 0)) { return; }

        var p = reasonTextEl(el);

        setEm(el, base);
        if (!reasonClipped(el, p)) { return; }

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

        /*
         * If nothing in range fitted, put the size BACK rather than leaving it at the
         * floor. The reason's box is `min-height: 2em` and the column habitually has
         * no spare room, so the box is often pinned at exactly 2em + padding — one
         * ruled line — whichever size the text is at. Shrinking against a box that
         * shrinks with you buys nothing and costs legibility: the citation would be
         * clipped either way, so clip it at a size somebody can read.
         *
         * The genuine fix for that case is pass 3, which shrinks the certificate's
         * root scale and so gives the column real room back.
         */
        setEm(el, best === null ? base : best);
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

        var floor = base * 0.6;

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

    /* One certificate. Safe to call as often as you like. */
    function fitAward(award) {
        if (!award || !award.clientWidth) { return; }

        /* Restore the root first: everything below is measured in em against it. */
        var rootBase = baseEm(award);
        if (rootBase > 0) { setEm(award, rootBase); }

        var avail = columnWidth(award);
        if (!(avail > 0)) { return; }

        var name = award.querySelector(POLICY.name.sel);
        if (name) { fitLines(name, POLICY.name, avail); }

        var order = award.querySelector(POLICY.order.sel);
        if (order) { fitLines(order, POLICY.order, avail); }

        var reason = award.querySelector(POLICY.reason.sel);
        if (reason) { fitReason(reason); }

        /*
         * Pass 3 last, and then pass 2 once more if it fired. Shrinking the root
         * scale is the one thing that hands the column real room back, and a citation
         * that had no fitting size a moment ago very often has one now. Passes 1 and
         * 2 do not need repeating for width: every size they chose is in em, so the
         * root scale carries them down with it and text that fitted still fits.
         */
        if (fitColumn(award) && reason) {
            fitReason(reason);
        }
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
     * The first pass, gated on the webfonts.
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

    var ready = Promise.all([
        whenParsed(),
        (doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve())
    ])
        .catch(function () { /* a font that failed to load still gets a fitted page */ })
        .then(function () {
            fit(doc);
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
        /* ready: Promise, resolved after the initial fonts-gated pass. Anything that
           prints should await this. It never rejects. */
        ready: ready
    };
}(window, document));
