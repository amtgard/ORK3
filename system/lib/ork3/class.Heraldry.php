<?php

class Heraldry extends Ork3
{
    /**
     * SINGLE SOURCE OF TRUTH for the zero-pad width of a heraldry FILENAME.
     *
     * These widths are not a style choice, they are the on-disk reality, and they
     * DIFFER per scope type:
     *     assets/heraldry/kingdom/0007.jpg   (4)
     *     assets/heraldry/park/01049.png     (5)
     *     assets/heraldry/player/000123.jpg  (6)
     *
     * Anything that builds a heraldry path MUST read the width from here rather
     * than re-typing the number. A second copy fails silently, not loudly: it
     * probes a filename that simply never exists, so the caller sees "this org has
     * no device" instead of an error. That is exactly what happened —
     * CmsSite::_heraldryPath() hard-coded 5 for BOTH scopes, so the heraldry
     * colour extractor never matched a single kingdom device and every kingdom
     * site silently fell through to the name-hash palette.
     *
     * @var array<string,int>
     */
    public const PAD_LENGTHS = array(
        'player'  => 6,
        'mundane' => 6,   // the player table's own name, accepted as an alias
        'park'    => 5,
        'kingdom' => 4,
        'unit'    => 5,
        'event'   => 5,
    );

    /**
     * Zero-pad width for a heraldry scope type, case-insensitive.
     *
     * @param string $type 'player'|'park'|'kingdom'|'unit'|'event'
     * @return int width, or 0 for an unknown type
     */
    public static function PadLength($type)
    {
        $key = strtolower((string) $type);
        return isset(self::PAD_LENGTHS[$key]) ? self::PAD_LENGTHS[$key] : 0;
    }

    /**
     * Zero-padded heraldry basename, e.g. ('kingdom', 7) => '0007'.
     *
     * @param string $type
     * @param int    $id
     * @return string basename without extension, or '' for an unknown type
     */
    public static function BaseName($type, $id)
    {
        $pad = self::PadLength($type);
        return ($pad > 0) ? sprintf('%0' . $pad . 'd', (int) $id) : '';
    }

    public function __construct()
    {
        parent::__construct();
        $this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
        $this->kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
        $this->park = new yapo($this->db, DB_PREFIX . 'park');
        $this->unit = new yapo($this->db, DB_PREFIX . 'unit');
        $this->event = new yapo($this->db, DB_PREFIX . 'event');
    }

    public function GetHeraldry($request)
    {
        $response = array('Heraldry' => '');
        switch ($request['Type']) {
            case 'Player':
                $name = sprintf('%06d', $request['Id']);
                $path = file_exists(DIR_PLAYER_HERALDRY . $name . '.png')
                    ? DIR_PLAYER_HERALDRY . $name . '.png'
                    : DIR_PLAYER_HERALDRY . $name . '.jpg';
                $response['Heraldry'] = base64_encode(file_get_contents($path));
                break;
        }
        return $response;
    }

    public function GetHeraldryUrl($request)
    {
        $response = array('Url' => '');
        switch ($request['Type']) {
            case 'Player': $response['Url'] = $this->resolve_heraldry_url(HTTP_PLAYER_HERALDRY, DIR_PLAYER_HERALDRY, self::PadLength('player'), $request['Id']);
                break;
            case 'Park': $response['Url'] = $this->resolve_heraldry_url(HTTP_PARK_HERALDRY, DIR_PARK_HERALDRY, self::PadLength('park'), $request['Id']);
                break;
            case 'Kingdom': $response['Url'] = $this->resolve_heraldry_url(HTTP_KINGDOM_HERALDRY, DIR_KINGDOM_HERALDRY, self::PadLength('kingdom'), $request['Id']);
                break;
            case 'Unit': $response['Url'] = $this->resolve_heraldry_url(HTTP_UNIT_HERALDRY, DIR_UNIT_HERALDRY, self::PadLength('unit'), $request['Id']);
                break;
            case 'Event': $response['Url'] = $this->resolve_heraldry_url(HTTP_EVENT_HERALDRY, DIR_EVENT_HERALDRY, self::PadLength('event'), $request['Id']);
                break;
        }
        return $response;
    }

    private function resolve_heraldry_url($http_base, $dir_base, $pad_len, $id)
    {
        $name = sprintf("%0" . $pad_len . "d", $id);
        // filemtime()-based cache buster so re-uploads always show fresh —
        // the URL was previously bare and relied on browser cache expiring
        // on its own.
        if (file_exists($dir_base . $name . '.png')) {
            return $http_base . $name . '.png?v=' . filemtime($dir_base . $name . '.png');
        }
        if (file_exists($dir_base . $name . '.jpg')) {
            return $http_base . $name . '.jpg?v=' . filemtime($dir_base . $name . '.jpg');
        }
        return $http_base . $name . '.jpg';
    }

    public function RemovePlayerHeraldry($request)
    {
        $mundane = Ork3::$Lib->player->player_info($request['MundaneId']);

        if ((($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT))
            || $mundane_id == $request['MundaneId']) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $path = DIR_PLAYER_HERALDRY . sprintf('%06d', $request['MundaneId']) . '.jpg';
                if (file_exists($path)) {
                    unlink($path);
                }
                $this->mundane->has_heraldry = 0;
                $this->mundane->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function SetPlayerHeraldry($request)
    {
        $mundane = Ork3::$Lib->player->player_info($request['MundaneId']);

        if ((($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT))
            || $mundane_id == $request['MundaneId']) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $request = $this->fetch_url_heraldry($request);
                $this->store_heraldry($request, DIR_PLAYER_HERALDRY, self::PadLength('mundane'), 'mundane');
                $this->mundane->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    private function store_heraldry($request, $path, $img_len, $table)
    {
        if (strlen($request['Heraldry']) > 0 && strlen($request['Heraldry']) < 465000 && Common::supported_mime_types($request['HeraldryMimeType'])) {
            $heraldry = @imagecreatefromstring(base64_decode($request['Heraldry']));
            if ($heraldry !== false) {
                $src_id = ucwords($table) . 'Id';
                $base = $path . sprintf("%0" . $img_len . "d", $request[$src_id]);
                // Trust the client-declared PNG mime: alpha may be sparse enough
                // to evade gd_has_transparency's grid sampling. Falling through
                // to JPEG would mask transparency with a black background.
                $use_png = (strtolower($request['HeraldryMimeType']) === 'image/png')
                    || Common::gd_has_transparency($heraldry);

                if ($use_png) {
                    $heraldry = Common::gd_trim_transparent($heraldry);
                }

                if (file_exists($base . '.jpg')) {
                    unlink($base . '.jpg');
                }
                if (file_exists($base . '.png')) {
                    unlink($base . '.png');
                }

                if ($use_png) {
                    imagealphablending($heraldry, false);
                    imagesavealpha($heraldry, true);
                    imagepng($heraldry, $base . '.png');
                } else {
                    imagejpeg($heraldry, $base . '.jpg');
                }

                $this->$table->has_heraldry = 1;
            }
        }
    }

    private function fetch_url_heraldry($request)
    {
        if (strlen($request['HeraldryUrl']) > 0 && Common::url_exists($request['HeraldryUrl'])) {
            if ($this->url_file_size($request['HeraldryUrl']) < 465000) {
                $request['Heraldry'] = base64_encode(file_get_contents($request['HeraldryUrl']));
                $request['HeraldryMimeType'] = Common::exif_to_mime(@exif_imagetype($tmp_file), $request['HeraldryUrl']);
            }
        }
        return $request;
    }

    public function SetPrincipalityHeraldry($request)
    {
        $request['KingdomId'] = $request['PrincipalityId'];
        $this->SetKingdomHeraldry($request);
    }

    public function url_file_size($remoteFile)
    {
        $ch = curl_init($remoteFile);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); //not necessary unless the file redirects (like the PHP example we're using here)
        $data = curl_exec($ch);
        curl_close($ch);
        if ($data === false) {
            echo 'cURL failed';
            exit;
        }

        $contentLength = 0;
        if (preg_match('/Content-Length: (\d+)/', $data, $matches)) {
            $contentLength = (int)$matches[1];
        }

        return $contentLength;
    }

    public function SetKingdomHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $request['KingdomId'];
            if ($this->kingdom->find()) {
                $request = $this->fetch_url_heraldry($request);
                $this->store_heraldry($request, DIR_KINGDOM_HERALDRY, self::PadLength('kingdom'), 'kingdom');
                $this->kingdom->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveKingdomHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $request['KingdomId'];
            if ($this->kingdom->find()) {
                $base = DIR_KINGDOM_HERALDRY . sprintf('%04d', $request['KingdomId']);
                if (file_exists($base . '.jpg')) {
                    unlink($base . '.jpg');
                }
                if (file_exists($base . '.png')) {
                    unlink($base . '.png');
                }
                $this->kingdom->has_heraldry = 0;
                $this->kingdom->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function SetParkHeraldry($request)
    {

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
            && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_EDIT)) {
            $this->park->clear();
            $this->park->park_id = $request['ParkId'];
            if ($this->park->find()) {
                $request = $this->fetch_url_heraldry($request);
                $this->store_heraldry($request, DIR_PARK_HERALDRY, self::PadLength('park'), 'park');
                $this->park->save();
                // has_heraldry is one of the columns Park::GetParkDetails() memoizes
                // per request, so this write has to drop that memo.
                Park::BustParkMemo($request['ParkId']);
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveParkHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_EDIT)) {
            $this->park->clear();
            $this->park->park_id = $request['ParkId'];
            if ($this->park->find()) {
                $base = DIR_PARK_HERALDRY . sprintf('%05d', $request['ParkId']);
                if (file_exists($base . '.jpg')) {
                    unlink($base . '.jpg');
                }
                if (file_exists($base . '.png')) {
                    unlink($base . '.png');
                }
                $this->park->has_heraldry = 0;
                $this->park->save();
                Park::BustParkMemo($request['ParkId']);
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function SetUnitHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_EDIT)) {
            //			logtrace("SetUnitHeraldry() :1", $request);
            $this->unit->clear();
            $this->unit->unit_id = $request['UnitId'];
            if ($this->unit->find()) {
                $request = $this->fetch_url_heraldry($request);
                $this->store_heraldry($request, DIR_UNIT_HERALDRY, self::PadLength('unit'), 'unit');
                $this->unit->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveUnitHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_UNIT, $request['UnitId'], AUTH_EDIT)) {
            $this->unit->clear();
            $this->unit->unit_id = $request['UnitId'];
            if ($this->unit->find()) {
                $base = DIR_UNIT_HERALDRY . sprintf('%05d', $request['UnitId']);
                if (file_exists($base . '.jpg')) {
                    unlink($base . '.jpg');
                }
                if (file_exists($base . '.png')) {
                    unlink($base . '.png');
                }
                $this->unit->has_heraldry = 0;
                $this->unit->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveEventHeraldry($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int)($request['EventId'] ?? 0);
        if ($mundane_id <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId)) {
            return InvalidParameter();
        }

        $planning = new EventPlanning();
        if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $eventId, AUTH_EDIT)
                && !$planning->CanManageEventDetail($mundane_id, $eventId, 0, 'manage')) {
            return NoAuthorization();
        }

        $this->event->clear();
        $this->event->event_id = $eventId;
        if (!$this->event->find()) {
            return InvalidParameter();
        }

        $base = DIR_EVENT_HERALDRY . sprintf('%05d', $eventId);
        if (file_exists($base . '.jpg')) {
            @unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            @unlink($base . '.png');
        }
        $this->event->has_heraldry = 0;
        $this->event->save();
        Ork3::$Lib->ghettocache->bust_event_search($eventId);

        return Success();
    }

    public function SetEventHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_EDIT)) {
            $this->event->clear();
            $this->event->event_id = $request['EventId'];
            if ($this->event->find()) {
                $request = $this->fetch_url_heraldry($request);
                $this->store_heraldry($request, DIR_EVENT_HERALDRY, self::PadLength('event'), 'event');
                $this->event->save();
                Ork3::$Lib->ghettocache->bust_event_search((int) $request['EventId']);
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

}
