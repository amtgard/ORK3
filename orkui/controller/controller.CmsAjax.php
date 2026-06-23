<?php

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
    /** v2 scope: org-wide. */
    private static $SCOPE = array('type' => 'global', 'id' => 0);

    /** Block field bodies that hold authored HTML → must be sanitized on save. */
    private static $HTML_FIELDS = array('body', 'html');

    public function __construct($call = null, $action = null)
    {
        parent::__construct($call, $action);
        $this->load_model('CmsAuth');
        $this->load_model('CmsPage');
        $this->load_model('CmsPost');
    }

    /* ------------------------------------------------------------------ *
     * savepage — create/update meta + replace blocks
     * ------------------------------------------------------------------ */

    public function savepage($action = null)
    {
        $uid = $this->_begin();

        $pageId = (int)($_POST['page_id'] ?? 0);
        $isNew  = ($pageId <= 0);
        $needed = $isNew ? 'page.create' : 'page.edit';
        $this->_require($uid, $needed);

        // ---- Page meta ----
        $title = trim((string)($_POST['title'] ?? ''));
        $slug  = $this->_slugify((string)($_POST['slug'] ?? ''), $title);
        $type  = $this->_normalizeType((string)($_POST['type'] ?? 'composed'));
        $meta  = trim((string)($_POST['meta_description'] ?? ''));

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
            'meta_description' => ($meta === '' ? null : $meta),
            'updated_by'       => $uid,
        );

        if (array_key_exists('hero_media_id', $_POST)) {
            $hero = (int)$_POST['hero_media_id'];
            $meta['hero_media_id'] = ($hero > 0) ? $hero : null;
        }

        if ($isNew) {
            $meta['created_by'] = $uid;
            $meta['status']     = 'draft';
            $meta['scope_type'] = 'global';
            $meta['scope_id']   = 0;
            $pageId = (int)$this->CmsPage->create_page($meta);
            if ($pageId <= 0) {
                $this->_fail('Could not create the page (the slug may already be in use).');
            }
        } else {
            $existing = $this->CmsPage->get_page($pageId);
            if (empty($existing)) {
                $this->_fail('Page not found.', 4);
            }
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
        $this->_require($uid, 'page.publish');

        $pageId = (int)($_POST['page_id'] ?? 0);
        if ($pageId <= 0 || empty($this->CmsPage->get_page($pageId))) {
            $this->_fail('Page not found.', 4);
        }

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
        $this->_require($uid, 'page.publish');

        $pageId = (int)($_POST['page_id'] ?? 0);
        if ($pageId <= 0 || empty($this->CmsPage->get_page($pageId))) {
            $this->_fail('Page not found.', 4);
        }

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
        $this->_require($uid, 'page.delete');

        $pageId = (int)($_POST['page_id'] ?? 0);
        $row    = $this->CmsPage->get_page($pageId);
        if ($pageId <= 0 || empty($row)) {
            $this->_fail('Page not found.', 4);
        }
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

        $postId = (int)($_POST['post_id'] ?? 0);
        $isNew  = ($postId <= 0);
        $needed = $isNew ? 'page.create' : 'page.edit';
        $this->_require($uid, $needed);

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
            $meta['hero_media_id'] = ($hero > 0) ? $hero : null;
        }

        if ($isNew) {
            $meta['author_id']  = $uid;
            $meta['created_by'] = $uid;
            $meta['status']     = 'draft';
            $meta['scope_type'] = 'global';
            $meta['scope_id']   = 0;
            $postId = (int)$this->CmsPost->create_post($meta);
            if ($postId <= 0) {
                $this->_fail('Could not create the post (the slug may already be in use).');
            }
        } else {
            $existing = $this->CmsPost->get_post($postId);
            if (empty($existing)) {
                $this->_fail('Post not found.', 4);
            }
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
        $this->_require($uid, 'page.publish');

        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0 || empty($this->CmsPost->get_post($postId))) {
            $this->_fail('Post not found.', 4);
        }

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
        $this->_require($uid, 'page.publish');

        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId <= 0 || empty($this->CmsPost->get_post($postId))) {
            $this->_fail('Post not found.', 4);
        }

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
        $this->_require($uid, 'page.delete');

        $postId = (int)($_POST['post_id'] ?? 0);
        $row    = $this->CmsPost->get_post($postId);
        if ($postId <= 0 || empty($row)) {
            $this->_fail('Post not found.', 4);
        }

        $deleted = (bool)$this->CmsPost->delete_post($postId);
        if (!$deleted) {
            $this->_fail('Could not delete the post.');
        }

        $this->_ok(array('post_id' => $postId, 'deleted' => true));
    }

    /* ------------------------------------------------------------------ *
     * mediaupload
     * ------------------------------------------------------------------ */

    public function mediaupload($action = null)
    {
        $uid = $this->_begin();
        $this->_require($uid, 'media.manage');

        $data = (string)($_POST['data'] ?? $_POST['image'] ?? '');
        if ($data === '') {
            $this->_fail('No image data was supplied.');
        }
        $filename = trim((string)($_POST['filename'] ?? ''));
        $alt      = trim((string)($_POST['alt'] ?? ''));

        $this->load_model('CmsMedia');
        $row = $this->CmsMedia->upload($data, $filename, $alt, $uid, self::$SCOPE);
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
        $this->_require($uid, 'media.manage');

        $search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $search = ($search === '') ? null : $search;
        $limit  = (int)($_GET['limit'] ?? $_POST['limit'] ?? 200);
        if ($limit <= 0 || $limit > 500) {
            $limit = 200;
        }

        $this->load_model('CmsMedia');
        $list = $this->CmsMedia->list_media(self::$SCOPE, $limit, $search);
        if (!is_array($list)) {
            $list = array();
        }

        $this->_ok(array('media' => $list, 'count' => count($list)));
    }

    /* ------------------------------------------------------------------ *
     * Internal helpers
     * ------------------------------------------------------------------ */

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
        return $uid;
    }

    /** Gate a capability at global scope; JSON error + exit when denied. */
    private function _require($uid, $capability)
    {
        if (!$this->CmsAuth->cms_can($uid, $capability, self::$SCOPE)) {
            $this->_fail('You are not authorized to perform this action.', 5);
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
            $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();
            foreach ($fields as $key => $val) {
                if (is_string($val) && in_array($key, self::$HTML_FIELDS, true)) {
                    $fields[$key] = $this->CmsSanitizer->clean($val);
                }
            }
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
        $allowed = array('composed', 'article', 'media', 'blog_index', 'resource', 'dynamic');
        return in_array($type, $allowed, true) ? $type : 'composed';
    }
}
