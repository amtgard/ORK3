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

    public const DEFAULT_RESOURCES = [
        'flourish_top'    => 'scrolls/common/text/squiggly-top.svg',
        'flourish_right'  => 'scrolls/common/text/squiggly-right.svg',
        'flourish_bottom' => 'scrolls/common/text/squiggly-bottom.svg',
        'writing_line'    => 'scrolls/common/text/writing-line.svg',
    ];

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

    public static function HasArt(array $awardRow): bool
    {
        return self::OrderForAwardId((int) ($awardRow['AwardId'] ?? 0)) !== null;
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
        $order = self::OrderForAwardId((int) ($awardRow['AwardId'] ?? 0));
        if ($order === null) {
            throw new InvalidArgumentException(
                'No certificate art for award_id ' . (int) ($awardRow['AwardId'] ?? 0)
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

    /** Render one certificate's markup. `$s` is the name every partial binds to. */
    public static function Render(array $s): string
    {
        $path = self::PartialPath($s['order']);
        if (!is_file($path)) {
            throw new RuntimeException('No partial for order ' . $s['order']);
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
