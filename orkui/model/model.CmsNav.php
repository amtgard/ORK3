<?php

/**
 * Model_CmsNav — thin pass-through to the CmsNav lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsNav')
 * (because system/lib/ork3/class.CmsNav.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib).
 */
class Model_CmsNav extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsNav = new APIModel('CmsNav');
    }

    public function get_menu($menu, $scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsNav->GetMenu($menu, $scopeType, $scopeId);
    }

    public function list_items($menu, $scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsNav->ListItems($menu, $scopeType, $scopeId);
    }

    public function create_item($data)
    {
        return $this->CmsNav->CreateItem($data);
    }

    public function update_item($navId, $data)
    {
        return $this->CmsNav->UpdateItem($navId, $data);
    }

    public function delete_item($navId)
    {
        return $this->CmsNav->DeleteItem($navId);
    }

    public function reorder($menu, array $orderedItems, $scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsNav->Reorder($menu, $orderedItems, $scopeType, $scopeId);
    }
}
