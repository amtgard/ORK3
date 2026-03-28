<?php

// Bump WHATS_NEW_VERSION whenever you add new items — every logged-in user will see
// the modal once on their next page load, then not again until the version changes.
define('WHATS_NEW_VERSION', '2026-03-22');

// Application version — shown in the site footer.
define('ORK_VERSION', '3.5.0');

$WHATS_NEW_ITEMS = [
	['icon' => 'fas fa-user', 'title' => 'New Player Profiles', 'body' => 'Player profiles have a fresh new look with stats, awards, titles, attendance, and more all in one place.'],
	['icon' => 'fas fa-chess-rook', 'title' => 'New Kingdom Profiles', 'body' => 'Kingdom pages now show parks, events, tournaments, a live map, and reports in a redesigned layout.'],
	['icon' => 'fas fa-tree', 'title' => 'New Park Profiles', 'body' => 'Park pages now show your weekly schedule, upcoming events, tournaments, and reports at a glance. Taking attendance is now faster with recent sign-ins displayed for Quick Add.'],
	['icon' => 'fas fa-calendar-check', 'title' => 'Event Enhancements', 'body' => 'See all upcoming events and park days in a rollup calendar, create events with no templates required, and RSVP to events you want to attend to speed up check-in.'],
	['icon' => 'fas fa-cog', 'title' => 'Managing the ORK', 'body' => 'Updating your profile, parks, and kingdoms is easier than ever. Look for the gear and edit symbols to make changes directly on the page.'],
	// Add future items above this line; bump WHATS_NEW_VERSION to re-show for all users
];
