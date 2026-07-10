<?php

class Model_KingdomProfile extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Report = new APIModel('Report');
    }

    public function extended_park_averages(int $kingdomId, bool $isAdmin): array
    {
        $report = new Report();

        return $report->GetKingdomExtendedParkAverages([
            'KingdomId' => $kingdomId,
            'IsAdmin' => $isAdmin,
        ]);
    }

    public function paginated_events(int $kingdomId, int $window): array
    {
        $profile = new KingdomProfile();

        return $profile->GetPaginatedKingdomEvents($kingdomId, $window);
    }

    public function players_roster(int $kingdomId): array
    {
        $profile = new KingdomProfile();

        return $profile->GetKingdomPlayersRoster($kingdomId);
    }

    public function profile_event_bundle(int $kingdomId, int $mundaneId, bool $isAdmin): array
    {
        $profile = new KingdomProfile();

        return $profile->buildProfileEventBundle($kingdomId, $mundaneId, $isAdmin);
    }

    public function park_days(int $kingdomId): array
    {
        $profile = new KingdomProfile();

        return $profile->GetKingdomParkDays($kingdomId);
    }

    public function player_count(int $kingdomId): int
    {
        $profile = new KingdomProfile();

        return $profile->GetKingdomPlayerCount($kingdomId);
    }

    public function royal_officer_ids(int $kingdomId): array
    {
        $profile = new KingdomProfile();

        return $profile->GetRoyalOfficerIds($kingdomId);
    }

    public function export_ics(int $kingdomId, string $kingdomName = ''): string
    {
        $profile = new KingdomProfile();

        return $profile->ExportKingdomEventsIcs($kingdomId, $kingdomName);
    }

    public function calendar_feed(int $kingdomId, string $start, string $end, int $mundaneId, bool $isAdmin): array
    {
        $profile = new KingdomProfile();

        return $profile->GetKingdomCalendarFeed($kingdomId, $start, $end, $mundaneId, $isAdmin);
    }

    public function authorize_move_player(int $uid, int $playerKingdomId, int $destKingdomId): bool
    {
        $profile = new KingdomProfile();

        return $profile->AuthorizeMovePlayer($uid, $playerKingdomId, $destKingdomId);
    }

    public function set_award_recs_public(int $kingdomId, bool $public): void
    {
        $profile = new KingdomProfile();
        $profile->SetAwardRecsPublic($kingdomId, $public);
    }

    public function abbreviation_taken(string $abbr, int $excludeKingdomId = 0): bool
    {
        $profile = new KingdomProfile();

        return $profile->CheckKingdomAbbreviationTaken($abbr, $excludeKingdomId);
    }

    public function suspension_context(int $mundaneId): array
    {
        $profile = new KingdomProfile();

        return $profile->GetPlayerSuspensionContext($mundaneId);
    }

    public function user_home_park_id(int $mundaneId): int
    {
        $profile = new KingdomProfile();

        return $profile->GetUserHomeParkId($mundaneId);
    }

    public function has_park_create_auth(int $mundaneId, int $kingdomId): bool
    {
        $profile = new KingdomProfile();

        return $profile->HasParkCreateAuthInKingdom($mundaneId, $kingdomId);
    }
}
