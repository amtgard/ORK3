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
     */
    public function HasAuthority(array $request): array
    {
        $mundaneId = isset($request['MundaneId']) ? (int) $request['MundaneId'] : 0;
        $type = $request['Type'] ?? '';
        $id = array_key_exists('Id', $request) ? $request['Id'] : null;
        if ($id !== null && $id !== '') {
            $id = (int) $id;
        }
        $role = $request['Role'] ?? null;

        return [
            'Status' => Success(),
            'Authorized' => $this->check($mundaneId, $type, $id, $role),
        ];
    }
}
