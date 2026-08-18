<?php

/**
 * Live dashboard JSON service (T-LIB-01).
 */
class LiveService extends Ork3
{
    public function GetStats($Token = null): array
    {
        if (Ork3::$Lib->authorization->IsAuthorized($Token ?? '') <= 0) {
            return array_merge(BadToken(), ['now' => null, 'parks' => [], 'events' => [], 'active_3h' => 0]);
        }

        $live = new Live();

        return $live->stats();
    }

    public function GetRecent($Token = null): array
    {
        if (Ork3::$Lib->authorization->IsAuthorized($Token ?? '') <= 0) {
            return array_merge(BadToken(), ['signins' => [], 'now' => null]);
        }

        $live = new Live();

        return $live->recent();
    }
}
