<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization for P3-R1 highest-class-level helpers.
 */
final class PlayerHighestClassLevelTest extends TestCase
{
    public function testHighestClassLevelFromClassesMatchesFormerControllerLoop(): void
    {
        $classes = [
            ['Credits' => 4, 'Reconciled' => 0],
            ['Credits' => 10, 'Reconciled' => 2],
            ['Credits' => 20, 'Reconciled' => 1],
            ['Credits' => 0, 'Reconciled' => 53],
        ];

        $this->assertSame(6, Player::HighestClassLevelFromClasses($classes));
    }

    public function testHighestClassLevelFromClassesEmptyIsZero(): void
    {
        $this->assertSame(0, Player::HighestClassLevelFromClasses([]));
    }

    public function testHighestClassLevelFromClassesLevelBoundaries(): void
    {
        $cases = [
            0.0 => 1,
            4.9 => 1,
            5.0 => 2,
            11.9 => 2,
            12.0 => 3,
            20.9 => 3,
            21.0 => 4,
            33.9 => 4,
            34.0 => 5,
            52.9 => 5,
            53.0 => 6,
        ];

        foreach ($cases as $credits => $expectedLevel) {
            $this->assertSame(
                $expectedLevel,
                Player::HighestClassLevelFromClasses([
                    ['Credits' => $credits, 'Reconciled' => 0],
                ]),
                'Credits ' . $credits . ' should yield highest level ' . $expectedLevel,
            );
        }
    }

    public function testHighestClassLevelFromClassesAddsReconciled(): void
    {
        $this->assertSame(
            3,
            Player::HighestClassLevelFromClasses([
                ['Credits' => 10, 'Reconciled' => 2],
            ]),
        );
    }
}
