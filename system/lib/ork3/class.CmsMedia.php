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

    /**
     * Hard pixel-area ceiling (~40 megapixels). GD allocates ~4 bytes/pixel on
     * decode, so a small highly-compressed file declaring, e.g., 30000x30000
     * would try to allocate ~3.6 GB and kill the FPM worker (decompression bomb,
     * C18). We reject on declared dimensions BEFORE imagecreatefromstring().
     */
    private static $MAX_PIXELS = 40000000; // 40 * 1000 * 1000

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

        // C18: decompression-bomb guard. Reject an image whose declared pixel
        // area exceeds the ceiling BEFORE decoding — the compressed byte cap does
        // NOT bound decoded size (a few KB can declare hundreds of megapixels).
        if ($width * $height > self::$MAX_PIXELS) {
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

        // Persist the row. Capture the column set so the success payload can be
        // built from it in memory (no read-back SELECT).
        $cols = array(
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
        );
        $mediaId = $this->_insertRow($cols);

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

        // Build the success payload from the columns we just wrote (plus the
        // new id) — the row is already fully known in memory, so a SELECT to
        // read back our own INSERT is wasted work. Mirror GetMedia()'s shape.
        $row = array_merge($cols, array('media_id' => $mediaId));
        $row['url']       = $this->_url($relPath);
        $row['thumb_url'] = $this->_url($thumbStored !== null ? $thumbStored : $relPath);

        $ref = $this->ToMediaRef($row);
        $row['key']   = $ref['key'];
        $row['src']   = $ref['src'];
        $row['thumb'] = $ref['thumb'];
        $row['focal'] = $ref['focal'];

        return $row;
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

    /**
     * Enrich a raw list row (from ListMedia/ListTrashed) into the media-ref
     * shape plus the id/filename/alt/title/created_at fields the picker + Trash
     * surfaces consume. Shared so the two list mappings stay in lockstep.
     *
     * @param array $row associative ork_cms_media row
     * @return array media-ref + {media_id,filename,alt,created_at,title}
     */
    private function _mediaListRow($row)
    {
        $ref = $this->ToMediaRef($row);
        $ref['media_id']   = isset($row['media_id']) ? (int)$row['media_id'] : 0;
        $ref['filename']   = isset($row['filename']) ? (string)$row['filename'] : '';
        $ref['alt']        = isset($row['alt']) ? (string)$row['alt'] : '';
        $ref['created_at'] = isset($row['created_at']) ? $row['created_at'] : null;
        $ref['title']      = isset($row['title']) ? (string)$row['title'] : '';
        return $ref;
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

        // C2: never list trashed media.
        $where = array('deleted_at IS NULL');

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
            // A named placeholder reused across a statement is undefined behavior
            // under PDO emulated prepares (only the first binds), so use distinct
            // names — same rule CmsPage::ListPages follows.
            $where[] = '(filename LIKE :search_fn OR alt LIKE :search_alt OR title LIKE :search_ti)';
            // Escape LIKE metacharacters: bound params block SQL injection but
            // not '%'/'_' wildcards, so a bare '%' would otherwise match every row.
            $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $search) . '%';
            $DB->search_fn  = $like;
            $DB->search_alt = $like;
            $DB->search_ti  = $like;
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
            $out[] = $this->_mediaListRow($row);
        }
        return $out;
    }

    /**
     * List TRASHED media (deleted_at IS NOT NULL) for a scope — the mirror of
     * ListMedia for the Trash view. Newest-trashed-first, returned as media refs
     * enriched with id/filename/alt so the admin Trash surface can offer
     * Restore + Purge. Never surfaced to public pickers (those gate deleted_at
     * IS NULL).
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @param int    $limit     max rows (default 200, clamped)
     * @return array list of media-ref + {media_id,filename,alt,created_at}
     */
    public function ListTrashed($scopeType = 'global', $scopeId = 0, $limit = 200)
    {
        global $DB;

        $DB->Clear();
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;

        $limitSql = ' LIMIT ' . ($this->_clampLimit($limit));

        $sql = 'SELECT media_id, filename, path, mime, width, height, bytes,'
            . ' alt, title, focal, thumb_path, scope_type, scope_id, uploaded_by, created_at, deleted_at'
            . ' FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE deleted_at IS NOT NULL AND scope_type = :scope_type AND scope_id = :scope_id'
            . ' ORDER BY deleted_at DESC, media_id DESC'
            . $limitSql;

        $r = $DB->DataSet($sql);

        $out = array();
        foreach ($this->_eachRow($r) as $row) {
            $out[] = $this->_mediaListRow($row);
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
        // C2: a trashed media row is invisible to pickers/consumers.
        $row = $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_media WHERE media_id = :media_id AND deleted_at IS NULL LIMIT 1'
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
     * Update a media row's authored metadata (C1: alt + title). Only the keys
     * present in $data are written; anything else is untouched. Both columns are
     * cleared with '' (not NULL) so an author who intentionally marks an image
     * DECORATIVE (alt='') is persisted rather than dropped by the yapo null-skip.
     *
     * Alt text is authored copy, so it is stored verbatim (the front-door image
     * partial escapes it with htmlspecialchars on render — see image.tpl).
     *
     * @param int         $mediaId
     * @param array       $data      subset: 'alt' (string, '' = decorative), 'title' (string)
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @param string|null $scopeType optional ownership guard: only touch a row in this scope
     * @param int|null    $scopeId   optional ownership guard: scope owner id
     * @return bool true when a valid, non-trashed, owned row was updated
     */
    public function Update($mediaId, $data, $actorId = 0, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0 || !is_array($data)) {
            return false;
        }

        // Confirm the row exists and is not trashed (also grabs scope for audit).
        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT media_id, scope_type, scope_id FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }

        // IDOR guard: refuse when the caller's scope doesn't own this row.
        if (!$this->_scopeOwns($row, $scopeType, $scopeId)) {
            return false;
        }

        $set = array();
        $DB->Clear();
        if (array_key_exists('alt', $data)) {
            $set[] = 'alt = :alt';
            // '' is a first-class value here (decorative image); never coerce to NULL.
            $DB->alt = (string)$data['alt'];
        }
        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $DB->title = (string)$data['title'];
        }
        if (count($set) === 0) {
            return false;
        }

        $DB->media_id = $mediaId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_media SET ' . implode(', ', $set)
            . ' WHERE media_id = :media_id AND deleted_at IS NULL'
        );

        $this->_cmsAudit((int)$actorId, 'update', 'media', $mediaId, (string)$row['scope_type'], (int)$row['scope_id']);
        return true;
    }

    /**
     * Trash a media row (C2 soft-delete). Files are KEPT so a restore can bring
     * the asset back. REFUSES (C8) when the media is still referenced anywhere —
     * a page/post hero, a site logo, or inside any block's fields_json — so a
     * live page can never end up pointing at a vanished image.
     *
     * @param int         $mediaId
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @param string|null $scopeType optional ownership guard: only touch a row in this scope
     * @param int|null    $scopeId   optional ownership guard: scope owner id
     * @return bool true when the row existed, was owned, unreferenced, and was trashed
     */
    public function DeleteMedia($mediaId, $actorId = 0, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return false;
        }

        // Read the (non-trashed) row + its scope for the audit entry.
        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT media_id, scope_type, scope_id FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }

        // IDOR guard: refuse when the caller's scope doesn't own this row.
        if (!$this->_scopeOwns($row, $scopeType, $scopeId)) {
            return false;
        }

        // C8: where-used check. Refuse while any reference remains.
        if ($this->_referenceCount($mediaId) > 0) {
            return false;
        }

        // Soft-delete: stamp the trash marker; keep the files for restore.
        $DB->Clear();
        $DB->deleted_at = date('Y-m-d H:i:s');
        $DB->media_id = $mediaId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_media SET deleted_at = :deleted_at'
            . ' WHERE media_id = :media_id AND deleted_at IS NULL'
        );

        // Confirm the marker landed (Execute() is void under ERRMODE_WARNING).
        $DB->Clear();
        $DB->media_id = $mediaId;
        $check = $this->_firstRow($DB->DataSet(
            'SELECT deleted_at FROM ' . DB_PREFIX . 'cms_media WHERE media_id = :media_id LIMIT 1'
        ));
        if ($check === null || empty($check['deleted_at'])) {
            return false;
        }

        $this->_cmsAudit((int)$actorId, 'delete', 'media', $mediaId, (string)$row['scope_type'], (int)$row['scope_id']);
        return true;
    }

    /**
     * Restore a trashed media row (clear deleted_at).
     *
     * @param int         $mediaId
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @param string|null $scopeType optional ownership guard: only touch a row in this scope
     * @param int|null    $scopeId   optional ownership guard: scope owner id
     * @return bool
     */
    public function RestoreMedia($mediaId, $actorId = 0, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return false;
        }

        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT media_id, scope_type, scope_id, deleted_at FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id LIMIT 1'
        ));
        if ($row === null || empty($row['deleted_at'])) {
            return false;
        }

        // IDOR guard: refuse when the caller's scope doesn't own this row.
        if (!$this->_scopeOwns($row, $scopeType, $scopeId)) {
            return false;
        }

        $DB->Clear();
        $DB->media_id = $mediaId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_media SET deleted_at = NULL WHERE media_id = :media_id'
        );

        $this->_cmsAudit((int)$actorId, 'restore', 'media', $mediaId, (string)$row['scope_type'], (int)$row['scope_id']);
        return true;
    }

    /**
     * Permanently remove a TRASHED media row and unlink its files (empty-trash).
     * Only operates on rows already soft-deleted; refuses if still referenced.
     * The unlink is guarded so it can only ever remove paths inside cms-media/.
     *
     * @param int         $mediaId
     * @param int         $actorId   acting mundane_id (for the audit trail)
     * @param string|null $scopeType optional ownership guard: only touch a row in this scope
     * @param int|null    $scopeId   optional ownership guard: scope owner id
     * @return bool true when a trashed, owned, unreferenced row was purged
     */
    public function PurgeMedia($mediaId, $actorId = 0, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return false;
        }

        // Only purge rows already in the trash.
        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT media_id, path, thumb_path, scope_type, scope_id, deleted_at FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id LIMIT 1'
        ));
        if ($row === null || empty($row['deleted_at'])) {
            return false;
        }

        // IDOR guard: refuse when the caller's scope doesn't own this row.
        if (!$this->_scopeOwns($row, $scopeType, $scopeId)) {
            return false;
        }

        // Belt-and-suspenders: never purge something a live surface still uses.
        if ($this->_referenceCount($mediaId) > 0) {
            return false;
        }

        $DB->Clear();
        $DB->media_id = $mediaId;
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'cms_media WHERE media_id = :media_id');

        // Only unlink once the row is actually gone (Execute() is void).
        $DB->Clear();
        $DB->media_id = $mediaId;
        $stillThere = $this->_firstRow($DB->DataSet(
            'SELECT media_id FROM ' . DB_PREFIX . 'cms_media WHERE media_id = :media_id LIMIT 1'
        ));
        if ($stillThere !== null) {
            return false;
        }

        if (!empty($row['path'])) {
            $this->_safeUnlink((string)$row['path']);
        }
        if (!empty($row['thumb_path'])) {
            $this->_safeUnlink((string)$row['thumb_path']);
        }

        $this->_cmsAudit((int)$actorId, 'purge', 'media', $mediaId, (string)$row['scope_type'], (int)$row['scope_id']);
        return true;
    }

    /**
     * Ownership guard: does a fetched media row belong to the given scope?
     * Mirrors CmsNav::_ownsItem — a caller that passes a scope (non-null
     * $scopeType) may only touch rows whose scope_type/scope_id match, so a
     * kingdom manager can never mutate global or another org's media (IDOR).
     * A null $scopeType means "no ownership constraint" (trusted/legacy caller).
     *
     * @param array       $row       row with scope_type/scope_id columns
     * @param string|null $scopeType 'global'|'kingdom'|'park', or null to skip
     * @param int|null    $scopeId   scope owner id
     * @return bool true when the caller is allowed to act on the row
     */
    private function _scopeOwns($row, $scopeType, $scopeId)
    {
        if ($scopeType === null) {
            return true; // no ownership constraint requested
        }
        return $this->_normalizeScopeType((string)$row['scope_type'])
                === $this->_normalizeScopeType((string)$scopeType)
            && (int)$row['scope_id'] === (int)$scopeId;
    }

    /**
     * Count everywhere a media id is still referenced: page/post hero images,
     * site logos, and any block that embeds it (matched on the media_id value
     * inside fields_json). Used by DeleteMedia/PurgeMedia's where-used guard.
     *
     * @param int $mediaId
     * @return int total references (0 = safe to trash/purge)
     */
    private function _referenceCount($mediaId)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return 0;
        }

        // A media ref inside a block is stored as {"media_id":<id>, "key":"m<id>", ...};
        // match the numeric media_id bounded by a non-digit (or end) so 12 never
        // matches 123. REGEXP over fields_json; NULL never matches.
        $pattern = '"media_id"[[:space:]]*:[[:space:]]*' . $mediaId . '([^0-9]|$)';

        // Independent counts (summed) so a missing table in a partial schema
        // (e.g. ork_cms_site absent) can't zero the WHOLE guard and let a
        // still-referenced image be trashed — each source fails closed to 0
        // only for itself.
        $total = 0;
        $total += $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_page WHERE hero_media_id = :mid',
            $mediaId
        );
        $total += $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_post WHERE hero_media_id = :mid',
            $mediaId
        );
        $total += $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_site WHERE logo_media_id = :mid',
            $mediaId
        );
        $total += $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_block WHERE fields_json REGEXP :pat',
            null,
            $pattern
        );

        return $total;
    }

    /**
     * Run a single COUNT(*) AS c query bound with either an int :mid or a
     * string :pat, returning the count (0 on any failure). Helper for
     * _referenceCount so each source is isolated.
     */
    private function _countOne($sql, $mid = null, $pat = null)
    {
        global $DB;

        $DB->Clear();
        if ($mid !== null) {
            $DB->mid = (int)$mid;
        }
        if ($pat !== null) {
            $DB->pat = (string)$pat;
        }
        $row = $this->_firstRow($DB->DataSet($sql));
        return ($row !== null && isset($row['c'])) ? (int)$row['c'] : 0;
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

        // GetLastInsertId() is unreliable on this stack (a failed INSERT returns
        // a stale prior id). Read the row back by its `path` — which carries a
        // crypto-random unique component (_uniqueBase), so it identifies exactly
        // this INSERT. Returns 0 when the row didn't land; Upload() treats 0 as
        // failure and cleans up the orphaned files.
        $DB->Clear();
        $DB->path = isset($cols['path']) ? (string)$cols['path'] : '';
        $check = $this->_firstRow($DB->DataSet(
            'SELECT media_id FROM ' . DB_PREFIX . 'cms_media WHERE path = :path LIMIT 1'
        ));
        return ($check !== null && isset($check['media_id'])) ? (int)$check['media_id'] : 0;
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
                // imagescale() already produced the resampled pixels; only
                // enable alpha preservation on the result. Do NOT call
                // _preserveAlpha() here — its flood-fill would erase the
                // scaled content and yield a blank thumbnail.
                @imagealphablending($scaled, false);
                @imagesavealpha($scaled, true);
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
                // No webp support in this GD build: writing JPEG bytes to a
                // .webp path produces an undecodable asset (server sends
                // Content-Type: image/webp over JPEG payload). Signal failure
                // so Upload() cleanly aborts rather than storing a corrupt file.
                return false;
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
