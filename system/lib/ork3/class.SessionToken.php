<?php

class SessionToken extends Ork3
{
    /**
     * Compare session token to the current DB token (T-INF-03).
     */
    public function ValidateSessionToken(int $mundaneId, string $token): bool
    {
        if (!valid_id($mundaneId) || $token === '') {
            return false;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT token FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' LIMIT 1'
        );
        // $rs === false means the query itself failed (transient DB error). Report the
        // session as still valid in that case: a DB blip must not log every user out.
        if ($rs === false) {
            return true;
        }
        if (!$rs || !$rs->Next()) {
            return false;
        }

        return (string) $rs->token === $token;
    }
}
