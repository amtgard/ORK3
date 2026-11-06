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
     * Permission key for an officer action family, resolved per scope.
     *
     * Exists so a controller never has to name system/lib/ork3's OfficerPosition
     * directly -- this model is the only membrane between the two layers.
     *
     * @param string $action one of set|vacate|position|history
     * @param int    $park_id 0 for kingdom scope, otherwise park scope
     * @return string
     */
    public function permission_key_for($action, $park_id): string
    {
        return OfficerPosition::PermissionKeyFor($action, (int)$park_id);
    }

    /**
     * Permission keys an officer position may never be granted (the writer rejects
     * them on save, so the builder must not offer them either).
     *
     * @return array
     */
    public function excluded_permission_keys(): array
    {
        return OfficerPosition::EXCLUDED_PERMISSION_KEYS;
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

    /**
     * The officer rolls of a kingdom ($park_id 0) or a park: one section per
     * office, terms newest first, gaps and overlaps flagged. Public data; feeds
     * both the rolls report and the edit ledgers.
     *
     * $only_key ('position:N' / 'role:X') limits it to that one office.
     *
     * @return array ['Offices' => [...]] -- see OfficerPosition::rolls_for_org()
     */
    public function rolls(int $kingdom_id, int $park_id, ?string $only_key = null): array
    {
        return $this->OfficerPosition->rolls_for_org($kingdom_id, $park_id, $only_key);
    }

    /**
     * Whether $uid may write officer history in this kingdom/park -- the same gate
     * Add/Edit/DeleteHistoryTerm enforce (OfficerPosition::can_edit_history_for).
     */
    public function can_edit_history_for(int $uid, int $kingdom_id, int $park_id): bool
    {
        return $this->OfficerPosition->can_edit_history_for($uid, $kingdom_id, $park_id);
    }

    /**
     * Each holder's latest term per office: "mundane_id|role" => history row.
     *
     * @return array see OfficerPosition::latest_term_by_holder()
     */
    public function latest_term_by_holder(int $kingdom_id, int $park_id): array
    {
        return $this->OfficerPosition->latest_term_by_holder($kingdom_id, $park_id);
    }

    /**
     * Read a typed period back for the ledger's period field (OfficerTermPeriod::readBack).
     *
     * @return array|string error message, or [Label, StartOrdinal, EndOrdinal, PreviousSpan]
     */
    public function read_back_period(string $text, bool $is_current = false): array|string
    {
        return OfficerTermPeriod::readBack($text, $is_current);
    }
}
