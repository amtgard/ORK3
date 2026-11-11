<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    private string $tmpDir = '';

    private string $repoRoot = '';

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->tmpDir = sys_get_temp_dir() . '/ork3-bootstrap-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $ga4 = $this->repoRoot . '/files/ga4-sa.json';
        if (is_file($ga4) && str_starts_with((string) file_get_contents($ga4), '{"test fixture"')) {
            @unlink($ga4);
        }
        if (is_dir($this->repoRoot . '/files') && glob($this->repoRoot . '/files/*') === []) {
            @rmdir($this->repoRoot . '/files');
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testTestEnvironmentUrlsAndDatabaseDefaults(): void
    {
        putenv('ENVIRONMENT=TEST');
        $_SERVER['HTTP_HOST'] = 'localhost';
        require $this->repoRoot . '/config/bootstrap.php';

        $this->assertSame('', ORK_DIST_NAME);
        $this->assertSame('http://localhost/orkui/', HTTP_UI);
        $this->assertSame('127.0.0.1', DB_HOSTNAME);
        $this->assertSame(19307, DB_PORT);
        $this->assertSame('ork_test', DB_DATABASE);
        $this->assertFalse(CACHE_ENABLED);
        $this->assertStringEndsWith('/cache/', DIR_CACHE);
        $this->assertSame($this->repoRoot . '/', DIR_BASENAME);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testDevEnvironmentUsesForwardedProtoScheme(): void
    {
        putenv('ENVIRONMENT=DEV');
        $_SERVER['HTTP_HOST'] = 'staging.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        require $this->repoRoot . '/config/bootstrap.php';

        $this->assertSame('', ORK_DIST_NAME);
        $this->assertSame('https://staging.example.com/orkui/', HTTP_UI);
        $this->assertSame('ork3-php8-db', DB_HOSTNAME);
        $this->assertTrue(CACHE_ENABLED);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testProductionEnvironmentUsesOrkDistPrefix(): void
    {
        putenv('ENVIRONMENT=PROD');
        putenv('DB_DATABASE=ork_live');
        putenv('DB_USERNAME=live-user');
        putenv('DB_PASSWORD=live-pass');
        $_SERVER['HTTP_HOST'] = 'ork.amtgard.com';
        require $this->repoRoot . '/config/bootstrap.php';

        $this->assertSame('ork', ORK_DIST_NAME);
        $this->assertSame('http://ork.amtgard.com/ork/orkui/', HTTP_UI);
        $this->assertSame('mysql.amtgard.com', DB_HOSTNAME);
        $this->assertSame('PROD', APP_STAGE);
        $this->assertSame('https://idp.amtgard.com', IDP_BASE_URL);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testSecretsFillUnsetKeepsPreSetDbPassword(): void
    {
        $secretsDir = $this->tmpDir . '/secrets';
        mkdir($secretsDir, 0775, true);
        $fixtureSecret = 'fixture-secret-value-' . uniqid('', true);
        file_put_contents($secretsDir . '/secrets.env', "DB_PASSWORD={$fixtureSecret}\n");

        putenv('ENVIRONMENT=PROD');
        putenv('DB_PASSWORD=kept-from-process-env');
        putenv('HOST_ENV_SECRETS_DIR=' . $secretsDir);
        $_SERVER['HTTP_HOST'] = 'localhost';
        require $this->repoRoot . '/config/bootstrap.php';

        $this->assertSame('kept-from-process-env', DB_PASSWORD);
        $traceJson = json_encode(ork3_bootstrap_get_trace(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($fixtureSecret, $traceJson);
        $this->assertContains(
            ['level' => 'debug', 'event' => 'env_key_kept', 'detail' => 'DB_PASSWORD'],
            ork3_bootstrap_get_trace()
        );
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testBootstrapTraceRecordsSecretsDirAndGa4Inject(): void
    {
        $secretsDir = $this->tmpDir . '/secrets-trace';
        mkdir($secretsDir, 0775, true);
        file_put_contents($secretsDir . '/secrets.env', "IDP_API_KEY=trace-key\n");

        if (!is_dir($this->repoRoot . '/files')) {
            mkdir($this->repoRoot . '/files', 0775, true);
        }
        file_put_contents($this->repoRoot . '/files/ga4-sa.json', '{"test fixture": true}');

        putenv('ENVIRONMENT=DEV');
        putenv('HOST_ENV_SECRETS_DIR=' . $secretsDir);
        $_SERVER['HTTP_HOST'] = 'localhost';
        require $this->repoRoot . '/config/bootstrap.php';

        $events = array_column(ork3_bootstrap_get_trace(), 'event');
        $this->assertContains('bootstrap_secrets_dir_configured', $events);
        $this->assertContains('bootstrap_ga4_inject_path_set', $events);
        $this->assertSame($this->repoRoot . '/files/ga4-sa.json', getenv('GA4_SA_KEY_PATH'));

        $encoded = json_encode(ork3_bootstrap_get_trace(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('trace-key', $encoded);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testOverridesTestDatabaseHostFromEnv(): void
    {
        putenv('ENVIRONMENT=TEST');
        putenv('ORK3_TEST_DB_HOST=10.0.0.5');
        putenv('ORK3_TEST_DB_PORT=3307');
        $_SERVER['HTTP_HOST'] = 'localhost';
        require $this->repoRoot . '/config/bootstrap.php';

        $this->assertSame('10.0.0.5', DB_HOSTNAME);
        $this->assertSame(3307, DB_PORT);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function testDefaultSecretsDirWhenRunSecretsReadable(): void
    {
        putenv('ENVIRONMENT=TEST');
        putenv('HOST_ENV_SECRETS_DIR');
        $_SERVER['HTTP_HOST'] = 'localhost';
        require $this->repoRoot . '/config/bootstrap.php';

        $events = array_column(ork3_bootstrap_get_trace(), 'event');
        $this->assertTrue(
            in_array('bootstrap_secrets_skipped', $events, true)
            || in_array('bootstrap_secrets_dir_default', $events, true)
        );
    }
}
