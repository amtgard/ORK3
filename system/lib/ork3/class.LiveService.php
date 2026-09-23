<?php

/**
 * Live dashboard JSON service (T-LIB-01).
 */
class LiveService extends Ork3
{
    // PUBLIC (opened 2026-08-23, Ken's call): the Live page no longer requires
    // login. Both feeds are park-level aggregates with mundane_id deliberately
    // stripped before the wire (class.Live), and both sit behind short
    // GhettoCache TTLs (~30s/~10s) that bound origin load for any viewer count.
    // $Token retained for caller compatibility; unused.
    public function GetStats($Token = null): array
    {
        $live = new Live();

        return $live->stats();
    }

    public function GetRecent($Token = null): array
    {
        $live = new Live();

        return $live->recent();
    }
}
