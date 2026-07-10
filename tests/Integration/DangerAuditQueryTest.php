<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Admin::auditlog queries (T-ADM-03) and
 * auth-add audit side effects on residual lib paths (T-LIB-08–10, T-LIB-12).
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

    public function testAuthAddAuditKingdomScope(): void
    {
        $this->assertAuthAddAuditRow(AUTH_KINGDOM, $this->fixture->firstKingdomId(), [
            'kingdom_id' => $this->fixture->firstKingdomId(),
            'park_id' => 0,
            'event_id' => 0,
        ]);
    }

    public function testAuthAddAuditParkScope(): void
    {
        $parkId = $this->fixture->firstParkId();
        $this->assertAuthAddAuditRow(AUTH_PARK, $parkId, [
            'park_id' => $parkId,
            'kingdom_id' => 0,
            'event_id' => 0,
        ]);
    }

    public function testAuthAddAuditEventScope(): void
    {
        $parkId = $this->fixture->firstParkId();
        $event = $this->fixture->createPublishedEvent($parkId, 'audit-event');
        $this->assertAuthAddAuditRow(AUTH_EVENT, $event['event_id'], [
            'park_id' => 0,
            'kingdom_id' => 0,
            'event_id' => $event['event_id'],
        ]);
    }

    public function testAuthAddAuditGlobalAdminScope(): void
    {
        $this->assertAuthAddAuditRow(AUTH_ADMIN, 0, [
            'park_id' => 0,
            'kingdom_id' => 0,
            'event_id' => 0,
            'role' => AUTH_ADMIN,
        ]);
    }

    /**
     * @param array<string, mixed> $postState
     */
    private function assertAuthAddAuditRow(string $type, int $entityId, array $postState): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createPlayer($parkId, 'audit-grantor');
        $grantee = $this->fixture->createPlayer($parkId, 'audit-grantee');
        $authId = 900000 + random_int(1, 99999);
        $method = 'Authorization::AddAuthorization';

        $_SESSION['is_authorized_mundane_id'] = $grantor['mundane_id'];
        $this->auditDomain->audit(
            $method,
            ['MundaneId' => $grantee['mundane_id'], 'Type' => $type, 'Id' => $entityId, 'Role' => AUTH_CREATE],
            'Player',
            $grantee['mundane_id'],
            null,
            array_merge([
                'authorization_id' => $authId,
                'mundane_id' => $grantee['mundane_id'],
                'unit_id' => 0,
                'role' => $postState['role'] ?? AUTH_CREATE,
            ], $postState),
        );
        unset($_SESSION['is_authorized_mundane_id']);

        $page = $this->auditDomain->ListAuditLog([
            'Start' => date('Y-m-d', strtotime('-1 day')),
            'End' => date('Y-m-d', strtotime('+1 day')),
            'MethodCall' => $method,
            'EntityType' => 'Player',
            'EntityId' => $grantee['mundane_id'],
        ]);

        $this->assertGreaterThanOrEqual(1, $page['total']);
        $row = $page['rows'][0];
        $this->assertSame($method, $row['MethodCall']);
        $this->assertSame($grantee['mundane_id'], (int) $row['EntityId']);
    }
}
