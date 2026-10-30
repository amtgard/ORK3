<?php

/**
 * Kingdom voting eligibility rule configuration (T-RPT-04 through T-RPT-06).
 * Single backend source of truth; consumed by Report::GetVotingEligible*.
 */
class VotingRules
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function allRules(): array
    {
        return [
            14 => [
                'AttendanceRequired' => 7,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'count',
                'ProvinceMode' => false,
                'ActiveMemberThreshold' => 12,
                'AllKingdoms' => true,
            ],
            31 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 6,
                'AttendanceMode' => 'weeks',
                'ProvinceMode' => false,
            ],
            3 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 6,
                'AttendanceMode' => 'weeks',
                'ProvinceMode' => false,
            ],
            17 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 6,
                'AttendanceMode' => 'count',
                'ProvinceMode' => true,
                'KingdomEventBonus' => true,
            ],
            10 => [
                'AttendanceRequired' => 7,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 6,
                'AttendanceMode' => 'days',
                'ProvinceMode' => false,
                'MembershipMode' => 'first_attendance',
                'WeekSnap' => true,
            ],
            25 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'days',
                'ProvinceMode' => false,
                'WeekSnap' => true,
            ],
            20 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'days',
                'ProvinceMode' => false,
                'ExcludeOnline' => true,
                'WeekSnap' => true,
            ],
            40 => [
                // Painted Skies corpora 1.2 (2026-10): six sign-ins within the
                // Principality in the past six months, spread across at least two
                // different months; dues-paid; 14 or older (no birth dates in the
                // ORK, so MinAge is a caveat the report states for officers to confirm
                // by hand). Online and in-person sign-ins are no longer distinguished.
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'count',
                'MinDistinctMonths' => 2,
                'ProvinceMode' => false,
                'MinAge' => 14,
            ],
            8 => [
                // Freeholds of Amtgard (2026-10): each freehold is its own electorate.
                // A "Contributing Member" has six sign-ins at their own freehold in the
                // past six months, dues paid at that freehold (dues at another freehold
                // do not count), is 14 or older (caveat only: no birth dates in the ORK),
                // and is a standard member, which the waiver + home-park requirement
                // already covers. Run from a freehold's Park page for that freehold.
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'count',
                'ProvinceMode' => false,
                'HomeParkOnly' => true,
                'DuesHomeParkOnly' => true,
                'MinAge' => 14,
            ],
            36 => [
                'AttendanceRequired' => 12,
                'MonthsWindow' => 0,
                'DaysWindow' => 180,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'weeks',
                'ProvinceMode' => false,
                'MinAge' => 14,
            ],
            27 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 3,
                'AttendanceMode' => 'weeks',
                'WeekOffset' => 6,
                'ProvinceMode' => false,
            ],
            38 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'days',
                'ProvinceMode' => false,
                'WeekSnap' => true,
            ],
            4 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'count',
                'ProvinceMode' => false,
                'HomeParkOnly' => true,
                'KingdomEventBonus' => true,
                'WeekSnap' => true,
            ],
            6 => [
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 6,
                'AttendanceMode' => 'weeks',
                'WeekOffset' => 1,
                'ProvinceMode' => false,
                'ActiveKnightThreshold' => 8,
            ],
            19 => [
                'AttendanceRequired' => 8,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 3,
                'AttendanceMode' => 'count',
                'ProvinceMode' => false,
                'MaxCreditsPerEvent' => 2,
                'MaxOutsideKingdomCredits' => 2,
            ],
            12 => [
                'AttendanceRequired' => 12,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 0,
                'AttendanceMode' => 'count',
                'ProvinceMode' => false,
                'ExcludeEvents' => true,
                'WaiverAgeMonths' => 6,
            ],
            24 => [
                // Citizenship is kingdom-scoped: gauge membership age by first
                // attendance in the kingdom (park_member_since resets on park moves).
                'AttendanceRequired' => 6,
                'MonthsWindow' => 6,
                'MinMembershipMonths' => 3,
                'AttendanceMode' => 'weeks',
                'MembershipMode' => 'first_attendance',
                'ProvinceMode' => false,
                'ShowEventCount' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function rulesForKingdom(int $kingdomId): ?array
    {
        $all = self::allRules();

        return $all[$kingdomId] ?? null;
    }

    /**
     * @return list<int>
     */
    public static function supportedKingdomIds(): array
    {
        return array_keys(self::allRules());
    }
}
