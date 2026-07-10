<?php

/**
 * Shared class level thresholds and calculator (T-SIN-04).
 */
class ClassLevel
{
    /** @var list<float> Credits required to reach Level N+1 from Level N. */
    public const THRESHOLDS = [5, 12, 21, 34, 53];

    /**
     * @return array{Level: int, ToNext: ?float}
     */
    public static function computeClassLevel(float $credits): array
    {
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
            $toNext = max(0, self::THRESHOLDS[$level - 1] - $credits);
        }

        return ['Level' => $level, 'ToNext' => $toNext];
    }
}
