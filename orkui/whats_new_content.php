<?php

// Bump WHATS_NEW_VERSION whenever you add new items — every logged-in user will see
// the modal once on their next page load, then not again until the version changes.
define('WHATS_NEW_VERSION', '2026-04-22');

// Application version — shown in the site footer. Change this if you change the above date.
define('ORK_VERSION', '3.5.2 Mask');

// An array of releases, each with a version, date, and array of items. Each item has an icon (Font Awesome class), title, and body. Make sure the latest
// version matches the ORK_VERSION above, and that the date is in YYYY-MM-DD format and matches the WHATS_NEW_VERSION above.
$WHATS_NEW_ITEMS = [
	['version' => '3.5.2 Mask', 'date' => '2026-04-22', 'items' => [
		['icon' => 'fas fa-paint-roller', 'title' => "Tell Your Story with 'Design My Profile'", 'body' => 'The profile customizer added an About tab (markdown bio), color presets with hero gradients, a flexible name builder with comma toggle and noble-title support, beltline visibility, and pronunciation guides. Make your profile look like yours.'],
		['icon' => 'fas fa-stream', 'title' => 'My Milestones Timeline', 'body' => 'Add personal milestones — knightings, first wins, retirements, anything worth remembering — to a new timeline on your profile\'s About tab.'],
		['icon' => 'fas fa-bolt', 'title' => 'Snappier Player Profiles', 'body' => 'Profile pages now lazy-load attendance, notes, dues history, recommendations, and voting eligibility — the page paints fast and the heavier sections fill in as you click their tabs.'],
		['icon' => 'fas fa-medal', 'title' => 'Custom Title Aliases', 'body' => 'Your Custom Award can now be tagged as the peerage equivalent of a Knighthood, Squire, Page, or other title — so it counts toward beltlines and rosters the way it should.'],
		['icon' => 'fas fa-thumbs-up', 'title' => 'Second a Recommendation', 'body' => 'See an award recommendation you agree with? Hit the new + button to add your support — optionally with a few words on why. Officers reviewing recommendations now see who else stands behind each one.'],
		['icon' => 'fas fa-pen', 'title' => 'Edit Your Reason After Submitting', 'body' => 'Already filed a recommendation but learned something new about the recipient? You can now edit your own reason text from your Player profile, your Park profile, or your Kingdom profile — wherever you spotted the rec.'],
		['icon' => 'fas fa-magic', 'title' => 'And Quality of Life Updates', 'body' => 'Plenty of smaller polish items, performance improvements, and bug fixes throughout — see the release notes for the full list.'],

		// ----- Notes-only items (Release Notes page; not surfaced in the modal) -----
		['icon' => 'fas fa-shield-alt', 'title' => 'Knights Can Choose Belt Display', 'notes_only' => true, 'body' => 'Belted Knights get a new Icons tab in Design My Profile with three options: the generic white belt (default), their actual knighthood belts shown in award-date order, or no belt at all.'],
		['icon' => 'fas fa-feather', 'title' => 'Quick Add Snippets + About-tab Edit Pencil', 'notes_only' => true, 'body' => 'Drop pre-built sections into your About bio with one click, and jump straight to editing from a pencil icon on the About tab itself.'],
		['icon' => 'fas fa-font', 'title' => 'Basic & Dyslexia-Friendly Fonts', 'notes_only' => true, 'body' => 'New viewer preferences let you enforce basic fonts site-wide for legibility, or switch to Lexend — a typeface designed to improve reading proficiency for people with dyslexia.'],
		['icon' => 'fas fa-microphone', 'title' => 'Pronunciation Guide + Persona Privacy', 'notes_only' => true, 'body' => 'Tell the world how to say your persona name, and choose who can see your real name and email — defaults match the existing privacy posture (always visible to monarchy and admins).'],
		['icon' => 'fas fa-square-full', 'title' => 'Hero & Heraldry Overlay Controls', 'notes_only' => true, 'body' => 'Tune the gradient overlay strength on your hero banner and the heraldry frame — fine-grained control over how your profile reads at a glance.'],
		['icon' => 'fas fa-i-cursor', 'title' => 'Smarter Name Builder', 'notes_only' => true, 'body' => 'The name builder now includes noble titles and Master/Paragon in the dropdown, supports a comma between core name and suffix, and warns you when you pick a title you may not be entitled to (with a softer yield-triangle treatment than before).'],
		['icon' => 'fas fa-eye', 'title' => 'About-tab Visibility Banner', 'notes_only' => true, 'body' => 'A banner at the top of the About design tab makes it obvious whether what you\'re writing is private, restricted, or visible to everyone.'],
		['icon' => 'fas fa-handshake-slash', 'title' => 'Notes Tab — Visibility & Self-Service Close-out', 'notes_only' => true, 'body' => 'Player Notes are now restricted to those who should see them, with an in-context infobox and the ability to close out your own follow-ups without bothering an officer.'],
		['icon' => 'fas fa-history', 'title' => 'Reactivate Revoked Awards & Titles', 'notes_only' => true, 'body' => 'Revoked an award or title in error? You can now bring it back without re-creating the record from scratch.'],
		['icon' => 'fas fa-search', 'title' => 'Mundane Name Search on Players Tab', 'notes_only' => true, 'body' => 'Search your kingdom\'s Players tab by mundane name — with the same restricted-flag honoring as the rest of the system.'],
		['icon' => 'fab fa-discord', 'title' => 'Discord Link in Resources', 'notes_only' => true, 'body' => 'Logged-in users now get a quick link to the Amtgard ORK Discord under the Resources dropdown.'],
		['icon' => 'fas fa-map-marker-alt', 'title' => 'Park Abbreviation in Events to Check Out', 'notes_only' => true, 'body' => 'The "Events to Check Out" widget now shows park abbreviations so you can tell at a glance which kingdom each event is in.'],
		['icon' => 'fas fa-sign-in-alt', 'title' => 'Login Preserves Where You Were Going', 'notes_only' => true, 'body' => 'Get bumped to the login screen by a stale session? After signing in you\'ll land on the page you were trying to reach, not the dashboard.'],
	]],
	['version' => '3.5.1 Owl', 'date' => '2026-04-18', 'items' => [
		['icon' => 'fas fa-moon', 'title' => 'Dark Mode', 'body' => 'The ORK now supports dark mode! The theme toggle has three modes — click to cycle: Auto (half-circle icon — follows your OS setting) → Dark (moon) → Light (sun). Your preference is saved automatically.'],
		['icon' => 'fas fa-desktop', 'title' => 'Follows Your System', 'body' => 'If you leave the theme set to automatic, the ORK will follow your device\'s light or dark preference and update instantly when it changes.'],
		['icon' => 'fas fa-paint-brush', 'title' => 'Full Coverage', 'body' => 'Dark mode is supported across player, kingdom, and park profiles, as well as the navigation, modals, tables, calendars, and all other site components.'],
		['icon' => 'fas fa-magic', 'title' => 'Quality of Life Updates', 'body' => 'Several quality of life updates to award entry and Event RSVPs — see the release notes for details.'],
		['icon' => 'fas fa-trophy', 'title' => 'Smarter Award Entry', 'notes_only' => true, 'body' => 'Granting awards just got tidier. The Player, Kingdom, and Park award modals now have dedicated buttons for Achievement Titles (Knighthoods, Masterhoods, Paragons, Noble Titles) and Associations (Associate Titles) — no more scrolling through a giant list. Pick the bucket, pick the award, done.'],
		['icon' => 'fas fa-list-ol', 'title' => 'Ladder Tally Improvement', 'notes_only' => true, 'body' => 'If a player had duplicate ranked entries (say, two Crown 3s), we now only count one of those toward the "count of awards or highest databased rank, whichever is higher" calculation.'],
		['icon' => 'fas fa-clipboard-check', 'title' => 'Event RSVP Tab — Now With Superpowers', 'notes_only' => true, 'body' => 'New Sign-in Credits field right in the RSVP table so you can set credits before the event even starts (it syncs with quick check-in too). Kingdom and Park are now their own clickable columns, and a brand new "Waivered?" column shows at a glance who\'s got a waiver on file — be sure to check your kingdom/park/event policies on active waivers vs event-specific waivers being needed.'],
		['icon' => 'fas fa-keyboard', 'title' => 'Typo-Catching Email Field', 'notes_only' => true, 'body' => 'Type gmial.com by mistake? The system now gently asks "Did you mean gmail.com?" with a one-click fix. Works on the Add Player popup and your Edit Account screen.'],
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
