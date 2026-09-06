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
     * It matters because `.Order` in scrollery.css is a FLAT 1.5em with no
     * length-scaling in _text.tpl at all — only scrollery-autofit.js shrinks it, in
     * the browser, after layout — so an unbounded value is a layout break, not a
     * small font.
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
     * The one rank set whose numerals are a small glyph (292x171) rather than a
     * whole-page plate, so it alone needs a box and a bottom offset.
     */
    public const RANK_SET_GLYPH = 'common';

    /**
     * The one rank set whose plates are oversized — 2625x3375 against the page's
     * 2550x3300 — and so need zodiac.tpl's own margin to re-centre.
     */
    public const RANK_SET_OVERSIZED = 'zodiac';

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

    public static function ArtBase(): string
    {
        return rtrim(HTTP_ASSETS, '/') . '/scrolls';
    }

    public static function PartialPath(string $orderId): string
    {
        return DIR_TEMPLATE . 'revised-frontend/scroll/' . $orderId . '.tpl';
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
     *   <<ISSUER>>                    the issuing park or kingdom's name
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

        $rank = self::ClampRank($order, (int) ($awardRow['Rank'] ?? 0));

        $variant = '';
        if ($order['variants'] !== []) {
            $requested = (string) ($context['variant'] ?? '');
            $variant = in_array($requested, $order['variants'], true)
                ? $requested
                : $order['variants'][0];
        }

        $parkName = trim((string) ($awardRow['ParkName'] ?? ''));

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
        $isPark     = $parkName !== '';
        $issuerName = $isPark ? $parkName : (string) ($awardRow['KingdomName'] ?? '');

        $issuerTitle = trim((string) ($context['issuerTitle'] ?? ''));
        if ($issuerTitle === '') {
            $issuerTitle = $isPark ? 'Barony' : '';
        }

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

        return [
            'mundaneId'   => max(0, $mundaneId),
            'scopeType'   => $scopeType,
            'scopeId'     => max(0, $scopeId),
            'order'       => $order['id'],
            'rank'        => $rank,
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
            'title'       => self::ResolveTitle($awardRow, (string) ($context['customName'] ?? '')),
            // Introduces the title on the two custom entries, where 'ranked' is false and
            // the ordinal phrase above renders nothing. '' everywhere else, so the two can
            // never both appear. array_key_exists, not ??, because '' is a legitimate
            // override meaning "print no phrase" and must not fall back to the default.
            // See TitlePhrase().
            'titlePhrase' => self::TitlePhrase(
                $awardRow,
                array_key_exists('titlePhrase', $context)
                    ? (string) $context['titlePhrase']
                    : self::PHRASE_UNSET
            ),
            'player'      => (string) ($context['player'] ?? ''),
            'pronoun'     => (string) ($context['pronoun'] ?? 'their'),
            'reason'      => (string) ($awardRow['Note'] ?? ''),
            'date'        => self::FormatDate((string) ($awardRow['Date'] ?? '')),
            'givenBy'     => (string) ($awardRow['GivenBy'] ?? ''),
            'issuerTitle' => $issuerTitle,
            'issuerName'  => $issuerName,
            'useThe'      => (bool) ($context['useThe'] ?? $isPark),
            'useOf'       => (bool) ($context['useOf'] ?? $isPark),
            'heraldry'    => (string) ($context['heraldry'] ?? ''),
            'flavour'     => self::ExpandFlavour(
                (string) ($order['flavour'][(string) $rank] ?? ''),
                $issuerName,
                $context['month'] ?? null
            ),
            'primary'     => $order['primary'],
            'secondary'   => $order['secondary'],
            'resources'   => $order['resources'],
            // Only the custom scroll composes its own art; every other order's layers
            // are fixed by its partial.
            'recipe'      => $order['id'] === 'custom'
                ? self::NormaliseRecipe(is_array($context['recipe'] ?? null) ? $context['recipe'] : [])
                : [],
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
    public static function Render(array $s): string
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

        $art = self::ArtBase();
        ob_start();
        include $path;
        return (string) ob_get_clean();
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
     * @param array<int, array{Subject:string, Possessive:string}> $pronounRows
     */
    public static function PossessiveFromFreetext(string $freetext, array $pronounRows): string
    {
        $text = strtolower(trim($freetext));
        if ($text === '') {
            return '';
        }

        $subjects = [];
        foreach ($pronounRows as $row) {
            $subject = strtolower(trim((string) ($row['Subject'] ?? '')));
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

    /** Server-side twin of the create-tool's `<input maxlength>` on the override field. */
    private const PRONOUN_OVERRIDE_MAX_LENGTH = 20;

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
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $capped   = mb_substr($trimmed, 0, self::PRONOUN_OVERRIDE_MAX_LENGTH);
        $resolved = self::PossessiveFromFreetext($capped, $pronounRows);
        if ($resolved !== '') {
            return strtolower($resolved);
        }

        if (str_contains($capped, '/')) {
            $afterSlash = trim(explode('/', $capped)[1] ?? '');

            return $afterSlash !== '' ? strtolower($afterSlash) : 'their';
        }

        return strtolower($capped);
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
