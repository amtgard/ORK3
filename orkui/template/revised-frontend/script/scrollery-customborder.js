/*
 * Scrollery — can the chosen award take a Custom Border? Browser half.
 *
 * A line-for-line mirror of Scroll::CustomBorderRule()'s yes/no over the SAME data
 * (system/lib/ork3/data/scroll-custom-border.php, handed over by the controller as
 * cfg.customBorderRules) — not a second list. The server applies the rule again and
 * silently ignores a border it cannot honour, so this decides only whether the
 * checkbox is offered; it cannot make a certificate wrong.
 *
 * Both halves are driven from tests/fixtures/scroll-custom-border-compat.json
 * (PHP: tests/Unit/ScrollCustomBorderParityTest.php; here:
 * tools/scroll-sweep/tests/customborder.spec.ts), so neither can change a rule
 * without the other's tests going red.
 *
 *   - an order absent from the data is incompatible (garber, zodiac);
 *   - variant-keyed passes need the variant BuildScroll() would pick: the one asked
 *     for if the order has it, otherwise its first;
 *   - a rank mapped to false is incompatible, at the CLAMPED rank the art is drawn
 *     from (Scroll::ClampRank, the order's own from/to);
 *   - the custom order with a rank-numeral set whose plates break the knot gap
 *     (recipeRankSets: garber from rank 7) is incompatible at those ranks;
 *   - the custom order on a ground that paints its own frame is compatible only where
 *     recipeGrounds maps that ground to a frameless twin.
 *
 * Pure: no DOM, no window. Loads as window.ScrolleryCustomBorder and under require().
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScrolleryCustomBorder = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    function has(obj, key) { return !!obj && Object.prototype.hasOwnProperty.call(obj, key); }

    /*
     * rules: the parsed data file. s: { order, variants[], variant, min, max, rank,
     * rankSet, ground, groundOwnBorder }.
     */
    function compatible(rules, s) {
        rules = rules || {};
        var order = s.order;
        if (!order || !has(rules, order)) { return false; }
        var rule = rules[order] || {};

        // A list arrives as a JSON array; a variant-keyed map as an object.
        if (rule.passes && typeof rule.passes === 'object' && !Array.isArray(rule.passes)) {
            var variants = s.variants || [],
                variant  = variants.indexOf(s.variant) !== -1 ? s.variant : (variants[0] || '');
            if (!has(rule.passes, variant)) { return false; }
        }

        var min  = s.min || 0,
            max  = s.max == null ? 10 : s.max,
            rank = Math.max(min, Math.min(max, s.rank || 0));
        if (has(rule.ranks, String(rank)) && rule.ranks[rank] === false) { return false; }

        if (rule.recipeRankSets && has(rule.recipeRankSets, s.rankSet)) {
            var rs = rule.recipeRankSets[s.rankSet];
            if (rs && rs.minIncompatibleRank != null && rank >= rs.minIncompatibleRank) { return false; }
        }

        if (rule.recipeGrounds && s.groundOwnBorder && typeof rule.recipeGrounds[s.ground] !== 'string') {
            return false;
        }
        return true;
    }

    return { compatible: compatible };
}));
