<?php

class Model_FrontDoor extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    // Single content seam. v1 returns hardcoded defaults; v2 (CMS) will read a store here.
    // $ctx: ['logged_in'=>bool, 'kingdom_id'=>int, ...] — reserved for future scoping.
    public function GetContent($ctx = [])
    {
        $img = HTTP_TEMPLATE . 'default/img/frontdoor/';
        $logo = ['key' => 'logo', 'src' => $img . 'amtgard-logo.png', 'alt' => 'Amtgard'];

        $blocks = [];

        $blocks[] = [
            'id' => 'nav', 'type' => 'marketing_nav', 'enabled' => true, 'order' => 10, 'source' => 'authored',
            'fields' => [
                'logo' => $logo,
                'items' => [
                    ['label' => 'Home', 'href' => '#'],
                    ['label' => 'About', 'href' => '#', 'children' => [
                        ['label' => 'Mission', 'href' => '#'], ['label' => 'Staff', 'href' => '#'], ['label' => 'Volunteers', 'href' => '#'],
                    ]],
                    ['label' => 'Join', 'href' => '#', 'children' => [
                        ['label' => 'Learn the Basics', 'href' => '#'], ['label' => 'Find a Chapter', 'href' => '#'], ['label' => 'Start a Chapter', 'href' => '#'],
                    ]],
                    ['label' => 'AI Programs', 'href' => '#', 'children' => [
                        ['label' => 'Food Fight', 'href' => '#'], ['label' => 'Olympiad', 'href' => '#'],
                    ]],
                    ['label' => 'Media', 'href' => '#', 'children' => [
                        ['label' => 'Galleries', 'href' => '#'], ['label' => 'Writing', 'href' => '#'],
                    ]],
                    ['label' => 'Official Resources', 'href' => '#', 'children' => [
                        ['label' => 'Documents', 'href' => '#'],
                    ]],
                    ['label' => 'Merch', 'href' => 'https://www.redbubble.com/people/amtgardmarket/shop'],
                ],
                'cta' => ['label' => 'Find a Chapter', 'href' => '#'],
                'login' => ['label' => 'Record Keeper', 'href' => '#'],
            ],
        ];

        $blocks[] = [
            'id' => 'member', 'type' => 'member_bar', 'enabled' => true, 'order' => 20, 'source' => 'dynamic',
            'fields' => [],
        ];

        $blocks[] = [
            'id' => 'hero', 'type' => 'hero_carousel', 'enabled' => true, 'order' => 30, 'source' => 'authored',
            'fields' => [
                'logo' => $logo,
                'autoplay_ms' => 4500,
                'slides' => [
                    ['image' => ['key' => 'hero-1', 'src' => $img . 'hero-1.jpg', 'alt' => ''], 'kicker' => 'Worldwide Medieval Combat · Since 1983', 'headline' => 'Take the Field.', 'subcopy' => 'Safe boffer weapons, real glory. Step into a living world of heroic combat, quests, and craft.'],
                    ['image' => ['key' => 'hero-2', 'src' => $img . 'hero-2.jpg', 'alt' => ''], 'kicker' => 'Archery · Magic · Steel', 'headline' => 'Find Your Path.', 'subcopy' => 'Warrior, archer, healer, monster, crafter — there\'s a place for every kind of hero.'],
                    ['image' => ['key' => 'hero-7', 'src' => $img . 'hero-7.jpg', 'alt' => ''], 'kicker' => 'From First-Timers to Great Wars', 'headline' => 'Answer the Call.', 'subcopy' => 'Hundreds of chapters worldwide. Your first day on the field is always free.'],
                ],
                'ctas' => [
                    ['label' => 'Find Amtgard Near You', 'href' => '#', 'style' => 'gold'],
                    ['label' => 'Watch & Learn', 'href' => '#', 'style' => 'ghost'],
                ],
            ],
        ];

        $blocks[] = [
            'id' => 'whatis', 'type' => 'richtext', 'enabled' => true, 'order' => 40, 'source' => 'authored',
            'fields' => [
                'kicker' => 'New here?', 'heading' => 'What is Amtgard?', 'align' => 'center',
                'body' => 'Amtgard is a world-wide organization dedicated to medieval and fantasy combat sports and recreation. We use padded weapons, fantasy and authentic clothing, and imagination to immerse players in a world of heroic combat, quests, crafts, and more.',
                'cta' => ['label' => 'The full story →', 'href' => '#'],
            ],
        ];

        $blocks[] = [
            'id' => 'paths', 'type' => 'card_grid', 'enabled' => true, 'order' => 50, 'source' => 'authored',
            'fields' => [
                'kicker' => 'There\'s a place for you', 'heading' => 'Find Your Path',
                'subheading' => 'However you like to play, Amtgard has a role for you.',
                'cards' => [
                    ['image' => ['key' => 'hero-1', 'src' => $img . 'hero-1.jpg', 'alt' => ''], 'icon' => '⚔', 'title' => 'The Warrior', 'blurb' => 'Sword, shield, and the front line', 'href' => '#'],
                    ['image' => ['key' => 'hero-2', 'src' => $img . 'hero-2.jpg', 'alt' => ''], 'icon' => '🏹', 'title' => 'The Archer', 'blurb' => 'Ranged skill and battlefield control', 'href' => '#'],
                    ['image' => ['key' => 'hero-5', 'src' => $img . 'hero-5.jpg', 'alt' => ''], 'icon' => '✨', 'title' => 'The Caster', 'blurb' => 'Spells, healing, and the magic classes', 'href' => '#'],
                    ['image' => ['key' => 'hero-6', 'src' => $img . 'hero-6.jpg', 'alt' => ''], 'icon' => '🎨', 'title' => 'The Artisan', 'blurb' => 'Garb, armor, and craft (A&S)', 'href' => '#'],
                    ['image' => ['key' => 'hero-3', 'src' => $img . 'hero-3.jpg', 'alt' => ''], 'icon' => '🐉', 'title' => 'The Monster', 'blurb' => 'Quests, role-play, and the wilds', 'href' => '#'],
                    ['image' => ['key' => 'hero-8', 'src' => $img . 'hero-8.jpg', 'alt' => ''], 'icon' => '👑', 'title' => 'The Leader', 'blurb' => 'Reeving, office, and running the realm', 'href' => '#'],
                ],
            ],
        ];

        $blocks[] = [
            'id' => 'firstday', 'type' => 'steps', 'enabled' => true, 'order' => 60, 'source' => 'authored',
            'fields' => [
                'kicker' => 'It\'s easier than you think', 'heading' => 'Your First Day', 'band' => 'dark',
                'steps' => [
                    ['n' => 1, 'title' => 'Find a chapter', 'body' => 'Hundreds of parks meet weekly in public spaces. Find one near you.'],
                    ['n' => 2, 'title' => 'Just show up', 'body' => 'No experience or gear needed. Wear comfy clothes and bring water.'],
                    ['n' => 3, 'title' => 'Borrow a sword', 'body' => 'Chapters have loaner weapons. Take the field — your first day is free.'],
                ],
                'cta' => ['label' => 'Find Amtgard Near You', 'href' => '#'],
            ],
        ];

        $blocks[] = [
            'id' => 'events', 'type' => 'events_feed', 'enabled' => true, 'order' => 70, 'source' => 'dynamic',
            'fields' => ['kicker' => 'Come check one out', 'heading' => 'Upcoming Events', 'limit' => 3, 'more_href' => UIR . 'Search/event'],
        ];

        $blocks[] = [
            'id' => 'mosaic', 'type' => 'photo_mosaic', 'enabled' => true, 'order' => 80, 'source' => 'authored',
            'fields' => [
                'caption' => 'This is Amtgard',
                'images' => [
                    ['key' => 'hero-7', 'src' => $img . 'hero-7.jpg', 'alt' => ''],
                    ['key' => 'hero-4', 'src' => $img . 'hero-4.jpg', 'alt' => ''],
                    ['key' => 'hero-6', 'src' => $img . 'hero-6.jpg', 'alt' => ''],
                    ['key' => 'hero-3', 'src' => $img . 'hero-3.jpg', 'alt' => ''],
                ],
            ],
        ];

        $blocks[] = [
            'id' => 'kingdoms', 'type' => 'kingdoms_teaser', 'enabled' => true, 'order' => 90, 'source' => 'dynamic',
            'fields' => ['kicker' => 'Explore the realm', 'heading' => 'Kingdoms Around the World', 'limit' => 12, 'more_href' => UIR . 'Directory/index'],
        ];

        $blocks[] = [
            'id' => 'getinvolved', 'type' => 'cta_band', 'enabled' => true, 'order' => 100, 'source' => 'authored',
            'fields' => [
                'logo' => $logo,
                'heading' => 'Ready to take up arms?',
                'subcopy' => 'There\'s a chapter near you, and your first day on the field is always free.',
                'ctas' => [
                    ['label' => 'Find Amtgard Near You', 'href' => '#', 'style' => 'gold'],
                    ['label' => 'Official Resources', 'href' => '#', 'style' => 'ghost'],
                ],
                'links' => 'amtgard.com · play.amtgard.com · Online Record Keeper',
            ],
        ];

        // Stable order; CMS will reorder via 'order' later.
        usort($blocks, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        return $blocks;
    }
}
