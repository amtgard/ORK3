<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrateOrk3SecretsTest extends TestCase
{
    private const FIXTURE_SECRET = 'fixture-secret-value';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ork3-migrate-' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->removeTree($this->tmpDir);
        }
    }

    public function testMissingConfigReturnsEmptyInjectSlots(): void
    {
        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($this->tmpDir . '/missing-config.php');

        $this->assertCount(count(Ork3SecretsMigrate::INJECT_KEYS), $values);
        $this->assertSame('', $values['DB_PASSWORD']);
    }

    public function testUnreadableConfigDirectoryYieldsEmptyValues(): void
    {
        $configDir = $this->tmpDir . '/config-dir';
        mkdir($configDir, 0775, true);

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($configDir);

        $this->assertSame('', $values['DB_PASSWORD']);
    }

    public function testDevEnvDirectoryIsIgnoredSafely(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('DB_PASSWORD', 'keep');\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $this->tmpDir);

        $this->assertSame('keep', $values['DB_PASSWORD']);
    }

    public function testInvalidDevEnvLineIsSkipped(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, "not-valid\nDB_PASSWORD=from-env\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame('from-env', $values['DB_PASSWORD']);
    }

    public function testCollectsDoubleQuotedDefineWithEscapes(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, <<<'PHP'
<?php
define("SENDGRID_API_KEY", "line1\nline2\"quote");
PHP);

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame("line1\nline2\"quote", $values['SENDGRID_API_KEY']);
    }

    public function testCollectsSingleQuotedDefineWithEscapedQuote(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('BEHOLD_KEY', 'it\\'s-fine');\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame("it's-fine", $values['BEHOLD_KEY']);
    }

    public function testNonStringDefineIsIgnored(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('CF_API_TOKEN', true);\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame('', $values['CF_API_TOKEN']);
    }

    public function testConstantDefineOperandIsIgnored(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('DB_PASSWORD', DB_PASSWORD);\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame('', $values['DB_PASSWORD']);
    }

    public function testDevEnvParsingHonorsExportQuotesAndSkipsNoise(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, <<<'ENV'
# comment
NOT_INJECT=ignored
export CARTO_API_KEY="carto\nvalue"
AMAZON_SES_USERNAME=plain
ENVIRONMENT=DEV
ENV);

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame("carto\nvalue", $values['CARTO_API_KEY']);
        $this->assertSame('plain', $values['AMAZON_SES_USERNAME']);
        $this->assertSame('', $values['DB_PASSWORD']);
    }

    public function testUnreadableDevEnvLeavesConfigValues(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('IDP_API_KEY', 'from-php');\n");

        $devEnv = $this->tmpDir . '/nope.env';
        touch($devEnv);
        chmod($devEnv, 0000);

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        @chmod($devEnv, 0644);
        $this->assertSame('from-php', $values['IDP_API_KEY']);
    }

    public function testCollectsDefineFromConfigPhp(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, <<<'PHP'
<?php
define('DB_PASSWORD', 'fixture-secret-value');
define('IDP_CLIENT_SECRET', '');
PHP);

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertArrayHasKey('DB_PASSWORD', $values);
        $this->assertSame(self::FIXTURE_SECRET, $values['DB_PASSWORD']);
        $this->assertSame('', $values['IDP_CLIENT_SECRET']);
    }

    public function testMalformedDefineIsSkipped(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('GOOGLE_MAPS_API_KEY', 'unterminated\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame('', $values['GOOGLE_MAPS_API_KEY']);
    }

    public function testDefineTruncatedAfterCommaIsSkipped(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, '<?php define(\'DB_PASSWORD\',');

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame('', $values['DB_PASSWORD']);
    }

    public function testDefineDoubleQuoteHonorsCarriageReturn(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('CF_API_TOKEN', \"tok\\r\\n\");\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame("tok\r\n", $values['CF_API_TOKEN']);
    }

    public function testSingleQuotedDefineHonorsBackslashEscape(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('CARTO_API_KEY', 'carto\\\\tail');\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame('carto\\tail', $values['CARTO_API_KEY']);
    }

    public function testDevEnvDoubleQuotedValueHonorsQuoteEscape(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, "IDP_API_KEY=\"api\\\"key\"\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame('api"key', $values['IDP_API_KEY']);
    }

    public function testDefineDoubleQuoteHonorsTabAndUnknownEscape(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('AMAZON_SES_PASSWORD', \"pw\\t\\z\");\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config);

        $this->assertSame("pw\tz", $values['AMAZON_SES_PASSWORD']);
    }

    public function testDevEnvEmptyValueDoesNotOverrideConfigDefine(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('IDP_CLIENT_SECRET', 'from-php');\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, "IDP_CLIENT_SECRET=\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame('from-php', $values['IDP_CLIENT_SECRET']);
    }

    public function testDevEnvSingleQuotedValue(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, "AMAZON_SES_USERNAME='smtp-user'\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame('smtp-user', $values['AMAZON_SES_USERNAME']);
    }

    public function testDevEnvDoubleQuotedUnknownEscapeIsPreserved(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, "GOOGLE_MAPS_ACCESS_API_KEY=\"maps\\z\"\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame('maps\\z', $values['GOOGLE_MAPS_ACCESS_API_KEY']);
    }

    public function testDevEnvOverridesDefineForInjectKeys(): void
    {
        $config = $this->tmpDir . '/config.php';
        file_put_contents($config, "<?php\ndefine('IDP_API_KEY', 'from-config');\n");

        $devEnv = $this->tmpDir . '/.dev.env';
        file_put_contents($devEnv, "IDP_API_KEY=from-dev-env\n");

        $migrate = new Ork3SecretsMigrate();
        $values = $migrate->collectInjectValues($config, $devEnv);

        $this->assertSame('from-dev-env', $values['IDP_API_KEY']);
    }

    public function testWriteProfileEnvListsEveryInjectKey(): void
    {
        $migrate = new Ork3SecretsMigrate();
        $out = $this->tmpDir . '/deep/nested/ork-web.env';
        $migrate->writeProfileEnv($out, ['DB_PASSWORD' => self::FIXTURE_SECRET]);

        $content = file_get_contents($out);
        $this->assertIsString($content);
        foreach (Ork3SecretsMigrate::INJECT_KEYS as $key) {
            $this->assertMatchesRegularExpression('/^' . preg_quote($key, '/') . '=/m', $content);
        }
        $this->assertStringContainsString('DB_PASSWORD=' . self::FIXTURE_SECRET, $content);
    }

    public function testMigrateShellDoesNotEchoSecretsWhenHevBinaryMissing(): void
    {
        $install = $this->tmpDir . '/repo';
        mkdir($install, 0775, true);
        $this->copyMigrateHelper($install);

        file_put_contents($install . '/config.php', <<<'PHP'
<?php
define('DB_PASSWORD', 'fixture-secret-value');
PHP);

        $script = dirname(__DIR__, 2) . '/scripts/migrate-ork3-secrets-to-host-store.sh';
        $outputPath = $install . '/.gb/ork-web.env';

        $phpBinDir = dirname(PHP_BINARY);
        $cmd = sprintf(
            'PATH=%s:/usr/bin:/bin HOME=%s %s %s --output %s 2>&1',
            escapeshellarg($phpBinDir),
            escapeshellarg($this->tmpDir),
            escapeshellarg($script),
            escapeshellarg($install),
            escapeshellarg($outputPath)
        );

        $captured = [];
        $exitCode = 0;
        exec($cmd, $captured, $exitCode);
        $joined = implode("\n", $captured);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringNotContainsString(self::FIXTURE_SECRET, $joined);
        $this->assertFileExists($outputPath);
        $written = file_get_contents($outputPath);
        $this->assertIsString($written);
        $this->assertStringContainsString('DB_PASSWORD=', $written);
        $this->assertStringContainsString(self::FIXTURE_SECRET, $written);
    }

    private function copyMigrateHelper(string $installRoot): void
    {
        $src = dirname(__DIR__, 2) . '/system/lib/system/class.Ork3SecretsMigrate.php';
        $destDir = $installRoot . '/system/lib/system';
        mkdir($destDir, 0775, true);
        copy($src, $destDir . '/class.Ork3SecretsMigrate.php');
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @chmod($path, 0644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
