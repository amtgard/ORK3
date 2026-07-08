<?php

/**
 * Seed the amtgard.com content replication into the CMS (global scope, front door).
 *
 * Reads extracted page specs + downloaded assets from a staging dir and creates
 * one published global CMS page per spec, re-hosting images into the CMS media
 * library and self-hosting linked PDFs under assets/cms-docs/.
 *
 * Idempotent: non-system pages are deleted by slug then recreated; media is
 * deduped by a deterministic filename (amtg-<slug>-<file>); docs deduped by name.
 * Parents are created before children so parent_id resolves.
 *
 * Media src/thumb are stored ROOT-RELATIVE (/assets/...) so content is
 * host-agnostic (matches the exemplar seed's $IMG convention).
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-seed-amtgard.php \
 *     /var/www/ork.amtgard.com/db-migrations/.amtgard-assets
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$STG = isset($argv[1]) ? rtrim($argv[1], '/') : (__DIR__ . '/.amtgard-assets');
if (!is_dir("$STG/specs")) {
    fwrite(STDERR, "No specs dir at $STG/specs\n");
    exit(1);
}

chdir('/var/www/ork.amtgard.com/orkui');
define('DONOTWEBSERVICE', true);
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}
ob_start();
require('/var/www/ork.amtgard.com/startup.php');
ob_end_clean();
@ini_set('memory_limit', '1024M');
@set_time_limit(0);
// Host-agnostic relative internal-link base (matches exemplar seed).
if (!defined('UIR')) {
    define('UIR', '/orkui/index.php?Route=');
}

global $DB;
$cms   = new CmsPage();
$media = new CmsMedia();

// First row of a YapoMysql DataSet result as an assoc array, or null.
// (DataSet returns a result object driven by CurrentFieldSet()/Next(); the
// pre-fetch is unreliable so we probe the pre-fetched row then Next().)
$firstRow = function ($r) {
    if (!$r) {
        return null;
    }
    $f = $r->CurrentFieldSet();
    if (!empty($f)) {
        return $f;
    }
    while ($r->Next()) {
        $f = $r->CurrentFieldSet();
        if (!empty($f)) {
            return $f;
        }
    }
    return null;
};
$now   = date('Y-m-d H:i:s');
$by    = 1; // super-admin seed author / media uploader

// Parents before children so parent_id targets exist.
$order = array(
    'about', 'join', 'programs', 'media', 'resources',
    'mission', 'staff', 'volunteers', 'learn-the-basics', 'start-a-chapter',
    'foodfight', 'olympiad', 'galleries', 'writing', 'documents',
);

// --- helpers -------------------------------------------------------------

// Strip scheme+host -> root-relative path (+query) so stored src is portable.
$toRel = function ($url) {
    if (!is_string($url) || $url === '') {
        return $url;
    }
    $p = parse_url($url);
    if (empty($p['path'])) {
        return $url;
    }
    return $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
};

// Return image bytes, downscaling to <=2400px / re-encoding when the source is
// oversized (CmsMedia::Upload rejects >8MB and guards against decompression
// bombs). GIFs are passed through untouched (avoid reflowing animations).
$prepBytes = function ($abs) {
    $raw = file_get_contents($abs);
    $info = @getimagesize($abs);
    if (!$info) {
        return $raw;
    }
    $mime = $info['mime'];
    $needScale = (strlen($raw) > 7500000) || (max($info[0], $info[1]) > 2400);
    if (!$needScale || $mime === 'image/gif') {
        return $raw;
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return $raw;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = 2400 / max($w, $h);
    if ($scale < 1) {
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }
    ob_start();
    if ($mime === 'image/png') {
        imagepng($src);
    } elseif ($mime === 'image/webp') {
        imagewebp($src);
    } else {
        imagejpeg($src, null, 85);
    }
    $bytes = ob_get_clean();
    imagedestroy($src);
    return $bytes;
};

// Upload one staging image (dedup by deterministic filename), return a
// root-relative media ref {key,media_id,src,thumb,alt,focal} or null.
$uploadImage = function ($slug, $file, $alt) use ($media, $DB, $by, $STG, $toRel, $prepBytes, $firstRow) {
    $abs = "$STG/assets/$slug/$file";
    if (!is_file($abs)) {
        fwrite(STDERR, "  missing image $slug/$file\n");
        return null;
    }
    $fname = 'amtg-' . $slug . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file);
    // Dedup: reuse an existing global media row with this filename.
    $DB->Clear();
    $DB->filename = $fname;
    $hit = $firstRow($DB->DataSet(
        'SELECT media_id, path, thumb_path FROM ' . DB_PREFIX . 'cms_media '
        . 'WHERE filename = :filename AND scope_type = \'global\' AND scope_id = 0 '
        . 'AND deleted_at IS NULL LIMIT 1'
    ));
    if ($hit && (int) $hit['media_id'] > 0) {
        $path  = $hit['path'];
        $thumb = !empty($hit['thumb_path']) ? $hit['thumb_path'] : $hit['path'];
        return array('key' => 'm' . (int) $hit['media_id'], 'media_id' => (int) $hit['media_id'],
            'src' => '/assets/' . $path, 'thumb' => '/assets/' . $thumb,
            'alt' => (string) $alt, 'focal' => '50% 50%');
    }
    $row = $media->Upload(
        base64_encode($prepBytes($abs)),
        $fname,
        (string) $alt,
        $by,
        array('type' => 'global', 'id' => 0)
    );
    if (empty($row['media_id'])) {
        fwrite(STDERR, "  upload FAILED $slug/$file\n");
        return null;
    }
    return array('key' => 'm' . (int) $row['media_id'], 'media_id' => (int) $row['media_id'],
        'src' => $toRel($row['src']), 'thumb' => $toRel(!empty($row['thumb']) ? $row['thumb'] : $row['src']),
        'alt' => (string) $alt, 'focal' => '50% 50%');
};

// Copy a staging PDF/doc into assets/cms-docs (dedup by safe name); return
// {label,url} (root-relative) or null. Interim until in-CMS doc storage lands.
$docsDir = DIR_ASSETS . 'cms-docs';
if (!is_dir($docsDir)) {
    @mkdir($docsDir, 0775, true);
}
$copyDoc = function ($slug, $file, $label, $name) use ($STG, $docsDir) {
    $abs = "$STG/assets/$slug/$file";
    if (!is_file($abs)) {
        fwrite(STDERR, "  missing doc $slug/$file\n");
        return null;
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name ?: basename($file));
    if (!is_file("$docsDir/$safe")) {
        @copy($abs, "$docsDir/$safe");
    }
    return array('label' => $label ?: ($name ?: 'Download'), 'url' => '/assets/cms-docs/' . $safe);
};

// Resolve a raw spec block into a persisted CMS block (assets -> refs/urls,
// internal-link placeholders -> UIR).
$resolveBlock = function ($slug, $b) use ($uploadImage, $copyDoc) {
    $type = $b['type'];
    $f = isset($b['fields']) ? $b['fields'] : array();

    // alt lookup for inline filename references.
    $altOf = array();
    if (!empty($b['assets']['images'])) {
        foreach ($b['assets']['images'] as $im) {
            if (!empty($im['file'])) {
                $altOf[$im['file']] = isset($im['alt']) ? $im['alt'] : '';
            }
        }
    }

    // Internal-link placeholders -> relative UIR route.
    array_walk_recursive($f, function (&$v) {
        if (is_string($v)) {
            $v = str_replace('UIRPLACEHOLDER/', UIR, $v);
        }
    });

    // file_download: rebuild files[] as {label,url} from assets.files.
    if ($type === 'file_download') {
        $files = array();
        if (!empty($b['assets']['files'])) {
            foreach ($b['assets']['files'] as $fl) {
                $d = $copyDoc($slug, $fl['file'], isset($fl['label']) ? $fl['label'] : '', isset($fl['name']) ? $fl['name'] : '');
                if ($d) {
                    $files[] = $d;
                }
            }
        }
        $f['files'] = $files;
        return array('type' => $type, 'enabled' => 1, 'source' => 'authored', 'fields' => $f);
    }

    // Assets-only image blocks (filename lives only in assets.images).
    if ($type === 'image') {
        if (!empty($b['assets']['images'][0]['file'])) {
            $im = $b['assets']['images'][0];
            $ref = $uploadImage($slug, $im['file'], isset($im['alt']) ? $im['alt'] : '');
            if ($ref) {
                $f['image'] = $ref;
            }
        }
    } elseif ($type === 'gallery' || $type === 'photo_mosaic') {
        $refs = array();
        foreach ($b['assets']['images'] ?? array() as $im) {
            $ref = $uploadImage($slug, $im['file'], isset($im['alt']) ? $im['alt'] : '');
            if ($ref) {
                $refs[] = $ref;
            }
        }
        $f['images'] = $refs;
    }

    // Inline bare-filename references (card_grid cards[].image,
    // hero_carousel slides[].image, staff_roster people[].image, etc.):
    // replace any leaf string that is a downloaded image filename with a ref.
    array_walk_recursive($f, function (&$v) use ($slug, $altOf, $uploadImage) {
        if (is_string($v) && preg_match('/^[\w.\- ]+\.(jpg|jpeg|png|gif|webp)$/i', $v)) {
            $ref = $uploadImage($slug, $v, isset($altOf[$v]) ? $altOf[$v] : '');
            if ($ref) {
                $v = $ref;
            } else {
                $v = ''; // drop a missing image reference rather than leak a filename
            }
        }
    });

    return array('type' => $type, 'enabled' => 1, 'source' => 'authored', 'fields' => $f);
};

// --- main loop -----------------------------------------------------------

$idBySlug = array();
$report = array();
foreach ($order as $slug) {
    $file = "$STG/specs/$slug.json";
    if (!is_file($file)) {
        $report[] = "$slug: NO SPEC";
        continue;
    }
    $spec = json_decode(file_get_contents($file), true);
    if (!$spec) {
        $report[] = "$slug: BAD SPEC JSON";
        continue;
    }

    $existing = $cms->GetPageBySlug($slug, 'global', 0, false);
    if (!empty($existing) && empty($existing['is_system'])) {
        $cms->DeletePage((int) $existing['page_id']);
    } elseif (!empty($existing) && !empty($existing['is_system'])) {
        $report[] = "$slug: SKIPPED (system page)";
        continue;
    }

    $parentId = (!empty($spec['parent_slug']) && isset($idBySlug[$spec['parent_slug']]))
        ? $idBySlug[$spec['parent_slug']] : null;

    $pid = (int) $cms->CreatePage(array(
        'slug' => $slug,
        'type' => isset($spec['type']) ? $spec['type'] : 'composed',
        'title' => isset($spec['title']) ? $spec['title'] : $slug,
        'meta_description' => isset($spec['meta_description']) ? $spec['meta_description'] : null,
        'status' => 'published', 'published_at' => $now,
        'scope_type' => 'global', 'scope_id' => 0, 'is_system' => 0,
        'parent_id' => $parentId,
        'created_by' => $by, 'created_at' => $now, 'updated_by' => $by, 'updated_at' => $now,
    ));
    if ($pid <= 0) {
        $report[] = "$slug: CREATE FAILED";
        continue;
    }
    $idBySlug[$slug] = $pid;
    $cms->SetStatus($pid, 'published', $by);

    $blocks = array();
    $i = 0;
    foreach ($spec['blocks'] as $b) {
        $rb = $resolveBlock($slug, $b);
        $rb['order'] = $i++;
        $blocks[] = $rb;
    }
    $n = $cms->ReplaceBlocks('page', $pid, $blocks);
    $line = "$slug: page_id=$pid parent=" . ($parentId ?? '-') . " blocks=$n";
    $report[] = $line;
    echo $line . "\n";
    flush();
}

echo "--- done: " . count($report) . " pages ---\n";
