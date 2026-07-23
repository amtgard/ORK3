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
        return $this->Report->GetKingdomExtendedParkAverages([
            'KingdomId' => $kingdomId,
            'IsAdmin' => $isAdmin,
        ]);
    }

    public function paginated_events(int $kingdomId, int $window): array
    {
        return $this->_profile()->GetPaginatedKingdomEvents($kingdomId, $window);
    }

    public function players_roster(int $kingdomId): array
    {
        return $this->_profile()->GetKingdomPlayersRoster($kingdomId);
    }

    public function profile_event_bundle(int $kingdomId, int $mundaneId, bool $isAdmin): array
    {
        return $this->_profile()->buildProfileEventBundle($kingdomId, $mundaneId, $isAdmin);
    }

    public function park_days(int $kingdomId): array
    {
        return $this->_profile()->GetKingdomParkDays($kingdomId);
    }

    public function player_count(int $kingdomId): int
    {
        return $this->_profile()->GetKingdomPlayerCount($kingdomId);
    }

    public function royal_officer_ids(int $kingdomId): array
    {
        return $this->_profile()->GetRoyalOfficerIds($kingdomId);
    }

    public function export_ics(int $kingdomId, string $kingdomName = ''): string
    {
        return $this->_profile()->ExportKingdomEventsIcs($kingdomId, $kingdomName);
    }

    public function calendar_feed(int $kingdomId, string $start, string $end, int $mundaneId, bool $isAdmin): array
    {
        return $this->_profile()->GetKingdomCalendarFeed($kingdomId, $start, $end, $mundaneId, $isAdmin);
    }

    public function authorize_move_player(int $uid, int $playerKingdomId, int $destKingdomId): bool
    {
        return $this->_profile()->AuthorizeMovePlayer($uid, $playerKingdomId, $destKingdomId);
    }

    public function set_award_recs_public(int $kingdomId, bool $public): void
    {
        $this->_profile()->SetAwardRecsPublic($kingdomId, $public);
    }

    public function abbreviation_taken(string $abbr, int $excludeKingdomId = 0): bool
    {
        return $this->_profile()->CheckKingdomAbbreviationTaken($abbr, $excludeKingdomId);
    }

    public function suspension_context(int $mundaneId): array
    {
        return $this->_profile()->GetPlayerSuspensionContext($mundaneId);
    }

    public function park_kingdom_id(int $parkId): int
    {
        return $this->_profile()->GetParkKingdomId($parkId);
    }

    public function abbreviation_conflict(string $abbr, int $excludeKingdomId = 0): ?string
    {
        return $this->_profile()->GetKingdomAbbreviationConflict($abbr, $excludeKingdomId);
    }

    public function user_home_park_id(int $mundaneId): int
    {
        return $this->_profile()->GetUserHomeParkId($mundaneId);
    }

    public function has_park_create_auth(int $mundaneId, int $kingdomId): bool
    {
        return $this->_profile()->HasParkCreateAuthInKingdom($mundaneId, $kingdomId);
    }

    private function _profile(): KingdomProfile
    {
        return new KingdomProfile();
    }
}
