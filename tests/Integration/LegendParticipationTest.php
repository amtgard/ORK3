<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Legend can't be added to anything forward-looking. Pickers can be bypassed,
 * so the library refuses: attendance, RSVPs, staff, entries, court roles, unit
 * and officer seats, authorizations, dues, qual results and recommendations.
 * Every refusal carries Legend::REFUSED and writes nothing.
 */
final class LegendParticipationTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;
    private int $admin = 0;
    private string $token = '';
    private int $legend = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
        $this->admin   = $this->fixture->addOrkAdmin($this->park);
        $this->token   = $this->fixture->addSession($this->admin);
        $this->legend  = $this->fixture->addPlayer($this->park, 'legend', 1999)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'bonus_credit WHERE mundane_id = ' . (int) $this->legend);
            $this->fixture->pdo()->exec('DELETE FROM ' . DB_PREFIX . "bonus_credit_category WHERE name = 'Legend fixture credit'");
            $this->fixture->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'qual_result WHERE player_id = ' . (int) $this->legend);
            $this->fixture->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'recommendations WHERE mundane_id = ' . (int) $this->legend);
            $this->fixture->cleanup();
        }
    }

    private function player(string $suffix = 'plain'): int
    {
        return $this->fixture->addPlayer($this->park, $suffix)['mundane_id'];
    }

    private function assertRefused(array $r, string $message = ''): void
    {
        $this->assertNotSame(0, $r['Status'], $message ?: 'the call must not report success');
        $this->assertSame(Legend::REFUSED, $r['Detail'] ?? $r['Error'] ?? null, $message);
    }

    private function classId(): int
    {
        return (int) $this->fixture->value('class', 'class_id', "active = 1");
    }

    private function attendanceRequest(int $mundaneId): array
    {
        return [
            'Token' => $this->token, 'MundaneId' => $mundaneId, 'ClassId' => $this->classId(),
            'Date' => date('Y-m-d'), 'Credits' => 1, 'ParkId' => $this->park, 'KingdomId' => 0,
            'EventId' => 0, 'EventCalendarDetailId' => 0, 'AttendanceId' => 0,
        ];
    }

    // ---- Attendance --------------------------------------------------------

    public function test_add_attendance_refused_even_for_ork_admin(): void
    {
        $att = new Attendance();
        $control = $att->AddAttendance($this->attendanceRequest($this->player()));
        $this->assertSame(0, $control['Status'], 'control: the admin may add a plain player');

        $r = $att->AddAttendance($this->attendanceRequest($this->legend));

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('attendance', 'mundane_id = ' . $this->legend));
    }

    public function test_set_attendance_cannot_reassign_to_legend(): void
    {
        $owner = $this->player('owner');
        $id = $this->fixture->addAttendance($this->park, $this->kingdom, $owner);

        $r = (new Attendance())->SetAttendance(['AttendanceId' => $id] + $this->attendanceRequest($this->legend));

        $this->assertRefused($r);
        $this->assertSame($owner, (int) $this->fixture->value('attendance', 'mundane_id', 'attendance_id = ' . $id));
    }

    public function test_attendance_does_not_reactivate_legend(): void
    {
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ' . $this->legend);

        $r = (new Attendance())->AddAttendance($this->attendanceRequest($this->legend));

        $this->assertRefused($r);
        $this->assertSame(0, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $this->legend));
    }

    // ---- Bonus credits and granters ---------------------------------------

    public function test_bonus_grant_refused(): void
    {
        $r = (new BonusCredit())->grant($this->admin, ['MundaneId' => $this->legend, 'ClassId' => $this->classId(), 'CategoryId' => 1]);

        $this->assertSame(3, $r['Status']);
        $this->assertSame(Legend::REFUSED, $r['Error']);
    }

    public function test_bonus_granter_assignment_refused(): void
    {
        $r = (new BonusCredit())->addGranter($this->admin, $this->kingdom, $this->legend);

        $this->assertSame(3, $r['Status']);
        $this->assertSame(Legend::REFUSED, $r['Error']);
        $this->assertSame(0, $this->fixture->count('bonus_credit_granter', 'mundane_id = ' . $this->legend));
    }

    // ---- Events ------------------------------------------------------------

    public function test_rsvp_refused_including_on_behalf(): void
    {
        $detail = $this->fixture->futureEvent($this->park);
        $event = new Event();
        $request = ['Token' => $this->token, 'EventCalendarDetailId' => $detail, 'MundaneId' => $this->legend, 'Status' => 'going'];

        $r = $event->SetRsvp($request);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('event_rsvp', 'mundane_id = ' . $this->legend));
        $control = $event->SetRsvp(['MundaneId' => $this->player()] + $request);
        $this->assertSame(0, $control['Status']['Status'], 'control: an on-behalf RSVP for a plain player works');
    }

    public function test_event_staff_refused(): void
    {
        $detail = $this->fixture->futureEvent($this->park);
        $r = (new EventPlanning())->AddEventStaff([
            'Token' => $this->token, 'EventId' => $this->fixture->eventIdForDetail($detail),
            'EventCalendarDetailId' => $detail, 'MundaneId' => $this->legend, 'RoleName' => 'Marshal',
        ]);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('event_staff', 'mundane_id = ' . $this->legend));
    }

    public function test_schedule_lead_refused(): void
    {
        $detail = $this->fixture->futureEvent($this->park);
        $r = (new EventPlanning())->AddEventSchedule([
            'Token' => $this->token, 'EventId' => $this->fixture->eventIdForDetail($detail),
            'EventCalendarDetailId' => $detail, 'Title' => 'Feast', 'StartTime' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'EndTime' => date('Y-m-d H:i:s', strtotime('+2 days 1 hour')), 'Category' => 'Other',
            'Leads' => [['MundaneId' => $this->legend]],
        ]);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('event_schedule_lead', 'mundane_id = ' . $this->legend));
    }

    public function test_tournament_participant_refused(): void
    {
        $tournament = $this->fixture->addTournament($this->kingdom, $this->park, date('Y-m-d H:i:s', strtotime('+3 days')));
        $r = (new Tournament())->AddParticipant([
            'Token' => $this->token, 'TournamentId' => $tournament, 'BracketId' => 0, 'ParticipantId' => 0,
            'MundaneId' => $this->legend, 'Alias' => 'Ghost',
        ]);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('participant', 'tournament_id = ' . $tournament));
    }

    // ---- Court Planner -----------------------------------------------------

    public function test_court_recorder_refused(): void
    {
        $court = $this->fixture->addCourt($this->kingdom, $this->park);
        $domain = new Court();

        $this->assertFalse($domain->updateCourt($court, ['RecorderMundaneId' => $this->legend]));
        $this->assertNull($this->fixture->value('court', 'recorder_mundane_id', 'court_id = ' . $court));
        $this->assertTrue($domain->updateCourt($court, ['RecorderMundaneId' => $this->player('recorder')]), 'control');
    }

    public function test_court_giver_refused(): void
    {
        $court = $this->fixture->addCourt($this->kingdom, $this->park);
        $line = $this->fixture->addCourtLine($court, $this->player('recipient'));

        $this->assertFalse((new Court())->stageAward($line, $this->legend, '', 1));
        $this->assertSame('planned', $this->fixture->value('court_award', 'status', 'court_award_id = ' . $line));
    }

    public function test_court_award_to_legend_allowed(): void
    {
        $court = $this->fixture->addCourt($this->kingdom, $this->park);
        $line = $this->fixture->addCourtLine($court, $this->legend);

        $this->assertTrue((new Court())->stageAward($line, $this->player('giver'), '', 1));
        $this->assertSame('staged', $this->fixture->value('court_award', 'status', 'court_award_id = ' . $line));
    }

    public function test_court_artisan_legend_allowed(): void
    {
        $court = $this->fixture->addCourt($this->kingdom, $this->park);
        $line = $this->fixture->addCourtLine($court, $this->player('recipient'));

        $artisan = (new Court())->addArtisan($line, $this->legend, 'Scroll');

        $this->assertSame(1, $this->fixture->count('court_award_artisan', 'mundane_id = ' . $this->legend));
        $this->assertTrue($artisan['IsLegend'], 'the add response carries the flag the planner draws the pill from');
    }

    public function test_court_artisan_living_player_is_not_flagged(): void
    {
        $court = $this->fixture->addCourt($this->kingdom, $this->park);
        $line = $this->fixture->addCourtLine($court, $this->player('recipient'));

        $artisan = (new Court())->addArtisan($line, $this->player('giver'), 'Scroll');

        $this->assertFalse($artisan['IsLegend']);
    }

    // ---- Units, seats and authority ---------------------------------------

    public function test_unit_add_refused_existing_member_kept(): void
    {
        $unit = $this->fixture->addUnit();
        $member = $this->fixture->addUnitMember($unit, $this->legend);
        $domain = new Unit();
        $add = ['UnitId' => $unit, 'Role' => 'member', 'Title' => '', 'Active' => 'Active'];

        $again = $domain->add_member_h(['MundaneId' => $this->legend] + $add);
        $this->assertNotSame(Legend::REFUSED, $again['Detail'] ?? null, 'an existing active row is not this guard');
        $this->assertSame(1, $this->fixture->count('unit_mundane', 'mundane_id = ' . $this->legend . ' AND unit_id = ' . $unit));

        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "unit_mundane SET active = 'Retired' WHERE unit_mundane_id = " . $member);
        $this->assertRefused($domain->add_member_h(['MundaneId' => $this->legend] + $add), 'a retired Legend is not revived');
        $this->assertSame('Retired', $this->fixture->value('unit_mundane', 'active', 'unit_mundane_id = ' . $member));

        $other = $this->fixture->addUnit('Legend Fixture Other');
        $this->assertRefused($domain->add_member_h(['UnitId' => $other, 'MundaneId' => $this->legend] + $add), 'a new add is refused');
        $this->assertSame(0, $this->fixture->count('unit_mundane', 'unit_id = ' . $other));
    }

    public function test_unit_ownership_transfer_to_legend_refused(): void
    {
        $unit = $this->fixture->addUnit();
        $r = (new Unit())->TransferOwnership(['Token' => $this->token, 'UnitId' => $unit, 'MundaneId' => $this->legend]);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('authorization', 'mundane_id = ' . $this->legend));
    }

    public function test_officer_assignment_refused(): void
    {
        $before = $this->fixture->checksums()['officer'];

        $park = (new Park())->SetOfficer([
            'Token' => $this->token, 'ParkId' => $this->park, 'KingdomId' => $this->kingdom,
            'MundaneId' => $this->legend, 'Role' => 'Monarch',
        ]);
        $kingdom = (new Kingdom())->SetOfficer([
            'Token' => $this->token, 'KingdomId' => $this->kingdom, 'MundaneId' => $this->legend, 'Role' => 'Monarch',
        ]);

        $this->assertRefused($park);
        $this->assertRefused($kingdom);
        $this->assertSame($before, $this->fixture->checksums()['officer']);
    }

    public function test_authorization_grant_refused(): void
    {
        $r = (new Authorization())->AddAuthorization([
            'Token' => $this->token, 'MundaneId' => $this->legend, 'Type' => AUTH_PARK, 'Id' => $this->park, 'Role' => AUTH_EDIT,
        ]);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('authorization', 'mundane_id = ' . $this->legend));
    }

    public function test_bonus_update_refused_for_everyone(): void
    {
        $pdo = $this->fixture->pdo();
        $classId = $this->classId();
        $pdo->exec('INSERT INTO ' . DB_PREFIX . "bonus_credit_category (scope_type, scope_id, name, default_credits, created_by, created_at)
            VALUES ('kingdom', {$this->kingdom}, 'Legend fixture credit', 2, {$this->admin}, NOW())");
        $category = (int) $pdo->lastInsertId();
        $pdo->exec('INSERT INTO ' . DB_PREFIX . "bonus_credit (mundane_id, class_id, credits, credit_date, category_id, granted_by, granted_at)
            VALUES ({$this->legend}, {$classId}, 1, CURDATE(), {$category}, {$this->admin}, NOW())");
        $credit = (int) $pdo->lastInsertId();
        $kingdomOfficer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);

        foreach ([$this->admin, $kingdomOfficer] as $uid) {
            $r = (new BonusCredit())->update($uid, $credit, ['Credits' => 2]);
            $this->assertSame(3, $r['Status']);
            $this->assertSame(Legend::REFUSED, $r['Error']);
        }
        $this->assertSame('1.00', $this->fixture->value('bonus_credit', 'credits', 'bonus_credit_id = ' . $credit));
    }

    // ---- Unit revival, face matching ---------------------------------------

    public function test_unit_set_member_cannot_revive_a_legend(): void
    {
        $unit = $this->fixture->addUnit();
        $pdo = $this->fixture->pdo();
        $domain = new Unit();
        $legendRow = $this->fixture->addUnitMember($unit, $this->legend);
        $plainRow = $this->fixture->addUnitMember($unit, $this->player('plainmember'));
        $pdo->exec('UPDATE ' . DB_PREFIX . "unit_mundane SET active = 'Retired' WHERE unit_mundane_id IN ($legendRow, $plainRow)");
        $set = fn (int $row, string $active) => $domain->SetMember([
            'Token' => $this->token, 'UnitMundaneId' => $row, 'Active' => $active, 'Role' => 'member', 'Title' => 'x',
        ]);

        $this->assertSame(0, $set($plainRow, 'Active')['Status'], 'control: a plain player is revived');
        $this->assertRefused($set($legendRow, 'Active'));
        $this->assertSame('Retired', $this->fixture->value('unit_mundane', 'active', 'unit_mundane_id = ' . $legendRow));

        $pdo->exec('UPDATE ' . DB_PREFIX . "unit_mundane SET active = 'Active' WHERE unit_mundane_id = $legendRow");
        $this->assertSame(0, $set($legendRow, 'Active')['Status'], 'an already-active Legend member keeps working');
        $this->assertSame('x', $this->fixture->value('unit_mundane', 'title', 'unit_mundane_id = ' . $legendRow));
    }

    public function test_unit_member_calls_refused_on_an_active_legend_and_work_on_a_plain_player(): void
    {
        $unit = $this->fixture->addUnit();
        $domain = new Unit();
        $legendRow = $this->fixture->addUnitMember($unit, $this->legend);
        $plainRow = $this->fixture->addUnitMember($unit, $this->player('plainunit'));
        $active = fn (int $row) => $this->fixture->value('unit_mundane', 'active', 'unit_mundane_id = ' . $row);
        $set = fn (int $row, string $a) => $domain->SetMember([
            'Token' => $this->token, 'UnitMundaneId' => $row, 'Active' => $a, 'Role' => 'member', 'Title' => 'y',
        ]);

        $this->assertRefused($set($legendRow, 'Retired'));
        $this->assertRefused($domain->RetireMember(['Token' => $this->token, 'UnitMundaneId' => $legendRow]));
        $this->assertRefused($domain->RemoveMember(['Token' => $this->token, 'UnitMundaneId' => $legendRow, 'UnitId' => $unit]));
        $this->assertSame('Active', $active($legendRow));
        $this->assertSame(1, $this->fixture->count('unit_mundane', 'unit_mundane_id = ' . $legendRow));

        $this->assertSame(0, $set($plainRow, 'Retired')['Status'], 'control: SetMember retires a plain player');
        $this->assertSame('Retired', $active($plainRow));
        $this->assertSame(0, $set($plainRow, 'Active')['Status']);
        $this->assertSame(0, $domain->RetireMember(['Token' => $this->token, 'UnitMundaneId' => $plainRow])['Status'], 'control: RetireMember');
        $this->assertSame(0, $domain->RemoveMember(['Token' => $this->token, 'UnitMundaneId' => $plainRow, 'UnitId' => $unit])['Status'], 'control: RemoveMember');
        $this->assertSame(0, $this->fixture->count('unit_mundane', 'unit_mundane_id = ' . $plainRow));
    }

    public function test_may_move_and_can_edit_admin_gates(): void
    {
        $plain = $this->player('movable');
        $officer = $this->fixture->addParkOfficer($this->park);

        $this->assertTrue(Legend::mayMove($officer, $plain), 'anyone may move a plain player');
        $this->assertFalse(Legend::mayMove($officer, $this->legend), 'a park officer may not move a Legend');
        $this->assertTrue(Legend::mayMove($this->admin, $this->legend), 'an ORK admin may');

        $this->assertTrue(Legend::canEditAdmin($officer, $plain, $this->park));
        $this->assertFalse(Legend::canEditAdmin($officer, $this->legend, $this->park), 'park EDIT alone is gated on a Legend');
        $this->assertTrue(Legend::canEditAdmin($this->admin, $this->legend, $this->park));
        $this->assertFalse(Legend::canEditAdmin(0, $plain, $this->park), 'logged out');
    }

    public function test_face_image_never_posted_for_a_legend(): void
    {
        $r = (new Player())->AddOneShotFaceImage(['Token' => $this->token, 'MundaneId' => $this->legend, 'Base64FaceImage' => 'x']);

        $this->assertRefused($r);
    }

    /** LookupByFaces calls the third-party matcher, so the filter it applies is covered directly. */
    public function test_face_matches_drop_legends(): void
    {
        $plain = $this->player();

        $this->assertSame([$plain], (new Player())->without_legends([$this->legend, $plain]));
    }

    // ---- Dues, qual tests and recommendations ------------------------------

    public function test_dues_refused(): void
    {
        $r = (new Player())->AddDues(['Token' => $this->token, 'MundaneId' => $this->legend, 'Semesters' => 1]);

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('dues', 'mundane_id = ' . $this->legend));
    }

    public function test_qual_result_refused(): void
    {
        $qual = new QualTest();

        $this->assertFalse($qual->addManager($this->kingdom, $this->legend));
        $this->assertSame(0, $this->fixture->count('qual_manager', 'mundane_id = ' . $this->legend));
        $this->assertNull($qual->recordResult($this->legend, $this->kingdom, 'reeve', 95, 365));
        $this->assertSame(0, $qual->recordAttempt($this->legend, $this->kingdom, 'reeve', 95, 80, true, []));
        $this->assertSame(0, $this->fixture->count('qual_result', 'player_id = ' . $this->legend));
    }

    private function recommendation(array $extra = []): array
    {
        $kingdomAward = (int) $this->fixture->value('kingdomaward', 'kingdomaward_id', 'kingdom_id = ' . $this->kingdom . ' AND award_id > 0');

        return (new Player())->AddAwardRecommendation([
            'Token' => $this->token, 'MundaneId' => $this->legend, 'KingdomAwardId' => $kingdomAward, 'AwardId' => 0,
            'Rank' => 0, 'Reason' => 'Remembered fondly.', 'MonarchyOnly' => 0,
        ] + $extra);
    }

    public function test_recommendation_refused_without_from_profile(): void
    {
        $r = $this->recommendation();

        $this->assertRefused($r);
        $this->assertSame(0, $this->fixture->count('recommendations', 'mundane_id = ' . $this->legend));
    }

    public function test_recommendation_allowed_from_profile(): void
    {
        $r = $this->recommendation(['FromProfile' => 1]);

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame(1, $this->fixture->count('recommendations', 'mundane_id = ' . $this->legend));
    }
}
