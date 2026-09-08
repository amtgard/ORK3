<?php

declare(strict_types=1);

/**
 * Dump Scrollery's published vocabulary as JSON for the sweep harness.
 *
 * This is the ONE seam between the PHP vocabulary and the TypeScript harness. The
 * harness never restates a list of orders, hands or grounds: it reads this. That is
 * what makes ScrollSweepCoverageTest able to fail when a hand is added — there is
 * nowhere for the harness to disagree with the source.
 *
 * Reads the data files directly rather than booting the app: they are plain
 * `return array(...)` files with no side effects, and booting startup.php here would
 * drag in a database connection this script has no use for.
 *
 * Usage: php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json
 */

$root = dirname(__DIR__, 3);

$orders  = require $root . '/system/lib/ork3/data/scroll-orders.php';
$recipes = require $root . '/system/lib/ork3/data/scroll-recipes.php';

$orderVariants = [];
foreach ($orders as $id => $def) {
    $variants = $def['variants'] ?? [];
    if ($variants === []) {
        $orderVariants[] = ['order' => (string) $id, 'variant' => ''];
        continue;
    }
    foreach ($variants as $v) {
        $orderVariants[] = ['order' => (string) $id, 'variant' => (string) $v];
    }
}

/*
 * CUSTOM_NAME_MAX_LENGTH is read out of the class source rather than by loading the
 * class, for the same reason as above: class.Scroll.php is only loadable inside a
 * booted app. A regex over a `public const NAME = N;` line is brittle in general and
 * safe here because ScrollSweepCoverageTest asserts the dumped value against the
 * real constant from inside PHPUnit, where the class IS loadable.
 */
$src = (string) file_get_contents($root . '/system/lib/ork3/class.Scroll.php');
preg_match('/public const CUSTOM_NAME_MAX_LENGTH\s*=\s*(\d+)/', $src, $m);

echo json_encode([
    'orderVariants'        => $orderVariants,
    'ranks'                => range(0, 10),
    'hands'                => array_keys($recipes['hands']),
    'grounds'              => array_keys($recipes['grounds']),
    'borders'              => array_keys($recipes['borders']),
    'groundsWithOwnBorder' => $recipes['groundsWithOwnBorder'],
    'rankSets'             => array_keys($recipes['rankSets']),
    'caps'                 => ['customName' => (int) ($m[1] ?? 64)],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
