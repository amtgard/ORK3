<?php

class Model_Authorization extends Model
{
    public function __construct()
    {
        parent::__construct($call, $method);
        $this->Authorization = new APIModel('Authorization');
    }

    public function index()
    {

    }

    public function add_auth($request)
    {
        return $this->Authorization->AddAuthorization($request);
    }

    public function del_auth($request)
    {
        return $this->Authorization->RemoveAuthorization($request);
    }

    public function has_authority(int $uid, string $type, $id, ?string $role): bool
    {
        $gate = new AuthorizationGate();

        return $gate->check($uid, $type, $id, $role);
    }
}
