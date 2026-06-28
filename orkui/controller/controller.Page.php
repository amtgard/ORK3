<?php

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

        $slug = trim((string) $slug);
        $this->load_model('CmsPage');
        $page = ($slug !== '') ? $this->CmsPage->get_page_by_slug($slug, 'global', 0, true) : null;

        if (empty($page)) {
            http_response_code(404);
            $this->data['Message']    = 'Page not found.';
            $this->data['page_title'] = 'Page not found';
            $this->data['FrontDoor']  = [];
            $this->data['no_index']   = true;
            return;
        }

        $this->data['FrontDoor']  = $this->CmsPage->get_page_blocks((int) $page['page_id']);
        $this->_attachFrontDoorTheme();
        $this->data['page_title'] = $page['title'];

        // Show the floating editor FAB to CMS editors (rendered by default.theme).
        $uid = (int) ($this->session->user_id ?? 0);
        if ($uid > 0) {
            $this->load_model('CmsAuth');
            if ($this->CmsAuth->cms_can($uid, 'page.edit', self::$SCOPE)) {
                $this->data['cmsEditUrl'] = UIR . 'Cms/edit/' . (int) $page['page_id'];
                $this->data['cmsEditTip'] = 'Edit this page';
            }
        }
    }
}
