<?php

class Model_Authorization extends Model
{
    public function __construct()
    {
        parent::__construct();
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
        return $this->_authorization_gate()->check($uid, $type, $id, $role);
    }

    /**
     * Resolve a session token to its owning mundane_id, or 0 when the token is
     * unknown, expired, or the wrong length.
     *
     * NOT a pure read: on a session older than 60s the lib also slides
     * last_seen/expires forward, which is what keeps an active tab signed in.
     *
     * @param  string $token
     * @return int    owning mundane_id, or 0
     */
    public function validate_session_by_token($token): int
    {
        return (int) $this->Authorization->ValidateSessionByToken($token);
    }

    /**
     * The caller's live sessions, most-recently-active first, for the
     * logout-everywhere dialog. The presented token must itself be live; an
     * unknown or expired one yields an empty list rather than an error.
     *
     * @param  string $token
     * @return array<int, array{session_id:int, created:string, last_seen:string, user_agent:string, ip:string, current:bool}>
     */
    public function list_sessions_for_token($token): array
    {
        return (array) $this->Authorization->ListSessionsForToken($token);
    }

    /**
     * First-class audit write for auth / staff mutations (replaces inline new Dangeraudit()).
     */
    public function audit($call, $parameters, $entity, $entity_id, $prior_state = null, $post_state = null)
    {
        return $this->_dangeraudit()->audit($call, $parameters, $entity, $entity_id, $prior_state, $post_state);
    }

    /**
     * HasAuthority / auth ORM shares the global DB connection — clear after nav auth
     * checks so subclass actions start clean.
     */
    public function clear_db_after_auth_checks(): void
    {
        $this->_authorization_gate()->clearSharedDb();
    }

    private function _authorization_gate(): AuthorizationGate
    {
        return new AuthorizationGate();
    }

    private function _dangeraudit(): Dangeraudit
    {
        return new Dangeraudit();
    }
}
