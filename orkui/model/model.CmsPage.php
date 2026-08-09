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
 */
class Model_CmsPage extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsPage = new APIModel('CmsPage');
    }

    public function get_page_by_slug($slug, $scopeType = 'global', $scopeId = 0, $publishedOnly = true)
    {
        return $this->CmsPage->GetPageBySlug($slug, $scopeType, $scopeId, $publishedOnly);
    }

    public function get_home_page()
    {
        return $this->CmsPage->GetHomePage();
    }

    /**
     * C1: GhettoCache-backed bundle — the resolved page row + its ENABLED blocks
     * in one call. $slug null → the scope's home page.
     */
    public function get_page_with_blocks($scopeType, $scopeId, $slug = null)
    {
        return $this->CmsPage->GetPageWithBlocks($scopeType, $scopeId, $slug);
    }

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
}
