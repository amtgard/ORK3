<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\IdNamespace;
use PHPUnit\Framework\TestCase;

final class IdNamespaceTest extends TestCase
{
    public function testConstantsMatchTd11Namespace(): void
    {
        $this->assertSame(100001, IdNamespace::KINGDOM_ID_MIN);
        $this->assertSame(100005, IdNamespace::KINGDOM_ID_MAX);
        $this->assertSame(1_000_000, IdNamespace::PARK_ID_BASE);
        $this->assertSame(100_000_000, IdNamespace::FAKE_MUNDANE_ID_START);
    }

    public function testParkIdFormulaMatchesTd11Spec(): void
    {
        $this->assertSame(1_000_001, IdNamespace::parkId(0, 1));
        $this->assertSame(1_000_004, IdNamespace::parkId(0, 4));
        $this->assertSame(1_000_101, IdNamespace::parkId(1, 1));
        $this->assertSame(1_000_403, IdNamespace::parkId(4, 3));
    }

    public function testKingdomIdRangeSql(): void
    {
        $this->assertSame('100001 AND 100005', IdNamespace::kingdomIdRangeSql());
    }

    public function testSeed42ParkLayoutUsesHighNamespace(): void
    {
        $render = new \OrkDb\Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $parks = $render->parkLayoutForSeed(42);

        $this->assertCount(20, $parks);
        $this->assertSame(1_000_001, $parks[0]['park_id']);
        $this->assertSame(100001, $parks[0]['kingdom_id']);
        $this->assertSame(1_000_403, $parks[19]['park_id']);
        $this->assertSame(100005, $parks[19]['kingdom_id']);
    }

    public function testSeed42FakeMundaneIdsStartAtOneHundredMillion(): void
    {
        $render = new \OrkDb\Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $ids = $render->fakeMundaneIdsForSeed(42);

        $this->assertNotEmpty($ids);
        $this->assertSame(100_000_000, $ids[0]);
        $this->assertSame(100_000_000 + count($ids) - 1, $ids[array_key_last($ids)]);
    }

    public function testSeed42FakeMundaneHeraldryIdsAreThirtyPercentSample(): void
    {
        $render = new \OrkDb\Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $allIds = $render->fakeMundaneIdsForSeed(42);
        $heraldryIds = $render->fakeMundaneHeraldryIdsForSeed(42);

        $this->assertNotEmpty($heraldryIds);
        $this->assertLessThan(count($allIds), count($heraldryIds));
        foreach ($heraldryIds as $id) {
            $this->assertContains($id, $allIds);
            $this->assertGreaterThanOrEqual(100_000_000, $id);
        }
        $this->assertSame(30, IdNamespace::FAKE_PLAYER_HERALDRY_PERCENT);
        $this->assertSame('000000', IdNamespace::PLAYER_HERALDRY_DEFAULT_BASENAME);
    }
}
