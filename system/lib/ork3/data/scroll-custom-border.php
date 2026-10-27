<?php

/**
 * Custom Border compatibility: which orders can have their border replaced by the
 * Jovious knot in two chosen colours, and what that replacement has to remove.
 * Read through Scroll::CustomBorderRules() / CustomBorderRule(); the schema is
 * documented there. An order ABSENT from this file is incompatible — flame (a painted
 * flame frame), garber and zodiac (the border is part of the ground/numeral art).
 *
 * Every `passes` string is copied VERBATIM, in draw order, from the order's template
 * in orkui/template/revised-frontend/scroll/. Match-style mode re-uses them so the
 * knot is blended into the paper exactly the way the artist blended the art it replaces.
 * Every path is relative to assets/scrolls/.
 *
 * The _border.tpl orders (battle, custom, dragon, griffon) record the partial's outer
 * layer style: 'mix-blend-mode:multiply;padding:1px;margin:-1px;' plus the extra style
 * the template passes, one entry per scroll_partial('border') call.
 */

$partial = 'mix-blend-mode:multiply;padding:1px;margin:-1px;';

return [
    'crown'   => ['passes' => ['mix-blend-mode:multiply;', 'opacity:0.5;']],
    'hunter'  => ['passes' => ['mix-blend-mode:multiply;', 'mix-blend-mode:normal;opacity:0.7;']],
    'hydra'   => ['passes' => ['mix-blend-mode:multiply;', 'mix-blend-mode:soft-light;opacity:0.5;']],
    'lion'    => ['passes' => ['mix-blend-mode:multiply;', 'mix-blend-mode:soft-light;opacity:0.3;']],
    'mask'    => ['passes' => ['mix-blend-mode:screen;opacity:0.6;', 'mix-blend-mode:multiply;opacity:1;']],
    'rose'    => ['passes' => ['mix-blend-mode:multiply;', 'opacity:0.85;']],
    'smith'   => ['passes' => ['mix-blend-mode:multiply;', 'opacity:0.6;']],

    // owl.tpl draws the same plate 2 (parchment) / 1 (cyanotype) / 3 (transparency)
    // times with one $blend: the cyanotype branch, or the light branch for the other two.
    // Built from the constants so the three cannot drift from owl's own pair.
    'owl'     => ['passes' => [
        'parchment'    => [Scroll::BORDER_STYLES['owl']['light'], Scroll::BORDER_STYLES['owl']['light']],
        'cyanotype'    => [Scroll::BORDER_STYLES['owl']['dark']],
        'transparency' => array_fill(0, 3, Scroll::BORDER_STYLES['owl']['light']),
    ]],

    // Ranks 1-9 draw warrior/border_N.jpg; rank 10 draws none (its frame is painted
    // into bg_10.jpg), so there the ground is swapped for the frameless one.
    // Ranks 1-8 also carry their rank animal inside border_N.jpg; warrior.tpl brings it
    // back above the knot, clipped to the paper in the frame's bottom gap. Rank 1's
    // snake is coiled THROUGH an unbroken bottom band, so no clip keeps the snake
    // without keeping band too: it is incompatible until a snake cut-out exists.
    // Rank 9's dragon (symbol_9.png) stands on rocks that run the full width of the
    // page, across the frame's bottom band and up both sides, so it would sit on the
    // knot; it is rank art and cannot go, so rank 9 is incompatible too (an owner
    // question: the rocks-in-front look may be wanted).
    'warrior' => [
        'passes' => ['mix-blend-mode:multiply;'],
        'ranks'  => [1 => false, 9 => false, 10 => 'warrior/bg_10-noborder.jpg'],
    ],

    'battle'  => ['passes' => [$partial]],
    'dragon'  => ['passes' => [$partial, $partial . 'filter:blur(13px);mix-blend-mode:color-dodge;']],
    'griffon' => ['passes' => [$partial]],

    // jovious paints its knot into bg.jpg; the ground is swapped for the frameless one.
    'jovious' => [
        'passes' => ['mix-blend-mode:multiply;'],
        'ground' => 'jovious/bg-noborder.jpg',
    ],

    // custom draws the knot partial itself. Of the grounds that carry their own frame
    // (groundsWithOwnBorder), only these two have a frameless twin; linen, flame and
    // map stay unmapped, which makes them incompatible.
    // `passes` is derived from the recipe by CustomBorderRule(), not listed here.
    'custom'  => [
        'recipeGrounds' => [
            'jovious'  => 'jovious/bg-noborder.jpg',
            'sunburst' => 'warrior/bg_10-noborder.jpg',
        ],
    ],
];
