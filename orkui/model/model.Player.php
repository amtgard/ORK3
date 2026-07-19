<?php

class Model_Player extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Player = new APIModel('Player');
        $this->Award = new APIModel('Award');
    }

    public function remove_note($request)
    {
        return $this->Player->RemoveNote($request);
    }

    public function clear_notes($request)
    {
        return $this->Player->ClearNotes($request);
    }

    public function edit_note($request)
    {
        return $this->_player()->EditNote($request);
    }

    public function get_notes($id)
    {
        return $this->Player->GetNotes(array('MundaneId' => $id));
    }

    public function update_class_reconciliation($request)
    {
        return $this->Player->SetPlayerReconciledCredits($request);
    }

    public function fetch_player($mundane_id)
    {
        $player = $this->Player->GetPlayer(array( 'MundaneId' => $mundane_id, 'Token' => $this->session->token ));
        if ($player['Status']['Status'] != 0) {
            return false;
        }
        $player = $player['Player'];
        return $player;
    }

    public function fetch_player_details($mundane_id)
    {
        return $this->_player()->GetPlayerProfileDetails((int) $mundane_id);
    }

    public function fetch_player_attendance($mundane_id)
    {
        return $this->_player()->GetPlayerAttendanceList((int) $mundane_id);
    }

    private function bust_player_details_cache($request)
    {
        $mundane_id = $request['RecipientId'] ?? $request['MundaneId'] ?? null;
        if (!$mundane_id) {
            return;
        }
        $this->_player()->bustPlayerProfileCaches((int) $mundane_id);
    }

    // Bust the kingdom + park roster caches for the player's current home.
    // Roster JSON is cached for 20 minutes and was previously never invalidated when a
    // player's name, persona, or restricted flag changed — leading to stale rosters
    // (e.g. a restricted player still showed up in client-side searches by mundane name
    // for up to 20 minutes after the toggle).
    private function bust_player_roster_caches($request)
    {
        $mundane_id = (int)($request['RecipientId'] ?? $request['MundaneId'] ?? 0);
        if (!$mundane_id) {
            return;
        }
        $this->_player()->bustRosterCachesForPlayer($mundane_id);
    }

    public function delete_player_award($request)
    {
        $r = $this->Player->RemoveAward($request);
        if ($r['Status']['Status'] == 0) {
            $this->bust_player_details_cache($request);
        }
        return $r;
    }

    public function revoke_player_award($request)
    {
        $r = $this->Player->RevokeAward($request);
        if ($r['Status']['Status'] == 0) {
            $this->bust_player_details_cache($request);
        }
        return $r;
    }

    public function reactivate_player_award($request)
    {
        $r = $this->Player->ReactivateAward($request);
        if ($r['Status']['Status'] == 0) {
            $this->bust_player_details_cache($request);
        }
        return $r;
    }

    public function add_note($request)
    {
        return $this->Player->AddNote($request);
    }

    public function revoke_all_awards($request)
    {
        $r = $this->Player->RevokeAllAwards($request);
        if ($r['Status']['Status'] == 0) {
            $this->bust_player_details_cache($request);
        }
        return $r;
    }

    public function add_player_award($request)
    {
        $r = $this->Player->AddAward($request);
        if ($r['Status']['Status'] == 0) {
            $this->bust_player_details_cache($request);
        }
        return $r;
    }

    public function update_player_award($request)
    {
        $r = $this->Player->UpdateAward($request);
        if ($r['Status']['Status'] == 0) {
            $this->bust_player_details_cache($request);
        }
        return $r;
    }

    public function reconcile_player_award($request)
    {
        return $this->Player->ReconcileAward($request);
    }

    public function add_dues($request)
    {
        return $this->Player->AddDues($request);
    }

    public function get_dues($id, $exclude_revoked = 0, $active = false)
    {
        return $this->Player->GetDues(array('MundaneId' => $id, 'ExcludeRevoked' => $exclude_revoked, 'Active' => $active));
    }

    public function revoke_dues($request)
    {
        return $this->Player->RevokeDues($request);
    }

    public function one_shot($request)
    {
        return $this->Player->AddOneShotFaceImage($request);
    }

    public function update_player($request)
    {
        $r = $this->Player->UpdatePlayer($request);
        $this->bust_player_details_cache($request);
        $this->bust_player_roster_caches($request);
        return $r;
    }

    public function set_ban($request)
    {
        $r = $this->Player->SetBan($request);
        return $r;
    }
    public function create_player($request)
    {
        return $this->Player->CreatePlayer($request);
    }
    public function create_selfreg_link($request)
    {
        return $this->Player->CreateSelfRegLink($request);
    }
    public function validate_selfreg_link($token)
    {
        return $this->Player->ValidateSelfRegLink(['SelfRegToken' => $token]);
    }
    public function self_register($request)
    {
        return $this->Player->SelfRegister($request);
    }
    public function move_player($request)
    {
        // Bust source kingdom/park caches BEFORE the move (player still has old park_id),
        // and again AFTER so the destination's caches refresh too.
        $this->bust_player_roster_caches($request);
        $r = $this->Player->MovePlayer($request);
        $this->bust_player_roster_caches($request);
        $this->bust_player_details_cache($request);
        return $r;
    }

    public function suspend_player($request)
    {
        $r = $this->Player->SetPlayerSuspension($request);
        $this->bust_player_roster_caches($request);
        $this->bust_player_details_cache($request);
        return $r;
    }

    public function merge_player($request)
    {
        $r = $this->Player->MergePlayer($request);
        $this->bust_player_roster_caches($request);
        $this->bust_player_details_cache($request);
        return $r;
    }

    public function reset_waivers($request)
    {
        return $this->Player->ResetWaivers($request);
    }

    public function add_player_recommendation($request)
    {
        return $this->Player->AddAwardRecommendation($request);
    }

    public function delete_player_recommendation($request)
    {
        return $this->Player->DeleteAwardRecommendation($request);
    }

    public function restore_player_recommendation($request)
    {
        return $this->Player->RestoreAwardRecommendation($request);
    }

    public function remove_heraldry($request)
    {
        return $this->Player->RemoveHeraldry($request);
    }

    public function remove_image($request)
    {
        return $this->Player->RemoveImage($request);
    }

    public function get_circle_award_ids($mundane_id)
    {
        return $this->_player()->GetCircleAwardIds($mundane_id);
    }

    public function player_info($token_or_id)
    {
        return $this->_player()->player_info($token_or_id);
    }

    public function get_custom_milestones($mundane_id)
    {
        return $this->_player()->GetCustomMilestones($mundane_id);
    }

    public function add_custom_milestone($request)
    {
        return $this->_player()->AddCustomMilestone($request);
    }

    public function update_custom_milestone($request)
    {
        return $this->_player()->UpdateCustomMilestone($request);
    }

    public function delete_custom_milestone($request)
    {
        return $this->_player()->DeleteCustomMilestone($request);
    }

    public function get_latest_attendance_date($mundane_id)
    {
        return $this->_player()->get_latest_attendance_date($mundane_id);
    }

    public function get_earliest_attendance_date($mundane_id)
    {
        return $this->_player()->get_earliest_attendance_date($mundane_id);
    }

    public function get_earliest_park_attendance_date($mundane_id, $park_id)
    {
        return $this->_player()->get_earliest_park_attendance_date($mundane_id, $park_id);
    }

    public function get_custom_title_award_id()
    {
        return $this->_player()->getCustomTitleAwardId();
    }

    public function has_notes($mundane_id)
    {
        return $this->_player()->GetNotesCount($mundane_id);
    }

    public function get_officer_roles($mundane_id)
    {
        return $this->_player()->GetOfficerRoles($mundane_id);
    }

    public function get_display_grants($mundane_id)
    {
        return $this->_player()->GetDisplayGrants($mundane_id);
    }

    public function get_beltline_for_player($mundane_id, $viewer_mundane_id = 0)
    {
        return $this->_player()->GetBeltlineForPlayer($mundane_id, $viewer_mundane_id);
    }

    public function get_reconcile_award_map($kingdom_id)
    {
        return $this->_player()->GetReconcileAwardMap($kingdom_id);
    }

    public function dismiss_whats_new($mundane_id, $version)
    {
        return $this->_player()->DismissWhatsNew((int)$mundane_id, (string)$version);
    }

    /** @return array{BasicFonts: int, DyslexiaFonts: int} */
    public function get_viewer_preferences(int $mundane_id): array
    {
        return $this->_player()->GetViewerPreferences($mundane_id);
    }

    public function get_whats_new_seen(int $mundane_id, string $version): bool
    {
        return $this->_player()->GetWhatsNewSeen($mundane_id, $version);
    }

    /** @return array{KingdomId: int, ParentKingdomId: int} */
    public function get_home_kingdom(int $mundane_id): array
    {
        return $this->_player()->GetHomeKingdom($mundane_id);
    }

    public function check_username_available($username, $exclude_mundane_id = 0)
    {
        return $this->_player()->CheckUsernameAvailable($username, $exclude_mundane_id);
    }

    public function username_check_payload($candidate)
    {
        $candidate = trim((string)$candidate);
        if (strlen($candidate) < 4) {
            return ['status' => 0, 'available' => false, 'reason' => 'too-short', 'username' => $candidate];
        }
        $available = $this->check_username_available($candidate);
        return ['status' => 0, 'available' => $available, 'username' => $candidate];
    }

    public static function username_check_payload_for($candidate, $player = null)
    {
        if ($player === null) {
            $player = new Model_Player();
        }
        return $player->username_check_payload($candidate);
    }

    public function add_second_to_recommendation($request)
    {
        return $this->_player()->AddSecondToRecommendation($request);
    }

    public function edit_second_notes($request)
    {
        return $this->_player()->EditSecondNotes($request);
    }

    public function withdraw_second($request)
    {
        return $this->_player()->WithdrawSecond($request);
    }

    public function edit_award_recommendation_reason($request)
    {
        return $this->_player()->EditAwardRecommendationReason($request);
    }

    public function get_dietary_preferences($mundane_id)
    {
        return $this->_player()->GetDietaryPreferences($mundane_id);
    }

    public function save_dietary_preferences($mundane_id, $data)
    {
        return $this->_player()->SaveDietaryPreferences($mundane_id, $data);
    }

    public function get_award_max_ranks($mundane_id)
    {
        return $this->_player()->GetAwardMaxRanks($mundane_id);
    }

    public function save_own_email($email)
    {
        return $this->_player()->SaveOwnEmail([
            'Token' => $this->session->token,
            'Email' => $email,
        ]);
    }

    public function get_persona(int $mundaneId): string
    {
        return $this->Player->GetPersona($mundaneId);
    }

    public function get_revoked_awards(int $mundaneId): array
    {
        return $this->Player->GetRevokedAwardsForPlayer($mundaneId);
    }

    private function _player(): Player
    {
        return new Player();
    }
}
