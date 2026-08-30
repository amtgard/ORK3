<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerTransitionTest extends TestCase
{
    private const MARKER = 'zztransition';
    private const KINGDOM_ID = 100021;

    private PDO $pdo;
    private OfficerPosition $positions;
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
        $this->positions = new OfficerPosition();
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

    public function testSkipHistorySuppressesTheLegacyHistoryWrite(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];

        // Seed the vacant slot set_officer's find() requires -- without this row,
        // find() fails and NOTHING is written, which would make this test pass
        // trivially regardless of whether $skip_history is honored.
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, 0, :role, 0, 0, :pid, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':role' => self::MARKER . '_crown_a', ':pid' => $positionId]);

        $common = new Common();
        $common->set_officer(
            self::KINGDOM_ID,
            0,
            $mundaneId,
            self::MARKER . '_crown_a',
            0,
            0,
            $positionId,
            'Test crown_a',
            true   // $skip_history
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'skip_history must suppress the term write entirely'
        );
    }

    public function testDefaultStillWritesHistorySoExistingCallersAreUnchanged(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $mundaneId  = $this->seededMundanes[0];

        // Seed the vacant slot set_officer's find() requires.
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, 0, :role, 0, 0, :pid, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':role' => self::MARKER . '_crown_b', ':pid' => $positionId]);

        $common = new Common();
        $common->set_officer(
            self::KINGDOM_ID,
            0,
            $mundaneId,
            self::MARKER . '_crown_b',
            0,
            0,
            $positionId,
            'Test crown_b'        // flag omitted
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND end_date IS NULL'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'omitting the flag must behave exactly as before'
        );
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
