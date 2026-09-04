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
     */
    public static function ResolveTitle(array $awardRow): string
    {
        foreach (['KingdomAwardName', 'Name', 'AliasAwardName', 'CustomAwardName'] as $key) {
            $name = trim((string) ($awardRow[$key] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return '';
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
     * An absent/blank role tolerates the archery case: a real archery grant's
     * award_id dangles (0, 94, 131 under a base id nothing else uses), so the LEFT
     * JOIN to officer_role misses and yields NULL/''. NULL there means "unknown
     * award", not "this is an officer position" — and must still be printable.
     */
    private static function IsOfficerPosition(array $awardRow): bool
    {
        $role = strtolower(trim((string) ($awardRow['OfficerRole'] ?? 'none')));

        return $role !== '' && $role !== 'none';
    }

    /**
     * Whether an award with no design of its own may still be printed.
     *
     * Officer positions are excluded: an officer position is a job somebody held, not
     * an honour they were given.
     */
    public static function IsCustomEligible(array $awardRow): bool
    {
        return !self::IsOfficerPosition($awardRow);
    }

    /**
     * THE single resolution point for "what does this award print as".
     *
     * Order of precedence: an officer position first (excludes everything, by any
     * route), then the award's own design, then archery by name, then the custom
     * scroll, then nothing. Every caller — the reprint list, the bulk list, the
     * Awards tab, the create tool — goes through here, so coverage cannot drift
     * between surfaces.
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

        $specific = self::OrderForAwardId((int) ($awardRow['AwardId'] ?? 0));
        if ($specific !== null) {
            return $specific;
        }

        foreach (['KingdomAwardName', 'Name'] as $key) {
            $archery = self::ArcheryOrderForName((string) ($awardRow[$key] ?? ''));
            if ($archery !== null) {
                return $archery;
            }
        }

        return self::IsCustomEligible($awardRow) ? (self::Orders()['custom'] ?? null) : null;
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

        return [
            'order'       => $order['id'],
            'rank'        => $rank,
            'variant'     => $variant,
            'title'       => self::ResolveTitle($awardRow),
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
