<?php

class Heraldry extends Ork3
{
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
        $size = $request['Size'] ?? null;
        switch ($request['Type']) {
            case 'Player': $response['Url'] = $this->resolve_heraldry_url(HTTP_PLAYER_HERALDRY, DIR_PLAYER_HERALDRY, 6, $request['Id'], $size);
                break;
            case 'Park': $response['Url'] = $this->resolve_heraldry_url(HTTP_PARK_HERALDRY, DIR_PARK_HERALDRY, 5, $request['Id'], $size);
                break;
            case 'Kingdom': $response['Url'] = $this->resolve_heraldry_url(HTTP_KINGDOM_HERALDRY, DIR_KINGDOM_HERALDRY, 4, $request['Id'], $size);
                break;
            case 'Unit': $response['Url'] = $this->resolve_heraldry_url(HTTP_UNIT_HERALDRY, DIR_UNIT_HERALDRY, 5, $request['Id'], $size);
                break;
            case 'Event': $response['Url'] = $this->resolve_heraldry_url(HTTP_EVENT_HERALDRY, DIR_EVENT_HERALDRY, 5, $request['Id'], $size);
                break;
        }
        return $response;
    }

    private function resolve_heraldry_url($http_base, $dir_base, $pad_len, $id, $size = null)
    {
        $name = sprintf("%0" . $pad_len . "d", $id);
        // filemtime()-based cache buster so re-uploads always show fresh —
        // the URL was previously bare and relied on browser cache expiring
        // on its own. resolve_media_ext picks the right rendition for $size
        // (thumb/display -> webp/jpg, falling back to the master), or the
        // master itself when $size is null. Cache-bust against whichever file
        // it resolves to.
        $file = Common::resolve_media_ext($dir_base, $name, $size);
        if (file_exists($dir_base . $file)) {
            return $http_base . $file . '?v=' . filemtime($dir_base . $file);
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
                $base = DIR_PLAYER_HERALDRY . sprintf('%06d', $request['MundaneId']);
                Common::unlink_image_set($base);
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
                if (!$this->store_heraldry($request, DIR_PLAYER_HERALDRY, 6, 'mundane')) {
                    return InvalidParameter('Image is too large even after resizing; please upload a smaller image.');
                }
                $this->mundane->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    // Returns true when the upload is stored (or is a no-op: empty/invalid/
    // undecodable input keeps the historical silent-success behavior), and
    // false only when a decoded master still exceeds IMAGE_MASTER_MAX_BYTES
    // after the 3000px clamp — i.e. an over-ceiling image the caller must
    // reject with a clear message rather than silently shrink.
    private function store_heraldry($request, $path, $img_len, $table)
    {
        if (strlen($request['Heraldry']) > 0 && strlen($request['Heraldry']) < IMAGE_UPLOAD_MAX_BYTES && Common::supported_mime_types($request['HeraldryMimeType'])) {
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

                // Clamp the master to a 3000px longest edge AFTER the trim so
                // the scale reflects the trimmed bounds. Never upscales.
                $heraldry = Common::gd_scale_to_max_edge($heraldry, 3000);

                // Reject an over-ceiling master before touching disk — never
                // silently shrink past the quality target. Nothing is unlinked
                // or written on rejection.
                $encoded = Common::encode_size($heraldry, $use_png ? 'png' : 'jpeg', 92);
                if ($encoded > IMAGE_MASTER_MAX_BYTES) {
                    return false;
                }

                // A re-upload can flip formats (png<->jpg, webp<->jpg), so sweep
                // the master plus every prior rendition too or a stale one would linger.
                Common::unlink_image_set($base);

                if ($use_png) {
                    imagealphablending($heraldry, false);
                    imagesavealpha($heraldry, true);
                    imagepng($heraldry, $base . '.png');
                } else {
                    imagejpeg($heraldry, $base . '.jpg', 92);
                }

                // Derive the delivery renditions (thumb 256px, display 1024px)
                // from the same in-memory master.
                Common::generate_renditions($heraldry, $base, $use_png);

                $this->$table->has_heraldry = 1;
            }
        }
        return true;
    }

    private function fetch_url_heraldry($request)
    {
        if (strlen($request['HeraldryUrl']) > 0 && Common::url_exists($request['HeraldryUrl'])) {
            if ($this->url_file_size($request['HeraldryUrl']) < IMAGE_UPLOAD_MAX_BYTES) {
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
                if (!$this->store_heraldry($request, DIR_KINGDOM_HERALDRY, 4, 'kingdom')) {
                    return InvalidParameter('Image is too large even after resizing; please upload a smaller image.');
                }
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
                Common::unlink_image_set($base);
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
                if (!$this->store_heraldry($request, DIR_PARK_HERALDRY, 5, 'park')) {
                    return InvalidParameter('Image is too large even after resizing; please upload a smaller image.');
                }
                $this->park->save();
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
                Common::unlink_image_set($base);
                $this->park->has_heraldry = 0;
                $this->park->save();
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
                if (!$this->store_heraldry($request, DIR_UNIT_HERALDRY, 5, 'unit')) {
                    return InvalidParameter('Image is too large even after resizing; please upload a smaller image.');
                }
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
                Common::unlink_image_set($base);
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

    public function SetEventHeraldry($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_EDIT)) {
            $this->event->clear();
            $this->event->event_id = $request['EventId'];
            if ($this->event->find()) {
                $request = $this->fetch_url_heraldry($request);
                if (!$this->store_heraldry($request, DIR_EVENT_HERALDRY, 5, 'event')) {
                    return InvalidParameter('Image is too large even after resizing; please upload a smaller image.');
                }
                $this->event->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

}
