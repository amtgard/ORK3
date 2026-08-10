<?php

/**
 * Model_CmsView — thin pass-through to the CmsView lib (usage analytics #09).
 *
 * The base Model constructor auto-instantiates new APIModel('CmsView')
 * (because system/lib/ork3/class.CmsView.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib).
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
class Model_CmsView extends Model
{
    public function record_view($scopeType, $scopeId, $entityType, $entityId)
    {
        return $this->CmsView->RecordView($scopeType, $scopeId, $entityType, $entityId);
    }

    public function get_scope_view_summary($scopeType, $scopeId)
    {
        return $this->CmsView->GetScopeViewSummary($scopeType, $scopeId);
    }

    public function get_view_stats($scopeType, $scopeId, $limit = 8)
    {
        return $this->CmsView->GetViewStats($scopeType, $scopeId, $limit);
    }
}
