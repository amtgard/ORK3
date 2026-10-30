<?php

/**
 * Legend: the In Memoriam status of a deceased player.
 *
 * A Legend is an ork_mundane row with is_legend = 1 and the year they passed
 * in legend_year. The record and its history stay; the page becomes a
 * memorial and the account is closed to edits by anyone but the people who may
 * manage the status: an officer of the player's kingdom (or of a kingdom above
 * it, for a principality player) or an ORK admin. This class holds the status
 * checks every guard shares; it is deliberately small and static-friendly so a
 * guard in any layer can ask "is this player a Legend, and may this caller
 * edit them anyway?" in one call.
 *
 * Not registered on Ork3::$Lib on purpose: the JSON gateway exposes every
 * public method of a $Lib class, and these take a caller uid, not a token.
 * Callers reach it through Model_Legend.
 */
class Legend
{
    /** The one refusal message every guard uses. */
    public const REFUSED = 'This player is a Legend and can no longer be changed this way.';

    /** Nothing in the ORK predates this. */
    public const MIN_YEAR = 1983;

    /** @var array<int,bool> per-request memo of isLegend() */
    private static $memo = [];

    /** Is this player a Legend? Memoised for the request; clearMemo() after a change. */
    public static function isLegend(int $mundaneId): bool
    {
        if ($mundaneId <= 0) {
            return false;
        }
        if (!array_key_exists($mundaneId, self::$memo)) {
            global $DB;
            $DB->Clear();
            $rs = $DB->DataSet('SELECT is_legend FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId);
            self::$memo[$mundaneId] = ($rs && $rs->Next()) ? ((int) $rs->is_legend === 1) : false;
        }
        return self::$memo[$mundaneId];
    }

    /** Forget every memoised answer; call after converting, undoing or in tests. */
    public static function clearMemo(): void
    {
        self::$memo = [];
    }

    /** A global ORK admin: an unscoped admin authorization row. */
    public static function isOrkAdmin(int $uid): bool
    {
        return $uid > 0 && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
    }

    /**
     * May $uid manage this player's Legend status (and edit a Legend)? EDIT on
     * the player's kingdom, which a parent kingdom's officer holds for a
     * principality, or an ORK admin; as in Player::SetPlayerSuspension. A park
     * officer does not qualify unless also a kingdom officer.
     */
    public static function mayManage(int $uid, int $mundaneId): bool
    {
        if ($uid <= 0 || $mundaneId <= 0) {
            return false;
        }
        if (self::isOrkAdmin($uid)) {
            return true;
        }
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId);
        if (!$rs || !$rs->Next()) {
            return false;
        }
        $kingdomId = (int) $rs->kingdom_id;
        return valid_id($kingdomId)
            && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_EDIT);
    }

    /** Must a guard refuse $uid's edit of this player? True for a Legend unless $uid may manage them. */
    public static function blocksEdit(int $uid, int $mundaneId): bool
    {
        return self::isLegend($mundaneId) && !self::mayManage($uid, $mundaneId);
    }
}
