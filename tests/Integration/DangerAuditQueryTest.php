<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Admin::auditlog queries (T-ADM-03).
 */
final class DangerAuditQueryTest extends TestCase
{
    private AdminDashboardFixture $fixture;
    private Dangeraudit $auditDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
        $this->auditDomain = new Dangeraudit();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testAuditLogPagination(): void
    {
        $parkId = $this->fixture->firstParkId();
        $actor = $this->fixture->createPlayer($parkId, 'audit-actor');
        $method = 'T08ADM.TestMethod.' . bin2hex(random_bytes(4));

        for ($i = 0; $i < 3; $i++) {
            $this->fixture->insertAuditRow($method, $actor['mundane_id'], 'Player', $actor['mundane_id']);
        }

        $page1 = $this->auditDomain->ListAuditLog([
            'Start' => date('Y-m-d', strtotime('-7 days')),
            'End' => date('Y-m-d'),
            'MethodCall' => $method,
            'Page' => 1,
            'PerPage' => 2,
        ]);
        $page2 = $this->auditDomain->ListAuditLog([
            'Start' => date('Y-m-d', strtotime('-7 days')),
            'End' => date('Y-m-d'),
            'MethodCall' => $method,
            'Page' => 2,
            'PerPage' => 2,
        ]);

        $this->assertSame(3, $page1['total']);
        $this->assertCount(2, $page1['rows']);
        $this->assertCount(1, $page2['rows']);
        $this->assertSame(2, $page1['perPage']);
    }

    public function testEntityTypeGuard(): void
    {
        $parkId = $this->fixture->firstParkId();
        $actor = $this->fixture->createPlayer($parkId, 'entity-guard');
        $method = 'T08ADM.EntityGuard.' . bin2hex(random_bytes(4));

        $this->fixture->insertAuditRow($method, $actor['mundane_id'], 'Unit', $actor['mundane_id']);

        $withoutEntityId = $this->auditDomain->ListAuditLog([
            'Start' => date('Y-m-d', strtotime('-7 days')),
            'End' => date('Y-m-d'),
            'MethodCall' => $method,
            'EntityType' => 'Player',
            'EntityId' => 0,
        ]);
        $withEntityId = $this->auditDomain->ListAuditLog([
            'Start' => date('Y-m-d', strtotime('-7 days')),
            'End' => date('Y-m-d'),
            'MethodCall' => $method,
            'EntityType' => 'Player',
            'EntityId' => $actor['mundane_id'],
        ]);

        $this->assertSame(1, $withoutEntityId['total']);
        $this->assertSame(0, $withEntityId['total']);

        $this->auditDomain->audit('T08ADM.TestAudit', ['foo' => 'bar'], 'Player', $actor['mundane_id']);
    }
}
