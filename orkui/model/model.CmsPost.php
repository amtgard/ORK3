<?php

/**
 * Model_CmsPost — thin pass-through to the CmsPost lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsPost')
 * (because system/lib/ork3/class.CmsPost.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib).
 */
class Model_CmsPost extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsPost = new APIModel('CmsPost');
    }

    public function get_post_by_slug($slug, $scopeType = 'global', $scopeId = 0, $publishedOnly = true)
    {
        return $this->CmsPost->GetPostBySlug($slug, $scopeType, $scopeId, $publishedOnly);
    }

    public function get_post_blocks($postId)
    {
        return $this->CmsPost->GetPostBlocks($postId);
    }

    public function list_posts($opts = array())
    {
        return $this->CmsPost->ListPosts($opts);
    }

    public function create_post($data)
    {
        return $this->CmsPost->CreatePost($data);
    }

    public function get_post($postId)
    {
        return $this->CmsPost->GetPost($postId);
    }

    public function update_post($postId, $data)
    {
        return $this->CmsPost->UpdatePost($postId, $data);
    }

    public function set_status($postId, $status, $uid = 0)
    {
        return $this->CmsPost->SetStatus($postId, $status, $uid);
    }

    public function delete_post($postId, $scopeType = null, $scopeId = null)
    {
        return $this->CmsPost->DeletePost($postId, $scopeType, $scopeId);
    }

    public function set_tags($postId, array $tagNames)
    {
        return $this->CmsPost->SetTags($postId, $tagNames);
    }

    public function get_tags($postId)
    {
        return $this->CmsPost->GetTags($postId);
    }

    public function list_all_tags()
    {
        return $this->CmsPost->ListAllTags();
    }
}
