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
}
