<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerHistoryBackfillTest extends TestCase
{
    private const MARKER = 'zzbackfill';
    private const KINGDOM_ID = 100051;

    private PDO $pdo;
    /** @var array<string,int> */
    private array $seededPositions = [];
    /** @var list<int> */
    private array $seededMundanes = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->seededPositions['crown_a'] = $this->seedPosition('crown_a', 'crown');
        $this->seededPositions['crown_b'] = $this->seedPosition('crown_b', 'crown');
        $this->seededMundanes[] = $this->seedMundane('holder');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        if ($this->seededMundanes) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id IN (' . implode(',', $this->seededMundanes) . ')');
        }
    }

    public function testSeatedOfficerWithUsableModifiedGetsThatStartDate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '2024-05-01 12:00:00');

        $this->runBackfill();

        $row = $this->openTerm($positionId, $mundaneId);
        self::assertNotNull($row, 'a seated officer must end up with an open term');
        self::assertSame('2024-05-01', $row['start_date']);
        self::assertNull($row['end_date']);
    }

    public function testSeatedOfficerWithZeroModifiedGetsANullStartDate(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '0000-00-00 00:00:00');

        $this->runBackfill();

        $row = $this->openTerm($positionId, $mundaneId);
        self::assertNotNull($row);
        self::assertNull(
            $row['start_date'],
            'an unknown start must stay NULL -- never a derived date presented as recorded fact'
        );
    }

    public function testRunningTwiceDoesNotDuplicate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '2024-05-01 12:00:00');

        $this->runBackfill();
        $this->runBackfill();

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND end_date IS NULL'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'the migration must be idempotent');
    }

    public function testAFutureEndDateOnASeatedOfficerIsReopened(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '2026-08-29 16:06:01');
        $future = date('Y-m-d', strtotime('+90 days'));
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                 position_id, display_label, start_date, end_date, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pid, "Test", "2026-08-29", :end, NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId, ':end' => $future,
        ]);

        $this->runBackfill();

        self::assertNotNull(
            $this->openTerm($positionId, $mundaneId),
            'a future end date on a still-seated officer made them read as departed'
        );
    }

    public function testALegitimatelyClosedTermIsNotReopened(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $formerId   = $this->seedMundane('former');
        $this->seededMundanes[] = $formerId;
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                 position_id, display_label, start_date, end_date, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pid, "Test", "2025-01-01", "2025-12-31", NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $formerId,
            ':role' => self::MARKER . '_crown_b', ':pid' => $positionId,
        ]);

        $this->runBackfill();

        self::assertNull(
            $this->openTerm($positionId, $formerId),
            'a person who is no longer seated must stay closed'
        );
    }

    private function runBackfill(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/db-migrations/2026-08-29-officer-history-backfill.sql');
        foreach (explode(';', $sql) as $statement) {
            // Each chunk between semicolons carries its leading `--` doc
            // comment along with the real statement, so a whole-chunk
            // str_starts_with('--') check would skip every statement here.
            // Strip comment-only lines first, then judge what remains.
            $lines = array_filter(
                array_map('trim', explode("\n", $statement)),
                static fn (string $line): bool => $line !== '' && !str_starts_with($line, '--')
            );
            $cleaned = trim(implode("\n", $lines));
            if ($cleaned === '') {
                continue;
            }
            $this->pdo->exec($cleaned);
        }
    }

    /** @return array<string,mixed>|null */
    private function openTerm(int $positionId, int $mundaneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT start_date, end_date FROM ork_officer_history
             WHERE position_id = :pid AND mundane_id = :mid AND end_date IS NULL LIMIT 1'
        );
        $stmt->execute([':pid' => $positionId, ':mid' => $mundaneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function seatOfficer(int $positionId, int $mundaneId, string $modified): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, :mid, :role, 0, 0, :pid, :mod)'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId, ':mod' => $modified,
        ]);
    }

    private function seedPosition(string $suffix, string $classification): int
    {
        $key = self::MARKER . '_' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification,
                 is_pinned, is_system, rbac_role_id, has_auth_role, sort_order,
                 parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
             VALUES (:kid, :key, :title, "", :cls, 0, 0, 0, 0, 100, NULL, 0, NULL, 0, NOW())'
        );
        $stmt->execute([
            ':kid' => self::KINGDOM_ID,
            ':key' => $key,
            ':title' => 'Test ' . $suffix,
            ':cls' => $classification,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedMundane(string $suffix): int
    {
        $username = self::MARKER . '_' . $suffix . '_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES (:g, "Test", "", :u, :p, :e, 0, :kid, "", "", "0000-00-00", "", "", "0000-00-00")'
        );
        $stmt->execute([
            ':g' => self::MARKER,
            ':u' => $username,
            ':p' => self::MARKER . ' ' . $suffix,
            ':e' => $username . '@example.test',
            ':kid' => self::KINGDOM_ID,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
