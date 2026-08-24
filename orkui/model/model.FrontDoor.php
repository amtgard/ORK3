<?php

class Model_FrontDoor extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    // Single content seam. The front door's default block list is domain content:
    // it lives in CmsBlockRegistry::DefaultFrontDoorBlocks(), beside the rest of
    // the CMS content-type registry, where the seed migrations and any future
    // client can reach the same copy. This model is the membrane over it.
    // $ctx: ['logged_in'=>bool, 'kingdom_id'=>int, ...] — currently ignored by
    // DefaultFrontDoorBlocks(); reserved for future scoping.
    //
    // Calling convention: call GetContent() directly. There is no
    // system/lib/ork3/class.FrontDoor.php, so nothing here is reachable through
    // Model::__call, and no snake_case wrapper is needed.
    public function GetContent($ctx = [])
    {
        return CmsBlockRegistry::DefaultFrontDoorBlocks($ctx);
    }
}
