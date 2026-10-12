<?php

/**
 * Shared CLI bootstrap for the CMS migrations/seeds in this directory.
 *
 * Every CMS migration here needs the same four things before it can touch the
 * app; this is the single canonical form of them (it mirrors the pre-existing
 * house script dev-set-test-logins.php):
 *
 *   1. CLI guard. These files live under the docroot and are therefore
 *      web-reachable, yet they perform site-wide writes with nothing
 *      authenticating the caller — so an HTTP request to one must be refused.
 *   2. HTTP_HOST default. startup.php derives HTTP_TEMPLATE (and UIR, on the
 *      web path) from HTTP_HOST, which CLI has no reason to set. The default
 *      matches the dev container's external origin (see
 *      reference_local_dev_routing).
 *   3. DONOTWEBSERVICE + chdir into orkui + output-suppressed startup, so
 *      startup runs in the same working directory the web entry point uses and
 *      emits nothing onto the migration's own stdout (several migrations print
 *      a machine-readable JSON report there).
 *   4. UIR. It is normally defined by orkui/index.php (web), not by startup, so
 *      the CLI path has to supply it. It is deliberately a HOST-AGNOSTIC
 *      RELATIVE base: seeds persist these hrefs verbatim into nav items and
 *      block fields, so an absolute host would bake the seed machine's origin
 *      into content served from every environment.
 *
 * Usage — first executable line of the migration:
 *   require_once __DIR__ . '/_cms_cli_bootstrap.php';
 */

// Web-reachable file: refuse any non-CLI (HTTP) invocation.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!defined('DONOTWEBSERVICE')) {
    define('DONOTWEBSERVICE', true);
}

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}

chdir(__DIR__ . '/../orkui');
ob_start();
require_once __DIR__ . '/../startup.php';
ob_end_clean();

if (!defined('UIR')) {
    define('UIR', '/orkui/index.php?Route=');
}
