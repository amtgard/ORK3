<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for shared class level helper (T-SIN-04).
 */
final class ClassLevelTest extends TestCase
{
    public function testLevelBoundaries(): void
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
            99.0 => 6,
        ];

        foreach ($cases as $credits => $expectedLevel) {
            $result = ClassLevel::computeClassLevel($credits);
            $this->assertSame(
                $expectedLevel,
                $result['Level'],
                'Credits ' . $credits . ' should be level ' . $expectedLevel,
            );
        }
    }

    public function testCreditsToNext(): void
    {
        $this->assertSame(5.0, ClassLevel::computeClassLevel(0.0)['ToNext']);
        $this->assertSame(7.0, ClassLevel::computeClassLevel(5.0)['ToNext']);
        $this->assertSame(1.0, ClassLevel::computeClassLevel(11.0)['ToNext']);
        $this->assertSame(9.0, ClassLevel::computeClassLevel(12.0)['ToNext']);
        $this->assertSame(13.0, ClassLevel::computeClassLevel(21.0)['ToNext']);
        $this->assertSame(19.0, ClassLevel::computeClassLevel(34.0)['ToNext']);
        $this->assertNull(ClassLevel::computeClassLevel(53.0)['ToNext']);
        $this->assertNull(ClassLevel::computeClassLevel(100.0)['ToNext']);
    }
}
