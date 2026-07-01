<?php

require_once __DIR__ . '/trait.CmsScope.php';

/**
 * Controller_CmsAjax — JSON endpoints for the CMS admin editor.
 *
 * Routes (one method per endpoint; the router calls $C->$method($action)):
 *   CmsAjax/savepage      → create/update page meta + REPLACE its blocks   (page.create | page.edit)
 *   CmsAjax/publish       → set status=published, stamp published_at        (page.publish)
 *   CmsAjax/unpublish     → set status=draft                                (page.publish)
 *   CmsAjax/deletepage    → delete a non-system page + its blocks           (page.delete)
 *   CmsAjax/mediaupload   → base64 image upload → media library             (media.manage)
 *   CmsAjax/medialist     → media-ref list for the picker                   (media.manage)
 *
 * Every action: requires a logged-in user, gates the capability via
 * CmsAuth->cms_can($uid, <cap>, GLOBAL_SCOPE), and emits a JSON envelope
 * {ok:bool, ...} then exit. v2 is global scope only (the data model carries
 * scope_type/scope_id for kingdom/park later).
 *
 * Listed in the no-token-skip set in class.Controller.php (the *Ajax pattern),
 * so the single-device token check does not bounce these XHR calls. Conventions:
 * thin controller (DB work lives in the libs); rich_text/raw_html block bodies
 * run through CmsSanitizer::Clean before save (defense-in-depth at render too).
 */
class Controller_CmsAjax extends Controller
{
    use CmsScopeContext;

    /**
     * Default scope when no ?scope= selector is present — the global front door.
     * Phase 3 threads a per-request scope via _resolveScope() / _scope(); this
     * constant is only the fallback shape (byte-for-byte legacy behavior).
     */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    /** Block field bodies that hold authored HTML → must be sanitized on save. */
    private static $HTML_FIELDS = array('body', 'html');

    /** Block fields that hold a URL → must pass URL-scheme validation on save. */
    private static $URL_FIELDS = array('href', 'more_href', 'url', 'link', 'cta_href', 'button_href', 'src');

    /**
     * Canonical block-type allowlist — kept in lockstep with the keys of
     * Controller_Cms::_blockCatalog()'s $known map (the authoritative catalog).
     * Used by _parseBlocks() to drop blocks with an unknown/forged type.
     */
    private static $BLOCK_TYPES = array(
        'marketing_nav', 'member_bar', 'hero_carousel', 'richtext', 'card_grid',
        'steps', 'events_feed', 'photo_mosaic', 'kingdoms_teaser', 'cta_band',
        'staff_roster', 'rich_text', 'heading', 'divider', 'spacer', 'accordion',
        'quote', 'table', 'image', 'gallery', 'video_embed', 'file_download',
        'columns', 'raw_html', 'stat_ticker', 'tournaments_feed', 'recap_highlight',
        'blog_feed',
    );

    public function __construct($call = null, $action = null)
    {
        parent::__construct($call, $action);
        $this->load_model('CmsAuth');
        $this->load_model('CmsPage');
        $this->load_model('CmsPost');
        $this->load_model('CmsNav');
        $this->load_model('CmsTheme');
    }

    /* ------------------------------------------------------------------ *
     * savepage — create/update meta + replace blocks
     * ------------------------------------------------------------------ */

    public function savepage($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);

        $pageId = (int)($_POST['page_id'] ?? 0);
        $isNew  = ($pageId <= 0);
        $needed = $isNew ? 'page.create' : 'page.edit';
        $this->_require($uid, $needed, $scope);

        // ---- Page meta ----
        $title = trim((string)($_POST['title'] ?? ''));
        $slug  = $this->_slugify((string)($_POST['slug'] ?? ''), $title);
        $type  = $this->_normalizeType((string)($_POST['type'] ?? 'composed'));
        $metaDesc = trim((string)($_POST['meta_description'] ?? ''));

        if ($title === '') {
            $this->_fail('A page title is required.');
        }
        if ($slug === '') {
            $this->_fail('A page slug is required.');
        }

        // ---- Blocks (posted as a JSON array string) ----
        $blocks = $this->_parseBlocks($_POST['blocks'] ?? null);

        $meta = array(
            'title'            => $title,
            'slug'             => $slug,
            'type'             => $type,
            'meta_description' => ($metaDesc === '' ? null : $metaDesc),
            'updated_by'       => $uid,
        );

        if (array_key_exists('hero_media_id', $_POST)) {
            $hero = (int)$_POST['hero_media_id'];
            // Only honor an in-scope media id; a cross-scope (forged) id is dropped.
            $this->load_model('CmsMedia');
            $meta['hero_media_id'] = ($hero > 0 && $this->_rowInScope($this->CmsMedia->get_media($hero), $scope))
                ? $hero : null;
        }

        if ($isNew) {
            $meta['created_by'] = $uid;
            $meta['status']     = 'draft';
            $meta['scope_type'] = (string)$scope['type'];
            $meta['scope_id']   = (int)$scope['id'];
            $pageId = (int)$this->CmsPage->create_page($meta);
            if ($pageId <= 0) {
                $this->_fail('Could not create the page (the slug may already be in use).');
            }
        } else {
            $existing = $this->CmsPage->get_page($pageId);
            if (empty($existing)) {
                $this->_fail('Page not found.', 4);
            }
            // IDOR guard: the existing page must belong to the resolved scope.
            $this->_requireOwned($existing, $scope);
            $this->CmsPage->update_page($pageId, $meta);
        }

        $count = (int)$this->CmsPage->replace_blocks('page', $pageId, $blocks);

        $this->_ok(array(
            'page_id'     => $pageId,
            'slug'        => $slug,
            'block_count' => $count,
            'is_new'      => $isNew,
            'saved_at'    => date('c'),
        ));
    }

    /* ------------------------------------------------------------------ *
     * publish / unpublish
     * ------------------------------------------------------------------ */

    public function publish($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $pageId = (int)($_POST['page_id'] ?? 0);
        $row = $this->CmsPage->get_page($pageId);
        if ($pageId <= 0 || empty($row)) {
            $this->_fail('Page not found.', 4);
        }
        $this->_requireOwned($row, $scope);

        $this->CmsPage->set_status($pageId, 'published', $uid);
        $row = $this->CmsPage->get_page($pageId);

        $this->_ok(array(
            'page_id'      => $pageId,
            'status'       => 'published',
            'published_at' => isset($row['published_at']) ? $row['published_at'] : null,
        ));
    }

    public function unpublish($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $pageId = (int)($_POST['page_id'] ?? 0);
        $row = $this->CmsPage->get_page($pageId);
        if ($pageId <= 0 || empty($row)) {
            $this->_fail('Page not found.', 4);
        }
        $this->_requireOwned($row, $scope);

        $this->CmsPage->set_status($pageId, 'draft', $uid);

        $this->_ok(array(
            'page_id' => $pageId,
            'status'  => 'draft',
        ));
    }

    /* ------------------------------------------------------------------ *
     * deletepage
     * ------------------------------------------------------------------ */

    public function deletepage($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        $pageId = (int)($_POST['page_id'] ?? 0);
        $row    = $this->CmsPage->get_page($pageId);
        if ($pageId <= 0 || empty($row)) {
            $this->_fail('Page not found.', 4);
        }
        // IDOR guard: never delete a page belonging to another scope.
        $this->_requireOwned($row, $scope);
        if (!empty($row['is_system'])) {
            $this->_fail('System pages cannot be deleted.', 3);
        }

        $deleted = (bool)$this->CmsPage->delete_page($pageId);
        if (!$deleted) {
            $this->_fail('Could not delete the page.');
        }

        $this->_ok(array('page_id' => $pageId, 'deleted' => true));
    }

    /* ================================================================== *
     * BLOG POSTS — reuse the page.* caps + the polymorphic block store
     * (owner_type='post'). Mirrors the page handlers' envelope/_begin/_require.
     * ================================================================== */

    /* ------------------------------------------------------------------ *
     * savepost — create/update post meta + replace body blocks
     * ------------------------------------------------------------------ */

    public function savepost($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);

        $postId = (int)($_POST['post_id'] ?? 0);
        $isNew  = ($postId <= 0);
        $needed = $isNew ? 'page.create' : 'page.edit';
        $this->_require($uid, $needed, $scope);

        // ---- Post meta ----
        $title   = trim((string)($_POST['title'] ?? ''));
        $slug    = $this->_slugify((string)($_POST['slug'] ?? ''), $title);
        $excerpt = trim((string)($_POST['excerpt'] ?? ''));

        if ($title === '') {
            $this->_fail('A post title is required.');
        }
        if ($slug === '') {
            $this->_fail('A post slug is required.');
        }

        // ---- Body blocks (posted as a JSON array string; HTML fields sanitized) ----
        $blocks = $this->_parseBlocks($_POST['blocks'] ?? null);

        $meta = array(
            'title'      => $title,
            'slug'       => $slug,
            'excerpt'    => ($excerpt === '' ? null : $excerpt),
            'updated_by' => $uid,
        );

        if (array_key_exists('hero_media_id', $_POST)) {
            $hero = (int)$_POST['hero_media_id'];
            // Only honor an in-scope media id; a cross-scope (forged) id is dropped.
            $this->load_model('CmsMedia');
            $meta['hero_media_id'] = ($hero > 0 && $this->_rowInScope($this->CmsMedia->get_media($hero), $scope))
                ? $hero : null;
        }

        if ($isNew) {
            $meta['author_id']  = $uid;
            $meta['created_by'] = $uid;
            $meta['status']     = 'draft';
            $meta['scope_type'] = (string)$scope['type'];
            $meta['scope_id']   = (int)$scope['id'];
            $postId = (int)$this->CmsPost->create_post($meta);
            if ($postId <= 0) {
                $this->_fail('Could not create the post (the slug may already be in use).');
            }
        } else {
            $existing = $this->CmsPost->get_post($postId);
            if (empty($existing)) {
                $this->_fail('Post not found.', 4);
            }
            // IDOR guard: the existing post must belong to the resolved scope.
            $this->_requireOwned($existing, $scope);
            $this->CmsPost->update_post($postId, $meta);
        }

        // Tags arrive as a comma-separated string.
        $tagsRaw = (string)($_POST['tags'] ?? '');
        $tagNames = array();
        foreach (explode(',', $tagsRaw) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $tagNames[] = $name;
            }
        }
        $this->CmsPost->set_tags($postId, $tagNames);

        // Body blocks live in the shared polymorphic store under owner_type='post'.
        $count = (int)$this->CmsPage->replace_blocks('post', $postId, $blocks);

        // Echo back the resolved tag set (slugified/deduped) for the editor.
        $tags = $this->CmsPost->get_tags($postId);

        $this->_ok(array(
            'post_id'     => $postId,
            'slug'        => $slug,
            'block_count' => $count,
            'is_new'      => $isNew,
            'tags'        => $tags,
            'saved_at'    => date('c'),
        ));
    }

    /* ------------------------------------------------------------------ *
     * publishpost / unpublishpost
     * ------------------------------------------------------------------ */

    public function publishpost($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $postId = (int)($_POST['post_id'] ?? 0);
        $row = $this->CmsPost->get_post($postId);
        if ($postId <= 0 || empty($row)) {
            $this->_fail('Post not found.', 4);
        }
        $this->_requireOwned($row, $scope);

        $this->CmsPost->set_status($postId, 'published', $uid);
        $row = $this->CmsPost->get_post($postId);

        $this->_ok(array(
            'post_id'      => $postId,
            'status'       => 'published',
            'published_at' => isset($row['published_at']) ? $row['published_at'] : null,
        ));
    }

    public function unpublishpost($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $postId = (int)($_POST['post_id'] ?? 0);
        $row = $this->CmsPost->get_post($postId);
        if ($postId <= 0 || empty($row)) {
            $this->_fail('Post not found.', 4);
        }
        $this->_requireOwned($row, $scope);

        $this->CmsPost->set_status($postId, 'draft', $uid);

        $this->_ok(array(
            'post_id' => $postId,
            'status'  => 'draft',
        ));
    }

    /* ------------------------------------------------------------------ *
     * deletepost
     * ------------------------------------------------------------------ */

    public function deletepost($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        $postId = (int)($_POST['post_id'] ?? 0);
        $row    = $this->CmsPost->get_post($postId);
        if ($postId <= 0 || empty($row)) {
            $this->_fail('Post not found.', 4);
        }
        // IDOR guard: never delete a post belonging to another scope.
        $this->_requireOwned($row, $scope);

        $deleted = (bool)$this->CmsPost->delete_post($postId);
        if (!$deleted) {
            $this->_fail('Could not delete the post.');
        }

        $this->_ok(array('post_id' => $postId, 'deleted' => true));
    }

    /* ================================================================== *
     * NAVIGATION — edit the 'marketing' (and future) menus. All gated
     * 'nav.manage'. Mirrors the page/post envelope (_begin/_require/_ok/_fail).
     * ================================================================== */

    /* ------------------------------------------------------------------ *
     * savenavitem — create (nav_id<=0) or update one nav item
     * ------------------------------------------------------------------ */

    public function savenavitem($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'nav.manage', $scope);

        $navId = (int)($_POST['nav_id'] ?? 0);
        $isNew = ($navId <= 0);

        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') {
            $this->_fail('A label is required.');
        }

        $linkType = $this->_normalizeNavLinkType((string)($_POST['link_type'] ?? 'page'));

        // Resolve the link target for the chosen link_type; clear the others so
        // a type-switch never leaves a stale page_id/post_id/url around.
        $pageId = null;
        $postId = null;
        $url    = null;
        switch ($linkType) {
            case 'page':
                $pageId = (int)($_POST['page_id'] ?? 0);
                if ($pageId <= 0) {
                    $this->_fail('Pick a page for this link.');
                }
                // IDOR: the linked page must belong to THIS scope. Otherwise its
                // title/slug would leak into this org's nav admin via the
                // page/post JOIN (cross-org, incl. draft, metadata disclosure).
                $this->_requireOwned($this->CmsPage->get_page($pageId), $scope);
                break;
            case 'post':
                $postId = (int)($_POST['post_id'] ?? 0);
                if ($postId <= 0) {
                    $this->_fail('Pick a post for this link.');
                }
                // IDOR: the linked post must belong to THIS scope (see above).
                $this->_requireOwned($this->CmsPost->get_post($postId), $scope);
                break;
            case 'url':
                $url = trim((string)($_POST['url'] ?? ''));
                if ($url === '') {
                    $this->_fail('Enter a URL for this link.');
                }
                // Prevent persistent XSS: reject javascript:, data:, protocol-
                // relative, and any other unsafe scheme before storing.
                if (!CmsSanitizer::IsSafeUrl($url)) {
                    $this->_fail('Invalid or unsafe URL.');
                }
                break;
            case 'dynamic':
                $url = trim((string)($_POST['url'] ?? ''));
                if ($url === '') {
                    $this->_fail('Enter an internal route (e.g. Directory/index).');
                }
                // Dynamic values are internal route keys (e.g. "Directory/index").
                // A bare route has no scheme and is always safe. Only reject if it
                // carries an explicit scheme that is not http/https (a bare path
                // containing no colon has no scheme at all, so it passes IsSafeUrl).
                if (!CmsSanitizer::IsSafeUrl($url)) {
                    $this->_fail('Invalid or unsafe URL.');
                }
                break;
        }

        // parent_id: 0/'' => top level. A child of a child is not allowed
        // (one dropdown level), enforced below for the create/update case.
        $parentRaw = $_POST['parent_id'] ?? '';
        $parentId  = ((string)$parentRaw !== '' && (int)$parentRaw > 0) ? (int)$parentRaw : null;

        $enabled = (array_key_exists('enabled', $_POST) && ((int)$_POST['enabled'] === 0 || $_POST['enabled'] === 'false'))
            ? 0 : 1;

        // Validate the proposed parent: must be an existing top-level item of
        // this menu (so we never create a 3rd nesting level), and not self.
        if ($parentId !== null) {
            $parentOk = false;
            foreach ($this->CmsNav->list_items('marketing', (string)$scope['type'], (int)$scope['id']) as $row) {
                if ((int)($row['nav_id'] ?? 0) === $parentId) {
                    // Parent must itself be top-level (parent_id null/0).
                    $pp = $row['parent_id'] ?? null;
                    $parentOk = ($pp === null || (int)$pp === 0) && ($parentId !== $navId);
                    break;
                }
            }
            if (!$parentOk) {
                $parentId = null;
            }
        }

        $data = array(
            'menu'      => 'marketing',
            'label'     => $label,
            'link_type' => $linkType,
            'page_id'   => $pageId,
            'post_id'   => $postId,
            'url'       => $url,
            'parent_id' => $parentId,
            'enabled'   => $enabled,
            'scope_type' => (string)$scope['type'],
            'scope_id'  => (int)$scope['id'],
        );

        if ($isNew) {
            $navId = (int)$this->CmsNav->create_item($data);
            if ($navId <= 0) {
                $this->_fail('Could not create the navigation item.');
            }
        } else {
            // Pass explicit scope so UpdateItem's IDOR ownership guard fires —
            // a cross-scope nav_id is rejected before any write.
            $ok = (bool)$this->CmsNav->update_item($navId, $data, (string)$scope['type'], (int)$scope['id']);
            if (!$ok) {
                $this->_fail('Could not update the navigation item.', 4);
            }
        }

        $this->_ok(array(
            'nav_id'   => $navId,
            'is_new'   => $isNew,
            'saved_at' => date('c'),
        ));
    }

    /* ------------------------------------------------------------------ *
     * deletenavitem — delete an item (and its direct children)
     * ------------------------------------------------------------------ */

    public function deletenavitem($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'nav.manage', $scope);

        $navId = (int)($_POST['nav_id'] ?? 0);
        if ($navId <= 0) {
            $this->_fail('Navigation item not found.', 4);
        }

        // Pass the resolved scope so DeleteItem's scope-ownership (IDOR) guard
        // actually fires — a cross-scope nav_id is rejected before any delete.
        $deleted = (bool)$this->CmsNav->delete_item($navId, (string)$scope['type'], (int)$scope['id']);
        if (!$deleted) {
            $this->_fail('Navigation item not found.', 4);
        }

        $this->_ok(array('nav_id' => $navId, 'deleted' => true));
    }

    /* ------------------------------------------------------------------ *
     * reordernav — apply a new ordering/parent layout for a menu
     * ------------------------------------------------------------------ */

    public function reordernav($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'nav.manage', $scope);

        $menu = trim((string)($_POST['menu'] ?? 'marketing'));
        if ($menu === '') {
            $menu = 'marketing';
        }

        $raw = $_POST['items'] ?? $_POST['order'] ?? null;
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            $this->_fail('No ordering was supplied.');
        }

        $ordered = array();
        $idx = 0;
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['nav_id'])) {
                $idx++;
                continue;
            }
            $navId = (int)$entry['nav_id'];
            if ($navId <= 0) {
                $idx++;
                continue;
            }
            $parentRaw = $entry['parent_id'] ?? null;
            $parentId  = ($parentRaw !== null && $parentRaw !== '' && (int)$parentRaw > 0) ? (int)$parentRaw : null;
            $ordered[] = array(
                'nav_id'    => $navId,
                'parent_id' => $parentId,
                'ordering'  => isset($entry['ordering']) ? (int)$entry['ordering'] : $idx,
            );
            $idx++;
        }

        $ok = (bool)$this->CmsNav->reorder($menu, $ordered, (string)$scope['type'], (int)$scope['id']);
        if (!$ok) {
            $this->_fail('Could not save the new order.');
        }

        $this->_ok(array('menu' => $menu, 'count' => count($ordered)));
    }

    /* ------------------------------------------------------------------ *
     * mediaupload
     * ------------------------------------------------------------------ */

    public function mediaupload($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $data = (string)($_POST['data'] ?? $_POST['image'] ?? '');
        if ($data === '') {
            $this->_fail('No image data was supplied.');
        }
        $filename = trim((string)($_POST['filename'] ?? ''));
        $alt      = trim((string)($_POST['alt'] ?? ''));

        $this->load_model('CmsMedia');
        $row = $this->CmsMedia->upload($data, $filename, $alt, $uid, $scope);
        if (empty($row)) {
            $this->_fail('The image could not be processed (unsupported type or too large).');
        }

        $ref = $this->CmsMedia->to_media_ref($row);

        $this->_ok(array(
            'media'  => $ref,
            'ref'    => $ref, // alias for callers expecting `ref`
        ));
    }

    /* ------------------------------------------------------------------ *
     * medialist
     * ------------------------------------------------------------------ */

    public function medialist($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $search = ($search === '') ? null : $search;
        $limit  = (int)($_GET['limit'] ?? $_POST['limit'] ?? 200);
        if ($limit <= 0 || $limit > 500) {
            $limit = 200;
        }

        $this->load_model('CmsMedia');
        $list = $this->CmsMedia->list_media($scope, $limit, $search);
        if (!is_array($list)) {
            $list = array();
        }

        $this->_ok(array('media' => $list, 'count' => count($list)));
    }

    /* ------------------------------------------------------------------ *
     * theme engine
     * ------------------------------------------------------------------ */

    /** POST: validate+persist tokens (draft) under the global scope. */
    public function savetheme($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'theme.manage', $scope);
        $tokens = $this->_themeTokensFromPost();
        $name   = trim((string)($_POST['name'] ?? 'Default'));
        $id = (int)$this->CmsTheme->save_theme((string)$scope['type'], (int)$scope['id'], $name, $tokens, $uid);
        if ($id <= 0) {
            $this->_fail('Could not save the theme.');
        }
        $this->_ok(array('theme_id' => $id, 'saved_at' => date('c')));
    }

    /** POST: activate a theme id for the global scope. */
    public function activatetheme($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'theme.manage', $scope);
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id <= 0) {
            $this->_fail('Missing theme id.', 4);
        }
        // SetActive's WHERE keys on (scope_type, scope_id), so a foreign theme_id
        // cannot be activated cross-scope — the IDOR guard is inherent here.
        $this->CmsTheme->set_active((string)$scope['type'], (int)$scope['id'], $id);
        $this->_ok(array('active' => $id));
    }

    /** POST: deactivate all themes (revert to CSS defaults). */
    public function resettheme($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'theme.manage', $scope);
        $this->CmsTheme->reset_active((string)$scope['type'], (int)$scope['id']);
        $this->_ok();
    }

    /** POST: echo resolved CSS for the live preview (no persistence). */
    public function previewtheme($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'theme.manage', $scope);
        $tokens = $this->_themeTokensFromPost();
        $css = (string)$this->CmsTheme->preview_css($tokens);
        $this->_ok(array('css' => $css));
    }

    /* ------------------------------------------------------------------ *
     * site lifecycle — publish / unpublish an org's public site
     * ------------------------------------------------------------------ */

    /**
     * POST: publish the resolved org's public site (status='published').
     * Requires a non-global scope and an AUTH_ADMIN-tier officer (monarch /
     * regent) — gated via 'page.publish', which bridges to AUTH_ADMIN on the
     * scope. EnsureSite first so a never-opened site can still be published.
     */
    public function publishsite($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        if ($this->_scopeIsGlobal($scope)) {
            $this->_fail('The global front door is not a publishable org site.', 3);
        }
        $this->_require($uid, 'page.publish', $scope);

        $this->load_model('CmsSite');
        $site = $this->CmsSite->ensure_site((string)$scope['type'], (int)$scope['id'], $uid);
        if (empty($site) || empty($site['site_id'])) {
            $this->_fail('Could not resolve the site.', 4);
        }
        $this->CmsSite->set_published((int)$site['site_id'], $uid);
        $this->_ok(array('status' => 'published'));
    }

    /** POST: return the resolved org's public site to draft (unpublish). */
    public function unpublishsite($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        if ($this->_scopeIsGlobal($scope)) {
            $this->_fail('The global front door is not a publishable org site.', 3);
        }
        $this->_require($uid, 'page.publish', $scope);

        $this->load_model('CmsSite');
        $site = $this->CmsSite->get_site_for_scope((string)$scope['type'], (int)$scope['id']);
        if (empty($site) || empty($site['site_id'])) {
            $this->_fail('Could not resolve the site.', 4);
        }
        $this->CmsSite->set_draft((int)$site['site_id'], $uid);
        $this->_ok(array('status' => 'draft'));
    }

    /* ------------------------------------------------------------------ *
     * personlookup
     * ------------------------------------------------------------------ */

    /**
     * Editor-only: resolve a linked Amtgard persona to its display names so the
     * roster editor can snapshot them. Gated by CMS auth; real names are only
     * resolvable behind the CMS capability boundary, never via public search.
     */
    public function personlookup($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.edit', $scope);

        $mundaneId = (int)($_GET['mundane_id'] ?? $_POST['mundane_id'] ?? 0);
        if ($mundaneId <= 0) {
            $this->_fail('A valid person id is required.', 4);
        }

        $info = Ork3::$Lib->player->player_info($mundaneId);
        if (!$info || empty($info['Persona'])) {
            $this->_fail('Person not found.', 4);
        }

        $mundaneName = trim(($info['GivenName'] ?? '') . ' ' . ($info['Surname'] ?? ''));
        $this->_ok(array(
            'mundane_id'   => $mundaneId,
            'persona'      => (string)$info['Persona'],
            'mundane_name' => $mundaneName,
        ));
    }

    /* ------------------------------------------------------------------ *
     * Internal helpers
     * ------------------------------------------------------------------ */

    /** Decode posted tokens JSON into an assoc array (validation happens in the lib). */
    private function _themeTokensFromPost()
    {
        $raw = $_POST['tokens'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Common preamble: JSON + no-cache headers, login gate. Returns the uid.
     * Emits a JSON error + exit when not logged in.
     */
    private function _begin()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        if ($uid <= 0) {
            $this->_fail('You must be logged in.', 5);
        }

        // CSRF: state-changing requests arrive as POST and must carry the
        // per-session synchronizer token (sent by the editor JS as the
        // X-CSRF-Token header). GET reads (medialist, personlookup) are exempt.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
            if ($sent === '' || !hash_equals($this->_csrfToken(), $sent)) {
                $this->_fail('Invalid or expired request token. Reload the page and try again.', 9);
            }
        }

        return $uid;
    }

    /**
     * Resolve + authorize the request scope, or emit a JSON 403 and exit.
     * Returns the validated ['type'=>..,'id'=>..] scope array. No selector →
     * global (legacy). A present-but-invalid/unauthorized selector never
     * downgrades to global — it is rejected.
     *
     * @param int $uid
     * @return array{type:string,id:int}
     */
    private function _scope($uid)
    {
        $scope = $this->_resolveScope($uid);
        if ($scope === false) {
            $this->_fail('You are not authorized for this site.', 5);
        }
        return $scope;
    }

    /** Gate a capability in the resolved scope; JSON error + exit when denied. */
    private function _require($uid, $capability, $scope)
    {
        if (!$this->CmsAuth->cms_can($uid, $capability, $scope)) {
            $this->_fail('You are not authorized to perform this action.', 5);
        }
    }

    /**
     * IDOR guard: reject (JSON 403 + exit) when the loaded target row does not
     * belong to the resolved request scope. Closes cross-org tampering even by
     * an officer authorized for a DIFFERENT org.
     *
     * @param array|null $row   the target row (must carry scope_type/scope_id)
     * @param array      $scope the resolved, authorized request scope
     * @return void
     */
    private function _requireOwned($row, $scope)
    {
        if (!$this->_rowInScope($row, $scope)) {
            $this->_fail('You are not authorized to modify this content.', 5);
        }
    }

    /** Emit {ok:true, ...$extra} and exit. */
    private function _ok($extra = array())
    {
        echo json_encode(array('ok' => true) + (is_array($extra) ? $extra : array()));
        exit;
    }

    /** Emit {ok:false, status, error} and exit. */
    private function _fail($message, $status = 1)
    {
        echo json_encode(array(
            'ok'     => false,
            'status' => (int)$status,
            'error'  => (string)$message,
        ));
        exit;
    }

    /**
     * Decode the posted block list (a JSON array string) and sanitize every
     * authored-HTML field through CmsSanitizer::Clean. Returns the renderer-shape
     * block array CmsPage::ReplaceBlocks consumes. Invalid/empty → empty array.
     */
    private function _parseBlocks($raw)
    {
        if ($raw === null || $raw === '') {
            return array();
        }
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return array();
        }

        $this->load_model('CmsSanitizer');

        $out = array();
        foreach ($decoded as $block) {
            if (!is_array($block) || empty($block['type'])) {
                continue;
            }
            // Drop blocks whose type is not in the canonical catalog (forged/unknown).
            if (!in_array((string)$block['type'], self::$BLOCK_TYPES, true)) {
                continue;
            }
            $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();
            $fields = $this->_sanitizeFields($fields);
            $out[] = array(
                'type'    => (string)$block['type'],
                'enabled' => array_key_exists('enabled', $block) ? (int)(bool)$block['enabled'] : 1,
                'order'   => isset($block['order']) ? (int)$block['order']
                    : (isset($block['ordering']) ? (int)$block['ordering'] : count($out) * 10),
                'source'  => (isset($block['source']) && $block['source'] === 'dynamic') ? 'dynamic' : 'authored',
                'fields'  => $fields,
            );
        }
        return $out;
    }

    /**
     * Recursively walk a block-fields array and sanitize any string value
     * whose key is in $HTML_FIELDS. Descends into nested arrays (accordion
     * items, column sub-blocks, etc.) so authored HTML at any depth is cleaned.
     *
     * @param array $fields raw fields array (may be nested)
     * @return array the same structure with HTML fields sanitized
     */
    private function _sanitizeFields(array $fields)
    {
        foreach ($fields as $key => $val) {
            if (is_array($val)) {
                // Nested sub-structure (e.g. accordion items array or columns).
                $fields[$key] = $this->_sanitizeFields($val);
            } elseif (is_string($val) && in_array($key, self::$HTML_FIELDS, true)) {
                $fields[$key] = $this->CmsSanitizer->clean($val);
            } elseif (is_string($val) && in_array($key, self::$URL_FIELDS, true)) {
                $fields[$key] = CmsSanitizer::IsSafeUrl($val) ? $val : '#';
            }
        }
        return $fields;
    }

    /**
     * Coerce a slug: prefer the supplied value, fall back to the title; lower,
     * spaces/punct → hyphens, collapse + trim hyphens. Empty → '' (caller fails).
     */
    private function _slugify($slug, $fallbackTitle)
    {
        $src = ($slug !== '') ? $slug : $fallbackTitle;
        $src = strtolower(trim($src));
        $src = preg_replace('/[^a-z0-9]+/', '-', $src);
        $src = preg_replace('/-+/', '-', $src);
        return trim((string)$src, '-');
    }

    /** Clamp the page type to the supported enum. */
    private function _normalizeType($type)
    {
        $allowed = array('composed', 'article', 'media', 'about', 'blog_index', 'resource', 'dynamic');
        return in_array($type, $allowed, true) ? $type : 'composed';
    }

    /** Clamp a nav link_type to the supported enum (default 'page'). */
    private function _normalizeNavLinkType($linkType)
    {
        $allowed = array('page', 'post', 'url', 'dynamic');
        $linkType = strtolower(trim((string)$linkType));
        return in_array($linkType, $allowed, true) ? $linkType : 'page';
    }
}
