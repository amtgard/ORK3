<?php

declare(strict_types=1);

namespace OrkDb;

final class IdNamespace
{
    public const KINGDOM_ID_MIN = 100001;
    public const KINGDOM_ID_MAX = 100005;
    public const PARK_ID_BASE = 1_000_000;
    public const FAKE_MUNDANE_ID_START = 100_000_000;
    public const FAKE_PLAYER_HERALDRY_PERCENT = 30;
    public const PLAYER_HERALDRY_DEFAULT_BASENAME = '000000';

    public static function parkId(int $kingdomOrdinal, int $seq): int
    {
        return self::PARK_ID_BASE + ($kingdomOrdinal * 100) + $seq;
    }

    public static function kingdomIdRangeSql(): string
    {
        return self::KINGDOM_ID_MIN . ' AND ' . self::KINGDOM_ID_MAX;
    }
}
