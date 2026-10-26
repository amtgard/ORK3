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
 *   pass 1  the top of the certificate — recipient, order line, issuing body and the
 *           flourishes hung on them. When scrollery-typeset.js is on the page, it
 *           solves all of them together (ScrolleryTypeset.layoutTop). Without it the
 *           older per-block passes run: the largest size that puts each block on as
 *           few lines as the design can carry.
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
 * ONE sanctioned exception: lineBoxes(). The issuing line is an inline <span>, which
 * has no useful offsetWidth — it reports 0 — so there is nothing in the layout-pixel
 * family to measure it with. It uses a Range's client rects to COUNT line boxes and
 * never reads their geometry, and a count is scale-free. If you find yourself wanting
 * a width out of that function, you want a different function.
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
        /*
         * The issuing line — "Amtgard and The Kingdom of Crystal Groves".
         *
         * It had no fitting pass at all until now, only the server's own length
         * formula, and that formula is FACE-BLIND: it sizes from the character count
         * and knows nothing about how wide those characters are in the hand actually
         * being printed. "The Kingdom of Crystal Groves" is one line in BlackChancery
         * and needs 2160px of a 1670px column in Uncial Antiqua — 1.29x over — so it
         * wrapped, and a wrapped right-aligned line lands on the border. Same in
         * Cormorant Unicase and Fondamento. Adding six wider display faces is what
         * made a latent problem an obvious one.
         *
         * Two lines is a legitimate answer for a genuinely long name, which is why
         * there is a second tier rather than a hard one-line rule. The floors are
         * lower than the recipient's: this is the issuing body, set above and smaller
         * than the name of the person the certificate is actually about.
         */
        issuer: {
            sel: '[data-scroll-text="issuerName"]',
            /* One line at 0.62em or better, because the longest issuer name in the
               ORK is 37 characters ("The Principality of the Crimson Sands") and in
               the widest hand that is what it takes: a 29-character name resolves to
               0.80em in Uncial Antiqua, measured, and this scales from it. 0.62em is
               62px of a 2550px frame printed at 8.5in — about 15pt, above the floor
               the recipient's own name is allowed to reach. */
            lines: [
                { n: 1, floor: 0.62 },
                { n: 2, floor: 0.50 }
            ],
            minEm: 0.50
        },
        /*
         * The issuing TITLE line — "Amtgard and the Kingdom of".
         *
         * It had no fitting pass of any kind, and it is what case zero actually turns
         * on. The issuer NAME below it has had a ladder since the hands landed; this
         * line never did, so in a wide hand it simply wraps. Measured on
         * "Kingdom" + both connectives, same content throughout:
         *
         *     chancery 163px   gotisch 174px   unicase 170px      one line
         *     cinzel   318px   uncial  320px                      TWO lines
         *
         * and the second line lands in the band #BottomTextFlourish occupies. The
         * ornament fix below keeps the swash off it either way; this stops the wrap
         * happening at all, which is the cause rather than the symptom.
         *
         * Only #IssuerTitle is shrunk, never the versal: "Amtgard" is the word the
         * composition is built around and taking it down to fit a long principality
         * name would be fixing the wrong half of the line. So this is measured on the
         * PARAGRAPH's line count and applied to the TITLE's size — see fitTitle().
         *
         * 0.55em floor: #IssuerTitle sits at 0.8em of a 1.3em line on a 312.5px root,
         * so 0.55em is about 223px in the frame — roughly 16pt on paper, still larger
         * than the citation ever prints.
         */
        title: {
            sel: '#IssuerTitle',
            block: '.IssuingTextSection > p:first-of-type',
            lines: [
                { n: 1, floor: 0.62 },
                { n: 2, floor: 0.55 }
            ],
            minEm: 0.55
        },
        /*
         * The date and the signature.
         *
         * Both are a flat 0.7em with no scaling of any kind and no fitting pass, in a
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
     * Multi-up raises the LADDER floors — the recipient and the order line. Not the
     * citation's; see the note at the end of this comment.
     *
     * Every measurement in this file happens inside a 2550x3300 viewBox, which is
     * scale-free by construction — the whole point of the frame. That is normally a
     * feature and here it is the bug: at &up=2 or &up=4 the same certificate prints
     * at half its linear size, so 0.6em, which the policy comments justify as "about
     * 14pt on paper", is 7pt on paper and nothing in the measurement can see the
     * difference.
     *
     * The floors are therefore lifted when the page says it is a multi-up sheet.
     * NOT by the full 2x that would restore the physical size: the recipient's
     * one-line tier is 1.15em, so a 2x floor of 1.20em is above it, and the effect
     * would be that no name ever shrinks and every long one is clipped instead.
     * 1.5x lands the name floor at 0.90em (about 10.5pt at half scale), which is
     * small but legible, and leaves the ladder intact.
     *
     * Raising a floor can only ever REFUSE a size, never choose a bigger one than
     * the server's guess: every use is guarded by Math.min against the base.
     *
     * Read from the document, so a surface opts in by saying what it is printing
     * rather than by calling into this file. Scrollery_render.tpl stamps
     * data-scroll-up on <body> from &up=.
     *
     * WHY THE CITATION IS EXEMPT.
     *
     * A raised floor is a refusal to shrink. For the recipient and the order line
     * that refusal is harmless — they have a line ladder to fall down instead, and
     * both wrap. The citation has neither: its box is `overflow: hidden`, so the
     * only thing a refusal to shrink can produce is DELETED TEXT.
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
        if (spec) {
            var n = spec.split(',').map(Number);
            if (n.length < 5 || n.some(isNaN)) { return 0; }

            var text = (el.textContent || '').trim();
            var len = Array.from(text).length;
            if (len === 0 && n[4] > 0) { len = n[4]; }

            return Math.max(n[0], n[1] + n[2] * (len / n[3]));
        }

        /*
         * The issuing line's formula is the other shape: `1em + 0.5em * (14 / len)`
         * shrinks as a RECIPROCAL of the length, not linearly in it, so it cannot be
         * expressed in the four numbers above. It gets its own attribute rather than
         * a fudged fit, for the same reason data-af-len exists at all — so the
         * template stays the one place these numbers live.
         *
         * `data-af-inv="floor,intercept,coeff,numerator,minLength"`, evaluated as
         * max(floor, intercept + coeff * (numerator / max(minLength, len))). The
         * minLength clamp is the server's own max(10, ...) and is load-bearing: without
         * it a two-character issuer name computes a size far above the ceiling.
         */
        spec = el.getAttribute('data-af-inv');
        if (!spec) { return 0; }

        var m = spec.split(',').map(Number);
        if (m.length < 5 || m.some(isNaN)) { return 0; }

        var chars = Array.from((el.textContent || '').trim()).length;
        return Math.max(m[0], m[1] + m[2] * (m[3] / Math.max(m[4], chars)));
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
            if (want >= floorOf(tier.floor) && want >= floorOf(policy.minEm)) {
                setEm(el, want);
                return;
            }
        }

        setEm(el, Math.min(base, floorOf(policy.minEm)));
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
    /*
     * How many lines an INLINE element occupies.
     *
     * The recipient and the order line are block <p>s, so maxContentWidth() can
     * measure them and fitLines() can solve for the size in closed form. The issuer
     * name is a <span> inside a right-aligned <p>, and an inline box has no useful
     * offsetWidth — it reported 0 — so there is nothing to solve from.
     *
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
     * How many LINES a block takes, when the block mixes font sizes.
     *
     * lineBoxes() above counts distinct rect tops, which is exactly right for the
     * issuer name — one span, one size. It is wrong for the issuing TITLE line, and
     * wrong in the direction that does damage: that paragraph is the versal span at
     * 1.15em beside #IssuerTitle at 0.8em, and two inline boxes of different sizes
     * sitting on the SAME baseline have different tops. So a perfectly good one-line
     * title counted as two, and the first version of fitTitle() dutifully shrank
     * Chancery's title to its 0.55em floor — 71.5px where the design says 104px — on
     * every certificate that never had a problem.
     *
     * Clustering by half a line-height is what separates "a smaller word on this line"
     * from "the next line". The rows are far enough apart for the distinction to be
     * unambiguous: line-height here is 143px and the largest within-line top
     * difference is the versal's, about 20px.
     */
    function lineRows(el) {
        var range = doc.createRange();
        range.selectNodeContents(el);

        var lh = px(el, 'lineHeight');
        if (!(lh > 0)) { lh = px(el, 'fontSize') * 1.2; }

        /*
         * THE TOLERANCE HAS TO BE IN THE SAME UNITS AS THE RECTS.
         *
         * getComputedStyle gives layout px; getClientRects gives those multiplied by
         * whatever the <svg viewBox="0 0 2550 3300"> is scaled to on screen. This
         * file's header says never to mix the two families, and mixing them here was
         * silent rather than obviously broken: at a third scale the real gap between
         * two lines is about 46px against an unscaled tolerance of 71.5, so every
         * wrapped title clustered into one row and fitTitle() stopped firing on
         * exactly the hands it exists for.
         *
         * Derived from this element rather than passed in, so the function cannot be
         * called from somewhere that forgets to supply it.
         */
        var box = el.getBoundingClientRect();
        var k = el.offsetHeight > 0 ? (box.height / el.offsetHeight) : 1;
        var tol = lh * 0.5 * (k > 0 ? k : 1);

        var rects = range.getClientRects();
        var rows = [];
        for (var i = 0; i < rects.length; i++) {
            if (rects[i].width < 0.5 || rects[i].height < 0.5) { continue; }
            /* The BOTTOM, not the top: inline boxes of different sizes on one line
               share a baseline, so their bottoms agree far more closely than their
               tops do. */
            var b = rects[i].bottom;
            var seen = false;
            for (var j = 0; j < rows.length; j++) {
                if (Math.abs(rows[j] - b) < tol) { seen = true; break; }
            }
            if (!seen) { rows.push(b); }
        }
        return Math.max(1, rows.length);
    }

    /*
     * Fit an inline element by MEASURING the line count at a size rather than solving
     * for it. Same ladder semantics as fitLines(): the best tier that can be had at or
     * above its floor wins, and the floor is absolute em so both surfaces agree.
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
           pay for; below it the issuing line stops being readable. */
        setEm(el, Math.min(base, floorOf(policy.minEm)));
    }

    /*
     * Fit the issuing title by measuring the LINE it is part of.
     *
     * fitInline() measures the element it shrinks, which is right for the issuer name
     * — that span is the whole line. It is wrong here: the line is the versal plus the
     * title, the versal is not allowed to move, and a title that is one line box on
     * its own can still be the reason the paragraph is two.
     *
     * So the measurement and the adjustment are on different elements. Everything else
     * — absolute em floors, restore-then-fit, the descending binary search — is the
     * same discipline as fitInline(), for the same reasons.
     */
    function fitTitle(award, policy) {
        var el = award.querySelector(policy.sel);
        var block = award.querySelector(policy.block);
        if (!el || !block) { return; }

        var base = baseEm(el);
        if (!(base > 0)) { return; }

        setEm(el, base);
        if (lineRows(block) <= policy.lines[0].n) {
            /* Nothing to fit. Put back exactly what the server wrote rather than an
               equivalent-looking inline value: leaving `0.8000em` behind is harmless
               here but it is the kind of residue that makes a later diff of the
               rendered DOM impossible to read. */
            el.style.fontSize = el.getAttribute('data-af-inline') || '';
            return;
        }

        for (var t = 0; t < policy.lines.length; t++) {
            var tier = policy.lines[t];
            var lo = Math.min(floorOf(tier.floor), base);
            var hi = base;
            var best = null;

            for (var i = 0; i < STEPS; i++) {
                var mid = (lo + hi) / 2;
                setEm(el, mid);
                if (lineRows(block) <= tier.n) {
                    best = mid;
                    lo = mid;
                } else {
                    hi = mid;
                }
            }

            if (best !== null) { setEm(el, best); return; }
        }

        /* Two lines is a legitimate answer for a genuinely long issuing body, and the
           ornament anchor below is what keeps the swash off the second one. Going
           below the floor to avoid it would trade a solved problem for an unreadable
           line. */
        setEm(el, Math.min(base, floorOf(policy.minEm)));
    }

    function reasonTextEl(el) {
        return el.querySelector('[data-scroll-text="reason"]') || el;
    }

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
     * lift last, because it measures the gap the citation left. With no engine on the
     * page the first step is the old passes instead (name, award line, title, issuer)
     * and the swash anchor runs after the text passes.
     */
    function fitAward(award) {
        if (!award || !award.clientWidth) { return; }

        /* Restore the root first: everything below is measured in em against it. */
        var rootBase = baseEm(award);
        if (rootBase > 0) { setEm(award, rootBase); }

        var avail = columnWidth(award);
        if (!(avail > 0)) { return; }

        /*
         * The top of the certificate — issuing body, name, award line, and the
         * ornaments hung on them — is the typeset engine's. It solves the five blocks
         * together, because they depend on each other (the award may not outrank the
         * name, the place may not outrank either, the flourishes are placed against the
         * type the others chose) and the old passes below each solved their own block
         * with no knowledge of the rest.
         *
         * The old passes stay, behind this test, for the one case the engine can be
         * missing: a stale cached copy of this file paired with a page that never
         * loaded the engine. They run unchanged there. A later task deletes them.
         */
        var engine = typesetEngine();
        if (engine) {
            runEngine(award, engine);
        } else {
            var name = award.querySelector(POLICY.name.sel);
            if (name) { fitLines(name, POLICY.name, avail); }

            var order = award.querySelector(POLICY.order.sel);
            if (order) { fitLines(order, POLICY.order, avail); }

            /* Before the reason, and measured rather than solved — see fitInline(). It
               takes no `avail`: the width it has to fit into is its own <p>'s content box,
               which is not the .Award column (the issuing section overhangs it), and the
               line-box count already accounts for that without anyone computing it. */
            /* Before the issuer NAME: the name's paragraph sits directly under this line,
               so its position — and the ornament anchored to it — depends on how many
               lines this one ends up taking. */
            fitTitle(award, POLICY.title);

            var issuer = award.querySelector(POLICY.issuer.sel);
            if (issuer) { fitInline(issuer, POLICY.issuer); }
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

        /* After every text pass: the block it follows has finished moving only now. The
           engine places every flourish itself, against the type it just set, so this
           only runs when there is no engine. */
        if (!engine) { anchorOrnaments(award); }

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

    /* ----------------------------------------------------------------------
     * The ornament anchor.
     *
     * #BottomTextFlourish is the swash beside the issuing body's NAME — that is what
     * it decorates, and it is why `section.IssuingTextSection > p:nth-of-type(2)`
     * carries a 4.4em left gutter while the line above it carries none.
     *
     * But it is POSITIONED against the "Amtgard" span, which is the FIRST line. Those
     * are the same place only while the title line takes exactly one line, and that is
     * the whole defect: measured on "Kingdom" + both connectives, the title line is
     * 163px in Chancery and 318px in Cinzel, so in a wide hand its second line drops
     * into the swash's band — a band with no gutter, because the gutter was put on the
     * element the swash was never anchored to.
     *
     * An ornament anchored to the block it decorates cannot be crossed by that block.
     * So this measures where the name paragraph actually ended up and re-offsets the
     * swash from it, preserving the gap the design has in the one-line case.
     *
     * WITH THE SCRIPT OFF the CSS default applies unchanged, which is exactly what
     * ships today — correct for the common case and wrong only where it was already
     * wrong. Same contract --sc-sign-lift-want has with --sc-sign-lift.
     *
     * CLAMPED, like the signature lift: --sc-orn-travel-max is how far an ornament may
     * be moved from where it was drawn. Past that the composition is asking for more
     * than the ornament can give and the answer has to come from the type instead,
     * which is what fitTitle() above is for.
     * ---------------------------------------------------------------------- */

    /* The gap between the name paragraph's top and the swash's top, in the swash's own
       em. Measured off the shipping one-line layout, which is the design: the swash box
       starts 20 frame px above the name paragraph at an em basis of 149.4 frame px. */
    var ORN_BOTTOM_GAP_EM = -0.134;

    function anchorOrnaments(award) {
        var img = award.querySelector('#BottomTextFlourish');
        var name = award.querySelector('.IssuingTextSection > p:nth-of-type(2)');
        if (!img || !name) { return; }

        var host = img.offsetParent;
        if (!host) { return; }

        /* Restore the declared value before measuring: the offset is relative to where
           the ornament currently IS, so a previous run's answer has to come off first
           or this compounds. Same discipline as liftSignature(). */
        img.style.removeProperty('top');

        var em = px(img, 'fontSize');
        if (!(em > 0)) { return; }

        var k = frameScale(award) || 1;
        var declaredTop = parseFloat(global.getComputedStyle(img).top) || 0;

        /* getBoundingClientRect is scaled by the SVG; the offsets written back are not.
           Divide by k to get back into the frame's own pixels. */
        var gap = (name.getBoundingClientRect().top - host.getBoundingClientRect().top) / k;
        var want = gap + (ORN_BOTTOM_GAP_EM * em);

        var maxTravel = (parseFloat(
            global.getComputedStyle(templateOf(award) || award)
                .getPropertyValue('--sc-orn-travel-max')
        ) || 2.2) * em;

        var travel = want - declaredTop;
        if (travel > maxTravel) { want = declaredTop + maxTravel; }
        if (travel < -maxTravel) { want = declaredTop - maxTravel; }

        img.style.top = (want / em).toFixed(4) + 'em';
    }

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
