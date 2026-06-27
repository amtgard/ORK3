<?php

/**
 * Seed two staff_roster exemplar pages (content adapted from amtgard.com:
 * /teamleads and /bod) to demonstrate the new Staff Roster block. Pages are
 * created as DRAFTS (staged for review, not published). Idempotent: deletes any
 * existing non-system page with the same slug, then recreates it. Run once:
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/2026-06-27-cms-seed-staff-roster.php
 *
 * Team Leads cards carry their public amtgard.com headshots + a mailto: link.
 * Board of Directors photos are intentionally left empty (the source page's
 * image URLs could not be reliably attributed) — they render the block's
 * fallback avatar; add headshots via the media library when desired.
 */
chdir('/var/www/ork.amtgard.com/orkui');
define('DONOTWEBSERVICE', true);
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}
ob_start();
require('/var/www/ork.amtgard.com/startup.php');
ob_end_clean();
if (!defined('UIR')) {
    define('UIR', '/orkui/index.php?Route=');
}

$cms = new CmsPage();
$now = date('Y-m-d H:i:s');
$by  = 1; // seed author (super-admin)

/** Build one person entry for a staff_roster block. */
$person = function ($persona, $mundane, $role, $bio = '', $photo = '', $href = '') {
    return array(
        'image'        => ($photo !== '') ? array('src' => $photo, 'alt' => ($mundane !== '' ? $mundane : $persona)) : array(),
        'persona_name' => $persona,
        'mundane_name' => $mundane,
        'role'         => $role,
        'bio'          => $bio,
        'mundane_id'   => 0,
        'href'         => $href,
    );
};

$WIX = 'https://static.wixstatic.com/media/';

$pages = array();

/* ---------------- Board of Directors ---------------- */
$pages[] = array(
    'page' => array(
        'slug' => 'board-of-directors', 'type' => 'about', 'title' => 'Board of Directors',
        'meta_description' => 'The Amtgard International Board of Directors — the elected and appointed members who govern the organization.',
    ),
    'blocks' => array(
        array('type' => 'staff_roster', 'fields' => array(
            'kicker' => 'Amtgard International', 'heading' => 'Board of Directors',
            'subheading' => 'The members who govern and steward the organization.',
            'presentation' => 'mundane',
            'people' => array(
                $person('', 'Lisa Belkie', 'President'),
                $person('', 'Jennifer Stewart', 'Treasurer', 'With Amtgard since 2016 as a resident of Thorn Mountain. Has held club officer positions and spent over a decade in animal services.'),
                $person('', 'Shannon Cartwright', 'Secretary', 'Active since 2020, typically serving as Prime Minister in Desert Winds. Her passion lies in helping make everything run smoother for the next leadership teams.'),
                $person('', 'Sonya Duran', 'Board Member', 'Started in 2016 and has autocratted multiple events. Earned Knight of the Flame in 2023 for her volunteer work.'),
                $person('', 'Amber Hodges', 'Board Member, Communications', '22+ years with Amtgard, serving as park monarchy and Ombudsman. Volunteers with crisis intervention outside the organization.'),
                $person('', 'Jason Bonette', 'Executive Committee Chair', 'Part of Amtgard since 1992, playing in the Wetlands and the Celestial Kingdom. Executive Chair since late 2019.'),
                $person('', 'Vidalia Darby', 'Executive Committee Secretary', 'Manages Executive Committee records and proceedings, bringing quiet precision and battlefield focus.'),
                $person('Sir Ried', 'Dale Churchill', 'Executive Committee Seat', 'Active since 2007, holding numerous leadership positions including Monarch and Champion. Knighted for his service.'),
                $person('', 'J.P. Prentiss', 'General Counsel'),
            ),
        )),
    ),
);

/* ---------------- Team Leads ---------------- */
$pages[] = array(
    'page' => array(
        'slug' => 'team-leads', 'type' => 'about', 'title' => 'Team Leads',
        'meta_description' => 'Amtgard International team leads — the volunteers who keep the organization running day to day.',
    ),
    'blocks' => array(
        array('type' => 'staff_roster', 'fields' => array(
            'kicker' => 'Amtgard International', 'heading' => 'Team Leads',
            'subheading' => 'The volunteers who keep Amtgard running day to day. Reach out any time.',
            'presentation' => 'mundane',
            'people' => array(
                $person('', 'Jennifer Palmer', 'Executive Director', '', $WIX . 'd843b0_78c77fcb7bc14c3abeea1bb9446a619a~mv2.jpg', 'mailto:generalmanager@amtgard.com'),
                $person('', 'Robin Keirnan', 'Volunteer Engagement', '', '', 'mailto:peopleops@amtgard.com'),
                $person('', 'Pix Wright', 'Process Strategy', '', $WIX . '927b50_d073e7b26edc4d5a82d6681f6d76bca4~mv2.png', 'mailto:pwright@amtgard.com'),
                $person('', 'Eric Lloyd', 'Data Science', '', $WIX . 'd843b0_3e766aef201e4fd68b11c5302cbe8bc3~mv2.jpg', 'mailto:elloyd@amtgard.com'),
                $person('', 'David Syas', 'Member Services', '', $WIX . 'd843b0_00baca21253742df930cf71dcccd608f~mv2.png', 'mailto:memberservicesad@amtgard.com'),
                $person('', 'Ken Walker', 'Technical Resources', '', $WIX . 'd843b0_51c7be0d7a0b4da7b60017615a132e4d~mv2.jpg', 'mailto:technicalad@amtgard.com'),
                $person('', 'Dusty Marshall', 'Engagement', '', $WIX . 'd843b0_a2d050ea4f864000b262f66e7b1c0790~mv2.jpg', 'mailto:engagementadsr@amtgard.com'),
                $person('', 'Madison Chapel', 'Engagement', '', $WIX . '927b50_7b2733ba737c4d2aa80e04d3406db419~mv2.jpg', 'mailto:engagementad@amtgard.com'),
            ),
        )),
    ),
);

// Insert (as drafts).
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
        'status' => 'draft', 'published_at' => null, 'scope_type' => 'global', 'scope_id' => 0,
        'is_system' => 0, 'created_by' => $by, 'created_at' => $now, 'updated_by' => $by, 'updated_at' => $now,
    ));
    $pid = (int) $cms->CreatePage($data);
    if ($pid <= 0) {
        $report[] = "$slug: FAILED to create";
        continue;
    }
    $blocks = array();
    $i = 0;
    foreach ($def['blocks'] as $b) {
        $blocks[] = array(
            'type' => $b['type'], 'enabled' => 1, 'order' => $i++,
            'source' => 'authored', 'fields' => $b['fields'],
        );
    }
    $n = $cms->ReplaceBlocks('page', $pid, $blocks);
    $report[] = "$slug: page_id=$pid, blocks=$n, status=draft";
}
echo implode("\n", $report) . "\n";
