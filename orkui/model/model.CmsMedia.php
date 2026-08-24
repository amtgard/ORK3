<?php

/**
 * Model_CmsMedia — thin pass-through to the CmsMedia lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsMedia')
 * (because system/lib/ork3/class.CmsMedia.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB + file work lives in the lib).
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
class Model_CmsMedia extends Model
{
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
     * Why the last upload() returned null ('too_large' | 'quota_exceeded' | '').
     *
     * Must go through the lib's LastError() ACCESSOR, not the $LastError
     * property: APIModel defines __call() but no __get(), so a property read
     * across that boundary always yields null.
     */
    public function last_error()
    {
        return $this->CmsMedia->LastError();
    }
    public function scope_usage_bytes($scopeType, $scopeId)
    {
        return $this->CmsMedia->ScopeUsageBytes($scopeType, $scopeId);
    }
    public function scope_quota_bytes($scopeType)
    {
        return $this->CmsMedia->ScopeQuotaBytes($scopeType);
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

    /** @no-callers — mirror surface. */
    public function delete_media($mediaId, $actorId = 0, $scopeType = null, $scopeId = null)
    {
        return $this->CmsMedia->DeleteMedia($mediaId, $actorId, $scopeType, $scopeId);
    }

    public function delete_media_batch($ids, $actorId = 0, $scopeType = null, $scopeId = null)
    {
        return $this->CmsMedia->DeleteMediaBatch($ids, $actorId, $scopeType, $scopeId);
    }
}
