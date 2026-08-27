<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Task 9: grant-from-recommendation bucketing.
 *
 * Two defects, both stemming from inferring "is this a ladder?" from the wrong
 * signal:
 *
 * 1. Kingdomnew_recommendations_panel.tpl's `data-filter` bucketed a row on
 *    whether it happened to carry a rank ((int)$rec['Rank'] > 0), not on
 *    whether the award actually is a ladder. A kingdom-ladder recommendation
 *    has no rank yet, so it filed under "Non-Ladder Awards & Titles" and the
 *    officer was told to Grant-or-Delete a ranked award as though it were
 *    flat. bucketFor() below mirrors the template's (fixed) three-branch
 *    expression, the way AwardOptionGroupsTest::mirrorCategorizeSampleAwards()
 *    mirrors its subject -- it does not exercise the .tpl file itself.
 *
 * 2. Player::AddAwardRecommendation's custom-award detection ("Custom awards
 *    (is_ladder = 0 AND is_title = 0)") queried ork_award.is_ladder alone --
 *    the OFFICIAL flag -- so a kingdom that raises a shared "Custom Award"
 *    catalog row to ladder status via ka.is_ladder=1 was still misdetected as
 *    a plain custom award, and custom awards are deliberately exempt from the
 *    duplicate-recommendation guard. testKingdomLadderIsNoLongerTreatedAsCustom...
 *    below proves the guard now fires by seeding exactly that shape (a
 *    kingdomaward row on the shared Custom Award award_id, ka.is_ladder=1) and
 *    showing a second identical recommendation is rejected.
 */
final class RecommendationBucketTest extends TestCase
{
    private const MARKER = 'RECBKT';
    private const KINGDOM_ID = 1;

    private PDO $pdo;
    private Player $player;
    private AuthorizedOfficerFixture $officer;
    private string $token;
    private int $recipientId;
    private int $customAwardId;

    /** @var list<int> kingdomaward ids to clean up */
    private array $kingdomAwardIdsToClean = [];

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
        $this->player = new Player();
        $this->officer = new AuthorizedOfficerFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        $this->token = $this->officer->createAuthorizedOfficer();
        $this->recipientId = $this->seedRecipient();

        $stmt = $this->pdo->query("SELECT award_id FROM ork_award WHERE name = 'Custom Award' LIMIT 1");
        $customAwardId = $stmt->fetchColumn();
        if ($customAwardId === false) {
            $this->markTestSkipped('No "Custom Award" system award row in seed data.');
        }
        $this->customAwardId = (int) $customAwardId;
    }

    protected function tearDown(): void
    {
        if (isset($this->recipientId)) {
            $this->pdo->exec('DELETE FROM ork_recommendations WHERE mundane_id = ' . $this->recipientId);
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $this->recipientId);
        }
        foreach ($this->kingdomAwardIdsToClean as $kaId) {
            $this->pdo->exec('DELETE FROM ork_kingdomaward WHERE kingdomaward_id = ' . $kaId);
        }
        $this->kingdomAwardIdsToClean = [];
        $this->officer->cleanup();
    }

    private function seedRecipient(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, 0, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $username = strtolower(self::MARKER . '_recipient_' . bin2hex(random_bytes(4)));
        $stmt->execute([
            ':given_name' => self::MARKER,
            ':surname' => 'Recipient',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => self::MARKER . ' Recipient',
            ':email' => $username . '@example.test',
            ':kingdom_id' => self::KINGDOM_ID,
            ':token' => md5($username),
            ':waiver_ext' => '',
            ':password_expires' => '2099-01-01 00:00:00',
            ':password_salt' => '',
            ':xtoken' => '',
            ':reeve_qualified_until' => '2000-01-01',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A kingdomaward row on the shared "Custom Award" system award (is_ladder=0,
     * is_title=0 on the official row) but with ka.is_ladder=1 -- a kingdom that
     * has raised its own custom award to ladder status. Effective is_ladder
     * (Award::LadderSql()) is 1; official a.is_ladder is 0.
     */
    private function seedKingdomLadderOnCustomAward(int $maxLevel = 5): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, 1, :max_level)'
        );
        $stmt->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':award_id' => $this->customAwardId,
            ':name' => self::MARKER . '-' . uniqid(),
            ':max_level' => $maxLevel,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->kingdomAwardIdsToClean[] = $id;

        return $id;
    }

    public function testKingdomLadderIsNoLongerTreatedAsCustomDuplicateGuardFires(): void
    {
        $kaId = $this->seedKingdomLadderOnCustomAward();

        $first = $this->player->AddAwardRecommendation([
            'Token' => $this->token,
            'MundaneId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Rank' => 3,
            'Reason' => self::MARKER,
        ]);
        $this->assertSame(0, (int) $first['Status'], 'first recommendation must succeed: ' . (string) ($first['Detail'] ?? ''));

        $second = $this->player->AddAwardRecommendation([
            'Token' => $this->token,
            'MundaneId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Rank' => 3,
            'Reason' => self::MARKER,
        ]);

        // Before the fix: is_ladder was read from a.is_ladder (0 for the shared
        // "Custom Award" row) alone, so this kingdom ladder was misdetected as a
        // custom award and the duplicate guard below never ran -- the second,
        // identical recommendation would also return Status 0.
        $this->assertNotSame(0, (int) $second['Status'], 'a kingdom ladder must not be exempted from the duplicate-recommendation guard the way a genuine custom award is');
        $this->assertStringContainsString('already recommended', (string) ($second['Detail'] ?? ''));
    }

    public function testGenuineCustomAwardIsStillExemptFromTheDuplicateGuard(): void
    {
        // Control: the same shared "Custom Award" row, but ka.is_ladder left at
        // its default (0) -- a genuinely custom award, not a kingdom ladder.
        // Unlimited duplicate recommendations must still be allowed for it.
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, 0, 0)'
        );
        $stmt->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':award_id' => $this->customAwardId,
            ':name' => self::MARKER . '-plain-' . uniqid(),
        ]);
        $kaId = (int) $this->pdo->lastInsertId();
        $this->kingdomAwardIdsToClean[] = $kaId;

        $first = $this->player->AddAwardRecommendation([
            'Token' => $this->token,
            'MundaneId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Rank' => 0,
            'Reason' => self::MARKER,
        ]);
        $this->assertSame(0, (int) $first['Status']);

        $second = $this->player->AddAwardRecommendation([
            'Token' => $this->token,
            'MundaneId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Rank' => 0,
            'Reason' => self::MARKER,
        ]);
        $this->assertSame(0, (int) $second['Status'], 'a genuine (non-ladder) custom award must remain exempt from the duplicate guard');
    }

    public function testKingdomLadderRecommendationBucketsAsBelow(): void
    {
        $rec = ['Rank' => 0, 'IsLadder' => 1];
        $this->assertSame('below', $this->bucketFor($rec));
    }

    public function testGenuineNonLadderRecommendationStillBucketsAsNonladder(): void
    {
        $rec = ['Rank' => 0, 'IsLadder' => 0];
        $this->assertSame('nonladder', $this->bucketFor($rec));
    }

    public function testRankedLadderRecommendationStillBucketsAsBelow(): void
    {
        $rec = ['Rank' => 4, 'IsLadder' => 1];
        $this->assertSame('below', $this->bucketFor($rec));
    }

    public function testAlreadyHasTakesPriorityOverLadderStatus(): void
    {
        // The `already` branch must survive: a recommendation for an award the
        // player already holds must bucket as 'already' regardless of IsLadder,
        // not be swallowed by a collapse to a two-branch expression.
        $rec = ['Rank' => 3, 'IsLadder' => 1, 'AlreadyHas' => 1];
        $this->assertSame('already', $this->bucketFor($rec));

        $recFlat = ['Rank' => 0, 'IsLadder' => 0, 'AlreadyHas' => 1];
        $this->assertSame('already', $this->bucketFor($recFlat));
    }

    /**
     * Mirrors Kingdomnew_recommendations_panel.tpl's data-filter expression:
     *   !empty($rec['AlreadyHas']) ? 'already' : ((int)$rec['IsLadder'] === 1 ? 'below' : 'nonladder')
     */
    private function bucketFor(array $rec): string
    {
        if (!empty($rec['AlreadyHas'])) {
            return 'already';
        }

        return (int) $rec['IsLadder'] === 1 ? 'below' : 'nonladder';
    }
}
