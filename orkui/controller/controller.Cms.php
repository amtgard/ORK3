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

    public function __construct($call = null, $action = null)
    {
        parent::__construct($call, $action);
        // CMS admin is an org-level surface — drop the kingdom/park crumbs.
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->load_model('CmsAuth');
        $this->load_model('CmsPage');
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
     * Internal helpers
     * ------------------------------------------------------------------ */

    private function _uid()
    {
        return isset($this->session->user_id) ? (int)$this->session->user_id : 0;
    }

    /**
     * True when the user holds at least one CMS capability at global scope
     * (covers super-admin via cms_can short-circuit).
     */
    private function _hasAnyCmsCapability($uid)
    {
        if ($uid <= 0) {
            return false;
        }
        $caps = array(
            'page.create', 'page.edit_own', 'page.edit', 'page.publish',
            'page.delete', 'media.manage', 'nav.manage', 'roles.manage',
        );
        foreach ($caps as $cap) {
            if ($this->CmsAuth->cms_can($uid, $cap, self::$SCOPE)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Per-capability boolean map for templates (show/hide editor buttons).
     */
    private function _capFlags($uid)
    {
        return array(
            'create'  => $this->CmsAuth->cms_can($uid, 'page.create', self::$SCOPE),
            'edit'    => $this->CmsAuth->cms_can($uid, 'page.edit', self::$SCOPE),
            'publish' => $this->CmsAuth->cms_can($uid, 'page.publish', self::$SCOPE),
            'delete'  => $this->CmsAuth->cms_can($uid, 'page.delete', self::$SCOPE),
            'media'   => $this->CmsAuth->cms_can($uid, 'media.manage', self::$SCOPE),
            'nav'     => $this->CmsAuth->cms_can($uid, 'nav.manage', self::$SCOPE),
            'roles'   => $this->CmsAuth->cms_can($uid, 'roles.manage', self::$SCOPE),
        );
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
     * Each entry: ['type','label','blocks'=>[default block types]].
     */
    private function _pageTypes()
    {
        return array(
            array('type' => 'composed', 'label' => 'Composed / Landing', 'blocks' => array('hero_carousel', 'card_grid', 'cta_band')),
            array('type' => 'article',  'label' => 'Article / Text',      'blocks' => array('heading', 'rich_text')),
            array('type' => 'media',    'label' => 'Media / Gallery',     'blocks' => array('heading', 'gallery')),
            array('type' => 'resource', 'label' => 'Resource / Document', 'blocks' => array('heading', 'file_download')),
            array('type' => 'blog_index', 'label' => 'Blog Index',        'blocks' => array('heading', 'blog_feed')),
            array('type' => 'dynamic',  'label' => 'Dynamic Data',        'blocks' => array('stat_ticker')),
        );
    }
}
