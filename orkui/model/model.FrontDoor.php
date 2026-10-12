<?php

/**
 * Model_FrontDoor — the membrane over the front door's default block list.
 *
 * There is no system/lib/ork3/class.FrontDoor.php, so nothing here is reachable
 * through Model::__call and no snake_case wrapper is needed: call GetContent()
 * directly.
 */
class Model_FrontDoor extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The front door's default block list. This is domain content, so it lives
     * in CmsBlockRegistry::DefaultFrontDoorBlocks(), beside the rest of the CMS
     * content-type registry, where the seed migrations and any future client can
     * reach the same copy; this model is the single seam over it.
     *
     * @param array $ctx ['logged_in'=>bool, 'kingdom_id'=>int, …] — currently
     *                   ignored by DefaultFrontDoorBlocks(); reserved for future
     *                   scoping.
     */
    public function GetContent($ctx = [])
    {
        return CmsBlockRegistry::DefaultFrontDoorBlocks($ctx);
    }
}
