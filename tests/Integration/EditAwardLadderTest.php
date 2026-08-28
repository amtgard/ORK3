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
     * kingdom_id = 1 (the same kingdom every seed() row belongs to), via the
     * shared AuthorizedOfficerFixture (Task 8 extracted this out of this file
     * so LadderGrantRuleTest could share it). ork_test ships with zero
     * ork_mundane rows on this branch, so there is no template row to clone
     * from (the fixtures' usual approach) -- every NOT NULL column is
     * supplied explicitly instead. See task-4-report.md "Concerns" for the
     * full explanation of this deviation from the brief's literal test code.
     */
    private string $token;
    private AuthorizedOfficerFixture $officer;

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
        $this->officer = new AuthorizedOfficerFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        $this->token = $this->officer->createAuthorizedOfficer();
    }

    protected function tearDown(): void
    {
        foreach ($this->grantIdsToClean as $kingdomAwardId) {
            $this->pdo->exec('DELETE FROM ork_awards WHERE kingdomaward_id = ' . (int) $kingdomAwardId);
        }
        $this->grantIdsToClean = [];

        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
        $this->officer->cleanup();
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

    /**
     * Seed a kingdom-specific award that is ALREADY a ladder, the way the 24
     * hand-configured rows on production look.
     */
    private function seedLadder(int $maxLevel = 5): int
    {
        $id = $this->seed(0);
        $this->pdo->exec(
            "UPDATE ork_kingdomaward SET is_ladder = 1, max_level = {$maxLevel} WHERE kingdomaward_id = {$id}"
        );

        return $id;
    }

    /** The five scalar fields every editor DOES send, so nothing else is at stake. */
    private function unrelatedEdit(int $id, string $name): array
    {
        return [
            'KingdomAwardId' => $id,
            'KingdomId' => self::KINGDOM_ID,
            'Name' => $name,
            'ReignLimit' => 3,
            'MonthLimit' => 0,
            'IsTitle' => 0,
            'TitleClass' => '',
            'Token' => $this->token,
        ];
    }

    private function nameOf(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM ork_kingdomaward WHERE kingdomaward_id = :id');
        $stmt->execute([':id' => $id]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * THE DATA-LOSS DEFECT. Two of the three live editors that POST into EditAward
     * -- Admin/editkingdom's awards tab and the Kingdom profile Admin > Awards panel
     * -- never send IsLadder at all. Reading an absent field as "set it to 0" meant a
     * rename silently demoted the kingdom's ladder, with no warning and no audit
     * trail. Absence must mean "leave unchanged".
     */
    public function testEditAwardWithoutIsLadderLeavesAnExistingLadderIntact(): void
    {
        $id = $this->seedLadder(5);
        $newName = self::MARKER . '-renamed-' . uniqid();

        $this->kingdom->EditAward($this->unrelatedEdit($id, $newName));

        // The rename landed...
        $this->assertSame($newName, $this->nameOf($id));
        // ...and did not take the ladder with it.
        $this->assertSame(1, $this->readBack($id)['is_ladder']);
    }

    /** The guard must not make the flag un-clearable: an explicit 0 still clears. */
    public function testEditAwardWithIsLadderZeroStillClearsTheFlag(): void
    {
        $id = $this->seedLadder(5);

        $this->kingdom->EditAward(
            ['IsLadder' => 0] + $this->unrelatedEdit($id, self::MARKER . '-untick-' . uniqid())
        );

        $this->assertSame(0, $this->readBack($id)['is_ladder']);
    }

    /** Same pair for max_level: an omitted MaxLevel must not reset 5 -> 0. */
    public function testEditAwardWithoutMaxLevelLeavesAnExistingMaxLevelIntact(): void
    {
        $id = $this->seedLadder(5);

        $this->kingdom->EditAward($this->unrelatedEdit($id, self::MARKER . '-keepmax-' . uniqid()));

        $this->assertSame(5, $this->readBack($id)['max_level']);
    }

    public function testEditAwardWithMaxLevelZeroStillClearsIt(): void
    {
        $id = $this->seedLadder(5);

        $this->kingdom->EditAward(
            ['IsLadder' => 0, 'MaxLevel' => 0] + $this->unrelatedEdit($id, self::MARKER . '-zeromax-' . uniqid())
        );

        $this->assertSame(['is_ladder' => 0, 'max_level' => 0], $this->readBack($id));
    }

    /**
     * FAIL CLOSED. The official-ladder lookup used to leave $officialLadder = false
     * when DataSet() came back falsy, so a database hiccup let the write through --
     * the last remaining way to clobber an official ladder's configuration. A lookup
     * that does not answer must be read as "official", i.e. no ladder write at all.
     *
     * The stub replaces only Kingdom::$db (the raw-SQL handle). The yapo objects
     * built in the constructor still hold the real $DB, so find()/save() work
     * normally and the test proves the OTHER fields were saved while the ladder
     * columns were left alone -- not merely that the whole call aborted.
     */
    public function testAFailedOfficialLookupDoesNotPermitALadderWrite(): void
    {
        $id = $this->seedLadder(5);
        $newName = self::MARKER . '-failclosed-' . uniqid();

        $kingdom = new Kingdom();
        $kingdom->db = new class () {
            public function Clear(): void
            {
            }

            /** @return false */
            public function DataSet(string $sql)
            {
                return false; // simulate the lookup failing
            }
        };

        $kingdom->EditAward(
            ['IsLadder' => 0, 'MaxLevel' => 0] + $this->unrelatedEdit($id, $newName)
        );

        $this->assertSame($newName, $this->nameOf($id), 'the rest of the edit should still have saved');
        $this->assertSame(['is_ladder' => 1, 'max_level' => 5], $this->readBack($id));
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
