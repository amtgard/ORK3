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
                'starter_fields' => array(
                    'kicker' => '', 'heading' => '', 'subcopy' => '',
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
     * @return array<string,array{label:string|null,starters:array<int,string>|null,
     *                            extra_blocks:array<int,string>|null}>
     */
    public static function PageTypeDefs()
    {
        if (self::$_pageTypeDefs !== null) {
            return self::$_pageTypeDefs;
        }

        self::$_pageTypeDefs = array(
            'composed' => array(
                'label'        => 'Composed / Landing',
                'starters'     => array('hero_carousel', 'rich_text', 'cta_band'),
                // The landing-page kitchen sink: every addable block, computed
                // from the catalog rather than enumerated (see _blockAllow).
                'extra_blocks' => null,
            ),
            'article' => array(
                'label'    => 'Article / Text',
                'starters' => array('heading', 'rich_text'),
                // Long-form content + inline media + supporting layout.
                'extra_blocks' => array('accordion', 'table', 'file_download', 'video_embed', 'gallery', 'columns'),
            ),
            'media' => array(
                'label'    => 'Media / Gallery',
                'starters' => array('heading', 'gallery'),
                // Image-led blocks.
                'extra_blocks' => array('gallery', 'photo_mosaic', 'video_embed', 'card_grid'),
            ),
            'about' => array(
                'label'    => 'About / Team',
                'starters' => array('rich_text', 'staff_roster'),
                // A people roster plus supporting content blocks.
                'extra_blocks' => array('staff_roster', 'kingdom_officers', 'kingdom_parks', 'kingdom_parks_map', 'park_officers', 'park_meeting', 'card_grid', 'cta_band', 'gallery'),
            ),
            'resource' => array(
                'label'    => 'Resource / Document',
                'starters' => array('heading', 'file_download'),
                // Downloads + tabular/structured reference.
                'extra_blocks' => array('file_download', 'table', 'accordion', 'columns'),
            ),
            'blog_index' => array(
                'label'    => 'Blog Index',
                'starters' => array('heading', 'blog_feed'),
                // The live post feed, with an optional call-to-action.
                'extra_blocks' => array('blog_feed', 'cta_band'),
            ),
            'dynamic' => array(
                'label'    => 'Dynamic Data',
                'starters' => array('kingdoms_teaser'),
                // Every live feed, plus framing blocks. #65: the global
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
                'starters'     => null,
                'extra_blocks' => array('accordion', 'table', 'file_download', 'video_embed', 'gallery', 'columns'),
            ),
        );

        return self::$_pageTypeDefs;
    }
}
