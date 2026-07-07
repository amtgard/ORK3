<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for SignIn class level thresholds (T-SIN-04).
 *
 * Pre-refactor logic lives in controller.SignIn.php; R-12 will extract a shared helper.
 */
final class ClassLevelTest extends TestCase
{
    /** @return array{Level: int, ToNext: ?float} */
    private function mirrorSignInClassLevel(float $credits): array
    {
        $levelThresholds = [5, 12, 21, 34, 53];
        $level = 1;
        if ($credits >= 53) {
            $level = 6;
        } elseif ($credits >= 34) {
            $level = 5;
        } elseif ($credits >= 21) {
            $level = 4;
        } elseif ($credits >= 12) {
            $level = 3;
        } elseif ($credits >= 5) {
            $level = 2;
        }

        $toNext = null;
        if ($level < 6) {
            $toNext = max(0, $levelThresholds[$level - 1] - $credits);
        }

        return ['Level' => $level, 'ToNext' => $toNext];
    }

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
            $result = $this->mirrorSignInClassLevel($credits);
            $this->assertSame(
                $expectedLevel,
                $result['Level'],
                'Credits ' . $credits . ' should be level ' . $expectedLevel,
            );
        }
    }

    public function testCreditsToNext(): void
    {
        $this->assertSame(5.0, $this->mirrorSignInClassLevel(0.0)['ToNext']);
        $this->assertSame(7.0, $this->mirrorSignInClassLevel(5.0)['ToNext']);
        $this->assertSame(1.0, $this->mirrorSignInClassLevel(11.0)['ToNext']);
        $this->assertSame(9.0, $this->mirrorSignInClassLevel(12.0)['ToNext']);
        $this->assertSame(13.0, $this->mirrorSignInClassLevel(21.0)['ToNext']);
        $this->assertSame(19.0, $this->mirrorSignInClassLevel(34.0)['ToNext']);
        $this->assertNull($this->mirrorSignInClassLevel(53.0)['ToNext']);
        $this->assertNull($this->mirrorSignInClassLevel(100.0)['ToNext']);
    }
}
