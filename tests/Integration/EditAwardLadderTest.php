<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_kingdomaward.is_ladder gains its first writer, and the official 16 are locked.
 *
 * Covers both write paths that can seed is_ladder/max_level on a kingdomaward row:
 * Kingdom::EditAward (an existing row) and Kingdom::CreateAward (a brand-new row,
 * including an "Add Award Alias" pointed at one of the 16 official orders). Kept in
 * one file/class rather than split, since both share createAuthorizedOfficer(),
 * seed(), and readBack() and both exist to enforce the same requirement (the 16
 * official ladders can never be un-toggled or resized by a kingdom).
 */
final class EditAwardLadderTest extends TestCase
{
    private const MARKER = 'EDITLAD';
    private const KINGDOM_ID = 1;

    private PDO $pdo;
    private Kingdom $kingdom;

    /**
     * Kingdom::EditAward() is Token-gated (IsAuthorized() then
     * checkPermissionOrAuthority('kingdom.award.edit', ...)); a request with no
     * Token resolves mundane_id 0 and is refused before it ever reaches the
     * ladder-writing code this test exercises. The brief's test body omits
     * Token, so setUp() here manufactures one officer, authorized on
     * kingdom_id = 1 (the same kingdom every seed() row belongs to), following
     * the same mundane/session/authorization pattern already used by
     * EventPlanningFixture/AttendanceFixture. ork_test ships with zero
     * ork_mundane rows on this branch, so there is no template row to clone
     * from (the fixtures' usual approach) -- every NOT NULL column is
     * supplied explicitly instead. See task-4-report.md "Concerns" for the
     * full explanation of this deviation from the brief's literal test code.
     */
    private string $token;
    private int $officerMundaneId;

    /** @var list<int> kingdomaward ids whose ork_awards grants must be cleaned up */
    private array $grantIdsToClean = [];

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
        $this->kingdom = new Kingdom();
        $this->token = $this->createAuthorizedOfficer();
    }

    protected function tearDown(): void
    {
        foreach ($this->grantIdsToClean as $kingdomAwardId) {
            $this->pdo->exec('DELETE FROM ork_awards WHERE kingdomaward_id = ' . (int) $kingdomAwardId);
        }
        $this->grantIdsToClean = [];

        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
        if (isset($this->officerMundaneId)) {
            $this->pdo->exec('DELETE FROM ork_session WHERE mundane_id = ' . $this->officerMundaneId);
            $this->pdo->exec('DELETE FROM ork_authorization WHERE mundane_id = ' . $this->officerMundaneId);
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $this->officerMundaneId);
        }
    }

    private function createAuthorizedOfficer(): string
    {
        $token = md5(self::MARKER . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . substr($token, 0, 12));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, 0, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $stmt->execute([
            ':given_name' => self::MARKER,
            ':surname' => 'Officer',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => self::MARKER . ' Officer',
            ':email' => $username . '@example.test',
            ':kingdom_id' => self::KINGDOM_ID,
            ':token' => $token,
            ':waiver_ext' => '',
            ':password_expires' => '2099-01-01 00:00:00',
            ':password_salt' => '',
            ':xtoken' => '',
            ':reeve_qualified_until' => '2000-01-01',
        ]);
        $this->officerMundaneId = (int) $this->pdo->lastInsertId();

        // NOW()/DATE_ADD() computed in SQL, not PHP date(): startup.php sets the
        // default timezone to America/Chicago, so a PHP-side date()/time() value
        // compared against the DB's (UTC) NOW() reads as already-expired.
        $this->pdo->prepare(
            'INSERT INTO ork_session (mundane_id, token, created, last_seen, expires)
             VALUES (:mundane_id, :token, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([
            ':mundane_id' => $this->officerMundaneId,
            ':token' => $token,
        ]);

        // role = 'create' (AUTH_CREATE) satisfies checkPermissionOrAuthority()'s
        // legacy HasAuthority() branch for kingdom.award.edit unconditionally.
        $this->pdo->prepare(
            'INSERT INTO ork_authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (:mundane_id, 0, :kingdom_id, 0, 0, \'create\')'
        )->execute([
            ':mundane_id' => $this->officerMundaneId,
            ':kingdom_id' => self::KINGDOM_ID,
        ]);

        return $token;
    }

    private function seed(int $awardId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (1, :award_id, :name, 0, 0)'
        );
        $stmt->execute([':award_id' => $awardId, ':name' => self::MARKER . '-' . uniqid()]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{is_ladder: int, max_level: int} */
    private function readBack(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_ladder, max_level FROM ork_kingdomaward WHERE kingdomaward_id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['is_ladder' => (int) $row['is_ladder'], 'max_level' => (int) $row['max_level']];
    }

    public function testAKingdomCanLadderifyItsOwnAward(): void
    {
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 7, 'Token' => $this->token,
        ]);

        $this->assertSame(['is_ladder' => 1, 'max_level' => 7], $this->readBack($id));
    }

    public function testMaxRankAboveTwelveIsClampedServerSide(): void
    {
        // Rule 2. max="12" client-side is the first line of defence, not the only one.
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 40, 'Token' => $this->token,
        ]);

        $this->assertSame(12, $this->readBack($id)['max_level']);
    }

    public function testUnladderingIsAllowedOnAKingdomAward(): void
    {
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 7, 'Token' => $this->token,
        ]);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 0, 'MaxLevel' => 7, 'Token' => $this->token,
        ]);

        $this->assertSame(0, $this->readBack($id)['is_ladder']);
    }

    public function testEditAwardRefusesToClearTheLadderFlagOnAnOfficialAward(): void
    {
        // Requirement 1, second line of defence. award_id 21 = Order of the Rose.
        $id = $this->seed(21);
        $this->pdo->exec("UPDATE ork_kingdomaward SET is_ladder = 1 WHERE kingdomaward_id = {$id}");

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 0, 'MaxLevel' => 10, 'Token' => $this->token,
        ]);

        $this->assertSame(1, $this->readBack($id)['is_ladder']);
    }

    public function testEditAwardRefusesAMaxLevelWriteOnAnOfficialAward(): void
    {
        // The official ladders' shape belongs to Amtgard: one kingdom running Order of
        // the Rose to 12 while others run it to 10 makes ladder reports incomparable.
        $id = $this->seed(21);

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 12, 'Token' => $this->token,
        ]);

        $this->assertSame(0, $this->readBack($id)['max_level']);
        $this->assertSame(10, Award::MaxRankFor(21, $this->readBack($id)['max_level']));
    }

    public function testUnladderingDoesNotTouchGrantedRanks(): void
    {
        // Rank display is a property of the grant; rank offering is a property of the
        // award. Un-ticking Ladder is forward-only by construction.
        $id = $this->seed(0);
        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 1, 'MaxLevel' => 10, 'Token' => $this->token,
        ]);
        // Tracked before the INSERT so tearDown() still collects this ork_awards
        // row if the assertion below fails -- ork_awards has no FK back to
        // ork_kingdomaward, so the parent's marker-based cleanup would not.
        $this->grantIdsToClean[] = $id;
        $this->pdo->exec(
            // mundane_id 1 is not a real fixture officer -- ork_awards has no FK
            // to ork_mundane, so any id works; this just needs to be non-zero.
            "INSERT INTO ork_awards (mundane_id, kingdomaward_id, `rank`, date)
             VALUES (1, {$id}, 4, '2020-01-01')"
        );

        $this->kingdom->EditAward([
            'KingdomAwardId' => $id, 'KingdomId' => 1, 'IsLadder' => 0, 'MaxLevel' => 10, 'Token' => $this->token,
        ]);

        $stmt = $this->pdo->prepare('SELECT `rank` FROM ork_awards WHERE kingdomaward_id = :id');
        $stmt->execute([':id' => $id]);
        $this->assertSame(4, (int) $stmt->fetchColumn());
    }

    /**
     * CreateAward() never hands the caller the new kingdomaward_id back (it returns
     * Success(), not the row), so look it up by (kingdom_id, name) -- unique per the
     * schema's UNIQUE KEY (kingdom_id, award_id, name), and every name here is
     * marker-prefixed and uniqid()-suffixed, so tearDown()'s existing
     * "name LIKE 'EDITLAD%'" delete already reaps these rows even if an assertion
     * fails partway through -- no separate id-tracking array needed for this group.
     */
    private function findByName(string $name): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT kingdomaward_id FROM ork_kingdomaward WHERE kingdom_id = :kingdom_id AND name = :name'
        );
        $stmt->execute([':kingdom_id' => self::KINGDOM_ID, ':name' => $name]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, 'CreateAward() did not persist a row named ' . $name);

        return (int) $id;
    }

    public function testCreateAwardCanLadderifyANewKingdomSpecificAward(): void
    {
        $name = self::MARKER . '-create-' . uniqid();
        $this->kingdom->CreateAward([
            'KingdomId' => self::KINGDOM_ID, 'AwardId' => 0, 'Name' => $name,
            'ReignLimit' => 0, 'MonthLimit' => 0, 'IsTitle' => 0, 'TitleClass' => '',
            'IsLadder' => 1, 'MaxLevel' => 7, 'Token' => $this->token,
        ]);

        $this->assertSame(['is_ladder' => 1, 'max_level' => 7], $this->readBack($this->findByName($name)));
    }

    public function testCreateAwardRefusesLadderConfigOnAnAliasOfAnOfficialLadder(): void
    {
        // Requirement 1, fourth line of defence (the hole this task's brief closed):
        // an "Add Award Alias" pointed at award_id 21 (Order of the Rose,
        // ork_award.is_ladder = 1) must not seed is_ladder/max_level onto the new
        // kingdomaward row -- both columns must stay at their 0 default.
        $name = self::MARKER . '-alias-' . uniqid();
        $this->kingdom->CreateAward([
            'KingdomId' => self::KINGDOM_ID, 'AwardId' => 21, 'Name' => $name,
            'ReignLimit' => 0, 'MonthLimit' => 0, 'IsTitle' => 0, 'TitleClass' => '',
            'IsLadder' => 1, 'MaxLevel' => 12, 'Token' => $this->token,
        ]);

        $this->assertSame(['is_ladder' => 0, 'max_level' => 0], $this->readBack($this->findByName($name)));
    }

    public function testCreateAwardMaxLevelAboveTwelveIsClampedOnTheCreatePath(): void
    {
        // Rule 2 applies on create, not only on edit.
        $name = self::MARKER . '-clamp-' . uniqid();
        $this->kingdom->CreateAward([
            'KingdomId' => self::KINGDOM_ID, 'AwardId' => 0, 'Name' => $name,
            'ReignLimit' => 0, 'MonthLimit' => 0, 'IsTitle' => 0, 'TitleClass' => '',
            'IsLadder' => 1, 'MaxLevel' => 40, 'Token' => $this->token,
        ]);

        $this->assertSame(12, $this->readBack($this->findByName($name))['max_level']);
    }
}
