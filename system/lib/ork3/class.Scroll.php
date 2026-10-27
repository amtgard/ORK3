<?php

declare(strict_types=1);

/**
 * Award certificate ("scroll") definitions and rendering.
 *
 * Artwork and layer designs originate in Arcanyst by Valory Fox — see NOTICE.
 *
 * NOTE: startup.php scans system/lib/ork3 and instantiates every class it finds
 * as Ork3::$Lib->scroll. The constructor must therefore take no arguments.
 */
class Scroll extends Ork3
{
    public const DEFAULT_RANKS = ['from' => 0, 'to' => 10];

    /**
     * The most certificates one print run may contain.
     *
     * Each one costs several domain queries to build and a full 2550x3300 page to
     * render, and the awards table holds ~391k rows, so both the bulk selection and
     * the render route stop here rather than accepting an unbounded list.
     */
    public const MAX_PRINT_RUN = 200;

    /**
     * How many certificates one BROWSER print job should contain.
     *
     * MAX_PRINT_RUN is the server's hard guard on a single render URL; this is the
     * separate, smaller number a bulk run should chunk itself into, because the
     * limit that actually bites is the print rasteriser's, not the database's. Named
     * here as a domain constant so the page and any future PDF service agree on one
     * value rather than each hard-coding its own.
     *
     * Front end, not domain, decides whether to use it — see the handoff note in
     * Model_Scrollery::awards_for_bulk().
     */
    public const PRINT_CHUNK = 25;

    /**
     * THE ALLOWLIST'S ONLY NON-ORDER MEMBERS — the two catalogue entries that are not
     * an award at all, but the placeholder a kingdom hangs its own honour from:
     * Custom Award (94) and Custom Title (246).
     *
     * Scrollery prints an award if and only if it resolves to a real, art-backed
     * order in scroll-orders.php, or its id is one of these two. Everything else —
     * every title, master, paragon, knighthood, associate title, noble title,
     * Defender, Weaponmaster, Dragonmaster, Warmaster, Esquire, bare Master, and
     * Order of the Walker in the Middle (31) — does not print. See OrderForAwardRow().
     *
     * Maintainer's words: "remove all awards from the drop-down other than Order of X
     * awards (the ladders). No titles, no masters, etc. Leave Custom Award and Custom
     * Title but specifically give a space for that to be typed in to replace the
     * 'Custom Title' or 'Custom Award' wording."
     *
     * WHY AN ALLOWLIST AND NOT THE 74-ID DENYLIST IT REPLACED: the denylist enumerated
     * what must not print, so anything the catalogue grew that nobody thought about —
     * a new title, a kingdom's orphan id, a class the buckets could not express — was
     * printable by default. It failed OPEN. This rule fails CLOSED: an award has to
     * earn its way in by having artwork, and the artwork is a file somebody drew.
     *
     * The old denylist is not gone in spirit — every one of its 74 ids is still
     * refused, and ScrollExcludedAwardsTest keeps that as an explicit regression
     * guard so a later loosening of the allowlist cannot quietly re-admit a
     * knighthood.
     *
     * ON AWARD 31 — "Order of the Walker in the Middle" is a genuine is_ladder award
     * with 379 grants and it is dropped anyway, by explicit decision, because
     * Scrollery has no artwork for it: it is the ONE ladder in the catalogue with no
     * entry in scroll-orders.php. That also drops Crystal Groves' "Master Reeve"
     * (a kingdomaward on base 31) as a side effect, with no name-matching heuristic —
     * 31 is the only ladder in the whole catalogue carrying off-pattern kingdom
     * names, exactly three of them, 33 grants.
     *
     * @var list<int>
     */
    public const CUSTOM_AWARD_IDS = [94, 246];

    /**
     * Custom Title, specifically — the half of CUSTOM_AWARD_IDS that is a title
     * rather than an award, and the only entry that takes an interstitial phrase.
     * See TitlePhrase().
     */
    public const CUSTOM_TITLE_AWARD_ID = 246;

    /**
     * The interstitial line between the recipient's name and the title, for the two
     * custom entries only. A ranked award already has one — "with her third" — built
     * from the ordinal, and the template renders exactly one of the two.
     *
     * Different defaults because the two entries are different parts of speech.
     * A Custom Title names what someone IS ("with the title of / Fantastic Beast");
     * a Custom Award names a thing granted, and its custom_name values are frequently
     * already order-shaped in the real data — "Order of the Raider", "Order of the
     * Facade" — so "with the / Order of the Raider" reads as a sentence where the
     * title wording would not.
     *
     * Both are DEFAULTS, not fixed: the composer offers a "With phrase" field, and an
     * officer can reword or clear it per print. PHRASE_UNSET distinguishes "no override
     * supplied" from "overridden to empty", which is a real and different choice —
     * see TitlePhrase().
     */
    public const DEFAULT_TITLE_PHRASE = 'with the title of';

    public const DEFAULT_AWARD_PHRASE = 'with the';

    /** Sentinel: no override supplied. Any string, including '', is an override. */
    public const PHRASE_UNSET = "\0unset";

    /**
     * The catalogue names those two entries carry. They are placeholders — the ORK's
     * internal word for "this kingdom made something up" — never the honour's name,
     * so ResolveTitle() must not print them on a certificate.
     *
     * @var list<string>
     */
    private const CUSTOM_PLACEHOLDER_NAMES = ['custom award', 'custom title'];

    /**
     * The longest custom title Scrollery will print.
     *
     * ork_awards.custom_name is varchar(64) and is the natural precedent: it is where
     * this value comes from on an existing grant, so a typed override that could not
     * have been stored is not a value the module should print either. The longest
     * ork_kingdomaward.name in the ORK is 35 characters ("Guildmaster of Reeves,
     * Principality"), so 64 is roughly double the real-world worst case.
     *
     * It matters because `.Order` in scrollery.css starts from a size _text.tpl only
     * guesses (min(1.5em, 0.85 x the name's guess)) and the typeset engine sizes and
     * breaks it in the browser, after layout — so an unbounded value is a layout
     * break, not a small font.
     */
    public const CUSTOM_NAME_MAX_LENGTH = 64;

    public const DEFAULT_RESOURCES = [
        'flourish_top'    => 'scrolls/common/text/squiggly-top.svg',
        'flourish_right'  => 'scrolls/common/text/squiggly-right.svg',
        'flourish_bottom' => 'scrolls/common/text/squiggly-bottom.svg',
        'writing_line'    => 'scrolls/common/text/writing-line.svg',
    ];

    /**
     * The custom scroll used when an award has no design of its own.
     *
     * A recipe is chosen per print and travels in the URL, so nothing persists it.
     * This constant is what every path that prints WITHOUT an explicit recipe uses —
     * the reprint list and a bulk run both reach that state — which is what makes an
     * uncovered award printable everywhere and a reprint deterministic.
     *
     * primary/secondary here match the `custom` order's own colour pair in
     * system/lib/ork3/data/scroll-orders.php — but as an independent literal, not
     * derived from it (that file is a plain data file with no access to this
     * constant). Change one, change both, or a custom scroll's CSS and its rendered
     * border will disagree.
     */
    public const DEFAULT_RECIPE = [
        'ground'    => 'parchment',
        'border'    => 'knot',
        'primary'   => 'hsl(35, 29%, 55%)',
        'secondary' => 'hsl(34, 72%, 78%)',
        'rankSet'   => 'common',
    ];

    /** The Jovious ribbon tint when none (or a malformed one) is requested. */
    public const RIBBON_DEFAULT = '#4473CF';

    /**
     * The three flourishes a Custom Ribbon swaps in, in the same `scrolls/`-prefixed
     * form as an order's `resources`. Neutral tint bases: _text.tpl multiplies them by
     * the chosen colour through #sc-ribbon-tint. Their alpha is jovious/ribbon-*.png's,
     * so ink-extents.json carries the same grids under these paths.
     */
    public const RIBBON_RESOURCES = [
        'flourish_top'    => 'scrolls/common/ribbon/ribbon-top.png',
        'flourish_right'  => 'scrolls/common/ribbon/ribbon-right.png',
        'flourish_bottom' => 'scrolls/common/ribbon/ribbon-bottom.png',
    ];

    /**
     * The Custom Border's two-band knot, relative to assets/scrolls/: a symbols-only
     * sprite (Knot-Band-1, Knot-Band-2) that _knot_defs.tpl inlines once per page.
     */
    public const KNOT_SPRITE = 'common/knot/knot.svg';

    /**
     * The "Customize Design" card's choices. A design is per print and travels with the
     * request like a recipe, so nothing persists it; a caller that passes none gets
     * exactly this, which renders as the certificate always did.
     *
     * heraldrySource '' means the scope's own default. color1/color2 are the Jovious
     * "Primary" and "Secondary" bands.
     */
    public const DEFAULT_DESIGN = [
        'heraldrySource' => '',
        'customBorder'   => false,
        'color1'         => '#6AC1FF',
        'color2'         => '#E84C92',
        'matchStyle'     => false,
        'customRibbon'   => false,
        'ribbonColor'    => self::RIBBON_DEFAULT,
    ];

    /** @var string[] */
    public const HERALDRY_SOURCES = ['kingdom', 'park', 'persona'];

    /*
     * ------------------------------------------------------------------------------
     * Recipe exceptions.
     *
     * scroll-recipes.php stores every ground and border as a bare path, which asserts
     * that all grounds are opaque base plates and all borders are full-page frames.
     * Six pieces of the shipped artwork are not, and custom.tpl composes them
     * accordingly. Those facts live here rather than in the data file because the data
     * file's shape is not theirs to change — but they key on vocabulary NAMES, and a
     * rename in scroll-recipes.php would leave every existing test green while the
     * certificate silently started printing a wash as a bare plate, a black page, or a
     * band smeared down the page. ScrollRecipeTest::testEveryRecipeExceptionStillNames
     * AnEntryInTheVocabulary is the guard against exactly that.
     *
     * Task 6 (and anything else that needs to know which pieces are special) should
     * consume these rather than re-deriving them.
     * ------------------------------------------------------------------------------
     */

    /**
     * Ground entries that are WASHES rather than base plates: their own orders lay
     * them over the parchment, never instead of it. Measured mean luminance across
     * the text box is 118 and 120 for forge and arcane, against ~200 for a genuine
     * ground, so drawn alone each is a flat mid-grey the type sinks into. rosewash
     * fails the same way from the opposite end — 88.5% of its pixels are >= 250 and
     * its mean luminance is 0.939, so drawn alone it is a sheet of near-white with
     * no parchment beneath it.
     *
     * Values are the blend each file's own order uses — smith.tpl, battle.tpl and
     * rose.tpl respectively. rose.tpl's genuinely includes the hue-rotate, which is
     * why this is the first entry carrying a filter:.
     *
     * @var array<string, string>
     */
    public const GROUND_WASHES = [
        'forge'    => 'mix-blend-mode:soft-light;',
        'arcane'   => 'mix-blend-mode:soft-light;opacity:0.8;',
        'rosewash' => 'mix-blend-mode:multiply;opacity:0.2;filter:hue-rotate(80deg);',
    ];

    /**
     * Grounds dark enough that the four layers drawn over them have to be lifted
     * rather than multiplied down. Type, device, numeral and border all get a
     * different treatment on these; see custom.tpl and the [data-ground] rules in
     * scrollery.css, which key on the same names.
     *
     * @var array<int, string>
     */
    public const DARK_GROUNDS = ['cyanotype'];

    /**
     * How a raster border is composited, per ground tone.
     *
     * On a LIGHT ground a frame multiplies into the paper, which is what all nine of
     * them do in their own orders. On a DARK ground that buries them: the eight
     * transparent PNGs are dark line art (opaque mean luminance 61-100), and measured
     * on cyanotype (ground 57) they land at 22 — darker than the ground they sit on,
     * a contrast ratio of 1.2:1. So on a dark ground the default is inverted to lift
     * the ink, hue-rotated to give the artist's colour back, and screened so no part
     * of it can come out darker than the ground.
     *
     * owl is the exception in both directions, because owl/border.jpg is white line
     * art on an OPAQUE black plate where the other eight are transparent PNGs
     * (measured: mean luminance 6.7, 0% transparent). On a light ground, multiplied
     * unfiltered, it turns the whole certificate black; on a dark ground, inverted, it
     * disappears into the plate. Both entries here are owl.tpl's own two branches —
     * its parchment variant and its cyanotype variant — copied, not reinvented.
     *
     * @var array<string, array<string, string>>
     */
    public const BORDER_STYLES = [
        'owl' => [
            'light' => 'filter:invert();mix-blend-mode:multiply;',
            'dark'  => 'mix-blend-mode:screen;',
        ],
    ];

    /** @var array<string, string> */
    public const BORDER_STYLE_DEFAULT = [
        'light' => 'mix-blend-mode:multiply;',
        'dark'  => 'filter:invert() hue-rotate(180deg);mix-blend-mode:screen;',
    ];

    /**
     * The one border entry that is not a frame at all. battle/border_symbols.png is a
     * 2292x292 decorative band; battle.tpl draws the parametric knotwork and centres
     * the band over it. Full-bleed a 292px strip becomes vertical smear down the page,
     * and a border that draws no frame reads as a broken selection.
     */
    public const BORDER_BAND = 'battle';

    /**
     * The border slot's "draw nothing" value. Distinct from BORDER_BAND and from
     * 'knot': both of those DRAW something, they just do not come from a file.
     *
     * Forced by NormaliseRecipe() for any ground in groundsWithOwnBorder, and
     * selectable on its own for a plain sheet.
     */
    public const BORDER_NONE = 'none';

    /**
     * Whether a border draws the PARAMETRIC KNOTWORK rather than a raster frame.
     *
     * The single source of truth for that question. It was previously restated as
     * `$border === 'knot' || $border === Scroll::BORDER_BAND` in custom.tpl, and the
     * composer needed the same answer to stamp its swatches — so the predicate
     * existed twice and a rename of the 'knot' vocabulary key in scroll-recipes.php
     * would have broken one copy silently, exactly the drift the recipe-exception
     * block above exists to prevent.
     *
     * BORDER_BAND is included because battle/border_symbols.png is a 292px decorative
     * band, not a frame: battle.tpl draws the knotwork and centres the band over it,
     * so the band border draws a knot too.
     *
     * NOT to be confused with BORDER_STYLES, which is the per-tone compositing
     * exception map and holds exactly one entry (owl).
     */
    /**
     * Whether a ground already has its border painted into the image.
     *
     * Five of the twelve grounds come from orders that draw no border layer, because
     * the frame is part of the plate. Any border drawn over one of these is a SECOND
     * border sitting on the first. See the list's own note in scroll-recipes.php.
     */
    public static function GroundDrawsOwnBorder(string $ground): bool
    {
        $v = self::Recipes();

        return in_array($ground, $v['groundsWithOwnBorder'] ?? [], true);
    }

    public static function BorderDrawsKnot(string $border): bool
    {
        return $border === 'knot' || $border === self::BORDER_BAND;
    }

    /**
     * The style custom.tpl appends to the knot partial for a ground: screen on a dark
     * ground (multiplied, the knot reads as a dim silhouette), nothing otherwise. Shared
     * with CustomBorderRule() so match-style blends the replacement knot the way the
     * custom scroll's own knot is blended.
     */
    public static function CustomKnotStyle(string $ground): string
    {
        return in_array($ground, self::DARK_GROUNDS, true) ? 'mix-blend-mode:screen;' : '';
    }

    /** _border.tpl's outer layer style, before any per-pass $style is appended. */
    public const KNOT_PARTIAL_STYLE = 'mix-blend-mode:multiply;padding:1px;margin:-1px;';

    /**
     * The one rank set whose numerals are a small glyph (292x171) rather than a
     * whole-page plate, so it alone needs a box and a bottom offset.
     */
    public const RANK_SET_GLYPH = 'common';

    /**
     * The one rank set whose plates are oversized — 2625x3375 against the page's
     * 2550x3300 — and so need zodiac.tpl's own margin to re-centre.
     */
    public const RANK_SET_OVERSIZED = 'zodiac';

    /**
     * Where each border's art STOPS — the inner boundary of the frame, in frame px
     * on the 2550x3300 page.
     *
     * The certificate had no notion of this. `.Award` carried one flat 17% side
     * padding (433px) for every order, and the issuing section then deliberately
     * broke out of even that by 10%, which is how the issuer name came to sit on top
     * of the knotwork.
     *
     * Measured rather than guessed: each plate is scanned inward from both sides
     * along 25 lines spanning the vertical band the text actually occupies (30%-72%
     * of the page), skipping the transparent margin outside the frame, then the
     * frame's own ink, and taking the first sustained clear run after it. The number
     * kept is the DEEPER of the two sides, because a text area has to clear the whole
     * frame and not its thinnest part. Scanning the full height instead picks up the
     * corner devices and answers a different question.
     *
     *   hunter 592   crown 453   lion 485   hydra 480   mask 414
     *   rose   350   smith 351   battle 112
     *
     * Four of the eight intrude PAST the 433px padding — hunter by 159px. On the
     * fixed orders that has always been survivable because each one's own artwork and
     * text were drawn together. On the custom scroll it is not: an officer can put
     * any of these borders on any of the twelve grounds, and nothing in that pairing
     * was ever composed by hand.
     *
     * 'knot' is the parametric SVG border drawn by _border.tpl rather than a file. It is
     * 233, and it was 92 here until a review caught it: 92 is the braid's OUTER edge,
     * which is what reading the <use> elements' bounding boxes gives you. Rasterising
     * the border and scanning the alpha shows a ~141px woven band at x 92..233 and
     * 2316..2458. The wrong number is what made "the type at 2285 is clear of the
     * frame" look true when it never was.
     *
     * A CAVEAT ON THIS WHOLE TABLE. These are body-band numbers, scanned through
     * y 30%..72% where the citation sits. The frames are not uniform in y: at the
     * HEADING (y 300..1050) every one of them is thinner, ending at 206..318 on the
     * left and starting at 2243..2343 on the right, and hunter's 592 in particular is
     * its mid-height medallion and not anything the heading has to clear. The heading
     * does not read this table for that reason — it clamps to the text area instead.
     * If a caller ever wants a head-band inset, it needs its own measured table.
     */
    public const BORDER_INSETS = [
        'none'   => 0,
        'knot'   => 233,
        'crown'  => 453,
        'hunter' => 592,
        'hydra'  => 480,
        'lion'   => 485,
        'mask'   => 414,
        'owl'    => 433,
        'rose'   => 350,
        'smith'  => 351,
        'battle' => 112,
    ];

    /**
     * The text area's inset for a recipe, in frame px — the border's own inner
     * boundary, never less than the flat padding the design already used.
     */
    public static function SafeInsetFor(string $border): int
    {
        return max(433, self::BORDER_INSETS[$border] ?? 0);
    }

    /**
     * How far a COMPOSED certificate has to raise its signature block, in frame px,
     * so the date and the signature are not printed on top of the artwork.
     *
     * The block is the last thing on the page and sits lowest, which is where a
     * border's bottom corner ornaments and a full-page rank plate's own furniture
     * also are. On the sixteen fixed orders the art and the text were drawn together
     * and only four needed adjusting (those live in scrollery.css, keyed on
     * [data-order]/[data-rank]). The composed scroll is the case nobody composed: any
     * border, any rank set, and the pairing decides whether they collide.
     *
     * Measured, not guessed. A one-off browser probe (not committed) renders each
     * pairing, reads the five
     * text boxes of the block off the DOM in frame px, composites every transparent
     * plate that reaches that band, and reports the share of each box sitting on dark
     * ink. The number below is the smallest 5px step that takes the worst box under
     * 0.5%, plus a margin. Two axes came out of it:
     *
     *   the BORDER    only crown intrudes - 18.3% of the block, at every rank set,
     *                 from the corner ornaments. The other ten measure clean.
     *   the RANK SET  garber at every border (its tape measure and scissors are
     *                 full-page furniture drawn for garber's OWN wider block),
     *                 zodiac and flame only against the deepest inset. `common` is a
     *                 292x171 glyph and never reaches.
     *
     * And it depends on the inset, because the inset is what pushes the two columns
     * inward toward a centred plate: .Award's padding is max(17%, --sc-safe-inset),
     * so anything past 433 moves the date further under the artwork. Hence the
     * shallow/deep split rather than one number.
     *
     * This is a CEILING. scrollery-autofit.js clamps it to the room the citation
     * actually left, so a six-line citation keeps its space and gets no lift at all.
     */
    public const SIGN_LIFT_BORDER = ['crown' => 290];

    public static function SignLiftFor(string $border, string $rankSet, int $rank): int
    {
        $lift = self::SIGN_LIFT_BORDER[$border] ?? 0;

        /* Where the columns actually sit, which is the padding and not the border's
           own edge — a shallow border still gets the 433px flat padding. */
        $deep = self::SafeInsetFor($border) > 433;

        if ($rankSet === 'garber') {
            /* r3 measured 155 shallow / 250 at hunter; r7 300/375; r10 325/380. The
               plate grows with the rank, so the bands are by rank as well as inset. */
            if ($rank <= 3) {
                $lift = max($lift, $deep ? 270 : 175);
            } elseif ($rank <= 7) {
                $lift = max($lift, $deep ? 395 : 320);
            } else {
                $lift = max($lift, $deep ? 400 : 345);
            }
        } elseif ($rankSet === 'zodiac' && self::SafeInsetFor($border) >= 592) {
            /* Only against hunter's 592: 125-175 across the ranks. */
            $lift = max($lift, 195);
        } elseif ($rankSet === 'flame' && self::SafeInsetFor($border) >= 592) {
            /* A glyph tip grazing the signer's box at r7, 1.9%. */
            $lift = max($lift, 35);
        }

        return $lift;
    }

    /**
     * The rank sets painted in LIGHT ink, because their home order's plate is dark.
     *
     * zodiac's numeral is a pale constellation for a night sky; flame's is a bright
     * numeral for the fire at the foot of its own ground. Both are correct on the
     * plate they were drawn for and invisible on a pale one — and the custom scroll
     * lets an officer put either on any of the twelve grounds.
     *
     * Measured by compositing each set's rank_7 over each ground and taking the mean
     * WCAG contrast over the numeral's own solid ink. Nine pairs came back under
     * 2:1, all of them one of these two sets on a light ground: zodiac on parchment
     * and starfield at 1.50:1, linen 1.52, map 1.59, jovious 1.81; flame on
     * parchment and jovious at 1.58:1, map 1.60, linen 1.68. For scale, the darkest
     * pairing in the set is 14.51:1 and the ink-on-ground rule the text uses never
     * drops below 11.35:1.
     *
     * garber is deliberately NOT here. Its numerals are outlined — solid ink from
     * L 0 to L 0.892 in the same glyph — so they carry their own contrast onto any
     * ground; its worst pairing is 2.58:1 on `arcane`, and darkening a plate that is
     * already half dark would flatten the outline that is doing the work.
     *
     * Consumed by custom.tpl (which stamps data-rankset) and the [data-rankset]
     * rules in scrollery.css. Sits beside the other recipe exceptions and is guarded
     * the same way — see ScrollRecipeTest.
     */
    public const PALE_RANK_SETS = ['zodiac', 'flame'];

    /**
     * The hand every surface falls back to.
     *
     * BlackChancery over Averia Serif Libre — what the module drew before the
     * alternates existed, so every certificate printed then reprints identically.
     */
    public const DEFAULT_HAND = 'chancery';

    /**
     * The hands, keyed by the value that goes in &face= and [data-scroll-face].
     *
     * @return array<string, array{label:string, fonts:list<string>}>
     */
    public static function Hands(): array
    {
        return self::Recipes()['hands'];
    }

    /**
     * A requested hand reduced to one this installation actually ships.
     *
     * An ALLOWLIST rather than a sanitiser, because the value lands in an HTML
     * attribute and selects a font: an unknown key has to fall back to the default
     * hand rather than reaching either. Case and stray characters are stripped first
     * so a hand-typed `&face=Cinzel ` still resolves rather than silently reverting.
     */
    public static function NormaliseHand(string $face): string
    {
        $key = strtolower(preg_replace('/[^a-z]/i', '', $face) ?? '');

        return isset(self::Hands()[$key]) ? $key : self::DEFAULT_HAND;
    }

    /**
     * The woff2 filenames one hand needs preloaded. Empty for an unknown key, which
     * cannot happen through NormaliseHand() and is not worth throwing over.
     *
     * @return list<string>
     */
    public static function HandFonts(string $face): array
    {
        return self::Hands()[self::NormaliseHand($face)]['fonts'] ?? [];
    }

    /**
     * The signature hand every surface falls back to: none. The given-by line stays in
     * the certificate's own text face, which is what every certificate printed before
     * the picker existed did.
     */
    public const DEFAULT_SIGNATURE_HAND = 'hand';

    /**
     * The signature hands, keyed by the value that goes in &sig= and
     * [data-scroll-sig]. See the long note on 'signatureHands' in scroll-recipes.php.
     *
     * @return array<string, array{label:string, family:string, file:string, scale:float, fonts:list<string>}>
     */
    public static function SignatureHands(): array
    {
        return self::Recipes()['signatureHands'];
    }

    /**
     * A requested signature hand reduced to one this installation ships — an ALLOWLIST,
     * as NormaliseHand() is, because the value lands in an HTML attribute and selects a
     * font. Case and surrounding whitespace are forgiven; anything else, including a
     * value with junk after a valid word, is the default rather than a guess.
     */
    public static function NormaliseSignatureHand(?string $v): string
    {
        $key = strtolower(trim((string) $v));

        return isset(self::SignatureHands()[$key]) ? $key : self::DEFAULT_SIGNATURE_HAND;
    }

    /**
     * The woff2 filenames one signature hand needs preloaded: its own face, and nothing
     * for the default. Answers through NormaliseSignatureHand(), so it cannot name a
     * file the installation does not ship.
     *
     * @return list<string>
     */
    public static function SignatureHandFonts(?string $v): array
    {
        return self::SignatureHands()[self::NormaliseSignatureHand($v)]['fonts'] ?? [];
    }

    /** What every surface falls back to: let the engine choose. */
    public const DEFAULT_HEADING = 'auto';

    /**
     * How the certificate's top block is composed when the page is asked, per print,
     * to override the engine's own choice.
     *
     * `auto` lets the typeset engine choose (Classic when the text fits it, Centered
     * when it does not); `classic` and `centered` force one. Like the hand it is a
     * per-PRINT choice that travels in the URL (&heading=) and is never stored on the
     * award, so a reprint chooses again. Keys are the values that go in &heading= and
     * on <body data-sc-heading>.
     *
     * @return array<string, string> key => label
     */
    public static function Headings(): array
    {
        return ['auto' => 'Automatic', 'classic' => 'Classic', 'centered' => 'Centered'];
    }

    /**
     * A requested heading reduced to one Headings() lists.
     *
     * An ALLOWLIST, as NormaliseHand() is, because the value lands in an HTML
     * attribute and selects a layout. Only case and surrounding whitespace are
     * forgiven, so a hand-typed `&heading=Classic ` still resolves; anything else,
     * including a value with junk after a valid word, is not a heading and gets the
     * default rather than a guess at what was meant.
     */
    public static function NormaliseHeading(?string $v): string
    {
        $key = strtolower(trim((string) $v));

        return isset(self::Headings()[$key]) ? $key : self::DEFAULT_HEADING;
    }

    /**
     * Whether the citation's ruled writing lines are drawn.
     *
     * Six ruled rules sit behind the reason on every one of the seventeen orders —
     * they are in the SHARED text layer, not in any order's own art — and they exist
     * so a certificate can be finished by hand. When the citation is typed they are
     * just ruling, and some heralds want a clean sheet.
     *
     * A per-PRINT choice, like the hand: it changes nothing about what the award is,
     * so it is not stored on the award and a reprint is free to differ. Default ON,
     * so every certificate printed before this existed reprints identically.
     *
     * Only the literal string '0' turns them off. Anything else — absent, empty,
     * 'banana' — is on, because the failure mode of a typo has to be the certificate
     * people already know rather than a silently different one.
     */
    public const SHOW_LINES_DEFAULT = true;

    public static function NormaliseShowLines(?string $value): bool
    {
        return trim((string) $value) !== '0';
    }

    /**
     * @font-face rules for the ten display faces, for a page that shows the hand
     * picker but does NOT load scrollery.css.
     *
     * `mine` and `bulk` are ordinary CRM pages: they carry the picker, but the
     * certificate stylesheet has no business on them and the CSS-boundary discipline
     * says so. Without the declarations, though, each <option>'s font-family names a
     * family the document has never heard of, so every specimen renders in the
     * fallback serif and the whole point of the picker is lost — measured, 10 of 10
     * unloaded on `mine` against 0 of 10 on the composer, which does load the sheet.
     *
     * Generated from the same 'hands' data the picker itself reads, rather than
     * copied into a second stylesheet that could drift from section 1a.
     *
     * font-display: swap, NOT the `block` the certificate uses. Opposite jobs: on a
     * certificate a face arriving late means the wrong letterforms on paper, so it is
     * worth painting nothing until it lands. In a dropdown, painting nothing means an
     * empty list. Swap shows the readable label immediately and upgrades it in place.
     */
    public static function HandSpecimenStyles(string $fontBase): string
    {
        $css = '';
        foreach (self::Hands() as $hand) {
            $css .= "@font-face{font-family:'" . $hand['family'] . "';"
                . "src:url('" . $fontBase . $hand['file'] . "') format('woff2');"
                . 'font-weight:400;font-style:normal;font-display:swap}';
        }

        return $css;
    }

    /** @var array<string, array>|null */
    private static ?array $recipes = null;

    /** @return array{grounds:array,borders:array,rankSets:array,palette:array} */
    public static function Recipes(): array
    {
        if (self::$recipes !== null) {
            return self::$recipes;
        }

        return self::$recipes = require DIR_ORK3 . 'data/scroll-recipes.php';
    }

    /**
     * A hex colour as uppercase #RRGGBB, or $fallback. Strict six-digit only: the value
     * reaches a style attribute, so shorthand, names and anything trailing are refused
     * rather than repaired.
     */
    public static function NormaliseHexColour(string $raw, string $fallback): string
    {
        $raw = trim($raw);
        if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $raw, $m) !== 1) {
            return $fallback;
        }

        return '#' . strtoupper($m[1]);
    }

    /**
     * Validate a requested design against DEFAULT_DESIGN's keys. Allowlist, same as
     * NormaliseRecipe(): the heraldry source is a closed set, colours are strict hex,
     * and booleans accept only the spellings a checkbox or a query string produces.
     */
    public static function NormaliseDesign(array $requested): array
    {
        $d    = self::DEFAULT_DESIGN;
        $bool = static fn ($v): bool => in_array($v, ['1', 1, true, 'true', 'on'], true);

        $source = (string) ($requested['heraldrySource'] ?? '');
        if (in_array($source, self::HERALDRY_SOURCES, true)) {
            $d['heraldrySource'] = $source;
        }

        $d['customBorder'] = $bool($requested['customBorder'] ?? false);
        $d['matchStyle']   = $bool($requested['matchStyle'] ?? false);
        $d['customRibbon'] = $bool($requested['customRibbon'] ?? false);
        $d['color1']       = self::NormaliseHexColour((string) ($requested['color1'] ?? ''), $d['color1']);
        $d['color2']       = self::NormaliseHexColour((string) ($requested['color2'] ?? ''), $d['color2']);
        $d['ribbonColor']  = self::NormaliseHexColour((string) ($requested['ribbonColor'] ?? ''), $d['ribbonColor']);

        return $d;
    }

    /**
     * Which heraldry source actually draws: the requested one when it has arms,
     * otherwise the scope's own (kingdom for a kingdom scope, park for a park scope).
     * Unknown or unavailable never reaches a URL lookup; a missing default simply
     * draws no arms, as it always has.
     *
     * @param array{kingdom?:bool,park?:bool,persona?:bool} $available
     */
    public static function ResolveHeraldrySource(string $requested, string $scopeType, array $available): string
    {
        if (in_array($requested, self::HERALDRY_SOURCES, true) && !empty($available[$requested])) {
            return $requested;
        }

        return $scopeType === 'park' ? 'park' : 'kingdom';
    }

    /** @var array<string, array>|null */
    private static ?array $customBorderRules = null;

    /**
     * The raw compatibility data (data/scroll-custom-border.php): per order, `passes`
     * (style strings, or keyed by variant), optional `ground`, `ranks`, `recipeGrounds`.
     * An absent order is incompatible.
     *
     * @return array<string, array>
     */
    public static function CustomBorderRules(): array
    {
        if (self::$customBorderRules !== null) {
            return self::$customBorderRules;
        }

        return self::$customBorderRules = require DIR_ORK3 . 'data/scroll-custom-border.php';
    }

    /**
     * Can this order, at this rank/variant/recipe, take a Custom Border?
     *
     * null = no. Otherwise `passes` are the style attributes of the border layers the
     * knot replaces (match-style mode re-uses them) and `ground` is the frameless ground
     * to draw instead, relative to assets/scrolls/, or null when the order's border is
     * a separate layer that is simply not drawn.
     *
     * @return array{passes: string[], ground: ?string}|null
     */
    public static function CustomBorderRule(string $orderId, int $rank, string $variant, array $recipe): ?array
    {
        $rule = self::CustomBorderRules()[$orderId] ?? null;
        if ($rule === null) {
            return null;
        }

        $passes = $rule['passes'] ?? [];
        if ($passes !== [] && !array_is_list($passes)) {
            $passes = $passes[$variant] ?? null;
            if ($passes === null) {
                return null;
            }
        }
        $ground = $rule['ground'] ?? null;

        // A string-valued rank swaps the ground ONLY; the order's own passes stay, so
        // match-style still has something to draw the knot with. false = incompatible.
        if (isset($rule['ranks']) && array_key_exists($rank, $rule['ranks'])) {
            if ($rule['ranks'][$rank] === false) {
                return null;
            }
            $ground = $rule['ranks'][$rank];
        }

        if ($orderId === 'custom') {
            // The recipe, not a fixed list, decides what is being replaced: custom.tpl
            // draws the knot (or the band strip, which the custom border replaces too)
            // with the ground's knot style, and a raster with BORDER_STYLES[border][tone].
            // BORDER_NONE is a framed ground that was swapped, or a plain sheet: the knot.
            $recipe = self::NormaliseRecipe($recipe);
            $tone   = in_array($recipe['ground'], self::DARK_GROUNDS, true) ? 'dark' : 'light';
            $border = (string) $recipe['border'];
            $passes = [
                $border === self::BORDER_NONE || self::BorderDrawsKnot($border)
                    ? self::KNOT_PARTIAL_STYLE . self::CustomKnotStyle($recipe['ground'])
                    : (self::BORDER_STYLES[$border][$tone] ?? self::BORDER_STYLE_DEFAULT[$tone]),
            ];
        }

        if (isset($rule['recipeGrounds'])) {
            $rg = (string) ($recipe['ground'] ?? '');
            if (self::GroundDrawsOwnBorder($rg)) {
                $mapped = $rule['recipeGrounds'][$rg] ?? false;
                if (!is_string($mapped)) {
                    return null;
                }
                $ground = $mapped;
            }
        }

        // A compatible rule always draws at least one pass; an empty list would mean
        // match-style paints no border at all.
        assert($passes !== [], "custom-border rule for {$orderId} has no passes");

        return ['passes' => array_values($passes), 'ground' => $ground];
    }

    /**
     * Validate a requested recipe against the vocabulary.
     *
     * Anything unrecognised falls back to the default rather than being passed
     * through — these values reach an <img src> and a style attribute, and the
     * vocabulary is a closed set, so an allowlist is the whole defence.
     */
    public static function NormaliseRecipe(array $requested): array
    {
        $v      = self::Recipes();
        $recipe = self::DEFAULT_RECIPE;

        $ground = (string) ($requested['ground'] ?? '');
        if (isset($v['grounds'][$ground])) {
            $recipe['ground'] = $ground;
        }

        $border = (string) ($requested['border'] ?? '');
        if (isset($v['borders'][$border])) {
            $recipe['border'] = $border;
        }

        // A ground that already carries a frame takes no border layer, whatever was
        // asked for. Enforced HERE rather than in the composer because the recipe
        // travels in the query string: the UI going inert stops the honest mistake,
        // this stops the hand-edited URL, and both surfaces then agree because both
        // read the answer from the same place.
        if (self::GroundDrawsOwnBorder($recipe['ground'])) {
            $recipe['border'] = self::BORDER_NONE;
        }

        $rankSet = (string) ($requested['rankSet'] ?? '');
        if (isset($v['rankSets'][$rankSet])) {
            $recipe['rankSet'] = $rankSet;
        }

        // Colours are only accepted as a PAIR drawn from the curated palette, so a
        // caller cannot mix one order's primary with another's secondary.
        $primary   = (string) ($requested['primary'] ?? '');
        $secondary = (string) ($requested['secondary'] ?? '');
        foreach ($v['palette'] as $pair) {
            if ($pair[0] === $primary && $pair[1] === $secondary) {
                $recipe['primary']   = $primary;
                $recipe['secondary'] = $secondary;
                break;
            }
        }

        return $recipe;
    }

    /** @var array<string, array>|null */
    private static ?array $orders = null;

    /** @return array<string, array> */
    public static function Orders(): array
    {
        if (self::$orders !== null) {
            return self::$orders;
        }

        $raw = require DIR_ORK3 . 'data/scroll-orders.php';
        $orders = [];
        foreach ($raw as $id => $order) {
            $order['id']        = $id;
            $order['award_id']  = (int) $order['award_id'];
            $order['ranks']     = $order['ranks'] ?? self::DEFAULT_RANKS;
            $order['variants']  = $order['variants'] ?? [];
            // Overrides are partial: merge over the defaults, never replace them.
            $order['resources'] = array_merge(self::DEFAULT_RESOURCES, $order['resources'] ?? []);
            $orders[$id] = $order;
        }

        return self::$orders = $orders;
    }

    /**
     * No caller outside OrderForAwardRow() should need this directly: a bare
     * award id can never distinguish a genuine award from a job title sharing
     * its id space, which is exactly what OrderForAwardRow()'s OfficerRole
     * check is for. Left public only because ScrollOrdersTest and
     * ScrolleryPermissionTest assert on it directly.
     */
    public static function OrderForAwardId(int $awardId): ?array
    {
        // 0 is a sentinel, never a real award id (see the `custom` order's own
        // award_id: 0, chosen precisely so it cannot be reached this way). Real
        // archery grants DO carry award_id 0, so without this guard a lookup for
        // an actual award would collide with the sentinel and wrongly resolve to
        // the custom order by id instead of falling through to name matching in
        // OrderForAwardRow().
        if ($awardId === 0) {
            return null;
        }

        foreach (self::Orders() as $order) {
            if ($order['award_id'] === $awardId) {
                return $order;
            }
        }
        return null;
    }

    /**
     * Which of the ORK's four names for an award appears on the certificate.
     *
     * Arcanyst had no such concept — it computed "Order of the " . ucfirst($orderId),
     * which is why one archery award had to become four order ids. Here the word
     * after "Order of the" is data. The title prints on its own line, between the
     * recipient's rank and the reason, so it has to read as a whole order name.
     *
     * The kingdom's own name for the award leads, because that is the field that
     * holds one. awards.custom_name does not: on ladder awards it is overwhelmingly
     * a short tag typed beside the real name ("Rose" against a kingdomaward of
     * "Order of the Rose", "Warrior", "Gryphon" — 89,849 of 343,091 ladder awards),
     * so leading with it would misprint a quarter of every ladder scroll. Custom and
     * alias names stay in the chain as a last resort, purely so a row missing both
     * real names still prints something.
     *
     * Per-kingdom name variants are a later phase; see the plan's Deferred section.
     *
     * ---------------------------------------------------------------------------
     * THE TWO CUSTOM ENTRIES INVERT THAT ORDER, and only they do.
     *
     * The reasoning above is measured against LADDER grants, where custom_name is a
     * junk tag typed beside the real name. It does not hold for Custom Award (94) and
     * Custom Title (246), where custom_name IS the title and there is no other name
     * to have: 12,213 of award 94's 18,077 grants carry one, and 203 of award 246's
     * 206. Under the ladder order those all print the literal words "Custom Award".
     *
     * So for 94/246 only:
     *
     *   1. $customName — what an officer typed at print time. See BuildScroll().
     *   2. CustomAwardName — the per-grant ork_awards.custom_name.
     *   3. KingdomAwardName, but only when the kingdom actually named the row.
     *      Most kingdoms leave it literally "Custom Award"/"Custom Title" (37 rows
     *      each, one per kingdom), but some created real ones on base 94 —
     *      Dreamkeeper, Order of the Hellrider, Grand Marquis, Order of the Hardcore,
     *      Mistress, Order of the Emerald — and those are the award's real name.
     *   4. Nothing. See below.
     *
     * A BLANK CUSTOM TITLE PRINTS BLANK, deliberately — 5,864 grants on 94 and 3 on
     * 246 have neither a custom_name nor a distinct kingdomaward name, and the ORK
     * genuinely does not know what those awards were called. "Custom Award" is the
     * ORK's internal placeholder; lettering it onto a certificate states something
     * false about the honour. A blank line states nothing and can be completed by
     * hand, which is the convention this certificate already uses everywhere else:
     * FormatDate() returns '' for a zero date so the writing-line draws blank rather
     * than "30th Nov -0001", and BuildScroll()'s `ranked` leaves the ordinal blank as
     * "an invitation to write the rank in by hand". Since the composer now carries a
     * customName box, the blank case is also fixable at print time rather than a dead
     * end — which is what makes blank the better of two imperfect answers.
     * ---------------------------------------------------------------------------
     *
     * @param string $customName A title typed at print time; honoured ONLY for 94/246.
     */
    public static function ResolveTitle(array $awardRow, string $customName = ''): string
    {
        if (self::IsCustomAward($awardRow)) {
            $typed = self::SanitiseCustomName($customName);
            if ($typed !== '') {
                return $typed;
            }

            $granted = trim((string) ($awardRow['CustomAwardName'] ?? ''));
            if ($granted !== '') {
                return $granted;
            }

            $kingdom = trim((string) ($awardRow['KingdomAwardName'] ?? ''));
            if ($kingdom !== '' && !in_array(strtolower($kingdom), self::CUSTOM_PLACEHOLDER_NAMES, true)) {
                return $kingdom;
            }

            return '';
        }

        foreach (['KingdomAwardName', 'Name', 'AliasAwardName', 'CustomAwardName'] as $key) {
            $name = trim((string) ($awardRow[$key] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    /**
     * Clean a hand-typed custom title down to something that can be lettered onto a
     * certificate. Sanitising happens HERE, in the domain, not in the controller: it
     * is free text landing on a printed page, and every door into BuildScroll() has
     * to get the same treatment.
     *
     * - Control characters (newlines included) collapse to a space. `.Order` is one
     *   line; `.Multiline`'s `white-space: pre-line` is not applied to it, so an
     *   embedded newline is either invisible or a layout surprise depending on the
     *   partial.
     * - Runs of whitespace collapse, and the result is trimmed. A pasted value
     *   carrying a trailing tab must not change the rendered width.
     * - TRUNCATED at CUSTOM_NAME_MAX_LENGTH, not refused. Refusing silently prints a
     *   blank line and loses what the officer typed; truncating prints as much of it
     *   as the page can carry. ResolvePronounOverride() caps the same way, for the
     *   same reason.
     * - Invalid UTF-8 yields '' — preg_replace returns null on a bad subject with the
     *   /u modifier, and the fail-closed answer for a value bound for a printed page
     *   is to print nothing.
     *
     * Escaping is NOT done here: every partial passes the title through scroll_e()
     * (_text.tpl:128). Escaping twice would print "&amp;" on the certificate.
     */
    public static function SanitiseCustomName(string $raw): string
    {
        // Repair double-encoded text FIRST. Its closing quote ends in U+009D, a control
        // character, which the line below turns into a space: sanitising before repairing
        // left "â€" and a gap where the quote belonged (corpus case s20).
        $raw = ScrollText::RepairMojibake($raw);

        $clean = preg_replace('/\p{C}+/u', ' ', $raw);
        if ($clean === null) {
            return '';
        }

        $clean = preg_replace('/\s+/u', ' ', $clean);
        if ($clean === null) {
            return '';
        }

        return trim(mb_substr(trim($clean), 0, self::CUSTOM_NAME_MAX_LENGTH, 'UTF-8'));
    }

    /** Whether a bare award id is one of the two custom placeholder entries. */
    public static function IsCustomAwardId(int $awardId): bool
    {
        return in_array($awardId, self::CUSTOM_AWARD_IDS, true);
    }

    /**
     * Whether an award ROW is a grant of one of the two custom placeholder entries —
     * the rows whose real title lives in custom_name rather than in the catalogue.
     *
     * Reads AliasAwardId as well as AwardId for the same reason the printability rule
     * does: AwardsForPlayer() resolves an alias into AliasAwardId and leaves AwardId
     * on the base award.
     */
    public static function IsCustomAward(array $awardRow): bool
    {
        foreach (['AwardId', 'AliasAwardId'] as $key) {
            if (self::IsCustomAwardId((int) ($awardRow[$key] ?? 0))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The line that introduces the title, printed between the recipient's name and
     * the title itself.
     *
     * A ranked award already has one — "with her third" — rendered from the ordinal.
     * A Custom Title had nothing, so the certificate ran the recipient's name straight
     * into a bare phrase and read as two unrelated lines:
     *
     *     Haliax Duskstrider
     *     Fantastic Beast
     *
     * Returns '' for everything else, and the template renders nothing when it is
     * empty — so this cannot introduce a phrase on an order that already has one.
     *
     * ON CUSTOM AWARD (94): deliberately NOT given a phrase. It is the far larger
     * population (18,077 grants against 246's 206) and its custom_name values are
     * frequently already order-shaped — "Order of the Raider", "Order of the Facade" —
     * where any introducing phrase would read worse than the bare name, not better.
     * A parallel "with the award of" is a live option if the maintainer wants it;
     * it was not asked for, and applying the title wording to ninety times more
     * certificates on inference would be the wrong way round.
     */
    public static function TitlePhrase(array $awardRow, string $override = self::PHRASE_UNSET): string
    {
        if (!self::IsCustomAward($awardRow)) {
            // Every other order already has the ordinal phrase, and the template renders
            // exactly one of the two. An override must not be able to give an award both.
            return '';
        }

        if ($override !== self::PHRASE_UNSET) {
            // Deliberately allows ''. Clearing the box is a real choice — it prints the
            // title with no introducing line, which is what the certificate did before
            // this existed and is still right for some wordings.
            return self::SanitiseCustomName($override);
        }

        foreach (['AwardId', 'AliasAwardId'] as $key) {
            if ((int) ($awardRow[$key] ?? 0) === self::CUSTOM_TITLE_AWARD_ID) {
                return self::DEFAULT_TITLE_PHRASE;
            }
        }

        return self::DEFAULT_AWARD_PHRASE;
    }

    public static function ClampRank(array $order, int $rank): int
    {
        return max($order['ranks']['from'], min($order['ranks']['to'], $rank));
    }

    /**
     * The rank the certificate's WORD is written from — which is NOT the rank its
     * art is drawn from.
     *
     * Art clamps and has to: the numerals ship as rank_1..10 and nothing draws an
     * eleventh, so ClampRank() above stays exactly as it was. The ordinal has no
     * such limit, and clamping it printed the wrong sentence. 79 live grants sit
     * above the ceiling — 35 at rank 11, 19 at 12, and a tail out to 22 — and every
     * one of them lettered "with their tenth" onto a certificate that was in fact
     * the eleventh or the twenty-second.
     *
     * _text.tpl has always been ready for this: it names ordinals through "twelfth"
     * and falls back to "13th"/"22nd" past that. Its own comment anticipated "the
     * moment anything lifts Scroll::ClampRank() past its current ceiling" — the data
     * had already lifted it, so that branch was unreachable rather than unnecessary.
     *
     * The LOWER bound is kept deliberately. `from` is 1 only on warrior, whose art
     * starts at rank 1, and a warrior row recorded at 0 has always printed "first".
     * That is an existing reading of a data gap; changing it is not this fix's to
     * make, and unclamping it would silently turn those into blank hand-write lines.
     */
    public static function OrdinalRank(array $order, int $rank): int
    {
        return max($order['ranks']['from'], $rank);
    }

    /**
     * Kingdomaward names that print the archery certificate.
     *
     * Archery is the one order with no base award id to key on: real grants hang off
     * award_id 0 (no base award), 94 (Custom Award) and 131, under a dozen names. The
     * printed title still comes from ork_kingdomaward.name, so every spelling renders
     * on its own certificate.
     *
     * "Master Hunter" and "Master Archer" are deliberately absent — they take the
     * custom scroll with every other Master, because no ladder gives its Master tier
     * the ladder's own artwork.
     */
    public const ARCHERY_NAMES = [
        'order of the hunter',
        'order of the archer',
        'order of the marksman',
        'order of the sharpshooter',
        'hunter',
    ];

    public static function ArcheryOrderForName(string $name): ?array
    {
        $key = strtolower(trim($name));

        return in_array($key, self::ARCHERY_NAMES, true) ? (self::Orders()['hunter'] ?? null) : null;
    }

    /**
     * Whether an award row names an officer position rather than an honour.
     *
     * The single source of truth for that predicate — IsCustomEligible() and
     * OrderForAwardRow() both defer to it, rather than each keeping their own copy of
     * the `?? 'none'` expression, which is how the rule would drift between them.
     *
     * Two holes here need OPPOSITE treatment, and a single `?? 'none'` used to serve
     * both identically — that was itself the bug:
     *
     * - Key ABSENT — the caller never told us what this is — FAILS CLOSED (treated
     *   as an officer position). A caller with a real award row always has an
     *   OfficerRole key, even when its value is null; an absent key means something
     *   built this row by hand and forgot the field, which is a caller bug, not a
     *   printable award.
     * - Key present but `null` / `''` — stays OPEN (not an officer). A real archery
     *   grant's award_id dangles (0, 94, 131 under a base id nothing else uses), so
     *   the LEFT JOIN to officer_role misses and yields exactly this NULL. NULL
     *   there means "unknown award", not "this is an officer position", and archery
     *   must still print.
     * - Key present, non-empty, not `'none'` — an officer position. Unchanged.
     */
    private static function IsOfficerPosition(array $awardRow): bool
    {
        if (!array_key_exists('OfficerRole', $awardRow)) {
            return true;
        }

        $role = strtolower(trim((string) ($awardRow['OfficerRole'] ?? 'none')));

        return $role !== '' && $role !== 'none';
    }

    /**
     * Every award id the allowlist admits, as a flat list — for the one caller that
     * has to state the rule in SQL rather than row by row
     * (Model_Scrollery::bulkFromWhere(), which must filter BEFORE the LIMIT or its
     * total and its paging both lie).
     *
     * Derived from scroll-orders.php rather than restated, so adding an order cannot
     * leave the bulk query behind. hunter's fabricated award_id 6969 is included and
     * that is correct, not a leak: no ork_award row carries it, and if one ever did,
     * OrderForAwardId() would resolve it in PHP too — the SQL and the PHP agree.
     * custom's own 0 sentinel is skipped, because 0 means "no base award" on a real
     * row and would open the gate to every dangling kingdomaward in the ORK.
     *
     * @return list<int>
     */
    public static function PrintableAwardIds(): array
    {
        $ids = self::CUSTOM_AWARD_IDS;
        foreach (self::Orders() as $order) {
            if ((int) $order['award_id'] > 0) {
                $ids[] = (int) $order['award_id'];
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Whether Scrollery's award-class policy refuses this row.
     *
     * The INVERSE of the allowlist, kept under the name every caller already uses.
     * It no longer consults an id denylist: a row is excluded exactly when
     * OrderForAwardRow() can find nothing to print it as, which is the same question
     * asked from the other side. Officer positions answer true here too — they are
     * refused, and by the time a caller is choosing a refusal message the distinction
     * between "job, not an honour" and "off the menu" is one it does not act on.
     *
     * @see CUSTOM_AWARD_IDS for the policy and its reasoning.
     */
    public static function IsExcludedFromScrollery(array $awardRow): bool
    {
        return self::OrderForAwardRow($awardRow) === null;
    }

    /**
     * Whether an award with no design of its own may still be printed.
     *
     * Officer positions are excluded: an officer position is a job somebody held, not
     * an honour they were given.
     *
     * This answers ONLY that question — it is not the award-class gate and must never
     * become one. The gate is the allowlist inside OrderForAwardRow(), which every
     * surface goes through; an excluded award that happened to have artwork of its
     * own would sail straight past a gate placed here, because OrderForAwardId()
     * resolves before the custom fallback is ever consulted.
     */
    public static function IsCustomEligible(array $awardRow): bool
    {
        return !self::IsOfficerPosition($awardRow);
    }

    /**
     * THE single resolution point for "what does this award print as", and THE
     * ALLOWLIST — a row that does not leave this function with an order does not
     * print anywhere. One gate here is inherited by mine, bulk, create, compose,
     * render, the profile Awards and Titles print buttons and the post-grant link,
     * because HasArt() and BuildScroll() both resolve through it.
     *
     * A row is printable if and only if it reaches one of exactly three returns:
     *
     *   1. its award id names a real, art-backed order in scroll-orders.php;
     *   2. it is an archery grant, which has no base award of its own to key on and
     *      is recognised by kingdomaward name (see ARCHERY_NAMES);
     *   3. its award id is Custom Award (94) or Custom Title (246), and it takes the
     *      custom scroll.
     *
     * Everything else falls off the end and returns null. There is no denylist to
     * keep current: a class nobody anticipated is refused because it was never
     * admitted, not because somebody remembered to name it.
     */
    public static function OrderForAwardRow(array $awardRow): ?array
    {
        // An officer position is a job someone held, not an honour someone was given,
        // and that is true whichever door resolution comes through. Checked first so
        // a kingdom that happens to name an officer role "Hunter" cannot reach the
        // archery branch below — the name list is matched on kingdomaward text, which
        // nothing validates against what the row actually is.
        if (self::IsOfficerPosition($awardRow)) {
            return null;
        }

        $awardId = (int) ($awardRow['AwardId'] ?? 0);
        $aliasId = (int) ($awardRow['AliasAwardId'] ?? 0);

        // An alias RE-POINTS the grant at a different catalogue entry, and that entry
        // is what was really given. Every one of the ORK's 11 alias rows sits on
        // Custom Title (246) and points at a real title — Lord, Lady, Baronet,
        // Countess, Baron, Archduke, Paragon Anti-Paladin — so without this the
        // allowlist would admit all 11 through their harmless-looking base id and
        // print the very titles the policy removed. AwardsForPlayer() resolves the
        // alias into AliasAwardId and leaves AwardId on the base award, which is why
        // both have to be read.
        if ($aliasId > 0 && self::OrderForAwardId($aliasId) === null && !self::IsCustomAwardId($aliasId)) {
            return null;
        }

        $specific = self::OrderForAwardId($awardId);
        if ($specific !== null) {
            return $specific;
        }

        // Archery is the one order matched by NAME, so the match is confined to the
        // rows that can legitimately be archery: its real grants carry no resolvable
        // base award (award_id 0 — the LEFT JOIN missed) or hang off Custom Award.
        // Without that confinement the name list would be a second, weaker door into
        // the allowlist — a knighthood a kingdom happened to call "Order of the
        // Hunter" would print. No such row exists today; the gate is what keeps that
        // true.
        if ($awardId === 0 || self::IsCustomAwardId($awardId)) {
            foreach (['KingdomAwardName', 'Name'] as $key) {
                $archery = self::ArcheryOrderForName((string) ($awardRow[$key] ?? ''));
                if ($archery !== null) {
                    return $archery;
                }
            }
        }

        // The custom scroll is no longer the catch-all it was: only the two custom
        // catalogue entries reach it. A dangling kingdomaward, an orphan noble id, a
        // title, a masterhood — all of which used to land here — now return null.
        return self::IsCustomAwardId($awardId) && self::IsCustomEligible($awardRow)
            ? (self::Orders()['custom'] ?? null)
            : null;
    }

    public static function HasArt(array $awardRow): bool
    {
        return self::OrderForAwardRow($awardRow) !== null;
    }

    /**
     * The 300 dpi print plates — the masters, and the default.
     */
    public const ART_MASTER = 'master';

    /**
     * A screen-sized rendition of every plate — one third of the master's linear
     * size (850x1100 for a page plate), for the composer's ~600px preview stage.
     *
     * WHY. The preview downloads the print plates today: a lion rank-1 preview
     * pulls 3.83 MB and warrior rank 9 pulls 4.60 MB, to be drawn into a box about
     * 600 px wide. Nothing on that page needs 300 dpi.
     */
    public const ART_DISPLAY = 'display';

    /** @var list<string> */
    public const ART_SIZES = [self::ART_MASTER, self::ART_DISPLAY];

    /**
     * The base URL every certificate partial builds its plate URLs on.
     *
     * DELIBERATELY SIZE-FREE. The renditions are SIBLINGS of their masters, not a
     * parallel tree — bin/build-scroll-renditions.sh emits `<name>.webp` and
     * `<name>_display.webp` beside `<name>.png`, matching the naming the heraldry
     * masters/renditions pipeline already established. So a size is a FILENAME
     * concern, not a directory one, and ArtUrl() is where it is resolved. An
     * ArtBase('display') returning a /display directory would be a path nothing
     * writes to.
     */
    public static function ArtBase(): string
    {
        return rtrim(HTTP_ASSETS, '/') . '/scrolls';
    }

    /**
     * One plate's URL at a given size — THE seam for the rendition pass.
     *
     * Driven by assets/scrolls/renditions.json, which
     * bin/build-scroll-renditions.sh writes, rather than by guessing a filename:
     * a rendition is only emitted when it actually beats its master, so 17 of the
     * 244 masters have `"print": null` (WebP LOSES on garber/linen.jpg,
     * flame/bg.jpg, warrior/bg_10.jpg and both zodiac plates — lossless inflates
     * an already-lossy JPEG). Constructing `<name>.webp` blindly would 404 on
     * exactly those, and they are the biggest files in the set.
     *
     * Resolution, per size:
     *   display — the display rendition; falls back to the print rendition, then
     *             to the master, so a plate the pass skipped still draws.
     *   master  — the print rendition when one exists, else the master itself.
     *             PNG masters are re-encoded LOSSLESS, so those are bit-identical
     *             (RMSE 0 / PAE 0, measured through both blend paths). JPEG masters
     *             are q90 LOSSY, so "same pixels" is true of the PNGs only — the
     *             error there is a fraction of a level and was validated on the
     *             composited certificate, not on the file.
     *             Never a display rendition: only render/compose ask for master,
     *             and they print at 300 dpi.
     *
     * An unknown size is treated as the master. A preview heavier than it should
     * be is a performance bug; a blank preview is a broken feature.
     *
     * @param string $relative e.g. 'warrior/bg_10.jpg', relative to assets/scrolls/
     */
    public static function ArtUrl(string $relative, string $size = self::ART_MASTER): string
    {
        $relative = ltrim(trim($relative), '/');
        if ($relative === '') {
            return self::ArtBase();
        }

        // Strip any cache-buster or fragment before the lookup and re-attach it,
        // so a caller that already appended one is not silently un-busted.
        $suffix = '';
        $cut    = strcspn($relative, '?#');
        if ($cut < strlen($relative)) {
            $suffix   = substr($relative, $cut);
            $relative = substr($relative, 0, $cut);
        }

        $entry = self::renditionManifest()[$relative] ?? null;

        /*
         * The cache-buster.
         *
         * assets/ is served `Cache-Control: public, max-age=31536000, immutable`, and
         * `immutable` means a browser that already holds a plate will not so much as
         * revalidate it for a year. So a correction to the ARTWORK never reaches
         * anyone who has printed that certificate before — which is not hypothetical:
         * owl/rank_9.png printed the numeral XI where IX belongs, and the redrawn
         * plate was invisible in a browser that had the old one.
         *
         * The manifest already carries the answer. Its `sha` is of the MASTER and
         * changes whenever the art does, and both renditions are rebuilt from that
         * master, so one token is correct for all three URLs. This is what
         * bin/build-scroll-renditions.sh's own note means by "sha doubles as the ?v=
         * cache-busting token for the immutable nginx block" — it was written down
         * and never wired up.
         *
         * A plate with no manifest entry gets no token: there is nothing to key on,
         * and that is exactly the pre-rendition behaviour. A caller that appended its
         * own query keeps it and gets no second one.
         */
        $bust = '';
        if ($suffix === '' && is_string($entry['sha'] ?? null) && $entry['sha'] !== '') {
            $bust = '?v=' . $entry['sha'];
        }

        if (is_array($entry)) {
            $order = $size === self::ART_DISPLAY ? ['display', 'print'] : ['print'];
            foreach ($order as $key) {
                $file = $entry[$key]['file'] ?? null;
                if (is_string($file) && $file !== '') {
                    return self::ArtBase() . '/' . ltrim($file, '/') . $suffix . $bust;
                }
            }
        }

        return self::ArtBase() . '/' . $relative . $suffix . $bust;
    }

    /**
     * A plate's MASTER pixel box, for the width/height an <img> has to declare.
     *
     * The renditions pass swaps a smaller file in behind the same URL, and nothing in
     * the markup told the browser what box that file was standing in for. An <img>
     * with no width/height draws at its own intrinsic size — so on the composer, where
     * ArtUrl() serves display renditions at a third scale, every unsized plate drew a
     * third of the size it was laid out for. Order of the Flame rendered its whole
     * ground 850x1100 in the corner of a 2550x3300 page; Order of the Crown drew a
     * quarter-size border and a third-size phoenix. On the print surface the masters
     * ARE 2550x3300, so the same markup looked correct there by luck.
     *
     * Derived rather than stored: the manifest records each display rendition's
     * dimensions and the single displayScale they were cut at, so the master is the
     * one divided by the other. Nothing new has to be generated.
     *
     * ACCURATE TO +/-1px, not exactly. The rendition pass rounded when it cut the
     * display file, so the master is not always recoverable from it: common/rank_7.png
     * is 292 wide and its 97px display rendition divides back to 291. On a 2550px page
     * that is 0.04% of the frame and invisible at 300 dpi — and it is the aspect ratio
     * that matters here, which survives. Reading the true size would mean a
     * getimagesize() per plate per certificate, which is a real cost for a pixel.
     *
     * Null when the manifest has no entry, no display rendition, or an unreadable
     * scale — an installation that has never run the renditions pass serves masters
     * for everything, where declaring nothing is exactly right.
     *
     * @return array{0:int,1:int}|null
     */
    public static function ArtBox(string $relative): ?array
    {
        $entry = self::renditionManifest()[ltrim(trim($relative), '/')] ?? null;
        $dims  = $entry['display']['dimensions'] ?? null;
        if (!is_string($dims) || !preg_match('/^(\d+)x(\d+)$/', $dims, $m)) {
            return null;
        }

        $scale = (float) rtrim((string) (self::renditionMeta()['displayScale'] ?? ''), '%');
        if ($scale <= 0.0 || $scale >= 100.0) {
            return null;
        }

        return [
            (int) round((int) $m[1] * 100.0 / $scale),
            (int) round((int) $m[2] * 100.0 / $scale),
        ];
    }

    /**
     * assets/scrolls/renditions.json, read once per process.
     *
     * Absent or unreadable is not an error: an installation that has not run
     * bin/build-scroll-renditions.sh has only masters, and ArtUrl() then returns
     * exactly the URLs it always did. That is also what makes this change safe to
     * land before the generated files do.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function renditionManifest(): array
    {
        static $manifest = null;
        if ($manifest !== null) {
            return $manifest;
        }

        $manifest = [];
        $path     = (defined('DIR_ASSETS') ? DIR_ASSETS : '') . 'scrolls/renditions.json';
        if (!is_file($path)) {
            return $manifest;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded) && is_array($decoded['files'] ?? null)) {
            $manifest = $decoded['files'];
            self::$renditionMeta = array_diff_key($decoded, ['files' => true]);
        }

        return $manifest;
    }

    /** @var array<string,mixed> */
    private static array $renditionMeta = [];

    /**
     * The manifest's own top matter — printQuality, displayQuality, displayScale.
     * Populated by renditionManifest(), so reading it primes the same one-time load.
     *
     * @return array<string,mixed>
     */
    private static function renditionMeta(): array
    {
        self::renditionManifest();

        return self::$renditionMeta;
    }

    /**
     * The art size the partial currently being rendered should use.
     *
     * A static rather than a parameter because scroll_partial() is called from
     * INSIDE the order partials, which take no arguments of their own — there is
     * nothing to thread a size through. Render() sets it and restores the previous
     * value, so a nested render cannot leak a display size into a master print.
     *
     * The partials reach it through the scroll_art() helper at the foot of this
     * file rather than reading the static themselves.
     */
    private static string $artSize = self::ART_MASTER;

    public static function CurrentArtSize(): string
    {
        return self::$artSize;
    }

    public static function PartialPath(string $orderId): string
    {
        return DIR_TEMPLATE . 'revised-frontend/scroll/' . $orderId . '.tpl';
    }

    /*
     * ------------------------------------------------------------------------------
     * Layout data for the typeset engine.
     *
     * The engine runs in the browser and cannot see the plate it is typing on, so the
     * template hands it two things measured offline: where the type may run on this
     * exact certificate (free-space.json, as `data-sc-free` on .Award) and which cells
     * of each flourish are inked (ink-extents.json, as `data-sc-ink` on each flourish
     * <img>). Both are optional: an installation that has not run the builders gets
     * null and '' here, and the engine treats that as unconstrained.
     * ------------------------------------------------------------------------------
     */

    /**
     * Where free-space.json is read from when it is not the shipped file. Null means
     * assets/scrolls/free-space.json. Tests point it at a fixture, because the shipped
     * manifest is a generated artefact and a unit test must not depend on its numbers.
     */
    public static ?string $freeSpacePathOverride = null;

    private static function freeSpacePath(): string
    {
        return self::$freeSpacePathOverride
            ?: ((defined('DIR_ASSETS') ? DIR_ASSETS : '') . 'scrolls/free-space.json');
    }

    private static function inkExtentsPath(): string
    {
        return (defined('DIR_ASSETS') ? DIR_ASSETS : '') . 'scrolls/ink-extents.json';
    }

    /** @var array<string, array<string, mixed>|null> parsed free-space manifests, by path */
    private static array $freeSpaceManifests = [];

    /**
     * free-space.json, parsed once per process. Absent, unreadable or not JSON is
     * null, not an error: the file is generated, and a certificate with no profile is
     * still a certificate. Keyed by path so a test that swaps the override reads the
     * file it named.
     *
     * @return array<string, mixed>|null
     */
    private static function freeSpaceManifest(): ?array
    {
        $path = self::freeSpacePath();
        if (array_key_exists($path, self::$freeSpaceManifests)) {
            return self::$freeSpaceManifests[$path];
        }

        $manifest = null;
        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        return self::$freeSpaceManifests[$path] = $manifest;
    }

    /**
     * One manifest entry as two int lists of the same length, or null if it is not
     * that. A half-formed entry is treated as absent rather than trusted: a profile
     * whose L and R disagree about how many bands there are has no meaning band by
     * band, and the engine would be handed edges that belong to different rows.
     *
     * @return array{L: list<int>, R: list<int>}|null
     */
    private static function freeSpaceEntry(mixed $entry): ?array
    {
        if (!is_array($entry) || !is_array($entry['L'] ?? null) || !is_array($entry['R'] ?? null)) {
            return null;
        }

        // intval() would turn a non-numeric value into 0 — a plausible edge — so one is
        // treated as the entry being absent instead.
        foreach ([$entry['L'], $entry['R']] as $edges) {
            foreach ($edges as $edge) {
                if (!is_int($edge) && !is_float($edge) && !(is_string($edge) && is_numeric($edge))) {
                    return null;
                }
            }
        }

        $left  = array_map('intval', array_values($entry['L']));
        $right = array_map('intval', array_values($entry['R']));

        return $left !== [] && count($left) === count($right) ? ['L' => $left, 'R' => $right] : null;
    }

    /**
     * The free interval the type may run in, per 25px band, for one certificate.
     * See spec section 7.1 for the format and how the manifest is built.
     *
     * A fixed order has one measured profile per order/variant/rank, keyed
     * "smith//5" or "owl/parchment/0". A custom scroll has no profile of its own: it
     * is a ground, a border and a numeral set composed, and the ink of each was
     * measured alone, so the free space is where all of them are clear — the
     * rightmost left edge and the leftmost right edge, band by band. A component with
     * no entry is skipped rather than counted as blocked: the manifest only lists
     * what it measured, and "none" is not a border to measure. If nothing at all is
     * known the answer is null, which the engine reads as unconstrained.
     *
     * A band a component does not cover is unconstrained BY THAT COMPONENT, so a
     * shorter profile never truncates a longer one.
     *
     * `$manifest` is for tests; production passes nothing and gets the shipped file.
     *
     * @param  array<string, mixed>      $s         a BuildScroll() array
     * @param  array<string, mixed>|null $manifest
     * @return array{y0: int, step: int, L: list<int>, R: list<int>}|null
     */
    public static function FreeSpace(array $s, ?array $manifest = null): ?array
    {
        $manifest ??= self::freeSpaceManifest();
        if ($manifest === null) {
            return null;
        }

        $y0   = $manifest['y0'] ?? null;
        $step = $manifest['step'] ?? null;
        if (!is_numeric($y0) || !is_numeric($step) || (int) $step < 1) {
            return null;
        }

        $order = (string) ($s['order'] ?? '');
        $rank  = (int) ($s['rank'] ?? 0);
        if ($order === 'custom') {
            $recipe = is_array($s['recipe'] ?? null) ? $s['recipe'] : [];
            $ground = (string) ($recipe['ground'] ?? '');
            $border = (string) ($recipe['border'] ?? '');
            $set    = (string) ($recipe['rankSet'] ?? '');
            $keys   = array_values(array_filter([
                $ground === '' ? '' : 'ground/' . $ground,
                $border === '' ? '' : 'border/' . $border,
                $set === '' ? '' : 'rankset/' . $set . '/' . $rank,
            ], static fn (string $k): bool => $k !== ''));
            $source = $manifest['components'] ?? null;
        } else {
            $keys   = [$order . '/' . (string) ($s['variant'] ?? '') . '/' . $rank];
            $source = $manifest['configs'] ?? null;
        }

        if (!is_array($source)) {
            return null;
        }

        $left  = [];
        $right = [];
        $found = false;
        foreach ($keys as $key) {
            $entry = self::freeSpaceEntry($source[$key] ?? null);
            if ($entry === null) {
                continue;
            }
            $found = true;
            foreach ($entry['L'] as $band => $edge) {
                $left[$band] = isset($left[$band]) ? max($left[$band], $edge) : $edge;
            }
            foreach ($entry['R'] as $band => $edge) {
                $right[$band] = isset($right[$band]) ? min($right[$band], $edge) : $edge;
            }
        }

        return $found ? ['y0' => (int) $y0, 'step' => (int) $step, 'L' => $left, 'R' => $right] : null;
    }

    /**
     * FreeSpace() as the compact string `_text.tpl` puts in `data-sc-free`:
     * "<y0>,<step>|<L,L,…>|<R,R,…>". Empty when there is no profile, so the template
     * can omit the attribute with one test. All digits and separators, so it needs no
     * escaping.
     *
     * @param array<string, mixed>      $s
     * @param array<string, mixed>|null $manifest
     */
    public static function FreeSpaceAttr(array $s, ?array $manifest = null): string
    {
        $free = self::FreeSpace($s, $manifest);
        if ($free === null) {
            return '';
        }

        return $free['y0'] . ',' . $free['step'] . '|' . implode(',', $free['L']) . '|' . implode(',', $free['R']);
    }

    /** @var array<string, mixed>|null the `files` map of ink-extents.json */
    private static ?array $inkFiles = null;

    /**
     * ink-extents.json's per-file entries, read once per process. Missing or broken
     * is an empty map: no grids, which is the behaviour before the grids existed.
     *
     * @return array<string, mixed>
     */
    private static function inkFiles(): array
    {
        if (self::$inkFiles !== null) {
            return self::$inkFiles;
        }

        self::$inkFiles = [];
        $path           = self::inkExtentsPath();
        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded) && is_array($decoded['files'] ?? null)) {
                self::$inkFiles = $decoded['files'];
            }
        }

        return self::$inkFiles;
    }

    /**
     * A flourish's ink grid as "32x32:" plus its 32 rows of 8 hex digits, which is
     * what `data-sc-ink` carries (spec section 7.2). '' when the file has no grid.
     *
     * The shape is checked rather than trusted: this lands in an attribute the engine
     * indexes by cell, and a short row would shift every cell after it.
     *
     * Takes a path with or without the `scrolls/` prefix — order `resources` carry
     * it, the manifest keys do not — and strips it exactly as scroll_resource() does.
     */
    public static function InkGrid(string $relative): string
    {
        $key  = preg_replace('#^scrolls/#', '', ltrim(trim($relative), '/')) ?? '';
        $grid = self::inkFiles()[$key]['grid'] ?? null;
        if (!is_array($grid) || ($grid['w'] ?? null) !== 32 || ($grid['h'] ?? null) !== 32) {
            return '';
        }

        $rows = is_array($grid['rows'] ?? null) ? array_values($grid['rows']) : [];
        if (count($rows) !== 32) {
            return '';
        }
        foreach ($rows as $row) {
            if (!is_string($row) || preg_match('/^[0-9a-f]{8}\z/', $row) !== 1) {
                return '';
            }
        }

        return '32x32:' . implode('', $rows);
    }

    /**
     * Certificate date, e.g. "13th May 2024". Empty for a missing/zero date so the
     * text layer draws its writing-line blank instead of "30th Nov -0001".
     */
    public static function FormatDate(string $date): string
    {
        if ($date === '' || str_starts_with($date, '0000')) {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        $day    = (int) date('j', $ts);
        $suffix = ($day % 100 >= 11 && $day % 100 <= 13)
            ? 'th'
            : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$day % 10];

        return $day . $suffix . date(' F Y', $ts);
    }

    /**
     * Expand the placeholders Arcanyst's flavour text carries.
     *
     * Its viewmodel did this before display, so transliterating the strings alone
     * would print the raw markup on the certificate. Two forms exist, both only in
     * zodiac:
     *
     *   <<ISSUER>>                    the issuer as it reads after "of" — a park's
     *                                 name, or "the Kingdom of the Wetlands"
     *   <<prefix|MONTH|suffix|else>>  prefix . month . suffix when a month is
     *                                 known, otherwise the else branch verbatim
     *
     * Month handling proper is a separate branch, so $month is normally null and
     * the else branch is taken — which is exactly what those branches were written
     * for ("for whole months", "You're a", "the"). An unrecognised << >> block is
     * left alone rather than guessed at; ScrollFlavourTest fails if one ever
     * reaches a certificate.
     */
    public static function ExpandFlavour(string $flavour, string $issuerName = '', ?string $month = null): string
    {
        if ($flavour === '' || !str_contains($flavour, '<<')) {
            return $flavour;
        }

        $flavour = str_replace('<<ISSUER>>', $issuerName, $flavour);

        return (string) preg_replace_callback(
            '/<<([^<>]*)>>/',
            static function (array $m) use ($month): string {
                $parts = explode('|', $m[1]);
                // prefix | MONTH | suffix | else
                if (count($parts) !== 4 || $parts[1] !== 'MONTH') {
                    return $m[0];
                }
                return $month === null || $month === ''
                    ? $parts[3]
                    : $parts[0] . $month . $parts[2];
            },
            $flavour
        );
    }

    /**
     * @param array $context Recognised keys include:
     *   - customName (string) the title an officer typed at print time. Honoured ONLY
     *     when the award resolves to a custom entry (Custom Award 94 / Custom Title
     *     246), where it beats the stored custom_name and the kingdomaward name;
     *     IGNORED entirely for every other award, whose name comes from the
     *     catalogue and is not the officer's to retype. Sanitised and capped here —
     *     see SanitiseCustomName() and CUSTOM_NAME_MAX_LENGTH.
     */
    public static function BuildScroll(array $awardRow, array $context): array
    {
        $order = self::OrderForAwardRow($awardRow);
        if ($order === null) {
            throw new InvalidArgumentException(
                'No certificate for award_id ' . (int) ($awardRow['AwardId'] ?? 0)
            );
        }

        $rawRank     = (int) ($awardRow['Rank'] ?? 0);
        $rank        = self::ClampRank($order, $rawRank);
        $ordinalRank = self::OrdinalRank($order, $rawRank);

        $variant = '';
        if ($order['variants'] !== []) {
            $requested = (string) ($context['variant'] ?? '');
            $variant = in_array($requested, $order['variants'], true)
                ? $requested
                : $order['variants'][0];
        }

        // Display text that arrives double-encoded (UTF-8 read as Windows-1252 and encoded
        // again: "â€œHeadâ€" for “Head”) is repaired as it becomes a certificate, never in
        // the database. Every user-supplied string below goes through it — see
        // ScrollText::RepairMojibake(), whose browser twin the composer applies to the
        // boxes an officer types in, so the preview and the print agree.
        $parkName = ScrollText::RepairMojibake(trim((string) ($awardRow['ParkName'] ?? '')));

        // The issuer line reads: "and" [the] [title] [of] / NAME.
        //
        // A PARK needs all of it. Park names are bare — "Mordengaard", "Granite Spyre";
        // only 1 of ~600 carries a rank word — so "and the Barony of / Mordengaard"
        // reads correctly. ORK stores the real word per park (ork_park.parktitle_id ->
        // ork_parktitle.title, defined per kingdom) and most parks are not baronies
        // (349 Freeholds, 274 Grand Duchies, ~90 Baronies), so the model passes the
        // true word in and Barony is only the floor.
        //
        // A KINGDOM supplies its own. Every kingdom name in the ORK is already a whole
        // phrase — "The Kingdom of the Wetlands", "The Empire of the Iron Mountains",
        // "The Freeholds of Amtgard", "The Celestial Kingdom" — so prepending an
        // article and rank word gives "and the Kingdom of The Kingdom of the Wetlands".
        // 159,768 ladder awards, 47% of them, are kingdom-issued.
        //
        // Arcanyst carried UseThe/UseOf for exactly this, toggled by hand per scroll.
        // Here the shape is known from the data, so they are derived instead — and an
        // explicit context value still overrides, which is what the create flow uses.
        //
        // issuerName, issuerTitle, useThe and useOf stay the stored form. What the
        // certificate sets in separate sizes — "and the Kingdom of" / "the" / "Wetlands"
        // — is split out of them by ScrollText::IssuerLines() into issuerLead,
        // issuerArticle and issuerPlace, below.
        $isPark     = $parkName !== '';
        $issuerName = $isPark ? $parkName : ScrollText::RepairMojibake((string) ($awardRow['KingdomName'] ?? ''));

        // The title is the officer's or the park's own, corrected before the model runs:
        // "Inactive" (a park status that leaked into ork_parktitle.title on 5 parks) reads
        // as empty, and an empty park title takes the Barony floor rather than printing
        // "and the Inactive of". ScrollText::IssuerTitle() holds both rules and the
        // browser's issuerLines() applies the same two, so the preview agrees.
        $issuerTitle = ScrollText::IssuerTitle(
            ScrollText::RepairMojibake(trim((string) ($context['issuerTitle'] ?? ''))),
            $isPark ? 'park' : 'kingdom'
        );

        // PROVENANCE — who this certificate is for and which body issued it.
        //
        // Nothing on the certificate draws these; they exist so a caller that records
        // the print (ScrollPrintLog::RecordPrint()) does not have to re-derive facts
        // this function already resolved, and cannot disagree with it about them.
        // Without them the print log answers neither "has this player already had a
        // scroll?" nor "which kingdoms actually use this module?" — and for a
        // composed scroll, which has no awards_id at all, mundaneId is the ONLY link
        // back to a person.
        //
        // Derived from the award row where there is one, overridable from context
        // where there is not — the same pattern issuerTitle / useThe / useOf already
        // follow. An award-backed print (Model_Scrollery::scroll_for_awards_id())
        // needs no context at all: MundaneId / ParkId / KingdomId are on the row.
        // Scrollery/compose builds its row out of $_GET and carries none of the
        // three, so its controller passes them in context.
        //
        // Park beats kingdom, matching Model_Award::kingdom_for_grant_row(): a park
        // grant leaves at_kingdom_id blank, and the park is the narrower truth.
        $rowParkId    = (int) ($awardRow['ParkId'] ?? 0);
        $rowKingdomId = (int) ($awardRow['KingdomId'] ?? 0);
        $scopeType    = $rowParkId > 0 ? 'park' : ($rowKingdomId > 0 ? 'kingdom' : '');
        $scopeId      = $rowParkId > 0 ? $rowParkId : $rowKingdomId;

        // A zero is "the row does not say", not "the row says zero" — 120,284 award
        // rows carry neither scope id — so the fallback tests the VALUE, not just key
        // presence. ?? would keep a present-but-zero row value and never reach context.
        if ($scopeType === '') {
            $fromContext = (string) ($context['scopeType'] ?? '');
            $scopeType   = in_array($fromContext, ['park', 'kingdom'], true) ? $fromContext : '';
            $scopeId     = $scopeType === '' ? 0 : (int) ($context['scopeId'] ?? 0);
        }

        $mundaneId = (int) ($awardRow['MundaneId'] ?? 0);
        if ($mundaneId <= 0) {
            $mundaneId = (int) ($context['mundaneId'] ?? 0);
        }

        $useThe      = (bool) ($context['useThe'] ?? $isPark);
        $useOf       = (bool) ($context['useOf'] ?? $isPark);
        $issuerLines = ScrollText::IssuerLines($issuerTitle, $issuerName, $useThe, $useOf, $isPark ? 'park' : 'kingdom');

        // Only the custom scroll composes its own art; every other order's layers
        // are fixed by its partial.
        $recipe = $order['id'] === 'custom'
            ? self::NormaliseRecipe(is_array($context['recipe'] ?? null) ? $context['recipe'] : [])
            : [];

        $design = self::NormaliseDesign(is_array($context['design'] ?? null) ? $context['design'] : []);

        // A requested Custom Border is honoured only where the order's art can lose its
        // frame; elsewhere it is silently ignored, so a hand-edited URL cannot ask for it.
        $customBorder = null;
        if ($design['customBorder']) {
            $rule = self::CustomBorderRule((string) $order['id'], $rank, $variant, $recipe);
            if ($rule !== null) {
                $customBorder = [
                    'color1'     => $design['color1'],
                    'color2'     => $design['color2'],
                    'matchStyle' => $design['matchStyle'],
                    'passes'     => $rule['passes'],
                    'ground'     => $rule['ground'],
                ];
            }
        }

        return [
            'mundaneId'   => max(0, $mundaneId),
            'scopeType'   => $scopeType,
            'scopeId'     => max(0, $scopeId),
            'order'       => $order['id'],
            // The ART rank. Every order partial keys its numeral off this, so it stays
            // clamped to what the plates actually ship. See OrdinalRank() for why the
            // word beside it is written from a different number.
            'rank'        => $rank,
            'ordinalRank' => $ordinalRank,
            // A blank ordinal is an invitation to write the rank in by hand. That is right
            // for a ladder — including one recorded at rank 0, which 292 of Walker in the
            // Middle's 379 grants are — and wrong for a Knighthood, which has no ordinal to
            // write. Every bespoke order is a ladder, so they are excluded from the question
            // entirely rather than made to depend on a column their synthesised rows do not
            // carry: controller.Scrollery and controller.ScrolleryAjax build an award row
            // out of $_GET/$_POST with no IsLadder key, and a blank Griffon at rank 0 is
            // exactly the hand-writing case. The (int) cast is load-bearing — IsLadder
            // arrives from Yapo as the raw column value ('1'/'0'/null), so a strict === 1
            // would be false for every ladder and invert the rule.
            'ranked'      => $order['id'] !== 'custom'
                || $rank > 0
                || (int) ($awardRow['IsLadder'] ?? 0) === 1,
            'variant'     => $variant,
            'title'       => ScrollText::RepairMojibake(self::ResolveTitle($awardRow, (string) ($context['customName'] ?? ''))),
            // Introduces the title on the two custom entries, where 'ranked' is false and
            // the ordinal phrase above renders nothing. '' everywhere else, so the two can
            // never both appear. array_key_exists, not ??, because '' is a legitimate
            // override meaning "print no phrase" and must not fall back to the default.
            // See TitlePhrase().
            'titlePhrase' => ScrollText::RepairMojibake(self::TitlePhrase(
                $awardRow,
                array_key_exists('titlePhrase', $context)
                    ? (string) $context['titlePhrase']
                    : self::PHRASE_UNSET
            )),
            'player'      => ScrollText::RepairMojibake((string) ($context['player'] ?? '')),
            'pronoun'     => ScrollText::RepairMojibake((string) ($context['pronoun'] ?? 'their')),
            'reason'      => ScrollText::RepairMojibake((string) ($awardRow['Note'] ?? '')),
            'date'        => self::FormatDate((string) ($awardRow['Date'] ?? '')),
            'givenBy'     => ScrollText::RepairMojibake((string) ($awardRow['GivenBy'] ?? '')),
            'issuerTitle' => $issuerTitle,
            'issuerName'  => $issuerName,
            'useThe'      => $useThe,
            'useOf'       => $useOf,
            // The display split of the four keys above, which are left as stored. A
            // template that draws the issuer in parts reads these. The flavour text
            // gets ScrollText's "phrase" instead (see below), not these parts.
            'issuerLead'    => $issuerLines['lead'],
            'issuerArticle' => $issuerLines['article'],
            'issuerPlace'   => $issuerLines['place'],
            'heraldry'    => (string) ($context['heraldry'] ?? ''),
            'flavour'     => self::ExpandFlavour(
                (string) ($order['flavour'][(string) $rank] ?? ''),
                // "a shining star of <<ISSUER>>" wants the issuer as it reads after "of":
                // a park's name as stored, a kingdom as "the Kingdom of the Wetlands".
                // The stored kingdom name alone gave "star of The Kingdom of the Wetlands",
                // and the composer's split name gave "star of the Wetlands".
                $issuerLines['phrase'],
                $context['month'] ?? null
            ),
            'primary'     => $order['primary'],
            'secondary'   => $order['secondary'],
            'resources'   => $order['resources'],
            'recipe'       => $recipe,
            'design'       => $design,
            // Every order takes the ribbon; only a ticked design asks for it.
            'customRibbon' => $design['customRibbon'] ? $design['ribbonColor'] : null,
            'customBorder' => $customBorder,
        ];
    }

    /**
     * Render one certificate's markup. `$s` is the name every partial binds to.
     *
     * A missing partial file is a deploy/packaging fault, not a user error, and a
     * bulk run may be 200 certificates deep when it surfaces — so it degrades to a
     * visible placeholder and the rest of the batch still prints. A scroll with no
     * order at all is a programmer error and still throws.
     */
    public static function Render(array $s, string $artSize = self::ART_MASTER): string
    {
        $orderId = (string) ($s['order'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('Scroll::Render() called with no order');
        }

        $path = self::PartialPath($orderId);
        if (!is_file($path)) {
            return '<div xmlns="http://www.w3.org/1999/xhtml"'
                . ' style="display:flex;align-items:center;justify-content:center;'
                . 'width:2550px;height:3300px;border:8px dashed #999;box-sizing:border-box;'
                . 'font:64px/1.4 serif;color:#666;text-align:center;padding:200px;">'
                . 'This certificate could not be printed: its artwork is missing from this'
                . ' installation (' . scroll_e($orderId) . ').'
                . '</div>';
        }

        $cacheKey = self::renderCacheKey($s, $artSize);
        $cached   = self::cacheGet($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Restored rather than reset: a partial that renders another scroll inside
        // itself must not silently change this one's plate size.
        $previousSize  = self::$artSize;
        self::$artSize = in_array($artSize, self::ART_SIZES, true) ? $artSize : self::ART_MASTER;

        // $art stays the bare base, byte-for-byte what every partial already binds
        // to. A partial that wants the rendition for the size in force calls
        // scroll_art() instead; one that has not been converted yet keeps drawing
        // masters, which is correct rather than broken.
        $art = self::ArtBase();
        ob_start();
        try {
            include $path;
            $html = (string) ob_get_clean();
        } finally {
            self::$artSize = $previousSize;
        }

        self::cachePut($cacheKey, $html);

        return $html;
    }

    /**
     * How long a composed certificate stays cached. One hour: long enough that a
     * bulk run's reprint, a re-paginate and a "that one came out wrong, print it
     * again" are all hits, short enough that nothing is stale for a working day.
     */
    public const RENDER_CACHE_TTL = 3600;

    /**
     * Refuse to cache anything approaching memcached's 1 MB per-item limit.
     *
     * An oversized set() fails SILENTLY — no exception, no false that anything
     * checks — so a page-sized entry would look cached and never be. That is also
     * exactly why this cache is per CERTIFICATE and never per page: 200 certificates
     * is megabytes, one certificate is kilobytes.
     */
    private const RENDER_CACHE_MAX_BYTES = 900000;

    /**
     * The cache key for one composed certificate.
     *
     * `$s` IS the whole input. Render() is a pure function of it and the partial
     * files, so hashing it wholesale is both the cheapest correct key and the only
     * one that cannot forget a field: the recipe, the variant, the resolved pronoun
     * (including a per-print override), the custom name and the title phrase are all
     * inside it, so two prints that differ in any of them cannot collide. A key
     * hand-assembled from awards_id + variant would have made a reprint with a
     * different recipe serve the first one's markup.
     *
     * The partial generation stamp is the second half. Markup composed before a
     * deploy that edits a .tpl must not survive it, and the .tpl files are the other
     * input Render() has.
     *
     * ⚠️ NO VIEWER IS IN THIS KEY, AND NONE MAY BE. That is safe only because `$s`
     * carries no authority decision: by the time Render() sees it, the (viewer,
     * award) gate in Model_Scrollery::print_permission() has already said yes, and
     * that gate is deliberately never cached on anything less than the pair. This
     * caches the ARTEFACT, not the permission to have it.
     */
    private static function renderCacheKey(array $s, string $artSize): string
    {
        return hash('sha256', (string) json_encode([
            'art'  => in_array($artSize, self::ART_SIZES, true) ? $artSize : self::ART_MASTER,
            'tpl'  => self::partialGeneration(),
            /*
             * The ARTWORK's generation, which is not the templates'. Redrawing a plate
             * changes no .tpl, so partialGeneration() does not move and the cached HTML
             * keeps serving the URL it was built with. That is how a corrected
             * owl/rank_9 stayed invisible after the file on disk was already right.
             * The manifest is rewritten by bin/build-scroll-renditions.sh on every art
             * change — and its `sha` entries are what ArtUrl() now puts in the URL —
             * so its own mtime is the right stamp for "the art moved".
             */
            'gen'  => self::artGeneration(),
            /*
             * The LAYOUT data's generation. `data-sc-free` and `data-sc-ink` are baked
             * into the cached markup from free-space.json and ink-extents.json, and
             * regenerating either changes no template, no art and no input, so neither
             * stamp above moves. Without this a recalibrated profile would reach the
             * browser only as each certificate's cache entry expired.
             */
            'layout' => self::layoutGeneration(),
            'data' => $s,
        ]));
    }

    /** renditions.json's mtime: the stamp that moves when the artwork does. */
    private static function artGeneration(): int
    {
        static $stamp = null;
        if ($stamp !== null) {
            return $stamp;
        }

        $mtime = @filemtime((defined('DIR_ASSETS') ? DIR_ASSETS : '') . 'scrolls/renditions.json');
        $stamp = $mtime === false ? 0 : $mtime;

        return $stamp;
    }

    /** @var array{0: string, 1: string}|null [free-space path, stamp] */
    private static ?array $layoutStamp = null;

    /**
     * The mtimes of free-space.json and ink-extents.json, as "free|ink": the stamp
     * that moves when the layout data does. 0 stands for a file that is not there,
     * so a missing manifest is a stable key rather than an error.
     *
     * Taken once per process, like artGeneration(), and remembered with the path it
     * was taken for so that pointing $freeSpacePathOverride at another file cannot be
     * answered from the old one.
     */
    private static function layoutGeneration(): string
    {
        $free = self::freeSpacePath();
        if (self::$layoutStamp !== null && self::$layoutStamp[0] === $free) {
            return self::$layoutStamp[1];
        }

        $mtime = static function (string $path): int {
            $t = @filemtime($path);

            return $t === false ? 0 : $t;
        };
        $stamp = $mtime($free) . '|' . $mtime(self::inkExtentsPath());

        self::$layoutStamp = [$free, $stamp];

        return $stamp;
    }

    /**
     * The newest mtime across every scroll partial, memoised per process.
     *
     * One directory scan per request buys a key that invalidates itself on deploy.
     * A per-order stamp would be cheaper still and would be wrong: an order partial
     * pulls in the shared `_border.tpl` / `_text.tpl` / `_heraldry.tpl` layers, so
     * editing a shared one must invalidate all sixteen.
     */
    private static function partialGeneration(): int
    {
        static $stamp = null;
        if ($stamp !== null) {
            return $stamp;
        }

        $stamp = 0;
        foreach (glob(DIR_TEMPLATE . 'revised-frontend/scroll/*.tpl') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime > $stamp) {
                $stamp = $mtime;
            }
        }

        return $stamp;
    }

    /**
     * GhettoCache, or nothing at all.
     *
     * Never throws and never fails a render: memcached may be absent (the unit-test
     * bootstrap stubs the extension with a get() that always misses), and a cache
     * that is down must cost a rebuild, not a blank certificate.
     */
    private static function cacheGet(string $key): ?string
    {
        $cache = Ork3::$Lib->ghettocache ?? null;
        if (!is_object($cache)) {
            return null;
        }

        try {
            $hit = $cache->get('Scroll.Render', $key, self::RENDER_CACHE_TTL);
        } catch (Throwable $e) {
            return null;
        }

        return is_string($hit) ? $hit : null;
    }

    private static function cachePut(string $key, string $html): void
    {
        if ($html === '' || strlen($html) > self::RENDER_CACHE_MAX_BYTES) {
            return;
        }

        $cache = Ork3::$Lib->ghettocache ?? null;
        if (!is_object($cache)) {
            return;
        }

        try {
            $cache->cache('Scroll.Render', $key, $html);
        } catch (Throwable $e) {
            // A cache write failing is not a print failing.
        }
    }

    /**
     * Possessive from a free-text pronoun string.
     *
     * Free text is subject/object — "he/him", "she/her", "they/them" — and the
     * certificate needs the POSSESSIVE. Reading the second token gives "him", so the
     * certificate would say "with him third". Match the LEADING token against the
     * known subjects instead.
     *
     * Longest subject first, because "e/ey" and "ze/zie" are themselves slash-joined
     * and would otherwise lose to the bare "e" and "ze" they begin with.
     *
     * BOTH SIDES ARE NORMALISED before matching — see NormalisePronounText(). The
     * match used to require an exact string or a bare `subject/` prefix, so the two
     * commonest shapes people actually type missed: "she / her" (spaces around the
     * slash) and "They." (trailing punctuation) both fell through to "their",
     * silently, for a recipient who had stated otherwise. "He/Him." already worked —
     * the `he/` prefix test never looked past the slash — so the punctuation half of
     * this only ever bit slash-less values.
     *
     * @param array<int, array{Subject:string, Possessive:string}> $pronounRows
     */
    public static function PossessiveFromFreetext(string $freetext, array $pronounRows): string
    {
        $text = self::NormalisePronounText($freetext);
        if ($text === '') {
            return '';
        }

        $subjects = [];
        foreach ($pronounRows as $row) {
            // The stored subjects are normalised the same way rather than merely
            // lowercased, so the two sides can never disagree about a space.
            $subject = self::NormalisePronounText((string) ($row['Subject'] ?? ''));
            if ($subject !== '') {
                $subjects[$subject] = (string) ($row['Possessive'] ?? '');
            }
        }
        uksort($subjects, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($subjects as $subject => $possessive) {
            if ($text === $subject || str_starts_with($text, $subject . '/')) {
                return $possessive;
            }
        }

        return '';
    }

    /**
     * A free-text pronoun string reduced to the form the subject table is in.
     *
     * Lowercased; whitespace runs collapsed to one space and then removed from
     * either side of a slash ("she / her" -> "she/her"); leading and trailing
     * punctuation trimmed ("They." -> "they", "(he/him)" -> "he/him").
     *
     * The slash itself is NEVER removed — the pronoun table's own subjects include
     * "e/ey" and "ze/zie", and the prefix rule in PossessiveFromFreetext() depends
     * on it.
     */
    public static function NormalisePronounText(string $raw): string
    {
        $text = mb_strtolower(trim($raw));
        // \s and the non-breaking space people paste out of documents.
        $text = (string) preg_replace('/[\s\x{00a0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\/\s*/u', '/', $text);

        return trim($text, " \t\n\r\0\x0B.,;:!?\"'()[]{}<>");
    }

    /** Server-side twin of the create-tool's `<input maxlength>` on the override field. */
    public const PRONOUN_OVERRIDE_MAX_LENGTH = 20;

    /** The width of ork_mundane.pronoun_freetext — the profile door's own input cap. */
    public const PRONOUN_FREETEXT_MAX_LENGTH = 40;

    /**
     * Resolve a per-print pronoun override — free text a human typed into a box,
     * not a closed vocabulary. It goes through the exact same subject/object ->
     * possessive resolution PossessiveFromFreetext() gives a stored
     * pronoun_freetext value, so the certificate cannot print "with he/him
     * third" through the override door after the profile door was closed
     * against exactly that.
     *
     * A typed value that matches no known subject — "his", "eir", or any
     * neopronoun possessive that is not itself a subject in ork_pronoun — is
     * already a possessive word, so it passes through unresolved rather than
     * being discarded.
     *
     * An unmatched value that still contains a "/" is different: that shape is
     * an officer typing a pronoun SET ("ze/zir"), not a single possessive word,
     * and a set printed on the certificate is the same class of error the
     * table match above exists to prevent — just for a set ork_pronoun does
     * not carry ("ze/zie" resolves to "hir" via the table; "ze/zir" cannot,
     * because no such row exists — a data gap, not a logic one; the fix is a
     * row in ork_pronoun, not more parsing here). The token after the FIRST
     * slash is the best guess for the subject/object[/possessive] shapes
     * people actually type: "ze/zir" -> "zir", correct for that set. If
     * nothing usable survives the split (e.g. the value is only slashes),
     * fall back to "their" rather than print a fragment.
     *
     * Capped at the same length the create-tool's own input enforces
     * client-side: a client-side cap is not a server-side guarantee, and this
     * value reaches a printed certificate. Lowercased unconditionally — every
     * inferred pronoun is already lowercase, and "with His third" is wrong
     * mid-sentence, so the override cannot be the one path that prints a
     * capital.
     *
     * @param array<int, array{Subject:string, Possessive:string}> $pronounRows
     */
    public static function ResolvePronounOverride(string $raw, array $pronounRows): string
    {
        return self::ResolvePossessive($raw, $pronounRows, self::PRONOUN_OVERRIDE_MAX_LENGTH);
    }

    /**
     * How long a minted print token stays good.
     *
     * FIVE MINUTES, and the reasoning is worth keeping. The token exists so a
     * headless renderer, an archiver or a PDF sidecar can fetch Scrollery/render
     * without a session — it is a hand-off from a page that has ALREADY passed the
     * per-(viewer, award) gate, not a second way to earn permission. The whole
     * hand-off is a fetch that starts immediately, so anything longer is a window
     * with no use for it. Long enough to survive a slow rasteriser and a retry;
     * short enough that a token in an access log or a Referer header is worthless
     * by the time anyone reads it.
     */
    public const PRINT_TOKEN_TTL = 300;

    /**
     * The token format's own version, so a future change to what is signed can be
     * rejected rather than silently mis-verified.
     */
    private const PRINT_TOKEN_VERSION = 'v1';

    /**
     * A short-lived signed grant to print an EXACT set of awards, as one viewer.
     *
     * ⚠️ NOT SINGLE-USE, deliberately. Recorded because it is the first thing a
     * reviewer will ask.
     *
     * Single use needs server-side state, and the surface it would guard is a GET:
     * Scrollery/render is refreshed, back-buttoned, prefetched by the browser, and
     * re-requested when a print dialog is cancelled and reopened. A one-shot token
     * turns every one of those into a denial an officer cannot explain. Against
     * that, replay buys an attacker nothing new — the token authorises exactly the
     * id set someone was already entitled to print, for at most five minutes, and
     * the print log records every fetch either way.
     *
     * If single use is later wanted, the shape is: add a random `jti` to the signed
     * payload, write it to memcached with PRINT_TOKEN_TTL on first verify, and
     * refuse a token whose jti is already present. That is a real change in
     * behaviour for refresh, so it should be a decision, not a tightening.
     *
     * THE ID SET IS PART OF THE SIGNATURE AND IS NOT CARRIED IN THE TOKEN. The
     * verifier re-derives it from the ids the request actually asks for, so a token
     * minted for awards 1,2,3 cannot be replayed against 1,2,3,4 — the signed
     * string differs and the MAC fails. Narrowing fails for the same reason, which
     * is stricter than it needs to be and is left that way: "this token is for this
     * run" is a rule with no edges.
     *
     * The viewer id and the expiry ARE carried, because a headless fetch has no
     * session to read an identity out of — the token is the identity.
     *
     * @param array<int, mixed> $awardsIds the exact ids this token may print
     */
    public static function MintPrintToken(array $awardsIds, int $viewerId, int $ttl = self::PRINT_TOKEN_TTL): string
    {
        if ($viewerId <= 0) {
            return '';
        }

        $canonical = self::canonicalPrintIds($awardsIds);
        if ($canonical === '') {
            return '';
        }

        // $ttl is taken as given rather than clamped: a non-positive TTL mints a
        // token that is already expired, which is refused on arrival — fail-closed,
        // useless to a caller, and the only way to exercise the expiry branch in a
        // test without sleeping through it.
        $expires = time() + $ttl;
        $sig     = self::printTokenSignature($canonical, $viewerId, $expires);

        return self::PRINT_TOKEN_VERSION . '.' . $viewerId . '.' . $expires . '.' . $sig;
    }

    /**
     * Verify a print token against the ids the request is actually asking for.
     *
     * Returns the viewer the token speaks for, or 0 with a reason. A controller
     * uses it IN PLACE OF the session read, then runs the ordinary per-award
     * permission gate with that viewer id — the token says "this is who is asking",
     * never "let this through".
     *
     * Reasons are for logging, not for the requester: telling an unauthenticated
     * caller the difference between "expired" and "bad signature" is free
     * information about the secret.
     *
     * @param array<int, mixed> $awardsIds the ids the request carries
     * @return array{Valid: bool, ViewerId: int, Reason: string}
     */
    public static function VerifyPrintToken(string $token, array $awardsIds): array
    {
        $bad = static fn (string $why): array => ['Valid' => false, 'ViewerId' => 0, 'Reason' => $why];

        $parts = explode('.', trim($token));
        if (count($parts) !== 4 || $parts[0] !== self::PRINT_TOKEN_VERSION) {
            return $bad('malformed');
        }

        [, $viewer, $expires, $sig] = $parts;

        $viewerId = (int) $viewer;
        $expiry   = (int) $expires;
        // Only the CANONICAL decimal form of each number is accepted. " 7", "07"
        // and "7e2" all survive (int) as 7 and would otherwise let one grant be
        // presented under several spellings — the signature would still verify,
        // because it is recomputed from the parsed value, so this is the check that
        // makes the token's text and its meaning the same thing.
        if ((string) $viewerId !== $viewer || (string) $expiry !== $expires || $viewerId <= 0) {
            return $bad('malformed');
        }

        $canonical = self::canonicalPrintIds($awardsIds);
        if ($canonical === '') {
            return $bad('no-ids');
        }

        // The MAC is checked BEFORE the clock. An expired token and a forged one
        // must take the same path, and hash_equals is what keeps the comparison
        // constant-time.
        if (!hash_equals(self::printTokenSignature($canonical, $viewerId, $expiry), $sig)) {
            return $bad('bad-signature');
        }

        if ($expiry < time()) {
            return $bad('expired');
        }

        return ['Valid' => true, 'ViewerId' => $viewerId, 'Reason' => ''];
    }

    /**
     * The id set as one canonical string: unique, ascending, comma-joined.
     *
     * Sorted so the same SET signs identically however the caller ordered it —
     * a render URL's ids are a selection, not a sequence — and de-duplicated so
     * "1,1,2" and "1,2" cannot be two different grants for the same run.
     *
     * @param array<int, mixed> $awardsIds
     */
    private static function canonicalPrintIds(array $awardsIds): string
    {
        $ids = [];
        foreach ($awardsIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return '';
        }

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);

        return implode(',', $ids);
    }

    /**
     * hmac_sha256 over `version|ids|viewer|expiry`, hex.
     *
     * The pipe-joined layout matters: every field is either digits or a
     * comma-joined digit list, so no value can contain the separator and shift a
     * boundary — "1,2|3" is unreachable, and with it the class of attack where two
     * different (ids, viewer) pairs produce one signed string.
     */
    private static function printTokenSignature(string $canonicalIds, int $viewerId, int $expires): string
    {
        return hash_hmac(
            'sha256',
            self::PRINT_TOKEN_VERSION . '|' . $canonicalIds . '|' . $viewerId . '|' . $expires,
            self::printTokenSecret()
        );
    }

    /**
     * The signing key.
     *
     * `SCROLL_PRINT_SECRET` in config is the intended source and a deployment
     * SHOULD define one (any long random string; it never leaves the server and
     * rotating it only invalidates tokens minted in the last five minutes).
     *
     * Without it the key is DERIVED from server-side configuration that is already
     * secret and already stable across a request — never generated per process,
     * which would break the moment PHP-FPM answered the render with a different
     * worker than minted the token. The derivation is a floor so the feature works
     * on an installation that has not been told about the constant, not a
     * substitute for setting one: on an install still carrying config.dist.php's
     * placeholder DB_PASSWORD the derived key is guessable, which is why the
     * constant exists.
     */
    private static function printTokenSecret(): string
    {
        if (defined('SCROLL_PRINT_SECRET') && trim((string) SCROLL_PRINT_SECRET) !== '') {
            return (string) SCROLL_PRINT_SECRET;
        }

        return hash(
            'sha256',
            'scrollery-print-token|'
            . (defined('DB_PASSWORD') ? DB_PASSWORD : '')
            . '|' . (defined('DB_USERNAME') ? DB_USERNAME : '')
            . '|' . (defined('DB_DATABASE') ? DB_DATABASE : '')
        );
    }

    /**
     * THE possessive resolver. Both doors go through it, which is the point.
     *
     * The two doors used to disagree. A stored profile value that missed the
     * subject table fell straight to "their"; the SAME STRING typed into the
     * officer's override box got the post-slash fallback documented above. So
     * "ze/zir" on a recipient's own profile printed "their", while an officer
     * retyping it verbatim printed "zir" — the recipient's own statement about
     * themselves was the one the certificate ignored. There is no reading of that
     * which is correct, so there is now one function and no second opinion.
     *
     * $maxLength is each door's own input width, not a policy difference: the
     * override box is `maxlength=20` and ork_mundane.pronoun_freetext is
     * varchar(40). A client-side cap is not a server-side guarantee and this value
     * reaches a printed certificate, so it is re-applied here either way.
     *
     * Order, and why:
     *   1. the subject table — "he/him" -> "his";
     *   2. the post-slash token for a SET the table does not carry — "ze/zir" ->
     *      "zir", and "their" if nothing usable survives the split;
     *   3. otherwise the value itself, which for a slash-less string is already a
     *      possessive word ("his", "eir", a neopronoun that is not a subject row).
     *
     * Lowercased unconditionally — every inferred pronoun is already lowercase and
     * "with His third" is wrong mid-sentence, so neither door may be the one path
     * that prints a capital.
     *
     * @param array<int, array{Subject:string, Possessive:string}> $pronounRows
     */
    public static function ResolvePossessive(string $raw, array $pronounRows, int $maxLength): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $capped   = $maxLength > 0 ? mb_substr($trimmed, 0, $maxLength) : $trimmed;
        $resolved = self::PossessiveFromFreetext($capped, $pronounRows);
        if ($resolved !== '') {
            return mb_strtolower($resolved);
        }

        // Normalised BEFORE the split, so "ze / zir" and "ze/zir." reach the same
        // token the table lookup above already agreed to read.
        $normalised = self::NormalisePronounText($capped);
        if ($normalised === '') {
            return '';
        }

        if (str_contains($normalised, '/')) {
            $afterSlash = trim(explode('/', $normalised)[1] ?? '');

            return $afterSlash !== '' ? mb_strtolower($afterSlash) : 'their';
        }

        return mb_strtolower($normalised);
    }
}

/**
 * Escape a value for a certificate partial.
 *
 * NOT html_encode(): that is htmlentities() with the HTML5 tables, which turns "#"
 * into "&num;" — so a colour reaching a style attribute renders as
 * "--primary-color:&num;333333". A browser does decode that reference before CSS
 * sees it, but it is fragile and unreadable in view-source, and these partials put
 * colours and length-driven calc() values into style attributes constantly.
 * htmlspecialchars is also what the rest of the ORK's templates use.
 */
function scroll_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Include a shared scroll partial with an isolated variable scope.
 *
 * A bare `include` shares the caller's scope, which lets a partial silently
 * inherit a stray $primary from an earlier layer. extract() inside a function
 * gives partials real parameters, the way Aurelia's `bindable` did.
 */
function scroll_partial(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $art = Scroll::ArtBase();
    include DIR_TEMPLATE . 'revised-frontend/scroll/_' . $name . '.tpl';
}

/**
 * One plate's URL at whatever size the render in progress asked for.
 *
 * THE ONE-LINE CHANGE A CERTIFICATE PARTIAL NEEDS. `<?= $art ?>/lion/border.png`
 * becomes `<?= scroll_art('lion/border.png') ?>`, and that URL is then the display
 * rendition on the composer's preview stage and the print rendition (or the master,
 * where WebP lost to it) on render and compose. The size comes from the static
 * Scroll::Render() sets, because these partials take no arguments.
 *
 * Not applied to the partials here: those are .tpl files another workstream owns.
 * Until they are converted, every partial keeps composing off $art and keeps
 * drawing masters — correct, just not yet cheap.
 */
function scroll_art(string $relative): string
{
    return Scroll::ArtUrl($relative, Scroll::CurrentArtSize());
}

/**
 * The same, for a path out of an order's `resources` set.
 *
 * Those paths carry their own `scrolls/` prefix — they were re-rooted from
 * Arcanyst's ./resources/ash/ and kept whole — while ArtUrl() keys the rendition
 * manifest on the path BELOW that directory. _text.tpl bridged the two by
 * concatenating HTTP_ASSETS onto the raw value, which reaches the file but never
 * the manifest, and _text.tpl is the only scroll partial with no scroll_art() call
 * in it at all.
 *
 * That was invisible for fifteen orders, whose flourishes are the shared SVGs and
 * have no renditions to miss. jovious is the exception: it overrides all three with
 * rasters, so every jovious certificate served ribbon-top/right/bottom.png — 309 KB
 * of masters — including on the composer's preview stage, where the display
 * renditions of the same three come to about 24 KB.
 *
 * Stripping the prefix rather than storing the paths without it: the `resources`
 * values are also read by callers that want the asset path itself, and re-rooting
 * the data would move the problem rather than fix it.
 */
/**
 * A plate's src AND the box it must be drawn in, as attributes.
 *
 * `<img <?= scroll_art_attrs('crown/border.png') ?> alt="">` rather than
 * `<img src="<?= scroll_art('crown/border.png') ?>" alt="">`. The difference is the
 * width and height, and they matter because ArtUrl() may serve a rendition a third
 * the size of the master the layout was drawn against — see Scroll::ArtBox().
 *
 * Emits no dimensions when the master box is unknown, which reproduces the old
 * behaviour exactly for an installation that has not run the renditions pass.
 */
function scroll_art_attrs(string $relative): string
{
    $out = 'src="' . scroll_e(scroll_art($relative)) . '"';
    $box = Scroll::ArtBox($relative);

    return $box === null ? $out : $out . ' width="' . $box[0] . '" height="' . $box[1] . '"';
}

function scroll_resource(string $relative): string
{
    return scroll_art(preg_replace('#^scrolls/#', '', ltrim(trim($relative), '/')) ?? '');
}

/**
 * A flourish's ink grid, for `data-sc-ink`: "32x32:" and the 32 rows, or '' when the
 * file has none. Takes the same `scrolls/`-prefixed path scroll_resource() does.
 */
function scroll_ink_grid(string $relative): string
{
    return Scroll::InkGrid($relative);
}

/**
 * Is a Custom Border drawing on this certificate? BuildScroll() sets
 * `customBorder` only where the order, at this rank/variant/recipe, can lose its
 * frame, so an order template wraps its border passes in `if (!…)` and nothing more.
 */
function scroll_custom_border_active(array $s): bool
{
    return is_array($s['customBorder'] ?? null);
}

/**
 * Draw the Custom Border's knot at one of an order template's two slots.
 *
 * 'match' sits where the order's border passes were and draws one knot per entry in
 * `passes`, each blended with that pass's own style — the knot goes into the paper
 * the way the art it replaces did. 'exact' draws one plain knot, so the colours print
 * as picked: just below the heraldry, or — on mask, smith and battle arcane, whose
 * glow/burn/wash already lies over the heraldry — just over that effect. Every
 * template calls both; whichever does not match `matchStyle` echoes nothing, which is
 * what lets one template serve both modes without a branch of its own.
 */
function scroll_custom_border_slot(array $s, string $mode): void
{
    if (!scroll_custom_border_active($s)) {
        return;
    }
    $cb    = $s['customBorder'];
    $match = $mode === 'match';
    if ((bool) ($cb['matchStyle'] ?? false) !== $match) {
        return;
    }

    $styles = $match ? (array) ($cb['passes'] ?? []) : ['mix-blend-mode:normal;'];
    foreach ($styles as $style) {
        scroll_partial('knot', [
            'color1' => (string) ($cb['color1'] ?? ''),
            'color2' => (string) ($cb['color2'] ?? ''),
            'style'  => (string) $style,
        ]);
    }
}

/**
 * The ground an order draws: its frameless twin while a Custom Border is active and
 * the rule names one (jovious, warrior's tenth, custom on a framed ground), else
 * $default. Only a ground the compatibility data actually lists is honoured — the
 * value reaches an <img src>, and Render() is reachable with any scroll array.
 */
function scroll_custom_ground(array $s, string $default): string
{
    $ground = scroll_custom_border_active($s) ? ($s['customBorder']['ground'] ?? null) : null;
    if (!is_string($ground) || $ground === '') {
        return $default;
    }

    static $known = null;
    if ($known === null) {
        $known = [];
        foreach (Scroll::CustomBorderRules() as $rule) {
            $known = array_merge(
                $known,
                isset($rule['ground']) ? [$rule['ground']] : [],
                array_filter($rule['ranks'] ?? [], 'is_string'),
                array_filter($rule['recipeGrounds'] ?? [], 'is_string')
            );
        }
    }

    return in_array($ground, $known, true) ? $ground : $default;
}
