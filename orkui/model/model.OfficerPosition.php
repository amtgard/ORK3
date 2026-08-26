<?php

/***
 * Model_OfficerPosition
 *
 * Thin passthrough to the OfficerPosition DB layer (system/lib/ork3/class.OfficerPosition.php).
 * The base Model constructor auto-wires $this->OfficerPosition = new APIModel('OfficerPosition'),
 * and Model::__call() forwards any unhandled method to it — so this model is a pure
 * passthrough per the architecture-layers rule (no DB logic here; presentation
 * transforms only if/when a controller needs reshaped data).
 ***/

class Model_OfficerPosition extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Role options for the officer-HISTORY selects: canonical key => display title.
     *
     * Sourced from the position REGISTRY, not from current occupants. History exists to
     * record who held an office in the past, so a position that is vacant right now must
     * still be selectable -- keying off the occupant list made the control empty for any
     * scope with no sitting officers, which is exactly when backfill is most needed.
     * Retired positions are included for the same reason.
     *
     * Falls back to the Core Five when the registry is unavailable (a database that has
     * not run the officer-position migration yet), so the control is never empty.
     *
     * @param int $kingdom_id
     * @return array  Ordered map of canonical key => display title.
     */
    public function history_role_options($kingdom_id): array
    {
        $options = [];
        $rows = $this->OfficerPosition->GetPositions((int)$kingdom_id, true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $key = $row['CanonicalKey'] ?? '';
                if ($key === '' || isset($options[$key])) {
                    continue;
                }
                $options[$key] = $row['DisplayTitle'] ?? $row['Title'] ?? $key;
            }
        }
        if (empty($options)) {
            foreach (PermissionRegistry::GetOfficerRoleMap() as $label => $key) {
                $options[$key] = $label;
            }
        }
        return $options;
    }

}
