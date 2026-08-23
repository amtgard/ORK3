<?php

/**
 * Model_CmsPage — thin pass-through to the CmsPage lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsPage')
 * (because system/lib/ork3/class.CmsPage.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib). Each snake_case wrapper mirrors the FULL lib
 * signature so no caller has to reach past the wrapper (via __call) to pass a
 * later positional argument.
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
class Model_CmsPage extends Model
{
    /** @no-callers — mirror surface. */
    public function get_page_by_slug($slug, $scopeType = 'global', $scopeId = 0, $publishedOnly = true)
    {
        return $this->CmsPage->GetPageBySlug($slug, $scopeType, $scopeId, $publishedOnly);
    }

    /** @no-callers — mirror surface. */
    public function get_home_page()
    {
        return $this->CmsPage->GetHomePage();
    }

    /**
     * C1: GhettoCache-backed bundle — the resolved page row + its ENABLED blocks
     * in one call. $slug null → the scope's home page.
     */
    public function get_page_with_blocks($scopeType, $scopeId, $slug = null, $publishedOnly = true)
    {
        return $this->CmsPage->GetPageWithBlocks($scopeType, $scopeId, $slug, $publishedOnly);
    }

    /** @no-callers — mirror surface. */
    public function get_blocks($ownerType, $ownerId)
    {
        return $this->CmsPage->GetBlocks($ownerType, $ownerId);
    }

    /**
     * C2: ALL blocks for the editor (INCLUDING disabled), unlike the public
     * get_blocks() which returns enabled-only.
     */
    public function get_blocks_for_editor($ownerType, $ownerId)
    {
        return $this->CmsPage->GetBlocksForEditor($ownerType, $ownerId);
    }

    /**
     * E2 (live preview): sanitize an UNSAVED editor block list into render shape
     * WITHOUT writing anything. Runs the same clean a save runs — see
     * CmsPage::SanitizeBlocksForRender.
     */
    public function sanitize_blocks_for_render($blocksArray)
    {
        return $this->CmsPage->SanitizeBlocksForRender($blocksArray);
    }

    public function get_page_blocks($pageId)
    {
        return $this->CmsPage->GetPageBlocks($pageId);
    }

    public function create_page($data)
    {
        return $this->CmsPage->CreatePage($data);
    }

    public function get_page($pageId)
    {
        return $this->CmsPage->GetPage($pageId);
    }

    public function update_page($pageId, $data, $scopeType = null, $scopeId = null)
    {
        return $this->CmsPage->UpdatePage($pageId, $data, $scopeType, $scopeId);
    }

    public function set_status($pageId, $status, $updatedBy = 0, $publishedAt = null)
    {
        return $this->CmsPage->SetStatus($pageId, $status, $updatedBy, $publishedAt);
    }

    public function delete_page($pageId, $scopeType = null, $scopeId = null, $actorId = 0)
    {
        return $this->CmsPage->DeletePage($pageId, $scopeType, $scopeId, $actorId);
    }

    public function replace_blocks($ownerType, $ownerId, $blocksArray, $actorId = 0)
    {
        return $this->CmsPage->ReplaceBlocks($ownerType, $ownerId, $blocksArray, $actorId);
    }

    public function list_pages($filters = array())
    {
        return $this->CmsPage->ListPages($filters);
    }

    /**
     * Scope-BOUND page list for the admin link chooser (CmsAjax/pagelist).
     * Unlike list_pages(), the scope is not an optional filter — see the lib.
     */
    public function list_pages_for_scope($scopeType, $scopeId, $search = null, $limit = 300)
    {
        return $this->CmsPage->ListPagesForScope($scopeType, $scopeId, $search, $limit);
    }
}
