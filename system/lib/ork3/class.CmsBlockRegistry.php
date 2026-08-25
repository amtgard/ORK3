<?php

/*************************************************************************
 * CmsBlockRegistry — the CMS content-type registry.
 *
 * TWO disjoint key domains, each declared exactly once:
 *
 *   BlockDefs()     keyed by BLOCK type  — every block type the editor knows
 *                   about, carrying its chooser presentation (label/group/icon/
 *                   description), whether it pulls data at render time
 *                   (`dynamic`), whether it may be added by hand (`addable`),
 *                   which site scopes it is OFFERED in (`scopes`, null = all),
 *                   and the empty field defaults a starter block is seeded with
 *                   (`starter_fields`).
 *
 *   PageTypeDefs()  keyed by PAGE type   — the page-type presets, carrying the
 *                   human `label`, the ordered `starters` (block types the
 *                   editor seeds a new page of that type with), and the
 *                   `extra_blocks` that type adds to the Add-block chooser on
 *                   top of the universal set.
 *
 * ORDER IS LOAD-BEARING. Both arrays are json_encode'd into the block editor
 * (Cms_edit.tpl / Cms_editpost.tpl), which buckets the block catalog by `group`
 * preserving catalog order within each bucket, and renders the page-type chooser
 * in declaration order. Reordering either array changes the admin UI.
 *
 * Pure data: no DB, no filesystem, no request state — exactly like CmsSanitizer,
 * so it is safe to read statically from any layer. The FILESYSTEM `available`
 * probe (does the block have a partial yet?) and the per-request SCOPE gating
 * both stay in Controller_Cms, which owns request context.
 *
 * Consumers:
 *   Controller_Cms::_blockCatalog()      chooser catalog (+ available/addable)
 *   Controller_Cms::_starter()           starter block field defaults
 *   Controller_Cms::_pageTypes()         page-type presets
 *   Controller_Cms::_blockAllow()        per-page-type chooser allowlist
 *   Controller_Cms::_pageTypeLabelMap()  page-type labels / canonical key set
 *   Controller_CmsAjax (via Controller_Cms::CanonicalBlockTypes/PageTypes)
 *     the save-time allowlists — so the write side can never drift from the
 *     editor's vocabulary.
 *   DefaultFrontDoorBlocks()             the shipped front-door block list —
 *     read through Model_FrontDoor::GetContent() at render time and imported
 *     directly by the CMS seed migrations (see that method's note).
 *************************************************************************/

class CmsBlockRegistry
{
    /** Per-request memo (PHP-FPM resets statics between requests). */
    private static $_blockDefs = null;

    /** Per-request memo (PHP-FPM resets statics between requests). */
    private static $_pageTypeDefs = null;

    /**
     * The canonical block-type registry, in EDITOR ORDER (see the class note).
     *
     * @return array<string,array{label:string,group:string,dynamic:bool,icon:string,
     *                            description:string,addable:bool,
     *                            scopes:array<int,string>|null,starter_fields:array}>
     */
    public static function BlockDefs()
    {
        if (self::$_blockDefs !== null) {
            return self::$_blockDefs;
        }

        self::$_blockDefs = array(
            // ---- Shipped front-door blocks -------------------------------
            'marketing_nav' => array(
                'label'          => 'Marketing Nav',
                'group'          => 'Layout',
                'dynamic'        => false,
                'icon'           => 'fa-bars',
                'description'    => 'Top navigation bar with logo, menu links, and login / call-to-action buttons. Rendered automatically as site chrome — not added per page.',
                'addable'        => false,
                'scopes'         => null,
                'starter_fields' => array('logo' => array(), 'cta' => array('label' => '', 'href' => ''), 'login' => array('label' => '', 'href' => '')),
            ),
            'member_bar' => array(
                'label'          => 'Member Bar',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-user-shield',
                'description'    => 'Logged-in welcome strip with quick links to the viewer’s kingdom, Live Attendance, and Member Tools. Hidden from signed-out visitors.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array(),
            ),
            'hero_carousel' => array(
                'label'          => 'Hero Carousel',
                'group'          => 'Hero',
                'dynamic'        => false,
                'icon'           => 'fa-images',
                'description'    => 'Full-width rotating hero with slides, logo, and call-to-action buttons.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('autoplay_ms' => '', 'logo' => array(), 'slides' => array(), 'ctas' => array()),
            ),
            'richtext' => array(
                'label'          => 'Rich Text (legacy)',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-align-left',
                'description'    => 'Legacy rich-text block. Prefer the newer Rich Text block for new pages.',
                'addable'        => false,
                'scopes'         => null,
                // Deliberately NO starter defaults: this type is not addable, so
                // nothing seeds one. Adding defaults here would be a UI change.
                'starter_fields' => array(),
            ),
            'card_grid' => array(
                'label'          => 'Card Grid',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-th-large',
                'description'    => 'Grid of cards, each with an image/icon, title, blurb, and link.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('kicker' => '', 'heading' => '', 'subheading' => '', 'cards' => array()),
            ),
            'steps' => array(
                'label'          => 'Steps / How-To',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-list-ol',
                'description'    => 'Numbered steps in a row — great for “How to join” style guides.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('kicker' => '', 'heading' => '', 'band' => 'light', 'cta' => array('label' => '', 'href' => ''), 'steps' => array()),
            ),
            'events_feed' => array(
                'label'          => 'Events Feed',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-calendar-day',
                'description'    => 'Shows the soonest upcoming events live across the org, as date cards linking to each event.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('kicker' => '', 'heading' => '', 'limit' => 3, 'more_href' => ''),
            ),
            'photo_mosaic' => array(
                'label'          => 'Photo Mosaic',
                'group'          => 'Media',
                'dynamic'        => false,
                'icon'           => 'fa-icons',
                'description'    => 'Asymmetric photo collage (first image large) with a caption tile.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('caption' => '', 'images' => array()),
            ),
            'kingdoms_teaser' => array(
                'label'          => 'Kingdoms Teaser',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-crown',
                'description'    => 'Live grid of active parent kingdoms with heraldry, linking to each kingdom profile.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('kicker' => '', 'heading' => '', 'limit' => 12, 'more_href' => ''),
            ),
            'cta_band' => array(
                'label'          => 'Call-to-Action Band',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-bullhorn',
                'description'    => 'Banner with a heading, subcopy, optional logo, and call-to-action buttons.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('heading' => '', 'subcopy' => '', 'logo' => array(), 'ctas' => array(), 'links' => ''),
            ),
            'staff_roster' => array(
                'label'          => 'Staff Roster',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-users',
                'description'    => 'A roster of people — photo, name, role, and bio, each optionally linked to their Amtgard persona.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('kicker' => '', 'heading' => 'Meet the Team', 'subheading' => '', 'presentation' => 'amtgard', 'people' => array()),
            ),

            // ---- New CMS block types from the spec (Phase 4 partials) -----
            'rich_text' => array(
                'label'          => 'Rich Text',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-paragraph',
                'description'    => 'Heading + formatted body text with an optional call-to-action.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('kicker' => '', 'heading' => '', 'body' => '', 'align' => 'left', 'cta' => array('label' => '', 'href' => '')),
            ),
            'heading' => array(
                'label'          => 'Heading',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-heading',
                'description'    => 'A standalone section heading (H2–H4) with alignment.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('text' => '', 'level' => 2, 'align' => 'left'),
            ),
            'divider' => array(
                'label'          => 'Divider',
                'group'          => 'Layout',
                'dynamic'        => false,
                'icon'           => 'fa-grip-lines',
                'description'    => 'A thin horizontal rule to separate sections.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('style' => 'line'),
            ),
            'spacer' => array(
                'label'          => 'Spacer',
                'group'          => 'Layout',
                'dynamic'        => false,
                'icon'           => 'fa-arrows-alt-v',
                'description'    => 'Vertical whitespace between blocks.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('size' => 'md'),
            ),
            'accordion' => array(
                'label'          => 'Accordion',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-chevron-circle-down',
                'description'    => 'Expandable question / answer (FAQ) items.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('items' => array()),
            ),
            'quote' => array(
                'label'          => 'Quote',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-quote-right',
                'description'    => 'A pull-quote with an optional attribution.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('text' => '', 'cite' => ''),
            ),
            'table' => array(
                'label'          => 'Table',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-table',
                'description'    => 'A simple data table with an optional caption and header row.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('caption' => '', 'header_first_row' => 1, 'rows' => array()),
            ),
            'image' => array(
                'label'          => 'Image',
                'group'          => 'Media',
                'dynamic'        => false,
                'icon'           => 'fa-image',
                'description'    => 'A single image with an optional caption and link.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('image' => array(), 'caption' => '', 'href' => '', 'align' => 'center', 'max_width' => ''),
            ),
            'gallery' => array(
                'label'          => 'Gallery',
                'group'          => 'Media',
                'dynamic'        => false,
                'icon'           => 'fa-images',
                'description'    => 'A multi-column grid of images.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('images' => array(), 'columns' => 3, 'caption' => ''),
            ),
            'video_embed' => array(
                'label'          => 'Video Embed',
                'group'          => 'Media',
                'dynamic'        => false,
                'icon'           => 'fa-play-circle',
                'description'    => 'An embedded YouTube or Vimeo video.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('provider' => 'youtube', 'video_id' => '', 'url' => '', 'caption' => ''),
            ),
            'file_download' => array(
                'label'          => 'File Download',
                'group'          => 'Content',
                'dynamic'        => false,
                'icon'           => 'fa-file-download',
                'description'    => 'A list of downloadable files with titles and metadata.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('files' => array()),
            ),
            'columns' => array(
                'label'          => 'Columns',
                'group'          => 'Layout',
                'dynamic'        => false,
                'icon'           => 'fa-columns',
                'description'    => 'Multiple side-by-side columns, each holding its own blocks.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('columns' => array()),
            ),
            'raw_html' => array(
                'label'          => 'Custom HTML (limited)',
                'group'          => 'Advanced',
                'dynamic'        => false,
                'icon'           => 'fa-code',
                'description'    => 'Custom HTML — script/style/iframe/form are stripped on save; use Video Embed for embeds.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('html' => ''),
            ),
            'blog_feed' => array(
                'label'          => 'Blog Feed',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-rss',
                'description'    => 'Shows the latest published blog posts live as linked cards. Optionally filtered to a single tag.',
                'addable'        => true,
                'scopes'         => null,
                'starter_fields' => array('heading' => '', 'limit' => 3, 'tag' => ''),
            ),

            // ---- Phase 4 org-scoped dynamic blocks (kingdom sites) -------
            // Pull live ORK data for the page's owning kingdom.
            'kingdom_officers' => array(
                'label'          => 'Officers (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-user-shield',
                'description'    => 'Live grid of the kingdom’s current officers from ORK data (office + persona). Pair with a Staff Roster for your Board of Directors.',
                'addable'        => true,
                'scopes'         => array('kingdom'),
                'starter_fields' => array('kicker' => '', 'heading' => '', 'limit' => 12),
            ),
            'kingdom_parks' => array(
                'label'          => 'Parks (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-map-marked-alt',
                'description'    => 'Live grid of the kingdom’s active parks (heraldry + name + city/state), sortable, each linking to its public park profile.',
                'addable'        => true,
                'scopes'         => array('kingdom'),
                'starter_fields' => array('kicker' => '', 'heading' => '', 'sort' => 'name', 'show_heraldry' => 0, 'limit' => 24, 'more_href' => ''),
            ),
            'kingdom_parks_map' => array(
                'label'          => 'Parks map (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-map',
                'description'    => 'Interactive map of the kingdom’s active parks with a click-to-open detail sidebar (heraldry, directions, description). Great placed above a Parks list.',
                'addable'        => true,
                'scopes'         => array('kingdom'),
                'starter_fields' => array('kicker' => '', 'heading' => 'Park Map'),
            ),
            'kingdom_events' => array(
                'label'          => 'Events (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-calendar-day',
                'description'    => 'Live list of the kingdom’s soonest upcoming events, as date cards linking to each event.',
                'addable'        => true,
                'scopes'         => array('kingdom'),
                'starter_fields' => array('kicker' => '', 'heading' => '', 'limit' => 3, 'more_href' => ''),
            ),

            // ---- Park-scoped dynamic blocks ------------------------------
            // The /p/{slug} counterparts of the four kingdom_* blocks above. A
            // park page is where someone searching for Amtgard actually lands, so
            // these answer "when do you meet, where, and who do I talk to" from
            // live ORK data instead of copy an officer has to remember to update.
            'park_meeting' => array(
                'label'          => 'Meeting times (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-map-marker-alt',
                'description'    => 'Live “when & where we meet” card set from the park’s ORK park-day records — recurrence, time, address, and a directions link. The single most useful block on a park page.',
                'addable'        => true,
                'scopes'         => array('park'),
                // show_map defaults ON: a meeting card without a directions link
                // is the block's whole point missed.
                'starter_fields' => array('kicker' => '', 'heading' => '', 'show_directions' => 0, 'show_map' => 1, 'limit' => 6),
            ),
            'park_officers' => array(
                'label'          => 'Officers (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-user-shield',
                'description'    => 'Live grid of the park’s current officers from ORK data (office + persona). Pair with a Staff Roster for non-ORK roles.',
                'addable'        => true,
                'scopes'         => array('park'),
                'starter_fields' => array('kicker' => '', 'heading' => '', 'limit' => 12),
            ),
            'park_events' => array(
                'label'          => 'Events (live)',
                'group'          => 'Dynamic',
                'dynamic'        => true,
                'icon'           => 'fa-calendar-day',
                'description'    => 'Live list of the park’s soonest upcoming events, as date cards linking to each event.',
                'addable'        => true,
                'scopes'         => array('park'),
                'starter_fields' => array('kicker' => '', 'heading' => '', 'limit' => 3, 'more_href' => ''),
            ),
            'park_hero' => array(
                'label'          => 'Park hero (live)',
                'group'          => 'Hero',
                'dynamic'        => true,
                'icon'           => 'fa-shield-alt',
                'description'    => 'Crest-led hero built from the park’s own heraldry and colour, with its next game day. Designed to look finished with no photo — only 5 of 342 parks have one.',
                'addable'        => true,
                'scopes'         => array('park'),
                // No 'subcopy'. It was declared here and in the partial's docblock
                // but park_hero.tpl never read it, so it was an editor field that
                // could be filled in and would then publish nothing. The hero
                // already carries an eyebrow, the name, the location and the next
                // game day above two buttons; a further paragraph is what the
                // rich_text block below it is for. Dropped rather than wired up.
                'starter_fields' => array(
                    'kicker' => '', 'heading' => '',
                    'cta_label' => '', 'cta_href' => '',
                    'show_weather' => 1, 'placeholder_image' => array(),
                ),
            ),
        );

        return self::$_blockDefs;
    }

    /**
     * The canonical page-type registry, in EDITOR ORDER (see the class note).
     *
     * A `label` of null means the key is NOT a page type — it is a block-owner
     * context that only contributes an Add-block chooser list. 'post' (blog post
     * bodies) is the one such entry: it must never appear in the page-type
     * chooser, in the Type column's label map, or in the save-time page-type
     * allowlist. A `starters` of null means the type seeds no starter blocks.
     * An `extra_blocks` of null means the chooser list is computed elsewhere —
     * 'composed' is the kitchen sink, derived from every addable catalog entry.
     *
     * `label` + `description` are AUTHOR-FACING copy and the single source for
     * every place a type is chosen (the New-page type cards on the dashboard and
     * the page list, and the Type select in the editor rail). They used to be
     * two-part names — 'Composed / Landing', 'Article / Text', 'Resource /
     * Document' — whose first half was just the internal key title-cased. That
     * taught an author the developer's vocabulary and explained nothing, so each
     * type is now named for what the author GETS, with one plain line saying what
     * kind of page it makes.
     *
     * @return array<string,array{label:string|null,description:string|null,
     *                            starters:array<int,string>|null,
     *                            extra_blocks:array<int,string>|null}>
     */
    public static function PageTypeDefs()
    {
        if (self::$_pageTypeDefs !== null) {
            return self::$_pageTypeDefs;
        }

        self::$_pageTypeDefs = array(
            'composed' => array(
                'label'        => 'Landing page',
                'description'  => 'A page you build from blocks — a big hero image, rows of cards, photos, buttons. The most flexible type, and the one front pages use.',
                'starters'     => array('hero_carousel', 'rich_text', 'cta_band'),
                // The landing-page kitchen sink: every addable block, computed
                // from the catalog rather than enumerated (see _blockAllow).
                'extra_blocks' => null,
            ),
            'article' => array(
                'label'    => 'Article',
                'description' => 'A page of writing: a heading and formatted text, with tables, downloads or images dropped in where you need them.',
                'starters' => array('heading', 'rich_text'),
                // Long-form content + inline media + supporting layout.
                'extra_blocks' => array('accordion', 'table', 'file_download', 'video_embed', 'gallery', 'columns'),
            ),
            'media' => array(
                'label'    => 'Photo gallery',
                'description' => 'A page led by pictures — galleries and photo mosaics, with headings between them.',
                'starters' => array('heading', 'gallery'),
                // Image-led blocks.
                'extra_blocks' => array('gallery', 'photo_mosaic', 'video_embed', 'card_grid'),
            ),
            'about' => array(
                'label'    => 'About us',
                'description' => 'Who you are and who runs things: your story, plus a roster of officers or staff with photos and roles.',
                'starters' => array('rich_text', 'staff_roster'),
                // A people roster plus supporting content blocks.
                'extra_blocks' => array('staff_roster', 'kingdom_officers', 'kingdom_parks', 'kingdom_parks_map', 'park_officers', 'park_meeting', 'card_grid', 'cta_band', 'gallery'),
            ),
            'resource' => array(
                'label'    => 'Documents & downloads',
                'description' => 'A place to hand people files — rules PDFs, forms, waivers — with tables and a questions list around them.',
                'starters' => array('heading', 'file_download'),
                // Downloads + tabular/structured reference.
                'extra_blocks' => array('file_download', 'table', 'accordion', 'columns'),
            ),
            'blog_index' => array(
                'label'    => 'News index',
                'description' => 'The page that lists your news posts, newest first. You write the posts themselves under Posts.',
                'starters' => array('heading', 'blog_feed'),
                // The live post feed, with an optional call-to-action.
                'extra_blocks' => array('blog_feed', 'cta_band'),
            ),
            'dynamic' => array(
                'label'    => 'Live ORK data',
                'description' => 'A page that fills itself in from the ORK — your parks, your officers, your upcoming events. Nothing to type or keep up to date.',
                'starters' => array('kingdoms_teaser'),
                // Every live feed, plus framing blocks. The global
                // events_feed (org-wide, all kingdoms) is dropped from the
                // chooser in favor of the scope-correct kingdom_events; existing
                // events_feed blocks still render and stay editable (the chooser
                // list never governs what already sits on a page).
                'extra_blocks' => array(
                    'kingdoms_teaser', 'blog_feed',
                    'kingdom_officers', 'kingdom_parks', 'kingdom_parks_map', 'kingdom_events',
                    'park_meeting', 'park_officers', 'park_events',
                    'member_bar', 'card_grid', 'cta_band',
                ),
            ),
            // NOT a page type (label null): blog post bodies, which behave like
            // articles in the Add-block chooser and nowhere else.
            'post' => array(
                'label'        => null,
                'description'  => null,
                'starters'     => null,
                'extra_blocks' => array('accordion', 'table', 'file_download', 'video_embed', 'gallery', 'columns'),
            ),
        );

        return self::$_pageTypeDefs;
    }

    /**
     * The front door's DEFAULT block list, in ascending 'order' (10..100).
     *
     * The one authoritative definition of the shipped front-door content: the
     * block list, its ordering, its enabled flags and its authored-vs-dynamic
     * source classification. Model_FrontDoor::GetContent() is a pass-through to
     * this method, and the CMS seed migrations import the same array, so the
     * unseeded-install fallback and the seeded CmsNav/CmsPage stores cannot
     * drift from separate copies.
     *
     * MIRRORED BY MIGRATIONS — do NOT shrink or delete any block literal below.
     * These literals are BOTH the seed source for, and the unseeded-install
     * fallback of, the editable CmsNav/CmsPage stores. marketing_nav.tpl only
     * prefers the CmsNav store when GetMenu() comes back non-empty, so on an
     * install that has not run the seeds the 'marketing_nav' items array IS the
     * rendered nav (cf. commit e16300b7 — blank document root).
     * Keep in sync with:
     *   db-migrations/2026-06-23-cms-seed-nav.php
     *   db-migrations/2026-07-08-cms-seed-amtgard.php
     *   db-migrations/2026-06-23-cms-nav-relink.php
     *   db-migrations/2026-07-08-cms-nav-relink-amtgard.php
     *   db-migrations/2026-08-09-cms-nav-ork-login-label.php
     *
     * Unlike BlockDefs()/PageTypeDefs() this reads the bootstrap constants
     * HTTP_TEMPLATE and UIR to build host-correct asset and route URLs (the CLI
     * seeds define CLI-time stand-ins before calling it). Still no DB, no
     * filesystem, no request state.
     *
     * $ctx: ['logged_in'=>bool, 'kingdom_id'=>int, ...] — reserved for future scoping.
     *
     * @return array<int,array{id:string,type:string,enabled:bool,order:int,source:string,fields:array}>
     */
    public static function DefaultFrontDoorBlocks($ctx = array())
    {
        // Asset base, forced ROOT-RELATIVE. These URLs are persisted verbatim
        // into seeded rows (ork_cms_block.fields_json / ork_cms_revision), so an
        // absolute base bakes the seed machine's origin into content served from
        // every environment — the CLI bootstrap's HTTP_HOST=localhost:19080
        // stand-in reached staging's DB exactly this way. Same reasoning as the
        // bootstrap's host-agnostic UIR; parse_url strips scheme+host and is a
        // no-op when the constant is already relative.
        $img  = (parse_url(HTTP_TEMPLATE, PHP_URL_PATH) ?: '/orkui/template/') . 'default/img/frontdoor/';
        $logo = array('key' => 'logo', 'src' => $img . 'amtgard-logo.png', 'alt' => 'Amtgard');

        // Internal route base, root-relative for the same persisted-verbatim
        // reason. Internal nav links resolve through it; external links point at
        // the live amtgard.com pages until those sections exist as CMS pages.
        // (This is the fallback / seed source; the live menu lives in the
        // editable CmsNav 'marketing' store.)
        $uir = defined('UIR') ? UIR : '/orkui/index.php?Route=';
        $_u  = parse_url($uir);
        if (!empty($_u['host'])) {
            $uir = ($_u['path'] ?? '/orkui/index.php') . (isset($_u['query']) ? '?' . $_u['query'] : '');
        }

        $blocks = array();

        $blocks[] = array(
            'id'      => 'nav',
            'type'    => 'marketing_nav',
            'enabled' => true,
            'order'   => 10,
            'source'  => 'authored',
            'fields'  => array(
                'logo'  => $logo,
                'items' => array(
                    array('label' => 'Home', 'href' => $uir),
                    array(
                        'label'    => 'About',
                        'href'     => $uir . 'Page/view/about',
                        'children' => array(
                            array('label' => 'Mission', 'href' => 'https://www.amtgard.com/mission'),
                            array('label' => 'Staff', 'href' => 'https://www.amtgard.com/staff'),
                            array('label' => 'Volunteers', 'href' => 'https://www.amtgard.com/volunteers'),
                        ),
                    ),
                    array(
                        'label'    => 'Join',
                        'href'     => $uir . 'Page/view/join',
                        'children' => array(
                            array('label' => 'Learn the Basics', 'href' => 'https://www.amtgard.com/learn-the-basics'),
                            array('label' => 'Find a Chapter', 'href' => $uir . 'Atlas'),
                            array('label' => 'Start a Chapter', 'href' => 'https://www.amtgard.com/start-a-chapter'),
                        ),
                    ),
                    array(
                        'label'    => 'AI Programs',
                        'href'     => 'https://www.amtgard.com/programs',
                        'children' => array(
                            array('label' => 'Food Fight', 'href' => 'https://www.amtgard.com/foodfight'),
                            array('label' => 'Olympiad', 'href' => 'https://www.amtgard.com/olympiad'),
                        ),
                    ),
                    array(
                        'label'    => 'Media',
                        'href'     => $uir . 'Page/view/media-gallery',
                        'children' => array(
                            array('label' => 'Galleries', 'href' => $uir . 'Page/view/media-gallery'),
                            array('label' => 'Writing', 'href' => $uir . 'Blog/index'),
                        ),
                    ),
                    array(
                        'label'    => 'Official Resources',
                        'href'     => 'https://www.amtgard.com/resources',
                        'children' => array(
                            array('label' => 'Documents', 'href' => 'https://www.amtgard.com/documents'),
                        ),
                    ),
                    array('label' => 'Merch', 'href' => 'https://www.redbubble.com/people/amtgardmarket/shop'),
                ),
                'cta'   => array('label' => 'Find a Chapter', 'href' => $uir . 'Atlas'),
                'login' => array('label' => 'ORK Login', 'href' => $uir . 'Directory'),
            ),
        );

        $blocks[] = array(
            'id'      => 'member',
            'type'    => 'member_bar',
            'enabled' => true,
            'order'   => 20,
            'source'  => 'dynamic',
            'fields'  => array(),
        );

        $blocks[] = array(
            'id'      => 'hero',
            'type'    => 'hero_carousel',
            'enabled' => true,
            'order'   => 30,
            'source'  => 'authored',
            'fields'  => array(
                'logo'        => $logo,
                'autoplay_ms' => 4500,
                'slides'      => array(
                    array(
                        'image'    => array('key' => 'hero-1', 'src' => $img . 'hero-1.jpg', 'alt' => ''),
                        'kicker'   => 'Worldwide Medieval Combat · Since 1983',
                        'headline' => 'Take the Field.',
                        'subcopy'  => 'Safe boffer weapons, real glory. Step into a living world of heroic combat, quests, and craft.',
                    ),
                    array(
                        'image'    => array('key' => 'hero-2', 'src' => $img . 'hero-2.jpg', 'alt' => ''),
                        'kicker'   => 'Archery · Magic · Steel',
                        'headline' => 'Find Your Path.',
                        'subcopy'  => 'Warrior, archer, healer, monster, crafter — there\'s a place for every kind of hero.',
                    ),
                    array(
                        'image'    => array('key' => 'hero-7', 'src' => $img . 'hero-7.jpg', 'alt' => ''),
                        'kicker'   => 'From First-Timers to Great Wars',
                        'headline' => 'Answer the Call.',
                        'subcopy'  => 'Hundreds of chapters worldwide. Your first day on the field is always free.',
                    ),
                ),
                'ctas'        => array(
                    array('label' => 'Find Amtgard Near You', 'href' => $uir . 'Atlas', 'style' => 'gold'),
                    array('label' => 'Watch & Learn', 'href' => $uir . 'Page/view/learn-the-basics', 'style' => 'ghost'),
                ),
            ),
        );

        $blocks[] = array(
            'id'      => 'whatis',
            'type'    => 'richtext',
            'enabled' => true,
            'order'   => 40,
            'source'  => 'authored',
            'fields'  => array(
                'kicker'  => 'New here?',
                'heading' => 'What is Amtgard?',
                'align'   => 'center',
                'body'    => 'Amtgard is a world-wide organization dedicated to medieval and fantasy combat sports and recreation. We use padded weapons, fantasy and authentic clothing, and imagination to immerse players in a world of heroic combat, quests, crafts, and more.',
                'cta'     => array('label' => 'The full story →', 'href' => $uir . 'Page/view/about'),
            ),
        );

        $blocks[] = array(
            'id'      => 'paths',
            'type'    => 'card_grid',
            'enabled' => true,
            'order'   => 50,
            'source'  => 'authored',
            'fields'  => array(
                'kicker'     => 'There\'s a place for you',
                'heading'    => 'Find Your Path',
                'subheading' => 'However you like to play, Amtgard has a role for you.',
                'cards'      => array(
                    array(
                        'image' => array('key' => 'hero-1', 'src' => $img . 'hero-1.jpg', 'alt' => ''),
                        'icon'  => 'fa-shield-alt',
                        'title' => 'The Warrior',
                        'blurb' => 'Sword, shield, and the front line',
                        'href'  => $uir . 'Page/view/learn-the-basics',
                    ),
                    array(
                        'image' => array('key' => 'hero-2', 'src' => $img . 'hero-2.jpg', 'alt' => ''),
                        'icon'  => 'fa-bullseye',
                        'title' => 'The Archer',
                        'blurb' => 'Ranged skill and battlefield control',
                        'href'  => $uir . 'Page/view/learn-the-basics',
                    ),
                    array(
                        'image' => array('key' => 'hero-5', 'src' => $img . 'hero-5.jpg', 'alt' => ''),
                        'icon'  => 'fa-hat-wizard',
                        'title' => 'The Caster',
                        'blurb' => 'Spells, healing, and the magic classes',
                        'href'  => $uir . 'Page/view/learn-the-basics',
                    ),
                    array(
                        'image' => array('key' => 'hero-6', 'src' => $img . 'hero-6.jpg', 'alt' => ''),
                        'icon'  => 'fa-palette',
                        'title' => 'The Artisan',
                        'blurb' => 'Garb, armor, and craft (A&S)',
                        'href'  => $uir . 'Page/view/learn-the-basics',
                    ),
                    array(
                        'image' => array('key' => 'hero-3', 'src' => $img . 'hero-3.jpg', 'alt' => ''),
                        'icon'  => 'fa-dragon',
                        'title' => 'The Monster',
                        'blurb' => 'Quests, role-play, and the wilds',
                        'href'  => $uir . 'Page/view/learn-the-basics',
                    ),
                    array(
                        'image' => array('key' => 'hero-8', 'src' => $img . 'hero-8.jpg', 'alt' => ''),
                        'icon'  => 'fa-crown',
                        'title' => 'The Leader',
                        'blurb' => 'Reeving, office, and running the realm',
                        'href'  => $uir . 'Page/view/join',
                    ),
                ),
            ),
        );

        $blocks[] = array(
            'id'      => 'firstday',
            'type'    => 'steps',
            'enabled' => true,
            'order'   => 60,
            'source'  => 'authored',
            'fields'  => array(
                'kicker'  => 'It\'s easier than you think',
                'heading' => 'Your First Day',
                'band'    => 'dark',
                'steps'   => array(
                    array('n' => 1, 'title' => 'Find a chapter', 'body' => 'Hundreds of parks meet weekly in public spaces. Find one near you.'),
                    array('n' => 2, 'title' => 'Just show up', 'body' => 'No experience or gear needed. Wear comfy clothes and bring water.'),
                    array('n' => 3, 'title' => 'Borrow a sword', 'body' => 'Chapters have loaner weapons. Take the field — your first day is free.'),
                ),
                'cta'     => array('label' => 'Find Amtgard Near You', 'href' => $uir . 'Atlas'),
            ),
        );

        $blocks[] = array(
            'id'      => 'events',
            'type'    => 'events_feed',
            'enabled' => true,
            'order'   => 70,
            'source'  => 'dynamic',
            'fields'  => array(
                'kicker'    => 'Come check one out',
                'heading'   => 'Upcoming Events',
                'limit'     => 3,
                'more_href' => $uir . 'Search/event',
            ),
        );

        $blocks[] = array(
            'id'      => 'mosaic',
            'type'    => 'photo_mosaic',
            'enabled' => true,
            'order'   => 80,
            'source'  => 'authored',
            'fields'  => array(
                'caption' => 'This is Amtgard',
                'images'  => array(
                    array('key' => 'hero-7', 'src' => $img . 'hero-7.jpg', 'alt' => ''),
                    array('key' => 'hero-4', 'src' => $img . 'hero-4.jpg', 'alt' => ''),
                    array('key' => 'hero-6', 'src' => $img . 'hero-6.jpg', 'alt' => ''),
                    array('key' => 'hero-3', 'src' => $img . 'hero-3.jpg', 'alt' => ''),
                ),
            ),
        );

        $blocks[] = array(
            'id'      => 'kingdoms',
            'type'    => 'kingdoms_teaser',
            'enabled' => true,
            'order'   => 90,
            'source'  => 'dynamic',
            'fields'  => array(
                'kicker'    => 'Explore the realm',
                'heading'   => 'Kingdoms Around the World',
                'limit'     => 12,
                'more_href' => $uir . 'Directory/index',
            ),
        );

        $blocks[] = array(
            'id'      => 'getinvolved',
            'type'    => 'cta_band',
            'enabled' => true,
            'order'   => 100,
            'source'  => 'authored',
            'fields'  => array(
                'logo'    => $logo,
                'heading' => 'Ready to take up arms?',
                'subcopy' => 'There\'s a chapter near you, and your first day on the field is always free.',
                'ctas'    => array(
                    array('label' => 'Find Amtgard Near You', 'href' => $uir . 'Atlas', 'style' => 'gold'),
                    array('label' => 'Official Resources', 'href' => $uir . 'Page/view/resources', 'style' => 'ghost'),
                ),
                'links'   => 'amtgard.com · play.amtgard.com · Online Record Keeper',
            ),
        );

        // Already emitted in ascending 'order' (10..100); the CMS reorders via
        // 'order' on the stored copy, not here.
        return $blocks;
    }
}
