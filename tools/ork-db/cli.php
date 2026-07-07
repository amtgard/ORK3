#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Json5.php';
require_once __DIR__ . '/lib/ValidationException.php';
require_once __DIR__ . '/lib/TierRefusalException.php';
require_once __DIR__ . '/lib/Wiring.php';
require_once __DIR__ . '/lib/DeploymentTier.php';
require_once __DIR__ . '/Validate.php';
require_once __DIR__ . '/Init.php';
require_once __DIR__ . '/Extract.php';
require_once __DIR__ . '/Render.php';
require_once __DIR__ . '/Apply.php';

use OrkDb\Apply;
use OrkDb\DeploymentTier;
use OrkDb\Extract;
use OrkDb\Init;
use OrkDb\Render;
use OrkDb\TierRefusalException;
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
        'schema-diff' => runStubDataCommand($tier, $command),
        'use' => runUseStub($tier, $options),
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
 *   persist_seed: bool
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
    $positional = [];

    for ($i = 2, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];
        if ($arg === '--mode' && isset($argv[$i + 1])) {
            $mode = $argv[++$i];
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

    $result = $validate->run($mode);
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

function runStubDataCommand(DeploymentTier $tier, string $command): never
{
    $tier->refuseDataCommands($command);
    fwrite(STDERR, "ork-db: '{$command}' not implemented yet (TD-8).\n");
    exit(2);
}

/** @param array{args: list<string>, mode: string|null, yes: bool} $options */
function runUseStub(DeploymentTier $tier, array $options): never
{
    $profile = $options['args'][0] ?? null;
    if ($profile === null) {
        fwrite(STDERR, "ork-db: usage: bin/ork-db use <prod|dev>\n");
        exit(2);
    }

    if ($profile === 'dev') {
        $tier->refuseDataCommands('use dev');
    }

    fwrite(STDERR, "ork-db: 'use {$profile}' not implemented yet (TD-6).\n");
    exit(2);
}

function unknownCommand(string $command): never
{
    fwrite(STDERR, "ork-db: unknown command '{$command}'\n");
    exitHelp(null);
}
