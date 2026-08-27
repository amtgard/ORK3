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
        switch ($request['Type']) {
            case 'Player': $response['Url'] = $this->resolve_heraldry_url(HTTP_PLAYER_HERALDRY, DIR_PLAYER_HERALDRY, 6, $request['Id']);
                break;
            case 'Park': $response['Url'] = $this->resolve_heraldry_url(HTTP_PARK_HERALDRY, DIR_PARK_HERALDRY, 5, $request['Id']);
                break;
            case 'Kingdom': $response['Url'] = $this->resolve_heraldry_url(HTTP_KINGDOM_HERALDRY, DIR_KINGDOM_HERALDRY, 4, $request['Id']);
                break;
            case 'Unit': $response['Url'] = $this->resolve_heraldry_url(HTTP_UNIT_HERALDRY, DIR_UNIT_HERALDRY, 5, $request['Id']);
                break;
            case 'Event': $response['Url'] = $this->resolve_heraldry_url(HTTP_EVENT_HERALDRY, DIR_EVENT_HERALDRY, 5, $request['Id']);
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'player.heraldry.manage', 'park', $mundane['ParkId'], AUTH_EDIT))
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'player.heraldry.manage', 'park', $mundane['ParkId'], AUTH_EDIT))
            || $mundane_id == $request['MundaneId']) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $request = $this->fetch_url_heraldry($request);
                $stored = $this->store_heraldry($request, DIR_PLAYER_HERALDRY, 6, 'mundane');
                if ((int)$stored['Status'] !== 0) {
                    // Nothing was written and has_heraldry was not touched;
                    // skip the save() so the failure cannot read as an update.
                    return $stored;
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

    /**
     * Write an uploaded heraldry payload to disk.
     *
     * Returns a service response array. Success() means either "written" or
     * "there was nothing to write"; anything else is a hard failure and the
     * caller MUST return it instead of save()ing and reporting success.
     *
     * Every rejection below used to be a bare `return`, so an oversize or
     * unreadable image left the record untouched while the UI said
     * "Heraldry updated!". Resizing is the browser's job (resizeImageToLimit()
     * in orkui.js); these checks are the backstop when that never ran.
     */
    private function store_heraldry($request, $path, $img_len, $table)
    {
        $payload = (string)($request['Heraldry'] ?? '');
        if (strlen($payload) === 0) {
            // No image on this request at all. Set*Heraldry() is also reached
            // from Set-record paths that carry no upload, so this is a no-op,
            // not a failure.
            return Success();
        }

        $mime = $request['HeraldryMimeType'] ?? '';
        $payload_error = Common::image_payload_error($payload, $mime);
        if ($payload_error !== null) {
            return ProcessingError(null, $payload_error);
        }

        $heraldry = @imagecreatefromstring(base64_decode($payload));
        if ($heraldry === false) {
            return ProcessingError(null, Common::IMAGE_UNREADABLE_MESSAGE);
        }

        $src_id = ucwords($table) . 'Id';
        $base = $path . sprintf("%0" . $img_len . "d", (int)($request[$src_id] ?? 0));
        // Trust the client-declared PNG mime: alpha may be sparse enough
        // to evade gd_has_transparency's grid sampling. Falling through
        // to JPEG would mask transparency with a black background.
        $use_png = (strtolower((string)$mime) === 'image/png')
            || Common::gd_has_transparency($heraldry);

        if ($use_png) {
            $heraldry = Common::gd_trim_transparent($heraldry);
        }

        // Write the new file BEFORE unlinking the stale sibling extension.
        // Both files used to be unlinked up front, so a failed imagepng() /
        // imagejpeg() destroyed the existing heraldry and left nothing behind.
        // The end state on success is identical: exactly one of .jpg / .png.
        if ($use_png) {
            imagealphablending($heraldry, false);
            imagesavealpha($heraldry, true);
            $written = @imagepng($heraldry, $base . '.png');
            $stale = $base . '.jpg';
        } else {
            $written = @imagejpeg($heraldry, $base . '.jpg');
            $stale = $base . '.png';
        }

        if ($written === false) {
            return ProcessingError(null, 'The image could not be saved to storage. Please try again, or contact an administrator if it keeps happening.');
        }

        if (file_exists($stale)) {
            @unlink($stale);
        }

        $this->$table->has_heraldry = 1;
        return Success();
    }

    private function fetch_url_heraldry($request)
    {
        // HeraldryUrl is only set on the URL-import path; the ordinary upload
        // path never sets it, so read it defensively rather than leaning on
        // suppressed undefined-index warnings.
        $url = (string)($request['HeraldryUrl'] ?? '');
        if (strlen($url) > 0 && Common::url_exists($url)) {
            if ($this->url_file_size($url) < Common::MAX_IMAGE_BASE64_LENGTH) {
                $body = @file_get_contents($url);
                if ($body === false || $body === '') {
                    return $request;
                }
                $request['Heraldry'] = base64_encode($body);
                // Was exif_imagetype($tmp_file) on a variable that was never
                // assigned in this method — always null, so the mime always
                // came from the filename fallback. Read the type out of the
                // bytes we already have instead of re-fetching the URL.
                $info = @getimagesizefromstring($body);
                $request['HeraldryMimeType'] = Common::exif_to_mime(
                    is_array($info) ? ($info[2] ?? 0) : 0,
                    $url
                );
            }
        }
        return $request;
    }

    public function SetPrincipalityHeraldry($request)
    {
        $request['KingdomId'] = $request['PrincipalityId'];
        // Was missing the return entirely, so this method answered null for
        // every outcome — authorization failures included.
        return $this->SetKingdomHeraldry($request);
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.heraldry.manage', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $request['KingdomId'];
            if ($this->kingdom->find()) {
                $request = $this->fetch_url_heraldry($request);
                $stored = $this->store_heraldry($request, DIR_KINGDOM_HERALDRY, 4, 'kingdom');
                if ((int)$stored['Status'] !== 0) {
                    return $stored;
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'kingdom.heraldry.manage', 'kingdom', $request['KingdomId'], AUTH_EDIT)) {
            $this->kingdom->clear();
            $this->kingdom->kingdom_id = $request['KingdomId'];
            if ($this->kingdom->find()) {
                $base = DIR_KINGDOM_HERALDRY . sprintf('%04d', (int)$request['KingdomId']);
                // Same two files as before, deleted the same way. The only new
                // behaviour is that the result of each unlink() is now checked,
                // so a delete that could not happen is reported instead of
                // being papered over with has_heraldry = 0 and "Success".
                $removed = 0;
                $stuck = array();
                foreach (array('.jpg', '.png') as $ext) {
                    if (!file_exists($base . $ext)) {
                        continue;
                    }
                    if (@unlink($base . $ext)) {
                        $removed++;
                    } else {
                        $stuck[] = $ext;
                    }
                }

                if (count($stuck) > 0) {
                    // The file is still on disk and still being served. Leave
                    // has_heraldry alone rather than hiding a file that exists.
                    return ProcessingError(null, 'The heraldry image could not be deleted from storage. Nothing was changed - please try again, or contact an administrator.');
                }

                $this->kingdom->has_heraldry = 0;
                $this->kingdom->save();
                // Removing heraldry unlinks a file from disk; it wrote no audit row.
                Ork3::$Lib->dangeraudit->audit(
                    __CLASS__ . '::' . __FUNCTION__,
                    $request,
                    'Kingdom',
                    (int)$request['KingdomId'],
                    ['has_heraldry' => 1],
                    ['has_heraldry' => 0]
                );
                // Distinguish "the image is gone" from "there was no image on
                // disk and the flag has been repaired" so the modal can say
                // something true either way.
                return Success($removed > 0
                    ? 'Heraldry removed.'
                    : 'No heraldry image was on file; the record has been cleared.');
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
            && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.heraldry.manage', 'park', $request['ParkId'], AUTH_EDIT)) {
            $this->park->clear();
            $this->park->park_id = $request['ParkId'];
            if ($this->park->find()) {
                $request = $this->fetch_url_heraldry($request);
                $stored = $this->store_heraldry($request, DIR_PARK_HERALDRY, 5, 'park');
                if ((int)$stored['Status'] !== 0) {
                    return $stored;
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'park.heraldry.manage', 'park', $request['ParkId'], AUTH_EDIT)) {
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
                // Removing heraldry unlinks a file from disk; it wrote no audit row.
                Ork3::$Lib->dangeraudit->audit(
                    __CLASS__ . '::' . __FUNCTION__,
                    $request,
                    'Park',
                    (int)$request['ParkId'],
                    ['has_heraldry' => 1],
                    ['has_heraldry' => 0]
                );
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'unit.heraldry.manage', 'unit', $request['UnitId'], AUTH_EDIT)) {
            //			logtrace("SetUnitHeraldry() :1", $request);
            $this->unit->clear();
            $this->unit->unit_id = $request['UnitId'];
            if ($this->unit->find()) {
                $request = $this->fetch_url_heraldry($request);
                $stored = $this->store_heraldry($request, DIR_UNIT_HERALDRY, 5, 'unit');
                if ((int)$stored['Status'] !== 0) {
                    return $stored;
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
                && Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'unit.heraldry.manage', 'unit', $request['UnitId'], AUTH_EDIT)) {
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
                // Removing heraldry unlinks a file from disk; it wrote no audit row.
                Ork3::$Lib->dangeraudit->audit(
                    __CLASS__ . '::' . __FUNCTION__,
                    $request,
                    'Unit',
                    (int)$request['UnitId'],
                    ['has_heraldry' => 1],
                    ['has_heraldry' => 0]
                );
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
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $eventId = (int)($request['EventId'] ?? 0);
        // Same authority rule as RemoveEventHeraldry below: an ork_authorization
        // event grant OR event-staff can_manage. The staff path was missing here,
        // so a fully-granted event staffer could remove the event's logo (and set
        // its banner — class.Banner accepts staff) but not upload a logo.
        $planning = new EventPlanning();
        if ($mundane_id > 0
                && (Ork3::$Lib->authorizationgate->checkPermissionOrAuthority($mundane_id, 'event.heraldry.manage', 'event', $eventId, AUTH_EDIT)
                    || $planning->CanManageEventDetail($mundane_id, $eventId, 0, 'manage'))) {
            $this->event->clear();
            $this->event->event_id = $request['EventId'];
            if ($this->event->find()) {
                $request = $this->fetch_url_heraldry($request);
                $stored = $this->store_heraldry($request, DIR_EVENT_HERALDRY, 5, 'event');
                if ((int)$stored['Status'] !== 0) {
                    return $stored;
                }
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
