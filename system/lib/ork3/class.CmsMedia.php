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
    /**
     * Ceiling on the id list FilterOwnedIds will accept, so a future caller can't
     * hand this public API 100k ids and build an unbounded IN(). The only current
     * caller (CmsAjax::_parseIdList) already caps at 200; this is the lib's own
     * code-controlled guard, in the spirit of _clampLimit. Over-cap fails closed.
     */
    public const MAX_FILTER_IDS = 1000;

    /**
     * Per-org storage quota, in bytes (512 MB).
     *
     * This is a MULTI-TENANT system: every kingdom and park uploads into the same
     * filesystem and the same table, so without a per-scope ceiling one org
     * uploading a season of event photos is silently everyone else's problem —
     * the disk fills and every other org's uploads start failing with no
     * indication of who caused it or why.
     *
     * Counts LIVE media only (deleted_at IS NULL): a soft-deleted image is
     * recoverable but should not hold an org hostage, and PurgeMedia is the
     * documented way to actually reclaim it.
     *
     * Global scope is exempt — it is the Amtgard site itself, not a tenant.
     */
    public const MAX_SCOPE_BYTES = 536870912;

    /**
     * Why the last Upload() returned null. Upload() reports every rejection the
     * same way (null), which leaves the caller unable to tell "that is not an
     * image" from "your org is out of space" — two failures a user must respond
     * to completely differently. Read immediately after an Upload() that returned
     * null; not meaningful otherwise.
     *
     * @var string
     */
    public $LastError = '';

    /**
     * Accessor for $LastError.
     *
     * The model layer reaches this lib through APIModel, which implements
     * __call() but NOT __get() — so `$this->CmsMedia->LastError` from a model
     * read an undeclared property and always yielded '', leaving the CmsAjax
     * 'quota_exceeded' branch permanently unreachable. A method call routes
     * through __call and reaches the real value. PHP allows a method and a
     * property to share a name, so the public property stays exactly as-is for
     * the direct (non-proxied) callers that already set/read it.
     *
     * @return string '' when the last Upload() did not record a reason
     */
    public function LastError()
    {
        return (string)$this->LastError;
    }

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

    /**
     * C4/#14: mid-size "display" rendition max width in pixels (~1800px, inside
     * the 1600–2000 band). Written as WebP alongside the thumb so front-door
     * partials can serve a right-sized, well-compressed hero image
     * ($ref['display'] ?? $ref['src']) instead of the full-resolution original.
     */
    private static $DISPLAY_MAX_W = 1800;

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
        // Reset first: LastError is read after a null return, so a value left over
        // from a previous upload in the same request would misreport this one.
        $this->LastError = '';

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
            $this->LastError = 'too_large';
            return null;
        }

        // Per-org storage quota. Checked here — before any decode, resize or file
        // write — so an over-quota upload costs nothing and leaves no orphan on
        // disk. Global scope is the Amtgard site itself, not a tenant, so it is
        // not metered.
        $quotaScopeType = (string)($scope['type'] ?? 'global');
        $quotaScopeId   = (int)($scope['id'] ?? 0);
        if ($quotaScopeType !== 'global' && $quotaScopeId > 0) {
            $used = $this->ScopeUsageBytes($quotaScopeType, $quotaScopeId);
            if (($used + $bytes) > self::MAX_SCOPE_BYTES) {
                $this->LastError = 'quota_exceeded';
                return null;
            }
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

        // C4/#14: mid-size (~1800px) WebP "display" rendition, derived from the
        // stored path so ToMediaRef can locate it without a schema column. WebP-
        // only: when this GD build lacks imagewebp we simply skip it and the ref
        // omits the "display" key (partials fall back to src). Non-fatal on any
        // failure — never abort the upload over an optional rendition.
        $relDisplay  = $this->_displayRelForPath($relPath);
        $diskDisplay = rtrim(DIR_ASSETS, '/') . '/' . $relDisplay;
        if ($relDisplay !== '' && function_exists('imagewebp')) {
            $display = $this->_makeDisplay($src, $width, $height);
            if ($display !== false) {
                $this->_writeImage($display, $diskDisplay, 'webp');
                imagedestroy($display);
            }
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
            if ($relDisplay !== '' && is_file($diskDisplay)) {
                @unlink($diskDisplay);
            }
            return null;
        }

        // Best-effort audit (never fail the upload if the hook is unreachable).
        $this->_auditUpload($mediaId, $bytes, $mime, (int)$uploadedBy, $scope);

        // Build the success payload from the columns we just wrote (plus the
        // new id) — the row is already fully known in memory, so a SELECT to
        // read back our own INSERT is wasted work. Mirror GetMedia()'s shape.
        $row = array_merge($cols, array('media_id' => $mediaId));

        return $this->_decorateWithRef($row, $relPath, $this->_thumbPathOr($thumbStored, $relPath));
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
        // Falls back to the original when no thumb was generated.
        $thumbPath = $this->_thumbPathOr($row['thumb_path'] ?? null, $path);

        $ref = array(
            'key'      => 'm' . $id,
            'media_id' => $id,
            'src'      => $this->_url($path),
            'thumb'    => $this->_url($thumbPath),
            'alt'      => isset($row['alt']) ? (string)$row['alt'] : '',
            'focal'    => (isset($row['focal']) && $row['focal'] !== '') ? (string)$row['focal'] : '50% 50%',
        );

        // C4/#14: expose the mid-size WebP display rendition when it exists on
        // disk (derived deterministically from the stored path — no DB column).
        // When absent (older asset, or a GD build without WebP) the key is
        // OMITTED so partials fall back to 'src' ($ref['display'] ?? $ref['src']).
        $displayRel = $this->_displayRelForPath($path);
        if ($displayRel !== '' && $this->_displayExists($displayRel)) {
            $ref['display'] = $this->_url($displayRel);
        }

        return $ref;
    }

    /**
     * The thumbnail path to serve for a row, falling back to the original when
     * no thumb was generated. Canonicalized on the STRICTEST of the historical
     * forms: a thumb_path that is absent, literal NULL, or '' all fall back.
     * (yapo stores an absent thumb as NULL; '' can appear on hand-edited rows.)
     *
     * @param mixed  $thumbPath raw thumb_path column value (may be null)
     * @param string $path      the original stored relative path
     * @return string
     */
    private function _thumbPathOr($thumbPath, $path)
    {
        return ($thumbPath !== null && $thumbPath !== '')
            ? (string)$thumbPath
            : (string)$path;
    }

    /**
     * Add the browser URLs + the media-ref keys to a raw media row.
     *
     * MERGE ORDER IS DELIBERATE: only ref-ONLY keys are written back, so a raw
     * DB column of the same name is never clobbered (notably 'alt' and 'focal',
     * which exist on the row and whose ref forms carry defaults). Shared by
     * Upload (payload built from the columns just INSERTed) and GetMedia (row
     * read back from the table) — the two callers whose output shape is
     * documented to match. NOT used by _mediaListRow, which is its own shared
     * helper with a different (ref-first) shape.
     *
     * @param array  $row       raw media row (or the column map just written)
     * @param string $path      stored relative path of the original
     * @param string $thumbPath stored relative path of the thumb (or the original)
     * @return array the row plus {url,thumb_url,key,src,thumb,focal}
     */
    private function _decorateWithRef(array $row, $path, $thumbPath)
    {
        $row['url']       = $this->_url($path);
        $row['thumb_url'] = $this->_url($thumbPath);

        $ref = $this->ToMediaRef($row);
        // Don't clobber raw columns; only add the ref-only keys.
        $row['key']   = $ref['key'];
        $row['src']   = $ref['src'];
        $row['thumb'] = $ref['thumb'];
        $row['focal'] = $ref['focal'];

        return $row;
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
    public function ListMedia($scope = null, $limit = 200, $search = null, $offset = 0)
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

        // SQL-level windowed paging: LIMIT <offset>, <count>. Both operands are
        // ints (offset sanitized here, count via _clampLimit) so no injection.
        // This replaces the caller's old over-fetch+slice, which collided with
        // _clampLimit's ceiling and broke has_more past ~1000 rows.
        $offset   = max(0, (int)$offset);
        $limitSql = ' LIMIT ' . $offset . ', ' . ($this->_clampLimit($limit));

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
     * Total LIVE bytes stored by one org scope.
     *
     * Trashed rows are excluded deliberately: soft-deleted media is recoverable,
     * and counting it would let an org exhaust its quota with content it has
     * already asked to remove, with no way out except a purge it may not be able
     * to trigger.
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId
     * @return int bytes (0 when nothing is stored)
     */
    public function ScopeUsageBytes($scopeType, $scopeId)
    {
        global $DB;

        $DB->Clear();
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;

        $r = $DB->DataSet(
            'SELECT COALESCE(SUM(bytes), 0) AS used FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
            . ' AND deleted_at IS NULL'
        );
        // DataSet() needs an explicit Next() before any field read.
        return ($r && $r->Next()) ? (int)$r->used : 0;
    }

    /**
     * The storage ceiling for a scope, in bytes. 0 means "not metered" (global).
     *
     * @param string $scopeType
     * @return int
     */
    public function ScopeQuotaBytes($scopeType)
    {
        return ((string)$scopeType === 'global') ? 0 : self::MAX_SCOPE_BYTES;
    }

    /**
     * IDOR guard: reduce a caller-supplied list of media ids to the subset that
     * actually exists, is NOT trashed, and belongs to the given scope. Anything
     * global, another org's, trashed, or nonexistent is dropped silently — the
     * caller acts only on what comes back.
     *
     * Ids are int-cast into the IN() list (never interpolated raw — note
     * mysql_real_escape_string is a no-op shim in this codebase); the scope is
     * bound. An empty/all-invalid input short-circuits without a query, and an
     * over-cap list (see MAX_FILTER_IDS) fails CLOSED so a runaway caller can't
     * build an unbounded IN() list.
     *
     * NOTE: unlike the sibling scope-taking methods (DeleteMedia, RestoreMedia,
     * PurgeMedia, _scopeOwns), a null $scopeType is NOT accepted here as "no
     * ownership constraint" — $scopeType is a typed non-nullable string, so
     * passing null is a fatal TypeError. This is an IDOR filter; it always
     * requires a concrete scope.
     *
     * @param array  $ids       candidate media ids (max MAX_FILTER_IDS)
     * @param string $scopeType 'global' | 'kingdom' | 'park' (never null)
     * @param int    $scopeId   scope owner id (0 for global)
     * @return array the owned subset, as ints, in input order
     */
    public function FilterOwnedIds(array $ids, string $scopeType, int $scopeId): array
    {
        global $DB;

        // Fail closed on an oversized list rather than building a giant IN().
        if (count($ids) > self::MAX_FILTER_IDS) {
            return array();
        }

        // Int-cast + dedupe (hash-keyed, not an O(n^2) in_array scan), dropping
        // non-positive ids.
        $seen  = array();
        $clean = array();
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $clean[] = $id;
            }
        }
        if (empty($clean)) {
            return array();
        }

        $DB->Clear();
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;

        $sql = 'SELECT media_id FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id IN (' . implode(',', $clean) . ')'
            . ' AND scope_type = :scope_type AND scope_id = :scope_id'
            . ' AND deleted_at IS NULL';

        $owned = array();
        foreach ($this->_eachRow($DB->DataSet($sql)) as $row) {
            $owned[(int)$row['media_id']] = true;
        }

        // Preserve the caller's ordering (hash lookup, not an O(n^2) in_array scan).
        $out = array();
        foreach ($clean as $id) {
            if (isset($owned[$id])) {
                $out[] = $id;
            }
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
        $thumbPath = $this->_thumbPathOr($row['thumb_path'] ?? null, $path);

        // Augment with browser URLs + the media-ref shape (merged in so callers
        // get both the raw columns and the ready-to-use ref keys; ref-only keys
        // are added, raw columns are never clobbered).
        $row = $this->_decorateWithRef($row, $path, $thumbPath);

        // media_id comes back from PDO as a string; callers compare it as an int.
        $row['media_id'] = (int)$row['media_id'];

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
     * A 'filename' key RENAMES the display filename only (the on-disk path is an
     * opaque unique base and is never touched); the canonical extension of the
     * stored asset is preserved so the display name can't misrepresent the type.
     * An empty/blank filename is ignored (a rename can't clear the name to '').
     *
     * @param int         $mediaId
     * @param array       $data      subset: 'alt' (string, '' = decorative), 'title' (string), 'filename' (string, rename)
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

        // Confirm the row exists and is not trashed (also grabs scope for audit +
        // the current filename/path so a rename can preserve the canonical ext).
        $DB->Clear();
        $DB->media_id = $mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT media_id, filename, path, scope_type, scope_id FROM ' . DB_PREFIX . 'cms_media'
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
        if (array_key_exists('filename', $data)) {
            $newName = trim((string)$data['filename']);
            // A blank rename is a no-op (never let a display name become empty).
            if ($newName !== '') {
                // Preserve the stored asset's canonical extension so a rename can't
                // lie about the type; fall back to the opaque path, then 'jpg'.
                $ext = $this->_extFromName((string)($row['filename'] ?? ''));
                if ($ext === '') {
                    $ext = $this->_extFromName((string)($row['path'] ?? ''));
                }
                if ($ext === '') {
                    $ext = 'jpg';
                }
                $set[] = 'filename = :filename';
                $DB->filename = $this->_safeFilename($newName, $ext);
            }
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
     * @param bool        $override  #68: purge is IRREVERSIBLE (hard DELETE + file
     *                               unlink). Left false, a WIDE reference scan (any
     *                               scope, incl. TinyMCE embeds) that finds a
     *                               remaining reference BLOCKS the purge. Pass true
     *                               only to force the destructive unlink past a
     *                               detected reference — an explicit acknowledgement
     *                               the caller must opt into.
     * @return bool true when a trashed, owned row was purged
     */
    public function PurgeMedia($mediaId, $actorId = 0, $scopeType = null, $scopeId = null, $override = false)
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

        // #68 belt-and-suspenders: a purge is IRREVERSIBLE (hard DELETE + file
        // unlink), so — unlike the reversible soft-delete guard, which is
        // scope-bounded for perf (#69) — it uses the WIDE reference scan (every
        // scope, plus TinyMCE-embedded <img src> URLs). Any remaining reference
        // blocks the purge UNLESS the caller passes an explicit $override
        // acknowledging the destructive unlink.
        // Trashed page/post owners STILL count here (false): RestorePage can bring
        // a trashed owner back and DeletePage does not clear hero_media_id, so a
        // hard DELETE + unlink now would strand a restored live page on a missing
        // file. Once the trashed owner is itself purged, this media becomes purgeable.
        if (!$override) {
            $fk = $this->_fkReferenceCount($mediaId, false);
            $refs = ($fk > 0)
                ? $fk
                : $this->_blockReferenceCount($mediaId, (string)$row['path'], null, null);
            if ($refs > 0) {
                return false;
            }
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
        // C4/#14: also unlink the derived mid-size WebP display rendition, if any.
        $relDisplay = $this->_displayRelForPath((string)($row['path'] ?? ''));
        if ($relDisplay !== '') {
            $this->_safeUnlink($relDisplay);
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
     * Public where-used breakdown for the media library UI: how many pages,
     * posts, site logos, and content blocks still reference a media id, plus the
     * total. Surfaced by CmsAjax/mediausage so an officer can see whether an
     * image is in use BEFORE deleting (and so the delete confirm can warn).
     *
     * Unlike _referenceCount (the delete/purge hot-path guard, which short-
     * circuits on the cheap FK checks and skips the block REGEXP scan), this
     * ALWAYS runs every source because the UI wants the full per-source counts.
     * It is only ever called on demand for a single media id — never in a loop
     * over the library — so the unbounded block scan cost is acceptable here.
     *
     * @param int $mediaId
     * @return array{pages:int,posts:int,logos:int,blocks:int,total:int}
     */
    public function ReferenceUsage($mediaId)
    {
        $mediaId = (int)$mediaId;
        $out = array('pages' => 0, 'posts' => 0, 'logos' => 0, 'blocks' => 0, 'total' => 0);
        if ($mediaId <= 0) {
            return $out;
        }

        // Only LIVE owners are counted, matching _fkReferenceCount: a hero image on
        // a trashed page/post is gone from the public site, so reporting it here
        // would show a phantom "still in use" count (and the single-card Trash
        // confirm in Cms_media.tpl pre-checks this total, dead-ending the officer
        // on content DeleteMedia would happily trash).
        $fk = $this->_fkCounts($mediaId, true);
        $out['pages'] = $fk['pages'];
        $out['posts'] = $fk['posts'];
        $out['logos'] = $fk['logos'];
        // Block embeds: the media-ref media_id AND (#68) any TinyMCE-embedded
        // <img src> whose URL carries the asset's path (so a rich-text embed
        // counts as used). WIDE (all scopes) — the UI wants the full picture.
        $out['blocks'] = $this->_blockReferenceCount($mediaId, $this->_mediaPath($mediaId), null, null);

        $out['total'] = $out['pages'] + $out['posts'] + $out['logos'] + $out['blocks'];
        return $out;
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
        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return 0;
        }

        // Cheap, indexed FK checks first (page/post hero, site logo). DeleteMedia
        // only tests this against > 0, so if any FK matches we return immediately
        // and SKIP the block scan.
        $fk = $this->_fkReferenceCount($mediaId, true);
        if ($fk > 0) {
            return $fk;
        }

        // #69: SCOPE-BOUND block-embed scan. Soft-delete is REVERSIBLE (restore
        // brings the asset back), so narrowing the scan to blocks whose owning
        // page/post shares this media's own scope is a safe perf win here — it
        // bounds the fields_json scan via the owner→page/post join instead of a
        // full-table REGEXP. The IRREVERSIBLE PurgeMedia deliberately uses the
        // WIDE scan (see there) so a cross-scope/forged embed still blocks the
        // hard unlink. Also matches TinyMCE-embedded <img src> URLs (#68).
        $meta = $this->_mediaRefMeta($mediaId);
        return $this->_blockReferenceCount(
            $mediaId,
            $meta['path'],
            $meta['scope_type'],
            $meta['scope_id']
        );
    }

    /**
     * Sum of the cheap indexed FK references: page/post hero + site logo.
     * Independent counts (summed) so a missing table in a partial schema (e.g.
     * ork_cms_site absent) can't zero the WHOLE guard — each source fails closed
     * to 0 only for itself. Shared by _referenceCount (scoped) and PurgeMedia (wide).
     *
     * $liveOwnersOnly mirrors the scoped-vs-wide split the block scan already uses
     * (see _referenceCount and PurgeMedia), for the same reversible-vs-irreversible
     * reason:
     *  - true (the REVERSIBLE soft-delete guard): a hero image on a soft-deleted
     *    (trashed) page/post is not a real reference, and counting it would strand
     *    the asset forever — DeleteMedia would refuse to trash it, so PurgeMedia
     *    (which requires the row to already be trashed) could never reach it either.
     *  - false (the IRREVERSIBLE purge guard): trashed owners STILL count. A trashed
     *    page can be restored (CmsPage::RestorePage) and DeletePage does not clear
     *    hero_media_id, so hard-DELETEing + unlinking the asset would leave a live
     *    restored page pointing at a missing file. The asset becomes purgeable once
     *    the trashed page/post is itself purged.
     *
     * @param int  $mediaId
     * @param bool $liveOwnersOnly true = ignore trashed page/post owners
     * @return int
     */
    private function _fkReferenceCount($mediaId, $liveOwnersOnly = true)
    {
        return array_sum($this->_fkCounts($mediaId, $liveOwnersOnly));
    }

    /**
     * The per-source breakdown behind _fkReferenceCount: page heroes, post
     * heroes, and site logos, counted independently so a missing table in a
     * partial schema fails closed to 0 for THAT SOURCE ONLY (see _countOne)
     * rather than zeroing the whole guard.
     *
     * $liveOwnersOnly carries the same reversible-vs-irreversible meaning
     * documented on _fkReferenceCount — passing it backwards either strands an
     * asset forever (counting trashed owners on the soft-delete path) or unlinks
     * a file a restorable page still points at (ignoring them on the purge path).
     *
     * @param int  $mediaId
     * @param bool $liveOwnersOnly true = ignore trashed page/post owners
     * @return array{pages:int,posts:int,logos:int}
     */
    private function _fkCounts($mediaId, $liveOwnersOnly = true)
    {
        $out = array('pages' => 0, 'posts' => 0, 'logos' => 0);

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return $out;
        }

        $liveOnlySql = $liveOwnersOnly ? ' AND deleted_at IS NULL' : '';
        $out['pages'] = $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE hero_media_id = :mid' . $liveOnlySql,
            $mediaId
        );
        $out['posts'] = $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_post'
            . ' WHERE hero_media_id = :mid' . $liveOnlySql,
            $mediaId
        );
        // ork_cms_site has NO deleted_at column (see db-migrations/2026-07-01-cms-site.sql),
        // so a site logo reference is always live — no soft-delete filter here.
        $out['logos'] = $this->_countOne(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_site WHERE logo_media_id = :mid',
            $mediaId
        );
        return $out;
    }

    /**
     * Count blocks that reference $mediaId. A ref is either the media_id value
     * inside fields_json ({"media_id":<id>, ...} — matched bounded by a non-digit
     * so 12 never matches 123) OR (#68) a TinyMCE-embedded <img src> whose URL
     * carries the asset's opaque path base (shared by the original/thumb/display
     * renditions), so a rich-text embed counts as "in use".
     *
     * When $scopeType is null the scan is WIDE (every block in every scope). When
     * a scope is supplied (#69) the scan is bounded to blocks whose owning
     * page/post shares that scope, via the polymorphic owner join — ork_cms_block
     * carries only owner_type/owner_id, and cms_page/cms_post carry the scope.
     *
     * @param int         $mediaId
     * @param string      $path      the media's stored relative path (for the embed LIKE)
     * @param string|null $scopeType null = wide; else bound to this scope
     * @param int|null    $scopeId   scope owner id (with $scopeType)
     * @return int matching block count
     */
    private function _blockReferenceCount($mediaId, $path, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return 0;
        }

        $pattern = '"media_id"[[:space:]]*:[[:space:]]*' . $mediaId . '([^0-9]|$)';

        $DB->Clear();
        $DB->pat = $pattern;

        $match = 'b.fields_json REGEXP :pat';
        $base = $this->_pathBase((string)$path);
        if ($base !== '') {
            // Escape LIKE metacharacters in the path base (defensive; the opaque
            // base is hex/digits/slash so it carries none in practice).
            $match .= ' OR b.fields_json LIKE :emb';
            $DB->emb = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $base) . '%';
        }

        if ($scopeType === null) {
            // WIDE: every block, every scope (PurgeMedia + the where-used UI).
            $sql = 'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_block b'
                . ' WHERE (' . $match . ')';
        } else {
            // #69: bound to blocks whose owning page/post shares the media's scope.
            // COALESCE picks the one joined owner's scope per row (the other join
            // is NULL); an orphan block whose owner was hard-deleted (both NULL)
            // can never render, so excluding it is safe. The joins also require
            // the owner to be live (deleted_at IS NULL) — a block on a TRASHED
            // page/post is not a real reference, and counting it would strand the
            // media permanently (it could never be trashed, so never purged).
            $sql = 'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_block b'
                . ' LEFT JOIN ' . DB_PREFIX . 'cms_page pg'
                . " ON b.owner_type = 'page' AND pg.page_id = b.owner_id"
                . ' AND pg.deleted_at IS NULL'
                . ' LEFT JOIN ' . DB_PREFIX . 'cms_post po'
                . " ON b.owner_type = 'post' AND po.post_id = b.owner_id"
                . ' AND po.deleted_at IS NULL'
                . ' WHERE (' . $match . ')'
                . ' AND COALESCE(pg.scope_type, po.scope_type) = :st'
                . ' AND COALESCE(pg.scope_id, po.scope_id) = :sid';
            $DB->st  = $this->_normalizeScopeType((string)$scopeType);
            $DB->sid = (int)$scopeId;
        }

        $row = $this->_firstRow($DB->DataSet($sql));
        return ($row !== null && isset($row['c'])) ? (int)$row['c'] : 0;
    }

    /**
     * Fetch a media row's path + scope (for the scope-bound where-used scan).
     * Returns defaults ('' path, 'global'/0 scope) when the row is absent.
     */
    private function _mediaRefMeta($mediaId)
    {
        global $DB;

        $DB->Clear();
        $DB->media_id = (int)$mediaId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT path, scope_type, scope_id FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id LIMIT 1'
        ));
        return array(
            'path'       => ($row !== null && isset($row['path'])) ? (string)$row['path'] : '',
            'scope_type' => ($row !== null && isset($row['scope_type'])) ? (string)$row['scope_type'] : 'global',
            'scope_id'   => ($row !== null && isset($row['scope_id'])) ? (int)$row['scope_id'] : 0,
        );
    }

    /**
     * Just the stored relative path of a media row ('' when absent). Thin wrapper
     * over _mediaRefMeta for the wide where-used scan.
     */
    private function _mediaPath($mediaId)
    {
        $meta = $this->_mediaRefMeta($mediaId);
        return $meta['path'];
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
     * Build a downscaled copy of $src no wider than $maxW (aspect ratio
     * preserved). Returns a NEW GD resource (caller destroys it) or false when
     * the source dimensions are unusable / GD refuses the canvas. When the
     * source is already within the cap we still return a full-size copy, so the
     * rendition file is always written.
     *
     * Shared body of _makeThumb (THUMB_MAX_W) and _makeDisplay (DISPLAY_MAX_W).
     *
     * @param resource|\GdImage $src    decoded source image
     * @param int               $width  source width
     * @param int               $height source height
     * @param int               $maxW   max width of the rendition
     * @return resource|\GdImage|false
     */
    private function _makeScaled($src, $width, $height, $maxW)
    {
        $width  = (int)$width;
        $height = (int)$height;
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $maxW    = (int)$maxW;
        $targetW = $width;
        $targetH = $height;
        if ($width > $maxW) {
            $scale   = $maxW / $width;
            $targetW = $maxW;
            $targetH = max(1, (int)round($height * $scale));
        }

        // Prefer imagescale when available (cleaner + simpler).
        if (function_exists('imagescale')) {
            $scaled = @imagescale($src, $targetW, $targetH);
            if ($scaled !== false) {
                // imagescale() already produced the resampled pixels; only
                // enable alpha preservation on the result. Do NOT call
                // _preserveAlpha() here — its flood-fill would erase the
                // scaled content and yield a blank rendition.
                @imagealphablending($scaled, false);
                @imagesavealpha($scaled, true);
                return $scaled;
            }
        }

        // Manual resample fallback (a fresh canvas, so the flood-fill in
        // _preserveAlpha is correct here — there is nothing to erase yet).
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
     * Build a downscaled copy of $src no wider than THUMB_MAX_W (preserving
     * aspect ratio). Returns a NEW GD resource (caller destroys it) or false
     * if no thumbnail is needed/possible. When the source is already within
     * the cap we still return a copy so the thumb file is always written.
     */
    private function _makeThumb($src, $width, $height)
    {
        return $this->_makeScaled($src, $width, $height, self::$THUMB_MAX_W);
    }

    /**
     * C4/#14: build the mid-size "display" copy of $src no wider than
     * DISPLAY_MAX_W (aspect preserved). Returns a NEW GD resource (caller
     * destroys it) or false. When the source is already within the cap we still
     * return a full-size copy so a WebP display is always produced (the WebP
     * re-encode alone is a worthwhile bandwidth win over the original).
     */
    private function _makeDisplay($src, $width, $height)
    {
        return $this->_makeScaled($src, $width, $height, self::$DISPLAY_MAX_W);
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
     * The stored relative path with its extension stripped — the opaque base
     * (cms-media/{yyyymm}/{unique}) shared by the original, thumb, and display
     * renditions. Used to derive the display path (C4/#14) and to build the
     * embedded-URL where-used LIKE (#68). '' for an empty path.
     */
    private function _pathBase($relPath)
    {
        $relPath = (string)$relPath;
        if ($relPath === '') {
            return '';
        }
        $slash = strrpos($relPath, '/');
        $dot   = strrpos($relPath, '.');
        // Only treat a dot in the LAST path segment as an extension separator.
        if ($dot !== false && ($slash === false || $dot > $slash)) {
            return substr($relPath, 0, $dot);
        }
        return $relPath;
    }

    /**
     * C4/#14: the derived relative path of the mid-size WebP display rendition
     * for a stored original path (…/{unique}.jpg → …/{unique}_display.webp).
     * Deterministic so it needs no DB column. '' when the path is empty.
     */
    private function _displayRelForPath($relPath)
    {
        $base = $this->_pathBase($relPath);
        return ($base === '') ? '' : $base . '_display.webp';
    }

    /**
     * C4/#14: does the display rendition exist on disk? Guards ToMediaRef so the
     * 'display' key is only exposed when the file is really present (older assets
     * / no-WebP GD builds fall back to 'src').
     */
    private function _displayExists($relDisplay)
    {
        $relDisplay = (string)$relDisplay;
        if ($relDisplay === '') {
            return false;
        }
        $disk = rtrim(DIR_ASSETS, '/') . '/' . ltrim($relDisplay, '/');
        return is_file($disk);
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
     * Extract a lowercase file extension from a filename or stored path, or ''
     * when none/unrecognized. Used by Update() to pin a rename to the stored
     * asset's real extension (a rename must not change the type on disk).
     */
    private function _extFromName($name)
    {
        $name = basename(str_replace('\\', '/', (string)$name));
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === strlen($name) - 1) {
            return '';
        }
        $ext = strtolower(substr($name, $dot + 1));
        return preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : '';
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
