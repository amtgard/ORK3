<?php

/**
 * Render Scrollery certificates from a list of specs, with no login and no print log.
 *
 *   php bin/scroll-render-fixtures.php <specs.json> <outDir>
 *
 * `specs.json` is an array of certificate specs or `{ "cases": [...] }` (the corpus
 * files' shape). One `<outDir>/<id>.html` is written per spec; each spec reports
 * `<id> ok (<order>)` or `<id> ERROR <message>` on stderr, and one bad spec never stops
 * the rest. Exit status: 0 all rendered, 2 some specs failed, 1 the run could not start.
 *
 * WHY THIS EXISTS: a certificate fetched from Scrollery/compose needs a login, an award
 * that really exists and a browser session per worker. The page is nothing but
 * BuildScroll() + Scrollery_render.tpl, so this runs those two directly, exactly as
 * controller.Scrollery.php's render_bare() does, and a Playwright route
 * (tools/scroll-sweep/lib/fixture.ts) serves the files same-origin so the page's own
 * scripts and fonts load as they do in production.
 *
 * Run it inside the app container, which carries PHP and the repo mount:
 *
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/bin/scroll-render-fixtures.php \
 *       /var/www/ork.amtgard.com/tools/scroll-sweep/out/fixtures/<tag>/specs.json \
 *       /var/www/ork.amtgard.com/tools/scroll-sweep/out/fixtures/<tag>
 *
 * Set ORK3_FIXTURE_HOST to render against another host name (default 127.0.0.1:19080,
 * the local Docker app); the host only decides which config startup.php loads.
 */

// nginx executes any .php under the repo root, so this must refuse the web outright.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$specsPath = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
if ($specsPath === '' || $outDir === '') {
    fwrite(STDERR, "usage: php bin/scroll-render-fixtures.php <specs.json> <outDir>\n");
    exit(1);
}
$specsPath = (string) realpath($specsPath);
$specs = $specsPath === '' ? null : json_decode((string) file_get_contents($specsPath), true);
if (is_array($specs) && isset($specs['cases']) && is_array($specs['cases'])) {
    $specs = $specs['cases'];
}
if (!is_array($specs) || !array_is_list($specs)) {
    fwrite(STDERR, "cannot read specs: expected a JSON array of specs or {\"cases\": [...]}\n");
    exit(1);
}
if (!is_dir($outDir) && !@mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}
$outDir = (string) realpath($outDir);

// startup.php builds its URLs from the request and the layout reads $_GET, so there has to be
// a request to read. chdir because the app resolves its includes from orkui/.
$_SERVER['HTTP_HOST'] = getenv('ORK3_FIXTURE_HOST') ?: '127.0.0.1:19080';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/orkui/index.php';
chdir(dirname(__DIR__) . '/orkui');
include_once '../startup.php';
if (!defined('UIR')) {
    define('UIR', HTTP_UI_REMOTE . 'index.php?Route=');
}
// Same noise the app itself runs under; a deprecation in a vendored include is not a failed render.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

const FIXTURE_REQUIRED = [
    'id', 'hand', 'order', 'awardId', 'title', 'variant', 'rank', 'scope', 'issuerName',
    'issuerTitle', 'useThe', 'useOf', 'player', 'pronoun', 'reason', 'givenBy', 'date',
];

$failed = 0;
foreach ($specs as $c) {
    $id = is_array($c) && isset($c['id']) ? (string) $c['id'] : '?';
    try {
        if (!is_array($c)) {
            throw new RuntimeException('spec is not an object');
        }
        $missing = array_diff(FIXTURE_REQUIRED, array_keys($c));
        if ($missing) {
            throw new RuntimeException('missing field(s): ' . implode(', ', $missing));
        }
        // The id becomes a file name, so it may not be a path.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new RuntimeException('id must match [A-Za-z0-9_-]+');
        }

        $isPark = $c['scope'] === 'park';
        $row = [
            'AwardId' => (int) $c['awardId'],
            'OfficerRole' => 'none',
            'Rank' => (int) $c['rank'],
            'Date' => $c['date'],
            'Note' => $c['reason'],
            'GivenBy' => $c['givenBy'],
            'KingdomAwardName' => $c['title'],
            'ParkName' => $isPark ? $c['issuerName'] : '',
            'KingdomName' => $isPark ? '' : $c['issuerName'],
        ];
        // Scrollery/compose builds its award row with no IsLadder key, and BuildScroll()'s `ranked`
        // reads it for a custom award at rank 0. Forcing 1 here printed a blank ordinal where
        // production prints the title phrase, so it is set only when a spec asks for it.
        if (array_key_exists('isLadder', $c)) {
            $row['IsLadder'] = (int) $c['isLadder'];
        }
        $ctx = [
            'player' => $c['player'],
            'pronoun' => $c['pronoun'],
            'heraldry' => (string) ($c['heraldry'] ?? ''),
            'customName' => $c['order'] === 'custom' ? $c['title'] : '',
            'issuerTitle' => $c['issuerTitle'],
            'useThe' => (bool) $c['useThe'],
            'useOf' => (bool) $c['useOf'],
            'variant' => $c['variant'],
            'recipe' => $c['recipe'] ?? [],
        ];
        $scroll = Scroll::BuildScroll($row, $ctx);

        // What the compose URL would carry. `face` is the hand; `heading` is the composition
        // override the layout engine reads (auto lets it choose).
        $_GET = ['face' => $c['hand'], 'heading' => $c['heading'] ?? 'auto'];
        if (!empty($c['up'])) {
            $_GET['up'] = (string) (int) $c['up'];
        }

        // Each file is a standalone page, so each one needs the page-level shared defs. The knotwork
        // library is emitted once per PROCESS (_border_defs.tpl sets this flag so a page that calls
        // it twice pays once), and this loop is many pages in one process: without the reset only the
        // first file carried the library and every later <use href="#Border-..."> drew nothing. The
        // griffon plate is decided by the first call too, so it is also per page now.
        unset($GLOBALS['scroll_border_defs']);

        ob_start();
        try {
            (static function (string $__path, array $__data): void {
                extract($__data, EXTR_SKIP);
                include $__path;
            })(DIR_TEMPLATE . 'revised-frontend/Scrollery_render.tpl', ['Scrolls' => [$scroll], 'PageTitle' => $id]);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (file_put_contents("$outDir/$id.html", $html) === false) {
            throw new RuntimeException("cannot write $outDir/$id.html");
        }
        fwrite(STDERR, "$id ok ({$scroll['order']})\n");
    } catch (Throwable $e) {
        $failed++;
        // A failed spec must not leave the previous run's file behind to be measured instead.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            @unlink("$outDir/$id.html");
        }
        fwrite(STDERR, "$id ERROR " . str_replace("\n", ' ', $e->getMessage()) . "\n");
    }
}
exit($failed ? 2 : 0);
