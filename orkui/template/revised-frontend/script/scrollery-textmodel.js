/*
 * Scrollery — the issuer text model, browser half.
 *
 * Turns a stored issuer ("The Kingdom of the Wetlands") into the pieces a certificate
 * sets in separate sizes: the connective after "Amtgard" ("and the Kingdom of"), the
 * article ("the") and the proper name ("Wetlands").
 *
 * This is a twin of ScrollText::IssuerLines() in system/lib/ork3/class.ScrollText.php,
 * and the two must agree to the letter, or the composer previews one certificate and
 * prints another. Both are tested against one file, tests/fixtures/scroll-text-model.json
 * (PHP: tests/Unit/ScrollTextModelTest.php; here: tools/scroll-sweep/tests/textmodel.spec.ts).
 * Change a rule in one place and the fixture drags the other along. Rules are spec 5.2.
 *
 * Pure: no DOM, no window. It loads as a browser global (window.ScrolleryText) and under
 * Node's require() for the tests.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScrolleryText = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /*
     * "Freeholds" is plural on purpose: "The Freeholds of Amtgard" is the stored name.
     * Þe is the archaic article a retired kingdom used. The `i` flag alone folds Þ/þ,
     * so the pattern needs no `u` flag.
     */
    var KINGDOM_SPLIT = /^(?:(?:The|Þe)\s+)?(Kingdom|Empire|Principality|Grand Duchy|Duchy|Freeholds|Confederacy)\s+of\s+(.+)$/i;
    var LEADING_THE   = /^the\s+(.+)$/i;

    function tidy(s) {
        return String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
    }

    /*
     * Same inputs and outputs as the PHP. The last four keys echo the effective form
     * after any split — the title, flags and name that, fed back in, print the same
     * lines — and are what the composer prefills from, so `name` keeps its article
     * ("the Wetlands"). scopeType decides one thing: on a 'park' an empty title takes the
     * Barony floor, as Scroll::BuildScroll does through ScrollText::IssuerTitle(), so a
     * cleared title box previews what it prints.
     */
    function issuerLines(title, name, useThe, useOf, scopeType) {
        title = tidy(title);
        name  = tidy(name);
        useThe = !!useThe;
        useOf  = !!useOf;

        // "Inactive" is a park status that leaked into ork_parktitle.title on 5 parks.
        if (title.toLowerCase() === 'inactive') { title = ''; }
        if (title === '' && scopeType === 'park') { title = 'Barony'; }

        // The stored kingdom shape: one whole phrase, no separate title or flags.
        // "and the" + title + "of" would print the rank word twice, so take it apart.
        // Anything an officer has typed or ticked is left exactly as given.
        var m;
        if (title === '' && !useThe && !useOf) {
            m = KINGDOM_SPLIT.exec(name);
            if (m) {
                title  = m[1];
                useThe = true;
                useOf  = true;
                name   = m[2];
            }
        }

        // The name can already carry the title's rank word ("The Principality of the
        // Crimson Sands" under title Principality), which printed it twice. Keep the
        // title and let the name be the place. Same rank word plus " of" only. The
        // name's own "of" goes only when the lead supplies one (useOf); otherwise it
        // stays with the place ("and Barony" + "of Iron Keep"). Twin of
        // ScrollText::IssuerLines().
        if (title !== '') {
            m = new RegExp('^(?:(?:The|Þe)\\s+)?' + title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s+(of\\s+(.+))$', 'i').exec(name);
            if (m) { name = useOf ? m[2] : m[1]; }
        }

        // "the" and "of" need a rank word to hang on; with none they would print
        // "and the  of".
        var lead = 'and';
        if (title !== '') {
            lead += (useThe ? ' the' : '') + ' ' + title + (useOf ? ' of' : '');
        }

        // Lifted out of the place only when the lead ends in " of". Otherwise the
        // article belongs to an unsplit name ("The Celestial Kingdom") and stays.
        var article = '';
        var place   = name;
        if (lead.slice(-3) === ' of') {
            m = LEADING_THE.exec(name);
            if (m) {
                article = 'the';
                place   = m[1];
            }
        }

        return {
            lead: lead,
            article: article,
            place: place,
            title: title,
            useThe: useThe,
            useOf: useOf,
            name: name
        };
    }

    /*
     * Display-time repair of double-encoded text (UTF-8 decoded as Windows-1252 and
     * encoded again): "â€œHeadâ€" for “Head”, "Ã©" for "é". Twin of
     * ScrollText::RepairMojibake(); the shared fixture's "repair" cases pin both.
     *
     * Each run of a UTF-8 lead byte (U+00C2-U+00F4) plus its continuation bytes, as
     * Windows-1252 shows them, is mapped back to bytes and kept only if they are valid
     * UTF-8 and not themselves a mojibake run. Everything else is returned as it came
     * in, so clean text and a lone "Ã" that is only a letter are never altered.
     */
    var WIN1252 = {
        0x20AC: 0x80, 0x201A: 0x82, 0x0192: 0x83, 0x201E: 0x84, 0x2026: 0x85,
        0x2020: 0x86, 0x2021: 0x87, 0x02C6: 0x88, 0x2030: 0x89, 0x0160: 0x8A,
        0x2039: 0x8B, 0x0152: 0x8C, 0x017D: 0x8E, 0x2018: 0x91, 0x2019: 0x92,
        0x201C: 0x93, 0x201D: 0x94, 0x2022: 0x95, 0x2013: 0x96, 0x2014: 0x97,
        0x02DC: 0x98, 0x2122: 0x99, 0x0161: 0x9A, 0x203A: 0x9B, 0x0153: 0x9C,
        0x017E: 0x9E, 0x0178: 0x9F
    };
    var CONT = '[\\u0080-\\u00BF\\u20AC\\u201A\\u0192\\u201E\\u2026\\u2020\\u2021'
             + '\\u02C6\\u2030\\u0160\\u2039\\u0152\\u017D\\u2018\\u2019\\u201C\\u201D\\u2022'
             + '\\u2013\\u2014\\u02DC\\u2122\\u0161\\u203A\\u0153\\u017E\\u0178]';
    var RUN_SRC = '[\\u00C2-\\u00DF]' + CONT + '|[\\u00E0-\\u00EF]' + CONT + '{2}|[\\u00F0-\\u00F4]' + CONT + '{3}';
    var RUN = new RegExp(RUN_SRC, 'g');
    var RUN_TEST = new RegExp(RUN_SRC);

    function repairRun(run) {
        var pct = '';
        for (var i = 0; i < run.length; i++) {
            var cp = run.charCodeAt(i);
            var b = Object.prototype.hasOwnProperty.call(WIN1252, cp) ? WIN1252[cp] : (cp <= 0xFF ? cp : -1);
            if (b < 0) { return run; }
            pct += '%' + (b < 16 ? '0' : '') + b.toString(16);
        }
        var out;
        try { out = decodeURIComponent(pct); } catch (e) { return run; }   /* not valid UTF-8 */
        return RUN_TEST.test(out) ? run : out;
    }

    function repairMojibake(s) {
        s = String(s == null ? '' : s);
        if (!/[^\x00-\x7F]/.test(s)) { return s; }
        return s.replace(RUN, repairRun);
    }

    return { issuerLines: issuerLines, repairMojibake: repairMojibake };
}));
