#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/IdNamespace.php';
require_once __DIR__ . '/lib/Json5.php';
require_once __DIR__ . '/lib/ValidationException.php';
require_once __DIR__ . '/lib/TierRefusalException.php';
require_once __DIR__ . '/lib/Wiring.php';
require_once __DIR__ . '/lib/DeploymentTier.php';
require_once __DIR__ . '/lib/MigrationClassifier.php';
require_once __DIR__ . '/lib/SchemaIntrospection.php';
require_once __DIR__ . '/lib/LastRender.php';
require_once __DIR__ . '/Validate.php';
require_once __DIR__ . '/Init.php';
require_once __DIR__ . '/Extract.php';
require_once __DIR__ . '/Render.php';
require_once __DIR__ . '/Apply.php';
require_once __DIR__ . '/Use.php';
require_once __DIR__ . '/Bootstrap.php';
require_once __DIR__ . '/DriftCheck.php';
require_once __DIR__ . '/SchemaDiff.php';
require_once __DIR__ . '/DeploySandbox.php';
require_once __DIR__ . '/SeedTestCredentials.php';
require_once __DIR__ . '/GenerateAssets.php';
require_once __DIR__ . '/DeployAssets.php';

use OrkDb\Apply;
use OrkDb\Bootstrap;
use OrkDb\DeployAssets;
use OrkDb\DeploySandbox;
use OrkDb\DeploymentTier;
use OrkDb\DriftCheck;
use OrkDb\Extract;
use OrkDb\GenerateAssets;
use OrkDb\Init;
use OrkDb\Render;
use OrkDb\SchemaDiff;
use OrkDb\SeedTestCredentials;
use OrkDb\TierRefusalException;
use OrkDb\UseProfile;
use OrkDb\Validate;
use OrkDb\ValidationException;
use OrkDb\Wiring;

$repoRoot = dirname(__DIR__, 2);
$toolRoot = __DIR__;

$wiring = new Wiring($toolRoot);
$tier = new DeploymentTier($wiring, $repoRoot);
$validate = new Validate($wiring, $toolRoot);
$init = new Init($wiring, $validate, $repoRoot);
$extract = new Extract($wiring, $toolRoot);
$render = new Render($toolRoot, $repoRoot);
$apply = new Apply($wiring, $validate, $render, $repoRoot);
$useProfile = new UseProfile($tier, $repoRoot);
$bootstrap = new Bootstrap($validate, $init, $extract, $apply, $toolRoot);
$driftCheck = new DriftCheck($wiring, $toolRoot, $repoRoot);
$schemaDiff = new SchemaDiff($wiring, $repoRoot);
$generateAssets = new GenerateAssets($toolRoot, $render);
$deployAssets = new DeployAssets($toolRoot, $repoRoot);
$seedCredentials = new SeedTestCredentials($wiring);
$deploySandbox = new DeploySandbox(
    $tier,
    $wiring,
    $validate,
    $init,
    $bootstrap,
    $extract,
    $render,
    $apply,
    $useProfile,
    $deployAssets,
    $toolRoot
);

$command = $argv[1] ?? 'help';
$options = parseOptions($argv);

try {
    match ($command) {
        'help', '--help', '-h', '' => exitHelp($options['args'][0] ?? null),
        'status' => runStatus($tier, $wiring),
        'validate' => runValidate($tier, $validate, $options),
        'init' => runInit($tier, $init),
        'extract' => runExtract($tier, $extract, $wiring, $options),
        'render' => runRender($tier, $render, $options),
        'apply' => runApply($tier, $apply, $options),
        'bootstrap' => runBootstrap($tier, $bootstrap, $options),
        'deploy-sandbox' => runDeploySandbox($tier, $deploySandbox, $options),
        'seed-test-credentials' => runSeedTestCredentials($tier, $seedCredentials, $options),
        'generate-assets' => runGenerateAssets($tier, $generateAssets, $options),
        'deploy-assets' => runDeployAssets($tier, $deployAssets, $options),
        'drift-check' => runDriftCheck($tier, $driftCheck, $options),
        'schema-diff' => runSchemaDiff($tier, $schemaDiff),
        'use' => runUse($tier, $useProfile, $options),
        default => unknownCommand($command),
    };
} catch (TierRefusalException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
} catch (ValidationException $e) {
    fwrite(STDERR, 'ork-db: ' . $e->getMessage() . PHP_EOL);
    exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ork-db: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return array{
 *   args: list<string>,
 *   mode: string|null,
 *   yes: bool,
 *   table: string|null,
 *   players_only: bool,
 *   anchor_date: string|null,
 *   seed: int|null,
 *   output: string|null,
 *   sql: string|null,
 *   deterministic: bool,
 *   persist_seed: bool,
 *   skip_extract: bool,
 *   force_extract: bool,
 *   force_refresh: bool,
 *   skip_use_dev: bool,
 *   strict: bool,
 *   target: string|null
 * }
 */
function parseOptions(array $argv): array
{
    $mode = null;
    $yes = false;
    $table = null;
    $playersOnly = false;
    $anchorDate = null;
    $seed = null;
    $output = null;
    $sql = null;
    $deterministic = false;
    $persistSeed = false;
    $skipExtract = false;
    $forceExtract = false;
    $forceRefresh = false;
    $skipUseDev = false;
    $strict = false;
    $target = null;
    $positional = [];

    for ($i = 2, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];
        if ($arg === '--mode' && isset($argv[$i + 1])) {
            $mode = $argv[++$i];
            continue;
        }
        if ($arg === '--target' && isset($argv[$i + 1])) {
            $target = $argv[++$i];
            continue;
        }
        if ($arg === '--table' && isset($argv[$i + 1])) {
            $table = $argv[++$i];
            continue;
        }
        if ($arg === '--anchor-date' && isset($argv[$i + 1])) {
            $anchorDate = $argv[++$i];
            continue;
        }
        if ($arg === '--seed' && isset($argv[$i + 1])) {
            $seed = (int) $argv[++$i];
            continue;
        }
        if ($arg === '--output' && isset($argv[$i + 1])) {
            $output = $argv[++$i];
            continue;
        }
        if ($arg === '--sql' && isset($argv[$i + 1])) {
            $sql = $argv[++$i];
            continue;
        }
        if ($arg === '--yes') {
            $yes = true;
            continue;
        }
        if ($arg === '--players-only') {
            $playersOnly = true;
            continue;
        }
        if ($arg === '--deterministic') {
            $deterministic = true;
            continue;
        }
        if ($arg === '--persist-seed') {
            $persistSeed = true;
            continue;
        }
        if ($arg === '--skip-extract') {
            $skipExtract = true;
            continue;
        }
        if ($arg === '--force-extract') {
            $forceExtract = true;
            continue;
        }
        if ($arg === '--force-refresh') {
            $forceRefresh = true;
            continue;
        }
        if ($arg === '--skip-use-dev') {
            $skipUseDev = true;
            continue;
        }
        if ($arg === '--strict') {
            $strict = true;
            continue;
        }
        if (str_starts_with($arg, '--mode=')) {
            $mode = substr($arg, strlen('--mode='));
            continue;
        }
        if (str_starts_with($arg, '--table=')) {
            $table = substr($arg, strlen('--table='));
            continue;
        }
        if (str_starts_with($arg, '--anchor-date=')) {
            $anchorDate = substr($arg, strlen('--anchor-date='));
            continue;
        }
        if (str_starts_with($arg, '--seed=')) {
            $seed = (int) substr($arg, strlen('--seed='));
            continue;
        }
        if (str_starts_with($arg, '--output=')) {
            $output = substr($arg, strlen('--output='));
            continue;
        }
        if (str_starts_with($arg, '--sql=')) {
            $sql = substr($arg, strlen('--sql='));
            continue;
        }
        $positional[] = $arg;
    }

    return [
        'args' => $positional,
        'mode' => $mode,
        'yes' => $yes,
        'table' => $table,
        'players_only' => $playersOnly,
        'anchor_date' => $anchorDate,
        'seed' => $seed,
        'output' => $output,
        'sql' => $sql,
        'deterministic' => $deterministic,
        'persist_seed' => $persistSeed,
        'skip_extract' => $skipExtract,
        'force_extract' => $forceExtract,
        'force_refresh' => $forceRefresh,
        'skip_use_dev' => $skipUseDev,
        'strict' => $strict,
        'target' => $target,
    ];
}

function exitHelp(?string $topic): never
{
    if ($topic !== null && $topic !== 'help') {
        fwrite(STDOUT, "ork-db help: no detailed help for '{$topic}' yet.\n\n");
    }

    $usage = <<<'TXT'
Usage:
  bin/ork-db use <prod|dev>
  bin/ork-db status
  bin/ork-db extract
  bin/ork-db render [--anchor-date YYYY-MM-DD] [--seed N] [--deterministic]
  bin/ork-db apply [--yes] [--sql path]
  bin/ork-db validate [--mode init|pre-apply|post-apply]
  bin/ork-db init
  bin/ork-db bootstrap [--yes] [--skip-extract] [--force-extract]
  bin/ork-db deploy-sandbox [--yes] [--force-refresh] [--skip-use-dev]
  bin/ork-db seed-test-credentials [--target sandbox|mirror|both]
  bin/ork-db generate-assets [--seed N]
  bin/ork-db deploy-assets
  bin/ork-db drift-check [--strict]
  bin/ork-db schema-diff
  bin/ork-db help [command]

Documentation:
  docs/megiddo/test-database-tool/10-cli-reference.md
TXT;
    fwrite(STDOUT, $usage . PHP_EOL);
    exit(0);
}

function runStatus(DeploymentTier $tier, Wiring $wiring): never
{
    $info = $tier->classify();
    $dataEnabled = $info['tier'] === DeploymentTier::LOCAL ? 'enabled' : 'disabled';

    fwrite(STDOUT, 'tier: ' . $info['tier'] . PHP_EOL);
    fwrite(STDOUT, 'mirror: ' . $wiring->mirrorTargetLabel()
        . ' (' . ($info['mirror_reachable'] ? 'reachable' : 'unreachable') . ')' . PHP_EOL);
    fwrite(STDOUT, 'sandbox: ' . $wiring->sandboxTargetLabel()
        . ' (' . ($info['sandbox_reachable'] ? 'reachable' : 'unreachable') . ')' . PHP_EOL);
    fwrite(STDOUT, 'data commands: ' . $dataEnabled . PHP_EOL);
    foreach ($info['reasons'] as $reason) {
        fwrite(STDOUT, 'note: ' . $reason . PHP_EOL);
    }

    exit(0);
}

/** @param array{args: list<string>, mode: string|null, yes: bool} $options */
function runValidate(DeploymentTier $tier, Validate $validate, array $options): never
{
    $tier->refuseDataCommands('validate');

    $mode = $options['mode'] ?? Validate::MODE_PRE_APPLY;
    if (!in_array($mode, [Validate::MODE_INIT, Validate::MODE_PRE_APPLY, Validate::MODE_POST_APPLY], true)) {
        fwrite(STDERR, "ork-db: unknown validate mode '{$mode}'\n");
        exit(2);
    }

    $result = $validate->run($mode, $mode === Validate::MODE_POST_APPLY);
    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

function runInit(DeploymentTier $tier, Init $init): never
{
    $tier->refuseDataCommands('init');
    $init->run();
    exit(0);
}

/** @param array{args: list<string>, mode: string|null, yes: bool, table: string|null, players_only: bool} $options */
function runExtract(DeploymentTier $tier, Extract $extract, Wiring $wiring, array $options): never
{
    $tier->refuseDataCommands('extract');

    $result = $extract->run([
        'table' => $options['table'],
        'players_only' => $options['players_only'],
    ]);

    fwrite(STDOUT, 'Source:       ' . $result['source'] . PHP_EOL);
    foreach ($result['files'] as $file) {
        fwrite(STDOUT, 'Wrote:        ' . $file . PHP_EOL);
    }
    foreach ($result['warnings'] as $warning) {
        fwrite(STDERR, 'Warning:      ' . $warning . PHP_EOL);
    }

    exit(0);
}

/** @param array{anchor_date: string|null, seed: int|null, output: string|null, deterministic: bool, persist_seed: bool} $options */
function runRender(DeploymentTier $tier, Render $render, array $options): never
{
    $tier->refuseDataCommands('render');

    $result = $render->run([
        'anchor_date' => $options['anchor_date'],
        'seed' => $options['seed'],
        'output' => $options['output'],
        'deterministic' => $options['deterministic'],
        'persist_seed' => $options['persist_seed'],
    ]);

    fwrite(STDOUT, 'Output:       ' . $result['output'] . PHP_EOL);
    fwrite(STDOUT, 'Anchor date:  ' . $result['anchor_date'] . PHP_EOL);
    fwrite(STDOUT, 'Content seed: ' . $result['content_seed'] . PHP_EOL);
    fwrite(STDOUT, 'Kingdoms:     ' . $result['kingdom_count'] . PHP_EOL);
    fwrite(STDOUT, 'Parks:        ' . $result['park_count'] . PHP_EOL);

    exit(0);
}

/** @param array{yes: bool, sql: string|null} $options */
function runApply(DeploymentTier $tier, Apply $apply, array $options): never
{
    $tier->refuseDataCommands('apply');

    $result = $apply->run([
        'yes' => $options['yes'],
        'sql' => $options['sql'],
    ]);

    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

function runDriftCheck(DeploymentTier $tier, DriftCheck $driftCheck, array $options): never
{
    unset($tier);

    $result = $driftCheck->run((bool) ($options['strict'] ?? false));
    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

function runSchemaDiff(DeploymentTier $tier, SchemaDiff $schemaDiff): never
{
    $tier->refuseDataCommands('schema-diff');

    $result = $schemaDiff->run();
    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

/** @param array{yes: bool, skip_extract: bool, force_extract: bool} $options */
function runBootstrap(DeploymentTier $tier, Bootstrap $bootstrap, array $options): never
{
    $tier->refuseDataCommands('bootstrap');

    $result = $bootstrap->run([
        'yes' => $options['yes'],
        'skip_extract' => $options['skip_extract'],
        'force_extract' => $options['force_extract'],
    ]);

    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

/** @param array{yes: bool, force_refresh: bool, skip_use_dev: bool} $options */
function runDeploySandbox(DeploymentTier $tier, DeploySandbox $deploySandbox, array $options): never
{
    $tier->refuseDataCommands('deploy-sandbox');

    $result = $deploySandbox->run([
        'yes' => $options['yes'],
        'force_refresh' => $options['force_refresh'],
        'skip_use_dev' => $options['skip_use_dev'],
    ]);

    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

/** @param array{target: string|null} $options */
function runSeedTestCredentials(DeploymentTier $tier, SeedTestCredentials $seed, array $options): never
{
    $tier->refuseDataCommands('seed-test-credentials');

    $result = $seed->run([
        'target' => $options['target'] ?? SeedTestCredentials::TARGET_BOTH,
    ]);

    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($result['exit_code']);
}

/** @param array{seed: int|null} $options */
function runGenerateAssets(DeploymentTier $tier, GenerateAssets $generateAssets, array $options): never
{
    $tier->refuseDataCommands('generate-assets');

    $result = $generateAssets->run([
        'seed' => $options['seed'],
    ]);

    fwrite(STDOUT, 'Output:       ' . $result['output_root'] . PHP_EOL);
    fwrite(STDOUT, 'Kingdoms:     ' . $result['kingdom_count'] . PHP_EOL);
    fwrite(STDOUT, 'Parks:        ' . $result['park_count'] . PHP_EOL);
    fwrite(STDOUT, 'Players:      ' . $result['player_count'] . PHP_EOL);
    fwrite(STDOUT, 'Files:        ' . count($result['files']) . PHP_EOL);

    exit(0);
}

function runDeployAssets(DeploymentTier $tier, DeployAssets $deployAssets): never
{
    $tier->refuseDataCommands('deploy-assets');

    $result = $deployAssets->run();

    fwrite(STDOUT, 'Assets root:  ' . $result['assets_root'] . PHP_EOL);
    fwrite(STDOUT, 'Source:       ' . $result['source_root'] . PHP_EOL);
    fwrite(STDOUT, 'Kingdoms:     ' . $result['kingdom_count'] . PHP_EOL);
    fwrite(STDOUT, 'Parks:        ' . $result['park_count'] . PHP_EOL);
    fwrite(STDOUT, 'Player art:   ' . $result['player_heraldry_count'] . PHP_EOL);
    fwrite(STDOUT, 'Portraits:    ' . $result['player_portrait_count'] . PHP_EOL);
    fwrite(STDOUT, 'Files:        ' . count($result['files']) . PHP_EOL);
    if ($result['manifest_ok'] === true) {
        fwrite(STDOUT, 'Manifest:     ok' . PHP_EOL);
    }

    exit(0);
}

/** @param array{args: list<string>, mode: string|null, yes: bool} $options */
function runUse(DeploymentTier $tier, UseProfile $useProfile, array $options): never
{
    $profile = $options['args'][0] ?? null;
    if ($profile === null) {
        fwrite(STDERR, "ork-db: usage: bin/ork-db use <prod|dev>\n");
        exit(2);
    }

    $result = $useProfile->run($profile);
    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit(0);
}

function unknownCommand(string $command): never
{
    fwrite(STDERR, "ork-db: unknown command '{$command}'\n");
    exitHelp(null);
}
