<?php

/**
 * What each case of the 150-combination sweep SHOULD draw, asked of the app's own rules.
 *
 * Reads tools/scroll-sweep/out/combo150/matrix.json (written by combo150-matrix.spec.ts) and
 * prints a JSON object keyed by case id: Scroll::CustomBorderRule() (compatible? how many
 * passes? which frameless ground?), the knot count the page should carry, the heraldry sources
 * Model_Scrollery::heraldry_sources() offers and the one Scroll::ResolveHeraldrySource() picks,
 * and the normalised design and recipe. The harness never restates these rules; it compares
 * the rendered page against this.
 *
 * Read-only. Boots the app the way bin/scroll-render-fixtures.php does, inside the app
 * container:
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/tools/scroll-sweep/bin/combo150-expect.php <matrix.json>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$matrixPath = $argv[1] ?? '';
$matrix = $matrixPath === '' ? null : json_decode((string) file_get_contents($matrixPath), true);
if (!is_array($matrix) || !isset($matrix['cases']) || !is_array($matrix['cases'])) {
    fwrite(STDERR, "usage: php combo150-expect.php <matrix.json with a cases array>\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = getenv('ORK3_FIXTURE_HOST') ?: '127.0.0.1:19080';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/orkui/index.php';
chdir(dirname(__DIR__, 3) . '/orkui');
include_once '../startup.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require_once DIR_MODEL . 'model.Scrollery.php';

$model = new Model_Scrollery();
$out = [];
foreach ($matrix['cases'] as $c) {
    $d = $c['design'];
    $scopeType = (string) $c['scope']['scopeType'];
    $scopeId = (int) $c['scope']['scopeId'];
    $playerId = (int) $c['playerId'];

    $sources = $model->heraldry_sources($scopeType, $scopeId, $playerId);
    $avail = array_map(static fn (string $u): bool => $u !== '', $sources);
    $resolved = Scroll::ResolveHeraldrySource((string) $d['heraldrySource'], $scopeType, $avail);

    $design = Scroll::NormaliseDesign([
        'heraldrySource' => $resolved,
        'customBorder' => $d['customBorder'] ? '1' : '',
        'color1' => (string) $d['color1'],
        'color2' => (string) $d['color2'],
        'matchStyle' => $d['matchStyle'] ? '1' : '',
        'customRibbon' => $d['customRibbon'] ? '1' : '',
        'ribbonColor' => (string) $d['ribbonColor'],
    ]);

    $recipeIn = isset($c['recipe']) ? [
        'ground' => $c['recipe']['ground'], 'border' => $c['recipe']['border'], 'rankSet' => $c['recipe']['rankSet'],
    ] : [];
    $rule = Scroll::CustomBorderRule((string) $c['order'], (int) $c['rank'], (string) $c['variant'], $recipeIn);
    $passes = $rule === null ? 0 : count($rule['passes']);
    $knots = (!$design['customBorder'] || $rule === null) ? 0 : ($design['matchStyle'] ? $passes : 1);

    $out[$c['id']] = [
        'compatible' => $rule !== null,
        'passes' => $passes,
        'knots' => $knots,
        'groundSwap' => ($knots > 0 && $rule !== null) ? $rule['ground'] : null,
        'heraldrySources' => $sources,
        'heraldrySource' => $resolved,
        'heraldryUrl' => (string) $sources[$resolved],
        'heraldryFellBack' => (string) $d['heraldrySource'] !== '' && $resolved !== (string) $d['heraldrySource'],
        'design' => $design,
    ] + ($recipeIn !== [] ? ['recipe' => Scroll::NormaliseRecipe($recipeIn)] : []);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
