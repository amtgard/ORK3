<?php

class Model_Tournament extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Report          = new APIModel('Report');
        $this->Tournament      = new APIModel('Tournament');
        $this->TournamentExport = new APIModel('TournamentExport');
        $this->TournamentReport = new APIModel('TournamentReport');
    }

    public function get_tournies($request)
    {
        return $this->Report->TournamentReport($request);
    }

    public function create_tournament($request)
    {
        return $this->Tournament->CreateTournament($request);
    }

    public function delete_tournament($request)
    {
        return $this->Tournament->DeleteTournament($request);
    }

    public function update_tournament($request)
    {
        return $this->Tournament->UpdateTournament($request);
    }

    public function add_bracket($request)
    {
        return $this->Tournament->AddBracket($request);
    }

    public function update_bracket($request)
    {
        return $this->Tournament->UpdateBracket($request);
    }

    public function add_participant($request)
    {
        return $this->Tournament->AddParticipant($request);
    }

    public function create_quick_bracket($request)
    {
        return $this->Tournament->CreateQuickBracket($request);
    }

    public function quick_place($request)
    {
        return $this->Tournament->QuickPlace($request);
    }

    public function start_quick_bracket($request)
    {
        return $this->Tournament->StartQuickBracket($request);
    }

    public function get_brackets($tournament_id)
    {
        return $this->Tournament->GetBrackets(['TournamentId' => $tournament_id]);
    }

    public function get_participants($request)
    {
        return $this->Tournament->GetParticipants($request);
    }

    public function get_matches($request)
    {
        return $this->Tournament->GetMatches($request);
    }

    public function remove_participant($request)
    {
        return $this->Tournament->RemoveParticipant($request);
    }

    public function register_participant($request)
    {
        return $this->Tournament->RegisterParticipant($request);
    }

    public function get_registrants($request)
    {
        return $this->Tournament->GetRegistrants($request);
    }

    public function assign_to_bracket($request)
    {
        return $this->Tournament->AssignToBracket($request);
    }

    public function unassign_from_bracket($request)
    {
        return $this->Tournament->UnassignFromBracket($request);
    }

    public function update_registration_status($request)
    {
        return $this->Tournament->UpdateRegistrationStatus($request);
    }

    public function remove_registrant($request)
    {
        return $this->Tournament->RemoveRegistrant($request);
    }

    public function register_team($request)
    {
        return $this->Tournament->RegisterTeam($request);
    }

    public function get_registered_teams($request)
    {
        return $this->Tournament->GetRegisteredTeams($request);
    }

    public function update_team($request)
    {
        return $this->Tournament->UpdateTeam($request);
    }

    public function remove_registered_team($request)
    {
        return $this->Tournament->RemoveRegisteredTeam($request);
    }

    public function assign_team_to_bracket($request)
    {
        return $this->Tournament->AssignTeamToBracket($request);
    }

    public function unassign_team_from_bracket($request)
    {
        return $this->Tournament->UnassignTeamFromBracket($request);
    }

    public function generate_matches($request)
    {
        return $this->Tournament->GenerateMatches($request);
    }

    public function post_match_result($request)
    {
        return $this->Tournament->PostMatchResult($request);
    }

    public function reset_match($request)
    {
        return $this->Tournament->ResetMatch($request);
    }

    public function create_confirmation_match($request)
    {
        return $this->Tournament->CreateConfirmationMatch($request);
    }

    public function create_tiebreaker_match($request)
    {
        return $this->Tournament->CreateTiebreakerMatch($request);
    }

    public function create_round_robin_tiebreaker($request)
    {
        return $this->Tournament->CreateRoundRobinTiebreaker($request);
    }

    public function decline_round_robin_tiebreaker($request)
    {
        return $this->Tournament->DeclineRoundRobinTiebreaker($request);
    }

    public function record_ironman_win($request)
    {
        return $this->Tournament->RecordIronmanWin($request);
    }

    public function complete_bracket($request)
    {
        return $this->Tournament->CompleteBracket($request);
    }

    public function delete_bracket($request)
    {
        return $this->Tournament->DeleteBracket($request);
    }

    public function clear_bracket_matches($request)
    {
        return $this->Tournament->ClearBracketMatches($request);
    }

    public function pools_to_bracket($request)
    {
        return $this->Tournament->PoolsToBracket($request);
    }

    public function auth_check($request)
    {
        return $this->Tournament->CheckAuth($request);
    }

    public function save_standings_points($request)
    {
        return $this->Tournament->SaveStandingsPoints($request);
    }

    public function reorder_seeds($request)
    {
        return $this->Tournament->ReorderSeeds($request);
    }

    public function update_participant_status($request)
    {
        return $this->Tournament->UpdateParticipantStatus($request);
    }

    public function update_alias($request)
    {
        return $this->Tournament->UpdateAlias($request);
    }

    public function search_parks($query)
    {
        return $this->Tournament->SearchParks($query);
    }

    public function search_events($query, $kingdom_id = 0)
    {
        return $this->Tournament->SearchEvents($query, $kingdom_id);
    }

    /**
     * Kingdom used to scope tournament player searches: the tournament's own
     * kingdom, else its park's kingdom, else its event's kingdom (0 if none).
     */
    public function get_search_kingdom_id(array $tournament): int
    {
        $kid = (int)($tournament['KingdomId'] ?? 0);
        if (valid_id($kid)) {
            return $kid;
        }
        $pid = (int)($tournament['ParkId'] ?? 0);
        if (valid_id($pid)) {
            $pr  = (new APIModel('Park'))->GetParkShortInfo(['ParkId' => $pid]);
            $kid = (int)($pr['ParkInfo']['KingdomId'] ?? 0);
            if (valid_id($kid)) {
                return $kid;
            }
        }
        $ecd = (int)($tournament['EventCalendarDetailId'] ?? 0);
        if (valid_id($ecd)) {
            $event = new APIModel('Event');
            $dr    = $event->GetEventDetail(['EventCalendarDetailId' => $ecd]);
            $eid   = (int)($dr['CalendarEventDetails'][0]['EventId'] ?? 0);
            if (valid_id($eid)) {
                $er  = $event->GetEvent(['EventId' => $eid]);
                $kid = (int)($er['KingdomId'] ?? 0);
                if (valid_id($kid)) {
                    return $kid;
                }
            }
        }
        return 0;
    }

    public function get_tournament_event_label($tournament_id)
    {
        return $this->Tournament->GetTournamentEventLabel($tournament_id);
    }

    public function get_standings_points($tournament_id)
    {
        return $this->Tournament->GetStandingsPoints($tournament_id);
    }

    public function get_standings($bracket_id)
    {
        return $this->Tournament->GetStandings(['BracketId' => $bracket_id]);
    }

    public function get_bracket_placements($bracket_id)
    {
        return $this->TournamentReport->GetBracketPlacements(['BracketId' => (int)$bracket_id]);
    }

    public function export_workbook($tournament_id)
    {
        return $this->TournamentExport->BuildWorkbook(['TournamentId' => (int)$tournament_id]);
    }

    public function get_reeves($request)
    {
        return $this->Tournament->GetReeves($request);
    }

    public function add_reeve($request)
    {
        return $this->Tournament->AddReeve($request);
    }

    public function remove_reeve($request)
    {
        return $this->Tournament->RemoveReeve($request);
    }

    public function save_point_score($request)
    {
        return $this->Tournament->SavePointScore($request);
    }

    public function add_points_round($request)
    {
        return $this->Tournament->AddPointsRound($request);
    }

    public function get_point_standings($request)
    {
        return $this->Tournament->GetPointStandings($request);
    }

    public function get_version($request)
    {
        return $this->Tournament->GetVersion($request);
    }

    public function get_seq($request)
    {
        return $this->Tournament->GetSeq($request);
    }

    public function get_changes($request)
    {
        return $this->Tournament->GetChanges($request);
    }

    public function get_reeve_role($request)
    {
        return $this->Tournament->GetReeveRole($request);
    }

    public function reeve_presence($request)
    {
        return $this->Tournament->ReevePresence($request);
    }

    public function get_player_history($mundane_id)
    {
        // Placement decoration + per-bracket standings batching now lives in the lib layer
        // (Tournament::GetPlayerHistory); this stays a thin pass-through. Unwrap Detail to
        // preserve the controller's bare-array contract.
        $r = $this->Tournament->GetPlayerHistory(['MundaneId' => $mundane_id]);
        return (isset($r['Detail']) && is_array($r['Detail'])) ? $r['Detail'] : [];
    }

}
