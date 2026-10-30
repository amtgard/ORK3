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
}
