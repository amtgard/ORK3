<?php

declare(strict_types=1);

require_once __DIR__ . '/class.EnvFile.php';

/**
 * Facade: loads process env and defines ORK3 runtime constants (gb-host-env-vault).
 */
final class Ork3BootstrapConfig
{
    /** @var list<array{level: string, event: string, detail?: string}> */
    private static array $trace = [];

    public static function boot(): void
    {
        if (defined('CONFIG')) {
            return;
        }

        $repoRoot = dirname(__DIR__, 3);
        self::loadProcessEnv($repoRoot);

        $environment = self::envNonEmpty('ENVIRONMENT', '');
        $isTest = $environment === 'TEST';
        $isDev = $environment === 'DEV';

        date_default_timezone_set('America/Chicago');
        define('CONFIG', true);

        error_reporting(E_ALL);
        ini_set('display_errors', $isTest ? '0' : '1');

        $_SERVER['HTTP_HOST'] ??= 'localhost';

        if ($isTest || $isDev) {
            define('ORK_DIST_NAME', '');
        } else {
            define('ORK_DIST_NAME', self::envNonEmpty('ORK_DIST_NAME', 'ork'));
        }

        $scheme = self::resolveHttpScheme($isTest, $isDev);
        $host = $_SERVER['HTTP_HOST'];
        $distPrefix = ORK_DIST_NAME === '' ? '' : '/' . ORK_DIST_NAME;

        self::defineHttpUrls($scheme, $host, $distPrefix, $isTest, $isDev);
        self::defineDirectoryConstants($repoRoot);
        self::defineStageConstants($isTest, $isDev);
        self::defineSharedConstants($isTest);

        require_once DIR_LIB . 'mail.php';
        require_once DIR_LIB . 'Yapo2/class.Yapo.php';
        require_once DIR_SYSTEMLIB . 'class.Log.php';
    }

    /**
     * @return list<array{level: string, event: string, detail?: string}>
     */
    public static function getTrace(): array
    {
        return self::$trace;
    }

    private static function resolveHttpScheme(bool $isTest, bool $isDev): string
    {
        if ($isTest) {
            return 'http';
        }
        if ($isDev) {
            return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
        }

        return 'http';
    }

    private static function defineHttpUrls(
        string $scheme,
        string $host,
        string $distPrefix,
        bool $isTest,
        bool $isDev,
    ): void {
        define('HTTP_SERVICE', $scheme . '://' . $host . $distPrefix . '/orkservice/');
        define('HTTP_UI', $scheme . '://' . $host . $distPrefix . '/orkui/');
        define('HTTP_UI_REMOTE', $scheme . '://' . $host . $distPrefix . '/orkui/');
        define('HTTP_TEMPLATE', HTTP_UI . 'template/');
        define('HTTP_ASSETS', $scheme . '://' . $host . $distPrefix . '/assets/');

        if ($isTest || $isDev) {
            define('HTTP_WAIVERS', $scheme . '://' . $host . '/assets/waivers/');
            define('HTTP_HERALDRY', $scheme . '://' . $host . '/assets/heraldry/');
            define('HTTP_PLAYER_IMAGE', $scheme . '://' . $host . '/assets/players/');
        } else {
            define('HTTP_WAIVERS', HTTP_ASSETS . 'waivers/');
            define('HTTP_HERALDRY', HTTP_ASSETS . 'heraldry/');
            define('HTTP_PLAYER_IMAGE', HTTP_ASSETS . 'players/');
        }

        define('HTTP_PLAYER_HERALDRY', HTTP_HERALDRY . 'player/');
        define('HTTP_PARK_HERALDRY', HTTP_HERALDRY . 'park/');
        define('HTTP_KINGDOM_HERALDRY', HTTP_HERALDRY . 'kingdom/');
        define('HTTP_EVENT_HERALDRY', HTTP_HERALDRY . 'event/');
        define('HTTP_EVENT_BANNER', HTTP_HERALDRY . 'event-banner/');
        define('HTTP_PARK_BANNER', HTTP_HERALDRY . 'park-banner/');
        define('HTTP_KINGDOM_BANNER', HTTP_HERALDRY . 'kingdom-banner/');
        define('HTTP_PLAYER_BANNER', HTTP_HERALDRY . 'player-banner/');
        define('HTTP_UNIT_BANNER', HTTP_HERALDRY . 'unit-banner/');
        define('HTTP_UNIT_HERALDRY', HTTP_HERALDRY . 'unit/');

        define('HERALDRY_PLAYER_DEFAULT', HTTP_PLAYER_HERALDRY . '000000.jpg');
        define('HERALDRY_PARK_DEFAULT', HTTP_PARK_HERALDRY . '00000.jpg');
        define('HERALDRY_KINGDOM_DEFAULT', HTTP_KINGDOM_HERALDRY . '0000.jpg');
        define('HERALDRY_EVENT_DEFAULT', HTTP_EVENT_HERALDRY . '00000.jpg');
        define('HERALDRY_UNIT_DEFAULT', HTTP_UNIT_HERALDRY . '00000.jpg');

        define('HTTPS_SERVICE', 'https://' . $host . $distPrefix . '/orkservice/');
        define('HTTPS_UI', 'https://' . $host . $distPrefix . '/orkui/');
    }

    private static function defineDirectoryConstants(string $repoRoot): void
    {
        define('DIR_BASENAME', $repoRoot . '/');
        define('DIR_SERVICE', DIR_BASENAME . 'orkservice/');
        define('DIR_UI', DIR_BASENAME . 'orkui/');
        define('DIR_SYSTEM', DIR_BASENAME . 'system/');
        define('DIR_ASSETS', DIR_BASENAME . 'assets/');
        define('DIR_TMP', DIR_ASSETS . 'tmp/');
        define('DIR_WAIVERS', DIR_ASSETS . 'waivers/');
        define('DIR_HERALDRY', DIR_ASSETS . 'heraldry/');
        define('DIR_PLAYER_IMAGE', DIR_ASSETS . 'players/');
        define('DIR_PLAYER_HERALDRY', DIR_HERALDRY . 'player/');
        define('DIR_PARK_HERALDRY', DIR_HERALDRY . 'park/');
        define('DIR_KINGDOM_HERALDRY', DIR_HERALDRY . 'kingdom/');
        define('DIR_EVENT_HERALDRY', DIR_HERALDRY . 'event/');
        define('DIR_EVENT_BANNER', DIR_HERALDRY . 'event-banner/');
        define('DIR_PARK_BANNER', DIR_HERALDRY . 'park-banner/');
        define('DIR_KINGDOM_BANNER', DIR_HERALDRY . 'kingdom-banner/');
        define('DIR_PLAYER_BANNER', DIR_HERALDRY . 'player-banner/');
        define('DIR_UNIT_BANNER', DIR_HERALDRY . 'unit-banner/');
        define('DIR_UNIT_HERALDRY', DIR_HERALDRY . 'unit/');
        define('DIR_CACHE', DIR_BASENAME . 'cache/');

        define('DIR_LIB', DIR_SYSTEM . 'lib/');
        define('DIR_ORK3', DIR_LIB . 'ork3/');
        define('DIR_SYSTEMLIB', DIR_LIB . 'system/');
        define('DIR_LOGS', DIR_SYSTEM . 'logs/');

        define('DIR_CONTROLLER', DIR_UI . 'controller/');
        define('DIR_LANGUAGE', DIR_UI . 'language/');
        define('DIR_TEMPLATE', DIR_UI . 'template/');
        define('DIR_VIEW', DIR_UI . 'view/');
        define('DIR_MODEL', DIR_UI . 'model/');
    }

    private static function defineStageConstants(bool $isTest, bool $isDev): void
    {
        define('DB_DRIVER', 'mysql');
        define('DB_PREFIX', 'ork_');

        if ($isTest) {
            define('DB_HOSTNAME', self::envNonEmpty('ORK3_TEST_DB_HOST', '127.0.0.1'));
            define('DB_PORT', (int) self::envNonEmpty('ORK3_TEST_DB_PORT', '19307'));
            define('DB_USERNAME', 'ork');
            define('DB_PASSWORD', 'secret');
            define('DB_DATABASE', 'ork_test');
            define('CACHE_HOST', 'ork-dev');
            define('LOGIN_TIMEOUT', 72 * 60 * 60);
            define('APP_STAGE', 'DEV');
            define('SETUP_ADMIN_EMAIL', 'test@example.com');
            define('CACHE_ENABLED', false);
            define('IDP_BASE_URL', 'http://localhost:37080');
            define('IDP_API_URL', 'http://host.docker.internal:37080');
            define('IDP_CLIENT_ID', 'ork3_dev');
            define('IDP_CLIENT_SECRET', 'test-secret');
        } elseif ($isDev) {
            $dbProfile = strtolower(self::envNonEmpty('ORK3_DB_PROFILE', 'prod'));
            if ($dbProfile === 'dev') {
                $testHost = self::envNonEmpty('ORK3_TEST_DB_HOST', 'ork3-php8-test-db');
                $testPort = (int) self::envNonEmpty('ORK3_TEST_DB_PORT', '3306');
                if (is_file('/.dockerenv') && in_array(strtolower($testHost), ['127.0.0.1', 'localhost'], true)) {
                    $testHost = 'ork3-php8-test-db';
                    $testPort = 3306;
                }
                define('DB_HOSTNAME', $testHost);
                define('DB_PORT', $testPort);
                define('DB_USERNAME', self::envNonEmpty('ORK3_DB_USER', 'ork'));
                define('DB_PASSWORD', self::envNonEmpty('ORK3_DB_PASSWORD', 'secret'));
                define('DB_DATABASE', 'ork_test');
            } else {
                define('DB_HOSTNAME', self::envNonEmpty('ORK3_DB_HOST', 'ork3-php8-db'));
                define('DB_PORT', (int) self::envNonEmpty('DB_PORT', '3306'));
                define('DB_USERNAME', self::envNonEmpty('ORK3_DB_USER', 'ork'));
                define('DB_PASSWORD', self::envNonEmpty('ORK3_DB_PASSWORD', 'secret'));
                define('DB_DATABASE', self::envNonEmpty('ORK3_DB_DATABASE', 'ork'));
            }
            define('CACHE_HOST', 'ork-dev');
            define('LOGIN_TIMEOUT', 30 * 24 * 60 * 60);
            define('APP_STAGE', 'DEV');
            define('SETUP_ADMIN_EMAIL', 'youremail@example.com');
            define('CACHE_ENABLED', true);
            define('IDP_BASE_URL', 'http://localhost:37080');
            define('IDP_API_URL', 'http://host.docker.internal:37080');
            define('IDP_CLIENT_ID', 'ork3_dev');
            define('IDP_CLIENT_SECRET', self::envNonEmpty('IDP_CLIENT_SECRET', ''));
        } else {
            define('DB_HOSTNAME', self::envNonEmpty('DB_HOSTNAME', 'mysql.amtgard.com'));
            define('DB_PORT', 3306);
            define('DB_USERNAME', self::envNonEmpty('DB_USERNAME', ''));
            define('DB_PASSWORD', self::envNonEmpty('DB_PASSWORD', ''));
            define('DB_DATABASE', self::envNonEmpty('DB_DATABASE', 'DB_DATABASE'));
            define('CACHE_HOST', self::envNonEmpty('CACHE_HOST', 'ork.amtgard.com'));
            define('LOGIN_TIMEOUT', 30 * 24 * 60 * 60);
            define('APP_STAGE', self::envNonEmpty('APP_STAGE', 'PROD'));
            define('SETUP_ADMIN_EMAIL', 'amtgardinc@gmail.com');
            define('CACHE_ENABLED', false);
            define('IDP_BASE_URL', 'https://idp.amtgard.com');
            define('IDP_API_URL', 'https://idp.amtgard.com');
            define('IDP_CLIENT_ID', self::envNonEmpty('IDP_CLIENT_ID', ''));
            define('IDP_CLIENT_SECRET', self::envNonEmpty('IDP_CLIENT_SECRET', ''));
        }

        define('CUSTOM_CSS', HTTP_TEMPLATE . 'default/style/custom.css');
    }

    private static function defineSharedConstants(bool $isTest): void
    {
        define('UI_LOCALITY', 'LOCAL');
        define('ORK3_SERVICE_URL', HTTP_SERVICE);

        if ($isTest) {
            define('UIR', HTTP_UI_REMOTE . 'index.php?Route=');
        }

        define('DO_SETUP', false);
        define('TRACE', false);
        define('DUMPTRACE', false);

        define('GOOGLE_MAPS_API_KEY', self::envNonEmpty('GOOGLE_MAPS_API_KEY', ''));
        define('GOOGLE_MAPS_ACCESS_API_KEY', self::envNonEmpty('GOOGLE_MAPS_ACCESS_API_KEY', ''));
        define('SENDGRID_API_KEY', self::envNonEmpty('SENDGRID_API_KEY', ''));
        define('BEHOLD_KEY', self::envNonEmpty('BEHOLD_KEY', ''));
        define('CF_API_TOKEN', self::envNonEmpty('CF_API_TOKEN', ''));
        define('CF_ZONE_ID', self::envNonEmpty('CF_ZONE_ID', ''));
        define('CARTO_API_KEY', self::envNonEmpty('CARTO_API_KEY', ''));

        define('AMAZON_SES_HOST', self::envNonEmpty('AMAZON_SES_HOST', ''));
        define('AMAZON_SES_USERNAME', self::envNonEmpty('AMAZON_SES_USERNAME', ''));
        define('AMAZON_SES_PASSWORD', self::envNonEmpty('AMAZON_SES_PASSWORD', ''));
        define('AMAZON_SES_FROM_EMAIL', self::envNonEmpty('AMAZON_SES_FROM_EMAIL', ''));
        $amazonSesPort = self::env('AMAZON_SES_PORT');
        define(
            'AMAZON_SES_PORT',
            ($amazonSesPort !== null && trim($amazonSesPort) !== '') ? (int) $amazonSesPort : 587
        );

        define('ORK_CLIENT_NAME', 'AmtgardORK');
        define('IDP_API_KEY', self::envNonEmpty('IDP_API_KEY', ''));
    }

    private static function loadProcessEnv(string $repoRoot): void
    {
        $dotEnv = $repoRoot . '/.env';
        if (is_file($dotEnv)) {
            $envFile = new EnvFile();
            $envFile->loadFromFile($dotEnv, true);
            self::mergeEnvFileTrace($envFile);
        }

        $dbProfileFile = $repoRoot . '/.ork3-db.local';
        if (is_file($dbProfileFile)) {
            $profileEnv = new EnvFile();
            $profileEnv->loadFromFile($dbProfileFile, true);
            self::mergeEnvFileTrace($profileEnv);
        }

        $secretsDir = self::env('HOST_ENV_SECRETS_DIR');
        if ($secretsDir === null || $secretsDir === '') {
            $defaultDir = '/run/secrets';
            $defaultFile = $defaultDir . '/secrets.env';
            if (is_readable($defaultFile)) {
                $secretsDir = $defaultDir;
                self::recordTrace('bootstrap_secrets_dir_default', 'HOST_ENV_SECRETS_DIR');
            } else {
                $secretsDir = null;
                self::recordTrace('bootstrap_secrets_skipped');
            }
        } else {
            self::recordTrace('bootstrap_secrets_dir_configured', 'HOST_ENV_SECRETS_DIR');
        }

        if ($secretsDir !== null) {
            $secretsFile = $secretsDir . '/secrets.env';
            if (is_file($secretsFile)) {
                $secrets = new EnvFile();
                $secrets->loadFromFile($secretsFile, true);
                self::mergeEnvFileTrace($secrets);
            } else {
                self::recordTrace('bootstrap_secrets_file_missing');
            }
        }

        $ga4Inject = $repoRoot . '/files/ga4-sa.json';
        if (is_file($ga4Inject)) {
            $current = self::env('GA4_SA_KEY_PATH');
            if ($current === null || $current === '') {
                putenv('GA4_SA_KEY_PATH=' . $ga4Inject);
                $_ENV['GA4_SA_KEY_PATH'] = $ga4Inject;
                $_SERVER['GA4_SA_KEY_PATH'] = $ga4Inject;
                self::recordTrace('bootstrap_ga4_inject_path_set', 'GA4_SA_KEY_PATH');
            }
        }
    }

    private static function recordTrace(string $event, ?string $detail = null): void
    {
        $row = ['level' => 'debug', 'event' => $event];
        if ($detail !== null && $detail !== '') {
            $row['detail'] = $detail;
        }
        self::$trace[] = $row;
    }

    private static function mergeEnvFileTrace(EnvFile $envFile): void
    {
        foreach ($envFile->getTrace() as $row) {
            self::$trace[] = $row;
        }
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            return null;
        }

        return $value;
    }

    private static function envNonEmpty(string $key, string $default): string
    {
        $value = self::env($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}
