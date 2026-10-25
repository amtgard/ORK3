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
 * THE PURE CORE
 *
 * Everything in this file so far is arithmetic on strings and numbers: it touches no
 * DOM, no `window` and no `document`, which is what lets a Node test drive it in
 * milliseconds without the app up. (The one rule for anything added later: reach for
 * `window` / `document` only inside a function, never at load, or `require()` under
 * Node dies.) It loads both ways:
 *
 *     browser   window.ScrolleryTypeset
 *     Node      require('.../scrollery-typeset.js')
 *
 * WHAT IS HERE
 *
 *   CONST        the role table and every tunable number, copied from spec §6.4.1 and
 *                §6.3.2. T14 calibrates them against the sweep; they are plain data on
 *                purpose, so a calibration run edits a value and nothing else.
 *   tokenize     text -> words, with protected spans held together and the honorific
 *                and epithet roles marked (§6.3.1).
 *   breakPenalty what it costs to break a line in the gap after a word (§6.3.2).
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

    var NBSP = ' ';

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
     * Escapes, not literals, so the curly quotes are visible in review.
     */
    var EDGE_PUNCT = /^[\s"'“”‘’()\[\]{}<>,.;:!?*…–—-]+|[\s"'“”‘’()\[\]{}<>,.;:!?*…–—-]+$/g;

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

    return {
        CONST: CONST,
        tokenize: tokenize,
        breakPenalty: breakPenalty
    };
}));
