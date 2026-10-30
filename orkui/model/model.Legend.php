<?php

/**
 * Thin pass-through to the Legend domain class
 * (system/lib/ork3/class.Legend.php): the In Memoriam status of a deceased
 * player.
 *
 * No SQL and no business logic here; every method forwards unchanged. The
 * domain takes the acting user's id, which the controller reads from the
 * session.
 */
class Model_Legend extends Model
{
    public function is_legend(int $mundaneId): bool
    {
        return Legend::isLegend($mundaneId);
    }

    public function clear_memo(): void
    {
        Legend::clearMemo();
    }

    public function may_manage(int $uid, int $mundaneId): bool
    {
        return Legend::mayManage($uid, $mundaneId);
    }

    public function is_ork_admin(int $uid): bool
    {
        return Legend::isOrkAdmin($uid);
    }

    public function blocks_edit(int $uid, int $mundaneId): bool
    {
        return Legend::blocksEdit($uid, $mundaneId);
    }

    public function parse_year($raw): int
    {
        return Legend::parseYear($raw);
    }

    public function preview(int $uid, int $mundaneId): array
    {
        return Legend::preview($uid, $mundaneId);
    }

    public function convert(int $uid, int $mundaneId, int $year): array
    {
        return Legend::convert($uid, $mundaneId, $year);
    }

    public function undo(int $uid, int $mundaneId): array
    {
        return Legend::undo($uid, $mundaneId);
    }

    public function set_year(int $uid, int $mundaneId, int $year): array
    {
        return Legend::setYear($uid, $mundaneId, $year);
    }

    public function memories(int $mundaneId): array
    {
        return Legend::memories($mundaneId);
    }

    public function add_memory(int $uid, int $mundaneId, string $body): array
    {
        return Legend::addMemory($uid, $mundaneId, $body);
    }

    public function remove_memory(int $uid, int $memoryId): array
    {
        return Legend::removeMemory($uid, $memoryId);
    }
}
