<?php

/**
 * Live dashboard JSON service (T-LIB-01).
 */
class LiveService extends Ork3
{
    public function GetStats(): array
    {
        $live = new Live();

        return $live->stats();
    }

    public function GetRecent(): array
    {
        $live = new Live();

        return $live->recent();
    }
}
