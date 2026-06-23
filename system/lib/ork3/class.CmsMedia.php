<?php

/*************************************************************************
 * CmsMedia — media library store for the CMS.
 *
 * Decodes base64 / data-uri image uploads with GD, validates them, writes
 * the original + a max-480px-wide thumbnail under a web-served directory
 * (assets/cms-media/{yyyymm}/), and records a row in ork_cms_media. Reads
 * shape rows into the media-ref form that block partials consume:
 *   {key:'m'+id, media_id, src, thumb, alt, focal}
 *
 * Storage scheme (relative, json-safe, stored in the `path` column):
 *   cms-media/{yyyymm}/{unique}.{ext}        original
 *   cms-media/{yyyymm}/{unique}_thumb.{ext}  thumbnail (<=480px wide)
 * Filesystem root = DIR_ASSETS; public URL root = HTTP_ASSETS (both end
 * in 'assets/'), so a stored relative path maps to DIR_ASSETS.$path on
 * disk and HTTP_ASSETS.$path in the browser.
 *
 * DB idiom: shared global $DB (YapoDb). Always Clear() before a raw
 * DataSet()/Execute(); bind via $DB->field = ... (=> :field placeholder).
 * Result rows are driven off Next()+CurrentFieldSet() (Size()/pre-fetch is
 * unreliable on this MariaDB) — same _firstRow()/_eachRow() idiom as
 * class.CmsPage.php / class.CmsAuth.php.
 *************************************************************************/

class CmsMedia extends CmsBase
{
    /** Hard upload ceiling: 8 MB of decoded image bytes. */
    private static $MAX_BYTES = 8388608; // 8 * 1024 * 1024

    /** Thumbnail max width in pixels. */
    private static $THUMB_MAX_W = 480;

    /** Relative storage root under assets/ (no leading/trailing context). */
    private static $REL_ROOT = 'cms-media';

    public function __construct()
    {
        parent::__construct();
    }

    /* ------------------------------------------------------------------ *
     * Upload
     * ------------------------------------------------------------------ */

    /**
     * Decode, validate, store an image + thumbnail, and record a media row.
     *
     * @param string $base64OrDataUri raw base64 OR a full data: URI
     * @param string $filename        original filename (display/ext hint)
     * @param string $alt             alt text
     * @param int    $uploadedBy      mundane_id of the uploader
     * @param array  $scope           ['type'=>'global'|'kingdom'|'park','id'=>int]
     * @return array|null media-row+refs on success; null on any failure
     */
    public function Upload($base64OrDataUri, $filename, $alt, $uploadedBy, $scope = array('type' => 'global', 'id' => 0))
    {
        // Strip a data-uri prefix if present: "data:image/png;base64,AAAA..."
        $raw = (string)$base64OrDataUri;
        if (strncmp($raw, 'data:', 5) === 0) {
            $comma = strpos($raw, ',');
            if ($comma !== false) {
                $raw = substr($raw, $comma + 1);
            }
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $binary = base64_decode($raw, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        // Size guard on the decoded payload.
        $bytes = strlen($binary);
        if ($bytes > self::$MAX_BYTES) {
            return null;
        }

        // Validate it's a real image and capture intrinsic dimensions/mime.
        $info = @getimagesizefromstring($binary);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }
        $width  = (int)$info[0];
        $height = (int)$info[1];
        $mime   = isset($info['mime']) ? (string)$info['mime'] : '';
        $ext    = $this->_extForMime($mime);
        if ($ext === null) {
            // Not an image type we can write back out.
            return null;
        }

        // Build a GD image from the bytes (final reject if GD can't decode it).
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }

        // Resolve the storage directory: assets/cms-media/{yyyymm}/.
        $yyyymm  = date('Ym');
        $relDir  = self::$REL_ROOT . '/' . $yyyymm;
        $diskDir = rtrim(DIR_ASSETS, '/') . '/' . $relDir;
        if (!is_dir($diskDir)) {
            @mkdir($diskDir, 0775, true);
        }
        if (!is_dir($diskDir) || !is_writable($diskDir)) {
            imagedestroy($src);
            return null;
        }

        // Unique base name (collision-proof, opaque on disk).
        $unique     = $this->_uniqueBase();
        $relPath    = $relDir . '/' . $unique . '.' . $ext;
        $relThumb   = $relDir . '/' . $unique . '_thumb.' . $ext;
        $diskPath   = rtrim(DIR_ASSETS, '/') . '/' . $relPath;
        $diskThumb  = rtrim(DIR_ASSETS, '/') . '/' . $relThumb;

        // Write the original (re-encoded through GD; normalizes/strips metadata).
        if (!$this->_writeImage($src, $diskPath, $ext)) {
            imagedestroy($src);
            return null;
        }

        // Generate + write the thumbnail (<= THUMB_MAX_W wide). Non-fatal on
        // failure — record a null thumb_path rather than aborting the upload.
        $thumbStored = null;
        $thumb = $this->_makeThumb($src, $width, $height);
        if ($thumb !== false) {
            if ($this->_writeImage($thumb, $diskThumb, $ext)) {
                $thumbStored = $relThumb;
            }
            imagedestroy($thumb);
        }

        imagedestroy($src);

        // Persist the row.
        $mediaId = $this->_insertRow(array(
            'filename'    => $this->_safeFilename($filename, $ext),
            'path'        => $relPath,
            'mime'        => $mime,
            'width'       => $width,
            'height'      => $height,
            'bytes'       => $bytes,
            'alt'         => (string)$alt,
            'thumb_path'  => $thumbStored,
            'focal'       => '50% 50%',
            'scope_type'  => $this->_normalizeScopeType(isset($scope['type']) ? $scope['type'] : 'global'),
            'scope_id'    => isset($scope['id']) ? (int)$scope['id'] : 0,
            'uploaded_by' => (int)$uploadedBy > 0 ? (int)$uploadedBy : null,
            'created_at'  => date('Y-m-d H:i:s'),
        ));

        if ($mediaId <= 0) {
            // DB write failed — clean up the orphaned files we just wrote.
            @unlink($diskPath);
            if ($thumbStored !== null) {
                @unlink($diskThumb);
            }
            return null;
        }

        // Best-effort audit (never fail the upload if the hook is unreachable).
        $this->_auditUpload($mediaId, $bytes, $mime, (int)$uploadedBy, $scope);

        return $this->GetMedia($mediaId);
    }

    /* ------------------------------------------------------------------ *
     * Shape helpers
     * ------------------------------------------------------------------ */

    /**
     * Convert a media row into the media-ref shape block partials consume.
     *
     * @param array $row associative ork_cms_media row
     * @return array {key,media_id,src,thumb,alt,focal}
     */
    public function ToMediaRef($row)
    {
        if (!is_array($row)) {
            return array();
        }
        $id   = isset($row['media_id']) ? (int)$row['media_id'] : 0;
        $path = isset($row['path']) ? (string)$row['path'] : '';
        $thumbPath = (isset($row['thumb_path']) && $row['thumb_path'] !== null && $row['thumb_path'] !== '')
            ? (string)$row['thumb_path']
            : $path; // fall back to the original when no thumb was generated

        return array(
            'key'      => 'm' . $id,
            'media_id' => $id,
            'src'      => $this->_url($path),
            'thumb'    => $this->_url($thumbPath),
            'alt'      => isset($row['alt']) ? (string)$row['alt'] : '',
            'focal'    => (isset($row['focal']) && $row['focal'] !== '') ? (string)$row['focal'] : '50% 50%',
        );
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * Newest-first media rows for the picker, returned as media refs enriched
     * with id/filename/alt and raw paths.
     *
     * @param array|null  $scope  optional ['type'=>...,'id'=>...] filter
     * @param int         $limit  max rows (default 200)
     * @param string|null $search optional LIKE over filename/alt/title
     * @return array list of media-ref + {media_id,filename,alt,created_at}
     */
    public function ListMedia($scope = null, $limit = 200, $search = null)
    {
        global $DB;

        $where = array('1 = 1');

        $DB->Clear();

        if (is_array($scope) && isset($scope['type'])) {
            $where[] = 'scope_type = :scope_type';
            $DB->scope_type = $this->_normalizeScopeType($scope['type']);
            if (isset($scope['id'])) {
                $where[] = 'scope_id = :scope_id';
                $DB->scope_id = (int)$scope['id'];
            }
        }
        if ($search !== null && $search !== '') {
            $where[] = '(filename LIKE :search OR alt LIKE :search OR title LIKE :search)';
            $DB->search = '%' . $search . '%';
        }

        $limitSql = ' LIMIT ' . ($this->_clampLimit($limit));

        $sql = 'SELECT media_id, filename, path, mime, width, height, bytes,'
            . ' alt, title, focal, thumb_path, scope_type, scope_id, uploaded_by, created_at'
            . ' FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY media_id DESC'
            . $limitSql;

        $r = $DB->DataSet($sql);

        $out = array();
        foreach ($this->_eachRow($r) as $row) {
            $ref = $this->ToMediaRef($row);
            $ref['media_id']   = (int)$row['media_id'];
            $ref['filename']   = isset($row['filename']) ? (string)$row['filename'] : '';
            $ref['alt']        = isset($row['alt']) ? (string)$row['alt'] : '';
            $ref['created_at'] = isset($row['created_at']) ? $row['created_at'] : null;
            $out[] = $ref;
        }
        return $out;
    }

    /**
     * Fetch a single media row, enriched with url + thumb_url + media-ref.
     *
     * @param int $mediaId
     * @return array|null the full row + {url,thumb_url} + media-ref fields, or null
     */
    public function GetMedia($mediaId)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return null;
        }

        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_media WHERE media_id = :media_id LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }

        $path = isset($row['path']) ? (string)$row['path'] : '';
        $thumbPath = (isset($row['thumb_path']) && $row['thumb_path'] !== null && $row['thumb_path'] !== '')
            ? (string)$row['thumb_path']
            : $path;

        // Augment with browser URLs + the media-ref shape (merged in so callers
        // get both the raw columns and the ready-to-use ref keys).
        $row['url']       = $this->_url($path);
        $row['thumb_url'] = $this->_url($thumbPath);

        $ref = $this->ToMediaRef($row);
        // Don't clobber raw columns; only add the ref-only keys.
        $row['key']      = $ref['key'];
        $row['media_id'] = (int)$row['media_id'];
        $row['src']      = $ref['src'];
        $row['thumb']    = $ref['thumb'];
        $row['focal']    = $ref['focal'];

        return $row;
    }

    /**
     * Delete a media row and unlink its files. The unlink is guarded so it can
     * only ever remove paths inside assets/cms-media/.
     *
     * @param int $mediaId
     * @return bool true when the row existed and was deleted
     */
    public function DeleteMedia($mediaId)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return false;
        }

        // Read the row first so we know which files to remove.
        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT media_id, path, thumb_path FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }

        // Delete the DB row.
        $DB->Clear();
        $DB->media_id = $mediaId;
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'cms_media WHERE media_id = :media_id');

        // Unlink files (guarded to assets/cms-media/).
        if (!empty($row['path'])) {
            $this->_safeUnlink((string)$row['path']);
        }
        if (!empty($row['thumb_path'])) {
            $this->_safeUnlink((string)$row['thumb_path']);
        }

        return true;
    }

    /* ------------------------------------------------------------------ *
     * Internal: persistence
     * ------------------------------------------------------------------ */

    /**
     * INSERT a media row from a column map; returns the new media_id (0 fail).
     */
    private function _insertRow($cols)
    {
        global $DB;

        $names = array_keys($cols);
        $placeholders = array();
        foreach ($names as $n) {
            $placeholders[] = ':' . $n;
        }
        $sql = 'INSERT INTO ' . DB_PREFIX . 'cms_media (`' . implode('`, `', $names) . '`)'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $DB->Clear();
        foreach ($cols as $field => $value) {
            $DB->$field = $value;
        }
        $DB->Execute($sql);

        return (int)$DB->GetLastInsertId();
    }

    /* ------------------------------------------------------------------ *
     * Internal: image handling
     * ------------------------------------------------------------------ */

    /**
     * Build a downscaled copy of $src no wider than THUMB_MAX_W (preserving
     * aspect ratio). Returns a NEW GD resource (caller destroys it) or false
     * if no thumbnail is needed/possible. When the source is already within
     * the cap we still return a copy so the thumb file is always written.
     */
    private function _makeThumb($src, $width, $height)
    {
        $width  = (int)$width;
        $height = (int)$height;
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $targetW = $width;
        $targetH = $height;
        if ($width > self::$THUMB_MAX_W) {
            $scale   = self::$THUMB_MAX_W / $width;
            $targetW = self::$THUMB_MAX_W;
            $targetH = max(1, (int)round($height * $scale));
        }

        // Prefer imagescale when available (cleaner + simpler).
        if (function_exists('imagescale')) {
            $scaled = @imagescale($src, $targetW, $targetH);
            if ($scaled !== false) {
                $this->_preserveAlpha($scaled);
                return $scaled;
            }
        }

        // Manual resample fallback.
        $dst = imagecreatetruecolor($targetW, $targetH);
        if ($dst === false) {
            return false;
        }
        $this->_preserveAlpha($dst);
        if (!@imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $width, $height)) {
            imagedestroy($dst);
            return false;
        }
        return $dst;
    }

    /**
     * Encode + write a GD image to disk in the format implied by $ext.
     */
    private function _writeImage($img, $diskPath, $ext)
    {
        switch ($ext) {
            case 'png':
                imagealphablending($img, false);
                imagesavealpha($img, true);
                return (bool)@imagepng($img, $diskPath);
            case 'gif':
                return (bool)@imagegif($img, $diskPath);
            case 'webp':
                if (function_exists('imagewebp')) {
                    return (bool)@imagewebp($img, $diskPath);
                }
                // Fall through to jpeg if webp unsupported by this GD build.
                return (bool)@imagejpeg($img, $diskPath, 88);
            case 'jpg':
            case 'jpeg':
            default:
                return (bool)@imagejpeg($img, $diskPath, 88);
        }
    }

    /**
     * Keep transparency on a truecolor canvas (PNG/GIF/WEBP thumbnails).
     */
    private function _preserveAlpha($img)
    {
        @imagealphablending($img, false);
        @imagesavealpha($img, true);
        $transparent = @imagecolorallocatealpha($img, 0, 0, 0, 127);
        if ($transparent !== false) {
            @imagefilledrectangle($img, 0, 0, imagesx($img), imagesy($img), $transparent);
        }
    }

    /**
     * Map a mime type to a writable file extension, or null if unsupported.
     */
    private function _extForMime($mime)
    {
        switch (strtolower((string)$mime)) {
            case 'image/jpeg':
            case 'image/jpg':
            case 'image/pjpeg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
        }
        return null;
    }

    /* ------------------------------------------------------------------ *
     * Internal: paths / filenames / urls
     * ------------------------------------------------------------------ */

    /**
     * Browser URL for a stored relative path (HTTP_ASSETS + path).
     */
    private function _url($relPath)
    {
        $relPath = ltrim((string)$relPath, '/');
        if ($relPath === '') {
            return '';
        }
        return rtrim(HTTP_ASSETS, '/') . '/' . $relPath;
    }

    /**
     * A collision-resistant opaque base name (no extension).
     */
    private function _uniqueBase()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                // fall through
            }
        }
        return md5(uniqid((string)mt_rand(), true));
    }

    /**
     * Sanitize the display filename; force the resolved extension.
     */
    private function _safeFilename($filename, $ext)
    {
        $name = (string)$filename;
        // Strip any path components a client may have sent.
        $name = basename(str_replace('\\', '/', $name));
        // Drop the existing extension; we re-append the canonical one.
        $dot = strrpos($name, '.');
        if ($dot !== false) {
            $name = substr($name, 0, $dot);
        }
        // Whitelist a conservative set of characters.
        $name = preg_replace('/[^A-Za-z0-9 ._-]+/', '_', $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'image';
        }
        if (strlen($name) > 200) {
            $name = substr($name, 0, 200);
        }
        return $name . '.' . $ext;
    }

    /**
     * Unlink a stored relative path, but ONLY if it resolves under
     * DIR_ASSETS/cms-media/ (defense against traversal / stray rows).
     */
    private function _safeUnlink($relPath)
    {
        $relPath = (string)$relPath;
        if ($relPath === '') {
            return false;
        }

        $root = rtrim(DIR_ASSETS, '/') . '/' . self::$REL_ROOT;
        $disk = rtrim(DIR_ASSETS, '/') . '/' . ltrim($relPath, '/');

        // Resolve the directory portion (the file may already be gone).
        $dir  = dirname($disk);
        $realDir  = realpath($dir);
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return false;
        }
        $realRootSlash = $realRoot . '/';
        if ($realDir === false
            || ($realDir !== $realRoot && strncmp($realDir, $realRootSlash, strlen($realRootSlash)) !== 0)) {
            return false; // outside the media tree — refuse (separator-anchored)
        }

        if (is_file($disk)) {
            return @unlink($disk);
        }
        return false;
    }

    /**
     * Clamp the list limit to a sane, code-controlled integer (LIMIT can't bind).
     */
    private function _clampLimit($limit)
    {
        $limit = (int)$limit;
        if ($limit <= 0) {
            $limit = 200;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }
        return $limit;
    }

    /* ------------------------------------------------------------------ *
     * Internal: audit (best-effort)
     * ------------------------------------------------------------------ */

    /**
     * Record a media-upload audit event if the danger-audit hook is reachable.
     * Mirrors class.Player.php::audit_media_upload — wrapped so a missing hook
     * never fails the upload.
     */
    private function _auditUpload($mediaId, $bytes, $mime, $uploadedBy, $scope)
    {
        try {
            if (!isset(Ork3::$Lib) || !isset(Ork3::$Lib->dangeraudit)
                || !is_object(Ork3::$Lib->dangeraudit)
                || !method_exists(Ork3::$Lib->dangeraudit, 'audit')) {
                return;
            }
            $payload = array(
                'MediaId'    => (int)$mediaId,
                'Media'      => array('uploaded' => true, 'bytes' => (int)$bytes, 'mime' => (string)$mime),
                'ScopeType'  => $this->_normalizeScopeType(isset($scope['type']) ? $scope['type'] : 'global'),
                'ScopeId'    => isset($scope['id']) ? (int)$scope['id'] : 0,
                'UploadedBy' => (int)$uploadedBy,
            );
            Ork3::$Lib->dangeraudit->audit(
                __CLASS__ . '::Upload',
                $payload,
                'CmsMedia',
                (int)$mediaId,
                null,
                null
            );
        } catch (\Throwable $e) {
            // Best-effort only — swallow.
        }
    }

}
