<?php

// Bump WHATS_NEW_VERSION whenever you add new items — every logged-in user will see
// the modal once on their next page load, then not again until the version changes.
define('WHATS_NEW_VERSION', '2026-04-18');

// Application version — shown in the site footer. Change this if you change the above date.
define('ORK_VERSION', '3.5.1 Owl');

// An array of releases, each with a version, date, and array of items. Each item has an icon (Font Awesome class), title, and body. Make sure the latest
// version matches the ORK_VERSION above, and that the date is in YYYY-MM-DD format and matches the WHATS_NEW_VERSION above.
$WHATS_NEW_ITEMS = [
	['version' => '3.5.1 Owl', 'date' => '2026-04-18', 'items' => [
		['icon' => 'fas fa-moon', 'title' => 'Dark Mode', 'body' => 'The ORK now supports dark mode! The theme toggle has three modes — click to cycle: Auto (half-circle icon — follows your OS setting) → Dark (moon) → Light (sun). Your preference is saved automatically.'],
		['icon' => 'fas fa-desktop', 'title' => 'Follows Your System', 'body' => 'If you leave the theme set to automatic, the ORK will follow your device\'s light or dark preference and update instantly when it changes.'],
		['icon' => 'fas fa-paint-brush', 'title' => 'Full Coverage', 'body' => 'Dark mode is supported across player, kingdom, and park profiles, as well as the navigation, modals, tables, calendars, and all other site components.'],
		['icon' => 'fas fa-magic', 'title' => 'Quality of Life Updates', 'body' => 'Several quality of life updates to award entry and Event RSVPs — see the release notes for details.'],
		['icon' => 'fas fa-trophy', 'title' => 'Smarter Award Entry', 'notes_only' => true, 'body' => 'Granting awards just got tidier. The Player, Kingdom, and Park award modals now have dedicated buttons for Achievement Titles (Knighthoods, Masterhoods, Paragons, Noble Titles) and Associations (Associate Titles) — no more scrolling through a giant list. Pick the bucket, pick the award, done.'],
		['icon' => 'fas fa-list-ol', 'title' => 'Ladder Tally Improvement', 'notes_only' => true, 'body' => 'If a player had duplicate ranked entries (say, two Crown 3s), we now only count one of those toward the "count of awards or highest databased rank, whichever is higher" calculation.'],
		['icon' => 'fas fa-clipboard-check', 'title' => 'Event RSVP Tab — Now With Superpowers', 'notes_only' => true, 'body' => 'New Sign-in Credits field right in the RSVP table so you can set credits before the event even starts (it syncs with quick check-in too). Kingdom and Park are now their own clickable columns, and a brand new "Waivered?" column shows at a glance who\'s got a waiver on file — be sure to check your kingdom/park/event policies on active waivers vs event-specific waivers being needed.'],
		['icon' => 'fas fa-spell-check', 'title' => 'Typo-Catching Email Field', 'notes_only' => true, 'body' => 'Type gmial.com by mistake? The system now gently asks "Did you mean gmail.com?" with a one-click fix. Works on the Add Player popup and your Edit Account screen.'],
		['icon' => 'fas fa-at', 'title' => 'Email Reminder for Players Without One', 'notes_only' => true, 'body' => 'If you log in and don\'t have a recovery email on file, you\'ll get a friendly nudge to add one. (Don\'t want to deal with it right now? "Skip for now" and take care of it next time.)'],
	]],
	['version' => '3.5.0 Dragon', 'date' => '2026-04-02', 'items' => [
		['icon' => 'fas fa-user', 'title' => 'New Player Profiles', 'body' => 'Player profiles have a fresh new look with stats, awards, titles, attendance, and more all in one place.'],
		['icon' => 'fas fa-chess-rook', 'title' => 'New Kingdom Profiles', 'body' => 'Kingdom pages now show parks, events, tournaments, a live map, and reports in a redesigned layout.'],
		['icon' => 'fas fa-tree', 'title' => 'New Park Profiles', 'body' => 'Park pages now show your weekly schedule, upcoming events, tournaments, and reports at a glance. Taking attendance is now faster with recent sign-ins displayed for Quick Add.'],
		['icon' => 'fas fa-calendar-check', 'title' => 'Event Enhancements', 'body' => 'See all upcoming events and park days in a rollup calendar, create events with no templates required, and RSVP to events you want to attend to speed up check-in.'],
		['icon' => 'fas fa-cog', 'title' => 'Managing the ORK', 'body' => 'Updating your profile, parks, and kingdoms is easier than ever. Look for the gear and edit symbols to make changes directly on the page.'],
		['icon' => 'fas fa-gift', 'title' => 'And so much more!', 'body' => 'So many improvements, bug fixes, and quality-of-life changes across the entire ORK with this first of many upcoming updates.']
	]]
	// Add future items above this line; bump WHATS_NEW_VERSION date to re-show for all users. Change ORK_VERSION when you want to update the version shown in the footer.
];
