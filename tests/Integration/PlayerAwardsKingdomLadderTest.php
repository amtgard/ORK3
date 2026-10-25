<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Per-process stand-in for memcached so PlayerAwards' 60s memo cannot leak between tests. */
final class PlayerAwardsInMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get($call, $key, $lifetime)
    {
        $fullKey = $this->prefix . '.' . $call . '.' . $key;
        $this->lifetime[$fullKey] = $lifetime;

        return $this->store[$fullKey] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $this->store[$this->prefix . '.' . $call . '.' . $key] = $content;

        return $content;
    }

    public function bust($call, $key): void
    {
        unset($this->store[$this->prefix . '.' . $call . '.' . $key]);
    }
}

/**
 * The Player Awards report (Report::PlayerAwards, behind Reports/player_awards)
 * must list grants of KINGDOM-ORIGINAL ladders -- Order of the Sharpshooter,
 * Hunter, Raider and friends -- exactly as it lists catalog ladders.
 *
 * Those awards carry no ladder flag anywhere: their ork_kingdomaward row points
 * at award 94 "Custom Award" (is_ladder 0) or at no catalog row at all, and are
 * recognised only by Award::pseudoLadderKingdomAwardIds(). The report used to
 * test the catalog flag alone, so a Lichwood Grove officer entering two
 * Sharpshooters in October 2026 could not find them (while the ladder grid and
 * recommendations, which already use Award::sqlEffectiveIsLadder, could).
 *
 * The sandbox has none of the curated production ids, so the test creates a
 * kingdom award UNDER one of them (7055, Nine Blades' Sharpshooter) on award 94.
 */
final class PlayerAwardsKingdomLadderTest extends TestCase
{
    private const SHARPSHOOTER_KA_ID = 7055;
    private const CUSTOM_AWARD_ID    = 94;

    private ReportsFixture $fixture;
    private Ghettocache $originalCache;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->assertContains(self::SHARPSHOOTER_KA_ID, Award::pseudoLadderKingdomAwardIds(), 'test relies on 7055 being a curated kingdom-original ladder');
        $this->fixture = ReportsFixture::create();
        // PlayerAwards memoises per request for 60s; keep each test's query honest.
        $this->originalCache = Ork3::$Lib->ghettocache;
        Ork3::$Lib->ghettocache = new PlayerAwardsInMemoryGhettocache();
    }

    protected function tearDown(): void
    {
        Ork3::$Lib->ghettocache = $this->originalCache;
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testKingdomOriginalLadderGrantsAppearWithTheirRank(): void
    {
        $kid    = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $kaId   = $this->fixture->insertKingdomAwardWithId(self::SHARPSHOOTER_KA_ID, $kid, self::CUSTOM_AWARD_ID, 'Order of the Sharpshooter');

        $archer = $this->fixture->createPlayer($parkId, 'sharpshooter');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ' . (int) $archer['mundane_id']);
        $grantId = $this->fixture->insertLadderAward($archer['mundane_id'], $parkId, $kid, $kaId, self::CUSTOM_AWARD_ID, 4);

        unset($_SESSION['is_authorized_mundane_id']);
        $report = (new Report())->PlayerAwards([
            'KingdomId' => 0, 'ParkId' => $parkId,
            'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0,
        ]);
        $this->assertSame(0, $report['Status']['Status']);

        $rows = array_values(array_filter($report['Awards'] ?? [], static fn (array $a): bool => (int) $a['MundaneId'] === (int) $archer['mundane_id']));
        $this->assertCount(1, $rows, 'the Sharpshooter grant is listed once for the player');
        $this->assertSame('Order of the Sharpshooter', $rows[0]['AwardName']);
        $this->assertSame(4, (int) $rows[0]['Rank']);
    }

    public function testLadderMinimumStillAppliesToKingdomOriginalLadders(): void
    {
        $kid    = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $kaId   = $this->fixture->insertKingdomAwardWithId(self::SHARPSHOOTER_KA_ID, $kid, self::CUSTOM_AWARD_ID, 'Order of the Sharpshooter');

        $novice = $this->fixture->createPlayer($parkId, 'novice');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ' . (int) $novice['mundane_id']);
        $this->fixture->insertLadderAward($novice['mundane_id'], $parkId, $kid, $kaId, self::CUSTOM_AWARD_ID, 1);

        unset($_SESSION['is_authorized_mundane_id']);
        $report = (new Report())->PlayerAwards([
            'KingdomId' => 0, 'ParkId' => $parkId,
            'IncludeKnights' => 0, 'IncludeMasters' => 0, 'IncludeLadder' => 1, 'LadderMinimum' => 7,
        ]);
        $ids = array_map(static fn (array $a): int => (int) $a['MundaneId'], $report['Awards'] ?? []);
        $this->assertNotContains((int) $novice['mundane_id'], $ids, 'a rank-1 grant stays hidden behind LadderMinimum 7, as for any ladder');
    }

    public function testCatalogFlaggedLadderStillListedUnchanged(): void
    {
        $kid    = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $ladder = $this->fixture->firstLadderAward($kid);

        $player = $this->fixture->createPlayer($parkId, 'catalog-ladder');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ' . (int) $player['mundane_id']);
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 2);

        unset($_SESSION['is_authorized_mundane_id']);
        $report = (new Report())->PlayerAwards([
            'KingdomId' => 0, 'ParkId' => $parkId,
            'IncludeKnights' => 0, 'IncludeMasters' => 0, 'IncludeLadder' => 1, 'LadderMinimum' => 0,
        ]);
        $ids = array_map(static fn (array $a): int => (int) $a['MundaneId'], $report['Awards'] ?? []);
        $this->assertContains((int) $player['mundane_id'], $ids, 'ordinary catalog ladders are unaffected');
    }
}
