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
}
