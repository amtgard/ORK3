<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Admin::auditlog queries (T-ADM-03).
 */
final class DangerAuditQueryTest extends TestCase
{
    private AdminDashboardFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
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

        $page1 = $this->mirrorAuditPage($method, page: 1, perPage: 2);
        $page2 = $this->mirrorAuditPage($method, page: 2, perPage: 2);

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

        $withoutEntityId = $this->mirrorAuditPage($method, entityType: 'Player', entityId: 0);
        $withEntityId = $this->mirrorAuditPage($method, entityType: 'Player', entityId: $actor['mundane_id']);

        $this->assertSame(1, $withoutEntityId['total']);
        $this->assertSame(0, $withEntityId['total']);

        $audit = new DangerAudit();
        $audit->audit('T08ADM.TestAudit', ['foo' => 'bar'], 'Player', $actor['mundane_id']);
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>, perPage: int}
     */
    private function mirrorAuditPage(
        string $methodFilter,
        int $page = 1,
        int $perPage = 50,
        string $entityType = '',
        int $entityId = 0,
    ): array {
        $offset = ($page - 1) * $perPage;
        $start = date('Y-m-d', strtotime('-7 days'));
        $end = date('Y-m-d');

        global $DB;
        $where = "da.modified_at >= '" . mysql_real_escape_string($start) . " 00:00:00'"
            . " AND da.modified_at <= '" . mysql_real_escape_string($end) . " 23:59:59'";
        if ($methodFilter !== '') {
            $where .= " AND da.method_call = '" . mysql_real_escape_string($methodFilter) . "'";
        }
        if ($entityId > 0) {
            $where .= ' AND da.entity_id = ' . $entityId;
        }
        if ($entityId > 0 && $entityType !== '') {
            $where .= " AND da.entity = '" . mysql_real_escape_string($entityType) . "'";
        }

        $DB->Clear();
        $cr = $DB->DataSet('SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . "danger_audit da WHERE {$where}");
        $total = 0;
        if ($cr && $cr->Next()) {
            $total = (int) $cr->cnt;
        }

        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT da.danger_audit_id, da.method_call, da.entity, da.entity_id, da.by_whom_id, da.modified_at
             FROM ' . DB_PREFIX . "danger_audit da
             WHERE {$where}
             ORDER BY da.modified_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $rows = [];
        if ($rs && $rs->Size() > 0) {
            do {
                if (empty($rs->method_call)) {
                    continue;
                }
                $rows[] = [
                    'Id' => (int) $rs->danger_audit_id,
                    'MethodCall' => $rs->method_call,
                    'Entity' => $rs->entity,
                    'EntityId' => (int) $rs->entity_id,
                ];
            } while ($rs->Next());
        }

        return ['total' => $total, 'rows' => $rows, 'perPage' => $perPage];
    }
}
