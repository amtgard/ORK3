<?php

class Model_ParkProfile extends Model
{
    public function profile_event_bundle(int $parkId, int $kingdomId, int $mundaneId, bool $isAdmin): array
    {
        return $this->_profile()->buildProfileEventBundle($parkId, $kingdomId, $mundaneId, $isAdmin);
    }

    public function players_roster(int $parkId): array
    {
        return $this->_profile()->GetParkPlayersRoster($parkId);
    }

    public function attendance_averages(int $parkId): array
    {
        return $this->_profile()->GetParkAttendanceAverages($parkId);
    }

    public function abbreviation_taken(int $kingdomId, string $abbr, int $excludeParkId = 0): bool
    {
        return $this->_profile()->CheckParkAbbreviationTaken($kingdomId, $abbr, $excludeParkId);
    }

    public function park_belongs_to_kingdom(int $parkId, int $kingdomId): bool
    {
        return $this->_profile()->ParkBelongsToKingdom($parkId, $kingdomId);
    }

    private function _profile(): ParkProfile
    {
        return new ParkProfile();
    }
}
