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
 *   setRole      one role: solve, write explicit lines, verify against the browser,
 *                report (§6.8). The name, the award and the place all go through it.
 *   the issuer   the versal, the lead and the place solved together: Classic with its
 *                geometry taken from the flourishes' ink grids (§6.5.1), Centered
 *                (§6.5.2), and the choice between them (§6.5.3).
 *   ornaments    each flourish tried placed, then scaled, then hidden, against the ink
 *                boxes of the text and the artwork's free interval (§6.6).
 *   the budget   the top stack held to its share of the page, the name's ceiling coming
 *                down until it fits or every role is at its floor (§6.7).
 *   layoutTop    the entry point: runs the pipeline of §4 over one certificate and
 *                writes the report (§6.8).
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
     *     AWARD_LONG carries that rule beside the table. The name's `maxLines` is 3,
     *     rising to 4 for a persona over 70 characters, which NAME_LONG carries. The
     *     name's fourth line costs 90, a step of 30 like each before it, so a long
     *     persona takes the fourth line only when three cannot hold it at a size worth
     *     printing.
     *   - the lead (the connective, "and the Barony of") is sized by hierarchy, not
     *     scored, so it has no `sat` and no line price. `sat` is null so a caller that
     *     feeds it to the utility function fails loudly instead of quietly producing
     *     NaN, and `linePenalty` is the single zero a one-line role needs.
     */
    var CONST = {
        ROLES: {
            name:          { min: 0.80, max: 2.30, sat: 1.60, maxLines: 3, lineHeight: 1.12, linePenalty: [0, 30, 60, 90] },
            award:         { min: 0.70, max: 1.50, sat: 1.20, maxLines: 2, lineHeight: 1.10, linePenalty: [0, 22, 50] },
            placeClassic:  { min: 0.62, max: 1.30, sat: 1.00, maxLines: 1, lineHeight: 1.10, linePenalty: [0] },
            placeCentered: { min: 0.62, max: 1.30, sat: 1.00, maxLines: 2, lineHeight: 1.10, linePenalty: [0, 25] },
            lead:          { min: 0.70, max: 1.04, sat: null, maxLines: 1, lineHeight: 1.10, linePenalty: [0] }
        },
        AWARD_LONG: { chars: 40, maxLines: 3 },

        /* Calibration (T14). A persona over 70 characters may take a fourth line. The
           frozen corpus's c16 (100 characters of Cormorant Unicase) cannot be broken into
           three lines inside the column even at name.min: it printed 1,169px lines in a
           1,121px column and was flagged overflow. Lowering name.min would shrink every
           long persona to buy one case. A fourth line is the typographer's answer and the
           role already has a price for it. Measured: c16 sets four lines at 0.957em with no
           overflow, and c31 four lines at 1.051em where three had held it at 0.838em. */
        NAME_LONG: { chars: 70, maxLines: 4 },

        /* Calibration (T14). The most a candidate may be paid back, in total, for the
           good places it breaks (the comma, the end of the honorific run, the epithet:
           the negative penalties of §6.3.2). Uncapped, every extra line that lands on a
           second good gap is paid again, which is how c46 ("Syr Grand Marquis Noodles,
           The Symbol of Peace") scored a three-line 1.846em over its two-line 1.284em:
           the third line was worth 45 points of break credit against 28 for the second,
           and the size it bought is mostly past `sat`. The cap is one honorific-run gap:
           an extra line has to earn its place on size, not on collecting credit. Measured
           over the frozen corpus (docs/scrollery-typeset-results.md): c46 two lines at
           1.284em, c12 and c32 rise from 1.158 and 1.229em to 1.350 and 1.441em, c49
           from 1.187 to 1.520em, and nothing else moves. A lower utility bonus (20 to 10)
           and a dearer third line ([0, 30, 80]) were measured first and rejected: the
           bonus left c46 on three lines and turned the two-line c28, c38 and c48 into
           small one-liners, and the dearer line fixed c46 by shrinking c12, c15 and c32
           to 0.82, 1.02 and 0.87em two-liners. */
        BREAK_CREDIT_CAP: 25,

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

        /* The Centered lead is held lower than the table's maximum: spec §6.5.2 gives
           "0.70-0.90em" for the connective on its own line. */
        LEAD_CENTERED_MAX: 0.90,

        /* Ornament candidates, tried in order before the flourish is hidden (§6.5.1,
           §6.6), and the line-two rule's own two (§6.5.2: scale 0.8, then hidden). */
        ORN_SCALES: [1, 0.85, 0.70],
        RULE_SCALES: [1, 0.8],

        /* The Centered rule pair (§6.5.2): each half is min(RULE.share of the place's
           widest line, RULE.maxEm of its own em) wide; the halves meet with RULE.gapEm
           between them. */
        RULE: { share: 0.32, maxEm: 3.3, gapEm: 0.2 },

        /* Budget (§6.7): the name's ceiling drops by this factor, at most BUDGET_ROUNDS
           times, while the top stack is taller than its share of the page. */
        NAME_SHRINK: 0.92,
        BUDGET_ROUNDS: 3,

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
     *
     * `run` is the length of the leading honorific run. A comma inside it belongs to the
     * title ("Sir, Kato"), so the search for the epithet's comma starts after it:
     * otherwise "Kato" was marked an epithet and the gap after "Sir," scored -53 (a
     * comma, the end of the honorifics and an epithet start all at once) for what is a
     * plain title and name.
     */
    function epithetStart(words, run) {
        var at = -1, i;
        for (i = run || 0; i < words.length - 1; i++) {
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
        var epi = role === 'name' ? epithetStart(words, run) : -1;
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

    /*
     * The band of the page line `j` can occupy: from where it would start at the smallest
     * size to where it would end at the largest, so the width found for it is safe at
     * every size in between and no iteration is needed (§6.4.3). When line 0 is minor the
     * lines below it start higher than uniform spacing says, so their band begins higher
     * by the same fraction. The solver and the DOM layer's verify read the same band
     * through this one function: a band computed twice is a band that drifts.
     */
    function bandFor(y0, lineHeight, lo, hi, rootPx, j, lineZeroMinor) {
        var up = j - (lineZeroMinor && j > 0 ? 1 - CONST.MINOR_SCALE : 0);
        return {
            top: y0 + up * lineHeight * lo * rootPx,
            bottom: y0 + (j + 1) * lineHeight * hi * rootPx
        };
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

            var score = utility(Math.max(sStar, lo), range) - ctx.linePenalty[Math.min(kk, ctx.linePenalty.length) - 1]
                - Math.max(bpSum, -CONST.BREAK_CREDIT_CAP);
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
     *   maxLines   most lines the block may take (a name over 70 characters is allowed four, an award over 40 characters is allowed
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

        var chars = charCount(tokens.map(function (t) { return t.text; }).join(' '));
        if (role === 'award' && chars > CONST.AWARD_LONG.chars) {
            maxLines = Math.max(maxLines, CONST.AWARD_LONG.maxLines);
        }
        if (role === 'name' && chars > CONST.NAME_LONG.chars) {
            maxLines = Math.max(maxLines, CONST.NAME_LONG.maxLines);
        }

        /* The price of each line count: the range's own table, else the role's. A place
           without one is priced like the centred place, whose first entry is the same
           zero a one-line Classic place pays. */
        var table = o.range.linePenalty
            || (CONST.ROLES[role] && CONST.ROLES[role].linePenalty)
            || CONST.ROLES.placeCentered.linePenalty;

        /* Free width for line j over its conservative band (bandFor, §6.4.3). Asked for
           each band once. */
        var cache = {};
        function allowed(j, lineZeroMinor) {
            var band = bandFor(o.y0, o.lineHeight, lo, range.max, o.rootPx, j, lineZeroMinor);
            var key = band.top + ':' + band.bottom;
            if (!(key in cache)) { cache[key] = o.freeWidth(band.top, band.bottom) * (1 - CONST.SLACK); }
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
    var SIZE_EPS = 1e-3;

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
       chain. Differences of two of these cancel whatever the chain ends at. On a rendered
       certificate the chain ends at the SVG's <foreignObject>, which is not an HTMLElement
       and has no offsetTop (undefined), so a missing offset counts as 0: summed raw it
       made every box NaN, and NaN compares false with everything (the budget read floor
       and the profile read as ignored on every certificate). */
    function absTop(el) {
        var y = 0;
        while (el) { y += el.offsetTop || 0; el = el.offsetParent; }
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

    function collapse(text) {
        return (text || '').replace(/\s+/g, ' ').trim();
    }

    /* The words to set. A role already holding lines gives its source back from
       data-sc-text; otherwise its text is read and stored there. A composer's
       `textContent = ...` removes the lines, so the next run reads the new text. */
    function sourceText(role) {
        if (lineEls(role).length) {
            var stored = role.getAttribute('data-sc-text');
            if (stored !== null) { return stored; }
            return lineEls(role).map(function (l) { return l.textContent; }).join(' ');
        }
        var text = collapse(role.textContent);
        if (text) { role.setAttribute('data-sc-text', text); } else { role.removeAttribute('data-sc-text'); }
        return text;
    }

    /*
     * The place's source: its words and its article. The server writes the article as a
     * `<span class="sc-art">the</span>` inside the place span (spec §8.1), and it is set
     * at the lead's size, so it is not one of the words the solver breaks. It is kept in
     * data-sc-art while the lines are up, and written back onto the first line.
     */
    function placeSource(role) {
        if (lineEls(role).length) {
            var stored = role.getAttribute('data-sc-text');
            if (stored !== null) { return { text: stored, art: role.getAttribute('data-sc-art') || '' }; }
            return { text: lineEls(role).map(function (l) { return l.textContent; }).join(' '), art: '' };
        }
        var art = '', text = '';
        Array.prototype.forEach.call(role.childNodes, function (n) {
            if (n.nodeType === 1 && n.classList.contains('sc-art')) { art = collapse(n.textContent); } else { text += n.textContent; }
        });
        text = collapse(text);
        if (text) { role.setAttribute('data-sc-text', text); } else { role.removeAttribute('data-sc-text'); }
        if (text && art) { role.setAttribute('data-sc-art', art); } else { role.removeAttribute('data-sc-art'); }
        return { text: text, art: text ? art : '' };
    }

    function clearReport(role) {
        ['data-sc-size', 'data-sc-lines', 'data-sc-planned', 'data-sc-overflow', 'data-sc-text', 'data-sc-art']
            .forEach(function (a) { role.removeAttribute(a); });
    }

    /* The lines as explicit block spans. The text goes in as a text node, so nothing in a
       persona can become markup. A minor line carries its own em size, relative to the
       role's. The article, when there is one, is re-created on the first line. */
    function writeLines(role, lines, minor, allowed, art) {
        role.textContent = '';
        lines.forEach(function (text, j) {
            var span = document.createElement('span');
            span.className = j === minor ? 'sc-line sc-line--minor' : 'sc-line';
            if (j === minor) { span.style.fontSize = CONST.MINOR_SCALE + 'em'; }
            span.setAttribute('data-sc-w', allowed[j].toFixed(2));
            if (j === 0 && art) {
                var a = document.createElement('span');
                a.className = 'sc-art';
                a.appendChild(document.createTextNode(art));
                span.appendChild(a);
                span.appendChild(document.createTextNode(' ' + text));
            } else {
                span.appendChild(document.createTextNode(text));
            }
            role.appendChild(span);
        });
    }

    /*
     * Set one role: solve, write, verify, report. Returns the report entry, or null when
     * the role is absent, empty, or has no size to write against. An empty role is left
     * alone, down to the blank writing line a composer leaves inside it, and any report it
     * carried from a fuller text is taken off so that it does not describe words that are
     * gone. A role whose parent or root has no font size (a hidden subtree) is left alone
     * too: the em to write would divide by zero.
     *
     * `x` carries what a role needs beyond the name's and the award's defaults:
     *   row         the role-table row for line height (default CONST.ROLES[roleName])
     *   source      el -> { text, art } (default: sourceText, no article)
     *   solverRole  the role solveBlock is told (the place's two rows share 'place')
     *   range       { sat, uMax, linePenalty } passed through for a place
     *   maxLines    the place's line count
     *   y0          the block's top, frame px (default: roleTop)
     *   width       (top, bottom) -> width of the band, frame px (default: the centred
     *               free width over the column)
     *   artPx       width the article takes on the first line, held back from line 0
     *   onSize      called with the size after every write, so the article follows
     */
    function setRole(ctx, el, roleName, maxEm, minEm, x) {
        x = x || {};
        if (!el) { return null; }
        var row = x.row || CONST.ROLES[roleName];
        var src = x.source ? x.source(el) : { text: sourceText(el), art: '' };
        if (!src.text) { clearReport(el); return null; }

        var parentPx = pxOf(computed(el.parentElement).fontSize);
        if (!(parentPx > 0) || !(ctx.rootPx > 0)) { return null; }

        var range = { min: minEm, max: Math.max(maxEm, minEm) };
        var tokens = tokenize(src.text, roleName).map(function (t) { return t.text; });
        var m = measureWords(el, tokens);
        var y0 = x.y0 != null ? x.y0 : roleTop(ctx.award, el);
        var widthOf = x.width || function (top, bottom) { return freeWidth(ctx, top, bottom, true); };
        var artPx = x.artPx || 0;

        var sol = solveBlock({
            text: src.text, role: x.solverRole || roleName, measure: m.measure, spaceW: m.space,
            range: x.range
                ? { min: range.min, max: range.max, sat: x.range.sat, uMax: x.range.uMax, linePenalty: x.range.linePenalty }
                : { min: range.min, max: range.max },
            maxLines: x.maxLines,
            lineHeight: row.lineHeight, y0: y0, rootPx: ctx.rootPx,
            /* The article sits on line 0, which is the band that starts at y0 exactly. */
            freeWidth: function (top, bottom) {
                var w = widthOf(top, bottom);
                return artPx && top === y0 ? Math.max(0, w - artPx) : w;
            }
        });

        /* data-sc-w is the whole line's width, article included: it is what verify and the
           tests hold the printed line to. */
        var allowed = sol.lines.map(function (_, j) {
            var b = bandFor(y0, row.lineHeight, range.min, range.max, ctx.rootPx, j, sol.minor === 0);
            return widthOf(b.top, b.bottom) * (1 - CONST.SLACK);
        });
        writeLines(el, sol.lines, sol.minor, allowed, src.art);
        var size = setSize(el, sol.size, ctx.rootPx, parentPx);
        if (x.onSize) { x.onSize(size); }

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
                if (round === VERIFY_ROUNDS || size <= range.min + SIZE_EPS) { overflow = true; break; }
                size = setSize(el, Math.max(size * worst * VERIFY_MARGIN, range.min), ctx.rootPx, parentPx);
                if (x.onSize) { x.onSize(size); }
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
            overflow: overflow,
            y0: y0
        };
    }

    /* ------------------------------------------------------------------ ink and ornaments */

    /* Half a pixel is the smallest overlap that is real: layout rounds to whole pixels,
       and a curl placed exactly ORN_GAP below a line must read as clear. */
    var INK_EPS = 0.5;

    var inkCache = Object.create(null);

    /* "32x32:" + 256 hex -> 32 row masks (bit 31 is the leftmost cell), or null. Spec
       §7.2. */
    function decodeInk(text) {
        if (!text) { return null; }
        if (!(text in inkCache)) {
            var m = /^32x32:([0-9a-fA-F]{256})$/.exec(text), rows = null;
            if (m) {
                rows = [];
                for (var i = 0; i < 32; i++) { rows.push(parseInt(m[1].substr(i * 8, 8), 16)); }
            }
            inkCache[text] = rows;
        }
        return inkCache[text];
    }

    function absLeft(el) {
        var x = 0;
        while (el) { x += el.offsetLeft || 0; el = el.offsetParent; }
        return x;
    }

    /* An element's laid-out box in frame px from the top-left of the .Award, as a
       difference of offsetParent-chain sums so that whatever the chain ends at cancels. */
    function relBox(award, el) {
        return { x: absLeft(el) - absLeft(award), y: absTop(el) - absTop(award), w: el.offsetWidth, h: el.offsetHeight };
    }

    /* Is the image drawn mirrored (the right ScRule copy)? Its grid is then read from the
       other side. */
    function isFlipped(el) {
        var m = /^matrix\(([^)]+)\)$/.exec(computed(el).transform);
        return !!m && parseFloat(m[1].split(',')[0]) < 0;
    }

    /* Screen px per frame px: the SVG that holds the certificate scales it, and every
       ink box here comes from getBoundingClientRect and its relatives (spec §6.2). */
    function frameScale(award) {
        var w = award.offsetWidth;
        return w > 0 ? award.getBoundingClientRect().width / w : 1;
    }

    function resetFlourish(img, props) {
        (props || ['top', 'right', 'bottom', 'left', 'width', 'display']).forEach(function (p) { img.style.removeProperty(p); });
        if (!img.getAttribute('style')) { img.removeAttribute('style'); }
    }

    function setWidth(img, px) {
        var fontPx = pxOf(computed(img).fontSize) || 100;
        img.style.width = (px / fontPx).toFixed(4) + 'em';
    }

    /* The inked cells of a flourish as frame-px rectangles [x0, y0, x1, y1], with the
       box and grid they came from. Null when the image has no grid or no laid-out box
       (a hidden image, or one not decoded yet): there is nothing to judge then. */
    function inkOf(award, img) {
        var rows = decodeInk(img.getAttribute('data-sc-ink'));
        if (!rows) { return null; }
        var box = relBox(award, img);
        if (!(box.w > 0 && box.h > 0)) { return null; }
        var flip = isFlipped(img), cw = box.w / 32, ch = box.h / 32, rects = [];
        for (var r = 0; r < 32; r++) {
            if (!rows[r]) { continue; }
            for (var c = 0; c < 32; c++) {
                if ((rows[r] >>> (31 - c)) & 1) {
                    var cc = flip ? 31 - c : c;
                    rects.push([box.x + cc * cw, box.y + r * ch, box.x + (cc + 1) * cw, box.y + (r + 1) * ch]);
                }
            }
        }
        return { box: box, rows: rows, rects: rects };
    }

    /* The ink boxes of a node's text, frame px: Range.getClientRects() undone by the
       frame's scale. */
    function nodeRects(award, k, node) {
        var a = award.getBoundingClientRect(), range = document.createRange(), out = [];
        range.selectNodeContents(node);
        Array.prototype.forEach.call(range.getClientRects(), function (r) {
            if (r.width > 0 && r.height > 0) {
                out.push({ x0: (r.left - a.left) / k, y0: (r.top - a.top) / k, x1: (r.right - a.left) / k, y1: (r.bottom - a.top) / k });
            }
        });
        return out;
    }

    function unionOf(rects) {
        if (!rects.length) { return null; }
        return rects.reduce(function (u, r) {
            return { x0: Math.min(u.x0, r.x0), y0: Math.min(u.y0, r.y0), x1: Math.max(u.x1, r.x1), y1: Math.max(u.y1, r.y1) };
        });
    }

    function nodeRect(award, k, node) {
        return unionOf(nodeRects(award, k, node));
    }

    /*
     * Every text line an ornament must keep clear of (spec §6.6): the lines of each
     * engine role, the lead (one run of text, no lines) and the two quiet lines under
     * the issuer. NOT the versal: the top and bottom flourishes are drawn around
     * "Amtgard" by design, and the versal is not a role, so it is simply not listed.
     */
    function textBoxes(award, k) {
        var out = [];
        function add(node) { nodeRects(award, k, node).forEach(function (r) { out.push(r); }); }
        Array.prototype.forEach.call(award.querySelectorAll('[data-sc-role]'), function (role) {
            var lines = lineEls(role);
            if (lines.length) { lines.forEach(add); } else if (collapse(role.textContent)) { add(role); }
        });
        Array.prototype.forEach.call(award.querySelectorAll('.IssuingVerb, .AwardDetails'), function (el) {
            if (collapse(el.textContent)) { add(el); }
        });
        return out;
    }

    /* Does any inked cell meet a text box grown by `gap` on every side? */
    function hitsText(rects, boxes, gap) {
        for (var i = 0; i < rects.length; i++) {
            var c = rects[i];
            for (var j = 0; j < boxes.length; j++) {
                var b = boxes[j];
                if (c[0] < b.x1 + gap - INK_EPS && c[2] > b.x0 - gap + INK_EPS
                    && c[1] < b.y1 + gap - INK_EPS && c[3] > b.y0 - gap + INK_EPS) { return true; }
            }
        }
        return false;
    }

    /*
     * The free interval at a span of y, as raw edges: unlike freeWidth() it is NOT held
     * inside the column, because a flourish is allowed to hang past the column and what
     * matters to it is the artwork. A band with no data is unbounded.
     */
    function profileEdges(ctx, yTop, yBottom) {
        var l = -Infinity, r = Infinity, free = ctx.free;
        if (free) {
            var b0 = Math.floor((yTop - free.y0) / free.step);
            var b1 = Math.ceil((yBottom - free.y0) / free.step) - 1;
            if (b1 < b0) { b1 = b0; }
            for (var b = Math.max(b0, 0); b <= b1; b++) {
                if (free.L[b] != null && free.L[b] > l) { l = free.L[b]; }
                if (free.R[b] != null && free.R[b] < r) { r = free.R[b]; }
            }
        }
        return { l: l, r: r };
    }

    /* Does any inked cell fall outside the free interval of its band? */
    function hitsArtwork(ctx, rects) {
        if (!ctx.free) { return false; }
        for (var i = 0; i < rects.length; i++) {
            var e = profileEdges(ctx, rects[i][1], rects[i][3]);
            if (rects[i][0] < e.l - INK_EPS || rects[i][2] > e.r + INK_EPS) { return true; }
        }
        return false;
    }

    /*
     * Try each scale in turn (apply(s) puts the image(s) there) and keep the first that
     * is clear of every text box and, unless `skipArt`, of the artwork; hide the image(s)
     * when none is. Returns 'placed' (first candidate), 'scaled' or 'hidden'. An image with nothing to
     * judge it by (no grid, or no box yet) is left at its first candidate and reported
     * placed: the engine does not move what it cannot measure.
     */
    function chooseScale(ctx, imgs, scales, apply, boxes, gap, skipArt) {
        for (var i = 0; i < scales.length; i++) {
            apply(scales[i]);
            var clear = true, judged = true;
            for (var j = 0; j < imgs.length && clear; j++) {
                var ink = inkOf(ctx.award, imgs[j]);
                if (!ink) { judged = false; continue; }
                if (hitsText(ink.rects, boxes, gap) || (!skipArt && hitsArtwork(ctx, ink.rects))) { clear = false; }
            }
            if (!judged && i === 0) { return 'placed'; }
            if (clear) { return i === 0 ? 'placed' : 'scaled'; }
        }
        imgs.forEach(function (img) { img.style.display = 'none'; });
        return 'hidden';
    }

    /* The row, in frame px from the image's top, where its curl starts: the topmost
       inked row in the left 65% of the box (spec §6.5.1). Columns 0..20 are bits 31..11. */
    function curlOffset(rows, h) {
        for (var r = 0; r < 32; r++) { if ((rows[r] >>> 11) !== 0) { return r * h / 32; } }
        return 0;
    }

    function firstInkRow(rows, h) {
        for (var r = 0; r < 32; r++) { if (rows[r]) { return r * h / 32; } }
        return 0;
    }

    /* ------------------------------------------------------------------ the issuer */

    function issuerEls(award) {
        var lead = award.querySelector('[data-sc-role="lead"]');
        var place = award.querySelector('[data-sc-role="place"]');
        if (!lead || !place) { return null; }
        var p1 = lead.closest('p') || lead.parentElement;
        var p2 = place.closest('p') || place.parentElement;
        return {
            section: award.querySelector('.IssuingTextSection') || p1.parentElement,
            p1: p1, p2: p2, lead: lead, place: place,
            versal: award.querySelector('.ScVersal'),
            top: award.querySelector('#TopTextFlourish'),
            bottom: award.querySelector('#BottomTextFlourish'),
            right: award.querySelector('#RightTextFlourish'),
            verb: award.querySelector('.IssuingVerb'),
            rules: Array.prototype.slice.call(award.querySelectorAll('.ScRule')),
            rightBase: 0
        };
    }

    /* Put the issuer back to what the stylesheet draws, so each composition starts from
       the same page and a second run starts where the first did. */
    function resetIssuer(A) {
        A.p1.style.removeProperty('white-space');
        if (!A.p1.getAttribute('style')) { A.p1.removeAttribute('style'); }
        A.section.style.removeProperty('--sc-place-gutter');
        A.section.style.removeProperty('--sc-place-reserve');
        if (!A.section.getAttribute('style')) { A.section.removeAttribute('style'); }
        if (A.top) { resetFlourish(A.top, ['width', 'display']); }
        if (A.bottom) { resetFlourish(A.bottom, ['width', 'display']); }
        if (A.right) { resetFlourish(A.right); }
        A.rules.forEach(function (r) { resetFlourish(r); });
        if (A.verb) {
            A.verb.style.removeProperty('margin-top');
            if (!A.verb.getAttribute('style')) { A.verb.removeAttribute('style'); }
        }
    }

    function clamp(v, lo, hi) { return Math.min(Math.max(v, lo), hi); }

    function setVersal(ctx, A, nameSize) {
        if (!A.versal) { return; }
        var parentPx = pxOf(computed(A.versal.parentElement).fontSize);
        if (!(parentPx > 0) || !(ctx.rootPx > 0)) { return; }
        var size = setSize(A.versal, clamp(nameSize, CONST.VERSAL.min, CONST.VERSAL.max), ctx.rootPx, parentPx);
        A.versal.setAttribute('data-sc-size', size.toFixed(3));
    }

    function setLeadSize(ctx, A, size) {
        var parentPx = pxOf(computed(A.lead.parentElement).fontSize);
        if (!(parentPx > 0) || !(ctx.rootPx > 0)) { return size; }
        return setSize(A.lead, size, ctx.rootPx, parentPx);
    }

    /* The article rides at the lead's size (§6.4.1). The stylesheet sizes `.sc-art` from
       --sc-art-size, in em of the line it sits on, which is the place's own size, so the
       variable is written on the place and rewritten whenever the place's size moves. */
    function setArticle(ctx, A, size) {
        if (!A.place.querySelector('.sc-art')) { return; }
        var px = pxOf(computed(A.place).fontSize);
        if (px > 0 && ctx.rootPx > 0) { A.place.style.setProperty('--sc-art-size', (size * ctx.rootPx / px).toFixed(4) + 'em'); }
    }

    /* How wide the article makes line 0 at lead size `size`, with its space measured at
       the place's largest size (an upper bound: the real space is no wider). */
    function articleWidth(ctx, A, src, size, placeMax) {
        if (!src.art) { return 0; }
        var m = measureWords(A.place, [src.art]);
        return (m.measure(src.art) * size + m.space * placeMax) * ctx.rootPx;
    }

    /* The connective as one line, like every other role (spec §6.8): its text goes in an
       `.sc-line`, which the stylesheet makes an inline-block in Classic so it stays on the
       versal's row and a centred block in Centered. Returns its width in em at 1px, or
       null when it has no text. */
    function prepareLead(A) {
        var text = sourceText(A.lead);
        if (!text) { clearReport(A.lead); return null; }
        if (!lineEls(A.lead).length) { writeLines(A.lead, [text], -1, [0]); }
        var words = text.split(' ').filter(Boolean);
        var m = measureWords(A.lead, words), em = 0;
        words.forEach(function (w) { em += m.measure(w); });
        return { em: em + m.space * (words.length - 1) };
    }

    /* The lead's report: the size and the printed row count, and the width its line was
       held to. */
    function reportLead(A, size, overflow) {
        var lines = lineEls(A.lead);
        if (!lines.length) { return; }
        A.lead.setAttribute('data-sc-size', size.toFixed(3));
        A.lead.setAttribute('data-sc-planned', '1');
        A.lead.setAttribute('data-sc-lines', String(printedRows(lines)));
        if (overflow) { A.lead.setAttribute('data-sc-overflow', '1'); } else { A.lead.removeAttribute('data-sc-overflow'); }
    }

    /*
     * Size the lead to `avail` px: the largest size up to `cap` that fits, never below
     * `min`. Checked against the browser's own width afterwards, like every role.
     * Returns { size, fits }; a lead that cannot fit at `min` is set at `min` and does
     * not fit.
     */
    function sizeLead(ctx, A, k, m, avail, cap, min) {
        if (!m) { return { size: cap, fits: true }; }
        var line = lineEls(A.lead)[0];
        if (line) { line.setAttribute('data-sc-w', (avail * (1 - CONST.SLACK)).toFixed(2)); }
        var s = avail * (1 - CONST.SLACK) / (m.em * ctx.rootPx);
        var size = Math.min(cap, s);
        var fits = size >= min - SIZE_EPS;
        if (!fits) { size = min; }
        size = setLeadSize(ctx, A, size);
        /* Checked against the very width stamped in data-sc-w (avail less SLACK), so a line
           that passes here is a line the stamp does not contradict. A size is "at the
           floor" within a thousandth: setSize reads back a size rounded through four
           decimals of em, which never equals the request exactly. */
        var allowed = avail * (1 - CONST.SLACK);
        for (var round = 0; round < 4 && fits; round++) {
            var r = nodeRect(ctx.award, k, A.lead);
            var w = r ? r.x1 - r.x0 : 0;
            if (w <= allowed + VERIFY_TOL) { break; }
            if (size <= min + SIZE_EPS) { fits = false; break; }
            size = setLeadSize(ctx, A, Math.max(size * (allowed / w) * VERIFY_MARGIN, min));
        }
        if (fits && round === 4) {
            var last = nodeRect(ctx.award, k, A.lead);
            fits = !last || last.x1 - last.x0 <= allowed + VERIFY_TOL;
        }
        return { size: size, fits: fits };
    }

    /*
     * Solve the place and the lead together. The lead is capped by the place's size, and
     * the article that sits in the place is set at the lead's, so each waits on the other.
     * The first pass budgets the article at the lead's ceiling, which only over-reserves;
     * the second tries the lead's real size and is kept only if the lead did not grow
     * (a bigger article than the one planned for would overflow line 0). A composition
     * whose place sits under a lead that changes height (Centered) solves once more when
     * the place's top moved.
     */
    function converge(ctx, A, caps, placeFor, leadFor, placeMin) {
        var place = placeFor(caps.leadMax);
        var lead = leadFor(place ? place.size : caps.placeMax);
        if (place && lead.size < caps.leadMax - 0.01) {
            var budget = lead.size;
            var place2 = placeFor(budget);
            var lead2 = leadFor(place2 ? place2.size : caps.placeMax);
            if (lead2.size <= budget + 0.002) {
                place = place2; lead = lead2;
            } else {
                place = placeFor(caps.leadMax);
                lead = leadFor(place ? place.size : caps.placeMax);
            }
        }
        if (place && Math.abs(roleTop(ctx.award, A.p2) - place.y0) > VERIFY_TOL) {
            place = placeFor(Math.max(lead.size, caps.leadMin));
            lead = leadFor(place ? place.size : caps.placeMax);
        }
        setArticle(ctx, A, lead.size);
        reverifyPlace(ctx, A, place, lead.size, placeMin);
        return { place: place, lead: lead };
    }

    /*
     * The place's lines checked again after the article has been resized.
     *
     * setRole verified the place's line against its width with the article at the size that
     * was budgeted for it; the article's FINAL size is the lead's, written after the last
     * solve, and the two differ by whatever the lead moved between the budget and the answer.
     * A larger article than the one verified widens line 0, and nothing looked at it again
     * (the fresh-corpus case s02 printed a place line 444px wide in a 440.7px band). So the
     * lines are measured here against the width they carry in data-sc-w, which is the whole
     * line, article included, and the place is shrunk the way setRole shrinks it until they
     * fit, never below `placeMin`; a line that still does not fit there is flagged, as
     * setRole flags one. `place` is the report entry and is updated in place.
     */
    function reverifyPlace(ctx, A, place, leadSize, placeMin) {
        if (!place || place.overflow) { return; }
        var parentPx = pxOf(computed(A.place.parentElement).fontSize);
        if (!(parentPx > 0) || !(ctx.rootPx > 0)) { return; }
        var lines = lineEls(A.place);
        if (!lines.length) { return; }
        for (var round = 0; round <= VERIFY_ROUNDS; round++) {
            var widths = naturalWidths(lines), worst = 1;
            widths.forEach(function (w, j) {
                var allowed = parseFloat(lines[j].getAttribute('data-sc-w'));
                if (allowed > 0 && w > allowed + VERIFY_TOL) { worst = Math.min(worst, allowed / w); }
            });
            if (worst === 1) { return; }
            if (round === VERIFY_ROUNDS || place.size <= placeMin + SIZE_EPS) {
                place.overflow = true;
                A.place.setAttribute('data-sc-overflow', '1');
                return;
            }
            place.size = setSize(A.place, Math.max(place.size * worst * VERIFY_MARGIN, placeMin), ctx.rootPx, parentPx);
            A.place.setAttribute('data-sc-size', place.size.toFixed(3));
            setArticle(ctx, A, leadSize);
        }
    }

    /* Where the right flourish goes, vertically: its curl hangs ORN_GAP below the place's
       last line (§6.5.1). The text ink box's bottom is the descender line and then some;
       it is measured, as the spec asks, after the place is written. */
    function applyRight(ctx, A, k, scale) {
        var img = A.right;
        setWidth(img, A.rightBase * scale);
        var box = relBox(ctx.award, img), rows = decodeInk(img.getAttribute('data-sc-ink'));
        var lines = lineEls(A.place), last = lines.length ? nodeRect(ctx.award, k, lines[lines.length - 1]) : null;
        if (!rows || !last || !(box.h > 0)) { return; }
        var host = img.offsetParent, hostY = host ? relBox(ctx.award, host).y + host.clientTop : 0;
        var fontPx = pxOf(computed(img).fontSize) || ctx.rootPx;
        var topFrame = last.y1 + CONST.ORN_GAP * ctx.rootPx + INK_EPS - curlOffset(rows, box.h);
        img.style.top = ((topFrame - hostY) / fontPx).toFixed(4) + 'em';
        img.style.bottom = 'auto';
    }

    /* How tall the place's text box is, per em of its size: the font's content area, a
       little over 1. Measured from whatever the place holds now; 1.25 when nothing
       can be measured. */
    function contentRatio(ctx, k, el) {
        var r = nodeRect(ctx.award, k, el), px = pxOf(computed(el).fontSize);
        return r && px > 0 ? clamp((r.y1 - r.y0) / px, 1, 1.6) : 1.25;
    }

    /* Classic (§6.5.1). */
    function issuerClassic(ctx, A, caps, st, relaxed) {
        var award = ctx.award, root = ctx.rootPx, col = ctx.column, gap = CONST.ORN_GAP * root;
        var P = CONST.ROLES.placeClassic, lh = P.lineHeight;

        resetIssuer(A);
        /* The versal and the lead share one row in Classic and the lead never wraps. Left
           to wrap, a lead too wide for what the versal leaves goes under it, its left edge
           becomes the column's and the whole column reads as available. Held on the row
           it overflows to the right instead, its left edge stays true, and it is shrunk
           to the room there is. */
        A.p1.style.whiteSpace = 'nowrap';
        A.section.setAttribute('data-sc-comp', 'classic');
        var k = frameScale(award);
        var leadM = prepareLead(A);

        /* The place's smallest size for Classic to count as feasible. A forced Classic
           that cannot meet it is solved again at the table's own floor. */
        var pmin = relaxed ? caps.placeMin
            : Math.max(caps.placeMin, Math.min(CONST.CLASSIC_PLACE_MIN * ctx.floorScale, caps.placeMax));

        /* The geometry that does not depend on the place's text: the place's box
           [xL, xR] and the bands the lead and the place occupy. The lead is held at its
           ceiling while the versal line is measured, because its line is the tallest the
           lead can make. */
        setLeadSize(ctx, A, caps.leadMax);
        var y0 = roleTop(award, A.p2);
        var placeBand = { top: y0, bottom: y0 + lh * caps.placeMax * root };
        var xL = col.left, xR = col.right;

        /* gutter: the bottom flourish's rightmost ink in the place's rows, plus ORN_GAP. */
        var bot = A.bottom ? inkOf(award, A.bottom) : null;
        if (bot) {
            bot.rects.forEach(function (r) {
                if (r[3] > placeBand.top - gap && r[1] < placeBand.bottom + gap) { xL = Math.max(xL, r[2] + gap); }
            });
        }

        /* reserve: where the right flourish's stem ink starts, in the rows the place's
           text box can reach. With the curl placed ORN_GAP below the text, those rows lie
           above the curl row, so only the stem is in them. The box is the one at the
           natural scale: a smaller flourish hangs further right, so this is the larger
           reserve and holds for every scale the ornament pass may pick. */
        var rightOn = A.right && !st.rightOff;
        if (rightOn) {
            A.rightBase = A.right.offsetWidth;
            var rink = inkOf(award, A.right);
            if (rink) {
                var tMax = contentRatio(ctx, k, A.place) * caps.placeMax * root;
                var off = curlOffset(rink.rows, rink.box.h), ch = rink.box.h / 32, cw = rink.box.w / 32;
                var lo = off - 2 * gap - tMax - INK_EPS, hi = off - INK_EPS, stem = Infinity;
                for (var r = 0; r < 32; r++) {
                    if (!rink.rows[r] || (r + 1) * ch <= lo || r * ch >= hi) { continue; }
                    for (var c = 0; c < 32; c++) {
                        if ((rink.rows[r] >>> (31 - c)) & 1) { stem = Math.min(stem, rink.box.x + c * cw); break; }
                    }
                }
                xR = Math.min(xR, stem - gap);
            }
        }
        var edges = profileEdges(ctx, placeBand.top, placeBand.bottom);
        xL = Math.max(xL, edges.l);
        xR = Math.min(xR, edges.r);
        A.section.style.setProperty('--sc-place-gutter', Math.max(0, xL - col.left).toFixed(2) + 'px');
        A.section.style.setProperty('--sc-place-reserve', Math.max(0, col.right - xR).toFixed(2) + 'px');

        var src = placeSource(A.place), artSize = caps.leadMax;
        function placeFor(budget) {
            artSize = budget;
            return setRole(ctx, A.place, 'place', caps.placeMax, pmin, {
                row: P, solverRole: 'place', range: { sat: P.sat, uMax: P.max, linePenalty: P.linePenalty },
                maxLines: 1, source: placeSource, y0: y0,
                width: function () { return Math.max(0, xR - xL); },
                artPx: articleWidth(ctx, A, src, budget, caps.placeMax),
                onSize: function () { setArticle(ctx, A, artSize); }
            });
        }

        var leadBand = null;
        function leadFor(placeSize) {
            var cap = Math.min(caps.leadMax, Math.max(caps.leadMin, placeSize));
            setLeadSize(ctx, A, cap);
            if (rightOn) { applyRight(ctx, A, k, 1); }

            /* The lead runs from its left edge to the column's, or to where the right
               flourish's ink begins in the lead's rows, less ORN_GAP, if that is sooner. */
            var rect = nodeRect(award, k, A.lead), avail = col.width, right = col.right;
            if (rect) {
                leadBand = { top: rect.y0 - gap, bottom: rect.y1 + gap };
                var ink = rightOn ? inkOf(award, A.right) : null;
                if (ink) {
                    ink.rects.forEach(function (c2) {
                        if (c2[3] > leadBand.top && c2[1] < leadBand.bottom) { right = Math.min(right, c2[0] - gap); }
                    });
                }
                right = Math.min(right, profileEdges(ctx, leadBand.top, leadBand.bottom).r);
                avail = right - rect.x0;
            }
            return sizeLead(ctx, A, k, leadM, avail, cap, caps.leadMin);
        }

        var res = converge(ctx, A, caps, placeFor, leadFor, pmin);

        /* Feasibility (§6.5.1): the lead fits at its floor, the place fits one line at
           pmin, and the profile is clear across the column for both bands. */
        var clear = true;
        if (ctx.free) {
            [placeBand, leadBand].forEach(function (b) {
                if (!b) { return; }
                var e = profileEdges(ctx, b.top, b.bottom);
                if (e.l > col.left + INK_EPS || e.r < col.right - INK_EPS) { clear = false; }
            });
        }
        var placeOk = !res.place || !res.place.overflow;
        var feasible = res.lead.fits && placeOk && clear;
        return { comp: 'classic', feasible: feasible, place: res.place, lead: res.lead };
    }

    /*
     * Where one half of the Centered rule goes at scale `s`: `widest` is the place's widest
     * line, `last` its last line's ink box. The halves meet at the column centre with
     * RULE.gapEm between them, and the topmost inked row sits ORN_GAP under the line.
     * Writes the image's inline geometry and returns the frame-px y of its lowest inked
     * row, or null when it has no grid or box to go by.
     */
    function applyRule(ctx, A, img, i, s, widest, last) {
        var award = ctx.award, gap = CONST.ORN_GAP * ctx.rootPx;
        img.style.display = 'block';
        var fontPx = pxOf(computed(img).fontSize) || ctx.rootPx;
        var w = Math.min(CONST.RULE.share * widest, CONST.RULE.maxEm * fontPx) * s;
        setWidth(img, w);
        var rows = decodeInk(img.getAttribute('data-sc-ink')), box = relBox(award, img);
        if (!rows || !(box.h > 0)) { return null; }
        var host = img.offsetParent, hostBox = host ? relBox(award, host) : { x: 0, y: 0 };
        var hostX = hostBox.x + (host ? host.clientLeft : 0), hostY = hostBox.y + (host ? host.clientTop : 0);
        var half = CONST.RULE.gapEm * fontPx / 2;
        var x = i === 0 ? ctx.column.cx - half - w : ctx.column.cx + half;
        var top = last.y1 + gap + INK_EPS - firstInkRow(rows, box.h);
        img.style.left = ((x - hostX) / fontPx).toFixed(4) + 'em';
        img.style.top = ((top - hostY) / fontPx).toFixed(4) + 'em';
        img.style.right = 'auto';
        img.style.bottom = 'auto';
        var lastRow = 31;
        while (lastRow > 0 && !rows[lastRow]) { lastRow--; }
        return top + (lastRow + 1) * box.h / 32;
    }

    /* The widest of the place's lines and the box of its last, frame px; null with no
       lines. */
    function placeExtent(award, k, A) {
        var lines = lineEls(A.place), widest = 0, last = null;
        lines.forEach(function (l, j) {
            var r = nodeRect(award, k, l);
            if (!r) { return; }
            widest = Math.max(widest, r.x1 - r.x0);
            if (j === lines.length - 1) { last = r; }
        });
        return last ? { widest: widest, last: last } : null;
    }

    /*
     * The rule pair hangs under the last place line and needs a rule's height of clear
     * paper there, which the gap above "presents" does not have. The room is made before
     * the budget is judged, by raising the verb's top margin by what is missing, so the
     * stack the budget sees is the stack that prints. A stylesheet that already leaves the
     * room adds nothing here.
     */
    function reserveRuleRoom(ctx, A, k) {
        var ext = placeExtent(ctx.award, k, A);
        if (!ext || A.rules.length !== 2 || !A.verb || !collapse(A.verb.textContent)) { return; }
        var bottom = applyRule(ctx, A, A.rules[0], 0, 1, ext.widest, ext.last);
        if (bottom === null) { return; }
        var gap = CONST.ORN_GAP * ctx.rootPx;
        var deficit = bottom + gap - relBox(ctx.award, A.verb).y;
        if (deficit > INK_EPS) {
            A.verb.style.marginTop = (num(computed(A.verb).marginTop) + deficit).toFixed(2) + 'px';
        }
        A.rules[0].style.display = '';
    }

    /* Centered (§6.5.2): the versal, the lead and the place each on lines of their own,
       centred. Nothing here can be infeasible; what does not fit is set at its floor
       and flagged. */
    function issuerCentered(ctx, A, caps) {
        var award = ctx.award;
        var PC = CONST.ROLES.placeCentered;

        resetIssuer(A);
        A.section.setAttribute('data-sc-comp', 'centered');
        var k = frameScale(award);
        var leadM = prepareLead(A);
        var leadMax = Math.min(caps.leadMax, CONST.LEAD_CENTERED_MAX);
        var ccaps = { leadMax: leadMax, leadMin: Math.min(caps.leadMin, leadMax), placeMax: caps.placeMax, placeMin: caps.placeMin };

        setLeadSize(ctx, A, leadMax);
        var src = placeSource(A.place), artSize = leadMax;
        function placeFor(budget) {
            artSize = budget;
            return setRole(ctx, A.place, 'place', ccaps.placeMax, ccaps.placeMin, {
                row: PC, solverRole: 'place', range: { sat: PC.sat, uMax: PC.max, linePenalty: PC.linePenalty },
                maxLines: 2, source: placeSource, y0: roleTop(award, A.p2),
                artPx: articleWidth(ctx, A, src, budget, ccaps.placeMax),
                onSize: function () { setArticle(ctx, A, artSize); }
            });
        }
        function leadFor(placeSize) {
            var cap = Math.min(ccaps.leadMax, Math.max(ccaps.leadMin, placeSize));
            setLeadSize(ctx, A, cap);
            var rect = nodeRect(award, k, A.lead);
            var avail = rect ? freeWidth(ctx, rect.y0, rect.y1, true) : ctx.column.width;
            return sizeLead(ctx, A, k, leadM, avail, cap, ccaps.leadMin);
        }

        var res = converge(ctx, A, ccaps, placeFor, leadFor, ccaps.placeMin);
        reserveRuleRoom(ctx, A, k);
        return { comp: 'centered', feasible: true, place: res.place, lead: res.lead };
    }

    /*
     * The issuer solve for a given hierarchy: the versal, then the composition by the
     * choice of §6.5.3. `st` carries the name's and the award's sizes (null before they
     * are known, which is the provisional solve with no hierarchy cap), and `rightOff`
     * when the right flourish is known to be hidden and so constrains nothing.
     */
    function layoutIssuer(ctx, A, st) {
        var L = CONST.ROLES.lead, P = CONST.ROLES.placeClassic, fs = ctx.floorScale, R = CONST.RATIOS;
        var placeMin = Math.min(P.min * fs, P.max);
        var placeMax = P.max;
        if (st.nameSize != null) { placeMax = Math.min(placeMax, R.placeToName * st.nameSize); }
        if (st.awardSize != null) { placeMax = Math.min(placeMax, R.placeToAward * st.awardSize); }
        placeMax = Math.max(placeMax, placeMin);
        var caps = { placeMin: placeMin, placeMax: placeMax, leadMin: Math.min(L.min * fs, L.max), leadMax: L.max };

        setVersal(ctx, A, st.nameSize != null ? st.nameSize : CONST.ROLES.name.max);

        var forced = st.comp || ctx.heading;
        var res;
        if (forced === 'centered') {
            res = issuerCentered(ctx, A, caps);
        } else {
            res = issuerClassic(ctx, A, caps, st, false);
            if (!res.feasible) {
                if (forced === 'classic') {
                    /* A forced Classic renders anyway, at the floors, and says so. */
                    res = issuerClassic(ctx, A, caps, st, true);
                    res.feasible = false;
                    if (res.place) { A.place.setAttribute('data-sc-overflow', '1'); res.place.overflow = true; }
                    if (!res.lead.fits) { reportLead(A, res.lead.size, true); }
                } else {
                    res = issuerCentered(ctx, A, caps);
                }
            }
        }
        if (res.lead) { reportLead(A, res.lead.size, !res.lead.fits); }
        res.caps = caps;
        return res;
    }

    /*
     * The hierarchy of §6.4.2, held against the FINAL name and award. The issuer is
     * solved before the name settles (the name's top moves with the issuer's height), so
     * a name that shrinks afterwards can leave the place above 0.75 of it. Sizes only
     * come down here, which can never overflow a line, and the lead and article follow.
     */
    function enforceHierarchy(ctx, A, res, nameSize, awardSize) {
        setVersal(ctx, A, nameSize);
        var caps = res.caps, P = CONST.ROLES.placeClassic, R = CONST.RATIOS;
        var cap = Math.max(Math.min(P.max, R.placeToName * nameSize, R.placeToAward * awardSize), caps.placeMin);
        if (res.place && res.place.size > cap + 0.0005) {
            var parentPx = pxOf(computed(A.place.parentElement).fontSize);
            if (parentPx > 0) {
                res.place.size = setSize(A.place, cap, ctx.rootPx, parentPx);
                A.place.setAttribute('data-sc-size', res.place.size.toFixed(3));
            }
        }
        var leadCap = Math.min(res.comp === 'centered' ? Math.min(caps.leadMax, CONST.LEAD_CENTERED_MAX) : caps.leadMax,
            Math.max(caps.leadMin, res.place ? res.place.size : caps.placeMax));
        if (res.lead && res.lead.size > leadCap + 0.0005) {
            res.lead.size = setLeadSize(ctx, A, leadCap);
            reportLead(A, res.lead.size, !res.lead.fits);
        }
        if (res.lead) { setArticle(ctx, A, res.lead.size); }
    }

    /*
     * Ornament placement (§6.6), after the text is final. Each flourish is tried placed,
     * then at 0.85 and 0.70, then hidden; the Centered rule pair at 1 and 0.8. Returns
     * { top, right, bottom, rule } as 'placed' | 'scaled' | 'hidden'.
     */
    function placeOrnaments(ctx, A, comp, st) {
        var award = ctx.award, k = frameScale(award), gap = CONST.ORN_GAP * ctx.rootPx;
        var boxes = textBoxes(award, k), out = { top: 'hidden', right: 'hidden', bottom: 'hidden', rule: 'hidden' };

        /* A flourish the stylesheet draws at some width: scaled in place. */
        function inPlace(img, skipArt) {
            if (!img) { return 'hidden'; }
            img.style.removeProperty('width');
            if (!(img.offsetWidth > 0)) { return computed(img).display === 'none' ? 'hidden' : 'placed'; }
            var base = img.offsetWidth;
            return chooseScale(ctx, [img], CONST.ORN_SCALES, function (s) {
                if (s === 1) { resetFlourish(img, ['width']); } else { setWidth(img, base * s); }
            }, boxes, gap, skipArt);
        }

        /* #TopTextFlourish and #BottomTextFlourish are one piece with the versal: where
           they sit is where the artwork was designed to leave room for "Amtgard", so they
           are not judged against the artwork, only against the text. Only the right
           flourish and the rule pair give way to the artwork. */
        out.top = inPlace(A.top, true);

        if (comp === 'classic') {
            out.bottom = inPlace(A.bottom, true);
            if (A.right && !st.rightOff && A.rightBase > 0) {
                out.right = chooseScale(ctx, [A.right], CONST.ORN_SCALES, function (s) { applyRight(ctx, A, k, s); }, boxes, gap);
            } else if (A.right) {
                A.right.style.display = 'none';
            }
            A.rules.forEach(function (r) { r.style.display = 'none'; });
            return out;
        }

        /* Centered: the line-two flourishes go, the rule pair takes their place. */
        [A.bottom, A.right].forEach(function (img) { if (img) { img.style.display = 'none'; } });
        var ext = placeExtent(award, k, A);
        if (A.rules.length === 2 && ext) {
            out.rule = chooseScale(ctx, A.rules, CONST.RULE_SCALES, function (s) {
                A.rules.forEach(function (img, i) { applyRule(ctx, A, img, i, s, ext.widest, ext.last); });
            }, boxes, gap);
        } else {
            A.rules.forEach(function (r) { r.style.display = 'none'; });
        }
        return out;
    }

    /* -------------------------------------------------------------------- the budget */

    function num(v) {
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    function outerHeight(el) {
        var cs = computed(el);
        return el.offsetHeight + num(cs.marginTop) + num(cs.marginBottom);
    }

    /* An element's height with nothing growing it: flex-grow off for the read, put back
       after. With nothing growing it and nothing shrinking it, a flex item is the larger
       of its min-height, its flex-basis and its content, which is what the budget wants. */
    function naturalOuter(el) {
        var g = el.style.flexGrow, s = el.style.flexShrink;
        el.style.flexGrow = '0';
        el.style.flexShrink = '0';
        var h = outerHeight(el);
        el.style.flexGrow = g;
        el.style.flexShrink = s;
        if (!el.getAttribute('style')) { el.removeAttribute('style'); }
        return h;
    }

    /* The child of the .Award that holds `el`. */
    function topChildOf(award, el) {
        while (el && el.parentElement !== award) { el = el.parentElement; }
        return el;
    }

    /*
     * What the page needs below the award line (§6.7): everything after it, at the
     * smallest it can be. The elastic spacer at its min-height, the citation at its
     * natural text height capped at six of its lines, the signature block and the rest
     * at their natural heights, and the bare spacer divs at nothing.
     */
    function bottomNeed(award, awardEl) {
        var need = 0, el = awardEl ? topChildOf(award, awardEl) : null;
        el = el ? el.nextElementSibling : null;
        while (el) {
            var cs = computed(el);
            if (el.classList.contains('ScSpacer')) {
                var min = num(cs.minHeight) || 0.3 * (num(cs.fontSize) || 0);
                need += min + num(cs.marginTop) + num(cs.marginBottom);
            } else if (el.classList.contains('Award_Reason')) {
                var p = el.querySelector('p');
                var lh = cs.lineHeight === 'normal' ? 1.2 * num(cs.fontSize) : num(cs.lineHeight);
                need += (p ? Math.min(p.offsetHeight, 6 * lh) : el.offsetHeight) + num(cs.marginTop) + num(cs.marginBottom);
            } else if (el.tagName === 'DIV' && !el.className && !collapse(el.textContent) && !el.children.length) {
                /* a bottom spacer: elastic, and nothing at its smallest */
            } else {
                need += naturalOuter(el);
            }
            el = el.nextElementSibling;
        }
        return need;
    }

    function stackHeight(award, firstEl, awardEl) {
        var a = firstEl ? topChildOf(award, firstEl) : null, b = awardEl ? topChildOf(award, awardEl) : null;
        if (!a || !b) { return null; }
        return absTop(b) + b.offsetHeight - absTop(a);
    }

    /* ------------------------------------------------------------------ layoutTop */

    function now() {
        return (window.performance && window.performance.now) ? window.performance.now() : Date.now();
    }

    /*
     * layoutTop(award, opts) -> Report
     *
     *   Report = { comp, ms, budget, roles: { name?, award?, lead?, place? },
     *              ornaments: { top?, right?, bottom?, rule? } }
     *
     * The pipeline of spec §4, in this order:
     *   1. read the context;
     *   2. a provisional issuer solve with no hierarchy cap, so the name is measured at
     *      the top the issuer will roughly leave it;
     *   3. the name, at its measured top;
     *   4. the award, under 0.85 of the name's FINAL size;
     *   5. the final issuer solve under the hierarchy of §6.4.2, and one re-solve of the
     *      name and award if that changed the issuer's height;
     *   6. the budget (§6.7): while the top stack is taller than its share of the page,
     *      the name's ceiling comes down 8% and steps 3-5 run again, three times at most;
     *   7. the ornaments, from the text as it finally stands.
     * Multi-up lifts every minimum (§6.4.1). A page with no issuer roles (the lead and
     * the place) skips steps 2 and 5, and a page with no name or award skips theirs.
     *
     * `opts.nameMax` lowers the name's ceiling before the budget loop starts.
     */
    function layoutTop(award, opts) {
        var t0 = now();
        var o = opts || {};
        var ctx = readContext(award);
        var N = CONST.ROLES.name, A = CONST.ROLES.award, fs = ctx.floorScale;
        var report = { comp: ctx.heading, ms: 0, budget: 'ok', roles: {}, ornaments: {} };

        var nameEl = award.querySelector('[data-sc-role="name"]');
        var awardEl = award.querySelector('[data-sc-role="award"]');
        var I = issuerEls(award);

        /* What the page below the award line needs does not depend on the top, so it is
           read once, before the top is touched. The room the top may take is read each
           time it is judged: on a page whose height follows its content that makes the
           budget vacuous, which is right, and on a frame of fixed height it is the same
           number every time. */
        var below = bottomNeed(award, awardEl);
        function topRoom() {
            var cs = computed(award);
            var content = award.clientHeight - pxOf(cs.paddingTop) - pxOf(cs.paddingBottom);
            return content > 0 ? Math.max(CONST.H_TOP_MIN * content, content - below) : null;
        }

        var nameMaxCap = o.nameMax > 0 ? Math.min(o.nameMax, N.max) : N.max;
        var nameFloor = Math.min(N.min * fs, nameMaxCap);
        var awardMin = Math.min(A.min * fs, A.max);

        if (I) { layoutIssuer(ctx, I, { nameSize: null, awardSize: null }); }

        /* Steps 3-5. */
        function fitNameAndAward(cap) {
            var name = setRole(ctx, nameEl, 'name', cap, nameFloor);
            var nameSize = name ? name.size : N.max;
            var awardMax = Math.max(Math.min(A.max, CONST.RATIOS.awardToName * nameSize), awardMin);
            var aw = setRole(ctx, awardEl, 'award', awardMax, awardMin);
            return { name: name, award: aw };
        }
        function fitStack(cap) {
            var fit = fitNameAndAward(cap);
            if (I) {
                var h0 = I.section.offsetHeight;
                fit.issuer = layoutIssuer(ctx, I, {
                    nameSize: fit.name ? fit.name.size : null,
                    awardSize: fit.award ? fit.award.size : null
                });
                if (Math.abs(I.section.offsetHeight - h0) > VERIFY_TOL) {
                    var again = fitNameAndAward(cap);
                    fit.name = again.name;
                    fit.award = again.award;
                }
            }
            return fit;
        }

        var fit = fitStack(nameMaxCap);
        var firstEl = I ? I.lead : (nameEl || awardEl);
        var stack = stackHeight(award, firstEl, awardEl);

        var room = topRoom();
        if (room !== null && stack !== null) {
            for (var round = 0; round < CONST.BUDGET_ROUNDS && stack > room + VERIFY_TOL && fit.name; round++) {
                var cur = Math.min(nameMaxCap, fit.name.size);
                var next = Math.max(CONST.NAME_SHRINK * cur, nameFloor);
                if (next >= cur - 1e-6) { break; }
                nameMaxCap = next;
                fit = fitStack(nameMaxCap);
                stack = stackHeight(award, firstEl, awardEl);
                room = topRoom();
            }
            report.budget = stack <= room + VERIFY_TOL ? 'ok' : 'floor';
        }

        if (fit.name) { report.roles.name = fit.name; }
        if (fit.award) { report.roles.award = fit.award; }

        if (I) {
            var nameSize = fit.name ? fit.name.size : N.max;
            var awardSize = fit.award ? fit.award.size : A.max;
            var res = fit.issuer;
            enforceHierarchy(ctx, I, res, nameSize, awardSize);

            var orn = placeOrnaments(ctx, I, res.comp, {});
            if (res.comp === 'classic' && orn.right === 'hidden' && I.right && I.rightBase > 0) {
                /* The right flourish did not fit anywhere, so what it reserved is room
                   the type could have had: solve the issuer again without it. */
                res = layoutIssuer(ctx, I, { nameSize: nameSize, awardSize: awardSize, rightOff: true, comp: 'classic' });
                enforceHierarchy(ctx, I, res, nameSize, awardSize);
                orn = placeOrnaments(ctx, I, 'classic', { rightOff: true });
            }

            report.comp = res.comp;
            report.ornaments = orn;
            if (res.lead) { report.roles.lead = { size: res.lead.size, lines: Number(I.lead.getAttribute('data-sc-lines')), planned: 1, overflow: !res.lead.fits }; }
            if (res.place) { report.roles.place = res.place; }
            award.setAttribute('data-sc-comp', res.comp);
        } else if (report.comp) {
            award.setAttribute('data-sc-comp', report.comp);
        } else {
            award.removeAttribute('data-sc-comp');
        }
        award.setAttribute('data-sc-budget', report.budget);

        report.ms = now() - t0;
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
