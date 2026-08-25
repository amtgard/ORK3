<?php

/**
 * Download the amtgard.com replication's images and documents into a staging
 * directory, so 2026-07-08-cms-seed-amtgard.php can seed a FULL replication.
 *
 * WHY THIS EXISTS: the committed specs (db-migrations/amtgard-specs/specs/*.json)
 * carry every page's structure, copy and asset FILENAMES, but not the asset bytes
 * — binaries scraped from a third-party site do not belong in git history. This
 * fetches them on demand, so a clean checkout can reach a complete replication
 * without anyone hand-carrying a staging directory between machines.
 *
 * HOW THE MATCH IS MADE (and why it is safe): each spec names its assets by slot
 * and extension — 1.jpg, 2.png, 3.jpg. This walks the live page's content images
 * in DOM order and pairs them off against those slots. It REFUSES to guess: if the
 * extension sequence on the live page no longer matches the spec, the page is
 * reported as MISMATCH and nothing is written for it, because a silently wrong
 * image is worse than a missing one. Re-run after re-extracting that page.
 *
 * Wix serves a resized/re-encoded derivative at the URL found in the markup; the
 * original is everything up to and including '~mv2.<ext>'. We fetch the original
 * and let CmsMedia::Upload do the resizing, which is what the image pipeline is for.
 *
 * Idempotent: an asset already on disk with a non-zero size is left alone.
 *
 * Run (host or container — it only needs curl and the filesystem):
 *   php db-migrations/fetch-amtgard-assets.php db-migrations/amtgard-specs
 */

$STG = isset($argv[1]) ? rtrim($argv[1], '/') : (__DIR__ . '/amtgard-specs');
if ($STG !== '' && $STG[0] !== '/') {
    $STG = rtrim(getcwd(), '/') . '/' . $STG;
}
if (!is_dir("$STG/specs")) {
    fwrite(STDERR, "No specs dir at $STG/specs\n");
    exit(1);
}

$PAGES = array(
    'about' => 'https://www.amtgard.com/about',
    'mission' => 'https://www.amtgard.com/mission',
    'staff' => 'https://www.amtgard.com/staff',
    'volunteers' => 'https://www.amtgard.com/volunteers',
    'join' => 'https://www.amtgard.com/join',
    'learn-the-basics' => 'https://www.amtgard.com/learn-the-basics',
    'start-a-chapter' => 'https://www.amtgard.com/start-a-chapter',
    'programs' => 'https://www.amtgard.com/programs',
    'foodfight' => 'https://www.amtgard.com/foodfight',
    'olympiad' => 'https://www.amtgard.com/olympiad',
    'media' => 'https://www.amtgard.com/media',
    'galleries' => 'https://www.amtgard.com/galleries',
    'writing' => 'https://www.amtgard.com/writing',
    'resources' => 'https://www.amtgard.com/resources',
    'documents' => 'https://www.amtgard.com/documents',
);

/** GET a URL, following redirects. Returns the body, or '' on failure. */
$get = function ($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'ORK3-amtgard-replication-seed/1.0 (+https://github.com/amtgard/ORK3)',
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : '';
};

/**
 * Content images on a page, in DOM order, as ORIGINAL (un-derivated) URLs.
 * Site chrome is excluded: Wix serves nav/social/logo art either at tiny display
 * sizes or from the shared un-namespaced media root, neither of which is content.
 */
$contentImages = function ($html) {
    if (!preg_match_all('/<img[^>]+src="([^"]+)"/i', $html, $m)) {
        return array();
    }
    $out = array();
    foreach ($m[1] as $u) {
        if (strpos($u, 'static.wixstatic.com/media/') === false) {
            continue;
        }
        // Display geometry lives in the /v1/fill/w_,h_ segment; chrome is small.
        if (preg_match('#/v1/[a-z_]+/w_(\d+),h_(\d+)#', $u, $g) && ((int)$g[1] < 120 || (int)$g[2] < 120)) {
            continue;
        }
        if (preg_match('/(logo|banner|favicon)/i', $u)) {
            continue;
        }
        // Reduce to the original asset: everything through '~mv2.<ext>'.
        if (!preg_match('#^(https://static\.wixstatic\.com/media/[^/]+~mv2\.(jpg|jpeg|png|gif|webp))#i', $u, $o)) {
            continue;
        }
        if (!in_array($o[1], $out, true)) {
            $out[] = $o[1];
        }
    }
    return $out;
};

/** Every PDF/doc link on the page, in DOM order. */
$docLinks = function ($html) {
    $out = array();
    if (preg_match_all('/<a[^>]+href="([^"]+)"/i', $html, $m)) {
        foreach ($m[1] as $u) {
            if (preg_match('/\.(pdf|docx?|xlsx?|pptx?|odt|rtf|txt|csv)(\?|$)/i', $u)) {
                $abs = (strpos($u, '//') === 0) ? "https:$u" : $u;
                if (strpos($abs, 'http') === 0 && !in_array($abs, $out, true)) {
                    $out[] = $abs;
                }
            }
        }
    }
    return $out;
};

$ok = $skipped = $missing = 0;
$report = array();

foreach ($PAGES as $slug => $url) {
    $specFile = "$STG/specs/$slug.json";
    if (!is_file($specFile)) {
        $report[] = "$slug: no spec";
        continue;
    }
    $spec = json_decode(file_get_contents($specFile), true);

    // Wanted assets, in spec order, de-duplicated by filename.
    $wantImg = array();
    $wantDoc = array();
    foreach ($spec['blocks'] ?? array() as $b) {
        foreach ($b['assets']['images'] ?? array() as $a) {
            if (!empty($a['file']) && !isset($wantImg[$a['file']])) {
                $wantImg[$a['file']] = true;
            }
        }
        foreach ($b['assets']['docs'] ?? array() as $a) {
            if (!empty($a['file']) && !isset($wantDoc[$a['file']])) {
                $wantDoc[$a['file']] = true;
            }
        }
    }
    $wantImg = array_keys($wantImg);
    $wantDoc = array_keys($wantDoc);
    if (!$wantImg && !$wantDoc) {
        $report[] = "$slug: no assets referenced";
        continue;
    }

    $dir = "$STG/assets/$slug";
    @mkdir($dir, 0775, true);

    // Everything already present? Then this page is done.
    $need = false;
    foreach (array_merge($wantImg, $wantDoc) as $f) {
        if (!is_file("$dir/$f") || filesize("$dir/$f") === 0) {
            $need = true;
            break;
        }
    }
    if (!$need) {
        $skipped += count($wantImg) + count($wantDoc);
        $report[] = "$slug: already complete (" . (count($wantImg) + count($wantDoc)) . ')';
        continue;
    }

    $html = $get($url);
    if ($html === '') {
        $report[] = "$slug: FETCH FAILED $url";
        $missing += count($wantImg) + count($wantDoc);
        continue;
    }

    // ---- images: pair spec slots against live DOM order, extension-checked ----
    if ($wantImg) {
        $live = $contentImages($html);
        $liveExt = array_map(function ($u) {
            $e = strtolower(pathinfo(parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
            return ($e === 'jpeg') ? 'jpg' : $e;
        }, $live);
        $wantExt = array_map(function ($f) {
            $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            return ($e === 'jpeg') ? 'jpg' : $e;
        }, $wantImg);

        $pairs = null;
        if (count($live) >= count($wantImg)
            && array_slice($liveExt, 0, count($wantExt)) === $wantExt) {
            $pairs = array_combine($wantImg, array_slice($live, 0, count($wantImg)));
        }
        if ($pairs === null) {
            $report[] = sprintf(
                '%s: IMAGE MISMATCH — spec wants [%s], live page offers [%s]; nothing written',
                $slug,
                implode(',', $wantExt),
                implode(',', array_slice($liveExt, 0, max(count($wantExt), 6)))
            );
            $missing += count($wantImg);
        } else {
            foreach ($pairs as $file => $src) {
                $dest = "$dir/$file";
                if (is_file($dest) && filesize($dest) > 0) {
                    $skipped++;
                    continue;
                }
                $bytes = $get($src);
                if ($bytes === '' || strlen($bytes) < 128) {
                    $report[] = "$slug/$file: download failed";
                    $missing++;
                    continue;
                }
                file_put_contents($dest, $bytes);
                $ok++;
            }
        }
    }

    // ---- documents: matched by filename, which Wix preserves in the URL ----
    foreach ($wantDoc as $file) {
        $dest = "$dir/$file";
        if (is_file($dest) && filesize($dest) > 0) {
            $skipped++;
            continue;
        }
        $hit = '';
        foreach ($docLinks($html) as $u) {
            if (strcasecmp(rawurldecode(basename(parse_url($u, PHP_URL_PATH))), $file) === 0) {
                $hit = $u;
                break;
            }
        }
        if ($hit === '') {
            $report[] = "$slug/$file: no matching link on the live page";
            $missing++;
            continue;
        }
        $bytes = $get($hit);
        if ($bytes === '' || strlen($bytes) < 128) {
            $report[] = "$slug/$file: download failed";
            $missing++;
            continue;
        }
        file_put_contents($dest, $bytes);
        $ok++;
    }

    $report[] = sprintf('%s: %d image(s), %d doc(s) requested', $slug, count($wantImg), count($wantDoc));
}

echo implode("\n", $report) . "\n";
printf("\ndownloaded %d, already present %d, unresolved %d\n", $ok, $skipped, $missing);
exit($missing > 0 ? 2 : 0);
