<?php

/**
 * ork_officer — the officer vocabulary and the PUBLIC officer projection.
 *
 * ork_officer.role is ONE vocabulary across both org scopes: a park's Monarch and
 * a kingdom's Monarch are the same enum value in the same column. The public
 * projection of an officer roster is likewise identical at both scopes. Both used
 * to live on Kingdom, which forced Park to reach into Kingdom for PARK roles and
 * made the park side look like a borrowed special case. They live here instead, so
 * neither scope owns the other's vocabulary.
 *
 * Kingdom::GetPublicOfficers() and Park::GetPublicOfficers() are thin adapters over
 * PublicRoster() — they stay because they are the model membrane's entry points and
 * the front-door officer blocks call them by name.
 */
class Officer extends Ork3
{
    /**
     * Canonical ORK officer-role vocabulary → display label, keyed lowercase.
     *
     * Matched case-insensitively on purpose: prod stores the capitalized ENUM
     * roles, the shared local DB was migrated to lowercase canonical keys. This
     * is the same set the GetOfficers FIELD() sort orders by, at park level and
     * at kingdom level alike.
     *
     * @var array<string,string>
     */
    public const PUBLIC_OFFICER_ROLE_LABELS = array(
        'monarch'        => 'Monarch',
        'regent'         => 'Regent',
        'prime minister' => 'Prime Minister',
        'champion'       => 'Champion',
        'gmr'            => 'GMR',
    );

    /**
     * Shared PUBLIC projection of an officer roster — kingdom seats or park seats.
     *
     * Owns the public-visibility policy that the front-door officer blocks used to
     * carry inline in a template:
     *   - VACANCY: a seat with no persona is dropped. ork_officer keeps a row per
     *     office with mundane_id = 0 when nobody holds it, so the LEFT JOIN to
     *     ork_mundane yields a NULL persona while `role` stays populated
     *     ("Champion", "GMR", …). A name is required; an office title is not.
     *   - RESTRICTED: a restricted player keeps their persona (their public ORK
     *     identity, shown on every other ORK surface) but their PERSONAL heraldry
     *     is never published to anonymous viewers.
     *   - role → display label from PUBLIC_OFFICER_ROLE_LABELS.
     *   - the avatar URL is resolved here, so callers never touch Heraldry.
     * The limit is applied to the seats that actually render, after vacancies are
     * dropped, so "show N officers" means N cards.
     *
     * @param array $officers rows as returned by Kingdom/Park::GetOfficers()
     * @param int   $limit    max seats to return (<= 0 → no limit)
     * @return array list of array{persona:string,role:string,mundane_id:int,avatar:string}
     */
    public static function ProjectPublicOfficers($officers, $limit = 0)
    {
        $limit    = (int)$limit;
        $resolved = array();
        if (!is_array($officers)) {
            return $resolved;
        }
        foreach ($officers as $officer) {
            if (!is_array($officer)) {
                continue;
            }
            if ($limit > 0 && count($resolved) >= $limit) {
                break;
            }
            $persona = trim((string)($officer['Persona'] ?? ''));
            if ('' === $persona) {
                continue;
            }
            $role       = trim((string)($officer['OfficerRole'] ?? $officer['Role'] ?? ''));
            $role_key   = strtolower($role);
            $mundane_id = (int)($officer['MundaneId'] ?? 0);
            $restricted = 1 === (int)($officer['Restricted'] ?? 0);
            $avatar     = '';
            if (!$restricted && $mundane_id > 0 && isset(Ork3::$Lib->heraldry)) {
                $h = Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type' => 'Player', 'Id' => $mundane_id));
                if (is_array($h) && !empty($h['Url'])) {
                    $avatar = (string)$h['Url'];
                }
            }
            $resolved[] = array(
                'persona'    => $persona,
                'role'       => self::PUBLIC_OFFICER_ROLE_LABELS[$role_key] ?? $role,
                'mundane_id' => $mundane_id,
                'avatar'     => $avatar,
            );
        }
        return $resolved;
    }

    /**
     * PUBLIC (anonymous) officer roster for ONE org, ready to render.
     *
     * The anonymous projection is chosen HERE, not by the caller: no Token is
     * accepted, the scope's GetOfficers is asked for the unauthenticated view
     * (real given and surnames suppressed, only Persona exposed), and
     * ProjectPublicOfficers applies the vacancy, restricted, role-label and
     * avatar policy.
     *
     * @param string $scope_type 'kingdom' | 'park'
     * @param int    $org_id     kingdom_id or park_id
     * @param int    $limit      max seats to return (<= 0 → no limit)
     * @return array list of array{persona:string,role:string,mundane_id:int,avatar:string}
     */
    public static function PublicRoster($scope_type, $org_id, $limit = 0)
    {
        $org_id = (int)$org_id;
        if ($org_id <= 0) {
            return array();
        }
        switch (strtolower(trim((string)$scope_type))) {
            case 'kingdom':
                $r = Ork3::$Lib->kingdom->GetOfficers(array('KingdomId' => $org_id, 'Token' => ''));
                break;
            case 'park':
                $r = Ork3::$Lib->park->GetOfficers(array('ParkId' => $org_id, 'Token' => ''));
                break;
            default:
                return array();
        }
        $officers = (is_array($r) && isset($r['Officers']) && is_array($r['Officers'])) ? $r['Officers'] : array();
        return self::ProjectPublicOfficers($officers, (int)$limit);
    }
}
