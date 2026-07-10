<?php

class Model_ParkProfile extends Model
{
    public function profile_event_bundle(int $parkId, int $kingdomId, int $mundaneId, bool $isAdmin): array
    {
        $profile = new ParkProfile();

        return $profile->buildProfileEventBundle($parkId, $kingdomId, $mundaneId, $isAdmin);
    }

    public function players_roster(int $parkId): array
    {
        $profile = new ParkProfile();

        return $profile->GetParkPlayersRoster($parkId);
    }

    public function attendance_averages(int $parkId): array
    {
        $profile = new ParkProfile();

        return $profile->GetParkAttendanceAverages($parkId);
    }

    public function abbreviation_taken(int $kingdomId, string $abbr, int $excludeParkId = 0): bool
    {
        $profile = new ParkProfile();

        return $profile->CheckParkAbbreviationTaken($kingdomId, $abbr, $excludeParkId);
    }
}
