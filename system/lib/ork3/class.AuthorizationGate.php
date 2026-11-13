<?php

/**
 * Committable HasAuthority facade (class.Authorization.php is local-only per pre-commit hook).
 */
class AuthorizationGate extends Ork3
{
    public function check(int $mundaneId, string $type, $id, ?string $role): bool
    {
        $auth = new Authorization();

        return (bool) $auth->HasAuthority($mundaneId, $type, $id, $role);
    }

    /**
     * JSON/SOAP API: HasAuthority request → { Status, Authorized }.
     * Actor is resolved from Token; client MundaneId is ignored (privilege-oracle fix).
     */
    public function HasAuthority(array $request): array
    {
        $actorId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($actorId <= 0) {
            return array_merge(BadToken(), ['Authorized' => false]);
        }

        $type = $request['Type'] ?? '';
        $id = array_key_exists('Id', $request) ? $request['Id'] : null;
        if ($id !== null && $id !== '') {
            $id = (int) $id;
        }
        $role = $request['Role'] ?? null;

        return [
            'Status' => Success(),
            'Authorized' => $this->check($actorId, $type, $id, $role),
        ];
    }

    /**
     * True when the user holds at least one kingdom-scoped authorization row.
     * Deliberately a bare existence probe (not HasAuthority) — no role/scope
     * traversal, just "does this account carry any kingdom-level grant at all".
     */
    public function HasAnyKingdomAuthorization(int $mundaneId): bool
    {
        $uid = (int) $mundaneId;
        $this->db->Clear();
        $r = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . "authorization WHERE mundane_id = {$uid} AND kingdom_id > 0 LIMIT 1"
        );

        return (bool) ($r && $r->Size() > 0);
    }

    /**
     * HasAuthority / auth ORM share the global DB connection. Clear after nav
     * auth checks so subclass controller actions start with a clean DB state.
     */
    public function clearSharedDb(): void
    {
        global $DB;
        $DB->Clear();
    }
}
