<?php

require_once __DIR__ . '/trait.CmsScope.php';
// #42/MOD-18: the block-type and page-type REGISTRIES themselves now live in
// CmsBlockRegistry (an ork3 lib, loaded for every request by startup.php), but
// the two canonical KEY LISTS the save-time parser gates on are exposed by
// Controller_Cms — CanonicalPageTypes in particular applies the null-label
// filter that keeps the chooser-only 'post' context out of the page-type enum.
// The router include_once's only the ROUTED controller, so this require is what
// makes Controller_Cms's statics reachable from here; it is still load-bearing.
require_once __DIR__ . '/controller.Cms.php';

/**
 * Controller_CmsAjax — JSON endpoints for the CMS admin editor.
 *
 * One public method per route; the router calls $C->$method($action). The
 * surface spans roughly thirty endpoints — pages/posts (save, publish,
 * unpublish, delete, revisions), Trash/undo (restore*, purge, listtrashed*),
 * media (upload, list, update, usage, delete, bulk delete), navigation, themes,
 * per-org site settings (publishsite/unpublishsite/savesite), maintenance
 * (clearrendercache, runmaintenance) and the editor helpers (previewblocks,
 * personlookup, pagelist). Each group is documented by its own section banner
 * below rather than duplicated here.
 *
 * Every action: requires a logged-in user, gates the capability via
 * CmsAuth->cms_can($uid, <cap>, <scope>), and emits a JSON envelope
 * {ok:bool, ...} then exit. Scope is multi-tenant: a request carries a scope
 * selector (?scope=k:5 / p:12, else global) resolved + authorized by
 * CmsScopeContext, and the site endpoints REJECT the global scope outright —
 * they require a kingdom/park site. Publishing with a future published_at
 * yields status 'scheduled', not 'published'.
 *
 * Listed in the no-token-skip set in class.Controller.php (the *Ajax pattern),
 * so the single-device token check does not bounce these XHR calls. Conventions:
 * thin controller (DB work lives in the libs). Rich-text/HTML block fields are
 * sanitized AUTHORITATIVELY in CmsPage::ReplaceBlocks — the storage choke point
 * every writer passes through (editor, imports, seeding) — so stored content is
 * always clean regardless of entry path (E36/#36: the field-name lists and the
 * Clean/IsSafeUrl passes all live there, on CmsPage::HTML_FIELDS /
 * CmsPage::URL_FIELDS). This controller therefore does NOT re-sanitize on the
 * way in — it only decides which block TYPES may be stored (_parseBlocks'
 * canonical allowlist + the nested-columns rejection) — and there is
 * deliberately no reliance on re-sanitizing at render time either.
 */
class Controller_CmsAjax extends Controller
{
    use CmsScopeContext;

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

        // ---- Authorization ----
        // New page → page.create. Existing page → page.edit OR page.edit_own on a
        // page the user created (C16: page.edit_own was granted to contributors
        // but never honored, locking them out of their own draft after creating it).
        // _requireOwnerEditable encapsulates the full existing-content gate
        // (auth → not-found → IDOR scope → edit_own ownership) used identically by
        // savepost/revisions; then C15 optimistic-concurrency on the loaded row.
        if ($isNew) {
            $this->_require($uid, 'page.create', $scope);
        } else {
            $existing = $this->_requireOwnerEditable($uid, 'page', $pageId, $scope);
            $this->_guardConcurrency($existing, 'page', $pageId);
        }

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
        // C17: reject a router-shadowed slug (blog/post/p/k/rss/sitemap/robots) up front with a
        // specific message — such a page would be unreachable behind the pretty
        // URLs. (CmsPage::CreatePage/UpdatePage also enforce this authoritatively.)
        if ($this->CmsPage->IsReservedPageSlug($slug)) {
            $this->_fail('The slug "' . $slug . '" is reserved by the site router. Please choose another.', 3);
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

        $hero = $this->_resolveHeroMediaId($scope);
        if ($hero !== false) {
            $meta['hero_media_id'] = $hero;
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
            if (!$this->CmsPage->update_page($pageId, $meta)) {
                $this->_fail('Could not save the page (the slug may already be in use).');
            }
        }

        $count = (int)$this->CmsPage->replace_blocks('page', $pageId, $blocks, $uid);
        // #40: ReplaceBlocks returns -1 when the post-write verification fails (the
        // blocks did NOT persist as intended). Fail loudly instead of reporting a
        // successful save the content didn't actually land in.
        if ($count < 0) {
            $this->_fail('Could not save the page content. Please reload and try again.');
        }

        $this->_ok(array(
            'page_id'     => $pageId,
            'slug'        => $slug,
            'block_count' => $count,
            'is_new'      => $isNew,
            // #49: the version token is now the latest block revision_id (a
            // monotonic int), which the client resends as base_version — immune to
            // the second-granular updated_at collision the timestamp token had.
            'version'     => $this->_latestRevisionId('page', $pageId),
            'saved_at'    => date('c'),
        ));
    }

    /* ------------------------------------------------------------------ *
     * previewblocks — render an UNSAVED editor block list (E2, live preview)
     * ------------------------------------------------------------------ */

    /**
     * Render the editor's CURRENT, UNSAVED blocks through the real front-door
     * renderer and hand the resulting page back as HTML. Writes nothing.
     *
     * Why this exists: Cms/preview/{id} renders what is in the DATABASE, so an
     * iframe pointed at it previews the last save, not what the author is
     * typing. The editor posts its live block list here instead.
     *
     * SECURITY — this action renders author-supplied JSON, so it must apply the
     * SAME validation a save applies, not a preview-shaped approximation:
     *
     *   1. _begin()                 login + the CSRF synchronizer token (POST).
     *   2. _scope()                 the ?scope= selector, authorized, never
     *                               silently downgraded to global.
     *   3. _requireOwnerEditable()  for an existing row: page.edit / page.edit_own,
     *                               not-found, the IDOR scope guard and the
     *                               edit_own ownership test — the identical gate
     *                               savepage/savepost run. A brand-new, unsaved
     *                               page needs page.create, as savepage does.
     *   4. _parseBlocks()           the write-side TYPE vocabulary: the canonical
     *                               allowlist and the nested-columns rejection.
     *   5. sanitize_blocks_for_render()  CmsPage::_normalizeBlocks — literally the
     *                               method ReplaceBlocks calls — so CmsSanitizer
     *                               cleans the HTML fields and hardens the URL
     *                               fields exactly as it does on the way to disk.
     *
     * Steps 4 and 5 are the same two calls savepage() makes, in the same order,
     * against the same code. There is no second path.
     *
     * The response is a FULL page document (Cms_preview.tpl rendered through the
     * normal view pipeline), which the editor drops into its preview iframe.
     * Rendering it as a document rather than a fragment is deliberate: the
     * fragment would have to be injected into an already-loaded frame, where
     * frontdoor.js — a parse-time IIFE with no re-init hook — would never re-run,
     * so carousels and lightboxes would be dead in the preview. A document also
     * gets the public stylesheet cascade (frontdoor.css -> blocks.css -> the org
     * theme tokens) from the same partials the public site uses, instead of this
     * controller assembling <link> tags (which the CSS boundary gate forbids CMS
     * PHP from doing, rule C7).
     *
     * @return void (emits JSON {ok, html} + exit)
     */
    public function previewblocks($action = null)
    {
        $uid = $this->_begin();
        // POST ONLY. _begin() now enforces this for every mutating endpoint, but
        // the check stays spelled out here: this one renders caller-supplied
        // content, so its own handler is the right place to say out loud that it
        // must not be reachable by a bare cross-site GET.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->_fail('Preview must be requested with POST.', 1);
        }
        $scope = $this->_scope($uid);

        $isPost  = ((string)($_POST['owner_type'] ?? 'page') === 'post');
        $ownerId = (int)($_POST['owner_id'] ?? 0);

        // Same authorization split as savepage/savepost: an existing row runs the
        // full edit gate (IDOR included), an unsaved one needs page.create.
        $row = null;
        if ($ownerId > 0) {
            $row = $this->_requireOwnerEditable($uid, $isPost ? 'post' : 'page', $ownerId, $scope);
        } else {
            $this->_require($uid, 'page.create', $scope);
        }

        // Steps 4 + 5 — the save path's own validation, in the save path's order.
        $blocks = $this->CmsPage->sanitize_blocks_for_render($this->_parseBlocks($_POST['blocks'] ?? null));

        // The banner row. For an unsaved page there is no row to show, so a
        // minimal stand-in carries the title the author has typed. status is
        // pinned to the STORED status (or 'draft'): a live preview shows unsaved
        // content, and calling it "Published" would be a lie.
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            $title = (string)($row['title'] ?? 'Untitled');
        }
        $previewRow = is_array($row) ? $row : array();
        $previewRow['title']  = $title;
        $previewRow['status'] = (string)($row['status'] ?? 'draft');

        $this->template = 'Cms_preview.tpl';
        $this->data['IsFrontDoor'] = false;
        $this->data['no_index']    = true;
        $this->data['FrontDoor']   = $blocks;
        $this->data['PreviewPage'] = $previewRow;
        // 'postrow' (not 'post') is a literal contract: Cms_preview.tpl keys on it.
        $this->data['PreviewKind'] = $isPost ? 'postrow' : 'page';
        // No Publish button on a LIVE preview: the button publishes the SAVED
        // row, which is not what is on screen. Save, then publish.
        $this->data['CanPublish']  = false;
        $this->data['PreviewLive'] = true;
        // Render the preview as a STANDALONE PUBLIC DOCUMENT rather than inside
        // the ORK application shell. default.theme gates the whole ORK chrome on
        // $IsOrgSite, so this one flag gives the frame exactly the public asset
        // set (cms-base.css -> fonts -> frontdoor.css -> blocks.css) with no
        // orkui.css, no global nav, and — the reason this matters for a LIVE
        // preview specifically — no Google Tag Manager / gtag, which would
        // otherwise fire on every debounced keystroke. It also drops the
        // document from ~44KB to ~7KB per refresh.
        $this->data['IsOrgSite'] = true;
        $this->data['page_title']  = 'Preview: ' . $title;
        // The kingdom_*/park_* live blocks derive their org from the render-time
        // site scope (Controller_Site::_bootShell publishes these). Without them
        // every one of those blocks renders NOTHING — an author on a park page
        // would preview a blank where their meeting times are going to be. The
        // scope here is the resolved, ALREADY AUTHORIZED request scope, so this
        // cannot be used to render another org's data.
        $this->data['SiteNavScopeType'] = (string)$scope['type'];
        $this->data['SiteNavScopeId']   = (int)$scope['id'];
        $this->_applyPreviewTheme($scope);

        // Controller::view() is the framework's own render step. Calling it here
        // (rather than letting index.php call it after the action) is what lets an
        // AJAX action return a rendered document; _ok() exits before index.php
        // would reach its own $C->view(), so it is never rendered twice.
        $this->_ok(array('html' => (string)$this->view()));
    }

    /**
     * Publish the active scope's theme tokens to the preview document. The CSS
     * text is built by CmsThemeTokens and emitted by default.theme's
     * <style id="fd-theme-tokens"> — the one sanctioned inline-CSS channel — so
     * a themed kingdom previews in its own colours instead of the defaults.
     *
     * @param array $scope resolved ['type'=>..,'id'=>..]
     * @return void
     */
    private function _applyPreviewTheme($scope)
    {
        $css = (string)$this->CmsTheme->get_active_css((string)$scope['type'], (int)$scope['id']);
        if ($css !== '') {
            $this->data['fdThemeCss'] = $css;
        }
        // The :root copy, for the same reason Controller_Site::_bootShell emits
        // it: <html>/<body> are ANCESTORS of .fd-page and custom properties
        // inherit downward only, so cms-base.css's `body { background:
        // var(--fd-bg) }` cannot see the scoped block. Without this a
        // dark-themed org previews its page on a white body. default.theme gates
        // this emit on $IsOrgSite, which the live preview sets.
        $rootCss = (string)$this->CmsTheme->get_active_root_css((string)$scope['type'], (int)$scope['id']);
        if ($rootCss !== '') {
            $this->data['fdThemeCssRoot'] = $rootCss;
        }
        // Only the families this scope actually uses (CmsTheme::GetActiveFontQuery).
        $fontQuery = (string)$this->CmsTheme->get_active_font_query((string)$scope['type'], (int)$scope['id']);
        if ($fontQuery !== '') {
            $this->data['fdThemeFontQuery'] = $fontQuery;
        }
    }

    /* ------------------------------------------------------------------ *
     * publish / unpublish
     * ------------------------------------------------------------------ */

    public function publish($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $this->_publishEntity('page', $uid, $scope);
    }

    public function unpublish($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $this->_unpublishEntity('page', $uid, $scope);
    }

    /* ------------------------------------------------------------------ *
     * deletepage
     * ------------------------------------------------------------------ */

    public function deletepage($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        $this->_deleteEntity('page', $uid, $scope);
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

        // ---- Authorization (mirrors savepage; C16 page.edit_own honored) ----
        // Same shared gate as savepage via _requireOwnerEditable (auth → not-found
        // → IDOR scope → edit_own ownership), then the C15 concurrency guard.
        if ($isNew) {
            $this->_require($uid, 'page.create', $scope);
        } else {
            $existing = $this->_requireOwnerEditable($uid, 'post', $postId, $scope);
            $this->_guardConcurrency($existing, 'post', $postId);
        }

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

        $hero = $this->_resolveHeroMediaId($scope);
        if ($hero !== false) {
            $meta['hero_media_id'] = $hero;
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
            // Authorization + IDOR + concurrency were enforced above.
            if (!$this->CmsPost->update_post($postId, $meta)) {
                $this->_fail('Could not save the post (the slug may already be in use).');
            }
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
        // set_tags replaces the tag set atomically in the lib (transaction +
        // post-write verification). A false return means the set was NOT fully
        // applied — fail loudly instead of reporting a partially-applied save.
        if (!$this->CmsPost->set_tags($postId, $tagNames)) {
            $this->_fail('Could not save the post tags. Please try again.');
        }

        // Body blocks live in the shared polymorphic store under owner_type='post'.
        $count = (int)$this->CmsPage->replace_blocks('post', $postId, $blocks, $uid);
        // #40: -1 means the post-write verification failed — the body blocks did not
        // persist. Fail loudly rather than reporting a save that didn't land.
        if ($count < 0) {
            $this->_fail('Could not save the post content. Please reload and try again.');
        }

        // Echo back the resolved tag set (slugified/deduped) for the editor.
        $tags = $this->CmsPost->get_tags($postId);

        $this->_ok(array(
            'post_id'     => $postId,
            'slug'        => $slug,
            'block_count' => $count,
            'is_new'      => $isNew,
            'tags'        => $tags,
            // #49: latest block revision_id (see savepage) as the concurrency token.
            'version'     => $this->_latestRevisionId('post', $postId),
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

        $this->_publishEntity('post', $uid, $scope);
    }

    public function unpublishpost($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.publish', $scope);

        $this->_unpublishEntity('post', $uid, $scope);
    }

    /* ------------------------------------------------------------------ *
     * deletepost
     * ------------------------------------------------------------------ */

    public function deletepost($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        $this->_deleteEntity('post', $uid, $scope);
    }

    /* ================================================================== *
     * REVISIONS (C2) — capped block-set history + restore. Shared by pages
     * and posts (the block store is polymorphic).
     *
     * There is NO revision-browser UI wired to these two endpoints today: they
     * are the API half of the history store, reachable by any authorized client.
     * The store itself is very much live — every save writes a revision, and
     * _latestRevisionId reads the newest one as the optimistic-concurrency token
     * the editor round-trips — so these are an unfinished surface, not dead code.
     * ================================================================== */

    /**
     * List an owner's block revisions. GET/POST: owner_type=page|post, owner_id.
     * Gated the same as editing (page.edit / page.edit_own + scope ownership).
     */
    public function revisions($action = null)
    {
        $uid = $this->_begin(false);
        $scope = $this->_scope($uid);

        $ownerType = $this->_ownerType($_GET['owner_type'] ?? $_POST['owner_type'] ?? 'page');
        $ownerId   = (int)($_GET['owner_id'] ?? $_POST['owner_id'] ?? 0);
        $this->_requireOwnerEditable($uid, $ownerType, $ownerId, $scope);

        $list = $this->CmsPage->ListRevisions($ownerType, $ownerId);
        if (!is_array($list)) {
            $list = array();
        }
        $this->_ok(array('revisions' => $list, 'count' => count($list)));
    }

    /**
     * Restore an owner's blocks from a revision. POST: owner_type, owner_id,
     * revision_id. Same gating as editing.
     */
    public function restorerevision($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);

        $ownerType  = $this->_ownerType($_POST['owner_type'] ?? 'page');
        $ownerId    = (int)($_POST['owner_id'] ?? 0);
        $revisionId = (int)($_POST['revision_id'] ?? 0);
        $this->_requireOwnerEditable($uid, $ownerType, $ownerId, $scope);

        if ($revisionId <= 0) {
            $this->_fail('A revision id is required.', 4);
        }
        $ok = (bool)$this->CmsPage->RestoreRevision($revisionId, $ownerType, $ownerId);
        if (!$ok) {
            $this->_fail('Could not restore that revision.');
        }
        $this->_ok(array('owner_type' => $ownerType, 'owner_id' => $ownerId, 'restored' => $revisionId));
    }

    /* ================================================================== *
     * TRASH / UNDO (C2) — restore soft-deleted pages/posts/media + purge.
     * The editor lane's Trash/Undo UI calls these exact route names. All are
     * POST + CSRF-guarded (via _begin) and scope-IDOR-guarded (the lib restore
     * methods re-check the caller's scope where they carry one).
     * ================================================================== */

    /** Restore a trashed page. POST: page_id. Gated page.delete in scope. */
    public function restorepage($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        $this->_restoreEntity('page', $uid, $scope);
    }

    /** Restore a trashed post. POST: post_id. Gated page.delete in scope (posts
     *  share the page.delete capability — mirrors deletepost). */
    public function restorepost($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        $this->_restoreEntity('post', $uid, $scope);
    }

    /** Restore a trashed media item. POST: media_id. Gated media.manage in scope. */
    public function restoremedia($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId <= 0) {
            $this->_fail('Media not found.', 4);
        }
        $this->load_model('CmsMedia');
        // IDOR guard: the target must be in THIS scope's trash. get_media can't be
        // used (it hides trashed rows), so verify against the scope-filtered trash.
        $this->_requireTrashedMediaOwned($mediaId, $scope);
        // Scope passed through as well: RestoreMedia carries its own ownership
        // guard, and leaving it unarmed made the controller's check the ONLY one.
        $ok = (bool)$this->CmsMedia->RestoreMedia($mediaId, $uid, (string)$scope['type'], (int)$scope['id']);
        if (!$ok) {
            $this->_fail('Could not restore the media (it may not be in the Trash).');
        }
        $this->_ok(array('media_id' => $mediaId, 'restored' => true));
    }

    /** Permanently purge a trashed media item. POST: media_id. Gated media.manage. */
    public function purgemedia($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId <= 0) {
            $this->_fail('Media not found.', 4);
        }
        $this->load_model('CmsMedia');
        // IDOR guard: the target must be in THIS scope's trash. get_media can't be
        // used (it hides trashed rows), so verify against the scope-filtered trash.
        $this->_requireTrashedMediaOwned($mediaId, $scope);
        // Scope passed through as well (see restoremedia) — an irreversible delete
        // is the last place to rely on a single guard.
        $ok = (bool)$this->CmsMedia->PurgeMedia($mediaId, $uid, (string)$scope['type'], (int)$scope['id']);
        if (!$ok) {
            $this->_fail('Could not purge the media.');
        }
        $this->_ok(array('media_id' => $mediaId, 'purged' => true));
    }

    /**
     * List trashed posts for the Trash view. GET/POST: none (scope-derived).
     * Gated page.delete in scope (posts share the page.delete capability —
     * mirrors deletepost/restorepost). Returns the same row shape the Posts
     * list renders, minus the C2 deleted_at IS NULL gate.
     */
    public function listtrashedposts($action = null)
    {
        $uid   = $this->_begin(false);
        $scope = $this->_scope($uid);
        $this->_require($uid, 'page.delete', $scope);

        // PascalCase routes through Model::__call → the lib (no model.* snake-case
        // forwarder exists for this method), same path restorepost/restoremedia use.
        $list = $this->CmsPost->ListTrashed((string)$scope['type'], (int)$scope['id']);
        if (!is_array($list)) {
            $list = array();
        }
        $this->_ok(array('posts' => $list, 'count' => count($list)));
    }

    /**
     * List trashed media for the Trash view. Gated media.manage in scope
     * (mirrors medialist/restoremedia/purgemedia). Returns media-refs enriched
     * with filename/alt, minus the C2 deleted_at IS NULL gate.
     */
    public function listtrashedmedia($action = null)
    {
        $uid   = $this->_begin(false);
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $limit = $this->_clampLimit($_GET['limit'] ?? $_POST['limit'] ?? 200);

        $this->load_model('CmsMedia');
        // PascalCase routes through Model::__call → the lib (mirrors restoremedia).
        $list = $this->CmsMedia->ListTrashed((string)$scope['type'], (int)$scope['id'], $limit);
        if (!is_array($list)) {
            $list = array();
        }
        $this->_ok(array('media' => $list, 'count' => count($list)));
    }

    /**
     * Update a media item's authored metadata. POST: media_id + any of
     * alt / title / filename (only the keys PRESENT in the request are written,
     * so the inline alt editor and the full edit form share this endpoint).
     * Gated media.manage in scope. '' is a valid decorative-image alt, never
     * NULL; a blank filename is ignored (a rename can't clear the name).
     */
    public function mediaupdate($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId <= 0) {
            $this->_fail('Media not found.', 4);
        }

        // Build the update from only the fields the caller actually sent, so a
        // request that edits just the alt doesn't blank the title (and vice versa).
        $data = array();
        if (array_key_exists('alt', $_POST)) {
            $data['alt'] = (string)$_POST['alt'];
        }
        if (array_key_exists('title', $_POST)) {
            $data['title'] = (string)$_POST['title'];
        }
        if (array_key_exists('filename', $_POST)) {
            $data['filename'] = (string)$_POST['filename'];
        }
        if (empty($data)) {
            $this->_fail('Nothing to update.', 4);
        }

        $this->load_model('CmsMedia');
        // IDOR guard: never alter a media row belonging to another scope. Update
        // itself only touches non-trashed rows, which get_media also returns.
        $this->_requireOwned($this->CmsMedia->get_media($mediaId), $scope);
        $ok = (bool)$this->CmsMedia->Update($mediaId, $data, $uid, (string)$scope['type'], (int)$scope['id']);
        if (!$ok) {
            $this->_fail('Could not update the media (it may not exist or be in the Trash).');
        }

        // Echo the fresh row so the client can reflect the sanitized filename.
        $fresh = $this->CmsMedia->get_media($mediaId);
        $this->_ok(array(
            'media_id' => $mediaId,
            'alt'      => isset($fresh['alt']) ? (string)$fresh['alt'] : ($data['alt'] ?? ''),
            'title'    => isset($fresh['title']) ? (string)$fresh['title'] : ($data['title'] ?? ''),
            'filename' => isset($fresh['filename']) ? (string)$fresh['filename'] : ($data['filename'] ?? ''),
        ));
    }

    /**
     * Report where a media item is still used (pages/posts/logos/blocks + total).
     * GET or POST: media_id. Gated media.manage in scope. Read-only — surfaced by
     * the library's "Where used" affordance and the delete confirm so an officer
     * can see references BEFORE trying to delete an in-use image.
     */
    public function mediausage($action = null)
    {
        $uid   = $this->_begin(false);
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $mediaId = (int)($_GET['media_id'] ?? $_POST['media_id'] ?? 0);
        if ($mediaId <= 0) {
            $this->_fail('Media not found.', 4);
        }
        $this->load_model('CmsMedia');
        // IDOR guard: never disclose usage for a row belonging to another scope.
        $this->_requireOwned($this->CmsMedia->get_media($mediaId), $scope);

        $usage = $this->CmsMedia->ReferenceUsage($mediaId);
        if (!is_array($usage)) {
            $usage = array('pages' => 0, 'posts' => 0, 'logos' => 0, 'blocks' => 0, 'total' => 0);
        }
        $this->_ok(array('media_id' => $mediaId, 'usage' => $usage));
    }

    /**
     * Soft-delete (move to Trash) a media item. POST: media_id. Gated
     * media.manage in scope. REFUSES an in-use image (the lib's where-used
     * guard); on that refusal we return the usage breakdown so the UI can say
     * exactly where it's still referenced.
     */
    public function mediadelete($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $mediaId = (int)($_POST['media_id'] ?? 0);
        if ($mediaId <= 0) {
            $this->_fail('Media not found.', 4);
        }
        $this->load_model('CmsMedia');
        // IDOR guard: never delete a media row belonging to another scope.
        $this->_requireOwned($this->CmsMedia->get_media($mediaId), $scope);

        $ok = (bool)$this->CmsMedia->DeleteMedia($mediaId, $uid, (string)$scope['type'], (int)$scope['id']);
        if (!$ok) {
            // Most likely cause: still referenced. Surface the where-used breakdown
            // so the officer knows what to detach first (fail-safe: never orphan a
            // live image). A zero total means an unexpected write failure.
            $usage = $this->CmsMedia->ReferenceUsage($mediaId);
            $total = is_array($usage) ? (int)($usage['total'] ?? 0) : 0;
            if ($total > 0) {
                $this->_fail(
                    'This image is still used in ' . $total . ' place' . ($total === 1 ? '' : 's')
                    . '. Remove those references before deleting it.',
                    8,
                    array('usage' => $usage)
                );
            }
            $this->_fail('Could not delete the media (it may not exist or be in the Trash).');
        }

        // Soft-delete (C2): the media is moved to Trash, recoverable via restore.
        $this->_ok(array('media_id' => $mediaId, 'deleted' => true, 'trashed' => true));
    }

    /**
     * Bulk soft-delete media. POST: media_ids (JSON array or comma-separated).
     * Gated media.manage in scope. Each id is scope-checked (IDOR) and passed
     * through the same where-used guard as mediadelete: in-use or foreign ids are
     * SKIPPED (never silently deleted), and the response reports what happened.
     */
    public function mediabulkdelete($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $ids = $this->_parseIdList($_POST['media_ids'] ?? $_POST['ids'] ?? null);
        if (empty($ids)) {
            $this->_fail('No media were selected.', 4);
        }

        $this->load_model('CmsMedia');

        // #21: batch the per-id IDOR/scope check into ONE query instead of a
        // GetMedia round-trip per id. The query itself is the lib's job — this is
        // the IDOR guard, so it lives with the code that defines what "in scope"
        // and "trashed" mean (CmsMedia::FilterOwnedIds).
        $ownedSet = array();
        $ownedIds = array();
        $owned = $this->CmsMedia->filter_owned_ids($ids, (string)$scope['type'], (int)$scope['id']);
        foreach ((is_array($owned) ? $owned : array()) as $ownedId) {
            $ownedSet[(int)$ownedId] = true;
        }
        foreach ($ids as $mediaId) {
            // A row not owned by this scope (foreign / absent / already trashed) is
            // skipped, not fatal — one forged id can't abort the whole batch.
            if (!empty($ownedSet[$mediaId])) {
                $ownedIds[] = $mediaId;
            }
        }

        // One lib call for the whole set: the where-used scan runs once instead of
        // per id. Scope is passed through as well (see mediadelete/purgemedia):
        // the batched filter above is the enforced IDOR guard, but the lib re-checks
        // ownership per row — a delete is the last place to rely on a single guard.
        $batch = $this->CmsMedia->delete_media_batch(
            $ownedIds,
            $uid,
            (string)$scope['type'],
            (int)$scope['id']
        );
        $batch      = is_array($batch) ? $batch : array();
        $deletedSet = array_flip(array_map('intval', (array)($batch['deleted'] ?? array())));
        $inUseSet   = array_flip(array_map('intval', (array)($batch['in_use'] ?? array())));

        $deleted = array();
        $inUse   = array();
        $failed  = array();
        foreach ($ids as $mediaId) {
            if (isset($deletedSet[$mediaId])) {
                $deleted[] = $mediaId;
            } elseif (isset($inUseSet[$mediaId])) {
                $inUse[] = $mediaId;
            } else {
                $failed[] = $mediaId;
            }
        }

        $this->_ok(array(
            'deleted'       => $deleted,
            'deleted_count' => count($deleted),
            'in_use'        => $inUse,
            'in_use_count'  => count($inUse),
            'failed'        => $failed,
            'failed_count'  => count($failed),
        ));
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

        // Resolve the link target for the chosen link_type. Clearing the OTHER
        // columns on a type-switch is the lib's job now: passing a null through
        // yapo is a no-op (yapo drops nulls from the UPDATE), so CmsNav::UpdateItem
        // authoritatively NULLs the unused link columns based on $data['link_type'].
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

        // Only the ACTIVE target column is passed; link_type tells the lib which
        // columns are unused so UpdateItem can clear them (yapo can't clear via a
        // null, so the controller no longer tries to). On create the omitted
        // columns simply default to NULL.
        $data = array(
            'menu'      => 'marketing',
            'label'     => $label,
            'link_type' => $linkType,
            'parent_id' => $parentId,
            'enabled'   => $enabled,
            'scope_type' => (string)$scope['type'],
            'scope_id'  => (int)$scope['id'],
        );
        if ($linkType === 'page') {
            $data['page_id'] = $pageId;
        } elseif ($linkType === 'post') {
            $data['post_id'] = $postId;
        } else {
            // 'url' and 'dynamic' both store their target in the url column.
            $data['url'] = $url;
        }

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
            // Every rejection used to read the same way, so an org that had simply
            // run out of space was told its image was the wrong type — and would
            // keep retrying a file that was never the problem. Report the two
            // apart, with the numbers needed to act on it.
            if ($this->CmsMedia->last_error() === 'quota_exceeded') {
                $scopeType = (string)($scope['type'] ?? 'global');
                $usedMb    = round($this->CmsMedia->scope_usage_bytes($scopeType, (int)($scope['id'] ?? 0)) / 1048576, 1);
                $quotaMb   = round($this->CmsMedia->scope_quota_bytes($scopeType) / 1048576);
                $this->_fail(
                    'Your media library is full (' . $usedMb . ' MB of ' . $quotaMb . ' MB used). '
                    . 'Delete some images and empty the trash to free up space.'
                );
            }
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
        $uid = $this->_begin(false);
        $scope = $this->_scope($uid);
        $this->_require($uid, 'media.manage', $scope);

        $search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $search = ($search === '') ? null : $search;
        $limit  = $this->_clampLimit($_GET['limit'] ?? $_POST['limit'] ?? 200);
        // Optional windowed paging for the block-editor media picker's lazy-load.
        // Backward compatible: an absent (or 0) offset yields the original window.
        $offset = (int)($_GET['offset'] ?? $_POST['offset'] ?? 0);
        if ($offset < 0) {
            $offset = 0;
        }

        $this->load_model('CmsMedia');
        // SQL-level windowed paging: fetch limit+1 rows AT the offset (not a giant
        // over-fetch), so a scope with >1000 media stays fully reachable and the +1
        // sentinel reports has_more correctly. list_media applies LIMIT offset,count.
        $rows = $this->CmsMedia->list_media($scope, $limit + 1, $search, $offset);
        if (!is_array($rows)) {
            $rows = array();
        }
        $hasMore = count($rows) > $limit;
        $page    = array_slice($rows, 0, $limit);

        $this->_ok(array(
            'media'    => $page,
            'count'    => count($page),
            'offset'   => $offset,
            'limit'    => $limit,
            'has_more' => $hasMore,
        ));
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
        // SetActive returns false when it refuses (stale/forged id): report the
        // refusal instead of echoing a success the DB never performed.
        if (!$this->CmsTheme->set_active((string)$scope['type'], (int)$scope['id'], $id)) {
            $this->_fail('Could not activate that theme.', 4);
        }
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

        // EnsureSite: a never-opened site must still be publishable.
        $site = $this->_requireOrgSite($scope, $uid, true);
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

        // No EnsureSite here: a site that was never opened has nothing to unpublish.
        $site = $this->_requireOrgSite($scope, $uid, false);
        $this->CmsSite->set_draft((int)$site['site_id'], $uid);
        $this->_ok(array('status' => 'draft'));
    }

    /**
     * POST: save the resolved org site's identity/settings.
     *   site_name, logo_media_id, home_page_id → 'page.edit'
     *   slug                                   → 'page.publish' (AUTH_ADMIN tier)
     * so an edit-tier officer can name the site but not move its public URL.
     * Only keys actually present in the POST are written. Slug charset/reserved/
     * uniqueness and the logo/home-page IDOR checks are enforced authoritatively
     * in CmsSite::UpdateSite — its error string is surfaced verbatim.
     */
    public function savesite($action = null)
    {
        $uid = $this->_begin();
        $scope = $this->_scope($uid);
        if ($this->_scopeIsGlobal($scope)) {
            $this->_fail('The global front door has no org site settings.', 3);
        }
        $this->_require($uid, 'page.edit', $scope);

        $site = $this->_requireOrgSite($scope, $uid, true);

        $fields = array();
        if (array_key_exists('site_name', $_POST)) {
            $fields['site_name'] = trim((string)$_POST['site_name']);
        }
        if (array_key_exists('logo_media_id', $_POST)) {
            $fields['logo_media_id'] = (string)$_POST['logo_media_id'];
        }
        if (array_key_exists('home_page_id', $_POST)) {
            $fields['home_page_id'] = (string)$_POST['home_page_id'];
        }
        if (array_key_exists('slug', $_POST)) {
            // The public URL is an admin-tier change even for an officer who may
            // otherwise edit the site's content and name.
            if (!$this->CmsAuth->cms_can($uid, 'page.publish', $scope)) {
                $this->_denyCapability('page.publish');
            }
            $fields['slug'] = trim((string)$_POST['slug']);
        }

        if (empty($fields)) {
            $this->_fail('Nothing to save.', 4);
        }

        $res = $this->CmsSite->update_site((int)$site['site_id'], $fields, $uid);
        if ($res !== true) {
            $this->_fail(is_string($res) && $res !== '' ? $res : 'Could not save the site settings.', 2);
        }

        // Echo the stored row back so the card can re-render the canonical values
        // (the slug is normalized by DeriveSlug on the way in).
        $saved = $this->CmsSite->get_site_for_scope((string)$scope['type'], (int)$scope['id']);
        $saved = is_array($saved) ? $saved : array();
        $this->_ok(array(
            'site_name'    => (string)($saved['site_name'] ?? ''),
            'slug'         => (string)($saved['slug'] ?? ''),
            'home_page_id' => (int)($saved['home_page_id'] ?? 0),
        ));
    }

    /* ------------------------------------------------------------------ *
     * clearrendercache — force-refresh the public site's live-data blocks
     * ------------------------------------------------------------------ */

    /**
     * E71/#71: bust the GhettoCache entries the front-door live-data blocks
     * populate (300s TTL), so an officer can force their public site to pick up
     * an officer change / new park immediately instead of waiting the window out.
     * POST + CSRF-guarded (via _begin) and scope-checked (page.edit in scope).
     * Only the ACTING scope is cleared — a kingdom officer cannot flush another
     * org's cache, and cannot flush the shared global one either.
     *
     * GhettoCache/Memcached has no prefix scan, so the bounded key space is
     * enumerated exactly. Both the enumeration AND the key formats/clamp bounds
     * it depends on come from CmsRenderCache, which the block partials also read
     * — the two used to declare them independently, and a drift there fails
     * silently (this endpoint happily reports a large `cleared` count while
     * busting keys nothing ever wrote).
     *
     * Coverage by scope:
     *   kingdom → kingdom_officers, kingdom_parks, kingdom_parks_map
     *   park    → park_officers, park_meeting, park_hero, park_strip
     *   global  → kingdoms_teaser (front-door only; it is the one cached block
     *             with no org in its key, which is exactly why it went unbusted)
     * kingdom_events / park_events are NOT here: they render from
     * SearchService::Event, which caches internally and exposes no bust hook.
     */
    public function clearrendercache($action = null)
    {
        $uid   = $this->_begin();
        $scope = $this->_scope($uid);
        // Editing the site is the bar for forcing its public cache to refresh.
        $this->_require($uid, 'page.edit', $scope);

        // Key-space enumeration and the cache handle both live in CmsRenderCache —
        // the controller only says WHICH scope to flush and reports the count.
        $cleared = CmsRenderCache::BustScope((string)$scope['type'], (int)$scope['id']);

        // Echoed back so the caller can confirm what was flushed. The org blocks
        // render nothing outside their own scope, so at most one is ever non-zero.
        $kid = ((string)$scope['type'] === 'kingdom') ? (int)$scope['id'] : 0;
        $pid = ((string)$scope['type'] === 'park') ? (int)$scope['id'] : 0;

        $this->_ok(array(
            'cleared'    => $cleared,
            'kingdom_id' => $kid,
            'park_id'    => $pid,
            // Whether the flush targeted a single org (vs. the global front door).
            'scoped'     => ($kid > 0 || $pid > 0),
        ));
    }

    /* ------------------------------------------------------------------ *
     * runmaintenance — super-admin housekeeping sweep
     * ------------------------------------------------------------------ */

    /**
     * E117/#117: super-admin-only maintenance cleanup — hard-purge long-trashed
     * pages (+ their blocks/revisions), sweep orphaned blocks, and prune zero-usage
     * post tags (all non-destructive to live rows; only trashed/orphaned data is
     * removed). POST + CSRF-guarded. The heavy lifting lives in the CmsPage /
     * CmsPost libs; each call is method_exists-guarded so the endpoint degrades
     * gracefully (0 counts) rather than fataling if a lib method is unavailable.
     */
    public function runmaintenance($action = null)
    {
        $uid   = $this->_begin();
        // Called for its side effect only: _scope() re-validates the request's
        // scope selector and refuses (JSON 403 + exit) a malformed or
        // unauthorized one, so a bad selector can never reach the sweep. The
        // sweep itself is cross-org by definition, so the resolved value is
        // deliberately unused.
        $this->_scope($uid);
        // Cross-org housekeeping is a strictly super-admin action.
        if (!$this->CmsAuth->IsSuperAdmin($uid)) {
            $this->_denyCapability('super-admin');
        }

        $olderThanDays = (int)($_POST['older_than_days'] ?? 30);
        if ($olderThanDays < 1) {
            $olderThanDays = 30;
        }

        $summary = array(
            'pages'         => 0,
            'blocks'        => 0,
            'revisions'     => 0,
            'orphan_blocks' => 0,
            'tags_pruned'   => 0,
        );
        $ran = array();

        // PurgeTrashed hard-deletes aged-out trashed pages and their blocks/
        // revisions AND runs the orphan-block sweep itself (so SweepOrphanBlocks is
        // NOT called separately here — that would double-count). Returns per-bucket
        // counts ['pages','blocks','revisions','orphan_blocks'].
        if (method_exists('CmsPage', 'PurgeTrashed')) {
            $purge = $this->CmsPage->PurgeTrashed($olderThanDays);
            if (is_array($purge)) {
                $summary['pages']         = (int)($purge['pages'] ?? 0);
                $summary['blocks']        = (int)($purge['blocks'] ?? 0);
                $summary['revisions']     = (int)($purge['revisions'] ?? 0);
                $summary['orphan_blocks'] = (int)($purge['orphan_blocks'] ?? 0);
            }
            $ran[] = 'purge_trashed';
        }
        // Prune zero-usage post tags (returns the count of removed tag rows).
        if (method_exists('CmsPost', 'PruneUnusedTags')) {
            $summary['tags_pruned'] = (int)$this->CmsPost->PruneUnusedTags();
            $ran[] = 'prune_tags';
        }

        $this->_ok(array(
            'summary' => $summary,
            // Which sweeps actually ran (the lib method existed) — so the UI can
            // distinguish "0 cleaned" from "not yet available".
            'ran'     => $ran,
        ));
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
        $uid = $this->_begin(false);
        $scope = $this->_scope($uid);
        // #25: the roster editor is reachable by contributors too — gate on
        // page.edit OR page.edit_own (an edit_own contributor building their own
        // draft must be able to resolve a persona), not page.edit alone.
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', $scope)
            && !$this->CmsAuth->cms_can($uid, 'page.edit_own', $scope)
        ) {
            $this->_fail('You are not authorized to perform this action.', 5);
        }

        $mundaneId = (int)($_GET['mundane_id'] ?? $_POST['mundane_id'] ?? 0);
        if ($mundaneId <= 0) {
            $this->_fail('A valid person id is required.', 4);
        }

        $this->load_model('Player');
        $info = $this->Player->player_info($mundaneId);
        if (!$info || empty($info['Persona'])) {
            $this->_fail('Person not found.', 4);
        }

        // #23: real names are resolvable ONLY behind the CMS capability boundary
        // AND only for people WITHIN the caller's resolved scope — a kingdom/park
        // officer must not resolve arbitrary system-wide personas to real names.
        // Global scope (the front door / super-admin) is unrestricted by design.
        $scopeType = (string)$scope['type'];
        $scopeId   = (int)$scope['id'];
        if ($scopeType === 'kingdom' && (int)($info['KingdomId'] ?? 0) !== $scopeId) {
            $this->_fail('That person is not a member of this site\'s kingdom.', 4);
        }
        if ($scopeType === 'park' && (int)($info['ParkId'] ?? 0) !== $scopeId) {
            $this->_fail('That person is not a member of this site\'s park.', 4);
        }

        $mundaneName = trim(($info['GivenName'] ?? '') . ' ' . ($info['Surname'] ?? ''));
        $this->_ok(array(
            'mundane_id'   => $mundaneId,
            'persona'      => (string)$info['Persona'],
            'mundane_name' => $mundaneName,
        ));
    }

    /* ------------------------------------------------------------------ *
     * pagelist — page-chooser list for the block editor's link control
     * ------------------------------------------------------------------ */

    /**
     * GET: the pages in the caller's resolved scope, each with the PUBLIC href
     * an author's link should carry, so the block editor can offer "a page on
     * this site" instead of asking a volunteer to copy a framework route out of
     * the address bar.
     *
     * Scope + capability handling mirrors personlookup (the other editor-support
     * read): the scope selector is re-validated server-side by _scope(), and the
     * gate accepts page.edit OR page.edit_own OR page.create, because every
     * context that can place a link is one of those three.
     *
     * IDOR: the list is read with CmsPage::ListPagesForScope(), whose scope is
     * MANDATORY, so a foreign org's pages can never appear. The optional
     * `page_id` lookup (used to resolve a stored href back to a page name) goes
     * through the same _requireOwned() guard every by-id mutation uses, so an id
     * from another scope is refused rather than resolved.
     */
    public function pagelist($action = null)
    {
        $uid   = $this->_begin(false);
        $scope = $this->_scope($uid);
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', $scope)
            && !$this->CmsAuth->cms_can($uid, 'page.edit_own', $scope)
            && !$this->CmsAuth->cms_can($uid, 'page.create', $scope)
        ) {
            $this->_denyCapability(array('page.edit', 'page.edit_own', 'page.create'));
        }

        // Single-page resolve (round-tripping a stored href back to a title).
        // Scope-guarded exactly like every by-id path in this controller.
        $pageId = (int)($_GET['page_id'] ?? $_POST['page_id'] ?? 0);
        if ($pageId > 0) {
            $row = $this->CmsPage->get_page($pageId);
            if (!is_array($row) || !empty($row['deleted_at'])) {
                $this->_fail('Page not found.', 4);
            }
            $this->_requireOwned($row, $scope);
            $this->_ok(array('pages' => array($this->_pageChoice($scope, $row, null)), 'count' => 1));
        }

        $search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $limit  = $this->_clampLimit($_GET['limit'] ?? $_POST['limit'] ?? 300, 300, 500);

        $rows = $this->CmsPage->list_pages_for_scope(
            (string)$scope['type'],
            (int)$scope['id'],
            ($search === '' ? null : $search),
            $limit
        );
        if (!is_array($rows)) {
            $rows = array();
        }

        // One in-memory parent_id walk for the whole list instead of a PagePath()
        // DB round-trip per row (#13) — the same map the admin page list builds.
        $pathMap = $this->_buildScopePathMap($rows);

        $out = array();
        foreach ($rows as $row) {
            $out[] = $this->_pageChoice($scope, $row, $pathMap);
        }

        $this->_ok(array(
            'pages' => $out,
            'count' => count($out),
            'limit' => $limit,
        ));
    }

    /**
     * One pagelist row -> the chooser's wire shape. `href` is the SAME public URL
     * the admin page list links to, so a chosen page stores exactly what an
     * author would have pasted by hand.
     *
     * @param array      $scope
     * @param array      $row     a cms_page row
     * @param array|null $pathMap optional pageId => slug-path map
     * @return array
     */
    private function _pageChoice($scope, $row, $pathMap)
    {
        $pageId = (int)($row['page_id'] ?? 0);
        $slug   = (string)($row['slug'] ?? '');
        return array(
            'page_id' => $pageId,
            'title'   => (string)($row['title'] ?? ''),
            'slug'    => $slug,
            'status'  => (string)($row['status'] ?? 'draft'),
            'href'    => $this->_pageLiveHref($scope, $pageId, $slug, $pathMap),
        );
    }

    /* ------------------------------------------------------------------ *
     * Internal helpers
     * ------------------------------------------------------------------ */

    /**
     * Parse a posted id list into a de-duplicated array of positive ints.
     * Accepts a JSON array (["1","2"]), a PHP array, or a comma-separated
     * string ("1,2,3"). Non-numeric / <=0 entries are dropped. Capped at 200
     * so a single bulk request can't fan out into an unbounded scan.
     */
    private function _parseIdList($raw)
    {
        if ($raw === null || $raw === '') {
            return array();
        }
        $list = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($list)) {
            // Fall back to comma-separated parsing for a plain string.
            $list = explode(',', (string)$raw);
        }
        $out = array();
        foreach ($list as $v) {
            $id = (int)$v;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
                if (count($out) >= 200) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Clamp a posted list-window size to a sane range.
     *
     * NOTE the deliberate asymmetry, preserved from both call sites: an
     * over-large request falls back to the DEFAULT, it is not truncated to $max.
     * A client asking for 5000 rows is not asking for 500 — it is a client that
     * has lost track of what it wants, and quietly handing it the ceiling would
     * mask that while still paying for the biggest page we allow.
     *
     * @param mixed $raw     the posted value, unvalidated
     * @param int   $default fallback for a non-positive or over-large request
     * @param int   $max     largest window a caller may actually ask for
     * @return int
     */
    private function _clampLimit($raw, $default = 200, $max = 500)
    {
        $limit = (int)$raw;
        return ($limit <= 0 || $limit > (int)$max) ? (int)$default : $limit;
    }

    /**
     * Resolve the acting org's site row for the three site-lifecycle endpoints,
     * or emit the JSON refusal and exit.
     *
     * The global-scope rejection and the capability gate stay INLINE in each
     * endpoint on purpose: publish/unpublish refuse the front door with a
     * different sentence than savesite does, and savesite gates on 'page.edit'
     * where the other two gate on 'page.publish'. Only the resolve-or-fail step
     * is genuinely common.
     *
     * @param array $scope  the resolved, authorized (non-global) request scope
     * @param int   $uid    acting mundane_id (EnsureSite records the creator)
     * @param bool  $ensure true → EnsureSite (create on first use);
     *                      false → read only (nothing to act on if absent)
     * @return array the site row (guaranteed to carry a positive site_id)
     */
    private function _requireOrgSite($scope, $uid, $ensure)
    {
        $this->load_model('CmsSite');
        $site = $ensure
            ? $this->CmsSite->ensure_site((string)$scope['type'], (int)$scope['id'], $uid)
            : $this->CmsSite->get_site_for_scope((string)$scope['type'], (int)$scope['id']);
        if (empty($site) || empty($site['site_id'])) {
            $this->_fail('Could not resolve the site.', 4);
        }
        return $site;
    }

    /**
     * Resolve the posted hero_media_id for savepage/savepost.
     *
     * Three-way result, because "not sent" and "sent as empty" mean different
     * things to the caller: a request that omits the field must leave the stored
     * hero UNTOUCHED, while a request that sends a blank/forged one must CLEAR
     * it. Returning null for both would silently drop every page's hero image on
     * any save that didn't happen to include the field.
     *
     * @param array $scope the resolved, authorized request scope
     * @return int|null|false the in-scope media id, null to clear it, or FALSE
     *                        when the field was not sent at all (leave as-is)
     */
    private function _resolveHeroMediaId($scope)
    {
        if (!array_key_exists('hero_media_id', $_POST)) {
            return false;
        }
        $hero = (int)$_POST['hero_media_id'];
        // Only honor an in-scope media id; a cross-scope (forged) id is dropped.
        $this->load_model('CmsMedia');
        return ($hero > 0 && $this->_rowInScope($this->CmsMedia->get_media($hero), $scope))
            ? $hero : null;
    }

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
     *
     * @param bool $mutating true (the default) for every state-changing endpoint:
     *        the request MUST be a POST carrying the synchronizer token. Read-only
     *        endpoints (medialist, pagelist, personlookup, mediausage, revisions,
     *        listtrashedposts/media) pass false — a GET read needs no token, but a
     *        POST to one is still token-checked.
     * @return int the acting mundane_id
     */
    private function _begin($mutating = true)
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $uid = $this->_uid();
        if ($uid <= 0) {
            $this->_fail('You must be logged in.', 5);
        }

        // CSRF: a state-changing request must arrive as POST *and* carry the
        // per-session synchronizer token (sent by the editor JS as the
        // X-CSRF-Token header). Gating the token on the METHOD alone left the
        // parameterless mutations — publishsite/unpublishsite/resettheme/
        // savetheme/clearrendercache/runmaintenance, whose only input is the
        // ?scope= selector the query string already supplies — reachable by a
        // bare cross-site GET with the check skipped, so the method itself is
        // now part of the requirement. GET reads (medialist, personlookup,
        // pagelist, mediausage, revisions, listtrashed*) pass $mutating=false
        // and stay exempt.
        $isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
        if ($mutating && !$isPost) {
            $this->_fail('This action must be requested with POST.', 1);
        }
        // Every POST is token-checked — including a POST to a read-only endpoint.
        // A mutating request has already been forced to POST just above, so this
        // one test covers both cases.
        if ($isPost) {
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
            // #129: name the missing capability so the client can surface exactly
            // what the user lacks (and window.CMS_CAPS can pre-disable the action).
            $this->_denyCapability($capability);
        }
    }

    /**
     * #129: authorization-denied response that NAMES the missing capability (in
     * both the human message and a machine-readable `capability` key), instead of
     * the generic "not authorized". $capability may be a string or a list of
     * acceptable capabilities (any-of).
     *
     * @param string|string[] $capability
     * @return void (emits JSON + exit)
     */
    private function _denyCapability($capability)
    {
        $caps = is_array($capability) ? array_values(array_map('strval', $capability)) : array((string)$capability);
        $label = implode(' or ', $caps);
        $this->_fail(
            'You are not authorized to perform this action (requires: ' . $label . ').',
            5,
            array('capability' => (count($caps) === 1) ? $caps[0] : $caps)
        );
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

    /**
     * IDOR guard for trashed media (restore/purge). GetMedia hides trashed rows,
     * so ownership is verified against the scope-filtered trash list: a media id
     * not in THIS scope's trash (foreign org, or not trashed) is rejected.
     *
     * @param int   $mediaId
     * @param array $scope the resolved, authorized request scope
     * @return void
     */
    private function _requireTrashedMediaOwned($mediaId, $scope)
    {
        $mediaId = (int)$mediaId;
        $this->load_model('CmsMedia');
        $trashed = $this->CmsMedia->ListTrashed((string)$scope['type'], (int)$scope['id'], 1000);
        if (is_array($trashed)) {
            foreach ($trashed as $row) {
                if ((int)($row['media_id'] ?? 0) === $mediaId) {
                    return;
                }
            }
        }
        $this->_fail('You are not authorized to modify this content.', 5);
    }

    /** Emit {ok:true, ...$extra} and exit. */
    private function _ok($extra = array())
    {
        echo json_encode(array('ok' => true) + (is_array($extra) ? $extra : array()));
        exit;
    }

    /**
     * Emit {ok:false, status, error, ...$extra} and exit.
     *
     * $extra carries the machine-readable payload a few refusals attach for the
     * client (mediadelete's `usage` breakdown, _denyCapability's `capability`).
     * It is unioned with LEFT precedence exactly like _ok, so an $extra key can
     * never overwrite ok/status/error — the envelope's three guaranteed keys stay
     * first and stay authoritative.
     *
     * @param string $message
     * @param int    $status
     * @param array  $extra
     * @return void (emits JSON + exit)
     */
    private function _fail($message, $status = 1, $extra = array())
    {
        echo json_encode(array(
            'ok'     => false,
            'status' => (int)$status,
            'error'  => (string)$message,
        ) + (is_array($extra) ? $extra : array()));
        exit;
    }

    /**
     * Decode the posted block list (a JSON array string) into the renderer-shape
     * block array CmsPage::ReplaceBlocks consumes. Invalid/empty → empty array.
     *
     * Field CONTENT is not sanitized here: every writer reaches ReplaceBlocks,
     * whose CmsPage::_normalizeBlocks pass cleans the authored-HTML and URL fields
     * unconditionally (and strictly more tightly than this controller ever did).
     * What stays here is the write-side TYPE vocabulary — the canonical allowlist
     * and the nested-columns rejection — which the lib deliberately does not own,
     * because seeding and imports may legitimately carry types the editor cannot
     * add by hand.
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

        // #42: the canonical allowlist comes from Controller_Cms (single source of
        // truth); resolve it once, not per block.
        $allowedTypes = Controller_Cms::CanonicalBlockTypes();

        $out = array();
        foreach ($decoded as $block) {
            if (!is_array($block) || empty($block['type'])) {
                continue;
            }
            // Drop blocks whose type is not in the canonical catalog (forged/unknown).
            if (!in_array((string)$block['type'], $allowedTypes, true)) {
                continue;
            }
            $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();
            // #103: defense-in-depth — a `columns` block must never contain another
            // `columns` child (the render side already caps recursion depth). Strip
            // any nested columns at save so the invalid structure never persists.
            if ((string)$block['type'] === 'columns') {
                $fields = $this->_rejectNestedColumns($fields);
            }
            $out[] = array(
                // C15: carry the STABLE block id so CmsPage::ReplaceBlocks can
                // upsert in place (preserve the row) instead of delete-all/reinsert.
                // 0/absent = a brand-new block. Editor contract: echo back each
                // block's server id and resend it on the next save.
                'id'      => isset($block['id']) ? (int)$block['id'] : 0,
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
     * #103: strip any `columns` child from a columns block's column lists so a
     * columns-in-columns structure can never be persisted (defense-in-depth; the
     * render side also caps recursion depth). Each entry in fields['columns'] is
     * an ordered list of child block objects ({type,...}); a child of type
     * 'columns' is dropped.
     *
     * @param array $fields the columns block's fields
     * @return array the same fields with nested columns removed
     */
    private function _rejectNestedColumns(array $fields)
    {
        if (!isset($fields['columns']) || !is_array($fields['columns'])) {
            return $fields;
        }
        $cols = array();
        foreach ($fields['columns'] as $col) {
            if (!is_array($col)) {
                $cols[] = $col;
                continue;
            }
            $kept = array();
            foreach ($col as $child) {
                if (is_array($child) && isset($child['type']) && (string)$child['type'] === 'columns') {
                    continue; // reject nested columns
                }
                $kept[] = $child;
            }
            $cols[] = array_values($kept);
        }
        $fields['columns'] = $cols;
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

    /**
     * Clamp the page type to the supported enum. #110: the allowlist is the
     * canonical page-type key set owned by Controller_Cms (the SAME list that
     * drives the presets/labels), so a type added to the presets can never be
     * silently clamped back to 'composed' here.
     */
    private function _normalizeType($type)
    {
        $allowed = Controller_Cms::CanonicalPageTypes();
        return in_array($type, $allowed, true) ? $type : 'composed';
    }

    /**
     * Clamp a nav link_type to the supported enum (default 'page'). AJAX-7: the
     * enum itself is CmsNav::LINK_TYPES — the lib that stores the column owns the
     * vocabulary, so the controller's accept-list can never drift from it. The
     * lib's own _normalizeLinkType stays private: we share the DATA, not the
     * method (the controller normalizes BEFORE choosing which target column to
     * validate, well ahead of any write).
     */
    private function _normalizeNavLinkType($linkType)
    {
        $linkType = strtolower(trim((string)$linkType));
        return in_array($linkType, CmsNav::LINK_TYPES, true) ? $linkType : 'page';
    }

    /**
     * Clamp a polymorphic owner_type to the two the block store supports.
     * Anything that is not exactly 'post' is a page — the same fail-to-page rule
     * the revisions endpoints, the concurrency token and the editable-owner gate
     * each spelled out inline, which is how a third owner kind could once have
     * been read as 'page' by one site and rejected by another.
     *
     * Deliberately NOT paired with a generic _param() reader: the $_GET/$_POST
     * split around these calls is a CSRF posture (reads may accept GET, mutations
     * are POST-only), not incidental inconsistency.
     *
     * @param mixed $raw
     * @return string 'page' | 'post'
     */
    private function _ownerType($raw)
    {
        return ((string)$raw === 'post') ? 'post' : 'page';
    }

    /**
     * C15 optimistic-concurrency guard. The client sends the version token it
     * loaded (base_version = the owner row's updated_at at load time). If the
     * stored row is NEWER, someone else saved in the meantime → reject with a
     * conflict (status 12) instead of a silent last-write-wins. A missing token
     * (legacy client) skips the check.
     *
     * Editor contract (seam): read `version` from every load AND every save
     * response, and resend it as POST `base_version` on the next save.
     *
     * #49: the durable token is the owner's LATEST block revision_id — a
     * monotonic integer bumped by every ReplaceBlocks — which the save responses
     * echo and the client resends. A revision-id token is compared exactly and
     * rejects on ANY change (no more second-granular strtotime() collision, and
     * no schema change: the revision table already exists). A DATETIME token
     * (only the very first save after a fresh page load, before a save response
     * has handed the client a revision id — the initial token is emitted by the
     * editor template) falls back to the legacy timestamp compare.
     *
     * @param array  $existing  the loaded owner row (carries updated_at)
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @return void
     */
    private function _guardConcurrency($existing, $ownerType = 'page', $ownerId = 0)
    {
        $baseVersion = trim((string)($_POST['base_version'] ?? ''));
        if ($baseVersion === '') {
            return;   // no token supplied — preserve legacy behavior
        }

        // Revision-id token (all saves after the first): exact compare, reject on
        // any mismatch. A base of 0 means "loaded with no revisions yet".
        if (ctype_digit($baseVersion)) {
            $current = $this->_latestRevisionId($ownerType, (int)$ownerId);
            if ((int)$baseVersion !== $current) {
                $this->_fail(
                    'This content was changed by someone else after you loaded it. '
                    . 'Reload to get the latest version before saving.',
                    12
                );
            }
            return;
        }

        // Legacy datetime token fallback (first save after a fresh load).
        $stored = (is_array($existing) && isset($existing['updated_at'])) ? (string)$existing['updated_at'] : '';
        if ($stored === '') {
            return;
        }
        $storedTs = strtotime($stored);
        $baseTs   = strtotime($baseVersion);
        if ($storedTs !== false && $baseTs !== false && $storedTs > $baseTs) {
            $this->_fail(
                'This content was changed by someone else after you loaded it. '
                . 'Reload to get the latest version before saving.',
                12
            );
        }
    }

    /**
     * #49: the owner's latest block revision_id (0 when none) — the concurrency
     * token. ListRevisions is newest-first, so the first row is the latest.
     *
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @return int
     */
    private function _latestRevisionId($ownerType, $ownerId)
    {
        $ownerType = $this->_ownerType($ownerType);
        $list = $this->CmsPage->ListRevisions($ownerType, (int)$ownerId, 1);
        if (is_array($list) && isset($list[0]['revision_id'])) {
            return (int)$list[0]['revision_id'];
        }
        return 0;
    }

    /**
     * Publish-or-schedule a page/post from a posted published_at (C7). A future
     * timestamp schedules (status='scheduled', promoted to published on read once
     * due); a past/empty timestamp publishes immediately. Returns the resulting
     * status so the caller can echo it.
     *
     * The timestamp is passed IN rather than read from $_POST here: this is the
     * one piece of request state the publish path needs, and taking it as an
     * argument keeps the helper callable from anywhere (and testable) instead of
     * being silently coupled to a specific POST field name.
     *
     * @param string $kind    'page' | 'post'
     * @param int    $id
     * @param int    $uid     acting mundane_id
     * @param mixed  $rawWhen the raw published_at the client sent ('' = now)
     * @return string 'published' | 'scheduled'
     */
    private function _applyPublish($kind, $id, $uid, $rawWhen)
    {
        $model = ($kind === 'post') ? $this->CmsPost : $this->CmsPage;

        $rawWhen = trim((string)$rawWhen);
        $when = ($rawWhen !== '') ? strtotime($rawWhen) : false;

        if ($when !== false && $when > time()) {
            // #63: the set_status wrapper now mirrors the lib's 4-param signature
            // and forwards published_at, so a future timestamp schedules correctly
            // (no more __call bypass to reach the 4-param SetStatus).
            $model->set_status((int)$id, 'scheduled', (int)$uid, date('Y-m-d H:i:s', $when));
            return 'scheduled';
        }
        $model->set_status((int)$id, 'published', (int)$uid);
        return 'published';
    }

    /* ------------------------------------------------------------------ *
     * page/post handler bodies
     *
     * The four lifecycle endpoints exist twice — once per owner kind — because
     * their ROUTE names and their response id keys are a client contract
     * ('page_id' vs 'post_id'; the editor JS reads them by name). Only the body
     * is shared, and only along the page/post axis: publish and unpublish stay
     * separate handlers (scheduling, a reload for the stamped published_at, and a
     * different response shape are not "the same code with a flag"), as do the
     * media variants (different capability, different lib, different failure
     * taxonomy — and purge is irreversible, so looking different is a feature).
     * ------------------------------------------------------------------ */

    /**
     * publish / publishpost: publish-or-schedule the posted id and echo the
     * resulting status + stored published_at.
     *
     * @param string $kind  'page' | 'post'
     * @param int    $uid
     * @param array  $scope the resolved, authorized request scope
     * @return void (emits JSON + exit)
     */
    private function _publishEntity($kind, $uid, $scope)
    {
        $kind   = $this->_ownerType($kind);
        $model  = ($kind === 'post') ? $this->CmsPost : $this->CmsPage;
        $getter = 'get_' . $kind;

        $id = (int)($_POST[$kind . '_id'] ?? 0);
        $this->_loadOwnedEntity($kind, $id, $scope);

        // C7: a future published_at schedules instead of publishing now; the read
        // path promotes it to 'published' once that time passes.
        $status = $this->_applyPublish($kind, $id, $uid, $_POST['published_at'] ?? '');
        // Re-read: set_status stamps published_at, and the client renders it.
        // The STORED status is echoed rather than the one we asked for — a write
        // refused by a concurrent trash must not be reported as a status change
        // (the row falls back to the requested status only if the re-read failed).
        $row = $model->$getter($id);
        if (isset($row['status']) && (string)$row['status'] !== '') {
            $status = (string)$row['status'];
        }

        $this->_ok(array(
            $kind . '_id'  => $id,
            'status'       => $status,
            'published_at' => isset($row['published_at']) ? $row['published_at'] : null,
        ));
    }

    /**
     * unpublish / unpublishpost: return the posted id to draft.
     *
     * @param string $kind  'page' | 'post'
     * @param int    $uid
     * @param array  $scope the resolved, authorized request scope
     * @return void (emits JSON + exit)
     */
    private function _unpublishEntity($kind, $uid, $scope)
    {
        $kind  = $this->_ownerType($kind);
        $model = ($kind === 'post') ? $this->CmsPost : $this->CmsPage;

        $id = (int)($_POST[$kind . '_id'] ?? 0);
        $this->_loadOwnedEntity($kind, $id, $scope);

        $model->set_status($id, 'draft', $uid);

        // Echo the STORED status (see _publishEntity): a write refused by a
        // concurrent trash must not be reported to the editor as a draft.
        $getter = 'get_' . $kind;
        $row = $model->$getter($id);
        $status = (isset($row['status']) && (string)$row['status'] !== '') ? (string)$row['status'] : 'draft';

        $this->_ok(array(
            $kind . '_id' => $id,
            'status'      => $status,
        ));
    }

    /**
     * deletepage / deletepost: soft-delete (C2 Trash) the posted id.
     * The is_system refusal is page-only — posts carry no such column.
     *
     * @param string $kind  'page' | 'post'
     * @param int    $uid
     * @param array  $scope the resolved, authorized request scope
     * @return void (emits JSON + exit)
     */
    private function _deleteEntity($kind, $uid, $scope)
    {
        $kind  = $this->_ownerType($kind);
        $model = ($kind === 'post') ? $this->CmsPost : $this->CmsPage;

        $id = (int)($_POST[$kind . '_id'] ?? 0);
        // Includes the IDOR guard: never delete content belonging to another scope.
        $row = $this->_loadOwnedEntity($kind, $id, $scope);
        if ($kind === 'page' && !empty($row['is_system'])) {
            $this->_fail('System pages cannot be deleted.', 3);
        }

        $deleter = 'delete_' . $kind;
        $deleted = (bool)$model->$deleter($id, (string)$scope['type'], (int)$scope['id'], $uid);
        if (!$deleted) {
            $this->_fail('Could not delete the ' . $kind . '.');
        }

        // Soft-delete (C2): the row is moved to Trash, recoverable via restore.
        $this->_ok(array($kind . '_id' => $id, 'deleted' => true, 'trashed' => true));
    }

    /**
     * restorepage / restorepost: bring the posted id back out of the Trash.
     * The scope IDOR guard is the LIB's here (RestorePage/RestorePost take the
     * caller's scope) because a trashed row is invisible to get_page/get_post.
     *
     * @param string $kind  'page' | 'post'
     * @param int    $uid
     * @param array  $scope the resolved, authorized request scope
     * @return void (emits JSON + exit)
     */
    private function _restoreEntity($kind, $uid, $scope)
    {
        $kind  = $this->_ownerType($kind);
        $model = ($kind === 'post') ? $this->CmsPost : $this->CmsPage;

        $id = (int)($_POST[$kind . '_id'] ?? 0);
        if ($id <= 0) {
            $this->_fail(ucfirst($kind) . ' not found.', 4);
        }

        $restore = 'Restore' . ucfirst($kind);
        $ok = (bool)$model->$restore($id, (string)$scope['type'], (int)$scope['id'], $uid);
        if (!$ok) {
            // A slug collision with a LIVE row is the one recoverable failure —
            // name it, so the officer knows to rename the other one first.
            if ($model->RestoreSlugConflict($id)) {
                $this->_fail(
                    'A live ' . $kind . ' already uses this address (slug). '
                    . 'Rename that ' . $kind . ', then restore this one.'
                );
            }
            $this->_fail('Could not restore the ' . $kind . ' (it may not be in the Trash).');
        }
        $this->_ok(array($kind . '_id' => $id, 'restored' => true));
    }

    /**
     * Load a page/post by id for a by-id mutation, or emit the JSON refusal and
     * exit: the id <= 0 / not-found check ("<Noun> not found.", status 4) followed
     * by the scope-ownership IDOR guard. Returns the row.
     *
     * This is the LOAD half only. The _begin()/_scope()/_require() preamble stays
     * spelled out in every endpoint deliberately — _begin() is where the CSRF
     * check lives, and its literal presence at the top of each handler is how a
     * reader (or reviewer) sees that CSRF is enforced without following a call.
     *
     * NOT used for media: those endpoints intentionally have no not-found branch
     * at all (a missing media id falls through to _requireOwned and is refused as
     * status 5, and mediaupdate's "Nothing to update" gate sits BETWEEN its id
     * check and its ownership check), so routing them through here would change
     * which error a client gets.
     *
     * @param string $kind  'page' | 'post'
     * @param mixed  $id    the posted owner id (raw)
     * @param array  $scope the resolved, authorized request scope
     * @return array the owner row
     */
    private function _loadOwnedEntity($kind, $id, $scope)
    {
        $kind = $this->_ownerType($kind);
        $id   = (int)$id;
        $row  = ($id > 0)
            ? (($kind === 'post') ? $this->CmsPost->get_post($id) : $this->CmsPage->get_page($id))
            : null;
        if ($id <= 0 || empty($row)) {
            $this->_fail(ucfirst($kind) . ' not found.', 4);
        }
        $this->_requireOwned($row, $scope);
        return $row;
    }

    /**
     * Load an editable page/post owner or emit a JSON error + exit. Enforces the
     * same gate as savepage/savepost: page.edit, OR page.edit_own on content the
     * user created (C16), plus the scope-ownership IDOR guard. Returns the row.
     *
     * @param int    $uid
     * @param string $ownerType 'page' | 'post'
     * @param int    $ownerId
     * @param array  $scope
     * @return array the owner row
     */
    private function _requireOwnerEditable($uid, $ownerType, $ownerId, $scope)
    {
        $ownerType = $this->_ownerType($ownerType);
        $ownerId = (int)$ownerId;
        $label = ucfirst($ownerType);

        if ($ownerId <= 0) {
            $this->_fail($label . ' not found.', 4);
        }
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', $scope)
            && !$this->CmsAuth->cms_can($uid, 'page.edit_own', $scope)
        ) {
            // #129: name the acceptable capabilities (either satisfies this gate).
            $this->_denyCapability(array('page.edit', 'page.edit_own'));
        }
        $row = ($ownerType === 'post')
            ? $this->CmsPost->get_post($ownerId)
            : $this->CmsPage->get_page($ownerId);
        if (empty($row)) {
            $this->_fail($label . ' not found.', 4);
        }
        $this->_requireOwned($row, $scope);
        if (!$this->CmsAuth->cms_can($uid, 'page.edit', $scope)
            && (int)($row['created_by'] ?? 0) !== $uid
        ) {
            $this->_fail('You can only edit content you created.', 5);
        }
        return $row;
    }
}
