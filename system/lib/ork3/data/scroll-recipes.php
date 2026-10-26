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

    /*
     * The hands — a per-PRINT typeface choice, not part of the composed artwork.
     *
     * Unlike everything above it, a hand changes nothing about what the award IS:
     * the same certificate is legitimately printed in a different hand for a
     * different court. So it travels as a page parameter (&face=) rather than as a
     * recipe key, and a reprint URL that omits it draws exactly what it drew before.
     *
     * It lives HERE anyway, because four surfaces now need the same list — the
     * render/compose page, the composer, `mine` and `bulk` — and Scrollery_render.tpl
     * used to hold the only copy. That was fine while it was the only surface with a
     * picker and became the reason there was only one picker.
     *
     * `label` is what the officer reads, and it is the FONT'S OWN NAME plus "Scroll
     * Font" rather than a description of it. The picker renders each option in the
     * face it names (see `family` below), so the label is not a caption — it is the
     * specimen. "Cinzel Scroll Font" set in Cinzel says what "Roman capitals, no
     * lowercase" was trying to say, and says it in the letterforms themselves, which
     * is the only description of a typeface that cannot be wrong. The words are
     * chosen to be long enough to show ascenders, descenders, round bowls and a
     * capital or two; a bare "Cinzel" is four letters and shows almost nothing.
     *
     * `family` is the CSS font-family the option is drawn in, and it must match the
     * --sc-display-face that scrollery.css sets for the same key — a picker that
     * previews one face and prints another is worse than one that previews nothing.
     * ScrollRecipeTest asserts the two agree.
     *
     * `fonts` is the <link rel=preload> manifest, and it is per-hand rather than a
     * union on purpose. Every face here is font-display:block, which paints NOTHING
     * where the text goes until it lands, and the print gate waits on
     * document.fonts.ready — so preloading a face the page will not paint spends
     * bandwidth on the critical path of a print job. Averia's upright Bold is absent
     * for the same reason: .Award .Body is italic, so every bold thing on the
     * certificate resolves to BoldItalic.
     *
     * The faces themselves are declared in style/scrollery.css section 1a, and the
     * key selects them through [data-scroll-face] in section 1a-ii. Adding a hand
     * means a block there and an entry here; nothing else reads the list.
     */
    'hands' => [
        'chancery' => [
            'label'  => 'Black Chancery Scroll Font',
            'family' => 'BlackChancery',
            'file'   => 'BlackChancery.woff2',
            'fonts' => [
                'BlackChancery.woff2',
                'AveriaSerifLibre-Regular.woff2',
                'AveriaSerifLibre-Italic.woff2',
                'AveriaSerifLibre-BoldItalic.woff2',
            ],
        ],
        'sharp' => [
            'label'  => 'Medieval Sharp Scroll Font',
            'family' => 'MedievalSharp',
            'file'   => 'MedievalSharp-Regular.woff2',
            'fonts' => [
                'MedievalSharp-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'pirata' => [
            'label'  => 'Pirata Scroll Font',
            'family' => 'Pirata One',
            'file'   => 'PirataOne-Regular.woff2',
            'fonts' => [
                'PirataOne-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'cinzel' => [
            'label'  => 'Cinzel Scroll Font',
            'family' => 'Cinzel',
            'file'   => 'Cinzel-Regular.woff2',
            'fonts' => [
                'Cinzel-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],

        /*
         * Six more, all display faces over the same Grenze companion — see
         * scrollery.css section 1a-iii for the coverage table and why the companion
         * is shared rather than one text face per hand.
         *
         * Ordered as a picker should read rather than alphabetically: the four
         * period hands an officer is most likely to want first, then the two that
         * are a deliberate departure.
         */
        'gotisch' => [
            'label'  => 'Grenze Gotisch Scroll Font',
            'family' => 'Grenze Gotisch',
            'file'   => 'GrenzeGotisch-Regular.woff2',
            'fonts' => [
                'GrenzeGotisch-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'uncial' => [
            'label'  => 'Uncial Antiqua Scroll Font',
            'family' => 'Uncial Antiqua',
            'file'   => 'UncialAntiqua-Regular.woff2',
            'fonts' => [
                'UncialAntiqua-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'fondamento' => [
            'label'  => 'Fondamento Scroll Font',
            'family' => 'Fondamento',
            'file'   => 'Fondamento-Regular.woff2',
            'fonts' => [
                'Fondamento-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'garamond' => [
            'label'  => 'EB Garamond Scroll Font',
            'family' => 'EB Garamond',
            'file'   => 'EBGaramond-Regular.woff2',
            'fonts' => [
                'EBGaramond-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'unicase' => [
            'label'  => 'Cormorant Unicase Scroll Font',
            'family' => 'Cormorant Unicase',
            'file'   => 'CormorantUnicase-Regular.woff2',
            'fonts' => [
                'CormorantUnicase-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
        'metamorphous' => [
            'label'  => 'Metamorphous Scroll Font',
            'family' => 'Metamorphous',
            'file'   => 'Metamorphous-Regular.woff2',
            'fonts' => [
                'Metamorphous-Regular.woff2',
                'Grenze-Regular.woff2',
                'Grenze-Italic.woff2',
                'Grenze-SemiBoldItalic.woff2',
            ],
        ],
    ],

    /*
     * The signature hands — a per-PRINT choice of typeface for the given-by line.
     *
     * Same idea as 'hands' above, and built the same way so it behaves the same on every
     * surface: a key that travels in &sig= and [data-scroll-sig], a `label` the officer
     * reads (here the face's own name, drawn in that face by the picker), the `family`
     * the picker draws it in, the woff2 `file`, and the `fonts` preload manifest. It is
     * not stored on the award; each officer's choice is remembered per browser ('sc-sig').
     *
     * `hand` is the default and is TODAY'S BEHAVIOUR: the given-by line stays in the
     * certificate's own text face, with no rule applied. It has no family and no file,
     * and is the only entry that may lack them, so a certificate nobody has chosen a
     * signature for prints exactly as it always did.
     *
     * `scale` is how large the face is set against the lettering it replaces, as a
     * multiple of the shared x-height target in scrollery-signature.css. Script faces
     * have small x-heights and enormous swashes, so one font-size would print some of
     * them as a whisper and others as a shout; the scale was set by eye on a rendered
     * certificate, against the 0.7em given-by line, until a short and a long name read
     * as comparably sized in every face.
     *
     * The faces are declared in style/scrollery-signature.css and selected by
     * [data-scroll-sig="<key>"] there; ScrollRecipeTest keeps the three in agreement.
     * All eight are SIL OFL 1.1 from Google Fonts — see style/fonts/SIGNATURE-FONTS.md.
     */
    'signatureHands' => [
        'hand' => [
            'label'  => 'Match the lettering',
            'family' => '',
            'file'   => '',
            'scale'  => 1.0,
            'fonts'  => [],
        ],
        'greatvibes' => [
            'label'  => 'Great Vibes',
            'family' => 'Great Vibes',
            'file'   => 'GreatVibes-Regular.woff2',
            'scale'  => 1.10,
            'fonts'  => ['GreatVibes-Regular.woff2'],
        ],
        'pinyon' => [
            'label'  => 'Pinyon Script',
            'family' => 'Pinyon Script',
            'file'   => 'PinyonScript-Regular.woff2',
            'scale'  => 1.10,
            'fonts'  => ['PinyonScript-Regular.woff2'],
        ],
        'alexbrush' => [
            'label'  => 'Alex Brush',
            'family' => 'Alex Brush',
            'file'   => 'AlexBrush-Regular.woff2',
            'scale'  => 1.10,
            'fonts'  => ['AlexBrush-Regular.woff2'],
        ],
        'allura' => [
            'label'  => 'Allura',
            'family' => 'Allura',
            'file'   => 'Allura-Regular.woff2',
            'scale'  => 1.15,
            'fonts'  => ['Allura-Regular.woff2'],
        ],
        'saintdelafield' => [
            'label'  => 'Mrs Saint Delafield',
            'family' => 'Mrs Saint Delafield',
            'file'   => 'MrsSaintDelafield-Regular.woff2',
            'scale'  => 1.35,
            'fonts'  => ['MrsSaintDelafield-Regular.woff2'],
        ],
        'muellerhoff' => [
            'label'  => 'Herr Von Muellerhoff',
            'family' => 'Herr Von Muellerhoff',
            'file'   => 'HerrVonMuellerhoff-Regular.woff2',
            'scale'  => 1.30,
            'fonts'  => ['HerrVonMuellerhoff-Regular.woff2'],
        ],
        'doulaise' => [
            'label'  => 'Monsieur La Doulaise',
            'family' => 'Monsieur La Doulaise',
            'file'   => 'MonsieurLaDoulaise-Regular.woff2',
            'scale'  => 1.20,
            'fonts'  => ['MonsieurLaDoulaise-Regular.woff2'],
        ],
        'cedarville' => [
            'label'  => 'Cedarville Cursive',
            'family' => 'Cedarville Cursive',
            'file'   => 'CedarvilleCursive-Regular.woff2',
            'scale'  => 1.0,
            'fonts'  => ['CedarvilleCursive-Regular.woff2'],
        ],
    ],
];
