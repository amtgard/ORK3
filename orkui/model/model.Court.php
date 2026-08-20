<?php

/**
 * Thin pass-through to the Court domain class (system/lib/ork3/class.Court.php).
 *
 * Controllers must not reach into Ork3::$Lib->court directly; every Court
 * Planner / court reporting call site goes through this model. No SQL and no
 * business logic belongs here -- each method forwards its arguments unchanged.
 */
class Model_Court extends Model
{
    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    public function can_manage($uid, $kingdom_id, $park_id = 0)
    {
        return $this->_court()->canManage($uid, $kingdom_id, $park_id);
    }

    // -----------------------------------------------------------------------
    // Courts
    // -----------------------------------------------------------------------

    public function get_court_list($kingdom_id, $park_id = 0)
    {
        return $this->_court()->getCourtList($kingdom_id, $park_id);
    }

    public function get_court_detail($court_id)
    {
        return $this->_court()->getCourtDetail($court_id);
    }

    public function create_court($kingdom_id, $park_id, $name, $court_date, $event_cd, $created_by)
    {
        return $this->_court()->createCourt($kingdom_id, $park_id, $name, $court_date, $event_cd, $created_by);
    }

    public function update_court_status($court_id, $status)
    {
        return $this->_court()->updateCourtStatus($court_id, $status);
    }

    public function get_court_state($court_id)
    {
        return $this->_court()->getCourtState($court_id);
    }

    public function set_court_mode($court_id, $mode)
    {
        return $this->_court()->setCourtMode($court_id, $mode);
    }

    public function set_court_finalized($court_id, $uid)
    {
        return $this->_court()->setCourtFinalized($court_id, $uid);
    }

    public function get_court_giver_options($court_id)
    {
        return $this->_court()->getCourtGiverOptions($court_id);
    }

    // -----------------------------------------------------------------------
    // Court awards
    // -----------------------------------------------------------------------

    public function get_court_awards($court_id)
    {
        return $this->_court()->getCourtAwards($court_id);
    }

    public function add_award($court_id, $kingdom_id, $mundane_id, $kingdomaward_id, $rank, $rec_id, $pass_to_local, $notes, $public_comment)
    {
        return $this->_court()->addAward($court_id, $kingdom_id, $mundane_id, $kingdomaward_id, $rank, $rec_id, $pass_to_local, $notes, $public_comment);
    }

    public function update_award($court_award_id, $notes, $public_comment, $pass_to_local, $scroll_maker_id, $regalia_maker_id, $expectedRowVersion = null)
    {
        return $this->_court()->updateAward($court_award_id, $notes, $public_comment, $pass_to_local, $scroll_maker_id, $regalia_maker_id, $expectedRowVersion);
    }

    public function remove_award($court_award_id)
    {
        return $this->_court()->removeAward($court_award_id);
    }

    public function reorder_awards($court_id, $order)
    {
        return $this->_court()->reorderAwards($court_id, $order);
    }

    public function get_court_award_court_id($court_award_id)
    {
        return $this->_court()->getCourtAwardCourtId($court_award_id);
    }

    public function get_court_award_for_pass($court_award_id)
    {
        return $this->_court()->getCourtAwardForPass($court_award_id);
    }

    public function get_court_award_for_grant($court_award_id)
    {
        return $this->_court()->getCourtAwardForGrant($court_award_id);
    }

    public function set_award_status($court_award_id, $status, $expectedRowVersion = null)
    {
        return $this->_court()->setAwardStatus($court_award_id, $status, $expectedRowVersion);
    }

    public function skip_award($court_award_id, $expectedRowVersion = null)
    {
        return $this->_court()->skipAward($court_award_id, $expectedRowVersion);
    }

    public function update_award_tracking_status($courtAwardId, $type)
    {
        return $this->_court()->updateAwardTrackingStatus($courtAwardId, $type);
    }

    public function court_has_award($court_id, $mundane_id, $kingdomaward_id, $rank)
    {
        return $this->_court()->courtHasAward($court_id, $mundane_id, $kingdomaward_id, $rank);
    }

    // -----------------------------------------------------------------------
    // Staging / granting
    // -----------------------------------------------------------------------

    public function stage_award($court_award_id, $given_by_mundane_id, $public_comment, $rank)
    {
        return $this->_court()->stageAward($court_award_id, $given_by_mundane_id, $public_comment, $rank);
    }

    public function unstage_award($court_award_id)
    {
        return $this->_court()->unstageAward($court_award_id);
    }

    public function get_staged_awards($court_id)
    {
        return $this->_court()->getStagedAwards($court_id);
    }

    public function count_staged_awards($court_id)
    {
        return $this->_court()->countStagedAwards($court_id);
    }

    public function bulk_stage_planned($court_id, $default_giver_mundane_id)
    {
        return $this->_court()->bulkStagePlanned($court_id, $default_giver_mundane_id);
    }

    public function commit_staged_award($court_award_id, $ctx)
    {
        return $this->_court()->commitStagedAward($court_award_id, $ctx);
    }

    public function cancel_unresolved($court_id)
    {
        return $this->_court()->cancelUnresolved($court_id);
    }

    public function reconcile_grant_for_recommendation($recommendations_id, $awards_id, $given_by_mundane_id, $rank = null, $mundane_id = 0, $kingdomaward_id = 0, $court_action = 'leave', $actor_uid = 0)
    {
        return $this->_court()->reconcileGrantForRecommendation($recommendations_id, $awards_id, $given_by_mundane_id, $rank, $mundane_id, $kingdomaward_id, $court_action, $actor_uid);
    }

    // -----------------------------------------------------------------------
    // Artisans
    // -----------------------------------------------------------------------

    public function add_artisan($court_award_id, $mundane_id, $contribution)
    {
        return $this->_court()->addArtisan($court_award_id, $mundane_id, $contribution);
    }

    public function get_artisan_court_id($artisan_id)
    {
        return $this->_court()->getArtisanCourtId($artisan_id);
    }

    public function remove_artisan($artisan_id)
    {
        return $this->_court()->removeArtisan($artisan_id);
    }

    // -----------------------------------------------------------------------
    // Recommendations
    // -----------------------------------------------------------------------

    public function get_pending_recommendations($kingdom_id, $park_id = 0, $caller_uid = 0, $court_id = 0)
    {
        return $this->_court()->getPendingRecommendations($kingdom_id, $park_id, $caller_uid, $court_id);
    }

    public function get_recommendation_court_map($kingdom_id, $park_id = 0)
    {
        return $this->_court()->getRecommendationCourtMap($kingdom_id, $park_id);
    }

    public function get_ungranted_from_last_court($kingdom_id, $park_id)
    {
        return $this->_court()->getUngrantedFromLastCourt($kingdom_id, $park_id);
    }

    // -----------------------------------------------------------------------
    // Events
    // -----------------------------------------------------------------------

    public function get_upcoming_events($kingdom_id)
    {
        return $this->_court()->getUpcomingEvents($kingdom_id);
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    public function get_court_report_scope($kingdom_id, $park_id)
    {
        return $this->_court()->getCourtReportScope($kingdom_id, $park_id);
    }

    public function get_court_report_list($kingdom_id, $park_id, $from_date, $until_date)
    {
        return $this->_court()->getCourtReportList($kingdom_id, $park_id, $from_date, $until_date);
    }

    public function get_court_report_detail($court_id)
    {
        return $this->_court()->getCourtReportDetail($court_id);
    }

    private function _court(): Court
    {
        return new Court();
    }
}
