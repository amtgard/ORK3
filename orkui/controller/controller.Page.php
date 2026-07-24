<?php

require_once __DIR__ . '/trait.CmsScope.php';

/**
 * Controller_Page — renders a published CMS page through the shared block renderer.
 *
 * Route: Page/view/{slug}  →  Controller_Page::view($slug)
 * (the framework passes the route's 3rd segment as the action arg).
 *
 * NOTE on the dual role of view(): the framework calls the controller twice —
 * first as the action handler ($C->view($slug), one arg) to populate data, then
 * as the render step ($C->view(), zero args, defined on the base Controller) to
 * produce the page HTML. Because the action name here collides with the base
 * render method, view() dispatches on arg count: with a slug it loads the page;
 * with no args it delegates to parent::view() to render.
 *
 * Published global pages only (v2 scope). Blocks come from the CmsPage lib and
 * render via the same frontdoor/render_blocks.tpl partial the home page uses, so
 * CMS pages inherit the front-door block styling.
 */
class Controller_Page extends Controller
{
    use CmsScopeContext;

    /** v2 scope: org-wide. */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
    }

    public function view($slug = null)
    {
        // Zero-arg call = framework render step → delegate to base renderer.
        if (func_num_args() === 0) {
            return parent::view();
        }

        $this->template = 'Page_view.tpl';
        $this->data['IsFrontDoor'] = false;
        // Distinct from the front-door home: still a CMS-styled public page, so the
        // brand serif (MedievalSharp) must load (default.theme gates on this flag).
        $this->data['IsCmsPage'] = true;

        $slug = trim((string) $slug);
        $this->load_model('CmsPage');
        // C1: one GhettoCache-backed bundle (page row + its enabled blocks) instead
        // of a separate GetPageBySlug() + GetPageBlocks() pair.
        $bundle = ($slug !== '') ? $this->CmsPage->get_page_with_blocks('global', 0, $slug) : null;
        $page   = (is_array($bundle) && !empty($bundle['page'])) ? $bundle['page'] : null;

        if (empty($page)) {
            http_response_code(404);
            $this->data['Message']    = 'Page not found.';
            $this->data['page_title'] = 'Page not found';
            $this->data['FrontDoor']  = [];
            $this->data['no_index']   = true;
            return;
        }

        $this->data['FrontDoor']  = (is_array($bundle) && isset($bundle['blocks']) && is_array($bundle['blocks']))
            ? $bundle['blocks'] : array();
        $this->_attachFrontDoorTheme();
        $this->data['page_title'] = $page['title'];

        // #122: per-page canonical + Open Graph (mirrors Controller_Site::_setPageMeta)
        // so a shared CMS page carries its own canonical/og:* instead of leaking the
        // global front-door defaults.
        $this->_setPageMeta($page, $slug);

        // Wayfinding: breadcrumbs (root → current) + active-nav matching.
        $this->data['CurrentSlug']  = $slug;
        $this->data['CurrentPage']  = $page;
        $this->data['PageAncestors'] = $this->CmsPage->GetPageAncestors((int) $page['page_id']);

        // Show the floating editor FAB to CMS editors (rendered by default.theme).
        // #29: single shared edit-scope gate (CmsCan-backed).
        $uid = (int) ($this->session->user_id ?? 0);
        if ($this->_cmsCanEditScope($uid, self::$SCOPE)) {
            $this->data['cmsEditUrl'] = UIR . 'Cms/edit/' . (int) $page['page_id'];
            $this->data['cmsEditTip'] = 'Edit this page';
        }
    }

    /**
     * #122: publish a per-page $PageMeta (canonical + og:*) for a global CMS page,
     * mirroring Controller_Site::_setPageMeta. Canonical is the page's public
     * Page/view URL (built from UIR); og:image comes from the page's hero_media_id
     * when set (else default.theme's ORK fallback applies).
     *
     * @param array  $page page row (title, meta_description, hero_media_id, slug)
     * @param string $slug the requested slug
     */
    private function _setPageMeta($page, $slug)
    {
        $slug = trim((string) $slug);
        $canon = ($slug === 'home' || $slug === '')
            ? UIR
            : UIR . 'Page/view/' . rawurlencode($slug);

        // og:image ← hero (absolute); relative media urls get the request origin.
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $origin = ($host !== '') ? (($https ? 'https://' : 'http://') . $host) : '';

        $ogImage = '';
        $mediaId = (int) ($page['hero_media_id'] ?? 0);
        if ($mediaId > 0) {
            $this->load_model('CmsMedia');
            $hm = $this->CmsMedia->get_media($mediaId);
            if (is_array($hm) && !empty($hm['url'])) {
                $u = (string) $hm['url'];
                $ogImage = preg_match('#^https?://#i', $u) ? $u : ($origin . '/' . ltrim($u, '/'));
            }
        }

        $title = trim((string) ($page['title'] ?? ''));
        $this->data['PageMeta'] = array(
            'canonical'   => $canon,
            'og_type'     => 'article',
            'og_title'    => ($title !== '' ? $title : 'ORK 3 - Amtgard Online Record Keeper'),
            'og_desc'     => trim((string) ($page['meta_description'] ?? '')),
            'og_image'    => $ogImage,
            'og_sitename' => 'ORK 3 - Amtgard Online Record Keeper',
        );
    }
}
