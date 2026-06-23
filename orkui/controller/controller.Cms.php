<?php

/**
 * Controller_Cms — CMS admin (page-rendering surfaces).
 *
 * Routes:
 *   Cms/index            → page list (any CMS capability / super-admin)
 *   Cms/edit/{id|new}    → block editor for a page (page.edit, or page.create for new)
 *   Cms/preview/{id}     → render the page's CURRENT draft blocks with a preview banner (page.edit)
 *
 * Auth: every action gates on CmsAuth->cms_can($uid, <capability>, GLOBAL_SCOPE).
 * v2 is global scope only (the data model carries scope_type/scope_id for later).
 * Unauthorized / not-logged-in → redirect to Login (page surfaces never emit JSON).
 *
 * Conventions: thin controller (no raw $DB; all DB work via the CmsPage lib).
 * Templates are PLAIN PHP (extract()+include), set via $this->template.
 */
class Controller_Cms extends Controller
{
    /** v2 scope: org-wide. */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    /**
     * Per-request capability cache: ['is_super' => bool, 'caps' => string[]].
     * Keyed by uid so a single request can't bleed between users.
     * @var array
     */
    private $_capCache = array();

    public function __construct($call = null, $action = null)
    {
        parent::__construct($call, $action);
        // CMS admin is an org-level surface — drop the kingdom/park crumbs.
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->load_model('CmsAuth');
        $this->load_model('CmsPage');
        $this->load_model('CmsPost');
        $this->load_model('CmsNav');
    }

    /* ------------------------------------------------------------------ *
     * Page list
     * ------------------------------------------------------------------ */

    public function index($action = null)
    {
        $uid = $this->_uid();
        // The list is visible to anyone holding ANY CMS capability (or super-admin).
        if (!$this->_hasAnyCmsCapability($uid)) {
            return $this->_denyRedirect();
        }

        $this->template = 'Cms_index.tpl';
        $this->data['page_title'] = 'Content Management';

        $filters = array();
        $search = trim((string)($_GET['q'] ?? ''));
        if ($search !== '') {
            $filters['search'] = $search;
        }
        $status = trim((string)($_GET['status'] ?? ''));
        if ($status === 'draft' || $status === 'published') {
            $filters['status'] = $status;
        }

        $this->data['Pages']      = $this->CmsPage->list_pages($filters);
        $this->data['Search']     = $search;
        $this->data['StatusFilter'] = $status;

        // Capability flags the list UI uses to show/hide actions.
        $this->data['Caps'] = $this->_capFlags($uid);
    }

    /* ------------------------------------------------------------------ *
     * Block editor
     * ------------------------------------------------------------------ */

    public function edit($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid    = $this->_uid();
        $id     = (string)$id;
        $isNew  = ($id === 'new' || $id === '' || $id === '0');
        $needed = $isNew ? 'page.create' : 'page.edit';

        if (!$this->CmsAuth->cms_can($uid, $needed, self::$SCOPE)) {
            return $this->_denyRedirect();
        }

        $this->template = 'Cms_edit.tpl';

        if ($isNew) {
            $page   = array(
                'page_id'          => 0,
                'slug'             => '',
                'type'             => 'composed',
                'title'            => '',
                'status'           => 'draft',
                'published_at'     => null,
                'hero_media_id'    => null,
                'meta_description' => '',
                'is_system'        => 0,
                'scope_type'       => 'global',
                'scope_id'         => 0,
            );
            $blocks = array();
            $this->data['page_title'] = 'New Page';
        } else {
            $page = $this->CmsPage->get_page((int)$id);
            if (empty($page)) {
                // No such page — fall back to the list with a message.
                $this->template = 'Cms_index.tpl';
                $this->data['page_title'] = 'Content Management';
                $this->data['Pages']  = $this->CmsPage->list_pages(array());
                $this->data['Search'] = '';
                $this->data['StatusFilter'] = '';
                $this->data['Caps'] = $this->_capFlags($uid);
                $this->data['Message'] = 'Page not found.';
                return;
            }
            // Editing an existing page returns ALL its blocks (incl. disabled) so
            // the editor can toggle them; the public renderer filters to enabled.
            $blocks = $this->CmsPage->get_blocks('page', (int)$page['page_id']);
            $this->data['page_title'] = 'Edit: ' . $page['title'];
        }

        $this->data['Page']         = $page;
        $this->data['Blocks']       = $blocks;
        $this->data['IsNew']        = $isNew;
        $this->data['BlockCatalog'] = $this->_blockCatalog();
        $this->data['PageTypes']    = $this->_pageTypes();
        $this->data['Caps']         = $this->_capFlags($uid);
    }

    /* ------------------------------------------------------------------ *
     * Draft preview
     * ------------------------------------------------------------------ */

    public function preview($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid = $this->_uid();
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', self::$SCOPE)) {
            return $this->_denyRedirect();
        }

        $this->template = 'Cms_preview.tpl';
        $this->data['IsFrontDoor'] = false;
        $this->data['no_index']    = true;

        $page = $this->CmsPage->get_page((int)$id);
        if (empty($page)) {
            $this->data['Message']    = 'Page not found.';
            $this->data['page_title'] = 'Preview — not found';
            $this->data['FrontDoor']  = array();
            $this->data['PreviewPage'] = null;
            return;
        }

        // Preview renders the CURRENT (draft) enabled blocks via the shared renderer.
        $this->data['FrontDoor']   = $this->CmsPage->get_page_blocks((int)$page['page_id']);
        $this->data['PreviewPage'] = $page;
        $this->data['page_title']  = 'Preview: ' . $page['title'];
    }

    /* ------------------------------------------------------------------ *
     * Blog posts — list
     * ------------------------------------------------------------------ */

    public function posts($action = null)
    {
        $uid = $this->_uid();
        // Same gate as the page list: visible to anyone holding ANY CMS capability.
        if (!$this->_hasAnyCmsCapability($uid)) {
            return $this->_denyRedirect();
        }

        $this->template = 'Cms_posts.tpl';
        $this->data['page_title'] = 'Blog Posts';

        $opts = array('includeDrafts' => true, 'scope_type' => 'global', 'scope_id' => 0);
        $tag = trim((string)($_GET['tag'] ?? ''));
        if ($tag !== '') {
            $opts['tag'] = $tag;
        }

        $result = $this->CmsPost->list_posts($opts);
        $rows   = (is_array($result) && isset($result['rows']) && is_array($result['rows'])) ? $result['rows'] : array();

        $this->data['Posts']     = $rows;
        $this->data['TagFilter'] = $tag;
        $this->data['AllTags']   = $this->CmsPost->list_all_tags();
        $this->data['Caps']      = $this->_capFlags($uid);
    }

    /* ------------------------------------------------------------------ *
     * Navigation management (the 'marketing' menu)
     * ------------------------------------------------------------------ */

    public function nav($action = null)
    {
        $uid = $this->_uid();
        // Navigation management is an admin-only capability.
        if (!$this->CmsAuth->cms_can($uid, 'nav.manage', self::$SCOPE)) {
            return $this->_denyRedirect();
        }

        $this->template = 'Cms_nav.tpl';
        $this->data['page_title'] = 'Navigation';

        // The flat item list (incl. disabled) the admin tree is built from.
        $items = $this->CmsNav->list_items('marketing', 'global', 0);
        $this->data['Menu']     = 'marketing';
        $this->data['NavItems'] = is_array($items) ? $items : array();

        // Link-picker source lists: published + draft pages, and posts.
        $pages = $this->CmsPage->list_pages(array());
        $this->data['PickerPages'] = is_array($pages) ? $pages : array();

        $postsRes = $this->CmsPost->list_posts(array('includeDrafts' => true, 'scope_type' => 'global', 'scope_id' => 0));
        $postRows = (is_array($postsRes) && isset($postsRes['rows']) && is_array($postsRes['rows'])) ? $postsRes['rows'] : array();
        $this->data['PickerPosts'] = $postRows;

        $this->data['Caps'] = $this->_capFlags($uid);
    }

    /* ------------------------------------------------------------------ *
     * Blog posts — editor
     * ------------------------------------------------------------------ */

    public function editpost($id = null)
    {
        if (func_num_args() === 0) {
            return parent::view();
        }

        $uid    = $this->_uid();
        $id     = (string)$id;
        $isNew  = ($id === 'new' || $id === '' || $id === '0');
        $needed = $isNew ? 'page.create' : 'page.edit';

        if (!$this->CmsAuth->cms_can($uid, $needed, self::$SCOPE)) {
            return $this->_denyRedirect();
        }

        $this->template = 'Cms_editpost.tpl';

        if ($isNew) {
            $post = array(
                'post_id'       => 0,
                'slug'          => '',
                'title'         => '',
                'excerpt'       => '',
                'status'        => 'draft',
                'published_at'  => null,
                'hero_media_id' => null,
                'author_id'     => $uid,
                'author_name'   => '',
                'scope_type'    => 'global',
                'scope_id'      => 0,
                'tags'          => array(),
            );
            $blocks = array();
            $heroRef = null;
            $this->data['page_title'] = 'New Post';
        } else {
            $post = $this->CmsPost->get_post((int)$id);
            if (empty($post)) {
                // No such post — fall back to the post list with a message.
                $this->template = 'Cms_posts.tpl';
                $this->data['page_title'] = 'Blog Posts';
                $listed = $this->CmsPost->list_posts(array('includeDrafts' => true));
                $this->data['Posts']     = (is_array($listed) && isset($listed['rows'])) ? $listed['rows'] : array();
                $this->data['TagFilter'] = '';
                $this->data['AllTags']   = $this->CmsPost->list_all_tags();
                $this->data['Caps']      = $this->_capFlags($uid);
                $this->data['Message']   = 'Post not found.';
                return;
            }
            $blocks  = $this->CmsPost->get_post_blocks((int)$post['post_id']);
            $heroRef = $this->_heroRef($post);
            $this->data['page_title'] = 'Edit: ' . $post['title'];
        }

        $this->data['Post']         = $post;
        $this->data['Blocks']       = $blocks;
        $this->data['IsNew']        = $isNew;
        $this->data['HeroRef']      = $heroRef;
        $this->data['BlockCatalog'] = $this->_blockCatalog();
        $this->data['Caps']         = $this->_capFlags($uid);
    }

    /**
     * Resolve a post's hero image (hero_media_id) to a media-ref the editor's
     * image picker understands, or null when none is set / cannot be resolved.
     */
    private function _heroRef($post)
    {
        $mediaId = isset($post['hero_media_id']) ? (int)$post['hero_media_id'] : 0;
        if ($mediaId <= 0) {
            return null;
        }
        $this->load_model('CmsMedia');
        $row = $this->CmsMedia->get_media($mediaId);
        if (empty($row)) {
            return null;
        }
        return $this->CmsMedia->to_media_ref($row);
    }

    /* ------------------------------------------------------------------ *
     * Internal helpers
     * ------------------------------------------------------------------ */

    private function _uid()
    {
        return isset($this->session->user_id) ? (int)$this->session->user_id : 0;
    }

    /**
     * True when the user holds at least one CMS capability at global scope
     * (covers super-admin via _resolveCapabilities short-circuit).
     */
    private function _hasAnyCmsCapability($uid)
    {
        if ($uid <= 0) {
            return false;
        }
        $resolved = $this->_resolveCapabilities($uid);
        if ($resolved['is_super']) {
            return true;
        }
        return !empty($resolved['caps']);
    }

    /**
     * Per-capability boolean map for templates (show/hide editor buttons).
     */
    private function _capFlags($uid)
    {
        $resolved = $this->_resolveCapabilities($uid);
        $isSuper  = $resolved['is_super'];
        $caps     = $resolved['caps'];
        return array(
            'create'  => $isSuper || in_array('page.create', $caps, true),
            'edit'    => $isSuper || in_array('page.edit', $caps, true),
            'publish' => $isSuper || in_array('page.publish', $caps, true),
            'delete'  => $isSuper || in_array('page.delete', $caps, true),
            'media'   => $isSuper || in_array('media.manage', $caps, true),
            'nav'     => $isSuper || in_array('nav.manage', $caps, true),
            'roles'   => $isSuper || in_array('roles.manage', $caps, true),
        );
    }

    /**
     * Resolve a user's CMS capabilities ONCE per request (cached by uid).
     *
     * Issues exactly 2 DB queries total (1 IsSuperAdmin + 1 GetUserGrants),
     * versus the prior O(N) loop that fired up to ~24 queries (8 caps ×
     * IsSuperAdmin + GetUserGrants each). All callers do in_array() in memory.
     *
     * Big-O: O(G × R) per request, G = grant rows, R = roles/caps (both tiny,
     * single-digit in practice); previously O(N) DB round-trips where N = caps.
     *
     * @param int $uid mundane_id
     * @return array{is_super:bool, caps:string[]}
     */
    private function _resolveCapabilities($uid)
    {
        $uid = (int)$uid;
        if (isset($this->_capCache[$uid])) {
            return $this->_capCache[$uid];
        }

        // One HasAuthority query (super-admin short-circuit).
        $isSuper = ($uid > 0) && (bool)$this->CmsAuth->IsSuperAdmin($uid);

        // One GetUserGrants query + in-memory role expansion.
        // Skip for super-admins — they pass every cap already.
        $caps = ($uid > 0 && !$isSuper)
            ? $this->CmsAuth->get_user_capabilities($uid, self::$SCOPE)
            : array();

        $resolved = array('is_super' => $isSuper, 'caps' => $caps);
        $this->_capCache[$uid] = $resolved;
        return $resolved;
    }

    /**
     * Not-permitted / not-logged-in → bounce to Login (page surfaces don't
     * emit JSON). We still let view() run, but the redirect header wins.
     */
    private function _denyRedirect()
    {
        header('X-Robots-Tag: noindex, nofollow');
        header('Location: ' . UIR . 'Login');
        // Set a minimal template so view() has something harmless to render
        // if headers were already flushed (shouldn't happen in normal flow).
        $this->template = 'Cms_index.tpl';
        $this->data['Pages']  = array();
        $this->data['Search'] = '';
        $this->data['StatusFilter'] = '';
        $this->data['Caps'] = array();
        $this->data['Message'] = 'Not authorized.';
        return;
    }

    /**
     * The block catalog the editor offers. Derived from the partials actually
     * present in frontdoor/blocks/ (authoritative `available` flag) UNION the
     * spec's named catalog (so future block types appear as "coming soon").
     *
     * Each entry: ['type','label','group','available','dynamic'].
     */
    private function _blockCatalog()
    {
        // Human labels + grouping + whether the block pulls dynamic data.
        $known = array(
            // Shipped front-door blocks.
            'marketing_nav'   => array('Marketing Nav',     'Layout',   false),
            'member_bar'      => array('Member Bar',        'Layout',   true),
            'hero_carousel'   => array('Hero Carousel',     'Hero',     false),
            'richtext'        => array('Rich Text (legacy)', 'Content',  false),
            'card_grid'       => array('Card Grid',         'Content',  false),
            'steps'           => array('Steps / How-To',    'Content',  false),
            'events_feed'     => array('Events Feed',       'Dynamic',  true),
            'photo_mosaic'    => array('Photo Mosaic',      'Media',    false),
            'kingdoms_teaser' => array('Kingdoms Teaser',   'Dynamic',  true),
            'cta_band'        => array('Call-to-Action Band', 'Content', false),
            // New CMS block types from the spec (Phase 4 partials).
            'rich_text'       => array('Rich Text',         'Content',  false),
            'heading'         => array('Heading',           'Content',  false),
            'divider'         => array('Divider',           'Layout',   false),
            'spacer'          => array('Spacer',            'Layout',   false),
            'accordion'       => array('Accordion',         'Content',  false),
            'quote'           => array('Quote',             'Content',  false),
            'table'           => array('Table',             'Content',  false),
            'image'           => array('Image',             'Media',    false),
            'gallery'         => array('Gallery',           'Media',    false),
            'video_embed'     => array('Video Embed',       'Media',    false),
            'file_download'   => array('File Download',     'Content',  false),
            'columns'         => array('Columns',           'Layout',   false),
            'raw_html'        => array('Raw HTML',          'Advanced', false),
            'stat_ticker'     => array('Stat Ticker',       'Dynamic',  true),
            'tournaments_feed' => array('Tournaments Feed', 'Dynamic',  true),
            'recap_highlight' => array('Recap Highlight',   'Dynamic',  true),
            'blog_feed'       => array('Blog Feed',         'Dynamic',  true),
        );

        $blockDir = DIR_TEMPLATE . 'default/frontdoor/blocks/';

        $catalog = array();
        foreach ($known as $type => $meta) {
            $partial   = $blockDir . preg_replace('/[^a-z_]/', '', $type) . '.tpl';
            $available = file_exists($partial);
            $catalog[] = array(
                'type'      => $type,
                'label'     => $meta[0],
                'group'     => $meta[1],
                'dynamic'   => (bool)$meta[2],
                'available' => $available,
            );
        }
        return $catalog;
    }

    /**
     * Page-type presets (editor hint → starting block set). Mirrors the spec's
     * "Page types are editor presets" decision.
     *
     * Each entry: ['type','label','blocks'=>[<starter block objects>]], where a
     * starter block is a fully-formed block: ['type','enabled','source','fields'].
     * The editor seeds the block list from these when CREATING a new page of the
     * given type (and re-seeds when the type is switched on an empty new page).
     * `fields` carry sensible empty defaults matching each block's partial keys.
     */
    private function _pageTypes()
    {
        return array(
            array(
                'type'   => 'composed',
                'label'  => 'Composed / Landing',
                'blocks' => array(
                    $this->_starter('hero_carousel'),
                    $this->_starter('rich_text'),
                    $this->_starter('cta_band'),
                ),
            ),
            array(
                'type'   => 'article',
                'label'  => 'Article / Text',
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('rich_text'),
                ),
            ),
            array(
                'type'   => 'media',
                'label'  => 'Media / Gallery',
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('gallery'),
                ),
            ),
            array(
                'type'   => 'resource',
                'label'  => 'Resource / Document',
                'blocks' => array(
                    $this->_starter('heading'),
                    $this->_starter('file_download'),
                ),
            ),
            array(
                'type'   => 'blog_index',
                'label'  => 'Blog Index',
                'blocks' => array(
                    $this->_starter('heading'),
                ),
            ),
            array(
                'type'   => 'dynamic',
                'label'  => 'Dynamic Data',
                'blocks' => array(
                    $this->_starter('kingdoms_teaser'),
                ),
            ),
        );
    }

    /**
     * Build one starter block for a preset: a fully-formed block object with
     * sensible empty field defaults matching that block type's partial keys.
     *
     * @return array{type:string,enabled:int,source:string,fields:array}
     */
    private function _starter($type)
    {
        // Dynamic blocks (pull data at render time) are flagged source=dynamic.
        $dynamicTypes = array(
            'member_bar'      => true,
            'events_feed'     => true,
            'kingdoms_teaser' => true,
            'stat_ticker'     => true,
            'tournaments_feed' => true,
            'recap_highlight' => true,
            'blog_feed'       => true,
        );

        // Empty field defaults keyed to each partial's consumed fields.
        $defaults = array(
            'hero_carousel'   => array('autoplay_ms' => '', 'logo' => array(), 'slides' => array(), 'ctas' => array()),
            'rich_text'       => array('kicker' => '', 'heading' => '', 'body' => '', 'align' => 'left', 'cta' => array('label' => '', 'href' => '')),
            'cta_band'        => array('heading' => '', 'subcopy' => '', 'logo' => array(), 'ctas' => array(), 'links' => ''),
            'card_grid'       => array('kicker' => '', 'heading' => '', 'subheading' => '', 'cards' => array()),
            'heading'         => array('text' => '', 'level' => 2, 'align' => 'left'),
            'gallery'         => array('images' => array(), 'columns' => 3, 'caption' => ''),
            'file_download'   => array('files' => array()),
            'video_embed'     => array('provider' => 'youtube', 'video_id' => '', 'url' => '', 'caption' => ''),
            'accordion'       => array('items' => array()),
            'quote'           => array('text' => '', 'cite' => ''),
            'image'           => array('image' => array(), 'caption' => '', 'href' => '', 'align' => 'center', 'max_width' => ''),
            'kingdoms_teaser' => array(),
        );

        return array(
            'type'    => $type,
            'enabled' => 1,
            'source'  => isset($dynamicTypes[$type]) ? 'dynamic' : 'authored',
            'fields'  => isset($defaults[$type]) ? $defaults[$type] : array(),
        );
    }
}
