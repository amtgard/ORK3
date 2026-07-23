<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Model_Event RSVP methods (T-RSV-04 through T-RSV-09).
 *
 * Pre-refactor logic lives in orkui/model/model.Event.php; R-01 will move these
 * behaviors to system/lib/ork3/class.Event.php and rewire this suite.
 */
final class EventRsvpTest extends TestCase
{
    private EventRsvpFixture $fixture;

    private Model_Event $model;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);
        $this->fixture = EventRsvpFixture::create();
        $this->model = new Model_Event();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testSetRsvpInsertsGoing(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('insert-going');

        $result = $this->model->set_rsvp($ctx['detail_id'], $ctx['mundane_id'], 'going', $ctx['token']);

        $this->assertSame('going', $result);
        $this->assertSame('going', $this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testSetRsvpUpdatesStatus(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('update-status');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'interested');

        $result = $this->model->set_rsvp($ctx['detail_id'], $ctx['mundane_id'], 'going', $ctx['token']);

        $this->assertSame('going', $result);
        $this->assertSame('going', $this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testWithdrawRsvpDeletesRow(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('withdraw');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $removed = $this->model->remove_rsvp($ctx['detail_id'], $ctx['mundane_id']);

        $this->assertTrue($removed);
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
        $counts = $this->model->get_rsvp_count($ctx['detail_id']);
        $this->assertSame(0, $counts['total']);
    }

    public function testSetRsvpRejectsInvalidStatus(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('invalid-status');

        $result = $this->model->set_rsvp($ctx['detail_id'], $ctx['mundane_id'], 'maybe', $ctx['token']);

        // Model_Event coerces unknown status to 'going' (form-post path behavior).
        $this->assertSame('going', $result);
        $this->assertSame('going', $this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testGetRsvpCountsByDetail(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('counts');
        $otherId = $this->fixture->insertSecondPlayer($ctx['park_id'], $ctx['kingdom_id'], 'counts-b');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');
        $this->fixture->insertRsvp($ctx['detail_id'], $otherId, 'interested');

        $counts = $this->model->get_rsvp_count($ctx['detail_id']);

        $this->assertSame(1, $counts['going']);
        $this->assertSame(1, $counts['interested']);
        $this->assertSame(2, $counts['total']);
    }

    public function testGetBatchRsvpCounts(): void
    {
        $ctxA = $this->fixture->createFutureOccurrence('batch-a');
        $ctxB = $this->fixture->createFutureOccurrence('batch-b');
        $this->fixture->insertRsvp($ctxA['detail_id'], $ctxA['mundane_id'], 'going');
        $this->fixture->insertRsvp($ctxB['detail_id'], $ctxB['mundane_id'], 'interested');

        $countsA = $this->model->get_rsvp_count($ctxA['detail_id']);
        $countsB = $this->model->get_rsvp_count($ctxB['detail_id']);

        $this->assertSame(1, $countsA['going']);
        $this->assertSame(0, $countsA['interested']);
        $this->assertSame(0, $countsB['going']);
        $this->assertSame(1, $countsB['interested']);
    }

    public function testGetRsvpListIncludesPlayerFields(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('list-fields');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $list = $this->model->get_rsvp_list($ctx['detail_id']);

        $this->assertCount(1, $list);
        $row = $list[0];
        $this->assertSame($ctx['mundane_id'], $row['MundaneId']);
        $this->assertSame('going', $row['Status']);
        $this->assertArrayHasKey('Persona', $row);
        $this->assertArrayHasKey('ParkAbbr', $row);
        $this->assertArrayHasKey('KingdomAbbr', $row);
        $this->assertArrayHasKey('LastClassId', $row);
        $this->assertArrayHasKey('LastClassName', $row);
    }

    public function testGetUpcomingRsvpsExcludesPast(): void
    {
        $future = $this->fixture->createFutureOccurrence('upcoming-future');
        $past = $this->fixture->createPastOccurrence('upcoming-past');
        $this->fixture->insertRsvp($future['detail_id'], $future['mundane_id'], 'going');
        $this->fixture->insertRsvp($past['detail_id'], $past['mundane_id'], 'interested');

        $list = $this->model->get_upcoming_rsvps($future['mundane_id']);

        $detailIds = array_column($list, 'EventCalendarDetailId');
        $this->assertContains($future['detail_id'], $detailIds);
        $this->assertNotContains($past['detail_id'], $detailIds);
    }

    public function testGetKingdomUpcomingEventsExcludesExistingRsvp(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('kingdom-exclude');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $events = $this->model->get_kingdom_upcoming_events($ctx['kingdom_id'], $ctx['mundane_id']);

        $detailIds = array_column($events, 'EventCalendarDetailId');
        $this->assertNotContains($ctx['detail_id'], $detailIds);
    }

    public function testRemoveRsvpStaffRequiresAuth(): void
    {
        // Staff authorization is enforced in Controller_EventAjax::delete_rsvp, not Model_Event.
        // Model remove_rsvp is a direct delete used after auth checks pass.
        $ctx = $this->fixture->createFutureOccurrence('staff-remove');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $this->assertTrue($this->model->remove_rsvp($ctx['detail_id'], $ctx['mundane_id']));
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testToggleOffSemantics(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('toggle-off');
        $this->model->set_rsvp($ctx['detail_id'], $ctx['mundane_id'], 'going', $ctx['token']);

        $result = $this->model->set_rsvp($ctx['detail_id'], $ctx['mundane_id'], 'going', $ctx['token']);

        $this->assertFalse($result);
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testSetRsvpRequiresToken(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('c12-no-token');
        unset($_SESSION['is_authorized_mundane_id']);

        $event = new Event();
        $noToken = $event->SetRsvp([
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $ctx['mundane_id'],
            'Status' => 'going',
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $noToken['Status']);
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));

        $this->assertFalse(
            $this->model->set_rsvp($ctx['detail_id'], $ctx['mundane_id'], 'going', '')
        );
    }

    public function testSetRsvpActorCannotMutateOtherWithoutAuth(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('c12-idor');
        $stranger = $this->fixture->createGrantorWithoutAuth('c12-stranger');
        unset($_SESSION['is_authorized_mundane_id']);

        $event = new Event();
        $denied = $event->SetRsvp([
            'Token' => $stranger['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $ctx['mundane_id'],
            'Status' => 'going',
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status']);
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));

        $withdrawDenied = $event->WithdrawRsvp([
            'Token' => $stranger['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $ctx['mundane_id'],
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $withdrawDenied['Status']);
    }

    public function testSetRsvpActorCanMutateSelf(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('c12-self');
        unset($_SESSION['is_authorized_mundane_id']);

        $event = new Event();
        $ok = $event->SetRsvp([
            'Token' => $ctx['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $ctx['mundane_id'],
            'Status' => 'interested',
        ]);
        $this->assertSame(ServiceErrorIds::Success, $ok['Status']['Status']);
        $this->assertSame('interested', $ok['MyStatus'] ?? null);

        $withdraw = $event->WithdrawRsvp([
            'Token' => $ctx['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $ctx['mundane_id'],
        ]);
        $this->assertSame(ServiceErrorIds::Success, $withdraw['Status']['Status']);
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testSetRsvpStaffCanMutateOther(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('c12-staff');
        $target = $this->fixture->createGrantorWithoutAuth('c12-target');
        $staff = $this->fixture->createGrantorWithoutAuth('c12-staffer');
        $this->fixture->insertAttendanceStaff($ctx['detail_id'], $staff['mundane_id']);
        unset($_SESSION['is_authorized_mundane_id']);

        $event = new Event();
        $ok = $event->SetRsvp([
            'Token' => $staff['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $target['mundane_id'],
            'Status' => 'going',
        ]);
        $this->assertSame(ServiceErrorIds::Success, $ok['Status']['Status']);
        $this->assertSame('going', $this->model->get_rsvp($ctx['detail_id'], $target['mundane_id']));

        $editor = $this->fixture->createGrantorWithAuth(
            AUTH_EVENT,
            $ctx['event_id'],
            AUTH_EDIT,
            'c12-editor'
        );
        unset($_SESSION['is_authorized_mundane_id']);
        $withdraw = $event->WithdrawRsvp([
            'Token' => $editor['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $target['mundane_id'],
        ]);
        $this->assertSame(ServiceErrorIds::Success, $withdraw['Status']['Status']);
        $this->assertFalse($this->model->get_rsvp($ctx['detail_id'], $target['mundane_id']));
    }
}
