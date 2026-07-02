<?php

/**
 * Model_CmsPage — thin pass-through to the CmsPage lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsPage')
 * (because system/lib/ork3/class.CmsPage.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib).
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

    public function get_blocks($ownerType, $ownerId)
    {
        return $this->CmsPage->GetBlocks($ownerType, $ownerId);
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

    public function update_page($pageId, $data)
    {
        return $this->CmsPage->UpdatePage($pageId, $data);
    }

    public function set_status($pageId, $status, $updatedBy = 0)
    {
        return $this->CmsPage->SetStatus($pageId, $status, $updatedBy);
    }

    public function delete_page($pageId, $scopeType = null, $scopeId = null)
    {
        return $this->CmsPage->DeletePage($pageId, $scopeType, $scopeId);
    }

    public function replace_blocks($ownerType, $ownerId, $blocksArray)
    {
        return $this->CmsPage->ReplaceBlocks($ownerType, $ownerId, $blocksArray);
    }

    public function list_pages($filters = array())
    {
        return $this->CmsPage->ListPages($filters);
    }
}
