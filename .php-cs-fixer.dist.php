<?php

/**
 * ORK3 coding-standard configuration for PHP CS Fixer.
 *
 * Standard: PSR-12 (non-risky fixers only — no rule here changes runtime behavior).
 *
 * Day-to-day this runs automatically via the .githooks/pre-commit hook, which only
 * touches the PHP files you are actually committing (boy-scout style), so converting
 * the whole tree happens gradually with minimal diff churn.
 *
 * Manual use:
 *   php tools/php-cs-fixer/php-cs-fixer.phar fix                 # fix the whole project
 *   php tools/php-cs-fixer/php-cs-fixer.phar fix --dry-run -v    # report what WOULD change, no writes
 *   php tools/php-cs-fixer/php-cs-fixer.phar fix path/to/File.php
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    // Third-party, generated, or vendored code — never reformat these.
    ->exclude('vendor')
    ->exclude('node_modules')
    ->exclude('cache')
    ->exclude('assets')
    ->exclude('import')
    ->exclude('db-migrations')
    ->exclude('tools')
    ->exclude('system/lib/phpqrcode')
    // Local-only dev override with a login bypass — must never be touched/committed.
    ->notPath('system/lib/ork3/class.Authorization.php')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    // Local PHP may be newer than php-cs-fixer officially supports; allow it to run anyway.
    ->setUnsupportedPhpVersionAllowed(true)
    // Non-risky only: nothing here can change program behavior, only formatting.
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
