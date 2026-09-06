<?php

declare(strict_types=1);

/**
 * The custom scroll's composable vocabulary.
 *
 * These are the pieces of Arcanyst's artwork that are NOT order-identifying: full-page
 * grounds, borders, and rank numerals. Order symbols (the lion, the griffon, the
 * phoenix) are deliberately absent — they are order identity, and a griffon on a
 * Knighthood reads as a mistake rather than a choice.
 *
 * Every ground is exactly 2550x3300, the foreignObject's own size, which is what makes
 * any ground a drop-in swap for any other.
 *
 * Returns an array; no side effects. startup.php's scandir of system/lib/ork3 is flat,
 * so nothing in data/ is auto-instantiated.
 */

return [
    /*
     * Grounds that already have a border painted INTO the image.
     *
     * Five of the twelve grounds are lifted from orders that draw no border layer at
     * all — flame.tpl says so in as many words ("the only order in this group that
     * requires no border partial") — because the frame is part of the plate. Drawing
     * any border over one of these gives the certificate TWO borders, one on top of
     * the other, which is what a flame ground with a knot border looked like.
     *
     * Confirmed by inspecting the left edge of all twelve: these five carry knotwork
     * (and in garber's and smith's case a motif); parchment, starfield, starfield2,
     * cyanotype, forge, rosewash and arcane are fields or washes with no frame.
     * NormaliseRecipe() forces the border to 'none' for anything in this list, so a
     * hand-edited URL cannot reintroduce the double either.
     */
    'groundsWithOwnBorder' => ['linen', 'jovious', 'flame', 'sunburst', 'map'],

    'grounds' => [
        'parchment'   => 'common/parchment.jpg',
        'linen'       => 'garber/linen.jpg',
        'jovious'     => 'jovious/bg.jpg',
        'flame'       => 'flame/bg.jpg',
        'starfield'   => 'zodiac/bg_generic.jpg',
        'starfield2'  => 'zodiac/bg_rank.jpg',
        'cyanotype'   => 'owl/cyanotype.jpg',
        'sunburst'    => 'warrior/bg_10.jpg',
        'map'         => 'smith/bg.jpg',
        'forge'       => 'smith/burn.jpg',
        'rosewash'    => 'rose/burn.jpg',
        'arcane'      => 'battle/arcane/wash.jpg',
    ],

    // 'knot' is the parametric SVG partial (_border.tpl), recoloured through
    // --primary-color / --secondary-color. The rest are raster overlays.
    'borders' => [
        // No border layer at all. Not the same as 'knot' (whose empty string means
        // "drawn by _border.tpl rather than from a file") — this draws nothing.
        // Forced for the self-bordered grounds below, and selectable on its own for a
        // plain sheet.
        'none'   => '',
        'knot'   => '',
        'crown'  => 'crown/border.png',
        'hunter' => 'hunter/border.png',
        'hydra'  => 'hydra/border.png',
        'lion'   => 'lion/border.png',
        'mask'   => 'mask/border.png',
        'owl'    => 'owl/border.jpg',
        'rose'   => 'rose/border.png',
        'smith'  => 'smith/border.png',
        'battle' => 'battle/border_symbols.png',
    ],

    'rankSets' => [
        'common' => 'common',
        'zodiac' => 'zodiac',
        'garber' => 'garber',
        'flame'  => 'flame',
    ],

    // The 16 colour pairs the artist actually chose. Free colour entry was considered
    // and rejected: every pair here was drawn deliberately, and two arbitrary colours
    // can look wrong on artwork that was not designed for them.
    'palette' => [
        'Griffon tan'    => ['hsl(35, 29%, 55%)',  'hsl(34, 72%, 78%)'],
        'Dragon green'   => ['hsl(114, 57%, 37%)', 'hsl(41, 93%, 55%)'],
        'Battle red'     => ['hsl(0, 57%, 61%)',   'hsl(30, 55%, 80%)'],
        'Crown gold'     => ['hsl(43, 74%, 49%)',  'hsl(43, 40%, 82%)'],
        'Lion purple'    => ['hsl(280, 35%, 55%)', 'hsl(43, 74%, 62%)'],
        'Hydra teal'     => ['hsl(160, 45%, 42%)', 'hsl(160, 30%, 78%)'],
        'Rose crimson'   => ['hsl(340, 55%, 48%)', 'hsl(120, 25%, 60%)'],
        'Smith steel'    => ['hsl(210, 25%, 45%)', 'hsl(210, 20%, 75%)'],
        'Owl indigo'     => ['hsl(220, 45%, 35%)', 'hsl(220, 25%, 80%)'],
        'Mask ivory'     => ['hsl(30, 20%, 30%)',  'hsl(30, 30%, 88%)'],
        'Garber blue'    => ['hsl(215, 55%, 50%)', 'hsl(215, 30%, 80%)'],
        'Jovious rose'   => ['hsl(330, 45%, 60%)', 'hsl(205, 50%, 65%)'],
        'Flame ember'    => ['hsl(15, 75%, 52%)',  'hsl(43, 85%, 60%)'],
        'Warrior violet' => ['hsl(265, 40%, 48%)', 'hsl(265, 25%, 80%)'],
        'Zodiac night'   => ['hsl(230, 45%, 28%)', 'hsl(45, 60%, 75%)'],
        'Walker slate'   => ['hsl(200, 15%, 40%)', 'hsl(200, 15%, 78%)'],
    ],
];
