<?php

// system/lib/ork3/class.CmsStarterContent.php
// The hand-authored PUBLIC WEBSITE COPY that a new OGRE site is seeded with:
// the page list, the block layout, the HTML bodies, the FAQ items and the CTA
// wording, for both kingdom and park scope.
//
// WHY THIS IS ITS OWN FILE. This copy used to live inside
// CmsSite::_starterPageDefs(). CmsSite owns the ork_cms_site lifecycle
// (unbuilt -> draft -> published), addressability (slug) and identity — it is a
// security- and lifecycle-critical class — so changing one sentence of
// marketing copy meant editing that class and having that class reviewed. The
// copy and the lifecycle change for completely different reasons and at
// completely different rates; they no longer share a file.
//
// PURE CONTENT ONLY. This class touches no database, no globals and no
// request state. Everything runtime-dependent — the org's noun and display
// name, the park's own description and URL, the sanitizer, the block
// registry's starter_fields, the stable self-href form, the parks-list ceiling
// — is resolved by CmsSite and handed in through $ctx. That is what keeps this
// file safe to edit: nothing in it does I/O, and a $ctx key that never arrives
// degrades to a harmless default instead of fataling mid-seed (see the
// defaults at the top of _parkV0()/_kingdomV0()).
//
// VERSIONED, and that is the point. ork_cms_site.seed_version records which
// version of this content a site was seeded with. Registry() can return the
// registry for ANY past version, not just the current one, so
// CmsSite::BackfillSeedContent() can re-derive exactly what a site WAS seeded
// with, compare it byte-for-byte against what is stored, and upgrade only the
// fields nobody has edited. Without the old version on hand, "has an officer
// touched this?" is unanswerable and every copy fix needs its own bespoke
// hand-written migration (see db-migrations/2026-09-20-cms-kingdom-seed-copy-
// repair.php, the 162-line script this mechanism generalizes).
//
// HOW TO CHANGE THE COPY, therefore:
//   1. Fork the version. Copy _kingdomV{N}() / _parkV{N}() to V{N+1}, edit the
//      COPY there, and leave V{N} byte-for-byte alone — an older version's
//      builder is a historical record, not live copy, and editing it silently
//      breaks the backfill's byte-match for every site seeded at that version.
//   2. Bump LATEST_VERSION here and CmsSite::CURRENT_SEED_VERSION to match.
//   3. Run CmsSite::BackfillSeedContent() over the already-seeded sites.
//
// CmsStarterContent sorts AFTER class.CmsSite.php alphabetically; it extends
// nothing and is entirely static, so load order does not matter.

class CmsStarterContent
{
    /**
     * The newest starter-content version this file can build. Must stay in
     * lockstep with CmsSite::CURRENT_SEED_VERSION — that constant is what gets
     * stamped on a seeded site, and this one is what Registry() builds when no
     * version is named.
     *
     * VERSION 0 IS "THE CONTENT AS OF THIS WAVE", and the distinction is not
     * pedantic. It is NOT a faithful record of what every already-live site
     * received: the ~20 kingdoms provisioned before this branch were seeded
     * from the previous template, and this same branch rewrote that copy
     * (evergreen Home/About bodies, a per-org meta_description instead of the
     * shared "Welcome to our {noun}." placeholder, the documents page typed
     * 'resource' instead of 'media', the kingdom_parks limit taken from the
     * block's own ceiling instead of a hard-coded 24, the fabricated
     * staff_roster person removed, a seeded file_download row added). Those
     * sites are stamped seed_version = 0 while holding DIFFERENT bytes, so a
     * future backfill re-derives v0 and correctly declines to touch them.
     * Running db-migrations/2026-09-20-cms-kingdom-seed-copy-repair.php first
     * is what brings a pre-branch kingdom's stored copy back in line with what
     * v0 re-derives; until then it is simply skipped, never overwritten.
     *
     * Re-derivation also replays an old version's copy against TODAY's runtime
     * inputs (the parks-list ceiling, the block registry's starter_fields, the
     * live org name, the park's live description), so it is exact for the
     * authored strings and approximate for anything resolved at seed time.
     * Both kinds of mismatch fail the same safe way: no byte-match, no write.
     *
     * Version 1 is IDENTICAL to version 0 on purpose: the extraction that
     * introduced versioning was required to be behavior-preserving, so there is
     * no copy change to record yet.
     *
     * Version 2 is the first REAL copy change, and it is kingdom-only: the
     * kingdom starter is rebuilt around the one job a kingdom site has (get the
     * visitor to a park near them) — a crest-led kingdom_hero, a parks teaser,
     * the park template's first-day steps, events, and a closing CTA — plus a
     * 'New to Amtgard?' page carrying the SAME FAQ the park starter ships.
     * _parkV2() therefore delegates to _parkV1(): the park copy did not change,
     * and re-typing it into a V2 body would create a second copy that can drift.
     */
    public const LATEST_VERSION = 2;

    /**
     * The starter-page registry for one scope at one content version: a
     * slug-keyed list of ['nav_label', 'attrs', 'blocks'] in the order the pages
     * are seeded AND the order their nav items appear. ARRAY ORDER IS
     * LOAD-BEARING.
     *
     * Single source of truth: the seed loop and the nav loop both read this, so
     * the page list and the menu can no longer drift apart.
     *
     * SCOPE-AWARE, and it must stay that way. A park scope returns its OWN
     * three-page registry built on the park_* blocks (including park_meeting,
     * the most useful block on a park page) and no parks page, rather than
     * sharing the kingdom template: the kingdom-scoped dynamic blocks
     * (kingdom_events, kingdom_parks, kingdom_parks_map, kingdom_officers)
     * each correctly render NOTHING outside a kingdom scope, so seeding them
     * into a park site fails SILENTLY — blank pages, no error anywhere.
     *
     * An unknown/future $version builds the latest known one rather than
     * returning nothing: a site stamped with a version this code has never
     * heard of (a rollback) must still be able to render its starter registry.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param array  $ctx       runtime values resolved by CmsSite — see
     *   _kingdomV0()/_parkV0() for the keys each scope requires.
     * @param int    $version   content version to build (default: latest)
     * @return array slug => array{nav_label:string, attrs:array, blocks:array}
     */
    public static function Registry($scopeType, array $ctx, $version = self::LATEST_VERSION)
    {
        $version = (int) $version;
        $isPark  = ((string) $scopeType === 'park');

        if ($version <= 0) {
            return $isPark ? self::_parkV0($ctx) : self::_kingdomV0($ctx);
        }
        if ($version === 1) {
            return $isPark ? self::_parkV1($ctx) : self::_kingdomV1($ctx);
        }
        return $isPark ? self::_parkV2($ctx) : self::_kingdomV2($ctx);
    }

    /**
     * The authored-HTML cleaner, or a pass-through when none was handed in.
     *
     * The real one is CmsSite's CmsSanitizer::Clean closure, which every
     * authored body goes through exactly as the editor save path does. The
     * fallback is NOT a weaker sanitizer: nothing here is user input (this file
     * is dev-authored copy), and CmsPage::ReplaceBlocks() re-cleans every field
     * at the storage choke point regardless. It exists only so a caller that
     * forgot the key gets un-prettified copy instead of a fatal.
     *
     * @param mixed $clean the caller's cleaner, or anything non-callable
     * @return callable
     */
    private static function _cleaner($clean)
    {
        return is_callable($clean) ? $clean : function ($html) {
            return (string) $html;
        };
    }

    /**
     * Version 1, park scope. Byte-identical to version 0 — see LATEST_VERSION.
     * The first real park copy change forks this into _parkV2() and edits
     * THERE, leaving version 0 and version 1 as the historical record of what
     * the already-seeded sites received.
     *
     * @param array $ctx
     * @return array
     */
    private static function _parkV1(array $ctx)
    {
        return self::_parkV0($ctx);
    }

    /**
     * Version 1, kingdom scope. Byte-identical to version 0 — see _parkV1().
     *
     * @param array $ctx
     * @return array
     */
    private static function _kingdomV1(array $ctx)
    {
        return self::_kingdomV0($ctx);
    }

    /**
     * Version 2, park scope. Byte-identical to version 1 (and so to version 0).
     *
     * Version 2 is a KINGDOM-only copy change. Delegating here rather than
     * copying the park body forward is deliberate: a hand-copied V2 park
     * registry would be a second set of the same sentences, free to drift from
     * the one the already-seeded park sites were given, for no gain.
     *
     * @param array $ctx
     * @return array
     */
    private static function _parkV2(array $ctx)
    {
        return self::_parkV1($ctx);
    }

    /**
     * Version 2, KINGDOM scope — the kingdom starter rebuilt around the one job
     * a kingdom site actually has.
     *
     * WHAT WAS WRONG WITH V0/V1, in one line: the Home page said "Find a park
     * near you and come play" and then offered no way to do it — two centred
     * grey text bands, an events list, and nothing else. A visitor who arrives
     * from an "Amtgard <state>" search (the search a KINGDOM site wins, far more
     * often than any park site does) got no hero, no route to a park, and no
     * answer to "what is this and should I try it".
     *
     * So version 2:
     *   HOME    crest-led kingdom_hero carrying a "Find a park near you" CTA,
     *           then the parks TEASER (the newcomer's real next step), then the
     *           park template's four-step first day, then events, then a closing
     *           CTA on the same action.
     *   NEW     a 'New to Amtgard?' page built on the SAME eight-question FAQ
     *           the park starter ships (see _newPlayerFaqV0()) — it names no
     *           weekday, no price and no park-specific fact, so it is already
     *           true for a kingdom.
     *   PARKS   unchanged content; nav label rewritten for the visitor.
     *   OFFICE  the authored roster beside the live officer block now asks for
     *           the thing every kingdom HAS (guild masters and appointed
     *           officers, none of which exist in ork_officer) instead of a Board
     *           of Directors, which only separately-incorporated kingdoms have.
     *   NAV     News (the blog route the org already has), visitor-facing
     *           labels, and About as a parent with Documents beneath it.
     *
     * EVERY href into this site's own pages is the STABLE 'Page/view/{slug}'
     * form from CmsSite::_sitePageHref(), never a baked-in /k/{slug}/ route —
     * see that method's docblock. The partials resolve it against the CURRENT
     * site slug at render time.
     *
     * Required $ctx keys: everything _kingdomV0() takes, plus
     *   'parks_href'       string: this site's own 'parks' page (stable form).
     *   'new_players_href' string: this site's own 'new-players' page.
     *
     * @param array $ctx
     * @return array
     */
    private static function _kingdomV2(array $ctx)
    {
        // Same degrade-don't-fatal contract as V0: a caller that forgets a key
        // gets a weaker block, never a half-seeded site. An absent href seeds
        // empty, which the partials treat as "no link" rather than a dead one.
        $ctx += array(
            'clean' => null, 'meta' => null, 'starter_fields' => null,
            'noun' => 'Group', 'noun_lower' => 'group',
            'org_label' => 'our group', 'org_label_start' => 'Our group',
            'parks_limit' => 0, 'parks_href' => '', 'new_players_href' => '',
        );
        $clean         = self::_cleaner($ctx['clean']);
        $meta          = is_callable($ctx['meta']) ? $ctx['meta'] : function ($text) {
            return trim((string) $text);
        };
        $starterFields = is_callable($ctx['starter_fields'])
            ? $ctx['starter_fields']
            : function ($type, array $overrides = array()) {
                return $overrides;
            };
        $noun           = (string) $ctx['noun'];
        $nounLower      = (string) $ctx['noun_lower'];
        $orgLabel       = (string) $ctx['org_label'];
        $orgLabelStart  = (string) $ctx['org_label_start'];
        $parksLimit     = (int) $ctx['parks_limit'];
        $parksHref      = (string) $ctx['parks_href'];
        $newPlayersHref = (string) $ctx['new_players_href'];

        // The park starter's four-step first day, REUSED rather than rewritten:
        // every sentence in it is already true of every kingdom, and a second
        // copy of it here would be free to drift from the one 342 park sites
        // carry. Exactly ONE sentence is replaced, and only because it would
        // otherwise be FALSE here: on a park home page step one points at the
        // park_meeting block directly above it ("come to the time and place
        // above"), and a kingdom home has no meeting block — a kingdom's
        // meeting times live at the park level. It points at the parks teaser
        // seeded above it instead.
        $steps = self::_firstDayStepsV0();
        $steps[0]['body'] = 'You don’t need to email anyone, register, or bring anything but water. '
            . 'Pick a park from the list above and come to the time and place it lists. Ten minutes '
            . 'early is perfect. An hour late is also fine — they’ll still be out there.';

        // The live blocks, seeded with the SAME fields version 0 gave them so a
        // backfill over an already-seeded kingdom sees no change to write here.
        $eventsBlock = array(
            'type' => 'kingdom_events',
            'source' => 'dynamic', 'enabled' => 1, 'order' => 40,
            'fields' => array(
                'heading' => 'Upcoming Events',
                'kicker'  => "What's happening",
                'limit'   => 6,
            ),
        );

        // NOTE: seeded pages carry NO leading heading block — Site_shell already
        // promotes the page title to the page's <h1>.
        return array(
            // ---- HOME — hero, the parks teaser, the first day, events, CTA ----
            'home' => array(
                'nav_label' => 'Home',
                'attrs' => array(
                    'slug'             => 'home',
                    'type'             => 'composed',
                    'title'            => 'Home',
                    'is_system'        => 1,
                    'meta_description' => $meta(
                        $orgLabelStart . ' — Amtgard foam combat and medieval hobby. '
                        . 'Find a park near you, meet the officers, and see what is coming up.'
                    ),
                ),
                'blocks' => array(
                    // Crest-led, so it looks finished with no photo — the same
                    // reasoning park_hero's own catalog entry states, and just as
                    // true for a kingdom. It can never emit an empty-src <img>:
                    // with no heraldry it degrades to a monogram.
                    array(
                        'type' => 'kingdom_hero', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                        'fields' => $starterFields('kingdom_hero', array(
                            'kicker'    => '',
                            'heading'   => '',
                            // True of every kingdom, with nobody typing anything.
                            'tagline'   => 'Foam swords, real friendships, and a place for everyone.',
                            'cta_label' => 'Find a park near you',
                            'cta_href'  => $parksHref,
                        )),
                    ),
                    // A TEASER, not the whole list: six parks with their own
                    // heraldry, and an "All parks" link to the Parks page. The
                    // full list plus the map is what that page is for.
                    array(
                        'type' => 'kingdom_parks', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'kicker'    => 'Start here',
                            'heading'   => 'Find a Park Near You',
                            'sort'      => 'city',
                            'show_heraldry' => 1,
                            'limit'     => 6,
                            'more_href' => $parksHref,
                            'include_child_kingdoms' => 1,
                        ),
                    ),
                    array(
                        'type' => 'steps', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'kicker'  => 'New here? Start here',
                            'heading' => 'Your First Day, Start to Finish',
                            'band'    => 'light',
                            'cta'     => array(
                                'label' => 'More questions? Read the new player guide',
                                'href'  => $newPlayersHref,
                            ),
                            'steps'   => $steps,
                        ),
                    ),
                    $eventsBlock,
                    array(
                        'type' => 'cta_band', 'source' => 'authored', 'enabled' => 1, 'order' => 50,
                        'fields' => $starterFields('cta_band', array(
                            'heading' => 'Come play with us',
                            // No price and no schedule: this band is published on
                            // behalf of parks the kingdom does not control, so it
                            // promises nothing a park has to honour.
                            'subcopy' => 'Every park in the ' . $nounLower . ' teaches newcomers on the '
                                . 'field, and most keep loaner weapons on hand. Nothing to buy and nothing '
                                . 'to sign up for — just find the one nearest you.',
                            'ctas'    => array(
                                array('label' => 'Find a park near you', 'href' => $parksHref, 'style' => 'gold'),
                                array('label' => 'New to Amtgard? Start here', 'href' => $newPlayersHref, 'style' => 'ghost'),
                            ),
                        )),
                    ),
                ),
            ),

            // ---- NEW TO AMTGARD? — the page an "Amtgard <state>" search needs ----
            // Second in the registry, so it is second in the page list AND second
            // in the nav (array order is load-bearing for both).
            'new-players' => array(
                'nav_label' => 'New to Amtgard?',
                'attrs' => array(
                    'slug'             => 'new-players',
                    'type'             => 'article',
                    'title'            => 'New to Amtgard?',
                    'meta_description' => $meta(
                        'Your first day of Amtgard in ' . $orgLabel . ': what to wear, what it costs, '
                        . 'whether it is safe, and what actually happens at a park day.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                        'fields' => array(
                            'kicker'  => 'Never played?',
                            'heading' => 'Start Here',
                            'align'   => 'left',
                            'body'    => $clean(
                                '<p>Amtgard is a foam-combat and medieval hobby played outdoors in public '
                                . 'parks. There is no tryout, no membership to buy, and no experience '
                                . 'required. ' . htmlspecialchars($orgLabelStart, ENT_QUOTES, 'UTF-8')
                                . ' is made up of local parks, each with its own game days &mdash; turn up '
                                . 'at the one nearest you, borrow a sword, and someone will teach you the '
                                . 'rest.</p>'
                            ),
                        ),
                    ),
                    // The park starter's FAQ, verbatim and SHARED (see
                    // _newPlayerFaqV0): it names no weekday, no price and no
                    // park-specific fact, so it is already kingdom-safe. A
                    // second, kingdom-flavoured rewrite would only drift.
                    array(
                        'type' => 'accordion', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array('items' => self::_newPlayerFaqV0()),
                    ),
                    // Closes on the kingdom's OWN parks, not the global Atlas.
                    // The park starter sends a reader to the Atlas because one
                    // park may genuinely be too far; a kingdom routing visitors
                    // away from its own park list is the opposite of its job.
                    array(
                        'type' => 'cta_band', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        'fields' => $starterFields('cta_band', array(
                            'heading' => 'Ready when you are',
                            'subcopy' => 'You don’t have to tell anyone you’re coming. Find the park nearest '
                                . 'you, show up, and say you’re new.',
                            'ctas'    => array(
                                array('label' => 'Find a park near you', 'href' => $parksHref, 'style' => 'gold'),
                            ),
                        )),
                    ),
                ),
            ),

            // ---- FIND A PARK — content unchanged from v0; label rewritten ----
            // "Our Parks" is what an officer calls it; "Find a Park" is what a
            // visitor is searching for.
            'parks' => array(
                'nav_label' => 'Find a Park',
                'attrs' => array(
                    'slug'             => 'parks',
                    'type'             => 'composed',
                    'title'            => 'Our Parks',
                    'meta_description' => $meta(
                        'Every Amtgard park in ' . $orgLabel . ' — find the group nearest you, '
                        . 'see where they play, and come out for a day on the field.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'kingdom_parks_map', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'heading' => 'Find a Park Near You',
                            'kicker'  => 'Our Parks',
                        ),
                    ),
                    array(
                        'type' => 'kingdom_parks', 'source' => 'dynamic', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'heading' => 'Where We Play',
                            'kicker'  => '',
                            'sort'    => 'city',
                            'show_heraldry' => 1,
                            // The block's own ceiling, never an arbitrary cap —
                            // see _kingdomV0() for the map/list disagreement a
                            // hard-coded 24 caused on this very page.
                            'limit'   => $parksLimit,
                            'include_child_kingdoms' => 1,
                        ),
                    ),
                ),
            ),

            // ---- NEWS — NAV ONLY, no page ----
            // The org already has live blog routes (org_header.tpl rewrites
            // Site/blog/{slug} and Site/post/{slug}/{post}), but nothing in the
            // seeded nav ever pointed at them, so an officer who published a post
            // got a page with zero internal links to it. 'dynamic' + 'Blog' is
            // the internal-route link type CmsNav already resolves; org_header
            // re-points it onto this site's own /Site/blog/{slug}.
            'news' => array(
                'nav_label' => 'News',
                'nav_link'  => array('link_type' => 'dynamic', 'url' => 'Blog'),
            ),

            // ---- CONTACT (slug stays 'officers') ----
            'officers' => array(
                'nav_label' => 'Contact',
                'attrs' => array(
                    'slug'             => 'officers',
                    'type'             => 'composed',
                    // Titled the way the park starter titles its equivalent page,
                    // so the nav label and the <h1> agree.
                    'title'            => 'Contact & Officers',
                    'meta_description' => $meta(
                        'The officers who keep ' . $orgLabel . ' running, and how to reach them.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'kingdom_officers',
                        'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'heading' => 'Our Officers',
                            'kicker'  => 'Leadership',
                            'limit'   => 12,
                        ),
                    ),
                    array(
                        'type' => 'staff_roster', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        // THE POINT OF THIS BLOCK, corrected. The live block above
                        // can only ever show the five kingdom seats ORK stores
                        // (Officer::PUBLIC_OFFICER_ROLE_LABELS: Monarch, Regent,
                        // Prime Minister, Champion, GMR). A real officer corps is
                        // two to three times that — the guild masters, Treasurer,
                        // Historian, Webminister, and the Crown's appointed
                        // deputies — and NONE of them exist in ork_officer.
                        // Covering that gap is exactly what an authored roster is
                        // for, and v0 pointed it at a "Board of Directors" instead:
                        // a body only the few separately-incorporated kingdoms
                        // have, and a legal entity distinct from the Crown even
                        // there. So it asked every new web officer for something
                        // most kingdoms do not have, while the thing every kingdom
                        // DOES have had nowhere to go.
                        //
                        // presentation 'amtgard', not v0's 'mundane': the
                        // template's consent gate forces persona-first anyway
                        // without each person's own show_mundane opt-in, so
                        // 'mundane' could never take effect — and persona-first is
                        // the right default for Amtgard officers regardless.
                        //
                        // 'people' stays EMPTY (from starter_fields). Seeding
                        // role-only rows was considered and REJECTED against the
                        // template: staff_roster.tpl drops any row whose primary
                        // name resolves empty, so a row carrying only a role
                        // renders NOTHING — seeding blocks that publish nothing is
                        // the defect this starter has already been fixed for twice.
                        // An empty roster self-suppresses publicly and prompts the
                        // author in preview, which is the correct empty state.
                        'fields' => $starterFields('staff_roster', array(
                            'kicker'       => 'Officer corps',
                            'heading'      => 'Guild Masters & Appointed Officers',
                            'presentation' => 'amtgard',
                        )),
                    ),
                ),
            ),

            // ---- ABOUT — content unchanged from v0; now a nav PARENT ----
            'about' => array(
                'nav_label' => 'About',
                'attrs' => array(
                    'slug'             => 'about',
                    'type'             => 'article',
                    'title'            => 'About Us',
                    'meta_description' => $meta(
                        'The story of ' . $orgLabel . ': how it began, the parks it covers, '
                        . 'and the traditions that make it ours.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'kicker'  => 'Our History',
                            'heading' => 'How We Got Here',
                            'align'   => 'left',
                            // EVERGREEN PROSE, byte-identical to v0 — see that
                            // builder for why it is neither empty nor an author
                            // instruction.
                            'body'    => $clean(
                                '<p>' . htmlspecialchars($orgLabelStart, ENT_QUOTES, 'UTF-8')
                                . ' is part of Amtgard &mdash; a medieval and fantasy combat game played '
                                . 'outdoors with padded weapons, alongside the arts, crafts and service that '
                                . 'grew up around it. What the ' . $nounLower . ' is today was built by the '
                                . 'players who came before: volunteers founded the parks listed on this site, '
                                . 'and the officers who run the ' . $nounLower . ' are elected from among its '
                                . 'own members.</p>'
                                . '<p>That work carries on every game day. Newcomers are welcome at any of our '
                                . 'parks &mdash; most keep loaner weapons on hand and will teach you the rules '
                                . 'right there on the field.</p>'
                            ),
                        ),
                    ),
                ),
            ),

            // ---- DOCUMENTS — content unchanged from v0; now a nav CHILD ----
            // The two least-visited items in the bar move into one dropdown under
            // About, which also demonstrates the parent/child nav the CMS has
            // always supported (CmsNav::CreateItem takes parent_id; org_header
            // renders a .fd-dropdown for any item with children) and that no
            // seeded menu had ever used. Label shortened: "Documents & Resources"
            // was the longest item in a top-level bar it no longer sits in.
            'documents' => array(
                'nav_label'  => 'Documents',
                'nav_parent' => 'about',
                'attrs' => array(
                    'slug'             => 'documents',
                    'type'             => 'resource',
                    'title'            => 'Documents & Resources',
                    'meta_description' => $meta(
                        'Rules, documents, and resources for ' . $orgLabel . '.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'file_download', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        // EXACTLY ONE seeded row, unchanged from v0 — see that
                        // builder for why it is the Rules of Play and why it
                        // links the /documents hub rather than a PDF URL.
                        'fields' => $starterFields('file_download', array(
                            'files' => array(
                                array(
                                    'title'       => 'Amtgard Rules of Play',
                                    'description' => 'The rules every Amtgard game is played by.',
                                    'url'         => 'https://www.amtgard.com/documents',
                                    'filetype'    => '',
                                    'size_label'  => '',
                                ),
                            ),
                        )),
                    ),
                ),
            ),
        );
    }

    /**
     * The eight-question new-player FAQ, as version 0 authored it.
     *
     * SHARED, NOT COPIED, and version-frozen. _parkV0() authored these items and
     * _kingdomV2() publishes the same eight on its own 'New to Amtgard?' page:
     * they name no weekday, no price, no meeting place and no park-specific
     * claim, so every answer is as true for a kingdom as for a park. Writing a
     * second kingdom-flavoured set would guarantee the two drift.
     *
     * FROZEN LIKE ANY OTHER V0 BODY. This is a pure extraction — _parkV0()'s
     * output is byte-for-byte what it was, which is what the backfill's
     * re-derivation depends on. A future FAQ rewrite therefore adds
     * _newPlayerFaqV{N}() and is referenced from the NEW version's builder; it
     * never edits these strings, exactly as it never edits _parkV0()'s.
     *
     * @return array list of array{q:string,a:string}
     */
    private static function _newPlayerFaqV0()
    {
        return array(
            array('q' => 'What should I wear?', 'a' => 'Clothes you can run in and closed-toe shoes you don’t mind getting grass on. That’s genuinely it — you do not need a costume, armor, or anything medieval, and plenty of regulars play in gym shorts and a t-shirt. Bring water. Sunscreen if it’s that kind of day.'),
            array('q' => 'Do I need to buy equipment?', 'a' => 'No. We have loaner weapons and shields, and you’re welcome to use them as long as you want — weeks or months, nobody’s counting. When you do want your own, most players build theirs out of foam, tape, and a bit of patience, and someone here will happily show you how. This hobby is much cheaper than it looks.'),
            array('q' => 'Does it cost anything?', 'a' => 'Coming out and playing doesn’t. Amtgard is run entirely by volunteers — nobody here is paid and nobody is selling you anything. Some groups ask their regular members for small dues later on to keep loaner gear stocked, but nobody is going to ask you for money on your first day.'),
            array('q' => 'What actually happens at a park day?', 'a' => 'People trickle in, gear gets laid out and safety-checked, and someone starts calling games — team battles, last-one-standing, capture the flag with foam swords. In between, people sit in the shade and talk, work on armor and costume, or practice. You can play as hard or as gently as you like; there’s no fitness requirement and no minimum. Come late, leave early, take breaks whenever you want.'),
            array('q' => 'Is it safe? Will I get hurt?', 'a' => 'Every weapon is foam over a flexible core and gets checked before it’s used. Intentional hits to the head are against the rules, and so is swinging harder than it takes to feel a hit. You may pick up a bruise, the way you would in any sport — real injuries are rare. If someone is playing too hard, tell an officer. That’s what they’re there for.'),
            array('q' => 'Will I be the only new person?', 'a' => 'Maybe, maybe not — some days there are three newcomers and some days there’s just you. Either way, you won’t be the only person who has ever been new: every single player out there walked up once without knowing anybody. Showing up alone is the normal way to start.'),
            array('q' => 'How old do you have to be?', 'a' => 'Amtgard is all ages, and most groups have players from grade-schoolers to retirees. If you’re under 18, bring a parent or guardian along the first time — they may need to sign a waiver, and they’ll probably enjoy watching more than they expect.'),
            array('q' => 'Do I have to role-play or be in character?', 'a' => 'No. Some players have an elaborate persona and a name they go by out here; plenty of others just use their own first name and hit people with foam. Both are completely normal. Nobody is going to make you do an accent.'),
        );
    }

    /**
     * The four-step "your first day" copy, as version 0 authored it.
     *
     * SHARED, NOT COPIED, and version-frozen for exactly the reasons
     * _newPlayerFaqV0() is: _parkV0() authored these four steps, _kingdomV2()
     * seeds the same block, and every sentence is true of every Amtgard group
     * with nobody typing anything. The kingdom builder replaces ONE sentence —
     * step one's "come to the time and place above", which points at a
     * park_meeting block a kingdom home page does not have — and states why at
     * the point it does so.
     *
     * @return array list of array{title:string,body:string}
     */
    private static function _firstDayStepsV0()
    {
        return array(
            array('title' => 'Just show up.', 'body' => 'You don’t need to email anyone, register, or bring anything but water. Come to the time and place above. Ten minutes early is perfect. An hour late is also fine — we’ll still be out there.'),
            array('title' => 'Say the words "I’m new."', 'body' => 'Walk up to anyone and say it. That is the entire process. They’ll point you at whoever is running the day. Every person on that field said the same sentence once.'),
            array('title' => 'Borrow a sword.', 'body' => 'We keep loaner weapons and shields for exactly this reason. They’re foam over a flexible core. Someone will walk you through the safety basics — what counts as a hit, what’s off-limits — in about five minutes.'),
            array('title' => 'Play, or just watch.', 'body' => 'Jump into a game whenever you’re ready. If you’d rather stand on the sideline your whole first day and figure out what’s going on, that is completely normal and nobody will push you.'),
        );
    }

    /**
     * Version 0, PARK scope — the three-page park starter as it stood when
     * versioning was introduced.
     *
     * A park is not a small kingdom — it gets its OWN three-page design, not a
     * trimmed copy of the kingdom template.
     *
     * Three pages only (Home, New Players, Contact) against the kingdom's
     * five. About Us is gone because its seeded body published author
     * instructions to the open web; Documents & Resources is gone because
     * a park has no library to put behind it; the Board of Directors
     * roster is gone because parks have no board and it published a
     * fabricated person. No Events page either: 26 of 342 parks have an
     * upcoming event, so a nav item to an empty page would tell a
     * prospective newcomer the club is dead before they clicked — Events
     * stays as a block on Home, where the honest empty state reads as
     * "nothing beyond our regular park days".
     *
     * Every time/place/officer claim below comes from a dynamic block —
     * never hand-typed — so it can never contradict the live ORK data.
     *
     * Required $ctx keys:
     *   'clean'            callable(string): the CmsSanitizer::Clean pass every
     *                      authored body goes through on the editor save path.
     *   'new_players_href' string: the STABLE self-href for this site's own
     *                      'new-players' page (CmsSite::_sitePageHref()).
     *   'atlas_href'       string: the global Atlas route.
     *   'park_intro_body'  string: Home's "who we are" body, already chosen
     *                      from the park's own ORK description or the evergreen
     *                      fallback (CmsSite::_parkIntroBody()), NOT yet
     *                      sanitized — it is cleaned here like every other body.
     *   'park_cta_fields'  array: the closing CTA band's fields
     *                      (CmsSite::_parkCtaFields()).
     *
     * @param array $ctx
     * @return array
     */
    private static function _parkV0(array $ctx)
    {
        // Every key above is supplied by CmsSite::_starterPageDefs() today. The
        // defaults exist so a future caller that forgets one gets a degraded
        // block, not a fatal halfway through seeding a site.
        $ctx += array(
            'clean' => null, 'new_players_href' => '', 'atlas_href' => '',
            'park_intro_body' => '', 'park_cta_fields' => array(),
        );
        $clean          = self::_cleaner($ctx['clean']);
        $newPlayersHref = (string) $ctx['new_players_href'];
        $atlasHref      = (string) $ctx['atlas_href'];
        $parkIntroBody  = (string) $ctx['park_intro_body'];
        $parkCtaFields  = is_array($ctx['park_cta_fields']) ? $ctx['park_cta_fields'] : array();

        return array(
            'home' => array(
                'nav_label' => 'Home',
                'attrs' => array(
                    'slug' => 'home', 'type' => 'composed', 'title' => 'Home', 'is_system' => 1,
                    'meta_description' => 'A local Amtgard chapter — foam combat and medieval hobby, all ages, no experience or equipment needed. See when and where we meet, and what to expect on your first day.',
                ),
                'blocks' => array(
                    array('type' => 'park_hero', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                        'fields' => array('kicker' => '', 'heading' => '', 'show_weather' => 1,
                            'cta_label' => 'Plan your first visit', 'cta_href' => '#pk-meet')),
                    array('type' => 'park_meeting', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                        'fields' => array('kicker' => 'When can I show up?', 'heading' => 'When & Where We Meet',
                            'show_map' => 1, 'show_directions' => 1, 'limit' => 6)),
                    // The steps CTA links to this SAME site's own 'new-players'
                    // page, via the stable global href CmsSite::_sitePageHref()
                    // builds — never a baked-in /k/{siteSlug}/ one, which would
                    // 404 the moment an officer renamed the site. See that
                    // method's docblock for the full contract.
                    array('type' => 'steps', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'kicker' => 'New here? Start here', 'heading' => 'Your First Day, Start to Finish',
                            'band' => 'light',
                            'cta' => array('label' => 'More questions? Read the new player guide', 'href' => $newPlayersHref),
                            // Extracted, NOT rewritten: _firstDayStepsV0() returns
                            // these four steps verbatim so the kingdom starter can
                            // seed the same block without a second copy of the
                            // copy. v0's OUTPUT is unchanged, which is what the
                            // backfill's byte-match re-derivation depends on.
                            'steps' => self::_firstDayStepsV0())),
                    array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 40,
                        'fields' => array(
                            'kicker' => 'What is this, exactly?', 'heading' => 'Who We Are', 'align' => 'left',
                            'body' => $clean($parkIntroBody))),
                    array('type' => 'park_events', 'source' => 'dynamic', 'enabled' => 1, 'order' => 50,
                        'fields' => array('kicker' => 'What’s coming up?', 'heading' => 'Upcoming Events', 'limit' => 3)),
                    array('type' => 'park_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 60,
                        'fields' => array('kicker' => 'Who do I talk to?', 'heading' => 'Our Officers', 'limit' => 12)),
                    array('type' => 'cta_band', 'source' => 'authored', 'enabled' => 1, 'order' => 70,
                        'fields' => $parkCtaFields),
                ),
            ),

            'new-players' => array(
                'nav_label' => 'New Players',
                'attrs' => array(
                    'slug' => 'new-players', 'type' => 'article', 'title' => 'New Players',
                    'meta_description' => 'Everything you need for your first day of Amtgard: what to wear, what it costs, whether it’s safe, and what actually happens at a park day.',
                ),
                'blocks' => array(
                    array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                        'fields' => array(
                            'kicker' => 'Never played?', 'heading' => 'Start Here', 'align' => 'left',
                            'body' => $clean('<p>Amtgard is a foam-combat and medieval hobby that meets outdoors in a public park. There is no tryout, no membership to buy, and no experience required. Turn up, borrow a sword, and someone will teach you the rest.</p>'))),
                    // Extracted, NOT rewritten — see _newPlayerFaqV0(). v0's
                    // output is byte-for-byte what it was; the kingdom
                    // starter publishes the same eight answers.
                    array('type' => 'accordion', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array('items' => self::_newPlayerFaqV0())),
                    array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'kicker' => 'Not near us?', 'heading' => 'Find Another Group', 'align' => 'left',
                            'body' => $clean('<p>Amtgard has hundreds of chapters. If we’re too far away, the Atlas will find the one nearest you.</p>'),
                            'cta' => array('label' => 'Find another Amtgard group', 'href' => $atlasHref))),
                ),
            ),

            'contact' => array(
                'nav_label' => 'Contact',
                'attrs' => array(
                    'slug' => 'contact', 'type' => 'composed', 'title' => 'Contact & Officers',
                    'meta_description' => 'The volunteers who run this Amtgard chapter, and how to reach us.',
                ),
                'blocks' => array(
                    array('type' => 'park_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                        'fields' => array('kicker' => 'Who do I talk to?', 'heading' => 'Our Officers', 'limit' => 12)),
                    array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'heading' => 'Visiting from another park?', 'align' => 'left',
                            'body' => $clean('<p>You’re welcome at any of our park days — just come as you are. If you need to reach someone before you travel, any of the officers above can help.</p>'))),
                ),
            ),
        );
    }

    /**
     * Version 0, KINGDOM scope — the five-page kingdom starter as it stood when
     * versioning was introduced.
     *
     * Copy uses the org's own noun (CmsSite::OrgUnitNoun()) so a principality
     * reads "Principality" instead of every org being told it is a kingdom.
     *
     * Required $ctx keys:
     *   'clean'           callable(string): the CmsSanitizer::Clean pass.
     *   'meta'            callable(string): the 155-character meta-description
     *                     clamp (search engines truncate around there, and a
     *                     long org name must not push the distinguishing half
     *                     of a description past the cut).
     *   'noun'            string: 'Kingdom' | 'Principality' | 'Group'.
     *   'noun_lower'      string: the same, lowercased.
     *   'org_label'       string: the org's name for mid-sentence use, or
     *                     'our {noun}' when it could not be resolved.
     *   'org_label_start' string: the same for sentence-initial use.
     *   'parks_limit'     int: the kingdom_parks block's own ceiling.
     *   'starter_fields'  callable(string $type, array $overrides): a seeded
     *                     block's fields, built from the block type's OWN
     *                     declared starter_fields plus page-specific overrides
     *                     (CmsSite::_starterFields()).
     *
     * @param array $ctx
     * @return array
     */
    private static function _kingdomV0(array $ctx)
    {
        // Every key above is supplied by CmsSite::_starterPageDefs() today. The
        // defaults exist so a future caller that forgets one gets a degraded
        // block, not a fatal halfway through seeding a site. 'parks_limit' has
        // no safe literal — a hard-coded number here is the exact bug the
        // kingdom_parks ceiling note below records — so an absent one falls
        // back to 0, which the block reads as "no seeded cap".
        $ctx += array(
            'clean' => null, 'meta' => null, 'starter_fields' => null,
            'noun' => 'Group', 'noun_lower' => 'group',
            'org_label' => 'our group', 'org_label_start' => 'Our group',
            'parks_limit' => 0,
        );
        $clean         = self::_cleaner($ctx['clean']);
        $meta          = is_callable($ctx['meta']) ? $ctx['meta'] : function ($text) {
            return trim((string) $text);
        };
        $starterFields = is_callable($ctx['starter_fields'])
            ? $ctx['starter_fields']
            : function ($type, array $overrides = array()) {
                return $overrides;
            };
        $noun          = (string) $ctx['noun'];
        $nounLower     = (string) $ctx['noun_lower'];
        $orgLabel      = (string) $ctx['org_label'];
        $orgLabelStart = (string) $ctx['org_label_start'];
        $parksLimit    = (int) $ctx['parks_limit'];

        // The org's live "who holds office" block. Both partials take the same
        // fields; only the scope they read differs.
        // NOTE: KINGDOM scope only — the park registry is built separately, so
        // kingdom_officers is the only type ever used here.
        $officersBlock = array(
            'type' => 'kingdom_officers',
            'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
            'fields' => array(
                'heading' => 'Our Officers',
                'kicker'  => 'Leadership',
                'limit'   => 12,
            ),
        );

        // The org's live upcoming-events block, same story (kingdom scope only).
        $eventsBlock = array(
            'type' => 'kingdom_events',
            'source' => 'dynamic', 'enabled' => 1, 'order' => 40,
            'fields' => array(
                'heading' => 'Upcoming Events',
                'kicker'  => "What's happening",
                'limit'   => 6,
            ),
        );

        // NOTE: seeded pages deliberately carry NO leading heading block. Site_shell
        // already promotes the page title to the page's <h1> whenever no content
        // block supplies one, so a heading block repeating that title rendered the
        // page name twice, one directly under the other.
        $defs = array(
            // ---- HOME (is_system within scope) — welcome + intro + upcoming events ----
            // NOTE: deliberately NOT hero_carousel — that block bakes in a GLOBAL
            // stats ticker (0s on a kingdom scope) and would emit an empty-src <img>
            // with no seed image. The spec cut the stats ticker; the org adds its own
            // hero imagery via the editor. Seed a clean welcome rich_text instead.
            'home' => array(
                'nav_label' => 'Home',
                'attrs' => array(
                    'slug'             => 'home',
                    'type'             => 'composed',
                    'title'            => 'Home',
                    'is_system'        => 1,
                    'meta_description' => $meta(
                        $orgLabelStart . ' — Amtgard foam combat and medieval hobby. '
                        . 'Find a park near you, meet the officers, and see what is coming up.'
                    ),
                ),
                'blocks' => array_values(array_filter(array(
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                        'fields' => array(
                            'kicker'  => 'Welcome',
                            'heading' => 'Welcome to Our ' . $noun,
                            'align'   => 'center',
                            // NOTE: reached by KINGDOM scope only — the park
                            // registry is built separately.
                            //
                            // No price promise here. This copy is published on
                            // behalf of parks this org does not control, and
                            // "your first day is always free" is a claim only a
                            // park can make about its own field (the park
                            // registry's own copy refuses it for the same reason).
                            'body'    => $clean(
                                '<p>Foam swords, real friendships, and a place for everyone. Find a park near you and come play.</p>'
                            ),
                        ),
                    ),
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'kicker'  => 'About Us',
                            'heading' => 'A ' . $noun . ' of Adventurers',
                            'align'   => 'center',
                            // EVERGREEN PROSE, never a task for the officer.
                            // Every starter page is seeded status='published'
                            // behind a one-click site Publish, so whatever is
                            // written here IS the public copy of any org that
                            // publishes before editing. This body used to read
                            // "Tell visitors who you are… Edit this block…" —
                            // author instructions on the open web, the exact
                            // class of copy the park registry was rebuilt to
                            // remove (see its comment above). Modelled on
                            // CmsSite::_parkIntroBody()'s fallback paragraph:
                            // true of every kingdom, on every day, with nobody
                            // typing anything.
                            'body'    => $clean(
                                '<p>We are a ' . $nounLower . ' of Amtgard &mdash; an all-ages foam-combat and '
                                . 'medieval hobby played outdoors in public parks. Our parks welcome newcomers with '
                                . 'no experience and teach the game right there on the field. Find the park nearest '
                                . 'you and come see what a game day looks like.</p>'
                            ),
                        ),
                    ),
                    // Kingdoms have no meeting-time equivalent — their meeting times
                    // live at the park level. (A park's own home page carries a
                    // park_meeting block instead — see _parkV0().)
                    $eventsBlock,
                ))),
            ),

            // ---- ABOUT US / HISTORY — heading + rich_text placeholder ----
            'about' => array(
                'nav_label' => 'About Us',
                'attrs' => array(
                    'slug'             => 'about',
                    'type'             => 'article',
                    'title'            => 'About Us',
                    'meta_description' => $meta(
                        'The story of ' . $orgLabel . ': how it began, the parks it covers, '
                        . 'and the traditions that make it ours.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'kicker'  => 'Our History',
                            'heading' => 'How We Got Here',
                            'align'   => 'left',
                            // NOTE: reached by KINGDOM scope only — the park
                            // registry is built separately (and no longer has an
                            // About page).
                            //
                            // EVERGREEN PROSE, not an author prompt and not
                            // empty. The previous body ("Share your kingdom's
                            // story… Replace this placeholder…") published
                            // author instructions to the open web on any site
                            // published before it was edited. Empty is not the
                            // fix either: rich_text.tpl only self-suppresses
                            // when EVERY field is empty, and this block keeps a
                            // kicker and a heading, so an empty body would
                            // publish a nav-linked About page that is nothing
                            // but a headline over blank space — and would never
                            // fire fdEmptyBlockNotice() in preview either.
                            //
                            // So: a paragraph modelled on _parkIntroBody()'s
                            // fallback — true of every org on every day, with
                            // nobody typing anything — carrying the org's own
                            // name so it is not byte-identical across every
                            // kingdom. States no time and no place (the hard
                            // invariant _parkIntroBody() documents), and claims
                            // nothing a seed cannot know: the org's real history
                            // is still the officer's to write over this.
                            'body'    => $clean(
                                '<p>' . htmlspecialchars($orgLabelStart, ENT_QUOTES, 'UTF-8')
                                . ' is part of Amtgard &mdash; a medieval and fantasy combat game played '
                                . 'outdoors with padded weapons, alongside the arts, crafts and service that '
                                . 'grew up around it. What the ' . $nounLower . ' is today was built by the '
                                . 'players who came before: volunteers founded the parks listed on this site, '
                                . 'and the officers who run the ' . $nounLower . ' are elected from among its '
                                . 'own members.</p>'
                                . '<p>That work carries on every game day. Newcomers are welcome at any of our '
                                . 'parks &mdash; most keep loaner weapons on hand and will teach you the rules '
                                . 'right there on the field.</p>'
                            ),
                        ),
                    ),
                ),
            ),

            // ---- OUR PARKS — kingdom_parks_map + kingdom_parks (both dynamic) ----
            // KINGDOM SCOPE ONLY. A park has no parks of its own, and both blocks
            // here are kingdom-scoped, so on a park site this page seeded as a
            // permanently empty "Our Parks" entry in the nav. The park registry
            // has no such page.
            'parks' => array(
                'nav_label' => 'Our Parks',
                'attrs' => array(
                    'slug'             => 'parks',
                    'type'             => 'composed',
                    'title'            => 'Our Parks',
                    'meta_description' => $meta(
                        'Every Amtgard park in ' . $orgLabel . ' — find the group nearest you, '
                        . 'see where they play, and come out for a day on the field.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'kingdom_parks_map', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'heading' => 'Find a Park Near You',
                            'kicker'  => 'Our Parks',
                        ),
                    ),
                    array(
                        'type' => 'kingdom_parks', 'source' => 'dynamic', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'heading' => 'Where We Play',
                            'kicker'  => '',
                            'sort'    => 'city',
                            'show_heraldry' => 1,
                            // Seed the block's own ceiling, not an arbitrary 24.
                            // kingdom_parks.tpl renders an "All parks" link only
                            // when more_href is set, so a seeded cap silently
                            // dropped every park past the 24th — WHILE the
                            // kingdom_parks_map directly above plotted all of
                            // them. Map and list openly disagreed on one page.
                            'limit'   => $parksLimit,
                            // Roll a principality's parks up into its parent's
                            // list (opt-in on Kingdom::GetActiveParks()).
                            'include_child_kingdoms' => 1,
                        ),
                    ),
                ),
            ),

            // ---- OFFICERS — heading + kingdom_officers (dynamic) + Board roster ----
            'officers' => array(
                'nav_label' => 'Officers',
                'attrs' => array(
                    'slug'             => 'officers',
                    'type'             => 'composed',
                    'title'            => 'Officers',
                    'meta_description' => $meta(
                        'The officers who keep ' . $orgLabel . ' running, and how to reach them.'
                    ),
                ),
                'blocks' => array(
                    $officersBlock,
                    array(
                        'type' => 'staff_roster', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        // Built by MERGING the registry's own starter_fields with
                        // the page-specific overrides, rather than re-declaring
                        // the whole field array here. The block's field contract
                        // was previously written out by hand in two unrelated
                        // places (CmsBlockRegistry for the editor, here for the
                        // seed) with nothing keeping either in step with what
                        // staff_roster.tpl actually consumes — which is how the
                        // seeded roster ended up missing the show_mundane
                        // consent flag.
                        //
                        // 'subheading' and 'people' come from starter_fields and
                        // are DELIBERATELY empty: the seed used to carry a
                        // fabricated blank person ("Add a board member") that the
                        // template's consent gate discarded anyway, leaving a
                        // kicker + <h2> + subheading stranded over an empty grid
                        // — and a made-up person on a public page. An empty
                        // roster prompts the author in preview instead.
                        'fields' => $starterFields('staff_roster', array(
                            'kicker'       => 'Governance',
                            'heading'      => 'Board of Directors',
                            'presentation' => 'mundane',
                        )),
                    ),
                ),
            ),

            // ---- DOCUMENTS & RESOURCES — one real, universally-true download ----
            // type='resource' ('Documents & downloads'), NOT 'media' ('Photo
            // gallery'): _blockAllow() computes the Add-block chooser strictly
            // from PageTypeDefs[page.type], and file_download is in neither
            // 'media''s extra_blocks nor the universal set — so the one page
            // whose whole purpose is hosting files could not have a second
            // file_download added through the UI, and listed as "Photo gallery".
            'documents' => array(
                'nav_label' => 'Documents & Resources',
                'attrs' => array(
                    'slug'             => 'documents',
                    'type'             => 'resource',
                    'title'            => 'Documents & Resources',
                    'meta_description' => $meta(
                        'Rules, documents, and resources for ' . $orgLabel . '.'
                    ),
                ),
                'blocks' => array(
                    array(
                        'type' => 'file_download', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        // EXACTLY ONE seeded row, and only because it is true of
                        // every Amtgard org: the Rules of Play. An empty files[]
                        // published a page containing nothing but its own <h1>,
                        // permanently linked from every page's top nav.
                        //
                        // No Corpora row: there is no universal Amtgard Corpora
                        // (every kingdom has its own; the only one at that URL is
                        // the Freehold's), so it would be either wrong or a blank
                        // placeholder — the author-instructions-on-the-open-web
                        // failure again. And the /documents hub, never the direct
                        // Wix PDF URL: that URL is a content-hash path that dies
                        // the moment the file is re-uploaded.
                        //
                        // Field names are file_download.tpl's own contract
                        // (title/description/url/filetype/size_label).
                        'fields' => $starterFields('file_download', array(
                            'files' => array(
                                array(
                                    'title'       => 'Amtgard Rules of Play',
                                    'description' => 'The rules every Amtgard game is played by.',
                                    'url'         => 'https://www.amtgard.com/documents',
                                    'filetype'    => '',
                                    'size_label'  => '',
                                ),
                            ),
                        )),
                    ),
                ),
            ),
        );

        // NOTE: KINGDOM scope only — the park registry is a separate builder, so
        // nothing here needs a park-side "drop the Our Parks page" carve-out.
        return $defs;
    }
}
