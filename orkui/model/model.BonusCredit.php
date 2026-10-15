<?php

/**
 * Thin pass-through to the BonusCredit domain class
 * (system/lib/ork3/class.BonusCredit.php): bonus credits, the class credits
 * that are not attendance, and the reasons they are granted for.
 *
 * No SQL and no business logic here; every method forwards unchanged. The
 * domain takes the acting user's id, which the controller reads from the
 * session.
 */
class Model_BonusCredit extends Model
{
    public function reasons(string $scopeType, int $scopeId, bool $includeArchived = false): array
    {
        return $this->_bonus()->reasons($scopeType, $scopeId, $includeArchived);
    }

    public function reason(int $reasonId): ?array
    {
        return $this->_bonus()->reason($reasonId);
    }

    public function create_reason(int $uid, array $fields): array
    {
        return $this->_bonus()->createReason($uid, $fields);
    }

    public function archive_reason(int $uid, int $reasonId): array
    {
        return $this->_bonus()->archiveReason($uid, $reasonId);
    }

    public function restore_reason(int $uid, int $reasonId): array
    {
        return $this->_bonus()->restoreReason($uid, $reasonId);
    }

    public function grant(int $uid, array $fields): array
    {
        return $this->_bonus()->grant($uid, $fields);
    }

    public function revoke(int $uid, int $bonusCreditId, string $note = ''): array
    {
        return $this->_bonus()->revoke($uid, $bonusCreditId, $note);
    }

    public function for_player(int $mundaneId, bool $includeRevoked = false): array
    {
        return $this->_bonus()->forPlayer($mundaneId, $includeRevoked);
    }

    public function for_reason(int $reasonId, int $parkId = 0, int $limit = 200, int $kingdomId = 0): array
    {
        return $this->_bonus()->forReason($reasonId, $parkId, $limit, $kingdomId);
    }

    public function is_ork_admin(int $uid): bool
    {
        return $this->_bonus()->isOrkAdmin($uid);
    }

    public function reasons_for_kingdom(int $kingdomId, bool $includeArchived = false): array
    {
        return $this->_bonus()->reasonsForKingdom($kingdomId, $includeArchived);
    }

    public function ork_credits_for(int $kingdomId): array
    {
        return $this->_bonus()->orkCreditsFor($kingdomId);
    }

    public function adoptions(int $reasonId): array
    {
        return $this->_bonus()->adoptions($reasonId);
    }

    public function adopt(int $uid, int $reasonId, int $kingdomId): array
    {
        return $this->_bonus()->adopt($uid, $reasonId, $kingdomId);
    }

    public function withdraw(int $uid, int $reasonId, int $kingdomId): array
    {
        return $this->_bonus()->withdraw($uid, $reasonId, $kingdomId);
    }

    public function reasons_for_park(int $parkId): array
    {
        return $this->_bonus()->reasonsForPark($parkId);
    }

    public function park_kingdom(int $parkId): int
    {
        return $this->_bonus()->parkKingdom($parkId);
    }

    public function kingdom_ancestry(int $kingdomId): array
    {
        return $this->_bonus()->kingdomAncestry($kingdomId);
    }

    /** May $uid define credits for a kingdom? */
    public function can_define(int $uid, string $scopeType, int $scopeId): bool
    {
        return $this->_bonus()->canDefine($uid, $scopeType, $scopeId);
    }

    /** May $uid hand out credits anywhere in a kingdom? */
    public function can_grant_in_kingdom(int $uid, int $kingdomId): bool
    {
        return $this->_bonus()->canGrantInKingdom($uid, $kingdomId);
    }

    /** May $uid hand out credits to a park's players? */
    public function can_grant(int $uid, int $parkId): bool
    {
        return $this->_bonus()->canGrant($uid, $parkId);
    }

    public function can_appoint(int $uid, int $kingdomId): bool
    {
        return $this->_bonus()->canAppoint($uid, $kingdomId);
    }

    public function granters(int $kingdomId): array
    {
        return $this->_bonus()->granters($kingdomId);
    }

    public function add_granter(int $uid, int $kingdomId, int $mundaneId, int $reasonId = 0): array
    {
        return $this->_bonus()->addGranter($uid, $kingdomId, $mundaneId, $reasonId);
    }

    public function remove_granter(int $uid, int $kingdomId, int $granterId): array
    {
        return $this->_bonus()->removeGranter($uid, $kingdomId, $granterId);
    }

    public function grantable_reasons_for_park(int $uid, int $parkId): array
    {
        return $this->_bonus()->grantableReasonsForPark($uid, $parkId);
    }

    public function grantable_reasons_in_kingdom(int $uid, int $kingdomId, bool $includeArchived = false): array
    {
        return $this->_bonus()->grantableReasonsInKingdom($uid, $kingdomId, $includeArchived);
    }

    private function _bonus(): BonusCredit
    {
        return new BonusCredit();
    }
}
