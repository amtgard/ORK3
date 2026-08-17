<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization for P3-R2 Award catalogue maps (single source of truth).
 */
final class AwardCatalogueMapsTest extends TestCase
{
    public function testClassParagonMapMatchesFormerTemplateLiterals(): void
    {
        $expected = [
            1 => 37, 2 => 38, 3 => 39, 4 => 40, 5 => 41, 6 => 241, 7 => 42, 8 => 43,
            9 => 44, 10 => 45, 11 => 46, 12 => 47, 14 => 242, 15 => 49, 16 => 50, 17 => 51,
        ];

        $this->assertSame($expected, Award::GetClassParagonMap());
    }

    public function testKnightAwardMapMatchesFormerMilestoneBeltIds(): void
    {
        $expected = [
            17 => 'Flame',
            18 => 'Crown',
            19 => 'Serpent',
            20 => 'Sword',
            245 => 'Battle',
        ];

        $this->assertSame($expected, Award::GetKnightAwardMap());
    }

    public function testMasterAwardIdsFlattenLadderMasterMapIncludingWarlord(): void
    {
        $ids = Award::GetMasterAwardIds();
        $this->assertContains(1, $ids);
        $this->assertContains(12, $ids); // Warlord — from Order of the Warrior
        $this->assertContains(240, $ids);
        $this->assertContains(244, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));

        $fromMap = [];
        foreach (Award::GetLadderMasterMap() as $info) {
            foreach ($info['MasterAwardIds'] as $mid) {
                $fromMap[(int)$mid] = true;
            }
        }
        $this->assertSame(array_map('intval', array_keys($fromMap)), $ids);
    }

    public function testParagonAwardIdsMatchClassParagonValues(): void
    {
        $expected = array_values(array_unique(array_map('intval', array_values(Award::GetClassParagonMap()))));
        $this->assertSame($expected, Award::GetParagonAwardIds());
    }

    public function testLadderMasterMapIsSoleOrderToMasterSource(): void
    {
        $map = Award::GetLadderMasterMap();
        $this->assertArrayHasKey(21, $map);
        $this->assertSame([1], $map[21]['MasterAwardIds']);
        $this->assertSame(12, $map[30]['MaxRank']);
        $this->assertSame(10, $map[21]['MaxRank']);
        $this->assertSame([12], $map[27]['MasterAwardIds']);
    }
}
