<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Converting a player to a Legend, the preview of it, undoing it and editing
 * the year. Conversion deletes data, so these pin what goes, what stays and who
 * may do it, against the real tables.
 */
final class LegendConversionTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;
    private int $officer = 0;

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
        $this->officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function player(string $suffix = 'target'): int
    {
        return $this->fixture->addPlayer($this->park, $suffix)['mundane_id'];
    }

    private function isLegendInDb(int $mundaneId): bool
    {
        return (int) $this->fixture->value('mundane', 'is_legend', 'mundane_id = ' . $mundaneId) === 1;
    }

    /** @return list<array<string, mixed>> the player's log rows, oldest first */
    private function logRows(int $mundaneId): array
    {
        return $this->fixture->pdo()
            ->query('SELECT * FROM ' . DB_PREFIX . 'legend_log WHERE mundane_id = ' . $mundaneId . ' ORDER BY legend_log_id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array{Type: string, Label: string, Id: int}> $items
     * @return list<string>
     */
    private function typesOf(array $items): array
    {
        $types = array_values(array_unique(array_column($items, 'Type')));
        sort($types);

        return $types;
    }

    // ---- Who may convert, and what is refused ------------------------------

    public function test_convert_refused_for_park_officer(): void
    {
        $target = $this->player();
        $parkOfficer = $this->fixture->addParkOfficer($this->park);

        $r = Legend::convert($parkOfficer, $target, 2020);

        $this->assertSame(3, $r['Status']);
        $this->assertFalse($this->isLegendInDb($target));
    }

    public function test_convert_refused_for_other_kingdom_officer(): void
    {
        $otherPark = $this->fixture->parkOutsideKingdom($this->kingdom);
        $this->assertGreaterThan(0, $otherPark);
        $stranger = $this->fixture->addKingdomOfficer($this->fixture->kingdomIdForPark($otherPark), $otherPark, 'stranger');
        $target = $this->player();

        $r = Legend::convert($stranger, $target, 2020);

        $this->assertSame(3, $r['Status']);
        $this->assertFalse($this->isLegendInDb($target));
    }

    public function test_convert_rejects_year_before_1983_and_future_year(): void
    {
        $target = $this->player();
        foreach ([0, 1982, (int) date('Y') + 1] as $year) {
            $this->assertSame(1, Legend::convert($this->officer, $target, $year)['Status'], "year $year");
        }
        $this->assertFalse($this->isLegendInDb($target));

        $this->assertSame(0, Legend::convert($this->officer, $target, 1983)['Status']);
        $other = $this->player('thisyear');
        $this->assertSame(0, Legend::convert($this->officer, $other, (int) date('Y'))['Status']);
    }

    public function test_convert_rejects_player_one_and_self(): void
    {
        $this->assertSame(1, Legend::convert($this->officer, 1, 2020)['Status']);
        $admin = $this->fixture->addOrkAdmin($this->park);
        $this->assertSame(1, Legend::convert($admin, 1, 2020)['Status']);
        $this->assertSame(1, Legend::convert($admin, $admin, 2020)['Status']);
        $this->assertSame(1, Legend::convert($this->officer, $this->officer, 2020)['Status']);
        $this->assertFalse($this->isLegendInDb(1));
        $this->assertFalse($this->isLegendInDb($this->officer));
    }

    public function test_convert_rejects_already_legend(): void
    {
        $target = $this->player();
        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $r = Legend::convert($this->officer, $target, 2021);

        $this->assertSame(1, $r['Status']);
        $this->assertSame(2020, (int) $this->fixture->value('mundane', 'legend_year', 'mundane_id = ' . $target));
        $this->assertCount(1, $this->logRows($target));
    }

    public function test_convert_by_parent_kingdom_officer_on_a_principality_player(): void
    {
        $rows = $this->fixture->pdo()->query(
            'SELECT k.kingdom_id, k.parent_kingdom_id, MIN(p.park_id) AS park_id FROM ' . DB_PREFIX . 'kingdom k
             JOIN ' . DB_PREFIX . "park p ON p.kingdom_id = k.kingdom_id AND p.active = 'Active'
             WHERE k.parent_kingdom_id > 0 GROUP BY k.kingdom_id, k.parent_kingdom_id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$rows) {
            $this->markTestSkipped('The sandbox has no principality with a park.');
        }
        $target = $this->fixture->addPlayer((int) $rows['park_id'], 'principality')['mundane_id'];
        $parentOfficer = $this->fixture->addKingdomOfficer((int) $rows['parent_kingdom_id'], $this->park, 'parent');

        $this->assertSame(0, Legend::convert($parentOfficer, $target, 2020)['Status']);
        $this->assertTrue($this->isLegendInDb($target));
    }

    public function test_convert_by_a_park_officer_who_is_also_a_kingdom_officer(): void
    {
        $target = $this->player();
        $both = $this->fixture->addParkOfficer($this->park, 'both');
        $this->fixture->addAuthorization($both, ['kingdom_id' => $this->kingdom]);

        $this->assertSame(0, Legend::convert($both, $target, 2020)['Status']);
    }

    public function test_convert_a_player_with_no_park_and_an_ork_admin(): void
    {
        $noPark = $this->player('nopark');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET park_id = 0 WHERE mundane_id = ' . $noPark);
        $admin = $this->fixture->addOrkAdmin($this->park, 'targetadmin');

        $this->assertSame(0, Legend::convert($this->officer, $noPark, 2020)['Status']);
        $preview = Legend::preview($this->officer, $admin);
        $this->assertContains('ORK admin', array_column($preview['Items'], 'Label'));
        $this->assertSame(0, Legend::convert($this->officer, $admin, 2020)['Status']);

        $this->assertFalse(Legend::isOrkAdmin($admin));
    }

    public function test_convert_rejects_unknown_player(): void
    {
        $admin = $this->fixture->addOrkAdmin($this->park);

        $this->assertSame(1, Legend::convert($admin, 0, 2020)['Status']);
        $this->assertSame(1, Legend::convert($admin, 2147483000, 2020)['Status']);
    }

    // ---- What goes ---------------------------------------------------------

    public function test_convert_clears_future_rsvp_keeps_past_rsvp(): void
    {
        $target = $this->player();
        $future = $this->fixture->futureEvent($this->park);
        $past = $this->fixture->pastEvent($this->park);
        $futureRsvp = $this->fixture->addRsvp($future, $target);
        $pastRsvp = $this->fixture->addRsvp($past, $target);
        $otherRsvp = $this->fixture->addRsvp($future, $this->officer);

        $r = Legend::convert($this->officer, $target, 2020);

        $this->assertSame(0, $r['Status']);
        $this->assertSame(0, $this->fixture->count('event_rsvp', 'rsvp_id = ' . $futureRsvp));
        $this->assertSame(1, $this->fixture->count('event_rsvp', 'rsvp_id = ' . $pastRsvp));
        $this->assertSame(1, $this->fixture->count('event_rsvp', 'rsvp_id = ' . $otherRsvp));
    }

    public function test_convert_clears_future_staff_and_leads_keeps_past(): void
    {
        $target = $this->player();
        $future = $this->fixture->futureEvent($this->park);
        $past = $this->fixture->pastEvent($this->park);
        $futureStaff = $this->fixture->addEventStaff($future, $target);
        $pastStaff = $this->fixture->addEventStaff($past, $target);
        $futureLead = $this->fixture->addScheduleLead($future, $target);
        $pastLead = $this->fixture->addScheduleLead($past, $target);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(0, $this->fixture->count('event_staff', 'event_staff_id = ' . $futureStaff));
        $this->assertSame(1, $this->fixture->count('event_staff', 'event_staff_id = ' . $pastStaff));
        $this->assertSame(0, $this->fixture->count('event_schedule_lead', 'event_schedule_lead_id = ' . $futureLead));
        $this->assertSame(1, $this->fixture->count('event_schedule_lead', 'event_schedule_lead_id = ' . $pastLead));
    }

    public function test_convert_vacates_officer_seat_and_deletes_authorization(): void
    {
        $target = $this->player();
        $kingdomSeat = $this->fixture->addSeat($this->kingdom, 0, 'Regent', $target);
        $parkSeat = $this->fixture->addSeat($this->kingdom, $this->park, 'Prime Minister', $target);
        $unit = $this->fixture->addUnit();
        $event = $this->fixture->eventIdForDetail($this->fixture->futureEvent($this->park));
        $unitAuth = $this->fixture->addAuthorization($target, ['unit_id' => $unit], AUTH_CREATE);
        $eventAuth = $this->fixture->addAuthorization($target, ['event_id' => $event]);
        $parkAuth = $this->fixture->addAuthorization($target, ['park_id' => $this->park]);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        foreach ([$kingdomSeat, $parkSeat] as $seat) {
            $this->assertSame(0, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $seat['officer_id']));
            // The seat keeps its own authorization row, held by nobody, ready for the next officer.
            $this->assertSame(1, $this->fixture->count('authorization', 'mundane_id = 0 AND authorization_id = ' . $seat['authorization_id']));
        }
        $this->assertSame(0, $this->fixture->count('authorization', 'mundane_id = ' . $target));
        foreach ([$unitAuth, $eventAuth, $parkAuth] as $id) {
            $this->assertSame(0, $this->fixture->count('authorization', 'authorization_id = ' . $id));
        }
        $this->assertSame(1, $this->fixture->count('danger_audit', "entity = 'Kingdom' AND entity_id = " . $this->kingdom . " AND method_call = 'Legend::convert' AND by_whom_id = " . $this->officer));
        $this->assertSame(1, $this->fixture->count('danger_audit', "entity = 'Park' AND entity_id = " . $this->park . " AND method_call = 'Legend::convert' AND by_whom_id = " . $this->officer));
    }

    public function test_convert_vacates_seats_set_officer_would_skip(): void
    {
        // A seat with no live authorization link: set_officer finds it and silently does nothing.
        $target = $this->player();
        $unlinked = $this->fixture->addSeat($this->kingdom, 0, 'Monarch', $target, false);
        // A Champion keeps no seat authorization, but a stray row must still go.
        $champion = $this->fixture->addSeat($this->kingdom, 0, 'Champion', $target);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(0, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $unlinked['officer_id']));
        $this->assertSame(0, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $champion['officer_id']));
        $this->assertSame(0, $this->fixture->count('authorization', 'mundane_id = ' . $target));
    }

    public function test_convert_vacates_every_seat_with_its_own_kingdom_and_park(): void
    {
        $target = $this->player();
        $otherPark = $this->fixture->parkOutsideKingdom($this->kingdom);
        $otherKingdom = $this->fixture->kingdomIdForPark($otherPark);
        $home = $this->fixture->addSeat($this->kingdom, $this->park, 'Regent', $target);
        $away = $this->fixture->addSeat($otherKingdom, $otherPark, 'Regent', $target);
        $bystander = $this->player('bystander');
        $sameRoleElsewhere = $this->fixture->addSeat($this->kingdom, 0, 'Regent', $bystander);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(0, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $home['officer_id']));
        $this->assertSame(0, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $away['officer_id']));
        $this->assertSame($bystander, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $sameRoleElsewhere['officer_id']));
        $this->assertSame($bystander, (int) $this->fixture->value('authorization', 'mundane_id', 'authorization_id = ' . $sameRoleElsewhere['authorization_id']));
    }

    public function test_convert_clears_recorder_and_given_by_keeps_recipient_and_artisan(): void
    {
        $target = $this->player();
        $recipient = $this->player('recipient');
        $court = $this->fixture->addCourt($this->kingdom, 0, 'draft', $target);
        $published = $this->fixture->addCourt($this->kingdom, 0, 'published', $target);
        $givenLine = $this->fixture->addCourtLine($court, $recipient, $target);
        $ownLine = $this->fixture->addCourtLine($court, $target, $recipient);
        $artisan = $this->fixture->addCourtArtisan($givenLine, $target);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertNull($this->fixture->value('court', 'recorder_mundane_id', 'court_id = ' . $court));
        $this->assertNull($this->fixture->value('court', 'recorder_mundane_id', 'court_id = ' . $published));
        $this->assertNull($this->fixture->value('court_award', 'given_by_mundane_id', 'court_award_id = ' . $givenLine));
        // A posthumous award line and an artisan credit both stay.
        $this->assertSame($target, (int) $this->fixture->value('court_award', 'mundane_id', 'court_award_id = ' . $ownLine));
        $this->assertSame($recipient, (int) $this->fixture->value('court_award', 'given_by_mundane_id', 'court_award_id = ' . $ownLine));
        $this->assertSame($target, (int) $this->fixture->value('court_award_artisan', 'mundane_id', 'court_award_artisan_id = ' . $artisan));
    }

    public function test_convert_leaves_finalized_courts_and_conferred_lines_alone(): void
    {
        $target = $this->player();
        $recipient = $this->player('recipient');
        $finalized = $this->fixture->addCourt($this->kingdom, 0, 'complete', $target);
        $finalizedLine = $this->fixture->addCourtLine($finalized, $recipient, $target);
        $open = $this->fixture->addCourt($this->kingdom, 0, 'published');
        $conferredLine = $this->fixture->addCourtLine($open, $recipient, $target, 'given');

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame($target, (int) $this->fixture->value('court', 'recorder_mundane_id', 'court_id = ' . $finalized));
        $this->assertSame($target, (int) $this->fixture->value('court_award', 'given_by_mundane_id', 'court_award_id = ' . $finalizedLine));
        $this->assertSame($target, (int) $this->fixture->value('court_award', 'given_by_mundane_id', 'court_award_id = ' . $conferredLine));
    }

    public function test_convert_clears_only_tournaments_that_have_not_started(): void
    {
        $target = $this->player();
        $future = $this->fixture->addTournament($this->kingdom, $this->park, date('Y-m-d H:i:s', strtotime('+7 days')));
        $past = $this->fixture->addTournament($this->kingdom, $this->park, date('Y-m-d H:i:s', strtotime('-7 days')));
        $futureEntry = $this->fixture->addTournamentEntry($future, $target);
        $pastEntry = $this->fixture->addTournamentEntry($past, $target);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(0, $this->fixture->count('participant_mundane', 'participant_mundane_id = ' . $futureEntry));
        $this->assertSame(1, $this->fixture->count('participant_mundane', 'participant_mundane_id = ' . $pastEntry));
    }

    public function test_convert_clears_qual_manager_and_bonus_credit_granter(): void
    {
        $target = $this->player();
        $qual = $this->fixture->addQualManager($this->kingdom, $target);
        $granter = $this->fixture->addBonusCreditGranter($this->kingdom, $target, $this->officer);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(0, $this->fixture->count('qual_manager', 'qual_manager_id = ' . $qual));
        $this->assertSame(0, $this->fixture->count('bonus_credit_granter', 'granter_id = ' . $granter));
    }

    public function test_convert_purges_sessions_and_tokens(): void
    {
        $target = $this->player();
        $bystander = $this->player('bystander');
        $sessionToken = $this->fixture->addSession($target);
        $this->fixture->addSession($target);
        $bystanderToken = $this->fixture->addSession($bystander);
        $appRow = $this->fixture->addAppToken($target);
        $this->assertSame($target, Ork3::$Lib->authorization->ValidateSessionByToken($sessionToken));

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(0, $this->fixture->count('session', 'mundane_id = ' . $target));
        $this->assertSame(0, Ork3::$Lib->authorization->ValidateSessionByToken($sessionToken));
        $this->assertSame('', (string) $this->fixture->value('mundane', 'token', 'mundane_id = ' . $target));
        $this->assertSame('', (string) $this->fixture->value('application_auth', 'token', 'application_auth_id = ' . $appRow));
        $this->assertSame($bystander, Ork3::$Lib->authorization->ValidateSessionByToken($bystanderToken));
    }

    // ---- What stays ---------------------------------------------------------

    public function test_convert_keeps_unit_membership_and_dues(): void
    {
        $target = $this->player();
        $unit = $this->fixture->addUnit();
        $membership = $this->fixture->addUnitMember($unit, $target, true);
        $dues = $this->fixture->addDues($target, $this->kingdom, $this->park);
        $attendance = $this->fixture->addAttendance($this->park, $this->kingdom, $target);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(1, $this->fixture->count('unit_mundane', 'unit_mundane_id = ' . $membership));
        $this->assertSame(1, $this->fixture->count('dues', 'dues_id = ' . $dues));
        $this->assertSame(1, $this->fixture->count('attendance', 'attendance_id = ' . $attendance));
        // Their authority over the unit goes; the membership does not.
        $this->assertSame(0, $this->fixture->count('authorization', 'unit_id = ' . $unit));
    }

    // ---- Status, log and undo ----------------------------------------------

    public function test_convert_sets_inactive_and_undo_restores_prior_value(): void
    {
        $wasActive = $this->player('active');
        $wasInactive = $this->player('inactive');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ' . $wasInactive);

        foreach ([$wasActive => 1, $wasInactive => 0] as $target => $prior) {
            $this->assertSame(0, Legend::convert($this->officer, $target, 2015)['Status']);
            $this->assertTrue(Legend::isLegend($target));
            $this->assertSame(0, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $target));
            $this->assertSame(2015, (int) $this->fixture->value('mundane', 'legend_year', 'mundane_id = ' . $target));
            $this->assertSame($prior, (int) $this->logRows($target)[0]['prior_active']);

            $this->assertSame(0, Legend::undo($this->officer, $target)['Status']);

            $this->assertFalse(Legend::isLegend($target));
            $this->assertSame($prior, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $target));
            $this->assertSame(0, (int) $this->fixture->value('mundane', 'legend_year', 'mundane_id = ' . $target));
        }
    }

    public function test_convert_rolls_back_everything_when_a_step_fails(): void
    {
        $target = $this->player();
        $rsvp = $this->fixture->addRsvp($this->fixture->futureEvent($this->park), $target);
        $seat = $this->fixture->addSeat($this->kingdom, $this->park, 'Regent', $target);
        $auth = $this->fixture->addAuthorization($target, ['park_id' => $this->park]);
        $court = $this->fixture->addCourt($this->kingdom, 0, 'draft', $target);
        $token = $this->fixture->addSession($target);
        $entry = $this->fixture->addTournamentEntry(
            $this->fixture->addTournament($this->kingdom, $this->park, date('Y-m-d H:i:s', strtotime('+7 days'))),
            $target
        );
        $appRow = $this->fixture->addAppToken($target);
        $before = $this->fixture->checksums();
        // The log write comes after all the clearing and before the writes that can't roll back.
        $pdo = $this->fixture->pdo();
        $trigger = 'legend_test_refuse_log';
        $pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger);
        $pdo->exec('CREATE TRIGGER ' . $trigger . ' BEFORE INSERT ON ' . DB_PREFIX . "legend_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused for the test'");

        $errorLog = ini_set('error_log', '/dev/null');

        try {
            $r = Legend::convert($this->officer, $target, 2020);
        } finally {
            ini_set('error_log', (string) $errorLog);
            $pdo->exec('DROP TRIGGER IF EXISTS ' . $trigger);
        }

        $this->assertSame(1, $r['Status']);
        $this->assertSame($before, $this->fixture->checksums());
        $this->assertFalse(Legend::isLegend($target));
        $this->assertSame(1, $this->fixture->count('event_rsvp', 'rsvp_id = ' . $rsvp));
        $this->assertSame($target, (int) $this->fixture->value('officer', 'mundane_id', 'officer_id = ' . $seat['officer_id']));
        $this->assertSame(1, $this->fixture->count('authorization', 'authorization_id = ' . $auth));
        $this->assertSame($target, (int) $this->fixture->value('court', 'recorder_mundane_id', 'court_id = ' . $court));
        $this->assertSame($target, Ork3::$Lib->authorization->ValidateSessionByToken($token));
        // The two MyISAM tables can't roll back; they are written after everything that can fail.
        $this->assertSame(1, $this->fixture->count('participant_mundane', 'participant_mundane_id = ' . $entry));
        $this->assertNotSame('', (string) $this->fixture->value('application_auth', 'token', 'application_auth_id = ' . $appRow));
    }

    public function test_undo_returns_snapshot_and_keeps_memories(): void
    {
        $target = $this->player();
        $this->fixture->addRsvp($this->fixture->futureEvent($this->park), $target);
        $this->fixture->addSeat($this->kingdom, $this->park, 'Regent', $target);
        $this->fixture->addQualManager($this->kingdom, $target);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);
        $memory = $this->fixture->addMemory($target, $this->officer);

        $r = Legend::undo($this->officer, $target);

        $this->assertSame(0, $r['Status']);
        $this->assertSame(['officer', 'qual_manager', 'rsvp'], $this->typesOf($r['Cleared']));
        foreach ($r['Cleared'] as $item) {
            $this->assertNotSame('', $item['Label']);
            $this->assertGreaterThan(0, $item['Id']);
        }
        $this->assertSame(1, $this->fixture->count('legend_memory', 'legend_memory_id = ' . $memory . ' AND removed_by_id = 0'));
        $rows = $this->logRows($target);
        $this->assertSame(['convert', 'undo'], array_column($rows, 'action'));
        $this->assertSame(2018, (int) $rows[1]['legend_year']);
        $this->assertSame($this->officer, (int) $rows[1]['actor_id']);
    }

    public function test_undo_rejects_non_legend(): void
    {
        $target = $this->player();

        $r = Legend::undo($this->officer, $target);

        $this->assertSame(1, $r['Status']);
        $this->assertCount(0, $this->logRows($target));
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $target));
    }

    public function test_undo_refused_for_park_officer(): void
    {
        $target = $this->player();
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(3, Legend::undo($parkOfficer, $target)['Status']);

        $this->assertTrue($this->isLegendInDb($target));
    }

    public function test_cleared_lists_the_snapshot_without_changing_anything(): void
    {
        $target = $this->player();
        $this->fixture->addRsvp($this->fixture->futureEvent($this->park), $target);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);

        $r = Legend::cleared($this->officer, $target);

        $this->assertSame(0, $r['Status']);
        $this->assertSame(['rsvp'], $this->typesOf($r['Cleared']));
        $this->assertSame($r['Cleared'], Legend::undo($this->officer, $target)['Cleared'], 'what is shown is what undo reports');
    }

    public function test_cleared_writes_nothing(): void
    {
        $target = $this->player();
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);
        $before = $this->fixture->checksums();
        $logs   = count($this->logRows($target));

        Legend::cleared($this->officer, $target);

        $this->assertSame($before, $this->fixture->checksums());
        $this->assertCount($logs, $this->logRows($target));
        $this->assertTrue($this->isLegendInDb($target));
    }

    public function test_cleared_skips_a_merge_rehomed_row_and_takes_the_latest_conversion(): void
    {
        $target = $this->player();
        $this->fixture->addRsvp($this->fixture->futureEvent($this->park), $target);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);
        $this->fixture->pdo()->exec(
            'INSERT INTO ' . DB_PREFIX . "legend_log (mundane_id, action, legend_year, prior_active, cleared_json, actor_id)
             VALUES ({$target}, 'convert', 2018, 1, '{\"MergedFrom\":1}', {$this->officer})"
        );

        $this->assertSame(['rsvp'], $this->typesOf(Legend::cleared($this->officer, $target)['Cleared']));
    }

    public function test_cleared_refused_for_park_officer_and_non_legend(): void
    {
        $target = $this->player();
        $living = $this->player('living');
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $refused = Legend::cleared($parkOfficer, $target);
        $this->assertSame(3, $refused['Status']);
        $this->assertArrayNotHasKey('Cleared', $refused);
        $this->assertSame(1, Legend::cleared($this->officer, $living)['Status']);
        $this->assertSame(3, Legend::cleared(0, $target)['Status']);
    }

    public function test_reconvert_writes_a_second_snapshot(): void
    {
        $target = $this->player();
        $firstRsvp = $this->fixture->addRsvp($this->fixture->futureEvent($this->park), $target);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);
        $this->assertSame(0, Legend::undo($this->officer, $target)['Status']);
        $this->fixture->addQualManager($this->kingdom, $target);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2019)['Status']);

        $rows = $this->logRows($target);
        $this->assertSame(['convert', 'undo', 'convert'], array_column($rows, 'action'));
        $this->assertSame([$firstRsvp], array_column(json_decode($rows[0]['cleared_json'], true), 'Id'));
        $this->assertSame(['qual_manager'], $this->typesOf(json_decode($rows[2]['cleared_json'], true)));
        // The second conversion's prior_active is the one undo restores, and undo restored it to 1.
        $this->assertSame(1, (int) $rows[2]['prior_active']);

        $r = Legend::undo($this->officer, $target);

        $this->assertSame(['qual_manager'], $this->typesOf($r['Cleared']));
    }

    public function test_snapshot_survives_a_hostile_or_unicode_name(): void
    {
        $target = $this->player();
        $detail = $this->fixture->futureEvent($this->park);
        $this->fixture->addRsvp($detail, $target);
        $name = "<script>alert('x')</script> \"Fête\"";
        $this->fixture->pdo()->prepare(
            'UPDATE ' . DB_PREFIX . 'event SET name = ? WHERE event_id = ?'
        )->execute([$name, $this->fixture->eventIdForDetail($detail)]);

        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $json = (string) $this->logRows($target)[0]['cleared_json'];
        $items = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($name, $items[0]['Label']);
    }

    // ---- Preview -------------------------------------------------------------

    public function test_preview_warns_on_officer_seat_and_sole_unit_manager(): void
    {
        $target = $this->player();
        $this->fixture->addSeat($this->kingdom, 0, 'Regent', $target);
        $soleUnit = $this->fixture->addUnit('Sole Manager Company');
        $this->fixture->addUnitMember($soleUnit, $target, true);
        $sharedUnit = $this->fixture->addUnit('Shared Manager Company');
        $this->fixture->addUnitMember($sharedUnit, $target, true);
        $this->fixture->addUnitMember($sharedUnit, $this->officer, true);

        $r = Legend::preview($this->officer, $target);

        $this->assertSame(0, $r['Status']);
        $warnings = implode("\n", $r['Warnings']);
        $this->assertStringContainsString('Regent', $warnings);
        $this->assertStringContainsString('Sole Manager Company', $warnings);
        $this->assertStringNotContainsString('Shared Manager Company', $warnings);
        $this->assertCount(2, $r['Warnings']);
    }

    public function test_preview_counts_a_legend_as_no_manager(): void
    {
        $target = $this->player();
        $unit = $this->fixture->addUnit('Departed Managers Company');
        $this->fixture->addUnitMember($unit, $target, true);
        $other = $this->fixture->addPlayer($this->park, 'otherlegend', 2010)['mundane_id'];
        $this->fixture->addUnitMember($unit, $other, true);

        $r = Legend::preview($this->officer, $target);

        $this->assertStringContainsString('Departed Managers Company', implode("\n", $r['Warnings']));
    }

    public function test_preview_lists_what_convert_clears(): void
    {
        $target = $this->player();
        $recipient = $this->player('recipient');
        $future = $this->fixture->futureEvent($this->park);
        $this->fixture->addRsvp($future, $target);
        $this->fixture->addEventStaff($future, $target);
        $this->fixture->addScheduleLead($future, $target);
        $this->fixture->addSeat($this->kingdom, $this->park, 'Regent', $target);
        $this->fixture->addAuthorization($target, ['unit_id' => $this->fixture->addUnit()], AUTH_CREATE);
        $this->fixture->addTournamentEntry(
            $this->fixture->addTournament($this->kingdom, $this->park, date('Y-m-d H:i:s', strtotime('+7 days'))),
            $target
        );
        $court = $this->fixture->addCourt($this->kingdom, 0, 'draft', $target);
        $this->fixture->addCourtLine($court, $recipient, $target);
        $this->fixture->addQualManager($this->kingdom, $target);
        $this->fixture->addBonusCreditGranter($this->kingdom, $target, $this->officer);

        $preview = Legend::preview($this->officer, $target);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);

        $this->assertSame(
            ['authorization', 'bonus_credit_granter', 'court_given_by', 'court_recorder', 'event_staff', 'officer', 'qual_manager', 'rsvp', 'schedule_lead', 'tournament'],
            $this->typesOf($preview['Items'])
        );
        // The seat's own authorization row goes with the seat, so it isn't listed twice.
        $this->assertCount(10, $preview['Items']);
        $this->assertSame($preview['Items'], json_decode($this->logRows($target)[0]['cleared_json'], true));
    }

    public function test_preview_changes_nothing(): void
    {
        $target = $this->player();
        $this->fixture->addRsvp($this->fixture->futureEvent($this->park), $target);
        $this->fixture->addSeat($this->kingdom, 0, 'Regent', $target);
        $this->fixture->addSession($target);
        $before = $this->fixture->checksums();

        $r = Legend::preview($this->officer, $target);

        $this->assertSame(0, $r['Status']);
        $this->assertNotEmpty($r['Items']);
        $this->assertSame($before, $this->fixture->checksums());
    }

    public function test_preview_refuses_what_convert_refuses(): void
    {
        $target = $this->player();
        $parkOfficer = $this->fixture->addParkOfficer($this->park);

        $this->assertSame(3, Legend::preview($parkOfficer, $target)['Status']);
        $this->assertSame(1, Legend::preview($this->officer, 1)['Status']);
        $this->assertSame(1, Legend::preview($this->officer, $this->officer)['Status']);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2020)['Status']);
        $this->assertSame(1, Legend::preview($this->officer, $target)['Status']);
    }

    // ---- Year ----------------------------------------------------------------

    public function test_set_year_updates_and_logs(): void
    {
        $target = $this->player();
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);

        $this->assertSame(0, Legend::setYear($this->officer, $target, 2019)['Status']);

        $this->assertSame(2019, (int) $this->fixture->value('mundane', 'legend_year', 'mundane_id = ' . $target));
        $rows = $this->logRows($target);
        $this->assertSame(['convert', 'year'], array_column($rows, 'action'));
        $this->assertSame(2019, (int) $rows[1]['legend_year']);
        $this->assertSame($this->officer, (int) $rows[1]['actor_id']);
        // A year edit leaves the last conversion's snapshot as the one undo returns.
        $this->assertSame(0, Legend::undo($this->officer, $target)['Status']);
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $target));
    }

    public function test_set_year_refuses_bad_year_non_legend_and_park_officer(): void
    {
        $target = $this->player();
        $parkOfficer = $this->fixture->addParkOfficer($this->park);

        $this->assertSame(1, Legend::setYear($this->officer, $target, 2019)['Status']);
        $this->assertSame(0, Legend::convert($this->officer, $target, 2018)['Status']);
        $this->assertSame(1, Legend::setYear($this->officer, $target, 1982)['Status']);
        $this->assertSame(1, Legend::setYear($this->officer, $target, (int) date('Y') + 1)['Status']);
        $this->assertSame(3, Legend::setYear($parkOfficer, $target, 2019)['Status']);

        $this->assertSame(2018, (int) $this->fixture->value('mundane', 'legend_year', 'mundane_id = ' . $target));
        $this->assertCount(1, $this->logRows($target));
    }

    /** The controller's year comes from a form field; anything but a whole number reads as 0, which is refused. */
    public function test_parse_year(): void
    {
        $this->assertSame(1999, Legend::parseYear('1999'));
        $this->assertSame(1999, Legend::parseYear(' 1999 '));
        foreach (['', 'abc', '1999abc', '1999.5', '19e2', null, []] as $raw) {
            $this->assertSame(0, Legend::parseYear($raw), var_export($raw, true));
        }
    }
}
