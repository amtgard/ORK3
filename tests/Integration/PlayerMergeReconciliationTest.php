<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * BR-061: MergePlayer must add the source's reconciled class credits to the
 * destination instead of discarding them when both players have a row.
 */
final class PlayerMergeReconciliationTest extends TestCase
{
    private PlayerProfileFixture $fixture;
    private PDO $pdo;
    /** @var list<int> */
    private array $mundaneIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->fixture = PlayerProfileFixture::create();
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        if (isset($this->pdo)) {
            foreach ($this->mundaneIds as $id) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'class_reconciliation WHERE mundane_id = ' . (int) $id);
            }
        }
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function setReconciled(int $mundaneId, int $classId, int $n): void
    {
        $this->pdo->exec(sprintf(
            'INSERT INTO %sclass_reconciliation (class_id, mundane_id, reconciled) VALUES (%d, %d, %d)',
            DB_PREFIX,
            $classId,
            $mundaneId,
            $n,
        ));
    }

    /** @return array<int,int> class_id => reconciled */
    private function rows(int $mundaneId): array
    {
        $out = [];
        $q = $this->pdo->query(
            'SELECT class_id, reconciled FROM ' . DB_PREFIX . 'class_reconciliation WHERE mundane_id = ' . $mundaneId
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['class_id']] = (int) $r['reconciled'];
        }
        ksort($out);

        return $out;
    }

    /** @param array<int,int> $a @return array<int,int> */
    private function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    public function testMergeSumsSharedClassAndMovesOthers(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $admin = $this->fixture->createPlayer($parkId, 'mrg-admin');
        $this->fixture->insertScopedAuth($admin['mundane_id'], $parkId, $kingdomId, 'admin');
        $from = $this->fixture->createPlayer($parkId, 'mrg-from');
        $to = $this->fixture->createPlayer($parkId, 'mrg-to');
        $this->mundaneIds = [$from['mundane_id'], $to['mundane_id']];

        $classIds = array_map('intval', $this->pdo->query(
            'SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id LIMIT 3'
        )->fetchAll(PDO::FETCH_COLUMN));
        if (count($classIds) < 3) {
            $this->markTestSkipped('Need at least 3 classes.');
        }
        [$shared, $onlyFrom, $onlyTo] = $classIds;
        $this->setReconciled($from['mundane_id'], $shared, 5);
        $this->setReconciled($to['mundane_id'], $shared, 3);
        $this->setReconciled($from['mundane_id'], $onlyFrom, 2);
        $this->setReconciled($to['mundane_id'], $onlyTo, 4);

        $res = (new Player())->MergePlayer([
            'Token' => $admin['token'],
            'FromMundaneId' => $from['mundane_id'],
            'ToMundaneId' => $to['mundane_id'],
        ]);
        $this->assertSame(0, $res['Status'] ?? -1, json_encode($res));

        $this->assertSame([], $this->rows($from['mundane_id']));
        $this->assertSame(
            $this->sorted([$shared => 8, $onlyFrom => 2, $onlyTo => 4]),
            $this->rows($to['mundane_id']),
        );
    }
}
