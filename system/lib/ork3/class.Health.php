<?php

class Health extends Ork3
{
    /**
     * Liveness probe for load balancers (T-INF-01).
     */
    public function PingDb(): bool
    {
        try {
            $r = $this->db->query('SELECT 1 AS ok');

            return (bool) ($r && $r->size() > 0);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Probe an arbitrary PDO connection (used by characterization tests).
     */
    public static function PingPdo(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
