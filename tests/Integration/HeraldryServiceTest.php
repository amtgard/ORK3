<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for EventAjax SetEventHeraldry lib path (T-LIB-09).
 */
final class HeraldryServiceTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private Heraldry $heraldry;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = EventPlanningFixture::create();
        $this->heraldry = new Heraldry();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testSetEventHeraldryRejectsUnauthorized(): void
    {
        $ctx = $this->fixture->createPublishedEvent('heraldry-unauth');

        $r = $this->heraldry->SetEventHeraldry([
            'Token' => '',
            'EventId' => $ctx['event_id'],
        ]);

        $this->assertSame(ServiceErrorIds::NoAuthorization, $r['Status']);
    }

    public function testSetEventHeraldryRejectsInvalidEvent(): void
    {
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_ADMIN, 0, AUTH_ADMIN, 'heraldry-global');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->heraldry->SetEventHeraldry([
            'Token' => $grantor['token'],
            'EventId' => 999999999,
            'HeraldryUrl' => '',
        ]);

        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']);
    }

    public function testSetEventHeraldryAuthorizedWithoutImage(): void
    {
        $ctx = $this->fixture->createPublishedEvent('heraldry-auth');
        $grantor = $this->fixture->createGrantorWithAuth(
            AUTH_EVENT,
            $ctx['event_id'],
            AUTH_EDIT,
            'heraldry-editor',
        );

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->heraldry->SetEventHeraldry([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'HeraldryUrl' => '',
        ]);

        $this->assertSame(0, $r['Status']);
    }
}
