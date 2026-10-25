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
     * ("the Wetlands"). scopeType is accepted for signature parity; no rule uses it.
     */
    function issuerLines(title, name, useThe, useOf, scopeType) {
        title = tidy(title);
        name  = tidy(name);
        useThe = !!useThe;
        useOf  = !!useOf;

        // "Inactive" is a park status that leaked into ork_parktitle.title on 5 parks.
        if (title.toLowerCase() === 'inactive') { title = ''; }

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

    return { issuerLines: issuerLines };
}));
