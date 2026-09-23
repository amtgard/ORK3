<?php

class Model_SessionToken extends Model
{
    public function validate_session_token(int $mundane_id, string $token): bool
    {
        return $this->_session_token()->ValidateSessionToken($mundane_id, $token);
    }

    private function _session_token(): SessionToken
    {
        return new SessionToken();
    }
}
