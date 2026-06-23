<?php

/**
 * Seed exemplar CMS pages (content adapted from amtgard.com) to demonstrate the
 * CMS block system. Idempotent: deletes any existing non-system page with the
 * same slug, then recreates it published at global scope. Run once:
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/2026-06-23-cms-seed-exemplars.php
 */
chdir('/var/www/ork.amtgard.com/orkui');
define('DONOTWEBSERVICE', true);
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}
ob_start();
require('/var/www/ork.amtgard.com/startup.php');
ob_end_clean();
// UIR is defined by orkui/index.php (web), not the CLI bootstrap — define a
// host-agnostic relative form so stored internal links resolve in the browser.
if (!defined('UIR')) {
    define('UIR', '/orkui/index.php?Route=');
}

$cms  = new CmsPage();
$now  = date('Y-m-d H:i:s');
$by   = 1; // seed author (super-admin)
// Host-agnostic relative path (HTTP_TEMPLATE is host-dependent and empty in CLI).
$IMG  = '/orkui/template/default/img/frontdoor/';
$clean = function ($html) {
    return class_exists('CmsSanitizer') ? CmsSanitizer::Clean($html) : $html;
};
$img = function ($n, $alt = '') use ($IMG) {
    return array('key' => 'hero-' . $n, 'src' => $IMG . 'hero-' . $n . '.jpg', 'alt' => $alt);
};

// Build the four exemplar page definitions: [page-meta, blocks[]].
$pages = array();

/* ---------------- /about ---------------- */
$pages[] = array(
    'page' => array('slug' => 'about', 'type' => 'composed', 'title' => 'About Amtgard',
        'meta_description' => 'Amtgard is a worldwide medieval & fantasy combat sport and LARP — padded weapons, arts & sciences, and community since 1983.'),
    'blocks' => array(
        array('type' => 'hero_carousel', 'fields' => array(
            'autoplay_ms' => 5000,
            'slides' => array(array('image' => $img(3, 'Amtgard combat'),
                'kicker' => 'Worldwide Medieval Combat · Since 1983',
                'headline' => 'About Amtgard',
                'subcopy' => 'A world of heroic combat, quests, and craft — open to everyone.')),
            'ctas' => array(array('label' => 'Find a Chapter', 'href' => 'https://play.amtgard.com', 'style' => 'gold')),
        )),
        array('type' => 'rich_text', 'fields' => array(
            'kicker' => 'Our Mission', 'heading' => 'What is Amtgard?', 'align' => 'center',
            'body' => $clean('<p>Amtgard is a world-wide organization dedicated to medieval and fantasy combat sports and recreation. We use padded weapons, fantasy and authentic clothing, and imagination to immerse players in a world of heroic combat, quests, crafts, and more.</p><p>At its core, Amtgard is a Live Action Roleplaying (LARP) and boffer combat society focused on the Sword &amp; Sorcery, Medieval, and Ancient genres. We use safe, foam-padded replicas of medieval weaponry to bring the tabletop and video-game worlds many of us love to life. <strong>In Amtgard, you don\'t just say what you want to do — you do it.</strong></p>'),
        )),
        array('type' => 'card_grid', 'fields' => array(
            'kicker' => 'What we do', 'heading' => 'Three Pillars', 'subheading' => 'However you like to play, there is a place for you on the field.',
            'cards' => array(
                array('image' => $img(1), 'icon' => 'fa-shield-alt', 'title' => 'Combat', 'blurb' => 'Safe boffer fighting, from one-on-one tournaments to massive wars.', 'href' => UIR . 'Page/view/join'),
                array('image' => $img(6), 'icon' => 'fa-palette', 'title' => 'Arts & Sciences', 'blurb' => 'Garb, armor, leatherwork, and craft — build the world you fight in.', 'href' => UIR . 'Page/view/join'),
                array('image' => $img(7), 'icon' => 'fa-users', 'title' => 'Community', 'blurb' => 'Hundreds of chapters worldwide, meeting weekly in public parks.', 'href' => UIR . 'Page/view/join'),
            ),
        )),
        array('type' => 'quote', 'fields' => array(
            'text' => 'All people — regardless of gender, nationality, race, ethnicity, age, sexual orientation, mental or physical ability, or religious affiliation — should find Amtgard to be welcoming and inclusive.',
            'cite' => 'Amtgard leadership',
        )),
        array('type' => 'cta_band', 'fields' => array(
            'logo' => array('key' => 'logo', 'src' => $IMG . 'amtgard-logo.png', 'alt' => 'Amtgard'),
            'heading' => 'Ready to take the field?', 'subcopy' => 'There is a chapter near you, and your first day is always free.',
            'ctas' => array(
                array('label' => 'Find a Chapter', 'href' => 'https://play.amtgard.com', 'style' => 'gold'),
                array('label' => 'Get Started', 'href' => UIR . 'Page/view/join', 'style' => 'ghost'),
            ),
            'links' => 'amtgard.com · play.amtgard.com · Online Record Keeper',
        )),
    ),
);

/* ---------------- /join ---------------- */
$pages[] = array(
    'page' => array('slug' => 'join', 'type' => 'composed', 'title' => 'Get Started',
        'meta_description' => 'New to Amtgard? Find a chapter, show up, and borrow a sword — your first day on the field is free.'),
    'blocks' => array(
        array('type' => 'hero_carousel', 'fields' => array(
            'autoplay_ms' => 5000,
            'slides' => array(array('image' => $img(4, 'Amtgard newcomers'),
                'kicker' => 'It\'s easier than you think',
                'headline' => 'Get Started',
                'subcopy' => 'No experience or gear required. Your first day on the field is always free.')),
            'ctas' => array(array('label' => 'Find Amtgard Near You', 'href' => 'https://play.amtgard.com', 'style' => 'gold')),
        )),
        array('type' => 'steps', 'fields' => array(
            'kicker' => 'It\'s easier than you think', 'heading' => 'Your First Day', 'band' => 'dark',
            'steps' => array(
                array('n' => 1, 'title' => 'Find a chapter', 'body' => 'Hundreds of parks meet weekly in public spaces. Use the Atlas to find one near you.'),
                array('n' => 2, 'title' => 'Just show up', 'body' => 'No experience or gear needed — wear comfy clothes and bring water.'),
                array('n' => 3, 'title' => 'Borrow a sword', 'body' => 'Chapters keep loaner weapons. Take the field — your first day is free.'),
            ),
            'cta' => array('label' => 'Find Amtgard Near You', 'href' => 'https://play.amtgard.com'),
        )),
        array('type' => 'card_grid', 'fields' => array(
            'kicker' => 'There\'s a place for you', 'heading' => 'Find Your Path', 'subheading' => 'However you like to play, Amtgard has a role for you.',
            'cards' => array(
                array('image' => $img(1), 'icon' => 'fa-shield-alt', 'title' => 'The Warrior', 'blurb' => 'Sword, shield, and the front line.', 'href' => '#'),
                array('image' => $img(2), 'icon' => 'fa-bullseye', 'title' => 'The Archer', 'blurb' => 'Ranged skill and battlefield control.', 'href' => '#'),
                array('image' => $img(5), 'icon' => 'fa-hat-wizard', 'title' => 'The Caster', 'blurb' => 'Spells, healing, and the magic classes.', 'href' => '#'),
                array('image' => $img(6), 'icon' => 'fa-palette', 'title' => 'The Artisan', 'blurb' => 'Garb, armor, and craft (A&S).', 'href' => '#'),
                array('image' => $img(3), 'icon' => 'fa-dragon', 'title' => 'The Monster', 'blurb' => 'Quests, role-play, and the wilds.', 'href' => '#'),
                array('image' => $img(8), 'icon' => 'fa-crown', 'title' => 'The Leader', 'blurb' => 'Reeving, office, and running the realm.', 'href' => '#'),
            ),
        )),
        array('type' => 'cta_band', 'fields' => array(
            'heading' => 'See you on the field.', 'subcopy' => 'Find your local chapter and come for a free first day.',
            'ctas' => array(array('label' => 'Find a Chapter', 'href' => 'https://play.amtgard.com', 'style' => 'gold')),
            'links' => 'Questions? See the FAQ.',
        )),
    ),
);

/* ---------------- /faq ---------------- */
$pages[] = array(
    'page' => array('slug' => 'faq', 'type' => 'article', 'title' => 'Frequently Asked Questions',
        'meta_description' => 'Common questions about Amtgard: is it safe, what to wear, cost, age, and how to start.'),
    'blocks' => array(
        array('type' => 'heading', 'fields' => array('text' => 'Frequently Asked Questions', 'level' => 2, 'align' => 'center')),
        array('type' => 'rich_text', 'fields' => array(
            'align' => 'center',
            'body' => $clean('<p>New to Amtgard? Here are the questions newcomers ask most. Still curious? Visit a chapter — everyone there was new once.</p>'),
        )),
        array('type' => 'accordion', 'fields' => array('items' => array(
            array('q' => 'Is it safe?', 'a' => 'Yes. Amtgard uses foam-padded "boffer" replicas of medieval weapons, and every game is overseen by trained reeves who enforce the rules and safety checks.'),
            array('q' => 'What should I wear my first time?', 'a' => 'Comfortable clothes you can move in, closed-toe shoes, and water. No costume required — many players start in gym clothes and add garb over time.'),
            array('q' => 'How much does it cost?', 'a' => 'Your first day is free, and chapters keep loaner weapons so you can try the field at no cost. Membership dues, when you decide to join, are modest.'),
            array('q' => 'Do I have to make a costume?', 'a' => 'Not at all. "Garb" (medieval/fantasy clothing) is part of the fun and the arts-and-sciences side of the hobby, but it is never required to play.'),
            array('q' => 'How old do you have to be?', 'a' => 'Players of many ages take the field; minors typically need a parent or guardian present or to sign a waiver. Ask your local chapter for specifics.'),
            array('q' => 'Is Amtgard inclusive?', 'a' => 'Yes. Amtgard strives to be welcoming and inclusive to all people regardless of gender, nationality, race, ethnicity, age, sexual orientation, ability, or religious affiliation.'),
        ))),
        array('type' => 'cta_band', 'fields' => array(
            'heading' => 'Still have questions?', 'subcopy' => 'The best answer is a visit. Find a chapter and come say hello.',
            'ctas' => array(array('label' => 'Find a Chapter', 'href' => 'https://play.amtgard.com', 'style' => 'gold')),
        )),
    ),
);

/* ---------------- /media-gallery ---------------- */
$pages[] = array(
    'page' => array('slug' => 'media-gallery', 'type' => 'media', 'title' => 'Media Gallery',
        'meta_description' => 'Photos from the field — Amtgard combat, archery, and community.'),
    'blocks' => array(
        array('type' => 'heading', 'fields' => array('text' => 'The Look of Amtgard', 'level' => 2, 'align' => 'center')),
        array('type' => 'rich_text', 'fields' => array('align' => 'center',
            'body' => $clean('<p>A glimpse of the field — boffer combat, archery, craft, and community from chapters around the world.</p>'))),
        array('type' => 'gallery', 'fields' => array('columns' => 3, 'caption' => 'On the field',
            'images' => array($img(1, 'Combat'), $img(2, 'Archery'), $img(3, 'Sword fight'), $img(6, 'Players'), $img(7, 'A war'), $img(8, 'Archer')))),
        array('type' => 'divider', 'fields' => array('style' => 'line')),
        array('type' => 'photo_mosaic', 'fields' => array('caption' => 'This is Amtgard',
            'images' => array($img(7), $img(4), $img(5), $img(3)))),
    ),
);

// Insert.
$report = array();
foreach ($pages as $def) {
    $slug = $def['page']['slug'];
    $existing = $cms->GetPageBySlug($slug, 'global', 0, false);
    if (!empty($existing) && empty($existing['is_system'])) {
        $cms->DeletePage((int) $existing['page_id']); // fresh re-seed
    } elseif (!empty($existing) && !empty($existing['is_system'])) {
        $report[] = "$slug: SKIPPED (system page)";
        continue;
    }
    $data = array_merge($def['page'], array(
        'status' => 'published', 'published_at' => $now, 'scope_type' => 'global', 'scope_id' => 0,
        'is_system' => 0, 'created_by' => $by, 'created_at' => $now, 'updated_by' => $by, 'updated_at' => $now,
    ));
    $pid = (int) $cms->CreatePage($data);
    if ($pid <= 0) {
        $report[] = "$slug: FAILED to create";
        continue;
    }
    // ensure published (in case CreatePage defaults to draft)
    if (method_exists($cms, 'SetStatus')) {
        $cms->SetStatus($pid, 'published', $by);
    }
    $blocks = array();
    $i = 0;
    foreach ($def['blocks'] as $b) {
        $blocks[] = array('type' => $b['type'], 'enabled' => 1, 'order' => $i++,
            'source' => in_array($b['type'], array('member_bar', 'events_feed', 'kingdoms_teaser', 'blog_feed'), true) ? 'dynamic' : 'authored',
            'fields' => $b['fields']);
    }
    $n = $cms->ReplaceBlocks('page', $pid, $blocks);
    $report[] = "$slug: page_id=$pid, blocks=$n";
}
echo implode("\n", $report) . "\n";
