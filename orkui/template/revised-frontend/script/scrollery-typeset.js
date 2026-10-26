/*
 * Scrollery — the typeset engine for the top of the certificate (design spec §6).
 *
 * WHY THIS FILE EXISTS
 *
 * The top half of the certificate (name, award, issuing place) was sized by three
 * unrelated rules and broken by `text-wrap: balance` alone. Measured on 50 real
 * combinations: the award out-sized the name on 31, the place was as large as the name
 * or larger on 14, and lines broke after "of", inside quoted nicknames and on single
 * words left alone (spec §1, D1 and D5). This engine decides the breaks and the sizes
 * of those blocks together, from measured word widths and the artwork's free space,
 * and writes the answer once.
 *
 * TWO LAYERS
 *
 * The pure core is arithmetic on strings and numbers: it touches no DOM, no `window` and
 * no `document`, which is what lets a Node test drive it in milliseconds without the app
 * up. The DOM layer, at the bottom of the file, measures and writes. The one rule for
 * both: reach for `window` / `document` only inside a function, never at load, or
 * `require()` under Node dies. The file loads both ways:
 *
 *     browser   window.ScrolleryTypeset
 *     Node      require('.../scrollery-typeset.js')
 *
 * WHAT IS IN THE CORE
 *
 *   CONST        the role table and every tunable number, copied from spec §6.4.1 and
 *                §6.3.2 and awaiting calibration against the sweep. They are plain data
 *                on purpose, so a calibration run edits a value and nothing else.
 *   tokenize     text -> words, with protected spans held together and the honorific
 *                and epithet roles marked (§6.3.1).
 *   breakPenalty what it costs to break a line in the gap after a word (§6.3.2).
 *   utility      what a size is worth: 100 at the role's `sat`, 120 at its `max`
 *                (§6.3.3).
 *   solveBlock   one block's breaks and size together. It enumerates every way to cut
 *                the words into lines, finds the largest size each fits at under the
 *                artwork's free widths, scores them and returns the best (§6.3.3,
 *                §6.3.4, §6.4.3). When nothing fits it cuts over-long words and tries
 *                again, and when that fails it returns the words as written at the
 *                minimum size, flagged.
 *
 * WHAT IS IN THE DOM LAYER
 *
 *   readContext  the column, the root size, the multi-up floor scale, the free-space
 *                profile and the forced heading, read once per certificate.
 *   measureWords the width of each word in em at 1px, from a hidden span, cached per page.
 *   freeWidth    the free width of a band of the page, from the profile (§7.1).
 *   layoutTop    the entry point: sets the name, then the award under the hierarchy of
 *                §6.4.2, writes explicit lines, verifies them against the browser and
 *                writes the report (§6.8). The issuing place is not here yet; the
 *                spec's other pieces (composition, ornament placement, the budget) build
 *                on this and are not in the file yet.
 *
 * CHARACTERS ARE COUNTED WITH Array.from(), NEVER .length. `.length` counts UTF-16 code
 * units, so an astral character (an emoji in a persona is legal) counts twice, and
 * "a lone initial" or "a quote that opens a word" would be judged on the wrong text.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScrolleryTypeset = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /*
     * Spec §6.4.1. Sizes are in em of the `.Award` root (100px at 1-up before the
     * pass-3 root scale), so they ride along with that scale exactly as autofit's do.
     *
     * `linePenalty` is indexed by line count minus one: linePenalty[0] is the price of
     * one line, [1] of two, [2] of three.
     *
     * Two cells of the spec table have no plain number:
     *   - award `maxLines` is 2, rising to 3 for an award name over 40 characters;
     *     AWARD_LONG carries that rule beside the table.
     *   - the lead (the connective, "and the Barony of") is sized by hierarchy, not
     *     scored, so it has no `sat` and no line price. `sat` is null so a caller that
     *     feeds it to the utility function fails loudly instead of quietly producing
     *     NaN, and `linePenalty` is the single zero a one-line role needs.
     */
    var CONST = {
        ROLES: {
            name:          { min: 0.80, max: 2.30, sat: 1.60, maxLines: 3, lineHeight: 1.12, linePenalty: [0, 30, 60] },
            award:         { min: 0.70, max: 1.50, sat: 1.20, maxLines: 2, lineHeight: 1.10, linePenalty: [0, 22, 50] },
            placeClassic:  { min: 0.62, max: 1.30, sat: 1.00, maxLines: 1, lineHeight: 1.10, linePenalty: [0] },
            placeCentered: { min: 0.62, max: 1.30, sat: 1.00, maxLines: 2, lineHeight: 1.10, linePenalty: [0, 25] },
            lead:          { min: 0.70, max: 1.04, sat: null, maxLines: 1, lineHeight: 1.10, linePenalty: [0] }
        },
        AWARD_LONG: { chars: 40, maxLines: 3 },

        /* The "Amtgard" versal: clamp(nameSize, min, max), the one display element
           allowed to out-size the name, and only down to this floor (§6.4.2). */
        VERSAL: { min: 1.20, max: 1.495 },

        SLACK: 0.025,             /* fraction of free width held back from every line */
        RAGGED: 30,               /* weight of the line-length-evenness term */
        WIDOW: 40,                /* a lone short word on a line of its own */
        MINOR_SCALE: 0.72,        /* a minor line (honorifics, epithet) is set at this fraction */
        MINOR_PENALTY: 6,
        MINOR_LINES: true,
        CLASSIC_PLACE_MIN: 0.85,  /* floor for the place in the Classic composition */
        ORN_GAP: 0.12,            /* em of clearance kept between text ink and an ornament */
        H_TOP_MIN: 0.40,          /* share of the content height the top block must keep */

        /* Hierarchy (§6.4.2): each block's ceiling, as a fraction of the one above. */
        RATIOS: { awardToName: 0.85, placeToName: 0.75, placeToAward: 0.90 },

        /* Break penalties (§6.3.2). Positive discourages a break in the gap, negative
           invites one. They are additive. */
        P_WEAK_END: 60,           /* the word before the gap is a connective: "of |" */
        P_PUNCT: -20,             /* the word before the gap ends , ; : */
        P_HON_END: -25,           /* the gap closes the leading honorific run */
        P_HON_SPLIT: 30,          /* the gap splits the leading honorific run */
        P_EPITHET: -8,            /* the word after the gap starts an epithet */
        P_SUFFIX: 50,             /* the word after the gap is "III" / "Jr." */
        P_INITIAL: 40,            /* the word before the gap is a lone initial "T." */
        P_AWARD_BEFORE_OF: -10,   /* award role: the word after the gap is "of" */

        /* The leading honorific run (§6.3.1). "Grand" is handled separately: it counts
           only before Duke, Duchess, Marquis or Master (GRAND_RANKS, below). */
        HONORIFICS: [
            'Sir', 'Dame', 'Lord', 'Lady', 'Squire', 'Page', 'Master', 'Mistress',
            'Duke', 'Duchess', 'Archduke', 'Archduchess', 'Baron', 'Baroness',
            'Baronet', 'Baronetess', 'Count', 'Countess', 'Earl', 'Marquis',
            'Marquess', 'Marchioness', 'Viscount', 'Viscountess', 'Prince', 'Princess',
            'King', 'Queen', 'Emperor', 'Empress', 'Captain', 'Syr', 'Dom', 'Don',
            'Knight'
        ],

        /* Words a line must not end on (§6.3.2): articles, prepositions and the
           particles that introduce a surname. Matched lowercase, punctuation stripped. */
        WEAK_END: [
            'of', 'the', 'and', 'a', 'an', 'de', 'du', 'des', 'del', 'della', 'di', 'da',
            'van', 'von', 'der', 'den', 'ter', 'av', 'af', 'la', 'le', 'les', 'el', 'al',
            'y', 'e', 'ibn', 'bin', 'bint', 'mac', 'mc', 'to', 'for', 'at', 'in', 'on',
            'with', 'by', '&'
        ],

        /* A word that belongs to the name before it: generational and roman-numeral
           suffixes, I through X. Matched exactly (and with a trailing , ; : removed):
           a lowercase "i" or "v" is an ordinary word and must not count. "Jr" and "Sr"
           are listed with and without the period because people type both. */
        SUFFIXES: [
            'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X',
            'Jr.', 'Sr.', 'Jr', 'Sr'
        ]
    };

    var GRAND_RANKS = ['Duke', 'Duchess', 'Marquis', 'Master'];

    /* The no-break space that holds a protected span together, written as an escape
       because the literal character is invisible in review. */
    var NBSP = '\u00a0';

    /* A list as a lookup. The word lists are fixed at load; only the numbers above are
       meant to be edited in place. */
    function setOf(list) {
        var set = Object.create(null);
        for (var i = 0; i < list.length; i++) { set[list[i]] = true; }
        return set;
    }

    var HONORIFIC_SET = setOf(CONST.HONORIFICS);
    var GRAND_SET = setOf(GRAND_RANKS);
    var WEAK_END_SET = setOf(CONST.WEAK_END);
    var SUFFIX_SET = setOf(CONST.SUFFIXES);

    /*
     * The punctuation that dresses a word without being part of it: quotes, brackets,
     * sentence punctuation, dashes and the ellipsis. "&" is deliberately NOT here — it
     * is itself a WEAK_END word and stripping it would leave nothing to match.
     * Escapes, not literals, so a curly quote cannot be mistaken for a straight one in
     * review: \u201c \u201d \u2018 \u2019 are the curly quotes, \u2026 the ellipsis and
     * \u2013 \u2014 the en and em dashes.
     */
    var EDGE_PUNCT = /^[\s"'\u201c\u201d\u2018\u2019()\[\]{}<>,.;:!?*\u2026\u2013\u2014-]+|[\s"'\u201c\u201d\u2018\u2019()\[\]{}<>,.;:!?*\u2026\u2013\u2014-]+$/g;

    function stripEdges(text) {
        return text.replace(EDGE_PUNCT, '');
    }

    /* "(lowercased, punctuation stripped)" — the WEAK_END and "of" key. */
    function weakKey(text) {
        return stripEdges(text).toLowerCase();
    }

    /* "matched without surrounding punctuation and with the first letter
       capitalised" — the HONORIFICS key. Only the first letter is touched, so "sir"
       and "Sir" both match and "SIR" does not. */
    function honorificKey(text) {
        var cs = Array.from(stripEdges(text));
        if (cs.length === 0) { return ''; }
        return cs[0].toUpperCase() + cs.slice(1).join('');
    }

    function isWeakEnd(text) {
        return WEAK_END_SET[weakKey(text)] === true;
    }

    function isSuffix(text) {
        return SUFFIX_SET[text.replace(/[,;:]+$/, '')] === true;
    }

    /* A lone initial is exactly one capital letter and a period: "T.". Case is judged
       by comparing the character with its own case-folds, which holds for any script
       with case (an accented capital counts) and rules out "1." and "a.". */
    function isInitial(text) {
        var cs = Array.from(text);
        return cs.length === 2 && cs[1] === '.'
            && cs[0] === cs[0].toUpperCase() && cs[0] !== cs[0].toLowerCase();
    }

    /* ------------------------------------------------------------------ tokenize */

    var QUOTE_CLOSER = {
        '"': '"',
        '“': '”',
        '\'': '\'',
        '‘': '’'
    };
    var BRACKET_CLOSER = { '(': ')', '[': ']' };

    /* What may follow a closing quote and still leave it closing a word. */
    var AFTER_CLOSER = ',.;:!?)]}';

    /*
     * Does the character at `i` open a protected span that actually closes somewhere
     * later? Returns the index of its closer, or -1.
     *
     * A quote opens only at the start of a word and closes only at the end of one. That
     * is what keeps the apostrophe in Ma'at, O'Brien and Dars' from swallowing the rest
     * of the name: none of them opens a word. A bracket protects wherever it opens, and
     * counts nesting of its own kind so "(a (b) c)" is one span, not "(a (b)" and "c)".
     * An opener with no closer is ordinary text — the quote is "matching", or it is
     * nothing.
     */
    function closerOf(cs, i) {
        var open = cs[i], close, j, depth;

        if (BRACKET_CLOSER[open]) {
            close = BRACKET_CLOSER[open];
            depth = 0;
            for (j = i + 1; j < cs.length; j++) {
                if (cs[j] === open) { depth++; }
                else if (cs[j] === close) {
                    if (depth === 0) { return j; }
                    depth--;
                }
            }
            return -1;
        }

        close = QUOTE_CLOSER[open];
        if (!close) { return -1; }
        if (i > 0 && cs[i - 1] !== ' ') { return -1; }            // does not open a word
        if (i + 1 >= cs.length || cs[i + 1] === ' ') { return -1; } // nothing to hold
        for (j = i + 1; j < cs.length; j++) {
            if (cs[j] !== close || cs[j - 1] === ' ') { continue; }
            var next = cs[j + 1];
            if (next === undefined || next === ' ' || AFTER_CLOSER.indexOf(next) >= 0) { return j; }
        }
        return -1;
    }

    /* Split on spaces, keeping each protected span in one token, its inner spaces
       turned into no-break spaces so that nothing downstream can break it either. */
    function splitWords(text) {
        var cs = Array.from((text == null ? '' : String(text)).replace(/\s+/g, ' ').replace(/^ | $/g, ''));
        var words = [], word = '', i = 0, j;

        while (i < cs.length) {
            if (cs[i] === ' ') {
                if (word) { words.push(word); word = ''; }
                i++;
                continue;
            }
            j = closerOf(cs, i);
            if (j >= 0) {
                word += cs.slice(i, j + 1).join('').replace(/ /g, NBSP);
                i = j + 1;
                continue;
            }
            word += cs[i];
            i++;
        }
        if (word) { words.push(word); }
        return words;
    }

    /*
     * How many leading words are the honorific run. A run counts only if a
     * non-honorific word follows it, so "Lady" alone is a name, not a title. "Grand" is
     * in the run only when the next word is one of GRAND_RANKS ("Grand Duke"), and
     * "Grand Hall" is a name.
     */
    function honorificRun(words) {
        var n = 0, key;
        while (n < words.length) {
            key = honorificKey(words[n]);
            if (HONORIFIC_SET[key] === true) { n++; continue; }
            if (key === 'Grand' && n + 1 < words.length
                && GRAND_SET[honorificKey(words[n + 1])] === true) { n++; continue; }
            break;
        }
        return n < words.length ? n : 0;
    }

    /*
     * Where the epithet starts, or -1. Two signs of one: the word after the first word
     * that ends in a comma ("Stormcrow Giggleshadows, | the Relentless"), or a
     * lowercase "the" past the first word ("Ravenge | the Renegade"). When a name has
     * both, the earlier one starts the epithet — "Ravenge the Renegade, Slayer" is one
     * epithet, not a name followed by a one-word epithet — and exactly one word is
     * marked.
     */
    function epithetStart(words) {
        var at = -1, i;
        for (i = 0; i < words.length - 1; i++) {
            if (/,$/.test(words[i])) { at = i + 1; break; }
        }
        for (i = 1; i < words.length; i++) {
            if (words[i] === 'the') {
                if (at < 0 || i < at) { at = i; }
                break;
            }
        }
        return at;
    }

    /*
     * tokenize(text, role) -> [{ text, honorific, epithetStart }]
     *
     * `role` is 'name', 'award' or 'place'. Collapsing whitespace and protecting spans
     * apply to all three. The honorific and epithet marks apply to the name only: an
     * award called "Page of the Rose" has no honorific, and "Guildmaster of the Rose"
     * has no epithet.
     */
    function tokenize(text, role) {
        var words = splitWords(text);
        var run = role === 'name' ? honorificRun(words) : 0;
        var epi = role === 'name' ? epithetStart(words) : -1;
        var out = [];

        for (var i = 0; i < words.length; i++) {
            out.push({ text: words[i], honorific: i < run, epithetStart: i === epi });
        }
        return out;
    }

    /* ------------------------------------------------------------- breakPenalty */

    /*
     * breakPenalty(tokens, i, role) -> number
     *
     * The additive cost of breaking the line in the gap after token `i` (§6.3.2). Every
     * row of the table that applies is summed. A gap that does not exist — before the
     * first token or after the last — costs nothing, so an enumerator can sum over
     * whatever indices it likes.
     *
     * The honorific and epithet rows read the flags tokenize() set, which exist only
     * for the name role; `role` itself decides the one award-only row.
     */
    function breakPenalty(tokens, i, role) {
        if (i < 0 || i + 1 >= tokens.length) { return 0; }

        var a = tokens[i], b = tokens[i + 1], p = 0;
        var weak = isWeakEnd(a.text);

        if (weak) { p += CONST.P_WEAK_END; }
        if (/[,;:]$/.test(a.text)) { p += CONST.P_PUNCT; }
        if (a.honorific && !b.honorific) { p += CONST.P_HON_END; }
        if (a.honorific && b.honorific) { p += CONST.P_HON_SPLIT; }
        if (b.epithetStart && !weak) { p += CONST.P_EPITHET; }
        if (isSuffix(b.text)) { p += CONST.P_SUFFIX; }
        if (isInitial(a.text)) { p += CONST.P_INITIAL; }
        if (role === 'award' && weakKey(b.text) === 'of') { p += CONST.P_AWARD_BEFORE_OF; }
        return p;
    }

    /* ------------------------------------------------------------------ utility */

    /*
     * utility(s, range) -> number   (§6.3.3)
     *
     * What a size is worth: a straight line from 0 to 100 as `s` climbs to `sat`, then a
     * shallower climb to 120 at the role's own maximum. Past `sat` a bigger name still
     * reads as better, but not enough to be worth a bad break or a third line.
     *
     * The maximum is `range.uMax` when the range has one, else `range.max`. They differ
     * when the hierarchy of §6.4.2 has lowered a block's ceiling: that ceiling limits how
     * large the block may be set (`max`, the cap on s*), but it does not change what a
     * size is worth, so the worth is measured against the role's table value (`uMax`).
     * Measured against the lowered ceiling instead, the whole bonus past `sat` would be
     * squeezed into a sliver and a block would be pushed up to its cap by a second line.
     */
    function utility(s, range) {
        var sat = range.sat;
        if (!(sat > 0)) { throw new TypeError('utility: the range has no sat (the lead is sized by hierarchy, not scored)'); }
        var top = range.uMax != null ? range.uMax : range.max;
        var u = 100 * Math.min(s, sat) / sat;
        if (s > sat && top > sat) { u += 20 * (s - sat) / (top - sat); }
        return u;
    }

    /* ------------------------------------------------------------------ solveBlock */

    /* Two scores closer than this are a tie. Widths are sums of floats, so symmetrical
       candidates can differ in the last bits; the tie rules must not hang on that. */
    var EPS = 1e-9;

    /* The internal token: tokenize()'s marks, plus `joinPrev` for a fragment of a split
       word, which sits against the fragment before it with no space between. */
    function lineTokens(tokens) {
        return tokens.map(function (t) {
            return { text: t.text, honorific: t.honorific, epithetStart: t.epithetStart, joinPrev: false };
        });
    }

    function charCount(text) {
        return Array.from(text).length;
    }

    function isLowerChar(c) { return c !== c.toUpperCase(); }
    function isUpperChar(c) { return c !== c.toLowerCase(); }

    /* Cut `text` after each of the given character indices (a cut after index i leaves
       text[0..i] on the left). Returns the pieces, in order. */
    function cutAfter(chars, cuts) {
        var out = [], from = 0, i;
        for (i = 0; i < cuts.length; i++) {
            out.push(chars.slice(from, cuts[i] + 1).join(''));
            from = cuts[i] + 1;
        }
        out.push(chars.slice(from).join(''));
        return out;
    }

    /* The words inside a protected span. The no-break spaces that held the span together
       become ordinary breakable spaces, so a span too wide for any line is cut where a
       reader would cut it, between its words, before anything cuts inside one. */
    function nbspPieces(chars) {
        return chars.join('').split(NBSP).filter(function (word) { return word !== ''; });
    }

    /* Where a word may be cut at its own separators: after each run of _ - / . that is
       followed by something. The separator stays with the left piece ("Anthony_",
       "Anti-"), so the line that breaks there still shows where the word goes on. */
    function separatorCuts(chars) {
        var cuts = [], i;
        for (i = 0; i < chars.length - 1; i++) {
            if ('_-/.'.indexOf(chars[i]) >= 0 && '_-/.'.indexOf(chars[i + 1]) < 0) { cuts.push(i); }
        }
        return cuts;
    }

    /* Where a word may be cut between a lowercase letter and a capital ("McDonald" ->
       "Mc|Donald"), and between a run of capitals and the capital that starts the next
       word ("XMLParser" -> "XML|Parser"). */
    function camelCuts(chars) {
        var cuts = [], i;
        for (i = 0; i < chars.length - 1; i++) {
            if (isLowerChar(chars[i]) && isUpperChar(chars[i + 1])) { cuts.push(i); }
            else if (isUpperChar(chars[i]) && isUpperChar(chars[i + 1])
                && i + 2 < chars.length && isLowerChar(chars[i + 2])) { cuts.push(i); }
        }
        return cuts;
    }

    /* The midpoint of a word, halved again for as long as a piece is still too long, so
       a 40-character run with no seams ends as pieces that can sit on a line. */
    function midpointPieces(chars, tooLong) {
        if (chars.length < 2 || !tooLong(chars.join(''))) { return [chars.join('')]; }
        var mid = Math.ceil(chars.length / 2);
        return midpointPieces(chars.slice(0, mid), tooLong).concat(midpointPieces(chars.slice(mid), tooLong));
    }

    /*
     * One pass of the fallback (§6.3.3): break every token that cannot sit on any line
     * even at the smallest size into pieces, with `piecesOf` choosing where. Pieces after
     * the first carry no honorific or epithet mark (the word has one, and the first piece
     * keeps it) and, when `joined` is true, sit against the piece before with no space;
     * pieces of a protected span (`joined` false) are separate words again. Returns the
     * new token list, or null when no token was cut, so a pass that changes nothing is
     * not searched again.
     */
    function splitPass(tokens, tooLong, piecesOf, joined) {
        var out = [], changed = false;
        tokens.forEach(function (t) {
            var pieces = tooLong(t.text) ? piecesOf(Array.from(t.text)) : [t.text];
            if (pieces.length < 2) { out.push(t); return; }
            changed = true;
            pieces.forEach(function (text, k) {
                out.push({
                    text: text,
                    honorific: k === 0 ? t.honorific : false,
                    epithetStart: k === 0 ? t.epithetStart : false,
                    joinPrev: k === 0 ? t.joinPrev : joined
                });
            });
        });
        return changed ? out : null;
    }

    /* Every way to start `k` lines among `n` tokens: line 0 starts at token 0 and each
       later line at a larger index, in ascending order, so ties resolve the same way on
       every run. `visit` gets the live array of line starts; copy it to keep it. */
    function eachBreaking(n, k, visit) {
        var starts = [0];
        (function pick(next, left) {
            if (left === 0) { visit(starts); return; }
            for (var g = next; g <= n - left; g++) {
                starts.push(g);
                pick(g + 1, left - 1);
                starts.pop();
            }
        }(1, k - 1));
    }

    /*
     * Enumerate every candidate over `tokens` and rate it (§6.3.3, §6.3.4, §6.4.3).
     *
     * Returns { best, nearest }: `best` is the highest-scoring candidate whose largest
     * feasible size reaches the role's minimum (ties to fewer lines), or null; `nearest`
     * is, among all candidates, the one that comes closest to fitting. The caller falls
     * back to `nearest` only when nothing fits.
     *
     * A candidate is rated up to three ways: plain, and with each line §6.3.4 allows
     * made minor. Each way is its own entry in the running, since making a line minor
     * both lifts its size and costs MINOR_PENALTY.
     */
    function search(tokens, ctx) {
        var n = tokens.length, role = ctx.role, range = ctx.range, lo = range.min;
        var PW = [0], PS = [0], PC = [0], bp = [], i, k;

        for (i = 0; i < n; i++) {
            var spaced = (i > 0 && !tokens[i].joinPrev) ? 1 : 0;
            PW[i + 1] = PW[i] + ctx.measure(tokens[i].text);
            PS[i + 1] = PS[i] + spaced;
            PC[i + 1] = PC[i] + charCount(tokens[i].text);
            bp[i] = breakPenalty(tokens, i, role);
        }

        var run = 0;
        while (run < n && tokens[run].honorific) { run++; }
        var epi = -1;
        for (i = 0; i < n; i++) { if (tokens[i].epithetStart) { epi = i; break; } }

        var best = null, nearest = null;

        /* One way of rating one candidate. `minor` is -1 or the line made minor. */
        function rate(starts, LW, cnt, bpSum, minor) {
            var kk = starts.length, j;

            var sStar = range.max;
            for (j = 0; j < kk; j++) {
                var w = LW[j] * (j === minor ? CONST.MINOR_SCALE : 1) * ctx.rootPx;
                if (w > 0) { sStar = Math.min(sStar, ctx.allowed(j, minor === 0) / w); }
            }

            var maxLW = 0;
            for (j = 0; j < kk; j++) { if (j !== minor && LW[j] > maxLW) { maxLW = LW[j]; } }

            var score = utility(Math.max(sStar, lo), range) - ctx.linePenalty[Math.min(kk, ctx.linePenalty.length) - 1] - bpSum;
            if (kk >= 2 && maxLW > 0) {
                var ragged = 0, widow = false;
                for (j = 0; j < kk; j++) {
                    if (j === minor) { continue; }
                    ragged += Math.pow((maxLW - LW[j]) / maxLW, 2);
                    if (cnt[j] === 1 && LW[j] < 0.3 * maxLW) { widow = true; }
                }
                score -= CONST.RAGGED * ragged + (widow ? CONST.WIDOW : 0);
            }
            if (minor >= 0) { score -= CONST.MINOR_PENALTY; }

            return { starts: starts.slice(), k: kk, minor: minor, sStar: sStar, score: score };
        }

        function beats(a, b) {
            if (!b) { return true; }
            if (a.score > b.score + EPS) { return true; }
            if (a.score < b.score - EPS) { return false; }
            if (a.k !== b.k) { return a.k < b.k; }
            return a.minor < 0 && b.minor >= 0;
        }

        function closer(a, b) {
            if (!b) { return true; }
            if (a.sStar > b.sStar + EPS) { return true; }
            if (a.sStar < b.sStar - EPS) { return false; }
            return beats(a, b);
        }

        function consider(c) {
            if (closer(c, nearest)) { nearest = c; }
            if (c.sStar >= lo - EPS && beats(c, best)) { best = c; }
        }

        for (k = 1; k <= Math.min(ctx.maxLines, n); k++) {
            eachBreaking(n, k, function (starts) {
                var LW = [], cnt = [], chars = [], bpSum = 0, j, a, b;
                for (j = 0; j < k; j++) {
                    a = starts[j];
                    b = (j + 1 < k ? starts[j + 1] : n) - 1;
                    LW.push(PW[b + 1] - PW[a] + ctx.spaceW * (PS[b + 1] - PS[a + 1]));
                    cnt.push(b - a + 1);
                    chars.push(PC[b + 1] - PC[a] + (PS[b + 1] - PS[a + 1]));
                    if (j + 1 < k) { bpSum += bp[b]; }
                }

                consider(rate(starts, LW, cnt, bpSum, -1));
                if (k < 2 || role !== 'name' || !CONST.MINOR_LINES) { return; }

                /* The line holding the most characters is never the minor one. */
                var most = Math.max.apply(null, chars);
                if (run > 0 && starts[1] === run && chars[0] < most) {
                    consider(rate(starts, LW, cnt, bpSum, 0));
                }
                if (epi > 0 && starts[k - 1] === epi && n - epi >= 2 && chars[k - 1] < most) {
                    consider(rate(starts, LW, cnt, bpSum, k - 1));
                }
            });
        }
        return { best: best, nearest: nearest };
    }

    function joinLine(tokens, a, b) {
        var text = '', i;
        for (i = a; i <= b; i++) { text += (i > a && !tokens[i].joinPrev ? ' ' : '') + tokens[i].text; }
        return text;
    }

    function solutionOf(c, tokens, overflow, lo) {
        var lines = [], j, b;
        for (j = 0; j < c.k; j++) {
            b = (j + 1 < c.k ? c.starts[j + 1] : tokens.length) - 1;
            lines.push(joinLine(tokens, c.starts[j], b));
        }
        return { size: overflow ? lo : c.sStar, lines: lines, minor: c.minor, planned: c.k, score: c.score, overflow: overflow };
    }

    /*
     * solveBlock(opts) -> { size, lines, minor, planned, score, overflow }   (§6.3.3)
     *
     *   text       the words to set
     *   role       'name', 'award' or 'place'
     *   measure    word -> width in em at 1px (a protected span arrives with no-break
     *              spaces inside it, as tokenize() leaves it)
     *   spaceW     the width of one space, em at 1px
     *   range      { min, max, sat, uMax? } for this block.
     *                min   already carries any multi-up FLOOR_SCALE.
     *                max   the size ceiling after the hierarchy of §6.4.2 (max_eff). It
     *                      caps s* and sets how far down the page each line's band
     *                      reaches, and nothing else.
     *                sat   the role's own saturation size.
     *                uMax  the role's own maximum, which utility() measures worth
     *                      against: a lowered ceiling does not make a size worth more.
     *                      It defaults to CONST.ROLES[role].max for 'name' and 'award'.
     *                      A place has two table rows (Classic, Centered), so for
     *                      'place' the caller passes it, and solveBlock throws without.
     *              A range taken from CONST.ROLES also supplies the per-line-count prices.
     *   maxLines   most lines the block may take (an award over 40 characters is allowed
     *              three, whatever the caller passes). Defaults to the role's table row
     *              for 'name' and 'award'; a place must pass it.
     *   lineHeight line-height factor
     *   y0         where the block's top sits, frame px
     *   rootPx     computed font-size of the root, px
     *   freeWidth  (yTop, yBottom) -> width of the free band, frame px
     *
     * `size` is the em size of the block's main lines, `lines` the text of each line,
     * `minor` the index of the line set smaller (-1 for none), `planned` the number of
     * lines. `overflow` is true when nothing fits even at `range.min`: the lines then
     * come back at that minimum, every word present, and the caller flags the block.
     */
    function solveBlock(o) {
        var role = o.role;
        var row = CONST.ROLES[role];      // none for 'place': it has a Classic and a Centered row

        /* What a caller must say and the role table cannot say for it. Refused up front,
           so a missing value fails with its name rather than as a crash deep inside. */
        var uMax = o.range.uMax != null ? o.range.uMax : (row ? row.max : null);
        if (uMax == null) { throw new TypeError("solveBlock: role '" + role + "' has no single role-table row, so range.uMax (the role's own maximum) must be passed"); }
        var maxLines = o.maxLines != null ? o.maxLines : (row ? row.maxLines : null);
        if (!(maxLines >= 1)) { throw new TypeError("solveBlock: role '" + role + "' has no single role-table row, so maxLines must be passed"); }

        var tokens = lineTokens(tokenize(o.text, role));
        var range = {
            min: o.range.min,
            max: Math.max(o.range.max, o.range.min),
            sat: o.range.sat != null ? o.range.sat : (row ? row.sat : null),
            uMax: uMax
        };
        var lo = range.min;
        if (tokens.length === 0) {
            return { size: lo, lines: [], minor: -1, planned: 0, score: 0, overflow: false };
        }

        if (role === 'award' && charCount(tokens.map(function (t) { return t.text; }).join(' ')) > CONST.AWARD_LONG.chars) {
            maxLines = Math.max(maxLines, CONST.AWARD_LONG.maxLines);
        }

        /* The price of each line count: the range's own table, else the role's. A place
           without one is priced like the centred place, whose first entry is the same
           zero a one-line Classic place pays. */
        var table = o.range.linePenalty
            || (CONST.ROLES[role] && CONST.ROLES[role].linePenalty)
            || CONST.ROLES.placeCentered.linePenalty;

        /* Free width for line j, with the conservative band of §6.4.3: from where the
           line would start at the smallest size to where it would end at the largest.
           The band is taller than the line ever is, so the width found is safe at every
           size in between and no iteration is needed. When line 0 is minor, the lines
           below it start higher than the uniform spacing assumes, so their band begins
           higher by the same fraction. Asked for each band once. */
        var cache = {};
        function allowed(j, lineZeroMinor) {
            var up = j - (lineZeroMinor && j > 0 ? 1 - CONST.MINOR_SCALE : 0);
            var top = o.y0 + up * o.lineHeight * lo * o.rootPx;
            var bottom = o.y0 + (j + 1) * o.lineHeight * range.max * o.rootPx;
            var key = top + ':' + bottom;
            if (!(key in cache)) { cache[key] = o.freeWidth(top, bottom) * (1 - CONST.SLACK); }
            return cache[key];
        }

        var ctx = {
            role: role, range: range, maxLines: maxLines, measure: o.measure, spaceW: o.spaceW,
            rootPx: o.rootPx, linePenalty: table, allowed: allowed
        };

        var first = search(tokens, ctx);
        if (first.best) { return solutionOf(first.best, tokens, false, lo); }

        /* Nothing fits as written. Cut the words that cannot sit on any line, gently
           first: a protected span between its own words, then a word at its own
           separators, then at camelCase seams, then wherever it is long enough to need
           it. */
        var widest = 0, j;
        for (j = 0; j < maxLines; j++) { widest = Math.max(widest, allowed(j, false)); }
        function tooLong(text) { return o.measure(text) * lo * o.rootPx > widest; }

        var passes = [
            function (t) { return splitPass(t, tooLong, nbspPieces, false); },
            function (t) { return splitPass(t, tooLong, function (cs) { return cutAfter(cs, separatorCuts(cs)); }, true); },
            function (t) { return splitPass(t, tooLong, function (cs) { return cutAfter(cs, camelCuts(cs)); }, true); },
            function (t) { return splitPass(t, tooLong, function (cs) { return midpointPieces(cs, tooLong); }, true); }
        ];
        var current = tokens, p, next, found;
        for (p = 0; p < passes.length; p++) {
            next = passes[p](current);
            if (!next) { continue; }
            current = next;
            found = search(current, ctx);
            if (found.best) { return solutionOf(found.best, current, false, lo); }
        }

        /* Still nothing, even cut up. Cutting that does not make a block fit only
           mutilates the name, so the flagged answer is the closest-fitting candidate over
           the words as written, set at the minimum. */
        return solutionOf(first.nearest, tokens, true, lo);
    }


    /* ================================================================ DOM layer */

    /*
     * Everything below reads or writes the page. Each function touches `document` and
     * `window` only when called (the module-level rule at the top of the file).
     *
     * GEOMETRY IS LAYOUT PIXELS. Widths and tops come from offsetWidth, scrollWidth and
     * offsetTop, which are the unscaled px inside the 2550x3300 frame whatever scale the
     * SVG is drawn at. The one exception is the width of a word at 100px in the measuring
     * span: it lives outside the frame (so no scale applies to it) and wants the
     * sub-pixel width, where an integer offsetWidth would round a 100px word to a whole
     * percent of its size.
     */

    /* A line wider than its allowed width by more than this is overflowing. Layout
       rounds to whole pixels, so half a pixel is the smallest difference that is real. */
    var VERIFY_TOL = 0.5;
    var VERIFY_ROUNDS = 6;
    var VERIFY_MARGIN = 0.995;

    function computed(el) {
        return window.getComputedStyle(el);
    }

    function pxOf(value) {
        var n = parseFloat(value);
        return n > 0 ? n : 0;
    }

    /* Multi-up floor, read the way scrollery-autofit.js reads it: any element carrying
       data-scroll-up, which Scrollery_render.tpl stamps on <body>. 2-up and 4-up print at
       half scale, so every minimum size rises by half to stay legible. */
    function floorScale() {
        var el = document.querySelector('[data-scroll-up]');
        var up = el ? parseInt(el.getAttribute('data-scroll-up'), 10) : 0;
        return (up === 2 || up === 4) ? 1.5 : 1;
    }

    /*
     * data-sc-free is "y0,step|L,L,...|R,R,..." (spec §7.1): band b spans
     * [y0 + b*step, y0 + (b+1)*step) in frame px, and L[b] / R[b] are the free interval's
     * edges there. An empty or unreadable entry is an unconstrained band, as is a band
     * past the end of the list. Returns null when there is no profile at all.
     */
    function parseFree(text) {
        if (!text) { return null; }
        var parts = String(text).split('|');
        if (parts.length < 3) { return null; }
        var head = parts[0].split(',');
        var y0 = parseFloat(head[0]), step = parseFloat(head[1]);
        if (!(step > 0) || isNaN(y0)) { return null; }
        function list(raw) {
            return raw.split(',').map(function (v) {
                var n = v === '' ? NaN : parseFloat(v);
                return isNaN(n) ? null : n;
            });
        }
        return { y0: y0, step: step, L: list(parts[1]), R: list(parts[2]) };
    }

    /*
     * readContext(award) -> { award, rootPx, floorScale, column, free, heading }
     *
     * `column` is the .Award content box in frame px, with `cx` its centre. It is the
     * container's box and not any child's, for the reason scrollery-autofit.js gives at
     * columnWidth(): a flex child grows past the column when its text is too long, so a
     * child's own width says nothing about the column. `heading` is the forced issuer
     * composition from the closest [data-sc-heading] (spec §6.5), or null for auto.
     */
    function readContext(award) {
        var cs = computed(award);
        var left = pxOf(cs.paddingLeft);
        var right = award.clientWidth - pxOf(cs.paddingRight);
        var forced = award.closest('[data-sc-heading]');
        var heading = forced ? forced.getAttribute('data-sc-heading') : null;
        return {
            award: award,
            rootPx: pxOf(cs.fontSize),
            floorScale: floorScale(),
            column: { left: left, right: right, width: right - left, cx: (left + right) / 2 },
            free: parseFree(award.getAttribute('data-sc-free')),
            heading: (heading === 'classic' || heading === 'centered') ? heading : null
        };
    }

    /*
     * freeWidth(ctx, yTop, yBottom, centred) -> px
     *
     * The width of the page that is clear of the artwork from `yTop` to `yBottom` (frame
     * px). The profile's bands that the span touches are intersected, then the result is
     * held inside the column. A centred role can only use what is clear on BOTH sides of
     * the column centre, so its width is twice the nearer edge's distance from it; any
     * other role gets the whole free interval. With no profile, or no data in those
     * bands, the column itself is the free space.
     */
    function freeWidth(ctx, yTop, yBottom, centred) {
        var col = ctx.column, free = ctx.free;
        var l = col.left, r = col.right;
        if (free) {
            var b0 = Math.floor((yTop - free.y0) / free.step);
            var b1 = Math.ceil((yBottom - free.y0) / free.step) - 1;
            if (b1 < b0) { b1 = b0; }
            for (var b = Math.max(b0, 0); b <= b1; b++) {
                if (free.L[b] != null && free.L[b] > l) { l = free.L[b]; }
                if (free.R[b] != null && free.R[b] < r) { r = free.R[b]; }
            }
        }
        if (centred) { return Math.max(0, 2 * Math.min(col.cx - l, r - col.cx)); }
        return Math.max(0, r - l);
    }

    /* ----------------------------------------------------------------- measuring */

    /*
     * One hidden span for the page, kept in <body> and reused. Words are measured in
     * child spans of it at 100px, so a width in px at 100px is the width in em at 1px
     * times 100. The cache is keyed by every property that changes a glyph's width,
     * then by the word. A page whose fonts are still loading would cache the fallback
     * face's widths against the web font's name, so nothing is stored while
     * document.fonts says it is loading.
     */
    var measureHost = null;
    var measureCache = Object.create(null);

    function measureHostEl() {
        if (measureHost && measureHost.isConnected) { return measureHost; }
        measureHost = document.createElement('span');
        measureHost.className = 'sc-measure';
        measureHost.setAttribute('aria-hidden', 'true');
        measureHost.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden;'
            + 'pointer-events:none;white-space:pre;font-size:100px;line-height:1;'
            + 'margin:0;padding:0;border:0;text-indent:0;word-spacing:normal';
        document.body.appendChild(measureHost);
        return measureHost;
    }

    /* The role element's own face, as the properties that move a glyph's width. Letter
       spacing is stored as a fraction of the font size: computed, it is a px length for
       the role's current size, and at 100px it would be a different amount of space. */
    function faceOf(roleEl) {
        var cs = computed(roleEl);
        var size = pxOf(cs.fontSize) || 1;
        var ls = cs.letterSpacing === 'normal' ? 0 : parseFloat(cs.letterSpacing) || 0;
        return {
            fontFamily: cs.fontFamily,
            fontStyle: cs.fontStyle,
            fontWeight: cs.fontWeight,
            fontStretch: cs.fontStretch,
            fontVariant: cs.fontVariant,
            fontKerning: cs.fontKerning,
            fontFeatureSettings: cs.fontFeatureSettings,
            textTransform: cs.textTransform,
            letterSpacing: (ls / size * 100).toFixed(3) + 'px'
        };
    }

    function faceKey(face) {
        return Object.keys(face).map(function (k) { return face[k]; }).join('|');
    }

    /*
     * measureWords(roleEl, words) -> { widths, space, measure }
     *
     * `widths` maps each word to its width in em at 1px and `space` is the width of one
     * space. `measure(word)` answers for any string, measuring on demand: the solver
     * cuts over-long words into pieces nobody could list in advance. Asking for the
     * words up front costs one layout for the whole set.
     */
    function measureWords(roleEl, words) {
        var face = faceOf(roleEl);
        var key = faceKey(face);
        var cache = measureCache[key] || (measureCache[key] = Object.create(null));
        var live = !(document.fonts && document.fonts.status === 'loading');
        var scratch = live ? cache : Object.create(null);

        function fill(list) {
            var need = list.filter(function (w, i) { return !(w in scratch) && list.indexOf(w) === i; });
            if (!need.length) { return; }
            var host = measureHostEl();
            Object.keys(face).forEach(function (k) { host.style[k] = face[k]; });
            var spans = need.map(function (w) {
                var span = document.createElement('span');
                span.textContent = w;
                host.appendChild(span);
                return span;
            });
            spans.forEach(function (span, i) {
                scratch[need[i]] = span.getBoundingClientRect().width / 100;
            });
            host.textContent = '';
        }

        fill([' '].concat(words || []));

        function measure(word) {
            if (!(word in scratch)) { fill([word]); }
            return scratch[word];
        }
        var widths = Object.create(null);
        (words || []).forEach(function (w) { widths[w] = measure(w); });
        return { widths: widths, space: measure(' '), measure: measure };
    }

    /* ------------------------------------------------------------------ geometry */

    /* A box's top in layout px from the top of the document, summed up the offsetParent
       chain. Differences of two of these cancel whatever the chain ends at. */
    function absTop(el) {
        var y = 0;
        while (el) { y += el.offsetTop; el = el.offsetParent; }
        return y;
    }

    /* The block that holds a role: the role itself, or the nearest ancestor that is not
       inline. The name is an inline span, and an inline box's offsetTop moves with its own
       font size, which would make the first fit and the second disagree about where the
       name starts. */
    function blockOf(el) {
        while (el.parentElement && computed(el).display === 'inline') { el = el.parentElement; }
        return el;
    }

    /* The top of a role's text, in frame px from the top of the .Award. */
    function roleTop(award, role) {
        var block = blockOf(role), cs = computed(block);
        return absTop(block) - absTop(award) + block.clientTop + pxOf(cs.paddingTop);
    }

    function setSize(role, rootEm, rootPx, parentPx) {
        var em = rootEm * rootPx / parentPx;
        role.style.fontSize = em.toFixed(4) + 'em';
        /* What was written, read back as root em, so a size that went through four
           decimals is reported as the size the browser will draw. */
        return Number(em.toFixed(4)) * parentPx / rootPx;
    }

    function lineEls(role) {
        return Array.prototype.filter.call(role.children, function (c) { return c.classList.contains('sc-line'); });
    }

    /* The widths each line would have on one row, whatever its box does. A block line is
       as wide as its container unless its CSS shrinks it, and a line's scrollWidth would
       then say "the container" for every line. Shrinking them for the read, and letting
       go afterwards, makes the check the same on any stylesheet. */
    function naturalWidths(lines) {
        lines.forEach(function (l) { l.style.width = 'max-content'; });
        var out = lines.map(function (l) { return Math.max(l.scrollWidth, l.offsetWidth); });
        lines.forEach(function (l) {
            l.style.width = '';
            if (!l.getAttribute('style')) { l.removeAttribute('style'); }
        });
        return out;
    }

    /* How many rows the lines really occupy: a line the browser wrapped counts for each
       row it took, and a line that is not a block (so shares a row) counts once for all
       of them. This is the printed count of the report, as opposed to the planned one. */
    function printedRows(lines) {
        var tops = [];
        lines.forEach(function (l) {
            var range = document.createRange();
            range.selectNodeContents(l);
            Array.prototype.forEach.call(range.getClientRects(), function (r) {
                if (r.width > 0) { tops.push({ top: r.top, height: r.height }); }
            });
        });
        tops.sort(function (a, b) { return a.top - b.top; });
        var rows = 0, last = null;
        tops.forEach(function (t) {
            if (last === null || t.top - last.top > last.height * 0.5) { rows++; last = t; }
        });
        return rows;
    }

    /* ------------------------------------------------------------------ one role */

    /* The words to set. A role already holding lines gives its source back from
       data-sc-text; otherwise its text is read and stored there. A composer's
       `textContent = ...` removes the lines, so the next run reads the new text. */
    function sourceText(role) {
        if (lineEls(role).length) {
            var stored = role.getAttribute('data-sc-text');
            if (stored !== null) { return stored; }
            return lineEls(role).map(function (l) { return l.textContent; }).join(' ');
        }
        var text = (role.textContent || '').replace(/\s+/g, ' ').trim();
        if (text) { role.setAttribute('data-sc-text', text); } else { role.removeAttribute('data-sc-text'); }
        return text;
    }

    function clearReport(role) {
        ['data-sc-size', 'data-sc-lines', 'data-sc-planned', 'data-sc-overflow', 'data-sc-text']
            .forEach(function (a) { role.removeAttribute(a); });
    }

    /* The lines as explicit block spans. The text goes in as a text node, so nothing in a
       persona can become markup. A minor line carries its own em size, relative to the
       role's. */
    function writeLines(role, lines, minor, allowed) {
        role.textContent = '';
        lines.forEach(function (text, j) {
            var span = document.createElement('span');
            span.className = j === minor ? 'sc-line sc-line--minor' : 'sc-line';
            if (j === minor) { span.style.fontSize = CONST.MINOR_SCALE + 'em'; }
            span.setAttribute('data-sc-w', allowed[j].toFixed(2));
            span.appendChild(document.createTextNode(text));
            role.appendChild(span);
        });
    }

    /*
     * The width line `j` was allowed, as solveBlock worked it out and with the same
     * conservative band: from where the line would start at the smallest size to where it
     * would end at the largest, so the width is safe at every size in between. When line 0
     * is minor the lines below start higher than uniform spacing says.
     */
    function lineAllowed(ctx, lh, y0, j, minorIdx, range) {
        var up = j - (minorIdx === 0 && j > 0 ? 1 - CONST.MINOR_SCALE : 0);
        var top = y0 + up * lh * range.min * ctx.rootPx;
        var bottom = y0 + (j + 1) * lh * range.max * ctx.rootPx;
        return freeWidth(ctx, top, bottom, true) * (1 - CONST.SLACK);
    }

    /*
     * Set one role: solve, write, verify, report. Returns the report entry, or null when
     * the role is absent or empty. An empty role is left alone, down to the blank writing
     * line a composer leaves inside it, and any report it carried from a fuller text is
     * taken off so that it does not describe words that are gone.
     */
    function setRole(ctx, el, roleName, maxEm, minEm) {
        if (!el) { return null; }
        var row = CONST.ROLES[roleName];
        var text = sourceText(el);
        if (!text) { clearReport(el); return null; }

        var parentPx = pxOf(computed(el.parentElement).fontSize);
        var range = { min: minEm, max: Math.max(maxEm, minEm) };
        var tokens = tokenize(text, roleName).map(function (t) { return t.text; });
        var m = measureWords(el, tokens);
        var y0 = roleTop(ctx.award, el);

        var sol = solveBlock({
            text: text, role: roleName, measure: m.measure, spaceW: m.space,
            range: { min: range.min, max: range.max },
            lineHeight: row.lineHeight, y0: y0, rootPx: ctx.rootPx,
            freeWidth: function (top, bottom) { return freeWidth(ctx, top, bottom, true); }
        });

        var allowed = sol.lines.map(function (_, j) {
            return lineAllowed(ctx, row.lineHeight, y0, j, sol.minor, range);
        });
        writeLines(el, sol.lines, sol.minor, allowed);
        var size = setSize(el, sol.size, ctx.rootPx, parentPx);

        /* Verify against the browser. The solver measured each word alone, so kerning
           across a space, or a face that is not the one measured, can leave a line a few
           pixels wide. A solver overflow is already at the minimum with nothing left to
           give, so it is not shrunk further: the words stay legible and the block is
           flagged. */
        var overflow = sol.overflow;
        var lines = lineEls(el);
        if (!overflow) {
            for (var round = 0; round <= VERIFY_ROUNDS; round++) {
                var widths = naturalWidths(lines);
                var worst = 1;
                widths.forEach(function (w, j) {
                    if (w > allowed[j] + VERIFY_TOL) { worst = Math.min(worst, allowed[j] / w); }
                });
                if (worst === 1) { break; }
                if (round === VERIFY_ROUNDS || size <= range.min + 1e-9) { overflow = true; break; }
                size = setSize(el, Math.max(size * worst * VERIFY_MARGIN, range.min), ctx.rootPx, parentPx);
            }
        }

        el.setAttribute('data-sc-size', size.toFixed(3));
        el.setAttribute('data-sc-planned', String(sol.planned));
        el.setAttribute('data-sc-lines', String(printedRows(lines)));
        if (overflow) { el.setAttribute('data-sc-overflow', '1'); } else { el.removeAttribute('data-sc-overflow'); }

        return {
            size: size,
            lines: Number(el.getAttribute('data-sc-lines')),
            planned: sol.planned,
            overflow: overflow
        };
    }

    /*
     * layoutTop(award, opts) -> Report
     *
     *   Report = { comp, ms, budget, roles: { name?, award? }, ornaments: {} }
     *
     * Sets the name at its measured top, then the award under the hierarchy of §6.4.2:
     * its ceiling is the smaller of its own maximum and 0.85 of the name's FINAL size
     * (after verify, so a name that had to shrink pulls the award down with it), and
     * never below its own minimum. The award's top is measured after the name's lines
     * are written, because they are what moves it. Multi-up lifts both minimums (§6.4.1).
     * The issuer roles are not touched yet; `comp`, `budget` and `ornaments` are
     * placeholders until they are.
     *
     * `opts.nameMax` lowers the name's ceiling, which is the budget loop's lever (§6.7).
     */
    function layoutTop(award, opts) {
        var t0 = (window.performance && window.performance.now) ? window.performance.now() : Date.now();
        var o = opts || {};
        var ctx = readContext(award);
        var N = CONST.ROLES.name, A = CONST.ROLES.award;
        var report = { comp: ctx.heading, ms: 0, budget: 'ok', roles: {}, ornaments: {} };

        var nameEl = award.querySelector('[data-sc-role="name"]');
        var awardEl = award.querySelector('[data-sc-role="award"]');

        var nameMax = o.nameMax > 0 ? Math.min(o.nameMax, N.max) : N.max;
        var nameMin = Math.min(N.min * ctx.floorScale, nameMax);
        var name = setRole(ctx, nameEl, 'name', nameMax, nameMin);
        if (name) { report.roles.name = name; }

        /* With no name on the page there is nothing for the award to rank under. */
        var nameSize = name ? name.size : N.max;
        var awardMin = Math.min(A.min * ctx.floorScale, A.max);
        var awardMax = Math.max(Math.min(A.max, CONST.RATIOS.awardToName * nameSize), awardMin);
        var aw = setRole(ctx, awardEl, 'award', awardMax, awardMin);
        if (aw) { report.roles.award = aw; }

        var t1 = (window.performance && window.performance.now) ? window.performance.now() : Date.now();
        report.ms = t1 - t0;
        award.setAttribute('data-sc-ms', report.ms.toFixed(1));
        return report;
    }

    return {
        CONST: CONST,
        tokenize: tokenize,
        breakPenalty: breakPenalty,
        utility: utility,
        solveBlock: solveBlock,
        parseFree: parseFree,
        readContext: readContext,
        measureWords: measureWords,
        freeWidth: freeWidth,
        layoutTop: layoutTop
    };
}));
