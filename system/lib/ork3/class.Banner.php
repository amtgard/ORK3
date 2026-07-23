<?php

class Banner extends Ork3
{
    private const MAX_BYTES = 1048576;

    public function SetBanner($request)
    {
        $type = (string)($request['Type'] ?? '');
        $id = (int)($request['Id'] ?? 0);
        if (!$this->isValidEntity($type) || !valid_id($id)) {
            return InvalidParameter();
        }

        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!$this->canEditBanner($mundaneId, $type, $id)) {
            return NoAuthorization();
        }
        if ($type === 'Park' && !$this->parkIsActive($id)) {
            return InvalidParameter('This park is retired. Restore the park before changing its banner.');
        }

        $banner = (string)($request['Banner'] ?? '');
        if ($banner === '') {
            return InvalidParameter('No file uploaded.');
        }

        $raw = base64_decode($banner, true);
        if ($raw === false || strlen($raw) === 0) {
            return InvalidParameter('No file uploaded.');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return InvalidParameter('File too large (max 1 MB).');
        }

        $mime = strtolower((string)($request['BannerMimeType'] ?? ''));
        if (!Common::supported_mime_types($mime) || ($mime !== 'image/jpeg' && $mime !== 'image/png')) {
            return InvalidParameter('Only JPEG and PNG images are supported.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ork3banner');
        if ($tmp === false) {
            return ProcessingError('Could not save uploaded file.');
        }
        file_put_contents($tmp, $raw);
        $detectedType = @exif_imagetype($tmp);
        @unlink($tmp);
        if ($detectedType !== IMAGETYPE_JPEG && $detectedType !== IMAGETYPE_PNG) {
            return InvalidParameter('Only JPEG and PNG images are supported.');
        }

        $meta = $this->entityMeta($type);
        $ext = ($detectedType === IMAGETYPE_PNG) ? 'png' : 'jpg';
        $showLogo = !empty($request['ShowLogo']) ? 1 : 0;
        $vignette = !empty($request['Vignette']) ? 1 : 0;
        $offX = $this->clampOffset((int)($request['OffsetX'] ?? 50));
        $offY = $this->clampOffset((int)($request['OffsetY'] ?? 50));

        if (!is_dir($meta['dir'])) {
            @mkdir($meta['dir'], 0775, true);
        }
        $base = $meta['dir'] . sprintf('%0' . $meta['pad'] . 'd', $id);
        $this->deleteBannerFiles($base);
        if (!@file_put_contents($base . '.' . $ext, $raw)) {
            return ProcessingError('Could not save uploaded file.');
        }

        $table = DB_PREFIX . $meta['table'];
        $idCol = $meta['id'];
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . $table . ' SET has_banner = 1, banner_show_logo = ' . $showLogo
            . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX
            . ', banner_offset_y = ' . $offY . ' WHERE ' . $idCol . ' = ' . $id
        );

        if ($type === 'Player') {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'mundane_design SET hero_gradient = NULL WHERE mundane_id = ' . $id
            );
        }

        if (!$this->verifyHasBanner($table, $idCol, $id, 1)) {
            @unlink($base . '.' . $ext);
            return ProcessingError('Saved file but could not update the database. Please try again.');
        }

        $this->bustEventCacheIfNeeded($type, $id);

        return Success();
    }

    public function UpdateBannerConfig($request)
    {
        $type = (string)($request['Type'] ?? '');
        $id = (int)($request['Id'] ?? 0);
        if (!$this->isValidEntity($type) || !valid_id($id)) {
            return InvalidParameter();
        }

        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!$this->canEditBanner($mundaneId, $type, $id)) {
            return NoAuthorization();
        }
        if ($type === 'Park' && !$this->parkIsActive($id)) {
            return InvalidParameter('This park is retired. Restore the park before changing its banner.');
        }

        $meta = $this->entityMeta($type);
        $table = DB_PREFIX . $meta['table'];
        $idCol = $meta['id'];

        $this->db->Clear();
        $row = $this->db->DataSet('SELECT has_banner FROM ' . $table . ' WHERE ' . $idCol . ' = ' . $id);
        if (!$row || !$row->Next() || (int)$row->has_banner !== 1) {
            return InvalidParameter('Upload a banner first before saving settings.');
        }

        $showLogo = !empty($request['ShowLogo']) ? 1 : 0;
        $vignette = !empty($request['Vignette']) ? 1 : 0;
        $offX = $this->clampOffset((int)($request['OffsetX'] ?? 50));
        $offY = $this->clampOffset((int)($request['OffsetY'] ?? 50));

        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . $table . ' SET banner_show_logo = ' . $showLogo
            . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX
            . ', banner_offset_y = ' . $offY . ' WHERE ' . $idCol . ' = ' . $id
        );

        $this->db->Clear();
        $verify = $this->db->DataSet(
            'SELECT banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM '
            . $table . ' WHERE ' . $idCol . ' = ' . $id
        );
        if (!$verify || !$verify->Next()
            || (int)$verify->banner_show_logo !== $showLogo
            || (int)$verify->banner_vignette !== $vignette
            || (int)$verify->banner_offset_x !== $offX
            || (int)$verify->banner_offset_y !== $offY) {
            return ProcessingError('Could not save banner settings. Please try again.');
        }

        $this->bustEventCacheIfNeeded($type, $id);

        return Success();
    }

    public function RemoveBanner($request)
    {
        $type = (string)($request['Type'] ?? '');
        $id = (int)($request['Id'] ?? 0);
        if (!$this->isValidEntity($type) || !valid_id($id)) {
            return InvalidParameter();
        }

        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!$this->canEditBanner($mundaneId, $type, $id)) {
            return NoAuthorization();
        }

        $meta = $this->entityMeta($type);
        $table = DB_PREFIX . $meta['table'];
        $idCol = $meta['id'];

        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . $table . ' SET has_banner = 0, banner_show_logo = 1, banner_vignette = 1, banner_offset_x = 50, banner_offset_y = 50 WHERE ' . $idCol . ' = ' . $id
        );

        if (!$this->verifyHasBanner($table, $idCol, $id, 0)) {
            return ProcessingError('Could not clear banner flag in database. Please try again.');
        }

        $base = $meta['dir'] . sprintf('%0' . $meta['pad'] . 'd', $id);
        $this->deleteBannerFiles($base);
        $this->bustEventCacheIfNeeded($type, $id);

        return Success();
    }

    /**
     * Copy banner files/config from source entity to target (R-04 T-EVA-13).
     * Requires Token + canEditBanner on both source and target.
     */
    public function CopyBanner($request)
    {
        $type = (string)($request['Type'] ?? '');
        $sourceId = (int)($request['SourceId'] ?? 0);
        $targetId = (int)($request['TargetId'] ?? 0);
        if (!$this->isValidEntity($type) || !valid_id($sourceId) || !valid_id($targetId)) {
            return InvalidParameter();
        }

        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!$this->canEditBanner($mundaneId, $type, $sourceId)
            || !$this->canEditBanner($mundaneId, $type, $targetId)) {
            return NoAuthorization();
        }

        $meta = $this->entityMeta($type);
        $table = DB_PREFIX . $meta['table'];
        $idCol = $meta['id'];

        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM '
            . $table . ' WHERE ' . $idCol . ' = ' . $sourceId
        );
        if (!$row || !$row->Next() || (int)$row->has_banner !== 1) {
            return Success();
        }

        $sourceBase = $meta['dir'] . sprintf('%0' . $meta['pad'] . 'd', $sourceId);
        $targetBase = $meta['dir'] . sprintf('%0' . $meta['pad'] . 'd', $targetId);
        $this->deleteBannerFiles($targetBase);

        $copied = false;
        foreach (['jpg', 'png'] as $ext) {
            $src = $sourceBase . '.' . $ext;
            if (file_exists($src)) {
                if (!is_dir($meta['dir'])) {
                    @mkdir($meta['dir'], 0775, true);
                }
                @copy($src, $targetBase . '.' . $ext);
                $copied = true;
            }
        }

        if ($copied) {
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . $table . ' SET has_banner = 1, banner_show_logo = ' . (int)$row->banner_show_logo
                . ', banner_vignette = ' . (int)$row->banner_vignette
                . ', banner_offset_x = ' . (int)$row->banner_offset_x
                . ', banner_offset_y = ' . (int)$row->banner_offset_y
                . ' WHERE ' . $idCol . ' = ' . $targetId
            );
        }

        $this->bustEventCacheIfNeeded($type, $targetId);

        return Success();
    }

    public static function clampOffset(int $value): int
    {
        return max(0, min(100, $value));
    }

    public static function maxBannerBytes(): int
    {
        return self::MAX_BYTES;
    }

    private function isValidEntity(string $type): bool
    {
        return in_array($type, ['Player', 'Park', 'Kingdom', 'Unit', 'Event'], true);
    }

    /**
     * @return array{table: string, id: string, dir: string, pad: int}
     */
    private function entityMeta(string $type): array
    {
        return match ($type) {
            'Player' => ['table' => 'mundane', 'id' => 'mundane_id', 'dir' => DIR_PLAYER_BANNER, 'pad' => 6],
            'Park' => ['table' => 'park', 'id' => 'park_id', 'dir' => DIR_PARK_BANNER, 'pad' => 5],
            'Kingdom' => ['table' => 'kingdom', 'id' => 'kingdom_id', 'dir' => DIR_KINGDOM_BANNER, 'pad' => 4],
            'Unit' => ['table' => 'unit', 'id' => 'unit_id', 'dir' => DIR_UNIT_BANNER, 'pad' => 5],
            'Event' => ['table' => 'event', 'id' => 'event_id', 'dir' => DIR_EVENT_BANNER, 'pad' => 5],
            default => ['table' => '', 'id' => '', 'dir' => '', 'pad' => 0],
        };
    }

    private function canEditBanner(int $mundaneId, string $type, int $id): bool
    {
        switch ($type) {
            case 'Player':
                if ($mundaneId === $id) {
                    return true;
                }
                $this->db->Clear();
                $info = $this->db->DataSet(
                    'SELECT park_id, kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $id
                );
                if (!$info || !$info->Next()) {
                    return false;
                }
                $parkId = (int)$info->park_id;
                $kingdomId = (int)$info->kingdom_id;

                return ($parkId > 0 && (bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_PARK, $parkId, AUTH_EDIT))
                    || ($kingdomId > 0 && (bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_KINGDOM, $kingdomId, AUTH_EDIT))
                    || (bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_ADMIN, 0, AUTH_ADMIN);
            case 'Park':
                return (bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_PARK, $id, AUTH_EDIT);
            case 'Kingdom':
                return (bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_KINGDOM, $id, AUTH_EDIT);
            case 'Unit':
                return (bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_UNIT, $id, AUTH_EDIT);
            case 'Event':
                if ((bool)Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $id, AUTH_EDIT)) {
                    return true;
                }

                return $this->eventStaffCanManage($mundaneId, $id);
            default:
                return false;
        }
    }

    private function parkIsActive(int $parkId): bool
    {
        $this->db->Clear();
        $row = $this->db->DataSet('SELECT active FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId);

        return $row && $row->Next() && trim((string)$row->active) === 'Active';
    }

    private function eventStaffCanManage(int $mundaneId, int $eventId): bool
    {
        $this->db->Clear();
        $staffRow = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff s JOIN ' . DB_PREFIX . 'event_calendardetail cd'
            . ' ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $eventId
            . ' AND s.mundane_id = ' . $mundaneId . ' AND s.can_manage = 1 LIMIT 1'
        );

        return $staffRow && $staffRow->Next();
    }

    private function verifyHasBanner(string $table, string $idCol, int $id, int $expected): bool
    {
        $this->db->Clear();
        $verify = $this->db->DataSet('SELECT has_banner FROM ' . $table . ' WHERE ' . $idCol . ' = ' . $id);

        return $verify && $verify->Next() && (int)$verify->has_banner === $expected;
    }

    private function deleteBannerFiles(string $base): void
    {
        if (file_exists($base . '.jpg')) {
            @unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            @unlink($base . '.png');
        }
    }

    private function bustEventCacheIfNeeded(string $type, int $id): void
    {
        if ($type === 'Event') {
            Ork3::$Lib->ghettocache->bust_event_search($id);
        }
    }
}
