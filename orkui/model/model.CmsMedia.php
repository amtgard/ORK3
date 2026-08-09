<?php

/**
 * Model_CmsMedia — thin pass-through to the CmsMedia lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsMedia')
 * (because system/lib/ork3/class.CmsMedia.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB + file work lives in the lib).
 */
class Model_CmsMedia extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsMedia = new APIModel('CmsMedia');
    }

    public function upload($base64OrDataUri, $filename, $alt, $uploadedBy, $scope = array('type' => 'global', 'id' => 0))
    {
        return $this->CmsMedia->Upload($base64OrDataUri, $filename, $alt, $uploadedBy, $scope);
    }

    public function to_media_ref($mediaRow)
    {
        return $this->CmsMedia->ToMediaRef($mediaRow);
    }

    public function list_media($scope = null, $limit = 200, $search = null, $offset = 0)
    {
        return $this->CmsMedia->ListMedia($scope, $limit, $search, $offset);
    }

    /**
     * NOTE: unlike the other scope-taking CmsMedia methods, $scopeType must NOT be
     * null here — the lib signature is a typed non-nullable string, so a null
     * forwarded through this untyped pass-through is a fatal TypeError.
     */
    public function filter_owned_ids($ids, $scopeType, $scopeId)
    {
        return $this->CmsMedia->FilterOwnedIds($ids, $scopeType, $scopeId);
    }

    public function get_media($mediaId)
    {
        return $this->CmsMedia->GetMedia($mediaId);
    }

    public function delete_media($mediaId)
    {
        return $this->CmsMedia->DeleteMedia($mediaId);
    }
}
